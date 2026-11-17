// R182 banking smoke (development): add by hand, repeat monthly, replay, future refusal, duplicate flag on import, description edit.
import {S, sql, need} from '../lib.mjs';
const o = new S(); await o.login('owner@gate.test', 'Gate!Owner#2026pw');
o.cid = process.env.CID || sql("SELECT id FROM companies WHERE name LIKE 'Volume Northwind%' LIMIT 1");
const bank = sql(`SELECT id FROM bank_accounts WHERE company_id='${o.cid}' AND account_type='bank' AND active=1 ORDER BY created_at LIMIT 1`);
const log = (k, v) => console.log(k.padEnd(30), typeof v === 'string' ? v : JSON.stringify(v));
const key = 'r182dev-' + Date.now();
let r = await o.call('bank-transactions/add', {method: 'POST', json: {bankAccountId: bank, date: '2026-07-31', description: 'Office rent Main St', reference: 'RENT', amountCents: -250000, repeatMonths: 3, operationKey: key}});
log('add 3 months from 31 Jul', [r.s, (r.b.transactions || []).map(x => x.date), r.b.error]);
const r2 = await o.call('bank-transactions/add', {method: 'POST', json: {bankAccountId: bank, date: '2026-07-31', description: 'Office rent Main St', reference: 'RENT', amountCents: -250000, repeatMonths: 3, operationKey: key}});
log('same request again', [r2.s, (r2.b.transactions || []).map(x => x.replayed)]);
log('db rows', sql(`SELECT GROUP_CONCAT(CONCAT(transaction_date,':',amount_cents,':',status,':',LEFT(source_hash,7)) ORDER BY transaction_date) FROM bank_transactions WHERE company_id='${o.cid}' AND description LIKE 'Office rent%'`));
r = await o.call('bank-transactions/add', {method: 'POST', json: {bankAccountId: bank, date: '2026-09-15', description: 'x', amountCents: -100, repeatMonths: 6, operationKey: key + 'f'}});
log('future months refused', [r.s, r.b.code, (r.b.error || '').slice(0, 90)]);
r = await o.call('bank-transactions/add', {method: 'POST', json: {bankAccountId: bank, date: '2026-09-01', description: 'x', amountCents: 0, operationKey: key + 'z'}});
log('zero amount refused', [r.s, r.b.code]);
// A statement that contains the August rent two days later, plus one unrelated line.
const csv = `Date,Description,Amount\n2026-09-02,PREAUTH RENT MAIN ST ${key},-2500.00\n2026-09-03,COFFEE SHOP ${key},-4.50\n`;
const data = Buffer.from(csv);
const up = need('start', await o.call('imports/upload-start', {method: 'POST', json: {filename: key + '.csv', size: data.length}}));
need('chunk', await o.call('imports/upload-chunk', {method: 'POST', json: {uploadId: up.uploadId, offset: 0, data: data.toString('base64')}}));
const fin = await o.call('imports/upload-finish', {method: 'POST', json: {uploadId: up.uploadId, bankAccountId: bank, mode: 'preview'}});
const rows = fin.b.preview?.rows || [];
log('import preview', [fin.s, rows.map(x => [x.date, x.foreignAmountCents, !!x.requiresDuplicateReview, (x.validationReason || '').slice(0, 70)])]);
// Edit a description on the preview, as the screen does.
const edited = rows.map((x, i) => ({...x, sourceRunningBalanceCents: x.runningBalanceSource === 'source' ? x.runningBalanceCents : null, ...(i === 1 ? {description: 'Coffee – client meeting ' + key, fullDescription: 'Coffee – client meeting ' + key} : {})}));
const put = await o.call('operations/statement-preview', {method: 'PUT', json: {previewId: fin.b.preview.id, rows: edited}});
log('edit description', [put.s, (put.b.preview?.rows || []).map(x => [x.description, x.eligible, x.selectionKey, x.requiresDuplicateReview]), put.b.error]);
const keys = (put.b.preview?.rows || []).filter(x => x.eligible && !x.requiresDuplicateReview).map(x => x.selectionKey);
const ap = await o.call('operations/statement-approve', {method: 'POST', json: {previewId: fin.b.preview.id, operationKey: key + 'ap', selectedKeys: keys, reviewedDuplicateKeys: [], balanceGapAcknowledged: true}});
log('import (duplicate left out)', [ap.s, ap.b.error]);
log('imported description', sql(`SELECT GROUP_CONCAT(description) FROM bank_transactions WHERE company_id='${o.cid}' AND description LIKE '%client meeting %' ORDER BY created_at DESC LIMIT 1`));
const one = sql(`SELECT id FROM bank_transactions WHERE company_id='${o.cid}' AND description='Office rent Main St' AND transaction_date='2026-07-31'`);
r = await o.call('bank-transactions/edit', {method: 'POST', json: {transactionId: one, date: '2026-07-31', description: 'Office rent – Main St (July)', reference: 'RENT', remarks: '', amountCents: -250000}});
log('edit manual line', [r.s, r.b.changedFields]);

// R189: amount-based approval limits removed (Canada and India).
// Approval limits apply only when the server config sets accounting.approval_limits_enabled = true.
// This suite switches that setting on in the TEST server's config to create real waiting requests,
// then switches it off again and checks those requests can still be approved. The config is always restored.
import {readFileSync, writeFileSync} from 'node:fs';
import {S, sql, rec, need} from './lib.mjs';

const A = 'R189';
const t = async (id, title, fn) => { try { const r = await fn(); rec(id, A, title, r.ok ? 'PASS' : 'FAIL', r.info); return r.ok; } catch (e) { rec(id, A, title, 'FAIL', String(e.message || e).slice(0, 400)); return false; } };
const PW = 'Gate!Member#2026pw';
const CFG = '/srv/gate/sr-accountax-private/config.php';
const ORIGINAL = readFileSync(CFG, 'utf8');
const MARK = "'accounting' => [";
if (!ORIGINAL.includes(MARK)) throw new Error('test config has no accounting section');
const wait = ms => new Promise(r => setTimeout(r, ms));
const limits = async on => { writeFileSync(CFG, on ? ORIGINAL.replace(MARK, MARK + "'approval_limits_enabled' => true, ") : ORIGINAL); await wait(3500); };

const o = new S(); await o.login('owner@gate.test', 'Gate!Owner#2026pw');
const tag = Date.now().toString(36); let n = 0; const key = p => `r189-${p}-${tag}-${++n}`.padEnd(24, 'x');
const CO = need('company', await o.call('companies', {method: 'POST', json: {businessType: 'corporation', country: 'Canada', province: 'ON', taxRegistered: false, moduleMode: 'accounting', fiscalYearEnd: '12-31', booksStartDate: '2026-01-01', chartTemplate: 'default', currency: 'CAD', accountingBasis: 'accrual', name: 'R189 Limits ' + tag}})).company.id;
o.cid = CO;
const uid = e => sql(`SELECT id FROM users WHERE email='${e}'`);
sql(`INSERT IGNORE INTO company_members(company_id,user_id,role,status) VALUES('${CO}','${uid('otherowner@gate.test')}','owner','active')`);
const x = new S(); await x.login('otherowner@gate.test', PW); x.cid = CO;
const acct = c => sql(`SELECT id FROM accounts WHERE company_id='${CO}' AND code='${c}'`);
const tb = () => sql(`SELECT IFNULL(SUM(jl.debit_cents-jl.credit_cents),0) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id WHERE je.company_id='${CO}' AND je.status='posted'`);
const posted = acctCode => Number(sql(`SELECT IFNULL(SUM(jl.debit_cents-jl.credit_cents),0) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id JOIN accounts a ON a.id=jl.account_id WHERE je.company_id='${CO}' AND je.status='posted' AND a.code='${acctCode}'`));
const journal = (s, cents, memo) => s.call('journals', {method: 'POST', json: {date: '2026-09-10', memo: memo + ' ' + tag, lines: [{accountId: acct('1500'), debitCents: cents, creditCents: 0}, {accountId: acct('3000'), debitCents: 0, creditCents: cents}]}});
const VEND = need('vendor', await o.call('vendors', {method: 'POST', json: {name: 'R189 Vendor ' + tag, email: '', address: '', defaultTermsDays: 30, currency: 'CAD', defaultExpenseAccountId: null}})).vendor.id;
const bill = (cents, number) => o.call('bills', {method: 'POST', json: {vendorId: VEND, number: number + '-' + tag, billDate: '2026-09-11', dueDate: '2026-10-11', categoryAccountId: acct('6300'), currency: 'CAD', taxEntryMode: 'exclusive', applyGstHst: false, applyPst: false, foreignAmountCents: cents, issue: true}});
const CUST = need('customer', await o.call('customers', {method: 'POST', json: {name: 'R189 Customer ' + tag, email: '', phone: '', billingAddress: '1 King St', country: 'Canada', province: 'ON'}})).customer.id;
const TMPL = (await o.call('invoice-templates')).b.templates?.[0]?.id;
const invoice = async cents => { const r = need('invoice', await o.call('invoices', {method: 'POST', json: {customerId: CUST, issueDate: '2026-09-01', dueDate: '2026-09-30', currency: 'CAD', templateId: TMPL, issue: true, lines: [{description: 'Work', quantity: 1, unitPriceCents: cents, taxable: false}]}})); return r.invoice?.id || r.id; };
const voidInvoice = (s, id, extra = {}) => s.call('voids/apply', {method: 'POST', json: {entityType: 'invoice', entityId: id, reason: 'Issued in error in this R189 test', confirmed: true, operationKey: key('v'), ...extra}});
const billStatus = id => sql(`SELECT status FROM bills WHERE id='${id}'`);
const invStatus = id => sql(`SELECT status FROM invoices WHERE id='${id}'`);

try {
await limits(false);

await t('RL-01', 'Canada: a 25,000.00 journal (above the former 10,000.00 limit) posts at once; nothing waits for approval and the trial balance balances', async () => {
  const before = posted('1500');
  const r = await journal(o, 2500000, 'Equipment');
  const audit = sql(`SELECT IFNULL(JSON_EXTRACT(metadata_json,'$.materialityThresholdCents'),'missing') FROM audit_log WHERE company_id='${CO}' AND action='journal.manual_posted' ORDER BY created_at DESC LIMIT 1`);
  return {ok: r.s === 201 && r.b.journalEntry?.status === 'posted' && r.b.approvalRequired === false && posted('1500') === before + 2500000 && tb() === '0' && audit === 'null',
    info: JSON.stringify({s: r.s, status: r.b.journalEntry?.status, approvalRequired: r.b.approvalRequired, audit, tb: tb()})};
});

await t('RL-02', 'A 50,000.00 vendor invoice is issued and posted at once (status open, not sent for approval)', async () => {
  const before = posted('6300');
  const r = await bill(5000000, 'BIG');
  const id = r.b.bill?.id || r.b.id; const total = Number(sql(`SELECT total_cents FROM bills WHERE id='${id}'`));
  // Not registered for GST/HST: the tax is part of the expense, so the expense moves by the bill total.
  return {ok: (r.s === 201 || r.s === 200) && billStatus(id) === 'open' && total >= 5000000 && posted('6300') === before + total && tb() === '0', info: JSON.stringify({s: r.s, status: billStatus(id), total, exp: posted('6300') - before})};
});

await t('RL-03', 'A 20,000.00 customer invoice is voided directly: the preview needs no second owner and the void applies at once', async () => {
  const id = await invoice(2000000);
  const pv = (await o.call(`voids/preview&entityType=invoice&entityId=${id}`)).b;
  const r = await voidInvoice(o, id);
  return {ok: pv.dualControlRequired === false && pv.materialityThresholdCents === null && r.s === 200 && !r.b.pendingApproval && invStatus(id) === 'void' && tb() === '0',
    info: JSON.stringify({dual: pv.dualControlRequired, limit: pv.materialityThresholdCents, s: r.s, pending: r.b.pendingApproval, status: invStatus(id)})};
});

await t('RL-04', 'A 15,000.00 customer credit note (refused above the former limit) is now posted', async () => {
  const id = await invoice(2000000);
  const ar0 = posted('1200');
  const r = await o.call('accounting-notes', {method: 'POST', json: {kind: 'customer_credit', sourceId: id, date: '2026-09-20', subtotalCents: 1500000, taxCents: 0, memo: 'Return', operationKey: key('cn'), lines: [{description: 'Return', quantityMilli: 1000, foreignUnitPriceCents: 1500000}]}});
  const cn = r.b?.note?.id || r.b?.id;
  const p = await o.call('accounting-notes', {method: 'PATCH', json: {noteId: cn, action: 'post'}});
  const st = sql(`SELECT status FROM accounting_notes WHERE id='${cn}'`);
  return {ok: r.s < 300 && p.s === 200 && st !== 'draft' && posted('1200') === ar0 - 1500000 && tb() === '0', info: JSON.stringify({create: [r.s, r.b?.code], post: [p.s, p.b?.code], status: st, ar: posted('1200') - ar0})};
});

// Requests made while limits applied (switched on in the test config only), then limits removed.
await limits(true);
const pj = await journal(o, 3000000, 'Waiting journal');
const pbR = await bill(4000000, 'WAIT'); const pb = pbR.b.bill?.id || pbR.b.id;
const pvInv = await invoice(2200000); const pv = await voidInvoice(o, pvInv);
const BANK = sql(`SELECT id FROM bank_accounts WHERE company_id='${CO}' AND account_type='bank' AND active=1 ORDER BY created_at LIMIT 1`);
const bline = async cents => { const id = need('add', await o.call('bank-transactions/add', {method: 'POST', json: {bankAccountId: BANK, date: '2026-09-12', description: 'R189 equipment ' + tag, amountCents: cents, operationKey: key('a')}})).transactions[0].id;
  const r = await o.call('bank-transactions/post', {method: 'POST', json: {decisions: [{id, accountId: acct('6300'), taxCode: 'NO_TAX', applyGstHst: false, applyPst: false}]}}); if (r.s !== 200 || r.b.failed) throw new Error('post ' + r.s); return id; };
const unpost = (s, id, extra = {}) => s.call('bank-transactions/unpost', {method: 'POST', json: {transactionId: id, reason: 'Posted to the wrong place in this test', confirmed: true, operationKey: key('u'), ...extra}});
const bst = id => sql(`SELECT status FROM bank_transactions WHERE id='${id}'`);
const pu = await bline(-1600000); const pur = await unpost(o, pu);
await limits(false);

await t('RL-05', 'With limits switched on (test config), the journal, vendor invoice and void wait for approval; this proves the setting still works when an operator enables it', async () => {
  return {ok: pj.s === 202 && pj.b.approvalRequired === true && billStatus(pb) === 'submitted_for_approval' && pv.b.pendingApproval === true && invStatus(pvInv) === 'sent' && pur.s === 202 && pur.b.pendingApproval === true && bst(pu) === 'posted',
    info: JSON.stringify({journal: [pj.s, pj.b.approvalRequired], bill: billStatus(pb), void: [pv.s, pv.b.pendingApproval], inv: invStatus(pvInv), unpost: [pur.s, bst(pu)]})};
});

await t('RL-06', 'After the limits are removed, a journal that was already waiting is still approved by a second owner (its maker cannot) and posts', async () => {
  const before = posted('1500');
  const self = await o.call('journals', {method: 'PATCH', json: {approvalId: pj.b.approvalId}});
  const ok2 = await x.call('journals', {method: 'PATCH', json: {approvalId: pj.b.approvalId}});
  return {ok: self.s === 409 && ok2.s === 200 && ok2.b.journalEntry?.status === 'posted' && posted('1500') === before + 3000000 && tb() === '0',
    info: JSON.stringify({self: [self.s, self.b?.code], ok2: [ok2.s, ok2.b?.journalEntry?.status]})};
});

await t('RL-07', 'A vendor invoice that was already waiting is still approved by a second owner and posted by that approver', async () => {
  const before = posted('6300');
  const self = await o.call('bills', {method: 'PATCH', json: {action: 'approve', billId: pb}});
  const ap = await x.call('bills', {method: 'PATCH', json: {action: 'approve', billId: pb}});
  const ps = await x.call('bills', {method: 'PATCH', json: {action: 'post', billId: pb}});
  const total = Number(sql(`SELECT total_cents FROM bills WHERE id='${pb}'`));
  return {ok: self.s === 409 && ap.s === 200 && ps.s === 200 && billStatus(pb) === 'open' && total >= 4000000 && posted('6300') === before + total && tb() === '0',
    info: JSON.stringify({self: [self.s, self.b?.code], approve: ap.s, post: [ps.s, ps.b?.code], status: billStatus(pb)})};
});

await t('RL-08', 'A void that was already waiting is still approved by a second owner (its maker cannot) and the invoice is voided', async () => {
  const self = await voidInvoice(o, pvInv, {approvalId: pv.b.approvalId});
  const ok2 = await voidInvoice(x, pvInv, {approvalId: pv.b.approvalId});
  return {ok: self.s === 409 && ok2.s === 200 && invStatus(pvInv) === 'void' && tb() === '0', info: JSON.stringify({self: [self.s, self.b?.code], ok2: [ok2.s, ok2.b?.code], status: invStatus(pvInv)})};
});
await t('RL-09', 'A bank unpost that was already waiting is still approved by a second owner (its maker cannot) and the line returns to Unposted', async () => {
  const self = await unpost(o, pu, {approvalId: pur.b.approvalId});
  const ok2 = await unpost(x, pu, {approvalId: pur.b.approvalId, reason: '', confirmed: false});
  return {ok: self.s === 409 && ok2.s === 200 && bst(pu) === 'pending' && tb() === '0', info: JSON.stringify({self: [self.s, self.b?.code], ok2: [ok2.s, ok2.b?.code], st: bst(pu)})};
});

await t('RL-10', 'The setting is off again: a new 30,000.00 journal posts at once', async () => {
  const r = await journal(o, 3000000, 'After');
  return {ok: r.s === 201 && r.b.journalEntry?.status === 'posted', info: JSON.stringify({s: r.s, status: r.b.journalEntry?.status})};
});
} finally {
  writeFileSync(CFG, ORIGINAL);
}

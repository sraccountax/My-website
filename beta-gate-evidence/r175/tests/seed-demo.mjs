// R175: fills the demo site with a synthetic sample company for the public-page screenshots.
// Run with GATE_ORIGIN=https://gate.test:8444 GATE_DB=tegh_demo (and the gate's NODE_EXTRA_CA_CERTS / mfa preload).
import {S, sql, need, mails} from '/srv/gate/t/lib.mjs';
import fs from 'fs';
const key = fs.readFileSync('/srv/gate/t/.setup-key', 'utf8').trim();
const EMAIL = 'demo-owner@gate.test', PW = 'Demo!Owner#2026pw';
const o = new S();
let r = await o.call('auth/setup', {method: 'POST', company: false, json: {setupKey: key, email: EMAIL, displayName: 'Synthetic Demo', password: PW}});
console.log('setup', r.s);
await o.login(EMAIL, PW);
r = await o.call('startup/migrate', {method: 'POST', company: false, json: {backupConfirmed: true}}); console.log('migrate', r.s, r.b?.schemaVersion);
const co = need('company', await o.call('companies', {method: 'POST', company: false, json: {name: 'Cedar & Co. (Sample)', province: 'ON', country: 'Canada', businessType: 'corporation', taxRegistered: true, taxNumber: '123456789RT0001', currency: 'CAD', accountingBasis: 'accrual', moduleMode: 'both', fiscalYearEnd: '12-31', booksStartDate: '2026-01-01', coaMode: 'default'}}));
const CID = co.company?.id || co.id; o.cid = CID; console.log('company', CID);
fs.writeFileSync('/srv/demo/demo.json', JSON.stringify({CID, EMAIL, PW}));
const acct = code => sql(`SELECT id FROM accounts WHERE company_id='${CID}' AND code='${code}'`);
const taxCode = c => sql(`SELECT id FROM tax_codes WHERE company_id='${CID}' AND code='${c}' AND status='active' LIMIT 1`);
const EXP = sql(`SELECT code FROM accounts WHERE company_id='${CID}' AND account_type='expense' AND is_control=0 AND active=1 ORDER BY code LIMIT 1`);
const BANK = sql(`SELECT id FROM bank_accounts WHERE company_id='${CID}' AND account_type='bank' ORDER BY created_at LIMIT 1`);
const BANKGL = sql(`SELECT a.code FROM bank_accounts b JOIN accounts a ON a.id=b.ledger_account_id WHERE b.id='${BANK}'`);
const tmpl = async () => { const t = await o.call('invoice-templates'); return (t.b?.templates || t.b?.invoiceTemplates || [])[0]?.id; };
const T = await tmpl();

const customers = {};
for (const [n, p] of [['Maple Street Bakery', 'ON'], ['Harbourfront Dental', 'ON'], ['Lakeside Design Studio', 'ON'], ['Northwind Physio', 'ON'], ['Riverbend Café', 'ON']]) {
  const c = need('customer', await o.call('customers', {method: 'POST', json: {name: n, email: '', phone: '416-555-01' + String(Object.keys(customers).length).padStart(2, '0'), billingAddress: '100 King St W, Toronto', province: p}}));
  customers[n] = c.customer?.id || c.id;
}
const vendors = {};
for (const n of ['Lakeshore Office Supply', 'Metro Hydro', 'Northern Software Inc.', 'Bayview Property Management']) {
  const v = need('vendor', await o.call('vendors', {method: 'POST', json: {name: n, email: '', address: '', defaultTermsDays: 30, currency: 'CAD', defaultExpenseAccountId: null}}));
  vendors[n] = v.vendor?.id || v.id;
}
const invoices = [];
for (const [c, issue, due, desc, cents] of [
  ['Maple Street Bakery', '2026-07-06', '2026-08-05', 'Monthly bookkeeping — July', 85000],
  ['Harbourfront Dental', '2026-08-03', '2026-09-02', 'Year-end planning session', 142500],
  ['Lakeside Design Studio', '2026-08-18', '2026-09-17', 'Payroll set-up', 64000],
  ['Northwind Physio', '2026-09-01', '2026-10-01', 'Monthly bookkeeping — September', 92000],
  ['Riverbend Café', '2026-09-08', '2026-10-08', 'GST/HST return preparation', 38000],
  ['Maple Street Bakery', '2026-09-15', '2026-10-15', 'Monthly bookkeeping — September', 85000],
  ['Harbourfront Dental', '2026-09-22', '2026-10-22', 'Cash-flow forecast', 116000],
]) {
  const x = await o.call('invoices', {method: 'POST', json: {customerId: customers[c], issueDate: issue, dueDate: due, currency: 'CAD', templateId: T, issue: true, lines: [{description: desc, quantity: 1, unitPriceCents: cents, taxable: true}]}});
  invoices.push({id: x.b?.invoice?.id || x.b?.id, cents, c}); console.log('invoice', x.s, c);
}
// Some customers have paid.
for (const i of [1, 3]) {
  const inv = invoices[i];
  const p = await o.call('payments', {method: 'POST', json: {type: 'customer', documentId: inv.id, paymentDate: '2026-09-25', reference: 'EFT ' + (i + 100), foreignAmountCents: Number(sql(`SELECT total_cents FROM invoices WHERE id='${inv.id}'`)), paymentAccountId: acct(BANKGL), operationKey: 'demo-payment-' + i + '-' + Date.now()}});
  console.log('payment', p.s);
}
const ON = taxCode('ON') || taxCode('HST-ON') || '';
for (const [v, num, date, cents] of [['Lakeshore Office Supply', 'LOS-4471', '2026-09-03', 18450], ['Metro Hydro', 'MH-2026-09', '2026-09-10', 21300], ['Northern Software Inc.', 'NS-88213', '2026-09-12', 49900], ['Bayview Property Management', 'BPM-OCT', '2026-09-25', 240000]]) {
  const b = await o.call('bills', {method: 'POST', json: {vendorId: vendors[v], number: num, billDate: date, dueDate: date.replace('2026-09', '2026-10'), categoryAccountId: acct(EXP), currency: 'CAD', taxEntryMode: 'exclusive', taxCodeId: ON, foreignAmountCents: cents, issue: true}});
  console.log('bill', b.s, v);
}
for (const [vendor, date, cents, memo] of [['Union Station Parking', '2026-09-18', 2400, 'Client meeting parking'], ['Staples', '2026-09-21', 6380, 'Printer paper and toner']]) {
  const e = await o.call('expenses', {method: 'POST', json: {vendor, date, expenseDate: date, categoryAccountId: acct(EXP), paidFromAccountId: acct(BANKGL), currency: 'CAD', amountCents: cents, foreignAmountCents: cents, taxEntryMode: 'inclusive', memo, operationKey: 'demo-exp-' + cents + '-' + Date.now()}});
  console.log('expense', e.s);
}
// Bank statement for September.
const csv = 'Date,Description,Amount\n2026-09-02,Opening deposit,15000.00\n2026-09-05,Metro Hydro pre-authorized debit,-240.69\n2026-09-09,Square deposit Riverbend,429.40\n2026-09-12,Northern Software Inc.,-563.87\n2026-09-15,Bank service fee,-19.95\n2026-09-18,Harbourfront Dental EFT,1610.25\n2026-09-22,Lakeshore Office Supply,-208.49\n2026-09-25,Northwind Physio EFT,1039.60\n2026-09-28,Canada Revenue Agency,-1250.00\n2026-09-30,Interest,3.12\n';
const data = Buffer.from(csv);
const st = need('upload-start', await o.call('imports/upload-start', {method: 'POST', json: {filename: 'cedar-chequing-sept.csv', size: data.length}}));
need('chunk', await o.call('imports/upload-chunk', {method: 'POST', json: {uploadId: st.uploadId, offset: 0, data: data.toString('base64')}}));
const pv = need('finish', await o.call('imports/upload-finish', {method: 'POST', json: {uploadId: st.uploadId, bankAccountId: BANK, exchangeRateMicros: 1000000, mode: 'preview'}}));
const sel = (pv.preview.rows || []).filter(x => x.selectionKey && x.eligible !== false && !x.duplicate && !x.requiresDuplicateReview).map(x => String(x.selectionKey));
r = await o.call('operations/statement-approve', {method: 'POST', json: {previewId: pv.preview.id, operationKey: 'statement_' + Date.now(), selectedKeys: sel, reviewedDuplicateKeys: [], balanceGapAcknowledged: true}});
console.log('statement', r.s, sel.length);
const tx = d => sql(`SELECT id FROM bank_transactions WHERE company_id='${CID}' AND description='${d}' ORDER BY created_at LIMIT 1`);
for (const [d, code] of [['Bank service fee', EXP], ['Interest', '4000'], ['Opening deposit', sql(`SELECT code FROM accounts WHERE company_id='${CID}' AND account_type='equity' AND is_control=0 AND active=1 ORDER BY code LIMIT 1`)]]) {
  const p = await o.call('bank-transactions/post', {method: 'POST', json: {decisions: [{id: tx(d), accountId: acct(code), taxCode: 'NO_TAX', applyGstHst: false, applyPst: false, taxCodeId: ''}]}});
  console.log('post', p.s, d);
}
// Payroll: two employees.
r = await o.call('payroll/setup', {method: 'POST', json: {payrollAccountNumber: '123456789RP0001', remitterType: 'regular', defaultFrequency: 'biweekly'}}); console.log('payroll setup', r.s);
for (const [num, first, last, salary] of [['EMP-0001', 'Priya', 'Sharma', 6200000], ['EMP-0002', 'Daniel', 'Okafor', 5400000]]) {
  r = await o.call('payroll/employees', {method: 'POST', json: {employeeNumber: num, firstName: first, lastName: last, provinceOfEmployment: 'ON', provinceOfResidence: 'ON', hireDate: '2026-01-05', payFrequency: 'biweekly', payType: 'salary', annualSalaryCents: salary, standardHours: 80, vacationRateBps: 400}});
  console.log('employee', r.s);
}
// Client dashboard link for the owner of Maple Street Bakery (a synthetic client).
r = await o.call('client-view/links', {method: 'POST', json: {clientName: 'Maple Street Bakery', clientEmail: 'client@gate.test', reports: ['profit-loss', 'balance-sheet'], expiresInDays: 30, sendEmail: true}});
const url = r.b?.link?.url || ''; fs.writeFileSync('/srv/demo/client-link.txt', url);
console.log('client link', r.s, !!url);

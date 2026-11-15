// R186 probe: what does the existing Void do to a bank line that was posted as an invoice payment?
import {S, sql, need} from '../lib.mjs';
const o = new S(); await o.login('owner@gate.test', 'Gate!Owner#2026pw');
const tag = Date.now().toString(36); let n = 0; const key = p => `r186p-${p}-${tag}-${++n}`.padEnd(20, 'x');
const CO = need('company', await o.call('companies', {method: 'POST', json: {businessType: 'corporation', country: 'Canada', province: 'ON', taxRegistered: false, moduleMode: 'accounting', fiscalYearEnd: '12-31', booksStartDate: '2026-01-01', chartTemplate: 'default', currency: 'CAD', accountingBasis: 'accrual', name: 'R186 probe ' + tag}})).company.id;
o.cid = CO;
const BANK = sql(`SELECT id FROM bank_accounts WHERE company_id='${CO}' AND account_type='bank' AND active=1 ORDER BY created_at LIMIT 1`);
const CUST = need('customer', await o.call('customers', {method: 'POST', json: {name: 'Probe Customer', email: '', phone: '', billingAddress: '1 King St', country: 'Canada', province: 'ON'}})).customer.id;
const TMPL = (await o.call('invoice-templates')).b.templates?.[0]?.id;
const inv = need('invoice', await o.call('invoices', {method: 'POST', json: {customerId: CUST, issueDate: '2026-09-01', dueDate: '2026-09-30', currency: 'CAD', templateId: TMPL, issue: true, lines: [{description: 'Work', quantity: 1, unitPriceCents: 50000, taxable: false}]}}));
const INV = inv.invoice?.id || inv.id;
const add = need('add', await o.call('bank-transactions/add', {method: 'POST', json: {bankAccountId: BANK, date: '2026-09-10', description: 'Deposit from customer', amountCents: 50000, operationKey: key('a')}}));
const BT = add.transactions[0].id;
const show = label => console.log(label, JSON.stringify({
  inv: sql(`SELECT CONCAT(status,' ',balance_cents) FROM invoices WHERE id='${INV}'`),
  bt: sql(`SELECT CONCAT(status,' ',IFNULL(journal_entry_id,'-')) FROM bank_transactions WHERE id='${BT}'`),
  pay: sql(`SELECT GROUP_CONCAT(CONCAT(status,' ',applied_cents)) FROM party_payments WHERE bank_transaction_id='${BT}'`),
  apps: sql(`SELECT GROUP_CONCAT(status) FROM party_payment_applications WHERE company_id='${CO}'`),
  ar: sql(`SELECT SUM(jl.debit_cents-jl.credit_cents) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id JOIN accounts a ON a.id=jl.account_id WHERE je.company_id='${CO}' AND je.status='posted' AND a.code='1200'`),
  bank: sql(`SELECT SUM(jl.debit_cents-jl.credit_cents) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id JOIN bank_accounts b ON b.ledger_account_id=jl.account_id WHERE je.company_id='${CO}' AND je.status='posted' AND b.id='${BANK}'`),
  journals: sql(`SELECT GROUP_CONCAT(CONCAT(source_type,':',status) ORDER BY created_at) FROM journal_entries WHERE company_id='${CO}'`),
}));
show('before');
const p = await o.call('bank-transactions/post', {method: 'POST', json: {decisions: [{id: BT, invoiceId: INV}]}});
console.log('post', p.s, JSON.stringify(p.b).slice(0, 300));
show('posted');
const pv = await o.call(`voids/preview&entityType=bank_transaction&entityId=${BT}`);
console.log('preview', pv.s, JSON.stringify(pv.b).slice(0, 600));
const v = await o.call('voids/apply', {method: 'POST', json: {entityType: 'bank_transaction', entityId: BT, reason: 'probe: test void of invoice payment', confirmed: true}});
console.log('void', v.s, JSON.stringify(v.b).slice(0, 300));
show('voided');
console.log('CO', CO, 'BT', BT, 'INV', INV);

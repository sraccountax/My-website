// R186 dev check of bank-transactions/unpost for each kind of posting. Expected figures are worked out here.
import {S, sql, need} from '../lib.mjs';
const o = new S(); await o.login('owner@gate.test', 'Gate!Owner#2026pw');
const tag = Date.now().toString(36); let n = 0; const key = p => `r186-${p}-${tag}-${++n}`.padEnd(24, 'x');
const CO = need('company', await o.call('companies', {method: 'POST', json: {businessType: 'corporation', country: 'Canada', province: 'ON', taxRegistered: false, moduleMode: 'accounting', fiscalYearEnd: '12-31', booksStartDate: '2026-01-01', chartTemplate: 'default', currency: 'CAD', accountingBasis: 'accrual', name: 'R186 dev ' + tag}})).company.id;
o.cid = CO;
const BANK = sql(`SELECT id FROM bank_accounts WHERE company_id='${CO}' AND account_type='bank' AND active=1 ORDER BY created_at LIMIT 1`);
const BANKGL = sql(`SELECT ledger_account_id FROM bank_accounts WHERE id='${BANK}'`);
const acct = c => sql(`SELECT id FROM accounts WHERE company_id='${CO}' AND code='${c}'`);
const gl = code => Number(sql(`SELECT IFNULL(SUM(jl.debit_cents-jl.credit_cents),0) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id JOIN accounts a ON a.id=jl.account_id WHERE je.company_id='${CO}' AND je.status='posted' AND a.code='${code}'`));
const bankGl = () => Number(sql(`SELECT IFNULL(SUM(jl.debit_cents-jl.credit_cents),0) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id WHERE je.company_id='${CO}' AND je.status='posted' AND jl.account_id='${BANKGL}'`));
const tb = () => Number(sql(`SELECT IFNULL(SUM(jl.debit_cents-jl.credit_cents),0) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id WHERE je.company_id='${CO}' AND je.status='posted'`));
const line = async (amountCents, d = '2026-09-10', desc = 'line') => need('add', await o.call('bank-transactions/add', {method: 'POST', json: {bankAccountId: BANK, date: d, description: desc + ' ' + tag, amountCents, operationKey: key('a')}})).transactions[0].id;
const post = async decision => { const r = await o.call('bank-transactions/post', {method: 'POST', json: {decisions: [decision]}}); if (r.s !== 200 || r.b.failed) throw new Error('post ' + r.s + ' ' + JSON.stringify(r.b).slice(0, 300)); return r.b; };
const preview = async id => (await o.call(`bank-transactions/unpost-preview&transactionId=${id}`)).b;
const unpost = async (id, extra = {}) => o.call('bank-transactions/unpost', {method: 'POST', json: {transactionId: id, reason: 'Posted to the wrong place in test', confirmed: true, operationKey: key('u'), ...extra}});
const st = id => sql(`SELECT CONCAT(status,'|',IFNULL(journal_entry_id,'-')) FROM bank_transactions WHERE id='${id}'`);
const results = []; const check = (name, ok, info) => { results.push([ok ? 'PASS' : 'FAIL', name, info]); console.log(ok ? 'PASS' : 'FAIL', name, info ?? ''); };

const CUST = need('customer', await o.call('customers', {method: 'POST', json: {name: 'Unpost Customer ' + tag, email: '', phone: '', billingAddress: '1 King St', country: 'Canada', province: 'ON'}})).customer.id;
const VEND = need('vendor', await o.call('vendors', {method: 'POST', json: {name: 'Unpost Vendor ' + tag, email: '', address: '', defaultTermsDays: 30, currency: 'CAD', defaultExpenseAccountId: null}})).vendor.id;
const TMPL = (await o.call('invoice-templates')).b.templates?.[0]?.id;
const invoice = async cents => { const r = need('invoice', await o.call('invoices', {method: 'POST', json: {customerId: CUST, issueDate: '2026-09-01', dueDate: '2026-09-30', currency: 'CAD', templateId: TMPL, issue: true, lines: [{description: 'Work', quantity: 1, unitPriceCents: cents, taxable: false}]}})); return r.invoice?.id || r.id; };
const inv = id => sql(`SELECT CONCAT(status,'|',balance_cents) FROM invoices WHERE id='${id}'`);

// 1. Posted straight to an account: rent 1,200.00 out.
{
  const id = await line(-120000, '2026-09-10', 'Rent');
  await post({id, accountId: acct('6300'), taxCode: 'NO_TAX', applyGstHst: false, applyPst: false});
  const p = await preview(id);
  check('account: preview kind', p.kind === 'account' && p.blockers.length === 0, JSON.stringify({kind: p.kind, blockers: p.blockers, acc: p.accountsAffected}));
  const vb = (await o.call(`voids/preview&entityType=bank_transaction&entityId=${id}`)).b;
  check('account: Void still allowed for a plain account posting', (vb.blockers || []).length === 0, JSON.stringify(vb.blockers));
  const before = {bank: bankGl(), rent: gl('6300')};
  const u = await unpost(id);
  check('account: unpost 200', u.s === 200 && u.b.unposted === true, JSON.stringify(u.b));
  check('account: line pending, no journal', st(id) === 'pending|-', st(id));
  check('account: bank and rent back by 1,200.00', bankGl() === before.bank + 120000 && gl('6300') === before.rent - 120000, `bank ${before.bank}->${bankGl()} rent ${before.rent}->${gl('6300')}`);
  check('account: trial balance still balances', tb() === 0, tb());
  await post({id, accountId: acct('6300'), taxCode: 'NO_TAX', applyGstHst: false, applyPst: false});
  check('account: line can be posted again', st(id).startsWith('posted|'), st(id));
  const u2 = await unpost(id); check('account: second unpost', u2.s === 200 && st(id) === 'pending|-', JSON.stringify(u2.b).slice(0, 200));
  const replayKey = key('rp'); const r1 = await post({id, accountId: acct('6300'), taxCode: 'NO_TAX', applyGstHst: false, applyPst: false});
  const a1 = await unpost(id, {operationKey: replayKey}), a2 = await unpost(id, {operationKey: replayKey});
  check('account: retried request with same key is a replay', a1.s === 200 && a2.s === 200 && a2.b.replay === true, JSON.stringify(a2.b));
  const again = await unpost(id); check('account: unposting a pending line is refused', again.s === 409, again.s + ' ' + JSON.stringify(again.b).slice(0, 200));
  console.log('rent journals:', sql(`SELECT GROUP_CONCAT(CONCAT(source_type,':',status) ORDER BY created_at) FROM journal_entries WHERE company_id='${CO}' AND memo LIKE '%Rent%'`));
}

// 2. Posted as receipt of an invoice (full) and 3. partial.
for (const [label, invCents, lineCents] of [['invoice full', 50000, 50000], ['invoice partial', 80000, 30000]]) {
  const I = await invoice(invCents);
  const id = await line(lineCents, '2026-09-12', 'Deposit ' + label);
  const ar0 = gl('1200'), bank0 = bankGl();
  await post({id, invoiceId: I});
  check(`${label}: invoice after posting`, inv(I) === (invCents === lineCents ? 'paid|0' : `partial|${invCents - lineCents}`) || inv(I).endsWith('|' + (invCents - lineCents)), inv(I));
  const vb = (await o.call(`voids/preview&entityType=bank_transaction&entityId=${id}`)).b;
  check(`${label}: generic Void now refuses this line`, (vb.blockers || []).some(b => b.includes('Unpost')), JSON.stringify(vb.blockers));
  const p = await preview(id);
  check(`${label}: preview kind document`, p.kind === 'document' && p.blockers.length === 0, JSON.stringify({kind: p.kind, blockers: p.blockers, effects: p.effects}));
  const u = await unpost(id);
  check(`${label}: unpost 200`, u.s === 200, JSON.stringify(u.b).slice(0, 300));
  check(`${label}: invoice open for full amount again`, inv(I) === `sent|${invCents}`, inv(I));
  check(`${label}: AR and bank GL back to before posting`, gl('1200') === ar0 && bankGl() === bank0, `ar ${ar0}->${gl('1200')} bank ${bank0}->${bankGl()}`);
  check(`${label}: line pending`, st(id) === 'pending|-', st(id));
  check(`${label}: payment reversed and unlinked`, sql(`SELECT GROUP_CONCAT(CONCAT(status,'|',IFNULL(bank_transaction_id,'-'))) FROM party_payments WHERE company_id='${CO}' AND document_id='${I}'`) === 'reversed|-', sql(`SELECT GROUP_CONCAT(CONCAT(status,'|',IFNULL(bank_transaction_id,'-'))) FROM party_payments WHERE company_id='${CO}' AND document_id='${I}'`));
  check(`${label}: trial balance still balances`, tb() === 0, tb());
  await post({id, invoiceId: I});
  check(`${label}: can be posted to the invoice again`, st(id).startsWith('posted|') && inv(I).endsWith('|' + (invCents - lineCents)), st(id) + ' ' + inv(I));
  const ar = await o.call('reports/v5980/receivables-ageing'); // informational
  console.log(label, 'journals:', sql(`SELECT GROUP_CONCAT(CONCAT(source_type,':',status,':',entry_date) ORDER BY created_at SEPARATOR ' ') FROM journal_entries WHERE company_id='${CO}' AND (source_id='${id}' OR source_id LIKE '${id}~%' OR source_id IN (SELECT id FROM party_payments WHERE document_id='${I}') OR source_id IN (SELECT pa.id FROM party_payment_applications pa JOIN party_payments pp ON pp.id=pa.payment_id WHERE pp.document_id='${I}'))`));
}

// 4. Bill payment.
{
  const B = need('bill', await o.call('bills', {method: 'POST', json: {vendorId: VEND, number: 'B-' + tag, billDate: '2026-09-02', dueDate: '2026-10-02', categoryAccountId: acct('6300'), currency: 'CAD', taxEntryMode: 'exclusive', taxCodeId: '', foreignAmountCents: 20000, issue: true}}));
  const BID = B.bill?.id || B.id;
  const id = await line(-20000, '2026-09-14', 'Pay vendor');
  const ap0 = gl('2050');
  await post({id, billId: BID});
  const p = await preview(id);
  const u = await unpost(id);
  const bill = sql(`SELECT CONCAT(status,'|',balance_cents) FROM bills WHERE id='${BID}'`);
  check('bill: unpost reopens the bill and AP', p.kind === 'document' && u.s === 200 && bill === 'open|20000' && gl('2050') === ap0, `${p.kind} ${u.s} ${bill} ap ${ap0}->${gl('2050')} ${JSON.stringify(u.b).slice(0, 200)}`);
}

// 5. On-account customer receipt.
{
  // New companies have no AR mapping (company_system_accounts); add it the way the schema-45 migration does.
  sql(`INSERT IGNORE INTO company_system_accounts(company_id,system_key,configured_code,account_id,status) VALUES('${CO}','accounts_receivable','1200','${acct('1200')}','active')`);
  const id = await line(15000, '2026-09-15', 'Advance from customer');
  const ar0 = gl('1200');
  const r = await o.call('bank-transactions/post', {method: 'POST', json: {decisions: [{id, accountId: acct('1200'), partyId: CUST, flowKind: 'customer_receipt', taxCode: 'NO_TAX', applyGstHst: false, applyPst: false}]}});
  if (r.s !== 200 || r.b.failed) console.log('on-account post', r.s, JSON.stringify(r.b).slice(0, 300));
  const p = await preview(id);
  const u = await unpost(id);
  check('on account: unpost reverses receipt', p.kind === 'on_account' && u.s === 200 && gl('1200') === ar0 && st(id) === 'pending|-', `${p.kind} ${u.s} ar ${ar0}->${gl('1200')} ${st(id)} ${JSON.stringify(u.b).slice(0, 200)}`);
}

// 6. Matched to a payment posted earlier in Payments.
{
  const I = await invoice(40000);
  const pay = need('payment', await o.call('payments', {method: 'POST', json: {type: 'customer', documentId: I, paymentDate: '2026-09-16', reference: 'EFT ' + tag, foreignAmountCents: 40000, paymentAccountId: BANKGL, operationKey: key('pay')}}));
  const PID = pay.payment?.id || sql(`SELECT id FROM party_payments WHERE company_id='${CO}' AND document_id='${I}' AND status='posted'`);
  const id = await line(40000, '2026-09-16', 'EFT deposit');
  const bank0 = bankGl();
  await post({id, paymentId: PID});
  const p = await preview(id);
  const u = await unpost(id);
  const payRow = sql(`SELECT CONCAT(status,'|',IFNULL(bank_transaction_id,'-')) FROM party_payments WHERE id='${PID}'`);
  check('matched payment: unpost only unlinks', p.kind === 'matched_payment' && u.s === 200 && payRow === 'posted|-' && inv(I) === 'paid|0' && bankGl() === bank0 && st(id) === 'pending|-', `${p.kind} ${u.s} pay ${payRow} inv ${inv(I)} bank ${bank0}->${bankGl()} ${st(id)}`);
  await post({id, paymentId: PID});
  check('matched payment: can be matched again', st(id).startsWith('posted|'), st(id));
}

// 7. Material amount needs a second owner (threshold 10,000.00 by default).
{
  const id = await line(-1500000, '2026-09-18', 'Equipment');
  await post({id, accountId: acct('6300'), taxCode: 'NO_TAX', applyGstHst: false, applyPst: false});
  const u = await unpost(id);
  check('material: first owner gets pending approval', u.s === 202 && u.b.pendingApproval === true && st(id).startsWith('posted|'), u.s + ' ' + JSON.stringify(u.b).slice(0, 200));
  const self = await unpost(id, {approvalId: u.b.approvalId});
  check('material: requester cannot approve own request', self.s === 409, self.s + ' ' + JSON.stringify(self.b).slice(0, 200));
}

// 8. Closed period refuses.
console.log(JSON.stringify(results.filter(r => r[0] !== 'PASS')));
console.log('CO', CO, `${results.filter(r => r[0] === 'PASS').length}/${results.length} pass`);

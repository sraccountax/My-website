// R186: Unpost a posted bank line from Match and Post; edit and delete unposted lines there.
// Every expected figure is worked out here from the amounts the test enters, never read from Tegh first.
import {S, sql, rec, save, need} from './lib.mjs';
import {open, confirm} from './e2e/base.mjs';

const A = 'R186';
const t = async (id, title, fn) => { try { const r = await fn(); rec(id, A, title, r.ok ? 'PASS' : 'FAIL', r.info); return r.ok; } catch (e) { rec(id, A, title, 'FAIL', String(e.message || e).slice(0, 400)); return false; } };
const PW = 'Gate!Member#2026pw';
const o = new S(); await o.login('owner@gate.test', 'Gate!Owner#2026pw');
const tag = Date.now().toString(36); let n = 0; const key = p => `r186-${p}-${tag}-${++n}`.padEnd(24, 'x');
const CO = need('company', await o.call('companies', {method: 'POST', json: {businessType: 'corporation', country: 'Canada', province: 'ON', taxRegistered: false, moduleMode: 'accounting', fiscalYearEnd: '12-31', booksStartDate: '2026-01-01', chartTemplate: 'default', currency: 'CAD', accountingBasis: 'accrual', name: 'R186 Unpost ' + tag}})).company.id;
o.cid = CO;
const BANK = sql(`SELECT id FROM bank_accounts WHERE company_id='${CO}' AND account_type='bank' AND active=1 ORDER BY created_at LIMIT 1`);
const BANKGL = sql(`SELECT ledger_account_id FROM bank_accounts WHERE id='${BANK}'`);
const acct = c => sql(`SELECT id FROM accounts WHERE company_id='${CO}' AND code='${c}'`);
// Test set-up only: the other gate users join this synthetic company, and the AR control mapping that the schema-45
// migration creates for older companies is added (new companies do not get one; see RESULTS.md).
const uid = e => sql(`SELECT id FROM users WHERE email='${e}'`);
for (const [email, role] of [['otherowner@gate.test', 'owner'], ['editor@gate.test', 'editor'], ['viewer@gate.test', 'viewer']])
  sql(`INSERT IGNORE INTO company_members(company_id,user_id,role,status) VALUES('${CO}','${uid(email)}','${role}','active')`);
sql(`INSERT IGNORE INTO company_system_accounts(company_id,system_key,configured_code,account_id,status) VALUES('${CO}','accounts_receivable','1200','${acct('1200')}','active')`);

const sum = where => Number(sql(`SELECT IFNULL(SUM(jl.debit_cents-jl.credit_cents),0) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id JOIN accounts a ON a.id=jl.account_id WHERE je.company_id='${CO}' AND je.status='posted' ${where}`));
const gl = code => sum(`AND a.code='${code}'`), bankGl = () => sum(`AND a.id='${BANKGL}'`), tb = () => sum('');
const journals = () => Number(sql(`SELECT COUNT(*) FROM journal_entries WHERE company_id='${CO}'`));
const line = async (amountCents, d, desc) => need('add', await o.call('bank-transactions/add', {method: 'POST', json: {bankAccountId: BANK, date: d, description: desc + ' ' + tag, amountCents, operationKey: key('a')}})).transactions[0].id;
const post = async decision => { const r = await o.call('bank-transactions/post', {method: 'POST', json: {decisions: [decision]}}); if (r.s !== 200 || r.b.failed) throw new Error('post ' + r.s + ' ' + JSON.stringify(r.b).slice(0, 300)); return r.b; };
const toAccount = (id, code) => post({id, accountId: acct(code), taxCode: 'NO_TAX', applyGstHst: false, applyPst: false});
const preview = async (s, id) => s.call(`bank-transactions/unpost-preview&transactionId=${id}`);
const unpost = (s, id, extra = {}) => s.call('bank-transactions/unpost', {method: 'POST', json: {transactionId: id, reason: 'Posted to the wrong place in this test', confirmed: true, operationKey: key('u'), ...extra}});
const st = id => sql(`SELECT CONCAT(status,'|',IFNULL(journal_entry_id,'-')) FROM bank_transactions WHERE id='${id}'`);
const CUST = need('customer', await o.call('customers', {method: 'POST', json: {name: 'R186 Customer ' + tag, email: '', phone: '', billingAddress: '1 King St', country: 'Canada', province: 'ON'}})).customer.id;
const VEND = need('vendor', await o.call('vendors', {method: 'POST', json: {name: 'R186 Vendor ' + tag, email: '', address: '', defaultTermsDays: 30, currency: 'CAD', defaultExpenseAccountId: null}})).vendor.id;
const TMPL = (await o.call('invoice-templates')).b.templates?.[0]?.id;
const invoice = async cents => { const r = need('invoice', await o.call('invoices', {method: 'POST', json: {customerId: CUST, issueDate: '2026-09-01', dueDate: '2026-09-30', currency: 'CAD', templateId: TMPL, issue: true, lines: [{description: 'Work', quantity: 1, unitPriceCents: cents, taxable: false}]}})); return r.invoice?.id || r.id; };
const inv = id => sql(`SELECT CONCAT(status,'|',balance_cents) FROM invoices WHERE id='${id}'`);
const arAgeing = async () => ((await o.call('portal/aging?type=receivable&asOf=2026-09-30')).b?.totals || []).reduce((a, b) => a + b, 0);

try {
await t('UP-01', 'Posted to an account: Unpost takes the entry out of every figure, keeps it in the Day Book and returns the line to Unposted', async () => {
  const id = await line(-120000, '2026-09-10', 'Rent'); // rent 1,200.00 out
  await toAccount(id, '6300');
  const before = {bank: bankGl(), exp: gl('6300'), j: journals()};
  const p = (await preview(o, id)).b;
  const u = await unpost(o, id);
  const after = {bank: bankGl(), exp: gl('6300'), j: journals(), st: st(id), tb: tb(),
    jstatus: sql(`SELECT GROUP_CONCAT(status) FROM journal_entries WHERE company_id='${CO}' AND source_id LIKE '${id}%'`),
    audit: sql(`SELECT JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.reason')) FROM audit_log WHERE company_id='${CO}' AND action='bank_transaction.unposted' AND entity_id='${id}' LIMIT 1`)};
  const ok = p.kind === 'account' && p.blockers.length === 0 && u.s === 200 && u.b.unposted === true && after.bank === before.bank + 120000 && after.exp === before.exp - 120000
    && after.st === 'pending|-' && after.tb === 0 && after.j === before.j && after.jstatus === 'reversed' && after.audit === 'Posted to the wrong place in this test';
  return {ok, info: JSON.stringify({kind: p.kind, s: u.s, before, after})};
});

await t('UP-02', 'An unposted line posts again; a retried request with the same key changes nothing more; a pending line cannot be unposted', async () => {
  const id = await line(-4500, '2026-09-11', 'Bank fee'); await toAccount(id, '6300');
  const u1 = await unpost(o, id); await toAccount(id, '6300'); const reposted = st(id).startsWith('posted|');
  const k = key('same'); const a = await unpost(o, id, {operationKey: k}); const bankAfter = bankGl(); const b = await unpost(o, id, {operationKey: k});
  const again = await unpost(o, id);
  const ok = u1.s === 200 && reposted && a.s === 200 && b.s === 200 && b.b.replay === true && bankGl() === bankAfter && again.s === 409 && st(id) === 'pending|-';
  return {ok, info: JSON.stringify({u1: u1.s, reposted, a: a.s, b: b.b, again: again.s})};
});

for (const [id_, label, invCents, lineCents] of [['UP-03', 'paid in full', 50000, 50000], ['UP-04', 'paid in part', 80000, 30000]]) {
  await t(id_, `Receipt of an invoice ${label}: Unpost reverses the receipt on the statement date and the invoice is open for its full amount again`, async () => {
    const I = await invoice(invCents);
    const id = await line(lineCents, '2026-09-12', 'Deposit ' + label);
    const ar0 = gl('1200'), bank0 = bankGl();
    await post({id, invoiceId: I}); const posted = inv(I);
    const vb = (await o.call(`voids/preview&entityType=bank_transaction&entityId=${id}`)).b;
    const p = (await preview(o, id)).b;
    const u = await unpost(o, id);
    const pay = sql(`SELECT GROUP_CONCAT(CONCAT(status,'|',IFNULL(bank_transaction_id,'-'))) FROM party_payments WHERE company_id='${CO}' AND document_id='${I}'`);
    const revDate = sql(`SELECT GROUP_CONCAT(entry_date) FROM journal_entries WHERE company_id='${CO}' AND source_type='customer_payment_reversal' AND source_id IN (SELECT id FROM party_payments WHERE document_id='${I}')`);
    const ok = posted.endsWith('|' + (invCents - lineCents)) && (vb.blockers || []).some(b => b.includes('Unpost')) && p.kind === 'document' && p.blockers.length === 0
      && u.s === 200 && inv(I) === `sent|${invCents}` && gl('1200') === ar0 && bankGl() === bank0 && st(id) === 'pending|-' && pay === 'reversed|-' && revDate === '2026-09-12' && tb() === 0;
    await post({id, invoiceId: I}); const repost = inv(I);
    return {ok: ok && repost.endsWith('|' + (invCents - lineCents)), info: JSON.stringify({posted, voidBlocked: vb.blockers, kind: p.kind, effects: p.effects, s: u.s, inv: inv(I), ar: [ar0, gl('1200')], bank: [bank0, bankGl()], pay, revDate, repost})};
  });
}

await t('UP-05', 'Payment of a bill: Unpost reopens the bill and Accounts Payable', async () => {
  const B = need('bill', await o.call('bills', {method: 'POST', json: {vendorId: VEND, number: 'B-' + tag, billDate: '2026-09-02', dueDate: '2026-10-02', categoryAccountId: acct('6300'), currency: 'CAD', taxEntryMode: 'exclusive', taxCodeId: '', foreignAmountCents: 20000, issue: true}}));
  const BID = B.bill?.id || B.id; const id = await line(-20000, '2026-09-14', 'Pay vendor'); const ap0 = gl('2050'), bank0 = bankGl();
  await post({id, billId: BID}); const paid = sql(`SELECT CONCAT(status,'|',balance_cents) FROM bills WHERE id='${BID}'`);
  const u = await unpost(o, id); const bill = sql(`SELECT CONCAT(status,'|',balance_cents) FROM bills WHERE id='${BID}'`);
  return {ok: paid.endsWith('|0') && u.s === 200 && bill === 'open|20000' && gl('2050') === ap0 && bankGl() === bank0 && tb() === 0, info: JSON.stringify({paid, s: u.s, bill, ap: [ap0, gl('2050')]})};
});

let ON_ACCOUNT_LINE = '';
await t('UP-06', 'On-account customer receipt: Unpost reverses it; once that receipt is applied to an invoice, Unpost is refused until the allocation is undone', async () => {
  const id = await line(15000, '2026-09-15', 'Advance'); const ar0 = gl('1200');
  await post({id, accountId: acct('1200'), partyId: CUST, flowKind: 'customer_receipt', taxCode: 'NO_TAX', applyGstHst: false, applyPst: false});
  const k1 = (await preview(o, id)).b.kind; const u = await unpost(o, id); const back = gl('1200') === ar0 && st(id) === 'pending|-';
  // Post it on account again, then apply it to an invoice in Payments: Unpost must now refuse.
  await post({id, accountId: acct('1200'), partyId: CUST, flowKind: 'customer_receipt', taxCode: 'NO_TAX', applyGstHst: false, applyPst: false});
  const PID = sql(`SELECT id FROM party_payments WHERE company_id='${CO}' AND bank_transaction_id='${id}' AND status='posted'`);
  const I = await invoice(15000);
  const ap = await o.call('payments', {method: 'PATCH', json: {action: 'apply', applicationDate: '2026-09-20', operationKey: key('apply'), allocations: [{paymentId: PID, documentId: I, foreignAmountCents: 15000}]}});
  const p = (await preview(o, id)).b; const refused = await unpost(o, id);
  ON_ACCOUNT_LINE = id;
  return {ok: k1 === 'on_account' && u.s === 200 && back && ap.s === 200 && p.blockers.some(b => b.includes('Unapply')) && refused.s === 409 && st(id).startsWith('posted|') && inv(I) === 'paid|0',
    info: JSON.stringify({k1, s: u.s, back, apply: ap.s, blockers: p.blockers, refused: refused.s, inv: inv(I)})};
});

await t('UP-07', 'Matched to a payment posted earlier: Unpost removes only the link; the payment and the paid invoice stay as they are', async () => {
  const I = await invoice(40000);
  need('payment', await o.call('payments', {method: 'POST', json: {type: 'customer', documentId: I, paymentDate: '2026-09-16', reference: 'EFT ' + tag, foreignAmountCents: 40000, paymentAccountId: BANKGL, operationKey: key('pay')}}));
  const PID = sql(`SELECT id FROM party_payments WHERE company_id='${CO}' AND document_id='${I}' AND status='posted'`);
  const id = await line(40000, '2026-09-16', 'EFT deposit'); const bank0 = bankGl(), j0 = journals();
  await post({id, paymentId: PID});
  const p = (await preview(o, id)).b; const u = await unpost(o, id);
  const pay = sql(`SELECT CONCAT(status,'|',IFNULL(bank_transaction_id,'-')) FROM party_payments WHERE id='${PID}'`);
  await post({id, paymentId: PID}); const again = st(id).startsWith('posted|');
  return {ok: p.kind === 'matched_payment' && u.s === 200 && pay === 'posted|-' && inv(I) === 'paid|0' && bankGl() === bank0 && journals() === j0 && again, info: JSON.stringify({kind: p.kind, s: u.s, pay, inv: inv(I), bank: [bank0, bankGl()], journals: [j0, journals()], again})};
});

await t('UP-08', 'After all of the above, the receivable ageing total equals the Accounts Receivable balance and the trial balance balances', async () => {
  const ag = await arAgeing(), ar = gl('1200');
  return {ok: ag === ar && tb() === 0, info: JSON.stringify({ageing: ag, gl1200: ar, tb: tb()})};
});

await t('UP-09', 'Above the approval limit (10,000.00): the request waits; its maker cannot approve it; a second Company Owner approves and the line is unposted', async () => {
  const id = await line(-1500000, '2026-09-18', 'Equipment'); await toAccount(id, '6300'); const bank0 = bankGl();
  const r1 = await unpost(o, id); const waiting = st(id).startsWith('posted|') && bankGl() === bank0;
  const self = await unpost(o, id, {approvalId: r1.b.approvalId});
  const x = new S(); await x.login('otherowner@gate.test', PW); x.cid = CO;
  const pv = (await preview(x, id)).b;
  const ok2 = await unpost(x, id, {approvalId: r1.b.approvalId, reason: '', confirmed: false});
  const appr = sql(`SELECT status FROM journal_approvals WHERE id='${r1.b.approvalId}'`);
  return {ok: r1.s === 202 && r1.b.pendingApproval && waiting && self.s === 409 && pv.pendingApproval?.canApprove === true && ok2.s === 200 && st(id) === 'pending|-' && bankGl() === bank0 + 1500000 && appr === 'posted',
    info: JSON.stringify({r1: r1.s, waiting, self: self.s, canApprove: pv.pendingApproval?.canApprove, ok2: [ok2.s, ok2.b?.error], appr})};
});

await t('UP-10', 'A line in a closed period cannot be unposted; it stays posted and the books do not change', async () => {
  const id = await line(-7000, '2026-08-20', 'August fee'); await toAccount(id, '6300'); const bank0 = bankGl();
  need('close', await o.call('period-close', {method: 'POST', json: {closedThroughDate: '2026-08-31'}}));
  const p = (await preview(o, id)).b; const u = await unpost(o, id);
  const reopen = await o.call('period-close', {method: 'POST', json: {closedThroughDate: ''}});
  return {ok: p.blockers.some(b => b.includes('closed period')) && u.s === 409 && st(id).startsWith('posted|') && bankGl() === bank0, info: JSON.stringify({blockers: p.blockers, s: u.s, reopen: reopen.s})};
});

await t('UP-11', 'An Editor and a Viewer cannot unpost (as with Void); the line is unchanged', async () => {
  const id = await line(-3000, '2026-09-19', 'Parking'); await toAccount(id, '6300');
  const ed = new S(); await ed.login('editor@gate.test', PW); ed.cid = CO; const vw = new S(); await vw.login('viewer@gate.test', PW); vw.cid = CO;
  const a = await unpost(ed, id), b = await unpost(vw, id), pv = await preview(vw, id);
  return {ok: a.s === 403 && b.s === 403 && pv.s === 200 && st(id).startsWith('posted|'), info: JSON.stringify({editor: a.s, viewer: b.s, viewerPreview: pv.s})};
});

await t('UP-12', 'Preview changes nothing', async () => {
  const id = await line(-2500, '2026-09-19', 'Coffee'); await toAccount(id, '6300');
  const snap = () => [journals(), tb(), bankGl(), st(id), sql(`SELECT COUNT(*) FROM audit_log WHERE company_id='${CO}'`)].join(',');
  const s0 = snap(); for (let i = 0; i < 3; i++) await preview(o, id); const s1 = snap();
  return {ok: s0 === s1, info: s0 + ' / ' + s1};
});

// ---- Browser: Match and Post ----
let UI_LINES = {};
await t('UI-01', 'Match and Post (1440 px): posted lines show Unpost; unposted lines show ✎ and 🗑; Unpost from the row returns the line to Unposted', async () => {
  UI_LINES.posted = await line(-6600, '2026-09-21', 'UI phone bill'); await toAccount(UI_LINES.posted, '6300');
  UI_LINES.edit = await line(-1000, '2026-09-22', 'UI edit me'); UI_LINES.del = await line(-2000, '2026-09-23', 'UI delete me');
  const bank0 = bankGl();
  const {b, p, errs} = await open(CO, {w: 1440, h: 900}); const got = {};
  try {
    await p.evaluate(() => TeghPortal.invokeMenuAction('Banking', 'Match And Post Transactions'));
    await p.waitForSelector('[data-r28-bank-row]', {timeout: 60000}); await p.waitForTimeout(1200);
    got.buttons = await p.evaluate(() => [...document.querySelectorAll('[data-r28-bank-row]')].map(r => [r.dataset.status, !!r.querySelector('[data-r186-unpost-line]'), !!r.querySelector('[data-r182-edit-desc]'), !!r.querySelector('[data-r186-delete-line]')]));
    const row = p.locator('[data-r28-bank-row]', {hasText: 'UI phone bill'});
    await row.locator('[data-r186-unpost-line]').click();
    await p.waitForSelector('.r186-unpost [data-r186-form]', {timeout: 20000});
    got.effects = await p.evaluate(() => [...document.querySelectorAll('.r186-effects li')].map(x => x.textContent));
    await p.screenshot({path: '/srv/gate/ev/shots/r186-unpost-dialog-1440.png'});
    await p.fill('.r186-unpost textarea[name=reason]', 'Wrong account in the browser test');
    await p.check('.r186-unpost input[name=confirmed]'); await p.click('.r186-unpost [data-r186-submit]');
    await p.waitForFunction(() => !document.querySelector('.r186-unpost'), null, {timeout: 20000}); await p.waitForTimeout(2000);
    got.rowStatus = await p.evaluate(() => [...document.querySelectorAll('[data-r28-bank-row]')].find(r => r.textContent.includes('UI phone bill'))?.dataset.status);
    await p.screenshot({path: '/srv/gate/ev/shots/r186-match-post-1440.png'});
  } finally { got.errs = errs; await b.close(); }
  const okButtons = got.buttons.every(([s, un, ed, del]) => s === 'posted' ? un && !ed && !del : s === 'pending' ? !un && ed && del : true);
  return {ok: okButtons && got.effects.length >= 2 && got.rowStatus === 'pending' && st(UI_LINES.posted) === 'pending|-' && bankGl() === bank0 + 6600 && !got.errs.length, info: JSON.stringify(got)};
});

await t('UI-02', 'Match and Post: ✎ edits the date, direction and amount of an unposted line; the audit history keeps the old values', async () => {
  const {b, p, errs} = await open(CO, {w: 1440, h: 900}); const got = {};
  try {
    await p.evaluate(() => TeghPortal.invokeMenuAction('Banking', 'Match And Post Transactions'));
    await p.waitForSelector('[data-r28-bank-row]', {timeout: 60000}); await p.waitForTimeout(1200);
    await p.locator('[data-r28-bank-row]', {hasText: 'UI edit me'}).locator('[data-r182-edit-desc]').click();
    await p.waitForSelector('[data-r186-edit]', {timeout: 20000});
    await p.screenshot({path: '/srv/gate/ev/shots/r186-edit-dialog-1440.png'});
    await p.check('[data-r186-edit] input[name=direction][value=in]'); await p.fill('[data-r186-edit] input[name=amount]', '12.34');
    await p.fill('[data-r186-edit] input[name=date]', '2026-09-24'); await p.fill('[data-r186-edit] input[name=description]', 'UI edited line ' + 'x');
    await p.fill('[data-r186-edit] input[name=remarks]', 'checked against receipt');
    await p.click('[data-r186-save]'); await p.waitForFunction(() => !document.querySelector('[data-r186-edit]'), null, {timeout: 20000}); await p.waitForTimeout(1500);
  } finally { got.errs = errs; await b.close(); }
  const row = sql(`SELECT CONCAT(transaction_date,'|',amount_cents,'|',foreign_amount_cents,'|',description,'|',remarks,'|',status) FROM bank_transactions WHERE id='${UI_LINES.edit}'`);
  const audit = sql(`SELECT JSON_EXTRACT(metadata_json,'$.before.amountCents') FROM audit_log WHERE company_id='${CO}' AND action='bank_transaction.edited' AND entity_id='${UI_LINES.edit}' LIMIT 1`);
  return {ok: row === '2026-09-24|1234|1234|UI edited line x|checked against receipt|pending' && audit === '-1000' && !got.errs.length, info: JSON.stringify({row, audit, errs: got.errs})};
});

await t('UI-03', 'Match and Post: 🗑 deletes an unposted line after confirmation; nothing in the ledger changes', async () => {
  const j0 = journals(); const {b, p, errs} = await open(CO, {w: 1440, h: 900});
  try {
    await p.evaluate(() => TeghPortal.invokeMenuAction('Banking', 'Match And Post Transactions'));
    await p.waitForSelector('[data-r28-bank-row]', {timeout: 60000}); await p.waitForTimeout(1200);
    await p.locator('[data-r28-bank-row]', {hasText: 'UI delete me'}).locator('[data-r186-delete-line]').click(); await confirm(p); await p.waitForTimeout(2500);
  } finally { await b.close(); }
  const left = sql(`SELECT COUNT(*) FROM bank_transactions WHERE id='${UI_LINES.del}'`);
  return {ok: left === '0' && journals() === j0 && !errs.length, info: JSON.stringify({left, journals: [j0, journals()], errs})};
});

await t('UI-04', 'Match and Post at 390 px: the row buttons stay inside their rows and the page has no sideways scroll', async () => {
  const {b, p, errs} = await open(CO, {w: 390, h: 844}); const got = {};
  try {
    await p.evaluate(() => TeghPortal.invokeMenuAction('Banking', 'Match And Post Transactions'));
    await p.waitForSelector('[data-r28-bank-row]', {timeout: 60000}); await p.waitForTimeout(1500);
    got.inside = await p.evaluate(() => [...document.querySelectorAll('.r186-row-actions')].map(a => { const r = a.getBoundingClientRect(), row = a.closest('tr').getBoundingClientRect(); return r.left >= row.left - 1 && r.right <= row.right + 1 && r.width > 0; }));
    got.overflow = await p.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    await p.locator('[data-r28-bank-row]').first().scrollIntoViewIfNeeded(); await p.waitForTimeout(400);
    await p.screenshot({path: '/srv/gate/ev/shots/r186-match-post-390.png', fullPage: false});
  } finally { got.errs = errs; await b.close(); }
  return {ok: got.inside.length > 0 && got.inside.every(Boolean) && got.overflow <= 0 && !got.errs.length, info: JSON.stringify(got)};
});
await t('UI-05', 'Bank review queue: an unposted line shows Edit and Delete beside Post & Next; a posted line shows Unpost, which opens the same preview dialog', async () => {
  await line(-1500, '2026-09-25', 'Review queue line');
  const {b, p, errs} = await open(CO, {w: 1440, h: 900}); const got = {};
  try {
    await p.evaluate(() => TeghPortal.openBankReview()); await p.waitForSelector('[data-review-row]', {timeout: 60000}); await p.waitForTimeout(1500);
    await p.locator('[data-review-row]').first().click(); await p.waitForTimeout(1200);
    got.pending = await p.evaluate(() => ({edit: !!document.querySelector('[data-review-detail-footer] [data-r186-edit]'), del: !!document.querySelector('[data-review-detail-footer] [data-r186-delete]'), post: !!document.querySelector('[data-review-detail-footer] [data-review-post-next]')}));
    await p.click('[data-review-tab="posted"]'); await p.waitForTimeout(2000);
    await p.locator('[data-review-row]').first().click(); await p.waitForTimeout(1500);
    got.unpost = await p.locator('[data-review-detail-footer] [data-r186-unpost]').count();
    if (got.unpost) { await p.click('[data-review-detail-footer] [data-r186-unpost]'); await p.waitForSelector('.r186-unpost', {timeout: 20000}); got.dialog = await p.evaluate(() => document.querySelector('.r186-unpost h2')?.textContent); await p.screenshot({path: '/srv/gate/ev/shots/r186-review-unpost-1440.png'}); }
  } finally { got.errs = errs; await b.close(); }
  return {ok: got.pending.edit && got.pending.del && got.pending.post && got.unpost === 1 && got.dialog === 'Unpost this line?' && !got.errs.length, info: JSON.stringify(got)};
});
} finally {
  save('r186.json');
}

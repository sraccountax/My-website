// R187: GL line memos name the invoice or note; one Journal Entry menu (journal, expense, interbank transfer); interbank
// transfers in Match and Post; Reconcile Account finds book entries that match bank lines; Bank Reconciliation Report
// empty-state note; GIFI under Tax Reports; cancelling an invoice edit stays on the invoice; Day Book downloads.
// Synthetic company only. Expected figures are worked out here from the amounts the test enters.
import {S, sql, rec, save, need} from './lib.mjs';
import {open, confirm} from './e2e/base.mjs';
import fs from 'fs';

const A = 'R187';
const t = async (id, title, fn) => { try { const r = await fn(); rec(id, A, title, r.ok ? 'PASS' : 'FAIL', r.info); return r.ok; } catch (e) { rec(id, A, title, 'FAIL', String(e.message || e).slice(0, 400)); return false; } };
const o = new S(); await o.login('owner@gate.test', 'Gate!Owner#2026pw');
const tag = Date.now().toString(36); let n = 0; const key = p => `r187-${p}-${tag}-${++n}`.padEnd(24, 'x');
const CO = need('company', await o.call('companies', {method: 'POST', json: {businessType: 'corporation', country: 'Canada', province: 'ON', taxRegistered: false, moduleMode: 'accounting', fiscalYearEnd: '12-31', booksStartDate: '2026-01-01', chartTemplate: 'default', currency: 'CAD', accountingBasis: 'accrual', name: 'R187 Batch ' + tag}})).company.id;
o.cid = CO;
const acct = c => sql(`SELECT id FROM accounts WHERE company_id='${CO}' AND code='${c}'`);
sql(`INSERT IGNORE INTO company_system_accounts(company_id,system_key,configured_code,account_id,status) VALUES('${CO}','accounts_receivable','1200','${acct('1200')}','active')`);
const BANK = sql(`SELECT id FROM bank_accounts WHERE company_id='${CO}' AND account_type='bank' AND active=1 ORDER BY created_at LIMIT 1`);
need('second bank', await o.call('bank-accounts', {method: 'POST', json: {name: 'R187 Savings', currency: 'CAD', accountType: 'bank', type: 'bank', ledgerCode: '1016', ledgerName: 'R187 Savings'}}));
const SAV = sql(`SELECT id FROM bank_accounts WHERE company_id='${CO}' AND name='R187 Savings'`);
const gl = id => sql(`SELECT ledger_account_id FROM bank_accounts WHERE id='${id}'`);
const BANKGL = gl(BANK), SAVGL = gl(SAV);
const sum = where => Number(sql(`SELECT IFNULL(SUM(jl.debit_cents-jl.credit_cents),0) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id JOIN accounts a ON a.id=jl.account_id WHERE je.company_id='${CO}' AND je.status='posted' ${where}`));
const bal = accountId => sum(`AND a.id='${accountId}'`), tb = () => sum('');
const journals = () => Number(sql(`SELECT COUNT(*) FROM journal_entries WHERE company_id='${CO}'`));
const line = async (bankId, amountCents, d, desc) => need('add', await o.call('bank-transactions/add', {method: 'POST', json: {bankAccountId: bankId, date: d, description: desc + ' ' + tag, amountCents, operationKey: key('a')}})).transactions[0].id;
const st = id => sql(`SELECT CONCAT(status,'|',IFNULL(journal_entry_id,'-')) FROM bank_transactions WHERE id='${id}'`);
const CUST = need('customer', await o.call('customers', {method: 'POST', json: {name: 'R187 Customer ' + tag, email: '', phone: '', billingAddress: '1 King St', country: 'Canada', province: 'ON'}})).customer.id;
const VEND = need('vendor', await o.call('vendors', {method: 'POST', json: {name: 'R187 Vendor ' + tag, email: '', address: '', defaultTermsDays: 30, currency: 'CAD', defaultExpenseAccountId: null}})).vendor.id;
const TMPL = (await o.call('invoice-templates')).b.templates?.[0]?.id;
const lineMemos = (source, id) => sql(`SELECT GROUP_CONCAT(jl.memo ORDER BY jl.debit_cents DESC SEPARATOR ' || ') FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id WHERE je.company_id='${CO}' AND je.source_type='${source}' AND je.source_id='${id}'`);
const DOCS = {};

try {
// ---------------- 1. GL line memos name the document ----------------
await t('GL-01', 'Customer invoice: every General Ledger line of its entry names the invoice number and the customer', async () => {
  const r = need('invoice', await o.call('invoices', {method: 'POST', json: {customerId: CUST, issueDate: '2026-09-01', dueDate: '2026-09-30', currency: 'CAD', templateId: TMPL, issue: true, lines: [{description: 'Consulting', quantity: 1, unitPriceCents: 40000, taxable: false}]}}));
  const id = r.invoice?.id || r.id; DOCS.invoice = id; const num = sql(`SELECT number FROM invoices WHERE id='${id}'`);
  const memos = lineMemos('invoice', id), parts = memos.split(' || ');
  return {ok: parts.length >= 2 && parts.every(m => m.includes(num) && m.includes('R187 Customer')), info: JSON.stringify({num, memos})};
});
await t('GL-02', 'Vendor invoice: every General Ledger line of its entry names the vendor invoice number and the vendor', async () => {
  const B = need('bill', await o.call('bills', {method: 'POST', json: {vendorId: VEND, number: 'R187-B-' + tag, billDate: '2026-09-02', dueDate: '2026-10-02', categoryAccountId: acct('6300'), currency: 'CAD', taxEntryMode: 'exclusive', taxCodeId: '', foreignAmountCents: 12345, issue: true}}));
  const id = B.bill?.id || B.id; DOCS.bill = id;
  const memos = lineMemos('bill', id), parts = memos.split(' || ');
  return {ok: parts.length >= 2 && parts.every(m => m.includes('R187-B-' + tag) && m.includes('R187 Vendor')), info: memos};
});
await t('GL-03', 'Customer credit note: every General Ledger line names the credit note number and the customer', async () => {
  const r = await o.call('accounting-notes', {method: 'POST', json: {kind: 'customer_credit', partyId: CUST, referenceNumber: 'OLD-' + tag, date: '2026-09-05', currency: 'CAD', memo: 'Refund', operationKey: key('n'), lines: [{description: 'Refund', quantity: 1, unitPriceCents: 5000, accountId: acct('4000'), taxCodeId: ''}]}});
  const N = r.b?.note?.id; const p = await o.call('accounting-notes', {method: 'PATCH', json: {noteId: N, action: 'post'}});
  const num = sql(`SELECT number FROM accounting_notes WHERE id='${N}'`), memos = lineMemos('accounting_note', N), parts = memos.split(' || ');
  return {ok: r.s < 300 && p.s === 200 && parts.length >= 2 && parts.every(m => m.includes(num) && m.includes('R187 Customer') && m.includes('Credit note')), info: JSON.stringify({create: r.s, post: p.s, num, memos})};
});
await t('GL-04', 'Entries already in the books keep their stored line memos (nothing is rewritten); a manual journal\'s own line memos are unchanged', async () => {
  const j = need('journal', await o.call('journals', {method: 'POST', json: {date: '2026-09-06', memo: 'Accrual ' + tag, lines: [{accountId: acct('6300'), debitCents: 1000, creditCents: 0, memo: 'Accrued fee'}, {accountId: acct('2200'), debitCents: 0, creditCents: 1000, memo: 'Accrued liability'}]}}));
  const id = j.journalEntry?.id; const memos = sql(`SELECT GROUP_CONCAT(memo ORDER BY debit_cents DESC SEPARATOR ' || ') FROM journal_lines WHERE journal_entry_id='${id}'`);
  return {ok: memos === 'Accrued fee || Accrued liability', info: memos};
});

// ---------------- 2/8. Interbank transfer as a journal entry ----------------
await t('JE-01', 'Journal Entry › Interbank transfer (server): one entry, debit the receiving bank and credit the paying bank; refused for a non-bank account, the same account twice or unequal lines', async () => {
  const bad1 = await o.call('journals', {method: 'POST', json: {date: '2026-09-07', memo: 'x', interbankTransfer: true, lines: [{accountId: SAVGL, debitCents: 500, creditCents: 0}, {accountId: acct('6300'), debitCents: 0, creditCents: 500}]}});
  const bad2 = await o.call('journals', {method: 'POST', json: {date: '2026-09-07', memo: 'x', interbankTransfer: true, lines: [{accountId: SAVGL, debitCents: 500, creditCents: 0}, {accountId: SAVGL, debitCents: 0, creditCents: 500}]}});
  const j0 = journals(), s0 = bal(SAVGL), b0 = bal(BANKGL);
  const ok = need('transfer journal', await o.call('journals', {method: 'POST', json: {date: '2026-09-08', memo: 'Move to savings ' + tag, interbankTransfer: true, lines: [{accountId: SAVGL, debitCents: 25000, creditCents: 0, memo: 'Transfer from chequing'}, {accountId: BANKGL, debitCents: 0, creditCents: 25000, memo: 'Transfer to savings'}]}}));
  DOCS.transferJournal = ok.journalEntry?.id;
  const memo = sql(`SELECT memo FROM journal_entries WHERE id='${DOCS.transferJournal}'`);
  return {ok: bad1.s === 422 && bad1.b.code === 'interbank_journal_accounts' && bad2.s === 422 && journals() === j0 + 1 && bal(SAVGL) === s0 + 25000 && bal(BANKGL) === b0 - 25000 && tb() === 0 && memo.startsWith('Interbank transfer'),
    info: JSON.stringify({bad1: [bad1.s, bad1.b.code], bad2: [bad2.s, bad2.b.code], memo})};
});
await t('JE-02', 'The statement line in each bank links to that transfer journal entry (Match and Post › Interbank transfer finds it); nothing new is posted', async () => {
  const out = await line(BANK, -25000, '2026-09-08', 'TFR to savings'), into = await line(SAV, 25000, '2026-09-08', 'TFR from chequing');
  const j0 = journals(), s0 = bal(SAVGL), b0 = bal(BANKGL);
  const c = (await o.call(`interbank-v5990/candidates&transactionId=${out}`)).b;
  const cand = (c.candidates || []).find(x => x.candidateKind === 'journal' && x.journalEntryId === DOCS.transferJournal);
  const a1 = await o.call('interbank-v5990/adopt', {method: 'POST', json: {transactionId: out, journalEntryId: DOCS.transferJournal, otherBankAccountId: SAV, operationKey: key('ad'), confirmed: true}});
  const c2 = (await o.call(`interbank-v5990/candidates&transactionId=${into}`)).b;
  const cand2 = (c2.candidates || [])[0];
  const a2 = cand2?.candidateKind === 'transfer' ? await o.call('interbank-v5990/match', {method: 'POST', json: {transactionId: into, transferId: cand2.transferId, operationKey: key('mt'), confirmed: true}}) : await o.call('interbank-v5990/adopt', {method: 'POST', json: {transactionId: into, journalEntryId: DOCS.transferJournal, otherBankAccountId: BANK, operationKey: key('ad'), confirmed: true}});
  return {ok: !!cand && a1.s === 200 && a2.s === 200 && st(out) === `posted|${DOCS.transferJournal}` && st(into) === `posted|${DOCS.transferJournal}` && journals() === j0 && bal(SAVGL) === s0 && bal(BANKGL) === b0 && tb() === 0,
    info: JSON.stringify({candidates: (c.candidates || []).map(x => x.candidateKind), a1: a1.s, second: cand2?.candidateKind, a2: [a2.s, a2.b?.code], out: st(out), into: st(into)})};
});

// ---------------- 3. Reconcile Account finds book entries for bank lines ----------------
await t('RC-01', 'A manual journal entry posted to the bank GL and an unposted bank line of the same amount are found by Reconcile Account; matching them posts nothing and clears both from the reconciling items', async () => {
  const j = need('journal', await o.call('journals', {method: 'POST', json: {date: '2026-09-10', memo: 'Bank fee by journal ' + tag, lines: [{accountId: acct('6300'), debitCents: 1750, creditCents: 0}, {accountId: BANKGL, debitCents: 0, creditCents: 1750}]}}));
  const JE = j.journalEntry?.id; const ln = await line(BANK, -1750, '2026-09-11', 'Service charge');
  const pos = async () => (await o.call(`operations/reconciliation-position&bankAccountId=${BANK}&start=2026-09-01&end=2026-09-30`)).b.position;
  const p0 = await pos(); const sug = (p0.suggestedMatches || []).find(s => s.bankTransactionId === ln && s.journalEntryId === JE);
  const j0 = journals(), book0 = p0.book.closingCents;
  const m = await o.call('operations/reconciliation-match', {method: 'POST', json: {bankAccountId: BANK, bankTransactionIds: [ln], journalEntryIds: [JE]}});
  const p1 = await pos();
  const inUnposted = (p1.reconcilingItems.unposted.items || []).some(x => x.id === ln), inBookOnly = (p1.reconcilingItems.bookOnly.items || []).some(x => x.journalEntryId === JE);
  return {ok: !!sug && sug.daysApart === 1 && m.s === 201 && journals() === j0 && p1.book.closingCents === book0 && !inUnposted && !inBookOnly && !(p1.suggestedMatches || []).some(s => s.bankTransactionId === ln),
    info: JSON.stringify({suggested: p0.suggestedMatches, match: m.s, inUnposted, inBookOnly, unexplained: [p0.unexplainedCents, p1.unexplainedCents]})};
});
await t('RC-02', 'The book balance on Reconcile Account is the live General Ledger balance: a journal entry posted to the bank GL changes it at once', async () => {
  const pos = async () => (await o.call(`operations/reconciliation-position&bankAccountId=${BANK}&start=2026-09-01&end=2026-09-30`)).b.position;
  const b0 = (await pos()).book.closingCents;
  need('journal', await o.call('journals', {method: 'POST', json: {date: '2026-09-12', memo: 'Owner deposit ' + tag, lines: [{accountId: BANKGL, debitCents: 3300, creditCents: 0}, {accountId: acct('3000'), debitCents: 0, creditCents: 3300}]}}));
  const b1 = (await pos()).book.closingCents;
  return {ok: b1 === b0 + 3300 && b1 === bal(BANKGL), info: JSON.stringify({b0, b1, gl: bal(BANKGL)})};
});

// ---------------- 8. Match and Post › Interbank transfer (server path used by the screen) ----------------
await t('MP-01', 'Interbank transfer of two unposted lines (chequing out, savings in): one entry covers both; each bank moves by the amount; trial balance balances', async () => {
  const out = await line(BANK, -40000, '2026-09-15', 'Online transfer out'), into = await line(SAV, 40000, '2026-09-15', 'Online transfer in');
  const j0 = journals(), s0 = bal(SAVGL), b0 = bal(BANKGL);
  const c = (await o.call(`interbank-v5990/candidates&transactionId=${out}`)).b, pair = (c.candidates || []).find(x => x.candidateKind === 'pending_pair' && x.counterpartTransactionId === into);
  const r = await o.call('interbank-v5990/post-pair', {method: 'POST', json: {transactionId: out, counterpartTransactionId: into, decision: {transferBankAccountId: SAV, remarks: 'To savings'}, operationKey: key('pp'), confirmed: true}});
  const je1 = st(out).split('|')[1], je2 = st(into).split('|')[1];
  return {ok: !!pair && r.s === 200 && journals() === j0 + 1 && je1 === je2 && je1 !== '-' && bal(SAVGL) === s0 + 40000 && bal(BANKGL) === b0 - 40000 && tb() === 0, info: JSON.stringify({pair: !!pair, r: [r.s, r.b?.code, r.b?.error], out: st(out), into: st(into)})};
});
await t('MP-02', 'Interbank transfer to the clearing account: the line posts to 1070 Interbank Transfer Clearing with no tax', async () => {
  const ln = await line(BANK, -900, '2026-09-16', 'Transfer to other bank');
  const r = await o.call('bank-transactions/post', {method: 'POST', json: {decisions: [{id: ln, accountId: acct('1070'), taxCode: 'NO_TAX', applyGstHst: false, applyPst: false, remarks: 'Transfer'}]}});
  return {ok: r.s === 200 && bal(acct('1070')) === 900 && st(ln).startsWith('posted|'), info: JSON.stringify({r: r.s, clearing: bal(acct('1070'))})};
});

// ---------------- Browser ----------------
const page = async (fn, w = 1440) => { const {b, p, errs} = await open(CO, {w, h: 900}); try { return await fn(p, errs); } finally { await b.close(); } };
const title = p => p.evaluate(() => [...document.querySelectorAll('.srp-page')].pop()?.querySelector('h1')?.textContent?.trim());
const menuItems = p => p.evaluate(() => { const out = {}; for (const m of ['Banking', 'General ledger']) { try { out[m] = TeghPortal.menuItems ? TeghPortal.menuItems(m) : null; } catch { out[m] = null; } } return out; });

await t('UI-01', 'Menus: General ledger has one "Journal Entry" (no separate Journal Entries or Expense Vouchers); Banking has no Bank Transfers; GIFI Report is under Reports › Tax Reports and not under General ledger', async () => page(async (p, errs) => {
  const got = {};
  // A menu item that no longer exists does nothing when invoked; the page stays where it was.
  const opens = async (m, item) => { await p.evaluate(() => TeghPortal.openDashboard?.()); await p.waitForTimeout(1200); const before = await title(p); await p.evaluate(([m, i]) => TeghPortal.invokeMenuAction(m, i), [m, item]).catch(() => {}); await p.waitForTimeout(2000); const after = await title(p); return after !== before ? after : null; };
  got.journalEntry = await opens('General ledger', 'Journal Entry');
  got.tabs = (await p.locator('[data-r187-je-tabs] button').allTextContents()).map(x => x.toLowerCase());
  got.oldJournalEntries = await opens('General ledger', 'Journal Entries'); got.oldExpense = await opens('General ledger', 'Expense Vouchers');
  got.bankTransfers = await opens('Banking', 'Bank Transfers'); got.glGifi = await opens('General ledger', 'GIFI Report'); got.reportsGifi = await opens('Reports', 'GIFI Report');
  await p.evaluate(() => TeghPortal.openReportsDashboard()); await p.waitForTimeout(2500);
  got.taxGroup = await p.evaluate(() => { const h = [...document.querySelectorAll('h2,h3')].find(x => x.textContent.trim() === 'Tax Reports'); const card = h?.parentElement?.closest('section,article') || h?.parentElement?.parentElement; if (card) card.scrollIntoView(); return (card?.innerText || '').replace(/\s+/g, ' ').slice(0, 400); });
  await p.waitForTimeout(400); await p.screenshot({path: '/srv/gate/ev/shots/r187-reports-tax-1440.png'});
  got.errs = errs;
  return {ok: got.journalEntry === 'Journal Entry' && JSON.stringify(got.tabs) === JSON.stringify(['journal', 'expense voucher', 'interbank transfer', 'import', 'recurring']) && !got.oldJournalEntries && !got.oldExpense && !got.bankTransfers && !got.glGifi && got.reportsGifi === 'GIFI Report' && /GIFI Report/.test(got.taxGroup) && !errs.length, info: JSON.stringify(got)};
}));
await t('UI-02', 'Journal Entry › Interbank transfer (screen): choosing From, To and the amount shows the entry; Post transfer records one entry moving the money', async () => page(async (p, errs) => {
  const s0 = bal(SAVGL), b0 = bal(BANKGL), j0 = journals();
  await p.evaluate(() => TeghPortal.invokeMenuAction('General ledger', 'Journal Entry')); await p.waitForTimeout(2000);
  await p.click('[data-r187-je-tabs] [data-journal-view=transfer]'); await p.waitForSelector('[data-r187-transfer-form]', {timeout: 20000});
  const f = p.locator('[data-r187-transfer-form]');
  await f.locator('input[name=date]').fill('2026-09-18'); await f.locator('input[name=amount]').fill('60.00');
  await f.locator('select[name=from]').selectOption(BANK); await f.locator('select[name=to]').selectOption(SAV); await f.locator('input[name=memo]').fill('Top up savings');
  const preview = await f.locator('[data-r187-transfer-preview]').textContent();
  await p.screenshot({path: '/srv/gate/ev/shots/r187-je-transfer-1440.png'});
  await f.locator('[data-r187-transfer-post]').click(); await p.waitForTimeout(2500);
  return {ok: /R187 Savings/.test(preview) && journals() === j0 + 1 && bal(SAVGL) === s0 + 6000 && bal(BANKGL) === b0 - 6000 && tb() === 0 && !errs.length, info: JSON.stringify({preview, errs})};
}));
await t('UI-03', 'Match and Post › Post as Interbank transfer: the other side waiting in the savings account is found, and linking posts one entry for both lines', async () => {
  const out = await line(BANK, -7700, '2026-09-20', 'UI transfer out'), into = await line(SAV, 7700, '2026-09-20', 'UI transfer in');
  const j0 = journals();
  return page(async (p, errs) => {
    const got = {};
    await p.evaluate(b => TeghPortal.openBankReconciliation(b, '2026-09-01', '2026-09-30', 'post', []), BANK);
    await p.waitForSelector('[data-r28-bank-row]', {timeout: 60000}); await p.waitForTimeout(1200);
    const row = p.locator(`[data-r28-bank-row][data-key="${BANK}:${out}"]`); await row.scrollIntoViewIfNeeded(); await row.locator('[data-r28-bank-select]').check(); await p.waitForTimeout(800);
    await p.check('[data-r28-post-form] [data-r187-postas-transfer]'); await p.waitForTimeout(400);
    await p.selectOption('[data-r28-post-form] select[name=transferTarget]', 'bank:' + SAV); await p.waitForSelector('[data-r187-found]:not([hidden])', {timeout: 20000});
    got.found = (await p.locator('[data-r187-found]').textContent()).replace(/\s+/g, ' ').slice(0, 300);
    await p.screenshot({path: '/srv/gate/ev/shots/r187-mp-transfer-1440.png'});
    await p.click('[data-r28-post-form] [data-r28-post]'); await p.waitForTimeout(3500);
    got.errs = errs; got.out = st(out); got.into = st(into);
    const je = got.out.split('|')[1];
    return {ok: /UI transfer in/.test(got.found) && got.out.startsWith('posted|') && got.into === got.out && journals() === j0 + 1 && je !== '-' && tb() === 0 && !errs.length, info: JSON.stringify(got)};
  });
});
await t('UI-04', 'Reconcile Account lists a book entry that matches a bank line and matches it with one click', async () => {
  const j = need('journal', await o.call('journals', {method: 'POST', json: {date: '2026-09-22', memo: 'Cheque 1044 ' + tag, lines: [{accountId: acct('6300'), debitCents: 8150, creditCents: 0}, {accountId: BANKGL, debitCents: 0, creditCents: 8150}]}}));
  const JE = j.journalEntry?.id, ln = await line(BANK, -8150, '2026-09-24', 'Cheque 1044 cleared');
  return page(async (p, errs) => {
    const got = {};
    await p.evaluate(b => TeghPortal.openBankReconciliationCompletion(b, '2026-09-01', '2026-09-30'), BANK);
    await p.waitForSelector('[data-r187-recon-found]', {timeout: 60000});
    got.text = (await p.locator('[data-r187-recon-found] tbody').textContent()).replace(/\s+/g, ' ').slice(0, 400);
    await p.screenshot({path: '/srv/gate/ev/shots/r187-reconcile-found-1440.png'});
    const rowBtn = p.locator('[data-r187-recon-found] tr', {hasText: 'Cheque 1044 cleared'}).locator('[data-r187-match-one]');
    await rowBtn.click(); await confirm(p); await p.waitForTimeout(3000);
    got.group = sql(`SELECT IFNULL(GROUP_CONCAT(g.status),'-') FROM bank_match_bank_items bi JOIN bank_match_groups g ON g.id=bi.match_group_id WHERE bi.bank_transaction_id='${ln}'`);
    got.book = sql(`SELECT COUNT(*) FROM bank_match_book_items WHERE journal_entry_id='${JE}'`); got.errs = errs;
    return {ok: /Cheque 1044/.test(got.text) && got.group === 'matched' && got.book === '1' && !errs.length, info: JSON.stringify(got)};
  });
});
await t('UI-05', 'Bank Reconciliation Report with no completed reconciliation says to complete one in Reconcile Account, and its button opens Reconcile Account', async () => page(async (p, errs) => {
  await p.evaluate(() => TeghPortal.invokeMenuAction('Banking', 'Bank Reconciliation Report')); await p.waitForSelector('[data-r187-recon-empty]', {timeout: 60000});
  const text = (await p.locator('[data-r187-recon-empty]').textContent()).replace(/\s+/g, ' ');
  await p.screenshot({path: '/srv/gate/ev/shots/r187-recon-report-empty-1440.png'});
  await p.click('[data-r187-open-reconcile]'); await p.waitForTimeout(3000); const after = await title(p);
  return {ok: /Reconcile Account/.test(text) && /Reconcile Bank Account|Reconcile Account/.test(after || '') && !errs.length, info: JSON.stringify({text: text.slice(0, 260), after, errs})};
}));
await t('UI-06', 'Cancelling an edit of a customer invoice or a vendor invoice stays on that document (not the register)', async () => page(async (p, errs) => {
  const got = {};
  await p.evaluate(id => TeghPortal.openDocumentEditor('customer', {editId: id, editType: 'invoice'}), DOCS.invoice); await p.waitForSelector('[data-r151-cancel]', {timeout: 30000}); await p.waitForTimeout(800);
  await p.click('[data-r151-cancel]'); await confirm(p, 1500); await p.waitForTimeout(2500); got.invoice = {title: await title(p), url: await p.evaluate(() => location.hash)};
  await p.evaluate(id => TeghPortal.openDocumentEditor('vendor', {editId: id, editType: 'bill'}), DOCS.bill); await p.waitForSelector('[data-r151-cancel]', {timeout: 30000}); await p.waitForTimeout(800);
  await p.click('[data-r151-cancel]'); await confirm(p, 1500); await p.waitForTimeout(2500); got.bill = {title: await title(p), url: await p.evaluate(() => location.hash)};
  got.errs = errs;
  return {ok: !/Register/i.test(got.invoice.title || '') && !/Register/i.test(got.bill.title || '') && /Vendor Invoice/.test(got.bill.title || '') && !errs.length, info: JSON.stringify(got)};
}));
const download = async (p, click) => { const [d] = await Promise.all([p.waitForEvent('download', {timeout: 60000}), click()]); const path = await d.path(); return {name: d.suggestedFilename(), bytes: fs.statSync(path).size, head: fs.readFileSync(path).subarray(0, 5).toString('latin1')}; };
await t('UI-07', 'Day Book has one Download menu; its PDF and Excel choices download a real PDF and a real Excel workbook', async () => page(async (p, errs) => {
  const got = {};
  await p.evaluate(() => TeghPortal.invokeMenuAction('General ledger', 'Day Book')); await p.waitForSelector('[data-tegh-output-toolbar] summary', {timeout: 60000}); await p.waitForTimeout(1500);
  got.menus = await p.locator('[data-tegh-output-toolbar]').count();
  await p.click('[data-tegh-output-toolbar] summary'); await p.waitForTimeout(400); got.options = await p.locator('[data-tegh-report-pdf]:visible,[data-tegh-report-xlsx]:visible,[data-tegh-report-csv]:visible,[data-tegh-report-print]:visible').allTextContents();
  await p.screenshot({path: '/srv/gate/ev/shots/r187-daybook-download-1440.png'});
  got.pdf = await download(p, () => p.locator('[data-tegh-report-pdf]:visible').first().click());
  await p.click('[data-tegh-output-toolbar] summary'); got.xlsx = await download(p, () => p.locator('[data-tegh-report-xlsx]:visible').first().click());
  got.errs = errs;
  return {ok: got.menus === 1 && got.options.join('|').includes('PDF') && got.options.join('|').includes('Excel') && /\.pdf$/.test(got.pdf.name) && got.pdf.head === '%PDF-' && got.pdf.bytes > 1000 && /\.xlsx$/.test(got.xlsx.name) && got.xlsx.head.startsWith('PK') && !errs.length, info: JSON.stringify(got)};
}));
await t('UI-08', 'Bank Reconciliation Report has one Download menu with the formats (no separate export buttons), and its Excel choice downloads a workbook', async () => page(async (p, errs) => {
  const got = {};
  await p.evaluate(() => TeghPortal.invokeMenuAction('Banking', 'Bank Reconciliation Report')); await p.waitForTimeout(3500);
  got.oldButtons = await p.locator('button', {hasText: /Export (Current Visible|All Report) Columns/}).count();
  got.menus = await p.locator('[data-r187-download]').count();
  await p.click('[data-r187-download] summary'); await p.waitForTimeout(400); got.options = await p.locator('[data-r187-xlsx-visible]:visible,[data-r187-xlsx-all]:visible,[data-export-visible]:visible,[data-export-all]:visible,[data-r187-print]:visible').allTextContents();
  got.xlsx = await download(p, () => p.locator('[data-r187-xlsx-all]:visible').first().click());
  got.errs = errs;
  return {ok: got.oldButtons === 0 && got.menus === 1 && got.options.length >= 4 && /\.xlsx$/.test(got.xlsx.name) && got.xlsx.head.startsWith('PK') && !errs.length, info: JSON.stringify(got)};
}));
await t('UI-09', '"+ Add new vendor…" in the vendor drop-down of a new vendor invoice creates the vendor and selects it', async () => page(async (p, errs) => {
  const got = {}, name = 'R187 Quick Vendor ' + tag;
  await p.evaluate(() => TeghPortal.openDocumentEditor('vendor', {kind: 'invoice'})); await p.waitForTimeout(3000);
  const sel = p.locator('.srp-page:last-of-type select').filter({has: p.locator('option[data-r187-add=vendor],option[value="__create_vendor__"]')}).first();
  got.found = await sel.count(); if (!got.found) return {ok: false, info: 'no vendor drop-down with the add option'};
  got.addOptions = await sel.locator('option[data-r187-add],option[value^="__create_"]').allTextContents();
  await sel.selectOption(await sel.locator('option[data-r187-add],option[value^="__create_"]').first().getAttribute('value')); await p.waitForSelector('[data-r187-qa-form],[data-qe-form]', {timeout: 20000});
  await p.fill('[data-r187-qa-form] input[name=name],[data-qe-form] input[name=name]', name); await p.screenshot({path: '/srv/gate/ev/shots/r187-quick-add-vendor-1440.png'});
  await p.click('[data-r187-qa-save],[data-qe-form] button[type=submit]'); await p.waitForFunction(() => !document.querySelector('[data-r187-qa-form],[data-qe-form]'), null, {timeout: 20000}); await p.waitForTimeout(800);
  got.id = sql(`SELECT id FROM vendors WHERE company_id='${CO}' AND name='${name}'`); got.selected = await sel.inputValue(); got.errs = errs;
  return {ok: !!got.id && got.selected === got.id && got.addOptions.length === 1 && !errs.length, info: JSON.stringify(got)};
}));
await t('UI-10', '"+ Add new customer…" in the customer drop-down of a new customer invoice creates the customer and selects it', async () => page(async (p, errs) => {
  const got = {}, name = 'R187 Quick Customer ' + tag;
  await p.evaluate(() => TeghPortal.openDocumentEditor('customer', {kind: 'invoice'})); await p.waitForTimeout(3000);
  const sel = p.locator('.srp-page:last-of-type select').filter({has: p.locator('option[data-r187-add=customer],option[value="__create_customer__"]')}).first();
  got.found = await sel.count(); if (!got.found) return {ok: false, info: 'no customer drop-down with the add option'};
  got.addOptions = await sel.locator('option[data-r187-add],option[value^="__create_"]').allTextContents();
  await sel.selectOption(await sel.locator('option[data-r187-add],option[value^="__create_"]').first().getAttribute('value')); await p.waitForSelector('[data-r187-qa-form],[data-qe-form]', {timeout: 20000});
  await p.fill('[data-r187-qa-form] input[name=name],[data-qe-form] input[name=name]', name); await p.click('[data-r187-qa-save],[data-qe-form] button[type=submit]');
  await p.waitForFunction(() => !document.querySelector('[data-r187-qa-form],[data-qe-form]'), null, {timeout: 20000}); await p.waitForTimeout(800);
  got.id = sql(`SELECT id FROM customers WHERE company_id='${CO}' AND name='${name}'`); got.selected = await sel.inputValue(); got.errs = errs;
  return {ok: !!got.id && got.selected === got.id && got.addOptions.length === 1 && !errs.length, info: JSON.stringify(got)};
}));
await t('UI-11', '"+ Add new GL account…" in Match and Post\'s account drop-down: the form suggests a free code, creates the account and selects it; Cancel leaves the drop-down as it was', async () => {
  const ln = await line(BANK, -1234, '2026-09-26', 'Quick account test');
  return page(async (p, errs) => {
    const got = {}, name = 'R187 Quick Expense ' + tag;
    await p.evaluate(b => TeghPortal.openBankReconciliation(b, '2026-09-01', '2026-09-30', 'post', []), BANK);
    await p.waitForSelector('[data-r28-bank-row]', {timeout: 60000}); await p.waitForTimeout(1200);
    const row = p.locator(`[data-r28-bank-row][data-key="${BANK}:${ln}"]`); await row.scrollIntoViewIfNeeded(); await row.locator('[data-r28-bank-select]').check(); await p.waitForTimeout(800);
    const sel = p.locator('[data-r28-post-form] select[name=accountId]');
    got.option = await sel.locator('option[data-r187-add=account]').textContent();
    await sel.selectOption('__tegh_add_new__'); await p.waitForSelector('[data-r187-qa-form]', {timeout: 20000});
    await p.click('[data-r187-qa-form] [data-r187-qa-close]'); await p.waitForTimeout(400); got.afterCancel = await sel.inputValue();
    await sel.selectOption('__tegh_add_new__'); await p.waitForSelector('[data-r187-qa-form]', {timeout: 20000});
    got.types = await p.locator('[data-r187-qa-form] select[name=type] option').allTextContents();
    await p.selectOption('[data-r187-qa-form] select[name=type]', 'expense'); got.code = await p.inputValue('[data-r187-qa-form] input[name=code]');
    await p.fill('[data-r187-qa-form] input[name=name]', name); await p.screenshot({path: '/srv/gate/ev/shots/r187-quick-add-account-1440.png'});
    await p.click('[data-r187-qa-save]'); await p.waitForFunction(() => !document.querySelector('[data-r187-qa-form]'), null, {timeout: 20000}); await p.waitForTimeout(800);
    got.row = sql(`SELECT CONCAT(id,'|',code,'|',account_type) FROM accounts WHERE company_id='${CO}' AND name='${name}'`); got.selected = await sel.inputValue(); got.errs = errs;
    const [id, code, type] = (got.row || '||').split('|');
    return {ok: got.option === '+ Add new GL account…' && got.afterCancel === '' && got.types.length >= 2 && code === got.code && type === 'expense' && got.selected === id && !errs.length, info: JSON.stringify(got)};
  });
});
await t('UI-13', 'Journal Entry: each line\'s account drop-down offers "+ Add new GL account…"', async () => page(async (p, errs) => {
  await p.evaluate(() => TeghPortal.openJournalEntry('manual')); await p.waitForSelector('select[data-account]', {timeout: 30000}); await p.waitForTimeout(800);
  const got = {lines: await p.locator('select[data-account]').count(), withAdd: await p.locator('select[data-account]:has(option[data-r187-add=account])').count(), errs};
  return {ok: got.lines >= 2 && got.withAdd === got.lines && !errs.length, info: JSON.stringify(got)};
}));
await t('UI-12', 'A Viewer sees no "+ Add new" options', async () => {
  const {chromium} = await import('/opt/node22/lib/node_modules/playwright/index.mjs');
  sql(`INSERT IGNORE INTO company_members(company_id,user_id,role,status) VALUES('${CO}',(SELECT id FROM users WHERE email='viewer@gate.test'),'viewer','active')`);
  const b = await chromium.launch({executablePath: '/opt/pw-browsers/chromium', args: ['--no-proxy-server', '--host-resolver-rules=MAP gate.test 127.0.0.1']}); const got = {};
  try {
    const p = await (await b.newContext({viewport: {width: 1440, height: 900}, ignoreHTTPSErrors: true})).newPage();
    await p.goto('https://gate.test/app.html'); await p.fill('#sr-login-form input[name=email]', 'viewer@gate.test'); await p.fill('#sr-login-form input[name=password]', 'Gate!Member#2026pw'); await p.click('#sr-login-form button[type=submit]');
    await p.waitForFunction(() => window.TeghPortal && document.querySelector('.sidebar,.topbar'), null, {timeout: 40000});
    await p.evaluate(id => { localStorage.setItem('sr-accountax-company', id); localStorage.setItem('sr-accountax-companies', JSON.stringify([id])); }, CO);
    await p.goto('https://gate.test/app.html'); await p.waitForFunction(() => window.TeghPortal && document.querySelector('.sidebar,.topbar'), null, {timeout: 40000}); await p.waitForTimeout(1200);
    await p.evaluate(() => TeghPortal.invokeMenuAction('General ledger', 'General Ledger Account Report')); await p.waitForTimeout(3000);
    got.selects = await p.locator('select').count(); got.addOptions = await p.locator('option[data-r187-add]').count();
  } finally { await b.close(); }
  return {ok: got.selects > 0 && got.addOptions === 0, info: JSON.stringify(got)};
});
} finally {
  fs.writeFileSync('/srv/gate/t/r187-state.json', JSON.stringify({CO, BANK, SAV, DOCS, tag}));
  save('r187.json');
}

// R200: Group & Intercompany (Canada edition; synthetic group of three companies).
// P = parent (Ontario), W = branch (British Columbia), Q = subsidiary (Quebec). Every expected figure is worked out by
// hand in the comments; none is read back from Tegh first.
//  - P invoices W twice: $25,000 + GST 5% = $26,250 each; P invoices Q $10,000 + GST 5% = $10,500.
//  - P lends W $100,000 (P 1260 / W 2260); interest: P books $500 (Dr 1260 Cr 4510), W books $450 (Dr 6510 Cr 2260).
//  - Mirrors: each invoice creates a draft vendor invoice in the partner; W posts one at first, the second later.
import {S, sql, rec, save, need} from './lib.mjs';
import {chromium} from '/opt/node22/lib/node_modules/playwright/index.mjs';

const A = 'R200';
const t = async (id, title, fn) => { try { const r = await fn(); rec(id, A, title, r.ok ? 'PASS' : 'FAIL', r.info); return r.ok; } catch (e) { rec(id, A, title, 'FAIL', String(e.message || e).slice(0, 600)); return false; } };
const o = new S(); await o.login('owner@gate.test', 'Gate!Owner#2026pw');
const tag = Date.now().toString(36), EMAIL = `r200-${tag}@gate.test`, UID = `user_r200${tag}`.padEnd(37, '0'), PW = 'Gate!Member#2026pw';
sql(`CREATE TEMPORARY TABLE r200u AS SELECT * FROM users WHERE email='otherowner@gate.test'; UPDATE r200u SET id='${UID}', email='${EMAIL}'; INSERT INTO users SELECT * FROM r200u`);
const R = d => Math.round(d * 100);
const acct = (co, code) => sql(`SELECT id FROM accounts WHERE company_id='${co}' AND code='${code}'`);
const tax = (co, code) => sql(`SELECT id FROM tax_codes WHERE company_id='${co}' AND code='${code}' AND status='active'`);
const unbalanced = co => Number(sql(`SELECT COUNT(*) FROM (SELECT je.id FROM journal_entries je JOIN journal_lines jl ON jl.journal_entry_id=je.id WHERE je.company_id='${co}' GROUP BY je.id HAVING SUM(jl.debit_cents)<>SUM(jl.credit_cents)) x`));
const as = co => { o.cid = co; return o; };
const company = async (name, province, bn) => need(name, await o.call('companies', {method: 'POST', company: false, json: {businessType: 'corporation', country: 'Canada', province, taxRegistered: true, taxNumber: bn + 'RT0001', moduleMode: 'accounting', fiscalYearEnd: '12-31', booksStartDate: '2026-01-01', chartTemplate: 'default', currency: 'CAD', name: name + ' ' + tag, accountingBasis: 'accrual'}})).company.id;
const ensure = async (co, code, name, type) => { if (!acct(co, code)) need('acct ' + code, await as(co).call('accounts', {method: 'POST', json: {code, name, type}})); return acct(co, code); };
const cust = async (co, name, province) => need('cust', await as(co).call('customers', {method: 'POST', json: {name, email: '', phone: '', billingAddress: '1 Group Way', country: 'Canada', province}})).customer.id;
const vend = async (co, name, province) => { const b = need('vend', await as(co).call('vendors', {method: 'POST', json: {name, email: '', address: '1 Group Way', defaultTermsDays: 30, currency: 'CAD', country: 'Canada', province}})); return b.vendor?.id || b.id; };
const je = async (co, date, memo, lines) => need('je ' + memo, await as(co).call('journals', {method: 'POST', json: {date, memo, lines}}));
let browser;
const ids = {};
try {
  const P = await company('R200 Parent', 'ON', '751111111'), W = await company('R200 Branch', 'BC', '752222222'), Q = await company('R200 Subsidiary', 'QC', '753333333');
  Object.assign(ids, {P, W, Q});
  sql(`INSERT INTO company_members (company_id,user_id,role,status,created_at) VALUES ('${P}','${UID}','admin','active',NOW()),('${W}','${UID}','editor','active',NOW()),('${Q}','${UID}','admin','active',NOW())`);
  const acc = {P1260: await ensure(P, '1260', 'Loan to R200 Branch (IC)', 'asset'), P4500: await ensure(P, '4500', 'Management fees (IC)', 'income'), P4510: await ensure(P, '4510', 'Interest income (IC)', 'income'),
    W2260: await ensure(W, '2260', 'Loan from R200 Parent (IC)', 'liability'), W6510: await ensure(W, '6510', 'Interest expense (IC)', 'expense'), W6550: await ensure(W, '6550', 'Management fees (IC)', 'expense'), Q6550: await ensure(Q, '6550', 'Management fees (IC)', 'expense')};
  const pty = {P_W: await cust(P, 'R200 Branch Ltd', 'BC'), P_Q: await cust(P, 'R200 Subsidiary Inc.', 'QC'), P_vW: await vend(P, 'R200 Branch Ltd', 'BC'), P_ext: await cust(P, 'R200 Outside Client', 'ON'),
    W_vP: await vend(W, 'R200 Parent Inc.', 'ON'), W_P: await cust(W, 'R200 Parent Inc.', 'ON'), Q_vP: await vend(Q, 'R200 Parent Inc.', 'ON')};
  const u = new S(); await u.login(EMAIL, PW);
  const pairPW = {companyA: P, companyB: W, aCustomerId: pty.P_W, aVendorId: pty.P_vW, bCustomerId: pty.W_P, bVendorId: pty.W_vP, bExpenseAccountId: acc.W6550,
    accounts: [{companyId: P, accountId: acc.P1260, role: 'balance'}, {companyId: W, accountId: acc.W2260, role: 'balance'}, {companyId: P, accountId: acc.P4510, role: 'pl'}, {companyId: W, accountId: acc.W6510, role: 'pl'}]};

  await t('R200-01', 'Linking two companies: a vendor from the wrong company is refused (422); a person who is only an Editor in one of them cannot link them (403); the Owner links P–W and P–Q; linking the same two companies again is refused (409); the list shows each side\'s customer, vendor and accounts', async () => {
    const wrong = await o.call('group/pairs', {method: 'POST', company: false, json: {...pairPW, bVendorId: pty.P_vW}});
    const editor = await u.call('group/pairs', {method: 'POST', company: false, json: pairPW});
    const ok1 = await o.call('group/pairs', {method: 'POST', company: false, json: pairPW});
    const ok2 = await o.call('group/pairs', {method: 'POST', company: false, json: {companyA: P, companyB: Q, aCustomerId: pty.P_Q, bVendorId: pty.Q_vP, bExpenseAccountId: acc.Q6550}});
    const dup = await o.call('group/pairs', {method: 'POST', company: false, json: {companyA: W, companyB: P, aVendorId: pty.W_vP}});
    const list = need('pairs', await o.call('group/pairs', {company: false})).pairs.filter(p => [P, W, Q].includes(p.a.companyId));
    ids.pairPW = ok1.b?.pair?.id; ids.pairPQ = ok2.b?.pair?.id;
    const pw = list.find(p => p.id === ids.pairPW);
    return {ok: wrong.s === 422 && wrong.b.code === 'group_party_invalid' && editor.s === 403 && editor.b.code === 'group_pair_role' && ok1.s === 201 && ok2.s === 201 && dup.s === 409 && list.length === 2 && pw?.a.customer?.name === 'R200 Branch Ltd' && pw?.b.vendor?.name === 'R200 Parent Inc.' && pw?.accounts.length === 4,
      info: `wrong ${wrong.s} ${wrong.b.code}; editor ${editor.s} ${editor.b.code}; P–W ${ok1.s}; P–Q ${ok2.s}; again ${dup.s} ${dup.b.code}; listed ${list.length}, P–W accounts ${pw?.accounts.length}`};
  });

  // Activity: three intercompany invoices, one outside invoice, the loan and interest.
  const tm = (await as(P).call('invoice-templates')).b.templates?.[0]?.id;
  const inv = async (customerId, date, amount, desc) => need('inv', await as(P).call('invoices', {method: 'POST', json: {customerId, issueDate: date, dueDate: '2026-12-31', currency: 'CAD', templateId: tm, issue: true, lines: [{description: desc, quantity: 1, unitPriceCents: R(amount), taxable: true, taxCodeId: tax(P, 'GST'), incomeAccountId: acc.P4500}]}})).invoice.id;
  ids.i1 = await inv(pty.P_W, '2026-07-31', 25000, 'Management fee July'); ids.i2 = await inv(pty.P_W, '2026-08-31', 25000, 'Management fee August');
  ids.i3 = await inv(pty.P_Q, '2026-08-31', 10000, 'IT support August'); ids.iExt = await inv(pty.P_ext, '2026-08-15', 5000, 'Consulting');
  await je(P, '2026-07-01', 'Loan to branch', [{accountId: acc.P1260, debitCents: R(100000), creditCents: 0}, {accountId: acct(P, '1000'), debitCents: 0, creditCents: R(100000)}]);
  await je(W, '2026-07-01', 'Loan from parent', [{accountId: acct(W, '1000'), debitCents: R(100000), creditCents: 0}, {accountId: acc.W2260, debitCents: 0, creditCents: R(100000)}]);
  await je(P, '2026-08-31', 'Interest on loan', [{accountId: acc.P1260, debitCents: R(500), creditCents: 0}, {accountId: acc.P4510, debitCents: 0, creditCents: R(500)}]);
  await je(W, '2026-08-31', 'Interest on loan', [{accountId: acc.W6510, debitCents: R(450), creditCents: 0}, {accountId: acc.W2260, debitCents: 0, creditCents: R(450)}]);

  await t('R200-02', 'Mirror: each intercompany invoice creates a draft vendor invoice in the partner (same number and date, partner\'s GST code, expense account from the link, $26,250 / $10,500); a second mirror of the same invoice is refused (409); an invoice to an outside customer is not intercompany (422); the branch posts one draft', async () => {
    const m = [];
    for (const i of [ids.i1, ids.i2]) m.push(await u.call('group/mirror', {method: 'POST', company: false, json: {companyId: P, invoiceId: i}}));
    m.push(await o.call('group/mirror', {method: 'POST', company: false, json: {companyId: P, invoiceId: ids.i3}}));
    const again = await u.call('group/mirror', {method: 'POST', company: false, json: {companyId: P, invoiceId: ids.i1}});
    const ext = await u.call('group/mirror', {method: 'POST', company: false, json: {companyId: P, invoiceId: ids.iExt}});
    const bills = sql(`SELECT GROUP_CONCAT(CONCAT_WS('|',b.number,b.bill_date,b.status,b.total_cents,v.name,a.code,IFNULL(t.code,'-')) ORDER BY b.bill_date, b.number SEPARATOR ' ; ') FROM bills b JOIN vendors v ON v.id=b.vendor_id JOIN accounts a ON a.id=b.category_account_id LEFT JOIN tax_codes t ON t.id=b.tax_code_id WHERE b.company_id IN ('${W}','${Q}')`);
    const invNo = id => sql(`SELECT number FROM invoices WHERE id='${id}'`);
    ids.b1 = m[0].b?.bill?.billId; ids.b2 = m[1].b?.bill?.billId; ids.b3 = m[2].b?.bill?.billId;
    const post1 = await as(W).call('bills', {method: 'PATCH', json: {action: 'issue', billId: ids.b1}});
    const post3 = await as(Q).call('bills', {method: 'PATCH', json: {action: 'issue', billId: ids.b3}});
    const docs = need('docs', await u.call('group/documents?companyId=' + P, {company: false})).documents.map(d => `${d.number}:${d.mirror?.status || 'none'}`).join(' ');
    const want = `${invNo(ids.i1)}|2026-07-31|draft|2625000|R200 Parent Inc.|6550|GST ; ${invNo(ids.i2)}|2026-08-31|draft|2625000|R200 Parent Inc.|6550|GST ; ${invNo(ids.i3)}|2026-08-31|draft|1050000|R200 Parent Inc.|6550|GST`;
    return {ok: m.every(x => x.s === 201) && again.s === 409 && again.b.code === 'group_mirror_exists' && ext.s === 422 && ext.b.code === 'group_invoice_not_intercompany' && bills === want && post1.s === 200 && post3.s === 200 && /:open|:paid/.test(docs) && /:draft/.test(docs) && [P, W, Q].every(c => unbalanced(c) === 0),
      info: `mirrors ${m.map(x => x.s)}; again ${again.s}; outside ${ext.s} ${ext.b.code}; bills ${bills}; post ${post1.s}/${post3.s}; docs ${docs}`};
  });

  await t('R200-03', 'Reconciliation as at 30 Sep 2026: P says W owes $153,000.00 (2 × $26,250 + loan $100,500); W says it owes $126,700.00 ($26,250 posted bill + loan $100,450); difference $26,300.00 with one draft not posted. After W posts the draft the difference is $50.00 (the interest booked $500 vs $450). P–Q agrees.', async () => {
    const r1 = need('recon', await o.call(`group/reconciliation?asOf=2026-09-30&companies=${P},${W},${Q}`, {company: false}));
    const pw1 = r1.pairs.find(p => p.pairId === ids.pairPW), pq1 = r1.pairs.find(p => p.pairId === ids.pairPQ);
    const post2 = await as(W).call('bills', {method: 'PATCH', json: {action: 'issue', billId: ids.b2}});
    const r2 = need('recon', await o.call(`group/reconciliation?asOf=2026-09-30&companies=${P},${W},${Q}`, {company: false}));
    const pw2 = r2.pairs.find(p => p.pairId === ids.pairPW);
    const ok = pw1.a.netCents === R(153000) && pw1.b.netCents === -R(126700) && pw1.differenceCents === R(26300) && pw1.draftMirrors === 1 && pq1.agrees === true && post2.s === 200 && pw2.differenceCents === R(50) && pw2.draftMirrors === 0;
    return {ok, info: `before: P ${pw1.a.netCents} W ${pw1.b.netCents} diff ${pw1.differenceCents} drafts ${pw1.draftMirrors}; P–Q agrees ${pq1.agrees}; after posting: diff ${pw2.differenceCents} drafts ${pw2.draftMirrors}`};
  });

  await t('R200-04', 'Consolidation worksheet P + W + Q (1 Jan–30 Sep 2026): intercompany income $60,500 (fees $60,000 + interest $500) and expense $60,450 are eliminated; the $50 left over is on its own line and group net income is unchanged; receivables, payables and the loan eliminate with the $50 shown; the outside invoice stays; one company is refused (422)', async () => {
    const one = await o.call(`group/consolidation?companies=${P}&start=2026-01-01&end=2026-09-30`, {company: false});
    const r = need('consol', await o.call(`group/consolidation?companies=${P},${W},${Q}&start=2026-01-01&end=2026-09-30`, {company: false}));
    const row = (sheet, code) => r[sheet].find(x => x.code === code);
    const pl = c => row('profitLoss', c), bs = c => row('balanceSheet', c);
    const diffPl = r.profitLoss.find(x => x.type === 'difference'), diffBs = r.balanceSheet.find(x => x.type === 'difference');
    // Income 4500: $60,000 IC (eliminated) + $5,000 outside = $5,000 consolidated. 4510 → 0. 6550 → 0. 6510 → 0.
    const checks = {fees: pl('4500')?.consolidatedCents === R(5000), interestIn: pl('4510')?.consolidatedCents === 0, feesExp: pl('6550')?.consolidatedCents === 0, interestEx: pl('6510')?.consolidatedCents === 0,
      plDiff: diffPl?.consolidatedCents === R(50) && r.totals.plDifferenceCents === R(50), net: r.totals.netIncomeCents === r.totals.netIncomeBeforeCents,
      // AR: P has $52,500 + $10,500 intercompany + $5,250 outside; consolidated AR = $5,250. AP: W $52,500 + Q $10,500 → 0.
      ar: bs('1200')?.consolidatedCents === R(5250), ap: bs('2050')?.consolidatedCents === 0, loanP: bs('1260')?.consolidatedCents === 0, loanW: bs('2260')?.consolidatedCents === 0,
      bsDiff: diffBs?.consolidatedCents === R(50) && r.totals.bsDifferenceCents === R(50)};
    return {ok: one.s === 422 && Object.values(checks).every(Boolean), info: `one company ${one.s} ${one.b.code}; ${JSON.stringify(checks)}; net ${r.totals.netIncomeCents} before ${r.totals.netIncomeBeforeCents}`};
  });

  await t('R200-05', 'Group close: with W included, where the person is only an Editor, nothing is closed (409, every reason listed); P and Q close together through 30 Sep 2026; a September entry in P is then refused; W is still open', async () => {
    const blocked = await u.call('group/close', {method: 'POST', company: false, json: {companyIds: [P, W, Q], closedThroughDate: '2026-09-30'}});
    const lockedAfterBlock = sql(`SELECT COUNT(*) FROM period_locks WHERE company_id IN ('${P}','${Q}') AND locked=1`);
    const ok = await u.call('group/close', {method: 'POST', company: false, json: {companyIds: [P, Q], closedThroughDate: '2026-09-30'}});
    const late = await as(P).call('journals', {method: 'POST', json: {date: '2026-09-15', memo: 'Late', lines: [{accountId: acc.P1260, debitCents: 100, creditCents: 0}, {accountId: acc.P4510, debitCents: 0, creditCents: 100}]}});
    const wOpen = await as(W).call('journals', {method: 'POST', json: {date: '2026-09-15', memo: 'Branch still open', lines: [{accountId: acct(W, '6400'), debitCents: 100, creditCents: 0}, {accountId: acct(W, '1000'), debitCents: 0, creditCents: 100}]}});
    return {ok: blocked.s === 409 && blocked.b.code === 'group_close_blocked' && /R200 Branch/.test(blocked.b.error) && lockedAfterBlock === '0' && ok.s === 200 && ok.b.companies.length === 2 && late.s === 409 && wOpen.s < 300,
      info: `blocked ${blocked.s} "${String(blocked.b.error).slice(0, 140)}"; locks after refusal ${lockedAfterBlock}; close ${ok.s} ${ok.b.companies?.length}; late P entry ${late.s} ${late.b.code || ''}; W entry ${wOpen.s}`};
  });

  // ---------------- Browser ----------------
  browser = await chromium.launch({executablePath: '/opt/pw-browsers/chromium', args: ['--no-proxy-server', '--host-resolver-rules=MAP gate.test 127.0.0.1']});
  const session = async (w = 1440, h = 900) => {
    const mobile = w < 800;
    const ctx = await browser.newContext({viewport: {width: w, height: h}, isMobile: mobile, hasTouch: mobile, ignoreHTTPSErrors: true});
    await ctx.addInitScript(id => { try { if (!sessionStorage.getItem('r200')) { localStorage.setItem('sr-accountax-company', id); localStorage.setItem('sr-accountax-companies', JSON.stringify([id])); sessionStorage.setItem('r200', '1'); } } catch {} }, P);
    const p = await ctx.newPage(); const errs = [];
    p.on('pageerror', e => errs.push(e.message.slice(0, 160)));
    p.on('response', async r => { if (r.status() >= 400 && /\/api\//.test(r.url()) && !/auth%2Fme/.test(r.url())) errs.push('api ' + r.status() + ' ' + r.url().replace(/^.*route=/, '').slice(0, 60)); });
    await p.route(/clarity\.ms|challenges\.cloudflare|googleapis|gstatic|bing\.com/, r => r.abort());
    await p.goto('https://gate.test/app.html'); await p.waitForSelector('#sr-login-form');
    await p.fill('#sr-login-form input[name=email]', EMAIL); await p.fill('#sr-login-form input[name=password]', PW); await p.click('#sr-login-form button[type=submit]');
    await p.waitForFunction(() => window.TeghPortal?.openGroupIntercompany && document.querySelector('.sidebar,.topbar'), null, {timeout: 60000}); await p.waitForTimeout(1500);
    return {ctx, p, errs};
  };

  await t('UI-01', 'Settings › Group & Intercompany at 1440 px: the two links, P\'s intercompany invoices with their partner status, the reconciliation (P–W differs by $50.00, P–Q agrees) and the consolidation worksheet (difference line, net income) on screen; no page errors', async () => {
    const {ctx, p, errs} = await session();
    const inMenu = await p.evaluate(() => [...document.querySelectorAll('button,a')].some(b => b.textContent.trim() === 'Group & Intercompany') || null);
    await p.evaluate(() => TeghPortal.openGroupIntercompany()); await p.waitForSelector('[data-r200-card="close"]', {timeout: 20000}); await p.waitForTimeout(800);
    const pairs = await p.$$eval('[data-r200-pair]', l => l.length);
    const docs = await p.$$eval('[data-r200-card="documents"] tbody tr', l => l.map(x => x.textContent.replace(/\s+/g, ' ').trim()));
    await p.locator('[data-r200-recon] input[name=asOf]').fill('2026-09-30'); await p.locator('[data-r200-recon] button[type=submit]').click(); await p.waitForSelector('[data-r200-recon-out] table', {timeout: 20000});
    const recon = await p.locator('[data-r200-recon-out]').innerText();
    const consol = p.locator('[data-r200-consol]');
    for (const box of await consol.locator('input[name=company]').all()) { const v = await box.getAttribute('value'); if ([P, W, Q].includes(v)) await box.check(); else await box.uncheck(); }
    await consol.locator('input[name=from]').fill('2026-01-01'); await consol.locator('input[name=to]').fill('2026-09-30'); await consol.locator('button[type=submit]').click();
    await p.waitForSelector('[data-r200-consol-out] table', {timeout: 20000});
    const sheet = await p.locator('[data-r200-consol-out]').innerText();
    await p.screenshot({path: '/srv/gate/ev/shots/r200-group-1440.png', fullPage: true});
    const over = await p.evaluate(() => document.documentElement.scrollWidth > innerWidth);
    await ctx.close();
    const ok = pairs === 2 && docs.length === 3 && docs.some(d => /Posted/.test(d)) && /Difference \$50\.00/.test(recon) && /Agrees/.test(recon) && /Intercompany difference to investigate/.test(sheet) && /Group net income/.test(sheet) && !over && !errs.length;
    return {ok, info: JSON.stringify({inMenu, pairs, docs: docs.map(d => d.slice(0, 80)), recon: recon.slice(0, 200), sheet: sheet.slice(0, 160), over, errs})};
  });

  await t('UI-02', 'The page at 390 px and in the dark theme: no sideways scroll of the page (the worksheet scrolls inside its own box); text readable on the cards', async () => {
    const {ctx, p, errs} = await session(390, 844);
    await p.evaluate(() => TeghPortal.openGroupIntercompany()); await p.waitForSelector('[data-r200-card="close"]', {timeout: 20000}); await p.waitForTimeout(800);
    const over = await p.evaluate(() => document.documentElement.scrollWidth > innerWidth);
    await p.screenshot({path: '/srv/gate/ev/shots/r200-group-390.png'});
    await p.evaluate(() => TeghPortal.openTeghPreferences()); await p.waitForSelector('[data-tegh-preferences]'); await p.locator('[data-tegh-preferences] input[name=theme][value=dark]').check({force: true}); await p.locator('[data-tegh-preferences] button[type=submit]').first().click(); await p.waitForTimeout(1200);
    await p.evaluate(() => TeghPortal.openGroupIntercompany()); await p.waitForSelector('[data-r200-card="close"]', {timeout: 20000}); await p.waitForTimeout(800);
    const light = await p.evaluate(() => [...document.querySelectorAll('[data-r200-card], [data-r200-card] *')].filter(e => { const b = getComputedStyle(e).backgroundColor; const m = b.match(/\d+/g); return m && m.length >= 3 && (m.length < 4 || Number(m[3]) !== 0) && Number(m[0]) > 225 && Number(m[1]) > 225 && Number(m[2]) > 225; }).map(e => e.className || e.tagName).slice(0, 5));
    await p.screenshot({path: '/srv/gate/ev/shots/r200-group-dark-390.png'});
    await p.evaluate(() => TeghPortal.openTeghPreferences()); await p.waitForSelector('[data-tegh-preferences]'); await p.locator('[data-tegh-preferences] input[name=theme][value=light]').check({force: true}); await p.locator('[data-tegh-preferences] button[type=submit]').first().click(); await p.waitForTimeout(800);
    await ctx.close();
    return {ok: !over && !light.length && !errs.length, info: JSON.stringify({over, light, errs})};
  });
} finally {
  if (browser) await browser.close().catch(() => {});
  try { sql(`UPDATE users SET active=0 WHERE id='${UID}'`); } catch {}
  save('r200.json');
}

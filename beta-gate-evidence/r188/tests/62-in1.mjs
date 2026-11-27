// IN-1: India edition foundation. The edition is chosen at sign-in; the switch decides who may choose India; India and
// Canada companies never mix; an India company has INR books, an April-March year, an Indian chart and no Canadian tax,
// payroll or report set-up; and India screens carry no Canadian wording. Synthetic data only. Expected values (GSTIN
// check characters, chart contents) are worked out here, never read from Tegh first.
import {S, sql, rec, save, need} from './lib.mjs';
import {chromium} from '/opt/node22/lib/node_modules/playwright/index.mjs';
import fs from 'fs';

const A = 'IN1';
const t = async (id, title, fn) => { try { const r = await fn(); rec(id, A, title, r.ok ? 'PASS' : (r.info0 ? 'INFO' : 'FAIL'), r.info); return r.ok; } catch (e) { rec(id, A, title, 'FAIL', String(e.message || e).slice(0, 400)); return false; } };
const PW = 'Gate!Owner#2026pw', MPW = 'Gate!Member#2026pw';
const o = new S(); await o.login('owner@gate.test', PW);
const release = async () => (await o.call('site-admin', {company: false})).b.groups.release;
const setMode = async m => { const cur = await release(); return o.call('site-admin/save', {method: 'POST', company: false, json: {groups: {release: {value: {...cur.value, indiaEdition: m}, revision: cur.revision}}, reason: 'IN-1 gate ' + m}}); };
const startMode = (await release()).value.indiaEdition || 'off';
const tag = Date.now().toString(36);

// GSTIN check character, computed independently of Tegh (base-36, factors 1 and 2 alternately).
const CH = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
const gstin = base14 => { let sum = 0; for (let i = 0; i < 14; i++) { const p = CH.indexOf(base14[i]) * (i % 2 === 0 ? 1 : 2); sum += Math.floor(p / 36) + p % 36; } return base14 + CH[(36 - sum % 36) % 36]; };
const PAN = 'AAACT' + String(1000 + (Date.now() % 9000)).slice(0, 4) + 'F';
const GSTIN_MH = gstin('27' + PAN + '1Z');
const BAD_CHECK = GSTIN_MH.slice(0, 14) + (GSTIN_MH[14] === 'A' ? 'B' : 'A');
const CANADIAN = /GST\/HST|\bHST\b|\bPST\b|\bQST\b|\bCRA\b|GIFI|T2125|\bCPP\b|\bQPP\b|\bT4\b|\bROE\b|Canada|Canadian|[Pp]rovince|\bCAD\b|\$\s?\d/;

let IN = null, INCO = '', CACO = '';
try {
await t('IN-01', 'Switch Off: the sign-in screen offers no edition, and choosing India at sign-in is refused; Canada sign-in is unchanged', async () => {
  need('off', await setMode('off'));
  const pub = (await new S().call('site/public', {company: false})).b;
  const x = new S(); const r = await x.call('auth/login', {method: 'POST', company: false, json: {email: 'owner@gate.test', password: PW, edition: 'IN'}});
  const ca = new S(); const b = await ca.login('owner@gate.test', PW);
  return {ok: pub.indiaEdition === 'off' && r.s === 403 && r.b.code === 'india_edition_unavailable' && (b.edition || 'CA') === 'CA', info: JSON.stringify({pub: pub.indiaEdition, india: [r.s, r.b.code], canada: b.edition})};
});
await t('IN-02', 'Switch "Platform owner only": the platform owner signs in to India; another person choosing India is refused', async () => {
  need('owner', await setMode('owner'));
  IN = new S(); const b = await IN.login('owner@gate.test', PW, {edition: 'IN'});
  const ed = new S(); let refused = null; try { await ed.login('editor@gate.test', MPW, {edition: 'IN'}); refused = 'allowed'; } catch (e) { refused = /403/.test(e.message) && /india_edition_unavailable/.test(e.message) ? 'refused' : e.message.slice(0, 120); }
  const pub = (await new S().call('site/public', {company: false})).b;
  return {ok: b.edition === 'IN' && (b.companies || []).every(c => c.edition === 'IN') && refused === 'refused' && pub.indiaEdition === 'owner' && (b.indiaReference?.states || []).length === 36,
    info: JSON.stringify({edition: b.edition, companies: (b.companies || []).length, states: b.indiaReference?.states?.length, editor: refused})};
});
await t('IN-03', 'India company: wrong PAN, wrong GSTIN check character and a GSTIN from another state are refused; a valid one creates INR books, April-March year, Asia/Kolkata', async () => {
  const base = {name: 'IN1 Traders ' + tag, legalName: 'IN1 Traders Private Limited', constitution: 'private_limited', state: 'MH', pin: '400001', city: 'Mumbai', fiscalYearEnd: '03-31', booksStartDate: '2026-04-01'};
  const badPan = await IN.call('companies', {method: 'POST', company: false, json: {...base, pan: 'ABC123'}});
  const badCheck = await IN.call('companies', {method: 'POST', company: false, json: {...base, pan: PAN, gstin: BAD_CHECK}});
  const otherState = await IN.call('companies', {method: 'POST', company: false, json: {...base, state: 'KA', pan: PAN, gstin: GSTIN_MH}});
  const ok = need('india company', await IN.call('companies', {method: 'POST', company: false, json: {...base, pan: PAN, gstin: GSTIN_MH}}), [201]);
  INCO = ok.company.id; IN.cid = INCO;
  const row = sql(`SELECT CONCAT_WS('|',edition,country,currency,province,fiscal_year_end,fiscal_year_end_date,books_start_date,timezone,module_mode,tax_reporting_profile) FROM companies WHERE id='${INCO}'`);
  const prof = sql(`SELECT CONCAT_WS('|',constitution,pan,gstin,gst_state_code,pin) FROM company_india_profile WHERE company_id='${INCO}'`);
  return {ok: badPan.s === 422 && badPan.b.code === 'india_pan_invalid' && badCheck.s === 422 && badCheck.b.code === 'india_gstin_invalid' && otherState.s === 422 && otherState.b.code === 'india_gstin_state'
    && row === 'IN|India|INR|MH|03-31|2027-03-31|2026-04-01|Asia/Kolkata|accounting|none' && prof === `private_limited|${PAN}|${GSTIN_MH}|27|400001`,
    info: JSON.stringify({badPan: badPan.b.code, badCheck: badCheck.b.code, otherState: otherState.b.code, row, prof, gstin: GSTIN_MH})};
});
await t('IN-04', 'India company set-up has nothing Canadian: no GST/HST, PST, QST or Canadian payroll accounts, no GIFI codes, no tax codes, no Canadian payroll rates; bank account is an INR current account', async () => {
  const codes = sql(`SELECT GROUP_CONCAT(code ORDER BY code) FROM accounts WHERE company_id='${INCO}'`).split(',');
  const names = sql(`SELECT GROUP_CONCAT(name SEPARATOR '|') FROM accounts WHERE company_id='${INCO}'`);
  const want = ['1000', '1010', '1070', '1200', '1300', '1310', '1400', '1500', '1590', '1600', '2050', '2200', '2300', '2500', '2600', '2700', '3000', '3100', '3200', '4000', '4100', '4500', '4600', '5000', '5100', '6000', '6100', '6200', '6300', '6400', '6500', '6600', '6700', '6800', '6810', '6850', '6900', '9999'];
  const counts = {gifi: sql(`SELECT COUNT(*) FROM accounts WHERE company_id='${INCO}' AND gifi_code IS NOT NULL`), taxCodes: sql(`SELECT COUNT(*) FROM tax_codes WHERE company_id='${INCO}'`),
    levy: sql(`SELECT COUNT(*) FROM employer_levy_profiles WHERE company_id='${INCO}'`), statutory: sql(`SELECT COUNT(*) FROM statutory_rates WHERE company_id='${INCO}'`)};
  const bank = sql(`SELECT CONCAT(name,'|',currency,'|',account_type) FROM bank_accounts WHERE company_id='${INCO}'`);
  return {ok: JSON.stringify(codes) === JSON.stringify(want) && !CANADIAN.test(names) && Object.values(counts).every(v => v === '0') && bank === 'Current Account|INR|bank', info: JSON.stringify({codes: codes.join(','), counts, bank})};
});
await t('IN-05', 'Editions never mix: India sign-in cannot open a Canadian company; Canada sign-in cannot open the India company and does not list it', async () => {
  CACO = sql(`SELECT c.id FROM companies c JOIN company_members cm ON cm.company_id=c.id JOIN users u ON u.id=cm.user_id WHERE u.email='owner@gate.test' AND (c.edition IS NULL OR c.edition<>'IN') AND c.active=1 LIMIT 1`);
  const x = Object.assign(new S(), {c: IN.c, csrf: IN.csrf, cid: CACO}); const a = await x.call('workspace');
  const ca = new S(); const cb = await ca.login('owner@gate.test', PW); ca.cid = INCO; const b = await ca.call('workspace');
  const listed = (cb.companies || []).some(c => c.id === INCO);
  const inList = (await IN.call('companies', {company: false})).b.companies || [];
  return {ok: a.s === 403 && a.b.code === 'edition_mismatch' && b.s === 403 && b.b.code === 'edition_mismatch' && !listed && inList.every(c => c.edition === 'IN') && inList.some(c => c.id === INCO),
    info: JSON.stringify({indiaOpensCanada: [a.s, a.b.code], canadaOpensIndia: [b.s, b.b.code], canadaListsIndia: listed, indiaList: inList.length})};
});
await t('IN-06', 'The accounting core works in the India company: a ₹2,500 journal posts at once, a ₹25,000 journal waits for approval above the 10,000.00 control limit (the same rule as Canada), and the trial balance balances', async () => {
  const acct = c => sql(`SELECT id FROM accounts WHERE company_id='${INCO}' AND code='${c}'`);
  const small = await IN.call('journals', {method: 'POST', json: {date: '2026-04-10', memo: 'Office rent April', lines: [{accountId: acct('6100'), debitCents: 250000, creditCents: 0}, {accountId: acct('1000'), debitCents: 0, creditCents: 250000}]}});
  const large = await IN.call('journals', {method: 'POST', json: {date: '2026-04-11', memo: 'Equipment', lines: [{accountId: acct('1500'), debitCents: 2500000, creditCents: 0}, {accountId: acct('1000'), debitCents: 0, creditCents: 2500000}]}});
  const tb = sql(`SELECT IFNULL(SUM(jl.debit_cents-jl.credit_cents),0) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id WHERE je.company_id='${INCO}' AND je.status='posted'`);
  return {ok: small.s === 201 && small.b.journalEntry?.status === 'posted' && large.s === 202 && large.b.approvalRequired === true && tb === '0', info: JSON.stringify({small: [small.s, small.b.journalEntry?.status], large: [large.s, large.b.journalEntry?.status], tb})};
});

// ---------------- Browser ----------------
const browser = await chromium.launch({executablePath: '/opt/pw-browsers/chromium', args: ['--no-proxy-server', '--host-resolver-rules=MAP gate.test 127.0.0.1']});
const indiaPage = async (w = 1440) => {
  const ctx = await browser.newContext({viewport: {width: w, height: 900}, ignoreHTTPSErrors: true}); const p = await ctx.newPage(); const errs = [];
  p.on('pageerror', e => errs.push(e.message.slice(0, 200))); p.on('dialog', d => d.accept());
  await p.goto('https://gate.test/app.html?edition=in'); await p.waitForSelector('#sr-login-form [name=edition]', {timeout: 30000});
  await p.selectOption('#sr-login-form [name=edition]', 'IN');
  await p.fill('#sr-login-form input[name=email]', 'owner@gate.test'); await p.fill('#sr-login-form input[name=password]', PW); await p.click('#sr-login-form button[type=submit]');
  await p.waitForFunction(() => window.TeghPortal && document.querySelector('.sidebar,.topbar'), null, {timeout: 40000});
  await p.evaluate(id => { localStorage.setItem('sr-accountax-company', id); localStorage.setItem('sr-accountax-companies', JSON.stringify([id])); }, INCO);
  await p.goto('https://gate.test/app.html'); await p.waitForFunction(() => window.TeghPortal && document.querySelector('.sidebar,.topbar'), null, {timeout: 40000}); await p.waitForTimeout(1500);
  return {ctx, p, errs};
};
await t('UI-01', 'Sign-in screen: with the switch on "Platform owner only" the Edition choice appears only on the ?edition=in link; India sign-in opens the India company', async () => {
  const ctx = await browser.newContext({ignoreHTTPSErrors: true}); const p = await ctx.newPage();
  await p.goto('https://gate.test/app.html'); await p.waitForSelector('#sr-login-form', {timeout: 30000}); await p.waitForTimeout(2000);
  const plain = await p.locator('#sr-login-form [name=edition]').count(); await ctx.close();
  const {ctx: c2, p: q, errs} = await indiaPage();
  try {
    const got = {plain, company: await q.evaluate(() => document.querySelector('.topbar, header')?.textContent?.replace(/\s+/g, ' ').slice(0, 200))};
    await q.screenshot({path: '/srv/gate/ev/shots/in1-india-home-1440.png'});
    return {ok: plain === 0 && /IN1 Traders/.test(got.company || '') && !errs.length, info: JSON.stringify({...got, errs})};
  } finally { await c2.close(); }
});
await t('UI-02', 'India menus have no Payroll, GIFI, Tax Code Report, GST/HST/PST Summary or CRA items; Bank Transfers and the guided mode are not offered', async () => {
  const {ctx, p, errs} = await indiaPage();
  try {
    const nav = await p.evaluate(() => [...document.querySelectorAll('.sidebar button, .sidebar a, nav button, nav a')].filter(x => x.getClientRects().length).map(x => x.textContent.trim()).filter(Boolean));
    await p.evaluate(() => TeghPortal.openReportsDashboard()); await p.waitForTimeout(2500);
    const reports = await p.evaluate(() => document.querySelector('.srp-page:last-of-type')?.innerText || '');
    const bad = [...nav, reports].join('\n').match(/Payroll|GIFI|Tax Code Report|GST\/HST|CRA|Bank Transfers/g) || [];
    return {ok: !bad.length && !errs.length, info: JSON.stringify({bad: [...new Set(bad)], navCount: nav.length, errs})};
  } finally { await ctx.close(); }
});
await t('UI-03', 'Money and dates in the India edition: ₹ with Indian digit grouping and DD-MM-YYYY', async () => {
  const {ctx, p, errs} = await indiaPage();
  try {
    await p.evaluate(() => TeghPortal.invokeMenuAction('General ledger', 'Day Book')); await p.waitForTimeout(3500);
    const text = await p.evaluate(() => document.querySelector('.srp-page:last-of-type')?.innerText || '');
    await p.screenshot({path: '/srv/gate/ev/shots/in1-daybook-1440.png'});
    return {ok: /₹\s?2,500\.00/.test(text) && /10-04-2026/.test(text) && !/\$\s?2,500/.test(text) && !errs.length, info: JSON.stringify({sample: (text.match(/.{0,40}2,500.{0,10}/) || [''])[0], date: /10-04-2026/.test(text), errs})};
  } finally { await ctx.close(); }
});
// The "no Canadian text" sweep: each India screen is opened and searched for Canadian terms, $ amounts and CAD.
const SCREENS = [['Dashboard', p => p.evaluate(() => TeghPortal.openDashboard())], ['Chart of Accounts', p => p.evaluate(() => TeghPortal.invokeMenuAction('My account', 'Chart of Accounts & Opening Balances'))],
  ['Customers', p => p.evaluate(() => TeghPortal.invokeMenuAction('Receivables', 'Create Customer'))], ['New customer invoice', p => p.evaluate(() => TeghPortal.openDocumentEditor('customer', {kind: 'invoice'}))],
  ['New vendor invoice', p => p.evaluate(() => TeghPortal.openDocumentEditor('vendor', {kind: 'invoice'}))], ['Match and Post', p => p.evaluate(() => TeghPortal.invokeMenuAction('Banking', 'Match And Post Transactions'))],
  ['Journal Entry', p => p.evaluate(() => TeghPortal.openJournalEntry('manual'))], ['Expense voucher', p => p.evaluate(() => TeghPortal.openJournalEntry('expense'))],
  ['Profit and Loss', p => p.evaluate(() => TeghPortal.openReport('Profit and Loss'))], ['Balance Sheet', p => p.evaluate(() => TeghPortal.openReport('Balance Sheet'))],
  ['Trial Balance', p => p.evaluate(() => TeghPortal.openReport('Trial Balance'))], ['Day Book', p => p.evaluate(() => TeghPortal.invokeMenuAction('General ledger', 'Day Book'))],
  ['Company Details', p => p.evaluate(() => TeghPortal.invokeMenuAction('My account', 'Company Details'))], ['Add company', p => p.evaluate(() => TeghPortal.invokeMenuAction('My account', 'Add Company or Client File'))]];
await t('UI-04', 'No Canadian wording, $ amounts or CAD on the India screens (Dashboard, chart, customers, invoices, Match and Post, journals, P&L, Balance Sheet, Trial Balance, Day Book, company details, add company)', async () => {
  const {ctx, p, errs} = await indiaPage(); const found = {};
  try {
    for (const [name, go] of SCREENS) {
      try { await go(p); await p.waitForTimeout(3000); } catch (e) { found[name] = 'ERR ' + e.message.slice(0, 80); continue; }
      // A country list legitimately includes Canada among its 249 countries: it is left out of the scan, but the country it has selected is checked.
      const text = await p.evaluate(() => { const pg = [...document.querySelectorAll('.srp-page')].pop(); if (!pg) return ''; const countries = [...pg.querySelectorAll('select[name=country]')]; countries.forEach(x => { x.dataset.in1Display = x.style.display; x.style.display = 'none'; });
        const out = (pg.innerText || '') + '\n' + [...pg.querySelectorAll('select option')].filter(o => o.closest('select').name !== 'country').map(o => o.textContent).join('\n') + '\n' + countries.map(x => x.selectedOptions[0]?.textContent || '').join('\n');
        countries.forEach(x => { x.style.display = x.dataset.in1Display; }); return out; });
      const hits = [...new Set((text.match(new RegExp(CANADIAN.source, 'g')) || []))];
      if (hits.length) { found[name] = hits.slice(0, 8); await p.screenshot({path: `/srv/gate/ev/shots/in1-canadian-${name.replace(/\W+/g, '-').toLowerCase()}.png`}); }
    }
  } finally { await ctx.close(); }
  return {ok: !Object.keys(found).length && !errs.length, info: JSON.stringify({found, errs})};
});
await browser.close();
} finally {
  await setMode(startMode).catch(() => {});
  fs.writeFileSync('/srv/gate/t/in1-state.json', JSON.stringify({INCO, CACO, tag}));
  save('in1.json');
}

// R200 removed: Group & Intercompany is no longer part of Tegh (the user chose to keep Tegh simple).
// Checks that nothing of it is left: no API route, no menu item, no sections on Company Details, the Invoice Register
// or Month-End Close, even for a person with two companies; those pages still open without errors.
import {S, sql, rec, save, need} from './lib.mjs';
import {chromium} from '/opt/node22/lib/node_modules/playwright/index.mjs';

const A = 'R200';
const t = async (id, title, fn) => { try { const r = await fn(); rec(id, A, title, r.ok ? 'PASS' : 'FAIL', r.info); return r.ok; } catch (e) { rec(id, A, title, 'FAIL', String(e.message || e).slice(0, 600)); return false; } };
const o = new S(); await o.login('owner@gate.test', 'Gate!Owner#2026pw');
const tag = Date.now().toString(36), EMAIL = `r200-${tag}@gate.test`, UID = `user_r200${tag}`.padEnd(37, '0'), PW = 'Gate!Member#2026pw';
sql(`CREATE TEMPORARY TABLE r200u AS SELECT * FROM users WHERE email='otherowner@gate.test'; UPDATE r200u SET id='${UID}', email='${EMAIL}'; INSERT INTO users SELECT * FROM r200u`);
const company = async (name, province, bn) => need(name, await o.call('companies', {method: 'POST', company: false, json: {businessType: 'corporation', country: 'Canada', province, taxRegistered: true, taxNumber: bn + 'RT0001', moduleMode: 'accounting', fiscalYearEnd: '12-31', booksStartDate: '2026-01-01', chartTemplate: 'default', currency: 'CAD', name: name + ' ' + tag, accountingBasis: 'accrual'}})).company.id;
let browser;
try {
  const P = await company('R200 Parent', 'ON', '751111111'), W = await company('R200 Branch', 'BC', '752222222');
  sql(`INSERT INTO company_members (company_id,user_id,role,status,created_at) VALUES ('${P}','${UID}','owner','active',NOW()),('${W}','${UID}','owner','active',NOW())`);

  await t('R200-01', 'The group API is gone: every group/ route answers 404 and creates no tables', async () => {
    o.cid = P; const before = sql(`SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('ic_pairs','ic_pair_accounts','ic_mirrors')`);
    const r = [];
    for (const route of ['group/companies', 'group/pairs', 'group/reconciliation', 'group/consolidation']) r.push([route, (await o.call(route, {})).s]);
    r.push(['POST group/close', (await o.call('group/close', {method: 'POST', json: {companyIds: [P, W], through: '2026-09-30'}})).s]);
    const after = sql(`SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('ic_pairs','ic_pair_accounts','ic_mirrors')`);
    return {ok: r.every(x => x[1] === 404) && before === after, info: JSON.stringify({r, before, after})};
  });

  browser = await chromium.launch({executablePath: '/opt/pw-browsers/chromium', args: ['--no-proxy-server', '--host-resolver-rules=MAP gate.test 127.0.0.1']});
  await t('UI-01', 'No menu item and no group sections for a person with two companies: Settings, Company Details, the Invoice Register and Month-End Close open without the group parts and without page errors', async () => {
    const ctx = await browser.newContext({viewport: {width: 1440, height: 900}, ignoreHTTPSErrors: true});
    await ctx.addInitScript(id => { try { if (!sessionStorage.getItem('r200')) { localStorage.setItem('sr-accountax-company', id); localStorage.setItem('sr-accountax-companies', JSON.stringify([id])); sessionStorage.setItem('r200', '1'); } } catch {} }, P);
    const p = await ctx.newPage(); const errs = [];
    p.on('pageerror', e => errs.push(e.message.slice(0, 160)));
    p.on('response', async r => { if (r.status() >= 400 && /\/api\//.test(r.url()) && !/auth%2Fme/.test(r.url())) errs.push('api ' + r.status() + ' ' + r.url().replace(/^.*route=/, '').slice(0, 60)); });
    await p.route(/clarity\.ms|challenges\.cloudflare|googleapis|gstatic|bing\.com/, r => r.abort());
    await p.goto('https://gate.test/app.html'); await p.waitForSelector('#sr-login-form');
    await p.fill('#sr-login-form input[name=email]', EMAIL); await p.fill('#sr-login-form input[name=password]', PW); await p.click('#sr-login-form button[type=submit]');
    await p.waitForFunction(() => window.TeghPortal?.openCompanyDetails && document.querySelector('.sidebar,.topbar'), null, {timeout: 60000}); await p.waitForTimeout(1500);
    const noApi = await p.evaluate(() => typeof TeghPortal.openGroupIntercompany === 'undefined');
    await p.evaluate(() => TeghPortal.openSettings?.()); await p.waitForTimeout(1500);
    const noMenu = await p.evaluate(() => ![...document.querySelectorAll('button,a')].some(b => /Group & Intercompany|Intercompany/i.test(b.textContent)));
    const seen = {};
    const visit = async (name, fn, sel) => { await p.evaluate(fn); await p.waitForSelector(sel, {timeout: 30000}); await p.waitForTimeout(2500);
      seen[name] = await p.evaluate(() => ({host: document.querySelectorAll('.r200-host,[data-r200-card]').length, ic: /Intercompany|Group companies|Close the group/i.test(document.querySelector('.srp-page-body')?.innerText || '')})); };
    await visit('companyDetails', () => TeghPortal.openCompanyDetails(), '.srp-page-body');
    await visit('invoiceRegister', () => TeghPortal.invokeMenuAction('', 'receivables:customer-invoice-note-register'), '.srp-page-body');
    await visit('monthEnd', () => TeghPortal.openMonthEndClose(), '.srp-page-body');
    await p.screenshot({path: '/srv/gate/ev/shots/r200-removed-month-end.png'});
    await ctx.close();
    const clean = Object.values(seen).every(v => !v.host && !v.ic);
    return {ok: noApi && noMenu && clean && Object.keys(seen).length === 3 && !errs.length, info: JSON.stringify({noApi, noMenu, seen, errs})};
  });
} finally {
  if (browser) await browser.close().catch(() => {});
  try { sql(`UPDATE users SET active=0 WHERE id='${UID}'`); } catch {}
  save('r200.json');
}

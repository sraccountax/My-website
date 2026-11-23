// R195 (India only): after an India company is created, the dashboard's "Get your books going" lists the India set-up
// steps (PAN, GSTIN and TAN; chart of accounts; bank accounts; GST; TDS and payroll, optional; opening balances; bank
// statement; first invoice) and each button opens its page. A new Canadian company keeps the four Canadian steps.
// Synthetic data only.
import {S, sql, rec, save, need} from './lib.mjs';
import {chromium} from '/opt/node22/lib/node_modules/playwright/index.mjs';

const A = 'R195';
const t = async (id, title, fn) => { try { const r = await fn(); rec(id, A, title, r.ok ? 'PASS' : 'FAIL', r.info); return r.ok; } catch (e) { rec(id, A, title, 'FAIL', String(e.message || e).slice(0, 400)); return false; } };
const o = new S(); await o.login('owner@gate.test', 'Gate!Owner#2026pw');
const release = async () => (await o.call('site-admin', {company: false})).b.groups.release;
const setRelease = async (k, v) => { const cur = await release(); return o.call('site-admin/save', {method: 'POST', company: false, json: {groups: {release: {value: {...cur.value, [k]: v}, revision: cur.revision}}, reason: 'R195 gate: India set-up steps on the new dashboard'}}); };
const start = (await release()).value;
const tag = Date.now().toString(36), EMAIL = `r195-${tag}@gate.test`, UID = `user_r195${tag}`.padEnd(37, '0');
sql(`CREATE TEMPORARY TABLE r195u AS SELECT * FROM users WHERE email='otherowner@gate.test'; UPDATE r195u SET id='${UID}', email='${EMAIL}'; INSERT INTO users SELECT * FROM r195u`);
const IN_STEPS = ['company', 'india-registrations', 'chart-of-accounts', 'bank-accounts', 'india-gst', 'india-tds', 'india-payroll', 'opening-balances', 'bank-import', 'first-invoice'];
const EXPECT_PAGE = {'Add Registrations': /^company-details$/, 'Review Accounts': /^chart-of-accounts$/, 'Add Bank Accounts': /^(financial-accounts|bank-accounts|banking)/, 'Set Up GST': /^india-masters$/, 'Set Up TDS': /^india-masters$/, 'Set Up Payroll': /^(india-masters|india-payroll)$/, 'Add Balances': /^opening-balances$/, 'Import Statement': /^bank-imports?$/, 'Create Invoice': /^customer-(document|invoice)/};

let browser, inCo = '';
try {
  need('india mode', await setRelease('indiaEdition', 'everyone')); need('new dashboard', await setRelease('newDashboard', 'everyone'));

  await t('R195-01', 'Canada: a new company keeps the four Canadian set-up steps (company, bank statement, opening balances, first invoice), counted 1 of 4', async () => {
    const ca = await o.call('companies', {method: 'POST', company: false, json: {name: 'R195 CA ' + tag, province: 'ON', country: 'Canada', businessType: 'corporation', currency: 'CAD', accountingBasis: 'accrual', moduleMode: 'accounting', fiscalYearEnd: '12-31'}});
    const id = ca.b?.company?.id || ca.b?.companyId || ca.b?.id;
    const d = await o.call('dashboard/summary', {headers: {'X-Company-Id': id}, company: false});
    const ids = d.b?.setup?.steps?.map(s => s.id);
    return {ok: d.s === 200 && JSON.stringify(ids) === JSON.stringify(['company', 'bank-import', 'opening-balances', 'first-invoice']) && d.b.setup.done === 1 && d.b.setup.total === 4, info: `${d.s} ${JSON.stringify(ids)} ${d.b?.setup?.done}/${d.b?.setup?.total}`};
  });

  browser = await chromium.launch({executablePath: '/opt/pw-browsers/chromium', args: ['--no-proxy-server', '--host-resolver-rules=MAP gate.test 127.0.0.1']});
  const ctx = await browser.newContext({viewport: {width: 1440, height: 900}, ignoreHTTPSErrors: true});
  const p = await ctx.newPage(); const errs = [];
  p.on('pageerror', e => errs.push(e.message.slice(0, 160)));

  await t('UI-01', 'India: a new user creates an India company with the India form and the dashboard lists the India set-up steps in order, TDS and payroll marked optional and not counted', async () => {
    await p.goto('https://gate.test/app.html?edition=in'); await p.waitForSelector('#sr-login-form');
    await p.selectOption('#sr-login-form [name=edition]', 'IN');
    await p.fill('#sr-login-form input[name=email]', EMAIL); await p.fill('#sr-login-form input[name=password]', 'Gate!Member#2026pw'); await p.click('#sr-login-form button[type=submit]');
    const f = '[data-in7-first] [data-in7-company-form]'; await p.waitForSelector(f, {timeout: 45000});
    await p.fill(`${f} [name=name]`, 'R195 India ' + tag); await p.selectOption(`${f} [name=state]`, 'TN'); await p.fill(`${f} [name=city]`, 'Chennai'); await p.fill(`${f} [name=pin]`, '600001');
    await p.click(`${f} [data-in7-submit]`);
    await p.waitForSelector('.r177-steps', {timeout: 60000}); await p.waitForTimeout(1200);
    inCo = sql(`SELECT c.id FROM companies c JOIN company_members cm ON cm.company_id=c.id WHERE cm.user_id='${UID}' AND c.edition='IN' LIMIT 1`);
    const ui = await p.evaluate(() => ({head: document.querySelector('.r177-hero-main .r177-note')?.textContent || '', steps: [...document.querySelectorAll('.r177-steps > li')].map(li => ({t: li.querySelector('b')?.textContent || '', done: li.classList.contains('is-done'), next: li.classList.contains('is-next')}))}));
    await p.screenshot({path: '/srv/gate/ev/shots/r195-in-setup-1440.png'});
    const s = new S(); await s.login(EMAIL, 'Gate!Member#2026pw', {edition: 'IN'}); s.cid = inCo;
    const d = await s.call('dashboard/summary');
    const ids = d.b?.setup?.steps?.map(x => x.id) || [];
    const optional = (d.b?.setup?.steps || []).filter(x => x.optional).map(x => x.id);
    const required = (d.b?.setup?.steps || []).filter(x => !x.optional);
    const next = ui.steps.find(x => x.next);
    return {ok: JSON.stringify(ids) === JSON.stringify(IN_STEPS) && JSON.stringify(optional) === JSON.stringify(['india-tds', 'india-payroll']) && d.b.setup.total === required.length && d.b.setup.done === required.filter(x => x.done).length
      && ui.steps.length === IN_STEPS.length && /\(optional\)/.test(ui.steps[5].t) && /\(optional\)/.test(ui.steps[6].t) && !!next && !/optional/.test(next.t) && ui.head.startsWith(`${d.b.setup.done} of ${d.b.setup.total}`) && !errs.length,
      info: JSON.stringify({ids, optional, done: d.b?.setup?.done, total: d.b?.setup?.total, head: ui.head, next: next?.t, errs})};
  });

  await t('UI-02', 'India: each set-up button opens its page (Company details, Chart of accounts, India Masters for GST and TDS, payroll set-up, Opening balances, bank statement import, new invoice)', async () => {
    const opened = {}; const n = await p.locator('.r177-steps > li').count();
    for (let i = 0; i < n; i++) {
      await p.evaluate(() => window.TeghPortal.openDashboard()); await p.waitForSelector('.r177-steps', {timeout: 30000}); await p.waitForTimeout(700);
      const btn = p.locator('.r177-steps > li').nth(i).locator('button');
      if (!(await btn.count())) continue;
      const label = (await btn.textContent()).trim(); await btn.click(); await p.waitForTimeout(2500);
      opened[label] = await p.evaluate(() => [...document.querySelectorAll('.srp-page[data-srp-page]')].filter(x => x.offsetParent).pop()?.dataset.srpPage || '?');
    }
    const bad = Object.entries(opened).filter(([l, pg]) => !(EXPECT_PAGE[l] || /^$/).test(pg));
    return {ok: Object.keys(opened).length >= 7 && !bad.length && !errs.length, info: JSON.stringify({opened, bad, errs})};
  });
  await ctx.close();
} finally {
  if (browser) await browser.close().catch(() => {});
  await setRelease('indiaEdition', start.indiaEdition || 'off').catch(() => {});
  await setRelease('newDashboard', start.newDashboard || 'off').catch(() => {});
  try { sql(`UPDATE users SET active=0 WHERE id='${UID}'`); } catch {}
  save('r195.json');
}

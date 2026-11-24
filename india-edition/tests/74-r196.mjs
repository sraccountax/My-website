// R196 (India only): short GST and payroll set-up. Synthetic data only.
// Browser: a new user creates an India company; GST Set-up creates approved rate rules and the GST codes from ticked rates;
// Payroll Set-up saves EPF, ESIC and Professional Tax and adds employees with three amounts; Pay Runs calculates from the
// screen (the India screens now send JSON) and the figures follow the rules; Save structure works from the screen.
// API: confirmation required; repeating changes nothing; a different figure on the same date is refused; Canada gets 404.
import {S, sql, rec, save, need} from './lib.mjs';
import {chromium} from '/opt/node22/lib/node_modules/playwright/index.mjs';

const A = 'R196';
const t = async (id, title, fn) => { try { const r = await fn(); rec(id, A, title, r.ok ? 'PASS' : 'FAIL', r.info); return r.ok; } catch (e) { rec(id, A, title, 'FAIL', String(e.message || e).slice(0, 400)); return false; } };
const o = new S(); await o.login('owner@gate.test', 'Gate!Owner#2026pw');
const release = async () => (await o.call('site-admin', {company: false})).b.groups.release;
const setRelease = async (k, v) => { const cur = await release(); return o.call('site-admin/save', {method: 'POST', company: false, json: {groups: {release: {value: {...cur.value, [k]: v}, revision: cur.revision}}, reason: 'R196 gate: India set-up screens'}}); };
const start = (await release()).value;
const tag = Date.now().toString(36), EMAIL = `r196-${tag}@gate.test`, UID = `user_r196${tag}`.padEnd(37, '0'), PW = 'Gate!Member#2026pw';
sql(`CREATE TEMPORARY TABLE r196u AS SELECT * FROM users WHERE email='otherowner@gate.test'; UPDATE r196u SET id='${UID}', email='${EMAIL}'; INSERT INTO users SELECT * FROM r196u`);

let browser, CO = '', api;
try {
  need('india mode', await setRelease('indiaEdition', 'everyone'));
  await t('R196-02', 'Companies created at the same moment (16 at once, 3 rounds): every one is created (a deadlock between two new companies is retried), each with one creation entry in the audit trail', async () => {
    const stamp = Date.now().toString(36), st = {};
    for (let r = 0; r < 3; r++) {
      const res = await Promise.all(Array.from({length: 16}, (_, i) => o.call('companies', {method: 'POST', company: false, json: {name: `R196 Race ${stamp} ${r}-${i}`, province: 'ON', country: 'Canada', businessType: 'corporation', currency: 'CAD', accountingBasis: 'accrual', moduleMode: 'accounting', fiscalYearEnd: '12-31'}})));
      for (const x of res) st[x.s] = (st[x.s] || 0) + 1;
    }
    const made = Number(sql(`SELECT COUNT(*) FROM companies WHERE name LIKE 'R196 Race ${stamp} %'`));
    const audits = Number(sql(`SELECT COUNT(*) FROM audit_log a JOIN companies c ON c.id=a.company_id WHERE c.name LIKE 'R196 Race ${stamp} %' AND a.action='company.created'`));
    return {ok: st[201] === 48 && made === 48 && audits === 48, info: JSON.stringify({statuses: st, made, audits})};
  });

  browser = await chromium.launch({executablePath: '/opt/pw-browsers/chromium', args: ['--no-proxy-server', '--host-resolver-rules=MAP gate.test 127.0.0.1']});
  const ctx = await browser.newContext({viewport: {width: 1440, height: 900}, ignoreHTTPSErrors: true});
  const p = await ctx.newPage(); const errs = [];
  p.on('pageerror', e => errs.push(e.message.slice(0, 160)));
  p.on('response', r => { if (r.status() >= 400 && /\/api\//.test(r.url()) && !/auth%2Fme/.test(r.url())) errs.push('api ' + r.status() + ' ' + r.url().replace(/^.*route=/, '').slice(0, 60)); });
  await p.goto('https://gate.test/app.html?edition=in'); await p.waitForSelector('#sr-login-form');
  await p.selectOption('#sr-login-form [name=edition]', 'IN');
  await p.fill('#sr-login-form input[name=email]', EMAIL); await p.fill('#sr-login-form input[name=password]', PW); await p.click('#sr-login-form button[type=submit]');
  const f = '[data-in7-first] [data-in7-company-form]'; await p.waitForSelector(f, {timeout: 45000});
  await p.fill(`${f} [name=name]`, 'R196 India ' + tag); await p.selectOption(`${f} [name=state]`, 'KA'); await p.fill(`${f} [name=city]`, 'Bengaluru'); await p.fill(`${f} [name=pin]`, '560001');
  await p.click(`${f} [data-in7-submit]`); await p.waitForFunction(() => window.TeghPortal?.openIndiaGstSetup && document.querySelector('.srp-page[data-srp-page]'), null, {timeout: 60000});
  CO = sql(`SELECT c.id FROM companies c JOIN company_members cm ON cm.company_id=c.id WHERE cm.user_id='${UID}' AND c.edition='IN' LIMIT 1`);
  api = new S(); await api.login(EMAIL, PW, {edition: 'IN'}); api.cid = CO;

  await t('UI-01', 'GST Set-up: suggested rates 5% and 18% ticked; nothing is saved until the rates are confirmed; after confirming, each rate is an approved rule (source names the person) with its GST and IGST codes, and the B2C (large) limit rule is saved', async () => {
    await p.evaluate(() => TeghPortal.openIndiaGstSetup()); await p.waitForSelector('[data-r196-gst]', {timeout: 20000});
    const ticked = await p.$$eval('[data-r196-gst] input[name=rate]:checked', l => l.map(i => i.value));
    await p.click('[data-r196-gst] button[type=submit]'); await p.waitForTimeout(600);
    const refused = await p.locator('[data-r196-gst] [data-r196-error]').textContent();
    const before = Number(sql(`SELECT COUNT(*) FROM statutory_rates WHERE company_id='${CO}'`));
    await p.check('[data-r196-gst] input[name=confirmed]'); await p.click('[data-r196-gst] button[type=submit]');
    await p.waitForSelector('.r196-option.is-set', {timeout: 30000}); await p.waitForTimeout(500);
    const rules = sql(`SELECT GROUP_CONCAT(CONCAT(rate_key,':',status,':',IF(source_label LIKE '%${EMAIL}%','src','nosrc')) ORDER BY rate_key) FROM statutory_rates WHERE company_id='${CO}'`);
    const codes = sql(`SELECT GROUP_CONCAT(t.code ORDER BY t.code) FROM tax_codes t JOIN india_gst_codes g ON g.tax_code_id=t.id WHERE t.company_id='${CO}'`);
    const comp = sql(`SELECT GROUP_CONCAT(CONCAT(c.name,'=',c.rate_mpct) ORDER BY c.name) FROM tax_code_components c JOIN tax_codes t ON t.id=c.tax_code_id WHERE t.company_id='${CO}' AND t.code='GST18'`);
    await p.screenshot({path: '/srv/gate/ev/shots/r196-gst-1440.png', fullPage: true});
    return {ok: JSON.stringify(ticked) === '["5","18"]' && /confirm/i.test(refused) && before === 0 && rules === 'B2CL:active:src,GST18:active:src,GST5:active:src' && codes === 'GST18,GST5,IGST18,IGST5' && comp === 'CGST=9000,SGST=9000' && !errs.length,
      info: JSON.stringify({ticked, refused, before, rules, codes, comp, errs})};
  });

  await t('R196-01', 'GST set-up API: unconfirmed is refused; repeating the same rates changes nothing; the same rate code with a different figure from the same date is refused (409); a Canadian company gets 404', async () => {
    const un = await api.call('india/setup/gst', {method: 'POST', json: {rates: ['40'], confirmed: false}});
    const n0 = Number(sql(`SELECT COUNT(*) FROM statutory_rates WHERE company_id='${CO}'`));
    const again = await api.call('india/setup/gst', {method: 'POST', json: {rates: ['5', '18'], effectiveFrom: (await api.call('india/setup/gst')).b.effectiveFrom, b2clRupees: '100000', confirmed: true}});
    const n1 = Number(sql(`SELECT COUNT(*) FROM statutory_rates WHERE company_id='${CO}'`));
    const pr = await api.call('india/setup/payroll', {method: 'POST', json: {confirmed: true, epf: {on: true, employeePercent: '12', employerPercent: '12', ceilingRupees: '15000'}, esic: {on: false}, pt: {on: false}}});
    const conflict = await api.call('india/setup/payroll', {method: 'POST', json: {confirmed: true, epf: {on: true, employeePercent: '10', employerPercent: '12', ceilingRupees: '15000'}, esic: {on: false}, pt: {on: false}}});
    const caId = (await o.call('companies', {company: false})).b?.companies?.find(c => (c.edition || 'CA') === 'CA')?.id || sql(`SELECT c.id FROM companies c JOIN company_members cm ON cm.company_id=c.id JOIN users u ON u.id=cm.user_id WHERE u.email='owner@gate.test' AND c.edition='CA' AND c.active=1 LIMIT 1`);
    const ca = await o.call('india/setup/gst', {headers: {'X-Company-Id': caId}, company: false});
    return {ok: un.s === 422 && again.s === 200 && again.b.rates.every(r => r.rule.status === 'unchanged') && n1 === n0 && pr.s === 200 && conflict.s === 409 && ca.s === 404,
      info: JSON.stringify({un: un.s, again: again.s, statuses: again.b?.rates?.map(r => r.rule.status), n0, n1, payroll: pr.s, conflict: [conflict.s, conflict.b?.code], ca: ca.s})};
  });

  await t('UI-02', 'Payroll Set-up: EPF, ESIC and Professional Tax confirmed on one screen create the standard components; two employees are added with three amounts each (code, gross shown), and ticking ESIC after an employee exists adds it to that employee', async () => {
    await p.evaluate(() => TeghPortal.openIndiaPayrollSetup()); await p.waitForSelector('[data-r196-change-rules], [data-r196-payroll]', {timeout: 20000});
    if (await p.locator('[data-r196-change-rules]').count()) { await p.click('[data-r196-change-rules]'); await p.waitForSelector('[data-r196-payroll]'); }
    // Add the first employee before ESIC and PT are ticked (EPF only came from R196-01), then tick them.
    await p.click('[data-r196-cancel-rules]'); await p.waitForSelector('[data-r196-add]');
    const add = async (name, basic, hra, special) => { await p.click('[data-r196-add]'); await p.waitForSelector('[data-r196-employee]');
      await p.fill('[data-r196-employee] input[name=name]', name); await p.fill('[data-r196-employee] input[name=dateOfJoining]', '2026-04-01');
      await p.fill('[data-r196-employee] input[name=basic]', basic); await p.fill('[data-r196-employee] input[name=hra]', hra); await p.fill('[data-r196-employee] input[name=special]', special);
      const gross = await p.locator('[data-r196-gross]').textContent();
      await p.click('[data-r196-employee] button[type=submit]'); await p.waitForFunction(n => [...document.querySelectorAll('.r196-employees tbody tr')].some(r => r.innerText.includes(n)), name, {timeout: 30000}); return gross; };
    const g1 = await add('Asha Rao', '20000', '8000', '2000');
    await p.click('[data-r196-change-rules]'); await p.waitForSelector('[data-r196-payroll]');
    await p.check('[data-r196-payroll] input[name=esic]'); await p.check('[data-r196-payroll] input[name=pt]'); await p.check('[data-r196-payroll] input[name=confirmed]');
    await p.click('[data-r196-payroll] button[type=submit]'); await p.waitForSelector('[data-r196-change-rules]', {timeout: 30000});
    // Asha's employee record keeps ESIC/PT off; switch them on through Edit.
    await p.click('[data-r196-edit]'); await p.waitForSelector('[data-r196-employee]');
    await p.check('[data-r196-employee] input[name=esic]'); await p.check('[data-r196-employee] input[name=pt]'); await p.fill('[data-r196-employee] input[name=salaryFrom]', '2026-04-01');
    await p.click('[data-r196-employee] button[type=submit]'); await p.waitForTimeout(1500);
    const g2 = await add('Bala K', '9000', '3000', '1000');
    const rows = await p.$$eval('.r196-employees tbody tr', l => l.map(x => x.innerText.replace(/\s+/g, ' ').trim()));
    const comps = sql(`SELECT GROUP_CONCAT(JSON_UNQUOTE(JSON_EXTRACT(data_json,'$.name')) ORDER BY JSON_UNQUOTE(JSON_EXTRACT(data_json,'$.name')) SEPARATOR '|') FROM india_master_records WHERE company_id='${CO}' AND record_type='pay_component' AND status='active'`);
    await p.screenshot({path: '/srv/gate/ev/shots/r196-payroll-1440.png', fullPage: true});
    return {ok: g1 === 'Monthly gross ₹30,000' && g2 === 'Monthly gross ₹13,000' && rows.length === 2 && /E001 Asha Rao .*₹30,000\.00 EPF, ESIC, PT/.test(rows[0]) && /E002 Bala K .*₹13,000\.00 EPF, ESIC, PT/.test(rows[1])
      && comps === 'Basic|EPF (employee)|EPF (employer)|ESIC (employee)|ESIC (employer)|HRA|Income tax (TDS)|Professional Tax|Special allowance' && !errs.length, info: JSON.stringify({g1, g2, rows, comps, errs})};
  });

  await t('UI-03', 'Pay Runs from the screen (September 2026): EPF 12% of Basic capped at ₹15,000, ESIC only up to ₹21,000 gross (₹98 and ₹423 for ₹13,000), Professional Tax ₹200; net ₹28,000 and ₹11,622', async () => {
    await p.click('[data-r196-runs]'); await p.waitForSelector('[data-in5-run]', {timeout: 20000});
    await p.fill('[data-in5-run] input[name=period]', '2026-09'); await p.fill('[data-in5-run] input[name=payDate]', '2026-09-30');
    await p.click('[data-in5-run] button[type=submit]'); await p.waitForTimeout(4000);
    const run = sql(`SELECT id FROM india_pay_runs WHERE company_id='${CO}' AND period='2026-09' LIMIT 1`);
    const lines = run ? (await api.call('india/payroll/run?id=' + run)).b : null;
    const by = Object.fromEntries((lines?.lines || []).map(l => [l.name || l.employeeName, Object.fromEntries(l.components.map(c => [c.name, c.amountCents / 100])) && {net: l.netCents / 100, c: Object.fromEntries(l.components.map(c => [c.name, c.amountCents / 100]))}]));
    const a = by['Asha Rao'], b = by['Bala K'];
    return {ok: !!run && a?.net === 28000 && a.c['EPF (employee)'] === 1800 && a.c['ESIC (employee)'] === 0 && a.c['Professional Tax'] === 200 && b?.net === 11622 && b.c['EPF (employee)'] === 1080 && b.c['ESIC (employee)'] === 98 && b.c['ESIC (employer)'] === 423 && !errs.length,
      info: JSON.stringify({run, a, b, errs})};
  });

  await t('UI-04', 'Salary Structures: Save structure from the screen saves (the request is JSON; before R196 it was refused as "not valid JSON")', async () => {
    await p.evaluate(() => TeghPortal.openIndiaPayroll('structures')); await p.waitForSelector('[data-in5-structure]', {timeout: 20000});
    const n0 = Number(sql(`SELECT COUNT(*) FROM india_salary_structures WHERE company_id='${CO}'`));
    await p.fill('[data-in5-structure] input[name=effectiveFrom]', '2026-11-01'); await p.click('[data-in5-structure] button[type=submit]'); await p.waitForTimeout(2500);
    const n1 = Number(sql(`SELECT COUNT(*) FROM india_salary_structures WHERE company_id='${CO}'`));
    return {ok: n1 === n0 + 1 && !errs.length, info: JSON.stringify({n0, n1, errs})};
  });

  await t('UI-05', 'Phone (390 px): GST Set-up and Payroll Set-up (with the employee form open) fit the screen', async () => {
    await p.setViewportSize({width: 390, height: 844});
    await p.evaluate(() => TeghPortal.openIndiaGstSetup()); await p.waitForSelector('[data-r196-gst]'); await p.waitForTimeout(600);
    const g = await p.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1);
    await p.evaluate(() => TeghPortal.openIndiaPayrollSetup()); await p.waitForSelector('[data-r196-add]'); await p.click('[data-r196-add]'); await p.waitForSelector('[data-r196-employee]'); await p.waitForTimeout(600);
    const pr = await p.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1);
    await p.screenshot({path: '/srv/gate/ev/shots/r196-payroll-390.png', fullPage: true});
    return {ok: !g && !pr && !errs.length, info: JSON.stringify({gstOverflow: g, payrollOverflow: pr, errs})};
  });
  await ctx.close();
} finally {
  if (browser) await browser.close().catch(() => {});
  await setRelease('indiaEdition', start.indiaEdition || 'off').catch(() => {});
  try { sql(`UPDATE users SET active=0 WHERE id='${UID}'`); } catch {}
  save('r196.json');
}

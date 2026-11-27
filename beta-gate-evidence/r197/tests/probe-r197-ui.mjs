// R197 probe (browser): pay heads and the employee pay table in a new India company of a synthetic user.
import {sql} from './lib.mjs';
import {chromium} from '/opt/node22/lib/node_modules/playwright/index.mjs';
const tag = Date.now().toString(36), EMAIL = `r197ui-${tag}@gate.test`, UID = `user_r197${tag}`.padEnd(37, '0');
sql(`CREATE TEMPORARY TABLE ru AS SELECT * FROM users WHERE email='otherowner@gate.test'; UPDATE ru SET id='${UID}', email='${EMAIL}'; INSERT INTO users SELECT * FROM ru`);
const b = await chromium.launch({executablePath: '/opt/pw-browsers/chromium', args: ['--no-proxy-server', '--host-resolver-rules=MAP gate.test 127.0.0.1']});
const ctx = await b.newContext({viewport: {width: 1440, height: 900}, ignoreHTTPSErrors: true, deviceScaleFactor: 1});
const p = await ctx.newPage(); const errs = [];
p.on('pageerror', e => errs.push(e.message.slice(0, 200))); p.on('response', r => { if (r.status() >= 400 && /\/api\//.test(r.url()) && !/auth%2Fme/.test(r.url())) errs.push('api ' + r.status() + ' ' + r.url().replace(/^.*route=/, '').slice(0, 60)); });
await p.goto('https://gate.test/app.html?edition=in'); await p.waitForSelector('#sr-login-form');
await p.selectOption('#sr-login-form [name=edition]', 'IN');
await p.fill('#sr-login-form input[name=email]', EMAIL); await p.fill('#sr-login-form input[name=password]', 'Gate!Member#2026pw'); await p.click('#sr-login-form button[type=submit]');
const f = '[data-in7-first] [data-in7-company-form]'; await p.waitForSelector(f, {timeout: 45000});
await p.fill(`${f} [name=name]`, 'R197 India ' + tag); await p.selectOption(`${f} [name=state]`, 'MH'); await p.fill(`${f} [name=city]`, 'Pune'); await p.fill(`${f} [name=pin]`, '411001');
await p.click(`${f} [data-in7-submit]`); await p.waitForFunction(() => window.TeghPortal?.openIndiaPayrollSetup && document.querySelector('.srp-page[data-srp-page]'), null, {timeout: 60000});
await p.evaluate(() => TeghPortal.openIndiaPayrollSetup()); await p.waitForSelector('[data-r196-payroll]');
await p.check('[data-r196-payroll] input[name=pt]'); await p.check('[data-r196-payroll] input[name=confirmed]'); await p.click('[data-r196-payroll] button[type=submit]');
await p.waitForSelector('.r197-heads', {timeout: 30000});
console.log('heads:', JSON.stringify(await p.$$eval('.r197-heads > div', l => l.map(d => [...d.querySelectorAll('.r197-head')].map(x => x.innerText.replace(/\s+/g, ' ').trim())))));
// Add an allowance and a deduction, rename the allowance.
await p.click('[data-r197-add="earning"]'); await p.fill('[data-r197-new-name]', 'House Rent Allowance'); await p.click('[data-r197-add-save="earning"]'); await p.waitForSelector('[data-r197-head-name]');
await p.click('[data-r197-add="deduction"]'); await p.fill('[data-r197-new-name]', 'Canteen'); await p.click('[data-r197-add-save="deduction"]'); await p.waitForTimeout(1500);
const hra = p.locator('[data-r197-head]', {has: p.locator('[data-r197-head-name]')}).first();
await hra.locator('[data-r197-head-name]').fill('HRA'); await hra.locator('[data-r197-rename]').click(); await p.waitForTimeout(1500);
console.log('own heads:', JSON.stringify(await p.$$eval('[data-r197-head-name]', l => l.map(i => i.value))));
// Employee through the form.
await p.click('[data-r196-add]'); await p.waitForSelector('[data-r196-employee]');
const row = name => p.locator('[data-r197-row]', {has: p.locator('.r197-name', {hasText: new RegExp('^' + name + '$')})});
const setRow = async (name, mode, value, sel) => { const r = row(name); await r.locator('[data-f=mode]').selectOption(mode); if (value !== undefined) await r.locator('[data-f=value]').fill(value); if (sel) await r.locator(mode === 'amount' ? '[data-f=basis]' : '[data-f=of]').selectOption(sel); };
await p.fill('[data-r196-employee] input[name=name]', 'Asha Rao'); await p.fill('[data-r196-employee] input[name=dateOfJoining]', '2026-04-01'); await p.fill('[data-r196-employee] input[name=salaryFrom]', '2026-04-01');
await setRow('Basic Pay', 'amount', '30000', 'monthly');
await setRow('Dearness Allowance', 'percent', '50', 'basic');
await setRow('HRA', 'percent', '20', 'basic_da');
await setRow('Travel Allowance', 'amount', '19200', 'annual');
await setRow('Cell Phone Allowance', 'amount', '600', 'bi_weekly');
await setRow('Insurance', 'amount', '500', 'monthly');
await setRow('Canteen', 'percent', '1', 'gross');
await p.waitForTimeout(1500);
const pv = await p.evaluate(() => ({rows: [...document.querySelectorAll('[data-r197-row]:not([hidden])')].map(r => [r.querySelector('.r197-name').textContent, r.querySelector('[data-r197-amount]').textContent]).filter(x => x[1] !== '—'),
  gross: document.querySelector('[data-r197-gross]').textContent, ded: document.querySelector('[data-r197-ded]').textContent, net: document.querySelector('[data-r197-net]').textContent, er: document.querySelector('[data-r197-er]').textContent, err: document.querySelector('[data-r196-emp-error]').hidden ? '' : document.querySelector('[data-r196-emp-error]').textContent}));
console.log('preview:', JSON.stringify(pv));
await p.screenshot({path: '/srv/gate/ev/shots/r197-employee-form-1440.png', fullPage: true});
await p.click('[data-r196-employee] button[type=submit]'); await p.waitForSelector('.r196-employees', {timeout: 30000}); await p.waitForTimeout(800);
console.log('list:', JSON.stringify(await p.$$eval('.r196-employees tbody tr', l => l.map(x => x.innerText.replace(/\s+/g, ' ').trim()))));
await p.screenshot({path: '/srv/gate/ev/shots/r197-setup-1440.png', fullPage: true});
// Edit: values come back as entered.
await p.click('[data-r196-edit]'); await p.waitForSelector('[data-r196-employee]'); await p.waitForTimeout(1200);
console.log('edit values:', JSON.stringify(await p.$$eval('[data-r197-row]:not([hidden])', l => l.map(r => [r.querySelector('.r197-name').textContent, r.querySelector('[data-f=mode]').value, r.querySelector('[data-f=value]').value, r.querySelector('[data-f=basis]').value, r.querySelector('[data-f=of]').value]).filter(x => x[2] || x[1] !== 'amount'))));
await p.setViewportSize({width: 390, height: 844}); await p.waitForTimeout(800);
console.log('390 overflow', await p.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1));
await p.screenshot({path: '/srv/gate/ev/shots/r197-employee-form-390.png', fullPage: true});
console.log('errors', JSON.stringify(errs)); console.log('company', sql(`SELECT c.id FROM companies c JOIN company_members cm ON cm.company_id=c.id WHERE cm.user_id='${UID}' LIMIT 1`), EMAIL);
await b.close();

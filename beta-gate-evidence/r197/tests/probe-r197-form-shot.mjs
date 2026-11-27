// Screenshot of the employee pay table (element screenshots), desktop and phone, for a company and user given.
import {chromium} from '/opt/node22/lib/node_modules/playwright/index.mjs';
const [,, EMAIL, CO, WHO = 'Dev M'] = process.argv;
const b = await chromium.launch({executablePath: '/opt/pw-browsers/chromium', args: ['--no-proxy-server', '--host-resolver-rules=MAP gate.test 127.0.0.1']});
for (const [w, h] of [[1440, 900], [390, 844]]) {
  const ctx = await b.newContext({viewport: {width: w, height: h}, isMobile: w < 800, hasTouch: w < 800, ignoreHTTPSErrors: true, deviceScaleFactor: 1});
  await ctx.addInitScript(id => { try { if (!sessionStorage.getItem('s')) { localStorage.setItem('sr-accountax-company', id); sessionStorage.setItem('s', '1'); } } catch {} }, CO);
  const p = await ctx.newPage();
  await p.goto('https://gate.test/app.html?edition=in'); await p.waitForSelector('#sr-login-form');
  await p.selectOption('#sr-login-form [name=edition]', 'IN');
  await p.fill('#sr-login-form input[name=email]', EMAIL); await p.fill('#sr-login-form input[name=password]', 'Gate!Member#2026pw'); await p.click('#sr-login-form button[type=submit]');
  await p.waitForFunction(() => window.TeghPortal?.openIndiaPayrollSetup && document.querySelector('.srp-page[data-srp-page]'), null, {timeout: 60000});
  await p.evaluate(() => TeghPortal.openIndiaPayrollSetup()); await p.waitForSelector('.r196-employees', {timeout: 20000});
  await p.locator('.r196-employees').screenshot({path: `/srv/gate/ev/shots/r197-list-${w}.png`});
  await p.locator('.r196-employees tbody tr', {hasText: WHO}).locator('[data-r196-edit]').click(); await p.waitForSelector('[data-r196-employee]'); await p.waitForTimeout(1800);
  await p.locator('[data-r196-employee]').screenshot({path: `/srv/gate/ev/shots/r197-form-${w}.png`});
  console.log(w, 'overflow', await p.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1));
  await ctx.close();
}
await b.close();

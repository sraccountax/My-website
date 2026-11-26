// Theme mock-up screenshots: the real app on the gate host, with CSS/JS colours remapped in the browser only.
// Usage: node shots.mjs <email> <caCompany> <inCompany> <palette|current> [discover]
import {chromium} from '/opt/node22/lib/node_modules/playwright/index.mjs';
import {PALETTES, recolor} from './recolor.mjs';
import fs from 'fs';
const [,, EMAIL, CA, IN, PAL = 'current', MODE = ''] = process.argv;
const P = PALETTES[PAL];
const OUT = '/srv/gate/ev/theme'; fs.mkdirSync(OUT, {recursive: true});
const b = await chromium.launch({executablePath: '/opt/pw-browsers/chromium', args: ['--no-proxy-server', '--host-resolver-rules=MAP gate.test 127.0.0.1']});
async function session({w = 1440, h = 900, theme = 'light', edition = 'CA', co = CA} = {}) {
  const mobile = w < 800;
  const ctx = await b.newContext({viewport: {width: w, height: h}, isMobile: mobile, hasTouch: mobile, ignoreHTTPSErrors: true, deviceScaleFactor: 2});
  await ctx.addInitScript(id => { try { if (!sessionStorage.getItem('th')) { localStorage.setItem('sr-accountax-company', id); localStorage.setItem('sr-accountax-companies', JSON.stringify([id])); sessionStorage.setItem('th', '1'); } } catch {} }, co);
  const p = await ctx.newPage();
  await p.route(/clarity\.ms|challenges\.cloudflare|googleapis|gstatic|bing\.com/, r => r.abort());
  if (P) await p.route(/gate\.test\/.*\.(css|js|svg)(\?.*)?$/, async r => {
    if (/xlsx|pdf|onnx|tesseract|ort\./i.test(r.request().url())) return r.continue();
    const resp = await r.fetch(); const body = await resp.text();
    await r.fulfill({response: resp, body: recolor(body, P)});
  });
  if (P) await p.route(/gate\.test\/(app\.html|index\.html)?(\?.*)?$/, async r => { const resp = await r.fetch(); await r.fulfill({response: resp, body: recolor(await resp.text(), P)}); });
  await p.goto('https://gate.test/app.html' + (edition === 'IN' ? '?edition=in' : '')); await p.waitForSelector('#sr-login-form');
  if (MODE === 'signin' && w > 800 && theme === 'light' && edition === 'CA') { await p.waitForTimeout(1200); await p.screenshot({path: `${OUT}/${PAL}-signin.png`}); }
  if (edition === 'IN') await p.selectOption('#sr-login-form [name=edition]', 'IN').catch(() => {});
  await p.fill('#sr-login-form input[name=email]', EMAIL); await p.fill('#sr-login-form input[name=password]', 'Gate!Member#2026pw'); await p.click('#sr-login-form button[type=submit]');
  await p.waitForFunction(() => window.TeghPortal && document.querySelector('.sidebar,.topbar'), null, {timeout: 60000}); await p.waitForTimeout(2000);
  // Theme through the real Preferences page.
  await p.evaluate(() => window.TeghPortal.openTeghPreferences()); await p.waitForSelector('[data-tegh-preferences]'); await p.waitForTimeout(400);
  await p.locator(`[data-tegh-preferences] input[name=theme][value=${theme}]`).check({force: true}); await p.locator('[data-tegh-preferences] input[name=navigationLayout][value=side]').check({force: true});
  await p.locator('[data-tegh-preferences] button[type=submit]').first().click(); await p.waitForTimeout(1500);
  return {ctx, p};
}
const settle = async p => { await p.waitForTimeout(2200); await p.evaluate(() => document.querySelectorAll('.tegh-toast,.srp-toast,[role=status].toast').forEach(t => t.remove())).catch(() => {}); };
if (MODE === 'discover') {
  const {p} = await session();
  console.log(JSON.stringify(await p.evaluate(() => ({acts: [...new Map([...document.querySelectorAll('[data-srp-action-id]')].map(x => [x.dataset.srpActionId, x.textContent.trim().slice(0, 30)]))], reports: window.TeghPortal.listReports?.()}))));
  await b.close(); process.exit(0);
}
const go = async (p, f, ...a) => { await p.evaluate(([f, a]) => { const i = f.indexOf(':'); const kind = i < 0 ? f : f.slice(0, i), x = i < 0 ? '' : f.slice(i + 1); return kind === 'act' ? TeghPortal.invokeMenuAction('', x) : kind === 'report' ? TeghPortal.openReport(x) : TeghPortal[kind](...a); }, [f, a]); await settle(p); };
const shot = async (p, name) => p.screenshot({path: `${OUT}/${PAL}-${name}.png`});
{
  const {ctx, p} = await session();
  await go(p, 'openDashboard'); await shot(p, 'dashboard');
  await go(p, process.env.INV || 'act:invoices'); await shot(p, 'invoices');
  await go(p, 'report:' + (process.env.REP || 'profit-loss')); await shot(p, 'report');
  await ctx.close();
}
{
  const {ctx, p} = await session({edition: 'IN', co: IN});
  await go(p, 'openIndiaTaxSetup'); await shot(p, 'india-tax');
  await ctx.close();
}
if (process.env.DARK) { const {ctx, p} = await session({theme: 'dark'}); await go(p, 'openDashboard'); await shot(p, 'dark-dashboard'); await go(p, process.env.INV || 'act:invoices'); await shot(p, 'dark-invoices'); await ctx.close(); }
{ const {ctx, p} = await session({w: 390, h: 844}); await go(p, 'openDashboard'); await shot(p, 'phone'); await ctx.close(); }
await b.close();
console.log('done', PAL);

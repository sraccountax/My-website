// Phone-size captures of the demo company for the public pages (390×844 CSS pixels at 2× density).
import {chromium} from '/opt/node22/lib/node_modules/playwright/index.mjs'; import fs from 'fs';
import {mails} from '/srv/gate/t/lib.mjs';
const OUT = process.argv[2]; const d = JSON.parse(fs.readFileSync('/srv/demo/demo.json'));
const b = await chromium.launch({executablePath: '/opt/pw-browsers/chromium', args: ['--no-proxy-server', '--host-resolver-rules=MAP gate.test 127.0.0.1']});
const ctx = await b.newContext({viewport: {width: 390, height: 844}, deviceScaleFactor: 2, isMobile: true, hasTouch: true, ignoreHTTPSErrors: true});
const shift = Number(process.env.GATE_CLOCK_SHIFT_SECONDS || 0) * 1000; if (shift > 0) { await ctx.clock.install({time: Date.now() - shift}); await ctx.clock.resume(); }
const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message.slice(0, 120)));
await p.goto('https://gate.test:8444/app.html'); await p.fill('#sr-login-form input[name=email]', d.EMAIL); await p.fill('#sr-login-form input[name=password]', d.PW); await p.click('#sr-login-form button[type=submit]');
await p.waitForFunction(() => window.TeghPortal && document.querySelector('.topbar'), null, {timeout: 60000}); await p.waitForTimeout(2500);
const idle = () => p.waitForLoadState('networkidle').catch(() => {});
const shot = async (name, focus) => { await idle(); await p.waitForTimeout(1800); await p.evaluate(sel => { window.scrollTo(0, 0); const el = sel.startsWith('.') ? [...document.querySelectorAll(sel)].pop() : sel && [...document.querySelectorAll('.srp-page:last-of-type h2, .srp-page:last-of-type h3, .srp-page:last-of-type .srp-card')].find(x => sel.startsWith('/') ? new RegExp(sel.slice(1, -1)).test(x.textContent.trim()) : x.textContent.trim().startsWith(sel)); if (el) { el.scrollIntoView({block: 'start'}); const top = el.getBoundingClientRect().top; if (top < 130) { const sc = (function f(n){ while (n && n !== document.body) { const o = getComputedStyle(n).overflowY; if ((o === 'auto' || o === 'scroll') && n.scrollHeight > n.clientHeight) return n; n = n.parentElement; } return document.scrollingElement; })(el.parentElement); sc.scrollTop -= (130 - top); } const sc = el.closest('[class*=scroll], .srp-page-body, main') ; } }, focus || ''); await p.screenshot({path: `${OUT}/m-${name}.png`}); console.log('captured', name); };
await shot('overview');
await p.evaluate(() => TeghPortal.invokeMenuAction('Banking', 'Match And Post Transactions')); await shot('matching', 'Bank Transactions');
await p.evaluate(() => TeghPortal.invokeMenuAction('Receivables', 'Customer Invoice & Note Register')); await idle(); await p.waitForTimeout(1500); await p.locator('.srp-page').last().locator('a:visible, button:visible').filter({hasText: /^INV-1006$/}).first().click(); await shot('invoicing');
await p.evaluate(() => TeghPortal.invokeMenuAction('Payables', 'Vendor Invoice & Note Register')); await idle(); await p.waitForTimeout(1500); await p.locator('.srp-page').last().locator('a:visible, button:visible').filter({hasText: /^NS-88213$/}).first().click().catch(async () => { const row = p.locator('.srp-page').last().locator('tr', {hasText: 'NS-88213'}).first(); await row.locator('button', {hasText: 'Actions'}).first().click(); await p.waitForTimeout(500); await p.locator('.tegh-overlay-surface button:visible, [role=menuitem]:visible').filter({hasText: /^View/}).first().click(); }); await shot('expenses');
await p.locator('nav button:visible, nav a:visible, .srp-mobile-nav button:visible, footer button:visible').filter({hasText: /^\s*Reports\s*$/}).last().click(); await shot('reports');
await p.evaluate(() => TeghPortal.invokeMenuAction('Payroll', 'Employees')); await shot('payroll', 'Columns');
// Client dashboard, signed in with the emailed viewing code.
const link = fs.readFileSync('/srv/demo/client-link.txt', 'utf8').trim(); const since = mails().length;
const c = await ctx.newPage(); await c.goto(link); await c.waitForTimeout(2000);
await c.click('[data-cv-send-code]');
let code; for (let i = 0; i < 30 && !code; i++) { await c.waitForTimeout(500); code = mails().slice(since).map(m => m.t.match(/is: (\d{6})/)?.[1]).find(Boolean); }
if (code) { await c.locator('input[inputmode=numeric]:visible, input[name=code]:visible, input[autocomplete=one-time-code]:visible').first().fill(code); await c.locator('button:visible', {hasText: 'View dashboard'}).first().click(); }
await c.waitForTimeout(4000); await c.screenshot({path: `${OUT}/m-client-dashboard.png`}); console.log('captured client-dashboard', code ? 'signed in' : 'NO CODE');
console.log('errors', errs.join(' | ') || 'none'); await b.close();

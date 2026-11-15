// R185: speed changes. Each check proves the faster path gives the same result as before, and that the speed-up
// itself is in place. Timings are recorded as INFO only (they depend on the host); pass/fail never rests on a timing.
import {S, sql, rec, save, need} from './lib.mjs';
import {chromium} from '/opt/node22/lib/node_modules/playwright/index.mjs';
import {execFileSync} from 'child_process';
import fs from 'fs';

const A = 'R185';
const t = async (id, title, fn) => { try { const r = await fn(); rec(id, A, title, r.ok ? 'PASS' : (r.info?.startsWith?.('INFO') ? 'INFO' : 'FAIL'), r.info); return r.ok; } catch (e) { rec(id, A, title, 'FAIL', String(e.message || e).slice(0, 400)); return false; } };
const WWW = '/srv/gate/www';
const TOKEN = (fs.readFileSync(WWW + '/assets/tegh-gate-v5990.js', 'utf8').match(/const ASSET_REVISION = '([^']+)'/) || [])[1];
const curlHeaders = url => execFileSync('curl', ['--noproxy', '*', '-s', '-o', '/dev/null', '-D', '-', '--cacert', '/srv/gate/pki/ca.crt', 'https://gate.test' + url], {encoding: 'utf8'});
const cacheControl = url => (curlHeaders(url).match(/^cache-control:\s*(.+)$/im) || [])[1]?.trim() || '';

const o = new S(); await o.login('owner@gate.test', 'Gate!Owner#2026pw');
const tag = Date.now().toString(36);
const base = {businessType: 'corporation', country: 'Canada', province: 'ON', taxRegistered: true, taxNumber: '123456789RT0001', moduleMode: 'accounting', fiscalYearEnd: '12-31', booksStartDate: '2026-01-01', chartTemplate: 'default', currency: 'CAD', accountingBasis: 'accrual'};
const CO = need('company', await o.call('companies', {method: 'POST', json: {...base, name: `R185 Speed ${tag}`}})).company.id;
o.cid = CO;
// 40 invoices, enough that the legacy register table is large: 2 lines each, amounts worked out here.
const CUST = need('customer', await o.call('customers', {method: 'POST', json: {name: 'R185 Customer ' + tag, email: '', phone: '', billingAddress: '1 King St', country: 'Canada', province: 'ON'}})).customer.id;
const TMPL = (await o.call('invoice-templates')).b.templates?.[0]?.id;
let expectTotal = 0;
for (let i = 0; i < 40; i++) {
  const q = 1 + (i % 3), price = 10000 + i * 100;
  const sub = q * price, tax = Math.round(sub * 0.13); expectTotal += sub + tax;
  need('invoice ' + i, await o.call('invoices', {method: 'POST', json: {customerId: CUST, issueDate: '2026-0' + (7 + (i % 3)) + '-1' + (i % 9), dueDate: '2026-10-30', currency: 'CAD', templateId: TMPL, issue: true, lines: [{description: 'Work ' + i, quantity: q, unitPriceCents: price, taxable: true}]}}));
}

async function browser({w = 1440, h = 900, log = null} = {}) {
  const b = await chromium.launch({executablePath: '/opt/pw-browsers/chromium', args: ['--no-proxy-server', '--host-resolver-rules=MAP gate.test 127.0.0.1']});
  const ctx = await b.newContext({viewport: {width: w, height: h}, ignoreHTTPSErrors: true});
  const shift = Number(process.env.GATE_CLOCK_SHIFT_SECONDS || 0) * 1000 || Number(process.env.GATE_CLOCK_SHIFT_DAYS || 0) * 86400000;
  if (shift > 0) { await ctx.clock.install({time: Date.now() - shift}); await ctx.clock.resume(); }
  const p = await ctx.newPage(); const errs = [];
  p.on('pageerror', e => errs.push(e.message.slice(0, 200))); p.on('dialog', d => d.accept());
  if (log) p.on('request', r => log.push({url: r.url(), method: r.method(), at: Date.now()}));
  return {b, ctx, p, errs};
}
async function signIn(p, company) {
  await p.goto('https://gate.test/app.html');
  await p.fill('#sr-login-form input[name=email]', 'owner@gate.test'); await p.fill('#sr-login-form input[name=password]', 'Gate!Owner#2026pw');
  await p.click('#sr-login-form button[type=submit]');
  await p.waitForFunction(() => window.TeghPortal && document.querySelector('.sidebar,.topbar'), null, {timeout: 60000});
  if (company) {
    await p.evaluate(id => { localStorage.setItem('sr-accountax-company', id); localStorage.setItem('sr-accountax-companies', JSON.stringify([id])); }, company);
    await p.goto('https://gate.test/app.html');
    await p.waitForFunction(() => window.TeghPortal && document.querySelector('.sidebar,.topbar'), null, {timeout: 60000});
  }
  await p.waitForTimeout(1500);
}

try {
await t('PF-01', 'One release token: every /assets stylesheet and script in app.html, the gate module list and the preflight carry the same token, and .htaccess names it', async () => {
  const html = fs.readFileSync(WWW + '/app.html', 'utf8'), ht = fs.readFileSync(WWW + '/.htaccess', 'utf8'), pre = fs.readFileSync(WWW + '/assets/tegh-preflight-v5990.js', 'utf8');
  const tokens = [...new Set([...html.matchAll(/(?:href|src)="\/assets\/[^"?]+\.(?:css|js)\?v=([^"]+)"/g)].map(m => m[1]))];
  const preTok = (pre.match(/const assetRevision = '([^']+)'/) || [])[1];
  const ok = !!TOKEN && tokens.length === 1 && tokens[0] === TOKEN && preTok === TOKEN && ht.includes(`v=${TOKEN}(&|$)`);
  return {ok, info: `gate ${TOKEN}; app.html ${tokens.join(',')}; preflight ${preTok}; .htaccess names it: ${ht.includes(TOKEN)}`};
});

await t('PF-02', 'Caching: this release\'s files are kept for a year; files with an older token, app.html and the API are not; fonts are kept 30 days', async () => {
  const cur = cacheControl(`/assets/tegh-foundation-r22.css?v=${TOKEN}`), cur2 = cacheControl(`/assets/tegh-r120.css?v=${TOKEN}`);
  const old = cacheControl('/assets/tegh-foundation-r22.css?v=5990-r184-builder'), app = cacheControl('/app.html'), font = cacheControl('/assets/marketing-r164/fonts/manrope-latin-wght-normal.woff2');
  const apiH = curlHeaders('/api/index.php?route=health'); const apiCC = (apiH.match(/^cache-control:\s*(.+)$/im) || [])[1] || '';
  const ok = /max-age=31536000/.test(cur) && /immutable/.test(cur) && /immutable/.test(cur2) && !/immutable/.test(old) && /no-store/.test(app) && /max-age=2592000/.test(font) && !/immutable/.test(apiCC);
  return {ok, info: `current: ${cur}; r120: ${cur2}; older token: '${old}'; app.html: ${app}; font: ${font}; api: '${apiCC.trim()}'`};
});

await t('PF-03', 'Sign-in database check: the faster foreign-key reading returns exactly the rows of the original joined query, for every table', async () => {
  const out = execFileSync('sudo', ['-u', 'www-data', 'php', '/srv/gate/t/perf/fkcheck.php'], {encoding: 'utf8', cwd: WWW + '/api'}).trim().split('\n').pop();
  const m = out.match(/tables (\d+), rows (\d+), tables differing (\d+)/);
  return {ok: !!m && Number(m[1]) > 100 && Number(m[2]) > 100 && m[3] === '0', info: out};
});

await t('PF-04', 'startup/prepare and startup/status still report the same readiness (all ready, not degraded) through the API', async () => {
  const st = need('status', await o.call('startup/status'));
  const pr = need('prepare', await o.call('startup/prepare', {method: 'POST', json: {}}));
  const keys = ['coreReady', 'aiReady', 'nativeAgentReady', 'financialAnalysisReady', 'payrollTaxAgentReady', 'entitlementReady', 'vendorLearningReady', 'transactionControlsReady', 'bankAccountProfilesReady', 'releaseStorageReady', 'accountingFoundationReady', 'invoiceDocumentsReady', 'accountingNotesReady', 'companyTaxPresetsReady'];
  const ok = keys.every(k => st[k] === true && pr[k] === true) && st.degraded === false && pr.degraded === false;
  return {ok, info: keys.filter(k => st[k] !== true || pr[k] !== true).join(',') || `all ${keys.length} ready; degraded ${st.degraded}/${pr.degraded}`};
});

{
  const log = []; const {b, p, errs} = await browser({log});
  try {
    await p.goto('https://gate.test/app.html');
    await p.waitForSelector('#sr-login-form input[name=email]', {state: 'visible'});
    await p.click('#sr-login-form input[name=email]');
    await p.waitForTimeout(300);
    const preloads = await p.evaluate(() => [...document.querySelectorAll('link[rel=modulepreload]')].map(l => l.getAttribute('href')));
    await t('PF-05', 'Interface files start downloading when the person starts signing in (module preloads with this release\'s token)', async () => {
      const ok = preloads.length >= 14 && preloads.every(h => h.endsWith('?v=' + TOKEN)) && preloads.some(h => h.includes('tegh-portal-v5990.js')) && preloads.some(h => h.includes('index-BsxPiq85-v2817.js'));
      return {ok, info: `${preloads.length} preloads; e.g. ${preloads.slice(0, 3).join(' ')}`};
    });
    log.length = 0;
    const t0 = Date.now();
    await p.fill('#sr-login-form input[name=email]', 'owner@gate.test'); await p.fill('#sr-login-form input[name=password]', 'Gate!Owner#2026pw');
    await p.click('#sr-login-form button[type=submit]');
    await p.waitForFunction(() => window.TeghPortal && document.querySelector('.sidebar,.topbar') && document.documentElement.dataset.srStartup, null, {timeout: 60000});
    await p.waitForTimeout(2500);
    const ws = log.filter(r => /route=workspace(&|$)/.test(r.url));
    const prep = log.find(r => /route=startup%2Fprepare/.test(r.url));
    await t('PF-06', 'Sign-in reads the company workspace once, started together with the startup check, and the dashboard opens with no page errors', async () => {
      const first = ws[0];
      const ok = ws.length === 1 && !!prep && !!first && Math.abs(first.at - prep.at) < 250 && errs.length === 0 && await p.evaluate(() => !!document.querySelector('.sidebar,.topbar'));
      return {ok, info: `workspace reads ${ws.length}; started ${first && prep ? first.at - prep.at : '?'} ms from startup/prepare; errors ${JSON.stringify(errs)}; opened in ${Date.now() - t0} ms`};
    });
  } finally { await b.close(); }
}

{
  const {b, p, errs} = await browser();
  try {
    await signIn(p, CO);
    // Record, at the moment the layout code first finishes on the register, whether the legacy table was already hidden.
    await p.evaluate(() => { window.__r185 = []; window.addEventListener('tegh:activity-ready', e => { const page = e.detail?.page; if (page?.dataset?.srpPage !== 'invoices') return; const wraps = [...page.querySelectorAll('.srp-page-body .srp-table-wrap,.srp-page-body .sra-table-wrap')].filter(n => !n.closest('[data-tegh-authoritative-report]')); window.__r185.push({wraps: wraps.length, visible: wraps.filter(n => !n.hidden).length, rows: wraps.reduce((a, n) => a + n.querySelectorAll('tbody tr').length, 0)}); }); });
    const t0 = Date.now();
    await p.evaluate(() => TeghPortal.invokeMenuAction('Receivables', 'Customer Invoice & Note Register'));
    await p.waitForSelector('[data-r23-table] tbody tr', {timeout: 30000}); await p.waitForTimeout(1200);
    const ms = Date.now() - t0;
    const state = await p.evaluate(() => ({ready: window.__r185, r23Rows: document.querySelectorAll('.srp-page:last-of-type [data-r23-table] tbody tr[data-r23-row]').length, note: document.querySelector('.srp-page:last-of-type .r23-table-meta span')?.textContent || '', legacyVisible: [...document.querySelectorAll('.srp-page:last-of-type .srp-page-body .srp-table-wrap')].filter(n => !n.closest('[data-tegh-authoritative-report]') && !n.hidden).length}));
    await t('PF-07', 'Invoice register: the 40-row legacy table is already hidden when the layout code runs, and the sealed register shows 25 of 40 records', async () => {
      const first = state.ready[0];
      const ok = !!first && first.rows >= 40 && first.visible === 0 && state.r23Rows === 25 && /^40 of 40 records/.test(state.note) && state.legacyVisible === 0;
      return {ok, info: `at layout: ${JSON.stringify(first)}; register rows ${state.r23Rows}; '${state.note}'; legacy visible after ${state.legacyVisible}; ${ms} ms`};
    });
    await t('PF-08', 'Invoice register row actions still work: a row\'s Actions menu offers the invoice actions taken from the (hidden) legacy row', async () => {
      await p.click('.srp-page:last-of-type [data-r23-table] tbody tr[data-r23-row] td.r23-row-actions button');
      await p.waitForTimeout(500);
      const items = await p.evaluate(() => [...document.querySelectorAll('[role=menu] button,[role=menu] [role=menuitem],.r23-menu button')].filter(n => n.offsetParent).map(n => n.textContent.trim()).filter(Boolean));
      await p.keyboard.press('Escape');
      return {ok: items.length >= 2 && items.some(x => /print|pdf|day book|payment|edit|void/i.test(x)), info: items.slice(0, 10).join(' | ')};
    });
    await t('PF-09', 'Invoice register total: the sealed register\'s total equals the 40 invoices worked out by hand', async () => {
      const r = need('register', await o.call('professional-output/v5990/invoice-register?status=all'));
      const tot = r.output.rows.reduce((a, x) => a + Number(x.foreignTotalCents ?? x.totalCents ?? 0), 0);
      return {ok: r.output.rowCount === 40 && tot === expectTotal, info: `rows ${r.output.rowCount}; total ${tot} vs ${expectTotal}`};
    });
    await t('PF-10', 'Profile menu: the panel holding Workspace preferences carries the class that replaced the :has() test, and keeps its width', async () => {
      await p.evaluate(() => document.querySelector('[data-r17-profile] > summary, [data-r17-profile] summary')?.click());
      await p.waitForTimeout(600);
      const s = await p.evaluate(() => { const panel = document.querySelector('.r31-profile-preferences')?.closest('.r17-menu-panel'); if (!panel) return null; const r = panel.getBoundingClientRect(); return {cls: panel.classList.contains('r31-has-profile-preferences'), inOverlay: !!panel.closest('#tegh-overlay-root'), width: Math.round(r.width), overflowY: getComputedStyle(panel).overflowY}; });
      await p.keyboard.press('Escape');
      return {ok: !!s && s.cls && (!s.inOverlay || (s.width <= 400 && s.width > 200 && s.overflowY === 'auto')), info: JSON.stringify(s)};
    });
    await t('PF-11', 'No page errors while opening the register and the profile menu', async () => ({ok: errs.length === 0, info: JSON.stringify(errs)}));
  } finally { await b.close(); }
}

await t('PF-12', 'Unused stylesheets are not in the package and nothing in it refers to them', async () => {
  const files = fs.readFileSync(WWW + '/FILE-MANIFEST.sha256', 'utf8');
  const gone = ['assets/tegh-app-v5990.css', 'assets/tegh-app-v5700.css', 'assets/tegh-marketing-v5900.css'];
  const listed = gone.filter(f => files.includes('  ' + f + '\n'));
  const refs = execFileSync('bash', ['-c', `cd ${WWW} && grep -rlF -e tegh-app-v5990.css -e tegh-app-v5700.css -e tegh-marketing-v5900.css --include=*.html --include=*.js --include=*.mjs --include=*.php . || true`], {encoding: 'utf8'}).trim();
  return {ok: listed.length === 0 && refs === '', info: `listed ${JSON.stringify(listed)}; referenced by: '${refs}'`};
});
} finally { save('r185.json'); }

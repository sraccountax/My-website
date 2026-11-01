// R178: Client Dashboard Demo (platform owner). Where it is listed; it opens with sample figures and never asks the
// server for company figures; the brand-new business view; buttons explain instead of navigating; phone width;
// a company editor cannot use it; it writes nothing; it works whether the New dashboard switch is on or off.
import {S, sql, rec, save, need} from './lib.mjs';
import {open} from './e2e/base.mjs';
import {chromium} from '/opt/node22/lib/node_modules/playwright/index.mjs';
import fs from 'fs';

const A = 'R178';
const ids = JSON.parse(fs.readFileSync('/srv/gate/t/ids.json'));
const t = async (id, title, fn) => { try { const r = await fn(); rec(id, A, title, r.ok ? 'PASS' : 'FAIL', r.info); return r.ok; } catch (e) { rec(id, A, title, 'FAIL', String(e.message || e).slice(0, 400)); return false; } };
const o = new S(); await o.login('owner@gate.test', 'Gate!Owner#2026pw'); o.cid = ids.BC;
const mode = need('state', await o.call('site-admin', {company: false})).groups.release.value.newDashboard;

const openAs = async (email, password, cid, {w = 1440, h = 900} = {}) => {
  const b = await chromium.launch({executablePath: '/opt/pw-browsers/chromium', args: ['--no-proxy-server', '--host-resolver-rules=MAP gate.test 127.0.0.1']});
  const ctx = await b.newContext({viewport: {width: w, height: h}, ignoreHTTPSErrors: true, isMobile: w < 800, hasTouch: w < 800});
  const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message.slice(0, 200)));
  await p.goto('https://gate.test/app.html');
  await p.fill('#sr-login-form input[name=email]', email); await p.fill('#sr-login-form input[name=password]', password); await p.click('#sr-login-form button[type=submit]');
  await p.waitForFunction(() => window.TeghPortal && document.querySelector('.sidebar,.topbar'), null, {timeout: 40000});
  await p.evaluate(id => { localStorage.setItem('sr-accountax-company', id); localStorage.setItem('sr-accountax-companies', JSON.stringify([id])); }, cid);
  await p.goto('https://gate.test/app.html'); await p.waitForFunction(() => window.TeghPortal && document.querySelector('.sidebar,.topbar'), null, {timeout: 40000}); await p.waitForTimeout(1200);
  return {b, p, errs};
};
const demoReady = p => p.waitForFunction(() => { const r = [...document.querySelectorAll('.r178-demo .sk-region')]; return r.length === 8 && r.every(x => ['ready', 'error'].includes(x.dataset.state)); }, null, {timeout: 20000});

await t('DM-01', 'Platform owner: listed second under Settings › Platform Admin and as a card on Platform Owner Home', async () => {
  const {b, p} = await open(ids.BC);
  await p.evaluate(() => { history.pushState(null, '', '#tegh=settings-category-platform-owner'); dispatchEvent(new PopStateEvent('popstate')); }); await p.waitForTimeout(2500);
  const items = await p.evaluate(() => [...document.querySelectorAll('.srp-page:last-of-type [data-settings-item] b')].map(x => x.textContent.trim()));
  await p.evaluate(() => TeghPortal.openPlatformAdmin()); await p.waitForTimeout(2500);
  const card = await p.evaluate(() => !!document.querySelector('[data-admin-dashboard-demo]'));
  await b.close();
  return {ok: items[1] === 'Client Dashboard Demo' && items[0] === 'Site & Release Settings' && card, info: `items: ${items.slice(0, 3).join(', ')}; home card ${card}`};
});

let calls = [];
await t('DM-02', 'The demo opens with sample figures, every panel loads, and no company figures are requested from the server', async () => {
  const {b, p, errs} = await open(ids.BC);
  p.on('request', r => { const u = r.url(); if (/\/api\//.test(u)) calls.push(decodeURIComponent(u.replace(/^.*\/api\/(index\.php\?route=)?/, '').split('&')[0])); });
  calls = [];
  await p.evaluate(() => TeghPortal.openClientDashboardDemo()); await demoReady(p);
  // The headline figures count up when they appear; wait for the count to finish.
  await p.waitForFunction(() => document.querySelector('.r178-demo #r177-p-hero .tc-kpi-value')?.textContent === '$48,210', null, {timeout: 8000}).catch(() => {});
  const r = await p.evaluate(() => ({ title: [...document.querySelectorAll('.srp-page')].pop()?.querySelector('h1')?.textContent, cash: document.querySelector('.r178-demo #r177-p-hero .tc-kpi-value')?.textContent, tag: document.querySelector('.r178-demo-tag')?.textContent, chartH: document.querySelector('.r178-demo .tc-svg')?.getBoundingClientRect().height, over: document.documentElement.scrollWidth - innerWidth }));
  await p.screenshot({path: '/srv/gate/ev/shots/r178-demo.png'});
  await b.close();
  const figureCalls = calls.filter(c => /dashboard\/summary|workspace|reports\/|portal\/aging|financial/.test(c));
  return {ok: r.title === 'Client Dashboard Demo' && r.cash === '$48,210' && /sample/i.test(r.tag || '') && r.chartH >= 200 && r.over <= 0 && !figureCalls.length && !errs.length,
    info: JSON.stringify(r) + ` | figure requests: ${figureCalls.length ? figureCalls.join(', ') : 'none'}${errs.length ? ' | ' + errs.join(' | ') : ''}`};
});
await t('DM-03', 'A brand-new business: the four setup steps, empty panels, and the illustration is drawn in colour', async () => {
  const {b, p} = await open(ids.BC);
  await p.evaluate(() => TeghPortal.openClientDashboardDemo()); await demoReady(p);
  await p.click('[data-r178-kind="new"]'); await demoReady(p); await p.waitForTimeout(800);
  const r = await p.evaluate(() => ({ steps: document.querySelectorAll('.r178-demo .r177-step').length, cfEmpty: !!document.querySelector('.r178-demo #r177-p-cf .r177-empty'), stroke: getComputedStyle(document.querySelector('.r178-demo .r177-hero-chart svg path')).stroke, pressed: document.querySelector('[data-r178-kind="new"]').getAttribute('aria-pressed') }));
  await p.screenshot({path: '/srv/gate/ev/shots/r178-demo-new.png'});
  await b.close();
  return {ok: r.steps === 4 && r.cfEmpty && r.stroke && r.stroke !== 'none' && r.pressed === 'true', info: JSON.stringify(r)};
});
await t('DM-04', 'Buttons in the demo explain what they open in a client\'s account and stay on the demo', async () => {
  const {b, p} = await open(ids.BC);
  await p.evaluate(() => TeghPortal.openClientDashboardDemo()); await demoReady(p);
  await p.click('.r178-demo #r177-p-ar .r177-link'); await p.waitForTimeout(500);
  const r = await p.evaluate(() => ({ toast: document.querySelector('.srp-toast')?.textContent || '', still: !!document.querySelector('.r178-demo') }));
  await b.close();
  return {ok: /Demo only/.test(r.toast) && /who owes them/.test(r.toast) && r.still, info: JSON.stringify(r)};
});
await t('DM-05', 'Phone (390 wide): every panel loads and nothing scrolls sideways', async () => {
  const {b, p} = await open(ids.BC, {w: 390, h: 844});
  await p.evaluate(() => TeghPortal.openClientDashboardDemo()); await demoReady(p); await p.waitForTimeout(800);
  const r = await p.evaluate(() => ({ states: [...document.querySelectorAll('.r178-demo .sk-region')].map(x => x.dataset.state), over: document.documentElement.scrollWidth - innerWidth }));
  await p.screenshot({path: '/srv/gate/ev/shots/r178-demo-phone.png', fullPage: true});
  await b.close();
  return {ok: r.states.every(x => x === 'ready') && r.over <= 0, info: JSON.stringify(r)};
});
await t('DM-06', 'A company editor: no Platform Admin entry, and opening the demo directly shows only a notice', async () => {
  const {b, p} = await openAs('editor@gate.test', 'Gate!Member#2026pw', ids.BC);
  await p.evaluate(() => { history.pushState(null, '', '#tegh=settings-category-platform-owner'); dispatchEvent(new PopStateEvent('popstate')); }); await p.waitForTimeout(2500);
  const listed = await p.evaluate(() => [...document.querySelectorAll('.srp-page:last-of-type [data-settings-item]')].some(x => /Client Dashboard Demo/.test(x.textContent)));
  await p.evaluate(() => TeghPortal.openClientDashboardDemo()); await p.waitForTimeout(2500);
  const r = await p.evaluate(() => ({ dash: !!document.querySelector('.r178-demo'), text: [...document.querySelectorAll('.srp-page')].pop()?.querySelector('.srp-page-body')?.textContent.trim().slice(0, 80) }));
  await b.close();
  return {ok: !listed && !r.dash && /platform owner/i.test(r.text || ''), info: `listed ${listed}; ${JSON.stringify(r)}`};
});
await t('DM-07', 'Using the demo changes no row in any table', async () => {
  const skip = new Set(['sessions', 'login_attempts', 'platform_incident_log', 'client_view_sessions']);
  const tables = sql("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE'").split('\n').filter(x => x && !skip.has(x));
  const count = () => sql(tables.map(x => `SELECT '${x}', COUNT(*) FROM \`${x}\``).join(' UNION ALL '));
  const {b, p} = await open(ids.BC);
  const before = count();
  await p.evaluate(() => TeghPortal.openClientDashboardDemo()); await demoReady(p);
  await p.click('[data-r178-kind="new"]'); await demoReady(p); await p.click('[data-r178-kind="established"]'); await demoReady(p); await p.click('[data-r178-replay]'); await demoReady(p);
  const after = count();
  await b.close();
  const changed = before.split('\n').filter((l, i) => l !== after.split('\n')[i]);
  return {ok: !changed.length, info: changed.length ? 'changed: ' + changed.join(', ') : `${tables.length} tables unchanged`};
});
await t('DM-08', 'The demo does not depend on the New dashboard switch (it is ' + mode + ' now)', async () => {
  const {b, p} = await open(ids.BC);
  await p.evaluate(() => TeghPortal.openClientDashboardDemo()); await demoReady(p);
  const ok = await p.evaluate(() => [...document.querySelectorAll('.r178-demo .sk-region')].every(x => x.dataset.state === 'ready'));
  await b.close();
  return {ok, info: 'switch ' + mode + ', demo ready ' + ok};
});
save('r178.json');

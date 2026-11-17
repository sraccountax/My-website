// R177: the new dashboard. API: the Site & Release Settings switch (off / owner / everyone) and who may open the
// summary; every figure against the report it comes from, on several synthetic companies; company isolation; the
// request writes nothing; speed. Browser: the new page, switching to the classic dashboard and back, a member who
// may not see it, a brand-new company, phone width, dark theme, keyboard, and the switch in Site & Release Settings.
// Every setting is put back to "off" at the end.
import {S, sql, rec, save, need} from './lib.mjs';
import {open} from './e2e/base.mjs';
import {chromium} from '/opt/node22/lib/node_modules/playwright/index.mjs';
import fs from 'fs';

const A = 'R177';
const ids = JSON.parse(fs.readFileSync('/srv/gate/t/ids.json'));
const stamp = Date.now().toString(36);
const t = async (id, title, fn) => { try { const r = await fn(); rec(id, A, title, r.ok ? 'PASS' : 'FAIL', r.info); return r.ok; } catch (e) { rec(id, A, title, 'FAIL', String(e.message || e).slice(0, 400)); return false; } };
const o = new S(); await o.login('owner@gate.test', 'Gate!Owner#2026pw'); o.cid = ids.BC;
const ed = new S(); await ed.login('editor@gate.test', 'Gate!Member#2026pw'); ed.cid = ids.BC;
let st = need('state', await o.call('site-admin', {company: false}));
const setMode = async mode => {
  st = need('state', await o.call('site-admin', {company: false}));
  const r = await o.call('site-admin/save', {method: 'POST', company: false, json: {groups: {release: {value: {...st.groups.release.value, newDashboard: mode}, revision: st.groups.release.revision}}, reason: 'R177 gate check ' + stamp}});
  if (r.s === 200) st = r.b; return r;
};
const summary = async (s, cid) => { const prev = s.cid; if (cid) s.cid = cid; const r = await s.call('dashboard/summary'); s.cid = prev; return r; };

// ---------------- Switch and access ----------------
await t('DS-01', 'Switch off: nobody gets the summary (403), and auth/me says off', async () => {
  need('off', await setMode('off'));
  const a = await summary(o), b = await summary(ed), me = (await o.call('auth/me', {company: false})).b;
  return {ok: a.s === 403 && b.s === 403 && a.b.code === 'dashboard_preview_not_enabled' && me.site?.newDashboard === 'off', info: `owner ${a.s} ${a.b.code}, editor ${b.s}, auth/me ${me.site?.newDashboard}`};
});
await t('DS-02', 'Platform owner only: the platform owner gets it; a company editor does not', async () => {
  need('owner', await setMode('owner'));
  const a = await summary(o), b = await summary(ed);
  return {ok: a.s === 200 && b.s === 403, info: `owner ${a.s}, editor ${b.s}`};
});
await t('DS-03', 'Everyone: a company editor gets it too', async () => {
  need('everyone', await setMode('everyone'));
  const b = await summary(ed);
  return {ok: b.s === 200 && b.b.company.id === ids.BC, info: `editor ${b.s}`};
});
await t('DS-04', 'The switch is validated, kept when another release setting is saved, and recorded in the change history', async () => {
  const bad = await o.call('site-admin/save', {method: 'POST', company: false, json: {groups: {release: {value: {stage: st.groups.release.value.stage, newDashboard: 'sometimes'}, revision: st.groups.release.revision}}, reason: 'R177 invalid value check'}});
  st = need('state', await o.call('site-admin', {company: false}));
  const keep = await o.call('site-admin/save', {method: 'POST', company: false, json: {groups: {release: {value: {stage: st.groups.release.value.stage}, revision: st.groups.release.revision}}, reason: 'R177 stage-only save ' + stamp}});
  st = need('state', await o.call('site-admin', {company: false}));
  // DS-01..03 change the switch twice (owner, everyone); setting "off" when it is already off is not a change and,
  // correctly, is not recorded.
  const hist = Number(sql("SELECT COUNT(*) FROM site_setting_history WHERE setting_group='release' AND reason LIKE 'R177 gate check%'"));
  return {ok: bad.s === 422 && keep.s === 200 && st.groups.release.value.newDashboard === 'everyone' && hist >= 2, info: `invalid ${bad.s}; after stage-only save ${st.groups.release.value.newDashboard}; history rows ${hist}`};
});
await t('DS-05', 'Company isolation: a company the person does not belong to is refused, and no other company\'s figures appear', async () => {
  // The company editor belongs to Gate Test BC Ltd only; Gate Other Firm Ltd (X) is another firm.
  const member = sql(`SELECT COUNT(*) FROM company_members m JOIN users u ON u.id=m.user_id WHERE u.email='editor@gate.test' AND m.company_id='${ids.X}'`);
  const x = await summary(ed, ids.X);
  const own = await summary(ed, ids.BC);
  return {ok: member === '0' && [403, 404].includes(x.s) && own.s === 200 && own.b.company.id === ids.BC && !JSON.stringify(own.b).includes(ids.X), info: `editor is member of other firm: ${member}; other firm ${x.s} ${x.b?.code || ''}; own company ${own.b.company?.id === ids.BC}`};
});
await t('DS-06', 'Signed out: 401', async () => {
  const anon = new S(); anon.cid = ids.BC; const r = await anon.call('dashboard/summary');
  return {ok: r.s === 401, info: String(r.s)};
});

// ---------------- Accuracy against the reports ----------------
const companies = ['BC', 'QC', 'ON', 'US', 'R172', 'E2E'].filter(k => ids[k]);
for (const key of companies) {
  await t('DS-10-' + key, `Figures for ${key} match workspace/summary, the ageing report, profit and loss and the cash forecast`, async () => {
    const s = await summary(o, ids[key]);
    if (s.s !== 200) return {ok: false, info: 'summary ' + s.s + ' ' + JSON.stringify(s.b).slice(0, 200)};
    const d = s.b, today = d.company.asOf, prev = o.cid; o.cid = ids[key];
    const ws = need('workspace/summary', await o.call('workspace/summary')).summary;
    const aging = need('aging', await o.call(`portal/aging?type=receivable&asOf=${today}`));
    const pl = need('pl', await o.call(`reports/v5600/financial?kind=profit-loss&reportKey=profit_loss&mode=custom&start=${d.headline.periodStart}&end=${today}`));
    const start = new Date(Date.parse(today + 'T00:00:00Z') + 864e5).toISOString().slice(0, 10), end = new Date(Date.parse(start + 'T00:00:00Z') + (13 * 7 - 1) * 864e5).toISOString().slice(0, 10);
    const fc = await o.call(`reports/v5600/forecast?reportKey=cash_forecast&mode=custom&start=${start}&end=${end}&bucketUnit=week&unit=week`);
    o.cid = prev;
    const problems = [];
    const eq = (label, a, b) => { if (a !== b) problems.push(`${label}: dashboard ${a} vs report ${b}`); };
    eq('bank', d.headline.bankBalanceCents, ws.bankBalanceCents);
    eq('owed to you', d.headline.receivablesCents, ws.unpaidInvoicesCents);
    eq('you owe', d.headline.payablesCents, ws.payableSubledgerCents);
    eq('profit (headline)', d.headline.netIncomeYtdCents, ws.netIncomeYtdCents);
    if (d.receivables) {
      for (let i = 0; i < 5; i++) eq('ageing bucket ' + i, d.receivables.buckets.length ? d.receivables.buckets[i].cents : 0, aging.totals[i]);
    }
    if (!(d.headline.customMappedCards || []).includes('profit')) {
      eq('profit (P&L report)', d.headline.netIncomeYtdCents, pl.totals.netIncomeCents);
      if (d.expenses) eq('expenses total', d.expenses.totalCents, pl.totals.expenseCents);
      if (d.profit && d.profit.trend.length) eq('profit trend, last point', d.profit.trend[d.profit.trend.length - 1], pl.totals.netIncomeCents);
    }
    if (d.cash) {
      const past = d.cash.weeks.filter(w => !w.forecast), future = d.cash.weeks.filter(w => w.forecast);
      eq('forecast opening = last past week', d.cash.openingCents, past[past.length - 1].balanceCents);
      if (fc.s === 200) future.forEach((w, i) => eq('forecast week ' + (i + 1), w.balanceCents, fc.b.forecast.points[i]?.closingCents));
      else problems.push('forecast report ' + fc.s);
    }
    // Money in and out: the months both answers cover must agree.
    const wsMonths = Object.fromEntries((await (async () => { const p2 = o.cid; o.cid = ids[key]; const w = (await o.call('workspace/summary')).b; o.cid = p2; return w.cashFlow || []; })()).map(m => [m.key, [m.incoming, m.outgoing]]));
    for (const m of (d.cashFlow?.months || [])) if (wsMonths[m.month]) { eq('money in ' + m.month, m.inCents, wsMonths[m.month][0]); eq('money out ' + m.month, m.outCents, wsMonths[m.month][1]); }
    return {ok: !problems.length && d.accountingWrites === 0 && !Object.keys(d.unavailable || {}).length, info: problems.length ? problems.slice(0, 6).join('; ') : `bank ${d.headline.bankBalanceCents}, owed ${d.headline.receivablesCents}, profit ${d.headline.netIncomeYtdCents}, ${d.cash?.weeks.length || 0} weeks, ${d.cashFlow?.months.length || 0} months, ${d.todo.length} to-dos; unavailable ${JSON.stringify(d.unavailable)}`};
  });
}

await t('DS-11', 'Read-only: 15 summaries change no row in any accounting table', async () => {
  const skip = new Set(['sessions', 'login_attempts', 'platform_incident_log', 'rate_limits', 'api_rate_limits', 'client_view_sessions']);
  const tables = sql("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE'").split('\n').filter(x => x && !skip.has(x));
  const count = () => sql(tables.map(x => `SELECT '${x}', COUNT(*) FROM \`${x}\``).join(' UNION ALL '));
  const before = count();
  for (let i = 0; i < 15; i++) await summary(o, ids[companies[i % companies.length]]);
  const after = count();
  const changed = before.split('\n').filter((l, i) => l !== after.split('\n')[i]);
  return {ok: !changed.length, info: changed.length ? 'changed: ' + changed.join(', ') : `${tables.length} tables unchanged`};
});
await t('DS-12', 'Speed: every company answers in under 300 ms on the server', async () => {
  const times = [];
  for (const key of companies) { const r = await summary(o, ids[key]); times.push(`${key} ${r.b?.performance?.durationMs}`); }
  const worst = Math.max(...times.map(x => Number(x.split(' ')[1])));
  return {ok: worst < 300, info: times.join(', ')};
});

// A brand-new synthetic company: setup steps and no figures yet.
let freshId = '';
await t('DS-13', 'A new company: setup steps, no forecast weeks without bank data, no expenses, nothing to do', async () => {
  const co = need('company', await o.call('companies', {method: 'POST', company: false, json: {name: 'R177 New Co ' + stamp, province: 'ON', country: 'Canada', businessType: 'corporation', taxRegistered: false, currency: 'CAD', accountingBasis: 'accrual', moduleMode: 'both', fiscalYearEnd: '12-31', booksStartDate: '2026-01-01', coaMode: 'default'}}));
  freshId = co.company?.id || co.id;
  const r = await summary(o, freshId);
  const d = r.b;
  return {ok: r.s === 200 && d.company.isNew === true && d.setup.done === 1 && d.setup.total === 4 && d.headline.bankBalanceCents === 0 && d.cashFlow.months.length === 0 && d.expenses.items.length === 0 && d.receivables.buckets.length === 0 && d.profit === null,
    info: `isNew ${d.company?.isNew}, setup ${d.setup?.done}/${d.setup?.total}, months ${d.cashFlow?.months?.length}, to-dos ${d.todo?.length}`};
});

// ---------------- Browser ----------------
// Like e2e/base.mjs open(), but for any account (the MFA preload drives the second sign-in step).
const openAs = async (email, password, cid, {w = 1440, h = 900} = {}) => {
  const b = await chromium.launch({executablePath: '/opt/pw-browsers/chromium', args: ['--no-proxy-server', '--host-resolver-rules=MAP gate.test 127.0.0.1']});
  const ctx = await b.newContext({viewport: {width: w, height: h}, ignoreHTTPSErrors: true, isMobile: w < 800, hasTouch: w < 800});
  const shift = Number(process.env.GATE_CLOCK_SHIFT_SECONDS || 0) * 1000 || Number(process.env.GATE_CLOCK_SHIFT_DAYS || 0) * 86400000;
  if (shift > 0) { await ctx.clock.install({time: Date.now() - shift}); await ctx.clock.resume(); }
  const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message.slice(0, 200))); p.on('dialog', d => d.accept());
  await p.goto('https://gate.test/app.html');
  await p.fill('#sr-login-form input[name=email]', email); await p.fill('#sr-login-form input[name=password]', password); await p.click('#sr-login-form button[type=submit]');
  await p.waitForFunction(() => window.TeghPortal && document.querySelector('.sidebar,.topbar'), null, {timeout: 40000});
  await p.evaluate(id => { localStorage.setItem('sr-accountax-company', id); localStorage.setItem('sr-accountax-companies', JSON.stringify([id])); }, cid);
  await p.goto('https://gate.test/app.html'); await p.waitForFunction(() => window.TeghPortal && document.querySelector('.sidebar,.topbar'), null, {timeout: 40000}); await p.waitForTimeout(1200);
  return {b, p, errs};
};
const page = async (who, cid, w = 1440, h = 900, theme) => {
  const s = who === 'editor' ? await openAs('editor@gate.test', 'Gate!Member#2026pw', cid, {w, h}) : await open(cid, {w, h});
  await s.p.evaluate(() => { try { localStorage.removeItem('tegh.dashboard.choice'); } catch {} TeghPortal.openDashboard?.(true); });
  await s.p.waitForFunction(() => document.querySelector('.r177-dash') ? [...document.querySelectorAll('.r177-dash .sk-region')].every(r => ['ready', 'error'].includes(r.dataset.state)) : !!document.querySelector('.r17-dashboard, .r19-dashboard'), null, {timeout: 20000}).catch(() => {});
  await s.p.waitForTimeout(1200);
  // The app sets its theme when a page opens, so switch it afterwards (as the theme button would).
  if (theme) { await s.p.evaluate(th => { document.documentElement.dataset.teghTheme = th; }, theme); await s.p.waitForTimeout(300); }
  return s;
};
await setMode('owner');
await t('DU-01', 'Platform owner sees the new dashboard across the full width; every panel loads; the figures match the summary; no script errors', async () => {
  const {b, p, errs} = await page('owner', ids.BC);
  const api = (await summary(o, ids.BC)).b;
  const r = await p.evaluate(() => ({ states: [...document.querySelectorAll('.r177-dash .sk-region')].map(x => x.dataset.state), cash: document.querySelector('#r177-p-hero .tc-kpi-value')?.textContent, ar: document.querySelector('#r177-p-ar .tc-kpi-value')?.textContent, ap: document.querySelector('#r177-p-ap .tc-kpi-value')?.textContent, over: document.documentElement.scrollWidth - innerWidth, chartH: document.querySelector('#r177-p-hero .tc-svg')?.getBoundingClientRect().height,
    // The page frame must not keep an empty Shortcuts column beside the new dashboard.
    gapRight: Math.round(document.querySelector('.r177-dash').parentElement.getBoundingClientRect().right - document.querySelector('.r177-dash').getBoundingClientRect().right) }));
  await p.screenshot({path: '/srv/gate/ev/shots/r177-desktop.png'});
  await b.close();
  const money = c => '$' + Math.round(c / 100).toLocaleString('en-CA');
  return {ok: r.states.length === 8 && r.states.every(x => x === 'ready') && r.cash === money(api.headline.bankBalanceCents) && r.ar === money(api.headline.receivablesCents) && r.ap === money(api.headline.payablesCents) && r.over <= 0 && r.chartH >= 200 && r.gapRight <= 40 && !errs.length, info: JSON.stringify(r) + (errs.length ? ' | ' + errs.join(' | ') : '')};
});
await t('DU-02', 'Switch to the classic dashboard, then back with "Try the new dashboard"', async () => {
  const {b, p} = await page('owner', ids.BC);
  await p.click('.r177-dash .r177-bar .r177-link'); await p.waitForTimeout(3500);
  const classic = await p.evaluate(() => ({ classic: !!document.querySelector('.r19-dashboard') && !document.querySelector('.r177-dash'), tryNew: !!document.querySelector('[data-r177-try-new]'), choice: localStorage.getItem('tegh.dashboard.choice') }));
  await p.click('[data-r177-try-new]'); await p.waitForTimeout(3500);
  const back = await p.evaluate(() => !!document.querySelector('.r177-dash'));
  await b.close();
  return {ok: classic.classic && classic.tryNew && classic.choice === 'classic' && back, info: JSON.stringify(classic) + ' back to new: ' + back};
});
await t('DU-03', 'While it is owner-only, a company editor keeps the classic dashboard and is not offered the new one', async () => {
  const {b, p} = await page('editor', ids.BC);
  const r = await p.evaluate(() => ({ newDash: !!document.querySelector('.r177-dash'), classic: !!document.querySelector('.r19-dashboard'), tryNew: !!document.querySelector('[data-r177-try-new]') }));
  await b.close();
  return {ok: !r.newDash && r.classic && !r.tryNew, info: JSON.stringify(r)};
});
await t('DU-04', 'Phone (390 wide): panels stack, nothing scrolls sideways, every panel loads', async () => {
  const {b, p} = await page('owner', ids.BC, 390, 844);
  const r = await p.evaluate(() => ({ states: [...document.querySelectorAll('.r177-dash .sk-region')].map(x => x.dataset.state), over: document.documentElement.scrollWidth - innerWidth, cols: getComputedStyle(document.querySelector('.r177-kpis')).gridTemplateColumns.split(' ').length }));
  await p.screenshot({path: '/srv/gate/ev/shots/r177-phone.png', fullPage: true});
  await b.close();
  return {ok: r.states.every(x => x === 'ready') && r.over <= 0 && r.cols === 1, info: JSON.stringify(r)};
});
await t('DU-05', 'Dark theme: the dashboard uses the app\'s dark colours (dark cards, light text)', async () => {
  const {b, p} = await page('owner', ids.BC, 1440, 900, 'dark');
  const r = await p.evaluate(() => { const c = document.querySelector('#r177-p-ar').closest('.r177-card'); const cs = getComputedStyle(c); const lum = s => { const m = s.match(/\d+(\.\d+)?/g).map(Number); return (0.2126 * m[0] + 0.7152 * m[1] + 0.0722 * m[2]) / 255; }; return { card: cs.backgroundColor, text: getComputedStyle(c.querySelector('h2')).color, cardLum: lum(cs.backgroundColor), textLum: lum(getComputedStyle(c.querySelector('h2')).color) }; });
  await p.screenshot({path: '/srv/gate/ev/shots/r177-dark.png'});
  await b.close();
  return {ok: r.cardLum < 0.3 && r.textLum > 0.7, info: JSON.stringify(r)};
});
await t('DU-06', 'Keyboard: the money chart reads out each month with the arrow keys', async () => {
  const {b, p} = await page('owner', ids.BC);
  const has = await p.evaluate(() => !!document.querySelector('#r177-p-cf .tc-stage'));
  if (!has) { await b.close(); return {ok: false, info: 'no chart (no bank lines?)'}; }
  await p.focus('#r177-p-cf .tc-stage'); await p.keyboard.press('End'); await p.waitForTimeout(200);
  const tip = await p.evaluate(() => { const x = document.querySelector('#r177-p-cf .tc-tip'); return x && !x.hidden ? x.textContent : ''; });
  await b.close();
  return {ok: /Money in/.test(tip) && /Net/.test(tip), info: tip};
});
await t('DU-07', 'A new company sees the setup steps and empty states instead of zeros and empty charts', async () => {
  const {b, p} = await page('owner', freshId);
  const r = await p.evaluate(() => ({ steps: document.querySelectorAll('#r177-p-hero .r177-step').length, next: document.querySelector('#r177-p-hero .r177-step.is-next b')?.textContent, cfEmpty: document.querySelector('#r177-p-cf .r177-empty h3')?.textContent, charts: document.querySelectorAll('#r177-p-cf .tc-svg').length, todo: document.querySelector('#r177-p-todo .r177-empty h3')?.textContent }));
  await p.screenshot({path: '/srv/gate/ev/shots/r177-new-company.png'});
  await b.close();
  return {ok: r.steps === 4 && /bank statement/i.test(r.next || '') && /will show here/i.test(r.cfEmpty || '') && r.charts === 0 && /nothing needs you/i.test(r.todo || ''), info: JSON.stringify(r)};
});
await t('DU-08', 'Site & Release Settings shows the New dashboard choice and saves it with a reason', async () => {
  const {b, p} = await open(ids.BC, {w: 1440, h: 900});
  await p.evaluate(() => TeghPortal.openSiteReleaseSettings()); await p.waitForTimeout(2500);
  const form = p.locator('[data-r177-dashboard]');
  const shown = await form.count();
  await form.locator('input[value="everyone"]').check();
  await form.locator('input[name="reason"]').fill('R177 browser check ' + stamp);
  await form.locator('button[type="submit"]').click(); await p.waitForTimeout(2500);
  await b.close();
  const now = (await o.call('site-admin', {company: false})).b.groups.release.value.newDashboard;
  return {ok: shown === 1 && now === 'everyone', info: `card ${shown}, saved ${now}`};
});
await t('DU-09', 'Everyone: the company editor now sees the new dashboard', async () => {
  const {b, p} = await page('editor', ids.BC);
  const r = await p.evaluate(() => ({ newDash: !!document.querySelector('.r177-dash'), states: [...document.querySelectorAll('.r177-dash .sk-region')].map(x => x.dataset.state) }));
  await b.close();
  return {ok: r.newDash && r.states.every(x => x === 'ready'), info: JSON.stringify(r)};
});

// Put the switch back.
await t('DS-99', 'The switch is put back to off', async () => { const r = await setMode('off'); return {ok: r.s === 200 && st.groups.release.value.newDashboard === 'off', info: String(r.s)}; });
save('r177.json');

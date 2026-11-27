// Browser checks for the combined dashboard prototype.
// Run: node design/tegh-dashboard/tests/dashboard.test.mjs   (needs Playwright with Chromium)
// Screenshots go to design/tegh-dashboard/tests/screenshots/ (not committed).
import { createRequire } from 'module';
import { fileURLToPath } from 'url';
import path from 'path';
import fs from 'fs';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');
const here = path.dirname(fileURLToPath(import.meta.url));
const PAGE = 'file://' + path.resolve(here, '..', 'index.html');
const SHOTS = path.join(here, 'screenshots');
fs.mkdirSync(SHOTS, { recursive: true });

const results = [];
const check = async (id, title, fn) => {
  try { const r = await fn(); results.push({ id, title, ok: !!r.ok, info: r.info || '' }); }
  catch (e) { results.push({ id, title, ok: false, info: String(e.message || e).slice(0, 240) }); }
};
const browser = await chromium.launch();
const open = async (opts = {}) => {
  const ctx = await browser.newContext({ viewport: { width: opts.w || 1440, height: opts.h || 900 }, reducedMotion: opts.reduced ? 'reduce' : 'no-preference', colorScheme: opts.scheme || 'dark' });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  page.on('console', m => { if (m.type() === 'error' && !/fonts\.g/.test(m.text())) errors.push(m.text()); });
  await page.goto(PAGE);
  return { ctx, page, errors };
};
const allReady = page => page.waitForFunction(() => [...document.querySelectorAll('.sk-region')].every(r => r.dataset.state === 'ready' || r.dataset.state === 'error'), null, { timeout: 15000 });
const states = page => page.evaluate(() => [...document.querySelectorAll('.sk-region')].map(r => r.dataset.state));
const scenario = async (page, name) => { await page.click(`[data-scenario="${name}"]`); };

// 1. Normal load
await check('D-01', 'Every panel loads, with no script errors', async () => {
  const { ctx, page, errors } = await open();
  await allReady(page);
  const s = await states(page);
  await page.waitForTimeout(1200);
  await page.screenshot({ path: path.join(SHOTS, 'desktop-dark.png'), fullPage: true });
  await ctx.close();
  return { ok: s.every(x => x === 'ready') && !errors.length, info: s.join(',') + (errors.length ? ' | ' + errors.join(' | ') : '') };
});

// 2. Figures agree with the data
await check('D-02', 'Headline figures and totals match the summary data', async () => {
  const { ctx, page } = await open();
  await allReady(page); await page.waitForTimeout(1300);
  const v = await page.evaluate(() => ({
    cash: document.querySelector('#p-hero .tc-kpi-value')?.textContent,
    ar: document.querySelector('#p-ar .tc-kpi-value')?.textContent,
    ap: document.querySelector('#p-ap .tc-kpi-value')?.textContent,
    pl: document.querySelector('#p-pl .tc-kpi-value')?.textContent,
    cf: document.querySelector('#cf-sub')?.textContent,
    exp: document.querySelector('#exp-sub')?.textContent,
    todo: document.querySelector('#todo-count')?.textContent
  }));
  await ctx.close();
  const ok = v.cash === '$48,210' && v.ar === '$12,940' && v.ap === '$7,320' && v.pl === '$51,000' &&
    /in \$408,000 · out \$350,900 · net \+\$57,100/.test(v.cf) && /\$297,400/.test(v.exp) && v.todo === '5';
  return { ok, info: JSON.stringify(v) };
});

// 3. Period switch animates the existing chart instead of reloading
await check('D-03', 'Quarter shows 3 months and updates the totals', async () => {
  const { ctx, page } = await open();
  await allReady(page);
  await page.click('#period [data-months="3"]'); await page.waitForTimeout(1200);
  const r = await page.evaluate(() => ({ bars: document.querySelectorAll('#p-cf .tc-mark').length, sub: document.querySelector('#cf-sub').textContent, saved: localStorage.getItem('tegh.dashboard.period') }));
  await page.click('#period [data-months="12"]');
  await ctx.close();
  return { ok: r.bars === 6 && /^August 2026 to October 2026/.test(r.sub) && r.saved === '3', info: JSON.stringify(r) };
});

// 4. New company: empty states, setup checklist, no empty charts
await check('D-04', 'A new company sees the setup steps and empty states, not zeros and blank charts', async () => {
  const { ctx, page, errors } = await open();
  await allReady(page);
  await scenario(page, 'fresh'); await allReady(page); await page.waitForTimeout(600);
  const r = await page.evaluate(() => ({
    steps: document.querySelectorAll('#p-hero .step').length,
    next: document.querySelector('#p-hero .step.is-next b')?.textContent,
    cfEmpty: document.querySelector('#p-cf .empty h3')?.textContent,
    cfChart: document.querySelectorAll('#p-cf .tc-svg').length,
    todo: document.querySelector('#p-todo .empty h3')?.textContent,
    pl: document.querySelector('#p-pl .tc-kpi-value')?.textContent,
    period: document.querySelector('#period').hidden
  }));
  await page.screenshot({ path: path.join(SHOTS, 'new-company.png'), fullPage: true });
  await ctx.close();
  return { ok: r.steps === 4 && r.next === 'Import a bank statement' && /will show here/.test(r.cfEmpty || '') && r.cfChart === 0 && /Nothing needs you/.test(r.todo || '') && r.pl === '—' && r.period && !errors.length, info: JSON.stringify(r) };
});

// 5. Server error: every panel says so, the banner offers a retry
await check('D-05', 'A server error shows the banner and panel errors; nothing is left loading', async () => {
  const { ctx, page } = await open();
  await allReady(page);
  await scenario(page, 'error'); await allReady(page); await page.waitForTimeout(300);
  const r = await page.evaluate(() => ({ banner: !document.querySelector('#dash-banner').hidden, states: [...document.querySelectorAll('.sk-region')].map(x => x.dataset.state), busy: [...document.querySelectorAll('.sk-region')].filter(x => x.getAttribute('aria-busy') === 'true').length }));
  await scenario(page, 'normal'); await allReady(page);
  const after = await page.evaluate(() => document.querySelector('#dash-banner').hidden);
  await ctx.close();
  return { ok: r.banner && r.states.every(s => s === 'error') && r.busy === 0 && after, info: JSON.stringify(r) + ' banner hidden after recovery: ' + after };
});

// 6. Slow network: placeholders show, then content
await check('D-06', 'On a slow network the placeholders appear before the content', async () => {
  const { ctx, page } = await open();
  await allReady(page);
  await scenario(page, 'slow'); await page.waitForTimeout(700);
  const during = await states(page);
  await page.screenshot({ path: path.join(SHOTS, 'loading.png') });
  await allReady(page);
  const after = await states(page);
  await ctx.close();
  return { ok: during.every(s => s === 'loading') && after.every(s => s === 'ready'), info: 'during ' + during.join(',') };
});

// 7. Theme toggle persists
await check('D-07', 'Light and dark mode switch and are remembered', async () => {
  const { ctx, page } = await open();
  await allReady(page);
  const before = await page.evaluate(() => document.documentElement.dataset.theme);
  await page.click('#theme-btn');
  const mid = await page.evaluate(() => document.documentElement.dataset.theme);
  await page.reload(); await allReady(page); await page.waitForTimeout(1200);
  const after = await page.evaluate(() => document.documentElement.dataset.theme);
  await page.screenshot({ path: path.join(SHOTS, 'desktop-light.png'), fullPage: true });
  await page.click('#theme-btn');
  await ctx.close();
  return { ok: before === 'dark' && mid === 'light' && after === 'light', info: `${before} → ${mid} → after reload ${after}` };
});

// 8. Sidebar rail persists; phone drawer opens and closes
await check('D-08', 'Sidebar collapses with [ and stays collapsed after a reload', async () => {
  const { ctx, page } = await open();
  await allReady(page);
  await page.keyboard.press('[');
  await page.waitForTimeout(400);
  const a = await page.evaluate(() => document.querySelector('#sidebar').classList.contains('is-collapsed'));
  await page.reload(); await allReady(page);
  const b = await page.evaluate(() => document.querySelector('#sidebar').classList.contains('is-collapsed'));
  await page.keyboard.press('[');
  await ctx.close();
  return { ok: a && b, info: `collapsed ${a}, after reload ${b}` };
});
await check('D-09', 'Phone: menu opens the drawer, Esc closes it and returns focus', async () => {
  const { ctx, page } = await open({ w: 390, h: 844 });
  await allReady(page);
  await page.click('#menu'); await page.waitForTimeout(500);
  const open1 = await page.evaluate(() => ({ open: document.querySelector('#sidebar').classList.contains('is-open'), mainInert: document.querySelector('#main').inert }));
  await page.screenshot({ path: path.join(SHOTS, 'phone-drawer.png') });
  await page.keyboard.press('Escape'); await page.waitForTimeout(400);
  const closed = await page.evaluate(() => ({ open: document.querySelector('#sidebar').classList.contains('is-open'), focus: document.activeElement.id }));
  await ctx.close();
  return { ok: open1.open && open1.mainInert && !closed.open && closed.focus === 'menu', info: JSON.stringify({ open1, closed }) };
});

// 9. No sideways scrolling at common widths
await check('D-10', 'No sideways scrolling at 390, 768, 1024 and 1440 pixels wide', async () => {
  const out = [];
  for (const w of [390, 768, 1024, 1440]) {
    const { ctx, page } = await open({ w, h: 900 });
    await allReady(page); await page.waitForTimeout(900);
    out.push([w, await page.evaluate(() => document.documentElement.scrollWidth - innerWidth)]);
    if (w === 390) await page.screenshot({ path: path.join(SHOTS, 'phone.png'), fullPage: true });
    await ctx.close();
  }
  return { ok: out.every(([, o]) => o <= 0), info: out.map(([w, o]) => w + ':' + o).join(' ') };
});

// 10. Keyboard reaches chart values
await check('D-11', 'Keyboard: arrow keys on the money chart show each month', async () => {
  const { ctx, page } = await open();
  await allReady(page);
  await page.focus('#p-cf .tc-stage'); await page.keyboard.press('End'); await page.waitForTimeout(200);
  const tip = await page.evaluate(() => { const t = document.querySelector('#p-cf .tc-tip'); return t && !t.hidden ? t.textContent : ''; });
  await ctx.close();
  return { ok: /Oct/.test(tip) && /Net/.test(tip), info: tip };
});

// 11. Reduced motion: figures appear at once
await check('D-12', 'With motion turned off, figures show their final value immediately', async () => {
  const { ctx, page } = await open({ reduced: true });
  await allReady(page);
  const v = await page.evaluate(() => document.querySelector('#p-ar .tc-kpi-value').textContent);
  await ctx.close();
  return { ok: v === '$12,940', info: v };
});

// 12. Basic accessibility
await check('D-13', 'Every button has a name, every picture a description, and no panel is left busy', async () => {
  const { ctx, page } = await open();
  await allReady(page);
  const r = await page.evaluate(() => ({
    unnamed: [...document.querySelectorAll('button')].filter(b => b.offsetParent && !(b.getAttribute('aria-label') || b.textContent.trim())).length,
    imgs: [...document.querySelectorAll('[role="img"]')].filter(x => !x.getAttribute('aria-label')).length,
    busy: [...document.querySelectorAll('[aria-busy="true"]')].length,
    h1: document.querySelectorAll('h1').length
  }));
  await ctx.close();
  return { ok: !r.unnamed && !r.imgs && !r.busy && r.h1 === 1, info: JSON.stringify(r) };
});

await browser.close();
let pass = 0;
for (const r of results) { if (r.ok) pass++; console.log((r.ok ? 'PASS' : 'FAIL').padEnd(5), r.id.padEnd(5), r.title, '|', r.info); }
console.log(`\n${pass}/${results.length} passed`);
process.exit(pass === results.length ? 0 : 1);

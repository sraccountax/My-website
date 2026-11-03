// R175: public-page layout (width, heading sizes) and phone captures; the register Actions column is pinned only on
// wider screens. Browser checks against the installed package.
import {rec, save} from './lib.mjs';
import {open} from './e2e/base.mjs';
import {chromium} from '/opt/node22/lib/node_modules/playwright/index.mjs';
import fs from 'fs';
const A = 'R175';
const ids = JSON.parse(fs.readFileSync('/srv/gate/t/ids.json'));
const t = async (id, title, fn) => { try { const r = await fn(); rec(id, A, title, r.ok ? 'PASS' : 'FAIL', r.info); return r.ok; } catch (e) { rec(id, A, title, 'FAIL', String(e.message || e).slice(0, 300)); return false; } };
const b = await chromium.launch({executablePath: '/opt/pw-browsers/chromium', args: ['--no-proxy-server', '--host-resolver-rules=MAP gate.test 127.0.0.1']});
const page = async (path, w, h = 900) => {
  const ctx = await b.newContext({viewport: {width: w, height: h}, ignoreHTTPSErrors: true, isMobile: w < 800, hasTouch: w < 800});
  const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(e.message.slice(0, 100)));
  await p.goto('https://gate.test' + path, {waitUntil: 'load'}); await p.waitForTimeout(800);
  return {p, ctx, errs};
};

await t('MK-01', 'Desktop 1920: content spans most of the screen (side margins at most 200px each), no sideways scroll', async () => {
  const {p, ctx} = await page('/index.html', 1920);
  const m = await p.evaluate(() => { const r = document.querySelector('.hero').getBoundingClientRect(); return {left: Math.round(r.left), right: Math.round(innerWidth - r.right), over: document.documentElement.scrollWidth - innerWidth}; });
  await ctx.close(); return {ok: m.left <= 200 && m.right <= 200 && m.over <= 0, info: JSON.stringify(m)};
});

await t('MK-02', 'Desktop 1440: hero heading at most 50px; section headings at least 44px; card headings at least 22px; menu 15px', async () => {
  const {p, ctx} = await page('/index.html', 1440);
  const m = await p.evaluate(() => { const fs = s => parseFloat(getComputedStyle(document.querySelector(s)).fontSize);
    return {h1: fs('.hero h1'), h2: fs('#product-explorer h2'), card: fs('.boundary-grid h3'), nav: fs('.desktop-nav a'), side: Math.round(document.querySelector('.hero').getBoundingClientRect().left)}; });
  await ctx.close(); return {ok: m.h1 <= 50 && m.h2 >= 44 && m.card >= 22 && m.nav >= 15 && m.side <= 40, info: JSON.stringify(m)};
});

await t('MK-03', 'Phone 390: every product capture on the home, product and features pages is the phone capture (m-*.webp)', async () => {
  const out = [];
  for (const path of ['/index.html', '/product.html', '/features.html']) {
    const {p, ctx, errs} = await page(path, 390, 844);
    // Load every capture (they are lazy), including those in hidden explorer tabs.
    const srcs = await p.evaluate(async () => { const imgs = [...document.querySelectorAll('.product-window img')];
      for (const img of imgs) { img.loading = 'eager'; const panel = img.closest('[role=tabpanel]'); const was = panel?.hidden; if (panel) panel.hidden = false; img.scrollIntoView(); await new Promise(r => setTimeout(r, 120)); if (img.decode) await img.decode().catch(() => {}); if (panel) panel.hidden = was; }
      return imgs.map(i => (i.currentSrc || '').split('/').pop()); });
    const over = await p.evaluate(() => document.documentElement.scrollWidth - innerWidth);
    out.push({path, n: srcs.length, phone: srcs.filter(s => s.startsWith('m-')).length, over, errs: errs.length}); await ctx.close();
  }
  return {ok: out.every(o => o.n > 0 && o.phone === o.n && o.over <= 0 && !o.errs), info: JSON.stringify(out)};
});

await t('MK-04', 'Desktop 1440: the same captures are the desktop ones', async () => {
  const {p, ctx} = await page('/product.html', 1440);
  const srcs = await p.evaluate(async () => { const imgs = [...document.querySelectorAll('.product-window img')]; for (const i of imgs) { i.loading = 'eager'; i.scrollIntoView(); await new Promise(r => setTimeout(r, 100)); } return imgs.map(i => (i.currentSrc || '').split('/').pop()); });
  await ctx.close(); return {ok: srcs.length > 0 && srcs.every(s => s && !s.startsWith('m-')), info: srcs.join(', ')};
});

await t('MK-05', 'Phone: "Expand" opens the phone capture in the preview', async () => {
  const {p, ctx} = await page('/index.html', 390, 844);
  await p.locator('.hero [data-preview]').first().click(); await p.waitForTimeout(500);
  const src = await p.evaluate(() => document.querySelector('.preview-dialog img').getAttribute('src'));
  await ctx.close(); return {ok: /m-overview\.webp$/.test(src || ''), info: src};
});

const reg = async w => {
  const {b: bb, p} = await open(ids.BC, {w, h: 900});
  await p.evaluate(() => TeghPortal.openReport('Customer Invoice & Note Register')); await p.waitForTimeout(3500);
  const pos = await p.evaluate(() => { const td = document.querySelector('.srp-page:last-of-type td.r23-row-actions'); return td ? getComputedStyle(td).position : 'none'; });
  await bb.close(); return pos;
};
await t('MK-06', 'Register Actions column: pinned at 1440 wide (R172), not pinned on a phone where it hid the amounts', async () => {
  const wide = await reg(1440), phone = await reg(390);
  return {ok: wide === 'sticky' && phone !== 'sticky', info: `1440 ${wide}; 390 ${phone}`};
});

await b.close();
save('r175.json');

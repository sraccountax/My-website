// R176: Site & Release Settings is listed under Settings › Platform Admin and found by the Settings search.
import {rec, save} from './lib.mjs';
import {open, ids} from './e2e/base.mjs';
const A = 'R176';
const t = async (id, title, fn) => { try { const r = await fn(); rec(id, A, title, r.ok ? 'PASS' : 'FAIL', r.info); return r.ok; } catch (e) { rec(id, A, title, 'FAIL', String(e.message || e).slice(0, 300)); return false; } };
const go = (p, route) => p.evaluate(r => { history.pushState(null, '', '#tegh=' + r); dispatchEvent(new PopStateEvent('popstate')); }, route);

for (const [id, w, h] of [['SR-01', 1440, 900], ['SR-02', 390, 844]]) {
  await t(id, `Settings › Platform Admin at ${w} wide lists Site & Release Settings first and opens the page`, async () => {
    const {b, p, errs} = await open(ids().BC, {w, h});
    await go(p, 'settings-category-platform-owner'); await p.waitForTimeout(2500);
    const items = await p.evaluate(() => [...document.querySelectorAll('.srp-page:last-of-type [data-settings-item]')].map(x => (x.querySelector('b')?.textContent || x.textContent).trim()));
    const item = p.locator('.srp-page [data-settings-item]:visible').filter({hasText: 'Site & Release Settings'}).first();
    let title = '';
    if (await item.count()) { await item.click(); await p.waitForTimeout(2500); title = await p.evaluate(() => [...document.querySelectorAll('.srp-page')].pop()?.querySelector('h1')?.textContent.trim()); }
    const over = await p.evaluate(() => document.documentElement.scrollWidth - innerWidth);
    await b.close();
    return {ok: /Site & Release Settings/.test(items[0] || '') && title === 'Site & Release Settings' && over <= 0 && !errs.length, info: `first item: ${items[0]}; opens: ${title}; overflow ${over}; errors ${errs.length}`};
  });
}

await t('SR-03', 'The Settings search finds Site & Release Settings', async () => {
  const {b, p} = await open(ids().BC, {w: 1440, h: 900});
  await go(p, 'module-my-account'); await p.waitForTimeout(2500);
  const box = p.locator('[data-settings-search]:visible').first();
  if (!await box.count()) { await b.close(); return {ok: false, info: 'no search box'}; }
  await box.pressSequentially('Site & Release', {delay: 30}); await p.waitForTimeout(1000);
  const hits = await p.locator('[data-settings-search-results]:visible button, [data-settings-search-results]:visible [role=option]').allTextContents();
  await b.close();
  return {ok: hits.some(x => /Site & Release Settings/.test(x)), info: `${hits.length} result(s): ${hits.map(x => x.trim().slice(0, 40)).join(' | ')}`};
});

save('r176.json');

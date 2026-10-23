// II-UI-GUIDED alone, in the suite's order: Tegh Assist answer window open first, then the Guided menu.
import {S, rec, save} from './lib.mjs';
import {open, ids} from './e2e/base.mjs';
const A = 'R170';
const t = async (id, title, fn) => { try { const r = await fn(); rec(id, A, title, r.ok ? 'PASS' : 'FAIL', r.info); } catch (e) { rec(id, A, title, 'FAIL', e.message.slice(0, 400)); } };
const {b, p, errs} = await open(ids().R170);
await p.evaluate(() => TeghPortal.openExplicitTeghAssistant('Show possible duplicate invoices'));
await p.waitForSelector('[data-ii-answer="duplicates"]', {timeout: 30000});
await t('II-UI-GUIDED', 'Guided mode: Money Out › Read Supplier Bills opens Invoice Intelligence', async () => {
  // II-UI-ASSIST leaves the Tegh Assist dialog open over the page; close it first.
  await p.keyboard.press('Escape'); await p.waitForTimeout(400);
  if (await p.locator('.tegh-assist-scrim').count()) { await p.reload(); await p.waitForFunction(() => window.TeghPortal && document.querySelector('.sidebar,.topbar'), null, {timeout: 40000}); }
  // The layout checks above end in top navigation; Guided's menu is checked in the sidebar.
  await p.evaluate(() => { const prefs = JSON.parse(localStorage.getItem('tegh-preferences') || '{}'); prefs.navigationLayout = 'side'; localStorage.setItem('tegh-preferences', JSON.stringify(prefs)); TeghPortal.refreshNavigation(); });
  await p.evaluate(() => TeghPortal.switchBookkeepingMode('owner')); await p.waitForTimeout(2500);
  const money = p.locator('.sidebar nav button:has-text("Money Out")').first();
  if ((await money.getAttribute('aria-expanded')) !== 'true') await money.click();
  await p.waitForTimeout(500);
  await p.locator('.sidebar nav button[data-srp-menu="Payables"]:has-text("Read Supplier Bills")').first().click();
  const opened = await p.waitForFunction(() => [...document.querySelectorAll('.srp-page')].pop()?.dataset.srpPage === 'invoice-intel', null, {timeout: 30000}).then(() => true, () => false);
  const note = await p.locator('[data-ii-vendor-note]').first().waitFor({timeout: 20000}).then(() => 1, () => 0);
  await p.evaluate(() => TeghPortal.switchBookkeepingMode('accountant')); await p.waitForTimeout(2500);
  return {ok: opened && note > 0, info: `screen opened ${opened}; supplier note shown ${note > 0}`};
});
await t('II-JS', 'No browser errors', async () => ({ok: errs.length === 0, info: errs.join(' | ') || 'none'}));
await b.close();
save('r170-guided.json');

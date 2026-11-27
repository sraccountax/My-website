// R182 mode switch (development): lands on the mode's home with a review.
import {sql} from '../lib.mjs';
import {open} from '../e2e/base.mjs';
const cid = process.env.CID || sql("SELECT id FROM companies WHERE name LIKE 'Volume Northwind%' LIMIT 1");
const {b, p, errs} = await open(cid, {w: 1440, h: 900});
const log = (k, v) => console.log(k.padEnd(26), typeof v === 'string' ? v : JSON.stringify(v));
const state = () => p.evaluate(() => { const pg = [...document.querySelectorAll('.srp-page')].pop(); const rv = document.querySelector('[data-r182-mode-review]'); return {page: pg?.dataset.srpPage, title: pg?.querySelector('h1')?.textContent?.trim().slice(0, 40), mode: document.documentElement.dataset.bookkeepingMode, review: rv ? [...rv.querySelectorAll('li')].map(li => li.querySelector('b').textContent + ': ' + li.querySelector('.r182-review-state').textContent) : null}; });
for (const target of ['owner', 'accountant']) {
  await p.evaluate(() => TeghPortal.invokeMenuAction('Banking', 'Bank Transfers')); await p.waitForTimeout(2500);
  await p.evaluate(() => TeghPortal.invokeMenuAction('My account', 'Bookkeeping Mode')); await p.waitForTimeout(2500);
  const btn = p.locator(`[data-settings-mode="${target}"]`).first();
  if (!(await btn.count())) { log('no button for', target); continue; }
  if (await btn.isDisabled()) { log('already', target); }
  await btn.click(); await p.waitForTimeout(9000);
  log('after switch to ' + target, await state());
  await p.screenshot({path: `/srv/gate/ev/shots/r182-mode-${target}.png`});
}
log('page errors', errs);
await b.close();

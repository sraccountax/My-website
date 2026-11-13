// R182 banking screens (development).
import {sql} from '../lib.mjs';
import {open} from '../e2e/base.mjs';
const D = '/srv/gate/ev/shots/';
const cid = process.env.CID || sql("SELECT id FROM companies WHERE name LIKE 'Volume Northwind%' LIMIT 1");
const {b, p, errs} = await open(cid, {w: 1440, h: 900});
const log = (k, v) => console.log(k.padEnd(28), typeof v === 'string' ? v : JSON.stringify(v));
const scrollers = () => p.evaluate(() => [...document.querySelectorAll('*')].filter(e => { const s = getComputedStyle(e); return (s.overflowY === 'auto' || s.overflowY === 'scroll') && e.scrollHeight > e.clientHeight + 4 && e.offsetParent; }).map(e => String(e.className).slice(0, 40)));

// 1. Match and Post: Add transaction button and edit pencil
await p.evaluate(() => TeghPortal.invokeMenuAction('Banking', 'Match And Post Transactions'));
await p.waitForSelector('[data-r182-add-tx]', {timeout: 60000}); await p.waitForTimeout(1500);
log('pencils on page', await p.locator('[data-r182-edit-desc]').count());
await p.locator('[data-r182-add-tx]').first().click(); await p.waitForSelector('[data-r182-add]');
await p.fill('[data-r182-add] [name=amount]', '45.00'); await p.fill('[data-r182-add] [name=description]', 'Internet – monthly UI ' + Date.now());
await p.fill('[data-r182-add] [name=date]', '2026-08-15');
await p.check('[data-r182-add] [name=repeat]'); await p.fill('[data-r182-add] [name=repeatMonths]', '2'); await p.dispatchEvent('[data-r182-add] [name=repeatMonths]', 'input');
log('repeat note', await p.textContent('[data-r182-repeat-note]'));
log('scrollers with form open', await scrollers());
await p.screenshot({path: D + 'r182-add-tx.png'});
await p.click('[data-r182-save]'); await p.waitForTimeout(2500);
log('toast', await p.evaluate(() => document.querySelector('.srp-toast')?.textContent || ''));
await p.waitForTimeout(20000);
const pencil = p.locator('[data-r182-edit-desc]').first();
if (await pencil.count()) {
  await pencil.click(); await p.waitForSelector('[data-r182-edit]');
  const nd = 'Edited in Match and Post ' + Date.now(); await p.fill('[data-r182-edit] [name=description]', nd);
  await p.click('[data-r182-edit] button[type=submit]'); await p.waitForTimeout(2500);
  log('edit toast', await p.evaluate(() => document.querySelector('.srp-toast')?.textContent || ''));
  log('edited in db', sql(`SELECT COUNT(*) FROM bank_transactions WHERE company_id='${cid}' AND description='${nd}'`));
}
await p.screenshot({path: D + 'r182-match-post.png'});
// 2. Bank Transaction Report: Add transaction button
await p.evaluate(() => TeghPortal.invokeMenuAction('Banking', 'Bank Transaction Report')); await p.waitForTimeout(5000);
log('report add button', await p.locator('.srp-page:last-of-type [data-r182-add-tx], [data-r182-add-tx]').count());
// 3. Upload Statement → CSV preview: editable descriptions, one scroll area
await p.evaluate(() => TeghPortal.invokeMenuAction('Banking', 'Upload Statement')); await p.waitForTimeout(3000);
const fs = await import('fs'); const csvPath = '/tmp/r182-ui.csv';
fs.writeFileSync(csvPath, `Date,Description,Amount\n2026-09-04,UI ROW A ${Date.now()},-12.00\n2026-09-05,UI ROW B ${Date.now()},30.00\n`);
const f = p.locator('[data-bank-preview-form]').last();
await f.locator('input[type=file]').setInputFiles(csvPath); await f.locator('button[type=submit]').click();
await p.waitForSelector('.tegh-bank-verification-panel [data-r182-desc]', {timeout: 40000}); await p.waitForTimeout(800);
log('scrollers with preview open', await scrollers());
log('page scroll locked', await p.evaluate(() => getComputedStyle(document.querySelector('.r22-primary-scroll')).overflowY));
const di = p.locator('[data-r182-desc]').first(); const newText = 'UI edited ' + Date.now();
await di.fill(newText); await di.press('Enter'); await p.waitForTimeout(3000);
log('after desc edit', await p.evaluate(() => [document.querySelector('[data-bank-verify-error]')?.textContent, document.querySelector('[data-r182-desc]')?.value]));
await p.screenshot({path: D + 'r182-preview.png'});
await p.locator('.tegh-bank-verification-panel [data-bank-cancel]').first().click(); await p.waitForTimeout(1500);
log('page errors', errs);
await b.close();

import {sql} from '../lib.mjs';
import {open} from '../e2e/base.mjs';
const CO = sql(`SELECT id FROM companies WHERE name LIKE 'R184 Builder%' ORDER BY created_at DESC LIMIT 1`);
const {b, p, errs} = await open(CO, {w: 1440, h: 900});
p.on('response', r => { if (/report-builder\/reports/.test(r.url())) console.log('resp', r.request().method(), r.status()); });
try {
  await p.evaluate(() => TeghPortal.invokeMenuAction('Reports', 'Report Builder'));
  await p.waitForSelector('[data-rb-form] select[name=source]', {timeout: 30000});
  await p.click('[data-rb-run]'); await p.waitForSelector('[data-rb-table]', {timeout: 30000});
  await p.click('[data-rb-saveas]'); await p.waitForSelector('.rb-save input[name=name]');
  await p.fill('.rb-save input[name=name]', 'Dev saved'); await p.click('.rb-save button[type=submit]');
  await p.waitForTimeout(3000);
  console.log('options', await p.evaluate(() => [...document.querySelectorAll('[data-rb-saved] option')].map(o => o.textContent)));
  console.log('modal', await p.evaluate(() => document.querySelector('.rb-save')?.innerText));
  await p.screenshot({path: '/srv/gate/t/dev/r184-save.png'});
} finally { console.log('errors', JSON.stringify(errs)); await b.close(); }

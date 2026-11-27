import {sql} from '../lib.mjs';
import {open} from '../e2e/base.mjs';
const CO = sql(`SELECT id FROM companies WHERE name LIKE 'R184 Builder%' ORDER BY created_at DESC LIMIT 1`);
const {b, p, errs} = await open(CO, {w: 1440, h: 900});
try {
  await p.evaluate(() => TeghPortal.invokeMenuAction('Reports', 'Report Builder'));
  await p.waitForSelector('[data-rb-form] select[name=source]', {timeout: 30000});
  await p.selectOption('[data-rb-form] select[name=source]', 'gl'); await p.waitForTimeout(300);
  await p.selectOption('[data-rb-form] select[name=preset]', 'this-year'); await p.dispatchEvent('[data-rb-form] select[name=preset]', 'change');
  await p.click('[data-rb-run]'); await p.waitForSelector('[data-rb-table]', {timeout: 30000});
  console.log('cols', await p.evaluate(() => document.querySelectorAll('[data-rb-table] thead th').length));
  await p.click('[data-rb-download] > summary'); await p.click('[data-rb-export=pdf]'); await p.waitForTimeout(6000);
  console.log('toasts', await p.evaluate(() => [...document.querySelectorAll('.srp-toast,.toast,[role=status]')].map(x => x.innerText).filter(Boolean).slice(-3)));
  await p.screenshot({path: '/srv/gate/t/dev/r184-gl.png'});
} finally { console.log('errors', JSON.stringify(errs)); await b.close(); }

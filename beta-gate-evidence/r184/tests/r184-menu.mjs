import {sql} from '../lib.mjs';
import {open} from '../e2e/base.mjs';
import fs from 'fs';
const CO = sql(`SELECT id FROM companies WHERE name LIKE 'R184 Builder%' ORDER BY created_at DESC LIMIT 1`);
sql(`UPDATE site_settings SET value_json=JSON_SET(value_json,'$.reportBuilder','everyone') WHERE setting_group='release'`);
const {b, p, errs} = await open(CO, {w: 1440, h: 900});
try {
  await p.locator('.sidebar nav button:has-text("Reports")').first().click(); await p.waitForTimeout(1500);
  await p.screenshot({path: '/srv/gate/t/dev/r184-menu.png'});
  console.log('visible:', await p.evaluate(() => [...document.querySelectorAll('button,a')].filter(x => x.offsetParent && /Report Builder/i.test(x.textContent)).map(x => x.className + ' :: ' + x.textContent.trim().slice(0, 60))));
  await p.evaluate(() => TeghPortal.invokeMenuAction('Reports', 'Report Builder'));
  await p.waitForSelector('[data-rb-form] select[name=source]', {timeout: 30000});
  await p.selectOption('[data-rb-form] select[name=source]', 'gl'); await p.waitForTimeout(300);
  await p.selectOption('[data-rb-form] select[name=preset]', 'this-year'); await p.dispatchEvent('[data-rb-form] select[name=preset]', 'change');
  await p.click('[data-rb-run]'); await p.waitForSelector('[data-rb-table]', {timeout: 30000});
  await p.click('[data-rb-download] > summary');
  const dl = p.waitForEvent('download'); await p.click('[data-rb-export=pdf]'); const d = await dl; fs.copyFileSync(await d.path(), '/srv/gate/t/dev/r184.pdf');
  await p.screenshot({path: '/srv/gate/t/dev/r184-gl.png'});
} finally { console.log('errors', JSON.stringify(errs)); await b.close(); }

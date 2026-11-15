import {sql} from '../lib.mjs';
import {open} from '../e2e/base.mjs';
const CO = sql(`SELECT id FROM companies WHERE name LIKE 'R184 Builder%' ORDER BY created_at DESC LIMIT 1`);
sql(`UPDATE site_settings SET value_json=JSON_SET(value_json,'$.reportBuilder','everyone') WHERE setting_group='release'`);
const {b, p, errs} = await open(CO, {w: Number(process.argv[2] || 1440), h: 900});
try {
  await p.evaluate(() => TeghPortal.invokeMenuAction('Reports', 'Report Builder'));
  await p.waitForSelector('[data-rb-form] select[name=source]', {timeout: 30000});
  await p.selectOption('[data-rb-form] select[name=source]', 'invoices'); await p.waitForTimeout(300);
  await p.selectOption('[data-rb-form] select[name=preset]', 'custom').catch(() => {});
  await p.fill('[data-rb-form] input[name=start]', '2026-07-01'); await p.fill('[data-rb-form] input[name=end]', '2026-09-30');
  await p.dispatchEvent('[data-rb-form] input[name=end]', 'change');
  await p.click('[data-rb-run]'); await p.waitForSelector('[data-rb-table]', {timeout: 30000}); await p.waitForTimeout(500);
  await p.screenshot({path: `/srv/gate/t/dev/r184-ui-${process.argv[2] || 1440}.png`, fullPage: false});
  console.log(await p.evaluate(() => [...document.querySelectorAll('[data-rb-table] thead th')].map(x => x.textContent).join(' | ')));
  console.log(await p.evaluate(() => document.querySelector('[data-rb-table] tfoot')?.innerText.replace(/\s+/g, ' ')));
  await p.click('[data-rb-table] tbody tr:first-child [data-rb-drill]'); await p.waitForSelector('.rb-drill table', {timeout: 30000});
  console.log('drill:', await p.evaluate(() => document.querySelector('.rb-drill-body .rb-meta')?.textContent));
  await p.screenshot({path: `/srv/gate/t/dev/r184-drill.png`});
} finally { console.log('errors', JSON.stringify(errs)); await b.close(); }

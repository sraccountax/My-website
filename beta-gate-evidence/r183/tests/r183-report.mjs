// Bank Transaction Report: what each download button does (development).
import {sql} from '../lib.mjs';
import {open} from '../e2e/base.mjs';
const cid = process.env.CID || sql("SELECT id FROM companies WHERE name LIKE 'R182 Ontario%' ORDER BY created_at DESC LIMIT 1");
const {b, p, errs} = await open(cid, {w: 1440, h: 900});
const log = (k, v) => console.log(k.padEnd(24), typeof v === 'string' ? v : JSON.stringify(v));
const bad = []; p.on('response', r => { if (r.url().includes('/api/') && r.status() >= 400) bad.push(r.status() + ' ' + decodeURIComponent(r.url().replace(/^.*route=/, '')).slice(0, 90)); });
p.on('console', m => { if (m.type() === 'error') bad.push('console: ' + m.text().slice(0, 200)); });
await p.evaluate(() => TeghPortal.invokeMenuAction('Banking', 'Bank Transaction Report')); await p.waitForTimeout(6000);
const buttons = await p.evaluate(() => [...document.querySelectorAll('.srp-page:last-of-type button, [class*=output] button')].filter(b => b.offsetParent).map(b => b.textContent.trim()).filter(Boolean));
log('buttons', buttons);
for (const label of ['Export CSV', 'Download', 'Excel', 'PDF', 'CSV']) {
  const btn = p.locator('button:visible', {hasText: label}).first();
  if (!(await btn.count())) continue;
  const dl = p.waitForEvent('download', {timeout: 15000}).then(d => d.suggestedFilename()).catch(e => 'NO DOWNLOAD (' + e.message.slice(0, 40) + ')');
  await btn.click().catch(e => log('click fail ' + label, e.message.slice(0, 80)));
  await p.waitForTimeout(1500);
  const menu = await p.evaluate(() => [...document.querySelectorAll('[role=menu] button,[role=menuitem],.r19-menu button')].filter(b => b.offsetParent).map(b => b.textContent.trim()));
  log(label, [await dl, menu]);
  await p.keyboard.press('Escape');
}
await p.screenshot({path: '/srv/gate/ev/shots/r183-report.png'});
log('bad', bad); log('page errors', errs);
await b.close();

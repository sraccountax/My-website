// R183: when the full report cannot be prepared, Download explains why and still gives the lines on screen as CSV.
import {sql} from '../lib.mjs';
import {open} from '../e2e/base.mjs';
const log = (k, v) => console.log(k.padEnd(26), typeof v === 'string' ? v : JSON.stringify(v));
const cid = process.env.CID || sql("SELECT id FROM companies WHERE name='Volume Lakeshore Inc' LIMIT 1");
const {b, p, errs} = await open(cid, {w: 1440, h: 900});
try {
  await p.evaluate(() => TeghPortal.invokeMenuAction('Banking', 'Bank Transaction Report')); await p.waitForTimeout(9000);
  log('full report state', await p.evaluate(() => ({unavailable: document.body.innerText.includes('Complete report unavailable'), sealed: !![...document.querySelectorAll('.srp-page')].pop().teghSealedReport, download: !!document.querySelector('[data-r183-download]')})));
  await p.click('[data-r183-download] > summary'); await p.waitForTimeout(300); await p.click('[data-r183-format=xlsx]');
  await p.waitForSelector('.r183-dl', {timeout: 30000});
  log('dialog', await p.evaluate(() => document.querySelector('.r183-dl').innerText.replace(/\s+/g, ' ').slice(0, 300)));
  const dl = p.waitForEvent('download', {timeout: 20000}).then(async d => [d.suggestedFilename(), (await import('fs')).readFileSync(await d.path(), 'utf8').split('\n').length]).catch(() => 'NO DOWNLOAD');
  await p.click('[data-r183-csv]'); log('csv of lines on screen', await dl);
  await p.screenshot({path: '/srv/gate/ev/shots/r183-fail.png'});
} finally { log('page errors', errs); await b.close(); }

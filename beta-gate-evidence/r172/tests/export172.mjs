// R172 export sweep: every report, every export format offered in its Export menu. A download must arrive, be
// non-empty and be the format named (PDF / XLSX / CSV), with no browser errors or failed requests.
import fs from 'fs';
import {open, ids} from './e2e/base.mjs';
const coKey = process.argv[2] || 'BC';
const {b, p, errs} = await open(ids()[coKey], {w: 1440, h: 900});
const failed = []; p.on('response', r => { if (r.status() >= 400 && /gate\.test/.test(r.url())) failed.push(r.status() + ' ' + decodeURIComponent((/route=([^&]+)/.exec(r.url()) || [])[1] || r.url().slice(-60))); });
const reports = JSON.parse(fs.readFileSync('/srv/gate/t/deep172-reports.json'));
const out = [];
const sniff = buf => buf.slice(0, 4).toString('latin1') === '%PDF' ? 'pdf' : buf.slice(0, 2).toString('latin1') === 'PK' ? 'xlsx' : /^[\x09\x0a\x0d\x20-\x7e -￿"]/.test(buf.slice(0, 1).toString('utf8')) ? 'text' : 'other';
// Screens without the standard table menu offer their own export buttons: "Export Current Visible Columns",
// direct CSV / Excel / PDF buttons, or an "Export▾" menu. Each must still produce the file it names.
async function grab(name, label, click) {
  errs.length = 0; failed.length = 0;
  let dl;
  try { [dl] = await Promise.all([p.waitForEvent('download', {timeout: 30000}), click()]); }
  catch (e) { out.push({report: name, format: label, ok: false, info: 'no download: ' + e.message.split('\n')[0].slice(0, 100) + (failed.length ? ' | ' + failed.join(', ') : '')}); return; }
  const path = await dl.path(); const buf = path ? fs.readFileSync(path) : Buffer.alloc(0);
  const fn = dl.suggestedFilename(), kind = sniff(buf);
  const want = /pdf/i.test(label) || /\.pdf$/i.test(fn) ? 'pdf' : /excel|xlsx/i.test(label) || /\.xlsx$/i.test(fn) ? 'xlsx' : 'text';
  out.push({report: name, format: label, ok: buf.length > (want === 'text' ? 50 : 200) && kind === want && !errs.length && !failed.length, info: `${fn} ${buf.length} bytes ${kind}${errs.length ? ' js:' + errs.join(';') : ''}${failed.length ? ' http:' + failed.join(',') : ''}`});
  await p.waitForTimeout(500);
}
async function otherExports(name) {
  const pg = p.locator('.srp-page').last();
  const direct = pg.locator('button:visible, a:visible').filter({hasText: /^\s*(Export Current Visible Columns|Export All Report Columns|CSV|Excel|PDF)\s*$/});
  const labels = await direct.allTextContents();
  for (const l of labels) await grab(name, l.trim(), () => pg.locator('button:visible, a:visible').filter({hasText: new RegExp('^\\s*' + l.trim() + '\\s*$')}).first().click());
  const menu = pg.locator('button:visible, summary:visible').filter({hasText: /^\s*Export\s*▾?\s*$/}).first();
  if (await menu.count()) {
    await menu.click(); await p.waitForTimeout(400);
    const items = (await p.evaluate(() => [...document.querySelectorAll('[role=menu] button, [role=menu] a, details[open] button, details[open] a, .tegh-overlay-surface button, .tegh-overlay-surface a')].filter(x => x.getBoundingClientRect().width).map(x => x.textContent.trim()).filter(t => /csv|excel|xlsx|pdf/i.test(t))));
    await p.keyboard.press('Escape').catch(() => {});
    for (const it of [...new Set(items)]) await grab(name, it, async () => { await menu.click(); await p.waitForTimeout(400); await p.locator('button:visible, a:visible').filter({hasText: it}).last().click(); });
    if (!items.length) out.push({report: name, format: 'Export▾', ok: false, info: 'menu opened with no format items'});
  }
  if (!labels.length && !(await menu.count())) out.push({report: name, format: '-', ok: true, info: 'no export on this screen'});
}
for (const name of reports) {
  errs.length = 0; failed.length = 0;
  try { await p.evaluate(n => TeghPortal.openReport(n), name); } catch (e) { out.push({report: name, format: '-', ok: false, info: 'open: ' + e.message.slice(0, 100)}); continue; }
  await p.waitForTimeout(3500);
  const btn = p.locator('.srp-page').last().locator('button[aria-label="Table actions"]:visible').first();
  if (!(await btn.count())) { await otherExports(name); continue; }
  const openDlg = async () => {
    await btn.click(); await p.waitForTimeout(400);
    const it = p.locator('[role=menu] button:visible').filter({hasText: 'Export'}).first();
    if (!(await it.count())) return null;
    await it.click(); await p.waitForTimeout(600);
    return p.locator('dialog[open], [role=dialog]').filter({hasText: 'Export report'}).first();
  };
  let dlg = await openDlg();
  if (!dlg || !(await dlg.isVisible().catch(() => false))) { out.push({report: name, format: '-', ok: false, info: 'Export… not reachable from Table actions'}); await p.keyboard.press('Escape').catch(() => {}); continue; }
  const formats = (await dlg.locator('select').first().evaluate(s => [...s.options].map(o => o.value))).filter(f => f !== 'print');
  for (const f of formats) {
    errs.length = 0; failed.length = 0;
    if (!(await dlg.isVisible().catch(() => false))) dlg = await openDlg();
    let dl = null;
    try {
      await dlg.locator('select').first().selectOption(f);
      [dl] = await Promise.all([p.waitForEvent('download', {timeout: 30000}), dlg.locator('button').filter({hasText: /^Export$/}).first().click()]);
    } catch (e) { out.push({report: name, format: f, ok: false, info: 'no download: ' + e.message.split('\n')[0].slice(0, 100) + (failed.length ? ' | ' + failed.join(', ') : '')}); await p.keyboard.press('Escape').catch(() => {}); continue; }
    const path = await dl.path(); const buf = path ? fs.readFileSync(path) : Buffer.alloc(0);
    const kind = sniff(buf), want = f === 'pdf' ? 'pdf' : f === 'xlsx' ? 'xlsx' : 'text';
    const ok = buf.length > 200 && kind === want && !errs.length && !failed.length;
    out.push({report: name, format: f, ok, info: `${dl.suggestedFilename()} ${buf.length} bytes ${kind}${errs.length ? ' js:' + errs.join(';') : ''}${failed.length ? ' http:' + failed.join(',') : ''}`});
    await p.waitForTimeout(500);
  }
  await p.keyboard.press('Escape').catch(() => {});
}
fs.writeFileSync(`/srv/gate/ev/export172-${coKey}.json`, JSON.stringify(out, null, 1));
const bad = out.filter(x => !x.ok);
console.log(`exports: ${out.filter(x => x.format !== '-').length} downloads tried across ${reports.length} reports; ${bad.length} problems`);
for (const x of bad) console.log(' ', x.report.padEnd(34), x.format.padEnd(8), x.info);
for (const x of out.filter(x => x.format === '-')) console.log('  (info)', x.report, x.info);
await b.close();

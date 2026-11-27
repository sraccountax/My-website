import {sql} from '../lib.mjs';
import {open} from '../e2e/base.mjs';
const cid = process.env.CID || sql("SELECT id FROM companies WHERE name LIKE 'R182 Ontario%' ORDER BY created_at DESC LIMIT 1");
const {b, p, errs} = await open(cid, {w: Number(process.env.W || 1440), h: 900});
const log = (k, v) => console.log(k.padEnd(24), typeof v === 'string' ? v : JSON.stringify(v));
p.on('console', m => { if (m.type() === 'error') log('console', m.text().slice(0, 200)); });
await p.evaluate(() => TeghPortal.invokeMenuAction('Banking', 'Bank Transaction Report')); await p.waitForTimeout(7000);
await p.click('.r23-actions-head button'); await p.waitForTimeout(800);
log('after click', await p.evaluate(() => ({menuButtons: [...document.querySelectorAll('.r23-menu button, [class*=r23] [role=menu] button, .r23-layer button')].filter(x => x.offsetParent).map(x => x.textContent.trim()), status: [...document.querySelectorAll('.r23-status,[role=status]')].map(x => x.textContent.trim()).filter(Boolean).slice(-3), expanded: document.querySelector('.r23-actions-head button').getAttribute('aria-expanded')})));
const exp = p.locator('button:visible', {hasText: 'Export…'}).first();
if (await exp.count()) {
  await exp.click(); await p.waitForTimeout(800);
  for (const fmt of ['xlsx', 'csv', 'pdf']) {
    if (fmt !== 'xlsx') { await p.click('.r23-actions-head button'); await p.waitForTimeout(500); await p.locator('button:visible', {hasText: 'Export…'}).first().click(); await p.waitForTimeout(600); }
    await p.locator('dialog[open] select').first().selectOption(fmt);
    const dl = p.waitForEvent('download', {timeout: 40000}).then(d => d.suggestedFilename()).catch(() => 'NO DOWNLOAD');
    await p.locator('dialog[open] button', {hasText: /^Export$/}).click();
    const got = await dl; await p.waitForTimeout(800);
    log(fmt, [got, await p.evaluate(() => [...document.querySelectorAll('dialog[open] [role=alert], dialog[open] .r23-error, .srp-toast')].map(x => x.textContent.trim().slice(0, 200)))]);
    await p.keyboard.press('Escape'); await p.waitForTimeout(300);
  }
}
log('page errors', errs);
await b.close();

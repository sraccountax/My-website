import {sql} from '../lib.mjs';
import {open} from '../e2e/base.mjs';
const cid = process.env.CID || sql("SELECT id FROM companies WHERE name LIKE 'R182 Ontario%' ORDER BY created_at DESC LIMIT 1");
const W = Number(process.env.W || 1440);
const {b, p, errs} = await open(cid, {w: W, h: 900});
const log = (k, v) => console.log(k.padEnd(24), typeof v === 'string' ? v : JSON.stringify(v));
const net = []; p.on('response', r => { if (r.url().includes('/api/')) net.push(r.status() + ' ' + decodeURIComponent(r.url().replace(/^.*route=/, '')).slice(0, 110)); });
await p.evaluate(() => TeghPortal.invokeMenuAction('Banking', 'Bank Transaction Report')); await p.waitForTimeout(7000);
log('export btns', await p.evaluate(() => [...document.querySelectorAll('button')].filter(b => /export/i.test(b.textContent)).map(b => { const r = b.getBoundingClientRect(); const s = getComputedStyle(b); return {t: b.textContent.trim(), x: Math.round(r.x), y: Math.round(r.y), w: Math.round(r.width), vis: s.visibility, disp: s.display, hidden: b.hidden, parent: String(b.parentElement.className).slice(0, 60), anc: b.closest('[hidden]') ? 'in hidden' : ''}; })));
log('unavailable', await p.evaluate(() => document.body.innerText.includes('Complete report unavailable')));
const btn = p.locator('button', {hasText: /^Export/}).first();
if (await btn.count()) {
  await btn.click({force: true}).catch(e => log('click', e.message.slice(0, 80))); await p.waitForTimeout(800);
  const items = await p.evaluate(() => [...document.querySelectorAll('[role=menuitem], [role=menu] button')].filter(x => x.offsetParent).map(x => x.textContent.trim()));
  log('menu', items);
  for (const it of items) {
    await btn.click({force: true}).catch(() => {}); await p.waitForTimeout(400);
    const dl = p.waitForEvent('download', {timeout: 30000}).then(d => d.suggestedFilename()).catch(() => 'NO DOWNLOAD');
    await p.locator('[role=menuitem]:visible, [role=menu] button:visible', {hasText: it}).first().click().catch(e => log('item', e.message.slice(0, 60)));
    log(it, [await dl, await p.evaluate(() => [...document.querySelectorAll('.srp-toast,[role=alert]')].map(t => t.textContent.trim().slice(0, 140)).filter(Boolean).slice(-2))]);
    await p.keyboard.press('Escape');
  }
}
log('api', net.filter(x => !x.startsWith('200')).concat(net.filter(x => /report|output|export/.test(x)).slice(0, 8)));
log('page errors', errs);
await b.close();

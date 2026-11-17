import {sql} from '../lib.mjs';
import {open} from '../e2e/base.mjs';
const cid = sql("SELECT id FROM companies WHERE name LIKE 'R182 Ontario%' ORDER BY created_at DESC LIMIT 1");
const {b, p, errs} = await open(cid, {w: 1440, h: 900});
const log = (k, v) => console.log(k.padEnd(24), typeof v === 'string' ? v : JSON.stringify(v));
const bad = []; p.on('response', r => { if (r.url().includes('/api/') && r.status() >= 400) bad.push(r.status() + ' ' + decodeURIComponent(r.url().replace(/^.*route=/, '')).slice(0, 120)); });
p.on('console', m => { if (['error', 'warning'].includes(m.type())) bad.push('console: ' + m.text().slice(0, 200)); });
await p.evaluate(() => TeghPortal.invokeMenuAction('Banking', 'Bank Transaction Report')); await p.waitForTimeout(6000);
log('grid classes', await p.evaluate(() => { const a = [...document.querySelectorAll('button')].find(b => /Actions/.test(b.textContent) && b.offsetParent); let e = a, out = []; for (let i = 0; i < 6 && e; i++) { out.push(e.tagName + '.' + String(e.className).slice(0, 50) + (e.dataset ? JSON.stringify(Object.keys(e.dataset)) : '')); e = e.parentElement; } return out; }));
const actions = p.locator('button:visible', {hasText: 'Actions'}).first(); await actions.click(); await p.waitForTimeout(800);
await p.screenshot({path: '/srv/gate/ev/shots/r183-actions.png'});
const items = await p.evaluate(() => { const r = document.querySelector('.r23-actions-head button').getBoundingClientRect(); return [...document.querySelectorAll('button,a,[role=menuitem]')].filter(b => { const q = b.getBoundingClientRect(); return b.offsetParent && q.top > r.bottom - 2 && q.top < r.bottom + 400 && q.left > r.left - 400; }).map(b => b.textContent.trim()).filter(Boolean).slice(0, 20); });
log('menu items', items);
for (const it of items) {
  if (!/download|export|csv|excel|pdf|print/i.test(it)) continue;
  await p.keyboard.press('Escape'); await p.waitForTimeout(300); await actions.click(); await p.waitForTimeout(500);
  const dl = p.waitForEvent('download', {timeout: 20000}).then(d => d.suggestedFilename()).catch(() => 'NO DOWNLOAD');
  const pop = p.context().waitForEvent('page', {timeout: 20000}).then(pg => 'popup ' + pg.url().slice(0, 60)).catch(() => '');
  await p.locator('button:visible, a:visible, [role=menuitem]:visible', {hasText: it}).first().click().catch(e => log('click', e.message.slice(0, 60)));
  await p.waitForTimeout(4000);
  const dialog = await p.evaluate(() => [...document.querySelectorAll('dialog[open], [role=dialog]')].map(d => d.textContent.replace(/\s+/g, ' ').slice(0, 160)));
  log(it, [await dl, await pop, dialog, await p.evaluate(() => document.querySelector('.srp-toast')?.textContent?.slice(0, 160) || '')]);
}
log('bad', bad); log('page errors', errs);
await b.close();

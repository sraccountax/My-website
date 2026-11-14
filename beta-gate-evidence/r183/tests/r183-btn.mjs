import {sql} from '../lib.mjs';
import {open} from '../e2e/base.mjs';
const cid = sql("SELECT id FROM companies WHERE name LIKE 'R182 Ontario%' ORDER BY created_at DESC, id LIMIT 1");
const {b, p} = await open(cid, {w: 1440, h: 900});
await p.evaluate(() => TeghPortal.invokeMenuAction('Banking', 'Match And Post Transactions'));
await p.waitForSelector('[data-r182-add-tx]', {timeout: 60000}); await p.waitForTimeout(1000);
console.log(JSON.stringify(await p.evaluate(() => { const a = document.querySelector('[data-r182-add-tx]'); const s = getComputedStyle(a); let e = a, path = []; for (let i = 0; i < 4; i++) { e = e.parentElement; path.push(e.tagName + '.' + e.className); } return {cls: a.className, border: s.border, bg: s.backgroundColor, path, css: [...document.styleSheets].some(x => /tegh-r182\.css/.test(x.href || '') && [...x.cssRules].some(r => r.cssText.includes('r183-add-tx')))}; })));
await b.close();

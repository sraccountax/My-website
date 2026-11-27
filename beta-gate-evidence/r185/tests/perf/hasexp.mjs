// Experiment: style-recalc time of a set of navigations with all CSS rules intact, then with every :has() rule
// removed in the browser (CSSOM only; nothing on the server changes). Usage: node hasexp.mjs <company> <mode:keep|strip>
import {open} from '../e2e/base.mjs';
const [co, mode] = process.argv.slice(2);
const {b, p} = await open(co);
const cdp = await p.context().newCDPSession(p); await cdp.send('Performance.enable');
await p.waitForTimeout(2000);
if (mode !== 'keep') {
  const n = await p.evaluate((mode) => {
    let removed = 0;
    const walk = list => { for (let i = list.cssRules.length - 1; i >= 0; i--) { const r = list.cssRules[i]; if (r.cssRules && !(r instanceof CSSStyleRule)) walk(r); else if (r.selectorText && (mode.startsWith('sel:') ? r.selectorText.includes(mode.slice(4)) : (mode === 'strip' || mode.startsWith('sheet:')) ? r.selectorText.includes(':has(') : mode === 'root' ? /(^|,\s*)(html|body):has\(/.test(r.selectorText) : !/(^|,\s*)(html|body):has\(/.test(r.selectorText) && r.selectorText.includes(':has('))) { list.deleteRule(i); removed++; } } };
    for (const s of document.styleSheets) { if (mode.startsWith('sheet:') && !(s.href || '').includes(mode.slice(6))) continue; try { walk(s); } catch {} }
    return removed;
  }, mode);
  console.log('removed :has rules', n);
}
const metric = async () => Object.fromEntries((await cdp.send('Performance.getMetrics')).metrics.map(x => [x.name, x.value]));
const navs = [['Receivables', 'Customers'], ['Payables', 'Vendors'], ['Reports', 'Trial Balance'], ['Banking', 'Bank Transaction Report'], ['General ledger', 'Journal Entries'], ['Reports', 'Profit and Loss']];
let total = 0;
for (let round = 0; round < 4; round++) for (const [m, a] of navs) {
  const before = await metric();
  await p.evaluate(([m, a]) => TeghPortal.invokeMenuAction(m, a), [m, a]); await p.waitForTimeout(2500);
  const after = await metric(); const ms = Math.round((after.RecalcStyleDuration - before.RecalcStyleDuration) * 1000);
  if (round >= 1) { total += ms; console.log(mode, a.padEnd(26), ms, 'ms style'); }
}
console.log(mode, 'TOTAL style ms (second round):', total);
await b.close();

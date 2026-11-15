// Records each ActivityShell mount (element count and step timings) during one navigation.
import {open} from '../e2e/base.mjs';
const [m, a, co] = process.argv.slice(2);
const {b, p} = await open(co); await p.waitForTimeout(1500);
await p.evaluate(() => { window.__mounts = []; });
await p.evaluate(([m, a]) => TeghPortal.invokeMenuAction(m, a), [m, a]); await p.waitForTimeout(5000);
console.log(JSON.stringify(await p.evaluate(() => ({m: window.__mounts, els: document.querySelectorAll('*').length, rows: document.querySelectorAll('tbody tr').length}))));
await b.close();

// Calls one route repeatedly with pauses, to tell first-call cost from time-based work. Usage: node calls2.mjs <company> <route> <pause ms> <count>
import {S} from '../lib.mjs';
const [co, route, pause, count] = process.argv.slice(2);
const o = new S(); await o.login('owner@gate.test', 'Gate!Owner#2026pw'); o.cid = co;
for (let i = 0; i < Number(count); i++) {
  const t = Date.now(); const r = await o.call(route); console.log(i, r.s, Date.now() - t, 'ms');
  await new Promise(res => setTimeout(res, Number(pause)));
}

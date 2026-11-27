// Signs in once, then calls the given API routes, printing status and wall time. Usage: node calls.mjs <company> <route> [route...]
// A route starting with "POST " is sent as POST with an empty JSON body.
import {S} from '../lib.mjs';
const [co, ...routes] = process.argv.slice(2);
const o = new S(); await o.login('owner@gate.test', 'Gate!Owner#2026pw'); o.cid = co;
if (process.env.MARK) { const fs = await import('fs'); fs.writeFileSync(process.env.MARK, String(Date.now())); }
for (const r of routes) {
  const post = r.startsWith('POST ');
  const route = post ? r.slice(5) : r;
  const t = Date.now();
  const res = await o.call(route, post ? {method: 'POST', json: {}} : {});
  console.log(String(res.s).padEnd(4), String(Date.now() - t).padStart(6) + ' ms', route, JSON.stringify(res.b).length + ' B');
}

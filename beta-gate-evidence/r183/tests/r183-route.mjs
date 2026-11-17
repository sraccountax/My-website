import {S, sql} from '../lib.mjs';
const o = new S(); await o.login('owner@gate.test', 'Gate!Owner#2026pw');
const ids = sql("SELECT c.id FROM companies c JOIN company_members m ON m.company_id=c.id JOIN users u ON u.id=m.user_id AND u.email='owner@gate.test'").split('\n').filter(Boolean);
const out = {};
for (const id of ids) { o.cid = id; const r = await o.call('professional-output/v5990/bank-transactions?start=2026-01-01&end=2026-12-31'); const k = r.s + ' ' + (r.b?.code || ''); (out[k] ||= []).push(id.slice(8, 16) + ':' + (r.b?.rowCount ?? '') + (r.s >= 400 ? ':' + String(r.b?.requestId || '') : '')); }
for (const [k, v] of Object.entries(out)) console.log(k, v.length, v.slice(0, 8).join(' '));

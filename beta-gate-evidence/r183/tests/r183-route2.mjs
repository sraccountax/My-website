import {S, sql} from '../lib.mjs';
const o = new S(); await o.login('owner@gate.test', 'Gate!Owner#2026pw');
o.cid = sql("SELECT id FROM companies WHERE name=\'" + (process.env.CO || 'E2E Workflow Ltd') + "\' ORDER BY created_at DESC LIMIT 1");
for (const q of ['start=2026-01-01&end=2026-10-02&dateBasis=transactionDate&bankAccountId=&currency=&q=', 'start=2026-01-01&end=2026-10-02&dateBasis=transactionDate', 'start=2026-01-01&end=2026-10-02']) {
  const r = await o.call('professional-output/v5990/bank-transactions?' + q);
  console.log(r.s, q, JSON.stringify(r.b).slice(0, 300));
}

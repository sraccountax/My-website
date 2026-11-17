import {S} from '../lib.mjs';
const o = new S(); await o.login('owner@gate.test', 'Gate!Owner#2026pw');
o.cid = process.env.CID || 'company_0235935639bea04e3f545a581815fb8f';
for (const q of ['start=2026-01-01&end=2026-10-02&dateBasis=transactionDate&bankAccountId=&currency=&q=', 'start=2026-01-01&end=2026-12-31']) {
  const r = await o.call('professional-output/v5990/bank-transactions?' + q);
  console.log(r.s, JSON.stringify(r.b).slice(0, 200));
}

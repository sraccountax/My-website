// R193: for every active company the owner can open (both editions), the customer and vendor balances (party trial
// balances) must add up to the receivables and payables control accounts in the GL, at the server's today.
import {S, sql} from '../lib.mjs'; import fs from 'fs';
const out = []; const today = sql('SELECT CURDATE()');
for (const ed of ['CA', 'IN']) {
  const o = new S(); await o.login('owner@gate.test', 'Gate!Owner#2026pw', ed === 'IN' ? {edition: 'IN'} : {});
  const cos = sql(`SELECT c.id FROM companies c JOIN company_members cm ON cm.company_id=c.id JOIN users u ON u.id=cm.user_id WHERE u.email='owner@gate.test' AND cm.status='active' AND c.active=1 AND c.archived_at IS NULL AND ${ed === 'IN' ? "c.edition='IN'" : "(c.edition IS NULL OR c.edition<>'IN')"}`).split('\n').filter(Boolean);
  for (const id of cos) {
    o.cid = id; const basis = sql(`SELECT accounting_basis FROM companies WHERE id='${id}'`);
    const glOf = code => Number(sql(`SELECT IFNULL(SUM(jl.debit_cents-jl.credit_cents),0) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id JOIN accounts a ON a.id=jl.account_id WHERE je.company_id='${id}' AND je.status='posted' AND je.entry_date<='${today}' AND a.code='${code}'`));
    const ar = await o.call(`portal/trial-balance?scope=receivables&start=2000-01-01&end=${today}`), ap = await o.call(`portal/trial-balance?scope=payables&start=2000-01-01&end=${today}`);
    if (ar.s !== 200 || ap.s !== 200) { out.push({ed, id, error: `${ar.s}/${ap.s}`}); continue; }
    const arT = (ar.b.rows || []).reduce((s, r) => s + (r.closingDebitCents || 0) - (r.closingCreditCents || 0), 0), apT = (ap.b.rows || []).reduce((s, r) => s + (r.closingCreditCents || 0) - (r.closingDebitCents || 0), 0);
    // The dated subledger reports (ageing and party balances) must equal the GL and raise no reconciliation exception.
    const rep = {}; for (const r of ['ar-ageing', 'ap-ageing', 'customer-balances', 'vendor-balances']) { const x = await o.call(`professional-output/v5990/${r}&asOf=${today}`); const t = x.b?.output?.totals?.balanceCents ?? JSON.stringify(x.b).match(/"totals":\{[^}]*"balanceCents":(-?\d+)/)?.[1]; const ex = (JSON.stringify(x.b).match(/"code":"subledger_[a-z_]+"/g) || []); rep[r] = {s: x.s, total: t === undefined ? null : Number(t), ex: [...new Set(ex)]}; }
    out.push({ed, id, basis, arParty: arT, arGl: glOf('1200'), apParty: apT, apGl: -glOf('2050'), rep});
  }
}
// Cash basis: invoices and bills are not posted to receivables and payables, so the GL control accounts stay at zero by design.
const repBad = x => ['ar-ageing', 'customer-balances'].some(r => x.rep[r].s !== 200 || x.rep[r].total !== x.arGl || x.rep[r].ex.length) || ['ap-ageing', 'vendor-balances'].some(r => x.rep[r].s !== 200 || x.rep[r].total !== x.apGl || x.rep[r].ex.length);
const bad = out.filter(x => x.error || (x.basis !== 'cash' && (x.arParty !== x.arGl || x.apParty !== x.apGl || repBad(x))));
fs.writeFileSync(process.argv[2] || '/srv/gate/ev/party-tieout.json', JSON.stringify({today, companies: out.length, mismatches: bad, all: out}, null, 1));
console.log('companies', out.length, 'cash basis (not compared)', out.filter(x => x.basis === 'cash').length, 'mismatches', bad.length); for (const b of bad) console.log(JSON.stringify(b));

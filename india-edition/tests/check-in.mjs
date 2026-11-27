// R193: the large India company's reports against the figures summed independently by seed-in.mjs.
import {S, rec, save} from '../lib.mjs'; import fs from 'fs';
const A = 'R193IN'; const o = new S(); await o.login('owner@gate.test', 'Gate!Owner#2026pw', {edition: 'IN'});
const exps = fs.readdirSync('/srv/gate/t/vol').filter(f => /^expected-company_.*\.json$/.test(f)).map(f => JSON.parse(fs.readFileSync('/srv/gate/t/vol/' + f))).filter(e => e.edition === 'IN' && /^Volume Deccan/.test(e.name));
const timed = async route => { const t = Date.now(); const r = await o.call(route); return {...r, ms: Date.now() - t}; };
const money = c => (c < 0 ? '-' : '') + '₹' + (Math.abs(c) / 100).toLocaleString('en-IN', {minimumFractionDigits: 2});
const MONTHS = ['04', '05', '06', '07', '08', '09'];
for (const e of exps) {
  o.cid = e.id; const g = e.gl, slow = [];
  const gl = await timed('portal/trial-balance?scope=general-ledger&start=2026-04-01&end=2026-09-30'); slow.push(['trial balance', gl.ms]);
  const got = {}; let dr = 0, cr = 0; for (const r of gl.b.rows || []) { const v = (r.closingDebitCents || 0) - (r.closingCreditCents || 0); if (v) got[r.code] = v; dr += r.closingDebitCents || 0; cr += r.closingCreditCents || 0; }
  const diff = Object.keys({...got, ...g}).filter(c => (got[c] || 0) !== (g[c] || 0)).map(c => `${c}: app ${money(got[c] || 0)} expected ${money(g[c] || 0)}`);
  rec('VIN-01', A, `Trial Balance after ${e.counts.invoices} GST invoices, ${e.counts.bills} purchase invoices, ${e.counts.creditNotes} credit notes, ${e.counts.customerPayments + e.counts.vendorPayments} payments, ${e.counts.tds} TDS entries, ${e.counts.payrollRuns} payroll runs and ${e.counts.journals} journals equals the independently summed figures for every account; debits equal credits`, gl.s === 200 && !diff.length && dr === cr ? 'PASS' : 'FAIL', diff.length ? diff.slice(0, 8).join('; ') : `${Object.keys(got).length} accounts, debits ${money(dr)} = credits ${money(cr)}`);
  const ar = await timed('portal/trial-balance?scope=receivables&start=2026-04-01&end=2026-09-30'), ap = await timed('portal/trial-balance?scope=payables&start=2026-04-01&end=2026-09-30'); slow.push(['receivables', ar.ms], ['payables', ap.ms]);
  const arT = (ar.b.rows || []).reduce((s, r) => s + (r.closingDebitCents || 0) - (r.closingCreditCents || 0), 0), apT = (ap.b.rows || []).reduce((s, r) => s + (r.closingCreditCents || 0) - (r.closingDebitCents || 0), 0);
  rec('VIN-02', A, `Customer and vendor balances (${(ar.b.rows || []).length} customers, ${(ap.b.rows || []).length} vendors) add up to the receivables and payables control accounts`, arT === (g[e.accounts.ar] || 0) && apT === -(g[e.accounts.ap] || 0) ? 'PASS' : 'FAIL', `customers ${money(arT)} vs ${e.accounts.ar} ${money(g[e.accounts.ar] || 0)}; vendors ${money(apT)} vs ${e.accounts.ap} ${money(-(g[e.accounts.ap] || 0))}`);
  const pl = await timed('reports/v5600/financial?kind=profit-loss&reportKey=profit_loss&start=2026-04-01&end=2026-09-30'); slow.push(['profit and loss', pl.ms]);
  const netExp = -Object.entries(g).filter(([c]) => c >= '4000').reduce((s, [, v]) => s + v, 0);
  const flat = JSON.stringify(pl.b); const m = flat.match(/"net(?:Income|Profit)[A-Za-z]*Cents":(-?\d+)/); const plNet = m ? +m[1] : null;
  rec('VIN-03', A, 'Profit and Loss net profit equals income less expenses summed independently', pl.s === 200 && plNet === netExp ? 'PASS' : 'FAIL', `app ${plNet === null ? 'not found' : money(plNet)} expected ${money(netExp)}`);
  // GST: each month's GST ledger agrees with the GL; GSTR-3B 3.1(a) for the half-year equals the output GST summed here.
  const out = {cgst: 0, sgst: 0, igst: 0}, gaps = []; let ledgerClose = {};
  for (const mm of MONTHS) {
    const p = `2026-${mm}`, r3 = await timed(`india/gst/gstr3b&period=${p}`), lg = await timed(`india/gst/ledger&period=${p}`); slow.push(['GSTR-3B ' + p, r3.ms], ['GST ledger ' + p, lg.ms]);
    const a = r3.b?.table31?.a || {}; out.cgst += a.cgstCents || 0; out.sgst += a.sgstCents || 0; out.igst += a.igstCents || 0;
    for (const row of lg.b?.rows || []) { if (row.differenceCents) gaps.push(`${p} ${row.side} ${row.head} ${money(row.differenceCents)}`); ledgerClose[row.accountCode] = row.closingCents; }
  }
  const want = {cgst: -(g['2401'] || 0), sgst: -(g['2402'] || 0), igst: -(g['2404'] || 0)};
  rec('VIN-04', A, 'GSTR-3B 3.1(a) output CGST, SGST and IGST for April to September equal the output GST summed from the invoices and credit notes', JSON.stringify(out) === JSON.stringify(want) ? 'PASS' : 'FAIL', `app ${JSON.stringify(out)} expected ${JSON.stringify(want)}`);
  const inWant = {1451: g['1451'] || 0, 1452: g['1452'] || 0, 1454: g['1454'] || 0};
  const inGot = {1451: ledgerClose['1451'], 1452: ledgerClose['1452'], 1454: ledgerClose['1454']};
  rec('VIN-05', A, 'The GST ledger agrees with the GL in every month (no differences), and its September closing input credit equals the input GST summed from the purchase invoices', !gaps.length && JSON.stringify(inGot) === JSON.stringify(inWant) ? 'PASS' : 'FAIL', `differences: ${gaps.slice(0, 4).join('; ') || 'none'}; input closing ${JSON.stringify(inGot)} expected ${JSON.stringify(inWant)}`);
  const reg = await timed('india/payroll/registers&from=2026-04-01&to=2026-09-30'); slow.push(['payroll registers', reg.ms]);
  const runs = (reg.b?.runs || []).filter(r => r.status === 'posted');
  const tot = runs.reduce((s, r) => ({gross: s.gross + r.grossCents, ded: s.ded + r.deductionsCents, er: s.er + r.employerCents, net: s.net + r.netCents}), {gross: 0, ded: 0, er: 0, net: 0});
  const expenseGl = Object.entries(g).filter(([c]) => c >= '6000' && c < '6100').reduce((s, [, v]) => s + v, 0);
  rec('VIN-06', A, `Payroll registers for ${runs.length} posted runs: gross less deductions equals net pay, and gross plus employer contributions equals the payroll expense in the GL`, runs.length === e.counts.payrollRuns && tot.gross - tot.ded === tot.net && tot.gross + tot.er === expenseGl ? 'PASS' : 'FAIL', `${JSON.stringify(tot)}; payroll expense GL ${money(expenseGl)}`);
  const over = slow.filter(([, ms]) => ms >= 10000);
  rec('VIN-07', A, 'Each of these reports answers in under 10 seconds on the large company', over.length ? 'FAIL' : 'PASS', slow.map(([n, ms]) => `${n} ${ms} ms`).join('; '));
}
if (!exps.length) rec('VIN-00', A, 'The large India company exists', 'FAIL', 'no expected-*.json for Volume Deccan');
save('vol-in.json');

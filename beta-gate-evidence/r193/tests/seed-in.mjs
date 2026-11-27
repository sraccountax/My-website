// R193: large synthetic India company for the audit. Every posting goes through the public API.
// Synthetic data only: GST rates, TDS sections and payroll rules are invented test values, not Indian law.
// Sales and purchases use whole-rupee prices, so CGST/SGST/IGST are exact; the expected GL is summed here from the
// generated amounts. The account each kind of amount lands in is taken from the first document of that kind,
// after checking that its amounts equal the formula.
// Usage: node seed-in.mjs "<name>" customers vendors invoices bills journals bankLines employees
import {S, sql, need} from '../lib.mjs'; import fs from 'fs';
const [,, NAME, NC, NV, NI, NB, NJ, NBANK, NE] = process.argv; const N = {c: +NC, v: +NV, i: +NI, b: +NB, j: +NJ, bank: +NBANK, e: +NE};
let seed = [...NAME].reduce((a, c) => a * 31 + c.charCodeAt(0) >>> 0, 11); const rnd = () => (seed = (seed * 1664525 + 1013904223) >>> 0) / 4294967296; const ri = (a, b) => a + Math.floor(rnd() * (b - a + 1));
const CH = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
const gstin = b => { let s = 0; for (let i = 0; i < 14; i++) { const p = CH.indexOf(b[i]) * (i % 2 === 0 ? 1 : 2); s += Math.floor(p / 36) + p % 36; } return b + CH[(36 - s % 36) % 36]; };
const L = n => String.fromCharCode(65 + n % 26);
const pan = (k, t) => L(k) + L(k * 7 + 3) + L(k * 3 + 11) + t + L(k * 5 + 1) + String(1000 + k % 9000).padStart(4, '0') + L(k * 13 + 2);
const tag = Date.now().toString(36);
const o = new S(); await o.login('owner@gate.test', 'Gate!Owner#2026pw', {edition: 'IN'});
const PAN = pan(ri(0, 9999), 'C');
const co = need('company', await o.call('companies', {method: 'POST', company: false, json: {name: NAME, legalName: NAME + ' Private Limited', constitution: 'private_limited', state: 'MH', pin: '400001', city: 'Mumbai', fiscalYearEnd: '03-31', booksStartDate: '2026-04-01', pan: PAN, gstin: gstin('27' + PAN + '1Z')}}), [201]);
const CID = co.company.id; o.cid = CID; console.log(NAME, CID);
const M = (r, j) => o.call('india/masters/' + r, {method: 'POST', json: j});
const Gst = (r, j) => j ? o.call('india/gst/' + r, {method: 'POST', json: j}) : o.call('india/gst/' + r);
const Pay = (r, j) => j ? o.call('india/payroll/' + r, {method: 'POST', json: j}) : o.call('india/payroll/' + r);
const T = (r, j) => j ? o.call('india/tds/' + r, {method: 'POST', json: j}) : o.call('india/tds/' + r);
const rule = async j => { const id = need('rule ' + j.code, await M('rule', {label: 'Synthetic ' + j.code, source: 'Synthetic volume data', effectiveFrom: '2026-04-01', ...j}), [201]).id; need('approve', await M('rule-approve', {id})); return id; };
const rec2 = async (recordType, data, extra = {}) => need(recordType, await M('record', {recordType, effectiveFrom: '2026-04-01', data, ...extra}), [201]).id;
const acct = c => sql(`SELECT id FROM accounts WHERE company_id='${CID}' AND code='${c}'`);
const BANK = sql(`SELECT id FROM bank_accounts WHERE company_id='${CID}' AND account_type='bank' ORDER BY created_at LIMIT 1`);
const BANKGL = sql(`SELECT ledger_account_id FROM bank_accounts WHERE id='${BANK}'`);
const BANKCODE = sql(`SELECT code FROM accounts WHERE id='${BANKGL}'`);
const jl = id => Object.fromEntries(sql(`SELECT a.code,SUM(jl.debit_cents-jl.credit_cents) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id JOIN accounts a ON a.id=jl.account_id WHERE je.company_id='${CID}' AND je.source_id='${id}' AND je.status='posted' GROUP BY a.code`).split('\n').filter(Boolean).map(l => { const [c, v] = l.split('\t'); return [c, +v]; }));
const exp = {}; const add = (c, v) => { if (v) exp[c] = (exp[c] || 0) + v; };
const day = (i, n) => new Date(Date.UTC(2026, 3, 1) + Math.floor(i * (182 / n)) * 86400000).toISOString().slice(0, 10);
const plus = (d, k) => new Date(Date.parse(d) + k * 86400000).toISOString().slice(0, 10); const cap = d => d > '2026-09-30' ? '2026-09-30' : d;
async function pool(items, w, fn) { let i = 0, err = null; await Promise.all(Array.from({length: w}, async () => { while (i < items.length && !err) { const k = i++; try { await fn(items[k], k); } catch (e) { err = e; } } })); if (err) throw err; }
const t0 = Date.now(); const log = (...a) => console.log(...a, Date.now() - t0, 'ms');

// GST codes from approved synthetic rules: 5, 12, 18 and 28 percent (intra: CGST+SGST, inter: IGST).
need('context', await Gst('context'));
const RATES = [5, 12, 18, 28]; const code = {};
for (const r of RATES) { const id = await rule({category: 'gst_rate', code: `VOL-GST${r}-${tag}`, ratePercent: String(r)}); need('codes', await Gst('codes', {ruleId: id}), [201]); }
{ const ctx = need('context', await Gst('context')); for (const r of RATES) { code[r] = {intra: ctx.codes.find(c => c.supply === 'intra' && c.ruleKey === `VOL-GST${r}-${tag}`).id, inter: ctx.codes.find(c => c.supply === 'inter' && c.ruleKey === `VOL-GST${r}-${tag}`).id}; } }
log('gst codes');

// Parties across states. Own state is MH.
const STATES = ['MH', 'MH', 'MH', 'KA', 'GJ', 'DL', 'TN', 'WB', 'UP', 'TG'];
const CITY = {MH: ['Pune', '411001'], KA: ['Bengaluru', '560001'], GJ: ['Surat', '395003'], DL: ['New Delhi', '110001'], TN: ['Chennai', '600001'], WB: ['Kolkata', '700001'], UP: ['Lucknow', '226001'], TG: ['Hyderabad', '500001']};
const KIND = ['Traders', 'Enterprises', 'Industries', 'Textiles', 'Pharma', 'Logistics', 'Foods', 'Infotech', 'Agencies', 'Steel'];
const cust = [], vend = [];
await pool(Array.from({length: N.c}, (_, k) => k), 8, async k => {
  const st = STATES[k % STATES.length], [city, pin] = CITY[st];
  const r = need('cust', await o.call('customers', {method: 'POST', json: {name: `${NAME.split(' ')[1]} Buyer ${String(k + 1).padStart(4, '0')} ${KIND[k % KIND.length]}`, email: '', phone: '', billingAddress: `${k + 1} Main Road`, country: 'India', province: st, city, postalCode: pin, indiaBuilding: `${k + 1}, Shanti Complex`, indiaStreet: 'Station Road', indiaDistrict: city}}), [200, 201]);
  cust[k] = {id: r.customer.id, st};
});
await pool(Array.from({length: N.v}, (_, k) => k), 8, async k => {
  const st = STATES[(k * 3) % STATES.length];
  const r = need('vend', await o.call('vendors', {method: 'POST', json: {name: `${NAME.split(' ')[1]} Supplier ${String(k + 1).padStart(3, '0')} ${KIND[(k + 3) % KIND.length]}`, email: '', address: `${k + 1} Industrial Estate`, defaultTermsDays: 30, currency: 'INR', country: 'India', province: st}}), [200, 201]);
  vend[k] = {id: r.vendor?.id || r.id, st};
});
log('parties', N.c, N.v);

const TMPL = (await o.call('invoice-templates')).b.templates?.[0]?.id;
const A = {ar: null, ap: null, rev: '4100', exp: ['6100', '6200', '6300', '6400', '6500']};
const learnt = {};
// The first document of each kind is checked against the formula; the accounts found there are used for the rest.
const learn = (key, got, want) => {
  const vals = Object.values(got).filter(v => v).sort((a, b) => a - b), wv = Object.values(want).sort((a, b) => a - b);
  if (JSON.stringify(vals) !== JSON.stringify(wv)) throw new Error(`${key}: posting ${JSON.stringify(got)} does not match the amounts ${JSON.stringify(want)}`);
  const map = {}; for (const [name, v] of Object.entries(want)) { const c = Object.keys(got).find(c => got[c] === v && !Object.values(map).includes(c)); map[name] = c; }
  learnt[key] = map;
};

// Sales invoices: 1-3 lines, mixed rates; intra-state for MH buyers, IGST for others.
const invs = [];
await pool(Array.from({length: N.i}, (_, k) => k), 6, async k => {
  const c = cust[k % N.c], intra = c.st === 'MH', date = day(k, N.i), n = ri(1, 3), lines = [];
  let net = 0; const tax = {cgst: 0, sgst: 0, igst: 0};
  let rate0 = 0; for (let j = 0; j < n; j++) { const q = ri(1, 20), p = 2 * ri(25, 12500), r = RATES[ri(0, 3)]; if (!j) rate0 = r; const amt = q * p * 100; net += amt; if (intra) { tax.cgst += amt * r / 200; tax.sgst += amt * r / 200; } else tax.igst += amt * r / 100;
    lines.push({description: ['Steel rods', 'Cotton fabric', 'Software licence', 'Packaging material', 'Consulting'][j % 5], quantity: q, unitPriceCents: p * 100, incomeAccountId: acct(A.rev), taxCodeId: code[r][intra ? 'intra' : 'inter']}); }
  const total = net + tax.cgst + tax.sgst + tax.igst;
  const r = need('inv ' + k, await o.call('invoices', {method: 'POST', json: {customerId: c.id, issueDate: date, dueDate: plus(date, 30), currency: 'INR', templateId: TMPL, issue: true, lines}}), [200, 201]);
  const inv = r.invoice || r; const key = intra ? 'sale-intra' : 'sale-inter';
  const want = intra ? {ar: total, rev: -net, cgst: -tax.cgst, sgst: -tax.sgst} : {ar: total, rev: -net, igst: -tax.igst};
  if (!learnt[key]) learn(key, jl(inv.id), want);
  for (const [nm, v] of Object.entries(want)) add(learnt[key][nm], v);
  invs[k] = {id: inv.id, total, date, c: k % N.c, rate0, net};
});
A.ar = learnt['sale-intra']?.ar || learnt['sale-inter']?.ar;
log('invoices', N.i);

// Purchase invoices with input GST.
const bills = [];
await pool(Array.from({length: N.b}, (_, k) => k), 6, async k => {
  const v = vend[k % N.v], intra = v.st === 'MH', date = day(k, N.b), r = RATES[k % 4], net = 2 * ri(250, 45000) * 100, e = A.exp[k % A.exp.length];
  const tax = intra ? {cgst: net * r / 200, sgst: net * r / 200} : {igst: net * r / 100}; const total = net + Object.values(tax).reduce((a, b) => a + b, 0);
  const res = need('bill ' + k, await o.call('bills', {method: 'POST', json: {vendorId: v.id, number: `PI-${String(k + 1).padStart(5, '0')}`, billDate: date, dueDate: plus(date, 30), currency: 'INR', taxEntryMode: 'exclusive', memo: 'Purchase', issue: true, lines: [{description: ['Raw material', 'Freight', 'Office rent', 'Repairs', 'Power'][k % 5], quantity: 1, unitPriceCents: net, accountId: acct(e), taxCodeId: code[r][intra ? 'intra' : 'inter']}]}}), [200, 201]);
  const b = res.bill || res; const key = 'buy-' + (intra ? 'intra' : 'inter') + '-' + e;
  const want = intra ? {exp: net, cgst: tax.cgst, sgst: tax.sgst, ap: -total} : {exp: net, igst: tax.igst, ap: -total};
  if (!learnt[key]) learn(key, jl(b.id), want);
  for (const [nm, val] of Object.entries(want)) add(learnt[key][nm], val);
  bills[k] = {id: b.id, total, date, v: k % N.v};
});
A.ap = Object.entries(learnt).find(([k]) => k.startsWith('buy-'))[1].ap;
log('bills', N.b);

// Customer credit notes on 2% of invoices (a quarter of the first line value, before tax, posted).
const cnInv = invs.filter((_, k) => k % 50 === 7); let cnCount = 0, cnFail = [];
for (const x of cnInv) {
  const r = await o.call('accounting-notes', {method: 'POST', json: {kind: 'customer_credit', sourceId: x.id, date: cap(plus(x.date, 5)), subtotalCents: 10000, taxCents: Math.round(10000 * (x.total - x.net) / x.net), memo: 'Goods returned', operationKey: `vol-in-cn-${CID.slice(-8)}-${cnCount}`, lines: [{description: 'Return', quantityMilli: 1000, foreignUnitPriceCents: 10000}]}});
  const id = r.b?.note?.id || r.b?.id;
  if (r.s >= 300 || !id) { cnFail.push(`${r.s} ${JSON.stringify(r.b).slice(0, 160)}`); continue; }
  const p = await o.call('accounting-notes', {method: 'PATCH', json: {noteId: id, action: 'post'}});
  if (p.s >= 300) { cnFail.push(`post ${p.s} ${JSON.stringify(p.b).slice(0, 160)}`); continue; }
  const g = jl(id); for (const [c, v] of Object.entries(g)) add(c, v); x.credit = -(g[A.ar] || 0); cnCount++;
}
log('credit notes', cnCount, cnFail.slice(0, 2));

// TDS on a fifth of the vendors: synthetic 1% section, recorded on bills over the single-invoice limit.
const R = rupees => Math.round(rupees * 100); const SEC = 'VOL194C-' + tag;
await rule({category: 'tds_section', code: SEC, ratePercent: '1', thresholdMinCents: String(R(30000)), thresholdMaxCents: String(R(100000))});
const tdsVend = new Set(); for (let k = 0; k < N.v; k += 5) { need('profile', await M('profile', {entity: 'vendor', entityId: vend[k].id, effectiveFrom: '2026-04-01', data: {pan: pan(k + 500, 'C'), tdsSection: SEC}}), [201]); tdsVend.add(k); }
let tdsCount = 0; const tdsFail = [];
for (const b of bills) { if (!tdsVend.has(b.v) || b.total < R(35000) || tdsCount >= 150) continue;
  const r = await T('record', {kind: 'tds', documentId: b.id});
  if (r.s >= 300) { tdsFail.push(`${r.s} ${r.b?.code || ''}`); continue; }
  const id = r.b?.entry?.journalEntryId || r.b?.journalEntryId; b.tds = true; tdsCount++;
}
// TDS postings are read back from the GL (the amount is the app's own TDS calculation).
const tdsGl = Object.fromEntries(sql(`SELECT a.code,SUM(jl.debit_cents-jl.credit_cents) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id JOIN accounts a ON a.id=jl.account_id WHERE je.company_id='${CID}' AND je.status='posted' AND je.source_type LIKE '%tds%' GROUP BY a.code`).split('\n').filter(Boolean).map(l => { const [c, v] = l.split('\t'); return [c, +v]; }));
for (const [c, v] of Object.entries(tdsGl)) add(c, v);
log('tds', tdsCount, tdsFail.slice(0, 3), JSON.stringify(tdsGl));

// Payments: 60% of invoices paid in full, 10% part-paid; 55% of bills without TDS paid in full.
const cp = []; invs.forEach(x => { const r = rnd(), due = x.total - (x.credit || 0); if (r < 0.6) cp.push([x, due]); else if (r < 0.7) cp.push([x, Math.floor(due * ri(20, 80) / 100)]); });
await pool(cp, 6, async ([x, amt], k) => { need('cpay', await o.call('payments', {method: 'POST', json: {type: 'customer', documentId: x.id, paymentDate: cap(plus(x.date, ri(3, 25))), reference: 'NEFT ' + (k + 1), foreignAmountCents: amt, paymentAccountId: BANKGL, operationKey: 'vol-in-cp-' + CID.slice(-8) + '-' + k}}), [200, 201]); add(BANKCODE, amt); add(A.ar, -amt); x.paid = amt; });
const vp = bills.filter(b => !b.tds && rnd() < 0.55);
await pool(vp, 6, async (x, k) => { need('vpay', await o.call('payments', {method: 'POST', json: {type: 'vendor', documentId: x.id, paymentDate: cap(plus(x.date, ri(5, 25))), reference: 'RTGS ' + (1000 + k), foreignAmountCents: x.total, paymentAccountId: BANKGL, operationKey: 'vol-in-vp-' + CID.slice(-8) + '-' + k}}), [200, 201]); add(BANKCODE, -x.total); add(A.ap, x.total); x.paid = x.total; });
log('payments', cp.length, vp.length);

// Journals: bank charges.
const FEES = acct('6800') ? '6800' : '6400';
await pool(Array.from({length: N.j}, (_, k) => k), 6, async k => { const amt = ri(50, 2500) * 100; need('jnl', await o.call('journals', {method: 'POST', json: {date: day(k, N.j), memo: 'Bank charges ' + (k + 1), lines: [{accountId: acct(FEES), debitCents: amt, creditCents: 0, description: 'Charges'}, {accountId: BANKGL, debitCents: 0, creditCents: amt, description: 'Charges'}]}}), [200, 201]); add(FEES, amt); add(BANKCODE, -amt); });
log('journals', N.j);

// Payroll: synthetic EPF/ESIC/PT rules, N.e employees, six monthly runs posted (Apr-Sep).
await rule({category: 'epf_rate', code: 'V-EPF-' + tag, ratePercent: '12', thresholdMaxCents: String(R(15000))});
await rule({category: 'esic_rate', code: 'V-ESIC-EE-' + tag, ratePercent: '0.75', thresholdMaxCents: String(R(21000))});
await rule({category: 'esic_rate', code: 'V-ESIC-ER-' + tag, ratePercent: '3.25', thresholdMaxCents: String(R(21000))});
await rule({category: 'pt_slab', code: 'V-PT1-' + tag, state: 'MH', amountCents: String(R(200)), thresholdMinCents: '0'});
const P = {};
P.basic = await rec2('pay_component', {name: 'Basic', kind: 'earning', calculation: 'fixed', taxable: true});
P.hra = await rec2('pay_component', {name: 'House Rent Allowance', kind: 'earning', calculation: 'percent', percent: '40', baseComponent: P.basic, taxable: true});
P.special = await rec2('pay_component', {name: 'Special Allowance', kind: 'earning', calculation: 'fixed', taxable: true});
P.epfEe = await rec2('pay_component', {name: 'EPF (employee)', kind: 'deduction', calculation: 'rule', rule: 'V-EPF-' + tag, baseComponent: P.basic});
P.epfEr = await rec2('pay_component', {name: 'EPF (employer)', kind: 'employer_contribution', calculation: 'rule', rule: 'V-EPF-' + tag, baseComponent: P.basic});
P.esicEe = await rec2('pay_component', {name: 'ESIC (employee)', kind: 'deduction', calculation: 'rule', rule: 'V-ESIC-EE-' + tag});
P.esicEr = await rec2('pay_component', {name: 'ESIC (employer)', kind: 'employer_contribution', calculation: 'rule', rule: 'V-ESIC-ER-' + tag});
P.pt = await rec2('pay_component', {name: 'Professional Tax', kind: 'deduction', calculation: 'rule', rule: 'V-PT1-' + tag});
const all = [P.basic, P.hra, P.special, P.epfEe, P.epfEr, P.esicEe, P.esicEr, P.pt];
const FIRST = ['Asha', 'Ravi', 'Meena', 'Arjun', 'Kavya', 'Imran', 'Neha', 'Suresh', 'Divya', 'Farhan'], LAST = ['Patil', 'Iyer', 'Shah', 'Khan', 'Reddy', 'Das', 'Gupta', 'Nair'];
for (let k = 0; k < N.e; k++) {
  const e = await rec2('employee', {employeeCode: `V${String(k + 1).padStart(3, '0')}`, name: `${FIRST[k % 10]} ${LAST[k % 8]} (synthetic)`, dateOfJoining: '2026-04-01', epfApplicable: true, esicApplicable: true, ptApplicable: true, ptState: 'MH'});
  const basic = ri(9, 60) * 1000, special = ri(1, 15) * 1000;
  need('structure', await Pay('structure', {employeeId: e, effectiveFrom: '2026-04-01', lines: all.map(c => ({componentId: c, ...(c === P.basic ? {amountCents: R(basic)} : c === P.special ? {amountCents: R(special)} : {})}))}), [200, 201]);
}
const runs = []; const payFail = [];
for (const m of ['04', '05', '06', '07', '08', '09']) {
  const last = new Date(Date.UTC(2026, +m, 0)).toISOString().slice(0, 10);
  const r = await Pay('run', {period: '2026-' + m, payDate: last, inputs: {}});
  if (r.s >= 300) { payFail.push(`run ${m} ${r.s} ${r.b?.code || JSON.stringify(r.b).slice(0, 120)}`); continue; }
  const p = await Pay('run-post', {id: r.b.run.id});
  if (p.s >= 300) { payFail.push(`post ${m} ${p.s} ${p.b?.code || ''}`); continue; }
  runs.push(r.b.run.id);
}
const payGl = Object.fromEntries(sql(`SELECT a.code,SUM(jl.debit_cents-jl.credit_cents) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id JOIN accounts a ON a.id=jl.account_id WHERE je.company_id='${CID}' AND je.status='posted' AND je.source_type='india_pay_run' GROUP BY a.code`).split('\n').filter(Boolean).map(l => { const [c, v] = l.split('\t'); return [c, +v]; }));
for (const [c, v] of Object.entries(payGl)) add(c, v);
log('payroll', N.e, 'employees', runs.length, 'runs', payFail);

// Bank statement imported and left for review (no GL effect until posted).
let csv = 'Date,Description,Amount\n'; for (let k = 0; k < N.bank; k++) { const v = (rnd() < 0.5 ? -1 : 1) * ri(100, 500000) / 100; csv += `${day(k, N.bank)},${['UPI transfer', 'NEFT credit', 'RTGS payment', 'Card POS', 'GST payment', 'ATM withdrawal'][k % 6]} ${k + 1},${v.toFixed(2)}\n`; }
const data = Buffer.from(csv); const st = need('up', await o.call('imports/upload-start', {method: 'POST', json: {filename: `vol-in-statement-${N.bank}.csv`, size: data.length}}));
for (let off = 0; off < data.length; off += 512 * 1024) need('chunk', await o.call('imports/upload-chunk', {method: 'POST', json: {uploadId: st.uploadId, offset: off, data: data.subarray(off, off + 512 * 1024).toString('base64')}}));
const pv = need('finish', await o.call('imports/upload-finish', {method: 'POST', json: {uploadId: st.uploadId, bankAccountId: BANK, exchangeRateMicros: 1000000, mode: 'preview'}}));
const sel = (pv.preview.rows || []).filter(x => x.selectionKey && x.eligible !== false && !x.duplicate && !x.requiresDuplicateReview).map(x => String(x.selectionKey));
const ap = await o.call('operations/statement-approve', {method: 'POST', json: {previewId: pv.preview.id, operationKey: 'vol-in-stmt-' + CID.slice(-10), selectedKeys: sel, reviewedDuplicateKeys: [], balanceGapAcknowledged: true}});
log('bank import', pv.preview.rows?.length, sel.length, ap.s);

const out = {name: NAME, id: CID, edition: 'IN', accounts: {ar: A.ar, ap: A.ap, bank: BANKCODE}, learnt, counts: {customers: N.c, vendors: N.v, invoices: N.i, bills: N.b, creditNotes: cnCount, tds: tdsCount, customerPayments: cp.length, vendorPayments: vp.length, journals: N.j, employees: N.e, payrollRuns: runs.length, bankLines: N.bank, bankImported: sel.length},
  problems: {creditNotes: cnFail.slice(0, 5), tds: tdsFail.slice(0, 5), payroll: payFail}, gl: Object.fromEntries(Object.entries(exp).filter(([, v]) => v !== 0).sort())};
fs.writeFileSync(`/srv/gate/t/vol/expected-${CID}.json`, JSON.stringify(out, null, 1)); console.log(JSON.stringify(out).slice(0, 3000));

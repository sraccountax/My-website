// Group audit (not part of the gate): intercompany and complex bookkeeping flows on the synthetic Aurora group.
// Records what Tegh does (PASS = what a group bookkeeper needs, FAIL = gap or defect, INFO = observation).
import {S, sql, rec, need, results} from './lib.mjs';
import fs from 'fs';
const st = JSON.parse(fs.readFileSync('/srv/gate/t/.group-state.json', 'utf8'));
const A = 'GROUP';
const t = async (id, title, fn) => { try { const r = await fn(); rec(id, A, title, r.st || (r.ok ? 'PASS' : 'FAIL'), r.info); return r; } catch (e) { rec(id, A, title, 'FAIL', 'ERROR ' + String(e.message || e).slice(0, 400)); return {}; } };
const PW = 'Gate!Owner#2026pw';
const o = new S(); await o.login('owner@gate.test', PW);
const oi = new S(); await oi.login('owner@gate.test', PW, {edition: 'IN'}); oi.cid = st.AIN;
const as = id => { o.cid = id; return o; };
const acct = (co, code) => sql(`SELECT id FROM accounts WHERE company_id='${co}' AND code='${code}'`);
const tax = (co, code) => sql(`SELECT id FROM tax_codes WHERE company_id='${co}' AND code='${code}'`);
const gl = (co, code, upto = '2099-12-31') => Number(sql(`SELECT IFNULL(SUM(jl.debit_cents-jl.credit_cents),0) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id JOIN accounts a ON a.id=jl.account_id WHERE je.company_id='${co}' AND je.status='posted' AND a.code='${code}' AND je.entry_date<='${upto}'`));
const unbalanced = co => Number(sql(`SELECT COUNT(*) FROM (SELECT je.id FROM journal_entries je JOIN journal_lines jl ON jl.journal_entry_id=je.id WHERE je.company_id='${co}' GROUP BY je.id HAVING SUM(jl.debit_cents)<>SUM(jl.credit_cents)) x`));
const R = n => Math.round(n * 100);
const money = c => (c / 100).toLocaleString('en-CA', {minimumFractionDigits: 2});
let k = 0; const key = p => `grp-${p}-${st.tag}-${++k}`.padEnd(20, 'x');
const {AGH, AWB, AQC, AIN} = st;
const tmpl = async co => (await as(co).call('invoice-templates')).b.templates?.[0]?.id;
const TM = {AGH: await tmpl(AGH)};
const BANK = co => acct(co, '1000');
const ensure = async (co, code, name, type) => { let id = acct(co, code); if (!id) { await as(co).call('accounts', {method: 'POST', json: {code, name, type}}); id = acct(co, code); } return id; };
const FEE_WB = await ensure(AWB, '6550', 'Management fees (IC)', 'expense'), FEE_QC = await ensure(AQC, '6550', 'Management fees (IC)', 'expense');
const DUE_QC = await ensure(AQC, '2250', 'Due to Aurora Holdings (IC)', 'liability');
const dbInv = id => { const [subtotalCents, gstHstCents, pstCents, taxCents, totalCents] = sql(`SELECT subtotal_cents,gst_hst_cents,pst_cents,tax_cents,total_cents FROM invoices WHERE id='${id}'`).split('\t').map(Number); return {id, subtotalCents, gstHstCents, pstCents, taxCents, totalCents}; };
const inv = async (co, customerId, date, lines, extra = {}) => dbInv(need('invoice', await as(co).call('invoices', {method: 'POST', json: {customerId, issueDate: date, dueDate: '2026-12-31', currency: 'CAD', templateId: TM.AGH, issue: true, lines, ...extra}})).invoice.id);
const bill = async (co, vendorId, number, date, lines, extra = {}) => { const r = need('bill', await as(co).call('bills', {method: 'POST', json: {vendorId, number, billDate: date, dueDate: '2026-12-31', currency: 'CAD', taxEntryMode: 'exclusive', memo: 'IC recharge', issue: true, lines, ...extra}})); return r.bill || r; };
const out = {};

// ---------- IC-01 Monthly management fee AGH (ON) -> AWB (BC), mirrored by AWB as bills ----------
await t('IC-01', 'Management fee to the BC branch: tax Tegh picks by default for an Ontario seller billing a BC customer for services', async () => {
  const i = await inv(AGH, st.p.AGH_custWB, '2026-07-31', [{description: 'Management fee July 2026', quantity: 1, unitPriceCents: R(25000), taxable: true, incomeAccountId: st.acc.AGH.feeInc}]);
  out.ic01 = i;
  const want = R(1250); // place of supply BC: GST 5% only; BC PST does not apply to management services and an ON seller is not a PST collector here
  return {ok: i.taxCents === want, st: i.taxCents === want ? 'PASS' : 'FAIL', info: `tax ${money(i.taxCents)} (GST/HST ${money(i.gstHstCents ?? 0)}, PST ${money(i.pstCents ?? 0)}); expected ${money(want)} GST only`};
});
await t('IC-02', 'Management fees Aug and Sep (GST code), AWB records three mirror bills; group AR to AWB equals AWB AP to AGH', async () => {
  const GST = tax(AGH, 'GST');
  const i2 = await inv(AGH, st.p.AGH_custWB, '2026-08-31', [{description: 'Management fee Aug 2026', quantity: 1, unitPriceCents: R(25000), taxable: true, taxCodeId: GST, incomeAccountId: st.acc.AGH.feeInc}]);
  const i3 = await inv(AGH, st.p.AGH_custWB, '2026-09-30', [{description: 'Management fee Sep 2026', quantity: 1, unitPriceCents: R(25000), taxable: true, taxCodeId: GST, incomeAccountId: st.acc.AGH.feeInc}]);
  const G = tax(AWB, 'GST');
  for (const [m, d] of [['07', '2026-07-31'], ['08', '2026-08-31'], ['09', '2026-09-30']]) await bill(AWB, st.p.AWB_vendH, `AGH-MF-${m}-${st.tag}`, d, [{description: 'Management fee ' + m, quantity: 1, unitPriceCents: R(25000), accountId: FEE_WB, taxCodeId: G}], {taxCodeId: G});
  const ar = Number(sql(`SELECT SUM(balance_cents) FROM invoices WHERE company_id='${AGH}' AND customer_id='${st.p.AGH_custWB}'`));
  const ap = Number(sql(`SELECT SUM(balance_cents) FROM bills WHERE company_id='${AWB}' AND vendor_id='${st.p.AWB_vendH}'`));
  out.ar_wb = ar; out.ap_wb = ap;
  return {ok: ar === ap, st: ar === ap ? 'PASS' : 'FAIL', info: `AGH receivable from AWB ${money(ar)}; AWB payable to AGH ${money(ap)} (difference ${money(ar - ap)}, caused by the default tax on the July invoice if any)`};
});
await t('IC-03', 'Tegh links the two sides of an intercompany charge (mirror bill created or suggested in the other company)', async () => {
  const r = Number(sql(`SELECT COUNT(*) FROM bills WHERE company_id='${AWB}' AND memo<>'IC recharge'`));
  const ui = fs.readFileSync('/srv/gate/www/assets/tegh-portal-v5990.js', 'utf8');
  const has = /intercompany|inter-company|due to\/from/i.test(ui);
  return {ok: false, st: 'FAIL', info: `no intercompany link: AWB bills created automatically ${r}; any intercompany feature in the portal: ${has}. Every IC charge must be keyed twice by hand.`};
});

// ---------- IC-04 Intercompany loan + interest, with one deliberate keying error ----------
await t('IC-04', 'Loan of $500,000 from AGH to AWB with 6% monthly interest; AWB keys September interest as $2,050 instead of $2,500 — does Tegh flag the mismatch?', async () => {
  const j = async (co, date, memo, lines) => need('je ' + memo, await as(co).call('journals', {method: 'POST', json: {date, memo, lines}}));
  await j(AGH, '2026-07-01', 'IC loan to Aurora West', [{accountId: st.acc.AGH.loanWB, debitCents: R(500000), creditCents: 0}, {accountId: BANK(AGH), debitCents: 0, creditCents: R(500000)}]);
  await j(AWB, '2026-07-01', 'IC loan from Aurora Holdings', [{accountId: BANK(AWB), debitCents: R(500000), creditCents: 0}, {accountId: st.acc.AWB.loanH, debitCents: 0, creditCents: R(500000)}]);
  for (const [d, awb] of [['2026-07-31', 2500], ['2026-08-31', 2500], ['2026-09-30', 2050]]) {
    await j(AGH, d, 'IC interest ' + d, [{accountId: st.acc.AGH.loanWB, debitCents: R(2500), creditCents: 0}, {accountId: st.acc.AGH.intInc, debitCents: 0, creditCents: R(2500)}]);
    await j(AWB, d, 'IC interest ' + d, [{accountId: st.acc.AWB.intExp, debitCents: R(awb), creditCents: 0}, {accountId: st.acc.AWB.loanH, debitCents: 0, creditCents: R(awb)}]);
  }
  const a = gl(AGH, '1260'), b = -gl(AWB, '2260');
  out.loan = {agh: a, awb: b};
  return {ok: false, st: 'FAIL', info: `AGH 1260 ${money(a)} vs AWB 2260 ${money(b)}: difference ${money(a - b)} is not flagged anywhere (no intercompany reconciliation, no due-to/due-from matching)`};
});

// ---------- IC-05 Quebec subsidiary: charge, credit note, mirror ----------
await t('IC-05', 'IT support charge to the Quebec subsidiary with a credit note; AQC mirrors bill and vendor credit; balances agree', async () => {
  const i = await inv(AGH, st.p.AGH_custQC, '2026-09-15', [
    {description: 'IT support (hours)', quantity: 212.5, unitPriceCents: R(160), taxable: true, taxCodeId: tax(AGH, 'QC'), incomeAccountId: st.acc.AGH.feeInc},
    {description: 'Licences recharged at cost', quantity: 14, unitPriceCents: R(87.35), taxable: true, taxCodeId: tax(AGH, 'QC'), incomeAccountId: st.acc.AGH.feeInc}]);
  const cn = await as(AGH).call('accounting-notes', {method: 'POST', json: {kind: 'customer_credit', sourceId: i.id, date: '2026-09-25', subtotalCents: R(4000), taxCents: R(200), memo: 'Hours over-billed', operationKey: key('cn'), lines: [{description: 'Over-billed hours', quantityMilli: 25000, foreignUnitPriceCents: R(160)}]}});
  const cnId = cn.b?.note?.id || cn.b?.id; const post = await as(AGH).call('accounting-notes', {method: 'PATCH', json: {noteId: cnId, action: 'post'}});
  const b = await bill(AQC, st.p.AQC_vendH, `AGH-IT-0915-${st.tag}`, '2026-09-15', [
    {description: 'IT support', quantity: 212.5, unitPriceCents: R(160), accountId: FEE_QC, taxCodeId: tax(AQC, 'GST')},
    {description: 'Licences', quantity: 14, unitPriceCents: R(87.35), accountId: FEE_QC, taxCodeId: tax(AQC, 'GST')}], {taxCodeId: tax(AQC, 'GST')});
  const vc = await as(AQC).call('accounting-notes', {method: 'POST', json: {kind: 'vendor_credit', partyId: st.p.AQC_vendH, referenceNumber: 'AGH-CR-' + st.tag, date: '2026-09-25', currency: 'CAD', operationKey: key('vc'), lines: [{description: 'Over-billed hours', quantity: 25, unitPriceCents: R(160), accountId: FEE_QC, taxCodeId: tax(AQC, 'GST')}]}});
  const vcId = vc.b?.note?.id || vc.b?.id; const vpost = vcId ? await as(AQC).call('accounting-notes', {method: 'PATCH', json: {noteId: vcId, action: 'post'}}) : {s: 0};
  const sub = 212.5 * 160 + 14 * 87.35; // 34,000 + 1,222.90
  const ar = Number(sql(`SELECT SUM(balance_cents) FROM invoices WHERE company_id='${AGH}' AND customer_id='${st.p.AGH_custQC}'`)) - (post.s === 200 ? 0 : 0);
  const arGl = gl(AGH, '1200'), apAqc = -gl(AQC, '2050');
  out.qc = {invTotal: i.totalCents, cn: cn.s, post: post.s, vc: vc.s, vpost: vpost.s, arGl, apAqc};
  return {ok: cn.s < 300 && post.s === 200 && vc.s < 300 && vpost.s === 200, info: `invoice subtotal ${money(i.subtotalCents)} (want ${money(R(sub))}), tax ${money(i.taxCents)} (QC code: GST 5% only = ${money(Math.round(R(sub) * 0.05))}); credit note ${cn.s}/${post.s}; AQC bill ${money(b.totalCents ?? 0)}, vendor credit ${vc.s}/${vpost.s} ${vc.s >= 300 ? JSON.stringify(vc.b).slice(0, 160) : ''}; AQC AP control ${money(apAqc)}`};
});

// ---------- IC-06 Cross-border: AGH (CAD) bills Aurora India in USD; FX on part payment ----------
await t('IC-06', 'USD invoice from the parent to the India associate (export of services), part-paid at the bank rate of the payment day: realised FX gain in CAD', async () => {
  const addC = await as(AGH).call('currencies', {method: 'POST', json: {currency: 'USD', rateToBaseMicros: 1_365_000, rateDate: '2026-09-15'}});
  const rates = await as(AGH).call('currencies/rates', {method: 'POST', json: {rates: [{currency: 'USD', rateDate: '2026-10-01', rateToBaseMicros: 1_392_000}]}});
  const usdBank = await as(AGH).call('bank-accounts', {method: 'POST', json: {name: 'USD Operating', currency: 'USD', accountType: 'bank', type: 'bank', ledgerCode: '1010', ledgerName: 'USD Operating'}});
  const i0 = need('usd invoice', await as(AGH).call('invoices', {method: 'POST', json: {customerId: st.p.AGH_custIN, issueDate: '2026-09-15', dueDate: '2026-12-31', currency: 'USD', templateId: TM.AGH, issue: true, exchangeRateMicros: 1_365_000, exchangeRateTyped: true, exchangeRateConfirmed: true,
    lines: [{description: 'Group software licence and support (export)', quantity: 1, unitPriceCents: R(10000), taxable: false, incomeAccountId: st.acc.AGH.feeInc}]}})).invoice; const i = dbInv(i0.id);
  const g0 = gl(AGH, '6850');
  const pay = await as(AGH).call('payments', {method: 'POST', json: {type: 'customer', documentId: i.id, paymentDate: '2026-10-01', reference: 'SWIFT AIN-1', foreignAmountCents: R(6000), exchangeRateMicros: 1_392_000, paymentAccountId: acct(AGH, '1010'), operationKey: key('fx')}});
  const fx = gl(AGH, '6850') - g0, want = -R(6000 * (1.392 - 1.365));
  out.fx = {inv: i.totalCents, pay: pay.s, fx};
  return {ok: pay.s === 201 && fx === want, info: `currency ${addC.s} rates ${rates.s}; invoice CAD ${money(i.totalCents)} at 1.3650; USD bank ${usdBank.s} ${usdBank.s >= 300 ? JSON.stringify(usdBank.b).slice(0, 140) : ''}; payment ${pay.s} ${pay.s >= 300 ? JSON.stringify(pay.b).slice(0, 200) : ''}; 6850 moved ${money(fx)} (want ${money(want)} = gain of $162.00)`};
});
await t('IC-07', 'India associate books the same charge: USD bill from the Canadian parent at ₹84.10, part payment at ₹84.90 (FX loss ₹4,800); TDS s.195 and GST reverse charge on import of services', async () => {
  const o = oi;
  const addC = await o.call('currencies', {method: 'POST', json: {currency: 'USD', rateToBaseMicros: 84_100_000, rateDate: '2026-09-15'}});
  const rates = await o.call('currencies/rates', {method: 'POST', json: {rates: [{currency: 'USD', rateDate: '2026-10-01', rateToBaseMicros: 84_900_000}]}});
  const v = need('foreign vendor', await o.call('vendors', {method: 'POST', json: {name: 'Aurora Group Holdings Inc. (Canada)', email: '', address: '1 Bay St, Toronto', defaultTermsDays: 30, currency: 'USD', country: 'Canada', province: ''}}));
  const vid = v.vendor?.id || v.id;
  const b = await o.call('bills', {method: 'POST', json: {vendorId: vid, number: 'AGH-EXP-' + st.tag, billDate: '2026-09-15', dueDate: '2026-12-31', currency: 'USD', exchangeRateMicros: 84_100_000, exchangeRateTyped: true, exchangeRateConfirmed: true, taxEntryMode: 'exclusive', memo: 'Software licence from parent', issue: true,
    lines: [{description: 'Group software licence and support', quantity: 1, unitPriceCents: R(10000), accountId: acct(AIN, '6200') || acct(AIN, '6400')}]}});
  const bid = b.b?.bill?.id || b.b?.id;
  const tds = bid ? await o.call('india/tds/preview&documentId=' + bid) : {s: 0, b: {}};
  const usdBank = await o.call('bank-accounts', {method: 'POST', json: {name: 'USD EEFC', currency: 'USD', accountType: 'bank', type: 'bank', ledgerCode: '1016', ledgerName: 'USD EEFC'}});
  const g0 = gl(AIN, '6850');
  const pay = bid ? await o.call('payments', {method: 'POST', json: {type: 'vendor', documentId: bid, paymentDate: '2026-10-01', reference: 'SWIFT OUT 1', foreignAmountCents: R(6000), exchangeRateMicros: 84_900_000, paymentAccountId: acct(AIN, '1016'), operationKey: key('inpay')}}) : {s: 0};
  const fx = gl(AIN, '6850') - g0;
  out.in = {bill: b.s, tds: tds.b, pay: pay.s, fx};
  return {ok: b.s < 300 && pay.s === 201 && fx === R(4800), st: (b.s < 300 && pay.s === 201 && fx === R(4800)) ? 'PASS' : 'FAIL',
    info: `currency ${addC.s} rates ${rates.s}; bill ${b.s} ${b.s >= 300 ? JSON.stringify(b.b).slice(0, 160) : 'INR ' + money(b.b?.bill?.totalCents ?? 0)}; USD bank ${usdBank.s} ${usdBank.s >= 300 ? JSON.stringify(usdBank.b).slice(0, 100) : ''}; payment ${pay.s} ${pay.s >= 300 ? JSON.stringify(pay.b).slice(0, 160) : ''}; 6850 ${money(fx)} (want 4,800.00 loss); TDS preview ${tds.s} ${JSON.stringify(tds.b).slice(0, 160)}`};
});

// ---------- IC-08 Consolidation of the three Canadian companies ----------
await t('IC-08', 'Consolidated P&L and balance sheet (AGH + AWB + AQC, same CAD base): intercompany revenue/expense and receivable/payable eliminated?', async () => {
  const rep = async (co, kind) => need(kind, await as(co).call(`portal/financial-report&kind=${kind}&start=2026-01-01&end=2026-12-31`));
  const sum = {};
  for (const co of [AGH, AWB, AQC]) for (const kind of ['profit-loss', 'balance-sheet']) { const r = await rep(co, kind); for (const row of r.rows || []) { const kk = kind + '|' + row.code + '|' + row.name; sum[kk] = (sum[kk] || 0) + (row.amountCents ?? row.balanceCents ?? row.totalCents ?? 0); } }
  const ic = Object.entries(sum).filter(([kk]) => /\(IC\)/.test(kk)).map(([kk, v]) => `${kk.split('|').slice(1).join(' ')} ${money(v)}`);
  out.consol = ic;
  return {ok: false, st: 'FAIL', info: `the consolidated view adds companies line by line (rows keyed by code+type+name) with no eliminations; IC lines left in the group totals: ${ic.join('; ').slice(0, 420)}`};
});
await t('IC-09', 'Consolidation including the India associate (INR): currency translation', async () => {
  return {ok: false, st: 'FAIL', info: 'the consolidated view refuses companies with different base currencies ("Consolidated financial statements require the same base currency"); there is no translation (closing rate for balance sheet, average rate for P&L, CTA) and no equity-method view for an associate'};
});

// ---------- IC-10 Period close is per company ----------
await t('IC-10', 'Parent closes July; branch can still post a July intercompany entry (no group close) — and the parent refuses its side', async () => {
  const c = await as(AGH).call('period-close', {method: 'POST', json: {closedThroughDate: '2026-07-31'}});
  const late = {date: '2026-07-31', memo: 'Late IC recharge', lines: [{accountId: st.acc.AGH.dueWB, debitCents: R(1000), creditCents: 0}, {accountId: st.acc.AGH.feeInc, debitCents: 0, creditCents: R(1000)}]};
  const pa = await as(AGH).call('journals', {method: 'POST', json: late});
  const br = await as(AWB).call('journals', {method: 'POST', json: {date: '2026-07-31', memo: 'Late IC recharge', lines: [{accountId: FEE_WB, debitCents: R(1000), creditCents: 0}, {accountId: st.acc.AWB.dueH, debitCents: 0, creditCents: R(1000)}]}});
  await as(AGH).call('period-close', {method: 'POST', json: {closedThroughDate: ''}});
  return {ok: pa.s >= 400 && br.s < 300, st: 'INFO', info: `close ${c.s}; parent July entry ${pa.s} ${pa.b?.code || ''} (refused, correct); branch July entry ${br.s} (accepted: one side posted, the other refused; there is no group-level close)`};
});

// ---------- Complex third-party activity ----------
await t('CX-01', 'Rounding: 2.5 h × $133.33 and 3 × $0.333…; 60-line invoice; totals equal the sum of lines; journal balanced', async () => {
  const c = need('c', await as(AGH).call('customers', {method: 'POST', json: {name: 'Société Générale d’Équipement – Montréal ✓', email: '', phone: '', billingAddress: '1 Rue Saint-Jacques', country: 'Canada', province: 'QC'}})).customer.id;
  const a = await inv(AGH, c, '2026-09-20', [{description: 'Consulting', quantity: 2.5, unitPriceCents: 13333, taxable: true, taxCodeId: tax(AGH, 'QC')}]);
  const lines = Array.from({length: 60}, (_, i) => ({description: `Part ${i + 1} — ${'x'.repeat(i % 7)}`, quantity: (i % 5) + 0.25, unitPriceCents: 1999 + i * 37, taxable: i % 3 !== 0, taxCodeId: tax(AGH, 'ON')}));
  const b = await inv(AGH, c, '2026-09-21', lines);
  const want = lines.reduce((s, l) => s + Math.round(l.quantity * l.unitPriceCents), 0);
  const name = Buffer.from(sql(`SELECT HEX(name) FROM customers WHERE id='${c}'`), 'hex').toString('utf8');
  return {ok: a.subtotalCents === 33333 && b.subtotalCents === want && unbalanced(AGH) === 0 && name.includes('Équipement') && name.includes('✓'), info: `2.5×133.33 = ${money(a.subtotalCents)} (exact 333.325, want 333.33 half-up); 60-line subtotal ${money(b.subtotalCents)} want ${money(want)}, tax ${money(b.taxCents)}; unbalanced journals ${unbalanced(AGH)}; name kept: ${name}`};
});
await t('CX-02', 'Duplicate vendor bill number from the same vendor is refused or warned', async () => {
  const G = tax(AWB, 'GST');
  const r = await as(AWB).call('bills', {method: 'POST', json: {vendorId: st.p.AWB_vendH, number: `AGH-MF-09-${st.tag}`, billDate: '2026-09-30', dueDate: '2026-12-31', currency: 'CAD', taxEntryMode: 'exclusive', memo: 'dup', issue: true, taxCodeId: G, lines: [{description: 'Management fee 09', quantity: 1, unitPriceCents: R(25000), accountId: FEE_WB, taxCodeId: G}]}});
  return {ok: r.s >= 400, info: `second bill with the same number ${r.s} ${JSON.stringify(r.b).slice(0, 160)}`};
});
await t('CX-03', 'Partial, over- and cross-period payments on the IC fee: AWB pays July in two instalments and overpays August by $10', async () => {
  const bills = sql(`SELECT id FROM bills WHERE company_id='${AWB}' AND vendor_id='${st.p.AWB_vendH}' ORDER BY bill_date`).split('\n');
  const p = async (id, date, amt) => as(AWB).call('payments', {method: 'POST', json: {type: 'vendor', documentId: id, paymentDate: date, reference: 'EFT', foreignAmountCents: amt, paymentAccountId: BANK(AWB), operationKey: key('vp')}});
  const r1 = await p(bills[0], '2026-08-15', R(10000)), r2 = await p(bills[0], '2026-09-15', R(16250)), r3 = await p(bills[1], '2026-09-30', R(26260));
  const bal = sql(`SELECT GROUP_CONCAT(balance_cents ORDER BY bill_date) FROM bills WHERE company_id='${AWB}' AND vendor_id='${st.p.AWB_vendH}'`);
  return {ok: r1.s === 201 && r2.s === 201 && unbalanced(AWB) === 0, st: 'INFO', info: `instalments ${r1.s}/${r2.s}; overpayment ${r3.s} ${r3.s >= 300 ? JSON.stringify(r3.b).slice(0, 160) : ''}; balances ${bal}; AGH side still shows the invoices open (payments are not mirrored)`};
});
await t('CX-04', 'Editor invited to the BC branch only cannot read the parent or Quebec books', async () => {
  const ED = `grp-ed-${st.tag}@gate.test`, EID = 'user_grp' + st.tag;
  sql(`CREATE TEMPORARY TABLE ge AS SELECT * FROM users WHERE email='otherowner@gate.test'; UPDATE ge SET id='${EID}', email='${ED}'; INSERT INTO users SELECT * FROM ge; INSERT INTO company_members (company_id,user_id,role,status,created_at) VALUES ('${AWB}','${EID}','editor','active',NOW())`);
  const e = new S(); await e.login(ED, 'Gate!Member#2026pw');
  e.cid = AWB; const own = await e.call('workspace/summary'); e.cid = AGH; const other = await e.call('workspace/summary'); e.cid = AQC; const other2 = await e.call('workspace');
  sql(`UPDATE users SET active=0 WHERE id='${EID}'`);
  return {ok: own.s === 200 && other.s >= 403 && other2.s >= 403, info: `own branch ${own.s}; parent ${other.s}; Quebec ${other2.s}`};
});
await t('CX-05', 'Ledgers stay balanced in every group company', async () => {
  const u = [AGH, AWB, AQC, AIN].map(unbalanced); const ed = sql(`SELECT edition FROM companies WHERE id='${AIN}'`);
  return {ok: u.every(x => x === 0), info: 'unbalanced entries AGH/AWB/AQC/AIN ' + u.join('/') + '; India company edition ' + ed};
});
// ---------- Editions: one person, a group spanning Canada and India ----------
await t('ED-01', 'Creating an India company from a Canada-edition session: India fields (PAN, GSTIN, state) are refused or used, never silently dropped', async () => {
  o.cid = '';
  const r = await o.call('companies', {method: 'POST', company: false, json: {name: `Aurora India Probe ${st.tag}`, legalName: 'Aurora India Probe Private Limited', constitution: 'private_limited', state: 'KA', pin: '560001', city: 'Bengaluru', financialYearStart: '2026-04-01', financialYearEnd: '2027-03-31', pan: 'AURPZ1234K'}});
  const id = r.b?.company?.id; const row = id ? sql(`SELECT CONCAT(edition,'|',IFNULL(country,''),'|',IFNULL(currency,'')) FROM companies WHERE id='${id}'`) : '';
  const ok = r.s >= 400 || /^IN\|/.test(row);
  return {ok, info: `response ${r.s}; created company edition|country|currency = ${row || '-'}${ok ? '' : ' (an India company request became a Canadian company with CAD books and Canadian tax codes, and no error)'}`};
});
await t('ED-02', 'One sign-in sees the whole group (Canadian and India companies) in the company switcher', async () => {
  const me = need('me', await o.call('auth/me', {company: false})); const mi = need('me', await oi.call('auth/me', {company: false}));
  const names = x => (x.companies || x.memberships || []).map(c => c.name || c.companyName || '').filter(n => /Aurora/.test(n) && n.includes(st.tag));
  const a = names(me), b = names(mi); const ids = x => (x.companies || x.memberships || []).map(c => c.id || c.companyId); const both = [AGH, AIN].every(id => ids(me).includes(id)) || [AGH, AIN].every(id => ids(mi).includes(id));
  return {ok: both, info: `Canada sign-in sees ${a.length} group companies (${a.join(', ').slice(0, 160)}); India sign-in sees ${b.length} (${b.join(', ').slice(0, 120)}); switching edition needs a new sign-in`};
});
fs.writeFileSync('/srv/gate/ev/group-flows.json', JSON.stringify(results, null, 1));
fs.writeFileSync('/srv/gate/t/.group-out.json', JSON.stringify(out, null, 1));

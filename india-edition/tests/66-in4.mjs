// IN-4: GST return working reports for India companies (GSTR-1, GSTR-3B, registers, GST ledger check, return periods,
// GSTR-2B import and reconciliation). Every expected figure below is worked out by hand from the synthetic documents
// this suite creates; none is read back from Tegh. Synthetic data only: rule codes, rates and limits are invented test values.
import {S, sql, rec, save, need} from './lib.mjs';
import {execFileSync} from 'node:child_process';
import {chromium} from '/opt/node22/lib/node_modules/playwright/index.mjs';

const A = 'IN4';
const t = async (id, title, fn) => { try { const r = await fn(); rec(id, A, title, r.ok ? 'PASS' : 'FAIL', r.info); return r.ok; } catch (e) { rec(id, A, title, 'FAIL', String(e.message || e).slice(0, 400)); return false; } };
const PW = 'Gate!Owner#2026pw', MPW = 'Gate!Member#2026pw';
const o = new S(); await o.login('owner@gate.test', PW);
const release = async () => (await o.call('site-admin', {company: false})).b.groups.release;
const setMode = async m => { const cur = await release(); return o.call('site-admin/save', {method: 'POST', company: false, json: {groups: {release: {value: {...cur.value, indiaEdition: m}, revision: cur.revision}}, reason: 'IN-4 gate ' + m}}); };
const startMode = (await release()).value.indiaEdition || 'off';
const tag = Date.now().toString(36);
const TAG = tag.toUpperCase();
const CH = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
const gstin = b => { let s = 0; for (let i = 0; i < 14; i++) { const p = CH.indexOf(b[i]) * (i % 2 === 0 ? 1 : 2); s += Math.floor(p / 36) + p % 36; } return b + CH[(36 - s % 36) % 36]; };
const n4 = String(1000 + (Date.now() % 9000)).slice(0, 4);
const PAN = 'AAACG' + n4 + 'K', VPAN = 'AAAFV' + n4 + 'Q', V2PAN = 'AAAFM' + n4 + 'R', CPAN = 'AAACB' + n4 + 'P', C2PAN = 'AAACK' + n4 + 'T';
const M = (s, r, j) => s.call('india/masters/' + r, {method: 'POST', json: j});
const Gst = (s, r, j) => j ? s.call('india/gst/' + r, {method: 'POST', json: j}) : s.call('india/gst/' + r);
const P = '2026-09';
const key = p => p + '-' + tag + '-' + Math.random().toString(36).slice(2, 8);

let IN, ED, INCO = '', CACO = '';
const ids = {}, G = {};
try {
  need('mode', await setMode('everyone'));
  IN = new S(); await IN.login('owner@gate.test', PW, {edition: 'IN'});
  const COMPANY_GSTIN = gstin('27' + PAN + '1Z');
  INCO = need('india company', await IN.call('companies', {method: 'POST', company: false, json: {name: 'IN4 Returns ' + tag, legalName: 'IN4 Returns Private Limited', constitution: 'private_limited', state: 'MH', pin: '400001', city: 'Mumbai', fiscalYearEnd: '03-31', booksStartDate: '2026-04-01', pan: PAN, gstin: COMPANY_GSTIN}}), [201]).company.id;
  IN.cid = INCO;
  CACO = sql(`SELECT c.id FROM companies c JOIN company_members cm ON cm.company_id=c.id JOIN users u ON u.id=cm.user_id WHERE u.email='owner@gate.test' AND (c.edition IS NULL OR c.edition<>'IN') AND c.active=1 AND cm.role='owner' LIMIT 1`);
  sql(`INSERT INTO company_members (company_id,user_id,role,status) SELECT '${INCO}',id,'editor','active' FROM users WHERE email='editor@gate.test'`);
  ED = new S(); await ED.login('editor@gate.test', MPW, {edition: 'IN'}); ED.cid = INCO;
  need('context', await Gst(IN, 'context'));
  const reg = async (entity, entityId, g) => need('registration', await M(IN, 'record', {recordType: 'gst_registration', entity, entityId, effectiveFrom: '2026-04-01', data: {gstin: g, registrationType: 'regular', status: 'active', validFrom: '2026-04-01'}}), [201]);
  const cust = async (name, extra) => need('customer', await IN.call('customers', {method: 'POST', json: {name: name + ' ' + tag, email: '', phone: '', billingAddress: '1 Road', ...extra}}), [200, 201]).customer.id;
  ids.cMHreg = await cust('IN4 Pune Registered', {country: 'India', province: 'MH'}); await reg('customer', ids.cMHreg, G.cMH = gstin('27' + CPAN + '1Z'));
  ids.cKAreg = await cust('IN4 Bengaluru Registered', {country: 'India', province: 'KA'}); await reg('customer', ids.cKAreg, G.cKA = gstin('29' + C2PAN + '1Z'));
  ids.cKA = await cust('IN4 Bengaluru Consumer', {country: 'India', province: 'KA'});
  ids.cMH = await cust('IN4 Pune Consumer', {country: 'India', province: 'MH'});
  ids.cUS = await cust('IN4 Export Buyer', {country: 'United States', province: 'NY'});
  const vend = async (name, extra) => { const r = need('vendor', await IN.call('vendors', {method: 'POST', json: {name: name + ' ' + tag, email: '', address: '', defaultTermsDays: 30, currency: 'INR', ...extra}}), [200, 201]); return r.vendor?.id || r.id; };
  ids.vGJ = await vend('IN4 Surat Supplier', {country: 'India', province: 'GJ'}); await reg('vendor', ids.vGJ, G.vGJ = gstin('24' + VPAN + '1Z'));
  ids.vMH = await vend('IN4 Mumbai Supplier', {country: 'India', province: 'MH'}); await reg('vendor', ids.vMH, G.vMH = gstin('27' + V2PAN + '1Z'));
  const acct = c => sql(`SELECT id FROM accounts WHERE company_id='${INCO}' AND code='${c}'`);
  const TMPL = (await IN.call('invoice-templates')).b.templates?.[0]?.id;
  // Rules (synthetic): 18% GST, and a B2C (large) limit of ₹1,00,000.
  const r18 = need('rule', await M(IN, 'rule', {category: 'gst_rate', code: 'GATE4-GST-' + tag, label: 'Synthetic 18', effectiveFrom: '2026-04-01', ratePercent: '18', source: 'Synthetic gate data'}), [201]).id;
  need('approve', await M(IN, 'rule-approve', {id: r18}));
  const lim = need('limit rule', await M(IN, 'rule', {category: 'gst_return_limit', code: 'B2CL', label: 'Synthetic B2CL limit', effectiveFrom: '2026-04-01', amountCents: '10000000', source: 'Synthetic gate data'}), [201]).id;
  need('approve limit', await M(IN, 'rule-approve', {id: lim}));
  need('codes', await Gst(IN, 'codes', {ruleId: r18}), [201]);
  const ctx = need('context', await Gst(IN, 'context'));
  const C18 = ctx.codes.find(c => c.supply === 'intra' && c.ruleKey === 'GATE4-GST-' + tag).id, I18 = ctx.codes.find(c => c.supply === 'inter' && c.ruleKey === 'GATE4-GST-' + tag).id;
  const invoice = async (customerId, cents, code, date = '2026-09-10', extra = {}) => need('invoice', await IN.call('invoices', {method: 'POST', json: {customerId, issueDate: date, dueDate: '2026-10-10', currency: 'INR', templateId: TMPL, issue: true, ...extra,
    lines: [{description: 'Synthetic supply', quantity: 1, unitPriceCents: cents, incomeAccountId: acct('4100'), ...(code ? {taxCodeId: code} : {})}]}}), [200, 201]).invoice;
  const bill = async (vendorId, number, cents, code) => { const r = need('bill', await IN.call('bills', {method: 'POST', json: {vendorId, number, billDate: '2026-09-12', dueDate: '2026-10-12', currency: 'INR', taxEntryMode: 'exclusive', memo: 'Synthetic purchase', issue: true,
    lines: [{description: 'Synthetic goods', quantity: 1, unitPriceCents: cents, accountId: acct('6400'), taxCodeId: code}]}}), [200, 201]); return r.b?.bill?.id || r.bill?.id || r.id; };

  // Documents of September 2026 (hand-worked figures in paise):
  ids.i1 = (await invoice(ids.cMHreg, 1000000, C18)).id;   // B2B intra: taxable 1,000,000; CGST 90,000; SGST 90,000
  ids.i2 = (await invoice(ids.cKAreg, 2000000, I18)).id;   // B2B inter: taxable 2,000,000; IGST 360,000
  ids.i3 = (await invoice(ids.cKA, 20000000, I18)).id;     // B2CL: value 23,600,000 > limit 10,000,000; IGST 3,600,000
  ids.i4 = (await invoice(ids.cKA, 500000, I18)).id;       // B2CS inter, place 29: IGST 90,000
  ids.i5 = (await invoice(ids.cMH, 100000, C18)).id;       // B2CS intra, place 27: CGST 9,000; SGST 9,000
  ids.i6 = (await invoice(ids.cUS, 5000000, I18)).id;      // Export with payment (WPAY): IGST 900,000
  ids.i7 = (await invoice(ids.cMH, 300000, null)).id;      // No GST code: exempt, intra-state, unregistered: 300,000
  ids.i8 = (await invoice(ids.cMHreg, 400000, C18)).id;    // voided below: counted as cancelled, no figures
  need('void', await IN.call('voids/apply', {method: 'POST', json: {entityType: 'invoice', entityId: ids.i8, reason: 'Issued in error in this IN-4 test', confirmed: true, operationKey: key('v')}}));
  // Credit note on i1 (registered): taxable 100,000; CGST 9,000; SGST 9,000 (reduces outward tax).
  const cn = need('credit note', await IN.call('accounting-notes', {method: 'POST', json: {kind: 'customer_credit', sourceId: ids.i1, date: '2026-09-20', subtotalCents: 100000, taxCents: 18000, memo: 'Return', operationKey: key('cn'), lines: [{description: 'Return', quantityMilli: 1000, foreignUnitPriceCents: 100000}]}}), [200, 201]);
  ids.cn = cn.note?.id || cn.id;
  need('post note', await IN.call('accounting-notes', {method: 'PATCH', json: {noteId: ids.cn, action: 'post'}}));
  ids.bGJno = '00GJ/71/' + tag; ids.bMHno = 'MH-55-' + tag;
  ids.bGJ = await bill(ids.vGJ, ids.bGJno, 3000000, I18);  // inward inter: IGST 540,000 credit
  ids.bMH = await bill(ids.vMH, ids.bMHno, 1000000, C18);  // inward intra: CGST 90,000; SGST 90,000 credit

  await t('IN4-01', 'Canada is unaffected: every return route answers 404 for a Canadian company, and no return rows are created', async () => {
    const ca = new S(); await ca.login('owner@gate.test', PW); ca.cid = CACO;
    const r = [await Gst(ca, 'gstr1&period=' + P), await Gst(ca, 'gstr3b&period=' + P), await Gst(ca, 'register&period=' + P), await Gst(ca, 'ledger&period=' + P), await Gst(ca, 'periods&fy=2026-27'), await Gst(ca, 'gstr2b&period=' + P), await Gst(ca, 'gstr2b', {period: P, content: '{}'}), await Gst(ca, 'periods', {returnType: 'GSTR1', period: P, action: 'prepare'})];
    const exists = sql(`SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('india_gst_returns','india_gstr2b_imports')`);
    const rows = exists === '2' ? sql(`SELECT (SELECT COUNT(*) FROM india_gst_returns WHERE company_id='${CACO}')+(SELECT COUNT(*) FROM india_gstr2b_imports WHERE company_id='${CACO}')`) : '0';
    return {ok: r.every(x => x.s === 404 && x.b.code === 'india_only') && rows === '0', info: JSON.stringify({r: r.map(x => [x.s, x.b.code]), rows})};
  });

  let g1;
  await t('IN4-02', 'GSTR-1 sections: B2B (2 invoices, GSTIN and place), B2C large above the approved limit, export with payment, B2C small by place and rate, credit note in CDNR', async () => {
    g1 = need('gstr1', await Gst(IN, 'gstr1&period=' + P)); const s = g1.sections;
    const b2b = s.b2b.map(r => `${r.partyGstin}|${r.placeOfSupply}|${r.taxableCents}|${r.igstCents}|${r.cgstCents}|${r.sgstCents}`).sort();
    const want = [`${G.cMH}|27|1000000|0|90000|90000`, `${G.cKA}|29|2000000|360000|0|0`].sort();
    const b2cs = s.b2cs.map(r => `${r.placeOfSupply}|${r.rateMpct}|${r.taxableCents}|${r.igstCents}|${r.cgstCents}|${r.sgstCents}`).sort();
    const ok = JSON.stringify(b2b) === JSON.stringify(want)
      && s.b2cl.length === 1 && s.b2cl[0].taxableCents === 20000000 && s.b2cl[0].igstCents === 3600000 && s.b2cl[0].placeOfSupply === '29' && s.b2cl[0].valueCents === 23600000
      && s.exp.length === 1 && s.exp[0].type === 'WPAY' && s.exp[0].taxableCents === 5000000 && s.exp[0].igstCents === 900000
      && JSON.stringify(b2cs) === JSON.stringify(['27|18000|100000|0|9000|9000', '29|18000|500000|90000|0|0'])
      && s.cdnr.length === 1 && s.cdnr[0].noteType === 'C' && s.cdnr[0].taxableCents === -100000 && s.cdnr[0].cgstCents === -9000 && s.cdnr[0].sgstCents === -9000 && s.cdnr[0].partyGstin === G.cMH
      && s.cdnur.length === 0 && g1.b2clLimitCents === 10000000;
    return {ok, info: JSON.stringify({b2b, b2cl: s.b2cl.map(r => [r.taxableCents, r.igstCents, r.valueCents]), exp: s.exp.map(r => [r.type, r.taxableCents, r.igstCents]), b2cs, cdnr: s.cdnr.map(r => [r.noteType, r.taxableCents, r.cgstCents, r.invoiceNumber])})};
  });

  await t('IN4-03', 'GSTR-1 nil/exempt, HSN summary and documents issued: the untaxed line is exempt (intra, unregistered); HSN total equals all coded lines net of the note; 8 invoices with 1 cancelled', async () => {
    const s = g1.sections;
    const nil = s.nil.map(r => `${r.supply}|${r.registered}|${r.exemptCents}|${r.nilCents}|${r.nonGstCents}`);
    const hsnTaxable = s.hsn.reduce((a, r) => a + r.taxableCents, 0), hsnIgst = s.hsn.reduce((a, r) => a + r.igstCents, 0);
    const inv = s.docs.find(d => /Invoices/.test(d.document)), cnd = s.docs.find(d => /Credit/.test(d.document));
    // Coded taxable: 1,000,000 + 2,000,000 + 20,000,000 + 500,000 + 100,000 + 5,000,000 - 100,000 = 28,500,000; IGST 360,000+3,600,000+90,000+900,000 = 4,950,000
    return {ok: JSON.stringify(nil) === JSON.stringify(['intra|false|300000|0|0']) && hsnTaxable === 28500000 && hsnIgst === 4950000 && inv?.total === 8 && inv?.cancelled === 1 && inv?.net === 7 && cnd?.total === 1
      && g1.warnings.some(w => /HSN/.test(w.message)),
      info: JSON.stringify({nil, hsnTaxable, hsnIgst, docs: s.docs, warnings: g1.warnings.map(w => w.message.slice(0, 60))})};
  });

  let g3;
  await t('IN4-04', 'GSTR-3B: 3.1(a) taxable and tax net of the credit note, 3.1(b) export, 3.1(c) exempt, 3.2 inter-state B2C by place, 4(A)(5) input credit, 4(C) net ITC', async () => {
    g3 = need('gstr3b', await Gst(IN, 'gstr3b&period=' + P));
    const a = g3.table31.a, b = g3.table31.b, c = g3.table31.c;
    // 3.1(a): 1,000,000+2,000,000+20,000,000+500,000+100,000-100,000 = 23,500,000; IGST 4,050,000; CGST 90,000+9,000-9,000 = 90,000; SGST 90,000
    const ok = a.taxableCents === 23500000 && a.igstCents === 4050000 && a.cgstCents === 90000 && a.sgstCents === 90000
      && b.taxableCents === 5000000 && b.igstCents === 900000 && c.taxableCents === 300000
      && g3.table32.length === 1 && g3.table32[0].placeOfSupply === '29' && g3.table32[0].taxableCents === 20500000 && g3.table32[0].igstCents === 3690000
      && g3.table4.A5.igstCents === 540000 && g3.table4.A5.cgstCents === 90000 && g3.table4.A5.sgstCents === 90000 && g3.table4.C.igstCents === 540000 && g3.table4.D2.igstCents === 0;
    return {ok, info: JSON.stringify({a, b, c, t32: g3.table32, A5: g3.table4.A5, C: g3.table4.C})};
  });

  await t('IN4-05', 'GSTR-3B payment: IGST credit is used against IGST first; CGST and SGST credit pay their own heads; cash payable IGST 4,410,000, CGST 0, SGST 0', async () => {
    const p = Object.fromEntries(g3.payment.map(r => [r.head, r]));
    // Liability IGST 4,050,000+900,000 = 4,950,000; credit 540,000 → cash 4,410,000. CGST 90,000 paid by CGST credit 90,000; SGST likewise.
    const ok = p.IGST.liabilityCents === 4950000 && p.IGST.paidByIgstCents === 540000 && p.IGST.cashCents === 4410000
      && p.CGST.liabilityCents === 90000 && p.CGST.paidByCgstCents === 90000 && p.CGST.cashCents === 0
      && p['SGST/UTGST'].paidBySgstCents === 90000 && p['SGST/UTGST'].cashCents === 0;
    return {ok, info: JSON.stringify(g3.payment)};
  });

  await t('IN4-06', 'Set-off order (unit check of the computation, hand-worked): IGST credit beyond IGST tax pays CGST then SGST; CGST credit pays IGST but never SGST; SGST credit never pays CGST', async () => {
    const php = `require '/srv/gate/www/api/india_gst_in3.php'; require '/srv/gate/www/api/india_gst_returns_in4.php';
      $z=['CGST'=>0,'SGST'=>0,'UTGST'=>0,'IGST'=>0,'CESS'=>0];
      echo json_encode([in4_setoff(['IGST'=>100,'CGST'=>300,'SGST'=>300]+$z, ['IGST'=>500,'CGST'=>0,'SGST'=>0]+$z), in4_setoff(['IGST'=>200,'CGST'=>0,'SGST'=>400]+$z, ['IGST'=>0,'CGST'=>500,'SGST'=>100]+$z)]);`;
    const out = JSON.parse(execFileSync('php', ['-r', 'function fail($m){throw new Exception($m);} ' + php], {encoding: 'utf8'}));
    const m = rows => Object.fromEntries(rows.map(r => [r.head, r]));
    const [x, y] = out.map(m);
    // x: IGST credit 500 → IGST 100, CGST 300, SGST 100 → cash SGST 200.
    // y: CGST credit 500 → CGST 0, IGST 200 (left 300); SGST credit 100 → SGST 100 → cash SGST 300; CGST credit never pays SGST.
    const ok = x.IGST.paidByIgstCents === 100 && x.CGST.paidByIgstCents === 300 && x['SGST/UTGST'].paidByIgstCents === 100 && x['SGST/UTGST'].cashCents === 200
      && y.IGST.paidByCgstCents === 200 && y.IGST.cashCents === 0 && y['SGST/UTGST'].paidBySgstCents === 100 && y['SGST/UTGST'].paidByCgstCents === 0 && y['SGST/UTGST'].cashCents === 300 && y.CGST.creditLeftCents === 300;
    return {ok, info: JSON.stringify(out)};
  });

  await t('IN4-07', 'Registers: the purchase (ITC) register lists both vendor invoices with supplier GSTIN and tax by head; the sales register nets the credit note', async () => {
    const pr = need('purchase register', await Gst(IN, 'register&side=purchase&period=' + P)), sr = need('sales register', await Gst(IN, 'register&side=sales&period=' + P));
    const rows = pr.rows.map(r => `${r.partyGstin}|${r.supply}|${r.taxableCents}|${r.igstCents}|${r.cgstCents}|${r.sgstCents}`).sort();
    const ok = JSON.stringify(rows) === JSON.stringify([`${G.vGJ}|inter|3000000|540000|0|0`, `${G.vMH}|intra|1000000|0|90000|90000`].sort())
      && sr.totals.taxableCents === 28500000 && sr.totals.igstCents === 4950000 && sr.totals.cgstCents === 90000 && sr.totals.sgstCents === 90000;
    return {ok, info: JSON.stringify({rows, sales: sr.totals})};
  });

  await t('IN4-08', 'GST ledger check: for every GST account the movement posted by documents equals the report figure (output 2401/2402/2404, input 1451/1452/1454)', async () => {
    const l = need('ledger', await Gst(IN, 'ledger&period=' + P));
    const by = Object.fromEntries(l.rows.map(r => [r.accountCode, r]));
    const ok = l.ties && by['2401'].fromDocumentsCents === 90000 && by['2402'].fromDocumentsCents === 90000 && by['2404'].fromDocumentsCents === 4950000
      && by['1451'].fromDocumentsCents === 90000 && by['1454'].fromDocumentsCents === 540000 && l.rows.every(r => r.differenceCents === 0);
    return {ok, info: JSON.stringify(l.rows.map(r => [r.accountCode, r.fromDocumentsCents, r.reportCents, r.otherEntriesCents]))};
  });

  await t('IN4-09', 'Return periods: an Editor prepares GSTR-3B but cannot mark it filed; the Owner marks it filed with an ARN; a later September invoice flags it as changed; reopening needs a reason; every step is audited', async () => {
    const prep = await Gst(ED, 'periods', {returnType: 'GSTR3B', period: P, action: 'prepare'});
    const edFile = await Gst(ED, 'periods', {returnType: 'GSTR3B', period: P, action: 'filed', arn: 'AA2709260000001', filedOn: '2026-10-01'});
    const badArn = await Gst(IN, 'periods', {returnType: 'GSTR3B', period: P, action: 'filed', arn: 'x', filedOn: '2026-10-01'});
    const filed = await Gst(IN, 'periods', {returnType: 'GSTR3B', period: P, action: 'filed', arn: 'AA2709260000001', filedOn: '2026-10-01'});
    const before = need('periods', await Gst(IN, 'periods&fy=2026-27')).periods.find(r => r.period === P).GSTR3B;
    const rePrep = await Gst(IN, 'periods', {returnType: 'GSTR3B', period: P, action: 'prepare'});
    await invoice(ids.cMHreg, 50000, C18, '2026-09-25');
    const after = need('periods', await Gst(IN, 'periods&fy=2026-27')).periods.find(r => r.period === P).GSTR3B;
    const noReason = await Gst(IN, 'periods', {returnType: 'GSTR3B', period: P, action: 'reopen', reason: ''});
    const reopen = await Gst(IN, 'periods', {returnType: 'GSTR3B', period: P, action: 'reopen', reason: 'Late invoice found after filing'});
    const audit = sql(`SELECT GROUP_CONCAT(action ORDER BY created_at, action) FROM audit_log WHERE company_id='${INCO}' AND action LIKE 'india.gst_return%'`);
    return {ok: prep.s === 200 && edFile.s === 403 && badArn.s === 422 && filed.s === 200 && before.status === 'filed' && before.arn === 'AA2709260000001' && before.changedSince === false
      && rePrep.s === 409 && after.changedSince === true && noReason.s === 422 && reopen.s === 200 && /prepared/.test(audit) && /filed/.test(audit) && /reopened/.test(audit),
      info: JSON.stringify({prep: prep.s, edFile: edFile.s, badArn: badArn.b.code, filed: filed.s, before, rePrep: rePrep.b.code, after: after.changedSince, noReason: noReason.s, reopen: reopen.s, audit})};
  });

  const twoB = (docs, extra = {}) => JSON.stringify({chksum: 'synthetic', data: {gstin: COMPANY_GSTIN, rtnprd: '092026', version: '1.0', docdata: docs}, ...extra});
  await t('IN4-10', 'GSTR-2B import refuses bad files: not JSON, no docdata, a bad supplier GSTIN, a bad amount, another company\'s GSTIN, another period; an Editor may import', async () => {
    const r = [];
    r.push(await Gst(IN, 'gstr2b', {period: P, fileName: 'a.json', content: '<html>not json'}));
    r.push(await Gst(IN, 'gstr2b', {period: P, fileName: 'a.json', content: '{"data":{}}'}));
    r.push(await Gst(IN, 'gstr2b', {period: P, fileName: 'a.json', content: twoB({b2b: [{ctin: 'NOTAGSTIN', inv: [{inum: '1', dt: '12-09-2026', val: 1, items: [{txval: 1}]}]}]})}));
    r.push(await Gst(IN, 'gstr2b', {period: P, fileName: 'a.json', content: twoB({b2b: [{ctin: G.vGJ, inv: [{inum: '1', dt: '12-09-2026', val: '12abc', items: [{txval: 1}]}]}]})}));
    r.push(await Gst(IN, 'gstr2b', {period: P, fileName: 'a.json', content: JSON.stringify({data: {gstin: G.vMH, rtnprd: '092026', docdata: {b2b: []}}})}));
    r.push(await Gst(IN, 'gstr2b', {period: '2026-08', fileName: 'a.json', content: twoB({b2b: []})}));
    const codes = r.map(x => [x.s, x.b.code]);
    const want = [[422, 'india_2b_not_json'], [422, 'india_2b_shape'], [422, 'india_2b_gstin'], [422, 'india_2b_value'], [422, 'india_2b_gstin_other'], [422, 'india_2b_period']];
    const ed = await Gst(ED, 'gstr2b', {period: P, fileName: 'empty.json', content: twoB({b2b: []})});
    const rows = sql(`SELECT COUNT(*) FROM india_gstr2b_imports WHERE company_id='${INCO}'`);
    return {ok: JSON.stringify(codes) === JSON.stringify(want) && ed.s === 201 && rows === '1', info: JSON.stringify({codes, ed: ed.s, rows})};
  });

  await t('IN4-11', 'GSTR-2B reconciliation: the Surat invoice matches though written differently; the Mumbai invoice is a mismatch (supplier shows ₹50 more CGST); a supplier invoice not in the books is "only in 2B"', async () => {
    const content = twoB({b2b: [
      {ctin: G.vGJ, trdnm: 'Surat Supplier', inv: [{inum: 'gj 71 ' + TAG, dt: '12-09-2026', val: 3540000.0 / 100, pos: '27', rev: 'N', itcavl: 'Y', items: [{num: 1, rt: 18, txval: 30000, igst: 5400, cgst: 0, sgst: 0, cess: 0}]}]},
      {ctin: G.vMH, trdnm: 'Mumbai Supplier', inv: [{inum: ids.bMHno, dt: '12-09-2026', val: 11850, pos: '27', rev: 'N', itcavl: 'Y', items: [{num: 1, rt: 18, txval: 10000, igst: 0, cgst: 950, sgst: 900, cess: 0}]},
                                                   {inum: 'MISSING-' + TAG, dt: '15-09-2026', val: 1180, pos: '27', rev: 'N', itcavl: 'Y', items: [{num: 1, rt: 18, txval: 1000, igst: 0, cgst: 90, sgst: 90, cess: 0}]}]},
    ]});
    const imp = need('import', await Gst(IN, 'gstr2b', {period: P, fileName: 'gstr2b-sep.json', content}), [201]);
    const rc = need('reconcile', await Gst(IN, 'gstr2b&period=' + P));
    const mm = rc.rows.find(r => r.status === 'mismatch');
    const replaced = sql(`SELECT GROUP_CONCAT(status ORDER BY created_at, status) FROM india_gstr2b_imports WHERE company_id='${INCO}'`);
    return {ok: imp.documents === 3 && rc.counts.matched === 1 && rc.counts.mismatch === 1 && rc.counts.only2b === 1 && rc.counts.onlyBooks === 0 && mm?.differences?.cgstCents === 5000 && /active/.test(replaced) && /replaced/.test(replaced),
      info: JSON.stringify({imp, counts: rc.counts, mm: mm?.differences, replaced})};
  });

  // ---------------- Browser ----------------
  const browser = await chromium.launch({executablePath: '/opt/pw-browsers/chromium', args: ['--no-proxy-server', '--host-resolver-rules=MAP gate.test 127.0.0.1']});
  try {
    const login = async (p, edition) => {
      await p.goto('https://gate.test/app.html' + (edition ? '?edition=in' : '')); await p.waitForSelector('#sr-login-form', {timeout: 30000});
      if (edition) await p.selectOption('#sr-login-form [name=edition]', 'IN');
      await p.fill('#sr-login-form input[name=email]', 'owner@gate.test'); await p.fill('#sr-login-form input[name=password]', PW); await p.click('#sr-login-form button[type=submit]');
      await p.waitForFunction(() => window.TeghPortal && document.querySelector('.sidebar,.topbar'), null, {timeout: 40000});
      if (edition) { await p.evaluate(id => { localStorage.setItem('sr-accountax-company', id); localStorage.setItem('sr-accountax-companies', JSON.stringify([id])); }, INCO); await p.goto('https://gate.test/app.html'); await p.waitForFunction(() => window.TeghPortal && document.querySelector('.sidebar,.topbar'), null, {timeout: 40000}); }
      await p.waitForTimeout(1500);
    };
    const canadianTerms = text => text.match(/GST\/HST|\bHST\b|\bPST\b|\bCRA\b|GIFI|Canada|Canadian|\bCAD\b|\$/g) || [];
    await t('UI-01', 'GST Returns page (India): GSTR-1 tables show the B2B, B2C and export rows; GSTR-3B shows the payment table; the ledger check says the accounts agree; no Canadian wording', async () => {
      const ctx = await browser.newContext({viewport: {width: 1440, height: 900}, ignoreHTTPSErrors: true}); const p = await ctx.newPage(); const errs = [];
      p.on('pageerror', e => errs.push(e.message.slice(0, 200)));
      try {
        await login(p, true);
        await p.evaluate(() => TeghPortal.openGstReturns('gstr1')); await p.waitForSelector('[data-in4-period]', {timeout: 20000});
        await p.fill('[data-in4-period] [name=period]', '2026-09'); await p.click('[data-in4-period] button[type=submit]');
        await p.waitForSelector('[data-in4-table="b2b"] tbody tr', {timeout: 20000});
        const b2b = await p.locator('[data-in4-table="b2b"] tbody tr').count(), b2cs = await p.locator('[data-in4-table="b2cs"] tbody tr').count(), exp = await p.locator('[data-in4-table="exp"] tbody tr').count();
        const t1 = await p.evaluate(() => document.querySelector('.in4-returns')?.innerText || '');
        await p.screenshot({path: '/srv/gate/ev/shots/in4-gstr1-1440.png'});
        const dl = p.waitForEvent('download', {timeout: 10000}); await p.click('[data-in4-csv="b2b"]'); const file = await (await dl).path(); const csv = (await import('node:fs')).readFileSync(file, 'utf8');
        await p.click('[data-in4-tab="gstr3b"]'); await p.waitForSelector('[data-in4-table="pay"] tbody tr', {timeout: 20000});
        const pay = await p.locator('[data-in4-table="pay"] tbody tr').first().innerText();
        await p.screenshot({path: '/srv/gate/ev/shots/in4-gstr3b-1440.png', fullPage: true});
        await p.click('[data-in4-tab="ledger"]'); await p.waitForSelector('[data-in4-ties]', {timeout: 20000});
        const ties = await p.getAttribute('[data-in4-ties]', 'data-in4-ties');
        await p.click('[data-in4-tab="periods"]'); await p.waitForSelector('[data-in4-table="periods"] tbody tr', {timeout: 20000});
        const months = await p.locator('[data-in4-table="periods"] tbody tr').count();
        const sep = await p.locator('[data-in4-status="GSTR3B|2026-09"]').innerText();
        await p.screenshot({path: '/srv/gate/ev/shots/in4-periods-1440.png'});
        const all = t1 + pay + sep;
        // B2B: i1 and i2, plus the late invoice added in IN4-09.
        return {ok: b2b === 3 && b2cs === 2 && exp === 1 && /₹/.test(t1) && /Invoice,Date,Party,GSTIN/.test(csv.replace(/^\uFEFF/, '').replace(/"/g, '')) && /IGST/.test(pay) && /44,100\.00/.test(pay) && ties === '1' && months === 12 && /Prepared/.test(sep) && !canadianTerms(all).length && !errs.length,
          info: JSON.stringify({b2b, b2cs, exp, csv: csv.slice(0, 80), pay: pay.replace(/\s+/g, ' '), ties, months, sep: sep.replace(/\s+/g, ' ').slice(0, 80), canadian: canadianTerms(all), errs})};
      } finally { await ctx.close(); }
    });
    await t('UI-02', 'GSTR-2B tab: the reconciliation lists matched, differing and missing documents with counts', async () => {
      const ctx = await browser.newContext({viewport: {width: 1440, height: 900}, ignoreHTTPSErrors: true}); const p = await ctx.newPage(); const errs = [];
      p.on('pageerror', e => errs.push(e.message.slice(0, 200)));
      try {
        await login(p, true);
        await p.evaluate(() => TeghPortal.openGstReturns('gstr2b')); await p.waitForSelector('[data-in4-period]', {timeout: 20000});
        await p.fill('[data-in4-period] [name=period]', '2026-09'); await p.click('[data-in4-period] button[type=submit]');
        await p.waitForSelector('[data-in4-counts]', {timeout: 20000});
        const counts = await p.innerText('[data-in4-counts]'); const rows = await p.locator('[data-in4-table="gstr2b"] tbody tr').count();
        await p.screenshot({path: '/srv/gate/ev/shots/in4-gstr2b-1440.png', fullPage: true});
        return {ok: /Matched 1/.test(counts) && /Amounts differ 1/.test(counts) && /Only in GSTR-2B 1/.test(counts) && rows === 3 && !errs.length, info: JSON.stringify({counts, rows, errs})};
      } finally { await ctx.close(); }
    });
    await t('UI-03', 'Canadian company: no GST Returns in the menus, and opening the page is refused', async () => {
      const ctx = await browser.newContext({viewport: {width: 1440, height: 900}, ignoreHTTPSErrors: true}); const p = await ctx.newPage();
      try {
        await login(p, false);
        await p.evaluate(id => { localStorage.setItem('sr-accountax-company', id); }, CACO); await p.goto('https://gate.test/app.html'); await p.waitForFunction(() => window.TeghPortal && document.querySelector('.sidebar,.topbar'), null, {timeout: 40000}); await p.waitForTimeout(1200);
        const menu = await p.evaluate(() => [...document.querySelectorAll('nav a, nav button, .sidebar a, .sidebar button')].some(n => /GST Returns/.test(n.textContent || '')));
        await p.evaluate(() => TeghPortal.openGstReturns()); await p.waitForTimeout(800);
        const toast = await p.locator('.srp-toast').first().textContent().catch(() => '');
        return {ok: !menu && /only in India companies/.test(toast || ''), info: JSON.stringify({menu, toast})};
      } finally { await ctx.close(); }
    });
  } finally { await browser.close(); }
} finally {
  await setMode(startMode).catch(() => {});
  save('in4.json');
}

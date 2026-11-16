// IN-5: user-defined payroll for India companies (salary structures, pay runs, posting, reversal, registers).
// Every expected figure is worked out by hand below from synthetic structures and synthetic rules; none is read back from Tegh.
// The rates, ceilings and slabs are invented test values, not Indian law.
import {S, sql, rec, save, need} from './lib.mjs';

const A = 'IN5';
const t = async (id, title, fn) => { try { const r = await fn(); rec(id, A, title, r.ok ? 'PASS' : 'FAIL', r.info); return r.ok; } catch (e) { rec(id, A, title, 'FAIL', String(e.message || e).slice(0, 400)); return false; } };
const PW = 'Gate!Owner#2026pw', MPW = 'Gate!Member#2026pw';
const o = new S(); await o.login('owner@gate.test', PW);
const release = async () => (await o.call('site-admin', {company: false})).b.groups.release;
const setMode = async m => { const cur = await release(); return o.call('site-admin/save', {method: 'POST', company: false, json: {groups: {release: {value: {...cur.value, indiaEdition: m}, revision: cur.revision}}, reason: 'IN-5 gate ' + m}}); };
const startMode = (await release()).value.indiaEdition || 'off';
const tag = Date.now().toString(36);
const CH = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
const gstin = b => { let s = 0; for (let i = 0; i < 14; i++) { const p = CH.indexOf(b[i]) * (i % 2 === 0 ? 1 : 2); s += Math.floor(p / 36) + p % 36; } return b + CH[(36 - s % 36) % 36]; };
const n4 = String(1000 + (Date.now() % 9000)).slice(0, 4);
const PAN = 'AAACP' + n4 + 'K';
const M = (s, r, j) => s.call('india/masters/' + r, {method: 'POST', json: j});
const Pay = (s, r, j) => j ? s.call('india/payroll/' + r, {method: 'POST', json: j}) : s.call('india/payroll/' + r);
const R = rupees => Math.round(rupees * 100);
const gl = (co, code) => Number(sql(`SELECT IFNULL(SUM(jl.debit_cents-jl.credit_cents),0) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id JOIN accounts a ON a.id=jl.account_id WHERE je.company_id='${co}' AND je.status='posted' AND a.code='${code}'`));
const tb = co => sql(`SELECT IFNULL(SUM(jl.debit_cents-jl.credit_cents),0) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id WHERE je.company_id='${co}' AND je.status='posted'`);

let IN, ED, VW, INCO = '', CACO = '';
const ids = {};
try {
  need('mode', await setMode('everyone'));
  IN = new S(); await IN.login('owner@gate.test', PW, {edition: 'IN'});
  INCO = need('india company', await IN.call('companies', {method: 'POST', company: false, json: {name: 'IN5 Payroll ' + tag, legalName: 'IN5 Payroll Private Limited', constitution: 'private_limited', state: 'MH', pin: '400001', city: 'Mumbai', fiscalYearEnd: '03-31', booksStartDate: '2026-04-01', pan: PAN, gstin: gstin('27' + PAN + '1Z')}}), [201]).company.id;
  IN.cid = INCO;
  CACO = sql(`SELECT c.id FROM companies c JOIN company_members cm ON cm.company_id=c.id JOIN users u ON u.id=cm.user_id WHERE u.email='owner@gate.test' AND (c.edition IS NULL OR c.edition<>'IN') AND c.active=1 AND cm.role='owner' LIMIT 1`);
  sql(`INSERT INTO company_members (company_id,user_id,role,status) SELECT '${INCO}',id,'editor','active' FROM users WHERE email='editor@gate.test'`);
  ED = new S(); await ED.login('editor@gate.test', MPW, {edition: 'IN'}); ED.cid = INCO;
  // Synthetic rules (approved): EPF 12% on wages up to ₹15,000; ESIC 0.75% (employee) and 3.25% (employer) when wages are at most ₹21,000;
  // Maharashtra Professional Tax slabs: up to ₹7,500 nil, ₹7,500.01–₹10,000 ₹175, above ₹10,000 ₹200.
  const rule = async (j) => { const id = need('rule', await M(IN, 'rule', {label: 'Synthetic ' + j.code, source: 'Synthetic gate data', ...j}), [201]).id; need('approve', await M(IN, 'rule-approve', {id})); return id; };
  await rule({category: 'epf_rate', code: 'G-EPF-' + tag, effectiveFrom: '2026-04-01', ratePercent: '12', thresholdMaxCents: String(R(15000))});
  await rule({category: 'esic_rate', code: 'G-ESIC-EE-' + tag, effectiveFrom: '2026-09-01', ratePercent: '0.75', thresholdMaxCents: String(R(21000))});
  await rule({category: 'esic_rate', code: 'G-ESIC-ER-' + tag, effectiveFrom: '2026-09-01', ratePercent: '3.25', thresholdMaxCents: String(R(21000))});
  await rule({category: 'pt_slab', code: 'G-PT1-' + tag, state: 'MH', effectiveFrom: '2026-04-01', amountCents: '0', thresholdMinCents: '0', thresholdMaxCents: String(R(7500))});
  await rule({category: 'pt_slab', code: 'G-PT2-' + tag, state: 'MH', effectiveFrom: '2026-04-01', amountCents: String(R(175)), thresholdMinCents: String(R(7500) + 1), thresholdMaxCents: String(R(10000))});
  await rule({category: 'pt_slab', code: 'G-PT3-' + tag, state: 'MH', effectiveFrom: '2026-04-01', amountCents: String(R(200)), thresholdMinCents: String(R(10000) + 1)});
  const rec2 = async (recordType, data) => need(recordType, await M(IN, 'record', {recordType, effectiveFrom: '2026-04-01', data}), [201]).id;
  ids.basic = await rec2('pay_component', {name: 'Basic', kind: 'earning', calculation: 'fixed', taxable: true});
  ids.hra = await rec2('pay_component', {name: 'House Rent Allowance', kind: 'earning', calculation: 'percent', percent: '40', baseComponent: ids.basic, taxable: true});
  ids.special = await rec2('pay_component', {name: 'Special Allowance', kind: 'earning', calculation: 'fixed', taxable: true});
  ids.epfEe = await rec2('pay_component', {name: 'EPF (employee)', kind: 'deduction', calculation: 'rule', rule: 'G-EPF-' + tag, baseComponent: ids.basic});
  ids.epfEr = await rec2('pay_component', {name: 'EPF (employer)', kind: 'employer_contribution', calculation: 'rule', rule: 'G-EPF-' + tag, baseComponent: ids.basic});
  ids.esicEe = await rec2('pay_component', {name: 'ESIC (employee)', kind: 'deduction', calculation: 'rule', rule: 'G-ESIC-EE-' + tag});
  ids.esicEr = await rec2('pay_component', {name: 'ESIC (employer)', kind: 'employer_contribution', calculation: 'rule', rule: 'G-ESIC-ER-' + tag});
  ids.pt = await rec2('pay_component', {name: 'Professional Tax', kind: 'deduction', calculation: 'rule', rule: 'G-PT1-' + tag});
  ids.tds = await rec2('pay_component', {name: 'Income tax (TDS)', kind: 'deduction', calculation: 'entered'});
  const emp = (code, name, join, extra = {}) => rec2('employee', {employeeCode: code, name, dateOfJoining: join, epfApplicable: true, esicApplicable: true, ptApplicable: true, ptState: 'MH', ...extra});
  ids.e1 = await emp('A001-' + tag, 'Asha Synthetic', '2026-04-01');
  ids.e2 = await emp('A002-' + tag, 'Ravi Synthetic', '2026-09-11');
  ids.e3 = await emp('A003-' + tag, 'Meera Synthetic', '2026-04-01');
  const all = [ids.basic, ids.hra, ids.special, ids.epfEe, ids.epfEr, ids.esicEe, ids.esicEr, ids.pt, ids.tds];
  const lines = (basic, special) => all.map(c => ({componentId: c, ...(c === ids.basic ? {amountCents: R(basic)} : c === ids.special ? {amountCents: R(special)} : {})}));
  const comp = (line, cid) => line.components.find(c => c.componentId === cid)?.amountCents;

  await t('IN5-01', 'Canada is unaffected: every payroll route answers 404 for a Canadian company and no India payroll rows are created', async () => {
    const ca = new S(); await ca.login('owner@gate.test', PW); ca.cid = CACO;
    const r = [await Pay(ca, 'context'), await Pay(ca, 'registers&from=2026-09-01&to=2026-09-30'), await Pay(ca, 'structure', {employeeId: 'x'}), await Pay(ca, 'run', {period: '2026-09'}), await Pay(ca, 'run-post', {id: 'x'})];
    const exists = sql(`SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='india_pay_runs'`);
    const rows = exists === '1' ? sql(`SELECT COUNT(*) FROM india_pay_runs WHERE company_id='${CACO}'`) : '0';
    return {ok: r.every(x => x.s === 404 && x.b.code === 'india_only') && rows === '0', info: JSON.stringify({r: r.map(x => [x.s, x.b.code]), rows})};
  });

  await t('IN5-02', 'Salary structures: a percent component without its base and an unknown employee are refused; the Owner and an Editor save structures; a Viewer cannot', async () => {
    const noBase = await Pay(IN, 'structure', {employeeId: ids.e1, effectiveFrom: '2026-04-01', lines: [{componentId: ids.hra}]});
    const noEmp = await Pay(IN, 'structure', {employeeId: 'nobody', effectiveFrom: '2026-04-01', lines: lines(20000, 5000)});
    const noAmt = await Pay(IN, 'structure', {employeeId: ids.e1, effectiveFrom: '2026-04-01', lines: [{componentId: ids.basic}]});
    const s1 = await Pay(IN, 'structure', {employeeId: ids.e1, effectiveFrom: '2026-04-01', lines: lines(20000, 5000)});
    const s2 = await Pay(ED, 'structure', {employeeId: ids.e2, effectiveFrom: '2026-09-11', lines: lines(12000, 3000)});
    sql(`INSERT INTO company_members (company_id,user_id,role,status) SELECT '${INCO}',id,'viewer','active' FROM users WHERE email='viewer@gate.test' AND NOT EXISTS (SELECT 1 FROM company_members WHERE company_id='${INCO}' AND user_id=users.id)`);
    let viewer = {s: 'n/a'};
    try { VW = new S(); await VW.login('viewer@gate.test', MPW, {edition: 'IN'}); VW.cid = INCO; viewer = await Pay(VW, 'structure', {employeeId: ids.e3, effectiveFrom: '2026-04-01', lines: lines(10000, 0)}); } catch (e) { viewer = {s: 'login ' + String(e.message).slice(0, 60)}; }
    return {ok: noBase.s === 422 && noBase.b.code === 'india_payroll_base_missing' && noEmp.s === 422 && noAmt.s === 422 && s1.s === 201 && s2.s === 201 && (viewer.s === 403 || /^login/.test(String(viewer.s))),
      info: JSON.stringify({noBase: [noBase.s, noBase.b.code], noEmp: [noEmp.s, noEmp.b.code], noAmt: noAmt.b.code, s1: s1.s, s2: s2.s, viewer: viewer.s})};
  });

  let run;
  await t('IN5-03', 'September draft: Asha (full month) gross ₹33,000, EPF ₹1,800 on the ₹15,000 ceiling, no ESIC above ₹21,000, PT ₹200, TDS ₹1,500, net ₹29,500; Ravi (joined 11 September, 20 of 30 days) prorated; Meera has no structure and is skipped', async () => {
    const r = need('run', await Pay(IN, 'run', {period: '2026-09', payDate: '2026-09-30', inputs: {[ids.e1]: {entered: {[ids.tds]: R(1500)}}}}), [201]);
    run = r;
    const e1 = r.lines.find(l => l.employeeId === ids.e1), e2 = r.lines.find(l => l.employeeId === ids.e2);
    // Asha: Basic 20,000; HRA 40% = 8,000; Special 5,000 → gross 33,000. EPF 12% × min(20,000, 15,000) = 1,800 (both sides).
    // ESIC: gross 33,000 > 21,000 → 0. PT: 33,000 > 10,000 → 200. TDS 1,500. Deductions 3,500. Net 29,500. Employer 1,800.
    const a = e1 && comp(e1, ids.basic) === R(20000) && comp(e1, ids.hra) === R(8000) && comp(e1, ids.special) === R(5000) && e1.grossCents === R(33000)
      && comp(e1, ids.epfEe) === R(1800) && comp(e1, ids.epfEr) === R(1800) && comp(e1, ids.esicEe) === 0 && comp(e1, ids.esicEr) === 0 && comp(e1, ids.pt) === R(200) && comp(e1, ids.tds) === R(1500)
      && e1.deductionsCents === R(3500) && e1.netCents === R(29500) && e1.employerCents === R(1800);
    // Ravi: 20/30 days → Basic 8,000; Special 2,000; HRA 3,200 → gross 13,200. EPF 12% × 8,000 = 960. ESIC 0.75% × 13,200 = 99; employer 3.25% = 429.
    // PT: 13,200 > 10,000 → 200. Deductions 960+99+200 = 1,259. Net 11,941. Employer 960+429 = 1,389.
    const b = e2 && e2.paidDaysMilli === 20000 && comp(e2, ids.basic) === R(8000) && comp(e2, ids.special) === R(2000) && comp(e2, ids.hra) === R(3200) && e2.grossCents === R(13200)
      && comp(e2, ids.epfEe) === R(960) && comp(e2, ids.esicEe) === R(99) && comp(e2, ids.esicEr) === R(429) && comp(e2, ids.pt) === R(200) && e2.netCents === R(11941) && e2.employerCents === R(1389);
    const skipped = (r.skipped || []).map(x => x.employeeId);
    return {ok: !!a && !!b && r.run.status === 'draft' && skipped.includes(ids.e3) && r.lines.length === 2, info: JSON.stringify({e1: e1 && {g: e1.grossCents, d: e1.deductionsCents, n: e1.netCents, c: e1.components.map(c => [c.name, c.amountCents])}, e2: e2 && {days: e2.paidDaysMilli, g: e2.grossCents, n: e2.netCents, c: e2.components.map(c => [c.name, c.amountCents])}, skipped})};
  });

  await t('IN5-04', 'Recalculating the draft with 27 paid days for Asha (3 days loss of pay) prorates her fixed pay; the EPF ceiling still applies; bad paid days are refused', async () => {
    const bad = await Pay(IN, 'run', {period: '2026-09', payDate: '2026-09-30', inputs: {[ids.e1]: {paidDays: 31}}});
    const r = need('recalc', await Pay(IN, 'run', {period: '2026-09', payDate: '2026-09-30', inputs: {[ids.e1]: {paidDays: 27, entered: {[ids.tds]: R(1500)}}}}), [201]);
    run = r;
    const e1 = r.lines.find(l => l.employeeId === ids.e1);
    // 27/30: Basic 18,000; Special 4,500; HRA 7,200 → gross 29,700. EPF 12% × 15,000 = 1,800. PT 200. TDS 1,500. Net 26,200.
    return {ok: bad.s === 422 && bad.b.code === 'india_payroll_paid_days' && r.run.id === run.run.id && comp(e1, ids.basic) === R(18000) && e1.grossCents === R(29700) && comp(e1, ids.epfEe) === R(1800) && e1.netCents === R(26200)
      && r.run.grossCents === R(42900) && r.run.deductionsCents === R(4759) && r.run.employerCents === R(3189) && r.run.netCents === R(38141),
      info: JSON.stringify({bad: [bad.s, bad.b.code], e1: {g: e1.grossCents, n: e1.netCents}, run: r.run})};
  });

  await t('IN5-05', 'Posting: an Editor cannot post; the Owner posts one balanced journal (6000 Dr 42,900; 6010 Dr 3,189; 2310 Cr 4,759; 2320 Cr 3,189; 2300 Cr 38,141); a posted run cannot be recalculated', async () => {
    const ed = await Pay(ED, 'run-post', {id: run.run.id});
    const p = need('post', await Pay(IN, 'run-post', {id: run.run.id}));
    const again = await Pay(IN, 'run', {period: '2026-09', payDate: '2026-09-30', inputs: {}});
    const b = {e6000: gl(INCO, '6000'), e6010: gl(INCO, '6010'), l2310: -gl(INCO, '2310'), l2320: -gl(INCO, '2320'), l2300: -gl(INCO, '2300')};
    const lines = Number(sql(`SELECT COUNT(*) FROM journal_lines WHERE journal_entry_id='${p.run.journalEntryId}'`));
    return {ok: ed.s === 403 && p.run.status === 'posted' && b.e6000 === R(42900) && b.e6010 === R(3189) && b.l2310 === R(4759) && b.l2320 === R(3189) && b.l2300 === R(38141) && tb(INCO) === '0' && again.s === 409 && lines === 5,
      info: JSON.stringify({ed: ed.s, status: p.run.status, b, tb: tb(INCO), again: [again.s, again.b.code], lines})};
  });

  await t('IN5-06', 'Registers: employee totals, component totals (EPF employee ₹2,760, ESIC ₹99, PT ₹400, TDS ₹1,500) and the liability check against the posted journal', async () => {
    const r = need('registers', await Pay(IN, 'registers&from=2026-09-01&to=2026-09-30'));
    const c = Object.fromEntries(r.components.map(x => [x.name, x.amountCents]));
    const liab = Object.fromEntries(r.liabilities.map(x => [x.accountCode, x.postedCents]));
    return {ok: r.employees.length === 2 && r.totals.grossCents === R(42900) && r.totals.netCents === R(38141) && c['EPF (employee)'] === R(2760) && c['ESIC (employee)'] === R(99) && c['Professional Tax'] === R(400) && c['Income tax (TDS)'] === R(1500)
      && c['ESIC (employer)'] === R(429) && liab['2310'] === R(4759) && liab['2320'] === R(3189) && liab['2300'] === R(38141) && r.ties === true,
      info: JSON.stringify({totals: r.totals, c, liab, ties: r.ties})};
  });

  await t('IN5-07', 'A missing rule is reported: an August run fails because no ESIC rule is in force before 1 September', async () => {
    const r = await Pay(IN, 'run', {period: '2026-08', payDate: '2026-08-31', inputs: {}});
    return {ok: r.s === 422 && r.b.code === 'india_payroll_rule_missing', info: JSON.stringify([r.s, r.b.code, String(r.b.error || r.b.message || '').slice(0, 120)])};
  });

  await t('IN5-08', 'Reversal: needs a reason and the Owner; the reversing journal returns every payroll account to nil; the month can then be run again', async () => {
    const noReason = await Pay(IN, 'run-reverse', {id: run.run.id, reason: ''});
    const ed = await Pay(ED, 'run-reverse', {id: run.run.id, reason: 'Wrong paid days'});
    const r = need('reverse', await Pay(IN, 'run-reverse', {id: run.run.id, reason: 'Wrong paid days entered', date: '2026-09-30'}));
    const b = [gl(INCO, '6000'), gl(INCO, '6010'), gl(INCO, '2310'), gl(INCO, '2320'), gl(INCO, '2300')];
    const again = await Pay(IN, 'run', {period: '2026-09', payDate: '2026-09-30', inputs: {[ids.e1]: {entered: {[ids.tds]: R(1500)}}}});
    const audit = sql(`SELECT GROUP_CONCAT(DISTINCT action ORDER BY action) FROM audit_log WHERE company_id='${INCO}' AND action LIKE 'india.pay_run%'`);
    return {ok: noReason.s === 422 && ed.s === 403 && r.run.status === 'reversed' && b.every(x => x === 0) && again.s === 201 && again.b.run.id !== run.run.id && again.b.run.netCents === R(29500 + 11941) && /calculated/.test(audit) && /posted/.test(audit) && /reversed/.test(audit),
      info: JSON.stringify({noReason: noReason.s, ed: ed.s, status: r.run.status, b, again: [again.s, again.b.run?.netCents], audit})};
  });
} finally {
  await setMode(startMode).catch(() => {});
  save('in5.json');
}

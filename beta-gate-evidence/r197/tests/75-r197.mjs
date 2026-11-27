// R197 (India only): payroll set-up with pay heads and employee pay tables, tested with 10 employees over 10 monthly
// payrolls (April 2026 to January 2027). Synthetic data only. Every head of every employee in every month is compared with
// figures worked out independently here; every run is posted and its journal checked; the registers add up.
import {S, sql, rec, save, need} from './lib.mjs';
import {chromium} from '/opt/node22/lib/node_modules/playwright/index.mjs';

const A = 'R197';
const t = async (id, title, fn) => { try { const r = await fn(); rec(id, A, title, r.ok ? 'PASS' : 'FAIL', r.info); return r.ok; } catch (e) { rec(id, A, title, 'FAIL', String(e.message || e).slice(0, 600)); return false; } };
const o = new S(); await o.login('owner@gate.test', 'Gate!Owner#2026pw');
const release = async () => (await o.call('site-admin', {company: false})).b.groups.release;
const setRelease = async (k, v) => { const cur = await release(); return o.call('site-admin/save', {method: 'POST', company: false, json: {groups: {release: {value: {...cur.value, [k]: v}, revision: cur.revision}}, reason: 'R197 gate: India payroll'}}); };
const start = (await release()).value;
const tag = Date.now().toString(36), EMAIL = `r197-${tag}@gate.test`, UID = `user_r197${tag}`.padEnd(37, '0'), PW = 'Gate!Member#2026pw';
sql(`CREATE TEMPORARY TABLE r197u AS SELECT * FROM users WHERE email='otherowner@gate.test'; UPDATE r197u SET id='${UID}', email='${EMAIL}'; INSERT INTO users SELECT * FROM r197u`);

// ---------- independent model ----------
const R = x => Math.sign(x) * Math.round(Math.abs(x));            // PHP round(): half away from zero
const rupee = p => R(p / 100) * 100;
const RULES = {epfEe: 12, epfEr: 12, epfCap: 1500000, esicEe: 0.75, esicEr: 3.25, esicLimit: 2100000, pt: 20000};
const BASES = {monthly: [1, 1], annual: [1, 12], semi_monthly: [2, 1], bi_weekly: [26, 12]};
const daysIn = m => new Date(+m.slice(0, 4), +m.slice(5, 7), 0).getDate();
const iso = (m, d) => `${m}-${String(d).padStart(2, '0')}`;
// pay: {head: {amount, basis} | {pct, of:[heads] | 'gross'} | 'entered'}, stat: {epf: 'basic_da'|'basic'|{amount}, esic, pt}
function model(emp, month, entered = {}, paidDays = null) {
  const d = daysIn(month), from = iso(month, 1), to = iso(month, d);
  if (emp.join > to || (emp.leave && emp.leave < from)) return null;
  const s = d0 => (d0 < from ? 1 : +d0.slice(8)), e = d1 => (!d1 || d1 > to ? d : +d1.slice(8));
  const employed = e(emp.leave) - s(emp.join) + 1;
  const paid = (paidDays ?? employed) * 1000;
  const pay = emp.payFor(month), v = {}, earn = [], ded = [], er = [];
  const isEarn = h => EARN.includes(h) || (emp.ownEarn || []).includes(h);
  // fixed amounts
  for (const [h, x] of Object.entries(pay)) if (x && x.amount !== undefined) { const [mu, dv] = BASES[x.basis || 'monthly']; const monthly = R(x.amount * 100 * mu / dv); v[h] = R(monthly * paid / (d * 1000)); }
  // percentages of earnings (in order until stable)
  for (let k = 0; k < 6; k++) for (const [h, x] of Object.entries(pay)) if (x && x.pct !== undefined && v[h] === undefined && x.of !== 'gross' && x.of.every(b => v[b] !== undefined)) v[h] = R(x.of.reduce((a, b) => a + v[b], 0) * x.pct / 100);
  const gross = Object.entries(v).filter(([h]) => isEarn(h)).reduce((a, [, x]) => a + x, 0);
  for (const [h, x] of Object.entries(pay)) if (x && x.pct !== undefined && x.of === 'gross') v[h] = R(gross * x.pct / 100);
  if (pay.tds === 'entered') v['Income tax (TDS)'] = entered.tds || 0;
  // statutory
  if (emp.stat.epf) {
    const base = (emp.stat.epfOf || ['Basic Pay', 'Dearness Allowance']).reduce((a, b) => a + (v[b] || 0), 0), capped = Math.min(base, RULES.epfCap);
    v['EPF (employee)'] = emp.stat.epf.amount !== undefined ? R(emp.stat.epf.amount * 100 * paid / (d * 1000)) : rupee(R(capped * RULES.epfEe / 100));
    const erBase = emp.stat.epf.amount !== undefined ? Math.min(v['Basic Pay'] || 0, RULES.epfCap) : capped;
    v['EPF (employer)'] = rupee(R(erBase * RULES.epfEr / 100));
  }
  if (emp.stat.esic) { const ok = gross <= RULES.esicLimit; v['ESIC (employee)'] = ok ? rupee(R(gross * RULES.esicEe / 100)) : 0; v['ESIC (employer)'] = ok ? rupee(R(gross * RULES.esicEr / 100)) : 0; }
  if (emp.stat.pt) v['Professional Tax'] = RULES.pt;
  let g = 0, dd = 0, e2 = 0;
  for (const [h, x] of Object.entries(v)) { if (isEarn(h)) g += x; else if (/\(employer\)/.test(h)) e2 += x; else dd += x; }
  return {v, gross: g, ded: dd, er: e2, net: g - dd};
}
const EARN = ['Basic Pay', 'Dearness Pay', 'Dearness Allowance', 'Cell Phone Allowance', 'Travel Allowance', 'Medical Allowance'];

// ---------- the ten employees ----------
const amt = (a, basis = 'monthly') => ({amount: a, basis});
const pct = (p, of) => ({pct: p, of});
const BD = ['Basic Pay', 'Dearness Allowance'], BP = ['Basic Pay'], BDP = ['Basic Pay', 'Dearness Pay'], BDPD = ['Basic Pay', 'Dearness Pay', 'Dearness Allowance'];
const EMP = [
  {name: 'Asha Rao', join: '2026-04-01', stat: {epf: true, pt: true}, payFor: m => ({'Basic Pay': amt(30000), 'Dearness Allowance': pct(50, BP), 'Travel Allowance': amt(19200, 'annual'), 'Medical Allowance': amt(15000, 'annual'), 'Cell Phone Allowance': amt(1000), Insurance: amt(500), tds: 'entered', ...(m < '2026-10' ? {'Loan Recovery': amt(2000)} : {})})},
  {name: 'Bala K', join: '2026-04-01', stat: {epf: true, esic: true, pt: true}, payFor: () => ({'Basic Pay': amt(12000), 'Dearness Allowance': pct(40, BP), 'Travel Allowance': amt(800)})},
  {name: 'Chitra S', join: '2026-04-01', stat: {epf: true, esic: true, pt: true, epfOf: BP}, payFor: () => ({'Basic Pay': amt(9000, 'bi_weekly'), 'Medical Allowance': amt(1000)})},
  {name: 'Dev M', join: '2026-04-01', stat: {}, payFor: () => ({'Basic Pay': amt(25000, 'semi_monthly'), 'Dearness Pay': pct(10, BP), 'Dearness Allowance': pct(46, BDP), 'Cell Phone Allowance': amt(1500), CPF: pct(10, BD), 'Income tax (TDS)': amt(5000)})},
  {name: 'Esha P', join: '2026-06-15', stat: {epf: true, pt: true}, ownEarn: ['Night Shift Allowance'], payFor: () => ({'Basic Pay': amt(18000), 'Dearness Allowance': pct(50, BP), 'Night Shift Allowance': amt(1200), Canteen: amt(300)})},
  {name: 'Farid N', join: '2026-04-01', leave: '2026-11-20', stat: {epf: true}, payFor: () => ({'Basic Pay': amt(22000), 'Dearness Allowance': pct(30, BP)})},
  {name: 'Gita V', join: '2026-04-01', stat: {epf: true, esic: true, pt: true}, payFor: m => m < '2026-09' ? {'Basic Pay': amt(15000), 'Dearness Allowance': pct(42, BP)} : {'Basic Pay': amt(17500), 'Dearness Allowance': pct(46, BP)}},
  {name: 'Hari L', join: '2026-04-01', stat: {epf: true, esic: true, pt: true}, payFor: () => ({'Basic Pay': amt(14000), 'Dearness Allowance': pct(20, BP)})},
  {name: 'Indu T', join: '2026-04-01', stat: {}, payFor: m => ({'Basic Pay': amt(m < '2026-12' ? 40000 : 42000), 'Dearness Pay': amt(4000), 'Dearness Allowance': pct(50, BDP), GPF: pct(8, BDPD), Insurance: amt(1200), 'Loan Recovery': amt(5000)})},
  {name: 'Jai B', join: '2026-04-01', stat: {epf: {amount: 2000}, pt: true}, payFor: () => ({'Basic Pay': amt(25000), 'Dearness Allowance': pct(10, BP), Insurance: pct(1, 'gross')})},
];
const MONTHS = ['2026-04', '2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10', '2026-11', '2026-12', '2027-01'];
const OFKEY = {'Basic Pay': 'basic', 'Basic Pay|Dearness Allowance': 'basic_da', 'Basic Pay|Dearness Pay': 'basic_dp', 'Basic Pay|Dearness Pay|Dearness Allowance': 'basic_dp_da', 'Dearness Pay': 'dp', 'Dearness Allowance': 'da'};

let browser, CO = '', api, heads = {};
const rowsFor = (emp, m) => {
  const pay = emp.payFor(m), rows = [];
  for (const [h, x] of Object.entries(pay)) {
    if (h === 'tds') { rows.push({componentId: heads['Income tax (TDS)'], mode: 'entered'}); continue; }
    const cid = heads[h]; if (!cid) throw Error('no head ' + h);
    if (x.amount !== undefined) rows.push({componentId: cid, mode: 'amount', amount: String(x.amount), basis: x.basis});
    else rows.push({componentId: cid, mode: 'percent', percent: String(x.pct), of: x.of === 'gross' ? 'gross' : OFKEY[x.of.join('|')]});
  }
  if (emp.stat.epf) rows.push(emp.stat.epf.amount !== undefined ? {componentId: heads['EPF (employee)'], mode: 'amount', amount: String(emp.stat.epf.amount), basis: 'monthly'} : {componentId: heads['EPF (employee)'], mode: 'rule', of: emp.stat.epfOf ? 'basic' : 'basic_da'});
  if (emp.stat.esic) rows.push({componentId: heads['ESIC (employee)'], mode: 'rule'});
  if (emp.stat.pt) rows.push({componentId: heads['Professional Tax'], mode: 'rule'});
  return rows;
};
const flags = emp => ({epf: !!emp.stat.epf, esic: !!emp.stat.esic, pt: !!emp.stat.pt});

try {
  need('india mode', await setRelease('indiaEdition', 'everyone'));
  api = new S(); await api.login(EMAIL, PW, {edition: 'IN'});
  const created = need('india company', await api.call('companies', {method: 'POST', company: false, json: {name: 'R197 Payroll ' + tag, legalName: 'R197 Payroll Private Limited', constitution: 'private_limited', state: 'KA', pin: '560001', building: '1 Test Street', street: 'MG Road', locality: 'Ashok Nagar', city: 'Bengaluru', district: 'Bengaluru Urban',
    financialYearStart: '2026-04-01', financialYearEnd: '2027-03-31', timezone: 'Asia/Kolkata', bankAccountType: 'current', coaMode: 'default'}}), [201]);
  CO = created.company.id; api.cid = CO;

  await t('R197-01', 'Payroll set-up creates the fixed pay heads (Basic Pay, Dearness Pay, Dearness Allowance, Cell Phone, Travel and Medical Allowances; EPF, ESIC, Professional Tax, CPF, GPF, Insurance, Income tax (TDS), Loan Recovery; EPF and ESIC employer); own heads are added and renamed; a standard head cannot be renamed or removed; duplicate headings are refused', async () => {
    const r = need('payroll set-up', await api.call('india/setup/payroll', {method: 'POST', json: {effectiveFrom: '2026-04-01', confirmed: true, epf: {on: true, employeePercent: '12', employerPercent: '12', ceilingRupees: '15000'}, esic: {on: true, employeePercent: '0.75', employerPercent: '3.25', limitRupees: '21000'}, pt: {on: true, amountRupees: '200'}}}));
    const names0 = r.state.heads.map(h => h.name);
    const a1 = await api.call('india/setup/head', {method: 'POST', json: {name: 'Night Allowance', kind: 'earning'}});
    const a2 = await api.call('india/setup/head', {method: 'POST', json: {name: 'Canteen', kind: 'deduction'}});
    const dup = await api.call('india/setup/head', {method: 'POST', json: {name: 'canteen', kind: 'deduction'}});
    const std = await api.call('india/setup/head', {method: 'POST', json: {name: 'Basic Pay', kind: 'earning'}});
    const ren = await api.call('india/setup/head', {method: 'POST', json: {id: a1.b.id, name: 'Night Shift Allowance'}});
    const basicId = ren.b.state.heads.find(h => h.key === 'basic').id;
    const renStd = await api.call('india/setup/head', {method: 'POST', json: {id: basicId, name: 'Pay'}});
    const remStd = await api.call('india/setup/head-remove', {method: 'POST', json: {id: basicId}});
    heads = Object.fromEntries(ren.b.state.heads.map(h => [h.name, h.id]));
    const want = ['Basic Pay', 'Dearness Pay', 'Dearness Allowance', 'Cell Phone Allowance', 'Travel Allowance', 'Medical Allowance', 'EPF (employee)', 'ESIC (employee)', 'Professional Tax', 'CPF', 'GPF', 'Insurance', 'Income tax (TDS)', 'Loan Recovery', 'EPF (employer)', 'ESIC (employer)'];
    return {ok: JSON.stringify(names0) === JSON.stringify(want) && a1.s === 201 && a2.s === 201 && dup.s === 409 && std.s === 409 && ren.s === 201 && renStd.s === 409 && remStd.s === 409 && !!heads['Night Shift Allowance'] && !!heads.Canteen,
      info: JSON.stringify({names0, a1: a1.s, a2: a2.s, dup: [dup.s, dup.b?.code], std: [std.s, std.b?.code], ren: ren.s, renStd: renStd.s, remStd: remStd.s})};
  });

  const ids = {};
  await t('R197-02', 'Ten employees with pay tables (amounts monthly, annual, semi-monthly and bi-weekly; DA, DP, HRA-style, CPF, GPF and Insurance as percentages of Basic, DP, DA or gross; EPF on Basic + DA or Basic, or an amount); the preview equals the saved month; refusals for a percentage of gross on an earning and for a loop', async () => {
    const bad1 = await api.call('india/setup/preview', {method: 'POST', json: {salaryFrom: '2026-04-01', rows: [{componentId: heads['Basic Pay'], mode: 'amount', amount: '1000', basis: 'monthly'}, {componentId: heads['Medical Allowance'], mode: 'percent', percent: '5', of: 'gross'}]}});
    const bad2 = await api.call('india/setup/preview', {method: 'POST', json: {salaryFrom: '2026-04-01', rows: [{componentId: heads['Basic Pay'], mode: 'percent', percent: '10', of: 'da'}, {componentId: heads['Dearness Allowance'], mode: 'percent', percent: '10', of: 'basic'}]}});
    const bad3 = await api.call('india/setup/preview', {method: 'POST', json: {salaryFrom: '2026-04-01', rows: [{componentId: heads['Basic Pay'], mode: 'amount', amount: '1000', basis: 'weekly'}]}});
    const mism = [];
    for (const emp of EMP) {
      const m0 = MONTHS.find(m => m >= emp.join.slice(0, 7));
      const body = {name: emp.name, dateOfJoining: emp.join, dateOfLeaving: emp.leave || '', salaryFrom: emp.join, ...flags(emp), rows: rowsFor(emp, m0)};
      const pv = need('preview ' + emp.name, await api.call('india/setup/preview', {method: 'POST', json: body}));
      const sv = need('save ' + emp.name, await api.call('india/setup/employee', {method: 'POST', json: body}), [201]);
      ids[emp.name] = sv.employeeId;
      const listed = sv.state.employees.find(x => x.id === sv.employeeId);
      const full = model({...emp, join: '2000-01-01', leave: null}, pv.month);
      if (pv.netCents !== full.net || pv.grossCents !== full.gross) mism.push({emp: emp.name, preview: [pv.grossCents, pv.netCents], model: [full.gross, full.net]});
      if (!listed?.month || listed.month.netCents !== pv.netCents && !emp.leave) mism.push({emp: emp.name, listed: listed?.month?.netCents, preview: pv.netCents, problem: listed?.problem});
    }
    return {ok: bad1.s === 422 && bad1.b.code === 'india_payroll_gross_base' && bad2.s === 422 && bad2.b.code === 'india_payroll_cycle' && bad3.s === 422 && !mism.length && Object.keys(ids).length === 10,
      info: JSON.stringify({bad1: [bad1.s, bad1.b?.code], bad2: [bad2.s, bad2.b?.code], bad3: [bad3.s, bad3.b?.code], mism, codes: Object.keys(ids).length})};
  });

  await t('R197-03', 'Changes during the year: Asha\'s loan stops from October, Gita\'s pay is revised from September, Indu\'s Basic Pay rises from December (saved after earlier runs), and a renamed head keeps its new name; each new pay applies from its month', async () => {
    const res = [];
    for (const [name, m] of [['Asha Rao', '2026-10'], ['Gita V', '2026-09']]) {
      const emp = EMP.find(e => e.name === name);
      res.push((await api.call('india/setup/employee', {method: 'POST', json: {id: ids[name], name, dateOfJoining: emp.join, salaryFrom: m + '-01', ...flags(emp), rows: rowsFor(emp, m)}})).s);
    }
    const st = (await api.call('india/setup/payroll')).b;
    const gita = st.employees.find(x => x.name === 'Gita V'), asha = st.employees.find(x => x.name === 'Asha Rao');
    return {ok: res.every(s => s === 201) && gita.salaryFrom === '2026-09-01' && asha.salaryFrom === '2026-10-01' && !asha.rows.some(r => r.componentId === heads['Loan Recovery']), info: JSON.stringify({res, gita: gita.salaryFrom, asha: asha.salaryFrom})};
  });

  const runs = {};
  const mism = [], journal = [];
  for (const [mi, month] of MONTHS.entries()) {
    if (month === '2026-12') {
      // Indu's raise is saved now, after the November run is posted.
      const emp = EMP.find(e => e.name === 'Indu T');
      need('indu raise', await api.call('india/setup/employee', {method: 'POST', json: {id: ids['Indu T'], name: emp.name, dateOfJoining: emp.join, salaryFrom: '2026-12-01', ...flags(emp), rows: rowsFor(emp, '2026-12')}}), [201]);
    }
    const tds = 200000 + mi * 10000; // Asha's TDS entered each month
    const inputs = {[ids['Asha Rao']]: {entered: {[heads['Income tax (TDS)']]: tds}}};
    if (month === '2026-08') inputs[ids['Hari L']] = {paidDays: '28'};
    const run = need('run ' + month, await api.call('india/payroll/run', {method: 'POST', json: {period: month, payDate: iso(month, daysIn(month)), inputs}}), [201]);
    runs[month] = run;
    const got = Object.fromEntries((run.lines || []).map(l => [l.name, l]));
    for (const emp of EMP) {
      const want = model(emp, month, emp.name === 'Asha Rao' ? {tds} : {}, emp.name === 'Hari L' && month === '2026-08' ? 28 : null);
      const g = got[emp.name];
      if (!want) { if (g) mism.push({month, emp: emp.name, issue: 'paid but not employed'}); continue; }
      if (!g) { mism.push({month, emp: emp.name, issue: 'missing from run'}); continue; }
      const gv = Object.fromEntries(g.components.map(c => [c.name, c.amountCents]));
      for (const h of new Set([...Object.keys(want.v), ...Object.keys(gv)])) if ((gv[h] ?? 0) !== (want.v[h] ?? 0)) mism.push({month, emp: emp.name, head: h, got: gv[h] ?? null, want: want.v[h] ?? null});
      if (g.grossCents !== want.gross || g.netCents !== want.net || g.deductionsCents !== want.ded || g.employerCents !== want.er) mism.push({month, emp: emp.name, totals: [g.grossCents, g.deductionsCents, g.employerCents, g.netCents], want: [want.gross, want.ded, want.er, want.net]});
    }
    const posted = await api.call('india/payroll/run-post', {method: 'POST', json: {id: run.run.id}});
    const je = sql(`SELECT journal_entry_id FROM india_pay_runs WHERE id='${run.run.id}'`);
    const sums = sql(`SELECT CONCAT(SUM(jl.debit_cents),'|',SUM(jl.credit_cents)) FROM journal_lines jl WHERE jl.journal_entry_id='${je}'`).split('|').map(Number);
    const byCode = Object.fromEntries(sql(`SELECT GROUP_CONCAT(CONCAT(a.code,':',jl.debit_cents-jl.credit_cents)) FROM journal_lines jl JOIN accounts a ON a.id=jl.account_id WHERE jl.journal_entry_id='${je}'`).split(',').map(x => x.split(':')).map(([k, v]) => [k, (Number(v) || 0) + 0]));
    const sumBy = k => Object.values(Object.fromEntries((run.lines || []).map(l => [l.name, l]))).reduce((a, l) => a + l[k], 0);
    journal.push({month, posted: posted.s, balanced: sums[0] === sums[1], netOk: -(byCode['2300'] || 0) === sumBy('netCents'), dedOk: -(byCode['2310'] || 0) === sumBy('deductionsCents'), erOk: -(byCode['2320'] || 0) === sumBy('employerCents')});
  }

  await t('R197-04', 'Ten monthly payrolls (April 2026 to January 2027) for ten employees: every head of every employee in every month equals the figures worked out independently (proration for joining, leaving and unpaid days; percentages of prorated bases; EPF ceiling; ESIC limit on gross; amounts entered annually, semi-monthly or bi-weekly; TDS entered each month; revisions from their month)', async () => {
    const counts = MONTHS.map(m => (runs[m]?.lines || []).length);
    return {ok: !mism.length && JSON.stringify(counts) === JSON.stringify([9, 9, 10, 10, 10, 10, 10, 10, 9, 9]), info: JSON.stringify({counts, mismatches: mism.length, first: mism.slice(0, 8)})};
  });

  await t('R197-05', 'Each of the ten runs posts one balanced journal: Salaries Payable equals total net pay, Payroll Deductions Payable equals total deductions, Employer Contributions Payable equals total employer contributions', async () => {
    const bad = journal.filter(j => j.posted !== 200 || !j.balanced || !j.netOk || !j.dedOk || !j.erOk);
    return {ok: journal.length === 10 && !bad.length, info: JSON.stringify({runs: journal.length, bad})};
  });

  await t('R197-06', 'Registers for the ten months: totals equal the sum of the runs; the renamed head appears under its new name; Farid stops after November and Esha starts in June', async () => {
    const reg = need('registers', await api.call('india/payroll/registers?from=2026-04-01&to=2027-01-31'));
    const tot = MONTHS.reduce((a, m) => ({g: a.g + runs[m].run.grossCents, n: a.n + runs[m].run.netCents, d: a.d + runs[m].run.deductionsCents, e: a.e + runs[m].run.employerCents}), {g: 0, n: 0, d: 0, e: 0});
    const t2 = reg.totals || {};
    const comps = JSON.stringify(reg.components || reg.byComponent || '');
    const farid = MONTHS.filter(m => (runs[m].lines || []).some(l => l.name === 'Farid N')), esha = MONTHS.filter(m => (runs[m].lines || []).some(l => l.name === 'Esha P'));
    return {ok: t2.grossCents === tot.g && t2.netCents === tot.n && t2.deductionsCents === tot.d && t2.employerCents === tot.e && /Night Shift Allowance/.test(comps) && farid.at(-1) === '2026-11' && esha[0] === '2026-06',
      info: JSON.stringify({reg: t2, sum: tot, farid: farid.at(-1), esha: esha[0]})};
  });

  // Browser: the pay table and a payslip from the screens.
  browser = await chromium.launch({executablePath: '/opt/pw-browsers/chromium', args: ['--no-proxy-server', '--host-resolver-rules=MAP gate.test 127.0.0.1']});
  const ctx = await browser.newContext({viewport: {width: 1440, height: 900}, ignoreHTTPSErrors: true});
  const p = await ctx.newPage(); const errs = [];
  p.on('pageerror', e => errs.push(e.message.slice(0, 160)));
  p.on('response', r => { if (r.status() >= 400 && /\/api\//.test(r.url()) && !/auth%2Fme/.test(r.url())) errs.push('api ' + r.status() + ' ' + r.url().replace(/^.*route=/, '').slice(0, 60)); });
  await ctx.addInitScript(id => { try { if (!sessionStorage.getItem('r197')) { localStorage.setItem('sr-accountax-company', id); sessionStorage.setItem('r197', '1'); } } catch {} }, CO);
  await p.goto('https://gate.test/app.html?edition=in'); await p.waitForSelector('#sr-login-form');
  await p.selectOption('#sr-login-form [name=edition]', 'IN');
  await p.fill('#sr-login-form input[name=email]', EMAIL); await p.fill('#sr-login-form input[name=password]', PW); await p.click('#sr-login-form button[type=submit]');
  await p.waitForFunction(() => window.TeghPortal?.openIndiaPayrollSetup && document.querySelector('.srp-page[data-srp-page]'), null, {timeout: 60000});

  await t('UI-01', 'Payroll Set-up lists the pay heads and the ten employees with their monthly gross, deductions and net; editing an employee shows the pay as entered (amount and basis, or percentage and base) and the preview', async () => {
    await p.evaluate(() => TeghPortal.openIndiaPayrollSetup()); await p.waitForSelector('.r196-employees', {timeout: 20000});
    const rows = await p.$$eval('.r196-employees tbody tr', l => l.map(x => x.innerText.replace(/\s+/g, ' ').trim()));
    const own = await p.$$eval('[data-r197-head-name]', l => l.map(i => i.value));
    await p.locator('.r196-employees tbody tr', {hasText: 'Dev M'}).locator('[data-r196-edit]').click(); await p.waitForSelector('[data-r196-employee]'); await p.waitForTimeout(1500);
    const basic = await p.evaluate(() => { const r = [...document.querySelectorAll('[data-r197-row]')].find(x => x.querySelector('.r197-name').textContent === 'Basic Pay'); return [r.querySelector('[data-f=mode]').value, r.querySelector('[data-f=value]').value, r.querySelector('[data-f=basis]').value, r.querySelector('[data-r197-amount]').textContent]; });
    const da = await p.evaluate(() => { const r = [...document.querySelectorAll('[data-r197-row]')].find(x => x.querySelector('.r197-name').textContent === 'Dearness Allowance'); return [r.querySelector('[data-f=mode]').value, r.querySelector('[data-f=value]').value, r.querySelector('[data-f=of]').value, r.querySelector('[data-r197-amount]').textContent]; });
    const net = await p.textContent('[data-r197-net]');
    await p.screenshot({path: '/srv/gate/ev/shots/r197-edit-dev-1440.png', fullPage: true});
    const dev = model(EMP[3], '2027-01');
    return {ok: rows.length === 10 && JSON.stringify(own) === '["Night Shift Allowance","Canteen"]' && basic[0] === 'amount' && basic[1] === '25000' && basic[2] === 'semi_monthly' && basic[3] === '₹50,000.00' && da[0] === 'percent' && da[1] === '46' && da[2] === 'basic_dp' && net === '₹' + (dev.net / 100).toLocaleString('en-IN', {minimumFractionDigits: 2}) && !errs.length,
      info: JSON.stringify({rows: rows.length, own, basic, da, net, want: dev.net, errs})};
  });

  await t('UI-02', 'Pay Runs: the posted January 2027 run opens with a payslip per employee showing each head', async () => {
    await p.evaluate(() => TeghPortal.openIndiaPayroll('runs')); await p.waitForSelector('[data-in5-open]', {timeout: 20000});
    const btn = p.locator('[data-in5-open]').first(); await btn.click(); await p.waitForTimeout(2500);
    const slips = await p.$$eval('[data-in5-slip]', l => l.length);
    await p.locator('[data-in5-slip]').first().click(); await p.waitForSelector('[data-in5-payslip]', {timeout: 10000});
    const txt = await p.evaluate(() => document.querySelector('[data-in5-payslip]')?.innerText || '');
    return {ok: slips >= 9 && /Basic Pay/.test(txt) && /Net pay/i.test(txt) && !errs.length, info: JSON.stringify({slips, sample: txt.slice(0, 200), errs})};
  });
  await ctx.close();
} finally {
  if (browser) await browser.close().catch(() => {});
  await setRelease('indiaEdition', start.indiaEdition || 'off').catch(() => {});
  try { sql(`UPDATE users SET active=0 WHERE id='${UID}'`); } catch {}
  save('r197.json');
}

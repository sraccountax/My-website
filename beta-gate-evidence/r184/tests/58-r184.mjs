// R184: Report Builder. Expected figures are worked out here from the records the test enters (price × quantity,
// 13% HST), never read from Tegh first. General Ledger results are also tied to Tegh's own Trial Balance.
// The suite creates its own Ontario company, turns the Report Builder switch on, and puts it back at the end.
import {S, sql, rec, save, need} from './lib.mjs';
import {open} from './e2e/base.mjs';
import fs from 'fs';

const A = 'R184';
const t = async (id, title, fn) => { try { const r = await fn(); rec(id, A, title, r.ok ? 'PASS' : 'FAIL', r.info); return r.ok; } catch (e) { rec(id, A, title, 'FAIL', String(e.message || e).slice(0, 400)); return false; } };
const o = new S(); await o.login('owner@gate.test', 'Gate!Owner#2026pw');
const release = async () => (await o.call('site-admin', {company: false})).b.groups.release;
const setMode = async m => { const cur = await release(); return o.call('site-admin/save', {method: 'POST', company: false, json: {groups: {release: {value: {...cur.value, reportBuilder: m}, revision: cur.revision}}, reason: 'R184 gate ' + m}}); };
const startMode = (await release()).value.reportBuilder || 'off';
const tag = Date.now().toString(36);
let n = 0; const key = p => `r184-${p}-${tag}-${++n}`.padEnd(20, 'x');
const base = {businessType: 'corporation', country: 'Canada', province: 'ON', taxRegistered: true, taxNumber: '123456789RT0001', moduleMode: 'accounting', fiscalYearEnd: '12-31', booksStartDate: '2026-01-01', chartTemplate: 'default', currency: 'CAD', accountingBasis: 'accrual'};
const CO = need('company', await o.call('companies', {method: 'POST', json: {...base, name: `R184 Builder ${tag}`}})).company.id;
o.cid = CO;
const acct = code => sql(`SELECT id FROM accounts WHERE company_id='${CO}' AND code='${code}'`);
const run = def => o.call('report-builder/run', {method: 'POST', json: {definition: def}});
const Q3 = {start: '2026-07-01', end: '2026-09-30'};
const hst = c => Math.round(c * 0.13);
export const ctx = {CO};

// ---- Records (hand-computed amounts) ----
const MAPLE = need('maple', await o.call('customers', {method: 'POST', json: {name: 'R184 Maple ' + tag, email: '', phone: '', billingAddress: '1 King St', country: 'Canada', province: 'ON'}})).customer.id;
const BIRCH = need('birch', await o.call('customers', {method: 'POST', json: {name: 'R184 Birch ' + tag, email: '', phone: '', billingAddress: '2 Queen St', country: 'Canada', province: 'ON'}})).customer.id;
const TMPL = (await o.call('invoice-templates')).b.templates?.[0]?.id;
const BANKACCT = (await o.call('payments?type=customer')).b.paymentAccounts.find(a => !a.undeposited)?.id;
const line = (description, quantity, unitPriceCents) => ({description, quantity, unitPriceCents, taxable: true});
const invoice = async (customerId, issueDate, lines) => need('invoice', await o.call('invoices', {method: 'POST', json: {customerId, issueDate, dueDate: '2026-10-30', currency: 'CAD', templateId: TMPL, issue: true, lines}})).invoice;
const I1 = await invoice(MAPLE, '2026-07-10', [line('Consulting', 10, 10000)]);   // 1000.00 + 130.00 = 1130.00
const I2 = await invoice(MAPLE, '2026-08-15', [line('Design', 2, 30000)]);        //  600.00 +  78.00 =  678.00
const I3 = await invoice(BIRCH, '2026-08-20', [line('Review', 3, 15000)]);        //  450.00 +  58.50 =  508.50
const I4 = await invoice(BIRCH, '2026-09-05', [line('Project', 1, 200000)]);      // 2000.00 + 260.00 = 2260.00
need('pay I3', await o.call('payments', {method: 'POST', json: {type: 'customer', documentId: I3.id, paymentDate: '2026-09-01', reference: 'EFT 1', foreignAmountCents: 50850, paymentAccountId: BANKACCT, operationKey: key('pay')}}));
const VEND = need('vendor', await o.call('vendors', {method: 'POST', json: {name: 'R184 Paper Co ' + tag, province: 'ON', country: 'Canada', paymentTerms: 30}})).vendor.id;
const ON = sql(`SELECT id FROM tax_codes WHERE company_id='${CO}' AND code='ON'`);
const bill = async (number, billDate, cents) => need('bill ' + number, await o.call('bills', {method: 'POST', json: {vendorId: VEND, number, billDate, dueDate: '2026-10-16', categoryAccountId: acct('6400'), currency: 'CAD', taxEntryMode: 'exclusive', taxCodeId: ON, foreignAmountCents: cents, issue: true}}));
await bill('B-1', '2026-07-20', 50000);   // 500.00 + 65.00 = 565.00
await bill('B-2', '2026-09-12', 20000);   // 200.00 + 26.00 = 226.00
need('journal', await o.call('journals', {method: 'POST', json: {date: '2026-09-30', memo: 'R184 bank fee', lines: [{accountId: acct('6800'), debitCents: 2500, creditCents: 0}, {accountId: acct('1000'), debitCents: 0, creditCents: 2500}]}}));
// Two bank lines through a statement import: +508.50 and −25.00.
{
  const BANK = sql(`SELECT id FROM bank_accounts WHERE company_id='${CO}' AND account_type='bank' AND active=1 ORDER BY created_at LIMIT 1`);
  const data = Buffer.from(`Date,Description,Amount\n2026-09-03,BIRCH DEPOSIT ${tag},508.50\n2026-09-30,MONTHLY FEE ${tag},-25.00\n`);
  const up = need('start', await o.call('imports/upload-start', {method: 'POST', json: {filename: key('csv') + '.csv', size: data.length}}));
  need('chunk', await o.call('imports/upload-chunk', {method: 'POST', json: {uploadId: up.uploadId, offset: 0, data: data.toString('base64')}}));
  const fin = await o.call('imports/upload-finish', {method: 'POST', json: {uploadId: up.uploadId, bankAccountId: BANK, mode: 'preview'}});
  const keys = (fin.b.preview?.rows || []).filter(x => x.eligible && !x.requiresDuplicateReview).map(x => x.selectionKey);
  need('approve', await o.call('operations/statement-approve', {method: 'POST', json: {previewId: fin.b.preview.id, operationKey: key('ap'), selectedKeys: keys, reviewedDuplicateKeys: [], balanceGapAcknowledged: true}}));
}
// Totals by customer and month, worked out by hand.
const INV = {maple: {Jul: 113000, Aug: 67800, Sep: 0}, birch: {Jul: 0, Aug: 50850, Sep: 226000}};
const sum = o2 => Object.values(o2).reduce((a, b) => a + b, 0);
const cols = out => out.columns.map(c => c.label);
const byLabel = (out, label) => out.rows.find(r => r.d0 === label);

try {
await t('RB-00', 'Switch off by default: the Report Builder is refused (403) and nothing is created', async () => {
  need('off', await setMode('off'));
  const has = () => sql(`SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='report_builder_reports'`);
  const before = has();
  const r = await o.call('report-builder/catalogue');
  const s2 = await o.call('report-builder/reports', {method: 'POST', json: {name: 'x', visibility: 'private', definition: {source: 'gl', mode: 'summary', rows: ['account'], values: ['debit'], period: Q3}, operationKey: key('off')}});
  // On a fresh install (the full gate) the table does not exist before or after.
  return {ok: r.s === 403 && r.b.code === 'report_builder_off' && s2.s === 403 && has() === before, info: `${r.s} ${r.b.code}; save ${s2.s}; table before ${before}, after ${has()}`};
});
need('on', await setMode('everyone'));

await t('RB-01', 'Sales invoices by customer, months side by side: every cell, the Total column and the grand totals match the invoices entered', async () => {
  const r = need('run', await run({source: 'invoices', mode: 'summary', rows: ['customer'], columns: 'period:month', values: ['total'], period: Q3})).output;
  const m = byLabel(r, 'R184 Maple ' + tag), b = byLabel(r, 'R184 Birch ' + tag);
  const want = [['Jul 2026', 'c0_m0'], ['Aug 2026', 'c1_m0'], ['Sep 2026', 'c2_m0']];
  const okCols = JSON.stringify(cols(r)) === JSON.stringify(['Customer', 'Jul 2026', 'Aug 2026', 'Sep 2026', 'Total']);
  const okCells = want.every(([lab, k], i) => m[k] === INV.maple[lab.slice(0, 3)] && b[k] === INV.birch[lab.slice(0, 3)]);
  const okTot = m.t_m0 === sum(INV.maple) && b.t_m0 === sum(INV.birch) && r.totals.c0_m0 === 113000 && r.totals.c1_m0 === 67800 + 50850 && r.totals.c2_m0 === 226000 && r.totals.t_m0 === 457650;
  return {ok: okCols && okCells && okTot && r.verifiedOutput?.reference && r.definitionKey === 'report-builder', info: `columns ${cols(r).join(' | ')}; Maple ${[m.c0_m0, m.c1_m0, m.c2_m0, m.t_m0]}; Birch ${[b.c0_m0, b.c1_m0, b.c2_m0, b.t_m0]}; totals ${JSON.stringify(r.totals)}`};
});

await t('RB-02', 'Change and % change columns between months; open balance after the payment; two values side by side', async () => {
  const r = need('run', await run({source: 'invoices', mode: 'summary', rows: ['customer'], columns: 'period:month', values: ['total', 'balance'], compare: 'change', period: Q3})).output;
  const p = need('run', await run({source: 'invoices', mode: 'summary', rows: ['customer'], columns: 'period:month', values: ['total'], compare: 'percent', period: Q3})).output;
  const m = byLabel(r, 'R184 Maple ' + tag), b = byLabel(r, 'R184 Birch ' + tag), mp = byLabel(p, 'R184 Maple ' + tag), bp = byLabel(p, 'R184 Birch ' + tag);
  // Change Aug−Jul and Sep−Aug; balance: Birch's Aug invoice is paid.
  const ok = m.x1_m0 === 67800 - 113000 && b.x1_m0 === 50850 && m.x2_m0 === -67800 && b.x2_m0 === 226000 - 50850
    && b.c1_m1 === 0 && b.t_m1 === 226000 && m.t_m1 === 113000 + 67800
    && mp.x1_m0 === Math.round((67800 - 113000) * 1000 / 113000) / 10 && bp.x1_m0 === null && mp.x2_m0 === -100;
  return {ok, info: `columns ${cols(r).join(' | ')}; Maple change ${m.x1_m0},${m.x2_m0}; Birch change ${b.x1_m0},${b.x2_m0}; balances M ${m.t_m1} B ${b.t_m1}; % Maple ${mp.x1_m0},${mp.x2_m0}; % Birch from zero ${bp.x1_m0}`};
});

await t('RB-03', 'General Ledger: debits and credits by account for the quarter equal the Trial Balance for every account, and debits equal credits', async () => {
  const r = need('run', await run({source: 'gl', mode: 'summary', rows: ['accountCode'], values: ['debit', 'credit'], period: Q3})).output;
  const tb = need('tb', await o.call(`professional-output/v5990/trial-balance?start=${Q3.start}&end=${Q3.end}`)).output;
  const mine = Object.fromEntries(r.rows.map(x => [x.d0, [x.c0_m0, x.c0_m1]]));
  const theirs = Object.fromEntries(tb.rows.filter(x => x.periodDebitCents || x.periodCreditCents).map(x => [x.accountCode, [x.periodDebitCents, x.periodCreditCents]]));
  const same = JSON.stringify(Object.entries(mine).sort()) === JSON.stringify(Object.entries(theirs).sort());
  return {ok: same && r.totals.c0_m0 === r.totals.c0_m1 && r.totals.c0_m0 === tb.totals.periodDebitCents && Object.keys(mine).length >= 6, info: `accounts ${Object.keys(mine).length}; builder D/C ${r.totals.c0_m0}/${r.totals.c0_m1}; TB ${tb.totals.periodDebitCents}/${tb.totals.periodCreditCents}; same per account ${same}`};
});

await t('RB-04', 'Sales tax lines: tax charged on sales 526.50 and paid on purchases 91.00, side by side by "Sales or purchases"', async () => {
  const r = need('run', await run({source: 'taxLines', mode: 'summary', rows: ['taxCode'], columns: 'side', values: ['tax', 'taxable'], period: Q3})).output;
  const on = byLabel(r, 'ON');
  const sales = hst(100000) + hst(60000) + hst(45000) + hst(200000), purch = hst(50000) + hst(20000);
  const labels = cols(r);
  const iSales = r.columns.findIndex(c => c.label.startsWith('Sales (tax charged) · Tax')), iPurch = r.columns.findIndex(c => c.label.startsWith('Purchases (tax paid) · Tax'));
  return {ok: !!on && iSales > 0 && iPurch > 0 && on[r.columns[iSales].key] === sales && on[r.columns[iPurch].key] === purch && sales === 52650 && purch === 9100, info: `columns ${labels.join(' | ')}; ON ${JSON.stringify(on)}`};
});

await t('RB-05', 'List with filter and sort: bills over 300.00 lists B-1 only; the bank lines give money in 508.50 and out 25.00', async () => {
  const l = need('list', await run({source: 'bills', mode: 'list', fields: ['number', 'date', 'vendor', 'total'], filters: [{field: 'total', op: 'gt', value: 30000}], period: Q3, sort: {key: 'total', dir: 'desc'}})).output;
  const b = need('bank', await run({source: 'bank', mode: 'summary', rows: ['bankAccount'], values: ['moneyIn', 'moneyOut', 'count'], period: Q3})).output;
  return {ok: l.rows.length === 1 && l.rows[0].number === 'B-1' && l.rows[0].total === 56500 && l.totals.total === 56500 && b.totals.c0_m0 === 50850 && b.totals.c0_m1 === 2500 && b.totals.c0_m2 === 2, info: `list ${JSON.stringify(l.rows)}; bank ${JSON.stringify(b.totals)}`};
});

await t('RB-06', 'Two row fields give a subtotal per first field; filters "is one of" and "contains"; quarter and financial-year columns', async () => {
  const r = need('run', await run({source: 'invoiceLines', mode: 'summary', rows: ['customer', 'description'], values: ['amount', 'tax'], period: Q3})).output;
  const sub = r.rows.filter(x => x.__rowType === 'subtotal').map(x => [x.d0, x.c0_m0, x.c0_m1]);
  const f = need('filter', await run({source: 'invoiceLines', mode: 'summary', rows: ['description'], values: ['amount'], period: Q3, filters: [{field: 'customer', op: 'in', value: ['R184 Birch ' + tag]}, {field: 'description', op: 'contains', value: 'proj'}]})).output;
  const q = need('quarter', await run({source: 'invoices', mode: 'summary', rows: ['status'], columns: 'period:quarter', values: ['total'], period: {start: '2026-01-01', end: '2026-12-31'}})).output;
  const fy = need('fy', await run({source: 'invoices', mode: 'summary', rows: [], columns: 'period:fy', values: ['total'], period: {start: '2026-01-01', end: '2026-12-31'}})).output;
  const okSub = JSON.stringify(sub) === JSON.stringify([['Subtotal: R184 Birch ' + tag, 245000, hst(45000) + hst(200000)], ['Subtotal: R184 Maple ' + tag, 160000, hst(100000) + hst(60000)]]);
  return {ok: okSub && f.rows.length === 1 && f.rows[0].d0 === 'Project' && f.rows[0].c0_m0 === 200000 && JSON.stringify(cols(q)) === JSON.stringify(['Status', 'Q1 2026', 'Q2 2026', 'Q3 2026', 'Q4 2026', 'Total']) && q.totals.c2_m0 === 457650 && fy.totals.t_m0 === 457650 && cols(fy).includes('FY 2026'),
    info: `subtotals ${JSON.stringify(sub)}; filtered ${JSON.stringify(f.rows)}; quarter ${cols(q).join(' | ')} ${JSON.stringify(q.totals)}; fy ${cols(fy).join(' | ')}`};
});

await t('RB-07', 'Refused safely: an unknown field, a field name with SQL in it, an unknown setting, a 61-column report, a wrong-type filter', async () => {
  const a = await run({source: 'gl', mode: 'summary', rows: ['nope'], values: ['debit'], period: Q3});
  const b = await run({source: 'gl', mode: 'summary', rows: ["account) UNION SELECT password_hash FROM users -- "], values: ['debit'], period: Q3});
  const c = await run({source: 'gl', mode: 'summary', rows: ['account'], values: ['debit'], period: Q3, sql: 'SELECT 1'});
  const d = await run({source: 'gl', mode: 'summary', rows: ['account'], columns: 'period:month', values: ['debit'], period: {start: '2020-01-01', end: '2026-12-31'}});
  const e = await run({source: 'invoices', mode: 'list', fields: ['number'], filters: [{field: 'total', op: 'gt', value: '1 OR 1=1'}], period: Q3});
  const codes = [a, b, c, d, e].map(x => `${x.s}:${x.b.code}`);
  return {ok: codes.join(',') === '422:report_builder_field_invalid,422:report_builder_field_invalid,422:report_builder_spec_invalid,422:report_builder_too_many_columns,422:report_builder_filter_invalid', info: codes.join(', ')};
});

const journalsBefore = () => sql(`SELECT COUNT(*) FROM journal_entries WHERE company_id='${CO}'`) + '/' + sql(`SELECT COALESCE(SUM(debit_cents),0) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id WHERE je.company_id='${CO}'`);
const DEF = {source: 'invoices', mode: 'summary', rows: ['customer'], columns: 'period:month', values: ['total'], period: Q3};
await t('RB-08', 'Saving: a report saves once even if the request is sent twice; the same key with a different report is refused; it reopens and runs for a new period', async () => {
  const before = journalsBefore();
  const k = key('save');
  const a = need('save', await o.call('report-builder/reports', {method: 'POST', json: {name: 'Sales by customer and month', description: 'Q3 check', visibility: 'private', definition: DEF, operationKey: k}}));
  const b = need('again', await o.call('report-builder/reports', {method: 'POST', json: {name: 'Sales by customer and month', description: 'Q3 check', visibility: 'private', definition: DEF, operationKey: k}}));
  const c = await o.call('report-builder/reports', {method: 'POST', json: {name: 'Something else', visibility: 'private', definition: DEF, operationKey: k}});
  const count = sql(`SELECT COUNT(*) FROM report_builder_reports WHERE company_id='${CO}'`);
  const list = need('list', await o.call('report-builder/reports')).reports;
  const r = need('run saved', await o.call('report-builder/run', {method: 'POST', json: {reportId: a.report.id, period: {start: '2026-08-01', end: '2026-08-31'}}})).output;
  const audit = sql(`SELECT COUNT(*) FROM audit_log WHERE company_id='${CO}' AND action='report_builder.saved' AND entity_id='${a.report.id}'`);
  ctx.saved = a.report;
  return {ok: b.idempotentReplay === true && b.report.id === a.report.id && c.s === 409 && c.b.code === 'idempotency_payload_conflict' && count === '1' && list.length === 1 && list[0].name === 'Sales by customer and month'
      && r.title === 'Sales by customer and month' && JSON.stringify(cols(r)) === JSON.stringify(['Customer', 'Aug 2026', 'Total']) && r.totals.t_m0 === 67800 + 50850 && audit === '1' && journalsBefore() === before,
    info: `replay ${b.idempotentReplay}; conflict ${c.s} ${c.b.code}; rows ${count}; list ${list.map(x => x.name)}; saved run ${cols(r).join(' | ')} ${r.totals.t_m0}; audit ${audit}; books unchanged ${journalsBefore() === before}`};
});

await t('RB-09', 'Editing: only its creator can change a report, and a stale revision is refused; renaming works', async () => {
  const id = ctx.saved.id;
  const ok1 = need('rename', await o.call('report-builder/reports/' + id, {method: 'PUT', json: {name: 'Sales by customer (Q3)', description: '', visibility: 'private', definition: DEF, revision: ctx.saved.revision}}));
  const stale = await o.call('report-builder/reports/' + id, {method: 'PUT', json: {name: 'Late edit', visibility: 'private', definition: DEF, revision: ctx.saved.revision}});
  return {ok: ok1.report.name === 'Sales by customer (Q3)' && ok1.report.revision === 2 && stale.s === 409 && stale.b.code === 'report_builder_revision_conflict', info: `renamed ${ok1.report.name} r${ok1.report.revision}; stale ${stale.s} ${stale.b.code}`};
});

await t('RB-10', 'Sharing in a company with several users: a viewer sees a shared report (not a private one), can run it but not change or delete it; an admin can delete it; another firm cannot reach it', async () => {
  const ids = JSON.parse((await import('fs')).readFileSync('/srv/gate/t/ids.json')); const BC = ids.BC;
  const ob = new S(); await ob.login('owner@gate.test', 'Gate!Owner#2026pw'); ob.cid = BC;
  const def = {source: 'gl', mode: 'summary', rows: ['accountType'], values: ['debit', 'credit'], period: {start: '2026-01-01', end: '2026-12-31'}};
  const shared = need('shared', await ob.call('report-builder/reports', {method: 'POST', json: {name: 'R184 shared ' + tag, visibility: 'company', definition: def, operationKey: key('sh')}})).report;
  const priv = need('private', await ob.call('report-builder/reports', {method: 'POST', json: {name: 'R184 private ' + tag, visibility: 'private', definition: def, operationKey: key('pr')}})).report;
  const v = new S(); await v.login('viewer@gate.test', 'Gate!Member#2026pw'); v.cid = BC;
  const vl = need('viewer list', await v.call('report-builder/reports')).reports.map(x => x.name);
  const vr = await v.call('report-builder/run', {method: 'POST', json: {reportId: shared.id}});
  const vp = await v.call('report-builder/run', {method: 'POST', json: {reportId: priv.id}});
  const ve = await v.call('report-builder/reports/' + shared.id, {method: 'PUT', json: {name: 'Hijack', visibility: 'company', definition: def, revision: shared.revision}});
  const vd = await v.call('report-builder/reports/' + shared.id, {method: 'DELETE'});
  const x = new S(); await x.login('otherowner@gate.test', 'Gate!Member#2026pw'); x.cid = ids.X || sql(`SELECT c.id FROM companies c JOIN company_members m ON m.company_id=c.id JOIN users u ON u.id=m.user_id WHERE u.email='otherowner@gate.test' LIMIT 1`);
  const xr = await x.call('report-builder/reports/' + shared.id);
  const xBC = new S(); await xBC.login('otherowner@gate.test', 'Gate!Member#2026pw'); xBC.cid = BC;
  const xb = await xBC.call('report-builder/reports/' + shared.id);
  const ad = new S(); await ad.login('admin@gate.test', 'Gate!Member#2026pw'); ad.cid = BC;
  const adDel = await ad.call('report-builder/reports/' + shared.id, {method: 'DELETE'});
  const gone = await ob.call('report-builder/reports/' + shared.id);
  need('cleanup', await ob.call('report-builder/reports/' + priv.id, {method: 'DELETE'}));
  return {ok: vl.includes('R184 shared ' + tag) && !vl.includes('R184 private ' + tag) && vr.s === 200 && vr.b.output?.title === 'R184 shared ' + tag && vp.s === 404 && ve.s === 403 && vd.s === 403 && xr.s === 404 && xb.s === 403 && adDel.s === 200 && gone.s === 404,
    info: `viewer sees ${vl.filter(n => n.includes(tag))}; viewer run shared ${vr.s} "${vr.b.output?.title}"; private ${vp.s}; edit ${ve.s}; delete ${vd.s}; other firm ${xr.s}; other firm with BC header ${xb.s}; admin delete ${adDel.s}; afterwards ${gone.s}`};
});

await t('RB-11', 'Values for filter pick-lists come only from this company; every run is sealed and audited; reports never change the books', async () => {
  const before = journalsBefore();
  const v = need('values', await o.call('report-builder/values?source=invoices&field=customer')).values.map(x => x.value);
  const runs = sql(`SELECT COUNT(*) FROM audit_log WHERE company_id='${CO}' AND action='professional_output.generated' AND JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.definitionKey'))='report-builder'`);
  return {ok: JSON.stringify(v.sort()) === JSON.stringify(['R184 Birch ' + tag, 'R184 Maple ' + tag].sort()) && Number(runs) >= 10 && journalsBefore() === before, info: `values ${v}; sealed runs audited ${runs}; books ${before} → ${journalsBefore()}`};
});

const pickCustom = async (p, start, end) => { await p.selectOption('[data-rb-form] select[name=preset]', 'custom'); await p.fill('[data-rb-form] input[name=start]', start); await p.fill('[data-rb-form] input[name=end]', end); await p.dispatchEvent('[data-rb-form] input[name=end]', 'change'); };
await t('RB-12', 'Browser: Reports › Report Builder builds sales by customer and month with the right totals; a figure opens the lines behind it; Excel, CSV and PDF download; save, reopen and delete from the page', async () => {
  const {b, p, errs} = await open(CO, {w: 1440, h: 900}); const got = {};
  try {
    got.menu = await p.evaluate(() => TeghPortal.getModuleNavigationSections ? true : true);
    await p.evaluate(() => TeghPortal.invokeMenuAction('Reports', 'Report Builder'));
    await p.waitForSelector('[data-rb-form] select[name=source]', {timeout: 30000});
    await p.selectOption('[data-rb-form] select[name=source]', 'invoices'); await p.waitForTimeout(300);
    await pickCustom(p, Q3.start, Q3.end);
    await p.click('[data-rb-run]'); await p.waitForSelector('[data-rb-table]', {timeout: 30000});
    got.head = await p.evaluate(() => [...document.querySelectorAll('[data-rb-table] thead th')].map(x => x.textContent.trim()));
    got.foot = await p.evaluate(() => [...document.querySelectorAll('[data-rb-table] tfoot td, [data-rb-table] tfoot th')].map(x => x.textContent.trim()));
    got.rowNames = await p.evaluate(() => [...document.querySelectorAll('[data-rb-table] tbody th')].map(x => x.textContent.trim()));
    // The Birch September figure: the lines behind it.
    const birchRow = await p.evaluate(tag => [...document.querySelectorAll('[data-rb-table] tbody tr')].findIndex(r => r.querySelector('th')?.textContent.includes('Birch ' + tag)), tag);
    await p.click(`[data-rb-table] tbody tr:nth-child(${birchRow + 1}) [data-rb-drill="c2_m0"]`); await p.waitForSelector('.rb-drill table', {timeout: 30000});
    got.drill = await p.evaluate(() => ({meta: document.querySelector('.rb-drill-body .rb-meta')?.textContent, rows: [...document.querySelectorAll('.rb-drill tbody tr')].map(r => r.innerText.replace(/\s+/g, ' '))}));
    await p.click('.rb-drill [data-rb-close]');
    for (const fmt of ['xlsx', 'csv', 'pdf']) {
      await p.click('[data-rb-download] > summary'); await p.waitForTimeout(200);
      const dl = p.waitForEvent('download', {timeout: 40000}).then(async d => ({name: d.suggestedFilename(), size: fs.statSync(await d.path()).size, text: fmt === 'csv' ? fs.readFileSync(await d.path(), 'utf8') : ''})).catch(() => null);
      await p.click(`[data-rb-export=${fmt}]`); got[fmt] = await dl;
    }
    // Save from the page, reopen it from the list, delete it.
    await p.click('[data-rb-saveas]'); await p.waitForSelector('.rb-save input[name=name]');
    await p.fill('.rb-save input[name=name]', 'Browser saved ' + tag); await p.click('.rb-save button[type=submit]');
    await p.waitForFunction(tag => [...document.querySelectorAll('[data-rb-saved] option')].some(o => o.textContent.includes('Browser saved ' + tag)), tag, {timeout: 20000});
    await p.click('[data-rb-new]'); await p.waitForTimeout(300);
    const id = await p.evaluate(tag => [...document.querySelectorAll('[data-rb-saved] option')].find(o => o.textContent.includes('Browser saved ' + tag)).value, tag);
    await p.selectOption('[data-rb-saved]', id); await p.waitForSelector('[data-rb-table]', {timeout: 30000}); await p.waitForTimeout(400);
    got.reopened = await p.evaluate(() => document.querySelector('[data-rb-result] h2')?.textContent.trim());
    await p.click('[data-rb-delete]'); await p.waitForSelector('.tegh-action-dialog button[type=submit]', {timeout: 15000}); await p.click('.tegh-action-dialog button[type=submit]');
    await p.waitForFunction(tag => ![...document.querySelectorAll('[data-rb-saved] option')].some(o => o.textContent.includes('Browser saved ' + tag)), tag, {timeout: 20000});
    got.deleted = sql(`SELECT deleted_at IS NOT NULL FROM report_builder_reports WHERE id='${id}'`);
    // A wide report (12 months × debit and credit) still downloads as PDF: each row is listed with its figures.
    await p.selectOption('[data-rb-form] select[name=source]', 'gl'); await p.waitForTimeout(300);
    await pickCustom(p, '2026-01-01', '2026-12-31');
    await p.click('[data-rb-run]'); await p.waitForFunction(() => document.querySelectorAll('[data-rb-table] thead th').length === 27, null, {timeout: 30000});
    await p.click('[data-rb-download] > summary'); await p.waitForTimeout(200);
    const wide = p.waitForEvent('download', {timeout: 40000}).then(async d => fs.statSync(await d.path()).size).catch(() => 0);
    await p.click('[data-rb-export=pdf]'); got.widePdf = await wide;
  } finally { got.errs = errs; await b.close(); }
  const csvOk = /R184 Maple/.test(got.csv?.text || '') && /1130\.00/.test(got.csv?.text || '') && /4576\.50/.test(got.csv?.text || '');
  const ok = JSON.stringify(got.head) === JSON.stringify(['Customer', 'Jul 2026', 'Aug 2026', 'Sep 2026', 'Total']) && JSON.stringify(got.foot) === JSON.stringify(['Total', '$1,130.00', '$1,186.50', '$2,260.00', '$4,576.50'])
    && got.rowNames.includes('R184 Birch ' + tag) && got.drill.rows.length === 1 && /2026-09-05/.test(got.drill.rows[0]) && /2,260\.00/.test(got.drill.rows[0])
    && got.xlsx?.size > 1000 && got.pdf?.size > 1000 && csvOk && got.reopened === 'Browser saved ' + tag && got.deleted === '1' && got.widePdf > 1000 && !got.errs.length;
  return {ok, info: JSON.stringify({...got, csv: got.csv ? {name: got.csv.name, size: got.csv.size, ok: csvOk} : null})};
});

await t('RB-13', 'Browser: hidden in Guided and when switched off; at phone width (390) the page has no sideways scroll and the result comes into view', async () => {
  const {b, p, errs} = await open(CO, {w: 390, h: 844}); const got = {};
  try {
    await p.evaluate(() => TeghPortal.invokeMenuAction('Reports', 'Report Builder'));
    await p.waitForSelector('[data-rb-form] select[name=source]', {timeout: 30000});
    await pickCustom(p, Q3.start, Q3.end);
    await p.click('[data-rb-run]'); await p.waitForSelector('[data-rb-table]', {timeout: 30000}); await p.waitForTimeout(900);
    got.sideways = await p.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
    got.inView = await p.evaluate(() => { const r = document.querySelector('[data-rb-result]').getBoundingClientRect(); return r.top < innerHeight && r.bottom > 0; });
    const inCatalogue = () => p.evaluate(() => { try { TeghPortal.openReport('Report Builder'); return 'listed'; } catch (e) { return 'not listed'; } });
    got.catalogueOn = await inCatalogue();
    need('off', await setMode('off'));
    await p.reload(); await p.waitForTimeout(3000);
    got.catalogueOff = await inCatalogue();
    got.offOpens = await p.evaluate(() => { try { TeghPortal.invokeMenuAction('Reports', 'Report Builder'); } catch (e) { return 'refused: ' + e.message; } return document.querySelector('[data-rb]') ? 'opened' : 'not opened'; });
    need('on again', await setMode('everyone'));
    await p.reload(); await p.waitForTimeout(3000);
    await p.evaluate(() => TeghPortal.switchBookkeepingMode('owner')); await p.waitForTimeout(2500);
    got.catalogueGuided = await inCatalogue();
    got.guided = await p.evaluate(() => { try { TeghPortal.invokeMenuAction('Reports', 'Report Builder'); } catch (e) { return 'refused: ' + e.message; } return document.querySelector('[data-rb]') ? 'opened' : 'not opened'; });
    await p.evaluate(() => TeghPortal.switchBookkeepingMode('accountant')); await p.waitForTimeout(2500);
  } finally { got.errs = errs.filter(e => !/Report Builder is off/.test(e)); await b.close(); }
  return {ok: !got.sideways && got.inView && got.catalogueOn === 'listed' && got.catalogueOff === 'not listed' && got.catalogueGuided === 'not listed' && !/^opened/.test(got.offOpens) && !/^opened/.test(got.guided) && !got.errs.length, info: JSON.stringify(got)};
});

await t('RB-14', 'Browser: Site & Release Settings has a Report Builder card; choosing "Platform owner only" there saves the switch', async () => {
  const {b, p, errs} = await open(CO, {w: 1440, h: 900}); const got = {};
  try {
    await p.evaluate(() => TeghPortal.openSiteReleaseSettings());
    await p.waitForSelector('[data-r184-builder]', {timeout: 30000});
    got.card = await p.evaluate(() => document.querySelector('[data-r184-builder-card] h2')?.textContent.trim());
    await p.check('[data-r184-builder] input[value=owner]');
    const reason = await p.$('[data-r184-builder] [name=reason]'); if (reason) await reason.fill('R184 gate: owner only');
    await p.click('[data-r184-builder] button[type=submit]');
    await p.waitForFunction(() => /saved|updated/i.test(document.querySelector('[data-r184-builder] [data-status]')?.textContent || ''), null, {timeout: 20000}).catch(() => {});
    await p.waitForTimeout(800);
    got.stored = sql(`SELECT JSON_UNQUOTE(JSON_EXTRACT(value_json,'$.reportBuilder')) FROM site_settings WHERE setting_group='release'`);
    got.status = await p.evaluate(() => document.querySelector('[data-r184-builder] [data-status]')?.textContent.trim());
  } finally { got.errs = errs; await b.close(); }
  need('back on', await setMode('everyone'));
  return {ok: /Report Builder/i.test(got.card || '') && got.stored === 'owner' && !got.errs.length, info: JSON.stringify(got)};
});

await t('RB-15', 'Backup and restore: the company copy keeps its saved report, which runs to the same totals; the original is untouched', async () => {
  o.cid = CO;
  const savedBefore = sql(`SELECT GROUP_CONCAT(CONCAT(name,'|',visibility) ORDER BY name) FROM report_builder_reports WHERE company_id='${CO}' AND deleted_at IS NULL`);
  const res = await o.call('backup/export', {method: 'POST', json: {}, raw: true}); const buf = Buffer.from(await res.arrayBuffer());
  const fd = new FormData(); fd.append('backup', new Blob([buf]), 'r184.tegh'); for (const [k, v] of [['companyName', 'R184 Restored ' + tag], ['confirm', 'RESTORE'], ['confirmation', 'RESTORE'], ['mode', 'new']]) fd.append(k, v);
  const r = await o.call('backup/restore', {method: 'POST', body: fd});
  const NEW = r.b?.companyId;
  const savedAfter = NEW ? sql(`SELECT GROUP_CONCAT(CONCAT(name,'|',visibility) ORDER BY name) FROM report_builder_reports WHERE company_id='${NEW}' AND deleted_at IS NULL`) : '';
  let total = null;
  if (NEW) { o.cid = NEW; const list = need('list', await o.call('report-builder/reports')).reports; const rep = list.find(x => x.name === 'Sales by customer (Q3)'); if (rep) total = need('run', await o.call('report-builder/run', {method: 'POST', json: {reportId: rep.id}})).output.totals.t_m0; o.cid = CO; }
  const orig = sql(`SELECT GROUP_CONCAT(CONCAT(name,'|',visibility) ORDER BY name) FROM report_builder_reports WHERE company_id='${CO}' AND deleted_at IS NULL`);
  return {ok: res.status === 200 && r.s < 300 && !!NEW && savedAfter === savedBefore && total === 457650 && orig === savedBefore, info: `export ${res.status}; restore ${r.s} ${r.b?.code || ''}; saved before "${savedBefore}" after "${savedAfter}"; restored report total ${total}; original "${orig}"`};
});
} finally {
  await setMode(startMode);
  save('r184.json');
}

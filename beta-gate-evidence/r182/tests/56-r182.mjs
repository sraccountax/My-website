// R182: correcting issued customer invoices (A), plus checks of the R182 screens (B–F) in later sections.
// Every expected amount is worked out here (price × quantity, 13% HST), never read back from Tegh first.
// The suite creates its own synthetic Ontario companies, turns "Edit issued invoices" on for the platform owner,
// and puts the switch back the way it was at the end.
import {S, sql, rec, save, need} from './lib.mjs';
import {open} from './e2e/base.mjs';
import fs from 'fs';

const A = 'R182';
const t = async (id, title, fn) => { try { const r = await fn(); rec(id, A, title, r.ok ? 'PASS' : 'FAIL', r.info); return r.ok; } catch (e) { rec(id, A, title, 'FAIL', String(e.message || e).slice(0, 400)); return false; } };
const o = new S(); await o.login('owner@gate.test', 'Gate!Owner#2026pw');
const release = async () => (await o.call('site-admin', {company: false})).b.groups.release;
const setMode = async m => { const cur = await release(); return o.call('site-admin/save', {method: 'POST', company: false, json: {groups: {release: {value: {...cur.value, editIssuedInvoices: m}, revision: cur.revision}}, reason: 'R182 gate ' + m}}); };
const startMode = (await release()).value.editIssuedInvoices || 'off';
const tag = Date.now().toString(36);
let n = 0; const key = p => `r182-${p}-${tag}-${++n}`.padEnd(20, 'x');
const base = {businessType: 'corporation', country: 'Canada', province: 'ON', taxRegistered: true, taxNumber: '123456789RT0001', moduleMode: 'accounting', fiscalYearEnd: '12-31', booksStartDate: '2026-01-01', chartTemplate: 'default', currency: 'CAD'};
const mkCompany = async (label, extra = {}) => need('company ' + label, await o.call('companies', {method: 'POST', json: {...base, name: `R182 ${label} ${tag}`, accountingBasis: 'accrual', ...extra}})).company.id;
const glBy = cid => Object.fromEntries(sql(`SELECT a.code, SUM(jl.debit_cents-jl.credit_cents) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id AND je.status='posted' JOIN accounts a ON a.id=jl.account_id WHERE je.company_id='${cid}' GROUP BY a.code`).split('\n').filter(Boolean).map(l => { const [c, v] = l.split('\t'); return [c, Number(v)]; }));
const glAt = (cid, from, to) => Object.fromEntries(sql(`SELECT a.code, SUM(jl.debit_cents-jl.credit_cents) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id AND je.status='posted' JOIN accounts a ON a.id=jl.account_id WHERE je.company_id='${cid}' AND je.entry_date BETWEEN '${from}' AND '${to}' GROUP BY a.code`).split('\n').filter(Boolean).map(l => { const [c, v] = l.split('\t'); return [c, Number(v)]; }));
const balanced = cid => Number(sql(`SELECT COALESCE(SUM(jl.debit_cents-jl.credit_cents),0) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id AND je.status='posted' WHERE je.company_id='${cid}'`)) === 0;
// AR control must equal open invoices minus unapplied customer receipts plus refunds paid out.
const arSub = cid => Number(sql(`SELECT (SELECT COALESCE(SUM(balance_cents),0) FROM invoices WHERE company_id='${cid}' AND status IN ('sent','paid'))
  - (SELECT COALESCE(SUM(amount_cents-applied_cents),0) FROM party_payments WHERE company_id='${cid}' AND payment_type='customer' AND flow_kind='customer_receipt' AND status='posted')
  + (SELECT COALESCE(SUM(amount_cents),0) FROM party_payments WHERE company_id='${cid}' AND payment_type='customer' AND flow_kind='customer_refund' AND status='posted')`));
const integrity = cid => { const g = glBy(cid); return {balanced: balanced(cid), ar: g['1200'] || 0, sub: arSub(cid), ok: balanced(cid) && (g['1200'] || 0) === arSub(cid)}; };

let CO, CUST, CUST2, TMPL, BANK;
const ctx = {};
const invoice = async (lines, date = '2026-09-10') => need('invoice', await o.call('invoices', {method: 'POST', json: {customerId: CUST, issueDate: date, dueDate: '2026-10-30', currency: 'CAD', templateId: TMPL, issue: true, lines}})).invoice;
const detail = async id => need('detail', await o.call('invoices/r20/detail?invoiceId=' + id));
const pay = async (id, cents, date = '2026-09-20') => need('pay', await o.call('payments', {method: 'POST', json: {type: 'customer', documentId: id, paymentDate: date, reference: 'EFT ' + n, foreignAmountCents: cents, paymentAccountId: BANK, operationKey: key('pay')}}));
// The editor sends the invoice as the screen does: every field, with the changed lines.
const body = async (id, change = {}) => { const d = await detail(id); const i = d.invoice; return {invoiceId: id, expectedRevision: d.revision, customerId: i.customerId, issueDate: i.issueDate, dueDate: i.dueDate, currency: i.currency, exchangeRateMicros: i.exchangeRateMicros, templateId: i.templateId, purchaseOrder: i.purchaseOrder || '', message: i.message || '', lines: d.lines.map(l => ({description: l.description, quantity: l.quantity, unitPriceCents: l.unitPriceCents, taxable: l.taxable})), ...change}; };
const preview = async b => o.call('invoices/correction/preview', {method: 'POST', json: b});
const correct = async (b, reason = 'Wrong quantity on line 1', k = key('fix')) => o.call('invoices/correction', {method: 'POST', json: {...b, reason, operationKey: k}});
const row = id => Object.fromEntries(Object.entries(JSON.parse(sql(`SELECT JSON_OBJECT('number',number,'status',status,'total',total_cents,'tax',tax_cents,'balance',balance_cents,'date',issue_date,'entry',issued_journal_entry_id) FROM invoices WHERE id='${id}'`))));
const line = (desc, qty, price) => ({description: desc, quantity: qty, unitPriceCents: price, taxable: true});
const hst = c => Math.round(c * 0.13);

try {
await t('IE-00', 'Setup: Ontario HST company, customer, bank; switch off by default refuses a correction', async () => {
  CO = await mkCompany('Ontario'); o.cid = CO;
  CUST = need('customer', await o.call('customers', {method: 'POST', json: {name: 'R182 Maple Ltd ' + tag, email: '', phone: '', billingAddress: '1 King St', country: 'Canada', province: 'ON'}})).customer?.id;
  CUST2 = need('customer2', await o.call('customers', {method: 'POST', json: {name: 'R182 Birch Inc ' + tag, email: '', phone: '', billingAddress: '2 Queen St', country: 'Canada', province: 'ON'}})).customer?.id;
  TMPL = (await o.call('invoice-templates')).b.templates?.[0]?.id;
  BANK = (await o.call('payments?type=customer')).b.paymentAccounts.find(a => !a.undeposited)?.id;
  const cur = startMode; await setMode('off');
  const inv = await invoice([line('Consulting', 10, 10000)]);
  ctx.off = inv.id;
  const r = await preview(await body(inv.id, {lines: [line('Consulting', 8, 10000)]}));
  const d = await detail(inv.id);
  return {ok: !!(CUST && CUST2 && BANK) && cur === startMode && r.s === 403 && r.b.code === 'invoice_correction_unavailable' && d.capabilities.correct === undefined && d.correction === undefined, info: `start ${cur}; ids ${[!!CUST, !!CUST2, !!TMPL, !!BANK]}; off → ${r.s} ${r.b.code}; detail correct=${d.capabilities.correct} correction=${JSON.stringify(d.correction)}`};
});

await t('IE-01', 'Unpaid invoice: 10 × 100 + HST corrected to 8 × 100: original reversed on its date, corrected entry posted, same number, AR 904', async () => {
  need('owner', await setMode('owner'));
  const inv = await invoice([line('Consulting', 10, 10000)]); ctx.i1 = inv.id;
  const before = row(inv.id); const glB = glBy(CO);
  const b = await body(inv.id, {lines: [line('Consulting', 8, 10000)]});
  const p = need('preview', await preview(b)).preview;
  const r = await correct(b);
  const after = row(inv.id); const glA = glBy(CO);
  const want = 80000 + hst(80000);
  const rev = sql(`SELECT CONCAT(entry_date,'|',source_type,'|',reversal_of_id) FROM journal_entries WHERE company_id='${CO}' AND reversal_of_id='${before.entry}'`);
  const hist = sql(`SELECT CONCAT(revision_number,'|',before_total_cents,'|',after_total_cents,'|',reason) FROM invoice_revisions WHERE invoice_id='${inv.id}'`);
  const audit = sql(`SELECT COUNT(*) FROM audit_log WHERE company_id='${CO}' AND action='invoice.corrected' AND entity_id='${inv.id}'`);
  const ok = p.canSave && p.after.totalCents === want && p.entries.reversal.length >= 3 && p.entries.corrected.length >= 3 && r.s === 200
    && after.number === before.number && after.total === want && after.balance === want && after.status === 'sent' && after.entry !== before.entry
    && rev === `2026-09-10|invoice_correction_reversal|${before.entry}` && glA['1200'] - glB['1200'] === want - 113000 && glA['2100'] - glB['2100'] === -(hst(80000) - 13000)
    && hist === `1|113000|${want}|Wrong quantity on line 1` && audit === '1' && integrity(CO).ok;
  return {ok, info: `preview ${p.before.totalCents}→${p.after.totalCents}; save ${r.s} ${r.b.error || ''}; ${before.number}→${after.number} total ${after.total} bal ${after.balance}; reversal ${rev}; AR Δ ${glA['1200'] - glB['1200']}; history ${hist}; audit ${audit}; ${JSON.stringify(integrity(CO))}`};
});

await t('IE-02', 'Reason required; same request twice saves once; a stale revision is refused', async () => {
  const b = await body(ctx.i1, {lines: [line('Consulting', 9, 10000)]});
  const noReason = await correct(b, '');
  const k = key('rep'); const r1 = await correct(b, 'Nine hours, not eight', k); const r2 = await correct(b, 'Nine hours, not eight', k);
  const stale = await correct(b, 'Again', key('stale'));
  const count = sql(`SELECT COUNT(*) FROM invoice_revisions WHERE invoice_id='${ctx.i1}'`);
  return {ok: noReason.s === 422 && noReason.b.code === 'invoice_correction_reason_required' && r1.s === 200 && r2.s === 200 && r2.b.correction?.replayed === true && count === '2' && stale.s === 409 && stale.b.code === 'invoice_revision_conflict' && integrity(CO).ok,
    info: `no reason ${noReason.s}; first ${r1.s}; repeat ${r2.s} replayed ${r2.b.correction?.replayed}; revisions ${count}; stale ${stale.s} ${stale.b.code}`};
});

await t('IE-03', 'Date moved to August: reversal on 10 Sep, corrected entry on 20 Aug; September and August figures move accordingly', async () => {
  const inv = await invoice([line('Design', 1, 50000)]); ctx.i3 = inv.id;
  const augB = glAt(CO, '2026-08-01', '2026-08-31'), sepB = glAt(CO, '2026-09-01', '2026-09-30');
  const r = await correct(await body(inv.id, {issueDate: '2026-08-20', dueDate: '2026-09-30'}), 'Work was done in August');
  const augA = glAt(CO, '2026-08-01', '2026-08-31'), sepA = glAt(CO, '2026-09-01', '2026-09-30');
  const d = (a, b, c) => (a[c] || 0) - (b[c] || 0);
  return {ok: r.s === 200 && d(augA, augB, '4000') === -50000 && d(augA, augB, '2100') === -hst(50000) && d(sepA, sepB, '4000') === 50000 && d(sepA, sepB, '2100') === hst(50000) && row(inv.id).date === '2026-08-20' && integrity(CO).ok,
    info: `save ${r.s} ${r.b.error || ''}; Aug revenue Δ ${d(augA, augB, '4000')}, HST Δ ${d(augA, augB, '2100')}; Sep revenue Δ ${d(sepA, sepB, '4000')}`};
});

await t('IE-04', 'Paid invoice corrected higher: re-opens with the difference to collect', async () => {
  const inv = await invoice([line('Support', 2, 25000)]); const total = 50000 + hst(50000);
  await pay(inv.id, total);
  const r = await correct(await body(inv.id, {lines: [line('Support', 3, 25000)]}), 'Third month of support');
  const want = 75000 + hst(75000); const a = row(inv.id);
  return {ok: r.s === 200 && a.status === 'sent' && a.total === want && a.balance === want - total && r.b.correction.customerCreditCents === 0 && integrity(CO).ok, info: `save ${r.s} ${r.b.error || ''}; ${a.status} total ${a.total} balance ${a.balance} (want ${want - total})`};
});

await t('IE-05', 'Paid invoice corrected lower: invoice stays paid at the new total; the difference is a customer credit on the payment', async () => {
  const inv = await invoice([line('Hosting', 1, 100000)]); ctx.i5 = inv.id; const total = 100000 + hst(100000);
  const p = await pay(inv.id, total); ctx.p5 = p.payment?.id || sql(`SELECT id FROM party_payments WHERE document_id='${inv.id}' LIMIT 1`);
  const b = await body(inv.id, {lines: [line('Hosting', 1, 80000)]});
  const pv = need('preview', await preview(b)).preview;
  const r = await correct(b, 'Discount agreed after payment');
  const want = 80000 + hst(80000); const credit = total - want; const a = row(inv.id);
  const pays = (await o.call('payments?type=customer')).b.payments.find(x => x.id === ctx.p5);
  const bankLine = sql(`SELECT amount_cents FROM party_payments WHERE id='${ctx.p5}'`);
  return {ok: pv.customerCreditCents === credit && r.s === 200 && a.status === 'paid' && a.total === want && a.balance === 0 && pays?.remainingCents === credit && pays?.unapplied === true && Number(bankLine) === total && integrity(CO).ok,
    info: `preview credit ${pv.customerCreditCents}; save ${r.s} ${r.b.error || ''}; invoice ${a.status} ${a.total}/${a.balance}; payment remaining ${pays?.remainingCents} (want ${credit}); payment amount unchanged ${bankLine}; ${JSON.stringify(integrity(CO))}`};
});

await t('IE-06', 'The customer credit can be applied to another invoice and refunded with the existing screens', async () => {
  const credit = (100000 + hst(100000)) - (80000 + hst(80000));
  const other = await invoice([line('Extra', 1, 5000)]); const otherTotal = 5000 + hst(5000);
  const ap = await o.call('payments', {method: 'PATCH', json: {action: 'apply', applicationDate: '2026-09-25', operationKey: key('apply'), allocations: [{paymentId: ctx.p5, documentId: other.id, foreignAmountCents: otherTotal}]}});
  const left = credit - otherTotal;
  const rf = await o.call('payments', {method: 'POST', json: {type: 'customer', flowKind: 'customer_refund', partyId: CUST, paymentDate: '2026-09-26', reference: 'Refund credit', foreignAmountCents: left, paymentAccountId: BANK, currency: 'CAD', operationKey: key('refund')}});
  return {ok: ap.s === 200 && row(other.id).status === 'paid' && rf.s === 201 && integrity(CO).ok, info: `apply ${ap.s} ${ap.b.error || ''}; other invoice ${row(other.id).status}; refund ${left}: ${rf.s} ${rf.b.error || ''}; ${JSON.stringify(integrity(CO))}`};
});

await t('IE-07', 'Partly paid invoice, both directions: balance follows the new total; below the paid part gives a credit', async () => {
  const inv = await invoice([line('Retainer', 1, 100000)]); const total = 100000 + hst(100000);
  await pay(inv.id, 50000);
  const r1 = await correct(await body(inv.id, {lines: [line('Retainer', 1, 90000)]}), 'Lower retainer');
  const t1 = 90000 + hst(90000); const a1 = row(inv.id);
  const r2 = await correct(await body(inv.id, {lines: [line('Retainer', 1, 40000)]}), 'Retainer cancelled in part');
  const t2 = 40000 + hst(40000); const a2 = row(inv.id);
  return {ok: r1.s === 200 && a1.balance === t1 - 50000 && a1.status === 'sent' && r2.s === 200 && a2.balance === 0 && a2.status === 'paid' && r2.b.correction.customerCreditCents === 50000 - t2 && integrity(CO).ok,
    info: `${total}→${t1}: bal ${a1.balance}; →${t2}: ${a2.status} credit ${r2.b.correction?.customerCreditCents} (want ${50000 - t2}); ${JSON.stringify(integrity(CO))}`};
});

await t('IE-08', 'Refused: locked original period, locked new date, linked credit note, customer change on a paid invoice, date after its payment', async () => {
  const out = {};
  const locked = await invoice([line('Old work', 1, 10000)], '2026-06-15');
  const late = await invoice([line('Recent', 1, 10000)], '2026-09-12');
  need('lock', await o.call('period-close', {method: 'POST', json: {closedThroughDate: '2026-06-30'}}));
  out.lockedOld = (await correct(await body(locked.id, {lines: [line('Old work', 2, 10000)]}), 'x')).b.code;
  out.lockedNew = (await correct(await body(late.id, {issueDate: '2026-06-20'}), 'x')).b.code;
  need('unlock', await o.call('period-close', {method: 'POST', json: {closedThroughDate: ''}}));
  const noted = await invoice([line('Goods', 1, 20000)]);
  const cn = await o.call('accounting-notes', {method: 'POST', json: {kind: 'customer_credit', sourceId: noted.id, date: '2026-09-20', subtotalCents: 5000, taxCents: hst(5000), memo: 'Return', operationKey: key('cn'), lines: [{description: 'Return', quantityMilli: 1000, foreignUnitPriceCents: 5000}]}});
  out.note = (await correct(await body(noted.id, {lines: [line('Goods', 1, 15000)]}), 'x')).b.code;
  const paid = await invoice([line('Paid work', 1, 10000)]); await pay(paid.id, 10000 + hst(10000), '2026-09-15');
  out.customer = (await correct(await body(paid.id, {customerId: CUST2}), 'x')).b.code;
  out.afterPayment = (await correct(await body(paid.id, {issueDate: '2026-09-18', dueDate: '2026-10-30'}), 'x')).b.code;
  const pv = (await preview(await body(paid.id, {customerId: CUST2}))).b.preview;
  return {ok: out.lockedOld === 'period_locked' && out.lockedNew === 'period_locked' && cn.s < 300 && out.note === 'invoice_correction_linked_note' && out.customer === 'invoice_correction_customer_paid' && out.afterPayment === 'invoice_correction_date_after_payment' && pv?.canSave === false && pv.blockers[0]?.instead && integrity(CO).ok,
    info: JSON.stringify(out) + ` note created ${cn.s}; preview blocker "${pv?.blockers?.[0]?.message}"`};
});

await t('IE-09', 'Cash-basis company: an unpaid invoice can be corrected (no entries); a paid one is refused', async () => {
  const cash = await mkCompany('Cash', {accountingBasis: 'cash'}); const keep = o.cid; o.cid = cash;
  const cc = need('customer', await o.call('customers', {method: 'POST', json: {name: 'R182 Cash Cust ' + tag, email: '', phone: '', billingAddress: '3 Bay St', country: 'Canada', province: 'ON'}})).customer.id;
  const tm = (await o.call('invoice-templates')).b.templates?.[0]?.id; const bank = (await o.call('payments?type=customer')).b.paymentAccounts.find(a => !a.undeposited)?.id;
  const mk = async () => need('inv', await o.call('invoices', {method: 'POST', json: {customerId: cc, issueDate: '2026-09-10', dueDate: '2026-10-30', currency: 'CAD', templateId: tm, issue: true, lines: [line('Cash work', 1, 30000)]}})).invoice.id;
  const u = await mk(); const entriesBefore = sql(`SELECT COUNT(*) FROM journal_entries WHERE company_id='${cash}'`);
  const b1 = await body(u, {lines: [line('Cash work', 1, 20000)]}); const r1 = await correct(b1, 'Lower price');
  const entriesAfter = sql(`SELECT COUNT(*) FROM journal_entries WHERE company_id='${cash}'`);
  const p = await mk(); need('pay', await o.call('payments', {method: 'POST', json: {type: 'customer', documentId: p, paymentDate: '2026-09-20', reference: 'cash', foreignAmountCents: 30000 + hst(30000), paymentAccountId: bank, operationKey: key('cpay')}}));
  const r2 = await correct(await body(p, {lines: [line('Cash work', 1, 20000)]}), 'x');
  o.cid = keep;
  return {ok: r1.s === 200 && row(u).total === 20000 + hst(20000) && entriesBefore === entriesAfter && r2.s === 409 && r2.b.code === 'invoice_correction_cash_paid', info: `unpaid ${r1.s} total ${row(u).total}, entries ${entriesBefore}→${entriesAfter}; paid ${r2.s} ${r2.b.code}`};
});

await t('IE-10', 'History lists each correction with who, when, reason, before and after; detail shows the count', async () => {
  const h = need('history', await o.call('invoices/correction/history?invoiceId=' + ctx.i1)).revisions;
  const d = await detail(ctx.i1);
  return {ok: h.length === 2 && h[0].number === 2 && h[0].reason === 'Nine hours, not eight' && h[1].beforeTotalCents === 113000 && !!h[0].by && !!h[0].at && h[0].before?.lines?.length === 1 && d.correction?.count === 2 && d.capabilities.correct === true,
    info: `revisions ${h.map(x => `${x.number}:${x.beforeTotalCents}→${x.afterTotalCents} by ${x.by}`).join(', ')}; detail count ${d.correction?.count} correct ${d.capabilities.correct}`};
});

await t('IE-11', 'Permissions: a viewer and an editor (no reverse permission) are refused; another company cannot reach the invoice', async () => {
  const pw = 'Gate!Member#2026pw'; const res = {}; need('everyone', await setMode('everyone'));
  for (const role of ['viewer', 'editor']) {
    const uid = sql(`SELECT id FROM users WHERE email='${role}@gate.test'`);
    sql(`INSERT INTO company_members(company_id,user_id,role,status) VALUES('${CO}','${uid}','${role}','active') ON DUPLICATE KEY UPDATE role='${role}',status='active'`);
    const u = new S(); await u.login(`${role}@gate.test`, pw); u.cid = CO;
    const b = await body(ctx.i1, {lines: [line('Consulting', 7, 10000)]});
    const p = await u.call('invoices/correction/preview', {method: 'POST', json: b});
    const c = await u.call('invoices/correction', {method: 'POST', json: {...b, reason: 'x', operationKey: key(role)}});
    const d = (await u.call('invoices/r20/detail?invoiceId=' + ctx.i1)).b;
    res[role] = [p.s, c.s, c.b.code, d.capabilities?.correct, !!d.correction?.reason];
  }
  need('owner', await setMode('owner'));
  const other = await mkCompany('Other'); const keep = o.cid;
  const b = await body(ctx.i1, {lines: [line('Consulting', 7, 10000)]}); o.cid = other;
  const cross = await correct(b, 'cross'); o.cid = keep;
  const unchanged = sql(`SELECT COUNT(*) FROM invoice_revisions WHERE invoice_id='${ctx.i1}'`) === '2';
  return {ok: res.viewer[0] === 403 && res.viewer[1] === 403 && res.editor[0] === 403 && res.editor[1] === 403 && res.editor[3] === false && res.editor[4] && cross.s === 404 && cross.b.code === 'invoice_unavailable' && unchanged,
    info: `viewer ${JSON.stringify(res.viewer)}; editor ${JSON.stringify(res.editor)}; other company ${cross.s} ${cross.b.code}; revisions unchanged ${unchanged}`};
});

await t('IE-12', 'Integrity after all cases: every entry balances; AR control equals the customer ledger', async () => {
  const i = integrity(CO); const unbalanced = sql(`SELECT COUNT(*) FROM (SELECT je.id FROM journal_entries je JOIN journal_lines jl ON jl.journal_entry_id=je.id WHERE je.company_id='${CO}' GROUP BY je.id HAVING SUM(jl.debit_cents)<>SUM(jl.credit_cents)) x`);
  return {ok: i.ok && unbalanced === '0', info: `${JSON.stringify(i)}; unbalanced entries ${unbalanced}`};
});

// ---------- A on screen ----------
await t('IE-13', 'Screens: Correct invoice button, editor in correction mode, review with before/after and entries, reason required, Corrected tag and History; no page errors', async () => {
  const inv = await invoice([line('Workshop', 4, 25000)], '2026-09-14');
  const {b, p, errs} = await open(CO, {w: 1440, h: 900}); const got = {};
  try {
    await p.evaluate(id => TeghPortal.openInvoiceDetails(id), inv.id);
    await p.waitForSelector('[data-r182-correct]', {timeout: 30000});
    await p.click('[data-r182-correct]'); await p.waitForSelector('[data-r182-review]', {timeout: 20000}); await p.waitForTimeout(500);
    got.title = await p.textContent('[data-r151-title]');
    const q = p.locator('[data-r151-body] [name=qty]').first(); await q.fill('3'); await q.dispatchEvent('input');
    await p.click('[data-r182-review]'); await p.waitForSelector('.r182-review [data-r182-save]', {timeout: 20000});
    got.total = await p.evaluate(() => [...document.querySelectorAll('.r182-compare tr')].find(r => /Total/.test(r.textContent))?.textContent.replace(/\s+/g, ' ').trim());
    got.entries = await p.locator('.r182-review .r182-entry').count();
    await p.click('.r182-review [data-r182-save]'); await p.waitForTimeout(300); got.need = await p.textContent('[data-r182-error]');
    // The window's buttons are on top (not hidden behind the page).
    got.onTop = await p.evaluate(() => { const b = document.querySelector('.r182-review [data-r182-save]').getBoundingClientRect(); const el = document.elementFromPoint(b.x + b.width / 2, b.y + b.height / 2); return !!el?.closest('[data-r182-save]'); });
    await p.fill('.r182-review [name=reason]', 'Three people attended, not four'); await p.click('.r182-review [data-r182-save]');
    await p.waitForSelector('[data-r182-tag]', {timeout: 30000}); got.tag = await p.textContent('[data-r182-tag]');
    await p.click('[data-r182-history]'); await p.waitForSelector('.r182-history'); got.history = await p.evaluate(() => document.querySelector('.r182-history li')?.textContent.includes('Three people attended'));
    await p.screenshot({path: '/srv/gate/ev/shots/r182-gate-correct.png'});
  } finally { got.errs = errs; await b.close(); }
  const want = 75000 + hst(75000);
  return {ok: /Correct Invoice/i.test(got.title) && got.total?.includes('$1,130.00') && got.total?.includes('$847.50') && got.entries === 2 && /reason/i.test(got.need || '') && got.onTop && /Corrected/.test(got.tag || '') && got.history && row(inv.id).total === want && !got.errs.length && integrity(CO).ok,
    info: JSON.stringify(got) + ` saved total ${row(inv.id).total}`};
});

// ---------- D, E: bank lines entered by hand, description edits ----------
const BANKACC = () => sql(`SELECT id FROM bank_accounts WHERE company_id='${CO}' AND account_type='bank' AND active=1 ORDER BY created_at LIMIT 1`);
await t('BK-01', 'Add a bank line by hand, repeated monthly: 31 Jul → 31 Aug → 30 Sep; the same request again adds nothing; future months and zero are refused', async () => {
  const bank = BANKACC(); const k = key('bk');
  const r = await o.call('bank-transactions/add', {method: 'POST', json: {bankAccountId: bank, date: '2026-07-31', description: 'Office rent Main St', reference: 'RENT', amountCents: -250000, repeatMonths: 3, operationKey: k}});
  const r2 = await o.call('bank-transactions/add', {method: 'POST', json: {bankAccountId: bank, date: '2026-07-31', description: 'Office rent Main St', reference: 'RENT', amountCents: -250000, repeatMonths: 3, operationKey: k}});
  const fut = await o.call('bank-transactions/add', {method: 'POST', json: {bankAccountId: bank, date: '2026-09-15', description: 'x', amountCents: -100, repeatMonths: 6, operationKey: key('bkf')}});
  const zero = await o.call('bank-transactions/add', {method: 'POST', json: {bankAccountId: bank, date: '2026-09-01', description: 'x', amountCents: 0, operationKey: key('bkz')}});
  const rows = sql(`SELECT GROUP_CONCAT(CONCAT(transaction_date,':',amount_cents,':',status) ORDER BY transaction_date) FROM bank_transactions WHERE company_id='${CO}' AND description='Office rent Main St'`);
  return {ok: r.s === 201 && (r.b.transactions || []).map(x => x.date).join() === '2026-07-31,2026-08-31,2026-09-30' && r2.s === 201 && r2.b.transactions.every(x => x.replayed) && rows.split(',').length === 3 && rows.includes('pending') && fut.s === 422 && zero.s === 422,
    info: `add ${r.s} ${(r.b.transactions || []).map(x => x.date)}; again replayed ${r2.b.transactions?.map(x => x.replayed)}; rows ${rows}; future ${fut.s} ${fut.b.code}; zero ${zero.s}`};
});

await t('BK-02', 'Statement import: a line matching a hand-entered one is flagged as a possible duplicate and left out; an edited description is imported as edited', async () => {
  const bank = BANKACC(); const k = key('imp');
  const csv = `Date,Description,Amount\n2026-08-02,PREAUTH RENT MAIN ST ${k},-2500.00\n2026-09-03,COFFEE SHOP ${k},-4.50\n`; const data = Buffer.from(csv);
  const up = need('start', await o.call('imports/upload-start', {method: 'POST', json: {filename: k + '.csv', size: data.length}}));
  need('chunk', await o.call('imports/upload-chunk', {method: 'POST', json: {uploadId: up.uploadId, offset: 0, data: data.toString('base64')}}));
  const fin = await o.call('imports/upload-finish', {method: 'POST', json: {uploadId: up.uploadId, bankAccountId: bank, mode: 'preview'}});
  const rows = fin.b.preview?.rows || [];
  const flagged = rows.find(x => x.foreignAmountCents === -250000);
  const edited = rows.map(x => ({...x, sourceRunningBalanceCents: x.runningBalanceSource === 'source' ? x.runningBalanceCents : null, ...(x.foreignAmountCents === -450 ? {description: 'Coffee - client meeting ' + k, fullDescription: 'Coffee - client meeting ' + k} : {})}));
  const put = await o.call('operations/statement-preview', {method: 'PUT', json: {previewId: fin.b.preview.id, rows: edited}});
  const keys = (put.b.preview?.rows || []).filter(x => x.eligible && !x.requiresDuplicateReview).map(x => x.selectionKey);
  const ap = await o.call('operations/statement-approve', {method: 'POST', json: {previewId: fin.b.preview.id, operationKey: key('ap'), selectedKeys: keys, reviewedDuplicateKeys: [], balanceGapAcknowledged: true}});
  const coffee = sql(`SELECT COUNT(*) FROM bank_transactions WHERE company_id='${CO}' AND description='Coffee - client meeting ${k}'`);
  const rent = sql(`SELECT COUNT(*) FROM bank_transactions WHERE company_id='${CO}' AND description LIKE 'PREAUTH RENT MAIN ST ${k}%'`);
  return {ok: !!flagged?.requiresDuplicateReview && /by hand/i.test(flagged.validationReason || '') && put.s === 200 && keys.length === 1 && ap.s < 300 && coffee === '1' && rent === '0',
    info: `flag "${(flagged?.validationReason || '').slice(0, 70)}"; edit ${put.s}; selected ${keys.length}; import ${ap.s} ${ap.b.error || ''}; coffee as edited ${coffee}; rent imported ${rent}`};
});

await t('BK-03', 'Edit the description of an unposted line in Match and Post (amount and date unchanged)', async () => {
  const id = sql(`SELECT id FROM bank_transactions WHERE company_id='${CO}' AND description='Office rent Main St' AND transaction_date='2026-07-31'`);
  const r = await o.call('bank-transactions/edit', {method: 'POST', json: {transactionId: id, date: '2026-07-31', description: 'Office rent - Main St (July)', reference: 'RENT', remarks: '', amountCents: -250000}});
  const after = sql(`SELECT CONCAT(description,'|',amount_cents,'|',transaction_date) FROM bank_transactions WHERE id='${id}'`);
  return {ok: r.s === 200 && after === 'Office rent - Main St (July)|-250000|2026-07-31', info: `edit ${r.s} ${r.b.error || ''}; now ${after}`};
});

await t('BK-04', 'Screens: Match and Post has Add transaction, the edit pencil and an Entered-by-hand tag; the add window buttons are usable; Bank Transaction Report has Add transaction', async () => {
  const {b, p, errs} = await open(CO, {w: 1440, h: 900}); const got = {};
  try {
    await p.evaluate(() => TeghPortal.invokeMenuAction('Banking', 'Match And Post Transactions'));
    await p.waitForSelector('[data-r182-add-tx]', {timeout: 60000}); await p.waitForTimeout(1500);
    got.pencils = await p.locator('[data-r182-edit-desc]').count(); got.tags = await p.locator('.r182-manual-tag').count();
    await p.locator('[data-r182-add-tx]').first().click(); await p.waitForSelector('[data-r182-add]');
    got.onTop = await p.evaluate(() => { const b = document.querySelector('[data-r182-save]').getBoundingClientRect(); return !!document.elementFromPoint(b.x + b.width / 2, b.y + b.height / 2)?.closest('[data-r182-save]'); });
    got.locked = await p.evaluate(() => getComputedStyle(document.querySelector('.r22-primary-scroll')).overflowY);
    await p.keyboard.press('Escape'); await p.evaluate(() => document.querySelector('[data-r182-add] [data-cancel], [data-r182-add] button[type=button]')?.click());
    await p.evaluate(() => TeghPortal.invokeMenuAction('Banking', 'Bank Transaction Report')); await p.waitForTimeout(5000);
    got.report = await p.locator('[data-r182-add-tx]').count();
  } finally { got.errs = errs; await b.close(); }
  return {ok: got.pencils >= 1 && got.tags >= 1 && got.onTop && got.locked === 'hidden' && got.report >= 1 && !got.errs.length, info: JSON.stringify(got)};
});

await t('BK-05', 'Upload Statement preview: one scroll area (page behind locked), descriptions editable in place', async () => {
  const {b, p, errs} = await open(CO, {w: 1440, h: 900}); const got = {};
  try {
    await p.evaluate(() => TeghPortal.invokeMenuAction('Banking', 'Upload Statement')); await p.waitForTimeout(3000);
    const csv = '/tmp/r182-gate-' + tag + '.csv'; fs.writeFileSync(csv, `Date,Description,Amount\n2026-09-04,UI ROW A ${tag},-12.00\n2026-09-05,UI ROW B ${tag},30.00\n`);
    const f = p.locator('[data-bank-preview-form]').last(); await f.locator('input[type=file]').setInputFiles(csv); await f.locator('button[type=submit]').click();
    await p.waitForSelector('.tegh-bank-verification-panel [data-r182-desc]', {timeout: 40000}); await p.waitForTimeout(800);
    got.locked = await p.evaluate(() => getComputedStyle(document.querySelector('.r22-primary-scroll')).overflowY);
    const di = p.locator('[data-r182-desc]').first(); await di.fill('UI edited ' + tag); await di.press('Enter'); await p.waitForTimeout(3000);
    got.after = await p.evaluate(() => [document.querySelector('[data-bank-verify-error]')?.textContent || '', document.querySelector('[data-r182-desc]')?.value]);
    await p.locator('.tegh-bank-verification-panel [data-bank-cancel]').first().click(); await p.waitForTimeout(800);
  } finally { got.errs = errs; await b.close(); }
  return {ok: got.locked === 'hidden' && got.after[1] === 'UI edited ' + tag && /saved/i.test(got.after[0]) && !got.errs.length, info: JSON.stringify(got)};
});

// ---------- B: switching Guided / Full Accounting ----------
await t('MD-01', 'Switching mode opens that mode’s home with a review of what is and is not done; switching back works; no page errors', async () => {
  const {b, p, errs} = await open(CO, {w: 1440, h: 900}); const got = {};
  const start = await p.evaluate(() => document.documentElement.dataset.bookkeepingMode);
  const state = () => p.evaluate(() => { const pg = [...document.querySelectorAll('.srp-page')].pop(); const rv = document.querySelector('[data-r182-mode-review]'); return {page: pg?.dataset.srpPage, items: rv ? rv.querySelectorAll('li').length : 0}; });
  try {
    for (const target of start === 'owner' ? ['accountant', 'owner'] : ['owner', 'accountant']) {
      await p.evaluate(() => TeghPortal.invokeMenuAction('My account', 'Bookkeeping Mode')); await p.waitForTimeout(2500);
      const btn = p.locator(`[data-settings-mode="${target}"]`).first(); await btn.click(); await p.waitForSelector('[data-r182-mode-review] li', {timeout: 30000});
      got[target] = await state();
    }
  } finally { got.errs = errs; await b.close(); }
  return {ok: got.owner?.page === 'guided-bookkeeping' && got.owner.items >= 3 && got.accountant?.page === 'dashboard' && got.accountant.items >= 3 && !got.errs.length, info: `start ${start}; ` + JSON.stringify(got)};
});

// ---------- C: new company page ----------
await t('CO-01', 'New company page: numbered sections, live summary, time zone follows the province until chosen, no tutorial over the form, fits a phone; all field names kept', async () => {
  const got = {};
  for (const w of [1440, 390]) {
    const {b, p, errs} = await open(CO, {w, h: 900});
    try {
      await p.evaluate(() => TeghPortal.invokeMenuAction('My account', 'Add Company or Client File')); await p.waitForSelector('.r182-co-layout', {timeout: 20000}); await p.waitForTimeout(600);
      got[w] = await p.evaluate(() => ({sections: document.querySelectorAll('[data-r182-sec]').length, names: ['name', 'legalName', 'country', 'province', 'timezone', 'currency', 'fiscalYearEndDate', 'booksStartDate', 'accountingBasis', 'taxRegistered', 'moduleMode', 'addressLine1', 'shortName'].every(n => document.querySelector(`[data-create-company] [name="${n}"]`)),
        hscroll: document.documentElement.scrollWidth > innerWidth, tutorial: !!document.querySelector('.srp-modal-scrim'), provinceNext: document.querySelector('[data-location-country]').closest('label').nextElementSibling?.hasAttribute('data-location-province')}));
      if (w === 1440) { await p.selectOption('[name=province]', 'BC'); await p.waitForTimeout(200); got.bc = await p.inputValue('[name=timezone]'); await p.selectOption('[name=timezone]', 'UTC'); await p.selectOption('[name=province]', 'AB'); await p.waitForTimeout(200); got.kept = await p.inputValue('[name=timezone]'); }
      got['errs' + w] = errs.length;
    } finally { await b.close(); }
  }
  const ok = [1440, 390].every(w => got[w].sections >= 7 && got[w].names && !got[w].hscroll && !got[w].tutorial && got[w].provinceNext && got['errs' + w] === 0) && got.bc === 'America/Vancouver' && got.kept === 'UTC';
  return {ok, info: JSON.stringify(got)};
});

await t('CO-02', 'Creating a company from the new page stores what was entered (Manitoba, Winnipeg time zone, cash basis, tax number, city)', async () => {
  const {b, p, errs} = await open(CO, {w: 1440, h: 900}); const name = 'R182 Layout Co ' + tag;
  try {
    await p.evaluate(() => TeghPortal.invokeMenuAction('My account', 'Add Company or Client File')); await p.waitForSelector('.r182-co-layout');
    await p.fill('[name=name]', name); await p.fill('[name=legalName]', name + ' Inc.'); await p.selectOption('[name=province]', 'MB'); await p.click('[data-r182-basis=cash]');
    await p.check('[name=taxRegistered]'); await p.fill('[name=taxNumber]', '123456789RT0001'); await p.click('details[data-r182-sec=address] > summary'); await p.fill('[name=city]', 'Winnipeg');
    await p.click('.r182-co-actions button[type=submit]'); await p.waitForTimeout(8000);
  } finally { await b.close(); }
  const r = sql(`SELECT CONCAT_WS('|',province,timezone,accounting_basis,tax_registered,city,currency) FROM companies WHERE name='${name}'`);
  return {ok: r === 'MB|America/Winnipeg|cash|1|Winnipeg|CAD' && !errs.length, info: `${r}; errors ${errs.length}`};
});


await t('BR-01', 'Backup and restore of a company with corrections: invoices, entries and the correction history come back the same', async () => {
  o.cid = CO;
  const res = await o.call('backup/export', {method: 'POST', json: {}, raw: true}); const buf = Buffer.from(await res.arrayBuffer());
  const fd = new FormData(); fd.append('backup', new Blob([buf]), 'r182.tegh'); for (const [k, v] of [['companyName', 'R182 Restored ' + tag], ['confirm', 'RESTORE'], ['confirmation', 'RESTORE'], ['mode', 'new']]) fd.append(k, v);
  const r = await o.call('backup/restore', {method: 'POST', body: fd});
  const NEW = r.b?.companyId;
  const sig = cid => sql(`SELECT CONCAT((SELECT COUNT(*) FROM invoice_revisions WHERE company_id='${cid}'),'|',(SELECT GROUP_CONCAT(CONCAT(number,':',total_cents,':',balance_cents,':',status) ORDER BY number) FROM invoices WHERE company_id='${cid}'),'|',(SELECT COUNT(*) FROM journal_entries WHERE company_id='${cid}'))`);
  const a = sig(CO), b = NEW ? sig(NEW) : '';
  const glA = JSON.stringify(glBy(CO)), glB = NEW ? JSON.stringify(glBy(NEW)) : '';
  return {ok: res.status === 200 && r.s < 300 && !!NEW && a === b && glA === glB && integrity(NEW).ok, info: `export ${res.status}; restore ${r.s} ${r.b?.code || ''}; revisions/invoices/entries equal ${a === b} (${a.split('|')[0]} revisions); GL equal ${glA === glB}; restored ${JSON.stringify(NEW ? integrity(NEW) : null)}`};
});
} finally {
  await setMode(startMode);
}
save('r182.json');

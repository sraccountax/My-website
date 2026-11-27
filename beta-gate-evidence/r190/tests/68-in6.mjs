// IN-6: TDS on vendor invoices and TCS on customer invoices (user-defined sections), India dashboard and setup checklist.
// Every expected figure is worked out by hand below; none is read back from Tegh. Sections, rates and limits are invented test values.
import {S, sql, rec, save, need} from './lib.mjs';
import {chromium} from '/opt/node22/lib/node_modules/playwright/index.mjs';

const A = 'IN6';
const t = async (id, title, fn) => { try { const r = await fn(); rec(id, A, title, r.ok ? 'PASS' : 'FAIL', r.info); return r.ok; } catch (e) { rec(id, A, title, 'FAIL', String(e.message || e).slice(0, 400)); return false; } };
const PW = 'Gate!Owner#2026pw', MPW = 'Gate!Member#2026pw';
const o = new S(); await o.login('owner@gate.test', PW);
const release = async () => (await o.call('site-admin', {company: false})).b.groups.release;
const setMode = async m => { const cur = await release(); return o.call('site-admin/save', {method: 'POST', company: false, json: {groups: {release: {value: {...cur.value, indiaEdition: m}, revision: cur.revision}}, reason: 'IN-6 gate ' + m}}); };
const startMode = (await release()).value.indiaEdition || 'off';
const tag = Date.now().toString(36);
const CH = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
const gstin = b => { let s = 0; for (let i = 0; i < 14; i++) { const p = CH.indexOf(b[i]) * (i % 2 === 0 ? 1 : 2); s += Math.floor(p / 36) + p % 36; } return b + CH[(36 - s % 36) % 36]; };
const n4 = String(1000 + (Date.now() % 9000)).slice(0, 4);
const PAN = 'AAACT' + n4 + 'K', V1PAN = 'AAAFA' + n4 + 'B', V3PAN = 'AAAFC' + n4 + 'D', C1PAN = 'AAACE' + n4 + 'F';
const M = (s, r, j) => s.call('india/masters/' + r, {method: 'POST', json: j});
const T = (s, r, j) => j ? s.call('india/tds/' + r, {method: 'POST', json: j}) : s.call('india/tds/' + r);
const R = rupees => Math.round(rupees * 100);
const key = p => p + '-' + tag + '-' + Math.random().toString(36).slice(2, 8);
const gl = (co, code) => Number(sql(`SELECT IFNULL(SUM(jl.credit_cents-jl.debit_cents),0) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id JOIN accounts a ON a.id=jl.account_id WHERE je.company_id='${co}' AND je.status='posted' AND a.code='${code}'`));
const tb = co => sql(`SELECT IFNULL(SUM(jl.debit_cents-jl.credit_cents),0) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id WHERE je.company_id='${co}' AND je.status='posted'`);

let IN, ED, INCO = '', CACO = '';
const ids = {};
try {
  need('mode', await setMode('everyone'));
  IN = new S(); await IN.login('owner@gate.test', PW, {edition: 'IN'});
  INCO = need('india company', await IN.call('companies', {method: 'POST', company: false, json: {name: 'IN6 TDS ' + tag, legalName: 'IN6 TDS Private Limited', constitution: 'private_limited', state: 'MH', pin: '400001', city: 'Mumbai', fiscalYearEnd: '03-31', booksStartDate: '2026-04-01', pan: PAN, gstin: gstin('27' + PAN + '1Z')}}), [201]).company.id;
  IN.cid = INCO;
  CACO = sql(`SELECT c.id FROM companies c JOIN company_members cm ON cm.company_id=c.id JOIN users u ON u.id=cm.user_id WHERE u.email='owner@gate.test' AND (c.edition IS NULL OR c.edition<>'IN') AND c.active=1 AND cm.role='owner' LIMIT 1`);
  sql(`INSERT INTO company_members (company_id,user_id,role,status) SELECT '${INCO}',id,'editor','active' FROM users WHERE email='editor@gate.test'`);
  ED = new S(); await ED.login('editor@gate.test', MPW, {edition: 'IN'}); ED.cid = INCO;
  const rule = async j => { const id = need('rule', await M(IN, 'rule', {label: 'Synthetic ' + j.code, source: 'Synthetic gate data', effectiveFrom: '2026-04-01', ...j}), [201]).id; need('approve', await M(IN, 'rule-approve', {id})); return id; };
  // Synthetic TDS section: 1%, single-invoice limit ₹30,000, yearly limit ₹1,00,000. Synthetic TCS section: 1%, no limits.
  ids.S = 'G194C-' + tag; ids.TC = 'G206C-' + tag;
  await rule({category: 'tds_section', code: ids.S, ratePercent: '1', thresholdMinCents: String(R(30000)), thresholdMaxCents: String(R(100000))});
  await rule({category: 'tcs_section', code: ids.TC, ratePercent: '1'});
  const vend = async name => { const r = need('vendor', await IN.call('vendors', {method: 'POST', json: {name: name + ' ' + tag, email: '', address: '', defaultTermsDays: 30, currency: 'INR', country: 'India', province: 'MH'}}), [200, 201]); return r.vendor?.id || r.id; };
  const prof = async (entity, entityId, data) => need('profile', await M(IN, 'profile', {entity, entityId, effectiveFrom: '2026-04-01', data}), [201]);
  ids.v1 = await vend('IN6 Contractor'); await prof('vendor', ids.v1, {pan: V1PAN, tdsSection: ids.S});
  ids.v2 = await vend('IN6 No PAN Contractor'); await prof('vendor', ids.v2, {tdsSection: ids.S});
  ids.v3 = await vend('IN6 Certificate Contractor'); await prof('vendor', ids.v3, {pan: V3PAN, tdsSection: ids.S});
  need('certificate', await M(IN, 'record', {recordType: 'tds_certificate', entity: 'vendor', entityId: ids.v3, effectiveFrom: '2026-04-01', data: {section: ids.S, certificateNumber: 'CERT' + n4, ratePercent: '0.5', limitCents: String(R(50000)), validFrom: '2026-04-01', validTo: '2027-03-31'}}), [201]);
  const acct = c => sql(`SELECT id FROM accounts WHERE company_id='${INCO}' AND code='${c}'`);
  const bill = async (vendorId, number, cents, date) => { const r = need('bill', await IN.call('bills', {method: 'POST', json: {vendorId, number: number + '-' + tag, billDate: date, dueDate: '2026-10-30', currency: 'INR', taxEntryMode: 'exclusive', memo: 'Synthetic service', issue: true,
    lines: [{description: 'Synthetic contract work', quantity: 1, unitPriceCents: cents, accountId: acct('6400')}]}}), [200, 201]); return r.b?.bill?.id || r.bill?.id || r.id; };
  ids.b1 = await bill(ids.v1, 'C-1', R(25000), '2026-09-02');
  ids.b2 = await bill(ids.v1, 'C-2', R(20000), '2026-09-09');
  ids.b3 = await bill(ids.v1, 'C-3', R(60000), '2026-09-16');
  ids.b4 = await bill(ids.v2, 'N-1', R(40000), '2026-09-16');
  ids.b5 = await bill(ids.v3, 'K-1', R(40000), '2026-09-16');
  const bal = id => Number(sql(`SELECT balance_cents FROM bills WHERE id='${id}'`));

  await t('IN6-01', 'Canada is unaffected: every TDS/TCS route answers 404 for a Canadian company', async () => {
    const ca = new S(); await ca.login('owner@gate.test', PW); ca.cid = CACO;
    const r = [await T(ca, 'pending&from=2026-09-01&to=2026-09-30'), await T(ca, 'register&from=2026-09-01&to=2026-09-30'), await T(ca, 'home'), await T(ca, 'record', {documentId: 'x'}), await T(ca, 'reverse', {id: 'x'})];
    return {ok: r.every(x => x.s === 404 && x.b.code === 'india_only'), info: JSON.stringify(r.map(x => [x.s, x.b.code]))};
  });

  await t('IN6-02', 'Limits: ₹25,000 and then ₹20,000 are below the single-invoice limit (₹30,000) and the year (₹45,000) is below ₹1,00,000, so nothing is due; recording is refused', async () => {
    const p1 = need('preview', await T(IN, `preview&documentId=${ids.b1}`)), p2 = need('preview', await T(IN, `preview&documentId=${ids.b2}`));
    const r = await T(IN, 'record', {kind: 'tds', documentId: ids.b2});
    return {ok: !p1.due && !p2.due && /Below the limits/.test(p2.reason) && r.s === 422 && r.b.code === 'india_tds_not_due', info: JSON.stringify({p1: [p1.due, p1.reason.slice(0, 80)], p2: p2.reason.slice(0, 120), r: [r.s, r.b.code]})};
  });

  await t('IN6-03', 'The ₹60,000 invoice crosses both limits: the two earlier invoices are added (base ₹1,05,000), TDS 1% = ₹1,050; payables fall by ₹1,050 and TDS Payable 2330 is credited; the invoice balance is ₹58,950', async () => {
    const p = need('preview', await T(IN, `preview&documentId=${ids.b3}`));
    const ed = await T(ED, 'record', {kind: 'tds', documentId: ids.b3});
    ids.e3 = ed.b.id;
    const okEd = ed.s === 201; // an Editor (bills.write) may record TDS
    const again = await T(IN, 'record', {kind: 'tds', documentId: ids.b3});
    return {ok: p.due && p.baseCents === R(105000) && p.amountCents === R(1050) && p.catchUp.length === 2 && okEd && ed.b.amountCents === R(1050) && bal(ids.b3) === R(58950) && gl(INCO, '2330') === R(1050) && again.s === 409 && tb(INCO) === '0',
      info: JSON.stringify({base: p.baseCents, amt: p.amountCents, catch: p.catchUp.map(c => c.number), ed: [ed.s, ed.b.code], bal: bal(ids.b3), gl: gl(INCO, '2330'), again: again.b.code})};
  });

  await t('IN6-04', 'No PAN: refused until the approved "<section>-NOPAN" rule (synthetic 20%) exists; then ₹40,000 × 20% = ₹8,000', async () => {
    const before = await T(IN, 'record', {kind: 'tds', documentId: ids.b4});
    await rule({category: 'tds_section', code: ids.S + '-NOPAN', ratePercent: '20', thresholdMinCents: String(R(30000)), thresholdMaxCents: String(R(100000))});
    const after = need('record', await T(IN, 'record', {kind: 'tds', documentId: ids.b4}), [201]);
    ids.e4 = after.id;
    return {ok: before.s === 422 && /no PAN/.test(String(before.b.error || before.b.message || '')) && after.amountCents === R(8000) && /no PAN/.test(after.rateSource) && bal(ids.b4) === R(40000 + 0) - R(8000), info: JSON.stringify({before: [before.s, String(before.b.error || '').slice(0, 80)], after: [after.amountCents, after.rateSource], bal: bal(ids.b4)})};
  });

  await t('IN6-05', 'Lower deduction certificate (0.5%, limit ₹50,000): ₹40,000 × 0.5% = ₹200, and the rate source names the certificate', async () => {
    const r = need('record', await T(IN, 'record', {kind: 'tds', documentId: ids.b5}), [201]);
    return {ok: r.amountCents === R(200) && r.rateMpct === 500 && /certificate/.test(r.rateSource), info: JSON.stringify({amt: r.amountCents, rate: r.rateMpct, src: r.rateSource})};
  });

  await t('IN6-06', 'TDS register for September: 3 entries, total ₹9,250 (1,050 + 8,000 + 200), quarter 2026-27 Q2, PAN of each deductee; it equals the TDS Payable credits', async () => {
    const r = need('register', await T(IN, 'register&from=2026-09-01&to=2026-09-30'));
    return {ok: r.rows.length === 3 && r.totalCents === R(9250) && r.postedCents === R(9250) && r.ties && r.rows.every(x => x.quarter === '2026-27 Q2') && r.rows.find(x => x.amountCents === R(1050))?.pan === V1PAN && r.rows.find(x => x.amountCents === R(8000))?.pan === null && r.sections.length === 1,
      info: JSON.stringify({n: r.rows.length, total: r.totalCents, posted: r.postedCents, q: r.rows.map(x => x.quarter), pans: r.rows.map(x => x.pan)})};
  });

  await t('IN6-07', 'The vendor invoice with TDS cannot be voided until the TDS is reversed; reversal needs the Owner and a reason; the balance returns to ₹60,000 and the register drops to ₹8,200', async () => {
    const pv = (await IN.call(`voids/preview&entityType=bill&entityId=${ids.b3}`)).b;
    const blocked = (pv.blockers || []).some(b => /TDS is recorded/.test(b));
    const ed = await T(ED, 'reverse', {id: ids.e3, reason: 'Wrong section used'});
    const noReason = await T(IN, 'reverse', {id: ids.e3, reason: ''});
    const r = need('reverse', await T(IN, 'reverse', {id: ids.e3, reason: 'Wrong section used', date: '2026-09-30'}));
    const reg = need('register', await T(IN, 'register&from=2026-09-01&to=2026-09-30'));
    const pv2 = (await IN.call(`voids/preview&entityType=bill&entityId=${ids.b3}`)).b;
    return {ok: blocked && ed.s === 403 && noReason.s === 422 && r.status === 'reversed' && bal(ids.b3) === R(60000) && reg.totalCents === R(8200) && reg.ties && !(pv2.blockers || []).some(b => /TDS/.test(b)) && tb(INCO) === '0',
      info: JSON.stringify({blocked, ed: ed.s, noReason: noReason.s, bal: bal(ids.b3), reg: [reg.totalCents, reg.postedCents], after: pv2.blockers})};
  });

  await t('IN6-08', 'TCS: a ₹50,000 customer invoice gets a separate receivable "TCS-<invoice>" of ₹500 (1%), TCS Payable 2340 is credited; GST reports leave the TCS document out; the invoice cannot be voided until the TCS document is voided', async () => {
    const c = need('customer', await IN.call('customers', {method: 'POST', json: {name: 'IN6 Scrap Buyer ' + tag, email: '', phone: '', billingAddress: '1 Road', country: 'India', province: 'MH'}}), [200, 201]).customer.id;
    await prof('customer', c, {pan: C1PAN, tcsSection: ids.TC});
    const TMPL = (await IN.call('invoice-templates')).b.templates?.[0]?.id;
    const inv = need('invoice', await IN.call('invoices', {method: 'POST', json: {customerId: c, issueDate: '2026-09-20', dueDate: '2026-10-20', currency: 'INR', templateId: TMPL, issue: true, lines: [{description: 'Synthetic scrap', quantity: 1, unitPriceCents: R(50000), incomeAccountId: acct('4100')}]}}), [200, 201]).invoice;
    const r = need('tcs', await T(IN, 'record', {kind: 'tcs', documentId: inv.id}), [201]);
    const doc = sql(`SELECT CONCAT_WS('|',number,status,total_cents,balance_cents) FROM invoices WHERE id='${r.tcsInvoiceId}'`);
    const g1 = need('gstr1', await IN.call('india/gst/gstr1&period=2026-09'));
    const docsIssued = g1.sections.docs.find(d => /Invoices/.test(d.document));
    const pv = (await IN.call(`voids/preview&entityType=invoice&entityId=${inv.id}`)).b;
    const blocked = (pv.blockers || []).some(b => /TCS document/.test(b));
    const v = await IN.call('voids/apply', {method: 'POST', json: {entityType: 'invoice', entityId: r.tcsInvoiceId, reason: 'TCS raised in error in this IN-6 test', confirmed: true, operationKey: key('v')}});
    const pv2 = (await IN.call(`voids/preview&entityType=invoice&entityId=${inv.id}`)).b;
    const reg = need('register', await T(IN, 'register&kind=tcs&from=2026-09-01&to=2026-09-30'));
    return {ok: r.amountCents === R(500) && doc === `TCS-${inv.number}|sent|${R(500)}|${R(500)}` && docsIssued?.total === 1 && blocked && v.s === 200 && !(pv2.blockers || []).some(b => /TCS/.test(b)) && reg.rows.length === 0 && reg.ties && tb(INCO) === '0',
      info: JSON.stringify({amt: r.amountCents, doc, docsIssued, blocked, void: v.s, reg: [reg.rows.length, reg.totalCents, reg.postedCents]})};
  });

  await t('IN6-09', 'India dashboard and setup checklist: the checklist lists company, GST, payroll and TDS items with what is done; TDS Payable shows ₹8,200', async () => {
    const h = need('home', await T(IN, 'home'));
    const keys = h.checklist.items.map(i => i.key);
    const done = Object.fromEntries(h.checklist.items.map(i => [i.key, i.done]));
    return {ok: ['pan', 'gstin', 'tan', 'gst_rate', 'gst_codes', 'employees', 'tds_sections', 'vendor_sections'].every(k => keys.includes(k)) && done.pan && done.gstin && done.tds_sections && done.vendor_sections && !done.gst_codes && !done.employees && h.balances.tdsPayableCents === R(8200),
      info: JSON.stringify({done, tds: h.balances.tdsPayableCents, n: `${h.checklist.done}/${h.checklist.total}`})};
  });


  await t('IN6-10', 'Backup and restore of the India company: the copy has the same trial balance, the TDS entries keep their documents (register ₹8,200, tying to TDS Payable), and the GST and TDS tables come across', async () => {
    const res = await IN.call('backup/export', {method: 'POST', json: {}, raw: true}); const buf = Buffer.from(await res.arrayBuffer());
    const fd = new FormData(); fd.append('backup', new Blob([buf]), 'in6.tegh'); for (const [k, v] of [['companyName', 'IN6 Restored ' + tag], ['confirm', 'RESTORE'], ['confirmation', 'RESTORE'], ['mode', 'new']]) fd.append(k, v);
    const r = await IN.call('backup/restore', {method: 'POST', body: fd});
    const nid = r.b?.companyId;
    if (!nid) return {ok: false, info: JSON.stringify({export: res.status, restore: [r.s, r.b?.code, String(r.b?.error || '').slice(0, 160)]})};
    const tbRows = co => sql(`SELECT GROUP_CONCAT(CONCAT(a.code,':',x.v) ORDER BY a.code) FROM (SELECT jl.account_id, SUM(jl.debit_cents-jl.credit_cents) v FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id WHERE je.company_id='${co}' AND je.status='posted' GROUP BY jl.account_id HAVING v<>0) x JOIN accounts a ON a.id=x.account_id`);
    const R2 = new S(); await R2.login('owner@gate.test', PW, {edition: 'IN'}); R2.cid = nid;
    const reg = await T(R2, 'register&from=2026-09-01&to=2026-09-30');
    const docsOk = sql(`SELECT COUNT(*) FROM india_tds_entries e JOIN bills b ON b.id=e.document_id AND b.company_id=e.company_id WHERE e.company_id='${nid}' AND e.kind='tds'`);
    return {ok: tbRows(nid) === tbRows(INCO) && reg.s === 200 && reg.b.totalCents === R(8200) && reg.b.ties && docsOk === sql(`SELECT COUNT(*) FROM india_tds_entries WHERE company_id='${INCO}' AND kind='tds'`),
      info: JSON.stringify({restore: r.s, tb: tbRows(nid) === tbRows(INCO), reg: [reg.s, reg.b?.totalCents, reg.b?.ties], docsOk})};
  });
  // ---------------- Browser ----------------
  const browser = await chromium.launch({executablePath: '/opt/pw-browsers/chromium', args: ['--no-proxy-server', '--host-resolver-rules=MAP gate.test 127.0.0.1']});
  try {
    const login = async (p, company, edition) => {
      await p.goto('https://gate.test/app.html' + (edition ? '?edition=in' : '')); await p.waitForSelector('#sr-login-form', {timeout: 30000});
      if (edition) await p.selectOption('#sr-login-form [name=edition]', 'IN');
      await p.fill('#sr-login-form input[name=email]', 'owner@gate.test'); await p.fill('#sr-login-form input[name=password]', PW); await p.click('#sr-login-form button[type=submit]');
      await p.waitForFunction(() => window.TeghPortal && document.querySelector('.sidebar,.topbar'), null, {timeout: 40000});
      await p.evaluate(id => { localStorage.setItem('sr-accountax-company', id); localStorage.setItem('sr-accountax-companies', JSON.stringify([id])); }, company); await p.goto('https://gate.test/app.html'); await p.waitForFunction(() => window.TeghPortal && document.querySelector('.sidebar,.topbar'), null, {timeout: 40000});
      await p.waitForTimeout(1500);
    };
    await t('UI-01', 'TDS and TCS page: the register lists the deductions with PAN and quarter; India Home shows the checklist and figures; India Help opens; no Canadian wording', async () => {
      const ctx = await browser.newContext({viewport: {width: 1440, height: 900}, ignoreHTTPSErrors: true}); const p = await ctx.newPage(); const errs = [];
      p.on('pageerror', e => errs.push(e.message.slice(0, 200)));
      try {
        await login(p, INCO, true);
        await p.evaluate(() => TeghPortal.openIndiaTds('register')); await p.waitForSelector('[data-in6-form]', {timeout: 20000});
        await p.fill('[data-in6-form] [name=from]', '2026-09-01'); await p.fill('[data-in6-form] [name=to]', '2026-09-30'); await p.click('[data-in6-form] button[type=submit]');
        await p.waitForSelector('[data-in6-register] tbody tr', {timeout: 20000});
        const rows = await p.locator('[data-in6-register] tbody tr').count(); const ties = await p.getAttribute('[data-in6-ties]', 'data-in6-ties');
        const t1 = await p.innerText('.in6-tds');
        await p.screenshot({path: '/srv/gate/ev/shots/in6-tds-register-1440.png', fullPage: true});
        await p.evaluate(() => TeghPortal.openIndiaHome()); await p.waitForSelector('[data-in6-checklist] li', {timeout: 20000});
        const items = await p.locator('[data-in6-checklist] li').count(); const t2 = await p.innerText('.in6-home');
        await p.screenshot({path: '/srv/gate/ev/shots/in6-home-1440.png', fullPage: true});
        await p.evaluate(() => TeghPortal.openIndiaHelp()); await p.waitForSelector('[data-in6-help] section', {timeout: 20000});
        const topics = await p.locator('[data-in6-help] section').count(); const t3 = await p.innerText('[data-in6-help]');
        const all = t1 + t2 + t3, canadian = all.match(/GST\/HST|\bHST\b|\bPST\b|\bCRA\b|GIFI|Canada|Canadian|\bCAD\b|\$/g) || [];
        return {ok: rows === 2 && ties === '1' && /2026-27 Q2/.test(t1) && items >= 14 && /₹8,200\.00/.test(t2) && topics >= 5 && !canadian.length && !errs.length, info: JSON.stringify({rows, ties, items, topics, canadian, errs})};
      } finally { await ctx.close(); }
    });
    await t('UI-02', 'Canadian company: no India Home, India Help or TDS and TCS items in the menus, and the pages are refused', async () => {
      const ctx = await browser.newContext({viewport: {width: 1440, height: 900}, ignoreHTTPSErrors: true}); const p = await ctx.newPage();
      try {
        await login(p, CACO, false);
        const nav = await p.evaluate(() => [...document.querySelectorAll('.sidebar button, .sidebar a, nav button, nav a')].map(x => x.textContent.trim()).join('|'));
        await p.evaluate(() => TeghPortal.openIndiaTds()); await p.waitForTimeout(700);
        const toast = await p.locator('.srp-toast').first().textContent().catch(() => '');
        return {ok: !/India Home|India Help|TDS and TCS/.test(nav) && /only in India companies/.test(toast || ''), info: JSON.stringify({toast})};
      } finally { await ctx.close(); }
    });
  } finally { await browser.close(); }
} finally {
  await setMode(startMode).catch(() => {});
  save('in6.json');
}

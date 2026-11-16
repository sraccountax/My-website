// IN-3a: GST for India companies on the tax-code engine. GST codes from approved rate rules; place of supply decides
// CGST+SGST (intra-state) or IGST (inter-state); the rule in force on the document date must match the code; output tax
// and input tax credit post to their GL accounts; the GST summary equals the books. Canadian companies are untouched.
// Synthetic data only: rule codes, rates and sources are invented test values, not Indian law.
import {S, sql, rec, save, need} from './lib.mjs';
import {chromium} from '/opt/node22/lib/node_modules/playwright/index.mjs';

const A = 'IN3';
const t = async (id, title, fn) => { try { const r = await fn(); rec(id, A, title, r.ok ? 'PASS' : 'FAIL', r.info); return r.ok; } catch (e) { rec(id, A, title, 'FAIL', String(e.message || e).slice(0, 400)); return false; } };
const PW = 'Gate!Owner#2026pw', MPW = 'Gate!Member#2026pw';
const o = new S(); await o.login('owner@gate.test', PW);
const release = async () => (await o.call('site-admin', {company: false})).b.groups.release;
const setMode = async m => { const cur = await release(); return o.call('site-admin/save', {method: 'POST', company: false, json: {groups: {release: {value: {...cur.value, indiaEdition: m}, revision: cur.revision}}, reason: 'IN-3 gate ' + m}}); };
const startMode = (await release()).value.indiaEdition || 'off';
const tag = Date.now().toString(36);
const CH = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
const gstin = b => { let s = 0; for (let i = 0; i < 14; i++) { const p = CH.indexOf(b[i]) * (i % 2 === 0 ? 1 : 2); s += Math.floor(p / 36) + p % 36; } return b + CH[(36 - s % 36) % 36]; };
const n4 = String(1000 + (Date.now() % 9000)).slice(0, 4);
const PAN = 'AAACH' + n4 + 'K', VPAN = 'AAAFV' + n4 + 'Q', UPAN = 'AAACU' + n4 + 'L';
const M = (s, r, j) => s.call('india/masters/' + r, {method: 'POST', json: j});
const Gst = (s, r, j) => j ? s.call('india/gst/' + r, {method: 'POST', json: j}) : s.call('india/gst/' + r);
const bal = (co, code, from = '2026-04-01', to = '2026-09-30') => Number(sql(`SELECT IFNULL(SUM(jl.credit_cents-jl.debit_cents),0) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id JOIN accounts a ON a.id=jl.account_id WHERE je.company_id='${co}' AND je.status='posted' AND a.code='${code}' AND je.entry_date BETWEEN '${from}' AND '${to}'`));
const tb = co => sql(`SELECT IFNULL(SUM(jl.debit_cents-jl.credit_cents),0) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id WHERE je.company_id='${co}' AND je.status='posted'`);
const docTax = (co, type, id) => sql(`SELECT IFNULL(GROUP_CONCAT(CONCAT(component_name,':',tax_cents) ORDER BY component_name SEPARATOR ','),'') FROM document_tax_lines WHERE company_id='${co}' AND document_type='${type}' AND document_id='${id}'`);
const pos = (co, type, id) => sql(`SELECT IFNULL((SELECT CONCAT(place_of_supply,'|',supply) FROM india_document_gst WHERE company_id='${co}' AND document_type='${type}' AND document_id='${id}'),'-')`);

let IN, ED, INCO = '', CACO = '', UNCO = '';
const ids = {};
try {
  need('mode', await setMode('everyone'));
  IN = new S(); await IN.login('owner@gate.test', PW, {edition: 'IN'});
  INCO = need('india company', await IN.call('companies', {method: 'POST', company: false, json: {name: 'IN3 GST ' + tag, legalName: 'IN3 GST Private Limited', constitution: 'private_limited', state: 'MH', pin: '400001', city: 'Mumbai', fiscalYearEnd: '03-31', booksStartDate: '2026-04-01', pan: PAN, gstin: gstin('27' + PAN + '1Z')}}), [201]).company.id;
  IN.cid = INCO;
  CACO = sql(`SELECT c.id FROM companies c JOIN company_members cm ON cm.company_id=c.id JOIN users u ON u.id=cm.user_id WHERE u.email='owner@gate.test' AND (c.edition IS NULL OR c.edition<>'IN') AND c.active=1 AND cm.role='owner' LIMIT 1`);
  sql(`INSERT INTO company_members (company_id,user_id,role,status) SELECT '${INCO}',id,'editor','active' FROM users WHERE email='editor@gate.test'`);
  ED = new S(); await ED.login('editor@gate.test', MPW, {edition: 'IN'}); ED.cid = INCO;
  need('context (creates the GST tables on first use)', await Gst(IN, 'context'));
  // Synthetic parties: customers in Maharashtra (own state), Karnataka and abroad; vendors in Maharashtra and (by GST registration) Gujarat.
  const cust = async (name, extra) => need('customer', await IN.call('customers', {method: 'POST', json: {name: name + ' ' + tag, email: '', phone: '', billingAddress: '1 Road', ...extra}}), [200, 201]).customer.id;
  ids.cMH = await cust('IN3 Pune Buyer', {country: 'India', province: 'MH'});
  ids.cKA = await cust('IN3 Bengaluru Buyer', {country: 'India', province: 'KA'});
  ids.cUS = await cust('IN3 Export Buyer', {country: 'United States', province: 'NY'});
  const vend = async (name, extra) => { const r = need('vendor', await IN.call('vendors', {method: 'POST', json: {name: name + ' ' + tag, email: '', address: '', defaultTermsDays: 30, currency: 'INR', ...extra}}), [200, 201]); return r.vendor?.id || r.id; };
  ids.vMH = await vend('IN3 Mumbai Supplier', {country: 'India', province: 'MH'});
  ids.vGJ = await vend('IN3 Surat Supplier', {country: 'India', province: ''});
  need('vendor GST registration', await M(IN, 'record', {recordType: 'gst_registration', entity: 'vendor', entityId: ids.vGJ, effectiveFrom: '2026-04-01', data: {gstin: gstin('24' + VPAN + '1Z'), registrationType: 'regular', status: 'active', validFrom: '2026-04-01'}}), [201]);
  const acct = c => sql(`SELECT id FROM accounts WHERE company_id='${INCO}' AND code='${c}'`);
  const TMPL = (await IN.call('invoice-templates')).b.templates?.[0]?.id;
  const invoice = (customerId, cents, extra = {}, lineExtra = {}) => IN.call('invoices', {method: 'POST', json: {customerId, issueDate: '2026-05-10', dueDate: '2026-06-09', currency: 'INR', templateId: TMPL, issue: true, ...extra, lines: [{description: 'Synthetic service', quantity: 1, unitPriceCents: cents, incomeAccountId: acct('4100'), ...lineExtra}]}});
  const bill = (vendorId, number, cents, lineExtra = {}, extra = {}) => IN.call('bills', {method: 'POST', json: {vendorId, number: number + '-' + tag, billDate: '2026-05-12', dueDate: '2026-06-11', currency: 'INR', taxEntryMode: 'exclusive', memo: 'Synthetic purchase', issue: true, ...extra, lines: [{description: 'Synthetic goods', quantity: 1, unitPriceCents: cents, accountId: acct('6400'), ...lineExtra}]}});
  const rule = async (code, rate, from, cat = 'gst_rate') => need('rule', await M(IN, 'rule', {category: cat, code, label: 'Synthetic ' + code, effectiveFrom: from, ratePercent: String(rate), source: 'Synthetic gate data'}), [201]).id;

  await t('IN3-01', 'Canada is unaffected: a Canadian company gets 404 on every GST route and gains no GST codes or accounts', async () => {
    const ca = new S(); await ca.login('owner@gate.test', PW); ca.cid = CACO;
    const before = sql(`SELECT CONCAT_WS('|',(SELECT COUNT(*) FROM tax_codes WHERE company_id='${CACO}'),(SELECT COUNT(*) FROM accounts WHERE company_id='${CACO}'))`);
    const r = [await Gst(ca, 'context'), await Gst(ca, 'summary&from=2026-04-01&to=2026-09-30'), await Gst(ca, 'codes', {ruleId: 'x'}), await Gst(ca, 'document&type=invoice&id=x')];
    const after = sql(`SELECT CONCAT_WS('|',(SELECT COUNT(*) FROM tax_codes WHERE company_id='${CACO}'),(SELECT COUNT(*) FROM accounts WHERE company_id='${CACO}'))`);
    const rows = sql(`SELECT (SELECT COUNT(*) FROM india_gst_codes WHERE company_id='${CACO}')+(SELECT COUNT(*) FROM india_document_gst WHERE company_id='${CACO}')`);
    return {ok: r.every(x => x.s === 404 && x.b.code === 'india_only') && before === after && rows === '0', info: JSON.stringify({codes: r.map(x => [x.s, x.b.code]), before, after, rows})};
  });

  let G18 = null, I18 = null;
  await t('IN3-02', 'GST codes come only from an approved rule: a draft rule and an Editor are refused; the approved 18% rule makes GST18 (CGST 9 + SGST 9) and IGST18 with their GL accounts; repeating changes nothing', async () => {
    ids.r18 = await rule('GATE-GST-' + tag, 18, '2026-04-01');
    const draft = await Gst(IN, 'codes', {ruleId: ids.r18});
    need('approve', await M(IN, 'rule-approve', {id: ids.r18}));
    const editor = await Gst(ED, 'codes', {ruleId: ids.r18});
    const made = need('codes', await Gst(IN, 'codes', {ruleId: ids.r18}), [201]);
    const again = need('again', await Gst(IN, 'codes', {ruleId: ids.r18}), [201]);
    const ctx = need('context', await Gst(IN, 'context'));
    G18 = ctx.codes.find(c => c.supply === 'intra' && c.ruleKey === 'GATE-GST-' + tag); I18 = ctx.codes.find(c => c.supply === 'inter' && c.ruleKey === 'GATE-GST-' + tag);
    const comps = c => (c?.components || []).map(x => `${x.name}:${x.rateMpct}`).join(',');
    const accts = sql(`SELECT GROUP_CONCAT(CONCAT(code,'/',account_type) ORDER BY code) FROM accounts WHERE company_id='${INCO}' AND code IN ('1451','1452','1454','2401','2402','2404')`);
    return {ok: draft.s === 409 && draft.b.code === 'india_gst_rule_not_approved' && editor.s === 403 && made.codes.every(c => c.created) && again.codes.every(c => !c.created)
      && G18?.code === 'GST18' && I18?.code === 'IGST18' && comps(G18) === 'CGST:9000,SGST:9000' && comps(I18) === 'IGST:18000'
      && accts === '1451/asset,1452/asset,1454/asset,2401/liability,2402/liability,2404/liability',
      info: JSON.stringify({draft: [draft.s, draft.b.code], editor: editor.s, made: made.codes, again: again.codes.map(c => c.created), g: comps(G18), i: comps(I18), accts})};
  });

  await t('IN3-03', 'Intra-state sale (customer in Maharashtra): CGST 9% + SGST 9% credited to 2401 and 2402; place of supply 27 saved; an IGST code is refused', async () => {
    const wrong = await invoice(ids.cMH, 1000000, {}, {taxCodeId: I18.id});
    const r = need('invoice', await invoice(ids.cMH, 1000000, {}, {taxCodeId: G18.id}), [201]); ids.inv1 = r.invoice?.id || r.id;
    const tax = docTax(INCO, 'invoice', ids.inv1), p = pos(INCO, 'invoice', ids.inv1);
    return {ok: wrong.s === 422 && wrong.b.code === 'india_gst_supply_mismatch' && /IGST|intra-state/i.test(wrong.b.error || '') && tax === 'CGST:90000,SGST:90000' && p === '27|intra' && bal(INCO, '2401') === 90000 && bal(INCO, '2402') === 90000 && tb(INCO) === '0',
      info: JSON.stringify({wrong: [wrong.s, wrong.b.code], tax, pos: p, b2401: bal(INCO, '2401'), b2402: bal(INCO, '2402'), tb: tb(INCO)})};
  });

  await t('IN3-04', 'Inter-state sale (customer in Karnataka): IGST 18% to 2404, place of supply 29; the CGST/SGST code is refused; an explicit place of supply in Maharashtra makes it intra-state; an export is inter-state (96)', async () => {
    const wrong = await invoice(ids.cKA, 500000, {}, {taxCodeId: G18.id});
    const r = need('invoice', await invoice(ids.cKA, 500000, {}, {taxCodeId: I18.id}), [201]); ids.inv2 = r.invoice?.id || r.id;
    const over = need('override', await invoice(ids.cKA, 200000, {placeOfSupply: 'MH'}, {taxCodeId: G18.id}), [201]); ids.inv3 = over.invoice?.id || over.id;
    const exp = need('export', await invoice(ids.cUS, 300000, {}, {taxCodeId: I18.id}), [201]); ids.inv4 = exp.invoice?.id || exp.id;
    const res = {wrong: [wrong.s, wrong.b.code], tax: docTax(INCO, 'invoice', ids.inv2), pos: pos(INCO, 'invoice', ids.inv2), over: pos(INCO, 'invoice', ids.inv3), exp: pos(INCO, 'invoice', ids.inv4), b2404: bal(INCO, '2404')};
    return {ok: wrong.s === 422 && wrong.b.code === 'india_gst_supply_mismatch' && res.tax === 'IGST:90000' && res.pos === '29|inter' && res.over === '27|intra' && res.exp === '96|inter' && res.b2404 === 90000 + 54000 && tb(INCO) === '0', info: JSON.stringify(res)};
  });

  await t('IN3-05', 'A line with no tax code takes the item\'s GST rate rule (India Masters) for the supply type; an explicit "no tax" stays without GST', async () => {
    const item = need('item', await IN.call('portal/products', {method: 'POST', json: {kind: 'service', code: 'IN3-' + tag, name: 'IN3 audit service ' + tag, description: '', unitPriceCents: 100000}}), [200, 201]);
    ids.item = item.item?.id || item.id || item.product?.id;
    need('item profile', await M(IN, 'profile', {entity: 'item', entityId: ids.item, effectiveFrom: '2026-04-01', data: {hsnSac: '998311', gstRate: 'GATE-GST-' + tag}}), [200, 201]);
    const a = need('auto intra', await invoice(ids.cMH, 100000, {}, {productServiceId: ids.item}), [201]); const b = need('auto inter', await invoice(ids.cKA, 100000, {}, {productServiceId: ids.item}), [201]);
    const c = need('no tax', await invoice(ids.cMH, 100000, {}, {productServiceId: ids.item, taxCodeId: ''}), [201]);
    const res = {intra: docTax(INCO, 'invoice', a.invoice?.id || a.id), inter: docTax(INCO, 'invoice', b.invoice?.id || b.id), none: docTax(INCO, 'invoice', c.invoice?.id || c.id)};
    return {ok: res.intra === 'CGST:9000,SGST:9000' && res.inter === 'IGST:18000' && res.none === '', info: JSON.stringify(res)};
  });

  await t('IN3-06', 'The rule in force on the document date decides: after a later approved version changes the rate, the old code is refused for that date (and still accepted before it); codes made from the new version work', async () => {
    ids.r12 = need('v2', await M(IN, 'rule', {category: 'gst_rate', code: 'GATE-GST-' + tag, label: 'Synthetic v2', effectiveFrom: '2026-09-01', ratePercent: '12', source: 'Synthetic gate data'}), [201]).id;
    need('approve v2', await M(IN, 'rule-approve', {id: ids.r12}));
    const after = await invoice(ids.cMH, 100000, {issueDate: '2026-09-10', dueDate: '2026-10-10'}, {taxCodeId: G18.id});
    const before = await invoice(ids.cMH, 100000, {issueDate: '2026-08-20', dueDate: '2026-09-19'}, {taxCodeId: G18.id});
    const made = need('codes v2', await Gst(IN, 'codes', {ruleId: ids.r12}), [201]);
    const g12 = made.codes.find(c => c.supply === 'intra');
    const ok12 = await invoice(ids.cMH, 100000, {issueDate: '2026-09-10', dueDate: '2026-10-10'}, {taxCodeId: g12.id});
    const tax12 = docTax(INCO, 'invoice', ok12.b.invoice?.id || ok12.b.id);
    return {ok: after.s === 422 && after.b.code === 'india_gst_rate_changed' && before.s === 201 && g12.code === 'GST12' && ok12.s === 201 && tax12 === 'CGST:6000,SGST:6000',
      info: JSON.stringify({after: [after.s, after.b.code], before: before.s, g12: g12.code, ok12: ok12.s, tax12})};
  });

  await t('IN3-07', 'Purchases: a supplier in Maharashtra is intra-state (input CGST/SGST to 1451/1452); a supplier registered in Gujarat is inter-state (input IGST to 1454); the wrong supply type is refused', async () => {
    const r1 = need('bill MH', await bill(ids.vMH, 'IN3-B1', 400000, {taxCodeId: G18.id}), [201]); ids.b1 = r1.bill?.id || r1.id;
    const wrong = await bill(ids.vGJ, 'IN3-BX', 300000, {taxCodeId: G18.id});
    const r2 = need('bill GJ', await bill(ids.vGJ, 'IN3-B2', 300000, {taxCodeId: I18.id}), [201]); ids.b2 = r2.bill?.id || r2.id;
    const res = {b1: docTax(INCO, 'bill', ids.b1), p1: pos(INCO, 'bill', ids.b1), b2: docTax(INCO, 'bill', ids.b2), p2: pos(INCO, 'bill', ids.b2), wrong: [wrong.s, wrong.b.code],
      i1451: -bal(INCO, '1451'), i1452: -bal(INCO, '1452'), i1454: -bal(INCO, '1454')};
    return {ok: res.b1 === 'CGST:36000,SGST:36000' && res.p1 === '27|intra' && res.b2 === 'IGST:54000' && res.p2 === '27|inter' && wrong.s === 422 && wrong.b.code === 'india_gst_supply_mismatch'
      && res.i1451 === 36000 && res.i1452 === 36000 && res.i1454 === 54000 && tb(INCO) === '0', info: JSON.stringify(res)};
  });

  await t('IN3-08', 'Only GST codes in an India company: a plain tax code is refused on an invoice; a bank line or expense cannot take a GST code (no place of supply there)', async () => {
    const plain = need('plain code', await IN.call('tax-codes', {method: 'POST', json: {code: 'PLAIN' + n4, name: 'Plain test code', components: [{name: 'Levy', rateMpct: 5000, salesAccountId: acct('2700'), purchaseAccountId: acct('1310')}]}}), [200, 201]);
    const plainId = plain.taxCode?.id || plain.code?.id || plain.id || sql(`SELECT id FROM tax_codes WHERE company_id='${INCO}' AND code='PLAIN${n4}'`);
    const inv = await invoice(ids.cMH, 100000, {}, {taxCodeId: plainId});
    const exp = await IN.call('expenses', {method: 'POST', json: {vendor: 'Synthetic shop', expenseDate: '2026-05-15', memo: 'Synthetic expense', amountCents: 11800, categoryAccountId: acct('6400'), paidFromAccountId: acct('1000'), taxCodeId: G18.id, taxEntryMode: 'inclusive'}});
    return {ok: inv.s === 422 && inv.b.code === 'india_gst_code_required' && exp.s === 422 && exp.b.code === 'india_gst_use_documents', info: JSON.stringify({inv: [inv.s, inv.b.code], exp: [exp.s, exp.b.code, (exp.b.error || '').slice(0, 80)]})};
  });

  await t('IN3-09', 'A company without a GSTIN cannot charge GST (its codes may still be created for purchases)', async () => {
    UNCO = need('unregistered', await IN.call('companies', {method: 'POST', company: false, json: {name: 'IN3 Unregistered ' + tag, legalName: 'IN3 Unregistered Traders', constitution: 'proprietorship', state: 'KA', pin: '560001', city: 'Bengaluru', fiscalYearEnd: '03-31', booksStartDate: '2026-04-01', pan: UPAN}}), [201]).company.id;
    const U = Object.assign(new S(), {c: IN.c, csrf: IN.csrf, cid: UNCO});
    const rid = need('rule', await M(U, 'rule', {category: 'gst_rate', code: 'GATE-U-' + tag, label: 'Synthetic', effectiveFrom: '2026-04-01', ratePercent: '18', source: 'Synthetic gate data'}), [201]).id;
    need('approve', await M(U, 'rule-approve', {id: rid}));
    const codes = need('codes', await Gst(U, 'codes', {ruleId: rid}), [201]).codes;
    const c = need('customer', await U.call('customers', {method: 'POST', json: {name: 'IN3 U buyer ' + tag, email: '', phone: '', billingAddress: '1 Road', country: 'India', province: 'KA'}}), [200, 201]).customer.id;
    const tpl = (await U.call('invoice-templates')).b.templates?.[0]?.id;
    const r = await U.call('invoices', {method: 'POST', json: {customerId: c, issueDate: '2026-05-10', dueDate: '2026-06-09', currency: 'INR', templateId: tpl, issue: true, lines: [{description: 'x', quantity: 1, unitPriceCents: 100000, taxCodeId: codes.find(x => x.supply === 'intra').id}]}});
    const second = codes.find(x => x.supply === 'intra').code;
    return {ok: r.s === 422 && r.b.code === 'india_gst_not_registered' && second === 'GST18', info: JSON.stringify({r: [r.s, r.b.code], codes: codes.map(x => x.code)})};
  });

  await t('IN3-10', 'GST summary equals the books: output CGST/SGST/IGST equal the 2401/2402/2404 credits, input credit equals the 1451/1452/1454 debits, net = output − credit, and every GST document is listed', async () => {
    const s = need('summary', await Gst(IN, 'summary&from=2026-04-01&to=2026-09-30'));
    const h = Object.fromEntries(s.heads.map(x => [x.head, x]));
    const docs = s.documents.length, invoices = Number(sql(`SELECT COUNT(DISTINCT il.invoice_id) FROM invoice_lines il JOIN invoices i ON i.id=il.invoice_id JOIN india_gst_codes g ON g.tax_code_id=il.tax_code_id WHERE i.company_id='${INCO}' AND i.status<>'void'`));
    const bills = Number(sql(`SELECT COUNT(*) FROM bills b JOIN india_gst_codes g ON g.tax_code_id=b.tax_code_id WHERE b.company_id='${INCO}' AND b.status IN ('open','paid')`));
    const res = {out: [h.CGST.outputCents, h.SGST.outputCents, h.IGST.outputCents], gl: [bal(INCO, '2401'), bal(INCO, '2402'), bal(INCO, '2404')], itc: [h.CGST.creditCents, h.SGST.creditCents, h.IGST.creditCents], glIn: [-bal(INCO, '1451'), -bal(INCO, '1452'), -bal(INCO, '1454')], net: s.heads.every(x => x.netCents === x.outputCents - x.creditCents), docs, invoices, bills};
    return {ok: JSON.stringify(res.out) === JSON.stringify(res.gl) && JSON.stringify(res.itc) === JSON.stringify(res.glIn) && res.net && docs >= invoices + bills && invoices >= 7 && bills === 2, info: JSON.stringify(res)};
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
    await t('UI-01', 'India Masters › GST codes lists the codes made from rules; the GST Summary page shows the heads and the documents', async () => {
      const ctx = await browser.newContext({viewport: {width: 1440, height: 900}, ignoreHTTPSErrors: true}); const p = await ctx.newPage(); const errs = [];
      p.on('pageerror', e => errs.push(e.message.slice(0, 200)));
      try {
        await login(p, true);
        await p.evaluate(() => TeghPortal.openIndiaMasters('gst')); await p.waitForSelector('[data-in3-codes]', {timeout: 20000});
        const codes = await p.locator('[data-in3-codes] tbody tr[data-in3-code]').evaluateAll(rs => rs.map(r => r.dataset.in3Code));
        await p.screenshot({path: '/srv/gate/ev/shots/in3-codes-1440.png'});
        await p.evaluate(() => TeghPortal.openGstSummary()); await p.waitForSelector('[data-in3-period]', {timeout: 20000});
        await p.fill('[data-in3-period] [name=from]', '2026-04-01'); await p.fill('[data-in3-period] [name=to]', '2026-09-30'); await p.click('[data-in3-period] button[type=submit]');
        await p.waitForSelector('[data-in3-heads] tbody tr', {timeout: 20000});
        const heads = await p.locator('[data-in3-heads] tbody tr').evaluateAll(rs => rs.map(r => r.dataset.in3Head));
        const docs = await p.locator('[data-in3-docs] tbody tr').count();
        const text = await p.evaluate(() => document.querySelector('.in3-summary')?.innerText || '');
        await p.screenshot({path: '/srv/gate/ev/shots/in3-summary-1440.png', fullPage: true});
        const canadian = text.match(/GST\/HST|\bHST\b|\bPST\b|\bCRA\b|GIFI|Canada|Canadian|\bCAD\b|\$/g) || [];
        return {ok: ['GST12', 'GST18', 'IGST12', 'IGST18'].every(c => codes.includes(c)) && ['CGST', 'SGST', 'IGST'].every(h => heads.includes(h)) && docs >= 9 && /₹/.test(text) && !canadian.length && !errs.length,
          info: JSON.stringify({codes, heads, docs, canadian, errs})};
      } finally { await ctx.close(); }
    });
    await t('UI-02', 'Invoice form: the place of supply follows the customer; an out-of-state customer lists only IGST codes and the line shows IGST; saving records the place of supply', async () => {
      const ctx = await browser.newContext({viewport: {width: 1440, height: 900}, ignoreHTTPSErrors: true}); const p = await ctx.newPage(); const errs = [];
      p.on('pageerror', e => errs.push(e.message.slice(0, 200)));
      try {
        await login(p, true);
        await p.evaluate(() => TeghPortal.openDocumentEditor('customer', {kind: 'invoice'})); await p.waitForSelector('[data-r151-form] [name=partyId]', {timeout: 20000});
        await p.selectOption('[data-r151-form] [name=partyId]', ids.cKA); await p.waitForTimeout(400);
        for (const [n, v] of [['date', '2026-08-20'], ['dueDate', '2026-09-19']]) { const f = p.locator(`[data-r151-form] [name=${n}]`); if (await f.count()) await f.fill(v); }
        const place = await p.locator('[data-r151-form] [name=placeOfSupply]').inputValue();
        const supply = await p.locator('[data-in3-supply]').innerText();
        await p.fill('[data-r151-form] tr[data-r151-line] [name=description]', 'UI GST line'); await p.fill('[data-r151-form] tr[data-r151-line] [name=qty]', '1'); await p.fill('[data-r151-form] tr[data-r151-line] [name=rate]', '1000');
        const options = await p.locator('[data-r151-form] tr[data-r151-line] [name=taxKey] option').evaluateAll(os => os.map(o => o.textContent.trim()));
        await p.selectOption('[data-r151-form] tr[data-r151-line] [name=taxKey]', {label: options.find(o => /^IGST18/.test(o))});
        const acctSel = p.locator('[data-r151-form] tr[data-r151-line] [name=accountId]'); if (await acctSel.count()) { const v = await acctSel.locator('option').evaluateAll(os => (os.find(o => /^4100/.test(o.textContent.trim())) || os.find(o => /^account_/.test(o.value)))?.value); if (v) await acctSel.selectOption(v); }
        const totals = await p.locator('[data-r151-totals]').innerText();
        await p.screenshot({path: '/srv/gate/ev/shots/in3-invoice-1440.png'});
        await p.click('[data-r151-form] button[data-r151-save="post"]').catch(() => {}); await p.waitForTimeout(400);
        const dlg = p.locator('.tegh-action-dialog button[type=submit]'); if (await dlg.count()) await dlg.first().click();
        await p.waitForFunction(() => /issued|saved|refused|error|choose|cannot|invalid/i.test(document.querySelector('.srp-toast')?.textContent || ''), null, {timeout: 20000}).catch(() => {});
        const toast = (await p.locator('.srp-toast').first().textContent().catch(() => '')) || '';
        await p.screenshot({path: '/srv/gate/ev/shots/in3-invoice-saved-1440.png'});
        const saved = sql(`SELECT CONCAT(d.place_of_supply,'|',d.supply,'|',(SELECT GROUP_CONCAT(component_name) FROM document_tax_lines x WHERE x.document_id=i.id AND x.document_type='invoice')) FROM invoices i JOIN india_document_gst d ON d.document_id=i.id AND d.document_type='invoice' WHERE i.company_id='${INCO}' AND i.customer_id='${ids.cKA}' AND EXISTS(SELECT 1 FROM invoice_lines l WHERE l.invoice_id=i.id AND l.description='UI GST line') ORDER BY i.created_at DESC LIMIT 1`);
        return {ok: place === '29' && /Inter-state/.test(supply) && !options.some(o => /^GST18|^GST12/.test(o)) && options.some(o => /^IGST18/.test(o)) && /₹1,180\.00|1,180\.00/.test(totals) && saved === '29|inter|IGST' && !errs.length,
          info: JSON.stringify({place, supply, options, totals: totals.replace(/\s+/g, ' ').slice(0, 120), saved, toast: toast.slice(0, 200), errs})};
      } finally { await ctx.close(); }
    });
    await t('UI-03', 'Canadian company: no GST Summary in the menus and the page is refused', async () => {
      const ctx = await browser.newContext({viewport: {width: 1440, height: 900}, ignoreHTTPSErrors: true}); const p = await ctx.newPage();
      try {
        await login(p, false);
        const html = await p.evaluate(() => document.body.innerText);
        await p.evaluate(() => TeghPortal.openGstSummary()).catch(() => {}); await p.waitForTimeout(800);
        const toast = await p.evaluate(() => document.querySelector('.srp-toast')?.textContent || '');
        return {ok: !/GST Summary/.test(html) && /only in India companies/.test(toast), info: JSON.stringify({menu: /GST Summary/.test(html), toast: toast.slice(0, 120)})};
      } finally { await ctx.close(); }
    });
  } finally { await browser.close(); }
} finally {
  await setMode(startMode).catch(() => {});
  save('in3.json');
}

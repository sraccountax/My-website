// R198 (India only): Tax Set-up, laid out like the payroll set-up: GST rates and TDS / TCS sections as a list (standard rows
// with Tegh's suggested figures, plus the company's own rows with a heading it can rename), then each vendor / customer
// (PAN and section) and each product or service (HSN/SAC and GST rate), from a date. Synthetic data only. Every expected
// TDS figure is worked out by hand below; none is read back from Tegh.
import {S, sql, rec, save, need} from './lib.mjs';
import {chromium} from '/opt/node22/lib/node_modules/playwright/index.mjs';

const A = 'R198';
const t = async (id, title, fn) => { try { const r = await fn(); rec(id, A, title, r.ok ? 'PASS' : 'FAIL', r.info); return r.ok; } catch (e) { rec(id, A, title, 'FAIL', String(e.message || e).slice(0, 600)); return false; } };
const o = new S(); await o.login('owner@gate.test', 'Gate!Owner#2026pw');
const release = async () => (await o.call('site-admin', {company: false})).b.groups.release;
const setRelease = async (k, v) => { const cur = await release(); return o.call('site-admin/save', {method: 'POST', company: false, json: {groups: {release: {value: {...cur.value, [k]: v}, revision: cur.revision}}, reason: 'R198 gate: India tax set-up'}}); };
const start = (await release()).value;
const tag = Date.now().toString(36), EMAIL = `r198-${tag}@gate.test`, UID = `user_r198${tag}`.padEnd(37, '0'), PW = 'Gate!Member#2026pw';
sql(`CREATE TEMPORARY TABLE r198u AS SELECT * FROM users WHERE email='otherowner@gate.test'; UPDATE r198u SET id='${UID}', email='${EMAIL}'; INSERT INTO users SELECT * FROM r198u`);
const R = rupees => Math.round(rupees * 100);
const CH = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
const gstin = b => { let s = 0; for (let i = 0; i < 14; i++) { const p = CH.indexOf(b[i]) * (i % 2 === 0 ? 1 : 2); s += Math.floor(p / 36) + p % 36; } return b + CH[(36 - s % 36) % 36]; };
const n4 = String(Date.now() % 10000).padStart(4, '0'), PAN = 'AAACR' + n4 + 'T';
let api, CO = '', browser;
const ids = {};
try {
  need('india on', await setRelease('indiaEdition', 'everyone'));
  api = new S(); await api.login(EMAIL, PW, {edition: 'IN'});
  const created = need('india company', await api.call('companies', {method: 'POST', company: false, json: {name: 'R198 Tax ' + tag, legalName: 'R198 Tax Private Limited', constitution: 'private_limited', state: 'KA', pin: '560001', building: '1 Test Street', street: 'MG Road', locality: 'Ashok Nagar', city: 'Bengaluru', district: 'Bengaluru Urban',
    financialYearStart: '2026-04-01', financialYearEnd: '2027-03-31', timezone: 'Asia/Kolkata', bankAccountType: 'current', coaMode: 'default', pan: PAN, gstin: gstin('29' + PAN + '1Z')}}), [201]);
  CO = created.company.id; api.cid = CO;
  const P = (r, j) => api.call('india/setup/' + r, {method: 'POST', json: j});
  const state = async () => need('tax state', await api.call('india/setup/tax'));
  const acct = c => sql(`SELECT id FROM accounts WHERE company_id='${CO}' AND code='${c}'`);

  await t('R198-01', 'Tax Set-up lists the standard TDS sections with suggested rates and limits (194C individual 1% and others 2%, ₹30,000 / ₹1,00,000; 194J 10%; 194H 2%; 194I rent 10% and 2%, ₹50,000 a month) and nothing is saved before confirming; a Canadian company gets 404', async () => {
    const s = await state();
    const sug = Object.fromEntries(s.tds.suggestions.map(x => [x.code, [x.rate, x.single, x.yearly]]));
    const unconfirmed = await P('sections', {kind: 'tds', effectiveFrom: '2026-04-01', rows: [{code: '194C', ratePercent: '2'}]});
    const n = Number(sql(`SELECT COUNT(*) FROM statutory_rates WHERE company_id='${CO}' AND person_type IN ('tds_section','tcs_section')`));
    const ca = new S(); await ca.login('owner@gate.test', 'Gate!Owner#2026pw');
    ca.cid = sql(`SELECT c.id FROM companies c JOIN company_members cm ON cm.company_id=c.id JOIN users u ON u.id=cm.user_id WHERE u.email='owner@gate.test' AND (c.edition IS NULL OR c.edition<>'IN') AND c.active=1 AND cm.role='owner' LIMIT 1`);
    const caR = await ca.call('india/setup/tax');
    const ok = s.tds.sections.length === 0 && s.tds.noPanPercent === '20' && JSON.stringify(sug['194C-IND']) === '["1",30000,100000]' && JSON.stringify(sug['194C']) === '["2",30000,100000]'
      && JSON.stringify(sug['194J']) === '["10",50000,50000]' && JSON.stringify(sug['194H']) === '["2",20000,20000]' && JSON.stringify(sug['194I']) === '["10",50000,null]' && JSON.stringify(sug['194I-PM']) === '["2",50000,null]'
      && unconfirmed.s === 422 && unconfirmed.b.code === 'india_setup_confirm' && n === 0 && caR.s === 404;
    return {ok, info: JSON.stringify({sug, unconfirmed: [unconfirmed.s, unconfirmed.b.code], n, ca: caR.s})};
  });

  await t('R198-02', 'Confirming TDS sections saves and approves each one (standard heading kept), with a "<section>-NOPAN" rule at 20% for parties without a PAN; an own section (194A) keeps its heading; repeating changes nothing; a different figure from the same date is refused (409); a later date adds a version', async () => {
    const rows = [{code: '194C-IND', ratePercent: '1', singleRupees: '30000', yearlyRupees: '100000', label: 'ignored for standard rows'}, {code: '194J', ratePercent: '10', singleRupees: '50000', yearlyRupees: '50000'},
      {code: '194I', ratePercent: '10', singleRupees: '50000', yearlyRupees: ''}, {code: '194a', label: 'Interest other than bank interest', ratePercent: '10', singleRupees: '10000', yearlyRupees: '10000'}];
    const r1 = need('sections', await P('sections', {kind: 'tds', effectiveFrom: '2026-04-01', confirmed: true, rows, noPanPercent: '20'}));
    const rules = sql(`SELECT GROUP_CONCAT(CONCAT(rate_key,'=',TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM value_decimal)),':',IFNULL(threshold_min_cents,'-'),':',IFNULL(threshold_max_cents,'-'),':',status) ORDER BY rate_key SEPARATOR ',') FROM statutory_rates WHERE company_id='${CO}' AND person_type='tds_section'`);
    const labels = sql(`SELECT GROUP_CONCAT(CONCAT(rate_key,'|',label) ORDER BY rate_key SEPARATOR ';') FROM statutory_rates WHERE company_id='${CO}' AND person_type='tds_section' AND rate_key IN ('194C-IND','194A')`);
    const again = need('again', await P('sections', {kind: 'tds', effectiveFrom: '2026-04-01', confirmed: true, rows, noPanPercent: '20'}));
    const conflict = await P('sections', {kind: 'tds', effectiveFrom: '2026-04-01', confirmed: true, rows: [{code: '194J', ratePercent: '7.5', singleRupees: '50000', yearlyRupees: '50000'}]});
    const want = '194A-NOPAN=20:1000000:1000000:active,194A=10:1000000:1000000:active,194C-IND-NOPAN=20:3000000:10000000:active,194C-IND=1:3000000:10000000:active,194I-NOPAN=20:5000000:-:active,194I=10:5000000:-:active,194J-NOPAN=20:5000000:5000000:active,194J=10:5000000:5000000:active';
    const ok = rules.split(',').sort().join(',') === want.split(',').sort().join(',') && labels === '194A|Interest other than bank interest;194C-IND|Contractors: individual or HUF' && again.sections.every(x => x.rule.status === 'unchanged' && x.noPan.status === 'unchanged')
      && conflict.s === 409 && r1.state.tds.sections.length === 4 && r1.state.tds.sections.find(x => x.code === '194A').fixed === false && r1.state.tds.sections.find(x => x.code === '194J').fixed === true;
    return {ok, info: JSON.stringify({rules, labels, again: again.sections.map(x => x.rule.status), conflict: [conflict.s, conflict.b.code]})};
  });

  await t('R198-03', 'Headings: the own section 194A is renamed (and its no-PAN rule follows); a standard section and a standard GST rate cannot be renamed (409); an own GST rate (12%) is created with its heading and renamed, its codes GST12 and IGST12 created', async () => {
    const ren = need('rename', await P('tax-label', {category: 'tds_section', code: '194A', label: 'Interest (not from a bank)'}));
    const l = sql(`SELECT GROUP_CONCAT(label ORDER BY rate_key SEPARATOR '|') FROM statutory_rates WHERE company_id='${CO}' AND rate_key IN ('194A','194A-NOPAN')`);
    const fixed = await P('tax-label', {category: 'tds_section', code: '194J', label: 'x'});
    const gst = need('gst', await P('gst', {rates: ['5', '18', {rate: '12', label: 'GST 12% (old slab, some contracts)'}], effectiveFrom: '2026-04-01', b2clRupees: '100000', confirmed: true}));
    const fixedGst = await P('tax-label', {category: 'gst_rate', code: 'GST18', label: 'x'});
    need('rename gst', await P('tax-label', {category: 'gst_rate', code: 'GST12', label: 'GST 12% (works contracts)'}));
    const s = await state(); const g12 = s.gst.rates.find(x => x.rate === '12');
    const ok = ren.label === 'Interest (not from a bank)' && l === 'Interest (not from a bank)|Interest (not from a bank): party without PAN (206AA)' && fixed.s === 409 && fixedGst.s === 409
      && g12?.label === 'GST 12% (works contracts)' && g12.fixed === false && JSON.stringify(g12.codes.slice().sort()) === '["GST12","IGST12"]' && gst.rates.length === 3;
    return {ok, info: JSON.stringify({l, fixed: fixed.s, fixedGst: fixedGst.s, g12})};
  });

  // Vendors, bills and products for R198-04..06.
  const vend = async name => { const r = need('vendor', await api.call('vendors', {method: 'POST', json: {name: name + ' ' + tag, email: '', address: '', defaultTermsDays: 30, currency: 'INR', country: 'India', province: 'KA'}}), [200, 201]); return r.vendor?.id || r.id; };
  const bill = async (vendorId, number, rupees, date) => { const r = need('bill', await api.call('bills', {method: 'POST', json: {vendorId, number: number + '-' + tag, billDate: date, dueDate: '2026-10-30', currency: 'INR', taxEntryMode: 'exclusive', memo: 'Synthetic service', issue: true,
    lines: [{description: 'Synthetic work', quantity: 1, unitPriceCents: R(rupees), accountId: acct('6400')}]}}), [200, 201]); return r.b?.bill?.id || r.bill?.id || r.id; };
  const preview = async id => need('preview', await api.call('india/tds/preview&documentId=' + id));

  await t('R198-04', 'Vendors: PAN and TDS section are set from the Tax Set-up list (from 1 April 2026); other India details already on the vendor are kept; a wrong PAN and an unknown section are refused', async () => {
    ids.v1 = await vend('R198 Contractor'); ids.v2 = await vend('R198 Consultant'); ids.v3 = await vend('R198 Landlord');
    need('msme', await api.call('india/masters/profile', {method: 'POST', json: {entity: 'vendor', entityId: ids.v1, effectiveFrom: '2026-04-01', data: {msme: true, msmeCategory: 'micro'}}}), [200, 201]);
    need('v1', await P('party', {entity: 'vendor', id: ids.v1, pan: 'abcpk1234f', section: '194C-IND', effectiveFrom: '2026-04-01'}));
    need('v2', await P('party', {entity: 'vendor', id: ids.v2, pan: '', section: '194J', effectiveFrom: '2026-04-01'}));
    need('v3', await P('party', {entity: 'vendor', id: ids.v3, pan: 'ABCPL5678K', section: '194I', effectiveFrom: '2026-04-01'}));
    const badPan = await P('party', {entity: 'vendor', id: ids.v3, pan: 'NOTAPAN', section: '194I', effectiveFrom: '2026-05-01'});
    const badSec = await P('party', {entity: 'vendor', id: ids.v3, pan: 'ABCPL5678K', section: '194ZZ', effectiveFrom: '2026-05-01'});
    const s = await state(); const v1 = s.vendors.find(v => v.id === ids.v1);
    const prof = JSON.parse(sql(`SELECT data_json FROM india_entity_profiles WHERE company_id='${CO}' AND entity_type='vendor' AND entity_id='${ids.v1}' ORDER BY effective_from DESC LIMIT 1`));
    const ok = v1.pan === 'ABCPK1234F' && v1.section === '194C-IND' && v1.effectiveFrom === '2026-04-01' && prof.msme === true && prof.msmeCategory === 'micro' && badPan.s === 422 && badSec.s === 422
      && s.vendors.find(v => v.id === ids.v2).pan === '';
    return {ok, info: JSON.stringify({v1, prof, badPan: [badPan.s, badPan.b.code], badSec: [badSec.s, badSec.b.code]})};
  });

  await t('R198-05', 'TDS on vendor invoices uses what was set up (worked out by hand): contractor 1% — ₹25,000 and ₹20,000 are under both limits; ₹60,000 crosses both, so the base is ₹1,05,000 and TDS ₹1,050; consultant without a PAN 20% of ₹60,000 = ₹12,000; rent ₹45,000 under the ₹50,000 monthly limit = nil, ₹55,000 at 10% = ₹5,500', async () => {
    const b1 = await bill(ids.v1, 'C-1', 25000, '2026-09-02'), b2 = await bill(ids.v1, 'C-2', 20000, '2026-09-09'), b3 = await bill(ids.v1, 'C-3', 60000, '2026-09-16');
    const b4 = await bill(ids.v2, 'P-1', 60000, '2026-09-16'), b5 = await bill(ids.v3, 'R-1', 45000, '2026-09-01'), b6 = await bill(ids.v3, 'R-2', 55000, '2026-10-01');
    const p = {}; for (const [k, id] of Object.entries({b1, b2, b3, b4, b5, b6})) p[k] = await preview(id);
    const got = Object.fromEntries(Object.entries(p).map(([k, x]) => [k, [x.amountCents, x.baseCents, x.rateMpct]]));
    const want = {b1: [0, R(25000), 0], b2: [0, R(20000), 0], b3: [R(1050), R(105000), 1000], b4: [R(12000), R(60000), 20000], b5: [0, R(45000), 0], b6: [R(5500), R(55000), 10000]};
    const posted = need('record', await api.call('india/tds/record', {method: 'POST', json: {kind: 'tds', documentId: b3}}), [201]);
    const bal = Number(sql(`SELECT balance_cents FROM bills WHERE id='${b3}'`));
    return {ok: JSON.stringify(got) === JSON.stringify(want) && bal === R(60000) - R(1050), info: JSON.stringify({got, src: p.b4.rateSource, bal, posted: posted.entry?.amountCents ?? posted.amountCents})};
  });

  await t('R198-06', 'Products and services: HSN/SAC and GST rate set from the list; an invoice line for that service with no tax code takes it (12%: CGST 6% + SGST 6% within Karnataka); a wrong HSN/SAC is refused', async () => {
    const item = need('item', await api.call('portal/products', {method: 'POST', json: {kind: 'service', code: 'R198-' + tag, name: 'R198 Works contract ' + tag, description: '', unitPriceCents: R(10000)}}), [200, 201]);
    ids.item = item.item?.id || item.id || item.product?.id;
    need('item tax', await P('item', {id: ids.item, hsnSac: '995411', gstRate: 'GST12', effectiveFrom: '2026-04-01'}));
    const bad = await P('item', {id: ids.item, hsnSac: '12', gstRate: 'GST12', effectiveFrom: '2026-05-01'});
    const cust = need('customer', await api.call('customers', {method: 'POST', json: {name: 'R198 Buyer ' + tag, email: '', phone: '', billingAddress: '1 Road', country: 'India', province: 'KA'}}), [200, 201]).customer.id;
    const TMPL = (await api.call('invoice-templates')).b.templates?.[0]?.id;
    const inv = need('invoice', await api.call('invoices', {method: 'POST', json: {customerId: cust, issueDate: '2026-09-20', dueDate: '2026-10-20', currency: 'INR', templateId: TMPL, issue: true, lines: [{description: 'Works', quantity: 1, unitPriceCents: R(10000), incomeAccountId: acct('4100'), productServiceId: ids.item}]}}), [201]);
    const id = inv.invoice?.id || inv.id;
    const tax = sql(`SELECT IFNULL(GROUP_CONCAT(CONCAT(component_name,':',tax_cents) ORDER BY component_name SEPARATOR ','),'') FROM document_tax_lines WHERE company_id='${CO}' AND document_type='invoice' AND document_id='${id}'`);
    const s = await state(); const it = s.items.find(x => x.id === ids.item);
    return {ok: tax === `CGST:${R(600)},SGST:${R(600)}` && it.hsnSac === '995411' && it.gstRate === 'GST12' && bad.s === 422, info: JSON.stringify({tax, it, bad: [bad.s, bad.b.code]})};
  });

  await t('R198-07', 'TCS: an own section (206C(1) scrap, 1%) is saved with its heading; a customer is given it; an Editor can set a vendor\'s PAN but cannot confirm sections (403)', async () => {
    const r = need('tcs', await P('sections', {kind: 'tcs', effectiveFrom: '2026-04-01', confirmed: true, rows: [{code: '206C(1)-SCRAP', label: 'Sale of scrap', ratePercent: '1', singleRupees: '', yearlyRupees: ''}]}));
    const cust = (await state()).customers[0];
    need('customer tcs', await P('party', {entity: 'customer', id: cust.id, pan: 'ABCPM1111Q', section: '206C(1)-SCRAP', effectiveFrom: '2026-04-01'}));
    const ED = `r198ed-${tag}@gate.test`, EID = `user_r198e${tag}`.padEnd(37, '0');
    sql(`CREATE TEMPORARY TABLE r198e AS SELECT * FROM users WHERE email='otherowner@gate.test'; UPDATE r198e SET id='${EID}', email='${ED}'; INSERT INTO users SELECT * FROM r198e; INSERT INTO company_members (company_id,user_id,role,status,created_at) VALUES ('${CO}','${EID}','editor','active',NOW())`);
    const ed = new S(); await ed.login(ED, PW, {edition: 'IN'}); ed.cid = CO;
    const edParty = await ed.call('india/setup/party', {method: 'POST', json: {entity: 'vendor', id: ids.v2, pan: 'ABCPN2222R', section: '194J', effectiveFrom: '2026-10-01'}});
    const edSec = await ed.call('india/setup/sections', {method: 'POST', json: {kind: 'tds', effectiveFrom: '2026-04-01', confirmed: true, rows: [{code: '194H', ratePercent: '2'}]}});
    sql(`UPDATE users SET active=0 WHERE id='${EID}'`);
    const s = await state(); const c = s.customers.find(x => x.id === cust.id);
    return {ok: r.state.tcs.sections[0]?.label === 'Sale of scrap' && c.section === '206C(1)-SCRAP' && edParty.s === 200 && edSec.s === 403, info: JSON.stringify({tcs: r.state.tcs.sections, c, edParty: edParty.s, edSec: [edSec.s, edSec.b.code]})};
  });

  // ---------- browser ----------
  browser = await chromium.launch({executablePath: '/opt/pw-browsers/chromium', args: ['--no-proxy-server', '--host-resolver-rules=MAP gate.test 127.0.0.1']});
  const ctx = await browser.newContext({viewport: {width: 1440, height: 900}, ignoreHTTPSErrors: true});
  const p = await ctx.newPage(); const errs = [];
  p.on('pageerror', e => errs.push(e.message.slice(0, 160)));
  p.on('response', async r => { if (r.status() >= 400 && /\/api\//.test(r.url()) && !/auth%2Fme/.test(r.url())) { let m = ''; try { m = (await r.json()).code || ''; } catch {} errs.push('api ' + r.status() + ' ' + r.url().replace(/^.*route=/, '').slice(0, 50) + ' ' + m); } });
  await ctx.addInitScript(id => { try { if (!sessionStorage.getItem('r198')) { localStorage.setItem('sr-accountax-company', id); sessionStorage.setItem('r198', '1'); } } catch {} }, CO);
  await p.goto('https://gate.test/app.html?edition=in'); await p.waitForSelector('#sr-login-form');
  await p.selectOption('#sr-login-form [name=edition]', 'IN');
  await p.fill('#sr-login-form input[name=email]', EMAIL); await p.fill('#sr-login-form input[name=password]', PW); await p.click('#sr-login-form button[type=submit]');
  await p.waitForFunction(() => window.TeghPortal?.openIndiaTaxSetup && document.querySelector('.srp-page[data-srp-page]'), null, {timeout: 60000});
  const open = async () => { await p.evaluate(() => TeghPortal.openIndiaTaxSetup()); await p.waitForSelector('[data-r198-card="items"]', {timeout: 20000}); await p.waitForTimeout(400); };

  await t('UI-01', 'Tax Set-up (Settings › Tax Set-up) shows GST rates, TDS sections (7 standard rows plus the own 194A row), TCS sections, vendors and customers, products and services; ticking 194H and saving from the screen saves the approved rule; an own GST rate (28%, with a heading) is added from the screen', async () => {
    const nav = await p.evaluate(() => { const m = [...document.querySelectorAll('[data-sr-settings-item], .srp-settings a, button')].map(b => b.textContent.trim()); return m.includes('Tax Set-up') || null; });
    await open();
    const cards = await p.$$eval('[data-r198-card]', l => l.map(x => x.dataset.r198Card));
    const tdsRows = await p.$$eval('[data-r198-sections="tds"] tr[data-r198-row]', l => l.map(x => x.dataset.code));
    await p.locator('[data-r198-sections="tds"] tr[data-code="194H"] [data-f=use]').check();
    await p.locator('[data-r198-sections="tds"] input[name=effectiveFrom]').fill('2026-04-01');
    await p.locator('[data-r198-sections="tds"] input[name=confirmed]').check(); await p.locator('[data-r198-sections="tds"] button[type=submit]').click();
    await p.waitForSelector('[data-r198-sections="tds"] tr[data-code="194H"].is-set', {timeout: 20000});
    const h = sql(`SELECT CONCAT(TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM value_decimal)),':',threshold_min_cents,':',threshold_max_cents,':',status) FROM statutory_rates WHERE company_id='${CO}' AND rate_key='194H'`);
    await p.fill('[data-r196-gst] input[name=other]', '28'); await p.fill('[data-r196-gst] input[name=otherLabel]', 'GST 28% (old slab)');
    await p.check('[data-r196-gst] input[name=confirmed]'); await p.click('[data-r196-gst] button[type=submit]');
    await p.waitForSelector('[data-r198-label="gst_rate"][data-code="GST28"]', {timeout: 20000});
    const g28 = sql(`SELECT label FROM statutory_rates WHERE company_id='${CO}' AND rate_key='GST28'`);
    await p.screenshot({path: '/srv/gate/ev/shots/r198-tax-1440.png', fullPage: true});
    await p.locator('[data-r198-card="tds"]').screenshot({path: '/srv/gate/ev/shots/r198-tds-1440.png'});
    return {ok: JSON.stringify(cards) === '["gst","tds","tcs","parties","items"]' && tdsRows.length === 8 && tdsRows.includes('194A') && h === '2:2000000:2000000:active' && g28 === 'GST 28% (old slab)' && !errs.length,
      info: JSON.stringify({nav, cards, tdsRows, h, g28, errs})};
  });

  await t('UI-02', 'From the screen: the own section heading is renamed; a vendor gets a PAN from 1 November 2026 (a new version, the earlier one kept); a product\'s GST rate changes to 18% from 1 November 2026 (invoices before that date keep 12%)', async () => {
    await open();
    const box = p.locator('[data-r198-label="tds_section"][data-code="194A"]');
    await box.locator('[data-r198-label-name]').fill('Interest from others'); await box.locator('[data-r198-rename]').click();
    await p.waitForFunction(() => document.querySelector('[data-r198-label="tds_section"][data-code="194A"] input')?.value === 'Interest from others', null, {timeout: 15000});
    await p.locator('[data-r198-parties="vendor"] tr', {hasText: 'R198 Consultant'}).locator('[data-r198-edit]').click();
    const row = p.locator('[data-r198-parties="vendor"] tr.is-editing');
    await row.locator('[data-f=pan]').fill('abcpn2222r'); await row.locator('[data-f=from]').fill('2026-11-01'); await row.locator('[data-r198-save]').click();
    await p.waitForFunction(() => [...document.querySelectorAll('[data-r198-parties="vendor"] tr')].some(r => r.innerText.includes('R198 Consultant') && r.innerText.includes('ABCPN2222R')), null, {timeout: 15000});
    await p.locator('.r198-items tr', {hasText: 'R198 Works contract'}).locator('[data-r198-edit]').click();
    const ir = p.locator('.r198-items tr.is-editing');
    await ir.locator('[data-f=gst]').selectOption('GST18'); await ir.locator('[data-f=from]').fill('2026-11-01'); await ir.locator('[data-r198-save]').click();
    await p.waitForFunction(() => [...document.querySelectorAll('.r198-items tr')].some(r => r.innerText.includes('R198 Works contract') && /GST 18%/.test(r.innerText)), null, {timeout: 15000});
    const vers = sql(`SELECT GROUP_CONCAT(CONCAT(effective_from,'=',IFNULL(JSON_UNQUOTE(JSON_EXTRACT(data_json,'$.pan')),'-')) ORDER BY effective_from SEPARATOR ',') FROM india_entity_profiles WHERE company_id='${CO}' AND entity_type='vendor' AND entity_id='${ids.v2}'`);
    const iv = sql(`SELECT GROUP_CONCAT(CONCAT(effective_from,'=',JSON_UNQUOTE(JSON_EXTRACT(data_json,'$.gstRate'))) ORDER BY effective_from SEPARATOR ',') FROM india_entity_profiles WHERE company_id='${CO}' AND entity_type='item' AND entity_id='${ids.item}'`);
    const label = sql(`SELECT label FROM statutory_rates WHERE company_id='${CO}' AND rate_key='194A'`);
    return {ok: label === 'Interest from others' && vers === '2026-04-01=-,2026-10-01=ABCPN2222R,2026-11-01=ABCPN2222R' && iv === '2026-04-01=GST12,2026-11-01=GST18' && !errs.length, info: JSON.stringify({label, vers, iv, errs})};
  });

  await t('UI-03', 'Phone (390 px) and dark theme: Tax Set-up fits the screen (no sideways scroll of the page; wide tables scroll inside their box)', async () => {
    await p.setViewportSize({width: 390, height: 844}); await open();
    const over = await p.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1);
    await p.locator('[data-r198-card="tds"]').screenshot({path: '/srv/gate/ev/shots/r198-tds-390.png'});
    await p.setViewportSize({width: 1440, height: 900});
    const pref = async v => { await p.evaluate(() => TeghPortal.openTeghPreferences()); await p.waitForSelector('[data-tegh-preferences]', {timeout: 20000}); await p.locator(`[data-tegh-preferences] input[name=theme][value=${v}]`).check({force: true}); await p.locator('[data-tegh-preferences] button[type=submit]').first().click(); await p.waitForTimeout(1500); };
    await pref('dark'); await open();
    await p.locator('[data-r198-card="tds"]').screenshot({path: '/srv/gate/ev/shots/r198-tds-dark.png'});
    const dark = await p.evaluate(() => {
      const lum = c => { const m = c.match(/\d+(\.\d+)?/g); if (!m) return 0; const [r, g, b, a = 1] = m.map(Number); if (+a === 0) return null; return (0.2126 * r + 0.7152 * g + 0.0722 * b) / 255; };
      const light = [...document.querySelectorAll('[data-r198-card], [data-r198-card] *')].filter(e => { const r = e.getBoundingClientRect(); const l = lum(getComputedStyle(e).backgroundColor); return r.width * r.height > 2000 && l !== null && l > 0.85; }).map(e => e.tagName + '.' + e.className + ':' + e.textContent.trim().slice(0, 30) + ':' + e.offsetParent?.tagName).slice(0, 5);
      return {theme: document.documentElement.dataset.teghTheme, light, input: getComputedStyle(document.querySelector('.r198-sections input[data-f=rate]')).backgroundColor};
    });
    await pref('light');
    return {ok: !over && dark.theme === 'dark' && !dark.light.length && !errs.length, info: JSON.stringify({over, dark, errs})};
  });
  await ctx.close();
} finally {
  if (browser) await browser.close().catch(() => {});
  await setRelease('indiaEdition', start.indiaEdition || 'off').catch(() => {});
  try { sql(`UPDATE users SET active=0 WHERE id='${UID}'`); } catch {}
  save('r198.json');
}

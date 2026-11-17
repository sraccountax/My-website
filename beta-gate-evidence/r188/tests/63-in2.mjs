// IN-2: India master data. Profiles, records and statutory rules of an India company: validation, effective-dated
// versions that keep history, approval of rules, roles, company isolation, audit, and no effect on Canadian companies.
// Synthetic data only: rule codes, rates and sources below are invented test values, not Indian law.
import {S, sql, rec, save, need} from './lib.mjs';
import {chromium} from '/opt/node22/lib/node_modules/playwright/index.mjs';

const A = 'IN2';
const t = async (id, title, fn) => { try { const r = await fn(); rec(id, A, title, r.ok ? 'PASS' : 'FAIL', r.info); return r.ok; } catch (e) { rec(id, A, title, 'FAIL', String(e.message || e).slice(0, 400)); return false; } };
const PW = 'Gate!Owner#2026pw', MPW = 'Gate!Member#2026pw';
const o = new S(); await o.login('owner@gate.test', PW);
const release = async () => (await o.call('site-admin', {company: false})).b.groups.release;
const setMode = async m => { const cur = await release(); return o.call('site-admin/save', {method: 'POST', company: false, json: {groups: {release: {value: {...cur.value, indiaEdition: m}, revision: cur.revision}}, reason: 'IN-2 gate ' + m}}); };
const startMode = (await release()).value.indiaEdition || 'off';
const tag = Date.now().toString(36);
const CH = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
const gstin = b => { let s = 0; for (let i = 0; i < 14; i++) { const p = CH.indexOf(b[i]) * (i % 2 === 0 ? 1 : 2); s += Math.floor(p / 36) + p % 36; } return b + CH[(36 - s % 36) % 36]; };
const PAN = 'AAACG' + String(1000 + (Date.now() % 9000)).slice(0, 4) + 'K', OTHER_PAN = 'AAAPZ' + String(1000 + (Date.now() % 9000)).slice(0, 4) + 'Q';
const day = (d, n) => { const x = new Date(d + 'T00:00:00Z'); x.setUTCDate(x.getUTCDate() + n); return x.toISOString().slice(0, 10); };
const P = (s, r, j) => s.call('india/masters/' + r, {method: 'POST', json: j});
const G = (s, r, q = '') => s.call('india/masters/' + r + q);
const audits = (co, act) => Number(sql(`SELECT COUNT(*) FROM audit_log WHERE company_id='${co}' AND action='${act}'`));

let IN, ED, INCO = '', CACO = '', VENDOR = '';
try {
  need('mode', await setMode('everyone'));
  IN = new S(); await IN.login('owner@gate.test', PW, {edition: 'IN'});
  INCO = need('india company', await IN.call('companies', {method: 'POST', company: false, json: {name: 'IN2 Masters ' + tag, legalName: 'IN2 Masters Private Limited', constitution: 'private_limited', state: 'MH', pin: '400001', city: 'Mumbai', fiscalYearEnd: '03-31', booksStartDate: '2026-04-01', pan: PAN, gstin: gstin('27' + PAN + '1Z')}}), [201]).company.id;
  IN.cid = INCO;
  CACO = sql(`SELECT c.id FROM companies c JOIN company_members cm ON cm.company_id=c.id JOIN users u ON u.id=cm.user_id WHERE u.email='owner@gate.test' AND (c.edition IS NULL OR c.edition<>'IN') AND c.active=1 AND cm.role='owner' LIMIT 1`);
  // Editor membership in the synthetic India company (test database only).
  sql(`INSERT INTO company_members (company_id,user_id,role,status) SELECT '${INCO}',id,'editor','active' FROM users WHERE email='editor@gate.test'`);
  ED = new S(); await ED.login('editor@gate.test', MPW, {edition: 'IN'}); ED.cid = INCO;

  need('spec (creates the India tables on first use)', await G(IN, 'spec'));
  await t('IN2-01', 'Canada is unaffected: a Canadian company gets 404 on every India master route and gains no India rows', async () => {
    const ca = new S(); await ca.login('owner@gate.test', PW); ca.cid = CACO;
    const before = sql(`SELECT CONCAT_WS('|',(SELECT COUNT(*) FROM statutory_rates WHERE company_id='${CACO}'),(SELECT COUNT(*) FROM accounts WHERE company_id='${CACO}'))`);
    const r = [await G(ca, 'spec'), await G(ca, 'rules'), await P(ca, 'rule', {category: 'tds_section', code: 'X', label: 'x', effectiveFrom: '2026-04-01', source: 'x'}), await P(ca, 'profile', {entity: 'company', data: {}})];
    const after = sql(`SELECT CONCAT_WS('|',(SELECT COUNT(*) FROM statutory_rates WHERE company_id='${CACO}'),(SELECT COUNT(*) FROM accounts WHERE company_id='${CACO}'))`);
    const rows = sql(`SELECT (SELECT COUNT(*) FROM india_entity_profiles WHERE company_id='${CACO}')+(SELECT COUNT(*) FROM india_master_records WHERE company_id='${CACO}')`);
    return {ok: r.every(x => x.s === 404 && x.b.code === 'india_only') && before === after && rows === '0', info: JSON.stringify({codes: r.map(x => [x.s, x.b.code]), before, after, rows})};
  });

  await t('IN2-02', 'Spec covers the matrix (7 profile types, 14 record types, 29 rule categories); a new India company has no statutory rules seeded', async () => {
    const s = need('spec', await G(IN, 'spec'));
    const n = sql(`SELECT COUNT(*) FROM statutory_rates WHERE company_id='${INCO}'`);
    return {ok: Object.keys(s.profiles).length === 7 && Object.keys(s.records).length === 14 && Object.keys(s.ruleCategories).length === 29 && n === '0' && s.canWrite && s.canApprove && (s.states || []).length === 36,
      info: JSON.stringify({profiles: Object.keys(s.profiles), records: Object.keys(s.records).length, rules: Object.keys(s.ruleCategories).length, seeded: n})};
  });

  let R1 = '', R2 = '';
  await t('IN2-03', 'Statutory rule: bad rate, bad link and missing source are refused; draft until approved; a later version ends the earlier one the day before; same date twice is refused', async () => {
    const base = {category: 'tds_section', code: 'GATE-SEC-' + tag, label: 'Synthetic section for the gate', effectiveFrom: '2026-04-01', ratePercent: '1.5', thresholdMinCents: '3000000', source: 'Synthetic gate data'};
    const bad = [await P(IN, 'rule', {...base, ratePercent: '150'}), await P(IN, 'rule', {...base, sourceUrl: 'http://x.example'}), await P(IN, 'rule', {...base, source: ''}), await P(IN, 'rule', {...base, category: 'made_up'})];
    R1 = need('rule v1', await P(IN, 'rule', base), [201]).id;
    const dupe = await P(IN, 'rule', base);
    const draftRef = await P(IN, 'profile', {entity: 'company', effectiveFrom: '2026-04-01', data: {incomeTaxRegime: 'NOPE'}});
    need('approve v1', await P(IN, 'rule-approve', {id: R1}));
    R2 = need('rule v2', await P(IN, 'rule', {...base, effectiveFrom: '2026-10-01', ratePercent: '2'}), [201]).id;
    need('approve v2', await P(IN, 'rule-approve', {id: R2}));
    const again = await P(IN, 'rule-approve', {id: R2});
    const v = sql(`SELECT GROUP_CONCAT(CONCAT_WS(':',effective_from,IFNULL(effective_to,'-'),status,value_decimal*1e0,jurisdiction,person_type,IF(approved_by IS NULL,'unapproved','approved')) ORDER BY effective_from) FROM statutory_rates WHERE company_id='${INCO}' AND rate_key='GATE-SEC-${tag}'`);
    const want = `2026-04-01:2026-09-30:active:1.5:IN:tds_section:approved,2026-10-01:-:active:2:IN:tds_section:approved`;
    return {ok: bad.every(x => x.s === 422) && dupe.s === 409 && dupe.b.code === 'india_rule_version_exists' && draftRef.s === 422 && again.s === 409 && v === want && audits(INCO, 'india.rule_approved') === 2,
      info: JSON.stringify({bad: bad.map(x => x.b.code), dupe: dupe.b.code, draftRef: draftRef.b.code, again: again.b.code, v})};
  });

  await t('IN2-04', 'Company profile: invalid IEC / Udyam and unknown fields are refused; a rule reference must exist; a later effective date adds a version and keeps the earlier one', async () => {
    const regime = need('regime', await P(IN, 'rule', {category: 'it_regime', code: 'GATE-REGIME', label: 'Synthetic regime', effectiveFrom: '2026-04-01', source: 'Synthetic gate data'}), [201]).id;
    const bad = [await P(IN, 'profile', {entity: 'company', effectiveFrom: '2026-04-01', data: {iec: 'SHORT'}}), await P(IN, 'profile', {entity: 'company', effectiveFrom: '2026-04-01', data: {udyamNumber: 'UDYAM-123'}}),
      await P(IN, 'profile', {entity: 'company', effectiveFrom: '2026-04-01', data: {gifiCode: '1000'}}), await P(IN, 'profile', {entity: 'company', effectiveFrom: '2026-04-01', data: {incomeTaxRegime: 'NOT-A-RULE'}})];
    need('v1', await P(IN, 'profile', {entity: 'company', effectiveFrom: '2026-04-01', data: {iec: 'AB12345678', udyamNumber: 'UDYAM-MH-01-0000001', msmeCategory: 'small', incomeTaxRegime: 'GATE-REGIME', taxAuditApplicable: true}}), [201]);
    need('v2', await P(IN, 'profile', {entity: 'company', effectiveFrom: '2026-10-01', data: {iec: 'AB12345678', msmeCategory: 'medium', incomeTaxRegime: 'GATE-REGIME'}}), [201]);
    const g = need('get', await G(IN, 'profile', '?entity=company'));
    const v1 = JSON.parse(sql(`SELECT data_json FROM india_entity_profiles WHERE company_id='${INCO}' AND entity_type='company' AND effective_from='2026-04-01'`));
    const au = sql(`SELECT metadata_json FROM audit_log WHERE company_id='${INCO}' AND action='india.profile_saved' ORDER BY created_at DESC LIMIT 1`);
    return {ok: bad.every(x => x.s === 422) && g.history.length === 2 && g.current.effectiveFrom === '2026-10-01' && g.current.data.msmeCategory === 'medium' && v1.msmeCategory === 'small' && v1.udyamNumber === 'UDYAM-MH-01-0000001' && regime && /"before"/.test(au) && /small/.test(au) && /medium/.test(au),
      info: JSON.stringify({bad: bad.map(x => x.b.code), current: g.current, versions: g.history.length, v1})};
  });

  await t('IN2-05', 'Vendor profile and company isolation: PAN validated; a record of another company is not found; an India vendor gets PAN, MSME and TDS section', async () => {
    VENDOR = need('vendor', await IN.call('vendors', {method: 'POST', json: {name: 'IN2 Supplier ' + tag, email: '', address: '', defaultTermsDays: 30, currency: 'INR', country: 'India', province: 'MH'}}), [200, 201]);
    VENDOR = VENDOR.vendor?.id || VENDOR.id;
    const caVendor = sql(`SELECT id FROM vendors WHERE company_id='${CACO}' LIMIT 1`);
    const cross = await P(IN, 'profile', {entity: 'vendor', entityId: caVendor, effectiveFrom: '2026-04-01', data: {}});
    const badPan = await P(IN, 'profile', {entity: 'vendor', entityId: VENDOR, effectiveFrom: '2026-04-01', data: {pan: 'ABCDE1234'}});
    const ok = await P(IN, 'profile', {entity: 'vendor', entityId: VENDOR, effectiveFrom: '2026-04-01', data: {pan: OTHER_PAN, msme: true, udyamNumber: 'UDYAM-KA-02-0000002', msmeCategory: 'micro', agreedPaymentDays: '30', tdsSection: 'GATE-SEC-' + tag}});
    return {ok: cross.s === 404 && cross.b.code === 'india_entity_missing' && badPan.s === 422 && ok.s === 201 && ok.b.data.pan === OTHER_PAN && ok.b.data.agreedPaymentDays === 30,
      info: JSON.stringify({cross: [cross.s, cross.b.code], badPan: badPan.b.code, ok: ok.s, data: ok.b.data})};
  });

  await t('IN2-06', 'GST registrations: another PAN is refused for the company; a second state is added; a change with a later date supersedes the old row (end date the day before) and keeps it', async () => {
    const foreign = await P(IN, 'record', {recordType: 'gst_registration', entity: 'company', effectiveFrom: '2026-04-01', data: {gstin: gstin('29' + OTHER_PAN + '1Z'), registrationType: 'regular', status: 'active', validFrom: '2026-04-01'}});
    const badCheck = await P(IN, 'record', {recordType: 'gst_registration', entity: 'company', effectiveFrom: '2026-04-01', data: {gstin: '29' + PAN + '1Z0', registrationType: 'regular', status: 'active', validFrom: '2026-04-01'}});
    const ka = need('KA', await P(IN, 'record', {recordType: 'gst_registration', entity: 'company', effectiveFrom: '2026-04-01', data: {gstin: gstin('29' + PAN + '1Z'), registrationType: 'regular', status: 'active', validFrom: '2026-04-01'}}), [201]);
    const noDate = await P(IN, 'record', {recordType: 'gst_registration', entity: 'company', effectiveFrom: '2026-11-01', replacesId: ka.id, data: {gstin: gstin('29' + PAN + '1Z'), registrationType: 'regular', status: 'cancelled', validFrom: '2026-04-01'}});
    const ch = need('change', await P(IN, 'record', {recordType: 'gst_registration', entity: 'company', effectiveFrom: '2026-11-01', replacesId: ka.id, data: {gstin: gstin('29' + PAN + '1Z'), registrationType: 'regular', status: 'cancelled', validFrom: '2026-04-01', cancelledOn: '2026-11-01'}}), [201]);
    const stale = await P(IN, 'record', {recordType: 'gst_registration', entity: 'company', effectiveFrom: '2026-12-01', replacesId: ka.id, data: {gstin: gstin('29' + PAN + '1Z'), registrationType: 'regular', status: 'active', validFrom: '2026-04-01'}});
    const old = sql(`SELECT CONCAT_WS('|',status,effective_to) FROM india_master_records WHERE id='${ka.id}'`), nw = sql(`SELECT CONCAT_WS('|',status,replaces_id) FROM india_master_records WHERE id='${ch.id}'`);
    const active = need('list', await G(IN, 'records', '?recordType=gst_registration')).records, all = need('hist', await G(IN, 'records', '?recordType=gst_registration&history=1')).records;
    return {ok: foreign.s === 422 && foreign.b.code === 'india_gstin_pan' && badCheck.s === 422 && noDate.s === 422 && old === 'superseded|2026-10-31' && nw === `active|${ka.id}` && stale.s === 409 && active.length === 1 && all.length === 2,
      info: JSON.stringify({foreign: foreign.b.code, badCheck: badCheck.b.code, noDate: noDate.b.code, old, nw, stale: stale.b.code, active: active.length, all: all.length})};
  });

  await t('IN2-07', 'Roles: an Editor sets item HSN/SAC and records but cannot change company or account mappings or add/approve rules; HSN and UQC formats are checked', async () => {
    const item = need('item', await IN.call('portal/products', {method: 'POST', json: {kind: 'service', code: 'IN2-' + tag, name: 'IN2 consulting ' + tag, description: '', unitPriceCents: 100000}}), [200, 201]);
    const itemId = item.item?.id || item.product?.id || item.id;
    const acct = sql(`SELECT id FROM accounts WHERE company_id='${INCO}' AND code='6000'`);
    const r = {badHsn: await P(ED, 'profile', {entity: 'item', entityId: itemId, effectiveFrom: '2026-04-01', data: {hsnSac: '12345'}}),
      badUqc: await P(ED, 'profile', {entity: 'item', entityId: itemId, effectiveFrom: '2026-04-01', data: {uqc: 'nos1'}}),
      item: await P(ED, 'profile', {entity: 'item', entityId: itemId, effectiveFrom: '2026-04-01', data: {hsnSac: '998314', uqc: 'OTH', gstTreatment: 'taxable'}}),
      account: await P(ED, 'profile', {entity: 'account', entityId: acct, effectiveFrom: '2026-04-01', data: {section14a: true}}),
      company: await P(ED, 'profile', {entity: 'company', effectiveFrom: '2026-04-01', data: {}}),
      rule: await P(ED, 'rule', {category: 'gst_rate', code: 'X', label: 'x', effectiveFrom: '2026-04-01', source: 'x'}),
      approve: await P(ED, 'rule-approve', {id: R1}),
      wh: await P(ED, 'record', {recordType: 'warehouse', effectiveFrom: '2026-04-01', data: {code: 'WH1', name: 'Bhiwandi godown', state: 'MH'}})};
    const acctOwner = await P(IN, 'profile', {entity: 'account', entityId: acct, effectiveFrom: '2026-04-01', data: {section14a: true, section269: '269ST', tdsSection: 'GATE-SEC-' + tag}});
    const s = need('spec', await G(ED, 'spec'));
    return {ok: r.badHsn.s === 422 && r.badUqc.s === 422 && r.item.s === 201 && r.account.s === 403 && r.company.s === 403 && r.rule.s === 403 && r.approve.s === 403 && r.wh.s === 201 && acctOwner.s === 201 && s.canWrite && !s.canApprove,
      info: JSON.stringify(Object.fromEntries(Object.entries(r).map(([k, v]) => [k, v.s + ' ' + (v.b.code || '')]))) + ' owner-account ' + acctOwner.s};
  });

  await t('IN2-08', 'Records refer to records: a storage location needs an active warehouse; after the warehouse is made inactive it can no longer be chosen; history keeps it', async () => {
    const wh = need('wh', await G(IN, 'records', '?recordType=warehouse')).records[0];
    const loc = await P(IN, 'record', {recordType: 'location', effectiveFrom: '2026-04-01', data: {warehouse: wh.id, code: 'R1', name: 'Rack 1'}});
    const off = await P(IN, 'record-deactivate', {id: wh.id, effectiveTo: '2026-12-31'});
    const loc2 = await P(IN, 'record', {recordType: 'location', effectiveFrom: '2026-04-01', data: {warehouse: wh.id, code: 'R2', name: 'Rack 2'}});
    const list = need('list', await G(IN, 'records', '?recordType=warehouse')).records, hist = need('hist', await G(IN, 'records', '?recordType=warehouse&history=1')).records;
    return {ok: loc.s === 201 && off.s === 200 && loc2.s === 422 && loc2.b.code === 'india_reference_missing' && list.length === 0 && hist.length === 1 && hist[0].status === 'inactive' && hist[0].effectiveTo === '2026-12-31',
      info: JSON.stringify({loc: loc.s, off: off.s, loc2: loc2.b.code, list: list.length, hist: hist.map(h => [h.status, h.effectiveTo])})};
  });

  await t('IN2-09', 'Employee, pay component, bank (IFSC) and payment-method records validate their formats; every change is in the audit log', async () => {
    const r = {badPan: await P(IN, 'record', {recordType: 'employee', effectiveFrom: '2026-04-01', data: {employeeCode: 'E1', name: 'Test Person', pan: 'X', dateOfJoining: '2026-04-01'}}),
      badUan: await P(IN, 'record', {recordType: 'employee', effectiveFrom: '2026-04-01', data: {employeeCode: 'E1', name: 'Test Person', uan: '123', dateOfJoining: '2026-04-01'}}),
      emp: await P(IN, 'record', {recordType: 'employee', effectiveFrom: '2026-04-01', data: {employeeCode: 'E1', name: 'Test Person', pan: 'ABCPE1234F', uan: '100000000001', epfApplicable: true, ptApplicable: true, ptState: 'MH', dateOfJoining: '2026-04-01'}}),
      comp: await P(IN, 'record', {recordType: 'pay_component', effectiveFrom: '2026-04-01', data: {name: 'Basic', kind: 'earning', calculation: 'entered', taxable: true}}),
      pm: await P(IN, 'record', {recordType: 'payment_method', effectiveFrom: '2026-04-01', data: {name: 'Petty cash', channel: 'cash'}})};
    const bank = sql(`SELECT id FROM bank_accounts WHERE company_id='${INCO}' LIMIT 1`);
    const badIfsc = await P(IN, 'profile', {entity: 'bank_account', entityId: bank, effectiveFrom: '2026-04-01', data: {ifsc: 'HDFC1234567'}});
    const ifsc = await P(IN, 'profile', {entity: 'bank_account', entityId: bank, effectiveFrom: '2026-04-01', data: {ifsc: 'HDFC0001234', accountKind: 'current', upiId: 'in2gate@okbank'}});
    const n = Number(sql(`SELECT COUNT(*) FROM audit_log WHERE company_id='${INCO}' AND action LIKE 'india.%'`));
    return {ok: r.badPan.s === 422 && r.badUan.s === 422 && r.emp.s === 201 && r.comp.s === 201 && r.pm.s === 201 && badIfsc.s === 422 && ifsc.s === 201 && n >= 16,
      info: JSON.stringify({...Object.fromEntries(Object.entries(r).map(([k, v]) => [k, v.s])), badIfsc: badIfsc.s, ifsc: ifsc.s, audit: n})};
  });

  const browser = await chromium.launch({executablePath: '/opt/pw-browsers/chromium', args: ['--no-proxy-server', '--host-resolver-rules=MAP gate.test 127.0.0.1']});
  try {
    await t('UI-01', 'India Masters page: in Settings for the India company only; profile, records and rules tabs render from the spec; a rule is added as a draft and approved in the browser', async () => {
      const ctx = await browser.newContext({viewport: {width: 1440, height: 900}, ignoreHTTPSErrors: true}); const p = await ctx.newPage(); const errs = [];
      p.on('pageerror', e => errs.push(e.message.slice(0, 200)));
      try {
        await p.goto('https://gate.test/app.html?edition=in'); await p.waitForSelector('#sr-login-form [name=edition]', {timeout: 30000});
        await p.selectOption('#sr-login-form [name=edition]', 'IN');
        await p.fill('#sr-login-form input[name=email]', 'owner@gate.test'); await p.fill('#sr-login-form input[name=password]', PW); await p.click('#sr-login-form button[type=submit]');
        await p.waitForFunction(() => window.TeghPortal && document.querySelector('.sidebar,.topbar'), null, {timeout: 40000});
        await p.evaluate(id => { localStorage.setItem('sr-accountax-company', id); localStorage.setItem('sr-accountax-companies', JSON.stringify([id])); }, INCO);
        await p.goto('https://gate.test/app.html'); await p.waitForFunction(() => window.TeghPortal && document.querySelector('.sidebar,.topbar'), null, {timeout: 40000}); await p.waitForTimeout(1500);
        await p.evaluate(() => TeghPortal.openIndiaMasters()); await p.waitForSelector('[data-in2-profile]', {timeout: 20000});
        const profileFields = await p.locator('[data-in2-profile] [name^=f_]').count();
        const msme = await p.locator('[data-in2-profile] [name=f_msmeCategory]').inputValue();
        await p.click('[data-in2-tab=records]'); await p.waitForSelector('[data-in2-record]', {timeout: 15000});
        const gstRows = await p.locator('.in2-records tbody tr[data-in2-row]').count();
        await p.click('[data-in2-tab=rules]'); await p.waitForSelector('[data-in2-rule-form]', {timeout: 15000});
        await p.selectOption('[data-in2-rule-form] [name=category]', 'gst_rate');
        await p.fill('[data-in2-rule-form] [name=code]', 'UI-RATE-' + tag); await p.fill('[data-in2-rule-form] [name=label]', 'Synthetic UI rate');
        await p.fill('[data-in2-rule-form] [name=effectiveFrom]', '2026-04-01'); await p.fill('[data-in2-rule-form] [name=ratePercent]', '3');
        await p.fill('[data-in2-rule-form] [name=source]', 'Synthetic gate data'); await p.click('[data-in2-rule-form] button[type=submit]');
        await p.waitForFunction(t => [...document.querySelectorAll('.in2-rules tbody tr')].some(r => r.textContent.includes(t) && /Draft/.test(r.textContent)), 'UI-RATE-' + tag, {timeout: 15000});
        const row = p.locator('.in2-rules tbody tr', {hasText: 'UI-RATE-' + tag}); await row.locator('[data-in2-approve]').click();
        await p.click('.tegh-action-dialog button[type=submit]');
        await p.waitForFunction(t => [...document.querySelectorAll('.in2-rules tbody tr')].some(r => r.textContent.includes(t) && /Active/.test(r.textContent)), 'UI-RATE-' + tag, {timeout: 15000});
        await p.screenshot({path: '/srv/gate/ev/shots/in2-rules-1440.png'});
        const db = sql(`SELECT CONCAT_WS('|',status,value_decimal*1e0) FROM statutory_rates WHERE company_id='${INCO}' AND rate_key='UI-RATE-${tag}'`);
        const text = await p.evaluate(() => document.querySelector('[data-in2-body]')?.innerText || '');
        const canadian = text.match(/GST\/HST|\bHST\b|\bPST\b|\bCRA\b|GIFI|Canada|Canadian|[Pp]rovince|\bCAD\b/g) || [];
        return {ok: profileFields >= 7 && msme === 'medium' && gstRows === 1 && db === 'active|3' && !canadian.length && !errs.length, info: JSON.stringify({profileFields, msme, gstRows, db, canadian, errs})};
      } finally { await ctx.close(); }
    });
    await t('UI-02', 'Canadian company: no India Masters item in the menus', async () => {
      const ctx = await browser.newContext({viewport: {width: 1440, height: 900}, ignoreHTTPSErrors: true}); const p = await ctx.newPage();
      try {
        await p.goto('https://gate.test/app.html'); await p.waitForSelector('#sr-login-form', {timeout: 30000});
        await p.fill('#sr-login-form input[name=email]', 'owner@gate.test'); await p.fill('#sr-login-form input[name=password]', PW); await p.click('#sr-login-form button[type=submit]');
        await p.waitForFunction(() => window.TeghPortal && document.querySelector('.sidebar,.topbar'), null, {timeout: 40000}); await p.waitForTimeout(1500);
        const html = await p.evaluate(() => document.body.innerText);
        await p.evaluate(() => TeghPortal.openIndiaMasters()).catch(() => {}); await p.waitForTimeout(800);
        const page = await p.evaluate(() => document.querySelector('.srp-toast')?.textContent || '');
        return {ok: !/India Masters/.test(html) && /available only in India companies/.test(page), info: JSON.stringify({menu: /India Masters/.test(html), page: page.slice(0, 160)})};
      } finally { await ctx.close(); }
    });
  } finally { await browser.close(); }
} finally {
  await setMode(startMode).catch(() => {});
  save('in2.json');
}

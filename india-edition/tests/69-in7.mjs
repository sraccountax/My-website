// IN-7a: India company set-up. The first company of an India sign-in uses the India form (not the Canadian wizard);
// every time zone; financial year chosen by start and end dates; registrations (PAN, GSTIN, TAN, Udyam, EPF, ESIC, PT, ...);
// INR only; current or savings first bank account; editable company details; full Indian address on customers and
// vendors, printed through the invoice's customer address. Synthetic data only.
import {S, sql, rec, save, need} from './lib.mjs';
import {chromium} from '/opt/node22/lib/node_modules/playwright/index.mjs';

const A = 'IN7';
const t = async (id, title, fn) => { try { const r = await fn(); rec(id, A, title, r.ok ? 'PASS' : 'FAIL', r.info); return r.ok; } catch (e) { rec(id, A, title, 'FAIL', String(e.message || e).slice(0, 400)); return false; } };
const PW = 'Gate!Owner#2026pw', MPW = 'Gate!Member#2026pw';
const o = new S(); await o.login('owner@gate.test', PW);
const release = async () => (await o.call('site-admin', {company: false})).b.groups.release;
const setMode = async m => { const cur = await release(); return o.call('site-admin/save', {method: 'POST', company: false, json: {groups: {release: {value: {...cur.value, indiaEdition: m}, revision: cur.revision}}, reason: 'IN-7a gate ' + m}}); };
const startMode = (await release()).value.indiaEdition || 'off';
const tag = Date.now().toString(36);
const CH = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
const gstin = b => { let s = 0; for (let i = 0; i < 14; i++) { const p = CH.indexOf(b[i]) * (i % 2 === 0 ? 1 : 2); s += Math.floor(p / 36) + p % 36; } return b + CH[(36 - s % 36) % 36]; };
const n4 = String(1000 + (Date.now() % 9000)).slice(0, 4);
const PAN = 'AAACS' + n4 + 'K';
const base = {name: 'IN7 Setup ' + tag, legalName: 'IN7 Setup Private Limited', constitution: 'private_limited', state: 'KA', pin: '560001', building: 'Flat 4B, Lotus Towers', street: '12 MG Road', locality: 'Ashok Nagar', landmark: 'Brigade Junction', city: 'Bengaluru', district: 'Bengaluru Urban',
  financialYearStart: '2026-04-01', financialYearEnd: '2027-03-31', timezone: 'Asia/Kolkata', pan: PAN, gstin: gstin('29' + PAN + '1Z'), gstRegistrationType: 'regular', tan: 'BLRS' + n4.slice(0, 4) + '1B'.slice(0, 0) + '0A', bankAccountType: 'savings', coaMode: 'default',
  registrations: {udyamNumber: 'UDYAM-KA-03-0' + n4 + '12', msmeCategory: 'small', epfEstablishmentCode: 'KABNG' + n4 + '123', esicEmployerCode: '5300' + n4 + '000000123', ptRegistration: 'PTRC/' + n4, shopsEstablishment: 'SE-BLR-' + n4, businessActivity: 'Software services'}};
base.tan = 'BLRS' + n4 + '5B';

let IN, INCO = '', CACO = '';
try {
  need('mode', await setMode('everyone'));
  IN = new S(); await IN.login('owner@gate.test', PW, {edition: 'IN'});
  CACO = sql(`SELECT c.id FROM companies c JOIN company_members cm ON cm.company_id=c.id JOIN users u ON u.id=cm.user_id WHERE u.email='owner@gate.test' AND (c.edition IS NULL OR c.edition<>'IN') AND c.active=1 AND cm.role='owner' LIMIT 1`);
  const create = j => IN.call('companies', {method: 'POST', company: false, json: j});

  await t('IN7-01', 'Refusals: an unknown time zone, a financial year ending before it starts or longer than 18 months, a non-INR currency, a bad Udyam number, an unknown registration', async () => {
    const r = [await create({...base, timezone: 'Mars/Olympus'}), await create({...base, financialYearEnd: '2026-03-31'}), await create({...base, financialYearEnd: '2027-12-31'}), await create({...base, currency: 'CAD'}),
      await create({...base, registrations: {udyamNumber: 'UDYAM-123', msmeCategory: 'micro'}}), await create({...base, registrations: {hst: '123456789RT0001'}})];
    const want = [[422, 'india_timezone_invalid'], [422, 'india_fy_invalid'], [422, 'india_fy_invalid'], [422, 'india_currency_inr'], [422, 'india_field_invalid'], [422, 'india_field_unknown']];
    return {ok: JSON.stringify(r.map(x => [x.s, x.b.code])) === JSON.stringify(want), info: JSON.stringify(r.map(x => [x.s, x.b.code]))};
  });

  await t('IN7-02', 'Create: INR books, the chosen time zone and financial year (1 April 2026 to 31 March 2027), the full address composed into lines 1 and 2, registrations in the India Masters company profile, the GSTIN as a GST registration record, and a savings account (no chequing)', async () => {
    const r = need('create', await create({...base, timezone: 'Asia/Kolkata'}), [201]); INCO = r.company.id; IN.cid = INCO;
    const c = sql(`SELECT CONCAT_WS('|',currency,timezone,fiscal_year_end,fiscal_year_end_date,books_start_date,address_line1,address_line2,city,postal_code,province) FROM companies WHERE id='${INCO}'`);
    const p = JSON.parse(sql(`SELECT data_json FROM india_entity_profiles WHERE company_id='${INCO}' AND entity_type='company'`));
    const g = JSON.parse(sql(`SELECT data_json FROM india_master_records WHERE company_id='${INCO}' AND record_type='gst_registration'`));
    const bank = sql(`SELECT CONCAT_WS('|',b.name,a.name,JSON_UNQUOTE(JSON_EXTRACT(b.profile_json,'$.accountSubtype'))) FROM bank_accounts b JOIN accounts a ON a.id=b.ledger_account_id WHERE b.company_id='${INCO}'`);
    const fy = sql(`SELECT fy_start_date FROM company_india_profile WHERE company_id='${INCO}'`);
    const want = 'INR|Asia/Kolkata|03-31|2027-03-31|2026-04-01|Flat 4B, Lotus Towers, 12 MG Road|Ashok Nagar, Near Brigade Junction, Bengaluru Urban district|Bengaluru|560001|KA';
    return {ok: c === want && fy === '2026-04-01' && p.udyamNumber === base.registrations.udyamNumber && p.msmeCategory === 'small' && p.epfEstablishmentCode === base.registrations.epfEstablishmentCode && p.esicEmployerCode === base.registrations.esicEmployerCode
      && g.gstin === base.gstin && g.registrationType === 'regular' && bank === 'Savings Account|Bank - Savings Account|savings' && !/chequing/i.test(bank),
      info: JSON.stringify({c, fy, p, g: [g.gstin, g.registrationType], bank})};
  });

  await t('IN7-03', 'Company details: the Owner reads PAN and TAN in full and edits address, time zone and registrations (a new profile version); the financial year changes while there are no entries and is locked after the first journal', async () => {
    const d = need('details', await IN.call('india/company'));
    const u = need('update', await IN.call('india/company', {method: 'POST', json: {landmark: 'Trinity Metro', timezone: 'Asia/Dubai', financialYearStart: '2026-04-01', financialYearEnd: '2027-03-31', registrations: {...base.registrations, iec: PAN, fssai: '1' + n4 + '000000099'}}}));
    const after = need('details', await IN.call('india/company'));
    const fyOk = await IN.call('india/company', {method: 'POST', json: {financialYearStart: '2026-01-01', financialYearEnd: '2026-12-31'}});
    const acct = c => sql(`SELECT id FROM accounts WHERE company_id='${INCO}' AND code='${c}'`);
    need('journal', await IN.call('journals', {method: 'POST', json: {date: '2026-05-10', memo: 'IN7 synthetic', lines: [{accountId: acct('6100'), debitCents: 100000, creditCents: 0}, {accountId: acct('1010'), debitCents: 0, creditCents: 100000}]}}), [200, 201]);
    const fyLocked = await IN.call('india/company', {method: 'POST', json: {financialYearStart: '2026-04-01', financialYearEnd: '2027-03-31'}});
    const tz = sql(`SELECT timezone FROM companies WHERE id='${INCO}'`);
    return {ok: d.pan === PAN && d.tan === base.tan && d.canEdit && u.ok && after.address.landmark === 'Trinity Metro' && after.registrations.iec === PAN && after.registrations.fssai && tz === 'Asia/Dubai'
      && fyOk.s === 200 && fyLocked.s === 409 && fyLocked.b.code === 'india_fy_locked' && sql(`SELECT fiscal_year_end_date FROM companies WHERE id='${INCO}'`) === '2026-12-31',
      info: JSON.stringify({pan: d.pan === PAN, landmark: after.address.landmark, regs: after.registrations, tz, fyOk: fyOk.s, fyLocked: [fyLocked.s, fyLocked.b.code]})};
  });

  let CUST = '';
  await t('IN7-04', 'Customer and vendor with a full Indian address: lines 1 and 2 are composed, the parts are kept for editing, a bad PIN is refused, and the invoice carries the whole address', async () => {
    const bad = await IN.call('customers', {method: 'POST', json: {name: 'IN7 Bad PIN ' + tag, country: 'India', province: 'MH', city: 'Pune', postalCode: '012345', indiaBuilding: 'Shop 3'}});
    const c = need('customer', await IN.call('customers', {method: 'POST', json: {name: 'IN7 Customer ' + tag, email: '', phone: '', country: 'India', province: 'MH', city: 'Pune', postalCode: '411001', indiaBuilding: 'Shop 3, Sai Plaza', indiaStreet: 'FC Road', indiaLocality: 'Shivajinagar', indiaLandmark: 'Fergusson College', indiaDistrict: 'Pune'}}), [200, 201]).customer;
    CUST = c.id;
    const lines = sql(`SELECT CONCAT_WS('|',address_line1,address_line2,city,postal_code) FROM customers WHERE id='${c.id}'`);
    const parts = need('parts', await IN.call(`india/party-address&type=customer&id=${c.id}`)).address;
    const upd = await IN.call('customers', {method: 'PUT', json: {customerId: c.id, name: 'IN7 Customer ' + tag, country: 'India', province: 'MH', city: 'Pune', postalCode: '411005', indiaBuilding: 'Shop 3, Sai Plaza', indiaStreet: 'FC Road', indiaLocality: 'Shivajinagar', indiaLandmark: '', indiaDistrict: 'Pune', defaultTermsDays: 30, status: 'active'}});
    const lines2 = sql(`SELECT CONCAT_WS('|',address_line1,address_line2,postal_code) FROM customers WHERE id='${c.id}'`);
    const v = need('vendor', await IN.call('vendors', {method: 'POST', json: {name: 'IN7 Vendor ' + tag, email: '', currency: 'INR', defaultTermsDays: 30, country: 'India', province: 'MH', city: 'Thane', postalCode: '400601', indiaBuilding: 'Unit 7, Wagle Estate', indiaLocality: 'Wagle Industrial Area', indiaDistrict: 'Thane'}}), [200, 201]);
    const vid = v.vendor?.id || v.id;
    const vlines = sql(`SELECT CONCAT_WS('|',address_line1,address_line2) FROM vendors WHERE id='${vid}'`);
    const TMPL = (await IN.call('invoice-templates')).b.templates?.[0]?.id;
    const inv = need('invoice', await IN.call('invoices', {method: 'POST', json: {customerId: c.id, issueDate: '2026-06-10', dueDate: '2026-07-10', currency: 'INR', templateId: TMPL, issue: true, lines: [{description: 'Synthetic', quantity: 1, unitPriceCents: 100000, incomeAccountId: sql(`SELECT id FROM accounts WHERE company_id='${INCO}' AND code='4100'`)}]}}), [200, 201]).invoice;
    const snap = sql(`SELECT customer_snapshot_json FROM invoices WHERE id='${inv.id}'`);
    return {ok: bad.s === 422 && bad.b.code === 'india_pin_invalid' && lines === 'Shop 3, Sai Plaza, FC Road|Shivajinagar, Near Fergusson College|Pune|411001' && parts.landmark === 'Fergusson College' && upd.s === 200
      && lines2 === 'Shop 3, Sai Plaza, FC Road|Shivajinagar|411005' && vlines === 'Unit 7, Wagle Estate|Wagle Industrial Area' && /Shop 3, Sai Plaza, FC Road/.test(snap) && /Shivajinagar/.test(snap) && /411005/.test(snap),
      info: JSON.stringify({bad: [bad.s, bad.b.code], lines, parts, upd: upd.s, lines2, vlines, snap: snap.slice(0, 260)})};
  });

  await t('IN7-05', 'Canada is unaffected: a Canadian company gets 404 on the India company and address routes, India address fields sent to a Canadian customer are ignored, and the Canadian chequing account type still works', async () => {
    const ca = new S(); await ca.login('owner@gate.test', PW); ca.cid = CACO;
    const r1 = await ca.call('india/company'), r2 = await ca.call('india/party-address&type=customer&id=x');
    const c = need('ca customer', await ca.call('customers', {method: 'POST', json: {name: 'IN7 Canada ' + tag, email: '', phone: '', addressLine1: '1 King St', addressLine2: 'Suite 2', city: 'Toronto', province: 'ON', postalCode: 'M5H 1A1', country: 'Canada', indiaBuilding: 'Should be ignored'}}), [200, 201]).customer;
    const lines = sql(`SELECT CONCAT_WS('|',address_line1,address_line2) FROM customers WHERE id='${c.id}'`);
    const rows = sql(`SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='india_party_addresses'`) === '0' ? '0' : sql(`SELECT COUNT(*) FROM india_party_addresses WHERE company_id='${CACO}'`);
    return {ok: r1.s === 404 && r2.s === 404 && lines === '1 King St|Suite 2' && rows === '0', info: JSON.stringify({r1: r1.s, r2: r2.s, lines, rows})};
  });

  // ---------------- Browser ----------------
  const browser = await chromium.launch({executablePath: '/opt/pw-browsers/chromium', args: ['--no-proxy-server', '--host-resolver-rules=MAP gate.test 127.0.0.1']});
  try {
    const loginAs = async (p, email, pw) => {
      await p.goto('https://gate.test/app.html?edition=in'); await p.waitForSelector('#sr-login-form', {timeout: 30000});
      await p.selectOption('#sr-login-form [name=edition]', 'IN');
      await p.fill('#sr-login-form input[name=email]', email); await p.fill('#sr-login-form input[name=password]', pw); await p.click('#sr-login-form button[type=submit]');
    };
    const canadian = text => text.match(/HST|\bPST\b|\bQST\b|Chequing|Fiscal|Province|\bCAD\b|Newfoundland|Saskatchewan|Yukon/g) || [];
    await t('UI-01', 'First company of an India sign-in: the India form replaces the Canadian wizard (every time zone with Asia/Kolkata chosen, financial year start and end date pickers, registrations, Indian address, INR, current or savings account, no provinces, HST or chequing); creating it opens the new India company', async () => {
      // otherowner has Canadian companies only (any India membership from earlier runs is removed: synthetic data).
      sql(`DELETE cm FROM company_members cm JOIN companies c ON c.id=cm.company_id JOIN users u ON u.id=cm.user_id WHERE u.email='otherowner@gate.test' AND c.edition='IN'`);
      const ctx = await browser.newContext({viewport: {width: 1440, height: 900}, ignoreHTTPSErrors: true}); const p = await ctx.newPage(); const errs = [];
      p.on('pageerror', e => errs.push(e.message.slice(0, 200)));
      try {
        await loginAs(p, 'otherowner@gate.test', MPW);
        await p.waitForSelector('[data-in7-first] [data-in7-company-form]', {timeout: 45000});
        const f = '[data-in7-first] [data-in7-company-form]';
        const zones = await p.locator(`${f} [name=timezone] option`).count(), zone = await p.inputValue(`${f} [name=timezone]`);
        const types = {fs: await p.getAttribute(`${f} [name=financialYearStart]`, 'type'), fe: await p.getAttribute(`${f} [name=financialYearEnd]`, 'type')};
        const bank = await p.locator(`${f} [name=bankAccountType] option`).allTextContents();
        const states = await p.locator(`${f} [name=state] option`).count();
        const text = await p.innerText('[data-in7-first]');
        const wizardHidden = await p.evaluate(() => [...document.querySelectorAll('.company-create,.company-wizard')].every(n => n.hidden || !n.getClientRects().length));
        await p.screenshot({path: '/srv/gate/ev/shots/in7-first-company-1440.png', fullPage: true});
        await p.fill(`${f} [name=name]`, 'IN7 First ' + tag); await p.selectOption(`${f} [name=state]`, 'TN'); await p.fill(`${f} [name=city]`, 'Chennai'); await p.fill(`${f} [name=pin]`, '600001');
        await p.fill(`${f} [name=financialYearStart]`, '2026-04-01'); await p.dispatchEvent(`${f} [name=financialYearStart]`, 'change');
        const autoEnd = await p.inputValue(`${f} [name=financialYearEnd]`);
        await p.click(`${f} [data-in7-submit]`);
        await p.waitForFunction(() => window.TeghPortal && document.querySelector('.sidebar,.topbar'), null, {timeout: 60000}); await p.waitForTimeout(1500);
        const made = sql(`SELECT CONCAT_WS('|',c.currency,c.edition,c.timezone,c.fiscal_year_end_date) FROM companies c JOIN company_members cm ON cm.company_id=c.id JOIN users u ON u.id=cm.user_id WHERE u.email='otherowner@gate.test' AND c.name='IN7 First ${tag}'`);
        return {ok: zones > 300 && zone === 'Asia/Kolkata' && types.fs === 'date' && types.fe === 'date' && JSON.stringify(bank) === JSON.stringify(['Current Account', 'Savings Account']) && states === 37 && /Udyam/.test(text) && /EPF establishment code/.test(text) && /Indian rupee/.test(text)
          && !canadian(text).length && wizardHidden && autoEnd === '2027-03-31' && made === 'INR|IN|Asia/Kolkata|2027-03-31' && !errs.length,
          info: JSON.stringify({zones, zone, types, bank, states, canadian: canadian(text), wizardHidden, autoEnd, made, errs})};
      } finally { await ctx.close(); }
    });
    await t('UI-02', 'In the app: Company details shows the registrations and edits in place; the customer form shows flat/building, street, area, landmark, city or town, district and PIN code; Company Setup shows India cards only', async () => {
      const ctx = await browser.newContext({viewport: {width: 1440, height: 900}, ignoreHTTPSErrors: true}); const p = await ctx.newPage(); const errs = [];
      p.on('pageerror', e => errs.push(e.message.slice(0, 200)));
      try {
        await loginAs(p, 'owner@gate.test', PW);
        await p.waitForFunction(() => window.TeghPortal && document.querySelector('.sidebar,.topbar'), null, {timeout: 40000});
        await p.evaluate(id => { localStorage.setItem('sr-accountax-company', id); localStorage.setItem('sr-accountax-companies', JSON.stringify([id])); }, INCO); await p.goto('https://gate.test/app.html'); await p.waitForFunction(() => window.TeghPortal && document.querySelector('.sidebar,.topbar'), null, {timeout: 40000}); await p.waitForTimeout(1500);
        await p.evaluate(() => TeghPortal.openCompanyDetails()); await p.waitForSelector('.in1-company-details', {timeout: 20000});
        const det = await p.innerText('.in1-company-details');
        await p.screenshot({path: '/srv/gate/ev/shots/in7-company-details-1440.png', fullPage: true});
        await p.click('[data-in7-edit]'); await p.waitForSelector('[data-in7-company-form][data-mode=edit]', {timeout: 20000});
        await p.fill('[data-in7-company-form] [name=reg_lei]', '529900T8BM49AURSDO55'); await p.click('[data-in7-company-form] [data-in7-submit]');
        await p.waitForSelector('.in1-company-details', {timeout: 20000}); const det2 = await p.innerText('.in1-company-details');
        await p.evaluate(() => TeghPortal.openCustomers('new')); await p.waitForSelector('[data-customer-form] [name=indiaBuilding]', {timeout: 20000});
        const labels = await p.locator('[data-customer-form] label:not([hidden])').allInnerTexts();
        await p.screenshot({path: '/srv/gate/ev/shots/in7-customer-form-1440.png', fullPage: true});
        await p.evaluate(() => TeghPortal.openCompanySetup()); await p.waitForTimeout(1500);
        const setup = await p.evaluate(() => document.querySelector('.srp-page:last-of-type')?.innerText || '');
        const L = labels.join('|');
        return {ok: /Udyam registration/.test(det) && /UDYAM-KA/.test(det) && /Asia\/Dubai|Asia\/Dubai/.test(det.replace(/ /g, '_')) && /529900T8BM49AURSDO55/.test(det2) && /Flat, door or building/i.test(L) && /Area or locality/i.test(L) && /Landmark/i.test(L) && /District/i.test(L) && /PIN code/i.test(L) && /City or town/i.test(L) && !/Address Line 1/i.test(L)
          && /India Masters/.test(setup) && !/GST\/HST|PST|GIFI|province/i.test(setup) && !errs.length,
          info: JSON.stringify({det: det.slice(0, 200), lei: /529900/.test(det2), labels: L.slice(0, 400), setup: setup.slice(0, 200), errs})};
      } finally { await ctx.close(); }
    });
  } finally { await browser.close(); }
} finally {
  await setMode(startMode).catch(() => {});
  save('in7.json');
}

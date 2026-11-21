// R192: fixes from the functional and UI audit of both editions. Synthetic companies only.
// API: registrations saved with a time-zone change are read back at once; recurring documents refuse GST/HST/PST in an
// India company (Canada unchanged); data import checks Indian states and defaults the country to India in an India
// company (Canada unchanged); report builder and the expense register report use India wording.
// Browser: India financial-year wording and dates, no Canadian payroll notice or payroll call in India payroll, Canada-only
// screens closed in India, no PST column on India expenses; Canadian wording unchanged; the phone Match and Post table
// is readable; the report scope line separates title and period.
import {S, sql, rec, save, need} from './lib.mjs';
import {chromium} from '/opt/node22/lib/node_modules/playwright/index.mjs';

const A = 'R192';
const t = async (id, title, fn) => { try { const r = await fn(); rec(id, A, title, r.ok ? 'PASS' : 'FAIL', r.info); return r.ok; } catch (e) { rec(id, A, title, 'FAIL', String(e.message || e).slice(0, 400)); return false; } };
const PW = 'Gate!Owner#2026pw';
const o = new S(); await o.login('owner@gate.test', PW);
const release = async () => (await o.call('site-admin', {company: false})).b.groups.release;
const setMode = async m => { const cur = await release(); return o.call('site-admin/save', {method: 'POST', company: false, json: {groups: {release: {value: {...cur.value, indiaEdition: m}, revision: cur.revision}}, reason: 'R192 gate ' + m}}); };
const startMode = (await release()).value.indiaEdition || 'off';
const tag = Date.now().toString(36);
const owned = ed => sql(`SELECT c.id FROM companies c JOIN company_members cm ON cm.company_id=c.id JOIN users u ON u.id=cm.user_id WHERE u.email='owner@gate.test' AND cm.role='owner' AND c.active=1 AND ${ed === 'IN' ? "c.edition='IN'" : "(c.edition IS NULL OR c.edition<>'IN')"} ORDER BY (SELECT COUNT(*) FROM invoices i WHERE i.company_id=c.id) DESC, c.created_at LIMIT 1`);

try {
  need('mode', await setMode('everyone'));
  const IN = new S(); await IN.login('owner@gate.test', PW, {edition: 'IN'});
  const CA = new S(); await CA.login('owner@gate.test', PW);
  // The Canadian company is the gate's BC company when it is there (the volume companies are slow in a browser).
  let IDS = {}; try { IDS = JSON.parse((await import('fs')).readFileSync('/srv/gate/t/ids.json', 'utf8')); } catch {}
  const bc = IDS.BC && sql(`SELECT c.id FROM companies c JOIN company_members cm ON cm.company_id=c.id JOIN users u ON u.id=cm.user_id WHERE c.id='${IDS.BC}' AND u.email='owner@gate.test' AND c.active=1`);
  const INCO = owned('IN'), CACO = bc || sql(`SELECT c.id FROM companies c JOIN company_members cm ON cm.company_id=c.id JOIN users u ON u.id=cm.user_id WHERE u.email='owner@gate.test' AND cm.role='owner' AND c.active=1 AND c.name='Gate Test BC Ltd' LIMIT 1`) || owned('CA'); IN.cid = INCO; CA.cid = CACO;
  if (!INCO || !CACO) throw new Error('need an India and a Canadian company of owner@gate.test (run 62-in1 to 69-in7 first)');
  const acct = (co, code) => sql(`SELECT id FROM accounts WHERE company_id='${co}' AND code='${code}' LIMIT 1`);

  await t('R192-01', 'India company details: registrations saved in the same save as a time-zone change are read back at once (the version is dated in the new zone, not the old one)', async () => {
    // Kiritimati (UTC+14) and Pago Pago (UTC-11) are always on different dates.
    need('tz', await IN.call('india/company', {method: 'POST', json: {timezone: 'Pacific/Kiritimati'}}));
    const before = need('details', await IN.call('india/company')).registrations || {};
    const lwf = 'LWF-' + tag.toUpperCase().slice(-6);
    const u = need('update', await IN.call('india/company', {method: 'POST', json: {timezone: 'Pacific/Pago_Pago', registrations: {...before, lwfRegistration: lwf}}}));
    const after = need('details', await IN.call('india/company'));
    const back = await IN.call('india/company', {method: 'POST', json: {timezone: 'Asia/Kolkata'}});
    return {ok: after.registrations?.lwfRegistration === lwf && (u.india?.registrations?.lwfRegistration === lwf || u.india?.registrations === undefined) && back.s === 200,
      info: JSON.stringify({lwf, read: after.registrations?.lwfRegistration, reply: u.india?.registrations?.lwfRegistration, tz: after.timezone})};
  });

  await t('R192-02', 'Recurring documents: an India company cannot mark a recurring invoice line taxable or apply GST/HST or PST to a recurring vendor invoice (422 india_recurring_gst); the same requests are not refused for that reason in a Canadian company', async () => {
    const cust = co => sql(`SELECT id FROM customers WHERE company_id='${co}' AND status='active' LIMIT 1`), vend = co => sql(`SELECT id FROM vendors WHERE company_id='${co}' AND status='active' LIMIT 1`);
    const exp = co => sql(`SELECT id FROM accounts WHERE company_id='${co}' AND account_type='expense' AND COALESCE(is_control,0)=0 AND active=1 ORDER BY code LIMIT 1`);
    const ri = (S_, co) => S_.call('advanced/recurring-invoices', {method: 'POST', json: {customerId: cust(co), name: 'R192 recurring ' + tag, frequency: 'monthly', nextInvoiceDate: '2027-01-15', paymentTermsDays: 30, lines: [{description: 'Synthetic service', quantity: 1, unitPriceCents: 100000, taxable: true}]}});
    const rb = (S_, co) => S_.call('advanced/recurring-bills', {method: 'POST', json: {vendorId: vend(co), name: 'R192 recurring bill ' + tag, frequency: 'monthly', nextBillDate: '2027-01-15', paymentTermsDays: 30, categoryAccountId: exp(co), foreignAmountCents: 50000, applyGstHst: true}});
    const a = await ri(IN, INCO), b = await rb(IN, INCO), c = await ri(CA, CACO), d = await rb(CA, CACO);
    const noIn = sql(`SELECT COUNT(*) FROM recurring_invoice_profiles WHERE company_id='${INCO}' AND name='R192 recurring ${tag}'`);
    return {ok: a.s === 422 && a.b.code === 'india_recurring_gst' && b.s === 422 && b.b.code === 'india_recurring_gst' && c.b?.code !== 'india_recurring_gst' && d.b?.code !== 'india_recurring_gst' && [200, 201].includes(c.s) && noIn === '0',
      info: JSON.stringify({in: [a.s, a.b.code, b.s, b.b.code], ca: [c.s, c.b?.code, d.s, d.b?.code], noIn})};
  });

  await t('R192-03', 'Data import: in an India company a customer state is checked against Indian states and union territories (MH accepted, BC refused under "State") and an imported customer with no country is in India; in a Canadian company BC is accepted and MH refused under "Province"', async () => {
    const rows = p => [{customerName: 'R192 Import A ' + tag + p, province: 'MH', city: 'Pune', status: 'Active', paymentTerms: 'Net 30'}, {customerName: 'R192 Import B ' + tag + p, province: 'BC', city: 'Victoria', status: 'Active', paymentTerms: 'Net 30'}];
    const v = async (S_, p) => need('validate', await S_.call('data-imports/validate', {method: 'POST', json: {importType: 'customers', filename: 'r192.csv', rows: rows(p)}}));
    const i = await v(IN, 'i'), c = await v(CA, 'c');
    await CA.call('data-imports/cancel', {method: 'POST', json: {previewId: c.previewId}}).catch(() => {});
    // The valid India row is imported (into the synthetic India company) to read the country it gets.
    const keys = (i.records || []).filter(x => x.eligible).map(x => x.key);
    const com = await IN.call('data-imports/commit', {method: 'POST', json: {previewId: i.previewId, selectedKeys: keys}});
    const country = sql(`SELECT CONCAT_WS('|',country,province) FROM customers WHERE company_id='${INCO}' AND name='R192 Import A ${tag}i'`);
    const iss = r => (r.issues || []).map(x => [x.row, x.field, x.error]);
    const inIss = iss(i), caIss = iss(c);
    const ok = inIss.length === 1 && inIss[0][0] === 3 && inIss[0][1] === 'State' && /union territory/.test(inIss[0][2]) && keys.length === 1 && [200, 201].includes(com.s) && country === 'India|MH'
      && caIss.length === 1 && caIss[0][0] === 2 && caIss[0][1] === 'Province' && /province/i.test(caIss[0][2]);
    return {ok, info: JSON.stringify({inIss, caIss, keys: keys.length, commit: com.s, country})};
  });

  await t('R192-04', 'Reports: the India expense register has a GST column and no PST column or total; the Canadian one keeps GST/HST and PST; the report builder calls the customer region "State" in India and "Province" in Canada (when the builder is on)', async () => {
    const q = 'professional-output/v5990/expense-register?start=2026-01-01&end=2027-12-31';
    const ri = need('in report', await IN.call(q)), rc = need('ca report', await CA.call(q));
    const cols = r => (r.output?.columns || []).map(c => c.label), tot = r => Object.keys(r.output?.totals || {});
    const ci = cols(ri), cc = cols(rc), ti = tot(ri), tc = tot(rc);
    const bi = await IN.call('report-builder/catalogue'), bc = await CA.call('report-builder/catalogue');
    const lab = r => r.s === 200 ? (r.b.sources || []).find(s => s.key === 'customers')?.fields?.find(f => f.key === 'province')?.label : 'off:' + r.s;
    const builderOk = (bi.s !== 200 && bc.s !== 200) || (lab(bi) === 'State' && lab(bc) === 'Province');
    return {ok: ci.includes('GST') && !ci.includes('PST') && !ci.includes('GST/HST') && !ti.includes('pstCents') && cc.includes('GST/HST') && cc.includes('PST') && tc.includes('pstCents') && builderOk,
      info: JSON.stringify({ci, ti, cc, tc, builder: [lab(bi), lab(bc)]})};
  });

  // ---------------- Browser ----------------
  const browser = await chromium.launch({executablePath: '/opt/pw-browsers/chromium', args: ['--no-proxy-server', '--host-resolver-rules=MAP gate.test 127.0.0.1']});
  const open = async (ed, co, vp = {width: 1440, height: 900}) => {
    const mobile = vp.width < 800;
    const ctx = await browser.newContext({viewport: vp, isMobile: mobile, hasTouch: mobile, ignoreHTTPSErrors: true});
    await ctx.addInitScript(id => { try { if (!sessionStorage.getItem('r192-co')) { localStorage.setItem('sr-accountax-company', id); localStorage.setItem('sr-accountax-companies', JSON.stringify([id])); sessionStorage.setItem('r192-co', '1'); } } catch {} }, co);
    const p = await ctx.newPage(); await p.route(/clarity\.ms|challenges\.cloudflare|googleapis|gstatic|bing\.com/, r => r.abort());
    const errs = [], api = []; p.on('pageerror', e => errs.push(e.message.slice(0, 160)));
    p.on('response', r => { if (r.status() >= 400 && /\/api\//.test(r.url()) && !/auth%2Fme/.test(r.url())) api.push(r.status() + ' ' + r.url().replace(/^.*route=/, '').slice(0, 60)); });
    await p.goto('https://gate.test/app.html' + (ed === 'IN' ? '?edition=in' : '')); await p.waitForSelector('#sr-login-form', {timeout: 30000});
    if (ed === 'IN') await p.selectOption('#sr-login-form [name=edition]', 'IN');
    await p.fill('#sr-login-form input[name=email]', 'owner@gate.test'); await p.fill('#sr-login-form input[name=password]', PW); await p.click('#sr-login-form button[type=submit]');
    await p.waitForFunction(() => window.TeghPortal && document.querySelector('.sidebar,.topbar'), null, {timeout: 60000}); await p.waitForTimeout(2000);
    await p.evaluate(() => window.TeghPortal.switchBookkeepingMode?.('accountant')).catch(() => {}); await p.waitForTimeout(600);
    return {ctx, p, errs, api};
  };
  const go = async (p, how) => { await p.evaluate(how => { const [k, v] = how.split('|'); if (k === 'menu') TeghPortal.invokeMenuAction('', v); else if (k === 'report') TeghPortal.openReport(v); else TeghPortal[v](); }, how); await p.waitForTimeout(2600); };
  const pageText = p => p.evaluate(() => [...document.querySelectorAll('.srp-page')].filter(x => x.offsetParent !== null).map(x => x.innerText).join('\n'));
  const options = p => p.evaluate(() => [...document.querySelectorAll('.srp-page select option')].filter(o => o.closest('.srp-page').offsetParent !== null).map(o => o.textContent.trim()));
  try {
    await t('UI-01', 'India company in the browser: period lists say "Financial year to date" with DD-MM-YYYY dates; payroll pages show no Canadian Payroll Support notice and make no Canadian payroll call; Tax codes and GIFI open the Canada-only page; the expense page and expense register show GST and no PST', async () => {
      const {ctx, p, errs, api} = await open('IN', INCO);
      try {
        await go(p, 'menu|general-ledger:period-trial-balance'); const opts = await options(p); const tbText = await pageText(p);
        api.length = 0; await go(p, 'menu|payroll:pay-runs'); const pay = await pageText(p); const payApi = [...api];
        await go(p, 'fn|openTaxCodes'); const tax = await pageText(p);
        await go(p, 'fn|openGifiReport'); const gifi = await pageText(p);
        await go(p, 'fn|openExpenseVouchers'); const heads = await p.evaluate(() => [...document.querySelectorAll('.srp-page[data-srp-page="expense-vouchers"] th')].map(x => x.textContent.trim())); const expText = await pageText(p);
        await go(p, 'report|Expense Register'); const reg = await pageText(p);
        await p.screenshot({path: '/srv/gate/ev/shots/r192-in-expense-register-1440.png'});
        const fy = opts.find(x => /^Financial year to date \(\d\d-\d\d-\d{4} to \d\d-\d\d-\d{4}\)$/.test(x));
        const bad = [tbText, pay, expText, reg].join('\n').match(/\bfiscal year\b|GST\/HST|\bPST\b|\bCRA\b|T4127|gst Hst/gi) || [];
        return {ok: !!fy && opts.some(x => /^Previous financial year/.test(x)) && !opts.some(x => /fiscal/i.test(x)) && !/Payroll Support Tool/.test(pay) && !payApi.some(x => /^409 payroll/.test(x))
          && /is for Canadian companies/i.test(tax) && /is for Canadian companies/i.test(gifi) && heads.includes('GST') && !heads.includes('PST') && !bad.length && !errs.length,
          info: JSON.stringify({fy, payApi, tax: tax.slice(0, 80), heads, bad: bad.slice(0, 6), errs})};
      } finally { await ctx.close(); }
    });

    await t('UI-02', 'Canadian company in the browser is unchanged: "Fiscal year to date", the Payroll Support notice on payroll pages, the Tax codes page itself, and GST/HST and PST columns on expenses', async () => {
      const {ctx, p, errs} = await open('CA', CACO);
      try {
        await go(p, 'menu|general-ledger:period-trial-balance'); const opts = await options(p);
        await go(p, 'menu|payroll:payroll-tax-center'); const pay = await pageText(p);
        await go(p, 'fn|openTaxCodes'); const tax = await pageText(p);
        await go(p, 'fn|openExpenseVouchers'); const heads = await p.evaluate(() => [...document.querySelectorAll('.srp-page[data-srp-page="expense-vouchers"] th')].map(x => x.textContent.trim()));
        return {ok: opts.some(x => /^Fiscal year to date \(\d{4}-\d\d-\d\d to \d{4}-\d\d-\d\d\)$/.test(x)) && /Payroll Support Tool/.test(pay) && !/is for Canadian companies/i.test(tax) && /Tax codes/i.test(tax) && heads.includes('GST/HST') && heads.includes('PST') && !errs.length,
          info: JSON.stringify({opts: opts.filter(x => /fiscal/i.test(x)), notice: /Payroll Support Tool/.test(pay), tax: tax.slice(0, 60), heads, errs})};
      } finally { await ctx.close(); }
    });

    await t('UI-03', 'Phone (390 px), both editions: Match and Post shows dates and amounts in full (the table scrolls sideways instead of cutting them off); report scope line reads "<title> · <period>"', async () => {
      const out = {};
      for (const [ed, co] of [['CA', CACO], ['IN', INCO]]) {
        const {ctx, p, errs} = await open(ed, co, {width: 390, height: 844});
        try {
          await go(p, 'menu|banking:match-and-post-transactions');
          const m = await p.evaluate(() => { const t = document.querySelector('.r28-bank-scroll table'); if (!t) return {none: true}; const cells = [...t.querySelectorAll('tbody tr td')].filter(td => /\d/.test(td.textContent) && td.offsetParent !== null);
            const clipped = cells.filter(td => td.scrollWidth > td.clientWidth + 2 && /[$₹]|\d{4}/.test(td.textContent)).map(td => td.textContent.trim().slice(0, 20));
            return {rows: t.querySelectorAll('tbody tr').length, clipped: clipped.slice(0, 4), wrapScroll: t.parentElement.scrollWidth > t.parentElement.clientWidth}; });
          await p.screenshot({path: `/srv/gate/ev/shots/r192-${ed.toLowerCase()}-match-post-390.png`});
          await go(p, 'report|Expense Register');
          const scope = await p.evaluate(() => document.querySelector('.r22-recorded-scope')?.textContent || '');
          out[ed] = {...m, scope, errs};
        } finally { await ctx.close(); }
      }
      const ok = ['CA', 'IN'].every(e => !out[e].none && !out[e].clipped.length && (out[e].rows <= 1 || out[e].wrapScroll) && (!out[e].scope || / · /.test(out[e].scope)) && !out[e].errs.length);
      return {ok, info: JSON.stringify(out)};
    });
  } finally { await browser.close(); }
} finally {
  await setMode(startMode).catch(() => {});
  save('r192.json');
}

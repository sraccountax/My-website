// R199: fixes from the group bookkeeping audit (synthetic data only).
// API: foreign-vendor bills no longer assume the home province's HST; India details on a Canada-edition company request
// are refused; Zero-rated and Exempt starter codes; an overpayment is refused with the open balance and the excess;
// the Users page offers only the signed-in edition's companies.
// Browser: a negative bank balance keeps its minus sign on one line and shows in the critical tone (1440 and 390 px);
// no clipped header or sidebar labels; dark Financial Analyst figures readable; the payment form prefills the
// payment-day rate and shows the document's own rate, and offers to keep an overpayment as an advance; registers show
// amounts in the table's currency without the code and zero as a dash; a USD bank account is labelled on the dashboard.
import {S, sql, rec, save, need} from './lib.mjs';
import {chromium} from '/opt/node22/lib/node_modules/playwright/index.mjs';

const A = 'R199';
const t = async (id, title, fn) => { try { const r = await fn(); rec(id, A, title, r.ok ? 'PASS' : 'FAIL', r.info); return r.ok; } catch (e) { rec(id, A, title, 'FAIL', String(e.message || e).slice(0, 600)); return false; } };
const o = new S(); await o.login('owner@gate.test', 'Gate!Owner#2026pw');
const release = async () => (await o.call('site-admin', {company: false})).b.groups.release;
const setRelease = async (k, v) => { const cur = await release(); return o.call('site-admin/save', {method: 'POST', company: false, json: {groups: {release: {value: {...cur.value, [k]: v}, revision: cur.revision}}, reason: 'R199 gate'}}); };
const start = (await release()).value;
const tag = Date.now().toString(36), EMAIL = `r199-${tag}@gate.test`, UID = `user_r199${tag}`.padEnd(37, '0'), PW = 'Gate!Member#2026pw';
sql(`CREATE TEMPORARY TABLE r199u AS SELECT * FROM users WHERE email='otherowner@gate.test'; UPDATE r199u SET id='${UID}', email='${EMAIL}'; INSERT INTO users SELECT * FROM r199u`);
const R = d => Math.round(d * 100);
let k = 0; const key = p => `r199-${p}-${tag}-${++k}`.padEnd(24, 'x');
const acct = (co, code) => sql(`SELECT id FROM accounts WHERE company_id='${co}' AND code='${code}'`);
const taxId = (co, code) => sql(`SELECT id FROM tax_codes WHERE company_id='${co}' AND code='${code}' AND status='active'`);
const unbalanced = co => Number(sql(`SELECT COUNT(*) FROM (SELECT je.id FROM journal_entries je JOIN journal_lines jl ON jl.journal_entry_id=je.id WHERE je.company_id='${co}' GROUP BY je.id HAVING SUM(jl.debit_cents)<>SUM(jl.credit_cents)) x`));
let browser, CO = '', IN = '';
try {
  need('india on', await setRelease('indiaEdition', 'everyone'));
  need('new dashboard on', await setRelease('newDashboard', 'everyone'));
  // Canadian company (Ontario), owned by owner@gate.test, with the test user as Admin.
  CO = need('company', await o.call('companies', {method: 'POST', company: false, json: {businessType: 'corporation', country: 'Canada', province: 'ON', taxRegistered: true, taxNumber: '744444444RT0001', moduleMode: 'accounting', fiscalYearEnd: '12-31', booksStartDate: '2026-01-01', chartTemplate: 'default', currency: 'CAD', name: 'R199 Holdings ' + tag, accountingBasis: 'accrual'}})).company.id;
  o.cid = CO;
  sql(`INSERT INTO company_members (company_id,user_id,role,status,created_at) VALUES ('${CO}','${UID}','admin','active',NOW())`);
  const vendor = async (name, country, province, currency = 'CAD') => { const b = need('vendor', await o.call('vendors', {method: 'POST', json: {name: name + ' ' + tag, email: '', address: '1 St', defaultTermsDays: 30, currency, country, province}})); return b.vendor?.id || b.id; };
  need('usd', await o.call('currencies', {method: 'POST', json: {currency: 'USD', rateToBaseMicros: 1_365_000, rateDate: '2026-09-15'}}));
  const usV = await vendor('R199 US SaaS', 'United States', '', 'USD'), onV = await vendor('R199 Toronto Supplier', 'Canada', 'ON');
  const billOf = id => sql(`SELECT CONCAT_WS('|',b.currency,b.subtotal_cents,b.gst_hst_cents,b.pst_cents,b.total_cents,IFNULL(t.code,'-')) FROM bills b LEFT JOIN tax_codes t ON t.id=b.tax_code_id WHERE b.id='${id}'`);
  const bill = async (vid, currency, extra = {}) => { const r = await o.call('bills', {method: 'POST', json: {vendorId: vid, number: 'B' + (++k) + tag, billDate: '2026-09-20', dueDate: '2026-10-20', currency, taxEntryMode: 'exclusive', memo: 'R199', issue: true, ...(currency === 'USD' ? {exchangeRateMicros: 1_365_000, exchangeRateTyped: true, exchangeRateConfirmed: true} : {}), ...extra}}); return [r.s, r.b?.bill?.id || r.b?.id, r.b]; };

  await t('R199-01', 'A US vendor\'s USD bill with no tax code gets no tax (no Ontario HST, no input tax credit); an Ontario vendor still gets HST 13%; a code chosen on the foreign bill is still used (line and single-amount bills)', async () => {
    const lines = [{description: 'Licence', quantity: 1, unitPriceCents: R(1000), accountId: acct(CO, '6200')}];
    const [s1, b1] = await bill(usV, 'USD', {lines});
    const [s2, b2] = await bill(onV, 'CAD', {lines});
    const [s3, b3] = await bill(usV, 'USD', {lines: [{...lines[0], taxCodeId: taxId(CO, 'ON')}], taxCodeId: taxId(CO, 'ON')});
    const [s4, b4] = await bill(usV, 'USD', {categoryAccountId: acct(CO, '6200'), foreignAmountCents: R(1000)});
    const got = [billOf(b1), billOf(b2), billOf(b3), billOf(b4)];
    // US$1,000 at 1.365 = $1,365.00; HST 13% of $1,000 = $130.00; 13% of $1,365.00 = $177.45.
    const want = ['USD|136500|0|0|136500|-', 'CAD|100000|13000|0|113000|ON', 'USD|136500|17745|0|154245|ON', 'USD|136500|0|0|136500|-'];
    return {ok: [s1, s2, s3, s4].every(s => s === 201) && JSON.stringify(got) === JSON.stringify(want) && unbalanced(CO) === 0, info: `${[s1, s2, s3, s4]} ${JSON.stringify(got)}`};
  });

  await t('R199-02', 'India details (PAN, GSTIN, constitution) on a Canada-edition company request are refused with a clear message; no company is created', async () => {
    const before = sql(`SELECT COUNT(*) FROM companies WHERE name LIKE 'R199 India Probe%'`);
    const r = await o.call('companies', {method: 'POST', company: false, json: {name: 'R199 India Probe ' + tag, legalName: 'R199 India Probe Private Limited', constitution: 'private_limited', state: 'KA', pin: '560001', city: 'Bengaluru', financialYearStart: '2026-04-01', financialYearEnd: '2027-03-31', pan: 'AURPZ1234K'}});
    const after = sql(`SELECT COUNT(*) FROM companies WHERE name LIKE 'R199 India Probe%'`);
    return {ok: r.s === 422 && r.b.code === 'india_company_needs_india_edition' && before === after, info: `${r.s} ${r.b.code} "${r.b.error}" companies ${before}->${after}`};
  });

  await t('R199-03', 'New Canadian companies get "Zero-rated" and "Exempt" starter codes; a services export on Zero-rated posts no tax and stays balanced', async () => {
    const z = taxId(CO, 'ZERO'), e = taxId(CO, 'EXEMPT');
    const cust = need('cust', await o.call('customers', {method: 'POST', json: {name: 'R199 Export Buyer ' + tag, email: '', phone: '', billingAddress: '1 Str', country: 'Germany', province: ''}})).customer.id;
    const tm = (await o.call('invoice-templates')).b.templates?.[0]?.id;
    const r = need('inv', await o.call('invoices', {method: 'POST', json: {customerId: cust, issueDate: '2026-09-20', dueDate: '2026-10-20', currency: 'CAD', templateId: tm, issue: true, lines: [{description: 'Consulting (export)', quantity: 1, unitPriceCents: R(2500), taxable: true, taxCodeId: z}]}}));
    const inv = sql(`SELECT CONCAT_WS('|',subtotal_cents,tax_cents,total_cents) FROM invoices WHERE id='${r.invoice.id}'`);
    const names = sql(`SELECT GROUP_CONCAT(name ORDER BY code SEPARATOR ' / ') FROM tax_codes WHERE company_id='${CO}' AND code IN ('ZERO','EXEMPT')`);
    return {ok: !!z && !!e && inv === '250000|0|250000' && unbalanced(CO) === 0, info: `codes ${names}; invoice ${inv}`};
  });

  // Data for the payment and dashboard checks: USD bank, USD invoice to a customer, a loan out of the CAD bank (negative).
  need('usd bank', await o.call('bank-accounts', {method: 'POST', json: {name: 'USD Operating', currency: 'USD', accountType: 'bank', type: 'bank', ledgerCode: '1010', ledgerName: 'USD Operating'}}));
  const usCust = need('cust', await o.call('customers', {method: 'POST', json: {name: 'R199 US Client ' + tag, email: '', phone: '', billingAddress: '1 Ave', country: 'United States', province: ''}})).customer.id;
  const tm = (await o.call('invoice-templates')).b.templates?.[0]?.id;
  const usInv = need('usd inv', await o.call('invoices', {method: 'POST', json: {customerId: usCust, issueDate: '2026-09-15', dueDate: '2026-12-31', currency: 'USD', templateId: tm, issue: true, exchangeRateMicros: 1_365_000, exchangeRateTyped: true, exchangeRateConfirmed: true, lines: [{description: 'Platform fee', quantity: 1, unitPriceCents: R(1000), taxable: false}]}})).invoice;
  need('loan', await o.call('journals', {method: 'POST', json: {date: '2026-09-01', memo: 'Loan to subsidiary', lines: [{accountId: acct(CO, '1300'), debitCents: R(25000), creditCents: 0}, {accountId: acct(CO, '1000'), debitCents: 0, creditCents: R(25000)}]}}));
  need('rate', await o.call('currencies', {method: 'POST', json: {currency: 'USD', rateToBaseMicros: 1_392_000, rateDate: '2026-10-01'}}));

  await t('R199-04', 'A payment above the open balance is refused with the balance and the excess named (code payment_exceeds_balance)', async () => {
    const r = await o.call('payments', {method: 'POST', json: {type: 'customer', documentId: usInv.id, paymentDate: '2026-10-01', reference: 'Over', foreignAmountCents: R(1010), exchangeRateMicros: 1_392_000, paymentAccountId: acct(CO, '1010'), operationKey: key('over')}});
    return {ok: r.s === 422 && r.b.code === 'payment_exceeds_balance' && /1,000\.00/.test(r.b.error) && /10\.00/.test(r.b.error), info: `${r.s} ${r.b.code} "${r.b.error}"`};
  });

  await t('R199-05', 'The Users page offers only the signed-in edition\'s companies (Canada sign-in: no India companies; India sign-in: India only)', async () => {
    const ca = need('inv', await o.call('platform/invitations', {company: false}));
    const oi = new S(); await oi.login('owner@gate.test', 'Gate!Owner#2026pw', {edition: 'IN'});
    const ind = need('inv', await oi.call('platform/invitations', {company: false}));
    const ed = ids => ids.length ? sql(`SELECT GROUP_CONCAT(DISTINCT IFNULL(edition,'CA')) FROM companies WHERE id IN (${ids.map(i => `'${i}'`).join(',')})`) : '';
    const caEd = ed((ca.companies || []).map(c => c.id)), inEd = ed((ind.companies || []).map(c => c.id));
    return {ok: (ca.companies || []).length > 0 && !/IN/.test(caEd) && (!inEd || inEd === 'IN') && (ca.companies || []).some(c => c.id === CO), info: `Canada sign-in ${(ca.companies || []).length} companies (${caEd}); India sign-in ${(ind.companies || []).length} (${inEd})`};
  });

  // ---------------- Browser ----------------
  browser = await chromium.launch({executablePath: '/opt/pw-browsers/chromium', args: ['--no-proxy-server', '--host-resolver-rules=MAP gate.test 127.0.0.1']});
  const session = async (w = 1440, h = 900) => {
    const mobile = w < 800;
    const ctx = await browser.newContext({viewport: {width: w, height: h}, isMobile: mobile, hasTouch: mobile, ignoreHTTPSErrors: true});
    await ctx.addInitScript(id => { try { if (!sessionStorage.getItem('r199')) { localStorage.setItem('sr-accountax-company', id); localStorage.setItem('sr-accountax-companies', JSON.stringify([id])); sessionStorage.setItem('r199', '1'); } } catch {} }, CO);
    const p = await ctx.newPage(); const errs = [];
    p.on('pageerror', e => errs.push(e.message.slice(0, 160)));
    await p.route(/clarity\.ms|challenges\.cloudflare|googleapis|gstatic|bing\.com/, r => r.abort());
    await p.goto('https://gate.test/app.html'); await p.waitForSelector('#sr-login-form');
    await p.fill('#sr-login-form input[name=email]', EMAIL); await p.fill('#sr-login-form input[name=password]', PW); await p.click('#sr-login-form button[type=submit]');
    await p.waitForFunction(() => window.TeghPortal && document.querySelector('.sidebar,.topbar'), null, {timeout: 60000}); await p.waitForTimeout(1800);
    return {ctx, p, errs};
  };
  const pref = async (p, theme) => { await p.evaluate(() => TeghPortal.openTeghPreferences()); await p.waitForSelector('[data-tegh-preferences]'); await p.locator(`[data-tegh-preferences] input[name=theme][value=${theme}]`).check({force: true}); await p.locator('[data-tegh-preferences] button[type=submit]').first().click(); await p.waitForTimeout(1200); };
  const clipped = p => p.evaluate(() => [...document.querySelectorAll('.topbar *, .sidebar *')].filter(e => e.children.length === 0 && e.textContent.trim() && e.offsetParent && e.scrollWidth > e.clientWidth + 1).map(e => e.textContent.trim().slice(0, 40)));
  const cash = p => p.evaluate(() => { const v = document.querySelector('.r177-hero .tc-kpi-value'); const cs = getComputedStyle(v); return {text: v.textContent, lines: Math.round(v.getBoundingClientRect().height / parseFloat(cs.lineHeight)), color: cs.color, chips: [...document.querySelectorAll('.r177-hero .r177-chip')].map(c => c.textContent).join(' | ')}; });

  await t('UI-01', 'Dashboard at 1440 px: the negative bank balance shows "−$…" on one line in the critical tone; the USD bank is labelled as shown in CAD; no clipped sidebar or header label', async () => {
    const {ctx, p, errs} = await session();
    await p.evaluate(() => TeghPortal.openDashboard()); await p.waitForSelector('.r177-hero .tc-kpi-value', {timeout: 20000}); await p.waitForTimeout(1500);
    const c = await cash(p), cl = await clipped(p);
    await p.screenshot({path: '/srv/gate/ev/shots/r199-dashboard-1440.png'}); await ctx.close();
    return {ok: /^[−-]\$25,000$/.test(c.text) && c.lines === 1 && c.color === 'rgb(168, 40, 30)' && /USD Operating .*\(USD account, in CAD\)/.test(c.chips) && !cl.length && !errs.length, info: JSON.stringify({c, cl, errs})};
  });

  await t('UI-02', 'Dashboard at 390 px: the company name and mode are on their own header row (not cut off); the cash figure keeps its minus sign on one line', async () => {
    const {ctx, p, errs} = await session(390, 844);
    await p.evaluate(() => TeghPortal.openDashboard()); await p.waitForSelector('.r177-hero .tc-kpi-value', {timeout: 20000}); await p.waitForTimeout(1500);
    const c = await cash(p), cl = await clipped(p);
    const head = await p.evaluate(() => { const t = document.querySelector('.topbar'), b = document.querySelector('.srp-company-trigger'); const tr = t.getBoundingClientRect(), br = b.getBoundingClientRect(); const content = document.querySelector('.stage')?.getBoundingClientRect().top; return {h: tr.height, triggerW: Math.round(br.width), inside: br.top >= tr.top && br.bottom <= tr.bottom + 1, contentTop: Math.round(content), over: document.documentElement.scrollWidth > innerWidth}; });
    await p.screenshot({path: '/srv/gate/ev/shots/r199-dashboard-390.png'}); await ctx.close();
    return {ok: /^[−-]\$25,000$/.test(c.text) && c.lines === 1 && !cl.length && head.inside && head.triggerW > 300 && head.contentTop >= head.h - 1 && !head.over && !errs.length, info: JSON.stringify({c: c.text, cl, head, errs})};
  });

  await t('UI-03', 'Dark theme: Financial Analyst figures and warning/critical cards are readable (every figure at least 4.5:1 against its card)', async () => {
    const {ctx, p, errs} = await session();
    await pref(p, 'dark');
    await p.evaluate(() => TeghPortal.openFinancialAnalyst()); await p.waitForSelector('.tegh-fa-kpis b', {timeout: 30000}); await p.waitForTimeout(1500);
    const res = await p.evaluate(() => {
      const lum = c => { const [r, g, b] = c.match(/\d+(\.\d+)?/g).slice(0, 3).map(Number).map(v => v / 255).map(v => v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4); return 0.2126 * r + 0.7152 * g + 0.0722 * b; };
      const bgOf = e => { while (e) { const b = getComputedStyle(e).backgroundColor; if (b && !/rgba\(0, 0, 0, 0\)|transparent/.test(b)) return b; e = e.parentElement; } return 'rgb(255,255,255)'; };
      return [...document.querySelectorAll('.tegh-fa-kpis b')].map(b => { const f = lum(getComputedStyle(b).color), g = lum(bgOf(b)); const [x, y] = [f, g].sort((a, c) => c - a); return {text: b.textContent.trim(), ratio: +((x + 0.05) / (y + 0.05)).toFixed(2)}; });
    });
    await p.screenshot({path: '/srv/gate/ev/shots/r199-analyst-dark.png'});
    await pref(p, 'light'); await ctx.close();
    return {ok: res.length >= 3 && res.every(r => r.ratio >= 4.5) && !errs.length, info: JSON.stringify({res, errs})};
  });

  await t('UI-04', 'Customer payment form: for a USD invoice the rate is prefilled with the company\'s rate for the payment day (1.392000, not the invoice\'s 1.365000) with a note naming the invoice rate; paying US$1,010.00 on a US$1,000.00 invoice offers to keep US$10.00 as an advance, and accepting records both (FX gain $27.00 on the invoice)', async () => {
    const {ctx, p, errs} = await session();
    const dialogs = []; p.on('dialog', d => { dialogs.push(d.message()); d.accept(); });
    await p.evaluate(() => TeghPortal.invokeMenuAction('', 'receivables:customer-payments')); await p.waitForTimeout(2500);
    const form = p.locator('form').filter({has: p.locator('[name="exchangeRate"]')}).first();
    await form.locator('[name="paymentDate"]').fill('2026-10-01');
    const docSel = form.locator('select[name="documentId"]');
    await docSel.selectOption(usInv.id); await p.waitForTimeout(1500);
    const pre = {rate: await form.locator('[name="exchangeRate"]').inputValue(), hint: await form.locator('[data-rate-hint]').textContent()};
    await form.locator('[name="reference"]').fill('Wire R199');
    await form.locator('[name="amount"]').fill('1010.00');
    const g0 = Number(sql(`SELECT IFNULL(SUM(jl.debit_cents-jl.credit_cents),0) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id JOIN accounts a ON a.id=jl.account_id WHERE je.company_id='${CO}' AND a.code='6850'`));
    const invalid = await form.evaluate(f => [...f.elements].filter(e => e.willValidate && !e.checkValidity()).map(e => `${e.name}:${e.validationMessage}:${e.value}:max=${e.max}`));
    const btns = await form.evaluate(f => [...f.querySelectorAll('button')].map(b => `${b.type}|${b.textContent.trim().slice(0,30)}|${b.disabled}`));
    // Tegh's confirmations are in-app dialogs: approve the "record receipt" dialog, then the split offer.
    const approve = async () => { const d = p.locator('.tegh-action-dialog'); await d.waitFor({timeout: 15000}); dialogs.push(await d.locator('p').first().textContent()); await d.locator('button[type="submit"]').click(); await p.waitForTimeout(1500); };
    await form.locator('button[type="submit"]').click();
    await approve(); await approve(); await p.waitForTimeout(3000);
    const toasts = await p.evaluate(() => [...document.querySelectorAll('.tegh-toast,.srp-toast,[role=status],[role=alert]')].map(t => t.textContent.trim().slice(0, 160)).filter(Boolean));
    const vals = await form.evaluate(f => Object.fromEntries([...new FormData(f)].map(([k, v]) => [k, String(v).slice(0, 40)])));
    const pays = sql(`SELECT GROUP_CONCAT(CONCAT(IFNULL(document_id,'zz-advance'),':',foreign_amount_cents) ORDER BY IFNULL(document_id,'zz-advance') SEPARATOR ' ') FROM party_payments WHERE company_id='${CO}' AND reference='Wire R199'`);
    const fx = Number(sql(`SELECT IFNULL(SUM(jl.debit_cents-jl.credit_cents),0) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id JOIN accounts a ON a.id=jl.account_id WHERE je.company_id='${CO}' AND a.code='6850'`)) - g0;
    await ctx.close();
    const ok = pre.rate === '1.392000' && /recorded at 1\.365000/.test(pre.hint) && dialogs.some(d => /US?D? ?1,000\.00 open|USD 1,000\.00 open/.test(d) && /10\.00 as an advance/.test(d)) && pays === `${usInv.id}:100000 zz-advance:1000` && fx === -2700 && unbalanced(CO) === 0;
    return {ok, info: JSON.stringify({pays, fx, unbalanced: unbalanced(CO), rate: pre.rate, hintOk: /recorded at 1\.365000/.test(pre.hint), dialogs: dialogs.map(d => d.slice(0, 160)), errs, toasts})};
  });

  await t('UI-05', 'Registers: amounts in the table\'s currency show without the code and zero as a dash; the footer total keeps the code; the Tax Codes help no longer mentions CGST/SGST', async () => {
    const {ctx, p, errs} = await session();
    await p.evaluate(() => TeghPortal.openReport('Profit and Loss')); await p.waitForTimeout(3000);
    const cells = await p.evaluate(() => [...document.querySelectorAll('.srp-page:not([hidden]) td')].map(td => td.textContent.trim()).filter(x => /^[−-]?[\d,]+\.\d\d$|^–$|CAD/.test(x)).slice(0, 40));
    const foot = await p.evaluate(() => [...document.querySelectorAll('.srp-page:not([hidden]) .r27-total b')].map(b => b.textContent.trim()));
    await p.evaluate(() => TeghPortal.openTaxCodes()); await p.waitForTimeout(1500);
    const help = await p.evaluate(() => document.querySelector('.srp-page:not([hidden])')?.innerText || '');
    await ctx.close();
    const ok = cells.length > 3 && cells.some(x => x === '–') && cells.some(x => /^[\d,]+\.\d\d$/.test(x)) && !cells.some(x => /CAD/.test(x)) && foot.length && foot.every(x => /^-?CAD|CAD/.test(x)) && !/CGST|SGST/.test(help) && /GST and PST/.test(help);
    return {ok, info: JSON.stringify({cells: cells.slice(0, 12), foot, help: /GST and PST/.test(help), errs})};
  });
} finally {
  if (browser) await browser.close().catch(() => {});
  await setRelease('indiaEdition', start.indiaEdition || 'off').catch(() => {});
  await setRelease('newDashboard', start.newDashboard || 'off').catch(() => {});
  try { sql(`UPDATE users SET active=0 WHERE id='${UID}'`); } catch {}
  save('r199.json');
}

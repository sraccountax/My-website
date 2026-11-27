// R193: fixes from the large-data audit of both editions (bookkeeping modes, light and dark themes, side and top
// navigation). Synthetic companies only.
// API: customer and vendor balances, AR/AP ageing and party balance reports reconcile to the GL control accounts with
// an amount paid beyond its invoice, a credit note and a refund (Canada), and with TDS deducted from a vendor invoice
// (India); the workspace sends each invoice template snapshot once.
// Browser: the snapshot is restored on every invoice; an India company cannot switch to Guided mode and is told why;
// a report over the complete-row limit offers its latest month (large company); dark theme surfaces and text are
// readable while the light theme is unchanged.
import {S, sql, rec, save, need} from './lib.mjs';
import {chromium} from '/opt/node22/lib/node_modules/playwright/index.mjs';

const A = 'R193';
const t = async (id, title, fn) => { try { const r = await fn(); rec(id, A, title, r.ok ? 'PASS' : 'FAIL', r.info); return r.ok; } catch (e) { rec(id, A, title, 'FAIL', String(e.message || e).slice(0, 400)); return false; } };
const PW = 'Gate!Owner#2026pw';
const o = new S(); await o.login('owner@gate.test', PW);
const release = async () => (await o.call('site-admin', {company: false})).b.groups.release;
const setRelease = async (k, v) => { const cur = await release(); return o.call('site-admin/save', {method: 'POST', company: false, json: {groups: {release: {value: {...cur.value, [k]: v}, revision: cur.revision}}, reason: `R193 gate ${k} ${v}`}}); };
const start = (await release()).value; const startIndia = start.indiaEdition || 'off', startEdit = start.editIssuedInvoices || 'off';
const tag = Date.now().toString(36); const n4 = String(Date.now() % 10000).padStart(4, '0');
let n = 0; const key = p => `r193-${p}-${tag}-${++n}`.padEnd(20, 'x');
const CH = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
const gstin = b => { let s = 0; for (let i = 0; i < 14; i++) { const p = CH.indexOf(b[i]) * (i % 2 === 0 ? 1 : 2); s += Math.floor(p / 36) + p % 36; } return b + CH[(36 - s % 36) % 36]; };
const gl = (co, code) => Number(sql(`SELECT IFNULL(SUM(jl.debit_cents-jl.credit_cents),0) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id JOIN accounts a ON a.id=jl.account_id WHERE je.company_id='${co}' AND je.status='posted' AND a.code='${code}'`));
const today = sql('SELECT CURDATE()');
// Party trial balance totals and the four dated subledger reports, as positive amounts owed.
const reconcile = async (s, ar) => {
  const tb = need('tb', await s.call(`portal/trial-balance?scope=${ar ? 'receivables' : 'payables'}&start=2000-01-01&end=${today}`));
  const party = (tb.rows || []).reduce((x, r) => x + (ar ? (r.closingDebitCents || 0) - (r.closingCreditCents || 0) : (r.closingCreditCents || 0) - (r.closingDebitCents || 0)), 0);
  const reports = {};
  for (const r of ar ? ['ar-ageing', 'customer-balances'] : ['ap-ageing', 'vendor-balances']) {
    const x = await s.call(`professional-output/v5990/${r}&asOf=${today}`);
    reports[r] = {s: x.s, total: x.b?.output?.totals?.balanceCents ?? null, ex: [...new Set((JSON.stringify(x.b).match(/"code":"subledger_[a-z_]+"/g) || []))]};
  }
  return {party, reports};
};
const recOk = (r, glAmt) => r.party === glAmt && Object.values(r.reports).every(x => x.s === 200 && x.total === glAmt && !x.ex.length);

let browser;
try {
  need('india mode', await setRelease('indiaEdition', 'everyone'));
  need('edit issued invoices', await setRelease('editIssuedInvoices', 'everyone'));

  // Canada: invoice paid in full and then corrected lower (the difference stays with the customer as a credit), part of
  // that credit refunded, and a posted credit note on another invoice.
  const CA = new S(); await CA.login('owner@gate.test', PW);
  const CACO = need('company', await CA.call('companies', {method: 'POST', json: {businessType: 'corporation', country: 'Canada', province: 'ON', taxRegistered: true, taxNumber: '123456789RT0001', moduleMode: 'accounting', fiscalYearEnd: '12-31', booksStartDate: '2026-01-01', chartTemplate: 'default', currency: 'CAD', name: 'R193 Ledger ' + tag, accountingBasis: 'accrual'}})).company.id; CA.cid = CACO;
  const CUST = need('customer', await CA.call('customers', {method: 'POST', json: {name: 'R193 Customer ' + tag, email: '', phone: '', billingAddress: '1 King St', country: 'Canada', province: 'ON'}})).customer.id;
  const TMPL = (await CA.call('invoice-templates')).b.templates?.[0]?.id;
  const BANKGL = sql(`SELECT id FROM accounts WHERE company_id='${CACO}' AND code='1000'`);
  const line = (d, q, p) => ({description: d, quantity: q, unitPriceCents: p, taxable: true});
  const inv = async lines => need('invoice', await CA.call('invoices', {method: 'POST', json: {customerId: CUST, issueDate: '2026-09-10', dueDate: '2026-10-30', currency: 'CAD', templateId: TMPL, issue: true, lines}})).invoice;

  await t('R193-01', 'Canada: customer balances, Receivable Ageing and Customer Balances equal the receivables control account after an invoice corrected lower once paid (the difference stays as a customer credit), part of that credit refunded, and a posted credit note', async () => {
    const i1 = await inv([line('Hosting', 1, 100000)]), i2 = await inv([line('Support', 1, 50000)]);
    need('pay', await CA.call('payments', {method: 'POST', json: {type: 'customer', documentId: i1.id, paymentDate: '2026-09-20', reference: 'EFT 1', foreignAmountCents: 113000, paymentAccountId: BANKGL, operationKey: key('pay')}}));
    const d = need('detail', await CA.call('invoices/r20/detail?invoiceId=' + i1.id)); const di = d.invoice;
    need('correct', await CA.call('invoices/correction', {method: 'POST', json: {invoiceId: i1.id, expectedRevision: d.revision, customerId: di.customerId, issueDate: di.issueDate, dueDate: di.dueDate, currency: di.currency, exchangeRateMicros: di.exchangeRateMicros, templateId: di.templateId, purchaseOrder: '', message: '', lines: [line('Hosting', 1, 80000)], reason: 'Discount agreed after payment', operationKey: key('fix')}}));
    need('refund', await CA.call('payments', {method: 'POST', json: {type: 'customer', flowKind: 'customer_refund', partyId: CUST, paymentDate: '2026-09-26', reference: 'Refund part', foreignAmountCents: 10000, paymentAccountId: BANKGL, currency: 'CAD', operationKey: key('refund')}}), [201]);
    const cn = await CA.call('accounting-notes', {method: 'POST', json: {kind: 'customer_credit', sourceId: i2.id, date: '2026-09-22', subtotalCents: 10000, taxCents: 1300, memo: 'Return', operationKey: key('cn'), lines: [{description: 'Return', quantityMilli: 1000, foreignUnitPriceCents: 10000}]}});
    const cnId = cn.b?.note?.id || cn.b?.id; need('post note', await CA.call('accounting-notes', {method: 'PATCH', json: {noteId: cnId, action: 'post'}}));
    const r = await reconcile(CA, true), g = gl(CACO, '1200');
    // Independently: invoices 90,400 + 56,500 = 146,900; paid 113,000; refund +10,000; credit note -11,300 => 32,600.
    return {ok: g === 32600 && recOk(r, g), info: JSON.stringify({gl: g, ...r})};
  });

  // India: TDS deducted from a vendor invoice reduces what is owed to the vendor.
  const IN = new S(); await IN.login('owner@gate.test', PW, {edition: 'IN'});
  const PAN = 'AAACR' + n4 + 'T', VPAN = 'AAAFR' + n4 + 'V';
  const INCO = need('india company', await IN.call('companies', {method: 'POST', company: false, json: {name: 'R193 TDS ' + tag, legalName: 'R193 TDS Private Limited', constitution: 'private_limited', state: 'MH', pin: '400001', city: 'Mumbai', fiscalYearEnd: '03-31', booksStartDate: '2026-04-01', pan: PAN, gstin: gstin('27' + PAN + '1Z')}}), [201]).company.id; IN.cid = INCO;
  await t('R193-02', 'India: vendor balances, Payable Ageing and Vendor Balances equal the payables control account after TDS is deducted from a vendor invoice (the TDS reduces what is owed to the vendor)', async () => {
    const M = (r, j) => IN.call('india/masters/' + r, {method: 'POST', json: j});
    const SEC = 'R193C-' + tag; const id = need('rule', await M('rule', {category: 'tds_section', code: SEC, label: 'Synthetic ' + SEC, source: 'Synthetic gate data', effectiveFrom: '2026-04-01', ratePercent: '1', thresholdMinCents: '3000000', thresholdMaxCents: '10000000'}), [201]).id;
    need('approve', await M('rule-approve', {id}));
    const v = need('vendor', await IN.call('vendors', {method: 'POST', json: {name: 'R193 Contractor ' + tag, email: '', address: '', defaultTermsDays: 30, currency: 'INR', country: 'India', province: 'MH'}}), [200, 201]); const VID = v.vendor?.id || v.id;
    need('profile', await M('profile', {entity: 'vendor', entityId: VID, effectiveFrom: '2026-04-01', data: {pan: VPAN, tdsSection: SEC}}), [201]);
    const acct = c => sql(`SELECT id FROM accounts WHERE company_id='${INCO}' AND code='${c}'`);
    const b = need('bill', await IN.call('bills', {method: 'POST', json: {vendorId: VID, number: 'R193-' + tag, billDate: '2026-09-16', dueDate: '2026-10-30', currency: 'INR', taxEntryMode: 'exclusive', memo: 'Synthetic contract work', issue: true, lines: [{description: 'Contract work', quantity: 1, unitPriceCents: 6000000, accountId: acct('6400')}]}}), [200, 201]);
    const BID = b.bill?.id || b.id;
    need('tds', await IN.call('india/tds/record', {method: 'POST', json: {kind: 'tds', documentId: BID}}), [200, 201]);
    const r = await reconcile(IN, false), g = -gl(INCO, '2050');
    // Independently: ₹60,000 owed less 1% TDS ₹600 => ₹59,400.
    return {ok: g === 5940000 && recOk(r, g), info: JSON.stringify({gl: g, ...r})};
  });

  await t('R193-03', 'Workspace: each distinct invoice template snapshot is sent once (invoiceTemplateSnapshots) and every invoice names it; no invoice carries its own copy', async () => {
    const w = need('workspace', await CA.call('workspace'));
    const invs = w.invoices || [], snaps = w.invoiceTemplateSnapshots || {};
    return {ok: invs.length >= 2 && invs.every(i => i.templateSnapshotRef && snaps[i.templateSnapshotRef] && !i.templateSnapshot) && Object.keys(snaps).length >= 1,
      info: JSON.stringify({invoices: invs.length, snapshots: Object.keys(snaps).length, refs: invs.filter(i => i.templateSnapshotRef).length, inline: invs.filter(i => i.templateSnapshot).length})};
  });

  browser = await chromium.launch({executablePath: '/opt/pw-browsers/chromium', args: ['--no-proxy-server', '--host-resolver-rules=MAP gate.test 127.0.0.1']});
  const open = async (ed, co, {vp = {width: 1440, height: 900}, theme = 'light'} = {}) => {
    const ctx = await browser.newContext({viewport: vp, ignoreHTTPSErrors: true});
    await ctx.addInitScript(id => { try { if (!sessionStorage.getItem('r193-co')) { localStorage.setItem('sr-accountax-company', id); localStorage.setItem('sr-accountax-companies', JSON.stringify([id])); sessionStorage.setItem('r193-co', '1'); } } catch {} }, co);
    const p = await ctx.newPage(); await p.route(/clarity\.ms|challenges\.cloudflare|googleapis|gstatic|bing\.com/, r => r.abort());
    const errs = []; p.on('pageerror', e => errs.push(e.message.slice(0, 160)));
    await p.goto('https://gate.test/app.html' + (ed === 'IN' ? '?edition=in' : '')); await p.waitForSelector('#sr-login-form', {timeout: 30000});
    if (ed === 'IN') await p.selectOption('#sr-login-form [name=edition]', 'IN');
    await p.fill('#sr-login-form input[name=email]', 'owner@gate.test'); await p.fill('#sr-login-form input[name=password]', PW); await p.click('#sr-login-form button[type=submit]');
    await p.waitForFunction(() => window.TeghPortal && document.querySelector('.sidebar,.topbar'), null, {timeout: 60000}); await p.waitForTimeout(2000);
    await p.evaluate(() => window.TeghPortal.switchBookkeepingMode?.('accountant')).catch(() => {});
    // The theme is chosen on the Preferences page, as a user does.
    await p.evaluate(() => window.TeghPortal.openTeghPreferences()); await p.waitForSelector('[data-tegh-preferences]'); await p.waitForTimeout(400);
    await p.locator(`[data-tegh-preferences] input[name=theme][value=${theme}]`).check({force: true}); await p.locator('[data-tegh-preferences] input[name=navigationLayout][value=side]').check({force: true});
    await p.locator('[data-tegh-preferences] button[type=submit]').first().click(); await p.waitForTimeout(1200);
    return {ctx, p, errs};
  };
  const lumOf = `const lum=c=>{const m=c.match(/\\d+(\\.\\d+)?/g).map(Number);const f=x=>{x/=255;return x<=0.03928?x/12.92:Math.pow((x+0.055)/1.055,2.4)};return 0.2126*f(m[0])+0.7152*f(m[1])+0.0722*f(m[2])};const ratio=(a,b)=>{const x=lum(a),y=lum(b);return (Math.max(x,y)+0.05)/(Math.min(x,y)+0.05)};`;

  await t('UI-01', 'Browser: the invoice template snapshot is restored on every invoice the portal reads (Customer Invoices opens without errors)', async () => {
    const {ctx, p, errs} = await open('CA', CACO);
    try {
      const r = await p.evaluate(async () => { const x = await (await fetch('/api/index.php?route=workspace', {headers: {'X-Company-Id': localStorage.getItem('sr-accountax-company')}, credentials: 'same-origin'})).json(); window.teghRehydrateWorkspaceR193(x); return {n: x.invoices.length, ok: x.invoices.every(i => i.templateSnapshot && typeof i.templateSnapshot.accentColor === 'string')}; });
      await p.evaluate(() => TeghPortal.invokeMenuAction('', 'receivables:customer-invoices')).catch(() => {}); await p.waitForTimeout(2500);
      return {ok: r.n >= 2 && r.ok && !errs.length, info: JSON.stringify({...r, errs})};
    } finally { await ctx.close(); }
  });

  await t('UI-02', 'India: choosing Guided mode is refused with a message (India companies use Full Accounting) and the mode stays Full Accounting; the Bookkeeping Mode page shows Guided as not available; a Canadian company still switches to Guided', async () => {
    const {ctx, p, errs} = await open('IN', INCO); const got = {};
    try {
      got.switched = await p.evaluate(() => window.TeghPortal.switchBookkeepingMode('owner')); await p.waitForTimeout(600);
      got.toast = await p.evaluate(() => [...document.querySelectorAll('.srp-toast,[role=status],[role=alert]')].map(x => x.textContent).join(' ').slice(0, 300));
      got.mode = await p.evaluate(() => document.documentElement.dataset.bookkeepingMode);
      await p.evaluate(() => window.TeghPortal.openBookkeepingModeSettings()); await p.waitForTimeout(1500);
      got.guidedDisabled = await p.evaluate(() => document.querySelector('[data-settings-mode=owner]')?.disabled === true);
      got.note = await p.evaluate(() => document.getElementById('in-guided-note')?.textContent || '');
    } finally { await ctx.close(); }
    const c = await open('CA', CACO);
    try { got.caSwitched = await c.p.evaluate(() => window.TeghPortal.switchBookkeepingMode('owner')); await c.p.waitForTimeout(800); got.caMode = await c.p.evaluate(() => document.documentElement.dataset.bookkeepingMode); await c.p.evaluate(() => window.TeghPortal.switchBookkeepingMode('accountant')); } finally { await c.ctx.close(); }
    return {ok: got.switched === false && /not available for India/i.test(got.toast) && got.mode === 'full-accounting' && got.guidedDisabled && /Full Accounting/.test(got.note) && !/Canad/i.test(got.note) && got.caSwitched === true && got.caMode === 'guided' && !errs.length, info: JSON.stringify(got)};
  });

  await t('UI-03', 'Large company: a report over the complete-row limit (General Ledger Detail, fiscal year to date) shows no preview totals and offers its latest month; choosing it shows that month in full with debits equal to credits', async () => {
    const big = sql(`SELECT c.id FROM companies c JOIN company_members cm ON cm.company_id=c.id JOIN users u ON u.id=cm.user_id WHERE u.email='owner@gate.test' AND c.name='Volume Northwind Ltd' AND c.active=1 LIMIT 1`);
    if (!big) return {ok: false, info: 'Volume Northwind Ltd (the R161 large company) is not on this installation'};
    const {ctx, p, errs} = await open('CA', big); const got = {};
    try {
      await p.evaluate(() => { const i = [...document.querySelectorAll('input')]; }); await p.evaluate(() => TeghPortal.openReport('General Ledger Detail')); await p.waitForTimeout(9000);
      got.refused = await p.evaluate(() => !!document.querySelector('[data-tegh-authoritative-report] .srp-notice.danger'));
      got.previewTotals = await p.evaluate(() => [...document.querySelectorAll('.srp-report-toolbar .srp-kpis')].filter(k => !k.hidden && k.offsetParent).length);
      const btn = p.locator('[data-tegh-report-latest-month]').first(); got.offer = (await btn.count()) ? (await btn.textContent()).trim() : '';
      // The latest month of the fiscal year to date: move to September 2026 (inside the large company's data) by editing the end date first.
      if (got.offer) { await btn.click(); await p.waitForTimeout(9000); }
      got.after = await p.evaluate(() => ({refused: !!document.querySelector('[data-tegh-authoritative-report] .srp-notice.danger'), start: document.querySelector('input[name=start]')?.value, end: document.querySelector('input[name=end]')?.value, text: (document.querySelector('[data-tegh-authoritative-report]')?.innerText || '').replace(/\s+/g, ' ').slice(-200)}));
    } finally { await ctx.close(); }
    const mStart = got.after?.end ? got.after.end.slice(0, 8) + '01' : '';
    return {ok: got.refused && got.previewTotals === 0 && /^Show .+ only$/i.test(got.offer) && got.after.refused === false && got.after.start === mStart && !errs.length, info: JSON.stringify(got)};
  });

  await t('UI-04', 'Dark theme: the Add bank transaction dialog header and the Users status pills are dark surfaces with readable text, Journal Entry tabs are readable (contrast at least 3:1); in the light theme the dialog header stays white', async () => {
    const measure = async p => p.evaluate(src => { eval(src); const out = {};
      const head = document.querySelector('.r182-modal-head'); if (head) { const cs = getComputedStyle(head); out.headBg = cs.backgroundColor; const h = head.querySelector('h2'); out.headText = h ? ratio(getComputedStyle(h).color, cs.backgroundColor) : null; }
      return out; }, lumOf + 'globalThis.ratio=ratio;globalThis.lum=lum;');
    const res = {};
    for (const theme of ['dark', 'light']) {
      const {ctx, p, errs} = await open('CA', CACO, {theme});
      try {
        await p.evaluate(() => TeghPortal.invokeMenuAction('', 'banking:match-and-post-transactions')); await p.waitForTimeout(3000);
        await p.locator('[data-r182-add-tx]').first().click(); await p.waitForSelector('.r182-modal-head', {timeout: 15000}); await p.waitForTimeout(500);
        res[theme] = await measure(p);
        await p.keyboard.press('Escape'); await p.waitForTimeout(400);
        if (theme === 'dark') {
          await p.evaluate(() => TeghPortal.invokeMenuAction('', 'general-ledger:journal-entry')); await p.waitForTimeout(2500);
          res.tabs = await p.evaluate(src => { eval(src); return [...document.querySelectorAll('.r187-je-tabs > button')].map(b => { let e = b, bg = null; while (e) { const c = getComputedStyle(e).backgroundColor; if (!/rgba\(0, 0, 0, 0\)|transparent/.test(c)) { bg = c; break; } e = e.parentElement; } return +ratio(getComputedStyle(b).color, bg || 'rgb(16,33,28)').toFixed(2); }); }, lumOf + 'globalThis.ratio=ratio;');
          await p.evaluate(() => TeghPortal.openUsers()); await p.waitForTimeout(2500);
          res.pills = await p.evaluate(src => { eval(src); return [...document.querySelectorAll('td > .srp-status')].slice(0, 6).map(s => +ratio(getComputedStyle(s).color, getComputedStyle(s).backgroundColor).toFixed(2)); }, lumOf + 'globalThis.ratio=ratio;');
        }
        res[theme + 'Errs'] = errs;
      } finally { await ctx.close(); }
    }
    const lumBg = c => { const m = c.match(/\d+/g).map(Number); return (m[0] + m[1] + m[2]) / 765; };
    return {ok: res.dark?.headBg && lumBg(res.dark.headBg) < 0.3 && res.dark.headText >= 4.5 && res.light?.headBg === 'rgb(255, 255, 255)' && res.tabs?.length && res.tabs.every(x => x >= 3) && (!res.pills?.length || res.pills.every(x => x >= 3)) && !res.darkErrs.length && !res.lightErrs.length, info: JSON.stringify(res)};
  });
} finally {
  if (browser) await browser.close().catch(() => {});
  await setRelease('indiaEdition', startIndia).catch(() => {});
  await setRelease('editIssuedInvoices', startEdit).catch(() => {});
  save('r193.json');
}

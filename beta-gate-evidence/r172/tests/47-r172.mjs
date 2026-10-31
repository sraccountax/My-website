// R172 regression checks for what the R172 testing round found and fixed, plus journeys no earlier suite covered
// (fixed assets, recurring invoices, budgets, data import). A fresh synthetic company; screens are driven the way a
// person uses them.
import fs from 'fs';
import {S, sql, rec, save, need} from './lib.mjs';
import {open} from './e2e/base.mjs';
const A = 'R172';
const pw = 'Gate!Owner#2026pw';
const stamp = Date.now().toString(36);
const t = async (id, title, fn) => { try { const r = await fn(); rec(id, A, title, r.ok ? 'PASS' : 'FAIL', r.info); return r.ok; } catch (e) { rec(id, A, title, 'FAIL', e.message.slice(0, 400)); return false; } };
const o = new S(); await o.login('owner@gate.test', pw);
const CID = need('co', await o.call('companies', {method: 'POST', company: false, json: {name: 'R172 Round Test Ltd ' + stamp, province: 'BC', country: 'Canada', businessType: 'corporation', currency: 'CAD', accountingBasis: 'accrual', moduleMode: 'accounting', fiscalYearEnd: '12-31', booksStartDate: '2026-01-01', taxRegistered: true, taxNumber: '123456789RT0001', pstRegistered: true, coaMode: 'default'}})).company?.id;
o.cid = CID;
const ids = JSON.parse(fs.readFileSync('/srv/gate/t/ids.json')); ids.R172 = CID; fs.writeFileSync('/srv/gate/t/ids.json', JSON.stringify(ids));
try { sql(`INSERT INTO company_onboarding (company_id,required,steps_json,completed_at,tour_done_at) VALUES ('${CID}',0,'{}',UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE completed_at=UTC_TIMESTAMP(),tour_done_at=UTC_TIMESTAMP()`); } catch {}
const cust = need('cust', await o.call('customers', {method: 'POST', json: {name: 'Harbourfront Dental ' + stamp, email: '', phone: '', billingAddress: '1 Test St', province: 'BC'}}));
await o.call('vendors', {method: 'POST', json: {name: 'Fraser Valley Supply ' + stamp, province: 'BC', country: 'Canada', paymentTerms: 30}});
need('lock', await o.call('period-close', {method: 'POST', json: {closedThroughDate: '2026-06-30'}}), [200, 201]);
const acct = code => sql(`SELECT id FROM accounts WHERE company_id='${CID}' AND code='${code}'`);
const audit = action => Number(sql(`SELECT COUNT(*) FROM audit_log WHERE company_id='${CID}' AND action='${action}'`));

const {b, p, errs} = await open(CID, {w: 1440, h: 900});
const failed = []; p.on('response', r => { if (r.status() >= 400 && /gate\.test/.test(r.url())) failed.push(r.status() + ' ' + decodeURIComponent((/route=([^&]+)/.exec(r.url()) || [])[1] || r.url())); });
const page = () => p.evaluate(() => [...document.querySelectorAll('.srp-page')].pop());
const menu = async id => { await p.evaluate(id => TeghPortal.invokeMenuAction('', id), id); await p.waitForTimeout(3000); };

await t('RT-01', 'Create Vendor loads its supplier list without a failed request (a vendor’s currency field is not sent as a list filter)', async () => {
  failed.length = 0; await menu('payables:create-vendor'); await p.waitForTimeout(1500);
  const rows = await p.evaluate(() => [...document.querySelectorAll('.srp-page:last-of-type tr')].length);
  return {ok: failed.length === 0, info: `failed requests: ${failed.join(', ') || 'none'}; table rows ${rows}`};
});
await t('RT-02', 'Tegh Assist page: the question box uses the page width (was squeezed into a 190px column)', async () => {
  await p.evaluate(() => TeghPortal.openCommandCentre()); await p.waitForTimeout(2500);
  const w = await p.evaluate(() => Math.round(document.querySelector('[data-command-free-text] input')?.getBoundingClientRect().width || 0));
  return {ok: w >= 400, info: `question box ${w}px at 1440 wide`};
});
await t('RT-03', 'Account & Access: name and email fields are wide enough to type in (were about 106–132px)', async () => {
  await p.evaluate(() => TeghPortal.openAccount()); await p.waitForTimeout(2500);
  const w = await p.evaluate(() => [...document.querySelectorAll('input[name=displayName],input[type=email]')].filter(i => i.offsetParent).map(i => Math.round(i.getBoundingClientRect().width)));
  return {ok: w.length > 0 && Math.min(...w) >= 200, info: `field widths ${w.join(', ')}px`};
});
await t('RT-04', 'GIFI codes a person chose are kept when the GIFI report opens; blank standard accounts still get the suggested code; no empty audit entries', async () => {
  need('map', await o.call('operations/account-mappings', {method: 'POST', json: {rows: [{accountId: acct('1500'), gifiCode: '1774'}, {accountId: acct('6810'), gifiCode: ''}]}}));
  const before = audit('accounts.tax_mappings_updated');
  // A fresh page, as a person opening the GIFI report after the change would have.
  await p.reload(); await p.waitForFunction(() => window.TeghPortal && document.querySelector('.sidebar,.topbar'), null, {timeout: 40000}); await p.waitForTimeout(2500);
  for (let i = 0; i < 2; i++) { await menu('general-ledger:gifi-report'); await p.waitForTimeout(1500); }
  const g1500 = sql(`SELECT IFNULL(gifi_code,'') FROM accounts WHERE id='${acct('1500')}'`), g6810 = sql(`SELECT IFNULL(gifi_code,'') FROM accounts WHERE id='${acct('6810')}'`);
  const added = audit('accounts.tax_mappings_updated') - before, empty = Number(sql(`SELECT COUNT(*) FROM audit_log WHERE company_id='${CID}' AND action='accounts.tax_mappings_updated' AND metadata_json LIKE '%"updated":0%'`));
  return {ok: g1500 === '1774' && g6810 === '8670' && added === 1 && empty === 0, info: `1500 → ${g1500} (chosen 1774); 6810 → ${g6810}; audit entries from two openings ${added}; "updated 0" entries ${empty}`};
});

// Fixed assets
await t('RT-05', 'Fixed asset schedule: $12,000 over 60 months is $200 a month; months in the closed period say so instead of offering Post', async () => {
  await menu('advanced-accounting:fixed-assets');
  await p.locator('button', {hasText: 'Add Asset Schedule'}).first().click(); await p.waitForTimeout(800);
  const f = n => p.locator(`[name=${n}]`).first();
  await f('name').fill('Laser cutter ' + stamp); await f('acquisitionDate').fill('2026-01-15'); await f('inServiceDate').fill('2026-02-01');
  await f('originalCost').fill('12000'); await f('salvageValue').fill('0'); await f('usefulLifeMonths').fill('60');
  await f('assetAccountId').selectOption(acct('1500'));
  await p.evaluate(() => document.querySelector('[name=usefulLifeMonths]').form.requestSubmit()); await p.waitForTimeout(3500);
  const lines = await p.evaluate(() => [...document.querySelectorAll('.srp-page:last-of-type tr')].map(r => r.innerText.replace(/\s+/g, ' ')).filter(t => /Laser cutter/.test(t) && /\$200\.00/.test(t)));
  const closed = lines.filter(l => /Closed period/.test(l)).length, open = lines.filter(l => !/Closed period/.test(l)).length;
  return {ok: lines.length === 8 && closed === 5 && open === 3, info: `${lines.length} lines due at $200 (Feb–Sep); in the closed period ${closed} (Feb–Jun); open ${open}`};
});
await t('RT-06', 'Posting September depreciation makes one balanced entry: Dr 6810 Depreciation Expense, Cr 1590 Accumulated Depreciation, $200', async () => {
  const row = p.locator('tr', {hasText: 'Laser cutter ' + stamp}).filter({hasText: 'Sep 30, 2026'}).first();
  await row.locator('summary, button', {hasText: 'Actions'}).first().click(); await p.waitForTimeout(500);
  await p.locator('button[data-post-dep]:visible').first().click(); await p.waitForTimeout(3500);
  const lines = sql(`SELECT GROUP_CONCAT(CONCAT(a.code,':',jl.debit_cents,'/',jl.credit_cents) ORDER BY a.code) FROM journal_entries je JOIN journal_lines jl ON jl.journal_entry_id=je.id JOIN accounts a ON a.id=jl.account_id WHERE je.company_id='${CID}' AND je.entry_date='2026-09-30' AND je.status='posted'`);
  return {ok: lines === '1590:0/20000,6810:20000/0', info: lines};
});
await t('RT-07', 'The server still refuses depreciation in a closed period (the screen no longer offers it)', async () => {
  // Sent the way the screen sends it: an operation key, the schedule revision and the feature-access revision.
  const ws = (await o.call('advanced/workspace')).b, line = (ws.depreciationLines || []).find(l => l.periodEnd === '2026-03-31');
  const r = await o.call('advanced/assets/post-depreciation', {method: 'POST', json: {depreciationLineId: line?.id, operationKey: 'dep-r172-' + stamp + '-locked', expectedSourceRevisionHash: line?.sourceRevisionHash, expectedEntitlementRevision: Number(ws.entitlementRevision || 0)}});
  return {ok: r.s === 409 && /locked period/i.test(JSON.stringify(r.b)), info: `${r.s} ${JSON.stringify(r.b).slice(0, 120)}`};
});

// Recurring invoice
await t('RT-08', 'Recurring invoice profile run from its Actions menu issues the invoice with BC GST and PST ($500 + $60 = $560) and moves the next date on a month', async () => {
  await menu('advanced-accounting:recurring-transactions');
  await p.locator('button', {hasText: 'Create Profile'}).first().click(); await p.waitForTimeout(800);
  const form = p.locator('form:has([name=customerId])').first();
  await form.locator('[name=name]').fill('Monthly retainer ' + stamp);
  await form.locator('[name=customerId]').selectOption(cust.customer?.id || cust.id);
  await form.locator('[name=nextInvoiceDate]').fill('2026-10-01');
  await form.locator('[name=description]').fill('Monthly bookkeeping retainer'); await form.locator('[name=quantity]').fill('1'); await form.locator('[name=unitPrice]').fill('500');
  if (!(await form.locator('[name=taxable]').isChecked())) await form.locator('[name=taxable]').check();
  await form.locator('[name=issueAutomatically]').check();
  await form.evaluate(f => f.requestSubmit()); await p.waitForTimeout(3000);
  await p.locator('tr', {hasText: 'Monthly retainer ' + stamp}).first().locator('summary, button', {hasText: 'Actions'}).first().click(); await p.waitForTimeout(500);
  await p.locator('.tegh-overlay-surface button:visible, [role=menuitem]:visible').filter({hasText: /^Run$/}).first().click(); await p.waitForTimeout(3500);
  const inv = sql(`SELECT CONCAT(status,' ',subtotal_cents,'+',tax_cents,'=',total_cents) FROM invoices WHERE company_id='${CID}' AND is_recurring=1 ORDER BY created_at DESC LIMIT 1`);
  const next = sql(`SELECT next_invoice_date FROM recurring_invoice_profiles WHERE company_id='${CID}'`);
  return {ok: /50000\+6000=56000$/.test(inv) && next === '2026-11-01', info: `${inv}; next ${next}`};
});
// Budget
await t('RT-09', 'Budget for 6400 Office Supplies shows planned $1,200 and actual equal to the posted GL amount', async () => {
  await menu('advanced-accounting:budgets');
  await p.locator('button', {hasText: 'Create Budget'}).first().click(); await p.waitForTimeout(800);
  const form = p.locator('form:has([name=periodStart])').first();
  await form.locator('[name=name]').fill('Office budget ' + stamp); await form.locator('[name=periodStart]').fill('2026-01-01'); await form.locator('[name=periodEnd]').fill('2026-12-31');
  const sel = form.locator('select').filter({has: p.locator('option', {hasText: '6400'})}).first(); await sel.selectOption(acct('6400'));
  await form.locator('input[type=number]').first().fill('1200');
  await form.evaluate(f => f.requestSubmit()); await p.waitForTimeout(3000);
  const row = await p.locator('tr', {hasText: 'Office budget ' + stamp}).first().innerText();
  const gl = Number(sql(`SELECT IFNULL(SUM(jl.debit_cents-jl.credit_cents),0) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id WHERE je.company_id='${CID}' AND jl.account_id='${acct('6400')}' AND je.status='posted'`));
  const money = c => '$' + (c / 100).toLocaleString('en-CA', {minimumFractionDigits: 2});
  return {ok: /\$1,200\.00/.test(row) && row.includes(money(gl)), info: row.replace(/\s+/g, ' ').slice(0, 160) + ` · GL ${money(gl)}`};
});
// Data import
await t('RT-10', 'Customer CSV import: 5 rows → 2 eligible, 2 errors (no name, bad email), 1 duplicate; only the 2 eligible customers are created', async () => {
  const csv = '/srv/gate/ev/r172-customers.csv';
  fs.writeFileSync(csv, `name,email,phone,province,country\nMaple Bakery ${stamp},orders@maple.example,416-555-0101,ON,Canada\nCedar Florist ${stamp},,604-555-0102,BC,Canada\n,missing@name.example,,ON,Canada\nBad Email ${stamp},not-an-email,,ON,Canada\nMaple Bakery ${stamp},orders@maple.example,416-555-0101,ON,Canada\n`);
  await p.evaluate(() => TeghPortal.openDataImport()); await p.waitForTimeout(3000);
  const typeSel = p.locator('select').filter({has: p.locator('option[value*=custom]')}).first();
  if (await typeSel.count()) await typeSel.selectOption({index: await typeSel.evaluate(s => [...s.options].findIndex(o => /customer/i.test(o.value + o.text) && !/invoice/i.test(o.value + o.text)))});
  await p.locator('input[type=file]').first().setInputFiles(csv); await p.waitForTimeout(400);
  await p.locator('button:visible', {hasText: /Validate/}).first().click(); await p.waitForTimeout(4000);
  const summary = (await p.evaluate(() => [...document.querySelectorAll('.srp-page')].pop().innerText)).replace(/\s+/g, ' ');
  await p.locator('[role=dialog] button:visible', {hasText: 'Select All Eligible'}).first().click(); await p.waitForTimeout(500);
  await p.locator('[role=dialog] button:visible', {hasText: 'Continue With Import'}).first().click(); await p.waitForTimeout(4000);
  const made = sql(`SELECT GROUP_CONCAT(name ORDER BY name SEPARATOR ' | ') FROM customers WHERE company_id='${CID}' AND name LIKE '%${stamp}' AND name NOT LIKE 'Harbourfront%'`);
  return {ok: /ELIGIBLE 2/.test(summary) && /ERRORS 2/.test(summary) && /DUPLICATES 1/.test(summary) && made === `Cedar Florist ${stamp} | Maple Bakery ${stamp}`, info: `${(summary.match(/ELIGIBLE \d+ WARNINGS \d+ ERRORS \d+ DUPLICATES \d+/) || [''])[0]}; created: ${made}`};
});
await t('RT-11', 'The company feed shows the recurring invoice (with the customer), the new fixed asset, the depreciation and the import', async () => {
  const r = await o.call('activity-feed?limit=50'); const texts = (r.b.items || []).map(i => i.text);
  const want = [/issued recurring invoice INV-\d+ to Harbourfront Dental/, /added the fixed asset Laser cutter/, /posted depreciation for the period to 2026-09-30/, /imported 2 customers/];
  const missing = want.filter(re => !texts.some(x => re.test(x)));
  return {ok: r.s === 200 && !missing.length, info: `missing: ${missing.map(String).join(', ') || 'none'}`};
});
const dlKind = async click => { const [dl] = await Promise.all([p.waitForEvent('download', {timeout: 30000}), click()]); const buf = fs.readFileSync(await dl.path()); return {name: dl.suggestedFilename(), n: buf.length, head: buf.slice(0, 4).toString('latin1')}; };
await t('RT-13', 'Fixed Assets "Export ▾" menu (opened over the page) downloads a PDF and an Excel file; it gave nothing before', async () => {
  await p.evaluate(() => TeghPortal.openReport('Fixed Asset Register')); await p.waitForTimeout(3500);
  const ex = p.locator('.srp-page').last().locator('summary:visible').filter({hasText: /^\s*Export/}).first();
  const got = [];
  for (const f of ['PDF', 'Excel']) { await ex.click(); await p.waitForTimeout(400); got.push(await dlKind(() => p.locator('.tegh-overlay-layer button:visible').filter({hasText: f}).first().click())); await p.waitForTimeout(500); }
  return {ok: got[0].head === '%PDF' && got[0].n > 1000 && got[1].head.startsWith('PK') && got[1].n > 1000, info: got.map(g => `${g.name} ${g.n} bytes`).join('; ')};
});
await t('RT-14', 'Profit and Loss by month at 1440 wide: the "Actions ⋮" table menu stays on screen and its Export… gives a PDF', async () => {
  await p.evaluate(() => TeghPortal.openReport('Profit and Loss')); await p.waitForTimeout(3500);
  const pg = p.locator('.srp-page').last();
  const cols = pg.locator('select').filter({has: p.locator('option', {hasText: 'Monthly'})}).first();
  if (await cols.count()) { await cols.selectOption({label: 'Monthly'}); await pg.locator('button:visible', {hasText: 'Run Report'}).first().click(); await p.waitForTimeout(3500); }
  const btn = pg.locator('button[aria-label="Table actions"]:visible').first(); const box = await btn.boundingBox();
  await btn.click(); await p.waitForTimeout(400); await p.locator('[role=menu] button:visible').filter({hasText: 'Export'}).first().click(); await p.waitForTimeout(600);
  const dlg = p.locator('dialog[open], [role=dialog]').filter({hasText: 'Export report'}).first(); await dlg.locator('select').first().selectOption('pdf');
  const got = await dlKind(() => dlg.locator('button').filter({hasText: /^Export$/}).first().click());
  return {ok: box && box.x + box.width <= 1440 && got.head === '%PDF' && got.n > 1000, info: `button right edge ${box && Math.round(box.x + box.width)}px; ${got.name} ${got.n} bytes`};
});
await t('RT-JS', 'No browser errors on the desktop', async () => ({ok: errs.length === 0, info: errs.join(' | ') || 'none'}));
await b.close();

const ph = await open(CID, {w: 390, h: 844});
await t('RT-12', 'Phone 390×844: Invoice Intelligence filters are full width (the search box was 95px)', async () => {
  await ph.p.evaluate(() => TeghPortal.openInvoiceIntelligence()); await ph.p.waitForTimeout(3500);
  const w = await ph.p.evaluate(() => Math.round([...document.querySelectorAll('.ii-filters input[type=search]')].find(i => i.offsetParent)?.getBoundingClientRect().width || 0));
  return {ok: w >= 250, info: `search box ${w}px`};
});
await t('RT-PH-JS', 'No browser errors on the phone', async () => ({ok: ph.errs.length === 0, info: ph.errs.join(' | ') || 'none'}));
await ph.b.close();
save('r172.json');

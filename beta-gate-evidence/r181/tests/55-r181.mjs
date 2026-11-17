// R181: books in any currency, with exchange rates recorded by date.
// Every expected amount below is worked out here from the rates the test records (amount × rate), never from Tegh.
// The suite turns Worldwide currencies on for the platform owner, creates its own synthetic companies, and turns the
// switch back to what it was at the end.
import {S, sql, rec, save, need} from './lib.mjs';
import {open} from './e2e/base.mjs';
import fs from 'fs';

const A = 'R181';
const ids = JSON.parse(fs.readFileSync('/srv/gate/t/ids.json'));
const t = async (id, title, fn) => { try { const r = await fn(); rec(id, A, title, r.ok ? 'PASS' : 'FAIL', r.info); return r.ok; } catch (e) { rec(id, A, title, 'FAIL', String(e.message || e).slice(0, 400)); return false; } };
const o = new S(); await o.login('owner@gate.test', 'Gate!Owner#2026pw');
const release = async () => (await o.call('site-admin', {company: false})).b.groups.release;
const setMode = async m => { const cur = await release(); return o.call('site-admin/save', {method: 'POST', company: false, json: {groups: {release: {value: {...cur.value, worldCurrencies: m}, revision: cur.revision}}, reason: 'R181 gate ' + m}}); };
const startMode = (await release()).value.worldCurrencies || 'off';
const tag = Date.now().toString(36);
const base = {businessType: 'corporation', taxRegistered: false, accountingBasis: 'accrual', moduleMode: 'accounting', fiscalYearEnd: '03-31', booksStartDate: '2026-04-01', chartTemplate: 'default'};
const mkCompany = (country, province, extra = {}) => o.call('companies', {method: 'POST', json: {...base, name: `R181 ${country} ${tag}`, country, province, ...extra}});
const rows = (cid, q) => sql(q.replaceAll('$C', cid)).split('\n').filter(Boolean);
const gl = cid => Object.fromEntries(rows(cid, `SELECT a.code, SUM(jl.debit_cents-jl.credit_cents) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id AND je.status='posted' AND je.voided_at IS NULL JOIN accounts a ON a.id=jl.account_id WHERE je.company_id='$C' GROUP BY a.code`).map(l => l.split('\t')).map(([c, v]) => [c, Number(v)]));
const conv = (foreign, micros) => Math.round(foreign * micros / 1e6);
let IN = null, CUST = null, TMPL = null;

try {
await t('FX-01', 'Switch off (default): a company outside Canada is still kept in CAD, and auth/me reports the switch off', async () => {
  need('off', await setMode('off'));
  const me = (await o.call('auth/me', {company: false})).b;
  const r = await mkCompany('India', 'MH', {currency: 'INR'});
  return {ok: me.site?.worldCurrencies === 'off' && r.s === 422 && r.b.code === 'cad_gl_required', info: `auth/me ${me.site?.worldCurrencies}; INR company ${r.s} ${r.b.code}`};
});

await t('FX-02', 'Switch on for the owner: an India company defaults to INR, rates by date, Asia/Kolkata; Canada stays CAD; JPY, KWD and IDR are explained', async () => {
  need('owner', await setMode('owner'));
  const r = await mkCompany('India', 'MH');
  IN = r.b.company?.id;
  const row = IN ? sql(`SELECT CONCAT_WS('|',currency,fx_rate_policy,timezone,fx_rate_max_age_days) FROM companies WHERE id='${IN}'`) : '';
  const ca = await mkCompany('Canada', 'ON', {currency: 'INR'});
  const bad = {};
  for (const [c, cur] of [['Japan', 'JPY'], ['Kuwait', 'KWD'], ['Indonesia', 'IDR']]) bad[cur] = (await mkCompany(c, '', {currency: cur})).b.code;
  return {ok: r.s === 201 && row === 'INR|dated|Asia/Kolkata|7' && ca.s === 422 && ca.b.code === 'cad_gl_required' && Object.values(bad).every(x => x === 'base_currency_unsupported'),
    info: `India ${r.s} ${row}; Canada+INR ${ca.s} ${ca.b.code}; ${JSON.stringify(bad)}`};
});
o.cid = IN;

await t('FX-03', 'Rates: several saved at once; future dates, 3-decimal currencies and pairs below 0.005 are refused; lookups use the date, then an earlier rate up to 7 days', async () => {
  const ok = await o.call('currencies/rates', {method: 'POST', json: {rates: [
    {currency: 'USD', rateDate: '2026-09-26', rateToBaseMicros: 88_120_000}, {currency: 'USD', rateDate: '2026-09-29', rateToBaseMicros: 88_400_000},
    {currency: 'USD', rateDate: '2026-09-30', rateToBaseMicros: 88_520_000}, {currency: 'EUR', rateDate: '2026-09-29', rateToBaseMicros: 103_250_000}]}});
  const fut = await o.call('currencies/rates', {method: 'POST', json: {rates: [{currency: 'USD', rateDate: '2099-01-01', rateToBaseMicros: 88_000_000}]}});
  const kwd = await o.call('currencies/rates', {method: 'POST', json: {rates: [{currency: 'KWD', rateDate: '2026-09-29', rateToBaseMicros: 290_000_000}]}});
  const krw = await o.call('currencies/rates', {method: 'POST', json: {rates: [{currency: 'KRW', rateDate: '2026-09-29', rateToBaseMicros: 4_000}]}});
  const look = async d => (await o.call(`currencies/rate?currency=USD&date=${d}`)).b;
  const [d29, d28, d10] = [await look('2026-09-29'), await look('2026-09-28'), await look('2026-09-10')];
  return {ok: ok.s === 201 && ok.b.saved === 4 && fut.b.code === 'rate_date_future' && kwd.b.code === 'currency_unsupported' && krw.b.code === 'fx_rate_precision'
      && d29.rateToBaseMicros === 88_400_000 && d28.rateDate === '2026-09-26' && d28.rateToBaseMicros === 88_120_000 && d10.found === false,
    info: `saved ${ok.b.saved}; future ${fut.b.code}; KWD ${kwd.b.code}; KRW ${krw.b.code}; 29 Sep ${d29.rateToBaseMicros}; 28 Sep → ${d28.rateDate}; 10 Sep found ${d10.found}`};
});

await t('FX-04', 'A USD invoice converts at the recorded rate for its date; with no rate within 7 days it is not saved; a typed rate needs confirmation and is audited', async () => {
  CUST = need('customer', await o.call('customers', {method: 'POST', json: {name: 'R181 Acme US ' + tag, email: '', phone: '', billingAddress: '1 Main St', country: 'United States', province: 'NY'}}));
  CUST = CUST.customer?.id || CUST.id;
  TMPL = (await o.call('invoice-templates')).b.templates?.[0]?.id;
  const inv = (date, extra = {}) => o.call('invoices', {method: 'POST', json: {customerId: CUST, issueDate: date, dueDate: '2026-10-30', currency: 'USD', templateId: TMPL, issue: true, lines: [{description: 'Consulting', quantity: 1, unitPriceCents: 100000}], ...extra}});
  const before = Number(sql(`SELECT COUNT(*) FROM invoices WHERE company_id='${IN}'`));
  const a = await inv('2026-09-29'), b = await inv('2026-09-28'), c = await inv('2026-09-10');
  const after = Number(sql(`SELECT COUNT(*) FROM invoices WHERE company_id='${IN}'`));
  const d = await inv('2026-09-29', {exchangeRateMicros: 87_000_000, exchangeRateTyped: true});
  const e = await inv('2026-09-29', {exchangeRateMicros: 87_000_000, exchangeRateTyped: true, exchangeRateConfirmed: true});
  const got = id => sql(`SELECT CONCAT(exchange_rate_micros,'|',total_cents) FROM invoices WHERE id='${id}'`);
  const audit = Number(sql(`SELECT COUNT(*) FROM audit_log WHERE company_id='${IN}' AND action='currency.rate_override_confirmed' AND metadata_json LIKE '%87000000%'`));
  const want = [`88400000|${conv(100000, 88_400_000)}`, `88120000|${conv(100000, 88_120_000)}`, `87000000|${conv(100000, 87_000_000)}`];
  const have = [got(a.b.invoice?.id), got(b.b.invoice?.id), got(e.b.invoice?.id)];
  return {ok: a.s === 201 && b.s === 201 && c.s === 422 && c.b.code === 'fx_rate_missing' && after === before + 2 && d.s === 409 && d.b.code === 'fx_rate_differs' && e.s === 201
      && JSON.stringify(have) === JSON.stringify(want) && audit === 1,
    info: `29 Sep ${have[0]}; 28 Sep ${have[1]}; 10 Sep ${c.s} ${c.b.code}; typed ${d.s} ${d.b.code}, confirmed ${have[2]}; audit rows ${audit}; expected ${want.join(' / ')}`};
});

await t('FX-05', 'INR books: the invoice posts in INR (debits = credits); payment on 30 Sep at its rate posts the exchange gain of ₹120.00 to 6850', async () => {
  need('usd bank', await o.call('bank-accounts', {method: 'POST', json: {name: 'USD Account', currency: 'USD', accountType: 'bank', type: 'bank', ledgerCode: '1010', ledgerName: 'USD Account'}}));
  const inv = need('invoice', await o.call('invoices', {method: 'POST', json: {customerId: CUST, issueDate: '2026-09-29', dueDate: '2026-10-30', currency: 'USD', templateId: TMPL, issue: true, lines: [{description: 'Design', quantity: 1, unitPriceCents: 100000}]}}));
  const g0 = gl(IN);
  const pay = await o.call('payments', {method: 'POST', json: {type: 'customer', documentId: inv.invoice.id, paymentDate: '2026-09-30', reference: 'WIRE 1', foreignAmountCents: 100000, paymentAccountId: sql(`SELECT a.id FROM accounts a WHERE a.company_id='${IN}' AND a.code='1010'`), operationKey: 'r181-pay-' + tag}});
  const g1 = gl(IN);
  const delta = k => (g1[k] || 0) - (g0[k] || 0);
  const unbalanced = Number(sql(`SELECT COUNT(*) FROM (SELECT je.id FROM journal_entries je JOIN journal_lines jl ON jl.journal_entry_id=je.id WHERE je.company_id='${IN}' GROUP BY je.id HAVING SUM(jl.debit_cents)<>SUM(jl.credit_cents)) x`));
  const bank = conv(100000, 88_520_000), ar = conv(100000, 88_400_000);
  return {ok: pay.s === 201 && delta('1010') === bank && delta('6850') === -(bank - ar) && unbalanced === 0,
    info: `payment ${pay.s}; bank +${delta('1010')} (want ${bank}); 6850 ${delta('6850')} (want ${-(bank - ar)}); unbalanced entries ${unbalanced}`};
});

await t('FX-06', 'A USD bank statement converts each transaction at the rate recorded for its own date', async () => {
  const bankId = sql(`SELECT id FROM bank_accounts WHERE company_id='${IN}' AND currency='USD' LIMIT 1`);
  const csv = 'Date,Description,Amount\n2026-09-26,CLIENT WIRE A,500.00\n2026-09-29,CLIENT WIRE B,500.00\n2026-09-30,BANK FEE,-10.00\n';
  const data = Buffer.from(csv);
  const up = need('upload-start', await o.call('imports/upload-start', {method: 'POST', json: {filename: `r181-${tag}.csv`, size: data.length}}));
  need('chunk', await o.call('imports/upload-chunk', {method: 'POST', json: {uploadId: up.uploadId, offset: 0, data: data.toString('base64')}}));
  const fin = await o.call('imports/upload-finish', {method: 'POST', json: {uploadId: up.uploadId, bankAccountId: bankId, mode: 'preview'}});
  const rowsOut = (fin.b.preview?.rows || []).map(r => [r.date, r.foreignAmountCents, r.amountCents, r.exchangeRateMicros]);
  const want = [['2026-09-26', 50000, conv(50000, 88_120_000), 88_120_000], ['2026-09-29', 50000, conv(50000, 88_400_000), 88_400_000], ['2026-09-30', -1000, conv(-1000, 88_520_000), 88_520_000]];
  return {ok: [200, 201].includes(fin.s) && JSON.stringify(rowsOut) === JSON.stringify(want), info: `preview ${fin.s} ${fin.b.code || ''}; rows ${JSON.stringify(rowsOut)}; want ${JSON.stringify(want)}`};
});

await t('FX-07', 'The base currency cannot change after the first transaction', async () => {
  const r = await o.call('settings', {method: 'PUT', json: {...base, name: `R181 India ${tag}`, legalName: `R181 India ${tag}`, country: 'India', province: 'MH', currency: 'USD'}});
  return {ok: r.s === 409 && /fundamental|locked/i.test(String(r.b.code) + r.b.error), info: `${r.s} ${r.b.code}`};
});

await t('FX-08', 'A Canadian company is unaffected with the switch on: its rule stays "current" and a typed USD rate is used as typed, as in R180', async () => {
  o.cid = ids.BC;
  const pol = sql(`SELECT fx_rate_policy FROM companies WHERE id='${ids.BC}'`);
  const usd = sql(`SELECT COUNT(*) FROM company_currencies WHERE company_id='${ids.BC}' AND currency_code='USD' AND active=1`) === '1';
  let info = `policy ${pol}; USD active ${usd}`, ok = pol === 'current';
  if (usd) {
    const cust = sql(`SELECT id FROM customers WHERE company_id='${ids.BC}' AND active=1 LIMIT 1`);
    const tm = (await o.call('invoice-templates')).b.templates?.[0]?.id;
    const r = await o.call('invoices', {method: 'POST', json: {customerId: cust, issueDate: '2026-09-29', dueDate: '2026-10-30', currency: 'USD', templateId: tm, issue: false, lines: [{description: 'R181 check', quantity: 1, unitPriceCents: 10000}], exchangeRateMicros: 1_234_567}});
    const got = r.b.invoice?.id ? sql(`SELECT exchange_rate_micros FROM invoices WHERE id='${r.b.invoice.id}'`) : '';
    ok = ok && r.s === 201 && got === '1234567'; info += `; draft invoice ${r.s} rate ${got}`;
  }
  o.cid = IN;
  return {ok, info};
});

await t('FX-09', 'Another company\'s user cannot read or record this company\'s rates', async () => {
  const e = new S(); await e.login('editor@gate.test', 'Gate!Member#2026pw'); e.cid = IN;
  const r1 = await e.call('currencies/rates'), r2 = await e.call('currencies/rates', {method: 'POST', json: {rates: [{currency: 'USD', rateDate: '2026-09-25', rateToBaseMicros: 1}]}});
  const n = Number(sql(`SELECT COUNT(*) FROM company_exchange_rates WHERE company_id='${IN}' AND rate_date='2026-09-25'`));
  return {ok: r1.s >= 400 && r2.s >= 400 && n === 0, info: `read ${r1.s}; write ${r2.s}; rows written ${n}`};
});

await t('FX-10', 'Screens: India company form defaults to INR; rates screen shows the history; the invoice editor fills in the rate for its date; amounts show ₹', async () => {
  const out = {};
  {
    const {b, p, errs} = await open(ids.BC);
    await p.evaluate(() => TeghPortal.invokeMenuAction('My account', 'Add Company or Client File')); await p.waitForTimeout(2500);
    const form = p.locator('.srp-page').last().locator('form').first();
    await form.locator('[name=country]').selectOption({label: 'India'}); await p.waitForTimeout(1500);
    out.form = await form.locator('[data-r181-base]').evaluate(s => s.value);
    out.errs1 = errs.length; await b.close();
  }
  {
    const {b, p, errs} = await open(IN);
    await p.evaluate(() => TeghPortal.invokeMenuAction('Dashboard', 'Dashboard')); await p.waitForTimeout(6000);
    out.rupee = await p.evaluate(() => (document.body.innerText.match(/₹/g) || []).length);
    await p.evaluate(() => TeghPortal.invokeMenuAction('My account', 'Currency Exchange Rates')); await p.waitForTimeout(3000);
    out.history = await p.evaluate(() => [...document.querySelectorAll('.srp-page')].pop().querySelectorAll('[data-r181-history] tbody tr').length);
    await p.screenshot({path: '/srv/gate/ev/shots/r181-rates.png'});
    await p.evaluate(() => TeghPortal.openCustomerInvoice()); await p.waitForTimeout(3000);
    const f = p.locator('form:has([name=exchangeRate])').last();
    await f.locator('[name=partyId]').selectOption({index: 1});
    await f.locator('[name=date]').fill('2026-09-28'); await f.locator('[name=date]').dispatchEvent('change');
    await f.locator('[name=currency]').selectOption('USD'); await p.waitForTimeout(2500);
    out.rate28 = await f.evaluate(x => x.exchangeRate.value);
    await f.locator('[name=date]').fill('2026-09-10'); await f.locator('[name=date]').dispatchEvent('change'); await p.waitForTimeout(2500);
    out.rate10 = await f.evaluate(x => [x.exchangeRate.value, (x.querySelector('[data-r181-rate-note]')?.textContent || '').slice(0, 30)]);
    await p.screenshot({path: '/srv/gate/ev/shots/r181-invoice-rate.png'});
    out.errs2 = errs.length; await b.close();
  }
  return {ok: out.form === 'INR' && out.rupee > 0 && out.history >= 4 && out.rate28 === '88.120000' && out.rate10[0] === '' && /No USD rate/.test(out.rate10[1]) && !out.errs1 && !out.errs2, info: JSON.stringify(out)};
});

await t('FX-11', 'Switch off again: the India company keeps its INR books and its rates-by-date rule', async () => {
  need('off', await setMode('off'));
  o.cid = IN;
  const look = (await o.call('currencies/rate?currency=USD&date=2026-09-29')).b;
  const r = await o.call('invoices', {method: 'POST', json: {customerId: CUST, issueDate: '2026-09-10', dueDate: '2026-10-30', currency: 'USD', templateId: TMPL, issue: true, lines: [{description: 'After switch off', quantity: 1, unitPriceCents: 100}]}});
  return {ok: look.policy === 'dated' && look.rateToBaseMicros === 88_400_000 && r.s === 422 && r.b.code === 'fx_rate_missing', info: `policy ${look.policy} ${look.rateToBaseMicros}; undated invoice ${r.s} ${r.b.code}`};
});
} finally {
  await setMode(startMode);
}
save('r181.json');

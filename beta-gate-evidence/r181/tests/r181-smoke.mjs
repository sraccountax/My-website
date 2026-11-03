// R181 API smoke test (development): an INR company with USD/EUR documents at recorded rates.
import {S, sql, need} from '../lib.mjs';
const o = new S(); await o.login('owner@gate.test', 'Gate!Owner#2026pw');
const out = (k, v) => console.log(k.padEnd(28), typeof v === 'string' ? v : JSON.stringify(v));
const st = (await o.call('site-admin', {company: false})).b.groups.release;
const setMode = async m => { const cur = (await o.call('site-admin', {company: false})).b.groups.release; return o.call('site-admin/save', {method: 'POST', company: false, json: {groups: {release: {value: {...cur.value, worldCurrencies: m}, revision: cur.revision}}, reason: 'R181 smoke ' + m}}); };
out('switch owner', (await setMode('owner')).s);
const tag = Date.now().toString(36);
const base = {businessType: 'corporation', taxRegistered: false, accountingBasis: 'accrual', moduleMode: 'accounting', fiscalYearEnd: '03-31', booksStartDate: '2026-04-01', chartTemplate: 'default'};
let r = await o.call('companies', {method: 'POST', json: {...base, name: 'Smoke India ' + tag, country: 'India', province: 'MH'}});
out('create India (no currency)', [r.s, r.b.company?.currency || r.b.error]);
const IN = r.b.company?.id; o.cid = IN;
out('db policy/timezone', sql(`SELECT CONCAT(currency,' ',fx_rate_policy,' ',IFNULL(timezone,'-'),' ',fx_rate_max_age_days) FROM companies WHERE id='${IN}'`));
for (const [c, cur] of [['Japan', 'JPY'], ['Kuwait', 'KWD'], ['Indonesia', 'IDR'], ['Canada', 'INR']]) {
  r = await o.call('companies', {method: 'POST', json: {...base, name: 'Smoke ' + cur + tag, country: c, province: c === 'Canada' ? 'ON' : '', currency: cur}});
  out('create ' + c + ' ' + cur, [r.s, r.b.code, (r.b.error || '').slice(0, 90)]);
}
// rates
r = await o.call('currencies', {method: 'POST', json: {currency: 'USD', rateToBaseMicros: 88_120_000, rateDate: '2026-09-26'}});
out('USD rate 26 Sep (old route)', [r.s, r.b.currency?.rateDate || r.b.error]);
r = await o.call('currencies/rates', {method: 'POST', json: {rates: [{currency: 'USD', rateDate: '2026-09-29', rateToBaseMicros: 88_400_000}, {currency: 'EUR', rateDate: '2026-09-29', rateToBaseMicros: 103_250_000}, {currency: 'JPY', rateDate: '2026-09-29', rateToBaseMicros: 592_000}]}});
out('bulk USD/EUR/JPY 29 Sep', [r.s, r.b.saved ?? r.b.error]);
r = await o.call('currencies/rates', {method: 'POST', json: {rates: [{currency: 'KRW', rateDate: '2026-09-29', rateToBaseMicros: 4000}]}});
out('KRW rate 0.004 (precision)', [r.s, r.b.code]);
r = await o.call('currencies/rates', {method: 'POST', json: {rates: [{currency: 'KWD', rateDate: '2026-09-29', rateToBaseMicros: 290_000_000}]}});
out('KWD (3 decimals)', [r.s, r.b.code]);
r = await o.call('currencies/rates', {method: 'POST', json: {rates: [{currency: 'USD', rateDate: '2099-01-01', rateToBaseMicros: 88_000_000}]}});
out('future date', [r.s, r.b.code]);
out('rate lookup 30 Sep', (await o.call('currencies/rate?currency=USD&date=2026-09-30')).b);
out('rate lookup 27 Sep', (await o.call('currencies/rate?currency=USD&date=2026-09-27')).b);
out('rate lookup 10 Sep', (await o.call('currencies/rate?currency=USD&date=2026-09-10')).b);
// documents
const cust = need('customer', await o.call('customers', {method: 'POST', json: {name: 'Acme US ' + tag, email: '', phone: '', billingAddress: '1 Main St', country: 'United States', province: 'NY'}}));
const custId = cust.customer?.id || cust.id;
const tmpl = (await o.call('invoice-templates')).b.templates?.[0]?.id;
const inv = (date, extra = {}) => o.call('invoices', {method: 'POST', json: {customerId: custId, issueDate: date, dueDate: '2026-10-30', currency: 'USD', templateId: tmpl, issue: true, lines: [{description: 'Consulting', quantity: 1, unitPriceCents: 100000}], ...extra}});
for (const [d, ex, label] of [['2026-09-29', {}, 'no rate sent'], ['2026-09-30', {exchangeRateMicros: 88_400_000}, 'browser default'], ['2026-09-27', {}, 'falls back to 26 Sep'],
  ['2026-09-15', {}, 'no rate within 7 days'], ['2026-09-29', {exchangeRateMicros: 87_000_000, exchangeRateTyped: true}, 'typed, not confirmed'],
  ['2026-09-29', {exchangeRateMicros: 87_000_000, exchangeRateTyped: true, exchangeRateConfirmed: true}, 'typed and confirmed']]) {
  r = await inv(d, ex);
  const id = r.b.invoice?.id;
  const row = id ? sql(`SELECT CONCAT(exchange_rate_micros,' foreign ',foreign_total_cents,' base ',total_cents) FROM invoices WHERE id='${id}'`) : '';
  out('invoice ' + d + ' ' + label, [r.s, r.b.code || row, (r.b.error || '').slice(0, 140)]);
}
// base currency change after activity
r = await o.call('settings', {method: 'POST', json: {...base, name: 'Smoke India ' + tag, country: 'India', province: 'MH', currency: 'USD'}});
out('change base after invoices', [r.s, r.b.code]);
// Canadian company unaffected by policy
o.cid = sql(`SELECT id FROM companies WHERE country='Canada' AND archived_at IS NULL LIMIT 1`);
out('Canadian policy', sql(`SELECT fx_rate_policy FROM companies WHERE id='${o.cid}'`));
out('switch off', (await setMode('off')).s);

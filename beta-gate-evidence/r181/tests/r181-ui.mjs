// R181 UI smoke (development): company creation currency, rates screen, invoice rate prefill, ₹ on screens.
import {S, sql} from '../lib.mjs';
import {open} from '../e2e/base.mjs';
import fs from 'fs';
const ids = JSON.parse(fs.readFileSync('/srv/gate/t/ids.json'));
const o = new S(); await o.login('owner@gate.test', 'Gate!Owner#2026pw');
const setMode = async m => { const cur = (await o.call('site-admin', {company: false})).b.groups.release; return (await o.call('site-admin/save', {method: 'POST', company: false, json: {groups: {release: {value: {...cur.value, worldCurrencies: m}, revision: cur.revision}}, reason: 'R181 ui ' + m}})).s; };
const log = (k, v) => console.log(k.padEnd(30), typeof v === 'string' ? v : JSON.stringify(v));
log('switch owner', await setMode('owner'));
const IN = sql(`SELECT id FROM companies WHERE name LIKE 'Smoke India %' ORDER BY created_at DESC LIMIT 1`);
{
  const {b, p, errs} = await open(ids.BC);
  await p.evaluate(() => TeghPortal.invokeMenuAction('My account', 'Add Company or Client File')); await p.waitForTimeout(2500);
  const form = p.locator('.srp-page').last().locator('form').first();
  const r = {};
  r.canada = await form.locator('[data-r181-base]').evaluate(s => [s.value, s.options.length]);
  await form.locator('[name=country]').selectOption({label: 'India'}); await p.waitForTimeout(1500);
  r.india = await form.locator('[data-r181-base]').evaluate(s => [s.value, s.options.length, s.closest('label').querySelector('[data-r181-base-note]').textContent.slice(0, 60)]);
  await form.locator('[name=country]').selectOption({label: 'Japan'}); await p.waitForTimeout(800);
  r.japan = await form.locator('[data-r181-base]').evaluate(s => [s.value, s.closest('label').querySelector('[data-r181-base-note]').textContent.slice(0, 90)]);
  log('create form', r); log('page errors', errs);
  await b.close();
}
{
  const {b, p, errs} = await open(IN);
  await p.evaluate(() => TeghPortal.invokeMenuAction('My account', 'Currency Exchange Rates')); await p.waitForTimeout(3000);
  const t = await p.evaluate(() => { const pg = [...document.querySelectorAll('.srp-page')].pop(); return {intro: pg.querySelector('.r181-fx p')?.textContent.slice(0, 80), history: pg.querySelectorAll('[data-r181-history] tbody tr').length, subtitle: pg.textContent.includes('remains in INR')}; });
  log('rates screen', t);
  await p.locator('[data-r181-paste]').evaluate(f => f.closest('details').open = true);
  await p.fill('[data-r181-paste] textarea', '2026-09-30, USD, 88.52\n2026-09-30, EUR, 103.40');
  await p.click('[data-r181-paste] button'); await p.waitForTimeout(2500);
  log('after paste', await p.evaluate(() => [...document.querySelectorAll('.srp-page')].pop().querySelectorAll('[data-r181-history] tbody tr').length));
  await p.evaluate(() => TeghPortal.openCustomerInvoice()); await p.waitForTimeout(3000);
  const f = p.locator('form:has([name=exchangeRate])').last();
  if (await f.count()) {
    await f.locator('[name=partyId]').selectOption({index: 1}); await f.locator('[name=date]').fill('2026-09-30'); await f.locator('[name=date]').dispatchEvent('change');
    await f.locator('[name=currency]').selectOption('USD'); await p.waitForTimeout(2500);
    log('invoice prefill 30 Sep', await f.evaluate(x => [x.exchangeRate.value, x.querySelector('[data-r181-rate-note]')?.textContent]));
    await f.locator('[name=date]').fill('2026-09-27'); await f.locator('[name=date]').dispatchEvent('change'); await p.waitForTimeout(2500);
    log('invoice prefill 27 Sep', await f.evaluate(x => [x.exchangeRate.value, x.querySelector('[data-r181-rate-note]')?.textContent]));
    await f.locator('[name=date]').fill('2026-09-10'); await f.locator('[name=date]').dispatchEvent('change'); await p.waitForTimeout(2500);
    log('invoice prefill 10 Sep', await f.evaluate(x => [x.exchangeRate.value, x.querySelector('[data-r181-rate-note]')?.textContent]));
  } else log('invoice prefill', 'invoice form with exchangeRate not found');
  await p.evaluate(() => TeghPortal.invokeMenuAction('Dashboard', 'Dashboard')); await p.waitForTimeout(8000);
  log('dashboard currency', await p.evaluate(() => { const t = document.body.innerText; return {rupee: (t.match(/₹/g) || []).length, cadSign: (t.match(/CA\$/g) || []).length}; }));
  log('page errors', errs);
  await p.screenshot({path: '/srv/gate/ev/shots/r181-dev-dashboard.png'});
  await b.close();
}
log('switch off', await setMode('off'));

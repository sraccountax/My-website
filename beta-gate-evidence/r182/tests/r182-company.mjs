// R182 new company page (development): layout at three widths, hooks intact, no submit.
import {sql} from '../lib.mjs';
import {open} from '../e2e/base.mjs';
const cid = process.env.CID || sql("SELECT id FROM companies WHERE name LIKE 'Volume Northwind%' LIMIT 1");
const log = (k, v) => console.log(k.padEnd(26), typeof v === 'string' ? v : JSON.stringify(v));
for (const [w, h] of [[1440, 900], [768, 1000], [390, 844]]) {
  const {b, p, errs} = await open(cid, {w, h});
  await p.evaluate(() => TeghPortal.invokeMenuAction('My account', 'Add Company or Client File'));
  await p.waitForSelector('.r182-co-layout', {timeout: 20000}); await p.waitForTimeout(800);
  const info = await p.evaluate(() => {
    const f = document.querySelector('[data-create-company]');
    const sec = [...f.querySelectorAll('[data-r182-sec]')].map(s => s.dataset.r182Sec + ':' + [...s.querySelectorAll('[name]')].map(i => i.name).join(','));
    return {sec, hooks: ['[name=taxRegistered]', '[data-location-country]', '[name=moduleMode] option[value=payroll]', '[data-create-tax-title]'].map(s => !!f.querySelector(s)),
      provinceNext: f.querySelector('[data-location-country]').closest('label').nextElementSibling?.hasAttribute('data-location-province'),
      tz: f.querySelector('[name=timezone]').value, summary: [...document.querySelectorAll('[data-r182-summary] div')].map(d => d.textContent).join(' | '),
      hscroll: document.documentElement.scrollWidth > innerWidth, tutorial: !!document.querySelector('.tegh-tutorial, [data-tutorial-hub], .srp-modal')};
  });
  log(`${w} info`, info);
  if (w === 1440) {
    await p.selectOption('[name=province]', 'BC'); await p.waitForTimeout(300);
    log('tz after BC', await p.inputValue('[name=timezone]'));
    await p.click('[data-r182-basis=cash]'); log('basis', await p.inputValue('[name=accountingBasis]'));
    await p.selectOption('[name=timezone]', 'UTC'); await p.selectOption('[name=province]', 'AB'); await p.waitForTimeout(300);
    log('tz kept after pick', await p.inputValue('[name=timezone]'));
    await p.selectOption('[data-location-country]', {label: 'United States'}).catch(e => log('country err', e.message.slice(0, 80))); await p.waitForTimeout(500);
    log('US tax title', await p.textContent('[data-create-tax-title]'));
    log('US summary', await p.evaluate(() => [...document.querySelectorAll('[data-r182-summary] div')].map(d => d.textContent).join(' | ')));
  }
  await p.screenshot({path: `/srv/gate/ev/shots/r182-company-${w}.png`, fullPage: false});
  log('page errors', errs); await b.close();
}

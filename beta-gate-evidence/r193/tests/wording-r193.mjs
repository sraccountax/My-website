// R193: context of the other edition's wording that the sweep reported on a Canadian company (Tax Codes, Users)
// and on an India company. Prints each matching line of visible text. Read only.
// Usage: node wording-r193.mjs <CA|IN> <companyId> <fn,fn,...>
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';
const [,, ED = 'CA', CO, FNS = 'openTaxCodes,openUsers'] = process.argv; const IN = ED === 'IN';
const browser = await chromium.launch({executablePath: '/opt/pw-browsers/chromium', args: ['--no-proxy-server', '--host-resolver-rules=MAP gate.test 127.0.0.1']});
const ctx = await browser.newContext({viewport: {width: 1440, height: 900}, ignoreHTTPSErrors: true});
await ctx.addInitScript(id => { try { if (!sessionStorage.getItem('w-co')) { localStorage.setItem('sr-accountax-company', id); localStorage.setItem('sr-accountax-companies', JSON.stringify([id])); sessionStorage.setItem('w-co', '1'); } } catch {} }, CO);
const page = await ctx.newPage();
await page.route(/clarity\.ms|challenges\.cloudflare|googleapis|gstatic|bing\.com/, r => r.abort());
await page.goto('https://gate.test/app.html' + (IN ? '?edition=in' : ''));
await page.waitForSelector('#sr-login-form', {timeout: 30000});
if (IN) await page.selectOption('#sr-login-form [name=edition]', 'IN').catch(() => {});
await page.fill('#sr-login-form input[name=email]', 'owner@gate.test'); await page.fill('#sr-login-form input[name=password]', 'Gate!Owner#2026pw');
await page.click('#sr-login-form button[type=submit]');
await page.waitForFunction(() => window.TeghPortal && document.querySelector('.sidebar,.topbar'), null, {timeout: 60000}); await page.waitForTimeout(2500);
const RX = IN
  ? '\\b(HST|GST\\/HST|PST|QST|CRA|provinces?|chequing|Canada|Canadian|GIFI|T4A?|T5018|ROE|fiscal year|postal code|Business Number|CPP|EI premiums?)\\b'
  : '\\b(GSTIN|PAN|TDS|TCS|Udyam|GSTR-?\\d\\w*|CGST|SGST|IGST|UTGST|HSN|EPF|ESIC|lakh|crore|financial year|PIN code|India Masters|India Home)\\b';
for (const fn of FNS.split(',')) {
  if (fn.includes(':')) await page.evaluate(a => TeghPortal.invokeMenuAction('', a), fn); else await page.evaluate(f => TeghPortal[f](), fn);
  await page.waitForTimeout(3500);
  const lines = await page.evaluate(src => { const pg = [...document.querySelectorAll('.srp-page')].pop() || document.body; const re = new RegExp(src, 'i'); return (pg.innerText || '').split('\n').filter(l => re.test(l)).map(l => l.trim().slice(0, 260)).slice(0, 8); }, RX);
  console.log('==', fn); for (const l of lines) console.log('   ', l);
  if (process.env.PAGES) console.log('   pages:', JSON.stringify(await page.evaluate(src => { const re = new RegExp(src, 'i'); return [...document.querySelectorAll('.srp-page,.stage,main')].map(e => ({tag: e.tagName, cls: e.className.slice(0, 50), page: e.dataset.srpPage || '', shown: e.offsetParent !== null, hits: (e.innerText || '').split('\n').filter(l => re.test(l)).map(l => l.trim().slice(0, 200)).slice(0, 3)})); }, RX)));
  if (process.env.UNLABELLED) console.log('   unlabelled:', JSON.stringify(await page.evaluate(() => { const vis = el => el && el.offsetParent !== null; const pg = [...document.querySelectorAll('.srp-page')].pop() || document.body; return [...pg.querySelectorAll('input:not([type=hidden]):not([type=checkbox]):not([type=radio]),select,textarea')].filter(vis).filter(e => !(e.labels && e.labels.length) && !e.getAttribute('aria-label') && !e.getAttribute('aria-labelledby') && !e.title && !e.placeholder).map(e => ({tag: e.tagName, name: e.name, cls: e.className.slice(0, 40), row: (e.closest('tr,label,div')?.innerText || '').replace(/\s+/g, ' ').slice(0, 80), cell: e.closest('td') ? [...e.closest('table').querySelectorAll('th')].map(h => h.innerText).join('|').slice(0, 120) : ''})).slice(0, 4); })));
}
await browser.close();

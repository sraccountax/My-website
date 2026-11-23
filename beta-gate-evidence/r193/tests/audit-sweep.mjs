// R192 audit sweep: every menu screen, and the first New/Add/Create form on it, in one edition and company.
// Records page errors, console errors, failed API calls (HTTP >= 400 and network failures), page overflow,
// off-screen controls, "undefined"/NaN text, missing headings, error toasts, and the other edition's wording.
// Usage: node audit-sweep.mjs <CA|IN> <companyId> <width> <height> <tag> [forms=1]
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs'; import fs from 'fs';
const [,, ED = 'CA', CO, W = '1440', H = '900', tag = 'audit', FORMS = '1'] = process.argv;
// R193: bookkeeping mode (full|guided), theme (light|dark) and navigation (side|top), set through the Preferences page and the mode switch.
const MODE = process.env.MODE || 'full', THEME = process.env.THEME || 'light', NAV = process.env.NAV || 'side';
const vw = Number(W), vh = Number(H), mobile = vw < 800, IN = ED === 'IN';
const browser = await chromium.launch({executablePath: '/opt/pw-browsers/chromium', args: ['--no-proxy-server', '--host-resolver-rules=MAP gate.test 127.0.0.1']});
const ctx = await browser.newContext({viewport: {width: vw, height: vh}, isMobile: mobile, hasTouch: mobile, ignoreHTTPSErrors: true, acceptDownloads: true});
await ctx.addInitScript(id => { try { if (!sessionStorage.getItem('audit-co')) { localStorage.setItem('sr-accountax-company', id); localStorage.setItem('sr-accountax-companies', JSON.stringify([id])); sessionStorage.setItem('audit-co', '1'); } } catch {} }, CO);
const page = await ctx.newPage();
await page.route(/clarity\.ms|challenges\.cloudflare|googleapis|gstatic|bing\.com/, r => r.abort());
const ev = []; // events since the last reset
page.on('pageerror', e => ev.push('pageerror: ' + e.message.slice(0, 200)));
page.on('console', m => { if (m.type() === 'error' && !/Failed to load resource|net::ERR_FAILED|ERR_BLOCKED/.test(m.text())) ev.push('console: ' + m.text().slice(0, 200)); });
page.on('response', r => { const u = r.url(); if (r.status() >= 400 && /\/api\//.test(u)) ev.push('api ' + r.status() + ' ' + u.replace(/^https:\/\/gate\.test/, '').slice(0, 120)); });
page.on('requestfailed', r => { const u = r.url(); if (/gate\.test/.test(u) && !/abort/i.test(r.failure()?.errorText || '')) ev.push('reqfail ' + u.replace(/^https:\/\/gate\.test/, '').slice(0, 100) + ' ' + r.failure()?.errorText); });
page.on('dialog', d => { ev.push('DIALOG ' + d.message().slice(0, 80)); d.dismiss(); });

await page.goto('https://gate.test/app.html' + (IN ? '?edition=in' : ''));
await page.waitForSelector('#sr-login-form', {timeout: 30000});
if (IN) await page.selectOption('#sr-login-form [name=edition]', 'IN').catch(() => {});
await page.fill('#sr-login-form input[name=email]', 'owner@gate.test'); await page.fill('#sr-login-form input[name=password]', 'Gate!Owner#2026pw');
await page.click('#sr-login-form button[type=submit]');
await page.waitForFunction(() => window.TeghPortal && document.querySelector('.sidebar,.topbar'), null, {timeout: 60000}); await page.waitForTimeout(2500);
const setupErrs = [];
try {
  await page.evaluate(() => window.TeghPortal.openTeghPreferences()); await page.waitForSelector('[data-tegh-preferences]', {timeout: 20000}); await page.waitForTimeout(500);
  await page.locator(`[data-tegh-preferences] input[name=theme][value=${THEME}]`).check({force: true}); await page.locator(`[data-tegh-preferences] input[name=navigationLayout][value=${NAV}]`).check({force: true});
  await page.locator('[data-tegh-preferences] button[type=submit]').first().click(); await page.waitForTimeout(1500);
} catch (e) { setupErrs.push('preferences ' + String(e.message).slice(0, 120)); }
await page.evaluate(m => window.TeghPortal.switchBookkeepingMode?.(m), MODE === 'guided' ? 'owner' : 'accountant').catch(e => setupErrs.push('mode ' + String(e.message).slice(0, 80))); await page.waitForTimeout(1500);
await page.reload(); await page.waitForFunction(() => window.TeghPortal && document.querySelector('.sidebar,.topbar'), null, {timeout: 60000}); await page.waitForTimeout(2500);
const applied = await page.evaluate(() => ({mode: document.documentElement.dataset.bookkeepingMode, theme: document.documentElement.dataset.teghTheme, nav: document.documentElement.dataset.teghNavigationLayout}));
if (applied.mode !== (MODE === 'guided' ? 'guided' : 'full-accounting') || applied.theme !== THEME || applied.nav !== NAV) setupErrs.push('settings not applied ' + JSON.stringify(applied));
const state = await page.evaluate(() => ({mode: document.documentElement.dataset.bookkeepingMode, theme: document.documentElement.dataset.teghTheme, nav: document.documentElement.dataset.teghNavigationLayout, company: localStorage.getItem('sr-accountax-company'), name: document.querySelector('[data-company-name],.company-name,.srp-company-name,.srp-company-switch strong')?.textContent?.trim()}));

// Wording of the other edition. India screens must not show Canadian terms; Canadian screens must not show India terms.
const FOREIGN = IN
  ? /\b(HST|GST\/HST|PST|QST|CRA|provinces?|chequing|Canada|Canadian|GIFI|T4A?|T5018|ROE|fiscal year|postal code|Business Number|CPP|EI premiums?)\b/gi
  : /\b(GSTIN|PAN|TDS|TCS|Udyam|GSTR-?\d\w*|CGST|SGST|IGST|UTGST|HSN|EPF|ESIC|lakh|crore|financial year|PIN code|India Masters|India Home)\b/g;
const measure = async scope => page.evaluate(([scope, src, flags]) => {
  const de = document.documentElement; const vis = el => el && el.offsetParent !== null && getComputedStyle(el).visibility !== 'hidden';
  const root = scope === 'dialog' ? ([...document.querySelectorAll('dialog[open],[role=dialog],.srp-modal,.srp-drawer')].filter(vis).pop() || document.body) : (document.querySelector('.srp-page,.stage,main') || document.body);
  const txt = root.innerText || '';
  const bad = (txt.match(/\bundefined\b|\bNaN\b|\[object Object\]|\bInfinity\b|\bnull\b(?! *(?:island|value))/g) || []).slice(0, 4);
  const foreign = [...new Set((txt.match(new RegExp(src, flags)) || []).map(x => x.toLowerCase()))].slice(0, 8);
  const over = [...root.querySelectorAll('button,input,select,textarea,h1,h2,label,.srp-card')].filter(e => { if (!vis(e)) return false; const r = e.getBoundingClientRect(); if (r.width === 0) return false; let p = e.parentElement; while (p) { const s = getComputedStyle(p); if (/(auto|scroll|hidden)/.test(s.overflowX) && p !== document.body && p !== de) return false; p = p.parentElement; } return r.right > innerWidth + 2 || r.left < -2; }).map(e => (e.tagName + ':' + (e.textContent || e.name || '').trim().slice(0, 24)));
  const h1 = [...document.querySelectorAll('h1,h2')].find(vis);
  const toast = [...document.querySelectorAll('.srp-toast.error,[data-toast-type=error],.srp-toast-error,.srp-alert-error,.error-banner')].filter(vis).map(t => t.textContent.trim().slice(0, 100));
  // Unlabelled form controls (no label, aria-label, title or placeholder).
  const unlabelled = [...root.querySelectorAll('input:not([type=hidden]):not([type=checkbox]):not([type=radio]),select,textarea')].filter(vis).filter(e => !(e.labels && e.labels.length) && !e.getAttribute('aria-label') && !e.getAttribute('aria-labelledby') && !e.title && !e.placeholder).length;
  // Text clipped inside buttons (scrollWidth > clientWidth with overflow hidden).
  const clipped = [...root.querySelectorAll('button,.srp-btn,th')].filter(vis).filter(b => !b.closest('nav,aside,.sidebar,.srp-sidebar,[data-srp-nav]')).filter(b => b.scrollWidth > b.clientWidth + 3 && getComputedStyle(b).overflow !== 'visible' && b.textContent.trim().length > 2).map(b => b.textContent.trim().slice(0, 24)).slice(0, 4);
  // R193: text contrast against its effective background (WCAG ratio below 3 is flagged), and large light panels in the dark theme.
  const rgb = c => { const m = (c || '').match(/rgba?\(([^)]+)\)/); if (!m) return null; const v = m[1].split(',').map(x => parseFloat(x)); return {r: v[0], g: v[1], b: v[2], a: v.length > 3 ? v[3] : 1}; };
  const lum = c => { const f = x => { x /= 255; return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4); }; return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b); };
  const bgOf = el => { let e = el; while (e) { const c = rgb(getComputedStyle(e).backgroundColor); if (c && c.a > 0.5 && !/gradient|url/.test(getComputedStyle(e).backgroundImage)) return c; if (e !== el && /gradient|url/.test(getComputedStyle(e).backgroundImage)) return null; e = e.parentElement; } return rgb(getComputedStyle(document.body).backgroundColor); };
  const lowContrast = [];
  for (const el of root.querySelectorAll('h1,h2,h3,h4,p,span,label,td,th,a,button,li,small,strong,legend,dt,dd')) {
    if (lowContrast.length >= 4) break; if (!vis(el)) continue;
    const own = [...el.childNodes].some(n => n.nodeType === 3 && n.nodeValue.trim().length > 1); if (!own) continue;
    const cs = getComputedStyle(el); if (parseFloat(cs.opacity) < 0.9 || el.closest('[disabled],[aria-disabled=true],.disabled,[hidden]')) continue;
    const fg = rgb(cs.color), bg = bgOf(el); if (!fg || !bg) continue;
    const L1 = lum(fg), L2 = lum(bg), ratio = (Math.max(L1, L2) + 0.05) / (Math.min(L1, L2) + 0.05);
    if (ratio < 3) lowContrast.push(el.textContent.trim().slice(0, 24) + ' ' + ratio.toFixed(1));
  }
  const dark = document.documentElement.dataset.teghTheme === 'dark'; const lightPanels = [];
  if (dark) for (const el of root.querySelectorAll('div,section,article,table,form,aside,header,dialog')) {
    if (lightPanels.length >= 4) break; if (!vis(el)) continue; const r = el.getBoundingClientRect(); if (r.width < 200 || r.height < 80) continue;
    const c = rgb(getComputedStyle(el).backgroundColor); if (c && c.a > 0.5 && lum(c) > 0.75) lightPanels.push((el.className || el.tagName).toString().split(' ')[0].slice(0, 30));
  }
  return {h1: h1?.textContent?.trim().slice(0, 60) || '', sw: de.scrollWidth, cw: de.clientWidth, bad, foreign, over: over.slice(0, 5), toast, unlabelled, clipped, lowContrast, lightPanels};
}, [scope, FOREIGN.source, FOREIGN.flags]);
const issuesOf = m => { const i = []; if (m.sw > m.cw + 2) i.push('page-hscroll ' + m.sw + '>' + m.cw); if (m.over.length) i.push('off-screen ' + m.over.join(',')); if (m.bad.length) i.push('text ' + m.bad.join(',')); if (!m.h1) i.push('no heading');
  if (m.toast.length) i.push('error-toast ' + m.toast.join(';')); if (m.foreign.length) i.push('foreign-wording ' + m.foreign.join(',')); if (m.clipped.length) i.push('clipped ' + m.clipped.join(',')); if (m.unlabelled) i.push(m.unlabelled + ' unlabelled'); if (m.lowContrast?.length) i.push('low-contrast ' + m.lowContrast.join(',')); if (m.lightPanels?.length) i.push('light-panel ' + m.lightPanels.join(',')); return i; };

const acts = await page.evaluate(() => [...new Map([...document.querySelectorAll('[data-srp-action-id]')].map(b => [b.dataset.srpActionId, b.textContent.trim()])).entries()]);
// Pages outside the sidebar (settings, tools, India pages) and every report this user can open.
const PAGES = ['openDashboard','openReportsDashboard','openMonthEndClose','openAccount','openCompanySetup','openCompanyDetails','openBookkeepingModeSettings','openSiteReleaseSettings','openTeghPreferences','openWorkspacePreferences',
  'openTaxCodes','openTaxCodeReport','openDashboardFigures','openBackup','openUsers','openRates','openSupport','openFaq','openIntegrity','openAuditHistory','openOpeningBalances','openDataImport','openGifiReport','openGifiMapping',
  'openInvoiceTemplates','openVoidRegister','openImportedTransactions','openInsights','openBankTransfers','openExpenseVouchers','openManualJournals','openAdvancedReconciliation','openFlexibleCashForecast','openInventoryReport',
  'openRequestedDownloads','openModulesAccess','openCommandCentre','openAgentCenter','openConnectAccountant','openClientViewLinks','openAccountingNotes','openFinancialAnalyst',
  ...(IN ? ['openIndiaMasters','openGstSummary','openGstReturns','openIndiaPayroll','openIndiaTds','openIndiaHome','openIndiaHelp'] : [])];
for (const f of PAGES) acts.push(['fn:' + f, f]);
for (const r of await page.evaluate(() => window.TeghPortal.listReports?.() || [])) acts.push(['report:' + r, r]);
const out = {tag, edition: ED, company: CO, W, H, MODE, THEME, NAV, state, setupErrs, screens: []};
for (const [id, label] of acts) {
  ev.length = 0;
  await page.evaluate(id => id.startsWith('fn:') ? TeghPortal[id.slice(3)]?.() : id.startsWith('report:') ? TeghPortal.openReport(id.slice(7)) : TeghPortal.invokeMenuAction('', id), id).catch(e => ev.push('invoke ' + e.message.slice(0, 80)));
  await page.waitForTimeout(2200);
  const m = await measure('page'); const s = {id, label, h1: m.h1, issues: [...issuesOf(m), ...ev]};
  if (FORMS === '1') {
    // Open the first New/Add/Create button of the page (never Save, Post, Delete or a submit button).
    const opened = await page.evaluate(() => {
      const vis = el => el && el.offsetParent !== null; const main = document.querySelector('.srp-page,.stage,main') || document.body;
      const b = [...main.querySelectorAll('button,a.srp-btn,[role=button]')].filter(vis).find(x => /^\+?\s*(new|add|create)\b/i.test(x.textContent.trim()) && x.type !== 'submit' && !x.closest('form') && !/delete|post|save|import|upload/i.test(x.textContent));
      if (!b) return ''; b.click(); return b.textContent.trim().slice(0, 40);
    }).catch(() => '');
    if (opened) {
      ev.length = 0; await page.waitForTimeout(1800);
      const f = await measure('dialog'); s.form = {opened, h1: f.h1, issues: [...issuesOf(f).filter(x => x !== 'no heading'), ...ev]};
      if (s.form.issues.length) await page.screenshot({path: `/srv/gate/ev/shots/${tag}-${id.replace(/[^a-z0-9]+/gi, '_')}-form.png`});
      await page.keyboard.press('Escape').catch(() => {}); await page.waitForTimeout(400);
      await page.evaluate(() => { const c = [...document.querySelectorAll('dialog[open] button,[role=dialog] button,.srp-modal button')].find(b => /^(cancel|close|×)$/i.test(b.textContent.trim())); c?.click(); }).catch(() => {});
    }
  }
  if (s.issues.length) await page.screenshot({path: `/srv/gate/ev/shots/${tag}-${id.replace(/[^a-z0-9]+/gi, '_')}.png`});
  out.screens.push(s);
  await page.keyboard.press('Escape').catch(() => {}); await page.evaluate(() => document.querySelectorAll('.srp-modal-scrim,.tegh-action-dialog-scrim').forEach(x => x.querySelector('[data-tegh-dialog-cancel],button[aria-label=Close]')?.click())).catch(() => {});
}
fs.writeFileSync(`/srv/gate/ev/audit-${tag}.json`, JSON.stringify(out, null, 1));
const bad = out.screens.filter(s => s.issues.length || s.form?.issues.length);
if (setupErrs.length) console.log('SETUP', setupErrs.join(' | '));
console.log(tag, ED, MODE, THEME, NAV, 'screens', out.screens.length, 'forms', out.screens.filter(s => s.form).length, 'with issues', bad.length, JSON.stringify(state));
for (const s of bad) { if (s.issues.length) console.log('  ', s.id.padEnd(34), s.issues.join(' | ').slice(0, 300)); if (s.form?.issues.length) console.log('  ', (s.id + ' [' + s.form.opened + ']').padEnd(34), s.form.issues.join(' | ').slice(0, 300)); }
await browser.close();

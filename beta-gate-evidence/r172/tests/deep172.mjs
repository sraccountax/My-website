// R172 deep sweep: every screen reachable from the menus, reports, settings and portal entry points, opened the way a
// person would, then explored (tabs, filters, expandable sections, the "New / Create / Add" form opened but not saved).
// Records browser errors, console errors, failed API calls, slow screens and layout faults.
// Usage: node deep172.mjs <width> <height> <theme light|dark> <nav side|top> <mode full|guided> <role owner|editor|viewer> <tag> [companyKey]
import {chromium} from '/opt/node22/lib/node_modules/playwright/index.mjs';
import fs from 'fs';
const [,, W = '1440', H = '900', theme = 'light', nav = 'side', mode = 'full', role = 'owner', tag = 'x', coKey = 'BC'] = process.argv;
const users = {owner: ['owner@gate.test', 'Gate!Owner#2026pw'], editor: ['editor@gate.test', 'Gate!Member#2026pw'], viewer: ['viewer@gate.test', 'Gate!Member#2026pw'], admin: ['admin@gate.test', 'Gate!Member#2026pw']};
const IDS = JSON.parse(fs.readFileSync('/srv/gate/t/ids.json'));
const CID = IDS[coKey];
const vw = Number(W), vh = Number(H), mobile = vw < 800;
const browser = await chromium.launch({executablePath: '/opt/pw-browsers/chromium', args: ['--no-proxy-server', '--host-resolver-rules=MAP gate.test 127.0.0.1']});
const ctx = await browser.newContext({viewport: {width: vw, height: vh}, isMobile: mobile, hasTouch: mobile, ignoreHTTPSErrors: true, acceptDownloads: true});
const shift = Number(process.env.GATE_CLOCK_SHIFT_SECONDS || 0) * 1000; if (shift > 0) { await ctx.clock.install({time: Date.now() - shift}); await ctx.clock.resume(); }
await ctx.addInitScript(id => { try { if (!sessionStorage.getItem('gate-co')) { localStorage.setItem('sr-accountax-company', id); localStorage.setItem('sr-accountax-companies', JSON.stringify([id])); sessionStorage.setItem('gate-co', '1'); } } catch {} }, CID);
const p = await ctx.newPage();
await p.route(/clarity\.ms|challenges\.cloudflare|googleapis|gstatic|bing\.com/, r => r.abort());
let errs = [], cons = [], http = [], inflight = 0;
p.on('pageerror', e => errs.push(e.message.slice(0, 180)));
p.on('console', m => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) cons.push(m.text().slice(0, 180)); });
p.on('dialog', d => { errs.push('DIALOG ' + d.message().slice(0, 80)); d.dismiss().catch(() => {}); });
p.on('request', () => inflight++); p.on('requestfinished', () => inflight--); p.on('requestfailed', r => { inflight--; if (!/abort/i.test(r.failure()?.errorText || '')) http.push('FAILED ' + r.url().replace(/^https:\/\/gate\.test/, '').split('?')[0]); });
p.on('response', r => { const s = r.status(); if (s >= 400 && /gate\.test/.test(r.url())) http.push(s + ' ' + r.request().method() + ' ' + r.url().replace(/^https:\/\/gate\.test/, '').split('?')[0] + ' ' + ((/[?&]route=([^&]+)/.exec(r.url()) || [])[1] || '')); });
const idle = async (max = 15000) => { const t0 = Date.now(); let quiet = 0; while (Date.now() - t0 < max) { await p.waitForTimeout(150); quiet = inflight <= 0 ? quiet + 150 : 0; if (quiet >= 600) break; } return Date.now() - t0; };
await p.goto('https://gate.test/app.html');
await p.fill('#sr-login-form input[name=email]', users[role][0]); await p.fill('#sr-login-form input[name=password]', users[role][1]); await p.click('#sr-login-form button[type=submit]');
await p.waitForFunction(() => window.TeghPortal && document.querySelector('.sidebar,.topbar'), null, {timeout: 60000}); await idle();
// Appearance and mode, the way a person sets them.
await p.evaluate(() => window.TeghPortal.openTeghPreferences()); await p.waitForSelector('[data-tegh-preferences]', {timeout: 30000});
await p.locator(`[data-tegh-preferences] input[name=theme][value=${theme}]`).check({force: true});
await p.locator(`[data-tegh-preferences] input[name=navigationLayout][value=${nav}]`).check({force: true});
await p.locator('[data-tegh-preferences] button[type=submit]').first().click(); await idle();
await p.evaluate(m => window.TeghPortal.switchBookkeepingMode(m), mode === 'guided' ? 'owner' : 'accountant').catch(() => {}); await idle();
const applied = await p.evaluate(() => ({theme: document.documentElement.dataset.teghTheme, nav: document.documentElement.dataset.teghNavigationLayout, mode: document.documentElement.dataset.bookkeepingMode}));

// What to visit
const targets = [];
const acts = await p.evaluate(() => [...new Set([...document.querySelectorAll('[data-srp-action-id]')].map(b => b.dataset.srpActionId + '|' + b.textContent.trim().replace(/\|/g, '')))]);
for (const a of acts) { const [id, label] = a.split('|'); targets.push({kind: 'menu', key: id || 'label:' + label, label}); }
const reportNames = JSON.parse(fs.readFileSync('/srv/gate/t/deep172-reports.json'));
for (const n of reportNames) targets.push({kind: 'report', key: 'report:' + n, label: n});
const entry = ['openDashboard', 'openInsights', 'openTeghPreferences', 'openWorkspacePreferences', 'openRequestedDownloads', 'openAgentCenter', 'openCommandCentre', 'openTaxCodes', 'openTaxCodeReport', 'openDashboardFigures', 'openBackup', 'openUsers', 'openAuditHistory', 'openOpeningBalances', 'openDataImport', 'openGifiMapping', 'openInvoiceTemplates', 'openClientViewLinks', 'openMonthEndClose', 'openBookkeepingWorkspace', 'openAdvancedDashboard', 'openSupport', 'openFaq', 'openCompanySetup', 'openModulesAccess', 'openFinancialAccounts', 'openBankImports', 'openVoidRegister', 'openAccount', 'openRates', 'openIntegrity', 'openReports', 'openGuidedBookkeeping', 'openGuidedTransactionQueue', 'openGuidedMonthEnd', 'openFinancialAnalyst', 'openFlexibleCashForecast', 'openInvoiceIntelligence', 'openNativeAgentSettings', 'openBookkeepingModeSettings'];
for (const f of entry) targets.push({kind: 'entry', key: 'fn:' + f, label: f});
const open = async t => {
  if (t.kind === 'menu') return p.evaluate(([id, label]) => { if (!id.startsWith('label:')) return TeghPortal.invokeMenuAction('', id); const b = [...document.querySelectorAll('[data-srp-action-id]')].find(x => x.textContent.trim() === label); b?.click(); }, [t.key, t.label]);
  if (t.kind === 'report') return p.evaluate(n => TeghPortal.openReport(n), t.label);
  if (t.kind === 'entry') return p.evaluate(f => typeof TeghPortal[f] === 'function' ? TeghPortal[f]() : 'missing', t.label);
  if (t.kind === 'setting') { await p.evaluate(() => TeghPortal.openAccount()); await idle(); return p.evaluate(label => { const b = [...document.querySelectorAll('[data-settings-item]')].find(x => (x.dataset.settingsSearchText || x.textContent).includes(label)); b?.click(); return !!b; }, t.label); }
};
const inspect = () => p.evaluate(() => {
  const de = document.documentElement, vis = el => !!el && el.offsetParent !== null && el.getBoundingClientRect().width > 0;
  const page = [...document.querySelectorAll('.srp-page')].pop();
  const modal = [...document.querySelectorAll('.srp-modal-scrim,[role=dialog][aria-modal=true]')].find(vis);
  const scope = modal || page || document.body;
  const h = [...scope.querySelectorAll('h1,h2')].find(vis);
  const txt = (scope.innerText || '');
  const bad = (txt.match(/\bundefined\b|\bNaN\b|\[object Object\]|Infinity|null null/g) || []).slice(0, 3);
  const loading = /Loading…|Loading\.\.\./.test(txt) ? (txt.match(/[^\n]*Loading[^\n]*/) || [''])[0].slice(0, 60) : '';
  const scroller = el => { let q = el.parentElement; while (q && q !== document.body && q !== de) { const s = getComputedStyle(q); if (/(auto|scroll|hidden|clip)/.test(s.overflowX)) return true; q = q.parentElement; } return false; };
  const ctrls = [...scope.querySelectorAll('button,a[href],input:not([type=hidden]),select,textarea')].filter(vis);
  const off = ctrls.filter(e => { const r = e.getBoundingClientRect(); return (r.right > innerWidth + 2 || r.left < -2) && !scroller(e); }).map(e => (e.textContent || e.name || e.tagName).trim().slice(0, 24));
  // Controls lying on top of each other (not nested), from what is actually drawn at their centre.
  // Sticky bars, fixed panels and pop-up menus are meant to sit over content; only in-flow controls on top of each other count.
  const layered = el => { for (let q = el; q && q !== document.body; q = q.parentElement) { const s = getComputedStyle(q); if (s.position === 'fixed' || s.position === 'sticky' || (s.position === 'absolute' && (parseInt(s.zIndex) > 0 || q.matches('[role=menu],[role=listbox],details[open] > *:not(summary),.srp-menu,[class*=menu],[class*=popover],[class*=dropdown]')))) return true; } return false; };
  const overlap = [];
  for (const e of ctrls.slice(0, 250)) { const r = e.getBoundingClientRect(); if (r.width < 8 || r.height < 8 || r.bottom < 0 || r.top > innerHeight) continue; const top = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2); if (top && top !== e && !e.contains(top) && !top.contains(e) && !top.closest('.tf-root,.srp-toast,.sr-ui-toast,[role=tooltip]') && top.closest('button,a,input,select,textarea,label') && !layered(top) && !layered(e)) { const t = top.closest('button,a,input,select,textarea,label,header,nav,aside') || top; if (!t.contains(e) && !e.contains(t)) overlap.push((e.textContent || e.name || e.tagName).trim().slice(0, 20) + ' ⟂ ' + (t.textContent || t.className || t.tagName).toString().trim().slice(0, 20)); } }
  const clipped = [...scope.querySelectorAll('button')].filter(vis).filter(b => b.scrollWidth > b.clientWidth + 3 && getComputedStyle(b).textOverflow !== 'ellipsis' && getComputedStyle(b).overflow !== 'visible').map(b => b.textContent.trim().slice(0, 24));
  // Text fields too narrow to use (a label on top, a box under 120px wide).
  const narrow = [...scope.querySelectorAll('input[type=text],input[type=email],input[type=search],input[type=password],input:not([type]),textarea')].filter(vis).filter(i => { const r = i.getBoundingClientRect(); return r.width < 120 && !i.closest('table,[role=grid],td'); }).map(i => (i.labels?.[0]?.textContent || i.placeholder || i.name || 'field').trim().slice(0, 24) + ' ' + Math.round(i.getBoundingClientRect().width) + 'px');
  const imgs = [...scope.querySelectorAll('img')].filter(i => i.complete && i.naturalWidth === 0 && vis(i)).length;
  const toast = [...document.querySelectorAll('.srp-toast,.sr-ui-toast')].filter(vis).map(t => t.textContent.trim().slice(0, 100)).filter(t => /error|could not|failed|unavailable|not found|denied|refused/i.test(t));
  return {page: page?.dataset.srpPage || '', modal: !!modal, heading: h?.textContent.trim().slice(0, 50) || '', hscroll: de.scrollWidth - de.clientWidth, bad, loading, off: off.slice(0, 5), overlap: [...new Set(overlap)].slice(0, 5), narrow: narrow.slice(0, 4), clipped: clipped.slice(0, 5), imgs, toast, xss: window.__xss || 0};
});
const problems = (m, ms) => {
  const out = [];
  if (m.hscroll > 2) out.push('page sideways scroll ' + m.hscroll + 'px');
  if (m.off.length) out.push('off-screen: ' + m.off.join(', '));
  if (m.overlap.length) out.push('overlap: ' + m.overlap.join('; '));
  if (m.clipped.length) out.push('clipped: ' + m.clipped.join(', '));
  if (m.narrow?.length) out.push('narrow field: ' + m.narrow.join(', '));
  if (m.bad.length) out.push('text: ' + m.bad.join(','));
  if (m.loading) out.push('still loading: ' + m.loading);
  if (m.imgs) out.push(m.imgs + ' broken images');
  if (m.toast.length) out.push('error toast: ' + m.toast.join(' / '));
  if (m.xss) out.push('SCRIPT FROM DATA RAN');
  if (!m.heading) out.push('no heading');
  if (ms > 6000) out.push('slow ' + (ms / 1000).toFixed(1) + 's');
  if (errs.length) out.push('js: ' + [...new Set(errs)].join(' ; '));
  if (cons.length) out.push('console: ' + [...new Set(cons)].slice(0, 3).join(' ; '));
  if (http.length) out.push('http: ' + [...new Set(http)].slice(0, 6).join(' ; '));
  return out;
};
const reset = () => { errs = []; cons = []; http = []; };
const results = [];
const seenPages = new Set();
// Settings entries are listed from the hub once.
await p.evaluate(() => TeghPortal.openAccount()); await idle();
const settingLabels = await p.evaluate(() => [...document.querySelectorAll('[data-settings-item]')].map(b => (b.querySelector('strong,b,span')?.textContent || b.textContent).trim().slice(0, 60)).filter(Boolean));
for (const s of [...new Set(settingLabels)]) targets.push({kind: 'setting', key: 'setting:' + s, label: s});
const safeWord = /^(new|create|add)\b/i, danger = /delete|remove|void|reverse|post\b|issue|send|approve|archive|restore|reset|lock|sign out|log out|pay\b|finalize|submit|save|import|upload|close period|transfer ownership|revoke|disable|deactivate/i;
for (const t of targets) {
  reset();
  const t0 = Date.now();
  let r;
  try { r = await open(t); } catch (e) { errs.push('open: ' + e.message.slice(0, 120)); }
  const ms = await idle() + 0;
  await p.waitForTimeout(400);
  const m = await inspect();
  const issues = r === 'missing' ? ['entry point not exported'] : problems(m, Date.now() - t0);
  const row = {target: t.key, label: t.label, page: m.page, heading: m.heading, ms: Date.now() - t0, issues, explored: []};
  // Explore inside the screen once per distinct page: tabs, filters, sections, and the New/Create form.
  if (m.page && !seenPages.has(m.page + '|' + m.heading)) {
    seenPages.add(m.page + '|' + m.heading);
    const n = await p.evaluate(() => { const page = [...document.querySelectorAll('.srp-page')].pop(); if (!page) return 0; const vis = el => el.offsetParent !== null; document.querySelectorAll('[data-deep-idx]').forEach(e => e.removeAttribute('data-deep-idx')); const els = [...page.querySelectorAll('[role=tab],.srp-segmented button,[data-tab],[data-filter],[data-status-filter],details:not([open])>summary')].filter(vis); els.forEach((e, i) => e.dataset.deepIdx = String(i)); return els.length; });
    for (let i = 0; i < Math.min(n, 12); i++) {
      reset();
      const lab = await p.evaluate(i => { const e = document.querySelector(`[data-deep-idx="${i}"]`); if (!e || e.offsetParent === null) return null; const l = e.textContent.trim().slice(0, 30); if (/delete|void|remove|reverse|sign out/i.test(l)) return null; e.click(); return l; }, i).catch(() => null);
      if (lab === null) continue;
      await idle(8000); const mm = await inspect(); const iss = problems(mm, 0).filter(x => x !== 'no heading');
      if (iss.length) row.explored.push({control: lab, issues: iss});
    }
    // The "New / Create / Add" form: opened and checked, never saved.
    const nb = await p.evaluate(([safeSrc, dangerSrc]) => { const safe = new RegExp(safeSrc, 'i'), danger = new RegExp(dangerSrc, 'i'); document.querySelectorAll('[data-deep-new]').forEach(e => e.removeAttribute('data-deep-new')); const page = [...document.querySelectorAll('.srp-page')].pop(); if (!page) return null; const b = [...page.querySelectorAll('button')].find(x => x.offsetParent !== null && safe.test(x.textContent.trim()) && !danger.test(x.textContent)); if (!b) return null; b.dataset.deepNew = '1'; return b.textContent.trim().slice(0, 30); }, [safeWord.source, danger.source]);
    if (nb) {
      reset();
      await p.evaluate(() => document.querySelector('[data-deep-new="1"]')?.click()).catch(e => errs.push('click new: ' + e.message.slice(0, 80)));
      await idle(8000); await p.waitForTimeout(300);
      const fm = await inspect(); const iss = problems(fm, 0).filter(x => x !== 'no heading');
      const hasForm = await p.evaluate(() => !![...document.querySelectorAll('form,[role=dialog]')].find(f => f.offsetParent !== null && f.querySelector('input,select,textarea')));
      if (!hasForm) iss.push('no form opened');
      if (iss.length) row.explored.push({control: 'form: ' + nb, issues: iss});
      if (mobile || process.env.SHOTS) await p.screenshot({path: `/srv/gate/ev/shots/deep-${tag}-${t.key.replace(/[^a-z0-9]+/gi, '_')}-form.png`});
      await p.keyboard.press('Escape').catch(() => {});
    }
  }
  if (row.issues.length || row.explored.length || process.env.SHOTS) await p.screenshot({path: `/srv/gate/ev/shots/deep-${tag}-${t.key.replace(/[^a-z0-9]+/gi, '_')}.png`});
  results.push(row);
  fs.writeFileSync(`/srv/gate/ev/deep-${tag}.json`, JSON.stringify({tag, W, H, theme, nav, mode, role, company: coKey, applied, results}, null, 1));
}
const bad = results.filter(r => r.issues.length || r.explored.length);
console.log(`${tag}: ${results.length} screens, ${bad.length} with findings; applied ${JSON.stringify(applied)}`);
for (const r of bad) console.log(' ', r.target.padEnd(44), [...r.issues, ...r.explored.map(e => `[${e.control}] ${e.issues.join(' | ')}`)].join(' || ').slice(0, 400));
await browser.close();

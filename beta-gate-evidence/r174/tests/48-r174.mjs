// R174: Site & Release Settings (platform owner). API: permissions, validation, revisions, reasons, history and audit;
// each setting's effect (notice, version label, Beta labels, business details, public wording, sign-up, policies and
// the Terms/Privacy versions people accept); the server emergency stop; text from the owner treated as text.
// UI: the settings page from Platform Owner Home, "Apply production settings", the published policy page and the
// public wording in a browser, desktop and phone. Every setting is put back at the end.
import {S, sql, rec, save, need, mails} from './lib.mjs';
import {open} from './e2e/base.mjs';
import {chromium} from '/opt/node22/lib/node_modules/playwright/index.mjs';
import fs from 'fs';

const A = 'R174';
const ids = JSON.parse(fs.readFileSync('/srv/gate/t/ids.json'));
const stamp = Date.now().toString(36);
const t = async (id, title, fn) => { try { const r = await fn(); rec(id, A, title, r.ok ? 'PASS' : 'FAIL', r.info); return r.ok; } catch (e) { rec(id, A, title, 'FAIL', String(e.message || e).slice(0, 400)); return false; } };
const o = new S(); await o.login('owner@gate.test', 'Gate!Owner#2026pw'); o.cid = ids.BC;
const ed = new S(); await ed.login('editor@gate.test', 'Gate!Member#2026pw'); ed.cid = ids.BC;
const anon = new S();
let st = need('state', await o.call('site-admin', {company: false}));
const save1 = async (groups, reason = 'R174 gate check ' + stamp) => {
  const payload = {}; for (const [g, value] of Object.entries(groups)) payload[g] = {value, revision: st.groups[g].revision};
  const r = await o.call('site-admin/save', {method: 'POST', company: false, json: {groups: payload, reason}});
  if (r.s === 200) st = r.b; return r;
};
const me = async () => (await o.call('auth/me', {company: false})).b;
const audit = action => Number(sql(`SELECT COUNT(*) FROM platform_audit_log WHERE action='${action}'`));
const CFG = '/srv/gate/sr-accountax-private/config.php';

await t('SR-01', 'Only the platform owner can read or change the settings (editor 403, signed out 401)', async () => {
  const a = await ed.call('site-admin', {company: false}), b = await anon.call('site-admin', {company: false});
  const c = await ed.call('site-admin/save', {method: 'POST', company: false, json: {groups: {release: {value: {stage: 'production'}, revision: 0}}, reason: 'not allowed here'}});
  const d = await ed.call('site-admin/policy-draft', {method: 'POST', company: false, json: {key: 'terms', version: 'x1', effectiveDate: '2026-11-01', title: 'T', body: 'B'}});
  return {ok: a.s === 403 && b.s === 401 && c.s === 403 && d.s === 403, info: `editor GET ${a.s}, signed out ${b.s}, editor save ${c.s}, editor draft ${d.s}`};
});

await t('SR-02', 'Defaults before any change: Beta stage, notice from config.php, version and Beta labels shown, blank overrides', async () => {
  const l = st.groups.labels.value, m = await me();
  return {ok: st.groups.release.value.stage === 'beta' && l.noticeEnabled === true && /sample data only/.test(l.noticeText) && l.showVersion && l.showBetaLabels && m.site?.notice?.enabled === true && m.betaNotice === l.noticeText && st.groups.labels.revision === 0,
    info: JSON.stringify({stage: st.groups.release.value.stage, notice: l.noticeEnabled, version: l.showVersion, beta: l.showBetaLabels, me: m.site})};
});

await t('SR-03', 'Saves are refused without a reason (422), with an out-of-date revision (409) and with invalid values (422)', async () => {
  const noReason = await o.call('site-admin/save', {method: 'POST', company: false, json: {groups: {release: {value: {stage: 'production'}, revision: 0}}, reason: 'short'}});
  const stale = await o.call('site-admin/save', {method: 'POST', company: false, json: {groups: {release: {value: {stage: 'production'}, revision: 7}}, reason: 'stale revision test'}});
  const bad = await o.call('site-admin/save', {method: 'POST', company: false, json: {groups: {release: {value: {stage: 'gamma'}, revision: 0}}, reason: 'invalid stage test'}});
  const badEmail = await o.call('site-admin/save', {method: 'POST', company: false, json: {groups: {operator: {value: {support_email: 'not-an-email'}, revision: 0}}, reason: 'invalid email test'}});
  const unknown = await o.call('site-admin/save', {method: 'POST', company: false, json: {groups: {site_text: {value: {made_up: {text: 'x', hidden: false}}, revision: 0}}, reason: 'unknown key test'}});
  const rows = Number(sql("SELECT COUNT(*) FROM site_settings"));
  return {ok: noReason.s === 422 && stale.s === 409 && bad.s === 422 && badEmail.s === 422 && unknown.s === 422 && rows === 0,
    info: `reason ${noReason.s}, stale ${stale.s}, stage ${bad.s}, email ${badEmail.s}, unknown wording ${unknown.s}; rows saved ${rows}`};
});

await t('SR-04', 'Notice off, version label hidden, Beta labels hidden: saved with history and audit; auth/me reflects it at once', async () => {
  const before = audit('platform.site_settings_changed');
  const r = await save1({labels: {...st.groups.labels.value, noticeEnabled: false, showVersion: false, showBetaLabels: false}});
  const m = await me(), hist = Number(sql("SELECT COUNT(*) FROM site_setting_history WHERE setting_group='labels'"));
  return {ok: r.s === 200 && m.betaNotice === '' && m.site.notice.enabled === false && m.site.showVersion === false && m.site.showBetaLabels === false && hist === 1 && audit('platform.site_settings_changed') === before + 1 && st.groups.labels.revision === 1,
    info: `save ${r.s}; me ${JSON.stringify(m.site)}; history ${hist}; revision ${st.groups.labels.revision}`};
});

await t('SR-05', 'A custom notice label and text reach every signed-in user (editor sees it); markup is kept as plain text', async () => {
  const r = await save1({labels: {...st.groups.labels.value, noticeEnabled: true, noticeLabel: 'Maintenance Sunday', noticeText: 'Tegh is closed Sunday 2-4 a.m. <b>ET</b>'}});
  const e = (await ed.call('auth/me', {company: false})).b;
  return {ok: r.s === 200 && e.site.notice.label === 'Maintenance Sunday' && e.betaNotice === 'Tegh is closed Sunday 2-4 a.m. <b>ET</b>', info: JSON.stringify(e.site.notice)};
});

await t('SR-06', 'Business details: an owner value replaces config.php on the public operator details; a blank field falls back', async () => {
  const r = await save1({operator: {legal_name: 'Owner Set Name Ltd.', support_email: ''}});
  const p = (await anon.call('public/operator', {company: false})).b;
  return {ok: r.s === 200 && p.operator.legal_name === 'Owner Set Name Ltd.' && p.operator.support_email === 'support@gate.test' && p.complete === true,
    info: `legal ${p.operator.legal_name}; support ${p.operator.support_email}; complete ${p.complete}`};
});

await t('SR-07', 'Public wording: replaced and hidden lines are served to the public pages; unchanged lines are not sent', async () => {
  const r = await save1({site_text: {footer_note: {text: 'No automatic paid conversion <script>x</script>', hidden: false}, footer_legal: {text: '', hidden: true}}});
  const p = (await anon.call('site/public', {company: false})).b;
  return {ok: r.s === 200 && p.siteText.footer_note.text === 'No automatic paid conversion <script>x</script>' && p.siteText.footer_legal.hidden === true && !('home_note' in p.siteText) && p.stage === 'beta',
    info: JSON.stringify(p.siteText).slice(0, 200)};
});

let draftId = '';
await t('SR-08', 'Policy drafts: invalid version refused; a draft is not public; publishing needs a reason', async () => {
  const bad = await o.call('site-admin/policy-draft', {method: 'POST', company: false, json: {key: 'privacy', version: 'bad version!', effectiveDate: '2026-11-01', title: 'Privacy Policy', body: 'x'}});
  const body = '## Who we are\n{{legal_name}} operates TEGH. Write to [privacy](mailto:{{privacy_email}}).\n\n## Unsafe text\n<img src=x onerror=alert(1)> and [click](javascript:alert(1))\n- one\n- two';
  const d = await o.call('site-admin/policy-draft', {method: 'POST', company: false, json: {key: 'privacy', version: 'P-' + stamp, effectiveDate: '2026-11-01', title: 'Privacy Policy', lead: 'How TEGH handles personal information.', body, reviewBanner: false}});
  draftId = d.b?.savedId || '';
  const pub = (await anon.call('site/policy&key=privacy', {company: false})).b;
  const noReason = await o.call('site-admin/policy-publish', {method: 'POST', company: false, json: {id: draftId, reason: 'no'}});
  return {ok: bad.s === 422 && d.s === 200 && !!draftId && pub.policy === null && noReason.s === 422, info: `bad version ${bad.s}; draft ${d.s}; public before publish ${JSON.stringify(pub.policy)}; publish without reason ${noReason.s}`};
});

await t('SR-09', 'Publishing makes the version public with business details filled in; published versions cannot be edited or deleted; a duplicate version is refused', async () => {
  const before = audit('platform.policy_published');
  const p = await o.call('site-admin/policy-publish', {method: 'POST', company: false, json: {id: draftId, reason: 'R174 gate: publish privacy'}});
  const pub = (await anon.call('site/policy&key=privacy', {company: false})).b.policy;
  const edit = await o.call('site-admin/policy-draft', {method: 'POST', company: false, json: {id: draftId, key: 'privacy', version: 'P-' + stamp, effectiveDate: '2026-11-01', title: 'Changed', body: 'changed'}});
  const del = await o.call('site-admin/policy-delete', {method: 'POST', company: false, json: {id: draftId}});
  const dup = await o.call('site-admin/policy-draft', {method: 'POST', company: false, json: {key: 'privacy', version: 'P-' + stamp, effectiveDate: '2026-11-02', title: 'Privacy', body: 'again'}});
  const ok = p.s === 200 && pub?.version === 'P-' + stamp && /Owner Set Name Ltd\. operates TEGH/.test(pub.body) && /mailto:privacy@gate\.test/.test(pub.body)
    && pub.body.includes('<img src=x onerror=alert(1)>') && edit.s === 409 && del.s === 409 && dup.s === 409 && audit('platform.policy_published') === before + 1;
  return {ok, info: `publish ${p.s}; public version ${pub?.version}; edit ${edit.s}; delete ${del.s}; duplicate ${dup.s}`};
});

await t('SR-10', 'Publishing a new Terms version changes what invitees accept: the old version is refused, the new one is recorded', async () => {
  const d = need('terms draft', await o.call('site-admin/policy-draft', {method: 'POST', company: false, json: {key: 'terms', version: 'T-' + stamp, effectiveDate: '2026-11-01', title: 'Terms of Use', body: '## Agreement\nThese terms are supplied by {{legal_name}}.'}}));
  need('terms publish', await o.call('site-admin/policy-publish', {method: 'POST', company: false, json: {id: d.savedId, reason: 'R174 gate: publish terms'}}));
  const email = `r174-${stamp}@gate.test`, pw = 'Gate!Member#2026pw', before = mails().length;
  need('invite', await o.call('platform/invitations', {method: 'POST', json: {action: 'create_account', email, scope: 'company', assignments: [{companyId: ids.BC, role: 'viewer'}], operationKey: 'r174-invite-' + stamp + '-' + Date.now()}}));
  const code = mails().slice(before).map(m => m.t.match(/invitation code:\s*([A-Z0-9]{5}-[A-Z0-9]{5})/)?.[1]).find(Boolean);
  const n = new S(); const det = (await n.call('platform/invite-details', {method: 'POST', company: false, json: {email, code}})).b.invitation;
  const old = await n.call('platform/invite-accept', {method: 'POST', company: false, json: {email, code, password: pw, confirmPassword: pw, acceptTerms: true, termsVersion: '2026-10-21-beta.1', privacyVersion: det.privacyVersion}});
  const acc = await n.call('platform/invite-accept', {method: 'POST', company: false, json: {email, code, password: pw, confirmPassword: pw, acceptTerms: true, termsVersion: det.termsVersion, privacyVersion: det.privacyVersion}});
  const recd = sql(`SELECT CONCAT(t.terms_version,'|',t.privacy_version) FROM terms_acceptances t JOIN users u ON u.id=t.user_id WHERE u.email='${email}'`);
  return {ok: det.termsVersion === 'T-' + stamp && det.privacyVersion === 'P-' + stamp && old.s === 422 && [200, 201].includes(acc.s) && recd === `T-${stamp}|P-${stamp}`,
    info: `details ${det.termsVersion}/${det.privacyVersion}; old version ${old.s}; accept ${acc.s}; recorded ${recd}`};
});

await t('SR-11', 'Sign-up: "Invitation or open sign-up" lets the public create an account (with the published versions); back to invitation only refuses it', async () => {
  const reg = (await o.call('platform/registration', {company: false})).b;
  const on = await o.call('platform/registration', {method: 'POST', company: false, json: {enabled: true, revision: reg.revision, confirmation: 'CHANGE REGISTRATION', reason: 'R174 gate: open sign-up'}});
  const sec = (await anon.call('auth/security', {company: false})).b;
  const email = `public-${stamp}@gate.test`;
  const r = await new S().call('auth/register', {method: 'POST', company: false, json: {email, displayName: 'Public Person', password: 'Gate!Public#2026pw', acceptTerms: true}});
  const recd = sql(`SELECT CONCAT(u.signup_source,'|',t.terms_version) FROM users u JOIN terms_acceptances t ON t.user_id=u.id WHERE u.email='${email}'`);
  const off = await o.call('platform/registration', {method: 'POST', company: false, json: {enabled: false, revision: on.b.revision, confirmation: 'CHANGE REGISTRATION', reason: 'R174 gate: invitation only again'}});
  const r2 = await new S().call('auth/register', {method: 'POST', company: false, json: {email: `public2-${stamp}@gate.test`, displayName: 'Second Person', password: 'Gate!Public#2026pw', acceptTerms: true}});
  return {ok: on.s === 200 && sec.publicSignupEnabled === true && [200, 201].includes(r.s) && recd === `public_signup|T-${stamp}` && off.s === 200 && r2.s === 403,
    info: `on ${on.s}; profile ${sec.publicSignupEnabled}; register ${r.s} → ${recd}; off ${off.s}; register after ${r2.s}`};
});

await t('SR-12', 'Emergency stop: public_signup_force_off in config.php overrides the owner setting', async () => {
  const orig = fs.readFileSync(CFG, 'utf8');
  const reg = (await o.call('platform/registration', {company: false})).b;
  const on = await o.call('platform/registration', {method: 'POST', company: false, json: {enabled: true, revision: reg.revision, confirmation: 'CHANGE REGISTRATION', reason: 'R174 gate: force-off check'}});
  let sec, r, st2;
  try {
    fs.writeFileSync(CFG, orig.replace("'public_signup_force_off' => false", "'public_signup_force_off' => true"));
    await new Promise(res => setTimeout(res, 3000));
    sec = (await anon.call('auth/security', {company: false})).b;
    r = await new S().call('auth/register', {method: 'POST', company: false, json: {email: `forced-${stamp}@gate.test`, displayName: 'Forced Off', password: 'Gate!Public#2026pw', acceptTerms: true}});
    st2 = (await o.call('site-admin', {company: false})).b.registration;
  } finally {
    fs.writeFileSync(CFG, orig); await new Promise(res => setTimeout(res, 3000));
    const cur = (await o.call('platform/registration', {company: false})).b;
    await o.call('platform/registration', {method: 'POST', company: false, json: {enabled: false, revision: cur.revision, confirmation: 'CHANGE REGISTRATION', reason: 'R174 gate: restore invitation only'}});
  }
  return {ok: on.s === 200 && sec.publicSignupEnabled === false && r.s === 403 && st2.deploymentAllowsPublicSignup === false && st2.ownerSettingEnabled === true,
    info: `owner on ${on.s}; profile ${sec.publicSignupEnabled}; register ${r.s}; owner view ${JSON.stringify({deployment: st2.deploymentAllowsPublicSignup, owner: st2.ownerSettingEnabled})}`};
});

await t('SR-13', 'Production stage: auth/security reports it (the sign-in screen stops calling Tegh a beta)', async () => {
  const r = await save1({release: {stage: 'production'}});
  const sec = (await anon.call('auth/security', {company: false})).b;
  return {ok: r.s === 200 && sec.releaseStage === 'production', info: `save ${r.s}; releaseStage ${sec.releaseStage}`};
});

// ---------- browser ----------
const browser = await chromium.launch({executablePath: '/opt/pw-browsers/chromium', args: ['--no-proxy-server', '--host-resolver-rules=MAP gate.test 127.0.0.1']});
const pub = async (path, w = 1440) => {
  const ctx = await browser.newContext({viewport: {width: w, height: 900}, ignoreHTTPSErrors: true, isMobile: w < 800});
  const p = await ctx.newPage(); const errs = [], dialogs = [];
  p.on('pageerror', e => errs.push(e.message.slice(0, 120))); p.on('dialog', d => { dialogs.push(d.message()); d.dismiss(); });
  await p.goto('https://gate.test' + path); await p.waitForFunction(() => document.documentElement.dataset.siteSettings === 'applied', null, {timeout: 15000});
  return {p, ctx, errs, dialogs};
};

await t('SR-UI-01', 'Privacy page shows the published version: sections, business details, effective date; injected markup is shown as text and runs nothing', async () => {
  const {p, ctx, errs, dialogs} = await pub('/privacy.html');
  const info = await p.evaluate(() => ({h1: document.querySelector('main h1')?.textContent, meta: document.querySelector('.legal-meta')?.textContent.replace(/\s+/g, ' '),
    sections: document.querySelectorAll('main article section').length, imgs: document.querySelectorAll('main img').length, js: [...document.querySelectorAll('main a')].filter(a => /^javascript:/i.test(a.getAttribute('href') || '')).length,
    text: document.querySelector('main article')?.textContent, mail: document.querySelector('main article a[href^="mailto:privacy@gate.test"]')?.textContent, banner: !!document.querySelector('.draft-banner'), li: document.querySelectorAll('main article li').length}));
  await p.screenshot({path: '/srv/gate/ev/shots/r174-privacy-published.png', fullPage: true}); await ctx.close();
  const ok = info.h1 === 'Privacy Policy' && /Version: P-/.test(info.meta) && /November 1, 2026/.test(info.meta) && info.sections === 2 && info.imgs === 0 && info.js === 0
    && info.text.includes('<img src=x onerror=alert(1)>') && info.text.includes('Owner Set Name Ltd. operates TEGH') && info.mail === 'privacy' && !info.banner && info.li === 2 && !errs.length && !dialogs.length;
  return {ok, info: JSON.stringify({...info, text: undefined, errs, dialogs})};
});

await t('SR-UI-02', 'Public wording on the pages: footer line replaced (markup as text), policy note hidden; unpublished pages keep their built-in text', async () => {
  const {p, ctx, errs} = await pub('/pricing.html');
  const info = await p.evaluate(() => ({note: document.querySelector('[data-site-text="footer_note"]')?.textContent, scripts: document.querySelectorAll('[data-site-text="footer_note"] script').length,
    legalShown: !!document.querySelector('[data-site-text="footer_legal"]')?.offsetParent, title: document.querySelector('h1')?.textContent}));
  const s = await pub('/security.html'); const sec = await s.p.evaluate(() => ({h1: document.querySelector('main h1')?.textContent, banner: !!document.querySelector('.draft-banner')}));
  await ctx.close(); await s.ctx.close();
  return {ok: info.note === 'No automatic paid conversion <script>x</script>' && info.scripts === 0 && info.legalShown === false && info.title === 'Free during beta' && sec.h1 === 'Security Statement' && sec.banner && !errs.length,
    info: JSON.stringify({info, sec})};
});

await t('SR-UI-03', 'Sign-in screen on Production: no "invitation-only beta", the sign-up forms link "Terms of Use"', async () => {
  const ctx = await browser.newContext({viewport: {width: 1440, height: 900}, ignoreHTTPSErrors: true}); const p = await ctx.newPage();
  await p.goto('https://gate.test/app.html'); await p.waitForSelector('#sr-choose-register'); await p.waitForTimeout(1200);
  await p.click('#sr-choose-register'); await p.waitForTimeout(600);
  const info = await p.evaluate(() => ({msg: document.querySelector('[data-invite-needed-message]')?.textContent, terms: [...document.querySelectorAll('.sr-gate a[href="/terms.html"]')].map(a => a.textContent.trim())}));
  await p.screenshot({path: '/srv/gate/ev/shots/r174-signin-production.png'}); await ctx.close();
  return {ok: /^Tegh accounts are created by invitation\./.test(info.msg) && !/beta/i.test(info.msg) && info.terms.every(x => !/Beta/.test(x)) && info.terms.length > 0, info: JSON.stringify(info)};
});

// Back to the beta labels, then use the owner screen as a person would.
await save1({release: {stage: 'beta'}, labels: {...st.groups.labels.value, noticeEnabled: true, noticeLabel: 'Private beta · sample data only', noticeText: 'Private beta: use sample data only.', showVersion: true, showBetaLabels: true}});
const {b, p, errs} = await open(ids.BC, {w: 1440, h: 900});
await t('SR-UI-04', 'Owner screen: Platform Owner Home → Site & release settings; the notice chip and version label show before the change', async () => {
  await p.evaluate(() => TeghPortal.openPlatformAdmin()); await p.waitForSelector('[data-admin-site-settings]', {timeout: 20000});
  await p.click('[data-admin-site-settings]'); await p.waitForSelector('[data-r174-production]', {timeout: 20000});
  const info = await p.evaluate(() => ({chip: document.querySelector('[data-r155-beta-notice] .r155-beta-long')?.textContent, version: !!document.querySelector('.srp-sites-release')?.offsetParent,
    tabs: [...document.querySelectorAll('[data-r174-tab]')].map(x => x.textContent.trim())}));
  await p.screenshot({path: '/srv/gate/ev/shots/r174-settings-release.png', fullPage: true});
  return {ok: info.chip === 'Private beta · sample data only' && info.version && info.tabs.length === 6, info: JSON.stringify(info)};
});

await t('SR-UI-05', '"Apply production settings" with a reason: stage Production; after reload the notice chip and the version label are gone', async () => {
  await p.fill('[data-r174-production] input[name=reason]', 'Gate: moving to the production release');
  await p.click('[data-r174-production] button[type=submit]');
  await p.locator('.tegh-dialog button, [role=dialog] button').filter({hasText: /^Apply$/}).first().click({timeout: 10000});
  await p.waitForFunction(() => /Stage<\/b>: Production/.test(document.querySelector('.r174-checklist')?.innerHTML || ''), null, {timeout: 20000});
  await p.reload(); await p.waitForFunction(() => window.TeghPortal && document.querySelector('.sidebar,.topbar'), null, {timeout: 40000}); await p.waitForTimeout(1500);
  const info = await p.evaluate(() => ({chip: !!document.querySelector('[data-r155-beta-notice]'), version: !!document.querySelector('.srp-sites-release')?.offsetParent,
    hideVersion: document.documentElement.hasAttribute('data-tegh-hide-version'), hideBeta: document.documentElement.hasAttribute('data-tegh-hide-beta-labels')}));
  const row = sql("SELECT reason FROM site_setting_history ORDER BY created_at DESC, id DESC LIMIT 1");
  await p.screenshot({path: '/srv/gate/ev/shots/r174-after-production.png'});
  return {ok: !info.chip && !info.version && info.hideVersion && info.hideBeta && row === 'Gate: moving to the production release', info: JSON.stringify({...info, row})};
});

await t('SR-UI-06', 'Policies tab: start from the current public page, preview, save a draft, publish with a reason', async () => {
  await p.evaluate(() => TeghPortal.openSiteReleaseSettings({tab: 'policies', policy: 'cookies'})); await p.waitForSelector('[data-r174-policy-form]', {timeout: 20000});
  await p.click('[data-r174-import]'); await p.waitForFunction(() => document.querySelector('[data-r174-policy-form] textarea[name=body]').value.includes('## '), null, {timeout: 10000});
  await p.fill('[data-r174-policy-form] input[name=version]', 'C-' + stamp);
  await p.click('[data-r174-preview]'); await p.waitForSelector('[data-r174-preview-box] section', {timeout: 10000});
  const sections = await p.locator('[data-r174-preview-box] section').count();
  await p.click('[data-r174-policy-form] button[type=submit]'); await p.waitForSelector('[data-r174-publish]', {timeout: 15000});
  await p.fill('[data-r174-publish] input[name=reason]', 'Gate: publish cookies from the built-in page');
  await p.click('[data-r174-publish] button[type=submit]');
  await p.locator('.tegh-dialog button, [role=dialog] button').filter({hasText: /^Publish$/}).first().click({timeout: 10000});
  await p.waitForFunction(v => document.body.textContent.includes(`Live: version ${v}`), 'C-' + stamp, {timeout: 20000});
  await p.screenshot({path: '/srv/gate/ev/shots/r174-settings-policies.png', fullPage: true});
  const live = (await anon.call('site/policy&key=cookies', {company: false})).b.policy;
  return {ok: sections >= 3 && live?.version === 'C-' + stamp && /## Current technologies/.test(live.body), info: `preview sections ${sections}; live ${live?.version}`};
});

await t('SR-UI-07', 'Phone 390×844: the settings page has no sideways scroll; no browser errors on the owner screens', async () => {
  const ph = await open(ids.BC, {w: 390, h: 844});
  await ph.p.evaluate(() => TeghPortal.openSiteReleaseSettings({tab: 'wording'})); await ph.p.waitForSelector('[data-r174-wording]', {timeout: 20000});
  const over = await ph.p.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  await ph.p.screenshot({path: '/srv/gate/ev/shots/r174-settings-phone.png'}); await ph.b.close();
  return {ok: over <= 0 && !errs.length && !ph.errs.length, info: `sideways ${over}px; errors ${[...errs, ...ph.errs].join(' | ') || 'none'}`};
});
await b.close();

// ---------- put everything back ----------
await t('SR-99', 'Restore: beta stage, config notice, labels shown, no overrides, built-in policy pages, invitation only', async () => {
  st = (await o.call('site-admin', {company: false})).b;
  await save1({release: {stage: 'beta'}, labels: {noticeEnabled: true, noticeLabel: 'Private beta · sample data only', noticeText: 'Private beta: use sample data only. Do not enter real client, employee or bank records. Beta data may be reset.', showVersion: true, showBetaLabels: true}, operator: {}, site_text: {}}, 'R174 gate: restore defaults');
  for (const key of ['privacy', 'terms', 'cookies']) await o.call('site-admin/policy-unpublish', {method: 'POST', company: false, json: {key, reason: 'R174 gate: restore built-in page'}});
  const pubs = Number(sql("SELECT COUNT(*) FROM site_policy_versions WHERE status='published'")), reg = (await anon.call('auth/security', {company: false})).b;
  const op = (await anon.call('public/operator', {company: false})).b.operator;
  return {ok: pubs === 0 && reg.publicSignupEnabled === false && reg.releaseStage === 'beta' && op.legal_name === 'Gate Test Operator Inc.', info: `published ${pubs}; signup ${reg.publicSignupEnabled}; stage ${reg.releaseStage}; operator ${op.legal_name}`};
});
await browser.close();
save('r174.json');

// R171 Company feed. A fresh synthetic company gets real activity through the API (customers, an issued invoice, a
// supplier, a journal) from the owner and an editor; payroll and team-member events, which need a long set-up, are
// added as synthetic audit rows (test database only). The suite then checks what the feed returns and to whom, and
// the window on the Dashboard: desktop sizes and themes, minimising, live posts, opening a record, phone sheet.
import fs from 'fs';
import {S, sql, rec, save, need} from './lib.mjs';
import {open} from './e2e/base.mjs';
const A = 'R171';
const pw = 'Gate!Owner#2026pw', mpw = 'Gate!Member#2026pw';
const stamp = Date.now().toString(36);
const t = async (id, title, fn) => { try { const r = await fn(); rec(id, A, title, r.ok ? 'PASS' : 'FAIL', r.info); return r.ok; } catch (e) { rec(id, A, title, 'FAIL', e.message.slice(0, 400)); return false; } };
const o = new S(); await o.login('owner@gate.test', pw);
const CID = need('co', await o.call('companies', {method: 'POST', company: false, json: {name: 'R171 Feed Test Ltd ' + stamp, province: 'ON', country: 'Canada', businessType: 'corporation', currency: 'CAD', accountingBasis: 'accrual', moduleMode: 'accounting', fiscalYearEnd: '12-31', booksStartDate: '2026-01-01', taxRegistered: true, taxNumber: '123456789RT0001', coaMode: 'default'}})).company?.id;
o.cid = CID;
const ids = JSON.parse(fs.readFileSync('/srv/gate/t/ids.json')); ids.R171 = CID; fs.writeFileSync('/srv/gate/t/ids.json', JSON.stringify(ids));
const uid = e => sql(`SELECT id FROM users WHERE email='${e}'`);
for (const [email, role] of [['viewer@gate.test', 'viewer'], ['editor@gate.test', 'editor']]) sql(`INSERT IGNORE INTO company_members (company_id,user_id,role,status) VALUES ('${CID}','${uid(email)}','${role}','active')`);
// Skip the first-run set-up screens for this synthetic company so the Dashboard opens directly.
try { sql(`INSERT INTO company_onboarding (company_id,required,steps_json,completed_at,tour_done_at) VALUES ('${CID}',0,'{}',UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE completed_at=UTC_TIMESTAMP(),tour_done_at=UTC_TIMESTAMP()`); } catch (e) { console.log('onboarding row', e.message.slice(0, 120)); }
const ed = new S(); await ed.login('editor@gate.test', mpw); ed.cid = CID;
const vw = new S(); await vw.login('viewer@gate.test', mpw); vw.cid = CID;
const feed = async (s, params = '') => { const r = await s.call('activity-feed' + (params ? '?' + params : '')); return r; };
const auditCount = () => Number(sql(`SELECT COUNT(*) FROM audit_log WHERE company_id='${CID}'`));

// Activity
const tmpl = async () => { const x = await o.call('invoice-templates'); return (x.b?.templates || x.b?.invoiceTemplates || [])[0]?.id; };
const cust = need('cust', await o.call('customers', {method: 'POST', json: {name: 'Harbour Bakery ' + stamp, email: '', phone: '', billingAddress: '1 Test St', province: 'ON'}}));
const custId = cust.customer?.id || cust.id;
const inv = await o.call('invoices', {method: 'POST', json: {customerId: custId, issueDate: '2026-09-15', dueDate: '2026-10-15', currency: 'CAD', templateId: await tmpl(), issue: true, lines: [{description: 'Catering', quantity: 1, unitPriceCents: 100000, taxable: true}]}});
const invoiceId = inv.b?.invoice?.id || inv.b?.id;
await ed.call('vendors', {method: 'POST', json: {name: 'Lakeside Flour Co. ' + stamp, province: 'ON', country: 'Canada', paymentTerms: 30}});
const evil = '<img src=x onerror="window.__xss=1">Mallory ' + stamp;
await ed.call('customers', {method: 'POST', json: {name: evil, email: '', phone: '', billingAddress: '2 Test St', province: 'ON'}});
// Synthetic audit rows: a pay run and a team-member role change (test database only).
const ownerId = uid('owner@gate.test');
sql(`INSERT INTO audit_log (id,company_id,actor_user_id,actor_email,action,entity_type,entity_id,metadata_json,created_at) VALUES
  ('audit_r171pay${stamp}','${CID}','${ownerId}','owner@gate.test','payroll.run_finalized','payroll_run','payrun_r171${stamp}','{"grossCents":250000}',UTC_TIMESTAMP()),
  ('audit_r171mem${stamp}','${CID}','${ownerId}','owner@gate.test','company.member_role_changed','company_member','member_r171','{"email":"editor@gate.test","from":"viewer","to":"editor"}',UTC_TIMESTAMP()),
  ('audit_r171ui${stamp}','${CID}','${ownerId}','owner@gate.test','interface.layout_preference','user','${ownerId}','{"layout":"side"}',UTC_TIMESTAMP())`);

// ---------------- API ----------------
const all = await feed(o, 'limit=50');
const texts = (all.b.items || []).map(i => i.text);
await t('FD-01', 'The owner’s feed shows the company’s business activity in plain language, newest first', async () => {
  const want = [/issued invoice INV-\d+ to Harbour Bakery/, /added a new customer, Harbour Bakery/, /added a new supplier, Lakeside Flour/, /created the company/];
  const missing = want.filter(re => !texts.some(x => re.test(x)));
  const at = (all.b.items || []).map(i => i.at), sorted = at.every((x, i) => i === 0 || at[i - 1] >= x);
  return {ok: all.s === 200 && !missing.length && sorted, info: `${all.s}; ${texts.length} posts; missing ${missing.join(',') || 'none'}; newest first ${sorted}; first: ${texts.slice(0, 4).join(' | ')}`};
});
await t('FD-02', 'The issued invoice post carries the amount and opens the invoice', async () => {
  const p = all.b.items.find(i => i.action === 'invoice.issued');
  return {ok: p && p.amount?.cents === 113000 && p.link?.type === 'invoice' && p.link.id === invoiceId, info: JSON.stringify(p && {amount: p.amount, link: p.link})};
});
await t('FD-03', 'Who did it: shown by name with “you” marked; the editor’s supplier is the editor’s', async () => {
  const mine = all.b.items.find(i => i.action === 'invoice.issued'), theirs = all.b.items.find(i => i.action === 'vendor.created');
  return {ok: mine?.actor.you === true && theirs?.actor.you === false && !!theirs?.actor.name, info: `invoice by you=${mine?.actor.you}; supplier by ${theirs?.actor.name} you=${theirs?.actor.you}`};
});
await t('FD-04', 'Private details stay out: no email addresses, IP or browser fingerprints, and technical entries (layout preferences) are not posted', async () => {
  const raw = JSON.stringify(all.b);
  return {ok: !/@gate\.test/.test(raw) && !/ip_hash|user_agent|actor_email|entry_hash/.test(raw) && !all.b.items.some(i => i.action.startsWith('interface.')), info: `emails ${/@gate\.test/.test(raw)}; hashes ${/ip_hash|user_agent|entry_hash/.test(raw)}; interface posts ${all.b.items.filter(i => i.action.startsWith('interface.')).length}`};
});
await t('FD-05', 'Payroll: the owner sees the pay run (no amount); a viewer, who cannot see payroll, does not', async () => {
  const pay = all.b.items.find(i => i.action === 'payroll.run_finalized');
  const v = await feed(vw, 'limit=50'), vp = await feed(vw, 'category=payroll');
  return {ok: !!pay && pay.amount === null && v.s === 200 && !v.b.items.some(i => i.category === 'payroll') && vp.s === 200 && vp.b.items.length === 0, info: `owner sees "${pay?.text}" amount ${JSON.stringify(pay?.amount)}; viewer ${v.s} payroll posts ${v.b.items?.filter(i => i.category === 'payroll').length}; viewer payroll filter ${vp.b.items?.length}`};
});
await t('FD-06', 'Team changes: the owner sees the member’s name; a viewer (no access to the team list) sees “a team member”', async () => {
  const mo = all.b.items.find(i => i.action === 'company.member_role_changed');
  const mv = (await feed(vw, 'limit=50')).b.items.find(i => i.action === 'company.member_role_changed');
  return {ok: !!mo && !/a team member/.test(mo.text) && /a team member/.test(mv?.text || '') && !/@/.test(mo.text + mv.text), info: `owner: ${mo?.text} | viewer: ${mv?.text}`};
});
await t('FD-07', 'Another company’s feed cannot be read, even with its id', async () => {
  const outsider = new S(); await outsider.login('owner@gate.test', pw);
  const other = need('co2', await outsider.call('companies', {method: 'POST', company: false, json: {name: 'R171 Other Ltd ' + stamp, province: 'ON', country: 'Canada', businessType: 'corporation', currency: 'CAD', accountingBasis: 'accrual', moduleMode: 'accounting', fiscalYearEnd: '12-31', booksStartDate: '2026-01-01', taxRegistered: false, coaMode: 'default'}})).company?.id;
  ed.cid = other; const r = await feed(ed); ed.cid = CID;
  return {ok: r.s === 403 || r.s === 404, info: `editor (not a member) asking for the other company: ${r.s}`};
});
await t('FD-08', 'Filters: Sales shows only sales posts; “only other people” leaves out your own; an unknown filter is refused', async () => {
  const s = await feed(o, 'category=sales'), others = await feed(o, 'who=others&limit=50'), bad = await feed(o, 'category=everything');
  return {ok: s.b.items.length > 0 && s.b.items.every(i => i.category === 'sales') && others.b.items.length > 0 && others.b.items.every(i => !i.actor.you) && bad.s === 422,
    info: `sales ${s.b.items.length} (${[...new Set(s.b.items.map(i => i.category))]}); others ${others.b.items.length} all others ${others.b.items.every(i => !i.actor.you)}; unknown ${bad.s}`};
});
await t('FD-09', 'Paging: older posts follow on without repeats; “after” returns only newer posts', async () => {
  const p1 = await feed(o, 'limit=3'), p2 = await feed(o, 'limit=3&before=' + encodeURIComponent(p1.b.next || ''));
  const overlap = p1.b.items.filter(i => p2.b.items.some(j => j.id === i.id)).length;
  const none = await feed(o, 'after=' + encodeURIComponent(p1.b.latest));
  return {ok: !!p1.b.next && p2.b.items.length > 0 && overlap === 0 && none.b.items.length === 0, info: `page1 ${p1.b.items.length}, page2 ${p2.b.items.length}, overlap ${overlap}; after latest ${none.b.items.length}`};
});
await t('FD-10', 'Reading the feed records nothing and changes nothing', async () => {
  const before = auditCount(); for (let i = 0; i < 3; i++) await feed(o); return {ok: auditCount() === before, info: `audit rows ${before} → ${auditCount()}`};
});
await t('FD-11', 'Record text is returned as data: the customer name with markup comes back unchanged for the browser to escape', async () => {
  const p = (await feed(o, 'limit=50')).b.items.find(i => i.text.includes('Mallory'));
  return {ok: !!p && p.text.includes('<img'), info: p?.text.slice(0, 120)};
});
await t('FD-12', 'Signed-out requests are refused', async () => { const anon = new S(); anon.cid = CID; const r = await anon.call('activity-feed'); return {ok: r.s === 401 || r.s === 403, info: String(r.s)}; });

// ---------------- the window ----------------
const shots = '/srv/gate/ev/shots';
const {b, p, errs} = await open(CID, {w: 1920, h: 1080});
// Theme and navigation are set the way a person sets them: on the Appearance screen (as suite 41 does).
const appearance = async (theme, nav) => {
  await p.evaluate(() => window.TeghPortal.openTeghPreferences()); await p.waitForSelector('[data-tegh-preferences]', {timeout: 30000});
  await p.locator(`[data-tegh-preferences] input[name=theme][value=${theme}]`).check({force: true});
  await p.locator(`[data-tegh-preferences] input[name=navigationLayout][value=${nav}]`).check({force: true});
  await p.locator('[data-tegh-preferences] button[type=submit]').first().click(); await p.waitForTimeout(1500);
  return p.evaluate(() => ({theme: document.documentElement.dataset.teghTheme || '', nav: document.documentElement.dataset.teghNavigationLayout || ''}));
};
const shown = sel => p.waitForSelector(sel, {timeout: 30000}).then(() => true, () => false);
// After a refresh the app reopens the last screen a moment later; wait for that, then open the Dashboard.
const home = async () => {
  await p.waitForFunction(() => document.querySelector('.srp-page'), null, {timeout: 30000}); await p.waitForTimeout(800);
  for (let i = 0; i < 3; i++) {
    await p.evaluate(() => TeghPortal.openDashboard());
    await p.waitForFunction(() => [...document.querySelectorAll('.srp-page')].pop()?.dataset.srpPage === 'dashboard', null, {timeout: 30000});
    await p.waitForTimeout(2000);
    if (await p.evaluate(() => [...document.querySelectorAll('.srp-page')].pop()?.dataset.srpPage === 'dashboard')) break;
  }
  await shown('[data-feed-pill]:not([hidden]), [data-feed-panel]:not([hidden])'); await p.waitForTimeout(1000);
};
// Where the feed is, and which of the page's own buttons, links and fields lie under it (must be none).
const geo = () => p.evaluate(() => {
  const root = document.querySelector('[data-company-feed]'), panel = root?.querySelector('[data-feed-panel]'), pill = document.querySelector('[data-feed-pill]');
  const page = [...document.querySelectorAll('.srp-page')].pop(), se = document.scrollingElement;
  const open = panel && !panel.hidden, box = (open ? panel : pill)?.getBoundingClientRect();
  const covered = !box ? [] : [...page.querySelectorAll('button,a,input,select,textarea,[role=button]')].filter(e => {
    if (e.closest('[data-company-feed]') || e.matches('[data-feed-pill]')) return false;
    const r = e.getBoundingClientRect(); if (!r.width || !r.height) return false;
    return r.left < box.right - 1 && r.right > box.left + 1 && r.top < box.bottom - 1 && r.bottom > box.top + 1;
  }).map(e => (e.textContent || e.getAttribute('aria-label') || e.tagName).trim().slice(0, 30));
  return {page: page?.dataset.srpPage, mode: root.hidden ? 'none' : open ? (root.classList.contains('is-docked') ? 'docked' : 'floating') : (pill && !pill.hidden ? 'pill' : 'none'),
    pillInHeading: !!pill?.closest('header.srp-page-head, .tegh-guided-home-head'), box: box && [box.x, box.y, box.width, box.height].map(Math.round),
    inView: box ? box.right <= innerWidth + 1 && box.bottom <= innerHeight + 1 && box.x >= 0 && box.y >= 0 : null, covered,
    pageX: Math.max(se.scrollWidth - se.clientWidth, page.scrollWidth - page.clientWidth), posts: document.querySelectorAll('[data-feed-post]').length};
});
await p.evaluate(() => { try { localStorage.removeItem('tegh-feed-r171'); } catch (_) {} });
await p.reload(); await p.waitForFunction(() => window.TeghPortal && document.querySelector('.sidebar,.topbar'), null, {timeout: 40000});
await home();
// Wide screens: the window is open and the page keeps a strip free for it. Narrower screens: a Company feed button in
// the page heading. Either way nothing on the page is covered and there is no sideways scroll.
for (const [w, h, theme, nav, label, want] of [[1920, 1080, 'light', 'side', '1920', 'docked'], [2560, 1440, 'dark', 'side', '2560-dark', 'docked'], [1440, 900, 'light', 'side', '1440', 'pill'], [1366, 768, 'dark', 'side', '1366-dark', 'pill'], [1280, 720, 'light', 'top', '1280-top', 'pill'], [1920, 1080, 'dark', 'top', '1920-dark-top', 'docked'], [1024, 768, 'dark', 'top', '1024-dark-top', 'pill']]) {
  await t('FD-UI-' + label, `Dashboard at ${w}×${h}, ${theme}, ${nav} navigation: ${want === 'docked' ? 'the feed is open beside the page' : 'a Company feed button in the page heading'}; nothing on the page is covered; no sideways scroll`, async () => {
    await p.evaluate(() => { try { localStorage.removeItem('tegh-feed-r171'); } catch (_) {} });
    await p.setViewportSize({width: w, height: h});
    const applied = await appearance(theme, nav);
    await p.reload(); await p.waitForFunction(() => window.TeghPortal && document.querySelector('.sidebar,.topbar'), null, {timeout: 40000});
    await home();
    const g = await geo(); await p.screenshot({path: `${shots}/r171-feed-${label}.png`});
    const ok = g.page === 'dashboard' && g.box && g.box[2] > 0 && g.mode === want && g.covered.length === 0 && g.pageX <= 1 && g.inView && applied.theme === theme && applied.nav === nav && (want === 'docked' ? g.posts > 3 : g.pillInHeading);
    return {ok, info: JSON.stringify({...g, applied})};
  });
}
await p.setViewportSize({width: 1920, height: 1080});
await appearance('light', 'side');
await p.evaluate(() => { try { localStorage.removeItem('tegh-feed-r171'); } catch (_) {} });
await p.reload(); await p.waitForFunction(() => window.TeghPortal && document.querySelector('.sidebar,.topbar'), null, {timeout: 40000});
await home();
await t('FD-UI-ESC', 'Names and memos are shown as text: the customer name containing markup runs nothing', async () => {
  const text = await p.evaluate(() => [...document.querySelectorAll('[data-feed-post]')].map(x => x.textContent).find(x => x.includes('Mallory')) || '');
  return {ok: text.includes('<img') && !(await p.evaluate(() => window.__xss)) && !(await p.locator('[data-company-feed] img').count()), info: text.replace(/\s+/g, ' ').trim().slice(0, 140)};
});
await t('FD-UI-MIN', 'Minimise turns the window into the Company feed button in the heading; the choice is kept after a refresh; the button opens it again', async () => {
  await p.locator('[data-feed-close]').click(); await p.waitForTimeout(400);
  const g1 = await geo();
  await p.reload(); await p.waitForFunction(() => window.TeghPortal && document.querySelector('.sidebar,.topbar'), null, {timeout: 40000}); await home();
  const g2 = await geo();
  await p.locator('[data-feed-pill]').click(); await p.waitForTimeout(800); const g3 = await geo();
  return {ok: g1.mode === 'pill' && g1.pillInHeading && g2.mode === 'pill' && g3.mode === 'docked', info: `after minimise ${g1.mode}; after refresh ${g2.mode}; reopened ${g3.mode}`};
});
await t('FD-UI-LIVE', 'Another person’s new activity appears without reloading, marked New, and the button counts it while minimised', async () => {
  await p.locator('[data-feed-close]').click(); await p.waitForTimeout(300);
  await ed.call('customers', {method: 'POST', json: {name: 'Northshore Deli ' + stamp, email: '', phone: '', billingAddress: '3 Test St', province: 'ON'}});
  // The window checks every 45 seconds; wait for that check rather than forcing it.
  const got = await p.waitForFunction(() => /\d/.test(document.querySelector('[data-feed-pill-count]')?.textContent || ''), null, {timeout: 70000}).then(() => true, () => false);
  const count = await p.locator('[data-feed-pill-count]').textContent();
  await p.locator('[data-feed-pill]').click(); await p.waitForTimeout(600);
  const post = await p.evaluate(() => { const x = [...document.querySelectorAll('[data-feed-post]')].find(e => e.textContent.includes('Northshore Deli')); return x ? {text: x.textContent.replace(/\s+/g, ' ').trim().slice(0, 90), isNew: x.classList.contains('is-new')} : null; });
  return {ok: got && !!post && post.isNew, info: `button count "${count}"; post ${JSON.stringify(post)}`};
});
await t('FD-UI-FLOAT', 'On a narrower screen the Company feed button opens the window over the page; Escape closes it again', async () => {
  await p.setViewportSize({width: 1280, height: 800}); await home();
  const before = await geo();
  await p.locator('[data-feed-pill]').click(); await p.waitForTimeout(600); const open = await geo();
  await p.keyboard.press('Escape'); await p.waitForTimeout(300); const after = await geo();
  await p.setViewportSize({width: 1920, height: 1080}); await home();
  return {ok: before.mode === 'pill' && open.mode === 'floating' && open.inView && open.posts > 0 && after.mode === 'pill', info: `${before.mode} → ${open.mode} (${JSON.stringify(open.box)}) → ${after.mode}`};
});
await t('FD-UI-OPEN', 'Choosing the invoice post opens that invoice; the feed is not shown on other screens and returns on the Dashboard', async () => {
  if ((await geo()).mode !== 'docked') { await p.locator('[data-feed-pill]').click(); await p.waitForTimeout(600); }
  await p.locator('[data-feed-filter="sales"]').click(); await p.waitForTimeout(1200);
  await p.locator('[data-feed-open="invoice"]').first().click();
  await p.waitForFunction(() => !/tegh=dashboard/.test(location.hash), null, {timeout: 20000}); await p.waitForTimeout(1500);
  const page = await p.evaluate(() => ({hash: location.hash.slice(0, 50), text: [...document.querySelectorAll('.srp-page')].pop()?.innerText.slice(0, 400) || '', feedHidden: document.querySelector('[data-company-feed]')?.hidden, pill: !!document.querySelector('[data-feed-pill]:not([hidden])')}));
  await home(); const back = await geo();
  return {ok: /INV-\d+/.test(page.text) && /Harbour Bakery/.test(page.text) && page.feedHidden === true && !page.pill && back.mode === 'docked', info: `${page.hash}; invoice shown ${/INV-\d+/.test(page.text)}; feed hidden there ${page.feedHidden}, button ${page.pill}; back on Dashboard ${back.mode}`};
});
await t('FD-UI-KEY', 'Keyboard: a post can be reached and opened with Enter; Escape minimises the window', async () => {
  await p.locator('[data-feed-filter="all"]').click(); await p.waitForTimeout(1200);
  await p.locator('[data-feed-post].is-link').first().focus(); const focused = await p.evaluate(() => document.activeElement?.matches('[data-feed-post].is-link'));
  await p.keyboard.press('Escape'); await p.waitForTimeout(300); const g = await geo();
  await p.locator('[data-feed-pill]').click(); await p.waitForTimeout(600);
  await p.locator('[data-feed-post].is-link').first().focus(); await p.keyboard.press('Enter');
  const left = await p.waitForFunction(() => !/tegh=dashboard/.test(location.hash), null, {timeout: 20000}).then(() => true, () => false);
  await home();
  return {ok: focused && g.mode === 'pill' && left, info: `focusable ${focused}; Escape → ${g.mode}; Enter opened a record ${left}`};
});
await t('FD-UI-GUIDED', 'Guided Home shows the same feed and it covers nothing there (the Month-End Checklist shortcut stays clickable)', async () => {
  await p.evaluate(() => TeghPortal.switchBookkeepingMode('owner')); await p.waitForTimeout(2000);
  await p.evaluate(() => TeghPortal.openGuidedBookkeeping()); await shown('[data-feed-pill]:not([hidden]), [data-feed-panel]:not([hidden])'); await p.waitForTimeout(2000);
  const g = await geo(); await p.screenshot({path: `${shots}/r171-feed-guided.png`});
  const sc = p.locator('[data-guided-shortcut]').last(); const name = await sc.textContent().catch(() => '');
  const clickable = await sc.click({trial: true, timeout: 5000}).then(() => true, () => false);
  await p.evaluate(() => TeghPortal.switchBookkeepingMode('accountant')); await p.waitForTimeout(2500);
  return {ok: g.mode !== 'none' && g.covered.length === 0 && clickable, info: `${g.mode}; covered ${JSON.stringify(g.covered)}; shortcut "${(name || '').trim()}" clickable ${clickable}`};
});
await t('FD-JS', 'No browser errors on the desktop', async () => ({ok: errs.length === 0, info: errs.join(' | ') || 'none'}));
await b.close();

// Phone
const ph = await open(CID, {w: 390, h: 844});
await t('FD-PH', 'Phone 390×844: a Company feed button in the page heading; it opens a full-screen sheet with the posts; minimising returns to the Dashboard; no sideways scroll', async () => {
  const P = ph.p;
  await P.waitForSelector('[data-feed-pill]:not([hidden])', {timeout: 30000}); await P.waitForTimeout(1200);
  const pill = await P.evaluate(() => { const r = document.querySelector('[data-feed-pill]').getBoundingClientRect(); return {inHeading: !!document.querySelector('[data-feed-pill]').closest('header.srp-page-head'), visible: r.width > 0 && r.right <= innerWidth}; });
  await P.locator('[data-feed-pill]').tap(); await P.waitForSelector('[data-feed-post]', {timeout: 30000}); await P.waitForTimeout(600);
  const sheet = await P.evaluate(() => { const r = document.querySelector('[data-feed-panel]').getBoundingClientRect(), se = document.scrollingElement; return {box: [r.x, r.y, r.width, r.height].map(Math.round), pageX: se.scrollWidth - se.clientWidth}; });
  await P.screenshot({path: `${shots}/r171-feed-phone.png`});
  await P.locator('[data-feed-close]').tap(); await P.waitForTimeout(400);
  const closed = await P.evaluate(() => document.querySelector('[data-feed-panel]').hidden);
  return {ok: pill.visible && pill.inHeading && sheet.box[2] === 390 && sheet.box[3] === 844 && sheet.pageX <= 1 && closed, info: JSON.stringify({pill, sheet, closed})};
});
await t('FD-PH-JS', 'No browser errors on the phone', async () => ({ok: ph.errs.length === 0, info: ph.errs.join(' | ') || 'none'}));
await ph.b.close();
save('r171.json');

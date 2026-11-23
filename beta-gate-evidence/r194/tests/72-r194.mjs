// R194: the Tegh mark on the sign-in and first-company screens shows the T; the first-company screen (Canadian
// Guided/Full choice, Canadian company form, India company form) offers Sign out, which ends the session and returns
// to sign-in; the Invoice Intelligence job list answers and expires a lease that has run out. Synthetic data only.
import {S, sql, rec, save, need} from './lib.mjs';
import {chromium} from '/opt/node22/lib/node_modules/playwright/index.mjs';

const A = 'R194';
const t = async (id, title, fn) => { try { const r = await fn(); rec(id, A, title, r.ok ? 'PASS' : 'FAIL', r.info); return r.ok; } catch (e) { rec(id, A, title, 'FAIL', String(e.message || e).slice(0, 400)); return false; } };
const PW = 'Gate!Owner#2026pw', MPW = 'Gate!Member#2026pw';
const o = new S(); await o.login('owner@gate.test', PW);
const release = async () => (await o.call('site-admin', {company: false})).b.groups.release;
const setRelease = async (k, v) => { const cur = await release(); return o.call('site-admin/save', {method: 'POST', company: false, json: {groups: {release: {value: {...cur.value, [k]: v}, revision: cur.revision}}, reason: 'R194 gate: India edition for the first-company check'}}); };
const startIndia = (await release()).value.indiaEdition || 'off';
const tag = Date.now().toString(36);
// A user with no company: a copy of the synthetic otherowner account (same password) under a new id and email.
const EMAIL = `r194-first-${tag}@gate.test`, UID = `user_r194${tag}`.padEnd(37, '0');
sql(`CREATE TEMPORARY TABLE r194u AS SELECT * FROM users WHERE email='otherowner@gate.test'; UPDATE r194u SET id='${UID}', email='${EMAIL}'; INSERT INTO users SELECT * FROM r194u`);

let browser;
const open = async (ed, w, h) => {
  const ctx = await browser.newContext({viewport: {width: w, height: h}, isMobile: w < 800, hasTouch: w < 800, ignoreHTTPSErrors: true});
  const p = await ctx.newPage(); const errs = [];
  p.on('pageerror', e => errs.push(e.message.slice(0, 160)));
  await p.goto('https://gate.test/app.html?edition=' + ed.toLowerCase()); await p.waitForSelector('#sr-login-form', {timeout: 30000});
  if (await p.locator('#sr-login-form [name=edition]').count()) await p.selectOption('#sr-login-form [name=edition]', ed);
  await p.fill('#sr-login-form input[name=email]', EMAIL); await p.fill('#sr-login-form input[name=password]', MPW); await p.click('#sr-login-form button[type=submit]');
  await p.waitForSelector('[data-first-signout]', {timeout: 45000}); await p.waitForTimeout(800);
  return {ctx, p, errs};
};
const look = p => p.evaluate(() => { const m = document.querySelector('.auth-shell .brand-mark'); const bar = document.querySelector('[data-first-signout]'); const r = bar?.getBoundingClientRect();
  return {mark: !!m && /tegh-mark\.svg/.test(getComputedStyle(m).backgroundImage), bar: !!bar && bar.offsetParent !== null, text: (bar?.innerText || '').replace(/\s+/g, ' ').trim(), inView: !!r && r.left >= 0 && r.right <= innerWidth + 1,
    screen: document.querySelector('[data-in7-first]') ? 'india' : document.querySelector('[data-first-experience-choice]') ? 'choice' : document.querySelector('.company-create:not([hidden]),.company-wizard:not([hidden])') ? 'canadian-form' : 'other',
    overflow: document.documentElement.scrollWidth > innerWidth + 1}; });
const signOut = async p => { await p.click('[data-first-signout-button]'); await p.waitForSelector('#sr-login-form', {state: 'visible', timeout: 20000});
  return p.evaluate(async () => (await fetch('/api/index.php?route=auth/me', {cache: 'no-store', headers: {'X-SR-Test-Run': '1'}})).status); };

try {
  need('india mode', await setRelease('indiaEdition', 'everyone'));
  browser = await chromium.launch({executablePath: '/opt/pw-browsers/chromium', args: ['--no-proxy-server', '--host-resolver-rules=MAP gate.test 127.0.0.1']});

  await t('UI-01', 'Canada, no company: the Tegh mark shows the T and the Guided/Full choice offers Sign out ("Signed in as" the email) at 1440 px and 390 px; Sign out ends the session (auth/me 401) and returns to sign-in', async () => {
    const res = {};
    for (const [w, h] of [[1440, 900], [390, 844]]) {
      const {ctx, p, errs} = await open('CA', w, h);
      try { res[w] = await look(p); await p.screenshot({path: `/srv/gate/ev/shots/r194-ca-choice-${w}.png`}); res[w].me = await signOut(p); res[w].errs = errs; } finally { await ctx.close(); }
    }
    return {ok: Object.values(res).every(x => x.mark && x.bar && x.screen === 'choice' && x.text.includes(EMAIL) && /Sign out/.test(x.text) && x.inView && !x.overflow && x.me === 401 && !x.errs.length), info: JSON.stringify(res)};
  });

  await t('UI-02', 'Canada, no company: after choosing Full Accounting the Canadian company form keeps Sign out, and Sign out from it ends the session', async () => {
    const {ctx, p, errs} = await open('CA', 1440, 900); let got;
    try { await p.getByText('Open Full Accounting').click(); await p.waitForTimeout(1200); got = await look(p); got.me = await signOut(p); } finally { await ctx.close(); }
    return {ok: got.screen === 'canadian-form' && got.bar && got.me === 401 && !errs.length, info: JSON.stringify({got, errs})};
  });

  await t('UI-03', 'India, no India company: the India company form offers Sign out at 1440 px and 390 px, and Sign out ends the session', async () => {
    const res = {};
    for (const [w, h] of [[1440, 900], [390, 844]]) {
      const {ctx, p, errs} = await open('IN', w, h);
      try { res[w] = await look(p); await p.screenshot({path: `/srv/gate/ev/shots/r194-in-form-${w}.png`}); res[w].me = await signOut(p); res[w].errs = errs; } finally { await ctx.close(); }
    }
    return {ok: Object.values(res).every(x => x.mark && x.bar && x.screen === 'india' && x.inView && !x.overflow && x.me === 401 && !x.errs.length), info: JSON.stringify(res)};
  });

  await t('R194-01', 'Signing out created no company for the user', async () => {
    const n = Number(sql(`SELECT COUNT(*) FROM company_members WHERE user_id='${UID}'`));
    return {ok: n === 0, info: `memberships ${n}`};
  });

  await t('R194-02', 'Invoice Intelligence job list answers and expires a lease that has run out (job back in the queue)', async () => {
    const job = sql(`SELECT CONCAT(id,'|',company_id,'|',state,'|',attempts,'|',max_attempts) FROM invoice_intel_jobs WHERE state IN ('ready','review_required','queued') AND attempts < max_attempts ORDER BY created_at LIMIT 1`);
    if (!job) return {ok: false, info: 'no Invoice Intelligence job on this installation (45-r170 creates them)'};
    const [id, co, state] = job.split('|');
    sql(`UPDATE invoice_intel_jobs SET state='processing', lease_token='r194', leased_by='r194', lease_expires_at=UTC_TIMESTAMP() - INTERVAL 5 MINUTE WHERE id='${id}'`);
    let list, after;
    try { list = await o.call('invoice-intel/jobs', {headers: {'X-Company-Id': co}, company: false}); after = sql(`SELECT CONCAT(state,'|',IFNULL(lease_token,'-')) FROM invoice_intel_jobs WHERE id='${id}'`); }
    finally { sql(`UPDATE invoice_intel_jobs SET state='${state}', lease_token=NULL, leased_by=NULL, lease_expires_at=NULL WHERE id='${id}'`); }
    return {ok: list.s === 200 && Array.isArray(list.b?.jobs) && after === 'queued|-', info: `list ${list.s} (${list.b?.jobs?.length} jobs); job after list ${after}; restored to ${state}`};
  });
} finally {
  if (browser) await browser.close().catch(() => {});
  await setRelease('indiaEdition', startIndia).catch(() => {});
  try { sql(`UPDATE users SET active=0 WHERE id='${UID}'`); } catch {}
  save('r194.json');
}

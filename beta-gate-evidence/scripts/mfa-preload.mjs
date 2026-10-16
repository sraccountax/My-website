// R164: makes every Playwright page in the gate suites MFA-aware without editing each suite.
// Loaded with NODE_OPTIONS="--import /srv/gate/t/mfa-preload.mjs". All suites import chromium from
// the same absolute module, so patching the shared chromium object reaches every launch.
//
// For each page: when the sign-in form posts auth/login, the user's MFA rows are reset (so the server
// asks a privileged account to enrol rather than verify an unknown secret). If the response is an MFA
// challenge, the handler drives the real two-step screen: confirm password -> read the setup key from
// the page -> type the current TOTP -> continue past the recovery codes. Accounts that do not need MFA
// are untouched.
import {chromium} from '/opt/node22/lib/node_modules/playwright/index.mjs';
import {execFileSync} from 'child_process';
import crypto from 'crypto';

const DB = process.env.GATE_DB || 'tegh_gate';
const sql = q => execFileSync('mysql', [DB, '-N', '-B', '-e', q], {encoding: 'utf8'}).trim();
const quote = v => "'" + String(v).replace(/'/g, "''") + "'";
let OFF = 0;
try { OFF = Number(execFileSync('bash', ['-lc', "grep -hE 'env\\[FAKETIME\\]' /etc/php/8.3/fpm/pool.d/*.conf 2>/dev/null | grep -oE '\\-?[0-9]+' | head -1"], {encoding: 'utf8'}).trim() || '0') || 0; } catch {}
const step = () => Math.floor((Date.now() / 1000 + OFF) / 30);
function b32(s) { const al = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; s = String(s).toUpperCase().replace(/[^A-Z2-7]/g, ''); let bits = 0, val = 0; const out = []; for (const ch of s) { val = (val << 5) | al.indexOf(ch); bits += 5; if (bits >= 8) { bits -= 8; out.push((val >> bits) & 0xff); } } return Buffer.from(out); }
function totp(secret, st = step()) { const buf = Buffer.alloc(8); buf.writeBigUInt64BE(BigInt(st)); const h = crypto.createHmac('sha1', b32(secret)).update(buf).digest(); const o = h[h.length - 1] & 15; const n = ((h[o] & 127) << 24) | (h[o + 1] << 16) | (h[o + 2] << 8) | h[o + 3]; return String(n % 1e6).padStart(6, '0'); }
const isLogin = u => { try { const url = new URL(u); return url.searchParams.get('route') === 'auth/login' || /\/api\/auth\/login\/?$/.test(url.pathname); } catch { return false; } };

async function completeMfa(page, password) {
  const form = '#sr-mfa-form';
  await page.waitForSelector(`${form}:not([hidden])`, {timeout: 20000});
  const enrolling = await page.locator(`${form} [data-mfa-view="enrol"]`).isVisible();
  if (!enrolling) throw new Error('MFA verify view shown although enrolment was reset');
  await page.fill(`${form} [name=enrolPassword]`, password);
  await page.click(`${form} [data-mfa-begin]`);
  await page.waitForFunction(sel => (document.querySelector(sel)?.textContent || '').trim().length >= 16, `${form} [data-mfa-secret]`, {timeout: 20000});
  const secret = (await page.textContent(`${form} [data-mfa-secret]`)).trim();
  for (let attempt = 0; attempt < 2; attempt++) {
    await page.fill(`${form} [name=enrolCode]`, totp(secret));
    await page.click(`${form} [data-mfa-confirm]`);
    try { await page.waitForSelector(`${form} [data-mfa-recovery-done]`, {state: 'visible', timeout: 12000}); break; }
    catch (e) { if (attempt) throw e; const s0 = step(); while (step() <= s0) await new Promise(r => setTimeout(r, 1000)); }
  }
  await page.click(`${form} [data-mfa-recovery-done]`);
}

function attach(page) {
  if (page.__teghMfa) return; page.__teghMfa = true;
  let pending = null;
  page.route(u => isLogin(String(u)), async route => {
    const req = route.request();
    if (req.method() === 'POST') {
      try {
        const body = req.postDataJSON() || {};
        pending = {password: String(body.password || '')};
        const uid = body.email ? sql('SELECT id FROM users WHERE email=' + quote(body.email)) : '';
        if (uid) { sql(`DELETE FROM user_mfa WHERE user_id='${uid}'`); sql(`DELETE FROM user_mfa_recovery_codes WHERE user_id='${uid}'`); }
      } catch (e) { console.error('[mfa-preload] reset failed:', e.message); }
    }
    await route.fallback();
  }).catch(() => {});
  page.on('response', async resp => {
    if (!isLogin(resp.url()) || resp.request().method() !== 'POST' || !pending) return;
    const creds = pending; pending = null;
    let body = null; try { body = await resp.json(); } catch { return; }
    if (!body || !body.mfaRequired) return;
    try { await completeMfa(page, creds.password); }
    catch (e) { console.error('[mfa-preload] MFA completion failed:', e.message); }
  });
}

const origLaunch = chromium.launch.bind(chromium);
chromium.launch = async (...args) => {
  const browser = await origLaunch(...args);
  const origNewContext = browser.newContext.bind(browser);
  browser.newContext = async (...a) => { const ctx = await origNewContext(...a); ctx.on('page', attach); return ctx; };
  const origNewPage = browser.newPage.bind(browser);
  browser.newPage = async (...a) => { const p = await origNewPage(...a); attach(p); return p; };
  return browser;
};

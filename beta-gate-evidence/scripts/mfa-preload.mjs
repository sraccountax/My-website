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
// R164: setup and invitation acceptance can also return a session that must enrol MFA before use.
const isAccountStart = u => { try { const url = new URL(u); const r = url.searchParams.get('route') || url.pathname.replace(/^.*\/api\//, ''); return /^(auth\/setup|platform\/invite-accept)$/.test(r); } catch { return false; } };

// R164 (revised): the second step is a 6-digit code emailed to the account. Read it from the test mailbox and enter
// it in the real sign-in form, as the person would. "Trust this device" is left unticked: each browser context is new.
import fs from 'fs';
const decodeMail = t => t + '\n' + [...t.matchAll(/Content-Transfer-Encoding: base64\s*\n\s*\n([A-Za-z0-9+\/=\s]+?)(?:\n--|$)/g)].map(m => Buffer.from(m[1].replace(/\s+/g, ''), 'base64').toString('utf8')).join('\n');
const mailFiles = () => fs.readdirSync('/srv/gate/mail').sort();
async function emailedCodes(email, since) {
  // Every code emailed to this account since this sign-in started, oldest first. Parallel browsers signing in as the
  // same account each get their own code (bound to their own sign-in), so the right one is found by trying them.
  const t0 = Date.now();
  while (Date.now() - t0 < 20000) {
    const codes = [];
    for (const f of mailFiles().slice(since)) {
      const t = decodeMail(fs.readFileSync('/srv/gate/mail/' + f, 'utf8'));
      if (t.includes(email)) { const m = t.match(/sign-in code is (\d{6})/); if (m) codes.push(m[1]); }
    }
    if (codes.length) return codes;
    await new Promise(r => setTimeout(r, 250));
  }
  return [];
}

function attach(page) {
  if (page.__teghMfa) return; page.__teghMfa = true;
  let since = 0;
  page.on('request', req => { if (isLogin(req.url()) && req.method() === 'POST') since = mailFiles().length; });
  page.on('response', async resp => {
    if (!isLogin(resp.url()) || resp.request().method() !== 'POST' || globalThis.__teghMfaManual) return; // a test that checks the screen itself
    let creds = {}; try { creds = JSON.parse(resp.request().postData() || '{}'); } catch {}
    let body = null; try { body = await resp.json(); } catch { return; }
    if (!body || !body.mfaRequired) return;
    try {
      const email = String(creds.email || '');
      await page.waitForSelector('#sr-mfa-form:not([hidden])', {timeout: 20000});
      const tried = new Set(); let accepted = false;
      for (let round = 0; round < 3 && !accepted; round++) {
        for (const code of (await emailedCodes(email, since)).filter(c => !tried.has(c))) {
          tried.add(code);
          const answer = page.waitForResponse(r => /auth(%2F|\/)mfa(%2F|\/)verify/.test(r.url()), {timeout: 15000});
          await page.fill('#sr-mfa-form input[name=code]', code);
          await page.click('#sr-mfa-form [data-mfa-verify]');
          if ((await answer).status() === 200) { accepted = true; break; }
        }
        if (!accepted) await new Promise(r => setTimeout(r, 1000));
      }
      if (!accepted) throw new Error('no accepted sign-in code for ' + email + ' (tried ' + tried.size + ')');
      // Test database only: browser contexts carry no trusted-device cookie, so suites sign the same account in far
      // more often than a person would. Remove that account's already-used codes so the product's limit of 10 codes per
      // 15 minutes (tested in 34-mfa E-05) is not reached by the harness itself. The current code is untouched.
      try { sql('DELETE o FROM user_email_otp o JOIN users u ON u.id=o.user_id WHERE u.email=' + quote(String(creds.email || '')) + ' AND o.used_at IS NOT NULL'); } catch {}
    } catch (e) { console.error('[mfa-preload] sign-in code entry failed:', e.message); }
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

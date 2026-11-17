// R180: 46 unused files removed. The removed files are gone from the install; every page in the package loads with no
// missing file (no 404 or 403 on the site itself); the signed-in app, its Appearance previews and the import samples
// still load.
import {rec, save} from './lib.mjs';
import {open} from './e2e/base.mjs';
import {chromium} from '/opt/node22/lib/node_modules/playwright/index.mjs';
import fs from 'fs';
import path from 'path';

const A = 'R180', WWW = '/srv/gate/www';
const ids = JSON.parse(fs.readFileSync('/srv/gate/t/ids.json'));
const t = async (id, title, fn) => { try { const r = await fn(); rec(id, A, title, r.ok ? 'PASS' : 'FAIL', r.info); return r.ok; } catch (e) { rec(id, A, title, 'FAIL', String(e.message || e).slice(0, 400)); return false; } };
const man = JSON.parse(fs.readFileSync(WWW + '/RELEASE-MANIFEST.json'));
const removed = man.r180RemovedFiles || [];

await t('CL-01', 'The 46 removed files are listed in the release manifest and are not on the installed site', async () => {
  const still = removed.filter(f => fs.existsSync(path.join(WWW, f)));
  return {ok: removed.length === 46 && !still.length, info: `${removed.length} listed; ${still.length ? 'still present: ' + still.join(', ') : 'none present'}`};
});

const pages = fs.readdirSync(WWW).filter(f => f.endsWith('.html')).concat(fs.readdirSync(WWW + '/guides').filter(f => f.endsWith('.html')).map(f => 'guides/' + f)).sort();
const browser = await chromium.launch({executablePath: '/opt/pw-browsers/chromium', args: ['--no-proxy-server', '--host-resolver-rules=MAP gate.test 127.0.0.1']});
const missing = [], asked = [];
for (const pg of pages) {
  const ctx = await browser.newContext({ignoreHTTPSErrors: true}); const p = await ctx.newPage();
  p.on('response', r => { const u = r.url(); if (u.startsWith('https://gate.test/') && !/\/api\//.test(u) && [403, 404].includes(r.status())) missing.push(`${pg}: ${r.status()} ${u.replace('https://gate.test', '')}`); });
  p.on('request', r => { const u = r.url().replace('https://gate.test/', '').split('?')[0]; if (removed.includes(u)) asked.push(`${pg}: ${u}`); });
  await p.goto('https://gate.test/' + pg, {waitUntil: 'networkidle', timeout: 45000}).catch(e => missing.push(`${pg}: ${String(e.message).slice(0, 80)}`));
  await p.evaluate(() => window.scrollTo(0, document.body.scrollHeight)).catch(() => {}); await p.waitForTimeout(400);
  await ctx.close();
}
await t('CL-02', `Every page in the package (${pages.length}) loads with no missing file and asks for none of the removed files`, async () => ({
  ok: !missing.length && !asked.length, info: `${pages.length} pages; ${missing.length ? 'missing: ' + missing.slice(0, 6).join(' | ') : 'no 404/403'}; ${asked.length ? 'asked for removed: ' + asked.slice(0, 4).join(' | ') : 'no removed file requested'}`}));
await browser.close();

await t('CL-03', 'Signed in: the dashboard, the Appearance previews and the import sample downloads still load with no missing file', async () => {
  const {b, p} = await open(ids.BC); const bad = [];
  p.on('response', r => { const u = r.url(); if (u.startsWith('https://gate.test/') && [403, 404].includes(r.status())) bad.push(`${r.status()} ${u.replace('https://gate.test', '')}`); });
  await p.reload(); await p.waitForFunction(() => window.TeghPortal && document.querySelector('.sidebar,.topbar'), null, {timeout: 40000}); await p.waitForTimeout(2500);
  const r = await p.evaluate(async () => {
    const shots = ['side', 'top'].flatMap(l => ['light', 'dark'].flatMap(th => ['comfortable', 'large'].flatMap(tx => ['comfortable', 'compact'].map(d => `/assets/appearance-r25/${l}-${th}-${tx}-${d}.webp`))));
    const st = await Promise.all(shots.map(async u => (await fetch(u)).status));
    const samples = ['Customer', 'Vendor', 'Journal-Entry'].map(n => `/sample-data/Tegh-5.1.0-Test-Company/Tegh-${n}-Import-Sample.csv`);
    const ss = await Promise.all(samples.map(async u => (await fetch(u)).status));
    return {shots: shots.length, shotsOk: st.filter(x => x === 200).length, samplesOk: ss.filter(x => x === 200).length};
  });
  await b.close();
  return {ok: r.shotsOk === 16 && r.samplesOk === 3 && !bad.length, info: `appearance ${r.shotsOk}/16, samples ${r.samplesOk}/3; ${bad.length ? 'missing: ' + bad.slice(0, 5).join(' | ') : 'no 404/403'}`};
});
save('r180.json');

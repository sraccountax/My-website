// R179: PaddleOCR (PP-OCRv5 mobile, ONNX in the browser) for Document Intake, with Tesseract as the fallback.
// Integrity of the bundled models and runtime; field accuracy on the seven synthetic documents; which engine read them;
// Tesseract takes over when PaddleOCR is missing or a file was changed; nothing leaves the site; the Document Intake
// screen fills in the low-contrast café receipt that Tesseract misread.
import {sql, rec, save} from './lib.mjs';
import {open} from './e2e/base.mjs';
import {chromium} from '/opt/node22/lib/node_modules/playwright/index.mjs';
import fs from 'fs';
import crypto from 'crypto';

const A = 'R179';
const ids = JSON.parse(fs.readFileSync('/srv/gate/t/ids.json'));
const t = async (id, title, fn) => { try { const r = await fn(); rec(id, A, title, r.ok ? 'PASS' : r.info?.startsWith?.('INFO') ? 'INFO' : 'FAIL', r.info); return r.ok; } catch (e) { rec(id, A, title, 'FAIL', String(e.message || e).slice(0, 400)); return false; } };
const WWW = '/srv/gate/www', DX = '/srv/gate/t/conv/dx', DXE = JSON.parse(fs.readFileSync(DX + '/expected.json'));
const sha = p => crypto.createHash('sha256').update(fs.readFileSync(p)).digest('hex');

await t('PO-01', 'The bundled runtime and models match their manifest, the manifest matches the hash pinned in the code, and the licence notices are installed', async () => {
  const man = JSON.parse(fs.readFileSync(WWW + '/assets/tegh-ocr/paddle-r179/manifest.json'));
  const pinned = (fs.readFileSync(WWW + '/assets/tegh-paddle-ocr-r179.js', 'utf8').match(/MANIFEST_SHA256 = '([a-f0-9]{64})'/) || [])[1];
  const bad = man.runtimeAssets.filter(a => { const p = WWW + a.path; return !fs.existsSync(p) || fs.statSync(p).size !== a.sizeBytes || sha(p) !== a.sha256; }).map(a => a.role);
  const legal = man.legalAssets.filter(a => !fs.existsSync(WWW + a.path) || sha(WWW + a.path) !== a.sha256).map(a => a.path);
  return {ok: pinned === sha(WWW + '/assets/tegh-ocr/paddle-r179/manifest.json') && !bad.length && !legal.length && man.providerRequests === 0 && man.execution === 'browser-only' && man.modelSources.length === 6,
    info: `pinned manifest ${pinned === sha(WWW + '/assets/tegh-ocr/paddle-r179/manifest.json')}; ${man.runtimeAssets.length} runtime files${bad.length ? ', bad: ' + bad.join(', ') : ' verified'}; ${man.legalAssets.length} notices${legal.length ? ', bad: ' + legal.join(', ') : ''}; ${man.engine.conversion}`};
});

const browser = await chromium.launch({executablePath: '/opt/pw-browsers/chromium', args: ['--no-proxy-server', '--host-resolver-rules=MAP gate.test 127.0.0.1']});
const fresh = async (setup) => {
  const ctx = await browser.newContext({ignoreHTTPSErrors: true});
  const q = await ctx.newPage(); const reqs = [], warns = [];
  q.on('request', r => reqs.push(r.url())); q.on('console', m => { if (/PaddleOCR/.test(m.text())) warns.push(m.text().slice(0, 200)); });
  if (setup) await setup(q);
  await q.goto('https://gate.test/404.html'); await q.addScriptTag({url: '/assets/tegh-native-ocr-v5220.js?v=gate-r179'});
  return {ctx, q, reqs, warns};
};
const extract = (q, k) => q.evaluate(async ({b64, type}) => {
  const bytes = Uint8Array.from(atob(b64), c => c.charCodeAt(0));
  try { const r = await window.TeghNativeOCR.extract(new File([bytes], 'd', {type})); return {method: r.extractionMethod, engine: r.diagnostics.ocrEngine, c: r.candidate}; }
  catch (e) { return {method: 'error: ' + String(e.message).slice(0, 120), c: {}}; }
}, {b64: fs.readFileSync(`${DX}/${k}.${DXE[k].kind === 'png' ? 'png' : 'pdf'}`).toString('base64'), type: DXE[k].kind === 'png' ? 'image/png' : 'application/pdf'});
const score = (k, c) => Object.entries(DXE[k].want).map(([f, v]) => { const got = f === 'lineItems' ? (c.lineItems || []).length : f === 'alternatives' ? (c.alternatives || []).map(a => a.documentDate) : c[f] ?? ''; return {f, ok: v && v.re ? new RegExp(v.re).test(got) : JSON.stringify(got) === JSON.stringify(v), got}; });

// All seven documents with PaddleOCR in place.
const results = {};
{
  const {ctx, q, reqs} = await fresh();
  for (const k of Object.keys(DXE)) { const t0 = Date.now(); results[k] = {...await extract(q, k), ms: Date.now() - t0}; }
  const offsite = reqs.filter(u => !u.startsWith('https://gate.test/'));
  await ctx.close();
  for (const k of Object.keys(DXE)) {
    const r = results[k], s = score(k, r.c), bad = s.filter(x => !x.ok);
    const wantEngine = DXE[k].kind === 'pdf' ? null : 'paddleocr';
    await t('PO-' + k, `Document Intake reads ${k} (${DXE[k].kind}) with every field right${wantEngine ? ', using PaddleOCR' : ''}`, async () => ({
      ok: !bad.length && (wantEngine ? r.engine === wantEngine : true) && !/^error/.test(r.method),
      info: `${r.method}, engine ${r.engine || 'none (PDF text)'}, ${r.ms} ms; ${bad.length ? 'misses ' + bad.map(x => x.f + '=' + JSON.stringify(x.got)).join(', ') : s.length + ' fields right'}`}));
  }
  await t('PO-LOCAL', 'Reading the seven documents sends nothing off the site (every request is to gate.test)', async () => ({ok: !offsite.length, info: `${reqs.length} requests, ${offsite.length} elsewhere${offsite.length ? ': ' + offsite.slice(0, 3).join(', ') : ''}`}));
}

await t('PO-FALLBACK', 'Without the PaddleOCR files, Document Intake still reads photos with Tesseract (receipts I3 and I5 fully right)', async () => {
  const {ctx, q, warns} = await fresh(async q => { await q.route(/\/assets\/(tegh-ocr\/paddle-r179\/|tegh-paddle-ocr-r179\.js)/, r => r.abort()); });
  const out = {};
  for (const k of ['I3', 'I5']) out[k] = await extract(q, k);
  await ctx.close();
  const right = k => score(k, out[k].c).every(x => x.ok);
  return {ok: out.I3.engine === 'tesseract' && out.I5.engine === 'tesseract' && right('I3') && right('I5'), info: `I3 ${out.I3.engine} ${right('I3') ? 'right' : 'wrong'}; I5 ${out.I5.engine} ${right('I5') ? 'right' : 'wrong'}; ${warns.length} fallback notes`};
});
await t('PO-TAMPER', 'A changed model file is refused: PaddleOCR does not use it and Tesseract reads the page instead', async () => {
  const {ctx, q, warns} = await fresh(async q => {
    await q.route(/ppocrv5-mobile-rec-latin\.onnx/, async r => { const res = await r.fetch(); const body = Buffer.from(await res.body()); body[body.length - 10] ^= 0xff; await r.fulfill({response: res, body}); });
  });
  const r = await extract(q, 'I5');
  await ctx.close();
  return {ok: r.engine === 'tesseract' && warns.some(w => /paddle_integrity_failed|did not pass verification/.test(w)), info: `engine ${r.engine}; note: ${warns[0] || 'none'}`};
});

// The real screen: Payables › Document Intake, upload the café receipt photo, open its review.
await t('PO-UI', 'Document Intake screen: the low-contrast café receipt photo fills in Maple Leaf Cafe, 9.00 + 1.17 = 10.17', async () => {
  const {b, p} = await open(ids.BC);
  await p.evaluate(() => { document.querySelectorAll('.srp-page').forEach(x => x.dataset.gateOld = '1'); TeghPortal.invokeMenuAction('Payables', 'Document Intake'); });
  await p.waitForFunction(() => { const pg = [...document.querySelectorAll('.srp-page[data-srp-page="document-intake"]')].find(x => !x.dataset.gateOld); return pg && pg.dataset.teghRenderState === 'ready' && pg.querySelector('[data-native-document-upload]'); }, null, {timeout: 30000});
  const form = p.locator('[data-native-document-upload]');
  if (!await form.locator('button[type=submit]').isVisible()) { await p.locator('summary:visible', {hasText: /^Upload document/}).first().click(); await form.locator('button[type=submit]').waitFor({state: 'visible', timeout: 10000}); }
  await form.locator('select[name=documentType]').selectOption('vendor_bill', {force: true});
  await form.locator('input[name=file]').setInputFiles(`${DX}/I6.png`); await form.locator('button[type=submit]').click();
  const rb = p.locator('[data-r22-review-key]', {hasText: 'I6.png'}).first(); await rb.waitFor({timeout: 120000}); await rb.click();
  const card = p.locator('[data-native-document]').filter({has: p.locator('input[name=partyName][value="MAPLE LEAF CAFE"]')}).first(); await card.waitFor({timeout: 60000});
  const v = n => card.locator(`[name=${n}]`).inputValue();
  const got = [await v('partyName'), await v('documentNumber'), await v('documentDate'), await v('subtotalCents'), await v('taxCents'), await v('totalCents'), (await card.locator('[data-amount-check]').textContent()).trim()];
  await p.screenshot({path: '/srv/gate/ev/shots/r179-intake-cafe.png'});
  await b.close();
  return {ok: JSON.stringify(got) === JSON.stringify(['MAPLE LEAF CAFE', '7781', '2026-08-21', '9.00', '1.17', '10.17', 'Subtotal + tax = total ✓']), info: got.join(' | ')};
});

await t('PO-LIC', 'Help › Open Source / AI Licences lists PaddleOCR, ONNX Runtime, Tesseract and PDF.js, and every licence link opens', async () => {
  const {b, p} = await open(ids.BC);
  await p.evaluate(() => { history.pushState(null, '', '#tegh=tegh-ai-licences'); dispatchEvent(new PopStateEvent('popstate')); });
  await p.waitForSelector('[data-r179-ocr-licences]', {timeout: 20000});
  const r = await p.evaluate(async () => { const pg = [...document.querySelectorAll('.srp-page')].pop(); const links = [...pg.querySelectorAll('a[href^="/assets/tegh-ocr/"]')].map(a => a.getAttribute('href'));
    const st = await Promise.all(links.map(async u => (await fetch(u)).status)); return {text: pg.textContent, links: links.length, bad: links.filter((u, i) => st[i] !== 200), stale: /No third-party model weights are distributed/.test(pg.textContent)}; });
  await b.close();
  const names = ['PaddleOCR PP-OCRv5', 'ONNX Runtime Web', 'Tesseract.js', 'PDF.js'].filter(n => !r.text.includes(n));
  return {ok: !names.length && r.links >= 7 && !r.bad.length && !r.stale, info: `${r.links} licence links${r.bad.length ? ', broken: ' + r.bad.join(', ') : ' open'}${names.length ? '; missing ' + names.join(', ') : ''}; old wording ${r.stale}`};
});

await browser.close();
save('r179.json');

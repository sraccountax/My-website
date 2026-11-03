// R173 public-page sweep on the local preview: every public page at six widths; browser errors, failed requests,
// sideways scroll, web fonts actually loaded, header/footer present, and internal links that do not resolve.
import {createRequire} from 'module'; const {chromium} = createRequire('/opt/node22/lib/node_modules/')('playwright');
import fs from 'fs';
const root = process.env.ROOT || '/home/user/My-website/tegh-app', base = process.env.BASE || 'http://127.0.0.1:8173/';
const pages = fs.readdirSync(root).filter(f => f.endsWith('.html') && !['app.html', 'client-view.html', 'startup-recovery.html'].includes(f)).concat(fs.readdirSync(root + '/guides').map(f => 'guides/' + f));
const b = await chromium.launch(); const out = [], links = new Set();
for (const w of [320, 390, 768, 1024, 1440, 1920]) {
  const ctx = await b.newContext({viewport: {width: w, height: 900}, isMobile: w < 800, hasTouch: w < 800});
  for (const pg of pages) {
    const p = await ctx.newPage(); const errs = [];
    // No database in the preview: answer API calls as a signed-out visitor gets them, so only page problems remain.
    await p.route(/\/api\/index\.php/, r => { const route = new URL(r.request().url()).searchParams.get('route') || '';
      return route === 'auth/security' ? r.fulfill({status: 200, contentType: 'application/json', body: '{"publicSignupEnabled":false}'})
        : r.fulfill({status: 200, contentType: 'application/json', body: '{}'}); });
    p.on('console', m => { if (m.type() === 'error') errs.push(m.text().slice(0, 120)); });
    p.on('pageerror', e => errs.push('js: ' + e.message.slice(0, 120)));
    p.on('requestfailed', r => { if (/127\.0\.0\.1/.test(r.url())) errs.push('failed: ' + r.url().slice(-60)); });
    p.on('response', r => { if (r.status() >= 400 && /127\.0\.0\.1/.test(r.url()) && !/\/api\//.test(r.url()) && !(pg === '404.html' && r.url().endsWith('404.html'))) errs.push(r.status() + ' ' + r.url().slice(-60)); });
    await p.goto(base + pg, {waitUntil: 'load'}); await p.waitForTimeout(700);
    const m = await p.evaluate(async () => { await document.fonts.ready; return {
      over: document.documentElement.scrollWidth - document.documentElement.clientWidth,
      fonts: ['Manrope', 'Playfair Display'].filter(f => !document.fonts.check(`16px "${f}"`)),
      h1font: getComputedStyle(document.querySelector('h1') || document.body).fontFamily.split(',')[0],
      header: !!document.querySelector('.site-header'), footer: !!document.querySelector('.site-footer'),
      links: [...document.querySelectorAll('a[href]')].map(a => a.getAttribute('href'))}; });
    m.links.forEach(l => { if (!/^(https?:|mailto:|tel:|#)/.test(l)) links.add(new URL(l, base + pg).pathname); });
    const probs = [...errs]; if (m.over > 0) probs.push('sideways ' + m.over + 'px'); if (m.fonts.length) probs.push('font not loaded: ' + m.fonts);
    if (!/Playfair/.test(m.h1font)) probs.push('h1 font ' + m.h1font); if (!m.header || !m.footer) probs.push('header/footer missing');
    out.push({pg, w, probs}); await p.close();
  }
  await ctx.close();
}
const bad = []; for (const l of links) { const r = await fetch(base.replace(/\/$/, '') + l, {redirect: 'manual'}).catch(() => ({status: 'ERR'})); if (![200, 301, 302, 304].includes(r.status)) bad.push(`${l} ${r.status}`); }
await b.close();
const issues = out.filter(o => o.probs.length);
console.log(`${pages.length} pages × 6 widths = ${out.length} renders; ${issues.length} with problems`);
issues.forEach(o => console.log(`  ${o.pg} @${o.w}: ${o.probs.join(' | ')}`));
console.log(`internal links checked ${links.size}; not resolving ${bad.length}${bad.length ? ': ' + bad.join(', ') : ''}`);
fs.writeFileSync(process.argv[2] || '/dev/null', JSON.stringify({out, links: [...links], bad}, null, 1));

import {createRequire} from 'module'; const {chromium} = createRequire('/opt/node22/lib/node_modules/')('playwright');
// Usage: node shot173.mjs <outdir> <prefix> <url>... ; sizes via SIZES env "1440x900,390x844"
const [,, out, prefix, ...urls] = process.argv;
const sizes = (process.env.SIZES || '1440x900,390x844').split(',').map(s => s.split('x').map(Number));
const b = await chromium.launch();
const errs = [];
for (const u of urls) {
  for (const [w, h] of sizes) {
    const p = await b.newPage({viewport: {width: w, height: h}});
    p.on('pageerror', e => errs.push(u + ' ' + e.message.slice(0, 120)));
    // The local preview has no database: answer the two sign-in-page requests the way a signed-out visit gets them.
    await p.route(/\/api\/index\.php/, r => {
      const route = new URL(r.request().url()).searchParams.get('route') || '';
      if (route === 'auth/security') return r.fulfill({status: 200, contentType: 'application/json', body: JSON.stringify({publicSignupEnabled: false, captchaEnabled: false, loginFailureThreshold: 3})});
      return r.fulfill({status: 401, contentType: 'application/json', body: JSON.stringify({error: 'Sign in to continue.', code: 'unauthenticated'})});
    });
    try { await p.goto(u, {waitUntil: 'load', timeout: 30000}); } catch (e) { errs.push('nav ' + u + ' ' + e.message.slice(0, 80)); }
    await p.waitForTimeout(1800);
    const name = u.replace(/^https?:\/\/[^/]+\/?/, '').replace(/\.html$/, '').replace(/[^a-z0-9]+/gi, '_') || 'home';
    const full = process.env.FULL === '1';
    await p.screenshot({path: `${out}/${prefix}-${name}-${w}.png`, fullPage: full});
    const hs = await p.evaluate(() => document.documentElement.scrollWidth - innerWidth);
    if (hs > 0) errs.push(`${u} @${w}: sideways scroll ${hs}px`);
    await p.close();
  }
}
console.log(errs.length ? errs.join('\n') : 'no page errors, no sideways scroll');
await b.close();

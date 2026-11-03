# R150 changes over R149: public pages always open in the light design

5.9.9 / Build 5990 / Schema 46. No application, server or database change. The app cache token stays `5990-r149-tegh`; the public-page stylesheets load with `?v=5990-r150`.
Status: **staging candidate**, `productionReady: false`.

## Problem (owner report, 2026-10-05)
"index.html is opening the page in dark mode." Since R27 the public pages followed the phone or computer's dark-mode setting (`@media (prefers-color-scheme: dark)` in `tegh-marketing-v5900.css` and `tegh-marketing-v4140.css`). On a device set to dark mode, the home page and every other public page opened dark. The dark version was also partly unreadable: grey haze over the hero text and faint labels. Android browsers that darken pages automatically (Chrome's "auto dark mode", Samsung Internet) had the same effect.

## Fix
- The dark-mode blocks were removed from both public-page stylesheets. They now declare `color-scheme: only light`.
- All 17 public pages carry `<meta name="color-scheme" content="only light">`. This also stops browsers from darkening them automatically.
- The stylesheet links on those pages use `?v=5990-r150`, so browsers fetch the new file instead of a cached copy.
- The app (`app.html`) and client viewing page are unchanged. The app keeps its own Light / Dark / System setting.

## The main URL opening app.html
This was fixed in R147: `.htaccess` no longer sends `/` to `app.html`. The owner's "Tegh-Updated-Package" ZIP (SHA-256 `6fb6db08…28dd`) predates that fix. It still contains `RewriteRule ^$ /app.html [R=302,L]`, and every file in it is mode 0600. Deploy this package (R150), not that ZIP, and upload `.htaccess` too.

## Checks
In a browser set to dark mode, and again with Chrome's forced dark mode, all 17 public pages at 390×844 render light. Before: 34 of 34 dark. After: 0 of 34. Script: `beta-gate-evidence/scripts/dark-check.mjs`.

## Files
`assets/tegh-marketing-v5900.css`, `assets/tegh-marketing-v4140.css`, the 17 public pages (`index.html`, `product.html`, `ask-tegh.html`, `solutions.html`, `subscriptions.html`, `resources.html`, `company.html`, `security.html`, `contact.html`, `migration.html`, `privacy.html`, `terms.html`, `404.html`, `guides/*.html`), plus `R150-CHANGES.md`, README, DEPLOYMENT-NOTES and the manifests.

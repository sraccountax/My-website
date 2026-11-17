# R140 changes over R139: vendor invoice scrolling on phones

5.9.9 / Build 5990 / Schema 46. Cache token `5990-r140-tegh` (client view page unchanged: `5990-r135`). No migration.
Status: **staging candidate**, `productionReady: false`. The host acceptance items are still open.

## Fix
**The New Vendor Invoice form could not be scrolled on phones.**

**Cause:** on the vendor invoice page, the form's layout box was set as its own scroll box (`overflow-y: auto; overscroll-behavior: contain`). It was not the page's designated scroll area, so it had nothing of its own to scroll. On phones (notably iOS Safari) a swipe that started on the form was caught by that box, and `contain` stopped it from reaching the page. The customer invoice form was not affected: there the same box is either the designated scroll area (desktop) or not a scroll box (phone).

**Fix:** `assets/tegh-r120.css`. An invoice or vendor-invoice layout that is not the page's designated scroll area (`r22-fill-path`) is no longer a scroll box. The page or workspace scrolls, as on the customer invoice form. Desktop behaviour is unchanged.

## Files
- `assets/tegh-r120.css`
- Cache token bumped in `app.html`, `assets/tegh-gate-v5990.js` and `assets/tegh-preflight-v5990.js`
- Docs: README.txt, DEPLOYMENT-NOTES.txt, R140-CHANGES.md
- Manifests: FILE-MANIFEST.sha256 and the release manifests

## Verified (gate host: Apache + PHP 8.3 HTTPS, exact R140 ZIP, fresh database)
- **Swipe-trap scan:** every one of the 46 menu screens at 390×844 was scanned for non-scrolling boxes that capture swipes. Before: 1 (New Vendor Invoice). After: 0.
- **Touch swipe and wheel scroll:** tested at 1440×900, 1024×768, 768×1024, 390×844 and 360×800. The vendor invoice form scrolls at every size. Its layout now matches the customer invoice form on phones and tablets. The customer invoice form is unchanged.
- **Full gate suite:** re-run on the exact ZIP; see the R140 addendum in FINAL-BETA-QA-REPORT.md (source repository).
- **Not tested:** Chromium only. iOS Safari was not available here, so the owner should check the fix on an iPhone (see DEPLOYMENT-NOTES).

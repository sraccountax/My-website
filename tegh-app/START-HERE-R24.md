# R24 — Navigation repair: staging deployment

**Complete cumulative application; not deployed or production approved.**

Version **5.9.9 / Build 5990 / Schema 46 / R24**, cache **5990-r24**.
Built from the exact R23 unified-registers package (SHA-256 `dbd0b4c7154872f9132b32c2908709806a02b3314ea69d78880364bee1cb34ba`). Includes the R20 Schema 46 invoice/report services and R21 manual opening-balance confirmation fix.

## Install safely

1. Back up the existing application, database and private document storage together. Use staging and fictional QA data first.
2. Confirm the database is already Schema 46 using the protected owner preflight. R24 adds no migration. A retained Schema 45 database still needs the existing protected Schema 46 upgrade; do not run fresh-install SQL over retained data.
3. Extract this deployment ZIP. Upload its contents into the existing application root containing `app.html`, preserving the assets folder hierarchy. Replace supplied managed application files, but **do not delete the site or its assets directory**. Preserve private configuration, uploaded documents, logs and host-specific files.
4. Do not upload the separate source/test/evidence ZIP. It is an offline development bundle.
5. Open a fresh Tegh tab and hard-refresh (Ctrl+F5 on Windows). Confirm `tegh-build.json` reports R24, Schema 46, cache 5990-r24. Confirm the new `assets/tegh-navigation-r24.css` loads after the three existing application stylesheets, and the gate loads JS with the same cache revision.
6. In Profile → Appearance & density, select Top navigation to use the horizontal module menus, or keep sidebar navigation. Existing saved layout preference is preserved; this update does not forcibly change your preferred navigation mode.

## Navigation acceptance on staging

- Top navigation: visible module buttons, direct Activity/Report menu links and an adaptive More menu; no substitute Modules select or extra left rail. No horizontal panning to reach utilities.
- Side navigation: expanded and collapsed menus keep one company, search, Create, Assist, bell, accounting-mode control and Profile. Long company names have a complete tooltip.
- Small screens (820 CSS px and below): use the navigation drawer, the labelled bottom Create control, and Profile for accounting mode/Assist. Close/Escape/resize must restore the company control and keyboard focus with no remaining inert page.
- Switch Guided ↔ Full Accounting using the original mode control. Menus must reflect the selected mode rather than a second conflicting label.
- Check current company, allowed/denied roles, real datasets, browser zoom, final table rows, invoice table actions/columns/exports, Send/Edit/Attachments and one-confirmation manual opening balances. Opening a navigation menu must not submit a financial transaction.

## Rollback

When database/storage schema is unchanged, restore the complete matching R23 managed application backup, including its app.html, gate, metadata, CSS references and manifest. Remove only R24-added managed files if needed; never delete private configuration or uploads. Hard-refresh clients. If any database/storage changes require recovery, restore the coordinated application/database/private-storage backup, not a mixed set of versions.

The local test report and screenshots are supplied separately. Historical release documents in this cumulative archive do not certify R24. Actual staging login/cache, host permissions, other browsers and full application acceptance are not claimed.

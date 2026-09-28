# Tegh 5.9.9 R28 Step 16S HOLD report

## Release decision

**HOLD — do not promote to production yet.**

R28 Step 16S is a cumulative full-application candidate built from the live
S16R premium Day Book baseline. It repairs the shared native scrollbar-thumb
geometry affecting reports, registers, reconciliation and long menus. The exact
S16S bytes have not yet been deployed, so real pointer acceptance remains a
release blocker.

## Baseline control

| Field | Verified value |
|---|---|
| Product | Tegh Accounting & Payroll |
| Version | 5.9.9 |
| Build | 5990 |
| Schema | 46 |
| Hosted baseline revision | `5990-r28-s16r-premium-day-book` |
| S16S candidate revision | `5990-r28-s16s-native-report-scroll` |
| Full source baseline ZIP | `Tegh-5.9.9-Build-5990-Schema-46-R28-STEP-16-LIVE-TEST-REPAIR.zip` |
| Full source baseline SHA-256 | `8eec6281892ec15837221f3999d779b0ab5abc5670f167701e0b343e219a86f8` |
| Git commit | Not available |
| Database migration | None; Schema 46 preserved |

The active `app.html` includes nine cache-revisioned startup/layout assets. The
gate, preflight, portal route cache, build manifest and release manifest all use
the S16S revision.

## Defect register

| Issue | Severity | Route/control | Previous behaviour | Verified root cause | Coding change | Result |
|---|---|---|---|---|---|---|
| UI-02 / S16S-01 | Blocker | Reports and registers; reproduced on General Ledger → Day Book | Real overflow existed and PageDown worked, but wheel and pointer thumb drag did not move the owner. | `assets/tegh-r27.css` forced a 44px minimum width and height on a 16px native thumb, invalidating the cross-axis pointer hit geometry. | Base thumb keeps colour/border only; `:vertical` receives 44px height/0 width and `:horizontal` receives 44px width/0 height. | Fixed in source; hosted candidate retest pending. |

Full reproduction metrics and the active-file line review are in
`verification/SCROLL-CODE-REVIEW-S16S.md`.

## Exact active source changed from S16R

- `assets/tegh-r27.css` — shared orientation-correct native scrollbar thumbs
- `app.html` — S16S cache revision on all nine active startup/layout tags
- `assets/tegh-preflight-v5990.js` — S16S revision
- `assets/tegh-gate-v5990.js` — S16S revision
- `assets/tegh-portal-v5990.js` — S16S route-cache namespace
- `tegh-build.json`, `RELEASE-MANIFEST.json`, `PACKAGE-MANIFEST.json` — release metadata
- `verification/r28_s16s_verify.py` — focused regression guard
- `verification/build_s16s_manifest.py` — cumulative full-tree manifest builder
- `verification/SCROLL-CODE-REVIEW-S16S.md`, this report and generated manifests

No posting, journal, matching, tax, payroll, invoice, payment, import or schema
logic was changed.

## Automated results

| Gate | Result |
|---|---|
| S16S focused checks | PASS — 64/64 |
| General syntax/sanity | PASS — 25/25 |
| JavaScript parse sweep | PASS — 77/77 |
| Large register dataset | PASS — 25,000 rows / 500 pages / balances preserved |
| CRA reference vectors | PASS — Ontario and British Columbia retained vectors |
| Protected accounting/schema files | PASS — 11/11 byte-identical |
| Clean archive extraction | PASS — flat root; 422/422 hashes; focused, sanity, JS, CRA and large-data gates repeated |
| PHP lint | NOT EXECUTED — PHP CLI unavailable |

## Manual browser results

| Build | Route | Viewport / zoom | Interaction | Result |
|---|---|---|---|---|
| Hosted S16R | Day Book | 1363×936 / 100% | Render and accounting totals | PASS — 225 transactions, 502 GL lines, debit=credit `$183,270.99` |
| Hosted S16R | Day Book | 1363×936 / 100% | PageDown | PASS — principal owner moved 486px |
| Hosted S16R | Day Book | 1363×936 / 100% | Wheel | FAIL — reproduced; owner remained at 0 |
| Hosted S16R | Day Book | 1363×936 / 100% | Vertical thumb drag | FAIL — reproduced; owner remained at 486 |
| S16S candidate | Reports matrix | Required viewports / zooms | Wheel, trackpad, track click, vertical/horizontal thumb drag and touch | NOT EXECUTED — candidate not deployed |

The full application inventory, route/control coverage matrix and prior S16R
Day Book implementation evidence remain in the cumulative package's S16Q/S16R
reports. Those unexecuted matrix cells are not upgraded to PASS by this repair.

## Accounting regression result

The repair is presentation-only. The 11 protected accounting, workflow and
Schema 46 controls are byte-identical to the verified baseline, the 25,000-row
balance test passes and the retained payroll reference vectors pass. Live
MariaDB posting/reversal/reconciliation acceptance was not executed and remains
a release gate.

## Deployment instructions

1. Back up the current web root and database; retain the deployed S16R ZIP.
2. Verify the supplied S16S ZIP against its companion `.sha256` file.
3. Extract into an empty temporary directory and confirm `app.html`, `api/`,
   `assets/`, `verification/` and the manifests are at the archive root.
4. Overwrite the complete staging application from that clean extraction. Do
   not copy only `tegh-r27.css` and do not mix S16S with older asset revisions.
5. Purge CDN/server caches if present, then hard-refresh the browser.
6. Confirm active asset URLs contain
   `v=5990-r28-s16s-native-report-scroll`.
7. Run the smoke test below before considering production promotion.

## Post-deployment smoke test

- Open Reports Centre and scroll its category list by wheel, trackpad, track
  click and thumb drag.
- Open Day Book with at least 200 rows; drag the vertical thumb from top to
  bottom, horizontally drag the table at narrow widths and reach the last row.
- Repeat on General Ledger, Trial Balance, Cash Forecast, bank reconciliation
  and a long Message & Details/dropdown surface.
- Test 1920×1080 and 1366×768, supported tablet/mobile widths and browser zoom
  at 100%, 125%, 150% and 200%.
- Repeat Light, Dark and System themes; Guided and Full Accounting modes;
  expanded, collapsed and top navigation.
- Verify PageDown/PageUp/Home/End, focus visibility and scroll-position
  preservation after filtering/sorting.
- Verify no page-level horizontal scrollbar and no clipped final record/action.
- Confirm debit equals credit and perform the authorized fictional accounting
  regression workflows before promotion.

## Rollback

If any gate fails, restore the complete S16R web-root backup and purge caches.
Schema rollback is not required because S16S adds no migration. Confirm the
active revision returns to `5990-r28-s16r-premium-day-book`, then record the
failed route, viewport, zoom, theme and interaction before further changes.

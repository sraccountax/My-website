# R28 Step 16T — scroll hit-testing HOLD report

## Release decision

**HOLD.** This cumulative candidate is not represented as production-ready or deployed. The exact S16T bytes must be uploaded to the authenticated test slot and pass native pointer scrolling before release.

| Item | Value |
|---|---|
| Product | Tegh Accounting & Payroll |
| Version / build / schema | 5.9.9 / 5990 / 46 |
| Live package tested | S16S |
| Live cache revision | `5990-r28-s16s-native-report-scroll` |
| Candidate cache revision | `5990-r28-s16t-scroll-hit-testing` |
| Candidate type | Cumulative full application, flat root |
| Database migration | None |

## Live reproduction evidence

The S16S upload was positively identified from all nine active startup/layout asset URLs. Testing used the authenticated application at `books-test.sraccountax.ca`, 1363×936, 100% browser zoom.

| Issue | Route / owner | Input | Before | After | Result |
|---|---|---|---:|---:|---|
| UI-02 | Day Book / `.r28-daybook-scroll` | Page Down | 0 | 486 | PASS |
| UI-02 | Day Book / `.r28-daybook-scroll` | Mouse wheel | 0 | 0 | FAIL |
| UI-02 | Day Book / `.r28-daybook-scroll` | Scrollbar track click | 0 | 0 | FAIL |
| UI-02 | Day Book / `.r28-daybook-scroll` | Scrollbar thumb drag | 0 | 0 | FAIL |
| UI-02 | Reports Centre / `.r27-report-groups` | Page Down | 0 | 518 | PASS |
| UI-02 | Reports Centre / `.r27-report-groups` | Mouse wheel | 0 | 0 | FAIL |
| UI-02 | Reports Centre / `.r27-report-groups` | Scrollbar track click | 0 | 0 | FAIL |
| UI-02 | Reports Centre / `.r27-report-groups` | Scrollbar thumb drag | 0 | 0 | FAIL |
| Control | Public long page | Mouse wheel | 0 | 650 | PASS |
| Control | Public long page | Scrollbar track click | 650 | 819 | PASS |
| Control | Public long page | Scrollbar thumb drag | 819 | 6951 | PASS |

The control eliminates the browser pointer driver as the explanation. Day Book had a 1057×540 client region with 1057×11526 scroll content; Reports Centre had a 1039×732 client region with 1039×1250 scroll content. Both were genuine native overflow regions.

## Verified shared root cause

The routed page had class `tegh-motion-settled`, but computed style still reported:

- `animation-name: teghPremiumPageIn`
- `transform: matrix(1, 0, 0, 1, 0, 0)`

The premium selector in `assets/tegh-app-v5990.css` is more specific than the old `.srp-page.tegh-motion-settled` rule. With `animation-fill-mode: both`, it retained a transformed compositor layer after motion completed. The Day Book and Reports Centre scroll owners also computed `contain: layout paint`. Hit testing reached the scroll element itself and no overlay, dialog, scrim, wheel listener or pointer-events rule intercepted input.

## Candidate repair

`assets/tegh-r27.css` now:

- retires the premium animation, transform and `will-change` layer after `.tegh-motion-settled` using a selector that wins the cascade;
- removes paint containment from report, register, reconciliation and banking scroll owners;
- retains native overflow, visible 16px scrollbars and travel-axis thumb sizing;
- does not add wheel handlers or custom JavaScript scrolling.

All active startup and layout URLs, the preflight revision, gate revision and portal route-cache scope use `5990-r28-s16t-scroll-hit-testing`.

## Files changed from S16S

- `app.html`
- `assets/tegh-r27.css`
- `assets/tegh-preflight-v5990.js`
- `assets/tegh-gate-v5990.js`
- `assets/tegh-portal-v5990.js`
- `tegh-build.json`
- `RELEASE-MANIFEST.json`
- `PACKAGE-MANIFEST.json`
- `R28-S16T-HOLD-REPORT.md`
- `verification/SCROLL-CODE-REVIEW-S16T.md`
- `verification/r28_s16t_verify.py`
- `verification/build_s16t_manifest.py`
- generated manifests

## Release blockers

1. Upload and verify the exact S16T cache revision.
2. Retest wheel, trackpad, scrollbar-track click and vertical/horizontal thumb drag in Day Book, Reports Centre, banking, registers, dialogs and long menus.
3. Complete the declared viewport, browser-zoom, mode, theme and role matrix.
4. Run PHP lint and live accounting/workflow regression tests in the authorized environment.
5. Capture S16T after screenshots only after the hosted bytes pass.

## Automated candidate results

| Check | Result |
|---|---|
| S16T focused source/protected-file checks | 68/68 PASS |
| Release syntax/sanity | 25/25 PASS |
| JavaScript and module parse | 78/78 PASS |
| CRA 2026 reference vectors | PASS |
| 25,000-row pagination/balance preservation | PASS |
| Clean extraction full-tree hashes | 426/426 PASS |
| PHP lint | NOT EXECUTED — PHP executable unavailable |
| S16T hosted pointer acceptance | NOT EXECUTED — candidate not yet uploaded |
| Live accounting workflow acceptance | NOT EXECUTED |

## Deployment

Extract the ZIP into a new staging directory, verify the companion SHA-256, preserve the flat root, then atomically replace the current application files. Do not merge individual assets into an older tree. No database migration is required.

## Rollback

Restore the complete previously deployed S16S package as one unit. Do not mix S16S HTML/cache metadata with S16T CSS or JavaScript. Verify the restored revision from the active asset URLs after cache invalidation.

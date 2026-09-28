# Tegh 5.9.9 Build 5990 — R28 Step 16Q Hosted Acceptance Repair

## Release decision: HOLD

This is a cumulative, flat-root deployment candidate, not an approved production release. The exact S16P package was uploaded to the authenticated Tegh test host and exercised at 1363×936/100%. That hosted pass verified the main profile, Day Book, Cash Forecast, Guided Month-End, Transfers, selector and long-menu repairs, and exposed three additional defects. S16Q fixes those shared root causes. The S16Q bytes have not yet been deployed, so repaired row actions, Notifications and dark Preferences require hosted retest. The complete viewport/zoom/role matrix, PHP lint and live accounting-posting workflows remain **NOT EXECUTED**. No claim of S16Q deployment or production readiness is made.

Release can proceed only after the exact S16Q ZIP in this delivery is deployed to the authenticated test slot and the release-gate checklist in this report passes.

## 1. Baseline inspection

| Item | Verified value | Evidence/result |
|---|---|---|
| Application URL | `https://books-test.sraccountax.ca/app.html` | Authenticated browser inspection |
| Live product/version/build | Tegh Accounting & Payroll / `5.9.9` / `5990` | Live HTML/runtime |
| Previous live asset revision | `5990-r28-s16o-viewport-action-menus` | Initial live HTML dataset and active asset URLs |
| Uploaded/hosted revision tested | `5990-r28-s16p-ui-stabilization` | Active URLs on `books-test.sraccountax.ca`; authenticated browser pass |
| Schema | `46` | Supplied full-package and release manifests; no database migration added |
| Full supplied baseline ZIP | `Tegh-5.9.9-Build-5990-Schema-46-R28-STEP-16-LIVE-TEST-REPAIR.zip` | SHA-256 `8eec6281892ec15837221f3999d779b0ab5abc5670f167701e0b343e219a86f8` |
| Applied replacement lineage | R28 Step 16J, 16K, 16L, 16M, 16N, 16O, in that order | Supplied replacement-only ZIPs; 16O matches the deployed revision name |
| Supplied audited sources | Portal, registers, R27 CSS and corrected R22 activity shell | Compared and overlaid only after the S16O replacement chain |
| Candidate revision | `5990-r28-s16q-hosted-acceptance-repair` | `app.html`, gate, preflight, portal, `tegh-build.json`, release manifest |
| Git commit | Not available | No `.git` metadata in the supplied application or deployment package |
| Active entry | `app.html` | Direct inspection |
| Active startup scripts | `tegh-preflight-v5990.js`, `tegh-gate-v5990.js` | Script tags in `app.html` |
| Active required UI modules | advanced, sites shell, downloads, activity R22, registers R23, invoice reports R20, portal, professional output, workflow, workspace R17/R18, R27 | Dynamic import allowlist in `tegh-gate-v5990.js` |
| Active layout styles | foundation R22, activity R22, registers R23, navigation R24, audit R25, R26, R27 | Stylesheet tags in `app.html` |
| Service-worker/cache behaviour | Preflight removes old SR Books caches and unregisters service workers when revision changes; gate imports modules with the current revision | Direct source inspection |

The full ZIP by itself predates the deployed S16O application. It was not treated as the latest source. The matching S16O lineage was reconstructed by applying the supplied J–O replacements in order, followed by the supplied audited source files. S16P was then packaged, uploaded by the user and verified live before the three S16Q repairs were applied.

## 2. Route and component inventory

The inventory below covers every implemented functional area named in the acceptance request. “Static inventory” means the route/control is present in the candidate source and included in the source-based regression review. It does not mean that its full live workflow passed.

| Area | Routes/components inventoried | Candidate coverage status |
|---|---|---|
| Shell and home | Dashboard, logo/Home, top navigation, expanded/collapsed sidebar, mobile navigation, Guided/Full mode, global search, quick actions, global `+ New`, notifications, profile/account/appearance/sign-out | S16P hosted: profile, top/side navigation, Guided/Full, Light/Dark/System, collapsed/expanded, `+ New` and More exercised; Notifications failed accessibility/lifecycle and is repaired in S16Q; mobile and sign-out NOT EXECUTED |
| Command Centre | Tegh Assist/Command Centre, agent centre, contextual actions | Static inventory only |
| Banking | Bank accounts, uploads/imports, Review Transactions, matching, reconciliation, transfers, Banking reports, statement converter | Bank Transfers contextual/direct navigation passed hosted; mutation workflows NOT EXECUTED |
| Receivables | Customers, invoices, receipts/payments, statements, products/services, customer ledger and ageing, credit/refund/allocation paths where exposed | Customer/template/product selectors and Message & Details passed hosted; record mutations NOT EXECUTED |
| Payables | Suppliers/vendors, bills, supplier payments, supplier ledger and ageing, debit/credit/refund/allocation paths where exposed | Static inventory only |
| General ledger | Chart of Accounts, journal entries, Day Book, opening balances, Trial Balance, GIFI, GL detail/account reports | Day Book compact layout, keyboard scrolling, final page and debit/credit totals passed hosted; pointer thumb drag inconclusive; row actions failed and are repaired in S16Q |
| Payroll | Employees, runs, calculator, remittances, history, verification and reports | Static inventory; CRA reference vectors executed |
| Reports | Reports Centre, Profit and Loss, Balance Sheet, Cash Flow, Cash Forecast, tax, ageing, GL, Banking and register reports | Cash Forecast populated height and dark surfaces passed hosted; other report workflows static only |
| Month end | Guided Month-End and Month-End Close | Hosted delayed-navigation race passed at 3.5 seconds; complete workflow NOT EXECUTED |
| Documents | OCR/document intake, attachments, requested downloads | Static inventory only |
| Administration | Company settings/details/setup, preferences, invoice templates, users, roles/permissions, currencies/rates, period locks, advanced features, modules/access | Static inventory only |
| Support/session | Support, FAQ/tutorials, account, security surfaces and sign-out | Profile menu hosted pass; Sign Out deliberately NOT EXECUTED to preserve the authenticated session for acceptance testing |
| Shared controls | Menus, listboxes/selectors, autocomplete, date pickers, overflow/row actions, filters, saved views, column and bulk actions | Profile/More/`+ New`/invoice selectors hosted pass; R23 row/table actions failed and Notifications failed keyboard close/focus; both repaired in S16Q pending redeploy |
| Forms/surfaces | Create/edit customers, suppliers, invoices, bills, products/services, receipts, payments, journals, employees and pay runs; dialogs/drawers | Static inventory only |

## 3. Root-cause defect register

| ID / severity | Route and control | Baseline reproduction | Verified root cause | Exact source/component/selectors | Change and regression coverage | Final result |
|---|---|---|---|---|---|---|
| UI-01 / Blocker | Shell profile/account menu and Sign Out; Full/Guided, Light/Dark/System, expanded/collapsed/sidebar/top navigation, 1363×936, 100% | S16O menu ended at x=1589 while viewport ended at x=1363; 226 px was clipped. | The fixed panel remained inside `.topbar`; `backdrop-filter` created a containing block and stale ancestor ownership. | `assets/tegh-activity-r22.js`: `TeghOverlayManager`; `assets/tegh-workspace-r17.js`; `assets/tegh-portal-v5990.js`; `#tegh-overlay-root`, `.tegh-overlay-surface`, `[data-r17-profile]` | Top-level/top-layer portal; flip/clamp/internal scroll; Escape/arrows/Home/End/Tab; outside-pointer interception; focus restore. | **Fixed and retested** on S16P: rect x=1067–1347 inside 1363 viewport; Escape and outside click close; focus restores; keyboard entry works. Sign Out action itself remains NOT EXECUTED. |
| UI-02 / Blocker | Day Book principal vertical scroll region; Full/Light, 1363×936, 100% | S16O wheel worked but pointer track/thumb input did not. | Global `scrollbar-width: thin` overrode the wider WebKit rule; an unnecessary non-passive wheel interceptor was also installed. | `assets/tegh-r27.css`: principal scroll selector/pseudo-elements; `assets/tegh-activity-r22.js` | Standard native width, 16 px track, 44 px minimum thumb, no wheel interception. | Keyboard PageDown/Home/End/Arrow passed hosted. Computed target is 16 px/44 px and unobstructed. Cloud pointer driver did not move track/thumb or wheel; real-mouse drag is **NOT EXECUTED / inconclusive**, so blocker remains open. |
| UI-03 / High | General Ledger → Day Book | S16O table began at y≈386 after duplicated identity/guidance bands and a wrapped filter row. | Multiple enhancement layers rendered redundant bands; filters and scroll ownership were unconstrained. | `assets/tegh-activity-r22.js`: `finalChrome`; `assets/tegh-r27.css`: `[data-srp-page="day-book"]`; `assets/tegh-registers-r23.js` | Compact filters; one `.r23-scroll`; aligned money columns; counterpart summaries; totals; scroll-state preservation. | **Fixed and retested** on S16P: table starts about y=252, 502 records, final page reachable, debit CAD 183,270.99 equals credit CAD 183,270.99. Remaining viewport/zoom matrix NOT EXECUTED. |
| UI-04 / Blocker | R23 row/table/Day Book action menus | On hosted S16P, opening final-row invoice actions, table actions or Day Book View left `aria-expanded=false` and no overlay. Row and table geometry stayed stable, but the menu was unusable. | R23's global cleanup `MutationObserver` saw the menu removed from its table cell while it was being portaled and immediately called `__r23Close`, despite the node already being reconnected under the overlay root. | `assets/tegh-registers-r23.js`: cleanup observer; `.r23-menu`; `__r23Close` | Cleanup now skips removed nodes whose `node.isConnected` is true, retaining real cleanup for disconnected routes/dialogs. Static S16Q regression check added. | **Fixed in S16Q source; hosted retest NOT EXECUTED** — release blocker. |
| UI-05 / High | Reports → Cash Forecast results | Earlier populated result host could collapse to zero height and leak light surfaces in dark mode. | Zero flex basis/min-height and literal colours conflicted with bounded workspace/theming. | `assets/tegh-r27.css`: `[data-srp-page="cash-forecast"] .r15-forecast` and `.srp-table-wrap` | `min-height:180px`, non-zero flex basis, semantic tokens and mobile fallback. | **Fixed and retested** on S16P dark: 4 records, result wrap 180 px, dark surface `rgb(16,33,28)`, light text `rgb(237,247,243)`. |
| UI-06 / High | Guided Month-End delayed dialog | Earlier late response could block a destination after navigation. | Async completion lacked route/generation/connectivity guards. | `assets/tegh-portal-v5990.js`: `openGuidedMonthEnd`, `closeRouteOwnedOverlays`; `[data-tegh-route-owned-overlay]` | Route-owned cleanup plus page/route/body-connectivity guards. | **Fixed and retested** on S16P: opened route, navigated immediately to Dashboard, waited 3.5 s; no stale dialog/overlay appeared. |
| UI-07 / Medium | Banking contextual navigation | Bank Transfers existed but contextual/direct routing was incomplete. | Route action was omitted or restored to Banking dashboard. | `assets/tegh-portal-v5990.js`: Banking action map/direct route | Adds contextual item and direct `bank-transfers` restore. | **Fixed and retested** on S16P: contextual item present and destination title `Transfers`; posting workflow NOT EXECUTED. |
| UI-08 / High | Active asset/cache revision | Supplied cumulative files and replacements carried multiple revisions. | Overlapping HTML/gate/preflight revisions could cache incompatible assets. | `app.html`, gate, preflight, portal cache key, manifests | All nine active startup/layout tags use `5990-r28-s16q-hosted-acceptance-repair`; route cache is revision-scoped. | S16P cache revision passed hosted. S16Q static coherence passes; hosted S16Q cache test NOT EXECUTED. |
| UI-09 / High | Entity selectors and invoice Message & Details | Regression risk from async rerenders/long menus. | Company/generation guards and bounded details scroll required preservation. | Portal selector guards; activity `asMenu(notes,'Message & details')`; activity CSS | Guards preserved; menu remains viewport-bounded/internal-scrolling. | **Fixed and partially retested**: customer (10), template (5) and product (9) options selected; details menu x=10, y=539, w=440, h=334, overflow auto; Escape restored focus. Supplier/GL/tax/bank/full matrix NOT EXECUTED. |
| UI-10 / High | Notifications menu; shell bell | Hosted S16P menu opened but the actual install path lacked dialog/menu semantics, focus stayed on the bell and Escape did not close. | `installBell` used a legacy bespoke fixed panel and bypassed `TeghOverlayManager`. | `assets/tegh-portal-v5990.js`: `openTeghNotificationCenter`, `installBell`; `.srp-notice-menu`; `assets/tegh-r27.css` notification surfaces | Unified with the shared overlay, adds dialog relationship/state, initial focus, Escape/outside close, focus restoration, viewport bounds, async generation/route guards and semantic theme tokens. | **Fixed in S16Q source; hosted retest NOT EXECUTED**. |
| UI-11 / High | Settings → Appearance; dark/System-dark | Hosted S16P final checkbox labels were `rgb(19,40,34)` on the dark surface; preview caption was `rgb(88,111,102)`. | The R25 light selector had higher specificity than the later generic dark rule. | `assets/tegh-r27.css`: `[data-srp-page="tegh-preferences"] .srp-check`, `.r25-preview-head span`, `figcaption` | Page-scoped dark and auto-dark token overrides with matching/higher specificity. | **Fixed in S16Q source; hosted retest NOT EXECUTED**. |

## 4. Shared-infrastructure changes

- One shared overlay manager now owns profile, top navigation, R17, R22 and R23 menus. It uses a top-level portal, browser top layer where supported, a documented layer scale, viewport collision handling, bounded internal scroll, keyboard handling, outside-click interception and focus restoration.
- Desktop scroll ownership remains bounded; Day Book nominates one record scroll owner. Menus and Cash Forecast results own only their bounded internal scrolling. At the 820 CSS-pixel breakpoint, page-level touch scrolling is restored for the relevant compact layout.
- Native wheel interception was removed. Standard browser scrolling, scrollbar track/thumb input, keyboard input and touch behaviour remain the intended input model.
- Register rerenders reuse the connected scroll node and restore bounded scroll offsets. Route-owned overlays close on navigation, and delayed Guided callbacks validate their page/route/body before rendering.
- Cache revisions are coherent across the entry document, gate, preflight, route cache and manifests. Schema remains 46.

## 5. Exact S16Q files changed

S16Q is cumulative: it contains every S16P change plus these hosted-acceptance repairs and release files. The full baseline comparison is in `verification/CHANGED-FILE-MANIFEST.md`.

| File | Purpose |
|---|---|
| `app.html` | Active startup/layout cache revision |
| `assets/tegh-activity-r22.js` | Shared overlay manager, menu lifecycle, Day Book chrome and native scroll handling |
| `assets/tegh-registers-r23.js` | S16P row-action portal/Day Book work plus S16Q connected-node cleanup guard |
| `assets/tegh-workspace-r17.js` | Profile/create/export menu integration and keyboard relationships |
| `assets/tegh-portal-v5990.js` | S16P top/profile/dialog/Transfers work plus S16Q shared Notifications lifecycle and async guards |
| `assets/tegh-r27.css` | S16P layer/scroll/Day Book/Cash Forecast work plus S16Q notification surfaces and Preferences contrast |
| `assets/tegh-gate-v5990.js` | Candidate asset revision |
| `assets/tegh-preflight-v5990.js` | Candidate cache invalidation revision |
| `tegh-build.json` | HOLD release metadata |
| `RELEASE-MANIFEST.json` | HOLD release metadata |
| `verification/r28_s16q_verify.py` | Focused source/protected-file regression checks |
| `verification/build_s16q_manifest.py` | Reproducible baseline comparison/full-tree manifest builder |
| `PACKAGE-MANIFEST.json` | Package identity, changed-file hashes and acceptance status |
| `R28-S16Q-HOLD-REPORT.md` | This release/evidence report |

`assets/tegh-activity-r22.css` differs from the older full ZIP because it is the supplied S16O replacement asset; it was not modified by S16P.

## 6. Change log

1. Added a common viewport-aware overlay/menu manager and migrated profile, top navigation, R17, R22 and R23 menu families.
2. Restored native scrollbar hit targets and removed JavaScript wheel interception.
3. Compacted Day Book, established a single record-scroll owner, preserved counterpart summaries/totals and retained stable scrolling across safe rerenders.
4. Prevented Cash Forecast result collapse and replaced literal theme surfaces with semantic tokens.
5. Added route ownership and generation/connectivity guards to Guided Month-End.
6. Restored contextual and direct navigation to Bank Transfers.
7. Hosted-tested the exact S16P bytes and recorded results without converting unexecuted coverage into passes.
8. Fixed R23's portal-cleanup race, moved Notifications to the shared overlay lifecycle and corrected dark Preferences specificity.
9. Advanced every active cache identifier to the S16Q revision.
10. Preserved Schema 46 and kept 11 protected accounting/workflow files byte-identical.

## 7. Coverage matrix

Result vocabulary is restricted to the requested values. Source checks do not convert an unexecuted browser test into a pass.

| Route/component | Mode | Theme | Role | Viewport | Zoom | State | Interaction tested | Result | Evidence |
|---|---|---|---|---|---:|---|---|---|---|
| Dashboard/profile menu | Guided + Full | Light/Dark/System | Authenticated demo; role not independently verified | 1363×936 | 100% | Open/close | Bounds, first click, Space/Arrow, Escape, outside click, focus return, expanded/collapsed/sidebar/top | Fixed and retested | S16P hosted measurements and screenshots |
| Dashboard/profile menu full matrix | Guided + Full | Light/Dark/System | All authorized roles required | Required viewports | 100–200% | Open/long/edge/touch | Complete acceptance | Not executed | Only 1363×936/100% and one authenticated role exercised |
| Sign Out | Guided + Full | All | All | Required matrix | 100–200% | Session end | Activate and verify signed-out screen | Not executed | Session preserved for hosted acceptance |
| Day Book | Full Accounting | Light | Authenticated demo; role not independently verified | 1363×936 | 100% | 502 rows/final page | Layout, PageDown/Home/End/Arrow, final record/totals | Fixed and retested | Hosted: scrollTop 0→523→4321; debit=credit CAD 183,270.99 |
| Day Book pointer scroll | Full Accounting | Light | Same | 1363×936 | 100% | Populated | Wheel/track/thumb through cloud pointer driver | Not executed | Driver did not move native scroll; real mouse/equivalent unavailable |
| Shared overlay implementation | All | All | N/A | Source-level | N/A | Long/final-edge | Portal, collision, flip, max-height, keyboard, outside click, restore | Passed | `r28_s16q_verify.py` |
| Shared scroll implementation | All | All | N/A | Source-level | N/A | Principal regions | Native width/target, no wheel interceptor | Passed | `r28_s16q_verify.py` |
| R23 row/table actions S16P | Full Accounting | Light | Authenticated demo | 1363×936 | 100% | Final invoice row/table/Day Book | Open and geometry | Failed | Menu closed immediately; row 53 px and widths stayed stable |
| R23 row/table actions S16Q | All | All | All required | Required matrix | 100–200% | Selected/final/long | Open, target stable ID, no geometry shift | Not executed | Source repaired; S16Q not deployed |
| Global `+ New` and top More | Full Accounting | Light/System | Authenticated demo | 1363×936 | 100% | Populated | Open/bounds/final option | Passed | `+ New` x=817–1057, bottom=658; More x=742–1022 |
| Notifications S16P | Full Accounting | Light | Authenticated demo | 1363×936 | 100% | Populated | Open/Escape/focus/semantics | Failed | Opened at x=925–1345; no role; focus stayed on bell; Escape failed |
| Notifications S16Q | All | All | All required | Required matrix | 100–200% | Long/empty/populated | Bounds, internal scroll, keyboard, outside click, focus return | Not executed | Source repaired; S16Q not deployed |
| Entity and account selectors | Full Accounting | Light | Authenticated demo | 1363×936 | 100% | Populated | Customer/template/product selection | Fixed and retested | 10/5/9 options; selected values persisted |
| Supplier/GL/tax/bank selectors | All | All | All required | Required matrix | 100–200% | Search/long/max | Selection/final option | Not executed | Not reached in hosted timebox |
| Invoice Message & Details | Full Accounting | Light | Authenticated demo | 1363×936 | 100% | Populated | Open/bounds/internal scroll/Escape/focus | Fixed and retested | x=10, y=539, w=440, h=334, overflow auto |
| Cash Forecast | Full Accounting | Dark | Authenticated demo | 1363×936 | 100% | 4 populated rows | Visible results/theme | Fixed and retested | 180 px host; dark surface/light text measurements |
| Guided Month-End | Guided | Light | Authenticated demo | 1363×936 | 100% | Pending navigation | Navigate away; wait 3.5 seconds | Fixed and retested | No stale dialog or route overlay |
| Bank Transfers | Full Accounting | Light | Authenticated demo | 1363×936 | 100% | Contextual/direct route | Navigate to transfer screen | Fixed and retested | Banking item present; title `Transfers` |
| Banking/review/matching/reconciliation | Guided + Full | All | Banking roles | Required matrix | 100–200% | Selected/bulk/final row | Match/Post & Match/Best Matches semantics | Not executed | No live mutation authorized/executed |
| Receivables suite | Guided + Full | All | AR roles | Required matrix | 100–200% | Required states | Routes/forms/selectors/actions | Not executed | Static inventory only |
| Payables suite | Guided + Full | All | AP roles | Required matrix | 100–200% | Required states | Routes/forms/selectors/actions | Not executed | Static inventory only |
| GL/Trial Balance/GIFI/opening balances | Full | All | Accounting roles | Required matrix | 100–200% | Required states | Reports/forms/actions/totals | Not executed | Protected files unchanged; no posting run |
| Payroll suite | Guided + Full | All | Payroll roles | Required matrix | 100–200% | Required states | Forms/runs/history/reports | Not executed | Browser/workflow not run; CRA vectors passed |
| Reports Centre and financial reports | Guided + Full | All | Report roles | Required matrix | 100–200% | Required states | Filter/generate/export/final content | Not executed | Static inventory only |
| OCR/intake/attachments/downloads | Guided + Full | All | Authorized roles | Required matrix | 100–200% | Slow/failure/direct route | Upload/intake/download lifecycle | Not executed | Static inventory only |
| Appearance S16P | Full Accounting | Dark | Authenticated demo | 1363×936 | 100% | Populated | Checkbox/caption contrast | Failed | Dark text caused by R25 specificity |
| Appearance S16Q | Guided + Full | Dark/System-dark | Available roles | Required matrix | 100–200% | Populated | Contrast/readability | Not executed | Source repaired; S16Q not deployed |
| Settings/templates/users/roles/rates/locks/advanced | Guided + Full | All | Available roles | Required matrix | 100–200% | Required states | Navigation/forms/permissions | Not executed | Static inventory only |
| Browser Back/Forward/refresh/direct routes | All | All | All | Required matrix | 100–200% | Pending/overlay open | Navigation lifecycle | Not executed | Candidate not deployed |
| Protected accounting source files | N/A | N/A | N/A | N/A | N/A | Byte comparison | 11 critical files vs verified baseline | Passed | 11/11 SHA-256 matches |
| Large register data | N/A | N/A | N/A | Test runtime | N/A | 25,000 rows / 500 pages | Paging/search/balance preservation | Passed | `r28_step12_large_data_test.js` |
| PHP syntax | N/A | N/A | N/A | Local runtime | N/A | All PHP files | `php -l` | Not executed | PHP executable unavailable |
| Hosted S16P after screenshots | Full/Guided | Light/Dark | Authenticated demo | 1363×936 | 100% | Profile/Day Book | Visual comparison | Passed | Three hosted S16P screenshots listed below |
| Hosted S16Q screenshots | Required modes | Required themes | Required roles | Required matrix | 100–200% | Repaired controls | Visual comparison | Not executed | S16Q not deployed |

## 8. Automated test results

| Test | Result | Detail |
|---|---|---|
| `verification/r28_s16q_verify.py` | Passed | 55/55 S16P contracts plus connected-menu, Notifications, dark Preferences, revision, protected hashes and JS parse checks |
| `verification/syntax_sanity.py` | Passed | 25/25 existing syntax/sanity checks |
| `verification/payroll_cra_reference_check.py` | Passed | 2026 CRA constants plus Ontario and British Columbia complex vectors |
| `verification/r28_step12_large_data_test.js` | Passed | 25,000 rows, 500 pages, search and ledger balances preserved |
| JavaScript syntax | Passed | Portal, activity, registers, workspace, gate and preflight via `node --check` |
| Clean ZIP extraction | Passed | Flat-root `app.html`; all 419 full-tree hashes; 55/55 focused checks; 25/25 sanity checks; CRA vectors and 25,000-row test passed from a new extraction directory |
| PHP lint | Not executed | `php` is not installed in the execution environment |
| Legacy step verifiers | Not applicable | Several assert superseded historical cache IDs/scroll architecture; S16Q has a revision-specific verifier |

## 9. Manual browser results

| Test | Result | Notes |
|---|---|---|
| Authenticate to correct Tegh test app | Passed | Browser Auth used only for the Tegh application; no OpenAI sign-in used |
| Hosted S16P revision/version/build | Passed | `5990-r28-s16p-ui-stabilization`, 5.9.9/5990 verified from active URLs/runtime |
| Profile containment/keyboard/focus | Fixed and retested | Guided/Full, Light/Dark/System, expanded/collapsed/sidebar/top navigation at 1363×936/100% |
| Day Book compactness/keyboard/final totals | Fixed and retested | Table y≈252; PageDown/Home/End/Arrow; final page; debit=credit |
| Day Book real mouse thumb drag | Not executed | Native driver did not move the scrollbar; actual real-mouse evidence unavailable |
| Cash Forecast dark populated results | Fixed and retested | Four records; non-zero 180 px host and readable dark tokens |
| Guided Month-End route race | Fixed and retested | No delayed dialog after immediate Dashboard navigation and 3.5 s wait |
| Bank Transfers navigation | Fixed and retested | Contextual/direct route opens Transfers |
| Invoice customer/template/product selectors | Fixed and retested | All selected successfully; no record saved |
| Invoice Message & Details | Fixed and retested | Viewport-contained, internally scrollable, Escape/focus restore |
| R23 action menus | Failed | Immediate close on S16P; repaired in S16Q pending deployment |
| Notifications | Failed | S16P legacy path lacked Escape/focus/role; repaired in S16Q pending deployment |
| Dark Appearance | Failed | S16P selector specificity caused unreadable labels; repaired in S16Q pending deployment |
| S16Q post-fix browser tests | Not executed | S16Q is not yet deployed |
| Exact required viewport/browser-zoom matrix | Not executed | Only hosted 1363×936 at actual 100% was available; keyboard zoom commands did not change the controlled browser scale |
| Manual touch/mobile keyboard/role matrix | Not executed | Mobile viewport controls and authorized role fixtures were unavailable |

## 10. Accounting regression results

- Schema remains 46; no migration is included.
- Eleven protected accounting/workflow files are byte-identical to the verified baseline: accounting, books, payroll, payments, imports, data imports, interbank, invoice documents, migrations, Schema 46 migration and schema SQL.
- The 25,000-row register test preserved ledger balances.
- The 2026 payroll reference test passed both complex CRA vectors.
- Debit=credit after live posting, AR/AP control-account integrity, reconciliation history, period locks, role boundaries, document numbering, currency posting, tax mapping, idempotency and reversal workflows are **NOT EXECUTED**. No production or customer transaction was changed.

## 11. Evidence

| Evidence | Description | Status |
|---|---|---|
| `tegh-live-before-profile-1790094074231.jpg` | Live S16O Dashboard immediately before opening profile menu; 1363×936 | Available |
| `tegh-live-profile-open-1790094114098.jpg` | Live S16O profile menu clipped beyond the right viewport edge; 1363×936 | Available |
| `tegh-s16p-profile-menu-after-1790098566051.jpg` | S16P profile inside viewport; Full/Light, 1363×936/100% | Available |
| `tegh-s16p-daybook-scroll-after-1790098781118.jpg` | S16P compact Day Book and record scroller | Available |
| `tegh-s16p-profile-dark-after-1790098940281.jpg` | S16P profile in Guided/Dark | Available |
| S16Q after screenshots | Exact S16Q repaired row actions, Notifications and Preferences | Not executed; release blocker |

## 12. Remaining limitations and release blockers

1. Exact S16Q bytes have not been deployed; the three hosted S16P failures repaired in S16Q are not retested.
2. Native scrollbar-thumb drag with a real mouse or equivalent pointer remains NOT EXECUTED.
3. The requested 1920×1080, 1600×900, 1440×900, 1366×768, 1280×720, 1024×768, 820 px, tablet and mobile widths were not run.
4. Actual browser zoom at 125%, 150% and 200% was not run.
5. Dark/System themes, Guided mode and collapsed/top navigation received targeted S16P coverage, but the full route/control matrix, mobile navigation and every authorized role were not run.
6. PHP lint could not run because PHP is unavailable.
7. Live accounting mutation, debit/credit reconciliation, permissions and audit-trail acceptance were not run.

No blocker/high-severity defect is claimed closed until these items pass.

## 13. Package and manifest

- Package type: cumulative full application, flat root.
- Candidate revision: `5990-r28-s16q-hosted-acceptance-repair`.
- Package filename: `Tegh-5.9.9-Build-5990-Schema-46-R28-S16Q-HOSTED-ACCEPTANCE-REPAIR-HOLD.zip`.
- ZIP SHA-256 is delivered in the adjacent `.sha256` file.
- Full package member hashes are in `FILE-MANIFEST.sha256`.
- Release metadata is in `PACKAGE-MANIFEST.json`, `tegh-build.json` and `RELEASE-MANIFEST.json`.

## 14. Staging overwrite instructions

These instructions are for an isolated staging slot only while the package is on HOLD.

1. Record the current deployed directory/archive and database backup point. Do not alter Schema 46.
2. Verify the ZIP SHA-256 against the adjacent `.sha256` file.
3. Extract to a new empty staging directory. Confirm `app.html` is at the extraction root, not inside an extra wrapper directory.
4. Verify every package member with `sha256sum -c FILE-MANIFEST.sha256` from that root.
5. Atomically point the staging virtual host at the new directory, or overwrite the staging tree only after preserving the previous tree.
6. Load `/app.html` in a clean browser profile. Confirm runtime/cache revision `5990-r28-s16q-hosted-acceptance-repair`, version 5.9.9 and build 5990.
7. Run the smoke and full release-gate matrices below. Do not promote if any blocker/high defect remains.

## 15. Post-deployment smoke-test checklist

- [ ] Profile button is visible in top/expanded/collapsed/mobile navigation.
- [ ] Profile menu opens inside the viewport; every option including Sign Out is visible and keyboard/touch reachable.
- [ ] Escape/outside click closes without activating underlying controls; focus returns to the trigger.
- [ ] Sign Out ends the session and displays the signed-out screen.
- [ ] Final-row, table-level and Day Book View menus remain open, flip/clamp and do not change row height or column width.
- [ ] Notifications opens as an accessible, viewport-contained dialog; Escape/outside close and focus returns to the bell.
- [ ] Appearance checkbox labels and preview captions are readable in Dark and System-dark.
- [ ] Long dropdowns and Invoice Message & Details scroll internally to the final control.
- [ ] Day Book starts below the compact filter row; vertical and horizontal native scrollbar thumbs drag with a real pointer.
- [ ] Day Book final record/totals and collapsed GL counterpart are reachable; debit equals credit for the tested source.
- [ ] Cash Forecast populated results have non-zero height and correct Light/Dark/System colours.
- [ ] Navigating away from a delayed Guided Month-End request never produces a stale modal.
- [ ] Bank Transfers appears in Banking and direct links restore the transfer route.
- [ ] Guided/Full, all themes, expanded/collapsed/top/mobile navigation, required viewports and actual zoom through 200% pass.
- [ ] Required roles and permission-denied states pass.
- [ ] PHP lint, automated checks and live accounting regression workflows pass.
- [ ] Browser console has no unhandled errors, observer loops or duplicate-handler symptoms.

## 16. Rollback instructions

1. Stop promotion and capture the failing route, console output, viewport/zoom/theme/mode/role and screenshot.
2. Atomically repoint the staging host to the preserved S16P directory or restore the predeployment file backup.
3. Restore the database only if a separately authorized staging accounting test changed it; this package itself has no migration.
4. Purge the staging CDN/proxy cache and reload in a clean browser profile.
5. Confirm revision `5990-r28-s16p-ui-stabilization`, version 5.9.9, build 5990 and Schema 46.
6. Verify authentication, Dashboard, profile control, Day Book and one read-only financial report before reopening staging.

## 17. Final disposition

The cumulative package is suitable for controlled staging acceptance only. It is **not deployed**, **not production-ready**, and **must remain on HOLD** until the missing browser, PHP and accounting gates pass against the exact packaged bytes.

# Tegh 5.9.9 Build 5990 — R28 Step 16R Premium Day Book

## Release decision: HOLD

S16R is a cumulative, flat-root deployment candidate built from the authenticated live S16Q baseline. It implements the supplied Day Book visual direction without changing Schema 46 or accounting/posting logic. The exact S16R bytes have not been deployed to `books-test.sraccountax.ca`, so no after screenshot or hosted S16R pass is claimed. Production promotion remains blocked until the deployment and browser matrix below are completed.

## 1. Baseline inspection

| Item | Verified value | Evidence/result |
|---|---|---|
| Product | Tegh Accounting & Payroll | Authenticated live application |
| URL | `https://books-test.sraccountax.ca/app.html` | Authenticated browser inspection |
| Version / build / schema | `5.9.9` / `5990` / `46` | Live runtime and supplied manifests |
| Live revision before S16R | `5990-r28-s16q-hosted-acceptance-repair` | Live asset URLs and runtime |
| S16R revision | `5990-r28-s16r-premium-day-book` | Entry document, gate, preflight, portal and manifests |
| Full source baseline | `Tegh-5.9.9-Build-5990-Schema-46-R28-STEP-16-LIVE-TEST-REPAIR.zip` | SHA-256 `8eec6281892ec15837221f3999d779b0ab5abc5670f167701e0b343e219a86f8` |
| Cumulative lineage | R28 Step 16J–16O, S16P, S16Q, then S16R | Changed-file manifest |
| Git commit | Not available | No repository metadata in supplied deployment tree |
| Database migration | None | Schema 46 preserved |

The live S16Q Day Book was inspected before modification. Its shared View menu opened correctly, confirming the S16Q portal-cleanup repair was active. The old Day Book still displayed dense group strips, separate GL-line rows and insufficient visual hierarchy; S16R replaces that renderer at its shared report root.

## 2. Complete route/component inventory

The cumulative route and component inventory is retained in `R28-S16Q-HOLD-REPORT.md`, section 2. It covers Dashboard, Command Centre, shell controls, Banking, Receivables, Payables, General Ledger, Payroll, Reports, Month End, OCR/documents, settings/permissions and support/session controls. S16R changes only the shared Day Book output path and source-action mapping; no route or approved workflow was removed.

## 3. Root-cause defect register

| ID / severity | Route/control | Reproduction and prior behaviour | Root cause | Exact source/component | Coding change | Regression test | Final result |
|---|---|---|---|---|---|---|---|
| UI-03R / High | General Ledger → Day Book; Full Accounting/Light; 1363×936/100% | Open Day Book with 502 GL lines. The page showed dense transaction summary strips mixed with separate GL-line rows. Long text merged visually, transaction hierarchy was weak, and the output did not match the supplied premium ledger reference. | The generic register renderer treated each journal line as a first-class row, then added a second summary band. It could not create one stable transaction row with expandable ledger detail. | `assets/tegh-registers-r23.js`: `renderDayBook`, `dayBookSummary`, `dayBookCards`, `dayBookGroupMenu`; `.r28-daybook-*` | Added a Day Book-specific renderer: four truthful KPI cards, one row per journal/source transaction, expandable complete GL lines, source/status chips, compact viewport-aware actions, transaction pagination, fixed numeric alignment, stable scroll reuse and table-owned horizontal/vertical scrolling. | `verification/r28_s16r_verify.py`; JS parse; 25k-row regression | **Fixed in source; hosted S16R retest NOT EXECUTED** |
| UI-03R-A / High | Day Book source document action | A premium summary row needs to open the correct underlying source without using a line ID. | The professional-output action collector assumed the action node was in the last table cell and did not recognize the source ID marker. | `assets/tegh-professional-output-v5990.js`: `collectReportActions`; `api/report_loaders_v5980.php`: Day Book group summaries | Added source ID, partner and remarks report metadata; collect the existing authorized source action from its actual cell; reuse the existing click handler from the compact action menu. | S16R source-action checks and protected-file hashes | **Fixed in source; hosted retest NOT EXECUTED** |
| UI-03R-B / Medium | Day Book table actions | The fixed premium layout would expose a non-functional Choose columns command. | The shared table menu assumed all register layouts had user-selectable columns. | `assets/tegh-registers-r23.js`: `tableMenu` | Day Book keeps Export, Filter columns and Reset view, but omits Choose columns because the reference layout uses a stable ledger column contract. | S16R static checks | **Fixed** |

## 4. Visual/interaction specification implemented

- Four real-data KPI cards: Debit, Credit, Transactions and Out of balance. No sample or fabricated totals are used.
- One compact transaction row with Date, Ref, Source, Contact/Memo, Account, Debit, Credit, Status and overflow action.
- Pastel source/status pills, right-aligned tabular monetary values, hover/highlight states and theme tokens.
- Disclosure on the reference opens every authorized GL line for the journal; the collapsed row keeps the preferred GL counterpart.
- The action menu uses the shared top-level overlay manager, so opening it does not resize the row or table.
- Pagination counts transactions rather than GL lines and defaults to 50 transactions per page.
- One native `.r23-scroll` owner handles records; the table owns horizontal scrolling only when its 980 px compact ledger cannot fit.
- Long menus, GL details and mobile/tablet layouts stay bounded; the 820 CSS-pixel breakpoint remains the shared mobile boundary.

## 5. Exact S16R files changed

| File | Change |
|---|---|
| `api/report_loaders_v5980.php` | Adds existing source ID, partner and remarks to Day Book presentation summaries |
| `assets/tegh-registers-r23.js` | Premium Day Book renderer, transaction grouping/paging, GL details, KPI cards and compact actions |
| `assets/tegh-professional-output-v5990.js` | Captures authorized source actions by source ID from the action's real cell |
| `assets/tegh-r27.css` | Premium Day Book cards/table/pills/details/pagination and responsive layout |
| `app.html` | S16R cache revision on all active startup/layout assets |
| `assets/tegh-gate-v5990.js` | S16R dynamic asset revision |
| `assets/tegh-preflight-v5990.js` | S16R cache invalidation revision |
| `assets/tegh-portal-v5990.js` | S16R route-cache namespace |
| `tegh-build.json`, `RELEASE-MANIFEST.json`, `PACKAGE-MANIFEST.json` | HOLD identity, scope and acceptance state |
| `verification/r28_s16r_verify.py` | 62 focused source/protected-file checks |
| `verification/build_s16r_manifest.py` | Reproducible cumulative changed-file and full-tree manifests |
| `R28-S16R-HOLD-REPORT.md` | This report |

## 6. Coverage matrix

| Route/component | Mode | Theme | Role | Viewport | Zoom | State | Interaction tested | Result | Evidence |
|---|---|---|---|---|---:|---|---|---|---|
| Live S16Q Day Book baseline | Full | Light | Authenticated demo | 1363×936 | 100% | 502 GL rows | Visual baseline, View overlay | Passed | Before screenshot; live revision verified |
| S16R Day Book data contract | N/A | N/A | N/A | Source-level | N/A | Populated/grouped | Totals, transaction grouping, full GL-line retention, source identity | Passed | 62/62 focused checks |
| S16R Day Book layout contract | Full/Guided | Light/Dark/System | N/A | Source-level | 100–200% design contract | Desktop/mobile | Cards, fixed columns, table scroll owner, 820 px breakpoint | Passed | CSS/source checks |
| S16R Day Book hosted rendering | Full/Guided | Light/Dark/System | Authenticated demo | Required matrix | 100–200% | Empty/populated/error/final row | Visual, keyboard, pointer, touch, menu containment | Not executed | S16R not deployed |
| Native scrollbar thumb drag | Full/Guided | All | Available roles | Required matrix | 100–200% | Vertical/horizontal | Real pointer drag/track/wheel | Not executed | Requires hosted S16R and real pointer evidence |
| Source document row action | Full/Guided | All | Available roles | Required matrix | 100–200% | Final row | Open source, stable target/geometry | Not executed | Source integration passed; browser retest required |
| Accounting integrity | N/A | N/A | N/A | Local | N/A | Protected files | Hash comparison | Passed | 11/11 protected files unchanged |
| Posting/reversal/reconciliation | Guided/Full | All | Required roles | Hosted DB | N/A | Sample workflows | Debit=credit, controls, audit trail | Not executed | No mutation was performed |
| Full application matrix | Guided/Full | All | All authorized roles | Required matrix | 100–200% | Required states | Complete release gate | Not executed | Prior cumulative report lists remaining scope |

Result vocabulary is intentionally conservative: a source check is not recorded as a browser pass.

## 7. Automated results

| Test | Result |
|---|---|
| `verification/r28_s16r_verify.py` | Passed — 62/62 |
| `verification/syntax_sanity.py` | Passed — 25/25 |
| `verification/payroll_cra_reference_check.py` | Passed — 2026 constants and Ontario/BC complex vectors |
| `verification/r28_step12_large_data_test.js` | Passed — 25,000 rows, 500 pages, balances preserved |
| JavaScript parse checks | Passed — portal, activity, registers, professional output, workspace, gate and preflight |
| JSON parse checks | Passed — build/release/package manifests |
| PHP lint | Not executed — PHP executable unavailable |
| Clean extraction | Passed — flat root, 420 full-tree hashes, 62/62 focused checks, 25/25 sanity checks, CRA vectors and 25,000-row test from a new extraction |

## 8. Manual browser results

- Authenticated access to the correct Tegh URL: Passed.
- Live S16Q version/build/revision: Passed (`5.9.9` / `5990` / `5990-r28-s16q-hosted-acceptance-repair`).
- Live S16Q Day Book View menu: Passed; it opens through the shared viewport overlay.
- Live S16Q Day Book baseline screenshot: Captured.
- S16R after screenshot and interaction matrix: Not executed because S16R is not deployed.

## 9. Accounting regression protection

Schema remains 46 and no migration is included. The accounting, books, payroll, payments, imports, data-imports, interbank, invoice-document, migrations, Schema 46 migration and schema SQL files remain byte-identical to the verified baseline. `report_loaders_v5980.php` changes presentation metadata only; debit/credit amounts continue to come from the authorized journal lines and server totals. Live posting, reversal, period-lock, permission, tax, payroll and reconciliation workflows remain NOT EXECUTED.

## 10. Evidence

| Evidence | Status |
|---|---|
| User-supplied target image | Available in conversation; used as the visual direction |
| `tegh-s16q-daybook-before-1790102955585.jpg` | Captured from the live S16Q Day Book at 1363×936/100% |
| S16R after screenshot | Not executed — exact S16R bytes are not deployed |

## 11. Package/deployment instructions

1. Treat this ZIP as HOLD and deploy only to the isolated authenticated test slot.
2. Record the current web-root/archive and database restore point. Do not alter Schema 46.
3. Verify the ZIP with its adjacent `.sha256` file.
4. Extract into a new empty directory. `app.html` must be at the extraction root.
5. Run `sha256sum -c FILE-MANIFEST.sha256` from that root.
6. Atomically switch the staging virtual host to the new directory, or overwrite only after preserving the old tree.
7. Load `/app.html` in a clean browser profile and confirm revision `5990-r28-s16r-premium-day-book`, version `5.9.9`, build `5990`.
8. Complete the smoke test below before any promotion.

## 12. Post-deployment Day Book smoke test

- [ ] Four KPI cards render real Debit, Credit, Transactions and Out of balance values.
- [ ] Debit equals Credit for the same source scope and Out of balance is zero.
- [ ] Each transaction occupies one compact row; row height does not change when the action menu opens.
- [ ] Ref disclosure exposes every GL line and collapsed rows retain the preferred counterpart.
- [ ] Source document action opens the correct invoice, bill, bank transaction, payroll entry or journal where authorized.
- [ ] Final-row menu flips/clamps inside the viewport; Escape/outside click/focus return work.
- [ ] Table vertical and horizontal scrollbar thumbs drag with a real pointer; wheel, trackpad, keyboard and touch work.
- [ ] The final transaction, final GL line, totals and pagination controls are reachable.
- [ ] Light, Dark and System; Guided and Full; expanded/collapsed/top/mobile navigation pass.
- [ ] 1920×1080, 1366×768, tablet/mobile widths and actual zoom through 200% pass.
- [ ] Empty, loading, error, long-content, maximum-options and direct-refresh states pass.
- [ ] Browser console has no unhandled errors, observer loops or duplicate-handler symptoms.

## 13. Rollback

1. Remove the S16R staging web-root from service or repoint the virtual host to the preserved S16Q web-root.
2. Purge CDN/server caches for `app.html` and active assets.
3. Load the restored application in a clean browser and confirm revision `5990-r28-s16q-hosted-acceptance-repair`.
4. Verify Dashboard, profile menu, Day Book, Cash Forecast and Banking Transfers.
5. No database rollback is expected because S16R contains no schema or posting changes.

Do not present S16R as deployed or production-ready until every release blocker above is closed.

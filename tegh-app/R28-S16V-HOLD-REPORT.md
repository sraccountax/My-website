# R28 Step 16V — user-agent scrollbar mode HOLD report

## Release decision

**HOLD.** S16U was deployed and positively identified, but Day Book still rejected native pointer scrolling. S16V is a cumulative replacement candidate and must be deployed and retested before release.

| Item | Value |
|---|---|
| Product | Tegh Accounting & Payroll |
| Version / build / schema | 5.9.9 / 5990 / 46 |
| Live package tested | S16U |
| Live cache revision | `5990-r28-s16u-native-scrollbar-reset` |
| Candidate cache revision | `5990-r28-s16v-ua-scrollbar-mode` |
| Candidate type | Cumulative full application, flat root |
| Database migration | None |

## S16U hosted results

The authenticated test session used `books-test.sraccountax.ca` at 1363×936 and 100% zoom. All nine active startup/layout URLs contained the S16U revision.

| Route / control | Evidence | Result |
|---|---|---|
| Day Book `.r28-daybook-scroll` | 540 px client height, 11,526 px scroll height, maximum `scrollTop` 10,986 | Real overflow confirmed |
| S16U computed reset | `scrollbar-color:auto`, `scrollbar-width:auto`, `scrollbar-gutter:auto`, `touch-action:auto`; WebKit parts appeared reverted | Applied |
| Day Book keyboard End | Moved to `scrollTop` 10,986 and remained stable | PASS |
| Day Book wheel | `scrollTop` 0 → 0 | FAIL |
| Day Book scrollbar track | `scrollTop` 0 → 0 and 10,986 → 10,986 | FAIL |
| Day Book thumb drag | `scrollTop` 0 → 0 | FAIL |
| Public MDN nested sidebar | Wheel 6,725 → 7,225; track click 7,225 → 6,492 | PASS control |

## Verified remaining root cause

S16U reset the computed values but still matched every operational owner with `::-webkit-scrollbar` selectors. The active CSS also retained:

- a universal `*::-webkit-scrollbar` rule in `assets/tegh-foundation-r22.css`;
- a pointer-fine operational-owner rule in `assets/tegh-audit-r25.css`;
- S16U's own `all:revert` WebKit pseudo-element selector in `assets/tegh-r27.css`.

In Chromium, the existence of matching author `::-webkit-scrollbar` rules opts the element into the author-customized WebKit scrollbar path; `all:revert` changes computed paint values but does not make the selector cease to match. This explains why S16U visually/computationally resembled the control yet retained the broken input path.

## S16V coding change

- Removed the universal WebKit scrollbar pseudo-element rules from the foundation stylesheet.
- Removed R25's WebKit scrollbar sizing and thumb-painting rules for operational owners.
- Removed S16U's WebKit pseudo-element reset selector.
- Retained the standards-only `scrollbar-width:auto`, `scrollbar-color:auto`, `scrollbar-gutter:auto`, `overscroll-behavior:auto` and `touch-action:auto` contract.
- Preserved scoped sidebar, body and specialist import-control styles that do not match the nominated operational owners.
- Added no wheel handler, synthetic thumb, scroll proxy or database change.

## Automated verification

| Check | Candidate source result |
|---|---|
| Focused S16V regression checks | 70/70 PASS |
| Syntax and manifest sanity | 25/25 PASS |
| JavaScript/module parsing | 78/78 PASS |
| Full-tree clean-extraction hashes | 434/434 PASS |
| CRA reference vectors | PASS |
| Large-data register test | PASS — 25,000 rows, 500 pages, balances preserved |
| Protected accounting files | 11/11 byte-identical |
| PHP lint | NOT EXECUTED — PHP is unavailable in the test environment |
| Browser acceptance | NOT EXECUTED — S16V is not deployed |
| Live accounting/workflow acceptance | NOT EXECUTED — S16V is not deployed |

## Release blockers

1. Deploy the exact S16V ZIP and verify `5990-r28-s16v-ua-scrollbar-mode` on all active assets.
2. Retest mouse wheel, trackpad, scrollbar-track click, scrollbar arrow and vertical/horizontal thumb dragging.
3. Confirm the final Day Book record, totals, Reports Centre final category and long-menu final option are reachable.
4. Complete the declared viewport, actual browser-zoom, theme, mode and role matrix.
5. Run PHP lint and authorized live accounting/workflow acceptance.

## Deployment and rollback

Verify the companion SHA-256, extract to a new directory and atomically replace the complete flat-root application. Do not merge individual assets into S16U. No database migration is required. To roll back, restore the complete S16U package and verify its cache revision after invalidation.

# R28 Step 16U — native scrollbar reset HOLD report

## Release decision

**HOLD.** S16T was deployed and positively identified, but it did not restore native pointer scrolling. S16U is a cumulative replacement candidate and must be deployed and retested before release.

| Item | Value |
|---|---|
| Product | Tegh Accounting & Payroll |
| Version / build / schema | 5.9.9 / 5990 / 46 |
| Live package tested | S16T |
| Live cache revision | `5990-r28-s16t-scroll-hit-testing` |
| Candidate cache revision | `5990-r28-s16u-native-scrollbar-reset` |
| Candidate type | Cumulative full application, flat root |
| Database migration | None |

## S16T hosted results

The authenticated test session used `books-test.sraccountax.ca` at 1363×936 and 100% zoom. All nine active startup/layout URLs contained the S16T revision.

| Route / control | Evidence | Result |
|---|---|---|
| Day Book `.r28-daybook-scroll` | 540 px client height, 11,526 px scroll height, max `scrollTop` 10,986 | Real overflow confirmed |
| Day Book keyboard | Page Down moved `scrollTop` 0 → 486 | PASS |
| Day Book pointer | Wheel, scrollbar track and thumb drag remained 0 → 0 | FAIL |
| Reports Centre `.r27-report-groups` | 732 px client height, 1,250 px scroll height | Real overflow confirmed |
| Reports Centre pointer | Wheel remained 0 → 0 | FAIL |
| Public MDN nested sidebar | Wheel moved 6,738 → 7,388; track click moved 7,458 → 6,725 | PASS control |

S16T's page fixes applied exactly: the settled page computed `animation:none`, `transform:none`, and the scroll owners computed `contain:none`. This disproves the S16T compositor-layer hypothesis as the complete cause.

## Remaining shared root cause

The failing Tegh owners still combined:

- `scrollbar-color` and `scrollbar-width` standardized styling;
- WebKit scrollbar track/thumb sizing, borders and painting from multiple active revisions;
- stable scrollbar gutters;
- forced `touch-action:pan-x pan-y`;
- later high-specificity `!important` rules that overrode simpler repair declarations.

The working nested-scroll control used unstyled browser-native scrollbar parts and automatic gutter/input values. S16U removes this remaining shared difference instead of adding synthetic wheel or drag handlers.

## S16U coding change

`assets/tegh-r27.css` now applies one high-specificity native-scroll contract to page, report, register, banking, reconciliation, review and overlay scroll owners:

- `scrollbar-width:auto`
- `scrollbar-color:auto`
- `scrollbar-gutter:auto`
- `overscroll-behavior:auto`
- `touch-action:auto`
- `all:revert` for WebKit scrollbar, track, thumb, button and corner pseudo-elements
- Day Book vertical overflow changed from forced `scroll` to native `auto`

Native colour-scheme handling remains enabled for light, dark and system themes. Scrollbars are not hidden and no JavaScript wheel interception is introduced.

## Automated and package verification

| Check | Final candidate result |
|---|---|
| Focused S16U regression checks | 68/68 PASS |
| Syntax and manifest sanity | 25/25 PASS |
| JavaScript/module parsing | 78/78 PASS |
| Full-tree clean-extraction hashes | 430/430 PASS |
| CRA reference vectors | PASS |
| Large-data register test | PASS — 25,000 rows, 500 pages, balances preserved |
| Protected accounting files | 11/11 byte-identical |
| PHP lint | NOT EXECUTED — PHP is unavailable in the test environment |
| S16U browser acceptance | NOT EXECUTED — candidate is not deployed |
| Live accounting/workflow acceptance | NOT EXECUTED — candidate is not deployed |

## Release blockers

1. Deploy the exact S16U ZIP and verify `5990-r28-s16u-native-scrollbar-reset` on all active assets.
2. Retest mouse wheel, trackpad, scrollbar-track click, scrollbar arrow and vertical/horizontal thumb dragging.
3. Confirm final Day Book record, totals, Reports Centre final category and long-menu final option are reachable.
4. Complete the declared viewport, actual browser-zoom, theme, mode and role matrix.
5. Run PHP lint and authorized live accounting/workflow acceptance.

## Deployment and rollback

Verify the companion SHA-256, extract to a new directory and atomically replace the complete flat-root application. Do not merge individual assets into S16T. No database migration is required. To roll back, restore the complete S16T package and verify its cache revision after invalidation.

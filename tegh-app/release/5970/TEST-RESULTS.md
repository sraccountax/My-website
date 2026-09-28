# Final executed tests and uncompleted gates

**Tegh 5.9.7 / Build 5970 / Schema 44 — HOLD / NOT SEALED**

No production deployment or customer-data mutation occurred. There are zero failures in the final runs listed below; this does not mean zero defects or that the requested acceptance matrix has passed.

| Executed suite | Pass | Fail | Scope |
|---|---:|---:|---|
| active-source-scan | 20 | 0 | 20 checks in four active entry/asset files |
| js-syntax | 60 | 0 | 60 JavaScript/MJS files; syntax only, not ESLint |
| json-parse | 17 | 0 | 17 JSON files at the static-test snapshot |
| php-lint | 65 | 0 | 65 PHP files; syntax only |
| schema-static | 1 | 0 | Additive DDL / no DROP or TRUNCATE scan; not SQL execution |
| source-packaging-policy | 1 | 0 | No bundled font programs |
| xlsx-structure | 12 | 0 | Two actual adapter XLSX files; XML, formulas, typing, frozen panes, protection |
| pure-php | 41 | 0 | 41 actual helper/fixture checks with explicit fictional stubs; no MySQL |
| offline-interaction | 22 | 0 | 22 actual renderer checks with mocked data/API/storage and synthetic shell |
| offline-output | 10 | 0 | 10 actual Day Book model/toolbar/Print/PDF/XLSX component checks |
| artifact-xlsx-import-render | 2 | 0 | Two workbooks imported and rendered; not Excel desktop certification |

The final archive extraction/hash/JSON inventory checks are recorded separately in `PACKAGE-VERIFICATION.json` in the evidence bundle.

## Exact commands used in this workspace

```sh
php /mnt/data/tegh5970/evidence/tests/php_unit.php /mnt/data/tegh5970/work /mnt/data/tegh5970/evidence
python /mnt/data/tegh5970/tools/build_browser_harness.py
python /mnt/data/tegh5970/tools/browser_offline_smoke.py
python /mnt/data/tegh5970/tools/interaction_evidence.py
python /mnt/data/tegh5970/tools/output_evidence.py
python /mnt/data/tegh5970/tools/static_validation.py
```

The static runner records the individual `php -l <path>` and `node --check <path>` commands/results. Workbook import/render used the Python artifact_tool APIs, not Microsoft Excel. PDF evidence was opened/rendered with PyMuPDF. The reproducibility bundle includes the verification scripts; their workspace constants point to `/mnt/data/tegh5970`. Use an isolated directory and adjust that root to replay elsewhere. Do not run test harnesses from a live document root.

## Uncompleted required gate groups

| Gate | Status | Why / what remains |
|---|---|---|
| G01: PHP/MySQL posting, categorization and authorization integration | BLOCKED | No MySQL/MariaDB or required PHP database/XML/ZIP extensions in the execution container. |
| G02: Schema 44 forward, rerun, partial-state recovery and rollback restore | BLOCKED | Requires isolated MySQL/retained schema clones; not executed. |
| G03: Complete authenticated browser/API E2E and import-entry matrix | BLOCKED | Normal browser navigation returned ERR_BLOCKED_BY_ADMINISTRATOR. Offline components are not a substitute. |
| G04: Live controlled SMTP sink, TLS/alignment and SPF/DKIM/DMARC | BLOCKED | No verified host mail configuration or controlled inbox supplied/tested; no live email was sent. |
| G05: Concurrency, IDOR/CSRF, company deletion/BFCache and lost responses | BLOCKED | No real sessions/MySQL transactions; only selected pure/offline paths executed. |
| G06: All report loaders, GL/subledger controls and accounting fixtures | BLOCKED | Day Book pure fixture passed; every SQL loader/control reconciliation still requires a database. |
| G07: Native browser zoom/mobile/axe/screen-reader coverage | NOT RUN | Responsive code exists, but the full native zoom and accessibility matrix has not been run. |
| G08: Every provider-capable endpoint and side-effect spy matrix | NOT RUN | Zero counters were established for exercised pure functions only, not every HTTP route. |
| G09: Microsoft Excel repair-free desktop open / large-output limits | NOT RUN | Two actual adapter workbooks import/render with artifact_tool and pass XML checks; Excel desktop was not used. |
| G10: Actual IONOS web PHP/MySQL/opcache/upload/runtime inventory | NOT RUN | The supplied ZIP does not establish actual live host versions/configuration. No production access/deployment occurred. |
| G11: Independent human accounting review | NOT REVIEWED | Required external sign-off not performed. |
| G12: Independent human security review | NOT REVIEWED | Required external sign-off not performed. |

This is **6 blocked groups, 4 not-run groups and 2 not-reviewed groups**. These are group counts, not a claim that only twelve individual test cases remain. The full user acceptance matrix must still be executed where not specifically evidenced.

## Important incomplete scope

Older output endpoint families remain available for compatibility and have not all been retired or redirected. Some subledger trial-balance variants and historical ageing are explicitly unavailable. Download PDF is rasterized and is not tagged/searchable text. Every report/document and every provider-capable HTTP path still needs coverage. See RELEASE-NOTES.md and REQUIREMENT-TEST-MATRIX.csv.

## Evidence provenance

Before/after Review Transactions screenshots use actual 5960/5970 renderers and fictional data in a synthetic shell. Review fit was checked at 1366×768, 1440×900 and 1920×1080 with expanded/collapsed synthetic rails. The posting screenshots simulate five outcomes. Continuous preview fixtures cover 1/25/26/500/5000 rows and search of an unmounted final row. Invitation click/Enter each issue one simulated request. No real bank posting or email delivery occurred.

The authoritative fixture model snapshot used for the Day Book output is `day-book-output-model.json`. Its same-run screen/HTML/PDF/Excel files share one reference. Other calls to generate the fixture can legitimately have another output timestamp/reference. The category workbook example contains fictional, unusable tokens and must not be imported into an actual company.

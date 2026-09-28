# Tegh 5.9.8 · Build 5980 · Schema 44

**Cumulative engineering candidate — HOLD / NOT SEALED. Not approved for production.**

## Source and release identity

The attached Tegh 5.9.7 cumulative archive is the baseline. SHA-256: `e376a472694527d28a2a501d2eae964aed2fe56e5bbf645d945151a68adbd6ad`. No older source was restored. A separate Guardian R1 branch found in project history was not merged; this package must not be represented as completion of that different project.

Active entry: `app.html` → `assets/tegh-gate-v5980.js` → `assets/tegh-portal-v5980.js`. Active output assets: `assets/tegh-professional-output-v5980.js` and `assets/tegh-pdf-v5980.js`. Active review CSS: `assets/tegh-app-v5980.css`. Historical shared assets with other suffixes remain only where intentionally shared or retained; historical notes under release/5970 are not this release's status record.

## Engineering completed in this follow-up

**Reporting.** The older professional-output5710/5800 public handlers now delegate the current server contract. A cached5970 output URL is routed to5980, not an old accounting loader. The registry contains33 definitions, including AR and AP trial balances. The server validates integer money values before wire serialization and rechecks authorization/settings after the read. The frontend rejects wrong-company, wrong-definition, incomplete or stale responses and removes cached models on scope invalidation. Individual bill actions use the vendor-bill endpoint.

**Dated subledgers.** `api/report_subledgers_v5980.php` reconstructs dated AR/AP ageing, party ledgers and trial balances using document events, actual payment/reversal dates, separate unapplied advances, dated applications, recorded carrying values and opening cutovers. It uses the actual `opening_document_imports.cutover_date` table/column. It compares complete company control totals rather than treating a filtered party list as the entire control. Exceptions are visible; no balancing plug is created. Queries are bounded and company scoped.

**Output presentation.** PDF output now contains selectable/searchable text, repeated report headers and Page X of Y. Unicode is mapped for text extraction; non-Western runs use local shaped glyph subsets, not a screenshot of the full page. This is not a certified tagged PDF/UA document. No downloadable font programs are required. Excel remains typed and filterable with frozen headers and parameters; Day Book grand debit/credit totals now align under their actual columns. Preview and report filters/source disclosures are retained in output, independent of visible/search-filtered DOM rows.

**Posting recovery.** A lost start response followed by a receipt404 no longer discards the operation key. The exact bounded decision payload/key is retained before the first commit and reused. A paused unresolved operation blocks a fresh independent key; reload preserves its reference. Only explicit resume checks/reuses that operation. Verified success survives list-refresh failure with Retry Refresh. The old imported-register posting shortcut no longer substitutes No Tax; it sends selected pending rows to Review Transactions for the authoritative decision.

**Dates and invitations.** Explicit calendar-month headings and Dec/Jan periods provide year evidence. Equal numeric month/day no longer produces false ambiguity. Invitation UI supports Send queued email without minting a new token, preserves unknown delivery outcomes, and exposes sanitized diagnostic fields only. An interrupted sending record older than15minutes is displayed as Manual review, not falsely Failed or blindly resent. Invitation history is reauthorized before details are returned to a non-platform administrator.

**Provider boundary and storage.** Connected state/roles/endpoints are additionally fenced before configuration/DB/provider work. The native-only gate remains false. Schema44 readiness checks actual column type/sign, index order/uniqueness and required foreign-key behavior. Existing migration step identifiers and checksum material were deliberately preserved.

## What has not been proved

The two remaining dependency groups are (1) authorized isolated runtime/host verification and (2) independent human accounting/security approval. These are substantive acceptance gates, not a request to edit a release label. Database transactions, actual SQL report outputs, permission/CSRF/IDOR tests, migration recovery, invitation acceptance and live SMTP were not executed here. Full authenticated import-entry/browser/native-zoom/accessibility testing and desktop Excel repair-warning verification remain unexecuted. Those tests may uncover further corrections.

Local tests use fictional data, pure function dependencies or simulated HTTP responses. They do not prove production readiness, an actual five-journal commit, real email delivery or a migrated customer database. Exact test results and commands are supplied separately.

## Accounting and operational boundaries

Historical as-of reports reconstruct the **current/restated books** using recorded effective dates. They are not a time-machine snapshot of what the database contained at an earlier wall-clock time. Missing/ambiguous legacy payment origin or application evidence fails safely or appears as an explicit exception; it is never assigned an invented date. Fixed assets, currency exposure and directories remain explicitly current-snapshot reports. Cash/accrual distinctions and foreign carrying amounts are disclosed rather than converted by appearance.

Outputs are **Management report — unaudited**. A configured ASPE/IFRS label is a company setting, not software certification or an audit opinion. Independent review must validate the company's intended basis, framework, accounts and presentation.

## Migration compatibility

This follow-up retains Schema44 because it introduces no additional schema object or data migration. For complete valid Schema44, do not manually rerun SQL or change the marker. For Schema43, use the protected cumulative Schema44 upgrader with a verified paired backup. Invalid/partial Schema44 must first pass structural diagnostic/recovery checks.

The13table definitions are byte-identical as returned by the migration functions. SHA-256 of `json_encode(tegh_schema44_table_sql())`: `c4c09d67d7e19b90bd7aef335ec731de09a19e4cf0895bcc8dda25ba3c5bac0e` for both5970 and5980. The original steps `schema44.5970.tables`, `schema44.5970.native_data`, `schema44.5970.adopt_marker` and contract string `schema44-native-5970-v1` remain intentional. This is source identity evidence, not evidence that migrations have executed successfully.

## Deployment status

Do not deploy this candidate to production. See DEPLOYMENT-ROLLBACK.md for isolated staging and recovery precautions. Source completion and local passing checks do not seal a release. No live website, customer database or email account was accessed or modified.

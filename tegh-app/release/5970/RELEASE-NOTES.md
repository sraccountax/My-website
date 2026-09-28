# Tegh 5.9.7 · Build 5970 · Schema 44

## HOLD / NOT SEALED — implementation candidate, not a production approval

This is a cumulative flat application source package, not a patch-only package. It contains implemented changes across the four requested checkpoints, but the full acceptance matrix has **not** been completed. Do not use this release on production accounting data. No production deployment, customer-data mutation or live mail test was performed.

Baseline: `Tegh-5_9_6-Build-5960-Schema-43-REVIEW-TRANSACTIONS-SPLIT-HOLD-FLAT(1).zip`, version 5.9.6 / build 5960 / schema 43. Its verified SHA-256 is `e05a885ba324364d004722d3a1b302ac9f6f6faad923a7f8134af35e1d535c33`. The baseline was extracted separately and not edited. The new archive has its own external SHA-256 file; do not confuse source and output hashes.

## Implemented candidate changes

**Checkpoint 1 — containment.** Current-import routing replaces legacy statement navigation in the revised handlers. The invitation form has an explicit submit button and safe, single-request busy handling. A parent/assignment/token/outbox invitation service and an independent Platform Owner workspace are added. Public signup requires both the private deployment permission and audited owner permission, defaulting to invitation-only. Company-deletion responses carry a server-authorized replacement and invoke a shared scope-clearing transition, including expired Test Mode cleanup. The provider release gate returns false before the exercised provider boundary can read a key, reserve budget or initialize cURL. Native inclusion initializes company entitlements while preserving explicit owner restrictions and user narrowing.

**Checkpoint 2 — banking.** The split queue/detail renderer has page-scoped width and a compact footer layout, three primary tabs, server confidence filtering, typed search helpers and a refreshed account combobox. Transfers use the signed statement direction and resolve financial accounts on the server. Bulk posting gets durable operation/row records and uses the existing posting engine, not a second journal engine. The UI displays processing, verified, pending and failed counts, retains receipt identity and has verification/retry/refresh paths. Statement review uses progressive complete-model selection and search, focused date correction, and a server-validated preview-output endpoint. A separate category-only XLSX workflow adds signed immutable state, strict parsing, preview and atomic commit receipts.

**Checkpoint 3 — reporting.** A 31-key versioned registry and server output model are added. Registered surfaces target one Print / Download PDF / Download Excel toolbar and replace legacy client tables with sealed model rows. Day Book uses actual journal lines and voucher totals; drafts and void history are shown but excluded from posted totals. Customer invoice and vendor bill definitions are distinct. The actual Day Book adapters produce the included fictional screen/print/PDF/XLSX evidence. Controls and configured basis/framework are disclosed without a compliance or audit assertion.

**Checkpoint 4 — release engineering.** Schema 44 is additive, with migration ledger/checkpoints and native/policy/invitation/source-text backfills. A verified file inventory, checksums, changed-file list, requirement matrix, test evidence and rollback instructions accompany the source. Database idempotency is an implementation property to verify, **not a passed runtime claim**.

## What the evidence does and does not prove

Local PHP is 8.4.23 CLI; Node is 22.16.0. Chromium is recorded in `chromium-version.txt`. The runtime lacks MySQL/MariaDB and PHP PDO MySQL, ZipArchive, DOM/SimpleXML, mbstring and cURL support. The package repository could not be resolved. Normal browser navigation was rejected with `ERR_BLOCKED_BY_ADMINISTRATOR`; no restriction was bypassed. Offline tests use `page.set_content`, actual application renderers, a synthetic shell, fictional data, API/storage stubs and an origin-only test replacement. They are component tests, not authenticated host/SQL/SMTP end-to-end tests.

The PHP pure suite executes actual pure/helper functions with explicitly fictional stubs. It proves the listed helper outcomes, not MySQL transaction isolation, lock order, deletion safety or complete endpoint authorization. A fake provider key is seeded in the pure test; zero counters apply only to the exercised paths. Full enumeration of provider-capable HTTP routes remains required.

Screenshots are actual offline browser renders. Before screenshots use the untouched 5960 renderer, not a recreation of customer books. After screenshots use 5970. Only the tested default desktop fixture passed the no-right-pane-scroll geometry checks. Native 125%, 150% and 200% zoom, real mobile, screen readers, axe and complete shell/navigation coverage remain unexecuted. The live posting screenshots simulate server results and create no journals.

The Day Book fixture contains 11 actual fixture lines in five vouchers: a two-line posting, a three-line split, a draft, retained void history and a posted reversal. Posted totals are CAD 396.00 debit and CAD 396.00 credit. All displayed history including excluded draft/void groups totals CAD 516.00 each. This verifies the pure projection fixture only. The generated output model snapshot, screen, HTML, PDF and Excel share a reference from the same run.

## Known remaining scope / limitations

1. Legacy professional-output 5710/5800 handlers remain for compatibility; not every old route is retired or forced through the new registry. Every registered report, register, ledger and document needs browser/API reconciliation testing. Unsupported subledger trial-balance variants are explicitly unavailable instead of exporting an unverified DOM table.
2. Ageing currently supports a current-balance snapshot. Historical ageing is rejected rather than presented as a reconstructed historical ledger. Historical party-ledger/void/payment and multi-currency semantics need database fixtures and accounting review.
3. Download PDF uses local system-font rasterized pages. It is visually paginated but not searchable text or a tagged accessible PDF. Screen/Print HTML and XLSX retain text. The local PDF engine has explicit 512-page / 128 MB limits; over-limit generation fails rather than silently omitting rows. Desktop Excel repair-warning testing remains outstanding.
4. The complete PDF/date-order corpus, transfer/card/FX/period-lock matrix, category parser and atomicity tests, concurrency/lost-response/deletion/BFCache tests, real mail delivery matrix, and all provider-capable endpoint spies have not been run.
5. Invitation policies, privacy/terms content, mail authentication/alignment, non-billable feature coverage and owner/user suspension governance need independent review. The acceptance contract records document version 2026-09-09; the corresponding packaged pages display that version. This is not a legal approval.
6. No actual IONOS host/runtime/database inventory was available in the supplied source. Do not treat local runtime versions as deployed-host versions.

Read `REQUIREMENT-TEST-MATRIX.csv` for implementation-to-file-to-evidence mapping. Read `TEST-RESULTS.md` for exact executed counts and uncompleted gates. Read `DEPLOYMENT-ROLLBACK.md` before staging. HOLD can be removed only after the outstanding technical gates and independent human accounting/security review actually pass.

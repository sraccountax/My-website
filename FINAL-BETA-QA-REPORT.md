# Tegh final beta QA report: invite-only beta release gate

Prepared 2026-09-30 by the build/QA agent (senior QA, security and accounting review). This is **not** the owner's review and **not** host acceptance.

## 1. Verdict

> **Update: R140 supersedes R139 for deployment.** After this report was issued, a new defect was reported: "Can't scroll vendor invoice creation in mobile view" (DEF-09). It is fixed in R140, and the gate was re-run on the exact R140 ZIP. See the addendum at the end. Where this report says "deploy R139", deploy **R140** (SHA-256 `c2e6bb611406a6e66d28c21e35a4ee6d639632cb9548d044ee35533e5890ac4f`). The verdict is unchanged: **NOT READY**, for the same host-acceptance and owner-decision blockers.

# NOT READY: exact blockers

R139 passes every test this gate could run off-host. The verdict is still NOT READY, because the gate rules require host acceptance evidence for READY and none exists yet. The blockers:

1. **Host acceptance items 1–6 are BLOCKED** (no shell or browser access to the IONOS host). The owner must run §11 on the serving host and record the results:
   - host PHP lint
   - `sha256sum -c FILE-MANIFEST.sha256` in the web root
   - real statement files and a $0.00 reconciliation
   - production-backup restore equality
   - live postings on the host database
   - signed-in Client Viewing Link check with a real emailed code
2. **Production email delivery is BLOCKED/unverified.** Mail was proven only through a local STARTTLS+AUTH sandbox. The IONOS SMTP account, SPF, DKIM and DMARC for the real sender domain are untested (§11, H6).
3. **Owner decisions are required before invitations go out** (§12):
   - **Privacy Notice vs Microsoft Clarity:** the marketing pages `index.html` and `company.html` still load Clarity. The Privacy Notice says the site does not store cookies. Either remove Clarity or publish the disclosure drafted in §12.3.
   - **Starter tax codes for other provinces (DEF-08):** by default, customers in QC, MB, SK and BC are charged that province's QST/RST/PST, even if the business is not registered there.
   - **Owner review (host-acceptance item 7):** the R135 sales-tax wording and the Product Activity note, drafted in §12.
4. **Deploy R139, not R138.** R138 has confirmed defects DEF-01 and DEF-04/05/06/07 (plus DEF-02 and DEF-03), all fixed in R139.

`productionReady` and `acceptanceComplete` stay **false** in all three manifests. Nothing was changed to obtain a verdict.

## 2. Release identity

| | Baseline tested | Successor (deploy this) |
|---|---|---|
| Release | R138 | **R139** (fixes only) |
| ZIP | `Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R138-Consolidated-IONOS-STAGING.zip` | `Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R139-Consolidated-IONOS-STAGING.zip` |
| SHA-256 | `83e05ec2c7222f06442d9ed3efa1177df8aada3131334a34838053cda12fa9ed` | `0678a234e37793245dc6a2361209f49faf3c5799a44275e40eeb15d22428be57` |
| Source commit | `2e27705` | `d5e46f6` (branch `claude/invoice-credit-debit-audit-e2y8wc`) |
| Version / build / schema | 5.9.9 / 5990 / 46 | 5.9.9 / 5990 / 46 |
| Cache token | `5990-r138-tegh` | `5990-r139-tegh` (client view: `5990-r135`) |
| FILE-MANIFEST.sha256 | 335 entries, all OK | 336 entries, all OK in the unpacked ZIP |
| productionReady / acceptanceComplete | false / false | false / false (unchanged) |
| Host acceptance items | 7 open | 7 open (item 7 now also covers the R139 analytics decision) |

The R139 manifests (`RELEASE-MANIFEST.json`, `PACKAGE-MANIFEST.json`, `tegh-build.json`) carry one identity: R139, the cache token and the package name. R138 identity and gates moved to `history.r138Identity`.

This report and `beta-gate-evidence/` sit at the repository root, outside the package, so writing them did not change the tested ZIP. `R139-CHANGES.md` inside the package refers to this report by name.

The ZIP was produced after the last code change. It is the **exact file tested** in §3–§10: unpacked into a fresh web root, verified with `sha256sum -c`, and served against an empty database.

## 3. Test environment (host-like, not the host)

| Item | Gate environment |
|---|---|
| Web server | Apache 2.4.58, `AllowOverride All` (package `.htaccess` files active), mod_rewrite/headers/ssl |
| PHP | PHP-FPM **8.3.6** (Ubuntu) serving; PHP 8.4.19 CLI also used for lint |
| TLS | HTTPS on 443 with a private test CA; HTTP on 80 for redirect tests |
| Database | MariaDB, **separate fresh database** `tegh_gate`. Dedicated least-privilege user with SELECT/INSERT/UPDATE/DELETE/CREATE/ALTER/INDEX/DROP/REFERENCES/LOCK/TEMP on that schema only. |
| Private config | `../sr-accountax-private/config.php` outside the web root; storage outside the web root |
| Email | Local SMTP sandbox with **STARTTLS and AUTH LOGIN** (Tegh requires both). Only test inboxes `*@gate.test`. |
| Data | Synthetic companies (Gate Test BC Ltd, QC Inc, ON Corp, Other Firm, Unregistered Sole Prop, Cash Basis ON) and synthetic people. No real books, no real customers, no payments, nothing published. |
| Upgrade host | Second vhost on :8443 with its own database. R118 baseline from commit `44cab22`, data added, then the exact R139 ZIP uploaded over it. |

Local results do **not** substitute for host acceptance (§11).

## 4. Results summary

**R139 (final ZIP, clean install):**

| Area | PASS | FAIL | BLOCKED | INFO / NA |
|---|---|---|---|---|
| Install, HTTPS, routing, permissions (H-INST + path probes) | 12 + 40 | 0 | – | – |
| Email and invitations (sandbox) | 19 | 0 | production SMTP: BLOCKED | – |
| Accounting controls (ACCT) | 60 | 0 | – | – |
| Sales tax R135–R139, remittances, custom GL, unregistered, cash basis (TAX) | 20 | 0 | – | 1 |
| Foreign currency (FX) | 5 | 0 | – | – |
| Security, isolation, roles, CSRF, uploads, errors, throttling (SEC) | 43 | 0 | – | 1 |
| Client viewing links (CVL) | 20 | 0 | – | – |
| Payroll SIN absence (PAY) | 7 | 0 | – | 2 |
| Backup/restore equality (REC) | 9 | 0 | – | – |
| Performance, logs, support (OPS) | 5 | 0 | – | 2 |
| Upgrade R118 → R139 (UPG) | 9 | 0 | – | – |
| PHP lint: 81 files × PHP 8.3 and 8.4 | 162 | 0 | host PHP: BLOCKED | – |
| UI matrix: 46 screens × 20 runs (§8) | 920 | 0 | – | – |
| Key journeys (desktop) | 9 | 0 | – | – |
| **Host acceptance items 1–7** | 0 | 0 | **7** | – |

INFO rows record observations with no pass/fail criterion. Each is explained in the full matrix (`beta-gate-evidence/test-matrix.md`). Blocked or unexecuted tests are **not** counted as passed.

**R138 baseline (exact R138 ZIP), same suite:**
- **Automated checks:** 195 PASS / 6 FAIL / 7 INFO. The FAILs were:
  - D-MAILMSG → DEF-02
  - AC-12, AC-23 and AC-25 → DEF-04
  - NR-01 → DEF-05
  - FX-00, a faulty test (wrong HTTP method), replaced by FX-01..04, which passed
- **Path probe:** 1 FAIL (`config.example.php` returned 200; DEF-03).
- **Found by inspection:**
  - DEF-01 (Clarity in `app.html`)
  - DEF-06 (dashboard card showed $0.00 with $13.00 owed in custom accounts)
  - DEF-07 (damaged backup returned 500; that check was loose in the baseline and was tightened for R139)

## 5. Defect register

| ID | Severity | Area | Found in R138 (evidence) | Status in R139 | Retest |
|---|---|---|---|---|---|
| DEF-01 | **High**: privacy | UI / legal | `app.html` loaded Microsoft Clarity session recording (`clarity.ms/tag/ymd1vld1vm`) on every signed-in screen. It could capture amounts, customer names and payroll on screen. The Privacy Notice says "does not store … cookies or accounting data". | **Fixed**: removed from `app.html` | PASS: path probe "app.html has no third-party session recording"; browser journeys recorded **0 external requests** |
| DEF-04 | **High**: accounting control | GL / AR / AP | A manual journal Dr 1200 / Cr 4000 $100 posted (AC-12). AR control became $3,317.76 while the subledger and ageing showed $3,217.76 (AC-23, AC-25). | **Fixed**: manual, Ask Tegh and recurring journals refuse 1200/2050 (409 `journal_subledger_control_protected`). The account list no longer offers them. | PASS: AC-12, AC-12b (recurring), AC-12c (AP), AC-23, AC-25, journey 05 |
| DEF-05 | **High**: sales tax / legal | Tax codes | A company **not registered for GST/HST** billed a $100 BC sale at $112 (GST $5 + PST $7), because starter codes applied automatically (NR-01). | **Fixed**: for an unregistered company, no automatic code on invoices, drafts or recurring invoices; purchase tax goes to cost. An explicit code on a line is still honoured. | PASS: NR-01, NR-02 (bill $100 + BC → expense $112, no ITC), NR-03 (explicit code), journey 09 |
| DEF-06 | Medium | Dashboard | With HST mapped to custom 2120/1120, the dashboard card showed "GST/HST you owe $0.00" and nothing else, while $13.00 net was owed. | **Fixed**: the card note shows "other tax accounts $13.00 (see Tax Summary)"; the subtitle states the GST/HST figure is 2100/1100 only. | PASS: CG-08 (`otherTaxNetCents` = 1300), journey 02 screenshot |
| DEF-02 | Medium | Email | A successful send (SMTP 250, status "sent") was recorded as "The mail server rejected the message before accepting it…". This appears in invitations and Email Health. | **Fixed** | PASS: D-MAILMSG |
| DEF-07 | Low | Backup | Restoring a damaged `.tegh` returned **500** (uncaught `JsonException`). Nothing was written. | **Fixed**: 422 integrity message | PASS: BR-08 |
| DEF-03 | Low: hardening | Web root | `/config.example.php` was executable over HTTP (200, empty body). | **Fixed**: `.htaccess` denies `config*.php` | PASS: path probe (403) |
| DEF-08 | Medium: sales tax | Starter codes | Starter codes apply by **customer** province. A BC company invoicing a QC customer charges QST $99.75 to 2115, and an MB customer is charged RST, even when the business is registered only for GST and BC PST. The R138 docs mention only home-province PST. | **Open**: owner decision (§12.2). Beta restriction in §13. | TX-03 shows the behaviour |
| OBS-1 | Low | Install | A fresh install creates Schema 43. The owner must run the in-app protected upgrade (→46) once after first sign-in. This was not documented for fresh installs. | **Documented** in README (R139) | PASS: H-11, H-12 |
| OBS-2 | Low | Logs | Invitation tokens are in the URL query (`app.html?accountSetup=`), so they can reach host access logs. They are single-use with 72-hour expiry. Client-view tokens use the URL fragment. | Open (accepted) | – |
| OBS-3 | Low | Deploy | The first request within about 2 s of uploading files saw a mixed old/new PHP opcache state ("degraded"). It cleared on the next request. | **Documented**: restart PHP / wait after upload (§14) | UP-01 PASS after reload |
| OBS-4 | Info | PHP limits | The PHP-FPM default `post_max_size` of 8 MB rejects bodies above 8 MB before Tegh's 10 MB rule. IONOS honours the shipped `api/php.ini` (24 MB). | Host check (§11) | – |

## 6. Accounting reconciliation evidence (independently computed)

Every expected figure was written by hand in the test, never computed by Tegh functions. The GL was read by direct SQL, and the reports by API. Company "Gate Test BC Ltd": accrual basis, Default chart, starter codes, BC.

**Tax arithmetic controls (all PASS on R139):**

| Control | Hand calculation | Posted |
|---|---|---|
| ON customer $1,000 | HST 13% = $130.00 | Cr 2100 $130.00; AR $1,130.00 |
| BC customer $1,000 | GST $50.00 + PST 7% $70.00 | Cr 2100 $50.00, Cr 2110 $70.00; AR $1,120.00 |
| QC customer $1,000 | GST $50.00 + QST 9.975% = $99.75 | Cr 2100 $50.00, Cr 2115 $99.75; AR $1,149.75 |
| 3 × $166.67 AB | $500.01 × 5% = $25.0005 → $25.00 | Cr 2100 $25.00; AR $525.01 |
| QC deposit $1,149.75 (bank, QC code, tax included) | $1,000 net + $50 + $99.75 | 4000 $1,000; 2100 $50.00; **2115 $99.75** |
| QC purchase $1,000 (bill) and $1,149.75 (bank) | ITC $50 + ITR $99.75 | Dr **1100 $50.00**, Dr **1115 $99.75** |
| BC purchase $1,000 (bill) / $1,120 (bank) | PST $70 not recoverable | expense $1,070.00; 1100 $50.00; no PST asset |
| Expense $113.00 incl. ON HST | $113 ÷ 1.13 = $100 + $13 | expense $100.00; 1100 $13.00 |
| Credit note $200 on BC invoice | GST $10 + PST $14 | Dr 2100 $10.00, Dr 2110 $14.00; AR −$224.00 |
| QC bill $500 | QST 49.875 → **$49.88** (half-up) | 1115 $49.88 |
| QST remittance $49.87 (QC company) | 99.75 − 49.88 | Dr 2115 $99.75, Cr 1115 $49.88, Cr bank $49.87; 2115 = 1115 = $0 |
| GST remittance $25.00 (QC company) | 50 − 25 | Dr 2100 $50, Cr 1100 $25, Cr bank $25; both $0 |
| Cash basis ON, half receipt $565 | 565 ÷ 1.13 | HST $65.00, revenue $500.00 recognised |
| USD 1,000 at 1.35, received 1,050 at 1.40 | 1,350 + 67.50; 1,470 − 1,417.50 | AR $1,417.50; FX gain **$52.50** (6850) |

**Ledger equality (BC company, R139): expected ledger = SQL GL = Trial Balance API, account by account**

| Account | Expected (hand) | GL (SQL) |
|---|---|---|
| 1000 Chequing | 8,613.00 Dr | 8,613.00 Dr |
| 1100 GST/HST Recoverable | 238.00 Dr | 238.00 Dr |
| 1115 QST Recoverable | 199.50 Dr | 199.50 Dr |
| 1200 AR | 3,217.76 Dr | 3,217.76 Dr |
| 2050 AP | 2,269.75 Cr | 2,269.75 Cr |
| 2100 GST/HST Payable | 301.00 Cr | 301.00 Cr |
| 2110 PST Payable | 63.00 Cr | 63.00 Cr |
| 2115 QST Payable | 199.50 Cr | 199.50 Cr |
| 3000 Equity | 9,623.45 Cr | 9,623.45 Cr |
| 4000 Revenue | 4,700.01 Cr | 4,700.01 Cr |
| 6000 Expense | 4,888.45 Dr | 4,888.45 Dr |

**Control checks:**
- **AR control:** 1200 = open invoices (SQL) = AR ageing report = $3,217.76.
- **AP control:** 2050 = open bills = AP ageing = $2,269.75.
- **Balance sheet:** assets $12,268.26 = liabilities $2,833.25 + equity $9,435.01, difference $0.00. The assets figure equals the SQL asset total.
- **Journals:** every journal in the database balances.
- **Re-runs:** the Trial Balance is identical on re-run.
- **History:** editing the QC code after posting changed no posted amounts.

**Other controls (PASS):**
- **Opening balances:** $10,000 balanced; a second post adds nothing.
- **Idempotency:** 5 simultaneous identical receipts gave 1 payment. 3 simultaneous posts of one bank line gave 1 journal. A re-post was refused.
- **Refusals:** over-payment, same-account (bank → its own GL) transfer, unbalanced journal and future-dated journal.
- **Period lock through 2026-08-31:** refuses journal, invoice, vendor invoice and receipt dated in the locked period.
- **Invalid, inactive or other-company `taxCodeId`:** refused on invoice, vendor invoice and expense (422), with nothing saved.
- **Custom GL 2120/1120:** appears in GL, TB and Tax Summary.
- **Statement import:** a real CSV import through upload → preview → approve; the re-import flagged all 5 rows duplicate.

Evidence: `beta-gate-evidence/bc-expected-vs-gl.json`, `bc-tb.json`, `bc-bs.json`, `acct.json`, `tax.json`.

## 7. Security and company isolation (R139, all PASS)

- **Cross-company tampering:** an admin of another company was refused on every attempt, with no data leaked:
  - BC company header
  - invoice detail and PDF model by ID
  - payment against a BC invoice
  - editing or deleting a BC tax code
  - posting a BC bank line
  - credit note against a BC invoice
  - backup export
  - GL ledger by account ID
  - invoicing a BC customer ID
  - listing client links
- **Unauthenticated requests:** 401.
- **Roles:**
  - A viewer cannot create invoices, post journals or bank lines, create tax codes, unlock periods, invite or export backups, but can read reports.
  - An editor can draft invoices but cannot unlock periods or invite owners.
  - A company admin cannot grant Owner.
- **CSRF:** missing or wrong token, foreign Origin and form-encoded POSTs are all refused.
- **Setup:** a wrong key or foreign Origin is refused, and a second setup is locked.
- **Session:**
  - Cookies are `Secure; HttpOnly; SameSite=Strict`.
  - The old cookie is rejected after logout.
  - Authenticated API responses send `private, no-store`, with no `X-Powered-By`.
- **Throttling:** after 20 wrong passwords, logins get 429, and even the correct password is refused while throttled.
- **XSS:** a customer named `<img src=x onerror=…>` renders as text on every screen in 20 UI runs; the script never ran.
- **Uploads:** refused for `.php`, `.svg`, PHP disguised as `.png` (checked by content) and anything over 10 MB. A valid PDF is stored outside the web root.
- **Error leakage:** malformed JSON, SQL-like IDs, oversize and wrong-type input all return a clean 4xx with no paths, stack traces or SQL. No PHP fatal errors were logged.
- **Web paths:** 403/404 for `api/*.php` other than `index.php`, `bootstrap.php`, `schema.sql`, `php.ini`, config templates, manifests, `README`/`DEPLOYMENT-NOTES`/`*.md`, `release/`, `storage/`, `.htaccess`, `knowledge/*.md`, `sample-data/` and directory listings. HTTP → HTTPS 301 for books-test/books.
- **Security headers:** HSTS, CSP, `frame-ancestors none`, nosniff and referrer policy are present.
- **Cache headers:**
  - `app.html` and `client-view.html`: `no-store`.
  - Versioned assets: immutable, and every referenced asset resolves.
- **Client viewing links:**
  - **Link and codes:** the token is in the URL fragment only. The code is emailed to the client address (masked in the response). A wrong code shows the tries left. A used code cannot be reused.
  - **Brute force:** after 5 wrong codes even the right one is refused. Code requests are limited to 5 per hour per link. The code expires after 10 minutes.
  - **Expiry and revocation:** an expired session is refused, and so is an expired link. Revoking a link ends live sessions and blocks new codes. A random token is refused without naming the company.
  - **Access:** unshared reports (GL, TB, payroll, audit, AR ageing) return 403. A client session cannot call the accountant API.
- **Logs:** the incident log (outside the web root) holds no passwords, cookies, CSRF tokens or SINs.

## 8. UI, modes, themes, viewports, zoom

**Matrix on R139:**
- 46 menu screens (every action in the side menu) per run, company "Gate Test BC Ltd" with data.
- Light and dark at 1920×1080, 1366×768, 1440×900, 768×1024, 390×844, 360×800 and 320×640.
- Zoom 125%, 150% and 200% (1366×768 at device scale).
- Top navigation with guided (owner) mode at 1440 and 390, dark.
- Top navigation with full mode at 1366.

**Automatic checks per screen:**
- a heading renders;
- no page-level horizontal scroll;
- no controls off-screen outside scroll containers;
- no "undefined", "NaN" or "[object Object]" text;
- no JS errors or error toasts;
- no dialogs;
- no XSS execution.

**Result: 20 runs × 46 screens = 920 screen renders, 0 issues** (`beta-gate-evidence/ui-matrix.txt`). No screen needed an issue screenshot.

**R138 baseline:** 20 runs × 46 screens, 0 issues. That run used the sparse "Cash Basis" company, so the R139 run was repeated on the data-rich company.

**Key journeys on R139 (1440×900, screenshots in `beta-gate-evidence/shots/journey-desk-*.png`):**
1. Sign-in.
2. Dashboard with other-tax line ($13.00).
3. Tax Codes.
4. New invoice (BC code GST 5% + PST 7%).
5. GL Journal (1200/2050 not offered).
6. Customers (XSS payload shown as text).
7. Match and Post.
8. Trial Balance.
9. Unregistered company invoice ("Not registered for GST/HST — no tax").

**External requests from the app page: none.**

**Not executed:** screen-reader and keyboard-only accessibility audit; real mobile devices (emulated only); Safari/Firefox (Chromium only).

## 9. Payroll, marketing and legal claims

**Payroll (PASS):**
- An employee submitted with SIN fields is **refused** (422 `sin_not_accepted`); one without a SIN is created with Employee ID EMP-0001.
- No SIN ciphertext or last-four is stored for any company.
- The payroll API and the company backup contain no SIN.
- The "Payroll Support Tool" notice is present.
- Rate tables report `CRA-T4127-2026.2-verified-tax`, supported year 2026. **The owner must confirm this against CRA T4127 (2026 edition in force) before beta users run payroll.**

**Marketing and legal claims (for owner review, no changes made):**
- **Accurate and properly hedged:**
  - `security.html`: no SOC 2/ISO claim; "users remain responsible…".
  - `terms.html`: review before relying or filing.
  - Beta and pricing pages: no placeholder pricing.
- **Privacy Notice:** inaccurate while Clarity runs on `index.html` and `company.html` (§12.3).
- **Payroll wording:** `product.html` says "CPP, EI and income-tax outputs through Tegh's payroll engine", while the app positions payroll as a "Payroll Support Tool". Consider aligning.
- **Demo figures:** marketing demo figures (Northstar Design Studio, Maple Ridge…) are labelled "Demo interaction". Keep them clearly fictional.

## 10. Recovery, performance, operations (R139, PASS)

**Backup and restore:**
- A signed `.tegh` export restores into a new company. The restored copy equals the source on:
  - Trial Balance, account by account;
  - record counts (invoices 10, bills 3, customers 8, vendors 2, journals 25, bank lines 5, tax codes 15, tax detail rows 20, payments 3, notes 1);
  - AR/AP ageing;
  - tax code rates and GL mapping.
- No cross-company account references.
- A damaged backup is refused with 422 and nothing is written.

**Upgrade R118 → R139:**
- Schema 46, not degraded.
- The R137 tables are created automatically.
- The ledger is unchanged.
- The existing company stays on legacy tax rules, and a new invoice posts correctly.
- A pre-upgrade invoice can be settled after the upgrade.
- "Add Canadian tax codes" adds 14 codes plus 2115/1115 and switches the company.
- All journals balance.

**Fresh install:**
- Setup key flow (wrong key / foreign origin refused, second setup locked).
- In-app protected upgrade 43 → 46, then not degraded.

**Performance:** on a small synthetic data set:
- API medians are 7–23 ms (dashboard 13 ms, TB 9 ms, Tax Summary 23 ms, GL 23 ms).
- 20 concurrent dashboard loads all succeeded in 115 ms.
- **Large-volume performance was not tested.**

**Concurrency:** covered by AC-03 and AC-07 (§6).

**Support:** the in-app support route responds. `contact.html` has a form (no mailto). **Confirm that someone monitors form submissions before inviting users.**

## 11. Host acceptance: BLOCKED (no host access), exact owner commands

Run these on the IONOS host after uploading **R139**. Record each result, and paste outputs with secrets and personal data removed.

```sh
# H1. PHP lint with the host's PHP (all must say "No syntax errors")
cd /path/to/webroot && php -v && for f in api/*.php; do php -l "$f"; done | grep -v "No syntax errors" ; echo "lint done"

# H2. File identity (every line OK; prints nothing on success)
cd /path/to/webroot && sha256sum -c --quiet FILE-MANIFEST.sha256 && echo "ALL OK"
#     and the ZIP you uploaded:
sha256sum Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R139-Consolidated-IONOS-STAGING.zip
#     expect 0678a234e37793245dc6a2361209f49faf3c5799a44275e40eeb15d22428be57

# H3. HTTPS, routing and protections (from any machine)
curl -sI http://books-test.sraccountax.ca/app.html | head -3          # 301 to https
curl -s https://books-test.sraccountax.ca/api/health                    # {"ok":true,...,"schemaVersion":46}
for p in api/bootstrap.php api/schema.sql config.example.php RELEASE-MANIFEST.json tegh-build.json FILE-MANIFEST.sha256 README.txt R139-CHANGES.md storage/ release/ api/.htaccess; do printf "%s " $p; curl -s -o /dev/null -w "%{http_code}\n" https://books-test.sraccountax.ca/$p; done   # all 403/404
curl -sI https://books-test.sraccountax.ca/app.html | grep -i -E "cache-control|strict-transport|content-security"
curl -s https://books-test.sraccountax.ca/app.html | grep -c clarity.ms   # expect 0

# H4. Private config and storage are outside the web root and not readable over HTTP
ls -la ../sr-accountax-private/ ; curl -s -o /dev/null -w "%{http_code}\n" https://books-test.sraccountax.ca/../sr-accountax-private/config.php
```

**H5. Database:**
1. Before uploading, take a full backup: `mysqldump --single-transaction --routines DBNAME > pre-R139.sql`.
2. After upload, sign in as Platform Owner. If "Database upgrade" appears, run it once.
3. Settings → Integrity shows no control-account difference.

**H6. Email:**
1. Settings → Email Delivery Health → Send test (to the owner address). The status should say "accepted", not "rejected".
2. Check SPF, DKIM and DMARC show pass for the sender domain.
3. Send one invitation to a test inbox you control and accept it.

**H7. Browser (owner, private window, desktop and phone, light and dark):**
1. Client Viewing Link: create a link to your own test inbox, open it, request the code, sign in, and check the charts match the reports.
2. Revoke the link and confirm the session ends.

**H8. Live postings on a test company in the host database** (never DN-1001):
1. PST invoice and HST invoice; check the 2100/2110 split in the journal.
2. Credit note.
3. Customer receipt.
4. Manual journal to 1200 is refused.
5. For a test company with "Registered for GST/HST" unticked, an invoice charges no tax.

**H9. Real statements:** import one real CSV/OFX per bank account, Match and Post, and complete a reconciliation at $0.00 difference.

**H10. Backup restore:** restore a production backup into a **separate staging database**. Trial balance, AR/AP and GST/HST/PST/QST balances must match the source.

**H11. PHP limits:** `php -i | grep -E "post_max_size|upload_max_filesize|memory_limit"` (expect the shipped `api/php.ini` values: 24M / 10M / 256M).

## 12. Owner review pack (prepared, not reviewed)

**12.1 Sales-tax wording (R135 guide, Company Details and registration)** is generated per province:
- "How sales tax works for this company", with a $100 example table.
- "GST/HST you charge is money you hold for the CRA (2100). GST/HST you pay on business purchases is claimed back as an input tax credit (1100 GST/HST Recoverable). Your return pays the difference."
- "Tegh works out GST/HST from the customer's province, so a sale to a customer in another province uses that province's GST or HST rate."
- "General rates shown. Some items are zero-rated or exempt (for example basic groceries and many health services). Confirm registration and filing with the CRA [and the provincial agency], or ask your accountant."

Proposed additions for owner decision:
- "If you are not registered for GST/HST (for example a small supplier under $30,000), leave *Registered for GST/HST* unticked. Tegh will not add tax to your invoices, and tax you pay on purchases becomes part of the cost."
- "You charge another province's PST, QST or RST only if you are registered with that province."

**12.2 DEF-08 decision (starter codes for other provinces).** Choose one:
- **(a) Keep, and warn:** keep the current codes and add the wording above to the Tax Codes page.
- **(b) Change the defaults (recommended for beta):** starter codes for provinces other than the company's own charge only GST or HST. The home-province PST/QST/RST stays, and other provinces' PST/QST/RST are added only when the owner ticks "registered in …". This is a small change for R140 if approved.

**12.3 Privacy Notice and analytics.** Choose one:
- **(a) Remove** the Clarity snippet from `index.html` and `company.html`.
- **(b) Keep it** and add this draft disclosure (for legal review):

  > "Our public website (not the signed-in Tegh application) uses Microsoft Clarity to understand how visitors use pages. Clarity may set cookies and record page interactions such as clicks and scrolling. It is not loaded in the signed-in application or client viewing pages, and no accounting data is sent to it. You can opt out by blocking cookies for clarity.ms."

  With (b), also add a cookie banner if required for your audience.

**12.4 Product Activity note** (R135, unchanged):

> "Product activity, not inventory. … Under ASPE Section 3031 and IFRS (IAS 2), inventory is measured at the lower of cost and net realisable value using a cost formula such as FIFO or weighted average; Tegh does not track stock movements or cost layers yet. Record inventory and cost of sales in the general ledger (for example a count-based period-end adjustment) and report them from the Balance Sheet and Income Statement."

**12.5 Payroll:** confirm the CRA T4127 edition, and align the product page wording ("payroll engine") with "Payroll Support Tool".

## 13. Limitations and beta restrictions

1. **Invite-only beta on R139.** Keep `public_signup_enabled=false`.
2. **Registration check:** until DEF-08 is decided, onboard only businesses that are registered in every province whose customers they invoice, or tell beta users to deactivate the other provinces' PST/QST/RST starter codes.
3. **Unregistered businesses:** keep "Registered for GST/HST" unticked. An explicitly chosen code still charges tax.
4. **Dashboard tax card:**
   - The GST/HST figure is 2100/1100 only; custom tax accounts are shown as a separate amount.
   - Use the Tax Summary report for returns.
5. **Journals already posted to 1200/2050 before R139** are not changed. Reverse them and use notes or opening balances.
6. **Starter rates:** general rates. Owners must review them against CRA and provincial rules and their own registrations.
7. **Product Activity is not inventory:** no COGS or valuation.
8. **Payroll Support Tool:** users verify outputs; no SIN storage.
9. **Not tested:**
   - large data volumes;
   - Safari/Firefox;
   - real devices;
   - screen-reader accessibility;
   - production SMTP/DNS;
   - the IONOS host.
10. **Invitation links:** tokens are in the query string (OBS-2).

## 14. Deployment, backup and rollback (R139)

**Before:**
1. Full database dump.
2. Copy of the web root, `../sr-accountax-private/` (config, storage, incident log) and uploads.
3. Note the currently deployed package name and hash.

**Deploy:**
1. **Optional:** enable maintenance mode.
2. **Upload:** put the **contents** of the R139 ZIP at the web root, keeping private config, storage and uploads.
3. **Verify files:** `sha256sum -c FILE-MANIFEST.sha256`.
4. **Restart PHP:** restart PHP/opcache, or wait at least one minute before the first sign-in (OBS-3).
5. **Sign in:** as Platform Owner; run "Database upgrade" if shown (not expected when upgrading from R118 or later).
6. **Check:** run the DEPLOYMENT-NOTES "Verify after upload" list and §11.

**No migration** is needed for R139; R137 tables are created automatically on the first ordinary API request.

**Rollback (to R138 or earlier):**
1. Enable maintenance.
2. Restore the previous web-root copy (or upload the previous ZIP contents).
3. Restart PHP.
4. The database **does not need restoring** for code rollback: R139 adds no schema. Restore the pre-deploy dump only if data written after deployment must be discarded. Postings made under R139 are valid under R138.
5. Clear the browser caches of testers: the cache token changes back.

## 15. Owner checklist

- [ ] Upload **R140** (SHA-256 `c2e6bb61…ac4f`) to staging, not R138 or R139.
- [ ] On a real iPhone and Android phone: Payables → New Vendor Invoice, swipe up from the form fields; the page scrolls to Save.
- [ ] Run §11 H1–H11 on the host and paste results into the host-acceptance record.
- [ ] Decide DEF-08 (§12.2) and the Clarity/Privacy Notice question (§12.3).
- [ ] Review the sales-tax wording (§12.1) and the Product Activity note (§12.4).
- [ ] Confirm the payroll rate edition (§12.5).
- [ ] Confirm someone monitors the contact form and support requests.
- [ ] Only after all of the above: set `productionReady` / `acceptanceComplete` in the three manifests (owner sign-off).

## 16. Reproducing the gate

Test scripts are in `beta-gate-evidence/scripts/` (Node 22, Playwright 1.56, MariaDB client). The gate host was built as in §3.

**Order:**
1. `reset.sh <zip> <tag>`: fresh web root from the exact ZIP, empty database, cleared storage and mail.
2. `paths.sh`: HTTP probes, headers and lint.
3. `run-all.sh`: runs `01-install` … `10-ops`.
4. `11-upg-seed.mjs` then `12-upg-verify.mjs`, with `GATE_ORIGIN=https://gate.test:8443 GATE_DB=tegh_upg`: the upgrade test.
5. `matrix.sh` (the `ui-sweep.mjs` runs) and `journeys.mjs`: the UI.
6. `aggregate.py`: builds `test-matrix.md`.

**Test data:** the owner and member passwords in the scripts are synthetic test credentials for the throwaway gate database only. They are not used anywhere else.

**Evidence** (`beta-gate-evidence/`):
- `identity.txt`, `paths.txt`, `lint.txt`, `run-all.txt`
- `test-matrix.md` (every test with PASS/FAIL/INFO and evidence)
- JSON results per area
- reconciliation JSON
- `perf.json`
- UI matrix summary and journey screenshots
- `r138-baseline/`: R138 results for comparison

Screenshots and logs contain only synthetic data. No secrets, SINs or real financial information.


## Addendum: R140 (vendor invoice scrolling on phones)

| | |
|---|---|
| Package | `Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R140-Consolidated-IONOS-STAGING.zip` |
| SHA-256 | `c2e6bb611406a6e66d28c21e35a4ee6d639632cb9548d044ee35533e5890ac4f` |
| Source commit | `0f6d5a4` |
| FILE-MANIFEST.sha256 | 337 entries, all OK |
| Cache token | `5990-r140-tegh` |
| productionReady / acceptanceComplete | false / false (unchanged) |

**DEF-09 (High, usability, phones): New Vendor Invoice could not be scrolled.**
- **Cause:** on the vendor invoice page only, the form's layout box was a scroll box (`overflow-y:auto; overscroll-behavior:contain`, from the R32 polish rule). It was not the page's designated scroll area, so it had nothing of its own to scroll. On phones, iOS Safari in particular, a swipe starting on the form was caught by that box and not passed on to the page. The customer invoice form never had this combination.
- **Why the gate missed it:** the gate's UI checks measured layout, not touch scrolling. Emulated Chromium passes swipes on to the page, so this reproduces only on WebKit/iOS.
- **Fix:** one CSS rule in `assets/tegh-r120.css`. An invoice layout without `r22-fill-path` is not a scroll box.

**Retest on the exact R140 ZIP (fresh gate database):**

| Test | Result |
|---|---|
| Swipe-trap scan of all 46 menu screens at 390×844 and 360×800 | Before the fix: 1 (New Vendor Invoice). R140: **0** |
| Touch swipe and wheel on New Vendor Invoice at 1440×900, 1024×768, 768×1024, 390×844, 360×800 | **PASS** at every size. The phone layout now matches the customer invoice form, and a swipe reaches the bottom (Save as Draft / Record and Post) at 390×844. |
| Customer invoice form, same sizes | Unchanged, PASS |
| Full API gate suite | **200 PASS / 0 FAIL / 6 INFO** (same results as R139) |
| Path, header and asset probes; PHP 8.3 and 8.4 lint | 40/40 PASS; 81 files × 2, 0 failures |
| UI sweep, 46 screens at 1440×900, 390×844, 360×800 | 0 issues |
| iOS Safari / real iPhone | **BLOCKED** (WebKit is not available in the build environment). Owner check added to DEPLOYMENT-NOTES. |

Evidence: `beta-gate-evidence/r140/`, including `r140-scroll.txt` and the phone screenshots at the top and bottom of the form.

Also noticed, not changed: on phones the vendor invoice's "Tax Code" dropdown sits just outside the summary card, and its long label is cut off ("GST 5% + P…"). It still works. Cosmetic only.

# Tegh final beta QA report: invite-only beta release gate

Prepared 2026-09-30 by the build/QA agent (senior QA, security and accounting review). This is **not** the owner's review and **not** host acceptance.

## 1. Verdict

> **Update: R144 supersedes R142 for deployment.** R143 fixes four defects found by a new click-through test of every daily workflow (DEF-10 to DEF-13). R144 adds Tegh Intelligence, built into Tegh with no external AI. The gate was re-run on the exact R144 ZIP (SHA-256 `4e880cd39406ecef661b5c074a732fac8a0aef159f332fcb02dbabf41afcd914`): 360 PASS, 0 FAIL, 6 INFO. See "Addendum: R143 and R144". The verdict is still **NOT READY**, because host acceptance is open.
>
> **Update: R142 supersedes R141 for deployment.** R142 applies the owner-approved wording for payroll (outside Quebec), the sales tax guide and the Product Activity note. The gate was re-run on the exact R142 ZIP (SHA-256 `29e9898004c7aaaf4e842581a1bb159053166d8f2ed8e7a51812aeca85534da4`): 264 PASS, 0 FAIL, 6 INFO. See "Addendum: R142". The verdict is still **NOT READY**, because host acceptance is open.
>
> **Update: R141 supersedes R140 and R139 for deployment.** R141 fixes DEF-08 and OBS-2, removes the last Clarity allowance and adds features the owner requested. The gate was re-run on the exact R141 ZIP (SHA-256 `f80d3d6608dc4d0686bada3ce82bcf4146c7a72cbc2efacf72a88aee29581c1f`): 253 PASS, 0 FAIL, 6 INFO. See "Addendum: R141" at the end. The verdict is still **NOT READY**, because host acceptance and the owner review are still open.
>
> **Update (R140):** After this report was issued, a new defect was reported: "Can't scroll vendor invoice creation in mobile view" (DEF-09). It is fixed in R140, and the gate was re-run on the exact R140 ZIP. See the addendum at the end. Where this report says "deploy R139", deploy **R140** (SHA-256 `c2e6bb611406a6e66d28c21e35a4ee6d639632cb9548d044ee35533e5890ac4f`). The verdict is unchanged: **NOT READY**, for the same host-acceptance and owner-decision blockers.

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
   - ~~**Privacy Notice vs Microsoft Clarity:** the marketing pages `index.html` and `company.html` still load Clarity.~~ **Correction (R141):** this was wrong. `index.html` and `company.html` never loaded Clarity; the check matched the tagline "Control · Clarity · Intelligence". Only `app.html` loaded it, and R139 removed it. The `.htaccess` Content-Security-Policy still allowed `*.clarity.ms` and `c.bing.com`; R141 removes that. No owner decision is needed.
   - **Starter tax codes for other provinces (DEF-08):** by default, customers in QC, MB, SK and BC are charged that province's QST/RST/PST, even if the business is not registered there. **Fixed in R141** (option 12.2(b)): starter codes are GST only outside the home province.
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
| DEF-08 | Medium: sales tax | Starter codes | Starter codes apply by **customer** province. A BC company invoicing a QC customer charges QST $99.75 to 2115, and an MB customer is charged RST, even when the business is registered only for GST and BC PST. The R138 docs mention only home-province PST. | **Fixed in R141** (option b): starter codes are GST only outside the home province. Codes created earlier are unchanged. | R141: TX-00b/c/d, D8-01…05, UP-11 PASS |
| OBS-1 | Low | Install | A fresh install creates Schema 43. The owner must run the in-app protected upgrade (→46) once after first sign-in. This was not documented for fresh installs. | **Documented** in README (R139) | PASS: H-11, H-12 |
| OBS-2 | Low | Logs | Invitation tokens are in the URL query (`app.html?accountSetup=`), so they can reach host access logs. They are single-use with 72-hour expiry. Client-view tokens use the URL fragment. | **Fixed in R141**: invitation, setup and reset links carry the token after `#`, and the lookup is a same-origin POST | R141: OB-01…06 PASS |
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
- **Privacy Notice:** ~~inaccurate while Clarity runs on `index.html` and `company.html`~~. **Correction (R141):** those pages never loaded Clarity, and R141 also removes the CSP allowance. The notice is consistent with the package.
- **Payroll wording:** `product.html` said "CPP, EI and income-tax outputs through Tegh's payroll engine", while the app positions payroll as a "Payroll Support Tool". **Aligned in R141:** "Payroll Support Tool (Canada only)" with "estimates for you to review and confirm".
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

**12.3 Privacy Notice and analytics.** *Resolved in R141: no page loads Clarity, and the CSP no longer allows it. The original options are kept below for the record.* Choose one:
- **(a) Remove** the Clarity snippet from `index.html` and `company.html`.
- **(b) Keep it** and add this draft disclosure (for legal review):

  > "Our public website (not the signed-in Tegh application) uses Microsoft Clarity to understand how visitors use pages. Clarity may set cookies and record page interactions such as clicks and scrolling. It is not loaded in the signed-in application or client viewing pages, and no accounting data is sent to it. You can opt out by blocking cookies for clarity.ms."

  With (b), also add a cookie banner if required for your audience.

**12.4 Product Activity note** (R135, unchanged):

> "Product activity, not inventory. … Under ASPE Section 3031 and IFRS (IAS 2), inventory is measured at the lower of cost and net realisable value using a cost formula such as FIFO or weighted average; Tegh does not track stock movements or cost layers yet. Record inventory and cost of sales in the general ledger (for example a count-based period-end adjustment) and report them from the Balance Sheet and Income Statement."

**12.5 Payroll:** confirm the CRA T4127 edition, and align the product page wording ("payroll engine") with "Payroll Support Tool".

## 13. Limitations and beta restrictions

1. **Invite-only beta on R139.** Keep `public_signup_enabled=false`.
2. **Registration check** (*resolved by R141 for new starter codes; companies that added codes before R141 should still check them*): until DEF-08 is decided, onboard only businesses that are registered in every province whose customers they invoice, or tell beta users to deactivate the other provinces' PST/QST/RST starter codes.
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
10. **Invitation links:** tokens are in the query string (OBS-2). *Fixed in R141.*

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

- [ ] Upload **R144** (SHA-256 `4e880cd3…cd914`) to staging, not R138–R143.
- [ ] On a real iPhone and Android phone: Payables → New Vendor Invoice, swipe up from the form fields; the page scrolls to Save.
- [ ] Run §11 H1–H11 on the host and paste results into the host-acceptance record.
- [x] ~~Decide DEF-08 (§12.2) and the Clarity/Privacy Notice question (§12.3).~~ Done in R141 at the owner's request (DEF-08 option b; Clarity removed).
- [ ] Sales-tax, Product Activity and payroll wording: the owner approved the proposed text, and R142 ships it. Record a sign-off once your accountant has confirmed it.
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

## Addendum: R141 (gate follow-ups and owner-requested features)

| | |
|---|---|
| Package | `Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R141-Consolidated-IONOS-STAGING.zip` |
| SHA-256 | `f80d3d6608dc4d0686bada3ce82bcf4146c7a72cbc2efacf72a88aee29581c1f` |
| Source commits | `5d941fa` (code), `28cae05` (manifests) |
| FILE-MANIFEST.sha256 | 340 entries, all OK on the gate host |
| Cache token | `5990-r141-tegh` |
| productionReady / acceptanceComplete | false / false (unchanged) |
| Migration | Automatic on first request: `companies.country`; `province` VARCHAR(10) on companies, customers and vendors; `company_dashboard_mappings`. Needs ALTER and CREATE privilege. |

**Requested by the owner:**
- fix DEF-08 and OBS-2;
- remove Microsoft Clarity;
- Tax Code Report with editing of selected codes;
- taxes for other countries;
- country and province/state everywhere, starting from company registration;
- Dashboard Figures GL mapping;
- payroll wording aligned and marked Canada only.

This is a feature release on top of the gate fixes, not a gate-only fix. Every new feature has its own tests below.

**Correction to this report.** §1, §9 and §12.3 said that `index.html` and `company.html` still load Microsoft Clarity. That was wrong. The check matched the brand tagline "Control · Clarity · Intelligence". Git history shows that only `app.html` ever loaded the Clarity script, and R139 removed it. What remained was the `.htaccess` Content-Security-Policy allowance (`https://*.clarity.ms https://c.bing.com`), which R141 removes. The earlier sections are annotated in place.

**Defect status:**

| ID | R141 status | Evidence |
|---|---|---|
| DEF-08 | **Fixed.** Starter codes for provinces other than the company's own are GST only (HST provinces keep HST), with a note saying how to add the provincial tax once registered there. Codes created before R141 are unchanged. | TX-00b/c/d, D8-01…05, UP-11 |
| OBS-2 | **Fixed.** Invitation, account-setup and password-reset links use `#accountSetup=` / `#passwordReset=`. The page removes the token from the address bar and looks it up with a same-origin POST. Old `?accountSetup=` links still work until they expire. | OB-01…06 (OB-05: the only access-log line with a token is the deliberate old-style GET) |
| Clarity | **Removed** from the CSP; no file loads it | CL-01, path probes |

**Retest on the exact R141 ZIP** (fresh gate database, Apache + PHP 8.3 over HTTPS, STARTTLS mail sandbox):

| Area | Result |
|---|---|
| Full API gate suite (install, mail, accounting, tax, FX, security, client links, payroll, backup, ops) | **203 PASS / 0 FAIL / 6 INFO**. This is the R140 set plus 3 new DEF-08 tax checks. The 6 INFO items are the same as in R140 (CB-02, XS-01, PY-01, PY-07, LG-03, SP-02). |
| R141 tests (DEF-08, countries, foreign tax codes, OBS-2, Tax Code Report usage, Dashboard Figures, Clarity, payroll wording) | **38 PASS / 0 FAIL** |
| Upgrade R118 → R141 (seeded R118 database, R141 unzipped over it) | **12 PASS / 0 FAIL**: ledger unchanged, legacy tax mode kept, new columns added, country `Canada`, MB starter code GST only, Dashboard Figures available |
| **Total** | **253 PASS / 0 FAIL / 6 INFO / 0 BLOCKED** (host acceptance items in §11 remain BLOCKED, as before) |
| Path, header and asset probes; PHP 8.3 and 8.4 lint | 40/40 PASS; 83 files × 2, 0 failures |
| UI matrix (46 screens × 20 runs: light/dark at 1920, 1440, 1366, 768, 390, 360, 320; zoom 125/150/200 %; top-navigation guided/full) | **0 screens with issues** |
| R141 browser journeys at 1440×900 and 390×844 | 10/10 at both sizes. Covers Company Setup cards, Tax Code Report (Edit selected opens the first code with "1 more selected after this one"), foreign tax region editor, Dashboard Figures, US customer state field, vendor country, Company Details country, US company invoice, and Add company with United Kingdom (tax heading "Sales tax, VAT or GST", Payroll disabled) |
| Swipe-trap scan at 390×844 (R140 regression) | 0 traps |
| Independent figures | The D8, FC and DM expectations are hand-computed: NY $1,000 × 8.875 % = $88.75; US fallback 6 % = $60.00; dashboard bank = SQL sum of GL 1000; profit = −(4000) − 6000 from SQL. None use Tegh's calculation functions. |
| Not executed | iOS Safari / real devices; tax rates for other countries (the owner enters them; Tegh does not supply or verify them); large data volumes |

**New limitations:**
- The base currency is CAD for every company, including companies outside Canada.
- Dashboard Figures mappings are not in backups.
- Payroll Support is Canada only.
- Legacy-mode companies (no tax codes) do not give foreign customers a code automatically.

**Owner review added by R141 (prepared, not reviewed).** These are in addition to §12.1 and §12.4:
1. **Home-province starter-code note** (shown on each non-home PST/RST/QST code): "Starter code: GST only, because PST is charged only by businesses registered in British Columbia. If you register there, edit this code and add PST 7%." The same pattern applies to RST in Manitoba, PST in Saskatchewan and QST in Quebec. Please confirm that "charged only by businesses registered in" is accurate enough. Out-of-province registration rules (for example, BC and SK registration for remote sellers) are more nuanced.
2. **Non-Canadian company tax text:**
   - The heading is "Sales tax, VAT or GST"; the tick box is "Registered to charge sales tax, VAT or GST".
   - The help text reads: "Tegh does not create starter tax codes outside Canada. After the company is created, add your taxes under Tax Codes (for example VAT 20%, or CGST + SGST)…"
   - Confirm that no country-specific claim is wanted.
3. **Payroll Support notice:** "Tegh Payroll Support is for Canadian payroll only (CRA rules for Canadian provinces and territories)…". On `product.html`: "Payroll Support Tool (Canada only)… estimates for you to review and confirm".
4. **§12.1 sentence to update:** "Tegh works out GST/HST from the customer's province". From R141 the code is chosen by the customer's state or province, then their country.

Evidence: `beta-gate-evidence/r141/` (identity, lint, paths, run-all log, `r141.json`, `upg.json`, UI matrix JSON, journeys and screenshots). No passwords, setup keys or SMTP secrets are in the evidence; a scan was run before committing.

**Verdict for R141: NOT READY**, for the same reasons as before (§1 items 1, 2 and the owner review). Fewer owner decisions are now open: DEF-08 and Clarity are resolved. Deploy **R141** to staging.

## Addendum: R142 (owner-approved wording)

| | |
|---|---|
| Package | `Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R142-Consolidated-IONOS-STAGING.zip` |
| SHA-256 | `29e9898004c7aaaf4e842581a1bb159053166d8f2ed8e7a51812aeca85534da4` |
| Source commits | `e5791a6` (code), `09b0625` (manifests) |
| FILE-MANIFEST.sha256 | 341 entries, all OK on the gate host |
| Cache token | `5990-r142-tegh` |
| Migration | none |
| productionReady / acceptanceComplete | false / false (unchanged) |

The owner approved the replacement wording proposed after R141, and R142 ships it. The text was proposed by the build/QA agent and approved by the owner in conversation. It is not legal or tax advice, and the owner intends to have their accountant confirm it. Calculations and postings are unchanged.

**Correction to R141.** The R141 payroll notice said "CRA rules for Canadian provinces and territories". The code refuses Quebec employees (`quebec_payroll_unsupported`), so the claim was broader than the product. R142 says "Canadian payroll outside Quebec", and WR-10 checks that the claim matches the code.

**Found while testing R142.** The short tax guide at company registration still said "Customers in each province get their province's code automatically", without saying that other provinces' starter codes became GST/HST only in R141. It is updated in R142 and covered by journey step 04.

| Area | Result |
|---|---|
| Full API gate suite | 203 PASS / 0 FAIL / 6 INFO (same 6 INFO as before) |
| R141 tests (PW-01/02 updated to the R142 wording) | 38 PASS |
| R142 tests WR-01…11: exact starter-code notes for BC and QC on an Ontario company, six guide sentences, old sentences removed, Product Activity in the report and Ask Tegh, payroll notice in both bundles, product/subscription pages, Quebec employee refused, CRA T4127 tables listed (January 2026 from 2026-01-01; July 2026 from 2026-07-01) | 11 PASS |
| Upgrade R118 → R142 | 12 PASS |
| **Total** | **264 PASS / 0 FAIL / 6 INFO / 0 BLOCKED** (§11 host items still BLOCKED) |
| R142 browser journeys at 1440×900 and 390×844 | 8/8 at both sizes: payroll notice and rate-table line, Payroll Manager, Product Activity note, registration guide (registered, unregistered, United Kingdom), Tax Code Report, full Company Details guide on a synthetic legacy-mode company |
| R141 browser journeys | 10/10 at both sizes |
| UI matrix (46 screens × 20 runs) | 0 screens with issues |
| Swipe-trap scan at 390×844 | 0. The first attempt exited with a Node error immediately after the matrix run; a re-run completed with 0 traps. |
| Path and header probes; PHP 8.3 and 8.4 lint | 40/40 PASS; 83 files × 2, 0 failures |

**Test environment note.** The first R142 run had 16 FAILs in mail and client-link tests. The cause was the gate host's SMTP sandbox, which had stopped when the build container restarted; invitations could not be delivered, so later logins failed. After restarting the sandbox and resetting to the same ZIP, the full run passed. Those FAILs came from the environment, not the package.

**Limitation added:** starter codes created before R142 keep the R141 note text until they are edited.

Evidence: `beta-gate-evidence/r142/`, including `r142.json`, the journeys JSON and the phone and desktop screenshots. A secrets scan was run before committing.

**Verdict for R142: NOT READY**, still only because of host acceptance (§11) and production email (§1 item 2). Deploy **R142** to staging.

## Addendum: R143 and R144 (click-through workflow test, Tegh Intelligence)

| | R143 | R144 |
|---|---|---|
| Package | `…-Hotfix-R143-Consolidated-IONOS-STAGING.zip` | `…-Hotfix-R144-Consolidated-IONOS-STAGING.zip` |
| SHA-256 | `7ed8b3856c0756df2a1cd2fd5a65bf4ff48bebaf31441baa421ba339b5a749a1` | `4e880cd39406ecef661b5c074a732fac8a0aef159f332fcb02dbabf41afcd914` |
| Source commits | `f670518`, `185db7f` | `a570ce7`, `c1fe9d3`, `90d3609` |
| FILE-MANIFEST.sha256 | 342 entries, all OK | 344 entries, all OK |
| Cache token | `5990-r143-tegh` | `5990-r144-tegh` |
| Migration | none | automatic: `company_insight_dismissals` (first dismissal) |
| productionReady / acceptanceComplete | false / false | false / false |

### Why a new kind of test
Before new features were started, the owner asked whether the app works as a whole. Until now the gate checked business rules through the API, and the browser runs opened every screen without completing workflows. A new **click-through workflow suite** (`beta-gate-evidence/scripts/e2e/`) now drives every daily workflow through the screens on a fresh synthetic company, as a user would. Every result is checked against the database, with expected figures worked out by hand:
1. Customer, then an invoice: 2 × $500 + HST 13% = $1,130.00, posting Dr 1200 / Cr 4000 / Cr 2100.
2. Credit note returning one unit: $565.00.
3. Customer payment.
4. Vendor, then a vendor invoice: $200 + HST $26, then a vendor payment.
5. GL journal.
6. Statement upload, duplicate protection and cancelling an import.
7. Match and Post with a tax code ($56.50 = $50 + $6.50 HST).
8. Linking recorded payments.
9. Reconciliation to $0.00 and Complete.
10. Profit and Loss, Balance Sheet and Trial Balance totals ($665.00 = $665.00).
11. Dashboard cards.
12. Payroll: setup, employee and draft pay run, checked against CRA T4127 July 2026 by hand.
    - Gross $2,000.00, CPP $110.99, EI $32.60.
    - Income tax $254.82 against a hand calculation of $254.83.
    - Then verification, the reviewed-and-verified tick, Post to GL, and employer CPP and EI.
13. Tegh Assist answers.
14. Invoice PDF and report CSV export.

### Defects found and fixed in R143
| ID | Severity | Defect | Fix | Test |
|---|---|---|---|---|
| DEF-10 | Medium, usability | A statement preview left open by a refresh, a closed tab or an expired session blocked re-uploading the same file ("Cancel that preview…"), and no screen listed it. | A new upload of the same file for the same account replaces the stale draft. Drafts create no bank lines or GL entries. The replacement is audited. | W9-01, W9-03, W9-04 |
| DEF-11 | High, accounting workflow | A receipt or vendor payment recorded with Record Payment could not be linked to its bank line after a statement import. The server supported it, but no screen sent it. Users could only exclude the line or post it again (double counting). | Match and Post shows **"Already recorded?"** with the matching payments. **Link to this payment** reuses the payment's journal entry. | W11-01a/b … W11-04: nothing posted twice; GL 1000 = statement $282.50 |
| DEF-12 | Medium, Tegh Assist | "how much money is in the bank", "cash in bank", "what is in my chequing account", "how much money is left in the bank" and "how much money do I owe" were not understood. "who do I owe money to" was confused with "who owes me money". | Phrasings added. The receivables rule no longer matches "who do I owe". The 144-question regression set passes 144/144. | W17-01 |
| DEF-13 | Low, display | The invoice screen showed 2 × $500.00 = "Amount $1,130.00". | The column is labelled "Total incl. tax", as on the emailed invoice. | screenshot |
| (wording) | Low | A balanced period that ends in the future said "Ready to complete", then refused. | It now says "Balanced. You can complete it once the period has ended." | W12-00 |

**Observations (not changed):**
- Report exports (Excel, CSV, PDF, Print) are under the table's Actions ⋮ → Export… menu.
- GL Journal Entry excludes bank, AR, AP and tax control accounts, by design.
- On the Balance Sheet, GST/HST Recoverable is under "Other Assets".
- Employee province fields are free text.

### Tegh Intelligence (R144)
This is built into Tegh, as the owner requested: **no external AI**.

**Features:**
- a dashboard brief;
- a Tegh Intelligence page:
  - anomaly watch: duplicates, unusual amounts, tax-region mismatch, parked balances, old bank lines, overdrawn bank, unposted payroll;
  - "Not a problem" and Undo, which are audited;
  - who to chase first;
  - cash runway with a what-if;
- bank suggestions with confidence and reason, and Use suggestion;
- Tegh Assist answers spending, sales, top-expense and top-customer questions from the posted books.

**Safeguards:**
- **No outbound calls.** IN-64 inspects the module for HTTP clients, sockets and the AI provider.
- **Read-only.** IN-61: no journal entries are created and bank lines stay pending.
- **Company isolation.** IN-62.
- **CSRF protection.** IN-63.
- **No guessing:** a line with no history gets no suggestion (IN-31).

**Regression found during the R144 gate.** The first R144 ZIP (SHA-256 `7066756c…f1eb`) failed W11-01. The new suggestion lookup is a POST, and the app's request helper treats any POST as a change and re-renders the page. That removed the R143 "Already recorded?" box. Insights requests are now exempt (they change no accounting data), and the ZIP was rebuilt. That earlier ZIP was never released.

### Results on the exact ZIPs (fresh gate database, Apache + PHP 8.3 HTTPS, STARTTLS mail sandbox)
| Area | R143 | R144 |
|---|---|---|
| Full API gate suite (install, mail, accounting, tax, FX, security, client links, payroll, backup, ops) | 203 PASS / 6 INFO | 203 PASS / 6 INFO |
| R141 / R142 tests | 38 / 11 PASS | 38 / 11 PASS |
| Click-through workflow suite | **51 PASS** | **51 PASS** |
| R144 Intelligence tests (seeded company; hand-computed: brief cash $4,887.00 and +$4,943.50, overdue $2,599.00, GST/HST $167.00, profit $1,825.00, suggestion 67%, telephone $1,000.00 this year and $800.00 in Q3, sales $3,300.00) | — | **35 PASS** |
| R144 browser checks at 1440×900 and 390×844 | — | **22 PASS** |
| **Total** | **303 PASS / 0 FAIL / 6 INFO** | **360 PASS / 0 FAIL / 6 INFO** |
| UI matrix (46 screens × 20 runs) | not run (superseded by R144) | **0 screens with issues** |
| Swipe-trap scan at 390×844 | — | **0 traps** |
| Upgrade R118 → R144 | — | **13 PASS** (ledger unchanged; Tegh Intelligence works on the upgraded company) |

**Test environment note.** An earlier R143 run was void: two runs overlapped after a hung helper script was restarted, and they reset the same database (27 FAILs, all from the overlap). A single clean run on the same ZIP gave the R143 results above. Gate runs now go through `gate-run.sh`, one at a time.

Evidence: `beta-gate-evidence/r143/` and `beta-gate-evidence/r144/`; scripts in `beta-gate-evidence/scripts/` (including `e2e/`, `15-r144.mjs`, `journeys144.mjs`, `gate-run.sh`).

**Verdict for R144: NOT READY.** As before, only host acceptance (§11) and production email (§1 item 2) are open. Deploy **R144** to staging.

## Addendum: R145 (PDF statement converter, Document Intake)

| | R145 |
|---|---|
| Package | `…-Hotfix-R145-Consolidated-IONOS-STAGING.zip` |
| SHA-256 | `4bde2d850051e3a71ae28b0382f8fe524cc5739df4d76f53dd2ac863ccccb181` |
| Source commits | `11d8489` (code), manifests commit after it |
| FILE-MANIFEST.sha256 | 348 entries, all OK |
| Cache token | `5990-r145-tegh` |
| Migration | none (browser-side changes only) |
| productionReady / acceptanceComplete | false / false |

The owner supplied three real business statements (a chequing statement with withdrawals / deposits / balance columns, a business Visa statement and a business chequing statement) and asked for the converter and Document Intake to be improved.

**Handling of the real statements.**
- They were used only in a private folder on the test machine.
- They were never committed or copied into evidence, and their contents are not quoted here.
- Only aggregate figures were compared, against each statement's own printed totals.
- The committed tests use made-up statements and documents in the same layouts.

### Bank Statement Converter
| Real statement | R144 reader | R145 reader |
|---|---|---|
| Chequing (date / description / withdrawals / deposits / balance) | 40 rows, 19 needing manual amounts, net wrong | 135 rows, none manual. Opening + rows = closing to the cent; 144/144 running balances agree |
| Business Visa | 21 rows, net wrong | 21 rows. Previous balance owed + rows = new balance to the cent |
| Business chequing | 0 rows | 254 rows. Balance forward + rows = last balance to the cent; 35/35 running balances and 18/18 page totals agree |

### Document Intake
On six made-up documents, the R144 reader got **19 of 41** fields right and the R145 reader got **41 of 41**:
- a text PDF invoice;
- a Québec invoice (TPS/TVQ, "185,69 $");
- a receipt photo with an ambiguous date;
- a scanned PDF;
- a fuel receipt with tax included;
- a small low-contrast photo.

### Defects found and fixed in R145
| ID | Severity | Defect | Fix | Test |
|---|---|---|---|---|
| DEF-14 | High, usability | In Payables › Document Intake › Upload document, the Upload and Extract Locally button was about 35 px wide, 401 px tall and pushed outside the panel. At desktop width there was no visible way to upload. | The upload form is a single column inside menu panels. | DI-UI0 (button 372×40 inside the panel) |
| DEF-15 | High, compatibility | OCR of a scanned (image-only) PDF failed with "getOrInsertComputed is not a function" in browsers without that 2026 JavaScript method (Chromium 141 here). The bundled pdf.js 5.6 uses it on the page and in its worker. | The standard method is installed where missing, on the page and through a same-origin worker entry. | DI-I4, DI-SCAN |
| (accuracy) | Medium | The R144 intake reader silently read 03/04/2026 as 3 April, and missed several fields: French labels, labels with values on the next line, receipt numbers, and dates beside a due date. | The reader was rewritten. Ambiguous dates are offered both ways and never guessed. | DI-I1…I6, DI-AMB, DI-UI3 |

### Results on the exact ZIP (fresh gate database, Apache + PHP 8.3 HTTPS, STARTTLS mail sandbox)
| Area | R145 |
|---|---|
| Full API gate suite | 203 PASS / 6 INFO |
| R141 / R142 / R144 tests | 38 / 11 / 35 PASS |
| Click-through workflow suite | **51 PASS** |
| R144 browser checks, 1440×900 and 390×844 | **22 PASS** |
| R145 converter and Document Intake tests (16-r145) | **44 PASS / 1 INFO** (the R144-vs-R145 field comparison) |
| Upgrade R118 → R145 | **13 PASS** |
| **Total** | **417 PASS / 0 FAIL / 7 INFO** |
| Paths and headers | 40 PASS |
| UI matrix (20 runs) | **0 screens with issues** |
| Swipe-trap scan at 390×844 | **0 traps** |

**What 16-r145 covers.**
- **Converter, engine level.** Six made-up statements in the three layouts plus an ISO-date layout, one with a missing line, and one with no column headings. Each is checked for rows, dates (including a December → January year change), net, money in and out, opening and closing balances, running balances and page totals. The missing-line statement must show amber, and the no-headings statement must fall back to the general reader.
- **Converter, on screen.** The review screen's green or amber line, and the hand-off of statement balances to the server preview.
- **Document Intake.** Six documents with hand-written expected fields. On screen: upload, the live subtotal + tax = total check, choosing a date reading, and saving the review as ready.
- **Nothing posted.** Nothing was imported, drafted or posted.

Expected figures come from the fixture generators' integer-cent arithmetic, not from Tegh code.

Evidence: `beta-gate-evidence/r145/` (results, screenshots, made-up fixtures) and `beta-gate-evidence/scripts/` (`16-r145.mjs`, `conv/`).

**Verdict for R145: NOT READY.** As before, only host acceptance (§11) and production email (§1 item 2) are open. Deploy **R145** to staging.

## Addendum: R146 (OCR clean-up fix) and the website converter v10

| | R146 |
|---|---|
| Package | `…-Hotfix-R146-Consolidated-IONOS-STAGING.zip` |
| SHA-256 | `9a2fa4ae7634596eaada6f6d02bcdae837964266e722f73445585e7b1ea7f82a` |
| FILE-MANIFEST.sha256 | 349 entries, all OK |
| Cache token | `5990-r146-tegh` |
| Migration | none |
| productionReady / acceptanceComplete | false / false |

| ID | Severity | Defect | Fix | Test |
|---|---|---|---|---|
| DEF-16 | High, Document Intake | R145's OCR clean-up stretched contrast between the 1st and 99th percentile. On a clean scan with little text, both points fell on the white background, every off-white pixel turned black, and OCR read nothing (0/6 fields on a sparse courier invoice). | The stretch is applied only to genuinely low-contrast pages (24–95 grey-level spread). | DI-I7: 6/6 fields. Seven documents: R146 47/47; R145 41/47; R144 19/47 |

The defect was found while porting the reader to the owner's website converter (below), where the same clean-up blanked a scanned statement.

**Results on the exact R146 ZIP:**

| Check | Result |
|---|---|
| Gate and upgrade tests | **418 PASS / 0 FAIL / 7 INFO** (R145 tests 45 PASS / 1 INFO; upgrade R118 → R146 13 PASS) |
| Paths and headers | 40 PASS |
| UI matrix (20 runs) | 0 screens with issues |
| Swipe traps | 0 |

**SR AccounTax Bank Statement Converter v10** (the owner's separate website, from the owner-supplied backup; update in `sr-bank-statement-converter-v10/`, tests in `beta-gate-evidence/website-converter-v10/`):
- **Reader and checks.** The R145 column reader is ported. It adds a statement check panel and a chain check across consecutive statements of the same account.
- **Scanned statements.** OCR keeps word positions, so the column reader works on scans. The pdf.js 6.1 `getOrInsertComputed` failure is fixed on the page and in the worker.
- **Settings and exports.** New account-type setting, QBO export, credit-card OFX, and a "Statement Check" sheet in the Excel export.

These were tested on the exact update files over a fresh copy of the backup, with test-only stand-ins for the member login and database:

| Test | v9 | v10 |
|---|---|---|
| Owner's three real statements | 21 / 0 / 0 rows | 135 / 21 / 254 rows; each balances to the cent; figures only, statements not stored |
| Scanned statement | crash | reads and balances |
| Consecutive statements | — | chain check links them and flags a missing month |
| Free plan | — | 25-row sample, with a whole-statement check |
| Exports | — | six formats valid; QBO and card OFX checked by hand |

**Verdict for R146: NOT READY.** As before, only host acceptance (§11) and production email (§1 item 2) are open. Deploy **R146** to staging.

## Addendum: R147 and R148 (owner's public pages, home page at the root, invitation Create account tab)

| | R148 |
|---|---|
| Package | `…-Hotfix-R148-Consolidated-IONOS-STAGING.zip` |
| SHA-256 | `9708a4355f7c0613eeb45296eacb966b04317e4040154e8e08cdc503b4a4b9b8` |
| FILE-MANIFEST.sha256 | 351 entries, all OK |
| ZIP entries not 0644 | **0** (new gate check) |
| Cache token | `5990-r148-tegh` |
| Migration | none |
| productionReady / acceptanceComplete | false / false |

**R147**
- **Public pages.** The 17 public pages from the owner's "R146 Marketing-Aligned" package are included. They were audited in `beta-gate-evidence/audit-r146-marketing/`: claims match the code, links work, and no new scripts were added.
- **Owner approval needed.** Privacy and Terms version 2026-10-01 still need owner/legal approval.
- **Site root.** `/` now serves the home page; it previously redirected to `app.html`, so the marketing page appeared not to open. Old root links that carry sign-in parameters still go to the app.
- **AUD-1.** File permissions in the package are corrected.

**R148: Sign in and Create account tabs**
- **Invitation link.** It opens Create account, showing:
  - the invited email, companies and roles;
  - Your name, password and terms.
- **Existing users** accept with their current password.
- **Without a link.** The tab explains the invitation-only beta and accepts a pasted invitation link.
- **Expired or used links** are explained in the tab.
- **Name.** The typed name is stored; `< >` and control characters are refused.

**Test clock.** The R147 run on 2026-10-02 showed 6 FAILs (IN-10, IN-11, IN-12, IN-20, W11-01, W12-01). All six had hand-computed expectations for "today = 2026-10-01", for example "62 days late" and payments that default to today's date. None of them involves the R147 changes.

From R148 on, the gate pins the host clock to 2026-10-01: PHP-FPM and MariaDB through libfaketime, and the workflow-suite browser through Playwright's clock. With that in place, all six pass.

**Results on the exact R148 ZIP**

| Area | Result |
|---|---|
| Full suite | **432 PASS / 0 FAIL / 7 INFO** |
| R148 invitation and tab tests (17-r148) | 14 PASS, including account creation with a typed name, the existing-user path, pasted and used links, phone width, and server-side name validation |
| Upgrade R118 → R148 | 13/13 |
| Paths and headers | 42 PASS (including "/ serves the home page" and "/?invite= reaches the app") |
| UI matrix | 0 screens with issues |
| Swipe traps | 0 |
| Public pages | 34/34 renders clean; 0 bad links |

One cosmetic flaw was found in the screenshots and fixed before the final ZIP: the Terms checkbox on the invitation form had been stretched into a large box.

**Verdict for R148: NOT READY.** As before, only host acceptance (§11), production email (§1 item 2) and owner/legal approval of Privacy/Terms 2026-10-01 are open. Deploy **R148** to staging.

## Addendum: R149 (invitation codes instead of links)

| | R149 |
|---|---|
| Package | `…-Hotfix-R149-Consolidated-IONOS-STAGING.zip` |
| SHA-256 | `31d022c726e099872760aebb4abf652e5109321c1853ea8b8cae7136e7744833` |
| FILE-MANIFEST.sha256 | 352 entries, all OK |
| ZIP entries not 0644 | 0 |
| Cache token | `5990-r149-tegh` |
| Migration | none (uses the existing `invitation_attempts` table) |
| productionReady / acceptanceComplete | false / false |

**What changed (owner request: "Whoever is invited will get invitation code in their mail box that they will need to put to create account")**
- **The invitation email carries a code, not a link.** It shows:
  - a 10-character code, for example `K7M2Q-PX9RT`, which never contains 0, O, 1, I or L;
  - the invited email address;
  - the steps: open Create account (`app.html?register=1`), enter the email and code, then a name and password.
- **Create account tab.** It asks for the email address and the invitation code. The code is formatted as it is typed; lower case and spaces are accepted. Then the tab shows the invited companies and roles, Your name, password and terms. Existing users accept with their current password.
- **Security.**
  - Each code is tied to the invited email address. The server stores only a keyed hash of email and code.
  - Codes are single-use and expire after 72 hours.
  - Wrong attempts are limited to 10 per 15 minutes per email and per network address, which gives a 429 and a "wait 15 minutes" message. Correct look-ups are not counted.
  - Wrong code, wrong email, used and expired codes all get the same message, so the reply does not reveal which invitations exist.
- **Compatibility.** Invitation links emailed before R149 still open the account form.
- **Unchanged.** The existing limit of 30 new invitations per hour per inviter is unchanged. The test suite ages its own earlier test invitations instead of relaxing that limit.

**Results on the exact R149 ZIP (host clock pinned to 2026-10-01)**

| Area | Result |
|---|---|
| Full suite | **438 PASS / 0 FAIL / 7 INFO** |
| R149 invitation-code tests (18-r149) | 20 PASS. They cover:<ul><li>the code email;</li><li>tabs and code formatting;</li><li>account creation with name, role and terms stored;</li><li>wrong code, other email, used code and invalid letters;</li><li>the attempt limit, in the server and the screen;</li><li>correct look-ups not counted;</li><li>existing users;</li><li>phone width;</li><li>name validation;</li><li>a pre-R149 link;</li><li>no JS errors.</li></ul> |
| Earlier invitation tests (02b, OB-01 to OB-05) | Rewritten to use codes; all PASS |
| Upgrade R118 → R149 | 13/13 |
| Paths and headers | 42 PASS |
| UI matrix | 0 screens with issues |
| Swipe traps | 0 |
| Public pages | 34/34 renders clean; 0 bad links |

Evidence: `beta-gate-evidence/r149/` (identity, summary, test matrix, `r149.json`, screenshots). The test scripts are in `beta-gate-evidence/scripts/`.

**Verdict for R149: NOT READY.** As before, only these remain open:
- host acceptance (§11);
- production email (§1 item 2);
- owner/legal approval of Privacy/Terms 2026-10-01.

Deploy **R149** to staging. After deploying, invite a test address and check that the email shows a code and that the code creates the account.

## Addendum: R150 (public pages always light; the main URL)

| | R150 |
|---|---|
| Package | `…-Hotfix-R150-Consolidated-IONOS-STAGING.zip` |
| SHA-256 | `5e8b72a4fd6d84cdf36b939cfe10d37144f23897b778452ef63afeabe3435e61` |
| FILE-MANIFEST.sha256 | 353 entries, all OK |
| ZIP entries not 0644 | 0 |
| Cache token | app `5990-r149-tegh` (unchanged); public-page stylesheets `?v=5990-r150` |
| Migration | none |
| productionReady / acceptanceComplete | false / false |

**Owner report (2026-10-05)**

**1. "Main URL is redirecting to app.html."**
- The ZIP the owner uploaded is the R146 Marketing-Aligned package again (SHA-256 `6fb6db08…28dd`). Its `.htaccess` still contains `RewriteRule ^$ /app.html [R=302,L]`, and its files are mode 0600 (AUD-1).
- Both were fixed in R147 and are absent from R150: the gate's paths check shows "/ serves the home page" PASS.
- The fix is to deploy R150 including `.htaccess`.

**2. "index.html is opening the page in dark mode."**
- Reproduced. Since R27 the public stylesheets followed the device setting (`prefers-color-scheme: dark`), and the dark version had low-contrast text and a grey haze over the hero.
- R150 removes the public-page dark mode and declares `color-scheme: only light` in CSS and in a meta tag on all 17 pages. Browsers' automatic dark mode leaves the pages alone.
- The app keeps its own Light / Dark / System setting.

**Results on the exact R150 ZIP (host clock pinned to 2026-10-01)**

| Area | Result |
|---|---|
| Full suite | **438 PASS / 0 FAIL / 7 INFO** |
| Public pages in a dark-mode browser (`dark-check.mjs`, 390×844, device dark and Chrome forced dark) | **34/34 light**. Before the fix: 0/34 |
| Upgrade R118 → R150 | 13/13 |
| Paths and headers | 42 PASS |
| UI matrix | 0 screens with issues |
| Swipe traps | 0 |
| Public pages | 34/34 renders clean; 0 bad links |

Evidence: `beta-gate-evidence/r150/` (including before/after screenshots of the home page in dark mode).

**Verdict for R150: NOT READY.** The open items are unchanged: host acceptance, production email, and owner/legal approval of Privacy/Terms 2026-10-01. Deploy **R150** to staging.

## Addendum: R151 (one document form, itemized vendor invoices, notes with typed references, in-page Edit, scrolling)

| | R151 |
|---|---|
| Package | `…-Hotfix-R151-Consolidated-IONOS-STAGING.zip` |
| SHA-256 | `d9db2ec5fe401a51eb1815a9e496f8f4255b1c8feb3ae91844456c2025d765e3` |
| FILE-MANIFEST.sha256 | 355 entries, all OK |
| ZIP entries not 0644 | 0 |
| Cache token | `5990-r151-tegh` |
| Migration | Automatic on first use: `bill_lines`; `accounting_notes.reference_number` and nullable `source_id`; new note-line columns |
| productionReady / acceptanceComplete | false / false |

The owner requests and what changed are listed in `tegh-app/R151-CHANGES.md`. Owner decisions recorded on 2026-10-05:

- A typed note number links to the invoice when it is in Tegh. Otherwise the note is recorded against the party with its own tax and GL per line.
- Issued documents can be edited for dates and notes only.

**Results on the exact R151 ZIP (host clock pinned to 2026-10-01)**

| Area | Result |
|---|---|
| Full suite including upgrade | **497 PASS / 0 FAIL / 7 INFO** |
| 19-r151 server accounting | 40/40, all postings hand-computed; see the list below |
| 20-r151ui browser | 19/19; see the list below |
| Click-through journeys (E2E, rewritten for the new form, same expected figures) | 51/51 |
| Upgrade R118 → R151 | 13/13 |
| Paths and headers | 42 PASS |
| UI matrix | 0 screens with issues |
| Swipe traps | 0 |
| Public pages | 34/34 renders clean |
| Public pages in dark mode | 34/34 light |

**19-r151 covers:**
- per-line posting, including non-recoverable PST added to the line's account;
- tax-inclusive lines;
- draft edit and edit of an issued document's details;
- validation and company isolation;
- single-amount compatibility;
- cash-basis recognition by line, checked to the cent;
- linked and unlinked notes, open-credit application, the unlinked debit-note receivable, voids;
- receivable and payable trial balances, ledgers, registers and backup.

**20-r151ui covers:**
- the shared form;
- Add line keeping the scroll position;
- saves checked in the database;
- linking by typed number;
- Edit from the register Actions menu, the invoice detail page and the note register, with no pop-ups;
- the recurring schedule and quick-add;
- the sidebar wheel moving the menu by exact amounts right after navigation;
- phone width.

**Defects found and fixed during R151 testing (before the final ZIP)**

| Defect | Fix |
|---|---|
| The first sidebar fix double-scrolled. A passive wheel can be applied before the page sees it. | The wheel over the menu is now handled on the sidebar itself. |
| The form's exchange-rate field shared the name `rate` with the line rate. A screen reader or form filler could pick the wrong one. | The first full gate run caught it in 13 journey checks. The field was renamed and the ZIP rebuilt and re-gated. |

**Sidebar caveat.** The owner's exact sidebar symptom could not be reproduced in Chromium. The fix addresses the lost first wheel after navigation and nested scroll boxes; the owner's browser and device are requested.

**Verdict for R151: NOT READY.** As before, the open items are host acceptance, production email, and owner/legal approval of Privacy/Terms. Deploy **R151** to staging.

## Addendum: R152 (statement reader Stage 1, invoice reader Stage 2)

| | R152 |
|---|---|
| Package | `…-Hotfix-R152-Consolidated-IONOS-STAGING.zip` |
| SHA-256 | `db1f4ae401bd562e7f136d7a06ee0283b483c9524154179c77fc3c3e886ae24c` |
| FILE-MANIFEST.sha256 | 357 entries, all OK |
| ZIP entries not 0644 | 0 |
| Cache token | `5990-r152-tegh` |
| Migration | Automatic on first use: `native_agent_documents.extracted_json`, table `vendor_document_memory` |
| productionReady / acceptanceComplete | false / false |

The owner asked for "stage 1 and 2" of the OCR plan: improvements to the existing readers, with no external AI. What changed is listed in `tegh-app/R152-CHANGES.md`.

**Results on the exact R152 ZIP (host clock pinned to 2026-10-01)**

| Area | Result |
|---|---|
| Full suite including upgrade | **528 PASS / 0 FAIL / 7 INFO** |
| 21-r152 statement and invoice readers | 31/31; see the list below |
| Earlier suites (R145 converter and intake, R151 documents and UI, journeys) | unchanged: 45 + 1 INFO, 40, 19, 51 |
| Upgrade R118 → R152 | 13/13 |
| Paths and headers | 42 PASS |
| UI matrix | 0 screens with issues; 0 swipe traps |
| Public pages | 34/34 renders clean; 34/34 light in dark mode |

**21-r152 covers:**

*Statements* (synthetic; fictional banks; expected figures from the generator's own integer-cent arithmetic):
- **12 single-statement layouts**, each with row count, net, opening and closing balances, first and last dates, sample rows and the balance check:
  - RBC-, BMO- (two-line headings), Scotia- (MM/DD, overdrawn), Desjardins- (French, code column), National Bank- (DD/MM) and Tangerine-style accounts;
  - Amex-style and French Visa cards;
  - a card with MM/DD dates across December–January;
  - a credit union with DR/CR and OD balances;
  - two-line rows;
  - French amounts split into pieces.
- **A PDF with three statements**: all listed; the same account read together with the chain check; the other account chosen alone; also through the converter screen.
- **Numeric dates that read both ways**: not guessed.
- **The six R145 statements**: unchanged.

*Invoices* (6 synthetic invoices through the Document Intake screen):
- line items as a table, with quantity × price checks, in English and French;
- verification stores the reader's candidate, not the document text;
- account suggestions from the vendor's history, by wording and by most-used account;
- hand-off of all lines into the vendor invoice form, with a check against the document's total and automatic linking;
- duplicate warning, and refusal without confirmation;
- supplier memory for the day/month order and for an invoice-number pattern;
- requests carry only identity fields;
- company isolation;
- backup.

**Owner's real statements.** The three statements the owner provided earlier stay local and were not copied or committed. They were re-read with the R152 reader and give the same rows, dates, amounts and balance checks as R151. The first R152 draft split one of them into two statements: a summary box printed a date range. The rule was tightened before the gate, so a different period now starts a statement only on a page that also shows an opening balance.

**Defects found and fixed during R152 testing (before the final ZIP)**

| Defect | Fix |
|---|---|
| The save message at the top right covered the page-header buttons (for example **Upload document**) for about 6 seconds after a save. Found by the first R152 gate run (IN-09, IN-10). | Clicks now pass through the message, which has no controls. ZIP rebuilt and re-gated. |
| The vendor invoice form prefilled from an invoice picked the vendor's province tax code (NS HST 14%) while the supplier charged GST 5%. The form total silently differed from the document. Seen in the gate screenshot. | The form now compares its total with the document's printed total. It warns until they match. 21-r152 IN-04 checks the warning and the match. ZIP rebuilt and re-gated. |
| Coding by wording missed "Toner cartridge HP 26A (high yield, black)" against an earlier "Toner cartridge HP 26A" line. | Matching now measures the share of the shorter description's words. |

**Limits.**
- Line items are read as a table only from text PDFs; scans use line-by-line reading.
- Supplier memory does not learn field positions.
- The statement layouts are synthetic copies of common styles. Real statements from other banks are still needed (host acceptance item "Real bank statement files").

**Verdict for R152: NOT READY.** The open items are unchanged from R151: host acceptance, production email, and owner/legal approval of Privacy/Terms. Deploy **R152** to staging.

## Addendum: R153 (company address and short name, company labels in multi-company reports, side menu arrows, theme switch)

| | R153 |
|---|---|
| Package | `…-Hotfix-R153-Consolidated-IONOS-STAGING.zip` |
| SHA-256 | `2b6f51a831e2ef13b05e0e0b9b8364244d21bb73a9a3369ee928875202db1662` |
| FILE-MANIFEST.sha256 | 359 entries, all OK |
| ZIP entries not 0644 | 0 |
| Cache token | `5990-r153-tegh` |
| Migration | Automatic on first use: nullable `companies` columns `short_name`, `address_line1`, `address_line2`, `city`, `postal_code`, `phone`, `contact_email` |
| productionReady / acceptanceComplete | false / false |

The owner asked for four things. What changed is listed in `tegh-app/R153-CHANGES.md`.

1. **Company address on invoices.** Company setup and Company Details now have:
   - street address, address line 2, city, postal code, phone and business email;
   - a short name.

   Customer invoices print the company address, unless the invoice template has its own address. The address is copied into the invoice when it is issued, so issued invoices do not change later.
2. **Company names on report lines.** When several companies are selected, report lines show a **Co.** column with the company short name, for example "ALP". Exports include the column too.
   - Reports that cannot be combined say so and ask for one company: GL Account Report, Bank GL Report, Budgets.
   - Bank Reconciliation, Fixed Assets and the monthly Profit and Loss show the combined list with a note above it.
3. **Side menu arrows.** Each side menu group has an arrow that expands or collapses only that group.
4. **Theme switch.** Sun, moon and screen icons in the top bar switch between Light, Dark and System. System follows the device setting and changes when the device setting changes. On phones, one icon cycles through the three.

**Results on the exact R153 ZIP (host clock pinned to 2026-10-01)**

| Area | Result |
|---|---|
| Full suite including upgrade | **548 PASS / 0 FAIL / 7 INFO** |
| 22-r153 | 20/20; see the list below |
| Earlier suites (R145, R151, R151 UI, R152, journeys) | unchanged: 45 + 1 INFO, 40, 19, 31, 51 |
| Upgrade R118 → R153 | 13/13 |
| Paths and headers | 42 PASS |
| UI matrix | 0 screens with issues; 0 swipe traps |
| Public pages | 34/34 renders clean; 0 not light in dark mode |

**22-r153 covers** (two synthetic companies; expected figures are the amounts the test entered, not values computed by the app):
- **Company profile (CO-01 to CO-05, UI-01):**
  - fields saved on create and on edit;
  - short name made from the initials when left blank;
  - an invalid email and a short name over 12 characters are refused;
  - the invoice snapshot carries the company address, phone and email;
  - a template's own address wins;
  - an issued invoice keeps its address after the company moves;
  - the Company Details screen.
- **Two companies selected (MC-01 to MC-08):**
  - the invoice register labels lines ALP ×2 and BTA ×1;
  - Profit and Loss Total Income is $1,300.00 ($1,050.00 + $250.00), with no notice saying figures were withheld;
  - Receivable Ageing, Day Book, Audit History, Tax Code Report and Trial Balance each carry the Co. column;
  - GL Account Report, Bank GL Report and Budget versus Actual ask for one company;
  - the Excel export contains the Co. column;
  - with one company selected there is no Co. column.
- **Side menu (NAV-01, NAV-02):**
  - arrows open and close groups without changing the page;
  - a closed group stays closed after navigating;
  - clicking the group name opens it.
- **Theme (TH-01 to TH-03):**
  - Light, Dark and System, each kept after a reload;
  - System on a dark device draws dark (page brightness 56) and turns light with the device;
  - the phone cycles through the three;
  - the Appearance settings show the same icons.
- **R153-JS:** no page errors.

**Gate runs**

| Run | ZIP | Result |
|---|---|---|
| 1 | `db20fc1e…` (first R153 build) | 547 PASS / **1 FAIL** / 7 INFO. R152 IN-10 failed: the Upload document menu closed between choosing the file and clicking Upload, because the app closes open menus when a page finishes drawing late. This race existed before R153 (see the R152 addendum). The test now reopens the menu and clicks again, up to three times, as a user would. The product behaviour is unchanged and is listed under limits. |
| 2 | `db20fc1e…` | 548 PASS / 0 FAIL / 7 INFO. Reviewing the screenshots then found the defect below, so this ZIP was not shipped. |
| 3 | `2b6f51a8…` (final) | **548 PASS / 0 FAIL / 7 INFO**. The IN-10 retry was not needed. |

**Defects found and fixed during R153 testing (before the final ZIP)**

| Defect | Fix |
|---|---|
| With several companies selected, Profit and Loss showed "Select one company … No partial consolidated figures have been displayed" above the combined figures that R153 now draws. Found in the run 2 screenshot. | The note now says that the monthly comparison columns need one company and that the list below covers every selected company. MC-02 checks that no contradicting notice is shown. ZIP rebuilt and re-gated. |
| During development: P&L combined totals showed only the first company; Day Book voucher numbers collided across companies; the r23 registers hid the Co. column; a collapsed menu group reopened when navigating; System theme on a dark device drew half-dark pages. | Fixed before the first gate run; each is covered by a 22-r153 check. |

**Limits.**
- With several companies selected, GL Account Report, Bank GL Report and Budgets need one company.
- Combined reports need the selected companies to share one currency.
- A menu opened while a page is still finishing its first draw can close itself once. Reopening it works.
- The website bank statement converter (v10) is unchanged.

**Verdict for R153: NOT READY.** The open items are unchanged: host acceptance, production email, and owner/legal approval of Privacy/Terms. Deploy **R153** to staging.

## Addendum: R154 (GL reports and Budgets with several companies) and website converter v11

| | R154 |
|---|---|
| Package | `…-Hotfix-R154-Consolidated-IONOS-STAGING.zip` |
| SHA-256 | `0a63a93c90e9c677d28df8f2de78824a1a33004dab3d478ac27a90a922858f8c` |
| FILE-MANIFEST.sha256 | 360 entries, all OK |
| ZIP entries not 0644 | 0 |
| Cache token | `5990-r154-tegh` |
| Migration | None |
| productionReady / acceptanceComplete | false / false |

The owner asked: "Let budgets and GL reports work with multiple companies too, and upgrade website converter too." What changed is listed in `tegh-app/R154-CHANGES.md`. It says 12 checks for 23-r154; the suite has **11**. That file is inside the gated ZIP, so it was left unchanged rather than altered after the gate.

With several companies selected, three screens that asked for one company in R153 now run for every selected company:
- **General Ledger Account Report.** You choose the account by code. A **Balance by company** table shows each company's opening, debits, credits and closing balance, with a Combined row. A company without that code is named. The ledger lists each company's account with its own running balance.
- **Bank General Ledger Report.** One bank account is chosen per company, or the company is marked "Not included".
- **Budgets.** Every company's budgets are listed with a Combined row. Budget versus Actual combines the budget chosen for each company. Creating, editing and closing budgets stays per company.

**Results on the exact R154 ZIP (host clock pinned to 2026-10-01)**

| Area | Result |
|---|---|
| Full suite including upgrade | **559 PASS / 0 FAIL / 7 INFO** |
| 23-r154 | 11/11; see the list below |
| 22-r153 | 20/20 (MC-06 updated; see below) |
| Earlier suites (R145, R151, R151 UI, R152, journeys) | unchanged: 45 + 1 INFO, 40, 19, 31, 51 |
| Upgrade R118 → R154 | 13/13 |
| Paths and headers | 42 PASS |
| UI matrix | 0 screens with issues; 0 swipe traps |
| Public pages | 34/34 renders clean; 0 not light in dark mode |

**23-r154 covers** (two synthetic companies, GLA and GLB). The expected figures were worked out by hand from the documents the test creates:
- **GL-01:** GL 4000 closes at $1,000.00 CR for GLA and $250.00 CR for GLB, combined **$1,250.00 CR**.
- **GL-02:** the ledger groups are "GLA · 4000 …" and "GLB · 4000 …", and every line is labelled.
- **GL-03:** account 6990 exists only in GLA, and the report names GLB as not having it.
- **BL-01:** the bank ledger shows each company's receipt, $600.00 and $250.00, with combined debits of $850.00.
- **BL-02:** with GLB left out, only GLA's lines appear.
- **BU-01:** both budgets are listed, with a Combined row of planned **$1,500.00** and actual **$1,250.00**.
- **BU-02:** Budget versus Actual shows GLA at 1,200.00 / 1,000.00 and GLB at 300.00 / 250.00.
- **EX-01:** the Excel export has the Co. column and both companies.
- **BU-03:** there is no create form in the combined view.
- **ONE-01:** with one company selected, the three screens are unchanged.
- **R154-JS:** no page errors.

**Changed expectation.** R153's MC-06 checked that these three reports ask for one company. The owner asked for that to change, so MC-06 now checks that each opens its combined screen and shows no "Select one company" notice.

**Gate runs**

| Run | ZIP | Result |
|---|---|---|
| 1 | `0a63a93c…` | 548 PASS / 0 FAIL / 7 INFO. The 23-r154 suite ran, but its results were not recorded because the test script did not save them. The test was fixed; the product was not changed. |
| 2 | `0a63a93c…` (same ZIP) | **559 PASS / 0 FAIL / 7 INFO**, with R154 11/11 recorded. |

**Found and fixed during development (before the ZIP was built)**
- The per-company summary tables were hidden by the report module, which hides other tables on a report page. They now use their own wrapper.
- The KPI tiles on the combined Budgets page were removed by the report layout. The totals now sit in a Combined row.
- In grouped reports, the group total label wrapped inside the narrow Co. column. It now runs across the empty cells beside it.

**Website bank statement converter v11** (separate from the Tegh ZIP: `sr-bank-statement-converter-v11/`, evidence in `beta-gate-evidence/website-converter-v11/`)
- **Reader.** v11 uses the Tegh R152 statement reader on the website's free and Pro converters.
- **Synthetic R152 statements.** v10 read 2 of 12 exactly; v11 reads **12 of 12** exactly (rows, net, and balance check passing). See `results-r152-fixtures.txt`.
- **Three-statement PDF.** v10 refused it ("more than one account"). v11 reads it as three statements, each balanced, with a 22-row net of −$1,805.81 and the …4567 January → February chain carried forward. This works on both free and Pro.
- **Unchanged from v10:**
  - the R145 statements A–F;
  - the v10 chain set, where January → February carries forward and the missing March statement is flagged;
  - the scanned statement read with OCR;
  - the owner's three real statements: 135, 21 and 254 rows, all balanced. Only row counts were recorded; the files stay local.
- **Fixed in v11:** the chain check skipped an account's statements when another account's statements fell between them by date.

**Verdict for R154: NOT READY.** The open items are unchanged: host acceptance, production email, and owner/legal approval of Privacy/Terms. Deploy **R154** to staging. Upload the converter v11 files to the converter site using `INSTALL-v11.txt`.

## Addendum: R155 (private beta package and beta-readiness checks)

| | R155 |
|---|---|
| Package | `Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R155-PRIVATE-BETA.zip` |
| SHA-256 | `e7e998dd439c73570d97386dc8a90468367116c21d7920bb70b91fe56242703c` |
| FILE-MANIFEST.sha256 | 361 entries, all OK |
| ZIP entries not 0644 | 0 |
| Cache token | `5990-r155-tegh` |
| Migration | None (optional config `app.beta_notice`) |
| productionReady / acceptanceComplete | false / false |

The owner asked for the build-side steps of an invitation-only, sample-data beta. The readiness evidence, mapped to the owner's pre-invitation checklist, is in `beta-kit/BETA-READINESS.md`.

**Release package review (owner's step 2)**

| Finding | Action |
|---|---|
| README said the three manifests "identify this package as R151". | Corrected. The manifest generator now keeps that sentence in step with the release. |
| Manifest history entries were off by one: `r150Identity` held R151, …, `r152Identity` held the first R153 build. Each rebuild of a release had moved the outgoing manifest into a fixed key. | Every `rNNNIdentity` (R135–R154) is rebuilt from the last manifest committed for that release in git. All 20 now name their own release. |
| **DEF-17 (Medium, privacy/legal record):** the Terms and Privacy pages show "document version: 2026-10-01", but their meta tag and the version Tegh records at invitation acceptance were 2026-09-09. Every acceptance named a version the person had not been shown. | Fixed: both set to 2026-10-01. 24-beta BS-07 compares the recorded version with the text printed on the pages. Acceptances recorded before R155 keep 2026-09-09. |
| The DEF-10 regression checks W9-03/W9-04 (re-uploading a statement) only ran on a database where the statement was already imported, so a fresh gate run never executed them. They were missing from the R154 results. | The journey now uploads the same statement a second time in every run. Both pass, and E2E rose from 51 to 53. |
| Earlier findings DEF-01…16 and OBS-1…4 | Their regression tests (listed in the earlier addenda) were re-checked on R154 and R155 and all pass. DEF-13 is screenshot-based. DEF-09 is covered by the swipe-trap scan, which found 0. |

**New gate suite 24-beta (14 checks), all PASS on the exact ZIP**
- **Public sign-up:** registration without an invitation is refused with 403 (BS-01).
- **Uploaded files:** a Document Intake file and an invoice attachment cannot be downloaded through another company (404) or by a user without access (403). Their own company gets the same bytes (BS-02, BS-03).
- **Removed member:** the session they are signed in with is refused at once, and a fresh sign-in has no access (BS-04).
- **Revoked invitation:** it cannot be accepted (410) (BS-05).
- **Password reset:**
  - the email link works once;
  - the new password works and the old one is refused;
  - earlier sessions end (401).

  (BS-06)
- **Terms version:** the recorded version equals the version printed on the pages, 2026-10-01 (BS-07).
- **Backup with files:** a company backup restored into a new company returns the Document Intake file and the invoice attachment byte for byte (BR-10).
- **Repeated requests:**
  - the same payment sent three times (two at once) is recorded once, and the balance is $300.00 = $400.00 − $100.00 (BA-01);
  - double-clicking Save & Issue creates one invoice and one journal (BA-03).
- **Balancing:** every posted entry on the gate database balances, and every company nets to zero (BA-02).
- **Currency:** a company cannot be created with a non-CAD ledger. A USD 1,000.00 invoice at 1.35 shows in USD on its line, and the combined totals add CAD 1,350.00. The expected total CAD 1,873.45 was worked out by hand (BM-01).
- **Beta notice:** the badge is visible in the top bar and not covered, at desktop and phone width; contrast is 6.8:1 light and 10.0:1 dark; tapping it shows the full text (BN-01).
- No page errors.

**Found and fixed during R155 testing**

| Defect | Fix |
|---|---|
| The first R155 build drew the beta notice as a line under the top bar. The page header covered it, so it was invisible, although BN-01 passed because it only checked the element and its colours. Found in the BN-01 screenshot. | The notice is now a badge inside the top bar, beside the company selector. BN-01 now checks that nothing covers the badge at three points, at 1440 and 390 px. ZIP rebuilt and re-gated. |

**Gate runs**

| Run | ZIP | Result |
|---|---|---|
| 1 | `79b19d16…` (first R155 build) | 575 PASS / 0 FAIL / 7 INFO. Not shipped: the notice-visibility defect above. |
| 2 | `e7e998dd…` (final) | **575 PASS / 0 FAIL / 7 INFO**, including BETA 14/14, E2E 53, upgrade R118 → R155 13/13, 42 path and header checks, 20 layout runs with 0 issues, and 34 public pages. |

**Host-level backup and restore (owner's step 5).** On the exact R155 installation after run 2:
- `beta-ops/tegh-backup.sh` backed up the database and upload folder, with an off-site copy simulated as a second folder.
- `tegh-restore-verify.sh` restored them into a separate database and folder. Result: `RESTORE VERIFIED`. All 24 files were identical, the row counts of 167 tables were equal, and the posted totals of 19 companies were equal.
- A separate HTTPS installation served from the restored data loaded identical trial balances for all 25 companies (120 account rows) and identical uploaded files (7 compared).
- A backup with one changed byte was refused.

The runbook is `beta-ops/RECOVERY-AND-ROLLBACK.md`.

**Not established here.** These need the owner, the host or an independent reviewer:
- the beta host's HTTPS, configuration, scheduled backups and restore test;
- real email delivery to an external mailbox;
- an independent security review;
- the privacy and terms text and its legal review, including a procedure for deleting a person's user account, which the app cannot do today;
- the owner's accountant walkthrough;
- a real iPhone/Safari check;
- the host acceptance items.

**Verdict for R155:** suitable to begin the **invitation-only, sample-data beta** once the owner's open items in `beta-kit/BETA-READINESS.md` are closed. **NOT READY** for real client or employee information.

## Addendum: R156 (owner's review of the beta package; DEF-18)

| | R156 |
|---|---|
| Package | `Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R156-PRIVATE-BETA.zip` |
| SHA-256 | `d00b0fc6ae69943caa68353b17955eb7a0b5fcf15928578a30a3cf6fe797d223` |
| FILE-MANIFEST.sha256 | 364 entries, all OK |
| Cache token | `5990-r156-tegh` |
| Migration | None. Required config: the `operator` section. |
| Terms / privacy version | 2026-10-07 |
| productionReady / acceptanceComplete | false / false (not changed) |
| Evidence bundle | `Tegh-R156-Gate-Evidence.zip`: the raw results, logs and every script of the final run, with `verify-evidence.py` |

**The owner's review findings and what was done**

| # | Finding | Action | Evidence |
|---|---|---|---|
| 1 | The 575 passing tests could not be verified: the report, raw results and scripts were not in either ZIP. | A separate evidence bundle now carries the evidence for this exact package hash. It includes: <ul><li>every raw result file;</li><li>the full logs;</li><li>every gate script as run;</li><li>this report;</li><li>`verify-evidence.py`, which checks the bundle files and the package hash and recounts PASS/FAIL from the raw files without using this report.</li></ul> The R152 statement benchmarks that read the owner's real statements are left out. | `python3 scripts/verify-evidence.py <zip>` prints `VERIFIED`. |
| 2 | The beta conditions were not in the Terms. | The Terms have a "Private beta" section: invitation only, free, sample data only, data may be reset, no guarantee of availability, not for real filings or payroll, feedback, leaving the beta. The page version, its meta tag and the server's recorded version moved to 2026-10-07 together. | 24-beta BS-07 and BS-09 |
| 3 | The privacy notice lacked the operator, privacy contact, hosting details and a deletion procedure. | <ul><li>The Terms and Privacy pages show the operator's legal name, address, privacy and support emails, hosting provider, data location, backup location and email provider, from a new `operator` section in `config.php`.</li><li>While any field is blank, both pages say the notice is incomplete, and invitations are refused (409 `operator_details_missing`) on both invitation paths.</li><li>New sections cover testers' own information, retention, access, correction and deletion with a response time, and incidents.</li><li>"Delete login" now also replaces the person's address in invitations, sent-email records, the platform log and the incident log; the deletion record keeps a SHA-256 only.</li><li>Company audit history keeps the address because it is part of each entry's hash; the notice says so.</li></ul> | BS-08 (deletion clean-up), BS-09 (texts and the incomplete warning), BS-10 (a configuration without operator details reports 8 missing fields; 2 invitation paths are guarded) |
| 4 | "No analytics" conflicted with the published wording on website measurement. | Checked against `api/marketing.php`: the public pages count nine named actions per day in a temporary file on Tegh's own server, with no identifiers or cookies. The Privacy Notice and `DATA-INVENTORY.md` now call this first-party website measurement and state that there is no third-party analytics, advertising or session recording. | BS-09 |
| 5 | The restore script ran destructive steps without enforcing a separate target. | The restore refuses: <ul><li>a database name without "restore";</li><li>the live database (read from the live `config.php`);</li><li>the live storage folder, or anything inside or around it;</li><li>any folder without a `.tegh-restore-target` marker naming the database.</li></ul> It is never run against the live site. A separate manual procedure covers a real recovery. | beta-ops B1–B6 (live data unchanged after every refused attempt) |
| 6 | The backup was not consistent while records changed. | The backup pauses changes with Tegh's maintenance flag: requests get 503 "Tegh is making a backup" and nothing is written. It takes the check figures before and after the dump and the file archive, and keeps the backup only if they are identical. Row counts are now compared exactly, with no tolerance. | beta-ops A1–A4: a writer ran throughout; 503 during the pause, success before and after; restore exact. |
| 7 | An empty database stopped verification (`grep -c` with `set -e`). | Zero postings and zero files are handled explicitly in both scripts. | beta-ops C1–C2: an empty installation backs up and restores. |
| 8 | The HTTPS redirect named only the two existing domains. | A generic rule redirects plain HTTP for any host to HTTPS on the same host, drops a port in the Host header, and respects a TLS proxy (`X-Forwarded-Proto`). The two existing domains keep their own rules. | paths: `beta.example.test` and `:80` give 301 to `https://beta.example.test/…`; `X-Forwarded-Proto: https` gives 200, no loop. |

**Correction to the R155 addendum.** The R155 addendum said the app cannot delete a person's user account. That was wrong: Platform owner › Users › **Delete login** existed. R156 extends it as described in finding 3.

**DEF-18 (Medium, wrong period / screen unavailable): the browser's calendar used instead of the accounting date.** Found by gate run 1, which started at 03:00 UTC.
- **Symptom.** Tegh keeps its books on Toronto time. The server allows a date up to the later of the Toronto and UTC dates, so a bank line can carry the browser's new day. When the browser's date is already the next day:
  - Match and Post took its From date from the newest bank line and its To date from Toronto's date. It then showed "Banking Workspace Unavailable — From date cannot be after To date".
  - Period presets ("this month", "this year" and others) started from the browser's calendar. On the 1st of a month this gave a period of the new month only.
  - The older form module set default and latest allowed dates on bills, expenses, payroll and voids from the browser's calendar.
- **Who is affected.** Browsers ahead of Toronto: Atlantic Canada and Newfoundland for about an hour each night, travellers, and computers set to UTC from about 8 pm Toronto time.
- **Not affected.** Posted figures; the problem is the period and dates offered on screen.
- **Fix.** Every client "today" now uses the Toronto accounting date. Match and Post's To date reaches at least the newest line. The older module loads under the release token so browsers do not keep the old copy.
- **Regression check 24-beta BD-01.** The browser is set to UTC+14 at 8:30 pm Sep 30 Toronto time. A company with bank lines dated Sep 28 and Oct 1 must open Match and Post with no inverted or refused period request. "This month" must give 2026-09-01 to 2026-09-30, worked out by hand. On the first R156 build it failed both ways: a 400 for 2026-10-01 > 2026-09-30, and "this month" of 2026-10-01 to 2026-10-01. It passes on the final build.
- **Test host.** The gate clock was pinned by whole UTC days, so a run started between 00:00 and 04:00 UTC was on Sep 30 in Toronto. It is now pinned to 2026-10-01 12:00 Toronto. The browsers stay on UTC, which keeps the DEF-18 conditions under test.

**Gate runs**

| Run | ZIP | Result |
|---|---|---|
| 1 | `cd999dfd…c92e` (first R156 build) | Stopped. E2E W10–W14 (7) and Intelligence IN-11/12/20 (3) failed because of DEF-18. Not shipped. |
| 2 | `d00b0fc6…d223` (final) | 578 PASS / 1 FAIL / 7 INFO. The failure was BD-01 itself: it relied on a bank line created later in the run by the journeys, and correctly refused to pass without it. The check now creates its own lines (test-only change; the ZIP is unchanged). |
| 3 | `d00b0fc6…d223` (final) | **579 PASS / 0 FAIL / 7 INFO**, with BETA 18/18, E2E 53/53, upgrade R118 → R156 13/13, 45 path, header and redirect checks, 20 layout runs with 0 screens with issues, 0 swipe traps, 34 public pages light and without overflow, and 0 BLOCKED. |

The 7 INFO rows are recorded for information and are **not** counted as passes: CB-02, XS-01, PY-01, PY-07, LG-03, SP-02 and DI-CMP. PY-01 is INFO because a submitted SIN was refused; PY-03…05 confirm no SIN is stored.

**Backup and restore on the exact R156 installation after run 3**
- `beta-ops-test.sh`: **12 PASS / 0 FAIL**, covering A1–A4, B1–B6 and C1–C2 as above.
- A quiet backup restored into the designated restore-test installation gave `RESTORE VERIFIED`: 25 files identical, 167 tables with equal row counts, posted totals equal for 19 companies, and every company balanced.
- The restored HTTPS site, served with the R156 code, showed **identical trial balances for all 26 companies** (121 account rows) and 7 identical uploaded files.

**Not established here.** These need the owner, the host or an independent reviewer:
- the real operator details in `config.php` (invitations stay blocked until they are filled in);
- the beta host's HTTPS, its configuration, scheduled backups and one restore test there;
- real email delivery to an external mailbox;
- legal review of the Terms and Privacy text;
- an independent security review;
- the owner's accountant walkthrough;
- a real iPhone/Safari check;
- the host acceptance items.

**Verdict for R156:** suitable to begin the **invitation-only, sample-data beta** once the operator details are filled in and the owner's open items in `beta-kit/BETA-READINESS.md` are closed. **NOT READY** for real client or employee information.

## Addendum: R157 (company onboarding, Tegh Assist tour, time zone and owner requests)

| | R157 |
|---|---|
| Package | `Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R157-PRIVATE-BETA.zip` |
| SHA-256 | `a35d2b6cb0ea19ded656d5701a7df43cf8476dfac2ea5ac35d521447265e6743` |
| FILE-MANIFEST.sha256 | 367 entries, all OK |
| Cache token | `5990-r157-tegh` |
| Migration | Additive, on first use: the table `company_onboarding` and the column `companies.timezone` |
| productionReady / acceptanceComplete | false / false (not changed) |
| Evidence bundle | `Tegh-R157-Gate-Evidence.zip`: raw results, logs and every script of the run; verify with `scripts/verify-evidence.py` |

**What the owner asked for, and where it is checked (suite 25-r157, 21 checks)**

| Request | Done | Checks |
|---|---|---|
| After sign-up, an onboarding page instead of "create company" | The first screen is onboarding step 1 of 6, with all six steps shown. After the company is created, the onboarding page opens. | ON-06, ON-07 |
| Steps: company; chart of accounts by import, template or by hand; tax codes the same three ways; data import with sub-options; other steps | Six steps: company, chart of accounts, tax codes, bank and card accounts, data import, and team and invoices. <ul><li>The chart template adds the standard chart and two bank accounts, and is refused a second time.</li><li>The tax-code file import is checked against hand-worked rates and accounts: ON-HST 13% to 2100/1100; BC GST 5% to 2100/1100 plus PST 7% to 2110, not recoverable.</li><li>A file naming an unknown account is refused with its row number.</li><li>Data import has seven sub-options.</li></ul> | ON-01, ON-03, ON-04, ON-08 |
| Modules locked until all steps are complete; the user can mark steps complete | <ul><li>Only an owner or admin can mark steps (a viewer gets 403).</li><li>Steps can be reopened, but the company step cannot.</li><li>Every module shows a lock, and a report opens the onboarding page instead.</li><li>The setup screens stay open, with a bar back to setup.</li><li>Every change is audited.</li></ul> | ON-05, ON-07, ON-11 |
| Every sign-in opens onboarding until it is complete; afterwards it does not | Checked by signing in again: before completion onboarding opens, after completion Home opens. Companies created before R157 are never locked. | ON-02, ON-09, ON-13 |
| Onboarding in Settings | Settings › Getting started › Onboarding, and Take the Tegh Tour. | ON-10 |
| Tegh Assist guides the user afterwards, attractively | A spotlight tour of the real menus with Tegh Assist cards: 8 stops in this run, every one spotlit. | ON-12 |
| An animated "start with bank statement import" that opens the banking import | The tour ends on that card and opens Upload Statement. An animated card on Home stays until the first statement is imported or dismissed. | ON-12, ON-13 |
| Time zone in company setup | <ul><li>Asked in company setup, Company Details and the guided forms; an unknown zone is refused.</li><li>The company's "today" follows it on the server and in the browser.</li><li>At the same moment, an invoice dated the UTC+14 company's today is accepted there and refused as a future date in a Toronto company. The expected dates are worked out from the pinned clock.</li></ul> | TZ-01, TZ-02, TZ-03 |
| Quick Actions set up again on every login; no keyboard shortcuts shown | <ul><li>Choices were already kept per company across sign-ins (checked).</li><li>A company with no choice yet, such as a new one, now uses the person's latest choice instead of the defaults.</li><li>Each shortcut shows Ctrl+Alt+N, and Ctrl+Alt+2 from another page opens the second one.</li></ul> | QA-01, QA-02 |
| Document Intake and the PDF converter marked Beta, with a note to check the fields | A Beta badge plus a note on Document Intake and on Upload Statement, in its Bank Statement Converter card. The website converters are marked too; they are outside the package. | BE-01 |
| A Document Intake section on vendor invoices, marked Beta | On a new vendor invoice, above the fields. The file goes through Document Intake's own reading (the document count rose by one), which offers "Create vendor invoice". | BE-02 |
| Light, dark and system in the profile menu, replacing the earlier icons | Three icon buttons in the profile menu; the top-bar icons are gone. R153 TH-01 and TH-02 were rewritten to the new location and pass: Dark is remembered after reload, System follows the device, and all three are reachable on a phone. | R153 TH-01, TH-02; 24-beta BN-01 |

**Found and fixed during R157 testing**
- `tegh-r27.js` read a page property before any page existed. This raised a page error when preferences loaded first, which onboarding made more likely. It is now guarded, and R157-JS and BETA-JS record no page errors.
- The Beta notes first landed outside their page. They now sit inside the card they describe.

**Changes to existing tests**
- R153 TH-01/TH-02 and 24-beta BN-01: the theme buttons are in the profile menu, by the owner's request. The behaviour checked is the same.
- `run-all.sh` runs 25-r157 and clears the invitation throttle before it, as it does for 24-beta.

**Gate run on the exact ZIP** (`a35d2b6c…6743`; host clock pinned to 2026-10-01 12:00 Toronto)

| Result | |
|---|---|
| **600 PASS / 0 FAIL / 7 INFO**, 0 BLOCKED | R157 21/21, BETA 18/18, R153 20/20, E2E 53/53, upgrade R118 → R157 13/13 |
| 45 path, header and redirect checks | all PASS |
| 20 layout runs | 0 screens with issues, 0 swipe traps |
| 34 public pages | light, no overflow, 17 internal links valid |

The 7 INFO rows are the same as in R156 and are not counted as passes.

**Backup and restore on the R157 installation**
- `beta-ops-test.sh`: 12 PASS / 0 FAIL. The writer got 96 accepted requests and 22 "making a backup" refusals.
- Quiet backup and restore: RESTORE VERIFIED with 26 files, 168 tables (the new onboarding table included) and the posted totals of 20 companies.
- The restored site showed identical trial balances for all 33 companies and 8 identical files.

**Not established here** (unchanged from R156):
- the operator details on the beta host;
- the host's HTTPS, backups and restore test;
- external email;
- legal review;
- an independent security review;
- the owner's accountant walkthrough;
- a real iPhone/Safari check, which is also needed for the onboarding page and the tour.

**Verdict for R157:** suitable for the invitation-only, sample-data beta once the owner's open items are closed. **NOT READY** for real client or employee information.

## Addendum: R158 (company deletion; the backup items of the R157 review)

| | R158 |
|---|---|
| Package | `Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R158-PRIVATE-BETA.zip` |
| SHA-256 | `a0add32007f697b9f6f3a6ad1e3b4359051a8c74ea2a3a64c52c833799e29b57` |
| FILE-MANIFEST.sha256 | 368 entries, all OK |
| Cache token | `5990-r158-tegh` |
| Migration | None |
| productionReady / acceptanceComplete | false / false (not changed) |
| Evidence bundle | `Tegh-R158-Gate-Evidence.zip`: raw results, logs and every script of the run; verify with `scripts/verify-evidence.py` |

**What the owner reported, and what was done**

| Report | Cause | Fix | Checks |
|---|---|---|---|
| "I am unable to delete a company" | Before deleting, Tegh checks its list of company tables against the database. Fifteen tables added from R133 to R157 were missing from the list, so every deletion was refused with 503 "unsupported dependency". No gate test deleted a company. | <ul><li>The fifteen tables are listed, dependents first.</li><li>A company table that a later release adds is read from the database and deleted first, instead of blocking.</li><li>Client viewing codes and sessions are deleted through their link.</li><li>A table that refuses deletion of the row it points to is still reported.</li></ul> | 26-r158 DEL-01…05 (new). On R157 the same suite: DEL-03 got 503 (kept as `r158-on-r157-code.json`). |
| Backup: a failing ledger-total query was suppressed with `\|\| true` | — | Every check query must succeed. A database error stops the backup: no backup is kept and changes resume. The restore check treats database errors as failures. | beta-ops D1, E4 |
| Backup: a fixed five-second wait for running requests | — | Each request leaves a marker while it runs, and the backup waits until none is left. A left-over marker (older than 15 min) is ignored. A request still running at `TEGH_WAIT_MAX` stops the backup. The script makes sure the web server owns the marker folder. | beta-ops D2, E1, E2 |
| Backup: row counts and ledger totals cannot detect every update | — | A content fingerprint of every table (every value of every row), compared before and after the dump, and again after a restore. | beta-ops D3, E3 |

**26-r158, company deletion (5 checks).** No expected figure comes from Tegh's own delete plan. The test reads the database for every table with a company column.
- DEL-01: the company used here has rows in 29 tables.
- DEL-02: wrong name 409, wrong password 403, no backup confirmation 409, and Company Admin 403. All rows stay unchanged.
- DEL-03: the owner's deletion returns 200. No row is left in any company table, and no tax code component, vendor invoice line, note line, viewing code or session is left.
- DEL-04: one deletion log entry, with the counts from before the deletion. Another company is untouched.
- DEL-05: the same deletion in the browser, through Account & Access › Actions › Delete and Tegh's confirmation dialog. No page errors.

**beta-ops-test.sh on the R158 installation: 19 PASS / 0 FAIL.** Sections A–C are as in R156 and R157. The new sections each run the same case with the R157 scripts as a control. All 4 controls showed the R157 defect.

| Case | R158 | R157 control |
|---|---|---|
| D1 Ledger-total query fails (column missing) | exit 1, error shown, no backup kept, changes resumed | exit 0, "companies with postings 0" |
| D2 Tegh request of ~15 s that changes a customer's name from second 7 to 15 | waited 14 s; the backup holds the final name | stopped waiting at 5 s; "consistent" while 7 changes landed during the backup |
| D3 Another program edits a name every 0.1 s (counts and totals unchanged) | detected, `customers` named, backup removed | accepted |
| E1 / E2 Left-over marker; request that never ends | ignored with a note / stops at TEGH_WAIT_MAX, nothing kept | — |
| E3 Restore of a dump that differs in one value | `customers: content differs`, not verified | RESTORE VERIFIED |
| E4 Database error in the restore's ledger and balance checks | both failures | — |

The slow request in D2 is a test page that exists on the test host only while the script runs; it is not in the package.

**Quiet backup and restore on the R158 installation**
- The backup was consistent: 168 tables with 168 content fingerprints, 26 files, and the posted totals of 20 companies.
- The restore check found every table's content equal, value for value: `RESTORE VERIFIED`.
- The restore-test site was running the same R158 ZIP. It showed identical trial balances for all 34 companies (130 account rows) and 8 identical files.

**Gate run on the exact ZIP** (`a0add320…9b57`; host clock pinned to 2026-10-01 12:00 Toronto)

| Result | |
|---|---|
| **605 PASS / 0 FAIL / 7 INFO**, 0 BLOCKED | R158 5/5, R157 21/21, BETA 18/18, E2E 53/53, upgrade R118 → R158 13/13 |
| 45 path, header and redirect checks | all PASS |
| 20 layout runs | 0 screens with issues, 0 swipe traps |
| 34 public pages | light, no overflow, 17 internal links valid |

The 7 INFO rows are the same as in R156 and R157, and are not counted as passes.

**Not established here** (unchanged): operator details on the beta host; the host's HTTPS and private-file protection; external invitation and reset email; a backup and restore on the beta host (with the R158 scripts); legal review; an independent security review; the owner's accounting walkthrough; a real iPhone/Safari check.

**Verdict for R158:** replaces R157 as the candidate for the invitation-only, sample-data beta once the owner's hosting checks pass. **NOT READY** for real client or employee information.

## Addendum: R159 (onboarding for every company, archiving, deletion through Tegh support)

| | R159 |
|---|---|
| Package | `Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R159-PRIVATE-BETA.zip` |
| SHA-256 | `bfb085a9bc338a59cb4bd3b8f730185162c5d58f1fb969cc3871aa6ab5ca8e8b` |
| FILE-MANIFEST.sha256 | 370 entries, all OK |
| Cache token | `5990-r159-tegh` |
| Migration | Additive, on first use: the columns `companies.archived_at` and `companies.archived_by`, and the table `company_deletion_requests` |
| productionReady / acceptanceComplete | false / false (not changed) |
| Evidence bundle | `Tegh-R159-Gate-Evidence.zip`; verify with `scripts/verify-evidence.py` |

**What the owner asked for, and where it is checked (suite 27-r159, 15 checks)**

| Request | Done | Checks |
|---|---|---|
| Onboarding separate for each company; a new company goes through every step again | Every company added in the app starts its own onboarding, locked, with step 1 complete. Each company's state is kept and shown separately. **Cause found:** Settings › Add company chose "Default Chart", so a second company arrived with a chart and starter tax codes and its GL and tax steps looked done. | PC-01, PC-03 |
| Tax codes and GL set up specific to each company | A company created with onboarding starts with 0 accounts and 0 tax codes. The server enforces this whatever the request asks. The chart template and the tax-code file of the second company change only that company: the first company's 44 accounts and its ON-HST code are unchanged, and no tax code points to another company's accounts. | PC-02, PC-04 |
| Archive a company so it is no longer seen | Owner only. The company leaves the lists of every member and is refused when opened (403). Its records are unchanged, and no one can be invited to it. Restore brings it back as it was. A Company Admin gets 403, and someone who is not a member gets 404. | AR-01…04 |
| Permanent deletion through a request to Tegh support (platform owner) | Direct deletion by an owner is refused (409). The request checks the name, password, backup and reason. It archives the company and emails Tegh support. The inbox is for the platform owner only and shows record counts. Reject requires a note; approve requires the company name and the platform owner's password. The owner is emailed either way, can cancel a request, and restoring the company cancels it. An approved deletion leaves no row in any company table, and its log names the request. | DR-01…07 |

**Found and fixed during R159 testing**
- **The app's copy of the company list could go stale.** The startup gate kept `auth/me` for 60 seconds, renewed by every request. An archived company therefore kept showing in Account & Access, and in the switcher, until a reload. The copy is now dropped after any company change. Found by AR-04 in the browser.

**Changes to existing tests**
- 26-r158 DEL-05: the browser deletion now goes through the request and its approval on Platform Owner Home. DEL-01…04 (the platform owner deleting directly through the API) are unchanged.
- `run-all.sh` runs 27-r159 and clears the sign-in throttle before 24-beta…27-r159; these suites sign in many times.

**Gate run on the exact ZIP** (`bfb085a9…8e8b`; host clock pinned to 2026-10-01 12:00 Toronto)

| Result | |
|---|---|
| **620 PASS / 0 FAIL / 7 INFO**, 0 BLOCKED | R159 15/15, R158 5/5, R157 21/21, BETA 18/18, E2E 53/53, upgrade R118 → R159 13/13 |
| 45 path, header and redirect checks | all PASS |
| 20 layout runs | 0 screens with issues, 0 swipe traps |
| 34 public pages | light, no overflow, 17 internal links valid |

**Backup and restore on the R159 installation**
- The R158 scripts are unchanged: `beta-ops-test.sh` gave 19 PASS / 0 FAIL, and 4 of 4 R157 controls showed the R157 defect.
- The quiet backup and its restore: `RESTORE VERIFIED`, 169 tables (the new request table included) equal value for value, 26 files.
- The restore-test site ran the same ZIP and showed identical trial balances for all 34 companies.

**Known minor issue (not fixed in R159):** Platform Owner Home › Companies counts every company in its "N active" badge, archived ones included. The list itself is correct, and archived companies cannot be opened. To be fixed in the next release.

**Not established here** (unchanged): operator details (now including a support mailbox for deletion requests) on the beta host; the host's HTTPS and private-file protection; external email; a restore on the beta host; legal review; an independent security review; the owner's accounting walkthrough; a real iPhone/Safari check.

**Verdict for R159:** replaces R158 as the candidate for the invitation-only, sample-data beta once the owner's hosting checks pass. **NOT READY** for real client or employee information.

## Addendum: R160 (invitations that fit the person; restoring a deactivated or deleted login; Invitations & Access page)

| | R160 |
|---|---|
| Package | `Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R160-PRIVATE-BETA.zip` |
| SHA-256 | `878bb49817b5a5922acc6bf1a52746992a16b89ef432b5f51084cd1fa94a5f95` |
| FILE-MANIFEST.sha256 | 372 entries, all OK |
| Cache token | `5990-r160-tegh` |
| Migration | None. Privacy Notice version 2026-10-08 (terms unchanged, 2026-10-07) |
| productionReady / acceptanceComplete | false / false (not changed) |
| Evidence bundle | `Tegh-R160-Gate-Evidence.zip`; verify with `scripts/verify-evidence.py` |

**What the owner asked for, and where it is checked (suite 28-r160, 9 checks)**

| Request | Done | Checks |
|---|---|---|
| A more user-friendly invitations page | Platform Owner Home › **Invitations & Access** with three tabs: Invite someone (Email → What they get → Send), Sent invitations, Sign-up & email. The choices are two cards; the company list opens only when needed, scrolls inside itself, and works at phone width. | UI-01, UI-04 |
| A registered email: only access to more companies | The page says "Already on Tegh", offers no own-workspace choice, and marks the companies the person already has. The server refuses an own-workspace invitation (409 `invitation_user_exists`). The person accepts with their current password. | IN-02, UI-02 |
| A new email: own companies and/or existing companies | One invitation can give the person their own workspace and access to the inviter's companies. After accepting, they have that access and can create their own company. | IN-01, UI-01 |
| A deleted or deactivated email: restore the old login and send only a password email | Tegh recognises a deleted login's email by the SHA-256 fingerprint kept since R156 (it does not keep the address). Inviting is refused (409 `invitation_user_deleted` / `invitation_user_deactivated`). **Restore login** brings back the same login (same id) or reactivates it, ends older reset links and sessions, and emails "Your Tegh login has been restored": the email says the login was previously deleted (or deactivated) and gives a 72-hour link to set a new password. No invitation email is sent. Only the platform owner can look up or restore; a company admin is told to ask Tegh support. | IN-03, IN-04, IN-05, UI-03 |

**Changes to existing tests**
- 27-r159 INV-01: a deleted address invited again is now refused and pointed to Restore login, instead of receiving a second account (R160's rule).
- 24-beta BS-09: expects privacy version 2026-10-08.
- `run-all.sh` runs 28-r160 and clears the sign-in throttle before it.
- `beta-ops-test.sh` gained section F: the optional encrypted copy (`TEGH_ENCRYPT_TO`) is written, opens with the private key and equals the plain backup, and cannot be opened with another key.

**Also in R160:** the company directory counts archived companies separately and labels them (the R159 known issue); `api/.htaccess` states `RewriteBase /api/` (the 404 on `/api/health` on IONOS).

**Gate run on the exact ZIP** (`878bb498…5f95`; host clock pinned to 2026-10-01 12:00 Toronto)

| Result | |
|---|---|
| **632 PASS / 0 FAIL / 7 INFO**, 0 BLOCKED | R160 9/9, R159 18/18, R158 5/5, R157 21/21, BETA 18/18, E2E 53/53, upgrade R118 → R160 13/13 |
| 45 path, header and redirect checks | all PASS |
| 20 layout runs | 0 screens with issues, 0 swipe traps |
| 34 public pages | light, no overflow, 17 internal links valid |

**Backup and restore on the R160 installation**
- `beta-ops-test.sh`: 22 PASS / 0 FAIL (A–E as before, plus F1–F3 for the encrypted copy); 4 of 4 R157 controls showed the R157 defect.
- The quiet backup and its restore: `RESTORE VERIFIED`, 169 tables equal value for value, 26 files.
- The restore-test site ran the same ZIP and showed identical trial balances for all 34 companies.

**Limits:** logins deleted before R156 have no fingerprint and are treated as new addresses. Company admins' own invite form (Account & Access › Users) keeps its layout; the same server rules apply to it.

**Not established here** (unchanged): operator details on the beta host; the host's HTTPS and private-file protection; external email (including the restore email); a restore on the beta host; legal review; an independent security review; the owner's accounting walkthrough; a real iPhone/Safari check.

**Verdict for R160:** replaces R159 as the candidate for the invitation-only, sample-data beta once the owner's hosting checks pass. **NOT READY** for real client or employee information.

## Addendum: R161 (final functional test: large companies in every mode, layout, theme and screen size)

| | R161 |
|---|---|
| Package | `Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R161-PRIVATE-BETA.zip` |
| SHA-256 | `58f7eac6b4b8605f2357697ceab99b92879fb600b98cdb39547a707e9c9244dc` |
| FILE-MANIFEST.sha256 | 373 entries, all OK |
| Cache token | `5990-r161-tegh` |
| Migration | None |
| productionReady / acceptanceComplete | false / false (not changed) |
| Evidence bundle | `Tegh-R161-Gate-Evidence.zip`; verify with `scripts/verify-evidence.py` |

**What was tested.** Two synthetic companies were built through the app's own API (no direct database writes):
- **Volume Northwind Ltd:** 400 customers, 150 vendors, 5,000 invoices, 2,000 vendor invoices, about 4,900 payments, 400 journals and 3,000 imported bank lines (41,670 journal lines in the database with the other test companies).
- **Volume Lakeshore Inc:** 150 customers, 60 vendors, 1,500 invoices, 600 vendor invoices, about 1,500 payments, 150 journals and 1,000 bank lines.

The seeding script summed the expected figures from the amounts it generated (13% HST on whole-dollar prices, so tax is exact). None of Tegh's own calculations were used.

**Defects found and fixed in R161**

| # | Defect | Found by | Fix | Check |
|---|---|---|---|---|
| 1 | Two vendor invoices, invoices or expenses saved at the same moment in one company: one could fail with a server error (database deadlock on voucher numbering). 18 of 160 failed. | Seeding the large company with 8 parallel writers | The company's numbering lock is taken first, so saves queue | C-01: 160 of 160 succeed, with no duplicate or skipped number and every entry balanced |
| 2 | The customer invoice register took 44-79 s with 5,000 invoices (the app's own page timeout is 45 s); the vendor register 14-30 s; Collections 8-15 s | Screen sweep | 250 rows at a time with Show more / Show all; id index for row buttons; formatters built once; the layout layer skips held-back rows | Matrix below; B-03 |
| 3 | Match and Post opened on "Banking Workspace Unavailable — the workspace changed while this request was loading" | Browser check on the large company | A background agent check no longer cancels screens that are still loading | B-08 |
| 4 | Amounts in the bank review queue were covered by the drag scrollbar ("−$1,415.3") | Screenshot review | The list keeps the scrollbar's strip free | B-08 (no amount under the scrollbar) |
| 5 | With several companies selected, a new theme or layout reverted on reload: the save was refused as a write in the multi-company view | Mode matrix (the multi-company runs kept the previous layout) | Display preferences are saved in that view | Multi-company matrix runs record the layout and theme they chose |
| 6 | The Bank General Ledger (and a busy GL account ledger) took 151 s. Each opening left a request running, and the host backup then waited and gave up: 10 of 22 backup checks failed. | Backup tests on the large database | The entry number now comes from two grouped lookups; same output, 0.4-0.7 s | P-01 (22 report definitions, each under 10 s); M-01 (no request left running); backup 22/22 |
| 7 | The sidebar badge "3000" spilled out of its circle; counts without thousands separators; a cut-off search placeholder | Screenshot review | Badge grows; counts formatted; "Search" | Screenshots |

**Gaps found in the earlier tests (now covered)**
- **Guided mode was not really tested.** The Guided/Full Accounting choice is kept in the browser, so the gate's "guided" layout runs had run in Full Accounting (46 screens). Guided menu items also have no action id. The layout sweep now sets the mode inside each run, records the mode it actually used, and opens Guided menu items by name. The gate's two Guided runs now show Guided's 10 screens.
- **Parallel runs shared one preference.** The navigation layout is saved on the server for the user, so parallel runs could change each other's layout. The final matrix ran one combination at a time.

**Gate run on the exact ZIP** (`58f7eac6…`; host clock pinned to 2026-10-01 12:00 Toronto)

| Result | |
|---|---|
| **652 PASS / 0 FAIL / 7 INFO**, 0 BLOCKED | R161 20/20, R160 9/9, R159 18/18, R158 5/5, R157 21/21, BETA 18/18, E2E 53/53, upgrade R118 → R161 13/13 |
| 29-r161 | C-01, S-01, V-011…024 (Trial Balance, party balances = GL control accounts, P&L net income; all equal to the independent sums to the cent), P-01, B-01…08, M-01 |
| 45 path, header and redirect checks | all PASS |
| 20 layout runs | 0 screens with issues, 0 swipe traps |
| 34 public pages | light, no overflow, 17 internal links valid |

**Mode, layout, theme and screen-size matrix on the large companies (same installation)**

| Runs | 26, one at a time, on the gate installation of the exact ZIP |
|---|---|
| Combinations | Full Accounting × side navigation: light at 1920, 1440, 768, 390 and 320 px and 150% zoom; dark at 1366 and 390 px. Full Accounting × top navigation: light at 1440, 768 and 390 px; dark at 1440 px. Guided × side navigation: light at 1440, 768, 390 and 320 px; dark at 1440 and 390 px. Guided × top navigation: light at 1440 and 390 px; dark at 1366 px. System theme on a dark device: Full Accounting at 1440 px, Guided at 390 px. Both companies selected: Full Accounting, side navigation, light, 1440 px; Full Accounting, top navigation, dark, 390 px; Guided, side navigation, light, 1440 px |
| Screens opened | 800 (46 per Full Accounting run, 10 per Guided run). Each run recorded the mode, navigation and theme it actually used, and they match the combination |
| Layout and script issues | **0**: no page-wide sideways scrolling, nothing off-screen, no "undefined" or "NaN", no error toasts, no failed actions, no script errors |
| Load time | Every screen under 8 s except the 5,000-invoice register (Full Accounting customer invoice register and Guided Invoices), which opened in 8.2-9.0 s in 9 of its 26 openings (known limit). Before R161 the same register took 44-79 s |

**Backup and restore on the R161 installation with the large companies**
- `beta-ops-test.sh`: 22 PASS / 0 FAIL; 4 of 4 R157 controls showed the R157 defect. Before fix 6 it gave 12 PASS / 10 FAIL.
- The quiet backup and its restore: `RESTORE VERIFIED`, a 17 MB database, 169 tables equal value for value.
- The restore-test site ran the same ZIP and showed identical trial balances for all 37 companies.

**Known limits (not changed)**
- The 5,000-invoice register takes about 7-9 s to open (single company; up to 12 s seen with both large companies on the development host); the other screens take under 8 s. The app downloads the whole company workspace (15.7 MB uncompressed, about 1.7 MB compressed for this company).
- The all-time Day Book for both large companies (over 25,000 lines) shows a clear "narrow the filters" message, by design.

**Not established here** (unchanged): operator details on the beta host; the host's HTTPS and private-file protection; external email; a restore on the beta host; legal review; an independent security review; the owner's accounting walkthrough; a real iPhone/Safari check.

**Verdict for R161:** replaces R160 as the candidate for the invitation-only, sample-data beta once the owner's hosting checks pass. **NOT READY** for real client or employee information.

## Addendum: R162 (Guided mode tested end to end)

| | R162 |
|---|---|
| Package | `Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R162-PRIVATE-BETA.zip` |
| SHA-256 | `f8a09bb038195d41668f101ce8c801d4118cde384ede846e86703f9cf1d4297d` |
| FILE-MANIFEST.sha256 | 374 entries, all OK |
| Cache token | `5990-r162-tegh` |
| Migration | None |
| productionReady / acceptanceComplete | false / false (not changed) |
| Evidence bundle | `Tegh-R162-Gate-Evidence.zip` (`eeec7c0a…`); `scripts/verify-evidence.py` prints VERIFIED |

**What was tested.** The R161 test opened every Guided screen but did not book anything through them. The new suite **30-guided** creates a fresh Ontario company (HST 13%), imports eight September statement lines and books them through the Guided screens:
- single postings with HST and with no tax;
- a bulk posting of three lines;
- exclude, restore, then post to Owner Draws;
- void, restore and re-post;
- a posting at phone width;
- matching all eight lines;
- the month-end checklist;
- Home and Profit and Loss.

Every journal and the final General Ledger are compared with amounts worked out by hand (for example 113.00 = 100.00 + 13.00 HST). An independent senior-accountant review re-worked the expected journals, balances and Home figures (bank 183.60, HST owed 28.60, profit 195.00) and found them correct.

**Defects found and fixed in R162**

| # | Defect | Found by | Fix | Check |
|---|---|---|---|---|
| 1 | After a posting changed the Banking badge, clicking Banking in the side menu did nothing until reload (any module row the core app redrew) | 30-guided | Tegh keeps each row's handler and runs it if the core app replaced it | G-07 |
| 2 | A voided bank posting, restored to review, could never be posted again ("already recorded", then a server error) | 30-guided | The voided journal is kept as `bank_transaction_voided` (lines, void date, reason, audit) and the line is released; only a live journal blocks posting | G-11 |
| 3 | Restoring a voided bank line left it Excluded, and after a re-post could have counted it twice | Code review during the fix | Restore brings back only the line's own posting and marks it Posted; refused once the line went back to review, through both routes | G-11, G-11b |
| 4 | The Guided month-end checklist had no button | 30-guided | Guided Home › Month-End Checklist | G-16 |
| 5 | Opening a row's Actions menu ticked the row (bulk actions could include it) | 30-guided | Menus no longer change the selection | G-09 |
| 6 | Match and Post buttons and phone status pills broke mid-word | Screenshots | Message on its own line; pills on one line | G-12 |
| 7 | A Guided user signed in to the Full Accounting Dashboard about half the time | Second gate run (G-12) | Start-up waits until the user is known before choosing the home | G-01b (5 sign-ins), G-19 (Full Accounting still opens the Dashboard) |
| 8 | The month-end checklist ticked the bank reconciliation with an old reconciliation | Senior-accountant review | Ticked only when the reconciliation reaches the latest bank activity | G-16b |

**Test corrected.** 20-r151ui UI-15 opened two side-menu groups by clicking their rows. A row click closes the other groups, so the test now uses the arrow, which keeps them open. It passed in R161 only because the second row had stopped responding (defect 1).

**Review points not changed in R162** (proposed):
- a GST/HST return step and a "lock the month" step in the Guided checklist;
- a record of filed HST periods;
- an accruals prompt;
- naming 3100 "Shareholder loan" for corporations (s.15(2) note for the owner's accountant).

The review also said a void does not check for a closed period. That is not so: a void is refused when any of its entries is in a locked period, and posting checks the period too.

**Gate runs on R162**
- **First run (ZIP `d49764b8…`):** 670 PASS / 2 FAIL. The failures were UI-15 (the test above) and B-03 (the 5,000-invoice register in 16.1 s against its 15 s limit).
  - For B-03, the same host timed R161's front end at 10–14 s and R162's at 10–12 s. The limit was not changed.
- **Second run:** stopped after G-12 found defect 7, and after the review found defect 8.

**Final gate on the exact ZIP** (`f8a09bb0…`; host clock pinned to 2026-10-01 12:00 Toronto)

| Result | |
|---|---|
| **675 PASS / 0 FAIL / 7 INFO**, 0 BLOCKED | GUIDED 23/23, R161 20/20 (B-03 within its limit), R160 9/9, R159 18/18, R158 5/5, R157 21/21, BETA 18/18, E2E 53/53, upgrade R118 → R162 13/13 |
| 45 path, header and redirect checks | all PASS |
| 20 layout runs | 0 screens with issues, 0 swipe traps |
| Public pages | light, no overflow, 17 internal links valid |

**Backup and restore on the R162 installation** (with the large companies)
- `beta-ops-test.sh`: 22 PASS / 0 FAIL.
- Quiet backup and restore: `RESTORE VERIFIED` (17 MB, 169 tables equal value for value).
- The restore-test site ran the same ZIP and showed identical trial balances for all 38 companies.

**Not re-run for R162:** the R161 large-company layout matrix (26 runs). R162 changes no layout outside Match and Post, the side menu and the Guided Home shortcuts, which the 20 gate layout runs and 30-guided cover.

**Known limits (unchanged):** the 5,000-invoice register takes about 7–14 s to open depending on the host; the all-time Day Book over 25,000 lines asks to narrow the filters.

**Not established here** (unchanged):
- operator details on the beta host;
- the host's HTTPS and private-file protection;
- external email;
- a restore on the beta host;
- legal review;
- an independent security review;
- the owner's accounting walkthrough;
- a real iPhone/Safari check.

**Verdict for R162:** replaces R161 as the candidate for the invitation-only, sample-data beta once the owner's hosting checks pass. **NOT READY** for real client or employee information.

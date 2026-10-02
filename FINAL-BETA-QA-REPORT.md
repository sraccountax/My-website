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

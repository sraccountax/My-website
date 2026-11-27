# R193 audit with large data, both editions, both bookkeeping modes, light and dark themes, side and top navigation: results

Package: `Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R193-PRIVATE-BETA.zip`, release token `5990-r193-audit`. The SHA-256 is in section 8.

**Where this was run:** everything was run on the test server (gate.test) with synthetic companies, or with the synthetic R180 database snapshot. **Nothing was run on the IONOS server**, so nothing here shows how R193 behaves there.

The fixes are described in `tegh-app/R193-CHANGES.md`.

## 1. Data
| Company | Edition | Contents |
|---|---|---|
| Volume Northwind Ltd | Canada | 400 customers, 150 vendors, 5,000 invoices, 2,000 vendor invoices, 4,890 payments, 400 journals, 3,000 imported bank lines |
| Volume Deccan India | India | 400 customers, 100 vendors, 6,000 GST invoices across nine states (5, 12, 18 and 28%), 2,000 purchase invoices, 120 credit notes, 150 TDS deductions, 5,201 payments, 300 journals, six payroll runs for 40 employees, 3,000 imported bank lines |

Both were generated through the public API by `seed.mjs` and `seed-in.mjs`. Each script sums the expected figures independently from the amounts it generates.

**All companies:** the customer and vendor balance checks also cover every other company on the test server: the R180 snapshot companies and the companies created by the gate suites.

## 2. Faults found and fixed
**Both editions:**
- **Customer and vendor balances:** the AR/AP Trial Balance could disagree with the receivables and payables control accounts. Before the fix, 17 accrual-basis companies disagreed (10 Canadian, 7 India). It mishandled:
  - credit notes;
  - refunds;
  - amounts paid beyond an invoice;
  - bank matches that had since been unposted;
  - India only: TDS.

  The Receivable and Payable Ageing and the Customer and Vendor Balances reports had the same gaps for TDS and amounts beyond an invoice.
- **Large-company workspace:** it ran out of PHP memory (HTTP 500) because every invoice carried its own copy of the invoice template.
- **Reports over the 25,000-row limit:** they opened on an error and showed the partial preview's totals beside it (General Ledger Detail showed debits that differed from credits). They now offer the latest month, and the preview totals are hidden.
- **Dark theme:** about 20 components stayed white or had unreadable text. One more was found by the matrix on the installed package: the note in the budget editor.
- **Sidebar release label:** "Version 5.9.9" had 2.7:1 contrast on the dark sidebar, in both themes. Found by the matrix on the installed package.

**India edition:**
- **Guided mode:** choosing Guided said "Guided mode is now active" while nothing changed. It now says Guided isn't available for India companies yet.

## 3. Full gate (automated, fresh install of the ZIP)
**First R193 build** (SHA-256 `018ed577…`, before the last two stylesheet fixes):

| Result | Count |
|---|---|
| PASS | 1218 |
| INFO | 13 |
| FAIL | 6 |

**The six failures** are the known ones (R186 to R192): BS-07, BS-09, PW-01, WR-09, DI-SCAN and DD-01.

**Other checks:**
- E2E: 53/53;
- path and header checks: 45 PASS, 0 FAIL;
- FILE-MANIFEST: all 448 entries OK;
- `71-r193`: 7/7.

**Final build** (this package, SHA-256 `a3052a77…`): the same result.

| Result | Count |
|---|---|
| PASS | 1218 |
| INFO | 13 |
| FAIL | 6 |

**The six failures** are the same known ones.

**Other checks:**
- E2E: 53/53;
- `71-r193`: 7/7;
- the 20 UI runs: no screens with issues;
- path and header checks: 45 PASS, 0 FAIL;
- ZIP entries: all 0644;
- FILE-MANIFEST: all 448 entries OK.

**One gate step stopped early.** I had removed a stale Apache pid file before the launch, so `reset.sh` stopped at `service apache2 reload`. By then it had already:
- installed this ZIP;
- emptied storage;
- recreated the database.

It had not yet written the identity record or run the ZIP-permission and path/header checks. I ran those three steps on the same installation after the gate; their results are above. Every suite ran on the fresh install. The installed files match this package's FILE-MANIFEST and the repository.

## 4. Canadian comparison (R192 against R193, same R180 snapshot)
`r181-compare.py` compared 609 captured files: 76 differ, none are missing and no status changed.

**62 workspace responses:** each invoice template is now sent once.
- After restoring the templates as the browser does, 59 are identical.
- The other 3 differ only in the order of bills or journals that share a date. Their content is identical.

**4 screens (Balance Sheet and Bank Transaction Report of two companies):** in this capture the complete report is refused, which is a capture-clock artefact present in every capture since R180.
- **R192:** showed the preview totals under "Complete report unavailable".
- **R193:** hides them (intended, section 2).

**10 screens of one company:** that company is not in the snapshot, so it shows the same error page in both releases. Only the support reference differs.

**Not exercised by the snapshot:** the party balance changes are not exercised by the snapshot's companies. They are checked in section 5.

## 5. Large-data checks on the installed package (first R193 build)
**Canada (Volume Northwind, `check-vol.mjs`): all PASS.**
- **Trial Balance:** equals the expected figures in all 14 accounts.
- **Party balances:** customer and vendor balances equal the GL control accounts.
- **Profit and Loss:** net income equals the expected figure.
- **All 22 report definitions:** each answers in full.
- **Speed:** each of these reports answers in under 10 seconds.

**India (Volume Deccan, `check-in.mjs`): VIN-01 to VIN-07 all PASS.**
- **Trial Balance:** equals the expected figures in 22 accounts.
- **Party balances:** customer and vendor balances equal the control accounts.
- **Profit and Loss:** net profit equals the expected figure.
- **GSTR-3B 3.1(a):** CGST, SGST and IGST equal the expected figures.
- **GST ledger:** agrees with the GL every month.
- **Payroll registers:** they add up.
- **Speed:** every report answers in under 10 seconds.

**All companies (`party-tieout.mjs`):** 69 companies.
- **Cash basis (2):** not compared; they post no receivables or payables by design.
- **One R142 legacy test company:** its balances agree (both zero), but it has no control-account mapping, so its ageing reports correctly say they cannot be reconciled.
- **Every other company:** party balances and all four reports equal the GL.

**Not reseeded:** Volume Lakeshore (the smaller Canadian volume company) was not reseeded this time. Its expected figures belonged to an earlier copy that the gate had removed. The party check above does cover the snapshot's Lakeshore.

## 6. Screen matrix on the installed package (24 runs)
**Runs:** `audit-sweep.mjs` on Volume Northwind and Volume Deccan:
- Canada in Full Accounting and Guided, India in Full Accounting;
- light and dark themes;
- side and top navigation;
- 1440 px and 390 px.

**What each run opens:** every menu screen, the first New/Add/Create form on it, the settings and tool pages and every report:
- Canada Full: 115 screens;
- Canada Guided: 72 screens;
- India: 118 screens.

**Result:** all 24 runs completed. There were no browser errors, page overflow, cut-off controls, error toasts or "undefined"/NaN text. Evidence is in `matrix-installed/`.

**Every remaining finding was checked by hand:**

| Finding | Explanation |
|---|---|
| HTTP 413 (General Ledger Detail; Day Book requests seen on the next screens) | The complete-row refusal for the large company, which now offers the latest month. The Day Book's "All time" request answers after the sweep has moved on. |
| India wording on Canadian Users ("TDS") | The names of synthetic users created by the `71-r193` suite. |
| "CGST, SGST" on the Canadian Add Tax Code form and Tax Codes page | The form's text, from R137, gives CGST and SGST as an example of foreign taxes kept separate. The Tax Codes page itself has no such text when opened directly; the sweep's hit came from the form opened just before it. |
| "Canada" on the India customer and vendor forms | Canada in the country list. |
| "Canadian"/"GIFI" on India Tax Codes, Payroll Updates and GIFI pages | These pages say "… Is For Canadian Companies" (R192). |
| "Canada" on India Site & Release Settings | The platform owner's page, which describes both editions. |
| White invoice template previews in the dark theme | By design: they show the printed page. |
| "9 unlabelled" on Fixed Assets (once in 24 runs) | Not reproduced when opened directly or by the sweep alone; carried over from the previous screen. |
| Budget note at 2.8:1 (dark, runs before 10:40) | Fixed (section 2). The dark runs after the fix reached the test server are clean. A re-check passed in both themes at 390 px. |
| "Version 5.9.9" at 2.7:1 (light, 390 px, side navigation) | Fixed (section 2). A re-check passed in both themes at 390 px. |

**Navigation:** side and top navigation gave the same findings.

## 7. What was not done
- **IONOS:** nothing was run on the IONOS server.
- **Volume Lakeshore:** not reseeded (section 5).
- **India Guided mode:** not built. It is shown as not available (section 2).
- **Matrix and last fixes:** the matrix ran on the first R193 build. The last two stylesheet fixes were checked by the targeted re-checks and the final gate, not by a second 24-run matrix. Between the two builds, only those two stylesheets, the change notes and the manifests differ.

## 8. Package
`Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R193-PRIVATE-BETA.zip`: SHA-256 `a3052a778812a32dce6bb6c78ac4bc3ca46cfe9b43df53c0c39ab4b4ebdc4e4c`, 449 files.

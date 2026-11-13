# R182 results

**Package:** `Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R182-PRIVATE-BETA.zip`
SHA-256 `db27fb091bf466ad21cb3f5fa098b4823e5a8e362d305d38cec6ea6faa8c4794`, 430 files (429 in FILE-MANIFEST.sha256, all verified on install).

Where the checks ran: the isolated gate host (`gate.test`) with synthetic data only. **Nothing was run against IONOS,
books-test.sraccountax.ca or production.** These results do not establish how R182 behaves on the IONOS deployment.

## 1. Canadian before/after comparison (switches off)

Method as in R181:
- the R180 data snapshot (62 companies);
- 609 captured reports and screens;
- compared with the R180 capture.

| Result | Detail |
|---|---|
| Reports | All identical |
| Screens | Identical except **Bank Transaction Report** on the companies that show it. The difference is the new **+ Add transaction** button (R182 D), which is intended |
| First run | 34 screens of one company (04733e…) were not captured. A capture of that company alone gave 17 screens: 16 identical, and Bank Transaction Report differing only by the same button. These were capture failures, not differences |

No `invoice_revisions` table was created with the switch off.

Files: `comparison/run-compare-r182.log`, `comparison/r182-off-report.json`, `tests/run-compare-r182.sh`.

## 2. Full gate on this exact ZIP

Fresh install from the ZIP, every suite once, then the tail.

| Part | Result |
|---|---|
| All suites (51 result files) | **1040 PASS**, 13 INFO, 6 FAIL. The six are the same known failures as R177–R181; R181 had 1017 PASS, and the 23 new passes are suite 56-r182 |
| R182 suite `56-r182` | **23/23 PASS** |
| R181 `55-r181`, R180 `54-r180`, R179 `53-r179` | 11/11, 3/3, 13/13 PASS |
| Upgrade from R118 | 13/13 PASS |
| Screen matrix | 20 runs, 0 screens with issues |
| Phone swipe-trap scan (390 × 844) | 0 traps |
| Paths and headers | 45 PASS, 0 FAIL |

Known failures, unchanged: BS-07 and BS-09 (policy pages not published on the reset host), PW-01 and WR-09 (public-page
wording checks, failing since R175), DI-SCAN (environment check of the old R144 reader), DD-01 (top-bar click timing
on this host).

Logs: `logs/run-all.log`, `logs/tail-run.log`, `logs/r182.json`.

## 3. What 56-r182 checks

Expected amounts are worked out in the test (price × quantity, 13% HST), not read from Tegh. The suite creates its own
Ontario companies and puts the switch back at the end.

| Check | What is verified |
|---|---|
| IE-00 | With the switch off (default), a correction is refused (403) and the invoice screen offers nothing new |
| IE-01 | Unpaid 10 × 100 + HST corrected to 8 × 100. Same number. Original entry reversed on 10 Sep (`invoice_correction_reversal`). New total 904.00. AR moves by −226.00 and HST by −26.00. History row and audit entry written |
| IE-02 | Reason required. The same request twice saves once. A stale revision is refused |
| IE-03 | Date moved from 10 Sep to 20 Aug: August revenue −500.00 and HST −65.00 reversed out, September +500.00 / +65.00 |
| IE-04 | Paid invoice corrected higher: re-opens with exactly the difference open |
| IE-05 | Paid 1,130.00 corrected to 904.00: invoice paid at 904.00; the payment shows 226.00 unapplied; the payment amount is unchanged |
| IE-06 | That credit applied to another invoice (paid), and the rest refunded |
| IE-07 | Partly paid, corrected lower then below the paid part: balance follows; credit 48.00 |
| IE-08 | Refused: locked original period, locked new date, linked credit note, customer change on a paid invoice, date after its payment. The preview explains why and what to do |
| IE-09 | Cash basis: unpaid corrected with no journal entries; paid refused |
| IE-10 | History newest first with who, when, reason, lines and totals |
| IE-11 | Viewer and editor refused (with the reason shown); another company cannot reach the invoice |
| IE-12 | Every entry balances; AR control equals open invoices less unapplied receipts plus refunds |
| IE-13 | Browser: Correct invoice, editor in correction mode, review (before/after, two entry tables), reason required, buttons on top, Corrected tag, History; no page errors |
| BK-01 | Bank line by hand repeated: 31 Jul, 31 Aug, 30 Sep. Repeat request adds nothing. Future months and zero refused |
| BK-02 | A statement line within 5 days of a hand-entered one is flagged and left out. An edited description is imported as edited |
| BK-03 | Description edit in Match and Post; amount and date unchanged |
| BK-04 | Browser: Add transaction, ✎, Entered by hand tag, the window's buttons usable, page behind locked, report button |
| BK-05 | Browser: import review is the only scroll area; description edited in place and saved |
| MD-01 | Browser: switching to Guided opens Guided home with the review; back to Full Accounting opens the dashboard with the review |
| CO-01 | Browser at 1440 and 390: 7+ sections, all field names kept, no horizontal scroll, no tutorial over the form, BC → Vancouver, a chosen zone is kept |
| CO-02 | Browser: a company created from the new page stores MB, America/Winnipeg, cash, tax registered, Winnipeg, CAD |
| BR-01 | Backup and restore of the company with 8 corrections: invoices, entry count, General Ledger and history identical; restored books balance |

## 4. Bug found and fixed during testing

**Restore failed for linked payment-application rows** (`party_payment_applications.reversal_of_id`). Rows were
inserted in export order, so a reversal row that shared its original's date and second could come first and fail the
foreign key. This affects any unapply done in the same second as its application, not only R182. Restore now inserts
a self-referencing table's rows after the rows they point to. Found by BR-01, which now passes.

## 5. Not covered

- The IONOS deployment: not deployed and not tested there.
- Vendor invoice (bill) corrections: not part of R182.
- A warning when a correction changes sales tax for a period already remitted: Tegh has no record of remittances.
  Locked periods are refused.

# R187 nine-item batch and "+ Add new" in drop-downs: results

Package: `Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R187-PRIVATE-BETA.zip`, release token `5990-r187-batch` (SHA-256 in section 6).

Everything here was run on the test server (gate.test) with synthetic companies. **Nothing was run on the IONOS server.**

## 1. What changed in this package

1. **General Ledger line memos** of customer invoices, vendor invoices, credit/debit notes, invoice corrections and customer/vendor payments now name the document number and the party (for example "Customer Northwind · Invoice INV-1042"). Lines already in the books are not rewritten.
2. **One "Journal Entry" menu** replaces Journal Entries and Expense Vouchers. Its tabs are Journal entry, Expense voucher and Interbank transfer.
3. **Reconcile Account** uses the live General Ledger balance, so a journal entry posted to the bank account shows at once. A new section, "Entries in the books that match bank lines", lists a book entry that has the same amount as an unposted bank line within 7 days. **Match** or **Match all** links them without posting anything new.
4. **Bank Reconciliation Report** has one **Download ▾** menu with the formats, instead of separate buttons.
5. When the **Bank Reconciliation Report has nothing to show**, a note says to complete a reconciliation in Reconcile Account, with a button that opens it.
6. **Cancelling an edit** of a customer or vendor invoice stays on that invoice instead of returning to the register.
7. **Day Book** has a Download menu with PDF, Excel, CSV and Print.
8. **Interbank transfers** can be made in two places, and the Bank Transfers menu is removed:
   - **Match and Post › Post as › Interbank transfer:** choose the other bank account or the 1070 Interbank Transfer Clearing account. Tegh finds the other side if it is already waiting in the other bank.
   - **Journal Entry › Interbank transfer:** one debit and one credit of equal amount, both on bank or credit-card accounts in the base currency. The server enforces this.
9. **GIFI Report** moved to Reports › Tax Reports.
10. **"+ Add new"** appears in every customer, vendor and General Ledger account drop-down. It opens a quick form, creates the record and selects it. Viewers do not see it.

## 2. Full gate (automated, fresh install from the R187 ZIP)

| Result | Count |
|---|---|
| PASS | 1115 |
| INFO | 13 |
| FAIL | 8 |

- **Known failures (6), the same as R186:** BS-07, BS-09, PW-01, WR-09, DI-SCAN and DD-01.
- **Two further failures, investigated:**
  - **IN-09 (R152, invitation-link message): timing.** The suite `21-r152` was run again on the same R187 installation and all 31 of its checks passed (`logs/rerun-21-r152.log`).
  - **DD-04 (R166, a menu painted in one place): a real R187 defect, cosmetic.**
    - R187 shows the professional **Download ▾** menu on every register page. On the Customers page it is now the first menu.
    - The button's press animation moves it by half a pixel while the menu opens, so the menu panel can move 1 px after it first appears.
    - It reproduced on a second run.
    - **Fix:** the Download buttons no longer animate when pressed. It is a one-rule CSS change, made after this package was built, so the fix is **not in the R187 ZIP**. It goes into the next package.
    - With the fix on the test server, `41-r166` passes DD-04 (`logs/rerun-41-r166-after-fix.log`); DD-01 is still the known failure.
- **Increase in PASS:** R186 had 1094 PASS. The new suite `61-r187` adds 23; the two failures above take away 2.

Other checks:
- Path and header checks: 45 PASS, 0 FAIL.
- UI runs: 20, with no screens showing issues.
- Swipe traps at 390×844: 0.

The gate ran on exactly the delivered ZIP (FILE-MANIFEST: all 435 entries OK).

Logs: `logs/gate-r187b.out`, `logs/run-all.log`, `logs/tail-run.log`, `logs/r187.json`.

## 3. Suite 61-r187 (automated, inside the gate): 23 of 23 PASS

| ID | Check | Result |
|---|---|---|
| GL-01 | Customer invoice: every General Ledger line of its entry names the invoice number and the customer | PASS |
| GL-02 | Vendor invoice: every General Ledger line of its entry names the vendor invoice number and the vendor | PASS |
| GL-03 | Customer credit note: every General Ledger line names the credit note number and the customer | PASS |
| GL-04 | Entries already in the books keep their stored line memos (nothing is rewritten); a manual journal's own line memos are unchanged | PASS |
| JE-01 | Journal Entry › Interbank transfer (server): one entry, debit the receiving bank and credit the paying bank; refused for a non-bank account, the same account twice or unequal lines | PASS |
| JE-02 | The statement line in each bank links to that transfer journal entry (Match and Post › Interbank transfer finds it); nothing new is posted | PASS |
| RC-01 | A manual journal entry posted to the bank GL and an unposted bank line of the same amount are found by Reconcile Account; matching them posts nothing and clears both from the reconciling items | PASS |
| RC-02 | The book balance on Reconcile Account is the live General Ledger balance: a journal entry posted to the bank GL changes it at once | PASS |
| MP-01 | Interbank transfer of two unposted lines (chequing out, savings in): one entry covers both; each bank moves by the amount; trial balance balances | PASS |
| MP-02 | Interbank transfer to the clearing account: the line posts to 1070 Interbank Transfer Clearing with no tax | PASS |
| UI-01 | Menus: General ledger has one "Journal Entry" (no separate Journal Entries or Expense Vouchers); Banking has no Bank Transfers; GIFI Report is under Reports › Tax Reports and not under General ledger | PASS |
| UI-02 | Journal Entry › Interbank transfer (screen): choosing From, To and the amount shows the entry; Post transfer records one entry moving the money | PASS |
| UI-03 | Match and Post › Post as Interbank transfer: the other side waiting in the savings account is found, and linking posts one entry for both lines | PASS |
| UI-04 | Reconcile Account lists a book entry that matches a bank line and matches it with one click | PASS |
| UI-05 | Bank Reconciliation Report with no completed reconciliation says to complete one in Reconcile Account, and its button opens Reconcile Account | PASS |
| UI-06 | Cancelling an edit of a customer invoice or a vendor invoice stays on that document (not the register) | PASS |
| UI-07 | Day Book has one Download menu; its PDF and Excel choices download a real PDF and a real Excel workbook | PASS |
| UI-08 | Bank Reconciliation Report has one Download menu with the formats (no separate export buttons), and its Excel choice downloads a workbook | PASS |
| UI-09 | "+ Add new vendor…" in the vendor drop-down of a new vendor invoice creates the vendor and selects it | PASS |
| UI-10 | "+ Add new customer…" in the customer drop-down of a new customer invoice creates the customer and selects it | PASS |
| UI-11 | "+ Add new GL account…" in Match and Post's account drop-down: the form suggests a free code, creates the account and selects it; Cancel leaves the drop-down as it was | PASS |
| UI-13 | Journal Entry: each line's account drop-down offers an add-account option (its own "+ Create New Account…" or "+ Add new GL account…") | PASS |
| UI-12 | A Viewer sees no "+ Add new" options | PASS |

**Earlier suites updated for the renamed menus.** These are changes to the tests' expectations, not to how the code behaves:
- `e2e/w2.mjs` opens "Journal Entry".
- `47-r172.mjs` looks for "reports:gifi-report" and accepts "Download" as well as "Export".
- `vol/vol-ui.mjs` B-08 waits for Match and Post instead of the retired Review page.

Copies of the new and updated tests are in `tests/`.

Screenshots (synthetic company): `shots/r187-*.png`.

## 4. Canadian before/after comparison (automated, test server)

**Method**
- Restored the R180 database snapshot (68 companies).
- Captured the reports (API) and screens with the R187 code.
- Compared them with the R186 capture (`comparison/r187-vs-r186.json`) and the R180 capture (`comparison/r187-off-report.json`).
- The test server also had the DD-04 CSS rule (section 2). It changes only how a button animates when pressed, not any captured text.

**Reports (API): 0 differences.** All 558 API captures, covering P&L, balance sheet, trial balance, ageing, control balances, workspace and dashboard summaries for every company, are identical to R186 and to R180. R187 changes no figures in existing books.

**Screens: 28 differ from R186, 0 status changes. Each difference is one of R187's intended changes:**

| Files | Difference |
|---|---|
| 14 | The export button is now labelled "Download" instead of "Export" |
| 4 | The account drop-down offers "+ Add new GL account…" |
| 2 | The register's export buttons ("Export Current Visible Columns", "Export All Report Columns") are one "Download ▾" menu |
| 4 | "Export" → "Download", and "+ Add new customer…" or "+ Add new vendor…" in the party drop-down |
| 4 | General ledger › GIFI Report and General ledger › Journal Entries: these menu items moved, so the capture script opened the dashboard instead. See below |

**The two moved screens** were captured again at their new places (`tests/r187-moved-capture.mjs`, files in `comparison/moved-screens/`):
- **GIFI Report** (Reports › Tax Reports), both companies: every mapping row and amount is the same as R186. The only addition is the Download ▾ button.
- **Journal Entry**, both companies: the same form and accounts. The title changed from "GL Journal Entry" to "Journal Entry", and the tabs are now Journal, Expense Voucher, Interbank Transfer, Import and Recurring.

**Company the full capture skipped:** 6cc4e6… was captured on its own (`comparison/pick-6cc4e6/`). The differences from R186 are:
- the random "Support reference" line on pages that say "Choose a company first" (as in R186);
- "Export" → "Download";
- the two moved menu items.

Company d103f5… was captured by this run but has no earlier capture to compare with.

## 5. Not covered

- **IONOS:** no deployment or tests there.
- **Interbank transfers across currencies:** both accounts must be in the base currency. A transfer between currencies is refused, not converted.
- **Suggested matches** are by equal amount within 7 days only. A bank line that pays several book entries is not suggested.

## 6. Package

- SHA-256 `8069d2c7f58842ea777a949528eabd6d03398e11d7e5f5c4e0d5d82c5a530c9b`; 436 files; FILE-MANIFEST: all 435 entries OK.
- The full gate in section 2 ran on this exact file.
- The DD-04 CSS fix is not in this file (see section 2).

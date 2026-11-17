# R139 changes over R138: beta-gate fixes

5.9.9 / Build 5990 / Schema 46. Cache token `5990-r139-tegh` (client view page unchanged: `5990-r135`). No manual migration.
Status: **staging candidate**, `productionReady: false`. The host acceptance items are still open.

R139 contains only fixes for defects confirmed by the R138 invite-only beta release gate (see FINAL-BETA-QA-REPORT.md). There are no new features.

| Defect | Severity | Fix |
|---|---|---|
| DEF-01 | High (privacy) | The signed-in app page (`app.html`) loaded Microsoft Clarity session recording. It could capture accounting, payroll and customer data on screen, which contradicts the Privacy Notice. The snippet is removed from `app.html`. The marketing pages still load it; owner decision, see the report. |
| DEF-04 | High (accounting control) | A manual journal could post straight to Accounts Receivable 1200 or Accounts Payable 2050. The control account then no longer equalled the customer/vendor documents and the ageing reports (gate test: AR $3,317.76 vs subledger $3,217.76). Manual journals, Ask Tegh journals and recurring journals now refuse 1200 and 2050 (`409 journal_subledger_control_protected`). The manual-journal account list no longer offers them. Journal Import already refused all control accounts. |
| DEF-05 | High (sales tax) | A company **not registered for GST/HST** still charged GST and PST automatically in tax-code mode: the starter province code was applied to every taxable invoice line (gate test: $100 BC sale billed $112). Now, for a company not registered for GST/HST: invoices, drafts and recurring invoices do not apply a province code automatically, and the line shows "Not registered for GST/HST — no tax". Tax paid on purchases (vendor invoices, expenses, bank withdrawals) is added to the cost instead of being claimed as input tax credits. A code the user picks explicitly on a line is still honoured. |
| DEF-06 | Medium | The dashboard "GST/HST you owe" card read only 2100/1100, 2110/1110 and 2115/1115. Tax mapped to custom accounts (for example 2120/1120) was silently left out, so the card showed $0.00 while HST was owed. The card now lists "other tax accounts $X (see Tax Summary)", and its subtitle says the GST/HST figure is 2100/1100 only. |
| DEF-02 | Medium | A successfully sent invitation or email test was recorded with the message "The mail server rejected the message…". Accepted mail now says "The mail server accepted the message for delivery." |
| DEF-07 | Low | Restoring a damaged `.tegh` file returned a 500 server error instead of the integrity-check message. It now returns 422 `backup_integrity_invalid`. Nothing was written in either case. |
| DEF-03 | Low (hardening) | `config.example.php` at the web root was executable over HTTP (empty 200). The root `.htaccess` now denies `config*.php`. |

## Files
- `api/tax_codes_r137.php`: `tax_code_for_purchases()` and `tax_code_auto_region()`
- `api/accounting.php`: invoice default code; expense and bank-withdrawal purchase tax
- `api/advanced.php`: recurring invoice default code; recurring journal control-account check
- `api/books.php`: `journal_assert_no_subledger_control()`; vendor invoice purchase tax
- `api/workspace_summary_v5610.php`: `taxSummary.otherTaxNetCents` and `otherTaxAccountCount`
- `api/portal.php`: accepted-mail message
- `api/backup.php`: damaged-archive handling
- `assets/tegh-portal-v5990.js`: invoice default, journal account list, dashboard card
- `app.html`: Clarity removed; cache token
- `assets/tegh-gate-v5990.js`, `assets/tegh-preflight-v5990.js`: cache token
- `.htaccess`
- Docs: README.txt, DEPLOYMENT-NOTES.txt, R139-CHANGES.md, FINAL-BETA-QA-REPORT.md
- Release manifests and FILE-MANIFEST.sha256

## Behaviour notes for existing data
- **Journals:** journals already posted to 1200/2050 are not changed. If the dashboard shows a receivable/payable difference, reverse the journal and use a credit/debit note or opening balance.
- **Documents:** documents already saved keep their tax amounts. For an unregistered company that already charged GST/PST, issue credit notes or edit drafts.
- **Registration setting:** the change follows Company Details → "Registered for GST/HST". Registering later turns automatic tax back on, with no other step.

## Verified
See FINAL-BETA-QA-REPORT.md: the full gate suite was re-run on the exact R139 ZIP on an Apache 2.4 / PHP 8.3 HTTPS test host with a fresh database.

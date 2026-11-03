# R143 changes over R142: fixes from a click-through test of daily workflows

5.9.9 / Build 5990 / Schema 46. Cache token `5990-r143-tegh` (client view page unchanged: `5990-r135`). No migration.
Status: **staging candidate**, `productionReady: false`. The host acceptance items are still open.

Before new features were started, every daily workflow was driven through the screens on a fresh synthetic company, the way a user would:
- customer, invoice, credit note, customer payment;
- vendor, vendor invoice, vendor payment;
- GL journal;
- statement upload, Match and Post, and linking recorded payments;
- reconciliation, reports, dashboard;
- payroll setup, employee, pay run, verification, posting;
- Tegh Assist;
- invoice PDF and report export.

Every result was checked against the database, and expected figures (tax, payroll deductions, report totals) were worked out by hand. Four defects were found and fixed, plus one wording issue.

## Fixes
- **DEF-10: a statement left in preview blocked re-uploading the same file.**
  - **What happened:** if the page was refreshed or closed, or the session expired, while a statement preview was open, uploading that file again said "Cancel that preview before uploading it again". Nothing on screen listed the preview.
  - **Fix:** a new upload of the same file for the same account now replaces the stale draft. Drafts create no bank transactions or GL entries, so nothing is lost. The replacement is recorded in the audit trail (`statement.preview_replaced`). (`api/operations.php`)
- **DEF-11: no way to link a bank line to a payment already recorded by hand.**
  - **What happened:** if a customer receipt or vendor payment was recorded with Record Payment and the bank statement was imported afterwards, Match and Post had no way to link the two. The user was left choosing between excluding the line and posting it again (double counting).
  - **Fix:** when one bank line is selected, Match and Post now shows **"Already recorded?"** with the matching payments: same direction, exact amount and currency, paid on or before the bank date, not yet linked. **Link to this payment** marks the bank line as posted against the payment's existing journal entry; nothing new is posted. The server side already existed; only the screen was missing. (`assets/tegh-portal-v5990.js`, `assets/tegh-r120.css`)
- **DEF-12: Tegh Assist missed common questions.**
  - **What happened:** "how much money is in the bank", "cash in bank", "what is in my chequing account", "how much money is left in the bank" and "how much money do I owe" were not understood. "who do I owe money to" was confused with "who owes me money".
  - **Fix:** they now open Bank Accounts, which shows the balances, or Payables ageing. All 144 earlier Assist test questions still resolve correctly. (`api/assist_language_r123.php`)
- **DEF-13: invoice screen line total.** The invoice view showed 2 × $500.00 = "Amount $1,130.00" because the line total includes tax. The column is now labelled **Total incl. tax**, as on the emailed invoice. (`assets/tegh-reference-r19.js`)
- **Reconciliation wording:** a balanced period that ends in the future no longer says "Ready to complete" and then refuses. It says "Balanced. You can complete it once the period has ended."

## Observations (not changed)
- Report exports (Excel, CSV, PDF, Print) are under the table's **Actions ⋮ → Export…** menu. Register pages show visible Export buttons.
- GL Journal Entry does not offer bank, receivable, payable or tax control accounts. Those are posted through Banking, invoices and bills, and tax remittances. This is by design.
- On the Balance Sheet, GST/HST Recoverable is shown under "Other Assets" rather than current assets.
- The employee form's province fields are free text ("ON") rather than the province list used elsewhere.

## Files
- **API:** `api/operations.php`, `api/assist_language_r123.php`.
- **Assets:** `assets/tegh-portal-v5990.js`, `assets/tegh-r120.css`, `assets/tegh-reference-r19.js`, `assets/tegh-gate-v5990.js` and `assets/tegh-preflight-v5990.js` (cache token).
- **Pages:** `app.html` (cache token).
- **Docs:** README.txt, DEPLOYMENT-NOTES.txt, R143-CHANGES.md.
- **Manifests:** FILE-MANIFEST.sha256 and the release manifests.

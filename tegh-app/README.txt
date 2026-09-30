TEGH 5.9.9 / BUILD 5990 / SCHEMA 46 — R135 IONOS STAGING CANDIDATE

A complete flat-root web application package. Read DEPLOYMENT-NOTES.txt before
uploading. Upload the ZIP contents at the web root, not the ZIP's containing
folder. Preserve private config and runtime data. This is not an accepted
production release.

Release notes included in this package:
R118: server-side account guard, metadata denied by .htaccess. See R118-CHANGES.md.
R119: invoice/note tabs, one Invoice & Note Register per side, invoice tax-split repair, scrolling report tables, mobile layout and export fixes. See R119-CHANGES.md.
R120: credit/debit notes as itemized documents (returns), render-loop fix for dropdowns/scrolling, layout polish. Auto-creates table accounting_note_lines. See R120-CHANGES.md.
R121: end-to-end functional test fixes: bulk bank posting, contra and exclude actions, match exact pairs, GST/HST remittance from the bank feed, Add Employee save, premium PDF/Excel totals. See R121-CHANGES.md.
R122: one-line menus and new brand block, actions for selected report rows, bank reconciliation calculated from the books with reconciling items, GST/HST remittance with input tax credits, payroll remittance prefill, credit/debit note PDFs, flicker fixes, SR Books name removed, 104 unused files removed. No migration. See R122-CHANGES.md.
R123: Tegh Assist audit: requests take about 0.2 s instead of about 15 s, understands everyday language, dates and typos, answers common small-business questions, acts at once on confident requests, and is available on phones. No migration. See R123-CHANGES.md.
R124: simpler Tegh Assist: plain one-line answers first, "Did you mean…" in everyday words, friendly forms, grouped "everything I can help with" list, recent questions. No migration. See R124-CHANGES.md.
R125: friendlier dashboard: one-sentence summary with an Ask box, cards in plain words, chart explained in a sentence, everyday shortcut names, To Do list with only what needs doing. No migration. See R125-CHANGES.md.
R126: dark mode readable everywhere (110 problems fixed, including top-navigation menus), top navigation uses the short menu names, guided home counts only issued invoices and bills. No migration. See R126-CHANGES.md.
R127: Match and Post shows the bank balance from imported statement lines (opening, money in, money out, bank balance, book balance, difference with Reconcile). No migration. See R127-CHANGES.md.
R128: Match and Post shows one bank balance (red when negative); customers and vendors get a Post opening balance button after creation; edit screens titled Edit Customer/Vendor. No migration. See R128-CHANGES.md.
R129: the Transactions Report can edit a bank line that is not posted and not matched (date, description, reference, amount, remarks; recorded in the audit trail); Match and Post phone header fixed. No migration. See R129-CHANGES.md.
R130: Payroll renamed Payroll Support; SINs removed everywhere and refused (Employee ID such as EMP-0001 instead); persistent Payroll Support notice; required review checkbox before finalizing a pay run; neutral calculation/support wording. No manual migration (stored SINs are cleared automatically). See R130-CHANGES.md.
R131: phone sidebar lists modules only (no submenus); tapping a module opens its Activity/Reports page and closes the drawer; drawer header and dark mode fixed. No migration. See R131-CHANGES.md.
R132: dashboard shows a GST/HST (tax summary) card: amount owing or refund due, collected vs paid, opens the GST/HST Summary. No migration. See R132-CHANGES.md.
R133: Client Viewing Links: accountants share a view-only dashboard and chosen reports with a client; the client signs in with a 6-digit emailed code and can download PDF/Excel. Also fixes outbound email when reply_to is empty. Additive tables created on first use. See R133-CHANGES.md.
R134: the client view-only dashboard has animated charts that follow the reports shared (money in/out, cash, ageing, invoices, bills, spending, balance sheet), each with tooltip, legend and table view. No migration. See R134-CHANGES.md.
R135: customer invoices charge and post PST (QST/RST) separately from GST/HST (2110 vs 2100), same as bills; sales tax setup explained in plain language when a company is registered; "Inventory Report" renamed Product Activity (not stock or inventory value); release manifests regenerated with one R135 identity. No migration. See R135-CHANGES.md.

Integrity: FILE-MANIFEST.sha256 lists every file with its SHA-256. It is the
only authoritative per-file hash list. RELEASE-MANIFEST.json, PACKAGE-MANIFEST.json
and tegh-build.json all identify this package as R135; per-file hashes kept in
their "history" sections record earlier releases and are not expected to match.

# R145 changes over R144: PDF statement converter and Document Intake

5.9.9 / Build 5990 / Schema 46. Cache token `5990-r145-tegh`; the client view page now loads its output script with `5990-r145-tegh` too.
No database migration. No server code changed: everything here runs in the browser.
Status: **staging candidate**, `productionReady: false`. The host acceptance items are still open.

## Bank Statement Converter (Banking › Upload Statement › Bank Statement Converter)
- **New column-aware reader** (`assets/tegh-statement-layout-r145.js`). It reads the position of every word on the page, the way a person reads the statement:
  - **Columns.** It finds the column headings (Date, Description, Withdrawals / Cheque-Debit, Deposits / Deposit-Credit, Amount, Balance; a transaction date beside a posting date) and puts each amount in its column by position.
  - **Statement layout.**
    - A date printed only on a day's first line is carried down to the lines below.
    - Descriptions that wrap onto a second or third line are joined.
    - Side panels and boxes beside the table are ignored.
    - Summary lines and payment slips are never read as transactions: "Sub-total", "Continued", "Debits 6 1,382.85", and a slip with two amounts in one column.
  - **Years.** Years come from the statement period, such as "For Jan 1 to Jan 31, 2023", "AUG 31/23 - SEP 29/23" or "STATEMENT DATE: … / PREVIOUS STATEMENT: …". A card statement running from December to January gets the right year on each line. Dates that print their own year ("2026-03-04", "Mar 4, 2026") are also read.
  - **Amount formats.** "1,234.56", "$1,234.56", "-$500.00", "500.00-", "(12.50)" and "12.50 CR".
  - **Credit cards.** Charges are money out and payments money in, and the balance is read as an amount owed.
- **The statement checks itself.** The review screen shows a green line when the rows add up and an amber line when they don't. The checks are:
  - the opening balance plus the rows equals the closing balance printed on the statement (or the last printed balance, when the bank prints no closing line);
  - every running balance on the statement agrees with the rows above it, and a row that differs is marked with the amount of the difference;
  - per-page counts and totals agree, where the bank prints them ("Debits 6 1,382.85", "Credits 0 0.00").
- If a card statement is read into a bank account, the review says so.
- The opening and closing balances are handed to the normal server preview, which shows any difference.
- **Fallback.** If the new reader finds no column headings, the R144 reader is used as before. If the rows don't balance, the review says so and points to the line to check.
- **Results.**
  - Three real Canadian business statements supplied by the owner: a chequing statement with date / description / withdrawals / deposits / balance, a business Visa statement and a business chequing statement. These are not included and were never committed.
    - **R144 reader:** 40 rows with 19 needing manual amounts and the wrong total; 21 rows with the wrong total; and 0 rows.
    - **R145 reader:** 135, 21 and 254 rows, with no manual entry. Each matches its statement's opening and closing balances to the cent, all 144 + 35 running balances agree, and all 18 page totals agree.
  - The committed tests use six made-up statements in the same layouts (see the gate evidence).

## Document Intake (Payables › Document Intake)
- **Field reader rewritten** (`candidateFromText` in `assets/tegh-native-ocr-v5220.js`). It is still local to the browser and still feeds human review.
  - **Labels and values.** A value can be on the same line as its label or on the line below.
  - **Dates.**
    - "Invoice Date … Due Date …" on one line is read correctly.
    - A numeric date that reads both ways (03/04/2026) is **never guessed**. Both readings are offered as one-click choices on the review form.
    - French dates such as "15 mars 2026" are read.
    - A due date is worked out from "Net 30" when none is printed.
  - **Québec / French labels:** Facture n°, Sous-total, TPS, TVQ, TVH, Montant dû, and "185,69 $" amounts.
  - **Tax.**
    - Several tax lines (GST + PST, TPS + TVQ) are added together.
    - Registration numbers are never read as amounts.
    - "Tax included" receipts give subtotal = total − tax.
  - **Receipts.**
    - The receipt or transaction number is read.
    - When a receipt prints several "total" lines, the one equal to subtotal + tax is chosen.
  - **Vendor and line items.**
    - A heading row with "INVOICE" beside the vendor name still gives the vendor.
    - Address and phone lines are skipped.
    - Line items drop the quantity and unit-price columns from the description.
- **Photos and scans.** Phone photos are turned upright from the camera orientation, scaled to a size OCR reads well, turned grey and contrast-stretched. Scanned PDF pages are rendered at a higher resolution (2.5×) and given the same clean-up.
- **Fix: scanned PDFs.** The bundled PDF reader (pdf.js 5.6) uses `Map.prototype.getOrInsertComputed`, which many current browsers lack. In those browsers, OCR of a scanned (image-only) PDF failed with "getOrInsertComputed is not a function". The standard behaviour is now installed where it is missing, on the page (`assets/tegh-upsert-polyfill-r145.js`) and in the PDF worker (`assets/tegh-pdf-worker-r145.mjs`). This also covers the attachment preview in `tegh-pdf-v5990.js`.
- **Review form.**
  - A live "Subtotal + tax = total ✓" check, or the amount of the difference.
  - The two date readings appear as buttons when the date is ambiguous.
- **DEF-14 fixed.** In the "Upload document" menu, the Upload and Extract Locally button was squeezed to about 35 px wide and pushed outside the panel, leaving no visible way to upload at desktop width. The form is now a single column there.
- **Results** on six made-up documents (a text PDF invoice, a French Québec invoice, a receipt photo, a scanned PDF invoice, a fuel receipt with tax included, and a small low-contrast café receipt):
  - **R144:** 19 of 41 fields right. The scanned PDF failed, and the café total was misread as $30.37.
  - **R145:** 41 of 41.

## Files
- New: `assets/tegh-statement-layout-r145.js`, `assets/tegh-upsert-polyfill-r145.js`, `assets/tegh-pdf-worker-r145.mjs`, `R145-CHANGES.md`.
- Changed:
  - converter and OCR: `assets/tegh-bank-converter-v5990.js`, `assets/tegh-bank-converter-v5990.css`, `assets/tegh-native-ocr-v5220.js`, `assets/tegh-native-ap-ar-v5600.js`, `assets/tegh-pdf-v5990.js`;
  - shared assets: `assets/tegh-professional-output-v5990.js`, `assets/tegh-portal-v5990.js`, `assets/tegh-r120.css`, `assets/tegh-gate-v5990.js`, `assets/tegh-preflight-v5990.js`;
  - pages: `app.html`, `client-view.html`;
  - docs and manifests: `README.txt`, `DEPLOYMENT-NOTES.txt` and the release manifests.

## Limits
- Statements must be text PDFs for the column reader. A scanned statement still needs the bank's CSV or OFX download.
- Layouts the reader has not seen may fall back to the general reader. The balance check shows whether the rows are complete.
- OCR uses the bundled English model. French accents may be lost in photos, but the labels above are still recognised.
- Every extracted field still needs a person's review before anything is created. Nothing is posted automatically.

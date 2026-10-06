# R152 changes over R151: smarter bank statement and invoice reading (Stages 1 and 2)

5.9.9 / Build 5990 / Schema 46. Cache token `5990-r152-tegh`.
Status: **staging candidate**, `productionReady: false`.

Everything runs in the browser and on your own server. No external AI or outside service is used. The text of a document never leaves the browser and is never stored.

## Stage 1: bank and credit card statements

### More statement layouts
The column reader (now `assets/tegh-statement-layout-r152.js`, which replaces the R145 file) reads:
- **Headings in English or French**, for example:
  - "Cheques & Debits", "Withdrawn/Debits", "Amounts deducted from your account";
  - "Retrait", "Dépôt", "Débit", "Crédit", "Montant", "Solde";
  - "Date de transaction", "Date d'inscription", a transaction-code column, a DR/CR column.
- **Headings printed over two lines**, such as "Amounts deducted" over "from your account ($)".
- **French amounts**: "1 500,00", "125,00 $" and "1 500,00 CR", including a thousands part printed as a separate piece of text.
- **French dates**: "02 janv.", "1er janvier 2026" and the other month names.
- **Numeric dates without a year** (01/02, 15/01). The day/month order is taken from the statement period. When both orders fit equally, Tegh does not guess: it asks for the Date Format.
- **Opening and closing lines in French and English**: "Solde d'ouverture", "Solde précédent", "Solde reporté", "Nouveau solde", "Solde de clôture", "Solde final", "Balance forward" and "Closing totals".
- **Overdrawn balances** marked with "-", "OD" or "DR".
- **Rows printed over two lines**, where the description is on the first line and the amounts are on the line under it.
- **Statement periods** written as "Du 1er janvier 2026 au 31 janvier 2026", "Période du 2026-01-01 au 2026-01-31" or "Jan 1 2026 - Jan 31 2026".

### PDFs that hold several statements
A new statement starts when one of these changes:
- the page says "Page 1 of …" again;
- the labelled account number changes;
- the statement period changes on a page that shows an opening balance.

What you see:
- The review screen says how many statements the PDF holds.
- A new **Statement in this PDF** drop-down lists each statement with its period, account and row count.
- **Statements of one account** (for example, January and February) can be read together. Tegh checks that each one starts at the previous statement's closing balance and says so.
- **When the PDF holds statements for several accounts:**
  - Tegh preselects the statements whose account matches the selected bank account.
  - Before R152 this was an error ("This PDF contains more than one account").
  - Choosing a statement for a different account is still refused.

## Stage 2: vendor invoices in Document Intake

### Line items read as a table
For text PDFs, Tegh now reads the invoice table:
- It finds the heading row (description, quantity, unit price, amount, tax), in English or French.
- It places each value under its heading and joins wrapped descriptions.
- It ignores an item-code column next to the description.
- It checks quantity × unit price = amount on every line.
- It checks that the lines add up to the subtotal.

For scanned invoices and photos, a line such as "Widget 4 12.50 50.00" is read the same way when the arithmetic agrees.

On the Document Intake card:
- the lines show in a small table: description, qty, unit price and amount, with ✓ on checked lines;
- a note says whether the lines add up to the subtotal.

### Supplier memory, learned from your corrections
When you verify a vendor invoice, Tegh compares the fields its reader found with the fields you verified. It remembers three things for that vendor:
- **Date order.** You chose "April 3, 2026" for 03/04/2026, so this supplier's numeric dates are read day/month.
- **Invoice number pattern.** For example "AA/99-9999". If the reader misses the number next time, the one text matching that pattern becomes the number.
- **Which printed amount is the invoice total.** This applies when you took the amount due rather than the total.

What is stored and shown:
- Tegh stores only the reader's own candidate fields for each document, in the new `extracted_json` column. It does not store the document text.
- The browser asks for the vendor's memory by sending only the name, email, business number and address it read.
- A **Supplier memory applied** note on the card says what was used.
- The memory is company-specific and is included in backups.

### Account and tax-code suggestions from history
- **Same wording as an earlier line.** Each line gets the account and tax code of a line with the same wording on one of the vendor's earlier invoices (for example, toner goes to the account you used for toner before).
- **Otherwise,** the line gets the vendor's most-used account.
- Every suggestion says where it came from.
- With no history, nothing is suggested.
- **Create draft vendor invoice** preselects the suggested account.

### Duplicate warnings
A card warns when the same supplier already has:
- a vendor invoice or another intake document with the same invoice number; or
- one with the same total within 7 days.

For a same-number duplicate, you must tick **"I checked: this is a different invoice"** before Tegh will create a draft or open the vendor invoice form. The server enforces this too.

### Into the vendor invoice form
A verified vendor invoice now has **Open in Vendor Invoice Form**:
- It opens the R151 form with every line, using its quantity, unit price, suggested account and tax code.
- A note at the top says the form was prefilled from Document Intake.
- Saving the vendor invoice links the intake document to it automatically.

## Fix found by the gate
The save message that appears at the top right for about 6 seconds covered the page-header buttons (for example **Upload document** on Document Intake), so they could not be clicked until it went away. The message has no controls, so clicks now pass through it (`assets/tegh-r120.css`).

## Database
These are made automatically on first use:
- the nullable column `native_agent_documents.extracted_json`;
- the table `vendor_document_memory`.

No manual migration is needed.

## Files
New:
- `api/intake_r152.php`
- `assets/tegh-statement-layout-r152.js`

Changed:
- `api/native_ap_ar_v5600.php`, `api/backup.php`, `api/index.php`
- `assets/tegh-bank-converter-v5990.js`, `assets/tegh-native-ocr-v5220.js`, `assets/tegh-native-ap-ar-v5600.js`
- `assets/tegh-portal-v5990.js`, `assets/tegh-r120.css`
- `app.html`, `assets/tegh-gate-v5990.js`, `assets/tegh-preflight-v5990.js`

Removed:
- `assets/tegh-statement-layout-r145.js`

## Tests
Suite `21-r152` covers:
- 13 synthetic statement layouts (fictional banks; expected figures from the generator's own arithmetic);
- a PDF with three statements, through the screen;
- date-order ambiguity;
- the R145 statements, unchanged;
- 6 synthetic vendor invoices through the Document Intake screen: lines, memory, suggestions, the form hand-off with linking, duplicates, privacy of the requests, company isolation and backup.

The owner's three real statements read exactly as in R151.

## Limits
- Line items are read as a table only from text PDFs. Scans use line-by-line reading.
- Supplier memory does not learn field positions on the page.
- Layouts not seen before may still fall back to the general reader. The balance check shows whether the rows are complete.

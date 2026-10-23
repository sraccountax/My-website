# R137 changes over R136: Tax Codes

5.9.9 / Build 5990 / Schema 46. Cache token `5990-r137-tegh`. No manual migration. Three tables and four columns are added automatically (see Database).
Status: **staging candidate**, `productionReady: false`. The host acceptance items are still open.

## What changed
Tax rates are no longer set automatically. The owner sets up **tax codes**, and every invoice, vendor invoice, expense and bank categorisation uses them.

**A tax code** (Settings → Company Setup → Tax Codes) has:
- **Code and name:** for example `QC` / "Quebec GST + QST".
- **Province (optional):** customers in that province get this code automatically on invoice lines. **Each province can have only one active tax code.** A second code for a province is refused, and a province that is already used is greyed out in the list.
- **One to six named taxes**, each with:
  - its own **name** (GST, HST, PST, QST, RST, CGST, SGST, …);
  - its own **rate**, to three decimals (9.975%);
  - a **sales GL account** for tax collected (a liability, for example 2100 GST/HST Payable or your own 2120 QST Payable);
  - a **purchases GL account** for tax you claim back (an asset or liability, for example 1100).

  If a tax has no purchases account it is **not recoverable** and is added to the cost of the purchase, like BC PST.

**Codes can always be edited.** Changes apply to documents saved from then on. Every document keeps a copy of the taxes it used (`document_tax_lines`), so editing a code never changes posted history. Removing a code that is already used makes it inactive instead of deleting it, which frees its province for another code.

## How it works on each document
- **Customer invoices:**
  - Each line has a **Tax code** picker. It defaults to the code for the customer's province; you can pick another code or **No tax**.
  - Totals list each tax separately: GST 5%, QST 9.975%, and so on.
  - Posting credits **each tax to its own GL account**.
  - The same applies to draft edits, recurring invoices and the invoice PDF (one line per tax).
- **Vendor invoices (bills):** a **Tax code** picker replaces the GST/PST tick boxes. It defaults to the company's home-province code. You can choose, for example, a GST-only code for a BC supplier who charged only GST, or No tax.
  - Tax-added and tax-included amounts both work.
  - Recoverable taxes post to their purchases GL; non-recoverable taxes are added to the expense.
- **Expenses:** same Tax code picker and posting as vendor invoices.
- **Credit and debit notes:** the note's tax is split across the original document's taxes and posts to each tax's account. A debit note's new receivable keeps its own tax split.
- **Cash-basis receipts and payments:** tax is recognised per tax, in the document's proportions.
- **Bank-feed categorisation:** the API accepts a `taxCodeId`. The categorisation screen still shows its GST/PST tick boxes; in tax-code mode, ticking either applies the company's home-province code.

## Company registration and existing companies
- **New companies:**
  - Start in tax-code mode with **no codes**, so nothing is charged until codes exist.
  - The registration form asks for the GST/HST number and shows a short "What applies in <province>" note.
  - After creation Tegh opens Tax Codes.
  - **Start from a Canadian example** fills the form for a chosen province (for Quebec: GST 5% → 2100/1100 and QST 9.975% → 2110/1110) for the owner to check and save. Nothing is saved automatically.
- **Existing companies:** keep the R135/R136 rules (GST/HST by province plus PST from Company Details) until their **first tax code is saved**. From then on they use tax codes. The Tax Codes page says this before the switch. Documents already saved keep their amounts.
- **Company Details:** in tax-code mode, shows a summary of the codes and a link to Tax Codes instead of the PST fields.
- **Tegh Assist:** "tax codes", "QST", "sales tax setup" and similar requests open Tax Codes.

## Database (created automatically, no manual step)
- **Tables:**
  - `tax_codes`, with a unique province per company among active codes, enforced by the database;
  - `tax_code_components`;
  - `document_tax_lines`.
- **Columns:**
  - `invoice_lines.tax_code_id`
  - `bills.tax_code_id`
  - `expenses.tax_code_id`
  - `companies.tax_setup_mode` (`legacy` or `codes`)
- **Privileges:** the database user needs CREATE and ALTER privilege.
- **Backups:** backups include tax codes, their taxes and each document's tax detail. Restore skips the computed province-key column.

## Verified (local: scratch MariaDB, PHP 8.4, Playwright Chromium)
- **Database test 1:** 27/27 pass.

  | Scenario | Result |
  |---|---|
  | New company, no codes | No tax charged |
  | QC code saved | Two taxes, 14.975% total |
  | Second Quebec code | Refused |
  | AR control account as a tax account | Refused |
  | Two taxes with the same name | Refused |
  | $1,000 sale, Quebec customer | GST $50 → 2100, QST $99.75 → 2120 |
  | $1,000 sale, BC customer | GST $50 → 2100, PST $70 → 2110 |
  | Alberta customer, no AB code | No tax |
  | Chosen code with CGST 9% + SGST 9% | $90 → 2130 and $90 → 2131 |
  | Line-level code change, and a No tax line | Applied per line |
  | Invoice PDF | GST 5% and QST 9.975% on separate lines |
  | Credit note | Reverses $10.00 in 2100 and $19.95 in 2120 |
  | Bill, GST-only code | Expense $1,000, ITC $50 |
  | Bill, BC code | PST added to cost ($1,070) |
  | Bill, tax included, Quebec code | 1100 $50, 1120 $99.75 |
  | Bill, no tax | No tax |
  | Draft bill issued later | Uses the saved taxes |
  | Expense, tax included | Split correctly |
  | Code edited (QST 10%) | New invoices use it; the old invoice keeps $99.75 |
  | Removing a used code | Becomes inactive, province freed |
  | Existing company | Keeps the old rules until its first code, then switches |

- **Database test 2:** 9/9 pass.

  | Scenario | Result |
  |---|---|
  | Cash-basis receipt | GST → 2100, QST → 2120 |
  | Cash-basis vendor payment | ITCs to 1100 and 1120 |
  | Recurring invoice | Uses the province code |
  | Draft detail | Returns the codes |
  | Draft edited to GST only | Saved, then issued |
  | Debit note | GST → 2100, PST → 2110, and its receivable keeps the split |
  | Backup | Exports |

- **Backup restore:** codes, taxes, document tax detail and tax-code mode restored, with GL links pointing to the restored company's accounts.
- **Old rules (legacy mode):** R135 test 21/21 and R136 test 13/13 still pass.
- **Browser, 1440×900 and 390×844:**
  - **Tax Codes page:** list, starter example, editor and live $100 example.
  - **Invoice:** defaults to the Quebec code with GST $50 + QST $99.75. Changing the line to GST only works. An invoice issued from the screen posted QST to 2120.
  - **Vendor invoice:** picker and breakdown, including "PST 7% · added to cost".
  - **Company Details:** shows the tax code summary.
  - **New company:** registration shows the Quebec note, then Tax Codes opens.

## Not covered yet (known gaps)
- **Tax reports:** the GST/HST Summary report and the dashboard tax card still read accounts 2100/1100/2110/1110. Taxes mapped to other accounts (for example 2120 QST Payable) are in the General Ledger and Trial Balance but not in those summaries. A "tax by code" report is the next step.
- **Bank-feed categorisation screen:** has no tax code picker yet (see above).
- **Customer region:** matching uses the customer's province. Codes for non-Canadian regions can be created and chosen by hand, but are not applied automatically until customers have a region field.
- **Recurring invoice profiles:** do not store a per-line code; they use the customer's province code.
- **New draft-editor line:** starts at No tax until a code is chosen.

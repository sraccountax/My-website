# R154 changes over R153: GL reports and Budgets with several companies

5.9.9 / Build 5990 / Schema 46. Cache token `5990-r154-tegh`.
Status: **staging candidate**, `productionReady: false`. No database change.

## Owner request (2026-10-06)

"Let budgets and GL reports work with multiple companies too."

In R153 these three screens asked you to select one company when several were selected. They now run for every selected company. Each line shows the company's short name, and the totals are combined.

### 1. General Ledger Account Report
- **The account is chosen by its code**, for example 4000 · Service Revenue. Each company keeps its own copy of an account, so the report finds the account with that code in each selected company. The list shows which companies have each code.
- **Balance by company**: a table of each company's opening balance, debits, credits, closing balance and number of lines, with a **Combined** row.
- **A company without that code** is named under the table ("Not in GLB: that company has no account 6990").
- **The ledger** lists each company's account in turn, headed "GLA · 4000 · Service Revenue". Each company keeps its own running balance, and every line shows its company.
- To open a line in the Day Book, select that company at the top.

### 2. Bank General Ledger Report
- **One bank account per company**, chosen in a picker for each company. Each picker starts on the company's first linked bank account. Choose "Not included" to leave a company out.
- **The ledger** lists each company's bank account in turn, with its own running balance. Every line shows its company, and the debit and credit totals are combined.

### 3. Budgets (and Budget versus Actual)
- **Every selected company's budgets** are listed with their company, period, status, planned, actual and variance figures.
- **One budget per company goes into Budget versus Actual.** Each company starts with its active budget, otherwise its latest. Use the "Use for …" buttons to change it.
- **A Combined row** totals the planned, actual and variance figures of the chosen budgets.
- **Budget versus Actual** lists every account once per company, and its Excel and PDF exports include the Co. column.
- **Creating, editing and closing a budget** still happens in one company. The page says so, as all combined views are read-only.

### Also
- **Group total labels.** In grouped reports with a Co. column, a group's total label ("Total GLA · 1015 · Operating Chequing") now runs across the empty cells beside it instead of wrapping inside the narrow Co. column.
- **Unchanged with one company selected.** The three screens are exactly as before.

## Website bank statement converter v11 (separate from this package)
The converter on bankstatementconverter.sraccountax.ca has its own update, v11. It is in `sr-bank-statement-converter-v11/` in the repository, with `INSTALL-v11.txt`, and is not part of this ZIP.
- **Reader.** It uses the R152 statement reader: more Canadian layouts in English and French, numeric dates checked against the statement period, DR/CR columns and OD balances.
- **Several statements in one PDF.** Such PDFs are read one statement at a time, with a chain check for each account.
- **Results.** It reads all 13 R152 test statements exactly; v10 read 2 of them exactly.

## Files
- `assets/tegh-portal-v5990.js`: the multi-company GL Account Report, Bank GL Report and Budgets screens.
- `assets/tegh-professional-output-v5990.js`: reports run for a different account or budget in each company. Ledger groups are kept apart and labelled per company.
- `assets/tegh-registers-r23.js`: group total labels beside the Co. column.
- `assets/tegh-r120.css`: per-company summary tables.
- Also: `app.html`, `assets/tegh-gate-v5990.js`, `assets/tegh-preflight-v5990.js` and `assets/tegh-bank-converter-v5990.js` (cache token); `R154-CHANGES.md`, `README.txt`, `DEPLOYMENT-NOTES.txt` and the manifests.

## Tests
New suite `23-r154` (12 checks) with two synthetic companies. The expected figures are worked out by hand from the documents the test creates:
- GL 4000 closes at $1,000.00 CR and $250.00 CR, combined $1,250.00 CR.
- The bank receipts are $600.00 and $250.00.
- The budgets plan $1,200.00 and $300.00 against actuals of $1,000.00 and $250.00.

R153's MC-06 used to check that these three reports ask for one company. It now checks that they open their combined screens.

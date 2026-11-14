# R183: Bank Transaction Report and Match and Post fixes

R183 = R182 plus five fixes from beta feedback. No switch; no database change.

## 1. Downloading the Bank Transaction Report

**What was wrong**
- Once the report finished loading, the only way to download it was a small **Actions ⋮** menu in the corner of the
  table.
- If the complete report could not be prepared on the server, every export was disabled. The page gave no reason and
  offered no other way to download.

**Now**
- **Download ▾** sits beside **Generate Report**, with Excel, CSV, PDF and Print. It exports every line that matches the
  filters, not only the current page.
- If the complete report cannot be prepared, Download says why, shows the support reference (the same reference
  as in the server log) and offers:
  - **Download CSV of these lines**: the lines shown on screen;
  - **Try again**.

## 2. Running balance

The report has a **Balance** column: the bank account's balance after each line.

- It uses the same definition as Reconcile Account: the account's opening balance (Opening Balances), plus every bank
  line up to that line in date order. Lines marked excluded or duplicate are not counted.
- With **All bank accounts** selected, each line shows its own account's balance.
- A search or status filter hides lines but does not change the balance shown on the lines that remain. When the
  period starts later, the balance carries every earlier line.
- For a bank account in a currency other than the books' currency, the balance is in the account's currency, and the
  opening balance (kept in the books' currency) is not added.
- **Balance** is in the default columns. Anyone who had chosen their own columns keeps them and can add Balance under
  **Actions ⋮ › Choose columns**.

## 3. Order of lines, and sorting

- **Order:** the report and Match and Post list lines by date, then in the statement's own order, then by when they
  were entered. A line entered by hand appears among the statement lines of its date.
- **Ties:** before R183, lines with the same date had no fixed order in the report.
- **Match and Post:** click **Date**, **Description**, the **amount** column or **Status** to sort; click again to reverse.
  Click **Date** to return to date order.
- **The report** already sorts by clicking a column name.

## 4. Match and Post layout

- **+ Add transaction** is beside **Post transactions | Match transactions**.
- The edit ✎ button is on the description's first line.

## Testing

| What | Result |
|---|---|
| Suite `57-r183` (5 checks) | Balances worked out by hand for a statement mixed with lines entered by hand; search and later-start filters; Download of Excel, CSV and PDF from the screen; the failure path (simulated server error) showing the reason, reference and CSV; Match and Post order, sorting both ways, button placement and pencil position |
| Canadian before/after comparison and full gate | See `beta-gate-evidence/r183/RESULTS.md` |

Not tested on the IONOS server. If Download still fails there, the support reference it now shows points to the
cause in the server log.

## Files

| Area | Files |
|---|---|
| Server | `api/report_loaders_v5980.php` (balance column, order), `api/operations.php` (Match and Post order on equal dates) |
| Browser | `assets/tegh-portal-v5990.js`, `assets/tegh-registers-r23.js` (Balance in the default columns), `assets/tegh-r182.css`, `assets/tegh-gate-v5990.js`, `assets/tegh-preflight-v5990.js`, `app.html` |
| Database | No change |
| Cache token | `5990-r183-bank` |

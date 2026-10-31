# R136 changes over R135: exact QST (9.975%) and other three-decimal PST rates

5.9.9 / Build 5990 / Schema 46. Cache token `5990-r136-tegh`. No manual migration: one column is added automatically (see below).
Status: **staging candidate**, `productionReady: false`. The R135 host acceptance items are still open.

## What was wrong
Tegh stored every tax rate in whole basis points (hundredths of a percent). Quebec's QST is 9.975%, which cannot be stored that way, so it was saved as 9.98%. On a $1,000 sale that charged $99.80 instead of $99.75, and on $100,000 it charged $9,980.00 instead of $9,975.00.

## What changed
- **Storage:** the company's PST/QST/RST rate is now stored exactly in a new column `companies.pst_rate_mpct`, in thousandths of a percent (7% = 7000; QST 9.975% = 9975). The old `pst_rate_bps` column is kept, rounded, for older readers only.
- **Calculations:** every PST calculation uses the exact rate:
  - customer invoices, draft edits and recurring invoices
  - bills, expenses and bank-feed categorisation (tax-included and tax-added)
  - the Tegh AI learning rules
- **Screens:** the invoice and bill/expense previews use the exact rate. Rates show as "9.975%" on the Charge QST box, the Apply PST box and Company Details (rate field now takes three decimals). Company registration saves 9.975 for Quebec.
- **Removed:** the R135 warning that QST is recorded at 9.98%.
- **Sales tax guide:** the $100 example explains that 9.975% of $100.00 is $9.975, which rounds to $9.98. On $1,000.00 it is exactly $99.75.
- **API:**
  - Company create and Settings accept `pstRateMpct` (preferred), `pstRatePercent` (for example "9.975"), or the old `pstRateBps`.
  - Rates above 25% are refused (`422 pst_rate_invalid`).
  - Saving settings without a rate keeps the exact stored rate.
  - The workspace returns `pstRateMpct` alongside the rounded `pstRateBps`.

## Database
The column is added the first time a company is created or its settings are saved (`ALTER TABLE companies ADD COLUMN pst_rate_mpct INT NULL`). At that point it is backfilled from `pst_rate_bps x 10`, which is exact for every rate that was stored before. Until then, calculations use the same fallback. The database user needs ALTER privilege, as for earlier automatic additions.

**Quebec companies set up before R136:** they hold 9.98% and must be corrected. Open Company Details, enter 9.975 and save. Invoices already issued keep their recorded amounts.

## Verified (local: scratch MariaDB, PHP 8.4, Playwright Chromium)
- **Old vs new calculation:** compared on 400,000 cases covering 10 rate pairs, tax-added and tax-included. There were 0 differences for every whole-basis-point rate, so BC, MB, SK, HST and GST results are unchanged.
- **Quebec database test:** 13/13 pass.

  | Scenario | Result |
  |---|---|
  | Existing BC company before the column existed | 7% via the fallback |
  | QC company created | Stores 9975 |
  | Workspace | Returns `pstRateMpct` 9975 |
  | $1,000 sale | Cr 2100 $50.00 / Cr 2110 $99.75 |
  | $123.45 sale | QST $12.31 |
  | $100,000 sale | QST $9,975.00 |
  | Draft editor detail | 9975 and GST 500 |
  | Bill, tax added, $1,000 | $50.00 + $99.75 = $1,149.75 |
  | Bill, tax included, $1,149.75 | Backs out to $1,000 + $50.00 + $99.75 |
  | Settings save, with or without a rate | Keeps 9975 |
  | Rate of 30% | Refused |
  | Old `pstRateBps: 700` input | Stored as 7000 |
  | BC 7% | Unchanged |

- **R135 posting test:** still 21/21.
- **Browser:** the QC invoice form shows QST $99.75 and a total of $1,149.75; Company Details shows 9.975 and the guide.
- **Regression:** see RELEASE-MANIFEST.json `executedLocalGates`.

# R135 changes over R134: PST on customer invoices, sales tax explained, Product Activity, one release identity

5.9.9 / Build 5990 / Schema 46. Cache token `5990-r135-tegh` (client view page `5990-r135`). No database migration.
Status: **staging candidate**. `productionReady` stays `false` until the host acceptance items at the end are done.

## 1. PST on customer invoices (accounting)

**What was wrong (confirmed in code and by a database test):** customer invoices only ever charged GST/HST. `invoice_record_tax_split()` always saved `pst_cents = 0`, and `invoice_posting_lines()` credited all invoice tax to 2100 GST/HST Payable. Bills already split GST/HST (1100) and PST (1110). A BC company registered for PST could not charge PST on a sale. The baseline test on the R134 code showed a $1,000 BC sale billed $1,050, with nothing in 2110 PST Payable.

**Now:**
- When the company is registered for PST (Company Details → *PST applies*, with a rate), a taxable invoice line to a customer **in the company's own province** adds PST by default. Each line has a **Charge PST** tick box, so an exempt item can be switched off. Sales to customers in other provinces get GST/HST only. Quebec shows **QST** and Manitoba **RST**.
- The API accepts an optional `pst` true/false per line. An explicit `pst: true` for a company with no PST setup is refused (`409 pst_not_configured`).
- The invoice saves the split (`gst_hst_cents`, `pst_cents`) and posts **Dr 1200 AR / Cr revenue / Cr 2100 GST/HST collected / Cr 2110 PST collected**. Bills already worked this way.
- The same rule applies to issuing drafts, draft edits (the editor shows the GST/HST rate and a PST tick box, so an unchanged draft saves without an "override reason"), recurring invoices, credit/debit notes (split in the invoice's GST/HST : PST ratio, as before), and cash-basis customer receipts (tax recognised in the same ratio to 2100 and 2110).
- The invoice form totals show **Subtotal · GST/HST · PST · Invoice Total**. Each line shows, for example, "GST/HST $50.00 + PST $70.00".
- Invoice PDFs show separate GST/HST and PST lines when the invoice has PST. Invoices without PST look as they did before.

## 2. Sales tax explained, from company registration

**Add company or client file** now has a *Sales tax (GST/HST and PST)* section:
- Registered for GST/HST (and the number).
- For BC, MB, QC and SK: *Registered to collect PST/RST/QST*, the rate (filled in from the province: BC 7%, MB 7%, SK 6%, QC 9.975%), and whether tax paid on purchases can be recovered (ticked for Quebec).
- A **"How sales tax works for this company"** guide updates as you change the province or tick boxes. It covers:
  - What you charge in that province: HST only, GST only, or GST plus PST/QST/RST.
  - Who each tax is paid to.
  - A worked $100 example showing which account each tax posts to (2100, 2110).
  - Input tax credits (1100).
  - Whether PST paid on purchases is recoverable.
  - The $30,000 small-supplier threshold, and that some items are zero-rated or exempt.

The same guide appears in **Company Details** under the tax fields.

**Known limitation:** Tegh stores tax rates in whole basis points (hundredths of a percent). The 9.975% QST rate is therefore recorded as 9.98%, which adds 5 cents of QST on a $1,000 sale. The guide says this. Storing the exact QST rate needs a finer-precision rate column across invoices, bills and expenses, which is a schema change for a later release.

## 3. "Inventory Report" is now "Product Activity"

The report lists the quantities and amounts on customer and vendor invoices for each product. It never showed stock on hand, cost of goods sold or inventory value. The report is renamed everywhere: the page, the reports catalogue, the PDF/Excel title, the CSV name, the workflow guide and Tegh Assist. Its note now says:

> Product activity, not inventory. … Under ASPE Section 3031 and IFRS (IAS 2), inventory is measured at the lower of cost and net realisable value using a cost formula such as FIFO or weighted average; Tegh does not track stock movements or cost layers yet. Record inventory and cost of sales in the general ledger (for example a count-based period-end adjustment) and report them from the Balance Sheet and Income Statement.

Tegh Assist still finds the report when asked for "inventory report" or "stock report". It explains that Tegh has no inventory report yet and names this report as the old one.

## 4. One release identity

`RELEASE-MANIFEST.json` still said R118, and `PACKAGE-MANIFEST.json` and `tegh-build.json` still said R120 with old test wording. All three were regenerated and now carry the same top-level identity:
- `release: R135`, the package name, generation time and parent commit.
- `productionReady: false` with `productionReadyBlockers`.
- The R135 local test results (`executedLocalGates`) and what was not executed (`notExecuted`).
- The seven open `hostAcceptance` items, and the known limitations.

Earlier release sections (`r118Files` … `r134Files`, R117 hotfix hashes, the old "changedActiveFiles" hashes) moved unchanged under `history`, marked as historical. `FILE-MANIFEST.sha256` is named as the only authoritative per-file hash list.

## Verified (local, this build: scratch MariaDB, PHP 8.4, Playwright Chromium)

- **PHP lint:** all 79 `api/*.php` files pass. Changed JavaScript parses.
- **Database-backed posting test:** 21/21 pass with a BC company registered for GST 5% and PST 7%:
  - A $1,000 BC sale posts Dr 1200 $1,120 / Cr 4000 $1,000 / Cr 2100 $50 / Cr 2110 $70.
  - A sale to an Alberta customer gets GST only.
  - A line with `pst:false` gets GST only.
  - Mixed lines split correctly.
  - A draft saves, round-trips through the editor without an override prompt, is edited and issued.
  - The invoice PDF model shows GST/HST $50 and PST $70.
  - A $200 credit note reverses $10 in 2100 and $14 in 2110.
  - An Ontario HST 13% sale posts entirely to 2100.
  - `pst:true` without a PST setup is refused.
  - A recurring invoice splits correctly.
  - A cash-basis half payment recognises GST $25 and PST $35.

  The same test on the R134 code failed 5 checks (no PST charged, saved or posted), and its PST credit note was refused.
- **Browser:** the invoice form at 1440×900 and 390×844, and an invoice issued from the UI that posted Cr 2100 $50 / Cr 2110 $70. The company guide was checked for BC, ON, QC and AB, and in Company Details.
- **Regression:** 46 screens with no errors and no sideways overflow on desktop and phone. Render churn totalled 1. Tegh Assist passed 54/54 and the holdout set 45/45; "inventory report", "product activity" and "stock report" all open Product Activity. The client viewing link API passed.
- **Not screenshot-verified:** dark mode for the new panels. Dark styles were added, but the test harness did not switch the theme.

## Not verified here: host acceptance (open)

These can only be done on the serving host by the owner. See DEPLOYMENT-NOTES.txt:
1. Host PHP lint.
2. `sha256sum -c FILE-MANIFEST.sha256` in the web root.
3. Real statement files.
4. A backup restore test.
5. Live postings on the host database.
6. A signed-in browser check of the R134 client charts.
7. Owner review.

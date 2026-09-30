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

## Verified (local, this build)

See `executedLocalGates` in RELEASE-MANIFEST.json for the recorded results.

## Not verified here: host acceptance (open)

These can only be done on the serving host by the owner. See DEPLOYMENT-NOTES.txt:
1. Host PHP lint.
2. `sha256sum -c FILE-MANIFEST.sha256` in the web root.
3. Real statement files.
4. A backup restore test.
5. Live postings on the host database.
6. A signed-in browser check of the R134 client charts.
7. Owner review.

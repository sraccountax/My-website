# R142 changes over R141: owner-approved wording

5.9.9 / Build 5990 / Schema 46. Cache token `5990-r142-tegh` (client view page unchanged: `5990-r135`). No migration.
Status: **staging candidate**, `productionReady: false`. The host acceptance items are still open.

The owner approved the replacement wording proposed after R141. These changes are wording only, plus one line on the payroll screen showing which CRA rate tables are loaded. Tax calculations and postings are unchanged.

## Payroll (correction)
R141's notice said Payroll Support covers "CRA rules for Canadian provinces and territories". Tegh refuses Quebec employees, so that overstated what it does.
- **In-app notice:** "Tegh Payroll Support is for Canadian payroll outside Quebec. It follows the CRA payroll deduction tables (T4127) and does not handle Quebec payroll (Revenu Québec, QPP, QPIP)." The rest of the notice is unchanged.
- **`product.html` and `subscriptions.html`:** "Canada, excluding Quebec".
- **Payroll screens:** below the notice, a line lists the CRA T4127 tables loaded and their start dates, for example "CRA T4127 — January 2026 (from 2026-01-01); CRA T4127 — July 2026 (from 2026-07-01)". Each pay run uses the table in effect on its pay date. The list comes from the server (new `rateTables` field in the payroll workspace), so it stays correct when the Platform Owner activates a newer edition.

## Sales tax wording
- **Starter-code note on other provinces' PST/QST/RST codes:** "GST only. Charge British Columbia PST only if you are registered with British Columbia. Businesses outside British Columbia may have to register if they sell to customers there. Check with the province. Once registered, edit this code and add PST 7%." This applies to new starter codes only. Codes created earlier keep their old note until edited.
- **Where tax applies:** "Tegh picks the tax code from the customer's province (or state and country). That is usually the right place for the sale, but for goods shipped elsewhere or for some services, change the line's tax code."
- **Zero-rated and exempt:** the guide now explains the difference and whether input tax credits can be claimed.
- **Small supplier:** "Small suppliers (total taxable sales of $30,000 or less over the last four calendar quarters) don't have to register; above that, most businesses must. If you're not registered, tax you pay on purchases becomes part of the cost."
- **Input tax credits:** "…recorded as recoverable, an input tax credit… Keep your receipts, because the CRA can ask for them."
- **Companies outside Canada:** "Tegh doesn't check foreign tax rates or rules. Confirm them with a local adviser."
- **Short guide at company registration:** it now says that codes for other provinces charge GST or HST only. Before, it implied every province's code charged that province's tax, which was out of date after R141. It also includes the where-tax-applies, zero-rated/exempt and small-supplier sentences.

## Product Activity note (report and Ask Tegh)
- Adds specific identification for items that are not interchangeable, and that LIFO is not allowed.
- Removes "yet" ("Tegh does not track stock quantities or cost layers").
- Gives explicit steps: "count your stock at period end, value it at the lower of cost and net realisable value, and post one journal that adjusts the Inventory account to that value, with the difference to cost of goods sold. Ask your accountant if you hold significant stock."

## Files
- **API:** `api/payroll.php` (rate tables summary), `api/tax_codes_r137.php` (starter-code note), `api/ai_agent.php` (Product Activity answer).
- **Assets:** `assets/tegh-portal-v5990.js`, `assets/index-BsxPiq85-v2817.js`, `assets/tegh-r120.css`, `assets/tegh-gate-v5990.js` and `assets/tegh-preflight-v5990.js` (cache token).
- **Pages:** `app.html` (cache token), `product.html`, `subscriptions.html`.
- **Docs:** README.txt, DEPLOYMENT-NOTES.txt, R142-CHANGES.md.
- **Manifests:** FILE-MANIFEST.sha256 and the release manifests.

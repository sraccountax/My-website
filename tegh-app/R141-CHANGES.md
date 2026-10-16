# R141 changes over R140: gate follow-ups, countries, tax code report, dashboard figures

5.9.9 / Build 5990 / Schema 46. Cache token `5990-r141-tegh` (client view page unchanged: `5990-r135`).
Status: **staging candidate**, `productionReady: false`. The host acceptance items are still open.

## Gate follow-ups
- **DEF-08 (starter tax codes charge PST/RST where the company is not registered).** The Canadian starter codes now depend on the company's home province. The codes for BC, MB, SK and QC charge PST/RST/QST only when that is the company's own province. For any other province they are GST only, with the note "Starter code: GST only, because PST is charged only by businesses registered in British Columbia. If you register there, edit this code and add the PST rate." HST provinces keep HST. Codes that companies created earlier are not changed.
- **OBS-2 (invite and reset tokens in the URL query string).** Invitation, account-setup and password-reset links now carry the token after `#` (`/app.html#accountSetup=…`, `/app.html#passwordReset=…`). Browsers do not send that part to the server, so the token no longer reaches access logs or Referer headers. The page reads the token, removes it from the address bar and looks it up with a same-origin POST. Old `?accountSetup=` links still work until they expire.
- **Microsoft Clarity removed.** The Content-Security-Policy in `.htaccess` no longer allows `*.clarity.ms` or `c.bing.com`. No page in the package loads Clarity. The R139 report wrongly said the marketing pages still loaded it; only the tagline "Control · Clarity · Intelligence" contains the word.

## Countries and provinces/states
- Company registration, Company Details, the guided company form, customers (including quick-create) and vendors have a **Country** list (249 ISO countries) and a **Province / State** field. For Canada it is a list of the 13 provinces and territories (required for companies and customers). For other countries it is an optional region code, such as NY, MH or ENG.
- A company outside Canada:
  - the tax section reads "Sales tax, VAT or GST";
  - PST and the Canadian starter codes are not offered;
  - Payroll Support is not available (it is Canada only);
  - the general ledger still reports in CAD, and the form says so.
- **Tax codes for other countries.** The Tax Codes editor's region now takes a country and an optional state, for example `US-NY`, `US`, `IN-MH` or `GB`. Invoices pick a code automatically by the customer's state first, then their country. If the customer has no province or state and is in the company's country, the company's own province is used.
- New database fields (added automatically on the first request; ALTER privilege needed):
  - `companies.country` (default `Canada`);
  - `province` widened to VARCHAR(10) on companies, customers and vendors.

## Tax Code Report
**Settings › Company Setup › Tax Code Report** (also linked from Tax Codes and in the Reports catalog under Tax) lists every code with:
- region, rates, and sales and purchase GL accounts;
- status;
- number of documents that used it, with total sales and purchase tax (all dates).

You can filter by status and export to CSV, Excel or PDF. Tick codes and press **Edit selected** to open each one in the Tax Codes editor in turn; every row also has its own **Edit**.

## Dashboard Figures
**Settings › Company Setup › Dashboard Figures** chooses which GL accounts feed each dashboard number:
- Money in the bank (Banking);
- Customers owe you (Receivables);
- You owe suppliers (Payables);
- Profit this year (income accounts minus expense accounts);
- Sales tax you owe (tax collected minus tax paid).

Each card stays on **Automatic** (the standard calculation) unless the owner or an admin chooses accounts. Mapped cards show "· your GL mapping" on the dashboard and the client viewing dashboard. Control checks such as AR and AP against their subledgers keep the standard figures. The table `company_dashboard_mappings` is created automatically. Mappings are **not** included in backups in this release.

## Payroll wording
- `product.html` and `subscriptions.html` call it the **Payroll Support Tool (Canada only)**: "CPP, EI and income-tax estimates for you to review and confirm".
- The in-app Payroll Support notice now begins "Tegh Payroll Support is for Canadian payroll only (CRA rules for Canadian provinces and territories)."
- The API refuses payroll setup for a company outside Canada (`payroll_canada_only`).

## Files
- **New API files:** `api/regions_r141.php`, `api/dashboard_mappings_r141.php`.
- **Changed API files:** `api/index.php`, `api/companies.php`, `api/accounting.php`, `api/advanced.php`, `api/books.php`, `api/operations.php`, `api/payroll.php`, `api/tax_codes_r137.php`, `api/workspace_summary_v5610.php`, `api/auth.php`, `api/invitations_v5980.php`, `api/platform.php`.
- **Changed assets:** `assets/tegh-portal-v5990.js`, `assets/index-BsxPiq85-v2817.js`, `assets/tegh-gate-v5990.js`, `assets/tegh-preflight-v5990.js`, `assets/tegh-r120.css`.
- **Changed pages:** `app.html`, `product.html`, `subscriptions.html`, `.htaccess`.
- **Docs:** README.txt, DEPLOYMENT-NOTES.txt, R141-CHANGES.md.
- **Manifests:** FILE-MANIFEST.sha256 and the release manifests.

## Known limitations
- The base (reporting) currency remains CAD for every company.
- Dashboard mappings are not saved in backups. Re-enter them after a restore.
- Payroll Support is Canada only.
- Companies still on the legacy tax rules (no tax codes saved) calculate tax as before R141; foreign customers are not given a tax code automatically.
- Starter codes created before R141 are unchanged. Companies outside BC, MB, SK and QC should still deactivate PST/RST codes they are not registered for.

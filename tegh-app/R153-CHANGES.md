# R153 changes over R152: company address, multi-company report labels, sidebar arrows, theme switch

5.9.9 / Build 5990 / Schema 46. Cache token `5990-r153-tegh`.
Status: **staging candidate**, `productionReady: false`.

## Owner requests (2026-10-06)

### 1. Company address in company setup, printed on customer invoices
- **New fields.** Add Company and Company Details now have an **Address and contact** section:
  - street address and address line 2;
  - city and postal/ZIP code;
  - phone and business email;
  - a **Short Name**.

  Province/state and country were already there.
- **On customer invoices**, the company address, phone and email are printed:
  - when the invoice template has no address of its own;
  - in place of the "ON, Canada" placeholder that new templates were created with.

  A template with its own address keeps using that address.
- **Issued invoices don't change.** They keep the address they were issued with, as before (it is copied into the invoice when it is issued).
- **Short Name.**
  - Up to 12 characters, for example "ALP".
  - When it is blank, the initials of the company name are used ("Bayview Trading Associates" → BTA).
- **Validation.** An invalid email or a short name longer than 12 characters is refused with a plain message.

### 2. Reports with several companies selected: each line shows its company
**What was wrong before.** With several companies selected, many reports quietly showed **only the first company's** data under the "Consolidated view" banner. Affected reports included the ageing reports, Day Book, Audit History, Tax Code Report and the pay-run register.

**What R153 does:**
- **Every catalog report is now read for each selected company** and combined into one table. A **Co.** column shows the company's short name on every line.
- **Totals are added across companies**, including the section totals and summary rows (for example Total Income and Net Profit).
- **Day Book vouchers** with the same number in two companies stay separate.
- **Exports include the Co. column**: PDF, Excel and CSV.
- **The combined reports are:**
  - Profit and Loss, Balance Sheet, Trial Balance, Cash Flow;
  - General Ledger Detail, Day Book, Audit History;
  - Receivable and Payable Ageing, Customer and Vendor Balances;
  - Customer and Vendor Invoice & Note Registers, Expense Register;
  - Bank Transaction Report, the bank reconciliation summary list;
  - Tax Code Report, GST/HST/PST Summary, Currency Exposure;
  - Pay Run Register, Product Activity, Fixed Asset Register.
- **Reports about one GL account or one bank account, and Budgets, say "Select one company"** instead of showing the first company. These are the General Ledger Account Report, the Bank General Ledger Report and the bank reconciliation workspace. The list on the Bank Reconciliation and Fixed Assets pages still combines every selected company.
- **Companies with different base currencies** are not combined; the report says so.
- **With one company selected, nothing changes.** There is no Co. column.

### 3. Sidebar submenus open and close with an arrow
- **The arrow.** Each module in the sidebar has a clear arrow: 28 px, pointing right when the menu is closed and down when it is open.
- **What it does.** The arrow opens or closes that module's submenu without leaving the page. Several submenus can be open at once.
- **It stays closed.** A submenu you close stays closed while you move between that module's pages. Clicking the module name opens it again.
- **Long names.** A long module name ends with "…" before the arrow; the full name shows in the tooltip.
- **On phones** the drawer keeps its R131 behaviour: a module tap opens its page.

### 4. Light / Dark / System theme switch with icons
- **Top bar.** Sun, moon and monitor icon buttons sit beside the notifications bell.
- **Choosing a theme.** A click applies the theme at once and saves it (on this device and to your profile).
- **On phones** only the current icon shows; tapping it moves Light → Dark → System.
- **Appearance page.** It shows the same icons, and "Auto" is now called "System".
- **Fix:** "System" with the device in dark mode used to turn only the menus dark; cards stayed white with light text. System now resolves to the device's light or dark setting and follows it when the device changes.

## Database
Nullable columns on `companies`, added automatically on first use:
- `short_name`
- `address_line1`, `address_line2`
- `city`, `postal_code`
- `phone`, `contact_email`

No manual migration is needed.

## Files
New:
- `api/company_profile_r153.php`

Changed:
- `api/index.php`, `api/auth.php`, `api/companies.php`, `api/accounting.php`, `api/invoicing.php`
- `assets/tegh-portal-v5990.js`, `assets/tegh-professional-output-v5990.js`, `assets/tegh-registers-r23.js`
- `assets/tegh-r120.css`, `assets/tegh-preflight-v5990.js`, `assets/tegh-gate-v5990.js`, `app.html`

## Tests
Suite `22-r153` (20 checks) covers:
- company address and short name: create, edit and validation;
- the invoice address, the template's own address, and the frozen issued invoice;
- the Company Details screen;
- reports with two companies: the register (2 + 1 lines), Profit and Loss total income $1,300.00 = $1,050.00 + $250.00, ageing, Day Book, Audit History, Tax Code Report, Trial Balance;
- the single-company notices;
- the Excel export with the Co. column;
- no Co. column with one company;
- the sidebar arrows;
- the theme switch on desktop and phone, including System on a dark device, and the Appearance page.

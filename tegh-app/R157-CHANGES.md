# R157 changes over R156: company onboarding, Tegh Assist tour, time zone and owner requests

5.9.9 / Build 5990 / Schema 46. Cache token `5990-r157-tegh`.
Status: **private beta candidate** (invitation-only, sample data only). `productionReady: false`.
Database: one table (`company_onboarding`) and one column (`companies.timezone`), both added on first use. Earlier versions ignore them.

## Company onboarding
**After sign-up.** A new user no longer goes straight to "create a company". The first screen is **Onboarding · Step 1 of 6**: create the company. The bar shows all six steps.

**The onboarding page.** It opens as soon as the company exists. It has a progress ring and one card per step:

| Step | Options |
|---|---|
| 1 Your company | Complete once the company exists. *Review company details* opens Company Details, including the new time zone. |
| 2 Chart of accounts | **Import** (Data Import › Chart of Accounts, opening balances optional); **Template** (Tegh's standard Canadian chart: 44 accounts plus Business Chequing and Business Credit Card); **Manual** (Chart of Accounts screen). |
| 3 Sales tax codes | **Import** (CSV file, one row per tax, with a downloadable template); **Template** (Tegh's Canadian starter codes); **Manual** (Tax Codes screen). Not registered? Mark it complete. |
| 4 Bank and card accounts | Bank Accounts screen. |
| 5 Bring in your data | Customers, vendors, products & services, unpaid customer invoices, unpaid vendor invoices, opening balances, employees. Each opens its Data Import. |
| 6 Team and invoices | User Management and Invoice Templates. |

**What each step shows.**
- Each step shows what Tegh detects, for example "Tegh sees 44 accounts", or customers, vendors and completed imports. This is only a hint.
- The owner or an admin **marks each step complete**, and can mark it not complete again.
- Every change is recorded in the audit history.

**Until all six steps are complete:**
- **every module is locked.** The sidebar shows a lock on each module. Opening a report or a module shows the onboarding page instead, with "*Profit and Loss* opens once setup is finished".
- the setup screens the steps lead to stay open: Chart of Accounts, Tax Codes, Bank Accounts, Data Import, Company Details, users, invoice templates and the related Settings screens. A bar at the bottom of these screens leads **Back to setup**.
- **every sign-in opens the onboarding page.**

**Once all six are complete:**
- Tegh unlocks and sign-in opens Home.
- Onboarding cannot be reopened. The setup screens are always in Settings.

**Settings › Getting started** has **Onboarding** and **Take the Tegh Tour**.

**Existing companies.** Companies created before R157, and books created through the API, have no onboarding and are never locked.

## Tegh Assist tour and the first bank statement
**The tour.**
- When onboarding finishes, Tegh Assist offers a tour. If it is not started then, it opens once on the first visit to Home.
- It spotlights the real menus one by one: Home and its shortcuts, Banking, Receivables, Payables, General Ledger, Reports (Profit and Loss, Balance Sheet, Trial Balance, Cash Flow…), Search and Ask Tegh, the profile menu and Settings. The guided mode leaves out the General Ledger stop.
- It ends with an animated card, **Start with your bank statement**, which opens Upload Statement.

**On Home.** An animated **Start with a bank statement import** card stays at the top until the first statement is imported, or the user chooses *Not now*.

## Company time zone
- **Where to set it:** company setup, the guided forms and Company Details all ask for the time zone. The list puts Canada first; the browser's own zone is suggested.
- **What it changes:** the company's "today" follows its time zone, on the server and in the browser. This covers default dates, report periods and the future-date check. Toronto stays the default when none is set.
- **Gate checks:** TZ-02 and TZ-03 compare a UTC+14 company with a Toronto company at the same moment.

## Quick Actions (Home shortcuts)
- **They follow the person.** A company where nothing was chosen yet uses the person's latest choice from another company, instead of the defaults. A new company created during onboarding therefore starts with the person's own shortcuts (QA-01).
- **Keyboard keys are shown.** Each shortcut shows its key, Ctrl+Alt+1 … 0 (⌃⌥ on a Mac), and the keys work on every page (QA-02).

## Appearance
- Light, Dark and System are now three icon buttons in the **profile menu**, replacing the earlier bulb toggle.
- The top-bar icons from R153 are removed. The R153 checks TH-01 and TH-02 now test the profile menu.

## Beta labels
- **Document Intake** and **Upload Statement** carry a **Beta** badge and the note "Check every field it reads… before you rely on it". On Upload Statement the note is in the Bank Statement Converter (PDF) card.
- **New vendor invoice** has a **Read this invoice from a file · Beta** section above the fields. The chosen file goes through Document Intake's own reading in the browser; its review screen then opens the vendor invoice with the fields filled in.
- The website converters (free and Pro, outside this package) are marked Beta with the same note.

## Fix found while testing
An internal page add-on (`tegh-r27.js`) read a page property before any page existed. This raised a page error when preferences loaded before the first page, for example while onboarding was being checked. It is now guarded.

## Files
- **New:**
  - `api/onboarding_r157.php`
  - `assets/tegh-r157.css`
  - `R157-CHANGES.md`
- **Server:**
  - `api/index.php`
  - `api/companies.php`
  - `api/company_profile_r153.php`
  - `api/auth.php`
  - `api/bootstrap.php`
  - `api/accounting.php`
  - `api/workspace_summary_v5610.php`
  - `api/command_centre_v5600.php`
  - `api/ai_agent.php`
- **Browser:**
  - `assets/tegh-portal-v5990.js`
  - `assets/tegh-gate-v5990.js`
  - `assets/index-BsxPiq85-v2817.js`
  - `assets/tegh-r27.js`
  - `app.html`
  - the cache-token assets
- `README.txt`, `DEPLOYMENT-NOTES.txt`, the manifests.

# Tegh India Edition: plan

Status: **plan agreed; IN-1 in progress** (decisions in section 13). It replaces the release sequence in `PHASE-0-AUDIT.md`
(that audit's facts about the code are still used here, re-counted on the R187 code).

## 1. What you asked for

1. **Edition at login:** the sign-in screen has a drop-down at the top: **Canada** or **India**. The person chooses the edition, then signs in.
2. **Separate behaviour:** everything built for India appears only in the India edition. The Canadian edition does not change.
3. **No Canadian references in India:** the India edition has nothing Canadian — no GST/HST, PST, QST, provinces, CRA, GIFI, T2125, CPP/QPP/EI, T4/ROE, Canadian payroll, CAD or `en-CA` formats.
4. **Self-setup:** in the India edition the person sets everything up during onboarding:
   - tax codes;
   - payroll allowances and deductions;
   - chart of accounts (a template is offered);
   - GST filing reports and other reports.

   Tegh does not ship fixed Indian tax rates or payroll rules. It ships the tools to define them.

## 2. Guiding decisions (recommended)

| # | Decision | Why |
|---|---|---|
| D1 | **One application and one database, with an `edition` on every company** (`CA` or `IN`), fixed when the company is created | One codebase to maintain. Accounting core, banking, reconciliation, audit, backups and security stay shared and already tested. A separate fork would double every future fix |
| D2 | **The login choice sets the session's edition.** After sign-in the person sees only companies of that edition; the server refuses any request for a company of the other edition | The two editions cannot mix, even by a typed URL or an old browser tab |
| D3 | **Canadian behaviour is the default and is not edited.** India behaviour is added beside it, selected in one place (an edition profile), never by scattered `if India` checks | The Canadian regression gate (about 1,100 checks) must pass unchanged on every India release |
| D4 | **India rules are data the user enters**, not code: tax codes, payroll components, report mappings, numbering | Matches your request, and keeps Tegh from asserting legal rates it cannot keep current |
| D5 | **Edition switch in Site & Release Settings: Off / Platform owner only / Everyone**, off by default | Installing the release changes nothing for anyone until you turn it on |
| D6 | **Existing companies stay Canadian.** A company's edition can never be changed. A wrong choice is fixed by creating a new company | No conversion of posted books between tax systems |

## 3. Sign-in and edition selection

**Screen:** at the top of the sign-in card, "Edition: [Canada ▾ | India ▾]" (flag-free text labels). The choice is remembered in the browser for the next visit and can be changed before signing in.

| Situation | What happens |
|---|---|
| Person signs in with India | Company list shows India companies only. If they have none, onboarding starts with "Create your India company" |
| Person signs in with Canada | Exactly today's behaviour; India companies are not listed |
| Same login used for both editions | Allowed (recommended): one user account, companies separated by edition. See decision Q1 |
| A link or bookmark opens a company of the other edition | Refused with "This company is in the India edition. Sign out and choose India to open it." |
| Switching edition | Sign out, choose the other edition, sign in (no in-app switch, so nothing is cached across editions) |
| Edition switch Off | The drop-down is not shown; everyone is in Canada |

**Server:** the session stores `edition`. `require_company()` adds one check: the company's edition must equal the session's. The workspace response carries the edition profile (section 4) so the browser builds menus and formats from it.

**Public website and marketing pages** stay Canadian. An India landing page and an India sign-in link (`app.html?edition=in`) can be added later (decision Q6).

## 4. The edition profile (one switch point)

The server builds one profile per company and sends it to the browser with the workspace:

| Setting | Canada (unchanged) | India |
|---|---|---|
| Currency | CAD base, other currencies allowed | INR base (fixed), other currencies allowed for foreign customers/vendors |
| Number format | `en-CA` ($1,000,000.00) | `en-IN` (₹10,00,000.00), amount in words in lakh/crore |
| Date display | Current Canadian display | DD-MM-YYYY (or DD MMM YYYY), stored ISO as today |
| Financial year | Company choice (often Dec) | April–March by default |
| Regions | Provinces and territories | States and union territories with GST state codes |
| Business types | Sole proprietor, partnership, corporation… | Proprietorship, partnership, LLP, private limited, public limited, OPC, trust, society, HUF |
| Company identifiers | Business number, GST/HST, PST, QST numbers | PAN, GSTIN (one or more), TAN, CIN/LLPIN, Udyam (optional) |
| Address | Postal code, province | PIN code (6 digits), state, district |
| Tax system | Canadian GST/HST/PST/QST engine (R137 codes) | User-defined GST tax codes (section 6) |
| Payroll | Canadian payroll | User-defined payroll (section 7) |
| Menus and reports | Today's | India list (section 8); Canadian items absent |
| Help, Assist, guided text | Canadian | India text; Canadian content not loaded |
| Statement import | Current parsers | Also DD/MM/YYYY, ₹ and Rs., UPI/NEFT/RTGS/IMPS references |

## 5. Canadian references to remove from the India edition

Counted in the R187 code (105 PHP files in `api/`, 43 `assets/tegh-*.js` files, the HTML pages): "files" is how many files mention the term, "hits" how many times.

| Area | Term | Files | Hits | What the India edition does instead |
|---|---|---|---|---|
| Currency | `'CAD'` | 39 | 114 | Company currency (INR); no CAD fallback shown |
| Formats | `en-CA` | 46 | 116 | `en-IN` from the profile |
| Sales tax | `GST/HST`, `HST` | 50 | 360 | User tax codes; labels come from the code names |
| Sales tax | `PST`, `QST` | 38 | 285 | Not present |
| Regions | province / Province | 37 | 610 | State / union territory, GST state code |
| Tax authority | `CRA` | 12 | 128 | Not present; no CRA links, research or remittance |
| Corporate tax | `GIFI`, `T2125` | 10 | 101 | Schedule III mapping (user-editable) instead |
| Payroll | CPP, QPP, EI | 11 | 78 | User-defined components (PF, ESI, PT, TDS on salary…) |
| Payroll forms | T4, ROE, TD1 | 4 | 9 | Not present; payslips and component registers |
| Wording | Canada, Canadian | 46 | 217 | India wording, or neutral |
| Address | Postal code | 4 | 6 | PIN code |

Grouped by screen:

1. **Company creation and setup:**
   - Canadian business types and province.
   - GST/HST, PST and QST registration numbers and rates.
   - The CAD-only base-currency rule (`companies.php`).
   - Canadian chart template with accounts 1100 GST/HST Recoverable, 1110 PST, 1115 QST, 2100 GST/HST Payable, 2110 PST, 2115 QST, 2310–2350 Canadian payroll liabilities, and GIFI codes.
2. **Sales and purchases:**
   - Tax drop-downs (GST/HST, PST, QST, region-based tax code choice).
   - Invoice templates showing "GST/HST No.".
   - Province on customers and vendors.
3. **Banking:**
   - GST/HST and PST "included" options when posting.
   - The sales-tax settlement and ITC-clearing option in Match and Post (accounts 1100/2100).
   - Month-first statement dates.
4. **Payroll (whole module):**
   - Payroll & Tax Center, CRA Remittance, Payroll Rates (CPP/EI/tax tables), statutory rate tables.
   - Employer levies, T4/ROE.
5. **Reports:**
   - GIFI Report, Tax Code Report (Canadian regimes), GST/HST/PST Summary.
   - CRA remittance and payroll history columns.
   - T2125 profile.
6. **Tegh Assist, agents and guided mode:**
   - The CRA research allow-list.
   - GST/HST answers, the Canadian tutorial and guides, the FAQ, the knowledge pages.
7. **Document reading (OCR, Invoice Intelligence, Document Intake):**
   - GST/HST/PST/QST label patterns, `$` amounts and Canadian date order.
8. **Emails and legal text:** Canadian references in templates and policy pages shown inside the app.

**How they are removed:** each item is either taken from the edition profile (labels, formats, regions) or switched off for India (menus, reports, payroll, Assist content). Canadian code paths stay as they are. A test opens every India screen, report, PDF and export and fails if it finds any of these terms (section 11).

## 6. India tax setup (user-defined)

**Onboarding step "Tax codes":** the person creates the codes their business uses. Tegh offers an empty list plus one optional starter: "Add common GST slabs as drafts". The drafts are editable and inactive until the person reviews and activates them. The starter takes its rates from your approved list (Q4) or is not offered at all.

**A tax code** (built on the existing R137 tables, with an India `regime` so Canadian rows are untouched):

| Field | Example |
|---|---|
| Name | GST 18% |
| Components | Intra-state: CGST 9% + SGST/UTGST 9%. Inter-state: IGST 18%. Optional cess (percentage or amount per unit) |
| Accounts | Output tax account per component (sales); input tax account per component (purchases); "not claimable" flag per component sends it to cost |
| Use | Sales, purchases or both |
| Special treatment | Taxable, exempt, nil-rated, non-GST, zero-rated (export/SEZ with or without payment), reverse charge (RCM), composition (bill of supply) |
| Report mapping | Which GSTR-1 table and GSTR-3B box it feeds (section 8). Defaults are offered from the treatment; the person can change them |
| Effective dates | From / to, so a rate change is a new code version, never an edit of posted history |

**Choosing CGST+SGST or IGST:**
- **Inputs:** the company's GSTIN state and the document's place of supply. The place of supply is defaulted from the customer's state and editable on the document; an override is recorded.
- **Same state:** intra-state components (CGST + SGST/UTGST).
- **Different state:** inter-state component (IGST).
- **No hard-coded rates:** Tegh never picks a rate itself. A line with no tax code is held until the person chooses one.

**Also user-set:**
- HSN/SAC and unit of measure on products, services and lines.
- The customer's and vendor's GSTIN and state.
- Invoice numbering per GSTIN and financial year (at most 16 characters).
- Rounding to the rupee, on or off.

## 7. India payroll setup (user-defined)

Canadian payroll is not shown. India payroll is a generic engine the person configures:

| Setting | What the person defines |
|---|---|
| Earnings | Basic, HRA, special allowance, conveyance, bonus, overtime, arrears…: fixed amount, % of another component, or entered each run; taxable or exempt flag; GL expense account |
| Deductions | PF (employee), ESI (employee), Professional Tax, TDS on salary, LWF, loan recovery, advance recovery…: fixed, % of a base with ceiling, a slab table (for Professional Tax by state) or entered each run; GL liability account |
| Employer contributions | PF (employer, including pension split if wanted), ESI (employer), gratuity provision, LWF (employer): rules as above; expense and liability accounts |
| Salary structures | A set of components with amounts or percentages, assigned to employees |
| Employees | Name, PAN, UAN, ESI number, bank account (masked), state, join/leave dates, structure |
| Pay runs | Monthly by default; draft → review → approve (second person above a limit, as today) → post one journal entry → mark paid |
| TDS on salary | Entered or calculated from a slab table the person enters for the year and regime; Tegh shows the working but does not supply the slabs |

**Outputs:**
- payslips (PDF);
- pay run register;
- component registers (PF, ESI, PT, TDS, LWF) for the person to file;
- liability ledgers;
- journal entries.

Tegh does not file with EPFO, ESIC or state portals.

## 8. Reports in the India edition

| Report | Notes |
|---|---|
| Profit and Loss, Balance Sheet | Existing engine; an optional **Schedule III** layout from an account-group mapping (replaces GIFI); the template chart comes pre-mapped and the person can change it |
| Trial Balance, General Ledger, Day Book, Cash Book, Bank Book, Journal Register | Existing, with India formats |
| Receivable/Payable ageing, customer/vendor ledgers | Existing |
| **GSTR-1 working report** | B2B, B2C (large/small), credit/debit notes, exports, nil/exempt/non-GST, HSN summary, document summary, built from posted documents and the tax-code mapping; Excel and JSON-shaped export for upload by the person |
| **GSTR-3B working report** | Outward supplies, RCM, eligible and ineligible ITC, interest and fee fields entered by the person; ties to the GST ledger balances |
| Purchase register and ITC register | By vendor GSTIN, invoice, tax code, claimable or not |
| GSTR-2B reconciliation | The person imports their GSTR-2B (Excel/JSON); Tegh lists matched, missing in books, missing in 2B, GSTIN/amount differences |
| GST ledger summary | Output tax, input tax and net payable per component and period, with a "payment made" entry |
| TDS/TCS on vendors | Sections and rates the person defines; deduction register and payable by section |
| Payroll reports | As in section 7 |
| Bank reconciliation, Report Builder | Existing |

**Filing status:**
- **Statuses:** a return period moves draft → prepared → reviewed → filed. "Filed" needs the person to enter the ARN and date from the portal.
- **No portal connection:** Tegh never claims to have filed.
- **Later option:** direct filing or e-invoice/e-way bill through an authorised provider (GSP/IRP), only with your provider account (Q5).

## 9. Onboarding (India)

A step-by-step setup the person can leave and resume. Each step can be skipped and finished later from Settings.

1. **Company:**
   - legal and trade name, business type, PAN;
   - address with state and PIN;
   - financial year start (April);
   - books start date.
2. **GST registrations:**
   - one or more GSTINs, each with state, address and registration type (regular or composition);
   - "Not registered" is allowed.
3. **Chart of accounts:**
   - **Templates:** Indian template by business type (trading, services, manufacturing) with Schedule III groups, or a blank chart.
   - **Editing:** every account can be renamed, added or deactivated.
   - **Tax accounts:** the template contains only the GST input/output and TDS/PF/ESI payable accounts as placeholders that the tax and payroll steps use.
4. **Tax codes** (section 6).
5. **Opening balances:** trial balance, open customer/vendor invoices (existing tools).
6. **Bank accounts and first statement import.**
7. **Payroll** (optional; section 7).
8. **Numbering and invoice template:**
   - invoice series per GSTIN and year;
   - logo;
   - bank details on the invoice;
   - terms.
9. **Report mappings:**
   - Schedule III groups (pre-filled from the template);
   - GSTR mapping (pre-filled from tax-code treatments);
   - TDS sections.

A setup checklist on the India dashboard shows what is left (for example "No tax code yet: invoices cannot be issued").

## 10. Data changes (additive only)

- **`companies`:**
  - `edition` (`CA` default for every existing row; set once at creation);
  - `edition_locked_at`.
- **Session:** `edition`. The release setting `indiaEdition` is Off / Owner / Everyone.
- **New India tables**, each keyed by `company_id`:
  - India company profile (PAN, TAN, CIN, constitution, district, PIN);
  - GST registrations;
  - tax-code India fields (regime, components by supply scope, treatment, report mapping, effective dates);
  - HSN/SAC and UoM on items and lines;
  - place of supply and supply type on documents;
  - payroll component definitions, salary structures, employees (India fields), pay runs and lines;
  - report mappings (Schedule III, GSTR, TDS sections);
  - return periods (status, ARN);
  - GSTR-2B imports.
- **Schema version:** 47, by migration (no first-use table creation).
- **Untouched:** no Canadian column, table or row is changed.
- **Rollback:** the new tables and columns can be dropped while no India company exists.

## 11. Testing (every India release)

1. **Canadian gate unchanged:** the full existing gate passes, and the Canadian before/after comparison shows no difference.
2. **Edition isolation:**
   - an India login cannot list, open or call any Canadian company (and the reverse);
   - with the switch Off there is no India option;
   - company edition cannot be changed.
3. **No Canadian text in India:**
   - a sweep opens every India menu, screen, report, PDF and download;
   - it fails on any term in the section 5 list, on `$`, and on `CAD`.
4. **Accounting:**
   - India invoices, bills, notes, receipts and payments balance;
   - CGST+SGST versus IGST follows place of supply;
   - RCM, exempt, zero-rated and composition post as defined;
   - GST reports equal the GST ledger accounts;
   - trial balance ties to P&L and Balance Sheet.

   Every expected figure is worked out by hand in the test, never read from Tegh.
5. **Payroll:**
   - user-defined components compute as defined (percent, ceiling, slab);
   - a pay run posts one balanced entry;
   - the registers equal the liability accounts.
6. **Formats:** ₹ and lakh/crore in screens, PDF and Excel; DD-MM-YYYY; amount in words.
7. **Phones and zoom:** India screens at 390 px and 1440 px.

## 12. Release sequence (proposal)

| Release | Content |
|---|---|
| IN-1 | Edition switch, edition at sign-in, `edition` on companies and session, server isolation, India company creation (INR, April–March, states, PAN/GSTIN fields, en-IN and DD-MM-YYYY formats), India menus with every Canadian item removed, isolation and "no Canadian text" suites |
| IN-2 | India chart-of-accounts templates and Schedule III mapping; onboarding wizard steps 1–3 and 5–6; Indian statement import formats |
| IN-3 | User-defined GST tax codes, place of supply, HSN/SAC; India invoice, bill of supply, credit/debit note PDFs; numbering per GSTIN and year |
| IN-4 | GST reports: GSTR-1 and GSTR-3B working reports, purchase/ITC register, GST ledger summary, return status; GSTR-2B import and reconciliation |
| IN-5 | User-defined payroll (components, structures, pay runs, payslips, registers) |
| IN-6 | Vendor TDS/TCS (user-defined sections), India dashboard and setup checklist, India help, Assist and guided text |
| IN-7 | Optional: e-invoice and e-way bill through an authorised provider (needs your provider account) |

Each release:
- runs the full Canadian gate and the India suites on the test server;
- has a RESULTS.md and a package.

Nothing goes to IONOS without your separate approval.

## 13. Decisions (agreed)

All seven recommendations below were accepted. The provider account for e-invoice, e-way bill and filing (Q5) will be supplied later; IN-7 waits for it.

### The questions as asked

| # | Question | Recommendation |
|---|---|---|
| Q1 | Can one login use both editions (Canadian and Indian companies), or must each edition have separate logins? | One login, companies separated by edition |
| Q2 | Same server and database for both, or a separate India installation (for example for data held in India)? | Same installation for now; revisit before Indian customers' live data |
| Q3 | Should the India edition be visible to everyone at sign-in, or only to invited users at first? | Switch "Platform owner only" until IN-3 is tested |
| Q4 | Should Tegh offer starter GST slabs as editable drafts, or leave tax codes completely empty? | Offer drafts only from a list you approve; otherwise empty |
| Q5 | Is direct e-invoice / e-way bill / GST filing through a provider wanted later? | Later (IN-7), only with your provider account |
| Q6 | Separate India website pages and pricing, or only the sign-in drop-down? | Sign-in drop-down first |
| Q7 | Languages: English only, or Hindi as well? | English first |

## 14. What is not changing

- Canadian edition screens, reports, payroll, tax and data.
- The accounting core (double entry, approvals, period locks, audit, backups), banking and reconciliation, which the India edition reuses.
- The earlier constraint "Dont make it for india" is lifted for this work by your request, and applies only inside the India edition.

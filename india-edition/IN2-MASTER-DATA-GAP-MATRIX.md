# India master data: inventory and gap matrix (IN-2)

Baseline R187 + IN-1 (Tegh 5.9.9 / Build 5990 / Schema 46). Inspected: `tegh_gate` schema (188 tables) and the master-data APIs.

**Rule used for every gap:** extend the existing master. Where an existing table carries a Canadian meaning (`accounts.gifi_code`, `payroll_employees.sin_*`), India values go beside it and the Canadian column is left alone.

## Existing structures reused

| Existing | Used for |
|---|---|
| `companies` (name, legal name, country, currency, `accounting_basis`, `fiscal_year_end`, books start, address) | Company identity, financial year, accounting method. The assessment year is derived from the financial year (not stored) |
| `company_india_profile` (IN-1: constitution, PAN, primary GSTIN, TAN, CIN/LLPIN, district, PIN) | Company registrations |
| `customers`, `vendors` (contact, single address, country, province/state, terms, currency) | Party master; the primary address stays here |
| `products_services` (kind goods/service, code, price, income account) | Item master |
| `accounts` (code, type, control flag, reporting group) | Chart of accounts; India mappings go beside it, not into `gifi_code` / `t2125_line` |
| `bank_accounts` (ledger mapping, account type, masked number, currency, `profile_json`) | Bank master and ledger mapping; multiple bank accounts already supported |
| `fixed_assets` (acquisition date, in-service date = put-to-use date, cost, accounting depreciation method/life, GL accounts) | Asset master and accounting depreciation |
| `company_currencies` + R181 dated exchange rates | Currency and exchange-rate configuration (complete) |
| `tax_codes` + `tax_code_components` | GST tax codes (user-defined CGST/SGST/IGST/cess components) — IN-3 |
| `statutory_rates` (jurisdiction, category, key, effective from/to, rate, amount, thresholds, metadata, source, draft/active/superseded, approver; backed up) | **All India statutory rule masters**, with jurisdiction `IN` or `IN-<state>` |
| `audit_log` / `audit_event()` | Audit of every master-data change |

## Matrix

**Implementation:**
- **P** — India profile: one versioned row per record and effective date, in `india_entity_profiles`.
- **R** — India records: multiple rows, effective-dated, in `india_master_records`.
- **S** — a statutory rule category in `statutory_rates`.

| Area | Existing | Partial | Missing | Action |
|---|---|---|---|---|
| A Company | Constitution, PAN, TAN, CIN/LLPIN, primary GSTIN, financial year, accounting method | Assessment year derivable; one GSTIN only | Business activity, IEC, Udyam number and MSME category, income-tax regime, tax-audit applicability, accounting standard, multiple GST registrations with validity/cancellation dates | Company profile **P**; GST registrations **R** `gst_registration` |
| B Customer / vendor | Name, single address, state, country, terms, currency | — | PAN, GSTIN with type/status/dates (several), extra addresses, MSME details and payment terms, TDS/TCS section, lower-deduction certificates, residency, related party, foreign tax ID | Party profile **P**; **R** `gst_registration`, `address`, `tds_certificate` |
| C GIFI replacement | `accounts.reporting_group`, control flag | — | Schedule III line, ITR schedule/line, Form 3CD clause, GST treatment, ITC class, TDS/TCS, s.40/40A/43B/14A/269SS-ST-T flags, depreciation block, reconciliation flag | Account mapping **P** (references **S** lists) |
| D Products / services | Kind, code, price, income account | Taxable flag (Canadian sense) | HSN/SAC, UQC, GST rate and cess rule, exemption class, RCM, ITC class, capital goods, income-tax / tax-audit mapping, import/export, valuation method, batch/serial/expiry tracking, default warehouse | Item profile **P** |
| E Fixed assets | Acquisition and put-to-use dates, accounting depreciation | — | Income-tax block, additional-depreciation eligibility, ITC/subsidy cost adjustments | Asset profile **P**. Under/over 180 days is derived from the put-to-use date. Disposals stay transactions (not master data) |
| F Employees / payroll | Canadian payroll employees (SIN, TD1, CPP/EI) — not usable for India | — | India employee master (PAN, regime, UAN, ESIC, PT/LWF state, gratuity, leave encashment), pay components, Chapter VI-A declarations | **R** `employee`, `pay_component`, `chapter_via_declaration`. The Canadian payroll table is untouched |
| G Banking | Multiple accounts, ledger mapping, currency, FX | Institution in `profile_json` | IFSC, branch, holder, Indian account type, SWIFT, UPI ID; payment-method classification (cash vs banking channel) | Bank profile **P**; **R** `payment_method` |
| H Inventory / operations | None | — | Warehouses, locations, transporters, vehicles, ports, job workers; batch/serial flags (on items) | **R** `warehouse`, `location`, `transporter`, `vehicle`, `port`, `job_worker` |
| I Statutory rules | Rule table with versions and approval (Canadian payroll use) | — | GST rates, cess, HSN/SAC, RCM, ITC, TDS, TCS, income-tax slabs/surcharge/rebate/regimes, exemptions, Chapter VI-A sections, depreciation blocks, MSME rules, 43B, 40A, ITR and 3CD maps, Schedule III lines, due dates, accounting standards, PT/LWF/EPF/ESIC | **S** categories in `statutory_rates`. No rates are seeded; every value is entered by the user with source and effective dates |
| J Additional | Currencies and FX | — | Related-party relationships, loan/deposit classification, income classification, compliance calendar | **S** categories plus **R** `loan_deposit`; the compliance calendar is the `due_date` rule category |

## Not stored in masters (kept in transactions)

- Invoice and payment dates, tax amounts, TDS deducted, ITC claimed, statutory payments, disposal proceeds and dates.

## Implementation (IN-2)

### Server: `api/india_masters_in2.php` (new; loaded only for `india/masters/*` routes)

- Every route answers **404 `india_only`** unless the company's edition is IN. A Canadian company cannot read or write India masters.
- **Field specification:** the server holds one specification for each of 7 profile types and 14 record types. The same specification validates input and is sent to the browser to draw the forms.
  - **Unknown fields are refused**, so data can never land in a Canadian column such as `gifi_code`.
- **Format checks:**
  - PAN; GSTIN (including the check character, through the IN-1 function); IFSC; HSN/SAC (4, 6 or 8 digits); UQC; Udyam; IEC; PIN; UAN; SWIFT; UPI ID; vehicle; port; financial year.
  - The PAN inside a GSTIN must match the PAN on the same record. A GST registration of the company must carry the company PAN, and a cancelled registration needs its cancellation date.
- **References:**
  - `rule:<category>` must be a non-superseded rule of this company.
  - `record:<type>` must be an active record of this company.
  - `entity:<type>` must be a customer, vendor, item, account, asset or bank account of this company.
- **Versions are kept.**
  - **Profiles:** each effective date is a new version. Saving again on the same date corrects that version, and the audit log keeps the old values.
  - **Records:** a change with a later date supersedes the old row. The old row gets `effective_to` = the day before and status `superseded`, and the new row points to it through `replaces_id`. A change of an already-superseded row is refused (409).
  - **Make inactive:** sets `effective_to` and status `inactive`. Nothing is deleted.
- **Statutory rules** are stored in the existing `statutory_rates` table:
  - jurisdiction is `IN` or `IN-<state>`, and the category goes in `person_type`;
  - each rule needs a source, and can carry a source link (https only) and notes;
  - every rule starts as a draft. Only the Company Owner or an Admin can approve it; approval records `approved_by` and ends the earlier active version the day before;
  - adding a second version with the same date is refused (409);
  - **Tegh supplies no rates, sections, thresholds or due dates.**
- **Roles:**
  - Editor or above may write item, customer, vendor, asset and bank profiles, and records.
  - Only the Owner or an Admin may change the company profile, account mappings and rules.
  - Viewers can read only.
- **Audit:** every write records an `india.*` entry with the before and after values.

### Database (first-use DDL; Schema stays 46; no column of an existing table is changed)

- `india_entity_profiles` (id, company_id FK cascade, entity_type, entity_id, effective_from, data_json, created_by, created_at). Unique on (company, entity type, entity, effective date).
- `india_master_records` (id, company_id FK cascade, record_type, entity_type, entity_id, data_json, effective_from, effective_to, status active/inactive/superseded, replaces_id, created_by, timestamps).
- **Backups:**
  - include `company_india_profile`, `india_entity_profiles` and `india_master_records` when those tables exist;
  - a restore of a backup that holds them creates the tables first.

### Browser

- **Settings › Company details › India Masters.** It appears only in an India company; a Canadian company has no menu item, and opening it directly refuses.
- **Profiles tab:** pick a record type and a record, set the effective date and edit the fields. A list of earlier versions is shown.
- **Records tab:** list the records, then Add, Change (as a new dated version) or Make inactive.
- **Statutory rules tab:** filter by category, see the source and status of each rule, add a draft, and approve it (Owner or Admin).
- Amounts are entered in rupees and stored in paise.

### Remaining gaps (not in IN-2)

- **Not yet used by transactions.** The masters are recorded but no transaction uses them yet:
  - GST tax codes and invoice/bill tax lines: IN-3;
  - TDS/TCS deduction on bills and receipts, and the ITC split: IN-3/IN-4;
  - Indian payroll calculation from the employee, pay-component and rule masters: IN-5;
  - Schedule III, ITR and Form 3CD reports from the account mappings: IN-6.
- **Statutory content:** no rates, sections, thresholds or due dates are supplied. The user enters them with their source.
- **No government services:** there are no GSTIN, PAN, e-invoice or e-way bill lookups or filing formats.
- **Not in this release:**
  - bulk import of masters;
  - inventory quantities by warehouse, batch or serial number. Only the master records and the tracking flags exist.

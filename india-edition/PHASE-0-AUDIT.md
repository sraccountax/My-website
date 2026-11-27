# Tegh India Edition — Phase 0: repository audit and plan

**Baseline inspected:** R180 (Tegh 5.9.9, build 5990, schema 46), branch `claude/invoice-credit-debit-audit-e2y8wc`. I
read the source and checked the database actually installed on the gate test host (`tegh_gate`, 188 tables). No code
was changed in Phase 0.

**Legal facts used for planning.** These come from secondary sources and must be confirmed against the official texts
(CBIC, CBDT, GSTN, MeitY) before any rule is approved in Tegh:

- **GST rates:** restructured from 22 September 2025 into 5%, 18% and 40% slabs, with nil and exempt lists, under
  Notification 9/2025-Central Tax (Rate) and related notifications. Later amendments are reported and must be checked.
- **Income tax:** the Income-tax Act, 2025 and the Income-tax Rules, 2026 are in force from 1 April 2026, with
  renumbered sections (for example, salary TDS moves from section 192 to section 392). The old Act still applies to
  credits or payments made on or before 31 March 2026.
- **E-invoicing:** required above ₹5 crore AATO; the 30-day reporting limit applies at ₹10 crore AATO and above.
- **Data protection:** DPDP Rules 2025 notified on 13–14 November 2025. Most obligations apply 18 months later
  (about May 2027).

---

## A. Existing architecture

| Layer | What exists | Notes for India |
|---|---|---|
| Server | 102 PHP files in `api/`, one router (`api/index.php`, about 130 routes), PDO/MariaDB, `db_transaction_retry` | Monolithic handlers (`accounting.php` 187 KB, `operations.php` 202 KB). There is no module layer yet, so a localisation service must be added beside them |
| Browser | `assets/tegh-portal-v5990.js` (2 MB, one IIFE) plus about 48 feature scripts | Menus are a static object (`'Receivables'`, `'Payroll'`, `'General ledger'` … around line 1140). A central `money()` exists (line 12) but is hard-wired to `en-CA` |
| Accounting core | Double entry in `journal_entries` / `journal_lines` (integer cents), `period_locks`, `vouchers` with `voucher_sequences`, `document_sequences`, approvals, voids, audit log | Fully reusable. Paise use the same 2-decimal integers |
| Tax | **R137 tax-code engine**: `tax_codes`, `tax_code_components` (rate in milli-percent, sales/purchase account, recoverable flag), `document_tax_lines` (per-document, per-component breakdown), a per-line `tax_code_id` on invoice and bill lines, inclusive/exclusive calculation with largest-remainder rounding | A good base for CGST + SGST / IGST + cess. **Mismatch:** a code is chosen by *region*, with one active code per company and region (`UNIQUE(company_id, active_region)`). India chooses the rate by item (HSN/SAC) and the split by place of supply |
| Location | **R141** `companies.country` (English name, default `Canada`), `province` varchar(10); `tegh_country_code()`; customers and vendors have country/province | A country already exists per company, so there is no need for a second column. Non-Canadian companies are already allowed, as accounting-only |
| Currency | `companies.currency`, `company_currencies` (rate to base, entered or imported per company); `official_rate_releases` records official rate releases (for example payroll rates) | **Blocker:** `companies.php:115` and `:282` refuse any base currency other than CAD. Most code reads `company.currency`, with `'CAD'` only as a fallback (8 PHP files, about 90 JS places) |
| Fiscal year | `fiscal_year_end` (MM-DD) plus `fiscal_year_end_date`; `03-31` is already valid | April–March needs only a country default |
| Payroll | Canadian only (CPP/QPP, EI, CRA remittances, `statutory_rates`, `employer_levy_*`); already blocked when country ≠ Canada or currency ≠ CAD | Indian payroll must be a separate module |
| Reports | P&L, Balance Sheet, Trial Balance, Cash Flow, GL, Day Book, ageing, `tax-summary`, GIFI and T2125 profiles, `report_loaders_v5980.php`, `report_output_v5980.php` | The P&L/BS engine is reusable; Schedule III needs an account mapping like GIFI (`accounts.gifi_code`) |
| Documents | Invoices, bills, credit/debit notes, `invoice_templates` (5 presets), PDF writer `tegh-pdf-v5990.js` (core fonts with WinAnsi encoding plus Unicode subsets from system fonts) | ₹ (U+20B9) is outside WinAnsi and must be tested. Amount in words needs lakh/crore |
| Banking | CSV/XLSX/PDF statement import (`imports.php`, `data_imports.php`, `pdfStatementImport`), matching, reconciliation | **Date bug for India:** `parse_statement_date` tries `m/d/Y` and has no `d/m/Y`. The amount parser handles `1,00,000.00` but rejects `₹` |
| Inventory | None. `products_services` has price, taxable flag and income account only | Inventory is entirely new work (Phase 4) |
| AI / agents | Native agents, Tegh Assist, research allow-list of Canadian government sites (`ai_research.php:73`) | Must be country-aware; never posts directly (already the rule) |
| Schema changes | `SR_ACCOUNTAX_SCHEMA_VERSION = 46`; `migrations.php`, plus features that add tables on first use (`tegh_*_ready()` creating tables outside transactions; ALTER privilege needed) | The India tables follow the migration path with a schema bump to 47, not first-use DDL |
| Tests | 78 suites on the gate host (about 1,000 checks), upgrade test from R118, screen matrix | The Canadian regression baseline already exists |

## B. Canadian dependencies (counted in `api/*.php` and `assets/tegh-*.js`)

| Term | Files | Hits | Most in |
|---|---|---|---|
| `'CAD'` | 36 | 212 | portal (103), professional output, forecast |
| `GST/HST` | 35 | 218 | portal (65), accounting.php, guided_r163.php |
| `HST` | 38 | 252 | portal, accounting.php, tax_codes_r137.php |
| `PST` / `QST` | 34 | 256 | portal, accounting.php, invoice intelligence |
| `CRA` | 12 | 128 | portal, payroll_rate_updates, payroll |
| `GIFI` | 9 | 95 | portal, operations.php |
| `en-CA` | 22 | 85 | portal, registers, dashboard |
| `CPP` / `QPP` / `EI` | 11 | 66 | payroll, migrations, report loaders |
| province `'ON'`, `TEGH_CANADA_PROVINCES` | 14 | 36 | portal, migrations, regions |

Hard Canadian rules, which must become country rules rather than be deleted:

1. **CAD-only base currency:** `companies.php:115`, `:282`; `payroll.php:1182` (correct for Canadian payroll).
2. **Company tax columns:** `tax_rate_bps` (default 1300), `pst_*`, `tax_reporting_profile` (`gifi`/`t2125`) and
   `business_type` (4 Canadian types) on `companies`.
3. **Tax code choice by province:** `tax_code_auto_region`, `tegh_tax_region_candidates`, `tax_codes_seed_canada`.
4. **Statement dates:** `imports.php:197` reads month first.
5. **Menus:** CRA Remittance, GIFI Report, Payroll & Tax Center and the GST/HST labels are shown to every company.
6. **Formatting:** `money()` and `formatCanadianAmount` use `en-CA`. Dates are mostly ISO; display formatting is
   scattered.
7. **AI research allow-list** and the Assist wording mention CRA and GST/HST.
8. **Invoice Intelligence and OCR** look for GST/HST/PST labels and `$` amounts.

## C. Proposed India localisation architecture

**One application, a localisation layer and one accounting core.**

```
api/localisation/
  locale_r181.php            CountryConfig service: tegh_locale(array $company): array
  ca.php                     Canada: today's behaviour, unchanged
  in.php                     India: INR, en-IN, DD-MM-YYYY, April–March, GST, modules
api/india/
  gstin.php                  PAN/GSTIN/TAN/CIN validation (format, state code, checksum)
  gst_registrations.php      registrations per legal entity (GSTIN, state, address)
  gst_rules.php              effective-dated rate rules (HSN/SAC → rate, cess, conditions, source, approval)
  gst_engine.php             place of supply → CGST+SGST/UTGST or IGST; RCM; exempt/nil/zero-rated; composition
  gst_returns.php            GSTR-1 / GSTR-3B preparation, GSTR-2B import and reconciliation (no filing)
  gst_provider.php           interface for authorised GSP/IRP/EWB providers; sandbox | production; no simulated success
  tds.php                    effective-dated TDS/TCS rules (Income-tax Act 2025 sections), deductions, challans
  schedule3.php              Schedule III mapping for P&L/BS
assets/tegh-locale-r181.js   TeghLocale: money, number, date, words (lakh/crore), menu filter
assets/tegh-india-*.js       GST Compliance Centre, registrations, India invoice fields
```

**CountryConfig** (returned by `tegh_locale()` and sent to the browser with the workspace):
`countryCode, currencyCode, currencySymbol, numberLocale (en-CA | en-IN), dateFormat (YYYY-MM-DD | DD-MM-YYYY),
fiscalYearDefault (12-31 | 03-31), taxSystem (ca_gst_hst | in_gst), statementDateOrder (mdy | dmy),
enabledModules, hiddenMenuItems, reportProfiles, businessTypes`.

Rules:

- `ca.php` returns exactly today's values. Every Canadian path calls the same functions as now, so the Canadian
  regression suite must pass unchanged.
- Code asks `tegh_locale($company)['taxSystem']` once, at the service boundary (tax engine selection, menu filter,
  report list). It does not scatter `if India` checks through unrelated files.
- **Country is fixed after the first posted entry.** Changing it on a company with postings is refused. A wrong country
  is fixed by creating a new company and importing, never by converting data.
- **Release switch** "India edition: off / platform owner only / everyone", in Site & Release Settings (the same
  pattern as the R177 New dashboard switch). Off by default, so installing changes nothing for anyone.

**GST engine design.** It reuses `document_tax_lines` and the journal posting, and adds India-specific selection:

1. Supplier registration: the GSTIN on the document, from `company_gst_registrations`.
2. Place of supply: an explicit field on the document, defaulted from the customer's state (goods/services rules,
   with the user able to override and the override recorded).
3. Intra-state gives CGST + SGST (or UTGST); inter-state gives IGST. This is decided per document, never from
   "different registrations".
4. Rate: from the line's HSN/SAC through `india_gst_rate_rules`, effective on the document date and **approved**. A
   line with no approved rule is held for review; it is never guessed.
5. Specials: RCM (tax posted to liability and ITC with the RCM flag), exempt/nil/non-GST/zero-rated (LUT or IGST),
   composition (bill of supply, no tax collected), cess components.
6. Rounding per line, then per component, using the existing allocation; document-level rounding to a configurable
   rupee.

India tax codes live in the existing tables with `region = NULL` and a new `regime = 'in_gst'` column, so the
Canadian unique-region rule and resolver are untouched.

## D. Database migration plan

All changes are additive. There are no drops, renames or rewrites of existing rows. Migration
`migration_schema47_india_r181.php` brings the schema to 47; later phases add 48, 49 and so on only when they need
tables.

| Phase | Change | Rollback |
|---|---|---|
| 1 | `companies`: add `country_locked_at DATETIME NULL`. Existing `country` is reused; all existing rows stay `Canada`. `platform_release_settings` row `indiaEdition = off` | Drop the column if no India company exists |
| 2 | `company_gst_registrations` (company_id, gstin UNIQUE, state_code, legal/trade name, address, type regular/composition/casual/SEZ, effective dates, status). `companies`: India profile columns (pan, tan, cin, constitution, trade_name, district, pin) as NULL-able columns or a `company_india_profile` table (preferred, so Canadian rows are untouched). `products_services`: `hsn_sac`, `uom`, `gst_rule_id` NULL. `customers`/`vendors`: `gstin`, `state_code`, `pan` NULL. `invoices`/`bills`/notes: `gst_registration_id`, `place_of_supply`, `supply_type`, `reverse_charge` NULL. Lines: `hsn_sac`, `uom`, `cess_cents` NULL. `tax_codes`: `regime` default `ca` | Drop new tables and columns only when they hold no India data (checked by the script) |
| 3 | `india_gst_rate_rules` (hsn_sac, description, rate_mpct, cess, condition_json, effective_from/to, source_ref, reviewed_at, approval_status, approved_by), `india_gst_return_periods` (status draft→prepared→reviewed→approved→submitted→filed→paid, with who and when), `india_gstr2b_imports` and lines, `india_reconciliation_items`, `india_einvoice_records`, `india_ewaybill_records`, `india_tds_rules`, `india_tds_entries`, `india_provider_credentials` (encrypted with the existing app secret; never shown) | As above |
| 4 | Inventory (items, warehouses, movements, valuation layers), India payroll tables | As above |

- **Invoice numbering (CGST Rule 46):** at most 16 characters, unique per financial year and GSTIN. This uses a new
  `document_sequences` scope `(company, type, gst_registration, fy)`. The existing Canadian sequence is untouched.
- **Isolation:** every new table has `company_id` with an FK to `companies`, and every query is filtered by the
  company from `require_company()` (the existing pattern).
- **IONOS:** the migration needs CREATE/ALTER, which the existing schema 41–46 migrations already required. Database
  privileges are not removed, per the standing constraint.

## E. File-by-file plan (Phases 1–2)

| File | Change |
|---|---|
| `api/localisation/locale_r181.php`, `ca.php`, `in.php` | New: CountryConfig service |
| `api/companies.php` | Base currency and fiscal defaults come from the locale (INR/03-31 for India; the CAD rule kept for Canada); India business constitutions; India profile fields; country lock after first posting; `payroll_canada_only` unchanged |
| `api/regions_r141.php` | Indian states and union territories with GST state codes (official list), validation for IN |
| `api/migration_schema47_india_r181.php`, `api/migrations.php`, `api/bootstrap.php` | Schema 47 migration and version bump |
| `api/imports.php` | `parse_statement_date` takes the order from the locale (`d/m/Y` for India), accepts `₹`/`Rs.`, and flags ambiguous dates instead of guessing; UPI/NEFT/RTGS/IMPS reference extraction |
| `api/tax_codes_r137.php` | `regime` column; `tax_code_auto_region` delegates to `api/india/gst_engine.php` only when `taxSystem = in_gst` |
| `api/india/*.php` | New (section C) |
| `api/index.php` | Routes `india/*`, guarded by the release switch and company country |
| `api/portal.php`, workspace bootstrap | Include `locale` in the workspace payload |
| `api/report_loaders_v5980.php`, `report_output_v5980.php` | Schedule III layout option; GST reports; India date and number format in exports |
| `api/site_settings_r174.php` | `indiaEdition` switch |
| `assets/tegh-locale-r181.js` | New: money/number/date/amount-in-words by locale |
| `assets/tegh-portal-v5990.js` | `money()` and `formatCanadianAmount` delegate to TeghLocale; the menu object is filtered by `locale.hiddenMenuItems` and India menus are added; company create/edit form shows India fields when India is chosen |
| `assets/tegh-pdf-v5990.js`, `tegh-professional-output-v5990.js` | ₹ rendering (tested), GST invoice layouts (tax invoice, bill of supply, credit/debit note), state code, HSN/SAC, CGST/SGST/IGST columns, amount in words |
| `assets/tegh-gate-v5990.js`, `app.html` | Feature registration, cache token |

Phases 3–5 (GST Compliance Centre, e-invoice, TDS, inventory, payroll, AI checks) get their own file plans when each
phase starts, because they depend on decisions made in Phases 1–2.

## F. Risks and compatibility issues

| # | Risk | Mitigation |
|---|---|---|
| 1 | **CAD as base is assumed in many places** (212 `'CAD'` hits) and could display INR books as CAD | Change the fallbacks to `company.currency`; a test opens every report and export for an INR company and fails on any "CAD" or "$" |
| 2 | The **region-unique tax code** model breaks if India codes are stored like Canadian ones | `regime` column and a separate resolver; Canadian rows unchanged |
| 3 | **Wrong GST law or rates** | No rate is hard-coded. Rules are data, effective-dated, with source and approval; unapproved means held for review. Seed data needs your approval or an accountant's |
| 4 | **Statement dates misread** (03/04 as 4 March) | The order comes from the country; ambiguous files ask the user; tests with Indian bank formats |
| 5 | **₹ in PDFs** depends on system fonts (WinAnsi has no ₹) | Test on Windows, macOS, Android and iOS; fall back to "Rs." if the glyph is missing |
| 6 | **E-invoice/EWB/GST filing need an authorised GSP/IRP account**, which this environment does not have | The provider interface is built and tested against a provider sandbox only when you supply sandbox credentials. It is never simulated |
| 7 | **Income-tax Act 2025 transition** (old Act for credits/payments up to 31 March 2026) | TDS rules carry an `act` field and dates; both can apply in FY 2025-26 adjustments |
| 8 | **Scope:** Schedule III, Ind AS, inventory, payroll, PF/ESI/PT per state is many months of work | Phased releases, each with a full gate run; nothing is marked production-ready without verified rules |
| 9 | **Canadian regressions** from shared files (portal, companies, imports) | Every phase runs the full Canadian gate (about 1,000 checks) plus new cross-country isolation suites |
| 10 | **DPDP** (most duties from about May 2027): PAN, GSTIN, payroll and bank data | Same access controls; mask PAN/GSTIN in lists for roles without the right; retention and deletion through the existing lifecycle; review before an India launch |
| 11 | **Data residency and IONOS hosting** for Indian customers | No technical blocker; a business and legal decision for you before launch |

## G. Proposed test cases (new suites, run with the full gate)

- **Localisation and isolation (Phase 1):**
  - Create an India company: INR base, April–March year, `en-IN` money (`₹1,00,000.00`), DD-MM-YYYY on screen and in
    exports.
  - A Canadian company on the same install keeps CAD, `en-CA`, its GST/HST menus and payroll.
  - The India company hides CRA/GIFI/T2125/Canadian payroll.
  - Changing one company's settings changes no row of the other company (row checksum before and after).
  - Country is locked after the first posting.
  - Switch off means no India option anywhere.
- **GSTIN/PAN validation:** valid and invalid checksum, state code mismatch, PAN embedded in GSTIN, an unregistered
  business with no GSTIN.
- **GST calculations** (each against a hand-worked expected value, never Tegh's own output):
  - Intra-state 18% (9 + 9) and inter-state 18% IGST.
  - Two registrations in one company (Maharashtra → Karnataka is IGST, Maharashtra → Maharashtra is CGST+SGST).
  - Union-territory UTGST.
  - Tax-inclusive pricing; rounding.
  - RCM purchase (liability and ITC both posted).
  - Exempt, nil, non-GST and zero-rated (LUT).
  - Composition bill of supply (no tax).
  - Cess.
  - Credit note and debit note reversing the original split.
  - A rule effective-date change on a boundary date.
  - An unapproved rule holds the line.
- **Documents:** tax invoice fields per Rule 46, a 16-character FY-unique number per GSTIN, amount in words
  (lakh/crore), and ₹ rendering in PDF.
- **Banking:** HDFC/ICICI/SBI-style CSV/XLSX layouts (synthetic), `dd/mm/yyyy`, `₹` and `Rs.`, UPI/NEFT/RTGS/IMPS
  references, an ambiguous-date prompt, and no uncertain match posted automatically.
- **Integrity:**
  - Debits equal credits for every India document.
  - GST control accounts reconcile to the GST reports.
  - Trial balance ties to P&L/BS (Schedule III).
  - Period lock enforced; duplicate posting refused (idempotency keys).
- **Returns (Phase 3):**
  - GSTR-1/3B figures equal ledger totals.
  - A synthetic GSTR-2B import reconciles: missing invoice, duplicate, GSTIN mismatch, value difference.
  - Status never reaches "Filed" without a recorded provider or portal acknowledgement.
- **Canadian regression:** the full existing gate (about 1,000 checks) and the upgrade from R118, on every release.

## H. Implementation sequence

| Release | Phase | Content | Schema |
|---|---|---|---|
| R181 | 1 | CountryConfig service; India option behind the release switch; INR base; en-IN money and DD-MM-YYYY; April–March default; Indian states and UTs; country lock; country-aware menus; India company profile (PAN, constitution, address, PIN); localisation and isolation suites | 47 |
| R182 | 2 | GST registrations (multi-GSTIN), GSTIN/PAN/TAN/CIN validation; customer/vendor GSTIN and state; HSN/SAC and UoM on items and lines; place of supply; India chart-of-accounts templates per constitution | 48 |
| R183 | 2 | Indian invoices (tax invoice, bill of supply, credit/debit note, proforma, export, delivery challan without stock) and PDF ₹ check; FY-scoped numbering; voucher views (sales, purchase, receipt, payment, contra, journal) over the existing documents | — |
| R184 | 2 | Indian bank statement import (dates, ₹, UPI/NEFT/RTGS/IMPS); Schedule III P&L/BS mapping; Day/Cash/Bank Book in India format | — |
| R185 | 3 | GST engine with effective-dated, approved rules; RCM, exempt, zero-rated, composition, cess; GST control reconciliation | 49 |
| R186 | 3 | GST Compliance Centre: dashboard, GSTR-1 and GSTR-3B preparation, purchase register, GSTR-2B import and reconciliation, liability and payment tracking, status workflow | 50 |
| R187 | 3 | TDS/TCS under the Income-tax Act 2025 (effective-dated rules, vendor TDS, challans, certificates tracking) | 51 |
| R188 | 3 | Provider interface for e-invoice (IRN, QR, cancel) and e-way bill. **Needs a GSP/IRP sandbox account from you**; nothing is simulated | 52 |
| R189+ | 4 | Inventory, India payroll (PF/ESI/PT/LWF by state, salary TDS), AI Financial Controller checks for India, India dashboard | as needed |
| — | 5 | Release readiness: security review, performance, phone/zoom matrix, install/upgrade/rollback, guides | — |

Each release: full Canadian gate, the new India suites, RESULTS.md and the ZIP, tested on the gate host only. Nothing
goes to IONOS staging or production without your separate authorisation.

**What I need from you before or during the phases (not blocking Phase 1):**

1. Approval of the GST rate rule list before it is enabled (Phase 3). I will prepare it from the CBIC notifications with
   references; a person must approve it.
2. A GSP/IRP and e-way bill sandbox account, if you want e-invoicing beyond preparation (Phase 3).
3. Your decision on hosting location for Indian customers' data (before an India launch).

# R188: India edition foundation (IN-1), India master data (IN-2), and an R187 menu fix

R188 = R187 plus the changes below.
- **Canadian companies:** their figures and screens do not change.
- **India edition:** off by default. It is controlled by a new switch in Site & Release Settings.

## 1. India edition switch and sign-in (IN-1)

Site & Release Settings › **India edition**:

| Setting | Sign-in screen |
|---|---|
| Off (default) | No edition choice. Choosing India in a crafted request is refused (403 `india_edition_unavailable`) |
| Platform owner only | The Edition choice appears only on the `app.html?edition=in` link, and only the platform owner may choose India |
| Everyone | The sign-in screen shows Edition: Canada / India |

**The edition is fixed for the session.**
- An India session lists and opens only India companies, and a Canada session only Canadian ones.
- The server refuses the other edition's companies with 403 `edition_mismatch`.

## 2. India company (IN-1)

**Add Company in an India session:**
- asks for the constitution, PAN, GSTIN (optional), TAN, CIN/LLPIN, state or union territory, district and PIN;
- PAN, GSTIN check character and GSTIN state code are checked;
- the company is created with INR, an April–March financial year, Asia/Kolkata, an Indian starter chart of accounts (37 accounts and 9999 suspense, no GIFI codes) and an INR current account;
- it has no Canadian tax codes, PST set-up or Canadian payroll rates. The upgrade routine skips India companies for those.

**What an India session shows:**
- money as ₹ with Indian digit grouping, and dates as DD-MM-YYYY (also in the registers and the Day Book);
- no Payroll module, no GIFI, no Tax Code Report, no GST/HST/PST Summary, no CRA items and no Bank Transfers;
- no GST/HST card on the dashboard, and the profit chart axis uses ₹.

These modules come back in Indian form in later releases: GST (IN-3), TDS/TCS (IN-4), payroll (IN-5) and reports (IN-6).

## 3. India master data (IN-2): Settings › Company details › India Masters

The page appears only in an India company.

### Profiles: India details of a record that already exists, kept by effective date
- **Company:** business activity, IEC, Udyam number and MSME category, income-tax regime, tax-audit applicability, accounting standard.
- **Customer and vendor:**
  - PAN, residency, foreign tax ID, MSME (Udyam number, category, activity, payment rule, agreed payment days), TCS section, related party and relationship;
  - vendors also have a TDS section, job worker and transporter.
- **Product or service:** HSN/SAC, UQC, GST rate and cess rules, GST treatment, reverse charge, ITC class, capital goods, ITR and Form 3CD mappings, import/export, customs tariff, valuation method, batch/serial/expiry tracking, default warehouse.
- **GL account:** Schedule III line, ITR schedule, Form 3CD clause, GST treatment, ITC class, TDS/TCS section, 40/40A, 43B, 14A, 269SS/ST/T, depreciation block, control account, reconciliation, income classification.
- **Fixed asset:** income-tax depreciation block, additional depreciation, ITC and subsidy adjustments, supplier.
- **Bank account:** IFSC, bank, branch, holder, account type, SWIFT, UPI ID.

### Records: several rows, each with an effective date
- GST registrations for the company, customers and vendors (type, status, valid from, cancelled on);
- addresses;
- lower or nil deduction certificates;
- employees, pay components and Chapter VI-A declarations;
- payment methods;
- warehouses, storage locations, transporters, vehicles and ports;
- job workers;
- loans and deposits.

### Statutory rules
29 categories, among them GST rates, cess, HSN/SAC, reverse charge, ITC, TDS, TCS, income-tax regimes, slabs, surcharge, rebate and exemptions, Chapter VI-A, depreciation blocks, MSME, 43B, 40A, ITR and 3CD maps, Schedule III, due dates, accounting standards, PT, LWF, EPF and ESIC.
- They are stored in the existing `statutory_rates` table with jurisdiction `IN` or `IN-<state>`.
- **Tegh supplies no rates, sections, thresholds or due dates.** The user enters each rule with its source and the date it takes effect.
- A new rule is a draft until the Company Owner or an Admin approves it. Approving a later version ends the earlier one the day before.

### Controls
- **History is kept.** A later effective date adds a version. A record change supersedes the old row (end date the day before). Make inactive keeps the row. Nothing is deleted.
- **Checks:** PAN, GSTIN (check character, and the PAN inside it must match), IFSC, HSN/SAC, UQC, Udyam, IEC, PIN, UAN, UPI ID and other formats.
  - Unknown fields are refused.
  - A referenced rule, record or entity must belong to this company.
- **Roles:**
  - an Editor can maintain items, parties, assets, bank details and records;
  - only the Owner or an Admin changes the company profile, account mappings and rules;
  - a Viewer can only read.
- **Isolation and audit:** every India route answers 404 for a Canadian company. Every change is audited with its old and new values.
- **Backups** include the India tables, and a restore creates them first.
- **What these masters do not do yet:** nothing in this release calculates or posts from them. GST, TDS/TCS, payroll and reports use them in later releases.

## 4. Database

Schema stays 46. The changes are first-use additions, run only when the India edition is first used (the first India sign-in or India company):
- `sessions.edition` and `companies.edition` (CHAR(2), default `CA`);
- tables `company_india_profile`, `india_entity_profiles` and `india_master_records`.

No existing column is changed or removed. An installation that never signs in to India gets none of these.

## 5. R187 fix (DD-04)

The Download menu's button no longer animates when pressed. Its panel used to move 1 px just after opening. CSS only.

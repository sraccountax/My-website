# R190: India edition GST, GST returns, payroll, TDS/TCS and India Home (IN-3a to IN-6)

R190 is R189 plus the changes below.

**Canadian companies:**
- Their figures and screens do not change.
- Every new India route answers 404 for them, and every new menu item is hidden in them.

**India edition:**
- It is still off by default (Site & Release Settings › India edition).
- **Statutory content:** Tegh ships no Indian rate, slab, section, limit or due date. The user enters each one as a statutory rule with its source, and the Company Owner or an Admin approves it before it is used.
- **Government services:** nothing is filed, and no government service is called.

## 1. GST on invoices (IN-3a)

### GST codes
**Where:** Settings › Company details › India Masters › GST codes.

**Source of a code:**
- It is made from an approved GST rate rule.
- An approved cess rule, if chosen, adds a cess component.

**Two codes per rate:**
- **Intra-state** (e.g. `GST18`): CGST and SGST, each half the rate. A union territory without a legislature (AN, CH, DH, LA, LD) gets UTGST instead of SGST.
- **Inter-state** (e.g. `IGST18`): IGST at the full rate.

**GL accounts**, added if missing:
- output (liability): 2401 CGST, 2402 SGST, 2403 UTGST, 2404 IGST, 2405 cess;
- input credit (asset): 1451 to 1455 in the same order.

**Who can create codes:** the Owner or an Admin. Creating them again for the same rule changes nothing.

### Place of supply
**Customer invoices:**
- The place of supply defaults from the customer's GST registration, then the customer's state.
- A customer abroad is 96 (outside India).

**Vendor invoices:** the form has Place of supply (the company's state by default) and Supplier's state.

**Where it is kept:** with each document, in an India table.

**Tax-code lists:** a line lists only the codes for the supply type. "Auto" takes the item's GST rate rule (India Masters).

### Checks on every customer invoice, invoice edit and vendor invoice
- **A non-GST code:** refused.
- **Wrong code for the supply type:** CGST/SGST on an inter-state supply, or IGST on an intra-state one, is refused.
- **Rule not in force:** no approved rule version in force on the document date. Refused.
- **Rate changed:** the rule in force has another rate. Refused.
- **No GSTIN:** a company without a GSTIN charging GST is refused.
- **Expenses and bank lines:** a GST code there is refused. Use a vendor invoice.

### Posting
- **Output tax:** each head is credited to its own account.
- **Input tax:** debited to its credit account.
- **No GSTIN:** a company without a GSTIN gets no credit, so input tax becomes part of the cost, as before.

### GST Summary
**Where:** Reports › GST Summary.

**What it shows:** output tax, input credit and the net by head, with every document.

**What it covers:** issued customer invoices, posted vendor invoices, and posted credit and debit notes. A credit note reduces the figures.

## 2. GST returns (IN-4)
**Where:** Reports › GST Returns. Each table downloads as CSV.

### GSTR-1
**Sections:**
- **4A B2B:** registered customers, with the GSTIN and the place of supply.
- **5 B2C (large):** inter-state invoices to unregistered persons above the limit in the approved rule "GST return limit", code `B2CL`. Without that rule, every such invoice goes to B2C (small), and a warning says so.
- **6A exports:** WPAY when IGST is charged, WOPAY when it is not.
- **7 B2C (small):** by place of supply and rate.
- **9B credit and debit notes:** registered and unregistered.
- **8 nil-rated, exempt and non-GST supplies.**
- **12 HSN/SAC summary:** from the item profile. Lines without an HSN/SAC code are flagged.
- **13 documents issued:** including cancelled (void) ones.

**Credit and debit notes:** a note's tax is split across the GST heads of its invoice, the same way posting splits it. The report therefore agrees with the GST accounts.

### GSTR-3B
- **3.1 (a) to (e):** outward supplies, net of notes.
- **3.2:** inter-state supplies to unregistered persons, by place.
- **4:** input tax credit:
  - imports from suppliers outside India (4(A)(1));
  - all other ITC;
  - net ITC;
  - ineligible ITC (tax not claimed).
- **5:** exempt and non-GST inward supplies.
- **6.1 payment of tax:** the credit is set off against tax in the order of section 49 and rule 88A:
  - IGST credit is used first;
  - CGST credit and SGST/UTGST credit are never used against each other.

  The table shows the cash payable and the credit carried forward for each head.

**Not computed yet:** these boxes show 0 and say so.
- reverse charge;
- ITC reversals;
- interest and late fee.

### Other tabs
**Registers:**
- **Sales:** every document, by code.
- **Purchases (input tax credit):** every document, by code, with supplier GSTIN and tax not claimed.

**GST ledger check:** for each GST account it shows:
- the opening balance;
- the movement posted by GST documents;
- other entries (payments, set-off journals);
- the closing balance;
- the report figure, with any difference.

The movement from GST documents must equal the report figure.

**Return periods:** months of the financial year, for GSTR-1 and GSTR-3B.
- **Prepare:** an Editor or above keeps the figures as they are now.
- **Mark filed:** the Owner or an Admin enters the ARN and the filing date.
- **Reopen:** the Owner or an Admin, with a reason.
- **Changed since prepared:** shown when the figures change after preparing.
- **Audit:** every step is audited.

**GSTR-2B:**
- **Import:** the JSON downloaded from the GST portal (5 MB at most). The file is untrusted data:
  - it is parsed as JSON only;
  - every GSTIN, number, date and amount is checked;
  - a file for another GSTIN or another month is refused.
- **Reconciliation:** the import is matched with vendor invoices and notes of the financial year, on supplier GSTIN and invoice number. Number matching ignores spaces, punctuation and leading zeros. Each document is then shown as:
  - matched;
  - amounts differ (more than ₹1 in any head, or another date);
  - only in GSTR-2B;
  - only in books;
  - no supplier GSTIN in books.
- **Re-importing:** a new import for the same month replaces the previous one, which is kept.

## 3. Payroll (IN-5)
**Where:**
- Payroll › Pay Runs, Salary Structures and Payroll Registers, in India companies only.
- The Canadian payroll items stay hidden in India, and the India items are hidden in Canada.

**Masters (India Masters › Records):**
- employees: joining and leaving dates; EPF, ESIC, PT and LWF applicability and states;
- pay components:
  - kinds: earning, deduction, employer contribution or reimbursement;
  - calculation: a fixed amount, a percent of another component, a statutory rule, or an amount entered each pay run.
- A pay component can name its GL account (expense) and a new payable account (liability).

**Salary structure:**
- the components of an employee and the monthly amount of each fixed component;
- each structure has an effective date, and earlier versions are kept;
- a percent component needs its base component in the structure.

### Calculating a pay run (one per month)
**Fixed amounts** are prorated by paid days:
- by default, the days employed in the month (joining and leaving dates are taken into account);
- the paid days can be entered to record loss of pay.

**Rule components** use the approved rule in force on the pay date:
- **EPF:** the rate applies to the base, capped at the rule's "threshold to" (the wage ceiling).
- **ESIC:** the rate applies to gross pay; nothing is deducted when gross pay is above "threshold to".
- **Professional Tax and Labour Welfare Fund:** the slab of the employee's state whose range holds the base.
- **Rounding:** rule amounts are rounded to the rupee.
- **Applicability:** a rule applies only when the employee's EPF, ESIC, PT or LWF flag is on.

**Refusals:**
- a missing rule, naming what to add;
- a circular dependency between components;
- deductions above gross pay.

**Income tax on salary (TDS):** a deduction entered each pay run. Tegh does not project annual tax.

### Posting, reversal and records
**Posting:**
- Only the Owner or an Admin posts.
- **One balanced journal:**
  - **debits:** earnings and reimbursements (default 6000 Salaries and Wages); employer contributions (default 6010);
  - **credits:** deductions (default 2310); employer contributions (default 2320); net pay (2300 Salaries Payable).
- A posted run cannot be recalculated.

**Reversal:**
- the Owner or an Admin, with a reason;
- it posts a reversing journal;
- the month can then be run again.

**Payslip:** earnings, deductions, employer contributions and net pay, each with its basis. It can be printed.

**Registers:**
- salary register by employee and component (CSV);
- component totals;
- a check that the amounts posted to the liability accounts equal the register.

## 4. TDS and TCS, India Home and India Help (IN-6)

### Sections
Sections are statutory rules in the categories TDS section and TCS section. Each has:
- a rate;
- "threshold from" = the single-document limit;
- "threshold to" = the yearly limit per party.

**Party settings:**
- **Section:** set on the vendor or customer India profile.
- **No PAN:** the party needs the approved rule `<section>-NOPAN`; otherwise deduction is refused.
- **Certificate:** a valid lower or nil deduction certificate (India Masters › Records), within its limit, replaces the rate.

### TDS and TCS page (Payables › TDS and TCS)
**To deduct or collect:** lists the period's documents of parties with a section, showing:
- the base (value before GST);
- the rate and where it comes from;
- the amount;
- or why nothing is due.

**Yearly limit crossed:** when the yearly limit is crossed for the first time, the earlier documents of the year that had no TDS are added to the base.

**Recording TDS** (Editor or above, on a posted vendor invoice):
- one journal: payables debited, TDS Payable 2330 credited;
- the vendor invoice balance falls by the TDS, so the vendor is paid the net amount;
- TDS above the unpaid balance is refused.

**Recording TCS** (on an issued customer invoice):
- Tegh issues a separate receivable "TCS-<invoice number>": receivables debited, TCS Payable 2340 credited;
- the customer pays it with the invoice;
- it is not a supply, so GST reports and the documents-issued count leave it out.

**Register:**
- by section and by quarter of the financial year, with each deductee's PAN;
- the TDS/TCS Payable check;
- CSV download;
- it is working data for Forms 26Q and 27EQ; paying the tax and filing are done outside Tegh.

**Reversing TDS:**
- the Owner or an Admin, with a reason;
- it restores the vendor invoice balance.

**Undoing TCS:** void its TCS document in the ordinary way.

**Voids:** the original invoice cannot be voided while TDS on it, or its TCS document, stands.

### India Home (Settings › Company details)
**Position:**
- output GST and input credit balances;
- last month's GSTR-3B cash payable;
- last month's return status;
- TDS and TCS Payable;
- vendor invoices with TDS still to record;
- the latest pay run.

**Set-up checklist** (14 items), each with a link to where it is set up:
- company PAN, GSTIN and TAN;
- GST rules, codes, B2C limit, party registrations and HSN/SAC;
- employees, components, structures and payroll rules;
- TDS sections and vendor sections.

### India Help (Settings › Company details)
How the edition works, in set-up order: GST, returns, payroll, TDS/TCS, and what Tegh does not do.

## 5. Not in this release
- reverse charge, and ITC blocking or reversal by item class;
- GST fields on the printed tax invoice;
- GSTR-1 and GSTR-3B upload files in the portal's format (CSV working files only);
- e-invoice and e-way bill (these need an authorised provider account);
- TDS on salary projected from the employee's regime and declarations;
- Forms 16, 24Q, 26Q and 27EQ as files;
- statutory payment challans (record payments as journals against 2330/2340/2310/2320);
- Schedule III, ITR and Form 3CD reports from the account mappings;
- recurring customer invoices in an India company do not apply GST codes automatically;
- Tegh Assist answers from its general knowledge; it has no India-specific guidance yet. India Help is the reference.

## 6. Database
Schema stays 46. The tables below are created on first use, only when an India company first uses the feature. No existing column is changed.

| Release | Tables |
|---|---|
| IN-3 | `india_gst_codes`, `india_document_gst` |
| IN-4 | `india_gst_returns`, `india_gstr2b_imports`, `india_gstr2b_lines` |
| IN-5 | `india_salary_structures`, `india_pay_runs`, `india_pay_run_lines` |
| IN-6 | `india_tds_entries` |

**Backups:**
- They include all of these tables.
- A restore creates the tables first.
- A restore into a new copy keeps the links between records. The TDS entries keep their vendor invoices; this was tested.

**Other changes:**
- India Masters adds:
  - the rule category "GST return limit";
  - the pay component field "Payable account".
- One shared check was added. A vendor or customer invoice cannot be voided while India TDS or TCS stands on it. It applies only when the India table exists.

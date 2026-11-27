# R198: Tax Set-up for India companies

R198 is R197 plus the changes below. India only; Canadian companies are unchanged. Schema stays 46. There is no migration.
Existing GST rules and codes, TDS/TCS rules, India profiles, invoices and TDS entries are unchanged.

**Settings › Tax Set-up** replaces GST Set-up. It is laid out like Payroll Set-up: first the tax heads, then who or what each one applies to, each from a date.
The dashboard steps "Set up GST" and "Set up TDS", and the India Home checklist items for GST rates, TDS sections, vendor sections and HSN/SAC, open it.

## 1. GST rates
- **Standard rates:** 5%, 18%, 40%, 0%, 3% and 0.25% to tick, as in R196. On a new company 5% and 18% are ticked; nothing is saved until the person confirms.
- **Your own rates:** "Add a rate" takes a rate and a heading, for example "GST 12% (works contracts)". The heading can be renamed later. Standard rates keep their heading.
- Tegh still creates the CGST + SGST (or UTGST) and IGST codes for each rate, and the B2C (large) limit is set here.

## 2. TDS sections (deducted from vendor invoices)
The common sections are listed with Tegh's suggested rate and limits. Each figure can be changed before confirming.

| Section | Heading | Rate | One invoice above | Year above |
|---|---|---|---|---|
| 194C-IND | Contractors: individual or HUF | 1% | ₹30,000 | ₹1,00,000 |
| 194C | Contractors: company, firm or others | 2% | ₹30,000 | ₹1,00,000 |
| 194J | Professional fees | 10% | ₹50,000 | ₹50,000 |
| 194J-TECH | Technical services, call centres, film royalty | 2% | ₹50,000 | ₹50,000 |
| 194H | Commission or brokerage | 2% | ₹20,000 | ₹20,000 |
| 194I | Rent: land, building or furniture (limit is per month) | 10% | ₹50,000 | — |
| 194I-PM | Rent: plant, machinery or equipment (limit is per month) | 2% | ₹50,000 | — |

- **Your own sections:** "+ Add section" takes a code (for example 194A), a heading, a rate and limits. The heading can be renamed later.
- **Vendors without a PAN:** ticked by default at 20% (section 206AA). Tegh adds a "<section>-NOPAN" rule for each section, which the TDS calculation already uses.
- **Confirming:** the person confirms once; each section is saved and approved (Owner or Admin). Repeating changes nothing. To change a figure that is set up, choose a later "From" date: a new version starts and the earlier one ends the day before. A figure that was used is never rewritten.

## 3. TCS sections (collected on sales)
Own sections only, with a note that TCS on sale of goods (206C(1H)) ended on 31 March 2025.

## 4. Vendors and customers
- **Lists:** every vendor with its PAN and TDS section, and every customer with its PAN and TCS section (customers are shown once a TCS section exists).
- **Edit:** changes the PAN and section from a date. Earlier invoices keep what applied then.
- **Other details:** the vendor's other India details (MSME and so on) are kept.

## 5. Products and services
- **List:** every product and service with its HSN/SAC code and GST rate.
- **Edit:** changes them from a date. A new invoice line for the item takes the rate in force on the invoice date, and it can still be changed on the line.

## Also
- **Who can do what:**
  - An Editor can set the details of vendors, customers, products and services.
  - Only the Owner or an Admin confirms rates and sections or renames their headings (403 otherwise).
- **Selection bar:** tables whose checkboxes are form choices no longer show the generic "rows selected" bar. Tables opt out with `data-no-select-bar`; no other table changes.

## Tests
- **Suite 76-r198 (10 tests):**
  - suggestions;
  - confirming, repeating and conflicts;
  - headings;
  - vendor, customer and item details;
  - the screen at 1440 px, 390 px and in the dark theme.
- **TDS on real vendor invoices, worked out by hand:**
  - contractor 1%: ₹25,000 and ₹20,000 under both limits, then ₹60,000 crosses both, giving a base of ₹1,05,000 and TDS of ₹1,050;
  - consultant without a PAN: 20% of ₹60,000 = ₹12,000;
  - rent: ₹45,000 is nil, and ₹55,000 at 10% = ₹5,500.
- **GST from the item:** an invoice line for a service set to 12% gets CGST 6% + SGST 6%.
- **Other suites:** 65-in3, 68-in6, 73-r195 (updated for the new page), 74-r196 and 75-r197 pass unchanged.

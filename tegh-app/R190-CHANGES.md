# R190: GST for India companies (IN-3a)

R190 = R189 plus the changes below.
- **Canadian companies:** their figures and screens do not change. Every GST route answers 404 for them, and every GST check returns at once outside the India edition.
- **India edition:** still off by default (Site & Release Settings › India edition).

## 1. GST codes (Settings › Company details › India Masters › GST codes)
- **Source of a code:** a GST code is made from a GST rate rule that the Company Owner or an Admin has approved in Statutory rules. Tegh supplies no rate. An optional approved cess rule adds a cess component.
- **Two codes per rate:**
  - **Intra-state**, e.g. `GST18`: CGST and SGST, each half the rate. A company in a union territory without a legislature (Andaman and Nicobar, Chandigarh, Dadra and Nagar Haveli and Daman and Diu, Ladakh, Lakshadweep) gets UTGST instead of SGST.
  - **Inter-state**, e.g. `IGST18`: IGST at the full rate.
- **GL accounts**, added if missing:
  - output (liability): 2401 CGST, 2402 SGST, 2403 UTGST, 2404 IGST, 2405 cess;
  - input credit (asset): 1451 to 1455 in the same order.
- **Who can create them:** only the Owner or an Admin. Creating them again for the same rule changes nothing.

## 2. Place of supply
- **Customer invoices:**
  - The new-invoice form shows **Place of supply**. It defaults from the customer's active GST registration (India Masters), then the customer's state. A customer outside India is "Outside India" (96).
  - A sale is **intra-state** when the place of supply is the company's own state; otherwise it is **inter-state**.
- **Vendor invoices:**
  - The form shows **Place of supply** (default: the company's state) and **Supplier's state** (from the supplier's GST registration, then its state).
  - A purchase is intra-state when the two are the same.
- **Where it is kept:** with each document, in a new India table.
- **Tax-code lists:** the line's tax-code list shows only the codes for the current supply type. "Auto" uses the item's GST rate rule from India Masters.

## 3. Checks on every customer invoice, invoice edit and vendor invoice in an India company
| Situation | Result |
|---|---|
| A tax code that is not a GST code | Refused (`india_gst_code_required`) |
| CGST/SGST code on an inter-state supply, or IGST on an intra-state one | Refused, naming the right code (`india_gst_supply_mismatch`) |
| No approved rule version in force on the document date | Refused (`india_gst_rule_not_in_force`) |
| The rule in force on that date has another rate | Refused; create codes from the current version (`india_gst_rate_changed`) |
| The company has no GSTIN and the invoice charges GST | Refused (`india_gst_not_registered`) |
| A GST code on an expense or a bank line (they have no place of supply) | Refused; use a vendor invoice (`india_gst_use_documents`) |

**Posting:**
- Output CGST, SGST, UTGST, IGST and cess are credited to their own GL accounts.
- Input tax is debited to its credit account. A company without a GSTIN cannot claim credit, so its input tax becomes part of the cost, as before.

## 4. GST Summary (Reports › GST Summary, India only)
- **What it shows:**
  - output tax and input tax credit by head (CGST, SGST, UTGST, IGST, cess), and the net payable or credit;
  - taxable value and tax by supply type and rate, for outward and inward supplies;
  - every document, with the party's GSTIN and the place of supply.
- **What it covers:** issued customer invoices and posted vendor invoices that use GST codes. It is computed from the books. **Nothing is filed** and no government service is called.

## 5. Not in this release
- Credit and debit notes still post GST in the proportions of their invoice, but the GST Summary does not include them yet.
- Not yet supported:
  - reverse charge;
  - ITC blocking or reversal by item class;
  - GST fields on the printed tax invoice;
  - GSTR-1 and GSTR-3B layouts and upload files;
  - e-invoice and e-way bill.
- Recurring customer invoices in an India company do not apply GST codes automatically.

## 6. Database
Schema stays 46. These are first-use additions, created only when an India company first uses GST:
- table `india_gst_codes`: links a tax code to its rule, rate and supply type;
- table `india_document_gst`: the place of supply of each document.

Backups include both tables, and a restore creates them first. No existing column is changed.

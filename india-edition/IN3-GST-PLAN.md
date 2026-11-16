# IN-3 GST: inspection and plan

## What already exists (reused, not duplicated)

| Area | Existing | Use for GST |
|---|---|---|
| Tax codes (R137) | `tax_codes` + `tax_code_components` (rate, sales GL, purchase GL, recoverable) | A GST code is a tax code: CGST + SGST (or UTGST), or IGST, plus cess when set |
| Per-document tax | `document_tax_lines` (component, rate, taxable base, tax, GL) on invoices, vendor invoices, notes, expenses | GST per head per document; the source for GST reports |
| Posting | `tax_rows_sales_lines` / `tax_rows_purchase_lines` post each component to its GL | Output CGST/SGST/IGST credited; input credited as ITC (recoverable) or added to cost |
| Notes | Credit/debit notes take the tax proportions of the source invoice | GST on notes follows the invoice |
| India rules (IN-2) | `statutory_rates` `gst_rate`, `gst_cess`, `rcm_rule`, `itc_rule`; draft until approved; dated versions | The only source of a GST rate. Tegh supplies none |
| India masters (IN-2) | Item profile (HSN/SAC, GST rate rule, cess, treatment, RCM, ITC class); GST registrations of company, customers, vendors; addresses with state | Default rate of a line; party GSTIN and state for place of supply |
| India company (IN-1) | `tax_setup_mode = codes`, `tax_registered` = has GSTIN, state in `companies.province` | Intra- or inter-state; whether GST may be charged |

**Missing:** GST code creation from a rule, place of supply, the intra/inter check, the rate-in-force check, GST GL accounts, and GST reports.

## Slices

### IN-3a (this release)
- **Settings › India Masters › GST codes.**
  - From an approved GST rate rule (and an optional approved cess rule), Tegh creates two codes:
    - intra-state: CGST + SGST, each half the rate (UTGST instead of SGST for a union territory without a legislature: AN, CH, DH, LA, LD);
    - inter-state: IGST at the full rate.
  - GST GL accounts are added if missing:
    - output (liability): 2401 CGST, 2402 SGST, 2403 UTGST, 2404 IGST, 2405 cess;
    - input credit (asset): 1451 CGST, 1452 SGST, 1453 UTGST, 1454 IGST, 1455 cess.
- **Place of supply** on customer and vendor invoices (India only). It is stored in a new India table, so no Canadian column changes.
  - Sales default: the customer's active GST registration, then the customer's state; a customer outside India is a foreign supply (96).
  - Purchases: intra-state when the supplier's state (from its GSTIN, its registration or its state) equals the place of supply (default the company's state).
- **Checks on every customer invoice, invoice edit and vendor invoice in an India company:**
  - only GST codes;
  - CGST/SGST only for intra-state, IGST only for inter-state;
  - the rule behind the code must be approved and in force on the document date, with the same rate;
  - a company without a GSTIN cannot charge GST.
- **Default code of a line:** the item's GST rate rule (India Masters) gives the code for the supply type.
- **GST summary report (India only):**
  - outward tax by head and rate;
  - input tax credit by head;
  - net payable by head;
  - drill down by document.
  - It is computed from the books. Nothing is filed.

### IN-3b
- Reverse charge on purchases.
- Blocked or reversed ITC from the item ITC class.
- Credit and debit notes with place of supply.
- Tax invoice print fields: GSTIN of both parties, place of supply, HSN/SAC, tax by head.

### IN-3c
- GSTR-1 and GSTR-3B layouts and export files for upload.
- Reconciliation with GSTR-2B imported by the user. No government API is called.

## Not in scope
- Rates, HSN lists and due dates. These stay user-entered rules.
- E-invoice (IRN) and e-way bill. These need a GSP account.
- Any change to Canadian companies.

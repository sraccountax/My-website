# R198: Tax Set-up (India): results

Package: `Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R198-PRIVATE-BETA.zip`, SHA-256 `f067ef99062f9ce401a9cf437307f6e9b09a3372d83059d8e93a64f17ed363f4`, 456 files. Release token `5990-r198-tax-setup`. Source commit `8d26a8d`.

All testing was done on the local gate host (`/srv/gate`, synthetic data only). Nothing was deployed to IONOS or books-test, and no production data was touched.

## Full gate on this exact ZIP
| | Count |
|---|---|
| PASS | 1251 |
| INFO | 13 |
| FAIL | 6 |

The six failures are the known open items from earlier releases, unchanged by R198: BS-07, BS-09 (public policy pages), PW-01, WR-09, DI-SCAN (OCR scanner on the gate host) and DD-01 (menu click timing). Raw results are in `gate/`.

## Suite 76-r198 (new): 10/10 PASS
- **R198-01:** the suggested TDS sections (194C-IND, 194C, 194J, 194J-TECH, 194H, 194I, 194I-PM) are listed with rate and limits. Nothing is saved before the person confirms.
- **R198-02:** confirming creates each section and its `-NOPAN` rule at 20%. An own section (194A) is added. Confirming again changes nothing. A different figure on the same date is refused (409); a later date starts a new version.
- **R198-03:** own headings for sections and GST rates can be renamed. Standard headings cannot (409).
- **R198-04:** an Editor can set vendor, customer and item details. An Editor cannot confirm sections or rename headings (403).
- **R198-05:** vendor PAN and section are effective dated, and other India details on the profile are kept.
- **R198-06:** TDS on real vendor invoices, checked against hand calculations:
  - contractor at 1%: ₹25,000 and ₹20,000 are under both limits (nil); then ₹60,000 crosses both, so the base is ₹1,05,000 and TDS is ₹1,050;
  - consultant without a PAN: 20% of ₹60,000 = ₹12,000;
  - rent: ₹45,000 is nil, and ₹55,000 at 10% = ₹5,500.
- **R198-07:** a service set to GST 12% gives an invoice line CGST 6% + SGST 6% (₹600 + ₹600 on ₹10,000).
- **UI-01 to UI-03:** the page at 1440 px and 390 px (no sideways overflow) and in the dark theme (switched through Preferences, with no light panels left).

Other suites: 65-in3, 68-in6, 73-r195 (updated for the new page), 74-r196 and 75-r197 pass.

## Issues found and fixed while building and testing
1. **"Rows selected" bar on the sections table.** The shared table script treated the tick boxes as row selection. Tables can now opt out with `data-no-select-bar`, and the sections tables do.
2. **Columns cut off.** The status column was clipped and the % sign wrapped. The status now sits under the code, and the column widths were adjusted.
3. **Save and Cancel spilled into the next row** when a vendor was edited. They now sit together in one cell.
4. **A changed limit was treated as "unchanged".** Confirming compared only rate and yearly limit, so a new single-invoice limit was not saved. The single-invoice limit is now compared too.

## Screenshots (`shots/`)
- **Whole page:** `r198-tax-1440.png`.
- **TDS:** `r198-tds-1440.png`, `r198-tds-390.png` and `r198-tds-dark.png`.
- **Cards:** `r198-gst-1440.png`, `r198-parties-1440.png`, `r198-parties-edit-1440.png` and `r198-items-1440.png`.

# R199: fixes from the group bookkeeping audit: results

Package: `Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R199-PRIVATE-BETA.zip`, SHA-256 `b10f4443066bee47e3587af5c2cb24f7c19d96ed9e60e820dc8d4ce6b5ee9ee5`, 457 files. Release token `5990-r199-group-fixes`. Source commit `145fc08`, manifests `0fa620c`.

All testing was done on the local gate host (`/srv/gate`, synthetic data only). Nothing was deployed to IONOS or books-test, and no existing company was changed.

## Full gate on this exact ZIP
| | Count |
|---|---|
| PASS | 1255 |
| INFO | 13 |
| FAIL | 12 |

**Six known open items,** unchanged from earlier releases:
- BS-07 and BS-09: public policy pages;
- PW-01 and WR-09;
- DI-SCAN: the OCR scanner on the gate host;
- DD-01: menu click timing.

**Six failures caused by R199's intended changes or by the test set-up.** Each was corrected in the test and re-run against the same installed package:

| Test | Cause | Re-run |
|---|---|---|
| TX-00 (03-acct), UP-08 (upgrade) | The tests expected exactly 14 starter tax codes; R199 adds Zero-rated and Exempt (16). | PASS (`AB,BC,EXEMPT,GST,…,YT,ZERO`; upgrade `codes=16`) |
| W13-01, W13-02 (end-to-end journey) | The tests matched report cells as "CAD 50.00". R199 shows cells in the table's own currency as "50.00" and keeps the code on totals. Every amount is still checked; only the optional "CAD " changed. | PASS (W13-01, W13-02, W13-03) |
| R199 UI-01, UI-02 | The suite assumed the new dashboard was switched on. The full gate leaves it off, so the suite now turns it on and restores it. | PASS (10/10) |

The standalone re-run of 03-acct also reported TX-00b and AC-01 as failing. Both depend on data a first run of that suite leaves behind: the opening balances were already posted, and the Quebec code had already been edited by TX-00d. Both passed in the full gate on a fresh database, so they are not R199 regressions.

Raw results are in `gate/`. The re-run log is `gate/r199-rerun.log`.

## Suite 77-r199 (new): 10/10 PASS
- **R199-01:** a US vendor's USD bill with no tax code gets no tax: US$1,000 at 1.365 = $1,365.00. Before R199 it got Ontario HST of $177.45. An Ontario vendor still gets HST 13% ($130.00 on $1,000), and a code chosen on the foreign bill is still used. This covers line and single-amount bills.
- **R199-02:** India details on a Canada-edition company request are refused (422 `india_company_needs_india_edition`), and no company is created.
- **R199-03:** new Canadian companies have the Zero-rated and Exempt codes. A $2,500 export on Zero-rated posts no tax and stays balanced.
- **R199-04:** paying US$1,010 on a US$1,000 invoice is refused with the open balance and the excess named (422 `payment_exceeds_balance`).
- **R199-05:** the Users page offers only the signed-in edition's companies.
- **UI-01, UI-02:** the dashboard shows "−$25,000" on one line in the critical colour at 1440 px and 390 px.
  - The USD bank is labelled "(USD account, in CAD)".
  - On a phone, the company name and mode have their own header row.
  - No header or sidebar label is cut off.
- **UI-03:** dark theme: every Financial Analyst figure is at least 9.4:1 against its card. Before R199 they were almost invisible.
- **UI-04:** the customer payment form prefills 1.392000 (the rate for the payment day), not the invoice's 1.365000, and shows a note naming the invoice rate. Paying US$1,010 offers to keep US$10 as an advance. Accepting records the payment and the advance, and books the $27.00 foreign exchange gain.
- **UI-05:** register cells show amounts without the code and zero as "–". The footer totals keep "CAD", and the Tax Codes help no longer mentions CGST/SGST.

Screenshots of the dashboard and the dark Financial Analyst are in the gate host's `ev/shots` (`r199-dashboard-1440.png`, `r199-dashboard-390.png`, `r199-analyst-dark.png`).

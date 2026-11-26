# R200: Group & Intercompany: results

Package: `Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R200-PRIVATE-BETA.zip`, SHA-256 `69110c78f430e1c541cdd168d983cb9810a8fd22a89aedc6f960c0ef778d07ae`, 459 files. Release token `5990-r200-group`. Source commit `3be9aec`, manifests `aa91717`.

All testing was done on the local gate host (`/srv/gate`, synthetic data only). Nothing was deployed to IONOS or books-test, and no existing company was changed.

## Full gate on this exact ZIP
| | Count |
|---|---|
| PASS | 1266 |
| INFO | 13 |
| FAIL | 8 |

**Six known open items,** unchanged from earlier releases:
- BS-07 and BS-09: public policy pages;
- PW-01 and WR-09;
- DI-SCAN: the OCR scanner on the gate host;
- DD-01: menu click timing.

**Two other failures:**

| Test | What happened | Outcome |
|---|---|---|
| UI-04 (suite 77-r199, payment form) | Tegh recorded the payment (US$1,000) and the advance (US$10), booked the $27.00 exchange gain, and left the ledger balanced. The test listed the two payments in the order they were saved. Both were saved in the same second, so the order varied and the expected string did not match. | Test defect. The test now compares the two payments in a fixed order. Re-run: 10/10 PASS. |
| IN-09 (suite 21-r152, Document Intake supplier memory) | A document card was still hidden when the 30-second wait ended. | Re-run on its own on the same install: PASS. It also passed in R199's full gate, and R200 does not change Document Intake. This is recorded as a timing failure in the full run, not a fix. |

Suite 78-r200 passed 7/7 inside the full gate. Raw results are in `gate/`.

## Suite 78-r200 (new): 7/7 PASS
Three synthetic companies: Ontario parent (P), BC branch (W) and Quebec subsidiary (Q). Every expected figure was worked out by hand before the test ran.
- **R200-01 (linking):**
  - a vendor from the wrong company is refused (422);
  - a person who is only an Editor in one of the two companies cannot link them (403);
  - the Owner links P–W (customers, vendors, expense account, loan and interest accounts) and P–Q;
  - linking the same companies again is refused (409).
- **R200-02 (mirror):**
  - P's three intercompany invoices become draft vendor invoices in W and Q with the same numbers and dates, GST, account 6550, and $26,250 / $26,250 / $10,500;
  - mirroring the same invoice again is refused (409), and an invoice to an outside customer is refused (422);
  - the drafts are posted; all three ledgers stay balanced.
- **R200-03 (reconciliation as at 30 Sep 2026):**
  - P says W owes $153,000.00 (2 × $26,250 + loan and interest $100,500);
  - W says it owes $126,700.00 ($26,250 posted + loan and interest $100,450);
  - the difference is $26,300.00, with one draft not posted;
  - after W posts it the difference is $50.00: interest booked as $500 by P and $450 by W;
  - P–Q agrees.
- **R200-04 (consolidation worksheet, 1 Jan–30 Sep 2026):**
  - intercompany income of $60,500 (fees $60,000 + interest $500) and expense of $60,450 are eliminated, and the $50 left over is on its own line;
  - group net income is unchanged;
  - management fees consolidate to the outside sale of $5,000;
  - receivables consolidate to $5,250 (the outside invoice); payables and both loan accounts to $0, with the $50 balance difference shown;
  - a single company is refused (422).
- **R200-05 (group close):**
  - with W included, where the person is only an Editor, nothing is closed (409, reason listed);
  - P and Q are then closed together through 30 Sep 2026;
  - a September entry in P is refused, and W is still open.
- **UI-01:**
  - Settings › Group & Intercompany shows the two links and P's three intercompany invoices (Posted);
  - the reconciliation shows "Difference $50.00" and "Agrees";
  - the worksheet shows the difference line and group net income;
  - no page errors, and no sideways scroll.
- **UI-02:**
  - at 390 px there is no sideways scroll (the worksheet scrolls in its own box);
  - in the dark theme no light panels are left.

Two display problems were found and fixed before the gate:
- **Stray Cancel button:** the shared form enhancer added a "Cancel" button to the worksheet and reconciliation filters. The filters now opt out.
- **Capitalised names:** company names were title-cased ("Mwxze1gu"). Labels and column headers holding company names now keep their case.

## Screenshots (`shots/`)
- `r200-group-1440.png`: the reconciliation and the consolidation worksheet.
- `r200-group-390.png` and `r200-group-dark-390.png`: phone width and dark theme.

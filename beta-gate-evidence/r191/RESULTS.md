# R191 India company set-up (IN-7a): results

Package: `Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R191-PRIVATE-BETA.zip`, release token `5990-r191-india-setup`. The SHA-256 is in section 6.

Everything here was run on the test server (gate.test) with synthetic companies. **Nothing was run on the IONOS server.**

## 1. What changed
See `tegh-app/R191-CHANGES.md`. In short:
- **First company of an India sign-in:** the India company form replaces the Canadian set-up wizard.
- **Time zone:** any time zone, Asia/Kolkata by default.
- **Financial year:** start and end chosen with date pickers.
- **Currency:** INR only.
- **Bank account:** current or savings instead of chequing.
- **Registrations:** GST and the Indian registrations (PAN, Udyam, EPF, ESIC, PT, LEI, FSSAI and others).
- **States:** Indian states and union territories only.
- **Address:** a full Indian address for the company, customers and vendors, carried onto invoices.
- **Company Details:** editable.

**Canadian companies:** no change (section 4).

## 2. Full gate (automated, fresh install from the R191 ZIP)

| Result | Count |
|---|---|
| PASS | 1204 |
| INFO | 13 |
| FAIL | 6 |

**The six failures** are the known ones (R186 to R190): BS-07, BS-09, PW-01, WR-09, DI-SCAN and DD-01.

**Re-runs:** IN-4 (UI-02, UI-03), IN-5 (IN5-01, UI-02) and IN-6 (IN6-10, UI-01) failed in the gate.
- **Cause:** sign-in was refused with "Too many sign-in attempts". This is the test server's login rate limit, filled by the earlier suites (the same cause as in R190).
- **Re-run:** with the attempts cleared, on the same installation:
  - IN-4: 14/14;
  - IN-5: 10/10;
  - IN-6: 12/12.

  The totals above include the re-runs.
- **Gate script change:** it now clears the login attempts before each India suite. This is a test-runner change only; the product's rate limit is unchanged.

**Other checks:**
- E2E: 53/53;
- path and header checks: 45 PASS, 0 FAIL;
- FILE-MANIFEST: all 445 entries OK.

## 3. India suites

| Suite | Result |
|---|---|
| 62-in1 | 10/10 |
| 63-in2 | 11/11 (IN2-06 and UI-01 updated: creating a company now also adds its GST registration record) |
| 65-in3 | 13/13 |
| 66-in4 | 14/14 |
| 67-in5 | 10/10 |
| 68-in6 | 12/12 |
| 69-in7 (new) | 7/7 |

**What 69-in7 checks** (titles from `logs/in7.json`):
- **IN7-01 refusals:**
  - an unknown time zone;
  - a financial year ending before it starts, or longer than 18 months;
  - a non-INR currency;
  - a bad Udyam number;
  - an unknown registration.
- **IN7-02 create:**
  - INR books, with the chosen time zone and financial year (1 April 2026 to 31 March 2027);
  - the full address composed into lines 1 and 2;
  - the registrations in the India Masters company profile;
  - the GSTIN as a GST registration record;
  - a savings account (no chequing).
- **IN7-03 Company details:**
  - the Owner reads PAN and TAN in full;
  - the Owner edits address, time zone and registrations (a new profile version);
  - the financial year can change while there are no entries, and is locked after the first journal.
- **IN7-04 customer and vendor with a full Indian address:**
  - lines 1 and 2 are composed;
  - the parts are kept for editing;
  - a bad PIN is refused;
  - the invoice carries the whole address.
- **IN7-05 Canada unaffected:**
  - a Canadian company gets 404 on the India company and address routes;
  - India address fields sent to a Canadian customer are ignored;
  - the Canadian chequing account type still works.
- **UI-01:** the first company of an India sign-in gets the India form instead of the Canadian wizard.
  - The form has every time zone (Asia/Kolkata chosen), financial year date pickers, registrations, an Indian address, INR, and a current or savings account.
  - It has no provinces, HST or chequing.
  - Creating the company opens it.
- **UI-02:**
  - Company details shows the registrations and edits in place;
  - the customer form has the Indian address fields;
  - Company Setup shows India cards only.

**Implemented but not covered by an automated test:**
- the state lock once GST documents exist;
- masking of PAN and TAN for roles below Admin;
- refusal of edits by those roles;
- refusal of a Canadian province (the form offers none).

**Faults found and fixed:**
- a blank legal name was refused;
- India sign-in failed on a date function that is not loaded at sign-in.

**Screenshots:** `shots/`. The date fields show the browser's own date format (the test browser uses the US format). A browser set to an Indian locale shows DD/MM/YYYY.

## 4. Canadian before/after comparison (test server)

**Method:**
1. The R180 database snapshot (68 companies) was restored.
2. It was captured with the R191 code, India switch Off.
3. The capture was compared with the R190 capture (`comparison/r191-vs-r190-report.json`).

**Result: 0 differences in 592 compared files.**

**Screens not compared:** the third company whose screens are captured differs between runs, so its 34 screen files (17 each) were not compared. API data for all companies was compared.

## 5. Not covered
- **IONOS:** no deployment or tests there.
- **Registration formats:** the patterns follow the published formats. They were not checked against government services; nothing is verified online.
- **IN-7 provider integration:** e-invoice and e-way bill wait for the provider account.

## 6. Package
- **SHA-256:** `55d2301ab1ba0d54f785a41fce4676d8fb79594316d8ca745d99806f93de1e43`
- **Size:** 446 files.
- **FILE-MANIFEST:** all 445 entries OK.

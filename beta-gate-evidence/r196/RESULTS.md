# R196: Easier GST and payroll set-up for India companies: results

Package: `Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R196-PRIVATE-BETA.zip`, SHA-256 `d8085d629078510fbb3ec6a6b1238a361196ab5868df6d9434c5f9139caa9617`, 453 files. Release token `5990-r196-easy-setup`. It contains R194 and R195.

**Where this was run:** everything here was run on the test server (gate.test) with synthetic users and companies. **Nothing was run on the IONOS server or on books-test.sraccountax.ca.**

The changes are described in `tegh-app/R196-CHANGES.md`.

## 1. What changed
**GST Set-up:** one screen.
- **Rates:** tick the GST rates you charge. 5% and 18% are ticked; 40%, 0%, 3% and 0.25% are offered, with another rate box.
- **Confirming:** tick "I have checked…" once. Tegh then saves and approves each rate and creates its CGST + SGST and IGST codes.
- **B2C (large) limit:** suggested at ₹1,00,000.

**Payroll Set-up:** one screen with three steps.
1. Tick EPF, ESIC and Professional Tax (suggested figures, all editable) and confirm once.
2. Add employees: name, joining date, PAN, and Basic, HRA and Special allowance.
3. Open Pay Runs.

**Decided by the owner:** Tegh suggests rates and the user confirms them. Before this release Tegh supplied no rates. The rules record "Confirmed by <email> in … set-up (suggested by Tegh)", and India Masters keeps every advanced option.

**Fixed along the way:**
- **India screens refused as "not valid JSON":** eleven actions failed this way: Save structure, pay run Calculate, Post and Reverse, TDS Record and Reverse, GSTR-2B import, and GST return periods. They now send their data correctly.
- **Two companies created at the same moment:** they could deadlock, and one failed. Company creation now retries.

## 2. Full gate (automated, fresh install of this exact ZIP)
| Result | Count |
|---|---|
| PASS | 1233 |
| INFO | 13 |
| FAIL | 6 |

**The six failures** are the known ones (since R186): BS-07, BS-09, PW-01, WR-09, DI-SCAN and DD-01.

**Other checks:**
- FILE-MANIFEST: 452 entries OK;
- ZIP entries: all 0644;
- path and header checks: 45 PASS, 0 FAIL;
- E2E: 53/53;
- the 20 UI runs: no screens with issues.

**Suites:**

| Suite | Result |
|---|---|
| `74-r196` (new) | 7/7 |
| `73-r195` | 3/3 |
| `72-r194` | 5/5 |
| `71-r193` | 7/7 |
| `29-r161` (large companies) | 20/20 |
| `65-in3` | 13/13 |
| `66-in4` | 14/14 |
| `67-in5` | 10/10 |
| `68-in6` | 12/12 |
| `69-in7` | 7/7 |

**The first R196 build** (SHA-256 `628283ca…`, in `gate-first/`) had three more failures: R161 S-01 and its large-company UI run, and R193 UI-03.
- **Cause:** the two large-company seeds run in parallel. Their company creations deadlocked in the database, so one company was not created.
- **Reproduced:** 2 failures in 80 companies created 16 at a time.
- **After the fix:** 160 of 160, every company complete. The fix is in this build, and suite `74-r196` (R196-02) checks it.

## 3. `74-r196` checks
- **UI-01:** GST Set-up from the screen. Nothing is saved until confirmed; then the three rules are approved and name the person, the codes GST5, IGST5, GST18 and IGST18 exist, and GST18 is CGST 9% + SGST 9%.
- **R196-01:** through the API:
  - unconfirmed is refused (422);
  - repeating changes nothing;
  - a different EPF figure from the same date is refused (409);
  - a Canadian company gets 404.
- **R196-02:** 48 companies created 16 at a time; all created, each with one creation audit entry.
- **UI-02:** Payroll Set-up from the screen:
  - two employees with the monthly gross shown as typed;
  - ESIC and PT ticked after the first employee was added;
  - the standard components created.
- **UI-03:** a September pay run calculated from the Pay Runs screen:
  - EPF ₹1,800 on ₹20,000 Basic (ceiling ₹15,000);
  - ESIC ₹0 on ₹30,000 gross, and ₹98 / ₹423 on ₹13,000;
  - PT ₹200;
  - net ₹28,000 and ₹11,622.
- **UI-04:** Save structure from the Salary Structures screen. Before this release it was refused.
- **UI-05:** both screens at 390 px, no overflow.

## 4. Light and dark themes
The audit sweep of the two new screens found no issues (contrast, light panels, overflow, errors) in:
- light and dark themes;
- 1440 px and 390 px.

## 5. Not done
- **Professional Tax:** a single monthly amount per state. State slabs by salary are still entered in India Masters.
- **TDS sections:** keep the India Masters route; there is no short screen for them.
- **Canadian tax codes:** not changed.

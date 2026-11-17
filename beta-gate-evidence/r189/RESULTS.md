# R189 amount-based approval limits removed (Canada and India): results

Package: `Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R189-PRIVATE-BETA.zip`, release token `5990-r189-limits`. The SHA-256 is in section 5.

All of this was run on the test server (gate.test) with synthetic companies. **Nothing was run on the IONOS server.**

## 1. What changed in this package

See `tegh-app/R189-CHANGES.md`. In short:
- Journals, vendor invoices, voids, credit and debit notes, bank unposts and inter-bank transfers no longer wait for a second approver because of their amount. This applies to Canadian and India companies.
- **Requests that were already waiting** when the package is installed can still be approved by a second owner, and their maker still cannot approve them.
- **Setting `accounting.approval_limits_enabled`:** set to `true` in `config.php`, it brings the former 10,000.00 limit back. It is off by default.

## 2. Full gate (automated, fresh install from the R189 ZIP)

| Result | Count |
|---|---|
| PASS | 1148 |
| INFO | 13 |
| FAIL | 6 |

**The six failures** are the known ones, the same as R186 to R188: BS-07, BS-09, PW-01, WR-09, DI-SCAN and DD-01.

**Two more checks failed in the gate run** (`logs/run-all.log`). Both were faults in the tests, not in the product. Each was fixed in the test and the suite was run again on the same R189 installation:
- **IN2-05 (`63-in2`): test data.**
  - **Cause:** the check takes a vendor of a Canadian company to prove that the India company cannot reach it, but the Canadian company it picked has no vendors. The request therefore had no record id, and the answer was 400 instead of 404.
  - **Fix:** the test now picks any vendor of a Canadian company.
  - **Re-run:** `63-in2` 11 of 11 PASS (`logs/in2.json`).
- **IN-09 (`21-r152`): this failure also happened in R187.**
  - **Cause:** the Document Intake review screen shows one document at a time. The test chose the document by its file name, but earlier gate runs had left several documents with the same name, so it opened the wrong one and waited for a card that stayed hidden.
  - **Fix:** the test now chooses the document by its id.
  - **Re-run:** `21-r152` 31 of 31 PASS (`logs/rerun-21-r152.log`, `logs/r152.json`).

**The R189 suite:**
- It ran in the gate, but its results were not written to the evidence folder because the suite lacked the save call.
- The save call was added and the suite was run again on the same installation: `64-r189` 10 of 10 PASS (`logs/r189.json`).
- The totals above include these re-runs.

**Other checks:**
- path and header checks: 45 PASS, 0 FAIL;
- UI runs: 20, with no screens showing issues;
- swipe traps at 390×844: 0.

The gate ran on exactly the delivered ZIP (FILE-MANIFEST: all 439 entries OK).

### 64-r189: 10 of 10 PASS

| ID | Check | Result |
|---|---|---|
| RL-01 | Canada: a 25,000.00 journal (above the former 10,000.00 limit) posts at once; nothing waits | PASS |
| RL-02 | A 50,000.00 vendor invoice is issued and posted at once (status open, not sent for approval) | PASS |
| RL-03 | A 20,000.00 customer invoice is voided directly; the preview needs no second owner | PASS |
| RL-04 | A 15,000.00 customer credit note (refused above the former limit) is now posted | PASS |
| RL-05 | With the limits switched on (test configuration only), the journal, vendor invoice and void wait for approval | PASS |
| RL-06 | With the limits removed again, a journal that was already waiting is still approved by a second owner (its maker cannot) | PASS |
| RL-07 | The same for a vendor invoice that was already waiting | PASS |
| RL-08 | The same for a void that was already waiting | PASS |
| RL-09 | The same for a bank unpost that was already waiting | PASS |
| RL-10 | With the setting off again, a new 30,000.00 journal posts at once | PASS |

Also changed and passing:
- `60-r186` UP-09 now expects a large unpost to need no second owner (22 of 22 PASS).
- `62-in1` IN-06 now expects ₹25,000 to post at once (10 of 10 PASS).

## 3. Canadian before/after comparison (automated, test server)

**Method:** the R180 database snapshot (68 companies) was restored and captured with the R189 code, then compared with the R188 capture (`comparison/r189-vs-r188-report.json`).

**Result: 0 differences in 592 compared files, and no status changes.**

**Approval limits are not part of these figures:** the capture reads reports and screens, so the removed limits do not change them. The removal itself is tested by `64-r189` above.

**Screens not compared:** screens are captured for three companies per run, and the third one differed between the runs.
- **6651d0…:** screens captured by R188 only.
- **c868ff…:** screens captured by R189 only.

Their 34 screen files (17 each) could not be compared. API data for all companies was compared.

## 4. Not covered

- **IONOS:** no deployment or tests there.
- **Waiting approvals on IONOS:** existing waiting requests on IONOS were not looked at. The tests only show that such requests stay approvable on the test server.

## 5. Package

- **SHA-256:** `e1a6808285b8f6e20fbf23307b8279493f76b48c232938753b181957bfae1e71`
- **Size:** 440 files.
- **FILE-MANIFEST:** all 439 entries OK.

# R186 Unpost, edit and delete in Match and Post: results

Package: `Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R186-PRIVATE-BETA.zip` (SHA-256 in section 6).

Everything here was run on the test server (gate.test) with synthetic companies. **Nothing was run on the IONOS server.**

## 1. Full gate (automated, fresh install from the R186 ZIP)

| Result | Count |
|---|---|
| PASS | 1090 |
| INFO | 13 |
| FAIL | 6 |

- **Failures:** the 6 are the same known ones as R185: BS-07, BS-09, PW-01, WR-09, DI-SCAN and DD-01.
- **Increase in PASS:** R185 had 1073 PASS. The 17 extra are the new suite `60-r186`.

Other checks:
- Path and header checks: 45 PASS, 0 FAIL.
- UI runs: 20, with no screens showing issues.
- Swipe traps at 390×844: 0.

Logs: `logs/run-all.log`, `logs/tail-run.log`, `logs/gate-r186.out`, `logs/r186.json`.

The gate ran on a package whose `R186-CHANGES.md` said "16 checks". The delivered package corrects this to 17, and that line is its only difference from the tested package.

## 2. Suite 60-r186 (automated, inside the gate)

| ID | Check | Result |
|---|---|---|
| UP-01 | Posted to an account: Unpost takes the entry out of every figure, keeps it in the Day Book and returns the line to Unposted | PASS |
| UP-02 | An unposted line posts again; a retried request with the same key changes nothing more; a pending line cannot be unposted | PASS |
| UP-03 | Line posted as an invoice receipt, paid in full: the receipt is reversed and the invoice is open for its full amount | PASS |
| UP-04 | Same, invoice paid in part: the invoice returns to its earlier balance | PASS |
| UP-05 | Payment of a bill: Unpost reopens the bill and Accounts Payable | PASS |
| UP-06 | On-account receipt: reversed. When the receipt was later applied to an invoice, Unpost is refused | PASS |
| UP-07 | Line matched to an earlier payment: only the link is removed; the payment stays posted and the invoice stays paid | PASS |
| UP-08 | After all of the above, the receivable ageing total equals the Accounts Receivable balance and the trial balance balances | PASS |
| UP-09 | Above the approval limit (10,000.00): the request waits; its maker cannot approve it; a second Company Owner approves and the line is unposted | PASS |
| UP-10 | A line in a closed period cannot be unposted; it stays posted and the books do not change | PASS |
| UP-11 | An Editor and a Viewer cannot unpost (as with Void); the line is unchanged | PASS |
| UP-12 | The preview changes nothing | PASS |
| UI-01 | 1440 px: Actions ▾ is hidden until the line is selected; on a posted line it offers only Unpost; the Unpost dialog returns the line to Unposted | PASS |
| UI-02 | Actions ▾ on an unposted line offers Edit and Delete; Edit changes the date, direction and amount, and the audit history keeps the old values | PASS |
| UI-03 | Ticking a line shows its Actions ▾; Delete removes the line after confirmation; no journal changes | PASS |
| UI-04 | 390 px: Actions stays inside its row, its menu opens on screen, and the page has no sideways scroll | PASS |
| UI-05 | Bank review queue: an unposted line shows Edit and Delete beside Post & Next; a posted line shows Unpost, which opens the same preview dialog | PASS |

Suite `57-r183` (Match and Post layout) passed 5 of 5. Its check BT-05 used to look at R182's ✎ pencil. The pencil is now the Actions ▾ menu, so BT-05 checks that button's placement instead. The updated test is `tests/57-r183-updated.mjs`.

Screenshots (synthetic company): `shots/r186-*.png`.

## 3. Pre-existing bug found and blocked

**The bug (before R186):** Void on a bank line that had been posted as an invoice receipt reversed the bank entry but left the invoice marked paid. Accounts Receivable in the GL then disagreed with the ageing.

- Reproduced on the test server with `tests/r186-probe.mjs`.
- R186 refuses that Void and points to Unpost.
- Companies may already hold lines voided this way. `R186-CHANGES.md` says how to find them.

## 4. Canadian before/after comparison (automated, test server)

**Method**
- Restored the R180 database snapshot (68 companies).
- Captured the reports and screens with the R186 code.
- Compared them with the R180 capture and the R185 capture.

**Against R180**
- 592 files compared; 0 status changes.
- 2 files differ: the Bank Transaction Report for two companies. This is the R183 change (running balance, date order); R184 and R185 showed the same 2 differences.

**Against R185**
- 592 files compared, **0 different**.

**Companies the full capture skipped**

The capture skipped 04733e… and c656d1…, so each was captured on its own:

| Company | Result |
|---|---|
| 04733e… | All 17 screens are identical to the R185 capture. Against R180, only the Bank Transaction Report differs (the same R183 change). |
| c656d1… | All 17 screens match R185 except the "Support reference" line on 11 of them. These pages say "Choose a company first" and each load gets a random reference. |

**A company with no earlier capture**

This run also captured 483f37…, which neither the R180 nor the R185 capture reached. It has no earlier capture to compare with.

Files: `comparison/`.

## 5. Not covered

- **IONOS:** no deployment or tests there.
- **On-account posting for new companies:** a new company has no Accounts Receivable mapping in `company_system_accounts`, so on-account posting fails for it. This gap is pre-existing and R186 does not change it. The tests add the mapping to their synthetic company.
- **Earlier wrong Voids:** lines voided before R186 in the way described in section 3 are not corrected automatically.

## 6. Package

- SHA-256 `df6a9eda827edb2813ea2e241bfb7e5b7a0414a0ca88e69cad2a125ffbd35d92`; 435 files; FILE-MANIFEST: all entries OK.
- It differs from the gate-tested package (SHA-256 `daf946184af3afffbf804be6fd1819a1b1f72c40c2b2c7c2e806db83ce84d6f6`) only in `R186-CHANGES.md` (16 → 17 checks) and that file's line in `FILE-MANIFEST.sha256`.
- FILE-MANIFEST is checked with `sha256sum -c` before packing.

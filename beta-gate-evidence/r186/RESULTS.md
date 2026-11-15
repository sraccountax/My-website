# R186 Unpost, edit and delete in Match and Post: results

Package: `Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R186-PRIVATE-BETA.zip`, release token `5990-r186-unmatch` (SHA-256 in section 7).

Everything here was run on the test server (gate.test) with synthetic companies. **Nothing was run on the IONOS server.**

## 1. What changed in this package

- One **Actions ▾** menu in Match and Post, shown on the selected line. An unposted line offers Edit and Delete; a posted line offers Unpost.
- **Lines linked to a customer invoice or vendor bill** (posted as an invoice receipt or bill payment, matched to such a receipt or payment, or an on-account receipt later applied to an invoice) cannot be edited, deleted, unposted or voided. The server refuses these actions, and the menu shows a note in their place.
- **Company Owner correction.** Only a member whose company role is Owner (not Admin) sees **Correct with journal entry** on a linked posted line. It uses the Unpost reversal path:
  - the receipt or payment is reversed by a journal entry dated on the statement date;
  - the invoice or bill is open again;
  - the line returns to Unposted.
  - Nothing is deleted. The audit record carries `ownerCorrection: true` and names the invoice or bill.
  - Above the approval limit, a second Company Owner must approve it.
  - For a line matched to a receipt or payment entered in Payments, only the link is removed. The payment stays posted and the invoice stays paid.
  - An on-account receipt that was applied to an invoice must first be unapplied in Payments.

- **Actions ▾ on every row (R186b).** The button now shows on every line, not only the selected one.
- **Unmatch (R186b).** A line matched to an entry in the books offers **Unmatch** in its Actions ▾ menu to the Company Owner or an Admin. It uses the existing Bank Reconciliation unmatch service: nothing is deleted, any reconciliation adjustment entry is reversed on the unmatch date, the reason is audited, and a match inside a completed reconciliation is refused until that reconciliation is reopened. Editors and Viewers see a note instead. A line in a completed reconciliation shows a note saying to reopen it first.

## 2. Full gate (automated, fresh install from the R186 ZIP)

| Result | Count |
|---|---|
| PASS | 1094 |
| INFO | 13 |
| FAIL | 6 |

- **Failures:** the same 6 known failures as R185: BS-07, BS-09, PW-01, WR-09, DI-SCAN and DD-01.
- **Increase in PASS:** R185 had 1073 PASS. The 21 extra are the new suite `60-r186`.

Other checks:
- Path and header checks: 45 PASS, 0 FAIL.
- UI runs: 20, with no screens showing issues.
- Swipe traps at 390×844: 0.

The gate ran on exactly the delivered ZIP (FILE-MANIFEST: all 434 entries OK).

Logs: `logs/gate-r186f.out`, `logs/run-all.log`, `logs/tail-run.log`, `logs/r186.json`.

## 3. Suite 60-r186 (automated, inside the gate): 21 of 21 PASS

The tests use an Owner and a separate Admin member of the same synthetic company.

| ID | Check | Result |
|---|---|---|
| UP-01 | Posted to an account: Unpost takes the entry out of every figure, keeps it in the Day Book and returns the line to Unposted | PASS |
| UP-02 | An unposted line posts again; a retried request with the same key changes nothing more; a pending line cannot be unposted | PASS |
| UP-03 | Invoice paid in full. The Admin's Unpost and Void are refused and nothing changes. The Owner's correction reverses the receipt on the statement date (2026-09-12). The invoice is open for its full amount; Accounts Receivable and the bank return to their earlier balances; the audit record shows an owner correction. The line posts again afterwards | PASS |
| UP-04 | Same, invoice paid in part | PASS |
| UP-05 | Bill payment: the Admin is refused ("vendor bill"); the Owner's correction reopens the bill for its full amount and restores Accounts Payable | PASS |
| UP-06 | On-account receipt (no invoice) is not linked, and an Admin can unpost it. Once applied to an invoice it is linked: the Admin is refused, and the Owner is told to unapply the allocation first | PASS |
| UP-07 | Matched to an earlier receipt in Payments: the Admin is refused; the Owner's correction removes only the link; the receipt stays posted and the invoice stays paid; no journal changes | PASS |
| UP-13 | Edit and Delete of a linked unposted line are refused even for the Owner, and the line is unchanged; once unlinked, Delete works. The link is made directly in the test database, because Tegh's screens never leave an unposted line linked | PASS |
| UP-08 | After all of the above, the receivable ageing total equals the Accounts Receivable balance and the trial balance balances | PASS |
| UP-09 | Above the approval limit (10,000.00): the request waits; its maker cannot approve it; a second Company Owner approves and the line is unposted | PASS |
| UP-10 | A line in a closed period cannot be unposted; it stays posted and the books do not change | PASS |
| UP-11 | An Editor and a Viewer cannot unpost (as with Void); the line is unchanged | PASS |
| UP-12 | The preview changes nothing | PASS |
| UI-01 | 1440 px: Actions ▾ shows on every line without selecting it; a posted line offers only Unpost; linked lines show the note and no actions; the Unpost dialog returns the line to Unposted | PASS |
| UI-02 | Actions ▾ on an unposted line offers Edit and Delete; Edit changes the date, direction and amount, and the audit history keeps the old values | PASS |
| UI-03 | Ticking a line shows its Actions ▾; Delete removes the line after confirmation; no journal changes | PASS |
| UI-04 | 390 px: Actions stays inside its row, its menu opens on screen, and the page has no sideways scroll | PASS |
| UI-06 | Owner, linked line: the menu offers only Correct with journal entry. The dialog shows "Correct with a journal entry?", its effects and "Record correction". Submitting it reopens the invoice and returns the line to Unposted | PASS |
| UI-07 | A matched line: the Owner's Actions ▾ offers only Unmatch, without selecting the line. The dialog removes the match; the line returns to Unposted and then offers Edit and Delete. No journal, trial balance or bank balance change; the audit record is written. An Editor's unmatch request is refused by the server (403) | PASS |
| UI-08 | An Editor sees the matched line with the note "Only the Company Owner or an Admin can unmatch it" and no Unmatch button | PASS |
| UI-05 | Bank review queue: an unposted line shows Edit and Delete beside Post & Next; a posted line shows Unpost, which opens the same preview dialog | PASS |

Tegh's button styling title-cases the label, so the screen shows "Correct with Journal Entry". UI-06 compares the label without regard to case.

Suite `57-r183` (Match and Post layout) passed 5 of 5. Its check BT-05 used to look at R182's ✎ pencil. The pencil is now the Actions ▾ menu, so BT-05 checks that button's placement instead. The updated test is `tests/57-r183-updated.mjs`.

Screenshots (synthetic company): `shots/r186-*.png`, including `r186-linked-note-1440.png` (note on a linked line), `r186-correct-dialog-1440.png` (Owner correction dialog), `r186-unmatch-menu-1440.png` and `r186-unmatch-dialog-1440.png`. The unmatch dialog screenshot was taken while the dialog was still fading in, so the page behind shows through.

## 4. Pre-existing bug found and blocked

**The bug (before R186):** Void on a bank line that had been posted as an invoice receipt reversed the bank entry but left the invoice marked paid. Accounts Receivable in the GL then disagreed with the ageing.

- Reproduced on the test server with `tests/r186-probe.mjs`.
- R186 refuses Void on any linked line. A line posted as an on-account receipt is pointed to Unpost instead.
- Companies may already hold lines voided this way. `R186-CHANGES.md` says how to find them.

## 5. Canadian before/after comparison (automated, test server)

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
| c656d1… | All 17 screens match R185 apart from the "Support reference" line on 11 of them. These pages say "Choose a company first", and each load gets a random reference. |

**A company with no earlier capture**

This run also captured 6cc4e6…, which neither the R180 nor the R185 capture reached. It has no earlier capture to compare with.

Files: `comparison/`.

## 6. Not covered

- **IONOS:** no deployment or tests there.
- **Admin unmatch:** the tests unmatch as the Owner and check that an Editor is refused. An Admin is allowed by the existing server rule (the same one Bank Reconciliation uses), but no test unmatches as an Admin.
- **On-account posting for new companies:** a new company has no Accounts Receivable mapping in `company_system_accounts`, so on-account posting fails for it. This gap is pre-existing and R186 does not change it. The tests add the mapping to their synthetic company.
- **Earlier wrong Voids:** lines voided before R186 in the way described in section 4 are not corrected automatically.

## 7. Package

- SHA-256 `0238672af1d27bdb89cf534aa5f21d068405b1fd68c2488becc2cb82e4ec59f2`; 435 files; FILE-MANIFEST: all 434 entries OK.
- The full gate in section 2 ran on this exact file.
- FILE-MANIFEST is checked with `sha256sum -c` before packing.

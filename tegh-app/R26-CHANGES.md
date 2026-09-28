# Tegh R26 — workflow continuity, report controls, interbank clearing and ageing

R26 is a cumulative staging candidate built from the exact R25 package. It keeps Schema 46 and does not require a new database migration.

## User-requested batch

| Request | R26 implementation | Verification |
|---|---|---|
| Bank Review should advance from the processed row to the next transaction, not restart at the first unposted row | The queue records the preferred next transaction before the authoritative refresh and restores that selection after posting/matching. If transaction 5 is processed while 1–4 remain, transaction 6 is selected. | Browser workflow test uses 10 rows and verifies 5 → 6 while rows 1–4 remain. |
| Bank Reconciliation should use the same continuity | The bank-side reconciliation list retains the processed row's successor across match/post refreshes. | Browser workflow test verifies the next unmatched bank row is selected rather than the first row. |
| User-defined report/register column order | Shared read-only registers support drag-and-drop header ordering plus accessible Move left/Move right controls in Choose columns. Saved order is scoped to the existing user/company/report preference key and exports follow the chosen order. | Browser test reorders columns, reloads the renderer and verifies persisted display/export order. |
| Dashboard Quick Actions should scroll | The Quick Actions list owns a bounded vertical scroll region rather than clipping or displacing the dashboard. | Wheel/overflow browser checks. |
| Remove verbose transfer draft sentence | Removed. Transfer help now explains the two safe recording approaches without that sentence. | Source contract search. |
| Put From/To and Bank/Credit labels beside their controls | Shared ActivityShell marks From, To, Bank Account, Bank / Credit Account and Current Bank / Credit Account controls for inline layout after toolbar movement. | Browser checks on populated Bank Review; responsive CSS retains a safe narrow reflow. |
| Optional standard Interbank Transfer Clearing GL account | New companies include standard account **1070 — Interbank Transfer Clearing**. Existing companies are not changed automatically; the Transfers workspace offers an explicit **Add clearing account** action after user confirmation. Users may instead keep using the linked-transfer workflow. | Source contract and browser workflow tests. |
| Invoice Actions must be a dropdown and not expand the invoice row | Row Actions remains a dedicated dropdown. More details is a separate control; opening Actions closes any open detail row and does not toggle it. | Browser register test. |
| Bank Reconciliation rows are too tall | Default row is a compact one-line record; evidence/reference detail is inside an explicit disclosure. | Browser compact-row/detail test. |
| Remove long as-of reconstruction wording | The legacy subledger disclosure renderer no longer returns that paragraph. | Source contract search. |
| Ageing for a specific customer/vendor | Receivable and payable ageing now include All / specific-party selection. Server queries validate the party belongs to the selected company and filter both ordinary documents and opening balances. | Source contracts plus browser request/result test. |
| Day Book should be collapsed by entry, with Expand all / Collapse all | Journal groups start collapsed. Each group is individually expandable and a View menu provides Expand all and Collapse all. | Browser tests cover default collapse, one group, expand all and collapse all. |

## Interbank clearing accounting choice

The optional clearing account is a normal asset account that a user can deliberately use when recording the two bank legs separately. A transfer out can credit the source bank and debit the clearing account; the receiving leg can debit the destination bank and credit the same clearing account. When both correctly recorded legs are present, the clearing balance nets to zero. Tegh does **not** force this method and does not automatically modify an existing company's chart of accounts. The existing linked-transfer workflow remains available; users should not combine both methods for the same transfer.

## Shared layout changes

R26 also adds the responsive styling needed for compact reconciliation rows, inline scope labels, draggable table-header affordances, Day Book group controls, optional transfer-method cards and dashboard Quick Actions scrolling. Narrow layouts may reflow where keeping labels inline would reduce readability or access.

## Functional integrity and boundaries

- No SQL schema file changed from R25; schema remains 46.
- The R21 manual opening-balance posting function is byte-identical to the R25 baseline.
- R26 changes ageing read/filter PHP and new-company default chart setup; these require authenticated MariaDB staging verification even though local source and browser checks pass.
- The broad module audit verifies declared actions can navigate/render and that overflowing task regions remain reachable. It is not a claim that all 102 actions posted financial transactions end-to-end.
- No IONOS upload, hosted sign-in, production transaction or actual SMTP delivery was performed while building R26.

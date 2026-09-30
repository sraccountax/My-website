# R138 changes over R137: tax codes in bank posting, and Canadian starter codes

5.9.9 / Build 5990 / Schema 46. Cache token `5990-r138-tegh`. No manual migration.
Status: **staging candidate**, `productionReady: false`. The host acceptance items are still open.

## 1. Tax code picker when posting bank transactions
In tax-code mode, all three bank posting screens offer the company's tax codes instead of the fixed "GST/HST / PST / GST/HST + PST" choices:
- Banking → Match and Post Transactions, for one line or several selected lines.
- The transaction review panel.
- Guided review.

Each option reads, for example, "QC · Quebec GST + QST (14.975%) · tax included". "No tax" is still available.

The bank amount is treated as **tax included**:
- **Deposits:** each tax goes to its sales GL account; income gets the net.
- **Withdrawals:** recoverable taxes go to their purchases GL accounts, and non-recoverable taxes (for example BC PST) are added to the expense.

The posting preview shows every tax and its GL account before you post. The saved transaction shows the code used (for example `CODE:QC`). Companies still on the built-in rules see the old choices.

**Server changes:**
- The bulk posting contract accepts `taxCode: "CODE"` with a `taxCodeId`, and refuses `CODE` without a code.
- The stored tax label follows the same rule as posting.

## 2. Canadian starter tax codes
**New companies** created with the Default chart now get a tax code for every province and territory, plus a GST-only code, using the default tax accounts:

| Code | Taxes | Sales GL | Purchases GL |
|---|---|---|---|
| AB, NT, NU, YT | GST 5% | 2100 | 1100 (recoverable) |
| ON | HST 13% | 2100 | 1100 |
| NS | HST 14% | 2100 | 1100 |
| NB, NL, PE | HST 15% | 2100 | 1100 |
| BC | GST 5% + PST 7% | 2100 / 2110 | 1100 / PST not recoverable (cost) |
| MB | GST 5% + RST 7% | 2100 / 2110 | 1100 / RST not recoverable (cost) |
| SK | GST 5% + PST 6% | 2100 / 2110 | 1100 / PST not recoverable (cost) |
| QC | GST 5% + QST 9.975% | 2100 / 2110 | 1100 / 1110 (QST recoverable) |
| GST (no province) | GST 5% | 2100 | 1100 |

- Every province code is linked to its province, so customers there get it automatically on invoices.
- The codes are marked as starter codes and can be **edited, deactivated or deleted** like any other code. The one-code-per-province rule still applies.
- Companies created with "Start Empty" have no tax accounts yet, so no codes are created for them. They can add accounts and use the button below.
- The registration form and the Tax Codes page explain this.

**Existing companies:** the Tax Codes page has an **Add Canadian tax codes** button. It adds codes only for provinces that don't have one yet. For a company still on the built-in rules, it first asks for confirmation, because adding codes switches the company to tax codes.

## Verified (local: scratch MariaDB, PHP 8.4, Playwright Chromium)
- **R138 database test:** 22/22 pass.

  | Scenario | Result |
  |---|---|
  | New company | 13 province codes + GST-only, with the rates and GL mapping above |
  | Ontario invoice | HST 13% |
  | Quebec invoice | GST → 2100, QST → 2110 |
  | Second code for Quebec | Refused |
  | Starter code | Editable |
  | Seeding again | Adds nothing |
  | Deposit $1,149.75 with QC | Income $1,000, GST $50, QST $99.75 |
  | Withdrawal with BC | ITC $50, PST $70 in cost |
  | Withdrawal with GST only | ITC $50 |
  | Withdrawal with no tax | Whole amount to expense |
  | Bulk route: Ontario deposit | HST $130 |
  | Bulk route: Quebec purchase | ITCs $50 + $99.75 |
  | `CODE` without a code | Refused |
  | Start Empty chart | No codes |

- **Earlier suites:** R137 27/27 and 9/9, R136 13/13 and R135 21/21 still pass. These tests remove the starter codes first, or run companies in legacy mode.
- **Browser:**
  - **Match and Post:** lists every code. The BC preview shows GST $5.00 recoverable and $107.00 to the expense, and posting it recorded `CODE:BC` with the same lines.
  - **Review panel:** lists the codes.
  - **Tax Codes page (existing legacy company):** "Add Canadian tax codes" asked for confirmation, then added 14 codes and switched the company.
- **Regression:** see RELEASE-MANIFEST.json `executedLocalGates`.

## Still open
- **Tax summaries:** the GST/HST Summary report and the dashboard tax card read only 2100/1100/2110/1110. The starter codes use exactly these accounts, so they are covered; codes you map to other accounts are not yet.
- **Rates:** starter codes use general rates as of this release. Review them against CRA and provincial guidance, and against your own registrations. For example, delete or deactivate the PST codes if you are not registered for PST.

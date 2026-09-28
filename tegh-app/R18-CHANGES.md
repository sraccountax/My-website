# Tegh 5.9.9 — R18 Premium UI and Opening Balances

R18 builds on the exact R17a invoice-posting hotfix. It does not change the product version, build number or Schema 45 database target.

## Premium workspace

- The authenticated application, stage, page and page body are constrained to the browser viewport. The window and workspace canvas do not scroll horizontally or vertically.
- Accounting tables and transaction lists own vertical scrolling. Their headers remain visible with an opaque background, subtle borders and compact zebra-striped rows.
- Page headings, cards, filters, inputs and actions use a denser Inter-based visual system while preserving readable labels, focus indicators and touch targets.
- Report, customer, vendor and bank export actions are consolidated into one top-right Export menu. Existing authorized export handlers are moved, not copied or bypassed.
- The dashboard places Cash, AR, AP and Equity cards above the Cash Forecast and Profit and Loss panels, with a persistent right-side Quick Actions rail on desktop.
- Bank Reconciliation retains a fixed control strip and side-by-side Bank Statement and Books panels. Each transaction list scrolls independently and exact matches retain their green visual cue.

## Manual Opening Trial Balance

- Eligible General Ledger accounts can be searched, filtered and edited directly without importing a file.
- Each changed amount is saved as a company-scoped draft. The screen shows dirty, saving, saved and error states and waits for every pending save before posting.
- Live debit, credit and automatic 9999 offset totals remain visible beside the explicit **Post Opening Balances** action.
- Posting requires an owner or administrator, a configured Start of Books date and the explicit `post` action.
- The server revalidates every saved draft and its active account state at commit time. Opening Balance Control 9999 is automatic; Accounts Receivable 1200 and Accounts Payable 2050 remain subledger-controlled; newly protected control accounts cannot be posted through stale drafts.
- The opening journal is dated at Start of Books, receives a voucher, appears in Day Book and clears the posted drafts atomically.

## Deployment

Deploy the complete flat-root package. Do not upload only the changed files. Back up the current site and database first, upload to a clean staging directory, keep `api/.htaccess`, then complete the authenticated smoke and accounting-control checks before promoting it.

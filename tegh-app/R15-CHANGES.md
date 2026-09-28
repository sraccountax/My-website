# Tegh 5.9.9 · Build 5990 · Schema 45 · R15

R15 is a cumulative update to the uploaded R14 package (SHA-256 `87e1cf899729d26769d2610afc5fe9ad6b7058526b01d6c9b0ab3233390fc33c`). It keeps the earlier accounting, import, concurrency, report and Bank Review repairs.

## Changes

- Settings → Opening Trial Balance accepts manual amounts against individual GL accounts. Posting waits for pending saves and blocks if a save failed. Customer/vendor controls remain in their subledgers.
- Cash Forecast opens its own compact page, with a start date and 1–52 weeks. The dashboard forecast period is configurable under Settings → Dashboard & Reconciliation. Scenario creation has Cancel.
- Screen, print, PDF and Excel reports omit internal verification references, hashes, raw control totals and repeated framework/unaudited notices. The authorized report model and audit evidence remain intact. Accounting document numbers, currency, dates and actionable exceptions remain visible.
- Expense entry uses a landscape form; an empty imported-bank selector stays hidden. Saved expense drafts have a direct Post action.
- Account creation, people with access and setup history have distinct panels. Appearance shows a live sample of navigation position, text size and theme.
- Vendor invoice extraction recognizes labelled supplier identity, tax registration, invoice total, amount payable, tax lines and product/service lines. Review can correct the extracted lines. Only reviewed vendor associations enter the existing company-specific learning; extraction never posts.
- Agent Center is under Settings → Advanced settings.
- Reconciliation has compact bank/date filters, All Accounts, Unmatched/Matched/All tabs, exact-source highlighting with a text legend, pane scrolling and Find/Match/Post actions. Find contains best-match and related-entry choices. Preview is a setting. Posting uses the existing bank posting endpoint; specialized transfers and invoice allocations remain available through Full review.
- Unmatching validates both original statement dates and original book dates against locked periods. It retains the original accounting entries. Completed statement reconciliations still retain their backend protection.
- Saved downloads have dismissible notifications and private user/company storage under Settings → Requested Downloads. Generated files remain downloadable immediately if archiving fails; the notification explains that no saved copy exists. The existing server upload limit is 10 MB per saved copy. Existing downloads from before R15 cannot be recreated automatically.
- Dashboard quick actions support ten choices, display titles only and occupy the right column on desktop. Financial summaries and expected cash occupy the left. Small screens stack the content.

## Validation and deployment

Consult the accompanying validation evidence for the exact package checksum, executed PHP/MariaDB/API/browser/output results, screenshots and remaining gates. Historical R14 evidence does not count as an R15 test. The user authorized the isolated private GitHub validation branch. Automated lab results do not substitute for IONOS acceptance or independent human accounting and security reviews.

R15 requires no additional schema migration over R14 Schema 45. Keep this candidate out of the live IONOS site until runtime validation is complete. For an eventual approved deployment, preserve the site's existing private configuration and storage and deploy the contents of the runtime ZIP to the application web root. The checkpoint ZIP contains development material and must not be uploaded to that web root. No production deployment has been performed.

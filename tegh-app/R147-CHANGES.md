# R147 changes over R146: owner's public-page update, home page at the site root

5.9.9 / Build 5990 / Schema 46. No application code, asset or database change; the cache token stays `5990-r146-tegh`.
Status: **staging candidate**, `productionReady: false`. The host acceptance items are still open.

## Public pages (from the owner's "R146 Marketing-Aligned" package)
- **Pages updated (17):** the home page, Product, Ask Tegh, Solutions, Pricing, Resources, Company, Security, Contact, Migration, Privacy, Terms, 404, and the four guides.
- **New content** describes features that already exist in the app:
  - credit and debit notes;
  - Tegh Intelligence;
  - Document Intake and the statement converter's balance checks;
  - configurable tax codes;
  - client viewing links;
  - advanced accounting;
  - the scope of Payroll Support (Canada outside Quebec, no SIN storage, no CRA transmission).
- **Privacy and Terms** are now version 2026-10-01. **They need owner/legal approval before invitations go out.**
- **Checks:**
  - Every claim was checked against the code, and every internal link and anchor resolves.
  - No new scripts were added.
  - All pages render on desktop and phone without errors.
  - The seven examples on the Ask Tegh page behave as described.
  - Details: `beta-gate-evidence/audit-r146-marketing/AUDIT-REPORT.md`.

## Fix: the site root opens the home page
Since the Sites R117 frontend, `https://books.sraccountax.ca/` was sent straight to `app.html` (`.htaccess`), so the public home page opened only at `/index.html`.

The root now shows the home page:
- the **Sign In** button and every emailed link (password reset, invitation, account setup) still go to `/app.html`;
- older links that carry sign-in details on the root (`/?invite=…`, `/?passwordReset=…`, `/?forceSignIn=…`) are still redirected to the app.

## Packaging fix (AUD-1)
The owner's package stored every file as `0600` (readable only by its owner), so an extracted install returned 403 everywhere.

R147 ships files as `0644` and folders as `0755`. The gate now reports any ZIP entry that is not `0644`.

# R118 changes over the audited R117 consolidated package

- api/companies.php: server-side guard on account changes. Posting-required control accounts (1100, 1110, 1200, 2000, 2050, 2100, 2110, 4000, 6800, 6850, 9999) and any is_control account can no longer be deleted, deactivated, renumbered or retyped through the API (rename, description and GIFI edits still work). Any account with journal lines can no longer change type.
- .htaccess: deny package and deployment metadata (README.txt, DEPLOYMENT-NOTES.txt, hash lists, R*-HOTFIX-CONSOLIDATION.txt, PACKAGE-MANIFEST.json, tegh-build.json). Confirm the live build from data-sr-build in app.html instead of tegh-build.json.
- Removed api/EXPECTED-EXISTING-SHA256.txt and api/REPLACEMENT-SHA256.txt (stale and publicly reachable). Root hash lists rewritten for this delta. tegh-build.json now matches PACKAGE-MANIFEST.json.
- Marketing pages: version stamp comment updated to 5.9.9 / Build 5990.

No migration. Not changed, needs your decision: Microsoft Clarity in app.html (privacy.html does not disclose it); hardcoded tax account codes in api/accounting_notes.php; robots/sitemap on the staging host.

## Invoice and note tabs (added in R118)
- assets/tegh-portal-v5990.js: customer and vendor invoice screens now share a two-tab strip with their credit and debit notes screen. Customer Invoice | Credit & Debit Notes, and Vendor Invoice | Supplier Credit & Debit Notes. Switching tabs asks before discarding a dirty form. The report registers and the customer invoice register are unchanged. Menu entries still open the same screens; the note screens still choose credit or debit inside the tab.
- assets/tegh-polish-r32.css: tab strip styles.
- Cache token r117-note-tax-party is now r118-doc-tabs in app.html, the gate and the preflight, so browsers fetch the new portal and CSS.

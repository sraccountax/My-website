# R21 — Manual opening-balance confirmation hotfix

## Read before uploading

This is an **incremental file overlay**, NOT a complete installation. Apply it only to the latest **Tegh 5.9.9 / Build 5990 / Schema 46 / R20 INVOICE-REPORTS-STAGING** package supplied in this conversation.

Expected baseline deployment ZIP SHA-256:
`aec6c2872d9fb1460774cc804bcc412b1c1bac23ad9728d62d7a72b6ca217dc2`

There was also a different R20/Schema45 package earlier. **Do not apply this overlay to that package, R18/R19, or a separately modified/newer deployment.** The two R20 packages are not interchangeable. Check `tegh-build.json` for revision/schema and match the source package before replacement.

## Apply on staging

1. Wait for any manual opening-balance edits to show **All changes saved**. Back up the existing files being replaced. Do not delete or re-enter saved balances merely to apply this fix.
2. Extract this ZIP locally. Upload its contents into the existing Tegh application root (the directory that contains `app.html`), preserving the `assets/` subfolder, and overwrite only these supplied paths. **Do not delete the existing website or replace the entire assets directory.** This ZIP does not contain the rest of the application.
3. Leave PHP, database tables, private configuration, stored documents and other files unchanged. There is **no database migration from the specified R20/Schema46 baseline**.
4. Reopen the Tegh tab and hard-refresh (`Ctrl+F5` on Windows). Loader requests now use `5990-r21-obfix`, so the corrected portal is not served from the old R20 asset cache.
5. Open **Opening Trial Balance**. Verify your saved amounts and selected company/date. Choose **Post Opening Balances**, review the totals, then choose **Confirm & Post** once. A successful server response refreshes the screen and shows the voucher reference and **View Day Book**.
6. Verify the resulting entry in Day Book and the Trial Balance on staging before treating the host as accepted. If the result is uncertain or already posted, use **Refresh posting status** and inspect the existing entry; do not repeatedly resubmit.

`tegh-build.json` identifies revision `R21`, schema `46`, and cache revision `5990-r21-obfix` after the overlay. If server shell access is available, `sha256sum -c FILE-MANIFEST.sha256` verifies all managed application files, not private runtime documents/configuration.

## Scope

The manual opening page now awaits the in-app confirmation directly instead of replaying a synchronous `confirm()` across an asynchronous save boundary. It guards the full save/review/post operation against repeated clicks, keeps cancellations and errors from losing entered values, validates non-finite amounts before JSON serialization, and does not retry an ambiguous posting result.

The existing company-scoped, CSRF-protected posting API is unchanged. All 74 PHP source files and all SQL source files are byte-identical to the specified R20 baseline. Payroll, invoice Send/Edit/Attachments and comparative P&L backend source are not modified. No new schema upgrade is introduced.

## Validation boundary

36 targeted Chromium scenarios passed, along with 52 retained component checks and 10 source/cache/syntax checks. The original loop was reproduced separately: three approvals opened a fourth dialog and sent zero posting requests. Tests load actual portal/dialog/API-wrapper code with fictional authentication, core-shell and network responses. These are not authenticated IONOS, real-database posting, ledger correctness or database concurrency tests. No deployment, real posting or live schema upgrade was performed.

## Rollback

Restore the backed-up R20 copies of the changed files and remove this newly added guide. No database rollback is needed for this frontend-only overlay. **Do not attempt to undo an opening journal by restoring frontend files**; any actual accounting correction must use the existing controlled accounting workflow.

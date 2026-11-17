# R158 changes over R157: company deletion, and the host backup per the R157 review

5.9.9 / Build 5990 / Schema 46. Cache token `5990-r158-tegh`.
Status: **private beta candidate** (invitation-only, sample data only). `productionReady: false`.
Database: no change.

## Deleting a company works again
**What went wrong.**
- Every deletion was refused: "Company deletion cannot continue because the current database structure contains an unsupported dependency" (503).
- Before deleting, Tegh compares its list of company tables with the live database. Since R133, fifteen tables had been added without being put on that list, so every company failed the comparison. These included client viewing links, tax codes, credit and debit notes, vendor invoice lines, dashboard figures and onboarding.
- No gate test deleted a company, so this was not caught.

**The fix (api/operations.php).**
- The fifteen tables are on the list, in an order that deletes rows before the rows they refer to.
- **A company table that a later release adds is deleted automatically.** Tegh reads the live database, and deletes such a table before the listed ones, dependents first. It no longer blocks deletion.
- The safety check that remains: a table that **refuses** the deletion of the row it points to must come first. A table that refuses is still reported, and nothing is deleted.
- Client viewing sign-in codes and sessions have no company column. They are deleted through their viewing link.
- Platform records kept by design are not deleted: the incident log, and entitlement and usage records. Their company is cleared, as before.

**Unchanged:**
- only the company owner can delete;
- the owner types the company name and their password, and confirms the final backup;
- the deletion is logged with the record counts from before it.

**Gate test 26-r158 (5 checks).**
- A company is put in full use: onboarding, tax codes from a file, a customer invoice, a vendor invoice with taxed lines, a credit note, a client viewing link with a sign-in code and a session, and Quick Actions. Its owner then deletes it.
- The refusals leave every row in place: wrong name, wrong password, no backup confirmation, and a Company Admin.
- After the deletion, no row of the company is left in any table that has a company column. That list is read from the database, not from Tegh's list. The rows found through their parents are also gone: tax code components, vendor invoice lines, note lines, and viewing codes and sessions.
- The deletion log holds the counts, and other companies are untouched.
- In the browser: Account & Access › the company's **Actions › Delete**, type the name and password, confirm. The company is gone, and Tegh moves to another company.
- On R157 the same deletion returned 503. That run is kept as evidence.

## Host backup (beta-ops, in the Beta Kit): the two items of the R157 review
**1. Requests that were running are waited for, however long they take.**
- Each Tegh request now leaves a small marker file while it runs, in `storage/runtime/active-requests/` (api/bootstrap.php). The marker is removed when the request ends, also after an error.
- `tegh-backup.sh` sets the maintenance flag, then waits until no marker is left. This replaces the fixed five seconds.
- If a request is still running after `TEGH_WAIT_MAX` (default 300 s), no backup is taken and that request continues normally.
- A marker older than `TEGH_STALE_AFTER` (default 900 s) is left over from a crashed process. It is reported and ignored.
- The script gives the marker folder the storage folder's owner, so the web server can always write it. It stops if it cannot.
- A command-line run of Tegh no longer creates that folder.
- During a backup, the upgrade routes are refused like every other request.

**2. Every change is detected, and no check is ever skipped.**
- Next to the row count, each table now gets a **content fingerprint**: every value of every row, hashed. An edit that changes neither row counts nor ledger totals changes the fingerprint.
- The script records the fingerprints before and after the dump. The backup is kept only when they are identical. Otherwise it names the tables that changed and removes the backup.
- **Every check query must succeed.** R157 suppressed a failing ledger-total query with `|| true` and recorded it as "no postings". Now a database error stops the run, shows the error, keeps no backup and resumes changes.
- `tegh-restore-verify.sh` compares the fingerprints of the restored database with the backup's.
- The restore check treats a database error in its row count, ledger or balance checks as a failure.
- A backup made before R158 has no fingerprints. The restore check says so and compares row counts only.

**Tests (beta-gate-evidence/scripts/beta-ops-test.sh, 19 checks, plus four controls run with the R157 scripts):**

| Case | R158 | R157 control |
|---|---|---|
| D1 The ledger-total query fails (column missing) | Stops with the database error; no backup kept; changes resumed | Exit 0, "companies with postings 0" |
| D2 A request running about 15 s, changing a customer's name from second 7 to 15 | Waited 14 s; the backup holds the request's final state | Stopped waiting after 5 s; reported consistent while the request made 7 changes during the backup |
| D3 Another program edits a customer's name every 0.1 s (counts and totals unchanged) | Detected; table `customers` named; backup removed | Accepted |
| E1 A left-over marker 20 minutes old | Reported and ignored | — |
| E2 A request that never ends | Stops at `TEGH_WAIT_MAX`; no backup kept | — |
| E3 Restore of a backup whose dump differs in one value | `customers: content differs`; not verified | `RESTORE VERIFIED` |
| E4 Database error in the restore's ledger and balance checks | Both failures | — |

Sections A to C (backup during writes, refused restore targets, empty installation) pass as before.

## Upgrading from R157
- **Back up first,** with the R158 `tegh-backup.sh` from the Beta Kit, or your host's own backup.
- Upload the whole package. The cache token is r158-tegh.
- No database change.
- Replace `tegh-backup.sh` and `tegh-restore-verify.sh` on the host with the R158 versions.
- Run the backup by hand once. It should end with `backup done`, and its `consistent snapshot` line should list content fingerprints.

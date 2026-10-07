# Tegh beta: backups, restore test and update rollback

For the developer or hosting administrator of the beta site (for example beta.yourdomain.com).
These steps cover the whole installation. Each company can also make its own backup in the app (Settings > Backup).

## 1. What must be backed up
| Item | Where | Why |
|---|---|---|
| Database | the database named in `config.php` (`db.dsn`) | every record: companies, documents, journals, users, settings |
| Upload folder | `storage_path` in `config.php` (outside the web root) | uploaded statements, receipts, Document Intake files, invoice attachments, payroll evidence |
| `config.php` | `sr-accountax-private/config.php` | database password, mail settings, secrets |
| The installed package ZIP | keep the exact ZIP you deployed | needed to roll back an update |

`config.php` holds secrets. Keep its copy in a password manager or an encrypted vault, not in ordinary backup storage.

## 2. Daily backup
1. Copy `tegh-backup.sh` to the host, outside the web root. Run it as root, or as the user that owns the storage folder (the web server's user). It must:
   - write the storage folder (it places Tegh's maintenance flag in `storage/runtime/`);
   - own `storage/runtime/active-requests/`, where every running Tegh request leaves a marker (R158). The script gives that folder the storage folder's owner and stops if it cannot;
   - read the database.
2. Create a MySQL option file readable only by the backup user, for example `~/.tegh-backup.cnf`:
   ```
   [client]
   user=tegh_beta_backup
   password=…
   ```
   The backup user needs SELECT, SHOW VIEW, TRIGGER and LOCK TABLES on the beta database.
3. Schedule it daily, at a quiet hour. Set `TEGH_OFFSITE` to a location **outside the hosting account**: another server or a storage box reachable by rsync/SSH.
   ```
   15 2 * * * MYSQL_DEFAULTS=$HOME/.tegh-backup.cnf TEGH_DB=tegh_beta TEGH_STORAGE=/…/sr-accountax-private/storage \
     TEGH_OUT=/…/tegh-backups TEGH_OFFSITE=backup@offsite:/tegh-beta TEGH_KEEP_DAYS=14 /…/tegh-backup.sh >> /…/tegh-backups/backup.log 2>&1
   ```
   If `config.php` sets `app.maintenance_flag_path`, pass the same path as `TEGH_MAINT_FLAG`.
   Optional: `TEGH_WAIT_MAX` (default 300) is how many seconds to wait for requests that were already running; `TEGH_STALE_AFTER` (default 900) is the age after which a request marker counts as left over from a crashed process.
4. Check `backup.log` the next morning. Each run ends with `backup done` after a line such as `consistent snapshot: db 708K, files 27, tables 168, content fingerprints 168, companies with postings 24`. A run that fails starts its last lines with `FAIL` and keeps no backup folder.

**How the backup stays consistent.**
1. The script pauses changes with Tegh's own maintenance flag. Every request during the pause is refused with "Tegh is making a backup" (503) and nothing is written. The pause usually lasts under a minute.
2. It waits for every request that was already running to finish, however long it takes (R158). Each Tegh request leaves a marker file while it runs, and the script waits until there are none. If one is still running after `TEGH_WAIT_MAX` seconds, no backup is taken and the run fails; that request continues normally. (Up to R157 the script waited a fixed five seconds.)
3. It records the check figures: rows per table, a **content fingerprint of every table**, posted debits and credits per company, and the SHA-256 of every uploaded file. Then it takes the database dump and the upload archive. The fingerprint covers every value of every row, so it changes when any record is edited, even if row counts and totals stay the same (R158).
4. It records the check figures again. The backup is kept only when both sets are identical, so the dump, the files and the figures all describe the same moment. Otherwise it is deleted and the run fails, naming the tables that changed. This also catches another program writing to the database during the backup.
5. Every check query must succeed. A database error (for example a missing column or a missing permission) stops the run; the script never records a figure it could not compute. (Up to R157 a failing ledger-total query was ignored and recorded as "no postings".)
6. The flag is always removed, also when the script fails.

An installation with no records or no uploads is a valid backup.

If the host has no cron or SSH (some shared hosting plans), use the host's own scheduled database backups plus a scheduled copy of the upload folder, taken while maintenance mode is on. Keep the restore test below in place either way.

## 3. Restore test into a separate installation (before inviting testers, then monthly)
`tegh-restore-verify.sh` **drops** its target database and **replaces** its target folder. It therefore runs only against a target you have designated for restore tests, and refuses anything else. **Never point it at the live installation**; it is built to refuse to.
1. Set up the restore-test installation once:
   - a second database whose name contains `restore` (for example `tegh_restore_test`);
   - a second folder holding the same package, with its own `sr-accountax-private/config.php`. Point its `db.dsn` at `tegh_restore_test`, its `base_url` at the test address, and `mail.smtp_host` at nothing or a sandbox, so it never emails anyone.
2. Mark that folder as the restore target, by hand, once:
   ```
   echo "db=tegh_restore_test" > /…/restore/sr-accountax-private/.tegh-restore-target
   ```
3. Run it with the **live** config, so it can check it is not touching the live installation:
   ```
   MYSQL_DEFAULTS=… TEGH_LIVE_CONFIG=/…/live/sr-accountax-private/config.php RESTORE_DB=tegh_restore_test \
     RESTORE_STORAGE=/…/restore/sr-accountax-private/storage ./tegh-restore-verify.sh /…/tegh-backups/tegh-YYYYMMDD-HHMMSS
   ```
   It **refuses** (exit 2, nothing changed) when:
   - the database name lacks `restore`;
   - the database is the live one;
   - the folder is the live storage folder, or is inside or around it;
   - the folder has no `.tegh-restore-target` naming that database.

   Otherwise it:
   - checks the backup's SHA-256 list;
   - restores the database and the upload folder;
   - checks every uploaded file, the row count and the content fingerprint of every table, and the posted debits and credits of every company against the backup's figures. A database error in any of these checks is a failure. Backups made before R158 have no fingerprints; the script says so and compares row counts only.

   It ends with `RESTORE VERIFIED`.
4. Open the test address, sign in, and compare a few screens with the live site: Trial Balance, an invoice PDF, a Document Intake file.
   - `compare-restore.mjs` does this for every company: trial balance and uploaded files, through the app's own API.
   - Run it with `node compare-restore.mjs https://live https://restore owner-email password`. It only reads.

**Results on the build team's test host (2026-10-07, R158 scripts, synthetic data; 19 of 19 passed; each R157-review case was also run with the R157 scripts as a control, and all 4 controls showed the R157 defect):**

| Check | Result |
|---|---|
| Backup taken while another process kept creating records every 300 ms | Consistent (figures identical before and after). 96 writes succeeded and 21 were refused with "Tegh is making a backup" during the pause. The flag was removed afterwards. |
| Restore of that backup | `RESTORE VERIFIED`, with every table's row count equal |
| Refused targets | Database without "restore" in its name, the live database, the live storage folder (even with a marker placed next to it), a folder containing the live storage, and an undesignated folder: all refused. The live database and files were unchanged. |
| Installation with no records and no uploads | Backed up and restored, verified |
| Damaged backup (one byte changed) | Refused before anything was restored (earlier run) |
| The ledger-total query fails (column missing) | R158: the run stops with the database error, keeps no backup and resumes changes. R157 control: exit 0, "companies with postings 0". |
| A Tegh request running about 15 s, changing a customer's name from second 7 to 15 | R158: waited 14 s, then took a backup that holds the request's final state. R157 control: stopped waiting after 5 s and reported a consistent snapshot while the request made 7 changes during the backup. |
| Another program changes a customer's name every 0.1 s (row counts and totals unchanged) | R158: detected by the content fingerprint (table `customers` named); backup removed. R157 control: accepted. |
| Left-over marker 20 minutes old; a request that never ends | The left-over marker is reported and ignored. The never-ending request stops the run at `TEGH_WAIT_MAX`, with no backup kept and changes resumed. |
| Restore of a backup whose dump differs in one value only | R158: row counts pass, `customers: content differs`, not verified. R157 control: `RESTORE VERIFIED`. |
| Database error in the restore's ledger and balance checks | Both reported as failures. |
| Separate installation served from a restored backup | On the final R156 installation: identical trial balances for all 26 companies (121 account rows) and 7 identical uploaded files. Earlier runs on R154 and R155 data gave the same result. |

The test script is `beta-gate-evidence/scripts/beta-ops-test.sh`. This proves the method; your host still needs one restore test of its own (host acceptance).

## Restoring the live site after a real loss
The restore script will not write to the live installation. A real recovery is a deliberate, manual step:
1. Restore the backup into the restore-test installation with `tegh-restore-verify.sh`, and confirm `RESTORE VERIFIED` and the screens.
2. Turn on maintenance mode on the live site.
3. Restore the live database with `gunzip -c db.sql.gz | mysql <live db>`, and the upload folder from `storage.tar.gz`, from that verified backup.
4. Turn maintenance mode off, then check `/api/health`, sign in, and check the Trial Balance.

## 4. Before every update
1. Run `tegh-backup.sh` by hand and wait for `backup done`.
2. Keep the ZIP that is currently installed (for example `…-R157-PRIVATE-BETA.zip`).
3. Optionally, enable maintenance mode in `config.php` (`app.maintenance_mode => true`) while uploading.
4. Upload the new package, then run `sha256sum -c FILE-MANIFEST.sha256` in the web root and open `/api/health`.
5. Sign in and check the dashboard, one report and one invoice PDF.

## 5. Rolling back an update
Use this when the new version shows a blocking problem.
1. Turn on maintenance mode, or take the site offline.
2. Re-upload the previous ZIP over the web root. Delete files that exist only in the new version; the new version's `DEPLOYMENT-NOTES.txt` lists added files.
3. If the new version changed the database, restore the database from the backup taken in step 4.1. Do the same with the upload folder if files were added after it. Releases R118–R158 add only columns and tables on first use and the previous version ignores them, so a database restore is normally needed only if data written after the update must be discarded.
4. Restart PHP, or wait a minute for the PHP cache to clear. Then check `/api/health`, sign in, and check the Trial Balance.
5. Tell the testers what happened and whether any work done after the update must be re-entered.

## 6. If data is lost or exposed
- **Lost data:** stop changes, restore the latest verified backup into the separate installation, and confirm what it holds before restoring the live site.
- **Suspected unauthorized access:** follow your breach-response plan (beta step 6). Preserve the logs, change the database and mail passwords and the app secrets in `config.php`, and sign everyone out. Removing and re-adding members, or a password reset, ends their sessions.

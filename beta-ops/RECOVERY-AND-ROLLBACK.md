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
1. Copy `tegh-backup.sh` to the host, outside the web root.
2. Create a MySQL option file readable only by the backup user, for example `~/.tegh-backup.cnf`:
   ```
   [client]
   user=tegh_beta_backup
   password=…
   ```
   The backup user needs SELECT, SHOW VIEW, TRIGGER and LOCK TABLES on the beta database.
3. Schedule it daily. Set `TEGH_OFFSITE` to a location **outside the hosting account**: another server or a storage box reachable by rsync/SSH.
   ```
   15 2 * * * MYSQL_DEFAULTS=$HOME/.tegh-backup.cnf TEGH_DB=tegh_beta TEGH_STORAGE=/…/sr-accountax-private/storage \
     TEGH_OUT=/…/tegh-backups TEGH_OFFSITE=backup@offsite:/tegh-beta TEGH_KEEP_DAYS=14 /…/tegh-backup.sh >> /…/tegh-backups/backup.log 2>&1
   ```
4. Check `backup.log` the next morning. Each run ends with `backup done` and a line such as `db 668K, files 44, companies with postings 32`.

If the host has no cron or SSH (some shared hosting plans), use the host's own scheduled database backups plus a scheduled copy of the upload folder. Keep the restore test below in place either way.

## 3. Restore test into a separate installation (do this before inviting testers, then monthly)
Never restore over the live site to test.
1. Create a second database (for example `tegh_restore_test`) and a second folder holding the same package, with its own `sr-accountax-private/config.php`. Point its `db.dsn` at `tegh_restore_test` and its `base_url` at the test address.
2. Run:
   ```
   MYSQL_DEFAULTS=… RESTORE_DB=tegh_restore_test RESTORE_STORAGE=/…/restore/sr-accountax-private/storage \
     ./tegh-restore-verify.sh /…/tegh-backups/tegh-YYYYMMDD-HHMMSS
   ```
   The script:
   - refuses a backup whose files do not match their SHA-256;
   - restores the database and the upload folder;
   - checks every uploaded file, the row count of every table, and the posted debits and credits of every company against the figures recorded when the backup was made.

   It ends with `RESTORE VERIFIED`.
3. Open the test address, sign in, and compare a few screens with the live site: Trial Balance, an invoice PDF, a Document Intake file.
   - `compare-restore.mjs` does this for every company: trial balance and uploaded files, through the app's own API.
   - Run it with `node compare-restore.mjs https://live https://restore owner-email password`. It only reads.
4. Delete the test database when finished, or keep it isolated and never send real email from it. In its config, set `mail.smtp_host` to a blank or sandbox server.

**Results on the build team's test host (2026-10-06):**
- **First run** (R154 data):
  - Backup: database 668 KB and 44 uploaded files, copied to a second location.
  - Restore: `RESTORE VERIFIED`. All 44 files identical, 167 tables' row counts equal, and the posted totals of 32 companies equal.
  - A separate installation on the restored data loaded the trial balances of all 40 companies (153 account rows). They were identical to the live site, and all 17 Document Intake files compared were identical.
- **Second run, on the exact R155 private-beta installation after its full gate run:**
  - Restore: `RESTORE VERIFIED`. 24 files, 167 tables, and the posted totals of 19 companies all matched.
  - The separate installation showed identical trial balances for all 25 companies (120 account rows), and all 7 files compared were identical.
- A backup with one changed byte was refused before anything was restored.

This proves the method. Your host still needs one restore test of its own (host acceptance).

## 4. Before every update
1. Run `tegh-backup.sh` by hand and wait for `backup done`.
2. Keep the ZIP that is currently installed (for example `…-R154-…zip`).
3. Optionally, enable maintenance mode in `config.php` (`app.maintenance_mode => true`) while uploading.
4. Upload the new package, then run `sha256sum -c FILE-MANIFEST.sha256` in the web root and open `/api/health`.
5. Sign in and check the dashboard, one report and one invoice PDF.

## 5. Rolling back an update
Use this when the new version shows a blocking problem.
1. Turn on maintenance mode, or take the site offline.
2. Re-upload the previous ZIP over the web root. Delete files that exist only in the new version; the new version's `DEPLOYMENT-NOTES.txt` lists added files.
3. If the new version changed the database, restore the database from the backup taken in step 4.1. Do the same with the upload folder if files were added after it. Releases R118–R154 add only columns and tables on first use and the previous version ignores them, so a database restore is normally needed only if data written after the update must be discarded.
4. Restart PHP, or wait a minute for the PHP cache to clear. Then check `/api/health`, sign in, and check the Trial Balance.
5. Tell the testers what happened and whether any work done after the update must be re-entered.

## 6. If data is lost or exposed
- **Lost data:** stop changes, restore the latest verified backup into the separate installation, and confirm what it holds before restoring the live site.
- **Suspected unauthorized access:** follow your breach-response plan (beta step 6). Preserve the logs, change the database and mail passwords and the app secrets in `config.php`, and sign everyone out. Removing and re-adding members, or a password reset, ends their sessions.

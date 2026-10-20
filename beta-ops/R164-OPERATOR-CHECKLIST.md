# R164 operator checklist — invitation-only beta on IONOS

Use it for package `Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R164-PRIVATE-BETA.zip`. Tick each line on the
real host. Nothing on this list has been checked on IONOS by the developer; the test-host results are in
`beta-gate-evidence/r164/`.

## A. Before uploading

- [ ] Take a full backup of the database, the private folder (`sr-accountax-private/`, including `config.php` and
  `storage/`) and the web root. Keep it off the server.
- [ ] Note the current PHP version in IONOS (8.3 recommended) and that the MariaDB user can CREATE and ALTER tables.
  R164 adds two tables and one column on first use.
- [ ] **Do not change `app.secret`.** It signs sessions and tokens and now also protects stored MFA secrets.
  Changing it would sign everyone out and make every enrolled authenticator invalid.

## B. Upload and verify the files

- [ ] Upload the ZIP contents into the web root (not the folder around them). Keep `config.php`, `storage/` and the
  database.
- [ ] In SSH, from the web root: `sha256sum -c --quiet FILE-MANIFEST.sha256 && echo OK`, then
  `for f in api/*.php; do php -l "$f" >/dev/null || echo "FAIL $f"; done`. Both must print nothing but `OK`.
- [ ] Remove any old copies or archives left in the web root (for example `*.zip`, `*-old`, `backup*`).

## C. Settings to complete in `config.php` (private folder)

| Setting | What to put | Why |
|---|---|---|
| `app.public_signup_enabled` | `false` | Invitation-only beta. |
| `app.setup_key` | Remove or replace with a new random value after setup | Setup is locked once an owner exists. |
| `monitoring.alert_email` | A mailbox someone reads daily | Backup, error, sign-in and config alerts. |
| `operator.support_email` | Already required for invitations | Fallback for alerts. |
| `security.trusted_proxy` | `''` unless the site is proxied by Cloudflare (orange cloud); then `'cloudflare'` | Rate limits use the real visitor address. |
| `captcha.*` | Turnstile keys (already configured) | Unchanged. |
| `mail.*` | Working SMTP account | Alerts, resets and invitations need it. |
| `deletion_register_path` | Leave empty (default is next to the incident log, in the private folder) | Must stay outside the web root and outside `storage/`. |

## D. Two-step verification (MFA)

- [ ] Sign in as the platform owner. Tegh asks you to set up two-step verification. Scan the key with an
  authenticator app, enter the code, and store the 10 recovery codes offline (password manager or paper in a safe
  place).
- [ ] Ask each company owner or admin to sign in and enrol. Until they enrol they can only reach the enrolment screen.
- [ ] Recovery procedure:
  1. Someone who lost their phone signs in with a recovery code, then turns MFA off and on again to get a new key and
     new codes.
  2. Someone who lost both: confirm who they are **outside Tegh** (phone call to a known number). Then use
     Platform Owner Home › View Users › Actions › Reset two-step verification. They enrol again at the next sign-in.
     The action is recorded in the platform audit log.
- [ ] If the platform owner loses both: from SSH run
  `mysql <db> -e "DELETE FROM user_mfa WHERE user_id=(SELECT id FROM users WHERE platform_role='platform_owner'); DELETE FROM user_mfa_recovery_codes WHERE user_id=(SELECT id FROM users WHERE platform_role='platform_owner')"`,
  then sign in and enrol again. Do this only from your own SSH session.

## E. Deny rules — probe from your own computer

Each request must return **403 or 404, never 200**. Replace `HOST` with your address.

```
for p in config.example.php api/monitor-cron.php api/native-agent-cron.php api/bootstrap.php .git/HEAD .htaccess \
         FILE-MANIFEST.sha256 tegh-backup.zip x.sql.gz storage/ ../sr-accountax-private/config.php \
         deletion-register.ndjson system-incidents.ndjson api/php.ini; do
  printf '%-45s %s\n' "$p" "$(curl -s -o /dev/null -w '%{http_code}' "https://HOST/$p")"; done
```

- [ ] All lines show 403 or 404 (on the test host every one of them does).
- [ ] `curl -sI https://HOST/app.html` shows `Strict-Transport-Security`, `X-Content-Type-Options: nosniff`,
  `X-Frame-Options: DENY` and a `Content-Security-Policy`, and no `X-Powered-By`.
- [ ] Signed in, download a statement source or an invoice attachment; the response headers show
  `Content-Disposition: attachment` and `Content-Security-Policy: sandbox; default-src 'none'`. Use the browser
  developer tools › Network.

## F. Backups, retention and monitoring (cron in IONOS)

- [ ] Daily backup cron (existing):
  `15 2 * * * MYSQL_DEFAULTS=$HOME/.tegh-backup.cnf TEGH_DB=<db> TEGH_STORAGE=<private>/storage TEGH_OUT=$HOME/tegh-backups TEGH_ENCRYPT_TO=<age public key> TEGH_KEEP_DAYS=14 $HOME/tegh-backup.sh >> $HOME/tegh-backups/backup.log 2>&1`
- [ ] Monitoring cron (new), every 30 minutes:
  `*/30 * * * * /usr/bin/php <webroot>/api/monitor-cron.php >> $HOME/tegh-backups/monitor.log 2>&1`
- [ ] Run each once by hand. `backup.log` ends with `backup done`. `<private>/storage/runtime/backup-status.json`
  shows `"lastRunStatus":"ok"`. `monitor.log` shows `monitor ok ... recipient=set`.
- [ ] Test alert delivery: temporarily set `monitoring.backup_max_age_hours` to `1` the day after a backup and run the
  monitor once. You receive "[Tegh alert] No successful backup recently". Put the value back.
- [ ] **Server retention:** after 15 days, `ls $HOME/tegh-backups` shows no `tegh-*` folder older than 14 days, and
  the log shows `retention: N local copies kept (limit 14 days)`.
- [ ] **Laptop retention:** schedule `tegh-pull-backups.ps1` (KeepDays 90). After a run, `pull.log` shows
  `oldest copy kept: <date> (limit 90 days)` and no `FAIL`. *This script was not executed in testing; check the
  first two runs by hand.*

## G. Restoring a backup (do not reactivate deleted data)

- [ ] Before any restore, copy `<private>/deletion-register.ndjson` aside. Never replace it with an older copy.
- [ ] Restore with `tegh-restore-verify.sh` into a separate restore-test install first, as in
  `RECOVERY-AND-ROLLBACK.md`.
- [ ] After restoring production, run the monitor once. If it reports "Deleted logins or companies are present
  again", those items are already blocked. Delete them again from Platform Owner Home (Users / Companies).

## H. After go-live, weekly

- [ ] Read the alert mailbox; review Platform Owner Home › View Errors.
- [ ] Check that `backup-status.json` is less than a day old and the laptop has the newest encrypted copy.
- [ ] Review the platform audit log for MFA resets, role changes and deletions you did not expect.

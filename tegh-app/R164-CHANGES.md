# R164 — security hardening for the invitation-only beta

R164 = R163 plus security hardening. Accounting behaviour is unchanged. New tables and columns are added on first use
(no manual migration); the database user needs CREATE and ALTER, as for every release since R118.

## Sign-in and accounts

- **Every sign-in needs a second step.** By default it is a 6-digit code emailed to the account's registered
  address. Nothing to set up, on for every account. The code is stored only as a keyed hash, works once, expires
  after 10 minutes and is bound to that sign-in. Five wrong entries withdraw it. A new code can be emailed after
  30 seconds, at most 10 per account per 15 minutes.
- **"Trust this device for 30 days"** is a checkbox on the code screen. That browser then signs in with the password
  alone for 30 days. Trust is withdrawn by a password change or reset, an administrator MFA reset, deactivation or
  deletion, a recovery-code sign-in, or **Account & Access › Two-step verification › Forget trusted devices**.
- **Authenticator app (optional).** In Account & Access a person can set up Google Authenticator, Microsoft
  Authenticator, 1Password or similar by **scanning a QR code** (or typing the key), then use it instead of the
  emailed code. Ten single-use recovery codes come with it. The secret is encrypted (AES-256-GCM, key derived from
  `app.secret`). Setup, removal and new recovery codes need the password and an already-verified session.
- Tables `user_email_otp`, `user_trusted_devices`, `user_mfa` and `user_mfa_recovery_codes`, and column
  `sessions.mfa_verified`, are added on first use. The QR code is drawn in the browser with the bundled
  `assets/qrcode-generator-2.0.4.js` (MIT); nothing is sent to an outside service.
- Accepting an emailed invitation and the first platform-owner setup (setup key) already prove the person, so those
  sessions start verified. The emailed code applies from the next sign-in.
- **Email delivery is now required to sign in** for anyone without an authenticator app. A failed code email raises
  an operator alert ("Sign-in codes could not be emailed").
- **Recovery:** someone who lost access to their email but has the authenticator app uses it, or a recovery code.
  Otherwise the platform owner confirms their identity outside Tegh, corrects the email address or resets their MFA
  (**Platform Owner Home › Users › Actions › Reset two-step verification**). This ends their sessions and trusted
  devices and is audited.
- **Recent sign-in required.** A password confirmation is needed when the sign-in is more than 15 minutes old, for:
  member role changes, suspension, removal and ownership transfer; platform user administration (deactivate,
  reactivate, MFA reset); login restore; approving or rejecting company deletion; and changing public registration.
  The portal asks for the password in a dialog and retries once. Password, MFA and company-deletion actions already
  require the password every time.
- **Sessions end** for the affected person on role change, suspension, removal, ownership transfer, deactivation,
  MFA reset, MFA disable, password change (other sessions), password reset (all sessions) and recovery-code sign-in
  (other sessions).
- **Password reset request** answers with the same response at the same speed whether or not the address has an
  account. The email is sent after the response, and delivery failures are logged, not shown.
- **Client address for rate limits** ignores `X-Forwarded-For` and `CF-Connecting-IP` unless
  `security.trusted_proxy = 'cloudflare'` is set. Even then, the header is used only when the direct peer is in
  Cloudflare's published ranges.

## Files and exports

- Every stored file (statement sources, receipts, invoice attachments, intake documents, saved downloads, backups) is
  served as an attachment with `nosniff`, `no-store` and a `sandbox` Content-Security-Policy. The site-wide CSP
  previously overrode the sandbox, so `.htaccess` now applies it to download responses.
- Receipt and statement-source downloads now also check the role permission and that the stored path belongs to the
  company.
- CSV exports from the portal prefix cells that would start a spreadsheet formula (`=`, `+`, `@`, tab, CR, or `-`
  not followed by a plain number).

## Monitoring (new: `api/monitoring_r164.php`, `api/monitor-cron.php`)

The operator is emailed (`monitoring.alert_email`, else `operator.support_email`) when:

- the last backup failed;
- there has been no successful backup for 26 hours;
- there are serious server errors;
- sign-in activity is unusual (failed sign-ins, many accounts tried from one source, MFA lockouts, many reset
  requests);
- security settings changed in the private config file;
- deleted logins or companies are present again.

At most one email per kind is sent per hour (configurable), and alerts contain counts and request references only.
Run `api/monitor-cron.php` from cron every 15–60 minutes. As a fallback it also runs at most every 15 minutes after a
request. The cron script is blocked from the web.

## Audit

Existing audit records cover role changes, suspension, removal, admin user actions, deletion requests and decisions,
invitations and MFA events. R164 adds:

- MFA reset;
- registration changes, which now require a recent sign-in;
- a `platform.security_settings_changed` record when the monitoring sweep notices a changed security setting in the
  config file. It names the setting only, never the value.

## Deletion and restore

- Completed deletions of logins and companies are written to a **deletion register**
  (`deletion-register.ndjson`). It sits in the private folder next to the incident log, outside the database and
  outside the backed-up storage folder.
- If an older backup is restored, a deleted login cannot sign in, its old sessions are refused and removed, and a
  deleted company cannot be opened. The operator is alerted and can delete them again.
- A deliberate login restore by the platform owner lifts the register entry.
- `beta-ops/tegh-backup.sh` records every run in `<storage>/runtime/backup-status.json` for monitoring.
  Server retention is exactly 14 days and is verified after deletion.
- `beta-ops/tegh-pull-backups.ps1` keeps 90 days on the laptop. Age is counted from the date the backup was made,
  not the download date, and deletion is verified.

## Deployment protection

`.htaccess` denies backups, archives, exports, editor and old copies (`.zip .tar .gz .7z .rar .orig .old .save
.swp .dist .phar .ndjson .tegh`), version-control folders and build files. Config and logs remain outside the web
root.

## Configuration

`config.example.php` adds:

- `security.trusted_proxy` and `security.trusted_proxy_ranges`;
- the `monitoring` block;
- optionally `deletion_register_path`.

No existing secret is rotated. `app.secret` must not be changed: it protects sessions and tokens, and now also the
MFA secrets.

## Tests (gate host)

New suites:

| Suite | Checks | Covers |
|---|---|---|
| `34-mfa` | 17 | Emailed codes, trusted devices, optional authenticator with QR (decoded in the browser), the sign-in gate |
| `35-session` | 14 | Step-up, sessions, reset parity, proxy, portal dialog |
| `36-monitor` | 10 | Monitoring |
| `37-audit` | 7 | Audit records |
| `38-files` | 11 | Cross-company file and record access, download headers, uploads, invitations |
| `39-restore` | 7 | Restore safety |

Also: a TOTP unit test (13 checks) and a client-IP unit test (11 checks). The backup operations suite passes 22/22.

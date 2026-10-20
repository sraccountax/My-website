# R164 gate results

**Package tested:** `Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R164-PRIVATE-BETA.zip`

| Package detail | Value |
|---|---|
| SHA-256 | `caee7f6ab73aaabe68a706aa1baafda9440075e66a09c37b176cd714e0a83876` |
| Files | 383, all mode 0644 |
| FILE-MANIFEST | 382 entries, all verified after unpacking (`identity.txt`) |
| Source commit | `97b3bb5`, branch `claude/invoice-credit-debit-audit-e2y8wc` |

This ZIP has the emailed sign-in code for every account, "trust this device" for 30 days, and the optional
authenticator app with a QR code. It **replaces** the earlier R164 ZIP `daae041a…`, which required an authenticator
for owners and admins. Do not deploy that one.

**Where:** a local test host (Apache 2.4 + PHP 8.3-FPM + MariaDB, HTTPS with a private CA, clock pinned to
2026-10-01 Toronto). This is **not** the IONOS host. Nothing was run against books-test or production; the host
checks are in `beta-ops/R164-OPERATOR-CHECKLIST.md`.

## Automated results (synthetic data only)

| Run | Result |
|---|---|
| Full regression on the exact ZIP: fresh install, every API and browser suite, two-step sign-in on for every account (`run-all-full-run.log`, `summary.json`) | **775 PASS · 7 INFO · 0 FAIL** |
| Two-step sign-in suite `34-mfa`, inside the full run | 17/17 |
| Other R164 security suites, inside the full run | 35-session 14/14 · 36-monitor 10/10 · 37-audit 7/7 · 38-files 11/11 · 39-restore 7/7 |
| Upgrade: an R118 install with data upgraded to this ZIP (`upgrade-r118-to-r164.log`) | 13/13 PASS: ledger balances unchanged, all journals balance |
| Paths and headers (`paths.txt`) | 45 PASS · 0 FAIL |
| Screen matrix: 20 runs over 7 widths, light and dark, zoom 125–200 %, top and guided navigation | 0 screens with issues; 0 phone swipe traps (`trapscan.txt`) |
| Backup operations (`backup-ops.log`; scripts unchanged since that run) | 22/22 PASS |
| Unit tests | TOTP (RFC 6238 vectors, AES-GCM tamper) 13/13; client IP / Cloudflare ranges 11/11 |

The 7 INFO results are informational records, not failures.

`34-mfa` U-01 decodes the QR code as the browser draws it (200 × 200 px). It checks that the code contains the setup
link with that account's key. Screenshot: `r164-authenticator-qr.png`.

## Found and fixed during testing

- **Security.** The authenticator-management routes ran before the sign-in gate, so someone holding only the password
  could add their own authenticator and skip the emailed code. They now require a verified session (test G-02).
- **Usability.** Signing in again within 30 seconds (a second tab, or after a typo) got no code. The 30-second wait
  now applies only to resending within the same sign-in.
- **The QR code drew at a few pixels** because of the app's general SVG styles. It is now a fixed 200 px.

## Test-harness fixes (no app change)

- The harness reads the emailed code from the test mailbox and keeps a trusted device for API sessions.
- Browser sign-ins in fresh contexts reached the product's limit of 10 codes per 15 minutes. The harness now removes an
  account's *used* codes after each test sign-in, on the test database only; the limit itself is tested in E-05.
- Parallel browsers signing in as the same account could pick up each other's emailed code, which the app rejected
  because codes are bound to their sign-in. The browser helper now tries the account's new codes until one is
  accepted.
- The first run of the matrix completed 9 of 20 views before that last fix. The 11 missing views were re-run with the
  same parallel pattern, and all 20 completed with 0 issues.

## Not executed / limitations

- Anything on IONOS. Email delivery through the production SMTP account (every sign-in depends on it for people
  without an authenticator app).
- `beta-ops/tegh-pull-backups.ps1` (no PowerShell here; reviewed only).

## What each kind of evidence means

- **Source review:** the code was read; nothing executed.
- **Automated tests:** executed on the test host above against the exact ZIP.
- **IONOS host checks:** none performed. The operator checklist lists them.

# R164 gate results

**Package tested:** `Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R164-PRIVATE-BETA.zip`

| Package detail | Value |
|---|---|
| SHA-256 | `daae041a3aa90b0e0cdf53783e8c738936ce26e26d1ca1592d75b998b111f0ec` |
| Files | 382, all mode 0644 |
| FILE-MANIFEST | 381 entries, all verified after unpacking (`identity.txt`) |
| Source commit | `ff33f47`, branch `claude/invoice-credit-debit-audit-e2y8wc` |

**Where:** a local test host (Apache 2.4 + PHP 8.3-FPM + MariaDB, HTTPS with a private CA, clock pinned to
2026-10-01 Toronto). This is **not** the IONOS host. Nothing here was run against books-test or production; the
host checks are in `beta-ops/R164-OPERATOR-CHECKLIST.md`.

## Automated results (synthetic data only)

| Run | Result |
|---|---|
| Full regression on the exact ZIP: fresh install, every API and browser suite, MFA enforced (`run-all-full-run.log`) | **762 PASS · 7 INFO · 8 FAIL** |
| — the 8 failures | ON-06 … ON-13 in `25-r157` (new-user onboarding in the browser). ON-06 timed out waiting for the onboarding screen after the first sign-in; the other 7 depend on it. See "Unresolved" below. |
| `25-r157` run alone afterwards on the same database, twice (`r157-standalone-rerun.json`) | 21/21 PASS both times |
| `summary.json` / `test-matrix.md` | 783 PASS · 7 INFO · 0 FAIL — **this includes the standalone `25-r157` re-run in place of the full-run result**; the full-run figure above is the honest one |
| R164 security suites, inside the full run | 34-mfa 12/12 · 35-session 14/14 · 36-monitor 10/10 · 37-audit 7/7 · 38-files 11/11 · 39-restore 7/7 |
| Upgrade: an R118 install with data upgraded to this ZIP (`upgrade-r118-to-r164.log`) | 13/13 PASS: ledger balances unchanged, all journals balance |
| Backup operations, `beta-ops/tegh-backup.sh` and the restore check (`backup-ops.log`) | 22/22 PASS |
| Paths and headers (`paths.txt`) | 45 PASS · 0 FAIL |
| Screen matrix: 20 runs over 7 widths, light and dark, zoom 125–200 %, top and guided navigation | 0 screens with issues; 0 phone swipe traps (`trapscan.txt`) |
| Unit tests | MFA (RFC 6238 vectors, AES-GCM tamper) 13/13; client IP / Cloudflare ranges 11/11 (`unit-client-ip.log`) |

The 7 INFO results are informational records (for example, rendering checked elsewhere). They are not failures.

## Test-harness fixes made during this run (no app change)

- **Password-reset emails (`24-beta` BS-06, `13-r141` OB-06).** The tests waited 0 ms for the email. R164 sends the
  reset email just after the response, so they now wait up to 15 s. A failed delivery would still fail them.
- **Screen matrix and trap scan sign-in.** These signed in without the MFA helper; `finish-run.sh` now loads it.
- **Parallel browser sign-ins.** Three browsers signing in as the same owner reset each other's MFA enrolment. The
  browser helper now takes the same per-account lock as the API helper. Five matrix runs that hit the lock's waiting
  time were re-run one at a time; all completed with 0 issues.

## Unresolved

- **`25-r157` ON-06…ON-13 in the full run.** They failed once in the full run, and passed 21/21 in an earlier
  (interrupted) full run and in two standalone runs on the same ZIP. The server incident log shows no MFA, CAPTCHA,
  rate-limit or server error around that sign-in. The screenshots from the failing run were overwritten. The root
  cause is **not established**.
- **Not executed:** `beta-ops/tegh-pull-backups.ps1` (no PowerShell here; reviewed only). Email delivery through the
  production SMTP account. Anything on IONOS.
- **Known limitation:** a member who becomes an owner by creating a company is asked to set up MFA at the next
  sign-in, not immediately.

## What each kind of evidence means

- **Source review:** the code was read; nothing executed.
- **Automated tests:** executed on the test host above against the exact ZIP.
- **IONOS host checks:** none performed. The operator checklist lists them.

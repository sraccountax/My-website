# Tegh R163 gate evidence bundle

This bundle holds the evidence for one package only:

`Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R163-PRIVATE-BETA.zip`

Its SHA-256 is in `PACKAGE.sha256`. The gate records the hash of the ZIP it installed in `raw/identity.txt`, so these results cannot be confused with those of another build.

## Check it yourself
```
unzip Tegh-R163-Gate-Evidence.zip && cd Tegh-R163-Gate-Evidence
python3 scripts/verify-evidence.py /path/to/Tegh-...-R163-PRIVATE-BETA.zip
```
The script does not rely on the QA report or on `summary.json`. It:
1. checks every file in the bundle against `BUNDLE-SHA256SUMS`;
2. checks that the ZIP you give it has the hash in `PACKAGE.sha256`, and that `raw/identity.txt` names the same hash;
3. recounts PASS, FAIL, INFO, BLOCKED and NOT APPLICABLE from each raw result file;
4. compares its counts with those in `summary.json`.

It prints `VERIFIED` only when nothing fails and the counts agree.

Note: `BUNDLE-SHA256SUMS` proves that the files belong together. It does not prove who made them. For independent assurance, re-run the gate (below) or have a reviewer run the scripts against the ZIP on their own host.

## Contents
| Folder | What |
|---|---|
| `PACKAGE.sha256` | The package file name and its SHA-256. |
| `raw/` | The gate's output, unedited. |
| `raw/*.json` | One row per test: `id`, `area`, `title`, `status` and `evidence`. |
| `raw/paths.txt` | The path, header and HTTPS-redirect checks. |
| `raw/ui-*.json` | The layout runs, one per viewport, theme and zoom. |
| `raw/identity.txt` | The ZIP hash and the FILE-MANIFEST check. |
| `raw/run-all.log` and `raw/*.log` | The console output of the suites. |
| `raw/trapscan.txt` | The swipe-trap scan. |
| `raw/shots/` | Screenshots. They contain synthetic test data only. |
| `raw/summary.json` and `raw/test-matrix.md` | Produced from the rows by `scripts/aggregate.py`. |
| `logs/` | The full logs of `gate-run.sh`, `finish-run.sh`, the public-page check and the dark-mode check. |
| `beta-ops/` | The backup and restore scripts. |
| `beta-ops/beta-ops-test.txt` | The output of their 22-case test (sections A–F, F being the optional age-encrypted copy, plus four control runs of the R157 scripts that must show the R157 defects), run on the installed R163 package. `tegh-backup.sh` is the R158 script plus the optional age-encrypted copy added after R159 (off unless `TEGH_ENCRYPT_TO` is set); `tegh-pull-backups.ps1` is the Windows download script. |
| `beta-ops/compare-restore-r163.txt` | The comparison of the live and restored sites. |
| `scripts/` | Every gate script as run (with `zz-r158-slow-probe.php`, the slow test page that `beta-ops-test.sh` places on the test host only while it runs), including `run-all.sh`, `gate-run.sh`, `finish-run.sh`, `vol/` (the large-company seed and checks; the R161 matrix is not re-run for R163), `guided/setup.mjs` and `30-guided.mjs` (the Guided-mode suite), `31-r163.mjs` (Guided bank statements, automatic reconciliation and Connect with an accountant; its screenshots in `r163/` are not in the bundle), the suites `01-install.mjs` to `31-r163.mjs`, the journeys in `e2e/`, the layout matrix, the upgrade test and `verify-evidence.py`. |
| `reports/` | `FINAL-BETA-QA-REPORT.md` (see the R163 addendum), `BETA-READINESS.md` and `DATA-INVENTORY.md`. |

## What was left out, and why
- **The R152 statement benchmarks** (`conv/dots.mjs` and `conv152/`). They read the owner's real bank statements, which never leave the test host.
- **The pinned-clock file** `clock.env`. It is host-specific.

The other statement and invoice fixtures in `scripts/conv/` are synthetic.

**Credentials.** The test accounts and passwords in the scripts belong only to the isolated gate host, for example `owner@gate.test`. They are synthetic and grant nothing anywhere else.

## Re-running the gate
The test host is Ubuntu with:
- Apache on 443, 8443 and 9443 with a private CA;
- PHP 8.3-FPM;
- MariaDB;
- Node with Playwright and Chromium;
- a local SMTP sandbox.

Run `scripts/gate-run.sh <zip> <tag>`, then `scripts/finish-run.sh <zip>`. `FINAL-BETA-QA-REPORT.md` §16 describes the setup.

The results apply to this test host only. They do not cover your beta host until the same checks are run there.

# R175 gate results (R175 includes R174)

**Package tested:** `Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R175-PRIVATE-BETA.zip`

| Package detail | Value |
|---|---|
| SHA-256 | `3475f7331146ea72e0f78ed65996cd001eeff55291388a340546c5310d1cac42` |
| Files | 446, all mode 0644; FILE-MANIFEST 445 entries, all verified after unpacking (`identity.txt`) |
| Source commit | `fa6da6b` (`git archive fa6da6b:tegh-app`), branch `claude/invoice-credit-debit-audit-e2y8wc` |

**What is in it:**
- **R174 — Site & Release Settings** for the platform owner: release stage, sign-up, notice and labels, business
  details, policy pages and public wording (`tegh-app/R174-CHANGES.md`).
- **R175 — public pages and phones:** wider public pages, heading sizes, phone screenshots on phones, and the table
  Actions column pinned only on wider screens (`tegh-app/R175-CHANGES.md`).

**Where it was tested:** a local test host, freshly installed from the exact ZIP, with the clock pinned to
2026-10-01 Toronto (`clock-pin.txt`) and synthetic data only. This is not the IONOS host.

The cloud container had been replaced, so the test host was **rebuilt** for this gate
(`../scripts/gate-host-setup.sh`). Some differences from earlier gates come from that rebuild, below.

## Results

| Run | Result |
|---|---|
| New suite `48-r174`, Site & Release Settings (`r174.json`) | **21/21 PASS** |
| New suite `49-r175`, public-page layout, phone screenshots, Actions column (`r175.json`) | **6/6 PASS** |
| `47-r172`, `46-r171`, `45-r170` in the same run | 16/16, 29/29, 57 PASS · 6 INFO |
| Full regression, suites 01–49 (`run-all-gate.log`, which lists only lines that are not PASS) | 722 PASS · 6 INFO · 8 FAIL, and one suite stopped early; all below |
| End-to-end journeys; UI journeys (desk and phone) | 53/53; 22/22 PASS |
| Paths and headers | 45 PASS · 0 FAIL |
| Screen matrix: 20 runs, widths 320–1920, light and dark, zoom up to 200 %, side and top navigation (`matrix.log`) | 0 screens with issues |
| Phone swipe traps (`trapscan.txt`) | 0 |
| Upgrade from an R118 install with data (`upg-verify.log`) | **13/13 PASS**; re-run after fixing the tail script, below |

## Failures and what they are

### From the supplied base, unchanged since R165

- **PW-01, WR-09:** the "Canada, excluding Quebec" payroll wording.
- **BS-07, BS-09:** the Terms and Privacy pages.
  - These two can now be fixed without a release: publish your own Terms and Privacy versions in
    **Site & Release Settings › Policies**, using `{{legal_name}}`, `{{support_email}}` and the other placeholders.
  - Suite `48-r174` shows that published versions are what the pages show and what sign-up records (SR-09, SR-10,
    SR-UI-01).

### Caused by the rebuilt test host, fixed and re-run

| Check | What happened | Fix | Re-run |
|---|---|---|---|
| **R-19, R-29** (`31-r163`) | The rebuilt test config sent "Connect with an accountant" requests to a test address. The suite expects `enquiry@sraccountax.ca`, the address in the real config. | Test config corrected. The sandbox only writes mail to files. | Both PASS (`rerun-31-r163.log`) |
| **U-01** (`34-mfa`) | The test-only QR reader `jsqr` was not installed. | Installed. | `34-mfa` **17/17 PASS** (`rerun-34-mfa.log`) |
| **Suite `16-r145`** | Stopped early: its comparison copy of the R144 reader was missing. | Restored from git history (the reader just before the R145 commit). | 43 PASS · 2 FAIL, see below |
| **Upgrade test** | Did not start: the tail script did not pass on the setup-key path. | Fixed. | 13/13 PASS |

In the `31-r163` re-run, **R-47 and R-48** failed, though they passed in the gate run. They check the one-time "Welcome
back to Guided" notice, which the gate run had already shown to this user.

### Same result on R173 on this host, so not caused by R174 or R175

`41-r166` and `16-r145` were also run against **R173**, the release before R174 and R175, on the same host
(`r173cmp-*.log`). R173 fails the same three checks:

- **DD-01** (`41-r166`): clicking Banking in the top menu bar at 1,280 pixels times out.
- **DI-I6** (`16-r145`): the tiny low-contrast receipt photo, read by OCR.
- **DI-SCAN** (`16-r145`): a scanned PDF read by OCR.

R173 passed all three on the old test host. They depend on this container's fonts, image rendering and the
regenerated fixture images, not on the code changed in R174 or R175.

### Not run

- **`26-r158` and `27-r159`** were never saved to the repository and were lost with the old test host.

## Before the gate

- **Settings suite:** `48-r174` passed 21/21 on the working tree.
- **Public-page sweep:** all 24 public pages at six widths (320–1920) showed no browser errors, no sideways scroll,
  and every internal link working.
- **Phone screenshots:** captured from a synthetic sample company, "Cedar & Co. (Sample)", on a separate demo site,
  using `tests/demo-host.sh`, `tests/seed-demo.mjs` and `tests/phone-captures.mjs`.

## What each kind of evidence means

- **Automated tests:** executed on the local test host against the exact ZIP.
- **IONOS host checks:** none performed. Before beta use, deploy to staging and run the host checks in
  `tegh-app/R170-OPERATIONS.md` and `beta-ops/R164-OPERATOR-CHECKLIST.md`.
- **Config change for staging:** `app.public_signup_enabled` is no longer read. Public sign-up is now chosen in Site
  & Release Settings, and `app.public_signup_force_off => true` is the server's emergency stop.
- **Production:** not touched; it needs your separate authorization.
- **Browsers:** Chromium only.

# R174 + R175 progress

Status: COMPLETE — see RESULTS.md.

**Package:** `/srv/gate/Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R175-PRIVATE-BETA.zip`

| Package detail | Value |
|---|---|
| SHA-256 | `3475f7331146ea72e0f78ed65996cd001eeff55291388a340546c5310d1cac42` |
| Files | 446; FILE-MANIFEST 445 entries |
| Source commit | `fa6da6b` |

## Done

- **R174 (Site & Release Settings for the platform owner):**
  - Suite `48-r174`: 21/21 PASS on the working tree (before packaging).
  - R174 was never delivered on its own; R175 contains it.
- **R175 (public-page layout, heading sizes, phone screenshots; Actions column pinned only above 700 pixels):**
  - Suite `49-r175`: 6/6 PASS on the working tree.
- **Gate host rebuilt after the container was replaced:** `scripts/gate-host-setup.sh`.
  - Test certificates are issued from 1 January 2026, so the pinned clock accepts them.
  - The R118 upgrade site runs on port 8443.
  - Fixtures are regenerated. The R170 invoice set's `expected.json` is identical to R171's.
- **Demo site for the phone screenshots** (`https://gate.test:8444`, database `tegh_demo`):
  - Built by `tests/demo-host.sh` and filled by `tests/seed-demo.mjs`.
  - Captured by `tests/phone-captures.mjs` and `tests/phone-capture-client.mjs`.

## Known gaps in the rebuilt gate

- **Suites `26-r158` and `27-r159`** were never saved to the repository, so they are lost with the old host. They
  report "module not found".
- **The first R174 gate attempt** was stopped before it finished; it was replaced by the R175 run. On the way, it
  showed the certificate-date problem and the missing fixtures, both fixed.

## Resume after a container restart

1. `git fetch` and fast-forward the branch to `origin/claude/invoice-credit-debit-audit-e2y8wc`.
2. `bash beta-gate-evidence/scripts/gate-host-setup.sh`; this also needs apt packages mariadb-server, apache2,
   php8.3-fpm, the php8.3 extensions, faketime and poppler-utils.
3. Rebuild the ZIP from commit `fa6da6b` (`git archive fa6da6b:tegh-app`, files 0644, sorted `zip -X`). Check the
   SHA-256 above.
4. `: > /srv/gate/.gate-resume-state`, then `nohup setsid bash /srv/gate/t/gate-resume.sh <zip> r175`.
5. When `tail-run.log` shows GATE-RESUME-DONE, write `RESULTS.md` here, commit, push and send the ZIP.

# R177 progress

Status: FULL GATE RUNNING on the R177 ZIP.

**Package:** `/srv/gate/Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R177-PRIVATE-BETA.zip`

| Package detail | Value |
|---|---|
| SHA-256 | `9f3c32490e29e2a495b7cfe88eb8933c5a04d4c54423dc9281a76f1f3868f0a4` |
| Files | 453; FILE-MANIFEST 452 entries (verified) |
| Source commit | `11043aa` |

## Done

- `dashboard/summary` route, New dashboard switch, new dashboard page, cache token `5990-r177-dashboard`.
- Suite `51-r177`: 25/25 PASS on the gate host before packaging (working tree copied over the R176 install).
- `run-all.sh` now also runs `50-r176` and `51-r177` (copy in `tests/`).

## Resume after a container restart

1. `git fetch` and fast-forward the branch.
2. `bash /srv/gate/t/up.sh </dev/null` (or rebuild with `beta-gate-evidence/scripts/gate-host-setup.sh`).
3. Rebuild the ZIP from commit `11043aa` (`git archive 11043aa:tegh-app`, files 0644, sorted `zip -X`) and check the
   SHA-256 above. Copy `tests/51-r177.mjs`, `tests/run-all.sh` and `../r176/tests/50-r176.mjs` to `/srv/gate/t/`.
4. `nohup setsid bash /srv/gate/t/gate-resume.sh <zip> r177` (it resumes from `/srv/gate/.gate-resume-state`).
5. When `/srv/gate/ev/tail-run.log` shows GATE-RESUME-DONE, write `RESULTS.md` here, commit, push and send the ZIP.

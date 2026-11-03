# R172 complete functionality and UI test round — progress

Request (2026-10-28): "Start complete functionality and ui testing and fix the issues. If I am out of tokens, continue
testing once tokens reset without me having to give you the command again."

Status: COMPLETE — R172 delivered (see RESULTS.md).

## Plan
1. Bring the test host up on the R171 package, clock pinned (2026-10-01 Toronto).
2. Exhaustive sweep: every menu entry in every module (Full and Guided), every create form, register, report and
   export; desktop 1440 and phone 390; light and dark; side and top navigation. Record browser errors, failed API
   calls (4xx/5xx), sideways scroll, clipped or overlapping controls, empty or broken screens.
3. Functional journeys per module with synthetic data (create → edit → post → report → export → reverse).
4. Fix each real issue, add a regression check, re-run.
5. Package R172, full gate, evidence, deliver.

## Log
- 2026-10-28: built `deep172.mjs` (every menu screen, report, setting and portal entry point; explores tabs, filters,
  sections and the New/Create form without saving; records browser/console errors, failed API calls, slow screens,
  sideways scroll, off-screen/overlapping controls, clipped buttons, narrow fields, broken images, stuck loading).
- Test-host note: re-pinning the clock to 12:00 after a gate made audit writes refuse ("audit clock moved backwards",
  correct behaviour); `clock-pin.sh` now takes PIN_TIME; sweeps run with PIN_TIME=14:30.
- d1440 (owner, full, light, side): 115 screens. Real findings fixed:
  1. Tegh Assist page squeezed into a 190px column (question box 58px) → one full-width column (tegh-r120.css).
  2. Account & Access: Display name / Email fields 106–132px inside one-third cards → one column (tegh-r120.css).
  3. Create Vendor: vendor directory failed (422) because the vendor's default-currency field was sent as a directory
     filter → directories forward only q/status (tegh-professional-output-v5990.js).
- Other configurations running in the background (deep172-all.sh, state /srv/gate/ev/.deep-state).
- Fixed assets journey (UI): schedule $12,000 / 60 months → $200 a month, 8 periods due (Feb–Sep); posting Sep 30
  made one balanced entry Dr 6810-type expense / Cr 1590 $200 (probe chose 6000; the form defaults to 6810); posting
  Feb 28 (closed period) refused with a clear toast. Fix 4: lines in a closed period are now marked "Closed period ·
  reopen it to post" (no Post action) and the asset's "due" count leaves them out (advanced.php periodLocked,
  tegh-advanced-v5800.js).
- Sweeps done: d1440-dark-top (ran before fixes 1–3), p390, p390-dark (Invoice Intelligence search 95px on phones →
  fix 5, full-width filters), d1920 and d1024-top clean.
- Recurring invoice journey: profile created and run → INV-1010 $500 + $60 GST/PST (BC 12%) = $560, next date Nov 1.
- Budget journey: $1,200 for 6400 created; Budget versus Actual shows planned $1,200, actual $0 (matches the GL).
- Data import journey: customers CSV, 5 rows → 2 eligible, 2 errors (missing name, bad email), 1 duplicate; commit
  imported exactly the 2 eligible customers.
- Fix 6 (data loss): opening the GIFI report silently replaced a GIFI code the user had chosen (1500: 1774 → 1740)
  and wrote an "updated 0" audit row on every visit. The workspace now carries each account's GIFI code, standard
  suggestions fill blanks only (server-enforced `onlyBlank`), and no audit row is written when nothing changed.
- Fix 7: the company feed missed documents made by recurring profiles, new fixed assets and completed imports; they
  now appear (e.g. "issued recurring invoice INV-1010 to Pacific Client BC · $560").
- Fix 8: Invoice Intelligence on phones was laid out in about twelve implicit grid columns (shell cards carry
  12-column spans), squeezing every section; the queue grid is now one explicit column (search 95px → 347px).
- New suite `47-r172.mjs` (fresh company, screens driven as a person would): 14/14 PASS — vendor directory, Assist
  width, account fields, GIFI codes kept, fixed asset schedule/closed-period lines/posting/server refusal, recurring
  invoice, budget, data import, feed coverage, phone filters, no browser errors.
- Fix 9: on wide reports (P&L by month, registers) the table's "Actions ⋮" menu, which holds Export…, Choose
  columns… and Filter columns…, sat past the right edge of the table and had to be scrolled to. The actions column is
  now pinned to the right edge with a shadow; rows without row actions are not pinned (tegh-r120.css).
- Fix 10: on Budgets and Fixed Assets the "Export ▾" menu opens over the page, and the moved PDF and Excel buttons
  could no longer find their page, so nothing downloaded ("Report unavailable"). They now resolve the page that
  owns the open menu (tegh-professional-output-v5990.js `ownerPage`).
- Export sweep (`export172.mjs`, every report, every format offered): 70 downloads across 28 reports, all the right
  format and non-empty; three CSVs are header-only because this company has no products or reconciliations (checked
  in the database). Three screens have no export (Tegh Intelligence, GL Account Report before it is generated,
  Pay Run Register with no runs).
- Sweeps finished: v1440, v390 (viewer), e1440 (editor), d1440-empty (new company, no data). Viewer and editor
  findings are all from the sweep calling a screen directly that their menus do not offer (Opening Balances, Client
  Viewing Links, Pay Run Register); the server refuses them (403), as it should. Empty company: 0 findings.
- `47-r172.mjs` now 16/16 PASS (adds RT-13 Fixed Assets Export ▾ PDF+Excel, RT-14 P&L Actions ⋮ on screen + PDF).
- Next: optional journeys (interbank transfer, collection drafts), then 47-r172 into run-all.sh, docs, manifests,
  package, full gate.
- Packaged: R172-CHANGES.md, README/DEPLOYMENT-NOTES, cache token 5990-r172-fixes, manifests (source commit 88cfcde).
  ZIP `/srv/gate/Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R172-PRIVATE-BETA.zip`, 431 files, SHA-256
  `e0da5ad581bed5f414e2fb98cb4e30d1ae22e1167358002b24565df2f437bcaf`; FILE-MANIFEST 430 entries verified.
- 47-r172 added to run-all.sh. Full gate started: `gate-resume.sh <zip> r172` (state /srv/gate/.gate-resume-state).
  RESUME: if the container restarted, run `nohup setsid bash /srv/gate/t/gate-resume.sh <zip> r172` again (it skips
  finished suites); when tail-run.log has GATE-RESUME-DONE, write RESULTS.md, commit, push, send the ZIP, set Status
  COMPLETE and delete the check-in trigger.

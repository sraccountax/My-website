# R172 complete functionality and UI test round — progress

Request (2026-10-28): "Start complete functionality and ui testing and fix the issues. If I am out of tokens, continue
testing once tokens reset without me having to give you the command again."

Status: IN PROGRESS

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

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

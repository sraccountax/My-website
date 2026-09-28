# Reproduce the local verification — Tegh5980

These are private engineering tools; do not upload the evidence archive to public web space.
Extract the evidence ZIP into one working folder. Extract the cumulative application ZIP into its `source/` subdirectory. Limited baseline files needed for comparison are included under `baseline/`. All names in fixtures are fictional.

Required for these checks: PHP CLI, Node, Python, Playwright and Chromium (`/usr/bin/chromium` in the recorded environment), PyMuPDF for PDF inspection, and artifact_tool for workbook import/render. PHP pure tests use explicit fictional substitutes for unavailable extensions/DB; they must never be cited as real MySQL integration. The source app itself contains no such stubs.

Run from the evidence working folder, sequentially:

```sh
mkdir -p evidence tests/fixtures
php tests/runtime_readiness.php > evidence/runtime-readiness.json
# Exit77 above is expected on this recording host; it is BLOCKED, not success.
php tests/php_unit.php "$PWD/source" "$PWD/evidence" > evidence/php-unit-run.txt
php tests/subledger_unit.php "$PWD/source" > evidence/subledger-unit-results.json
node tests/statement_dates.mjs "$PWD/source" "$PWD/evidence"
node tests/deletion_transition.mjs "$PWD/source" "$PWD/evidence"
python tests/build_browser_harness.py
python tests/browser_offline_smoke.py > evidence/browser-smoke-run.txt
python tests/interaction_evidence.py > evidence/interaction-run.txt
python tests/negative_browser.py > evidence/negative-browser-run.txt
python tests/report_contract_browser.py > evidence/report-contract-browser-run.txt
python tests/output_evidence.py > evidence/output-run.txt
python tests/output_structure.py > evidence/output-structure-run.txt
python tests/static_validation.py > evidence/static-validation-run.txt
```

The three browser harness adjustments are explicit: synthetic shell/auth/fetch/storage, origin replacement for an offline document, and disabling automatic shell `init()` so exported real production renderers can be invoked. The converter preview renderer is exposed only inside the fixture. No altered harness file is shipped as an app asset.

Screenshots labelled offline are actual application-renderer images with fictional data, not authenticated staging screenshots. Narrow viewport checks are not native browser zoom. The spreadsheet-render checks import actual adapter output with artifact_tool; no desktop Excel repair dialog was tested. PDF text is inspected with PyMuPDF.

The original failed first-draft tests found two real regressions addressed in source: lost-start404 key loss and mismatched/insufficient dated-subledger reporting. Later fixture corrections included opening a collapsed document menu before clicking its button, allowing text extraction line wrapping when matching a reference, and inspecting the migration checksum in its actual recovery file. The final scripts retain the strict accounting/authorization expectations; final results do not contain fabricated skipped-gate passes.

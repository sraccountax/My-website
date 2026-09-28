# Tegh R17 responsive workspace

Complete Tegh version 5.9.9 / Build 5990 / Schema 45 application based on the browser-tested R16a runtime. R17 changes the active frontend workspace only. All 75 files under `api/` remain byte-identical to R16a, and no database migration is required.

## Workspace and navigation

- Uses a shared 56px global header and keeps company, search, Create, Tegh Assist, notifications and profile actions directly accessible.
- Uses 224px expanded and 64px collapsed side navigation. Top-navigation mode is now genuinely full width and no longer creates a compulsory utility rail.
- Keeps the application shell constrained to the viewport and blocks document-level horizontal and vertical scrolling. Ordinary custom pages use one predictable internal vertical scroll owner. Reconciliation retains two independent list scrollers because users compare both sides.
- Adds module/page breadcrumbs while retaining separate contextual Back controls.

## Tables, filters and reports

- Removes the R16 mobile 620px table dependency. Wide tables show the full task-relevant layout; medium tables show up to five priority columns with accessible row details; phone layouts become labelled stacked records.
- Keeps amounts complete, right-aligned and tabular. Comfortable table text is 14px; the existing compact preference selects 13px rows.
- Uses sticky table headers with opaque backgrounds, subtle borders, alternating rows and compact semantic status badges.
- Condenses primary filters into a sticky row and shows active values as removable chips when they are optional.
- Consolidates authorized data-view export controls into one scope-labelled Export menu while retaining their original handlers.
- Adds compact report identity and stronger accounting hierarchy for sections, subtotals and final totals.

## Dashboard and workflows

- Keeps four real balance cards first, puts P&L period totals beside their chart, stacks charts using their available content width and removes the permanent right-hand Quick Actions column.
- Uses one signed P&L chart for both dashboard surfaces. Zero values have zero-height bars, reversals appear below zero, and month drilldowns preserve exact start/end dates.
- Improves cash forecast labels with real dates, a zero reference, keyboard-focusable values and a complete exact-data table.
- Keeps Bank Statement and Books side by side only when both panes have usable width; narrower layouts provide explicit tabs without destroying selections.
- Replaces generic payroll section generation with Pay period, Employees and earnings, and Review sections.
- Adds complete tablist/tab/tabpanel relationships and arrow/Home/End keyboard behavior to true tab interfaces.

## Validation

- 136 unique source and transformation assertions pass across prior runtime, Schema 45, interbank lifecycle, OCR/PDF, R16a regression, chart and R17 responsive suites.
- The 12 R16a hotfix checks also pass under both America/Toronto and Pacific/Auckland time zones.
- All 71 JavaScript/module files pass `node --check`; all 12 JSON files parse; all 75 API files match R16a byte for byte.
- PHP command-line syntax checking was unavailable in this build workspace. Authenticated R17 browser checks must be performed after this exact ZIP is uploaded to IONOS staging.

## Installation

Back up the existing site and data. Extract the complete ZIP into the IONOS `/Books-Test` directory so `app.html` and `api/` are directly inside it. Replace application files while preserving the live configuration, private uploads/storage and database. Do not delete the site first. Reload `app.html` and verify that active assets use cache revision `5990-r17`.

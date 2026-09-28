# Tegh R25 changes

## Exact Preferences preview

The old hand-built miniature in **Tegh Preferences** is replaced by a real screenshot preview generated from the Tegh native React shell and the packaged frontend source. Sixteen 1600×900 WebP images cover side/top navigation, light/dark theme, comfortable/large text and comfortable/compact density. Changing a preference updates the preview immediately. The image is labelled as fictional sample data so it cannot be confused with a live tenant screenshot.

## Scrolling repair

R24 could produce an overflowing register whose `scrollHeight` was larger than its client height while wheel and End/PageDown input did not move the active region in the reproduced Chromium path. R25 fixes the shared ActivityShell rather than one invoice screen:

- clears only stale scroll semantics previously added by ActivityShell;
- reassigns the current route-owned scroll region after rerenders;
- prefers the authoritative R23 register scroller where present;
- provides a guarded local wheel fallback only when the selected task region can actually move;
- provides Home/End/Page Up/Page Down/Arrow movement when the scroll region itself has focus;
- does not intercept Ctrl+wheel, form input keystrokes or a nested child scroller;
- reserves real height and visible overflow for task/report regions.

Customer and vendor invoice registers were exercised at 1920×900, 1536×760, 1366×768 and 1280×650 with final rendered rows reached by wheel plus keyboard. Dialog close, resize and sidebar collapse were also retested.

## Whole-application audit

A local native-shell audit invoked all 102 declared menu/actions visible to the full-accounting fixture across Dashboard, Receivables, Payables, Payroll, Banking, General Ledger, Advanced Accounting, Reports and My Account. It required an observable route/dialog/request, no new JavaScript error, no error/unavailable toast, no document-level horizontal overflow and successful wheel/End/Home movement for any overflowing assigned task region.

This is a broad navigation/render/reachability smoke audit. It does not turn those 102 actions into 102 end-to-end accounting transaction tests. Existing deeper suites were also retained for invoice/report workflows, opening balances, exports, register filtering/columns/actions, navigation and backend read/write safeguards.

## Theme parity

Newer R22/R23 surfaces had white unlayered backgrounds that could win over the selected dark theme. R25 adds presentation-only parity rules for the global header, dashboard cards, shared tables, menus/dialogs, fields and status surfaces. Accounting values and state are unchanged.

## Backend boundary

All 74 PHP files and both SQL files are byte-identical to R24. R25 has no schema migration and no accounting/payroll formula change. Existing backend service tests use their supplied SQLite/permission/mail/file adapters; real MariaDB, hosted middleware, SMTP and private storage remain staging acceptance work.

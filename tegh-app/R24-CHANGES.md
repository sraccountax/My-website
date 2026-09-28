# R24 — Focused navigation repair

## Cause

The R22 ActivityShell implementation removed `.tegh-top-primary` on every navigation/page refresh and replaced it with a native module selector. Its second mode button defaulted to Full Accounting even when the original mode chooser was unset. The portal returned early whenever ActivityShell was present, so its real horizontal menu builder never ran.

## Correction

- The portal owns navigation/menu actions; ActivityShell owns layout only. The real permission-filtered module menus are mounted in the existing header, with original Activity/Reports handlers, not recreated accounting actions.
- Remove the substitute Modules select, second mode button and obsolete utility rail. Keep one authoritative accounting-mode control and preserve saved navigation preferences.
- Adaptive More uses measured free header width. It resets to its module list when reopened, retains active-module indication, and includes keyboard/escape/tab/outside-click focus handling. Dropdowns are viewport-bounded and scroll vertically when necessary.
- Align all header controls on one centreline. Company/search/Create/Assist/notifications/current mode/profile remain accessible in expanded sidebar, collapsed sidebar and top navigation. Compact headers use Search's existing full dialog instead of squeezing an input.
- At narrow widths, accounting-mode settings and Assist are in Profile. The current mode stays visible beside company context. These entries invoke existing guarded functions.
- Unify the navigation/drawer threshold at 820 CSS px so the 761–820px gap has working navigation. Move the original authorized Create/company controls, never clone them. Keep a single visible drawer close button, a full-width in-drawer company chooser, bounded scroll regions, readable brand/current item and restoration on resize.
- Add one navigation-scoped stylesheet after existing styles. No report grids, calculations, routes, endpoints, invoice payloads, schema, server posting or permissions are rewritten.

## Preserved source

All PHP and SQL files, report/table models and register export/column/filter implementations, R20 invoice services and the opening-balance implementation remain unchanged from R23. R24 changes shell/navigation functions and their responsive presentation only.

## Evidence boundary

Native React shell/full portal init are exercised under fictional local auth/API transport. Gate/login, live database, IONOS deployment, real mail, every report/role and the complete R3 99% task matrix are not certified. The separate report identifies current executed tests; do not inherit older releases' acceptance claims.

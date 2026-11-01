# Tegh dashboard: implementation plan

This folder holds a working prototype of the new Tegh dashboard. It brings together six pieces designed earlier:
the layout, the animated charts, dark mode, the collapsible sidebar, the empty states and the loading placeholders.
This plan explains how to move it into the Tegh app (as release R177), how to test it and how to keep improving it.

Nothing here is part of the `tegh-app` package yet. The prototype uses made-up figures for "Cedar & Co. (Sample)".

## 1. What is in this folder

| File | What it is |
|---|---|
| `index.html` | The combined dashboard page. Open it in a browser; it needs no server. |
| `dashboard.css` | Page theme (light and dark) and layout. |
| `dashboard.js` | Controller: loads the data, fills each panel, handles the period, theme, empty, error and slow states. |
| `demo-data.js` | Two sample answers from `dashboard/summary`: a busy company and a brand-new company. |
| `assets/tegh-charts.*` | Animated charts: headline number, bars, line with forecast, ranked bars, waterfall. |
| `assets/tegh-sidebar.*` | Collapsible sidebar: full menu, icon rail and phone drawer. |
| `assets/tegh-skeleton.*` | Loading placeholders that cross-fade to the real content. |
| `demos/` | The stand-alone demo page of each piece. |
| `tests/dashboard.test.mjs` | 13 browser checks (section 5). |

## 2. How the page fits together

```
┌ Sidebar (tegh-sidebar) ┐┌ Top bar: menu (phone) · search · theme · + Create ───────────────┐
│ Dashboard              ││ Greeting · period (Quarter / 6 / 12 months)                        │
│ Banking  (14)          ││ ┌ Cash at bank: number + 13-week forecast ┐  ← or the setup steps  │
│ Receivables ›          ││ └──────────────────────────────────────────┘    for a new company │
│ …                      ││ [Customers owe you] [You owe suppliers] [Profit this year]        │
│ Books up to date ▓▓▓░  ││ [Money in and money out ──────────────] [Needs you]               │
│ Priya Sharma           ││ [Who owes you] [Where the money goes] [Recent activity]           │
└────────────────────────┘└────────────────────────────────────────────────────────────────────┘
```

- **One request, many panels.** The page asks the server once (`GET dashboard/summary`). Each panel has its own
  placeholder and shows its part as soon as the answer arrives.
- **Every panel has four states:** loading (placeholder), ready (figures and charts), empty (a message and the next
  step) and error (a message and Try again). The empty state is chosen from the data, never from a missing request.
- **Period changes do not reload.** The summary holds 12 months; Quarter and 6 months just animate the same chart.
- **Remembered per person:** theme, sidebar rail and period (browser now; account later, section 4.4).

## 3. Server: `GET dashboard/summary`

A new read-only route. It never writes, posts or changes anything.

### 3.1 Answer

Amounts are in cents, like the rest of Tegh. `demo-data.js` is a complete example.

```json
{
  "company":     { "name": "…", "currency": "CAD", "asOf": "2026-10-31", "isNew": false, "userFirstName": "Priya" },
  "setup":       { "done": 1, "total": 4, "steps": [{ "id": "bank-import", "title": "…", "detail": "…", "done": false, "action": "Import statement" }] },
  "cash":        { "balanceCents": 0, "changeCents": 0, "changeSince": "YYYY-MM-DD", "accounts": [{ "name": "…", "balanceCents": 0 }],
                   "weeks": [{ "weekEnding": "YYYY-MM-DD", "balanceCents": 0, "forecast": false }], "lowNote": "…" },
  "receivables": { "totalCents": 0, "previousCents": 0, "overdue30Count": 0, "overdue30Cents": 0, "trend": [0], "buckets": [{ "label": "…", "cents": 0, "followUp": false }] },
  "payables":    { "totalCents": 0, "previousCents": 0, "dueThisWeekCount": 0, "dueThisWeekCents": 0, "trend": [0] },
  "profit":      { "ytdCents": 0, "previousYtdCents": 0, "marginPct": 0, "lastYearMarginPct": 0, "trend": [0] },
  "cashFlow":    { "months": [{ "month": "YYYY-MM", "inCents": 0, "outCents": 0 }] },
  "expenses":    { "periodLabel": "…", "items": [{ "label": "…", "cents": 0, "other": false }] },
  "todo":        [{ "severity": "crit|warn|calm|good", "status": "…", "icon": "…", "title": "…", "detail": "…", "route": "…" }],
  "activity":    [{ "icon": "…", "who": "…", "what": "…", "amountCents": 0, "when": "…" }]
}
```

`cash` and `profit` are `null` when there is nothing to show yet, so the page shows "—" with a reason rather than a
misleading $0.

### 3.2 Where each part comes from (existing Tegh code)

| Part | Source in `tegh-app/api` |
|---|---|
| `cash.balanceCents`, monthly change | `tegh_ins_cash($company)` in `insights_r144.php` (bank ledger accounts, posted entries only) |
| `cash.weeks` (actual and forecast) | `financial_cash_forecast($company)` in `financial_forecast_v5300.php` |
| `profit` this year and last year | `tegh_ins_profit($companyId, $from, $to)` in `insights_r144.php`, using the company's fiscal year |
| `receivables.buckets`, `payables` | The ageing report (`portal_aging` and `portal_aging_profile` for the company's own cut-off days) |
| `cashFlow.months` | Posted bank-account movements by month (the same query `tegh_ins_cash` uses, split into in and out) |
| `expenses.items` | Posted expense accounts this fiscal year, top five plus "Everything else" |
| `todo` | Counts the app already shows: bank lines to sort, overdue invoices, bills due, next sales-tax return, month-end checklist |
| `activity` | `activity_feed_r171.php` (it already hides payroll and email details by permission) |
| `setup` | Company record, bank imports, opening balances and invoices exist or not |

### 3.3 Rules for the route

1. `require_user()` and `require_company()`, exactly like `workspace`. Company isolation: every query filters by the
   company id from the session, never from the request body.
2. Permissions: leave out parts the person may not see (for example payroll amounts, or receivables for a role
   without them). Leaving a part out makes the page show its empty state instead of an error.
3. Posted entries only, the same as the reports, so every dashboard number matches a report.
4. Time limit like `workspace` (30-second statement cap). Target under 300 ms for a company with 50,000 journal lines.
5. Optional short cache (60 seconds per company), cleared whenever an entry is posted or reversed.

## 4. Moving the prototype into the app

### 4.1 Files

Copy the three components into `tegh-app/assets` with release-stamped names, following the repository's habit:
`tegh-charts-r177.js/.css`, `tegh-sidebar-r177.js/.css`, `tegh-skeleton-r177.js/.css` and
`tegh-dashboard-r177.js/.css`. Load them from `app.html` with a new cache token. `demo-data.js`, `demos/` and the
Preview bar are not shipped.

### 4.2 Dashboard page

In `tegh-portal-v5990.js`, `openDashboard()` renders the new layout and starts it with:

```js
window.TeghDashboardConfig = {
  fetchSummary: () => api('dashboard/summary'),          // adds the company and session headers
  navigate: route => DASHBOARD_ROUTES[route]?.(),        // see below
  sidebar: false                                          // the portal mounts the sidebar itself (4.3)
};
```

`DASHBOARD_ROUTES` maps each button to a page opener that already exists, for example `aged-ar` →
`openAgeing('receivable')`, `pl` → `openFinancialReport('profit-loss')`, `bank-import` →
`openCurrentStatementImporter()`, `bills` → the bills list. The current dashboard's "Customize" figures
(`dashboard-mappings`) keep working: the summary uses the mapped accounts where a company has set them.

### 4.3 Sidebar

Build `sections` from the portal's existing `menus`, filtered with `menuItemAllowed` so each person sees only what
their role allows. `onNavigate` calls `invokeMenuAction`. Badges come from the same counts as Needs you. The phone
drawer replaces the current phone menu.

### 4.4 Remembering preferences on the account

Tegh already saves `sidebarHidden` through `agent/interface-preferences`. Add two fields there: `sidebarRail`
(true/false) and `theme` (`light`, `dark` or `system`). The server must list them as allowed fields. Until then the
browser keeps them.

### 4.5 Dark mode

The dashboard and the app frame (sidebar, top bar) are ready for dark mode. Other pages (registers, forms, reports)
still use light colours. Ship dark mode in two steps: first the dashboard and frame, with the theme switch shown only
there; then the rest of the app page by page, using the same colour names.

### 4.6 Turning it on safely

Add a "New dashboard" switch to Site & Release Settings (R174) with three values: off, platform owner only,
everyone. Start with "platform owner only" on staging, then everyone. The old dashboard stays available until the
new one has run in production for a full month-end.

## 5. Testing

### 5.1 Prototype checks (done)

`node design/tegh-dashboard/tests/dashboard.test.mjs` runs 13 checks in Chromium. Latest run: 13/13 pass.

| Check | What it proves |
|---|---|
| D-01 | Every panel loads; no script errors |
| D-02 | Figures and totals agree with the data (cash, owed, profit, money in and out, expenses, Needs you count) |
| D-03 | Quarter shows 3 months, updates the totals and is remembered |
| D-04 | A new company sees the setup steps and empty states, and no empty charts |
| D-05 | A server error shows the banner and panel errors; nothing is left loading; recovery clears it |
| D-06 | On a slow network the placeholders show first |
| D-07 | Light and dark mode switch and are remembered |
| D-08 | The sidebar rail is remembered after a reload |
| D-09 | Phone drawer opens, locks the page behind it, closes with Esc and returns focus |
| D-10 | No sideways scrolling at 390, 768, 1024 and 1440 pixels |
| D-11 | Keyboard: arrow keys on the money chart read out each month |
| D-12 | With motion turned off, figures appear at their final value at once |
| D-13 | Every button has a name, every picture a description; no panel left busy; one page heading |

### 5.2 Tests to add in the app (R177 gate)

1. **Accuracy:** for synthetic companies, every dashboard number equals the matching report on the same date:
   cash = bank accounts on the trial balance; owed = aged receivables total; buckets = aged receivables columns;
   profit = profit and loss for the fiscal year to date; money in and out = bank account movements. Include a fiscal
   year that does not start in January, a foreign-currency bank account, a negative bank balance, a company with no
   bank account, and a closed period.
2. **Company isolation:** a person in company A gets 403 for company B's summary, and B's figures never appear.
3. **Permissions:** a bookkeeper without payroll sees no payroll amounts in Needs you or Recent activity; a read-only
   person sees figures but no action buttons that change data.
4. **Read-only:** row counts of every table are unchanged after 100 summary requests.
5. **Speed:** under 300 ms with 50,000 journal lines; the statement cap stops a runaway query.
6. **Screens:** the existing screen matrix (all widths, light and dark) plus the 13 checks above against the real
   page; screenshots reviewed by a person.
7. **Assistive technology:** a manual pass with NVDA (Windows) and VoiceOver (iPhone).
8. **Full regression:** the whole gate suite and the upgrade from R118, as for every release.

### 5.3 Release path

Gate on the test host → staging on IONOS with synthetic data (owner-only switch) → a week of use → production
**only with your separate authorization**. No production records are touched by any of these tests.

## 6. Improving it after launch

**Measure** (counts only, no financial data leaves the company):

- time until the first figure appears, and how often a panel fails;
- which Needs you items are clicked, and how fast they are cleared;
- for new companies: how many finish the setup steps, and how long the first bank import takes;
- how many people switch to the old dashboard, and why (a one-question prompt).

**Next ideas**, in rough order of value:

1. Needs you items that can be done right there (send a reminder, approve a bank line) without leaving the page.
2. Forecast what-ifs: "What if this invoice is paid late?" on the cash chart.
3. Let each person hide, move and resize panels (extends today's Customize).
4. A monthly email or PDF of the dashboard for owners who do not log in often.
5. Comparisons with the same month last year on every chart.

## 7. Known limits of the prototype

- Sample data only; buttons announce where they would go instead of opening pages.
- The search button and + Create are not connected.
- The Preview bar (busy, new, slow, error) exists only for review and is not shipped.
- The money chart shows CAD only; multi-currency companies need a currency note (the server converts to the
  company currency, as the reports do).

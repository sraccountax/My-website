# R178 — Client Dashboard Demo

R178 = R177 plus a demo of the client dashboard for the platform owner, and one fix to the R177 dashboard.

## Client Dashboard Demo (platform owner)

Shows a client what their Tegh dashboard looks like, using made-up figures for "Cedar & Co. (Sample)". No company
is opened, and nothing is read from or written to any company's books.

**Where:** Settings › Platform Admin › **Client Dashboard Demo** (second in the list), the account menu, or the
**Client dashboard demo** card on Platform Owner Home.

| Control | What it does |
|---|---|
| An established business | Cash and a 13-week forecast, who owes them, what they owe, profit this year, money in and out, Needs you, who owes them by days overdue, where the money goes |
| A brand-new business | The "Get your books going" steps and the empty panels a new client sees |
| Replay | Shows the loading placeholders and animations again |
| Present full screen | Shows only the dashboard, full screen, for a meeting or screen share (Esc to leave) |

- The figures follow today's date, so the demo always looks current.
- Buttons on the demo do not leave the page. They say what the button opens in a client's own account (for example
  "the list of who owes them").
- It works whether the New dashboard switch (R177) is off, owner only or everyone.
- Only the platform owner sees it. Other users get a short notice if they open its address.

## Fix to the R177 dashboard

The illustrations on empty panels (the setup picture beside "Get your books going" and the money in and out
picture) were drawn without their teal and gold lines when shown outside a chart. They now use the dashboard's
colours, in light and dark mode.

## Files

| Area | Files |
|---|---|
| Browser | `assets/tegh-dashboard-demo-r178.js` (new, sample figures; loads only when the demo opens); `assets/tegh-portal-v5990.js`; `assets/tegh-gate-v5990.js`; `assets/tegh-dashboard-r177.css` |
| Server | No change |
| Database | No change |
| Cache token | `5990-r178-demo` |

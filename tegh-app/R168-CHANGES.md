# R168 — working in several tabs

R168 = R167 plus better support for using Tegh in several browser tabs at once. Server code, accounting and the
database are unchanged; only browser files changed.

## Open any screen in a new tab

- **Ctrl+click** (**Cmd+click** on a Mac), **Shift+click** or **middle-click** opens the item in a new browser tab.
  This works on:
  - a sidebar or top-bar menu entry;
  - a note or invoice number in a register;
  - a register's **View** buttons;
  - **View note**, **View vendor invoice** and **View invoice details** in a row's Actions menu.
- The new tab opens in the same company as the tab you clicked in. The tab you clicked in stays where it was.
- A plain click works as before.

## Each tab keeps its own company

- Switching company in one tab no longer reloads your other Tegh tabs or loses their unsaved work. Each tab stays on
  its own company, so two clients can be open side by side.
- A new tab starts in the company you used last.
- Every request still names its company, and the server checks your access to that company each time, exactly as
  before. An address naming a company you cannot open is ignored, and Tegh opens one of your own companies.
- Signing out in one tab still signs out every tab.

## Addresses, refresh and Back

- The address now names the tab's company as well as the screen (`#tegh=<screen>&co=<company>`). A copied link
  opens the same screen in the same company for anyone who has access to it.
- Most screens were already restored on refresh. Measured on 74 menu entries and reports, 68 came back. The six that
  did not now do:
  - **Create Customer** and **Create Vendor** reopen the new-customer or new-vendor form, not the list.
  - **Customer and vendor documents** (invoice, credit note and debit note forms) reopen the same document, or a
    blank form of the same kind. They used to fall back to the Dashboard.
  - The **GIFI Report** and the **Tax Code Report** reopen. They used to fall back to the Dashboard.
  - The new vendor invoice and credit/debit note pages from R165 also reopen.
- During start-up the Dashboard could briefly take over the screen named in the address. The address then still
  read Dashboard, so a second refresh lost your place. The address is now corrected after a screen is restored. If
  the Dashboard takes over before you click or type anything, your screen is restored again.

## Not changed

- Tabs do not update each other live. A change saved in one tab appears in another when that screen is reopened or
  refreshed.

## Files

- `assets/tegh-preflight-v5990.js`: per-tab company (loads before every other script).
- `assets/tegh-portal-v5990.js`: addresses with the company; new-tab opening; restoring the screens listed above;
  start-up fix.
- `assets/tegh-registers-r23.js`: addresses on register record links and View actions.
- `assets/tegh-downloads-r15.js`, `assets/tegh-professional-output-v5990.js`: no longer cleared by another tab's
  company switch.
- `app.html`, `assets/tegh-gate-v5990.js`: cache token `5990-r168-tabs`.
- Manifests and these notes.

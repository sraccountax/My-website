# R131 changes over R130: phone sidebar

5.9.9 / Build 5990 / Schema 46. Cache token `r131-tegh`. No database migration. Browser files only.

## What was wrong
On phones and small tablets (up to 820 px wide), the sidebar opens as a drawer from the ☰ button.
- **Submenus stayed open.** Tapping a module (for example Banking) opened its page, but also expanded its submenu in the drawer, and it stayed expanded. The next time the drawer opened, the list was longer than the screen, so it had to be scrolled.
- **Guided mode and the ▸ arrow.** In guided mode, or when tapping the arrow, a tap only expanded the submenu and did not open anything.
- **Drawer header overlap.** The logo's tagline slid under the company chip. In light mode the "TEGH" wordmark was white on white.
- **Dark mode.** The drawer showed dark-green text on a dark background, left over from an older phone style that assumed a white drawer.

## What changed
- **One tap opens the page.** Tapping a module in the drawer opens that module's page with its Activity and Reports sections, and closes the drawer. This applies in both full and guided mode, whichever part of the row is tapped.
- **No submenus on phones.** Submenus and their arrows are hidden in the drawer. The drawer lists only the modules, so it fits the screen.
- **Scrolling.** On very short screens the module list still scrolls, with iOS-friendly touch scrolling.
- **Resizing.** Crossing the 820 px width closes submenus. Going back to a wider window reopens the submenu of the module on screen.
- **Drawer header.** Logo and TEGH wordmark sit above the company chip. The tagline is hidden in the drawer.
- **Dark mode.** The drawer has a dark background with light text. The current module is highlighted in mint. The company chip, Settings and account rows are readable.
- **Desktop is unchanged.** Wider screens keep the expanding sidebar submenus exactly as before.

## Verified
Tested with Playwright (Chromium).
- **Phone, full mode (390×844):** each of the 8 modules opens its page and closes the drawer, with no submenu expanded. Dashboard and Reports open their own pages; the others show Activity and Reports. The drawer list fits the screen (487 of 487 px).
- **Phone, guided mode:** Home, Money In, Money Out, Banking and Reports each open their page. Previously Money In and Money Out only expanded.
- **Short phone (360×560):** the drawer list scrolls with a touch swipe, and tapping a module opens its page.
- **Tablet (768×1024):** the same behaviour, with no header overlap.
- **Desktop (1440×900), full and guided mode:** behaviour unchanged. The module's submenu expands with its items, as before.
- **Light and dark drawer:** checked at 390 and 768 px.
- **Regression:** all menu screens load on desktop, phone and tablet with no errors or sideways scrolling; idle page activity is 1 change; the dark-mode contrast check is clean.

## Files
- **Changed:** `assets/tegh-portal-v5990.js` (phone navigation), `assets/tegh-r120.css` (section 28)
- **Cache token only:** `assets/tegh-gate-v5990.js`, `assets/tegh-preflight-v5990.js`, `app.html`
- **New:** `R131-CHANGES.md`

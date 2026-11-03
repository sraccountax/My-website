# R173 results

**Package:** `Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R173-PRIVATE-BETA.zip`

| Package detail | Value |
|---|---|
| SHA-256 | `deee7140d9b2cad6219d7a858638b68a04ad52bb84c152978a008931d07d65a9` |
| Files | 435, all mode 0644; FILE-MANIFEST 434 entries, all verified after unpacking |
| Source commit | `195c58b` (`git archive 195c58b:tegh-app`), branch `claude/invoice-credit-debit-audit-e2y8wc` |

**What R173 changes** (see `tegh-app/R173-CHANGES.md`): the TEGH public pages and the sign-in screen now use the design
of sraccountax.ca. The design was read from the live site's stylesheet on 30 October 2026.
- **Changed:** style sheets, two web fonts and page head tags.
- **Unchanged:** the words on the pages, the signed-in app, accounting, the server and the database.

## What was checked, and where

The checks ran on a local PHP preview of the files unpacked from the exact ZIP. This is not the IONOS host.

The preview has no database, so the browser answered the two sign-in-page API calls the way a signed-out visitor
gets them:
- `auth/security`: public sign-up off;
- `auth/me`: not signed in.

| Check | Result |
|---|---|
| Public-page sweep (`public-sweep.json`, `tests/mkt173.mjs`): all 24 public pages (20 pages and 4 guides) at 320, 390, 768, 1024, 1440 and 1920 wide | **144 renders, 0 problems**: no browser errors, no failed requests, no sideways scroll, both web fonts loaded, Playfair Display on every main heading, header and footer present |
| Internal links on those pages | 24 checked, all resolve |
| Sign-in screen at 1920, 1440, 1024, 768, 390 and 320 wide | no browser errors, no sideways scroll |
| Sign-in, Create account (invitation code) and the emailed-code step with the device set to dark mode | stays light; text `#14242d` on white, headings `#0b2a3a` |
| Phone menu on the public pages | opens as a white drop-down menu; Escape closes it |
| Content-Security-Policy in `.htaccess` | `font-src 'self'` and `style-src 'self'` already allow the new fonts and stylesheet, all served from the site itself |

Found and fixed during these checks:
- **Privacy page on narrow phones:** a long slash-joined word pushed the page 18–88 px sideways at 320 and 390 wide.
  Long words now wrap.
- **Phone menu button:** it sat next to the logo instead of at the right edge.
- **Sign-in card:** the teal-to-gold bar spilled past the card's rounded corners.

**Not run:**
- **The full regression gate.** The test host, with its database, web server, test certificate authority and mail
  sandbox, was lost when the container restarted. Rebuilding it is a separate step.
  - R173 changes no server code, script or database. Its only effect inside `app.html` is the new stylesheet, and that
    stylesheet's rules apply only to the sign-in screens and the "Opening your workspace" screen.
  - The R172 gate results still describe the server and the signed-in app. Rebuild the gate host and run the full gate
    before beta use if you want this exact ZIP covered by it.
- **The IONOS host:** no checks were done there. Production was not touched.
- **Browsers:** Chromium only.

## Contrast note (for the owner)

The teal labels above the headings use sraccountax.ca's teal, `#18a999`, as the site does. Against white that is
2.9:1, and 2.6:1 on the pale hero band. WCAG AA asks for 4.5:1 at this text size.

Body text, buttons and links all pass:

| Text | Contrast |
|---|---|
| Ink on white | 14.9:1 |
| Muted text | 4.9:1 |
| Teal links (`#0b665d`) | 6.8:1 |
| Labels on navy | 8.7:1 |

If you prefer, the labels can use the darker teal `#0b665d` (6.8:1), on Tegh and on sraccountax.ca. That would differ
slightly from the site.

## Screenshots (`shots/`)

| What | Files |
|---|---|
| Before | `before-home-1440.png`, `before-signin-1440.png` |
| sraccountax.ca for comparison | `sraccountax-home-1440.png` |
| After | `after-home-1440.png`, `after-home-390.png`, `after-pricing-1440.png`, `after-signin-1440.png`, `after-signin-390.png`, `after-create-account-device-dark.png`, `after-phone-menu.png` |

# R173 — Public pages and sign-in match sraccountax.ca

R173 = R172 plus a new look for the TEGH public pages and the sign-in screen. They now use the design of
sraccountax.ca. **The words on the pages, the signed-in app, accounting and the database are unchanged.**

## The design taken from sraccountax.ca

The design was taken from the live site's stylesheet on 30 October 2026.

| Element | Design |
|---|---|
| Text | Manrope |
| Headings | Playfair Display |
| Colours | navy `#0b2a3a`, teal `#18a999`, gold `#d8b15b`, ink `#14242d` |
| Header | white and sticky, with rounded menu links |
| Buttons | navy, rounded |
| Cards | white, with soft shadows |
| Strip | a navy strip below the hero |
| Footer | deep navy |

TEGH keeps its own name and mark.

## Public pages

The public pages are the home page, Product, Why TEGH, Pricing, FAQ, Contact, the policy pages and the four
accounting guides. They all share one stylesheet, `assets/marketing-r164/website.css`, which was rewritten.

- **Header:** the TEGH mark sits in a rounded tile. **Sign in** is a light-teal link and **Start free** is a navy
  button.
- **Hero and page introductions:** a pale green-to-grey band behind the hero and each page introduction, with
  Playfair Display headings and a teal label above them.
- **Application previews:** shown in white cards with the navy title bar of the dashboard preview on sraccountax.ca.
- **Pricing:** a white card with the teal-to-gold bar along its top.
- **Other cards:** the boundary and value cards lift on hover, and each question in the FAQ sits in its own card.
- **Policy pages:**
  - the review-draft banner is in the gold notice style;
  - the version box has a teal edge;
  - links are teal.
- **Contact form:** a white card with the site's form fields.
- **Phones:** the menu button opens a white drop-down menu, as on sraccountax.ca.
- **Accounting guides:** these four pages use older page markup, which the earlier stylesheet barely styled. They
  now get the same look.
- **Light only:** the pages no longer have a dark version, matching sraccountax.ca. They no longer follow the
  device's dark mode.
- **Head tags:** `theme-color` is now `#0b2a3a` and `color-scheme` is `light`.
- **Long words:** a long slash-joined word on the Privacy page used to push the page sideways on narrow phones. Long
  words now wrap.

## Sign-in screen

This covers sign-in, Create account, the invitation code, the emailed code and password reset. It uses a new
stylesheet, `assets/tegh-signin-r173.css`, loaded last in `app.html`. It applies only before sign-in, plus the
"Opening your workspace" screen.

- **Left panel:** the navy panel of the About section on sraccountax.ca, with a Playfair Display heading, a teal
  label above it and teal check marks.
- **Right panel:** a white card with the teal-to-gold top bar.
  - The Sign in and Create account tabs are underlined in teal.
  - The buttons are navy.
  - The links are teal.
- **Light only:** the screen stays light even when the device uses dark mode.

## Fonts

The fonts are the same files sraccountax.ca uses. Both are under the SIL Open Font License; the licence files are
in `assets/marketing-r164/fonts/`.

| Font | File |
|---|---|
| Manrope | `assets/marketing-r164/fonts/manrope-latin-wght-normal.woff2` |
| Playfair Display | `assets/marketing-r164/fonts/playfair-display-latin-wght-normal.woff2` |

`Manrope.ttf` is removed; nothing uses it any more.

## Files

| Change | Files |
|---|---|
| New | `assets/tegh-signin-r173.css`; the two `.woff2` fonts; `assets/marketing-r164/fonts/PlayfairDisplay-OFL.txt` |
| Rewritten | `assets/marketing-r164/website.css` |
| Head tags only (font preloads, theme colour, colour scheme, stylesheet version) | the 20 public pages and the 4 guides |
| Sign-in stylesheet link | `app.html` |
| Removed | `assets/marketing-r164/fonts/Manrope.ttf` |

The cache token in `app.html`, `assets/tegh-gate-v5990.js` and `assets/tegh-preflight-v5990.js` is now
`5990-r173-sr`.

There is no database change and no server change.

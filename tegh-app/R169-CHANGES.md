# R169 — zoom on a phone

R169 = R168 plus working pinch-zoom on phones. Server code, accounting and the database are unchanged; only style
sheets changed.

## What was wrong

- On a phone, the style sheets limited touch gestures to one-finger vertical scrolling, so the browser ignored a
  two-finger pinch. This affected the sign-in page and every signed-in screen.
- The same rules stopped a zoomed view from moving sideways, so part of the screen was out of reach.

## What changed

- Pinch-zoom works on every screen, including the sign-in page.
- A zoomed screen can be moved left, right, up and down with one finger. Zoom back out with a pinch.
- Without zoom, scrolling works as before: one finger scrolls the screen, and the page never scrolls sideways.
  Lists inside the drawer, dialogs and the transaction review queue still keep their own vertical scrolling.
- While a dialog or the drawer is open, the screen behind it still does not scroll, but it can be zoomed.

## Files

- `assets/tegh-mobile-r30.css`: touch gestures allow pinch-zoom and sideways movement; a gesture that reaches the
  edge of a scrolling area moves the zoomed view.
- `assets/tegh-r119.css`, `assets/tegh-r120.css`, `assets/tegh-audit-r25.css`, `assets/tegh-r27.css`: tables and the
  sidebar menu allow pinch-zoom.
- `app.html`, `assets/tegh-gate-v5990.js`, `assets/tegh-preflight-v5990.js`: cache token `5990-r169-zoom`.
- Manifests and these notes.

# R176 — Site & Release Settings easier to find

R176 = R175 plus one change in the signed-in app.

## What changed

R174 added **Site & Release Settings** for the platform owner, but the page could only be opened from a card on
Platform Owner Home. It is now also listed:

- **Settings › Platform Admin › Site & Release Settings** (first item in the list);
- in the account menu, after **Registration & Invitations**;
- in the search box (type "Site & Release").

Only the platform owner sees it. The page itself is unchanged.

## Where the platform owner controls are

| Control | Tab |
|---|---|
| Release stage (Beta / Production) | Release |
| Public sign-up with or without an invitation code | Sign-up |
| "Private beta · sample data only" notice, version label, Beta labels | Notice & labels |
| Business name, address, contact and privacy-officer details | Business details |
| Privacy, Terms, Security, Cookies, DPA and Subprocessors pages (draft, publish, unpublish) | Policies |
| Beta wording on the public pages | Public wording |

## Package

| Item | Value |
|---|---|
| Files changed | `assets/tegh-portal-v5990.js`; cache token in `app.html`, `assets/tegh-gate-v5990.js`, `assets/tegh-preflight-v5990.js` |
| Cache token | `5990-r176-settings` |
| Database | No change |
| Server (PHP) | No change |

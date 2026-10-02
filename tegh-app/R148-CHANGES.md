# R148 changes over R147: Sign in and Create account tabs, account creation through an invitation link

5.9.9 / Build 5990 / Schema 46. Cache token `5990-r148-tegh`. No database migration.
Status: **staging candidate**, `productionReady: false`. The host acceptance items are still open.

## The sign-in screen (app.html)
Two tabs are always shown: **Sign in** and **Create account**. Left/right arrow keys move between them.

| How the person arrives | What the Create account tab shows |
|---|---|
| The link in the "Set up your Tegh account" email (`/app.html#accountSetup=…`) | **Create your account**: invited email (read-only), the companies and roles, **Your name** (pre-filled from the email address, editable), password and confirmation, Terms and Privacy acceptance. Creating the account opens Tegh signed in. |
| The same link, for someone who already has a Tegh account | **Accept your invitation**: current password only, with a link to reset a forgotten password. |
| No link, while sign-up is invitation-only (the beta default), including the website's **Join Beta** buttons (`?register=1`) | How invitations work, the email to look for, an **Invitation link** box for pasting the link when the email's link doesn't open, "Ask for beta access", and Sign in. |
| No link, when public sign-up is switched on | The existing open sign-up form. |
| An expired or already-used link | "This invitation link has expired or has already been used", the paste box, and Sign in one click away. |

- **Keeping the invitation.** Switching to **Sign in** and back keeps the invitation.
- **Link security.** The token is still read from after `#` (R141) and is removed from the address bar.
- **Old links.** Links that still carry the token after `?` continue to work.
- **Removed:** the separate single-form "Set up your password" screen.

## Server (api/invitations_v5980.php)
- **Name.** Accepting an invitation stores the name the person typed (up to 160 characters; control characters and `< >` are refused). The email-derived name is used only if the field is empty.
- **Suggested name.** The invitation lookup returns `suggestedName` for new users.
- **Unchanged:** permissions, the 10-attempts-per-15-minutes limit, and the Terms/Privacy recording.

## Tests
- **New test file:** `17-r148.mjs` (13 browser and server checks, plus a check for JavaScript errors).
  - **Invitations:** real invitations from the API, with links read from the mail sandbox.
  - **Tabs:** both tabs, Join Beta, switching tabs and keyboard navigation.
  - **Account creation:** creating an account with a typed name (stored together with the role and terms), and accepting as an existing user.
  - **Bad links:** pasted good and bad links, and a used link.
  - **Layout:** phone width.
  - **Server:** name validation.
- **Gate clock pinned.** Some hand-computed figures assume "today = 2026-10-01", so the gate host's clock is now pinned to that day:
  - PHP-FPM and MariaDB through libfaketime;
  - the workflow-suite browser through Playwright's clock.

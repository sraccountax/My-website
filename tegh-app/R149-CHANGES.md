# R149 changes over R148: invitation code instead of an invitation link

5.9.9 / Build 5990 / Schema 46. Cache token `5990-r149-tegh`. No database migration.
Status: **staging candidate**, `productionReady: false`. The host acceptance items are still open.

## How an invited person creates an account
1. **The email.** It is still titled "Set up your Tegh account". It contains:
   - an **invitation code** (10 letters and numbers, shown as `K7M2Q-PX9RT`);
   - the invited email address;
   - the address of the Create account page (`/app.html?register=1`);
   - the steps.

   It contains no setup link and no token.
2. **Create account tab.** The person enters the **email address** and the **invitation code**, then selects Continue. The code is formatted as it is typed, and lower case and spaces are accepted.
3. **Account details.** The form shows the invited email, the companies and roles, **Your name**, password and the Terms. Someone who already has a Tegh account confirms their current password instead.
4. **Sign in.** Creating the account opens Tegh signed in.

## Security
- **Code characters.** Codes use 31 characters that cannot be confused; 0, 1, O, I and L are never used. That gives about 50 bits of randomness.
- **Storage.** The server stores only a fingerprint of the code combined with the invited email, so a code is useless with any other address.
- **Lifetime.** A code works once and expires 72 hours after it is sent. Resending or cancelling an invitation replaces or stops the code.
- **Guessing limit.** Wrong codes count against both the email address and the network address: after 10 within 15 minutes, look-ups return "Too many attempts" (429). Correct codes never use up the allowance, so an office inviting several people from one network is not blocked. (In R148, look-ups by link were not limited.)
- **Messages.** "That email address and invitation code don’t match an open invitation" is shown for a wrong code, a wrong email or a used code alike, so the message never reveals which part was wrong.
- **Older invitations.** Links in invitations sent before R149 keep working until they expire. Resending such an invitation sends a code.

## Files
- `api/invitations_v5980.php`: code generation, the code email, look-up and acceptance by email + code, and the guessing limit.
- `app.html`, `assets/tegh-gate-v5990.js`, `assets/tegh-r120.css`: Email address and Invitation code fields on the Create account tab, formatting, and messages.
- `assets/tegh-portal-v5990.js`: the admin Cancel prompt now says the code will stop working.

## Tests
- **New test file:** `18-r149.mjs` (18 checks, plus a check for JavaScript errors). It replaces `17-r148`.
  - **Email:** content; no link or token.
  - **Screen:** both tabs, Join Beta, formatting as typed, the account form, switching tabs and keyboard navigation.
  - **Account creation:** with a typed name, and accepting as an existing user.
  - **Refusals:**
    - a wrong code;
    - the right code with the wrong email;
    - a used code;
    - characters that are never used in codes.
  - **Layout:** phone width.
  - **Server:** name validation.
  - **Guessing limit:** 429 after 10 wrong codes; 12 correct look-ups in a row all succeed.
- **Updated tests:** `02b-invite.mjs` and `13-r141.mjs` (OB-01 to OB-05) now use codes.

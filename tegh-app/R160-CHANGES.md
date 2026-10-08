# R160 changes over R159: invitations that fit the person, and a clearer Invitations & Access page

5.9.9 / Build 5990 / Schema 46. Cache token `5990-r160-tegh`.
Status: **private beta candidate** (invitation-only, sample data only). `productionReady: false`.
Database: no change. Privacy Notice version 2026-10-08 (terms unchanged at 2026-10-07).

## What an invitation can do depends on who it is for
Type the email first; Tegh checks it and shows only what fits.

| The email is… | What you can do |
|---|---|
| **New to Tegh** | Invite them to **set up their own companies**, to **work in your companies** (pick each company and role), or both, in one invitation. They choose a password when they accept. |
| **Already registered and active** | Only **give access to more of your companies**. Companies they already have are shown as "Already has access" and cannot be picked again. They accept with their current password. An "own companies" invitation is refused (409 `invitation_user_exists`): any Tegh login can already create its own companies. |
| **Registered before, now deactivated** | No invitation (409 `invitation_user_deactivated`). The page says the login was deactivated and when, and offers **Restore login and send password email**. |
| **Registered before, login deleted** | No invitation and no second account (409 `invitation_user_deleted`). The page says the email was registered before and its login was deleted, and when, and offers **Restore login and send password email**. |

**Restore login** (platform owner only):
- A deactivated login is reactivated with the company access it had.
- A deleted login is the same login brought back (same user id): the email is put back, the name is reset to a default, and it has no company access. The person who invited them gives access again; the page offers "Give them access to companies now" straight after.
- Every older password link stops working, all sessions are ended, and one new link valid for 72 hours is created.
- The person is emailed **"Your Tegh login has been restored"**: it says the login for that email was previously deactivated (or deleted), that Tegh support restored it, and gives the link to choose a new password. No invitation email is sent.
- Recorded in the platform audit log as `platform.user_login_restored` (previous state and the email's fingerprint).

**How a deleted email is recognised.** Tegh does not keep a deleted login's email. Since R156, deleting a login keeps the anonymised login and a SHA-256 fingerprint of the old address in the platform audit log; R160 matches a typed email against that fingerprint. Logins deleted before R156 have no fingerprint and are treated as new addresses.

**Company admins** (Account & Access › Users) keep their invite form. The same rules apply on the server; for a deactivated or deleted address they are told to ask Tegh support to restore it.

New API actions on `POST platform/invitations` (platform owner only):
- `{action:"lookup", email}` → `status` (`new`, `registered`, `deactivated`, `deleted`), dates, the companies the person already has, and any pending invitation.
- `{action:"restore_login", email}` → `restored`, `was`, `emailSent`, `linkValidHours`. Requires the operator details in config.php, like invitations.

## Invitations & Access page
Platform Owner Home › **Invitations & Access** (was "Registration, Invitations & Email Health") now has three tabs:
- **Invite someone:** 1 Email → 2 What they get → 3 Send. The choices are two cards ("Set up their own companies", "Work in my companies"); the company list opens only when needed, scrolls inside itself, and each company has its own role.
- **Sent invitations (count):** each invitation on one row with its email, what it gives, when and by whom it was sent, its status and email delivery, and **Send a new code** while it is waiting.
- **Sign-up & email:** public sign-up switch and email delivery status, as before.

The invitee's page now also says when an invitation includes "Your own workspace (set up your own companies)".

## Also in R160
- **Company directory (Platform Owner Home):** the count shows active companies and, separately, archived ones; archived rows are labelled "Archived" (R159 known issue).
- **`api/.htaccess` states `RewriteBase /api/`,** so friendly API routes such as `/api/health` work on IONOS shared hosting (they returned 404).
- **Privacy Notice:** explains the fingerprint kept for a deleted login and the restore email.

## Gate tests
**28-r160 (9 checks).**
- IN-01 new email: one invitation gives their own workspace and a company; they can create their own company.
- IN-02 registered email: own-workspace refused; more companies accepted with the current password.
- IN-03 deactivated: invitation refused; restore reactivates with access; email says "previously deactivated"; the link sets a new password.
- IN-04 deleted: recognised by fingerprint; invitation refused; restore brings back the same login id; email says "previously deleted"; the link sets a new password.
- IN-05 only the platform owner can look up or restore; a company admin is told to ask Tegh support.
- UI-01…04 the page in the browser: tabs, new/registered/deleted emails, restore from the page, sent list, phone width.

**27-r159 INV-01** now expects a deleted address to be refused and pointed to Restore login, instead of getting a second account.

## Upgrading from R159
- Back up first.
- Upload the whole package (cache token r160-tegh). New file: `api/invitations_r160.php`.
- If you already edited `api/.htaccess` on the host for RewriteBase, the packaged file is the same change.
- No database change. Beta-ops scripts are unchanged from the post-R159 versions (optional encrypted copy, Windows download script).

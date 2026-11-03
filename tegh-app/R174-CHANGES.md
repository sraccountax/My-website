# R174 — Site & Release Settings (platform owner)

R174 = R173 plus a **Site & Release Settings** page for the platform owner. The things that change when Tegh moves
from the private beta to a regular production release can now be changed on screen, without a new release and
without editing files.

Open it from **Platform Owner Home › Site & release settings**.

## What can be changed

| Tab | What it controls |
|---|---|
| **Release** | **Beta** or **Production**. On Production, the sign-in screen no longer says "invitation-only beta" and the sign-up forms link "Terms of Use" instead of "Beta Terms of Use". |
| **Release** | **Apply production settings**, one step: Production stage, top-bar notice off, version label hidden, Beta labels hidden. |
| **Release** | **Where things stand**: a checklist of every setting below, and the change history. |
| **Sign-up** | **Invitation only**, or **Invitation or open sign-up**: anyone can create an account on the sign-in screen, and invitation codes keep working. |
| **Notice & labels** | The top-bar notice: on or off, its label ("Private beta · sample data only") and its full text. |
| **Notice & labels** | "Version 5.9.9" at the bottom of the side menu: shown or hidden. |
| **Notice & labels** | The "Beta" labels and reminders on Document Intake and the PDF statement reader: shown or hidden. |
| **Business details** | Legal name, address, privacy and support emails, hosting provider, data location, backup location, email provider and the two day counts. An entry here replaces the config.php value; a blank field keeps it. |
| **Policies** | The Privacy Policy, Terms of Use, Security Statement, Cookies & Privacy Choices, Data Processing Addendum and Subprocessors pages (details below). |
| **Public wording** | The 15 lines on the public pages that mention the beta: replace the wording or hide the line. Examples are "Free during beta · no automatic paid conversion", "Beta terms", "Beta pricing", "What does beta cost?", the footer policy-draft note, and the guides' "Private beta" lines. |

The Sign-up setting is the same one as on Invitations & Access › Sign-up & email.

## Policies

Each policy has versions, and only a published version is public.

- **Drafts:**
  - Write a draft from scratch, or start one from the current public page with **Start from the current public
    page**.
  - **Preview** it, then **Save draft**.
  - Drafts are private.
- **Publishing:**
  - **Publish** needs a reason.
  - The public page shows the new version at once: title, line under the title, effective date, version and
    sections.
  - A published version cannot be edited or deleted. To change it, start a new draft from it.
- **Going back:** **Go back to the built-in page** stops showing published versions. The version history is kept.
- **Review-draft banner:** optional, and shown on the public page.
- **Text format:**
  - Plain text with a few marks: `## heading`, `### smaller heading`, `- bullet`, `1. numbered`, a blank line
    between paragraphs, `**bold**`, and `[text](https://…)` or `[text](mailto:…)` links.
  - Other links are shown as text.
  - Markup is shown as text and never run.
- **Business details in policy text:** `{{legal_name}}`, `{{address}}`, `{{privacy_email}}`, `{{support_email}}`,
  `{{hosting_provider}}`, `{{data_location}}`, `{{backup_location}}`, `{{mail_provider}}`,
  `{{backup_retention_days}}` and `{{deletion_response_days}}` are filled in from the Business details.
- **Versions people accept:**
  - Sign-up and invitation acceptance record the published Terms of Use and Privacy Policy versions, or the built-in
    pages' version when none is published.
  - An invitee who opened an invitation before a new Terms version was published is asked to accept the new one.

## Safeguards

- **Who:** only the platform owner can read or change these settings. Everyone else is refused (403), and signed-out
  requests are refused (401).
- **Recent sign-in:** every change needs one. Tegh asks for the password when the sign-in is older than 15 minutes.
  Saving a policy draft doesn't need one; drafts are not public.
- **Reason and history:**
  - Every change needs a reason of at least 8 characters.
  - It is kept with the old and new values in `site_setting_history`, and in the platform audit log.
  - Publishing, going back to the built-in page and deleting a draft are audited too.
- **Conflicts:** a save names the revision it was based on. If someone else changed the settings since, it is
  refused (409).
- **Emergency stop for public sign-up:** the server's `config.php` has one, `app.public_signup_force_off => true`. It
  overrides the owner's choice.
  - Before R174, `app.public_signup_enabled` also had to be `true` in config.php. That key is no longer read, so the
    owner's choice now decides.
  - Public sign-up stays off until the owner turns it on.

## Defaults

Until the owner saves a setting, Tegh behaves as R173:

- Beta stage.
- The top-bar notice is taken from config.php `app.beta_notice`.
- The version and Beta labels are shown.
- Business details come from config.php.
- The public pages show their own wording and their built-in policy text.

## Technical notes

- **New tables,** created automatically the first time the settings are used: `site_settings`,
  `site_setting_history` and `site_policy_versions`.
  - Tegh never creates them inside a transaction, because a table change would end that transaction early.
  - No manual migration is needed. To roll back, drop the three tables; the built-in behaviour returns.
- **New public routes** (no sign-in, read-only): `site/public` returns the stage, wording changes and published policy
  versions; `site/policy&key=…` returns one published policy.
- **New owner routes:** `site-admin` (read) and `site-admin/save`, `site-admin/policy-draft`, `site-admin/policy-delete`,
  `site-admin/policy-publish` and `site-admin/policy-unpublish`.
- **Public pages:** the 24 public pages load `assets/tegh-site-r174.js`, which applies the wording and published
  policies. If it cannot reach the server, the page keeps its built-in text.

## Files

| Change | Files |
|---|---|
| New | `api/site_settings_r174.php`, `assets/tegh-site-r174.js` |
| Changed (server) | `api/index.php`, `api/auth.php`, `api/captcha.php`, `api/legal_r156.php`, `api/release_v5980.php`, `api/invitations_v5980.php`, `api/monitoring_r164.php`, `config.example.php` |
| Changed (browser) | `assets/tegh-portal-v5990.js` (settings page, labels, notice); `assets/tegh-gate-v5990.js` (sign-in wording on Production); `assets/tegh-r157.css` |
| Changed (public pages) | the 20 public pages and the 4 guides (`data-site-text` marks and the script); the 6 policy pages also carry `data-policy` |
| Cache token | `5990-r174-site` in `app.html`, `assets/tegh-gate-v5990.js` and `assets/tegh-preflight-v5990.js` |

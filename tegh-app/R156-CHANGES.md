# R156 changes over R155: fixes from the owner's review of the private beta package

5.9.9 / Build 5990 / Schema 46. Cache token `5990-r156-tegh`.
Status: **private beta candidate**: invitation-only, sample data only. `productionReady: false`. No database change.
R156 replaces R155 as the one beta package.

## Terms and Privacy (version 2026-10-07)
- **Beta conditions are in the Terms users accept.** A new "Private beta" section covers:
  - invitation only;
  - free;
  - sample data only (no real clients, employees, bank accounts or tax numbers);
  - data may be reset or deleted, including at the end of the beta;
  - no guarantee of availability;
  - not for real filings or payroll;
  - feedback;
  - leaving the beta.
- **Who provides the service.** The Terms and Privacy pages show the operator's legal name, address, support and privacy contacts, hosting provider, data location, off-site backup location and email provider.
  - These come from a new `operator` section in `config.php`, because they differ for every deployment.
  - While any of them is blank, both pages show "This notice is incomplete…", and **Tegh refuses to send invitations** (409 `operator_details_missing`), on both invitation paths.
- **Privacy notice.** New sections:
  - who is responsible;
  - your own information as a user or beta tester (real personal information even in a sample-data beta);
  - where information is stored and who processes it;
  - retention, including beta resets and the backup period (`backup_retention_days`);
  - access, correction and deletion, with a practical request procedure and response time (`deletion_response_days`);
  - what happens if something goes wrong.
- **Website measurement wording.** It now says exactly what the code does: a first-party daily count of nine named website actions on Tegh's own server, with no names, emails, IDs, IP addresses or cookies, and nothing sent to an analytics company. Tegh uses no third-party analytics, advertising or session recording.
- **Versions.** The pages' version text, their version tag and the server's recorded version all moved to **2026-10-07** together. The gate checks that the recorded version equals the printed one (24-beta BS-07).

## Account deletion requests
- "Delete login" (Platform owner › Users) already:
  - replaced the person's name and email;
  - removed their password, sessions and company access.

  From R156 it also replaces their email address in invitations, sent-email records, the platform log and the incident log. The deletion record keeps only a SHA-256 of the old address (24-beta BS-08).
- Company audit history keeps the address for as long as that company exists, because the address is part of each entry's tamper-evident hash. The Privacy Notice says so.

## Backups (beta-ops scripts, outside the ZIP)
- **Consistent snapshot.** The backup pauses changes with Tegh's maintenance flag; the app answers "Tegh is making a backup" with 503 and writes nothing. It accepts the backup only if the check figures are identical before and after the dump and file archive.
- **Restore safety.** The restore runs only into a designated restore-test target, and refuses:
  - a database name without "restore";
  - the live database;
  - the live storage folder or anything inside or around it;
  - a folder without a `.tegh-restore-target` marker naming the database.
- **Empty installations** back up and restore correctly.

## HTTPS
- Plain-HTTP requests for **any** host name now redirect to HTTPS on the same host, for example a separate beta address.
  - Before, only books and books-test.sraccountax.ca were redirected; those two keep their `www.`-removing rules.
  - A port in the Host header is dropped.
  - A request that already arrived over HTTPS through the host's TLS proxy is not redirected again.

## Files
- `api/legal_r156.php` (new)
- `api/index.php`, `api/invitations_v5980.php`, `api/platform.php` (operator details, invitation guard)
- `api/admin.php` (deletion clean-up)
- `api/bootstrap.php` (backup maintenance message)
- `api/release_v5980.php` (versions)
- `privacy.html`, `terms.html`
- `assets/tegh-legal-r156.js` (new), `assets/tegh-marketing-v5900.css`
- `.htaccess`
- `config.example.php` (`operator` section)
- Cache token: `app.html`, `assets/tegh-gate-v5990.js`, `assets/tegh-preflight-v5990.js`, `assets/tegh-portal-v5990.js`, `assets/tegh-bank-converter-v5990.js`
- `README.txt`, `DEPLOYMENT-NOTES.txt`, `R156-CHANGES.md`
- The manifests

## Upgrade note
Add the `operator` section (see `config.example.php`) to the live `config.php` before or right after uploading R156. Until it is complete, invitations are refused and the Terms and Privacy pages say they are incomplete.

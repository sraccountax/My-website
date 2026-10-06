# R155 changes over R154: the private beta package

5.9.9 / Build 5990 / Schema 46. Cache token `5990-r155-tegh`.
Status: **private beta candidate**: invitation-only, sample data only. `productionReady: false`. No database change.

R155 is the one package for the invitation-only beta. It is R154 plus the fixes found while checking the release for the beta. There are no new features beyond the beta notice.

## Fixed
- **Terms acceptance recorded the wrong version (DEF-17).**
  - The published Terms and Privacy pages say "document version: 2026-10-01".
  - Their hidden version tag, and the version Tegh records when someone accepts an invitation, still said 2026-09-09.
  - Every acceptance therefore named a version the person had not been shown.
  - From R155, both the pages and the server use 2026-10-01, and the gate checks that the recorded version equals the version printed on the pages (24-beta BS-07).
  - Acceptances recorded before R155 keep 2026-09-09.
- **README said the manifests "identify this package as R151".** It now names the current release, and the manifest generator keeps that sentence in step.
- **Manifest history was off by one.**
  - Rebuilding a release (R153 was built twice, for example) moved the previous release's identity into the wrong `rNNNIdentity` entry: `r152Identity` held R153.
  - Each `rNNNIdentity` is now rebuilt from the last manifest committed for that release.

## Added
- **Private beta notice.** A badge in the top bar for every signed-in user, beside the company selector. It reads "Private beta · sample data only", or "Beta" on narrow screens. Hovering or tapping it shows the full text set by `app.beta_notice` in `config.php`. Nothing shows when the setting is blank or missing. `config.example.php` suggests: "Private beta: use sample data only. Do not enter real client, employee or bank records. Beta data may be reset." It is readable in light and dark mode (contrast 6.8:1 and 10.0:1); the gate checks that nothing covers it at desktop and phone widths.

## Checked for the beta (no change needed)
These were checked by the new gate suite `24-beta`:
- Public sign-up is closed by default.
- Another company, or a user without access, cannot download a company's uploaded files.
- A removed member loses access at once.
- A revoked invitation cannot be used.
- Password reset works end to end and ends earlier sessions.
- Backups restore uploaded files byte for byte.
- A repeated payment or a double-click records once.
- Every posted entry balances.
- Foreign-currency invoices add to combined totals at their CAD amount.

The statement re-upload duplicate check now runs in every gate run. A host-level backup was also restored into a separate installation and verified (see `beta-ops/` in the repository).

## Files
- `api/release_v5980.php` (terms and privacy versions)
- `privacy.html`, `terms.html` (version tag)
- `api/auth.php` (`betaNotice` in the sign-in payload)
- `assets/tegh-portal-v5990.js` (notice)
- `assets/tegh-r120.css`
- `config.example.php` (`beta_notice`)
- Cache token: `app.html`, `assets/tegh-gate-v5990.js`, `assets/tegh-preflight-v5990.js`, `assets/tegh-bank-converter-v5990.js`
- `README.txt`, `DEPLOYMENT-NOTES.txt`, `R155-CHANGES.md`
- The three manifests and `FILE-MANIFEST.sha256`

## Setting up the beta host
1. Add `'beta_notice' => '…'` to the `app` section of the beta site's `config.php`.
2. Keep `public_signup_enabled` false.
3. Follow `beta-ops/RECOVERY-AND-ROLLBACK.md` for daily backups and the restore test.

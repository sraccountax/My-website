TEGH 5.9.9 / BUILD 5990 / SCHEMA 46 — SITES R117 + DEBIT-NOTE OUTPUT HOTFIX
IONOS STAGING CANDIDATE, generated 2026-09-28T06:24:46Z

This is a complete flat-root web application package. The 223 public files shared
with the private Site v117 source at commit e33a278ff46f6e1e057eb9bfe94dc52461640794
match byte-for-byte. The two PHP files listed in PACKAGE-MANIFEST.json are replaced
by the exact bytes of the R117 output hotfix. No other application file differs
from the R117 full IONOS package. The Site-only _headers file is not an Apache file
and is intentionally omitted. No database migration is introduced by the hotfix.

DN-1001 was already posted once. Do NOT post it again. Its DN-001 journal was
Dr AR 5.65, Cr revenue 5.00, Cr GST/HST 0.65. The current invoice balance is
4.15 after 1.50 in credits. The owner reports the overlay was uploaded, but this
package does not prove the serving-host hashes or PHP syntax.

Read DEPLOYMENT-NOTES.txt before uploading. Upload the ZIP contents at the web
root, not the ZIP's containing folder. Preserve private config and runtime data.
This is not an accepted production release.

R118: server-side account guard, metadata denied by .htaccess, integrity lists refreshed. See R118-CHANGES.md.

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

R119: invoice/note tabs, one Invoice & Note Register per side, invoice tax-split repair (notes and sign-in), scrolling report tables, mobile layout and export fixes. No migration. See R119-CHANGES.md.
R120: credit/debit notes as itemized documents (returns), render-loop fix for dropdowns/scrolling, sidebar and layout polish. Auto-creates table accounting_note_lines. See R120-CHANGES.md.
R121: end-to-end functional test fixes: bulk bank posting, contra and exclude actions, match exact pairs, Reconcile Bank Account menu, GST/HST remittance from the bank feed, Add Employee save, Budgets header duplication, desktop tables scroll instead of stacking, premium PDF/Excel totals and invoice PDF. No migration. See R121-CHANGES.md.

# R180 — unused files removed

R180 = R179 with 46 files removed that nothing in Tegh loads (about 3.2 MB). No code, server or database change; every
remaining file is identical to R179.

## Removed

| Files | Why they are unused |
|---|---|
| `assets/og/` (24 social-preview images, PNG and SVG) | No page names them. The public pages carry no social-preview image since the R164 redesign |
| `assets/tegh-icons-v4140.svg`, `tegh-motion-v4140.css`, `tegh-motion-v4140.js`, `tegh-marketing-v4140.css`, `tegh-marketing-v4140.js`, `tegh-social-v4140.png` | Old public-site files replaced in R164; no page loads them |
| `assets/marketing-r164/` balance-sheet, banking, day-book, guided, pay-run, payables, profit-loss, receivables and invoice-create `.webp` (9) | Screenshots the R164 pages do not show (they use overview, invoicing, expenses, matching, payroll, reports and the phone versions) |
| `assets/marketing-r164/fonts/InstrumentSerif.ttf` and its licence | No style sheet uses the font |
| `assets/tegh-icon-180.png` | Byte-identical copy of `tegh-icon-180-v4600.png`, which the pages use |
| `api/schema44_v5980.sql` | Not read by installation or upgrade: a fresh install uses `schema.sql`, and the schema 44 upgrade builds its SQL in `migration_schema44_v5980.php` |
| `assets/fonts/v5220/Inter-OFL-v4.1.txt`, `assets/fonts/v5930/DejaVu-LICENSE.txt`, `assets/fonts/v5930/Liberation-LICENSE.txt` | Licences for fonts the package does not contain |

## Kept on purpose

- Files that are loaded under a name built in code, which a plain search does not find: the appearance previews
  (`assets/appearance-r25/`, some identical in pairs because each name is requested), the import samples
  (`sample-data/`) and the payroll sources (`api/payroll-catalogue/sources-*.json`).
- `api/php.ini`, `robots.txt`, favicons and the cron entry point (used by the host, not by other files).
- The release notes R118–R179 and the review documents (documentation, denied to the web by `.htaccess`).
- The licences of every font and library that is bundled.

## On the host

Copies of these files already on the server are harmless and may be deleted later. Do not delete config, storage or
uploads.

## Not changed

The four guide pages (`guides/*.html`) name a social-preview image, `assets/og/tegh-social-home-v5900.png`, which has
never been in the package (it is missing in R179 too). R180 leaves those pages as they are.

| Area | Files |
|---|---|
| Browser, server, database | No change |
| Cache token | `5990-r179-paddle` (unchanged; no loaded file changed) |

# R23 — shared register and whole-package UI audit changes

## Shared corrections

The common heading normalizer no longer injects a module eyebrow into every part
of a page header. Canonical navigation removes duplicate legacy label nodes and
uses separate icon/label/badge/chevron tracks; expanded width is 280 CSS pixels,
collapsed rail 72. Full labels stay single-line. Dashboard date/time is 14px;
Quick Actions use one Ctrl + Alt legend and +1…+9/+0 hints. The required module owns
numeric shortcuts; the optional premium skin does not dispatch them a second time.

Essential register date/search/create controls stay out of overflow menus. Routine
technical invoice-register copy is removed/shortened, while real financial scope,
errors and consequences remain. Customer/vendor ledger date filters no longer move
into Help; a separate disclosure retains the original open-payment application action.

## Shared read-only table

`tegh-registers-r23.js` and `.css` provide one renderer for complete authorized
report models. The ProfessionalOutput hook gives customer/vendor invoice registers,
directories and mapped read-only reports the same header Actions menu, typed filters,
column chooser, sorting, bounded display pagination, cross-page selection, safe row
menus and scope-aware exports. The original server context, action elements and
permission checks remain in use. Native top-layer popovers prevent clipping inside
table scroll regions. Dirty source filters or changed company invalidate old actions/exports.

Eighteen supported customer/vendor invoice fields include document-currency subtotal,
tax, total, outstanding, currency, due date, terms, terms source, document type and
base-currency amounts. Customer terms are labelled days from invoice/due dates;
vendor terms come from the saved bill. No latest customer/vendor default is substituted
for historical terms. Zero and missing values remain distinct. Opening documents retain
a visible opening-balance label. Two read-only report PHP files expose these fields;
no posting service, database migration or payroll formula is changed.

Preferences store column IDs/page size per user/company/report in this browser;
filters and record data are not saved. Narrow/long values reflow into labelled details,
not truncated money or a lateral scrollbar. Native edit grids/payroll verification
retain their task-specific controls rather than being replaced by a read-only table.

## Exports and financial integrity

Excel/CSV/PDF/Print can use all filtered or selected records, with chosen or all
supported columns. Excel keeps numeric/date formats and explicitly labels original
base totals separately when those columns are omitted. Currency is included for
foreign-currency amounts. Text exports are protected from formula interpretation.
Wide PDF/print records become labelled details; complete P&L monthly/comparison
panels are retained. Filtered subsets never carry an invented recalculated closing
balance or net profit. Source report models are not mutated and the presentation
view is not falsely marked as a separately sealed server report.

## Test boundary

Local Chromium uses actual frontend scripts with explicit fictional core/auth/API
fixtures. PHP tests use a SQLite adapter and local upload/mail substitutes; they do
not establish MariaDB locks or SMTP delivery. No GitHub/IONOS write or hosted login
was performed. Full startup, real permissions, whole-application task coverage,
actual zoom and staging/production approval remain independent gates.

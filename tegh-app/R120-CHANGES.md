# R120 changes over R119

5.9.9 / Build 5990 / Schema 46. Cache token `r120-layout`.

No manual migration. One additive table, `accounting_note_lines`, is created automatically the first time the notes screen or API is used. It only adds note line items and changes no existing data.

## Credit and debit notes are now full documents, like an invoice

The Credit Note and Debit Note tabs open a document form instead of a single "subtotal" field:

- **Header:** original invoice or vendor invoice, note date, reason and memo.
  - Customer credit reasons: Product return, Price adjustment or discount, Damaged or defective goods, Billing error, Other.
  - Debit and supplier notes have their own reason lists.
- **Items:**
  - **Return items from INV-…** loads the original document's lines at their original unit price. The quantity is limited to what is still returnable, counting every other draft or posted note on the same line.
  - **Add line** adds free-form adjustment lines, such as a price adjustment, a restocking fee or extra charges on a debit note.
- **Summary panel:**
  - Customer or vendor, the original document with its total and open balance.
  - Subtotal, tax at the original document's rate, and the note total.
  - What posting will do.
- **Actions:** Cancel, **Save draft**, and **Save & post**.
  - Save & post applies the credit to the original. If the original is already paid, the credit is kept open on the account.

Accounting is unchanged:

- The note still posts from its subtotal and the original document's tax proportion, which the server already required.
- The lines are the itemized source of that subtotal. The server checks that:
  - the line amounts add up to the subtotal;
  - returned lines belong to the original document;
  - returned quantities do not exceed the original.
- Notes saved without lines (older notes, API users) still work.

Files:

- `api/accounting_notes.php`:
  - `note_lines_ready()` creates the new table.
  - `note_prepare_lines()` validates the lines and `note_store_lines()` saves them.
  - The note list now returns each note's lines.
- `assets/tegh-portal-v5990.js`: the note form.
- `assets/tegh-r120.css`: the note form's styles.

## Dropdowns, menus and scrolling

- **Root cause: a DOM re-render loop on every screen.**
  - The page-enhancement pass rebuilt parts of the sidebar and top bar. The app's own DOM observer saw those new nodes and queued another pass, about 50 times a second.
  - The required-field markers were also removed and re-created on every pass.
  - Measured idle, 2 seconds per screen across all menu screens, 616,209 DOM changes in the original versus 1 now.
  - The loop rebuilt menus under the pointer, made taps miss on phones (every Day Book control was unclickable), and kept the CPU busy.
  - Fix in `assets/tegh-portal-v5990.js`: each pass discards the node additions it made itself (`takeRecords`), and `applyRequiredMarkers()` is idempotent.
- **Sidebar:** the open module's submenu was capped at 420px with its own hidden scrollbar, so the last reports were cut off ("GIFI Report", Payroll "Reports"). The whole menu now scrolls as one, and long labels wrap instead of being cut.
- **Ageing reports:** "More Filters / Ageing periods" opened inline and pushed the toolbar down. It is now a floating panel that closes on an outside click or Escape.
- **Search and other modals:** custom scrollbar rails were drawn above the search window. They are hidden while a modal, dialog or the phone menu is open.
- **Phone column chooser:** its buttons were behind the bottom navigation, and tall panels sat under the page title. It is now a bottom sheet above the navigation bar.
- **Phone Day Book:** every entry showed as an empty box because its cells were squeezed to 0px. This bug was also in the original package. Entries are now readable cards (date · status, reference · source, party and memo, account, debit, credit, ⋮ menu).

## Layout polish

- **Form labels:** numbered step badges and the per-field "Required / Pending / ✓ Complete" text were removed from all forms (`assets/tegh-r27.js`). The required dot now sits right after the label text instead of floating at the far edge. This affects Expense Voucher, Customer Receipt, Vendor Payment and others.
- **Card padding:** Journal Entry, Transfers and similar screens had cards with no inner padding, so text touched the border. They now have padding.
- **Journal Entry:**
  - The line headers were white text on a pale band and are now readable.
  - The card ends after its lines instead of showing an empty fixed-height box.
- **Two-panel pages:** Recurring Transactions and similar pages no longer stretch the short list to the height of the form beside it.
- **Dark banners:** body text on Document Intake, Collection Drafts and Payroll is now readable.
- **Invoice line item:** even column widths with aligned fields. "Rate Preset" is no longer cut off.

## Verified

Checked with Playwright (Chromium) against a local PHP 8.4 + MariaDB copy at 1440×900 and 390×844:

- **Notes, customer side:**
  - Return items from an invoice, add a line, then Save & post. The note posts and the invoice balance drops by the note total.
  - A customer debit note with a charge line saves as a draft.
- **Notes, vendor side:** a supplier credit returning the vendor invoice's item posts.
- **Server rejects:** over-returning, lines from another invoice, and a subtotal that does not match the lines.
- **Idle DOM activity:** 1 change in total across all 45 menu screens.
- **Dropdowns:** 348 desktop and 308 phone dropdown triggers open unclipped. The only items flagged were confirmed by eye to open correctly.
- **Phone pages:** 23 phone report/list pages render with no sideways drift.
- **Exports:** Excel, CSV and PDF succeed on phone and desktop.
- **Earlier releases:** R119 tabs and the combined registers still pass.

## Files

- Changed:
  - `api/accounting_notes.php`
  - `app.html`
  - `assets/tegh-gate-v5990.js`
  - `assets/tegh-preflight-v5990.js`
  - `assets/tegh-portal-v5990.js`
  - `assets/tegh-r27.js`
- New: `assets/tegh-r120.css`.

Upload them together; the cache token must change for browsers to fetch the new portal.

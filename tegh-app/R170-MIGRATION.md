# R170 — database changes and rollback

## What R170 adds

Four tables, created automatically the first time Invoice Intelligence is used (`r170_schema_ready()` in
`api/invoice_intel_r170.php`). No existing table or column is changed, and no existing data is rewritten.

| Table | Holds | Linked to |
|---|---|---|
| `invoice_intel_jobs` | One row per invoice found in an uploaded file: state, lease, attempts, the values read (raw and normalised), the encrypted evidence, the analysis (supplier match, checks, duplicates, confidence) and the draft created | `companies` (cascade), `native_agent_documents` (cascade) |
| `invoice_intel_corrections` | Every field a reviewer changed: before, after, reason, who, when | `invoice_intel_jobs` (cascade) |
| `invoice_intel_overrides` | Authorised duplicate overrides with their reason | `invoice_intel_jobs` (cascade) |
| `invoice_intel_vendor_rules` | Coding learned from approved drafts: supplier + line words → account and tax code | `companies`, `vendors` (cascade) |

The original files keep using the existing private storage and `native_agent_documents` (unchanged schema).
Drafts are ordinary `bills` or `accounting_notes` rows created by the existing services; their `import_reference`
is `intel-<job id>`.

The tables are included in company backups and restores (`backup_r164_company_tables()` in `api/backup.php`).

## Creating the tables ahead of time (optional)

The tables are created on first use. To create them during a maintenance window instead, sign in as an owner and open
Payables › Invoice Intelligence once, or run the four `CREATE TABLE IF NOT EXISTS` statements from
`r170_schema_ready()`.

## Rollback

1. Restore the previous package (R169) files as usual. R169 never reads these tables, so they can stay.
2. Drafts created by Invoice Intelligence are ordinary draft vendor invoices and credit notes. They remain in the
   register and can be issued, edited or deleted there as before.
3. To remove the tables as well (only after a backup, and only if no one needs the review history):

   ```sql
   DROP TABLE IF EXISTS invoice_intel_overrides;
   DROP TABLE IF EXISTS invoice_intel_corrections;
   DROP TABLE IF EXISTS invoice_intel_vendor_rules;
   DROP TABLE IF EXISTS invoice_intel_jobs;
   ```

   This removes the reading results, corrections, overrides and learned coding. It does not remove vendor invoices,
   credit notes, journal entries or the original files.

## Notes

- Evidence snippets are encrypted with a key derived from `app.secret` (HKDF, label
  `tegh.invoice-intel.evidence.v1`). `app.secret` is not changed or rotated by R170. If a backup is restored on a
  host with a different `app.secret`, the evidence cannot be decrypted; the invoices, values and drafts are not
  affected, and the screen shows no source snippets for those invoices.

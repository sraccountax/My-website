#!/bin/bash
# Tegh beta: host-level backup of the database and the private upload folder, with check figures for restore tests.
#
# Run daily from cron on the beta host (example, 02:15 every day):
#   15 2 * * * TEGH_DB=tegh_beta TEGH_STORAGE=/path/to/sr-accountax-private/storage TEGH_OUT=/path/to/backups \
#              TEGH_OFFSITE=user@offsite:/tegh-beta-backups /path/to/tegh-backup.sh >> /path/to/backups/backup.log 2>&1
#
# Settings (environment):
#   TEGH_DB        database name (required)
#   TEGH_STORAGE   the config's storage_path: uploaded statements, receipts, documents, attachments (required)
#   TEGH_OUT       local folder for backups, outside the web root (required)
#   TEGH_OFFSITE   optional rsync target outside the hosting account (another server or storage box)
#   TEGH_KEEP_DAYS local copies to keep (default 14)
#   MYSQL_DEFAULTS optional path to a my.cnf with [client] user= and password= (keeps the password off the command line)
#
# Each run writes TEGH_OUT/tegh-YYYYMMDD-HHMMSS/ with:
#   db.sql.gz       full database dump (single transaction, consistent while the app runs)
#   storage.tar.gz  the private upload folder
#   checks.tsv      check figures taken from the live database: rows per table, posted debits/credits per company
#   files.sha256    SHA-256 of every uploaded file
#   SHA256SUMS      SHA-256 of the files above; tegh-restore-verify.sh refuses a backup that does not match
set -euo pipefail
: "${TEGH_DB:?set TEGH_DB}" "${TEGH_STORAGE:?set TEGH_STORAGE}" "${TEGH_OUT:?set TEGH_OUT}"
KEEP="${TEGH_KEEP_DAYS:-14}"
MY=(mysql); DUMP=(mysqldump)
if [ -n "${MYSQL_DEFAULTS:-}" ]; then MY=(mysql --defaults-extra-file="$MYSQL_DEFAULTS"); DUMP=(mysqldump --defaults-extra-file="$MYSQL_DEFAULTS"); fi
umask 077
DIR="$TEGH_OUT/tegh-$(date -u +%Y%m%d-%H%M%S)"; mkdir -p "$DIR"
echo "$(date -u +%FT%TZ) backup start -> $DIR"

"${DUMP[@]}" --single-transaction --quick --routines --triggers --hex-blob --default-character-set=utf8mb4 "$TEGH_DB" | gzip -9 > "$DIR/db.sql.gz"
tar -C "$(dirname "$TEGH_STORAGE")" -czf "$DIR/storage.tar.gz" "$(basename "$TEGH_STORAGE")"
(cd "$TEGH_STORAGE" && find . -type f -print0 | sort -z | xargs -0 -r sha256sum) > "$DIR/files.sha256"

# Check figures from the live database (the restore test recomputes them on the restored copy and compares).
{
  "${MY[@]}" -N -B "$TEGH_DB" -e "SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE' ORDER BY table_name" |
  while read -r t; do printf 'rows\t%s\t%s\n' "$t" "$("${MY[@]}" -N -B "$TEGH_DB" -e "SELECT COUNT(*) FROM \`$t\`")"; done
  "${MY[@]}" -N -B "$TEGH_DB" -e "SELECT 'ledger',je.company_id,SUM(jl.debit_cents),SUM(jl.credit_cents) FROM journal_entries je JOIN journal_lines jl ON jl.journal_entry_id=je.id WHERE je.status='posted' GROUP BY je.company_id ORDER BY je.company_id"
} > "$DIR/checks.tsv"
# Tables written while the dump ran (sessions, logs) can differ by a row or two; the restore test reports them separately.

(cd "$DIR" && sha256sum db.sql.gz storage.tar.gz checks.tsv files.sha256 > SHA256SUMS)
echo "db $(du -h "$DIR/db.sql.gz" | cut -f1), files $(wc -l < "$DIR/files.sha256"), companies with postings $(grep -c '^ledger' "$DIR/checks.tsv")"

if [ -n "${TEGH_OFFSITE:-}" ]; then rsync -a "$DIR" "$TEGH_OFFSITE/" && echo "copied to $TEGH_OFFSITE"; fi
find "$TEGH_OUT" -maxdepth 1 -type d -name 'tegh-*' -mtime +"$KEEP" -exec rm -rf {} + || true
echo "$(date -u +%FT%TZ) backup done"

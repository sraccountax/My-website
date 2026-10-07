#!/bin/bash
# Tegh beta: consistent host-level backup of the database and the private upload folder, with check figures.
#
# Run daily from cron on the beta host (example, 02:15 every day):
#   15 2 * * * MYSQL_DEFAULTS=$HOME/.tegh-backup.cnf TEGH_DB=tegh_beta TEGH_STORAGE=/path/to/sr-accountax-private/storage \
#              TEGH_OUT=/path/to/backups TEGH_OFFSITE=user@offsite:/tegh-beta-backups /path/to/tegh-backup.sh >> /path/to/backups/backup.log 2>&1
#
# Settings (environment):
#   TEGH_DB          database name (required)
#   TEGH_STORAGE     the config's storage_path: uploaded statements, receipts, documents, attachments (required)
#   TEGH_OUT         local folder for backups, outside the web root (required)
#   TEGH_MAINT_FLAG  maintenance flag file; default TEGH_STORAGE/runtime/maintenance.json (the app's default). If config.php
#                    sets app.maintenance_flag_path, pass the same path here.
#   TEGH_QUIESCE     seconds to wait after pausing so requests already running can finish (default 5)
#   TEGH_OFFSITE     optional rsync target outside the hosting account
#   TEGH_KEEP_DAYS   local copies to keep (default 14)
#   MYSQL_DEFAULTS   optional my.cnf with [client] user= and password= (keeps the password off the command line)
#
# Consistency: the script pauses changes with Tegh's maintenance flag (every request is refused with "Tegh is making a
# backup", 503, and nothing is written), takes the check figures, the database dump, the upload archive and the file
# hashes, then takes the check figures again. The backup is accepted only when both sets are identical, so all four parts
# describe the same moment. The flag is always removed, also when the script fails. Typical pause: under a minute.
#
# Each run writes TEGH_OUT/tegh-YYYYMMDD-HHMMSS/ with db.sql.gz, storage.tar.gz, files.sha256, checks.tsv and SHA256SUMS.
set -euo pipefail
: "${TEGH_DB:?set TEGH_DB}" "${TEGH_STORAGE:?set TEGH_STORAGE}" "${TEGH_OUT:?set TEGH_OUT}"
KEEP="${TEGH_KEEP_DAYS:-14}"; QUIESCE="${TEGH_QUIESCE:-5}"
FLAG="${TEGH_MAINT_FLAG:-$TEGH_STORAGE/runtime/maintenance.json}"
MY=(mysql); DUMP=(mysqldump)
if [ -n "${MYSQL_DEFAULTS:-}" ]; then MY=(mysql --defaults-extra-file="$MYSQL_DEFAULTS"); DUMP=(mysqldump --defaults-extra-file="$MYSQL_DEFAULTS"); fi
[ -d "$TEGH_STORAGE" ] || { echo "FAIL storage folder $TEGH_STORAGE not found"; exit 1; }
umask 077
DIR="$TEGH_OUT/tegh-$(date -u +%Y%m%d-%H%M%S)"; mkdir -p "$DIR"
echo "$(date -u +%FT%TZ) backup start -> $DIR"

# --- pause changes ---------------------------------------------------------------------------------------------------
if [ -e "$FLAG" ]; then echo "FAIL Tegh is already in maintenance mode ($FLAG); not taking a backup now"; rm -rf "$DIR"; exit 1; fi
mkdir -p "$(dirname "$FLAG")"
TOKEN=$(head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n')
printf '{"token":"%s","reason":"backup","startedAt":"%s"}' "$TOKEN" "$(date -u +%FT%TZ)" > "$FLAG"
# The web server must be able to read the flag; give it the owner of the storage folder.
chown --reference="$TEGH_STORAGE" "$FLAG" 2>/dev/null || true; chmod 0644 "$FLAG"
release(){ if [ -f "$FLAG" ] && grep -q "\"token\":\"$TOKEN\"" "$FLAG"; then rm -f "$FLAG"; echo "$(date -u +%FT%TZ) changes resumed"; fi; }
trap release EXIT
echo "$(date -u +%FT%TZ) changes paused (maintenance flag); waiting ${QUIESCE}s for running requests"
sleep "$QUIESCE"

# --- snapshot -------------------------------------------------------------------------------------------------------
checks(){
  "${MY[@]}" -N -B "$TEGH_DB" -e "SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE' ORDER BY table_name" |
  while read -r t; do printf 'rows\t%s\t%s\n' "$t" "$("${MY[@]}" -N -B "$TEGH_DB" -e "SELECT COUNT(*) FROM \`$t\`")"; done
  # Posted debits/credits per company (no lines at all is a valid state, for example a brand-new installation).
  "${MY[@]}" -N -B "$TEGH_DB" -e "SELECT 'ledger',je.company_id,SUM(jl.debit_cents),SUM(jl.credit_cents) FROM journal_entries je JOIN journal_lines jl ON jl.journal_entry_id=je.id WHERE je.status='posted' GROUP BY je.company_id ORDER BY je.company_id" || true
}
files(){ (cd "$TEGH_STORAGE" && find . -type f ! -path "./runtime/maintenance.json*" -print0 | sort -z | xargs -0 -r sha256sum); }
checks > "$DIR/checks.tsv"; files > "$DIR/files.sha256"
"${DUMP[@]}" --single-transaction --quick --routines --triggers --hex-blob --default-character-set=utf8mb4 "$TEGH_DB" | gzip -9 > "$DIR/db.sql.gz"
tar -C "$(dirname "$TEGH_STORAGE")" --exclude="$(basename "$TEGH_STORAGE")/runtime/maintenance.json*" -czf "$DIR/storage.tar.gz" "$(basename "$TEGH_STORAGE")"
# The same figures again: anything written during the backup would show here.
if ! diff -q <(checks) "$DIR/checks.tsv" >/dev/null || ! diff -q <(files) "$DIR/files.sha256" >/dev/null; then
  echo "FAIL records or files changed while the backup ran (was the maintenance flag path right?); this backup is not consistent and was removed"
  rm -rf "$DIR"; exit 1
fi
release; trap - EXIT

(cd "$DIR" && sha256sum db.sql.gz storage.tar.gz checks.tsv files.sha256 > SHA256SUMS)
companies=$(grep -c '^ledger' "$DIR/checks.tsv" || true)
echo "consistent snapshot: db $(du -h "$DIR/db.sql.gz" | cut -f1), files $(wc -l < "$DIR/files.sha256"), tables $(grep -c '^rows' "$DIR/checks.tsv" || true), companies with postings ${companies:-0}"

if [ -n "${TEGH_OFFSITE:-}" ]; then rsync -a "$DIR" "$TEGH_OFFSITE/" && echo "copied to $TEGH_OFFSITE"; fi
find "$TEGH_OUT" -maxdepth 1 -type d -name 'tegh-*' -mtime +"$KEEP" -exec rm -rf {} + || true
echo "$(date -u +%FT%TZ) backup done"

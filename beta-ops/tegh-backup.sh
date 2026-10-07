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
#   TEGH_WAIT_MAX    seconds to wait for requests that were already running when changes were paused (default 300)
#   TEGH_STALE_AFTER seconds after which a request marker is treated as left over from a crashed process (default 900)
#   TEGH_OFFSITE     optional rsync target outside the hosting account
#   TEGH_KEEP_DAYS   local copies to keep (default 14)
#   MYSQL_DEFAULTS   optional my.cnf with [client] user= and password= (keeps the password off the command line)
#
# Consistency (R158):
#  1. Changes are paused with Tegh's maintenance flag: every new request is refused with "Tegh is making a backup" (503)
#     and writes nothing.
#  2. Requests that were already running are waited for. Each Tegh request (R158 and later) leaves a marker in
#     <flag folder>/active-requests while it runs; the script waits until there are none, however long a request takes,
#     up to TEGH_WAIT_MAX. If one is still running then, no backup is taken.
#  3. Check figures are recorded: rows per table, a content fingerprint of every table (it changes when any value in any
#     row changes, even if row counts and totals do not), posted debits and credits per company, and the SHA-256 of
#     every uploaded file. Then the database dump and the upload archive are taken, and the check figures again.
#     The backup is kept only when both sets are identical. A change from outside Tegh (another program writing to the
#     database) is caught this way.
#  4. Any database or file error stops the backup: the script never records a check it could not compute.
# The flag is always removed, also when the script fails.
#
# Each run writes TEGH_OUT/tegh-YYYYMMDD-HHMMSS/ with db.sql.gz, storage.tar.gz, files.sha256, checks.tsv and SHA256SUMS.
set -euo pipefail
: "${TEGH_DB:?set TEGH_DB}" "${TEGH_STORAGE:?set TEGH_STORAGE}" "${TEGH_OUT:?set TEGH_OUT}"
KEEP="${TEGH_KEEP_DAYS:-14}"; WAIT_MAX="${TEGH_WAIT_MAX:-300}"; STALE_AFTER="${TEGH_STALE_AFTER:-900}"
FLAG="${TEGH_MAINT_FLAG:-$TEGH_STORAGE/runtime/maintenance.json}"; ACTIVE="$(dirname "$FLAG")/active-requests"
MY=(mysql); DUMP=(mysqldump)
if [ -n "${MYSQL_DEFAULTS:-}" ]; then MY=(mysql --defaults-extra-file="$MYSQL_DEFAULTS"); DUMP=(mysqldump --defaults-extra-file="$MYSQL_DEFAULTS"); fi
[ -d "$TEGH_STORAGE" ] || { echo "FAIL storage folder $TEGH_STORAGE not found"; exit 1; }
umask 077
DIR="$TEGH_OUT/tegh-$(date -u +%Y%m%d-%H%M%S)"; mkdir -p "$DIR"
die(){ echo "FAIL $*"; rm -rf "$DIR"; exit 1; }
echo "$(date -u +%FT%TZ) backup start -> $DIR"

# One query; any database error stops the backup (its message is shown). UTC so timestamps compare the same way.
q(){ "${MY[@]}" -N -B "$TEGH_DB" -e "SET SESSION time_zone='+00:00'; SET SESSION group_concat_max_len=1048576; $1"; }

# --- pause changes ---------------------------------------------------------------------------------------------------
if [ -e "$FLAG" ]; then echo "FAIL Tegh is already in maintenance mode ($FLAG); not taking a backup now"; rm -rf "$DIR"; exit 1; fi
mkdir -p "$(dirname "$FLAG")"
TOKEN=$(head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n')
printf '{"token":"%s","reason":"backup","startedAt":"%s"}' "$TOKEN" "$(date -u +%FT%TZ)" > "$FLAG"
# The web server must be able to read the flag; give it the owner of the storage folder.
chown --reference="$TEGH_STORAGE" "$FLAG" 2>/dev/null || true; chmod 0644 "$FLAG"
release(){ if [ -f "$FLAG" ] && grep -q "\"token\":\"$TOKEN\"" "$FLAG"; then rm -f "$FLAG"; echo "$(date -u +%FT%TZ) changes resumed"; fi; }
trap release EXIT
echo "$(date -u +%FT%TZ) changes paused (maintenance flag)"
# The web server writes a marker per running request into this folder; it must own it (a folder created by root, for
# example by a command-line run, would silently stop the markers and the wait below would not see running requests).
mkdir -p "$ACTIVE"; chown --reference="$TEGH_STORAGE" "$ACTIVE" 2>/dev/null || true; chmod 0770 "$ACTIVE" 2>/dev/null || true
[ "$(stat -c %U "$ACTIVE")" = "$(stat -c %U "$TEGH_STORAGE")" ] || die "$ACTIVE is not owned by the owner of $TEGH_STORAGE; run the backup as root or as that user"

# --- wait for requests that were already running ---------------------------------------------------------------------
running(){ [ -d "$ACTIVE" ] || return 0; find "$ACTIVE" -maxdepth 1 -type f ! -mmin "+$(( (STALE_AFTER + 59) / 60 ))" -print 2>/dev/null; }
stale=$( [ -d "$ACTIVE" ] && find "$ACTIVE" -maxdepth 1 -type f -mmin "+$(( (STALE_AFTER + 59) / 60 ))" -print 2>/dev/null | wc -l || echo 0 )
[ "${stale:-0}" -gt 0 ] && echo "   note: $stale request marker(s) older than ${STALE_AFTER}s are left over from stopped processes and are ignored"
waited=0; first=1
while :; do
  n=$(running | wc -l)
  [ "$n" -eq 0 ] && break
  [ "$first" = 1 ] && { echo "   waiting for $n request(s) that started before the pause"; first=0; }
  [ "$waited" -ge "$WAIT_MAX" ] && die "$n request(s) were still running after ${WAIT_MAX}s; no backup was taken (they continue normally)"
  sleep 1; waited=$((waited+1))
done
echo "$(date -u +%FT%TZ) no request running (waited ${waited}s)"

# --- snapshot -------------------------------------------------------------------------------------------------------
# Check figures. Every query must succeed; a failure returns non-zero and the backup stops.
checks(){
  local tables t n fp cols ledger
  tables=$(q "SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE' ORDER BY table_name") || return 1
  [ -n "$tables" ] || { echo "the database has no tables" >&2; return 1; }
  for t in $tables; do
    n=$(q "SELECT COUNT(*) FROM \`$t\`") || return 1
    printf 'rows\t%s\t%s\n' "$t" "$n"
    # Content fingerprint: every column of every row, NULLs marked, hashed twice and combined in an order-free way.
    cols=$(q "SELECT GROUP_CONCAT(CONCAT('IFNULL(HEX(\`',column_name,'\`),''~'')') ORDER BY ordinal_position SEPARATOR ',') FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='$t'") || return 1
    fp=$(q "SELECT CONCAT(COUNT(*),':',COALESCE(SUM(CRC32(x)),0),':',COALESCE(BIT_XOR(CRC32(CONCAT('t',x))),0)) FROM (SELECT CONCAT_WS('|',$cols) x FROM \`$t\`) s") || return 1
    printf 'fp\t%s\t%s\n' "$t" "$fp"
  done
  # Posted debits/credits per company (no lines at all is a valid state, for example a brand-new installation;
  # a failing query is not).
  ledger=$(q "SELECT 'ledger',je.company_id,SUM(jl.debit_cents),SUM(jl.credit_cents) FROM journal_entries je JOIN journal_lines jl ON jl.journal_entry_id=je.id WHERE je.status='posted' GROUP BY je.company_id ORDER BY je.company_id") || return 1
  [ -z "$ledger" ] || printf '%s\n' "$ledger"
}
files(){ (cd "$TEGH_STORAGE" && find . -type f ! -path "./runtime/maintenance.json*" ! -path "./runtime/active-requests/*" -print0 | sort -z | xargs -0 -r sha256sum); }

checks > "$DIR/checks.tsv" || die "the check figures could not be read (database error above); no backup was taken"
files > "$DIR/files.sha256" || die "the upload folder could not be read; no backup was taken"
"${DUMP[@]}" --single-transaction --quick --routines --triggers --hex-blob --default-character-set=utf8mb4 "$TEGH_DB" | gzip -9 > "$DIR/db.sql.gz" || die "the database dump failed"
tar -C "$(dirname "$TEGH_STORAGE")" --exclude="$(basename "$TEGH_STORAGE")/runtime/maintenance.json*" --exclude="$(basename "$TEGH_STORAGE")/runtime/active-requests" -czf "$DIR/storage.tar.gz" "$(basename "$TEGH_STORAGE")" || die "the upload archive failed"
# The same figures again, into files (a failure here must stop the backup too): anything written during the backup shows.
checks > "$DIR/.checks.after" || die "the check figures could not be read again (database error above)"
files > "$DIR/.files.after" || die "the upload folder could not be read again"
if ! cmp -s "$DIR/.checks.after" "$DIR/checks.tsv"; then
  echo "   changed while the backup ran: $(diff "$DIR/checks.tsv" "$DIR/.checks.after" | grep '^>' | cut -f2 | sort -u | head -5 | tr '\n' ' ')"
  die "records changed while the backup ran (a request bypassed the pause, or another program wrote to the database); this backup is not consistent and was removed"
fi
cmp -s "$DIR/.files.after" "$DIR/files.sha256" || die "uploaded files changed while the backup ran; this backup is not consistent and was removed"
rm -f "$DIR/.checks.after" "$DIR/.files.after"
release; trap - EXIT

(cd "$DIR" && sha256sum db.sql.gz storage.tar.gz checks.tsv files.sha256 > SHA256SUMS)
companies=$(grep -c '^ledger' "$DIR/checks.tsv" || true)
echo "consistent snapshot: db $(du -h "$DIR/db.sql.gz" | cut -f1), files $(wc -l < "$DIR/files.sha256"), tables $(grep -c '^rows' "$DIR/checks.tsv"), content fingerprints $(grep -c '^fp' "$DIR/checks.tsv"), companies with postings ${companies:-0}"

if [ -n "${TEGH_OFFSITE:-}" ]; then rsync -a "$DIR" "$TEGH_OFFSITE/" && echo "copied to $TEGH_OFFSITE"; fi
find "$TEGH_OUT" -maxdepth 1 -type d -name 'tegh-*' -mtime +"$KEEP" -exec rm -rf {} + || true
echo "$(date -u +%FT%TZ) backup done"

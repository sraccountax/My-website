#!/bin/bash
# Tegh beta: restore a backup made by tegh-backup.sh into a SEPARATE, designated restore-test installation and verify it.
#
# This script DROPS the restore database and REPLACES the restore storage folder. It refuses to run unless the target
# has been designated for restore tests and is not the live installation:
#   1. RESTORE_DB must contain the word "restore" (for example tegh_restore_test).
#   2. The folder that holds RESTORE_STORAGE must contain a file named .tegh-restore-target whose text is exactly
#      "db=<RESTORE_DB>". Create it once, by hand, when you set up the restore-test installation:
#         echo "db=tegh_restore_test" > /path/to/restore/sr-accountax-private/.tegh-restore-target
#      The live installation never has this file.
#   3. TEGH_LIVE_CONFIG must point to the LIVE config.php. The script reads the live database name and storage path from
#      it and refuses when the restore target is the same database, or the same folder, or a folder inside or around it.
#
#   TEGH_LIVE_CONFIG=/path/to/live/sr-accountax-private/config.php RESTORE_DB=tegh_restore_test \
#   RESTORE_STORAGE=/path/to/restore/sr-accountax-private/storage ./tegh-restore-verify.sh /path/to/backups/tegh-YYYYMMDD-HHMMSS
#
#   MYSQL_DEFAULTS   optional my.cnf with [client] user=/password= that may create RESTORE_DB
# Exit code 0 only when every check passes.
set -euo pipefail
B="${1:?backup folder}"; : "${RESTORE_DB:?set RESTORE_DB}" "${RESTORE_STORAGE:?set RESTORE_STORAGE}" "${TEGH_LIVE_CONFIG:?set TEGH_LIVE_CONFIG to the live config.php}"
MY=(mysql); [ -n "${MYSQL_DEFAULTS:-}" ] && MY=(mysql --defaults-extra-file="$MYSQL_DEFAULTS")
fail=0; ok(){ echo "PASS  $*"; }; bad(){ echo "FAIL  $*"; fail=1; }; stop(){ echo "REFUSED  $*"; exit 2; }

# --- the target must be a designated restore-test installation, never the live one -------------------------------
[[ "$RESTORE_DB" =~ ^[A-Za-z0-9_]+$ ]] || stop "RESTORE_DB may use letters, digits and _ only"
[[ "$RESTORE_DB" == *restore* ]] || stop "RESTORE_DB '$RESTORE_DB' does not contain 'restore'; use a dedicated restore-test database"
[[ "$RESTORE_STORAGE" == /* ]] || stop "RESTORE_STORAGE must be an absolute path"
[ -f "$TEGH_LIVE_CONFIG" ] || stop "TEGH_LIVE_CONFIG $TEGH_LIVE_CONFIG not found"
read -r LIVE_DB LIVE_STORAGE < <(php -r '$c=require $argv[1]; preg_match("/dbname=([^;]+)/",(string)($c["db"]["dsn"]??""),$m); echo ($m[1]??"")," ",realpath((string)($c["storage_path"]??""))?:(string)($c["storage_path"]??""),"\n";' "$TEGH_LIVE_CONFIG")
[ -n "$LIVE_DB" ] && [ -n "$LIVE_STORAGE" ] || stop "could not read the live database name and storage path from $TEGH_LIVE_CONFIG"
[ "$RESTORE_DB" != "$LIVE_DB" ] || stop "RESTORE_DB is the LIVE database ($LIVE_DB)"
mkdir -p "$(dirname "$RESTORE_STORAGE")"
TARGET_PARENT=$(cd "$(dirname "$RESTORE_STORAGE")" && pwd -P); TARGET="$TARGET_PARENT/$(basename "$RESTORE_STORAGE")"
case "$TARGET/" in "$LIVE_STORAGE"/*) stop "RESTORE_STORAGE is the live storage folder or inside it ($LIVE_STORAGE)";; esac
case "$LIVE_STORAGE/" in "$TARGET"/*) stop "RESTORE_STORAGE contains the live storage folder ($LIVE_STORAGE)";; esac
MARK="$TARGET_PARENT/.tegh-restore-target"
[ -f "$MARK" ] || stop "$MARK not found: designate this folder for restore tests first (see the top of this script)"
[ "$(tr -d '\r\n' < "$MARK")" = "db=$RESTORE_DB" ] || stop "$MARK does not say db=$RESTORE_DB"
ok "restore target is designated ($MARK) and is not the live database ($LIVE_DB) or storage ($LIVE_STORAGE)"

# --- restore ------------------------------------------------------------------------------------------------------
(cd "$B" && sha256sum -c --quiet SHA256SUMS) && ok "backup files match SHA256SUMS" || { bad "backup files do not match SHA256SUMS"; exit 1; }
"${MY[@]}" -e "DROP DATABASE IF EXISTS \`$RESTORE_DB\`; CREATE DATABASE \`$RESTORE_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
gunzip -c "$B/db.sql.gz" | "${MY[@]}" "$RESTORE_DB" && ok "database restored into $RESTORE_DB"
rm -rf "$TARGET"
tmp=$(mktemp -d); tar -C "$tmp" -xzf "$B/storage.tar.gz"; mv "$tmp"/* "$TARGET"; rmdir "$tmp"
ok "upload folder restored into $TARGET"

# 1. Every uploaded file is back, byte for byte (an installation with no uploads has an empty list).
nfiles=$(grep -c . "$B/files.sha256" || true)
if [ "${nfiles:-0}" = 0 ]; then ok "the backup holds no uploaded files"
elif (cd "$TARGET" && sha256sum -c --quiet "$B/files.sha256"); then ok "all $nfiles uploaded files restored with the same SHA-256"; else bad "uploaded files differ"; fi

# 2. Row counts per table equal the check figures of the snapshot.
ntables=0
while IFS=$'\t' read -r kind t n; do
  [ "$kind" = rows ] || continue; ntables=$((ntables+1))
  got=$("${MY[@]}" -N -B "$RESTORE_DB" -e "SELECT COUNT(*) FROM \`$t\`")
  [ "$got" = "$n" ] || bad "$t: $got rows, backup check says $n"
done < "$B/checks.tsv"
[ $fail = 0 ] && ok "row counts of $ntables tables equal the backup checks"

# 3. Posted debits and credits per company equal the check figures (none at all is valid), and every company balances.
nledger=$(grep -c '^ledger' "$B/checks.tsv" || true)
if diff <(grep '^ledger' "$B/checks.tsv" || true) <("${MY[@]}" -N -B "$RESTORE_DB" -e "SELECT 'ledger',je.company_id,SUM(jl.debit_cents),SUM(jl.credit_cents) FROM journal_entries je JOIN journal_lines jl ON jl.journal_entry_id=je.id WHERE je.status='posted' GROUP BY je.company_id ORDER BY je.company_id") >/dev/null; then
  ok "posted debits/credits of ${nledger:-0} companies equal the backup checks"; else bad "posted totals differ from the backup checks"; fi
unbalanced=$("${MY[@]}" -N -B "$RESTORE_DB" -e "SELECT COUNT(*) FROM (SELECT je.company_id FROM journal_entries je JOIN journal_lines jl ON jl.journal_entry_id=je.id WHERE je.status='posted' GROUP BY je.company_id HAVING SUM(jl.debit_cents)<>SUM(jl.credit_cents)) x")
[ "$unbalanced" = 0 ] && ok "every restored company's posted entries balance" || bad "$unbalanced restored companies out of balance"

echo; [ $fail = 0 ] && echo "RESTORE VERIFIED: $B" || echo "RESTORE NOT VERIFIED: $B"
exit $fail

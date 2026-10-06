#!/bin/bash
# Tegh beta: restore a backup made by tegh-backup.sh into a SEPARATE test installation and verify it.
# Never point this at the live database or the live storage folder.
#
#   RESTORE_DB=tegh_restore_test RESTORE_STORAGE=/path/to/restore/sr-accountax-private/storage \
#   ./tegh-restore-verify.sh /path/to/backups/tegh-YYYYMMDD-HHMMSS
#
# Settings (environment):
#   RESTORE_DB       database to create for the test installation (dropped and recreated)
#   RESTORE_STORAGE  storage folder of the test installation (replaced)
#   MYSQL_DEFAULTS   optional my.cnf with [client] user=/password= that may create RESTORE_DB
# Exit code 0 only when every check passes.
set -euo pipefail
B="${1:?backup folder}"; : "${RESTORE_DB:?set RESTORE_DB}" "${RESTORE_STORAGE:?set RESTORE_STORAGE}"
MY=(mysql); [ -n "${MYSQL_DEFAULTS:-}" ] && MY=(mysql --defaults-extra-file="$MYSQL_DEFAULTS")
fail=0; ok(){ echo "PASS  $*"; }; bad(){ echo "FAIL  $*"; fail=1; }

(cd "$B" && sha256sum -c --quiet SHA256SUMS) && ok "backup files match SHA256SUMS" || { bad "backup files do not match SHA256SUMS"; exit 1; }

"${MY[@]}" -e "DROP DATABASE IF EXISTS \`$RESTORE_DB\`; CREATE DATABASE \`$RESTORE_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
gunzip -c "$B/db.sql.gz" | "${MY[@]}" "$RESTORE_DB" && ok "database restored into $RESTORE_DB"
rm -rf "$RESTORE_STORAGE"; mkdir -p "$(dirname "$RESTORE_STORAGE")"
tmp=$(mktemp -d); tar -C "$tmp" -xzf "$B/storage.tar.gz"; mv "$tmp"/* "$RESTORE_STORAGE"; rmdir "$tmp"
ok "upload folder restored into $RESTORE_STORAGE"

# 1. Every uploaded file is back, byte for byte.
if (cd "$RESTORE_STORAGE" && sha256sum -c --quiet "$B/files.sha256"); then ok "all $(wc -l < "$B/files.sha256") uploaded files restored with the same SHA-256"; else bad "uploaded files differ"; fi

# 2. Row counts per table equal the counts taken at backup time (sessions and logs may move during a live dump).
volatile='^(sessions|client_view_sessions|login_attempts|registration_attempts|invitation_attempts|outbound_email_attempts|platform_incident_log|ai_agent_workflow_sessions)$'
while IFS=$'\t' read -r kind t n; do
  [ "$kind" = rows ] || continue
  got=$("${MY[@]}" -N -B "$RESTORE_DB" -e "SELECT COUNT(*) FROM \`$t\`")
  if [ "$got" != "$n" ]; then if [[ $t =~ $volatile ]]; then echo "INFO  $t: $got rows (backup check $n; changes while the site runs)"; else bad "$t: $got rows, backup check says $n"; fi; fi
done < "$B/checks.tsv"
[ $fail = 0 ] && ok "row counts of $(grep -c '^rows' "$B/checks.tsv") tables equal the backup checks"

# 3. Posted debits and credits per company equal the backup checks, and every company balances.
diff <(grep '^ledger' "$B/checks.tsv") <("${MY[@]}" -N -B "$RESTORE_DB" -e "SELECT 'ledger',je.company_id,SUM(jl.debit_cents),SUM(jl.credit_cents) FROM journal_entries je JOIN journal_lines jl ON jl.journal_entry_id=je.id WHERE je.status='posted' GROUP BY je.company_id ORDER BY je.company_id") >/dev/null \
  && ok "posted debits/credits of $(grep -c '^ledger' "$B/checks.tsv") companies equal the backup checks" || bad "posted totals differ from the backup checks"
unbalanced=$("${MY[@]}" -N -B "$RESTORE_DB" -e "SELECT COUNT(*) FROM (SELECT je.company_id FROM journal_entries je JOIN journal_lines jl ON jl.journal_entry_id=je.id WHERE je.status='posted' GROUP BY je.company_id HAVING SUM(jl.debit_cents)<>SUM(jl.credit_cents)) x")
[ "$unbalanced" = 0 ] && ok "every restored company's posted entries balance" || bad "$unbalanced restored companies out of balance"

echo; [ $fail = 0 ] && echo "RESTORE VERIFIED: $B" || echo "RESTORE NOT VERIFIED: $B"
exit $fail

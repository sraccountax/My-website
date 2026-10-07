#!/bin/bash
# Tests beta-ops/tegh-backup.sh and tegh-restore-verify.sh on the gate host (synthetic data only).
#  A. backup while another process keeps writing: the backup must be consistent, writes during the pause get 503
#  B. the restore refuses every target that is not a designated restore-test installation, and the live data is untouched
#  C. an installation with no records and no uploads backs up and restores
#  D. (R158) the three cases of the R157 review, each also run with the R157 script as a control that must show the defect:
#     D1 a failing ledger-total query stops the backup; D2 a request running longer than five seconds is waited for;
#     D3 an update that changes neither row counts nor ledger totals is detected
#  E. (R158) left-over and never-ending request markers; the restore check detects changed content and database errors
set -u
OPS=/home/user/My-website/beta-ops; LIVE_CFG=/srv/gate/sr-accountax-private/config.php; OUT=/srv/gate/backups
export NODE_EXTRA_CA_CERTS=/srv/gate/pki/ca.crt
pass=0; failc=0; ctl=0; ctlbad=0; control(){ if [ "$1" = REPRODUCED ]; then ctl=$((ctl+1)); else ctlbad=$((ctlbad+1)); fi; echo "CONTROL $1  $2"; }
res(){ if [ "$1" = PASS ]; then pass=$((pass+1)); else failc=$((failc+1)); fi; echo "$1  $2"; }
rm -rf $OUT/tegh-*; mkdir -p $OUT
echo "db=tegh_restore_test" > /srv/rst/sr-accountax-private/.tegh-restore-target

# A synthetic customer used by D2, D3 and E3 (in its own "R158 Backup Probe" company), with its starting name.
# The slow test page exists only on the test host, only while this script runs (never in the package).
cp /srv/gate/t/zz-r158-slow-probe.php /srv/gate/www/zz-r158-slow-probe.php; trap 'rm -f /srv/gate/www/zz-r158-slow-probe.php' EXIT
CU=$(cd /srv/gate/t && node r158-probe-customer.mjs); mysql tegh_gate -e "UPDATE customers SET name='R158 Probe Customer' WHERE id='$CU'"

echo "== A. backup during writes"
node /srv/gate/t/beta-writer.mjs 40 > /tmp/beta-writer.out 2>&1 & W=$!
sleep 6
TEGH_DB=tegh_gate TEGH_STORAGE=/srv/gate/sr-accountax-private/storage TEGH_OUT=$OUT $OPS/tegh-backup.sh > /tmp/beta-backup.out 2>&1; rc=$?
wait $W; cat /tmp/beta-backup.out | sed 's/^/   /'; echo "   writer: $(tail -1 /tmp/beta-writer.out)"
[ $rc = 0 ] && grep -q "consistent snapshot" /tmp/beta-backup.out && res PASS "A1 backup taken while writes were attempted is consistent (figures identical before and after)" || res FAIL "A1 backup during writes (exit $rc)"
grep -q '"maintenance_backup":[1-9]' /tmp/beta-writer.out && grep -q '"ok":[1-9]' /tmp/beta-writer.out && res PASS "A2 writes during the pause were refused with 'Tegh is making a backup' (503) and writes before/after succeeded" || res FAIL "A2 writer saw: $(tail -1 /tmp/beta-writer.out)"
[ ! -e /srv/gate/sr-accountax-private/storage/runtime/maintenance.json ] && res PASS "A3 maintenance flag removed after the backup" || res FAIL "A3 flag left behind"
B=$(ls -d $OUT/tegh-* | tail -1)
TEGH_LIVE_CONFIG=$LIVE_CFG RESTORE_DB=tegh_restore_test RESTORE_STORAGE=/srv/rst/sr-accountax-private/storage $OPS/tegh-restore-verify.sh "$B" > /tmp/beta-restore.out 2>&1; rc=$?
sed 's/^/   /' /tmp/beta-restore.out
[ $rc = 0 ] && grep -q "RESTORE VERIFIED" /tmp/beta-restore.out && ! grep -q "^INFO" /tmp/beta-restore.out && res PASS "A4 restore verified with every table's row count equal (no tolerance for tables written during the backup)" || res FAIL "A4 restore (exit $rc)"

echo "== B. refused restore targets"
before=$(mysql -N tegh_gate -e "SELECT COUNT(*) FROM journal_lines"); lf=$(find /srv/gate/sr-accountax-private/storage -type f | wc -l)
try(){ local name="$1"; shift; out=$(env "$@" $OPS/tegh-restore-verify.sh "$B" 2>&1); rc=$?; if [ $rc = 2 ] && echo "$out" | grep -q "^REFUSED"; then res PASS "$name: $(echo "$out" | grep REFUSED | head -1 | cut -c1-140)"; else res FAIL "$name (exit $rc): $(echo "$out" | tail -2 | tr '\n' ' ' | cut -c1-200)"; fi; }
try "B1 database name without 'restore'" TEGH_LIVE_CONFIG=$LIVE_CFG RESTORE_DB=tegh_gate RESTORE_STORAGE=/srv/rst/sr-accountax-private/storage
sed "s/dbname=tegh_gate/dbname=tegh_restore_test/" $LIVE_CFG > /tmp/live-named-restore.php
try "B2 restore database equal to the live database" TEGH_LIVE_CONFIG=/tmp/live-named-restore.php RESTORE_DB=tegh_restore_test RESTORE_STORAGE=/srv/rst/sr-accountax-private/storage
echo "db=tegh_restore_test" > /srv/gate/sr-accountax-private/.tegh-restore-target
try "B3 restore storage = live storage (even with a marker placed next to it)" TEGH_LIVE_CONFIG=$LIVE_CFG RESTORE_DB=tegh_restore_test RESTORE_STORAGE=/srv/gate/sr-accountax-private/storage
try "B4 restore storage containing the live storage" TEGH_LIVE_CONFIG=$LIVE_CFG RESTORE_DB=tegh_restore_test RESTORE_STORAGE=/srv/gate/sr-accountax-private
rm -f /srv/gate/sr-accountax-private/.tegh-restore-target
mkdir -p /srv/rst-unmarked; try "B5 folder not designated (no .tegh-restore-target)" TEGH_LIVE_CONFIG=$LIVE_CFG RESTORE_DB=tegh_restore_other RESTORE_STORAGE=/srv/rst-unmarked/storage
after=$(mysql -N tegh_gate -e "SELECT COUNT(*) FROM journal_lines"); la=$(find /srv/gate/sr-accountax-private/storage -type f | wc -l)
[ "$before" = "$after" ] && [ "$lf" = "$la" ] && res PASS "B6 live database ($after journal lines) and live storage ($la files) unchanged after the refused attempts" || res FAIL "B6 live data changed: $before→$after lines, $lf→$la files"

echo "== D. R157 review cases (each with the R157 script as a control)"
git -C /home/user/My-website show 77928b0:beta-ops/tegh-backup.sh > /tmp/r157-backup.sh 2>/dev/null || cp /srv/gate/r157-ops/tegh-backup.sh /tmp/r157-backup.sh
git -C /home/user/My-website show 77928b0:beta-ops/tegh-restore-verify.sh > /tmp/r157-restore-verify.sh 2>/dev/null || cp /srv/gate/r157-ops/tegh-restore-verify.sh /tmp/r157-restore-verify.sh
chmod +x /tmp/r157-backup.sh /tmp/r157-restore-verify.sh
grep -q 'ledger.*|| true' /tmp/r157-backup.sh && echo "   control script: R157 tegh-backup.sh ($(sha256sum /tmp/r157-backup.sh | cut -c1-16)…), fixed five-second wait and '|| true' on the ledger query"
nflag(){ [ ! -e "$1/runtime/maintenance.json" ]; }
# D1. The ledger-total query fails (here: the column it reads is missing, as after a partial or wrong upgrade).
mysql -e "DROP DATABASE IF EXISTS tegh_sqlfail_src; CREATE DATABASE tegh_sqlfail_src CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"; mysqldump --no-data tegh_gate | mysql tegh_sqlfail_src
mysql tegh_sqlfail_src -e "ALTER TABLE journal_lines RENAME COLUMN debit_cents TO debit_cents_renamed"
rm -rf /srv/sqlfail-src $OUT/sqlfail && mkdir -p /srv/sqlfail-src/storage $OUT/sqlfail
TEGH_DB=tegh_sqlfail_src TEGH_STORAGE=/srv/sqlfail-src/storage TEGH_OUT=$OUT/sqlfail $OPS/tegh-backup.sh > /tmp/d1.out 2>&1; rc=$?; sed 's/^/   /' /tmp/d1.out
kept=$(ls -d $OUT/sqlfail/tegh-* 2>/dev/null | wc -l)
[ $rc != 0 ] && grep -q "Unknown column" /tmp/d1.out && grep -q "no backup was taken" /tmp/d1.out && [ "$kept" = 0 ] && nflag /srv/sqlfail-src/storage && res PASS "D1 a failing ledger-total query stops the backup: exit $rc, the database error is shown, no backup folder is kept, changes are resumed" || res FAIL "D1 SQL failure (exit $rc, folders kept $kept)"
TEGH_DB=tegh_sqlfail_src TEGH_STORAGE=/srv/sqlfail-src/storage TEGH_OUT=$OUT/sqlfail TEGH_QUIESCE=1 /tmp/r157-backup.sh > /tmp/d1-r157.out 2>&1; rc=$?
[ $rc = 0 ] && grep -q "consistent snapshot" /tmp/d1-r157.out && control REPRODUCED "D1 the R157 script, same database: exit 0, \"$(grep -m1 'consistent snapshot' /tmp/d1-r157.out | cut -c1-90)\" — the failed query was recorded as 'no postings'" || control NOT-REPRODUCED "D1 R157 script exit $rc"
mysql -e "DROP DATABASE IF EXISTS tegh_sqlfail_src"; rm -rf /srv/sqlfail-src $OUT/sqlfail

# D2. A Tegh request that runs for ~15 s and keeps changing a customer's name from second 7 to second 15 (no row
#     count or ledger total changes). zz-r158-slow-probe.php is a test-host-only page that loads Tegh's bootstrap.
PLOG=/srv/gate/backups/slow-probe.log
probe(){ curl -sk --noproxy '*' --resolve gate.test:443:127.0.0.1 "https://gate.test/zz-r158-slow-probe.php?id=$CU&wait=7&for=8&tag=$1" > /tmp/probe-$1.out; }
writes(){ local n; n=$(grep -c " write #" "$PLOG" 2>/dev/null); echo "${n:-0}"; }
rm -f $PLOG; rm -rf $OUT/d2 && mkdir -p $OUT/d2
probe d2new & P=$!; sleep 1
TEGH_DB=tegh_gate TEGH_STORAGE=/srv/gate/sr-accountax-private/storage TEGH_OUT=$OUT/d2 $OPS/tegh-backup.sh > /tmp/d2.out 2>&1; rc=$?; wait $P; sed 's/^/   /' /tmp/d2.out
final=$(mysql -N tegh_gate -e "SELECT name FROM customers WHERE id='$CU'"); D2B=$(ls -d $OUT/d2/tegh-* 2>/dev/null | tail -1); n=$(writes)
waited=$(grep -o 'waited [0-9]*s' /tmp/d2.out | grep -o '[0-9]*')
[ $rc = 0 ] && grep -q "waiting for 1 request" /tmp/d2.out && [ "${waited:-0}" -gt 5 ] && [ -n "$D2B" ] && gunzip -c "$D2B/db.sql.gz" | grep -qF "'$final'" && res PASS "D2 a request running ${waited}s after the pause (longer than five seconds; $n writes) was waited for; the backup holds its final state ('$final') and the before/after content check passed" || res FAIL "D2 long request (exit $rc, waited ${waited:-?}s, final '$final')"
rm -f $PLOG; probe d2old & P=$!; sleep 1; b0=$(writes)
TEGH_DB=tegh_gate TEGH_STORAGE=/srv/gate/sr-accountax-private/storage TEGH_OUT=$OUT/d2 /tmp/r157-backup.sh > /tmp/d2-r157.out 2>&1; rc=$?; b1=$(writes); wait $P
[ $rc = 0 ] && grep -q "consistent snapshot" /tmp/d2-r157.out && [ $((b1-b0)) -gt 0 ] && control REPRODUCED "D2 the R157 script: stopped waiting after 5 s and reported a consistent snapshot while the request made $((b1-b0)) changes during the backup" || control NOT-REPRODUCED "D2 R157 script exit $rc, writes during it $((b1-b0))"
rm -rf $OUT/d2

# D3. Another program changes a customer's name every 0.1 s during the backup (row counts and ledger totals unchanged).
loop(){ i=0; while [ ! -e /tmp/d3.stop ]; do i=$((i+1)); mysql tegh_gate -e "UPDATE customers SET name='R158 Loop $1 $i' WHERE id='$CU'"; sleep 0.1; done; }
rm -rf $OUT/d3 /tmp/d3.stop && mkdir -p $OUT/d3; loop new & L=$!; sleep 1
TEGH_DB=tegh_gate TEGH_STORAGE=/srv/gate/sr-accountax-private/storage TEGH_OUT=$OUT/d3 $OPS/tegh-backup.sh > /tmp/d3.out 2>&1; rc=$?; touch /tmp/d3.stop; wait $L; rm -f /tmp/d3.stop; sed 's/^/   /' /tmp/d3.out
kept=$(ls -d $OUT/d3/tegh-* 2>/dev/null | wc -l)
[ $rc != 0 ] && grep -q "changed while the backup ran:.*customers" /tmp/d3.out && [ "$kept" = 0 ] && nflag /srv/gate/sr-accountax-private/storage && res PASS "D3 an update that changes neither row counts nor ledger totals is detected by the content fingerprint (table customers named); the backup is removed and changes are resumed" || res FAIL "D3 silent update (exit $rc, folders kept $kept)"
loop old & L=$!; sleep 1
TEGH_DB=tegh_gate TEGH_STORAGE=/srv/gate/sr-accountax-private/storage TEGH_OUT=$OUT/d3 /tmp/r157-backup.sh > /tmp/d3-r157.out 2>&1; rc=$?; touch /tmp/d3.stop; wait $L; rm -f /tmp/d3.stop
[ $rc = 0 ] && grep -q "consistent snapshot" /tmp/d3-r157.out && control REPRODUCED "D3 the R157 script accepted the backup while the name changed throughout it (row counts and ledger totals were equal)" || control NOT-REPRODUCED "D3 R157 script exit $rc"
rm -rf $OUT/d3; mysql tegh_gate -e "UPDATE customers SET name='R158 Probe Customer' WHERE id='$CU'"

echo "== E. request markers and the restore check"
rm -rf /srv/mk-src $OUT/mk && mkdir -p /srv/mk-src/storage/runtime/active-requests $OUT/mk
mysql -e "DROP DATABASE IF EXISTS tegh_mk_src; CREATE DATABASE tegh_mk_src"; mysqldump --no-data tegh_gate | mysql tegh_mk_src
touch -d '20 minutes ago' /srv/mk-src/storage/runtime/active-requests/20260101000000-1-leftover
TEGH_DB=tegh_mk_src TEGH_STORAGE=/srv/mk-src/storage TEGH_OUT=$OUT/mk TEGH_STALE_AFTER=900 $OPS/tegh-backup.sh > /tmp/e1.out 2>&1; rc=$?; sed 's/^/   /' /tmp/e1.out
[ $rc = 0 ] && grep -q "left over from stopped processes" /tmp/e1.out && grep -q "waited 0s" /tmp/e1.out && res PASS "E1 a marker older than TEGH_STALE_AFTER (a crashed request) is reported and does not block the backup" || res FAIL "E1 stale marker (exit $rc)"
rm -rf $OUT/mk/tegh-*; touch /srv/mk-src/storage/runtime/active-requests/20260101000000-2-running
TEGH_DB=tegh_mk_src TEGH_STORAGE=/srv/mk-src/storage TEGH_OUT=$OUT/mk TEGH_WAIT_MAX=4 $OPS/tegh-backup.sh > /tmp/e2.out 2>&1; rc=$?; sed 's/^/   /' /tmp/e2.out
kept=$(ls -d $OUT/mk/tegh-* 2>/dev/null | wc -l)
[ $rc != 0 ] && grep -q "still running after 4s" /tmp/e2.out && [ "$kept" = 0 ] && nflag /srv/mk-src/storage && res PASS "E2 a request still running at TEGH_WAIT_MAX stops the backup (none kept) and changes are resumed" || res FAIL "E2 never-ending request (exit $rc, kept $kept)"
mysql -e "DROP DATABASE IF EXISTS tegh_mk_src"; rm -rf /srv/mk-src $OUT/mk
# E3. A backup whose dump differs from its check figures in one value only (same row counts and totals).
rm -rf /tmp/e3 && cp -r "$B" /tmp/e3 && gunzip -c /tmp/e3/db.sql.gz | sed "0,/'R158 Probe Customer'/s//'R158 Probe Custxmer'/" | gzip -9 > /tmp/e3/db2 && mv /tmp/e3/db2 /tmp/e3/db.sql.gz && (cd /tmp/e3 && sha256sum db.sql.gz storage.tar.gz checks.tsv files.sha256 > SHA256SUMS)
if gunzip -c /tmp/e3/db.sql.gz | grep -q "R158 Probe Custxmer"; then
  TEGH_LIVE_CONFIG=$LIVE_CFG RESTORE_DB=tegh_restore_test RESTORE_STORAGE=/srv/rst/sr-accountax-private/storage $OPS/tegh-restore-verify.sh /tmp/e3 > /tmp/e3.out 2>&1; rc=$?; grep -E "row counts|customers|content|RESTORE" /tmp/e3.out | sed 's/^/   /'
  [ $rc != 0 ] && grep -q "PASS  row counts" /tmp/e3.out && grep -q "FAIL  customers: content differs" /tmp/e3.out && res PASS "E3 the restore check finds a changed value that row counts and totals miss" || res FAIL "E3 restore content check (exit $rc)"
  TEGH_LIVE_CONFIG=$LIVE_CFG RESTORE_DB=tegh_restore_test RESTORE_STORAGE=/srv/rst/sr-accountax-private/storage /tmp/r157-restore-verify.sh /tmp/e3 > /tmp/e3-r157.out 2>&1; rc=$?
  [ $rc = 0 ] && grep -q "RESTORE VERIFIED" /tmp/e3-r157.out && control REPRODUCED "E3 the R157 restore check reported RESTORE VERIFIED for the same changed backup" || control NOT-REPRODUCED "E3 R157 restore check exit $rc"
else res FAIL "E3 could not prepare the changed backup (probe customer not in backup A)"; fi
# E4. The restored database answers the ledger query with an error: a failure, never "no postings".
rm -rf /tmp/e4 && cp -r "$B" /tmp/e4 && (gunzip -c "$B/db.sql.gz"; echo "ALTER TABLE journal_lines RENAME COLUMN debit_cents TO debit_cents_renamed;") | gzip -9 > /tmp/e4/db.sql.gz && (cd /tmp/e4 && sha256sum db.sql.gz storage.tar.gz checks.tsv files.sha256 > SHA256SUMS)
TEGH_LIVE_CONFIG=$LIVE_CFG RESTORE_DB=tegh_restore_test RESTORE_STORAGE=/srv/rst/sr-accountax-private/storage $OPS/tegh-restore-verify.sh /tmp/e4 > /tmp/e4.out 2>&1; rc=$?; grep -E "posted|balance|RESTORE" /tmp/e4.out | sed 's/^/   /'
[ $rc != 0 ] && grep -q "FAIL  posted totals could not be read" /tmp/e4.out && grep -q "FAIL  the balance check could not be run" /tmp/e4.out && res PASS "E4 a database error in the restore's ledger and balance checks is a failure" || res FAIL "E4 restore SQL failure (exit $rc)"
rm -rf /tmp/e3 /tmp/e4
echo "CONTROLS: $ctl of $((ctl+ctlbad)) R157 runs showed the defect the R158 test guards against"

echo "== C. empty installation"
mysql -e "DROP DATABASE IF EXISTS tegh_empty_src; CREATE DATABASE tegh_empty_src CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"; mysqldump --no-data tegh_gate | mysql tegh_empty_src
rm -rf /srv/empty-src && mkdir -p /srv/empty-src/storage /srv/rst-empty && echo "db=tegh_restore_empty" > /srv/rst-empty/.tegh-restore-target
printf "<?php return ['db'=>['dsn'=>'mysql:host=localhost;dbname=tegh_empty_src'],'storage_path'=>'/srv/empty-src/storage'];" > /tmp/empty-live.php
rm -rf $OUT/empty && mkdir -p $OUT/empty
TEGH_DB=tegh_empty_src TEGH_STORAGE=/srv/empty-src/storage TEGH_OUT=$OUT/empty $OPS/tegh-backup.sh > /tmp/beta-empty.out 2>&1; rc=$?; sed 's/^/   /' /tmp/beta-empty.out
[ $rc = 0 ] && res PASS "C1 backup of an installation with no records and no uploads" || res FAIL "C1 empty backup (exit $rc)"
E=$(ls -d $OUT/empty/tegh-* 2>/dev/null | tail -1)
TEGH_LIVE_CONFIG=/tmp/empty-live.php RESTORE_DB=tegh_restore_empty RESTORE_STORAGE=/srv/rst-empty/storage $OPS/tegh-restore-verify.sh "$E" > /tmp/beta-empty-restore.out 2>&1; rc=$?; sed 's/^/   /' /tmp/beta-empty-restore.out
[ $rc = 0 ] && grep -q "RESTORE VERIFIED" /tmp/beta-empty-restore.out && res PASS "C2 restore of the empty installation verified" || res FAIL "C2 empty restore (exit $rc)"
mysql -e "DROP DATABASE IF EXISTS tegh_empty_src; DROP DATABASE IF EXISTS tegh_restore_empty"; rm -rf /srv/empty-src /srv/rst-empty /srv/rst-unmarked $OUT/empty
echo "RESULT: $pass PASS / $failc FAIL"

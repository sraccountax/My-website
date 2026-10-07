#!/bin/bash
# Tests beta-ops/tegh-backup.sh and tegh-restore-verify.sh on the gate host (synthetic data only).
#  A. backup while another process keeps writing: the backup must be consistent, writes during the pause get 503
#  B. the restore refuses every target that is not a designated restore-test installation, and the live data is untouched
#  C. an installation with no records and no uploads backs up and restores
set -u
OPS=/home/user/My-website/beta-ops; LIVE_CFG=/srv/gate/sr-accountax-private/config.php; OUT=/srv/gate/backups
export NODE_EXTRA_CA_CERTS=/srv/gate/pki/ca.crt
pass=0; failc=0; res(){ if [ "$1" = PASS ]; then pass=$((pass+1)); else failc=$((failc+1)); fi; echo "$1  $2"; }
rm -rf $OUT/tegh-*; mkdir -p $OUT
echo "db=tegh_restore_test" > /srv/rst/sr-accountax-private/.tegh-restore-target

echo "== A. backup during writes"
node /srv/gate/t/beta-writer.mjs 40 > /tmp/beta-writer.out 2>&1 & W=$!
sleep 6
TEGH_DB=tegh_gate TEGH_STORAGE=/srv/gate/sr-accountax-private/storage TEGH_OUT=$OUT TEGH_QUIESCE=3 $OPS/tegh-backup.sh > /tmp/beta-backup.out 2>&1; rc=$?
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

echo "== C. empty installation"
mysql -e "DROP DATABASE IF EXISTS tegh_empty_src; CREATE DATABASE tegh_empty_src CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"; mysqldump --no-data tegh_gate | mysql tegh_empty_src
rm -rf /srv/empty-src && mkdir -p /srv/empty-src/storage /srv/rst-empty && echo "db=tegh_restore_empty" > /srv/rst-empty/.tegh-restore-target
printf "<?php return ['db'=>['dsn'=>'mysql:host=localhost;dbname=tegh_empty_src'],'storage_path'=>'/srv/empty-src/storage'];" > /tmp/empty-live.php
rm -rf $OUT/empty && mkdir -p $OUT/empty
TEGH_DB=tegh_empty_src TEGH_STORAGE=/srv/empty-src/storage TEGH_OUT=$OUT/empty TEGH_QUIESCE=1 $OPS/tegh-backup.sh > /tmp/beta-empty.out 2>&1; rc=$?; sed 's/^/   /' /tmp/beta-empty.out
[ $rc = 0 ] && res PASS "C1 backup of an installation with no records and no uploads" || res FAIL "C1 empty backup (exit $rc)"
E=$(ls -d $OUT/empty/tegh-* 2>/dev/null | tail -1)
TEGH_LIVE_CONFIG=/tmp/empty-live.php RESTORE_DB=tegh_restore_empty RESTORE_STORAGE=/srv/rst-empty/storage $OPS/tegh-restore-verify.sh "$E" > /tmp/beta-empty-restore.out 2>&1; rc=$?; sed 's/^/   /' /tmp/beta-empty-restore.out
[ $rc = 0 ] && grep -q "RESTORE VERIFIED" /tmp/beta-empty-restore.out && res PASS "C2 restore of the empty installation verified" || res FAIL "C2 empty restore (exit $rc)"
mysql -e "DROP DATABASE IF EXISTS tegh_empty_src; DROP DATABASE IF EXISTS tegh_restore_empty"; rm -rf /srv/empty-src /srv/rst-empty /srv/rst-unmarked $OUT/empty
echo "RESULT: $pass PASS / $failc FAIL"

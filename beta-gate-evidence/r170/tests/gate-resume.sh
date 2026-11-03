#!/bin/bash
# Whole gate on an exact ZIP, resumable across container restarts: reset once, each API/click-through suite once
# (run-all.sh with RA_STATE), then the resumable tail (e2e, journeys, matrix, trap scan, upgrade).
ZIP="$1"; TAG="$2"; ST=/srv/gate/.gate-resume-state; touch $ST
/srv/gate/t/up.sh > /srv/gate/up.log 2>&1
cd /srv/gate/t
if ! grep -qx reset $ST; then
  bad=$(zipinfo "$ZIP" | awk 'NR>2 && $1 ~ /^-/ && $1 !~ /^-rw-r--r--/' | wc -l); echo "ZIP-PERMS: $bad file entries not 0644" > /srv/gate/zip-perms.txt
  bash /srv/gate/t/reset.sh "$ZIP" "$TAG" && cp /srv/gate/zip-perms.txt /srv/gate/ev/ && bash /srv/gate/t/paths.sh > /dev/null 2>&1; echo reset >> $ST
fi
echo "=== resume $(date +%H:%M)" >> /srv/gate/ev/run-all.log
RA_STATE=$ST bash run-all.sh >> /srv/gate/ev/run-all.log 2>&1
bash /srv/gate/t/gate-tail.sh "$ZIP" > /srv/gate/ev/tail-run.log 2>&1
grep -q TAIL-DONE /srv/gate/ev/tail-run.log && echo GATE-RESUME-DONE >> /srv/gate/ev/tail-run.log

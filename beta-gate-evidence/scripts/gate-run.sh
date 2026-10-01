#!/bin/bash
# Full gate on an exact ZIP: reset, paths, API suite + click-through suite, aggregate.
ZIP="$1"; TAG="$2"
/srv/gate/t/up.sh > /srv/gate/up.log 2>&1
bash /srv/gate/t/reset.sh "$ZIP" "$TAG"
bash /srv/gate/t/paths.sh > /dev/null 2>&1; tail -1 /srv/gate/ev/paths.txt
cd /srv/gate/t && bash run-all.sh > /srv/gate/ev/run-all.log 2>&1
rm -f /srv/gate/ev/r144ui.json; for v in '1440 900 desk' '390 844 phone'; do NODE_EXTRA_CA_CERTS=/srv/gate/pki/ca.crt timeout 400 node journeys144.mjs $v > /dev/null 2>&1; done
grep -E "^(FAIL|BLOCKED)" /srv/gate/ev/run-all.log | head -20
python3 aggregate.py | head -2
echo GATE-RUN-DONE

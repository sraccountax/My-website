#!/bin/bash
# Remaining gate steps after run-all, resumable: each finished step is recorded in $STATE and skipped on a re-run
# (the container can restart mid-gate; the database and evidence files survive a restart).
ZIP="$1"; STATE=/srv/gate/ev/.tail-state; touch $STATE
[ -f /srv/gate/t/clock.env ] && . /srv/gate/t/clock.env
export NODE_EXTRA_CA_CERTS=/srv/gate/pki/ca.crt NODE_OPTIONS="--import /srv/gate/t/mfa-preload.mjs"
/srv/gate/t/up.sh > /srv/gate/up.log 2>&1
step(){ grep -qx "$1" $STATE && { echo "skip $1"; return 1; }; echo "=== $1 $(date +%H:%M)"; return 0; }
done_(){ echo "$1" >> $STATE; }
cd /srv/gate/t
step e2e && { bash /srv/gate/t/e2e/run-e2e.sh >> /srv/gate/ev/run-all.log 2>&1; done_ e2e; }
step journeys && { rm -f /srv/gate/ev/r144ui.json; for v in '1440 900 desk' '390 844 phone'; do timeout 400 node journeys144.mjs $v > /dev/null 2>&1; done; done_ journeys; }
step matrix1 && { sed -n 1,8p matrix.sh > /tmp/m1.sh; timeout 1500 bash /tmp/m1.sh > /srv/gate/ev/matrix.log 2>&1; done_ matrix1; }
step matrix2 && { { sed -n 1,2p matrix.sh; sed -n '9,$p' matrix.sh; } > /tmp/m2.sh; timeout 1500 bash /tmp/m2.sh >> /srv/gate/ev/matrix.log 2>&1; done_ matrix2; }
step trapscan && { timeout 600 node trapscan.mjs 390 844 > /srv/gate/ev/trapscan.txt 2>&1; done_ trapscan; }
step upgrade && { (cd /home/user/My-website/tegh-app && rm -rf /srv/upg/www && mkdir -p /srv/upg/www && git archive 44cab22 | tar -x -C /srv/upg/www; rm -rf /srv/upg/sr-accountax-private/storage/*)
  mysql -e "DROP DATABASE tegh_upg; CREATE DATABASE tegh_upg CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
  GATE_ORIGIN=https://gate.test:8443 GATE_DB=tegh_upg node 11-upg-seed.mjs > /srv/gate/ev/upg-seed.log 2>&1
  mysql tegh_upg -N -e "select a.code,sum(jl.debit_cents)-sum(jl.credit_cents) from journal_lines jl join accounts a on a.id=jl.account_id group by a.code order by 1" > /srv/gate/ev/upg-tb-before.txt
  (cd /srv/upg/www && unzip -qo "$ZIP" && sha256sum -c --quiet FILE-MANIFEST.sha256 && echo MANIFEST-OK && service php8.3-fpm reload >/dev/null)
  GATE_ORIGIN=https://gate.test:8443 GATE_DB=tegh_upg node 12-upg-verify.mjs > /srv/gate/ev/upg-verify.log 2>&1; done_ upgrade; }
python3 aggregate.py | head -4
tail -1 /srv/gate/ev/trapscan.txt
echo TAIL-DONE

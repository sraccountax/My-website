#!/bin/bash
[ -f /srv/gate/t/clock.env ] && . /srv/gate/t/clock.env
# UI matrix (both halves), swipe-trap scan and R118 -> ZIP upgrade, after gate-run.sh.
ZIP="$1"; cd /srv/gate/t; export NODE_EXTRA_CA_CERTS=/srv/gate/pki/ca.crt
sed -n 1,8p matrix.sh > /tmp/m1.sh
timeout 1500 bash /tmp/m1.sh > /srv/gate/ev/matrix.log 2>&1
timeout 1500 bash /tmp/m2.sh >> /srv/gate/ev/matrix.log 2>&1
timeout 600 node trapscan.mjs 390 844 > /srv/gate/ev/trapscan.txt 2>&1
cd /home/user/My-website/tegh-app
rm -rf /srv/upg/www && mkdir -p /srv/upg/www && git archive 44cab22 | tar -x -C /srv/upg/www; rm -rf /srv/upg/sr-accountax-private/storage/*
mysql -e "DROP DATABASE tegh_upg; CREATE DATABASE tegh_upg CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
cd /srv/gate/t; GATE_ORIGIN=https://gate.test:8443 GATE_DB=tegh_upg node 11-upg-seed.mjs > /srv/gate/ev/upg-seed.log 2>&1
mysql tegh_upg -N -e "select a.code,sum(jl.debit_cents)-sum(jl.credit_cents) from journal_lines jl join accounts a on a.id=jl.account_id group by a.code order by 1" > /srv/gate/ev/upg-tb-before.txt
cd /srv/upg/www && unzip -qo "$ZIP" && sha256sum -c --quiet FILE-MANIFEST.sha256 && echo MANIFEST-OK && service php8.3-fpm reload >/dev/null
cd /srv/gate/t; GATE_ORIGIN=https://gate.test:8443 GATE_DB=tegh_upg node 12-upg-verify.mjs
python3 aggregate.py | head -4
tail -1 /srv/gate/ev/trapscan.txt
echo FINISH-RUN-DONE

#!/bin/bash
cd /srv/gate/t; export NODE_EXTRA_CA_CERTS=/srv/gate/pki/ca.crt
for t in 01-install 02-setup 02b-invite 03-acct 04-tax 05-fx 06-sec 06b 07-cv 08-pay 09-backup 09b 10-ops 13-r141 14-r142 15-r144 16-r145 17-r148; do
  echo "=== $t"; [ "$t" = 07-cv ] && mysql tegh_gate -e "DELETE FROM login_attempts"
  timeout 900 node $t.mjs 2>&1 | grep -v "^PASS" | tail -40
done
echo "=== e2e"; bash /srv/gate/t/e2e/run-e2e.sh

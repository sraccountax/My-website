#!/bin/bash
# Click-through workflow tests on a fresh synthetic company; every result is checked against the database.
cd /srv/gate/t/e2e; export NODE_EXTRA_CA_CERTS=/srv/gate/pki/ca.crt; rm -f /srv/gate/ev/e2e.json
node mk.mjs
for w in w1 w2 w3 w4 w5 w6 w7 w8 w9; do echo "=== $w"; timeout 400 node $w.mjs 2>&1 | grep -E "^(FAIL|BLOCKED)|Error" | head -10; done

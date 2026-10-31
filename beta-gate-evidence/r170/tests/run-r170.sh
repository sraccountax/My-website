#!/bin/bash
cd /srv/gate/t; set -a; . ./clock.env; set +a; export NODE_EXTRA_CA_CERTS=/srv/gate/pki/ca.crt NODE_OPTIONS="--import /srv/gate/t/mfa-preload.mjs"
timeout 3000 node 45-r170.mjs > /srv/gate/ev/r170-run.log 2>&1; echo SUITE-DONE >> /srv/gate/ev/r170-run.log

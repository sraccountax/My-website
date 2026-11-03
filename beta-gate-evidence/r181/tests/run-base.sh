#!/bin/bash
cd /srv/gate/t && . ./clock.env; export NODE_EXTRA_CA_CERTS=/srv/gate/pki/ca.crt NODE_OPTIONS="--import /srv/gate/t/mfa-preload.mjs" GATE_SETUP_KEY_FILE=/srv/gate/t/.setup-key
for run in a b; do rm -rf /srv/gate/r181-base/r180-$run; timeout 1500 node dev/r181-capture.mjs /srv/gate/r181-base/r180-$run; done
python3 -I /srv/gate/t/dev/r181-compare.py /srv/gate/r181-base/r180-a /srv/gate/r181-base/r180-b
echo BASE-DONE

#!/bin/bash
cd /srv/gate/t; . ./clock.env; export NODE_EXTRA_CA_CERTS=/srv/gate/pki/ca.crt NODE_OPTIONS="--import /srv/gate/t/mfa-preload.mjs" GATE_SETUP_KEY_FILE=/srv/gate/t/.setup-key
CA=company_b41e96a7d5d0fda7a306f8826da2fdae; IN=company_d869aab7db2fbfb2d1ae2cdec1247aa8
run(){ mysql tegh_gate -e "DELETE FROM login_attempts"; MODE=$2 THEME=dark NAV=side timeout 3000 node audit-sweep.mjs $1 $3 $4 $5 $6 1 > /srv/gate/ev-r193/$6.txt 2>&1; echo "$6 $?"; }
if [ "$1" = 0 ]; then run CA full $CA 1440 900 dk-ca-full-1440; run CA guided $CA 1440 900 dk-ca-guided-1440; run CA full $CA 390 844 dk-ca-full-390; fi
if [ "$1" = 1 ]; then run IN full $IN 1440 900 dk-in-full-1440; run IN full $IN 390 844 dk-in-full-390; run CA guided $CA 390 844 dk-ca-guided-390; fi
echo WORKER-DONE

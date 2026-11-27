#!/bin/bash
# R172: all sweep configurations, resumable (finished tags are listed in /srv/gate/ev/.deep-state).
cd /srv/gate/t; set -a; . ./clock.env; set +a; export NODE_EXTRA_CA_CERTS=/srv/gate/pki/ca.crt NODE_OPTIONS="--import /srv/gate/t/mfa-preload.mjs"
ST=/srv/gate/ev/.deep-state; touch $ST
run(){ tag=$7; grep -qx "$tag" $ST && return; timeout 3600 node deep172.mjs "$@" > /srv/gate/ev/deep-$tag.log 2>&1; echo "$tag" >> $ST; }
run 1440 900 dark top full owner d1440-dark-top BC
run 390 844 light side full owner p390 BC
run 390 844 dark side full owner p390-dark BC
run 1920 1080 light side full owner d1920 BC
run 1024 768 light top full owner d1024-top BC
run 1440 900 light side guided owner g1440 BC
run 390 844 light side guided owner g390 BC
run 1440 900 light side full viewer v1440 BC
run 1440 900 light side full editor e1440 BC
run 1440 900 light side full owner d1440-empty R171
run 390 844 light side full viewer v390 BC
echo ALL-DONE >> $ST

#!/bin/bash
# R193 screen matrix on the large companies: edition x bookkeeping mode x theme x navigation x width.
# Usage: matrix-r193.sh <worker 0|1> <workers>
cd /srv/gate/t; . ./clock.env; export NODE_EXTRA_CA_CERTS=/srv/gate/pki/ca.crt NODE_OPTIONS="--import /srv/gate/t/mfa-preload.mjs" GATE_SETUP_KEY_FILE=/srv/gate/t/.setup-key
CA=${CA:-company_b41e96a7d5d0fda7a306f8826da2fdae}; IN=${IN:-company_d869aab7db2fbfb2d1ae2cdec1247aa8}; OUT=${OUT:-/srv/gate/ev-r193}; mkdir -p $OUT
RUNS=()
for w in "1440 900" "390 844"; do for nav in side top; do for th in light dark; do
  for m in full guided; do RUNS+=("CA $CA $w $m $th $nav"); done
  RUNS+=("IN $IN $w full $th $nav")
done; done; done
WK=$1; NW=$2; k=0
for r in "${RUNS[@]}"; do
  if [ $((k % NW)) -eq $WK ]; then read ED CO W H M TH NV <<< "$r"; tag="${PFX:-r193}-$(echo $ED | tr A-Z a-z)-$M-$TH-$NV-$W"
    mysql tegh_gate -e "DELETE FROM login_attempts"
    MODE=$M THEME=$TH NAV=$NV timeout 3000 node audit-sweep.mjs $ED $CO $W $H $tag 1 > $OUT/$tag.txt 2>&1; echo "$tag $?"
  fi
  k=$((k+1))
done
echo WORKER-DONE

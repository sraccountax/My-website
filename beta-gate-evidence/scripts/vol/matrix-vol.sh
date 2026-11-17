#!/bin/bash
# Final functional test: every screen in each mode, navigation layout, theme and screen size, on the large companies.
cd /srv/gate/t/vol; export NODE_EXTRA_CA_CERTS=/srv/gate/pki/ca.crt
A=$(ls expected-company_*.json | xargs grep -l '"Volume Northwind' | sed 's/expected-//;s/.json//'); B=$(ls expected-company_*.json | xargs grep -l '"Volume Lakeshore' | sed 's/expected-//;s/.json//')
R(){ GATE_COMPANY_IDS=$CO SCHEME=${SCHEME:-light} timeout 1500 node ui-sweep-vol.mjs "$@" owner@gate.test 'Gate!Owner#2026pw' 2>&1 | head -40; }
CO=$A
R 1920 1080 1 light side full f-side-1920
R 1440 900 1 light side full f-side-1440
R 768 1024 1 light side full f-side-768
R 390 844 1 light side full f-side-390
R 320 640 1 light side full f-side-320
R 1366 768 1.5 light side full f-side-zoom150
R 1366 768 1 dark side full f-side-dark-1366
R 390 844 1 dark side full f-side-dark-390
R 1440 900 1 light top full f-top-1440
R 768 1024 1 light top full f-top-768
R 390 844 1 light top full f-top-390
R 1440 900 1 dark top full f-top-dark-1440
R 1440 900 1 light side guided g-side-1440
R 768 1024 1 light side guided g-side-768
R 390 844 1 light side guided g-side-390
R 320 640 1 light side guided g-side-320
R 1440 900 1 dark side guided g-side-dark-1440
R 390 844 1 dark side guided g-side-dark-390
R 1440 900 1 light top guided g-top-1440
R 390 844 1 light top guided g-top-390
R 1366 768 1 dark top guided g-top-dark-1366
SCHEME=dark R 1440 900 1 auto side full sys-f-side-1440
SCHEME=dark R 390 844 1 auto top guided sys-g-top-390
CO=$A,$B
R 1440 900 1 light side full m-f-side-1440
R 390 844 1 dark top full m-f-top-dark-390
R 1440 900 1 light side guided m-g-side-1440
echo MATRIX-DONE

#!/bin/bash
# Restore the R180 snapshot (exact data), keep the R181 code in /srv/gate/www, capture, compare with the R180 capture.
set -e
TAG=${1:-r181-off}
mysql -e "DROP DATABASE tegh_gate; CREATE DATABASE tegh_gate CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql tegh_gate < /srv/gate/r181-base/tegh_gate-r180.sql
if [ -n "$SWITCH" ]; then mysql tegh_gate -e "UPDATE site_settings SET value_json=JSON_SET(value_json,'$.worldCurrencies','$SWITCH') WHERE setting_group='release'"; echo "switch: $(mysql tegh_gate -N -e "SELECT value_json FROM site_settings WHERE setting_group='release'")"; fi
echo "restored: $(mysql tegh_gate -N -e "SELECT COUNT(*) FROM companies") companies; r181 table: $(mysql tegh_gate -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='tegh_gate' AND table_name='company_exchange_rates'")"
bash /srv/gate/t/clock-pin.sh
cd /srv/gate/t && . ./clock.env; export NODE_EXTRA_CA_CERTS=/srv/gate/pki/ca.crt NODE_OPTIONS="--import /srv/gate/t/mfa-preload.mjs" GATE_SETUP_KEY_FILE=/srv/gate/t/.setup-key
rm -rf /srv/gate/r181-base/$TAG
timeout 1500 node dev/r181-capture.mjs /srv/gate/r181-base/$TAG
echo "after capture, r181 table: $(mysql tegh_gate -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='tegh_gate' AND table_name='company_exchange_rates'")"
python3 -I /srv/gate/t/dev/r181-compare.py /srv/gate/r181-base/r180-a /srv/gate/r181-base/$TAG --report /srv/gate/r181-base/$TAG-report.json || true
echo COMPARE-DONE

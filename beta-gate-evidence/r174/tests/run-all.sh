#!/bin/bash
[ -f /srv/gate/t/clock.env ] && . /srv/gate/t/clock.env
cd /srv/gate/t; export NODE_EXTRA_CA_CERTS=/srv/gate/pki/ca.crt; export NODE_OPTIONS="--import /srv/gate/t/mfa-preload.mjs"; export GATE_SETUP_KEY_FILE=${GATE_SETUP_KEY_FILE:-/srv/gate/t/.setup-key}
for t in 01-install 02-setup 02b-invite 03-acct 04-tax 05-fx 06-sec 06b 07-cv 08-pay 09-backup 09b 10-ops 13-r141 14-r142 15-r144 16-r145 18-r149 19-r151 20-r151ui 21-r152 22-r153 23-r154 24-beta 25-r157 26-r158 27-r159 28-r160 29-r161 30-guided 31-r163 32-r163-sec 33-sec 34-mfa 35-session 36-monitor 37-audit 38-files 39-restore 40-r165 41-r166 42-r167 43-r168 44-r169 45-r170 46-r171 47-r172; do
  # Resumable when RA_STATE names a state file: finished suites are skipped after a container restart.
  [ -n "$RA_STATE" ] && grep -qx "$t" "$RA_STATE" && { echo "skip $t"; continue; }
  echo "=== $t"; [ "$t" = 07-cv ] && mysql tegh_gate -e "DELETE FROM login_attempts"; { [ "$t" = 24-beta ] || [ "$t" = 25-r157 ] || [ "$t" = 26-r158 ] || [ "$t" = 27-r159 ] || [ "$t" = 28-r160 ] || [ "$t" = 29-r161 ] || [ "$t" = 30-guided ] || [ "$t" = 31-r163 ] || [ "$t" = 32-r163-sec ] || [ "$t" = 33-sec ]; } && mysql tegh_gate -e "DELETE FROM invitation_attempts; DELETE FROM login_attempts"
  timeout $([ "$t" = 29-r161 ] && echo 2700 || { { [ "$t" = 30-guided ] || [ "$t" = 31-r163 ]; } && echo 1500 || { [ "$t" = 45-r170 ] && echo 2700; }  || echo 900; }) node $t.mjs 2>&1 | grep -v "^PASS" | tail -40
  [ -n "$RA_STATE" ] && echo "$t" >> "$RA_STATE"
done
[ -n "$RA_STATE" ] && exit 0  # e2e and the rest run from gate-tail.sh
echo "=== e2e"; bash /srv/gate/t/e2e/run-e2e.sh

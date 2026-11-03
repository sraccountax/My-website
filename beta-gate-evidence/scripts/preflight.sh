#!/bin/bash
# Bring the gate host up after a container restart and verify every dependency the suites need.
grep -q " gate.test" /etc/hosts || echo "127.0.0.1 gate.test" >> /etc/hosts
update-ca-certificates >/dev/null 2>&1
for s in mariadb php8.3-fpm apache2; do service $s status >/dev/null 2>&1 || service $s start >/dev/null 2>&1; done
if ! ss -ltn | grep -q ':2587 '; then
  ( cd /srv/gate && exec setsid -f python3 /srv/gate/smtptls.py /srv/gate/mail pki/srv.crt pki/srv.key sandbox@gate.test "$(cat pki/smtp.pw)" >/srv/gate/smtp.log 2>&1 </dev/null 3>&- 4>&- 5>&- 6>&- 7>&- 8>&- 9>&- )
  for i in $(seq 1 20); do ss -ltn | grep -q ':2587 ' && break; sleep 0.5; done
fi
ok=1
getent hosts gate.test >/dev/null && echo "hosts ok" || { echo "hosts FAIL"; ok=0; }
ss -ltn | grep -q ':2587 ' && echo "smtp ok" || { echo "smtp FAIL"; ok=0; }
mysql -N -e 'select 1' >/dev/null 2>&1 && echo "db ok" || { echo "db FAIL"; ok=0; }
curl -s --max-time 8 --cacert /srv/gate/pki/ca.crt --noproxy '*' https://gate.test/api/health | grep -q '"ok":true' && echo "web ok" || { echo "web FAIL"; ok=0; }
[ $ok = 1 ] && echo PREFLIGHT-OK || echo PREFLIGHT-FAILED

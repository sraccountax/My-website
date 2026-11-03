#!/bin/bash
# Bring the gate host back after a container restart.
grep -q gate.test /etc/hosts || echo "127.0.0.1 gate.test" >> /etc/hosts
update-ca-certificates >/dev/null 2>&1
for s in mariadb php8.3-fpm apache2; do service $s status >/dev/null 2>&1 || service $s start >/dev/null 2>&1; done
pgrep -f smtptls.py >/dev/null || (cd /srv/gate && setsid nohup python3 /srv/gate/smtptls.py /srv/gate/mail pki/srv.crt pki/srv.key sandbox@gate.test "$(cat pki/smtp.pw)" >/srv/gate/smtp.log 2>&1 < /dev/null &)
sleep 2
echo "health: $(curl -s --noproxy '*' --cacert /srv/gate/pki/ca.crt https://gate.test/api/health | head -c 80)"
pgrep -f smtptls.py >/dev/null && echo "smtp: up" || echo "smtp: DOWN"

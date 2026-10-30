#!/bin/bash
# R175: a separate demo site (https://gate.test:8444, database tegh_demo) used only to capture the app screenshots for
# the public pages. Synthetic sample data. Runs beside the gate host without touching its database or test state.
# Usage: demo-host.sh <source folder or ZIP>
set -e
SRC="${1:-/home/user/My-website/tegh-app}"; D=/srv/demo
mkdir -p $D/www $D/sr-accountax-private/storage
rm -rf $D/www/*
if [ -f "$SRC" ]; then (cd $D/www && unzip -q "$SRC"); else (cd "$SRC" && git ls-files -co --exclude-standard | tar -cf - -T - | tar -xf - -C $D/www); fi
find $D/www -type f -exec chmod 644 {} +; find $D/www -type d -exec chmod 755 {} +
mysql -e "DROP DATABASE IF EXISTS tegh_demo; CREATE DATABASE tegh_demo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON tegh_demo.* TO 'tegh_gate'@'localhost'; FLUSH PRIVILEGES;"
sed -e "s#'base_url' => 'https://gate.test'#'base_url' => 'https://gate.test:8444'#" -e "s#dbname=tegh_gate#dbname=tegh_demo#" \
    -e "s#'session_cookie' => 'tegh_session'#'session_cookie' => 'tegh_demo_session'#" /srv/gate/sr-accountax-private/config.php > $D/sr-accountax-private/config.php
rm -rf $D/sr-accountax-private/storage/*; : > $D/sr-accountax-private/system-incidents.ndjson
chown -R www-data:www-data $D/sr-accountax-private; chmod 750 $D/sr-accountax-private
grep -q "Listen 8444" /etc/apache2/ports.conf || echo "Listen 8444" >> /etc/apache2/ports.conf
sed -e 's#8443#8444#' -e 's#/srv/upg/www#/srv/demo/www#g' /etc/apache2/sites-available/tegh-upg.conf > /etc/apache2/sites-available/tegh-demo.conf
a2ensite -q tegh-demo >/dev/null 2>&1; service apache2 reload >/dev/null
echo "demo site ready: $(curl -s --noproxy '*' https://gate.test:8444/api/health)"

#!/bin/bash
# Reset the gate host to a given exact ZIP with a fresh database and storage.
set -e; ZIP="$1"; TAG="$2"
mkdir -p /srv/gate/ev-archive; [ -d /srv/gate/ev ] && [ "$(ls -A /srv/gate/ev)" ] && mv /srv/gate/ev /srv/gate/ev-archive/$(date +%s) || true; mkdir -p /srv/gate/ev/shots
rm -rf /srv/gate/www && mkdir -p /srv/gate/www && cd /srv/gate/www && unzip -q "$ZIP"
chown -R root:root /srv/gate/www; find /srv/gate/www -type d -exec chmod 755 {} +; find /srv/gate/www -type f -exec chmod 644 {} +
rm -rf /srv/gate/sr-accountax-private/storage/* ; : > /srv/gate/sr-accountax-private/system-incidents.ndjson; chown -R www-data:www-data /srv/gate/sr-accountax-private
mysql -e "DROP DATABASE tegh_gate; CREATE DATABASE tegh_gate CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
rm -f /srv/gate/mail/*; : > /srv/gate/apache-error.log; : > /srv/gate/apache-access.log; service php8.3-fpm restart >/dev/null; service apache2 reload >/dev/null
{ echo "zip: $ZIP"; sha256sum "$ZIP"; echo "tag: $TAG"; cd /srv/gate/www && sha256sum -c --quiet FILE-MANIFEST.sha256 && echo "FILE-MANIFEST: all $(wc -l < FILE-MANIFEST.sha256) entries OK"; } > /srv/gate/ev/identity.txt
cat /srv/gate/ev/identity.txt

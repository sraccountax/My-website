#!/bin/bash
B=https://gate.test; out=/srv/gate/ev/paths.txt; : > $out
chk(){ c=$(curl -s --noproxy '*' -o /dev/null -w '%{http_code}' "$B/$1"); r=FAIL; [[ " $2 " == *" $c "* ]] && r=PASS; echo "$r $1 -> $c (expect $2)" >> $out; }
for p in api/bootstrap.php api/schema.sql api/config.example.php api/accounting.php api/tax_codes_r137.php api/native-agent-cron.php api/php.ini config.example.php RELEASE-MANIFEST.json PACKAGE-MANIFEST.json tegh-build.json FILE-MANIFEST.sha256 DEPLOYMENT-NOTES.txt README.txt R139-CHANGES.md UPGRADE-SCHEMA-46-R20.md release/ storage/ .htaccess api/.htaccess assets/ guides/ knowledge/ knowledge/README.md sample-data/ api/payroll-catalogue/; do chk $p "403 404"; done
for p in ../sr-accountax-private/config.php "api/..%2f..%2fsr-accountax-private/config.php" nonexistent-xyz.html; do chk "$p" "400 403 404"; done
for p in app.html client-view.html index.html api/health; do chk $p 200; done
c=$(curl -s --noproxy '*' -o /dev/null -w '%{http_code} %{redirect_url}' -H 'Host: books-test.sraccountax.ca' http://127.0.0.1/app.html?x=1); [[ "$c" == "301 https://books-test.sraccountax.ca/app.html?x=1" ]] && echo "PASS http->https redirect: $c" >> $out || echo "FAIL redirect: $c" >> $out
for h in beta.example.test beta.example.test:80; do c=$(curl -s --noproxy '*' -o /dev/null -w '%{http_code} %{redirect_url}' -H "Host: $h" http://127.0.0.1/app.html?x=1); [[ "$c" == "301 https://beta.example.test/app.html?x=1" ]] && echo "PASS http->https redirect for another host name ($h): $c" >> $out || echo "FAIL http->https redirect for $h: $c" >> $out; done
c=$(curl -s --noproxy '*' -o /dev/null -w '%{http_code}' -H 'Host: beta.example.test' -H 'X-Forwarded-Proto: https' http://127.0.0.1/api/health); [[ "$c" == "200" ]] && echo "PASS request that arrived over HTTPS at a proxy is not redirected again: $c" >> $out || echo "FAIL proxied HTTPS request redirected: $c" >> $out
for p in app.html client-view.html "assets/tegh-portal-v5990.js?v=5990-r144-tegh"; do echo "HDR $p: $(curl -s --noproxy '*' -D - -o /dev/null https://gate.test/$p | grep -i '^cache-control' | tr -d '\r')" >> $out; done
h=$(curl -s --noproxy '*' -D - -o /dev/null $B/app.html | tr -d '\r'); for x in Strict-Transport-Security Content-Security-Policy X-Frame-Options X-Content-Type-Options Referrer-Policy; do grep -qi "^$x:" <<<"$h" && echo "PASS header $x" >> $out || echo "FAIL header $x" >> $out; done
curl -s --noproxy '*' $B/app.html | grep -q "clarity.ms" && echo "FAIL app.html loads clarity.ms" >> $out || echo "PASS app.html has no third-party session recording" >> $out
for f in app.html client-view.html index.html; do for u in $(grep -o '\(src\|href\)="/[^"]*"' /srv/gate/www/$f | sed 's/.*="\(.*\)"/\1/' | sort -u); do [ "$u" = "/" ] && continue; c=$(curl -s --noproxy '*' -o /dev/null -w '%{http_code}' "$B$u"); [ "$c" != 200 ] && echo "FAIL asset $f $u $c" >> $out; done; done; echo "DONE asset crawl" >> $out
# R147: the root shows the public home page; old root links carrying sign-in parameters still go to the app.
c=$(curl -s --noproxy '*' -o /dev/null -w '%{http_code} %{redirect_url}' $B/); [[ "$c" == "200 " ]] && grep -q "<title>" <(curl -s --noproxy '*' $B/) && echo "PASS / serves the home page: $c" >> $out || echo "FAIL / does not serve the home page: $c" >> $out
c=$(curl -s --noproxy '*' -o /dev/null -w '%{http_code} %{redirect_url}' "$B/?invite=x"); [[ "$c" == "302 $B/app.html?invite=x" ]] && echo "PASS /?invite= goes to the app: $c" >> $out || echo "FAIL /?invite= redirect: $c" >> $out

{ for v in 8.3 8.4; do n=0; f=0; for x in $(cd /srv/gate/www && find . -name '*.php' | sort); do n=$((n+1)); php$v -l /srv/gate/www/$x >/dev/null 2>&1 || { f=$((f+1)); echo "LINT FAIL $v $x"; }; done; echo "php$v lint: $n files, $f failures"; done; } > /srv/gate/ev/lint.txt
node --check /srv/gate/www/assets/tegh-portal-v5990.js && echo "js parse portal OK" >> /srv/gate/ev/lint.txt
grep -c PASS $out; grep -v "^PASS\|^HDR\|^DONE" $out; cat /srv/gate/ev/lint.txt

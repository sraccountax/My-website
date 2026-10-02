#!/bin/bash
# Pin the gate host to the day the hand-computed expectations were written for (2026-10-01).
# PHP-FPM, MariaDB and the test browsers are all shifted back by the same whole number of days, so the time of day and
# the order of events are unchanged. `clock-pin.sh off` removes the shift.
BASE=2026-10-01; LIB=/usr/lib/x86_64-linux-gnu/faketime/libfaketimeMT.so.1
if [ "$1" = off ]; then rm -f /etc/php/8.3/fpm/pool.d/zz-gate-clock.conf /etc/default/php-fpm8.3 /etc/default/mariadb /srv/gate/t/clock.env; service mariadb restart >/dev/null; service php8.3-fpm restart >/dev/null; echo "clock: real"; exit 0; fi
N=$(( ( $(date -u +%s) - $(date -u -d "$BASE" +%s) ) / 86400 ))
if [ "$N" -le 0 ]; then rm -f /srv/gate/t/clock.env; echo "clock: real (today is $BASE or earlier)"; exit 0; fi
printf 'export LD_PRELOAD=%s\nexport FAKETIME="-%sd"\nexport FAKETIME_DONT_FAKE_MONOTONIC=1\n' "$LIB" "$N" | tee /etc/default/php-fpm8.3 /etc/default/mariadb >/dev/null
# PHP-FPM clears its workers' environment, so the shift is also set for the pool.
printf '[www]\nenv[FAKETIME] = -%sd\nenv[FAKETIME_DONT_FAKE_MONOTONIC] = 1\n' "$N" > /etc/php/8.3/fpm/pool.d/zz-gate-clock.conf
# Test browsers are not preloaded (libfaketime hangs Chromium); they shift their own clock through Playwright.
printf 'export GATE_CLOCK_SHIFT_DAYS=%s\n' "$N" > /srv/gate/t/clock.env
service mariadb restart >/dev/null; service php8.3-fpm restart >/dev/null
echo "clock: pinned -${N}d → PHP $(php -r 'echo date("Y-m-d");' 2>/dev/null) (cli unshifted); DB $(mysql -N -e 'select utc_date()')"

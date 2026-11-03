#!/bin/bash
# Pin the gate host to the day the hand-computed expectations were written for (2026-10-01, Toronto).
# PHP-FPM and MariaDB (libfaketime) and the test browsers (Playwright clock) are all shifted back by the same number of seconds.
# the order of events are unchanged. `clock-pin.sh off` removes the shift.
BASE=2026-10-01; LIB=/usr/lib/x86_64-linux-gnu/faketime/libfaketimeMT.so.1
if [ "$1" = off ]; then rm -f /etc/php/8.3/fpm/pool.d/zz-gate-clock.conf /etc/default/php-fpm8.3 /etc/default/mariadb /srv/gate/t/clock.env; service mariadb restart >/dev/null; service php8.3-fpm restart >/dev/null; echo "clock: real"; exit 0; fi
# The expectations are for the Toronto calendar date BASE (Tegh keeps its books on Toronto time). Pin to BASE 12:00 Toronto
# so a run of up to about 11 hours never crosses Toronto midnight, whatever hour it starts (R156: a whole-day UTC shift
# made runs started between 00:00 and 04:00 UTC land on the previous Toronto day).
S=$(( $(date -u +%s) - $(TZ=America/Toronto date -d "$BASE 12:00" +%s) ))
if [ "$S" -le 0 ]; then rm -f /srv/gate/t/clock.env; echo "clock: real (now is before $BASE 12:00 Toronto)"; exit 0; fi
printf 'export LD_PRELOAD=%s\nexport FAKETIME="-%s"\nexport FAKETIME_DONT_FAKE_MONOTONIC=1\n' "$LIB" "$S" | tee /etc/default/php-fpm8.3 /etc/default/mariadb >/dev/null
# PHP-FPM clears its workers' environment, so the shift is also set for the pool.
printf '[www]\nenv[FAKETIME] = -%s\nenv[FAKETIME_DONT_FAKE_MONOTONIC] = 1\n' "$S" > /etc/php/8.3/fpm/pool.d/zz-gate-clock.conf
# Test browsers are not preloaded (libfaketime hangs Chromium); they shift their own clock through Playwright.
printf 'export GATE_CLOCK_SHIFT_SECONDS=%s\n' "$S" > /srv/gate/t/clock.env
service mariadb restart >/dev/null; service php8.3-fpm restart >/dev/null
echo "clock: pinned -${S}s → PHP $(php -r 'echo date("Y-m-d");' 2>/dev/null) (cli unshifted); DB $(mysql -N -e 'select utc_date()')"

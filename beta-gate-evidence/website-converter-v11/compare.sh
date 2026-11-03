#!/bin/bash
# Compares the website converter v10 (port 8710) and v11 (port 8711) on the Tegh R152 synthetic statements.
# Test sites: php -S 127.0.0.1:8710 -t <site with v10>; php -S 127.0.0.1:8711 -t <site with v11> (TEST-ONLY stubs from website-converter-v10).
HERE="$(cd "$(dirname "$0")" && pwd)"; cd /srv/gate/t/conv/fx152
for k in G H I J K L M N P Q R S; do
  read card rows net <<<$(python3 -c "import json;d=json.load(open('expected.json'))['$k'];print('credit_card' if d.get('card') else '-',d['rows'],'%.2f'%(d['net']/100))")
  for port in 8710 8711; do
    got=$(timeout 200 node "$HERE/ui.mjs" $port pro $card $k.pdf | python3 -c "import json,sys;r=json.load(sys.stdin);print(r['included'],r['net'].replace('\$','').replace(',',''),r['check'] and r['check']['state'])")
    echo "$k v$([ $port = 8710 ] && echo 10 || echo 11) expected $rows $net got $got"
  done
done

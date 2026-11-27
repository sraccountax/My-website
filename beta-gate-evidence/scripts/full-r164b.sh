#!/bin/bash
Z=/srv/gate/Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R164-PRIVATE-BETA.zip
bash /srv/gate/t/gate-run.sh $Z R164 > /srv/gate/gate-run.log 2>&1
cp /srv/gate/ev/run-all.log /srv/gate/run-all-r164b-full.log
cp /srv/gate/ev/summary.json /srv/gate/summary-r164b-full.json 2>/dev/null; bash /srv/gate/t/finish-run.sh $Z > /srv/gate/finish-run.log 2>&1
echo ALL-DONE >> /srv/gate/finish-run.log

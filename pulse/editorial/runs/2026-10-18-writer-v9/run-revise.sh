#!/bin/sh
# usage: run-revise.sh <post> <key> [--opinion]  -> saves a revision draft (never publishes)
post=$1; key=$2; flag=$3
scp -q inputs/$key.packets.json inputs/$key.feedback.txt root@204.168.153.231:/tmp/ttd-v9/
ssh root@204.168.153.231 "cd /opt/ttd/pulse && docker compose run --rm --no-deps -T -v /tmp/ttd-v9/pulse/app:/var/www/html/app:ro -v /tmp/ttd-v9/pulse/resources/editorial:/var/www/html/resources/editorial:ro -v /tmp/ttd-v9/$key.packets.json:/tmp/p.json:ro -v /tmp/ttd-v9/$key.feedback.txt:/tmp/fb.txt:ro --entrypoint sh app -c 'php artisan posts:revise-from-packets $post --packets=/tmp/p.json --note=\"\$(cat /tmp/fb.txt)\" --max-calls=8 --max-usd=0.30 $flag'" 2>&1 | grep -v "PHP Warning"

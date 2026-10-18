#!/bin/sh
# usage: [DRY=1] run-remote.sh <post> <key> [--opinion]
# Runs the NEW code (throwaway copy /tmp/ttd-v9, never deployed) against prod data and key.
# Writes only private preview/ledger files. Editor note comes from inputs/<key>.feedback.txt.
post=$1; key=$2; flag=$3
scp -q inputs/$key.packets.json inputs/$key.feedback.txt root@204.168.153.231:/tmp/ttd-v9/
calls=4; [ -n "$DRY" ] && calls=0
ssh root@204.168.153.231 "cd /opt/ttd/pulse && docker compose run --rm --no-deps -T -v /tmp/ttd-v9/pulse/app:/var/www/html/app:ro -v /tmp/ttd-v9/pulse/resources/editorial:/var/www/html/resources/editorial:ro -v /tmp/ttd-v9/$key.packets.json:/tmp/p.json:ro -v /tmp/ttd-v9/$key.feedback.txt:/tmp/fb.txt:ro --entrypoint sh app -c 'php artisan posts:preview-writing $post --packets=/tmp/p.json --ledger=writer-v9${DRY:+-dry} --max-total-calls=$calls --max-total-usd=0.50 --feedback=\"\$(cat /tmp/fb.txt)\" $flag'" 2>&1 | grep -v "PHP Warning"

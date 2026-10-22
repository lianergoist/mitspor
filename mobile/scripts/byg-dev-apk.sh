#!/bin/bash
# Bygger dev-udgaven af appen ("MitSpor dev", dk.mitspor.app.dev), der åbner
# dev.mitspor.dk i stedet for mitspor.dk. Den installeres ved siden af den
# rigtige app. Dev ligger bag basic auth, så telefonens IP skal være lukket ind.
#   scripts/byg-dev-apk.sh   ->  android/app/build/outputs/apk/debug/app-debug.apk
set -euo pipefail
cd "$(dirname "$0")/.."
DEV=https://dev.mitspor.dk

npx cap copy android

# Kun config.js og index.html (CSP) afviger; resten tages fra den almindelige kopi.
UD=android/app/build/mitspor-dev-assets/public
rm -rf android/app/build/mitspor-dev-assets
mkdir -p "$UD"
sed "s#siteUrl: 'https://mitspor.dk'#siteUrl: '$DEV'#" www/config.js > "$UD/config.js"
sed "s#connect-src 'self' https://mitspor.dk#connect-src 'self' $DEV#" www/index.html > "$UD/index.html"
grep -q "$DEV" "$UD/config.js" && grep -q "$DEV" "$UD/index.html" || { echo "siteUrl eller CSP blev ikke rettet" >&2; exit 1; }

cd android
./gradlew --no-daemon -q assembleDebug -PmitsporDev
ls -la app/build/outputs/apk/debug/app-debug.apk

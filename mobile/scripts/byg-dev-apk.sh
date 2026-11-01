#!/bin/bash
# Bygger dev-udgaven af appen ("MitSpor dev", dk.mitspor.app.dev), der
# installeres ved siden af den rigtige app. Den åbner som standard mitspor.dk,
# fordi dev.mitspor.dk ligger bag basic auth og svarer "Unauthorized" fra
# telefonen. Sådan peger den på dev (telefonens IP skal være lukket ind):
#   MITSPOR_SITE=https://dev.mitspor.dk scripts/byg-dev-apk.sh
#   ->  android/app/build/outputs/apk/debug/app-debug.apk
set -euo pipefail
cd "$(dirname "$0")/.."
PROD=https://mitspor.dk
SITE="${MITSPOR_SITE:-$PROD}"

npx cap copy android

# Kun config.js og index.html (CSP) afviger, når en anden side end mitspor.dk
# bruges; resten tages fra den almindelige kopi.
UD=android/app/build/mitspor-dev-assets/public
rm -rf android/app/build/mitspor-dev-assets
mkdir -p "$UD"
sed "s#siteUrl: '$PROD'#siteUrl: '$SITE'#" www/config.js > "$UD/config.js"
sed "s#connect-src 'self' $PROD#connect-src 'self' $SITE#" www/index.html > "$UD/index.html"
grep -q "siteUrl: '$SITE'" "$UD/config.js" && grep -q "connect-src 'self' $SITE" "$UD/index.html" || { echo "siteUrl eller CSP blev ikke rettet" >&2; exit 1; }
echo "Dev-appen bruger $SITE"

cd android
./gradlew --no-daemon -q assembleDebug -PmitsporDev
ls -la app/build/outputs/apk/debug/app-debug.apk

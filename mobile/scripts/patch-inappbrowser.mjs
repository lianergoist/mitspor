// Peger @capacitor/inappbrowser på vores rettede kopi af OSInAppBrowserLib
// (ios/vendor/OSInAppBrowserLib-iOS, se README.md der) i stedet for
// GitHub-pakken. Køres automatisk efter npm install/npm ci (postinstall).
//
// Stopper med en fejl, hvis pluginets Package.swift ikke ser ud som
// forventet (fx efter en opgradering), så kopien ikke stille holder op
// med at blive brugt.
import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const rod = join(dirname(fileURLToPath(import.meta.url)), '..');
const fil = join(rod, 'node_modules/@capacitor/inappbrowser/Package.swift');
const original = '.package(url: "https://github.com/OutSystems/OSInAppBrowserLib-iOS.git", exact: "2.3.2")';
const rettet = '.package(path: "../../../ios/vendor/OSInAppBrowserLib-iOS")';

if (!existsSync(join(rod, 'ios/vendor/OSInAppBrowserLib-iOS/Package.swift'))) {
    console.error('patch-inappbrowser: kopien i ios/vendor/OSInAppBrowserLib-iOS mangler.');
    process.exit(1);
}

const indhold = readFileSync(fil, 'utf8');
if (indhold.includes(rettet)) {
    console.log('patch-inappbrowser: allerede rettet.');
} else if (indhold.includes(original)) {
    writeFileSync(fil, indhold.replace(original, rettet));
    console.log('patch-inappbrowser: bruger ios/vendor/OSInAppBrowserLib-iOS.');
} else {
    console.error('patch-inappbrowser: ' + fil + ' ser ikke ud som forventet (ny version af @capacitor/inappbrowser?). Se ios/vendor/OSInAppBrowserLib-iOS/README.md.');
    process.exit(1);
}

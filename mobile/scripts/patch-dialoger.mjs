// Danske knapper i WebViewets alert/confirm/prompt-dialoger. Capacitor
// skriver "Cancel" og "Ok"/"OK" fast i koden på både Android og iOS, uanset
// telefonens sprog. Køres automatisk efter npm install/npm ci (postinstall).
//
// Stopper med en fejl, hvis filerne ikke ser ud som forventet (fx efter en
// opgradering af Capacitor), så rettelsen ikke stille forsvinder.
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const rod = join(dirname(fileURLToPath(import.meta.url)), '..');
const filer = [
    {
        fil: 'node_modules/@capacitor/android/capacitor/src/main/java/com/getcapacitor/BridgeWebChromeClient.java',
        original: '.setNegativeButton("Cancel",',
        rettet: '.setNegativeButton("Annuller",',
        antal: 2,
    },
    {
        fil: 'node_modules/@capacitor/ios/Capacitor/Capacitor/WebViewDelegationHandler.swift',
        original: 'UIAlertAction(title: "Cancel",',
        rettet: 'UIAlertAction(title: "Annuller",',
        antal: 2,
    },
    {
        fil: 'node_modules/@capacitor/ios/Capacitor/Capacitor/WebViewDelegationHandler.swift',
        original: 'UIAlertAction(title: "Ok",',
        rettet: 'UIAlertAction(title: "OK",',
        antal: 3,
    },
];

for (const { fil, original, rettet, antal } of filer) {
    const sti = join(rod, fil);
    const indhold = readFileSync(sti, 'utf8');
    const fundet = indhold.split(original).length - 1;
    const allerede = indhold.split(rettet).length - 1;
    if (fundet === antal) {
        writeFileSync(sti, indhold.split(original).join(rettet));
        console.log(`patch-dialoger: ${rettet} i ${fil}`);
    } else if (fundet === 0 && allerede === antal) {
        console.log(`patch-dialoger: allerede rettet i ${fil}`);
    } else {
        console.error(`patch-dialoger: ${fil} ser ikke ud som forventet (ny version af Capacitor?). Fandt ${fundet} af ${antal} × ${original}`);
        process.exit(1);
    }
}

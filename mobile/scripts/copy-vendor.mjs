// Kopierer Capacitor-bibliotekerne fra node_modules til www/vendor/,
// så appens lokale sider kan bruge dem uden bundler/build-trin.
// Køres af "npm run sync" (og "npm run vendor").

import { copyFileSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const vendorDir = join(root, 'www', 'vendor');

// Kilde i node_modules -> filnavn i www/vendor/
const files = {
    '@capacitor/core/dist/capacitor.js': 'capacitor.js',
    '@capacitor/inappbrowser/dist/plugin.js': 'inappbrowser.js',
    '@capacitor/network/dist/plugin.js': 'network.js',
};

mkdirSync(vendorDir, { recursive: true });

for (const [source, target] of Object.entries(files)) {
    copyFileSync(join(root, 'node_modules', source), join(vendorDir, target));
    console.log(`vendor: ${source} -> www/vendor/${target}`);
}

// Kopierer Capacitor-bibliotekerne fra node_modules til www/vendor/,
// så appens lokale sider kan bruge dem uden bundler/build-trin.
// Køres af "npm run sync" (og "npm run vendor").

import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const vendorDir = join(root, 'www', 'vendor');

// Filnavn i www/vendor/ -> kilde(r) i node_modules. Flere kilder lægges
// efter hinanden i én fil.
const files = {
    'capacitor.js': ['@capacitor/core/dist/capacitor.js'],
    'inappbrowser.js': ['@capacitor/inappbrowser/dist/plugin.js'],
    'network.js': ['@capacitor/network/dist/plugin.js'],
    'app.js': ['@capacitor/app/dist/plugin.js'],
    'share.js': ['@capacitor/share/dist/plugin.js'],
    'background-geolocation.js': ['@capgo/background-geolocation/dist/plugin.js'],
    // Filesystem bruger hjælpebiblioteket synapse via den globale variabel
    // "synapse", men synapse.js lægger sig i "outsystemsSynapse".
    'filesystem.js': [
        '@capacitor/synapse/dist/synapse.js',
        { code: 'var synapse = window.outsystemsSynapse;' },
        '@capacitor/filesystem/dist/plugin.js',
    ],
};

mkdirSync(vendorDir, { recursive: true });

for (const [target, sources] of Object.entries(files)) {
    const parts = sources.map((source) =>
        typeof source === 'string'
            ? readFileSync(join(root, 'node_modules', source), 'utf8')
            : source.code
    );
    writeFileSync(join(vendorDir, target), parts.join('\n;\n'));
    console.log(`vendor: www/vendor/${target}`);
}

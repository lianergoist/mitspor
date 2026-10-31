// Pulsmåler over Bluetooth (BLE Heart Rate Service, 0x180D), fx et pulsbælte.
//
// Brugeren vælger selv pulsmåleren i optagerens menu. Den huskes på enheden
// (kun id og navn), og der forbindes, når optageren åbnes, og når en
// optagelse starter. Ryger forbindelsen (fx uden for rækkevidde), forsøges
// der igen, så længe der optages. Under optagelsen samler optager.js
// målingerne og gemmer dem i puls.jsonl.
(function () {
    'use strict';

    var Ble = window.capacitorCommunityBluetoothLe.BleClient;
    var Capacitor = window.capacitorExports.Capacitor;

    var HR_SERVICE = '0000180d-0000-1000-8000-00805f9b34fb';
    var HR_MAALING = '00002a37-0000-1000-8000-00805f9b34fb';
    var NOEGLE = 'mitspor.pulsmaaler';
    // En måling ældre end dette vises ikke som "nu".
    var FRISK_MS = 10 * 1000;
    var GENFORBIND_MS = 15 * 1000;

    var klar = null;          // initialize() køres kun én gang
    var maaler = hentValgt(); // { id, navn } eller null
    var forbundet = false;
    var forbinder = false;
    var holdForbundet = false; // forsøg igen, hvis forbindelsen ryger
    var genTimer = null;
    var seneste = null;       // { bpm, tid }
    var lyttere = [];
    var maalingLyttere = [];

    function hentValgt() {
        try {
            var m = JSON.parse(window.localStorage.getItem(NOEGLE) || 'null');
            return m && m.id ? m : null;
        } catch (e) {
            return null;
        }
    }

    function gemValgt(m) {
        if (m) {
            window.localStorage.setItem(NOEGLE, JSON.stringify(m));
        } else {
            window.localStorage.removeItem(NOEGLE);
        }
    }

    function skift() {
        lyttere.forEach(function (fn) { fn(); });
    }

    function initialiser() {
        if (!klar) {
            klar = Ble.initialize({ androidNeverForLocation: true }).catch(function (fejl) {
                klar = null;
                throw fejl;
            });
        }
        return klar;
    }

    // Heart Rate Measurement: første byte er flag. Bit 0: pulsen er 16 bit
    // (ellers 8 bit). Bit 2 sat og bit 1 ikke sat: bæltet har ikke
    // hudkontakt, og målingen er ubrugelig.
    function laesPuls(data) {
        if (!data || data.byteLength < 2) {
            return null;
        }
        var flag = data.getUint8(0);
        if ((flag & 0x04) && !(flag & 0x02)) {
            return null;
        }
        var bpm = (flag & 0x01) ? (data.byteLength >= 3 ? data.getUint16(1, true) : 0) : data.getUint8(1);
        return bpm >= 25 && bpm <= 250 ? bpm : null;
    }

    function maaling(data) {
        var bpm = laesPuls(data);
        if (bpm === null) {
            return;
        }
        seneste = { bpm: bpm, tid: Date.now() };
        maalingLyttere.forEach(function (fn) { fn(bpm, seneste.tid); });
        skift();
    }

    function afbrudt() {
        forbundet = false;
        seneste = null;
        skift();
        planlaegGenforbind();
    }

    function planlaegGenforbind() {
        clearTimeout(genTimer);
        genTimer = null;
        if (holdForbundet && maaler) {
            genTimer = setTimeout(function () { forbind().catch(function () {}); }, GENFORBIND_MS);
        }
    }

    // Forbinder til den valgte pulsmåler og starter målingerne.
    function forbind() {
        if (!maaler || forbundet || forbinder || !Capacitor.isNativePlatform()) {
            return Promise.resolve();
        }
        var id = maaler.id;
        forbinder = true;
        skift();
        return initialiser()
            .then(function () { return Ble.connect(id, afbrudt, { timeout: 10000 }); })
            .then(function () { return Ble.startNotifications(id, HR_SERVICE, HR_MAALING, maaling); })
            .then(function () {
                forbundet = true;
            })
            .catch(function (fejl) {
                Ble.disconnect(id).catch(function () {});
                planlaegGenforbind();
                throw fejl;
            })
            .finally(function () {
                forbinder = false;
                skift();
            });
    }

    function afbryd() {
        clearTimeout(genTimer);
        genTimer = null;
        var id = maaler && maaler.id;
        var varForbundet = forbundet;
        forbundet = false;
        seneste = null;
        skift();
        if (id && varForbundet) {
            return Ble.disconnect(id).catch(function () {});
        }
        return Promise.resolve();
    }

    // Telefonens egen liste over pulsmålere i nærheden. Den valgte
    // pulsmåler erstatter en tidligere.
    function vaelg() {
        return initialiser()
            .then(function () { return Ble.requestDevice({ services: [HR_SERVICE] }); })
            .then(function (enhed) {
                return afbryd().then(function () {
                    maaler = { id: enhed.deviceId, navn: enhed.name || 'Pulsmåler' };
                    gemValgt(maaler);
                    skift();
                    return forbind();
                });
            });
    }

    function glem() {
        holdForbundet = false;
        return afbryd().then(function () {
            maaler = null;
            gemValgt(null);
            skift();
        });
    }

    window.MitSporPuls = {
        vaelg: vaelg,
        glem: glem,
        forbind: forbind,
        afbryd: afbryd,
        // Under optagelse: forbind igen, hvis forbindelsen ryger.
        hold: function (ja) {
            holdForbundet = !!ja;
            if (!holdForbundet) {
                clearTimeout(genTimer);
                genTimer = null;
            }
        },
        maaler: function () { return maaler; },
        forbundet: function () { return forbundet; },
        forbinder: function () { return forbinder; },
        // Seneste puls (bpm), hvis den er frisk, ellers null.
        nu: function () {
            return seneste && Date.now() - seneste.tid <= FRISK_MS ? seneste.bpm : null;
        },
        onSkift: function (fn) { lyttere.push(fn); },
        // fn(bpm, tid) for hver brugbar måling.
        onMaaling: function (fn) { maalingLyttere.push(fn); }
    };
})();

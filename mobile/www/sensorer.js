// Sensorer over Bluetooth (BLE): pulsmåler og kadence-/wattmåler.
//
// To slags måler kan vælges, hver for sig:
//   'puls'  – pulsmåler (Heart Rate, 0x180D), fx et pulsbælte.
//   'kraft' – kadence og watt: wattmåler (Cycling Power, 0x1818),
//             kadencesensor (Cycling Speed and Cadence, 0x1816) eller
//             skridtmåler til løb (Running Speed and Cadence, 0x1814).
//
// Brugeren vælger måleren i optagerens menu, hvor appen selv søger efter
// målere i nærheden (soeg). Den valgte huskes på enheden (kun id, navn og
// om den kan måle watt). Der forbindes, når optageren åbnes, og når en
// optagelse starter. Ryger forbindelsen (fx uden for rækkevidde), forsøges
// der igen, så længe der optages. Under optagelsen samler optager.js
// målingerne og gemmer dem i turen.
//
// Målingerne sendes som (type, værdi, tid), hvor type er 'hr' (slag/min),
// 'cad' (kadence) eller 'power' (watt) – samme navne som i GPX-filen.
// Kadence fra cykel er omdrejninger/min. Løbekadence (skridt/min) halveres
// som hos Garmin, så den passer med <gpxtpx:cad> i andre programmer.
(function () {
    'use strict';

    var Ble = window.capacitorCommunityBluetoothLe.BleClient;
    var Capacitor = window.capacitorExports.Capacitor;

    function uuid(kort) {
        return '0000' + kort + '-0000-1000-8000-00805f9b34fb';
    }

    var HR = { service: uuid('180d'), maaling: uuid('2a37') };
    var CPS = { service: uuid('1818'), maaling: uuid('2a63') };
    var CSC = { service: uuid('1816'), maaling: uuid('2a5b') };
    var RSC = { service: uuid('1814'), maaling: uuid('2a53') };

    var SLAGS = {
        puls: { noegle: 'mitspor.pulsmaaler', tjenester: [HR] },
        kraft: { noegle: 'mitspor.kraftmaaler', tjenester: [CPS, CSC, RSC] }
    };

    // En måling ældre end dette vises ikke som "nu".
    var FRISK_MS = 10 * 1000;
    var GENFORBIND_MS = 15 * 1000;
    // Kommer der ingen ny omdrejning i så lang tid, er kadencen 0.
    var KADENCE_STOP_MS = 3000;
    var SOEG_MS = 20 * 1000;

    var klar = null;          // initialize() køres kun én gang
    var valgt = { puls: hentValgt('puls'), kraft: hentValgt('kraft') };
    var aktive = [];          // de slags, der skal være forbundet nu
    // Forbindelser pr. enheds-id: { status: 'forbinder'|'forbundet', slags: [...] }.
    // Er pulsmåleren og kadencemåleren samme enhed, deles forbindelsen.
    var forb = {};
    var holdForbundet = false; // forsøg igen, hvis forbindelsen ryger
    var genTimer = null;
    var seneste = {};          // type -> { v, tid }
    var krank = {};            // enheds-id + tjeneste -> forrige krankdata
    var soeger = false;
    var soegTimer = null;
    var soegSlut = null;
    var lyttere = [];
    var maalingLyttere = [];

    function hentValgt(slags) {
        try {
            var m = JSON.parse(window.localStorage.getItem(SLAGS[slags].noegle) || 'null');
            return m && m.id ? m : null;
        } catch (e) {
            return null;
        }
    }

    function gemValgt(slags) {
        if (valgt[slags]) {
            window.localStorage.setItem(SLAGS[slags].noegle, JSON.stringify(valgt[slags]));
        } else {
            window.localStorage.removeItem(SLAGS[slags].noegle);
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

    function send(type, v) {
        var tid = Date.now();
        seneste[type] = { v: v, tid: tid };
        maalingLyttere.forEach(function (fn) { fn(type, v, tid); });
        skift();
    }

    // ---------- Aflæsning af målingerne ----------

    // Heart Rate Measurement: første byte er flag. Bit 0: pulsen er 16 bit
    // (ellers 8 bit). Bit 2 sat og bit 1 ikke sat: bæltet har ikke
    // hudkontakt, og målingen er ubrugelig.
    function laesPuls(data) {
        if (!data || data.byteLength < 2) {
            return;
        }
        var flag = data.getUint8(0);
        if ((flag & 0x04) && !(flag & 0x02)) {
            return;
        }
        var bpm = (flag & 0x01) ? (data.byteLength >= 3 ? data.getUint16(1, true) : 0) : data.getUint8(1);
        if (bpm >= 25 && bpm <= 250) {
            send('hr', bpm);
        }
    }

    // Kadence ud fra krankens samlede omdrejninger og tidspunktet for den
    // seneste (1/1024 s). Begge tæller rundt ved 65536.
    function laesKrank(noegle, data, off) {
        if (data.byteLength < off + 4) {
            return;
        }
        var omdr = data.getUint16(off, true);
        var tid = data.getUint16(off + 2, true);
        var nu = Date.now();
        var f = krank[noegle];
        krank[noegle] = { omdr: omdr, tid: tid, aendret: f ? f.aendret : nu };
        if (!f) {
            return;
        }
        var dOmdr = (omdr - f.omdr) & 0xffff;
        var dTid = ((tid - f.tid) & 0xffff) / 1024;
        if (dOmdr > 0 && dTid > 0) {
            krank[noegle].aendret = nu;
            var rpm = Math.round(dOmdr / dTid * 60);
            if (rpm <= 250) {
                send('cad', rpm);
            }
        } else if (nu - f.aendret >= KADENCE_STOP_MS) {
            send('cad', 0);
        }
    }

    // Cycling Power Measurement: 16 bit flag og effekten (watt, med fortegn).
    // Derefter valgfrie felter; krankdata (bit 5) giver kadencen.
    function laesWatt(id, data) {
        if (!data || data.byteLength < 4) {
            return;
        }
        var flag = data.getUint16(0, true);
        var watt = data.getInt16(2, true);
        if (watt >= 0 && watt <= 3000) {
            send('power', watt);
        }
        var off = 4;
        if (flag & 0x01) { off += 1; } // pedalbalance
        if (flag & 0x04) { off += 2; } // samlet moment
        if (flag & 0x10) { off += 6; } // hjuldata
        if (flag & 0x20) {
            laesKrank(id + 'cps', data, off);
        }
    }

    // Cycling Speed and Cadence: flag, evt. hjuldata (6 byte), evt. krankdata.
    function laesCsc(id, data) {
        if (!data || data.byteLength < 1) {
            return;
        }
        var flag = data.getUint8(0);
        if (flag & 0x02) {
            laesKrank(id + 'csc', data, (flag & 0x01) ? 7 : 1);
        }
    }

    // Running Speed and Cadence: flag, fart (2 byte), kadence (skridt/min).
    function laesRsc(data) {
        if (!data || data.byteLength < 4) {
            return;
        }
        var spm = data.getUint8(3);
        if (spm <= 250) {
            send('cad', Math.round(spm / 2));
        }
    }

    // ---------- Forbindelse ----------

    function idSlags(id) {
        return aktive.filter(function (s) { return valgt[s] && valgt[s].id === id; });
    }

    // De enheder, der skal være forbundet: id -> de slags, de bruges til.
    function oenskede() {
        var ids = {};
        aktive.forEach(function (s) {
            if (valgt[s]) {
                ids[valgt[s].id] = idSlags(valgt[s].id);
            }
        });
        return ids;
    }

    function planlaegGenforbind() {
        clearTimeout(genTimer);
        genTimer = null;
        if (holdForbundet && Object.keys(oenskede()).length) {
            genTimer = setTimeout(function () { forbindAlle().catch(function () {}); }, GENFORBIND_MS);
        }
    }

    function afbrudt(id) {
        delete forb[id];
        skift();
        planlaegGenforbind();
    }

    // Starter de målinger, enheden har, for de slags den bruges til.
    function startMaalinger(id, slags) {
        return Ble.getServices(id).then(function (tjenester) {
            var har = {};
            tjenester.forEach(function (t) { har[t.uuid.toLowerCase()] = true; });
            var opg = [];
            function lyt(tj, fn) {
                if (har[tj.service]) {
                    opg.push(Ble.startNotifications(id, tj.service, tj.maaling, fn));
                }
            }
            if (slags.indexOf('puls') >= 0) {
                lyt(HR, laesPuls);
            }
            if (slags.indexOf('kraft') >= 0) {
                // Annoncerede måleren ikke wattmåling, men har den, så husk det.
                if (har[CPS.service] && valgt.kraft && valgt.kraft.id === id && !valgt.kraft.watt) {
                    valgt.kraft.watt = true;
                    gemValgt('kraft');
                }
                lyt(CPS, function (d) { laesWatt(id, d); });
                // Kadence fra CSC kun, hvis wattmåleren ikke selv giver den.
                if (!har[CPS.service]) {
                    lyt(CSC, function (d) { laesCsc(id, d); });
                    lyt(RSC, laesRsc);
                }
            }
            if (!opg.length) {
                throw new Error('Måleren sender ikke de forventede data.');
            }
            return Promise.all(opg);
        });
    }

    function forbindEn(id, slags) {
        forb[id] = { status: 'forbinder', slags: slags };
        skift();
        return initialiser()
            .then(function () {
                // iOS kender kun enheder, der er fundet i denne kørsel af appen.
                if (Capacitor.getPlatform() === 'ios') {
                    return Ble.getDevices([id]).catch(function () {});
                }
            })
            .then(function () { return Ble.connect(id, function () { afbrudt(id); }, { timeout: 10000 }); })
            .then(function () { return startMaalinger(id, slags); })
            .then(function () {
                if (forb[id]) {
                    forb[id].status = 'forbundet';
                }
            })
            .catch(function (fejl) {
                delete forb[id];
                Ble.disconnect(id).catch(function () {});
                planlaegGenforbind();
                throw fejl;
            })
            .finally(skift);
    }

    // Forbinder de aktive slags' målere (dem, der ikke allerede er forbundet).
    function forbindAlle() {
        if (!Capacitor.isNativePlatform() || soeger) {
            return Promise.resolve();
        }
        var ids = oenskede();
        var opg = Object.keys(ids).filter(function (id) { return !forb[id]; }).map(function (id) {
            return forbindEn(id, ids[id]);
        });
        return Promise.all(opg);
    }

    function afbrydId(id) {
        var f = forb[id];
        delete forb[id];
        if (f) {
            return Ble.disconnect(id).catch(function () {});
        }
        return Promise.resolve();
    }

    // Afbryder enheder, der ikke længere skal bruges.
    function ryd() {
        var ids = oenskede();
        return Promise.all(Object.keys(forb).filter(function (id) { return !ids[id]; }).map(afbrydId));
    }

    // Vælger hvilke slags der skal være forbundet (fx ['puls', 'kraft']),
    // og forbinder eller afbryder efter behov.
    function brug(liste) {
        aktive = (liste || []).filter(function (s) { return !!SLAGS[s]; });
        return ryd().then(function () {
            skift();
            return forbindAlle();
        });
    }

    function afbryd() {
        clearTimeout(genTimer);
        genTimer = null;
        aktive = [];
        seneste = {};
        krank = {};
        var opg = Object.keys(forb).map(afbrydId);
        skift();
        return Promise.all(opg);
    }

    // ---------- Søgning ----------

    function stopSoeg() {
        clearTimeout(soegTimer);
        soegTimer = null;
        if (!soeger) {
            return Promise.resolve();
        }
        soeger = false;
        var slut = soegSlut;
        soegSlut = null;
        return Ble.stopLEScan().catch(function () {}).then(function () {
            if (slut) {
                slut();
            }
            skift();
            // Forbindelser venter, mens der søges.
            return forbindAlle().catch(function () {});
        });
    }

    // Søger efter målere af en slags i nærheden. fundet(enhed) kaldes for
    // hver ny: { id, navn, watt }. Svaret kommer, når søgningen er slut
    // (efter SOEG_MS, eller når stopSoeg() kaldes). En måler, der allerede
    // er forbundet, sender ikke og bliver ikke fundet.
    function soeg(slags, fundet) {
        var tjenester = SLAGS[slags].tjenester;
        var set = {};
        return stopSoeg()
            .then(initialiser)
            .then(function () { return Ble.isEnabled(); })
            .then(function (taendt) {
                if (!taendt) {
                    var fejl = new Error('Bluetooth er slået fra.');
                    fejl.slukket = true;
                    throw fejl;
                }
                soeger = true;
                skift();
                return new Promise(function (resolve, reject) {
                    soegSlut = resolve;
                    Ble.requestLEScan({ services: tjenester.map(function (t) { return t.service; }) }, function (r) {
                        var id = r.device && r.device.deviceId;
                        if (!id || set[id] || !soeger) {
                            return;
                        }
                        set[id] = true;
                        var uuids = (r.uuids || []).map(function (u) { return u.toLowerCase(); });
                        fundet({
                            id: id,
                            navn: r.localName || r.device.name || 'Ukendt måler',
                            watt: uuids.indexOf(CPS.service) >= 0
                        });
                    }).then(function () {
                        soegTimer = setTimeout(stopSoeg, SOEG_MS);
                    }, function (fejl) {
                        soeger = false;
                        soegSlut = null;
                        skift();
                        reject(fejl);
                    });
                });
            });
    }

    // Gør enheden til den valgte måler af slagsen og forbinder, hvis
    // slagsen er aktiv.
    function vaelg(slags, enhed) {
        var gammel = valgt[slags];
        valgt[slags] = { id: enhed.id, navn: enhed.navn, watt: !!enhed.watt };
        gemValgt(slags);
        var opg = gammel && gammel.id !== enhed.id ? ryd() : Promise.resolve();
        return opg.then(function () {
            skift();
            return forbindAlle();
        });
    }

    function glem(slags) {
        valgt[slags] = null;
        gemValgt(slags);
        return ryd().then(skift);
    }

    function statusFor(slags) {
        var m = valgt[slags];
        var f = m && forb[m.id];
        return f && f.slags.indexOf(slags) >= 0 ? f.status : null;
    }

    window.MitSporSensorer = {
        brug: brug,
        soeg: soeg,
        stopSoeg: stopSoeg,
        vaelg: vaelg,
        glem: glem,
        afbryd: afbryd,
        forbind: function () { return forbindAlle(); },
        // Under optagelse: forbind igen, hvis forbindelsen ryger.
        hold: function (ja) {
            holdForbundet = !!ja;
            if (!holdForbundet) {
                clearTimeout(genTimer);
                genTimer = null;
            }
        },
        maaler: function (slags) { return valgt[slags]; },
        forbundet: function (slags) { return statusFor(slags) === 'forbundet'; },
        forbinder: function (slags) { return statusFor(slags) === 'forbinder'; },
        soeger: function () { return soeger; },
        // Seneste værdi af typen ('hr', 'cad', 'power'), hvis den er frisk, ellers null.
        nu: function (type) {
            var s = seneste[type];
            return s && Date.now() - s.tid <= FRISK_MS ? s.v : null;
        },
        onSkift: function (fn) { lyttere.push(fn); },
        // fn(type, værdi, tid) for hver brugbar måling.
        onMaaling: function (fn) { maalingLyttere.push(fn); }
    };
})();

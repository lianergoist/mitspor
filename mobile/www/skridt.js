// Telefonens skridttæller, til kadence i optagelsen.
//
// Android: appens eget plugin (SkridtPlugin.java), der også tæller, når
// skærmen er slukket. iOS: @capgo/capacitor-pedometer (CMPedometer).
// Begge giver løbende et samlet antal skridt; her bliver det til skridt
// siden start(). fn(tid, skridt) kaldes for hver måling.
(function () {
    'use strict';

    var cap = window.capacitorExports;
    var platform = cap.Capacitor.getPlatform();
    var Android = platform === 'android' ? cap.registerPlugin('MitSporSkridt') : null;
    var Ios = platform === 'ios' ? cap.registerPlugin('CapacitorPedometer') : null;

    var lytter = null;   // PluginListenerHandle
    var base = null;     // tællerens værdi ved første måling (Android)
    var lyttere = [];
    var findesSvar = null;

    function send(tid, skridt) {
        lyttere.forEach(function (fn) { fn(tid, skridt); });
    }

    // Har telefonen en skridttæller? (Svaret huskes.)
    function findes() {
        if (!findesSvar) {
            if (Android) {
                findesSvar = Android.findes().then(function (s) { return !!s.findes; });
            } else if (Ios) {
                findesSvar = Ios.isAvailable().then(function (s) { return !!s.stepCounting; });
            } else {
                findesSvar = Promise.resolve(false);
            }
            findesSvar = findesSvar.catch(function () { return false; });
        }
        return findesSvar;
    }

    // Beder om adgang ("Fysisk aktivitet" / "Bevægelse og fitness").
    // Svaret er true, hvis adgangen er givet.
    function bedOmTilladelse() {
        if (Android) {
            return Android.bedOmTilladelse().then(function (s) { return !!s.tilladt; });
        }
        if (Ios) {
            return Ios.requestPermissions().then(function (s) { return s.activityRecognition === 'granted'; });
        }
        return Promise.resolve(false);
    }

    function start() {
        return stop().then(function () {
            base = null;
            if (Android) {
                return Android.addListener('skridt', function (m) {
                    // Den første måling er nulpunktet.
                    if (base === null || m.antal < base) {
                        base = m.antal;
                    }
                    send(m.tid, m.antal - base);
                }).then(function (h) {
                    lytter = h;
                    return Android.start();
                });
            }
            if (Ios) {
                // Skridt siden start; tiden er, da målingen kom.
                return Ios.addListener('measurement', function (m) {
                    send(m.endDate || Date.now(), m.numberOfSteps || 0);
                }).then(function (h) {
                    lytter = h;
                    return Ios.startMeasurementUpdates();
                });
            }
            throw new Error('Skridttælleren findes kun i appen.');
        });
    }

    function stop() {
        var h = lytter;
        lytter = null;
        if (!h) {
            return Promise.resolve();
        }
        var opg = Android ? Android.stop() : Ios.stopMeasurementUpdates();
        return Promise.all([h.remove(), opg]).catch(function () {});
    }

    window.MitSporSkridt = {
        findes: findes,
        bedOmTilladelse: bedOmTilladelse,
        start: start,
        stop: stop,
        onMaaling: function (fn) { lyttere.push(fn); }
    };
})();

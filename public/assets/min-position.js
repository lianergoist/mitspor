// "Vis min position" på rutens side (route-view.php): en knap over
// zoomknapperne. Første tryk viser brugerens position som en blå prik med en
// cirkel for usikkerheden og flytter kortet derhen. Prikken følger med, mens
// man går, men kortet trækkes ikke med, så man frit kan kigge rundt.
// Et tryk mere slukker. Placeringen bliver i browseren og sendes ikke til
// serveren.
//   MitSporMinPosition.tilfoej(map)
(function () {
    "use strict";

    function tilfoej(map) {
        if (!navigator.geolocation || !window.L) return;

        var aktiv = false;
        var foersteGang = true;
        var prik = null;
        var cirkel = null;
        var knap = null;

        var Kontrol = L.Control.extend({
            options: { position: 'bottomright' },
            onAdd: function () {
                var boks = L.DomUtil.create('div', 'leaflet-bar min-position');
                knap = L.DomUtil.create('a', '', boks);
                knap.href = '#';
                knap.setAttribute('role', 'button');
                knap.title = 'Vis min position';
                knap.setAttribute('aria-label', 'Vis min position');
                knap.setAttribute('aria-pressed', 'false');
                knap.innerHTML = '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">'
                    + '<g fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round">'
                    + '<circle cx="12" cy="12" r="7"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/></g>'
                    + '<circle cx="12" cy="12" r="2.6" fill="currentColor"/></svg>';
                L.DomEvent.disableClickPropagation(boks);
                L.DomEvent.on(knap, 'click', function (ev) {
                    L.DomEvent.preventDefault(ev);
                    if (aktiv) {
                        sluk();
                    } else {
                        taend();
                    }
                });
                return boks;
            }
        });
        map.addControl(new Kontrol());

        function saetAktiv(v) {
            aktiv = v;
            knap.classList.toggle('min-position-aktiv', v);
            knap.setAttribute('aria-pressed', v ? 'true' : 'false');
            knap.title = v ? 'Skjul min position' : 'Vis min position';
        }

        function taend() {
            foersteGang = true;
            saetAktiv(true);
            map.locate({ watch: true, enableHighAccuracy: true, maximumAge: 10000, timeout: 20000 });
        }

        function sluk() {
            map.stopLocate();
            if (prik) { map.removeLayer(prik); prik = null; }
            if (cirkel) { map.removeLayer(cirkel); cirkel = null; }
            saetAktiv(false);
        }

        map.on('locationfound', function (e) {
            if (!aktiv) return;
            if (!prik) {
                cirkel = L.circle(e.latlng, { radius: e.accuracy, color: '#2563eb', weight: 1, fillColor: '#2563eb', fillOpacity: 0.12, interactive: false }).addTo(map);
                prik = L.circleMarker(e.latlng, { radius: 8, color: '#ffffff', weight: 3, fillColor: '#2563eb', fillOpacity: 1, interactive: false }).addTo(map);
            } else {
                prik.setLatLng(e.latlng);
                cirkel.setLatLng(e.latlng).setRadius(e.accuracy);
            }
            if (foersteGang) {
                foersteGang = false;
                map.setView(e.latlng, Math.max(map.getZoom(), 15));
            }
        });

        map.on('locationerror', function (e) {
            if (!aktiv) return;
            // Kom der en placering før, er det bare et enkelt mislykket forsøg.
            if (prik && e.code !== 1) return;
            sluk();
            window.alert(e.code === 1
                ? 'MitSpor må ikke se din placering. Giv lov i telefonens eller browserens indstillinger, og prøv igen.'
                : 'Din placering kunne ikke findes lige nu. Gå udendørs, og prøv igen.');
        });
    }

    window.MitSporMinPosition = { tilfoej: tilfoej };
})();

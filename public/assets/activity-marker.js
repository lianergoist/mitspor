/* ------------------------------------------------------------------
   ActivityMarker — Leaflet-ikoner til aktivitets-markørerne
   (public/markers/<aktivitet>/..., se app/markers.php).

   Ankeret (det punkt i billedet, der står PRÆCIS på ruten) beregnes ud fra
   billedets egen gennemsigtighed: når billedet er indlæst, finder scriptet
   det synlige motivs ramme og sætter ankeret i midten af motivets bund —
   ca. 1 px over underkanten, så "foden" står på linjen. Det virker uanset
   hvor tæt billederne er beskåret, og hvor meget luft de har rundt om
   motivet, så et nyt eller beskåret billede kan lægges i mappen uden at
   koden skal ændres.

   Billedets længste side vises altid i BASE × SCALE px (standard 120 × 0,5
   = 60 px), uanset billedets egen størrelse. Et 120 px-billede er dermed
   skarpt på retina-skærme, og et større billede skaleres ned.

     ActivityMarker.icon('markers/sup/standard_east.png').then(function (icon) {
         L.marker(latlng, { icon: icon }).addTo(map);
     });
     ActivityMarker.icon(url, { scale: 0.4 })   // mindre udgave (mini-kortet)
     ActivityMarker.icon(url, { mirror: true }) // spejlvendt (enkelt billede mod vest)
------------------------------------------------------------------- */
window.ActivityMarker = (function () {
    "use strict";

    var BASE = 120;      // billedets længste side regnes som BASE px
    var SCALE = 0.5;     // vist størrelse = BASE × SCALE (60 px)
    var ALPHA_MIN = 24;  // en pixel med alfa under dette (0–255) regnes for gennemsigtig
    var FOOT_LIFT = 1;   // px over motivets underkant, hvor ankeret sidder
    var cache = {};

    // Finder rammen om de synlige pixels. right/bottom er "udenfor"-koordinater
    // (første gennemsigtige kolonne/række), så bredde = right - left.
    function bounds(data, w, h) {
        var left = w, right = -1, bottom = -1;
        for (var y = 0; y < h; y++) {
            for (var x = 0; x < w; x++) {
                if (data[(y * w + x) * 4 + 3] >= ALPHA_MIN) {
                    if (x < left) left = x;
                    if (x > right) right = x;
                    if (y > bottom) bottom = y;
                }
            }
        }
        if (right < 0) return null; // helt gennemsigtigt billede
        return { left: left, right: right + 1, bottom: bottom + 1 };
    }

    // Indlæser billedet én gang pr. adresse og måler det.
    function measure(url) {
        if (cache[url]) return cache[url];

        cache[url] = new Promise(function (resolve, reject) {
            var img = new window.Image();

            img.onload = function () {
                var w = img.naturalWidth;
                var h = img.naturalHeight;
                if (!w || !h) {
                    reject(new Error("Markørbilledet er tomt: " + url));
                    return;
                }

                // Kan pixlerne ikke læses (fx hvis billedet kom fra et andet domæne),
                // bruges hele billedet som ramme: ankeret sættes i bunden af billedet.
                var box = { left: 0, right: w, bottom: h };
                try {
                    var canvas = document.createElement("canvas");
                    canvas.width = w;
                    canvas.height = h;
                    var ctx = canvas.getContext("2d");
                    ctx.drawImage(img, 0, 0);
                    box = bounds(ctx.getImageData(0, 0, w, h).data, w, h) || box;
                } catch (e) {
                    /* behold hele billedet som ramme */
                }

                // Spejlvendt udgave som data-adresse, til markører med ét billede,
                // der vender mod øst (se marker_image_urls() i app/markers.php).
                var mirrored = null;
                try {
                    var flip = document.createElement("canvas");
                    flip.width = w;
                    flip.height = h;
                    var fctx = flip.getContext("2d");
                    fctx.translate(w, 0);
                    fctx.scale(-1, 1);
                    fctx.drawImage(img, 0, 0);
                    mirrored = flip.toDataURL("image/png");
                } catch (e) {
                    /* ingen spejlvendt udgave */
                }

                resolve({ w: w, h: h, left: box.left, right: box.right, bottom: box.bottom, mirrored: mirrored });
            };

            img.onerror = function () {
                reject(new Error("Markørbilledet kunne ikke indlæses: " + url));
            };

            img.src = url;
        });

        return cache[url];
    }

    function icon(url, options) {
        var scale = (options && options.scale) || SCALE;

        var mirror = !!(options && options.mirror);

        return measure(url).then(function (m) {
            // Omregnes fra billedets egne pixels til BASE, så store billeder skaleres ned.
            var factor = scale * BASE / Math.max(m.w, m.h);
            // Spejlvendt flyttes motivets midte tilsvarende: x -> w - x.
            var centerX = (m.left + m.right) / 2;
            if (mirror) {
                if (!m.mirrored) throw new Error("Markørbilledet kunne ikke spejlvendes: " + url);
                centerX = m.w - centerX;
            }
            return window.L.icon({
                iconUrl: mirror ? m.mirrored : url,
                iconSize: [Math.round(m.w * factor), Math.round(m.h * factor)],
                iconAnchor: [
                    Math.round(centerX * factor),
                    Math.max(0, Math.round(m.bottom * factor) - FOOT_LIFT)
                ]
            });
        });
    }

    return { icon: icon, SCALE: SCALE };
})();

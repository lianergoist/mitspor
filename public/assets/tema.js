/*
 * Lyst/mørkt tema. Indlæses synkront i <head> før CSS'en, så en mørk side
 * ikke blinker hvid ved indlæsning.
 *
 * Som standard følger temaet enhedens indstilling (prefers-color-scheme), også
 * når den skifter, mens siden er åben. Vælger man selv med menupunktet, gemmes
 * valget i localStorage (kun en visningsindstilling, intet personligt). Vælger
 * man det samme, som enheden står til, slettes valget igen, så siden igen
 * følger enheden.
 *
 * Mørkt tema sættes som data-tema="moerk" på <html>; CSS'en skifter farver
 * med html[data-tema="moerk"]. Lyst tema har ingen attribut.
 *
 * Menupunktet med data-tema-skift (topbar_menu(), editorens menu og
 * vejledningens topbar) skifter tema; dets tekst opdateres her, så den altid
 * passer til det aktuelle tema.
 */
(function () {
    var NOEGLE = 'mitspor-tema';
    var root = document.documentElement;
    var system = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

    function gemt() {
        try { return localStorage.getItem(NOEGLE); } catch (e) { return null; }
    }

    function systemMoerk() {
        return !!(system && system.matches);
    }

    // Eget valg, ellers enhedens indstilling.
    function oensketMoerk() {
        var v = gemt();
        if (v === 'moerk') return true;
        if (v === 'lys') return false;
        return systemMoerk();
    }

    function erMoerk() {
        return root.getAttribute('data-tema') === 'moerk';
    }

    function opdaterKnapper() {
        var tekst = erMoerk() ? 'Lyst tema' : 'Mørkt tema';
        document.querySelectorAll('[data-tema-skift]').forEach(function (knap) {
            var label = knap.querySelector('[data-tema-label]') || knap;
            label.textContent = tekst;
        });
    }

    function saet(moerk) {
        var foer = erMoerk();
        if (moerk) root.setAttribute('data-tema', 'moerk');
        else root.removeAttribute('data-tema');
        opdaterKnapper();
        if (foer !== moerk) {
            document.dispatchEvent(new CustomEvent('mitspor-tema', { detail: { moerk: moerk } }));
        }
    }

    function skift() {
        var moerk = !erMoerk();
        try {
            if (moerk === systemMoerk()) localStorage.removeItem(NOEGLE);
            else localStorage.setItem(NOEGLE, moerk ? 'moerk' : 'lys');
        } catch (e) { /* privat tilstand: valget gælder kun denne side */ }
        saet(moerk);
    }

    if (oensketMoerk()) root.setAttribute('data-tema', 'moerk');

    window.mitsporTema = { erMoerk: erMoerk, skift: skift };

    // Enheden skifter (fx automatisk mørkt om aftenen): følg med, medmindre
    // man selv har valgt.
    if (system) {
        var vedSystemSkift = function () { saet(oensketMoerk()); };
        if (system.addEventListener) system.addEventListener('change', vedSystemSkift);
        else if (system.addListener) system.addListener(vedSystemSkift); // ældre Safari
    }

    // Skift i en anden fane slår også igennem her.
    window.addEventListener('storage', function (e) {
        if (e.key === NOEGLE || e.key === null) saet(oensketMoerk());
    });

    document.addEventListener('click', function (e) {
        var knap = e.target.closest && e.target.closest('[data-tema-skift]');
        if (knap) skift();
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', opdaterKnapper);
    } else {
        opdaterKnapper();
    }
})();

/*
 * Lyst/mørkt tema. Indlæses synkront i <head> før CSS'en, så en mørk side
 * ikke blinker hvid ved indlæsning. Valget gemmes i localStorage (kun en
 * visningsindstilling, intet personligt) og gælder for hele sitet.
 *
 * Mørkt tema sættes som data-tema="moerk" på <html>; CSS'en skifter farver
 * med html[data-tema="moerk"]. Lyst tema er standard (ingen attribut).
 *
 * Menupunktet med data-tema-skift (topbar_menu() og editorens menu) skifter
 * tema; dets tekst opdateres her, så den altid passer til det aktuelle tema.
 */
(function () {
    var NOEGLE = 'mitspor-tema';
    var root = document.documentElement;

    function gemt() {
        try { return localStorage.getItem(NOEGLE); } catch (e) { return null; }
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
        if (moerk) root.setAttribute('data-tema', 'moerk');
        else root.removeAttribute('data-tema');
        opdaterKnapper();
        document.dispatchEvent(new CustomEvent('mitspor-tema', { detail: { moerk: moerk } }));
    }

    function skift() {
        var moerk = !erMoerk();
        try { localStorage.setItem(NOEGLE, moerk ? 'moerk' : 'lys'); } catch (e) { /* privat tilstand */ }
        saet(moerk);
    }

    if (gemt() === 'moerk') root.setAttribute('data-tema', 'moerk');

    window.mitsporTema = { erMoerk: erMoerk, skift: skift };

    // Skift i en anden fane slår også igennem her.
    window.addEventListener('storage', function (e) {
        if (e.key === NOEGLE) saet(e.newValue === 'moerk');
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

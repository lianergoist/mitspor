// /app-login.php: sender engangskoden fra adressen (efter #) til serveren
// og går derefter videre til den side, appen bad om — også hvis koden
// ikke virkede (så er brugeren bare ikke logget ind).
(function () {
    'use strict';

    var params = new URLSearchParams(window.location.hash.slice(1));
    var til = params.get('til') || '/';

    // Fjern koden fra adressen, så den ikke ligger i historikken.
    history.replaceState(null, '', window.location.pathname);

    var data = new URLSearchParams(new FormData(document.getElementById('app-login')));
    data.append('kode', params.get('kode') || '');
    data.append('til', til);

    fetch('/app-login.php', { method: 'POST', body: data, credentials: 'same-origin' })
        .then(function (res) { return res.json(); })
        .then(function (svar) { window.location.replace(svar.til || '/'); })
        .catch(function () { window.location.replace('/'); });
})();

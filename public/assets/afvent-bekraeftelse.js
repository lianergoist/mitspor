/**
 * Siden "Tjek din indbakke" i appen (register.php): spørger hvert 4. sekund,
 * og når brugeren vender tilbage til appen, om e-mailen er bekræftet. Er den
 * det, er sessionen logget ind, og siden går til kontrolpanelet. Appen ser
 * sideskiftet og logger selv ind (se app/afvent_bekraeftelse.php).
 */
(function () {
    "use strict";

    var boks = document.getElementById('afvent-bekraeftelse');
    if (!boks) {
        return;
    }

    var csrf = boks.getAttribute('data-csrf');
    var status = document.getElementById('afvent-status');
    var timer = null;
    var spoerger = false;
    var faerdig = false;

    function spoerg() {
        if (spoerger || faerdig) {
            return;
        }
        spoerger = true;

        fetch('/register-status.php', {
            method: 'POST',
            body: new URLSearchParams({ csrf_token: csrf })
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.status === 'logget_ind') {
                    faerdig = true;
                    status.textContent = 'Din e-mail er bekræftet. Du logges ind …';
                    window.location.href = '/dashboard.php';
                } else if (data.status !== 'venter') {
                    // Intet at vente på længere: brugeren må logge ind selv.
                    faerdig = true;
                    status.textContent = 'Gå tilbage til appen, og log ind med din e-mail og adgangskode.';
                }
            })
            .catch(function () {})
            .finally(function () { spoerger = false; });
    }

    timer = window.setInterval(function () {
        if (faerdig) {
            window.clearInterval(timer);
            return;
        }
        if (!document.hidden) {
            spoerg();
        }
    }, 4000);

    // Når brugeren skifter tilbage fra mailen/browseren.
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            spoerg();
        }
    });
    window.addEventListener('focus', spoerg);
})();

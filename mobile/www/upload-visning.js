// Skærmene til login og upload: log ind, vælg titel og aktivitet for en
// tur og sæt den i kø (upload.js sender den). Linjen "Logget ind som …"
// i optageren styres også herfra.
(function () {
    'use strict';

    var Konto = window.MitSporKonto;
    var Upload = window.MitSporUpload;
    var MitSporApp = window.MitSporApp;

    var loginForm = document.getElementById('login-form');
    var loginEmail = document.getElementById('login-email');
    var loginKode = document.getElementById('login-kode');
    var loginKnap = document.getElementById('login-knap');
    var loginFejl = document.getElementById('login-fejl');
    var uploadForm = document.getElementById('upload-form');
    var uploadTur = document.getElementById('upload-tur');
    var uploadTitel = document.getElementById('upload-titel');
    var uploadAktivitet = document.getElementById('upload-aktivitet');
    var uploadKnap = document.getElementById('upload-knap');
    var uploadFejl = document.getElementById('upload-fejl');
    var kontoTekst = document.getElementById('optag-konto-tekst');
    var kontoKnap = document.getElementById('optag-konto-knap');

    var tur = null;   // turen, der skal uploades (også mens der logges ind)
    var login = null;

    function visFejl(felt, tekst) {
        felt.textContent = tekst || '';
        felt.hidden = !tekst;
    }

    // ---------- Login ----------

    function visLogin() {
        visFejl(loginFejl, '');
        MitSporApp.visSide('view-login');
    }

    function logInd(event) {
        event.preventDefault();
        var email = loginEmail.value.trim();
        if (!email || !loginKode.value) {
            visFejl(loginFejl, 'Udfyld e-mail og adgangskode.');
            return;
        }
        visFejl(loginFejl, '');
        loginKnap.disabled = true;
        Konto.logInd(email, loginKode.value)
            .then(function () {
                loginKode.value = '';
                if (tur) {
                    return visUpload(tur);
                }
                MitSporApp.visSide('view-optag');
            })
            .catch(function (fejl) {
                visFejl(loginFejl, fejl.message);
            })
            .finally(function () {
                loginKnap.disabled = false;
            });
    }

    // ---------- Upload ----------

    function fyldAktiviteter(liste, valgt) {
        uploadAktivitet.textContent = '';
        var tom = document.createElement('option');
        tom.value = '';
        tom.textContent = 'Vælg aktivitet';
        uploadAktivitet.appendChild(tom);
        liste.forEach(function (a) {
            var option = document.createElement('option');
            option.value = String(a.id);
            option.textContent = a.name_da;
            uploadAktivitet.appendChild(option);
        });
        uploadAktivitet.value = valgt && liste.some(function (a) { return a.id === valgt; }) ? String(valgt) : '';
    }

    function visUpload(m) {
        var valgt = (m.upload && m.upload.aktivitet) || Konto.sidsteAktivitet();
        var gemte = Konto.gemteAktiviteter();

        visFejl(uploadFejl, '');
        uploadTur.textContent = m.navn;
        uploadTitel.value = (m.upload && m.upload.titel) || m.navn;
        fyldAktiviteter(gemte, valgt);
        MitSporApp.visSide('view-upload');

        // Hent listen igen (den kan være ændret på sitet). Uden net bruges den gemte.
        return Konto.hentAktiviteter().then(function (liste) {
            if (liste.length && tur === m) {
                fyldAktiviteter(liste, Number(uploadAktivitet.value) || valgt);
            }
            if (!liste.length && tur === m) {
                visFejl(uploadFejl, 'Aktivitetstyperne kunne ikke hentes. Prøv igen, når du er på nettet.');
            }
        });
    }

    function saetIKoe(event) {
        event.preventDefault();
        var titel = uploadTitel.value.trim();
        var aktivitet = Number(uploadAktivitet.value);

        if (titel.length < 2 || titel.length > 200) {
            visFejl(uploadFejl, 'Titlen skal være mellem 2 og 200 tegn.');
            return;
        }
        if (!aktivitet) {
            visFejl(uploadFejl, 'Vælg en aktivitet.');
            return;
        }

        uploadKnap.disabled = true;
        Konto.gemSidsteAktivitet(aktivitet);
        Upload.saetIKoe(tur.id, titel, aktivitet)
            .then(function () {
                tur = null;
                MitSporApp.visSide('view-optag');
            })
            .catch(function () {
                visFejl(uploadFejl, 'Turen kunne ikke sættes i kø. Prøv igen.');
            })
            .finally(function () {
                uploadKnap.disabled = false;
            });
    }

    // Åbner upload af en tur. Er brugeren ikke logget ind, kommer login først.
    function aabn(m) {
        tur = m;
        Konto.hent().then(function (nu) {
            if (nu) {
                visUpload(m);
            } else {
                visLogin();
            }
        });
    }

    // ---------- Linjen "Logget ind som …" ----------

    function visKonto(nu) {
        login = nu;
        if (nu) {
            kontoTekst.textContent = 'Logget ind som ' + (nu.navn || 'dig') + '.';
            kontoKnap.textContent = 'Log ud';
        } else {
            kontoTekst.textContent = 'Log ind for at uploade dine ture til mitspor.dk.';
            kontoKnap.textContent = 'Log ind';
        }
    }

    kontoKnap.addEventListener('click', function () {
        if (!login) {
            tur = null;
            visLogin();
            return;
        }
        if (window.confirm('Log ud af appen?\n\nTure, der venter på upload, bliver sendt, når du logger ind igen.')) {
            Konto.logUd();
        }
    });

    loginForm.addEventListener('submit', logInd);
    uploadForm.addEventListener('submit', saetIKoe);
    document.getElementById('login-tilbage').addEventListener('click', function () {
        tur = null;
        MitSporApp.visSide('view-optag');
    });
    document.getElementById('upload-tilbage').addEventListener('click', function () {
        tur = null;
        MitSporApp.visSide('view-optag');
    });
    document.getElementById('login-opret').addEventListener('click', function (event) {
        event.preventDefault();
        MitSporApp.aabnSite('/register.php');
    });
    document.getElementById('login-glemt').addEventListener('click', function (event) {
        event.preventDefault();
        MitSporApp.aabnSite('/forgot-password.php');
    });

    Konto.onSkift(visKonto);
    Konto.hent().then(visKonto);

    window.MitSporUploadVisning = {
        aabn: aabn
    };
})();

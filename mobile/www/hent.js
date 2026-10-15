// Download fra sitet i appens WebView.
//
// WebView'et kan ikke selv hente filer. Fra MitSporApp/4 peger sitets
// download-links derfor på /app-hent.php (se app/app_hent.php på sitet).
// Når den side indlæses (app.js), henter appen filen via API'et, gemmer den
// i appens cache og viser telefonens del-ark (Gem i Filer, åbn i en anden
// app osv.). Sitet går selv tilbage til siden med linket.
(function () {
    'use strict';

    var Capacitor = window.capacitorExports.Capacitor;
    var browser = window.capacitorInAppBrowser;
    var fs = window.capacitorFilesystemPluginCapacitor;
    var Filesystem = fs.Filesystem;
    var Share = window.capacitorShare.Share;
    var API_URL = window.MITSPOR_CONFIG.siteUrl + '/api/v1/';
    var MAPPE = 'hentet';

    var igang = false;

    // Brugeren lukkede del-arket: ikke en fejl.
    function annulleret(f) {
        return !!f && /cancel/i.test(f.message || '');
    }

    // Fejl med en tekst, der kan vises for brugeren.
    function fejl(tekst) {
        var f = new Error(tekst);
        f.vis = true;
        return f;
    }

    function hentFil(sti, token) {
        var headers = {};
        if (token) {
            headers['X-Api-Token'] = token;
        }
        return fetch(API_URL + sti, { headers: headers, cache: 'no-store' })
            .catch(function () {
                throw fejl('Ingen forbindelse til mitspor.dk.');
            });
    }

    // Filnavnet fra Content-Disposition (filename*=UTF-8''… foretrækkes).
    function filnavn(svar, standard) {
        var cd = svar.headers.get('Content-Disposition') || '';
        var navn = null;
        var m = /filename\*=UTF-8''([^;]+)/i.exec(cd);
        if (m) {
            try { navn = decodeURIComponent(m[1]); } catch (e) { navn = null; }
        }
        if (!navn) {
            m = /filename="([^"]+)"/i.exec(cd);
            navn = m ? m[1] : null;
        }
        navn = (navn || standard).replace(/[\/\\:*?"<>|\u0000-\u001f]/g, '-').trim();
        return navn || standard;
    }

    function tilBase64(blob) {
        return new Promise(function (resolve, reject) {
            var laeser = new FileReader();
            laeser.onload = function () { resolve(String(laeser.result).split(',')[1] || ''); };
            laeser.onerror = function () { reject(fejl('Filen kunne ikke gemmes.')); };
            laeser.readAsDataURL(blob);
        });
    }

    // Svar fra API'et: fejltekst fra JSON, ellers en generel tekst.
    function svarFejl(svar, standard) {
        return svar.json()
            .catch(function () { return {}; })
            .then(function (data) {
                throw fejl((data && data.error) || standard);
            });
    }

    function hentGpx(id, share, login) {
        var sti = 'route-file.php?id=' + encodeURIComponent(id) + (share ? '&share=' + encodeURIComponent(share) : '');
        return hentFil(sti, login && login.token).then(function (svar) {
            // Udløbet login i appen: prøv uden, så offentlige ruter og del-links virker.
            if (svar.status === 401 && login) {
                return hentFil(sti, null);
            }
            return svar;
        }).then(function (svar) {
            if (!svar.ok) {
                return svarFejl(svar, 'Ruten kunne ikke hentes.');
            }
            return svar;
        });
    }

    function hentEksport(login) {
        if (!login) {
            return Promise.reject(fejl('Log ind i appen for at hente dine data.'));
        }
        return hentFil('export-data.php', login.token).then(function (svar) {
            if (svar.status === 401) {
                throw fejl('Dit login i appen er udløbet. Log ind igen.');
            }
            if (!svar.ok) {
                return svarFejl(svar, 'Dine data kunne ikke hentes.');
            }
            return svar;
        });
    }

    // Gem filen i appens cache (den forrige ryddes væk) og vis del-arket.
    // Der ventes ikke på del-arkets svar: på Android kommer det først, når
    // appens eget vindue er forrest igen (når WebView'et lukkes). Ventede vi,
    // ville alle senere downloads blive afvist som "i gang".
    function gemOgDel(svar, standardNavn) {
        var navn = filnavn(svar, standardNavn);
        return svar.blob()
            .then(tilBase64)
            .then(function (data) {
                return Filesystem.rmdir({ path: MAPPE, directory: fs.Directory.Cache, recursive: true })
                    .catch(function () {})
                    .then(function () {
                        return Filesystem.writeFile({
                            path: MAPPE + '/' + navn,
                            data: data,
                            directory: fs.Directory.Cache,
                            recursive: true
                        });
                    });
            })
            .then(function (gemt) {
                Share.share({ title: navn, files: [gemt.uri], dialogTitle: 'Gem eller del filen' })
                    .catch(function (f) {
                        if (!annulleret(f)) {
                            visFejl('Filen kunne ikke deles.');
                        }
                    });
            });
    }

    // En fejl kan ikke vises oven på WebView'et, så det lukkes først.
    function visFejl(tekst) {
        browser.InAppBrowser.close()
            .catch(function () {})
            .then(function () {
                setTimeout(function () { window.alert(tekst); }, 400);
            });
    }

    // url: den indlæste /app-hent.php?type=gpx&id=…[&share=…] eller ?type=export
    function hent(url) {
        if (igang || !Capacitor.isNativePlatform()) {
            return;
        }
        var parametre;
        try {
            parametre = new URL(url).searchParams;
        } catch (e) {
            return;
        }
        var type = parametre.get('type');
        if (type !== 'gpx' && type !== 'export') {
            return;
        }

        igang = true;
        window.MitSporKonto.hent()
            .then(function (login) {
                if (type === 'export') {
                    return hentEksport(login).then(function (svar) {
                        return gemOgDel(svar, 'mitspor-data.zip');
                    });
                }
                return hentGpx(parametre.get('id') || '', parametre.get('share') || '', login).then(function (svar) {
                    return gemOgDel(svar, 'rute.gpx');
                });
            })
            .catch(function (f) {
                if (annulleret(f)) {
                    return;
                }
                visFejl(f && f.vis ? f.message : 'Filen kunne ikke hentes.');
            })
            .finally(function () {
                igang = false;
            });
    }

    window.MitSporHent = { hent: hent };
})();

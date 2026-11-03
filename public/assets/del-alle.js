// "Del alle mine ruter" i kontrolpanelet (dialogen #share-all-dialog i
// dashboard.php, endpoint /share-all-link.php). Menupunktet
// #btn-open-share-all åbner dialogen. Appens menu åbner
// dashboard.php?del_alle=1, der åbner dialogen med det samme.
(function () {
    "use strict";
    var dialog = document.getElementById('share-all-dialog');
    var openBtn = document.getElementById('btn-open-share-all');
    if (!dialog || !openBtn) return;

    var csrfToken = dialog.getAttribute('data-csrf');
    var errorsEl = document.getElementById('share-all-errors');
    var activeEl = document.getElementById('share-all-active');
    var noneEl = document.getElementById('share-all-none');
    var urlInput = document.getElementById('share-all-url');

    function vis(url) {
        urlInput.value = url || '';
        activeEl.hidden = !url;
        noneEl.hidden = !!url;
    }

    function kald(action) {
        errorsEl.hidden = true;
        return fetch('/share-all-link.php', {
            method: 'POST',
            body: new URLSearchParams({ csrf_token: csrfToken, action: action })
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data.success) {
                    errorsEl.textContent = (data.errors && data.errors.join(' ')) || 'Der skete en fejl. Prøv igen.';
                    errorsEl.hidden = false;
                    return;
                }
                vis(data.url);
            })
            .catch(function () {
                errorsEl.textContent = 'Der skete en fejl. Prøv igen.';
                errorsEl.hidden = false;
            });
    }

    function aabn() {
        errorsEl.hidden = true;
        activeEl.hidden = true;
        noneEl.hidden = true;
        // 'create' opretter kun et nyt link, hvis der ikke allerede er ét.
        kald('create');
        dialog.showModal();
    }

    openBtn.addEventListener('click', aabn);

    // Fra appens menu: ?del_alle=1 åbner dialogen med det samme. Parameteren
    // fjernes fra adressen, så dialogen ikke åbner igen ved genindlæsning.
    var params = new URLSearchParams(location.search);
    if (params.has('del_alle')) {
        params.delete('del_alle');
        var q = params.toString();
        try { history.replaceState(null, '', location.pathname + (q ? '?' + q : '') + location.hash); } catch (e) { /* ikke kritisk */ }
        aabn();
    }

    document.getElementById('share-all-close').addEventListener('click', function () { dialog.close(); });
    dialog.addEventListener('click', function (ev) {
        if (ev.target === dialog) dialog.close();
    });

    document.getElementById('share-all-copy-btn').addEventListener('click', function () {
        urlInput.select();
        navigator.clipboard.writeText(urlInput.value).catch(function () {
            document.execCommand('copy');
        });
    });
    document.getElementById('share-all-new-btn').addEventListener('click', function () {
        if (confirm('Lav et nyt link? Det gamle link stopper med at virke med det samme.')) {
            kald('regenerate');
        }
    });
    document.getElementById('share-all-revoke-btn').addEventListener('click', function () {
        if (confirm('Slet linket? Det stopper med at virke med det samme.')) {
            kald('revoke');
        }
    });
    document.getElementById('share-all-create-btn').addEventListener('click', function () {
        kald('create');
    });
})();

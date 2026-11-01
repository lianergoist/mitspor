// Del-link-dialogen (app/share_link_dialog.php) i kontrolpanelet og på
// rutens side. Knapper med data-share-link="<rute-id>" og data-route-title
// åbner den; rutens side kalder MitSporDelLink.aabn() fra menuen.
(function () {
    "use strict";
    var dialog = document.getElementById('share-link-dialog');
    if (!dialog) return;

    var csrfToken = dialog.getAttribute('data-csrf');
    var closeBtn = document.getElementById('share-link-close');
    var errorsEl = document.getElementById('share-link-errors');
    var titleEl = document.getElementById('share-link-route-title');
    var urlInput = document.getElementById('share-link-url');

    var currentRoutePublicId = null;

    function buildUrl(publicId, token) {
        return window.location.origin + '/route-view.php?id=' + encodeURIComponent(publicId) + '&share=' + encodeURIComponent(token);
    }

    function aabn(publicId, title) {
        errorsEl.hidden = true;
        currentRoutePublicId = publicId;
        titleEl.textContent = title;
        urlInput.value = '';

        // 'create' er idempotent — opretter kun et nyt link, hvis der
        // ikke allerede findes ét, og returnerer ellers det eksisterende.
        callShareApi('create');
        dialog.showModal();
    }

    document.querySelectorAll('[data-share-link]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            aabn(btn.getAttribute('data-share-link'), btn.getAttribute('data-route-title'));
        });
    });

    closeBtn.addEventListener('click', function () { dialog.close(); });
    dialog.addEventListener('click', function (ev) {
        if (ev.target === dialog) dialog.close();
    });

    function callShareApi(action) {
        errorsEl.hidden = true;
        return fetch('/share-link.php', {
            method: 'POST',
            body: new URLSearchParams({ csrf_token: csrfToken, route_id: currentRoutePublicId, action: action })
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data.success) {
                    errorsEl.textContent = (data.errors && data.errors.join(' ')) || 'Der skete en fejl. Prøv igen.';
                    errorsEl.hidden = false;
                    return;
                }

                if (data.share_token) {
                    urlInput.value = buildUrl(currentRoutePublicId, data.share_token);
                } else {
                    // Slettet — dialogen giver ikke mere mening at have åben.
                    dialog.close();
                }

                // Opdater knappens data-attribut, så listen ikke skal
                // genindlæses for at afspejle den nye tilstand.
                var btn = document.querySelector('[data-share-link="' + currentRoutePublicId + '"]');
                if (btn) btn.setAttribute('data-has-token', data.share_token ? '1' : '0');
            })
            .catch(function () {
                errorsEl.textContent = 'Der skete en fejl. Prøv igen.';
                errorsEl.hidden = false;
            });
    }

    document.getElementById('share-link-revoke-btn').addEventListener('click', function () {
        if (confirm('Slet del-linket? Det stopper med at virke med det samme.')) {
            callShareApi('revoke');
        }
    });
    document.getElementById('share-link-copy-btn').addEventListener('click', function () {
        urlInput.select();
        navigator.clipboard.writeText(urlInput.value).catch(function () {
            document.execCommand('copy');
        });
    });

    window.MitSporDelLink = { aabn: aabn };
})();

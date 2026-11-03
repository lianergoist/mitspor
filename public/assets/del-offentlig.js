// "Del link" til offentlige ruter og grupper (app/share_link_dialog.php,
// public_link_dialog()). Knapper med data-open-public-link åbner dialogen.
(function () {
    "use strict";
    var dialog = document.getElementById('public-link-dialog');
    if (!dialog) return;
    var urlInput = document.getElementById('public-link-url');
    var copyBtn = document.getElementById('public-link-copy-btn');
    var shareBtn = document.getElementById('public-link-share-btn');

    document.querySelectorAll('[data-open-public-link]').forEach(function (btn) {
        btn.addEventListener('click', function () { dialog.showModal(); });
    });
    document.getElementById('public-link-close').addEventListener('click', function () { dialog.close(); });
    dialog.addEventListener('click', function (ev) {
        if (ev.target === dialog) dialog.close();
    });
    if (!urlInput) return;

    urlInput.addEventListener('focus', function () { urlInput.select(); });
    copyBtn.addEventListener('click', function () {
        urlInput.select();
        var faerdig = function () {
            copyBtn.textContent = 'Kopieret';
            setTimeout(function () { copyBtn.textContent = 'Kopiér'; }, 2000);
        };
        if (navigator.clipboard) {
            navigator.clipboard.writeText(urlInput.value).then(faerdig, function () { document.execCommand('copy'); faerdig(); });
        } else {
            document.execCommand('copy');
            faerdig();
        }
    });

    // Telefonens/browserens egen delemenu, hvis den findes (fx Safari på iPhone).
    if (navigator.share) {
        shareBtn.hidden = false;
        shareBtn.addEventListener('click', function () {
            navigator.share({ title: document.title, url: urlInput.value }).catch(function () {});
        });
    }
})();

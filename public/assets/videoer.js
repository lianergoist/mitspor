// Afspiller en instruktionsvideo i en dialog, når der trykkes på en knap med
// data-video (laves af video_button() i app/videoer.php eller af dashboard.js).
// Dialogen lægges oven på en eventuel hjælp-dialog og lukkes uden at lukke den.
(function () {
    var dialog = null;
    var titleEl, video;

    function build() {
        dialog = document.createElement('dialog');
        dialog.id = 'video-dialog';
        dialog.innerHTML =
            '<div class="video-dialog-header">' +
            '<h2></h2>' +
            '<button type="button" class="video-dialog-close" aria-label="Luk">&times;</button>' +
            '</div>' +
            '<video controls playsinline preload="metadata"></video>' +
            '<p class="video-dialog-more"><a href="/videoer.php">Alle videoer</a></p>';
        document.body.appendChild(dialog);
        titleEl = dialog.querySelector('h2');
        video = dialog.querySelector('video');
        dialog.querySelector('.video-dialog-close').addEventListener('click', function () { dialog.close(); });
        dialog.addEventListener('click', function (ev) {
            if (ev.target === dialog) dialog.close();
        });
        dialog.addEventListener('close', function () {
            video.pause();
            video.removeAttribute('src');
            video.innerHTML = '';
            video.load();
        });
    }

    document.addEventListener('click', function (ev) {
        var btn = ev.target.closest('[data-video]');
        if (!btn) return;
        ev.preventDefault();
        if (!dialog) build();
        var key = btn.getAttribute('data-video');
        var start = parseFloat(btn.getAttribute('data-start') || '0');
        titleEl.textContent = btn.getAttribute('data-title') || 'Video';
        dialog.classList.toggle('video-portrait', btn.hasAttribute('data-portrait'));
        video.poster = start > 0 ? '' : '/videoer/' + key + '.jpg';
        // Teksten er brændt ind; sporet kan slås til i afspilleren, fx til skærmlæsere.
        video.innerHTML = '<track kind="subtitles" src="/videoer/' + key + '.vtt" srclang="da" label="Dansk">';
        video.src = '/videoer/' + key + '.mp4' + (start > 0 ? '#t=' + start : '');
        dialog.showModal();
        var p = video.play();
        if (p && p.catch) p.catch(function () { /* autoplay afvist: brugeren trykker selv afspil */ });
    });
})();

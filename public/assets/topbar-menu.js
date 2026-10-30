/* ------------------------------------------------------------------
   Menuen (☰) i topbaren og "Om MitSpor"-dialogen (se app/topbar_menu.php).
   Menuen lukkes ved klik på et punkt, ved klik udenfor og med Esc.
------------------------------------------------------------------- */
(function () {
    "use strict";

    document.querySelectorAll('[data-topbar-menu]').forEach(function (wrap) {
        var btn = wrap.querySelector('button[aria-haspopup]');
        var list = wrap.querySelector('.topbar-menu-list');

        function setOpen(open) {
            list.hidden = !open;
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        }

        btn.addEventListener('click', function (ev) {
            ev.stopPropagation();
            setOpen(list.hidden);
        });
        list.addEventListener('click', function (ev) {
            if (ev.target.closest('[role="menuitem"]')) setOpen(false);
        });
        document.addEventListener('click', function (ev) {
            if (!list.hidden && !wrap.contains(ev.target)) setOpen(false);
        });
        document.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape' && !list.hidden) {
                setOpen(false);
                btn.focus();
            }
        });
    });

    var aboutDialog = document.getElementById('about-dialog');
    var aboutBtn = document.getElementById('btn-open-about');
    if (aboutDialog && aboutBtn) {
        aboutBtn.addEventListener('click', function () { aboutDialog.showModal(); });
        document.getElementById('about-dialog-close').addEventListener('click', function () { aboutDialog.close(); });
        aboutDialog.addEventListener('click', function (ev) {
            if (ev.target === aboutDialog) aboutDialog.close();
        });
    }
})();

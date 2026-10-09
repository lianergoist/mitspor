/**
 * Log ind / opret konto / glemt adgangskode / ny adgangskode.
 *
 * Bruges af forsiden (index.php) og rutesiden (route-view.php) sammen med
 * markup fra app/auth_dialogs.php (render_auth_dialogs()). Kun for besøgende,
 * der ikke er logget ind.
 *
 *   MitSporAuth.init({ handleUrl: true });
 *
 *   handleUrl  true: ?showLogin åbner login-dialogen, og ?resetToken=… åbner
 *              "vælg ny adgangskode" (bruges kun på forsiden, som mails linker til).
 *
 * Knapperne #btn-open-login og #btn-open-register kan mangle på en side —
 * så bliver de bare ikke koblet på.
 */
window.MitSporAuth = (function () {
    "use strict";

    function init(options) {
        options = options || {};

        var loginDialog = document.getElementById('login-dialog');
        var registerDialog = document.getElementById('register-dialog');

        var openLoginBtn = document.getElementById('btn-open-login');
        var closeLoginBtn = document.getElementById('login-dialog-close');
        var loginForm = document.getElementById('login-form');
        var loginErrors = document.getElementById('login-errors');

        var openRegisterBtn = document.getElementById('btn-open-register');
        var closeRegisterBtn = document.getElementById('register-dialog-close');
        var registerForm = document.getElementById('register-form');
        var registerErrors = document.getElementById('register-errors');
        var registerSuccess = document.getElementById('register-success');

        var forgotDialog = document.getElementById('forgot-password-dialog');
        var closeForgotBtn = document.getElementById('forgot-password-dialog-close');
        var forgotForm = document.getElementById('forgot-password-form');
        var forgotErrors = document.getElementById('forgot-password-errors');
        var forgotSuccess = document.getElementById('forgot-password-success');

        var resetDialog = document.getElementById('reset-password-dialog');
        var closeResetBtn = document.getElementById('reset-password-dialog-close');
        var resetForm = document.getElementById('reset-password-form');
        var resetErrors = document.getElementById('reset-password-errors');
        var loginSuccessNote = document.getElementById('login-success-note');

        function openLoginDialog() {
            loginErrors.hidden = true;
            loginDialog.showModal();
        }
        function openRegisterDialog() {
            registerErrors.hidden = true;
            registerForm.hidden = false;
            registerSuccess.hidden = true;
            registerDialog.showModal();
        }
        function openForgotDialog() {
            forgotErrors.hidden = true;
            forgotForm.hidden = false;
            forgotSuccess.hidden = true;
            forgotDialog.showModal();
        }

        if (openLoginBtn) openLoginBtn.addEventListener('click', openLoginDialog);
        closeLoginBtn.addEventListener('click', function () { loginDialog.close(); });
        loginDialog.addEventListener('click', function (ev) {
            if (ev.target === loginDialog) loginDialog.close();
        });

        if (openRegisterBtn) openRegisterBtn.addEventListener('click', openRegisterDialog);
        closeRegisterBtn.addEventListener('click', function () { registerDialog.close(); });
        registerDialog.addEventListener('click', function (ev) {
            if (ev.target === registerDialog) registerDialog.close();
        });

        closeForgotBtn.addEventListener('click', function () { forgotDialog.close(); });
        forgotDialog.addEventListener('click', function (ev) {
            if (ev.target === forgotDialog) forgotDialog.close();
        });

        closeResetBtn.addEventListener('click', function () { resetDialog.close(); });
        resetDialog.addEventListener('click', function (ev) {
            if (ev.target === resetDialog) resetDialog.close();
        });

        document.getElementById('link-open-register').addEventListener('click', function () {
            loginDialog.close();
            openRegisterDialog();
        });
        document.getElementById('link-open-login').addEventListener('click', function () {
            registerDialog.close();
            loginSuccessNote.hidden = true;
            openLoginDialog();
        });
        document.getElementById('link-open-forgot').addEventListener('click', function () {
            loginDialog.close();
            openForgotDialog();
        });

        loginForm.addEventListener('submit', function (ev) {
            ev.preventDefault();
            loginErrors.hidden = true;

            var submitBtn = loginForm.querySelector('button[type="submit"]');
            submitBtn.disabled = true;

            fetch('login-ajax.php', {
                method: 'POST',
                body: new URLSearchParams(new FormData(loginForm))
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data.success && data.app_forbind) {
                        // I appen: forbi /app-forbind.php, så appen også logges ind.
                        var dele = data.app_forbind.split('#');
                        window.location.href = dele[0] + '?til=' +
                            encodeURIComponent(window.location.pathname + window.location.search) + '#' + dele[1];
                        return;
                    }
                    if (data.success) {
                        window.location.reload();
                        return;
                    }
                    loginErrors.textContent = data.error || 'Der skete en fejl. Prøv igen.';
                    loginErrors.hidden = false;
                    submitBtn.disabled = false;
                })
                .catch(function () {
                    loginErrors.textContent = 'Der skete en fejl. Prøv igen.';
                    loginErrors.hidden = false;
                    submitBtn.disabled = false;
                });
        });

        registerForm.addEventListener('submit', function (ev) {
            ev.preventDefault();
            registerErrors.hidden = true;

            var submitBtn = registerForm.querySelector('button[type="submit"]');
            submitBtn.disabled = true;

            fetch('register-ajax.php', {
                method: 'POST',
                body: new URLSearchParams(new FormData(registerForm))
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    submitBtn.disabled = false;
                    if (data.success) {
                        registerForm.hidden = true;
                        registerSuccess.hidden = false;
                        return;
                    }
                    registerErrors.textContent = (data.errors && data.errors.join(' ')) || 'Der skete en fejl. Prøv igen.';
                    registerErrors.hidden = false;
                })
                .catch(function () {
                    submitBtn.disabled = false;
                    registerErrors.textContent = 'Der skete en fejl. Prøv igen.';
                    registerErrors.hidden = false;
                });
        });

        forgotForm.addEventListener('submit', function (ev) {
            ev.preventDefault();
            forgotErrors.hidden = true;

            var submitBtn = forgotForm.querySelector('button[type="submit"]');
            submitBtn.disabled = true;

            fetch('forgot-password-ajax.php', {
                method: 'POST',
                body: new URLSearchParams(new FormData(forgotForm))
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    submitBtn.disabled = false;
                    if (data.success) {
                        forgotForm.hidden = true;
                        forgotSuccess.hidden = false;
                        return;
                    }
                    forgotErrors.textContent = (data.errors && data.errors.join(' ')) || 'Der skete en fejl. Prøv igen.';
                    forgotErrors.hidden = false;
                })
                .catch(function () {
                    submitBtn.disabled = false;
                    forgotErrors.textContent = 'Der skete en fejl. Prøv igen.';
                    forgotErrors.hidden = false;
                });
        });

        resetForm.addEventListener('submit', function (ev) {
            ev.preventDefault();
            resetErrors.hidden = true;

            var submitBtn = resetForm.querySelector('button[type="submit"]');
            submitBtn.disabled = true;

            fetch('reset-password-ajax.php', {
                method: 'POST',
                body: new URLSearchParams(new FormData(resetForm))
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    submitBtn.disabled = false;
                    if (data.success) {
                        resetDialog.close();
                        // Sendes direkte videre til login, som du foreslog.
                        loginSuccessNote.textContent = 'Din adgangskode er ændret. Log ind herunder.';
                        loginSuccessNote.hidden = false;
                        openLoginDialog();
                        return;
                    }
                    resetErrors.textContent = (data.errors && data.errors.join(' ')) || 'Der skete en fejl. Prøv igen.';
                    resetErrors.hidden = false;
                })
                .catch(function () {
                    submitBtn.disabled = false;
                    resetErrors.textContent = 'Der skete en fejl. Prøv igen.';
                    resetErrors.hidden = false;
                });
        });

        if (options.handleUrl) {
            var params = new URLSearchParams(window.location.search);
            if (params.has('showLogin')) {
                openLoginDialog();
            }
            var resetToken = params.get('resetToken');
            if (resetToken) {
                document.getElementById('reset-password-token').value = resetToken;
                resetErrors.hidden = true;
                resetDialog.showModal();
            }
        }
    }

    return { init: init };
})();

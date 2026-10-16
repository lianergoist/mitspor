/* Kontrolpanelets JavaScript (flyttet ud af dashboard.php). */
(function () {
    "use strict";
    var dialog = document.getElementById('profile-dialog');
    var openBtn = document.getElementById('btn-open-profile');
    var closeBtn = document.getElementById('profile-dialog-close');

    openBtn.addEventListener('click', function () { dialog.showModal(); });
    closeBtn.addEventListener('click', function () { dialog.close(); });
    dialog.addEventListener('click', function (ev) {
        if (ev.target === dialog) {
            dialog.close();
        }
    });
})();

(function () {
    "use strict";
    var csrfToken = document.getElementById('upload-csrf-token').value;

    function wireInlinePanel(config) {
        var openBtn = document.getElementById(config.openBtnId);
        var panel = document.getElementById(config.panelId);
        var cancelBtn = document.getElementById(config.cancelBtnId);
        var saveBtn = document.getElementById(config.saveBtnId);
        var errorEl = document.getElementById(config.errorId);
        var successEl = config.successId ? document.getElementById(config.successId) : null;

        openBtn.addEventListener('click', function () {
            errorEl.hidden = true;
            if (successEl) successEl.hidden = true;
            panel.hidden = !panel.hidden;
            if (config.onOpen) config.onOpen();
        });

        cancelBtn.addEventListener('click', function () {
            panel.hidden = true;
            errorEl.hidden = true;
        });

        saveBtn.addEventListener('click', function () {
            errorEl.hidden = true;
            if (successEl) successEl.hidden = true;

            var errors = config.validate ? config.validate() : null;
            if (errors && errors.length) {
                errorEl.textContent = errors.join(' ');
                errorEl.hidden = false;
                return;
            }

            saveBtn.disabled = true;

            fetch(config.endpoint, {
                method: 'POST',
                body: new URLSearchParams(Object.assign({ csrf_token: csrfToken }, config.getBody()))
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    saveBtn.disabled = false;
                    if (!data.success) {
                        errorEl.textContent = (data.errors && data.errors.join(' ')) || 'Der skete en fejl. Prøv igen.';
                        errorEl.hidden = false;
                        return;
                    }
                    if (config.onSuccess) config.onSuccess(data);
                })
                .catch(function () {
                    saveBtn.disabled = false;
                    errorEl.textContent = 'Der skete en fejl. Prøv igen.';
                    errorEl.hidden = false;
                });
        });
    }

    // ---- Navn ----
    wireInlinePanel({
        openBtnId: 'btn-edit-name',
        panelId: 'edit-name-panel',
        cancelBtnId: 'cancel-name-btn',
        saveBtnId: 'save-name-btn',
        errorId: 'edit-name-error',
        endpoint: 'update-profile-name.php',
        onOpen: function () {
            document.getElementById('edit-name-input').value =
                document.getElementById('profile-name-display').textContent;
        },
        validate: function () {
            var value = document.getElementById('edit-name-input').value.trim();
            if (value.length < 2 || value.length > 100) {
                return ['Navnet skal være mellem 2 og 100 tegn.'];
            }
            return null;
        },
        getBody: function () {
            return { display_name: document.getElementById('edit-name-input').value };
        },
        onSuccess: function (data) {
            document.getElementById('profile-name-display').textContent = data.display_name;
            document.getElementById('edit-name-panel').hidden = true;
        }
    });

    // ---- E-mail ----
    wireInlinePanel({
        openBtnId: 'btn-edit-email',
        panelId: 'edit-email-panel',
        cancelBtnId: 'cancel-email-btn',
        saveBtnId: 'save-email-btn',
        errorId: 'edit-email-error',
        successId: 'edit-email-success',
        endpoint: 'request-email-change.php',
        onOpen: function () {
            document.getElementById('edit-email-input').value = '';
            document.getElementById('edit-email-password').value = '';
        },
        getBody: function () {
            return {
                new_email: document.getElementById('edit-email-input').value,
                current_password: document.getElementById('edit-email-password').value
            };
        },
        onSuccess: function () {
            document.getElementById('edit-email-success').hidden = false;
            document.getElementById('edit-email-input').value = '';
            document.getElementById('edit-email-password').value = '';
        }
    });

    // ---- Adgangskode ----
    wireInlinePanel({
        openBtnId: 'btn-edit-password',
        panelId: 'edit-password-panel',
        cancelBtnId: 'cancel-password-btn',
        saveBtnId: 'save-password-btn',
        errorId: 'edit-password-error',
        successId: 'edit-password-success',
        endpoint: 'update-profile-password.php',
        onOpen: function () {
            document.getElementById('edit-current-password').value = '';
            document.getElementById('edit-new-password').value = '';
            document.getElementById('edit-new-password-confirm').value = '';
        },
        validate: function () {
            var newPw = document.getElementById('edit-new-password').value;
            var confirmPw = document.getElementById('edit-new-password-confirm').value;
            if (newPw.length < 10) return ['Den nye adgangskode skal være mindst 10 tegn.'];
            if (newPw !== confirmPw) return ['De to nye adgangskoder er ikke ens.'];
            return null;
        },
        getBody: function () {
            return {
                current_password: document.getElementById('edit-current-password').value,
                new_password: document.getElementById('edit-new-password').value,
                new_password_confirm: document.getElementById('edit-new-password-confirm').value
            };
        },
        onSuccess: function () {
            document.getElementById('edit-password-success').hidden = false;
            document.getElementById('edit-current-password').value = '';
            document.getElementById('edit-new-password').value = '';
            document.getElementById('edit-new-password-confirm').value = '';
        }
    });
})();

(function () {
    "use strict";
    var metaDialog = document.getElementById('edit-meta-dialog');
    var metaCloseBtn = document.getElementById('edit-meta-close');
    var metaForm = document.getElementById('edit-meta-form');
    var metaErrors = document.getElementById('edit-meta-errors');

    var metaBehavior = RouteMetaDialog.init({
        routeTypeRute: 'edit-meta-route-type-rute',
        visibilityPublic: 'edit-meta-visibility-public',
        visibilityPrivate: 'edit-meta-visibility-private',
        allowDownload: 'edit-meta-allow-download',
        publicNotice: 'edit-meta-public-notice',
        anonymousField: 'edit-meta-anonymous',
        timeInRuteNotice: 'edit-meta-time-in-rute-notice',
        publicNoticeAck: 'edit-meta-public-notice-ack'
    });

    document.querySelectorAll('[data-edit-meta]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var publicId = btn.getAttribute('data-edit-meta');
            metaErrors.hidden = true;

            fetch('route-metadata.php?id=' + encodeURIComponent(publicId))
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (!data.success) {
                        alert((data.errors && data.errors.join(' ')) || 'Kunne ikke hente ruten.');
                        return;
                    }

                    document.getElementById('edit-meta-route-id').value = data.public_id;
                    document.getElementById('edit-meta-title').value = data.title;
                    document.getElementById('edit-meta-description').value = data.description || '';
                    document.getElementById('edit-meta-activity').value = String(data.activity_type_id);
                    var markerRadio = metaForm.querySelector('input[name="marker_icon"][value="' + (data.marker_icon || '') + '"]')
                        || metaForm.querySelector('input[name="marker_icon"][value=""]');
                    if (markerRadio) markerRadio.checked = true;
                    updateDefaultMarker();
                    document.getElementById('edit-meta-allow-download').checked = data.allow_download;
                    document.getElementById('edit-meta-hide-uploader-name').checked = data.hide_uploader_name;
                    var metaGroupSelect = document.getElementById('edit-meta-group');
                    if (metaGroupSelect) metaGroupSelect.value = data.group_id || '';

                    var routeTypeRadio = document.querySelector('input[name="route_type"][value="' + data.route_type + '"]');
                    if (routeTypeRadio) routeTypeRadio.checked = true;

                    var visRadio = document.querySelector('input[name="visibility"][value="' + data.visibility + '"]');
                    if (visRadio) visRadio.checked = true;

                    metaBehavior.setLoadedVisibility(data.visibility);
                    metaBehavior.setHasTimeData(data.has_time_data);

                    metaDialog.showModal();
                });
        });
    });

    metaCloseBtn.addEventListener('click', function () { metaDialog.close(); });
    metaDialog.addEventListener('click', function (ev) {
        if (ev.target === metaDialog) {
            metaDialog.close();
        }
    });

    // "Standard" i markørvalget viser markøren for den valgte aktivitet
    // (eller "Ingen", hvis aktiviteten ikke har en, fx "Rute på land").
    var metaActivity = document.getElementById('edit-meta-activity');
    function updateDefaultMarker() {
        var box = document.getElementById('edit-meta-marker-default');
        if (!box || !metaActivity) return;
        var option = metaActivity.options[metaActivity.selectedIndex];
        var markerUrl = option ? option.getAttribute('data-marker-url') : '';
        box.textContent = '';
        if (markerUrl) {
            var img = document.createElement('img');
            img.src = markerUrl;
            img.alt = '';
            box.appendChild(img);
        } else {
            box.textContent = 'Ingen';
        }
    }
    if (metaActivity) metaActivity.addEventListener('change', updateDefaultMarker);

    metaForm.addEventListener('submit', function (ev) {
        ev.preventDefault();
        metaErrors.hidden = true;

        if (!metaBehavior.requireGdprAckIfPublic()) {
            return;
        }

        var submitBtn = metaForm.querySelector('button[type="submit"]');
        submitBtn.disabled = true;

        fetch('update-route-metadata.php', {
            method: 'POST',
            body: new URLSearchParams(new FormData(metaForm))
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.success) {
                    window.MitSporGenindlaes();
                    return;
                }
                metaErrors.textContent = (data.errors && data.errors.join(' ')) || 'Der skete en fejl. Prøv igen.';
                metaErrors.hidden = false;
                submitBtn.disabled = false;
            })
            .catch(function () {
                metaErrors.textContent = 'Der skete en fejl. Prøv igen.';
                metaErrors.hidden = false;
                submitBtn.disabled = false;
            });
    });
})();

(function () {
    "use strict";
    var dialog = document.getElementById('upload-dialog');
    var openBtn = document.getElementById('btn-open-upload');
    var closeBtn = document.getElementById('upload-dialog-close');
    var form = document.getElementById('upload-form');
    var errorsEl = document.getElementById('upload-errors');
    var fileInput = document.getElementById('upload-gpx-file');
    var titleInput = document.getElementById('upload-title');

    // Flere filer på én gang -> oprettes som etaper i en ny gruppe.
    var isMulti = false;
    var needsReload = false;
    var titleLabel = document.getElementById('upload-title-label');
    var groupFields = document.getElementById('upload-group-fields');
    var groupTitleInput = document.getElementById('upload-group-title');
    var multiNote = document.getElementById('upload-multi-note');
    var multiHint = document.getElementById('upload-multi-hint');
    var progressEl = document.getElementById('upload-progress');
    var submitBtn = document.getElementById('upload-submit-btn');

    function setMultiMode(on, count) {
        isMulti = on;
        titleLabel.hidden = on;
        titleInput.required = !on;
        groupFields.hidden = !on;
        groupTitleInput.required = on;
        multiHint.hidden = on;
        if (on) {
            multiNote.textContent = count + ' filer valgt. De oprettes som etaper i en ny gruppe, og hver etape får filnavnet som titel (du kan omdøbe dem bagefter). ' +
                'Aktivitet, type og synlighed nedenfor gælder for alle etaperne. Tidsstempler og punkter beholdes uændret.';
        } else {
            groupTitleInput.value = '';
        }
    }

    function readFileText(file) {
        return new Promise(function (resolve, reject) {
            var reader = new FileReader();
            reader.onload = function () { resolve(reader.result); };
            reader.onerror = function () { reject(new Error('Kunne ikke læse filen ' + file.name + '.')); };
            reader.readAsText(file);
        });
    }

    // Har filens FØRSTE punkt et tidsstempel? (Kun starten af filen læses.)
    function startHasPointTime(text) {
        var i = text.search(/<(trkpt|rtept)[\s>]/);
        if (i === -1) return false;
        return /<time[>\s]/.test(text.slice(i, i + 3000));
    }

    function handleMultiSelect() {
        var files = Array.prototype.slice.call(fileInput.files);
        setMultiMode(true, files.length);
        Promise.all(files.map(function (f) { return readFileText(f.slice(0, 300000)); })).then(function (texts) {
            fileHasTimeData = texts.every(startHasPointTime);
            metaBehavior.setHasTimeData(fileHasTimeData);
            if (!userChoseRouteType) {
                routeTypeSporRadio.checked = fileHasTimeData;
                routeTypeRuteRadio.checked = !fileHasTimeData;
            }
            metaBehavior.refresh();
        }).catch(function () { /* gæt på type er kun en hjælp */ });
    }

    function showUploadError(msg) {
        errorsEl.textContent = msg;
        errorsEl.hidden = false;
    }

    async function submitMulti() {
        var files = Array.prototype.slice.call(fileInput.files).sort(function (a, b) {
            return a.name.localeCompare(b.name, 'da', { numeric: true });
        });
        var groupTitle = groupTitleInput.value.trim();
        if (groupTitle.length < 2) {
            showUploadError('Skriv en titel til gruppen (mindst 2 tegn).');
            return;
        }

        var common = {
            csrf_token: csrfToken,
            route_id: '',
            description: '',
            activity_type_id: document.getElementById('upload-activity').value,
            route_type: document.querySelector('#upload-form input[name="route_type"]:checked').value,
            visibility: document.querySelector('#upload-form input[name="visibility"]:checked').value,
            allow_download: document.getElementById('upload-allow-download').checked ? '1' : '0',
            hide_uploader_name: document.getElementById('upload-hide-uploader-name').checked ? '1' : '0',
            public_notice_ack: noticeCheckbox.checked ? '1' : '0',
            // Ingen spørgsmål undervejs ved mange filer: tidsstempler og punkter
            // beholdes som de er (kan ændres bagefter i GPX-editoren).
            time_resolution: 'keep',
            thin_interval: '0'
        };

        submitBtn.disabled = true;
        progressEl.hidden = false;
        var created = [];
        var failure = null;

        for (var i = 0; i < files.length; i++) {
            var f = files[i];
            progressEl.textContent = 'Uploader ' + (i + 1) + ' af ' + files.length + ': ' + f.name;
            try {
                var content = await readFileText(f);
                var body = new URLSearchParams(Object.assign({}, common, {
                    gpx_data: content,
                    title: f.name.replace(/\.gpx$/i, '')
                }));
                var res = await fetch('editor-save.php', { method: 'POST', body: body });
                var data = await res.json();
                if (!res.ok || !data.success) {
                    throw new Error((data.errors && data.errors.join(' ')) || 'Ukendt fejl.');
                }
                created.push(data.route_id);
            } catch (e) {
                failure = { name: f.name, message: e.message };
                break;
            }
        }

        // Gruppér det, der blev uploadet — også hvis en fil midtvejs fejlede.
        if (created.length > 0) {
            progressEl.textContent = 'Opretter gruppen…';
            try {
                var g = await fetch('group-action.php', {
                    method: 'POST',
                    body: new URLSearchParams({
                        csrf_token: csrfToken,
                        action: 'create',
                        title: groupTitle,
                        description: document.getElementById('upload-description').value,
                        route_ids: created.join(',')
                    })
                }).then(function (r) { return r.json(); });
                if (!g.success) {
                    failure = failure || { name: '', message: (g.errors && g.errors.join(' ')) || 'Gruppen kunne ikke oprettes.' };
                }
            } catch (e) {
                failure = failure || { name: '', message: 'Gruppen kunne ikke oprettes.' };
            }
        }

        if (!failure) {
            window.MitSporGenindlaes();
            return;
        }

        needsReload = created.length > 0;
        progressEl.hidden = true;
        submitBtn.disabled = false;
        showUploadError(
            (failure.name ? 'Filen "' + failure.name + '" kunne ikke uploades: ' : '') + failure.message +
            (created.length > 0
                ? ' ' + created.length + ' af ' + files.length + ' etaper er oprettet. Luk dialogen for at se dem i listen.'
                : '')
        );
    }

    var noticeCheckbox = document.getElementById('upload-public-notice-ack');
    var routeTypeRuteRadio = document.getElementById('upload-route-type-rute');
    var routeTypeSporRadio = document.getElementById('upload-route-type-spor');
    var fileHasTimeData = false;
    var userChoseRouteType = false;

    var warningPanel = document.getElementById('upload-speed-warning');
    var warningText = document.getElementById('upload-speed-warning-text');
    var thinningPanel = document.getElementById('upload-thinning-suggest');
    var thinningText = document.getElementById('upload-thinning-suggest-text');

    var csrfToken = document.getElementById('upload-csrf-token').value;
    var pendingGpxContent = null;

    var metaBehavior = RouteMetaDialog.init({
        routeTypeRute: 'upload-route-type-rute',
        visibilityPublic: 'upload-visibility-public',
        visibilityPrivate: 'upload-visibility-private',
        allowDownload: 'upload-allow-download',
        publicNotice: 'upload-public-notice',
        anonymousField: 'upload-anonymous',
        timeInRuteNotice: 'upload-time-in-rute-notice',
        publicNoticeAck: 'upload-public-notice-ack'
    });

    function updateTimeInRuteNotice() {
        metaBehavior.refresh();
    }
    [routeTypeRuteRadio, routeTypeSporRadio].forEach(function (r) {
        r.addEventListener('change', function () { userChoseRouteType = true; });
    });

    function resetDialog() {
        form.reset();
        form.hidden = false;
        warningPanel.hidden = true;
        thinningPanel.hidden = true;
        errorsEl.hidden = true;
        // Formularen er lige nulstillet til default (privat) — så
        // GDPR-boksen skal ikke forblive forudfyldt fra en tidligere upload.
        metaBehavior.setLoadedVisibility('private');
        pendingGpxContent = null;
        fileHasTimeData = false;
        metaBehavior.setHasTimeData(false);
        userChoseRouteType = false;
        setMultiMode(false);
        progressEl.hidden = true;
        submitBtn.disabled = false;
    }

    openBtn.addEventListener('click', function () {
        resetDialog();
        dialog.showModal();
    });
    closeBtn.addEventListener('click', function () { dialog.close(); });
    dialog.addEventListener('click', function (ev) {
        if (ev.target === dialog) {
            dialog.close();
        }
    });
    dialog.addEventListener('close', function () {
        if (needsReload) window.MitSporGenindlaes();
    });

    // Foreslå filnavnet (uden .gpx) som titel, og gæt Rute/Spor ud fra om
    // filen rent faktisk indeholder tidsstempler — men rør ikke ved
    // type-valget, hvis brugeren allerede selv har valgt.
    fileInput.addEventListener('change', function () {
        if (!fileInput.files[0]) return;
        if (fileInput.files.length > 1) {
            handleMultiSelect();
            return;
        }
        setMultiMode(false);
        if (!titleInput.value) {
            titleInput.value = fileInput.files[0].name.replace(/\.gpx$/i, '');
        }
        var reader = new FileReader();
        reader.onload = function () {
            fileHasTimeData = /<time[>\s]/.test(reader.result);
            metaBehavior.setHasTimeData(fileHasTimeData);
            if (!userChoseRouteType) {
                routeTypeSporRadio.checked = fileHasTimeData;
                routeTypeRuteRadio.checked = !fileHasTimeData;
            }
            updateTimeInRuteNotice();
        };
        reader.readAsText(fileInput.files[0]);
    });

    function submitUpload(timeResolution, thinInterval) {
        var body = new URLSearchParams({
            csrf_token: csrfToken,
            route_id: '',
            gpx_data: pendingGpxContent,
            title: titleInput.value,
            description: document.getElementById('upload-description').value,
            activity_type_id: document.getElementById('upload-activity').value,
            route_type: document.querySelector('#upload-form input[name="route_type"]:checked').value,
            visibility: document.querySelector('#upload-form input[name="visibility"]:checked').value,
            allow_download: document.getElementById('upload-allow-download').checked ? '1' : '0',
            hide_uploader_name: document.getElementById('upload-hide-uploader-name').checked ? '1' : '0',
            public_notice_ack: noticeCheckbox.checked ? '1' : '0',
            time_resolution: timeResolution || 'ask',
            thin_interval: (thinInterval === undefined || thinInterval === null) ? '' : String(thinInterval)
        });

        fetch('editor-save.php', { method: 'POST', body: body })
            .then(function (res) { return res.json().then(function (data) { return { status: res.status, data: data }; }); })
            .then(function (result) {
                if (result.status === 409 && result.data.speed_warning) {
                    form.hidden = true;
                    warningPanel.hidden = false;
                    warningText.textContent =
                        'Nogle af rutens tidsstempler antyder en hastighed på op til ' + result.data.max_speed_kmh +
                        ' km/t, hvilket er over den forventede grænse på ' + result.data.threshold_kmh +
                        ' km/t for den valgte aktivitetstype. Vælg hvordan du vil fortsætte:';
                    return;
                }

                if (result.status === 409 && result.data.thinning_suggested) {
                    form.hidden = true;
                    thinningPanel.hidden = false;
                    thinningText.textContent =
                        'Ruten logger i gennemsnit hvert ' + result.data.detected_interval_seconds +
                        '. sekund. Det er ofte flere punkter, end der reelt er brug for. ' +
                        'Vil du udtynde ruten, før den gemmes?';
                    return;
                }

                if (!result.data.success) {
                    errorsEl.textContent = (result.data.errors && result.data.errors.join(' ')) || 'Der skete en fejl. Prøv igen.';
                    errorsEl.hidden = false;
                    return;
                }

                window.MitSporGenindlaes();
            })
            .catch(function () {
                errorsEl.textContent = 'Der skete en fejl. Prøv igen.';
                errorsEl.hidden = false;
            });
    }

    document.getElementById('upload-speed-keep').addEventListener('click', function () { submitUpload('keep'); });
    document.getElementById('upload-speed-strip').addEventListener('click', function () { submitUpload('strip'); });
    document.getElementById('upload-speed-cancel').addEventListener('click', function () {
        warningPanel.hidden = true;
        form.hidden = false;
    });

    document.querySelectorAll('#upload-thinning-suggest [data-upload-thin-interval]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            submitUpload('ask', parseInt(btn.getAttribute('data-upload-thin-interval'), 10));
        });
    });
    document.getElementById('upload-thinning-keep').addEventListener('click', function () { submitUpload('ask', 0); });
    document.getElementById('upload-thinning-cancel').addEventListener('click', function () {
        thinningPanel.hidden = true;
        form.hidden = false;
    });

    form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        errorsEl.hidden = true;

        if (!fileInput.files[0]) {
            errorsEl.textContent = 'Vælg en GPX-fil.';
            errorsEl.hidden = false;
            return;
        }

        if (!metaBehavior.requireGdprAckIfPublic()) {
            return;
        }

        if (isMulti && fileInput.files.length > 1) {
            submitMulti();
            return;
        }

        var reader = new FileReader();
        reader.onload = function () {
            pendingGpxContent = reader.result;
            submitUpload('ask');
        };
        reader.onerror = function () {
            errorsEl.textContent = 'Kunne ikke læse filen.';
            errorsEl.hidden = false;
        };
        reader.readAsText(fileInput.files[0]);
    });
})();

(function () {
    "use strict";
    var dialog = document.getElementById('delete-route-dialog');
    var closeBtn = document.getElementById('delete-route-close');
    var form = document.getElementById('delete-route-form');
    var errorsEl = document.getElementById('delete-route-errors');
    var titleEl = document.getElementById('delete-route-title');
    var routeIdField = document.getElementById('delete-route-id');
    var confirmField = document.getElementById('delete-route-confirm-title');
    var submitBtn = document.getElementById('delete-route-submit-btn');
    var mismatchEl = document.getElementById('delete-route-mismatch');
    var expectedTitle = '';

    // Mellemrum før og efter ignoreres (mobiltastaturer tilføjer ofte et
    // mellemrum efter ordet); serveren trimmer også.
    function updateSubmitState() {
        var typed = confirmField.value.trim();
        submitBtn.disabled = typed !== expectedTitle;
        mismatchEl.hidden = typed === '' || typed === expectedTitle;
    }

    document.querySelectorAll('[data-delete-route]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            errorsEl.hidden = true;
            form.reset();
            routeIdField.value = btn.getAttribute('data-delete-route');
            expectedTitle = btn.getAttribute('data-route-title');
            titleEl.textContent = expectedTitle;
            updateSubmitState();
            dialog.showModal();
        });
    });

    confirmField.addEventListener('input', updateSubmitState);

    closeBtn.addEventListener('click', function () { dialog.close(); });
    dialog.addEventListener('click', function (ev) {
        if (ev.target === dialog) {
            dialog.close();
        }
    });

    form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        errorsEl.hidden = true;

        submitBtn.disabled = true;

        fetch('delete-route-ajax.php', {
            method: 'POST',
            body: new URLSearchParams(new FormData(form))
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.success) {
                    // Luk dialogen med det samme, og hent siden igen.
                    dialog.close();
                    window.MitSporGenindlaes();
                    return;
                }
                errorsEl.textContent = (data.errors && data.errors.join(' ')) || 'Der skete en fejl. Prøv igen.';
                errorsEl.hidden = false;
                updateSubmitState();
            })
            .catch(function () {
                errorsEl.textContent = 'Der skete en fejl. Prøv igen.';
                errorsEl.hidden = false;
                updateSubmitState();
            });
    });
})();

(function () {
    "use strict";
    var csrfToken = document.getElementById('upload-csrf-token').value;

    document.querySelectorAll('[data-restore-original]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var publicId = btn.getAttribute('data-restore-original');
            var title = btn.getAttribute('data-route-title');

            if (!confirm('Gendan den oprindelige, ikke-udtyndede fil for "' + title + '"? Den nuværende (udtyndede) version erstattes, og kan ikke fortrydes.')) {
                return;
            }

            btn.disabled = true;

            fetch('restore-original-gpx.php', {
                method: 'POST',
                body: new URLSearchParams({ csrf_token: csrfToken, route_id: publicId })
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data.success) {
                        window.MitSporGenindlaes();
                        return;
                    }
                    btn.disabled = false;
                    alert((data.errors && data.errors.join(' ')) || 'Kunne ikke gendanne filen.');
                })
                .catch(function () {
                    btn.disabled = false;
                    alert('Der skete en fejl. Prøv igen.');
                });
        });
    });
})();

(function () {
    "use strict";
    var csrfToken = document.getElementById('upload-csrf-token').value;

    var dialog = document.getElementById('share-link-dialog');
    var closeBtn = document.getElementById('share-link-close');
    var errorsEl = document.getElementById('share-link-errors');
    var titleEl = document.getElementById('share-link-route-title');
    var urlInput = document.getElementById('share-link-url');

    var currentRoutePublicId = null;

    function buildUrl(publicId, token) {
        return window.location.origin + '/route-view.php?id=' + encodeURIComponent(publicId) + '&share=' + encodeURIComponent(token);
    }

    document.querySelectorAll('[data-share-link]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            errorsEl.hidden = true;
            currentRoutePublicId = btn.getAttribute('data-share-link');
            titleEl.textContent = btn.getAttribute('data-route-title');
            urlInput.value = '';

            // 'create' er idempotent — opretter kun et nyt link, hvis der
            // ikke allerede findes ét, og returnerer ellers det eksisterende.
            callShareApi('create');
            dialog.showModal();
        });
    });

    closeBtn.addEventListener('click', function () { dialog.close(); });
    dialog.addEventListener('click', function (ev) {
        if (ev.target === dialog) dialog.close();
    });

    function callShareApi(action) {
        errorsEl.hidden = true;
        return fetch('share-link.php', {
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
})();

(function () {
    "use strict";
    var dialog = document.getElementById('dashboard-help-dialog');
    document.getElementById('btn-open-dashboard-help').addEventListener('click', function () {
        dialog.showModal();
    });
    document.getElementById('dashboard-help-close').addEventListener('click', function () {
        dialog.close();
    });
    dialog.addEventListener('click', function (ev) {
        if (ev.target === dialog) dialog.close();
    });
})();

(function () {
    "use strict";
    var tabBtns = document.querySelectorAll('.tab-btn');
    var tabPanels = document.querySelectorAll('.tab-panel');

    tabBtns.forEach(function (btn) {
        btn.addEventListener('click', function () {
            tabBtns.forEach(function (b) { b.classList.remove('active'); });
            tabPanels.forEach(function (p) { p.hidden = true; });

            btn.classList.add('active');
            document.getElementById(btn.getAttribute('data-tab-target')).hidden = false;
        });
    });
})();

(function () {
    "use strict";

    var csrf = document.getElementById('upload-csrf-token').value;

    function post(params) {
        params.csrf_token = csrf;
        return fetch('group-action.php', { method: 'POST', body: new URLSearchParams(params) })
            .then(function (res) { return res.json(); });
    }
    function showErrors(el, data) {
        el.textContent = (data && data.errors && data.errors.join(' ')) || 'Der skete en fejl. Prøv igen.';
        el.hidden = false;
    }
    function bindDialogClose(dialog, closeBtn) {
        closeBtn.addEventListener('click', function () { dialog.close(); });
        dialog.addEventListener('click', function (ev) {
            if (ev.target === dialog) dialog.close();
        });
    }

    // ---------- Vælg ruter til gruppering ----------
    var selected = {};
    var btnGroup = document.getElementById('btn-group-selected');
    var countEl = document.getElementById('group-selected-count');

    function updateSelectionUi() {
        var n = Object.keys(selected).length;
        // Knappen står altid i topbaren, men er kun aktiv, når noget er valgt.
        countEl.textContent = ' (' + n + ')';
        countEl.hidden = n === 0;
        btnGroup.disabled = n === 0;
    }

    document.querySelectorAll('.route-select').forEach(function (cb) {
        cb.addEventListener('change', function () {
            var id = cb.getAttribute('data-select-route');
            if (cb.checked) { selected[id] = true; } else { delete selected[id]; }
            // Samme rute står i flere faneblade (Alle/Ruter/Spor) — hold dem ens.
            document.querySelectorAll('.route-select[data-select-route="' + id + '"]').forEach(function (other) {
                other.checked = cb.checked;
            });
            updateSelectionUi();
        });
    });

    // Valgte ruter i den rækkefølge, de står i listen (ikke JS' nøgle-rækkefølge).
    // Når "Grupper" bruges på én rute i detaljepanelet, gælder dialogen
    // kun den rute (uanset hvad der er afkrydset i listen).
    var forcedRouteIds = null;

    function selectedIdsInListOrder() {
        if (forcedRouteIds) return forcedRouteIds.slice();
        return Array.prototype.filter.call(document.querySelectorAll('#tab-alle .route-select'), function (cb) {
            return cb.checked;
        }).map(function (cb) { return cb.getAttribute('data-select-route'); });
    }

    // ---------- "Gruppér valgte" ----------
    var createDialog = document.getElementById('group-create-dialog');
    var createForm = document.getElementById('group-create-form');
    var createErrors = document.getElementById('group-create-errors');
    var newBox = document.getElementById('group-create-new');
    var existingBox = document.getElementById('group-create-existing');
    bindDialogClose(createDialog, document.getElementById('group-create-close'));
    createDialog.addEventListener('close', function () { forcedRouteIds = null; });

    btnGroup.addEventListener('click', function () {
        forcedRouteIds = null;
        createErrors.hidden = true;
        document.getElementById('group-create-summary').textContent = selectedIdsInListOrder().length + ' rute(r) valgt.';
        createDialog.showModal();
    });

    document.querySelectorAll('[data-add-to-group]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            forcedRouteIds = [btn.getAttribute('data-add-to-group')];
            createErrors.hidden = true;
            document.getElementById('group-create-summary').textContent =
                '\u00ab' + btn.getAttribute('data-route-title') + '\u00bb';
            createDialog.showModal();
        });
    });

    document.querySelectorAll('input[name="group_mode"]').forEach(function (r) {
        r.addEventListener('change', function () {
            var existing = document.querySelector('input[name="group_mode"]:checked').value === 'existing';
            newBox.hidden = existing;
            existingBox.hidden = !existing;
        });
    });

    createForm.addEventListener('submit', function (ev) {
        ev.preventDefault();
        createErrors.hidden = true;
        var ids = selectedIdsInListOrder();
        if (ids.length === 0) {
            showErrors(createErrors, { errors: ['Vælg mindst én rute.'] });
            return;
        }
        var mode = document.querySelector('input[name="group_mode"]:checked').value;
        var request = mode === 'existing'
            ? post({ action: 'add_routes', group_id: document.getElementById('group-create-existing-select').value, route_ids: ids.join(',') })
            : post({
                action: 'create',
                title: document.getElementById('group-create-title').value,
                description: document.getElementById('group-create-description').value,
                route_ids: ids.join(',')
            });
        request.then(function (data) {
            if (data.success) { window.MitSporGenindlaes(); return; }
            showErrors(createErrors, data);
        }).catch(function () { showErrors(createErrors, null); });
    });

    // ---------- "Rediger gruppe" ----------
    var editDialog = document.getElementById('group-edit-dialog');
    var editForm = document.getElementById('group-edit-form');
    var editErrors = document.getElementById('group-edit-errors');
    var orderList = document.getElementById('group-order-list');
    var visAck = document.getElementById('group-vis-ack');
    var currentGroupId = null;
    var sortable = null;
    bindDialogClose(editDialog, document.getElementById('group-edit-close'));

    function renderOrder(routes) {
        orderList.innerHTML = '';
        routes.forEach(function (r) {
            var li = document.createElement('li');
            li.setAttribute('data-id', r.id);

            var grip = document.createElement('span');
            grip.className = 'grip';
            grip.textContent = '\u2630';
            grip.setAttribute('aria-hidden', 'true');

            var title = document.createElement('span');
            title.className = 'go-title';
            title.textContent = r.title;

            li.appendChild(grip);
            li.appendChild(title);

            if (r.visibility === 'private') {
                var priv = document.createElement('span');
                priv.className = 'go-private';
                priv.textContent = 'privat';
                li.appendChild(priv);
            }

            [['up', '\u25B2', 'Flyt op'], ['down', '\u25BC', 'Flyt ned']].forEach(function (def) {
                var b = document.createElement('button');
                b.type = 'button';
                b.className = 'link-btn go-move';
                b.setAttribute('data-move', def[0]);
                b.setAttribute('aria-label', def[2]);
                b.textContent = def[1];
                li.appendChild(b);
            });

            orderList.appendChild(li);
        });

        if (!sortable && window.Sortable) {
            sortable = window.Sortable.create(orderList, { animation: 150, handle: '.grip' });
        }
    }

    orderList.addEventListener('click', function (ev) {
        var btn = ev.target.closest('[data-move]');
        if (!btn) return;
        var li = btn.closest('li');
        if (btn.getAttribute('data-move') === 'up' && li.previousElementSibling) {
            orderList.insertBefore(li, li.previousElementSibling);
        } else if (btn.getAttribute('data-move') === 'down' && li.nextElementSibling) {
            orderList.insertBefore(li.nextElementSibling, li);
        }
    });

    document.querySelectorAll('[data-edit-group]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-edit-group');
            editErrors.hidden = true;
            fetch('group-action.php?group_id=' + encodeURIComponent(id))
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (!data.success) {
                        alert((data.errors && data.errors.join(' ')) || 'Kunne ikke hente gruppen.');
                        return;
                    }
                    currentGroupId = data.group_id;
                    document.getElementById('group-edit-title').value = data.title;
                    document.getElementById('group-edit-description').value = data.description || '';
                    visAck.checked = false;
                    renderOrder(data.routes);
                    editDialog.showModal();
                });
        });
    });

    function done(data) {
        if (data.success) { window.MitSporGenindlaes(); return; }
        showErrors(editErrors, data);
    }
    function failed() { showErrors(editErrors, null); }

    editForm.addEventListener('submit', function (ev) {
        ev.preventDefault();
        editErrors.hidden = true;
        post({
            action: 'update',
            group_id: currentGroupId,
            title: document.getElementById('group-edit-title').value,
            description: document.getElementById('group-edit-description').value
        }).then(done).catch(failed);
    });

    document.getElementById('group-order-save').addEventListener('click', function () {
        editErrors.hidden = true;
        var ids = Array.prototype.map.call(orderList.children, function (li) { return li.getAttribute('data-id'); });
        post({ action: 'reorder', group_id: currentGroupId, route_ids: ids.join(',') }).then(done).catch(failed);
    });

    document.getElementById('group-order-sort-time').addEventListener('click', function () {
        editErrors.hidden = true;
        post({ action: 'sort_by_time', group_id: currentGroupId }).then(done).catch(failed);
    });

    function setVisibility(visibility) {
        editErrors.hidden = true;
        if (visibility === 'public' && !visAck.checked) {
            alert('Husk at krydse boksen af om, at du forstår at start-/slutpunkter kan afsløre din bopæl (GDPR), før du kan gøre gruppen offentlig.');
            return;
        }
        post({
            action: 'set_visibility',
            group_id: currentGroupId,
            visibility: visibility,
            public_notice_ack: visAck.checked ? '1' : '0'
        }).then(done).catch(failed);
    }
    document.getElementById('group-make-public').addEventListener('click', function () { setVisibility('public'); });
    document.getElementById('group-make-private').addEventListener('click', function () { setVisibility('private'); });

    document.getElementById('group-dissolve').addEventListener('click', function () {
        if (!confirm('Opløs gruppen? Ruterne bliver stående som almindelige ruter — kun selve gruppen slettes.')) return;
        post({ action: 'dissolve', group_id: currentGroupId }).then(done).catch(failed);
    });
})();

// ---------------------------------------------------------------
// Liste/detalje: vælg en rute eller gruppe til venstre, se værktøjerne
// til højre. Valget huskes i adressens #hash (fx #route:123), så det
// overlever de sidegenindlæsninger, som handlingerne udløser.
// ---------------------------------------------------------------
(function () {
    "use strict";

    var hub = document.getElementById('hub');
    if (!hub) return;

    var panels = hub.querySelectorAll('[data-detail]');
    var items = hub.querySelectorAll('[data-item]');
    var placeholder = document.getElementById('hub-placeholder');

    function activateTabFor(key) {
        var active = hub.querySelector('.tab-panel:not([hidden]) [data-item="' + key + '"]');
        if (active) return;
        var target = key.indexOf('group:') === 0 ? 'tab-grupper' : 'tab-alle';
        var tabBtn = hub.querySelector('.tab-btn[data-tab-target="' + target + '"]');
        if (tabBtn) tabBtn.click();
    }

    function select(key, scroll) {
        var found = false;
        panels.forEach(function (p) {
            var on = p.getAttribute('data-detail') === key;
            p.hidden = !on;
            if (on) found = true;
        });
        items.forEach(function (li) {
            li.classList.toggle('selected', li.getAttribute('data-item') === key);
        });
        if (placeholder) placeholder.hidden = found;
        hub.classList.toggle('hub--detail-open', found);

        if (found) {
            document.dispatchEvent(new CustomEvent('hub:detail-shown', { detail: { key: key } }));
            activateTabFor(key);
            if (scroll) {
                var li = hub.querySelector('.tab-panel:not([hidden]) [data-item="' + key + '"]');
                if (li && li.scrollIntoView) li.scrollIntoView({ block: 'nearest' });
            }
        }

        try {
            history.replaceState(null, '', found ? '#' + key : location.pathname + location.search);
        } catch (e) { /* ikke kritisk */ }
        return found;
    }

    hub.querySelectorAll('[data-select-item]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            select(btn.getAttribute('data-select-item'), false);
        });
    });

    hub.querySelectorAll('[data-goto]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            select(btn.getAttribute('data-goto'), true);
        });
    });

    var back = document.getElementById('hub-back');
    if (back) back.addEventListener('click', function () { select(null, false); });

    var initial = decodeURIComponent((location.hash || '').replace(/^#/, ''));
    if (initial) {
        if (!select(initial, true)) select(null, false);
    }
})();

// ---------------------------------------------------------------
// Forklaring til værktøjsknapperne (det lille "?" til højre for hver knap)
// ---------------------------------------------------------------
(function () {
    "use strict";

    var dialog = document.getElementById('tool-help-dialog');
    if (!dialog) return;

    var TOOL_HELP = {
        view: {
            title: 'Vis rute på kort',
            text: [
                'Åbner rutens egen side med kortet og de visninger og datafelter, du har valgt.',
                'Det er den side, andre ser, hvis ruten er offentlig eller delt med et link. Pilen øverst til venstre tager dig tilbage hertil.'
            ]
        },
        editor: {
            title: 'Rediger i GPX-editor',
            text: [
                'Åbner editoren, hvor du retter selve ruten: flytter, tilføjer og sletter punkter og indsætter waypoints og billeder.',
                'Husk at trykke Gem i editoren. Titel, synlighed og lignende ændres under Rediger metadata, og hvad rutens side viser, vælger du under Visning og datafelter.'
            ]
        },
        meta: {
            title: 'Rediger metadata',
            text: [
                'Ændrer titel, beskrivelse, aktivitet, om det er en rute eller et spor, om den er privat eller offentlig, om andre må downloade filen, og om dit navn skjules.',
                'Selve GPX-filen og dens punkter ændres ikke.'
            ]
        },
        fields: {
            title: 'Visning og datafelter',
            text: [
                'Vælger, hvad rutens side viser. Fanen Visning styrer de indbyggede visninger (fx højdeprofil, hastighed og puls), og fanen Felter viser alle datafelter i filen.',
                'Her kan du også slette et felts data helt fra filen, fx tidsstempler, og hente højdedata fra internettet. Dine valg gælder først, når du trykker Gem.'
            ]
        },
        thin: {
            title: 'Udtynd punkter',
            text: [
                'Gør filen mindre ved at beholde højst ét punkt pr. 10, 20, 30 eller 60 sekunder. Det er nyttigt, hvis GPS-enheden har logget meget tæt.',
                'Første gang gemmes den oprindelige fil, så du kan fortryde med Gendan original fil. Udtynd punkter findes kun for ruter med tidsstempler.'
            ]
        },
        group: {
            title: 'Grupper',
            text: [
                'Sætter ruten i en gruppe, fx en flerdagstur med én rute pr. etape, eller flytter den til en anden gruppe.',
                'Du kan også oprette en ny gruppe i dialogen. Grupperne findes under fanen Grupper, hvor du kan åbne og ændre dem.'
            ]
        },
        share: {
            title: 'Del-link',
            text: [
                'Opretter et link, så alle, der har det, kan se din private rute uden at logge ind.',
                'Du kan oprette et nyt link, og så holder det gamle op med at virke. Knappen findes kun for private ruter.'
            ]
        },
        download: {
            title: 'Download GPX-fil',
            text: [
                'Henter rutens GPX-fil, som den ligger lige nu, med dine redigeringer og eventuelle udtynding.'
            ]
        },
        restore: {
            title: 'Gendan original fil',
            text: [
                'Erstatter den nuværende fil med den oprindelige fil, som blev gemt, før ruten blev udtyndet.',
                'Ændringer, du har lavet efter udtyndingen (fx rettede punkter), går tabt. Titel og øvrige indstillinger røres ikke, og knappen forsvinder bagefter.'
            ]
        },
        'delete': {
            title: 'Slet',
            text: [
                'Sletter ruten permanent, inklusive GPX-filen og en eventuel oprindelig fil. Ruten forlader sin gruppe, og likes og kommentarer slettes.',
                'Det kan ikke fortrydes.'
            ]
        }
    };

    var titleEl = document.getElementById('tool-help-title');
    var bodyEl = document.getElementById('tool-help-body');

    document.getElementById('tool-help-close').addEventListener('click', function () { dialog.close(); });
    dialog.addEventListener('click', function (ev) {
        if (ev.target === dialog) dialog.close();
    });

    document.querySelectorAll('[data-tool-help]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var entry = TOOL_HELP[btn.getAttribute('data-tool-help')];
            if (!entry) return;

            titleEl.textContent = entry.title;
            bodyEl.innerHTML = '';
            entry.text.forEach(function (paragraph) {
                var p = document.createElement('p');
                p.textContent = paragraph;
                bodyEl.appendChild(p);
            });
            dialog.showModal();
        });
    });
})();

// ---------------------------------------------------------------
// Listen og detaljepanelet bruger hele skærmens højde (minus lidt luft).
// Højden sættes som CSS-variabel, så den følger vinduets størrelse; på
// mobil (liste og detalje efter hinanden) bruges naturlig højde.
// ---------------------------------------------------------------
(function () {
    "use strict";

    var hub = document.getElementById('hub');
    if (!hub) return;

    var BOTTOM_MARGIN = 24;
    var MIN_HEIGHT = 360;
    var narrow = window.matchMedia ? window.matchMedia('(max-width: 800px)') : { matches: false };

    function sizeHub() {
        if (narrow.matches) {
            hub.style.removeProperty('--hub-height');
            return;
        }
        var top = hub.getBoundingClientRect().top + window.pageYOffset;
        var height = Math.max(MIN_HEIGHT, window.innerHeight - top - BOTTOM_MARGIN);
        hub.style.setProperty('--hub-height', height + 'px');
    }

    sizeHub();
    window.addEventListener('resize', sizeHub);
    window.addEventListener('load', sizeHub);
})();

// ---------------------------------------------------------------
// Går brugeren "tilbage" hertil (fx fra editoren), kan browseren genoprette
// siden fra sin hurtigcache med den gamle liste. Indlæs den da igen, så
// nye/ændrede ruter er med. Valget (#route:...) bevares i adressen
// (se assets/genindlaes.js).
// ---------------------------------------------------------------
window.addEventListener('pageshow', function (ev) {
    if (ev.persisted) window.MitSporGenindlaes();
});

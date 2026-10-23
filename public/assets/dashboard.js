/* Kontrolpanelets JavaScript (flyttet ud af dashboard.php). */
// Markørvalget: "Standard" viser markøren for den valgte aktivitet (fra
// <option data-marker-url>), eller "Ingen", hvis aktiviteten ikke har en.
function markerDefaultPreview(select, box) {
    if (!select || !box) return;
    var option = select.options[select.selectedIndex];
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
        markerDefaultPreview(metaActivity, document.getElementById('edit-meta-marker-default'));
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

    // note: forklaringen over gruppens titel; groupTitle: forslag til titlen.
    function setMultiMode(on, note, groupTitle) {
        isMulti = on;
        titleLabel.hidden = on;
        titleInput.required = !on;
        groupFields.hidden = !on;
        groupTitleInput.required = on;
        multiHint.hidden = on || splitParts !== null;
        if (on) {
            multiNote.textContent = note + ' Aktivitet, type og synlighed nedenfor gælder for alle etaperne. Tidsstempler og punkter beholdes uændret.';
            if (!groupTitleInput.value && groupTitle) groupTitleInput.value = groupTitle;
        } else {
            groupTitleInput.value = '';
        }
    }

    // ---------- Én fil med flere spor -> én etape pr. spor ----------
    // Filen deles pr. <trk>/<rte> (ikke pr. <trkseg>, som blot er huller i
    // GPS-signalet). Hver del beholder filens rodelement (navnerum,
    // Garmin-udvidelser), <metadata> uden <bounds> og de waypoints, der
    // ligger nærmest netop det spor.
    var splitParts = null; // [{ title, content }] når den valgte fil har flere spor
    var splitGroupTitle = '';
    var splitFields = document.getElementById('upload-split-fields');
    var splitLegend = document.getElementById('upload-split-legend');
    var splitYes = document.getElementById('upload-split-yes');

    function childElements(el, name) {
        return Array.prototype.filter.call(el.children, function (c) { return c.localName === name; });
    }

    function linePoints(line) {
        var tag = line.localName === 'rte' ? 'rtept' : 'trkpt';
        return Array.prototype.filter.call(line.getElementsByTagNameNS('*', tag), function (p) {
            return p.hasAttribute('lat') && p.hasAttribute('lon');
        }).map(function (p) {
            return { lat: parseFloat(p.getAttribute('lat')), lon: parseFloat(p.getAttribute('lon')) };
        });
    }

    // Kvadratet på en tilnærmet afstand: nok til at finde det nærmeste spor.
    function nearestLineIndex(wpt, lines) {
        var lat = parseFloat(wpt.getAttribute('lat'));
        var lon = parseFloat(wpt.getAttribute('lon'));
        if (isNaN(lat) || isNaN(lon)) return 0;
        var k = Math.cos(lat * Math.PI / 180);
        var best = 0, bestD = Infinity;
        lines.forEach(function (line, i) {
            line.points.forEach(function (p) {
                var dLat = p.lat - lat, dLon = (p.lon - lon) * k;
                var d = dLat * dLat + dLon * dLon;
                if (d < bestD) { bestD = d; best = i; }
            });
        });
        return best;
    }

    // Returnerer null, hvis filen ikke har mindst to spor/ruter med punkter.
    function splitGpx(text, fileBaseName) {
        var doc = new DOMParser().parseFromString(text, 'application/xml');
        if (doc.getElementsByTagName('parsererror').length) return null;
        var root = doc.documentElement;
        if (!root || root.localName !== 'gpx') return null;

        var lines = Array.prototype.filter.call(root.children, function (c) {
            return c.localName === 'trk' || c.localName === 'rte';
        }).map(function (el) {
            return { el: el, points: linePoints(el) };
        }).filter(function (line) { return line.points.length >= 2; });
        if (lines.length < 2) return null;

        var metadata = childElements(root, 'metadata')[0] || null;
        var metaName = metadata ? childElements(metadata, 'name')[0] : null;
        splitGroupTitle = (metaName && metaName.textContent.trim()) || fileBaseName;

        var wptsByLine = lines.map(function () { return []; });
        childElements(root, 'wpt').forEach(function (w) {
            wptsByLine[nearestLineIndex(w, lines)].push(w);
        });

        var serializer = new XMLSerializer();
        return lines.map(function (line, i) {
            var partDoc = document.implementation.createDocument(null, null, null);
            var partRoot = partDoc.importNode(root, false);
            partDoc.appendChild(partRoot);
            if (metadata) {
                var meta = partDoc.importNode(metadata, true);
                childElements(meta, 'bounds').forEach(function (b) { meta.removeChild(b); });
                partRoot.appendChild(meta);
            }
            wptsByLine[i].forEach(function (w) { partRoot.appendChild(partDoc.importNode(w, true)); });
            partRoot.appendChild(partDoc.importNode(line.el, true));

            var nameEl = childElements(line.el, 'name')[0];
            var title = (nameEl && nameEl.textContent.trim()) || (fileBaseName + ' ' + (i + 1));
            return {
                title: title.slice(0, 200),
                content: '<?xml version="1.0" encoding="UTF-8"?>\n' + serializer.serializeToString(partDoc)
            };
        });
    }

    function splitNote() {
        var names = splitParts.map(function (p) { return p.title; });
        var shown = names.slice(0, 5).join(', ') + (names.length > 5 ? ' …' : '');
        return 'De ' + splitParts.length + ' spor oprettes som etaper i en ny gruppe, med sporets navn som titel (' + shown + '). Du kan omdøbe dem bagefter.';
    }

    function applySplitChoice() {
        if (splitParts && splitYes.checked) {
            setMultiMode(true, splitNote(), splitGroupTitle);
        } else {
            setMultiMode(false);
        }
    }
    document.getElementById('upload-split-yes').addEventListener('change', applySplitChoice);
    document.getElementById('upload-split-no').addEventListener('change', applySplitChoice);

    function clearSplit() {
        splitParts = null;
        splitGroupTitle = '';
        splitFields.hidden = true;
        splitYes.checked = true;
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
        clearSplit();
        setMultiMode(true, files.length + ' filer valgt. De oprettes som etaper i en ny gruppe, og hver etape får filnavnet som titel (du kan omdøbe dem bagefter).');
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

    // Flere filer: én etape pr. fil, sorteret efter filnavn.
    function submitMulti() {
        var files = Array.prototype.slice.call(fileInput.files).sort(function (a, b) {
            return a.name.localeCompare(b.name, 'da', { numeric: true });
        });
        return submitParts(files.map(function (f) {
            return {
                label: 'Filen "' + f.name + '"',
                title: f.name.replace(/\.gpx$/i, ''),
                read: function () { return readFileText(f); }
            };
        }));
    }

    // Én fil med flere spor: én etape pr. spor, i filens rækkefølge.
    function submitSplit() {
        return submitParts(splitParts.map(function (p) {
            return {
                label: 'Sporet "' + p.title + '"',
                title: p.title,
                read: function () { return Promise.resolve(p.content); }
            };
        }));
    }

    // Uploader delene som ruter og samler dem i en ny gruppe.
    async function submitParts(parts) {
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
            marker_icon: selectedUploadMarker()
        };

        submitBtn.disabled = true;
        progressEl.hidden = false;
        var created = [];
        var failure = null;

        for (var i = 0; i < parts.length; i++) {
            var part = parts[i];
            progressEl.textContent = 'Uploader ' + (i + 1) + ' af ' + parts.length + ': ' + part.title;
            try {
                var content = await part.read();
                var body = new URLSearchParams(Object.assign({}, common, {
                    gpx_data: content,
                    title: part.title
                }));
                var res = await fetch('editor-save.php', { method: 'POST', body: body });
                var data = await res.json();
                if (!res.ok || !data.success) {
                    throw new Error((data.errors && data.errors.join(' ')) || 'Ukendt fejl.');
                }
                created.push(data.route_id);
            } catch (e) {
                failure = { label: part.label, message: e.message };
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
                    failure = failure || { label: '', message: (g.errors && g.errors.join(' ')) || 'Gruppen kunne ikke oprettes.' };
                }
            } catch (e) {
                failure = failure || { label: '', message: 'Gruppen kunne ikke oprettes.' };
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
            (failure.label ? failure.label + ' kunne ikke uploades: ' : '') + failure.message +
            (created.length > 0
                ? ' ' + created.length + ' af ' + parts.length + ' etaper er oprettet. Luk dialogen for at se dem i listen.'
                : '')
        );
    }

    var noticeCheckbox = document.getElementById('upload-public-notice-ack');
    var routeTypeRuteRadio = document.getElementById('upload-route-type-rute');
    var routeTypeSporRadio = document.getElementById('upload-route-type-spor');
    var fileHasTimeData = false;
    var userChoseRouteType = false;

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

    // Markøren gælder alle etaper ved upload af flere filer/spor.
    var uploadActivity = document.getElementById('upload-activity');
    function selectedUploadMarker() {
        var checked = form.querySelector('input[name="marker_icon"]:checked');
        return checked ? checked.value : '';
    }
    function updateUploadDefaultMarker() {
        markerDefaultPreview(uploadActivity, document.getElementById('upload-marker-default'));
    }
    uploadActivity.addEventListener('change', updateUploadDefaultMarker);

    function resetDialog() {
        form.reset();
        updateUploadDefaultMarker();
        form.hidden = false;
        errorsEl.hidden = true;
        // Formularen er lige nulstillet til default (privat) — så
        // GDPR-boksen skal ikke forblive forudfyldt fra en tidligere upload.
        metaBehavior.setLoadedVisibility('private');
        pendingGpxContent = null;
        fileHasTimeData = false;
        metaBehavior.setHasTimeData(false);
        userChoseRouteType = false;
        clearSplit();
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
        clearSplit();
        setMultiMode(false);
        var baseName = fileInput.files[0].name.replace(/\.gpx$/i, '');
        if (!titleInput.value) {
            titleInput.value = baseName;
        }
        var reader = new FileReader();
        reader.onload = function () {
            // Flere spor i filen? Så foreslås (forvalgt) én etape pr. spor.
            try {
                splitParts = splitGpx(reader.result, baseName);
            } catch (e) {
                splitParts = null;
            }
            if (splitParts) {
                splitLegend.textContent = 'Filen indeholder ' + splitParts.length + ' spor';
                splitFields.hidden = false;
                applySplitChoice();
            }
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

    function submitUpload() {
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
            marker_icon: selectedUploadMarker()
        });

        fetch('editor-save.php', { method: 'POST', body: body })
            .then(function (res) { return res.json().then(function (data) { return { status: res.status, data: data }; }); })
            .then(function (result) {
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
        if (isMulti && splitParts) {
            submitSplit();
            return;
        }

        var reader = new FileReader();
        reader.onload = function () {
            pendingGpxContent = reader.result;
            submitUpload();
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

// Slet en hel gruppe med alle etaper (bekræftes med gruppens navn).
(function () {
    "use strict";
    var dialog = document.getElementById('delete-group-dialog');
    if (!dialog) return;
    var form = document.getElementById('delete-group-form');
    var errorsEl = document.getElementById('delete-group-errors');
    var titleEl = document.getElementById('delete-group-title');
    var countEl = document.getElementById('delete-group-count');
    var groupIdField = document.getElementById('delete-group-id');
    var confirmField = document.getElementById('delete-group-confirm-title');
    var submitBtn = document.getElementById('delete-group-submit-btn');
    var mismatchEl = document.getElementById('delete-group-mismatch');
    var expectedTitle = '';

    function updateSubmitState() {
        var typed = confirmField.value.trim();
        submitBtn.disabled = typed !== expectedTitle;
        mismatchEl.hidden = typed === '' || typed === expectedTitle;
    }

    document.querySelectorAll('[data-delete-group]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            errorsEl.hidden = true;
            form.reset();
            groupIdField.value = btn.getAttribute('data-delete-group');
            expectedTitle = btn.getAttribute('data-group-title').trim();
            var n = parseInt(btn.getAttribute('data-stage-count'), 10) || 0;
            titleEl.textContent = expectedTitle;
            countEl.textContent = n === 1 ? 'dens ene etape' : 'alle dens ' + n + ' etaper';
            updateSubmitState();
            dialog.showModal();
        });
    });

    confirmField.addEventListener('input', updateSubmitState);

    document.getElementById('delete-group-close').addEventListener('click', function () { dialog.close(); });
    dialog.addEventListener('click', function (ev) {
        if (ev.target === dialog) {
            dialog.close();
        }
    });

    function fail(data) {
        errorsEl.textContent = (data && data.errors && data.errors.join(' ')) || 'Der skete en fejl. Prøv igen.';
        errorsEl.hidden = false;
        updateSubmitState();
    }

    form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        errorsEl.hidden = true;
        submitBtn.disabled = true;

        fetch('group-action.php', {
            method: 'POST',
            body: new URLSearchParams(new FormData(form))
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.success) {
                    dialog.close();
                    // Gruppen findes ikke længere, så valget i adressen ryddes.
                    if (location.hash.indexOf('#group:') === 0) {
                        history.replaceState(null, '', location.pathname + location.search);
                    }
                    window.MitSporGenindlaes();
                    return;
                }
                fail(data);
            })
            .catch(function () { fail(null); });
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
            // Hold eventuelle kopier af samme afkrydsning ens.
            document.querySelectorAll('.route-select[data-select-route="' + id + '"]').forEach(function (other) {
                other.checked = cb.checked;
            });
            updateSelectionUi();
        });
    });

    // Valgte ruter i den rækkefølge, de står i listen (ikke JS' nøgle-rækkefølge).
    function selectedIdsInListOrder() {
        // Spor og ruter står i hver sin fane; data-order er pladsen i den samlede liste.
        return Array.prototype.filter.call(document.querySelectorAll('.route-select'), function (cb) {
            return cb.checked;
        }).sort(function (a, b) {
            return Number(a.getAttribute('data-order')) - Number(b.getAttribute('data-order'));
        }).map(function (cb) { return cb.getAttribute('data-select-route'); });
    }

    // ---------- "Gruppér valgte" ----------
    var createDialog = document.getElementById('group-create-dialog');
    var createForm = document.getElementById('group-create-form');
    var createErrors = document.getElementById('group-create-errors');
    var newBox = document.getElementById('group-create-new');
    var existingBox = document.getElementById('group-create-existing');
    bindDialogClose(createDialog, document.getElementById('group-create-close'));

    btnGroup.addEventListener('click', function () {
        createErrors.hidden = true;
        document.getElementById('group-create-summary').textContent = selectedIdsInListOrder().length + ' rute(r) valgt.';
        createDialog.showModal();
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
    var visPublic = document.getElementById('group-vis-public');
    var visPrivate = document.getElementById('group-vis-private');
    var visMixed = document.getElementById('group-vis-mixed');
    var visAck = document.getElementById('group-vis-ack');
    var currentGroupId = null;
    var loadedVisibility = null; // 'public', 'private' eller null (blandet)
    var loadedOrder = '';        // etapernes id'er, som de var ved åbning
    var loadedIds = [];

    // Alle brugerens ruter (fra dashboard.php) til "Tilføj etaper".
    var catalog = [];
    try { catalog = JSON.parse(document.getElementById('group-route-catalog').textContent); } catch (e) { /* tom liste */ }
    var addToggle = document.getElementById('group-add-toggle');
    var addBox = document.getElementById('group-add-box');
    var addFilter = document.getElementById('group-add-filter');
    var addList = document.getElementById('group-add-list');

    // Samme opførsel som synligheden i "Rediger metadata": GDPR-boksen vises
    // ved Offentlig og er forudfyldt, hvis alle etaper allerede er offentlige.
    var visBehavior = RouteMetaDialog.init({
        visibilityPublic: 'group-vis-public',
        visibilityPrivate: 'group-vis-private',
        publicNotice: 'group-vis-notice',
        publicNoticeAck: 'group-vis-ack'
    });
    var sortable = null;
    bindDialogClose(editDialog, document.getElementById('group-edit-close'));

    function renderOrder(routes) {
        orderList.innerHTML = '';
        routes.forEach(function (r) { orderList.appendChild(stageItem(r, false)); });

        if (!sortable && window.Sortable) {
            sortable = window.Sortable.create(orderList, { animation: 150, handle: '.grip' });
        }
    }

    // Én etape i listen. isNew: tilføjet i denne omgang (gemmes først ved Gem).
    function stageItem(r, isNew) {
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

        if (isNew) {
            var nyt = document.createElement('span');
            nyt.className = 'go-new';
            nyt.textContent = 'ny';
            li.appendChild(nyt);
        }
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

        var rm = document.createElement('button');
        rm.type = 'button';
        rm.className = 'link-btn go-remove';
        rm.setAttribute('data-remove', '1');
        rm.setAttribute('aria-label', 'Fjern ' + r.title + ' fra gruppen');
        rm.title = 'Fjern fra gruppen';
        rm.textContent = '\u00d7';
        li.appendChild(rm);

        return li;
    }

    // ---------- Tilføj etaper ----------
    function stageIds() {
        return Array.prototype.map.call(orderList.children, function (li) { return li.getAttribute('data-id'); });
    }

    function renderCandidates() {
        var inGroup = stageIds();
        var q = addFilter.value.trim().toLowerCase();
        var rows = catalog.filter(function (r) {
            return inGroup.indexOf(r.id) === -1 && (!q || r.title.toLowerCase().indexOf(q) !== -1);
        });
        addList.innerHTML = '';
        if (rows.length === 0) {
            var none = document.createElement('li');
            none.className = 'hint';
            none.textContent = q ? 'Ingen ruter matcher.' : 'Alle dine ruter er allerede med i gruppen.';
            addList.appendChild(none);
            return;
        }
        rows.forEach(function (r) {
            var li = document.createElement('li');
            var b = document.createElement('button');
            b.type = 'button';
            b.setAttribute('data-add-id', r.id);
            var plus = document.createElement('span');
            plus.className = 'ga-plus';
            plus.textContent = '+';
            plus.setAttribute('aria-hidden', 'true');
            var title = document.createElement('span');
            title.className = 'ga-title';
            title.textContent = r.title;
            b.appendChild(plus);
            b.appendChild(title);
            // Står den i en anden gruppe, flyttes den — det skal man kunne se.
            if (r.group && r.group !== currentGroupId) {
                var g = document.createElement('span');
                g.className = 'ga-group';
                g.textContent = 'i ' + r.groupTitle;
                b.appendChild(g);
            }
            li.appendChild(b);
            addList.appendChild(li);
        });
    }

    addToggle.addEventListener('click', function () {
        addBox.hidden = !addBox.hidden;
        addToggle.setAttribute('aria-expanded', addBox.hidden ? 'false' : 'true');
        if (!addBox.hidden) {
            renderCandidates();
            addFilter.focus();
        }
    });
    addFilter.addEventListener('input', renderCandidates);
    // Enter i søgefeltet må ikke gemme hele formularen.
    addFilter.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') ev.preventDefault(); });

    addList.addEventListener('click', function (ev) {
        var btn = ev.target.closest('[data-add-id]');
        if (!btn) return;
        var id = btn.getAttribute('data-add-id');
        var r = catalog.filter(function (c) { return c.id === id; })[0];
        if (!r) return;
        var li = stageItem(r, loadedIds.indexOf(id) === -1);
        orderList.appendChild(li);
        li.scrollIntoView({ block: 'nearest' });
        renderCandidates();
    });

    orderList.addEventListener('click', function (ev) {
        var rm = ev.target.closest('[data-remove]');
        if (rm) {
            rm.closest('li').remove();
            if (!addBox.hidden) renderCandidates();
            return;
        }
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
                    var pub = data.routes.filter(function (r) { return r.visibility === 'public'; }).length;
                    loadedVisibility = pub === data.routes.length ? 'public' : (pub === 0 ? 'private' : null);
                    visPublic.checked = loadedVisibility === 'public';
                    visPrivate.checked = loadedVisibility === 'private';
                    visMixed.hidden = loadedVisibility !== null;
                    visBehavior.setLoadedVisibility(loadedVisibility === 'public' ? 'public' : 'private');
                    renderOrder(data.routes);
                    loadedOrder = currentOrder();
                    loadedIds = stageIds();
                    addFilter.value = '';
                    addBox.hidden = true;
                    addToggle.setAttribute('aria-expanded', 'false');
                    editDialog.showModal();
                });
        });
    });

    function done(data) {
        if (data.success) { window.MitSporGenindlaes(); return; }
        showErrors(editErrors, data);
    }
    function failed() { showErrors(editErrors, null); }

    function currentOrder() {
        return Array.prototype.map.call(orderList.children, function (li) { return li.getAttribute('data-id'); }).join(',');
    }

    // "Gem": titel og beskrivelse, nye og fjernede etaper, rækkefølgen, hvis
    // den er ændret, og synligheden, hvis der er valgt en anden end den,
    // etaperne har nu (gælder også nye etaper).
    editForm.addEventListener('submit', function (ev) {
        ev.preventDefault();
        editErrors.hidden = true;
        var ids = stageIds();
        var added = ids.filter(function (id) { return loadedIds.indexOf(id) === -1; });
        var removed = loadedIds.filter(function (id) { return ids.indexOf(id) === -1; });

        // Uden etaper findes gruppen ikke længere — det svarer til at opløse den.
        if (ids.length === 0) {
            if (!confirm('Du har fjernet alle etaper. Gruppen slettes, men ruterne bliver stående som almindelige ruter. Fortsæt?')) return;
            post({ action: 'dissolve', group_id: currentGroupId }).then(done).catch(failed);
            return;
        }

        var visibility = visPublic.checked ? 'public' : (visPrivate.checked ? 'private' : null);
        var changeVisibility = visibility !== null && visibility !== loadedVisibility;
        if (changeVisibility && !visBehavior.requireGdprAckIfPublic()) return;

        // Hvert trin kører kun, hvis det forrige lykkedes.
        function then(chain, step) {
            return chain.then(function (data) { return data.success ? step() : data; });
        }

        var chain = post({
            action: 'update',
            group_id: currentGroupId,
            title: document.getElementById('group-edit-title').value,
            description: document.getElementById('group-edit-description').value
        });
        if (added.length) {
            chain = then(chain, function () {
                return post({ action: 'add_routes', group_id: currentGroupId, route_ids: added.join(',') });
            });
        }
        removed.forEach(function (id) {
            chain = then(chain, function () { return post({ action: 'assign_route', route_id: id, group_id: '' }); });
        });
        chain.then(function (data) {
            var order = currentOrder();
            if (!data.success || order === loadedOrder) return data;
            return post({ action: 'reorder', group_id: currentGroupId, route_ids: order });
        }).then(function (data) {
            if (!data.success || !changeVisibility) return data;
            return post({
                action: 'set_visibility',
                group_id: currentGroupId,
                visibility: visibility,
                public_notice_ack: visAck.checked ? '1' : '0'
            });
        }).then(done).catch(failed);
    });

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
        var li = hub.querySelector('.tab-panel [data-item="' + key + '"]');
        var panel = li && li.closest('.tab-panel');
        if (!panel) return;
        var tabBtn = hub.querySelector('.tab-btn[data-tab-target="' + panel.id + '"]');
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
                'Husk at trykke Gem i editoren. Titel, synlighed og lignende ændres under Rediger metadata, og hvad rutens side viser, vælger du under Visning af datafelter.'
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
            title: 'Visning af datafelter',
            text: [
                'Vælger, hvad rutens side viser: de indbyggede visninger (fx højdeprofil, hastighed og puls) og øvrige felter i filen, der kan vises som tal.',
                'Filen ændres ikke. Dine valg gælder først, når du trykker Gem.'
            ]
        },
        strip: {
            title: 'Slet datafelter',
            text: [
                'Viser de datafelter, filen indeholder (fx tid, højde, puls og kadence), og fjerner et felts data helt fra filen. Så er det heller ikke med i den fil, andre kan downloade.',
                'Visninger, der bygger på feltet, forsvinder fra rutens side. Sletter du fx tiden, forsvinder hastighed og tid i bevægelse. Ændringen gemmes først, når du trykker Gem.'
            ]
        },
        ele: {
            title: 'Rediger højdedata',
            text: [
                'Viser, om ruten har højdedata, og rutens laveste og højeste punkt samt samlet stigning og fald.',
                'Du kan hente højden for alle punkter fra internettet (en højdemodel med 30 m opløsning). Det overskriver de højder, filen har, og er ofte mere præcist end GPS\'ens egen højde. Du kan også slette al højdedata fra filen.',
                'Ændringen gemmes først, når du trykker Gem.'
            ]
        },
        thin: {
            title: 'Udtynd punkter',
            text: [
                'Gør filen mindre ved at fjerne punkter, der ikke ændrer sporets form. Lige strækninger mister mange punkter, mens sving, slingrende stier og bakker bevares. Let, Middel og Kraftig tillader 2, 5 og 10 meters afvigelse fra det oprindelige spor.',
                'Der bliver højst 50 m og 60 sekunder mellem de punkter, der er tilbage, så højdeprofil og fart stadig kan vises. Før noget gemmes, ser du, hvor mange punkter der fjernes.',
                'Første gang gemmes den oprindelige fil, så du kan fortryde med Gendan original fil.'
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
            // Video med det relevante stykke (afspilles af assets/videoer.js).
            if (entry.video) {
                var vbtn = document.createElement('button');
                vbtn.type = 'button';
                vbtn.className = 'video-btn';
                vbtn.setAttribute('data-video', entry.video[0]);
                if (entry.video[1] > 0) vbtn.setAttribute('data-start', String(entry.video[1]));
                vbtn.setAttribute('data-title', entry.video[2]);
                vbtn.innerHTML = '<span class="video-btn-icon" aria-hidden="true">▶</span>';
                vbtn.appendChild(document.createTextNode('Se video'));
                bodyEl.appendChild(vbtn);
            }
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

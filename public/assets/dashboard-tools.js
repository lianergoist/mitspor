/* ------------------------------------------------------------------
   Kontrolpanelets rute-værktøj "Visning af datafelter".

   Det henter ruten (editor-load.php), finder filens felter med GpxTools
   (assets/gpx-tools.js) og gemmer valget via editor-save.php. Titel,
   synlighed osv. sendes ikke med — serveren beholder dem.
   "Slet datafelter", "Rediger højdedata" og "Udtynd punkter" ligger i
   editorens menu (editor/app.js).
------------------------------------------------------------------- */
(function () {
    "use strict";

    var csrfField = document.getElementById("upload-csrf-token");
    if (!csrfField || !window.GpxTools) return;
    var csrf = csrfField.value;
    var G = window.GpxTools;

    // ---------- Fælles ----------
    function loadRoute(publicId) {
        return fetch("editor-load.php?id=" + encodeURIComponent(publicId))
            .then(function (res) { return res.json().then(function (data) { return { res: res, data: data }; }); })
            .then(function (r) {
                if (!r.res.ok || !r.data.success) {
                    throw new Error((r.data.errors && r.data.errors.join(" ")) || "Kunne ikke hente ruten.");
                }
                return r.data;
            });
    }

    function saveRoute(params) {
        params.csrf_token = csrf;
        return fetch("editor-save.php", { method: "POST", body: new URLSearchParams(params) })
            .then(function (res) { return res.json().then(function (data) { return { res: res, data: data }; }); })
            .then(function (r) {
                if (!r.res.ok || !r.data.success) {
                    throw new Error((r.data.errors && r.data.errors.join(" ")) || "Kunne ikke gemme ruten (status " + r.res.status + ").");
                }
                return r.data;
            });
    }

    function bindDialogClose(dialog, closeBtn) {
        closeBtn.addEventListener("click", function () { dialog.close(); });
        dialog.addEventListener("click", function (ev) {
            if (ev.target === dialog) dialog.close();
        });
    }

    function setText(el, text) {
        el.textContent = text || "";
        el.hidden = !text;
    }

    function make(tag, className, text) {
        var el = document.createElement(tag);
        if (className) el.className = className;
        if (text !== undefined) el.textContent = text;
        return el;
    }

    function labelFor(field) {
        return window.gpxFieldLabel ? window.gpxFieldLabel(field) : field;
    }

    function checkboxRow(attrName, attrValue, text, checked) {
        var label = make("label", "checkbox-field");
        var input = document.createElement("input");
        input.type = "checkbox";
        input.setAttribute(attrName, attrValue);
        input.checked = checked;
        label.appendChild(input);
        label.appendChild(document.createTextNode(" " + text));
        return label;
    }

    // ================================================================
    // Visning af datafelter (indbyggede visninger + øvrige felter som tal)
    // ================================================================
    (function () {
        var dialog = document.getElementById("tool-fields-dialog");
        if (!dialog) return;

        var titleEl = document.getElementById("tool-fields-title");
        var errorsEl = document.getElementById("tool-fields-errors");
        var statusEl = document.getElementById("tool-fields-status");
        var bodyEl = document.getElementById("tool-fields-body");
        var functionsList = document.getElementById("tool-functions-list");
        var rawBox = document.getElementById("tool-fields-raw");
        var fieldsList = document.getElementById("tool-fields-list");
        var saveBtn = document.getElementById("tool-fields-save");

        bindDialogClose(dialog, document.getElementById("tool-fields-close"));

        // Den rute, dialogen er åben på. Kun valgene gemmes; filen ændres ikke.
        var st = null; // { id, enabledFields, enabledFunctions }

        function renderFunctions(detected) {
            functionsList.innerHTML = "";
            var available = G.availableFunctions(detected);

            // Position/afstand er ikke en tilvalgs-funktion — vises altid —
            // men står på listen, så de ikke ser ud til at mangle.
            ["Positionsdata (kort) — vises altid", "Afstand fra start — vises altid"].forEach(function (text) {
                var li = make("li");
                var label = checkboxRow("data-always", "1", text, true);
                label.querySelector("input").disabled = true;
                li.appendChild(label);
                functionsList.appendChild(li);
            });

            if (available.length === 0) {
                functionsList.appendChild(make("li", "hint", "Ruten indeholder ikke data til andre indbyggede visninger."));
                return;
            }

            // Er der ikke taget stilling endnu, er de nyere funktioner (tid/gennemsnit i
            // bevægelse, laveste/højeste hastighed) slået fra som udgangspunkt.
            var enabled = st.enabledFunctions !== null
                ? new Set(st.enabledFunctions)
                : new Set(available
                    .filter(function (fn) { return window.gpxFunctionDefaultEnabled ? window.gpxFunctionDefaultEnabled(fn.key) : true; })
                    .map(function (fn) { return fn.key; }));

            available.forEach(function (fn) {
                var li = make("li");
                li.appendChild(checkboxRow("data-tool-function", fn.key, fn.label, enabled.has(fn.key)));
                functionsList.appendChild(li);
            });
        }

        // Felter, som ingen indbygget visning bruger (puls har fx sin graf,
        // men en temperatur har ingen), kan vises som tal på rutens side.
        function renderFields(detected) {
            fieldsList.innerHTML = "";

            var functionFields = new Set();
            G.FUNCTIONS.forEach(function (fn) { fn.requires.forEach(function (f) { functionFields.add(f); }); });
            var rows = detected.filter(function (f) { return !functionFields.has(f); });
            rawBox.hidden = rows.length === 0;

            var enabled = st.enabledFields !== null
                ? new Set(st.enabledFields)
                : new Set(rows.filter(function (f) {
                    return window.gpxFieldDefaultEnabled ? window.gpxFieldDefaultEnabled(f) : true;
                }));

            rows.forEach(function (field) {
                var li = make("li");
                li.appendChild(checkboxRow("data-tool-field", field, labelFor(field), enabled.has(field)));
                fieldsList.appendChild(li);
            });
        }

        saveBtn.addEventListener("click", function () {
            if (!st) return;
            setText(errorsEl, "");

            var params = { route_id: st.id, settings_only: "1" };
            params.enabled_functions = JSON.stringify(Array.prototype.filter.call(
                functionsList.querySelectorAll("input[data-tool-function]"), function (c) { return c.checked; }
            ).map(function (c) { return c.getAttribute("data-tool-function"); }));
            // Øvrige felter sendes kun, når der er nogen (ellers bevares de gemte valg).
            var fieldInputs = fieldsList.querySelectorAll("input[data-tool-field]");
            if (fieldInputs.length) {
                params.enabled_data_fields = JSON.stringify(Array.prototype.filter.call(fieldInputs, function (c) { return c.checked; })
                    .map(function (c) { return c.getAttribute("data-tool-field"); }));
            }

            saveBtn.disabled = true;
            setText(statusEl, "Gemmer…");
            saveRoute(params)
                .then(function () { window.MitSporGenindlaes(); })
                .catch(function (e) {
                    setText(statusEl, "");
                    setText(errorsEl, e.message || "Der skete en fejl. Prøv igen.");
                    saveBtn.disabled = false;
                });
        });

        document.querySelectorAll("[data-tool-fields]").forEach(function (btn) {
            btn.addEventListener("click", function () {
                var id = btn.getAttribute("data-tool-fields");
                st = null;
                titleEl.textContent = btn.getAttribute("data-route-title");
                setText(errorsEl, "");
                setText(statusEl, "Henter ruten…");
                bodyEl.hidden = true;
                saveBtn.disabled = true;
                dialog.showModal();

                loadRoute(id)
                    .then(function (data) {
                        st = {
                            id: id,
                            enabledFields: Array.isArray(data.enabled_data_fields) ? data.enabled_data_fields : null,
                            enabledFunctions: Array.isArray(data.enabled_functions)
                                ? (window.gpxExpandFunctions ? window.gpxExpandFunctions(data.enabled_functions) : data.enabled_functions)
                                : null
                        };
                        var detected = G.detectFields(G.parse(data.gpx_content));
                        renderFunctions(detected);
                        renderFields(detected);
                        bodyEl.hidden = false;
                        saveBtn.disabled = false;
                        setText(statusEl, "");
                    })
                    .catch(function (e) {
                        setText(statusEl, "");
                        setText(errorsEl, e.message || "Kunne ikke hente ruten.");
                    });
            });
        });
    })();
})();

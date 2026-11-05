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

    // ================================================================
    // Datafelter og markør for alle etaper i en gruppe (fanen Grupper).
    // Etaperne hentes én ad gangen (editor-load.php) for at finde felterne.
    // Gemmes via group-display-settings.php og overskriver etapernes valg.
    // ================================================================
    (function () {
        var dialog = document.getElementById("group-display-dialog");
        if (!dialog) return;

        var titleEl = document.getElementById("group-display-title");
        var errorsEl = document.getElementById("group-display-errors");
        var statusEl = document.getElementById("group-display-status");
        var warningEl = document.getElementById("group-display-warning");
        var bodyEl = document.getElementById("group-display-body");
        var introEl = document.getElementById("group-display-intro");
        var applyFields = document.getElementById("group-display-apply-fields");
        var applyMarkers = document.getElementById("group-display-apply-markers");
        var fieldsBox = document.getElementById("group-display-fields-box");
        var functionsList = document.getElementById("group-display-functions");
        var rawBox = document.getElementById("group-display-raw");
        var fieldsList = document.getElementById("group-display-fields");
        var markersBox = document.getElementById("group-display-markers-box");
        var template = document.getElementById("group-display-marker-template");
        var saveBtn = document.getElementById("group-display-save");

        bindDialogClose(dialog, document.getElementById("group-display-close"));

        var st = null;        // { groupId, stages: [...] }
        var confirming = false; // andet tryk på Gem bekræfter
        var loadToken = 0;      // ignorér svar fra en tidligere åbning

        function stageText(n) { return n === 1 ? "1 etape" : n + " etaper"; }

        function resetConfirm() {
            confirming = false;
            setText(warningEl, "");
            if (st) saveBtn.textContent = "💾 Gem for alle " + stageText(st.stages.length);
            saveBtn.disabled = !st || (!applyFields.checked && !applyMarkers.checked);
        }

        applyFields.addEventListener("change", function () { fieldsBox.hidden = !applyFields.checked; resetConfirm(); });
        applyMarkers.addEventListener("change", function () { markersBox.hidden = !applyMarkers.checked; resetConfirm(); });
        dialog.addEventListener("change", function (ev) {
            if (ev.target !== applyFields && ev.target !== applyMarkers && confirming) resetConfirm();
        });

        // Funktion/felt er slået til på en etape: etapens eget valg, ellers standarden.
        function functionOn(stage, key) {
            if (stage.enabledFunctions !== null) return stage.enabledFunctions.indexOf(key) !== -1;
            return window.gpxFunctionDefaultEnabled ? window.gpxFunctionDefaultEnabled(key) : true;
        }
        function fieldOn(stage, field) {
            if (stage.enabledFields !== null) return stage.enabledFields.indexOf(field) !== -1;
            return window.gpxFieldDefaultEnabled ? window.gpxFieldDefaultEnabled(field) : true;
        }

        // Alle visninger/felter, som mindst én etape har. Et punkt er afkrydset,
        // når det er slået til på ALLE de etaper, der har det (så fx puls, som
        // én etape har skjult, ikke slås til uden at man ser det).
        function renderFields() {
            functionsList.innerHTML = "";
            fieldsList.innerHTML = "";

            ["Positionsdata (kort) — vises altid", "Afstand fra start — vises altid"].forEach(function (text) {
                var li = make("li");
                var label = checkboxRow("data-always", "1", text, true);
                label.querySelector("input").disabled = true;
                li.appendChild(label);
                functionsList.appendChild(li);
            });

            var fnMap = {};   // key -> { fn, on }
            var fnOrder = [];
            var functionFields = new Set();
            G.FUNCTIONS.forEach(function (fn) { fn.requires.forEach(function (f) { functionFields.add(f); }); });
            var rawMap = {};  // felt -> on
            var rawOrder = [];

            st.stages.forEach(function (stage) {
                G.availableFunctions(stage.detected).forEach(function (fn) {
                    if (!fnMap[fn.key]) { fnMap[fn.key] = { fn: fn, on: true }; fnOrder.push(fn.key); }
                    if (!functionOn(stage, fn.key)) fnMap[fn.key].on = false;
                });
                stage.detected.forEach(function (f) {
                    if (functionFields.has(f)) return;
                    if (!(f in rawMap)) { rawMap[f] = true; rawOrder.push(f); }
                    if (!fieldOn(stage, f)) rawMap[f] = false;
                });
            });

            if (fnOrder.length === 0) {
                functionsList.appendChild(make("li", "hint", "Etaperne indeholder ikke data til andre indbyggede visninger."));
            }
            fnOrder.forEach(function (key) {
                var li = make("li");
                li.appendChild(checkboxRow("data-group-function", key, fnMap[key].fn.label, fnMap[key].on));
                functionsList.appendChild(li);
            });

            rawBox.hidden = rawOrder.length === 0;
            rawOrder.forEach(function (f) {
                var li = make("li");
                li.appendChild(checkboxRow("data-group-field", f, labelFor(f), rawMap[f]));
                fieldsList.appendChild(li);
            });
        }

        // Ét markørvalg pr. aktivitet i gruppen (klonet fra skabelonen).
        // Kun aktivitetens markører og de neutrale vises. Er alle etaper med
        // aktiviteten ens i dag, er deres markør valgt; ellers Standard.
        function renderMarkers() {
            markersBox.innerHTML = "";
            var activities = [];
            var byId = {};
            st.stages.forEach(function (s) {
                var a = byId[s.activity_id];
                if (!a) {
                    a = byId[s.activity_id] = { id: s.activity_id, name: s.activity_name, folder: s.activity_folder, url: s.activity_marker_url, markers: [], count: 0 };
                    activities.push(a);
                }
                a.count++;
                if (a.markers.indexOf(s.marker_icon) === -1) a.markers.push(s.marker_icon);
            });

            activities.forEach(function (a) {
                var picker = template.content.firstElementChild.cloneNode(true);
                picker.setAttribute("data-group-activity", String(a.id));
                var legend = picker.querySelector("legend");
                legend.textContent = activities.length > 1
                    ? "Markør for " + (a.name || "anden aktivitet").toLowerCase() + " (" + stageText(a.count) + ")"
                    : "Markør på kortet";

                var name = "group_marker_" + a.id;
                picker.querySelectorAll("input[type=radio]").forEach(function (r) { r.name = name; r.checked = false; });

                picker.querySelectorAll(".marker-option[data-marker-activity]").forEach(function (label) {
                    var act = label.getAttribute("data-marker-activity");
                    label.hidden = !(act === "" || act === a.folder);
                });

                var box = picker.querySelector("#group-display-marker-default");
                box.removeAttribute("id");
                box.textContent = "";
                if (a.url) {
                    var img = document.createElement("img");
                    img.src = a.url;
                    img.alt = "";
                    box.appendChild(img);
                } else {
                    box.textContent = "Ingen";
                }

                var current = a.markers.length === 1 ? a.markers[0] : "";
                var radio = picker.querySelector('input[value="' + CSS.escape(current) + '"]');
                if (!radio || radio.closest(".marker-option").hidden) radio = picker.querySelector('input[value=""]');
                radio.checked = true;

                if (a.markers.length > 1) {
                    picker.appendChild(make("p", "hint", "Etaperne har forskellige markører i dag."));
                }
                markersBox.appendChild(picker);
            });
        }

        function chosenList(list, attr) {
            return Array.prototype.filter.call(list.querySelectorAll("input[" + attr + "]"), function (c) { return c.checked; })
                .map(function (c) { return c.getAttribute(attr); });
        }

        saveBtn.addEventListener("click", function () {
            if (!st || (!applyFields.checked && !applyMarkers.checked)) return;
            setText(errorsEl, "");

            // Første tryk viser, hvad der sker; andet tryk gemmer.
            if (!confirming) {
                var what = applyFields.checked && applyMarkers.checked ? "datafelter og markør"
                    : (applyFields.checked ? "datafelter" : "markør");
                setText(warningEl, "Dette ændrer " + what + " på alle " + stageText(st.stages.length) +
                    " og overskriver etapernes egne valg. Tryk igen for at gemme.");
                saveBtn.textContent = "Ja, overskriv " + stageText(st.stages.length);
                confirming = true;
                return;
            }

            var params = { csrf_token: csrf, group_id: st.groupId };
            if (applyFields.checked) {
                params.apply_fields = "1";
                params.enabled_functions = JSON.stringify(chosenList(functionsList, "data-group-function"));
                // Øvrige felter sendes kun, når der er nogen (ellers bevares etapernes valg).
                if (fieldsList.querySelector("input[data-group-field]")) {
                    params.enabled_data_fields = JSON.stringify(chosenList(fieldsList, "data-group-field"));
                }
            }
            if (applyMarkers.checked) {
                var markers = {};
                markersBox.querySelectorAll("[data-group-activity]").forEach(function (picker) {
                    var checked = picker.querySelector("input[type=radio]:checked");
                    markers[picker.getAttribute("data-group-activity")] = checked ? checked.value : "";
                });
                params.apply_markers = "1";
                params.markers = JSON.stringify(markers);
            }

            saveBtn.disabled = true;
            setText(warningEl, "");
            setText(statusEl, "Gemmer…");
            fetch("group-display-settings.php", { method: "POST", body: new URLSearchParams(params) })
                .then(function (res) { return res.json().then(function (data) { return { res: res, data: data }; }); })
                .then(function (r) {
                    if (!r.res.ok || !r.data.success) {
                        throw new Error((r.data.errors && r.data.errors.join(" ")) || "Kunne ikke gemme (status " + r.res.status + ").");
                    }
                    window.MitSporGenindlaes();
                })
                .catch(function (e) {
                    setText(statusEl, "");
                    setText(errorsEl, e.message || "Der skete en fejl. Prøv igen.");
                    resetConfirm();
                });
        });

        document.querySelectorAll("[data-group-display]").forEach(function (btn) {
            btn.addEventListener("click", function () {
                var groupId = btn.getAttribute("data-group-display");
                var token = ++loadToken;
                st = null;
                titleEl.textContent = btn.getAttribute("data-group-title");
                setText(errorsEl, "");
                setText(warningEl, "");
                setText(statusEl, "Henter gruppen…");
                bodyEl.hidden = true;
                saveBtn.disabled = true;
                saveBtn.textContent = "💾 Gem for alle etaper";
                applyFields.checked = true;
                applyMarkers.checked = true;
                fieldsBox.hidden = false;
                markersBox.hidden = false;
                dialog.showModal();

                fetch("group-display-settings.php?group_id=" + encodeURIComponent(groupId))
                    .then(function (res) { return res.json().then(function (data) { return { res: res, data: data }; }); })
                    .then(function (r) {
                        if (!r.res.ok || !r.data.success) {
                            throw new Error((r.data.errors && r.data.errors.join(" ")) || "Kunne ikke hente gruppen.");
                        }
                        if (!r.data.stages.length) throw new Error("Gruppen har ingen etaper.");
                        var stages = r.data.stages;

                        // Etaperne én ad gangen, så store grupper ikke hentes på én gang.
                        var chain = Promise.resolve();
                        stages.forEach(function (stage, i) {
                            chain = chain.then(function () {
                                if (token !== loadToken) throw new Error("afbrudt");
                                setText(statusEl, "Henter etape " + (i + 1) + " af " + stages.length + "…");
                                return loadRoute(stage.id).then(function (data) {
                                    stage.detected = G.detectFields(G.parse(data.gpx_content));
                                    stage.enabledFields = Array.isArray(data.enabled_data_fields) ? data.enabled_data_fields : null;
                                    stage.enabledFunctions = Array.isArray(data.enabled_functions)
                                        ? (window.gpxExpandFunctions ? window.gpxExpandFunctions(data.enabled_functions) : data.enabled_functions)
                                        : null;
                                });
                            });
                        });
                        return chain.then(function () { return stages; });
                    })
                    .then(function (stages) {
                        if (token !== loadToken) return;
                        st = { groupId: groupId, stages: stages };
                        introEl.textContent = "Valgene gælder alle " + stageText(stages.length) +
                            " i gruppen og overskriver etapernes egne valg. Filerne ændres ikke.";
                        renderFields();
                        renderMarkers();
                        bodyEl.hidden = false;
                        setText(statusEl, "");
                        resetConfirm();
                    })
                    .catch(function (e) {
                        if (token !== loadToken) return;
                        setText(statusEl, "");
                        setText(errorsEl, e.message || "Kunne ikke hente gruppen.");
                    });
            });
        });
    })();
})();

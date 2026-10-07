/* ------------------------------------------------------------------
   Kontrolpanelets rute-værktøjer: "Udtynd punkter" og "Visning og datafelter".

   Begge henter ruten (editor-load.php), ændrer den i browseren med
   GpxTools (assets/gpx-tools.js) og gemmer via editor-save.php. Titel,
   synlighed osv. sendes ikke med — serveren beholder dem.
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

    // ================================================================
    // Udtynd punkter
    // ================================================================
    (function () {
        var dialog = document.getElementById("tool-thin-dialog");
        if (!dialog) return;
        var titleEl = document.getElementById("tool-thin-title");
        var errorsEl = document.getElementById("tool-thin-errors");
        var statusEl = document.getElementById("tool-thin-status");
        var buttons = dialog.querySelectorAll("[data-thin-interval]");
        var currentId = null;

        bindDialogClose(dialog, document.getElementById("tool-thin-close"));

        function setBusy(busy) {
            buttons.forEach(function (b) { b.disabled = busy; });
        }

        document.querySelectorAll("[data-tool-thin]").forEach(function (btn) {
            btn.addEventListener("click", function () {
                currentId = btn.getAttribute("data-tool-thin");
                titleEl.textContent = btn.getAttribute("data-route-title");
                setText(errorsEl, "");
                setText(statusEl, "");
                setBusy(false);
                dialog.showModal();
            });
        });

        function run(interval) {
            setText(errorsEl, "");
            setText(statusEl, "Henter ruten…");
            setBusy(true);

            var gpx = null;
            var plan = null;

            loadRoute(currentId)
                .then(function (data) {
                    gpx = G.parse(data.gpx_content);

                    if (!G.hasTimeData(gpx)) {
                        setText(statusEl, "Ruten har ingen tidsstempler — den kan ikke udtyndes efter tid.");
                        return null;
                    }

                    plan = G.thinPlan(gpx, interval);
                    if (plan.total === 0) {
                        setText(statusEl, "Ingen punkter blev fjernet — de har allerede mindst " + interval + " sekunders mellemrum.");
                        return null;
                    }

                    var before = G.pointCount(gpx);
                    var ok = window.confirm(
                        "Fjern " + plan.total + " af " + before + " punkter, så der højst gemmes ét punkt hvert " + interval + ". sekund?\n\n" +
                        "Den oprindelige fil gemmes, og kan gendannes med \"Gendan original fil\"."
                    );
                    if (!ok) {
                        setText(statusEl, "");
                        return null;
                    }

                    G.applyThin(gpx, plan);
                    setText(statusEl, "Gemmer…");

                    return saveRoute({
                        route_id: currentId,
                        gpx_data: G.serialize(gpx),
                        thin_interval: String(interval),
                        time_resolution: "keep"
                    }).then(function () { return true; });
                })
                .then(function (saved) {
                    if (saved) window.location.reload();
                })
                .catch(function (e) {
                    setText(statusEl, "");
                    setText(errorsEl, e.message || "Der skete en fejl. Prøv igen.");
                })
                .then(function () { setBusy(false); });
        }

        buttons.forEach(function (b) {
            b.addEventListener("click", function () {
                run(parseInt(b.getAttribute("data-thin-interval"), 10));
            });
        });
    })();

    // ================================================================
    // Visning og datafelter (visninger + felter + højdedata)
    // ================================================================
    (function () {
        var dialog = document.getElementById("tool-fields-dialog");
        if (!dialog) return;

        var titleEl = document.getElementById("tool-fields-title");
        var errorsEl = document.getElementById("tool-fields-errors");
        var statusEl = document.getElementById("tool-fields-status");
        var bodyEl = document.getElementById("tool-fields-body");
        var functionsList = document.getElementById("tool-functions-list");
        var fieldsList = document.getElementById("tool-fields-list");
        var saveBtn = document.getElementById("tool-fields-save");
        var tabs = dialog.querySelectorAll("[data-fields-tab]");
        var panels = dialog.querySelectorAll("[data-fields-panel]");

        bindDialogClose(dialog, document.getElementById("tool-fields-close"));

        // Arbejdstilstand for den rute, dialogen er åben på. Ændringer i
        // selve filen (slet felt, hent højdedata) og valgene i listerne
        // gemmes først, når brugeren trykker Gem.
        var st = null; // { id, gpx, enabledFields, enabledFunctions, changed }

        function showTab(name) {
            tabs.forEach(function (t) { t.classList.toggle("active", t.getAttribute("data-fields-tab") === name); });
            panels.forEach(function (p) { p.hidden = p.getAttribute("data-fields-panel") !== name; });
        }
        tabs.forEach(function (t) {
            t.addEventListener("click", function () { showTab(t.getAttribute("data-fields-tab")); });
        });

        function setBusy(busy) {
            saveBtn.disabled = busy;
            dialog.querySelectorAll("[data-delete-field], [data-fetch-field]").forEach(function (b) { b.disabled = busy; });
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

        // Læser afkrydsningerne tilbage i arbejdstilstanden (så valg overlever en gentegning).
        function syncFromDom() {
            var fn = functionsList.querySelectorAll("input[data-tool-function]");
            if (fn.length) {
                st.enabledFunctions = Array.prototype.filter.call(fn, function (c) { return c.checked; })
                    .map(function (c) { return c.getAttribute("data-tool-function"); });
            }
            var fd = fieldsList.querySelectorAll("input[data-tool-field]");
            if (fd.length) {
                st.enabledFields = Array.prototype.filter.call(fd, function (c) { return c.checked; })
                    .map(function (c) { return c.getAttribute("data-tool-field"); });
            }
        }

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
                functionsList.appendChild(make("li", "hint", "Ruten indeholder ikke data til andre indbyggede visninger endnu."));
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

        function renderFields(detected) {
            fieldsList.innerHTML = "";

            var functionFields = new Set();
            G.FUNCTIONS.forEach(function (fn) { fn.requires.forEach(function (f) { functionFields.add(f); }); });

            // Højdedata kan HENTES fra internettet, uanset om filen allerede har nogen —
            // så "Højde" er altid en mulighed, og står øverst.
            var rows = detected.slice();
            if (G.pointCount(st.gpx) > 0 && rows.indexOf("ele") === -1) rows.push("ele");
            var eleIndex = rows.indexOf("ele");
            if (eleIndex > 0) rows.unshift(rows.splice(eleIndex, 1)[0]);

            if (rows.length === 0) {
                fieldsList.appendChild(make("li", "hint", "Ruten indeholder ingen øvrige datafelter."));
                return;
            }

            var enabled = st.enabledFields !== null
                ? new Set(st.enabledFields)
                : new Set(detected.filter(function (f) {
                    return !functionFields.has(f) && (window.gpxFieldDefaultEnabled ? window.gpxFieldDefaultEnabled(f) : true);
                }));

            rows.forEach(function (field) {
                var hasData = detected.indexOf(field) !== -1;
                var text = labelFor(field);
                var li = make("li", "data-field-row");

                if (!hasData) {
                    var none = make("span", "data-field-note", text + " ");
                    none.appendChild(make("small", null, "(ingen data endnu)"));
                    li.appendChild(none);
                } else if (functionFields.has(field)) {
                    var note = make("span", "data-field-note", text + " ");
                    note.appendChild(make("small", null, "(styres under Visning)"));
                    li.appendChild(note);
                } else {
                    li.appendChild(checkboxRow("data-tool-field", field, text, enabled.has(field)));
                }

                if (field === "ele") {
                    var fetchBtn = make("button", "link-btn", "🌐");
                    fetchBtn.type = "button";
                    fetchBtn.title = "Hent højdedata fra internettet";
                    fetchBtn.setAttribute("data-fetch-field", field);
                    fetchBtn.addEventListener("click", fetchElevation);
                    li.appendChild(fetchBtn);
                }
                if (hasData) {
                    var delBtn = make("button", "link-btn danger", "🗑");
                    delBtn.type = "button";
                    delBtn.title = "Slet al \"" + text + "\"-data fra ruten";
                    delBtn.setAttribute("data-delete-field", field);
                    delBtn.addEventListener("click", function () { deleteField(field); });
                    li.appendChild(delBtn);
                }

                fieldsList.appendChild(li);
            });
        }

        function render() {
            var detected = G.detectFields(st.gpx);
            renderFunctions(detected);
            renderFields(detected);
        }

        function deleteField(field) {
            syncFromDom();
            var label = labelFor(field);
            var count = G.detectFields(st.gpx).indexOf(field) === -1 ? 0 : 1;
            if (!count) return;
            if (!window.confirm("Fjern alle \"" + label + "\"-værdier fra ruten? Ændringen gemmes først, når du trykker Gem.")) return;

            var removed = G.stripField(st.gpx, field);
            st.changed = true;
            render();
            setText(statusEl, "Fjernede " + removed + " \"" + label + "\"-værdi(er). Husk at trykke Gem.");
        }

        function fetchElevation() {
            syncFromDom();
            var points = G.pointCount(st.gpx);
            if (points === 0) {
                setText(statusEl, "Ruten har ingen punkter at hente højdedata til.");
                return;
            }
            var hadEle = G.detectFields(st.gpx).indexOf("ele") !== -1;
            var ok = window.confirm(
                "Hent højdedata fra internettet til alle " + points + " punkter?" +
                (hadEle ? " Det OVERSKRIVER den eksisterende højdedata." : "") +
                " Ændringen gemmes først, når du trykker Gem."
            );
            if (!ok) return;

            var availBefore = G.availableFunctions(G.detectFields(st.gpx)).map(function (f) { return f.key; });
            setBusy(true);
            setText(errorsEl, "");

            G.fetchElevation(st.gpx, {
                proxyUrl: "elevation-proxy.php",
                onProgress: function (done, total) { setText(statusEl, "Henter højdedata… (" + done + "/" + total + ")"); }
            })
                .then(function (result) {
                    st.changed = true;

                    // Funktioner, der først nu har data (fx højdeprofil), slås til, så
                    // brugeren faktisk får noget ud af at have hentet dataen.
                    if (st.enabledFunctions !== null) {
                        G.availableFunctions(G.detectFields(st.gpx)).forEach(function (fn) {
                            if (availBefore.indexOf(fn.key) === -1 && st.enabledFunctions.indexOf(fn.key) === -1) {
                                st.enabledFunctions.push(fn.key);
                            }
                        });
                    }

                    render();
                    setText(statusEl, "Højdedata hentet for " + result.updated + " af " + result.total + " punkter. Husk at trykke Gem.");
                })
                .catch(function (e) {
                    setText(statusEl, "");
                    setText(errorsEl, "Fejl ved hentning af højdedata: " + e.message);
                })
                .then(function () { setBusy(false); });
        }

        saveBtn.addEventListener("click", function () {
            if (!st) return;
            syncFromDom();
            setText(errorsEl, "");

            var params = { route_id: st.id };
            if (st.changed) {
                params.gpx_data = G.serialize(st.gpx);
            } else {
                params.settings_only = "1";
            }
            if (st.enabledFields !== null) params.enabled_data_fields = JSON.stringify(st.enabledFields);
            if (st.enabledFunctions !== null) params.enabled_functions = JSON.stringify(st.enabledFunctions);

            setBusy(true);
            setText(statusEl, "Gemmer…");
            saveRoute(params)
                .then(function () { window.location.reload(); })
                .catch(function (e) {
                    setText(statusEl, "");
                    setText(errorsEl, e.message || "Der skete en fejl. Prøv igen.");
                    setBusy(false);
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
                showTab("functions");
                dialog.showModal();

                loadRoute(id)
                    .then(function (data) {
                        st = {
                            id: id,
                            gpx: G.parse(data.gpx_content),
                            enabledFields: Array.isArray(data.enabled_data_fields) ? data.enabled_data_fields : null,
                            enabledFunctions: Array.isArray(data.enabled_functions)
                                ? (window.gpxExpandFunctions ? window.gpxExpandFunctions(data.enabled_functions) : data.enabled_functions)
                                : null,
                            changed: false
                        };
                        render();
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

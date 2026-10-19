/* ------------------------------------------------------------------
   Livesendinger i kontrolpanelet (fanen "Live"): opret og ret i en
   dialog, kopiér link, afslut, nyt link og slet. Alt går til
   live-action.php.
------------------------------------------------------------------- */
(function () {
    "use strict";

    var dialog = document.getElementById("live-edit-dialog");
    if (!dialog) return;

    var form = document.getElementById("live-edit-form");
    var errorsBox = document.getElementById("live-edit-errors");
    var heading = document.getElementById("live-edit-heading");
    var planSelect = document.getElementById("live-edit-plan");
    var planKeep = document.getElementById("live-edit-plan-keep");
    var planHint = document.getElementById("live-edit-plan-hint");
    var visPrivate = document.getElementById("live-vis-private");
    var visPublic = document.getElementById("live-vis-public");
    var visNotice = document.getElementById("live-vis-notice");
    var visAck = document.getElementById("live-vis-ack");
    var csrfToken = form.querySelector('input[name="csrf_token"]').value;
    var wasPublic = false;

    function showErrors(box, errors) {
        box.innerHTML = "";
        (errors || ["Der skete en fejl. Prøv igen."]).forEach(function (msg) {
            var p = document.createElement("p");
            p.textContent = msg;
            box.appendChild(p);
        });
        box.hidden = false;
    }

    function post(body) {
        return fetch("live-action.php", { method: "POST", body: body, credentials: "same-origin" })
            .then(function (res) { return res.json(); });
    }

    function closeOnBackdrop(d) {
        d.addEventListener("click", function (ev) {
            if (ev.target === d) d.close();
        });
    }

    // Advarslen skal kun krydses af, når livesendingen bliver offentlig.
    function updateNotice() {
        var show = visPublic.checked && !wasPublic;
        visNotice.hidden = !show;
        visAck.required = show;
        if (!show) visAck.checked = false;
    }
    visPrivate.addEventListener("change", updateNotice);
    visPublic.addEventListener("change", updateNotice);

    // Tom data-live-edit = ny livesending; ellers JSON med de nuværende værdier.
    document.querySelectorAll("[data-live-edit]").forEach(function (btn) {
        btn.addEventListener("click", function () {
            var raw = btn.getAttribute("data-live-edit");
            var ev = raw ? JSON.parse(raw) : null;

            form.reset();
            errorsBox.hidden = true;
            heading.textContent = ev ? "Rediger livesending" : "Ny livesending";
            document.getElementById("live-edit-id").value = ev ? ev.id : "";
            document.getElementById("live-edit-title").value = ev ? ev.title : "";
            document.getElementById("live-edit-description").value = ev ? ev.description : "";
            if (ev) {
                document.getElementById("live-edit-activity").value = String(ev.activity_type_id);
                document.getElementById("live-edit-delay").value = String(ev.delay_minutes);
                document.getElementById("live-edit-start").value = ev.planned_start_at;
            }
            wasPublic = !!ev && ev.visibility === "public";
            (wasPublic ? visPublic : visPrivate).checked = true;
            updateNotice();

            // En ny livesending har ingen plan at beholde, og en afsluttet kan ikke få ny.
            planKeep.hidden = !ev;
            planKeep.disabled = !ev;
            planSelect.value = ev ? "keep" : "none";
            planSelect.disabled = !!ev && ev.finished;
            planKeep.textContent = ev && ev.planned_count > 0
                ? "Behold den nuværende plan (" + ev.planned_count + (ev.planned_count === 1 ? " planlagt etape)" : " planlagte etaper)")
                : "Behold (ingen planlagte etaper)";
            planHint.textContent = ev && ev.finished
                ? "Livesendingen er afsluttet, så planen kan ikke ændres."
                : "Etaper, der allerede er startet, røres ikke.";

            dialog.showModal();
        });
    });

    document.getElementById("live-edit-close").addEventListener("click", function () { dialog.close(); });
    closeOnBackdrop(dialog);

    form.addEventListener("submit", function (ev) {
        ev.preventDefault();
        var body = new FormData(form);
        if (planSelect.disabled) body.set("plan", "keep");
        post(body)
            .then(function (data) {
                if (!data.success) {
                    showErrors(errorsBox, data.errors);
                    return;
                }
                // Vis den gemte livesending, når siden er hentet igen.
                try { history.replaceState(null, "", "#live:" + data.event_id); } catch (e) { /* ikke kritisk */ }
                window.MitSporGenindlaes();
            })
            .catch(function () { showErrors(errorsBox, null); });
    });

    document.querySelectorAll("[data-live-copy]").forEach(function (btn) {
        btn.addEventListener("click", function () {
            var input = btn.closest(".detail-panel").querySelector("[data-live-url]");
            var done = function () {
                btn.textContent = "Kopieret";
                setTimeout(function () { btn.textContent = "Kopiér link"; }, 2000);
            };
            if (navigator.clipboard) {
                navigator.clipboard.writeText(input.value).then(done, function () { input.select(); });
            } else {
                input.select();
            }
        });
    });

    // Afslut og nyt link: bekræft, send, hent siden igen.
    document.querySelectorAll("[data-live-action]").forEach(function (btn) {
        btn.addEventListener("click", function () {
            if (!window.confirm(btn.getAttribute("data-live-confirm"))) return;
            var body = new URLSearchParams({
                csrf_token: csrfToken,
                action: btn.getAttribute("data-live-action"),
                event_id: btn.getAttribute("data-live-id")
            });
            post(body)
                .then(function (data) {
                    if (!data.success) {
                        window.alert((data.errors || ["Der skete en fejl. Prøv igen."]).join("\n"));
                        return;
                    }
                    window.MitSporGenindlaes();
                })
                .catch(function () { window.alert("Der skete en fejl. Prøv igen."); });
        });
    });

    var delDialog = document.getElementById("live-delete-dialog");
    var delForm = document.getElementById("live-delete-form");
    var delErrors = document.getElementById("live-delete-errors");
    var delInput = document.getElementById("live-delete-confirm-title");
    var delSubmit = document.getElementById("live-delete-submit-btn");
    var delTitle = "";

    document.querySelectorAll("[data-live-delete]").forEach(function (btn) {
        btn.addEventListener("click", function () {
            delTitle = btn.getAttribute("data-live-title");
            document.getElementById("live-delete-id").value = btn.getAttribute("data-live-delete");
            document.getElementById("live-delete-title").textContent = delTitle;
            delInput.value = "";
            delSubmit.disabled = true;
            delErrors.hidden = true;
            delDialog.showModal();
        });
    });
    delInput.addEventListener("input", function () {
        delSubmit.disabled = delInput.value.trim() !== delTitle;
    });
    document.getElementById("live-delete-close").addEventListener("click", function () { delDialog.close(); });
    closeOnBackdrop(delDialog);

    delForm.addEventListener("submit", function (ev) {
        ev.preventDefault();
        post(new FormData(delForm))
            .then(function (data) {
                if (!data.success) {
                    showErrors(delErrors, data.errors);
                    return;
                }
                try { history.replaceState(null, "", location.pathname + location.search); } catch (e) { /* ikke kritisk */ }
                window.MitSporGenindlaes();
            })
            .catch(function () { showErrors(delErrors, null); });
    });
})();

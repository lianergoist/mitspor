/**
 * Delt adfærd for metadata-redigeringsdialogen (titel, aktivitet, type,
 * synlighed, download-tilladelse, anonym-uploader, GDPR-boks).
 *
 * Bruges to steder med hver deres element-id'er og markup (kontrolpanelets
 * "Rediger metadata"-dialog i dashboard.php, og GPX-editorens tilsvarende
 * dialog i editor/app.js) — id'erne sendes ind via `ids`, så begge sider
 * kan beholde deres egen navngivning. Modulet håndterer KUN felt-synlighed
 * og GDPR-flowet omkring synlighed; selve hentning/afsendelse af data
 * varetages af den side, der bruger modulet.
 *
 * ids:
 *   routeTypeRute     - id på radioknappen for route_type="rute" (valgfri)
 *   visibilityPublic   - id på radioknappen for visibility="public"
 *   visibilityPrivate  - id på radioknappen for visibility="private"
 *   allowDownload      - id på download-tilladelse-checkboksen
 *   publicNotice       - id på GDPR-notitsfeltet (valgfri)
 *   anonymousField     - id på "vær anonym"-feltet (valgfri)
 *   timeInRuteNotice   - id på advarslen om tidsstempler i en downloadbar Rute (valgfri)
 *   publicNoticeAck    - id på selve GDPR-afkrydsningsboksen (valgfri)
 */
window.RouteMetaDialog = (function () {
    "use strict";

    function init(ids) {
        var routeTypeRute = ids.routeTypeRute ? document.getElementById(ids.routeTypeRute) : null;
        var publicRadio = document.getElementById(ids.visibilityPublic);
        var privateRadio = document.getElementById(ids.visibilityPrivate);
        var allowDownload = ids.allowDownload ? document.getElementById(ids.allowDownload) : null;
        var noticeField = ids.publicNotice ? document.getElementById(ids.publicNotice) : null;
        var anonField = ids.anonymousField ? document.getElementById(ids.anonymousField) : null;
        var timeInRuteNotice = ids.timeInRuteNotice ? document.getElementById(ids.timeInRuteNotice) : null;
        var ackCheckbox = ids.publicNoticeAck ? document.getElementById(ids.publicNoticeAck) : null;

        // true hvis ruten ALLEREDE var offentlig, sidst formularen blev
        // udfyldt fra serveren — bruges til at afgøre, om GDPR-boksen skal
        // nulstilles ved skift til offentlig, eller om den må forblive
        // forudfyldt/afkrydset (så man ikke skal bekræfte igen, hver gang
        // man blot åbner dialogen for en rute, der allerede er offentlig).
        var originallyPublic = false;

        // Sættes udefra via setHasTimeData() — om selve GPX-filen rent
        // faktisk indeholder tidsstempler. time-in-rute-notitsen skal kun
        // vises, hvis det er sandt, uanset de øvrige felters værdier.
        var hasTimeData = false;

        function isPublic() {
            return !!(publicRadio && publicRadio.checked);
        }

        function updateNoticeVisibility() {
            if (noticeField) noticeField.hidden = !isPublic();
            if (anonField) anonField.hidden = !isPublic();
        }

        function updateTimeInRuteNotice() {
            if (!timeInRuteNotice) return;
            var isRute = !routeTypeRute || routeTypeRute.checked;
            timeInRuteNotice.hidden = !(isRute && isPublic() && allowDownload && allowDownload.checked && hasTimeData);
        }

        function refresh() {
            updateNoticeVisibility();
            updateTimeInRuteNotice();
        }

        // Kaldes KUN ved et faktisk brugerklik på en synligheds-radioknap
        // (ikke ved programmatisk udfyldning via setLoadedVisibility).
        function handleVisibilityChange() {
            if (isPublic() && !originallyPublic && ackCheckbox) {
                ackCheckbox.checked = false;
            }
            refresh();
        }

        if (publicRadio) publicRadio.addEventListener("change", handleVisibilityChange);
        if (privateRadio) privateRadio.addEventListener("change", handleVisibilityChange);
        if (routeTypeRute) {
            document.querySelectorAll('input[name="' + routeTypeRute.name + '"]').forEach(function (r) {
                r.addEventListener("change", updateTimeInRuteNotice);
            });
        }
        if (allowDownload) allowDownload.addEventListener("change", updateTimeInRuteNotice);

        return {
            // Kaldes når formularen udfyldes med værdier fra serveren/lokal
            // state (dvs. IKKE ved et brugerklik) — sætter referencepunktet
            // for "var ruten allerede offentlig?", og opdaterer felt-synlighed.
            setLoadedVisibility: function (visibility) {
                originallyPublic = visibility === "public";
                if (ackCheckbox) ackCheckbox.checked = originallyPublic;
                refresh();
            },
            // Kaldes med en boolean for, om den aktuelle GPX-fil/state
            // faktisk indeholder tidsstempler — afgør (sammen med de
            // øvrige felter) om time-in-rute-notitsen skal vises.
            setHasTimeData: function (value) {
                hasTimeData = !!value;
                updateTimeInRuteNotice();
            },
            // Kaldes hvis andre felter (route_type, download-tilladelse osv.)
            // ændres programmatisk uden om de vante change-events.
            refresh: refresh,
            // Returnerer false (og advarer brugeren) hvis ruten er sat til
            // offentlig, men GDPR-boksen ikke er afkrydset endnu.
            requireGdprAckIfPublic: function () {
                if (isPublic() && ackCheckbox && !ackCheckbox.checked) {
                    alert("Husk at krydse boksen af om, at du forstår at start-/slutpunkter kan afsløre din bopæl (GDPR), før du kan gemme ruten som offentlig.");
                    return false;
                }
                return true;
            }
        };
    }

    return { init: init };
})();

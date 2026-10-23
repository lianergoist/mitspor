/**
 * Danske navne for kendte GPX-/extension-datafelter (fx <hr>, <cad>,
 * <power> fra Garmin/Suunto/Wahoo m.fl.). Bruges både af editorens
 * kontrolpanelets "Visning af datafelter"- og "Slet datafelter"-dialoger og af rutevisningens rå felt-statistik.
 *
 * Et felt, der ikke findes her, er ikke en fejl — det vises bare med
 * sit eget, rå navn fra filen i stedet (se gpxFieldLabel()).
 */
window.GPX_FIELD_LABELS = {
    time: "Tid",
    ele: "Højde",
    hr: "Puls",
    heartrate: "Puls",
    cad: "Kadence",
    cadence: "Kadence",
    atemp: "Temperatur",
    temp: "Temperatur",
    power: "Watt (effekt)",
    speed: "Hastighed (fra fil)",
    course: "Kurs",
    bearing: "Kurs",
    sat: "Satellitter",
    hdop: "GPS-nøjagtighed (HDOP)",
    vdop: "GPS-nøjagtighed (VDOP)",
    pdop: "GPS-nøjagtighed (PDOP)",
    geoidheight: "Geoide-højde",
    fix: "GPS fix-type",
    magvar: "Magnetisk misvisning"
};

// Returnerer det danske navn for et kendt felt, ellers feltets eget,
// rå navn fra filen uændret.
window.gpxFieldLabel = function (name) {
    return window.GPX_FIELD_LABELS[name.toLowerCase()] || name;
};

// Felter der IKKE droppes (de kan stadig vælges til), men som er
// forudkrydset FRA som udgangspunkt — enten fordi de sjældent er
// relevante for en almindelig bruger (GPS-metadata) eller fordi de
// kan skabe forvirring ved siden af en anden, indbygget beregning
// (speed — vi udregner altid selv hastighed ud fra tid/position,
// uafhængigt af om filen selv indeholder en <speed>-værdi).
// Alt andet — også felter vi slet ikke kender navnet på — er
// forudkrydset TIL som udgangspunkt.
window.GPX_FIELDS_DEFAULT_OFF = ['geoidheight', 'speed', 'sat', 'hdop', 'vdop', 'pdop', 'fix', 'magvar', 'src'];

window.gpxFieldDefaultEnabled = function (name) {
    return window.GPX_FIELDS_DEFAULT_OFF.indexOf(name.toLowerCase()) === -1;
};

// ---------- Indbyggede visnings-funktioner: tid & hastighed ----------
// Tidligere var "Tid", "Hastighed" og "Gennemsnit" ét samlet valg
// ('time_speed'). De er nu hver deres funktion, så ejeren kan vælge
// dem enkeltvis — og der er kommet nye til (tid/gennemsnit i bevægelse,
// laveste/højeste hastighed).
//
// Gamle ruter, der har gemt 'time_speed', udvides automatisk til de tre
// funktioner, som den nøgle før styrede (se gpxExpandFunctions()).
window.GPX_FUNCTION_LEGACY_ALIASES = {
    time_speed: ['time_total', 'speed_current', 'speed_avg']
};

// Funktioner der er slået FRA som udgangspunkt, når ejeren endnu ikke
// har taget stilling (enabled_functions = NULL) — så eksisterende ruter
// ikke pludselig får fire ekstra statistik-linjer.
window.GPX_FUNCTIONS_DEFAULT_OFF = ['time_moving', 'speed_avg_moving', 'speed_min', 'speed_max'];

window.gpxFunctionDefaultEnabled = function (key) {
    return window.GPX_FUNCTIONS_DEFAULT_OFF.indexOf(key) === -1;
};

// Udvider forældede funktionsnøgler til deres nuværende erstatninger.
// Returnerer en ny liste uden dubletter.
window.gpxExpandFunctions = function (list) {
    var out = [];
    (list || []).forEach(function (key) {
        var expanded = window.GPX_FUNCTION_LEGACY_ALIASES[key] || [key];
        expanded.forEach(function (k) {
            if (out.indexOf(k) === -1) out.push(k);
        });
    });
    return out;
};

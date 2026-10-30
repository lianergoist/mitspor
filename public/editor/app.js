/* ------------------------------------------------------------------
   GPX-editor (service for kontrolpanelet)

   Editoren er ikke en selvstændig side: ruter åbnes, uploades og får
   rettet metadata i kontrolpanelet (dashboard.php). Editoren åbnes
   enten med ?route=<id> (rediger punkter, billeder og waypoints på en
   eksisterende rute) eller ?new=1 (tegn en ny rute).

   - En ny rute findes kun i hukommelsen, indtil brugeren klikker Gem
     første gang. Da åbnes en lille dialog med titel/type/synlighed, og
     ruten oprettes via editor-save.php. Derefter skifter adressen til
     ?route=<id>, og alle senere Gem er almindelige opdateringer.
   - Ved en eksisterende rute sendes ingen metadata ved Gem —
     editor-save.php beholder dem, der allerede ligger i databasen.
     Menuen (☰) har "Rediger metadata", som gemmer metadata for sig selv
     via update-route-metadata.php, og værktøjerne "Slet datafelter",
     "Rediger højdedata" og "Udtynd punkter", der ændrer ruten her i
     editoren som almindelige ændringer, man kan fortryde og gemme.
   - Nye, håndtegnede punkter får IKKE noget tidsstempel (undgår
     misvisende hastighedsdata for ruter der ikke er GPS-optaget)
   - Har en rute mere end THIN_SUGGEST_POINTS punkter, spørges der om
     udtynding, før den tegnes (se askThin())
------------------------------------------------------------------- */

(function () {
  "use strict";

  // ---------- Globalt state ----------
  let map, trackLayer, wptLayer;
  let wptMarkers = {};
  let state = null;
  let historyStack = [];
  let redoStack = [];
  const MAX_HISTORY = 50;
  let geometryDirty = false; // ændringer i geometri/waypoints siden sidste server-gem
  let metaConfirmed = false; // kun for en NY rute: true når brugeren har udfyldt/bekræftet metadata-dialogen
  let waypointPlacementMode = false; // se btn-waypoint-mode / map.on("click")
  let suppressNextMapClick = false; // se map.on("click") og btnWaypointMode-handleren
  let currentFilename = null;
  let currentRouteId = null; // null = ny, endnu ikke gemt rute på platformen
  let thinnedSinceSave = false; // udtyndet i editoren: næste gem beder serveren gemme originalen
  let routeMeta = null; // eksisterende rutes metadata (fra editor-load.php) til "Rediger metadata"
  // Visningerne på rutens side: brugerens valg (null = ikke valgt endnu) og
  // de visninger, filen havde data til ved sidste gem. Se enabledFunctionsForSave().
  let enabledFunctions = null;
  let availableFunctionsAtSave = [];
  const THIN_SUGGEST_POINTS = 2000;

  function setCurrentRouteId(id) {
    currentRouteId = id;
    const btn = el("btn-view-page");
    if (btn) btn.disabled = !currentRouteId;
  }

  function setRouteTitleLabel(title) {
    const label = el("route-title-label");
    if (!label) return;
    label.textContent = title || "Ny rute (ikke gemt endnu)";
    label.title = title || "";
  }
  let idCounter = 1;

  const el = (id) => document.getElementById(id);
  const csrfToken = () => el("csrf-token").value;

  function nextId() {
    return "p" + (idCounter++);
  }

  // Beskeden vises i linjen under værktøjslinjen, sammen med rutens
  // antal punkter og længde.
  function setStatus(msg) {
    let statsText = "";
    if (state && map) {
      let totalMeters = 0;
      state.segments.forEach((seg) => {
        for (let i = 0; i < seg.length - 1; i++) {
          const p1 = L.latLng(seg[i].lat, seg[i].lon);
          const p2 = L.latLng(seg[i + 1].lat, seg[i + 1].lon);
          totalMeters += map.distance(p1, p2);
        }
      });
      const km = (totalMeters / 1000).toFixed(2).replace(".", ",");
      statsText = `· ${countPoints()} punkter · ${km} km`;
    }
    el("route-stats").textContent = statsText;
    el("status").textContent = msg || "";
  }

  // ---------- Historik ----------
  function pushHistory() {
    if (!state) return;
    const currentXmlStr = new XMLSerializer().serializeToString(state.xml);
    historyStack.push({ xmlStr: currentXmlStr, filename: currentFilename });
    if (historyStack.length > MAX_HISTORY) historyStack.shift();
    redoStack = [];
    updateUndoRedoButtons();
  }

  function updateUndoRedoButtons() {
    const btnUndo = el("btn-undo");
    const btnRedo = el("btn-redo");
    if (btnUndo) btnUndo.disabled = historyStack.length === 0;
    if (btnRedo) btnRedo.disabled = redoStack.length === 0;
  }

  function performUndo() {
    if (historyStack.length === 0 || !state) return;
    const currentXmlStr = new XMLSerializer().serializeToString(state.xml);
    redoStack.push({ xmlStr: currentXmlStr, filename: currentFilename });
    const previous = historyStack.pop();
    restoreStateFromXml(previous.xmlStr, previous.filename, false);
    render._hasFitBounds = true;
    render();
    updateUndoRedoButtons();

    if (historyStack.length === 0) {
      // Intet mere at fortryde — vi er tilbage ved udgangspunktet, så
      // Gem-knappen skal være grøn igen, ikke rød.
      markServerSaved();
    } else {
      markDirty();
    }

    setStatus("Fortrudt seneste handling");
  }

  function performRedo() {
    if (redoStack.length === 0 || !state) return;
    const currentXmlStr = new XMLSerializer().serializeToString(state.xml);
    historyStack.push({ xmlStr: currentXmlStr, filename: currentFilename });
    const next = redoStack.pop();
    restoreStateFromXml(next.xmlStr, next.filename, false);
    render._hasFitBounds = true;
    render();
    updateUndoRedoButtons();
    markDirty();
    setStatus("Gendannet handling");
  }

  function restoreStateFromXml(xmlStr, filename, resetHistory = false) {
    const parsed = parseGPX(xmlStr);
    state = parsed;
    currentFilename = filename;
    if (resetHistory) {
      historyStack = [];
      redoStack = [];
      updateUndoRedoButtons();
    }
  }

  // ---------- Init kort ----------
  function initMap() {
    map = L.map("map").setView([55.8559, 9.8773], 13);
    // Linjen over kortet bliver højere, når en besked bryder om på flere
    // linjer, og kortet dermed lavere — så Leaflet skal have besked.
    if (window.ResizeObserver) {
      new ResizeObserver(() => map.invalidateSize({ pan: false })).observe(el("map"));
    }
    L.tileLayer("../tile.php?z={z}&x={x}&y={y}", {
      maxZoom: 19,
      attribution: "&copy; OpenStreetMap-bidragydere"
    }).addTo(map);

    // Waypoint-tilstand-knappen ligger på selve kortet (under
    // zoom-knapperne), ikke i værktøjslinjen — den skal være synlig,
    // lige der hvor man klikker, mens man arbejder med kortet.
    const WaypointModeControl = L.Control.extend({
      options: { position: "topleft" },
      onAdd: function () {
        const btn = L.DomUtil.create("button", "waypoint-mode-map-btn");
        btn.type = "button";
        btn.id = "btn-waypoint-mode";
        btn.setAttribute("aria-pressed", "false");
        btn.title = "Slå til/fra: klik på kortet sætter et waypoint et vilkårligt sted (i stedet for at tegne på ruten)";
        L.DomEvent.disableClickPropagation(btn);
        return btn;
      }
    });
    map.addControl(new WaypointModeControl());

    map.createPane("trackPane").style.zIndex = 400;
    map.createPane("trackMarkerPane").style.zIndex = 410;
    map.createPane("wptPane").style.zIndex = 450;

    trackLayer = L.layerGroup().addTo(map);
    wptLayer = L.layerGroup().addTo(map);

    // Klik på kortet tilføjer et nyt trackpoint i slutningen af ruten —
    // MEDMINDRE waypointPlacementMode er slået til, hvor et klik i
    // stedet sætter et fritstående waypoint på det klikkede sted,
    // uafhængigt af selve rutens geometri (se btn-waypoint-mode).
    // VIGTIGT: nye, håndtegnede trackpoints får IKKE noget tidsstempel —
    // det ville ellers give en misvisende/umulig hastighed, da tiden
    // blot ville afspejle hvor hurtigt man klikkede rundt på kortet.
    map.on("click", (e) => {
      if (!state) return;

      // Klikket på "Rute-/Waypoint-tilstand"-knappen (en Leaflet-
      // kontrol oven på kortet) ser ud til at nå kortets eget
      // click-håndtag, SELVOM disableClickPropagation() er sat på
      // knappen — derfor dette eksplicitte, tilstandsbaserede
      // sikkerhedsnet i stedet: knappens egen klik-handler sætter
      // suppressNextMapClick, og her sluges det (kun) allerførste
      // kort-klik bagefter, uanset hvordan det er nået frem.
      if (suppressNextMapClick) {
        suppressNextMapClick = false;
        return;
      }

      if (waypointPlacementMode) {
        // Samme lille menu som på et trackpoint (Indsæt waypoint / Indsæt billede),
        // bare uden "Slet punkt". Waypointet oprettes først, når dialogen gemmes.
        openFreeWaypointPopup(e.latlng);
        setStatus('Vælg, om waypointet skal have en tekst eller billede(r). Klik et nyt sted for at sætte flere, eller slå "Waypoint" fra for at tegne videre på ruten.');
        return;
      }

      pushHistory();

      if (state.segments.length === 0) {
        state.segments.push(emptySegmentForNewPoints());
      }

      const currentSegment = state.segments[state.segments.length - 1];
      const newPt = {
        id: nextId(),
        lat: e.latlng.lat,
        lon: e.latlng.lng,
        ele: null,
        time: null // bevidst intet tidsstempel på håndtegnede punkter
      };

      newPt.node = createPointNode(currentSegment, newPt.lat, newPt.lon, newPt.ele, newPt.time);
      insertPointNode(currentSegment, newPt.node, currentSegment.length);
      currentSegment.push(newPt);

      markDirty();
      render();
      setStatus("Trackpoint tilføjet i slutningen af ruten");
    });
  }

  // ---------- DOM & GPX parsing ----------
  function tagAll(root, tag) {
    if (!root) return [];
    return Array.from(root.getElementsByTagNameNS("*", tag));
  }

  function firstText(root, tag) {
    const els = tagAll(root, tag);
    return els.length ? els[0].textContent : null;
  }

  function parseLinks(root) {
    return tagAll(root, "link").map((linkEl) => ({
      node: linkEl,
      href: linkEl.getAttribute("href") || "",
      text: firstText(linkEl, "text") || "",
      type: firstText(linkEl, "type") || ""
    }));
  }

  function setNodeText(parent, tag, text, ns) {
    let node = tagAll(parent, tag)[0];
    if (text) {
      if (!node) {
        node = parent.ownerDocument.createElementNS(ns, tag);
        parent.appendChild(node);
      }
      node.textContent = text;
    } else if (node) {
      node.remove();
    }
  }

  // Et segment er enten et <trkseg> med <trkpt> (spor) eller en <rte> med
  // <rtept> (rute). Nye punkter får samme type som segmentet, så filen
  // gemmes i det format, den blev uploadet i.
  function makeSegment(node, pointTag) {
    const seg = [];
    seg.node = node;
    seg.pointTag = pointTag;
    return seg;
  }

  // Segment til det første punkt, når ruten ikke har nogen punkter (ny rute,
  // eller alle punkter er slettet). Et tomt <trkseg> eller en tom <rte>, der
  // allerede findes i filen, genbruges, så en rute ikke skifter til <trk>.
  function emptySegmentForNewPoints() {
    const existing = tagAll(state.xml, "*").find((n) =>
      (n.localName === "trkseg" || n.localName === "rte") &&
      !Array.from(n.children).some((c) => c.localName === "trkpt" || c.localName === "rtept"));
    if (existing) {
      return makeSegment(existing, existing.localName === "rte" ? "rtept" : "trkpt");
    }
    const trkseg = state.xml.createElementNS(state.ns, "trkseg");
    let trk = tagAll(state.xml, "trk")[0];
    if (!trk) {
      trk = state.xml.createElementNS(state.ns, "trk");
      state.xml.documentElement.appendChild(trk);
    }
    trk.appendChild(trkseg);
    return makeSegment(trkseg, "trkpt");
  }

  // Indsætter et punkt på plads nr. index i segmentet. Sidst i segmentet
  // sættes det lige efter det hidtil sidste punkt (ikke efter fx <extensions>).
  function insertPointNode(seg, node, index) {
    if (index < seg.length) {
      seg.node.insertBefore(node, seg[index].node);
    } else if (seg.length > 0) {
      seg.node.insertBefore(node, seg[seg.length - 1].node.nextSibling);
    } else {
      seg.node.appendChild(node);
    }
  }

  function createPointNode(seg, lat, lon, ele, time) {
    const ns = state.ns;
    const pt = state.xml.createElementNS(ns, seg.pointTag || "trkpt");
    pt.setAttribute("lat", lat);
    pt.setAttribute("lon", lon);
    if (ele !== null && ele !== undefined && ele !== "") {
      const eleEl = state.xml.createElementNS(ns, "ele");
      eleEl.textContent = ele;
      pt.appendChild(eleEl);
    }
    if (time) {
      const tm = state.xml.createElementNS(ns, "time");
      tm.textContent = time;
      pt.appendChild(tm);
    }
    return pt;
  }

  const TAGS_AFTER_LINK = ["sym", "type", "fix", "sat", "hdop", "vdop", "pdop", "ageofdgpsdata", "dgpsid", "extensions"];

  function insertLinkIntoWptNode(wNode, linkNode) {
    for (const child of Array.from(wNode.children)) {
      const tagName = child.localName || child.tagName;
      if (TAGS_AFTER_LINK.includes(tagName)) {
        wNode.insertBefore(linkNode, child);
        return;
      }
    }
    wNode.appendChild(linkNode);
  }

  function createLinkNode(wNode, href, text, type) {
    const linkEl = state.xml.createElementNS(state.ns, "link");
    linkEl.setAttribute("href", href);
    if (text) {
      const textEl = state.xml.createElementNS(state.ns, "text");
      textEl.textContent = text;
      linkEl.appendChild(textEl);
    }
    if (type) {
      const typeEl = state.xml.createElementNS(state.ns, "type");
      typeEl.textContent = type;
      linkEl.appendChild(typeEl);
    }
    insertLinkIntoWptNode(wNode, linkEl);
    return { node: linkEl, href, text: text || "", type: type || "" };
  }

  function pickImageFile(callback) {
    const input = el("image-file-input");
    if (!input) return;
    input.value = "";
    input.onchange = () => {
      const file = input.files && input.files[0];
      if (file) callback(file);
    };
    input.click();
  }

  // Ligesom pickImageFile, men lader brugeren vælge flere billeder på
  // én gang (inputtet har fået multiple-attributten) — bruges af
  // billed-dialogen (edit-images-dialog).
  function pickImageFiles(callback) {
    const input = el("image-file-input");
    if (!input) return;
    input.value = "";
    input.onchange = () => {
      const files = input.files ? Array.from(input.files) : [];
      if (files.length) callback(files);
    };
    input.click();
  }

  // Billeder gøres mindre i browseren før upload: højst SHRINK_MAX_SIDE px
  // på den længste side, som appens kamera (mobile/www/kamera.js). Det
  // sparer hukommelse og upload-tid på telefoner og plads i brugerens
  // kvote. GIF (kan være animeret) og små filer sendes uændret, og går
  // noget galt, sendes originalen.
  const SHRINK_MAX_SIDE = 2048;
  const SHRINK_MIN_BYTES = 1024 * 1024;
  const SHRINK_QUALITY = 0.85;

  function shrinkImage(file) {
    if (file.type === "image/gif" || file.size < SHRINK_MIN_BYTES) return Promise.resolve(file);
    return new Promise((resolve) => {
      const url = URL.createObjectURL(file);
      const img = new Image();
      const done = (result) => {
        URL.revokeObjectURL(url);
        img.src = "";
        resolve(result);
      };
      img.onerror = () => done(file);
      img.onload = () => {
        // Billedet tegnes med den rigtige orientering (EXIF), så det står ret.
        const scale = Math.min(1, SHRINK_MAX_SIDE / Math.max(img.naturalWidth, img.naturalHeight));
        const canvas = document.createElement("canvas");
        canvas.width = Math.max(1, Math.round(img.naturalWidth * scale));
        canvas.height = Math.max(1, Math.round(img.naturalHeight * scale));
        const ctx = canvas.getContext("2d");
        ctx.fillStyle = "#ffffff"; // gennemsigtige PNG'er får hvid baggrund i JPEG
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
        canvas.toBlob((blob) => {
          canvas.width = 0; // frigiv hukommelsen med det samme
          canvas.height = 0;
          if (!blob || blob.size >= file.size) {
            done(file);
            return;
          }
          const name = file.name.replace(/\.[^./\\]+$/, "") + ".jpg";
          done(new File([blob], name, { type: "image/jpeg" }));
        }, "image/jpeg", SHRINK_QUALITY);
      };
      img.src = url;
    });
  }

  // Uploader til vores nye, login-krævende endpoint (public/upload-image.php)
  async function uploadImage(originalFile) {
    const file = await shrinkImage(originalFile);
    const formData = new FormData();
    formData.append("image", file);
    formData.append("csrf_token", csrfToken());

    const res = await fetch("../upload-image.php", { method: "POST", body: formData });
    const raw = await res.text();

    let data;
    try {
      data = JSON.parse(raw);
    } catch (parseErr) {
      throw new Error(`Serveren svarede ikke som forventet (status ${res.status}).`);
    }

    if (!res.ok || !data.success) {
      throw new Error((data.errors && data.errors.join(" ")) || `Ukendt fejl ved upload (status ${res.status}).`);
    }
    // Behold samme feltnavne som den gamle editor forventede ("filename"/"original"/"type"),
    // afledt af det nye endpoints svar, så resten af koden er uændret.
    return {
      success: true,
      filename: data.url, // fuld relativ URL bruges direkte som href
      original: file.name,
      url: data.url,
      thumbnailUrl: data.thumbnail_url || null,
      type: file.type
    };
  }

  function parseGPX(text) {
    const xml = new DOMParser().parseFromString(text, "application/xml");
    const err = xml.querySelector("parsererror");
    if (err) {
      console.error("GPX parser-fejl:", err.textContent);
      throw new Error("Kunne ikke fortolke GPX-filen. Se konsollen (F12) for detaljer.");
    }

    const ns = xml.documentElement.namespaceURI || "http://www.topografix.com/GPX/1/1";

    // Spor (<trkseg>/<trkpt>) og ruter (<rte>/<rtept>) læses begge som
    // segmenter, i den rækkefølge de står i filen.
    const segments = [];
    tagAll(xml, "*").filter((n) => n.localName === "trkseg" || n.localName === "rte").forEach((segEl) => {
      const pointTag = segEl.localName === "rte" ? "rtept" : "trkpt";
      const pts = makeSegment(segEl, pointTag);
      Array.from(segEl.children).filter((c) => c.localName === pointTag).forEach((ptEl) => {
        pts.push({
          id: nextId(),
          node: ptEl,
          lat: parseFloat(ptEl.getAttribute("lat")),
          lon: parseFloat(ptEl.getAttribute("lon")),
          ele: firstText(ptEl, "ele"),
          time: firstText(ptEl, "time")
        });
      });
      segments.push(pts);
    });

    const waypoints = tagAll(xml, "wpt").map((wEl) => ({
      id: nextId(),
      node: wEl,
      lat: parseFloat(wEl.getAttribute("lat")),
      lon: parseFloat(wEl.getAttribute("lon")),
      name: firstText(wEl, "name") || "",
      desc: firstText(wEl, "desc") || "",
      links: parseLinks(wEl)
    }));

    return { xml, ns, segments, waypoints };
  }

  // Findes der en <bounds> i <metadata>, rettes den til de nuværende
  // punkter og waypoints, så den ikke viser rutens gamle udstrækning.
  // Der tilføjes ingen <bounds>, hvis filen ikke havde en.
  function updateMetadataBounds(st) {
    const bounds = tagAll(st.xml, "bounds")[0];
    if (!bounds) return;
    const all = st.segments.flat().concat(st.waypoints)
      .filter((p) => Number.isFinite(p.lat) && Number.isFinite(p.lon));
    if (all.length === 0) {
      bounds.remove();
      return;
    }
    let minLat = Infinity, minLon = Infinity, maxLat = -Infinity, maxLon = -Infinity;
    for (const p of all) {
      if (p.lat < minLat) minLat = p.lat;
      if (p.lat > maxLat) maxLat = p.lat;
      if (p.lon < minLon) minLon = p.lon;
      if (p.lon > maxLon) maxLon = p.lon;
    }
    bounds.setAttribute("minlat", String(minLat));
    bounds.setAttribute("minlon", String(minLon));
    bounds.setAttribute("maxlat", String(maxLat));
    bounds.setAttribute("maxlon", String(maxLon));
  }

  function serializeGPX(st) {
    updateMetadataBounds(st);
    let out = new XMLSerializer().serializeToString(st.xml);
    if (!out.startsWith("<?xml")) {
      out = '<?xml version="1.0" encoding="UTF-8"?>\n' + out;
    }
    return out;
  }

  // ---------- Ugemte ændringer ----------
  // Kun geometri (trackpoints/waypoints) spores her — metadata ændres i
  // kontrolpanelet, ikke i editoren.
  function markDirty() {
    geometryDirty = true;
    updateSaveButtonState();
  }

  function markAllSaved() {
    geometryDirty = false;
    updateSaveButtonState();
  }

  function markServerSaved() {
    geometryDirty = false;
    updateSaveButtonState();
  }

  function isAnyDirty() {
    return geometryDirty;
  }

  function updateSaveButtonState() {
    const btn = el("btn-save-server");
    btn.disabled = !state;
    btn.classList.toggle("save-dirty", geometryDirty);
    // Grøn ("gemt") giver kun mening for en rute, der findes på platformen.
    btn.classList.toggle("save-clean", !geometryDirty && !!currentRouteId);
    btn.title = geometryDirty
      ? "Der er ugemte ændringer"
      : (currentRouteId ? "Gemt på platformen" : "Ruten er ikke gemt endnu");
  }

  // ---------- Rendering ----------
  function render() {
    trackLayer.clearLayers();
    wptLayer.clearLayers();
    wptMarkers = {};

    const allLatLngs = [];

    state.segments.forEach((seg) => {
      const latlngs = seg.map((p) => [p.lat, p.lon]);
      allLatLngs.push(...latlngs);
      if (latlngs.length > 1) {
        L.polyline(latlngs, { color: "#2563eb", weight: 3, opacity: 0.8, pane: "trackPane" }).addTo(trackLayer);
        L.polyline(latlngs, { weight: 16, opacity: 0, interactive: true, pane: "trackPane" })
          .addTo(trackLayer)
          .on("click", (e) => {
            L.DomEvent.stopPropagation(e);
            insertTrackpointOnSegment(seg, e.layerPoint);
          });
      }

      const trackIcon = L.divIcon({
        className: "touch-marker",
        html: '<div class="dot-track"></div>',
        iconSize: [40, 40],
        iconAnchor: [20, 20]
      });

      seg.forEach((p) => {
        const marker = L.marker([p.lat, p.lon], {
          icon: trackIcon,
          draggable: true,
          pane: "trackMarkerPane"
        }).addTo(trackLayer);

        marker.bindPopup(trackpointPopupHtml(p.id), { className: "gpx-popup" });
        marker.on("popupopen", () => bindTrackpointPopupEvents(p.id));

        marker.on("dragstart", () => {
          map.dragging.disable();
        });

        marker.on("dragend", () => {
          map.dragging.enable();
          const newLatLng = marker.getLatLng();

          const TAP_THRESHOLD_PX = 4;
          const fromPx = map.latLngToContainerPoint([p.lat, p.lon]);
          const toPx = map.latLngToContainerPoint(newLatLng);

          if (fromPx.distanceTo(toPx) < TAP_THRESHOLD_PX) {
            marker.setLatLng([p.lat, p.lon]);
            marker.openPopup();
            return;
          }

          p.lat = newLatLng.lat;
          p.lon = newLatLng.lng;

          pushHistory();
          p.node.setAttribute("lat", p.lat);
          p.node.setAttribute("lon", p.lon);

          markDirty();
          render();
          setStatus("Trackpoint flyttet");
        });
      });
    });

    const wptIcon = L.divIcon({
      className: "touch-marker",
      html: '<div class="dot-wpt"></div>',
      iconSize: [40, 40],
      iconAnchor: [20, 20]
    });

    const wptImgIcon = L.divIcon({
      className: "touch-marker",
      html: '<div class="dot-wpt-img"></div>',
      iconSize: [40, 40],
      iconAnchor: [20, 20]
    });

    state.waypoints.forEach((w) => {
      allLatLngs.push([w.lat, w.lon]);
      const hasImage = !!(w.links && w.links.length);
      const marker = L.marker([w.lat, w.lon], {
        icon: hasImage ? wptImgIcon : wptIcon,
        draggable: true,
        pane: "wptPane"
      }).addTo(wptLayer);

      // Klik på et eksisterende waypoint åbner den relevante dialog
      // direkte — lilla prik (har billeder) åbner billed-dialogen,
      // orange prik (kun tekst) åbner tekst-dialogen. Ingen
      // mellemliggende Leaflet-popup længere.
      const openWaypointDialog = () => {
        if (hasImage) {
          openEditImagesDialogForExistingWaypoint(w.id);
        } else {
          openEditWaypointTextDialog(w.id);
        }
      };
      marker.on("click", openWaypointDialog);

      // Waypoints kan trækkes til en ny plads, ligesom trackpoints —
      // uanset om de er sat på et trackpoint eller (i waypoint-tilstand)
      // et vilkårligt sted på kortet. Et lille træk (under tærsklen) tæller
      // som et klik: waypointet springer tilbage, og dialogen åbnes, fordi
      // Leaflet ikke sender et klik, når markøren har været trukket.
      marker.on("dragstart", () => {
        map.dragging.disable();
      });

      marker.on("dragend", () => {
        map.dragging.enable();
        const newLatLng = marker.getLatLng();

        const TAP_THRESHOLD_PX = 4;
        const fromPx = map.latLngToContainerPoint([w.lat, w.lon]);
        const toPx = map.latLngToContainerPoint(newLatLng);

        if (fromPx.distanceTo(toPx) < TAP_THRESHOLD_PX) {
          marker.setLatLng([w.lat, w.lon]);
          openWaypointDialog();
          return;
        }

        w.lat = newLatLng.lat;
        w.lon = newLatLng.lng;

        pushHistory();
        w.node.setAttribute("lat", w.lat);
        w.node.setAttribute("lon", w.lon);

        markDirty();
        render();
        setStatus("Waypoint flyttet");
      });

      wptMarkers[w.id] = marker;
    });

    if (allLatLngs.length && !render._hasFitBounds) {
      map.fitBounds(allLatLngs, { padding: [30, 30] });
      render._hasFitBounds = true;
    }
  }

  // ---------- Popup-indhold ----------
  function findPoint(id) {
    for (const seg of state.segments) {
      const p = seg.find((pt) => pt.id === id);
      if (p) return p;
    }
    return null;
  }

  function escapeHtml(str) {
    return String(str ?? "").replace(/[&<>"']/g, (c) => ({
      "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;"
    }[c]));
  }

  function trackpointPopupHtml(id) {
    return `
      <div class="gpx-popup">
        <div class="popup-buttons">
          <button class="primary" data-action="insert-wpt" data-id="${id}">➕ Indsæt waypoint</button>
          <button data-action="add-image" data-id="${id}">📷 Indsæt billede</button>
          <button class="danger" data-action="delete-pt" data-id="${id}">🗑 Slet punkt</button>
        </div>
      </div>`;
  }

  // Er href et link til et af brugerens egne billeder (billeder/<eget id>/<hex>.<endelse>)?
  // Andre links (fremmede profilers billeder fra en kopieret GPX-fil eller
  // eksterne adresser) hentes ikke og vises ikke på rutens side.
  function isOwnImageHref(href) {
    const prefix = document.body.dataset.imagePrefix || "";
    if (!prefix) return false;
    const escaped = prefix.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
    return new RegExp("^(?:/|\\.\\./)?" + escaped + "[0-9a-f]{32}(?:-thumb)?\\.(?:jpe?g|png|gif|webp)$", "i")
      .test(String(href || "").trim());
  }

  // Miniaturen til et af brugerens egne billeder hedder <navn>-thumb.jpg
  // (se image_generate_thumbnail()). Store originaler fylder meget i
  // hukommelsen på ældre telefoner, så dialogen viser miniaturen.
  function ownThumbHref(href) {
    const h = String(href || "").trim();
    if (!isOwnImageHref(h) || /-thumb\.jpg$/i.test(h)) return null;
    return h.replace(/\.(?:jpe?g|png|gif|webp)$/i, "-thumb.jpg");
  }

  // Billeder ligger under public/billeder/, ét niveau over editor/-mappen.
  function imagePreviewUrl(href) {
    if (/^https?:\/\//i.test(href) || href.startsWith("../") || href.startsWith("/")) return href;
    if (href.includes("/")) return "../" + href;
    return "../billeder/" + encodeURIComponent(href);
  }

  function bindTrackpointPopupEvents(id) {
    const popupEl = document.querySelector(`.gpx-popup button[data-action="insert-wpt"][data-id="${id}"]`)?.closest(".leaflet-popup-content");
    if (!popupEl) return;

    popupEl.querySelector('[data-action="insert-wpt"]').addEventListener("click", () => {
      map.closePopup();
      openInsertWaypointDialog(id);
    });
    popupEl.querySelector('[data-action="add-image"]').addEventListener("click", () => {
      map.closePopup();
      openEditImagesDialogForNewWaypoint(id);
    });
    popupEl.querySelector('[data-action="delete-pt"]').addEventListener("click", () => deleteTrackpoint(id));
  }

  // Hvor skal et NYT waypoint sættes? Enten på et eksisterende trackpoint
  // (ctx.pointId) eller på et frit valgt sted (ctx.lat/ctx.lon — waypoint-tilstand).
  function newWaypointPosition(ctx) {
    if (!ctx) return null;
    if (ctx.pointId !== undefined && ctx.pointId !== null) {
      const p = findPoint(ctx.pointId);
      return p ? { lat: p.lat, lon: p.lon } : null;
    }
    if (typeof ctx.lat === "number" && typeof ctx.lon === "number") {
      return { lat: ctx.lat, lon: ctx.lon };
    }
    return null;
  }

  // Waypoint-tilstand: klik på kortet åbner denne lille menu på det klikkede
  // sted — de samme to valg som på et trackpoint, men uden "Slet punkt".
  function openFreeWaypointPopup(latlng) {
    const position = { lat: latlng.lat, lon: latlng.lng };
    const popup = L.popup({ className: "gpx-popup" })
      .setLatLng(latlng)
      .setContent(`
        <div class="gpx-popup">
          <div class="popup-buttons">
            <button class="primary" data-action="insert-wpt">➕ Indsæt waypoint</button>
            <button data-action="add-image">📷 Indsæt billede</button>
          </div>
        </div>`)
      .openOn(map);

    const root = popup.getElement();
    if (!root) return;
    root.querySelector('[data-action="insert-wpt"]').addEventListener("click", () => {
      map.closePopup();
      openInsertWaypointDialog(null, position);
    });
    root.querySelector('[data-action="add-image"]').addEventListener("click", () => {
      map.closePopup();
      openEditImagesDialogForNewWaypoint(null, position);
    });
  }

  // ---------- Redigeringshandlinger ----------
  // Opretter et nyt waypoint-node i selve GPX-dokumentet på de angivne
  // koordinater — bruges både til "indsæt her" (samme sted som et
  // eksisterende trackpoint) og til fritstående waypoints uden for
  // ruten (se waypointPlacementMode). Kalder bevidst IKKE render()
  // eller åbner popup'en selv — det er op til den, der kalder.
  function createWaypointAt(lat, lon) {
    pushHistory();
    const newWpt = { id: nextId(), lat: lat, lon: lon, name: "Nyt waypoint", desc: "", links: [] };

    newWpt.node = state.xml.createElementNS(state.ns, "wpt");
    newWpt.node.setAttribute("lat", newWpt.lat);
    newWpt.node.setAttribute("lon", newWpt.lon);
    setNodeText(newWpt.node, "name", newWpt.name, state.ns);

    const trkNode = Array.from(state.xml.documentElement.children)
      .find((c) => c.localName === "rte" || c.localName === "trk");
    if (trkNode) {
      state.xml.documentElement.insertBefore(newWpt.node, trkNode);
    } else {
      state.xml.documentElement.appendChild(newWpt.node);
    }

    state.waypoints.push(newWpt);
    markDirty();
    return newWpt;
  }

  function insertWaypointAt(pointId) {
    const p = findPoint(pointId);
    if (!p) return;
    const newWpt = createWaypointAt(p.lat, p.lon);
    map.closePopup();
    render();

    const marker = wptMarkers[newWpt.id];
    if (marker) marker.openPopup();
    setStatus('Waypoint indsat. Giv det en tekst og tryk "Gem tekst"');
  }

  function deleteTrackpoint(pointId) {
    pushHistory();
    for (const seg of state.segments) {
      const idx = seg.findIndex((pt) => pt.id === pointId);
      if (idx !== -1) {
        seg[idx].node.remove();
        seg.splice(idx, 1);
        break;
      }
    }
    state.segments = state.segments.filter((seg) => seg.length > 0);
    markDirty();
    map.closePopup();
    render();
    setStatus("Trackpoint slettet");
  }

  function midpointOf(p1, p2) {
    return {
      lat: (p1.lat + p2.lat) / 2,
      lon: (p1.lon + p2.lon) / 2,
      ele: interpolateEle(p1.ele, p2.ele, 0.5),
      time: interpolateTime(p1.time, p2.time, 0.5)
    };
  }

  function extrapolateFrom(from, to) {
    return { lat: to.lat + (to.lat - from.lat), lon: to.lon + (to.lon - from.lon), ele: to.ele, time: null };
  }

  function nearbyOffset(p) {
    return { lat: p.lat + 0.0001, lon: p.lon + 0.0001, ele: p.ele, time: null };
  }

  function insertAdjacentTrackpoint(pointId, where) {
    pushHistory();
    for (const seg of state.segments) {
      const idx = seg.findIndex((pt) => pt.id === pointId);
      if (idx === -1) continue;

      let coords;
      if (where === "after") {
        if (idx < seg.length - 1) coords = midpointOf(seg[idx], seg[idx + 1]);
        else if (idx > 0) coords = extrapolateFrom(seg[idx - 1], seg[idx]);
        else coords = nearbyOffset(seg[idx]);
      } else {
        if (idx > 0) coords = midpointOf(seg[idx - 1], seg[idx]);
        else if (idx < seg.length - 1) coords = extrapolateFrom(seg[idx + 1], seg[idx]);
        else coords = nearbyOffset(seg[idx]);
      }

      const newPoint = { id: nextId(), ...coords };
      newPoint.node = createPointNode(seg, coords.lat, coords.lon, coords.ele, coords.time);

      let insertIdx = where === "after" ? idx + 1 : idx;
      insertPointNode(seg, newPoint.node, insertIdx);

      seg.splice(insertIdx, 0, newPoint);

      markDirty();
      map.closePopup();
      render();
      setStatus("Trackpoint indsat");
      return;
    }
  }

  function editWaypoint(id, name, desc) {
    const w = state.waypoints.find((x) => x.id === id);
    if (w) {
      pushHistory();
      w.name = name;
      w.desc = desc;
      setNodeText(w.node, "name", name, state.ns);
      setNodeText(w.node, "desc", desc, state.ns);
    }
    markDirty();
    map.closePopup();
    render();
    setStatus("Waypoint opdateret");
  }

  function closestPointOnSegment(p, a, b) {
    const dx = b.x - a.x;
    const dy = b.y - a.y;
    const lengthSq = dx * dx + dy * dy;
    let t = lengthSq === 0 ? 0 : ((p.x - a.x) * dx + (p.y - a.y) * dy) / lengthSq;
    t = Math.max(0, Math.min(1, t));
    return { point: L.point(a.x + t * dx, a.y + t * dy), t };
  }

  function interpolateEle(e1, e2, t) {
    const n1 = parseFloat(e1);
    const n2 = parseFloat(e2);
    if (Number.isNaN(n1) || Number.isNaN(n2)) return null;
    return (n1 + (n2 - n1) * t).toFixed(2);
  }

  function interpolateTime(t1, t2, t) {
    if (!t1 || !t2) return null;
    const d1 = Date.parse(t1);
    const d2 = Date.parse(t2);
    if (Number.isNaN(d1) || Number.isNaN(d2)) return null;
    return new Date(d1 + (d2 - d1) * t).toISOString();
  }

  function insertTrackpointOnSegment(seg, clickLayerPoint) {
    if (!state || seg.length < 2) return;

    let best = null;
    for (let i = 0; i < seg.length - 1; i++) {
      const a = map.latLngToLayerPoint([seg[i].lat, seg[i].lon]);
      const b = map.latLngToLayerPoint([seg[i + 1].lat, seg[i + 1].lon]);
      const { point, t } = closestPointOnSegment(clickLayerPoint, a, b);
      const dist = clickLayerPoint.distanceTo(point);
      if (!best || dist < best.dist) best = { index: i, point, t, dist };
    }
    if (!best) return;

    pushHistory();
    const latlng = map.layerPointToLatLng(best.point);
    const p1 = seg[best.index];
    const p2 = seg[best.index + 1];

    const newPoint = {
      id: nextId(),
      lat: latlng.lat,
      lon: latlng.lng,
      ele: interpolateEle(p1.ele, p2.ele, best.t),
      time: interpolateTime(p1.time, p2.time, best.t)
    };

    newPoint.node = createPointNode(seg, newPoint.lat, newPoint.lon, newPoint.ele, newPoint.time);
    insertPointNode(seg, newPoint.node, best.index + 1);

    seg.splice(best.index + 1, 0, newPoint);

    markDirty();
    render();
    setStatus("Nyt trackpoint indsat på ruten");
  }

  function deleteWaypoint(id) {
    const wIndex = state.waypoints.findIndex((w) => w.id === id);
    if (wIndex !== -1) {
      pushHistory();
      state.waypoints[wIndex].node.remove();
      state.waypoints.splice(wIndex, 1);
    }
    markDirty();
    map.closePopup();
    render();
    setStatus("Waypoint slettet");
  }

  // ---------- Waypoint-tekst-dialog (navn/beskrivelse) ----------
  // Bruges både til at INDSÆTTE et nyt waypoint på et trackpoint
  // ({ mode: "new", pointId }) og til at REDIGERE et allerede
  // eksisterende, rent tekst-baseret waypoint (orange prik)
  // ({ mode: "edit", waypointId }) — se marker.on("click", ...) i render().
  let insertWaypointContext = null;

  function openInsertWaypointDialog(pointId, position) {
    insertWaypointContext = {
      mode: "new",
      pointId: pointId,
      lat: position ? position.lat : null,
      lon: position ? position.lon : null
    };
    el("insert-waypoint-dialog-title").textContent = "Indsæt waypoint";
    el("insert-waypoint-name").value = "Nyt waypoint";
    el("insert-waypoint-desc").value = "";
    const errEl = el("insert-waypoint-errors");
    if (errEl) errEl.hidden = true;
    const deleteBtn = el("btn-delete-insert-waypoint");
    if (deleteBtn) deleteBtn.hidden = true;
    el("insert-waypoint-dialog").showModal();
    el("insert-waypoint-name").focus();
    el("insert-waypoint-name").select();
  }

  function openEditWaypointTextDialog(waypointId) {
    const w = state.waypoints.find((x) => x.id === waypointId);
    if (!w) return;
    insertWaypointContext = { mode: "edit", waypointId };
    el("insert-waypoint-dialog-title").textContent = "Rediger waypoint";
    el("insert-waypoint-name").value = w.name || "";
    el("insert-waypoint-desc").value = w.desc || "";
    const errEl = el("insert-waypoint-errors");
    if (errEl) errEl.hidden = true;
    const deleteBtn = el("btn-delete-insert-waypoint");
    if (deleteBtn) deleteBtn.hidden = false;
    el("insert-waypoint-dialog").showModal();
    el("insert-waypoint-name").focus();
    el("insert-waypoint-name").select();
  }

  function saveInsertWaypointDialog() {
    const name = el("insert-waypoint-name").value.trim();
    const desc = el("insert-waypoint-desc").value.trim();
    const errEl = el("insert-waypoint-errors");

    if (!name) {
      if (errEl) {
        errEl.textContent = "Waypointet skal have et navn.";
        errEl.hidden = false;
      }
      return;
    }

    if (insertWaypointContext && insertWaypointContext.mode === "edit") {
      editWaypoint(insertWaypointContext.waypointId, name, desc);
      el("insert-waypoint-dialog").close();
      insertWaypointContext = null;
      return;
    }

    const p = newWaypointPosition(insertWaypointContext);
    if (!p) {
      el("insert-waypoint-dialog").close();
      return;
    }

    const newWpt = createWaypointAt(p.lat, p.lon);
    newWpt.name = name;
    newWpt.desc = desc;
    setNodeText(newWpt.node, "name", name, state.ns);
    setNodeText(newWpt.node, "desc", desc, state.ns);

    map.closePopup(); // luk evt. stadig-åben trackpoint-popup — waypointet er nu sat op i selve dialogen
    render();
    el("insert-waypoint-dialog").close();
    setStatus("Waypoint indsat");
    insertWaypointContext = null;
  }

  function deleteWaypointFromTextDialog() {
    if (!insertWaypointContext || insertWaypointContext.mode !== "edit") return;
    deleteWaypoint(insertWaypointContext.waypointId);
    el("insert-waypoint-dialog").close();
    insertWaypointContext = null;
  }

  // ---------- Billed-dialog (indsæt/rediger billeder på et waypoint) ----------
  // editImagesContext: { mode: "new", pointId } for et helt nyt
  // waypoint (fra et trackpoints "Indsæt billede"), { mode: "new", lat, lon }
  // for et nyt waypoint på et frit valgt sted (waypoint-tilstand), eller
  // { mode: "existing", waypointId } for at redigere billederne på et
  // waypoint, der allerede findes.
  let editImagesContext = null;
  let editImagesRows = []; // { key, href, text, type, original?, thumbnailUrl }
  let editImagesSortable = null;

  function renderEditImagesRows() {
    const list = el("edit-images-list");
    list.innerHTML = editImagesRows.map((row) => `
      <li class="image-row" data-key="${row.key}">
        <span class="image-row-handle" title="Træk for at ændre rækkefølge">⠿</span>
        ${row.thumbnailUrl || isOwnImageHref(row.href)
          ? `<img class="image-row-thumb" src="${escapeHtml(imagePreviewUrl(row.thumbnailUrl || row.href))}" data-fallback="${escapeHtml(imagePreviewUrl(row.href))}" alt="">`
          : `<span class="image-row-thumb image-row-foreign" title="${escapeHtml(row.href)}">Vises ikke</span>`}
        <textarea class="image-row-comment" data-key="${row.key}" placeholder="Kommentar til billedet">${escapeHtml(row.text)}</textarea>
        <button type="button" class="image-row-remove" data-key="${row.key}" title="Fjern billede">✕</button>
      </li>
    `).join("");

    // Ældre billeder kan mangle en miniature: vis så selve billedet.
    list.querySelectorAll("img.image-row-thumb[data-fallback]").forEach((img) => {
      img.addEventListener("error", () => {
        const fallback = img.getAttribute("data-fallback");
        img.removeAttribute("data-fallback");
        if (fallback && img.getAttribute("src") !== fallback) img.src = fallback;
      }, { once: true });
    });

    list.querySelectorAll(".image-row-comment").forEach((textarea) => {
      textarea.addEventListener("input", () => {
        const row = editImagesRows.find((r) => r.key === textarea.getAttribute("data-key"));
        if (row) row.text = textarea.value;
      });
    });
    list.querySelectorAll(".image-row-remove").forEach((btn) => {
      btn.addEventListener("click", () => {
        const key = btn.getAttribute("data-key");
        editImagesRows = editImagesRows.filter((r) => r.key !== key);
        renderEditImagesRows();
      });
    });

    if (editImagesSortable) {
      editImagesSortable.destroy();
      editImagesSortable = null;
    }
    if (typeof Sortable !== "undefined" && list.children.length) {
      editImagesSortable = Sortable.create(list, {
        handle: ".image-row-handle",
        animation: 150,
        ghostClass: "sortable-ghost",
        chosenClass: "sortable-chosen"
      });
    }
  }

  function openEditImagesDialogForNewWaypoint(pointId, position) {
    editImagesContext = {
      mode: "new",
      pointId: pointId,
      lat: position ? position.lat : null,
      lon: position ? position.lon : null
    };
    editImagesRows = [];
    el("edit-images-dialog-title").textContent = "Nyt waypoint med billede(r)";
    el("edit-images-waypoint-name").value = "";
    const errEl = el("edit-images-errors");
    if (errEl) errEl.hidden = true;
    const deleteBtn = el("btn-delete-edit-images-waypoint");
    if (deleteBtn) deleteBtn.hidden = true;
    renderEditImagesRows();
    // Åbner bare dialogen. Brugeren vælger selv "Tilføj billeder" (filvælger) eller
    // "Vælg blandt mine billeder" (allerede uploadede billeder).
    el("edit-images-dialog").showModal();
  }

  function openEditImagesDialogForExistingWaypoint(waypointId) {
    const w = state.waypoints.find((x) => x.id === waypointId);
    if (!w) return;
    editImagesContext = { mode: "existing", waypointId };
    editImagesRows = (w.links || []).map((lnk) => ({
      key: "existing-" + nextId(),
      href: lnk.href,
      text: lnk.text || "",
      type: lnk.type || "",
      thumbnailUrl: ownThumbHref(lnk.href) // egen miniature; falder tilbage til selve billedet, hvis den mangler
    }));
    el("edit-images-dialog-title").textContent = "Billeder";
    el("edit-images-waypoint-name").value = w.name || "";
    const errEl = el("edit-images-errors");
    if (errEl) errEl.hidden = true;
    const deleteBtn = el("btn-delete-edit-images-waypoint");
    if (deleteBtn) deleteBtn.hidden = false;
    renderEditImagesRows();
    el("edit-images-dialog").showModal();
  }

  function deleteWaypointFromImagesDialog() {
    if (!editImagesContext || editImagesContext.mode !== "existing") return;
    deleteWaypoint(editImagesContext.waypointId);
    el("edit-images-dialog").close();
    editImagesContext = null;
    editImagesRows = [];
  }

  function addMoreImagesToDialog() {
    pickImageFiles(async (files) => {
      setStatus(`Uploader ${files.length} billede(r)…`);
      for (const file of files) {
        try {
          const result = await uploadImage(file);
          editImagesRows.push({
            key: "new-" + nextId(),
            href: result.filename,
            text: "",
            type: result.type,
            original: result.original,
            thumbnailUrl: result.thumbnailUrl
          });
          renderEditImagesRows();

          // Foreslå et navn ud fra det først uploadede billede, hvis
          // brugeren ikke allerede selv har skrevet en titel.
          const nameField = el("edit-images-waypoint-name");
          if (nameField && !nameField.value.trim()) {
            nameField.value = result.original.replace(/\.[^./\\]+$/, "");
          }
        } catch (e) {
          setStatus(`Fejl ved upload af ${file.name}: ${e.message}`);
        }
      }
      setStatus(`${files.length} billede(r) uploadet — husk at trykke Gem`);
    });
  }

  // ---------- Vælg blandt allerede uploadede billeder ----------
  let pickedImageIds = new Set();

  // "Administrér mine billeder" fører til /manage-images.php, hvor tilbage-pilen
  // fører tilbage hertil. Adressen tages først nu, da en ny rute får ?route=
  // efter første gem.
  function updateManageImagesLink() {
    const link = el("link-manage-images");
    if (!link) return;
    link.href = "/manage-images.php?tilbage=" + encodeURIComponent(location.pathname + location.search);
  }

  async function openImagePickerDialog() {
    pickedImageIds = new Set();
    const grid = el("image-picker-grid");
    const errEl = el("image-picker-errors");
    if (errEl) errEl.hidden = true;
    grid.innerHTML = '<p class="image-picker-empty">Henter dine billeder…</p>';
    el("image-picker-dialog").showModal();
    updateManageImagesLink();

    try {
      const res = await fetch("../my-images.php");
      const data = await res.json();
      if (!res.ok || !data.success) {
        throw new Error((data.errors && data.errors.join(" ")) || `Ukendt fejl (status ${res.status}).`);
      }

      if (data.images.length === 0) {
        grid.innerHTML = '<p class="image-picker-empty">Du har ikke uploadet nogen billeder endnu.</p>';
        return;
      }

      grid.innerHTML = "";
      data.images.forEach((img) => {
        const item = document.createElement("div");
        item.className = "image-picker-item";
        item.dataset.imageId = img.image_id;
        item.dataset.url = img.url;
        item.dataset.thumbnailUrl = img.thumbnail_url || "";
        item.dataset.original = img.original_filename;
        item.dataset.type = img.mime_type || "";
        item.title = img.original_filename;

        const thumbImg = document.createElement("img");
        thumbImg.src = imagePreviewUrl(img.thumbnail_url || img.url);
        thumbImg.alt = img.original_filename;
        item.appendChild(thumbImg);

        item.addEventListener("click", () => {
          const id = img.image_id;
          if (pickedImageIds.has(id)) {
            pickedImageIds.delete(id);
            item.classList.remove("picked");
          } else {
            pickedImageIds.add(id);
            item.classList.add("picked");
          }
        });

        grid.appendChild(item);
      });
    } catch (e) {
      if (errEl) {
        errEl.textContent = "Fejl ved hentning af dine billeder: " + e.message;
        errEl.hidden = false;
      }
      grid.innerHTML = "";
    }
  }

  function addPickedImagesToDialog() {
    const grid = el("image-picker-grid");
    grid.querySelectorAll(".image-picker-item.picked").forEach((item) => {
      editImagesRows.push({
        key: "existing-" + nextId(),
        href: item.dataset.url,
        text: "",
        type: item.dataset.type,
        thumbnailUrl: item.dataset.thumbnailUrl || null
      });

      // Foreslå et navn ud fra det først valgte billede, hvis brugeren
      // ikke allerede selv har skrevet en titel (samme princip som ved
      // upload af nye billeder).
      const nameField = el("edit-images-waypoint-name");
      if (nameField && !nameField.value.trim()) {
        nameField.value = item.dataset.original.replace(/\.[^./\\]+$/, "");
      }
    });

    renderEditImagesRows();
    el("image-picker-dialog").close();
  }

  function saveEditImagesDialog() {
    const list = el("edit-images-list");
    const orderedKeys = Array.from(list.children).map((li) => li.getAttribute("data-key"));
    const orderedRows = orderedKeys
      .map((key) => editImagesRows.find((r) => r.key === key))
      .filter(Boolean);

    const name = el("edit-images-waypoint-name").value.trim() || "Nyt waypoint";
    let targetWpt = null;

    if (editImagesContext.mode === "new") {
      if (orderedRows.length === 0) {
        el("edit-images-dialog").close();
        return;
      }
      const p = newWaypointPosition(editImagesContext);
      if (!p) {
        el("edit-images-dialog").close();
        return;
      }
      targetWpt = createWaypointAt(p.lat, p.lon);
    } else {
      targetWpt = state.waypoints.find((w) => w.id === editImagesContext.waypointId);
      if (!targetWpt) {
        el("edit-images-dialog").close();
        return;
      }
      pushHistory();
      // Fjern alle eksisterende <link>-noder — de genskabes forneden i den (evt. nye) rækkefølge.
      (targetWpt.links || []).forEach((lnk) => lnk.node.remove());
    }

    targetWpt.name = name;
    setNodeText(targetWpt.node, "name", name, state.ns);

    targetWpt.links = [];
    orderedRows.forEach((row) => {
      const linkInfo = createLinkNode(targetWpt.node, row.href, row.text, row.type);
      targetWpt.links.push(linkInfo);
    });

    markDirty();
    map.closePopup(); // luk evt. stadig-åben trackpoint-/waypoint-popup — alt redigeres nu i selve dialogen
    render();
    el("edit-images-dialog").close();
    setStatus(editImagesContext.mode === "new" ? "Waypoint med billeder oprettet" : "Billeder opdateret");

    editImagesContext = null;
    editImagesRows = [];
  }

  // ---------- Metadata-panel ----------
  function getMetaFromForm() {
    return {
      title: el("meta-title").value,
      description: el("meta-description").value,
      activityTypeId: el("meta-activity").value,
      // null = ingen gruppevælger på siden (brugeren har ingen grupper) -> sendes ikke
      groupId: el("meta-group") ? el("meta-group").value : null,
      routeType: document.querySelector('input[name="meta-route-type"]:checked').value,
      visibility: document.querySelector('input[name="meta-visibility"]:checked').value,
      allowDownload: el("meta-allow-download").checked,
      hideUploaderName: el("meta-hide-uploader-name") ? el("meta-hide-uploader-name").checked : false,
      publicNoticeAck: el("meta-public-notice-ack") ? el("meta-public-notice-ack").checked : false
    };
  }

  // Delt adfærd (med kontrolpanelets tilsvarende dialog) for GDPR-boks,
  // "vær anonym"-felt og advarslen om tidsstempler i en downloadbar
  // Rute — se assets/route-meta-dialog.js.
  const metaBehavior = RouteMetaDialog.init({
    routeTypeRute: "meta-route-type-rute",
    visibilityPublic: "meta-visibility-public",
    visibilityPrivate: "meta-visibility-private",
    allowDownload: "meta-allow-download",
    publicNotice: "public-notice-field",
    anonymousField: "meta-anonymous-field",
    timeInRuteNotice: "meta-time-in-rute-notice",
    publicNoticeAck: "meta-public-notice-ack"
  });

  // Åbner dialogen, der bruges til første gem af en NY rute.
  function openNewRouteMetaDialog() {
    const dialog = el("edit-meta-dialog");
    const errors = el("edit-meta-errors");
    if (errors) errors.hidden = true;
    el("edit-meta-heading").textContent = "Gem ny rute";
    el("btn-save-meta-label").textContent = "Gem rute";
    metaBehavior.refresh();
    if (dialog) dialog.showModal();
  }

  // "Rediger metadata" i menuen: samme dialog, udfyldt med den gemte rutes
  // metadata. Gemmes for sig selv (saveExistingMeta), uden om Gem-knappen.
  function openExistingMetaDialog() {
    if (!currentRouteId || !routeMeta) return;
    el("edit-meta-errors").hidden = true;
    el("edit-meta-heading").textContent = "Rediger metadata";
    el("btn-save-meta-label").textContent = "Gem ændringer";
    el("meta-title").value = routeMeta.title;
    el("meta-description").value = routeMeta.description || "";
    el("meta-activity").value = String(routeMeta.activity_type_id);
    if (el("meta-group")) el("meta-group").value = routeMeta.group_id || "";
    const typeRadio = el(routeMeta.route_type === "spor" ? "meta-route-type-spor" : "meta-route-type-rute");
    typeRadio.checked = true;
    el(routeMeta.visibility === "public" ? "meta-visibility-public" : "meta-visibility-private").checked = true;
    el("meta-allow-download").checked = !!routeMeta.allow_download;
    el("meta-hide-uploader-name").checked = !!routeMeta.hide_uploader_name;
    metaBehavior.setLoadedVisibility(routeMeta.visibility);
    metaBehavior.setHasTimeData(!!state && window.GpxTools.detectFields(toolGpx()).indexOf("time") !== -1);
    el("edit-meta-dialog").showModal();
  }

  async function saveExistingMeta(meta) {
    const body = new URLSearchParams({
      csrf_token: csrfToken(),
      route_id: currentRouteId,
      title: meta.title,
      description: meta.description,
      activity_type_id: meta.activityTypeId,
      route_type: meta.routeType,
      visibility: meta.visibility,
      allow_download: meta.allowDownload ? "1" : "0",
      hide_uploader_name: meta.hideUploaderName ? "1" : "0",
      public_notice_ack: meta.publicNoticeAck ? "1" : "0"
    });
    if (meta.groupId !== null && meta.groupId !== undefined) body.append("group_id", meta.groupId);

    const res = await fetch("../update-route-metadata.php", { method: "POST", body });
    const data = await res.json();
    if (!res.ok || !data.success) {
      throw new Error((data.errors && data.errors.join(" ")) || `Ukendt fejl (status ${res.status}).`);
    }

    routeMeta = Object.assign(routeMeta, {
      title: meta.title.trim(),
      description: meta.description,
      activity_type_id: parseInt(meta.activityTypeId, 10),
      route_type: meta.routeType,
      visibility: meta.visibility,
      allow_download: meta.allowDownload,
      hide_uploader_name: meta.hideUploaderName,
      group_id: meta.groupId !== null && meta.groupId !== undefined ? meta.groupId : routeMeta.group_id
    });
    setRouteTitleLabel(routeMeta.title);
    currentFilename = routeMeta.title + ".gpx";
  }

  // ---------- Ny fil ----------
  function createEmptyGPXDocument() {
    const ns = "http://www.topografix.com/GPX/1/1";
    const doc = document.implementation.createDocument(ns, "gpx", null);
    const gpx = doc.documentElement;
    gpx.setAttribute("version", "1.1");
    gpx.setAttribute("creator", "GPX-editor");
    return { xml: doc, ns: ns };
  }

  function startNewRoute() {
    const baseObj = createEmptyGPXDocument();
    const newState = { xml: baseObj.xml, ns: baseObj.ns, segments: [], waypoints: [] };

    setCurrentRouteId(null);
    metaConfirmed = false;
    metaBehavior.setLoadedVisibility("private");
    setRouteTitleLabel("");

    loadState(newState, "Ny rute (ugemt)");
    render._hasFitBounds = true;
    setStatus("Klik på kortet for at placere første trackpoint. Ruten gemmes først, når du klikker Gem.");
  }

  function loadState(newState, filename) {
    state = newState;
    currentFilename = filename;

    historyStack = [];
    redoStack = [];
    updateUndoRedoButtons();

    render._hasFitBounds = false;
    markAllSaved();
    render();
    setStatus("");
  }

  function countPoints() {
    return state.segments.reduce((sum, seg) => sum + seg.length, 0);
  }

  // ---------- Indlæsning fra platformen ----------
  async function loadFromServer(routeId) {
    if (!routeId) return;
    try {
      const res = await fetch("../editor-load.php?id=" + encodeURIComponent(routeId));
      const data = await res.json();
      if (!res.ok || !data.success) {
        throw new Error((data.errors && data.errors.join(" ")) || "Ukendt fejl.");
      }

      setCurrentRouteId(data.route_id);
      metaConfirmed = true; // eksisterende rute — metadata ændres kun via "Rediger metadata"
      routeMeta = data;
      enabledFunctions = Array.isArray(data.enabled_functions)
        ? (window.gpxExpandFunctions ? window.gpxExpandFunctions(data.enabled_functions) : data.enabled_functions)
        : null;
      availableFunctionsAtSave = availableFunctionKeys(data.gpx_content);
      setRouteTitleLabel(data.title);
      const filename = data.title + ".gpx";
      const thinned = await askThin(data.gpx_content, data.route_id);
      if (thinned) {
        // Udtyndingen er en almindelig, ugemt ændring: originalen ligger i
        // fortryd-historikken, og Gem sender thin_interval med.
        loadState(parseGPX(thinned.content), filename);
        historyStack = [{ xmlStr: data.gpx_content, filename: filename }];
        updateUndoRedoButtons();
        thinnedSinceSave = true;
        markDirty();
        setStatus(`Udtyndet (${thinned.label}): ${thinned.removed} af ${thinned.before} punkter fjernet. Tryk Gem for at beholde det`);
      } else {
        loadState(parseGPX(data.gpx_content), filename);
      }
    } catch (e) {
      setStatus("Fejl ved indlæsning fra platformen: " + e.message);
    }
  }

  // ---------- Udtynding ved mange punkter ----------
  // Editoren tegner et trækbart håndtag pr. punkt, så meget tætte filer
  // (fx ét punkt i sekundet fra et ur) gør den langsom, især på telefoner.
  // Spørg derfor, før ruten tegnes. "Fortsæt uden" huskes pr. rute i
  // browseren; lukkes dialogen med krydset, spørges der igen næste gang.
  // Svarer med { content, label, removed, before } eller null.
  function askThin(content, routeId) {
    const G = window.GpxTools;
    const skipKey = "mitspor-editor-udtynd-nej-" + routeId;
    let gpx;
    try {
      if (localStorage.getItem(skipKey)) return Promise.resolve(null);
    } catch (e) {
      /* lagring utilgængelig: spørg bare */
    }
    try {
      gpx = G.parse(content);
    } catch (e) {
      return Promise.resolve(null); // parseGPX() melder selv fejlen bagefter
    }
    const before = G.pointCount(gpx);
    if (before <= THIN_SUGGEST_POINTS) return Promise.resolve(null);

    const dialog = el("thin-dialog");
    const buttons = Array.from(dialog.querySelectorAll("[data-thin-tolerance]"));
    const plans = {};
    buttons.forEach((b) => {
      const tolerance = parseInt(b.getAttribute("data-thin-tolerance"), 10);
      const label = b.getAttribute("data-label") || b.textContent.trim();
      b.setAttribute("data-label", label);
      plans[tolerance] = G.thinPlan(gpx, tolerance);
      const left = before - plans[tolerance].total;
      b.textContent = `${label}: ${left.toLocaleString("da-DK")} punkter tilbage`;
      b.disabled = plans[tolerance].total === 0;
    });
    el("thin-dialog-heading").textContent = "Mange punkter";
    el("thin-dialog-skip").hidden = false;
    el("thin-dialog-text").textContent =
      `Ruten har ${before.toLocaleString("da-DK")} punkter. Det kan gøre editoren langsom, især på mobiltelefoner. Vil du udtynde den først?`;

    return new Promise((resolve) => {
      function done(result) {
        buttons.forEach((b) => { b.onclick = null; });
        el("thin-dialog-skip").onclick = null;
        el("thin-dialog-close").onclick = null;
        dialog.onclose = null;
        if (dialog.open) dialog.close();
        resolve(result);
      }
      buttons.forEach((b) => {
        b.onclick = () => {
          const tolerance = parseInt(b.getAttribute("data-thin-tolerance"), 10);
          G.applyThin(gpx, plans[tolerance]);
          done({
            content: G.serialize(gpx),
            label: b.getAttribute("data-label"),
            removed: plans[tolerance].total,
            before: before
          });
        };
      });
      el("thin-dialog-skip").onclick = () => {
        try {
          localStorage.setItem(skipKey, "1");
        } catch (e) {
          /* ikke kritisk */
        }
        done(null);
      };
      el("thin-dialog-close").onclick = () => done(null);
      dialog.onclose = () => done(null); // Esc
      dialog.showModal();
    });
  }

  // ---------- Visninger på rutens side ----------
  function availableFunctionKeys(content) {
    const G = window.GpxTools;
    try {
      return G.availableFunctions(G.detectFields(G.parse(content))).map((fn) => fn.key);
    } catch (e) {
      return [];
    }
  }

  // Har brugeren selv valgt visninger (Visning af datafelter), slås de
  // visninger til, som filen først nu har data til (fx højdeprofilen efter
  // "Hent fra internettet"), så de faktisk vises. Svarer null, hvis intet
  // skal sendes med; så beholder serveren valget.
  function enabledFunctionsForSave(content) {
    if (enabledFunctions === null) return null;
    const added = availableFunctionKeys(content).filter(
      (key) => availableFunctionsAtSave.indexOf(key) === -1 && enabledFunctions.indexOf(key) === -1
    );
    return added.length ? enabledFunctions.concat(added) : null;
  }

  // ---------- Værktøjer i menuen (Slet datafelter, Rediger højdedata, Udtynd punkter) ----------
  // Værktøjerne fra assets/gpx-tools.js arbejder på deres egen udgave af
  // filen. Den laves ud fra editorens nuværende rute, og resultatet lægges
  // tilbage som en almindelig ændring, man kan fortryde og skal gemme.
  function toolGpx() {
    return window.GpxTools.parse(serializeGPX(state));
  }

  function applyToolGpx(gpx, message) {
    pushHistory();
    restoreStateFromXml(window.GpxTools.serialize(gpx), currentFilename, false);
    render._hasFitBounds = true;
    render();
    markDirty();
    setStatus(message);
  }

  function fieldLabel(field) {
    return window.gpxFieldLabel ? window.gpxFieldLabel(field) : field;
  }

  // ---- Slet datafelter ----
  function renderStripList() {
    const G = window.GpxTools;
    const list = el("strip-list");
    list.innerHTML = "";
    const detected = G.detectFields(toolGpx());
    if (detected.length === 0) {
      const li = document.createElement("li");
      li.className = "thin-hint";
      li.textContent = "Filen indeholder ingen datafelter ud over positionerne.";
      list.appendChild(li);
    }
    detected.forEach((field) => {
      const li = document.createElement("li");
      li.className = "data-field-row";
      const name = document.createElement("span");
      name.className = "data-field-note";
      name.textContent = fieldLabel(field);
      const del = document.createElement("button");
      del.type = "button";
      del.className = "btn-small btn-danger";
      del.textContent = "Slet";
      del.setAttribute("aria-label", "Slet " + fieldLabel(field));
      del.addEventListener("click", () => stripField(field));
      li.appendChild(name);
      li.appendChild(del);
      list.appendChild(li);
    });
  }

  function stripField(field) {
    const G = window.GpxTools;
    const label = fieldLabel(field);
    const gpx = toolGpx();
    const detected = G.detectFields(gpx);
    // Visninger, der forsvinder fra rutens side, når feltet er væk.
    const after = G.availableFunctions(detected.filter((f) => f !== field)).map((fn) => fn.key);
    const lost = G.availableFunctions(detected).filter((fn) => after.indexOf(fn.key) === -1).map((fn) => fn.label);

    let msg = `Fjern alle "${label}"-værdier fra filen?`;
    if (lost.length) msg += "\n\nDisse visninger forsvinder fra rutens side: " + lost.join(", ") + ".";
    msg += "\n\nDu kan fortryde, og ændringen gemmes først, når du trykker Gem.";
    if (!window.confirm(msg)) return;

    const removed = G.stripField(gpx, field);
    applyToolGpx(gpx, `Fjernede ${removed} "${label}"-værdi(er). Tryk Gem for at beholde det`);
    renderStripList();
  }

  function openStripDialog() {
    if (!state) return;
    renderStripList();
    el("strip-dialog").showModal();
  }

  // ---- Rediger højdedata ----
  let eleBusy = false;

  function meters(v) {
    return Math.round(v).toLocaleString("da-DK") + " m";
  }

  // Samme beregning som serveren (gpx_compute_stats): summen af alle
  // stigninger og fald mellem nabopunkter, inden for hvert segment.
  function eleStats(gpx) {
    const r = { total: 0, withEle: 0, min: null, max: null, gain: 0, loss: 0 };
    gpx.segments.forEach((seg) => {
      let prev = null;
      seg.forEach((p) => {
        r.total++;
        const ele = p.ele === null || p.ele === "" ? null : parseFloat(p.ele);
        if (ele !== null && !isNaN(ele)) {
          r.withEle++;
          r.min = r.min === null ? ele : Math.min(r.min, ele);
          r.max = r.max === null ? ele : Math.max(r.max, ele);
          if (prev !== null) {
            if (ele > prev) r.gain += ele - prev;
            else r.loss += prev - ele;
          }
          prev = ele;
        } else {
          prev = null;
        }
      });
    });
    return r;
  }

  function setEleStatus(text) {
    el("ele-status").textContent = text || "";
    el("ele-status").hidden = !text;
  }

  function renderEleDialog() {
    const gpx = toolGpx();
    const s = eleStats(gpx);
    const rows = [["Punkter med højde", s.withEle.toLocaleString("da-DK") + " af " + s.total.toLocaleString("da-DK")]];
    if (s.withEle > 0) {
      rows.push(["Laveste punkt", meters(s.min)]);
      rows.push(["Højeste punkt", meters(s.max)]);
      rows.push(["Stigning i alt", meters(s.gain)]);
      rows.push(["Fald i alt", meters(s.loss)]);
    }
    const stats = el("ele-stats");
    stats.innerHTML = "";
    rows.forEach((row) => {
      const dt = document.createElement("dt");
      dt.textContent = row[0];
      const dd = document.createElement("dd");
      dd.textContent = row[1];
      stats.appendChild(dt);
      stats.appendChild(dd);
    });
    el("ele-fetch").disabled = eleBusy;
    el("ele-delete").hidden = s.withEle === 0;
    el("ele-delete").disabled = eleBusy;
  }

  function openEleDialog() {
    if (!state) return;
    el("ele-errors").hidden = true;
    if (!eleBusy) setEleStatus("");
    renderEleDialog();
    el("ele-dialog").showModal();
  }

  function fetchEle() {
    const G = window.GpxTools;
    const gpx = toolGpx();
    const points = G.pointCount(gpx);
    if (points === 0) {
      setEleStatus("Ruten har ingen punkter at hente højdedata til.");
      return;
    }
    const hasEle = eleStats(gpx).withEle > 0;
    const ok = window.confirm(
      `Hent højdedata fra internettet til alle ${points} punkter?` +
      (hasEle ? " Det overskriver den højdedata, ruten har nu." : "") +
      " Du kan fortryde, og ændringen gemmes først, når du trykker Gem."
    );
    if (!ok) return;

    // Ruten må ikke ændres, mens højderne hentes: dialogen kan ikke lukkes
    // undervejs, og resultatet lægges oven på den rute, der blev sendt af sted.
    eleBusy = true;
    el("ele-errors").hidden = true;
    renderEleDialog();
    G.fetchElevation(gpx, {
      proxyUrl: "../elevation-proxy.php",
      onProgress: (done, total) => setEleStatus(`Henter højdedata… (${done}/${total})`)
    })
      .then((result) => {
        eleBusy = false;
        applyToolGpx(gpx, `Højdedata hentet for ${result.updated} af ${result.total} punkter. Tryk Gem for at beholde det`);
        setEleStatus(`Højdedata hentet for ${result.updated} af ${result.total} punkter. Tryk Gem for at beholde det.`);
        renderEleDialog();
      })
      .catch((e) => {
        eleBusy = false;
        setEleStatus("");
        el("ele-errors").textContent = "Fejl ved hentning af højdedata: " + e.message;
        el("ele-errors").hidden = false;
        renderEleDialog();
      });
  }

  function deleteEle() {
    if (!window.confirm("Fjern al højdedata fra ruten? Du kan fortryde, og ændringen gemmes først, når du trykker Gem.")) return;
    const gpx = toolGpx();
    const removed = window.GpxTools.stripField(gpx, "ele");
    applyToolGpx(gpx, `Fjernede ${removed} højdeværdi(er). Tryk Gem for at beholde det`);
    setEleStatus(`Fjernede ${removed} højdeværdi(er). Tryk Gem for at beholde det.`);
    renderEleDialog();
  }

  // ---- Udtynd punkter ----
  // Samme dialog som spørgsmålet ved mange punkter (askThin), men uden
  // "Fortsæt uden at udtynde".
  function openThinTool() {
    if (!state) return;
    const G = window.GpxTools;
    const gpx = toolGpx();
    const before = G.pointCount(gpx);
    const dialog = el("thin-dialog");
    const buttons = Array.from(dialog.querySelectorAll("[data-thin-tolerance]"));
    const plans = {};
    buttons.forEach((b) => {
      const tolerance = parseInt(b.getAttribute("data-thin-tolerance"), 10);
      const label = b.getAttribute("data-label") || b.textContent.trim();
      b.setAttribute("data-label", label);
      plans[tolerance] = G.thinPlan(gpx, tolerance);
      const left = before - plans[tolerance].total;
      b.textContent = `${label}: ${left.toLocaleString("da-DK")} punkter tilbage`;
      b.disabled = plans[tolerance].total === 0;
      b.onclick = () => {
        G.applyThin(gpx, plans[tolerance]);
        thinnedSinceSave = true;
        dialog.close();
        applyToolGpx(gpx, `Udtyndet (${label}): ${plans[tolerance].total} af ${before} punkter fjernet. Tryk Gem for at beholde det`);
      };
    });
    el("thin-dialog-heading").textContent = "Udtynd punkter";
    el("thin-dialog-text").textContent = buttons.every((b) => b.disabled)
      ? `Ruten har ${before.toLocaleString("da-DK")} punkter. Der er ingen punkter, der kan fjernes.`
      : `Ruten har ${before.toLocaleString("da-DK")} punkter. Hvor kraftigt skal den udtyndes?`;
    el("thin-dialog-skip").hidden = true;
    el("thin-dialog-close").onclick = () => dialog.close();
    dialog.onclose = () => {
      buttons.forEach((b) => { b.onclick = null; });
      el("thin-dialog-close").onclick = null;
      dialog.onclose = null;
    };
    dialog.showModal();
  }

  // ---------- Gemning (til platformen) ----------
  async function saveToServer() {
    if (!state) return false;

    const isNewRoute = !currentRouteId;
    let meta = null;

    if (isNewRoute) {
      // En ny rute oprettes først nu. Serveren kræver mindst 2 punkter.
      if (countPoints() < 2) {
        setStatus("Tegn mindst to punkter, før ruten kan gemmes.");
        return false;
      }

      // Første gem: lad brugeren sætte titel, type og synlighed.
      if (!metaConfirmed) {
        openNewRouteMetaDialog();
        return false;
      }

      meta = getMetaFromForm();

      if (!meta.title || meta.title.trim().length < 2) {
        metaConfirmed = false;
        openNewRouteMetaDialog();
        return false;
      }

      if (meta.visibility === "public" && !meta.publicNoticeAck) {
        metaConfirmed = false;
        openNewRouteMetaDialog();
        return false;
      }
    }

    const content = serializeGPX(state);

    const body = new URLSearchParams({
      csrf_token: csrfToken(),
      route_id: currentRouteId || "",
      gpx_data: content
    });
    if (thinnedSinceSave) {
      // Får serveren til at gemme originalen første gang (Gendan original fil).
      body.append("thin_interval", "1");
    }
    const enabledForSave = enabledFunctionsForSave(content);
    if (enabledForSave) body.append("enabled_functions", JSON.stringify(enabledForSave));
    if (meta) {
      // Metadata sendes KUN ved oprettelse. Ved en eksisterende rute beholder
      // editor-save.php de værdier, der ligger i databasen (de ændres i
      // kontrolpanelet).
      body.append("title", meta.title);
      body.append("description", meta.description);
      body.append("activity_type_id", meta.activityTypeId);
      body.append("route_type", meta.routeType);
      body.append("visibility", meta.visibility);
      body.append("allow_download", meta.allowDownload ? "1" : "0");
      body.append("hide_uploader_name", meta.hideUploaderName ? "1" : "0");
      body.append("public_notice_ack", meta.publicNoticeAck ? "1" : "0");
      if (meta.groupId !== null && meta.groupId !== undefined) body.append("group_id", meta.groupId);
    }

    try {
      const res = await fetch("../editor-save.php", { method: "POST", body });
      const data = await res.json();

      if (!res.ok || !data.success) {
        throw new Error((data.errors && data.errors.join(" ")) || `Ukendt fejl (status ${res.status}).`);
      }

      const wasNewRoute = !currentRouteId;
      setCurrentRouteId(data.route_id);
      thinnedSinceSave = false;
      if (enabledForSave) enabledFunctions = enabledForSave;
      availableFunctionsAtSave = availableFunctionKeys(content);
      markServerSaved();
      setStatus("Gemt på platformen");

      if (wasNewRoute) {
        // Ruten findes nu på platformen: fra nu af er adressen ?route=<id>
        // (uden at genindlæse siden), og alle senere Gem er opdateringer.
        metaConfirmed = true;
        setRouteTitleLabel(meta.title);
        metaBehavior.setLoadedVisibility(meta.visibility);
        routeMeta = {
          title: meta.title.trim(),
          description: meta.description,
          activity_type_id: parseInt(meta.activityTypeId, 10),
          route_type: meta.routeType,
          visibility: meta.visibility,
          allow_download: meta.allowDownload,
          hide_uploader_name: meta.hideUploaderName,
          group_id: meta.groupId || ""
        };
        try {
          window.history.replaceState(null, "", "?route=" + encodeURIComponent(String(data.route_id)));
        } catch (e) {
          /* ikke kritisk */
        }
      }

      return true;
    } catch (e) {
      console.error("Fejl ved gem:", e);
      setStatus("Fejl ved gem: " + e.message);
      // Ny rute: åbn dialogen igen næste gang, så en evt. ugyldig titel/valg kan rettes.
      if (isNewRoute) metaConfirmed = false;
      return false;
    }
  }

  // ---------- Event bindings ----------
  function bindUI() {
    // ---- Waypoint uden for ruten ----
    // Knappen viser altid, hvad et klik på kortet gør lige nu: rød nål
    // "Waypoint" når tilstanden er slået til, blå prik "Rute" ellers
    // (i stedet for kun at skifte baggrundsfarve på selve knappen).
    const btnWaypointMode = el("btn-waypoint-mode");
    function updateWaypointModeButtonLabel() {
      if (!btnWaypointMode) return;
      btnWaypointMode.innerHTML = waypointPlacementMode
        ? '📍 <span class="btn-text">Waypoint-tilstand</span>'
        : '🔵 <span class="btn-text">Rute-tilstand</span>';
    }
    if (btnWaypointMode) {
      updateWaypointModeButtonLabel();
      btnWaypointMode.addEventListener("click", () => {
        waypointPlacementMode = !waypointPlacementMode;
        suppressNextMapClick = true;
        updateWaypointModeButtonLabel();
        btnWaypointMode.setAttribute("aria-pressed", waypointPlacementMode ? "true" : "false");
        map.getContainer().classList.toggle("waypoint-mode-cursor", waypointPlacementMode);
        setStatus(
          waypointPlacementMode
            ? "Waypoint-tilstand slået til — klik et vilkårligt sted på kortet for at sætte et waypoint (behøver ikke ligge på ruten)."
            : "Waypoint-tilstand slået fra — klik på kortet tegner ruten igen som normalt."
        );
      });
    }

    el("btn-save-server").addEventListener("click", () => saveToServer());
    // ---- Vis side ----
    el("btn-view-page").addEventListener("click", () => {
      if (!currentRouteId) return;
      window.location.href = "../route-view.php?id=" + encodeURIComponent(currentRouteId);
    });

    // ---- Indsæt waypoint-dialog ----
    const insertWaypointDialog = el("insert-waypoint-dialog");
    if (insertWaypointDialog) {
      el("insert-waypoint-dialog-close").addEventListener("click", () => insertWaypointDialog.close());
      el("btn-save-insert-waypoint").addEventListener("click", saveInsertWaypointDialog);
      const deleteInsertWaypointBtn = el("btn-delete-insert-waypoint");
      if (deleteInsertWaypointBtn) deleteInsertWaypointBtn.addEventListener("click", deleteWaypointFromTextDialog);
      insertWaypointDialog.addEventListener("click", (e) => {
        if (e.target === insertWaypointDialog) insertWaypointDialog.close();
      });
    }

    // ---- Billed-dialog (indsæt/rediger billeder på et waypoint) ----
    const editImagesDialog = el("edit-images-dialog");
    if (editImagesDialog) {
      el("edit-images-dialog-close").addEventListener("click", () => editImagesDialog.close());
      el("btn-save-edit-images").addEventListener("click", saveEditImagesDialog);
      el("btn-add-more-images").addEventListener("click", addMoreImagesToDialog);
      const deleteImagesWaypointBtn = el("btn-delete-edit-images-waypoint");
      if (deleteImagesWaypointBtn) deleteImagesWaypointBtn.addEventListener("click", deleteWaypointFromImagesDialog);
      editImagesDialog.addEventListener("click", (e) => {
        if (e.target === editImagesDialog) editImagesDialog.close();
      });
    }

    // ---- Vælg blandt allerede uploadede billeder ----
    const imagePickerDialog = el("image-picker-dialog");
    if (imagePickerDialog) {
      el("btn-pick-existing-images").addEventListener("click", openImagePickerDialog);
      // Ugemte ændringer går tabt, når man forlader editoren. 'beforeunload'
      // advarer ikke altid i appens WebView, så linket stopper selv.
      el("link-manage-images").addEventListener("click", (e) => {
        if (!isAnyDirty()) return;
        e.preventDefault();
        const errEl = el("image-picker-errors");
        errEl.textContent = "Gem ruten først. Ellers går dine ugemte ændringer tabt, når du forlader editoren.";
        errEl.hidden = false;
      });
      el("image-picker-dialog-close").addEventListener("click", () => imagePickerDialog.close());
      el("btn-add-picked-images").addEventListener("click", addPickedImagesToDialog);
      imagePickerDialog.addEventListener("click", (e) => {
        if (e.target === imagePickerDialog) imagePickerDialog.close();
      });
    }

    // ---- Metadata-dialog ----
    // En NY rute: åbnes af saveToServer(), når ruten gemmes første gang.
    // En gemt rute: "Rediger metadata" i menuen; gemmes med det samme.
    const editMetaDialog = el("edit-meta-dialog");
    const editMetaClose = el("edit-meta-dialog-close");
    const btnSaveMetaDialog = el("btn-save-meta-dialog");
    const editMetaErrors = el("edit-meta-errors");

    function showMetaDialogError(message) {
      if (editMetaErrors) {
        editMetaErrors.textContent = message;
        editMetaErrors.hidden = false;
      } else {
        alert(message);
      }
    }

    if (editMetaClose && editMetaDialog) {
      editMetaClose.addEventListener("click", () => editMetaDialog.close());
    }
    if (btnSaveMetaDialog && editMetaDialog) {
      btnSaveMetaDialog.addEventListener("click", async () => {
        if (editMetaErrors) editMetaErrors.hidden = true;

        const meta = getMetaFromForm();

        if (!meta.title || meta.title.trim().length < 2) {
          showMetaDialogError("Titlen skal være mindst 2 tegn.");
          return;
        }
        if (!metaBehavior.requireGdprAckIfPublic()) {
          return;
        }

        if (currentRouteId) {
          btnSaveMetaDialog.disabled = true;
          try {
            await saveExistingMeta(meta);
            editMetaDialog.close();
            setStatus("Metadata gemt");
          } catch (e) {
            showMetaDialogError(e.message || "Der skete en fejl. Prøv igen.");
          } finally {
            btnSaveMetaDialog.disabled = false;
          }
          return;
        }

        metaConfirmed = true;
        editMetaDialog.close();
        await saveToServer();
      });
    }
    if (editMetaDialog) {
      editMetaDialog.addEventListener("click", (e) => {
        if (e.target === editMetaDialog) editMetaDialog.close();
      });
    }
    // ---- Hjælp-dialog ----
    const helpDialog = el("help-dialog");
    const btnCloseHelp = el("btn-close-help");

    // ---- Menuen (☰) ----
    const btnMenu = el("btn-menu");
    const menu = el("editor-menu");
    function setMenuOpen(open) {
      menu.hidden = !open;
      btnMenu.setAttribute("aria-expanded", open ? "true" : "false");
      if (open) {
        // "Rediger metadata" kræver, at ruten findes på platformen.
        const metaItem = menu.querySelector('[data-menu="meta"]');
        metaItem.disabled = !currentRouteId || !routeMeta;
        metaItem.title = metaItem.disabled ? "Gem ruten først" : "";
        menu.querySelectorAll('[data-menu="strip"], [data-menu="ele"], [data-menu="thin"]').forEach((b) => {
          b.disabled = !state || countPoints() === 0;
        });
      }
    }
    btnMenu.addEventListener("click", (e) => {
      e.stopPropagation();
      setMenuOpen(menu.hidden);
    });
    document.addEventListener("click", (e) => {
      if (!menu.hidden && !menu.contains(e.target)) setMenuOpen(false);
    });
    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape" && !menu.hidden) {
        setMenuOpen(false);
        btnMenu.focus();
      }
    });
    const menuActions = {
      meta: openExistingMetaDialog,
      strip: openStripDialog,
      ele: openEleDialog,
      thin: openThinTool,
      help: () => helpDialog.showModal(),
      about: () => aboutDialog.showModal()
    };
    const aboutDialog = el("about-dialog");
    el("about-dialog-close").addEventListener("click", () => aboutDialog.close());
    aboutDialog.addEventListener("click", (e) => {
      if (e.target === aboutDialog) aboutDialog.close();
    });
    // Privatlivspolitikken forlader editoren; ugemte ændringer går så tabt.
    // 'beforeunload' advarer ikke altid i appens WebView, så linket spørger selv.
    el("menu-privacy").addEventListener("click", (e) => {
      setMenuOpen(false);
      if (isAnyDirty() && !window.confirm("Du har ugemte ændringer, som går tabt, hvis du forlader editoren. Vil du fortsætte?")) {
        e.preventDefault();
      }
    });
    menu.querySelectorAll("[data-menu]").forEach((item) => {
      item.addEventListener("click", () => {
        setMenuOpen(false);
        menuActions[item.getAttribute("data-menu")]();
      });
    });

    // ---- Værktøjsdialoger ----
    const stripDialog = el("strip-dialog");
    el("strip-dialog-close").addEventListener("click", () => stripDialog.close());
    stripDialog.addEventListener("click", (e) => {
      if (e.target === stripDialog) stripDialog.close();
    });
    const eleDialog = el("ele-dialog");
    el("ele-dialog-close").addEventListener("click", () => {
      if (!eleBusy) eleDialog.close();
    });
    eleDialog.addEventListener("click", (e) => {
      if (e.target === eleDialog && !eleBusy) eleDialog.close();
    });
    eleDialog.addEventListener("cancel", (e) => {
      if (eleBusy) e.preventDefault(); // Esc, mens højderne hentes
    });
    el("ele-fetch").addEventListener("click", fetchEle);
    el("ele-delete").addEventListener("click", deleteEle);

    // ---- Hjælp-dialog for metadata (Rute/Spor og synlighed) ----
    const metaHelpDialog = el("meta-help-dialog");
    const btnOpenMetaHelp = el("btn-open-meta-help");
    const btnCloseMetaHelp = el("btn-close-meta-help");
    if (btnOpenMetaHelp && metaHelpDialog) {
      btnOpenMetaHelp.addEventListener("click", () => metaHelpDialog.showModal());
    }
    if (btnCloseMetaHelp && metaHelpDialog) {
      btnCloseMetaHelp.addEventListener("click", () => metaHelpDialog.close());
    }
    if (metaHelpDialog) {
      metaHelpDialog.addEventListener("click", (e) => {
        if (e.target === metaHelpDialog) metaHelpDialog.close();
      });
    }

    const btnUndo = el("btn-undo");
    const btnRedo = el("btn-redo");
    if (btnUndo) btnUndo.addEventListener("click", performUndo);
    if (btnRedo) btnRedo.addEventListener("click", performRedo);

    if (btnCloseHelp && helpDialog) {
      btnCloseHelp.addEventListener("click", () => helpDialog.close());
    }
    if (helpDialog) {
      helpDialog.addEventListener("click", (e) => {
        if (e.target === helpDialog) {
          helpDialog.close();
        }
      });
    }

    window.addEventListener("keydown", (e) => {
      if (["INPUT", "TEXTAREA"].includes(document.activeElement.tagName)) return;

      if (e.ctrlKey || e.metaKey) {
        if (e.key.toLowerCase() === "z") {
          e.preventDefault();
          if (e.shiftKey) performRedo();
          else performUndo();
        } else if (e.key.toLowerCase() === "y") {
          e.preventDefault();
          performRedo();
        }
      }
    });

    // Pilen til venstre går ét trin tilbage i browserens historik (dér hvor
    // brugeren kom fra — kontrolpanelet, rutens side ...), ikke altid til
    // kontrolpanelet. Er der ingen historik at gå tilbage til (fx hvis siden er
    // åbnet fra et bogmærke eller i en ny fane), bruges linkets almindelige mål.
    // Ugemte ændringer fanges stadig af 'beforeunload' herunder.
    const backLink = el("btn-back");
    if (backLink) {
      backLink.addEventListener("click", (e) => {
        let cameFromThisSite = false;
        try {
          cameFromThisSite = !!document.referrer && new URL(document.referrer).origin === window.location.origin;
        } catch (err) {
          cameFromThisSite = false;
        }
        if (cameFromThisSite && window.history.length > 1) {
          e.preventDefault();
          window.history.back();
        }
      });
    }

    window.addEventListener("beforeunload", (e) => {
      if (isAnyDirty()) {
        e.preventDefault();
        e.returnValue = "";
      }
    });
  }

  // ---------- Start ----------
  document.addEventListener("DOMContentLoaded", () => {
    initMap();
    bindUI();
    metaBehavior.refresh();
    updateSaveButtonState();

    const initialRouteId = el("initial-route-id").value;
    if (initialRouteId) {
      loadFromServer(initialRouteId);
    } else {
      startNewRoute();
    }
  });
})();

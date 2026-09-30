/*
 * SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
 * SPDX-License-Identifier: LicenseRef-Larka-Proprietary
 *
 * This file is part of Larka, proprietary software by Mickaël Larcin.
 * All rights reserved. Use is subject to the license terms.
 */

/**
 * Larka — Annuaire cartographié (« Plans » côté Demandeur)
 *
 * Vue LECTURE SEULE : bâtiment → étage → points-personnes.
 *   - Barre de recherche avec AUTOCOMPLÉTION (composant PersonPicker partagé).
 *   - Points = pastilles PHOTO (si dispo) sinon initiales, sur le fond de plan.
 *   - Vraie LISTE latérale des personnes de l'étage, reliée aux points.
 *   - Clic (liste, marqueur ou suggestion) → point spécifique mis en évidence + fiche.
 *
 * Présence temps réel + agenda partagé via Microsoft Graph : optionnel,
 * activé par la configuration du tenant.
 * Point d'entrée : renderAnnuaire()
 */

/* global App, AnnuaireApi, apiRequest, API_BASE, toast, PersonPicker */

let _an = {
  map: null, batiments: [], etages: [], points: [],
  batiment: null, etage: null, markers: {}, selected: null,
  leafletOk: false, consent: null, picker: null, presence: {},
};

// Traduction du statut Microsoft Graph → couleur + libellé FR
function _anPresenceInfo(av, act) {
  const a = (av || '').toLowerCase(), k = (act || '').toLowerCase();
  if (a === 'available') return { c: '#16a34a', label: 'Disponible' };
  if (a === 'away' || a === 'berightback') return { c: '#f59e0b', label: 'Absent' };
  if (a === 'busy') return (k === 'inameeting' || k === 'inacall' || k === 'incall')
    ? { c: '#a855f7', label: k === 'inameeting' ? 'En réunion' : 'En appel' }
    : { c: '#dc2626', label: 'Occupé' };
  if (a === 'donotdisturb') return { c: '#dc2626', label: 'Ne pas déranger' };
  if (a === 'offline') return { c: '#9ca3af', label: 'Hors ligne' };
  return null;
}

function _anLoadLeaflet() {
  return new Promise(resolve => {
    if (_an.leafletOk && window.L) return resolve();
    if (!document.querySelector('link[href*="leaflet"]')) {
      const lk = document.createElement('link');
      lk.rel = 'stylesheet'; lk.href = 'js/vendor/leaflet/leaflet.css';
      document.head.appendChild(lk);
    }
    if (window.L) { _an.leafletOk = true; return resolve(); }
    const s = document.createElement('script');
    s.src = 'js/vendor/leaflet/leaflet.js';
    s.onload = () => { _an.leafletOk = true; resolve(); };
    document.head.appendChild(s);
  });
}

function _anEsc(s) {
  return String(s == null ? '' : s).replace(/[&<>"']/g, c =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

async function renderAnnuaire() {
  const main = document.getElementById('mainContent');
  if (!main) return;
  // Les onglets sont dans la barre de filtres ci-dessous, pas dans la barre du
  // haut : le choix de vue appartient au plan, pas à l'application.
  main.innerHTML = '<div style="padding:40px;text-align:center;color:var(--gray-text)">⏳ Chargement de l\'annuaire…</div>';
  try {
    await _anLoadLeaflet();
    _an.batiments = await AnnuaireApi.getBatiments();
  } catch (e) {
    main.innerHTML = '<div style="padding:40px;text-align:center;color:#e74c3c">Erreur : ' + _anEsc(e.message || e) + '</div>';
    return;
  }
  if (!_an.batiments.length) {
    main.innerHTML = '<div class="card" style="margin:24px;padding:32px;text-align:center;color:var(--gray-text)">'
      + '<div style="font-size:42px;margin-bottom:12px;opacity:.4">🗺️</div>'
      + 'Aucun plan disponible pour le moment.<br><span style="font-size:12px">Un gestionnaire doit créer des bâtiments et rattacher des personnes aux points.</span></div>';
    return;
  }
  if (!_an.batiment) _an.batiment = _an.batiments[0];
  await _anLoadEtages();
  _anRenderShell(main);
}

async function _anLoadEtages() {
  _an.etages = _an.batiment ? await AnnuaireApi.getEtages(_an.batiment.Id) : [];
  _an.etage = _an.etages.length ? _an.etages[0] : null;
}

function _anRenderShell(main) {
  const batOpts = _an.batiments.map(b =>
    '<option value="' + b.Id + '" ' + (b.Id === _an.batiment?.Id ? 'selected' : '') + '>' + _anEsc(b.Nom) + '</option>'
  ).join('');
  const etgOpts = _an.etages.map(e => {
    const niv = (e.Niveau === 0 || e.Niveau) ? ('N' + e.Niveau + ' — ') : '';
    return '<option value="' + e.Id + '" ' + (e.Id === _an.etage?.Id ? 'selected' : '') + '>' + _anEsc(niv + (e.Nom || '?')) + '</option>';
  }).join('');

  main.innerHTML = `
    <div style="display:flex;flex-direction:column;gap:12px;padding:12px">
      <div class="card" style="padding:12px;display:flex;flex-wrap:wrap;gap:12px;align-items:end">
        ${typeof plansTabBar==='function'?plansTabBar('annuaire'):''}
        <div class="form-group" style="margin:0;min-width:160px">
          <label class="form-label">Bâtiment</label>
          <select class="form-control" id="anBat" onchange="_anOnBat(this.value)">${batOpts}</select>
        </div>
        <div class="form-group" style="margin:0;min-width:160px">
          <label class="form-label">Étage</label>
          <select class="form-control" id="anEtg" onchange="_anOnEtage(this.value)">${etgOpts || '<option>—</option>'}</select>
        </div>
        <div class="form-group" style="margin:0;flex:1;min-width:220px">
          <label class="form-label">Rechercher une personne</label>
          <div id="anSearchMount"></div>
        </div>
        <button class="btn btn-secondary btn-sm" onclick="_anOpenConsent()" title="Choisir si votre présence est visible sur les plans">🔒 Mon partage</button>
      </div>

      <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:stretch">
        <div class="card" style="flex:2;min-width:320px;padding:0;overflow:hidden">
          <div id="anMap" style="width:100%;height:62vh;min-height:440px;background:#f8fafc"></div>
        </div>
        <div class="card" style="flex:1;min-width:260px;padding:12px;display:flex;flex-direction:column;gap:12px">
          <div>
            <div style="font-size:12px;color:var(--gray-text);margin-bottom:6px">Personnes sur cet étage <span id="anCount"></span></div>
            <div id="anList" style="border:1px solid var(--gray-border);border-radius:10px;overflow:auto;max-height:34vh"></div>
          </div>
          <div id="anFiche"></div>
        </div>
      </div>

      <!-- Agenda : bandeau PLEINE LARGEUR sous le plan. C'est le seul endroit
           où une vue semaine tient en 7 colonnes lisibles ; la colonne de
           droite est trop étroite pour autre chose qu'une liste. -->
      <div class="card" id="anCal" style="margin-top:12px;padding:12px;display:none"></div>
    </div>`;

  setTimeout(() => _anInitMap(), 60);
}

async function _anOnBat(id) {
  _an.batiment = _an.batiments.find(b => b.Id == id) || null;
  await _anLoadEtages();
  _anRenderShell(document.getElementById('mainContent'));
}
async function _anOnEtage(id) {
  _an.etage = _an.etages.find(e => e.Id == id) || null;
  _anInitMap();
}

async function _anInitMap() {
  const mc = document.getElementById('anMap');
  if (!mc) return;
  _an.selected = null;
  if (!_an.etage) { mc.innerHTML = '<div style="padding:40px;text-align:center;color:var(--gray-text)">Aucun étage.</div>'; return; }

  const et = _an.etage, w = et.FondLargeur || 1000, h = et.FondHauteur || 700;
  if (_an.map) { _an.map.remove(); _an.map = null; }
  _an.markers = {}; mc.innerHTML = '';

  const bounds = [[0, 0], [h, w]];
  _an.map = L.map('anMap', { crs: L.CRS.Simple, minZoom: -3, maxZoom: 5, zoomSnap: 0.25, attributionControl: false });
  _an.map.fitBounds(bounds);

  if (et.FondImage) {
    const url = API_BASE + '?action=plans_fond_image&etage_id=' + et.Id;
    // Les plans sont souvent des PDF : L.imageOverlay ne sait pas les afficher.
    // On réplique le rendu admin (pdf.js + withCredentials pour l'auth) ; sinon image directe.
    if (String(et.FondImage).toLowerCase().endsWith('.pdf')) {
      _anDrawGrid(w, h);              // repère visuel en attendant le rendu du PDF
      _anRenderPdfFond(url, bounds);
    } else {
      const ov = L.imageOverlay(url, bounds, { errorOverlayUrl: '' }).addTo(_an.map);
      ov.on('error', () => { _anDrawGrid(w, h); toast && toast('Image de fond introuvable', 'error'); });
    }
  } else {
    _anDrawGrid(w, h);
  }

  // Facteur de taille des marqueurs défini au niveau de l'étage (cohérent avec l'éditeur).
  _an.markerScale = (parseFloat(et.TailleMarqueurs) > 0) ? parseFloat(et.TailleMarqueurs) : 1;

  try { _an.points = await AnnuaireApi.getPoints(et.Id); }
  catch (e) { _an.points = []; toast && toast(e.message || 'Erreur points', 'error'); }

  _an.points.forEach(pt => _anAddMarker(pt, false));

  // Textes du plan explicitement marqués « afficher dans l'annuaire » (lecture seule).
  try {
    const textes = await AnnuaireApi.getTextes(et.Id);
    (textes || []).forEach(t => _anAddTexte(t));
  } catch (_e) { /* textes optionnels : ne pas bloquer l'affichage du plan */ }

  _anMountSearch();
  _anRenderList('');
  _anShowFiche(null);
  _anLoadPresence();
  _anStartPresenceRefresh();
}

// Grille de repère (fallback quand pas de fond image, ou en attendant le rendu PDF)
function _anDrawGrid(w, h) {
  if (!_an.map) return;
  const g = L.layerGroup().addTo(_an.map), step = 50;
  for (let x = 0; x <= w; x += step) L.polyline([[0, x], [h, x]], { color: '#e5e7eb', weight: .5, interactive: false }).addTo(g);
  for (let y = 0; y <= h; y += step) L.polyline([[y, 0], [y, w]], { color: '#e5e7eb', weight: .5, interactive: false }).addTo(g);
}

// Rendu d'un fond PDF via pdf.js (identique à l'éditeur de plans admin).
// withCredentials:true est indispensable : l'endpoint plans_fond_image exige une session.
async function _anRenderPdfFond(url, bounds) {
  const mapAtCall = _an.map; // garde-fou si l'utilisateur change d'étage pendant le rendu
  try {
    if (!window.pdfjsLib) {
      await new Promise((r, j) => { const s = document.createElement('script'); s.src = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js'; s.onload = r; s.onerror = () => j(new Error('PDF.js')); document.head.appendChild(s); });
      window.pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
    }
    const pdf = await window.pdfjsLib.getDocument({ url, withCredentials: true }).promise;
    const page = await pdf.getPage(1);
    const v0 = page.getViewport({ scale: 1 }), scale = Math.min(2400, Math.max(1200, v0.width * 2)) / v0.width, vp = page.getViewport({ scale });
    const cv = document.createElement('canvas'); cv.width = vp.width; cv.height = vp.height;
    await page.render({ canvasContext: cv.getContext('2d'), viewport: vp }).promise;
    if (_an.map && _an.map === mapAtCall) L.imageOverlay(cv.toDataURL('image/png'), bounds).addTo(_an.map);
  } catch (e) {
    toast && toast('PDF: ' + (e.message || 'rendu impossible'), 'error');
  }
}

// Récupère la présence (statut) de toutes les personnes de l'étage en un appel
// Le statut Teams change en permanence : sans ceci il fallait recharger la page
// (F5). On rafraîchit périodiquement, et la minuterie s'arrête d'elle-même dès
// que la page Plans n'est plus à l'écran — sinon elle continuerait d'interroger
// l'API indéfiniment après la navigation.
const _AN_PRESENCE_INTERVAL_MS = 60000;
function _anStartPresenceRefresh() {
  if (_an.presenceTimer) { clearInterval(_an.presenceTimer); _an.presenceTimer = null; }
  _an.presenceTimer = setInterval(async () => {
    if (!document.getElementById('anList')) {   // page quittée
      clearInterval(_an.presenceTimer); _an.presenceTimer = null; return;
    }
    if (document.hidden) return;                // onglet en arrière-plan : on économise
    await _anLoadPresence();
    // Conserver le texte saisi dans le filtre (keepQuery=true), sinon le
    // rafraîchissement effacerait la recherche en cours sous les doigts.
    const qEl = document.getElementById('anListQuery');
    _anRenderList(qEl ? qEl.value : '', true);
    // _an.selected est un elementId, pas un point : passer par _an.markers
    // (même motif qu'à la ligne 209).
    if (_an.selected && _an.markers[_an.selected]) _anShowFiche(_an.markers[_an.selected].pt);
  }, _AN_PRESENCE_INTERVAL_MS);
}

async function _anLoadPresence() {
  _an.presence = {};
  const ids = _an.points.map(p => p.user && p.user.Id).filter(Boolean);
  if (!ids.length || !(typeof AnnuaireApi !== 'undefined' && AnnuaireApi) || !AnnuaireApi.getPresence) return;
  try {
    const res = await AnnuaireApi.getPresence(ids);
    // Mémoriser la cause d'indisponibilité pour l'afficher (au lieu du silence).
    _an.presenceReason = (res && res.available === false) ? (res.reason || 'inconnu') : null;
    if (res && res.presences) {
      _an.presence = res.presences;
      _anRenderList(document.getElementById('anListQuery')?.value || '', !!document.getElementById('anListQuery'));
      // rafraîchir la fiche ouverte
      if (_an.selected && _an.markers[_an.selected]) _anShowFiche(_an.markers[_an.selected].pt);
    }
  } catch (_e) {
    // Le serveur peut répondre 401 (session sans token Microsoft) : ne pas
    // rester muet, l'utilisateur doit savoir quoi faire.
    _an.presenceReason = 'no_ms_token';
  }
}

// Traduit le code renvoyé par l'API en explication actionnable (infobulle).
function _anPresenceWhy() {
  switch (_an.presenceReason) {
    case 'disabled':    return "Présence désactivée : ajoutez \"plans_presence\": { \"actif\": true } dans config.json.";
    case 'no_ms_token': return "Vous n'êtes pas connecté via Microsoft. La présence est une permission déléguée : déconnectez-vous puis reconnectez-vous avec Microsoft.";
    case 'no_ms_id':    return "Cette personne n'a pas de compte Microsoft lié (fiche créée à la main, ou jamais connectée via Microsoft).";
    case 'graph_403':   return "Microsoft a refusé (403) : votre token ne porte pas Presence.Read.All. Reconnectez-vous via Microsoft.";
    case 'no_helper':   return "Module SharePoint non chargé côté serveur.";
    default:            return _an.presenceReason ? ('Microsoft a répondu : ' + _an.presenceReason) : "Statut momentanément indisponible.";
  }
}

function _anLatLng(pt) {
  let coords;
  try { coords = typeof pt.Coords === 'string' ? JSON.parse(pt.Coords) : pt.Coords; } catch (_e) { return null; }
  const ll = Array.isArray(coords?.[0]) ? coords[0] : coords;
  return (Array.isArray(ll) && ll.length >= 2) ? ll : null;
}

function _anAddMarker(pt, selected) {
  const ll = _anLatLng(pt);
  if (!ll) return;
  const u = pt.user || {};
  const scale = (_an.markerScale && _an.markerScale > 0) ? _an.markerScale : 1;
  const size = Math.round((selected ? 40 : 34) * scale);
  const html = (window.PersonPicker)
    ? PersonPicker.pinHtml(u, { selected, color: pt.Couleur, scale })
    : '<div style="width:' + size + 'px;height:' + size + 'px;border-radius:50%;background:' + (pt.Couleur || '#2b7be6') + '"></div>';
  const icon = L.divIcon({ html, className: 'anu-pin', iconSize: [size, size + 7], iconAnchor: [size / 2, size + 7] });
  const m = L.marker(ll, { icon, zIndexOffset: selected ? 1000 : 0 }).addTo(_an.map);
  m.on('click', () => _anSelect(pt.ElementId));
  _an.markers[pt.ElementId] = { marker: m, pt };
}

// Étiquette texte (lecture seule) affichée dans l'annuaire pour les textes marqués.
function _anAddTexte(t) {
  const ll = _anLatLng(t);
  if (!ll) return;
  const color = t.Couleur || '#e11d48';
  const fontSize = parseInt(t.SousType) || 14;
  const html = '<div style="color:' + color + ';font-size:' + fontSize + 'px;font-weight:600;white-space:nowrap;'
    + 'text-shadow:1px 1px 2px rgba(0,0,0,.3);pointer-events:none">' + _anEsc(t.Nom || '') + '</div>';
  const icon = L.divIcon({ html, className: '', iconAnchor: [0, fontSize / 2] });
  L.marker(ll, { icon, interactive: false }).addTo(_an.map);
}

function _anSelect(elementId) {
  const entry = _an.markers[elementId];
  if (!entry) return;
  // redessiner l'ancien et le nouveau marqueur avec le bon état
  const prev = _an.selected;
  _an.selected = elementId;
  [prev, elementId].forEach(id => {
    if (id == null) return;
    const e = _an.markers[id];
    if (!e) return;
    _an.map.removeLayer(e.marker);
    _anAddMarker(e.pt, String(id) === String(elementId));
  });
  const e2 = _an.markers[elementId];
  if (e2) {
    const ll = _anLatLng(e2.pt);
    if (ll) _an.map.setView(ll, Math.max(_an.map.getZoom(), 1), { animate: true });
    _anShowFiche(e2.pt);
    const row = document.getElementById('anRow_' + elementId);
    if (row) row.scrollIntoView({ block: 'nearest' });
    _anRenderList(document.getElementById('anListQuery')?.value || '', true);
  }
}

function _anMountSearch() {
  const el = document.getElementById('anSearchMount');
  if (!el || !window.PersonPicker) return;
  const users = _an.points.map(p => p.user).filter(Boolean);
  _an.picker = PersonPicker.mount(el, {
    users,
    placeholder: 'Nom, service, poste…',
    onSelect: (u) => {
      if (!u) return;
      const pt = _an.points.find(p => p.user && String(p.user.Id) === String(u.Id));
      if (pt) _anSelect(pt.ElementId);
    }
  });
}

function _anRenderList(query, keepQuery) {
  const box = document.getElementById('anList');
  if (!box) return;
  const q = (query || '').trim().toLowerCase();
  const rows = _an.points.filter(pt => {
    const u = pt.user || {};
    return !q || [u.Nom, u.Prenom, u.Service, u.Poste, pt.PointNom].some(v => (v || '').toLowerCase().includes(q));
  });
  document.getElementById('anCount').textContent = '· ' + rows.length;
  const header = '<div style="padding:6px 8px;border-bottom:1px solid var(--gray-border)">'
    + '<input id="anListQuery" class="form-control" placeholder="🔍 Filtrer la liste…" value="' + _anEsc(keepQuery ? query : '') + '" oninput="_anRenderList(this.value,true)" style="height:32px;font-size:13px"></div>';
  const list = rows.map(pt => {
    const u = pt.user || {}, on = String(pt.ElementId) === String(_an.selected);
    const av = window.PersonPicker ? PersonPicker.avatarHtml(u, 30, pt.Couleur) : '';
    const name = ((u.Prenom || '') + ' ' + (u.Nom || '')).trim() || pt.PointNom || 'Personne';
    const sub = [u.Poste, u.Service].filter(Boolean).join(' · ');
    const pr = (u.Id && _an.presence[u.Id]) ? _anPresenceInfo(_an.presence[u.Id].availability, _an.presence[u.Id].activity) : null;
    const dot = pr ? '<span title="' + _anEsc(pr.label) + '" style="width:9px;height:9px;border-radius:50%;background:' + pr.c + ';flex-shrink:0;box-shadow:0 0 0 2px var(--card-bg,#fff)"></span>' : '';
    return '<div id="anRow_' + pt.ElementId + '" onclick="_anSelect(' + pt.ElementId + ')" '
      + 'style="display:flex;gap:10px;align-items:center;padding:8px 10px;cursor:pointer;border-top:1px solid var(--gray-border);'
      + (on ? 'background:var(--gray-bg);' : '') + '">'
      + av
      + '<div style="flex:1;min-width:0"><div style="font-size:13px;font-weight:500">' + _anEsc(name) + '</div>'
      + '<div style="font-size:12px;color:var(--gray-text)">' + _anEsc(sub) + '</div></div>'
      + dot
      + '<span style="color:' + (on ? (pt.Couleur || '#2b7be6') : 'var(--gray-text)') + '">📍</span></div>';
  }).join('') || '<div style="padding:14px;color:var(--gray-text);font-size:13px">Aucun résultat.</div>';
  box.innerHTML = header + list;
  if (keepQuery) {
    const inp = document.getElementById('anListQuery');
    if (inp) { inp.focus(); inp.setSelectionRange(inp.value.length, inp.value.length); }
  }
}

function _anShowFiche(pt) {
  const box = document.getElementById('anFiche');
  if (!box) return;
  if (!pt) {
    box.innerHTML = '<div style="color:var(--gray-text);text-align:center;padding:18px 8px;font-size:13px">Choisissez une personne dans la liste ou sur le plan.</div>';
    // Sans ceci, l'agenda de la personne précédemment sélectionnée resterait
    // affiché sous une fiche vide.
    const cal = document.getElementById('anCal');
    if (cal) { cal.style.display = 'none'; cal.innerHTML = ''; }
    return;
  }
  const u = pt.user || {};
  const nom = _anEsc(((u.Prenom || '') + ' ' + (u.Nom || '')).trim() || pt.PointNom || 'Personne');
  const rows = [];
  const line = (label, val, href) => {
    if (!val) return;
    const v = href ? '<a href="' + href + _anEsc(val) + '">' + _anEsc(val) + '</a>' : _anEsc(val);
    rows.push('<div style="display:flex;justify-content:space-between;gap:12px;padding:6px 0;border-bottom:1px solid var(--gray-border)">'
      + '<span style="color:var(--gray-text);font-size:12px">' + label + '</span>'
      + '<span style="font-size:13px;text-align:right">' + v + '</span></div>');
  };
  line('Service', u.Service);
  line('Poste', u.Poste);
  line('Bureau', u.OfficeLocation);
  line('Étage', _an.etage?.Nom);
  line('Bâtiment', _an.batiment?.Nom);
  line('Téléphone', u.TelPro || u.Tel);
  line('Mobile', u.TelMobile);
  line('E-mail', u.Email, 'mailto:');

  const avatar = window.PersonPicker ? PersonPicker.avatarHtml(u, 56, pt.Couleur) : '';

  // Statut de présence (déjà chargé en batch) + agenda (chargé à la sélection)
  let presenceHtml = '';
  if (u.Id && Number(u.MasquerPresence) === 1) {
    presenceHtml = '<div style="margin-top:12px;font-size:11px;color:var(--gray-text)">🔒 Cette personne a masqué sa présence.</div>';
  } else if (u.Id) {
    const pr = _an.presence[u.Id] ? _anPresenceInfo(_an.presence[u.Id].availability, _an.presence[u.Id].activity) : null;
    const pill = pr
      ? '<span style="display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:500"><span style="width:10px;height:10px;border-radius:50%;background:' + pr.c + '"></span>' + _anEsc(pr.label) + '</span>'
      : '<span style="font-size:12px;color:var(--gray-text);cursor:help;border-bottom:1px dotted var(--gray-text)" title="' + _anEsc(_anPresenceWhy()) + '">Statut indisponible ⓘ</span>';
    // L'agenda a désormais son propre panneau (#anCal) sous la fiche : ici on
    // ne garde que la pastille de statut, qui doit rester collée à l'identité.
    presenceHtml = '<div style="margin-top:12px;padding:10px 12px;border:1px solid var(--gray-border);border-radius:8px">'
      + '<div style="display:flex;justify-content:space-between;align-items:center">' + pill
      + '<span style="font-size:11px;color:var(--gray-text)">Microsoft 365</span></div></div>';
  }

  box.innerHTML =
    '<div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">' + avatar
    + '<div><div style="font-weight:700;font-size:16px">' + nom + '</div>'
    + (pt.Categorie ? '<div style="font-size:12px;color:var(--gray-text)">' + _anEsc(pt.Categorie) + '</div>' : '')
    + '</div></div>'
    + (rows.join('') || '<div style="color:var(--gray-text);font-size:12px">Pas d\'informations complémentaires.</div>')
    + presenceHtml;

  // Charger l'agenda (créneaux occupés) pour la personne sélectionnée
  const calBox = document.getElementById('anCal');
  if (u.Id && Number(u.MasquerPresence) !== 1) {
    if (calBox) calBox.style.display = '';
    _anLoadAgenda(u.Id, u);
  } else if (calBox) { calBox.style.display = 'none'; calBox.innerHTML = ''; }
}

// ═══════════════════════════════════════════════════════════════════════════
// Agenda — vues Jour / Semaine / Mois
// ═══════════════════════════════════════════════════════════════════════════
// Optimisations volontaires :
//  • un seul appel Graph par fenêtre affichée (pas un par jour) ;
//  • cache client par (personne, début, fin) avec péremption courte — naviguer
//    d'avant en arrière ne redemande rien au serveur ;
//  • en vue Mois, le serveur ne renvoie que la trame d'occupation
//    (availabilityView, 1 caractère par heure) au lieu du détail des réunions :
//    réponse ~10× plus légère pour une information équivalente à cette échelle.

const _anCal = { view: 'jour', anchor: new Date(), userId: null, who: '', cache: new Map() };
const _AN_CAL_TTL_MS = 120000;

// Bornes locales de la fenêtre, selon la vue.
function _anCalWindow(view, anchor) {
  const s = new Date(anchor), e = new Date(anchor);
  if (view === 'semaine') {
    const dow = (s.getDay() + 6) % 7;            // lundi = 0
    s.setDate(s.getDate() - dow); s.setHours(0, 0, 0, 0);
    e.setTime(s.getTime()); e.setDate(e.getDate() + 6); e.setHours(23, 59, 0, 0);
  } else if (view === 'mois') {
    s.setDate(1); s.setHours(0, 0, 0, 0);
    e.setMonth(e.getMonth() + 1, 0); e.setHours(23, 59, 0, 0);
  } else {                                        // jour
    s.setHours(0, 0, 0, 0); e.setHours(23, 59, 0, 0);
  }
  return [s, e];
}
const _anIsoUtc = d => d.toISOString().slice(0, 19);   // le serveur attend de l'UTC
const _anHm = d => d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

async function _anCalFetch(userId, s, e) {
  const key = userId + '|' + _anIsoUtc(s) + '|' + _anIsoUtc(e);
  const hit = _anCal.cache.get(key);
  if (hit && (Date.now() - hit.t) < _AN_CAL_TTL_MS) return hit.d;
  const d = await AnnuaireApi.getAgenda(userId, _anIsoUtc(s), _anIsoUtc(e));
  _anCal.cache.set(key, { t: Date.now(), d });
  return d;
}

function _anCalSet(view) { _anCal.view = view; _anLoadAgenda(_anCal.userId); }
function _anCalNav(dir) {
  const a = new Date(_anCal.anchor);
  if (_anCal.view === 'jour') a.setDate(a.getDate() + dir);
  else if (_anCal.view === 'semaine') a.setDate(a.getDate() + 7 * dir);
  else a.setMonth(a.getMonth() + dir);
  _anCal.anchor = a; _anLoadAgenda(_anCal.userId);
}
function _anCalToday() { _anCal.anchor = new Date(); _anLoadAgenda(_anCal.userId); }
function _anCalPick(v) {
  if (!v) return;
  const d = new Date(v + 'T12:00:00');
  if (!isNaN(d)) { _anCal.anchor = d; _anLoadAgenda(_anCal.userId); }
}

function _anCalHeader(s, e) {
  const v = _anCal.view;
  let label;
  if (v === 'jour') label = _anCal.anchor.toLocaleDateString([], { weekday: 'long', day: 'numeric', month: 'long' });
  else if (v === 'semaine') label = s.toLocaleDateString([], { day: 'numeric', month: 'short' }) + ' – ' + e.toLocaleDateString([], { day: 'numeric', month: 'short' });
  else label = _anCal.anchor.toLocaleDateString([], { month: 'long', year: 'numeric' });
  const btn = (id, txt) => '<button onclick="_anCalSet(\'' + id + '\')" style="padding:3px 10px;font-size:11px;border:1px solid var(--gray-border);border-radius:5px;cursor:pointer;background:' + (v === id ? 'var(--primary,#2b7be6)' : 'transparent') + ';color:' + (v === id ? '#fff' : 'var(--text)') + '">' + txt + '</button>';
  const nav = (d, t) => '<button onclick="_anCalNav(' + d + ')" style="padding:1px 9px;border:1px solid var(--gray-border);border-radius:5px;cursor:pointer;background:transparent;color:var(--text)">' + t + '</button>';
  const iso = new Date(_anCal.anchor.getTime() - _anCal.anchor.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
  // Tout sur une ligne : le bandeau est large, inutile d'empiler comme dans
  // l'ancienne colonne étroite. Il s'enroule seulement sur petit écran.
  return '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:10px">'
    + '<span style="font-weight:600;color:var(--text)">📅 Agenda' + (_anCal.who ? ' — ' + _anEsc(_anCal.who) : '') + '</span>'
    + '<span style="display:flex;gap:4px;margin-left:8px">' + btn('jour', 'Jour') + btn('semaine', 'Semaine') + btn('mois', 'Mois') + '</span>'
    + '<span style="display:flex;align-items:center;gap:6px;margin-left:auto">'
    + nav(-1, '‹')
    + '<span style="min-width:190px;text-align:center;font-weight:500;color:var(--text);font-size:12px">' + _anEsc(label) + '</span>'
    + nav(1, '›')
    + '<button onclick="_anCalToday()" style="padding:2px 9px;font-size:11px;border:1px solid var(--gray-border);border-radius:5px;cursor:pointer;background:transparent;color:var(--text)">Auj.</button>'
    + '<input type="date" value="' + iso + '" onchange="_anCalPick(this.value)" style="font-size:11px;padding:2px 5px;border:1px solid var(--gray-border);border-radius:5px;background:transparent;color:var(--text);color-scheme:light dark">'
    + '</span></div>';
}

const _AN_ST = { busy: '#dc2626', tentative: '#f59e0b', oof: '#7c3aed', workingelsewhere: '#0ea5e9' };
const _anStColor = st => _AN_ST[String(st || 'busy').toLowerCase()] || '#dc2626';

function _anCalBodyJour(slots) {
  if (!slots.length) return '<div style="color:#16a34a;padding:8px 0">✓ Aucun créneau occupé ce jour</div>';
  return '<div style="display:flex;flex-direction:column;gap:3px">' + slots.map(sl => {
    const col = _anStColor(sl.status);
    return '<div style="display:flex;gap:10px;align-items:baseline;border-left:3px solid ' + col
      + ';background:' + col + '14;border-radius:0 5px 5px 0;padding:4px 8px">'
      + '<span style="font-variant-numeric:tabular-nums;font-weight:600;color:' + col + ';flex:none;width:104px">'
      + _anHm(new Date(sl.start)) + '–' + _anHm(new Date(sl.end)) + '</span>'
      + '<span style="color:var(--text);min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'
      + (sl.prive
          ? '<span style="opacity:.7">🔒 Privé</span>'
          : _anEsc(sl.subject || '(sans objet)'))
      + '</span></div>';
  }).join('') + '</div>';
}

function _anCalBodySemaine(slots, s) {
  const days = [];
  for (let i = 0; i < 7; i++) { const d = new Date(s); d.setDate(s.getDate() + i); days.push({ d, items: [] }); }
  slots.forEach(sl => {
    const a = new Date(sl.start);
    const i = Math.floor((new Date(a.getFullYear(), a.getMonth(), a.getDate()) - new Date(s.getFullYear(), s.getMonth(), s.getDate())) / 86400000);
    if (i >= 0 && i < 7) days[i].items.push(sl);
  });
  const today = new Date(); today.setHours(0, 0, 0, 0);
  return '<div style="display:grid;grid-template-columns:repeat(7,1fr);gap:6px;align-items:start">'
    + days.map(x => {
      const isToday = x.d.getTime() === today.getTime();
      const head = '<div style="font-size:11px;font-weight:600;text-align:center;padding:3px 0;border-radius:5px;margin-bottom:4px;'
        + (isToday ? 'background:var(--primary,#2b7be6);color:#fff' : 'color:var(--gray-text)') + '">'
        + _anEsc(x.d.toLocaleDateString([], { weekday: 'short' })) + ' ' + x.d.getDate() + '</div>';
      const body = x.items.length
        ? x.items.map(sl => {
            const col = _anStColor(sl.status);
            return '<div title="' + _anEsc(sl.subject || '') + '" style="border-left:3px solid ' + col
              + ';background:' + col + '18;border-radius:0 4px 4px 0;padding:3px 5px;margin-bottom:3px">'
              + '<div style="font-size:10px;font-variant-numeric:tabular-nums;color:' + col + ';font-weight:600">'
              + _anHm(new Date(sl.start)) + '–' + _anHm(new Date(sl.end)) + '</div>'
              + (sl.subject ? '<div style="font-size:10px;color:var(--text);line-height:1.25;word-break:break-word">' + _anEsc(sl.subject) + '</div>' : '')
              + '</div>';
          }).join('')
        : '<div style="text-align:center;font-size:10px;color:#16a34a;padding:6px 0">libre</div>';
      return '<div style="min-width:0;border:1px solid var(--gray-border);border-radius:6px;padding:4px">' + head + body + '</div>';
    }).join('') + '</div>';
}

// Vue mois : reconstruite depuis availabilityView (1 caractère par intervalle).
function _anCalBodyMois(res, s) {
  const view = res.view || '', iv = res.interval || 60;
  if (!view) return '<div style="color:var(--gray-text)">Trame d\'occupation indisponible.</div>';
  const perDay = Math.round(1440 / iv);
  const nb = new Date(s.getFullYear(), s.getMonth() + 1, 0).getDate();
  let out = '<div style="display:grid;grid-template-columns:repeat(7,1fr);gap:4px">';
  ['Lun','Mar','Mer','Jeu','Ven','Sam','Dim'].forEach(d => out += '<div style="text-align:center;font-size:10px;font-weight:600;color:var(--gray-text);padding-bottom:2px">' + d + '</div>');
  const first = (new Date(s.getFullYear(), s.getMonth(), 1).getDay() + 6) % 7;
  for (let i = 0; i < first; i++) out += '<div></div>';
  for (let d = 0; d < nb; d++) {
    const seg = view.slice(d * perDay, (d + 1) * perDay);
    let busy = 0; for (const ch of seg) if (ch !== '0') busy++;
    const ratio = perDay ? busy / perDay : 0;
    // Une journée pleine n'a jamais 24 h occupées : on cale l'échelle sur ~8 h.
    const alpha = ratio === 0 ? 0 : Math.min(1, (busy / (perDay / 3)) * 0.85 + 0.15);
    const bg = alpha ? 'rgba(220,38,38,' + alpha.toFixed(2) + ')' : 'transparent';
    const heures = Math.round(busy * iv / 60);
    out += '<div title="' + (heures ? heures + ' h occupée(s)' : 'libre') + '" style="text-align:center;font-size:12px;padding:10px 0;border-radius:3px;border:1px solid var(--gray-border);background:' + bg + ';color:' + (alpha > 0.5 ? '#fff' : 'inherit') + '">' + (d + 1) + '</div>';
  }
  return out + '</div><div style="font-size:10px;color:var(--gray-text);margin-top:4px">Intensité = heures occupées. Survolez un jour.</div>';
}

async function _anLoadAgenda(userId, u) {
  _anCal.userId = userId;
  if (u) _anCal.who = ((u.Prenom || '') + ' ' + (u.Nom || '')).trim();
  const cell = () => document.getElementById('anCal');
  const c0 = cell(); if (!c0) return;
  if (!(typeof AnnuaireApi !== 'undefined' && AnnuaireApi) || !AnnuaireApi.getAgenda) { c0.textContent = ''; return; }

  const [s, e] = _anCalWindow(_anCal.view, _anCal.anchor);
  c0.innerHTML = _anCalHeader(s, e) + '<div style="color:var(--gray-text)">Chargement…</div>';

  let res;
  try { res = await _anCalFetch(userId, s, e); }
  catch (_e) { const c = cell(); if (c) c.innerHTML = _anCalHeader(s, e) + '<div>Agenda indisponible.</div>'; return; }

  const c = cell(); if (!c) return;                 // la fiche a changé entre-temps
  if (!res || res.enabled === false || res.available === false) {
    c.innerHTML = _anCalHeader(s, e)
      + '<div style="cursor:help;border-bottom:1px dotted var(--gray-text)" title="' + _anEsc(_anAgendaWhy(res && res.reason)) + '">Agenda indisponible ⓘ</div>';
    return;
  }
  const slots = res.slots || [];
  let body;
  if (_anCal.view === 'mois') body = _anCalBodyMois(res, s);
  else if (_anCal.view === 'semaine') body = _anCalBodySemaine(slots, s);
  else body = _anCalBodyJour(slots);
  c.innerHTML = _anCalHeader(s, e) + body;
}

// Même logique que pour la présence : dire pourquoi, pas seulement « indisponible ».
function _anAgendaWhy(reason) {
  if (!reason) return "Agenda momentanément indisponible.";
  if (reason === 'disabled')    return "Présence désactivée : ajoutez \"plans_presence\": { \"actif\": true } dans config.json.";
  if (reason === 'no_ms_token') return "Vous n'êtes pas connecté via Microsoft : reconnectez-vous avec Microsoft (ou configurez la permission applicative pour les comptes locaux).";
  if (reason === 'no_app_token') return "Agenda partagé pour comptes locaux : nécessite la permission d'application Microsoft « Calendars.Read » (consentement admin) et un tenant précis dans config.json.";
  if (reason === 'no_ms_id')    return "Cette personne n'a pas de compte Microsoft lié.";
  if (reason === 'graph_403')   return "Microsoft a refusé (403) : votre token n'a pas Calendars.Read.Shared. Reconnectez-vous.";
  if (String(reason).indexOf('schedule_error') === 0) return "Microsoft refuse l'accès à ce calendrier (" + reason + ").";
  return "Microsoft a répondu : " + reason;
}

async function _anOpenConsent() {
  let current = _an.consent;
  if (current === null) {
    try { const r = await AnnuaireApi.getConsent(); current = Number(r?.partage) !== 0; } // partagé par défaut
    catch (_e) { current = true; }
    _an.consent = current;
  }
  const html =
    '<div style="font-size:13px;line-height:1.6">'
    + '<p>Votre <b>statut de présence</b> et vos <b>créneaux occupés</b> sont partagés <b>par défaut</b> avec les personnes qui vous cherchent sur les plans.</p>'
    + '<label style="display:flex;align-items:center;gap:10px;margin-top:8px;cursor:pointer">'
    + '<input type="checkbox" id="anConsentChk" ' + (current ? 'checked' : '') + '> Partager ma présence et mes créneaux</label>'
    + '<p style="font-size:11px;color:var(--gray-text);margin-top:10px">Décochez pour masquer votre présence et votre agenda. Vous pouvez changer d\'avis à tout moment.</p>'
    + '</div>';
  openModal('🔒 Mon partage de présence', html, async () => {
    const val = document.getElementById('anConsentChk')?.checked ? 1 : 0;
    await AnnuaireApi.setConsent(val);
    _an.consent = !!val;
    closeModal();
    toast && toast(val ? 'Présence partagée' : 'Présence masquée', 'success');
  });
}

/*
 * SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
 * SPDX-License-Identifier: LicenseRef-Larka-Proprietary
 *
 * This file is part of Larka, proprietary software by Mickaël Larcin.
 * All rights reserved. Use is subject to the license terms; copying,
 * distribution, modification or reverse-engineering without the author's
 * prior written permission is prohibited. See the LICENSE file for details.
 */

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * Larka Connect — Supervision (Bâtiments → Supervision)
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 *   Mode SIMPLE   : une carte par connexion, la dernière valeur de chaque point.
 *   Mode AVANCÉ   : en plus, la tendance des dernières 24 h sur chaque point, et
 *                   les consommations des 12 derniers mois en graphique.
 *   FILTRES       : bâtiment, type d'énergie, nature du point, état, recherche.
 *   CONSOMMATIONS : pour chaque compteur (point de nature « compteur »), la
 *                   consommation du mois choisi — index de fin moins index de
 *                   début, comme un relevé manuel — et l'écart avec le mois
 *                   précédent.
 *   IMPORT        : la consommation d'un ou plusieurs compteurs écrite dans un
 *                   compteur du module Énergie, en relevé manuel, après
 *                   vérification et ajustement ; ou un relevé automatique
 *                   mensuel posé pour la suite.
 *   EXPORT        : valeurs actuelles, consommations mensuelles ou historique
 *                   des mesures des points affichés, en CSV ou en Excel.
 *
 * Ce fichier complète js/pages/connect.js (chargé avant lui) : il en reprend
 * l'état (CxUI), les cartes (_cxCarte) et la courbe d'un point (_cxHistorique).
 * Comme le reste de l'écran, il ne lit que la base : jamais les équipements.
 * ═══════════════════════════════════════════════════════════════════════════════
 */

// ── État propre à la supervision ────────────────────────────────────────────

const CxSup = {
  lu: false,
  mode: 'simple',          // simple | avance (gardé par navigateur)
  type: '',                // Electricite | Gaz | Eau | Chauffage | Ambiance | Autre
  nature: '',              // compteur | mesure | etat | consigne
  etat: '',                // defaut | alarme
  mois: '',                // AAAA-MM des consommations
  consos: null,            // réponse de connect_consommations
  selection: new Set(),    // compteurs cochés (Id de point) pour l'import
  graphe: null,            // compteur affiché dans le graphique (mode avancé)
  tendances: null,         // réponse de connect_tendances
  tendancesLu: 0,
  charger() {
    if (this.lu) return;
    this.lu = true;
    try {
      const p = JSON.parse(localStorage.getItem('cx_supervision') || '{}') || {};
      if (p.mode === 'avance') this.mode = 'avance';
    } catch (_) { /* stockage indisponible : mode simple */ }
    if (!this.mois) this.mois = _cxMoisCourant();
  },
  garder() { try { localStorage.setItem('cx_supervision', JSON.stringify({ mode: this.mode })); } catch (_) { /* rien */ } },
};

const CX_TYPES = [
  ['Electricite', `Électricité`, `Electricity`, ''],
  ['Gaz', `Gaz`, `Gas`, ''],
  ['Eau', `Eau`, `Water`, ''],
  ['Chauffage', `Chauffage / froid`, `Heating / cooling`, ''],
  ['Ambiance', `Ambiance (température, CO₂…)`, `Indoor climate (temperature, CO₂…)`, ''],
  ['Autre', `Autre`, `Other`, '•'],
];
const CX_NATURES_FILTRE = [
  ['compteur', `Compteurs (index)`, `Meters (index)`],
  ['mesure', `Mesures`, `Measurements`],
  ['etat', `États et alarmes`, `States and alarms`],
  ['consigne', `Consignes`, `Setpoints`],
];
function _cxLibType(t) { const x = CX_TYPES.find(y => y[0] === t); return x ? _cxT(x[1], x[2]) : (t || ''); }

function _cxMoisCourant() {
  const d = new Date();
  return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
}
function _cxMoisDecale(mois, n) {
  const [a, m] = mois.split('-').map(Number);
  const d = new Date(a, m - 1 + n, 1);
  return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
}
function _cxLibMois(mois, court = false) {
  const [a, m] = String(mois || '').split('-').map(Number);
  if (!a || !m) return mois || '';
  const t = new Date(a, m - 1, 1).toLocaleDateString(_cxEn() ? 'en-GB' : 'fr-FR', court ? { month: 'short' } : { month: 'long', year: 'numeric' });
  return court ? t.replace('.', '') : t.charAt(0).toUpperCase() + t.slice(1);
}
/** « 01/11 00:00 » : date courte d'un index (l'année est celle du mois affiché). */
function _cxDateCourte(s) {
  const d = _cxDateObj(s);
  if (!d) return '';
  return d.toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }).replace(',', '');
}
/** Dernier jour du mois (ou aujourd'hui pour le mois en cours), « AAAA-MM-JJ ». */
function _cxFinMois(mois) {
  if (mois === _cxMoisCourant()) {
    const d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  }
  const [a, m] = mois.split('-').map(Number);
  return mois + '-' + String(new Date(a, m, 0).getDate()).padStart(2, '0');
}

// ══ Écran ══════════════════════════════════════════════════════════════════

async function renderConnectSupervision() {
  const c = document.getElementById('mainContent');
  _cxArreterMinuteur();
  CxSup.charger();
  showLoading(c);
  let e;
  try { e = await _cxEtat(true); } catch (err) { c.innerHTML = _cxErreurHtml(err); return; }
  if (App.currentPage !== 'connect') return;
  if (_cxModuleFerme(c, e)) return;

  document.getElementById('topbarActions').innerHTML =
      `<button class="btn btn-ghost btn-sm" onclick="navigate('connect_alarmes')">${lk('bell')} Alarmes</button>`
    + (e.peut_configurer ? `<button class="btn btn-ghost btn-sm" onclick="navigate('connect_config')">${lk('plug')} Connexions</button>` : '')
    + _cxBoutonAide();

  const opt = (liste, courant, tous) => `<option value="">${tous}</option>`
    + liste.map(x => `<option value="${x[0]}"${x[0] === courant ? ' selected' : ''}>${escHtml(_cxT(x[1], x[2]))}</option>`).join('');
  c.innerHTML = `
    <div class="cx-page" data-export-propre>
      <div id="cxBandeau">${_cxBandeau(e)}</div>
      ${_cxIntro('supervision')}
      <div id="cxSynthese" class="cx-synthese"></div>
      <div id="cxExtTuiles" class="cx-ext-tuiles"></div>
      <div class="cx-filtres cx-filtres-sup">
        <div class="cx-mode" role="group" aria-label="${escHtml(_cxT(`Affichage`, `Display`))}">
          <button type="button" id="cxModeSimple" class="cx-mode-btn" onclick="_cxMode('simple')">${_cxT(`Simple`, `Simple`)}</button>
          <button type="button" id="cxModeAvance" class="cx-mode-btn" onclick="_cxMode('avance')">${lk('trending-up')} ${_cxT(`Avancé`, `Advanced`)}</button>
        </div>
        <select id="cxBat" class="form-control" aria-label="Bâtiment" onchange="CxUI.bat=this.value;_cxPeindreSupervision()">
          <option value="">Tous les bâtiments</option>
        </select>
        <select id="cxType" class="form-control" aria-label="Type" onchange="CxSup.type=this.value;_cxPeindreSupervision()">
          ${opt(CX_TYPES, CxSup.type, _cxT(`Tous les types`, `All types`))}
        </select>
        <select id="cxNature" class="form-control" aria-label="Nature" onchange="CxSup.nature=this.value;_cxPeindreSupervision()">
          ${opt(CX_NATURES_FILTRE, CxSup.nature, _cxT(`Toutes les natures`, `All kinds`))}
        </select>
        <select id="cxEtatF" class="form-control" aria-label="État" onchange="CxSup.etat=this.value;_cxPeindreSupervision()">
          ${opt([['defaut', `En défaut`, `Faulty`], ['alarme', `En alarme`, `In alarm`]], CxSup.etat, _cxT(`Tous les états`, `All states`))}
        </select>
        <div class="search-input-wrap">
          <span class="search-icon">${lk('search')}</span>
          <input id="cxRecherche" class="search-input" type="search" placeholder="Rechercher un équipement, un point…" autocomplete="off"
            value="${escHtml(CxUI.recherche)}" oninput="CxUI.recherche=this.value;_cxPeindreSupervision()">
        </div>
        ${_cxBoutonsGrouper()}
        <button type="button" class="btn btn-ghost btn-sm" onclick="_cxExporter()">${lk('upload')} ${_cxT(`Exporter`, `Export`)}</button>
        <span id="cxFiltresActifs"></span>
        <span id="cxMaj" class="cx-maj"></span>
      </div>
      <div id="cxResume" class="cx-resume"></div>
      <section id="cxConsos" class="card cx-consos" aria-label="${escHtml(_cxT(`Consommations du mois`, `Monthly consumption`))}"></section>
      <div id="cxCartes" class="cx-grille"></div>
    </div>`;
  _cxMajBoutonsMode();
  _cxTuilesModules();
  await _cxChargerSupervision(false);
  if (App.currentPage === 'connect') _cxGuideAuto();
  // Relecture de la BASE toutes les 30 s (la collecte, elle, est faite par le serveur).
  CxUI.minuteur = setInterval(_cxTicSupervision, 30000);
}

/**
 * Tuiles des modules (ancrage « connect.supervision ») : « 3 dépassements de
 * CO₂ à analyser », « 12 interventions de sondes ce mois-ci »… C'est Larka qui
 * les dessine, d'après la déclaration du module. Sans attente : une tuile
 * lente ne retarde pas les cartes. Relues avec elles, toutes les 30 s.
 */
function _cxTuilesModules() {
  if (typeof LarkaExtensions === 'undefined') return;
  try {
    LarkaExtensions.rendrePoint('connect.supervision', document.getElementById('cxExtTuiles'), {});
  } catch (err) { console.warn('Extensions (supervision) :', err); }
}

function _cxTicSupervision() {
  if (App.currentPage !== 'connect') { _cxArreterMinuteur(); return; }
  if (document.hidden) return;
  _cxChargerSupervision(true);
  _cxTuilesModules();
}

function _cxMode(mode) {
  CxSup.mode = mode === 'avance' ? 'avance' : 'simple';
  CxSup.garder();
  _cxMajBoutonsMode();
  _cxChargerSupervision(true);
}

function _cxMajBoutonsMode() {
  [['cxModeSimple', 'simple'], ['cxModeAvance', 'avance']].forEach(([id, m]) => {
    const b = document.getElementById(id);
    if (!b) return;
    b.classList.toggle('actif', CxSup.mode === m);
    b.setAttribute('aria-pressed', String(CxSup.mode === m));
  });
  const p = document.querySelector('.cx-page');
  if (p) p.classList.toggle('cx-avance', CxSup.mode === 'avance');
}

async function _cxChargerSupervision(silencieux) {
  const zone = document.getElementById('cxCartes');
  if (!zone) return;
  CxSup.charger();
  if (silencieux) zone.classList.add('cx-recharge');
  else showLoading(zone);
  const avance = CxSup.mode === 'avance';
  let sup, consos;
  try {
    [sup, consos] = await Promise.all([
      apiRequest('connect_supervision'),
      apiRequest(`connect_consommations&mois=${encodeURIComponent(CxSup.mois)}&historique=${avance ? 12 : 0}`).catch(() => null),
    ]);
  } catch (err) {
    zone.classList.remove('cx-recharge');
    if (!silencieux) zone.innerHTML = _cxErreurHtml(err);
    return;
  }
  CxUI.sup = sup;
  CxSup.consos = consos;
  // Tendances : relues toutes les 5 minutes au plus (une requête pour tous les points).
  if (avance && (!CxSup.tendances || Date.now() - CxSup.tendancesLu > 300000)) {
    const ids = (sup.passerelles || []).flatMap(p => (p.Points || []).map(x => x.Id));
    if (ids.length) {
      try { CxSup.tendances = await apiRequest(`connect_tendances&heures=24&points=${ids.slice(0, 400).join(',')}`); CxSup.tendancesLu = Date.now(); }
      catch (_) { CxSup.tendances = null; }
    }
  }
  zone.classList.remove('cx-recharge');
  const sel = document.getElementById('cxBat');
  if (sel) {
    const pas = CxUI.sup.passerelles || [];
    const bats = [...new Set(pas.map(p => p.BatimentNom).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'fr'));
    if (CxUI.bat && CxUI.bat !== '__sans' && !bats.includes(CxUI.bat)) CxUI.bat = '';
    sel.innerHTML = '<option value="">Tous les bâtiments</option>'
      + bats.map(b => `<option value="${escHtml(b)}"${b === CxUI.bat ? ' selected' : ''}>${escHtml(b)}</option>`).join('')
      + (pas.some(p => !p.BatimentNom) ? `<option value="__sans"${CxUI.bat === '__sans' ? ' selected' : ''}>Sans bâtiment</option>` : '');
  }
  const maj = document.getElementById('cxMaj');
  if (maj) maj.textContent = _cxT(`Affiché à `, `Shown at `) + new Date().toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
  _cxPeindreSupervision();
}

// ── Filtres ─────────────────────────────────────────────────────────────────

function _cxTypePoint(p) {
  const t = CxUI.sup && CxUI.sup.types ? CxUI.sup.types[p.Id] : null;
  return t || 'Autre';
}

/** Filtres qui portent sur les POINTS (type, nature, état) : une carte sans point retenu disparaît. */
function _cxFiltresPoints() { return !!(CxSup.type || CxSup.nature || CxSup.etat); }

function _cxPointPasse(p, pa) {
  if (CxSup.type && _cxTypePoint(p) !== CxSup.type) return false;
  if (CxSup.nature) {
    const n = p.Nature || 'mesure';
    const groupe = n === 'alarme' ? 'etat' : n;
    if (groupe !== CxSup.nature) return false;
  }
  if (CxSup.etat === 'alarme' && !+p.NbAlarmes) return false;
  if (CxSup.etat === 'defaut') {
    const connexionKo = +pa.Actif && ['erreur', 'partiel'].includes(pa.Etat);
    const pointKo = p.DerniereQualite === 'mauvaise' || !!p.DerniereErreur || (+p.Actif && _cxPerime(p, pa));
    if (!connexionKo && !pointKo) return false;
  }
  return true;
}

function _cxPasserelleDansBat(p) {
  return !CxUI.bat || (CxUI.bat === '__sans' ? !p.BatimentNom : p.BatimentNom === CxUI.bat);
}

function _cxEffacerFiltres() {
  CxSup.type = ''; CxSup.nature = ''; CxSup.etat = ''; CxUI.recherche = ''; CxUI.bat = '';
  ['cxType', 'cxNature', 'cxEtatF', 'cxRecherche', 'cxBat'].forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
  _cxPeindreSupervision();
}

// ── Rendu ───────────────────────────────────────────────────────────────────

function _cxPeindreSupervision() {
  const zone = document.getElementById('cxCartes');
  if (!zone || !CxUI.sup) return;
  _cxSyntheseBatiments();
  const toutes = CxUI.sup.passerelles || [];
  const pas = toutes.filter(_cxPasserelleDansBat);
  const q = (CxUI.recherche || '').trim();
  const filtres = _cxFiltresPoints();

  // Points retenus par carte (filtres et recherche).
  const retenus = new Map();
  pas.forEach(pa => {
    let points = (pa.Points || []).filter(p => _cxPointPasse(p, pa));
    if (q) {
      const surCarte = smartTextMatch(pa.Nom || '', q) || smartTextMatch(pa.BatimentNom || '', q);
      if (!surCarte) points = points.filter(p => smartTextMatch(p.Nom || '', q) || smartTextMatch(p.Code || '', q));
      if (!surCarte && !points.length) return;
    }
    if (filtres && !points.length) return;
    retenus.set(pa.Id, points);
  });
  const visibles = pas.filter(pa => retenus.has(pa.Id));

  const fa = document.getElementById('cxFiltresActifs');
  if (fa) fa.innerHTML = (filtres || q || CxUI.bat)
    ? `<a href="#" class="cx-aide" onclick="event.preventDefault();_cxEffacerFiltres()">✕ ${_cxT(`Effacer les filtres`, `Clear filters`)}</a>` : '';

  // Résumé : ce qu'il faut savoir d'un coup d'œil (sur ce qui est affiché).
  const actives = visibles.filter(p => +p.Actif);
  const enDefaut = actives.filter(p => p.Etat === 'erreur').length;
  const partielles = actives.filter(p => p.Etat === 'partiel').length;
  const nbPoints = [...retenus.values()].reduce((n, l) => n + l.length, 0);
  const nbAlarmes = (CxUI.sup.alarmes || []).filter(a => visibles.some(p => p.Id == a.PasserelleId)).length;
  const resume = document.getElementById('cxResume');
  if (resume) {
    const tuile = (val, lib, etat, lien) => `<div class="cx-tuile${etat ? ' cx-tuile-' + etat : ''}"${lien ? ` role="button" tabindex="0" onclick="${lien}"` : ''}>
        <div class="cx-tuile-val">${val}</div><div class="cx-tuile-lib">${lib}</div></div>`;
    const enService = actives.filter(p => p.Etat === 'ok').length;
    const enAttente = actives.length - enDefaut - partielles - enService;
    const en = _cxEn();
    resume.innerHTML = toutes.length ? [
      tuile(enService, en ? (enService === 1 ? `connection in service` : `connections in service`) : `connexion(s) en service`, ''),
      enDefaut ? tuile('✕ ' + enDefaut, en ? `faulty` : `en défaut`, 'ko', "CxSup.etat='defaut';document.getElementById('cxEtatF').value='defaut';_cxPeindreSupervision()") : '',
      partielles ? tuile('! ' + partielles, en ? `partial` : `partielle(s)`, 'moyen') : '',
      enAttente ? tuile((lk('hourglass') + ' ') + enAttente, en ? `waiting for data` : `en attente de données`, '') : '',
      tuile(nbPoints, en ? (nbPoints === 1 ? `point shown` : `points shown`) : `point(s) affiché(s)`, ''),
      tuile(nbAlarmes ? (lk('bell') + ' ') + nbAlarmes : '0', en ? (nbAlarmes === 1 ? `active alarm` : `active alarms`) : `alarme(s) active(s)`, nbAlarmes ? 'ko' : '', "navigate('connect_alarmes')"),
    ].join('') : '';
  }

  _cxPeindreConsos();

  if (!toutes.length) {
    zone.innerHTML = `<div class="card cx-ferme" style="grid-column:1/-1"><div class="cx-ferme-icone">${lk('plug')}</div>
      <h3>Aucun équipement raccordé pour l'instant</h3>
      <p class="cx-aide">${_cxT(`Une connexion décrit un équipement (automate de GTB, compteur, broker MQTT, API d'un superviseur, dossier d'exports, borne de recharge) ; ses points sont les valeurs à suivre.`,
                                `A connection describes a device (BMS controller, meter, MQTT broker, supervisor API, export folder, EV charger); its points are the values to monitor.`)}</p>
      ${canEdit() ? `<button class="btn btn-primary" onclick="navigate('connect_config')">＋ Raccorder un équipement</button>` : ''}</div>`;
    return;
  }
  const cartes = _cxCartesParBatiment(visibles, p => p.BatimentNom, pa => _cxCarte(pa, retenus.get(pa.Id) || []), _cxResumeConnexions);
  zone.innerHTML = cartes || `<div class="card no-results" style="grid-column:1/-1">${_cxT(`Aucun résultat.`, `No results.`)}
      <a href="#" onclick="event.preventDefault();_cxEffacerFiltres()">${_cxT(`Effacer les filtres`, `Clear filters`)}</a></div>`;
}

// ══ Mini-courbes (mode avancé) ═════════════════════════════════════════════

/**
 * Tendance des dernières 24 h d'un point, dans sa ligne : moyenne horaire ; pour
 * un compteur, la consommation de chaque heure (en barres). Rien en mode simple.
 */
function _cxMiniCourbe(p) {
  if (CxSup.mode !== 'avance' || !CxSup.tendances || !CxSup.tendances.points) return '';
  const serie = CxSup.tendances.points[p.Id] || [];
  const L = 76, H = 22;
  if (serie.length < 2) return `<span class="cx-mini cx-mini-vide" aria-hidden="true"></span>`;
  const compteur = p.Nature === 'compteur';
  let vals;
  if (compteur) {
    vals = serie.slice(1).map((x, i) => Math.max(0, x[2] - serie[i][2]));
  } else {
    vals = serie.map(x => x[1]);
  }
  if (!vals.length) return '';
  const min = compteur ? 0 : Math.min(...vals), max = Math.max(...vals);
  const etendue = (max - min) || 1;
  const dec = p.Decimales ?? 2;
  const u = p.Unite ? ' ' + p.Unite : '';
  const titre = compteur
    ? _cxT(`Consommation par heure, dernières 24 h : `, `Consumption per hour, last 24 h: `) + _cxNombre(vals.reduce((s, v) => s + v, 0), dec) + u + _cxT(` au total`, ` in total`)
    : _cxT(`Dernières 24 h : de `, `Last 24 h: from `) + _cxNombre(min, dec) + _cxT(` à `, ` to `) + _cxNombre(max, dec) + u;
  let corps;
  if (compteur) {
    const pas = L / vals.length;
    corps = vals.map((v, i) => {
      const h = Math.max(1, (v - min) / etendue * (H - 2));
      return `<rect x="${(i * pas + 0.5).toFixed(1)}" y="${(H - h).toFixed(1)}" width="${Math.max(1, pas - 1).toFixed(1)}" height="${h.toFixed(1)}" rx="0.8"/>`;
    }).join('');
  } else {
    const pts = vals.map((v, i) => `${(i / (vals.length - 1) * (L - 2) + 1).toFixed(1)},${(H - 2 - (v - min) / etendue * (H - 4)).toFixed(1)}`);
    corps = `<polyline points="${pts.join(' ')}"/>`;
  }
  return `<svg class="cx-mini${compteur ? ' cx-mini-barres' : ''}" viewBox="0 0 ${L} ${H}" width="${L}" height="${H}" role="img" aria-label="${escHtml(titre)}"><title>${escHtml(titre)}</title>${corps}</svg>`;
}

// ══ Consommations du mois ══════════════════════════════════════════════════

/** Lignes de consommation retenues par les filtres (bâtiment, type, recherche). */
function _cxLignesConsos() {
  const d = CxSup.consos;
  if (!d || !d.lignes) return [];
  const q = (CxUI.recherche || '').trim();
  return d.lignes.filter(l => {
    const p = l.point;
    if (CxUI.bat && (CxUI.bat === '__sans' ? p.BatimentNom : p.BatimentNom !== CxUI.bat)) return false;
    if (CxSup.type && l.type !== CxSup.type) return false;
    // État (en défaut, en alarme) : celui du point, comme pour les cartes.
    if (CxSup.etat) {
      const pa = ((CxUI.sup && CxUI.sup.passerelles) || []).find(x => x.Id == p.PasserelleId);
      const pt = pa && (pa.Points || []).find(x => x.Id == p.Id);
      if (!pa || !pt || !_cxPointPasse(pt, pa)) return false;
    }
    if (q && !(smartTextMatch(p.Nom || '', q) || smartTextMatch(p.Code || '', q) || smartTextMatch(p.PasserelleNom || '', q) || smartTextMatch(p.BatimentNom || '', q))) return false;
    return true;
  });
}

function _cxChangerMois(mois) {
  if (!/^\d{4}-\d{2}$/.test(mois || '')) return;
  if (mois > _cxMoisCourant()) mois = _cxMoisCourant();
  CxSup.mois = mois;
  CxSup.selection.clear();
  const z = document.getElementById('cxConsos');
  if (z) z.classList.add('cx-recharge');
  apiRequest(`connect_consommations&mois=${encodeURIComponent(mois)}&historique=${CxSup.mode === 'avance' ? 12 : 0}`)
    .then(r => { CxSup.consos = r; _cxPeindreConsos(); })
    .catch(err => toast(err.message, 'error'))
    .finally(() => { if (z) z.classList.remove('cx-recharge'); });
}

function _cxEvolution(l) {
  const c = l.conso.conso, p = l.precedent.conso;
  if (l.conso.en_cours) return `<span class="cx-aide cx-nowrap">${_cxT(`en cours`, `in progress`)}</span>`;
  if (c === null || p === null || !(p > 0) || l.conso.partiel || l.precedent.partiel) return '<span class="cx-aide">—</span>';
  let pct = (c - p) / p * 100;
  if (Math.abs(pct) < 0.5) pct = 0;
  const fort = Math.abs(pct) >= 20;
  const signe = pct > 0 ? '+' : '';
  return `<span class="cx-evol${fort ? (pct > 0 ? ' cx-evol-hausse' : ' cx-evol-baisse') : ''}" title="${escHtml(_cxT(`Mois précédent : `, `Previous month: `) + _cxNombre(p, l.point.Decimales ?? 2) + ' ' + (l.point.Unite || ''))}">${pct > 0 ? '↗' : (pct < 0 ? '↘' : '→')} ${signe}${_cxNombre(pct, 0)} %</span>`;
}

function _cxEtatEnergie(l) {
  const a = l.automatique;
  if (a) return `<span class="badge badge-green" title="${escHtml(a.description + ' → ' + (a.cible_nom || ''))}">${lk('repeat')} ${_cxT(`Automatique`, `Automatic`)}</span>
    <div class="cx-aide cx-ellipse cx-ellipse-court" title="${escHtml(a.cible_nom || '')}">${escHtml(a.cible_nom || '')}</div>`;
  const i = l.importe;
  if (i) return `<span class="badge badge-blue" title="${escHtml(_cxT(`Relevé du `, `Reading of `) + _cxDate(i.date, false) + (i.par ? ' — ' + i.par : ''))}">✓ ${_cxT(`Importé`, `Imported`)}</span>
    <div class="cx-aide">${_cxDate(i.date, false)}</div>`;
  return `<span class="cx-aide">${_cxT(`Non importé`, `Not imported`)}</span>`;
}

function _cxPeindreConsos() {
  const z = document.getElementById('cxConsos');
  if (!z) return;
  const d = CxSup.consos;
  const masquer = !d || (CxSup.nature && CxSup.nature !== 'compteur') || !(d.lignes || []).length;
  z.hidden = masquer;
  if (masquer) return;
  const lignes = _cxLignesConsos();
  // Aucun compteur retenu par les filtres (type « Ambiance », bâtiment sans compteur…) : rien à montrer.
  if (!lignes.length && !(CxUI.recherche || '').trim()) { z.hidden = true; return; }
  const peut = !!d.peut_importer;
  const avance = CxSup.mode === 'avance';
  // Sélection : seulement des lignes affichées.
  const ids = new Set(lignes.map(l => l.point.Id));
  [...CxSup.selection].forEach(id => { if (!ids.has(id)) CxSup.selection.delete(id); });
  const nbSel = CxSup.selection.size;
  const mois = CxSup.mois;
  const courant = mois === _cxMoisCourant();

  let h = `<div class="cx-consos-tete">
      <h3 class="cx-consos-titre">${lk('chart-column')} ${_cxT(`Consommations`, `Consumption`)}</h3>
      <div class="cx-mois" role="group" aria-label="${escHtml(_cxT(`Mois`, `Month`))}">
        <button type="button" class="icon-btn" aria-label="${escHtml(_cxT(`Mois précédent`, `Previous month`))}" onclick="_cxChangerMois(_cxMoisDecale(CxSup.mois,-1))">◀</button>
        <input type="month" class="form-control cx-mois-choix" value="${mois}" max="${_cxMoisCourant()}" aria-label="${escHtml(_cxT(`Mois affiché`, `Month shown`))}" onchange="_cxChangerMois(this.value)">
        <button type="button" class="icon-btn" aria-label="${escHtml(_cxT(`Mois suivant`, `Next month`))}" ${courant ? 'disabled' : ''} onclick="_cxChangerMois(_cxMoisDecale(CxSup.mois,1))">▶</button>
        ${courant ? `<span class="badge badge-gray">${_cxT(`en cours`, `in progress`)}</span>` : ''}
      </div>
      <span class="cx-consos-actions">
        ${peut ? `<button type="button" class="btn btn-primary btn-sm" ${nbSel ? '' : 'disabled'} onclick="_cxImporter([...CxSup.selection])">${lk('arrow-down')} ${_cxT(`Importer dans Énergie`, `Import into Energy`)}${nbSel ? ` (${nbSel})` : ''}</button>` : ''}
        <button type="button" class="btn btn-ghost btn-sm" onclick="_cxExporter('consos')">${lk('upload')} ${_cxT(`Exporter`, `Export`)}</button>
      </span>
    </div>
    <p class="cx-aide cx-consos-aide">${_cxT(`Conso du mois = index de fin de mois − index de fin du mois précédent, d'après l'historique des compteurs (points de nature « compteur »).`,
      `Monthly consumption = end-of-month index − previous month's end index, from the meters' history (points of kind “meter”).`)}</p>`;

  if (avance) {
    const avecSerie = lignes.filter(l => (l.serie || []).length);
    if (avecSerie.length) {
      if (!avecSerie.some(l => l.point.Id === CxSup.graphe)) CxSup.graphe = avecSerie[0].point.Id;
      h += `<div class="cx-consos-graphe">
        <div class="cx-consos-graphe-tete">
          <select class="form-control" aria-label="${escHtml(_cxT(`Compteur affiché`, `Meter shown`))}" onchange="CxSup.graphe=+this.value;_cxDessinerConsos()">
            ${avecSerie.map(l => `<option value="${l.point.Id}"${l.point.Id === CxSup.graphe ? ' selected' : ''}>${escHtml(l.point.Nom + ' — ' + (l.point.BatimentNom || l.point.PasserelleNom || ''))}</option>`).join('')}
          </select>
          <span class="cx-aide">${_cxT(`12 derniers mois, jusqu'à `, `Last 12 months, up to `)}${escHtml(_cxLibMois(mois))}</span>
        </div>
        <div id="cxConsosBarres" class="cx-courbe cx-barres"></div>
      </div>`;
    }
  }

  if (!lignes.length) {
    h += `<div class="cx-vide">${_cxT(`Aucun compteur ne correspond aux filtres.`, `No meter matches the filters.`)}</div>`;
    z.innerHTML = h;
    return;
  }
  const tous = lignes.every(l => CxSup.selection.has(l.point.Id));
  h += `<div class="table-wrap cx-consos-table"><table>
    <thead><tr>
      ${peut ? `<th class="cx-col-case"><input type="checkbox" aria-label="${escHtml(_cxT(`Tout sélectionner`, `Select all`))}" ${tous ? 'checked' : ''} onchange="_cxSelectionTout(this.checked)"></th>` : ''}
      <th>${_cxT(`Compteur`, `Meter`)}</th>
      <th class="cx-num">${_cxT(`Index début`, `Start index`)}</th>
      <th class="cx-num">${_cxT(`Index fin`, `End index`)}</th>
      <th class="cx-num">${_cxT(`Conso du mois`, `Month's consumption`)}</th>
      <th title="${escHtml(_cxT(`Écart avec le mois précédent`, `Change from the previous month`))}">${_cxT(`Évolution`, `Change`)}</th>
      ${avance ? `<th>${_cxT(`12 mois`, `12 months`)}</th>` : ''}
      <th>${_cxT(`Module Énergie`, `Energy module`)}</th>
      ${peut ? '<th></th>' : ''}
    </tr></thead><tbody>
    ${lignes.map(l => {
      const p = l.point, c = l.conso, dec = p.Decimales ?? 2, u = p.Unite ? ` <small>${escHtml(p.Unite)}</small>` : '';
      const idx = (v, dt) => v === null ? '—' : `${_cxNombre(v, dec)}<div class="cx-aide" title="${escHtml(_cxDate(dt, true))}">${_cxDateCourte(dt)}</div>`;
      const conso = c.anomalie ? `<span class="cx-val-defaut" title="${escHtml(c.anomalie)}">${lk('triangle-exclamation-point', 'warn')} ${_cxT(`index en baisse`, `index decreasing`)}</span>`
        : (c.conso === null ? '<span class="cx-aide">—</span>' : `<strong>${_cxNombre(c.conso, dec)}</strong>${u}${c.partiel ? ` <span class="badge badge-orange" title="${escHtml(_cxT(`L'historique commence en cours de mois`, `History starts during the month`))}">${_cxT(`partielle`, `partial`)}</span>` : ''}`);
      return `<tr>
        ${peut ? `<td class="cx-col-case"><input type="checkbox" aria-label="${escHtml(_cxT(`Sélectionner `, `Select `) + p.Nom)}" ${CxSup.selection.has(p.Id) ? 'checked' : ''} onchange="_cxSelection(${p.Id}, this.checked)"></td>` : ''}
        <td><a href="#" class="cx-lien-point" onclick="event.preventDefault();_cxHistorique(${p.Id}, '30j')">${escHtml(p.Nom)}</a>
          <div class="cx-aide cx-ellipse" title="${escHtml([_cxLibType(l.type), p.BatimentNom, p.PasserelleNom].filter(Boolean).join(' · '))}">${escHtml([_cxLibType(l.type), p.BatimentNom].filter(Boolean).join(' · '))}</div></td>
        <td class="cx-num">${idx(c.debut, c.debut_date)}</td>
        <td class="cx-num">${idx(c.fin, c.fin_date)}</td>
        <td class="cx-num">${conso}</td>
        <td>${_cxEvolution(l)}</td>
        ${avance ? `<td>${_cxMiniBarres(l)}</td>` : ''}
        <td>${_cxEtatEnergie(l)}</td>
        ${peut ? `<td class="cx-col-action"><button type="button" class="btn btn-ghost btn-sm" ${c.fin === null ? 'disabled' : ''} title="${escHtml(_cxT(`Importer ce compteur dans le module Énergie`, `Import this meter into the Energy module`))}" aria-label="${escHtml(_cxT(`Importer ${p.Nom} dans Énergie`, `Import ${p.Nom} into Energy`))}" onclick="_cxImporter([${p.Id}])">${lk('arrow-down')}${CxSup.mode === 'avance' ? '' : ' ' + _cxT(`Importer`, `Import`)}</button></td>` : ''}
      </tr>`;
    }).join('')}
    </tbody></table></div>`;
  z.innerHTML = h;
  if (avance) _cxDessinerConsos();
}

function _cxSelection(id, oui) {
  if (oui) CxSup.selection.add(id); else CxSup.selection.delete(id);
  _cxPeindreConsos();
}
function _cxSelectionTout(oui) {
  _cxLignesConsos().forEach(l => { if (oui) CxSup.selection.add(l.point.Id); else CxSup.selection.delete(l.point.Id); });
  _cxPeindreConsos();
}

/** 12 mois en petites barres, dans une ligne du tableau. */
function _cxMiniBarres(l) {
  const s = (l.serie || []);
  if (!s.length) return '';
  const max = Math.max(...s.map(x => x.conso || 0)) || 1;
  const L = 72, H = 22, pas = L / s.length;
  const u = l.point.Unite || '';
  const titre = s.map(x => `${_cxLibMois(x.mois, true)} : ${x.conso === null ? '—' : _cxNombre(x.conso, l.point.Decimales ?? 2) + ' ' + u}`).join(' · ');
  return `<svg class="cx-mini cx-mini-barres" viewBox="0 0 ${L} ${H}" width="${L}" height="${H}" role="img" aria-label="${escHtml(titre)}"><title>${escHtml(titre)}</title>${
    s.map((x, i) => {
      const h = x.conso ? Math.max(1, x.conso / max * (H - 1)) : 0;
      return h ? `<rect x="${(i * pas + 0.5).toFixed(1)}" y="${(H - h).toFixed(1)}" width="${Math.max(1, pas - 1.5).toFixed(1)}" height="${h.toFixed(1)}" rx="1"${x.partiel || i === s.length - 1 && l.conso.en_cours ? ' class="cx-mini-partiel"' : ''}/>` : '';
    }).join('')}</svg>`;
}

/**
 * Graphique des 12 derniers mois d'un compteur : une série, des barres fines
 * arrondies en haut, la valeur au survol (et au clavier). Mois partiel ou en
 * cours : barre claire, dite dans la bulle.
 */
function _cxDessinerConsos() {
  const zone = document.getElementById('cxConsosBarres');
  const l = ((CxSup.consos && CxSup.consos.lignes) || []).find(x => x.point.Id === CxSup.graphe);
  if (!zone || !l) return;
  const s = l.serie || [];
  if (!s.some(x => x.conso !== null)) { zone.innerHTML = `<div class="cx-courbe-vide">${_cxT(`Pas d'historique sur ces mois.`, `No history over these months.`)}</div>`; return; }
  const dec = l.point.Decimales ?? 2, u = l.point.Unite || '';
  // Largeur réelle de la zone : textes et barres gardent leur taille, quelle que soit la largeur de l'écran.
  const L = Math.max(420, Math.round(zone.clientWidth || 720)), H = 230, mg = { g: 56, d: 12, h: 18, b: 26 };
  const w = L - mg.g - mg.d, h = H - mg.h - mg.b;
  const max = Math.max(...s.map(x => x.conso || 0)) || 1;
  const ticks = _cxGraduations(0, max, 4);
  const haut = ticks[ticks.length - 1] || max;
  const pas = w / s.length, larg = Math.min(40, pas * 0.56);
  const Y = v => mg.h + (1 - v / haut) * h;
  let svg = ticks.map(t => `<line class="cx-grille-ligne" x1="${mg.g}" x2="${L - mg.d}" y1="${Y(t).toFixed(1)}" y2="${Y(t).toFixed(1)}"/>
      <text x="${mg.g - 8}" y="${(Y(t) + 4).toFixed(1)}" text-anchor="end">${_cxNombreAxe(t)}</text>`).join('');
  s.forEach((x, i) => {
    const cx = mg.g + pas * (i + 0.5);
    const partiel = x.partiel || (i === s.length - 1 && l.conso.en_cours);
    if (x.conso !== null && x.conso > 0) {
      const y = Y(x.conso), hh = mg.h + h - y, r = Math.min(4, hh / 2, larg / 2);
      // Haut arrondi, bas droit (ancré à la ligne de base).
      svg += `<path class="cx-barre${partiel ? ' cx-barre-partielle' : ''}" d="M${(cx - larg / 2).toFixed(1)},${(mg.h + h).toFixed(1)} V${(y + r).toFixed(1)}
        Q${(cx - larg / 2).toFixed(1)},${y.toFixed(1)} ${(cx - larg / 2 + r).toFixed(1)},${y.toFixed(1)} H${(cx + larg / 2 - r).toFixed(1)}
        Q${(cx + larg / 2).toFixed(1)},${y.toFixed(1)} ${(cx + larg / 2).toFixed(1)},${(y + r).toFixed(1)} V${(mg.h + h).toFixed(1)} Z"/>`;
    }
    svg += `<text x="${cx.toFixed(1)}" y="${H - 8}" text-anchor="middle"${i === s.length - 1 ? ' class="cx-axe-fort"' : ''}>${escHtml(_cxLibMois(x.mois, true))}</text>`;
    svg += `<rect class="cx-cible" data-i="${i}" x="${(cx - pas / 2).toFixed(1)}" y="${mg.h}" width="${pas.toFixed(1)}" height="${h}" fill="transparent"/>`;
  });
  svg += `<line class="cx-base" x1="${mg.g}" x2="${L - mg.d}" y1="${mg.h + h}" y2="${mg.h + h}"/>`;
  zone.innerHTML = `<div class="cx-courbe-cadre"><svg viewBox="0 0 ${L} ${H}" tabindex="0" role="img"
      aria-label="${escHtml(_cxT(`Consommation mensuelle de `, `Monthly consumption of `) + l.point.Nom)}">${svg}</svg><div class="cx-infobulle" hidden></div></div>`;
  const cadre = zone.querySelector('.cx-courbe-cadre'), bulle = zone.querySelector('.cx-infobulle'), el = zone.querySelector('svg');
  let courant = s.length - 1;
  const montrer = i => {
    courant = i;
    const x = s[i];
    const partiel = x.partiel || (i === s.length - 1 && l.conso.en_cours);
    zone.querySelectorAll('.cx-barre').forEach(b => b.classList.remove('cx-barre-survol'));
    const barres = [...zone.querySelectorAll('.cx-cible')];
    const r = el.getBoundingClientRect(), k = r.width / L;
    const cx = (mg.g + pas * (i + 0.5)) * k;
    const y = (x.conso ? Y(x.conso) : mg.h + h) * k;
    bulle.innerHTML = `<strong>${x.conso === null ? '—' : _cxNombre(x.conso, dec) + ' ' + escHtml(u)}</strong>${escHtml(_cxLibMois(x.mois))}${partiel ? ' · ' + _cxT(`partiel`, `partial`) : ''}`;
    bulle.style.left = cx + 'px'; bulle.style.top = (y - 6) + 'px';
    bulle.hidden = false;
    if (barres[i]) barres[i].setAttribute('aria-current', 'true');
  };
  el.addEventListener('mousemove', ev => {
    const r = el.getBoundingClientRect();
    const x = (ev.clientX - r.left) / (r.width / L);
    const i = Math.max(0, Math.min(s.length - 1, Math.floor((x - mg.g) / pas)));
    montrer(i);
  });
  el.addEventListener('mouseleave', () => { bulle.hidden = true; });
  el.addEventListener('focus', () => montrer(courant));
  el.addEventListener('blur', () => { bulle.hidden = true; });
  el.addEventListener('keydown', ev => {
    if (ev.key === 'ArrowLeft') { ev.preventDefault(); montrer(Math.max(0, courant - 1)); }
    if (ev.key === 'ArrowRight') { ev.preventDefault(); montrer(Math.min(s.length - 1, courant + 1)); }
  });
  void cadre;
}

// ══ Import dans le module Énergie ══════════════════════════════════════════

/** Coefficient proposé d'après les unités du point et du compteur Énergie (Wh → kWh : 0,001). */
function _cxCoefUnites(uPoint, uCpt) {
  const n = s => String(s || '').toLowerCase().replace(/\s/g, '').replace('³', '3');
  const a = n(uPoint), b = n(uCpt);
  const facteurs = { wh: 1, kwh: 1e3, mwh: 1e6, gwh: 1e9, l: 1, m3: 1e3 };
  if (facteurs[a] && facteurs[b] && ((a.endsWith('wh') && b.endsWith('wh')) || (!a.endsWith('wh') && !b.endsWith('wh')))) return facteurs[a] / facteurs[b];
  return 1;
}

async function _cxImporter(ids) {
  const d = CxSup.consos;
  if (!d || !ids || !ids.length) return;
  const lignes = ids.map(id => d.lignes.find(l => l.point.Id === id)).filter(l => l && l.conso.fin !== null);
  if (!lignes.length) { toast(_cxT(`Rien à importer : aucun index sur ce mois.`, `Nothing to import: no index this month.`), 'warning'); return; }
  const compteurs = (await _cxRef('compteurs')).filter(c => c.Actif !== 0);
  if (!compteurs.length) {
    openModal(_cxT(`Importer dans Énergie`, `Import into Energy`), `<p>${_cxT(`Aucun compteur dans le module Énergie. Créez d'abord le compteur qui recevra les relevés (Énergie → Compteurs).`,
      `No meter in the Energy module. First create the meter that will receive the readings (Energy → Meters).`)}</p>`, null, '', _cxT(`Fermer`, `Close`));
    return;
  }
  const mois = d.mois;
  // Compteur proposé : celui du relevé automatique ; sinon, du même type, celui
  // dont le nom ou le site cite le bâtiment du point ; sinon le premier du type.
  const norm = t => String(t || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
  const devine = l => {
    const memeType = compteurs.filter(c => c.Type === l.type);
    const bat = norm(l.point.BatimentNom);
    const parBat = bat ? memeType.find(c => norm(c.Nom).includes(bat) || norm(c.Site).includes(bat) || (norm(c.Site) && bat.includes(norm(c.Site)))) : null;
    return (parBat || memeType[0] || {}).Id || '';
  };
  const etat = lignes.map(l => {
    const cible = l.cible || devine(l);
    const cpt = compteurs.find(c => c.Id == cible);
    const coef = l.coefficient ?? (cpt ? _cxCoefUnites(l.point.Unite, cpt.Unite) : 1);
    const dejaCeMois = !!(l.importe && String(l.importe.date || '').startsWith(mois) && !l.automatique);
    // debutManuel : index de début tapé par la personne (on ne le remplace plus) ;
    // repris : index de début pris au dernier relevé du compteur Énergie.
    return { l, cible, coef, debutBrut: l.conso.debut, finBrut: l.conso.fin, ecrire: !dejaCeMois && !l.automatique, auto: false,
             debutManuel: false, repris: false };
  });
  CxSup.import = { etat, mois, compteurs, contexte: {} };

  const ligneHtml = (e, i) => {
    const l = e.l, p = l.point;
    const a = l.automatique;
    const deja = l.importe && String(l.importe.date || '').startsWith(mois);
    return `<div class="cx-imp-ligne" data-i="${i}">
      <div class="cx-imp-tete">
        <label class="cx-case"><input type="checkbox" id="cxiE${i}" ${e.ecrire ? 'checked' : ''} onchange="_cxImpMaj(${i})">
          <strong>${escHtml(p.Nom)}</strong></label>
        <span class="cx-aide">${escHtml([p.BatimentNom, p.PasserelleNom].filter(Boolean).join(' · '))}</span>
      </div>
      ${a ? `<div class="cx-imp-note cx-imp-info">${lk('repeat')} ${_cxT(`Relevé automatique déjà en place : `, `Automatic reading already set up: `)}${escHtml(a.description)} → ${escHtml(a.cible_nom || '')}.
             ${_cxT(`Un import manuel s'ajouterait à ses relevés.`, `A manual import would add to its readings.`)}</div>` : ''}
      ${deja && !a ? `<div class="cx-imp-note cx-imp-alerte">${lk('triangle-exclamation-point', 'warn')} ${_cxT(`Un relevé existe déjà pour ce mois sur le compteur Énergie (le `, `A reading already exists for this month on the Energy meter (on `)}${_cxDate(l.importe.date, false)}${l.importe.index !== null ? ', index ' + _cxNombre(l.importe.index, 3) : ''}).
             ${_cxT(`Cochez la ligne pour en écrire un autre.`, `Tick the line to write another one.`)}</div>` : ''}
      <div class="cx-imp-grille">
        <div class="form-group cx-imp-cpt"><label>${_cxT(`Compteur Énergie`, `Energy meter`)}</label>
          <select class="form-control" id="cxiC${i}" onchange="_cxImpCompteur(${i}, this.value)">
            <option value="">${_cxT(`— Choisir —`, `— Choose —`)}</option>
            ${CX_TYPES.filter(t => compteurs.some(c => c.Type === t[0])).map(t => `<optgroup label="${escHtml(_cxT(t[1], t[2]))}">${
              compteurs.filter(c => c.Type === t[0]).map(c => `<option value="${c.Id}"${c.Id == e.cible ? ' selected' : ''}>${escHtml(c.Nom + (c.Site ? ' (' + c.Site + ')' : '') + (c.Unite ? ' — ' + c.Unite : ''))}</option>`).join('')}</optgroup>`).join('')}
          </select></div>
        <div class="form-group"><label title="${escHtml(_cxT(`Multiplie les index du point : Wh → kWh = 0,001`, `Multiplies the point's indexes: Wh → kWh = 0.001`))}">${_cxT(`Coefficient`, `Factor`)}</label>
          <input class="form-control" id="cxiK${i}" type="number" step="any" value="${e.coef}" oninput="_cxImpCoef(${i})"></div>
        <div class="form-group"><label>${_cxT(`Période du`, `Period from`)}</label>
          <input class="form-control" id="cxiP${i}" type="date" value="${mois}-01" onchange="_cxImpContexte(${i})"></div>
        <div class="form-group"><label>${_cxT(`Date du relevé`, `Reading date`)}</label>
          <input class="form-control" id="cxiT${i}" type="date" value="${_cxFinMois(mois)}" onchange="_cxImpContexte(${i})"></div>
        <div class="form-group"><label>${_cxT(`Index début`, `Start index`)}</label>
          <input class="form-control" id="cxiD${i}" type="number" step="any" oninput="_cxImpDebutSaisi(${i})"></div>
        <div class="form-group"><label>${_cxT(`Index fin`, `End index`)}</label>
          <input class="form-control" id="cxiF${i}" type="number" step="any" oninput="_cxImpConso(${i})"></div>
        <div class="form-group"><label>${_cxT(`Consommation`, `Consumption`)}</label>
          <div class="cx-imp-conso" id="cxiS${i}">—</div></div>
        <div class="form-group"><label>${_cxT(`Commentaire (facultatif)`, `Comment (optional)`)}</label>
          <input class="form-control" id="cxiN${i}" type="text" maxlength="300"></div>
      </div>
      <div class="cx-imp-contexte" id="cxiX${i}"></div>
      ${a ? '' : `<label class="cx-case cx-imp-auto"><input type="checkbox" id="cxiA${i}" onchange="_cxImpMaj(${i})">
          ${_cxT(`Ensuite, importer automatiquement chaque mois (dernier jour du mois, 23 h 55)`, `Then import automatically every month (last day of the month, 11:55 pm)`)}</label>`}
    </div>`;
  };
  const titre = lignes.length === 1
    ? _cxT(`Importer dans Énergie — `, `Import into Energy — `) + lignes[0].point.Nom
    : _cxT(`Importer ${lignes.length} compteurs dans Énergie`, `Import ${lignes.length} meters into Energy`);
  openModal(titre, `<div class="cx-imp">
      <p class="cx-aide">${_cxT(`Consommation de `, `Consumption for `)}<strong>${escHtml(_cxLibMois(mois))}</strong>${_cxT(`, d'après l'historique des compteurs. Vérifiez et ajustez les index avant d'écrire : le relevé est enregistré comme une saisie manuelle (montant au prix unitaire du compteur, CO₂ au facteur de l'année), avec son origine en commentaire.`,
        `, from the meters' history. Check and adjust the indexes before writing: the reading is saved like a manual entry (amount at the meter's unit price, CO₂ at the year's factor), with its origin in the comment.`)}</p>
      ${etat.map(ligneHtml).join('')}
      <label class="cx-case cx-imp-validation"><input type="checkbox" id="cxiValid">
        ${_cxT(`Relevés automatiques : faire valider chaque relevé avant de l'écrire (Connexions → Relevés automatiques)`, `Automatic readings: have each reading approved before it is written (Connections → Automatic readings)`)}</label>
      <div id="cxiErreurs"></div>
    </div>`, _cxImpEnregistrer, _cxT(`Écrire dans Énergie`, `Write to Energy`), _cxT(`Annuler`, `Cancel`), '920px');
  etat.forEach((e, i) => { _cxImpCoef(i); _cxImpContexte(i); _cxImpMaj(i); });
}

/**
 * Index affichés = index du point × coefficient (recalculés quand le coefficient change).
 * L'index de DÉBUT reste celui du dernier relevé du compteur Énergie quand il a été repris
 * (il est déjà dans l'unité du compteur), ou celui qu'a tapé la personne.
 */
function _cxImpCoef(i) {
  const e = CxSup.import && CxSup.import.etat[i];
  if (!e) return;
  const k = parseFloat(document.getElementById('cxiK' + i).value) || 1;
  const arr = v => v === null ? '' : String(+(v * k).toFixed(6));
  if (!e.repris && !e.debutManuel) document.getElementById('cxiD' + i).value = arr(e.debutBrut);
  document.getElementById('cxiF' + i).value = arr(e.finBrut);
  _cxImpConso(i);
}

/** Index de début tapé à la main : il n'est plus remplacé (ni par le coefficient, ni par le compteur). */
function _cxImpDebutSaisi(i) {
  const e = CxSup.import && CxSup.import.etat[i];
  if (e) { e.debutManuel = true; e.repris = false; }
  _cxImpConso(i);
}

function _cxImpConso(i) {
  const e = CxSup.import && CxSup.import.etat[i];
  if (!e) return;
  const d = document.getElementById('cxiD' + i).value, f = document.getElementById('cxiF' + i).value;
  const z = document.getElementById('cxiS' + i);
  const cpt = CxSup.import.compteurs.find(c => c.Id == document.getElementById('cxiC' + i).value);
  const u = cpt && cpt.Unite ? ' ' + escHtml(cpt.Unite) : '';
  if (d === '' || f === '') { z.innerHTML = '—'; return; }
  const c = parseFloat(f) - parseFloat(d);
  z.innerHTML = c < 0 ? `<span class="cx-val-defaut">${lk('triangle-exclamation-point', 'warn')} ${_cxT(`fin < début`, `end < start`)}</span>` : `<strong>${_cxNombre(c, 3)}</strong>${u}`;
  _cxImpVerifier(i);
}

function _cxImpCompteur(i, id) {
  const e = CxSup.import.etat[i];
  e.cible = id;
  // Autre compteur : son propre index précédent sera repris (sauf saisie à la main).
  if (e.repris) { e.repris = false; document.getElementById('cxiP' + i).value = CxSup.import.mois + '-01'; }
  const cpt = CxSup.import.compteurs.find(c => c.Id == id);
  if (cpt && e.l.coefficient === null) {
    document.getElementById('cxiK' + i).value = _cxCoefUnites(e.l.point.Unite, cpt.Unite);
    _cxImpCoef(i);
  }
  _cxImpContexte(i);
}

/*
 * INDEX PRÉCÉDENT DE L'IMPORT.
 * L'index de début proposé était celui de l'historique du POINT au 1er du mois, et le
 * contrôle cherchait le dernier relevé du compteur Énergie jusqu'au début de la période
 * seulement : un relevé saisi en cours de mois, ou un écart entre la supervision et le
 * module Énergie, donnait une consommation qui ne prolongeait pas le relevé d'avant.
 * L'index de début reprend maintenant le dernier relevé du compteur Énergie AVANT la date
 * du relevé (règle du formulaire Énergie), et la période part de ce relevé ; l'historique
 * du point reste proposé si les deux diffèrent.
 */
async function _cxImpContexte(i) {
  const e = CxSup.import && CxSup.import.etat[i];
  const z = document.getElementById('cxiX' + i);
  if (!e || !z) return;
  const id = document.getElementById('cxiC' + i).value;
  if (!id) { z.innerHTML = ''; return; }
  const avant = document.getElementById('cxiP' + i).value || document.getElementById('cxiT' + i).value;
  const date = document.getElementById('cxiT' + i).value;
  try {
    const r = await apiRequest(`connect_import_contexte&compteurs=${encodeURIComponent(id)}&avant=${encodeURIComponent(avant)}&date=${encodeURIComponent(date)}`);
    CxSup.import.contexte[i] = (r && r[id]) || null;
  } catch (_) { CxSup.import.contexte[i] = null; }
  const x = CxSup.import.contexte[i];
  if (x && x.index !== null && x.index !== undefined && !e.debutManuel) {
    document.getElementById('cxiD' + i).value = String(+(+x.index).toFixed(6));
    if (x.date) document.getElementById('cxiP' + i).value = String(x.date).slice(0, 10);
    e.repris = true;
  } else if (e.repris && (!x || x.index === null || x.index === undefined)) {
    // Plus de relevé avant cette date : retour à l'historique du point.
    e.repris = false;
    _cxImpCoef(i);
  }
  _cxImpConso(i);
}

function _cxImpVerifier(i) {
  const z = document.getElementById('cxiX' + i);
  const x = CxSup.import && CxSup.import.contexte[i];
  if (!z) return;
  // Relevés déjà saisis après la date choisie : le nouveau s'intercalerait, et les consommations suivantes ne seraient pas recalculées.
  const suite = x && x.suivant_date
    ? `<div class="cx-imp-ecart">${lk('triangle-exclamation-point', 'warn')} ${_cxT(`Le compteur Énergie a déjà un relevé à cette date ou après (le `, `The Energy meter already has a reading on or after this date (on `)}${_cxDate(x.suivant_date, false)}${_cxT(`, index `, `, index `)}${_cxNombre(x.suivant_index, 3)}${_cxT(`) : celui-ci s'intercalerait, et la consommation du relevé suivant ne serait pas recalculée.`, `): this one would be inserted, and the next reading's consumption would not be recalculated.`)}</div>`
    : '';
  if (!x || x.index === null || x.index === undefined) {
    z.innerHTML = (document.getElementById('cxiC' + i).value ? `<span class="cx-aide">${_cxT(`Aucun relevé avant cette période sur ce compteur Énergie : ce sera le premier.`, `No reading before this period on this Energy meter: this will be the first one.`)}</span>` : '') + suite;
    return;
  }
  const deb = parseFloat(document.getElementById('cxiD' + i).value);
  const fin = parseFloat(document.getElementById('cxiF' + i).value);
  const ecart = isNaN(deb) ? 0 : deb - x.index;
  // Tolérance : 0,5 % de la consommation du mois (un écart d'index n'a de sens que rapporté à elle).
  const tol = Math.max(0.001, isNaN(fin) || isNaN(deb) ? 0 : Math.abs(fin - deb) * 0.005);
  const e = CxSup.import.etat[i];
  if (e && e.repris && Math.abs(ecart) <= tol) {
    // Index de début repris du compteur Énergie ; l'historique du point dit-il autre chose ?
    const k = parseFloat(document.getElementById('cxiK' + i).value) || 1;
    const pt = e.debutBrut === null ? null : +(e.debutBrut * k).toFixed(6);
    const ecartPt = pt === null ? 0 : pt - x.index;
    z.innerHTML = `<span class="cx-aide">${_cxT(`Index précédent : `, `Previous index: `)}<strong>${_cxNombre(x.index, 3)}</strong>${_cxT(`, dernier relevé du compteur Énergie (le `, `, last reading of the Energy meter (on `)}${_cxDate(x.date, false)}).</span>`
      + (pt !== null && Math.abs(ecartPt) > tol
        ? ` <span class="cx-imp-ecart">${lk('triangle-exclamation-point', 'warn')} ${_cxT(`L'historique du point donnait `, `The point's history gave `)}${_cxNombre(pt, 3)}${_cxT(` au début de la période (écart `, ` at the start of the period (difference `)}${_cxNombre(ecartPt, 3)}${_cxT(`) : la consommation écrite couvre cet écart.`, `): the written consumption covers this difference.`)}</span>
           <button type="button" class="btn btn-ghost btn-sm" onclick="_cxImpDepuisPoint(${i})">${_cxT(`Partir de l'index du point`, `Start from the point's index`)}</button>`
        : ` <span class="cx-imp-ok">✓ ${_cxT(`l'historique du point le confirme`, `the point's history confirms it`)}</span>`) + suite;
    return;
  }
  z.innerHTML = `<span class="cx-aide">${_cxT(`Dernier index du compteur Énergie : `, `Last index of the Energy meter: `)}<strong>${_cxNombre(x.index, 3)}</strong>${_cxT(` le `, ` on `)}${_cxDate(x.date, false)}.</span>`
    + (Math.abs(ecart) > tol
      ? ` <span class="cx-imp-ecart">${lk('triangle-exclamation-point', 'warn')} ${_cxT(`L'index de début diffère de `, `The start index differs by `)}${_cxNombre(ecart, 3)}.</span>
         <button type="button" class="btn btn-ghost btn-sm" onclick="_cxImpReprendre(${i})">${_cxT(`Partir du dernier index du compteur`, `Start from the meter's last index`)}</button>`
      : ` <span class="cx-imp-ok">✓ ${_cxT(`l'index de début le prolonge`, `the start index continues it`)}</span>`) + suite;
}

/** Ajustement : l'index de début reprend le dernier index du compteur Énergie (la conso couvre alors l'écart). */
function _cxImpReprendre(i) {
  const x = CxSup.import.contexte[i];
  if (!x) return;
  const e = CxSup.import.etat[i];
  if (e) { e.repris = true; e.debutManuel = false; }
  document.getElementById('cxiD' + i).value = String(+(+x.index).toFixed(6));
  if (x.date) document.getElementById('cxiP' + i).value = String(x.date).slice(0, 10);
  _cxImpConso(i);
}

/** Ajustement inverse : l'index de début reprend l'historique du point au début du mois. */
function _cxImpDepuisPoint(i) {
  const e = CxSup.import && CxSup.import.etat[i];
  if (!e) return;
  e.repris = false; e.debutManuel = true;
  const k = parseFloat(document.getElementById('cxiK' + i).value) || 1;
  document.getElementById('cxiD' + i).value = e.debutBrut === null ? '' : String(+(e.debutBrut * k).toFixed(6));
  document.getElementById('cxiP' + i).value = CxSup.import.mois + '-01';
  _cxImpConso(i);
}

function _cxImpMaj(i) {
  const bloc = document.querySelector(`.cx-imp-ligne[data-i="${i}"]`);
  if (!bloc) return;
  const ecrire = document.getElementById('cxiE' + i).checked;
  const auto = document.getElementById('cxiA' + i);
  bloc.classList.toggle('cx-imp-off', !ecrire && !(auto && auto.checked));
  bloc.querySelectorAll('.cx-imp-grille input:not([id^="cxiK"]), .cx-imp-grille select').forEach(el => {
    if (el.id.startsWith('cxiC')) return;           // le compteur sert aussi au relevé automatique
    el.disabled = !ecrire;
  });
  const v = document.getElementById('cxiValid');
  if (v) v.closest('label').hidden = !CxSup.import.etat.some((e, j) => { const a = document.getElementById('cxiA' + j); return a && a.checked; });
}

async function _cxImpEnregistrer() {
  const imp = CxSup.import;
  if (!imp) return;
  const valid = document.getElementById('cxiValid');
  const lignes = imp.etat.map((e, i) => {
    const auto = document.getElementById('cxiA' + i);
    return {
      pointId: e.l.point.Id,
      compteurId: +document.getElementById('cxiC' + i).value || 0,
      ecrire: document.getElementById('cxiE' + i).checked,
      automatique: !!(auto && auto.checked),
      validation: !!(valid && valid.checked),
      coefficient: parseFloat(document.getElementById('cxiK' + i).value) || 1,
      indexDebut: document.getElementById('cxiD' + i).value,
      indexFin: document.getElementById('cxiF' + i).value,
      periodeDebut: document.getElementById('cxiP' + i).value,
      date: document.getElementById('cxiT' + i).value,
      commentaire: document.getElementById('cxiN' + i).value,
    };
  }).filter(x => x.ecrire || x.automatique);
  const err = document.getElementById('cxiErreurs');
  if (!lignes.length) { toast(_cxT(`Cochez au moins un compteur à écrire.`, `Tick at least one meter to write.`), 'warning'); return; }
  const sansCompteur = lignes.filter(x => !x.compteurId);
  if (sansCompteur.length) {
    err.innerHTML = `<div class="cx-test-verdict ko">${_cxT(`Choisissez le compteur Énergie de chaque ligne cochée.`, `Choose the Energy meter of each ticked line.`)}</div>`;
    return;
  }
  const btn = document.getElementById('modalSaveBtn');
  if (btn) btn.disabled = true;
  let r;
  try { r = await apiRequest('connect_importer_energie', 'POST', { lignes }); }
  catch (e) { err.innerHTML = `<div class="cx-test-verdict ko">${escHtml(e.message)}</div>`; return; }
  finally { if (btn) btn.disabled = false; }
  const ko = (r.resultats || []).filter(x => !x.ok || x.message);
  CxUI.cache.compteurs = null;
  if (ko.length) {
    err.innerHTML = `<div class="cx-test-verdict ${r.ecrits || r.automatiques ? 'moyen' : 'ko'}">${_cxT(`${r.ecrits} relevé(s) écrit(s).`, `${r.ecrits} reading(s) written.`)}<ul>${
      ko.map(x => `<li>${escHtml(x.message || '')}</li>`).join('')}</ul></div>`;
    const okIds = new Set((r.resultats || []).filter(x => x.ok && !x.message).map(x => x.pointId));
    imp.etat.forEach((e, i) => { if (okIds.has(e.l.point.Id)) { const c = document.getElementById('cxiE' + i); if (c) { c.checked = false; _cxImpMaj(i); } } });
  } else {
    closeModal();
    toast(_cxT(`${r.ecrits} relevé(s) écrit(s) dans le module Énergie`, `${r.ecrits} reading(s) written to the Energy module`)
      + (r.automatiques ? _cxT(`, ${r.automatiques} relevé(s) automatique(s) créé(s)`, `, ${r.automatiques} automatic reading(s) created`) : '') + '.', 'success');
  }
  CxSup.selection.clear();
  _cxChangerMois(CxSup.mois);
}

// ══ Export ═════════════════════════════════════════════════════════════════

/** Points affichés (après filtres) : ce qu'exporte « Historique des mesures » et « Valeurs actuelles ». */
function _cxPointsAffiches() {
  const out = [];
  const q = (CxUI.recherche || '').trim();
  ((CxUI.sup && CxUI.sup.passerelles) || []).filter(_cxPasserelleDansBat).forEach(pa => {
    const surCarte = q && (smartTextMatch(pa.Nom || '', q) || smartTextMatch(pa.BatimentNom || '', q));
    (pa.Points || []).forEach(p => {
      if (!_cxPointPasse(p, pa)) return;
      if (q && !surCarte && !(smartTextMatch(p.Nom || '', q) || smartTextMatch(p.Code || '', q))) return;
      out.push({ p, pa });
    });
  });
  return out;
}

function _cxExporter(quoi) {
  const pts = _cxPointsAffiches();
  const nbConsos = _cxLignesConsos().length;
  const choix = quoi || 'valeurs';
  const r = (v, lib, aide) => `<label class="cx-export-choix"><input type="radio" name="cxxQuoi" value="${v}" ${v === choix ? 'checked' : ''} onchange="_cxExportMaj()">
      <span><strong>${lib}</strong><span class="cx-aide">${aide}</span></span></label>`;
  openModal(_cxT(`Exporter les données`, `Export data`), `<div class="cx-export">
      ${r('valeurs', _cxT(`Valeurs actuelles`, `Current values`), _cxT(`${pts.length} point(s) affiché(s) : dernière valeur, unité, date de lecture, qualité.`, `${pts.length} point(s) shown: last value, unit, read time, quality.`))}
      ${r('consos', _cxT(`Consommations mensuelles`, `Monthly consumption`), _cxT(`${nbConsos} compteur(s) : index de début et de fin, conso par mois.`, `${nbConsos} meter(s): start and end index, consumption per month.`))}
      ${r('mesures', _cxT(`Historique des mesures`, `Measurement history`), _cxT(`${pts.length} point(s) affiché(s), sur la période choisie.`, `${pts.length} point(s) shown, over the chosen period.`))}
      ${r('courbes', _cxT(`Courbes (PDF)`, `Charts (PDF)`), _cxT(`Les courbes que vous choisissez, une par page ou deux, avec leurs minimum, maximum, moyenne et consommation.`, `The charts you pick, one or two per page, with their minimum, maximum, average and consumption.`))}
      <div id="cxxConsos" class="cx-export-options">
        <div class="form-group"><label>${_cxT(`Mois`, `Months`)}</label>
          <select id="cxxNbMois" class="form-control"><option value="1">${escHtml(_cxLibMois(CxSup.mois))}</option>
            <option value="12">${_cxT(`12 mois jusqu'à `, `12 months up to `)}${escHtml(_cxLibMois(CxSup.mois))}</option></select></div>
      </div>
      <div id="cxxMesures" class="cx-export-options">
        <div class="form-group"><label>${_cxT(`Du`, `From`)}</label><input id="cxxDebut" type="date" class="form-control" value="${CxSup.mois}-01"></div>
        <div class="form-group"><label>${_cxT(`Au`, `To`)}</label><input id="cxxFin" type="date" class="form-control" value="${_cxFinMois(CxSup.mois)}"></div>
        <div class="form-group"><label>${_cxT(`Pas`, `Step`)}</label>
          <select id="cxxPas" class="form-control">
            <option value="brut">${_cxT(`Chaque mesure (brut)`, `Every measurement (raw)`)}</option>
            <option value="heure">${_cxT(`Par heure`, `Per hour`)}</option>
            <option value="jour" selected>${_cxT(`Par jour`, `Per day`)}</option>
            <option value="mois">${_cxT(`Par mois`, `Per month`)}</option>
          </select></div>
      </div>
      <div id="cxxCourbes" class="cx-export-options cx-export-courbes">
        <div class="cx-export-courbes-tete">
          <input id="cxxFiltre" type="search" class="form-control" placeholder="${escHtml(_cxT(`Filtrer les points…`, `Filter points…`))}" oninput="_cxExportFiltrer(this.value)">
          <button type="button" class="btn btn-ghost btn-sm" onclick="_cxExportCocher(true)">${_cxT(`Tout cocher`, `Tick all`)}</button>
          <button type="button" class="btn btn-ghost btn-sm" onclick="_cxExportCocher(false)">${_cxT(`Aucun`, `None`)}</button>
          <span id="cxxNbCourbes" class="cx-aide"></span>
        </div>
        <div id="cxxListe" class="cx-export-liste">${pts.map(({ p, pa }) => `<label class="cx-case" data-nom="${escHtml(((p.Nom || '') + ' ' + (pa.Nom || '') + ' ' + (pa.BatimentNom || '')).toLowerCase())}">
            <input type="checkbox" value="${p.Id}" onchange="_cxExportCompter()"> <span><strong>${escHtml(p.Nom || '')}</strong>
            <span class="cx-aide">${escHtml([pa.BatimentNom, pa.Nom, p.Unite].filter(Boolean).join(' · '))}</span></span></label>`).join('')
            || `<div class="cx-aide">${_cxT(`Aucun point affiché : changez les filtres de la supervision.`, `No point shown: change the supervision filters.`)}</div>`}</div>
        <div class="cx-export-ligne">
          <div class="form-group"><label>${_cxT(`Période`, `Period`)}</label>
            <select id="cxxPeriode" class="form-control" onchange="_cxExportMaj()">
              <option value="24h">${_cxT(`24 dernières heures`, `Last 24 hours`)}</option>
              <option value="7j" selected>${_cxT(`7 derniers jours`, `Last 7 days`)}</option>
              <option value="30j">${_cxT(`30 derniers jours`, `Last 30 days`)}</option>
              <option value="mois">${escHtml(_cxLibMois(CxSup.mois))}</option>
              <option value="1an">${_cxT(`12 derniers mois`, `Last 12 months`)}</option>
              <option value="perso">${_cxT(`Personnalisée…`, `Custom…`)}</option>
            </select></div>
          <div class="form-group" id="cxxPersoD"><label>${_cxT(`Du`, `From`)}</label><input id="cxxPDebut" type="datetime-local" class="form-control" value="${CxSup.mois}-01T00:00"></div>
          <div class="form-group" id="cxxPersoF"><label>${_cxT(`Au`, `To`)}</label><input id="cxxPFin" type="datetime-local" class="form-control" value="${_cxLocal(new Date())}"></div>
        </div>
        <div class="cx-export-ligne">
          <div class="form-group"><label>${_cxT(`Style`, `Style`)}</label>
            <select id="cxxStyle" class="form-control">
              <option value="">${_cxT(`Celui de chaque point (mémorisé)`, `Each point's own (remembered)`)}</option>
              ${CX_STYLES.filter(x => x[0] !== 'auto').map(x => `<option value="${x[0]}">${escHtml(_cxT(x[1], x[2]))}</option>`).join('')}
            </select></div>
          <div class="form-group"><label>${_cxT(`Mise en page`, `Layout`)}</label>
            <select id="cxxParPage" class="form-control">
              <option value="1">${_cxT(`Une courbe par page`, `One chart per page`)}</option>
              <option value="2">${_cxT(`Deux courbes par page`, `Two charts per page`)}</option>
            </select></div>
          <label class="cx-case cx-export-synthese"><input id="cxxSynthese" type="checkbox" checked> ${_cxT(`Page de synthèse`, `Summary page`)}</label>
        </div>
      </div>
      <div class="cx-export-format">
        <span class="cx-aide">${_cxT(`Format`, `Format`)}</span>
        <label class="cx-case"><input type="radio" name="cxxFormat" value="xlsx" checked> Excel (.xlsx)</label>
        <label class="cx-case"><input type="radio" name="cxxFormat" value="csv"> CSV ${_cxT(`(séparateur « ; »)`, `(“;” separator)`)}</label>
      </div>
      <div id="cxxEtat"></div>
    </div>`, _cxExportLancer, _cxT(`Exporter`, `Export`), _cxT(`Annuler`, `Cancel`), '720px');
  _cxExportMaj();
}

function _cxExportMaj() {
  const q = (document.querySelector('input[name="cxxQuoi"]:checked') || {}).value;
  const c = document.getElementById('cxxConsos'), m = document.getElementById('cxxMesures');
  if (c) c.hidden = q !== 'consos';
  if (m) m.hidden = q !== 'mesures';
  const k = document.getElementById('cxxCourbes');
  if (k) k.hidden = q !== 'courbes';
  // Le PDF a son propre format : le choix Excel / CSV n'y a pas de sens.
  const f = document.querySelector('.cx-export-format');
  if (f) f.hidden = q === 'courbes';
  const perso = (document.getElementById('cxxPeriode') || {}).value === 'perso';
  ['cxxPersoD', 'cxxPersoF'].forEach(id => { const e = document.getElementById(id); if (e) e.hidden = !perso; });
  _cxExportCompter();
}

function _cxExportFiltrer(q) {
  q = (q || '').trim().toLowerCase();
  document.querySelectorAll('#cxxListe [data-nom]').forEach(l => { l.hidden = !!q && !l.dataset.nom.includes(q); });
}

function _cxExportCocher(oui) {
  document.querySelectorAll('#cxxListe [data-nom]').forEach(l => {
    if (l.hidden) return;
    const c = l.querySelector('input'); if (c) c.checked = oui;
  });
  _cxExportCompter();
}

const CX_EXPORT_COURBES_MAX = 60;
function _cxExportCompter() {
  const n = document.querySelectorAll('#cxxListe input:checked').length;
  const z = document.getElementById('cxxNbCourbes');
  if (z) z.textContent = n > CX_EXPORT_COURBES_MAX
    ? _cxT(`${n} cochées — ${CX_EXPORT_COURBES_MAX} au plus`, `${n} ticked — ${CX_EXPORT_COURBES_MAX} at most`)
    : _cxT(`${n} courbe(s)`, `${n} chart(s)`);
}

/** Bornes de la période choisie pour le PDF. */
function _cxExportPlage() {
  const v = (document.getElementById('cxxPeriode') || {}).value || '7j';
  if (v === 'mois') {
    const [a, mo] = CxSup.mois.split('-').map(Number);
    const debut = new Date(a, mo - 1, 1), fin = new Date(Math.min(new Date(a, mo, 1).getTime(), Date.now()));
    return { debut, fin };
  }
  if (v === 'perso') {
    const debut = new Date((document.getElementById('cxxPDebut') || {}).value), fin = new Date((document.getElementById('cxxPFin') || {}).value);
    if (isNaN(debut) || isNaN(fin) || fin <= debut) return null;
    return { debut, fin };
  }
  const pl = _cxPlage(v, null);
  return { debut: pl.debut, fin: pl.fin };
}

async function _cxExportLancer() {
  const quoi = (document.querySelector('input[name="cxxQuoi"]:checked') || {}).value || 'valeurs';
  const format = (document.querySelector('input[name="cxxFormat"]:checked') || {}).value || 'xlsx';
  const etat = document.getElementById('cxxEtat');
  const btn = document.getElementById('modalSaveBtn');
  const nomFichier = (suffixe) => 'larka-supervision-' + suffixe + '-' + new Date().toISOString().slice(0, 10);
  let entete, lignes, nom;
  try {
    if (btn) btn.disabled = true;
    if (quoi === 'courbes') {
      const ids = [...document.querySelectorAll('#cxxListe input:checked')].map(c => +c.value);
      if (!ids.length) { etat.innerHTML = `<div class="cx-test-verdict ko">${_cxT(`Cochez au moins une courbe.`, `Tick at least one chart.`)}</div>`; return; }
      if (ids.length > CX_EXPORT_COURBES_MAX) { etat.innerHTML = `<div class="cx-test-verdict ko">${_cxT(`${CX_EXPORT_COURBES_MAX} courbes au plus par document.`, `${CX_EXPORT_COURBES_MAX} charts at most per document.`)}</div>`; return; }
      const plage = _cxExportPlage();
      if (!plage) { etat.innerHTML = `<div class="cx-test-verdict ko">${_cxT(`Période invalide.`, `Invalid period.`)}</div>`; return; }
      const r = await _cxPdfCourbes(ids, 'export', {
        plage, style: (document.getElementById('cxxStyle') || {}).value || null,
        parPage: +(document.getElementById('cxxParPage') || {}).value || 1,
        synthese: !!(document.getElementById('cxxSynthese') || {}).checked,
        avancement: (i, n) => { etat.innerHTML = `<div class="cx-aide">${_cxT(`Lecture des courbes : ${i} / ${n}…`, `Reading charts: ${i} / ${n}…`)}</div>`; },
      });
      closeModal();
      toast(_cxT(`PDF prêt : ${r.courbes} courbe(s), ${r.pages} page(s).`, `PDF ready: ${r.courbes} chart(s), ${r.pages} page(s).`), 'success');
      return;
    }
    if (quoi === 'valeurs') {
      entete = [_cxT(`Bâtiment`, `Building`), _cxT(`Connexion`, `Connection`), _cxT(`Point`, `Point`), _cxT(`Code`, `Code`), _cxT(`Type`, `Type`),
                _cxT(`Nature`, `Kind`), _cxT(`Valeur`, `Value`), _cxT(`Texte`, `Text`), _cxT(`Unité`, `Unit`), _cxT(`Lue le`, `Read at`), _cxT(`Qualité`, `Quality`), _cxT(`Erreur`, `Error`)];
      lignes = _cxPointsAffiches().map(({ p, pa }) => [pa.BatimentNom || '', pa.Nom || '', p.Nom || '', p.Code || '', _cxLibType(_cxTypePoint(p)), p.Nature || '',
        p.DerniereValeur === null || p.DerniereValeur === '' ? null : +p.DerniereValeur, p.DerniereValeurTexte || '', p.Unite || '', p.DerniereLecture || '', p.DerniereQualite || '', p.DerniereErreur || '']);
      nom = nomFichier('valeurs');
    } else if (quoi === 'consos') {
      const nb = +(document.getElementById('cxxNbMois') || {}).value || 1;
      let d = CxSup.consos;
      if (nb > 1 && !(d && (d.lignes || []).some(l => (l.serie || []).length >= nb))) {
        etat.innerHTML = `<div class="cx-aide">${_cxT(`Calcul des 12 mois…`, `Computing 12 months…`)}</div>`;
        d = await apiRequest(`connect_consommations&mois=${encodeURIComponent(CxSup.mois)}&historique=12`);
      }
      const ids = new Set(_cxLignesConsos().map(l => l.point.Id));
      entete = [_cxT(`Bâtiment`, `Building`), _cxT(`Connexion`, `Connection`), _cxT(`Compteur`, `Meter`), _cxT(`Type`, `Type`), _cxT(`Mois`, `Month`),
                _cxT(`Index début`, `Start index`), _cxT(`Index fin`, `End index`), _cxT(`Consommation`, `Consumption`), _cxT(`Unité`, `Unit`), _cxT(`Partielle`, `Partial`)];
      lignes = [];
      (d.lignes || []).filter(l => ids.has(l.point.Id)).forEach(l => {
        const p = l.point;
        if (nb === 1) {
          lignes.push([p.BatimentNom || '', p.PasserelleNom || '', p.Nom, _cxLibType(l.type), d.mois, l.conso.debut, l.conso.fin, l.conso.conso, p.Unite || '', l.conso.partiel ? 'oui' : '']);
        } else {
          (l.serie || []).forEach(x => lignes.push([p.BatimentNom || '', p.PasserelleNom || '', p.Nom, _cxLibType(l.type), x.mois, null, null, x.conso, p.Unite || '', x.partiel ? 'oui' : '']));
        }
      });
      nom = nomFichier('consommations-' + CxSup.mois);
    } else {
      const pts = _cxPointsAffiches();
      if (!pts.length) { etat.innerHTML = `<div class="cx-test-verdict ko">${_cxT(`Aucun point affiché.`, `No point shown.`)}</div>`; return; }
      const debut = document.getElementById('cxxDebut').value, fin = document.getElementById('cxxFin').value, pas = document.getElementById('cxxPas').value;
      if (!debut || !fin || fin < debut) { etat.innerHTML = `<div class="cx-test-verdict ko">${_cxT(`Période invalide.`, `Invalid period.`)}</div>`; return; }
      etat.innerHTML = `<div class="cx-aide">${_cxT(`Lecture de l'historique…`, `Reading history…`)}</div>`;
      const r = await _cxApi(`connect_export&points=${pts.map(x => x.p.Id).slice(0, 500).join(',')}&debut=${debut}&fin=${fin}&pas=${pas}`, 'GET', null, 120000);
      const info = r.points || {};
      const base = id => { const i = info[id] || {}; return [i.BatimentNom || '', i.PasserelleNom || '', i.Nom || '']; };
      if (r.pas === 'brut') {
        entete = [_cxT(`Bâtiment`, `Building`), _cxT(`Connexion`, `Connection`), _cxT(`Point`, `Point`), _cxT(`Horodatage`, `Timestamp`), _cxT(`Valeur`, `Value`), _cxT(`Texte`, `Text`), _cxT(`Unité`, `Unit`), _cxT(`Qualité`, `Quality`)];
        lignes = r.lignes.map(x => [...base(x[0]), x[1], x[2], x[3] || '', (info[x[0]] || {}).Unite || '', x[4] || '']);
      } else {
        entete = [_cxT(`Bâtiment`, `Building`), _cxT(`Connexion`, `Connection`), _cxT(`Point`, `Point`), _cxT(`Période`, `Period`), _cxT(`Moyenne`, `Average`), _cxT(`Minimum`, `Minimum`),
                  _cxT(`Maximum (index de fin pour un compteur)`, `Maximum (end index for a meter)`), _cxT(`Nombre de mesures`, `Number of measurements`), _cxT(`Consommation (compteur)`, `Consumption (meter)`), _cxT(`Unité`, `Unit`)];
        lignes = r.lignes.map(x => [...base(x[0]), x[1], x[2], x[3], x[4], x[5], x[6], (info[x[0]] || {}).Unite || '']);
      }
      if (r.tronque) toast(_cxT(`Export limité à ${r.limite.toLocaleString('fr-FR')} lignes : réduisez la période ou choisissez un pas plus large.`, `Export limited to ${r.limite} rows: shorten the period or choose a wider step.`), 'warning');
      nom = nomFichier('mesures-' + pas);
    }
    if (!lignes.length) { etat.innerHTML = `<div class="cx-test-verdict moyen">${_cxT(`Rien à exporter avec ces choix.`, `Nothing to export with these choices.`)}</div>`; return; }
    if (format === 'xlsx') await _cxEcrireXlsx(nom, entete, lignes);
    else _cxEcrireCsv(nom, entete, lignes);
    closeModal();
    toast(_cxT(`${lignes.length.toLocaleString('fr-FR')} ligne(s) exportée(s).`, `${lignes.length} row(s) exported.`), 'success');
  } catch (e) {
    if (etat) etat.innerHTML = `<div class="cx-test-verdict ko">${escHtml(e.message)}</div>`;
  } finally {
    if (btn) btn.disabled = false;
  }
}

/** CSV pour Excel en français : « ; », virgule décimale, BOM UTF-8. */
function _cxEcrireCsv(nom, entete, lignes) {
  const cell = v => {
    if (v === null || v === undefined) return '';
    if (typeof v === 'number') return String(v).replace('.', ',');
    const s = String(v);
    return /[";\n\r]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
  };
  const texte = '﻿' + [entete, ...lignes].map(l => l.map(cell).join(';')).join('\r\n');
  _cxTelecharger(new Blob([texte], { type: 'text/csv;charset=utf-8' }), nom + '.csv');
}

async function _cxEcrireXlsx(nom, entete, lignes) {
  if (typeof XLSX === 'undefined') {
    await new Promise((ok, ko) => {
      const s = document.createElement('script');
      s.src = 'js/vendor/xlsx/xlsx.full.min.js';
      s.onload = ok; s.onerror = () => ko(new Error(_cxT(`Bibliothèque Excel introuvable : exportez en CSV.`, `Excel library not found: export as CSV.`)));
      document.head.appendChild(s);
    });
  }
  const ws = XLSX.utils.aoa_to_sheet([entete, ...lignes]);
  ws['!cols'] = entete.map((h, j) => ({ wch: Math.min(40, Math.max(10, String(h).length + 2, ...lignes.slice(0, 200).map(l => String(l[j] ?? '').length + 1))) }));
  ws['!autofilter'] = { ref: XLSX.utils.encode_range({ s: { r: 0, c: 0 }, e: { r: lignes.length, c: entete.length - 1 } }) };
  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, 'Supervision');
  XLSX.writeFile(wb, nom + '.xlsx');
}

function _cxTelecharger(blob, nom) {
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = nom;
  document.body.appendChild(a);
  a.click();
  setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
}

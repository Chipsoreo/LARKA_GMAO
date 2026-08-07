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
 * Larka — Plans interactifs v3
 * Fonctionnalités :
 *  - Panneaux gauche/droite réductibles
 *  - Onglets gauche : Étages, Calques (catégorisés), Listes, Filtres avancés
 *  - Bâtiment enrichi (type, surface, propriétaire, gestionnaire, notes...)
 *  - Mètre de référence visuel (2 clics)
 *  - Outil texte, gomme (reste active), mesure (temporaire)
 *  - Traits : styles visuels, verrouillage angle/distance, depuis point, fermer boucle
 *  - Traits : s'arrêter et retracer ailleurs sans revenir en arrière
 *  - Points/sommets fixables (point, trait, zone)
 *  - Zones : pas de mid-handles, hauteur -> volume
 *  - Sauvegardes : export/import/restauration
 *  - Fonds enregistrés : visualisation
 *  - Rattachement : recherche + listes configurables
 *  - Points : modal direct à la création
 * POINT D'ENTRÉE : renderPlans()
 */

/* global App, PlansApi, BiensApi, EquipementsApi, ListesApi, showLoading, toast, openModal,
   closeModal, canEdit, canDelete, errorHtml, apiRequest, API_BASE */

let _p = {
  map: null, batiments: [], etages: [], elements: [],
  batiment: null, etage: null,
  layers: {}, biens: [], equips: [],
  leafletOk: false,
  mode: null, sousType: '',
  drawCoords: [], drawLayer: null,
  color: '#2b7be6', opacity: 0.4, symbol: 'circle',
  measureLayer: null, measureCoords: [],
  selectedEl: null,
  activeCalque: 'Plan', hiddenCalques: {},
  vertexHandles: [],
  leftCollapsed: false, rightCollapsed: false,
  leftTab: 'etages',
  refMode: false, refCoords: [], refLayer: null, refRealDist: 1,
  // Mètre de référence tracé (persistant en session) : { coords:[[lat,lng],[lat,lng]], dist, hidden }
  // Stocké ici (et non dans _p.elements) pour pouvoir l'effacer à la gomme et le cacher.
  refDraw: null, refDrawLayer: null,
  lockAngle: false, lockAngleVal: 45,
  lockDistance: false, lockDistVal: 100,
  drawFromPoint: false,
  lineStyle: '',
  textMode: false, textValue: 'Texte', textSize: 14,
  eraserMode: false,
  filters: { search: '', type: '', calque: '', sousType: '', category: '', pointCategorie: '' },
  fixedVertices: new Set(),
  savedSnapshots: [],
  listes: [],
  pointCategories: [], // Catégories de points configurables (depuis ListesApi 'CategoriePoint')
};

const PLAN_SYMBOLS = {
  circle:   { label: 'Cercle',   svg: (c,s) => `<svg viewBox="0 0 24 24" width="${s}" height="${s}"><circle cx="12" cy="12" r="10" fill="${c}" stroke="#fff" stroke-width="2"/></svg>` },
  square:   { label: 'Carré',    svg: (c,s) => `<svg viewBox="0 0 24 24" width="${s}" height="${s}"><rect x="2" y="2" width="20" height="20" rx="2" fill="${c}" stroke="#fff" stroke-width="2"/></svg>` },
  triangle: { label: 'Triangle', svg: (c,s) => `<svg viewBox="0 0 24 24" width="${s}" height="${s}"><polygon points="12,2 22,22 2,22" fill="${c}" stroke="#fff" stroke-width="2"/></svg>` },
  diamond:  { label: 'Losange',  svg: (c,s) => `<svg viewBox="0 0 24 24" width="${s}" height="${s}"><polygon points="12,1 23,12 12,23 1,12" fill="${c}" stroke="#fff" stroke-width="2"/></svg>` },
  cross:    { label: 'Croix',    svg: (c,s) => `<svg viewBox="0 0 24 24" width="${s}" height="${s}"><path d="M8,2h8v6h6v8h-6v6h-8v-6h-6v-8h6z" fill="${c}" stroke="#fff" stroke-width="1.5"/></svg>` },
  star:     { label: 'Étoile',   svg: (c,s) => `<svg viewBox="0 0 24 24" width="${s}" height="${s}"><polygon points="12,1 15,9 24,9 17,14 19,23 12,18 5,23 7,14 0,9 9,9" fill="${c}" stroke="#fff" stroke-width="1"/></svg>` },
  hexagon:  { label: 'Hexagone', svg: (c,s) => `<svg viewBox="0 0 24 24" width="${s}" height="${s}"><polygon points="12,2 22,7 22,17 12,22 2,17 2,7" fill="${c}" stroke="#fff" stroke-width="1.5"/></svg>` },
  pin:      { label: 'Épingle',  svg: (c,s) => `<svg viewBox="0 0 24 24" width="${s}" height="${s}"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z" fill="${c}" stroke="#fff" stroke-width="1.5"/><circle cx="12" cy="9" r="2.5" fill="#fff"/></svg>` },
};

const TRAIT_SUBTYPES = {
  '':        { label: 'Trait simple', dash: null,         weight: 3, color: null },
  mur:       { label: 'Mur',          dash: null,         weight: 5, color: '#374151' },
  cloison:   { label: 'Cloison',      dash: '8,4',        weight: 3, color: '#6b7280' },
  porte:     { label: 'Porte',        dash: null,         weight: 4, color: '#b45309' },
  fenetre:   { label: 'Fenêtre',      dash: '3,6',        weight: 3, color: '#0ea5e9' },
  reseau:    { label: 'Réseau',       dash: '10,5,2,5',   weight: 2, color: '#dc2626' },
  elec:      { label: 'Électrique',   dash: '4,4',        weight: 2, color: '#f5a623' },
  plomberie: { label: 'Plomberie',    dash: '6,3',        weight: 3, color: '#2b7be6' },
  cvc:       { label: 'CVC',          dash: '12,4',       weight: 2, color: '#1ab394' },
};

const LINE_STYLES = {
  '':        { label: '\u2500\u2500 Continue',       dash: null },
  dashed:    { label: '- - Tirets',                  dash: '10,5' },
  dotted:    { label: '\u00b7\u00b7\u00b7 Pointillé', dash: '3,3' },
  dashdot:   { label: '-\u00b7- Mixte',              dash: '10,3,3,3' },
  longdash:  { label: '\u2014\u2014 Tirets longs',   dash: '16,6' },
};

const CALQUE_CATEGORIES = {
  'Structure':   { icon: '\ud83c\udfd7\ufe0f' },
  'Réseaux':     { icon: '\ud83d\udd0c' },
  'Équipements': { icon: '\u2699\ufe0f' },
  'Sécurité':    { icon: '\ud83d\udee1\ufe0f' },
  'Annotations': { icon: '\ud83d\udcdd' },
  'Mesures':     { icon: '\ud83d\udcd0' },
  'Divers':      { icon: '\ud83d\udcc1' },
};

function _getCalqueCategory(n) {
  n = (n || '').toLowerCase();
  if (n.includes('mur') || n.includes('struct') || n === 'plan' || n.includes('cloison') || n.includes('porte') || n.includes('fene')) return 'Structure';
  if (n.includes('seau') || n.includes('lec') || n.includes('plomb') || n.includes('cvc') || n.includes('câbl')) return 'Réseaux';
  if (n.includes('quip') || n.includes('machine')) return 'Équipements';
  if (n.includes('secu') || n.includes('sécu') || n.includes('incendie') || n.includes('alarme')) return 'Sécurité';
  if (n.includes('annot') || n.includes('texte') || n.includes('note')) return 'Annotations';
  if (n.includes('mesur') || n.includes('cote') || n.includes('dimen')) return 'Mesures';
  return 'Divers';
}

function _loadLeaflet() {
  return new Promise(resolve => {
    if (_p.leafletOk && window.L) return resolve();
    if (!document.querySelector('link[href*="leaflet"]')) { const lk = document.createElement('link'); lk.rel = 'stylesheet'; lk.href = 'js/vendor/leaflet/leaflet.css'; document.head.appendChild(lk); }
    if (window.L) { _p.leafletOk = true; return resolve(); }
    const s = document.createElement('script'); s.src = 'js/vendor/leaflet/leaflet.js'; s.onload = () => { _p.leafletOk = true; resolve(); }; document.head.appendChild(s);
  });
}

// ══════════════════ ENTRÉE PRINCIPALE ══════════════════
async function renderPlans() {
  // Les onglets sont dans la barre du plan (voir plan-batbar-row ci-dessous),
  // pas ici : le choix de vue doit être à côté du plan qu'il concerne.
  document.getElementById('topbarActions').innerHTML = '';
  const c = document.getElementById('mainContent');
  showLoading(c);
  try {
    await _loadLeaflet();
    _p.batiments = await PlansApi.getBatiments();
    try { _p.biens = await BiensApi.getAll(); } catch(_e) { _p.biens = []; }
    try { _p.equips = await EquipementsApi.getAll(); } catch(_e) { _p.equips = []; }
    // Charger les listes config (rattachement matériel) ET les catégories de points en un seul appel
    let allListesData = null;
    try { allListesData = await ListesApi.getAll(); } catch(_e) { allListesData = null; }
    _p.listes = [];
    if (allListesData && typeof allListesData === 'object' && !Array.isArray(allListesData)) {
      Object.entries(allListesData).forEach(([cat, items]) => {
        if (Array.isArray(items)) items.forEach(item => _p.listes.push({...item, Categorie: item.Categorie || cat}));
      });
    } else if (Array.isArray(allListesData)) {
      _p.listes = allListesData;
    }
    // Catégories de points configurables (depuis la même réponse, conserve Obligatoire/SaisieLibre)
    try {
      const cats = (allListesData && allListesData.CategoriePoint) || [];
      _p.pointCategories = Array.isArray(cats)
        ? cats.filter(c => c.Actif != 0).sort((a,b) => (a.Ordre||0) - (b.Ordre||0) || (a.Valeur||'').localeCompare(b.Valeur||''))
        : [];
    } catch(_e) { _p.pointCategories = []; }
    try { _p.savedSnapshots = JSON.parse(localStorage.getItem('gmao_plan_snapshots') || '[]'); } catch(_e) { _p.savedSnapshots = []; }
    if (_p.batiment && !_p.batiments.find(b => b.Id === _p.batiment.Id)) _p.batiment = null;
    if (!_p.batiment && _p.batiments.length) _p.batiment = _p.batiments[0];
    if (_p.batiment) {
      _p.etages = await PlansApi.getEtages(_p.batiment.Id);
      if (_p.etage && !_p.etages.find(e => e.Id === _p.etage.Id)) _p.etage = null;
      if (!_p.etage && _p.etages.length) _p.etage = _p.etages[0];
    } else { _p.etages = []; _p.etage = null; }
    if (_p.etage) _p.elements = await PlansApi.getElements(_p.etage.Id);
    else _p.elements = [];
    _renderMain(c);
    _pSetupKeyboard();
  } catch(e) { c.innerHTML = errorHtml(e.message); }
}

// ══════════════════ CSS v3 ══════════════════
function _injectCSS() {
  if (document.getElementById('plan-v3-css')) return;
  const s = document.createElement('style'); s.id = 'plan-v3-css';
  s.textContent = `
.plan-side-tabs{display:flex;border-bottom:1px solid var(--gray-border);background:var(--gray-bg)}
.plan-side-tab{flex:1;padding:8px 4px;border:none;background:transparent;font-size:11px;font-weight:600;color:var(--gray-text);cursor:pointer;border-bottom:2px solid transparent}
.plan-side-tab:hover{color:var(--text);background:var(--white)}
.plan-side-tab.active{color:var(--blue);border-bottom-color:var(--blue);background:var(--white)}
.plan-side-collapse-btn{background:none;border:none;cursor:pointer;padding:6px 8px;color:var(--gray-text);font-size:10px}
.plan-side-collapsed{width:24px;display:flex;flex-direction:column;align-items:center;justify-content:center;cursor:pointer;background:var(--white);border:1px solid var(--gray-border);border-radius:var(--radius);font-size:10px;color:var(--gray-text)}
.plan-side-collapsed:hover{background:var(--blue-pale);color:var(--blue)}
.plan-tbtn-small{display:inline-flex;align-items:center;gap:2px;padding:3px 8px;border:1px solid var(--gray-border);background:var(--white);color:var(--text);border-radius:6px;cursor:pointer;font-size:10px;white-space:nowrap}
.plan-tbtn-small:hover{background:var(--gray-bg)}
.plan-tbtn-small.active{background:var(--blue);color:#fff;border-color:var(--blue)}
.plan-ref-info{position:absolute;top:12px;left:50%;transform:translateX(-50%);z-index:500;background:rgba(231,76,60,.95);color:#fff;padding:6px 14px;border-radius:8px;font-size:12px;font-weight:600;pointer-events:none}
.plan-list-section{margin-bottom:2px}
.plan-list-header{display:flex;align-items:center;gap:6px;padding:8px 10px;cursor:pointer;font-size:11px;font-weight:700;color:var(--navy);text-transform:uppercase;letter-spacing:.3px;border-bottom:1px solid var(--gray-bg);user-select:none}
.plan-list-header:hover{background:var(--gray-bg)}
.plan-list-item{display:flex;align-items:center;gap:6px;padding:5px 10px 5px 18px;cursor:pointer;font-size:12px;color:var(--text);border-bottom:1px solid var(--gray-bg)}
.plan-list-item:hover{background:var(--blue-pale)}
.plan-list-item.active{background:var(--blue-pale);color:var(--blue);font-weight:600}
.plan-list-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}
.plan-list-label{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.plan-list-badge{display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:16px;padding:0 4px;background:var(--gray-bg);border-radius:8px;font-size:9px;font-weight:700;color:var(--gray-text)}
.plan-filter-group{margin-bottom:10px}
.plan-filter-label{font-size:11px;font-weight:600;color:var(--navy);margin-bottom:4px;display:block}
.plan-filter-input{width:100%;padding:6px 10px;border:1px solid var(--gray-border);border-radius:8px;font-size:12px;background:var(--white);color:var(--text)}
.plan-filter-input:focus{outline:none;border-color:var(--blue)}
.plan-filter-select{width:100%;padding:6px 10px;border:1px solid var(--gray-border);border-radius:8px;font-size:12px;background:var(--white);color:var(--text)}
.plan-filter-chips{display:flex;flex-wrap:wrap;gap:4px;margin-top:4px}
.plan-filter-chip{display:inline-flex;align-items:center;gap:3px;padding:3px 8px;border-radius:12px;font-size:10px;font-weight:600;cursor:pointer;border:1px solid var(--gray-border);background:var(--white);color:var(--text)}
.plan-filter-chip.active{background:var(--blue);color:#fff;border-color:var(--blue)}
.plan-filter-chip:hover{border-color:var(--blue)}
.plan-info-grid{display:grid;grid-template-columns:1fr 1fr;gap:6px}
.plan-info-field{padding:6px 0;border-bottom:1px solid var(--gray-bg)}
.plan-info-field-label{font-size:10px;color:var(--gray-text);text-transform:uppercase;letter-spacing:.5px}
.plan-info-field-val{font-size:13px;color:var(--text);font-weight:500;margin-top:2px}
.plan-vertex-fixed{background:var(--red,#e74c3c)!important;border-color:#fff!important}
.plan-kbd-hint{position:absolute;bottom:12px;right:12px;background:rgba(0,0,0,.7);color:#fff;padding:5px 10px;border-radius:6px;font-size:10px;pointer-events:none;opacity:0;transition:opacity .2s;z-index:500}
.plan-kbd-hint.show{opacity:1}
.plan-kbd-hint kbd{background:rgba(255,255,255,.2);padding:1px 5px;border-radius:3px;font-family:monospace;font-size:10px;margin:0 2px}
.plan-vertex-action-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.plan-vertex-action-btn{padding:10px;border:1px solid var(--gray-border);border-radius:8px;background:var(--white);cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:4px;font-size:12px;color:var(--text);transition:all .15s}
.plan-vertex-action-btn:hover{border-color:var(--blue);background:var(--blue-pale);color:var(--blue)}
.plan-vertex-action-btn.danger:hover{border-color:#e74c3c;background:#fee;color:#e74c3c}
.plan-vertex-action-btn .icon{font-size:18px}
.plan-point-tooltip{background:rgba(0,0,0,.85)!important;color:#fff!important;border:none!important;border-radius:6px!important;font-size:12px!important;padding:6px 10px!important;box-shadow:0 2px 8px rgba(0,0,0,.2)!important;line-height:1.4!important}
.plan-point-tooltip:before{border-top-color:rgba(0,0,0,.85)!important}
@media (max-width: 900px) {
  .plan-side-collapsed{display:none!important}
  .plan-side{max-height:none!important}
  .plan-side-left{max-height:200px!important;order:1}
  .plan-mapcard{order:2;min-height:50vh!important}
  .plan-side-right{order:3;max-height:240px!important}
  .plan-bottom{order:4}
  .plan-toolbar-row{gap:4px!important}
  .plan-toolgroup{flex-wrap:wrap}
}
  `;
  document.head.appendChild(s);
}

// ══════════════════ RENDU PRINCIPAL ══════════════════
function _renderMain(c) {
  const ed = canEdit();
  _p.mode = null; _p.drawCoords = []; _p.selectedEl = null;
  _p.textMode = false; _p.eraserMode = false; _p.refMode = false;
  _injectCSS();

  c.innerHTML = `
  <div class="plan-shell">
    <div class="card plan-batbar-card"><div class="plan-batbar-row">
      ${typeof plansTabBar==='function'?plansTabBar('plans'):''}
      <select id="planBatSelect" class="plan-batbar-select" onchange="_planSelectBat(this.value)">
        ${_p.batiments.length===0?'<option value="">— Aucun bâtiment —</option>':''}
        ${_p.batiments.map(b=>`<option value="${b.Id}" ${_p.batiment?.Id===b.Id?'selected':''}>🏢 ${_escPlan(b.Nom)}</option>`).join('')}
      </select>
      ${ed?`
        <button class="btn btn-secondary btn-sm" onclick="_planEditBatiment()" title="Nouveau bâtiment">＋ Bâtiment</button>
        ${_p.batiment?`
          <button class="btn btn-secondary btn-sm" onclick="_planEditBatiment(_p.batiment.Id)" title="Modifier">✏️</button>
          <button class="btn btn-secondary btn-sm" onclick="_planInfoBatiment()" title="Informations">ℹ️</button>
          <button class="btn btn-sm plan-btn-danger" onclick="_planDeleteBatiment()" title="Supprimer">🗑️</button>
        `:''}
        <div style="flex:1"></div>
        ${_p.etage?`
          <button class="btn btn-secondary btn-sm" onclick="_pFitToElements()" title="Recadrer sur les éléments">🎯 Recadrer</button>
          <button class="btn btn-secondary btn-sm" onclick="_planUploadFond()" title="Charger une image de fond">🖼️ Fond</button>
          <button class="btn btn-primary btn-sm" onclick="_planImport()" title="Importer DXF/SVG/GeoJSON/CSV">📥 Importer</button>
          <button class="btn btn-secondary btn-sm" onclick="_planShowMoreMenu(event)" title="Plus d'options">⋯</button>
        `:''}
      `:''}
    </div></div>

    ${ed?`
    <div class="card plan-toolbar-card"><div class="plan-toolbar-row">
      <div class="plan-toolgroup">
        <button class="plan-tbtn ${!_p.mode&&!_p.eraserMode&&!_p.textMode&&!_p.refMode?'active':''}" onclick="_pTool(null)" title="Sélection"><svg width="14" height="14" viewBox="0 0 16 16"><path d="M2,1L2,14L6,10L10,14L8,4Z" fill="currentColor"/></svg></button>
        <button class="plan-tbtn" id="ptPoint" onclick="_pTool('point')" title="Point technique"><svg width="14" height="14" viewBox="0 0 16 16"><circle cx="8" cy="8" r="5" fill="currentColor"/></svg></button>
        <button class="plan-tbtn" id="ptPersonne" onclick="_pTool('personne')" title="Personne (bureau)"><svg width="15" height="15" viewBox="0 0 16 16"><circle cx="8" cy="5" r="3" fill="currentColor"/><path d="M2.5,14.5 a5.5,4.5 0 0,1 11,0 z" fill="currentColor"/></svg></button>
        <button class="plan-tbtn" id="ptTrait" onclick="_pTool('trait')" title="Trait"><svg width="14" height="14" viewBox="0 0 16 16"><line x1="2" y1="14" x2="14" y2="2" stroke="currentColor" stroke-width="2.5"/></svg></button>
        <button class="plan-tbtn" id="ptZone" onclick="_pTool('zone')" title="Zone"><svg width="14" height="14" viewBox="0 0 16 16"><polygon points="8,1 15,6 13,14 3,14 1,6" fill="currentColor" opacity=".55" stroke="currentColor" stroke-width="1"/></svg></button>
        <button class="plan-tbtn" id="ptMeasure" onclick="_pTool('measure')" title="Mesurer (temporaire)"><svg width="14" height="14" viewBox="0 0 16 16"><line x1="1" y1="14" x2="15" y2="2" stroke="currentColor" stroke-width="1.5"/><line x1="1" y1="14" x2="1" y2="10" stroke="currentColor" stroke-width="1.5"/><line x1="1" y1="14" x2="5" y2="14" stroke="currentColor" stroke-width="1.5"/><line x1="15" y1="2" x2="15" y2="6" stroke="currentColor" stroke-width="1.5"/><line x1="15" y1="2" x2="11" y2="2" stroke="currentColor" stroke-width="1.5"/></svg></button>
        <button class="plan-tbtn" id="ptText" onclick="_pToolText()" title="Texte"><svg width="14" height="14" viewBox="0 0 16 16"><text x="2" y="13" font-size="14" font-weight="bold" fill="currentColor">T</text></svg></button>
        <button class="plan-tbtn" id="ptEraser" onclick="_pToolEraser()" title="Gomme"><svg width="14" height="14" viewBox="0 0 16 16"><path d="M14 14H5L2 11l6-6 6 6-3 3" fill="none" stroke="currentColor" stroke-width="1.5"/></svg></button>
        <button class="plan-tbtn" id="ptRef" onclick="_pToolRef()" title="Mètre de référence"><svg width="14" height="14" viewBox="0 0 16 16"><line x1="1" y1="8" x2="15" y2="8" stroke="currentColor" stroke-width="1.5"/><line x1="1" y1="5" x2="1" y2="11" stroke="currentColor" stroke-width="1.5"/><line x1="15" y1="5" x2="15" y2="11" stroke="currentColor" stroke-width="1.5"/></svg></button>
        <button class="plan-tbtn" id="ptRefToggle" onclick="_pToggleRefMeter()" title="Cacher le mètre de référence"><svg width="14" height="14" viewBox="0 0 16 16"><path d="M8 3C4 3 1.5 8 1.5 8S4 13 8 13s6.5-5 6.5-5S12 3 8 3z" fill="none" stroke="currentColor" stroke-width="1.2"/><circle cx="8" cy="8" r="2" fill="currentColor"/></svg></button>
      </div>
      <span class="plan-sep"></span>
      <label class="plan-mini-label"><span style="font-size:11px;color:var(--gray-text)">Calque:</span>
        <select id="planCalqueActif" class="plan-mini-select" onchange="_p.activeCalque=this.value">${_planCalqueOptions()}</select>
        <button class="plan-tbtn" onclick="_planAddCalque()" style="width:24px;height:24px">+</button>
      </label>
      <span class="plan-sep"></span>
      <label class="plan-mini-label"><span style="font-size:11px;color:var(--gray-text)">Taille marqueurs:</span>
        <select id="planTailleMarqueurs" class="plan-mini-select" onchange="_pSetTailleMarqueurs(this.value)" title="Taille des points et personnes sur ce plan">
          <option value="0.65">Petit</option>
          <option value="1">Normal</option>
          <option value="1.5">Grand</option>
          <option value="2">Très grand</option>
        </select>
      </label>
      <span class="plan-sep"></span>
      <select id="planTraitSub" class="plan-mini-select" onchange="_p.sousType=this.value" style="display:none">${Object.entries(TRAIT_SUBTYPES).map(([k,v])=>`<option value="${k}">${v.label}</option>`).join('')}</select>
      <select id="planLineStyle" class="plan-mini-select" onchange="_p.lineStyle=this.value" style="display:none">${Object.entries(LINE_STYLES).map(([k,v])=>`<option value="${k}">${v.label}</option>`).join('')}</select>
      <select id="planSymbol" class="plan-mini-select" onchange="_p.symbol=this.value" style="display:none">${Object.entries(PLAN_SYMBOLS).map(([k,v])=>`<option value="${k}">${v.label}</option>`).join('')}</select>
      <div class="plan-color-wrap" title="Couleur"><input type="color" id="planColor" value="${_p.color}" onchange="_p.color=this.value"></div>
      <span id="planDrawStatus" class="plan-status"></span>
      <div style="flex:1"></div>
      <span id="planTraitOpts" style="display:none;gap:4px;align-items:center;flex-wrap:wrap">
        <button class="plan-tbtn-small ${_p.lockAngle?'active':''}" onclick="_pToggleLockAngle()" id="ptLockAngle" title="Verrouiller l'angle">🔒 Angle</button>
        <button class="plan-tbtn-small ${_p.lockDistance?'active':''}" onclick="_pToggleLockDist()" id="ptLockDist" title="Verrouiller la distance">📏 Dist.</button>
        <button class="plan-tbtn-small" onclick="_pCloseLoop()" title="Fermer la forme en boucle">⟳ Fermer</button>
      </span>
      <button id="planFinish" class="btn btn-primary btn-sm" style="display:none" onclick="_pFinish()">✓ Terminer</button>
      <button id="planCancel" class="btn btn-secondary btn-sm" style="display:none" onclick="_pCancel()">Annuler</button>
    </div></div>
    `:''}

    <div class="plan-body">
      ${_p.leftCollapsed?`<div class="plan-side-collapsed" onclick="_p.leftCollapsed=false;_renderMain(document.getElementById('mainContent'))"><span>◀</span></div>`:`
      <div class="card plan-side plan-side-left">
        <div class="plan-side-tabs">
          <button class="plan-side-tab ${_p.leftTab==='etages'?'active':''}" onclick="_pLeftTab('etages')" title="Étages du bâtiment">🏢 Étages</button>
          <button class="plan-side-tab ${_p.leftTab==='calques'?'active':''}" onclick="_pLeftTab('calques')" title="Calques visibles">📚 Calques</button>
          <button class="plan-side-tab ${_p.leftTab==='recherche'?'active':''}" onclick="_pLeftTab('recherche')" title="Rechercher et filtrer">🔍 Recherche</button>
          <button class="plan-side-collapse-btn" onclick="_p.leftCollapsed=true;_renderMain(document.getElementById('mainContent'))">▶</button>
        </div>
        <div id="planLeftContent" style="flex:1;overflow-y:auto">${_renderLeftContent()}</div>
      </div>`}
      <div class="card plan-mapcard">
        <div id="planMapContainer" style="width:100%;height:100%"></div>
        <div id="planScaleBar" class="plan-scale-bar"></div>
        <div id="planRefInfo" class="plan-ref-info" style="display:none"></div>
      </div>
      ${_p.rightCollapsed?`<div class="plan-side-collapsed" onclick="_p.rightCollapsed=false;_renderMain(document.getElementById('mainContent'))"><span>▶</span></div>`:`
      <div class="card plan-side plan-side-right">
        <div class="plan-side-header"><span>Propriétés</span><button class="plan-side-collapse-btn" onclick="_p.rightCollapsed=true;_renderMain(document.getElementById('mainContent'))">◀</button></div>
        <div id="planPropsPanel" class="plan-props-content"><div class="plan-empty-msg">Sélectionnez un élément</div></div>
      </div>`}
    </div>
    <div class="card plan-bottom"><div id="planBottomContent" class="plan-bottom-inner"><span class="plan-empty-msg" style="padding:12px">Sélectionnez un élément pour voir détails et matériel rattaché.</span></div></div>
  </div>`;
  setTimeout(() => _pInitMap(), 80);
}

// ══════════════════ PANNEAU GAUCHE ══════════════════
function _pLeftTab(tab) { _p.leftTab = tab; const el = document.getElementById('planLeftContent'); if (el) el.innerHTML = _renderLeftContent(); }
function _renderLeftContent() {
  if (_p.leftTab === 'etages') return _renderEtagePanel();
  if (_p.leftTab === 'calques') return _renderCalquePanel();
  if (_p.leftTab === 'recherche') return _renderRecherchePanel();
  // rétro-compat
  if (_p.leftTab === 'listes' || _p.leftTab === 'filtres') { _p.leftTab = 'recherche'; return _renderRecherchePanel(); }
  return '';
}

function _renderEtagePanel() {
  const ed = canEdit();
  return `<div class="plan-side-header"><span>Étages</span>${ed && _p.batiment ? '<button class="btn btn-primary btn-sm" onclick="_planEditEtage()">+ Étage</button>' : ''}</div><div class="plan-etage-list">${_renderEtageList()}</div>`;
}
function _renderEtageList() {
  if (!_p.batiment) return '<div class="plan-empty-msg" style="padding:16px">Aucun bâtiment</div>';
  if (!_p.etages.length) return '<div class="plan-empty-msg" style="padding:16px">Aucun étage</div>';
  const ed = canEdit();
  return _p.etages.sort((a,b) => a.Niveau - b.Niveau).map(et => {
    const active = _p.etage?.Id === et.Id;
    const niv = et.Niveau < 0 ? 'SS'+Math.abs(et.Niveau) : et.Niveau === 0 ? 'RDC' : 'N'+et.Niveau;
    return `<div class="plan-etage-item ${active?'active':''}" onclick="_planSelectEtage(${et.Id})"><span class="plan-etage-niv">${niv}</span><span class="plan-etage-nom">${_escPlan(et.Nom)}</span>${ed?`<span class="plan-etage-actions"><button class="plan-icon-btn" onclick="event.stopPropagation();_planEditEtage(${et.Id})">✏️</button><button class="plan-icon-btn plan-icon-danger" onclick="event.stopPropagation();_planDeleteEtage(${et.Id},'${_escPlan(et.Nom)}')">🗑️</button></span>`:''}</div>`;
  }).join('');
}

// ── Calques catégorisés ──
function _renderCalquePanel() {
  const ed = canEdit();
  return `<div class="plan-side-header"><span>Calques</span>${ed && _p.etage ? '<button class="btn btn-primary btn-sm" onclick="_planAddCalque()">+</button>' : ''}</div><div class="plan-calque-list" id="planCalqueList">${_renderCalqueList()}</div>`;
}
function _planGetCalques() { const set = new Set(['Plan']); _p.elements.forEach(el => set.add(el.Calque || 'Plan')); return [...set].sort(); }
function _planCalqueOptions() { return _planGetCalques().map(c => `<option value="${_escPlan(c)}" ${c === _p.activeCalque ? 'selected' : ''}>${_escPlan(c)}</option>`).join(''); }

function _renderCalqueList() {
  if (!_p.etage) return '<div class="plan-empty-msg" style="padding:14px">—</div>';
  const ed = canEdit(), calques = _planGetCalques(), byCategory = {};
  calques.forEach(c => { const cat = _getCalqueCategory(c); if (!byCategory[cat]) byCategory[cat] = []; byCategory[cat].push(c); });
  let html = '';
  Object.entries(CALQUE_CATEGORIES).forEach(([catName, catInfo]) => {
    const items = byCategory[catName]; if (!items || !items.length) return;
    html += `<div class="plan-list-section"><div class="plan-list-header"><span>${catInfo.icon}</span> ${catName} <span class="plan-list-badge">${items.length}</span></div>`;
    items.forEach(c => {
      const count = _p.elements.filter(e => (e.Calque || 'Plan') === c).length;
      const hidden = !!_p.hiddenCalques[c], active = c === _p.activeCalque;
      html += `<div class="plan-calque-item ${active?'active':''} ${hidden?'hidden':''}" onclick="_planSelectCalque('${_escPlan(c)}')">
        <button class="plan-calque-eye" onclick="event.stopPropagation();_planToggleCalque('${_escPlan(c)}')">${hidden?'👁️‍🗨️':'👁️'}</button>
        <span class="plan-calque-nom">${_escPlan(c)}</span><span class="plan-calque-count">${count}</span>
        ${ed&&c!=='Plan'?`<span class="plan-calque-actions"><button class="plan-icon-btn" onclick="event.stopPropagation();_planRenameCalque('${_escPlan(c)}')">✏️</button><button class="plan-icon-btn plan-icon-danger" onclick="event.stopPropagation();_planDeleteCalque('${_escPlan(c)}')">🗑️</button></span>`:''}
      </div>`;
    });
    html += '</div>';
  });
  return html;
}
function _planSelectCalque(name) { _p.activeCalque = name; const s = document.getElementById('planCalqueActif'); if (s) s.value = name; const l = document.getElementById('planCalqueList'); if (l) l.innerHTML = _renderCalqueList(); }
function _planToggleCalque(name) { _p.hiddenCalques[name] = !_p.hiddenCalques[name]; _pInitMap(); const l = document.getElementById('planCalqueList'); if (l) l.innerHTML = _renderCalqueList(); }
function _planAddCalque() { if (!_p.etage) { toast('Sélectionnez un étage','error'); return; } const html = '<div class="form-row"><label>Nom du calque<input id="pCN" class="input" autofocus></label></div>'; openModal('Nouveau calque', html, () => { const name = document.getElementById('pCN').value.trim(); if (!name) { toast('Nom requis','error'); return; } if (_planGetCalques().includes(name)) { toast('Existe déjà','error'); return; } _p.activeCalque = name; closeModal(); toast('Calque créé','success'); _pRefreshCalqueLists(); }); }
async function _planRenameCalque(oldName) { if (!_p.etage) return; const html = '<div class="form-row"><label>Nouveau nom<input id="pCN" class="input" value="'+_escPlan(oldName)+'" autofocus></label></div>'; openModal('Renommer', html, async () => { const n = document.getElementById('pCN').value.trim(); if (!n || n === oldName) { closeModal(); return; } await PlansApi.renameCalque(_p.etage.Id, oldName, n); _p.elements.forEach(el => { if ((el.Calque||'Plan')===oldName) el.Calque=n; }); if (_p.activeCalque===oldName) _p.activeCalque=n; closeModal(); toast('Renommé','success'); _renderMain(document.getElementById('mainContent')); }); }
async function _planDeleteCalque(name) { if (!_p.etage) return; const count = _p.elements.filter(e=>(e.Calque||'Plan')===name).length; if (!confirm('Supprimer « '+name+' » et ses '+count+' élément(s) ?')) return; await PlansApi.deleteCalque(_p.etage.Id, name); _p.elements = _p.elements.filter(e=>(e.Calque||'Plan')!==name); if (_p.activeCalque===name) _p.activeCalque='Plan'; toast('Supprimé','success'); _renderMain(document.getElementById('mainContent')); }

// ── Recherche & Filtres (fusionné) ──
function _renderRecherchePanel() {
  const calques = _planGetCalques(), tc = {};
  _p.elements.forEach(el => { tc[el.TypeElement] = (tc[el.TypeElement]||0)+1; });
  const filtered = _getFilteredElements();
  const groups = { point: '📍 Points', trait: '📏 Traits', zone: '🔷 Zones', texte: '🔤 Textes' };
  const hasActiveFilter = _p.filters.search || _p.filters.type || _p.filters.calque || _p.filters.sousType || _p.filters.category || _p.filters.pointCategorie;
  // Récupérer les catégories de points utilisées (config + observées sur les éléments)
  const usedPtCats = new Set();
  _p.elements.forEach(el => { if (el.TypeElement === 'point' && el.SousType) usedPtCats.add(el.SousType); });
  const ptCats = [..._p.pointCategories.map(c => c.Valeur).filter(Boolean), ...[...usedPtCats].filter(c => !_p.pointCategories.find(pc => pc.Valeur === c))];
  let html = '<div style="padding:10px">';
  // Recherche en haut, gros et visible
  html += '<div class="plan-filter-group"><label class="plan-filter-label">🔍 Rechercher</label><input class="plan-filter-input" placeholder="Nom, description..." value="'+_escPlan(_p.filters.search)+'" oninput="_p.filters.search=this.value;_pApplyFilters()"></div>';
  // Filtres dans un détail rétractable
  html += '<details '+(hasActiveFilter?'open':'')+' style="margin-bottom:8px">';
  html += '<summary style="font-size:11px;font-weight:600;color:var(--navy);cursor:pointer;padding:4px 0;user-select:none">⚙️ Filtres avancés '+(hasActiveFilter?'<span class="plan-list-badge" style="background:var(--blue);color:#fff">!</span>':'')+'</summary>';
  html += '<div style="padding:6px 0">';
  html += '<div class="plan-filter-group"><label class="plan-filter-label">Type</label><div class="plan-filter-chips">'+['point','trait','zone','texte'].map(t=>`<span class="plan-filter-chip ${_p.filters.type===t?'active':''}" onclick="_p.filters.type=_p.filters.type==='${t}'?'':'${t}';_pApplyFilters()">${t} ${tc[t]?'('+tc[t]+')':''}</span>`).join('')+'</div></div>';
  html += '<div class="plan-filter-group"><label class="plan-filter-label">Calque</label><select class="plan-filter-select" onchange="_p.filters.calque=this.value;_pApplyFilters()"><option value="">Tous</option>'+calques.map(c=>`<option value="${_escPlan(c)}" ${_p.filters.calque===c?'selected':''}>${_escPlan(c)}</option>`).join('')+'</select></div>';
  // Filtre Catégorie de point (si au moins une définie)
  if (ptCats.length > 0) {
    html += '<div class="plan-filter-group"><label class="plan-filter-label">📍 Catégorie de point</label><select class="plan-filter-select" onchange="_p.filters.pointCategorie=this.value;_pApplyFilters()"><option value="">Toutes</option>'+ptCats.map(c => '<option value="'+_escPlan(c)+'" '+(_p.filters.pointCategorie===c?'selected':'')+'>'+_escPlan(c)+'</option>').join('')+'</select></div>';
  }
  html += '<div class="plan-filter-group"><label class="plan-filter-label">Catégorie de calque</label><div class="plan-filter-chips">'+Object.entries(CALQUE_CATEGORIES).map(([k,v])=>`<span class="plan-filter-chip ${_p.filters.category===k?'active':''}" onclick="_p.filters.category=_p.filters.category==='${k}'?'':'${k}';_pApplyFilters()">${v.icon} ${k}</span>`).join('')+'</div></div>';
  if (hasActiveFilter) html += '<button class="btn btn-secondary btn-sm" onclick="_pClearFilters()" style="width:100%;margin-top:4px">🔄 Réinitialiser</button>';
  html += '</div></details>';
  // Compteur
  html += '<div style="font-size:11px;color:var(--gray-text);text-align:center;margin-bottom:6px;padding:4px;background:var(--gray-bg);border-radius:6px">'+filtered.length+' / '+_p.elements.length+' éléments</div>';
  html += '</div>';
  // Pour les points : permettre un sous-groupement par catégorie
  Object.entries(groups).forEach(([type, label]) => {
    const items = filtered.filter(e => e.TypeElement === type);
    if (!items.length && hasActiveFilter) return;
    html += '<div class="plan-list-section"><div class="plan-list-header" onclick="this.nextElementSibling.style.display=this.nextElementSibling.style.display===\'none\'?\'\':\'none\'">'+label+' <span class="plan-list-badge">'+items.length+'</span></div><div>';
    if (!items.length) {
      html += '<div class="plan-empty-msg" style="padding:6px;font-size:11px">Aucun</div>';
    } else if (type === 'point') {
      // Grouper les points par catégorie
      const byCat = {};
      items.forEach(el => { const c = el.SousType || '— Sans catégorie'; if (!byCat[c]) byCat[c] = []; byCat[c].push(el); });
      const sortedCats = Object.keys(byCat).sort((a, b) => (a === '— Sans catégorie' ? 1 : b === '— Sans catégorie' ? -1 : a.localeCompare(b)));
      sortedCats.forEach(cat => {
        if (sortedCats.length > 1) html += '<div style="padding:4px 12px;font-size:10px;font-weight:700;color:var(--gray-text);text-transform:uppercase;background:var(--gray-bg)">'+_escPlan(cat)+' <span class="plan-list-badge">'+byCat[cat].length+'</span></div>';
        byCat[cat].forEach(el => { html += '<div class="plan-list-item '+(_p.selectedEl?.Id===el.Id?'active':'')+'" onclick="_pSelectEl(_p.elements.find(e=>e.Id==='+el.Id+'))"><span class="plan-list-dot" style="background:'+(el.Couleur||'#2b7be6')+'"></span><span class="plan-list-label">'+_escPlan(el.Nom||'Sans nom')+'</span><span class="plan-list-badge">'+_escPlan(el.Calque||'Plan')+'</span></div>'; });
      });
    } else {
      items.forEach(el => { html += '<div class="plan-list-item '+(_p.selectedEl?.Id===el.Id?'active':'')+'" onclick="_pSelectEl(_p.elements.find(e=>e.Id==='+el.Id+'))"><span class="plan-list-dot" style="background:'+(el.Couleur||'#2b7be6')+'"></span><span class="plan-list-label">'+_escPlan(el.Nom||'Sans nom')+'</span><span class="plan-list-badge">'+_escPlan(el.Calque||'Plan')+'</span></div>'; });
    }
    html += '</div></div>';
  });
  return html;
}
function _getFilteredElements() {
  return _p.elements.filter(el => {
    const calque = el.Calque || 'Plan';
    if (_p.hiddenCalques[calque]) return false;
    if (_p.filters.search) { const s = _p.filters.search.toLowerCase(); if (!(el.Nom||'').toLowerCase().includes(s) && !(el.Description||'').toLowerCase().includes(s) && !(el.SousType||'').toLowerCase().includes(s)) return false; }
    if (_p.filters.type && el.TypeElement !== _p.filters.type) return false;
    if (_p.filters.calque && calque !== _p.filters.calque) return false;
    if (_p.filters.sousType && (el.SousType||'') !== _p.filters.sousType) return false;
    if (_p.filters.category && _getCalqueCategory(calque) !== _p.filters.category) return false;
    if (_p.filters.pointCategorie && (el.TypeElement !== 'point' || (el.SousType||'') !== _p.filters.pointCategorie)) return false;
    return true;
  });
}
function _pApplyFilters() { _pInitMap(); const el = document.getElementById('planLeftContent'); if (el) el.innerHTML = _renderLeftContent(); }
function _pClearFilters() { _p.filters = { search:'', type:'', calque:'', sousType:'', category:'', pointCategorie:'' }; _pApplyFilters(); }

// ══════════════════ SÉLECTION BAT/ÉTAGE ══════════════════
async function _planSelectBat(id) { _p.batiment = _p.batiments.find(b=>b.Id==id)||null; _p.etage=null; _p.elements=[]; _p.selectedEl=null; if (_p.batiment) { _p.etages = await PlansApi.getEtages(_p.batiment.Id); if (_p.etages.length) { _p.etage = _p.etages[0]; _p.elements = await PlansApi.getElements(_p.etage.Id); } } else { _p.etages=[]; } _renderMain(document.getElementById('mainContent')); }
async function _planSelectEtage(id) { _p.etage = _p.etages.find(e=>e.Id==id)||null; _p.selectedEl=null; _p.elements = _p.etage ? await PlansApi.getElements(_p.etage.Id) : []; _renderMain(document.getElementById('mainContent')); }

// ══════════════════ CARTE LEAFLET ══════════════════
function _pInitMap() {
  const mc = document.getElementById('planMapContainer');
  if (!_p.etage) { if (mc) mc.innerHTML = '<div class="plan-empty-map"><div style="font-size:42px;margin-bottom:8px;opacity:.4">🗺️</div>Sélectionnez un bâtiment et un étage</div>'; return; }
  const et = _p.etage, w = et.FondLargeur||1000, h = et.FondHauteur||700;
  _pLoadRefMeterFromEtage(); // restaurer le mètre de référence sauvegardé pour cet étage
  if (_p.map) { _p.map.remove(); _p.map = null; } _p.layers = {}; if (mc) mc.innerHTML = '';
  const bounds = [[0,0],[h,w]];
  _p.map = L.map('planMapContainer', { crs: L.CRS.Simple, minZoom:-3, maxZoom:5, zoomSnap:0.25, attributionControl:false });
  _p.map.fitBounds(bounds);
  if (et.FondImage) {
    const url = API_BASE + '?action=plans_fond_image&etage_id=' + et.Id;
    if (String(et.FondImage).toLowerCase().endsWith('.pdf')) { _pDrawGrid(w,h); _pRenderPdfFond(url,bounds); }
    else { const ov = L.imageOverlay(url, bounds, {errorOverlayUrl:''}).addTo(_p.map); ov.on('error', ()=>{ toast('Image introuvable','error'); _pDrawGrid(w,h); }); }
  } else { _pDrawGrid(w,h); }
  _getFilteredElements().forEach(el => _pAddEl(el));
  // Si des points sont rattachés à des personnes mais que la liste des
  // utilisateurs n'est pas encore chargée, on la charge puis on redessine ces points
  // en pastille avatar (ils s'affichent d'abord avec leur symbole, puis se mettent à jour).
  if (!_p._annuaireUsers && _getFilteredElements().some(el => el.TypeElement === 'point' && el.RefUtilisateurId)) {
    _planEnsureUsers().then(() => {
      _getFilteredElements().forEach(el => {
        if (el.TypeElement === 'point' && el.RefUtilisateurId) {
          if (_p.layers[el.Id]) { _p.map.removeLayer(_p.layers[el.Id]); delete _p.layers[el.Id]; }
          _pAddEl(el);
        }
      });
    });
  }
  _p.map.on('click', _pMapClick); _p.map.on('mousemove', _pMapMove);
  _pUpdateScaleBar(); _p.map.on('zoomend', _pUpdateScaleBar);
  _pRenderRefMeter(); _pUpdateRefToggleBtn(); // conserver le mètre de référence tracé à travers les redraws
  // Synchroniser le sélecteur « Taille marqueurs » avec la valeur enregistrée de l'étage.
  const _tmSel=document.getElementById('planTailleMarqueurs');
  if(_tmSel){const _f=(parseFloat(_p.etage.TailleMarqueurs)>0)?parseFloat(_p.etage.TailleMarqueurs):1;_tmSel.value=String(_f);}
}

async function _pRenderPdfFond(url, bounds) {
  try {
    if (!window.pdfjsLib) { await new Promise((r,j)=>{const s=document.createElement('script');s.src='https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js';s.onload=r;s.onerror=()=>j(new Error('PDF.js'));document.head.appendChild(s);}); window.pdfjsLib.GlobalWorkerOptions.workerSrc='https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js'; }
    const pdf = await window.pdfjsLib.getDocument({url,withCredentials:true}).promise, page = await pdf.getPage(1);
    const v0 = page.getViewport({scale:1}), scale = Math.min(2400,Math.max(1200,v0.width*2))/v0.width, vp = page.getViewport({scale});
    const cv = document.createElement('canvas'); cv.width=vp.width; cv.height=vp.height;
    await page.render({canvasContext:cv.getContext('2d'),viewport:vp}).promise;
    L.imageOverlay(cv.toDataURL('image/png'), bounds).addTo(_p.map);
  } catch(e) { toast('PDF: '+e.message,'error'); _pDrawGrid(_p.etage.FondLargeur||1000,_p.etage.FondHauteur||700); }
}
function _pDrawGrid(w,h) { const g=L.layerGroup().addTo(_p.map),step=50; for(let x=0;x<=w;x+=step)L.polyline([[0,x],[h,x]],{color:'#e5e7eb',weight:.5,interactive:false}).addTo(g); for(let y=0;y<=h;y+=step)L.polyline([[y,0],[y,w]],{color:'#e5e7eb',weight:.5,interactive:false}).addTo(g); }
function _pUpdateScaleBar() { const bar=document.getElementById('planScaleBar'); if(!bar||!_p.map||!_p.etage)return; const ech=parseFloat(_p.etage.Echelle)||0.05,zoom=_p.map.getZoom(),scale=Math.pow(2,zoom),pxSize=100,meters=(pxSize/scale)*ech,label=meters>=1?Math.round(meters)+' m':Math.round(meters*100)+' cm'; bar.innerHTML=`<div class="plan-scale-line" style="width:${pxSize}px"></div><span>${label}</span>`; }

// ══════════════════ MÈTRE DE RÉFÉRENCE (persistant, effaçable, masquable) ══════════════════
// Dessine le tracé mémorisé dans _p.refDraw. Appelé après chaque (re)construction de la carte.
function _pRenderRefMeter() {
  if(_p.refDrawLayer){_p.map.removeLayer(_p.refDrawLayer);_p.refDrawLayer=null;}
  if(!_p.refDraw||!_p.refDraw.coords||_p.refDraw.coords.length<2||_p.refDraw.hidden||!_p.map)return;
  const[a,b]=_p.refDraw.coords, g=L.layerGroup().addTo(_p.map);
  L.polyline([a,b],{color:'#e74c3c',weight:2,dashArray:'6,4'}).addTo(g);
  const mid=[(a[0]+b[0])/2,(a[1]+b[1])/2];
  L.marker(mid,{icon:L.divIcon({html:'<div class="plan-measure-label">'+_p.refDraw.dist+' m (réf)</div>',className:'',iconAnchor:[50,10]})}).addTo(g);
  _p.refDrawLayer=g;
}
// Restaure le mètre de référence sauvegardé sur l'étage (colonne RefMetre, JSON).
function _pLoadRefMeterFromEtage() {
  _p.refDraw = null;
  const raw = _p.etage && _p.etage.RefMetre;
  if (!raw) return;
  try {
    const o = (typeof raw === 'string') ? JSON.parse(raw) : raw;
    if (o && Array.isArray(o.coords) && o.coords.length >= 2) {
      _p.refDraw = { coords: o.coords, dist: o.dist, hidden: !!o.hidden };
    }
  } catch (_e) { /* JSON invalide : on ignore */ }
}
// Sauvegarde l'état courant du mètre de référence sur l'étage (création/masquage/suppression).
function _pPersistRefMeter() {
  if (!_p.etage) return;
  const rmJson = _p.refDraw ? JSON.stringify(_p.refDraw) : '';
  _p.etage.RefMetre = rmJson || null; // garder la copie mémoire cohérente
  const batId = _p.batiment ? _p.batiment.Id : _p.etage.BatimentId;
  PlansApi.updateEtage(_p.etage.Id, {
    batimentId: batId, nom: _p.etage.Nom, niveau: _p.etage.Niveau,
    fondLargeur: _p.etage.FondLargeur || 1000, fondHauteur: _p.etage.FondHauteur || 700,
    echelle: _p.etage.Echelle || 0.05, refMetre: rmJson
  }).catch(err => toast(err.message || 'Erreur de sauvegarde', 'error'));
}
// Afficher / cacher le mètre de référence (état masqué persisté)
function _pToggleRefMeter() {
  if(!_p.refDraw){toast('Aucun mètre de référence tracé','error');return;}
  _p.refDraw.hidden=!_p.refDraw.hidden; _pRenderRefMeter(); _pUpdateRefToggleBtn(); _pPersistRefMeter();
  toast(_p.refDraw.hidden?'Mètre de référence caché':'Mètre de référence affiché','success');
}
// Supprimer définitivement le mètre de référence (utilisé par la gomme, suppression persistée)
function _pRemoveRefMeter() {
  _p.refDraw=null; if(_p.refDrawLayer){_p.map.removeLayer(_p.refDrawLayer);_p.refDrawLayer=null;} _pUpdateRefToggleBtn(); _pPersistRefMeter();
}
// Reflète l'état (présent / caché) sur le bouton de la barre d'outils
function _pUpdateRefToggleBtn() {
  const b=document.getElementById('ptRefToggle'); if(!b)return;
  const hidden=!!(_p.refDraw&&_p.refDraw.hidden);
  b.classList.toggle('active',hidden);
  b.title=hidden?'Afficher le mètre de référence':'Cacher le mètre de référence';
  b.style.opacity=_p.refDraw?'1':'0.45';
}

// ══════════════════ TAILLE DES MARQUEURS (par étage) ══════════════════
// Facteur appliqué à tous les points/personnes de l'étage (évite le fouillis
// quand il y a beaucoup d'éléments). Enregistré sur l'étage, puis re-rendu.
function _pSetTailleMarqueurs(val) {
  if(!_p.etage){toast('Sélectionnez un étage','error');return;}
  const f=parseFloat(val)>0?parseFloat(val):1;
  _p.etage.TailleMarqueurs=f;
  const batId=_p.batiment?_p.batiment.Id:_p.etage.BatimentId;
  PlansApi.updateEtage(_p.etage.Id,{batimentId:batId,nom:_p.etage.Nom,niveau:_p.etage.Niveau,fondLargeur:_p.etage.FondLargeur||1000,fondHauteur:_p.etage.FondHauteur||700,echelle:_p.etage.Echelle||0.05,tailleMarqueurs:f})
    .catch(err=>toast(err.message||'Erreur de sauvegarde','error'));
  _pInitMap(); // re-render des marqueurs à la nouvelle taille
}

// ══════════════════ OUTILS ══════════════════
function _pTool(mode) {
  _pCancel(); _p.mode = mode; _p.textMode=false; _p.eraserMode=false; _p.refMode=false;
  document.querySelectorAll('.plan-tbtn').forEach(b=>b.classList.remove('active'));
  if (mode==='point') document.getElementById('ptPoint')?.classList.add('active');
  else if (mode==='personne') document.getElementById('ptPersonne')?.classList.add('active');
  else if (mode==='trait') document.getElementById('ptTrait')?.classList.add('active');
  else if (mode==='zone') document.getElementById('ptZone')?.classList.add('active');
  else if (mode==='measure') document.getElementById('ptMeasure')?.classList.add('active');
  else document.querySelector('.plan-tbtn')?.classList.add('active');
  const sub=document.getElementById('planTraitSub'); if(sub)sub.style.display=mode==='trait'?'':'none';
  const ls=document.getElementById('planLineStyle'); if(ls)ls.style.display=mode==='trait'?'':'none';
  const sym=document.getElementById('planSymbol'); if(sym)sym.style.display=mode==='point'?'':'none';
  const to=document.getElementById('planTraitOpts'); if(to)to.style.display=mode==='trait'?'inline-flex':'none';
  const st=document.getElementById('planDrawStatus');
  if(st){if(mode==='point')st.textContent='Cliquez pour placer';else if(mode==='personne')st.textContent='Cliquez pour placer une personne (bureau)';else if(mode==='trait')st.textContent='Cliquez pour tracer — Terminer pour valider';else if(mode==='zone')st.textContent='Cliquez pour dessiner — Terminer pour valider';else if(mode==='measure')st.textContent='Cliquez 2 points pour mesurer (temporaire)';else st.textContent='';}
  const mc=document.getElementById('planMapContainer'); if(mc)mc.style.cursor=mode?'crosshair':'';
}
function _pToolText() { _pCancel(); _p.mode=null; _p.textMode=true; _p.eraserMode=false; _p.refMode=false; document.querySelectorAll('.plan-tbtn').forEach(b=>b.classList.remove('active')); document.getElementById('ptText')?.classList.add('active'); ['planTraitSub','planLineStyle','planSymbol','planTraitOpts'].forEach(id=>{const e=document.getElementById(id);if(e)e.style.display='none';}); const st=document.getElementById('planDrawStatus');if(st)st.textContent='Cliquez pour placer un texte'; const mc=document.getElementById('planMapContainer');if(mc)mc.style.cursor='text'; }
function _pToolEraser() { _pCancel(); _p.mode=null; _p.textMode=false; _p.eraserMode=true; _p.refMode=false; document.querySelectorAll('.plan-tbtn').forEach(b=>b.classList.remove('active')); document.getElementById('ptEraser')?.classList.add('active'); ['planTraitSub','planLineStyle','planSymbol','planTraitOpts'].forEach(id=>{const e=document.getElementById(id);if(e)e.style.display='none';}); const st=document.getElementById('planDrawStatus');if(st)st.textContent='Cliquez sur un élément pour le supprimer'; const mc=document.getElementById('planMapContainer');if(mc)mc.style.cursor='crosshair'; }
function _pToolRef() { _pCancel(); _p.mode=null; _p.textMode=false; _p.eraserMode=false; _p.refMode=true; _p.refCoords=[]; document.querySelectorAll('.plan-tbtn').forEach(b=>b.classList.remove('active')); document.getElementById('ptRef')?.classList.add('active'); ['planTraitSub','planLineStyle','planSymbol','planTraitOpts'].forEach(id=>{const e=document.getElementById(id);if(e)e.style.display='none';}); const dist=prompt('Distance réelle en mètres :',_p.refRealDist); if(!dist||isNaN(parseFloat(dist))){_pTool(null);return;} _p.refRealDist=parseFloat(dist); const st=document.getElementById('planDrawStatus');if(st)st.textContent='Cliquez 2 points = '+_p.refRealDist+'m'; const mc=document.getElementById('planMapContainer');if(mc)mc.style.cursor='crosshair'; const ri=document.getElementById('planRefInfo');if(ri){ri.style.display='';ri.textContent='📏 Cliquez le 1er point ('+_p.refRealDist+'m)';} }
function _pToggleLockAngle() { if(!_p.lockAngle){const v=prompt('Angle (degrés):',_p.lockAngleVal);if(!v)return;_p.lockAngleVal=parseInt(v);_p.lockAngle=true;}else _p.lockAngle=false; document.getElementById('ptLockAngle')?.classList.toggle('active',_p.lockAngle); }
function _pToggleLockDist() { if(!_p.lockDistance){const v=prompt('Distance (px):',_p.lockDistVal);if(!v)return;_p.lockDistVal=parseInt(v);_p.lockDistance=true;}else _p.lockDistance=false; document.getElementById('ptLockDist')?.classList.toggle('active',_p.lockDistance); }
function _pCloseLoop() { if(_p.drawCoords.length<3){toast('3+ points','error');return;} _p.drawCoords.push([..._p.drawCoords[0]]); _pFinish(); }

// ══════════════════ CLICK CARTE ══════════════════
function _pMapClick(e) {
  const lat=Math.round(e.latlng.lat*10)/10, lng=Math.round(e.latlng.lng*10)/10;
  // Ref mètre
  if (_p.refMode) {
    _p.refCoords.push([lat,lng]);
    if (_p.refCoords.length===1) { _p.refLayer=L.circleMarker([lat,lng],{radius:5,color:'#e74c3c',fillOpacity:1}).addTo(_p.map); const ri=document.getElementById('planRefInfo');if(ri)ri.textContent='📏 Cliquez le 2ème point'; }
    if (_p.refCoords.length>=2) {
      if(_p.refLayer)_p.map.removeLayer(_p.refLayer);
      const[a,b]=_p.refCoords, distPx=Math.sqrt((b[1]-a[1])**2+(b[0]-a[0])**2);
      if(distPx>0){const newEch=_p.refRealDist/distPx;_p.etage.Echelle=newEch;
        _p.refDraw={coords:[[a[0],a[1]],[b[0],b[1]]],dist:_p.refRealDist,hidden:false};
        const rmJson=JSON.stringify(_p.refDraw);_p.etage.RefMetre=rmJson;
        PlansApi.updateEtage(_p.etage.Id,{batimentId:_p.batiment.Id,nom:_p.etage.Nom,niveau:_p.etage.Niveau,fondLargeur:_p.etage.FondLargeur||1000,fondHauteur:_p.etage.FondHauteur||700,echelle:newEch,refMetre:rmJson}).then(()=>{toast('Échelle: 1m='+(1/newEch).toFixed(1)+'px','success');_pUpdateScaleBar();}).catch(err=>toast(err.message,'error'));
        _pRenderRefMeter();_pUpdateRefToggleBtn();}
      _p.refMode=false;_p.refCoords=[];_pTool(null);const ri=document.getElementById('planRefInfo');if(ri)ri.style.display='none';
    } return;
  }
  // Gomme — supprime seulement l'élément, reste en mode gomme
  if (_p.eraserMode) {
    let closest=null,closestDist=Infinity;
    _p.elements.forEach(el=>{
      let coords;try{coords=typeof el.Coords==='string'?JSON.parse(el.Coords):el.Coords;}catch(_e){return;}
      if(!coords?.length)return;
      // Distance aux sommets
      coords.forEach(c=>{const d=Math.sqrt((c[0]-lat)**2+(c[1]-lng)**2);if(d<closestDist){closestDist=d;closest=el;}});
      // Distance aux segments (pour traits et zones)
      if(coords.length>=2){
        for(let i=0;i<coords.length-1;i++){
          const ax=coords[i][1],ay=coords[i][0],bx=coords[i+1][1],by=coords[i+1][0],px=lng,py=lat;
          const abx=bx-ax,aby=by-ay,apx=px-ax,apy=py-ay;
          const t=Math.max(0,Math.min(1,(apx*abx+apy*aby)/(abx*abx+aby*aby||1)));
          const cx2=ax+t*abx,cy2=ay+t*aby;
          const d=Math.sqrt((py-cy2)**2+(px-cx2)**2);
          if(d<closestDist){closestDist=d;closest=el;}
        }
      }
    });
    if(closest&&closestDist<50){_planDeleteElement(closest.Id);return;}
    // Gomme du mètre de référence : il n'est pas dans _p.elements, on le traite à part.
    if (_p.refDraw && _p.refDraw.coords && _p.refDraw.coords.length>=2 && !_p.refDraw.hidden) {
      const [ra,rb]=_p.refDraw.coords, ax=ra[1],ay=ra[0],bx=rb[1],by=rb[0],px=lng,py=lat;
      const abx=bx-ax,aby=by-ay,apx=px-ax,apy=py-ay;
      const t=Math.max(0,Math.min(1,(apx*abx+apy*aby)/(abx*abx+aby*aby||1)));
      const cx2=ax+t*abx,cy2=ay+t*aby, d=Math.sqrt((py-cy2)**2+(px-cx2)**2);
      if(d<50){_pRemoveRefMeter();toast('Mètre de référence supprimé','success');}
    }
    return;
  }
  // Texte
  if (_p.textMode) { _pPlaceText(lat,lng); return; }
  if (!_p.mode) return;
  // Mesure temporaire
  if (_p.mode==='measure') {
    _p.measureCoords.push([lat,lng]);
    if(_p.measureCoords.length===1){_p.measureLayer=L.circleMarker([lat,lng],{radius:4,color:'#e74c3c',fillOpacity:1}).addTo(_p.map);}
    if(_p.measureCoords.length>=2){
      if(_p.measureLayer)_p.map.removeLayer(_p.measureLayer);
      const[a,b]=_p.measureCoords,distPx=Math.sqrt((b[1]-a[1])**2+(b[0]-a[0])**2),ech=parseFloat(_p.etage?.Echelle)||0.05,distM=distPx*ech;
      _p.measureLayer=L.layerGroup().addTo(_p.map);L.polyline([a,b],{color:'#e74c3c',weight:2,dashArray:'6,4'}).addTo(_p.measureLayer);
      L.circleMarker(a,{radius:4,color:'#e74c3c',fillOpacity:1}).addTo(_p.measureLayer);L.circleMarker(b,{radius:4,color:'#e74c3c',fillOpacity:1}).addTo(_p.measureLayer);
      const mid=[(a[0]+b[0])/2,(a[1]+b[1])/2],label=distM>=1?distM.toFixed(2)+' m':(distM*100).toFixed(1)+' cm';
      L.marker(mid,{icon:L.divIcon({html:'<div class="plan-measure-label">'+label+'</div>',className:'',iconAnchor:[40,10]})}).addTo(_p.measureLayer);
      const st=document.getElementById('planDrawStatus');if(st)st.textContent='Distance: '+label+' (temporaire)';
      _p.measureCoords=[];
    } return;
  }
  // Point — modal direct
  if (_p.mode==='point') { _pSaveElement('point',[[lat,lng]]); return; }
  // Personne — crée un point support puis ouvre directement la fiche personne
  if (_p.mode==='personne') { _pAddPersonnePoint([[lat,lng]]); return; }
  // Trait/Zone avec verrouillages
  let fLat=lat,fLng=lng;
  if(_p.drawFromPoint&&_p.drawCoords.length===0){let cp=null,md=20;_p.elements.filter(el=>el.TypeElement==='point').forEach(el=>{try{const c=typeof el.Coords==='string'?JSON.parse(el.Coords):el.Coords;if(c?.[0]){const d=Math.sqrt((c[0][0]-lat)**2+(c[0][1]-lng)**2);if(d<md){md=d;cp=c[0];}}}catch(_e){}});if(cp){fLat=cp[0];fLng=cp[1];}}
  if(_p.lockAngle&&_p.drawCoords.length>0){const last=_p.drawCoords[_p.drawCoords.length-1],angle=Math.atan2(fLat-last[0],fLng-last[1]),snapAngle=Math.round(angle/(_p.lockAngleVal*Math.PI/180))*(_p.lockAngleVal*Math.PI/180),dist=_p.lockDistance?_p.lockDistVal:Math.sqrt((fLat-last[0])**2+(fLng-last[1])**2);fLat=last[0]+Math.sin(snapAngle)*dist;fLng=last[1]+Math.cos(snapAngle)*dist;}
  else if(_p.lockDistance&&_p.drawCoords.length>0){const last=_p.drawCoords[_p.drawCoords.length-1],angle=Math.atan2(fLat-last[0],fLng-last[1]);fLat=last[0]+Math.sin(angle)*_p.lockDistVal;fLng=last[1]+Math.cos(angle)*_p.lockDistVal;}
  fLat=Math.round(fLat*10)/10;fLng=Math.round(fLng*10)/10;
  _p.drawCoords.push([fLat,fLng]); _pUpdatePreview();
  if(_p.drawCoords.length>=2){const f=document.getElementById('planFinish');if(f)f.style.display='';}
  const ca=document.getElementById('planCancel');if(ca)ca.style.display='';
}
function _pMapMove(e) { if(_p.mode||_p.textMode||_p.eraserMode||_p.refMode)return;if(!_p.etage)return;const ech=parseFloat(_p.etage?.Echelle)||0.05;const st=document.getElementById('planDrawStatus');if(st)st.textContent=`x:${(e.latlng.lng*ech).toFixed(1)}m y:${(e.latlng.lat*ech).toFixed(1)}m`; }
function _pUpdatePreview() {
  if(_p.drawLayer){_p.map.removeLayer(_p.drawLayer);_p.drawLayer=null;} if(_p.drawCoords.length<1)return;
  const c=_p.color;
  if(_p.mode==='trait'){const sub=TRAIT_SUBTYPES[_p.sousType]||TRAIT_SUBTYPES[''],ls=LINE_STYLES[_p.lineStyle]||LINE_STYLES[''];_p.drawLayer=L.polyline(_p.drawCoords,{color:sub.color||c,weight:sub.weight,dashArray:ls.dash||sub.dash||'8,4'}).addTo(_p.map);}
  else if(_p.mode==='zone'){if(_p.drawCoords.length>=3)_p.drawLayer=L.polygon(_p.drawCoords,{color:c,fillColor:c,fillOpacity:.15,dashArray:'8,4'}).addTo(_p.map);else _p.drawLayer=L.polyline(_p.drawCoords,{color:c,weight:2,dashArray:'8,4'}).addTo(_p.map);}
}
// Terminer = sauvegarde mais reste sur le même outil (trait/zone)
async function _pFinish() {
  if(_p.drawCoords.length<2){toast('2+ points','error');return;}
  const type=_p.mode,coords=[..._p.drawCoords];
  _p.drawCoords=[];if(_p.drawLayer){_p.map.removeLayer(_p.drawLayer);_p.drawLayer=null;}
  const f=document.getElementById('planFinish');if(f)f.style.display='none';const c=document.getElementById('planCancel');if(c)c.style.display='none';
  await _pSaveElement(type,coords);
  // Reste sur le même outil pour retracer ailleurs
  const st=document.getElementById('planDrawStatus');
  if(st&&(type==='trait'||type==='zone'))st.textContent=type==='trait'?'Cliquez pour tracer — Terminer pour valider':'Cliquez pour dessiner — Terminer pour valider';
}
function _pCancel() { _p.drawCoords=[];if(_p.drawLayer){_p.map.removeLayer(_p.drawLayer);_p.drawLayer=null;}if(_p.measureLayer){_p.map.removeLayer(_p.measureLayer);_p.measureLayer=null;}_p.measureCoords=[];const f=document.getElementById('planFinish');if(f)f.style.display='none';const c=document.getElementById('planCancel');if(c)c.style.display='none'; }
function _pPlaceText(lat,lng) {
  const html='<div style="display:flex;flex-direction:column;gap:16px"><div><div class="section-label">Texte</div><div class="form-grid"><div class="form-group"><label class="form-label">Contenu</label><input class="form-control" id="pTT" value="'+_escPlan(_p.textValue)+'" autofocus></div><div class="form-group"><label class="form-label">Taille</label><input class="form-control" id="pTS" type="number" value="'+_p.textSize+'" min="8" max="48"></div><div class="form-group"><label class="form-label">Couleur</label><input type="color" id="pTC" value="'+_p.color+'" style="height:38px;width:100%;padding:2px;border-radius:8px;border:1px solid var(--gray-border)"></div><div class="form-group"><label class="form-label">Calque</label><select class="form-control" id="pTL">'+_planGetCalques().map(c=>'<option value="'+_escPlan(c)+'" '+(c===_p.activeCalque?'selected':'')+'>'+_escPlan(c)+'</option>').join('')+'</select></div></div><label style="display:flex;align-items:center;gap:8px;margin-top:12px;cursor:pointer;font-size:13px"><input type="checkbox" id="pTA"> Afficher ce texte dans l\'annuaire (demandeurs)</label></div></div>';
  openModal('Ajouter un texte',html,async()=>{const text=document.getElementById('pTT').value.trim();if(!text){toast('Texte requis','error');return;}_p.textValue=text;_p.textSize=parseInt(document.getElementById('pTS').value)||14;const aff=document.getElementById('pTA').checked?1:0;const data={etageId:_p.etage.Id,typeElement:'texte',nom:text,couleur:document.getElementById('pTC').value,opacite:1,coords:JSON.stringify([[lat,lng]]),icone:'',sousType:_p.textSize.toString(),calque:document.getElementById('pTL').value,description:'',afficherAnnuaire:aff};const res=await PlansApi.addElement(data);_p.elements.push({Id:res.id,Nom:text,Couleur:data.couleur,Opacite:1,Coords:data.coords,TypeElement:'texte',Icone:'',SousType:data.sousType,Calque:data.calque,Description:'',AfficherAnnuaire:aff});_pInitMap();closeModal();toast('Texte ajouté','success');_pRefreshCalqueLists();});
}

// ══════════════════ ÉLÉMENTS SUR CARTE ══════════════════
function _pAddEl(el) {
  const calque=el.Calque||'Plan'; if(_p.hiddenCalques[calque])return;
  let coords; try{coords=typeof el.Coords==='string'?JSON.parse(el.Coords):el.Coords;}catch(_e){return;}
  if(!coords||!coords.length)return;
  const color=el.Couleur||'#2b7be6',opacity=parseFloat(el.Opacite)||0.4; let layer;
  if(el.TypeElement==='point'){
    const isFixed=_p.fixedVertices.has(el.Id+'-0');
    const mScale=(parseFloat(_p.etage&&_p.etage.TailleMarqueurs)>0)?parseFloat(_p.etage.TailleMarqueurs):1; // taille marqueurs de l'étage
    // Point « spécifique » : si une personne est rattachée (compte lié OU
    // saisie manuelle), on affiche une pastille photo/initiales au lieu du symbole.
    let personObj=(el.RefUtilisateurId&&window.PersonPicker)
      ?(_p._annuaireUsers||[]).find(u=>String(u.Id)===String(el.RefUtilisateurId)):null;
    if(!personObj&&el.PersonneData&&window.PersonPicker){try{personObj=JSON.parse(el.PersonneData);}catch(_e){}}
    let icon;
    if(personObj){
      const sz=Math.round(34*mScale);
      icon=L.divIcon({html:PersonPicker.pinHtml(personObj,{color,scale:mScale}),className:'',iconSize:[sz,sz+7],iconAnchor:[sz/2,sz+7]});
    } else if(el.EstPersonne==1){
      const sz=Math.round(32*mScale);
      const ph='<div style="position:relative;width:'+sz+'px;height:'+sz+'px"><div style="width:'+sz+'px;height:'+sz+'px;border-radius:50%;background:#9ca3af;box-shadow:0 0 0 2px #fff,0 1px 4px rgba(0,0,0,.3);display:flex;align-items:center;justify-content:center"><svg width="'+Math.round(18*mScale)+'" height="'+Math.round(18*mScale)+'" viewBox="0 0 16 16"><circle cx="8" cy="5" r="3" fill="#fff"/><path d="M2.5,14.5 a5.5,4.5 0 0,1 11,0 z" fill="#fff"/></svg></div><div style="position:absolute;left:50%;bottom:-6px;transform:translateX(-50%);width:0;height:0;border-left:5px solid transparent;border-right:5px solid transparent;border-top:7px solid #9ca3af"></div></div>';
      icon=L.divIcon({html:ph,className:'',iconSize:[sz,sz+7],iconAnchor:[sz/2,sz+7]});
    } else {
      const sym=el.Icone&&PLAN_SYMBOLS[el.Icone]?el.Icone:'circle';
      const sz=Math.round(24*mScale);
      icon=L.divIcon({html:'<div style="cursor:pointer;filter:drop-shadow(0 1px 2px rgba(0,0,0,.3))">'+PLAN_SYMBOLS[sym].svg(color,sz)+'</div>',className:'',iconSize:[sz,sz],iconAnchor:[sz/2,sz/2]});
    }
    layer=L.marker(coords[0],{icon,draggable:canEdit()&&!isFixed}).addTo(_p.map);
    if(canEdit()&&!isFixed){layer.on('dragend',async()=>{const pos=layer.getLatLng();const nc=[[Math.round(pos.lat*10)/10,Math.round(pos.lng*10)/10]];await PlansApi.updateElement(el.Id,{nom:el.Nom,couleur:el.Couleur,opacite:el.Opacite,coords:JSON.stringify(nc),icone:el.Icone,description:el.Description,sousType:el.SousType||'',calque:el.Calque||'Plan'});el.Coords=JSON.stringify(nc);});}
  } else if(el.TypeElement==='texte'){
    const fontSize=parseInt(el.SousType)||14;
    const icon=L.divIcon({html:'<div style="color:'+color+';font-size:'+fontSize+'px;font-weight:600;white-space:nowrap;cursor:pointer;text-shadow:1px 1px 2px rgba(0,0,0,.3)">'+_escPlan(el.Nom||'')+'</div>',className:'',iconAnchor:[0,fontSize/2]});
    layer=L.marker(coords[0],{icon,draggable:canEdit()}).addTo(_p.map);
    if(canEdit()){layer.on('dragend',async()=>{const pos=layer.getLatLng();const nc=[[Math.round(pos.lat*10)/10,Math.round(pos.lng*10)/10]];await PlansApi.updateElement(el.Id,{nom:el.Nom,couleur:el.Couleur,opacite:el.Opacite,coords:JSON.stringify(nc),icone:'',description:el.Description||'',sousType:el.SousType||'',calque:el.Calque||'Plan'});el.Coords=JSON.stringify(nc);});}
  } else if(el.TypeElement==='trait'){
    const sub=TRAIT_SUBTYPES[el.SousType]||TRAIT_SUBTYPES[''],ls=LINE_STYLES[el.LineStyle||'']||LINE_STYLES[''];
    layer=L.polyline(coords,{color:sub.color||color,weight:sub.weight||3,dashArray:ls.dash||sub.dash||null,opacity:Math.max(opacity,.5)}).addTo(_p.map);
  } else if(el.TypeElement==='zone'){
    layer=L.polygon(coords,{color,fillColor:color,fillOpacity:opacity,weight:2}).addTo(_p.map);
  }
  if(layer){
    if(layer.on)layer.on('click',e=>{L.DomEvent.stopPropagation(e);if(_p.eraserMode){_planDeleteElement(el.Id);return;}_pSelectEl(el);});
    // Tooltip au survol pour les points : nom + catégorie
    if (el.TypeElement === 'point' && (el.Nom || el.SousType)) {
      const ttHtml = (el.Nom ? '<strong>'+_escPlan(el.Nom)+'</strong>' : '') + (el.SousType ? (el.Nom ? '<br>' : '') + '<span style="color:#666;font-size:11px">'+_escPlan(el.SousType)+'</span>' : '');
      try { layer.bindTooltip(ttHtml, { direction: 'top', offset: [0, -10], className: 'plan-point-tooltip' }); } catch(_e) {}
    }
    _p.layers[el.Id]=layer;
  }
}

// ══════════════════ SÉLECTION + VERTEX ══════════════════
function _pSelectEl(el) { _p.selectedEl=el; _pShowProps(el); _pShowBottom(el); Object.entries(_p.layers).forEach(([id,ly])=>{if(ly.setStyle)ly.setStyle({weight:(id==el.Id?4:2)});}); _pClearVertexHandles(); if(canEdit()&&(el.TypeElement==='trait'||el.TypeElement==='zone'))_pShowVertexHandles(el); }
function _pClearVertexHandles() { if(_p.vertexHandles?.length){_p.vertexHandles.forEach(h=>_p.map.removeLayer(h));_p.vertexHandles=[];} }

function _pShowVertexHandles(el) {
  if(!canEdit())return; let coords; try{coords=typeof el.Coords==='string'?JSON.parse(el.Coords):el.Coords;}catch(_e){return;}
  if(!coords||coords.length<2)return; _p.vertexHandles=[];
  const updateLayer=()=>{if(_p.layers[el.Id])_p.map.removeLayer(_p.layers[el.Id]);const color=el.Couleur||'#2b7be6',opacity=parseFloat(el.Opacite)||0.4;let nl;if(el.TypeElement==='trait'){const sub=TRAIT_SUBTYPES[el.SousType]||TRAIT_SUBTYPES[''];nl=L.polyline(coords,{color:sub.color||color,weight:sub.weight||3,opacity:Math.max(opacity,.5)});}else{nl=L.polygon(coords,{color,fillColor:color,fillOpacity:opacity,weight:2});}nl.addTo(_p.map);nl.on('click',e=>{L.DomEvent.stopPropagation(e);_pSelectEl(el);});_p.layers[el.Id]=nl;};
  const persist=async()=>{el.Coords=JSON.stringify(coords);await PlansApi.updateElement(el.Id,{nom:el.Nom,couleur:el.Couleur,opacite:el.Opacite,coords:el.Coords,icone:el.Icone,description:el.Description,sousType:el.SousType||'',calque:el.Calque||'Plan'});};
  // Sommets principaux
  coords.forEach((pt,idx)=>{
    const isFixed=_p.fixedVertices.has(el.Id+'-'+idx);
    const handle=L.marker(pt,{draggable:!isFixed,icon:L.divIcon({html:'<div class="plan-vertex-handle '+(isFixed?'plan-vertex-fixed':'')+'"></div>',className:'',iconSize:[12,12],iconAnchor:[6,6]})}).addTo(_p.map);
    if(!isFixed){
      handle.on('drag',()=>{const pos=handle.getLatLng();coords[idx]=[Math.round(pos.lat*10)/10,Math.round(pos.lng*10)/10];updateLayer();});
      handle.on('dragend',persist);
    }
    handle.on('contextmenu',(e)=>{
      L.DomEvent.preventDefault(e);
      _pShowVertexActions(el.Id, idx);
    });
    _p.vertexHandles.push(handle);
  });
  // Mid-handles seulement pour traits (pas zones)
  if(el.TypeElement==='trait'){
    for(let i=0;i<coords.length-1;i++){
      const mid=[(coords[i][0]+coords[i+1][0])/2,(coords[i][1]+coords[i+1][1])/2];
      const mh=L.marker(mid,{draggable:true,icon:L.divIcon({html:'<div class="plan-vertex-handle plan-vertex-mid"></div>',className:'',iconSize:[10,10],iconAnchor:[5,5]})}).addTo(_p.map);
      let inserted=false,insertedIdx=-1;
      mh.on('dragstart',()=>{const pos=mh.getLatLng();coords.splice(i+1,0,[pos.lat,pos.lng]);insertedIdx=i+1;inserted=true;});
      mh.on('drag',()=>{if(!inserted)return;const pos=mh.getLatLng();coords[insertedIdx]=[Math.round(pos.lat*10)/10,Math.round(pos.lng*10)/10];updateLayer();});
      mh.on('dragend',async()=>{if(inserted){await persist();_pClearVertexHandles();_pShowVertexHandles(el);}});
      _p.vertexHandles.push(mh);
    }
  }
}

// ══════════════════ PROPRIÉTÉS (surface/périmètre/volume) ══════════════════
function _pShowProps(el) {
  const panel=document.getElementById('planPropsPanel');if(!panel)return;const ed=canEdit();
  const tn={point:'Point',trait:'Trait',zone:'Zone',texte:'Texte'}[el.TypeElement]||el.TypeElement;
  // Pour les traits, sn = libellé du sous-type (mur, cloison...). Pour les points, sn = nom de la catégorie.
  let sn = '';
  if (el.TypeElement === 'trait' && el.SousType && TRAIT_SUBTYPES[el.SousType]) sn = TRAIT_SUBTYPES[el.SousType].label;
  else if (el.TypeElement === 'point' && el.SousType) sn = el.SousType; // catégorie configurable
  let measHtml='';
  if(el.TypeElement==='trait'||el.TypeElement==='zone'){try{
    const coords=typeof el.Coords==='string'?JSON.parse(el.Coords):el.Coords;const ech=parseFloat(_p.etage?.Echelle)||0.05;
    let dist=0;for(let i=1;i<coords.length;i++)dist+=Math.sqrt((coords[i][1]-coords[i-1][1])**2+(coords[i][0]-coords[i-1][0])**2);
    const distM=dist*ech;measHtml+='<div class="plan-field"><span class="plan-field-label">Longueur</span><span class="plan-field-val">'+(distM>=1?distM.toFixed(2)+' m':(distM*100).toFixed(1)+' cm')+'</span></div>';
    if(el.TypeElement==='zone'){
      let a2=0;for(let i=0,j=coords.length-1;i<coords.length;j=i++)a2+=(coords[j][1]+coords[i][1])*(coords[j][0]-coords[i][0]);
      const area=Math.abs(a2/2)*ech*ech;
      let perim=0;for(let k=0;k<coords.length;k++){const j2=(k+1)%coords.length;perim+=Math.sqrt((coords[j2][1]-coords[k][1])**2+(coords[j2][0]-coords[k][0])**2);}
      measHtml+='<div class="plan-field"><span class="plan-field-label">Surface</span><span class="plan-field-val">'+area.toFixed(2)+' m²</span></div>';
      measHtml+='<div class="plan-field"><span class="plan-field-label">Périmètre</span><span class="plan-field-val">'+(perim*ech).toFixed(2)+' m</span></div>';
      const hauteur=parseFloat(el.Hauteur||0);
      if(hauteur>0)measHtml+='<div class="plan-field"><span class="plan-field-label">Volume</span><span class="plan-field-val">'+(area*hauteur).toFixed(2)+' m³ (h='+hauteur+'m)</span></div>';
    }
  }catch(_e){}}
  let vtxInfo='';try{const coords=typeof el.Coords==='string'?JSON.parse(el.Coords):el.Coords;if(coords&&coords.length>1){const fc=coords.filter((_c,i)=>_p.fixedVertices.has(el.Id+'-'+i)).length;vtxInfo='<div class="plan-field"><span class="plan-field-label">Sommets</span><span class="plan-field-val">'+coords.length+(fc?' ('+fc+' fixe'+(fc>1?'s':'')+')':'')+'</span></div>';}}catch(_e){}
  var phtml = '<div class="plan-props-body">';
  phtml += '<div class="plan-prop-badge" style="background:' + (el.Couleur||'#2b7be6') + '15;color:' + (el.Couleur||'#2b7be6') + ';border-color:' + (el.Couleur||'#2b7be6') + '">' + tn + (sn?' \u00b7 '+_escPlan(sn):'') + '</div>';
  phtml += '<div class="plan-field"><span class="plan-field-label">Nom</span><span class="plan-field-val">' + _escPlan(el.Nom||'Sans nom') + '</span></div>';
  if (el.TypeElement === 'point' && el.SousType) phtml += '<div class="plan-field"><span class="plan-field-label">Catégorie</span><span class="plan-field-val">' + _escPlan(el.SousType) + '</span></div>';
  if (el.Description) phtml += '<div class="plan-field"><span class="plan-field-label">Description</span><span class="plan-field-val">' + _escPlan(el.Description) + '</span></div>';
  phtml += '<div class="plan-field"><span class="plan-field-label">Calque</span><span class="plan-field-val">' + _escPlan(el.Calque||'Plan') + '</span></div>';
  if (el.TypeElement === 'texte') phtml += '<div class="plan-field"><span class="plan-field-label">Annuaire</span><span class="plan-field-val">' + (el.AfficherAnnuaire==1 ? '\u2713 Affich\u00e9' : '\u2014 Masqu\u00e9') + '</span></div>';
  phtml += '<div class="plan-field"><span class="plan-field-label">Couleur</span><span class="plan-field-val"><span class="plan-color-dot" style="background:' + (el.Couleur||'#2b7be6') + '"></span>' + (el.Couleur||'#2b7be6') + '</span></div>';
  if (el.TypeElement==='point') phtml += '<div class="plan-field"><span class="plan-field-label">Symbole</span><span class="plan-field-val">' + (PLAN_SYMBOLS[el.Icone]||PLAN_SYMBOLS.circle).label + '</span></div>';
  phtml += vtxInfo + measHtml + '</div>';
  if (ed) {
    phtml += '<div class="plan-props-actions">';
    phtml += '<button class="btn btn-secondary btn-sm" style="flex:1" onclick="_planEditElement(' + el.Id + ')">\u270f\ufe0f Modifier</button>';
    phtml += '<button class="btn btn-secondary btn-sm" onclick="_pToggleFix(' + el.Id + ')">' + (_p.fixedVertices.has(el.Id+'-0')?'\ud83d\udd13 Lib\u00e9rer':'\ud83d\udd12 Fixer') + '</button>';
    phtml += '<button class="btn btn-sm" style="flex:1;background:var(--red-light);color:var(--red);border:1px solid var(--red)" onclick="_planDeleteElement(' + el.Id + ')">\ud83d\uddd1\ufe0f Supprimer</button>';
    phtml += '</div>';
  }
  panel.innerHTML = phtml;
}
function _pToggleFix(elId) { const key=elId+'-0'; if(_p.fixedVertices.has(key))_p.fixedVertices.delete(key);else _p.fixedVertices.add(key); toast(_p.fixedVertices.has(key)?'Fixé':'Libéré','success'); const el=_p.elements.find(e=>e.Id===elId);if(el){if(_p.layers[elId]){_p.map.removeLayer(_p.layers[elId]);delete _p.layers[elId];}_pAddEl(el);_pShowProps(el);} }
function _pClearProps() { const p=document.getElementById('planPropsPanel');if(p)p.innerHTML='<div class="plan-empty-msg">Sélectionnez un élément</div>';_p.selectedEl=null; }

// ══════════════════ BAS (matériel rattaché) ══════════════════
async function _pShowBottom(el) {
  const bc=document.getElementById('planBottomContent');if(!bc)return;const ed=canEdit();
  let liens=[];try{liens=await PlansApi.getLiens(el.Id);}catch(_e){}
  const enriched=liens.map(l=>({...l,asset:l.AssetType==='Bien'?_p.biens.find(b=>b.Id==l.AssetId):_p.equips.find(e=>e.Id==l.AssetId)}));

  // Personne rattachée (compte lié OU saisie manuelle) — section du panneau bas
  let personObj=null;
  if (el.TypeElement==='point') {
    if (el.RefUtilisateurId) { await _planEnsureUsers(); personObj=(_p._annuaireUsers||[]).find(u=>String(u.Id)===String(el.RefUtilisateurId))||null; }
    else if (el.PersonneData) { try{ personObj=JSON.parse(el.PersonneData); }catch(_e){} }
  }
  let personHtml='';
  if (el.TypeElement==='point' && (el.EstPersonne==1 || el.RefUtilisateurId || el.PersonneData)) {
    personHtml += '<div class="plan-bottom-title" style="margin-top:10px">\u{1f464} Personne ';
    if (ed) personHtml += '<button class="btn btn-primary btn-sm" onclick="_planAddPersonne('+el.Id+')" style="margin-left:8px">'+(personObj?'Modifier':'+ Ajouter une personne')+'</button>';
    personHtml += '</div>';
    if (personObj) {
      const nom=((personObj.Prenom||'')+' '+(personObj.Nom||'')).trim()||'Personne';
      const av=window.PersonPicker?PersonPicker.avatarHtml(personObj,34,el.Couleur):'';
      const meta=[personObj.Poste,personObj.Service].filter(Boolean).map(_escPlan).join(' \u00b7 ');
      const contact=[personObj.Email,personObj.TelPro||personObj.Tel,personObj.TelMobile].filter(Boolean).map(_escPlan).join(' \u00b7 ');
      const src=el.RefUtilisateurId?'compte li\u00e9':'saisie manuelle';
      personHtml += '<div class="plan-asset-list"><div class="plan-asset-row">'
        + '<span style="display:inline-flex;margin-right:8px">'+av+'</span>'
        + '<div class="plan-asset-info"><div class="plan-asset-num">'+_escPlan(nom)+'</div>'
        + '<div class="plan-asset-meta">'+(meta||'\u2014')+' \u00b7 <span style="opacity:.7">'+src+'</span></div></div>'
        + (ed?'<button class="plan-icon-btn plan-icon-danger" onclick="event.stopPropagation();_planRemovePersonne('+el.Id+')">\u2715</button>':'')
        + '</div>'
        + (contact?'<div style="font-size:11px;color:var(--gray-text);padding:4px 2px">'+contact+'</div>':'')
        + '</div>';
    } else {
      personHtml += '<div class="plan-empty-msg" style="text-align:left;padding:8px 0">Aucune</div>';
    }
  }

  let extra='';
  if(el.TypeElement==='trait'){try{const coords=typeof el.Coords==='string'?JSON.parse(el.Coords):el.Coords;const ech=parseFloat(_p.etage?.Echelle)||0.05;let d=0;for(let i=1;i<coords.length;i++)d+=Math.sqrt((coords[i][1]-coords[i-1][1])**2+(coords[i][0]-coords[i-1][0])**2);const m=d*ech;extra='<div class="plan-bottom-stat"><span class="plan-bottom-stat-lbl">Longueur</span><span class="plan-bottom-stat-val">'+(m>=1?m.toFixed(2)+' m':(m*100).toFixed(1)+' cm')+'</span></div>';}catch(_e){}}
  if(el.TypeElement==='zone'){try{const coords=typeof el.Coords==='string'?JSON.parse(el.Coords):el.Coords;const ech=parseFloat(_p.etage?.Echelle)||0.05;let a2=0;for(let i=0,j=coords.length-1;i<coords.length;j=i++)a2+=(coords[j][1]+coords[i][1])*(coords[j][0]-coords[i][0]);const area=Math.abs(a2/2)*ech*ech;let perim=0;for(let i=0;i<coords.length;i++){const j=(i+1)%coords.length;perim+=Math.sqrt((coords[j][1]-coords[i][1])**2+(coords[j][0]-coords[i][0])**2);}const hauteur=parseFloat(el.Hauteur||0);
    extra='<div class="plan-bottom-stat"><span class="plan-bottom-stat-lbl">Surface</span><span class="plan-bottom-stat-val">'+area.toFixed(2)+' m²</span></div><div class="plan-bottom-stat"><span class="plan-bottom-stat-lbl">Périmètre</span><span class="plan-bottom-stat-val">'+(perim*ech).toFixed(2)+' m</span></div>';
    if(hauteur>0)extra+='<div class="plan-bottom-stat"><span class="plan-bottom-stat-lbl">Volume</span><span class="plan-bottom-stat-val">'+(area*hauteur).toFixed(2)+' m³</span></div>';
  }catch(_e){}}
  var bhtml = '<div class="plan-bottom-grid"><div>';
  bhtml += '<div class="plan-bottom-title">' + _escPlan(el.Nom||'Sans nom') + '</div>';
  if (el.TypeElement === 'point' && el.SousType) bhtml += '<div style="font-size:12px;color:var(--blue);font-weight:600;margin:2px 0 4px">📍 ' + _escPlan(el.SousType) + '</div>';
  if (el.Description) bhtml += '<div class="plan-bottom-desc">' + _escPlan(el.Description) + '</div>';
  bhtml += personHtml;
  bhtml += extra + '</div><div>';
  bhtml += '<div class="plan-bottom-title">\u{1f517} Mat\u00e9riel (' + enriched.length + ') ';
  if (ed) bhtml += '<button class="btn btn-primary btn-sm" onclick="_planLinkAsset(' + el.Id + ')" style="margin-left:8px">+ Rattacher</button>';
  bhtml += '</div>';
  if (enriched.length === 0) { bhtml += '<div class="plan-empty-msg" style="text-align:left;padding:8px 0">Aucun</div>'; }
  else {
    bhtml += '<div class="plan-asset-list">';
    enriched.forEach(function(l) {
      bhtml += '<div class="plan-asset-row" style="cursor:pointer" onclick="_planShowAssetDetail(\'' + l.AssetType + '\',' + l.AssetId + ')">';
      bhtml += '<span class="plan-asset-icon ' + (l.AssetType==='Bien'?'is-bien':'is-equip') + '">' + (l.AssetType==='Bien'?'B':'E') + '</span>';
      bhtml += '<div class="plan-asset-info"><div class="plan-asset-num">' + (l.asset ? _escPlan(l.asset.Numero||l.asset.InfoProduit||'#'+l.AssetId) : '#'+l.AssetId) + '</div>';
      bhtml += '<div class="plan-asset-meta">' + l.AssetType + (l.asset&&l.asset.Famille?' \u00b7 '+_escPlan(l.asset.Famille):'') + (l.asset&&l.asset.Marque?' \u00b7 '+_escPlan(l.asset.Marque):'') + '</div></div>';
      if (ed) bhtml += '<button class="plan-icon-btn plan-icon-danger" onclick="event.stopPropagation();_planUnlinkAsset(' + l.Id + ',' + el.Id + ')">\u2715</button>';
      bhtml += '</div>';
    });
    bhtml += '</div>';
  }
  bhtml += '</div></div>';
  bc.innerHTML = bhtml;
}
function _pClearBottom() { const bc=document.getElementById('planBottomContent');if(bc)bc.innerHTML='<span class="plan-empty-msg" style="padding:12px">Sélectionnez un élément.</span>'; }

// ══════════════════ RATTACHEMENT : recherche + listes config ══════════════════
function _planLinkAsset(elementId) {
  const listes = Array.isArray(_p.listes) ? _p.listes : [];
  const listeCats=[...new Set(listes.filter(l=>l.Actif!=0).map(l=>l.Categorie))].sort();
  const listeOpts=listeCats.map(cat=>{const items=listes.filter(l=>l.Categorie===cat&&l.Actif!=0);return '<optgroup label="📋 '+_escPlan(cat)+'">'+items.map(l=>'<option value="Liste:'+l.Id+'" data-search="'+_escPlan((l.Valeur||'')+(l.Categorie||'')).toLowerCase()+'">'+_escPlan(l.Valeur)+'</option>').join('')+'</optgroup>';}).join('');
  const bO=_p.biens.map(b=>'<option value="Bien:'+b.Id+'" data-search="'+_escPlan((b.Numero||'')+(b.Famille||'')+(b.InfoProduit||'')+(b.Marque||'')).toLowerCase()+'">'+_escPlan(b.Numero||'—')+' · '+_escPlan(b.Famille||'')+' '+_escPlan(b.InfoProduit||'')+'</option>').join('');
  const eO=_p.equips.map(e=>'<option value="Equipement:'+e.Id+'" data-search="'+_escPlan((e.Numero||'')+(e.Famille||'')+(e.Marque||'')+(e.Modele||'')).toLowerCase()+'">'+_escPlan(e.Numero||'—')+' · '+_escPlan(e.Famille||'')+' '+_escPlan(e.Marque||'')+' '+_escPlan(e.Modele||'')+'</option>').join('');
  const html='<div style="display:flex;flex-direction:column;gap:12px"><div class="form-group"><label class="form-label">🔍 Rechercher</label><input class="form-control" id="pLASearch" placeholder="Tapez pour filtrer..." oninput="_planFilterAssetList()"></div><div class="form-group"><label class="form-label">Matériel à rattacher</label><div style="border:1px solid var(--gray-border);border-radius:8px;overflow:hidden"><select class="form-control" id="pLA" size="12" style="height:280px;width:100%;overflow-y:auto;border:none;border-radius:0">'+listeOpts+'<optgroup label="🏢 Biens">'+(bO||'<option disabled>Aucun</option>')+'</optgroup><optgroup label="⚙️ Équipements">'+(eO||'<option disabled>Aucun</option>')+'</optgroup></select></div></div></div>';
  openModal('Rattacher du matériel',html,async()=>{const v=document.getElementById('pLA').value;if(!v)return;const[t,a]=v.split(':');await PlansApi.addLien({elementId,assetType:t,assetId:parseInt(a)});closeModal();toast('Rattaché','success');const el=_p.elements.find(x=>x.Id===elementId);if(el)_pShowBottom(el);});
}
function _planFilterAssetList() { const s=(document.getElementById('pLASearch')?.value||'').toLowerCase();const sel=document.getElementById('pLA');if(!sel)return;[...sel.options].forEach(o=>{if(o.disabled)return;o.style.display=!s||(o.getAttribute('data-search')||'').includes(s)?'':'none';}); }
function _planShowAssetDetail(type,id) {
  const asset=type==='Bien'?_p.biens.find(b=>b.Id==id):_p.equips.find(e=>e.Id==id);
  if(!asset){toast('Introuvable','error');return;}
  const fields=type==='Bien'?[['N°',asset.Numero],['Famille',asset.Famille],['Sous-famille',asset.SousFamille],['Statut',asset.Statut],['État',asset.Etat],['Prix',asset.Prix?asset.Prix+' €':''],['Bâtiment',asset.Batiment],['Affecté à',asset.NomPrenom],['Info produit',asset.InfoProduit],['N° Série',asset.NumSerie]]:[['N°',asset.Numero],['Famille',asset.Famille],['Marque',asset.Marque],['Modèle',asset.Modele],['Statut',asset.Statut],['État',asset.Etat],['Fournisseur',asset.Fournisseur],['Prix',asset.Prix?asset.Prix+' €':''],['Bâtiment',asset.Batiment],['N° Série',asset.NumSerie]];
  let html='<div class="plan-info-grid">'+fields.map(([l,v])=>'<div class="plan-info-field"><div class="plan-info-field-label">'+l+'</div><div class="plan-info-field-val">'+_escPlan(v||'—')+'</div></div>').join('')+'</div>';
  html+='<div style="margin-top:12px;text-align:center"><button class="btn btn-secondary btn-sm" onclick="closeModal()">Fermer</button></div>';
  openModal((type==='Bien'?'🏢':'⚙️')+' '+_escPlan(asset.Numero||'#'+id),html);
}
async function _planUnlinkAsset(lienId,elementId) { if(!confirm('Détacher ?'))return;await PlansApi.deleteLien(lienId);toast('Détaché','success');const el=_p.elements.find(x=>x.Id===elementId);if(el)_pShowBottom(el); }

// ══════════════════ CRUD ÉLÉMENTS ══════════════════
function _planPointCategorieOptions(selected) {
  // Conservé pour rétro-compat ; remplacé en pratique par listFieldHtml() qui respecte
  // les flags Obligatoire/SaisieLibre (cf. _pSaveElement / _planEditElement).
  const cats = _p.pointCategories || [];
  let html = '<option value="">— Aucune —</option>';
  cats.forEach(c => {
    const v = c.Valeur || '';
    html += '<option value="'+_escPlan(v)+'" '+(v===(selected||'')?'selected':'')+'>'+_escPlan(v)+'</option>';
  });
  return html;
}

/**
 * Génère le bloc HTML "Catégorie de point" en respectant les flags
 * Obligatoire (étoile + required) et SaisieLibre (input+datalist au lieu de select)
 * configurés via Configuration → Listes → Plans.
 */
function _planPointCategorieFieldHtml(selected) {
  const cats = _p.pointCategories || [];
  if (!cats.length) {
    return '<div class="form-group"><label class="form-label">Catégorie <span style="font-size:10px;color:var(--gray-text);font-weight:400">(Configuration → Listes → Plans)</span></label>'
      + '<input class="form-control" id="pXCat" placeholder="(aucune catégorie configurée)" disabled style="opacity:.6"></div>';
  }
  // Utilise listFieldHtml() défini dans ui.js (respecte Obligatoire/SaisieLibre)
  const labelHtml = (typeof listLabelHtml === 'function')
    ? listLabelHtml('Catégorie', cats)
    : 'Catégorie' + (cats.some(x => x.Obligatoire == 1) ? ' <span class="req">*</span>' : '');
  const fieldHtml = (typeof listFieldHtml === 'function')
    ? listFieldHtml('pXCat', cats, selected || '', { placeholder: 'Choisir ou saisir…', emptyOption: '— Aucune —' })
    : '<select class="form-control" id="pXCat">' + _planPointCategorieOptions(selected) + '</select>';
  return '<div class="form-group"><label class="form-label">' + labelHtml + ' <span style="font-size:10px;color:var(--gray-text);font-weight:400">(Configuration → Listes → Plans)</span></label>' + fieldHtml + '</div>';
}

// Annuaire : chargement (caché) de la liste des utilisateurs
async function _planEnsureUsers() {
  if (_p._annuaireUsers) return _p._annuaireUsers;
  try { _p._annuaireUsers = await UtilisateursApi.getAll(); }
  catch (_e) { _p._annuaireUsers = []; }
  return _p._annuaireUsers;
}

// Outil « Personne » : crée un point support puis ouvre la fiche personne
async function _pAddPersonnePoint(coords) {
  if (!_p.etage) { toast('Sélectionnez un étage', 'error'); return; }
  const data = { etageId:_p.etage.Id, typeElement:'point', nom:'', couleur:_p.color, opacite:_p.opacity,
    coords:JSON.stringify(coords), icone:'circle', sousType:'', calque:_p.activeCalque, description:'', estPersonne:1 };
  let res;
  try { res = await PlansApi.addElement(data); }
  catch (e) { toast('Impossible de créer le point : ' + (e.message || e), 'error'); return; }
  const newEl = { Id:res.id, Nom:'', Couleur:data.couleur, Opacite:data.opacite, Coords:data.coords,
    TypeElement:'point', Icone:'circle', SousType:'', Calque:data.calque, Description:'',
    RefUtilisateurId:null, PersonneData:null, EstPersonne:1 };
  _p.elements.push(newEl); _pAddEl(newEl);
  _pTool(null);            // revenir en mode sélection
  _pSelectEl(newEl);       // afficher le panneau du bas
  _planAddPersonne(newEl.Id); // ouvrir directement la fiche personne
}

// Ajouter / modifier la personne rattachée à un point
//          (recherche d'un compte OU saisie manuelle). Ouvert depuis le panneau du bas.
async function _planAddPersonne(elementId) {
  await _planEnsureUsers();
  const el = _p.elements.find(x => x.Id === elementId);
  let manual = {};
  if (el && el.PersonneData) { try { manual = JSON.parse(el.PersonneData) || {}; } catch (_e) {} }
  const users = (_p._annuaireUsers || []).slice()
    .sort((a,b)=>(a.Nom||'').localeCompare(b.Nom||'')||(a.Prenom||'').localeCompare(b.Prenom||''));
  const hasAccounts = users.length > 0;
  _p._personneMode = (el && el.RefUtilisateurId) ? 'search'
                   : ((el && el.PersonneData) || !hasAccounts) ? 'manual' : 'search';

  const opts = '<option value="">\u2014 Choisir \u2014</option>' + users.map(u => {
    const label = ((u.Prenom||'')+' '+(u.Nom||'')).trim() + (u.Service?' \u2014 '+u.Service:'') + (u.Poste?' \u00b7 '+u.Poste:'');
    const search = ((u.Prenom||'')+' '+(u.Nom||'')+' '+(u.Service||'')+' '+(u.Poste||'')+' '+(u.Email||'')).toLowerCase();
    return '<option value="'+u.Id+'" data-search="'+_escPlan(search)+'" '+(String(u.Id)===String((el&&el.RefUtilisateurId)||'')?'selected':'')+'>'+_escPlan(label)+'</option>';
  }).join('');

  const tabBtn = (m, label) => '<button type="button" class="btn btn-sm '+(_p._personneMode===m?'btn-primary':'btn-secondary')+'" id="ppTabBtn_'+m+'" onclick="_planPersonneTab(\''+m+'\')">'+label+'</button>';
  const field = (id, label, val) => '<div class="form-group"><label class="form-label">'+label+'</label><input class="form-control" id="'+id+'" value="'+_escPlan(val||'')+'"></div>';

  const searchTab =
    '<div id="ppTabSearch" style="display:'+(_p._personneMode==='search'?'block':'none')+'">'
    + (hasAccounts
      ? '<div class="form-group"><label class="form-label">\ud83d\udd0d Rechercher un compte</label>'
        + '<input class="form-control" id="ppUserSearch" placeholder="Nom, service, poste\u2026" oninput="_planFilterPersonneSelect()" style="margin-bottom:6px"></div>'
        + '<div class="form-group"><div style="border:1px solid var(--gray-border);border-radius:8px;overflow:hidden">'
        + '<select id="ppUser" size="7" class="form-control" style="width:100%;height:230px;border:none;border-radius:0">'+opts+'</select></div>'
        + '<div style="font-size:11px;color:var(--gray-text);margin-top:4px">Infos (service, poste, t\u00e9l, e-mail, photo) reprises du compte.</div></div>'
      : '<div class="plan-empty-msg" style="text-align:left;padding:8px 0">Aucun compte utilisateur disponible \u2014 utilisez la saisie manuelle.</div>'
        + '<select id="ppUser" style="display:none"></select>')
    + '</div>';

  const manualTab =
    '<div id="ppTabManual" style="display:'+(_p._personneMode==='manual'?'block':'none')+'">'
    + '<div class="form-grid">'
    + field('ppPrenom','Pr\u00e9nom',manual.Prenom) + field('ppNom','Nom',manual.Nom)
    + field('ppService','Service',manual.Service) + field('ppPoste','Poste',manual.Poste)
    + field('ppTel','T\u00e9l\u00e9phone',manual.Tel) + field('ppEmail','E-mail',manual.Email)
    + '</div></div>';

  const html = '<div style="display:flex;gap:8px;margin-bottom:12px">'+tabBtn('search','\ud83d\udd0e Compte existant')+tabBtn('manual','\u270f\ufe0f Saisir manuellement')+'</div>' + searchTab + manualTab;

  openModal('\ud83d\udc64 Personne rattach\u00e9e', html, async () => {
    const mode = _p._personneMode || 'search';
    let payload, localRef = null, localData = null;
    if (mode === 'search') {
      const id = document.getElementById('ppUser')?.value;
      if (!id) { toast('Choisissez un compte, ou passez en saisie manuelle', 'error'); return; }
      payload = { elementId, refUtilisateurId: parseInt(id), personneData: null };
      localRef = parseInt(id);
    } else {
      const g = id => (document.getElementById(id)?.value || '').trim();
      const data = { Prenom:g('ppPrenom'), Nom:g('ppNom'), Service:g('ppService'), Poste:g('ppPoste'), Tel:g('ppTel'), Email:g('ppEmail') };
      if (!data.Nom && !data.Prenom) { toast('Indiquez au moins un nom', 'error'); return; }
      payload = { elementId, refUtilisateurId: null, personneData: JSON.stringify(data) };
      localData = JSON.stringify(data);
    }
    if (typeof PlansApi.setPersonne !== 'function') { toast('Version en cache : rechargez la page (Ctrl+Shift+R).', 'error'); return; }
    try { await PlansApi.setPersonne(payload); }
    catch (e) { toast('\u00c9chec de l\'enregistrement : ' + (e.message || e), 'error'); return; }
    closeModal();
    toast('Personne enregistr\u00e9e', 'success');
    if (el) {
      el.RefUtilisateurId = localRef; el.PersonneData = localData;
      if (_p.layers[el.Id]) { _p.map.removeLayer(_p.layers[el.Id]); delete _p.layers[el.Id]; }
      _pAddEl(el); _pShowBottom(el);
    }
  });
}

function _planFilterPersonneSelect() {
  const q = (document.getElementById('ppUserSearch')?.value || '').trim().toLowerCase();
  const sel = document.getElementById('ppUser');
  if (!sel) return;
  Array.from(sel.options).forEach(o => { if (!o.value) { o.hidden=false; return; } o.hidden = q && !(o.getAttribute('data-search')||'').includes(q); });
}

// Bascule d'onglet (recherche / manuel) dans la modale personne
function _planPersonneTab(mode) {
  _p._personneMode = mode;
  const s = document.getElementById('ppTabSearch'), m = document.getElementById('ppTabManual');
  if (s) s.style.display = mode === 'search' ? 'block' : 'none';
  if (m) m.style.display = mode === 'manual' ? 'block' : 'none';
  ['search', 'manual'].forEach(x => {
    const b = document.getElementById('ppTabBtn_' + x);
    if (b) { b.classList.toggle('btn-primary', x === mode); b.classList.toggle('btn-secondary', x !== mode); }
  });
}

// Détacher la personne d'un point
async function _planRemovePersonne(elementId) {
  if (!confirm('Détacher la personne de ce point ?')) return;
  await PlansApi.setPersonne({ elementId, refUtilisateurId: null, personneData: null });
  toast('Détaché', 'success');
  const el = _p.elements.find(x => x.Id === elementId);
  if (el) {
    el.RefUtilisateurId = null; el.PersonneData = null;
    if (_p.layers[el.Id]) { _p.map.removeLayer(_p.layers[el.Id]); delete _p.layers[el.Id]; }
    _pAddEl(el); _pShowBottom(el);
  }
}

async function _pSaveElement(type, coords) {
  const calques=_planGetCalques().filter(c=>c!==_p.activeCalque);calques.unshift(_p.activeCalque);
  const html='<div style="display:flex;flex-direction:column;gap:16px"><div><div class="section-label">Identification</div><div class="form-grid"><div class="form-group"><label class="form-label">Nom</label><input class="form-control" id="pXN" placeholder="Bureau 12..."></div><div class="form-group"><label class="form-label">Calque</label><select class="form-control" id="pXL">'+calques.map(c=>'<option value="'+_escPlan(c)+'">'+_escPlan(c)+'</option>').join('')+'</select></div><div class="form-group"><label class="form-label">Couleur</label><input type="color" id="pXC" value="'+_p.color+'" style="height:38px;width:100%;padding:2px;border-radius:8px;border:1px solid var(--gray-border)"></div><div class="form-group"><label class="form-label">Opacité</label><input type="range" id="pXO" min="0.05" max="1" step="0.05" value="'+_p.opacity+'" style="width:100%;margin-top:8px"></div>'
    +(type==='point'?'<div class="form-group"><label class="form-label">Symbole</label><select class="form-control" id="pXS">'+Object.entries(PLAN_SYMBOLS).map(([k,v])=>'<option value="'+k+'" '+(k===_p.symbol?'selected':'')+'>'+v.label+'</option>').join('')+'</select></div>':'')
    +(type==='point'?_planPointCategorieFieldHtml(_p.lastPointCategorie||''):'')
    +(type==='trait'?'<div class="form-group"><label class="form-label">Type de trait</label><select class="form-control" id="pXT">'+Object.entries(TRAIT_SUBTYPES).map(([k,v])=>'<option value="'+k+'" '+(k===_p.sousType?'selected':'')+'>'+v.label+'</option>').join('')+'</select></div>':'')
    +(type==='zone'?'<div class="form-group"><label class="form-label">Hauteur (m)</label><input class="form-control" id="pXH" type="number" step="0.1" value="0" min="0" placeholder="Pour calcul volume"></div>':'')
    +'</div></div><div><div class="section-label">Observations</div><div class="form-group"><textarea class="form-control" id="pXD" rows="2" placeholder="Description..."></textarea></div></div></div>';
  openModal('Nouvel élément · '+(type==='point'?'Point':type==='trait'?'Trait':'Zone'),html,async()=>{
    // Pour les points, SousType = catégorie configurable ; pour les traits, SousType = type de trait ; pour les zones, vide
    let sousTypeVal = '';
    if (type === 'point') sousTypeVal = (document.getElementById('pXCat')?.value || '').trim();
    else if (type === 'trait') sousTypeVal = document.getElementById('pXT')?.value || '';
    // Validation : si la catégorie est obligatoire et qu'aucune n'est saisie
    if (type === 'point' && (_p.pointCategories || []).some(x => x.Obligatoire == 1) && !sousTypeVal) {
      toast('La catégorie est obligatoire', 'error');
      return;
    }
    if (type === 'point') _p.lastPointCategorie = sousTypeVal; // mémoriser pour le prochain
    const data={etageId:_p.etage.Id,typeElement:type,nom:document.getElementById('pXN').value.trim(),couleur:document.getElementById('pXC').value,opacite:parseFloat(document.getElementById('pXO').value)||.4,coords:JSON.stringify(coords),icone:type==='point'?(document.getElementById('pXS')?.value||'circle'):'',sousType:sousTypeVal,calque:document.getElementById('pXL')?.value||_p.activeCalque,description:document.getElementById('pXD').value.trim()};
    const hauteur=type==='zone'?parseFloat(document.getElementById('pXH')?.value)||0:0;
    const res=await PlansApi.addElement(data);
    const newEl={Id:res.id,Nom:data.nom,Couleur:data.couleur,Opacite:data.opacite,Coords:data.coords,TypeElement:type,Icone:data.icone,SousType:data.sousType,Calque:data.calque,Description:data.description,Hauteur:hauteur};
    _p.elements.push(newEl);_pAddEl(newEl);_pRefreshCalqueLists();closeModal();toast('Ajouté','success');
  });
}

async function _planEditElement(id) {
  const el=_p.elements.find(x=>x.Id===id);if(!el)return;
  const isTrait=el.TypeElement==='trait',isPoint=el.TypeElement==='point',isTexte=el.TypeElement==='texte',isZone=el.TypeElement==='zone';
  const calques=_planGetCalques();
  const html='<div style="display:flex;flex-direction:column;gap:16px"><div><div class="section-label">Identification</div><div class="form-grid"><div class="form-group"><label class="form-label">Nom</label><input class="form-control" id="pXN" value="'+_escPlan(el.Nom||'')+'"></div><div class="form-group"><label class="form-label">Calque</label><select class="form-control" id="pXL">'+calques.map(c=>'<option value="'+_escPlan(c)+'" '+(c===(el.Calque||'Plan')?'selected':'')+'>'+_escPlan(c)+'</option>').join('')+'</select></div><div class="form-group"><label class="form-label">Couleur</label><input type="color" id="pXC" value="'+(el.Couleur||'#2b7be6')+'" style="height:38px;width:100%;padding:2px;border-radius:8px;border:1px solid var(--gray-border)"></div><div class="form-group"><label class="form-label">Opacité</label><input type="range" id="pXO" min="0.05" max="1" step="0.05" value="'+(el.Opacite||.4)+'" style="width:100%;margin-top:8px"></div>'
    +(isPoint?'<div class="form-group"><label class="form-label">Symbole</label><select class="form-control" id="pXS">'+Object.entries(PLAN_SYMBOLS).map(([k,v])=>'<option value="'+k+'" '+(k===(el.Icone||'circle')?'selected':'')+'>'+v.label+'</option>').join('')+'</select></div>':'')
    +(isPoint?_planPointCategorieFieldHtml(el.SousType||''):'')
    +(isTrait?'<div class="form-group"><label class="form-label">Type de trait</label><select class="form-control" id="pXT">'+Object.entries(TRAIT_SUBTYPES).map(([k,v])=>'<option value="'+k+'" '+(k===(el.SousType||'')?'selected':'')+'>'+v.label+'</option>').join('')+'</select></div>':'')
    +(isTexte?'<div class="form-group"><label class="form-label">Taille</label><input class="form-control" id="pXTS" type="number" value="'+(parseInt(el.SousType)||14)+'" min="8" max="48"></div>':'')
    +(isZone?'<div class="form-group"><label class="form-label">Hauteur (m)</label><input class="form-control" id="pXH" type="number" step="0.1" value="'+(el.Hauteur||0)+'" min="0"></div>':'')
    +'</div>'
    +(isTexte?'<label style="display:flex;align-items:center;gap:8px;margin-top:10px;cursor:pointer;font-size:13px"><input type="checkbox" id="pXTA" '+(el.AfficherAnnuaire==1?'checked':'')+'> Afficher ce texte dans l\'annuaire (demandeurs)</label>':'')
    +'</div><div><div class="section-label">Observations</div><div class="form-group"><textarea class="form-control" id="pXD" rows="2">'+_escPlan(el.Description||'')+'</textarea></div></div></div>';
  openModal('Modifier',html,async()=>{
    let sousTypeVal = el.SousType || '';
    if (isPoint) sousTypeVal = (document.getElementById('pXCat')?.value || '').trim();
    else if (isTrait) sousTypeVal = document.getElementById('pXT')?.value || '';
    else if (isTexte) sousTypeVal = document.getElementById('pXTS')?.value || '14';
    // Validation : si la catégorie de point est obligatoire et qu'aucune n'est saisie
    if (isPoint && (_p.pointCategories || []).some(x => x.Obligatoire == 1) && !sousTypeVal) {
      toast('La catégorie est obligatoire', 'error');
      return;
    }
    const data={nom:document.getElementById('pXN').value.trim(),couleur:document.getElementById('pXC').value,opacite:parseFloat(document.getElementById('pXO').value)||.4,coords:el.Coords,icone:isPoint?(document.getElementById('pXS')?.value||'circle'):el.Icone||'',sousType:sousTypeVal,calque:document.getElementById('pXL')?.value||el.Calque||'Plan',description:document.getElementById('pXD').value.trim()};
    if(isTexte)data.afficherAnnuaire=document.getElementById('pXTA')?.checked?1:0;
    const hauteur=isZone?parseFloat(document.getElementById('pXH')?.value)||0:el.Hauteur||0;
    await PlansApi.updateElement(id,data);
    Object.assign(el,{Nom:data.nom,Couleur:data.couleur,Opacite:data.opacite,Icone:data.icone,SousType:data.sousType,Calque:data.calque,Description:data.description,Hauteur:hauteur});
    if(isTexte)el.AfficherAnnuaire=data.afficherAnnuaire;
    if(_p.layers[id]){_p.map.removeLayer(_p.layers[id]);delete _p.layers[id];}_pAddEl(el);
    _pRefreshCalqueLists();closeModal();toast('Modifié','success');_pShowProps(el);_pShowBottom(el);
  });
}

async function _planDeleteElement(id) {
  if(!_p.eraserMode&&!confirm('Supprimer cet élément ?'))return;
  try{await PlansApi.deleteElement(id);if(_p.layers[id]){_p.map.removeLayer(_p.layers[id]);delete _p.layers[id];}_p.elements=_p.elements.filter(x=>x.Id!==id);_pClearProps();_pClearBottom();_pClearVertexHandles();_pRefreshCalqueLists();toast('Supprimé','success');}
  catch(e){toast(e.message||'Erreur','error');}
}

function _pRefreshCalqueLists() { const l=document.getElementById('planCalqueList'),a=document.getElementById('planCalqueActif');if(l)l.innerHTML=_renderCalqueList();if(a)a.innerHTML=_planCalqueOptions();if(_p.leftTab==='recherche'||_p.leftTab==='listes'||_p.leftTab==='filtres'){const el=document.getElementById('planLeftContent');if(el)el.innerHTML=_renderLeftContent();} }

// ══════════════════ CRUD BÂTIMENT (enrichi) ══════════════════
async function _planEditBatiment(id) {
  const b=id?_p.batiments.find(x=>x.Id==id)||{}:{};
  const html='<div style="display:flex;flex-direction:column;gap:16px"><div><div class="section-label">Identification</div><div class="form-grid"><div class="form-group"><label class="form-label">Nom *</label><input class="form-control" id="pBN" value="'+_escPlan(b.Nom||'')+'"></div><div class="form-group"><label class="form-label">Adresse</label><input class="form-control" id="pBA" value="'+_escPlan(b.Adresse||'')+'"></div><div class="form-group"><label class="form-label">Type</label><select class="form-control" id="pBT"><option value="">—</option>'+['Tertiaire','Industriel','Résidentiel','Commercial','Hospitalier','Scolaire','Sportif','Entrepôt','Parking','Autre'].map(t=>'<option value="'+t+'" '+((b.Type||'')===t?'selected':'')+'>'+t+'</option>').join('')+'</select></div><div class="form-group"><label class="form-label">Surface (m²)</label><input class="form-control" id="pBS" type="number" value="'+(b.Surface||'')+'"></div><div class="form-group"><label class="form-label">Année construction</label><input class="form-control" id="pBY" type="number" value="'+(b.AnneeConstruction||'')+'"></div><div class="form-group"><label class="form-label">Couleur</label><input type="color" id="pBC" value="'+(b.Couleur||'#2b7be6')+'" style="height:38px;width:100%;padding:2px;border-radius:8px;border:1px solid var(--gray-border)"></div></div></div><div><div class="section-label">Contact</div><div class="form-grid"><div class="form-group"><label class="form-label">Propriétaire</label><input class="form-control" id="pBP" value="'+_escPlan(b.Proprietaire||'')+'"></div><div class="form-group"><label class="form-label">Gestionnaire</label><input class="form-control" id="pBG" value="'+_escPlan(b.Gestionnaire||'')+'"></div><div class="form-group"><label class="form-label">Téléphone</label><input class="form-control" id="pBPh" value="'+_escPlan(b.Telephone||'')+'"></div><div class="form-group"><label class="form-label">Email</label><input class="form-control" id="pBE" type="email" value="'+_escPlan(b.Email||'')+'"></div></div></div><div><div class="section-label">Observations</div><div class="form-group"><textarea class="form-control" id="pBNo" rows="3">'+_escPlan(b.Notes||'')+'</textarea></div></div></div>';
  openModal(id?'Modifier le bâtiment':'Nouveau bâtiment',html,async()=>{
    const data={nom:document.getElementById('pBN').value.trim(),adresse:document.getElementById('pBA').value.trim(),couleur:document.getElementById('pBC').value,type:document.getElementById('pBT')?.value||'',surface:document.getElementById('pBS')?.value||'',proprietaire:document.getElementById('pBP')?.value?.trim()||'',gestionnaire:document.getElementById('pBG')?.value?.trim()||'',telephone:document.getElementById('pBPh')?.value?.trim()||'',email:document.getElementById('pBE')?.value?.trim()||'',notes:document.getElementById('pBNo')?.value?.trim()||''};
    if(!data.nom){toast('Nom requis','error');return;}
    if(id)await PlansApi.updateBatiment(id,data);else await PlansApi.addBatiment(data);
    closeModal();toast(id?'Modifié':'Ajouté','success');renderPlans();
  });
}
async function _planDeleteBatiment() { if(!_p.batiment)return;if(!confirm('Supprimer « '+_p.batiment.Nom+' » et tout son contenu ?'))return;await PlansApi.deleteBatiment(_p.batiment.Id);_p.batiment=null;_p.etage=null;toast('Supprimé','success');renderPlans(); }
function _planInfoBatiment() { if(!_p.batiment)return;const b=_p.batiment;const fields=[['Nom',b.Nom],['Adresse',b.Adresse],['Type',b.Type],['Surface',(b.Surface||'—')+' m²'],['Étages',_p.etages.length],['Année',b.AnneeConstruction],['Propriétaire',b.Proprietaire],['Gestionnaire',b.Gestionnaire],['Tél.',b.Telephone],['Email',b.Email]];let html='<div class="plan-info-grid">'+fields.map(([l,v])=>'<div class="plan-info-field"><div class="plan-info-field-label">'+l+'</div><div class="plan-info-field-val">'+_escPlan(v||'—')+'</div></div>').join('')+'</div>';if(b.Notes)html+='<div style="margin-top:8px;padding:8px;background:var(--gray-bg);border-radius:6px;font-size:12px">'+_escPlan(b.Notes)+'</div>';html+='<hr style="margin:12px 0"><div style="font-size:12px;color:var(--gray-text)">'+_p.elements.length+' éléments · '+_planGetCalques().length+' calques</div>';openModal('ℹ️ '+_escPlan(b.Nom),html); }

// ══════════════════ CRUD ÉTAGES ══════════════════
async function _planEditEtage(id) { if(!_p.batiment){toast('Sélectionnez un bâtiment','error');return;} const et=id?_p.etages.find(x=>x.Id==id)||{}:{}; const html='<div style="display:flex;flex-direction:column;gap:16px"><div><div class="section-label">Identification</div><div class="form-grid"><div class="form-group"><label class="form-label">Nom *</label><input class="form-control" id="pEN" value="'+_escPlan(et.Nom||'')+'" placeholder="RDC, 1er…"></div><div class="form-group"><label class="form-label">Niveau (0=RDC)</label><input class="form-control" id="pEV" type="number" value="'+(et.Niveau??0)+'"></div></div></div><div><div class="section-label">Échelle</div><div class="form-grid"><div class="form-group"><label class="form-label">Échelle (m/px)</label><input class="form-control" id="pES" type="number" step="0.001" value="'+(et.Echelle||0.05)+'"><div style="font-size:11px;color:var(--gray-text);margin-top:4px">Ou utilisez l\'outil 📏 pour définir visuellement</div></div></div></div><div><div class="section-label">Canevas</div><div class="form-grid"><div class="form-group"><label class="form-label">Largeur (px)</label><input class="form-control" id="pEW" type="number" value="'+(et.FondLargeur||1000)+'"></div><div class="form-group"><label class="form-label">Hauteur (px)</label><input class="form-control" id="pEH" type="number" value="'+(et.FondHauteur||700)+'"></div></div></div></div>'; openModal(id?'Modifier l\'étage':'Nouvel étage',html,async()=>{const data={batimentId:_p.batiment.Id,nom:document.getElementById('pEN').value.trim(),niveau:parseInt(document.getElementById('pEV').value)||0,fondLargeur:parseInt(document.getElementById('pEW').value)||1000,fondHauteur:parseInt(document.getElementById('pEH').value)||700,echelle:parseFloat(document.getElementById('pES').value)||0.05};if(!data.nom){toast('Nom requis','error');return;}if(id)await PlansApi.updateEtage(id,data);else await PlansApi.addEtage(data);closeModal();toast(id?'Modifié':'Ajouté','success');_p.etages=await PlansApi.getEtages(_p.batiment.Id);if(!_p.etage&&_p.etages.length)_p.etage=_p.etages[0];if(_p.etage)_p.elements=await PlansApi.getElements(_p.etage.Id);_renderMain(document.getElementById('mainContent'));}); }
async function _planDeleteEtage(id,nom) { if(!confirm('Supprimer « '+nom+' » ?'))return;await PlansApi.deleteEtage(id);toast('Supprimé','success');if(_p.etage?.Id==id)_p.etage=null;_p.etages=await PlansApi.getEtages(_p.batiment.Id);if(!_p.etage&&_p.etages.length)_p.etage=_p.etages[0];_p.elements=_p.etage?await PlansApi.getElements(_p.etage.Id):[];_renderMain(document.getElementById('mainContent')); }

// ══════════════════ SAUVEGARDES ══════════════════
function _planExportSnapshot() { const name=prompt('Nom :','Sauvegarde '+new Date().toLocaleDateString('fr-FR'));if(!name)return;const snap={id:Date.now(),name,date:new Date().toISOString(),batiment:_p.batiment?{Id:_p.batiment.Id,Nom:_p.batiment.Nom}:null,etage:_p.etage?{Id:_p.etage.Id,Nom:_p.etage.Nom}:null,elements:_p.elements.map(e=>({...e})),hiddenCalques:{..._p.hiddenCalques},activeCalque:_p.activeCalque};_p.savedSnapshots.push(snap);try{localStorage.setItem('gmao_plan_snapshots',JSON.stringify(_p.savedSnapshots));}catch(_e){}const blob=new Blob([JSON.stringify(snap,null,2)],{type:'application/json'}),url=URL.createObjectURL(blob),a=document.createElement('a');a.href=url;a.download='gmao_'+name.replace(/\s/g,'_')+'.json';a.click();URL.revokeObjectURL(url);toast('Sauvegarde créée','success'); }

function _planShowSnapshots() {
  let html=_p.savedSnapshots.length===0?'<div class="plan-empty-msg">Aucune sauvegarde</div>':_p.savedSnapshots.map(s=>'<div style="display:flex;align-items:center;gap:8px;padding:8px 12px;border-bottom:1px solid var(--gray-border)"><div style="flex:1"><div style="font-weight:500">'+_escPlan(s.name)+'</div><div style="font-size:11px;color:var(--gray-text)">'+new Date(s.date).toLocaleString('fr-FR')+' · '+(s.elements?.length||0)+' éléments</div></div><button class="btn btn-secondary btn-sm" onclick="_planRestoreSnapshot('+s.id+')">♻️ Restaurer</button><button class="plan-icon-btn plan-icon-danger" onclick="_planDeleteSnapshot('+s.id+')">🗑️</button></div>').join('');
  html+='<hr style="margin:12px 0"><div style="text-align:center"><button class="btn btn-secondary btn-sm" onclick="_planImportSnapshot()">📥 Importer JSON</button></div>';
  openModal('📂 Sauvegardes',html);
}
async function _planRestoreSnapshot(id) { const s=_p.savedSnapshots.find(x=>x.id===id);if(!s){toast('Introuvable','error');return;}if(!confirm('Restaurer « '+s.name+' » ?\\nLes éléments actuels seront remplacés.'))return;for(const el of _p.elements){try{await PlansApi.deleteElement(el.Id);}catch(_e){}}_p.elements=[];for(const el of(s.elements||[])){try{const data={etageId:_p.etage.Id,typeElement:el.TypeElement,nom:el.Nom||'',couleur:el.Couleur||'#2b7be6',opacite:el.Opacite||0.4,coords:typeof el.Coords==='string'?el.Coords:JSON.stringify(el.Coords),icone:el.Icone||'',sousType:el.SousType||'',calque:el.Calque||'Plan',description:el.Description||''};const res=await PlansApi.addElement(data);_p.elements.push({...el,Id:res.id});}catch(_e){console.warn('Restore skip:',_e);}}closeModal();toast('Restauré: '+s.name,'success');_pInitMap();_pRefreshCalqueLists(); }
function _planDeleteSnapshot(id) { if(!confirm('Supprimer ?'))return;_p.savedSnapshots=_p.savedSnapshots.filter(x=>x.id!==id);try{localStorage.setItem('gmao_plan_snapshots',JSON.stringify(_p.savedSnapshots));}catch(_e){}toast('Supprimé','success');_planShowSnapshots(); }
function _planImportSnapshot() { const input=document.createElement('input');input.type='file';input.accept='.json';input.onchange=e=>{const f=e.target.files[0];if(!f)return;const r=new FileReader();r.onload=ev=>{try{const d=JSON.parse(ev.target.result);if(d.elements&&d.name){_p.savedSnapshots.push(d);try{localStorage.setItem('gmao_plan_snapshots',JSON.stringify(_p.savedSnapshots));}catch(_e){}toast('Importé: '+d.name,'success');_planShowSnapshots();}else toast('Format invalide','error');}catch(_e){toast('JSON invalide','error');}};r.readAsText(f);};input.click(); }

// ══════════════════ VOIR LES FONDS ══════════════════
// Affiche TOUS les fichiers du dossier data/plans, qu'ils soient rattachés à un
// étage ou non. Les orphelins (jamais rattachés ou dont l'étage n'utilise plus
// l'image) sont signalés et peuvent être rattachés à un étage existant.
async function _planShowFonds() {
  // On affiche la modal en chargement, puis on remplit
  openModal('🖼️ Images de fond', '<div style="padding:24px;text-align:center;color:var(--gray-text)">⏳ Chargement…</div>');

  let files = [];
  try {
    const r = await apiRequest('plans_list_files');
    files = r?.files || [];
  } catch (e) {
    document.querySelector('#modalBody, .modal-body, .modal .modal-body')?.replaceChildren();
    openModal('🖼️ Images de fond', '<div class="plan-empty-msg">Erreur de chargement : ' + _escPlan(e.message || 'inconnue') + '</div>');
    return;
  }

  if (!files.length) {
    openModal('🖼️ Images de fond', '<div class="plan-empty-msg">Le dossier <code>data/plans</code> est vide.</div>');
    return;
  }

  const fmtSize = (n) => {
    if (n < 1024) return n + ' o';
    if (n < 1048576) return (n/1024).toFixed(1) + ' Ko';
    return (n/1048576).toFixed(2) + ' Mo';
  };
  const fmtDate = (ts) => {
    try { return new Date(ts*1000).toLocaleString('fr-FR', {dateStyle:'short', timeStyle:'short'}); }
    catch(_) { return ''; }
  };
  const niveauLabel = (n) => n<0 ? 'SS'+Math.abs(n) : (n===0 ? 'RDC' : 'N'+n);

  const orphans = files.filter(f => !f.linked);
  const linked  = files.filter(f =>  f.linked);

  let html = '';

  if (orphans.length) {
    html += '<div style="font-size:12px;color:#92400e;background:#fef3c7;border:1px solid #fde68a;border-radius:8px;padding:8px 12px;margin-bottom:12px">'
         +  '⚠️ <b>' + orphans.length + ' fichier(s) orphelin(s)</b> — présents dans le dossier mais non rattachés à un étage.'
         +  '</div>';
  }

  const renderRow = (f) => {
    const isPdf = f.ext === 'pdf';
    const url = API_BASE + '?action=plans_file&name=' + encodeURIComponent(f.name);
    const linkedInfo = f.linked
      ? '<div style="font-size:11px;color:var(--gray-text);margin-top:2px">📌 Rattaché à : <b>' + niveauLabel(f.linked.niveau) + ' — ' + _escPlan(f.linked.etage_nom) + '</b></div>'
      : '<div style="font-size:11px;color:#b45309;margin-top:2px">⚠️ Non rattaché</div>';
    const previewInner = isPdf
      ? '<div style="display:flex;align-items:center;justify-content:center;width:100%;height:100%;background:var(--gray-bg);font-size:16px">📄</div>'
      : '<img src="' + url + '" loading="lazy" style="width:100%;height:100%;object-fit:cover" onerror="this.parentElement.innerHTML=\'<div style=&quot;display:flex;align-items:center;justify-content:center;width:100%;height:100%;background:var(--gray-bg);font-size:16px&quot;>?</div>\'">';
    const actions = []
      .concat(['<button class="btn btn-secondary btn-sm" onclick="window.open(\'' + url + '\',\'_blank\')" title="Ouvrir dans un onglet">👁️ Voir</button>'])
      .concat(f.linked
        ? []
        : ['<button class="btn btn-primary btn-sm" onclick="_planAttachOrphan(\'' + _escPlan(f.name).replace(/'/g, "\\'") + '\')" title="Rattacher à un étage existant">🔗 Rattacher</button>',
           '<button class="btn btn-danger btn-sm" onclick="_planDeleteOrphan(\'' + _escPlan(f.name).replace(/'/g, "\\'") + '\')" title="Supprimer du dossier">🗑️</button>']);
    return '<div style="display:flex;align-items:center;gap:10px;padding:8px;border:1px solid var(--gray-border);border-radius:8px;margin-bottom:6px;background:' + (f.linked ? 'var(--white)' : '#fffbeb') + '">'
         + '<div style="width:60px;height:40px;border-radius:4px;overflow:hidden;border:1px solid var(--gray-border);flex-shrink:0">' + previewInner + '</div>'
         + '<div style="flex:1;min-width:0">'
         +   '<div style="font-weight:600;font-size:12.5px;font-family:monospace;color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + _escPlan(f.name) + '</div>'
         +   '<div style="font-size:10.5px;color:var(--gray-text)">' + fmtSize(f.size) + ' · ' + fmtDate(f.mtime) + ' · .' + f.ext + '</div>'
         +   linkedInfo
         + '</div>'
         + '<div style="display:flex;flex-direction:column;gap:4px;flex-shrink:0">' + actions.join('') + '</div>'
         + '</div>';
  };

  if (orphans.length) {
    html += '<div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--gray-text);margin:6px 0 8px;letter-spacing:.5px">Fichiers orphelins</div>';
    html += orphans.map(renderRow).join('');
  }
  if (linked.length) {
    html += '<div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--gray-text);margin:14px 0 8px;letter-spacing:.5px">Fichiers rattachés (' + linked.length + ')</div>';
    html += linked.map(renderRow).join('');
  }

  openModal('🖼️ Images de fond — dossier <code>data/plans</code>', html);
}

// Rattacher un fichier orphelin à un étage existant
async function _planAttachOrphan(name) {
  if (!_p.etages || !_p.etages.length) {
    toast('Aucun étage chargé. Ouvrez d\'abord un bâtiment.', 'error');
    return;
  }
  const opts = _p.etages.map(et => {
    const niv = et.Niveau<0 ? 'SS'+Math.abs(et.Niveau) : (et.Niveau===0 ? 'RDC' : 'N'+et.Niveau);
    return '<option value="' + et.Id + '">' + _escPlan(niv + ' — ' + (et.Nom || '?')) + (et.FondImage ? ' (a déjà un fond)' : '') + '</option>';
  }).join('');
  const html = '<div class="form-row"><p style="margin:0 0 8px;font-size:13px">Rattacher <code>' + _escPlan(name) + '</code> à un étage :</p>'
             + '<select id="pAttachEtage" class="input" style="width:100%">' + opts + '</select>'
             + '<p style="margin:8px 0 0;font-size:11px;color:#b45309">⚠️ Si l\'étage a déjà un fond, il sera remplacé.</p></div>';
  openModal('🔗 Rattacher à un étage', html, async () => {
    const sel = document.getElementById('pAttachEtage');
    const etageId = parseInt(sel?.value, 10);
    if (!etageId) { toast('Choisissez un étage', 'error'); return; }
    try {
      await apiRequest('plans_attach_file', 'POST', { name, etage_id: etageId });
      // Mettre à jour la liste locale des étages
      const et = _p.etages.find(e => e.Id === etageId);
      if (et) et.FondImage = 'data/plans/' + name;
      closeModal();
      toast('Rattaché à l\'étage', 'success');
      _planShowFonds();  // ré-ouvrir la liste actualisée
    } catch(e) { toast(e.message, 'error'); }
  });
}

// Supprimer un fichier orphelin du dossier
async function _planDeleteOrphan(name) {
  if (!confirm('Supprimer définitivement « ' + name + ' » ?\nCette action est irréversible.')) return;
  try {
    await apiRequest('plans_delete_file', 'POST', { name });
    toast('Fichier supprimé', 'success');
    _planShowFonds();
  } catch(e) { toast(e.message, 'error'); }
}

// ══════════════════ FOND, IMPORT, RECADRAGE ══════════════════
function _planUploadFond() { if(!_p.etage){toast('Sélectionnez un étage','error');return;} const html='<div class="form-row"><p style="margin:0 0 8px;color:var(--gray-text);font-size:13px">Image de plan (PNG, JPG, SVG, PDF)</p><input id="pFF" type="file" accept="image/*,.pdf,.svg" class="input"></div>'; openModal('Image de fond',html,async()=>{const f=document.getElementById('pFF');if(!f.files[0]){toast('Fichier requis','error');return;}try{const res=await PlansApi.uploadFond(_p.etage.Id,f.files[0]);_p.etage.FondImage=res?.path||('data/plans/plan_'+_p.etage.Id+'_'+Date.now()+'.'+f.files[0].name.split('.').pop());closeModal();toast('Fond chargé','success');_pInitMap();}catch(e){toast(e.message,'error');}}); }
function _planImport() { if(!_p.etage){toast('Sélectionnez un étage','error');return;} const html='<div class="form-row"><p style="margin:0 0 8px;font-size:13px">Importez des éléments :</p><ul style="margin:6px 0 12px;padding-left:20px;font-size:12px;color:var(--gray-text);line-height:1.6"><li><b>DXF</b> — AutoCAD</li><li><b>SVG</b></li><li><b>GeoJSON</b></li><li><b>CSV</b></li></ul><input id="pIF" type="file" accept=".dxf,.csv,.geojson,.json,.svg,.kml,.kmz,.gpx,.zip,.shp" class="input"></div>'; openModal('Importer',html,async()=>{const f=document.getElementById('pIF');if(!f.files[0]){toast('Fichier requis','error');return;}try{const res=await PlansApi.importFile(_p.etage.Id,f.files[0]);closeModal();toast(res.imported+' importé(s)','success');_p.elements=await PlansApi.getElements(_p.etage.Id);_pInitMap();setTimeout(()=>_pFitToElements(),200);_pRefreshCalqueLists();}catch(e){toast(e.message,'error');}}); }
function _pFitToElements() { if(!_p.map||!_p.elements.length)return;const allCoords=[];_p.elements.forEach(el=>{try{const c=typeof el.Coords==='string'?JSON.parse(el.Coords):el.Coords;if(Array.isArray(c))c.forEach(p=>{if(Array.isArray(p)&&p.length>=2)allCoords.push(p);});}catch(_e){}});if(!allCoords.length)return;const lats=allCoords.map(c=>c[0]),lngs=allCoords.map(c=>c[1]);_p.map.fitBounds([[Math.min(...lats),Math.min(...lngs)],[Math.max(...lats),Math.max(...lngs)]],{padding:[40,40],maxZoom:4}); }

// ══════════════════ MENU PLUS (sauv. + fonds + raccourcis) ══════════════════
function _planShowMoreMenu(evt) {
  if (evt) evt.stopPropagation();
  // Fermer si déjà ouvert
  const existing = document.getElementById('planMoreMenu');
  if (existing) { existing.remove(); return; }
  const btn = evt?.currentTarget;
  if (!btn) return;
  const rect = btn.getBoundingClientRect();
  const menu = document.createElement('div');
  menu.id = 'planMoreMenu';
  menu.style.cssText = 'position:fixed;top:'+(rect.bottom+4)+'px;right:'+(window.innerWidth-rect.right)+'px;background:var(--white);border:1px solid var(--gray-border);border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.12);z-index:9999;min-width:220px;overflow:hidden';
  const items = [
    { icon:'💾', label:'Créer une sauvegarde', action:'_planExportSnapshot()' },
    { icon:'📂', label:'Mes sauvegardes…', action:'_planShowSnapshots()' },
    { icon:'🖼️', label:'Voir les images de fond', action:'_planShowFonds()' },
    { sep:true },
    { icon:'⌨️', label:'Raccourcis clavier', action:'_planShowShortcuts()' },
  ];
  menu.innerHTML = items.map(it => {
    if (it.sep) return '<div style="height:1px;background:var(--gray-border);margin:4px 0"></div>';
    return '<div onclick="document.getElementById(\'planMoreMenu\').remove();'+it.action+'" style="padding:10px 14px;cursor:pointer;font-size:13px;color:var(--text);display:flex;align-items:center;gap:10px;transition:background .1s" onmouseover="this.style.background=\'var(--gray-bg)\'" onmouseout="this.style.background=\'\'"><span style="font-size:15px;width:20px;text-align:center">'+it.icon+'</span><span>'+it.label+'</span></div>';
  }).join('');
  document.body.appendChild(menu);
  setTimeout(() => {
    const close = (e) => {
      if (!menu.contains(e.target)) {
        menu.remove();
        document.removeEventListener('click', close);
      }
    };
    document.addEventListener('click', close);
  }, 50);
}

function _planShowShortcuts() {
  const html = '<div style="font-size:13px;line-height:1.8">'
    + '<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--gray-bg)"><span>Annuler le dessin en cours</span><kbd style="background:var(--gray-bg);padding:2px 8px;border-radius:4px;font-family:monospace;font-size:11px">Échap</kbd></div>'
    + '<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--gray-bg)"><span>Terminer un trait/zone</span><kbd style="background:var(--gray-bg);padding:2px 8px;border-radius:4px;font-family:monospace;font-size:11px">Entrée</kbd></div>'
    + '<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--gray-bg)"><span>Supprimer la sélection</span><kbd style="background:var(--gray-bg);padding:2px 8px;border-radius:4px;font-family:monospace;font-size:11px">Suppr</kbd></div>'
    + '<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--gray-bg)"><span>Outil sélection</span><kbd style="background:var(--gray-bg);padding:2px 8px;border-radius:4px;font-family:monospace;font-size:11px">V</kbd></div>'
    + '<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--gray-bg)"><span>Outil point</span><kbd style="background:var(--gray-bg);padding:2px 8px;border-radius:4px;font-family:monospace;font-size:11px">P</kbd></div>'
    + '<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--gray-bg)"><span>Outil trait</span><kbd style="background:var(--gray-bg);padding:2px 8px;border-radius:4px;font-family:monospace;font-size:11px">L</kbd></div>'
    + '<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--gray-bg)"><span>Outil zone</span><kbd style="background:var(--gray-bg);padding:2px 8px;border-radius:4px;font-family:monospace;font-size:11px">Z</kbd></div>'
    + '<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--gray-bg)"><span>Outil texte</span><kbd style="background:var(--gray-bg);padding:2px 8px;border-radius:4px;font-family:monospace;font-size:11px">T</kbd></div>'
    + '<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--gray-bg)"><span>Outil mesure</span><kbd style="background:var(--gray-bg);padding:2px 8px;border-radius:4px;font-family:monospace;font-size:11px">M</kbd></div>'
    + '<div style="display:flex;justify-content:space-between;padding:6px 0"><span>Recadrer sur les éléments</span><kbd style="background:var(--gray-bg);padding:2px 8px;border-radius:4px;font-family:monospace;font-size:11px">F</kbd></div>'
    + '</div>';
  openModal('⌨️ Raccourcis clavier', html);
}

// ══════════════════ ACTIONS SUR SOMMET (modal au lieu de prompt) ══════════════════
function _pShowVertexActions(elId, idx) {
  const el = _p.elements.find(e => e.Id === elId);
  if (!el) return;
  let coords;
  try { coords = typeof el.Coords === 'string' ? JSON.parse(el.Coords) : el.Coords; }
  catch(_e) { return; }
  const key = elId + '-' + idx;
  const isFixed = _p.fixedVertices.has(key);
  const minPts = el.TypeElement === 'zone' ? 3 : 2;
  const canDelete = coords.length > minPts;
  const html = '<div style="font-size:13px;color:var(--gray-text);margin-bottom:12px">Sommet n°'+(idx+1)+' de « '+_escPlan(el.Nom||'Sans nom')+' »</div>'
    + '<div class="plan-vertex-action-grid">'
    + '<button class="plan-vertex-action-btn" onclick="_pVertexFix('+elId+','+idx+');closeModal()">'
    + '<span class="icon">'+(isFixed?'🔓':'🔒')+'</span><span>'+(isFixed?'Libérer':'Fixer')+'</span>'
    + '</button>'
    + (canDelete
      ? '<button class="plan-vertex-action-btn danger" onclick="_pVertexDelete('+elId+','+idx+');closeModal()"><span class="icon">🗑️</span><span>Supprimer</span></button>'
      : '<button class="plan-vertex-action-btn" disabled style="opacity:.4;cursor:not-allowed"><span class="icon">🗑️</span><span>Min. '+minPts+' pts</span></button>')
    + '</div>';
  openModal('Action sur le sommet', html);
}

function _pVertexFix(elId, idx) {
  const key = elId + '-' + idx;
  if (_p.fixedVertices.has(key)) _p.fixedVertices.delete(key);
  else _p.fixedVertices.add(key);
  toast(_p.fixedVertices.has(key) ? 'Sommet fixé' : 'Sommet libéré', 'success');
  const el = _p.elements.find(e => e.Id === elId);
  if (el) { _pClearVertexHandles(); _pShowVertexHandles(el); }
}

async function _pVertexDelete(elId, idx) {
  const el = _p.elements.find(e => e.Id === elId);
  if (!el) return;
  let coords;
  try { coords = typeof el.Coords === 'string' ? JSON.parse(el.Coords) : el.Coords; }
  catch(_e) { return; }
  const minPts = el.TypeElement === 'zone' ? 3 : 2;
  if (coords.length <= minPts) return;
  coords.splice(idx, 1);
  el.Coords = JSON.stringify(coords);
  await PlansApi.updateElement(el.Id, { nom:el.Nom, couleur:el.Couleur, opacite:el.Opacite, coords:el.Coords, icone:el.Icone, description:el.Description, sousType:el.SousType||'', calque:el.Calque||'Plan' });
  if (_p.layers[el.Id]) { _p.map.removeLayer(_p.layers[el.Id]); delete _p.layers[el.Id]; }
  _pAddEl(el);
  _pClearVertexHandles();
  _pShowVertexHandles(el);
  toast('Sommet supprimé','success');
}

// ══════════════════ RACCOURCIS CLAVIER ══════════════════
function _pSetupKeyboard() {
  if (_p._keyboardBound) return;
  _p._keyboardBound = true;
  document.addEventListener('keydown', (e) => {
    // Ne pas intercepter si on est dans un input ou textarea
    if (e.target.matches('input, textarea, select, [contenteditable]')) return;
    // Ne fonctionne que sur la page plans
    if (App.currentPage !== 'plans') return;
    if (!canEdit()) return;
    const k = e.key;
    if (k === 'Escape') { _pCancel(); _pTool(null); }
    else if (k === 'Enter' && (_p.mode === 'trait' || _p.mode === 'zone') && _p.drawCoords.length >= 2) { e.preventDefault(); _pFinish(); }
    else if (k === 'Delete' || k === 'Backspace') {
      if (_p.selectedEl) { e.preventDefault(); _planDeleteElement(_p.selectedEl.Id); }
    }
    else if (k === 'v' || k === 'V') _pTool(null);
    else if (k === 'p' || k === 'P') _pTool('point');
    else if (k === 'l' || k === 'L') _pTool('trait');
    else if (k === 'z' || k === 'Z') { if (!e.ctrlKey && !e.metaKey) _pTool('zone'); }
    else if (k === 't' || k === 'T') _pToolText();
    else if (k === 'm' || k === 'M') _pTool('measure');
    else if (k === 'f' || k === 'F') _pFitToElements();
  });
}

function _escPlan(s) { return s ? String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;') : ''; }



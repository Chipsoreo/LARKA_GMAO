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
 * Larka — Import de biens / équipements depuis Excel/CSV
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Modal en 4 étapes :
 *   1. Cible        : Biens (mobilier/informatique/véhicules) ou Équipements (technique)
 *   2. Fichier      : Upload, sélection feuille, ligne d'en-têtes
 *   3. Mapping      : pour chaque colonne du fichier → champ Larka + mapping de valeurs
 *   4. Aperçu       : dry-run + import
 *
 * Le mapping est inversé (par colonne) pour mieux correspondre à la lecture
 * naturelle d'un fichier Excel : on regarde ses 23 colonnes une par une.
 * ═══════════════════════════════════════════════════════════════════════════════
 */

const _BIENS_IMPORT = {
  target: 'auto',                   // 'auto' | 'biens' | 'equipements'
  workbook: null,
  sheetNames: [],
  selectedSheet: null,
  rawRows: [],
  headerRowIdx: 0,
  headers: [],                      // [{ idx:0, label:'Code bien', sample:'10776', suggested:'Numero' }]
  mapping: {},                      // { colIdx: 'fieldKey' | '' (ignoré) }
  valueMap: { Statut: {}, Etat: {} },
  dedupStrategy: 'update',
  importSorted: 'historique',       // 'historique' | 'biens_with_flag' | 'skip' — que faire des biens sortis
  step: 1,
};

// Familles → cible automatique (Équipement). Comparaison sans accents/casse.
const _BI_EQUIP_FAMILIES = [
  'cvc', 'plomberie', 'plomb', 'electricite', 'elec',
  'ssi', 'securite incendie', 'incendie', 'anti-intrusion', 'intrusion',
  'chauffage', 'climatisation', 'ventilation', 'clim',
  'sanitaire', 'sanitaires', 'ascenseur', 'ascenseurs',
  'serrurerie', 'menuiserie', 'toiture', 'couverture',
  'eclairage', 'extincteur', 'porte coupe-feu', 'detection',
  'alarme', 'cloture', 'voirie', 'reseau eau', 'gaz',
];

/** Devine la cible (biens|equipements) selon la famille. */
function _biGuessTarget(famille) {
  if (!famille) return 'biens';
  const f = String(famille).toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'').trim();
  for (const k of _BI_EQUIP_FAMILIES) {
    if (f === k || f.includes(k)) return 'equipements';
  }
  return 'biens';
}

/** Devine si une ligne représente un bien "sorti" (état/statut indiquant sortie). */
function _biIsSorti(item) {
  const etat = String(item.etat || '').toLowerCase();
  const statut = String(item.statut || '').toLowerCase();
  return etat.includes('sorti') || etat.includes('jet') || etat.includes('don') || etat.includes('vendu')
      || etat.includes('hors service') || etat.includes('rebut')
      || statut === 'hu' || statut.includes('detruit');
}

// ══════════════ DÉFINITION DES CHAMPS CIBLES ══════════════
//
// Système de matching :
//  - exact      : le libellé normalisé doit être identique (priorité max)
//  - contains   : le libellé doit contenir tous les mots (priorité moyenne)
//  - negative   : si le libellé contient l'un de ces mots, on DISQUALIFIE le match
//
// L'idée : "Code bien" doit matcher Numero, mais PAS "Code famille (origine)"
//          "N° de série" doit matcher NumeroSerie, mais PAS "N° Immobilisation"
//          etc.

const _BI_FIELDS_BIENS = [
  { key: 'Numero',           label: 'N° du bien',                 required: true,
    exact: ['code bien','n° bien','numero bien','numéro bien','code'],
    contains: [['code','bien']],
    negative: ['famille','origine','sous-famille','source','série','serie','immobilisation','immo','bc','référence','reference','mandat','ligne','marche','marché'] },

  { key: 'Famille',          label: 'Famille',                    required: true,
    exact: ['famille'],
    contains: [],
    negative: ['sous','origine','code'] },

  { key: 'SousFamille',      label: 'Sous-famille',               required: false,
    exact: ['sous-famille','sous famille','sousfamille'],
    contains: [['sous','famille']],
    negative: ['origine','code'] },

  { key: 'InfoProduit',      label: 'Désignation',                required: false,
    exact: ['désignation','designation','libellé','libelle','description','produit','intitulé'],
    contains: [],
    negative: [] },

  { key: '_Marque',          label: 'Marque',                     required: false,
    exact: ['marque'],
    contains: [],
    negative: [] },

  { key: '_Modele',          label: 'Modèle',                     required: false,
    exact: ['modèle','modele'],
    contains: [],
    negative: [] },

  { key: 'NumeroSerie',      label: 'N° de série',                required: false,
    exact: ['n° de série','n° serie','numéro de série','numero serie','sn','s/n','serial'],
    contains: [['série'],['serie']],
    negative: ['code','famille','immobilisation','immo','bc','reference','référence','marche','mandat'] },

  { key: '_Fournisseur',     label: 'Fournisseur',                required: false,
    exact: ['fournisseur','marché','marche'],
    contains: [],
    negative: [] },

  { key: 'Batiment',         label: 'Site / Bâtiment',            required: false,
    exact: ['site','bâtiment','batiment','site / bâtiment','site/batiment','bât.','bat.','localisation site'],
    contains: [['site'],['bâtiment'],['batiment']],
    negative: ['affecté','occupant','code','origine'] },

  { key: 'NomPrenom',        label: 'Affectation / Personne',     required: false,
    exact: ['localisation / affecté à','localisation','affectation','affecté','affecte','occupant','personne'],
    contains: [['affecté'],['affectation'],['occupant']],
    negative: ['site','bâtiment','batiment'] },

  { key: 'Statut',           label: 'Statut',                     required: false,
    exact: ['statut'],
    contains: [],
    negative: ['code','origine'] },

  { key: 'Etat',             label: 'État',                       required: false,
    exact: ['état','etat'],
    contains: [],
    negative: ['code','origine'] },

  { key: 'DateCommande',     label: 'Date acquisition',           required: false,
    exact: ['date acquisition',"date d'acquisition",'date acq','date achat','date de commande','date commande'],
    contains: [['date','acquisition'],['date','achat'],['date','commande']],
    negative: ['service','livraison','mise','installation'] },

  { key: 'DateLivraison',    label: 'Date mise en service',       required: false,
    exact: ['date mise en service','date mes','date livraison','mise en service'],
    contains: [['date','mise','service'],['date','mes'],['date','livraison']],
    negative: ['acquisition','achat','commande','installation'] },

  { key: 'Prix',             label: 'Valeur HT',                  required: false,
    exact: ["valeur acquisition ht (€)",'valeur acquisition ht','valeur ht','valeur acquisition','prix','montant ht','prix achat',"prix d'achat"],
    contains: [['valeur','ht'],['valeur','acquisition'],['prix']],
    negative: ['amortissement','amort','vnc','résiduelle','residuelle','tva'] },

  { key: '_NumeroImmo',      label: "N° d'immobilisation",        required: false, compta: true,
    exact: ['n° immobilisation','numéro immobilisation','numero immobilisation','immobilisation','n° immo'],
    contains: [['immobilisation'],['immo']],
    negative: ['code','série','serie','bc','reference','référence'] },

  { key: '_AmortCum',        label: 'Amortissement cumulé',       required: false, compta: true,
    exact: ['amortissement cumulé','amortissement cumule','amortissement','amort cumulé','amort cumule','amort'],
    contains: [['amortissement']],
    negative: [] },

  { key: '_VNC',             label: 'VNC (valeur résiduelle)',    required: false, compta: true,
    exact: ['vnc','vnc (€)','valeur nette comptable','valeur résiduelle','valeur residuelle','valeur nette'],
    contains: [['vnc'],['résiduelle'],['residuelle']],
    negative: [] },

  { key: '_BC',              label: 'N° Bon de commande',         required: false, compta: true,
    exact: ['n° bc','bon de commande','bc','référence','reference','n° bc / référence','n° bc / reference'],
    contains: [['bc'],['bon','commande']],
    negative: ['immobilisation','immo','série','serie','mandat'] },
];

const _BI_FIELDS_EQUIPS = [
  { key: 'Numero',           label: "N° de l'équipement",         required: true,
    exact: ['code','code équipement','code equipement','n° équipement','n° equipement','numéro','numero','reference','référence'],
    contains: [['code'],['n°'],['numéro'],['numero'],['reference'],['référence']],
    negative: ['famille','origine','série','serie','source','immobilisation','bc','mandat','ligne'] },

  { key: 'Famille',          label: 'Famille',                    required: true,
    exact: ['famille'],
    contains: [],
    negative: ['sous','origine','code'] },

  { key: 'SousFamille',      label: 'Sous-famille',               required: false,
    exact: ['sous-famille','sous famille','sousfamille'],
    contains: [['sous','famille']],
    negative: ['origine','code'] },

  { key: 'InfoProduit',      label: 'Désignation',                required: false,
    exact: ['désignation','designation','libellé','libelle','description','produit','intitulé'],
    contains: [],
    negative: [] },

  { key: 'Marque',           label: 'Marque',                     required: false,
    exact: ['marque'], contains: [], negative: [] },

  { key: 'Modele',           label: 'Modèle',                     required: false,
    exact: ['modèle','modele'], contains: [], negative: [] },

  { key: 'NumeroSerie',      label: 'N° de série',                required: false,
    exact: ['n° de série','n° serie','numéro de série','numero serie','sn','s/n','serial'],
    contains: [['série'],['serie']],
    negative: ['code','famille','immobilisation'] },

  { key: 'Fournisseur',      label: 'Fournisseur',                required: false,
    exact: ['fournisseur','marché','marche'], contains: [], negative: [] },

  { key: 'Batiment',         label: 'Site / Bâtiment',            required: false,
    exact: ['site','bâtiment','batiment','site / bâtiment','site/batiment'],
    contains: [['site'],['bâtiment'],['batiment']],
    negative: ['affecté','occupant'] },

  { key: 'Etage',            label: 'Étage',                      required: false,
    exact: ['étage','etage','niveau'], contains: [['étage'],['niveau']], negative: [] },

  { key: 'NumeroBureau',     label: 'N° de bureau / local',       required: false,
    exact: ['n° bureau','numéro bureau','bureau','local','salle','n° local','n° salle'],
    contains: [['bureau'],['local'],['salle']],
    negative: [] },

  { key: 'NomPrenom',        label: 'Localisation / Personne',    required: false,
    exact: ['localisation','affectation','affecté','affecte','occupant','personne'],
    contains: [['affecté'],['affectation'],['occupant']],
    negative: ['site','bâtiment'] },

  { key: 'Statut',           label: 'Statut',                     required: false,
    exact: ['statut'], contains: [], negative: ['code','origine'] },

  { key: 'Etat',             label: 'État',                       required: false,
    exact: ['état','etat'], contains: [], negative: ['code','origine'] },

  { key: 'DateCommande',     label: 'Date commande',              required: false,
    exact: ['date commande','date acquisition',"date d'acquisition",'date acq','date achat'],
    contains: [['date','commande'],['date','acquisition'],['date','achat']],
    negative: ['service','livraison','mise','installation'] },

  { key: 'DateLivraison',    label: 'Date livraison',             required: false,
    exact: ['date livraison','date de livraison'],
    contains: [['date','livraison']],
    negative: ['acquisition','installation','mise','service'] },

  { key: 'DateInstallation', label: 'Date installation',          required: false,
    exact: ['date installation','date mise en service','date mes','mise en service'],
    contains: [['date','installation'],['date','mise','service'],['date','mes']],
    negative: ['acquisition','livraison','achat','commande'] },

  { key: 'Prix',             label: "Prix d'achat",               required: false,
    exact: ['valeur ht','valeur acquisition','prix',"prix d'achat",'montant ht'],
    contains: [['valeur','ht'],['prix']],
    negative: ['amortissement','vnc','résiduelle','tva'] },

  { key: 'Observations',     label: 'Observations',               required: false,
    exact: ['observations','commentaire','remarque','remarques','notes'],
    contains: [['observation'],['commentaire'],['remarque']],
    negative: [] },
];

function _biGetFields() {
  if (_BIENS_IMPORT.target === 'equipements') return _BI_FIELDS_EQUIPS;
  if (_BIENS_IMPORT.target === 'biens')        return _BI_FIELDS_BIENS;
  // Mode auto : union des deux listes (sans doublons sur 'key')
  // On part des biens (qui contiennent les champs compta) + on ajoute les champs spécifiques équipements
  const seen = new Set(_BI_FIELDS_BIENS.map(f => f.key));
  const merged = [..._BI_FIELDS_BIENS];
  _BI_FIELDS_EQUIPS.forEach(f => {
    if (!seen.has(f.key)) { merged.push(f); seen.add(f.key); }
  });
  return merged;
}

// ══════════════ Charger SheetJS dynamiquement ══════════════
function _biLoadSheetJS() {
  if (window.XLSX) return Promise.resolve();
  return new Promise((resolve, reject) => {
    const s = document.createElement('script');
    s.src = 'https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js';
    s.onload = () => resolve();
    s.onerror = () => reject(new Error('Impossible de charger SheetJS depuis le CDN.'));
    document.head.appendChild(s);
  });
}

// ══════════════ ENTRÉE PRINCIPALE ══════════════
async function openImportBiens() {
  if (!canEdit()) { toast('Réservé aux Gestionnaires.', 'error'); return; }
  Object.assign(_BIENS_IMPORT, {
    target: 'auto',
    workbook: null, sheetNames: [], selectedSheet: null,
    rawRows: [], headerRowIdx: 0, headers: [], mapping: {},
    valueMap: { Statut: {}, Etat: {} }, dedupStrategy: 'update', step: 1,
  });
  _biRenderModal();
}

function _biRenderModal() {
  const html = '<div id="biImportContainer">' + _biRenderStep() + '</div>';
  if (typeof openModal === 'function') {
    openModal('📥 Importer des données', html, null);
    setTimeout(() => {
      // ⚠️ BUGFIX : openModal() utilise #modalOverlay, PAS #confirmModal.
      // Cibler #confirmModal masquait le footer de la modale de confirmation
      // (Annuler/Supprimer) de façon permanente via un style inline, ce qui
      // cassait toutes les confirmations de suppression dans l'app après un
      // passage par le wizard d'import (interventions, biens, etc.).
      const m = document.querySelector('#modalOverlay');
      if (m) {
        const footer = m.querySelector('.modal-footer');
        if (footer) footer.style.display = 'none';
        const modal = m.querySelector('.modal');
        if (modal) { modal.style.maxWidth = '900px'; modal.style.width = '95vw'; }
      }
    }, 50);
  }
}

function _biRender() {
  const cont = document.getElementById('biImportContainer');
  if (cont) cont.innerHTML = _biRenderStep();
}

function _biRenderStep() {
  const stepHtml =
    _BIENS_IMPORT.step === 1 ? _biRenderStep1() :
    _BIENS_IMPORT.step === 2 ? _biRenderStep2() :
    _BIENS_IMPORT.step === 3 ? _biRenderStep3() :
    _BIENS_IMPORT.step === 4 ? _biRenderStep4() : '';
  return _biRenderStepper() + stepHtml;
}

function _biRenderStepper() {
  const labels = ['1. Cible', '2. Fichier', '3. Correspondances', '4. Aperçu & import'];
  const cur = _BIENS_IMPORT.step;
  return '<div style="display:flex;gap:6px;margin-bottom:18px;font-size:11px">'
    + labels.map((l, i) => {
        const idx = i + 1;
        const active = idx === cur, done = idx < cur;
        const bg = active ? 'var(--blue)' : (done ? 'var(--blue-pale)' : 'var(--gray-bg)');
        const col = active ? '#fff' : (done ? 'var(--blue)' : 'var(--gray-text)');
        return '<div style="flex:1;padding:8px 6px;border-radius:6px;background:'+bg+';color:'+col+';font-weight:600;text-align:center;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">'+l+'</div>';
      }).join('')
    + '</div>';
}

// ══════════════ ÉTAPE 1 — Choix de la cible ══════════════
function _biRenderStep1() {
  const t = _BIENS_IMPORT.target;
  let html = '<div style="font-size:13px;color:var(--gray-text);margin-bottom:14px">Choisissez le mode de routage :</div>';
  html += '<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:18px">';

  // Carte AUTO (par défaut, recommandée)
  const autoActive = t === 'auto';
  html += '<div onclick="_biSetTarget(\'auto\')" style="cursor:pointer;padding:14px;border:2px solid '+(autoActive?'#16a34a':'var(--gray-border)')+';border-radius:10px;background:'+(autoActive?'#f0fdf4':'var(--white)')+';transition:all .15s;position:relative">';
  if (autoActive) html += '<div style="position:absolute;top:6px;right:6px;background:#16a34a;color:#fff;font-size:9px;padding:2px 6px;border-radius:8px;font-weight:700">RECOMMANDÉ</div>';
  html += '<div style="font-size:24px;text-align:center;margin-bottom:6px">🪄</div>';
  html += '<div style="font-weight:700;text-align:center;font-size:13px;color:'+(autoActive?'#16a34a':'var(--text)')+'">Auto-détection</div>';
  html += '<div style="font-size:11px;color:var(--gray-text);margin-top:6px;text-align:center;line-height:1.4">Chaque ligne est routée selon sa famille.</div>';
  html += '</div>';

  // Carte Biens
  const biensActive = t === 'biens';
  html += '<div onclick="_biSetTarget(\'biens\')" style="cursor:pointer;padding:14px;border:2px solid '+(biensActive?'var(--blue)':'var(--gray-border)')+';border-radius:10px;background:'+(biensActive?'var(--blue-pale)':'var(--white)')+';transition:all .15s">';
  html += '<div style="font-size:24px;text-align:center;margin-bottom:6px">🏢</div>';
  html += '<div style="font-weight:700;text-align:center;font-size:13px;color:'+(biensActive?'var(--blue)':'var(--text)')+'">Tout en Biens</div>';
  html += '<div style="font-size:11px;color:var(--gray-text);margin-top:6px;text-align:center;line-height:1.4">Force toutes les lignes en Biens.</div>';
  html += '</div>';

  // Carte Équipements
  const eqActive = t === 'equipements';
  html += '<div onclick="_biSetTarget(\'equipements\')" style="cursor:pointer;padding:14px;border:2px solid '+(eqActive?'var(--blue)':'var(--gray-border)')+';border-radius:10px;background:'+(eqActive?'var(--blue-pale)':'var(--white)')+';transition:all .15s">';
  html += '<div style="font-size:24px;text-align:center;margin-bottom:6px">⚙️</div>';
  html += '<div style="font-weight:700;text-align:center;font-size:13px;color:'+(eqActive?'var(--blue)':'var(--text)')+'">Tout en Équipements</div>';
  html += '<div style="font-size:11px;color:var(--gray-text);margin-top:6px;text-align:center;line-height:1.4">Force toutes les lignes en Équipements.</div>';
  html += '</div>';
  html += '</div>';

  // Détails du mode auto
  if (autoActive) {
    html += '<div style="padding:12px;background:#fef9c3;border:1px solid #eab308;border-radius:8px;margin-bottom:14px;font-size:12px;color:#713f12">';
    html += '<strong>🪄 Comment fonctionne l\'auto-détection ?</strong><br>';
    html += '• Famille <strong>CVC, Plomberie, Électricité, SSI, Chauffage, Ventilation…</strong> → ⚙️ Équipement<br>';
    html += '• Famille <strong>Mobilier, Informatique, Véhicule, Bureautique, Audio-Vidéo…</strong> → 🏢 Bien<br>';
    html += '• Les biens <strong>"sortis"</strong> (HU, SORTI, JETÉ, DON/VENDU…) restent en Biens avec leur état marqué comme tel.';
    html += '</div>';
  }

  html += '<div style="display:flex;justify-content:space-between;margin-top:20px;padding-top:14px;border-top:1px solid var(--gray-border)">';
  html += '<button class="btn btn-secondary" onclick="closeModal()">Annuler</button>';
  html += '<button class="btn btn-primary" onclick="_BIENS_IMPORT.step=2;_biRender()">Suivant ▶</button>';
  html += '</div>';
  return html;
}

function _biSetTarget(t) {
  if (_BIENS_IMPORT.target === t) return;
  _BIENS_IMPORT.target = t;
  if (_BIENS_IMPORT.rawRows && _BIENS_IMPORT.rawRows.length > 0) {
    _biUpdateHeaders();
  }
  _biRender();
}

// ══════════════ ÉTAPE 2 — Fichier + feuille ══════════════
function _biRenderStep2() {
  let html = '<div style="text-align:center;padding:24px 20px;border:2px dashed var(--gray-border);border-radius:10px;background:var(--gray-bg);margin-bottom:14px">';
  html += '<div style="font-size:28px;margin-bottom:8px">📂</div>';
  html += '<div style="font-size:13px;color:var(--text);margin-bottom:12px">Fichier <strong>Excel (.xlsx, .xls)</strong> ou <strong>CSV</strong></div>';
  html += '<input type="file" id="biFile" accept=".xlsx,.xls,.csv" onchange="_biFileSelected(event)" style="display:none">';
  html += '<button class="btn btn-primary" onclick="document.getElementById(\'biFile\').click()">📁 Choisir un fichier</button>';
  html += '<div id="biFileInfo" style="margin-top:12px;font-size:12px;color:var(--gray-text)"></div>';
  html += '</div>';

  if (_BIENS_IMPORT.sheetNames.length > 0) {
    html += '<div style="display:flex;gap:14px;margin-bottom:12px;align-items:end;flex-wrap:wrap">';
    html += '<div style="flex:1;min-width:200px"><label class="form-label">Feuille à importer</label>';
    html += '<select class="form-control" id="biSheet" onchange="_biSheetSelected(this.value)">';
    _BIENS_IMPORT.sheetNames.forEach(s => {
      html += '<option value="'+_esc(s)+'" '+(s===_BIENS_IMPORT.selectedSheet?'selected':'')+'>'+_esc(s)+'</option>';
    });
    html += '</select></div>';

    if (_BIENS_IMPORT.rawRows.length > 0) {
      html += '<div><label class="form-label">Ligne d\'en-tête</label>';
      html += '<select class="form-control" id="biHeaderRow" onchange="_biHeaderRowChanged(this.value)">';
      for (let i = 0; i < Math.min(5, _BIENS_IMPORT.rawRows.length); i++) {
        html += '<option value="'+i+'" '+(i===_BIENS_IMPORT.headerRowIdx?'selected':'')+'>Ligne '+(i+1)+'</option>';
      }
      html += '</select></div>';
    }
    html += '</div>';

    if (_BIENS_IMPORT.rawRows.length > 0) html += _biRenderPreview();
  }

  html += '<div style="display:flex;justify-content:space-between;margin-top:20px;padding-top:14px;border-top:1px solid var(--gray-border)">';
  html += '<button class="btn btn-secondary" onclick="_BIENS_IMPORT.step=1;_biRender()">◀ Retour</button>';
  html += '<button class="btn btn-primary" onclick="_biGoStep3()" '+(_BIENS_IMPORT.rawRows.length===0?'disabled':'')+'>Suivant ▶</button>';
  html += '</div>';
  return html;
}

function _biRenderPreview() {
  const preview = _BIENS_IMPORT.rawRows.slice(0, 6);
  if (preview.length === 0) return '';
  let html = '<div style="margin-bottom:10px">';
  html += '<div style="font-size:11px;color:var(--gray-text);margin-bottom:4px">Aperçu (6 premières lignes) — <strong>'+(_BIENS_IMPORT.rawRows.length)+' lignes au total</strong></div>';
  html += '<div style="overflow-x:auto;border:1px solid var(--gray-border);border-radius:6px;max-height:200px">';
  html += '<table style="width:100%;font-size:11px;border-collapse:collapse">';
  preview.forEach((row, ri) => {
    const isHeader = ri === _BIENS_IMPORT.headerRowIdx;
    html += '<tr style="'+(isHeader?'background:var(--blue-pale);font-weight:700':ri%2?'background:var(--gray-bg)':'')+'">';
    row.slice(0, 14).forEach(c => {
      html += '<td style="padding:4px 8px;border-right:1px solid var(--gray-border);max-width:140px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">'+_esc(String(c||''))+'</td>';
    });
    if (row.length > 14) html += '<td style="padding:4px 8px;color:var(--gray-text)">… +'+(row.length-14)+'</td>';
    html += '</tr>';
  });
  html += '</table></div></div>';
  return html;
}

async function _biFileSelected(evt) {
  const file = evt.target.files[0];
  if (!file) return;
  const info = document.getElementById('biFileInfo');
  info.textContent = '⏳ Lecture en cours...';
  try {
    await _biLoadSheetJS();
    const buf = await file.arrayBuffer();
    const wb = XLSX.read(buf, { type: 'array', cellDates: true });
    _BIENS_IMPORT.workbook = wb;
    _BIENS_IMPORT.sheetNames = wb.SheetNames;
    let bestSheet = wb.SheetNames[0];
    let bestScore = 0;
    wb.SheetNames.forEach(s => {
      const score = (s.toLowerCase().includes('actif') ? 100 : 0)
                  + (s.toLowerCase().includes('bien') ? 50 : 0)
                  + (s.toLowerCase().includes('équipe') || s.toLowerCase().includes('equipe') ? 50 : 0)
                  - (s.toLowerCase().includes('instruction') || s.toLowerCase().includes('mapping') || s.toLowerCase().includes('récap') ? 80 : 0);
      if (score > bestScore) { bestScore = score; bestSheet = s; }
    });
    info.innerHTML = '<span style="color:#16a34a">✅ '+_esc(file.name)+' chargé ('+wb.SheetNames.length+' feuille'+(wb.SheetNames.length>1?'s':'')+')</span>';
    _biSheetSelected(bestSheet);
  } catch(e) {
    info.innerHTML = '<span style="color:#e74c3c">❌ Erreur : '+_esc(e.message)+'</span>';
  }
}

function _biSheetSelected(name) {
  _BIENS_IMPORT.selectedSheet = name;
  const ws = _BIENS_IMPORT.workbook.Sheets[name];
  const arr = XLSX.utils.sheet_to_json(ws, { header: 1, defval: '', raw: false, dateNF: 'yyyy-mm-dd' });
  _BIENS_IMPORT.rawRows = arr.filter(r => r.some(c => c !== '' && c != null));
  let bestHeaderRow = 0;
  for (let i = 0; i < Math.min(5, _BIENS_IMPORT.rawRows.length); i++) {
    const row = _BIENS_IMPORT.rawRows[i];
    const nonEmpty = row.filter(c => String(c||'').trim().length > 0).length;
    const looksLikeHeader = row.some(c => /(famille|code|numero|num\u00e9ro|d\u00e9signation|designation|valeur|date)/i.test(String(c||'')));
    if (nonEmpty >= 3 && looksLikeHeader) { bestHeaderRow = i; break; }
  }
  _BIENS_IMPORT.headerRowIdx = bestHeaderRow;
  _biUpdateHeaders();
  _biRender();
}

function _biHeaderRowChanged(idx) {
  _BIENS_IMPORT.headerRowIdx = parseInt(idx);
  _biUpdateHeaders();
  _biRender();
}

/**
 * Construit la liste des colonnes du fichier avec auto-détection intelligente.
 * Pour chaque colonne, calcule :
 *   - label   : en-tête de la colonne
 *   - sample  : 3 valeurs d'aperçu
 *   - samples : tableau des 20 premières valeurs (pour analyse de contenu)
 *   - suggested : champ Larka suggéré
 *   - score   : 0-100 (confiance de la suggestion)
 *   - reasons : tableau des raisons qui ont mené à la suggestion (pour le tooltip)
 *
 * Le score combine : match du libellé (50 pts) + match du contenu (50 pts).
 */
function _biUpdateHeaders() {
  const headerRow = _BIENS_IMPORT.rawRows[_BIENS_IMPORT.headerRowIdx] || [];
  const fields = _biGetFields();
  _BIENS_IMPORT.mapping = {};
  _BIENS_IMPORT.headers = [];

  // Phase 1 : analyser chaque colonne, calculer un score pour chaque champ candidat
  const candidates = []; // [{ colIdx, label, samples, scores: { fieldKey: {score, reasons} } }]

  headerRow.forEach((label, idx) => {
    const lblTrim = String(label || '').trim();
    // Collecter 20 valeurs non vides
    const samples = [];
    for (let r = _BIENS_IMPORT.headerRowIdx + 1; r < _BIENS_IMPORT.rawRows.length && samples.length < 20; r++) {
      const v = String(_BIENS_IMPORT.rawRows[r][idx] || '').trim();
      if (v) samples.push(v);
    }
    const sampleStr = samples.slice(0, 3).filter((v, i, a) => a.indexOf(v) === i).join(' · ');

    // Calculer un score pour chaque champ
    const scores = {};
    fields.forEach(f => {
      const labelScore = _biScoreLabel(lblTrim, f);
      const contentScore = _biScoreContent(samples, f.key);
      const total = labelScore.score + contentScore.score;
      const reasons = [...labelScore.reasons, ...contentScore.reasons];
      if (total > 0) scores[f.key] = { score: total, reasons };
    });

    candidates.push({ idx, label: lblTrim || '(colonne ' + (idx+1) + ')', sample: sampleStr, samples, scores });
  });

  // Phase 2 : assigner globalement (algorithme glouton sur les scores)
  // On trie toutes les paires (colonne, champ) par score décroissant et on alloue
  const pairs = [];
  candidates.forEach(c => {
    Object.entries(c.scores).forEach(([fkey, info]) => {
      pairs.push({ colIdx: c.idx, fieldKey: fkey, score: info.score, reasons: info.reasons });
    });
  });
  pairs.sort((a, b) => b.score - a.score);

  const usedCols = new Set();
  const usedFields = new Set();
  const assignment = {}; // colIdx -> { fieldKey, score, reasons }

  // Seuil minimum pour qu'une suggestion soit retenue
  const MIN_SCORE = 30;

  for (const p of pairs) {
    if (p.score < MIN_SCORE) break;
    if (usedCols.has(p.colIdx) || usedFields.has(p.fieldKey)) continue;
    assignment[p.colIdx] = { fieldKey: p.fieldKey, score: p.score, reasons: p.reasons };
    usedCols.add(p.colIdx);
    usedFields.add(p.fieldKey);
  }

  // Phase 3 : construire _BIENS_IMPORT.headers et le mapping initial
  candidates.forEach(c => {
    const a = assignment[c.idx];
    const suggested = a ? a.fieldKey : '';
    _BIENS_IMPORT.headers.push({
      idx: c.idx,
      label: c.label,
      sample: c.sample,
      samples: c.samples,
      suggested,
      score: a ? a.score : 0,
      reasons: a ? a.reasons : [],
    });
    if (suggested) _BIENS_IMPORT.mapping[c.idx] = suggested;
  });
}

/**
 * Normalise un libellé : minuscules, sans accents, espaces réduits.
 * "N° d'Immobilisation" → "n d immobilisation"
 */
function _biNormalize(s) {
  return String(s || '')
    .toLowerCase()
    .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
    .replace(/[^a-z0-9\s]/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
}

/**
 * Score le libellé d'une colonne contre les hints d'un champ.
 *
 * Système :
 *  - exact   : match parfait (avec normalisation) → 50 pts
 *  - contains: tous les mots du hint sont présents → 30-40 pts
 *  - negative: si un mot négatif est présent ET pas dans le label complet attendu, on annule
 *
 * Retourne { score: 0..50, reasons: [string] }
 */
function _biScoreLabel(label, fieldDef) {
  if (!label) return { score: 0, reasons: [] };
  const norm = _biNormalize(label);
  const words = norm.split(' ').filter(Boolean);

  // 1. Vérifier les négatifs : si le label contient un mot négatif → score 0
  // Sauf si le label contient AUSSI un mot exact attendu (cas limite)
  const hasExactMatch = (fieldDef.exact || []).some(e => _biNormalize(e) === norm);
  if (!hasExactMatch && fieldDef.negative && fieldDef.negative.length > 0) {
    for (const neg of fieldDef.negative) {
      const negNorm = _biNormalize(neg);
      if (words.includes(negNorm)) {
        return { score: 0, reasons: ['exclu (contient "'+neg+'")'] };
      }
    }
  }

  let bestScore = 0, bestReason = '';

  // 2. Match exact (avec normalisation) → 50 pts
  for (const e of (fieldDef.exact || [])) {
    if (_biNormalize(e) === norm) {
      return { score: 50, reasons: ['libellé exact "'+e+'"'] };
    }
  }

  // 3. Contains : tous les mots du hint doivent apparaître dans le label
  for (const grp of (fieldDef.contains || [])) {
    const allMatch = grp.every(w => words.includes(_biNormalize(w)));
    if (allMatch) {
      const s = 30 + Math.min(10, grp.length * 5);
      if (s > bestScore) { bestScore = s; bestReason = 'contient ['+grp.join(', ')+']'; }
    }
  }

  // 4. Match exact partiel (si un hint exact est sous-chaîne du label normalisé)
  for (const e of (fieldDef.exact || [])) {
    const eNorm = _biNormalize(e);
    if (eNorm.length >= 4 && norm.includes(eNorm)) {
      const s = 35 + Math.min(10, eNorm.length / 2);
      if (s > bestScore) { bestScore = s; bestReason = 'contient le libellé "'+e+'"'; }
    }
  }

  return { score: bestScore, reasons: bestReason ? [bestReason] : [] };
}

/**
 * Score le contenu (échantillons) d'une colonne contre un champ donné.
 * Retourne { score: 0..50, reasons: [string] }
 *
 * Heuristiques :
 *  - Numero      : valeurs uniques courtes (ID alphanumérique)
 *  - Prix/Valeur : valeurs majoritairement numériques
 *  - DateXxx     : valeurs au format date
 *  - Statut      : valeurs courtes répétitives type UTILISE/DISPO
 *  - Etat        : valeurs courtes répétitives type ACTIF/SORTI
 *  - Famille     : valeurs courtes répétitives (Mobilier/Informatique...)
 */
function _biScoreContent(samples, fieldKey) {
  if (samples.length === 0) return { score: 0, reasons: [] };
  let score = 0;
  const reasons = [];

  // Helpers
  const isNumeric = (s) => /^-?\s*[\d\s]+([.,]\d+)?\s*€?\s*$/.test(s);
  const isDate = (s) => /^\d{4}-\d{1,2}-\d{1,2}/.test(s) || /^\d{1,2}\/\d{1,2}\/\d{2,4}/.test(s) || /^\d{1,2}-\d{1,2}-\d{2,4}/.test(s);
  const numericRatio = samples.filter(isNumeric).length / samples.length;
  const dateRatio    = samples.filter(isDate).length / samples.length;
  const distinctVals = new Set(samples).size;
  const uniqueRatio  = distinctVals / samples.length;
  const avgLen       = samples.reduce((s, v) => s + v.length, 0) / samples.length;

  // Numero : généralement très unique (ratio > 0.9), longueur modérée
  if (fieldKey === 'Numero') {
    if (uniqueRatio > 0.9 && avgLen < 30) {
      score = 30; reasons.push('valeurs uniques (probable identifiant)');
    }
  }

  // Champs numériques
  if (['Prix','_AmortCum','_VNC'].includes(fieldKey)) {
    if (numericRatio > 0.8) { score = 30; reasons.push(Math.round(numericRatio*100)+'% de valeurs numériques'); }
    else if (numericRatio > 0.5) { score = 15; reasons.push('majorité numérique'); }
  }

  // Champs date
  if (['DateCommande','DateLivraison','DateInstallation'].includes(fieldKey)) {
    if (dateRatio > 0.8) { score = 30; reasons.push(Math.round(dateRatio*100)+'% de valeurs date'); }
    else if (dateRatio > 0.5) { score = 15; reasons.push('majorité dates'); }
  }

  // Listes contrôlées (peu de valeurs distinctes répétées)
  if (['Statut','Etat','Famille','SousFamille'].includes(fieldKey)) {
    if (distinctVals <= 10 && samples.length >= 5 && avgLen < 30) {
      score = 20; reasons.push('valeurs catégorielles ('+distinctVals+' distinctes)');
      // Bonus spécifique : Statut
      if (fieldKey === 'Statut' && samples.some(s => /^(UTILISE|UTILIS\u00c9|DISPO|HU|STOCK|COMMAND|LIVRE)/i.test(s))) {
        score += 25; reasons.push('valeurs typiques de Statut');
      }
      // Bonus spécifique : Etat
      if (fieldKey === 'Etat' && samples.some(s => /^(ACTIF|SORTI|JET|DON|VENDU|HORS\s*SERVICE|A\s+(IMMOBILISER|SORTIR|ETIQUETER|REGULARISER))/i.test(s))) {
        score += 25; reasons.push('valeurs typiques d\'État');
      }
    }
  }

  // N° série, BC, n° immo : alphanumérique, plutôt unique
  if (['NumeroSerie','_BC','_NumeroImmo'].includes(fieldKey)) {
    if (uniqueRatio > 0.5 && avgLen >= 4 && avgLen < 25) {
      score = 15; reasons.push('format identifiant (alphanumérique)');
    }
  }

  return { score, reasons };
}

// ══════════════ ÉTAPE 3 — Mapping inversé : par colonne ══════════════
function _biGoStep3() {
  if (_BIENS_IMPORT.rawRows.length === 0) { toast('Aucune donnée à importer.', 'error'); return; }
  _biPrepareValueMaps();
  _BIENS_IMPORT.step = 3;
  _biRender();
}

function _biRenderStep3() {
  const fields = _biGetFields();
  const requiredKeys = fields.filter(f => f.required).map(f => f.key);
  const usedKeys = Object.values(_BIENS_IMPORT.mapping).filter(Boolean);
  const missingRequired = requiredKeys.filter(k => !usedKeys.includes(k));

  let html = '';
  if (missingRequired.length > 0) {
    const labels = missingRequired.map(k => fields.find(f => f.key === k)?.label || k);
    html += '<div style="padding:10px 14px;background:#fef9c3;border:1px solid #eab308;border-radius:8px;margin-bottom:14px;font-size:12px;color:#713f12">';
    html += '⚠️ <strong>Champs obligatoires non assignés :</strong> ' + labels.join(', ');
    html += '</div>';
  }

  html += '<div style="font-size:13px;color:var(--gray-text);margin-bottom:8px">';
  html += "Vérifiez les correspondances détectées automatiquement. Les colonnes inutiles peuvent rester sur « Ne pas importer ».";
  html += '</div>';

  // Compteur d'avancement avec indicateurs de confiance
  const totalCols = _BIENS_IMPORT.headers.length;
  const mappedCols = _BIENS_IMPORT.headers.filter(h => _BIENS_IMPORT.mapping[h.idx]).length;
  const highConfidence = _BIENS_IMPORT.headers.filter(h => _BIENS_IMPORT.mapping[h.idx] && (h.score >= 50)).length;
  const lowConfidence = mappedCols - highConfidence;

  html += '<div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;padding:8px 12px;background:var(--gray-bg);border-radius:8px;font-size:12px;flex-wrap:wrap">';
  html += '<span style="font-weight:600">'+mappedCols+' / '+totalCols+'</span> colonnes assignées';
  if (highConfidence > 0) html += '<span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600">🟢 '+highConfidence+' sûres</span>';
  if (lowConfidence > 0)  html += '<span style="background:#fef3c7;color:#854d0e;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600">🟡 '+lowConfidence+' à vérifier</span>';
  html += '<div style="flex:1;height:6px;background:var(--gray-border);border-radius:3px;overflow:hidden;min-width:80px"><div style="width:'+Math.round(mappedCols/totalCols*100)+'%;height:100%;background:linear-gradient(90deg,#16a34a 0%,#16a34a '+Math.round(highConfidence/totalCols*100)+'%,#eab308 '+Math.round(highConfidence/totalCols*100)+'%,#eab308 '+Math.round(mappedCols/totalCols*100)+'%);transition:width .2s"></div></div>';
  html += '<button onclick="_biAutoMap()" style="border:1px solid var(--gray-border);background:var(--white);color:var(--blue);cursor:pointer;font-size:12px;font-weight:600;padding:4px 10px;border-radius:6px">↺ Re-détecter</button>';
  html += '<button onclick="_biClearMapping()" style="border:1px solid var(--gray-border);background:var(--white);color:var(--gray-text);cursor:pointer;font-size:12px;padding:4px 10px;border-radius:6px">Tout effacer</button>';
  html += '</div>';

  // Tableau des colonnes avec code couleur
  html += '<div style="border:1px solid var(--gray-border);border-radius:8px;overflow:hidden;max-height:400px;overflow-y:auto">';
  html += '<table style="width:100%;border-collapse:collapse;font-size:12px">';
  html += '<thead style="position:sticky;top:0;background:var(--gray-bg);z-index:1"><tr>';
  html += '<th style="padding:8px 10px;text-align:center;font-weight:700;border-bottom:1px solid var(--gray-border);width:32px"></th>';
  html += '<th style="padding:8px 10px;text-align:left;font-weight:700;border-bottom:1px solid var(--gray-border);width:25%">Colonne du fichier</th>';
  html += '<th style="padding:8px 10px;text-align:left;font-weight:700;border-bottom:1px solid var(--gray-border);width:30%">Aperçu</th>';
  html += '<th style="padding:8px 10px;text-align:left;font-weight:700;border-bottom:1px solid var(--gray-border)">Champ Larka</th>';
  html += '</tr></thead><tbody>';

  const usedSet = new Set(Object.values(_BIENS_IMPORT.mapping).filter(Boolean));

  _BIENS_IMPORT.headers.forEach(h => {
    const cur = _BIENS_IMPORT.mapping[h.idx] || '';
    const matchedField = fields.find(f => f.key === cur);
    const isRequired = matchedField?.required;
    const score = h.score || 0;
    const reasonTitle = (h.reasons || []).map(r => '• ' + r).join('\n') || 'Aucune correspondance détectée';

    // Couleur selon la confiance (uniquement si on a une suggestion)
    let bgRow = '', icon = '⚪', iconTitle = 'Non détecté';
    if (cur && score >= 50) { bgRow = 'background:#f0fdf4'; icon = '🟢'; iconTitle = 'Détection sûre (score '+score+')'; }
    else if (cur && score >= 30) { bgRow = 'background:#fffbeb'; icon = '🟡'; iconTitle = 'À vérifier (score '+score+')'; }
    else if (cur) { bgRow = 'background:#fff'; icon = '🔵'; iconTitle = 'Choix manuel'; }
    else { bgRow = 'background:#fafafa'; icon = '⚪'; iconTitle = 'Non assigné'; }

    html += '<tr style="border-bottom:1px solid var(--gray-bg);'+bgRow+'">';
    html += '<td style="padding:8px 10px;text-align:center" title="'+_esc(iconTitle + (h.reasons && h.reasons.length ? '\n\n' + reasonTitle : ''))+'">'+icon+'</td>';
    html += '<td style="padding:8px 10px;font-weight:600;color:var(--text)">'+_esc(h.label)+'</td>';
    html += '<td style="padding:8px 10px;color:var(--gray-text);font-style:italic;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="'+_esc((h.samples||[]).slice(0,10).join('\n'))+'">'+(h.sample?_esc(h.sample):'<span style="opacity:.5">(vide)</span>')+'</td>';
    html += '<td style="padding:6px 10px">';
    html += '<select class="form-control" style="font-size:12px;padding:4px 8px;'+(isRequired?'border-color:var(--blue);background:var(--blue-pale)':'')+'" onchange="_biSetMapping('+h.idx+',this.value)">';
    html += '<option value="">— Ne pas importer —</option>';
    fields.forEach(f => {
      const used = usedSet.has(f.key) && f.key !== cur;
      const tag = f.compta ? ' (compta)' : '';
      const star = f.required ? ' *' : '';
      html += '<option value="'+f.key+'" '+(cur===f.key?'selected':'')+(used?' style="color:#999"':'')+'>'+_esc(f.label)+star+tag+(used?' — déjà utilisé':'')+'</option>';
    });
    html += '</select>';
    html += '</td>';
    html += '</tr>';
  });
  html += '</tbody></table></div>';

  // Mapping de valeurs (Statut + État) si mappés
  const statutCol = Object.entries(_BIENS_IMPORT.mapping).find(([_, v]) => v === 'Statut')?.[0];
  const etatCol   = Object.entries(_BIENS_IMPORT.mapping).find(([_, v]) => v === 'Etat')?.[0];
  if (statutCol !== undefined) html += _biRenderValueMapBlock('Statut', 'Statut', parseInt(statutCol), _biGetGmaoStatuts());
  if (etatCol !== undefined)   html += _biRenderValueMapBlock('Etat',   'État',   parseInt(etatCol),   _biGetGmaoEtats());

  // Stratégie de doublon
  html += '<div style="margin-top:14px;padding:12px;background:var(--gray-bg);border-radius:8px">';
  html += '<div style="font-weight:600;margin-bottom:6px;font-size:13px">🔁 Si un numéro existe déjà</div>';
  html += '<label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:12px;margin-bottom:4px"><input type="radio" name="biDedup" value="update" '+(_BIENS_IMPORT.dedupStrategy==='update'?'checked':'')+' onchange="_BIENS_IMPORT.dedupStrategy=this.value"> Mettre à jour les fiches existantes</label>';
  html += '<label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:12px"><input type="radio" name="biDedup" value="skip" '+(_BIENS_IMPORT.dedupStrategy==='skip'?'checked':'')+' onchange="_BIENS_IMPORT.dedupStrategy=this.value"> Ignorer (créer uniquement les nouveaux)</label>';
  html += '</div>';

  html += '<div style="display:flex;justify-content:space-between;margin-top:18px;padding-top:14px;border-top:1px solid var(--gray-border)">';
  html += '<button class="btn btn-secondary" onclick="_BIENS_IMPORT.step=2;_biRender()">◀ Retour</button>';
  html += '<button class="btn btn-primary" onclick="_biGoStep4()" '+(missingRequired.length?'disabled title="Champs obligatoires manquants"':'')+'>Aperçu ▶</button>';
  html += '</div>';
  return html;
}

function _biClearMapping() {
  _BIENS_IMPORT.mapping = {};
  _BIENS_IMPORT.headers.forEach(h => { h.score = 0; h.reasons = []; });
  _biRender();
}

function _biSetMapping(colIdx, fieldKey) {
  // Si on assigne un champ déjà utilisé ailleurs, on le délie de son ancienne colonne
  if (fieldKey) {
    Object.entries(_BIENS_IMPORT.mapping).forEach(([col, v]) => {
      if (v === fieldKey && parseInt(col) !== colIdx) delete _BIENS_IMPORT.mapping[col];
    });
    _BIENS_IMPORT.mapping[colIdx] = fieldKey;
  } else {
    delete _BIENS_IMPORT.mapping[colIdx];
  }
  _biRender();
}

function _biAutoMap() { _biUpdateHeaders(); _biRender(); }

function _biRenderValueMapBlock(field, label, colIdx, gmaoVals) {
  const distinct = _biGetDistinctValues(colIdx);
  if (distinct.length === 0) return '';
  let html = '<div style="margin-top:14px;padding:12px;background:var(--blue-pale);border:1px solid var(--blue);border-radius:8px">';
  html += '<div style="font-weight:600;margin-bottom:6px;font-size:13px">🎯 Correspondance des valeurs « '+label+' »</div>';
  html += '<div style="font-size:11px;color:var(--gray-text);margin-bottom:8px">Faites correspondre les valeurs de votre fichier aux valeurs Larka (laissez vide pour conserver telles quelles).</div>';
  html += '<div style="display:grid;grid-template-columns:1fr auto 1fr;gap:6px;align-items:center;font-size:12px">';
  distinct.forEach(v => {
    const cur = _BIENS_IMPORT.valueMap[field][v] || '';
    html += '<div style="padding:4px 8px;background:var(--white);border-radius:4px;border:1px solid var(--gray-border)"><strong>'+_esc(v)+'</strong></div>';
    html += '<div style="text-align:center;color:var(--gray-text)">→</div>';
    html += '<select class="form-control" style="font-size:12px;padding:4px 8px" onchange="_BIENS_IMPORT.valueMap.'+field+'[\''+v.replace(/'/g,"\\'")+'\']=this.value">';
    html += '<option value="">— Garder « '+_esc(v)+' » —</option>';
    gmaoVals.forEach(g => {
      html += '<option value="'+_esc(g)+'" '+(cur===g?'selected':'')+'>'+_esc(g)+'</option>';
    });
    html += '</select>';
  });
  html += '</div></div>';
  return html;
}

function _biGetDistinctValues(colIdx) {
  const set = new Set();
  for (let i = _BIENS_IMPORT.headerRowIdx + 1; i < _BIENS_IMPORT.rawRows.length; i++) {
    const v = String(_BIENS_IMPORT.rawRows[i][colIdx] || '').trim();
    if (v) set.add(v);
  }
  return [...set].sort();
}

function _biGetGmaoStatuts() {
  return _BIENS_IMPORT.target === 'equipements'
    ? ['Commande','Livre','Installe','En reparation']
    : ['Commande','Livre','Stock','Utilise','En reparation'];
}
function _biGetGmaoEtats() {
  return _BIENS_IMPORT.target === 'equipements'
    ? ['Stock','Utilise','Hors service','Sorti']
    : ['Stock','Utilise','Jete/Recycle','Don/Vendu'];
}

function _biPrepareValueMaps() {
  const auto = {
    Statut: {
      'UTILISE': 'Utilise', 'UTILISÉ': 'Utilise',
      'DISPO': 'Stock', 'DISPONIBLE': 'Stock',
      'HU': 'Utilise', 'P/PIECES': 'Stock',
      'COMMANDE': 'Commande', 'COMMANDÉ': 'Commande',
      'LIVRE': 'Livre', 'LIVRÉ': 'Livre',
      'INSTALLE': 'Installe', 'INSTALLÉ': 'Installe',
    },
    Etat: {
      'ACTIF': 'Utilise', 'STOCK': 'Stock',
      'A IMMOBILISER': 'Stock', 'À IMMOBILISER': 'Stock',
      'A SORTIR': 'Utilise', 'À SORTIR': 'Utilise',
      'A ETIQUETER': 'Utilise', 'À ÉTIQUETER': 'Utilise',
      'A REGULARISER': 'Utilise', 'À RÉGULARISER': 'Utilise',
      'ATTENTE SORTIE SDAF': 'Utilise',
      'SORTI': _BIENS_IMPORT.target === 'equipements' ? 'Sorti' : 'Jete/Recycle',
      'JETE': 'Jete/Recycle', 'JETÉ': 'Jete/Recycle', 'RECYCLE': 'Jete/Recycle',
      'DON': 'Don/Vendu', 'VENDU': 'Don/Vendu',
      'HORS SERVICE': 'Hors service',
    },
  };
  ['Statut','Etat'].forEach(field => {
    const colIdx = Object.entries(_BIENS_IMPORT.mapping).find(([_, v]) => v === field)?.[0];
    if (colIdx === undefined) return;
    _biGetDistinctValues(parseInt(colIdx)).forEach(v => {
      if (!_BIENS_IMPORT.valueMap[field][v]) {
        const k = v.toUpperCase().trim();
        if (auto[field][k]) _BIENS_IMPORT.valueMap[field][v] = auto[field][k];
      }
    });
  });
}

// ══════════════ ÉTAPE 4 — Aperçu et import ══════════════
function _biGoStep4() {
  const fields = _biGetFields();
  const requiredKeys = fields.filter(f => f.required).map(f => f.key);
  const usedKeys = Object.values(_BIENS_IMPORT.mapping).filter(Boolean);
  const missing = requiredKeys.filter(k => !usedKeys.includes(k));
  if (missing.length > 0) {
    const labels = missing.map(k => fields.find(f => f.key === k)?.label || k);
    toast('Champs obligatoires non assignés : ' + labels.join(', '), 'error');
    return;
  }
  _BIENS_IMPORT.step = 4;
  _biRender();
  _biRunDryRun();
}

function _biRenderStep4() {
  return '<div id="biPreviewArea" style="min-height:200px"><div style="text-align:center;padding:40px;color:var(--gray-text)">⏳ Validation en cours…</div></div>';
}

/**
 * Effectue un POST JSON avec gestion d'erreur détaillée.
 * Si le serveur renvoie autre chose que du JSON (page d'erreur 500, timeout, etc.),
 * on récupère le texte brut et on retourne une erreur structurée.
 *
 * Retourne { ok: true, data } ou { ok: false, error, status, body, hint }
 */
async function _biApiPost(payload) {
  let res;
  try {
    res = await fetch('api/index.php?action=import_biens', {
      method: 'POST', credentials: 'include',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(payload)
    });
  } catch(e) {
    return { ok: false, error: 'Erreur réseau : ' + e.message, hint: 'Vérifiez votre connexion. Si le payload est très lourd, le serveur a peut-être coupé la connexion.' };
  }

  const status = res.status;
  const ctype = res.headers.get('content-type') || '';
  let body = '';
  try { body = await res.text(); } catch(_e) {}

  // Cas idéal : JSON
  if (ctype.includes('application/json') || (body.startsWith('{') || body.startsWith('['))) {
    try {
      const json = JSON.parse(body);
      if (json.success === false) {
        return { ok: false, error: json.error || 'Erreur serveur (code ' + status + ')', status, body };
      }
      return { ok: true, data: json.data };
    } catch(_e) {
      // JSON malformé
    }
  }

  // Cas d'erreur : déterminer une cause probable
  let hint = '';
  const lcBody = body.toLowerCase();
  if (status === 0)        hint = 'Pas de réponse du serveur. Probablement un timeout, un crash, ou une coupure réseau.';
  else if (status === 401) hint = 'Session expirée — reconnectez-vous.';
  else if (status === 403) hint = 'Permission refusée — vérifiez votre rôle (Admin ou Gestionnaire requis).';
  else if (status === 404) hint = 'Route inexistante — la version du serveur n\'a peut-être pas la nouvelle route. Mettez à jour les fichiers PHP.';
  else if (status === 413) hint = 'Payload trop gros pour le serveur. Augmentez post_max_size, upload_max_filesize, et client_max_body_size (Nginx).';
  else if (status === 502 || status === 504) hint = 'Le serveur PHP a coupé (timeout ou crash). Augmentez max_execution_time et memory_limit, ou essayez avec moins de lignes.';
  else if (status === 500) {
    if (lcBody.includes('memory') || lcBody.includes('allowed memory'))         hint = 'Mémoire PHP insuffisante. Augmentez memory_limit (256M ou 512M).';
    else if (lcBody.includes('maximum execution time') || lcBody.includes('time limit')) hint = 'Timeout PHP. Augmentez max_execution_time.';
    else if (lcBody.includes('sqlstate') || lcBody.includes('pdo'))             hint = 'Erreur base de données. Voir le détail ci-dessous.';
    else hint = 'Erreur PHP côté serveur. Voir le détail ci-dessous.';
  }
  else if (status >= 200 && status < 300 && body.trim() === '') hint = 'Réponse vide. Le serveur a peut-être planté en silence.';

  // Extraire le 1er message d'erreur PHP s'il y en a (pour aider au diagnostic)
  let phpErr = '';
  const errMatch = body.match(/<b>(?:Fatal error|Parse error|Warning|Notice)<\/b>[\s\S]{0,500}?in\s*<b>([^<]+)<\/b>\s*on line\s*<b>(\d+)/i);
  if (errMatch) phpErr = errMatch[0].replace(/<\/?b>/g, '').replace(/\s+/g, ' ').substring(0, 300);

  return {
    ok: false,
    status,
    error: phpErr || ('Erreur serveur ' + status + (status ? '' : ' (pas de réponse)')),
    body: body.substring(0, 2000),
    hint,
  };
}

/**
 * Affiche une erreur d'API de façon claire dans le panneau aperçu.
 */
function _biShowApiError(result, context) {
  const c = document.getElementById('biPreviewArea');
  if (!c) return;
  let html = '<div style="padding:14px;background:#fee;border:1px solid #e74c3c;border-radius:8px;margin-bottom:12px">';
  html += '<div style="font-weight:700;color:#c00;margin-bottom:8px;font-size:14px">❌ Échec de l\'import</div>';
  html += '<div style="font-size:13px;margin-bottom:10px"><strong>Étape :</strong> ' + _esc(context) + '</div>';
  if (result.status) html += '<div style="font-size:12px;margin-bottom:6px"><strong>Code HTTP :</strong> ' + result.status + '</div>';
  html += '<div style="font-size:12px;margin-bottom:6px"><strong>Erreur :</strong> ' + _esc(result.error) + '</div>';
  if (result.hint) html += '<div style="font-size:12px;margin-bottom:8px;padding:8px 10px;background:#fff;border-radius:6px;color:#854d0e"><strong>💡 Cause probable :</strong> ' + _esc(result.hint) + '</div>';
  if (result.body) {
    html += '<details style="margin-top:8px"><summary style="cursor:pointer;font-size:11px;color:#666">Détails techniques (réponse brute du serveur)</summary>';
    html += '<pre style="font-size:10px;background:#fff;padding:8px;border-radius:4px;max-height:250px;overflow:auto;margin-top:6px;white-space:pre-wrap;word-break:break-all">' + _esc(result.body) + '</pre>';
    html += '</details>';
  }
  html += '</div>';
  html += '<div style="display:flex;justify-content:space-between;margin-top:12px"><button class="btn btn-secondary" onclick="_BIENS_IMPORT.step=3;_biRender()">◀ Retour au mapping</button></div>';
  c.innerHTML = html;
}

async function _biRunDryRun() {
  const items = _biBuildPayload();
  const res = await _biApiPost({ dryRun: true, target: _BIENS_IMPORT.target, biens: items, dedup: _BIENS_IMPORT.dedupStrategy });
  if (!res.ok) { _biShowApiError(res, 'Validation (étape "Aperçu")'); return; }
  _biRenderPreviewResult(res.data, items);
}

function _biRenderPreviewResult(result, items) {
  const c = document.getElementById('biPreviewArea');
  if (!c) return;
  const isAuto = _BIENS_IMPORT.target === 'auto';
  const targetLabel = _BIENS_IMPORT.target === 'equipements' ? 'équipements'
                    : (isAuto ? 'enregistrements' : 'biens');
  let html = '<div style="margin-bottom:14px">';
  html += '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:14px">';
  html += '<div style="padding:14px;background:var(--blue-pale);border-radius:8px;text-align:center"><div style="font-size:24px;font-weight:700;color:var(--blue)">'+result.toCreate+'</div><div style="font-size:11px;color:var(--gray-text)">à créer</div></div>';
  html += '<div style="padding:14px;background:#fef9c3;border-radius:8px;text-align:center"><div style="font-size:24px;font-weight:700;color:#a16207">'+result.toUpdate+'</div><div style="font-size:11px;color:var(--gray-text)">à mettre à jour</div></div>';
  html += '<div style="padding:14px;background:var(--gray-bg);border-radius:8px;text-align:center"><div style="font-size:24px;font-weight:700;color:var(--gray-text)">'+result.toSkip+'</div><div style="font-size:11px;color:var(--gray-text)">ignorés</div></div>';
  html += '<div style="padding:14px;background:'+(result.errors.length?'#fee':'var(--gray-bg)')+';border-radius:8px;text-align:center"><div style="font-size:24px;font-weight:700;color:'+(result.errors.length?'#e74c3c':'var(--gray-text)')+'">'+result.errors.length+'</div><div style="font-size:11px;color:var(--gray-text)">erreurs</div></div>';
  html += '</div>';

  // Récap de routage en mode auto
  if (isAuto && (result.routedBiens > 0 || result.routedEquipements > 0)) {
    html += '<div style="padding:12px;background:#f0fdf4;border:1px solid #16a34a;border-radius:8px;margin-bottom:12px">';
    html += '<div style="font-weight:600;margin-bottom:8px;font-size:13px;color:#166534">🪄 Routage automatique</div>';
    html += '<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;font-size:12px">';
    html += '<div style="padding:8px;background:#fff;border-radius:6px;text-align:center"><div style="font-size:20px;font-weight:700;color:var(--blue)">🏢 '+result.routedBiens+'</div><div style="font-size:11px;color:var(--gray-text)">vers Biens</div></div>';
    html += '<div style="padding:8px;background:#fff;border-radius:6px;text-align:center"><div style="font-size:20px;font-weight:700;color:#9333ea">⚙️ '+result.routedEquipements+'</div><div style="font-size:11px;color:var(--gray-text)">vers Équipements</div></div>';
    html += '<div style="padding:8px;background:#fff;border-radius:6px;text-align:center"><div style="font-size:20px;font-weight:700;color:#dc2626">🗃️ '+(result.sortis||0)+'</div><div style="font-size:11px;color:var(--gray-text)">marqués sortis</div></div>';
    html += '</div></div>';
  }

  if (result.listesToCreate && Object.keys(result.listesToCreate).length > 0) {
    html += '<div style="padding:12px;background:var(--blue-pale);border:1px solid var(--blue);border-radius:8px;margin-bottom:12px">';
    html += '<div style="font-weight:600;margin-bottom:6px;font-size:13px">📋 Nouvelles valeurs à créer dans les listes config</div>';
    Object.entries(result.listesToCreate).forEach(([cat, vals]) => {
      html += '<div style="font-size:12px;margin-top:4px"><strong>'+_esc(cat)+'</strong> : '+vals.map(v => '<span class="badge badge-blue" style="font-size:10px">'+_esc(v)+'</span>').join(' ')+'</div>';
    });
    html += '</div>';
  }

  // Info sur les doublons internes suffixés
  if (result.duplicatesSuffixed && result.duplicatesSuffixed > 0) {
    html += '<div style="padding:10px 14px;background:#fef9c3;border:1px solid #eab308;border-radius:8px;margin-bottom:12px;font-size:12px;color:#713f12">';
    html += 'ℹ️ <strong>'+result.duplicatesSuffixed+' doublon(s) interne(s)</strong> au fichier détecté(s). Ils seront automatiquement suffixés (ex. "9205-2", "9205-3") pour préserver toutes les lignes.';
    html += '</div>';
  }

  if (result.errors.length > 0) {
    html += '<div style="padding:12px;background:#fee;border:1px solid #e74c3c;border-radius:8px;margin-bottom:12px">';
    html += '<div style="font-weight:600;margin-bottom:6px;font-size:13px;color:#c00">⚠️ Erreurs détectées (lignes correspondantes ignorées)</div>';
    html += '<div style="max-height:200px;overflow-y:auto;font-size:11px;font-family:monospace">';
    result.errors.slice(0, 50).forEach(err => {
      const num = err.numero ? ' (n° '+_esc(err.numero)+')' : '';
      html += '<div style="padding:4px 0;border-bottom:1px solid #fcc"><strong>L'+err.row+'</strong>'+num+' : '+_esc(err.message)+'</div>';
    });
    if (result.errors.length > 50) html += '<div style="padding:3px 0;color:var(--gray-text)">… et '+(result.errors.length-50)+' autre(s)</div>';
    html += '</div></div>';
  }

  html += '<div style="font-size:13px;font-weight:600;margin-bottom:6px">Aperçu (5 premiers) :</div>';
  html += '<div style="max-height:200px;overflow-y:auto;border:1px solid var(--gray-border);border-radius:6px;margin-bottom:14px">';
  html += '<table style="width:100%;font-size:11px;border-collapse:collapse">';
  html += '<tr style="background:var(--gray-bg);font-weight:700"><td style="padding:6px 8px">N°</td><td style="padding:6px 8px">Famille</td><td style="padding:6px 8px">Désignation</td><td style="padding:6px 8px">État</td><td style="padding:6px 8px">Prix</td><td style="padding:6px 8px">Site</td></tr>';
  items.slice(0, 5).forEach(b => {
    html += '<tr>';
    html += '<td style="padding:4px 8px">'+_esc(b.numero||'')+'</td>';
    html += '<td style="padding:4px 8px">'+_esc(b.famille||'')+'</td>';
    html += '<td style="padding:4px 8px;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'+_esc(b.infoProduit||'')+'</td>';
    html += '<td style="padding:4px 8px">'+_esc(b.etat||'')+'</td>';
    html += '<td style="padding:4px 8px">'+_esc(String(b.prix||'0'))+' €</td>';
    html += '<td style="padding:4px 8px">'+_esc(b.batiment||'')+'</td>';
    html += '</tr>';
  });
  html += '</table></div>';
  html += '</div>';

  html += '<div style="display:flex;justify-content:space-between;margin-top:20px;padding-top:14px;border-top:1px solid var(--gray-border)">';
  html += '<button class="btn btn-secondary" onclick="_BIENS_IMPORT.step=3;_biRender()">◀ Retour</button>';
  if (result.toCreate + result.toUpdate > 0) {
    html += '<button class="btn btn-primary" onclick="_biRunImport()">✅ Importer ('+(result.toCreate+result.toUpdate)+' '+targetLabel+')</button>';
  } else {
    html += '<button class="btn btn-primary" disabled>Aucun à importer</button>';
  }
  html += '</div>';
  c.innerHTML = html;
}

async function _biRunImport() {
  const c = document.getElementById('biPreviewArea');
  if (!c) return;
  const items = _biBuildPayload();
  const total = items.length;
  const BATCH_SIZE = 500;     // Lots de 500 biens pour éviter timeouts/limits
  const useBatches = total > BATCH_SIZE;

  // Affichage barre de progression
  const renderProgress = (done, msg) => {
    const pct = total > 0 ? Math.round(done / total * 100) : 0;
    c.innerHTML = '<div style="text-align:center;padding:30px">'
      + '<div style="font-size:24px;margin-bottom:10px">⏳</div>'
      + '<div style="font-size:14px;font-weight:600;margin-bottom:14px">'+(msg || 'Import en cours…')+'</div>'
      + '<div style="background:var(--gray-bg);border-radius:8px;height:24px;overflow:hidden;margin:0 auto;max-width:400px;position:relative">'
      + '<div style="background:linear-gradient(90deg,var(--blue) 0%,#16a34a 100%);height:100%;width:'+pct+'%;transition:width .3s"></div>'
      + '<div style="position:absolute;top:0;left:0;right:0;bottom:0;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;mix-blend-mode:difference;color:#fff">'+done+' / '+total+' ('+pct+'%)</div>'
      + '</div>'
      + (useBatches ? '<div style="font-size:11px;color:var(--gray-text);margin-top:10px">Découpage en lots de '+BATCH_SIZE+' pour éviter les timeouts</div>' : '<div style="font-size:11px;color:var(--gray-text);margin-top:8px">Cela peut prendre quelques secondes…</div>')
      + '</div>';
  };

  // Cumul des résultats sur tous les lots
  const totals = { created: 0, updated: 0, failed: 0, comptaCreated: 0, listesAdded: 0, errors: [] };

  if (!useBatches) {
    // Petit volume : un seul appel
    renderProgress(0);
    const res = await _biApiPost({ dryRun: false, target: _BIENS_IMPORT.target, biens: items, dedup: _BIENS_IMPORT.dedupStrategy });
    if (!res.ok) { _biShowApiError(res, 'Import'); return; }
    Object.assign(totals, res.data);
    renderProgress(total, 'Terminé');
  } else {
    // Gros volume : batches successifs
    const batches = [];
    for (let i = 0; i < total; i += BATCH_SIZE) batches.push(items.slice(i, i + BATCH_SIZE));
    let done = 0;
    for (let bi = 0; bi < batches.length; bi++) {
      renderProgress(done, 'Lot '+(bi+1)+' / '+batches.length+' ('+batches[bi].length+' lignes)…');
      const res = await _biApiPost({ dryRun: false, target: _BIENS_IMPORT.target, biens: batches[bi], dedup: _BIENS_IMPORT.dedupStrategy });
      if (!res.ok) {
        // Stop au premier lot en erreur, mais montrer ce qui a déjà été importé
        _biShowApiError(res, 'Lot '+(bi+1)+'/'+batches.length+' (lignes '+(done+1)+'-'+(done+batches[bi].length)+')');
        return;
      }
      const d = res.data;
      totals.created       += d.created || 0;
      totals.updated       += d.updated || 0;
      totals.failed        += d.failed || 0;
      totals.comptaCreated += d.comptaCreated || 0;
      totals.listesAdded   += d.listesAdded || 0;
      if (Array.isArray(d.errors)) totals.errors.push(...d.errors);
      done += batches[bi].length;
      renderProgress(done);
      // Petite pause pour ne pas surcharger
      await new Promise(r => setTimeout(r, 50));
    }
  }

  // Affichage du résultat final
  const r = totals;
  const targetLabel = _BIENS_IMPORT.target === 'equipements' ? 'équipements' : 'biens';
  let html = '<div style="text-align:center;padding:30px">';
  html += '<div style="font-size:48px;margin-bottom:10px">🎉</div>';
  html += '<div style="font-size:18px;font-weight:700;margin-bottom:14px">Import terminé !</div>';
  html += '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:20px;max-width:500px;margin-left:auto;margin-right:auto">';
  html += '<div style="padding:14px;background:var(--blue-pale);border-radius:8px"><div style="font-size:24px;font-weight:700;color:var(--blue)">'+r.created+'</div><div style="font-size:11px">créés</div></div>';
  html += '<div style="padding:14px;background:#fef9c3;border-radius:8px"><div style="font-size:24px;font-weight:700;color:#a16207">'+r.updated+'</div><div style="font-size:11px">mis à jour</div></div>';
  html += '<div style="padding:14px;background:'+(r.failed?'#fee':'var(--gray-bg)')+';border-radius:8px"><div style="font-size:24px;font-weight:700;color:'+(r.failed?'#e74c3c':'var(--gray-text)')+'">'+r.failed+'</div><div style="font-size:11px">échecs</div></div>';
  html += '</div>';
  if (r.comptaCreated) html += '<div style="font-size:12px;color:var(--gray-text);margin-bottom:10px">+ '+r.comptaCreated+' entrée(s) en gestion matériel</div>';
  if (r.listesAdded)   html += '<div style="font-size:12px;color:var(--gray-text);margin-bottom:14px">+ '+r.listesAdded+' valeur(s) ajoutée(s) dans les listes</div>';

  // Afficher les erreurs s'il y en a
  if (r.errors && r.errors.length > 0) {
    html += '<details style="text-align:left;max-width:600px;margin:0 auto 16px;background:#fee;border:1px solid #fcc;border-radius:6px;padding:8px"><summary style="cursor:pointer;font-size:12px;color:#c00;font-weight:600">⚠️ '+r.errors.length+' erreur(s) — voir le détail</summary>';
    html += '<div style="max-height:200px;overflow-y:auto;font-size:11px;font-family:monospace;margin-top:8px">';
    r.errors.slice(0, 100).forEach(err => {
      const num = err.numero ? ' (n° '+_esc(err.numero)+')' : '';
      html += '<div style="padding:3px 0">L'+err.row+num+' : '+_esc(err.message)+'</div>';
    });
    if (r.errors.length > 100) html += '<div style="padding:3px 0;color:#666">… et '+(r.errors.length-100)+' autre(s)</div>';
    html += '</div></details>';
  }

  const reloadFn = _BIENS_IMPORT.target === 'equipements' ? 'renderEquipements' : 'renderBiens';
  html += '<button class="btn btn-primary" onclick="closeModal();typeof '+reloadFn+'===\'function\'&&'+reloadFn+'()">Fermer & rafraîchir</button>';
  html += '</div>';
  c.innerHTML = html;
  if (typeof toast === 'function') toast('Import : '+r.created+' créés, '+r.updated+' mis à jour', 'success');
}

// ══════════════ Construction du payload ══════════════
function _biBuildPayload() {
  const out = [];
  const inverse = {};
  Object.entries(_BIENS_IMPORT.mapping).forEach(([col, field]) => {
    if (field) inverse[field] = parseInt(col);
  });
  const startRow = _BIENS_IMPORT.headerRowIdx + 1;
  const isAuto = _BIENS_IMPORT.target === 'auto';

  for (let i = startRow; i < _BIENS_IMPORT.rawRows.length; i++) {
    const row = _BIENS_IMPORT.rawRows[i];
    const get = (key) => {
      const idx = inverse[key];
      if (idx === undefined) return '';
      return String(row[idx] || '').trim();
    };
    const mapVal = (field, raw) => {
      if (!raw) return raw;
      return _BIENS_IMPORT.valueMap[field]?.[raw] || raw;
    };
    const numero = get('Numero').replace(/\.0+$/, '');
    if (!numero) continue;

    const famille = get('Famille');

    // Déterminer la cible pour cette ligne (auto = par famille, sinon target global)
    const target = isAuto ? _biGuessTarget(famille) : _BIENS_IMPORT.target;

    // Construire InfoProduit (en mode auto/biens, on fusionne Marque/Modèle/Fournisseur dedans)
    let info = get('InfoProduit');
    if (target === 'biens') {
      const marque = get('_Marque') || get('Marque');
      const modele = get('_Modele') || get('Modele');
      const fournisseur = get('_Fournisseur') || get('Fournisseur');
      const suffix = [marque, modele].filter(Boolean).join(' ');
      if (suffix) info = info ? info + ' (' + suffix + ')' : suffix;
      if (fournisseur) info = info ? info + ' — ' + fournisseur : fournisseur;
    }

    const statut = mapVal('Statut', get('Statut'));
    let etat = mapVal('Etat', get('Etat'));

    // Si la ligne est "sortie" (HU, SORTI, JETÉ…), forcer un état explicite côté Biens
    // (pour que ça apparaisse comme tel dans la liste sans aller en historique)
    const sorti = _biIsSorti({ statut: get('Statut'), etat: get('Etat') });
    if (sorti && target === 'biens') {
      // Conserver l'état mappé s'il existe déjà (Jete/Recycle, Don/Vendu),
      // sinon le forcer à 'Jete/Recycle' par défaut
      if (!etat || etat === 'Stock' || etat === 'Utilise') etat = 'Jete/Recycle';
    }

    const item = {
      _row: i + 1,
      _targetGuess: target,        // 'biens' ou 'equipements' (le backend l'utilise en mode auto)
      _isSorti: sorti,             // info pour le récap dans l'aperçu
      numero,
      famille,
      sousFamille: get('SousFamille'),
      infoProduit: info,
      numeroSerie: get('NumeroSerie'),
      batiment: get('Batiment'),
      nomPrenom: get('NomPrenom'),
      statut,
      etat,
      dateCommande: _biParseDate(get('DateCommande')),
      dateLivraison: _biParseDate(get('DateLivraison')),
      prix: _biParseNumber(get('Prix')),
    };

    if (target === 'equipements') {
      item.marque = get('Marque') || get('_Marque');
      item.modele = get('Modele') || get('_Modele');
      item.fournisseur = get('Fournisseur') || get('_Fournisseur');
      item.etage = get('Etage');
      item.numeroBureau = get('NumeroBureau');
      item.observations = get('Observations');
      item.dateInstallation = _biParseDate(get('DateInstallation'));
    } else {
      // Biens : champs compta
      item._NumeroImmo = get('_NumeroImmo').replace(/\.0+$/, '');
      item._AmortCum = _biParseNumber(get('_AmortCum'));
      item._VNC = _biParseNumber(get('_VNC'));
      item._BC = get('_BC').replace(/\.0+$/, '');
    }

    out.push(item);
  }
  return out;
}

function _biParseDate(s) {
  if (!s) return null;
  s = String(s).trim();
  if (/^\d{4}-\d{2}-\d{2}/.test(s)) return s.substring(0, 10);
  let m = s.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})/);
  if (m) return m[3] + '-' + m[2].padStart(2,'0') + '-' + m[1].padStart(2,'0');
  m = s.match(/^(\d{1,2})-(\d{1,2})-(\d{4})/);
  if (m) return m[3] + '-' + m[2].padStart(2,'0') + '-' + m[1].padStart(2,'0');
  if (/^\d+$/.test(s) && parseInt(s) > 25000) {
    const d = new Date((parseInt(s) - 25569) * 86400 * 1000);
    if (!isNaN(d.getTime())) return d.toISOString().substring(0, 10);
  }
  return null;
}

function _biParseNumber(s) {
  if (!s) return 0;
  const n = parseFloat(String(s).replace(/\s/g, '').replace(',', '.'));
  return isNaN(n) ? 0 : n;
}

// ══════════════ Wrapper pour démarrer l'import directement en mode Équipements ══════════════
function openImportEquipements() {
  if (!canEdit()) { toast('Réservé aux Gestionnaires.', 'error'); return; }
  Object.assign(_BIENS_IMPORT, {
    target: 'equipements',
    workbook: null, sheetNames: [], selectedSheet: null,
    rawRows: [], headerRowIdx: 0, headers: [], mapping: {},
    valueMap: { Statut: {}, Etat: {} }, dedupStrategy: 'update', step: 1,
  });
  _biRenderModal();
}

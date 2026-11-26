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
 * Larka — Import du stock depuis Excel / CSV
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Même assistant, mêmes outils et même présentation que l'import des biens,
 * des équipements et des interventions (biens-import.js lit le fichier), en
 * trois étapes :
 *
 *   1. Fichier          : Excel ou CSV (lu dans un worker), feuille, ligne
 *                         d'en-tête détectée, disposition, aperçu ; modèle
 *   2. Correspondances  : chaque colonne → un champ de l'article (titre +
 *                         contenu + mémoire du dernier import), ou une case
 *                         complémentaire des fiches ; catégories du fichier →
 *                         catégories de Larka ; article déjà là ; quantités
 *                         remplacées (inventaire) ou ajoutées (livraison)
 *   3. Aperçu & import  : le serveur analyse sans rien écrire, rapport
 *                         détaillé et téléchargeable, puis import par lots
 *
 * Les règles de fond sont celles du serveur (api/ImportStock.php) : l'aperçu
 * montre exactement ce que l'import fera.
 * ═══════════════════════════════════════════════════════════════════════════════
 */

const _SI = {
  step: 1, workbook: null, sheetNames: [], selectedSheet: null, rawRows: [], refs: [],
  orientation: 'lignes', orientationChoisie: false, planningPossible: false,
  headerRowIdx: 0, headers: [], mapping: {}, valueMap: { categorie: {} },
  dedup: 'update', quantites: 'remplacer',
  categories: [], cases: [], nouvellesCases: {},
  items: [], apercu: null, nePasCreer: new Set(), dernierRapport: null,
};
const _SI_MEMOIRE = 'larka.import.stock.correspondances';
const _SI_LOT = 500;

/**
 * Champs d'un article, décrits comme ceux des autres imports :
 *   exact    : titres de colonne qui désignent sûrement ce champ
 *   contains : groupes de mots qui, tous présents, le désignent probablement
 *   negative : mots qui excluent le champ (sauf titre exact)
 *   contenu  : nature attendue des valeurs (nombre, montant, categorie, texte, identifiant)
 */
const _SI_CHAMPS = [
  { key: 'reference', label: 'Référence', required: true, contenu: 'identifiant',
    exact: ['reference', 'ref', 'ref article', 'reference article', 'code article', 'code', 'n article', 'numero article', 'sku', 'code produit', 'reference fournisseur', 'ref fournisseur', 'ref interne', 'code interne'],
    contains: [['reference'], ['ref'], ['code', 'article'], ['code', 'produit']], negative: ['date', 'prix', 'contrat', 'designation'] },
  // Désignation : exigée d'un NOUVEL article seulement (le serveur le vérifie) ;
  // un bon de livraison « référence + quantité » met à jour les articles déjà là.
  { key: 'designation', label: 'Désignation', contenu: 'texte',
    exact: ['designation', 'libelle', 'description', 'nom', 'intitule', 'produit', 'article', 'libelle article', 'designation article', 'nom article', 'nom du produit'],
    contains: [['designation'], ['libelle'], ['description'], ['intitule']], negative: ['categorie', 'famille', 'type'] },
  { key: 'categorie', label: 'Catégorie', contenu: 'categorie',
    exact: ['categorie', 'famille', 'categorie stock', 'classe', 'rayon', 'nature', 'type de stock'], contains: [['categorie'], ['famille']] },
  { key: 'marque', label: 'Marque', exact: ['marque', 'fabricant', 'constructeur', 'brand'], contains: [['marque'], ['fabricant']] },
  { key: 'modele', label: 'Modèle', exact: ['modele', 'model', 'gamme', 'serie'], contains: [['modele']] },
  { key: 'typeArticle', label: "Type d'article",
    exact: ['type', 'type article', 'type d article', 'technologie', 'sous type', 'variante'], contains: [['type', 'article'], ['technologie'], ['variante']], negative: ['categorie', 'stock'] },
  { key: 'quantite', label: 'Quantité', contenu: 'nombre',
    exact: ['quantite', 'qte', 'stock', 'quantite en stock', 'stock actuel', 'qte stock', 'nombre', 'nb', 'quantite disponible', 'inventaire', 'qte disponible', 'en stock'],
    contains: [['quantite'], ['qte'], ['stock']], negative: ['seuil', 'mini', 'minimum', 'alerte', 'prix', 'valeur', 'emplacement'] },
  { key: 'seuilAlerte', label: "Seuil d'alerte", contenu: 'nombre',
    exact: ['seuil', 'seuil alerte', 'seuil d alerte', 'stock mini', 'stock minimum', 'minimum', 'mini', 'qte mini', 'point de commande', 'alerte', 'stock de securite'],
    contains: [['seuil'], ['mini'], ['minimum'], ['alerte'], ['securite']] },
  { key: 'prixUnitaire', label: 'Prix unitaire (€)', contenu: 'montant',
    exact: ['prix', 'prix unitaire', 'pu', 'prix ht', 'pu ht', 'cout unitaire', 'prix achat', 'prix d achat', 'tarif', 'prix unitaire ht'],
    contains: [['prix'], ['pu'], ['cout'], ['tarif']], negative: ['total', 'valeur', 'ttc'] },
  { key: 'emplacement', label: 'Emplacement',
    exact: ['emplacement', 'localisation', 'lieu', 'magasin', 'etagere', 'rangement', 'casier', 'armoire', 'zone', 'stockage', 'lieu de stockage'],
    contains: [['emplacement'], ['localisation'], ['rangement'], ['stockage']] },
  { key: 'fournisseur', label: 'Fournisseur', exact: ['fournisseur', 'distributeur', 'vendeur', 'grossiste', 'fournisseur habituel'], contains: [['fournisseur']] },
  { key: 'source', label: 'Source (Achat / Contrat)', contenu: 'categorie',
    exact: ['source', 'provenance', 'origine', 'approvisionnement'], contains: [['source'], ['provenance'], ['approvisionnement']] },
  { key: 'contrat', label: 'Contrat (n°, référence ou société)',
    exact: ['contrat', 'n contrat', 'numero contrat', 'marche', 'reference contrat'], contains: [['contrat'], ['marche']], negative: ['date', 'montant'] },
];

const _siN = s => (typeof _biNormalize === 'function' ? _biNormalize(s) : String(s || '').toLowerCase().trim());

// ══════════════ Ouverture ══════════════

async function openImportStock() {
  if (typeof canEdit === 'function' && !canEdit()) { toast('Réservé aux Gestionnaires.', 'error'); return; }
  if (typeof _biAnnulerLecture === 'function') _biAnnulerLecture();
  Object.assign(_SI, {
    step: 1, workbook: null, sheetNames: [], selectedSheet: null, rawRows: [], refs: [],
    orientation: 'lignes', orientationChoisie: false, planningPossible: false,
    headerRowIdx: 0, headers: [], mapping: {}, valueMap: { categorie: {} },
    dedup: 'update', quantites: 'remplacer', nouvellesCases: {},
    items: [], apercu: null, nePasCreer: new Set(), dernierRapport: null,
  });
  try { _SI.categories = ((await ListesApi.getByCategorie('CategorieStock')) || []).map(c => c.Valeur); } catch (_) { _SI.categories = []; }
  try { _SI.cases = (typeof ChampsPerso !== 'undefined' ? await ChampsPerso.charger('stock', true) : []) || []; } catch (_) { _SI.cases = []; }
  openModal('Importer du stock', '<div id="siImportContainer">' + _siRenderStep() + '</div>', null);
  setTimeout(() => {
    const m = document.querySelector('#modalOverlay');
    if (m) {
      const footer = m.querySelector('.modal-footer');
      if (footer) footer.style.display = 'none';
      const modal = m.querySelector('.modal');
      if (modal) { modal.style.maxWidth = '900px'; modal.style.width = '95vw'; }
    }
  }, 50);
}

function _siRender() {
  const c = document.getElementById('siImportContainer');
  if (c) c.innerHTML = _siRenderStep();
}
function _siRenderStep() {
  const s = _SI.step;
  return _siStepper() + (s === 1 ? _siStep1() : s === 2 ? _siStep2() : _siStep3());
}
function _siStepper() {
  return '<div style="display:flex;gap:6px;margin-bottom:18px;font-size:11px">'
    + ['1. Fichier', '2. Correspondances', '3. Aperçu & import'].map((l, i) => {
        const idx = i + 1, a = idx === _SI.step, fait = idx < _SI.step;
        return '<div style="flex:1;padding:8px 6px;border-radius:6px;background:' + (a ? 'var(--blue)' : fait ? 'var(--blue-pale)' : 'var(--gray-bg)')
          + ';color:' + (a ? '#fff' : fait ? 'var(--blue)' : 'var(--gray-text)') + ';font-weight:600;text-align:center">' + l + '</div>';
      }).join('') + '</div>';
}
function _siPied(retour, suivant, actif = true, libelle = 'Suivant ▶') {
  return '<div style="display:flex;justify-content:space-between;margin-top:20px;padding-top:14px;border-top:1px solid var(--gray-border)">'
    + (retour ? '<button class="btn btn-secondary" onclick="' + retour + '">◀ Retour</button>' : '<button class="btn btn-secondary" onclick="closeModal()">Annuler</button>')
    + '<button class="btn btn-primary" onclick="' + suivant + '" ' + (actif ? '' : 'disabled') + '>' + libelle + '</button></div>';
}

// ══════════════ ÉTAPE 1 — Fichier ══════════════

function _siStep1() {
  let html = '<div style="text-align:center;padding:24px 20px;border:2px dashed var(--gray-border);border-radius:10px;background:var(--gray-bg);margin-bottom:14px">'
    + '<div style="font-size:28px;margin-bottom:8px">' + lk('folder-open') + '</div>'
    + '<div style="font-size:13px;color:var(--text);margin-bottom:12px">Fichier <strong>Excel (.xlsx, .xls)</strong> ou <strong>CSV</strong> : un article par ligne</div>'
    + '<input type="file" id="siFile" accept=".xlsx,.xls,.csv" onchange="_siFichierChoisi(event)" style="display:none">'
    + '<button class="btn btn-primary" onclick="document.getElementById(\'siFile\').click()">' + lk('folder') + ' Choisir un fichier</button> '
    + '<button class="btn btn-ghost" onclick="_siModele()" title="Un fichier CSV avec les colonnes reconnues et deux exemples">' + lk('download') + ' Télécharger un modèle</button>'
    + '<div id="siFileInfo" style="margin-top:12px;font-size:12px;color:var(--gray-text)"></div></div>';
  if (_SI.sheetNames.length) {
    html += '<div style="display:flex;gap:14px;margin-bottom:12px;align-items:end;flex-wrap:wrap">'
      + '<div style="flex:1;min-width:200px"><label class="form-label">Feuille à importer</label><select class="form-control" onchange="_siFeuilleChoisie(this.value)">'
      + _SI.sheetNames.map(s => '<option value="' + _esc(s) + '" ' + (s === _SI.selectedSheet ? 'selected' : '') + '>' + _esc(s) + '</option>').join('') + '</select></div>';
    if (_SI.rawRows.length) {
      html += '<div><label class="form-label">' + (_SI.orientation === 'colonnes' ? 'Colonne des titres' : 'Ligne d\'en-tête') + '</label><select class="form-control" onchange="_SI.headerRowIdx=parseInt(this.value,10)||0;_siMajEntetes();_siRender()">';
      for (let i = 0; i < Math.min(20, _SI.rawRows.length); i++) {
        const ap = (_SI.rawRows[i] || []).map(c => String(c || '').trim()).filter(Boolean).slice(0, 3).join(' · ');
        html += '<option value="' + i + '" ' + (i === _SI.headerRowIdx ? 'selected' : '') + '>' + _esc(_biRefLong(_SI.orientation, _SI.refs[i] || i + 1)) + (ap ? ' — ' + _esc(ap.substring(0, 50)) : '') + '</option>';
      }
      html += '</select></div>';
    }
    html += '</div>' + _biOrientationHtml(_SI, '_siSetOrientation');
    if (_SI.rawRows.length) html += _siApercuFichier();
  }
  return html + _siPied(null, '_siAllerEtape2()', _SI.rawRows.length > 0);
}

function _siApercuFichier() {
  const debut = Math.max(0, _SI.headerRowIdx - 1), lignes = _SI.rawRows.slice(debut, debut + 6);
  const nb = Math.max(0, _SI.rawRows.length - _SI.headerRowIdx - 1);
  let html = '<div style="font-size:11px;color:var(--gray-text);margin-bottom:4px">Aperçu autour des titres (en bleu) — <strong>' + nb + ' article(s)</strong></div>'
    + '<div style="overflow-x:auto;border:1px solid var(--gray-border);border-radius:6px;max-height:200px"><table style="width:100%;font-size:11px;border-collapse:collapse">';
  lignes.forEach((row, k) => {
    const ri = debut + k, entete = ri === _SI.headerRowIdx;
    html += '<tr style="' + (entete ? 'background:var(--blue-pale);font-weight:700' : ri % 2 ? 'background:var(--gray-bg)' : '') + '">'
      + row.slice(0, 14).map(c => '<td style="padding:4px 8px;border-right:1px solid var(--gray-border);max-width:140px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + _esc(String(c == null ? '' : c)) + '</td>').join('')
      + (row.length > 14 ? '<td style="padding:4px 8px;color:var(--gray-text)">… +' + (row.length - 14) + '</td>' : '') + '</tr>';
  });
  return html + '</table></div>';
}

async function _siFichierChoisi(evt) {
  const file = evt.target.files[0];
  if (!file) return;
  _biAnnulerLecture();
  const jeton = _biJetonLecture;
  Object.assign(_SI, { workbook: null, sheetNames: [], selectedSheet: null, rawRows: [], refs: [], headerRowIdx: 0, headers: [], mapping: {},
                       nouvellesCases: {}, orientationChoisie: false });
  _siRender();
  const afficher = h => { const el = document.getElementById('siFileInfo'); if (el) el.innerHTML = h; };
  afficher(lk('hourglass') + ' Lecture en cours...');
  try {
    const buf = await file.arrayBuffer();
    if (jeton !== _biJetonLecture) return;
    const wb = await _biLireClasseur(buf, undefined, file.name);
    if (jeton !== _biJetonLecture) return;
    _SI.workbook = wb; _SI.sheetNames = wb.SheetNames;
    // Feuille la plus prometteuse : son nom parle de stock, d'articles, d'inventaire.
    let best = wb.SheetNames[0], bestScore = -1;
    wb.SheetNames.forEach(s => {
      const n = _siN(s);
      const sc = (/stock|article|inventaire|piece|consommable|magasin|fourniture/.test(n) ? 60 : 0) - (/instruction|mode d emploi|parametre|recap|liste des/.test(n) ? 80 : 0);
      if (sc > bestScore) { bestScore = sc; best = s; }
    });
    _siFeuilleChoisie(best);
    afficher('<span style="color:#16a34a">' + lk('circle-check', 'ok') + ' ' + _esc(file.name) + ' chargé (' + wb.SheetNames.length + ' feuille' + (wb.SheetNames.length > 1 ? 's' : '') + ')</span>');
  } catch (e) {
    if ((e && e.annulee) || jeton !== _biJetonLecture) return;
    afficher('<span style="color:#e74c3c">' + lk('circle-x', 'ko') + ' Erreur : ' + _esc(e.message) + '</span>');
  }
}

function _siFeuilleChoisie(nom) {
  _SI.selectedSheet = nom;
  const brutes = (_SI.workbook.feuilles.get(nom) || []).map(r => r.slice());
  if (!_SI.orientationChoisie) _SI.orientation = _biOrientationProbable(brutes, _siEstTitre);
  const f = _biPreparerFeuille(brutes, _SI.orientation);
  _SI.rawRows = f.rows; _SI.refs = f.refs;
  _SI.headerRowIdx = _siDetecterEntete(_SI.rawRows);
  _siMajEntetes();
  _siRender();
}
function _siSetOrientation(o) {
  _SI.orientation = o === 'colonnes' ? 'colonnes' : 'lignes';
  _SI.orientationChoisie = true;
  if (_SI.workbook && _SI.selectedSheet) _siFeuilleChoisie(_SI.selectedSheet); else _siRender();
}
function _siEstTitre(c) {
  if (_biNombre(c) !== null || _biParseDate(c)) return false;
  return _SI_CHAMPS.some(f => _siScoreTitre(c, f).score >= 30);
}

/** Ligne d'en-tête : libellés reconnus, peu de nombres, des données dessous (même règle que les autres imports). */
function _siDetecterEntete(rows) {
  let best = 0, bestScore = -Infinity;
  for (let i = 0; i < Math.min(20, rows.length); i++) {
    const cells = rows[i].map(c => String(c == null ? '' : c).trim()), remplies = cells.filter(Boolean);
    if (remplies.length < 2) continue;
    const textes = remplies.filter(c => _biNombre(c) === null && !_biParseDate(c));
    const reconnus = remplies.filter(c => _SI_CHAMPS.some(f => _siScoreTitre(c, f).score >= 30)).length;
    let dessous = 0;
    for (let j = i + 1; j < Math.min(rows.length, i + 6); j++) {
      if (cells.filter((c, idx) => c && String(rows[j][idx] == null ? '' : rows[j][idx]).trim()).length >= Math.max(1, remplies.length / 2)) dessous++;
    }
    const score = remplies.length * 2 + textes.length * 2 + reconnus * 12 + dessous * 4 - (remplies.length - textes.length) * 4;
    if (score > bestScore) { bestScore = score; best = i; }
  }
  return best;
}

/** Modèle : les titres reconnus d'emblée, et deux lignes d'exemple. */
function _siModele() {
  const cols = ['Référence', 'Désignation', 'Catégorie', 'Marque', 'Modèle', "Type d'article", 'Quantité', "Seuil d'alerte", 'Prix unitaire', 'Emplacement', 'Fournisseur', 'Contrat'];
  const ex = [['LUM-001', 'Ampoule LED E27 10W', 'Consommable', 'Philips', 'CorePro', 'LED', '24', '6', '3,90', 'Armoire A, étagère 2', 'Rexel', ''],
              ['FIL-120', 'Filtre plissé G4 592x592', 'Piece', 'Camfil', '', '', '8', '4', '12,50', 'Local CTA', '', 'CT-2026-04']];
  const q = v => /[;"\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v;
  _siTelecharger('﻿' + [cols, ...ex].map(r => r.map(q).join(';')).join('\r\n'), 'modele-import-stock.csv');
}
function _siTelecharger(contenu, nom) {
  const a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob([contenu], { type: 'text/csv;charset=utf-8' }));
  a.download = nom;
  document.body.appendChild(a); a.click();
  setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
}

// ══════════════ Détection des correspondances ══════════════

/** Score d'un titre de colonne pour un champ (0 à 50), avec les débuts de mots (« qte », « quant »). */
function _siScoreTitre(label, f) {
  const norm = _siN(label);
  if (!norm) return { score: 0, reasons: [] };
  const mots = norm.split(' ').filter(Boolean);
  const a = w => mots.some(m => m === w || (w.length >= 4 && m.startsWith(w)));
  if ((f.exact || []).some(e => _siN(e) === norm)) return { score: 50, reasons: ['libellé exact « ' + label + ' »'] };
  for (const neg of (f.negative || [])) if (mots.includes(_siN(neg))) return { score: 0, reasons: ['exclu (contient « ' + neg + ' »)'] };
  let best = 0, raison = '';
  for (const g of (f.contains || [])) {
    if (g.every(w => a(_siN(w)))) { const s = 30 + Math.min(10, g.length * 5); if (s > best) { best = s; raison = 'contient [' + g.join(', ') + ']'; } }
  }
  return { score: best, reasons: raison ? [raison] : [] };
}

/** Score du contenu d'une colonne pour un champ (0 à 40). */
function _siScoreContenu(samples, f) {
  if (!samples.length || !f.contenu) return { score: 0, reasons: [] };
  const n = samples.length, part = t => samples.filter(t).length / n;
  const distincts = new Set(samples.map(v => _siN(v))).size, lg = samples.reduce((t, v) => t + v.length, 0) / n;
  switch (f.contenu) {
    case 'nombre': { const p = part(v => /^\d+([.,]0+)?$/.test(v.replace(/\s/g, ''))); return p > .8 ? { score: 30, reasons: [Math.round(p * 100) + ' % de nombres entiers'] } : { score: 0, reasons: [] }; }
    case 'montant': {
      const p = part(v => _biNombre(v) !== null && /\d/.test(v));
      let s = p > .8 ? 25 : 0; const r = s ? [Math.round(p * 100) + ' % de nombres'] : [];
      if (s && samples.some(v => /€|eur/i.test(v) || /[.,]\d{2}$/.test(v))) { s += 10; r.push('format monétaire'); }
      return { score: s, reasons: r };
    }
    case 'categorie': return distincts <= 12 && n >= 3 && lg <= 30 ? { score: 15, reasons: ['valeurs catégorielles (' + distincts + ' distinctes)'] } : { score: 0, reasons: [] };
    case 'texte': return lg > 12 && distincts / n > .6 ? { score: 20, reasons: ['libellés variés'] } : { score: 0, reasons: [] };
    case 'identifiant': return distincts / n > .8 && lg < 25 && samples.some(v => /\d/.test(v)) ? { score: 15, reasons: ['codes avec chiffres'] } : { score: 0, reasons: [] };
  }
  return { score: 0, reasons: [] };
}

function _siMemoireLire() { try { const m = JSON.parse(localStorage.getItem(_SI_MEMOIRE) || '{}'); return (m && typeof m === 'object') ? m : {}; } catch (_) { return {}; } }
function _siMemoireEcrire() {
  try {
    const m = _siMemoireLire();
    _SI.headers.forEach(h => {
      const k = _siN(h.label);
      if (!k || /^\(?colonne \d+\)?$/.test(k)) return;
      const v = _SI.mapping[h.idx];
      if (v && v.startsWith('cp+:')) { const c = _siCleCase(v); if (c) m[k] = 'cp:' + c; else delete m[k]; }
      else if (v) m[k] = v; else delete m[k];
    });
    const cles = Object.keys(m);
    if (cles.length > 300) cles.slice(0, cles.length - 300).forEach(k => delete m[k]);
    localStorage.setItem(_SI_MEMOIRE, JSON.stringify(m));
  } catch (_) { /* navigation privée, stockage plein : sans conséquence */ }
}

/** Colonnes du fichier et correspondances proposées : titre + contenu + mémoire, attribution gloutonne. */
function _siMajEntetes() {
  const entete = _SI.rawRows[_SI.headerRowIdx] || [];
  _SI.mapping = {}; _SI.headers = []; _SI.nouvellesCases = {};
  let largeur = entete.length;
  for (let r = _SI.headerRowIdx + 1; r < Math.min(_SI.rawRows.length, _SI.headerRowIdx + 200); r++) largeur = Math.max(largeur, (_SI.rawRows[r] || []).length);
  const memoire = _siMemoireLire(), vus = {}, candidats = [];
  for (let idx = 0; idx < largeur; idx++) {
    let lib = String(entete[idx] == null ? '' : entete[idx]).trim();
    const samples = [];
    for (let r = _SI.headerRowIdx + 1; r < _SI.rawRows.length && samples.length < 40; r++) {
      const v = String(_SI.rawRows[r][idx] == null ? '' : _SI.rawRows[r][idx]).trim();
      if (v) samples.push(v);
    }
    if (!lib && !samples.length) continue;
    const c = lib.toLowerCase();
    if (lib && vus[c]) { vus[c]++; lib += ' (' + vus[c] + ')'; } else if (lib) vus[c] = 1;
    const souvenir = memoire[_siN(lib)], scores = {};
    _SI_CHAMPS.forEach(f => {
      const st = _siScoreTitre(lib, f), sc = _siScoreContenu(samples, f);
      if (lib && st.score === 0) sc.score = Math.floor(sc.score / 2);
      let t = st.score + sc.score; const reasons = [...st.reasons, ...sc.reasons];
      if (souvenir === f.key) { t += 45; reasons.unshift('choisi lors de votre dernier import'); }
      if (t > 0) scores[f.key] = { score: t, reasons };
    });
    // Cases des fiches : même nom exact (55) ou choisie au dernier import (+45).
    _SI.cases.forEach(d => {
      let t = 0; const reasons = [];
      if (lib && _siN(d.libelle) === _siN(lib)) { t += 55; reasons.push('même nom qu\'une case des fiches'); }
      if (souvenir === 'cp:' + d.cle) { t += 45; reasons.unshift('choisi lors de votre dernier import'); }
      if (t) scores['cp:' + d.cle] = { score: t, reasons };
    });
    candidats.push({ idx, label: lib || '(colonne ' + (idx + 1) + ')', sample: samples.slice(0, 3).filter((v, i, a) => a.indexOf(v) === i).join(' · '), samples, scores });
  }
  const paires = [];
  candidats.forEach(c => Object.entries(c.scores).forEach(([k, i]) => paires.push({ col: c.idx, k, score: i.score, reasons: i.reasons })));
  paires.sort((a, b) => b.score - a.score);
  const colsPrises = new Set(), champsPris = new Set(), attrib = {};
  for (const p of paires) {
    if (p.score < 30) break;
    if (colsPrises.has(p.col) || champsPris.has(p.k)) continue;
    attrib[p.col] = p; colsPrises.add(p.col); champsPris.add(p.k);
  }
  candidats.forEach(c => {
    const a = attrib[c.idx];
    _SI.headers.push({ idx: c.idx, label: c.label, sample: c.sample, samples: c.samples, score: a ? a.score : 0, reasons: a ? a.reasons : [] });
    if (a) _SI.mapping[c.idx] = a.k;
  });
}

function _siColonne(k) { const e = Object.entries(_SI.mapping).find(([, v]) => v === k); return e ? parseInt(e[0], 10) : null; }

/** Catégories du fichier → catégories de Larka (les choix déjà faits sont gardés). */
function _siPreparerCategories() {
  const col = _siColonne('categorie'), anc = _SI.valueMap.categorie || {};
  _SI.valueMap.categorie = {};
  if (col === null) return;
  const vals = new Set();
  for (let i = _SI.headerRowIdx + 1; i < _SI.rawRows.length; i++) { const v = String(_SI.rawRows[i][col] == null ? '' : _SI.rawRows[i][col]).trim(); if (v) vals.add(v); }
  [...vals].sort((a, b) => a.localeCompare(b, 'fr')).slice(0, 80).forEach(v => {
    _SI.valueMap.categorie[v] = anc[v] !== undefined ? anc[v] : (_SI.categories.find(c => _siN(c) === _siN(v)) || '');
  });
}

// ══════════════ Cases complémentaires ══════════════
// Une colonne du fichier sans champ Larka devient une CASE des fiches du stock
// (section « Informations complémentaires »). Valeur de correspondance :
// « cp:<clé> » (case existante) ou « cp+:<colonne> » (case créée par cet import).

function _siCleCase(k) {
  if (!k) return null;
  if (k.startsWith('cp+:')) { const n = _SI.nouvellesCases[k.slice(4)]; return n ? n.cle : null; }
  return k.startsWith('cp:') ? k.slice(3) : null;
}
function _siTypeCase(k) {
  if (k.startsWith('cp+:')) { const n = _SI.nouvellesCases[k.slice(4)]; return n ? n.type : 'texte'; }
  const d = _SI.cases.find(x => x.cle === k.slice(3));
  return d ? d.type : 'texte';
}
function _siLibelleCase(k) {
  if (k.startsWith('cp+:')) { const n = _SI.nouvellesCases[k.slice(4)]; return n ? n.libelle : k; }
  const d = _SI.cases.find(x => x.cle === k.slice(3));
  return d ? d.libelle : k.slice(3);
}
function _siCreerCase(col) {
  const h = _SI.headers.find(x => x.idx === col);
  if (!h) return;
  const libelle = String(h.label || '').replace(/ \(\d+\)$/, '').replace(/^\(colonne (\d+)\)$/, 'Colonne $1').trim().substring(0, 80) || ('Colonne ' + (col + 1));
  let cle = ChampsPerso.cle(libelle);
  const existante = _SI.cases.find(d => d.cle === cle || _siN(d.libelle) === _siN(libelle));
  if (existante) { _siChoisir(col, 'cp:' + existante.cle, true); return; }
  const prises = new Set([..._SI.cases.map(d => d.cle), ...Object.entries(_SI.nouvellesCases).filter(([c]) => parseInt(c, 10) !== col).map(([, n]) => n.cle)]);
  const base = cle; let i = 2;
  while (prises.has(cle)) cle = base.substring(0, 56) + '_' + (i++);
  _SI.nouvellesCases[col] = { cle, libelle, type: typeof _biTypeCase === 'function' ? _biTypeCase(h.samples) : 'texte' };
  _SI.mapping[col] = 'cp+:' + col;
}
function _siRenommerCase(col, libelle) {
  const n = _SI.nouvellesCases[col];
  libelle = String(libelle || '').trim().substring(0, 80);
  if (!n || !libelle) return;
  n.libelle = libelle;
  const prises = new Set([..._SI.cases.map(d => d.cle), ...Object.entries(_SI.nouvellesCases).filter(([c]) => parseInt(c, 10) !== col).map(([, x]) => x.cle)]);
  let cle = ChampsPerso.cle(libelle); const base = cle; let i = 2;
  while (prises.has(cle)) cle = base.substring(0, 56) + '_' + (i++);
  n.cle = cle;
}
function _siCasesPourNonAssociees() {
  let n = 0;
  _SI.headers.forEach(h => { if (!_SI.mapping[h.idx] && (h.samples || []).length) { _siCreerCase(h.idx); n++; } });
  _siRender();
  toast(n ? `${n} case(s) ajoutée(s) aux fiches (créées à l'import).` : 'Toutes les colonnes remplies sont déjà associées.', 'success');
}

/** Change la correspondance d'une colonne (un champ n'est associé qu'à une colonne). */
function _siChoisir(col, k, sansRendu) {
  if (!String(k || '').startsWith('cp+')) delete _SI.nouvellesCases[col];
  if (k === 'cp+') { _siCreerCase(col); if (!sansRendu) _siRender(); return; }
  if (k) Object.keys(_SI.mapping).forEach(c => { if (_SI.mapping[c] === k && parseInt(c, 10) !== col) delete _SI.mapping[c]; });
  if (k) _SI.mapping[col] = k; else delete _SI.mapping[col];
  const h = _SI.headers.find(x => x.idx === col);
  if (h) { h.score = 0; h.reasons = ['choix manuel']; }
  if (sansRendu) return;
  _siPreparerCategories();
  _siRender();
}

// ══════════════ ÉTAPE 2 — Correspondances ══════════════

function _siAllerEtape2() {
  if (!_SI.rawRows.length) { toast('Aucune donnée à importer.', 'error'); return; }
  _siPreparerCategories();
  _SI.step = 2;
  _siRender();
}

function _siManquants() { return _SI_CHAMPS.filter(f => f.required && _siColonne(f.key) === null).map(f => f.label); }

function _siStep2() {
  const manque = _siManquants();
  let html = '';
  if (manque.length) {
    html += '<div style="padding:10px 14px;background:#fef9c3;border:1px solid #eab308;border-radius:8px;margin-bottom:14px;font-size:12px;color:#713f12">'
      + lk('triangle-exclamation-point', 'warn') + ' <strong>Champ(s) obligatoire(s) non assigné(s) :</strong> ' + manque.map(_esc).join(', ') + '</div>';
  }
  if (!manque.length && _siColonne('designation') === null) {
    html += '<div style="padding:10px 14px;background:var(--blue-pale);border:1px solid var(--blue);border-radius:8px;margin-bottom:14px;font-size:12px">'
      + lk('info') + ' Sans colonne <strong>Désignation</strong>, seuls les articles déjà dans Larka seront mis à jour : un nouvel article en exige une.</div>';
  }
  html += '<div style="font-size:13px;color:var(--gray-text);margin-bottom:8px">Vérifiez les correspondances détectées. Les colonnes inutiles peuvent rester sur « Ne pas importer ».</div>';
  const libres = _SI.headers.filter(h => !_SI.mapping[h.idx] && (h.samples || []).length).length;
  if (libres && typeof ChampsPerso !== 'undefined') {
    html += '<div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;padding:8px 12px;background:#f5f3ff;border:1px dashed #7c3aed;border-radius:8px;font-size:12px;color:#5b21b6">'
      + '<span style="flex:1"><b>' + libres + '</b> <span>colonne(s) remplie(s) sans champ de l\'article : elles peuvent devenir des cases de la fiche (section « Informations complémentaires »).</span></span>'
      + '<button type="button" class="btn btn-ghost btn-sm" onclick="_siCasesPourNonAssociees()">' + lk('folders') + ' Créer les cases</button></div>';
  }
  const utilises = new Set(Object.values(_SI.mapping).filter(Boolean));
  html += '<div style="border:1px solid var(--gray-border);border-radius:8px;overflow:hidden;max-height:380px;overflow-y:auto"><table style="width:100%;border-collapse:collapse;font-size:12px">'
    + '<thead style="position:sticky;top:0;background:var(--gray-bg);z-index:1"><tr>'
    + '<th style="padding:8px 10px;border-bottom:1px solid var(--gray-border);width:32px"></th>'
    + '<th style="padding:8px 10px;text-align:left;border-bottom:1px solid var(--gray-border);width:25%">Colonne du fichier</th>'
    + '<th style="padding:8px 10px;text-align:left;border-bottom:1px solid var(--gray-border);width:30%">Aperçu</th>'
    + '<th style="padding:8px 10px;text-align:left;border-bottom:1px solid var(--gray-border)">Champ de l\'article</th></tr></thead><tbody>';
  _SI.headers.forEach(h => {
    const cur = _SI.mapping[h.idx] || '', f = _SI_CHAMPS.find(x => x.key === cur);
    let icone = lk('circle', 'p gris'), titre = 'Non assigné';
    if (cur && h.score >= 50) { icone = lk('circle', 'p vert'); titre = 'Détection sûre (score ' + h.score + ')'; }
    else if (cur && h.score >= 30) { icone = lk('circle', 'p jaune'); titre = 'À vérifier (score ' + h.score + ')'; }
    else if (cur) { icone = lk('circle', 'p bleu'); titre = 'Choix manuel'; }
    const raisons = (h.reasons || []).map(r => '• ' + r).join('\n');
    html += '<tr style="border-bottom:1px solid var(--gray-bg)">'
      + '<td style="padding:8px 10px;text-align:center" title="' + _esc(titre + (raisons ? '\n\n' + raisons : '')) + '">' + icone + '</td>'
      + '<td style="padding:8px 10px;font-weight:600;color:var(--text)">' + _esc(h.label) + '</td>'
      + '<td style="padding:8px 10px;color:var(--gray-text);font-style:italic;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + _esc((h.samples || []).slice(0, 10).join('\n')) + '">' + (h.sample ? _esc(h.sample) : '<span style="opacity:.5">(vide)</span>') + '</td>'
      + '<td style="padding:6px 10px"><select class="form-control" style="font-size:12px;padding:4px 8px;' + (f && f.required ? 'border-color:var(--blue);background:var(--blue-pale)' : '') + '" onchange="_siChoisir(' + h.idx + ',this.value)">'
      + '<option value="">— Ne pas importer —</option><optgroup label="Champs de l\'article">'
      + _SI_CHAMPS.map(x => { const u = utilises.has(x.key) && x.key !== cur; return '<option value="' + x.key + '" ' + (cur === x.key ? 'selected' : '') + (u ? ' style="color:#999"' : '') + '>' + _esc(x.label) + (x.required ? ' *' : '') + (u ? ' — déjà utilisé' : '') + '</option>'; }).join('')
      + '</optgroup>'
      + (typeof ChampsPerso === 'undefined' ? '' : '<optgroup label="Cases complémentaires des fiches">'
        + _SI.cases.map(d => { const k = 'cp:' + d.cle, u = utilises.has(k) && k !== cur; return '<option value="' + _esc(k) + '" ' + (cur === k ? 'selected' : '') + (u ? ' style="color:#999"' : '') + '>' + _esc(d.libelle) + (u ? ' — déjà utilisé' : '') + '</option>'; }).join('')
        + (cur.startsWith('cp+:') ? '<option value="' + _esc(cur) + '" selected>' + _esc(_siLibelleCase(cur)) + ' (nouvelle case)</option>'
                                  : '<option value="cp+">Nouvelle case « ' + _esc(String(h.label).substring(0, 40)) + ' »</option>')
        + '</optgroup>')
      + '</select>';
    if (cur.startsWith('cp+:')) {
      const nc = _SI.nouvellesCases[h.idx] || {};
      html += '<div style="display:flex;gap:4px;margin-top:4px">'
        + '<input class="form-control" style="font-size:11px;padding:3px 6px" value="' + _esc(nc.libelle || '') + '" title="Nom de la case dans les fiches" onchange="_siRenommerCase(' + h.idx + ',this.value);_siRender()">'
        + '<select class="form-control" style="font-size:11px;padding:3px 6px;width:110px" title="Type de la case" onchange="_SI.nouvellesCases[' + h.idx + '].type=this.value">'
        + (ChampsPerso.TYPES || []).map(([v, l]) => '<option value="' + v + '" ' + (nc.type === v ? 'selected' : '') + '>' + l + '</option>').join('') + '</select></div>';
    }
    html += '</td></tr>';
  });
  html += '</tbody></table></div>';

  // Catégories du fichier → catégories de Larka
  const cats = Object.keys(_SI.valueMap.categorie || {});
  if (cats.length) {
    html += '<div style="margin-top:14px;padding:12px;background:var(--blue-pale);border:1px solid var(--blue);border-radius:8px">'
      + '<div style="font-weight:600;margin-bottom:6px;font-size:13px">' + lk('target') + ' Correspondance des catégories</div>'
      + '<div style="font-size:11px;color:var(--gray-text);margin-bottom:8px">Faites correspondre les catégories du fichier à celles de Larka ; une catégorie gardée telle quelle est proposée à l\'ajout dans la liste.</div>'
      + '<div style="display:grid;grid-template-columns:1fr auto 1fr;gap:6px;align-items:center;font-size:12px">';
    cats.forEach(v => {
      html += '<div style="padding:4px 8px;background:var(--white);border-radius:4px;border:1px solid var(--gray-border)"><strong>' + _esc(v) + '</strong></div><div style="text-align:center;color:var(--gray-text)">→</div>'
        + '<select class="form-control" style="font-size:12px;padding:4px 8px" onchange="_SI.valueMap.categorie[' + jsArg(v) + ']=this.value">'
        + '<option value="">— Garder « ' + _esc(v) + ' » (nouvelle catégorie) —</option>'
        + _SI.categories.map(c => '<option value="' + _esc(c) + '" ' + (_SI.valueMap.categorie[v] === c ? 'selected' : '') + '>' + _esc(c) + '</option>').join('') + '</select>';
    });
    html += '</div></div>';
  }

  // Règles : article déjà là, quantités
  const radio = (nom, val, cur, texte, maj) => '<label style="display:flex;align-items:flex-start;gap:6px;cursor:pointer;font-size:12px;margin-bottom:4px"><input type="radio" name="' + nom + '" value="' + val + '" ' + (cur === val ? 'checked' : '') + ' onchange="' + maj + '=this.value" style="margin-top:2px"> <span>' + texte + '</span></label>';
  html += '<div style="margin-top:14px;padding:12px;background:var(--gray-bg);border-radius:8px;display:grid;grid-template-columns:1fr 1fr;gap:12px 18px">'
    + '<div><div style="font-weight:600;margin-bottom:6px;font-size:13px">' + lk('repeat') + ' Si l\'article existe déjà</div>'
    + '<div style="font-size:11px;color:var(--gray-text);margin-bottom:6px">Même référence et même type d\'article.</div>'
    + radio('siDedup', 'update', _SI.dedup, 'Mettre à jour la fiche (seules les colonnes du fichier)', '_SI.dedup')
    + radio('siDedup', 'skip', _SI.dedup, 'Laisser la fiche telle quelle', '_SI.dedup') + '</div>'
    + '<div><div style="font-weight:600;margin-bottom:6px;font-size:13px">' + lk('package') + ' Quantité d\'un article déjà là</div>'
    + '<div style="font-size:11px;color:var(--gray-text);margin-bottom:6px">Un nouvel article prend la quantité du fichier.</div>'
    + radio('siQte', 'remplacer', _SI.quantites, '<strong>Remplacer</strong> par celle du fichier (inventaire)', '_SI.quantites')
    + radio('siQte', 'ajouter', _SI.quantites, '<strong>Ajouter</strong> celle du fichier au stock (livraison)', '_SI.quantites') + '</div>'
    + '</div>';
  return html + _siPied('_SI.step=1;_siRender()', '_siAllerEtape3()', !manque.length, 'Aperçu ▶');
}

// ══════════════ ÉTAPE 3 — Aperçu (serveur) & import ══════════════

/** Lignes du fichier telles qu'envoyées au serveur (il refait tous les contrôles). */
function _siConstruireItems() {
  const out = [];
  _SI.rawRows.slice(_SI.headerRowIdx + 1).forEach((r, k) => {
    const item = { _row: _SI.refs[_SI.headerRowIdx + 1 + k] || (_SI.headerRowIdx + k + 2) };
    let vide = true;
    Object.entries(_SI.mapping).forEach(([col, ch]) => {
      const v = String(r[col] == null ? '' : r[col]).trim();
      if (!v) return;
      vide = false;
      const cle = _siCleCase(ch);
      if (cle) {
        // Case : date et nombre ramenés à leur forme de saisie ; illisible, gardée telle quelle.
        const t = _siTypeCase(ch);
        let val = v;
        if (t === 'date') val = _biParseDate(v) || v;
        else if (t === 'nombre') { const n = _biNombre(v); val = n === null ? v : String(n); }
        (item.champsPerso = item.champsPerso || {})[cle] = val;
        return;
      }
      if (ch.startsWith('cp')) return;
      item[ch] = ch === 'categorie' ? (_SI.valueMap.categorie[v] || v) : v;
    });
    if (!vide) out.push(item);
  });
  return out;
}

function _siCorps(dryRun, items) {
  return { items, dryRun, dedup: _SI.dedup, quantites: _SI.quantites,
           champsMappes: [...new Set(Object.values(_SI.mapping))].filter(k => !k.startsWith('cp')),
           listesNePasCreer: { CategorieStock: [..._SI.nePasCreer] },
           champsPersoNouveaux: Object.values(_SI.nouvellesCases).filter(n => Object.values(_SI.mapping).some(v => _siCleCase(v) === n.cle)) };
}

/** POST de l'import : même gestion des réponses illisibles que les autres imports. */
async function _siPost(corps) {
  let res, texte = '';
  try {
    res = await fetch('api/index.php?action=import_stock', { method: 'POST', credentials: 'include',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify(corps) });
    texte = await res.text();
  } catch (e) { return { ok: false, error: 'Serveur injoignable : ' + e.message, code: 'NAV-002' }; }
  try {
    const j = JSON.parse(texte);
    if (j.success === false) return { ok: false, error: j.error || ('Erreur ' + res.status), code: j.code || null };
    return { ok: true, data: j.data };
  } catch (_) {
    return { ok: false, error: 'Réponse illisible du serveur (HTTP ' + res.status + ')', code: res.status >= 500 ? 'API-500' : null, brut: texte.substring(0, 1500),
             hint: res.status === 413 ? 'Fichier trop gros pour le serveur : découpez-le.' : '' };
  }
}

function _siAllerEtape3() {
  if (_siManquants().length) { toast('Champ(s) obligatoire(s) non assigné(s) : ' + _siManquants().join(', '), 'error'); return; }
  _siMemoireEcrire();
  _SI.items = _siConstruireItems();
  if (!_SI.items.length) { toast('Aucune ligne à importer.', 'error'); return; }
  _SI.step = 3;
  _siRender();
  _siAnalyser();
}
function _siStep3() {
  return '<div id="siApercu" style="min-height:200px"><div style="text-align:center;padding:40px;color:var(--gray-text)">' + lk('hourglass') + ' Analyse en cours… (rien n\'est encore enregistré)</div></div>';
}
function _siErreur(r, contexte) {
  const c = document.getElementById('siApercu');
  if (!c) return;
  c.innerHTML = '<div style="padding:14px;background:#fff5f5;border:1px solid #e74c3c;border-radius:8px;font-size:13px">'
    + lk('circle-x', 'ko') + ' <strong>' + _esc(contexte) + ' : ' + _esc(r.error) + '</strong>'
    + (r.code && typeof codeErreurBadge === 'function' ? ' ' + codeErreurBadge(r.code) : '')
    + (r.hint ? '<div style="margin-top:6px">' + _esc(r.hint) + '</div>' : '')
    + (r.brut ? '<details style="margin-top:6px"><summary>Réponse brute</summary><pre style="white-space:pre-wrap;font-size:10px">' + _esc(r.brut) + '</pre></details>' : '')
    + '</div>' + _siPied('_SI.step=2;_siRender()', '_siAllerEtape3()', true, '↺ Réessayer');
}

async function _siAnalyser() {
  const r = await _siPost(_siCorps(true, _SI.items));
  if (!r.ok) { _siErreur(r, 'Analyse'); return; }
  _SI.apercu = r.data;
  _siRendreApercu(r.data);
}

const _SI_ACTIONS = { creer: ['À créer', 'teal'], maj: ['Mise à jour', 'blue'], laisse: ['Laissé (existe)', 'gray'], erreur: ['Erreur : ignorée', 'red'] };
const _siRef = n => _biRefCourt(_SI.orientation, n);

/** Rapport : erreurs et avertissements du serveur, regroupés par motif. */
function _siRapport(lignes) {
  const out = [];
  (lignes || []).forEach(l => {
    (l.erreurs || []).forEach(e => out.push({ gravite: 'erreur', row: l.row, reference: l.reference, designation: l.designation, champ: e.champ || '', valeur: e.valeur || '', message: e.message }));
    (l.avert || []).forEach(e => out.push({ gravite: 'avertissement', row: l.row, reference: l.reference, designation: l.designation, champ: e.champ || '', valeur: e.valeur || '', message: e.message }));
  });
  _SI.dernierRapport = out;
  return out;
}
function _siRapportHtml(lignes) {
  if (!lignes.length) return '';
  const nErr = lignes.filter(l => l.gravite === 'erreur').length, groupes = {};
  lignes.forEach(l => { const k = l.gravite + '|' + l.champ + '|' + l.message; (groupes[k] = groupes[k] || []).push(l); });
  let html = '<div style="padding:12px;background:' + (nErr ? '#fff5f5' : '#fffbeb') + ';border:1px solid ' + (nErr ? '#e74c3c' : '#eab308') + ';border-radius:8px;margin-bottom:12px">'
    + '<div style="display:flex;align-items:center;gap:8px;margin-bottom:6px"><div style="font-weight:600;font-size:13px;flex:1">'
    + (nErr ? lk('octagon-x', 'ko') + ' <b>' + nErr + '</b> erreur(s) : lignes ignorées' : '') + (lignes.length - nErr ? (nErr ? ' · ' : lk('triangle-exclamation-point', 'warn') + ' ') + '<b>' + (lignes.length - nErr) + '</b> avertissement(s)' : '') + '</div>'
    + '<button class="btn btn-ghost btn-sm" onclick="_siTelechargerRapport()">' + lk('arrow-down') + ' Télécharger le rapport (CSV)</button></div>';
  Object.values(groupes).forEach(arr => {
    const { gravite, champ, message } = arr[0];
    html += '<details style="margin-top:4px"><summary style="cursor:pointer;font-size:12px">' + (gravite === 'erreur' ? lk('octagon-x', 'ko') : lk('triangle-exclamation-point', 'warn')) + ' <strong>' + arr.length + '</strong> × '
      + (champ ? _esc(champ) + ' : ' : '') + _esc(message) + '</summary>'
      + '<div style="max-height:160px;overflow-y:auto;font-size:11px;font-family:monospace;margin:4px 0 6px 18px">'
      + arr.slice(0, 200).map(l => '<div style="padding:2px 0">' + _esc(_siRef(l.row)) + (l.reference ? ' (' + _esc(l.reference) + ')' : '') + (l.valeur ? ' — « ' + _esc(l.valeur) + ' »' : '') + '</div>').join('')
      + (arr.length > 200 ? '<div style="color:var(--gray-text)">… et ' + (arr.length - 200) + ' autre(s) (voir le CSV)</div>' : '') + '</div></details>';
  });
  return html + '</div>';
}
function _siTelechargerRapport() {
  const r = _SI.dernierRapport;
  if (!r || !r.length) return;
  const q = v => '"' + String(v == null ? '' : v).replace(/"/g, '""') + '"';
  _siTelecharger('﻿' + [(_SI.orientation === 'colonnes' ? 'Colonne du fichier' : 'Ligne') + ';Référence;Désignation;Gravité;Champ;Valeur;Message']
    .concat(r.map(l => [_SI.orientation === 'colonnes' ? _biColLettre(l.row) : l.row, l.reference, l.designation, l.gravite, l.champ, l.valeur, l.message].map(q).join(';'))).join('\r\n'),
    'rapport-import-stock-' + new Date().toISOString().substring(0, 10) + '.csv');
}

function _siRendreApercu(a) {
  const c = document.getElementById('siApercu');
  if (!c) return;
  const aImporter = a.aCreer + a.aMettreAJour;
  const tuile = (n, lib, fond, coul) => '<div style="padding:12px;background:' + fond + ';border-radius:8px;text-align:center"><div style="font-size:22px;font-weight:700;color:' + coul + '">' + n + '</div><div style="font-size:11px">' + lib + '</div></div>';
  let html = '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:14px">'
    + tuile(a.aCreer, 'à créer', 'var(--blue-pale)', 'var(--blue)')
    + tuile(a.aMettreAJour, 'à mettre à jour', '#fef9c3', '#a16207')
    + tuile(a.laissees, 'laissés (déjà là)', 'var(--gray-bg)', 'var(--gray-text)')
    + tuile(a.enErreur, 'en erreur', a.enErreur ? '#fee' : 'var(--gray-bg)', a.enErreur ? '#e74c3c' : 'var(--gray-text)') + '</div>';
  if (a.aMettreAJour && _siColonne('quantite') !== null) {
    html += '<div style="font-size:12px;color:var(--gray-text);margin-bottom:10px">' + lk('package') + ' Quantités des articles déjà là : '
      + (a.quantites === 'ajouter' ? '<span><strong>ajoutées</strong> au stock (livraison)</span>' : '<span><strong>remplacées</strong> par celles du fichier (inventaire)</span>') + '.</div>';
  }
  html += _siRapportHtml(_siRapport(a.lignes));

  const nouvelles = (a.listesACreer && a.listesACreer.CategorieStock) || [];
  if (nouvelles.length || _SI.nePasCreer.size) {
    const toutes = [...new Set([...nouvelles, ..._SI.nePasCreer])];
    html += '<div style="padding:12px;background:#f0fdf4;border:1px solid #16a34a;border-radius:8px;margin-bottom:12px;font-size:12px">'
      + '<div style="font-weight:600;margin-bottom:6px">' + lk('list-plus') + ' Nouvelles catégories ajoutées à la liste</div>'
      + '<div style="color:var(--gray-text);margin-bottom:6px">Décochez une catégorie pour ne pas l\'ajouter à la liste : les articles la gardent quand même.</div>'
      + toutes.map(v => '<label style="display:inline-flex;align-items:center;gap:4px;margin-right:10px;cursor:pointer"><input type="checkbox" ' + (_SI.nePasCreer.has(v) ? '' : 'checked')
        + ' onchange="_siCategorieBasculer(' + jsArg(v) + ',this.checked)"> ' + _esc(v) + '</label>').join('') + '</div>';
  }
  if ((a.casesACreer || []).length) {
    html += '<div style="padding:12px;background:#f5f3ff;border:1px solid #7c3aed;border-radius:8px;margin-bottom:12px;font-size:12px;color:#5b21b6">'
      + '<div style="font-weight:600;margin-bottom:4px">' + lk('folders') + ' Cases créées dans les fiches du stock</div>'
      + a.casesACreer.map(l => '« ' + _esc(l) + ' »').join(', ')
      + ((a.casesRessemblances || []).length ? '<div style="margin-top:6px;color:#a16207">' + lk('triangle-exclamation-point', 'warn') + ' '
          + a.casesRessemblances.map(r => '« ' + _esc(r.libelle) + ' » ressemble à ' + Math.round(r.similarite * 100) + ' % à « ' + _esc(r.ressemble_a) + ' »').join(' ; ')
          + ' — revenez à l\'étape 2 pour choisir la case existante si c\'est la même.</div>' : '') + '</div>';
  }

  html += '<div style="font-size:13px;font-weight:600;margin-bottom:6px">Aperçu (10 premières lignes) :</div>'
    + '<div style="max-height:220px;overflow-y:auto;border:1px solid var(--gray-border);border-radius:6px;margin-bottom:14px"><table style="width:100%;font-size:11px;border-collapse:collapse">'
    + '<tr style="background:var(--gray-bg);font-weight:700"><td style="padding:6px 8px">' + (_SI.orientation === 'colonnes' ? 'Colonne' : 'Ligne') + '</td><td style="padding:6px 8px">Action</td><td style="padding:6px 8px">Référence</td><td style="padding:6px 8px">Désignation</td><td style="padding:6px 8px">Catégorie</td><td style="padding:6px 8px">Quantité</td></tr>';
  (a.lignes || []).slice(0, 10).forEach(l => {
    const [lib, coul] = _SI_ACTIONS[l.action] || [l.action, 'gray'];
    const qte = l.quantiteAvant !== null && l.quantiteAvant !== undefined && l.quantiteAvant !== l.quantite ? l.quantiteAvant + ' → ' + l.quantite : String(l.quantite ?? '');
    html += '<tr style="border-top:1px solid var(--gray-bg)"><td style="padding:4px 8px;color:var(--gray-text)">' + _esc(_siRef(l.row)) + '</td>'
      + '<td style="padding:4px 8px"><span class="badge badge-' + coul + '" style="font-size:10px">' + lib + '</span></td>'
      + '<td style="padding:4px 8px">' + _esc(l.reference) + '</td><td style="padding:4px 8px;max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + _esc(l.designation) + '">' + _esc(l.designation) + '</td>'
      + '<td style="padding:4px 8px">' + _esc(l.categorie || '—') + '</td><td style="padding:4px 8px;white-space:nowrap">' + _esc(qte) + '</td></tr>';
  });
  html += '</table></div>';
  html += '<div style="display:flex;justify-content:space-between;margin-top:20px;padding-top:14px;border-top:1px solid var(--gray-border)">'
    + '<button class="btn btn-secondary" onclick="_SI.step=2;_siRender()">◀ Retour aux correspondances</button>'
    + (aImporter > 0 ? '<button class="btn btn-primary" onclick="_siImporter()">' + lk('circle-check', 'ok') + ' Importer (' + aImporter + ' article' + (aImporter > 1 ? 's' : '') + ')</button>'
                     : '<button class="btn btn-primary" disabled>Aucun article à importer</button>') + '</div>';
  c.innerHTML = html;
}

/** Case d'une catégorie nouvelle : décochée, elle n'est pas ajoutée à la liste (l'article la garde). */
function _siCategorieBasculer(v, creer) { if (creer) _SI.nePasCreer.delete(v); else _SI.nePasCreer.add(v); }

async function _siImporter() {
  const c = document.getElementById('siApercu');
  if (!c) return;
  // Seules les lignes que l'aperçu annonce à créer ou à mettre à jour partent.
  const utiles = new Set(((_SI.apercu && _SI.apercu.lignes) || []).filter(l => l.action === 'creer' || l.action === 'maj').map(l => l.row));
  const items = _SI.items.filter(it => utiles.has(it._row));
  const total = items.length;
  const t = { crees: 0, misesAJour: 0, echecs: [], listesAjoutees: 0, casesCreees: 0 };
  let fait = 0;
  for (let i = 0, lot = 1, nLots = Math.ceil(total / _SI_LOT); i < total; i += _SI_LOT, lot++) {
    const morceau = items.slice(i, i + _SI_LOT);
    const pct = total ? Math.round(fait / total * 100) : 0;
    c.innerHTML = '<div style="text-align:center;padding:30px"><div style="font-size:24px;margin-bottom:10px">' + lk('hourglass') + '</div>'
      + '<div style="font-size:14px;font-weight:600;margin-bottom:14px">' + (nLots > 1 ? 'Lot ' + lot + ' / ' + nLots + '…' : 'Import en cours…') + '</div>'
      + '<div style="background:var(--gray-bg);border-radius:8px;height:20px;overflow:hidden;margin:0 auto;max-width:400px"><div style="background:var(--blue);height:100%;width:' + pct + '%"></div></div></div>';
    const r = await _siPost(_siCorps(false, morceau));
    if (!r.ok) { _siErreur(r, nLots > 1 ? 'Lot ' + lot + '/' + nLots + ' — ' + fait + ' ligne(s) déjà importée(s)' : 'Import'); if (fait) _siRafraichir(); return; }
    const d = r.data;
    t.crees += d.crees || 0; t.misesAJour += d.misesAJour || 0; t.listesAjoutees += d.listesAjoutees || 0; t.casesCreees += d.casesCreees || 0;
    (d.echecs || []).forEach(e => t.echecs.push(e));
    fait += morceau.length;
  }
  const rapport = t.echecs.map(e => { const it = _SI.items.find(x => x._row === e.row) || {}; return { gravite: 'erreur', row: e.row, reference: it.reference || '', designation: it.designation || '', champ: '', valeur: '', message: e.message }; });
  _SI.dernierRapport = rapport;
  let html = '<div style="text-align:center;padding:24px 10px">'
    + '<div style="font-size:48px;margin-bottom:10px">' + (t.echecs.length ? lk('triangle-exclamation-point', 'warn') : lk('party-popper')) + '</div>'
    + '<div style="font-size:18px;font-weight:700;margin-bottom:14px">' + (t.echecs.length ? 'Import terminé, avec des lignes en échec' : 'Import terminé !') + '</div>'
    + '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin:0 auto 20px;max-width:500px">'
    + '<div style="padding:14px;background:var(--blue-pale);border-radius:8px"><div style="font-size:24px;font-weight:700;color:var(--blue)">' + t.crees + '</div><div style="font-size:11px">créés</div></div>'
    + '<div style="padding:14px;background:#fef9c3;border-radius:8px"><div style="font-size:24px;font-weight:700;color:#a16207">' + t.misesAJour + '</div><div style="font-size:11px">mis à jour</div></div>'
    + '<div style="padding:14px;background:' + (t.echecs.length ? '#fee' : 'var(--gray-bg)') + ';border-radius:8px"><div style="font-size:24px;font-weight:700;color:' + (t.echecs.length ? '#e74c3c' : 'var(--gray-text)') + '">' + t.echecs.length + '</div><div style="font-size:11px">échecs</div></div></div>'
    + (t.listesAjoutees ? '<div style="font-size:12px;color:var(--gray-text);margin-bottom:6px">+ ' + t.listesAjoutees + ' catégorie(s) ajoutée(s) à la liste</div>' : '')
    + (t.casesCreees ? '<div style="font-size:12px;color:var(--gray-text);margin-bottom:6px">+ ' + t.casesCreees + ' case(s) ajoutée(s) aux fiches du stock</div>' : '')
    + '<div style="font-size:12px;color:var(--gray-text)">L\'import est inscrit au Journal.</div></div>'
    + '<div style="text-align:left;max-width:680px;margin:0 auto">' + _siRapportHtml(rapport) + '</div>'
    + '<div style="text-align:center;margin-top:10px"><button class="btn btn-ghost" onclick="openImportStock()">Importer un autre fichier</button> '
    + '<button class="btn btn-primary" onclick="closeModal();_siRafraichir()">Fermer & rafraîchir</button></div>';
  c.innerHTML = html;
  _siRafraichir();
  toast('Import du stock : ' + t.crees + ' créé(s), ' + t.misesAJour + ' mis à jour' + (t.echecs.length ? ', ' + t.echecs.length + ' échec(s)' : ''), t.echecs.length ? 'warning' : 'success');
}

function _siRafraichir() {
  if (typeof renderStock === 'function' && typeof App !== 'undefined' && App.currentPage === 'stock') { try { renderStock(); } catch (_) {} }
}

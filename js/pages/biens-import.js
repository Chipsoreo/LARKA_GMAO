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
 * Modal en 5 étapes :
 *   1. Cible        : Biens (mobilier/informatique/véhicules) ou Équipements (technique)
 *   2. Fichier      : Upload, sélection feuille, ligne d'en-têtes (détectée)
 *   3. Mapping      : pour chaque colonne du fichier → champ Larka + mapping de valeurs
 *                     (détection par libellé + contenu, mémoire du dernier import,
 *                     proposition de l'IA si l'assistant est configuré)
 *   4. Sélection    : une case par ligne — tout, rien, seuil de prix, familles,
 *                     sortis, lignes en erreur… — et contrôle ligne par ligne
 *   5. Aperçu       : dry-run (rapport d'erreurs et d'avertissements, téléchargeable)
 *                     + import
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
  lignes: [],                       // étape 4 : [{ i, row, item, erreurs, avert, inclus, total }]
  selFiltre: 'toutes',              // étape 4 : toutes | cochees | decochees | erreurs | avert
  selRecherche: '',
  selPage: 1,
  prixMin: '', prixMax: '',
  listesNePasCreer: {},             // étape 5 : { categorie: Set(valeurs décochées) }
  dernierRapport: null,             // étape 5 : rapport (aperçu / import) pour le téléchargement CSV
  iaDispo: null,                    // assistant IA appelable ? (null = pas encore demandé)
  cases: { biens: [], equipements: [] },   // cases complémentaires déjà définies dans les fiches
  nouvellesCases: {},               // { colIdx: { cle, libelle, type } } — à créer par l'import
  step: 1,
};

// ══════════════ Cases complémentaires (section « fourre-tout » des fiches) ══════════════
// Une colonne du fichier sans champ Larka (« Date de sortie administrative »,
// « Code analytique »…) devient une case des fiches, au lieu d'être perdue.
// Valeur de correspondance : « cp:<clé> » (case existante) ou « cp+:<colonne> »
// (case créée par cet import).
function _biChargerCases() {
  if (typeof ChampsPerso === 'undefined') return;
  ['biens', 'equipements'].forEach(c => ChampsPerso.charger(c, true).then(d => { _BIENS_IMPORT.cases[c] = d || []; }));
}

function _biCasesCible() {
  const t = _BIENS_IMPORT.target;
  if (t === 'biens' || t === 'equipements') return _BIENS_IMPORT.cases[t] || [];
  const vus = new Set(), out = [];
  [...(_BIENS_IMPORT.cases.biens || []), ...(_BIENS_IMPORT.cases.equipements || [])].forEach(d => { if (!vus.has(d.cle)) { vus.add(d.cle); out.push(d); } });
  return out;
}

/** Type proposé d'après le contenu : date, nombre, texte long ou texte. */
function _biTypeCase(samples) {
  const v = (samples || []).filter(Boolean);
  if (!v.length) return 'texte';
  if (v.filter(x => _biParseDate(x)).length / v.length > 0.8 && !v.every(x => /^\d+$/.test(x))) return 'date';
  if (v.filter(x => _biNombre(x) !== null).length / v.length > 0.9) return 'nombre';
  if (v.some(x => x.length > 80)) return 'texte_long';
  return 'texte';
}

/** Libellé d'une valeur de correspondance (champ Larka ou case). */
function _biLibelleChamp(k) {
  if (!k) return '';
  if (k.startsWith('cp+:')) { const n = _BIENS_IMPORT.nouvellesCases[k.slice(4)]; return n ? n.libelle : k; }
  if (k.startsWith('cp:')) { const d = _biCasesCible().find(x => x.cle === k.slice(3)); return d ? d.libelle : k.slice(3); }
  return _biGetFields().find(f => f.key === k)?.label || k;
}

/** Nouvelle case pour la colonne colIdx (ou la case existante de même nom). */
function _biCreerCase(colIdx) {
  const h = _BIENS_IMPORT.headers.find(x => x.idx === colIdx);
  if (!h) return;
  const libelle = String(h.label || '').replace(/ \(\d+\)$/, '').trim() || ('Colonne ' + (colIdx + 1));
  let cle = ChampsPerso.cle(libelle);
  const existante = _biCasesCible().find(d => d.cle === cle || _biNormalize(d.libelle) === _biNormalize(libelle));
  Object.entries(_BIENS_IMPORT.mapping).forEach(([col, v]) => { if (existante && v === 'cp:' + existante.cle && parseInt(col) !== colIdx) delete _BIENS_IMPORT.mapping[col]; });
  if (existante) { _BIENS_IMPORT.mapping[colIdx] = 'cp:' + existante.cle; return; }
  const prises = new Set([..._biCasesCible().map(d => d.cle), ...Object.entries(_BIENS_IMPORT.nouvellesCases).filter(([c]) => parseInt(c) !== colIdx).map(([, n]) => n.cle)]);
  const base = cle; let n = 2;
  while (prises.has(cle)) cle = base.substring(0, 56) + '_' + (n++);
  _BIENS_IMPORT.nouvellesCases[colIdx] = { cle, libelle, type: _biTypeCase(h.samples) };
  _BIENS_IMPORT.mapping[colIdx] = 'cp+:' + colIdx;
}

function _biCasesPourNonAssociees() {
  let n = 0;
  _BIENS_IMPORT.headers.forEach(h => {
    if (_BIENS_IMPORT.mapping[h.idx] || !(h.samples || []).length) return;
    _biCreerCase(h.idx); n++;
  });
  _biRender();
  toast(n ? `${n} cases ajoutées aux fiches (créées à l'import).` : 'Toutes les colonnes remplies sont déjà associées.', 'success');
}

function _biRenommerCase(colIdx, libelle) {
  const n = _BIENS_IMPORT.nouvellesCases[colIdx];
  if (!n) return;
  libelle = String(libelle || '').trim().substring(0, 80);
  if (!libelle) return;
  n.libelle = libelle;
  const prises = new Set([..._biCasesCible().map(d => d.cle), ...Object.entries(_BIENS_IMPORT.nouvellesCases).filter(([c]) => parseInt(c) !== colIdx).map(([, x]) => x.cle)]);
  let cle = ChampsPerso.cle(libelle); const base = cle; let i = 2;
  while (prises.has(cle)) cle = base.substring(0, 56) + '_' + (i++);
  n.cle = cle;
}

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
    exact: ['fournisseur','marché','marche','titulaire','titulaire du marché'],
    contains: [],
    negative: ['n','no','numero','numéro','code','référence','reference','date','montant'] },

  { key: 'Batiment',         label: 'Site / Bâtiment',            required: false,
    exact: ['site','bâtiment','batiment','site / bâtiment','site/batiment','bât.','bat.','localisation site'],
    contains: [['site'],['bâtiment'],['batiment']],
    negative: ['affecté','occupant','code','origine'] },

  { key: 'NomPrenom',        label: 'Affectation / Personne',     required: false,
    exact: ['localisation / affecté à','localisation','affectation','affecté','affecte','occupant','personne'],
    contains: [['affecté'],['affectation'],['occupant']],
    negative: ['site','bâtiment','batiment'] },

  { key: 'Etage',            label: 'Étage',                      required: false,
    exact: ['étage','etage','niveau'], contains: [['étage'],['niveau']], negative: [] },

  { key: 'NumeroBureau',     label: 'N° de bureau / local',       required: false,
    exact: ['n° bureau','numéro bureau','bureau','local','salle','n° local','n° salle'],
    contains: [['bureau'],['local'],['salle']],
    negative: [] },

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

  { key: 'DateSortie',       label: 'Date de sortie',             required: false,
    exact: ['date de sortie','date sortie','date de sortie technique','date sortie technique','sortie technique','date de mise au rebut','date rebut','date de réforme','date reforme'],
    contains: [['date','sortie'],['date','rebut'],['date','réforme'],['date','reforme']],
    negative: ['administratif','administrative','admin','comptable','compta','prévue','prevue'] },

  { key: 'CommentaireSortie', label: 'Commentaire de sortie',     required: false,
    exact: ['commentaire de sortie','commentaire sortie','motif de sortie','motif sortie','raison de sortie'],
    contains: [['motif','sortie'],['commentaire','sortie'],['raison','sortie']],
    negative: [] },

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
    exact: ['fournisseur','marché','marche','titulaire','titulaire du marché'], contains: [],
    negative: ['n','no','numero','numéro','code','référence','reference','date','montant'] },

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
    negative: ['sortie'] },

  { key: 'DateSortie',       label: 'Date de sortie',             required: false,
    exact: ['date de sortie','date sortie','date de sortie technique','date sortie technique','sortie technique','date de mise au rebut','date rebut','date de réforme','date reforme'],
    contains: [['date','sortie'],['date','rebut'],['date','réforme'],['date','reforme']],
    negative: ['administratif','administrative','admin','comptable','compta','prévue','prevue'] },

  { key: 'CommentaireSortie', label: 'Commentaire de sortie',     required: false,
    exact: ['commentaire de sortie','commentaire sortie','motif de sortie','motif sortie','raison de sortie'],
    contains: [['motif','sortie'],['commentaire','sortie'],['raison','sortie']],
    negative: [] }
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

// ══════════════ Lecture du classeur (Web Worker isolé) ══════════════
// ⚠️ SheetJS ÉTAIT CHARGÉ DEPUIS cdnjs (0.18.5) ET EXÉCUTÉ DANS LA PAGE.
// Cette version a deux failles à la lecture d'un fichier piégé (pollution de
// prototype CVE-2023-30533, ReDoS CVE-2024-22363) ; or l'import sert justement
// à ouvrir des fichiers venus d'ailleurs (inventaires de prestataires…). Un
// prototype pollué dans la page touchait tout le code de l'application, avec
// la session du gestionnaire ; une expression régulière sans fin gelait
// l'onglet. La lecture se fait désormais dans un worker jetable
// (biens-import.worker.js, qui explique le détail), avec la bibliothèque
// servie par Larka (js/vendor/xlsx/) : plus de dépendance à un CDN tiers.
const _BI_LECTURE_DELAI_MS = 60000;

// La lecture ne bloque plus l'onglet : l'assistant reste utilisable pendant
// qu'elle tourne. Sans ce jeton, choisir un fichier A (lent) puis B (rapide)
// laissait A arriver en dernier et remplacer B — colonnes et correspondances
// comprises. Toute nouvelle sélection, et toute réouverture de l'assistant,
// invalide la lecture en cours et arrête son worker.
let _biJetonLecture = 0;
let _biLecture = null;   // { annuler } du worker en cours

function _biArreterLecteur() {
  const l = _biLecture; _biLecture = null;
  if (l) l.annuler();
}
function _biAnnulerLecture() { _biJetonLecture++; _biArreterLecteur(); }

function _biUrlLecteur() {
  const v = (window.PageLoader && window.PageLoader.version) || '1';
  return 'js/pages/biens-import.worker.js?v=' + encodeURIComponent(v);
}

// Lit le fichier hors de la page. Résout { SheetNames, feuilles: Map(nom → lignes) }.
// nomFichier : son extension décide de la lecture (CSV : texte brut, cf. le worker).
function _biLireClasseur(buf, delaiMs = _BI_LECTURE_DELAI_MS, nomFichier = '') {
  _biArreterLecteur();   // un seul worker à la fois
  return new Promise((resolve, reject) => {
    let w;
    try { w = new Worker(_biUrlLecteur()); }
    catch (e) { reject(new Error('lecteur Excel indisponible (' + e.message + ')')); return; }
    let fini = false, minuterie = null;
    const lecture = {};
    const terminer = () => {
      fini = true; clearTimeout(minuterie); w.terminate();
      if (_biLecture === lecture) _biLecture = null;
    };
    lecture.annuler = () => {
      if (fini) return;
      terminer();
      const err = new Error('lecture annulée'); err.annulee = true; reject(err);
    };
    _biLecture = lecture;
    minuterie = setTimeout(() => {
      if (fini) return;
      terminer();
      reject(new Error('lecture interrompue au bout de ' + Math.round(delaiMs / 1000) + ' s : fichier trop lourd ou mal formé.'));
    }, delaiMs);
    w.onmessage = (e) => {
      if (fini) return;
      terminer();
      try { resolve(_biValiderClasseur(e.data)); } catch (err) { reject(err); }
    };
    w.onerror = (e) => {
      if (fini) return;
      if (e && e.preventDefault) e.preventDefault();
      terminer();
      reject(new Error('lecture impossible (' + ((e && e.message) || 'erreur du lecteur Excel') + ')'));
    };
    try { w.postMessage({ buf, nom: String(nomFichier || '') }, [buf]); }
    catch (e) { terminer(); reject(new Error('lecture impossible (' + e.message + ')')); }
  });
}

// N'accepte du worker que des noms de feuilles et des cellules en chaînes ;
// toute autre forme est rejetée, toute cellule non textuelle devient ''.
function _biValiderClasseur(d) {
  if (!d || d.ok !== true) throw new Error((d && typeof d.message === 'string' && d.message) || 'lecture impossible');
  if (!Array.isArray(d.feuilles)) throw new Error('réponse du lecteur Excel invalide');
  const SheetNames = [], feuilles = new Map();
  for (const f of d.feuilles) {
    if (!Array.isArray(f) || typeof f[0] !== 'string' || !Array.isArray(f[1])) throw new Error('réponse du lecteur Excel invalide');
    if (feuilles.has(f[0])) continue;
    SheetNames.push(f[0]);
    feuilles.set(f[0], f[1].map((l) => (Array.isArray(l) ? l.map((c) => (typeof c === 'string' ? c : '')) : [])));
  }
  return { SheetNames, feuilles };
}

// ══════════════ ENTRÉE PRINCIPALE ══════════════
async function openImportBiens() {
  if (!canEdit()) { toast('Réservé aux Gestionnaires.', 'error'); return; }
  _biAnnulerLecture();
  Object.assign(_BIENS_IMPORT, {
    target: 'auto',
    workbook: null, sheetNames: [], selectedSheet: null,
    rawRows: [], headerRowIdx: 0, headers: [], mapping: {},
    valueMap: { Statut: {}, Etat: {} }, dedupStrategy: 'update', step: 1,
    lignes: [], selFiltre: 'toutes', selRecherche: '', selPage: 1, prixMin: '', prixMax: '',
    listesNePasCreer: {}, dernierRapport: null, nouvellesCases: {},
  });
  _biChargerCases();
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
  if (!cont) return;
  cont.innerHTML = _biRenderStep();
  // Famille cochée en partie : case « indéterminée » (un clic coche toute la famille).
  cont.querySelectorAll('input[data-partiel]').forEach(e => { e.indeterminate = true; });
}

function _biRenderStep() {
  const stepHtml =
    _BIENS_IMPORT.step === 1 ? _biRenderStep1() :
    _BIENS_IMPORT.step === 2 ? _biRenderStep2() :
    _BIENS_IMPORT.step === 3 ? _biRenderStep3() :
    _BIENS_IMPORT.step === 4 ? _biRenderStep4() :
    _BIENS_IMPORT.step === 5 ? _biRenderStep5() : '';
  return _biRenderStepper() + stepHtml;
}

function _biRenderStepper() {
  const labels = ['1. Cible', '2. Fichier', '3. Correspondances', '4. Sélection', '5. Aperçu & import'];
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
      for (let i = 0; i < Math.min(20, _BIENS_IMPORT.rawRows.length); i++) {
        const apercu = (_BIENS_IMPORT.rawRows[i] || []).map(c => String(c || '').trim()).filter(Boolean).slice(0, 3).join(' · ');
        html += '<option value="'+i+'" '+(i===_BIENS_IMPORT.headerRowIdx?'selected':'')+'>Ligne '+(i+1)+(apercu ? ' — '+_esc(apercu.substring(0, 50)) : '')+'</option>';
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
  const debut = Math.max(0, _BIENS_IMPORT.headerRowIdx - 1);
  const preview = _BIENS_IMPORT.rawRows.slice(debut, debut + 6);
  if (preview.length === 0) return '';
  let html = '<div style="margin-bottom:10px">';
  const nbDonnees = Math.max(0, _BIENS_IMPORT.rawRows.length - _BIENS_IMPORT.headerRowIdx - 1);
  html += '<div style="font-size:11px;color:var(--gray-text);margin-bottom:4px">Aperçu autour de la ligne d\'en-tête (en bleu) — <strong>'+nbDonnees+' lignes de données</strong></div>';
  html += '<div style="overflow-x:auto;border:1px solid var(--gray-border);border-radius:6px;max-height:200px">';
  html += '<table style="width:100%;font-size:11px;border-collapse:collapse">';
  preview.forEach((row, k) => {
    const ri = debut + k;
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
  _biAnnulerLecture();                 // la sélection la plus récente l'emporte
  const jeton = _biJetonLecture;
  // L'ancien fichier est oublié tout de suite, et l'assistant revient à
  // l'étape 2 : sinon « Suivant » restait actif sur l'ancien fichier pendant
  // la lecture du nouveau, qui remplaçait ensuite lignes et correspondances
  // sous les yeux de l'utilisateur (étape 3), ou bloquait l'aperçu (étape 4).
  Object.assign(_BIENS_IMPORT, { workbook: null, sheetNames: [], selectedSheet: null,
    rawRows: [], headerRowIdx: 0, headers: [], mapping: {}, step: 2 });
  _biRender();
  // #biFileInfo est recréé à chaque rendu : on le relit au moment d'écrire.
  const afficher = (html) => { const el = document.getElementById('biFileInfo'); if (el) el.innerHTML = html; };
  afficher('⏳ Lecture en cours...');
  try {
    const buf = await file.arrayBuffer();
    if (jeton !== _biJetonLecture) return;
    const wb = await _biLireClasseur(buf, undefined, file.name);
    if (jeton !== _biJetonLecture) return;   // fichier remplacé ou assistant rouvert entre-temps
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
    afficher('<span style="color:#16a34a">✅ '+_esc(file.name)+' chargé ('+wb.SheetNames.length+' feuille'+(wb.SheetNames.length>1?'s':'')+')</span>');
    _biSheetSelected(bestSheet);
  } catch(e) {
    if ((e && e.annulee) || jeton !== _biJetonLecture) return;   // remplacée par une lecture plus récente
    afficher('<span style="color:#e74c3c">❌ Erreur : '+_esc(e.message)+'</span>');
  }
}

function _biSheetSelected(name) {
  _BIENS_IMPORT.selectedSheet = name;
  // Lignes déjà converties par le lecteur isolé (mêmes options sheet_to_json
  // qu'avant) ; copiées pour que chaque sélection reparte du fichier d'origine.
  const arr = (_BIENS_IMPORT.workbook.feuilles.get(name) || []).map(r => r.slice());
  _BIENS_IMPORT.rawRows = arr.filter(r => r.some(c => c !== '' && c != null && String(c).trim() !== ''));
  _BIENS_IMPORT.headerRowIdx = _biDetecterEntete(_BIENS_IMPORT.rawRows);
  _biUpdateHeaders();
  _biRender();
}

/**
 * Ligne d'en-tête : la plus « en-tête » des 20 premières lignes.
 * ⚠️ Seules les 5 premières étaient regardées, et la première qui contenait un
 * mot-clé l'emportait : un titre (« Inventaire au 31/12 — valeur des biens »)
 * ou un fichier commençant par 6 lignes de présentation faisait tout décaler.
 * Score : cellules de texte non vides, libellés reconnus des champs Larka,
 * libellés distincts, peu de nombres/dates — et des lignes de données dessous.
 */
function _biDetecterEntete(rows) {
  const fields = _biGetFields();
  const libelles = new Set();
  fields.forEach(f => (f.exact || []).forEach(e => libelles.add(_biNormalize(e))));
  let best = 0, bestScore = -Infinity;
  const n = Math.min(20, rows.length);
  for (let i = 0; i < n; i++) {
    const cells = rows[i].map(c => String(c == null ? '' : c).trim());
    const remplies = cells.filter(Boolean);
    if (remplies.length < 2) continue;
    const textes = remplies.filter(c => _biNombre(c) === null && !_biParseDate(c));
    const reconnus = remplies.filter(c => {
      const k = _biNormalize(c);
      if (libelles.has(k)) return true;
      return fields.some(f => (f.contains || []).some(g => g.every(w => k.split(' ').includes(_biNormalize(w)))));
    }).length;
    const distincts = new Set(remplies.map(c => c.toLowerCase())).size;
    // Lignes dessous : combien ont au moins la moitié de ses colonnes remplies ?
    let dessous = 0;
    for (let j = i + 1; j < Math.min(rows.length, i + 6); j++) {
      const r = rows[j];
      const k = cells.filter((c, idx) => c && String(r[idx] == null ? '' : r[idx]).trim()).length;
      if (k >= Math.max(1, remplies.length / 2)) dessous++;
    }
    const score = remplies.length * 2 + textes.length * 2 + reconnus * 12
      + (distincts === remplies.length ? 5 : 0) + dessous * 4
      - (remplies.length - textes.length) * 4 - (remplies.length === 1 ? 20 : 0);
    if (score > bestScore) { bestScore = score; best = i; }
  }
  return best;
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
  _BIENS_IMPORT.nouvellesCases = {};
  const cases = _biCasesCible();

  // Phase 1 : analyser chaque colonne, calculer un score pour chaque champ candidat
  const candidates = []; // [{ colIdx, label, samples, scores: { fieldKey: {score, reasons} } }]

  // Largeur réelle : la ligne d'en-tête peut être plus courte que les données.
  let largeur = headerRow.length;
  for (let r = _BIENS_IMPORT.headerRowIdx + 1; r < Math.min(_BIENS_IMPORT.rawRows.length, _BIENS_IMPORT.headerRowIdx + 200); r++) {
    largeur = Math.max(largeur, (_BIENS_IMPORT.rawRows[r] || []).length);
  }
  const vus = {};
  const memoire = _biMemoireLire();
  for (let idx = 0; idx < largeur; idx++) {
    const label = headerRow[idx];
    let lblTrim = String(label || '').trim();
    // Collecter jusqu'à 40 valeurs non vides, réparties dans le fichier
    const samples = [];
    const total = _BIENS_IMPORT.rawRows.length - _BIENS_IMPORT.headerRowIdx - 1;
    const pas = Math.max(1, Math.floor(total / 40));
    for (let r = _BIENS_IMPORT.headerRowIdx + 1; r < _BIENS_IMPORT.rawRows.length && samples.length < 40; r += (samples.length < 10 ? 1 : pas)) {
      const v = String(_BIENS_IMPORT.rawRows[r][idx] || '').trim();
      if (v) samples.push(v);
    }
    // Colonne entièrement vide (ni libellé, ni valeur) : ignorée.
    if (!lblTrim && samples.length === 0) continue;
    // Libellés en double : « Date », « Date (2) »… (sinon impossible de les distinguer)
    const cle = lblTrim.toLowerCase();
    if (lblTrim && vus[cle]) { vus[cle]++; lblTrim = lblTrim + ' (' + vus[cle] + ')'; }
    else if (lblTrim) vus[cle] = 1;
    const sampleStr = samples.slice(0, 3).filter((v, i, a) => a.indexOf(v) === i).join(' · ');

    // Calculer un score pour chaque champ
    const scores = {};
    const souvenir = memoire[_biNormalize(lblTrim)];
    fields.forEach(f => {
      const labelScore = _biScoreLabel(lblTrim, f);
      const contentScore = _biScoreContent(samples, f.key);
      // Un libellé PARLANT mais étranger au champ (« Date de sortie
      // administrative ») ne se rattache pas sur le seul contenu (des dates →
      // « Date acquisition ») : le contenu ne compte qu'à moitié. Sans libellé,
      // il compte entièrement.
      if (lblTrim && labelScore.score === 0) contentScore.score = Math.floor(contentScore.score / 2);
      let total = labelScore.score + contentScore.score;
      const reasons = [...labelScore.reasons, ...contentScore.reasons];
      // Même libellé associé à ce champ lors d'un import précédent (ce navigateur).
      if (souvenir && souvenir === f.key) { total += 45; reasons.unshift('choisi lors de votre dernier import'); }
      if (total > 0) scores[f.key] = { score: total, reasons };
    });
    // Cases complémentaires déjà créées : même libellé → même case.
    cases.forEach(d => {
      let total = 0; const reasons = [];
      if (_biNormalize(d.libelle) === _biNormalize(lblTrim)) { total = 60; reasons.push('case « ' + d.libelle + ' » de la fiche'); }
      if (souvenir && souvenir === 'cp:' + d.cle) { total += 45; reasons.unshift('choisi lors de votre dernier import'); }
      if (total > 0) scores['cp:' + d.cle] = { score: total, reasons };
    });

    candidates.push({ idx, label: lblTrim || '(colonne ' + (idx+1) + ')', sample: sampleStr, samples, scores });
  }

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
  const isNumeric = (s) => _biNombre(s) !== null && /\d/.test(s);
  const isDate = (s) => !/^\d{5}$/.test(s) ? _biParseDate(s) !== null : false;
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
    // Montants : symbole monétaire ou décimales à 2 chiffres
    if (score && samples.some(v => /€|eur/i.test(v) || /[.,]\d{2}$/.test(v))) { score += 8; reasons.push('format monétaire'); }
    // Un identifiant numérique (toutes valeurs entières et distinctes) n'est pas un prix.
    if (score && uniqueRatio === 1 && samples.every(v => /^\d+$/.test(v)) && avgLen >= 5) { score = Math.max(0, score - 25); reasons.push('ressemble plutôt à un identifiant'); }
  }

  // Personne (affectation) : « NOM Prénom », « Prénom Nom »
  if (fieldKey === 'NomPrenom') {
    const personnes = samples.filter(v => /^[A-ZÀ-Ý][A-Za-zÀ-ÿ'-]+\s+[A-ZÀ-Ý][A-Za-zÀ-ÿ'-]+$/.test(v)).length / samples.length;
    if (personnes > 0.6) { score = 20; reasons.push('ressemble à des noms de personnes'); }
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
  // L'assistant IA peut-il aider ? (une fois par session ; le bouton apparaît s'il le peut)
  if (_BIENS_IMPORT.iaDispo === null && typeof apiRequest === 'function') {
    _BIENS_IMPORT.iaDispo = false;
    apiRequest('assistant_status').then(st => {
      _BIENS_IMPORT.iaDispo = !!(st && st.ia_disponible);
      if (_BIENS_IMPORT.iaDispo && _BIENS_IMPORT.step === 3) _biRender();
    }).catch(() => {});
  }
}

// ── Mémoire des correspondances (ce navigateur) ──────────────────────────────
// Le même fichier revient souvent (inventaire annuel, export du même logiciel) :
// les choix faits à l'étape 3 sont retenus par libellé de colonne et proposés en
// priorité la fois suivante. Rien n'est envoyé au serveur.
const _BI_MEMOIRE = 'larka.import.correspondances';
function _biMemoireLire() {
  try {
    const m = JSON.parse(localStorage.getItem(_BI_MEMOIRE) || '{}');
    const t = m && m[_BIENS_IMPORT.target];
    return (t && typeof t === 'object') ? t : {};
  } catch (_e) { return {}; }
}
function _biMemoireEcrire() {
  try {
    const m = JSON.parse(localStorage.getItem(_BI_MEMOIRE) || '{}') || {};
    const t = Object.assign({}, m[_BIENS_IMPORT.target] || {});
    _BIENS_IMPORT.headers.forEach(h => {
      const k = _biNormalize(h.label);
      if (!k || /^colonne \d+$/.test(k)) return;
      let v = _BIENS_IMPORT.mapping[h.idx];
      if (v && v.startsWith('cp+:')) { const n = _BIENS_IMPORT.nouvellesCases[v.slice(4)]; v = n ? 'cp:' + n.cle : ''; }
      if (v) t[k] = v; else if (t[k]) delete t[k];
    });
    const cles = Object.keys(t);
    if (cles.length > 300) cles.slice(0, cles.length - 300).forEach(k => delete t[k]);
    m[_BIENS_IMPORT.target] = t;
    localStorage.setItem(_BI_MEMOIRE, JSON.stringify(m));
  } catch (_e) { /* navigation privée, stockage plein : sans conséquence */ }
}

// ── Correspondances proposées par l'IA (assistant configuré) ─────────────────
async function _biProposerIa() {
  const btn = document.getElementById('biBtnIa');
  if (btn) { btn.disabled = true; btn.textContent = '⏳ L\'IA analyse les colonnes…'; }
  const fields = _biGetFields();
  const colStatut = Object.entries(_BIENS_IMPORT.mapping).find(([_, v]) => v === 'Statut')?.[0];
  const colEtat   = Object.entries(_BIENS_IMPORT.mapping).find(([_, v]) => v === 'Etat')?.[0];
  const payload = {
    colonnes: _BIENS_IMPORT.headers.map(h => ({ idx: h.idx, libelle: h.label, exemples: (h.samples || []).filter((v, i, a) => a.indexOf(v) === i).slice(0, 5) })),
    champs: fields.map(f => ({ cle: f.key, libelle: f.label + (f.required ? ' (obligatoire)' : '') })),
    valeurs: {
      Statut: { source: colStatut !== undefined ? _biGetDistinctValues(parseInt(colStatut)).slice(0, 40) : [], cibles: _biGetGmaoStatuts() },
      Etat:   { source: colEtat   !== undefined ? _biGetDistinctValues(parseInt(colEtat)).slice(0, 40)   : [], cibles: _biGetGmaoEtats() },
    },
  };
  try {
    const r = await apiRequest('import_biens_ia', 'POST', payload);
    let n = 0;
    (r.correspondances || []).forEach(c => {
      const h = _BIENS_IMPORT.headers.find(x => x.idx === c.colonne);
      if (!h) return;
      Object.entries(_BIENS_IMPORT.mapping).forEach(([col, v]) => { if (v === c.champ && parseInt(col) !== c.colonne) delete _BIENS_IMPORT.mapping[col]; });
      if (_BIENS_IMPORT.mapping[c.colonne] !== c.champ) n++;
      _BIENS_IMPORT.mapping[c.colonne] = c.champ;
      h.score = Math.max(30, Math.min(100, c.confiance));
      h.reasons = ['proposé par l\'IA (confiance ' + c.confiance + ' %)'];
    });
    (r.valeurs || []).forEach(v => { if (_BIENS_IMPORT.valueMap[v.champ]) _BIENS_IMPORT.valueMap[v.champ][v.source] = v.cible; });
    _biPrepareValueMaps();
    _biRender();
    toast(n ? `${n} correspondances proposées par l'IA : vérifiez-les avant de continuer.` : 'L\'IA confirme les correspondances détectées.', 'success');
  } catch (e) {
    toast(e.message || 'IA indisponible', 'error');
    _biRender();
  }
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
  if (_BIENS_IMPORT.iaDispo) {
    html += '<button id="biBtnIa" onclick="_biProposerIa()" style="border:1px solid #7c3aed;background:#f5f3ff;color:#6d28d9;cursor:pointer;font-size:12px;font-weight:600;padding:4px 10px;border-radius:6px" title="Le modèle IA configuré reçoit les en-têtes et 5 valeurs d\'exemple par colonne — jamais le fichier">🤖 Proposer avec l\'IA</button>';
  }
  html += '<button onclick="_biClearMapping()" style="border:1px solid var(--gray-border);background:var(--white);color:var(--gray-text);cursor:pointer;font-size:12px;padding:4px 10px;border-radius:6px">Tout effacer</button>';
  html += '</div>';
  const libres = _BIENS_IMPORT.headers.filter(h => !_BIENS_IMPORT.mapping[h.idx] && (h.samples || []).length).length;
  if (libres) {
    html += '<div style="display:flex;align-items:center;gap:10px;margin:-4px 0 12px;padding:8px 12px;background:#f5f3ff;border:1px dashed #7c3aed;border-radius:8px;font-size:12px;color:#5b21b6">'
      + '<span style="flex:1"><b>'+libres+'</b> <span>colonne(s) remplie(s) sans champ Larka : elles peuvent devenir des cases de la fiche (section « Informations complémentaires »).</span></span>'
      + '<button type="button" class="btn btn-ghost btn-sm" onclick="_biCasesPourNonAssociees()">🗂️ Créer les cases</button></div>';
  }

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
    html += '<optgroup label="Champs Larka">';
    fields.forEach(f => {
      const used = usedSet.has(f.key) && f.key !== cur;
      const tag = f.compta ? ' (compta)' : '';
      const star = f.required ? ' *' : '';
      html += '<option value="'+f.key+'" '+(cur===f.key?'selected':'')+(used?' style="color:#999"':'')+'>'+_esc(f.label)+star+tag+(used?' — déjà utilisé':'')+'</option>';
    });
    html += '</optgroup><optgroup label="Cases complémentaires des fiches">';
    _biCasesCible().forEach(d => {
      const k = 'cp:' + d.cle, used = usedSet.has(k) && k !== cur;
      html += '<option value="'+_esc(k)+'" '+(cur===k?'selected':'')+(used?' style="color:#999"':'')+'>🗂️ '+_esc(d.libelle)+(used?' — déjà utilisé':'')+'</option>';
    });
    if (cur.startsWith('cp+:')) html += '<option value="'+_esc(cur)+'" selected>➕ '+_esc(_biLibelleChamp(cur))+' (nouvelle case)</option>';
    else html += '<option value="cp+">➕ Nouvelle case « '+_esc(String(h.label).substring(0, 40))+' »</option>';
    html += '</optgroup>';
    html += '</select>';
    if (cur.startsWith('cp+:')) {
      const nc = _BIENS_IMPORT.nouvellesCases[h.idx] || {};
      html += '<div style="display:flex;gap:4px;margin-top:4px">'
        + '<input class="form-control" style="font-size:11px;padding:3px 6px" value="'+_esc(nc.libelle||'')+'" title="Nom de la case dans les fiches" onchange="_biRenommerCase('+h.idx+',this.value);_biRender()">'
        + '<select class="form-control" style="font-size:11px;padding:3px 6px;width:110px" title="Type de la case" onchange="_BIENS_IMPORT.nouvellesCases['+h.idx+'].type=this.value">'
        + (ChampsPerso.TYPES || []).map(([v, l]) => '<option value="'+v+'" '+(nc.type===v?'selected':'')+'>'+l+'</option>').join('')
        + '</select></div>';
    }
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
  html += '<button class="btn btn-primary" onclick="_biGoStep4()" '+(missingRequired.length?'disabled title="Champs obligatoires manquants"':'')+'>Choisir les lignes ▶</button>';
  html += '</div>';
  return html;
}

function _biClearMapping() {
  _BIENS_IMPORT.mapping = {};
  _BIENS_IMPORT.nouvellesCases = {};
  _BIENS_IMPORT.headers.forEach(h => { h.score = 0; h.reasons = []; });
  _biRender();
}

function _biSetMapping(colIdx, fieldKey) {
  if (!String(fieldKey || '').startsWith('cp+')) delete _BIENS_IMPORT.nouvellesCases[colIdx];
  if (fieldKey === 'cp+') { _biCreerCase(colIdx); _biRender(); return; }
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
    html += '<select class="form-control" style="font-size:12px;padding:4px 8px" onchange="_BIENS_IMPORT.valueMap['+jsArg(field)+']['+jsArg(v)+']=this.value">';
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

// ══════════════ ÉTAPE 4 — Sélection des lignes ══════════════
// Une case par ligne : on choisit ce qui entre dans Larka (tout, rien, par
// famille, au-dessus d'un seuil de prix — un inventaire comptable ne suit
// souvent que les biens de plus de 500 € —, sans les biens sortis…). Chaque
// ligne est contrôlée ICI, avant tout envoi : N° vide, date ou montant
// illisible, doublon dans le fichier, ligne de total… avec la cellule en cause.
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
  _biMemoireEcrire();
  _BIENS_IMPORT.lignes = _biConstruireLignes();
  _BIENS_IMPORT.selPage = 1;
  _BIENS_IMPORT.step = 4;
  _biRender();
}

function _biLignesVisibles() {
  const q = _biNormalize(_BIENS_IMPORT.selRecherche || '');
  const f = _BIENS_IMPORT.selFiltre;
  return _BIENS_IMPORT.lignes.filter(l => {
    if (f === 'cochees' && !l.inclus) return false;
    if (f === 'decochees' && l.inclus) return false;
    if (f === 'erreurs' && !l.erreurs.length) return false;
    if (f === 'avert' && !l.avert.length) return false;
    if (q) {
      const t = _biNormalize([l.item.numero, l.item.famille, l.item.sousFamille, l.item.infoProduit, l.item.batiment, l.item.nomPrenom, l.item.etat].join(' '));
      if (!t.includes(q)) return false;
    }
    return true;
  });
}

function _biRenderStep4() {
  const L = _BIENS_IMPORT.lignes;
  const coches = L.filter(l => l.inclus);
  const somme = coches.reduce((t, l) => t + (Number(l.item.prix) || 0), 0);
  const nErr = L.filter(l => l.erreurs.length).length;
  const nAv  = L.filter(l => l.avert.length).length;
  const nSortis = L.filter(l => l.item._isSorti).length;

  let html = '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:12px">';
  html += '<div style="padding:10px;background:var(--blue-pale);border-radius:8px;text-align:center"><div style="font-size:22px;font-weight:700;color:var(--blue)">'+coches.length+'</div><div style="font-size:11px;color:var(--gray-text)"><span>lignes cochées sur</span> '+L.length+'</div></div>';
  html += '<div style="padding:10px;background:var(--gray-bg);border-radius:8px;text-align:center"><div style="font-size:18px;font-weight:700;line-height:26px">'+_esc(_biFmtEuro(somme))+'</div><div style="font-size:11px;color:var(--gray-text)">valeur des lignes cochées</div></div>';
  html += '<div onclick="_biSelFiltre(\'erreurs\')" style="cursor:pointer;padding:10px;background:'+(nErr?'#fee':'var(--gray-bg)')+';border-radius:8px;text-align:center"><div style="font-size:22px;font-weight:700;color:'+(nErr?'#c0392b':'var(--gray-text)')+'">'+nErr+'</div><div style="font-size:11px;color:var(--gray-text)">lignes en erreur</div></div>';
  html += '<div onclick="_biSelFiltre(\'avert\')" style="cursor:pointer;padding:10px;background:'+(nAv?'#fef9c3':'var(--gray-bg)')+';border-radius:8px;text-align:center"><div style="font-size:22px;font-weight:700;color:'+(nAv?'#a16207':'var(--gray-text)')+'">'+nAv+'</div><div style="font-size:11px;color:var(--gray-text)">avec avertissement</div></div>';
  html += '</div>';

  // Règles de sélection
  html += '<div style="padding:10px 12px;background:var(--gray-bg);border-radius:8px;margin-bottom:10px;font-size:12px">';
  html += '<div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:8px">';
  html += '<strong>💶 Seuil de prix</strong>';
  html += '<input id="biPrixMin" class="form-control" type="text" inputmode="decimal" placeholder="min (ex. 500)" value="'+_esc(_BIENS_IMPORT.prixMin)+'" style="width:120px;font-size:12px;padding:4px 8px">';
  html += '<span>–</span>';
  html += '<input id="biPrixMax" class="form-control" type="text" inputmode="decimal" placeholder="max" value="'+_esc(_BIENS_IMPORT.prixMax)+'" style="width:110px;font-size:12px;padding:4px 8px">';
  html += '<button class="btn btn-ghost btn-sm" onclick="_biAppliquerSeuil()" title="Décoche les lignes dont le prix est hors de cette plage">Appliquer le seuil</button>';
  html += '<span style="flex:1"></span>';
  if (nSortis) html += '<button class="btn btn-ghost btn-sm" onclick="_biSelSortis(false)" title="Biens marqués sortis, jetés, donnés ou vendus"><span>Décocher les biens sortis</span> ('+nSortis+')</button>';
  html += '</div>';
  // Familles
  const fam = {};
  L.forEach(l => { const k = l.item.famille || '(sans famille)'; fam[k] = fam[k] || { n: 0, c: 0 }; fam[k].n++; if (l.inclus) fam[k].c++; });
  const familles = Object.entries(fam).sort((a, b) => b[1].n - a[1].n);
  if (familles.length > 1) {
    html += '<div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center"><strong style="margin-right:4px">🏷️ Familles</strong>';
    familles.slice(0, 40).forEach(([k, v]) => {
      const etat = (v.c === v.n) ? 'checked' : '';   // partielle : non cochée + indéterminée (un clic coche toute la famille)
      html += '<label style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border:1px solid var(--gray-border);border-radius:12px;background:var(--white);cursor:pointer" title="'+v.c+' / '+v.n+' cochées">'
        + '<input type="checkbox" '+etat+' '+(v.c > 0 && v.c < v.n ? 'data-partiel="1"' : '')+' onchange="_biSelFamille('+jsArg(k)+',this.checked)"> '+_esc(k)+' <span style="color:var(--gray-text)">('+v.n+')</span></label>';
    });
    if (familles.length > 40) html += '<span style="color:var(--gray-text)">… +'+(familles.length - 40)+'</span>';
    html += '</div>';
  }
  html += '</div>';

  // Barre d'outils de la liste
  const vis = _biLignesVisibles();
  html += '<div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-bottom:6px">';
  html += '<input class="form-control" type="text" placeholder="🔍 Filtrer (n°, famille, désignation, site…)" value="'+_esc(_BIENS_IMPORT.selRecherche)+'" oninput="_biSelRecherche(this.value)" style="max-width:280px;font-size:12px;padding:4px 8px">';
  html += '<select class="form-control" style="width:auto;font-size:12px;padding:4px 8px" onchange="_biSelFiltre(this.value)">';
  [['toutes','Toutes les lignes'],['cochees','Cochées'],['decochees','Non cochées'],['erreurs','En erreur'],['avert','Avec avertissement']].forEach(([v, l]) => {
    html += '<option value="'+v+'" '+(_BIENS_IMPORT.selFiltre===v?'selected':'')+'>'+l+'</option>';
  });
  html += '</select>';
  html += '<span style="flex:1"></span>';
  html += '<span style="font-size:11px;color:var(--gray-text)"><span>Sur les</span> '+vis.length+' <span>lignes affichées :</span></span>';
  html += '<button class="btn btn-ghost btn-sm" onclick="_biSelVisibles(true)">Tout cocher</button>';
  html += '<button class="btn btn-ghost btn-sm" onclick="_biSelVisibles(false)">Tout décocher</button>';
  html += '<button class="btn btn-ghost btn-sm" onclick="_biSelVisibles(null)">Inverser</button>';
  html += '</div>';

  // Tableau paginé
  const PAR_PAGE = 100;
  const pages = Math.max(1, Math.ceil(vis.length / PAR_PAGE));
  if (_BIENS_IMPORT.selPage > pages) _BIENS_IMPORT.selPage = pages;
  const debut = (_BIENS_IMPORT.selPage - 1) * PAR_PAGE;
  html += '<div style="max-height:340px;overflow:auto;border:1px solid var(--gray-border);border-radius:6px">';
  html += '<table style="width:100%;font-size:11px;border-collapse:collapse">';
  html += '<thead style="position:sticky;top:0;background:var(--gray-bg);z-index:1"><tr style="font-weight:700"><td style="padding:6px 8px;width:28px"></td><td style="padding:6px 8px">Ligne</td><td style="padding:6px 8px">N°</td><td style="padding:6px 8px">Famille</td><td style="padding:6px 8px">Désignation</td><td style="padding:6px 8px;text-align:right">Prix</td><td style="padding:6px 8px">État</td><td style="padding:6px 8px">Site</td><td style="padding:6px 8px">Contrôle</td></tr></thead><tbody>';
  if (!vis.length) html += '<tr><td colspan="9" style="padding:14px;text-align:center;color:var(--gray-text)">Aucune ligne.</td></tr>';
  vis.slice(debut, debut + PAR_PAGE).forEach(l => {
    const bloque = l.erreurs.length > 0;
    const fond = bloque ? 'background:#fff5f5' : (l.inclus ? '' : 'opacity:.55');
    const pb = [...l.erreurs.map(e => '⛔ ' + (e.champ ? e.champ + ' : ' : '') + e.message + (e.valeur ? ' (« ' + e.valeur + ' »)' : '')),
                ...l.avert.map(e => '⚠️ ' + (e.champ ? e.champ + ' : ' : '') + e.message + (e.valeur ? ' (« ' + e.valeur + ' »)' : ''))];
    html += '<tr style="border-top:1px solid var(--gray-bg);'+fond+'">';
    html += '<td style="padding:4px 8px"><input type="checkbox" '+(l.inclus?'checked':'')+' '+(bloque?'disabled title="Ligne en erreur : elle ne peut pas être importée"':'')+' onchange="_biSelLigne('+l.i+',this.checked)"></td>';
    html += '<td style="padding:4px 8px;color:var(--gray-text)">'+l.row+'</td>';
    html += '<td style="padding:4px 8px;font-weight:600">'+_esc(l.item.numero||'')+'</td>';
    html += '<td style="padding:4px 8px">'+_esc(l.item.famille||'')+'</td>';
    html += '<td style="padding:4px 8px;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="'+_esc(l.item.infoProduit||'')+'">'+_esc(l.item.infoProduit||'')+'</td>';
    html += '<td style="padding:4px 8px;text-align:right;white-space:nowrap">'+(l.item.prix ? _esc(_biFmtEuro(l.item.prix)) : '<span style="color:var(--gray-text)">—</span>')+'</td>';
    html += '<td style="padding:4px 8px">'+_esc(l.item.etat||'')+(l.item._isSorti?' <span title="Bien sorti">🗃️</span>':'')+'</td>';
    html += '<td style="padding:4px 8px">'+_esc(l.item.batiment||'')+'</td>';
    const pr = l.erreurs[0] || l.avert[0];
    html += '<td style="padding:4px 8px;white-space:nowrap;max-width:300px;overflow:hidden;text-overflow:ellipsis" title="'+_esc(pb.join('\n'))+'">'+(bloque ? '⛔ ' : '')+(l.avert.length ? '⚠️ ' : '')
      + (pr ? '<span style="color:var(--gray-text)">'+(pr.champ ? '<span>'+_esc(pr.champ)+'</span> : ' : '')+'<span>'+_esc(pr.message)+'</span>'+(pb.length > 1 ? ' (+'+(pb.length-1)+')' : '')+'</span>'
            : '<span style="color:#16a34a">✓</span>')+'</td>';
    html += '</tr>';
  });
  html += '</tbody></table></div>';
  if (pages > 1) {
    const p = _BIENS_IMPORT.selPage;
    html += '<div style="display:flex;justify-content:center;align-items:center;gap:8px;margin-top:6px;font-size:12px">'
      + '<button class="btn btn-ghost btn-sm" '+(p<=1?'disabled':'')+' onclick="_BIENS_IMPORT.selPage--;_biRender()">←</button>'
      + '<span>Page '+p+' / '+pages+'</span>'
      + '<button class="btn btn-ghost btn-sm" '+(p>=pages?'disabled':'')+' onclick="_BIENS_IMPORT.selPage++;_biRender()">→</button></div>';
  }

  html += '<div style="display:flex;justify-content:space-between;margin-top:16px;padding-top:12px;border-top:1px solid var(--gray-border)">';
  html += '<button class="btn btn-secondary" onclick="_BIENS_IMPORT.step=3;_biRender()">◀ Retour</button>';
  html += '<button class="btn btn-primary" onclick="_biGoStep5()" '+(coches.length?'':'disabled')+'><span>Aperçu</span> ('+coches.length+' <span>lignes</span>) ▶</button>';
  html += '</div>';
  return html;
}

function _biSelLigne(i, on) {
  const l = _BIENS_IMPORT.lignes.find(x => x.i === i);
  if (l && !l.erreurs.length) l.inclus = !!on;
  _biRenderGarderDefilement();
}
function _biSelVisibles(on) {
  _biLignesVisibles().forEach(l => { if (!l.erreurs.length) l.inclus = (on === null) ? !l.inclus : on; });
  _biRender();
}
function _biSelFamille(fam, on) {
  _BIENS_IMPORT.lignes.forEach(l => { if ((l.item.famille || '(sans famille)') === fam && !l.erreurs.length) l.inclus = !!on; });
  _biRender();
}
function _biSelSortis(on) {
  _BIENS_IMPORT.lignes.forEach(l => { if (l.item._isSorti && !l.erreurs.length) l.inclus = !!on; });
  _biRender();
}
function _biSelFiltre(v) { _BIENS_IMPORT.selFiltre = v; _BIENS_IMPORT.selPage = 1; _biRender(); }
let _biRechMinuterie = null;
function _biSelRecherche(v) {
  _BIENS_IMPORT.selRecherche = v; _BIENS_IMPORT.selPage = 1;
  clearTimeout(_biRechMinuterie);
  _biRechMinuterie = setTimeout(() => {
    _biRender();
    const el = document.querySelector('#biImportContainer input[oninput^="_biSelRecherche"]');
    if (el) { el.focus(); el.setSelectionRange(el.value.length, el.value.length); }
  }, 250);
}
function _biAppliquerSeuil() {
  const vMin = (document.getElementById('biPrixMin') || {}).value || '';
  const vMax = (document.getElementById('biPrixMax') || {}).value || '';
  _BIENS_IMPORT.prixMin = vMin; _BIENS_IMPORT.prixMax = vMax;
  const min = vMin.trim() ? _biNombre(vMin) : null;
  const max = vMax.trim() ? _biNombre(vMax) : null;
  if ((vMin.trim() && min === null) || (vMax.trim() && max === null)) { toast('Seuil illisible : saisissez un montant (ex. 500 ou 1 500,50).', 'error'); return; }
  let n = 0;
  _BIENS_IMPORT.lignes.forEach(l => {
    const p = Number(l.item.prix) || 0;
    const dedans = (min === null || p >= min) && (max === null || p <= max);
    if (!dedans && l.inclus) { l.inclus = false; n++; }
  });
  toast(n ? `${n} lignes décochées (prix hors du seuil).` : 'Toutes les lignes cochées sont déjà dans le seuil.', 'success');
  _biRender();
}
// Cocher une case ne doit pas renvoyer en haut d'un long tableau.
function _biRenderGarderDefilement() {
  const box = document.querySelector('#biImportContainer div[style*="max-height:340px"]');
  const y = box ? box.scrollTop : 0;
  _biRender();
  const nb = document.querySelector('#biImportContainer div[style*="max-height:340px"]');
  if (nb) nb.scrollTop = y;
}

// ══════════════ Construction des lignes (étape 4) et du payload ══════════════
function _biConstruireLignes() {
  const out = [];
  const inverse = {};
  Object.entries(_BIENS_IMPORT.mapping).forEach(([col, field]) => { if (field) inverse[field] = parseInt(col); });
  const startRow = _BIENS_IMPORT.headerRowIdx + 1;
  const isAuto = _BIENS_IMPORT.target === 'auto';
  const libelle = (k) => _biLibelleChamp(k);
  const vusNum = {};
  // Colonnes envoyées dans des cases complémentaires : [colIdx, clé, type, libellé]
  const colsCases = [];
  Object.entries(_BIENS_IMPORT.mapping).forEach(([col, k]) => {
    if (!k) return;
    if (k.startsWith('cp+:')) { const n = _BIENS_IMPORT.nouvellesCases[k.slice(4)]; if (n) colsCases.push([parseInt(col), n.cle, n.type, n.libelle]); }
    else if (k.startsWith('cp:')) { const d = _biCasesCible().find(x => x.cle === k.slice(3)); colsCases.push([parseInt(col), k.slice(3), d ? d.type : 'texte', d ? d.libelle : k.slice(3)]); }
  });

  for (let i = startRow; i < _BIENS_IMPORT.rawRows.length; i++) {
    const row = _BIENS_IMPORT.rawRows[i];
    const get = (key) => {
      const idx = inverse[key];
      if (idx === undefined) return '';
      return String(row[idx] == null ? '' : row[idx]).trim();
    };
    const mapVal = (field, raw) => (!raw ? raw : (_BIENS_IMPORT.valueMap[field]?.[raw] || raw));
    const erreurs = [], avert = [];
    const numero = get('Numero').replace(/\.0+$/, '');
    const famille = get('Famille');
    const target = isAuto ? _biGuessTarget(famille) : _BIENS_IMPORT.target;

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
    const sorti = _biIsSorti({ statut: get('Statut'), etat: get('Etat') });
    if (sorti && target === 'biens') {
      if (!etat || etat === 'Stock' || etat === 'Utilise') etat = 'Jete/Recycle';
    }

    const item = {
      _row: i + 1, _targetGuess: target, _isSorti: sorti,
      numero, famille,
      sousFamille: get('SousFamille'),
      infoProduit: info,
      numeroSerie: get('NumeroSerie'),
      batiment: get('Batiment'),
      nomPrenom: get('NomPrenom'),
      statut, etat,
      dateCommande: _biParseDate(get('DateCommande')), dateCommande_brut: get('DateCommande'),
      dateLivraison: _biParseDate(get('DateLivraison')), dateLivraison_brut: get('DateLivraison'),
      prix: _biParseNumber(get('Prix')), prix_brut: get('Prix'),
    };
    if (target === 'equipements') {
      item.marque = get('Marque') || get('_Marque');
      item.modele = get('Modele') || get('_Modele');
      item.fournisseur = get('Fournisseur') || get('_Fournisseur');
      item.etage = get('Etage');
      item.numeroBureau = get('NumeroBureau');
      item.observations = get('Observations');
      item.dateInstallation = _biParseDate(get('DateInstallation')); item.dateInstallation_brut = get('DateInstallation');
    } else {
      item._NumeroImmo = get('_NumeroImmo').replace(/\.0+$/, '');
      item._AmortCum = _biParseNumber(get('_AmortCum')); item._AmortCum_brut = get('_AmortCum');
      item._VNC = _biParseNumber(get('_VNC')); item._VNC_brut = get('_VNC');
      item._BC = get('_BC').replace(/\.0+$/, '');
    }
    item.etage = item.etage || get('Etage');
    item.numeroBureau = item.numeroBureau || get('NumeroBureau');
    item.dateSortie = _biParseDate(get('DateSortie')); item.dateSortie_brut = get('DateSortie');
    item.commentaireSortie = get('CommentaireSortie');

    // Cases complémentaires de la fiche
    const cp = {};
    colsCases.forEach(([col, cle, type, lib]) => {
      const brut = String(row[col] == null ? '' : row[col]).trim();
      if (!brut) return;
      if (type === 'date') {
        const iso = _biParseDate(brut);
        if (iso) cp[cle] = iso;
        else { cp[cle] = brut; avert.push({ champ: lib, message: 'date non reconnue : gardée telle quelle', valeur: brut.substring(0, 40) }); }
      } else if (type === 'nombre') {
        const n = _biNombre(brut);
        if (n !== null) cp[cle] = String(n);
        else { cp[cle] = brut; avert.push({ champ: lib, message: 'nombre non reconnu : gardé tel quel', valeur: brut.substring(0, 40) }); }
      } else cp[cle] = brut;
    });
    if (Object.keys(cp).length) item.champsPerso = cp;

    // ── Contrôles de la ligne ──
    const texteLigne = row.map(c => String(c == null ? '' : c).trim()).filter(Boolean).join(' ');
    const ligneTotal = /^(sous[- ]?)?total\b|^total g[ée]n[ée]ral|^montant total/i.test(numero) || (/^(sous[- ]?)?total\b/i.test(texteLigne) && !famille);
    if (!numero) erreurs.push({ champ: libelle('Numero'), message: 'N° vide : ligne non importable' });
    else if (numero.length > 100) erreurs.push({ champ: libelle('Numero'), message: 'N° trop long (100 caractères au plus)', valeur: numero.substring(0, 30) + '…' });
    if (ligneTotal) avert.push({ message: 'ressemble à une ligne de total : décochée' });
    if (numero && !famille && inverse.Famille !== undefined) avert.push({ champ: libelle('Famille'), message: 'famille vide : la famille par défaut sera appliquée', valeur: target === 'equipements' ? 'Plomberie' : 'Mobilier' });
    [['DateCommande', 'dateCommande'], ['DateLivraison', 'dateLivraison'], ['DateInstallation', 'dateInstallation'], ['DateSortie', 'dateSortie']].forEach(([k]) => {
      const brut = get(k);
      if (brut && !_biDateLisible(brut)) avert.push({ champ: libelle(k), message: 'date non reconnue : importée vide', valeur: brut.substring(0, 40) });
    });
    [['Prix'], ['_AmortCum'], ['_VNC']].forEach(([k]) => {
      const brut = get(k);
      if (!brut) return;
      const n = _biNombre(brut);
      if (n === null) avert.push({ champ: libelle(k), message: 'montant non reconnu : importé à 0', valeur: brut.substring(0, 40) });
      else if (k === 'Prix' && n < 0) avert.push({ champ: libelle(k), message: 'montant négatif', valeur: brut });
    });
    if (numero) {
      const cle = numero.toLowerCase();
      vusNum[cle] = (vusNum[cle] || 0) + 1;
      if (vusNum[cle] > 1) avert.push({ champ: libelle('Numero'), message: 'N° déjà présent plus haut dans le fichier : il sera suffixé (-2, -3…)', valeur: numero });
    }

    out.push({ i, row: i + 1, item, erreurs, avert, inclus: !erreurs.length && !ligneTotal });
  }
  return out;
}

function _biBuildPayload() {
  if (!_BIENS_IMPORT.lignes || !_BIENS_IMPORT.lignes.length) _BIENS_IMPORT.lignes = _biConstruireLignes();
  return _BIENS_IMPORT.lignes.filter(l => l.inclus && !l.erreurs.length).map(l => l.item);
}

/**
 * Ce qui accompagne les lignes : cases à créer dans les fiches, et champs
 * réellement lus dans le fichier — à la mise à jour d'une fiche existante,
 * les autres ne sont pas remplacés (affectation, étage, date de sortie…).
 */
function _biExtrasPayload() {
  const vers = {
    Numero: ['numero'], Famille: ['famille'], SousFamille: ['sousFamille'], InfoProduit: ['infoProduit'],
    _Marque: ['marque', 'infoProduit'], Marque: ['marque', 'infoProduit'], _Modele: ['modele', 'infoProduit'], Modele: ['modele', 'infoProduit'],
    _Fournisseur: ['fournisseur', 'infoProduit'], Fournisseur: ['fournisseur', 'infoProduit'],
    NumeroSerie: ['numeroSerie'], Batiment: ['batiment'], NomPrenom: ['nomPrenom'], Statut: ['statut', 'etat'], Etat: ['etat'],
    DateCommande: ['dateCommande'], DateLivraison: ['dateLivraison'], DateInstallation: ['dateInstallation'], Prix: ['prix'],
    Etage: ['etage'], NumeroBureau: ['numeroBureau'], Observations: ['observations'], DateSortie: ['dateSortie'], CommentaireSortie: ['commentaireSortie'],
  };
  const mappes = new Set();
  Object.values(_BIENS_IMPORT.mapping).forEach(k => (vers[k] || []).forEach(x => mappes.add(x)));
  const nouvelles = Object.entries(_BIENS_IMPORT.nouvellesCases)
    .filter(([col]) => (_BIENS_IMPORT.mapping[col] || '').startsWith('cp+:'))
    .map(([, n]) => ({ cle: n.cle, libelle: n.libelle, type: n.type }));
  return { champsMappes: [...mappes], champsPersoNouveaux: nouvelles };
}

function _biListesNePasCreerPayload() {
  const out = {};
  Object.entries(_BIENS_IMPORT.listesNePasCreer || {}).forEach(([cat, set]) => { if (set && set.size) out[cat] = [...set]; });
  return out;
}

// ══════════════ ÉTAPE 5 — Aperçu et import ══════════════
function _biGoStep5() {
  if (!_biBuildPayload().length) { toast('Aucune ligne cochée.', 'error'); return; }
  _BIENS_IMPORT.step = 5;
  _biRender();
  _biRunDryRun();
}

function _biRenderStep5() {
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
  html += '<div style="display:flex;justify-content:space-between;margin-top:12px"><button class="btn btn-secondary" onclick="_BIENS_IMPORT.step=4;_biRender()">◀ Retour à la sélection</button></div>';
  c.innerHTML = html;
}

async function _biRunDryRun() {
  const items = _biBuildPayload();
  const res = await _biApiPost(Object.assign({ dryRun: true, target: _BIENS_IMPORT.target, biens: items, dedup: _BIENS_IMPORT.dedupStrategy,
                                 listesNePasCreer: _biListesNePasCreerPayload() }, _biExtrasPayload()));
  if (!res.ok) { _biShowApiError(res, 'Validation (étape "Aperçu")'); return; }
  _biRenderPreviewResult(res.data, items);
}

/**
 * Rapport : erreurs et avertissements du serveur ET des contrôles de l'étape 4
 * (lignes cochées), regroupés par motif, chaque cas avec sa ligne, son N°, la
 * colonne et la valeur en cause — et téléchargeable (CSV) pour corriger le
 * fichier d'origine.
 */
function _biRapport(serveurErreurs, serveurAvert, etape) {
  const lignes = [];
  const parRow = {};
  _BIENS_IMPORT.lignes.forEach(l => { parRow[l.row] = l; });
  const inclus = new Set(_BIENS_IMPORT.lignes.filter(l => l.inclus).map(l => l.row));
  const cles = new Set();
  const ajouter = (gravite, e, origine) => {
    const k = gravite + '|' + e.row + '|' + (e.champ || '') + '|' + e.message;
    if (cles.has(k)) return; cles.add(k);
    lignes.push({ gravite, row: e.row, numero: e.numero || (parRow[e.row] && parRow[e.row].item.numero) || '', champ: e.champ || '', valeur: e.valeur || '', message: e.message, origine });
  };
  (serveurErreurs || []).forEach(e => ajouter('erreur', e, 'serveur'));
  // Contrôles de l'étape 4 d'abord (ils nomment la colonne du FICHIER) ; le
  // serveur revérifie dates et montants : même ligne, même valeur = même cas.
  const dejaVus = new Set();
  _BIENS_IMPORT.lignes.forEach(l => {
    if (!inclus.has(l.row)) return;
    l.avert.forEach(a => {
      ajouter('avertissement', Object.assign({ row: l.row, numero: l.item.numero }, a), 'contrôle');
      if (a.valeur) dejaVus.add(l.row + '|' + a.valeur);
    });
  });
  (serveurAvert || []).forEach(e => { if (!dejaVus.has(e.row + '|' + (e.valeur || ''))) ajouter('avertissement', e, 'serveur'); });
  lignes.sort((a, b) => (a.gravite === b.gravite ? 0 : (a.gravite === 'erreur' ? -1 : 1)) || (a.row - b.row));
  _BIENS_IMPORT.dernierRapport = { etape, lignes, le: new Date() };
  return lignes;
}

function _biRapportHtml(lignes, titre) {
  if (!lignes.length) return '';
  const nErr = lignes.filter(l => l.gravite === 'erreur').length;
  const groupes = {};
  lignes.forEach(l => {
    const k = l.gravite + '|' + (l.champ || '') + '|' + l.message;
    (groupes[k] = groupes[k] || []).push(l);
  });
  let html = '<div style="padding:12px;background:'+(nErr?'#fff5f5':'#fffbeb')+';border:1px solid '+(nErr?'#e74c3c':'#eab308')+';border-radius:8px;margin-bottom:12px">';
  html += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:6px"><div style="font-weight:600;font-size:13px;flex:1">'+titre+'</div>'
    + '<button class="btn btn-ghost btn-sm" onclick="_biTelechargerRapport()" title="Toutes les lignes du rapport, pour corriger le fichier d\'origine">⬇️ Télécharger le rapport (CSV)</button></div>';
  Object.entries(groupes).sort((a, b) => (a[0] < b[0] ? -1 : 1)).forEach(([k, arr]) => {
    const grav = arr[0].gravite, champ = arr[0].champ, msg = arr[0].message;
    html += '<details style="margin-top:4px"><summary style="cursor:pointer;font-size:12px">'+(grav === 'erreur' ? '⛔' : '⚠️')+' <strong>'+arr.length+'</strong> × '
      + (champ ? '<span>'+_esc(champ)+'</span> : ' : '') + '<span>'+_esc(msg)+'</span></summary>';
    html += '<div style="max-height:160px;overflow-y:auto;font-size:11px;font-family:monospace;margin:4px 0 6px 18px">';
    arr.slice(0, 200).forEach(l => {
      html += '<div style="padding:2px 0;border-bottom:1px solid rgba(0,0,0,.05)">L'+_esc(String(l.row))+(l.numero ? ' (n° '+_esc(l.numero)+')' : '')+(l.valeur ? ' — « '+_esc(l.valeur)+' »' : '')+'</div>';
    });
    if (arr.length > 200) html += '<div style="color:var(--gray-text)">… et '+(arr.length - 200)+' autre(s) (voir le CSV)</div>';
    html += '</div></details>';
  });
  html += '</div>';
  return html;
}

function _biTelechargerRapport() {
  const r = _BIENS_IMPORT.dernierRapport;
  if (!r || !r.lignes.length) return;
  const q = (v) => '"' + String(v == null ? '' : v).replace(/"/g, '""') + '"';
  const csv = '﻿' + ['Ligne;N°;Gravité;Colonne;Valeur;Message']
    .concat(r.lignes.map(l => [l.row, l.numero, l.gravite, l.champ, l.valeur, l.message].map(q).join(';'))).join('\r\n');
  const a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
  a.download = 'rapport-import-' + (r.etape || 'apercu') + '-' + new Date().toISOString().substring(0, 10) + '.csv';
  document.body.appendChild(a); a.click();
  setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
}

function _biListeBasculer(cat, val, creer) {
  const m = _BIENS_IMPORT.listesNePasCreer;
  if (!m[cat]) m[cat] = new Set();
  if (creer) m[cat].delete(val); else m[cat].add(val);
}

function _biRenderPreviewResult(result, items) {
  const c = document.getElementById('biPreviewArea');
  if (!c) return;
  const isAuto = _BIENS_IMPORT.target === 'auto';
  const targetLabel = _BIENS_IMPORT.target === 'equipements' ? 'équipements'
                    : (isAuto ? 'enregistrements' : 'biens');
  const errors = result.errors || [];
  let html = '<div style="margin-bottom:14px">';
  html += '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:14px">';
  html += '<div style="padding:14px;background:var(--blue-pale);border-radius:8px;text-align:center"><div style="font-size:24px;font-weight:700;color:var(--blue)">'+result.toCreate+'</div><div style="font-size:11px;color:var(--gray-text)">à créer</div></div>';
  html += '<div style="padding:14px;background:#fef9c3;border-radius:8px;text-align:center"><div style="font-size:24px;font-weight:700;color:#a16207">'+result.toUpdate+'</div><div style="font-size:11px;color:var(--gray-text)">à mettre à jour</div></div>';
  html += '<div style="padding:14px;background:var(--gray-bg);border-radius:8px;text-align:center"><div style="font-size:24px;font-weight:700;color:var(--gray-text)">'+result.toSkip+'</div><div style="font-size:11px;color:var(--gray-text)">ignorés</div></div>';
  html += '<div style="padding:14px;background:'+(errors.length?'#fee':'var(--gray-bg)')+';border-radius:8px;text-align:center"><div style="font-size:24px;font-weight:700;color:'+(errors.length?'#e74c3c':'var(--gray-text)')+'">'+errors.length+'</div><div style="font-size:11px;color:var(--gray-text)">erreurs</div></div>';
  html += '</div>';
  const nonCoches = _BIENS_IMPORT.lignes.filter(l => !l.inclus).length;
  if (nonCoches) html += '<div style="font-size:12px;color:var(--gray-text);margin:-6px 0 10px"><b>'+nonCoches+'</b> <span>ligne(s) du fichier non cochée(s) à l\'étape 4 : elles ne sont pas importées.</span></div>';

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

  // Nouvelles valeurs de listes : une case par valeur (créer ou non)
  const proposees = {};
  Object.entries(result.listesToCreate || {}).forEach(([cat, vals]) => { proposees[cat] = new Set(vals); });
  Object.entries(_BIENS_IMPORT.listesNePasCreer || {}).forEach(([cat, set]) => { if (set && set.size) { proposees[cat] = proposees[cat] || new Set(); set.forEach(v => proposees[cat].add(v)); } });
  if (Object.keys(proposees).length > 0) {
    html += '<div style="padding:12px;background:var(--blue-pale);border:1px solid var(--blue);border-radius:8px;margin-bottom:12px">';
    html += '<div style="font-weight:600;margin-bottom:2px;font-size:13px">📋 Nouvelles valeurs pour les listes de choix</div>';
    html += '<div style="font-size:11px;color:var(--gray-text);margin-bottom:6px">Cochées : ajoutées aux listes (Configuration → Listes). Décochées : la fiche garde la valeur, la liste n\'est pas modifiée.</div>';
    Object.entries(proposees).forEach(([cat, set]) => {
      const exclues = (_BIENS_IMPORT.listesNePasCreer || {})[cat] || new Set();
      html += '<div style="font-size:12px;margin-top:6px"><strong>'+_esc(cat)+'</strong> : ';
      [...set].sort().forEach(v => {
        html += '<label style="display:inline-flex;align-items:center;gap:3px;margin:2px 6px 2px 0;padding:1px 6px;background:var(--white);border:1px solid var(--gray-border);border-radius:10px;cursor:pointer">'
          + '<input type="checkbox" '+(exclues.has(v)?'':'checked')+' onchange="_biListeBasculer('+jsArg(cat)+','+jsArg(v)+',this.checked)"> '+_esc(v)+'</label>';
      });
      html += '</div>';
    });
    html += '</div>';
  }

  // Cases complémentaires créées dans les fiches
  const nouvellesCases = result.casesACreer || [];
  const casesUtilisees = Object.values(_BIENS_IMPORT.mapping).filter(k => k && k.startsWith('cp')).length;
  if (casesUtilisees) {
    html += '<div style="padding:10px 14px;background:#f5f3ff;border:1px solid #7c3aed;border-radius:8px;margin-bottom:12px;font-size:12px;color:#5b21b6">'
      + '🗂️ <span>Cases complémentaires remplies dans les fiches :</span> <b>' + casesUtilisees + '</b>'
      + (nouvellesCases.length ? '<div style="margin-top:4px"><span>Nouvelles cases créées :</span> ' + nouvellesCases.map(l => '<span class="badge badge-blue" style="font-size:10px">' + _esc(l) + '</span>').join(' ') + '</div>' : '')
      + '</div>';
  }

  // Info sur les doublons internes suffixés
  if (result.duplicatesSuffixed && result.duplicatesSuffixed > 0) {
    html += '<div style="padding:10px 14px;background:#fef9c3;border:1px solid #eab308;border-radius:8px;margin-bottom:12px;font-size:12px;color:#713f12">';
    html += 'ℹ️ <strong>'+result.duplicatesSuffixed+' doublon(s) interne(s)</strong> au fichier détecté(s). Ils seront automatiquement suffixés (ex. "9205-2", "9205-3") pour préserver toutes les lignes.';
    html += '</div>';
  }

  const rapport = _biRapport(errors, result.avertissements || [], 'apercu');
  const nAv = rapport.filter(l => l.gravite !== 'erreur').length;
  html += _biRapportHtml(rapport, errors.length
    ? '⛔ <b>'+errors.length+'</b> <span>erreur(s) : lignes ignorées</span>'+(nAv ? ' · <b>'+nAv+'</b> <span>avertissement(s)</span>' : '')
    : '⚠️ <b>'+nAv+'</b> <span>avertissement(s) : les lignes sont importées, voir le détail</span>');

  html += '<div style="font-size:13px;font-weight:600;margin-bottom:6px">Aperçu (5 premières lignes cochées) :</div>';
  html += '<div style="max-height:200px;overflow-y:auto;border:1px solid var(--gray-border);border-radius:6px;margin-bottom:14px">';
  html += '<table style="width:100%;font-size:11px;border-collapse:collapse">';
  html += '<tr style="background:var(--gray-bg);font-weight:700"><td style="padding:6px 8px">N°</td><td style="padding:6px 8px">Famille</td><td style="padding:6px 8px">Désignation</td><td style="padding:6px 8px">État</td><td style="padding:6px 8px">Prix</td><td style="padding:6px 8px">Site</td></tr>';
  items.slice(0, 5).forEach(b => {
    html += '<tr>';
    html += '<td style="padding:4px 8px">'+_esc(b.numero||'')+'</td>';
    html += '<td style="padding:4px 8px">'+_esc(b.famille||'')+'</td>';
    html += '<td style="padding:4px 8px;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'+_esc(b.infoProduit||'')+'</td>';
    html += '<td style="padding:4px 8px">'+_esc(b.etat||'')+'</td>';
    html += '<td style="padding:4px 8px">'+_esc(_biFmtEuro(b.prix||0))+'</td>';
    html += '<td style="padding:4px 8px">'+_esc(b.batiment||'')+'</td>';
    html += '</tr>';
  });
  html += '</table></div>';
  html += '</div>';

  html += '<div style="display:flex;justify-content:space-between;margin-top:20px;padding-top:14px;border-top:1px solid var(--gray-border)">';
  html += '<button class="btn btn-secondary" onclick="_BIENS_IMPORT.step=4;_biRender()">◀ Retour à la sélection</button>';
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
  const nePasCreer = _biListesNePasCreerPayload();

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

  const totals = { created: 0, updated: 0, failed: 0, comptaCreated: 0, listesAdded: 0, errors: [] };
  const batches = [];
  for (let i = 0; i < total; i += BATCH_SIZE) batches.push(items.slice(i, i + BATCH_SIZE));
  let done = 0;
  for (let bi = 0; bi < batches.length; bi++) {
    renderProgress(done, useBatches ? 'Lot '+(bi+1)+' / '+batches.length+' ('+batches[bi].length+' lignes)…' : null);
    const res = await _biApiPost(Object.assign({ dryRun: false, target: _BIENS_IMPORT.target, biens: batches[bi], dedup: _BIENS_IMPORT.dedupStrategy, listesNePasCreer: nePasCreer }, _biExtrasPayload()));
    if (!res.ok) {
      // Les lots précédents sont importés : le dire, avec les lignes concernées.
      _biShowApiError(res, useBatches
        ? 'Lot '+(bi+1)+'/'+batches.length+' (lignes '+(done+1)+'-'+(done+batches[bi].length)+') — '+done+' ligne(s) des lots précédents déjà importée(s)'
        : 'Import');
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
    if (useBatches) await new Promise(r => setTimeout(r, 50));
  }

  const r = totals;
  const rapport = _biRapport(r.errors, [], 'import');
  let html = '<div style="text-align:center;padding:24px 10px">';
  html += '<div style="font-size:48px;margin-bottom:10px">'+(r.failed ? '⚠️' : '🎉')+'</div>';
  html += '<div style="font-size:18px;font-weight:700;margin-bottom:14px">'+(r.failed ? 'Import terminé, avec des lignes en échec' : 'Import terminé !')+'</div>';
  html += '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:20px;max-width:500px;margin-left:auto;margin-right:auto">';
  html += '<div style="padding:14px;background:var(--blue-pale);border-radius:8px"><div style="font-size:24px;font-weight:700;color:var(--blue)">'+r.created+'</div><div style="font-size:11px">créés</div></div>';
  html += '<div style="padding:14px;background:#fef9c3;border-radius:8px"><div style="font-size:24px;font-weight:700;color:#a16207">'+r.updated+'</div><div style="font-size:11px">mis à jour</div></div>';
  html += '<div style="padding:14px;background:'+(r.failed?'#fee':'var(--gray-bg)')+';border-radius:8px"><div style="font-size:24px;font-weight:700;color:'+(r.failed?'#e74c3c':'var(--gray-text)')+'">'+r.failed+'</div><div style="font-size:11px">échecs</div></div>';
  html += '</div>';
  if (r.comptaCreated) html += '<div style="font-size:12px;color:var(--gray-text);margin-bottom:10px">+ '+r.comptaCreated+' entrée(s) en gestion matériel</div>';
  if (r.listesAdded)   html += '<div style="font-size:12px;color:var(--gray-text);margin-bottom:14px">+ '+r.listesAdded+' valeur(s) ajoutée(s) dans les listes</div>';
  html += '</div>';
  html += '<div style="text-align:left;max-width:680px;margin:0 auto">' + _biRapportHtml(rapport, '⚠️ <b>'+rapport.length+'</b> <span>point(s) à reprendre</span>') + '</div>';

  const reloadFn = _BIENS_IMPORT.target === 'equipements' ? 'renderEquipements' : 'renderBiens';
  html += '<div style="text-align:center;margin-top:10px"><button class="btn btn-primary" onclick="closeModal();typeof '+reloadFn+'===\'function\'&&'+reloadFn+'()">Fermer & rafraîchir</button></div>';
  c.innerHTML = html;
  if (typeof toast === 'function') toast('Import : '+r.created+' créés, '+r.updated+' mis à jour'+(r.failed ? ', '+r.failed+' échecs' : ''), r.failed ? 'warning' : 'success');
}

// ══════════════ Lecture des valeurs (dates, montants) ══════════════
// ⚠️ « 1.234,56 » devenait 1.234 (la virgule remplacée, le point de milliers
// gardé) ; « 31/02/2020 » devenait une date inventée ou disparaissait ; une date
// à l'année sur 2 chiffres ou un numéro de série Excel n'était pas lu. Les
// valeurs illisibles sont maintenant SIGNALÉES (étape 4 et rapport), jamais
// transformées en silence.
const _BI_MOIS = { janv: 1, jan: 1, fevr: 2, fev: 2, feb: 2, mars: 3, mar: 3, avr: 4, apr: 4, mai: 5, may: 5,
  juin: 6, jun: 6, juil: 7, jul: 7, aout: 8, aug: 8, sept: 9, sep: 9, oct: 10, nov: 11, dec: 12 };

function _biDateIso(y, m, d) {
  y = parseInt(y, 10); m = parseInt(m, 10); d = parseInt(d, 10);
  if (y < 100) y += (y < 50 ? 2000 : 1900);
  if (!(y >= 1900 && y <= 2100 && m >= 1 && m <= 12 && d >= 1 && d <= 31)) return null;
  const dt = new Date(Date.UTC(y, m - 1, d));
  if (dt.getUTCFullYear() !== y || dt.getUTCMonth() !== m - 1 || dt.getUTCDate() !== d) return null;   // 31/02
  return y + '-' + String(m).padStart(2, '0') + '-' + String(d).padStart(2, '0');
}

/** Date du fichier → « AAAA-MM-JJ », ou null si absente OU illisible (voir _biDateLisible). */
function _biParseDate(s) {
  if (s == null) return null;
  s = String(s).trim();
  if (!s || s === '0') return null;
  let m = s.match(/^(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})/);
  if (m) return _biDateIso(m[1], m[2], m[3]);
  m = s.match(/^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4}|\d{2})(?!\d)/);
  if (m) return _biDateIso(m[3], m[2], m[1]);
  if (/^\d{5}(\.\d+)?$/.test(s)) {                     // numéro de série Excel
    const n = parseFloat(s);
    if (n > 20000 && n < 80000) {
      const d = new Date(Math.round((n - 25569) * 86400 * 1000));
      if (!isNaN(d.getTime())) return d.toISOString().substring(0, 10);
    }
  }
  const t = s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
  m = t.match(/^(?:(\d{1,2})(?:er)?\s+)?([a-z]{3,9})\.?\s+(\d{4})$/);
  if (m) {
    for (const [k, no] of Object.entries(_BI_MOIS)) if (m[2].startsWith(k)) return _biDateIso(m[3], no, m[1] || 1);
  }
  return null;
}

/** La cellule est-elle vide, ou une date lisible ? (false = valeur à signaler) */
function _biDateLisible(s) {
  const v = String(s == null ? '' : s).trim();
  return v === '' || v === '0' || _biParseDate(v) !== null;
}

/** Montant du fichier → nombre ; null si la valeur est présente mais illisible. */
function _biNombre(s) {
  if (s == null) return 0;
  let t = String(s).trim();
  if (t === '' || t === '-') return 0;
  let neg = false;
  const par = t.match(/^\((.*)\)$/);
  if (par) { neg = true; t = par[1]; }
  t = t.replace(/[\s   '’]|€|eur(os?)?|\bht\b|\bttc\b/gi, '');
  if (t.startsWith('-')) { neg = !neg; t = t.slice(1); }
  if (!t || !/^[0-9.,]+$/.test(t)) return null;
  const p = t.lastIndexOf('.'), c = t.lastIndexOf(',');
  if (p >= 0 && c >= 0) {
    const dec = p > c ? '.' : ',';
    t = t.split(dec === '.' ? ',' : '.').join('').replace(',', '.');
  } else if (c >= 0) {
    t = (t.split(',').length > 2) ? t.split(',').join('') : t.replace(',', '.');
  } else if (p >= 0 && t.split('.').length > 2) {
    t = t.split('.').join('');
  }
  const n = parseFloat(t);
  if (isNaN(n) || !/^\d*\.?\d*$/.test(t)) return null;
  return neg ? -n : n;
}

function _biParseNumber(s) {
  const n = _biNombre(s);
  return n === null ? 0 : n;
}

function _biFmtEuro(n) {
  try { return Number(n || 0).toLocaleString('fr-FR', { style: 'currency', currency: 'EUR', maximumFractionDigits: 2 }); }
  catch (_e) { return (Math.round((n || 0) * 100) / 100) + ' €'; }
}

// ══════════════ Wrapper pour démarrer l'import directement en mode Équipements ══════════════
function openImportEquipements() {
  if (!canEdit()) { toast('Réservé aux Gestionnaires.', 'error'); return; }
  _biAnnulerLecture();
  Object.assign(_BIENS_IMPORT, {
    target: 'equipements',
    workbook: null, sheetNames: [], selectedSheet: null,
    rawRows: [], headerRowIdx: 0, headers: [], mapping: {},
    valueMap: { Statut: {}, Etat: {} }, dedupStrategy: 'update', step: 1,
    lignes: [], selFiltre: 'toutes', selRecherche: '', selPage: 1, prixMin: '', prixMax: '',
    listesNePasCreer: {}, dernierRapport: null, nouvellesCases: {},
  });
  _biChargerCases();
  _biRenderModal();
}

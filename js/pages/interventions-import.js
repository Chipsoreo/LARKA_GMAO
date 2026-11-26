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
 * Larka — Import d'interventions depuis Excel / CSV
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Même assistant, même présentation et mêmes outils que l'import des biens et
 * des équipements (biens-import.js), en cinq étapes :
 *
 *   1. Type             : auto-détection (colonne Type du fichier) ou tout en
 *                         Préventive / Curative / Contrôle réglementaire
 *   2. Fichier          : Excel ou CSV (lu dans un worker), feuille, ligne
 *                         d'en-tête détectée, aperçu ; modèle à télécharger
 *   3. Correspondances  : chaque colonne → un champ, avec un indice de confiance
 *                         (titre + contenu + mémoire du dernier import), aide de
 *                         l'IA si elle est configurée ; valeurs du fichier →
 *                         types, statuts et priorités de Larka ; doublons
 *   4. Sélection        : chaque ligne contrôlée tout de suite (description,
 *                         dates, durées, montants, statut…) ; filtres, recherche,
 *                         cases par type et par statut, période
 *   5. Aperçu & import  : le serveur analyse les lignes cochées sans rien écrire
 *                         (équipements, contrats, doublons), rapport détaillé et
 *                         téléchargeable, puis import par lots avec progression
 *
 * La lecture du fichier et des valeurs (dates, montants) est celle de l'import
 * des biens ; les règles de fond sont celles du serveur
 * (api/ImportInterventions.php) : l'aperçu montre exactement ce que l'import fera.
 * ═══════════════════════════════════════════════════════════════════════════════
 */

const _II = {
  step: 1, origine: 'interventions', mode: 'auto', typeDefaut: 'Curative',
  workbook: null, sheetNames: [], selectedSheet: null, rawRows: [], refs: [], orientation: 'lignes', orientationChoisie: false,
  planningPossible: false, planningToutes: true, planningIgnorees: [],
  headerRowIdx: 0, headers: [], mapping: {},
  valueMap: { type: {}, statut: {}, priorite: {} },
  statutDefaut: 'auto', dedup: 'update', doublons: 'ignorer',
  listes: { types: [], priorites: [] },
  lignes: [], selFiltre: 'toutes', selRecherche: '', selPage: 1, periodeDu: '', periodeAu: '',
  apercu: null, listesNePasCreer: {}, dernierRapport: null, iaDispo: null,
  cases: [],          // cases complémentaires déjà définies dans les fiches d'intervention
  nouvellesCases: {}, // { colIdx: { cle, libelle, type } } — créées par cet import
};

const _II_NATURES = ['Préventive', 'Contrôle réglementaire'];
const _II_STATUTS = ['Planifiée', 'En cours', 'Réalisée', 'Validée', 'Archivée'];
const _II_STATUT_LIB = { 'Validée': 'Validée (terminée)' };
const _II_MEMOIRE = 'larka.import.interventions.correspondances';
const _II_LOT = 500;
const _II_DATES = ['dateDemande', 'dateIntervention', 'dateRealisation', 'dateEnvoiMail'];

/**
 * Champs d'une intervention, décrits comme ceux de l'import des biens :
 *   exact    : titres de colonne qui désignent sûrement ce champ
 *   contains : groupes de mots qui, tous présents, le désignent probablement
 *              (un mot de 4 lettres ou plus vaut aussi pour son début : « realis »)
 *   negative : mots qui excluent le champ (sauf titre exact)
 *   contenu  : nature attendue des valeurs (date, nombre, duree, montant,
 *              courriel, telephone, categorie, texte, identifiant)
 */
const _II_CHAMPS = [
  { key: 'numero', label: "N° d'intervention", contenu: 'identifiant',
    exact: ['n intervention', 'numero intervention', 'n d intervention', 'numero d intervention', 'ref intervention', 'reference intervention', 'id intervention', 'code intervention', 'n ot', 'ordre de travail', 'ot', 'n bon d intervention', 'n bon intervention'],
    contains: [['intervention', 'n'], ['intervention', 'numero'], ['ordre', 'travail'], ['n', 'ot']],
    negative: ['equipement', 'bien', 'contrat', 'demande', 'serie', 'marche', 'commande'] },
  { key: 'type', label: 'Type', contenu: 'categorie',
    exact: ['type', 'type intervention', 'type d intervention', 'nature', 'nature intervention', 'type de maintenance', 'categorie', 'genre'],
    contains: [['type', 'intervention'], ['type', 'maintenance'], ['nature']],
    negative: ['budget', 'contrat', 'equipement', 'fluide', 'priorite', 'travaux'] },
  { key: 'statut', label: 'Statut', contenu: 'categorie',
    exact: ['statut', 'etat', 'status', 'avancement', 'etat intervention', 'statut intervention'],
    contains: [['statut'], ['avancement']], negative: ['equipement', 'bien', 'contrat', 'paiement'] },
  { key: 'priorite', label: 'Priorité', contenu: 'categorie',
    exact: ['priorite', 'urgence', 'criticite', 'niveau d urgence'], contains: [['priorite'], ['urgence']] },
  { key: 'description', label: 'Description', required: true, contenu: 'texte',
    exact: ['description', 'libelle', 'objet', 'intitule', 'titre', 'designation', 'travaux', 'travaux realises', 'motif', 'probleme', 'anomalie', 'descriptif', 'description intervention', 'nature des travaux'],
    contains: [['description'], ['libelle'], ['travaux'], ['objet'], ['intitule']], negative: ['equipement', 'famille', 'contrat', 'date'] },
  { key: 'actif', label: 'Équipement ou bien (n°)', contenu: 'identifiant',
    exact: ['equipement', 'n equipement', 'numero equipement', 'code equipement', 'equipements', 'bien', 'n bien', 'numero bien', 'materiel', 'installation', 'actif', 'ouvrage', 'code actif', 'repere', 'n inventaire'],
    contains: [['equipement'], ['materiel'], ['installation'], ['bien']], negative: ['date', 'type', 'famille', 'montant', 'cout'] },
  { key: 'contrat', label: 'Contrat (n°, référence ou société)',
    exact: ['contrat', 'n contrat', 'numero contrat', 'marche', 'n marche', 'reference contrat'],
    contains: [['contrat'], ['marche']], negative: ['date', 'montant', 'type'] },
  { key: 'societeManuelle', label: 'Prestataire',
    exact: ['prestataire', 'societe', 'entreprise', 'fournisseur', 'sous traitant', 'titulaire', 'societe intervenante', 'entreprise intervenante',
            'verifie par', 'verificateur', 'organisme', 'organisme de controle', 'organisme agree', 'bureau de controle', 'controle par', 'effectue par', 'realise par'],
    contains: [['prestataire'], ['societe'], ['entreprise'], ['fournisseur']] },
  { key: 'dateDemande', label: 'Date de demande', contenu: 'date',
    exact: ['date demande', 'date de demande', 'demande le', 'date signalement', 'date de signalement', 'date creation', 'date ouverture', 'date d ouverture'],
    contains: [['date', 'demande'], ['date', 'signalement'], ['date', 'ouverture'], ['date', 'creation']] },
  { key: 'dateIntervention', label: "Date prévue d'intervention", contenu: 'date',
    exact: ['date prevue', 'date intervention', 'date d intervention', 'date planifiee', 'echeance', 'date programmee', 'prevue le', 'date de passage', 'date prevue d intervention', 'planifiee le'],
    contains: [['date', 'prevue'], ['date', 'planif'], ['echeance'], ['date', 'passage'], ['date', 'intervention']] },
  { key: 'dateRealisation', label: 'Date de réalisation', contenu: 'date',
    exact: ['date realisation', 'date de realisation', 'realisee le', 'date fin', 'date de fin', 'date cloture', 'date de cloture', 'date execution', 'fait le', 'date effective', 'realise le', 'date reelle'],
    contains: [['date', 'realis'], ['date', 'cloture'], ['date', 'fin'], ['date', 'execution'], ['realise', 'le']] },
  { key: 'dureeHeures', label: 'Durée (heures)', contenu: 'duree',
    exact: ['duree', 'temps passe', 'heures', 'nb heures', 'nombre d heures', 'temps', 'duree h', 'duree heures'],
    contains: [['duree'], ['temps', 'passe'], ['heures']], negative: ['date'] },
  { key: 'montantHT', label: 'Montant HT', contenu: 'montant',
    exact: ['montant ht', 'cout ht', 'prix ht', 'total ht', 'ht', 'montant', 'cout', 'prix', 'montant hors taxes'],
    contains: [['montant', 'ht'], ['cout', 'ht'], ['prix', 'ht'], ['montant'], ['cout']], negative: ['ttc', 'tva'] },
  { key: 'montant', label: 'Montant TTC', contenu: 'montant',
    exact: ['montant ttc', 'cout ttc', 'prix ttc', 'total ttc', 'ttc'], contains: [['ttc']] },
  { key: 'commentaire', label: 'Commentaire',
    exact: ['commentaire', 'commentaires', 'observation', 'observations', 'remarque', 'remarques', 'notes', 'compte rendu', 'cr', 'rapport'],
    contains: [['commentaire'], ['observation'], ['remarque'], ['compte', 'rendu']] },
  { key: 'agentNom', label: "Nom de l'intervenant",
    exact: ['technicien', 'nom technicien', 'intervenant', 'nom intervenant', 'agent', 'nom agent', 'nom du technicien'],
    contains: [['technicien'], ['intervenant'], ['agent']], negative: ['prenom', 'tel', 'telephone', 'mail', 'email', 'courriel'] },
  { key: 'agentPrenom', label: "Prénom de l'intervenant", exact: ['prenom technicien', 'prenom intervenant', 'prenom agent', 'prenom'], contains: [['prenom']] },
  { key: 'agentTel', label: "Téléphone de l'intervenant", contenu: 'telephone',
    exact: ['telephone', 'tel', 'tel technicien', 'portable', 'telephone technicien'], contains: [['telephone'], ['tel']] },
  { key: 'agentEmail', label: "Courriel de l'intervenant", contenu: 'courriel',
    exact: ['email', 'courriel', 'mail', 'e mail', 'adresse mail'], contains: [['courriel'], ['email']], negative: ['date'] },
  { key: 'typeBudget', label: 'CBDC / CHMA', contenu: 'categorie',
    exact: ['cbdc', 'chma', 'cbdc chma', 'type budget', 'budget', 'imputation', 'imputation budgetaire'], contains: [['cbdc'], ['chma'], ['budget']] },
  { key: 'codeNumeroDemande', label: 'N° de demande / de bon',
    exact: ['n demande', 'numero demande', 'n bon de commande', 'bon de commande', 'bc', 'n bc', 'code demande', 'n ticket', 'ticket', 'n de demande'],
    contains: [['demande', 'n'], ['bon', 'commande'], ['ticket']], negative: ['date'] },
  { key: 'dateEnvoiMail', label: "Date d'envoi du courriel", contenu: 'date',
    exact: ['date envoi mail', 'date envoi', 'envoye le', 'date d envoi'], contains: [['date', 'envoi']] },
  { key: 'lieu', label: 'Lieu / bâtiment', contenu: 'texte',
    exact: ['lieu', 'lieux', 'batiment', 'batiments', 'site', 'sites', 'lieu batiment', 'localisation', 'emplacement', 'etablissement',
            'immeuble', 'adresse', 'agence', 'implantation', 'feuille', 'secteur', 'lieu d intervention'],
    contains: [['lieu'], ['batiment'], ['site'], ['localisation'], ['etablissement']],
    negative: ['date', 'montant', 'type', 'equipement', 'n'] },
];

/** Noms usuels → valeurs de Larka (le serveur connaît les mêmes, et quelques autres). */
const _II_SYN = {
  type: { 'Préventive': ['preventive', 'preventif', 'maintenance preventive', 'entretien', 'entretien preventif', 'prev', 'mp', 'visite preventive'],
          'Contrôle réglementaire': ['controle reglementaire', 'reglementaire', 'verification periodique', 'verification reglementaire', 'vgp', 'controle periodique'],
          'Curative': ['curative', 'curatif', 'correctif', 'corrective', 'maintenance corrective', 'depannage', 'reparation', 'panne', 'mc', 'sav'],
          'Divers': ['divers', 'autre', 'autres'] },
  statut: { 'Planifiée': ['planifiee', 'planifie', 'prevue', 'prevu', 'a faire', 'a planifier', 'en attente', 'programmee', 'nouvelle', 'ouverte', 'open', 'a venir'],
            'En cours': ['en cours', 'encours', 'commencee', 'demarree', 'in progress', 'en traitement'],
            'Réalisée': ['realisee', 'realise', 'faite', 'fait', 'effectuee', 'effectue', 'executee', 'done'],
            'Validée': ['validee', 'valide', 'terminee', 'termine', 'cloturee', 'cloture', 'close', 'clos', 'fermee', 'achevee', 'soldee'],
            'Archivée': ['archivee', 'archive'] },
  priorite: { 'Urgente': ['urgente', 'urgent', 'haute', 'elevee', 'critique', 'p1', 'tres urgent'],
              'Normale': ['normale', 'normal', 'moyenne', 'standard', 'p2'],
              'Basse': ['basse', 'faible', 'p3', 'non urgent'] },
};

/** « 2026-01-15 » → « 15/01/2026 ». */
const _iiDateFr = d => /^\d{4}-\d{2}-\d{2}/.test(String(d || '')) ? String(d).substring(0, 10).split('-').reverse().join('/') : String(d || '');
const _iiN = s => (typeof _biNormalize === 'function' ? _biNormalize(s) : String(s || '').toLowerCase().trim());
const _iiTypes = () => [...new Set([..._II_NATURES, 'Curative', 'Divers', ..._II.listes.types])];

// ══════════════ Ouverture ══════════════

/**
 * depuis : 'interventions' | 'preventif' | 'controles' — sur les écrans
 * Préventif et Contrôles réglementaires, le type est choisi d'avance (et
 * modifiable à l'étape 1).
 */
async function openImportInterventions(depuis = 'interventions') {
  if (typeof canEdit === 'function' && !canEdit()) { toast('Réservé aux Gestionnaires.', 'error'); return; }
  if (typeof _biAnnulerLecture === 'function') _biAnnulerLecture();
  const force = depuis === 'preventif' ? 'Préventive' : depuis === 'controles' ? 'Contrôle réglementaire' : '';
  Object.assign(_II, {
    step: 1, origine: depuis, mode: force ? force : 'auto', typeDefaut: 'Curative',
    workbook: null, sheetNames: [], selectedSheet: null, rawRows: [], refs: [], orientation: 'lignes', orientationChoisie: false,
    planningPossible: false, planningToutes: true, planningIgnorees: [],
    headerRowIdx: 0, headers: [], mapping: {},
    valueMap: { type: {}, statut: {}, priorite: {} }, statutDefaut: 'auto', dedup: 'update', doublons: 'ignorer',
    lignes: [], selFiltre: 'toutes', selRecherche: '', selPage: 1, periodeDu: '', periodeAu: '',
    apercu: null, listesNePasCreer: {}, dernierRapport: null, cases: [], nouvellesCases: {},
  });
  try { _II.cases = (typeof ChampsPerso !== 'undefined' ? await ChampsPerso.charger('interventions', true) : []) || []; } catch (_) { _II.cases = []; }
  try {
    const [types, prios] = await Promise.all([ListesApi.getByCategorie('TypeIntervention'), ListesApi.getByCategorie('PrioriteIntervention')]);
    _II.listes.types = (types || []).map(t => t.Valeur).filter(v => !_II_NATURES.includes(v));
    _II.listes.priorites = (prios || []).map(t => t.Valeur);
  } catch (_) { _II.listes = { types: ['Curative', 'Divers'], priorites: ['Basse', 'Normale', 'Urgente'] }; }
  if (!_II.listes.priorites.length) _II.listes.priorites = ['Basse', 'Normale', 'Urgente'];
  openModal('Importer des interventions', '<div id="iiImportContainer">' + _iiRenderStep() + '</div>', null);
  setTimeout(() => {
    // #modalOverlay (et non #confirmModal) : voir la même remarque dans biens-import.js.
    const m = document.querySelector('#modalOverlay');
    if (m) {
      const footer = m.querySelector('.modal-footer');
      if (footer) footer.style.display = 'none';
      const modal = m.querySelector('.modal');
      if (modal) { modal.style.maxWidth = '900px'; modal.style.width = '95vw'; }
    }
  }, 50);
}

function _iiRender() {
  const cont = document.getElementById('iiImportContainer');
  if (!cont) return;
  cont.innerHTML = _iiRenderStep();
  cont.querySelectorAll('input[data-partiel]').forEach(e => { e.indeterminate = true; });
}

function _iiRenderStep() {
  const s = _II.step;
  return _iiRenderStepper() + (s === 1 ? _iiStep1() : s === 2 ? _iiStep2() : s === 3 ? _iiStep3() : s === 4 ? _iiStep4() : _iiStep5());
}

function _iiRenderStepper() {
  const labels = ['1. Type', '2. Fichier', '3. Correspondances', '4. Sélection', '5. Aperçu & import'];
  const cur = _II.step;
  return '<div style="display:flex;gap:6px;margin-bottom:18px;font-size:11px">'
    + labels.map((l, i) => {
        const idx = i + 1, active = idx === cur, done = idx < cur;
        const bg = active ? 'var(--blue)' : (done ? 'var(--blue-pale)' : 'var(--gray-bg)');
        const col = active ? '#fff' : (done ? 'var(--blue)' : 'var(--gray-text)');
        return '<div style="flex:1;padding:8px 6px;border-radius:6px;background:' + bg + ';color:' + col + ';font-weight:600;text-align:center;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + l + '</div>';
      }).join('')
    + '</div>';
}

function _iiPied(retour, suivant, suivantActif = true, libelleSuivant = 'Suivant ▶') {
  return '<div style="display:flex;justify-content:space-between;margin-top:20px;padding-top:14px;border-top:1px solid var(--gray-border)">'
    + (retour ? '<button class="btn btn-secondary" onclick="' + retour + '">◀ Retour</button>' : '<button class="btn btn-secondary" onclick="closeModal()">Annuler</button>')
    + '<button class="btn btn-primary" onclick="' + suivant + '" ' + (suivantActif ? '' : 'disabled') + '>' + libelleSuivant + '</button></div>';
}

// ══════════════ ÉTAPE 1 — Type des interventions ══════════════
function _iiStep1() {
  const m = _II.mode;
  const carte = (val, icone, titre, texte, couleur, reco) => {
    const a = m === val;
    return '<div onclick="_iiSetMode(' + jsArg(val) + ')" style="cursor:pointer;padding:14px;border:2px solid ' + (a ? couleur : 'var(--gray-border)') + ';border-radius:10px;background:' + (a ? (val === 'auto' ? '#f0fdf4' : 'var(--blue-pale)') : 'var(--white)') + ';transition:all .15s;position:relative">'
      + (a && reco ? '<div style="position:absolute;top:6px;right:6px;background:#16a34a;color:#fff;font-size:9px;padding:2px 6px;border-radius:8px;font-weight:700">RECOMMANDÉ</div>' : '')
      + '<div style="font-size:24px;text-align:center;margin-bottom:6px">' + lk(icone) + '</div>'
      + '<div style="font-weight:700;text-align:center;font-size:13px;color:' + (a ? couleur : 'var(--text)') + '">' + titre + '</div>'
      + '<div style="font-size:11px;color:var(--gray-text);margin-top:6px;text-align:center;line-height:1.4">' + texte + '</div></div>';
  };
  let html = '<div style="font-size:13px;color:var(--gray-text);margin-bottom:14px">Quel type d\'interventions contient le fichier ?</div>';
  html += '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:18px">';
  html += carte('auto', 'wand-sparkles', 'Auto-détection', 'Le type de chaque ligne est lu dans sa colonne « Type ».', '#16a34a', true);
  html += carte('Préventive', 'calendar-days', 'Tout en Préventif', 'Entretiens, visites périodiques.', 'var(--blue)');
  html += carte('Curative', 'wrench', 'Tout en Curatif', 'Dépannages, réparations.', 'var(--blue)');
  html += carte('Contrôle réglementaire', 'circle-check', 'Tout en Contrôles', 'Vérifications périodiques obligatoires.', 'var(--blue)');
  html += '</div>';
  if (m === 'auto') {
    html += '<div style="padding:12px;background:#fef9c3;border:1px solid #eab308;border-radius:8px;margin-bottom:14px;font-size:12px;color:#713f12">'
      + '<strong>' + lk('wand-sparkles') + ' Comment fonctionne l\'auto-détection ?</strong><br>'
      + '• <strong>Préventif, entretien, visite…</strong> → Préventive ; <strong>VGP, vérification périodique, réglementaire…</strong> → Contrôle réglementaire<br>'
      + '• <strong>Correctif, curatif, dépannage, réparation…</strong> → Curative ; un type inconnu est proposé à l\'ajout dans la liste des types<br>'
      + '• Ligne sans type : <select class="form-control" style="display:inline-block;width:auto;font-size:12px;padding:2px 6px" onchange="_II.typeDefaut=this.value">'
      + _iiTypes().map(t => '<option ' + (t === _II.typeDefaut ? 'selected' : '') + '>' + _esc(t) + '</option>').join('') + '</select></div>';
  } else if (_II_NATURES.includes(m)) {
    html += '<div style="padding:12px;background:var(--blue-pale);border:1px solid var(--blue);border-radius:8px;margin-bottom:14px;font-size:12px">'
      + lk('info') + ' Les interventions importées apparaîtront sur l\'écran <strong>' + (m === 'Préventive' ? 'Préventif' : 'Contrôles réglementaires') + '</strong>, dans la liste « Hors plan » : un historique de passages, sans plan à rattacher.</div>';
  }
  return html + _iiPied(null, '_II.step=2;_iiRender()');
}

function _iiSetMode(m) { _II.mode = m; _iiRender(); }

// ══════════════ ÉTAPE 2 — Fichier + feuille ══════════════
function _iiStep2() {
  let html = '<div style="text-align:center;padding:24px 20px;border:2px dashed var(--gray-border);border-radius:10px;background:var(--gray-bg);margin-bottom:14px">';
  html += '<div style="font-size:28px;margin-bottom:8px">' + lk('folder-open') + '</div>';
  html += '<div style="font-size:13px;color:var(--text);margin-bottom:12px">Fichier <strong>Excel (.xlsx, .xls)</strong> ou <strong>CSV</strong> : une intervention par ligne</div>';
  html += '<input type="file" id="iiFile" accept=".xlsx,.xls,.csv" onchange="_iiFileSelected(event)" style="display:none">';
  html += '<button class="btn btn-primary" onclick="document.getElementById(\'iiFile\').click()">' + lk('folder') + ' Choisir un fichier</button> ';
  html += '<button class="btn btn-ghost" onclick="_iiModele()" title="Un fichier CSV avec les colonnes reconnues et deux exemples">' + lk('download') + ' Télécharger un modèle</button>';
  html += '<div id="iiFileInfo" style="margin-top:12px;font-size:12px;color:var(--gray-text)"></div>';
  html += '</div>';
  if (_II.sheetNames.length) {
    html += '<div style="display:flex;gap:14px;margin-bottom:12px;align-items:end;flex-wrap:wrap">';
    html += '<div style="flex:1;min-width:200px"><label class="form-label">Feuille à importer</label><select class="form-control" onchange="_iiSheetSelected(this.value)">'
      + _II.sheetNames.map(s => '<option value="' + _esc(s) + '" ' + (s === _II.selectedSheet ? 'selected' : '') + '>' + _esc(s) + '</option>').join('') + '</select></div>';
    if (_II.rawRows.length && _II.orientation !== 'planning') {
      html += '<div><label class="form-label">' + (_II.orientation === 'colonnes' ? 'Colonne des titres' : 'Ligne d\'en-tête') + '</label><select class="form-control" onchange="_iiHeaderRowChanged(this.value)">';
      for (let i = 0; i < Math.min(20, _II.rawRows.length); i++) {
        const ap = (_II.rawRows[i] || []).map(c => String(c || '').trim()).filter(Boolean).slice(0, 3).join(' · ');
        html += '<option value="' + i + '" ' + (i === _II.headerRowIdx ? 'selected' : '') + '>' + _esc(_biRefLong(_II.orientation, _II.refs[i] || i + 1)) + (ap ? ' — ' + _esc(ap.substring(0, 50)) : '') + '</option>';
      }
      html += '</select></div>';
    }
    html += '</div>';
    html += _biOrientationHtml(_II, '_iiSetOrientation');
    if (_II.orientation === 'planning') html += _iiPlanningHtml();
    if (_II.rawRows.length) html += _iiApercuFichier();
  }
  return html + _iiPied('_II.step=1;_iiRender()', '_iiGoStep3()', _II.rawRows.length > 0);
}

function _iiApercuFichier() {
  const debut = Math.max(0, _II.headerRowIdx - 1);
  const lignes = _II.rawRows.slice(debut, debut + 6);
  const nb = Math.max(0, _II.rawRows.length - _II.headerRowIdx - 1);
  let html = '<div style="margin-bottom:10px"><div style="font-size:11px;color:var(--gray-text);margin-bottom:4px">'
    + (_II.orientation === 'planning' ? 'Planning déplié : une ligne par passage daté — <strong>' + nb + ' interventions</strong>'
      + (_II.planningFeuilles && _II.planningFeuilles.length > 1 ? ' sur ' + _II.planningFeuilles.length + ' feuilles' : '')
      : _II.orientation === 'colonnes' ? 'Aperçu, remis à l\'endroit (une intervention par ligne), autour des titres (en bleu) — <strong>' + nb + ' interventions</strong>'
                                      : 'Aperçu autour de la ligne d\'en-tête (en bleu) — <strong>' + nb + ' lignes de données</strong>') + '</div>';
  html += '<div style="overflow-x:auto;border:1px solid var(--gray-border);border-radius:6px;max-height:200px"><table style="width:100%;font-size:11px;border-collapse:collapse">';
  lignes.forEach((row, k) => {
    const ri = debut + k, entete = ri === _II.headerRowIdx;
    html += '<tr style="' + (entete ? 'background:var(--blue-pale);font-weight:700' : ri % 2 ? 'background:var(--gray-bg)' : '') + '">';
    row.slice(0, 14).forEach(c => { html += '<td style="padding:4px 8px;border-right:1px solid var(--gray-border);max-width:140px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + _esc(String(c || '')) + '</td>'; });
    if (row.length > 14) html += '<td style="padding:4px 8px;color:var(--gray-text)">… +' + (row.length - 14) + '</td>';
    html += '</tr>';
  });
  return html + '</table></div></div>';
}

async function _iiFileSelected(evt) {
  const file = evt.target.files[0];
  if (!file) return;
  _biAnnulerLecture();
  const jeton = _biJetonLecture;
  Object.assign(_II, { workbook: null, sheetNames: [], selectedSheet: null, rawRows: [], refs: [], headerRowIdx: 0, headers: [], mapping: {}, step: 2,
    orientationChoisie: false, planningPossible: false, planningToutes: true, planningIgnorees: [], planningFeuilles: [] });   // nouveau fichier : disposition re-détectée
  _iiRender();
  const afficher = h => { const el = document.getElementById('iiFileInfo'); if (el) el.innerHTML = h; };
  afficher(lk('hourglass') + ' Lecture en cours...');
  try {
    const buf = await file.arrayBuffer();
    if (jeton !== _biJetonLecture) return;
    const wb = await _biLireClasseur(buf, undefined, file.name);
    if (jeton !== _biJetonLecture) return;
    _II.workbook = wb; _II.sheetNames = wb.SheetNames;
    // Feuille la plus prometteuse : son nom parle d'interventions, de maintenance, d'historique.
    let best = wb.SheetNames[0], bestScore = -1;
    wb.SheetNames.forEach(s => {
      const n = _iiN(s);
      const sc = (/interv|maint|histor|travaux|curati|preventi|ot\b|bon|depann/.test(n) ? 60 : 0) - (/instruction|mode d emploi|liste|parametre|recap|mapping/.test(n) ? 80 : 0);
      if (sc > bestScore) { bestScore = sc; best = s; }
    });
    afficher('<span style="color:#16a34a">' + lk('circle-check', 'ok') + ' ' + _esc(file.name) + ' chargé (' + wb.SheetNames.length + ' feuille' + (wb.SheetNames.length > 1 ? 's' : '') + ')</span>');
    _iiSheetSelected(best);
    afficher('<span style="color:#16a34a">' + lk('circle-check', 'ok') + ' ' + _esc(file.name) + ' chargé (' + wb.SheetNames.length + ' feuille' + (wb.SheetNames.length > 1 ? 's' : '') + ')</span>');
  } catch (e) {
    if ((e && e.annulee) || jeton !== _biJetonLecture) return;
    afficher('<span style="color:#e74c3c">' + lk('circle-x', 'ko') + ' Erreur : ' + _esc(e.message) + '</span>');
  }
}

function _iiSheetSelected(nom) {
  _II.selectedSheet = nom;
  const brutes = (_II.workbook.feuilles.get(nom) || []).map(r => r.slice());
  // Disposition (une intervention par ligne, par colonne, ou planning) : détectée, sauf choix de l'utilisateur.
  _II.planningPossible = !!_iiPlanningDetecter(brutes);
  if (!_II.orientationChoisie) _II.orientation = _II.planningPossible ? 'planning' : _biOrientationProbable(brutes, _iiCelluleEstTitre);
  if (_II.orientation === 'planning' && !_II.planningPossible) _II.orientation = 'lignes';
  _II.planningIgnorees = []; _II.planningFeuilles = [];
  if (_II.orientation === 'planning') {
    const toutes = _II.planningToutes ? _iiPlanningFeuilles() : [];
    const d = _iiPlanningDeplier(toutes.length > 1 ? toutes : [nom]);
    _II.rawRows = d.rows; _II.refs = d.refs; _II.planningIgnorees = d.ignorees; _II.planningFeuilles = d.feuilles;
    _II.headerRowIdx = 0;
  } else {
    const f = _biPreparerFeuille(brutes, _II.orientation);
    _II.rawRows = f.rows; _II.refs = f.refs;
    _II.headerRowIdx = _iiDetecterEntete(_II.rawRows);
  }
  _iiUpdateHeaders();
  _iiRender();
}
function _iiCelluleEstTitre(c) {
  if (_biNombre(c) !== null || _biParseDate(c)) return false;
  return _II_CHAMPS.some(f => _iiScoreLabel(c, f).score >= 30);
}
function _iiSetOrientation(o) {
  _II.orientation = (o === 'colonnes' || o === 'planning') ? o : 'lignes';
  _II.orientationChoisie = true;
  if (_II.workbook && _II.selectedSheet) _iiSheetSelected(_II.selectedSheet); else _iiRender();
}
const _iiRef = n => (_II.orientation === 'planning' ? String(_II.refs[n] || '#' + n) : _biRefCourt(_II.orientation, n));
/** Titre de la colonne « où est-ce dans mon fichier ? » des tableaux et du rapport. */
const _iiRefTitre = () => (_II.orientation === 'planning' ? 'Cellule' : _II.orientation === 'colonnes' ? 'Colonne' : 'Ligne');

// ── Planning croisé (une ligne par matériel, une colonne par année) ─────────
//
// Beaucoup de suivis de contrôles réglementaires ne sont ni « une intervention
// par ligne » ni « une par colonne », mais un PLANNING : le matériel en
// colonne A, sa périodicité, puis pour chaque année un groupe de colonnes
// (« vérifié par », « date »), avec des cellules fusionnées (un prestataire
// pour tout un lot de lignes), des sous-lignes indentées (« - contrôle
// triennal » sous le matériel), plusieurs passages par an sur des lignes de
// suite, et des dates partielles (« nov », « 27 aout »).
//
// On le DÉPLIE avant tout le reste : une ligne par passage daté, avec des
// colonnes que l'assistant connaît déjà (Description, Prestataire, Date
// prévue, Réalisé le, Type, Commentaire). Correspondances, contrôles, aperçu
// et import ne changent pas. Ce qui n'est pas une date (« ok », « pas fait »,
// « reporté en 2025 ») ne devient PAS une intervention : la cellule est
// listée, pour que rien ne disparaisse sans le dire.

/** Mois écrits en toutes lettres ou abrégés (sans accents), dans un texte libre. */
// Les formes longues tolèrent une faute en fin de mot (« févrtier ») ; les
// courtes (« mai », « dec ») doivent être des mots entiers : « maintenance »,
// « décidé » ne sont pas des mois.
const _II_MOIS_RE = /(?:(\d{1,2})(?:er)?\s*-?\s*)?(?<![a-z])((?:janv|fevr|avri|juil|sept|octo|nove|dece|mars|juin|aout)[a-z]*|jan|fev|avr|mai|sep|oct|nov|dec)(?![a-z])\.?(?:\s*(\d{4}))?/g;
const _II_MOIS_NO = { jan: 1, fev: 2, mar: 3, avr: 4, mai: 5, juin: 6, juil: 7, aou: 8, sep: 9, oct: 10, nov: 11, dec: 12 };

/** Ce que le texte d'une case « date » dit d'un passage NON fait : jamais une intervention. */
const _II_PAS_FAIT = /pas fait|non (?:realise|fait|effectue)|report|supprim|demantel|en panne|\bhs\b|pas de |annul|a prevoir|change|installe|contrat|garantie/;

/**
 * Texte d'une case « date » + année de la colonne → dates ISO.
 * note : ce qu'il faut garder en commentaire (texte d'origine, jour supposé).
 */
function _iiPlanningDates(txt, annee) {
  const brut = String(txt == null ? '' : txt).trim();
  if (!brut) return { dates: [], note: '' };
  if (/^\d{4}-\d{2}-\d{2}$/.test(brut)) return { dates: [brut], note: '' };   // vraie date Excel
  const t = brut.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
  if (_II_PAS_FAIT.test(t)) return { dates: [], note: '' };
  // « 16 et 17 janv », « 3 au 5 mai » : un passage sur plusieurs jours, daté de son premier jour.
  const tj = t.replace(/(\d{1,2})\s*(?:et|&|au|a)\s*\d{1,2}(?=\s*-?\s*[a-z])/g, '$1');
  const dates = [];
  for (const m of t.matchAll(/(\d{1,2})[/.](\d{1,2})[/.](\d{4}|\d{2})(?!\d)/g)) {
    const d = _biDateIso(m[3], m[2], m[1]);
    if (d && !dates.includes(d)) dates.push(d);
  }
  if (dates.length) return { dates, note: /[a-z]{3,}/.test(t.replace(/\bet\b/g, '')) ? '« ' + brut + ' »' : '' };
  let jourSuppose = false;
  for (const m of tj.matchAll(_II_MOIS_RE)) {
    const cle = Object.keys(_II_MOIS_NO).find(k => m[2].startsWith(k));
    const an = m[3] ? parseInt(m[3], 10) : annee;
    if (!cle || !an) continue;
    if (!m[1]) jourSuppose = true;
    const d = _biDateIso(an, _II_MOIS_NO[cle], m[1] || 1);
    if (d && !dates.includes(d)) dates.push(d);
  }
  if (!dates.length) return { dates: [], note: '' };
  return { dates, note: 'Date d\'origine « ' + brut + ' »' + (jourSuppose ? ' (jour non précisé : le 1er retenu)' : '') };
}

/**
 * La feuille est-elle un planning ? Une ligne (dans les 20 premières) porte au
 * moins 3 années à intervalle régulier, précédées d'au moins une colonne de
 * libellés ; si chaque année couvre plusieurs colonnes, la ligne suivante
 * nomme ces colonnes de la même façon d'une année à l'autre.
 */
function _iiPlanningDetecter(brutes) {
  const txt = (r, c) => String(((brutes[r] || [])[c]) == null ? '' : brutes[r][c]).trim();
  for (let i = 0; i < Math.min(20, brutes.length); i++) {
    const ans = [];
    (brutes[i] || []).forEach((v, c) => { if (/^(19[5-9]\d|20\d\d)$/.test(String(v).trim())) ans.push(c); });
    if (ans.length < 3 || ans[0] < 1) continue;
    const pas = ans[1] - ans[0];
    if (pas < 1 || pas > 4 || ans.some((c, k) => k && c - ans[k - 1] !== pas)) continue;
    let sous = ['date'], ligneDonnees = i + 1;
    if (pas > 1) {
      sous = [];
      for (let o = 0; o < pas; o++) sous.push(txt(i + 1, ans[0] + o));
      if (!sous.some(Boolean)) continue;
      const pareil = ans.filter(c => sous.every((s, o) => _iiN(txt(i + 1, c + o)) === _iiN(s))).length;
      if (pareil < ans.length * 2 / 3) continue;
      ligneDonnees = i + 2;
    }
    // Colonne de la date dans un groupe : par son titre, sinon par son contenu.
    let offDate = sous.findIndex(s => /\bdate\b|\ble$|fait|realis|passage/.test(_iiN(s)));
    if (offDate < 0) {
      let best = -1;
      sous.forEach((s, o) => {
        let n = 0;
        for (let r = ligneDonnees; r < Math.min(brutes.length, ligneDonnees + 60); r++) {
          for (const c of ans) if (_iiPlanningDates(txt(r, c + o), 2000).dates.length) n++;
        }
        if (n > best) { best = n; offDate = o; }
      });
    }
    const fixes = [];
    for (let c = 0; c < ans[0]; c++) fixes.push({ col: c, label: txt(i, c) || (pas > 1 ? txt(i + 1, c) : '') || 'Colonne ' + _biColLettre(c + 1) });
    let colLibelle = fixes.findIndex(f => /materiel|equipement|installation|designation|libelle|objet|ouvrage|controle|verification/.test(_iiN(f.label)));
    if (colLibelle < 0) colLibelle = 0;
    return { ligneTitres: i, ligneDonnees, pas, offDate, sous, fixes, colLibelle,
             groupes: ans.map(c => ({ col: c, annee: parseInt(txt(i, c), 10) })) };
  }
  return null;
}

/** Feuille(s) planning → { rows (rows[0] = titres), refs, ignorees, feuilles }. */
function _iiPlanningDeplier(noms) {
  const plans = noms.map(nom => {
    const brutes = _II.workbook.feuilles.get(nom) || [];
    return { nom, brutes, fusions: (_II.workbook.fusions && _II.workbook.fusions.get(nom)) || [], plan: _iiPlanningDetecter(brutes) };
  }).filter(p => p.plan);
  // Titres des colonnes « en plus » de la date (vérifié par…) : ceux de la première feuille.
  const p0 = plans[0];
  const autres = p0 ? p0.plan.sous.map((s, o) => ({ o, label: s || 'Colonne ' + (o + 1) })).filter(x => x.o !== p0.plan.offDate) : [];
  // Plusieurs feuilles (une par site) : le nom de la feuille devient le lieu de chaque passage.
  const rows = [['Description', 'Année'].concat(autres.map(a => a.label), ['Date prévue', 'Réalisé le', 'Type', 'Commentaire', 'Lieu'])];
  const refs = [''], ignorees = [], vus = new Set();
  const aujourdhui = new Date().toISOString().substring(0, 10);
  const plusieurs = plans.length > 1 || (_II.sheetNames.length > 1 && _II.planningToutes);
  plans.forEach(({ nom, brutes, fusions, plan }) => {
    // Cellule fusionnée : la valeur de son coin haut-gauche vaut pour toute la
    // hauteur de la zone, dans SA colonne (un prestataire pour 6 lignes).
    const valeur = (r, c) => {
      const v = (brutes[r] || [])[c];
      if (v != null && String(v).trim() !== '') return String(v);
      const z = fusions.find(z => r >= z[0] && r <= z[2] && c === z[1] && r > z[0]);
      return z ? String(((brutes[z[0]] || [])[z[1]]) || '') : '';
    };
    const ref = (r, c) => (plusieurs ? nom + '!' : '') + _biColLettre(c + 1) + (r + 1);
    let parent = '', parentCourt = '', precedent = '';
    for (let r = plan.ligneDonnees; r < brutes.length; r++) {
      if (!(brutes[r] || []).some(c => String(c || '').trim())) continue;
      const libBrut = valeur(r, plan.fixes[plan.colLibelle].col);
      let lib;
      if (!libBrut.trim()) lib = precedent;                 // ligne de suite : même matériel
      else {
        const propre = libBrut.replace(/^[\s\-–•]+/, '').replace(/\s*:\s*-\s*/g, ' : ').replace(/\s+/g, ' ').trim();
        const enfant = /^(\s{2,}|\s*[-–•])/.test(libBrut);
        if (enfant && parent) lib = parentCourt + ' — ' + propre;
        else {
          lib = parent = propre;
          // Rappel du parent devant ses sous-lignes : son début, jusqu'au premier « : », « ( » ou « , ».
          const court = propre.split(/\s:\s|\(|,/)[0].trim();
          parentCourt = (court || propre).length > 45 ? (court || propre).slice(0, 45).replace(/\s+\S*$/, '') + '…' : (court || propre);
        }
      }
      precedent = lib;
      if (!lib) continue;
      // Le site n'est plus collé à la description (« Extincteurs — Bordeaux ») : il va dans
      // le lieu. Le serveur reconnaît encore les passages importés avant sous l'ancienne forme.
      const description = lib;
      const lieu = plusieurs ? nom : '';
      const infos = plan.fixes.filter((f, k) => k !== plan.colLibelle)
        .map(f => { const v = valeur(r, f.col).replace(/\s+/g, ' ').trim(); return v ? f.label.charAt(0).toUpperCase() + f.label.slice(1) + ' : ' + v : ''; })
        .filter(Boolean);
      const type = /maintenance|entretien|nettoyage/i.test(lib) ? 'Préventive' : 'Contrôle réglementaire';
      plan.groupes.forEach(g => {
        const vals = plan.sous.map((s, o) => valeur(r, g.col + o).replace(/\s+/g, ' ').trim());
        if (!vals.some(Boolean)) return;
        const lu = _iiPlanningDates(vals[plan.offDate], g.annee);
        if (!lu.dates.length) {
          ignorees.push({ ref: ref(r, g.col + plan.offDate), description, annee: g.annee,
                          valeur: vals[plan.offDate] || ('date vide — ' + autres.filter(a => vals[a.o]).map(a => a.label + ' : ' + vals[a.o]).join(' · ')) });
          return;
        }
        const extra = autres.map(a => vals[a.o] || '');
        const commentaire = infos.concat(lu.note ? [lu.note] : []).join(' · ');
        lu.dates.forEach(d => {
          // Une date fusionnée sur les lignes de suite d'un même matériel (L11:L13)
          // est UN passage, pas trois.
          const cle = description + '\u0001' + d + '\u0001' + type + '\u0001' + lieu;
          if (vus.has(cle)) return;
          vus.add(cle);
          rows.push([description, String(g.annee)].concat(extra, [d, d <= aujourdhui ? d : '', type, commentaire, lieu]));
          refs.push(ref(r, g.col + plan.offDate));
        });
      });
    }
  });
  return { rows, refs, ignorees, feuilles: plans.map(p => p.nom) };
}

/** Feuilles du classeur qui sont des plannings (même forme : importables ensemble). */
function _iiPlanningFeuilles() {
  return (_II.sheetNames || []).filter(n => _iiPlanningDetecter(_II.workbook.feuilles.get(n) || []));
}

function _iiPlanningToutes(oui) {
  _II.planningToutes = !!oui;
  if (_II.workbook && _II.selectedSheet) _iiSheetSelected(_II.selectedSheet);
}

/** Étape Fichier, en mode planning : ce qui a été déplié, et ce qui ne l'a pas été. */
function _iiPlanningHtml() {
  const feuilles = _iiPlanningFeuilles();
  let html = '';
  if (feuilles.length > 1) {
    html += '<label style="display:flex;gap:8px;align-items:flex-start;margin-bottom:10px;font-size:12px;cursor:pointer;padding:8px 10px;background:var(--gray-bg);border-radius:8px">'
      + '<input type="checkbox" ' + (_II.planningToutes ? 'checked' : '') + ' onchange="_iiPlanningToutes(this.checked)" style="margin-top:2px">'
      + '<span><strong>Importer les ' + feuilles.length + ' feuilles de ce planning</strong> (' + _esc(feuilles.join(', ')) + ')'
      + '<br><span style="color:var(--gray-text)">Le nom de la feuille (le site) devient le lieu / bâtiment de chaque passage.</span></span></label>';
  }
  const ig = _II.planningIgnorees || [];
  if (ig.length) {
    html += '<details style="margin-bottom:10px;font-size:12px;border:1px solid var(--gray-border);border-radius:8px;padding:8px 10px">'
      + '<summary style="cursor:pointer"><strong>' + ig.length + ' case' + (ig.length > 1 ? 's' : '') + ' sans date</strong> laissée' + (ig.length > 1 ? 's' : '')
      + ' de côté (« ok », « pas fait », « reporté »…) : elles ne deviennent pas des interventions.</summary>'
      + '<div style="max-height:220px;overflow:auto;margin-top:8px"><table style="width:100%;border-collapse:collapse;font-size:11px">'
      + '<tr style="font-weight:700;background:var(--gray-bg)"><td style="padding:4px 6px">Cellule</td><td style="padding:4px 6px">Matériel</td><td style="padding:4px 6px">Année</td><td style="padding:4px 6px">Contenu</td></tr>'
      + ig.slice(0, 500).map(x => '<tr style="border-top:1px solid var(--gray-bg)"><td style="padding:3px 6px;white-space:nowrap;color:var(--gray-text)">' + _esc(x.ref) + '</td><td style="padding:3px 6px">' + _esc(x.description)
        + '</td><td style="padding:3px 6px">' + _esc(String(x.annee)) + '</td><td style="padding:3px 6px">' + _esc(x.valeur) + '</td></tr>').join('')
      + '</table></div></details>';
  }
  return html;
}

function _iiHeaderRowChanged(i) { _II.headerRowIdx = parseInt(i, 10) || 0; _iiUpdateHeaders(); _iiRender(); }

/** Ligne d'en-tête : même règle que l'import des biens (libellés reconnus, peu de nombres, des données dessous). */
function _iiDetecterEntete(rows) {
  let best = 0, bestScore = -Infinity;
  for (let i = 0; i < Math.min(20, rows.length); i++) {
    const cells = rows[i].map(c => String(c == null ? '' : c).trim());
    const remplies = cells.filter(Boolean);
    if (remplies.length < 2) continue;
    const textes = remplies.filter(c => _biNombre(c) === null && !_biParseDate(c));
    const reconnus = remplies.filter(c => _II_CHAMPS.some(f => _iiScoreLabel(c, f).score >= 30)).length;
    const distincts = new Set(remplies.map(c => c.toLowerCase())).size;
    let dessous = 0;
    for (let j = i + 1; j < Math.min(rows.length, i + 6); j++) {
      const k = cells.filter((c, idx) => c && String(rows[j][idx] == null ? '' : rows[j][idx]).trim()).length;
      if (k >= Math.max(1, remplies.length / 2)) dessous++;
    }
    const score = remplies.length * 2 + textes.length * 2 + reconnus * 12 + (distincts === remplies.length ? 5 : 0) + dessous * 4
      - (remplies.length - textes.length) * 4;
    if (score > bestScore) { bestScore = score; best = i; }
  }
  return best;
}

/** Modèle : les titres reconnus d'emblée, et deux lignes d'exemple. */
function _iiModele() {
  const cols = ['N° intervention', 'Type', 'Statut', 'Priorité', 'Description', 'Équipement', 'Contrat', 'Prestataire', 'Date de demande',
                'Date prévue', 'Date de réalisation', 'Durée', 'Montant HT', 'Commentaire', 'Technicien'];
  const ex = [['', 'Préventive', 'Terminée', 'Normale', 'Entretien annuel de la chaudière', 'EQ-0012', 'CT-2026-04', 'Thermique Services', '05/01/2026', '15/01/2026', '15/01/2026', '2h30', '480,00', 'RAS', 'Martin'],
              ['', 'Curative', 'Planifiée', 'Urgente', 'Fuite sur la nourrice du 2e étage', 'EQ-0031', '', 'Plomberie Durand', '12/02/2026', '14/02/2026', '', '', '', '', '']];
  const q = v => /[;"\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v;
  _iiTelecharger('﻿' + [cols, ...ex].map(r => r.map(q).join(';')).join('\r\n'), 'modele-import-interventions.csv');
}
function _iiTelecharger(contenu, nom) {
  const a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob([contenu], { type: 'text/csv;charset=utf-8' }));
  a.download = nom;
  document.body.appendChild(a); a.click();
  setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
}

// ══════════════ Détection des correspondances ══════════════

/** Score d'un titre de colonne pour un champ (0 à 50), comme _biScoreLabel, avec les débuts de mots. */
function _iiScoreLabel(label, f) {
  const norm = _iiN(label);
  if (!norm) return { score: 0, reasons: [] };
  const mots = norm.split(' ').filter(Boolean);
  const a = w => mots.some(m => m === w || (w.length >= 4 && m.startsWith(w)));
  const exact = (f.exact || []).some(e => _iiN(e) === norm);
  if (exact) return { score: 50, reasons: ['libellé exact « ' + label + ' »'] };
  for (const neg of (f.negative || [])) if (mots.includes(_iiN(neg))) return { score: 0, reasons: ['exclu (contient « ' + neg + ' »)'] };
  let best = 0, raison = '';
  for (const g of (f.contains || [])) {
    if (g.every(w => a(_iiN(w)))) { const s = 30 + Math.min(10, g.length * 5); if (s > best) { best = s; raison = 'contient [' + g.join(', ') + ']'; } }
  }
  for (const e of (f.exact || [])) {
    const en = _iiN(e);
    if (en.length >= 4 && norm.includes(en)) { const s = 35 + Math.min(10, en.length / 2); if (s > best) { best = s; raison = 'contient le libellé « ' + e + ' »'; } }
  }
  return { score: best, reasons: raison ? [raison] : [] };
}

/** Score du contenu d'une colonne pour un champ (0 à 50). */
function _iiScoreContent(samples, f) {
  if (!samples.length || !f.contenu) return { score: 0, reasons: [] };
  const n = samples.length;
  const part = test => samples.filter(test).length / n;
  const distincts = new Set(samples.map(v => _iiN(v))).size;
  const lgMoy = samples.reduce((t, v) => t + v.length, 0) / n;
  switch (f.contenu) {
    case 'date': { const p = part(v => !/^\d{5}$/.test(v) && !!_biParseDate(v)); return p > .8 ? { score: 30, reasons: [Math.round(p * 100) + ' % de dates'] } : p > .5 ? { score: 15, reasons: ['majorité de dates'] } : { score: 0, reasons: [] }; }
    case 'montant': {
      const p = part(v => _biNombre(v) !== null && /\d/.test(v));
      let s = p > .8 ? 30 : p > .5 ? 15 : 0; const r = s ? [Math.round(p * 100) + ' % de nombres'] : [];
      if (s && samples.some(v => /€|eur/i.test(v) || /[.,]\d{2}$/.test(v))) { s += 8; r.push('format monétaire'); }
      return { score: s, reasons: r };
    }
    case 'duree': { const p = part(v => _iiHeures(v) !== null); const h = samples.some(v => /\d\s*h|min|:/i.test(v)); return p > .8 ? { score: h ? 35 : 15, reasons: [h ? 'durées (2h30, 90 min…)' : 'nombres'] } : { score: 0, reasons: [] }; }
    case 'courriel': { const p = part(v => /^[^@\s]+@[^@\s]+\.[a-z]{2,}$/i.test(v)); return p > .7 ? { score: 40, reasons: ['adresses de courriel'] } : { score: 0, reasons: [] }; }
    case 'telephone': { const p = part(v => /^[+0][\d\s.-]{8,}$/.test(v)); return p > .7 ? { score: 35, reasons: ['numéros de téléphone'] } : { score: 0, reasons: [] }; }
    case 'categorie': {
      if (distincts > 12 || n < 3 || lgMoy > 30) return { score: 0, reasons: [] };
      let s = 15; const r = ['valeurs catégorielles (' + distincts + ' distinctes)'];
      const syn = _II_SYN[f.key];
      if (syn) {
        const connus = new Set(Object.entries(syn).flatMap(([k, v]) => [_iiN(k), ...v]));
        if (samples.filter(v => connus.has(_iiN(v))).length / n > .5) { s += 30; r.push('valeurs typiques de « ' + f.label + ' »'); }
      }
      return { score: s, reasons: r };
    }
    case 'texte': return lgMoy > 20 && distincts / n > .6 ? { score: 20, reasons: ['phrases variées'] } : { score: 0, reasons: [] };
    case 'identifiant': return distincts / n > .7 && lgMoy < 25 && samples.some(v => /\d/.test(v)) ? { score: 15, reasons: ['codes avec chiffres'] } : { score: 0, reasons: [] };
  }
  return { score: 0, reasons: [] };
}

function _iiMemoireLire() { try { const m = JSON.parse(localStorage.getItem(_II_MEMOIRE) || '{}'); return (m && typeof m === 'object') ? m : {}; } catch (_) { return {}; } }
function _iiMemoireEcrire() {
  try {
    const m = _iiMemoireLire();
    _II.headers.forEach(h => {
      const k = _iiN(h.label);
      if (!k || /^\(?colonne \d+\)?$/.test(k)) return;
      // Une case créée par cet import est retenue sous sa clé (« cp:<clé> ») :
      // « cp+:3 » ne voudrait plus rien dire au prochain fichier.
      const v = _II.mapping[h.idx];
      if (v && v.startsWith('cp+:')) { const c = _iiCleCase(v); if (c) m[k] = 'cp:' + c; else delete m[k]; }
      else if (v) m[k] = v; else delete m[k];
    });
    const cles = Object.keys(m);
    if (cles.length > 300) cles.slice(0, cles.length - 300).forEach(k => delete m[k]);
    localStorage.setItem(_II_MEMOIRE, JSON.stringify(m));
  } catch (_) { /* navigation privée, stockage plein : sans conséquence */ }
}

/** Colonnes du fichier et correspondances proposées : titre (50) + contenu (50) + mémoire (45), attribution gloutonne. */
function _iiUpdateHeaders() {
  const entete = _II.rawRows[_II.headerRowIdx] || [];
  _II.mapping = {}; _II.headers = [];
  let largeur = entete.length;
  for (let r = _II.headerRowIdx + 1; r < Math.min(_II.rawRows.length, _II.headerRowIdx + 200); r++) largeur = Math.max(largeur, (_II.rawRows[r] || []).length);
  const memoire = _iiMemoireLire();
  const vus = {}, candidats = [];
  for (let idx = 0; idx < largeur; idx++) {
    let lib = String(entete[idx] || '').trim();
    const samples = [];
    const total = _II.rawRows.length - _II.headerRowIdx - 1, pas = Math.max(1, Math.floor(total / 40));
    for (let r = _II.headerRowIdx + 1; r < _II.rawRows.length && samples.length < 40; r += (samples.length < 10 ? 1 : pas)) {
      const v = String(_II.rawRows[r][idx] || '').trim();
      if (v) samples.push(v);
    }
    if (!lib && !samples.length) continue;
    const c = lib.toLowerCase();
    if (lib && vus[c]) { vus[c]++; lib += ' (' + vus[c] + ')'; } else if (lib) vus[c] = 1;
    const souvenir = memoire[_iiN(lib)];
    const scores = {};
    _II_CHAMPS.forEach(f => {
      const sl = _iiScoreLabel(lib, f), sc = _iiScoreContent(samples, f);
      if (lib && sl.score === 0) sc.score = Math.floor(sc.score / 2);
      let t = sl.score + sc.score; const reasons = [...sl.reasons, ...sc.reasons];
      if (souvenir === f.key) { t += 45; reasons.unshift('choisi lors de votre dernier import'); }
      if (t > 0) scores[f.key] = { score: t, reasons };
    });
    // Cases des fiches : une colonne qui porte EXACTEMENT le nom d'une case
    // (55, au-dessus d'un titre seulement « approchant » d'un champ), ou choisie
    // pour elle au dernier import (+45).
    _II.cases.forEach(d => {
      let t = 0; const reasons = [];
      if (lib && _iiN(d.libelle) === _iiN(lib)) { t += 55; reasons.push('même nom qu\'une case des fiches'); }
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
  // Une colonne de dates sans titre parlant, s'il n'y a pas de date de réalisation : c'est elle.
  if (!champsPris.has('dateRealisation')) {
    const c = candidats.find(c => !attrib[c.idx] && c.samples.length && c.samples.filter(v => _biParseDate(v)).length / c.samples.length > .8);
    if (c) attrib[c.idx] = { k: 'dateRealisation', score: 30, reasons: ['colonne de dates (la seule)'] };
  }
  _II.nouvellesCases = {};
  candidats.forEach(c => {
    const a = attrib[c.idx];
    _II.headers.push({ idx: c.idx, label: c.label, sample: c.sample, samples: c.samples, score: a ? a.score : 0, reasons: a ? a.reasons : [] });
    if (a) _II.mapping[c.idx] = a.k;
  });
}

function _iiColonne(k) { const e = Object.entries(_II.mapping).find(([, v]) => v === k); return e ? parseInt(e[0], 10) : null; }
function _iiDistinctes(col) {
  const s = new Set();
  for (let i = _II.headerRowIdx + 1; i < _II.rawRows.length; i++) { const v = String(_II.rawRows[i][col] || '').trim(); if (v) s.add(v); }
  return [...s].sort((a, b) => a.localeCompare(b, 'fr'));
}
function _iiCibles(champ) { return champ === 'type' ? _iiTypes() : champ === 'statut' ? _II_STATUTS : _II.listes.priorites; }
function _iiDeviner(champ, v) {
  const k = _iiN(v), connus = _iiCibles(champ);
  const direct = connus.find(c => _iiN(c) === k);
  if (direct) return direct;
  for (const [val, syn] of Object.entries(_II_SYN[champ] || {})) if (syn.includes(k)) return connus.find(c => _iiN(c) === _iiN(val)) || val;
  return '';
}
/** Valeurs distinctes des colonnes Type, Statut, Priorité, avec leur équivalent proposé (les choix déjà faits sont gardés). */
function _iiPrepareValueMaps() {
  ['type', 'statut', 'priorite'].forEach(ch => {
    const col = _iiColonne(ch), anc = _II.valueMap[ch] || {};
    _II.valueMap[ch] = {};
    if (col === null) return;
    _iiDistinctes(col).slice(0, 80).forEach(v => { _II.valueMap[ch][v] = anc[v] !== undefined ? anc[v] : _iiDeviner(ch, v); });
  });
}

// ══════════════ Cases complémentaires (section « fourre-tout » des fiches) ══════════════
// Comme à l'import des biens : une colonne du fichier sans champ Larka
// (« N° de marché », « Code analytique », « Réf. client »…) devient une CASE
// des fiches d'intervention, au lieu d'être perdue. Valeur de correspondance :
// « cp:<clé> » (case existante) ou « cp+:<colonne> » (case créée par cet import).

function _iiLibelleCase(k) {
  if (k.startsWith('cp+:')) { const n = _II.nouvellesCases[k.slice(4)]; return n ? n.libelle : k; }
  const d = _II.cases.find(x => x.cle === k.slice(3));
  return d ? d.libelle : k.slice(3);
}

/** Nouvelle case pour la colonne colIdx (ou la case existante de même nom). */
function _iiCreerCase(colIdx) {
  const h = _II.headers.find(x => x.idx === colIdx);
  if (!h) return;
  const libelle = String(h.label || '').replace(/ \(\d+\)$/, '').replace(/^\(colonne (\d+)\)$/, 'Colonne $1').trim().substring(0, 80) || ('Colonne ' + (colIdx + 1));
  let cle = ChampsPerso.cle(libelle);
  const existante = _II.cases.find(d => d.cle === cle || _iiN(d.libelle) === _iiN(libelle));
  if (existante) { _iiSetMapping(colIdx, 'cp:' + existante.cle, true); return; }
  const prises = new Set([..._II.cases.map(d => d.cle), ...Object.entries(_II.nouvellesCases).filter(([c]) => parseInt(c, 10) !== colIdx).map(([, n]) => n.cle)]);
  const base = cle; let n = 2;
  while (prises.has(cle)) cle = base.substring(0, 56) + '_' + (n++);
  _II.nouvellesCases[colIdx] = { cle, libelle, type: typeof _biTypeCase === 'function' ? _biTypeCase(h.samples) : 'texte' };
  _II.mapping[colIdx] = 'cp+:' + colIdx;
}

function _iiRenommerCase(colIdx, libelle) {
  const n = _II.nouvellesCases[colIdx];
  libelle = String(libelle || '').trim().substring(0, 80);
  if (!n || !libelle) return;
  n.libelle = libelle;
  const prises = new Set([..._II.cases.map(d => d.cle), ...Object.entries(_II.nouvellesCases).filter(([c]) => parseInt(c, 10) !== colIdx).map(([, x]) => x.cle)]);
  let cle = ChampsPerso.cle(libelle); const base = cle; let i = 2;
  while (prises.has(cle)) cle = base.substring(0, 56) + '_' + (i++);
  n.cle = cle;
}

/** Toutes les colonnes remplies et non associées deviennent des cases. */
function _iiCasesPourNonAssociees() {
  let n = 0;
  _II.headers.forEach(h => {
    if (_II.mapping[h.idx] || !(h.samples || []).length) return;
    _iiCreerCase(h.idx); n++;
  });
  _iiRender();
  toast(n ? `${n} case(s) ajoutée(s) aux fiches (créées à l'import).` : 'Toutes les colonnes remplies sont déjà associées.', 'success');
}

/** Clé de case d'une valeur de correspondance (« cp:x » ou « cp+:3 »), sinon null. */
function _iiCleCase(k) {
  if (!k) return null;
  if (k.startsWith('cp+:')) { const n = _II.nouvellesCases[k.slice(4)]; return n ? n.cle : null; }
  if (k.startsWith('cp:')) return k.slice(3);
  return null;
}
function _iiTypeCase(k) {
  if (k.startsWith('cp+:')) { const n = _II.nouvellesCases[k.slice(4)]; return n ? n.type : 'texte'; }
  const d = _II.cases.find(x => x.cle === k.slice(3));
  return d ? d.type : 'texte';
}

// ══════════════ ÉTAPE 3 — Correspondances ══════════════
function _iiGoStep3() {
  if (!_II.rawRows.length) { toast('Aucune donnée à importer.', 'error'); return; }
  _iiPrepareValueMaps();
  _II.step = 3;
  _iiRender();
  if (_II.iaDispo === null && typeof apiRequest === 'function') {
    _II.iaDispo = false;
    apiRequest('assistant_status').then(st => { _II.iaDispo = !!(st && st.ia_disponible); if (_II.iaDispo && _II.step === 3) _iiRender(); }).catch(() => {});
  }
}

function _iiStep3() {
  const manque = _iiColonne('description') === null;
  let html = '';
  if (manque) {
    html += '<div style="padding:10px 14px;background:#fef9c3;border:1px solid #eab308;border-radius:8px;margin-bottom:14px;font-size:12px;color:#713f12">'
      + lk('triangle-exclamation-point', 'warn') + ' <strong>Champ obligatoire non assigné :</strong> Description</div>';
  }
  html += '<div style="font-size:13px;color:var(--gray-text);margin-bottom:8px">Vérifiez les correspondances détectées automatiquement. Les colonnes inutiles peuvent rester sur « Ne pas importer ».</div>';
  const total = _II.headers.length, assignees = _II.headers.filter(h => _II.mapping[h.idx]).length;
  const sures = _II.headers.filter(h => _II.mapping[h.idx] && h.score >= 50).length, aVoir = assignees - sures;
  html += '<div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;padding:8px 12px;background:var(--gray-bg);border-radius:8px;font-size:12px;flex-wrap:wrap">';
  html += '<span style="font-weight:600">' + assignees + ' / ' + total + '</span> colonnes assignées';
  if (sures) html += '<span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600">' + lk('circle', 'p vert') + ' ' + sures + ' sûres</span>';
  if (aVoir) html += '<span style="background:#fef3c7;color:#854d0e;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600">' + lk('circle', 'p jaune') + ' ' + aVoir + ' à vérifier</span>';
  const pS = total ? Math.round(sures / total * 100) : 0, pA = total ? Math.round(assignees / total * 100) : 0;
  html += '<div style="flex:1;height:6px;background:var(--gray-border);border-radius:3px;overflow:hidden;min-width:80px"><div style="width:' + pA + '%;height:100%;background:linear-gradient(90deg,#16a34a 0%,#16a34a ' + pS + '%,#eab308 ' + pS + '%,#eab308 ' + pA + '%);transition:width .2s"></div></div>';
  html += '<button onclick="_iiUpdateHeaders();_iiPrepareValueMaps();_iiRender()" style="border:1px solid var(--gray-border);background:var(--white);color:var(--blue);cursor:pointer;font-size:12px;font-weight:600;padding:4px 10px;border-radius:6px">↺ Re-détecter</button>';
  if (_II.iaDispo) html += '<button id="iiBtnIa" onclick="_iiProposerIa()" style="border:1px solid #7c3aed;background:#f5f3ff;color:#6d28d9;cursor:pointer;font-size:12px;font-weight:600;padding:4px 10px;border-radius:6px" title="Le modèle IA configuré reçoit les en-têtes et 5 valeurs d\'exemple par colonne — jamais le fichier">' + lk('bot') + ' Proposer avec l\'IA</button>';
  html += '<button onclick="_II.mapping={};_II.nouvellesCases={};_II.headers.forEach(h=>{h.score=0;h.reasons=[]});_iiPrepareValueMaps();_iiRender()" style="border:1px solid var(--gray-border);background:var(--white);color:var(--gray-text);cursor:pointer;font-size:12px;padding:4px 10px;border-radius:6px">Tout effacer</button>';
  html += '</div>';

  const libres = _II.headers.filter(h => !_II.mapping[h.idx] && (h.samples || []).length).length;
  if (libres && typeof ChampsPerso !== 'undefined') {
    html += '<div style="display:flex;align-items:center;gap:10px;margin:-4px 0 12px;padding:8px 12px;background:#f5f3ff;border:1px dashed #7c3aed;border-radius:8px;font-size:12px;color:#5b21b6">'
      + '<span style="flex:1"><b>' + libres + '</b> <span>colonne(s) remplie(s) sans champ d\'intervention : elles peuvent devenir des cases de la fiche (section « Informations complémentaires »).</span></span>'
      + '<button type="button" class="btn btn-ghost btn-sm" onclick="_iiCasesPourNonAssociees()">' + lk('folders') + ' Créer les cases</button></div>';
  }
  const utilises = new Set(Object.values(_II.mapping).filter(Boolean));
  html += '<div style="border:1px solid var(--gray-border);border-radius:8px;overflow:hidden;max-height:400px;overflow-y:auto"><table style="width:100%;border-collapse:collapse;font-size:12px">';
  html += '<thead style="position:sticky;top:0;background:var(--gray-bg);z-index:1"><tr>'
    + '<th style="padding:8px 10px;border-bottom:1px solid var(--gray-border);width:32px"></th>'
    + '<th style="padding:8px 10px;text-align:left;font-weight:700;border-bottom:1px solid var(--gray-border);width:25%">Colonne du fichier</th>'
    + '<th style="padding:8px 10px;text-align:left;font-weight:700;border-bottom:1px solid var(--gray-border);width:30%">Aperçu</th>'
    + '<th style="padding:8px 10px;text-align:left;font-weight:700;border-bottom:1px solid var(--gray-border)">Champ de l\'intervention</th></tr></thead><tbody>';
  _II.headers.forEach(h => {
    const cur = _II.mapping[h.idx] || '', f = _II_CHAMPS.find(x => x.key === cur);
    let fond = 'background:#fafafa', icone = lk('circle', 'p gris'), titre = 'Non assigné';
    if (cur && h.score >= 50) { fond = 'background:#f0fdf4'; icone = lk('circle', 'p vert'); titre = 'Détection sûre (score ' + h.score + ')'; }
    else if (cur && h.score >= 30) { fond = 'background:#fffbeb'; icone = lk('circle', 'p jaune'); titre = 'À vérifier (score ' + h.score + ')'; }
    else if (cur) { fond = 'background:#fff'; icone = lk('circle', 'p bleu'); titre = 'Choix manuel'; }
    const raisons = (h.reasons || []).map(r => '• ' + r).join('\n');
    html += '<tr style="border-bottom:1px solid var(--gray-bg);' + fond + '">';
    html += '<td style="padding:8px 10px;text-align:center" title="' + _esc(titre + (raisons ? '\n\n' + raisons : '')) + '">' + icone + '</td>';
    html += '<td style="padding:8px 10px;font-weight:600;color:var(--text)">' + _esc(h.label) + '</td>';
    html += '<td style="padding:8px 10px;color:var(--gray-text);font-style:italic;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + _esc((h.samples || []).slice(0, 10).join('\n')) + '">' + (h.sample ? _esc(h.sample) : '<span style="opacity:.5">(vide)</span>') + '</td>';
    html += '<td style="padding:6px 10px"><select class="form-control" style="font-size:12px;padding:4px 8px;' + (f && f.required ? 'border-color:var(--blue);background:var(--blue-pale)' : '') + '" onchange="_iiSetMapping(' + h.idx + ',this.value)">'
      + '<option value="">— Ne pas importer —</option>'
      + '<optgroup label="Champs de l\'intervention">'
      + _II_CHAMPS.map(x => { const u = utilises.has(x.key) && x.key !== cur; return '<option value="' + x.key + '" ' + (cur === x.key ? 'selected' : '') + (u ? ' style="color:#999"' : '') + '>' + _esc(x.label) + (x.required ? ' *' : '') + (u ? ' — déjà utilisé' : '') + '</option>'; }).join('')
      + '</optgroup>'
      + (typeof ChampsPerso === 'undefined' ? '' : '<optgroup label="Cases complémentaires des fiches">'
        + _II.cases.map(d => { const k = 'cp:' + d.cle, u = utilises.has(k) && k !== cur; return '<option value="' + _esc(k) + '" ' + (cur === k ? 'selected' : '') + (u ? ' style="color:#999"' : '') + '>' + _esc(d.libelle) + (u ? ' — déjà utilisé' : '') + '</option>'; }).join('')
        + (cur.startsWith('cp+:') ? '<option value="' + _esc(cur) + '" selected>' + _esc(_iiLibelleCase(cur)) + ' (nouvelle case)</option>'
                                  : '<option value="cp+">Nouvelle case « ' + _esc(String(h.label).substring(0, 40)) + ' »</option>')
        + '</optgroup>')
      + '</select>'
      + (cur.startsWith('cp+:') ? (function () {
          const nc = _II.nouvellesCases[h.idx] || {};
          return '<div style="display:flex;gap:4px;margin-top:4px">'
            + '<input class="form-control" style="font-size:11px;padding:3px 6px" value="' + _esc(nc.libelle || '') + '" title="Nom de la case dans les fiches" onchange="_iiRenommerCase(' + h.idx + ',this.value);_iiRender()">'
            + '<select class="form-control" style="font-size:11px;padding:3px 6px;width:110px" title="Type de la case" onchange="_II.nouvellesCases[' + h.idx + '].type=this.value">'
            + (ChampsPerso.TYPES || []).map(([v, l]) => '<option value="' + v + '" ' + (nc.type === v ? 'selected' : '') + '>' + l + '</option>').join('')
            + '</select></div>';
        })() : '')
      + '</td></tr>';
  });
  html += '</tbody></table></div>';

  // Valeurs du fichier → valeurs de Larka
  const blocs = [['type', 'Type', '(nouveau type : ajouté à la liste)'], ['statut', 'Statut', '(statut par défaut ci-dessous)'], ['priorite', 'Priorité', '(nouvelle priorité : ajoutée à la liste)']];
  blocs.forEach(([ch, lib, vide]) => {
    if (ch === 'type' && _II.mode !== 'auto') return;
    const vals = Object.keys(_II.valueMap[ch] || {});
    if (!vals.length) return;
    html += '<div style="margin-top:14px;padding:12px;background:var(--blue-pale);border:1px solid var(--blue);border-radius:8px">'
      + '<div style="font-weight:600;margin-bottom:6px;font-size:13px">' + lk('target') + ' Correspondance des valeurs « ' + lib + ' »</div>'
      + '<div style="font-size:11px;color:var(--gray-text);margin-bottom:8px">Faites correspondre les valeurs de votre fichier aux valeurs de Larka. Les noms usuels (« terminée », « correctif », « VGP », « haute »…) sont reconnus d\'office.</div>'
      + '<div style="display:grid;grid-template-columns:1fr auto 1fr;gap:6px;align-items:center;font-size:12px">';
    vals.forEach(v => {
      html += '<div style="padding:4px 8px;background:var(--white);border-radius:4px;border:1px solid var(--gray-border)"><strong>' + _esc(v) + '</strong></div><div style="text-align:center;color:var(--gray-text)">→</div>'
        + '<select class="form-control" style="font-size:12px;padding:4px 8px" onchange="_II.valueMap[' + jsArg(ch) + '][' + jsArg(v) + ']=this.value">'
        + '<option value="">' + (ch === 'statut' ? '— Statut par défaut —' : '— Garder « ' + _esc(v) + ' » ' + vide + ' —') + '</option>'
        + _iiCibles(ch).map(g => '<option value="' + _esc(g) + '" ' + (_II.valueMap[ch][v] === g ? 'selected' : '') + '>' + _esc(_II_STATUT_LIB[g] || g) + '</option>').join('')
        + '</select>';
    });
    html += '</div>' + (ch === 'type' ? '<div style="font-size:11px;color:var(--gray-text);margin-top:8px">Une Préventive ou un Contrôle réglementaire importé apparaît sur son écran, dans « Hors plan ».</div>' : '') + '</div>';
  });

  // Règles : doublons, statut par défaut
  html += '<div style="margin-top:14px;padding:12px;background:var(--gray-bg);border-radius:8px;display:grid;grid-template-columns:1fr 1fr;gap:12px 18px">';
  html += '<div><div style="font-weight:600;margin-bottom:6px;font-size:13px">' + lk('repeat') + ' Si un n° d\'intervention existe déjà</div>'
    + '<label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:12px;margin-bottom:4px"><input type="radio" name="iiDedup" value="update" ' + (_II.dedup === 'update' ? 'checked' : '') + ' onchange="_II.dedup=this.value"> Mettre à jour la fiche (seules les colonnes du fichier)</label>'
    + '<label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:12px"><input type="radio" name="iiDedup" value="skip" ' + (_II.dedup === 'skip' ? 'checked' : '') + ' onchange="_II.dedup=this.value"> Laisser la fiche telle quelle</label></div>';
  html += '<div><div style="font-weight:600;margin-bottom:6px;font-size:13px">' + lk('copy') + ' Ligne sans n°, identique à une intervention existante</div>'
    + '<label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:12px;margin-bottom:4px"><input type="radio" name="iiDoublons" value="ignorer" ' + (_II.doublons === 'ignorer' ? 'checked' : '') + ' onchange="_II.doublons=this.value"> Ne pas la recréer (même type, date, équipement, description)</label>'
    + '<label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:12px"><input type="radio" name="iiDoublons" value="importer" ' + (_II.doublons === 'importer' ? 'checked' : '') + ' onchange="_II.doublons=this.value"> L\'importer quand même</label></div>';
  html += '<div style="grid-column:1 / -1"><div style="font-weight:600;margin-bottom:6px;font-size:13px">' + lk('list-checks') + ' Statut des lignes sans statut</div>'
    + '<select class="form-control" style="font-size:12px;padding:4px 8px;max-width:520px" onchange="_II.statutDefaut=this.value">'
    + '<option value="auto" ' + (_II.statutDefaut === 'auto' ? 'selected' : '') + '>Automatique : réalisée si une date de réalisation est donnée, sinon planifiée</option>'
    + _II_STATUTS.map(s => '<option value="' + s + '" ' + (_II.statutDefaut === s ? 'selected' : '') + '>' + _esc(_II_STATUT_LIB[s] || s) + '</option>').join('') + '</select></div>';
  html += '</div>';

  return html + _iiPied('_II.step=2;_iiRender()', '_iiGoStep4()', !manque, 'Choisir les lignes ▶');
}

function _iiSetMapping(col, k, sansRendu) {
  if (!String(k || '').startsWith('cp+')) delete _II.nouvellesCases[col];
  if (k === 'cp+') { _iiCreerCase(col); if (!sansRendu) _iiRender(); return; }
  if (k) Object.keys(_II.mapping).forEach(c => { if (_II.mapping[c] === k && parseInt(c, 10) !== col) delete _II.mapping[c]; });
  if (k) _II.mapping[col] = k; else delete _II.mapping[col];
  const h = _II.headers.find(x => x.idx === col);
  if (h) { h.score = 0; h.reasons = ['choix manuel']; }
  if (sansRendu) return;
  _iiPrepareValueMaps();
  _iiRender();
}

async function _iiProposerIa() {
  const btn = document.getElementById('iiBtnIa');
  if (btn) { btn.disabled = true; btn.textContent = 'L\'IA analyse les colonnes…'; }
  const val = ch => { const c = _iiColonne(ch); return { source: c !== null ? _iiDistinctes(c).slice(0, 40) : [], cibles: _iiCibles(ch) }; };
  try {
    const r = await apiRequest('import_biens_ia', 'POST', {
      contexte: 'interventions',
      colonnes: _II.headers.map(h => ({ idx: h.idx, libelle: h.label, exemples: (h.samples || []).filter((v, i, a) => a.indexOf(v) === i).slice(0, 5) })),
      champs: _II_CHAMPS.map(f => ({ cle: f.key, libelle: f.label + (f.required ? ' (obligatoire)' : '') })),
      valeurs: { type: val('type'), statut: val('statut'), priorite: val('priorite') },
    });
    let n = 0;
    (r.correspondances || []).forEach(c => {
      const h = _II.headers.find(x => x.idx === c.colonne);
      if (!h) return;
      Object.keys(_II.mapping).forEach(col => { if (_II.mapping[col] === c.champ && parseInt(col, 10) !== c.colonne) delete _II.mapping[col]; });
      if (_II.mapping[c.colonne] !== c.champ) n++;
      _II.mapping[c.colonne] = c.champ;
      h.score = Math.max(30, Math.min(100, c.confiance));
      h.reasons = ['proposé par l\'IA (confiance ' + c.confiance + ' %)'];
    });
    _iiPrepareValueMaps();
    (r.valeurs || []).forEach(v => { if (_II.valueMap[v.champ]) _II.valueMap[v.champ][v.source] = v.cible; });
    _iiRender();
    toast(n ? `${n} correspondances proposées par l'IA : vérifiez-les avant de continuer.` : 'L\'IA confirme les correspondances détectées.', 'success');
  } catch (e) { toast(e.message || 'IA indisponible', 'error'); _iiRender(); }
}

// ══════════════ Lecture et contrôle des lignes (étape 4) ══════════════

/** Durée en heures : « 2,5 », « 2h30 », « 1:45 », « 90 min » (même règle que le serveur). null = illisible. */
function _iiHeures(v) {
  const s = String(v == null ? '' : v).trim();
  if (!s) return 0;
  let m = /^(\d{1,4})\s*(?:h|:)\s*(\d{1,2})?\s*(?:min)?$/i.exec(s);
  if (m) return parseInt(m[1], 10) + (m[2] ? parseInt(m[2], 10) / 60 : 0);
  m = /^(\d+(?:[.,]\d+)?)\s*min(?:utes?)?$/i.exec(s);
  if (m) return parseFloat(m[1].replace(',', '.')) / 60;
  return _biNombre(s.replace(/\s*(h|heures?)$/i, ''));
}

function _iiConstruireLignes() {
  const out = [], aujourdhui = new Date().toISOString().substring(0, 10);
  const lib = Object.fromEntries(_II_CHAMPS.map(f => [f.key, f.label]));
  const colLib = {}; _II.headers.forEach(h => { if (_II.mapping[h.idx]) colLib[_II.mapping[h.idx]] = h.label; });
  _II.rawRows.slice(_II.headerRowIdx + 1).forEach((r, k) => {
    const brut = {}; let vide = true;
    Object.entries(_II.mapping).forEach(([col, ch]) => { const v = String(r[col] == null ? '' : r[col]).trim(); if (v) vide = false; brut[ch] = v; });
    if (vide) return;
    const item = {}, erreurs = [], avert = [];
    const champ = ch => colLib[ch] || lib[ch];
    Object.entries(brut).forEach(([ch, v]) => {
      const cle = _iiCleCase(ch);
      if (cle) {
        // Case complémentaire : date et nombre ramenés à leur forme de saisie
        // (« 15/03/2026 » → 2026-03-15) ; illisible, la valeur est gardée telle quelle.
        if (!v) return;
        const t = _iiTypeCase(ch);
        let val = v;
        if (t === 'date') val = _biParseDate(v) || v;
        else if (t === 'nombre') { const n = _biNombre(v); val = n === null ? v : String(n); }
        (item.champsPerso = item.champsPerso || {})[cle] = val;
        return;
      }
      if (ch.startsWith('cp')) return;   // case sans définition (ne devrait pas arriver)
      item[ch] = v;
    });
    // Type, statut, priorité : valeur du fichier → valeur de Larka.
    if (_II.mode !== 'auto') item.type = _II.mode;
    else if (item.type) item.type = _II.valueMap.type[item.type] || _iiDeviner('type', item.type) || item.type;
    if (item.statut) {
      const s = _II.valueMap.statut[item.statut] || _iiDeviner('statut', item.statut);
      if (!s) avert.push({ champ: champ('statut'), valeur: item.statut, message: 'statut inconnu : statut par défaut' });
      item.statut = s || '';
    }
    if (item.priorite) item.priorite = _II.valueMap.priorite[item.priorite] || _iiDeviner('priorite', item.priorite) || item.priorite;
    // Contrôles
    if (!String(item.description || '').trim()) erreurs.push({ champ: champ('description'), message: 'description vide' });
    _II_DATES.forEach(ch => {
      if (!item[ch]) return;
      const d = _biParseDate(item[ch]);
      if (d) item[ch] = d; else { avert.push({ champ: champ(ch), valeur: item[ch], message: 'date illisible : laissée vide' }); item._dateIllisible = true; }
    });
    ['montantHT', 'montant'].forEach(ch => {
      if (!item[ch]) return;
      const n = _biNombre(item[ch]);
      if (n === null) avert.push({ champ: champ(ch), valeur: item[ch], message: 'montant illisible : laissé vide' });
      else if (n < 0) avert.push({ champ: champ(ch), valeur: item[ch], message: 'montant négatif' });
      item['_' + ch] = n;
    });
    if (item.dureeHeures && _iiHeures(item.dureeHeures) === null) avert.push({ champ: champ('dureeHeures'), valeur: item.dureeHeures, message: 'durée illisible : laissée vide' });
    if (item.agentEmail && !/^[^@\s]+@[^@\s]+\.[a-z]{2,}$/i.test(item.agentEmail)) avert.push({ champ: champ('agentEmail'), valeur: item.agentEmail, message: 'courriel invalide' });
    const realisee = ['Réalisée', 'Validée', 'Archivée'].includes(item.statut);
    if (realisee && !item.dateRealisation && !item._dateIllisible) avert.push({ champ: champ('statut'), valeur: item.statut, message: 'terminée sans date de réalisation' });
    if (item.dateRealisation && /^\d{4}-\d{2}-\d{2}$/.test(item.dateRealisation) && item.dateRealisation > aujourdhui) avert.push({ champ: champ('dateRealisation'), valeur: _iiDateFr(item.dateRealisation), message: 'réalisation dans le futur' });
    const statutEff = item.statut || (_II.statutDefaut === 'auto' ? (item.dateRealisation ? 'Réalisée' : 'Planifiée') : _II.statutDefaut);
    const typeEff = item.type || _II.typeDefaut;
    const date = [item.dateRealisation, item.dateIntervention, item.dateDemande].find(d => /^\d{4}-\d{2}-\d{2}$/.test(d || '')) || '';
    // Planning déplié : le rang dans le tableau déplié (un entier, comme le serveur l'attend) ;
    // la cellule d'origine (« Bordeaux!D7 ») s'affiche par _iiRef.
    const rang = _II.orientation === 'planning' ? _II.headerRowIdx + 1 + k : (_II.refs[_II.headerRowIdx + 1 + k] || (_II.headerRowIdx + k + 2));
    out.push({ i: out.length, row: rang, item, erreurs, avert, typeEff, statutEff, date, inclus: !erreurs.length });
  });
  return out;
}

/** Ligne telle qu'envoyée au serveur (valeurs déjà traduites ; il refait ses propres contrôles). */
function _iiPayloadLigne(l) {
  const it = { _row: l.row };
  Object.entries(l.item).forEach(([k, v]) => { if (!k.startsWith('_')) it[k] = v; });
  return it;
}

// ══════════════ ÉTAPE 4 — Sélection des lignes ══════════════
function _iiGoStep4() {
  if (_iiColonne('description') === null) { toast('Champ obligatoire non assigné : Description', 'error'); return; }
  _iiMemoireEcrire();
  _II.lignes = _iiConstruireLignes();
  _II.selPage = 1; _II.selFiltre = 'toutes'; _II.selRecherche = '';
  _II.step = 4;
  _iiRender();
}

function _iiLignesVisibles() {
  const q = _iiN(_II.selRecherche || ''), f = _II.selFiltre;
  return _II.lignes.filter(l => {
    if (f === 'cochees' && !l.inclus) return false;
    if (f === 'decochees' && l.inclus) return false;
    if (f === 'erreurs' && !l.erreurs.length) return false;
    if (f === 'avert' && !l.avert.length) return false;
    if (q && !_iiN([l.item.numero, l.typeEff, l.statutEff, l.item.description, l.item.actif, l.item.societeManuelle, l.item.contrat].join(' ')).includes(q)) return false;
    return true;
  });
}

function _iiPuces(titre, icone, cle, valeur) {
  const g = {};
  _II.lignes.forEach(l => { const k = valeur(l) || '(vide)'; g[k] = g[k] || { n: 0, c: 0 }; g[k].n++; if (l.inclus) g[k].c++; });
  const liste = Object.entries(g).sort((a, b) => b[1].n - a[1].n);
  if (liste.length < 2) return '';
  return '<div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-top:6px"><strong style="margin-right:4px">' + lk(icone) + ' ' + titre + '</strong>'
    + liste.slice(0, 30).map(([k, v]) => '<label style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border:1px solid var(--gray-border);border-radius:12px;background:var(--white);cursor:pointer" title="' + v.c + ' / ' + v.n + ' cochées">'
      + '<input type="checkbox" ' + (v.c === v.n ? 'checked' : '') + ' ' + (v.c > 0 && v.c < v.n ? 'data-partiel="1"' : '') + ' onchange="_iiSelGroupe(' + jsArg(cle) + ',' + jsArg(k) + ',this.checked)"> '
      + _esc(_II_STATUT_LIB[k] || k) + ' <span style="color:var(--gray-text)">(' + v.n + ')</span></label>').join('')
    + '</div>';
}

function _iiStep4() {
  const L = _II.lignes, coches = L.filter(l => l.inclus);
  const somme = coches.reduce((t, l) => t + (Number(l.item._montantHT) || 0), 0);
  const nErr = L.filter(l => l.erreurs.length).length, nAv = L.filter(l => l.avert.length).length;
  let html = '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:12px">';
  html += '<div style="padding:10px;background:var(--blue-pale);border-radius:8px;text-align:center"><div style="font-size:22px;font-weight:700;color:var(--blue)">' + coches.length + '</div><div style="font-size:11px;color:var(--gray-text)"><span>lignes cochées sur</span> ' + L.length + '</div></div>';
  html += '<div style="padding:10px;background:var(--gray-bg);border-radius:8px;text-align:center"><div style="font-size:18px;font-weight:700;line-height:26px">' + _esc(_biFmtEuro(somme)) + '</div><div style="font-size:11px;color:var(--gray-text)">montant HT des lignes cochées</div></div>';
  html += '<div onclick="_iiSelFiltre(\'erreurs\')" style="cursor:pointer;padding:10px;background:' + (nErr ? '#fee' : 'var(--gray-bg)') + ';border-radius:8px;text-align:center"><div style="font-size:22px;font-weight:700;color:' + (nErr ? '#c0392b' : 'var(--gray-text)') + '">' + nErr + '</div><div style="font-size:11px;color:var(--gray-text)">lignes en erreur</div></div>';
  html += '<div onclick="_iiSelFiltre(\'avert\')" style="cursor:pointer;padding:10px;background:' + (nAv ? '#fef9c3' : 'var(--gray-bg)') + ';border-radius:8px;text-align:center"><div style="font-size:22px;font-weight:700;color:' + (nAv ? '#a16207' : 'var(--gray-text)') + '">' + nAv + '</div><div style="font-size:11px;color:var(--gray-text)">avec avertissement</div></div>';
  html += '</div>';

  // Règles de sélection : période, types, statuts
  html += '<div style="padding:10px 12px;background:var(--gray-bg);border-radius:8px;margin-bottom:10px;font-size:12px">';
  html += '<div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">';
  html += '<strong>' + lk('calendar-days') + ' Période</strong>';
  html += '<input id="iiDu" class="form-control" type="date" value="' + _esc(_II.periodeDu) + '" style="width:150px;font-size:12px;padding:4px 8px" title="Du">';
  html += '<span>–</span>';
  html += '<input id="iiAu" class="form-control" type="date" value="' + _esc(_II.periodeAu) + '" style="width:150px;font-size:12px;padding:4px 8px" title="Au">';
  html += '<button class="btn btn-ghost btn-sm" onclick="_iiAppliquerPeriode()" title="Décoche les lignes dont la date (réalisation, sinon prévue, sinon demande) est hors de la période">Appliquer la période</button>';
  html += '</div>';
  html += _iiPuces('Types', 'tag', 'type', l => l.typeEff);
  html += _iiPuces('Statuts', 'list-checks', 'statut', l => l.statutEff);
  html += '</div>';

  // Barre d'outils de la liste
  const vis = _iiLignesVisibles();
  html += '<div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-bottom:6px">';
  html += '<input class="form-control" type="text" placeholder="Filtrer (n°, description, équipement, prestataire…)" value="' + _esc(_II.selRecherche) + '" oninput="_iiSelRecherche(this.value)" style="max-width:300px;font-size:12px;padding:4px 8px">';
  html += '<select class="form-control" style="width:auto;font-size:12px;padding:4px 8px" onchange="_iiSelFiltre(this.value)">'
    + [['toutes', 'Toutes les lignes'], ['cochees', 'Cochées'], ['decochees', 'Non cochées'], ['erreurs', 'En erreur'], ['avert', 'Avec avertissement']]
      .map(([v, l]) => '<option value="' + v + '" ' + (_II.selFiltre === v ? 'selected' : '') + '>' + l + '</option>').join('') + '</select>';
  html += '<span style="flex:1"></span><span style="font-size:11px;color:var(--gray-text)"><span>Sur les</span> ' + vis.length + ' <span>lignes affichées :</span></span>';
  html += '<button class="btn btn-ghost btn-sm" onclick="_iiSelVisibles(true)">Tout cocher</button><button class="btn btn-ghost btn-sm" onclick="_iiSelVisibles(false)">Tout décocher</button><button class="btn btn-ghost btn-sm" onclick="_iiSelVisibles(null)">Inverser</button>';
  html += '</div>';

  // Tableau paginé
  const PAR_PAGE = 100, pages = Math.max(1, Math.ceil(vis.length / PAR_PAGE));
  if (_II.selPage > pages) _II.selPage = pages;
  const debut = (_II.selPage - 1) * PAR_PAGE;
  html += '<div id="iiSelTable" style="max-height:340px;overflow:auto;border:1px solid var(--gray-border);border-radius:6px"><table style="width:100%;font-size:11px;border-collapse:collapse">';
  html += '<thead style="position:sticky;top:0;background:var(--gray-bg);z-index:1"><tr style="font-weight:700"><td style="padding:6px 8px;width:28px"></td><td style="padding:6px 8px">' + _iiRefTitre() + '</td><td style="padding:6px 8px">N°</td><td style="padding:6px 8px">Type</td><td style="padding:6px 8px">Statut</td><td style="padding:6px 8px">Description</td><td style="padding:6px 8px">Équipement</td><td style="padding:6px 8px">Date</td><td style="padding:6px 8px;text-align:right">Montant HT</td><td style="padding:6px 8px">Contrôle</td></tr></thead><tbody>';
  if (!vis.length) html += '<tr><td colspan="10" style="padding:14px;text-align:center;color:var(--gray-text)">Aucune ligne.</td></tr>';
  vis.slice(debut, debut + PAR_PAGE).forEach(l => {
    const bloque = l.erreurs.length > 0;
    const pb = [...l.erreurs, ...l.avert].map(e => (e.champ ? e.champ + ' : ' : '') + e.message + (e.valeur ? ' (« ' + e.valeur + ' »)' : ''));
    const pr = l.erreurs[0] || l.avert[0];
    html += '<tr style="border-top:1px solid var(--gray-bg);' + (bloque ? 'background:#fff5f5' : (l.inclus ? '' : 'opacity:.55')) + '">';
    html += '<td style="padding:4px 8px"><input type="checkbox" ' + (l.inclus ? 'checked' : '') + ' ' + (bloque ? 'disabled title="Ligne en erreur : elle ne peut pas être importée"' : '') + ' onchange="_iiSelLigne(' + l.i + ',this.checked)"></td>';
    html += '<td style="padding:4px 8px;color:var(--gray-text);white-space:nowrap">' + _esc(_iiRef(l.row)) + '</td>';
    html += '<td style="padding:4px 8px;font-weight:600;white-space:nowrap">' + _esc(l.item.numero || '') + '</td>';
    html += '<td style="padding:4px 8px;white-space:nowrap">' + _esc(l.typeEff) + '</td>';
    html += '<td style="padding:4px 8px;white-space:nowrap">' + _esc(l.statutEff) + (l.item.statut ? '' : ' <span style="color:var(--gray-text)" title="Statut par défaut">*</span>') + '</td>';
    html += '<td style="padding:4px 8px;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + _esc(l.item.description || '') + '">' + _esc(l.item.description || '') + '</td>';
    html += '<td style="padding:4px 8px;white-space:nowrap;max-width:110px;overflow:hidden;text-overflow:ellipsis">' + _esc(l.item.actif || '') + '</td>';
    html += '<td style="padding:4px 8px;white-space:nowrap">' + (l.date ? _esc(_iiDateFr(l.date)) : '<span style="color:var(--gray-text)">—</span>') + '</td>';
    html += '<td style="padding:4px 8px;text-align:right;white-space:nowrap">' + (l.item._montantHT ? _esc(_biFmtEuro(l.item._montantHT)) : '<span style="color:var(--gray-text)">—</span>') + '</td>';
    html += '<td style="padding:4px 8px;white-space:nowrap;max-width:260px;overflow:hidden;text-overflow:ellipsis" title="' + _esc(pb.join('\n')) + '">'
      + (bloque ? lk('octagon-x', 'ko') + ' ' : '') + (l.avert.length ? lk('triangle-exclamation-point', 'warn') + ' ' : '')
      + (pr ? '<span style="color:var(--gray-text)">' + (pr.champ ? '<span>' + _esc(pr.champ) + '</span> : ' : '') + '<span>' + _esc(pr.message) + '</span>' + (pb.length > 1 ? ' (+' + (pb.length - 1) + ')' : '') + '</span>' : '<span style="color:#16a34a">✓</span>')
      + '</td></tr>';
  });
  html += '</tbody></table></div>';
  if (pages > 1) {
    const p = _II.selPage;
    html += '<div style="display:flex;justify-content:center;align-items:center;gap:8px;margin-top:6px;font-size:12px">'
      + '<button class="btn btn-ghost btn-sm" ' + (p <= 1 ? 'disabled' : '') + ' onclick="_II.selPage--;_iiRender()">←</button><span>Page ' + p + ' / ' + pages + '</span>'
      + '<button class="btn btn-ghost btn-sm" ' + (p >= pages ? 'disabled' : '') + ' onclick="_II.selPage++;_iiRender()">→</button></div>';
  }
  return html + _iiPied('_II.step=3;_iiRender()', '_iiGoStep5()', coches.length > 0, '<span>Aperçu</span> (' + coches.length + ' <span>lignes</span>) ▶');
}

function _iiGarderDefilement() {
  const y = (document.getElementById('iiSelTable') || {}).scrollTop || 0;
  _iiRender();
  const t = document.getElementById('iiSelTable'); if (t) t.scrollTop = y;
}
function _iiSelLigne(i, on) { const l = _II.lignes.find(x => x.i === i); if (l && !l.erreurs.length) l.inclus = !!on; _iiGarderDefilement(); }
function _iiSelVisibles(on) { _iiLignesVisibles().forEach(l => { if (!l.erreurs.length) l.inclus = on === null ? !l.inclus : on; }); _iiRender(); }
function _iiSelGroupe(cle, val, on) {
  _II.lignes.forEach(l => { if (((cle === 'type' ? l.typeEff : l.statutEff) || '(vide)') === val && !l.erreurs.length) l.inclus = !!on; });
  _iiGarderDefilement();
}
function _iiSelFiltre(v) { _II.selFiltre = v; _II.selPage = 1; _iiRender(); }
let _iiRechMinuterie = null;
function _iiSelRecherche(v) {
  _II.selRecherche = v; _II.selPage = 1;
  clearTimeout(_iiRechMinuterie);
  _iiRechMinuterie = setTimeout(() => {
    _iiRender();
    const el = document.querySelector('#iiImportContainer input[oninput^="_iiSelRecherche"]');
    if (el) { el.focus(); el.setSelectionRange(el.value.length, el.value.length); }
  }, 250);
}
function _iiAppliquerPeriode() {
  const du = (document.getElementById('iiDu') || {}).value || '', au = (document.getElementById('iiAu') || {}).value || '';
  _II.periodeDu = du; _II.periodeAu = au;
  if (!du && !au) { toast('Indiquez une date de début, de fin, ou les deux.', 'error'); return; }
  let n = 0;
  _II.lignes.forEach(l => {
    const dedans = l.date && (!du || l.date >= du) && (!au || l.date <= au);
    if (!dedans && l.inclus) { l.inclus = false; n++; }
  });
  toast(n ? `${n} lignes décochées (hors de la période, ou sans date).` : 'Toutes les lignes cochées sont déjà dans la période.', 'success');
  _iiRender();
}

// ══════════════ ÉTAPE 5 — Aperçu (serveur) & import ══════════════
function _iiItems() { return _II.lignes.filter(l => l.inclus && !l.erreurs.length).map(_iiPayloadLigne); }
function _iiCorps(dryRun, items) {
  const nePas = {};
  Object.entries(_II.listesNePasCreer).forEach(([cat, set]) => { nePas[cat] = [...set]; });
  return { items, dryRun, dedup: _II.dedup, doublons: _II.doublons, typeDefaut: _II.mode === 'auto' ? _II.typeDefaut : _II.mode,
           statutDefaut: _II.statutDefaut, champsMappes: [...new Set(Object.values(_II.mapping))].filter(k => !k.startsWith('cp')), listesNePasCreer: nePas,
           // Cases à créer dans les fiches, si l'import les remplit (le serveur ne crée que celles qui manquent).
           champsPersoNouveaux: Object.values(_II.nouvellesCases).filter(n => Object.values(_II.mapping).some(v => _iiCleCase(v) === n.cle)) };
}
/** POST de l'import : même gestion des réponses illisibles que l'import des biens. */
async function _iiPost(corps) {
  let res, texte = '';
  try {
    res = await fetch('api/index.php?action=import_interventions', { method: 'POST', credentials: 'include',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify(corps) });
    texte = await res.text();
  } catch (e) { return { ok: false, error: 'Serveur injoignable : ' + e.message, code: 'NAV-002' }; }
  try {
    const j = JSON.parse(texte);
    if (j.success === false) return { ok: false, error: j.error || ('Erreur ' + res.status), code: j.code || null };
    return { ok: true, data: j.data };
  } catch (_) {
    return { ok: false, error: 'Réponse illisible du serveur (HTTP ' + res.status + ')', code: res.status >= 500 ? 'API-500' : null, brut: texte.substring(0, 1500),
             hint: res.status === 413 ? 'Fichier trop gros pour le serveur : cochez moins de lignes à la fois.' : '' };
  }
}
function _iiErreur(r, contexte) {
  const c = document.getElementById('iiPreviewArea');
  if (!c) return;
  c.innerHTML = '<div style="padding:14px;background:#fff5f5;border:1px solid #e74c3c;border-radius:8px;font-size:13px">'
    + lk('circle-x', 'ko') + ' <strong>' + _esc(contexte) + ' : ' + _esc(r.error) + '</strong>'
    + (r.code && typeof codeErreurBadge === 'function' ? ' ' + codeErreurBadge(r.code) + ' <a href="#" onclick="event.preventDefault();ouvrirCodeErreur(' + _esc(JSON.stringify(r.code)) + ')">Que faire ?</a>' : '')
    + (r.hint ? '<div style="margin-top:6px">' + _esc(r.hint) + '</div>' : '')
    + (r.brut ? '<details style="margin-top:6px"><summary>Réponse brute</summary><pre style="white-space:pre-wrap;font-size:10px">' + _esc(r.brut) + '</pre></details>' : '')
    + '</div><div style="display:flex;justify-content:space-between;margin-top:20px;padding-top:14px;border-top:1px solid var(--gray-border)">'
    + '<button class="btn btn-secondary" onclick="_II.step=4;_iiRender()">◀ Retour à la sélection</button><button class="btn btn-primary" onclick="_iiGoStep5()">↺ Réessayer</button></div>';
}

function _iiGoStep5() {
  if (!_iiItems().length) { toast('Aucune ligne cochée.', 'error'); return; }
  _II.step = 5;
  _iiRender();
  _iiRunDryRun();
}
function _iiStep5() {
  return '<div id="iiPreviewArea" style="min-height:200px"><div style="text-align:center;padding:40px;color:var(--gray-text)">' + lk('hourglass') + ' Validation en cours… (rien n\'est encore enregistré)</div></div>';
}

async function _iiRunDryRun() {
  const items = _iiItems();
  const r = await _iiPost(_iiCorps(true, items));
  if (!r.ok) { _iiErreur(r, 'Validation (étape « Aperçu »)'); return; }
  _II.apercu = r.data;
  // Les messages du serveur citent « la ligne N du fichier » : dans un planning
  // déplié, N est un rang ; on cite la cellule d'origine.
  if (_II.orientation === 'planning' && _II.apercu && Array.isArray(_II.apercu.lignes)) {
    const cite = m => String(m || '').replace(/ligne (\d+) du fichier/g, (x, n) => 'cellule ' + _iiRef(parseInt(n, 10)));
    _II.apercu.lignes.forEach(l => ['erreurs', 'avert'].forEach(k => (l[k] || []).forEach(e => { e.message = cite(e.message); })));
  }
  _iiRenderApercu(r.data);
}

const _II_ACTIONS = { creer: ['À créer', 'teal'], maj: ['Mise à jour', 'blue'], lieu: ['Lieu ajouté', 'blue'], laisse: ['Laissée (existe)', 'gray'], doublon: ['Doublon : ignorée', 'gray'], erreur: ['Erreur : ignorée', 'red'] };

/** Rapport : erreurs et avertissements du serveur ET de l'étape 4, regroupés par motif (comme l'import des biens). */
function _iiRapport(lignesServeur) {
  const out = [], cles = new Set();
  const parRow = Object.fromEntries(_II.lignes.map(l => [l.row, l]));
  const ajouter = (gravite, row, e) => {
    const k = gravite + '|' + row + '|' + (e.champ || '') + '|' + e.message + '|' + (e.valeur || '');
    if (cles.has(k)) return; cles.add(k);
    const l = parRow[row];
    out.push({ gravite, row, numero: (l && l.item.numero) || '', description: (l && l.item.description) || '', champ: e.champ || '', valeur: e.valeur || '', message: e.message });
  };
  (lignesServeur || []).forEach(l => {
    (l.erreurs || []).forEach(e => ajouter('erreur', l.row, e));
    (l.avert || []).forEach(e => ajouter('avertissement', l.row, e));
  });
  out.sort((a, b) => (a.gravite === b.gravite ? 0 : (a.gravite === 'erreur' ? -1 : 1)) || (a.row - b.row));
  _II.dernierRapport = out;
  return out;
}
function _iiRapportHtml(lignes, titre) {
  if (!lignes.length) return '';
  const nErr = lignes.filter(l => l.gravite === 'erreur').length, groupes = {};
  lignes.forEach(l => { const k = l.gravite + '|' + (l.champ || '') + '|' + l.message; (groupes[k] = groupes[k] || []).push(l); });
  let html = '<div style="padding:12px;background:' + (nErr ? '#fff5f5' : '#fffbeb') + ';border:1px solid ' + (nErr ? '#e74c3c' : '#eab308') + ';border-radius:8px;margin-bottom:12px">';
  html += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:6px"><div style="font-weight:600;font-size:13px;flex:1">' + titre + '</div>'
    + '<button class="btn btn-ghost btn-sm" onclick="_iiTelechargerRapport()" title="Toutes les lignes du rapport, pour corriger le fichier d\'origine">' + lk('arrow-down') + ' Télécharger le rapport (CSV)</button></div>';
  Object.entries(groupes).sort((a, b) => (a[0] < b[0] ? -1 : 1)).forEach(([, arr]) => {
    const { gravite, champ, message } = arr[0];
    html += '<details style="margin-top:4px"><summary style="cursor:pointer;font-size:12px">' + (gravite === 'erreur' ? lk('octagon-x', 'ko') : lk('triangle-exclamation-point', 'warn')) + ' <strong>' + arr.length + '</strong> × '
      + (champ ? '<span>' + _esc(champ) + '</span> : ' : '') + '<span>' + _esc(message) + '</span></summary>'
      + '<div style="max-height:160px;overflow-y:auto;font-size:11px;font-family:monospace;margin:4px 0 6px 18px">'
      + arr.slice(0, 200).map(l => '<div style="padding:2px 0;border-bottom:1px solid rgba(0,0,0,.05)">' + _esc(_iiRef(l.row)) + (l.numero ? ' (n° ' + _esc(l.numero) + ')' : l.description ? ' (' + _esc(l.description.substring(0, 40)) + ')' : '') + (l.valeur ? ' — « ' + _esc(l.valeur) + ' »' : '') + '</div>').join('')
      + (arr.length > 200 ? '<div style="color:var(--gray-text)">… et ' + (arr.length - 200) + ' autre(s) (voir le CSV)</div>' : '') + '</div></details>';
  });
  return html + '</div>';
}
function _iiTelechargerRapport() {
  const r = _II.dernierRapport;
  if (!r || !r.length) return;
  const q = v => '"' + String(v == null ? '' : v).replace(/"/g, '""') + '"';
  _iiTelecharger('﻿' + [(_II.orientation === 'colonnes' ? 'Colonne du fichier' : _iiRefTitre()) + ';N°;Description;Gravité;Champ;Valeur;Message']
    .concat(r.map(l => [_II.orientation === 'colonnes' ? _biColLettre(l.row) : _II.orientation === 'planning' ? _iiRef(l.row) : l.row, l.numero, l.description, l.gravite, l.champ, l.valeur, l.message].map(q).join(';'))).join('\r\n'),
    'rapport-import-interventions-' + new Date().toISOString().substring(0, 10) + '.csv');
}
function _iiListeBasculer(cat, val, creer) {
  const m = _II.listesNePasCreer;
  if (!m[cat]) m[cat] = new Set();
  if (creer) m[cat].delete(val); else m[cat].add(val);
}

function _iiRenderApercu(a) {
  const c = document.getElementById('iiPreviewArea');
  if (!c) return;
  const aImporter = a.aCreer + a.aMettreAJour;
  let html = '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:14px">';
  html += '<div style="padding:12px;background:var(--blue-pale);border-radius:8px;text-align:center"><div style="font-size:22px;font-weight:700;color:var(--blue)">' + a.aCreer + '</div><div style="font-size:11px">à créer</div></div>';
  html += '<div style="padding:12px;background:#fef9c3;border-radius:8px;text-align:center"><div style="font-size:22px;font-weight:700;color:#a16207">' + a.aMettreAJour + '</div><div style="font-size:11px">à mettre à jour</div></div>';
  html += '<div style="padding:12px;background:var(--gray-bg);border-radius:8px;text-align:center"><div style="font-size:22px;font-weight:700;color:var(--gray-text)">' + (a.doublons + a.laissees) + '</div><div style="font-size:11px">ignorées (déjà dans Larka)</div></div>';
  html += '<div style="padding:12px;background:' + (a.enErreur ? '#fee' : 'var(--gray-bg)') + ';border-radius:8px;text-align:center"><div style="font-size:22px;font-weight:700;color:' + (a.enErreur ? '#e74c3c' : 'var(--gray-text)') + '">' + a.enErreur + '</div><div style="font-size:11px">en erreur</div></div>';
  html += '</div>';
  if (Object.keys(a.parType || {}).length) {
    html += '<div style="font-size:12px;color:var(--gray-text);margin-bottom:10px">' + lk('tag') + ' Par type : ' + Object.entries(a.parType).map(([t, n]) => '<strong>' + _esc(t) + '</strong> ' + n).join(' · ') + '</div>';
  }
  const rapport = _iiRapport(a.lignes);
  const nE = rapport.filter(l => l.gravite === 'erreur').length, nA = rapport.length - nE;
  html += _iiRapportHtml(rapport, nE
    ? lk('octagon-x', 'ko') + ' <b>' + nE + '</b> <span>erreur(s) : lignes ignorées</span>' + (nA ? ' · <b>' + nA + '</b> <span>avertissement(s)</span>' : '')
    : lk('triangle-exclamation-point', 'warn') + ' <b>' + nA + '</b> <span>avertissement(s) : les lignes sont importées, voir le détail</span>');

  const listes = a.listesACreer || {};
  if (Object.keys(listes).length) {
    html += '<div style="padding:12px;background:#f0fdf4;border:1px solid #16a34a;border-radius:8px;margin-bottom:12px;font-size:12px">'
      + '<div style="font-weight:600;margin-bottom:6px">' + lk('list-plus') + ' Nouvelles valeurs ajoutées aux listes de choix</div>'
      + '<div style="color:var(--gray-text);margin-bottom:6px">Décochez une valeur pour ne pas l\'ajouter à la liste : les interventions la gardent quand même.</div>'
      + Object.entries(listes).map(([cat, vals]) => '<div style="margin-top:4px"><strong>' + (cat === 'TypeIntervention' ? 'Types' : 'Priorités') + ' :</strong> '
        + vals.map(v => '<label style="display:inline-flex;align-items:center;gap:4px;margin-right:10px;cursor:pointer"><input type="checkbox" ' + ((_II.listesNePasCreer[cat] || new Set()).has(v) ? '' : 'checked')
          + ' onchange="_iiListeBasculer(' + jsArg(cat) + ',' + jsArg(v) + ',this.checked)"> ' + _esc(v) + '</label>').join('') + '</div>').join('')
      + '</div>';
  }

  if ((a.casesACreer || []).length) {
    html += '<div style="padding:12px;background:#f5f3ff;border:1px solid #7c3aed;border-radius:8px;margin-bottom:12px;font-size:12px;color:#5b21b6">'
      + '<div style="font-weight:600;margin-bottom:4px">' + lk('folders') + ' Cases créées dans les fiches d\'intervention</div>'
      + (a.casesACreer || []).map(l => '« ' + _esc(l) + ' »').join(', ')
      + '<div style="color:var(--gray-text);margin-top:4px">Section « Informations complémentaires » de chaque fiche ; à renommer ou retirer depuis « Cases des fiches ».</div>'
      + ((a.casesRessemblances || []).length ? '<div style="margin-top:6px;color:#a16207">' + lk('triangle-exclamation-point', 'warn') + ' '
          + a.casesRessemblances.map(r => '« ' + _esc(r.libelle) + ' » ressemble à ' + Math.round(r.similarite * 100) + ' % à « ' + _esc(r.ressemble_a) + ' »'
            + (r.statut === 'supprimee' ? ' (case retirée)' : '')).join(' ; ')
          + ' — revenez à l\'étape 3 pour choisir la case existante si c\'est la même.</div>' : '')
      + '</div>';
  }
  html += '<div style="font-size:13px;font-weight:600;margin-bottom:6px">Aperçu (8 premières lignes cochées) :</div>';
  html += '<div style="max-height:220px;overflow-y:auto;border:1px solid var(--gray-border);border-radius:6px;margin-bottom:14px"><table style="width:100%;font-size:11px;border-collapse:collapse">';
  html += '<tr style="background:var(--gray-bg);font-weight:700"><td style="padding:6px 8px">' + _iiRefTitre() + '</td><td style="padding:6px 8px">Action</td><td style="padding:6px 8px">Type</td><td style="padding:6px 8px">Statut</td><td style="padding:6px 8px">Description</td><td style="padding:6px 8px">Équipement / bien</td><td style="padding:6px 8px">Date</td></tr>';
  (a.lignes || []).slice(0, 8).forEach(l => {
    const [lib, coul] = _II_ACTIONS[l.action] || [l.action, 'gray'];
    html += '<tr style="border-top:1px solid var(--gray-bg)"><td style="padding:4px 8px;color:var(--gray-text);white-space:nowrap">' + _esc(_iiRef(l.row)) + '</td>'
      + '<td style="padding:4px 8px"><span class="badge badge-' + coul + '" style="font-size:10px">' + lib + '</span>' + (l.numero ? ' <span style="color:var(--gray-text)">' + _esc(l.numero) + '</span>' : '') + '</td>'
      + '<td style="padding:4px 8px">' + _esc(l.type) + '</td><td style="padding:4px 8px">' + _esc(_II_STATUT_LIB[l.statut] || l.statut) + '</td>'
      + '<td style="padding:4px 8px;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + _esc(l.description) + '">' + _esc(l.description) + '</td>'
      + '<td style="padding:4px 8px">' + _esc(l.actif || '—') + '</td><td style="padding:4px 8px;white-space:nowrap">' + (l.date ? _esc(_iiDateFr(l.date)) : '') + '</td></tr>';
  });
  html += '</table></div>';

  html += '<div style="display:flex;justify-content:space-between;margin-top:20px;padding-top:14px;border-top:1px solid var(--gray-border)">';
  html += '<button class="btn btn-secondary" onclick="_II.step=4;_iiRender()">◀ Retour à la sélection</button>';
  html += aImporter > 0
    ? '<button class="btn btn-primary" onclick="_iiRunImport()">' + lk('circle-check', 'ok') + ' Importer (' + aImporter + ' intervention' + (aImporter > 1 ? 's' : '') + ')</button>'
    : '<button class="btn btn-primary" disabled>Aucune à importer</button>';
  html += '</div>';
  c.innerHTML = html;
}

async function _iiRunImport() {
  const c = document.getElementById('iiPreviewArea');
  if (!c) return;
  // Seules les lignes que l'aperçu annonce à créer ou à mettre à jour partent :
  // ni les doublons ni les erreurs n'ont à faire le voyage.
  const utiles = new Set((_II.apercu && _II.apercu.lignes || []).filter(l => l.action === 'creer' || l.action === 'maj' || l.action === 'lieu').map(l => l.row));
  const items = _iiItems().filter(it => utiles.has(it._row));
  const total = items.length, parLots = total > _II_LOT;
  const progres = (fait, msg) => {
    const pct = total ? Math.round(fait / total * 100) : 0;
    c.innerHTML = '<div style="text-align:center;padding:30px"><div style="font-size:24px;margin-bottom:10px">' + lk('hourglass') + '</div>'
      + '<div style="font-size:14px;font-weight:600;margin-bottom:14px">' + (msg || 'Import en cours…') + '</div>'
      + '<div style="background:var(--gray-bg);border-radius:8px;height:24px;overflow:hidden;margin:0 auto;max-width:400px;position:relative">'
      + '<div style="background:linear-gradient(90deg,var(--blue) 0%,#16a34a 100%);height:100%;width:' + pct + '%;transition:width .3s"></div>'
      + '<div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;mix-blend-mode:difference;color:#fff">' + fait + ' / ' + total + ' (' + pct + '%)</div></div>'
      + '<div style="font-size:11px;color:var(--gray-text);margin-top:10px">' + (parLots ? 'Découpage en lots de ' + _II_LOT + ' pour éviter les délais dépassés' : 'Cela peut prendre quelques secondes…') + '</div></div>';
  };
  const t = { crees: 0, misesAJour: 0, laissees: 0, echecs: [], listesAjoutees: 0, casesCreees: 0 };
  let fait = 0;
  for (let i = 0, lot = 1, nLots = Math.ceil(total / _II_LOT); i < total; i += _II_LOT, lot++) {
    const morceau = items.slice(i, i + _II_LOT);
    progres(fait, parLots ? 'Lot ' + lot + ' / ' + nLots + ' (' + morceau.length + ' lignes)…' : null);
    const r = await _iiPost(_iiCorps(false, morceau));
    if (!r.ok) {
      _iiErreur(r, parLots ? 'Lot ' + lot + '/' + nLots + ' — ' + fait + ' ligne(s) des lots précédents déjà importée(s)' : 'Import');
      if (fait) _iiRafraichir();
      return;
    }
    const d = r.data;
    t.crees += d.crees || 0; t.misesAJour += d.misesAJour || 0; t.laissees += d.laissees || 0; t.listesAjoutees += d.listesAjoutees || 0; t.casesCreees += d.casesCreees || 0;
    (d.echecs || []).forEach(e => t.echecs.push(e));
    fait += morceau.length;
    progres(fait);
    if (parLots) await new Promise(res => setTimeout(res, 50));
  }
  const rapport = t.echecs.map(e => {
    const l = _II.lignes.find(x => x.row === e.row);
    return { gravite: 'erreur', row: e.row, numero: (l && l.item.numero) || '', description: (l && l.item.description) || '', champ: '', valeur: '', message: e.message };
  });
  _II.dernierRapport = rapport;
  let html = '<div style="text-align:center;padding:24px 10px">';
  html += '<div style="font-size:48px;margin-bottom:10px">' + (t.echecs.length ? lk('triangle-exclamation-point', 'warn') : lk('party-popper')) + '</div>';
  html += '<div style="font-size:18px;font-weight:700;margin-bottom:14px">' + (t.echecs.length ? 'Import terminé, avec des lignes en échec' : 'Import terminé !') + '</div>';
  html += '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:20px;max-width:500px;margin-left:auto;margin-right:auto">';
  html += '<div style="padding:14px;background:var(--blue-pale);border-radius:8px"><div style="font-size:24px;font-weight:700;color:var(--blue)">' + t.crees + '</div><div style="font-size:11px">créées</div></div>';
  html += '<div style="padding:14px;background:#fef9c3;border-radius:8px"><div style="font-size:24px;font-weight:700;color:#a16207">' + t.misesAJour + '</div><div style="font-size:11px">mises à jour</div></div>';
  html += '<div style="padding:14px;background:' + (t.echecs.length ? '#fee' : 'var(--gray-bg)') + ';border-radius:8px"><div style="font-size:24px;font-weight:700;color:' + (t.echecs.length ? '#e74c3c' : 'var(--gray-text)') + '">' + t.echecs.length + '</div><div style="font-size:11px">échecs</div></div>';
  html += '</div>';
  if (t.listesAjoutees) html += '<div style="font-size:12px;color:var(--gray-text);margin-bottom:10px">+ ' + t.listesAjoutees + ' valeur(s) ajoutée(s) dans les listes</div>';
  if (t.casesCreees) html += '<div style="font-size:12px;color:var(--gray-text);margin-bottom:10px">+ ' + t.casesCreees + ' case(s) ajoutée(s) aux fiches d\'intervention</div>';
  html += '<div style="font-size:12px;color:var(--gray-text)">L\'import est inscrit au Journal. Les préventives et contrôles réglementaires importés sont sur leurs écrans, dans « Hors plan ».</div>';
  html += '</div>';
  html += '<div style="text-align:left;max-width:680px;margin:0 auto">' + _iiRapportHtml(rapport, lk('triangle-exclamation-point', 'warn') + ' <b>' + rapport.length + '</b> <span>ligne(s) à reprendre</span>') + '</div>';
  html += '<div style="text-align:center;margin-top:10px"><button class="btn btn-ghost" onclick="openImportInterventions(_II.origine)">Importer un autre fichier</button> '
    + '<button class="btn btn-primary" onclick="closeModal();_iiRafraichir()">Fermer & rafraîchir</button></div>';
  c.innerHTML = html;
  _iiRafraichir();
  if (typeof toast === 'function') toast('Import : ' + t.crees + ' créées, ' + t.misesAJour + ' mises à jour' + (t.echecs.length ? ', ' + t.echecs.length + ' échecs' : ''), t.echecs.length ? 'warning' : 'success');
}

function _iiRafraichir() {
  // App est un « const » global (init.js) : pas une propriété de window, d'où le typeof.
  if (typeof _intervRafraichir === 'function' && _II.origine === 'interventions') { try { _intervRafraichir(); } catch (_) {} }
  else if (typeof App !== 'undefined' && typeof App.renderCurrentPage === 'function') { try { App.renderCurrentPage(); } catch (_) {} }
}

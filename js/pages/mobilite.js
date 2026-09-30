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
 * Larka — Page : Mobilité Carbone (onglet demandeur)
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Permet aux demandeurs de renseigner leurs trajets domicile-travail et
 * déplacements professionnels pour calculer leur bilan carbone mobilité.
 *
 * QUATRE PÉRIMÈTRES EMBOÎTÉS (kgCO₂e / passager.km)
 * -------------------------------------------------
 * Du plus large au plus étroit, chacun contenu dans le précédent :
 *   • co2Acv        construction du véhicule + amont carburant + combustion
 *                   + forçage radiatif (avion). Bilan carbone d'entreprise.
 *   • co2HorsConstr idem sans la fabrication du véhicule.
 *   • co2Combustion combustion à bord seule — périmètre « scope 1 ».
 *                   Nul pour tout mode électrique ou musculaire.
 *   • co2Seul       idem, en CO₂ uniquement (sans CH₄ ni N₂O).
 *
 * ⚠️ Les deux premiers viennent directement de l'API ADEME. Les deux derniers
 * en sont DÉRIVÉS par ratio (part de combustion dans le poste carburant, part
 * du CO₂ dans les GES de combustion) : ce sont des estimations, signalées
 * comme telles dans l'interface.
 *
 * MILLÉSIMES
 * ----------
 * Les facteurs sont figés PAR ANNÉE côté serveur (table FacteurMobilite) et ne
 * sont jamais recalculés rétroactivement : un trajet 2025 reste calculé avec
 * les facteurs 2025 même après une révision du référentiel. Chaque ligne porte
 * son année ; la page affiche une année à la fois.
 *
 * SOURCE : ADEME — Impact CO2 (paramètre includeConstruction de l'API).
 * Les valeurs ci-dessous sont un instantané servant de repli. Si la source est
 * activée dans la configuration serveur, elles sont écrasées au chargement
 * (voir loadFacteursCarbone → route facteurs_carbone).
 *
 * POINT D'ENTRÉE : renderMobiliteCarbone()
 * ═══════════════════════════════════════════════════════════════════════════════
 */

// ── Facteurs de repli — instantané du référentiel Impact CO2 ────────────────
const TRANSPORTS_DEFAUT = [
  // co2Acv / co2HorsConstr : référentiel ADEME. co2Combustion / co2Seul : dérivés.
  { id:'voiture_thermique',   label:'🚗 Voiture thermique',              co2Acv:0.14225, co2HorsConstr:0.11056, co2Combustion:0.08955, co2Seul:0.08866, cat:'Individuel' },
  { id:'voiture_hybride',     label:'🔋 Voiture hybride',                co2Acv:0.14658, co2HorsConstr:0.11342, co2Combustion:0.09187, co2Seul:0.09095, cat:'Individuel' },
  { id:'voiture_electrique',  label:'🔌 Voiture électrique',             co2Acv:0.06737, co2HorsConstr:0.01209, co2Combustion:0.00000, co2Seul:0.00000, cat:'Individuel' },
  { id:'covoiturage_2',       label:'👥 Covoiturage (2 pers.)',          co2Acv:0.07113, co2HorsConstr:0.05528, co2Combustion:0.04478, co2Seul:0.04433, cat:'Individuel' },
  { id:'covoiturage_3',       label:'👥 Covoiturage (3 pers.)',          co2Acv:0.04742, co2HorsConstr:0.03685, co2Combustion:0.02985, co2Seul:0.02955, cat:'Individuel' },
  { id:'moto',                label:'🏍️ Moto (> 250 cm³)',               co2Acv:0.21470, co2HorsConstr:0.14000, co2Combustion:0.11340, co2Seul:0.11227, cat:'Individuel' },
  { id:'scooter_thermique',   label:'🛵 Scooter / moto légère',          co2Acv:0.07630, co2HorsConstr:0.06040, co2Combustion:0.04892, co2Seul:0.04843, cat:'Individuel' },
  { id:'scooter_elec',        label:'🛵 Scooter électrique',             co2Acv:0.05930, co2HorsConstr:0.02070, co2Combustion:0.00000, co2Seul:0.00000, cat:'Individuel' },
  { id:'velo',                label:'🚲 Vélo',                           co2Acv:0.00017, co2HorsConstr:0.00000, co2Combustion:0.00000, co2Seul:0.00000, cat:'Mobilité douce' },
  { id:'velo_elec',           label:'🔋 Vélo électrique (VAE)',          co2Acv:0.01095, co2HorsConstr:0.00223, co2Combustion:0.00000, co2Seul:0.00000, cat:'Mobilité douce' },
  { id:'trottinette_elec',    label:'🛴 Trottinette électrique',         co2Acv:0.02490, co2HorsConstr:0.00200, co2Combustion:0.00000, co2Seul:0.00000, cat:'Mobilité douce' },
  { id:'marche',              label:'🚶 Marche à pied',                  co2Acv:0.00000, co2HorsConstr:0.00000, co2Combustion:0.00000, co2Seul:0.00000, cat:'Mobilité douce' },
  { id:'bus_thermique',       label:'🚌 Bus (thermique)',                co2Acv:0.12242, co2HorsConstr:0.11350, co2Combustion:0.09194, co2Seul:0.09102, cat:'Transport en commun' },
  { id:'bus_electrique',      label:'🚎 Bus électrique',                 co2Acv:0.02170, co2HorsConstr:0.00950, co2Combustion:0.00000, co2Seul:0.00000, cat:'Transport en commun' },
  { id:'autocar',             label:'🚍 Autocar',                        co2Acv:0.03756, co2HorsConstr:0.03314, co2Combustion:0.02684, co2Seul:0.02657, cat:'Transport en commun' },
  { id:'tramway',             label:'🚊 Tramway',                        co2Acv:0.00428, co2HorsConstr:0.00380, co2Combustion:0.00000, co2Seul:0.00000, cat:'Transport en commun' },
  { id:'metro',               label:'🚇 Métro',                          co2Acv:0.00444, co2HorsConstr:0.00420, co2Combustion:0.00000, co2Seul:0.00000, cat:'Transport en commun' },
  { id:'rer',                 label:'🚆 RER / Transilien',               co2Acv:0.00978, co2HorsConstr:0.00660, co2Combustion:0.00000, co2Seul:0.00000, cat:'Transport en commun' },
  { id:'ter',                 label:'🚈 TER',                            co2Acv:0.02769, co2HorsConstr:0.02290, co2Combustion:0.01030, co2Seul:0.01020, cat:'Transport en commun' },
  { id:'intercites',          label:'🚂 Intercités',                     co2Acv:0.00898, co2HorsConstr:0.00580, co2Combustion:0.00116, co2Seul:0.00115, cat:'Transport en commun' },
  { id:'tgv',                 label:'🚅 TGV',                            co2Acv:0.00293, co2HorsConstr:0.00230, co2Combustion:0.00000, co2Seul:0.00000, cat:'Transport en commun' },
  { id:'avion_court',         label:'✈️ Avion court-courrier (<1000 km)', co2Acv:0.22457, co2HorsConstr:0.22420, co2Combustion:0.09979, co2Seul:0.09879, cat:'Aérien' },
  { id:'avion_moyen',         label:'✈️ Avion moyen-courrier',           co2Acv:0.18466, co2HorsConstr:0.18430, co2Combustion:0.08205, co2Seul:0.08123, cat:'Aérien' },
  { id:'avion_long',          label:'✈️ Avion long-courrier',            co2Acv:0.16679, co2HorsConstr:0.16650, co2Combustion:0.07412, co2Seul:0.07337, cat:'Aérien' },
  { id:'personnalise',        label:'✏️ Personnalisé',                   co2Acv:0, co2HorsConstr:0, co2Combustion:0, co2Seul:0, cat:'Autre' },
];

// Table active — remplacée/complétée par la route facteurs_carbone si disponible.
let TRANSPORTS_ADEME = TRANSPORTS_DEFAUT.map(function(t) { return Object.assign({}, t, { co2: t.co2Acv, origine:'local' }); });

// Métadonnées de la source réellement utilisée (affichées en pied de page)
let FACTEURS_SOURCE = { origine:'local', libelle:'Valeurs de référence intégrées', maj:null, message:'', anneeFacteurs:null };

const FREQUENCES = [
  { id:'jour',      label:'par jour ouvré',   facAnnuel: 228 },
  { id:'semaine',   label:'par semaine',       facAnnuel: 47  },
  { id:'mois',      label:'par mois',          facAnnuel: 12  },
  { id:'annee',     label:'par an',            facAnnuel: 1   },
  { id:'ponctuel',  label:'ponctuel (total)',   facAnnuel: 1   },
];

// ── Méthode de calcul active ────────────────────────────────────────────────
const METHODES = [
  { id:'acv',        champ:'co2Acv',        label:'ACV complet',      court:'ACV',        source:'api',
    desc:'Fabrication du véhicule, amont carburant, combustion et forçage radiatif. Périmètre d\'un bilan carbone d\'entreprise.' },
  { id:'usage',      champ:'co2HorsConstr', label:'Hors construction', court:'Hors constr.', source:'api',
    desc:'Amont carburant et combustion, sans la fabrication du véhicule.' },
  { id:'combustion', champ:'co2Combustion', label:'Combustion (scope 1)', court:'Combustion', source:'derive',
    desc:'Combustion à bord seule, hors amont carburant. Nul pour les modes électriques et musculaires.' },
  { id:'co2seul',    champ:'co2Seul',       label:'CO₂ seul',         court:'CO₂ seul',   source:'derive',
    desc:'Combustion à bord, en CO₂ uniquement — sans méthane ni protoxyde d\'azote.' },
];

const MobiliteConfig = {
  _methode: null,
  get methode() {
    if (this._methode) return this._methode;
    try { this._methode = localStorage.getItem('larka_mobilite_methode') || 'acv'; }
    catch(e) { this._methode = 'acv'; }
    return this._methode;
  },
  set methode(v) {
    this._methode = METHODES.some(function(m){ return m.id === v; }) ? v : 'acv';
    try { localStorage.setItem('larka_mobilite_methode', this._methode); } catch(e) {}
  },

  _annee: null,
  get annee() {
    if (this._annee) return this._annee;
    var stocke = null;
    try { stocke = parseInt(localStorage.getItem('larka_mobilite_annee')); } catch(e) {}
    this._annee = (stocke && stocke > 2000 && stocke < 2100) ? stocke : new Date().getFullYear();
    return this._annee;
  },
  set annee(v) {
    var n = parseInt(v);
    if (!n || n < 2000 || n > 2100) return;
    this._annee = n;
    try { localStorage.setItem('larka_mobilite_annee', String(n)); } catch(e) {}
  },
};

/** Année d'une ligne. Les trajets antérieurs à la mise en place des millésimes
 *  n'ont pas d'année : ils sont rattachés à l'année courante plutôt que masqués. */
function _anneeLigne(ligne) {
  var a = parseInt(ligne.Annee);
  return (a && a > 2000 && a < 2100) ? a : new Date().getFullYear();
}

const MobiliteApi = {
  getAll:  ()          => apiRequest('mobilite_carbone'),
  create:  (data)      => apiRequest('mobilite_carbone', 'POST', data),
  update:  (id, data)  => apiRequest('mobilite_carbone', 'PUT', data, id),
  delete:  (id)        => apiRequest('mobilite_carbone', 'DELETE', null, id),
  adminAll:()          => apiRequest('mobilite_carbone_all'),
  facteurs:(annee)     => apiRequest('facteurs_carbone&annee=' + annee),
  millesimes:()        => apiRequest('facteurs_carbone_millesimes'),
  sync:(annee, ecraser)=> apiRequest('facteurs_carbone_sync', 'POST', { annee: annee, ecraser: !!ecraser }),
};

// ═══════════════════════════════════════════════════════════════════════════════
//  CHARGEMENT DES FACTEURS
// ═══════════════════════════════════════════════════════════════════════════════
/**
 * Récupère les facteurs via la route facteurs_carbone, qui sert de proxy vers
 * l'ADEME (la clé API reste côté serveur, dans .env).
 *
 * Réponse attendue : { source, maj, origine, message, facteurs:[{id, co2Acv,
 * co2HorsConstr}] }. Fusion par `id` : un mode ou un champ absent conserve sa
 * valeur de repli. Liste vide (source désactivée ou indisponible) → on garde
 * les valeurs intégrées, sans erreur visible pour l'utilisateur.
 */
var _facteursParAnnee = {};   // annee => true (millésime déjà chargé)
var _anneeChargee = null;     // année actuellement appliquée à TRANSPORTS_ADEME

async function loadFacteursCarbone(annee, force) {
  annee = parseInt(annee) || MobiliteConfig.annee;
  if (!force && _anneeChargee === annee) return TRANSPORTS_ADEME;

  // Cache navigateur (2 h) — le millésime est déjà figé côté serveur, ce cache
  // n'évite qu'un aller-retour réseau à chaque navigation.
  if (!force) {
    try {
      var brut = localStorage.getItem('larka_facteurs_carbone_' + annee);
      if (brut) {
        var cache = JSON.parse(brut);
        if (cache && cache.ts && (Date.now() - cache.ts) < 7200000 && Array.isArray(cache.facteurs) && cache.facteurs.length) {
          _appliquerFacteurs(cache.facteurs, cache.source, cache.maj, 'cache', '', cache.anneeFacteurs);
          _anneeChargee = annee;
          return TRANSPORTS_ADEME;
        }
      }
    } catch(e) { /* cache illisible → on ignore */ }
  }

  try {
    var data = await MobiliteApi.facteurs(annee);
    var liste = (data && Array.isArray(data.facteurs)) ? data.facteurs : [];

    if (!liste.length) {
      // Aucun millésime et source injoignable : les valeurs intégrées prennent
      // le relais. Ce n'est pas une erreur, mais l'utilisateur doit le savoir.
      _appliquerFacteurs([], null, null, 'local', (data && data.message) || '', null);
    } else {
      _appliquerFacteurs(liste, data.source, data.maj, data.origine || 'api',
                         data.message || '', data.anneeFacteurs);
      try {
        localStorage.setItem('larka_facteurs_carbone_' + annee, JSON.stringify({
          ts: Date.now(), facteurs: liste, source: data.source, maj: data.maj,
          anneeFacteurs: data.anneeFacteurs,
        }));
      } catch(e) {}
    }
  } catch(e) {
    console.warn('[Mobilite] Millésime ' + annee + ' indisponible, valeurs locales utilisées :', e.message);
    _appliquerFacteurs([], null, null, 'local', '', null);
  }

  _anneeChargee = annee;
  return TRANSPORTS_ADEME;
}

function _appliquerFacteurs(liste, source, maj, origine, message, anneeFacteurs) {
  var index = {};
  liste.forEach(function(f) { if (f && f.id) index[f.id] = f; });

  function nombre(v, defaut) {
    if (v === undefined || v === null) return defaut;
    var n = parseFloat(v);
    return isNaN(n) ? defaut : n;
  }

  TRANSPORTS_ADEME = TRANSPORTS_DEFAUT.map(function(base) {
    var maj_ = index[base.id] || {};
    var acv  = nombre(maj_.co2Acv, base.co2Acv);
    return {
      id: base.id,
      label: maj_.label || base.label,
      cat:   maj_.cat   || base.cat,
      co2Acv:        acv,
      co2HorsConstr: nombre(maj_.co2HorsConstr, base.co2HorsConstr),
      co2Combustion: nombre(maj_.co2Combustion, base.co2Combustion),
      co2Seul:       nombre(maj_.co2Seul,       base.co2Seul),
      co2: acv,                                  // rétrocompatibilité : `co2` = ACV
      origine: index[base.id] ? 'api' : 'local',
    };
  });

  // Modes renvoyés par le serveur mais absents de la table locale → ajoutés.
  liste.forEach(function(f) {
    if (!f || !f.id) return;
    if (TRANSPORTS_ADEME.some(function(t){ return t.id === f.id; })) return;
    var acv = nombre(f.co2Acv, 0);
    TRANSPORTS_ADEME.push({
      id: f.id, label: f.label || f.id, cat: f.cat || 'Autre',
      co2Acv: acv,
      co2HorsConstr: nombre(f.co2HorsConstr, 0),
      co2Combustion: nombre(f.co2Combustion, 0),
      co2Seul:       nombre(f.co2Seul, 0),
      co2: acv, origine:'api',
    });
  });

  FACTEURS_SOURCE = {
    origine: origine,
    libelle: source || 'Valeurs de référence intégrées',
    maj: maj || null,
    message: message || '',
    anneeFacteurs: anneeFacteurs || null,
  };
}

// ── Helpers ─────────────────────────────────────────────────────────────────
function _getTransport(id) { return TRANSPORTS_ADEME.find(t => t.id === id) || TRANSPORTS_ADEME[TRANSPORTS_ADEME.length-1]; }
function _getFreq(id) { return FREQUENCES.find(f => f.id === id) || FREQUENCES[0]; }
function _getMethode(id) { return METHODES.find(m => m.id === id) || METHODES[0]; }


/**
 * Facteur retenu pour une ligne, selon le périmètre demandé.
 * Priorité : valeur saisie sur la ligne > valeur de la table de référence.
 * `FacteurCO2` (colonne historique) = facteur ACV.
 */
// Colonne de surcharge stockée sur la ligne, par périmètre.
var CHAMPS_LIGNE = {
  acv:        'FacteurCO2',
  usage:      'FacteurCO2HorsConstr',
  combustion: 'FacteurCO2Combustion',
  co2seul:    'FacteurCO2Seul',
};

function _facteurLigne(ligne, methode) {
  methode = methode || MobiliteConfig.methode;
  var m = _getMethode(methode);
  var saisi = parseFloat(ligne[CHAMPS_LIGNE[m.id]]);
  if (!isNaN(saisi) && saisi > 0) return saisi;
  var t = _getTransport(ligne.Transport);
  return t[m.champ] || 0;
}

/**
 * Kilométrage ANNUEL d'une déclaration.
 *
 * La liste montrait « 15 km × 2 trajet(s) par jour » : le chiffre qui compte
 * dans un bilan — combien de kilomètres par an — restait à faire de tête, en
 * devinant au passage le nombre de jours travaillés retenu. Deux personnes
 * arrivaient à deux résultats, et aucune ne savait laquelle avait raison.
 *
 * Même formule que le calcul de CO2, sans le facteur d'émission : les deux
 * chiffres ne peuvent donc pas diverger.
 */
function _kmAnnuel(ligne) {
  var f = _getFreq(ligne.Frequence);
  var dist = parseFloat(ligne.DistanceKm) || 0;
  var nb = parseFloat(ligne.NbFrequence) || 1;
  return dist * nb * f.facAnnuel;
}

function _fmtKm(km) {
  if (!km) return '0 km';
  return Math.round(km).toLocaleString('fr-FR') + ' km';
}

function _co2Annuel(ligne, methode) {
  var f = _getFreq(ligne.Frequence);
  var co2 = _facteurLigne(ligne, methode);
  var dist = parseFloat(ligne.DistanceKm) || 0;
  var nb = parseFloat(ligne.NbFrequence) || 1;
  return co2 * dist * nb * f.facAnnuel;
}

function _fmtCO2(kg) {
  if (!kg || kg === 0) return '0 kg';
  if (kg >= 1000) return (kg/1000).toFixed(2) + ' t';
  return kg.toFixed(1) + ' kg';
}

function _fmtGramme(kgParKm) { return (kgParKm * 1000).toFixed(kgParKm < 0.01 ? 1 : 0) + ' g/km'; }

// Bascule ACV ↔ hors construction depuis le bandeau
function setMobiliteMethode(m) {
  MobiliteConfig.methode = m;
  renderMobiliteCarbone();
}

// Change l'année de bilan affichée
function setMobiliteAnnee(a) {
  MobiliteConfig.annee = a;
  renderMobiliteCarbone();
}

// Force un rechargement des facteurs de l'année courante (ignore le cache)
async function refreshFacteursCarbone() {
  var annee = MobiliteConfig.annee;
  try { localStorage.removeItem('larka_facteurs_carbone_' + annee); } catch(e) {}
  await loadFacteursCarbone(annee, true);
  if (FACTEURS_SOURCE.origine === 'local') {
    toast('Aucun millésime pour ' + annee + ' — valeurs intégrées conservées.', 'error');
  } else {
    toast('Facteurs ' + annee + ' rechargés (' + FACTEURS_SOURCE.libelle + ').', 'success');
  }
  renderMobiliteCarbone();
}

// ═══════════════════════════════════════════════════════════════════════════════
//  RENDER — Page Mobilité (demandeurs + admin)
// ═══════════════════════════════════════════════════════════════════════════════
async function renderMobiliteCarbone() {
  document.getElementById('topbarActions').innerHTML = '';
  var c = document.getElementById('mainContent');
  showLoading(c);
  try {
    var annee = MobiliteConfig.annee;
    await loadFacteursCarbone(annee);

    var methode = MobiliteConfig.methode;
    var meth = _getMethode(methode);

    var toutesLignes = await MobiliteApi.getAll();
    // Une année à la fois : mélanger des millésimes différents dans un même
    // total n'aurait pas de sens, chaque année ayant ses propres facteurs.
    var lignes = toutesLignes.filter(function(l) { return _anneeLigne(l) === annee; });

    // Années proposées : celles présentes dans les données, plus les trois
    // dernières, pour pouvoir saisir l'exercice en cours et les précédents.
    var anneesSet = {};
    toutesLignes.forEach(function(l) { anneesSet[_anneeLigne(l)] = true; });
    var courante = new Date().getFullYear();
    [courante, courante - 1, courante - 2].forEach(function(a) { anneesSet[a] = true; });
    anneesSet[annee] = true;
    var annees = Object.keys(anneesSet).map(Number).sort(function(a,b){ return b - a; });
    var totalAnnuel = lignes.reduce(function(s,l){ return s + _co2Annuel(l, methode); }, 0);
    var totalAcv    = lignes.reduce(function(s,l){ return s + _co2Annuel(l, 'acv'); }, 0);

    // Grouper par catégorie
    var byCat = {};
    lignes.forEach(function(l) {
      var t = _getTransport(l.Transport);
      var cat = t.cat || 'Autre';
      if (!byCat[cat]) byCat[cat] = [];
      byCat[cat].push(l);
    });

    var html = '';

    // Hero
    html += '<div class="card" style="padding:20px 24px;background:linear-gradient(135deg,#065f46,#047857);color:white;border:none;margin-bottom:16px;border-radius:10px">';
    html += '<div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">';
    html += '<span style="font-size:28px">🚗</span>';
    html += '<div><div style="font-size:16px;font-weight:700">Mon bilan carbone mobilité ' + annee + '</div>';
    html += '<div style="font-size:12px;opacity:.75;margin-top:2px">Renseignez vos trajets pour estimer votre empreinte carbone liée aux déplacements</div>';
    html += '</div>';
    html += '<div style="margin-left:auto;text-align:right">';
    html += '<select onchange="setMobiliteAnnee(this.value)" style="background:rgba(255,255,255,.15);color:white;border:1px solid rgba(255,255,255,.35);border-radius:6px;padding:2px 8px;font-size:12px;font-weight:600;cursor:pointer;margin-bottom:4px">'
         + annees.map(function(a) {
             return '<option value="' + a + '"' + (a === annee ? ' selected' : '') + ' style="color:#065f46">Bilan ' + a + '</option>';
           }).join('')
         + '</select>';
    html += '<div style="font-size:24px;font-weight:800">' + _fmtCO2(totalAnnuel) + '</div>';
    html += '<div style="font-size:11px;opacity:.7">' + (methode === 'co2seul' ? 'CO₂' : 'CO₂e') + ' / an — ' + meth.label + '</div>';
    if (methode !== 'acv') html += '<div style="font-size:10px;opacity:.55;margin-top:1px">' + _fmtCO2(totalAcv) + ' en ACV complet</div>';
    html += '</div></div>';

    // Sélecteur de périmètre
    html += '<div style="display:flex;align-items:center;gap:8px;margin-top:14px;padding-top:12px;border-top:1px solid rgba(255,255,255,.18);flex-wrap:wrap">';
    html += '<span style="font-size:11px;opacity:.75">Périmètre :</span>';
    METHODES.forEach(function(m) {
      var actif = (m.id === methode);
      html += '<button onclick="setMobiliteMethode(\'' + m.id + '\')" title="' + m.desc.replace(/"/g,'&quot;') + '" style="cursor:pointer;font-size:11px;font-weight:600;padding:4px 12px;border-radius:20px;border:1px solid rgba(255,255,255,' + (actif ? '.9' : '.3') + ');background:' + (actif ? 'rgba(255,255,255,.95)' : 'transparent') + ';color:' + (actif ? '#065f46' : 'rgba(255,255,255,.85)') + '">'
           + m.label + (m.source === 'derive' ? ' *' : '') + '</button>';
    });
    html += '</div>';
    html += '<div style="font-size:10px;opacity:.65;margin-top:8px;line-height:1.5">' + meth.desc
         + (meth.source === 'derive' ? ' <strong>*</strong> Périmètre dérivé par ratio du référentiel ADEME, pas une valeur publiée telle quelle.' : '')
         + '</div>';
    html += '</div>';

    // Report de millésime : le dire franchement, un calcul silencieusement
    // basé sur une autre année serait indéfendable dans un bilan.
    if (FACTEURS_SOURCE.origine === 'report_avant' && FACTEURS_SOURCE.anneeFacteurs) {
      html += '<div style="margin-bottom:16px;padding:10px 14px;background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;font-size:12px;color:#991b1b">';
      html += '⛔ Aucun millésime pour ' + annee + ' ni pour une année antérieure. Le calcul applique le millésime <strong>'
           + FACTEURS_SOURCE.anneeFacteurs + '</strong>, <strong>postérieur</strong> à l\'exercice : ce bilan n\'est pas défendable en l\'état. '
           + 'Un administrateur doit saisir les facteurs de ' + annee + ' dans la configuration serveur.';
      html += '</div>';
    } else if (FACTEURS_SOURCE.origine === 'report' && FACTEURS_SOURCE.anneeFacteurs) {
      html += '<div style="margin-bottom:16px;padding:10px 14px;background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;font-size:12px;color:#92400e">';
      html += '⚠️ Aucun facteur n\'a été figé pour ' + annee + '. Le calcul utilise le millésime <strong>'
           + FACTEURS_SOURCE.anneeFacteurs + '</strong>.';
      html += '</div>';
    } else if (FACTEURS_SOURCE.origine === 'local') {
      html += '<div style="margin-bottom:16px;padding:10px 14px;background:var(--gray-bg);border:1px solid var(--gray-border);border-radius:8px;font-size:12px;color:var(--gray-text)">';
      html += 'ℹ️ Calcul basé sur les valeurs de référence intégrées à Larka'
           + (FACTEURS_SOURCE.message ? ' — ' + FACTEURS_SOURCE.message : '') + '.';
      html += '</div>';
    }

    // Barre d'actions
    html += '<div style="display:flex;justify-content:flex-end;gap:8px;margin-bottom:16px">';
    html += FiltresEngine.renderButton('mobilite_carbone');
    html += '<button class="btn" onclick="refreshFacteursCarbone()" title="Recharger les facteurs d\'émission">🔄 Facteurs</button>';
    html += '<button class="btn btn-primary" onclick="editMobiliteLigne(0)">+ Ajouter un trajet</button>';
    html += '</div>';

    html += FiltresEngine.renderPanel('mobilite_carbone');

    if (!lignes.length) {
      html += '<div class="card" style="padding:48px;text-align:center;color:var(--gray-text)">';
      html += '<div style="font-size:36px;margin-bottom:12px">🌱</div>';
      html += '<div style="font-size:14px;font-weight:600;margin-bottom:6px">Aucun trajet renseigné pour ' + annee + '</div>';
      html += '<div style="font-size:12px">Ajoutez vos moyens de déplacement pour calculer votre bilan carbone.</div>';
      html += '</div>';
      c.innerHTML = html;
      return;
    }

    // Lignes
    var filteredLignes = lignes.filter(function(l) { return FiltresEngine.matchRow('mobilite_carbone', l); });
    html += '<div style="display:flex;flex-direction:column;gap:10px">';
    filteredLignes.forEach(function(l) {
      var t = _getTransport(l.Transport);
      var f = _getFreq(l.Frequence);
      var co2An = _co2Annuel(l, methode);
      var facActif = _facteurLigne(l, methode);
      var facAcv   = _facteurLigne(l, 'acv');
      var detail = METHODES.map(function(m) {
        return m.label + ' : ' + _fmtGramme(_facteurLigne(l, m.id));
      }).join(' · ');
      var dist = parseFloat(l.DistanceKm) || 0;
      var nb = parseFloat(l.NbFrequence) || 1;

      html += '<div class="card" style="padding:14px 18px">';
      html += '<div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">';
      html += '<div style="flex:1;min-width:180px">';
      html += '<div style="font-size:14px;font-weight:700;color:var(--navy)">' + t.label + '</div>';
      html += '<div style="font-size:12px;color:var(--gray-text);margin-top:2px">';
      html += dist + ' km × ' + nb + ' trajet(s) ' + f.label;
      // Le total annuel, explicite : c'est lui qu'on compare d'une année sur
      // l'autre, et il évite de refaire le calcul de tête.
      html += ' <strong style="color:var(--navy)">= ' + _fmtKm(_kmAnnuel(l))
           + '/an</strong>';
      html += ' <span style="display:inline-block;margin-left:6px;padding:1px 7px;border-radius:10px;background:var(--gray-bg);border:1px solid var(--gray-border);font-size:10px;font-weight:600">'
           + _anneeLigne(l) + '</span>';
      html += '</div>';
      if (l.Commentaire) html += '<div style="font-size:11px;color:var(--gray-text);margin-top:2px;font-style:italic">' + l.Commentaire + '</div>';
      html += '</div>';
      // Facteur du périmètre affiché, avec le détail des quatre en infobulle.
      html += '<div style="text-align:center;min-width:110px" title="' + detail.replace(/"/g,'&quot;') + '">';
      html += '<div style="font-size:11px;color:var(--gray-text)">' + meth.court + '</div>';
      html += '<div style="font-size:13px;font-weight:700;color:var(--text)">' + _fmtGramme(facActif) + '</div>';
      if (methode !== 'acv') html += '<div style="font-size:10px;color:var(--gray-text)">ACV : ' + _fmtGramme(facAcv) + '</div>';
      html += '</div>';
      // Total annuel
      html += '<div style="text-align:center;min-width:100px">';
      html += '<div style="font-size:11px;color:var(--gray-text)">CO₂e/an</div>';
      html += '<div style="font-size:16px;font-weight:700;color:' + (co2An > 500 ? '#dc2626' : co2An > 100 ? '#d97706' : '#16a34a') + '">' + _fmtCO2(co2An) + '</div>';
      html += '</div>';
      // Actions
      html += '<div style="display:flex;gap:6px">';
      html += '<button class="icon-btn" onclick="editMobiliteLigne(' + l.Id + ')" title="Modifier">✏️</button>';
      html += '<button class="icon-btn delete" onclick="deleteMobiliteLigne(' + l.Id + ')" title="Supprimer">🗑️</button>';
      html += '</div>';
      html += '</div></div>';
    });
    html += '</div>';

    // Répartition par catégorie
    html += '<div class="card" style="margin-top:16px;padding:18px 22px">';
    html += '<div style="font-size:14px;font-weight:700;color:var(--navy);margin-bottom:12px">📊 Répartition par catégorie <span style="font-size:11px;font-weight:500;color:var(--gray-text)">— bilan ' + annee + ', ' + meth.label + '</span></div>';
    var catColors = {'Individuel':'#ef4444','Mobilité douce':'#22c55e','Transport en commun':'#3b82f6','Aérien':'#f59e0b','Autre':'#6b7280'};
    Object.keys(byCat).forEach(function(cat) {
      var items = byCat[cat];
      var catTotal = items.reduce(function(s,l){ return s + _co2Annuel(l, methode); }, 0);
      var pct = totalAnnuel > 0 ? (catTotal / totalAnnuel * 100) : 0;
      var color = catColors[cat] || '#6b7280';
      html += '<div style="display:flex;align-items:center;gap:12px;margin-bottom:8px">';
      html += '<div style="width:140px;font-size:13px;font-weight:500">' + cat + '</div>';
      html += '<div style="flex:1;height:20px;background:var(--gray-bg);border-radius:10px;overflow:hidden">';
      html += '<div style="height:100%;width:' + Math.max(pct, 1) + '%;background:' + color + ';border-radius:10px;transition:width .3s"></div>';
      html += '</div>';
      html += '<div style="width:80px;text-align:right;font-size:13px;font-weight:700;color:' + color + '">' + _fmtCO2(catTotal) + '</div>';
      html += '<div style="width:50px;text-align:right;font-size:11px;color:var(--gray-text)">' + pct.toFixed(0) + '%</div>';
      html += '</div>';
    });
    html += '</div>';

    // Comparatif des quatre périmètres, en cascade décroissante
    html += '<div class="card" style="margin-top:16px;padding:18px 22px">';
    html += '<div style="font-size:14px;font-weight:700;color:var(--navy);margin-bottom:4px">⚖️ Les quatre périmètres — bilan ' + annee + '</div>';
    html += '<div style="font-size:11px;color:var(--gray-text);margin-bottom:14px">Les mêmes trajets, chaque périmètre étant contenu dans le précédent.</div>';
    var totaux = METHODES.map(function(m) {
      return { m: m, total: lignes.reduce(function(s,l){ return s + _co2Annuel(l, m.id); }, 0) };
    });
    var maxTotal = Math.max.apply(null, totaux.map(function(t){ return t.total; })) || 1;
    totaux.forEach(function(t) {
      var actif = (t.m.id === methode);
      var pct = (t.total / maxTotal) * 100;
      html += '<div style="margin-bottom:12px">';
      html += '<div style="display:flex;align-items:center;gap:10px;margin-bottom:3px">';
      html += '<div style="width:170px;font-size:12px;font-weight:' + (actif ? '700' : '500') + ';color:var(--navy)">'
           + t.m.label + (t.m.source === 'derive' ? ' <span style="color:var(--gray-text)">*</span>' : '') + '</div>';
      html += '<div style="flex:1;height:18px;background:var(--gray-bg);border-radius:9px;overflow:hidden">';
      html += '<div style="height:100%;width:' + Math.max(pct, 0.5) + '%;background:' + (actif ? '#047857' : '#94a3b8') + ';border-radius:9px;transition:width .3s"></div>';
      html += '</div>';
      html += '<div style="width:90px;text-align:right;font-size:13px;font-weight:700;color:' + (actif ? '#047857' : 'var(--navy)') + '">' + _fmtCO2(t.total) + '</div>';
      html += '</div>';
      html += '<div style="margin-left:180px;font-size:10px;color:var(--gray-text);line-height:1.4">' + t.m.desc + '</div>';
      html += '</div>';
    });
    html += '<div style="font-size:10px;color:var(--gray-text);margin-top:10px;padding-top:10px;border-top:1px solid var(--gray-border)">';
    html += '<strong>*</strong> Périmètres dérivés du référentiel ADEME par ratio (part de combustion dans le poste carburant, part du CO₂ dans les GES de combustion). ';
    html += 'Ce ne sont pas des valeurs publiées telles quelles : à valider avant tout usage réglementaire.';
    html += '</div>';
    html += '</div>';

    // Note de source
    var srcLibelle = (FACTEURS_SOURCE.origine === 'local')
      ? 'valeurs de référence intégrées à Larka (instantané du référentiel ADEME — Impact CO2)'
      : FACTEURS_SOURCE.libelle + (FACTEURS_SOURCE.maj ? ', relevé le ' + FACTEURS_SOURCE.maj : '')
        + (FACTEURS_SOURCE.origine === 'cache' ? ' (cache)' : '')
        + (FACTEURS_SOURCE.origine === 'cache_perime' ? ' (cache périmé — source momentanément injoignable)' : '');
    html += '<div style="margin-top:12px;padding:12px 16px;background:var(--gray-bg);border-radius:8px;border:1px solid var(--gray-border);font-size:11px;color:var(--gray-text);line-height:1.6">';
    html += '📋 <strong>Millésime :</strong> facteurs ' + (FACTEURS_SOURCE.anneeFacteurs || annee)
         + ', figés et non recalculés rétroactivement. <strong>Source :</strong> ' + srcLibelle + '. ';
    html += 'Quatre périmètres emboîtés : <strong>ACV complet</strong> (tout), <strong>hors construction</strong> (sans la fabrication du véhicule), ';
    html += '<strong>combustion</strong> (à bord seule, périmètre scope 1) et <strong>CO₂ seul</strong> (sans CH₄ ni N₂O). ';
    html += 'Estimations indicatives basées sur des moyennes nationales. Jours ouvrés/an : 228.';
    if (FACTEURS_SOURCE.message) html += '<br>⚠️ ' + FACTEURS_SOURCE.message;
    html += '</div>';

    c.innerHTML = html;
  } catch(e) { c.innerHTML = errorHtml(e.message); }
}

// ═══════════════════════════════════════════════════════════════════════════════
//  FORMULAIRE — Ajouter / Modifier un trajet
// ═══════════════════════════════════════════════════════════════════════════════
async function editMobiliteLigne(id) {
  var isNew = !id;
  var ligne = {};
  if (!isNew) {
    var all = await MobiliteApi.getAll();
    ligne = all.find(function(l){ return parseInt(l.Id) === parseInt(id); }) || {};
  }
  // Les facteurs proposés doivent être ceux de l'année du trajet, pas ceux de
  // l'année affichée : éditer un trajet 2024 ne doit pas y injecter du 2026.
  var anneeLigne = isNew ? MobiliteConfig.annee : _anneeLigne(ligne);
  await loadFacteursCarbone(anneeLigne);

  // Options transport groupées par catégorie
  var cats = {};
  TRANSPORTS_ADEME.forEach(function(t) {
    if (!cats[t.cat]) cats[t.cat] = [];
    cats[t.cat].push(t);
  });
  var optsTransport = '';
  Object.keys(cats).forEach(function(cat) {
    optsTransport += '<optgroup label="' + cat + '">';
    cats[cat].forEach(function(t) {
      var sel = (ligne.Transport === t.id) ? ' selected' : '';
      optsTransport += '<option value="' + t.id + '"' + sel
        + ' data-acv="' + t.co2Acv + '" data-hors="' + t.co2HorsConstr + '"'
        + ' data-comb="' + t.co2Combustion + '" data-seul="' + t.co2Seul + '">'
        + t.label + ' — ' + _fmtGramme(t.co2Acv) + ' ACV · ' + _fmtGramme(t.co2Combustion) + ' combustion</option>';
    });
    optsTransport += '</optgroup>';
  });

  var optsFreq = FREQUENCES.map(function(f) {
    var sel = ((ligne.Frequence || 'jour') === f.id) ? ' selected' : '';
    return '<option value="' + f.id + '"' + sel + '>' + f.label + '</option>';
  }).join('');

  var tRef = _getTransport(ligne.Transport || 'voiture_thermique');
  var currentAcv  = ligne.FacteurCO2           || tRef.co2Acv;
  var currentHors = ligne.FacteurCO2HorsConstr || tRef.co2HorsConstr;
  var currentComb = ligne.FacteurCO2Combustion || tRef.co2Combustion;
  var currentSeul = ligne.FacteurCO2Seul       || tRef.co2Seul;

  openModal(isNew ? '➕ Ajouter un trajet' : '✏️ Modifier le trajet', '\
    <div style="display:flex;flex-direction:column;gap:16px">\
      <div class="form-grid">\
        <div class="form-group span-2">\
          <label class="form-label">Moyen de transport <span class="req">*</span></label>\
          <select class="form-control" id="mob_transport" onchange="mobTransportChange()">' + optsTransport + '</select>\
        </div>\
        <div class="form-group">\
          <label class="form-label">Distance par trajet (km) <span class="req">*</span></label>\
          <input class="form-control" type="number" step="0.1" min="0" id="mob_distance" value="' + (ligne.DistanceKm || '') + '" placeholder="Ex: 15">\
          <div style="font-size:11px;color:var(--gray-text);margin-top:2px">Distance aller simple</div>\
        </div>\
        <div class="form-group">\
          <label class="form-label">Nombre de trajets</label>\
          <input class="form-control" type="number" step="1" min="1" id="mob_nb" value="' + (ligne.NbFrequence || 2) + '" placeholder="2">\
          <div style="font-size:11px;color:var(--gray-text);margin-top:2px">Ex: 2 (aller + retour)</div>\
        </div>\
        <div class="form-group">\
          <label class="form-label">Fréquence</label>\
          <select class="form-control" id="mob_freq" onchange="mobRecalcPreview()">' + optsFreq + '</select>\
        </div>\
        <div class="form-group">\
          <label class="form-label">Année du bilan <span class="req">*</span></label>\
          <input class="form-control" type="number" step="1" min="2000" max="2100" id="mob_annee" value="' + anneeLigne + '">\
          <div style="font-size:11px;color:var(--gray-text);margin-top:2px">Détermine le millésime de facteurs appliqué</div>\
        </div>\
        <div class="form-group">\
          <label class="form-label">Facteur ACV complet (kgCO₂e/km)</label>\
          <input class="form-control" type="number" step="0.001" min="0" id="mob_co2" value="' + currentAcv + '">\
          <div style="font-size:11px;color:var(--gray-text);margin-top:2px">Construction du véhicule incluse</div>\
        </div>\
        <div class="form-group">\
          <label class="form-label">Facteur hors construction (kgCO₂e/km)</label>\
          <input class="form-control" type="number" step="0.001" min="0" id="mob_co2_hors" value="' + currentHors + '">\
          <div style="font-size:11px;color:var(--gray-text);margin-top:2px">Sans la fabrication du véhicule</div>\
        </div>\
        <div class="form-group">\
          <label class="form-label">Facteur combustion (kgCO₂e/km)</label>\
          <input class="form-control" type="number" step="0.001" min="0" id="mob_co2_comb" value="' + currentComb + '">\
          <div style="font-size:11px;color:var(--gray-text);margin-top:2px">Scope 1 — nul si électrique</div>\
        </div>\
        <div class="form-group">\
          <label class="form-label">Facteur CO₂ seul (kgCO₂/km)</label>\
          <input class="form-control" type="number" step="0.001" min="0" id="mob_co2_seul" value="' + currentSeul + '">\
          <div style="font-size:11px;color:var(--gray-text);margin-top:2px">Sans CH₄ ni N₂O</div>\
        </div>\
        <div class="form-group span-2">\
          <label class="form-label">Commentaire (optionnel)</label>\
          <input class="form-control" id="mob_comment" value="' + ((ligne.Commentaire || '').replace(/"/g, '&quot;')) + '" placeholder="Ex: Trajet domicile-travail, déplacement chantier…">\
        </div>\
      </div>\
      <div id="mob_preview" style="padding:14px 16px;background:var(--gray-bg);border-radius:10px;border:1px solid var(--gray-border)"></div>\
    </div>', async function() {
      var transport = gv('mob_transport');
      var dist = parseFloat(gv('mob_distance')) || 0;
      var nb = parseFloat(gv('mob_nb')) || 1;
      var freq = gv('mob_freq');
      var co2 = parseFloat(gv('mob_co2')) || 0;
      var co2Hors = parseFloat(gv('mob_co2_hors')) || 0;
      var co2Comb = parseFloat(gv('mob_co2_comb')) || 0;
      var co2Seul = parseFloat(gv('mob_co2_seul')) || 0;
      var comment = gv('mob_comment');
      var anneeSaisie = parseInt(gv('mob_annee')) || anneeLigne;
      if (!dist) { toast('La distance est obligatoire.', 'error'); return; }
      if (anneeSaisie < 2000 || anneeSaisie > 2100) { toast('Année invalide.', 'error'); return; }
      var payload = {
        transport: transport,
        distanceKm: dist,
        nbFrequence: nb,
        frequence: freq,
        facteurCO2: co2,
        facteurCO2HorsConstr: co2Hors,
        facteurCO2Combustion: co2Comb,
        facteurCO2Seul: co2Seul,
        annee: anneeSaisie,
        commentaire: comment,
      };
      try {
        if (isNew) await MobiliteApi.create(payload);
        else       await MobiliteApi.update(id, payload);
        toast(isNew ? 'Trajet ajouté.' : 'Trajet modifié.', 'success');
        closeModal();
        // Sans ça, un trajet saisi pour une autre année disparaîtrait de l'écran.
        MobiliteConfig.annee = anneeSaisie;
        renderMobiliteCarbone();
      } catch(e) {
        console.error('[Mobilite] Erreur d\'enregistrement :', e.message, e);
        toast(e.message, 'error');
      }
  });

  setTimeout(function() {
    mobTransportChange();
    mobRecalcPreview();
    ['mob_distance','mob_nb','mob_co2','mob_co2_hors','mob_co2_comb','mob_co2_seul'].forEach(function(id) {
      var el = document.getElementById(id);
      if (el) el.addEventListener('input', mobRecalcPreview);
    });
  }, 100);
}

function mobTransportChange() {
  var sel = document.getElementById('mob_transport');
  if (!sel) return;
  var opt = sel.selectedOptions[0];
  var champs = [
    { el: document.getElementById('mob_co2'),      val: parseFloat(opt?.dataset?.acv  || 0) },
    { el: document.getElementById('mob_co2_hors'), val: parseFloat(opt?.dataset?.hors || 0) },
    { el: document.getElementById('mob_co2_comb'), val: parseFloat(opt?.dataset?.comb || 0) },
    { el: document.getElementById('mob_co2_seul'), val: parseFloat(opt?.dataset?.seul || 0) },
  ];
  champs.forEach(function(c) { if (c.el) c.el.value = c.val; });
  // Champs éditables uniquement pour « Personnalisé »
  var isCustom = sel.value === 'personnalise';
  champs.map(function(c){ return c.el; }).forEach(function(el) {
    if (!el) return;
    el.readOnly = !isCustom;
    el.style.opacity = isCustom ? '1' : '0.7';
  });
  mobRecalcPreview();
}

function mobRecalcPreview() {
  var prev = document.getElementById('mob_preview');
  if (!prev) return;
  var dist = parseFloat(gv('mob_distance')) || 0;
  var nb = parseFloat(gv('mob_nb')) || 1;
  var freq = _getFreq(gv('mob_freq'));
  var saisies = {
    acv:        parseFloat(gv('mob_co2'))      || 0,
    usage:      parseFloat(gv('mob_co2_hors')) || 0,
    combustion: parseFloat(gv('mob_co2_comb')) || 0,
    co2seul:    parseFloat(gv('mob_co2_seul')) || 0,
  };

  function bloc(co2, titre) {
    var parTrajet = co2 * dist;
    var parPeriode = parTrajet * nb;
    var annuel = parPeriode * freq.facAnnuel;
    var couleur = annuel > 500 ? '#dc2626' : annuel > 100 ? '#d97706' : '#16a34a';
    return '<div style="padding:10px 12px;border-radius:8px;background:var(--white,#fff);border:1px solid var(--gray-border)">' +
      '<div style="font-size:11px;font-weight:700;color:var(--navy);margin-bottom:8px">' + titre + '</div>' +
      '<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;text-align:center">' +
      '<div><div style="font-size:10px;color:var(--gray-text)">Par trajet</div><div style="font-size:14px;font-weight:700">' + (parTrajet*1000).toFixed(0) + ' g</div></div>' +
      '<div><div style="font-size:10px;color:var(--gray-text)">' + freq.label + '</div><div style="font-size:14px;font-weight:700">' + _fmtCO2(parPeriode) + '</div></div>' +
      '<div><div style="font-size:10px;color:var(--gray-text)">Total annuel</div><div style="font-size:17px;font-weight:800;color:' + couleur + '">' + _fmtCO2(annuel) + '</div></div>' +
      '</div></div>';
  }

  prev.innerHTML = '<div style="font-size:12px;font-weight:700;color:var(--navy);margin-bottom:10px">📊 Estimation par périmètre</div>' +
    '<div style="display:flex;flex-direction:column;gap:8px">' +
    METHODES.map(function(m) { return bloc(saisies[m.id], m.label + (m.source === 'derive' ? ' *' : '')); }).join('') +
    '</div>';
}

async function deleteMobiliteLigne(id) {
  showConfirm('Supprimer ce trajet ?', async function() {
    try {
      await MobiliteApi.delete(id);
      toast('Trajet supprimé.', 'success');
      renderMobiliteCarbone();
    } catch(e) { toast(e.message, 'error'); }
  });
}

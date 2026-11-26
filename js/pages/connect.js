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
 * Larka Connect — écrans de la section « Bâtiments »
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 *   renderConnectSupervision()  valeurs des équipements raccordés, courbes
 *   renderConnectAlarmes()      alarmes techniques, acquittement
 *   renderConnectConfig()       connexions, points, règles d'alarme, relevés
 *                               automatiques, bornes de recharge, journal
 *   _cxDetection()              auto-détection des équipements du réseau
 *
 * Toutes les listes se rangent par bâtiment (CxBat : repli, préférence).
 *
 * CE QUE CES ÉCRANS NE FONT PAS : interroger les équipements. La collecte est
 * faite par le serveur (tâche planifiée, minuterie systemd, start.sh, lanceur
 * Windows) ; l'écran relit la base. Ouvrir la supervision dans dix onglets ne
 * produit aucune requête de plus vers la GTB. Seuls « Tester », « Découvrir »,
 * « Explorer », « Lire maintenant », l'écriture d'une consigne et les commandes
 * de borne parlent à un équipement, et seulement quand on les demande.
 *
 * ÉCRITURE VERS UN ÉQUIPEMENT : le serveur exige une identité confirmée depuis
 * moins de X minutes, X réglé par le gestionnaire (HTTP 428 sinon).
 * _cxAvecIdentite() redemande alors le mot de passe — Larka pour un compte
 * local, Microsoft ou Google dans leur fenêtre — puis rejoue l'action.
 * ═══════════════════════════════════════════════════════════════════════════════
 */

const CxUI = {
  etat: null,          // réponse de connect_etat
  etatLu: 0,
  catalogue: {},       // protocole → description du pilote
  minuteur: null,      // rafraîchissement de la supervision (relecture de la base)
  sup: null,           // dernière réponse de connect_supervision
  bat: '',             // filtre bâtiment de la supervision
  recherche: '',
  periode: '24h',      // période des courbes (« perso » : plage ci-dessous)
  plage: null,         // { debut, fin } au format datetime-local
  courbe: null,        // courbe affichée : { point, mesures, plage } — pour changer de style sans relire
  onglet: 'connexions',
  filtrePa: '',        // filtre « connexion » de l'onglet Points
  rechPoints: '',
  alarmesEtat: 'active',
  alarmesGravite: '',
  alarmesRech: '',
  alarmes: [],
  passerelles: null,
  decouverte: null,
  detection: null,     // dernière auto-détection (réglages, équipements trouvés)
  cache: {},           // listes de référence (bâtiments, équipements, compteurs)
  apercuMinuteur: null,
  batiments: null,     // onglet Bâtiments : réseaux, mots-clés, état
  rangeables: [],      // connexions sans bâtiment dont le bâtiment se devine
};

const CX_PROTO_ICONE = { modbus: lk('hash'), bacnet: lk('thermometer'), mqtt: lk('wifi'), rest: lk('globe'), csv: lk('file-text'), ocpp: lk('plug'), lorawan: lk('satellite-dish'), wmbus: lk('droplet'), simulation: lk('flask-conical'), auto: lk('search') };

const CX_ETATS = {
  ok:      { libelle: 'Connectée',             classe: 'teal'   },
  partiel: { libelle: 'Partielle',             classe: 'orange' },
  erreur:  { libelle: 'En défaut',             classe: 'red'    },
  inconnu: { libelle: 'Pas encore interrogée', classe: 'gray'   },
};

const CX_NATURES = [
  ['mesure',   'Mesure'],
  ['compteur', 'Compteur (index)'],
  ['etat',     'État (marche, arrêt…)'],
  ['alarme',   'Défaut / alarme'],
  ['consigne', 'Consigne'],
];

const CX_GRAVITES = [
  ['info',     'Information', 'gray'],
  ['mineure',  'Mineure',     'blue'],
  ['majeure',  'Majeure',     'orange'],
  ['critique', 'Critique',    'red'],
];

const CX_OPERATEURS = [
  ['sup',        'Dépasse un seuil'],
  ['inf',        'Passe sous un seuil'],
  ['hors_plage', `Sort d'une plage`],
  ['egal',       'Prend une valeur, un état'],
  ['different',  'Quitte une valeur, un état'],
  ['qualite',    'Capteur en défaut (qualité mauvaise)'],
  ['absence',    'Plus aucune valeur reçue'],
  ['palier',     'Franchit un palier (ex. toutes les 2 000 h)'],
];

const CX_EXEC = {
  automatique: ['Écrit',      'teal'],
  rattrape:    ['Rattrapé',   'teal'],
  valide:      ['Validé',     'teal'],
  anomalie:    ['Anomalie',   'red'],
  a_valider:   ['À valider',  'orange'],
  manquant:    ['Manquant',   'orange'],
  rejete:      ['Rejeté',     'gray'],
  erreur:      ['Erreur',     'red'],
};

const CX_ETAPES = {
  reseau:    'Réseau et pare-feu',
  protocole: `Dialogue avec l'équipement`,
  droits:    `Droits d'accès`,
  points:    'Lecture des points',
  donnees:   'Données reçues',
};

const CX_ORIGINES = {
  regle:      'Règle',
  connexion:  'Communication',
  equipement: 'Équipement',
  liaison:    'Relevé automatique',
};

const CX_JOURS = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];
const CX_MOIS  = ['Janv.', 'Févr.', 'Mars', 'Avr.', 'Mai', 'Juin', 'Juil.', 'Août', 'Sept.', 'Oct.', 'Nov.', 'Déc.'];

/** Jours (lundi d'abord) et mois abrégés, dans la langue de l'interface. */
function _cxJoursCourts() {
  return _cxEn() ? [`Mon`, `Tue`, `Wed`, `Thu`, `Fri`, `Sat`, `Sun`] : CX_JOURS.map(j => j.slice(0, 3));
}
function _cxMoisCourts() {
  return _cxEn() ? [`Jan`, `Feb`, `Mar`, `Apr`, `May`, `Jun`, `Jul`, `Aug`, `Sep`, `Oct`, `Nov`, `Dec`] : CX_MOIS;
}
/** « le 1er », « le 2 »… — « 1st », « 2nd »… */
function _cxQuantieme(n) {
  if (!_cxEn()) return 'le ' + n + (n === 1 ? 'er' : '');
  const fin = (n % 100 >= 11 && n % 100 <= 13) ? 'th' : ({ 1: 'st', 2: 'nd', 3: 'rd' }[n % 10] || 'th');
  return `the ${n}${fin}`;
}

const CX_INTERVALLES = [
  [10, '10 secondes'], [30, '30 secondes'], [60, '1 minute'], [120, '2 minutes'], [300, '5 minutes'],
  [600, '10 minutes'], [900, '15 minutes'], [1800, '30 minutes'], [3600, '1 heure'], [21600, '6 heures'], [86400, '1 jour'],
];

const CX_ONGLETS = [
  ['batiments',  (lk('building-complex') + ' Bâtiments')],
  ['connexions', (lk('plug') + ' Connexions')],
  ['points',     (lk('map-pin') + ' Points')],
  ['regles',     `${lk('bell')} Règles d'alarme`],
  ['releves',    (lk('calendar') + ' Relevés automatiques')],
  ['bornes',     (lk('car') + ' Bornes de recharge')],
  ['hotspots',   (lk('wifi') + ' Hors réseau')],
  ['journal',    (lk('receipt') + ' Journal')],
];

// ══ Outils ═════════════════════════════════════════════════════════════════

/**
 * LANGUE. Les libellés fixes sont traduits par le pack de langue (nœud de
 * texte par nœud de texte, à l'identique : lang/traductions.json). Une phrase ASSEMBLÉE
 * — durée, date relative, compte, titre portant un nom — ne peut pas l'être :
 * elle est composée ici dans la langue de l'interface. Les gabarits `…` ne
 * sont pas récoltés comme libellés : l'anglais n'encombre pas le catalogue.
 */
let _cxLangue = { en: false, lu: 0 };
function _cxEn() {
  // Relue au plus toutes les 2 s : un rendu appelle cette fonction des centaines de fois.
  const t = Date.now();
  if (t - _cxLangue.lu > 2000) {
    let en = false;
    try { en = ((typeof uiPrefsGet === 'function' ? uiPrefsGet() : JSON.parse(localStorage.getItem('gmao_ui_prefs')) || {}).langue === 'en'); }
    catch (_) { en = false; }
    _cxLangue = { en, lu: t };
  }
  return _cxLangue.en;
}
function _cxT(fr, en) { return _cxEn() ? en : fr; }
/** Paramètre de langue pour les routes qui composent un libellé (calendriers, conditions des règles). */
function _cxLg() { return _cxEn() ? '&langue=en' : ''; }
/**
 * Libellé fixe venu d'ailleurs (catalogue du serveur) qu'il faut glisser dans
 * une phrase assemblée : traduit par le pack de langue, comme à l'écran.
 */
function _cxTr(texte) {
  if (!texte || !_cxEn() || typeof LarkaExtensions === 'undefined' || typeof LarkaExtensions.appliquerTraductions !== 'function') return texte;
  try {
    const s = document.createElement('span');
    s.textContent = texte;
    LarkaExtensions.appliquerTraductions(s);
    return s.textContent;
  } catch (_) { return texte; }
}
/** Nombre et nom accordé : _cxN(3, `point`, `points`) — 0 et 1 au singulier en français, seul 1 en anglais. */
function _cxN(n, un, plusieurs) {
  const pluriel = _cxEn() ? Math.abs(n) !== 1 : Math.abs(n) >= 2;
  return n + ' ' + (pluriel ? plusieurs : un);
}

/**
 * Requête longue (test, découverte, écriture…) : l'appel courant abandonne au
 * bout de 20 s, alors qu'un équipement derrière un pare-feu qui jette les
 * paquets ne répond qu'au bout de son délai, plusieurs fois de suite.
 */
async function _cxApi(action, method = 'GET', body = null, delaiMs = 60000) {
  const ctl = typeof AbortController !== 'undefined' ? new AbortController() : null;
  const minuteur = ctl ? setTimeout(() => ctl.abort(), delaiMs) : null;
  let res, texte = '';
  try {
    const opts = { method, headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', cache: 'no-store' };
    if (ctl) opts.signal = ctl.signal;
    if (body) opts.body = JSON.stringify(body);
    res = await fetch(`${API_BASE}?action=${action}`, opts);
    texte = await res.text();
  } catch (e) {
    const s = Math.round(delaiMs / 1000);
    throw new Error(e && e.name === 'AbortError'
      ? _cxT(`Pas de réponse au bout de ${s} s.`, `No response after ${s} s.`)
      : 'Impossible de joindre le serveur.');
  } finally {
    if (minuteur) clearTimeout(minuteur);
  }
  let json = null;
  try { json = texte ? JSON.parse(texte) : null; } catch (_) { json = null; }
  if (!json || typeof json !== 'object') throw new Error('Réponse du serveur illisible.');
  if (!json.success) {
    if (res.status === 401 && typeof App !== 'undefined' && App.currentUser) App.forceLogout();
    const err = new Error(json.error || _cxT(`Erreur HTTP ${res.status}`, `HTTP error ${res.status}`));
    err._status = res.status;
    throw err;
  }
  return json.data;
}

function _cxNombre(v, dec = 2) {
  if (v === null || v === undefined || v === '' || isNaN(Number(v))) return '—';
  const d = Math.max(0, Math.min(6, parseInt(dec, 10) || 0));
  return Number(v).toLocaleString('fr-FR', { maximumFractionDigits: d, minimumFractionDigits: 0 });
}

function _cxDateObj(s) {
  if (!s) return null;
  const d = new Date(String(s).replace(' ', 'T'));
  return isNaN(d.getTime()) ? null : d;
}

function _cxDate(s, avecHeure = true) {
  const d = _cxDateObj(s);
  if (!d) return s ? escHtml(String(s)) : '—';
  return d.toLocaleString('fr-FR', avecHeure
    ? { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }
    : { day: '2-digit', month: '2-digit', year: 'numeric' });
}

function _cxDuree(sec) {
  sec = Math.abs(Math.round(sec));
  if (sec < 90) return sec + ' s';
  const min = Math.round(sec / 60);
  if (min < 90) return min + ' min';
  const h = Math.round(min / 60);
  if (h < 36) return h + ' h';
  return Math.round(h / 24) + _cxT(` j`, ` d`);
}

/** « il y a 5 min », « à l'instant », « dans 2 h » — ou leur équivalent anglais. */
function _cxDepuis(s) {
  const d = _cxDateObj(s);
  if (!d) return _cxT(`jamais`, `never`);
  const sec = Math.round((Date.now() - d.getTime()) / 1000);
  if (sec < -30) return _cxT(`dans `, `in `) + _cxDuree(sec);
  if (sec < 45) return _cxT(`à l'instant`, `just now`);
  return _cxT(`il y a ${_cxDuree(sec)}`, `${_cxDuree(sec)} ago`);
}

/** Durée en toutes lettres : « 10 secondes », « 1 heure », « 6 hours »… */
function _cxDureeLongue(sec) {
  sec = +sec || 0;
  const en = _cxEn();
  if (sec % 86400 === 0) return _cxN(sec / 86400, en ? `day` : `jour`, en ? `days` : `jours`);
  if (sec % 3600 === 0) return _cxN(sec / 3600, en ? `hour` : `heure`, en ? `hours` : `heures`);
  if (sec % 60 === 0) return _cxN(sec / 60, `minute`, `minutes`);
  return _cxN(sec, en ? `second` : `seconde`, en ? `seconds` : `secondes`);
}

/** « chaque minute », « toutes les 5 minutes », « every 5 minutes »… */
function _cxRythme(sec) {
  sec = +sec || 0;
  if (!sec) return '';
  const unique = { 60: [`chaque minute`, `every minute`], 3600: [`chaque heure`, `every hour`], 86400: [`chaque jour`, `every day`] }[sec];
  if (unique) return _cxT(unique[0], unique[1]);
  return _cxT(`toutes les `, `every `) + _cxDureeLongue(sec);
}

function _cxErreurHtml(err) {
  return `<div class="card cx-ferme"><div class="cx-ferme-icone">${lk('triangle-exclamation-point', 'warn')}</div><p>${escHtml((err && err.message) || String(err))}</p>
    <button class="btn btn-ghost btn-sm" onclick="navigate(App.currentPage)">Réessayer</button></div>`;
}

function _cxGravite(g) {
  const x = CX_GRAVITES.find(v => v[0] === g) || CX_GRAVITES[2];
  return `<span class="badge badge-${x[2]}">${x[1]}</span>`;
}

function _cxOptions(liste, courant) {
  return liste.map(([v, l]) => `<option value="${escHtml(v)}"${String(v) === String(courant ?? '') ? ' selected' : ''}>${escHtml(l)}</option>`).join('');
}

function _cxCopier(id) {
  const el = document.getElementById(id);
  if (!el) return;
  const texte = el.value || el.textContent || '';
  const fini = () => toast('Copié dans le presse-papiers.', 'success');
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(texte).then(fini, () => { el.select && el.select(); document.execCommand('copy'); fini(); });
  } else {
    if (el.select) el.select();
    try { document.execCommand('copy'); fini(); } catch (_) { toast('Copie impossible : sélectionnez le texte à la main.', 'error'); }
  }
}

/** Listes de référence, gardées cinq minutes. */
async function _cxRef(nom) {
  const c = CxUI.cache[nom];
  if (c && Date.now() - c.lu < 300000) return c.donnees;
  const action = { batiments: 'plans_batiments', equipements: 'equipements', compteurs: 'energie_compteurs' }[nom];
  let donnees = [];
  try { donnees = await apiRequest(action) || []; } catch (_) { donnees = []; }
  CxUI.cache[nom] = { lu: Date.now(), donnees };
  return donnees;
}

async function _cxEtat(forcer = false) {
  if (!forcer && CxUI.etat && Date.now() - CxUI.etatLu < 60000) return CxUI.etat;
  const e = await apiRequest('connect_etat');
  CxUI.etat = e;
  CxUI.etatLu = Date.now();
  CxUI.catalogue = {};
  (e.catalogue || []).forEach(d => { CxUI.catalogue[d.protocole] = d; });
  return e;
}

/** Module fermé : l'écran dit pourquoi, et comment l'ouvrir. */
function _cxModuleFerme(c, e) {
  if (e.actif_serveur && e.actif_organisation) return false;
  const gestion = canEdit();
  let msg;
  if (!e.actif_serveur) {
    msg = (e.peut_activer
          ? `<p><button class="btn btn-primary" onclick="_cxActiverModule(this)">${_cxT(`▶ Activer Larka Connect`, `▶ Enable Larka Connect`)}</button></p>`
          : '')
      + _cxT(`<p>Larka Connect relie Larka aux équipements techniques du bâtiment : GTB/GTC, compteurs, capteurs, bornes de recharge. Il est fermé sur ce serveur.</p>`,
               `<p>Larka Connect links Larka to the building's technical equipment: BMS, meters,
sensors, EV chargers. It is closed on this server.</p>`)
        + (gestion
          ? _cxT(`<p class="cx-aide">Pour l'ouvrir : <strong>Configuration → Serveur → Larka Connect</strong> (en multi-organisation, c'est le Super Administrateur qui le fait), ou la clé <code>connect.actif</code> de <code>config.json</code>.</p>`,
                 `<p class="cx-aide">To open
it: <strong>Settings → Server →
Larka Connect</strong> (with several organisations, the Super Administrator does it), or the <code>connect.actif</code> key
in <code>config.json</code>.</p>`)
          : `<p class="cx-aide">Demandez à un gestionnaire de l'ouvrir.</p>`);
  } else {
    msg = `<p>Larka Connect est fermé pour votre organisation.</p><p class="cx-aide">Le Super Administrateur peut l'ouvrir : organisation → modules.</p>`;
  }
  // La supervision peut aussi vivre sur un serveur séparé : ce serveur-ci n'a alors pas à être ouvert au réseau technique.
  if (gestion && e.actif_organisation !== false) {
    msg += `<p class="cx-aide">${_cxT(`Ou bien : <strong>serveur de supervision séparé</strong>, sur le réseau technique, relié à celui-ci par un lien chiffré.`, `Or: a <strong>separate supervision server</strong>, linked by an encrypted channel.`)}</p>
      <p><button class="btn btn-ghost" onclick="_cxServeurOuvrir()">${lk('server')} ${_cxT(`Serveur de supervision…`, `Supervision server…`)}</button></p>`;
  }
  c.innerHTML = `<div class="card cx-ferme"><div class="cx-ferme-icone">${lk('satellite-dish')}</div><h3>Larka Connect n'est pas ouvert</h3>${msg}</div>`;
  return true;
}

/** Installation simple : un gestionnaire ouvre le module (config.json « connect.actif »), puis le menu se redessine. */
async function _cxActiverModule(btn) {
  if (btn) { btn.disabled = true; btn.textContent = ''; }
  try {
    await apiRequest('config_serveur', 'POST', [{ section: 'connect', cle: 'actif', valeur: true }]);
  } catch (err) {
    toast(err.message, 'error');
    if (btn) { btn.disabled = false; btn.textContent = _cxT(`▶ Activer Larka Connect`, `▶ Enable Larka Connect`); }
    return;
  }
  CxUI.etatLu = 0;
  toast(_cxT(`Larka Connect est activé.`, `Larka Connect is enabled.`), 'success');
  try { if (typeof buildSidebarWithVisibility === 'function') await buildSidebarWithVisibility(App.currentUser); } catch (_) { /* le menu suivra au prochain chargement */ }
  navigate(App.currentPage);
}

function _cxBandeau(e) {
  const l = [];
  const sd = e.supervision_distante;
  if (sd) {
    l.push(`<span style="color:var(--gray-text)">${lk('server')} ${_cxT(`Supervision servie par le <strong>serveur de supervision</strong>`, `Served by the <strong>supervision server</strong>`)}
      (${sd.mode === 'pont' ? _cxT(`pont sortant`, `bridge`) : _cxT(`port ouvert`, `open port`)}, ${_cxT(`lien chiffré`, `encrypted link`)})${sd.dernier_contact ? ` — ${_cxT(`dernier échange`, `last exchange`)} ${escHtml(_cxDate(sd.dernier_contact))}` : ''}.
      ${_cxT(`Les commandes indiquées ci-dessous se lancent sur le serveur de supervision.`, `Commands below run on the supervision server.`)}</span>`);
  }
  if (!e.planificateur_actif) {
    const dp = e.dernier_passage ? _cxDate(e.dernier_passage) : '';
    l.push(_cxT(`${lk('triangle-exclamation-point', 'warn')} <strong>La collecte automatique ne passe pas</strong>${dp ? ` (dernier passage : ${dp})` : ''} : les valeurs ne se mettent plus à jour. `
              + `Elle est lancée par le serveur — tâche planifiée en production, minuterie systemd, <code>./start.sh</code> ou <code>Larka.cmd</code>. `
              + `Vérifier : <code>php api/outils/connect-collecte.php --etat</code>`,
                `${lk('triangle-exclamation-point', 'warn')} <strong>Automatic collection
is not running</strong>${dp ? ` (last run: ${dp})` : ''}: values are no longer updated. `
              + `The server starts it — scheduled task in production, systemd timer, <code>./start.sh</code> or <code>Larka.cmd</code>. `
              + `Check: <code>php api/outils/connect-collecte.php --etat</code>`));
  }
  if (e.ocpp && e.ocpp.actif && !e.ocpp.en_marche) {
    l.push(_cxT(`${lk('triangle-exclamation-point', 'warn')} <strong>Le service des bornes de recharge (OCPP) ne tourne pas</strong> : les bornes ne peuvent pas se connecter. `
              + `Vérifier : <code>php api/outils/connect-ocpp.php --etat</code>`,
                `${lk('triangle-exclamation-point', 'warn')} <strong>The EV charger service
(OCPP) is not running</strong>: chargers cannot connect. `
              + `Check: <code>php api/outils/connect-ocpp.php --etat</code>`));
  }
  if (e.lorawan && e.lorawan.actif && !e.lorawan.en_marche) {
    l.push(_cxT(`${lk('triangle-exclamation-point', 'warn')} <strong>Le serveur LoRaWAN intégré ne tourne pas</strong> : les trames des capteurs radio sont perdues. `
              + `Vérifier : <code>php api/outils/connect-ocpp.php --etat</code>`,
                `${lk('triangle-exclamation-point', 'warn')} <strong>The built-in LoRaWAN server is not running</strong>: radio sensor frames are lost. `
              + `Check: <code>php api/outils/connect-ocpp.php --etat</code>`));
  }
  return l.map(x => `<div class="cx-bandeau">${x}</div>`).join('');
}

function _cxArreterMinuteur() {
  if (CxUI.minuteur) { clearInterval(CxUI.minuteur); CxUI.minuteur = null; }
}

// ══ Regroupement par bâtiment ══════════════════════════════════════════════
//
// Supervision, alarmes et chaque onglet de la configuration rangent leurs
// lignes (ou leurs cartes) sous le bâtiment de la connexion, « Sans bâtiment »
// en dernier. Un bâtiment se replie d'un clic sur son titre ; le choix
// (regrouper ou non, bâtiments repliés) est gardé par navigateur.

const CX_SANS_BAT = '__sans';
const CxBat = {
  lu: false,
  actif: true,
  replies: new Set(),
  charger() {
    if (this.lu) return;
    this.lu = true;
    try {
      const p = JSON.parse(localStorage.getItem('cx_batiments') || '{}') || {};
      this.actif = p.actif !== false;
      this.replies = new Set(Array.isArray(p.replies) ? p.replies.map(String) : []);
    } catch (_) { /* stockage indisponible : réglages par défaut */ }
  },
  garder() {
    try { localStorage.setItem('cx_batiments', JSON.stringify({ actif: this.actif, replies: [...this.replies].slice(0, 200) })); } catch (_) { /* rien */ }
  },
};

function _cxGrouperActif() { CxBat.charger(); return CxBat.actif; }
function _cxCleBat(nom) { return nom ? 'b:' + nom : CX_SANS_BAT; }
function _cxReplie(cle) { CxBat.charger(); return CxBat.replies.has(cle); }

/** Groupes d'une liste, par nom de bâtiment (ordre alphabétique, « Sans bâtiment » à la fin) ; l'ordre interne est gardé. */
function _cxGroupes(liste, nomDe) {
  const parCle = new Map();
  (liste || []).forEach(x => {
    const nom = (nomDe(x) || '').trim();
    const cle = _cxCleBat(nom);
    if (!parCle.has(cle)) parCle.set(cle, { cle, nom, items: [] });
    parCle.get(cle).items.push(x);
  });
  return [...parCle.values()].sort((a, b) => (a.cle === CX_SANS_BAT) - (b.cle === CX_SANS_BAT) || a.nom.localeCompare(b.nom, 'fr'));
}

/** Regrouper vaut-il la peine ? (Activé, et au moins deux groupes.) */
function _cxGroupesUtiles(groupes) { return _cxGrouperActif() && groupes.length > 1; }

function _cxNomGroupe(g) { return g.cle === CX_SANS_BAT ? _cxT(`Sans bâtiment`, `No building`) : g.nom; }

/** Attributs d'un élément rangé sous un bâtiment (masqué si le bâtiment est replié). */
function _cxAttrDans(cle) { return ` data-cx-dans="${escHtml(cle)}"${_cxReplie(cle) ? ' hidden' : ''}`; }

/** Glisse les attributs « dans le groupe » dans la première balise d'un fragment HTML. */
function _cxDansGroupe(html, cle) { return String(html).replace(/^(\s*<[a-zA-Z]+)/, `$1${_cxAttrDans(cle)}`); }

/** Bouton-titre d'un bâtiment. */
function _cxTitreGroupe(g, resume) {
  const ouvert = !_cxReplie(g.cle);
  return `<button type="button" class="cx-groupe-btn" aria-expanded="${ouvert}" data-cx-groupe="${escHtml(g.cle)}" onclick="_cxBasculerGroupe(this.dataset.cxGroupe)">
      <span class="cx-groupe-fleche" aria-hidden="true">▸</span><span aria-hidden="true">${g.cle === CX_SANS_BAT ? lk('package') : lk('building-complex')}</span>
      <span class="cx-groupe-nom">${escHtml(_cxNomGroupe(g))}</span>
      <span class="cx-groupe-compte">${g.items.length}</span>${resume ? `<span class="cx-groupe-resume">${resume}</span>` : ''}</button>`;
}

/**
 * Lignes d'un tableau, rangées par bâtiment.
 * @param ligne   (élément) → '<tr …>…</tr>'
 * @param resume  (éléments du groupe) → html court à côté du titre (facultatif)
 */
function _cxLignesParBatiment(liste, nomDe, colonnes, ligne, resume) {
  const groupes = _cxGroupes(liste, nomDe);
  if (!_cxGroupesUtiles(groupes)) return (liste || []).map(ligne).join('');
  return groupes.map(g => `<tr class="cx-groupe-ligne"><td colspan="${colonnes}">${_cxTitreGroupe(g, resume ? resume(g.items) : '')}</td></tr>`
    + g.items.map(x => _cxDansGroupe(ligne(x), g.cle)).join('')).join('');
}

/** Cartes d'une grille, rangées par bâtiment (le titre occupe toute la largeur). */
function _cxCartesParBatiment(liste, nomDe, carte, resume) {
  const groupes = _cxGroupes(liste, nomDe);
  if (!_cxGroupesUtiles(groupes)) return (liste || []).map(carte).filter(Boolean).join('');
  return groupes.map(g => {
    const cartes = g.items.map(carte).filter(Boolean);
    if (!cartes.length) return '';
    return `<div class="cx-groupe-titre">${_cxTitreGroupe({ ...g, items: cartes }, resume ? resume(g.items) : '')}</div>`
      + cartes.map(c => _cxDansGroupe(c, g.cle)).join('');
  }).join('');
}

function _cxBasculerGroupe(cle) {
  CxBat.charger();
  const replier = !CxBat.replies.has(cle);
  if (replier) CxBat.replies.add(cle); else CxBat.replies.delete(cle);
  CxBat.garder();
  document.querySelectorAll('[data-cx-dans]').forEach(el => { if (el.dataset.cxDans === cle) el.hidden = replier; });
  document.querySelectorAll('.cx-groupe-btn').forEach(b => { if (b.dataset.cxGroupe === cle) b.setAttribute('aria-expanded', String(!replier)); });
}

/** Tout replier (ou tout déplier) : les bâtiments affichés à l'écran. */
function _cxToutReplier(replier) {
  CxBat.charger();
  document.querySelectorAll('.cx-groupe-btn').forEach(b => {
    const cle = b.dataset.cxGroupe;
    if (replier) CxBat.replies.add(cle); else CxBat.replies.delete(cle);
    b.setAttribute('aria-expanded', String(!replier));
  });
  document.querySelectorAll('[data-cx-dans]').forEach(el => { el.hidden = replier; });
  CxBat.garder();
}

/** Interrupteur « par bâtiment » et « tout replier / déplier », pour les barres de filtres. */
function _cxBoutonsGrouper() {
  const on = _cxGrouperActif();
  return `<span class="cx-grouper">
      <button type="button" class="btn btn-ghost btn-sm${on ? ' cx-grouper-on' : ''}" aria-pressed="${on}" onclick="_cxGrouper(${!on})"
        title="${escHtml(_cxT(`Ranger les lignes sous leur bâtiment`, `Group rows under their building`))}">${lk('building-complex')} ${_cxT(`Par bâtiment`, `By building`)}</button>
      ${on ? `<button type="button" class="icon-btn" title="${escHtml(_cxT(`Tout replier`, `Collapse all`))}" aria-label="${escHtml(_cxT(`Tout replier`, `Collapse all`))}" onclick="_cxToutReplier(true)">⊟</button>
              <button type="button" class="icon-btn" title="${escHtml(_cxT(`Tout déplier`, `Expand all`))}" aria-label="${escHtml(_cxT(`Tout déplier`, `Expand all`))}" onclick="_cxToutReplier(false)">⊞</button>` : ''}
    </span>`;
}

function _cxGrouper(actif) {
  CxBat.charger();
  CxBat.actif = !!actif;
  CxBat.garder();
  document.querySelectorAll('.cx-grouper').forEach(el => { el.outerHTML = _cxBoutonsGrouper(); });
  if (App.currentPage === 'connect') _cxPeindreSupervision();
  else if (App.currentPage === 'connect_alarmes') _cxPeindreAlarmes();
  else if (App.currentPage === 'connect_config') _cxOnglet(CxUI.onglet);
}

/** Petit résumé d'un groupe de connexions : en défaut, alarmes. */
function _cxResumeConnexions(pas) {
  const ko = pas.filter(p => +p.Actif && p.Etat === 'erreur').length;
  const al = pas.reduce((n, p) => n + (+p.NbAlarmes || 0), 0);
  return (ko ? `<span class="badge badge-red">✕ ${ko}</span>` : '') + (al ? ` <span class="badge badge-red">${lk('bell')} ${al}</span>` : '');
}

// ══ Valeurs ════════════════════════════════════════════════════════════════

function _cxValeurHtml(p) {
  const v = p.DerniereValeur, t = p.DerniereValeurTexte;
  const vide = (v === null || v === undefined || v === '') && (t === null || t === undefined || t === '');
  if (vide) return `<span class="cx-val-vide">${p.DerniereErreur ? _cxT(`illisible`, `unreadable`) : '—'}</span>`;
  if ((p.Nature === 'etat' || p.Nature === 'alarme') && t) {
    return p.Nature === 'alarme' && Number(v) ? `<span class="cx-val-defaut">${lk('triangle-exclamation-point', 'warn')} ${escHtml(t)}</span>` : escHtml(t);
  }
  if (p.Nature === 'alarme' && v !== null && v !== '') {
    return Number(v) ? ('<span class="cx-val-defaut">' + lk('triangle-exclamation-point', 'warn') + ' En défaut</span>') : 'Normal';
  }
  if (v === null || v === undefined || v === '') return escHtml(t);
  return _cxNombre(v, p.Decimales ?? 2) + (p.Unite ? ` <small>${escHtml(p.Unite)}</small>` : '');
}

function _cxQualite(q, erreur) {
  // Infobulle assemblée (libellé + message du serveur) : composée dans la langue.
  const suite = erreur ? _cxT(` : `, `: `) + erreur : '';
  if (q === 'mauvaise') {
    return `<span class="cx-q cx-q-mauvaise" title="${escHtml(_cxT(`Qualité mauvaise`, `Bad quality`) + suite)}" aria-label="Qualité mauvaise">✕</span>`;
  }
  if (q === 'douteuse') {
    return `<span class="cx-q cx-q-douteuse" title="${escHtml(_cxT(`Qualité douteuse`, `Uncertain quality`) + suite)}" aria-label="Qualité douteuse">!</span>`;
  }
  return '';
}

/** Valeur trop ancienne pour être prise pour l'état présent ? */
function _cxPerime(p, pa) {
  if (!p.DerniereLecture) return false;
  const d = _cxDateObj(p.DerniereLecture);
  if (!d) return false;
  const intervalle = pa && +pa.IntervalleSecondes ? +pa.IntervalleSecondes : 60;
  const limite = Math.max(3 * intervalle, pa && pa.Protocole !== 'modbus' && pa.Protocole !== 'bacnet' ? 3600 : 900);
  return (Date.now() - d.getTime()) / 1000 > limite;
}

function _cxAdresseResume(proto, a) {
  a = a || {};
  switch (proto) {
    case 'modbus': {
      const tables = _cxEn() ? { holding: `Holding`, input: `Input`, coil: `Coil`, discrete: `Discrete` }
                             : { holding: `Maintien`, input: `Entrée`, coil: `Bobine`, discrete: `TOR` };
      return `${tables[a.table] || a.table || tables.holding} ${a.registre ?? '?'} · ${a.type || 'uint16'}${a.ordre ? ' ' + a.ordre : ''}${a.unite ? ' · unit ' + a.unite : ''}${a.bit !== null && a.bit !== undefined && a.bit !== '' ? ' · bit ' + a.bit : ''}`;
    }
    case 'bacnet':
      return `${a.objet || '?'},${a.instance ?? '?'}${a.propriete && a.propriete !== 'present-value' ? ' · ' + a.propriete : ''}`;
    case 'mqtt':
      return `${a.sujet || '?'}${a.chemin ? ' → ' + a.chemin : ''}`;
    case 'rest':
      return `${a.id || '?'}${a.chemin ? ' → ' + a.chemin : ''}`;
    case 'csv':
      return a.repere || a.colonne || '?';
    case 'simulation':
      return _cxProfilSimulation(a.profil);
    default:
      return '—';
  }
}

/** Grandeur simulée (connexion « Simulateur intégré »), d'après la description du pilote. */
function _cxProfilSimulation(profil) {
  const d = (typeof CxUI !== 'undefined' && CxUI.catalogue && CxUI.catalogue.simulation) || null;
  const c = d && (d.adresse || []).find(x => x.cle === 'profil');
  const o = c && (c.options || []).find(x => (Array.isArray(x) ? x[0] : x) === profil);
  return o ? (Array.isArray(o) ? o[1] : o) : (profil || '?');
}

/** La connexion passe-t-elle par le hotspot Wi-Fi de l'appareil (relevée par un PC releveur) ? */
function _cxEnHotspot(pa) { return !!pa && (+pa.Hotspot === 1 || ['hotspot', 'direct'].includes((pa.Parametres || {}).acces) || ['hotspot', 'direct'].includes(pa.Acces)); }
/** Hors réseau par câble direct (et non par hotspot) ? */
function _cxEnDirect(pa) { return !!pa && ((pa.Parametres || {}).acces === 'direct' || pa.Acces === 'direct'); }

function _cxAdresseConnexion(pa) {
  const prm = pa.Parametres || {};
  if (_cxEnHotspot(pa)) return (_cxEnDirect(pa) ? '' + _cxT(`câble direct`, `direct cable`) : '' + (prm.wifi_ssid || pa.Ssid || 'hotspot'))
    + ' → ' + (pa.Protocole === 'rest' ? (prm.url_base || pa.UrlBase || '—') : (pa.Hote || '—') + (pa.Port ? ':' + pa.Port : ''));
  switch (pa.Protocole) {
    case 'rest': return prm.url_base || '—';
    case 'csv':  return prm.dossier || '—';
    case 'ocpp': return pa.Identifiant || '—';
    case 'lorawan': return 'DevEUI ' + (pa.Identifiant || '—');
    case 'simulation': return _cxT(`simulateur intégré`, `built-in simulator`);
    default:     return (pa.Hote || '—') + (pa.Port ? ':' + pa.Port : '');
  }
}

// ══ Supervision ════════════════════════════════════════════════════════════

// renderConnectSupervision, son chargement et son rendu (modes simple et avancé,
// filtres, consommations du mois, import Énergie, export) : js/pages/connect-supervision.js.

/** État affiché d'une connexion (« en attente de données » : interrogée, rien reçu encore). */
function _cxEtatConnexion(pa) {
  if (!+pa.Actif) return { libelle: 'Désactivée', classe: 'gray' };
  if ((pa.Etat || 'inconnu') === 'inconnu' && (pa.DerniereCollecte || pa.DernierContact)) return { libelle: 'En attente de données', classe: 'gray' };
  return CX_ETATS[pa.Etat] || CX_ETATS.inconnu;
}

function _cxCarte(pa, points) {
  const desc = CxUI.catalogue[pa.Protocole] || {};
  const et = _cxEtatConnexion(pa);
  const gestion = canEdit();
  const ocpp = pa.Protocole === 'ocpp';
  // « Entrant » : l'appareil envoie de lui-même (borne OCPP, capteur LoRaWAN) ; personne ne l'interroge.
  const entrant = desc.collecte === 'entrant';
  const hotspot = _cxEnHotspot(pa);
  const meta = entrant
    ? (pa.DernierContact ? (ocpp ? _cxT(`Dernier message `, `Last message `) : _cxT(`Dernière trame `, `Last frame `)) + _cxDepuis(pa.DernierContact)
                         : (ocpp ? 'Jamais connectée' : _cxT(`Aucune trame reçue`, `No frame received`)))
    : (hotspot ? (lk('wifi') + ' ') + (pa.DerniereCollecte ? _cxT(`Relevée par un PC `, `Read by a PC `) + _cxDepuis(pa.DerniereCollecte) : _cxT(`Pas encore relevée par un PC releveur`, `Not read by a reader PC yet`))
    : (pa.DerniereCollecte ? _cxT(`Lue `, `Read `) + _cxDepuis(pa.DerniereCollecte) + ' · ' + _cxRythme(pa.IntervalleSecondes) : 'Pas encore lue'));
  const lignes = points.length
    ? points.map(p => _cxLignePoint(p, pa)).join('')
    : `<div class="cx-vide"><span>Aucun point suivi</span>${gestion && !ocpp ? ` — <a href="#" onclick="event.preventDefault();_cxAllerPoints(${pa.Id})">en ajouter</a>` : ''}</div>`;
  const nbAl = +pa.NbAlarmes || 0;
  let actions = '';
  if (gestion) {
    actions = '<div class="cx-carte-actions">'
      + (!entrant && !hotspot && +pa.Actif ? `<button class="btn btn-ghost btn-sm" onclick="_cxLireMaintenant(${pa.Id}, this)" data-cx-info="action.lire">↻ Lire maintenant</button>` : '')
      + (ocpp ? `<button class="btn btn-ghost btn-sm" onclick="_cxCommandesBorne(${pa.Id})" data-cx-info="action.commandes">${lk('zap')} Commandes</button>` : '')
      + `<button class="btn btn-ghost btn-sm" onclick="_cxAllerPoints(${pa.Id})">${lk('settings')} Paramétrer</button>`
      + '</div>';
  }
  return `<div class="card cx-carte${nbAl ? ' cx-carte-alarme' : ''}">
    <div class="cx-carte-tete">
      <span class="cx-proto" title="${escHtml(desc.libelle || pa.Protocole)}" aria-hidden="true">${CX_PROTO_ICONE[pa.Protocole] || '•'}</span>
      <div class="cx-carte-titre">
        <div class="cx-nom" title="${escHtml(pa.Nom)}">${escHtml(pa.Nom)}</div>
        <div class="cx-sous"><span>${escHtml(desc.libelle || pa.Protocole)}</span>${pa.BatimentNom ? ' · ' + escHtml(pa.BatimentNom) : ''}</div>
      </div>
      <span class="badge badge-${et.classe}">${et.libelle}</span>
    </div>
    <div class="cx-carte-meta">${meta}${nbAl ? ` · <a href="#" class="cx-lien-alarme" onclick="event.preventDefault();navigate('connect_alarmes')">${lk('bell')} ${_cxEn() ? _cxN(nbAl, `alarm`, `alarms`) : _cxN(nbAl, `alarme`, `alarmes`)}</a>` : ''}</div>
    ${pa.DerniereErreur && pa.Etat !== 'ok' && +pa.Actif ? `<div class="cx-erreur">${pa.CodeErreur ? codeErreurBadge(pa.CodeErreur) + ' ' : ''}${escHtml(pa.DerniereErreur)}</div>` : ''}
    <div class="cx-points">${lignes}</div>
    ${actions}
  </div>`;
}

function _cxLignePoint(p, pa) {
  const inactif = !+p.Actif;
  const perime = !inactif && _cxPerime(p, pa);
  const ecr = +p.EcritureAutorisee && canEdit() && !inactif;
  const info = inactif ? _cxT(`Point désactivé`, `Point disabled`)
    : (p.DerniereLecture
        ? (perime ? _cxT(`Dernière valeur reçue `, `Last value received `) : _cxT(`Lue `, `Read `)) + _cxDepuis(p.DerniereLecture)
        : _cxT(`Jamais lue`, `Never read`))
      + (p.DerniereErreur ? ' — ' + p.DerniereErreur : '');
  return `<div class="cx-point${inactif ? ' cx-inactif' : ''}${perime ? ' cx-perime' : ''}" role="button" tabindex="0"
      title="${escHtml(info)}" onclick="_cxHistorique(${p.Id})" onkeydown="if(event.key==='Enter'){event.preventDefault();_cxHistorique(${p.Id})}">
    <span class="cx-point-nom">${+p.NbAlarmes ? ('<span aria-label="Alarme active">' + lk('bell') + '</span> ') : ''}${escHtml(p.Nom)}</span>
    ${typeof _cxMiniCourbe === 'function' ? _cxMiniCourbe(p) : ''}
    <span class="cx-point-val">${_cxValeurHtml(p)}${_cxQualite(p.DerniereQualite, p.DerniereErreur)}</span>
    ${ecr ? `<button class="icon-btn cx-ecrire" title="Modifier la consigne" aria-label="Modifier la consigne" onclick="event.stopPropagation();_cxEcrire(${p.Id})">${lk('pencil')}</button>` : ''}
  </div>`;
}

function _cxTrouverPoint(id) {
  for (const pa of ((CxUI.sup && CxUI.sup.passerelles) || [])) {
    const p = (pa.Points || []).find(x => x.Id == id);
    if (p) return Object.assign({ PasserelleNom: pa.Nom, PasserelleProtocole: pa.Protocole }, p);
  }
  return null;
}

async function _cxPoint(id) {
  return _cxTrouverPoint(id) || await apiRequest('connect_points', 'GET', null, id);
}

async function _cxLireMaintenant(id, btn) {
  if (btn) { btn.disabled = true; btn.textContent = 'Lecture…'; }
  try {
    const r = await _cxApi('connect_collecter', 'POST', { id });
    if (r.erreur) toast(_cxT(`Lecture en échec : `, `Read failed: `) + r.erreur, 'error');
    else toast(_cxT(`${r.lues} valeur(s) lue(s)${r.en_defaut ? ', ' + r.en_defaut + ' en défaut' : ''}.`,
                    `${_cxN(r.lues, `value`, `values`)} read${r.en_defaut ? ', ' + r.en_defaut + ' faulty' : ''}.`), r.en_defaut ? 'warning' : 'success');
  } catch (err) {
    toast(err.message, 'error');
  } finally {
    if (btn) { btn.disabled = false; btn.textContent = '↻ Lire maintenant'; }
  }
  if (App.currentPage === 'connect') _cxChargerSupervision(true);
  else if (App.currentPage === 'connect_config') _cxOnglet(CxUI.onglet);
}

function _cxAllerPoints(passerelleId) {
  CxUI.onglet = 'points';
  CxUI.filtrePa = String(passerelleId);
  navigate('connect_config');
}

// ══ Historique d'un point (courbe) ═════════════════════════════════════════

const CX_PERIODES = [['24h', 1], ['7j', 7], ['30j', 30], ['1an', 365]];
const _cxLibPeriode = jours => jours === 1 ? _cxT(`24 heures`, `24 hours`) : jours === 365 ? _cxT(`1 an`, `1 year`) : _cxDureeLongue(jours * 86400);
const _cxLibQualite = q => ({ bonne: _cxT(`bonne`, `good`), douteuse: _cxT(`douteuse`, `uncertain`), mauvaise: _cxT(`mauvaise`, `bad`) })[q] || q || '';

/**
 * Styles de graphique. « auto » : un escalier pour un état ou une consigne
 * (la valeur tient jusqu'à la suivante), une courbe sinon. « conso » n'a de
 * sens que pour un compteur : la consommation par heure, jour ou mois, en
 * barres — l'index brut, toujours croissant, ne dit rien d'un coup d'œil.
 */
const CX_STYLES = [
  ['auto', `Automatique`, `Automatic`],
  ['ligne', `Courbe`, `Line`],
  ['aire', `Aire`, `Area`],
  ['barres', `Barres`, `Bars`],
  ['points', `Points`, `Points`],
  ['escalier', `Escalier`, `Steps`],
  ['conso', `Consommation (barres)`, `Consumption (bars)`],
];

/** Style choisi, mémorisé PAR NATURE de point (une sonde n'a pas le style d'un compteur). */
function _cxStyleMemorise(nature) {
  try { return ((JSON.parse(localStorage.getItem('cx_courbes') || '{}') || {}).styles || {})[nature || 'mesure'] || 'auto'; }
  catch (_) { return 'auto'; }
}
function _cxMemoriserStyle(nature, style) {
  try {
    const p = JSON.parse(localStorage.getItem('cx_courbes') || '{}') || {};
    p.styles = p.styles || {};
    p.styles[nature || 'mesure'] = style;
    localStorage.setItem('cx_courbes', JSON.stringify(p));
  } catch (_) { /* stockage indisponible : le choix vaut pour cette fenêtre */ }
}

/** Le style réellement dessiné. */
function _cxStyleEffectif(style, nature) {
  const escalier = ['etat', 'alarme', 'consigne'].includes(nature);
  if (!style || style === 'auto') return escalier ? 'escalier' : (nature === 'compteur' ? 'conso' : 'ligne');
  if (style === 'conso' && nature !== 'compteur') return 'ligne';
  return style;
}

/** Bornes d'une période : un préréglage (jusqu'à maintenant) ou une plage choisie. */
function _cxPlage(periode, plage) {
  const fin = new Date();
  if (periode === 'perso' && plage && plage.debut && plage.fin) {
    const d = new Date(plage.debut), f = new Date(plage.fin);
    if (!isNaN(d) && !isNaN(f) && f > d) return { debut: d, fin: f, cle: 'perso' };
  }
  const jours = (CX_PERIODES.find(x => x[0] === periode) || CX_PERIODES[0])[1];
  return { debut: new Date(fin.getTime() - jours * 86400000), fin, cle: (CX_PERIODES.find(x => x[0] === periode) || CX_PERIODES[0])[0] };
}

const _cx2 = n => String(n).padStart(2, '0');
/** Date → « AAAA-MM-JJ HH:MM:SS » locale (le format des horodatages de la base). */
const _cxSql = d => `${d.getFullYear()}-${_cx2(d.getMonth() + 1)}-${_cx2(d.getDate())} ${_cx2(d.getHours())}:${_cx2(d.getMinutes())}:${_cx2(d.getSeconds())}`;
/** Date → valeur d'un champ datetime-local. */
const _cxLocal = d => `${d.getFullYear()}-${_cx2(d.getMonth() + 1)}-${_cx2(d.getDate())}T${_cx2(d.getHours())}:${_cx2(d.getMinutes())}`;

/** Libellé d'une période, pour un titre ou un PDF. */
function _cxLibPlage(pl) {
  const loc = _cxEn() ? 'en-GB' : 'fr-FR';
  const o = { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' };
  return pl.debut.toLocaleString(loc, o) + ' → ' + pl.fin.toLocaleString(loc, o);
}

/**
 * Courbe d'un point : période, style, valeurs.
 *
 * @param periode  « 24h », « 7j », « 30j », « 1an » ou « perso » (CxUI.plage)
 */
async function _cxHistorique(pointId, periode) {
  periode = periode || CxUI.periode || '24h';
  CxUI.periode = periode;
  let p;
  try { p = await _cxPoint(pointId); } catch (err) { toast(err.message, 'error'); return; }
  if (!p) return;
  const pl = _cxPlage(periode, CxUI.plage);
  if (pl.cle !== 'perso') CxUI.plage = { debut: _cxLocal(pl.debut), fin: _cxLocal(pl.fin) };
  const choisi = _cxStyleMemorise(p.Nature);
  const styles = CX_STYLES.filter(s => s[0] !== 'conso' || p.Nature === 'compteur');
  const ecr = +p.EcritureAutorisee && canEdit() && +p.Actif;
  const corps = `
    <div class="cx-histo">
      <div class="cx-histo-tete">
        <div class="cx-histo-val">${_cxValeurHtml(p)}${_cxQualite(p.DerniereQualite, p.DerniereErreur)}</div>
        <div class="cx-aide">${p.DerniereLecture ? _cxT(`Lue `, `Read `) + _cxDepuis(p.DerniereLecture) + ' (' + _cxDate(p.DerniereLecture) + ')' : _cxT(`Jamais lue`, `Never read`)}
          ${p.DerniereErreur ? '<br><span class="cx-erreur-texte">' + (p.CodeErreur ? codeErreurBadge(p.CodeErreur) + ' ' : '') + escHtml(p.DerniereErreur) + '</span>' : ''}</div>
      </div>
      <div class="cx-courbe-barre">
        <div class="cx-periodes" role="group" aria-label="${escHtml(_cxT(`Période`, `Period`))}">
          <button type="button" class="cx-periode cx-decaler" aria-label="${escHtml(_cxT(`Période précédente`, `Previous period`))}" title="${escHtml(_cxT(`Période précédente`, `Previous period`))}" onclick="_cxDecaler(${p.Id}, -1)">‹</button>
          ${CX_PERIODES.map(([k, j]) => `<button type="button" class="cx-periode${k === pl.cle ? ' actif' : ''}" aria-pressed="${k === pl.cle}" onclick="_cxHistorique(${p.Id}, '${k}')">${_cxLibPeriode(j)}</button>`).join('')}
          <button type="button" class="cx-periode${pl.cle === 'perso' ? ' actif' : ''}" aria-pressed="${pl.cle === 'perso'}" aria-expanded="${pl.cle === 'perso'}" onclick="_cxBasculerPerso()">${lk('calendar')} ${_cxT(`Choisir…`, `Choose…`)}</button>
          <button type="button" class="cx-periode cx-decaler" aria-label="${escHtml(_cxT(`Période suivante`, `Next period`))}" title="${escHtml(_cxT(`Période suivante`, `Next period`))}" onclick="_cxDecaler(${p.Id}, 1)" ${pl.fin.getTime() > Date.now() - 60000 ? 'disabled' : ''}>›</button>
        </div>
        <label class="cx-style-choix"><span class="cx-aide">${_cxT(`Style`, `Style`)}</span>
          <select id="cxStyle" class="form-control" onchange="_cxChoisirStyle(${p.Id}, this.value)">
            ${styles.map(s => `<option value="${s[0]}"${s[0] === choisi ? ' selected' : ''}>${escHtml(_cxT(s[1], s[2]))}</option>`).join('')}
          </select></label>
        <button type="button" class="btn btn-ghost btn-sm" onclick="_cxPdfCourbes([${p.Id}], 'point')" title="${escHtml(_cxT(`Enregistrer cette courbe en PDF`, `Save this chart as PDF`))}">${lk('download')} PDF</button>
      </div>
      <div id="cxPerso" class="cx-perso" ${pl.cle === 'perso' ? '' : 'hidden'}>
        <label>${_cxT(`Du`, `From`)} <input id="cxPersoDebut" type="datetime-local" class="form-control" value="${escHtml(CxUI.plage.debut)}"></label>
        <label>${_cxT(`au`, `to`)} <input id="cxPersoFin" type="datetime-local" class="form-control" value="${escHtml(CxUI.plage.fin)}"></label>
        <button type="button" class="btn btn-primary btn-sm" onclick="_cxPeriodePerso(${p.Id})">${_cxT(`Afficher`, `Show`)}</button>
        <span id="cxPersoErreur" class="cx-erreur-texte"></span>
      </div>
      <div class="cx-aide cx-plage-lib">${escHtml(_cxLibPlage(pl))}</div>
      <div id="cxCourbe" class="cx-courbe"><div class="loading-spinner"><div class="spinner"></div></div></div>
      <div id="cxCourbeStats" class="cx-courbe-stats"></div>
      <details class="cx-tableau-valeurs"><summary>Voir les valeurs</summary><div id="cxCourbeTable"></div></details>
      ${ecr ? `<div class="cx-histo-ecrire"><button type="button" class="btn btn-primary btn-sm" onclick="_cxEcrire(${p.Id})">${lk('pencil')} Modifier la consigne</button>
        <span class="cx-aide">${_cxT(`Plage permise : `, `Allowed range: `)}${_cxNombre(p.MinEcriture, 6)} ${_cxT(`à`, `to`)} ${_cxNombre(p.MaxEcriture, 6)} ${escHtml(p.Unite || '')}</span></div>` : ''}
      <div id="cxAncragePoint" class="cx-ancrage-point"></div>
    </div>`;
  openModal(p.Nom + (p.PasserelleNom ? ' — ' + p.PasserelleNom : ''), corps, null, 'Enregistrer', 'Fermer', '820px');
  // Fiches des modules liées à ce point (ancrage « connect.point ») : la salle
  // que cette sonde surveille, l'étalonnage du capteur, le contrat du
  // compteur… Dessinées par Larka, sans attendre la courbe.
  if (typeof LarkaExtensions !== 'undefined') {
    try {
      LarkaExtensions.rendrePoint('connect.point', document.getElementById('cxAncragePoint'), { pointId: +p.Id });
    } catch (err) { console.warn('Extensions (point) :', err); }
  }

  let r;
  try {
    r = await apiRequest(`connect_mesures&point_id=${p.Id}&debut=${encodeURIComponent(_cxSql(pl.debut))}&fin=${encodeURIComponent(_cxSql(pl.fin))}&max=600`);
  } catch (err) {
    const z = document.getElementById('cxCourbe');
    if (z) z.innerHTML = `<div class="cx-courbe-vide">${escHtml(err.message)}</div>`;
    return;
  }
  if (!document.getElementById('cxCourbe')) return;   // modale fermée entre-temps
  const mesures = r.mesures || [];
  CxUI.courbe = { point: p, mesures, plage: pl };
  _cxRedessiner();

  const tb = document.getElementById('cxCourbeTable');
  if (tb) {
    const lignes = mesures.slice(-200).reverse();
    tb.innerHTML = lignes.length ? `<div class="table-wrap cx-table-valeurs"><table><thead><tr><th>Date</th><th>Valeur</th><th>Qualité</th></tr></thead><tbody>
      ${lignes.map(m => `<tr><td>${_cxDate(m.Horodatage)}</td><td>${m.Valeur !== null && m.Valeur !== undefined ? _cxNombre(m.Valeur, p.Decimales ?? 2) + (p.Unite ? ' ' + escHtml(p.Unite) : '') : escHtml(m.ValeurTexte || '—')}${m.ValeurTexte && m.Valeur !== null ? ' <span class="cx-aide">' + escHtml(m.ValeurTexte) + '</span>' : ''}</td><td>${escHtml(_cxLibQualite(m.Qualite))}</td></tr>`).join('')}
      </tbody></table></div>${mesures.length > 200 ? '<div class="cx-aide">200 valeurs les plus récentes.</div>' : ''}` : '<div class="cx-aide">Aucune valeur.</div>';
  }
}

/** Redessine la courbe affichée (changement de style : rien n'est relu). */
function _cxRedessiner() {
  const c = CxUI.courbe;
  if (!c || !document.getElementById('cxCourbe')) return;
  const p = c.point;
  const style = _cxStyleEffectif((document.getElementById('cxStyle') || {}).value || _cxStyleMemorise(p.Nature), p.Nature);
  const modele = _cxModeleCourbe(c.mesures, { style, unite: p.Unite || '', decimales: p.Decimales ?? 2,
                                              debut: c.plage.debut, fin: c.plage.fin, nom: p.Nom });
  _cxDessinerCourbe('cxCourbe', modele);
  const st = document.getElementById('cxCourbeStats');
  if (st) st.innerHTML = _cxStatsHtml(modele, p);
}

function _cxStatsHtml(m, p) {
  if (m.vide) return '';
  const u = p.Unite ? ' ' + escHtml(p.Unite) : '';
  const dec = p.Decimales ?? 2;
  const s = m.stats;
  return `<span>Minimum <strong>${_cxNombre(s.min, dec)}${u}</strong></span>
    <span>Maximum <strong>${_cxNombre(s.max, dec)}${u}</strong></span>
    <span>${_cxT(`Moyenne`, `Average`)} <strong>${_cxNombre(s.moy, dec)}${u}</strong></span>
    ${s.conso !== null ? `<span>${_cxT(`Consommation sur la période`, `Consumption over the period`)} <strong>${_cxNombre(s.conso, dec)}${u}</strong></span>` : ''}
    <span>${_cxEn() ? _cxN(s.n, `value`, `values`) : s.n + ' valeur(s)'}${s.regroupees ? _cxT(` (moyennes par tranche)`, ` (averages per slot)`) : ''}</span>`;
}

function _cxChoisirStyle(pointId, style) {
  const c = CxUI.courbe;
  if (c && c.point) _cxMemoriserStyle(c.point.Nature, style);
  _cxRedessiner();
}

function _cxBasculerPerso() {
  const z = document.getElementById('cxPerso');
  if (!z) return;
  z.hidden = !z.hidden;
  if (!z.hidden) { const d = document.getElementById('cxPersoDebut'); if (d) d.focus(); }
}

function _cxPeriodePerso(pointId) {
  const d = (document.getElementById('cxPersoDebut') || {}).value, f = (document.getElementById('cxPersoFin') || {}).value;
  const err = document.getElementById('cxPersoErreur');
  const dd = new Date(d), ff = new Date(f);
  if (!d || !f || isNaN(dd) || isNaN(ff) || ff <= dd) {
    if (err) err.textContent = _cxT(`Choisissez une fin postérieure au début.`, `Pick an end after the start.`);
    return;
  }
  if (ff - dd > 3 * 366 * 86400000) {
    if (err) err.textContent = _cxT(`Trois ans au plus.`, `Three years at most.`);
    return;
  }
  CxUI.plage = { debut: d, fin: f };
  _cxHistorique(pointId, 'perso');
}

/** Période précédente / suivante, de même durée. */
function _cxDecaler(pointId, sens) {
  const pl = _cxPlage(CxUI.periode, CxUI.plage);
  const duree = pl.fin - pl.debut;
  let fin = new Date(pl.fin.getTime() + sens * duree);
  if (fin.getTime() > Date.now()) fin = new Date();
  const debut = new Date(fin.getTime() - duree);
  CxUI.plage = { debut: _cxLocal(debut), fin: _cxLocal(fin) };
  _cxHistorique(pointId, 'perso');
}

/** Graduations « rondes » couvrant [min, max]. */
function _cxGraduations(min, max, n) {
  const etendue = Math.max(max - min, 1e-9);
  const brut = etendue / Math.max(1, n - 1);
  const p10 = Math.pow(10, Math.floor(Math.log10(brut)));
  const pas = [1, 2, 2.5, 5, 10].map(m => m * p10).find(s => s >= brut) || 10 * p10;
  const out = [];
  for (let v = Math.floor(min / pas) * pas; v <= max + pas * 1e-6 && out.length < 14; v += pas) out.push(+v.toFixed(10));
  if (out[out.length - 1] < max) out.push(+(out[out.length - 1] + pas).toFixed(10));
  return out;
}

function _cxNombreAxe(v) {
  const a = Math.abs(v);
  if (a >= 10000) return new Intl.NumberFormat('fr-FR', { notation: 'compact', maximumFractionDigits: 1 }).format(v);
  return v.toLocaleString('fr-FR', { maximumFractionDigits: a < 10 ? 2 : 1 });
}

/**
 * Courbe d'un point : une série, trait de 2 px, bande min–max légère quand
 * les valeurs sont regroupées, réticule et bulle au survol (et au clavier).
 * Les trous de collecte restent des trous : on ne relie pas deux valeurs
 * séparées par une panne.
 */
/**
 * Modèle d'une courbe : ce qu'il faut dessiner, calculé une fois et dessiné
 * deux fois — à l'écran (SVG) et dans un PDF (_cxPdfCourbes). Les deux rendus
 * lisent les mêmes graduations, les mêmes trous et les mêmes barres : un PDF
 * ne peut pas montrer autre chose que l'écran.
 *
 * @param o { style, unite, decimales, debut, fin, nom }
 */
function _cxModeleCourbe(mesures, o) {
  const pts = (mesures || [])
    .filter(m => m.Valeur !== null && m.Valeur !== undefined && m.Valeur !== '')
    .map(m => {
      const d = _cxDateObj(m.Horodatage);
      return d ? { t: d.getTime(), v: +m.Valeur, min: m.ValeurMin != null ? +m.ValeurMin : null, max: m.ValeurMax != null ? +m.ValeurMax : null,
                   texte: m.ValeurTexte || '' } : null;
    })
    .filter(Boolean)
    .sort((a, b) => a.t - b.t);
  const t0 = o.debut.getTime(), t1 = Math.max(o.fin.getTime(), t0 + 1);
  const duree = t1 - t0;
  const style = o.style || 'ligne';
  const m = { vide: !pts.length, style, pts, t0, t1, unite: o.unite || '', decimales: o.decimales ?? 2, nom: o.nom || '' };
  if (m.vide) return m;

  // Statistiques (sur les valeurs, quel que soit le style).
  const mins = pts.map(p => p.min ?? p.v), maxs = pts.map(p => p.max ?? p.v);
  m.stats = { min: Math.min(...mins), max: Math.max(...maxs), moy: pts.reduce((s, p) => s + p.v, 0) / pts.length,
              n: pts.length, regroupees: pts.some(p => p.min !== null), conso: null };

  // ── Consommation par tranche (compteur) ──────────────────────────────
  // L'index de fin d'une tranche est son maximum (un index ne fait que
  // monter) ; la consommation d'une tranche, la différence avec la fin de la
  // tranche précédente. Un index en baisse (compteur remplacé) ne donne rien.
  if (style === 'conso') {
    const pas = duree <= 2.5 * 86400000 ? 'heure' : duree <= 92 * 86400000 ? 'jour' : 'mois';
    const debutTranche = t => {
      const d = new Date(t);
      if (pas === 'heure') d.setMinutes(0, 0, 0);
      else if (pas === 'jour') d.setHours(0, 0, 0, 0);
      else { d.setDate(1); d.setHours(0, 0, 0, 0); }
      return d.getTime();
    };
    const suivante = t => {
      const d = new Date(t);
      if (pas === 'heure') d.setHours(d.getHours() + 1);
      else if (pas === 'jour') d.setDate(d.getDate() + 1);
      else d.setMonth(d.getMonth() + 1);
      return d.getTime();
    };
    const tranches = new Map();
    pts.forEach(p => {
      const k = debutTranche(p.t);
      const x = tranches.get(k) || { t0: k, t1: suivante(k), premier: p.min ?? p.v, fin: p.max ?? p.v };
      x.fin = Math.max(x.fin, p.max ?? p.v);
      tranches.set(k, x);
    });
    const liste = [...tranches.values()].sort((a, b) => a.t0 - b.t0);
    let prec = null;
    m.barres = [];
    liste.forEach((x, i) => {
      const depart = prec ?? x.premier;
      const c = x.fin - depart;
      if (c >= 0) m.barres.push({ t0: Math.max(x.t0, t0), t1: Math.min(x.t1, t1), t: (Math.max(x.t0, t0) + Math.min(x.t1, t1)) / 2, v: c,
                                  lib: _cxLibTranche(x.t0, pas) });
      prec = x.fin;
    });
    m.pas = pas;
    m.stats.conso = m.barres.reduce((s, b) => s + b.v, 0);
    const haut = Math.max(0, ...m.barres.map(b => b.v));
    m.ticks = _cxGraduations(0, haut || 1, 5);
    m.vmin = 0; m.vmax = Math.max(haut, m.ticks[m.ticks.length - 1]);
    m.base = 0;
  } else {
    let vmin = m.stats.min, vmax = m.stats.max;
    // États (0/1, codes d'état) : graduations entières, de 0 (au moins) à 1 (au moins).
    const entiers = style === 'escalier' && pts.every(p => Number.isInteger(p.v)) && (vmax - vmin) <= 12;
    if (entiers) {
      const bas = Math.min(0, Math.floor(vmin)), haut = Math.max(1, Math.ceil(vmax));
      m.ticks = Array.from({ length: haut - bas + 1 }, (_, i) => bas + i);
      vmin = bas - 0.08 * (haut - bas); vmax = haut + 0.08 * (haut - bas);
    } else {
      // Des barres partent de zéro quand zéro est proche : 20 °C en barres
      // depuis 19,8 °C exagèrent une variation de deux dixièmes.
      if (style === 'barres' && vmin > 0 && vmin < (vmax - vmin) * 2) vmin = 0;
      if (vmin === vmax) { const e = Math.abs(vmin) * 0.05 || 1; vmin -= e; vmax += e; }
      m.ticks = _cxGraduations(vmin, vmax, 5);
      vmin = Math.min(vmin, m.ticks[0]); vmax = Math.max(vmax, m.ticks[m.ticks.length - 1]);
    }
    m.vmin = vmin; m.vmax = vmax;
    m.base = vmin <= 0 && vmax >= 0 ? 0 : vmin;

    // Trou : plus de 3 fois l'écart habituel entre deux valeurs (et au moins 30 min).
    // Un ÉTAT, lui, dure jusqu'au suivant : jamais de trou, et le dernier se
    // prolonge jusqu'à maintenant (une borne « Connectée » l'est encore).
    const ecarts = pts.slice(1).map((p, i) => p.t - pts[i].t).sort((a, b) => a - b);
    const median = ecarts.length ? ecarts[Math.floor(ecarts.length / 2)] : 0;
    const seuilTrou = style === 'escalier' ? Infinity : Math.max(3 * median, 30 * 60000);
    m.segments = [];
    let seg = [];
    pts.forEach((p, i) => {
      if (i > 0 && p.t - pts[i - 1].t > seuilTrou) { m.segments.push(seg); seg = []; }
      seg.push(p);
    });
    m.segments.push(seg);
  }

  // Repères du temps : 6, au format de la durée affichée.
  const loc = _cxEn() ? 'en-GB' : 'fr-FR';
  m.fmtT = t => {
    const d = new Date(t);
    if (duree <= 1.5 * 86400000) return d.toLocaleTimeString(loc, { hour: '2-digit', minute: '2-digit' });
    // Deux à quatre jours : le jour seul se répéterait d'un repère à l'autre.
    if (duree <= 4 * 86400000) return d.toLocaleString(loc, { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
    if (duree <= 10 * 86400000) return d.toLocaleDateString(loc, { weekday: 'short', day: 'numeric' });
    if (duree <= 200 * 86400000) return d.toLocaleDateString(loc, { day: '2-digit', month: '2-digit' });
    return d.toLocaleDateString(loc, { month: 'short', year: '2-digit' });
  };
  return m;
}

function _cxLibTranche(t, pas) {
  const loc = _cxEn() ? 'en-GB' : 'fr-FR';
  const d = new Date(t);
  if (pas === 'heure') return d.toLocaleString(loc, { weekday: 'short', day: '2-digit', month: '2-digit', hour: '2-digit' }) + (_cxEn() ? ':00' : ' h');
  if (pas === 'jour') return d.toLocaleDateString(loc, { weekday: 'short', day: '2-digit', month: '2-digit', year: 'numeric' });
  return d.toLocaleDateString(loc, { month: 'long', year: 'numeric' });
}

/**
 * Géométrie d'un modèle dans un cadre : des coordonnées, rien de plus. Les
 * deux rendus (SVG, PDF) s'en servent — chacun n'écrit que sa syntaxe.
 */
function _cxGeometrie(m, L, H, mg) {
  const w = L - mg.g - mg.d, h = H - mg.h - mg.b;
  const X = t => mg.g + (t - m.t0) / (m.t1 - m.t0) * w;
  const Y = v => mg.h + (1 - (v - m.vmin) / ((m.vmax - m.vmin) || 1)) * h;
  const g = { L, H, mg, w, h, X, Y, traces: [], aires: [], bandes: [], barres: [], points: [], survol: [] };
  if (m.style === 'conso') {
    const yb = Y(0);
    m.barres.forEach(b => {
      const x0 = X(b.t0), x1 = X(b.t1);
      const marge = Math.min(2, (x1 - x0) * 0.12);
      g.barres.push({ x: x0 + marge, y: Y(b.v), l: Math.max(0.8, x1 - x0 - 2 * marge), h: Math.max(0, yb - Y(b.v)) });
      g.survol.push({ x: (x0 + x1) / 2, y: Y(b.v), t: b.t, v: b.v, lib: b.lib });
    });
    return g;
  }
  const yb = Y(m.base);
  const n = m.pts.length;
  const largeurBarre = Math.max(1.2, Math.min(14, w / Math.max(1, n) * 0.7));
  m.segments.forEach(seg => {
    const tr = [];
    seg.forEach((p, i) => {
      const x = X(p.t), y = Y(p.v);
      if (i > 0 && m.style === 'escalier') tr.push([x, Y(seg[i - 1].v)]);
      tr.push([x, y]);
    });
    if (m.style === 'escalier') tr.push([X(Math.min(m.t1, Date.now())), Y(seg[seg.length - 1].v)]);
    if (['ligne', 'aire', 'escalier'].includes(m.style)) g.traces.push(tr);
    if (m.style === 'aire') g.aires.push([...tr, [tr[tr.length - 1][0], yb], [tr[0][0], yb]]);
    if (['ligne', 'aire'].includes(m.style) && seg.some(p => p.min !== null && p.max !== null)) {
      g.bandes.push([...seg.map(p => [X(p.t), Y(p.max ?? p.v)]), ...seg.slice().reverse().map(p => [X(p.t), Y(p.min ?? p.v)])]);
    }
  });
  m.pts.forEach(p => {
    const x = X(p.t), y = Y(p.v);
    if (m.style === 'barres') g.barres.push({ x: x - largeurBarre / 2, y: Math.min(y, yb), l: largeurBarre, h: Math.abs(yb - y) });
    if (m.style === 'points') g.points.push([x, y]);
    g.survol.push({ x, y, t: p.t, v: p.v, min: p.min, max: p.max, texte: p.texte });
  });
  return g;
}

function _cxDessinerCourbe(idZone, m) {
  const zone = document.getElementById(idZone);
  if (!zone) return;
  if (m.vide) {
    zone.innerHTML = '<div class="cx-courbe-vide">Aucune valeur enregistrée sur cette période.</div>';
    return;
  }
  const L = 720, H = 250, mg = { g: 56, d: 18, h: 14, b: 30 };
  const g = _cxGeometrie(m, L, H, mg);
  const chemin = pts => pts.map((p, i) => (i ? 'L' : 'M') + p[0].toFixed(1) + ',' + p[1].toFixed(1)).join('') + (pts.length === 1 ? 'h0.01' : '');

  let axes = '';
  m.ticks.forEach(v => {
    const y = g.Y(v).toFixed(1);
    axes += `<line class="cx-grille-ligne" x1="${mg.g}" x2="${L - mg.d}" y1="${y}" y2="${y}"/>`
          + `<text x="${mg.g - 8}" y="${y}" text-anchor="end" dominant-baseline="middle">${escHtml(_cxNombreAxe(v))}</text>`;
  });
  for (let i = 0; i <= 5; i++) {
    const t = m.t0 + (m.t1 - m.t0) * i / 5;
    axes += `<text x="${g.X(t).toFixed(1)}" y="${H - 8}" text-anchor="${i === 0 ? 'start' : (i === 5 ? 'end' : 'middle')}">${escHtml(m.fmtT(t))}</text>`;
  }
  const dernier = g.survol[g.survol.length - 1];
  const traits = g.bandes.map(b => `<path class="cx-bande" d="${chemin(b)}Z"/>`).join('')
    + g.aires.map(a => `<path class="cx-aire" d="${chemin(a)}Z"/>`).join('')
    + g.barres.map(b => `<rect class="cx-barre" x="${b.x.toFixed(1)}" y="${b.y.toFixed(1)}" width="${b.l.toFixed(1)}" height="${b.h.toFixed(1)}" rx="1"/>`).join('')
    + g.traces.map(t => `<path class="cx-trace" d="${chemin(t)}"/>`).join('')
    + g.points.map(p => `<circle class="cx-point-courbe" cx="${p[0].toFixed(1)}" cy="${p[1].toFixed(1)}" r="2.4"/>`).join('');
  const parcours = m.style === 'conso' ? g.survol.length + _cxT(` tranches`, ` slots`) : m.pts.length + _cxT(` valeurs`, ` values`);
  zone.innerHTML = `<div class="cx-courbe-cadre">
    <svg viewBox="0 0 ${L} ${H}" role="img" tabindex="0" aria-label="${escHtml(_cxT(`Graphique de ${m.nom || 'la valeur'} : ${parcours}. Flèches gauche et droite pour parcourir.`,
                                                                                   `Chart of ${m.nom || 'the value'}: ${parcours}. Left and right arrows to browse.`))}">
      ${axes}
      ${traits}
      ${m.style !== 'conso' && m.style !== 'barres' ? `<circle class="cx-marque" cx="${dernier.x.toFixed(1)}" cy="${dernier.y.toFixed(1)}" r="4"/>` : ''}
      <line class="cx-reticule" x1="0" x2="0" y1="${mg.h}" y2="${mg.h + g.h}" visibility="hidden"/>
      <circle class="cx-marque cx-marque-survol" r="4" visibility="hidden"/>
      <rect class="cx-zone-survol" x="${mg.g}" y="${mg.h}" width="${g.w}" height="${g.h}" fill="transparent"/>
    </svg>
    <div class="cx-infobulle" hidden><strong></strong><span></span></div>
  </div>`;

  const svg = zone.querySelector('svg');
  const ret = svg.querySelector('.cx-reticule');
  const mq = svg.querySelector('.cx-marque-survol');
  const bulle = zone.querySelector('.cx-infobulle');
  const loc = _cxEn() ? 'en-GB' : 'fr-FR';
  const pts = g.survol;
  let courant = pts.length - 1;
  const montrer = i => {
    courant = Math.max(0, Math.min(pts.length - 1, i));
    const p = pts[courant];
    ret.setAttribute('x1', p.x); ret.setAttribute('x2', p.x); ret.setAttribute('visibility', 'visible');
    mq.setAttribute('cx', p.x); mq.setAttribute('cy', p.y); mq.setAttribute('visibility', 'visible');
    const dec = m.decimales;
    let valeur = p.texte && m.style === 'escalier' ? p.texte : _cxNombre(p.v, dec) + (m.unite ? ' ' + m.unite : '');
    if (p.min != null && p.max != null && p.min !== p.max) valeur += `  (${_cxNombre(p.min, dec)} ${_cxT(`à`, `to`)} ${_cxNombre(p.max, dec)})`;
    bulle.querySelector('strong').textContent = valeur;
    bulle.querySelector('span').textContent = p.lib || new Date(p.t).toLocaleString(loc, { weekday: 'short', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
    const r = svg.getBoundingClientRect();
    const echelle = r.width / L;
    bulle.hidden = false;
    bulle.style.left = Math.min(Math.max(p.x * echelle, 80), r.width - 80) + 'px';
    bulle.style.top = Math.max(p.y * echelle - 12, 0) + 'px';
  };
  const cacher = () => { ret.setAttribute('visibility', 'hidden'); mq.setAttribute('visibility', 'hidden'); bulle.hidden = true; };
  const plusProche = x => {
    let a = 0, b = pts.length - 1;
    while (b - a > 1) { const k = (a + b) >> 1; if (pts[k].x < x) a = k; else b = k; }
    return Math.abs(pts[a].x - x) <= Math.abs(pts[b].x - x) ? a : b;
  };
  svg.addEventListener('pointermove', ev => {
    const r = svg.getBoundingClientRect();
    const x = (ev.clientX - r.left) / r.width * L;
    if (x < mg.g - 4 || x > L - mg.d + 4) { cacher(); return; }
    montrer(plusProche(x));
  });
  svg.addEventListener('pointerleave', cacher);
  svg.addEventListener('blur', cacher);
  svg.addEventListener('focus', () => montrer(courant));
  svg.addEventListener('keydown', ev => {
    if (ev.key === 'ArrowLeft') { ev.preventDefault(); montrer(courant - 1); }
    else if (ev.key === 'ArrowRight') { ev.preventDefault(); montrer(courant + 1); }
    else if (ev.key === 'Home') { ev.preventDefault(); montrer(0); }
    else if (ev.key === 'End') { ev.preventDefault(); montrer(pts.length - 1); }
  });
}

// ══ Courbes en PDF ═════════════════════════════════════════════════════════
//
// Sans bibliothèque : js/pdf-mini.js écrit le PDF lui-même (texte Helvetica,
// traits vectoriels), chargé à la première demande. Les courbes sont tracées
// d'après le même modèle que l'écran (_cxModeleCourbe, _cxGeometrie).

async function _cxChargerPdf() {
  if (window.LarkaPdf) return window.LarkaPdf;
  await new Promise((ok, ko) => {
    const s = document.createElement('script');
    s.src = 'js/pdf-mini.js';
    s.onload = ok;
    s.onerror = () => ko(new Error(_cxT(`Générateur PDF introuvable (js/pdf-mini.js).`, `PDF generator not found (js/pdf-mini.js).`)));
    document.head.appendChild(s);
  });
  if (!window.LarkaPdf) throw new Error(_cxT(`Générateur PDF indisponible.`, `PDF generator unavailable.`));
  return window.LarkaPdf;
}

/**
 * PDF de courbes.
 *
 * @param ids       points à tracer (dans l'ordre)
 * @param origine   « point » : depuis la fiche d'un point (sa période et son
 *                  style) ; sinon les options passées par l'export
 * @param opts      { plage: {debut: Date, fin: Date}, style, parPage: 1|2, synthese: bool, avancement(i, n) }
 */
async function _cxPdfCourbes(ids, origine, opts = {}) {
  const Pdf = await _cxChargerPdf();
  let plage = opts.plage, styleImpose = opts.style || null;
  if (origine === 'point' && CxUI.courbe) {
    plage = CxUI.courbe.plage;
    styleImpose = (document.getElementById('cxStyle') || {}).value || null;
  }
  plage = plage || _cxPlage(CxUI.periode, CxUI.plage);
  const parPage = opts.parPage === 2 ? 2 : 1;

  // Lecture : une requête par point, quatre à la fois.
  const courbes = new Array(ids.length);
  let suivant = 0, faits = 0;
  const travail = async () => {
    while (suivant < ids.length) {
      const i = suivant++;
      const p = await _cxPoint(ids[i]);
      if (!p) continue;
      const r = await apiRequest(`connect_mesures&point_id=${p.Id}&debut=${encodeURIComponent(_cxSql(plage.debut))}&fin=${encodeURIComponent(_cxSql(plage.fin))}&max=600`);
      const style = _cxStyleEffectif(styleImpose || _cxStyleMemorise(p.Nature), p.Nature);
      courbes[i] = { p, m: _cxModeleCourbe(r.mesures || [], { style, unite: p.Unite || '', decimales: p.Decimales ?? 2,
                                                                 debut: plage.debut, fin: plage.fin, nom: p.Nom }) };
      faits++;
      if (opts.avancement) opts.avancement(faits, ids.length);
    }
  };
  await Promise.all([travail(), travail(), travail(), travail()]);
  const liste = courbes.filter(Boolean);
  if (!liste.length) throw new Error(_cxT(`Aucun point à tracer.`, `No point to draw.`));

  const doc = Pdf.creer({ orientation: 'paysage', titre: _cxT(`Larka — courbes de supervision`, `Larka — supervision charts`) });
  const PW = doc.largeurPage, PH = doc.hauteurPage, M = 36;
  const loc = _cxEn() ? 'en-GB' : 'fr-FR';
  const genere = new Date().toLocaleString(loc, { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
  const libPlage = _cxLibPlage(plage);
  const bleu = '#2563eb', gris = '#6b7280', grille = '#e5e7eb', encre = '#111827';

  const entete = (titre) => {
    doc.page();
    doc.rect(0, 0, PW, 30, { remplir: '#14233b' });
    doc.texte(M, 19, 'Larka', { taille: 12, gras: true, couleur: '#ffffff' });
    doc.texte(M + 42, 19, titre, { taille: 10, couleur: '#cbd5e1' });
    doc.texte(PW - M, 19, libPlage, { taille: 9, couleur: '#cbd5e1', align: 'droite' });
  };
  const fmt = (v, dec) => v === null || v === undefined || !isFinite(v) ? '—' : _cxNombre(v, dec);

  // ── Synthèse : une ligne par courbe ──────────────────────────────────
  if (opts.synthese !== false && liste.length > 1) {
    entete(_cxT(`Synthèse`, `Summary`));
    let y = 62;
    doc.texte(M, y, _cxT(`${liste.length} courbes`, `${liste.length} charts`), { taille: 15, gras: true, couleur: encre });
    y += 26;
    const cols = [[_cxT(`Point`, `Point`), 230], [_cxT(`Bâtiment / connexion`, `Building / connection`), 190], [`Minimum`, 70], [`Maximum`, 70],
                  [_cxT(`Moyenne`, `Average`), 70], [_cxT(`Conso`, `Consumption`), 70], [_cxT(`Valeurs`, `Values`), 60]];
    const ligne = (cells, gras, fond) => {
      if (y > PH - 50) { entete(_cxT(`Synthèse (suite)`, `Summary (continued)`)); y = 62; }
      if (fond) doc.rect(M, y - 12, PW - 2 * M, 18, { remplir: fond });
      let x = M + 4;
      cells.forEach((c, j) => {
        const largeur = cols[j][1];
        const droite = j >= 2;
        doc.texte(droite ? x + largeur - 8 : x, y, doc.couper(String(c), largeur - 10, 9, gras), { taille: 9, gras, couleur: encre, align: droite ? 'droite' : 'gauche' });
        x += largeur;
      });
      y += 18;
    };
    ligne(cols.map(c => c[0]), true, '#e8eef8');
    liste.forEach(({ p, m }, i) => {
      const u = p.Unite ? ' ' + p.Unite : '';
      const s = m.stats || {};
      ligne([p.Nom, [p.BatimentNom, p.PasserelleNom].filter(Boolean).join(' / '),
             m.vide ? '—' : fmt(s.min, p.Decimales ?? 2) + u, m.vide ? '—' : fmt(s.max, p.Decimales ?? 2) + u,
             m.vide ? '—' : fmt(s.moy, p.Decimales ?? 2) + u, m.vide || s.conso === null ? '' : fmt(s.conso, p.Decimales ?? 2) + u,
             m.vide ? '0' : String(s.n)], false, i % 2 ? '#f8fafc' : null);
    });
  }

  // ── Une courbe ───────────────────────────────────────────────────────
  const tracer = ({ p, m }, y0, hauteur) => {
    doc.texte(M, y0 + 16, p.Nom || '', { taille: 13, gras: true, couleur: encre });
    const sous = [p.BatimentNom, p.PasserelleNom, p.Unite ? _cxT(`unité : `, `unit: `) + p.Unite : '',
                  _cxT(CX_STYLES.find(s => s[0] === m.style)?.[1] || '', CX_STYLES.find(s => s[0] === m.style)?.[2] || '')
                  + (m.pas ? ' — ' + _cxT({ heure: `par heure`, jour: `par jour`, mois: `par mois` }[m.pas], `per ${m.pas === 'heure' ? 'hour' : m.pas === 'jour' ? 'day' : 'month'}`) : '')]
      .filter(Boolean).join(' · ');
    doc.texte(M, y0 + 31, sous, { taille: 9, couleur: gris });
    const cadre = { x: M, y: y0 + 40, l: PW - 2 * M, h: hauteur - 40 - 26 };
    if (m.vide) {
      doc.rect(cadre.x, cadre.y, cadre.l, cadre.h, { trait: grille });
      doc.texte(cadre.x + cadre.l / 2, cadre.y + cadre.h / 2, _cxT(`Aucune valeur enregistrée sur cette période.`, `No value recorded over this period.`),
                { taille: 10, couleur: gris, align: 'centre' });
      return;
    }
    const mg = { g: 58, d: 12, h: 8, b: 22 };
    const g = _cxGeometrie(m, cadre.l, cadre.h, mg);
    const tx = pt => [cadre.x + pt[0], cadre.y + pt[1]];
    m.ticks.forEach(v => {
      const y = cadre.y + g.Y(v);
      doc.ligne(cadre.x + mg.g, y, cadre.x + cadre.l - mg.d, y, { couleur: grille, epaisseur: 0.6 });
      doc.texte(cadre.x + mg.g - 6, y + 3, _cxNombreAxe(v), { taille: 8, couleur: gris, align: 'droite' });
    });
    for (let i = 0; i <= 5; i++) {
      const t = m.t0 + (m.t1 - m.t0) * i / 5;
      doc.texte(cadre.x + g.X(t), cadre.y + cadre.h - 6, m.fmtT(t), { taille: 8, couleur: gris, align: i === 0 ? 'gauche' : i === 5 ? 'droite' : 'centre' });
    }
    doc.ligne(cadre.x + mg.g, cadre.y + mg.h + g.h, cadre.x + cadre.l - mg.d, cadre.y + mg.h + g.h, { couleur: '#9ca3af', epaisseur: 0.8 });
    g.bandes.forEach(b => doc.chemin(b.map(tx), { fermer: true, remplir: '#dbe5fb' }));
    g.aires.forEach(a => doc.chemin(a.map(tx), { fermer: true, remplir: '#c7d7f8' }));
    g.barres.forEach(b => doc.rect(cadre.x + b.x, cadre.y + b.y, b.l, Math.max(0.3, b.h), { remplir: bleu }));
    g.traces.forEach(t => doc.chemin(t.map(tx), { trait: bleu, epaisseur: 1.4 }));
    g.points.forEach(pt => { const [x, y] = tx(pt); doc.cercle(x, y, 1.6, { remplir: bleu }); });
    // Chiffres de la période, sous le graphique.
    const s = m.stats, dec = p.Decimales ?? 2, u = p.Unite ? ' ' + p.Unite : '';
    const chiffres = [`Minimum ${fmt(s.min, dec)}${u}`, `Maximum ${fmt(s.max, dec)}${u}`, _cxT(`Moyenne `, `Average `) + fmt(s.moy, dec) + u]
      .concat(s.conso !== null ? [_cxT(`Consommation `, `Consumption `) + fmt(s.conso, dec) + u] : [])
      .concat([_cxEn() ? `${s.n} values` : `${s.n} valeur(s)` + (s.regroupees ? ' (moyennes par tranche)' : '')]);
    doc.texte(M, y0 + hauteur - 8, chiffres.join('     '), { taille: 9, couleur: encre });
  };

  const zone = PH - 30 - 34;     // sous le bandeau, au-dessus du pied
  liste.forEach((c, i) => {
    if (i % parPage === 0) entete(_cxT(`Courbes de supervision`, `Supervision charts`));
    const h = zone / parPage;
    tracer(c, 36 + (i % parPage) * h, h - (parPage === 2 ? 10 : 0));
  });

  // Pied de page : numérotation, et ce qui a produit le document.
  const n = doc.nombrePages();
  for (let i = 0; i < n; i++) {
    doc.allerPage(i);
    doc.texte(M, PH - 14, _cxT(`Généré par Larka le ${genere}`, `Generated by Larka on ${genere}`), { taille: 8, couleur: gris });
    doc.texte(PW - M, PH - 14, `${i + 1} / ${n}`, { taille: 8, couleur: gris, align: 'droite' });
  }

  const nom = 'larka-courbes-' + (liste.length === 1 ? (liste[0].p.Nom || 'point').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') + '-' : '')
            + new Date().toISOString().slice(0, 10) + '.pdf';
  const blob = doc.blob();
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = nom;
  document.body.appendChild(a);
  a.click();
  setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
  return { courbes: liste.length, pages: n, nom };
}

// ══ Écriture vers un équipement ════════════════════════════════════════════

/**
 * Exécute une action d'écriture ; si le serveur répond 428 (identité non
 * confirmée), demande la confirmation puis rejoue l'action une fois.
 */
async function _cxAvecIdentite(action) {
  try {
    return await action();
  } catch (err) {
    if (err._status !== 428) throw err;
    const ok = await _cxConfirmerIdentite();
    if (!ok) return null;
    return await action();
  }
}

/** « 5 min », « 1 h », « 8 h » : durée de validité de la confirmation. */
function _cxDureeIdentite(min) {
  min = +min || 5;
  return min < 60 ? `${min} min` : `${min / 60} h`;
}

/**
 * Fenêtre de confirmation d'identité, au-dessus de la modale en cours.
 * Compte local : le mot de passe Larka. Compte Microsoft / Google : la fenêtre
 * du fournisseur, qui redemande le mot de passe (api/IdentiteConfirmee.php) ;
 * elle prévient Larka par un message de même origine en se fermant.
 */
function _cxConfirmerIdentite(pourquoi) {
  return new Promise(resolve => {
    // L'état de l'écran, sinon celui de la fenêtre « Serveur de supervision » (module fermé).
    const id = (typeof CxServeur !== 'undefined' && CxServeur.identite) || {};
    const fournisseur = (CxUI.etat && CxUI.etat.fournisseur) || id.fournisseur || 'local';
    const sso = fournisseur !== 'local';
    const nomSso = { microsoft: 'Microsoft', google: 'Google' }[fournisseur] || fournisseur;
    const duree = _cxDureeIdentite((CxUI.etat && CxUI.etat.identite_minutes) || id.minutes);
    const ov = document.createElement('div');
    ov.className = 'modal-overlay open cx-identite';
    ov.innerHTML = `<div class="modal" role="dialog" aria-modal="true" aria-labelledby="cxIdTitre" style="width:440px;max-width:94vw">
      <div class="modal-header"><div class="modal-title" id="cxIdTitre">${lk('lock-keyhole')} Confirmez votre identité</div></div>
      <div class="modal-body">
        <p class="cx-aide" style="margin-top:0">${pourquoi ? escHtml(pourquoi) + ' ' : _cxT(`Agir sur un équipement du bâtiment demande de confirmer que c'est bien vous. `, `Acting on building equipment requires confirming that it is really you. `)}${_cxT(`La confirmation vaut ${duree} ; chaque action reste tracée dans le journal de sécurité.`,
                                                        `The confirmation lasts ${duree}; every action is recorded in the security log.`)}</p>
        ${sso
          ? `<p class="cx-aide">${_cxT(`Votre compte se connecte avec ${escHtml(nomSso)} : une fenêtre ${escHtml(nomSso)} va vous redemander votre mot de passe.`,
                                       `Your account signs in with ${escHtml(nomSso)}: a ${escHtml(nomSso)} window will ask for your password again.`)}</p>`
          : `<div class="form-group"><label class="form-label" for="cxIdMdp">Votre mot de passe Larka</label>
             <input type="password" id="cxIdMdp" class="form-control" autocomplete="current-password"></div>`}
        <div id="cxIdErreur" class="cx-erreur-texte" role="alert"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" data-r="non">Annuler</button>
        <button type="button" class="btn btn-primary" data-r="oui">${sso ? lk('arrow-up-right') + ' ' + _cxT(`Continuer avec ${escHtml(nomSso)}`, `Continue with ${escHtml(nomSso)}`) : 'Confirmer'}</button>
      </div>
    </div>`;
    document.body.appendChild(ov);
    const champ = ov.querySelector('#cxIdMdp') || ov.querySelector('[data-r="oui"]');
    setTimeout(() => champ && champ.focus(), 50);
    let fini = false, fenetre = null, veille = null;
    const ecoute = ev => {
      if (ev.origin !== window.location.origin || !ev.data || ev.data.type !== 'LARKA_IDENTITE') return;
      if (ev.data.ok) terminer(true);
      else echec(ev.data.message || _cxT(`Confirmation refusée.`, `Confirmation refused.`));
    };
    const terminer = v => {
      if (fini) return; fini = true;
      window.removeEventListener('message', ecoute);
      if (veille) clearInterval(veille);
      ov.remove(); resolve(v);
    };
    const err = ov.querySelector('#cxIdErreur');
    const btn = ov.querySelector('[data-r="oui"]');
    const echec = m => { err.textContent = m; btn.disabled = false; };
    window.addEventListener('message', ecoute);

    const validerSso = async () => {
      err.textContent = '';
      btn.disabled = true;
      // Ouverte TOUT DE SUITE, dans le clic : ouverte après l'appel au serveur,
      // le navigateur la prendrait pour une fenêtre surgissante et la bloquerait.
      fenetre = window.open('', 'larka_identite', 'width=520,height=680');
      if (!fenetre) { echec(_cxT(`Fenêtre bloquée par le navigateur : autorisez les fenêtres de ce site, puis recommencez.`, `Window blocked by the browser.`)); return; }
      try {
        const r = await apiRequest('connect_identite_sso', 'POST', {});
        fenetre.location.href = r.url;
      } catch (e) { try { fenetre.close(); } catch (_) {} echec(e.message || 'Confirmation impossible.'); return; }
      // Fenêtre fermée sans message (bloqueur de scripts, autre onglet) : on demande au serveur.
      if (veille) clearInterval(veille);
      veille = setInterval(async () => {
        if (fini || !fenetre || !fenetre.closed) return;
        clearInterval(veille); veille = null;
        try {
          const d = await apiRequest('connect_identite_duree');
          if (d.restant > 0) { terminer(true); return; }
        } catch (_) {}
        if (!fini) echec(_cxT(`Fenêtre fermée avant la fin de la confirmation.`, `Window closed before confirmation.`));
      }, 700);
    };
    const validerMdp = async () => {
      const motDePasse = (ov.querySelector('#cxIdMdp') || {}).value || '';
      if (!motDePasse) { err.textContent = 'Saisissez votre mot de passe.'; return; }
      btn.disabled = true;
      try {
        await apiRequest('connect_confirmer_identite', 'POST', { motDePasse });
        terminer(true);
      } catch (e) {
        echec(e.message || 'Confirmation refusée.');
        const m = ov.querySelector('#cxIdMdp');
        if (m) { m.value = ''; m.focus(); }
      }
    };
    const valider = sso ? validerSso : validerMdp;
    ov.querySelector('[data-r="non"]').addEventListener('click', () => { try { fenetre && fenetre.close(); } catch (_) {} terminer(false); });
    btn.addEventListener('click', valider);
    ov.addEventListener('keydown', e => {
      if (e.key === 'Escape') { e.stopPropagation(); terminer(false); }
      if (e.key === 'Enter' && !sso) { e.preventDefault(); valider(); }
    });
  });
}

async function _cxEcrire(pointId) {
  let p;
  try { p = await apiRequest('connect_points', 'GET', null, pointId); } catch (err) { toast(err.message, 'error'); return; }
  const bacnet = p.PasserelleProtocole === 'bacnet';
  const corps = `
    <div class="cx-ecriture">
      <div class="cx-ecriture-actuelle">${_cxT(`Valeur actuelle :`, `Current value:`)} <strong>${_cxValeurHtml(p)}</strong>
        <span class="cx-aide">${p.DerniereLecture ? '(' + _cxT(`lue `, `read `) + _cxDepuis(p.DerniereLecture) + ')' : ''}</span></div>
      <div class="form-group">
        <label class="form-label" for="cxValeur"><span>Nouvelle valeur</span>${p.Unite ? ' (' + escHtml(p.Unite) + ')' : ''}</label>
        <input id="cxValeur" class="form-control" type="number" step="any" inputmode="decimal"
          min="${escHtml(p.MinEcriture ?? '')}" max="${escHtml(p.MaxEcriture ?? '')}" value="${escHtml(p.DerniereValeur ?? '')}">
      </div>
      <div class="cx-aide">${_cxT(`Plage permise : ${_cxNombre(p.MinEcriture, 6)} à ${_cxNombre(p.MaxEcriture, 6)} ${escHtml(p.Unite || '')}. La valeur part tout de suite vers l'équipement ; l'action est tracée dans le journal de sécurité.`,
                                  `Allowed range: ${_cxNombre(p.MinEcriture, 6)} to ${_cxNombre(p.MaxEcriture, 6)} ${escHtml(p.Unite || '')}. The value goes to the device straight away; the action is recorded in the security log.`)}</div>
      ${bacnet ? `<label class="cx-case" style="margin-top:10px"><input type="checkbox" id="cxRelacher" onchange="document.getElementById('cxValeur').disabled=this.checked">
        Relâcher la commande (rendre la main à la programmation de la GTB)</label>` : ''}
    </div>`;
  openModal(_cxT(`Modifier « ${p.Nom} »`, `Change “${p.Nom}”`), corps, () => _cxEnvoyerEcriture(p.Id), "Envoyer à l'équipement", 'Annuler', '480px');
}

async function _cxEnvoyerEcriture(pointId) {
  const relacher = !!(document.getElementById('cxRelacher') || {}).checked;
  const brut = String(gv('cxValeur')).trim().replace(',', '.');
  if (!relacher && (brut === '' || isNaN(Number(brut)))) { toast('Saisissez une valeur numérique.', 'error'); return; }
  const btn = document.getElementById('modalSaveBtn');
  if (btn) btn.disabled = true;
  try {
    const r = await _cxAvecIdentite(() => _cxApi('connect_ecrire', 'POST', { pointId, valeur: relacher ? null : Number(brut), relacher }));
    if (r === null) return;   // confirmation annulée : la modale reste ouverte
    closeModal();
    toast(r.message || 'Valeur envoyée.', 'success');
    if (App.currentPage === 'connect') _cxChargerSupervision(true);
  } catch (err) {
    toast(err.message, 'error');
  } finally {
    if (btn) btn.disabled = false;
  }
}

// ══ Bornes de recharge : commandes ═════════════════════════════════════════

const CX_CMD_SENSIBLES = ['RemoteStopTransaction', 'ChangeAvailability:Inoperative', 'Reset:Soft', 'Reset:Hard'];

async function _cxCommandesBorne(passerelleId) {
  let e;
  try { e = await _cxEtat(); } catch (err) { toast(err.message, 'error'); return; }
  const d = CxUI.catalogue.ocpp || {};
  const cmds = d.commandes || {};
  const pa = ((CxUI.sup && CxUI.sup.passerelles) || CxUI.passerelles || []).find(x => x.Id == passerelleId) || {};
  const corps = `
    <div class="cx-commandes">
      ${!e.ocpp.en_marche ? ('<div class="cx-bandeau">' + lk('triangle-exclamation-point', 'warn') + ' Le service OCPP ne tourne pas : aucune commande ne peut être transmise.</div>') : ''}
      <p class="cx-aide" style="margin-top:0">${_cxT(`La commande est transmise à la borne par le service OCPP ; la réponse s'affiche ici (15 s au plus).`,
                                                      `The OCPP service sends the command to the charger; the answer appears here (15 s at most).`)}</p>
      <div class="form-grid">
        <div class="form-group"><label class="form-label" for="cxCmdBadge">Badge pour démarrer une charge</label>
          <input id="cxCmdBadge" class="form-control" placeholder="ex. 04A2B3C4" maxlength="20"></div>
        <div class="form-group"><label class="form-label" for="cxCmdConnecteur">Connecteur à déverrouiller</label>
          <input id="cxCmdConnecteur" class="form-control" type="number" min="1" value="1"></div>
      </div>
      <div class="cx-cmd-liste">
        ${Object.entries(cmds).map(([k, l]) => `<button type="button" class="btn ${CX_CMD_SENSIBLES.includes(k) ? 'btn-ghost cx-cmd-sensible' : 'btn-ghost'}"
            onclick="_cxEnvoyerCommande(${passerelleId}, ${jsArg(k)}, this)">${escHtml(l)}</button>`).join('')}
      </div>
      <div id="cxCmdResultat" role="status"></div>
    </div>`;
  openModal(_cxT(`Commandes — ${pa.Nom || 'borne'}`, `Commands — ${pa.Nom || 'charger'}`), corps, null, 'Enregistrer', 'Fermer', '620px');
}

function _cxEnvoyerCommande(passerelleId, commande, btn) {
  // Le libellé AFFICHÉ (déjà traduit par le pack de langue), à défaut celui du catalogue.
  const libelle = (btn && btn.textContent.trim()) || ((CxUI.catalogue.ocpp || {}).commandes || {})[commande] || commande;
  const go = async () => {
    const zone = document.getElementById('cxCmdResultat');
    let valeur = '';
    if (commande === 'RemoteStartTransaction') {
      valeur = gv('cxCmdBadge').trim();
      if (!valeur) { toast('Indiquez le badge à utiliser.', 'error'); return; }
    } else if (commande === 'UnlockConnector') {
      valeur = gv('cxCmdConnecteur') || '1';
    }
    const l = escHtml(libelle);
    if (zone) zone.innerHTML = `<div class="cx-aide">${_cxT(`${lk('hourglass')} « ${l} » transmise, en attente de la borne…`, `${lk('hourglass')} “${l}” sent, waiting for the charger…`)}</div>`;
    if (btn) btn.disabled = true;
    try {
      const r = await _cxAvecIdentite(() => _cxApi('connect_commande_borne', 'POST', { passerelleId, commande, valeur }, 30000));
      if (r === null) { if (zone) zone.innerHTML = ''; return; }
      if (zone) zone.innerHTML = r.ok
        ? `<div class="cx-test-verdict ok">${_cxT(`✓ ${l} : acceptée par la borne.`, `✓ ${l}: accepted by the charger.`)}${r.message ? ' <span class="cx-aide">' + escHtml(r.message) + '</span>' : ''}</div>`
        : `<div class="cx-test-verdict moyen">${lk('hourglass')} ${escHtml(r.message || _cxT(`Sans réponse pour l'instant.`, `No answer yet.`))}</div>`;
    } catch (err) {
      if (zone) zone.innerHTML = `<div class="cx-test-verdict ko">✕ ${escHtml(err.message)}</div>`;
    } finally {
      if (btn) btn.disabled = false;
    }
  };
  if (CX_CMD_SENSIBLES.includes(commande)) showConfirm(_cxT(`« ${libelle} » : confirmer l'envoi à la borne ?`, `“${libelle}”: confirm sending it to the charger?`), go, 'Envoyer');
  else go();
}

// ══ Alarmes ════════════════════════════════════════════════════════════════

async function renderConnectAlarmes() {
  const c = document.getElementById('mainContent');
  _cxArreterMinuteur();
  showLoading(c);
  let e;
  try { e = await _cxEtat(true); } catch (err) { c.innerHTML = _cxErreurHtml(err); return; }
  if (App.currentPage !== 'connect_alarmes') return;
  if (_cxModuleFerme(c, e)) return;
  document.getElementById('topbarActions').innerHTML =
    `<button class="btn btn-ghost btn-sm" onclick="navigate('connect')">${lk('satellite-dish')} Supervision</button>` + _cxBoutonAide();
  const gestion = canEdit();
  c.innerHTML = `
    <div class="cx-page">
      <div id="cxBandeau">${_cxBandeau(e)}</div>
      ${_cxIntro('alarmes')}
      <div class="cx-filtres">
        <div class="chip-filters" style="padding:0">
          ${[['active', 'Actives'], ['terminee', 'Terminées'], ['toutes', 'Toutes']].map(([k, l]) =>
            `<div class="chip${CxUI.alarmesEtat === k ? ' active' : ''}" role="button" tabindex="0" onclick="CxUI.alarmesEtat='${k}';renderConnectAlarmes()">${l}</div>`).join('')}
        </div>
        <select class="form-control" aria-label="Gravité" onchange="CxUI.alarmesGravite=this.value;_cxPeindreAlarmes()">
          <option value="">Toutes gravités</option>${_cxOptions(CX_GRAVITES.map(g => [g[0], g[1]]), CxUI.alarmesGravite)}
        </select>
        <div class="search-input-wrap"><span class="search-icon">${lk('search')}</span>
          <input class="search-input" type="search" placeholder="Rechercher…" value="${escHtml(CxUI.alarmesRech)}" oninput="CxUI.alarmesRech=this.value;_cxPeindreAlarmes()"></div>
        ${gestion ? '<button class="btn btn-ghost btn-sm" id="cxAcqSel" onclick="_cxAcquitterSelection()" disabled>✓ Acquitter la sélection</button>' : ''}
        ${_cxBoutonsGrouper()}
      </div>
      <div id="cxAlarmes" class="card"></div>
    </div>`;
  try {
    CxUI.alarmes = await apiRequest('connect_alarmes&etat=' + CxUI.alarmesEtat + '&limite=500') || [];
  } catch (err) {
    document.getElementById('cxAlarmes').innerHTML = `<div class="no-results">${escHtml(err.message)}</div>`;
    return;
  }
  _cxPeindreAlarmes();
  if (App.currentPage === 'connect_alarmes') _cxGuideAuto();
}

function _cxPeindreAlarmes() {
  const z = document.getElementById('cxAlarmes');
  if (!z) return;
  const gestion = canEdit();
  const q = (CxUI.alarmesRech || '').trim();
  const liste = (CxUI.alarmes || []).filter(a =>
    (!CxUI.alarmesGravite || a.Gravite === CxUI.alarmesGravite)
    && (!q || [a.Message, a.PointNom, a.PasserelleNom, a.BatimentNom, a.Code].some(x => smartTextMatch(x || '', q))));
  if (!liste.length) {
    z.innerHTML = `<div class="no-results">${CxUI.alarmesEtat === 'active' && !q && !CxUI.alarmesGravite ? (lk('circle-check', 'ok') + ' Aucune alarme active.') : 'Aucune alarme.'}</div>`;
    _cxMajSelection();
    return;
  }
  z.innerHTML = `<div class="table-wrap"><table class="cx-table-alarmes"><thead><tr>
      ${gestion ? `<th style="width:28px"><input type="checkbox" aria-label="Tout sélectionner" onchange="document.querySelectorAll('.cx-sel-alarme:not(:disabled)').forEach(c=>{if(!c.closest('[hidden]'))c.checked=this.checked});_cxMajSelection()"></th>` : ''}
      <th>Début</th><th>Gravité</th><th>Alarme</th><th>Où</th><th>État</th><th>Demande</th>${gestion ? '<th></th>' : ''}
    </tr></thead><tbody>
    ${_cxLignesParBatiment(liste, a => a.BatimentNom, gestion ? 8 : 6, a => {
      const active = a.Etat === 'active';
      const acq = +a.Acquittee;
      return `<tr class="${active && !acq ? 'cx-alarme-active' : ''}">
        ${gestion ? `<td><input type="checkbox" class="cx-sel-alarme" value="${a.Id}" ${acq ? 'disabled' : ''} aria-label="Sélectionner" onchange="_cxMajSelection()"></td>` : ''}
        <td style="white-space:nowrap">${_cxDate(a.Debut)}<div class="cx-aide">${_cxDepuis(a.Debut)}</div></td>
        <td>${_cxGravite(a.Gravite)}</td>
        <td><div class="cx-alarme-msg">${escHtml(a.Message || '')}</div>
          <div class="cx-aide"><span>${escHtml(CX_ORIGINES[a.Origine] || a.Origine || '')}</span>${a.ValeurDeclenchement !== null && a.ValeurDeclenchement !== undefined && a.ValeurDeclenchement !== '' ? _cxT(` · valeur `, ` · value `) + _cxNombre(a.ValeurDeclenchement, 3) + (a.PointUnite ? ' ' + escHtml(a.PointUnite) : '') : ''}</div></td>
        <td>${escHtml(a.PointNom || '')}${a.PointNom && a.PasserelleNom ? '<br>' : ''}<span class="cx-aide">${escHtml(a.PasserelleNom || '')}${a.BatimentNom ? ' · ' + escHtml(a.BatimentNom) : ''}</span></td>
        <td>${active ? '<span class="badge badge-red">Active</span>' : `<span class="badge badge-gray">Terminée</span><div class="cx-aide">${_cxDate(a.Fin)}</div>`}
          ${acq ? `<div class="cx-aide" title="${escHtml(a.Commentaire || '')}">${_cxT(`✓ acquittée par `, `✓ acknowledged by `)}${escHtml(a.AcquitteePar || '?')}, ${_cxDate(a.DateAcquittement)}${a.Commentaire ? ' — « ' + escHtml(a.Commentaire) + ' »' : ''}</div>` : ''}</td>
        <td>${a.DemandeId ? `<a href="#" onclick="event.preventDefault();_cxVoirDemande(${a.DemandeId})">${_cxT(`DI n° `, `Request #`)}${a.DemandeId}</a>${a.DemandeStatut ? '<div class="cx-aide">' + escHtml(a.DemandeStatut) + '</div>' : ''}` : '<span class="cx-aide">—</span>'}</td>
        ${gestion ? `<td>${!acq ? `<button class="btn btn-ghost btn-sm" onclick="_cxAcquitter([${a.Id}])">✓ Acquitter</button>` : ''}</td>` : ''}
      </tr>`;
    }, items => { const n = items.filter(a => a.Etat === 'active').length; return n ? `<span class="badge badge-red">${_cxT(`${n} active(s)`, `${n} active`)}</span>` : ''; })}
    </tbody></table></div>`;
  _cxMajSelection();
}

function _cxMajSelection() {
  const b = document.getElementById('cxAcqSel');
  if (!b) return;
  const n = document.querySelectorAll('.cx-sel-alarme:checked').length;
  b.disabled = n === 0;
  b.textContent = n ? _cxT(`✓ Acquitter la sélection (${n})`, `✓ Acknowledge selected (${n})`) : '✓ Acquitter la sélection';
}

function _cxAcquitterSelection() {
  const ids = [...document.querySelectorAll('.cx-sel-alarme:checked')].map(c => +c.value);
  if (ids.length) _cxAcquitter(ids);
}

function _cxAcquitter(ids) {
  const corps = `<p class="cx-aide" style="margin-top:0">${_cxT(`Acquitter, c'est indiquer que l'alarme a été vue et prise en charge. Elle reste « active » tant que la condition dure, et se termine d'elle-même ensuite.`,
                                                             `Acknowledging means the alarm has been seen and is being handled. It stays “active” while the condition lasts, and ends by itself afterwards.`)}</p>
    <div class="form-group"><label class="form-label" for="cxAcqCom">Commentaire (facultatif)</label>
      <textarea id="cxAcqCom" class="form-control" rows="3" maxlength="500" placeholder="ex. Technicien prévenu, intervention demain matin"></textarea></div>`;
  const titre = ids.length > 1 ? _cxT(`Acquitter ${ids.length} alarmes`, `Acknowledge ${ids.length} alarms`) : _cxT(`Acquitter l'alarme`, `Acknowledge the alarm`);
  openModal(titre, corps, async () => {
    try {
      const r = await apiRequest('connect_alarme_acquitter', 'POST', { ids, commentaire: gv('cxAcqCom').trim() });
      closeModal();
      toast(_cxT(`${r.acquittees} alarme(s) acquittée(s).`, `${_cxN(r.acquittees, `alarm`, `alarms`)} acknowledged.`), 'success');
      if (typeof _refreshBadgeConnect === 'function') _refreshBadgeConnect();
      renderConnectAlarmes();
    } catch (err) { toast(err.message, 'error'); }
  }, 'Acquitter', 'Annuler', '480px');
}

async function _cxVoirDemande(id) {
  try {
    if (window.PageLoader) await window.PageLoader.ensure('demandes');
    if (typeof voirDetailDemande === 'function') { voirDetailDemande(id); return; }
  } catch (_) { /* repli ci-dessous */ }
  navigate('demandes');
}

// ══ Configuration : connexions, points, règles, relevés, bornes ════════════

async function renderConnectConfig() {
  const c = document.getElementById('mainContent');
  _cxArreterMinuteur();
  showLoading(c);
  if (!canEdit()) {
    c.innerHTML = '<div class="card cx-ferme">La configuration des connexions est réservée aux gestionnaires.</div>';
    return;
  }
  let e;
  try { e = await _cxEtat(true); } catch (err) { c.innerHTML = _cxErreurHtml(err); return; }
  if (App.currentPage !== 'connect_config') return;
  if (_cxModuleFerme(c, e)) return;
  document.getElementById('topbarActions').innerHTML =
      `<button class="btn btn-ghost btn-sm" onclick="navigate('connect')">${lk('satellite-dish')} Supervision</button>`
    + (_cxParcoursMasque() ? `<button class="btn btn-ghost btn-sm" onclick="_cxMasquerParcours(false)">${lk('compass')} ${_cxT(`Guide`, `Guide`)}</button>` : '')
    + `<button class="btn btn-ghost btn-sm" onclick="_cxDetection()">${_cxT(`${lk('satellite')} Détecter les équipements`, `${lk('satellite')} Detect devices`)}</button>`
    + `<button class="btn btn-ghost btn-sm" onclick="_cxServeurOuvrir()" title="${escHtml(_cxT(`Supervision sur un serveur séparé, lien chiffré`, `Separate supervision server`))}">${lk('server')} ${_cxT(`Serveur de supervision`, `Supervision server`)}</button>`
    + `<button class="btn btn-primary btn-sm" onclick="_cxNouvelleConnexion()">＋ Nouvelle connexion</button>` + _cxBoutonAide();
  if (!CX_ONGLETS.some(o => o[0] === CxUI.onglet)) CxUI.onglet = 'connexions';
  c.innerHTML = `
    <div class="cx-page">
      <div id="cxBandeau">${_cxBandeau(e)}</div>
      <div id="cxParcours"></div>
      <div class="cx-onglets" role="tablist">
        ${CX_ONGLETS.map(([k, l]) => `<button type="button" class="cx-onglet${k === CxUI.onglet ? ' actif' : ''}" role="tab" data-onglet="${k}"
            aria-selected="${k === CxUI.onglet}" onclick="_cxOnglet('${k}')">${k === 'batiments' ? (lk('building-complex') + ' ') + _cxT(`Bâtiments`, `Buildings`) : l}${k === 'releves' && App._connectAValider
              ? ` <span class="cx-onglet-badge">${App._connectAValider}</span>` : ''}</button>`).join('')}
        <span class="cx-onglets-fin">${_cxBoutonsGrouper()}</span>
      </div>
      <div id="cxIntroOnglet"></div>
      <div id="cxOnglet" role="tabpanel"></div>
    </div>`;
  _cxParcours();
  await _cxOnglet(CxUI.onglet);
  _cxGuideAuto();
}

async function _cxOnglet(k) {
  CxUI.onglet = k;
  document.querySelectorAll('.cx-onglet').forEach(b => {
    const on = b.dataset.onglet === k;
    b.classList.toggle('actif', on);
    b.setAttribute('aria-selected', on ? 'true' : 'false');
  });
  const z = document.getElementById('cxOnglet');
  if (!z) return;
  const intro = document.getElementById('cxIntroOnglet');
  if (intro) intro.innerHTML = _cxIntro(k);
  showLoading(z);
  try {
    if (k === 'batiments') await _cxOngletBatiments(z);
    else if (k === 'connexions') await _cxOngletConnexions(z);
    else if (k === 'points') await _cxOngletPoints(z);
    else if (k === 'regles') await _cxOngletRegles(z);
    else if (k === 'releves') await _cxOngletReleves(z);
    else if (k === 'bornes') await _cxOngletBornes(z);
    else if (k === 'hotspots') await _cxOngletHotspots(z);
    else if (k === 'journal') await _cxOngletJournal(z);
  } catch (err) {
    z.innerHTML = `<div class="card"><div class="no-results">${escHtml(err.message)}</div></div>`;
  }
}

function _cxRafraichir() {
  if (App.currentPage === 'connect_config') { _cxOnglet(CxUI.onglet); _cxParcours(); }
  else if (App.currentPage === 'connect') _cxChargerSupervision(true);
}

// ── Onglet Connexions ───────────────────────────────────────────────────────

async function _cxOngletConnexions(z) {
  const pas = await apiRequest('connect_passerelles') || [];
  CxUI.passerelles = pas;
  const e = CxUI.etat || {};
  let h = '';
  const inconnues = (e.ocpp && e.ocpp.inconnues) || [];
  if (inconnues.length) {
    const n = inconnues.length;
    h += `<div class="cx-bandeau">${lk('triangle-exclamation-point', 'warn')} ${_cxT(`<strong>${n} borne(s) inconnue(s)</strong> ont tenté de se connecter et ont été refusées :`,
                                            `<strong>${_cxN(n, `unknown charger`, `unknown chargers`)}</strong> tried to connect and ${n === 1 ? 'was' : 'were'} refused:`)}
      ${inconnues.slice(0, 5).map(i => `<code>${escHtml(i.chemin)}</code> ${_cxT(`depuis`, `from`)} ${escHtml(i.ip)} (${_cxDate(i.quand)})
        — <a href="#" onclick="event.preventDefault();_cxDeclarerBorne(${jsArg(i.chemin)})">la déclarer</a>`).join(' · ')}</div>`;
  }
  // Capteurs LoRaWAN entendus par une passerelle mais pas déclarés : un clic pour les déclarer.
  const capteurs = ((e.lorawan && e.lorawan.inconnus) || []).filter(i => i.genre === 'join');
  if (capteurs.length) {
    h += `<div class="cx-bandeau cx-bandeau-info">${lk('satellite-dish')} ${_cxT(`<strong>${capteurs.length} capteur(s) LoRaWAN</strong> non déclaré(s) cherchent à rejoindre le réseau :`,
                                                     `<strong>${_cxN(capteurs.length, `LoRaWAN sensor`, `LoRaWAN sensors`)}</strong> not declared are trying to join:`)}
      ${capteurs.slice(0, 6).map(i => `<code>${escHtml(i.id)}</code> (${_cxDate(i.quand)}, ${escHtml(String(i.rssi))} dBm)
        — <a href="#" onclick="event.preventDefault();_cxFormConnexion(0, 'lorawan', {identifiant: ${jsArg(i.id)}, nom: ${jsArg(_cxT('Capteur ', 'Sensor ') + i.id.slice(-6))}${i.join_eui && !/^0+$/.test(i.join_eui) ? ', parametres: {join_eui: ' + jsArg(i.join_eui) + '}' : ''}})">${_cxT(`le déclarer`, `declare it`)}</a>`).join(' · ')}
      <div class="cx-aide">${_cxT(`Il faudra son AppKey (fiche ou étiquette du capteur, ou son fournisseur).`, `You will need its AppKey (sensor sheet or label, or its supplier).`)}</div></div>`;
  }
  if (!pas.length) {
    z.innerHTML = h + `<div class="card cx-ferme"><div class="cx-ferme-icone">${lk('plug')}</div><h3>Raccorder un premier équipement</h3>
      <p class="cx-aide">${_cxT(`Laissez Larka chercher : la détection parcourt le réseau interne et reconnaît automates BACnet, compteurs Modbus, brokers MQTT, superviseurs, dossiers d'exports et bornes.`,
                                `Let Larka look: detection scans the internal network and recognises BACnet controllers, Modbus meters, MQTT brokers, supervisors, export folders and chargers.`)}</p>
      <p><button class="btn btn-primary" onclick="_cxDetection()">${_cxT(`${lk('satellite')} Détecter les équipements`, `${lk('satellite')} Detect devices`)}</button></p>
      <p class="cx-aide">Choisissez comment l'équipement communique. Le test de connexion vérifie le réseau (pare-feu compris) avant tout enregistrement.</p>
      ${_cxChoixProtocoles()}</div>`;
    return;
  }
  h += `<div class="card"><div class="table-wrap"><table><thead><tr>
      <th>Connexion</th><th>Adresse</th><th>État</th><th>Points</th><th>Dernier échange</th><th>Actions</th>
    </tr></thead><tbody>
    ${_cxLignesParBatiment(pas, pa => pa.BatimentNom, 6, pa => {
      const d = CxUI.catalogue[pa.Protocole] || {};
      const et = _cxEtatConnexion(pa);
      const ocpp = pa.Protocole === 'ocpp';
      const hs = _cxEnHotspot(pa);
      const dernier = d.collecte === 'entrant' ? pa.DernierContact : pa.DerniereCollecte;
      return `<tr>
        <td><div class="cx-cellule-nom"><span aria-hidden="true">${CX_PROTO_ICONE[pa.Protocole] || '•'}</span>
          <div><div>${escHtml(pa.Nom)}</div><div class="cx-aide"><span>${escHtml(d.libelle || pa.Protocole)}</span>${pa.BatimentNom ? ' · ' + escHtml(pa.BatimentNom) : ''}</div></div></div></td>
        <td><code class="cx-code cx-code-ligne">${escHtml(_cxAdresseConnexion(pa))}</code></td>
        <td><span class="badge badge-${et.classe}">${et.libelle}</span>${+pa.NbAlarmes ? ` <span class="badge badge-red">${lk('bell')} ${pa.NbAlarmes}</span>` : ''}
          ${pa.DerniereErreur && pa.Etat !== 'ok' && +pa.Actif ? `<div class="cx-aide cx-erreur-texte" title="${escHtml(pa.DerniereErreur)}">${pa.CodeErreur ? codeErreurBadge(pa.CodeErreur) + ' ' : ''}${escHtml(pa.DerniereErreur.length > 90 ? pa.DerniereErreur.slice(0, 90) + '…' : pa.DerniereErreur)}</div>` : ''}</td>
        <td><a href="#" onclick="event.preventDefault();CxUI.filtrePa='${pa.Id}';_cxOnglet('points')">${+pa.NbPoints || 0}</a></td>
        <td>${dernier ? _cxDepuis(dernier) : `<span class="cx-aide">${_cxT(`jamais`, `never`)}</span>`}${hs ? `<div class="cx-aide">${_cxEnDirect(pa) ? lk('link') : lk('wifi')} ${_cxT(`par un PC releveur`, `by a reader PC`)}</div>` : (!ocpp ? `<div class="cx-aide">${_cxRythme(pa.IntervalleSecondes)}</div>` : '')}</td>
        <td><div class="actions-col cx-actions">
          <button class="icon-btn" aria-label="Modifier" data-cx-info="action.modifier" onclick="_cxFormConnexion(${pa.Id})">${lk('pencil')}</button>
          ${hs ? `<button class="icon-btn" aria-label="${escHtml(_cxT(`PC releveurs`, `Reader PCs`))}" data-cx-info="action.hotspot" onclick="_cxOnglet('hotspots')">${_cxEnDirect(pa) ? lk('link') : lk('wifi')}</button>` : `
          <button class="icon-btn" aria-label="Tester" data-cx-info="action.tester" onclick="_cxTester(${pa.Id})">${lk('flask-conical')}</button>
          ${d.decouverte ? `<button class="icon-btn" aria-label="Découvrir" data-cx-info="action.decouvrir" onclick="_cxDecouvrir(${pa.Id})">${lk('search')}</button>` : ''}
          ${pa.Protocole === 'modbus' ? `<button class="icon-btn" aria-label="Explorer" data-cx-info="action.explorer" onclick="_cxExplorer(${pa.Id})">${lk('compass')}</button>` : ''}
          ${!ocpp && +pa.Actif ? `<button class="icon-btn" aria-label="Lire maintenant" data-cx-info="action.lire" onclick="_cxLireMaintenant(${pa.Id})">↻</button>` : ''}`}
          ${ocpp ? `<button class="icon-btn" aria-label="Commandes" data-cx-info="action.commandes" onclick="_cxCommandesBorne(${pa.Id})">${lk('zap')}</button>` : ''}
          <button class="icon-btn delete" aria-label="Supprimer" data-cx-info="action.supprimer" onclick="_cxSupprimerConnexion(${pa.Id})">${lk('trash')}</button>
        </div></td>
      </tr>`;
    }, _cxResumeConnexions)}
    </tbody></table></div></div>`;
  z.innerHTML = h;
}

function _cxChoixProtocoles() {
  return `<div class="cx-protos">${Object.values(CxUI.catalogue).filter(d => !d.hors_reseau_seulement).map(d => `
    <button type="button" class="cx-proto-choix" onclick="_cxFormConnexion(0, ${jsArg(d.protocole)})">
      <span class="cx-proto-choix-icone" aria-hidden="true">${CX_PROTO_ICONE[d.protocole] || '•'}</span>
      <b>${escHtml(d.libelle)}</b><span>${escHtml(d.resume || '')}</span>
    </button>`).join('')}</div>`;
}

async function _cxNouvelleConnexion() {
  try { await _cxEtat(); } catch (err) { toast(err.message, 'error'); return; }
  openModal(_cxT(`Nouvelle connexion — comment l'équipement communique-t-il ?`, `New connection — how does the device communicate?`),
    `<p class="cx-aide" style="margin-top:0">${_cxT(`La documentation de l'équipement (ou l'intégrateur de la GTB) l'indique. Une GTB récente propose souvent plusieurs voies : préférez BACnet/IP ou l'API, qui décrivent elles-mêmes leurs points.`,
                                                    `The device documentation (or the BMS integrator) tells you. A recent BMS often offers several ways: prefer BACnet/IP or the API, which describe their own points.`)}</p>
    <button type="button" class="cx-proto-choix cx-detection-choix" onclick="_cxDetection()">
      <span class="cx-proto-choix-icone" aria-hidden="true">${lk('satellite')}</span>
      <b>${_cxT(`Détection automatique`, `Automatic detection`)}</b>
      <span>${_cxT(`Vous ne savez pas ? Larka parcourt le réseau et reconnaît lui-même le protocole de chaque équipement.`,
                   `Not sure? Larka scans the network and recognises each device's protocol by itself.`)}</span>
    </button>${_cxChoixProtocoles()}`,
    null, 'Enregistrer', 'Annuler', '760px');
}

// ── Auto-détection ──────────────────────────────────────────────────────────
//
// Une passe sur le réseau interne, tous protocoles : le serveur sonde, l'écran
// propose. Rien n'est raccordé sans être coché ; chaque connexion créée passe
// par la même validation qu'une saisie à la main.

const CX_DETECTABLES = [
  ['modbus', `Modbus TCP`, true], ['bacnet', `BACnet/IP`, true], ['mqtt', `MQTT`, true],
  ['rest', `API / interface web`, true], ['csv', `Dossiers CSV`, false], ['ocpp', `Bornes refusées`, false],
];

/**
 * Détection. « cible » : l'Id d'un bâtiment, 'tous' (les réseaux de tous les
 * bâtiments) ou rien (choix par défaut : tous les bâtiments s'ils ont des
 * réseaux, sinon une plage à saisir).
 */
async function _cxDetection(cible) {
  let info;
  try { await _cxEtat(); info = await apiRequest('connect_detection'); } catch (err) { toast(err.message, 'error'); return; }
  const bats = await _cxRef('batiments');
  let plage = '';
  try { plage = localStorage.getItem('cx_detection_plage') || ''; } catch (_) { plage = ''; }
  const sugg = (info.suggestions || []);
  if (!plage && sugg.length) plage = sugg[0].plage;
  const batsReseau = info.batiments || [];
  const avecReseau = batsReseau.filter(b => (b.Plages || []).length);
  let ou = cible === undefined || cible === null || cible === '' ? (avecReseau.length ? 'tous' : 'autres') : String(cible);
  if (ou !== 'tous' && ou !== 'autres' && !batsReseau.some(b => String(b.Id) === ou)) ou = avecReseau.length ? 'tous' : 'autres';
  CxUI.detection = { info, bats, trouves: [] };
  const ports = info.ports || {};
  const excl = info.exclusions || {};
  const nbExcl = (excl.appareils || []).length + (excl.plages ? 1 : 0);
  const optionsOu = (avecReseau.length ? `<option value="tous"${ou === 'tous' ? ' selected' : ''}>${escHtml(_cxT(`Tous les bâtiments (${avecReseau.length} avec un réseau)`, `All buildings (${avecReseau.length} with a network)`))}</option>` : '')
    + batsReseau.map(b => `<option value="${b.Id}"${String(b.Id) === ou ? ' selected' : ''}>${escHtml(b.Nom + ((b.Plages || []).length ? '' : ' — ' + _cxT(`aucun réseau`, `no network`)))}</option>`).join('')
    + `<option value="autres"${ou === 'autres' ? ' selected' : ''}>${escHtml(_cxT(`Autres adresses (à saisir)…`, `Other addresses (to type)…`))}</option>`;
  const corps = `
    <div class="cx-form cx-detection">
      <p class="cx-aide" style="margin-top:0">${_cxT(`Larka parcourt le réseau interne et reconnaît ce qu'il sait raccorder. Il ne fait que lire (poignée de main, Who-Is, page d'accueil) : rien n'est écrit, aucun mot de passe n'est essayé.`,
                                                      `Larka scans the internal network and recognises what it can connect. It only reads (handshake, Who-Is, home page): nothing is written, no password is tried.`)}</p>
      <div class="form-group"><label class="form-label" for="cxdOu">${_cxT(`Où chercher ?`, `Where to look?`)} ${_cxBulle('det.ou')}</label>
        <select id="cxdOu" class="form-control" onchange="_cxDetectionOu()">${optionsOu}</select>
        <div class="cx-aide" id="cxdOuAide"></div></div>
      <div class="form-group" id="cxdPlageGroupe"><label class="form-label" for="cxdPlage">${_cxT(`Adresses à parcourir`, `Addresses to scan`)} ${_cxBulle('det.plage')}</label>
        <input id="cxdPlage" class="form-control" value="${escHtml(plage)}" spellcheck="false" autocomplete="off" placeholder="192.168.20.0/24, 10.0.5.10-60, 10.0.6.7">
        <div class="cx-aide">${_cxT(`Une plage (/24 = 254 adresses), un intervalle, des adresses ou des noms, séparés par des virgules ; ${info.max_adresses || 1024} adresses au plus.`,
                                    `A range (/24 = 254 addresses), an interval, addresses or names, separated by commas; ${info.max_adresses || 1024} addresses at most.`)}
          ${sugg.length ? `<br>${_cxT(`Réseaux de ce serveur :`, `This server's networks:`)} ${sugg.map(x => `<a href="#" onclick="event.preventDefault();document.getElementById('cxdPlage').value=${jsArg(x.plage)}">${escHtml(x.plage)}</a>${x.interface ? ` <span>(${escHtml(x.interface)})</span>` : ''}`).join(' · ')}` : ''}</div></div>
      <div class="form-group"><span class="form-label">${_cxT(`Rechercher`, `Look for`)} ${_cxBulle('det.protocoles')}</span>
        <div class="cx-jours">${CX_DETECTABLES.map(([k, l, reseau]) => `<label class="cx-jour"><input type="checkbox" class="cx-det-proto" value="${k}" checked> ${CX_PROTO_ICONE[k] || ''} ${escHtml(_cxTr(l))}${reseau && (ports[k] || []).length ? ` <small class="cx-aide">${escHtml((ports[k] || []).join(', '))}</small>` : ''}</label>`).join('')}</div>
        ${info.sockets === false ? `<div class="cx-aide">${_cxT(`BACnet : l'extension PHP « sockets » manque, les automates ne sont interrogés qu'un par un (pas de diffusion).`, `BACnet: the PHP “sockets” extension is missing, controllers are only queried one by one (no broadcast).`)}</div>` : ''}</div>
      <details class="cx-avance"><summary>${_cxT(`Ports particuliers`, `Custom ports`)} ${_cxBulle('det.ports')}</summary>
        <div class="form-grid cx-grille-4" style="margin-top:8px">
          ${['modbus', 'bacnet', 'mqtt', 'rest'].map(k => `<div class="form-group"><label class="form-label" for="cxdPort_${k}">${escHtml(_cxTr((CX_DETECTABLES.find(x => x[0] === k) || [])[1] || k))}</label>
            <input id="cxdPort_${k}" class="form-control" value="${escHtml((ports[k] || []).join(', '))}" spellcheck="false"></div>`).join('')}
        </div>
        <div class="cx-aide">${_cxT(`Vide : ports habituels. Utile pour une passerelle Modbus sur 503, un superviseur sur 8081…`, `Empty: usual ports. Useful for a Modbus gateway on 503, a supervisor on 8081…`)}</div>
      </details>
      <details class="cx-avance" id="cxdExclusions"><summary>${_cxT(`Exclusions`, `Exclusions`)}${nbExcl ? ` (${nbExcl})` : ''} ${_cxBulle('det.exclusions')}</summary>
        <div class="form-group" style="margin-top:8px"><label class="form-label" for="cxdExclues">${_cxT(`Adresses à ne jamais parcourir`, `Addresses never to scan`)}</label>
          <div class="cx-ligne-champ"><input id="cxdExclues" class="form-control" value="${escHtml(excl.plages || '')}" spellcheck="false" placeholder="192.168.1.100-199">
            <button type="button" class="btn btn-ghost btn-sm" onclick="_cxEnregistrerExclues(this)">${_cxT(`Enregistrer`, `Save`)}</button></div>
          <div class="cx-aide">${_cxT(`Par exemple la plage des postes utilisateurs (DHCP), de la téléphonie ou du Wi-Fi invités : ces adresses ne sont jamais sondées.`,
                                      `For example the range of user workstations (DHCP), telephony or guest Wi-Fi: these addresses are never probed.`)}</div></div>
        <div id="cxdIgnores">${_cxListeIgnores(excl.appareils || [])}</div>
      </details>
      <div class="cx-test-zone">
        <button type="button" class="btn btn-primary" id="cxdLancer" onclick="_cxDetecter(this)">${_cxT(`${lk('satellite')} Lancer la détection`, `${lk('satellite')} Start detection`)}</button>
        <span class="cx-aide">${_cxT(`Un /24 se parcourt en une demi-minute environ ; l'application reste utilisable pendant ce temps.`, `A /24 takes about half a minute; the application stays usable meanwhile.`)}</span>
      </div>
      <div id="cxdResultats"></div>
    </div>`;
  openModal(_cxT(`Détecter les équipements`, `Detect devices`), corps, null, 'Enregistrer', 'Fermer', '1060px');
  _cxDetectionOu();
}

/** « Où chercher ? » : un bâtiment ou tous (leurs réseaux), ou une plage saisie. */
function _cxDetectionOu() {
  const ou = gv('cxdOu');
  const grp = document.getElementById('cxdPlageGroupe');
  const aide = document.getElementById('cxdOuAide');
  if (grp) grp.hidden = ou !== 'autres';
  if (!aide) return;
  const bats = ((CxUI.detection || {}).info || {}).batiments || [];
  const plages = b => (b.Plages || []).map(p => p.texte + (p.origine === 'appris' ? ' (' + _cxT(`appris`, `learned`) + ')' : '')).join(', ');
  if (ou === 'autres') {
    aide.textContent = _cxT(`Les équipements trouvés seront proposés dans le bâtiment dont ils portent l'adresse, ou deviné d'après leur nom.`,
                            `Devices found will be suggested in the building whose address they have, or guessed from their name.`);
  } else if (ou === 'tous') {
    const avec = bats.filter(b => (b.Plages || []).length);
    aide.textContent = _cxT(`Parcourt ${avec.map(b => b.Nom + ' (' + plages(b) + ')').join(', ')}. Chaque équipement trouvé va dans son bâtiment.`,
                            `Scans ${avec.map(b => b.Nom + ' (' + plages(b) + ')').join(', ')}. Each device found goes to its building.`);
  } else {
    const b = bats.find(x => String(x.Id) === ou) || {};
    aide.innerHTML = (b.Plages || []).length
      ? escHtml(_cxT(`Parcourt ${plages(b)}. Ce qui est trouvé est proposé dans ce bâtiment.`, `Scans ${plages(b)}. What is found is suggested in this building.`))
      : escHtml(_cxT(`Ce bâtiment n'a pas encore de réseau.`, `This building has no network yet.`))
        + ` <a href="#" onclick="event.preventDefault();CxUI.onglet='batiments';closeModal();navigate('connect_config')">${escHtml(_cxT(`Indiquer ses adresses`, `Set its addresses`))}</a>`
        + escHtml(_cxT(`, ou choisissez « Autres adresses ».`, `, or choose “Other addresses”.`));
  }
}

async function _cxDetecter(btn) {
  const z = document.getElementById('cxdResultats');
  if (!z) return;
  const ou = gv('cxdOu') || 'autres';
  const plage = gv('cxdPlage').trim();
  const protocoles = [...document.querySelectorAll('.cx-det-proto:checked')].map(c => c.value);
  if (!protocoles.length) { toast(_cxT(`Choisissez au moins un protocole.`, `Choose at least one protocol.`), 'error'); return; }
  const ports = {};
  ['modbus', 'bacnet', 'mqtt', 'rest'].forEach(k => {
    const v = (gv('cxdPort_' + k) || '').split(/[\s,;]+/).map(x => parseInt(x, 10)).filter(x => x > 0 && x < 65536);
    if (v.length) ports[k] = v;
  });
  if (ou === 'autres') { try { localStorage.setItem('cx_detection_plage', plage); } catch (_) { /* rien */ } }
  z.innerHTML = `<div class="cx-aide" role="status" style="margin-top:12px">${ou === 'tous'
    ? _cxT(`${lk('hourglass')} Parcours des réseaux des bâtiments… (jusqu'à trois minutes)`, `${lk('hourglass')} Scanning the buildings' networks… (up to three minutes)`)
    : _cxT(`${lk('hourglass')} Parcours du réseau… (jusqu'à une minute et demie pour une grande plage)`, `${lk('hourglass')} Scanning the network… (up to a minute and a half for a large range)`)}</div>`;
  if (btn) btn.disabled = true;
  let r;
  try {
    r = await _cxApi('connect_detecter', 'POST', ou === 'autres' ? { plage, protocoles, ports } : { batiment: ou, protocoles, ports }, 210000);
  } catch (err) {
    z.innerHTML = `<div class="cx-test-verdict ko">✕ ${escHtml(err.message)}</div>`;
    return;
  } finally {
    if (btn) btn.disabled = false;
  }
  CxUI.detection.trouves = r.equipements || [];
  _cxPeindreDetection(r);
}

function _cxAdresseDetectee(e) {
  if (e.protocole === 'csv') return (e.parametres || {}).dossier || '';
  if (e.protocole === 'ocpp') return e.identifiant + (e.hote ? ' · ' + e.hote : '');
  if (e.protocole === 'rest') return (e.parametres || {}).url_base || e.hote;
  return e.hote + (e.port ? ':' + e.port : '') + (e.identifiant ? ' · n° ' + e.identifiant : '');
}

function _cxPeindreDetection(r) {
  const z = document.getElementById('cxdResultats');
  if (!z) return;
  if (r) CxUI.detection.r = r; else r = CxUI.detection.r || {};
  const liste = CxUI.detection.trouves;
  const bats = CxUI.detection.bats || [];
  const stats = _cxT(`${r.adresses || 0} adresse(s) parcourue(s), ${r.ports_ouverts || 0} port(s) ouvert(s), en ${_cxNombre(r.duree, 1)} s.`,
                     `${_cxN(r.adresses || 0, `address`, `addresses`)} scanned, ${_cxN(r.ports_ouverts || 0, `open port`, `open ports`)}, in ${_cxNombre(r.duree, 1)} s.`)
    + (r.adresses_exclues ? ' ' + _cxT(`${r.adresses_exclues} adresse(s) jamais parcourue(s) (exclusions).`, `${_cxN(r.adresses_exclues, `address`, `addresses`)} never scanned (exclusions).`) : '');
  if (!liste.length) {
    z.innerHTML = `<div class="cx-test-verdict moyen" style="margin-top:12px">${_cxT(`Aucun équipement reconnu.`, `No device recognised.`)} <span class="cx-aide">${stats}</span></div>
      <p class="cx-aide">${_cxT(`Un pare-feu entre ce serveur et les équipements donne ce résultat : le test d'une connexion saisie à la main (${lk('flask-conical')}) rédige alors la demande à transmettre au service informatique.`,
                                `A firewall between this server and the devices gives this result: testing a manually entered connection (${lk('flask-conical')}) then drafts the request to send to IT.`)}</p>`;
    return;
  }
  // Trois groupes : à raccorder, écartés d'office (PC, imprimantes, pages web…), ignorés pour de bon.
  const idx = liste.map((e, i) => i);
  const principaux = idx.filter(i => !liste[i].ignore && (!liste[i].ecarte || liste[i]._propose));
  const ecartes = idx.filter(i => !liste[i].ignore && liste[i].ecarte && !liste[i]._propose);
  const ignores = idx.filter(i => liste[i].ignore);
  const nouveaux = principaux.filter(i => !liste[i].deja).length;
  const optBat = sel => `<option value="">—</option>` + bats.map(b => `<option value="${b.Id}"${String(b.Id) === String(sel ?? '') ? ' selected' : ''}>${escHtml(b.Nom)}</option>`).join('');
  const confiance = { sure: [_cxT(`reconnu`, `recognised`), 'teal'], probable: [_cxT(`probable`, `likely`), 'blue'], possible: [_cxT(`à vérifier`, `to check`), 'gray'] };
  const pourquoi = { reseau: _cxT(`réseau du bâtiment`, `building network`), appris: _cxT(`réseau appris`, `learned network`),
                     nom: _cxT(`d'après le nom`, `from the name`), cible: _cxT(`bâtiment parcouru`, `scanned building`) };
  const machine = { windows: 'PC Windows', imprimante: lk('printer'), nas: lk('archive'), box: lk('satellite-dish'), camera: lk('camera'), mobile: lk('smartphone'), mac: lk('laptop') };
  const raisons = { windows: _cxT(`PC Windows sans protocole technique : un poste de travail, sans doute`, `Windows PC without a technical protocol: a workstation, most likely`),
                    imprimante: _cxT(`imprimante`, `printer`), nas: _cxT(`serveur de fichiers (NAS)`, `file server (NAS)`),
                    box: _cxT(`box, routeur, pare-feu ou point d'accès Wi-Fi`, `router, firewall or Wi-Fi access point`), camera: _cxT(`caméra ou enregistreur vidéo`, `camera or video recorder`),
                    mobile: _cxT(`téléphone ou tablette`, `phone or tablet`), mac: _cxT(`ordinateur Mac`, `Mac computer`),
                    page: _cxT(`page web sans API ni indice de GTB`, `web page with no API nor BMS hint`) };
  const bilanGroupes = [ecartes.length ? _cxT(`${ecartes.length} écarté(s)`, `${ecartes.length} set aside`) : '',
                        ignores.length ? _cxT(`${ignores.length} ignoré(s)`, `${ignores.length} ignored`) : ''].filter(Boolean).join(', ');
  z.innerHTML = `
    <div class="cx-test-verdict ${principaux.length ? 'ok' : 'moyen'}" style="margin-top:12px" role="status">${_cxT(`✓ ${principaux.length} équipement(s) à raccorder, dont ${nouveaux} pas encore raccordé(s)`,
                                                                              `✓ ${_cxN(principaux.length, `device`, `devices`)} to connect, ${nouveaux} not yet connected`)}${bilanGroupes ? ' — ' + bilanGroupes : ''}. <span class="cx-aide">${stats}${r.incomplet ? ' ' + _cxT(`Temps écoulé : parcours incomplet, réduisez la plage.`, `Time is up: scan incomplete, narrow the range.`) : ''}</span></div>
    ${principaux.length ? `<div class="cx-filtres" style="margin:10px 0 8px">
      <button type="button" class="btn btn-ghost btn-sm" onclick="_cxCocherDetection(true)">Tout cocher</button>
      <button type="button" class="btn btn-ghost btn-sm" onclick="_cxCocherDetection(false)">Tout décocher</button>
      <button type="button" class="btn btn-ghost btn-sm" onclick="_cxIgnorerSelection()" data-cx-info="action.ignorer.selection">${lk('ban', 'ko')} ${_cxT(`Ignorer la sélection`, `Ignore the selection`)}</button>
      ${bats.length ? `<label class="cx-aide" for="cxdBatTous">${_cxT(`Bâtiment de la sélection :`, `Building for the selection:`)}</label>
        <select id="cxdBatTous" class="form-control cx-input-compact" style="min-width:160px" onchange="_cxBatimentDetection(this.value)">${optBat('')}</select>` : ''}
    </div>
    <div class="table-wrap cx-decouverte"><table><thead><tr><th></th><th>Équipement</th><th>Adresse</th><th>${_cxT(`Ce que Larka a reconnu`, `What Larka recognised`)} ${_cxBulle('det.confiance')}</th>${bats.length ? `<th>${_cxT(`Bâtiment`, `Building`)} ${_cxBulle('det.batiment')}</th>` : ''}<th></th></tr></thead><tbody>
    ${principaux.map(i => {
      const e = liste[i];
      const c = confiance[e.confiance] || confiance.possible;
      const coche = !e.deja && e.confiance !== 'possible' && !e._propose;
      return `<tr class="${e.deja ? 'cx-inactif' : ''}">
        <td><input type="checkbox" class="cx-det-case" id="cxdc_${i}" ${e.deja ? 'disabled' : (coche ? 'checked' : '')} aria-label="${escHtml(_cxT(`Raccorder`, `Connect`))}"></td>
        <td><div class="cx-cellule-nom"><span aria-hidden="true">${CX_PROTO_ICONE[e.protocole] || '•'}</span><div>
          <input class="form-control cx-input-compact" id="cxdn_${i}" maxlength="120" value="${escHtml(e.nom || '')}" ${e.deja ? 'disabled' : ''} style="min-width:220px">
          <div class="cx-aide">${escHtml(_cxTr((CxUI.catalogue[e.protocole] || {}).libelle || e.protocole))}${machine[e.machine] ? ' · ' + machine[e.machine] : ''}</div></div></div></td>
        <td><code class="cx-code">${escHtml(_cxAdresseDetectee(e))}</code></td>
        <td><span class="badge badge-${c[1]}">${c[0]}</span>${e.deja ? ` <span class="badge badge-gray">${_cxT(`déjà raccordé`, `already connected`)}</span>` : ''}
          <div class="cx-aide">${escHtml(e.details || '')}</div>
          ${e.identifiants_requis ? `<div class="cx-aide">${lk('key-round')} ${_cxT(`Identifiants à saisir après création (${lk('pencil')} Modifier).`, `Credentials to enter after creation (${lk('pencil')} Edit).`)}</div>` : ''}</td>
        ${bats.length ? `<td><select class="form-control cx-input-compact" id="cxdb_${i}" ${e.deja ? 'disabled' : ''}>${optBat(e.batimentId)}</select>
          ${e.batimentId && pourquoi[e.batimentPar] ? `<div class="cx-aide">${pourquoi[e.batimentPar]}</div>` : ''}</td>` : ''}
        <td>${e.deja ? '' : `<button type="button" class="icon-btn" aria-label="${escHtml(_cxT(`Ignorer`, `Ignore`))}" data-cx-info="action.ignorer" onclick="_cxIgnorer([${i}], false)">${lk('ban', 'ko')}</button>`}</td>
      </tr>`;
    }).join('')}
    </tbody></table></div>
    <label class="cx-case" style="margin-top:10px"><input type="checkbox" id="cxdPoints" checked> ${_cxT(`Découvrir et ajouter aussitôt les points des équipements qui savent les lister (BACnet, MQTT, API, CSV)`,
                                                                                                         `Discover and add right away the points of devices that can list them (BACnet, MQTT, API, CSV)`)}</label>
    <div class="cx-aide">${_cxT(`Modbus ne liste pas ses registres : les points s'ajoutent ensuite (${lk('compass')} Explorer aide à les trouver). Les connexions créées sont actives : la collecte commence au passage suivant.`,
                                `Modbus does not list its registers: points are added afterwards (${lk('compass')} Explore helps to find them). Created connections are active: collection starts on the next run.`)}</div>
    <div class="cx-test-zone"><button type="button" class="btn btn-primary" onclick="_cxRaccorderDetection(this)">${_cxT(`Raccorder la sélection`, `Connect the selection`)}</button>
      <span id="cxdBilan"></span></div>` : ''}
    ${ecartes.length ? `<details class="cx-avance cx-det-groupe"${principaux.length ? '' : ' open'}><summary>${_cxT(`Écartés d'office (${ecartes.length}) : PC, imprimantes, box, pages web…`, `Set aside (${ecartes.length}): PCs, printers, routers, web pages…`)} ${_cxBulle('det.ecartes')}</summary>
      <div class="table-wrap"><table><thead><tr><th>${_cxT(`Appareil`, `Device`)}</th><th>${_cxT(`Adresse`, `Address`)}</th><th>${_cxT(`Pourquoi`, `Why`)}</th><th></th></tr></thead><tbody>
      ${ecartes.map(i => { const e = liste[i]; return `<tr>
        <td>${machine[e.machine] || CX_PROTO_ICONE[e.protocole] || '•'} ${escHtml(e.nom || '')}</td>
        <td><code class="cx-code">${escHtml(_cxAdresseDetectee(e))}</code></td>
        <td class="cx-aide"><strong>${escHtml(raisons[e.machine] && e.machine !== 'windows' ? raisons[e.machine] : (e.machine === 'windows' ? raisons.windows : raisons.page))}</strong><div>${escHtml(e.details || '')}</div></td>
        <td><div class="actions-col cx-actions-releve">
          <button type="button" class="btn btn-ghost btn-sm" onclick="_cxProposer(${i})" data-cx-info="action.proposer">${_cxT(`Proposer quand même`, `Suggest anyway`)}</button>
          <button type="button" class="btn btn-ghost btn-sm" onclick="_cxIgnorer([${i}], true)" data-cx-info="action.ignorer.machine">${lk('ban', 'ko')} ${_cxT(`Ignorer la machine`, `Ignore the machine`)}</button>
        </div></td></tr>`; }).join('')}
      </tbody></table></div></details>` : ''}
    ${ignores.length ? `<details class="cx-avance cx-det-groupe"><summary>${_cxT(`Ignorés (${ignores.length})`, `Ignored (${ignores.length})`)}</summary>
      <ul class="cx-liste-bilan">${ignores.map(i => { const e = liste[i]; return `<li>${escHtml(e.nom || '')} <code class="cx-code">${escHtml(_cxAdresseDetectee(e))}</code>
        <a href="#" onclick="event.preventDefault();_cxNePlusIgnorer(${i})">${_cxT(`ne plus ignorer`, `stop ignoring`)}</a></li>`; }).join('')}</ul></details>` : ''}`;
}

function _cxListeIgnores(liste) {
  if (!liste.length) return `<div class="cx-aide">${_cxT(`Aucun appareil ignoré. Le bouton ${lk('ban', 'ko')} d'un résultat l'ajoute ici.`, `No ignored device. A result's ${lk('ban', 'ko')} button adds it here.`)}</div>`;
  return `<div class="form-label" style="margin-top:4px">${_cxT(`Appareils et machines ignorés`, `Ignored devices and machines`)}</div>
    <ul class="cx-liste-bilan">${liste.map(x => `<li>${x.Type === 'machine' ? (lk('monitor') + ' ') : ''}${escHtml(x.Nom || '')} <code class="cx-code">${escHtml(x.Valeur)}</code>
      <a href="#" onclick="event.preventDefault();_cxRetirerIgnore(${+x.Id})">${_cxT(`ne plus ignorer`, `stop ignoring`)}</a></li>`).join('')}</ul>
    <div class="cx-aide">${_cxT(`Un PC à adresse dynamique change d'adresse : pour les postes, préférez les « adresses à ne jamais parcourir ».`,
                                `A PC with a dynamic address changes address: for workstations, prefer “addresses never to scan”.`)}</div>`;
}

async function _cxEnregistrerExclues(btn) {
  let r;
  if (btn) btn.disabled = true;
  try { r = await _cxApi('connect_exclusions', 'POST', { op: 'plages', plages: gv('cxdExclues') }); }
  catch (err) { toast(err.message, 'error'); return; }
  finally { if (btn) btn.disabled = false; }
  const el = document.getElementById('cxdExclues');
  if (el) el.value = r.plages || '';
  if (CxUI.detection && CxUI.detection.info) CxUI.detection.info.exclusions = Object.assign({}, CxUI.detection.info.exclusions, { plages: r.plages || '' });
  toast(_cxT(`Exclusions enregistrées.`, `Exclusions saved.`), 'success');
}

async function _cxRetirerIgnore(id) {
  try { await _cxApi('connect_exclusions', 'POST', { op: 'retirer', id }); } catch (err) { toast(err.message, 'error'); return; }
  const ex = ((CxUI.detection || {}).info || {}).exclusions || {};
  ex.appareils = (ex.appareils || []).filter(x => +x.Id !== +id);
  const z = document.getElementById('cxdIgnores');
  if (z) z.innerHTML = _cxListeIgnores(ex.appareils);
  ((CxUI.detection || {}).trouves || []).forEach(x => { if (+x.ignore === +id) x.ignore = null; });
  if (document.getElementById('cxdResultats') && (CxUI.detection.trouves || []).length) _cxPeindreDetection();
}

/** Remet un résultat écarté dans la liste à raccorder (non coché). */
function _cxProposer(i) {
  const e = (CxUI.detection.trouves || [])[i];
  if (!e) return;
  e._propose = true;
  _cxPeindreDetection();
}

/** Ignore des résultats (un appareil, ou toute la machine) : la détection ne les proposera plus. */
async function _cxIgnorer(indices, machine) {
  const liste = CxUI.detection.trouves || [];
  const choisis = indices.map(i => liste[i]).filter(Boolean);
  if (!choisis.length) return;
  let r;
  try {
    r = await _cxApi('connect_exclusions', 'POST', { op: 'ignorer', appareils: choisis.map(e => ({ protocole: e.protocole, hote: e.hote, port: e.port, identifiant: e.identifiant,
                                                                                                 parametres: e.parametres || {}, nom: e.nom, machine: !!machine })) });
  } catch (err) { toast(err.message, 'error'); return; }
  const ex = (CxUI.detection.info || {}).exclusions || {};
  ex.appareils = ex.appareils || [];
  choisis.forEach((e, k) => {
    const id = (r.ids || [])[k];
    if (!id) return;
    if (!ex.appareils.some(x => +x.Id === +id)) {
      ex.appareils.unshift({ Id: id, Type: machine ? 'machine' : 'appareil', Nom: e.nom || '',
                             Valeur: machine ? e.hote : (e.protocole === 'csv' ? 'csv|' + ((e.parametres || {}).dossier || '') : (e.protocole === 'ocpp' ? 'ocpp|' + e.identifiant : `${e.protocole}|${e.hote}|${e.port}`)) });
    }
    // Une machine ignorée emporte tout ce qu'elle expose.
    liste.forEach(x => { if (x === e || (machine && x.hote && x.hote === e.hote)) x.ignore = id; });
  });
  toast(_cxT(`Ignoré : la détection ne le proposera plus.`, `Ignored: detection will not suggest it again.`), 'success');
  const zi = document.getElementById('cxdIgnores');
  if (zi) zi.innerHTML = _cxListeIgnores(ex.appareils);
  _cxPeindreDetection();
}

function _cxIgnorerSelection() {
  const liste = CxUI.detection.trouves || [];
  const indices = liste.map((e, i) => i).filter(i => { const c = document.getElementById('cxdc_' + i); return c && c.checked && !c.disabled; });
  if (!indices.length) { toast(_cxT(`Aucun équipement coché.`, `No device ticked.`), 'error'); return; }
  _cxIgnorer(indices, false);
}

async function _cxNePlusIgnorer(i) {
  const e = (CxUI.detection.trouves || [])[i];
  if (!e || !e.ignore) return;
  try { await _cxApi('connect_exclusions', 'POST', { op: 'retirer', id: e.ignore }); } catch (err) { toast(err.message, 'error'); return; }
  const id = e.ignore;
  (CxUI.detection.trouves || []).forEach(x => { if (x.ignore === id) x.ignore = null; });
  _cxPeindreDetection();
}

function _cxCocherDetection(v) {
  document.querySelectorAll('.cx-det-case').forEach(c => { if (!c.disabled) c.checked = v; });
}

function _cxBatimentDetection(id) {
  (CxUI.detection.trouves || []).forEach((e, i) => {
    const c = document.getElementById('cxdc_' + i), s = document.getElementById('cxdb_' + i);
    if (c && c.checked && s && !s.disabled) s.value = id;
  });
}

async function _cxRaccorderDetection(btn) {
  const liste = CxUI.detection.trouves || [];
  const equipements = [];
  liste.forEach((e, i) => {
    const c = document.getElementById('cxdc_' + i);
    if (!c || !c.checked || c.disabled) return;
    equipements.push({ protocole: e.protocole, nom: gv('cxdn_' + i).trim() || e.nom, hote: e.hote, port: e.port, identifiant: e.identifiant,
                       parametres: e.parametres || {}, details: e.details || '', batimentId: document.getElementById('cxdb_' + i) ? gv('cxdb_' + i) || null : null });
  });
  if (!equipements.length) { toast(_cxT(`Aucun équipement coché.`, `No device ticked.`), 'error'); return; }
  const bilan = document.getElementById('cxdBilan');
  if (bilan) bilan.innerHTML = `<span class="cx-aide" role="status">${_cxT(`${lk('hourglass')} Création des connexions et découverte des points…`, `${lk('hourglass')} Creating connections and discovering points…`)}</span>`;
  if (btn) btn.disabled = true;
  let r;
  try {
    r = await _cxApi('connect_detection_creer', 'POST', { equipements, decouvrirPoints: !!(document.getElementById('cxdPoints') || {}).checked }, 210000);
  } catch (err) {
    if (bilan) bilan.innerHTML = `<span class="cx-erreur-texte">✕ ${escHtml(err.message)}</span>`;
    if (btn) btn.disabled = false;
    return;
  }
  CxUI.etatLu = 0;
  // Les bâtiments qui reçoivent les nouvelles connexions se déplient : elles doivent se voir.
  CxBat.charger();
  equipements.forEach(e => {
    const bat = (CxUI.detection.bats || []).find(b => String(b.Id) === String(e.batimentId ?? ''));
    CxBat.replies.delete(_cxCleBat(bat ? bat.Nom : ''));
  });
  CxBat.garder();
  const lignes = (r.connexions || []).map(x => `<li>${x.id ? '✓' : '✕'} <strong>${escHtml(x.nom)}</strong>${x.id && x.points ? ' — ' + _cxN(x.points, `point`, `points`) : ''}${x.erreur ? ` <span class="cx-aide">— ${escHtml(x.erreur)}</span>` : ''}</li>`).join('');
  openModal(_cxT(`Détection — connexions créées`, `Detection — connections created`),
    `<div class="cx-test-verdict ${r.creees ? 'ok' : 'ko'}">${_cxT(`${r.creees} connexion(s) créée(s).`, `${_cxN(r.creees, `connection`, `connections`)} created.`)}</div>
     <ul class="cx-liste-bilan">${lignes}</ul>
     <p class="cx-aide">${_cxT(`Testez chacune (${lk('flask-conical')}) : le test dit ce qui bloque encore (identifiants, pare-feu). Les points se règlent dans l'onglet Points.`,
                              `Test each one (${lk('flask-conical')}): the test tells what still blocks (credentials, firewall). Points are set in the Points tab.`)}</p>`,
    null, 'Enregistrer', 'Fermer', '640px');
  if (App.currentPage === 'connect_config') { CxUI.onglet = 'connexions'; renderConnectConfig(); }
  else if (App.currentPage === 'connect') _cxChargerSupervision(true);
}

/** Borne refusée (inconnue) → formulaire prérempli avec son identifiant. */
function _cxDeclarerBorne(chemin) {
  const morceaux = String(chemin || '').split('?')[0].split('/').filter(Boolean);
  const id = morceaux.length ? decodeURIComponent(morceaux[morceaux.length - 1]) : '';
  _cxFormConnexion(0, 'ocpp', { identifiant: id, nom: _cxT(`Borne `, `Charger `) + id });
}

/** Un champ décrit par le pilote → contrôle de formulaire. */
function _cxChamp(def, valeur, prefixe) {
  const id = prefixe + def.cle;
  const v = (valeur === undefined || valeur === null) ? (def.defaut ?? '') : valeur;
  // L'astérisque à part : le libellé reste un texte seul, que le pack de langue reconnaît.
  const lab = escHtml(def.libelle) + (def.requis ? ' <span class="cx-requis" aria-hidden="true">*</span>' : '')
    + (_cxABulle('p.' + def.cle) ? ' ' + _cxBulle('p.' + def.cle) : '');
  const aide = def.aide ? `<div class="cx-aide">${escHtml(def.aide)}</div>` : '';
  switch (def.type) {
    case 'booleen':
      return `<div class="form-group"><label class="cx-case"><input type="checkbox" id="${id}" ${v === true || v === 1 || v === '1' || v === 'true' ? 'checked' : ''}> ${lab}</label>${aide}</div>`;
    case 'choix':
      return `<div class="form-group"><label class="form-label" for="${id}">${lab}</label>
        <select id="${id}" class="form-control">${_cxOptions(def.options || [], v)}</select>${aide}</div>`;
    case 'texte_long':
      return `<div class="form-group" style="grid-column:1/-1"><label class="form-label" for="${id}">${lab}</label>
        <textarea id="${id}" class="form-control" rows="3">${escHtml(v)}</textarea>${aide}</div>`;
    case 'nombre':
      return `<div class="form-group"><label class="form-label" for="${id}">${lab}</label>
        <input id="${id}" class="form-control" type="number" step="any" value="${escHtml(v)}">${aide}</div>`;
    case 'secret':
      return `<div class="form-group"><label class="form-label" for="${id}">${lab}</label>
        <input id="${id}" class="form-control" type="password" autocomplete="new-password" value="">${aide}</div>`;
    default:
      return `<div class="form-group"><label class="form-label" for="${id}">${lab}</label>
        <input id="${id}" class="form-control" type="text" value="${escHtml(v)}" spellcheck="false">${aide}</div>`;
  }
}

/**
 * LoRaWAN : ce qu'il faut régler dans CHAQUE passerelle du bâtiment, et ce que
 * Larka en voit. Les passerelles sont partagées par tous les capteurs : ce
 * réglage se fait une fois, pas capteur par capteur.
 */
function _cxReglagePasserelles() {
  const l = (CxUI.etat || {}).lorawan || {};
  const adr = escHtml(l.adresse_serveur || _cxT('adresse IP de ce serveur', 'this server IP address'));
  const gw = (l.passerelles || []).slice().sort((a, b) => String(b.vu).localeCompare(String(a.vu)));
  let h = `<div class="cx-form-section">${_cxT(`Réglage à faire dans les passerelles LoRaWAN`, `Setting to make in the LoRaWAN gateways`)}</div><div class="cx-apercu">`;
  if (!l.actif) {
    h += `${lk('triangle-exclamation-point', 'warn')} ${_cxT(`Le serveur LoRaWAN intégré est désactivé : Configuration → Serveur → Larka Connect → LoRaWAN.`,
                                                           `The built-in LoRaWAN server is disabled: Settings → Server → Larka Connect → LoRaWAN.`)}<br>`;
  } else if (!l.en_marche) {
    h += `${lk('triangle-exclamation-point', 'warn')} ${_cxT(`Le service d'écoute ne tourne pas.`, `The listening service is not running.`)} <code>php api/outils/connect-ocpp.php --etat</code><br>`;
  }
  h += _cxT(`Dans l'interface de chaque passerelle (Milesight, Kerlink, Dragino, RAK, MultiTech…), choisir le mode
      <strong>« Semtech UDP packet forwarder »</strong> (parfois « Legacy UDP » ou « LoRa Packet Forwarder ») et saisir :`,
      `In each gateway's interface (Milesight, Kerlink, Dragino, RAK, MultiTech…), choose
      <strong>“Semtech UDP packet forwarder”</strong> (sometimes “Legacy UDP” or “LoRa Packet Forwarder”) and enter:`)
    + `<ul class="cx-liste-reglage"><li>${_cxT(`Adresse du serveur`, `Server address`)} : <code>${adr}</code></li>
       <li>${_cxT(`Port montant et port descendant`, `Uplink and downlink port`)} : <code>${escHtml(String(l.port || 1700))}</code> (UDP)</li>
       <li>${_cxT(`Plan de fréquences`, `Frequency plan`)} : <code>EU868</code></li></ul>`
    + _cxT(`Le pare-feu du serveur doit laisser entrer l'UDP sur ce port. Les passerelles d'un réseau privé sont écoutées d'office ; une passerelle sur Internet doit être nommée dans la configuration.`,
           `The server firewall must let UDP in on this port. Gateways on a private network are heard by default; a gateway on the Internet must be listed in the configuration.`);
  if (l.en_marche) {
    h += '<div style="margin-top:8px">' + (gw.length
      ? `${lk('circle-check', 'ok')} ${_cxT(`Passerelles entendues :`, `Gateways heard:`)} ` + gw.slice(0, 6).map(g =>
          `<code>${escHtml(g.eui)}</code> (${escHtml(g.ip)}, ${_cxDepuis(g.vu)}${g.emission ? '' : ', ' + _cxT('ne peut pas encore répondre aux capteurs', 'cannot answer sensors yet')})`).join(' · ')
      : `${lk('triangle-exclamation-point', 'warn')} ${_cxT(`Aucune passerelle n'a encore parlé à Larka.`, `No gateway has talked to Larka yet.`)}`) + '</div>';
  }
  return h + '</div>';
}

/** Matériel radio vu par un PC releveur (collecteur 1.5 et suivants). */
function _cxMaterielPc(m) {
  const ports = (m && Array.isArray(m.ports)) ? m.ports : [];
  const radio = ports.filter(p => p.modele);
  if (!ports.length) return '';
  return `<div class="cx-aide">${lk('droplet')} ${radio.length
    ? _cxT(`Récepteur radio : `, `Radio receiver: `) + radio.map(p => `<strong>${escHtml(p.libelle)}</strong> (${escHtml(p.port)})`).join(', ')
    : _cxT(`Ports série : `, `Serial ports: `) + ports.map(p => escHtml(p.port + ' — ' + p.libelle)).join(', ')}</div>`;
}

/**
 * Compteurs radio : ce qui est branché sur CE serveur (clés USB reconnues),
 * pour choisir le port sans deviner. Sur un PC releveur, la commande
 * « releveur materiel » donne la même liste.
 */
async function _cxMaterielServeur(btn) {
  const z = document.getElementById('cxcMateriel');
  if (!z) return;
  if (btn) btn.disabled = true;
  z.innerHTML = `${lk('hourglass')} ${_cxT(`Recherche du matériel…`, `Looking for hardware…`)}`;
  try {
    const r = await apiRequest('connect_materiel') || {};
    const ports = r.ports || [];
    z.innerHTML = ports.length
      ? '<ul class="cx-liste-reglage">' + ports.map(p => `<li>${p.modele ? lk('circle-check', 'ok') : lk('square')} <code>${escHtml(p.port)}</code> — ${escHtml(p.libelle)}`
          + (p.vid ? ` <span class="cx-aide">[${escHtml(p.vid)}:${escHtml(p.pid || '')}]</span>` : '')
          + (p.modele ? ` — <a href="#" onclick="event.preventDefault();_cxChoisirPort(${jsArg(p.port)}, ${jsArg(p.modele)})">${_cxT(`utiliser`, `use`)}</a>` : '')
          + (p.conseil ? `<div class="cx-aide">${escHtml(p.conseil)}</div>` : '') + (p.acces === false ? `<div class="cx-aide cx-erreur-texte">${escHtml(r.aide_droits || '')}</div>` : '') + '</li>').join('') + '</ul>'
      : _cxT(`Aucun port série USB sur ce serveur. Clé branchée sur un PC ? Choisissez « un PC releveur » ci-dessus.`, `No USB serial port on this server. Key plugged into a PC? Choose “a reader PC” above.`);
  } catch (e) {
    z.textContent = _cxT(`Inventaire impossible : `, `Inventory failed: `) + (e.message || e);
  } finally { if (btn) btn.disabled = false; }
}

function _cxChoisirPort(port, modele) {
  const p = document.getElementById('cxcP_port'); if (p) p.value = port;
  const m = document.getElementById('cxcP_modele'); if (m && modele) m.value = modele;
  toast(_cxT(`Port choisi : `, `Port chosen: `) + port, 'success');
}

/** Protocoles qu'un PC releveur sait relever par le hotspot Wi-Fi d'un appareil. */
const CX_PROTOS_HOTSPOT = ['auto', 'modbus', 'bacnet', 'mqtt', 'rest'];

/** Accès choisi dans le formulaire : « reseau », « hotspot » ou « direct ». */
function _cxAccesChoisi() { return (document.querySelector('input[name="cxcAcces"]:checked') || {}).value || 'reseau'; }

function _cxAccesChange() {
  const a = _cxAccesChoisi();
  const hors = a !== 'reseau';
  const voir = (k, oui) => { const el = document.getElementById(k); if (el) el.hidden = !oui; };
  voir('cxcWifi', a === 'hotspot');
  voir('cxcDirect', a === 'direct');
  voir('cxcTestHotspot', hors);
  voir('cxcTestZone', !hors);
  voir('cxcIntervalleGroupe', !hors);          // hors réseau, le serveur n'interroge pas : c'est le PC qui relève
  _cxConseilIpDirect();
}

/**
 * Câble direct : l'adresse à donner au PC pour joindre l'appareil (même réseau,
 * autre numéro, sans passerelle — Internet reste sur le Wi-Fi).
 */
function _cxConseilIpDirect() {
  const z = document.getElementById('cxcIpPc');
  if (!z) return;
  let ip = (gv('cxcHote') || '').trim();
  if (!ip) { const u = gv('cxcP_url_base') || ''; const m = u.match(/^https?:\/\/([^/:]+)/i); ip = m ? m[1] : ''; }
  const m = ip.match(/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/);
  if (!m) { z.innerHTML = _cxT(`Indiquez l'adresse de l'appareil ci-dessous : Larka vous dira laquelle donner au PC.`, `Enter the device address below: Larka will tell you which one to give the PC.`); return; }
  const dernier = +m[4] === 250 ? 251 : 250;
  const pc = `${m[1]}.${m[2]}.${m[3]}.${dernier}`;
  z.innerHTML = _cxT(`Sur le PC, une fois (droits d'administrateur) : carte Ethernet → Protocole Internet version 4 → onglet <strong>Configuration alternative</strong> → adresse <code class="cx-code">${pc}</code>, masque <code class="cx-code">255.255.255.0</code>, <strong>passerelle vide</strong>. Sur le réseau habituel rien ne change ; branché sur l'appareil, le PC prend cette adresse et garde Internet par le Wi-Fi.`,
                     `On the PC, once (administrator rights): Ethernet adapter → Internet Protocol version 4 → <strong>Alternate Configuration</strong> tab → address <code class="cx-code">${pc}</code>, mask <code class="cx-code">255.255.255.0</code>, <strong>empty gateway</strong>. On the usual network nothing changes; plugged into the device, the PC takes this address and keeps Internet over Wi-Fi.`);
}

/** Section « Comment Larka joint-il l'appareil ? » du formulaire de connexion. */
function _cxSectionAcces(params, definis, pre, horsReseauSeulement) {
  const a = ['hotspot', 'direct'].includes(params.acces) ? params.acces : (['hotspot', 'direct'].includes(pre.acces) ? pre.acces : (horsReseauSeulement ? 'hotspot' : 'reseau'));
  const cleDefinie = definis.includes('wifi_cle');
  const radio = (v, libelle, aide) => `<label class="cx-acces-choix${a === v ? ' actif' : ''}"><input type="radio" name="cxcAcces" value="${v}" ${a === v ? 'checked' : ''}
      onchange="_cxAccesChange();this.closest('.cx-acces').querySelectorAll('.cx-acces-choix').forEach(l=>l.classList.toggle('actif',l.contains(this)))">
      <span><strong>${libelle}</strong><span class="cx-aide">${aide}</span></span></label>`;
  return `<div class="cx-form-section" style="margin-top:0">${_cxT(`Comment Larka joint-il l'appareil ?`, `How does Larka reach the device?`)} ${_cxBulle('cx.acces')}</div>
    <div class="cx-acces" role="radiogroup" aria-label="${escHtml(_cxT(`Accès à l'appareil`, `Access to the device`))}">
      ${horsReseauSeulement ? '' : radio('reseau', `${lk('plug')} ${_cxT(`Par le réseau`, `Through the network`)}`, _cxT(`Il est sur le réseau du bâtiment : le serveur l'interroge lui-même.`, `It is on the building network: the server polls it itself.`))}
      ${radio('hotspot', `${lk('wifi')} ${_cxT(`Par son hotspot Wi-Fi`, `Through its Wi-Fi hotspot`)}`, _cxT(`Il diffuse son propre Wi-Fi : un PC releveur s'y connecte, relève, puis revient sur son réseau.`, `It broadcasts its own Wi-Fi: a reader PC connects to it, reads, then goes back to its network.`))}
      ${radio('direct', `${lk('link')} ${_cxT(`Par câble, en direct`, `By cable, directly`)}`, _cxT(`Hors réseau : on branche un PC dessus (RJ45). Le collecteur relève quand il répond et envoie dès que le PC retrouve le réseau.`, `Off the network: a PC is plugged into it (RJ45). The collector reads when it answers and sends once the PC is back on the network.`))}
    </div>
    <div id="cxcWifi" class="form-grid" ${a === 'hotspot' ? '' : 'hidden'} style="margin-top:8px">
      <div class="form-group"><label class="form-label" for="cxcSsid">${_cxT(`Nom du Wi-Fi de l'appareil (SSID)`, `Device Wi-Fi name (SSID)`)} <span class="cx-requis" aria-hidden="true">*</span> ${_cxBulle('cx.ssid')}</label>
        <input id="cxcSsid" class="form-control" maxlength="32" value="${escHtml(params.wifi_ssid || '')}" spellcheck="false" autocomplete="off" placeholder="${escHtml(_cxT(`ex. SMA1930012345`, `e.g. SMA1930012345`))}"></div>
      <div class="form-group"><label class="form-label" for="cxcSecu">${_cxT(`Sécurité`, `Security`)}</label>
        <select id="cxcSecu" class="form-control" onchange="document.getElementById('cxcCleGroupe').hidden=this.value==='ouvert'">
          ${_cxOptions([['wpa2', 'WPA2 (clé)'], ['ouvert', _cxT(`Réseau ouvert (sans clé)`, `Open network (no key)`)]], params.wifi_securite || 'wpa2')}</select></div>
      <div class="form-group" id="cxcCleGroupe" ${(params.wifi_securite || 'wpa2') === 'ouvert' ? 'hidden' : ''}><label class="form-label" for="cxcCleWifi">${_cxT(`Clé du Wi-Fi`, `Wi-Fi key`)} ${_cxBulle('cx.cle_wifi')}</label>
        <input id="cxcCleWifi" class="form-control" type="password" autocomplete="new-password" maxlength="63" spellcheck="false"
          placeholder="${cleDefinie ? escHtml(_cxT(`•••••• définie — laisser vide pour la garder`, `•••••• set — leave empty to keep it`)) : ''}"></div>
      <div class="cx-aide" style="grid-column:1/-1">${_cxT(`L'adresse plus bas est celle de l'appareil SUR SON hotspot (souvent 192.168.4.1, voir sa notice).`, `The address below is the device's address ON ITS hotspot (often 192.168.4.1, see its manual).`)}</div>
    </div>
    <div id="cxcDirect" ${a === 'direct' ? '' : 'hidden'} style="margin-top:8px">
      <div class="cx-apercu">${lk('link')} ${_cxT(`L'adresse plus bas est celle de l'appareil (sa notice, ou son étiquette). Le PC n'a pas besoin du réseau pendant le relevé : les valeurs attendent sur lui et partent au retour.`, `The address below is the device's own (its manual, or its label). The PC does not need the network during the reading: values wait on it and leave when it is back.`)}
        <div class="cx-aide" id="cxcIpPc" style="margin-top:6px"></div></div>
    </div>`;
}

function _cxValeurChamp(def, prefixe) {
  const el = document.getElementById(prefixe + def.cle);
  if (!el) return undefined;
  if (def.type === 'booleen') return el.checked;
  return el.value;
}

async function _cxFormConnexion(id, protocole, pre) {
  pre = pre || {};
  let pa = null;
  try {
    await _cxEtat();
    if (id) pa = await apiRequest('connect_passerelles', 'GET', null, id);
  } catch (err) { toast(err.message, 'error'); return; }
  const proto = pa ? pa.Protocole : protocole;
  const d = CxUI.catalogue[proto];
  if (!d) { toast('Protocole inconnu.', 'error'); return; }
  const bats = await _cxRef('batiments');
  const v = pa || {};
  const params = (pa && pa.Parametres) || pre.parametres || {};
  const definis = (pa && pa.SecretsDefinis) || [];
  const intervalle = +(v.IntervalleSecondes || d.intervalle_defaut || 60);
  const presets = CX_INTERVALLES.map(x => [x[0], _cxDureeLongue(x[0])]);
  if (!CX_INTERVALLES.some(x => x[0] === intervalle)) {
    presets.push([intervalle, _cxDuree(intervalle)]);
    presets.sort((a, b) => a[0] - b[0]);
  }
  const req = ' <span class="cx-requis" aria-hidden="true">*</span>';

  let h = '<div class="cx-form">';
  if (d.avertissement) h += `<div class="cx-bandeau cx-bandeau-info"><span aria-hidden="true">${lk('info')}</span> ${escHtml(d.avertissement)}</div>`;
  if (pa && pa.SecretsIllisibles) h += ('<div class="cx-bandeau">' + lk('triangle-exclamation-point', 'warn') + ' Les identifiants enregistrés ne sont plus lisibles (clé de chiffrement changée ?) : saisissez-les de nouveau.</div>');
  // Comment Larka joint l'appareil : en premier, car l'adresse saisie en dépend.
  if (CX_PROTOS_HOTSPOT.includes(proto)) h += _cxSectionAcces(params, definis, pre, !!d.hors_reseau_seulement);
  h += '<div class="form-grid">';
  h += `<div class="form-group"><label class="form-label" for="cxcNom">Nom${req}</label>
          <input id="cxcNom" class="form-control" maxlength="120" value="${escHtml(v.Nom || pre.nom || '')}" placeholder="ex. Chaufferie — automate CVC"></div>`;
  h += `<div class="form-group"><label class="form-label" for="cxcBat">Bâtiment ${_cxBulle('cx.batiment')}</label>
          <select id="cxcBat" class="form-control"><option value="">—</option>${bats.map(b => `<option value="${b.Id}"${b.Id == v.BatimentId ? ' selected' : ''}>${escHtml(b.Nom)}</option>`).join('')}</select>
          <div class="cx-aide">Ceux des plans (Bâtiments → Plans).</div></div>`;
  if (d.hote) {
    h += `<div class="form-group"><label class="form-label" for="cxcHote">${escHtml(d.hote.libelle)}${d.hote.requis ? req : ''} ${_cxBulle('cx.hote')}</label>
            <input id="cxcHote" class="form-control" value="${escHtml(v.Hote || pre.hote || '')}" placeholder="ex. 192.168.20.15" spellcheck="false" autocomplete="off"></div>`;
  }
  if (d.port_defaut !== null && d.port_defaut !== undefined) {
    h += `<div class="form-group"><label class="form-label" for="cxcPort">Port ${_cxBulle('cx.port')}</label>
            <input id="cxcPort" class="form-control" type="number" min="1" max="65535" value="${escHtml(v.Port || d.port_defaut)}"></div>`;
  }
  if (d.identifiant) {
    h += `<div class="form-group"><label class="form-label" for="cxcIdent">${escHtml(d.identifiant.libelle)}${d.identifiant.requis ? req : ''} ${_cxBulle('cx.identifiant')}</label>
            <input id="cxcIdent" class="form-control" value="${escHtml(v.Identifiant || pre.identifiant || '')}" spellcheck="false" autocomplete="off">
            ${d.identifiant.aide ? `<div class="cx-aide">${escHtml(d.identifiant.aide)}</div>` : ''}</div>`;
  }
  if (d.collecte !== 'entrant') {
    h += `<div class="form-group" id="cxcIntervalleGroupe"><label class="form-label" for="cxcIntervalle">Interroger toutes les ${_cxBulle('cx.intervalle')}</label>
            <select id="cxcIntervalle" class="form-control">${_cxOptions(presets, intervalle)}</select>
            <div class="cx-aide">C'est le serveur qui interroge, à ce rythme, même quand personne n'a Larka ouvert.</div></div>`;
  }
  h += '</div>';
  if ((d.champs || []).length) {
    h += '<div class="cx-form-section">Paramètres</div><div class="form-grid">'
       + d.champs.map(ch => _cxChamp(ch, params[ch.cle], 'cxcP_')).join('') + '</div>';
  }
  if ((d.secrets || []).length) {
    h += `<div class="cx-form-section">Identifiants ${_cxBulle('cx.identifiants')} <span class="cx-aide">${_cxT(`(chiffrés sur le serveur, jamais réaffichés)`, `(encrypted on the server, never shown again)`)}</span></div><div class="form-grid">`
       + d.secrets.map(s => {
           const defini = definis.includes(s.cle);
           return `<div class="form-group"><label class="form-label" for="cxcS_${s.cle}">${escHtml(s.libelle)}</label>
             <input id="cxcS_${s.cle}" class="form-control" type="${s.type === 'secret' ? 'password' : 'text'}" autocomplete="new-password"
               placeholder="${defini ? '•••••• défini — laisser vide pour le garder' : ''}" spellcheck="false">
             ${defini ? `<label class="cx-case cx-aide"><input type="checkbox" id="cxcSx_${s.cle}"> Effacer la valeur enregistrée</label>` : ''}
             ${s.aide ? `<div class="cx-aide">${escHtml(s.aide)}</div>` : ''}</div>`;
         }).join('') + '</div>';
  }
  if (proto === 'ocpp') {
    h += `<div class="cx-form-section">Réglage à faire dans la borne</div>
      <div class="cx-apercu" id="cxcAdresseBorne"></div>`;
  }
  if (proto === 'lorawan') h += _cxReglagePasserelles();
  if (proto === 'wmbus') {
    h += `<div class="cx-form-section">${_cxT(`Matériel radio branché sur ce serveur`, `Radio hardware plugged into this server`)}</div>
      <div class="cx-apercu"><div id="cxcMateriel" class="cx-aide">${_cxT(`Clés reconnues : IMST iM871A, Amber AMB8465, CUL. Sur un PC releveur, la commande « releveur materiel » liste son matériel.`,
                                                                       `Supported keys: IMST iM871A, Amber AMB8465, CUL. On a reader PC, the “releveur materiel” command lists its hardware.`)}</div>
      <button type="button" class="btn btn-ghost btn-sm" style="margin-top:6px" onclick="_cxMaterielServeur(this)">${lk('search')} ${_cxT(`Chercher le matériel`, `Look for hardware`)}</button></div>`;
  }
  h += `<div class="cx-form-section">Divers</div>
    <div class="form-group"><label class="form-label" for="cxcDesc">Description</label>
      <textarea id="cxcDesc" class="form-control" rows="2" maxlength="1000">${escHtml(v.Description || '')}</textarea></div>
    <label class="cx-case" style="margin-top:8px"><input type="checkbox" id="cxcActif" ${v.Actif === undefined || +v.Actif ? 'checked' : ''}> Connexion active (interrogée par le serveur) ${_cxBulle('cx.actif')}</label>`;
  if (proto !== 'ocpp') {
    const horsReseau = ['hotspot', 'direct'].includes(params.acces);
    h += `<div class="cx-apercu" id="cxcTestHotspot" ${horsReseau ? '' : 'hidden'} style="margin-top:14px">${lk('phone-off')} ${_cxT(`Le serveur ne joint pas cet appareil : le test se fait depuis le PC releveur, connecté à l'appareil (« releveur tester »).`, `The server cannot reach this device: test it from the reader PC, connected to the device (“releveur tester”).`)}</div>`;
    h += `<div class="cx-test-zone" id="cxcTestZone" ${horsReseau ? 'hidden' : ''}>
      <button type="button" class="btn btn-ghost" onclick="_cxTesterFormulaire(${id || 0}, ${jsArg(proto)}, this)">${lk('flask-conical')} Tester la connexion</button>
      <span class="cx-aide">Le test n'enregistre rien. Il vérifie le réseau (pare-feu compris), le dialogue et les droits.</span>
      <div id="cxcTest"></div></div>`;
  }
  h += '</div>';
  openModal(id ? _cxT(`Modifier « ${v.Nom} »`, `Edit “${v.Nom}”`) : _cxT(`Nouvelle connexion — `, `New connection — `) + _cxTr(d.libelle), h,
    () => _cxEnregistrerConnexion(id, proto), id ? 'Enregistrer' : 'Créer la connexion', 'Annuler', '780px');
  if (CX_PROTOS_HOTSPOT.includes(proto)) {
    ['cxcHote', 'cxcP_url_base'].forEach(k => { const el = document.getElementById(k); if (el) el.addEventListener('input', _cxConseilIpDirect); });
    _cxAccesChange();
  }
  if (proto === 'ocpp') {
    const maj = () => {
      const z = document.getElementById('cxcAdresseBorne');
      if (!z) return;
      const ident = (gv('cxcIdent') || '').trim() || '<identifiant>';
      const e = CxUI.etat || {};
      const adr = String((e.ocpp && e.ocpp.adresse) || '').replace('<identifiant>', encodeURIComponent(ident).replace('%3Cidentifiant%3E', '<identifiant>'));
      const code = `<code class="cx-code-bloc">${escHtml(adr)}</code>`;
      z.innerHTML = _cxT(`Adresse du serveur central (« Central System » / « OCPP server URL ») à saisir dans la borne : ${code} `
                       + `Protocole <strong>OCPP 1.6 JSON</strong>. Avec un mot de passe ci-dessus, activez l'authentification « Basic » de la borne (identifiant = celui de la borne).`,
                         `Central system address (“Central System” / “OCPP server URL”) to enter in the charger: ${code} `
                       + `Protocol <strong>OCPP 1.6 JSON</strong>. With a password above, enable the charger's “Basic” authentication (username = the charger's identifier).`)
        + (e.ocpp && !e.ocpp.actif ? '<br>' + _cxT(`${lk('triangle-exclamation-point', 'warn')} Le service OCPP est désactivé (Configuration → Serveur → Larka Connect).`, `${lk('triangle-exclamation-point', 'warn')} The OCPP service is disabled (Settings → Server → Larka Connect).`) : '');
    };
    const champ = document.getElementById('cxcIdent');
    if (champ) champ.addEventListener('input', maj);
    maj();
  }
}

function _cxLireConnexion(proto) {
  const d = CxUI.catalogue[proto];
  const o = {
    protocole: proto,
    nom: gv('cxcNom').trim(),
    batimentId: gv('cxcBat') || null,
    description: gv('cxcDesc'),
    actif: !!(document.getElementById('cxcActif') || {}).checked,
    parametres: {},
    secrets: {},
  };
  if (d.hote) o.hote = gv('cxcHote').trim();
  if (document.getElementById('cxcPort')) o.port = gv('cxcPort');
  if (d.identifiant) o.identifiant = gv('cxcIdent').trim();
  if (document.getElementById('cxcIntervalle')) o.intervalle = +gv('cxcIntervalle');
  const acces = document.querySelector('input[name="cxcAcces"]:checked');
  if (acces) {
    o.acces = acces.value;
    if (acces.value === 'hotspot') {
      o.wifi = { ssid: gv('cxcSsid').trim(), securite: gv('cxcSecu') || 'wpa2' };
      if (gv('cxcCleWifi')) o.secrets.wifi_cle = gv('cxcCleWifi');
    }
  }
  (d.champs || []).forEach(ch => { const val = _cxValeurChamp(ch, 'cxcP_'); if (val !== undefined) o.parametres[ch.cle] = val; });
  (d.secrets || []).forEach(s => {
    const efface = document.getElementById('cxcSx_' + s.cle);
    const el = document.getElementById('cxcS_' + s.cle);
    if (efface && efface.checked) o.secrets[s.cle] = '__effacer__';
    else if (el && el.value !== '') o.secrets[s.cle] = el.value;
  });
  return o;
}

async function _cxEnregistrerConnexion(id, proto) {
  const o = _cxLireConnexion(proto);
  if (!o.nom) { toast('Le nom est obligatoire.', 'error'); return; }
  const btn = document.getElementById('modalSaveBtn');
  if (btn) btn.disabled = true;
  try {
    let nouvelle = 0;
    if (id) await apiRequest('connect_passerelles', 'PUT', o, id);
    else nouvelle = (await apiRequest('connect_passerelles', 'POST', o)).id;
    closeModal();
    toast(id ? 'Connexion enregistrée.' : 'Connexion créée.', 'success');
    CxUI.etatLu = 0;
    const d = CxUI.catalogue[proto] || {};
    if (nouvelle && ['hotspot', 'direct'].includes(o.acces)) {
      showConfirm(_cxT(`Connexion créée. Elle sera relevée hors réseau par un PC releveur : ajoutez ses points (registres, objets…), puis confiez-la à un PC dans l'onglet Hors réseau.`,
                       `Connection created. A reader PC will read it off the network: add its points (registers, objects…), then assign it to a PC in the Off the network tab.`),
                  () => { CxUI.filtrePa = String(nouvelle); CxUI.onglet = 'points'; _cxRafraichir(); _cxFormPoint(0, nouvelle); }, _cxT(`Ajouter un point`, `Add a point`));
    } else if (nouvelle && d.decouverte) {
      // Une connexion sans point ne sert à rien : on propose d'enchaîner.
      showConfirm(_cxT(`Connexion créée. Rechercher maintenant les points que l'équipement propose ?`, `Connection created. Look now for the points the device offers?`), () => _cxDecouvrir(nouvelle), 'Découvrir les points');
    } else if (nouvelle && proto === 'modbus') {
      showConfirm('Connexion créée. Ajouter maintenant les registres à lire (points) ?', () => { CxUI.filtrePa = String(nouvelle); CxUI.onglet = 'points'; _cxRafraichir(); _cxFormPoint(0, nouvelle); }, 'Ajouter un point');
    }
    if (App.currentPage === 'connect_config') {
      if (CxUI.onglet !== 'connexions' && CxUI.onglet !== 'points') CxUI.onglet = 'connexions';
      renderConnectConfig();
    }
  } catch (err) {
    toast(err.message, 'error');
  } finally {
    if (btn) btn.disabled = false;
  }
}

function _cxSupprimerConnexion(id) {
  const pa = (CxUI.passerelles || []).find(x => x.Id == id) || {};
  const n = +pa.NbPoints || 0;
  showConfirm(_cxT(`Supprimer « ${pa.Nom || 'la connexion'} » ? Ses ${n} point(s), leurs mesures, règles, relevés automatiques et alarmes seront supprimés. Les relevés déjà écrits dans le module Énergie restent.`,
                   `Delete “${pa.Nom || 'the connection'}”? Its ${_cxN(n, `point`, `points`)} and their measurements, rules, automatic readings and alarms will be deleted. Readings already written to the Energy module are kept.`), async () => {
    try {
      await apiRequest('connect_passerelles', 'DELETE', null, id);
      toast('Connexion supprimée.', 'success');
      _cxRafraichir();
    } catch (err) { toast(err.message, 'error'); }
  });
}

// ── Test, découverte, explorateur ──────────────────────────────────────────

/** Attente d'un test (le délai d'un pare-feu qui jette les paquets est long). */
function _cxEnCours() {
  return _cxT(`${lk('hourglass')} Test en cours… (jusqu'à une minute si un pare-feu ne répond pas)`, `${lk('hourglass')} Testing… (up to a minute if a firewall does not answer)`);
}

function _cxEtapesHtml(etapes, duree) {
  if (!etapes || !etapes.length) return '<div class="cx-aide">Aucun résultat.</div>';

  const ok = etapes.every(e => e.ok !== false);
  let h = `<div class="cx-test-verdict ${ok ? 'ok' : 'ko'}" role="status">${ok ? _cxT(`✓ La connexion fonctionne.`, `✓ The connection works.`)
                                                                             : _cxT(`✕ La connexion ne fonctionne pas encore : voir ci-dessous.`, `✕ The connection does not work yet: see below.`)}`
        + (duree !== null && duree !== undefined ? ` <span class="cx-aide">(${_cxNombre(duree, 1)} s)</span>` : '') + '</div>';
  h += '<ol class="cx-etapes">' + etapes.map((e, i) => {
    const cl = e.ok === true ? 'ok' : (e.ok === false ? 'ko' : 'info');
    const ic = e.ok === true ? '✓' : (e.ok === false ? '✕' : 'i');
    let x = `<li class="cx-etape cx-etape-${cl}"><span class="cx-etape-icone" aria-hidden="true">${ic}</span><div class="cx-etape-corps">
      <div class="cx-etape-titre">${escHtml(CX_ETAPES[e.etape] || e.etape)}${e.ms !== null && e.ms !== undefined ? ` <span class="cx-aide">· ${e.ms} ms</span>` : ''}</div>
      <div>${escHtml(e.message || '')}</div>`;
    // Étape en échec : son code, et la première chose à faire (la fiche donne le reste).
    if (e.code) {
      const f = CodesErreurUI._fiches[e.code];
      x += `<div class="ce-remede-court">${codeErreurBadge(e.code)} ${f ? escHtml(f.titre) + ' — ' + _ceTexte((f.remedes || [])[0] || '') : ''}
        <a href="#" onclick="event.preventDefault();ouvrirCodeErreur(${jsArg(e.code)}, {message: ${jsArg(e.message || '')}})">${_cxT(`Comment y remédier`, `How to fix it`)}</a></div>`;
    }
    if (e.demande_it) {
      x += `<details class="cx-demande-it" open><summary>Message à transmettre au service informatique</summary>
        <textarea id="cxIt${i}" class="form-control" rows="9" readonly>${escHtml(e.demande_it)}</textarea>
        <button type="button" class="btn btn-ghost btn-sm" onclick="_cxCopier('cxIt${i}')">${lk('clipboard-list')} Copier</button></details>`;
    }
    return x + '</div></li>';
  }).join('') + '</ol>';
  return h;
}

async function _cxTesterFormulaire(id, proto, btn) {
  const z = document.getElementById('cxcTest');
  if (!z) return;
  const o = _cxLireConnexion(proto);
  z.innerHTML = `<div class="cx-aide" role="status">${_cxEnCours()}</div>`;
  if (btn) btn.disabled = true;
  try {
    const r = await _cxApi('connect_tester', 'POST', { id: id || 0, connexion: o }, 90000);
    await CodesErreurUI.catalogue().catch(() => null);      // titres et remèdes des étapes en échec
    if (document.getElementById('cxcTest')) {
      z.innerHTML = _cxEtapesHtml(r.etapes, r.duree);
      z.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }
  } catch (err) {
    z.innerHTML = `<div class="cx-test-verdict ko">✕ ${escHtml(err.message)}</div>`;
  } finally {
    if (btn) btn.disabled = false;
  }
}

async function _cxTester(id) {
  const pa = (CxUI.passerelles || []).find(x => x.Id == id) || {};
  openModal(_cxT(`Test — ${pa.Nom || 'connexion'}`, `Test — ${pa.Nom || 'connection'}`), `<div id="cxcTest"><div class="cx-aide" role="status">${_cxEnCours()}</div></div>`,
    null, 'Enregistrer', 'Fermer', '680px');
  try {
    const r = await _cxApi('connect_tester', 'POST', { id }, 90000);
    await CodesErreurUI.catalogue().catch(() => null);
    const z = document.getElementById('cxcTest');
    if (z) z.innerHTML = _cxEtapesHtml(r.etapes, r.duree);
  } catch (err) {
    const z = document.getElementById('cxcTest');
    if (z) z.innerHTML = `<div class="cx-test-verdict ko">✕ ${escHtml(err.message)}</div>`;
  }
}

async function _cxDecouvrir(id) {
  if (!CxUI.passerelles) { try { CxUI.passerelles = await apiRequest('connect_passerelles'); } catch (_) { CxUI.passerelles = []; } }
  const pa = (CxUI.passerelles || []).find(x => x.Id == id) || {};
  const d = CxUI.catalogue[pa.Protocole] || {};
  openModal(_cxT(`Découverte — `, `Discovery — `) + (pa.Nom || ''), `<div class="cx-aide" role="status">${_cxT(`${lk('hourglass')} L'équipement est interrogé… (quelques secondes, davantage pour un gros automate)`, `${lk('hourglass')} Querying the device… (a few seconds, longer for a large controller)`)}</div>`,
    null, 'Enregistrer', 'Fermer', '960px');
  let props;
  try {
    props = await _cxApi('connect_decouvrir', 'POST', { id }, 150000) || [];
  } catch (err) {
    openModal(_cxT(`Découverte — `, `Discovery — `) + (pa.Nom || ''), `<div class="cx-test-verdict ko">✕ ${escHtml(err.message)}</div>
      <p class="cx-aide">Commencez par « ${lk('flask-conical')} Tester » : il dit ce qui bloque (réseau, pare-feu, droits).</p>`, null, 'Enregistrer', 'Fermer', '640px');
    return;
  }
  CxUI.decouverte = { id, props };
  if (!props.length) {
    openModal(_cxT(`Découverte — `, `Discovery — `) + (pa.Nom || ''), `<p>Aucun point proposé par l'équipement.</p>${d.aide_decouverte ? `<p class="cx-aide">${escHtml(d.aide_decouverte)}</p>` : ''}
      <p class="cx-aide">${pa.Protocole === 'mqtt' ? _cxT(`MQTT : seuls les sujets publiés pendant l'écoute apparaissent. Relancez quand les capteurs émettent, ou vérifiez les sujets suivis.`, `MQTT: only topics published while listening appear. Try again when the sensors transmit, or check the followed topics.`) : ''}</p>`,
      null, 'Enregistrer', 'Fermer', '640px');
    return;
  }
  const nouveaux = props.filter(p => !p.deja).length;
  const corps = `
    <p class="cx-aide" style="margin-top:0">${_cxT(`${props.length} point(s) trouvé(s), dont ${nouveaux} nouveau(x). Cochez ceux à suivre ; nom, nature et unité se corrigent ici ou plus tard.`,
                                                    `${_cxN(props.length, `point`, `points`)} found, ${nouveaux} new. Tick those to monitor; name, kind and unit can be corrected here or later.`)}</p>
    <div class="cx-filtres" style="margin-bottom:8px">
      <div class="search-input-wrap"><span class="search-icon">${lk('search')}</span>
        <input class="search-input" type="search" placeholder="Filtrer…" oninput="_cxFiltrerDecouverte(this.value)"></div>
      <button type="button" class="btn btn-ghost btn-sm" onclick="_cxCocherDecouverte(true)">Tout cocher</button>
      <button type="button" class="btn btn-ghost btn-sm" onclick="_cxCocherDecouverte(false)">Tout décocher</button>
    </div>
    <div class="table-wrap cx-decouverte"><table><thead><tr><th></th><th>Nom</th><th>Nature</th><th>Unité</th><th>Valeur lue</th><th>Repère</th></tr></thead><tbody>
    ${props.map((p, i) => `<tr data-cx-cherche="${escHtml((p.nom || '') + ' ' + (p.code || ''))}">
      <td><input type="checkbox" class="cx-dec-case" id="cxd_${i}" ${p.deja ? 'disabled' : 'checked'} aria-label="Ajouter"></td>
      <td><input class="form-control cx-input-compact" id="cxdn_${i}" value="${escHtml(p.nom || '')}" ${p.deja ? 'disabled' : ''}></td>
      <td><select class="form-control cx-input-compact" id="cxdt_${i}" ${p.deja ? 'disabled' : ''}>${_cxOptions(CX_NATURES, p.nature || 'mesure')}</select></td>
      <td><input class="form-control cx-input-compact" id="cxdu_${i}" value="${escHtml(p.unite || '')}" style="width:80px" ${p.deja ? 'disabled' : ''}></td>
      <td>${p.valeur !== null && p.valeur !== undefined && p.valeur !== '' ? escHtml(typeof p.valeur === 'number' ? _cxNombre(p.valeur, 4) : String(p.valeur)) : '<span class="cx-aide">—</span>'}</td>
      <td><code class="cx-code">${escHtml(p.code || '')}</code>${p.deja ? ' <span class="badge badge-gray">déjà suivi</span>' : ''}${p.ecriture ? ` <span class="badge badge-blue" title="Inscriptible : l'écriture s'autorise point par point, avec sa plage">${_cxT(`inscriptible`, `writable`)}</span>` : ''}</td>
    </tr>`).join('')}
    </tbody></table></div>`;
  openModal(_cxT(`Découverte — `, `Discovery — `) + (pa.Nom || ''), corps, _cxImporterDecouverte, 'Ajouter les points cochés', 'Annuler', '960px');
}

function _cxFiltrerDecouverte(q) {
  document.querySelectorAll('.cx-decouverte tbody tr').forEach(tr => {
    tr.style.display = !q || smartTextMatch(tr.dataset.cxCherche || '', q) ? '' : 'none';
  });
}

function _cxCocherDecouverte(v) {
  document.querySelectorAll('.cx-decouverte tbody tr').forEach(tr => {
    const c = tr.querySelector('.cx-dec-case');
    if (c && !c.disabled && tr.style.display !== 'none') c.checked = v;
  });
}

async function _cxImporterDecouverte() {
  const dec = CxUI.decouverte;
  if (!dec) return;
  const points = [];
  dec.props.forEach((p, i) => {
    const c = document.getElementById('cxd_' + i);
    if (!c || !c.checked || c.disabled) return;
    points.push({ nom: gv('cxdn_' + i).trim() || p.nom, code: p.code, nature: gv('cxdt_' + i), unite: gv('cxdu_' + i).trim(), adresse: p.adresse || {} });
  });
  if (!points.length) { toast('Aucun point coché.', 'error'); return; }
  try {
    const r = await apiRequest('connect_points_importer', 'POST', { passerelleId: dec.id, points });
    closeModal();
    toast(_cxT(`${r.crees} point(s) ajouté(s).`, `${_cxN(r.crees, `point`, `points`)} added.`), 'success');
    if (r.erreurs && r.erreurs.length) toast(_cxT(`Non ajoutés : `, `Not added: `) + r.erreurs.slice(0, 3).join(' ; '), 'error');
    CxUI.filtrePa = String(dec.id);
    if (App.currentPage === 'connect_config') { CxUI.onglet = 'points'; renderConnectConfig(); }
  } catch (err) { toast(err.message, 'error'); }
}

function _cxExplorer(id) {
  const pa = (CxUI.passerelles || []).find(x => x.Id == id) || {};
  const prm = pa.Parametres || {};
  const corps = `
    <p class="cx-aide" style="margin-top:0">${_cxT(`Lit une plage brute de registres et l'affiche sous plusieurs interprétations : la bonne est celle qui ressemble à ce qu'affiche l'équipement. Cliquez une valeur pour en faire un point.`,
                                                    `Reads a raw range of registers and shows it under several interpretations: the right one looks like what the device displays. Click a value to make it a point.`)}</p>
    <div class="form-grid cx-grille-4">
      <div class="form-group"><label class="form-label" for="cxeU">Unit id</label><input id="cxeU" class="form-control" type="number" min="0" max="255" value="${escHtml(prm.unite_defaut ?? 1)}"></div>
      <div class="form-group"><label class="form-label" for="cxeT">Table</label><select id="cxeT" class="form-control">
        ${_cxOptions([['holding', 'Registres de maintien (03)'], ['input', `Registres d'entrée (04)`], ['coil', 'Bobines (01)'], ['discrete', 'Entrées TOR (02)']], 'holding')}</select></div>
      <div class="form-group"><label class="form-label" for="cxeD">Premier registre</label><input id="cxeD" class="form-control" type="number" min="0" value="0"></div>
      <div class="form-group"><label class="form-label" for="cxeN">Nombre (60 au plus)</label><input id="cxeN" class="form-control" type="number" min="1" max="60" value="20"></div>
    </div>
    <button type="button" class="btn btn-primary btn-sm" onclick="_cxExplorerLire(${id}, this)">Lire la plage</button>
    <div id="cxExplo" style="margin-top:12px"></div>`;
  openModal(_cxT(`Explorateur Modbus — `, `Modbus explorer — `) + (pa.Nom || ''), corps, null, 'Enregistrer', 'Fermer', '980px');
}

async function _cxExplorerLire(id, btn) {
  const z = document.getElementById('cxExplo');
  if (!z) return;
  const corps = { id, unite: +gv('cxeU') || 0, table: gv('cxeT'), debut: +gv('cxeD') || 0, nombre: Math.min(60, Math.max(1, +gv('cxeN') || 20)) };
  z.innerHTML = ('<div class="cx-aide" role="status">' + lk('hourglass') + ' Lecture…</div>');
  if (btn) btn.disabled = true;
  try {
    const r = await _cxApi('connect_explorer', 'POST', corps);
    if (!r.ok) {
      z.innerHTML = `<div class="cx-test-verdict ko">✕ ${escHtml(r.message || 'Lecture impossible.')}</div>`
        + (r.demande_it ? `<details class="cx-demande-it" open><summary>Message à transmettre au service informatique</summary>
            <textarea id="cxItExp" class="form-control" rows="9" readonly>${escHtml(r.demande_it)}</textarea>
            <button type="button" class="btn btn-ghost btn-sm" onclick="_cxCopier('cxItExp')">${lk('clipboard-list')} Copier</button></details>` : '');
      return;
    }
    const bits = corps.table === 'coil' || corps.table === 'discrete';
    const cell = (reg, val, type, ordre) => (val === null || val === undefined) ? '<td class="cx-aide">—</td>'
      : `<td><button type="button" class="cx-valeur-brute" title="${_cxT(`Créer un point : registre ${reg}, ${type}${ordre ? ' ' + ordre : ''}`, `Create a point: register ${reg}, ${type}${ordre ? ' ' + ordre : ''}`)}"
           onclick="_cxPointDepuisExplorateur(${id}, ${corps.unite}, ${jsArg(corps.table)}, ${reg}, ${jsArg(type)}, ${jsArg(ordre || '')})">${escHtml(typeof val === 'number' ? val.toLocaleString('fr-FR', { maximumFractionDigits: 4 }) : String(val))}</button></td>`;
    z.innerHTML = (r.absents ? `<div class="cx-aide" style="margin-bottom:6px">${_cxT(`${r.absents} registre(s) de la plage n'existent pas dans l'équipement (refus 0x02) : marqués « absent ».`,
                                                                                       `${_cxN(r.absents, `register`, `registers`)} of the range do not exist in the device (0x02 refusal): marked “absent”.`)}</div>` : '')
      + `<div class="table-wrap"><table class="cx-table-explo"><thead><tr><th>Registre</th><th>Brut</th>
        ${bits ? '' : '<th>int16</th><th>uint32 ABCD</th><th>uint32 CDAB</th><th>float32 ABCD</th><th>float32 CDAB</th>'}</tr></thead><tbody>
      ${(r.lignes || []).map(l => l.absent
        ? `<tr class="cx-inactif"><td><strong>${l.registre}</strong></td><td colspan="${bits ? 1 : 6}" class="cx-aide">${_cxT(`absent`, `absent`)}</td></tr>`
        : `<tr><td><strong>${l.registre}</strong></td>${cell(l.registre, l.brut, bits ? 'bool' : 'uint16', '')}
        ${bits ? '' : cell(l.registre, l.int16, 'int16', '') + cell(l.registre, l.uint32_abcd, 'uint32', 'ABCD') + cell(l.registre, l.uint32_cdab, 'uint32', 'CDAB')
          + cell(l.registre, l.float32_abcd, 'float32', 'ABCD') + cell(l.registre, l.float32_cdab, 'float32', 'CDAB')}</tr>`).join('')}
      </tbody></table></div>`;
  } catch (err) {
    z.innerHTML = `<div class="cx-test-verdict ko">✕ ${escHtml(err.message)}</div>`;
  } finally {
    if (btn) btn.disabled = false;
  }
}

function _cxPointDepuisExplorateur(passerelleId, unite, table, registre, type, ordre) {
  _cxFormPoint(0, passerelleId, { nom: _cxT(`Registre `, `Register `) + registre, adresse: { unite: unite || '', table, registre, type, ordre } });
}

// ── Onglet Points ───────────────────────────────────────────────────────────

async function _cxOngletPoints(z) {
  const [pas, pts] = await Promise.all([
    apiRequest('connect_passerelles'),
    apiRequest('connect_points' + (CxUI.filtrePa ? '&passerelle_id=' + encodeURIComponent(CxUI.filtrePa) : '')),
  ]);
  CxUI.passerelles = pas || [];
  if (CxUI.filtrePa && !CxUI.passerelles.some(p => String(p.Id) === String(CxUI.filtrePa))) { CxUI.filtrePa = ''; return _cxOngletPoints(z); }
  CxUI.points = pts || [];
  const pa = CxUI.passerelles.find(p => String(p.Id) === String(CxUI.filtrePa));
  const d = pa ? (CxUI.catalogue[pa.Protocole] || {}) : {};
  z.innerHTML = `
    <div class="cx-filtres">
      <select class="form-control" aria-label="Connexion" onchange="CxUI.filtrePa=this.value;_cxOnglet('points')">
        <option value="">Toutes les connexions</option>
        ${CxUI.passerelles.map(p => `<option value="${p.Id}"${String(p.Id) === String(CxUI.filtrePa) ? ' selected' : ''}>${escHtml(p.Nom)}</option>`).join('')}
      </select>
      <div class="search-input-wrap"><span class="search-icon">${lk('search')}</span>
        <input class="search-input" type="search" placeholder="Rechercher un point…" value="${escHtml(CxUI.rechPoints)}" oninput="CxUI.rechPoints=this.value;_cxPeindrePoints()"></div>
      <div style="flex:1"></div>
      ${pa && d.decouverte && !_cxEnHotspot(pa) ? `<button class="btn btn-ghost btn-sm" onclick="_cxDecouvrir(${pa.Id})">${lk('search')} Découvrir</button>` : ''}
      ${pa && _cxEnHotspot(pa) ? `<button class="btn btn-ghost btn-sm" onclick="_cxReconnaissance(${pa.Id})">${lk('search')} ${_cxT(`Points trouvés par le PC releveur`, `Points found by the reader PC`)}</button>` : ''}
      ${pa && pa.Protocole === 'modbus' && !_cxEnHotspot(pa) ? `<button class="btn btn-ghost btn-sm" onclick="_cxExplorer(${pa.Id})">${lk('compass')} Explorer</button>` : ''}
      ${pa && pa.Protocole !== 'ocpp' && pa.Protocole !== 'auto' ? `<button class="btn btn-primary btn-sm" onclick="_cxFormPoint(0, ${pa.Id})">＋ Nouveau point</button>` : ''}
      ${!pa ? `<button class="btn btn-primary btn-sm" onclick="_cxNouveauPointChoix()">＋ Nouveau point</button>` : ''}
    </div>
    ${pa && pa.Protocole === 'ocpp' ? `<div class="cx-bandeau cx-bandeau-info">${lk('info')} ${_cxT(`Les points d'une borne (état, énergie, puissance, défaut) se créent d'eux-mêmes à sa première connexion. Vous pouvez les renommer, leur ajouter des règles d'alarme ou des relevés automatiques.`,
                                                                                          `A charger's points (state, energy, power, fault) are created by themselves on its first connection. You can rename them and add alarm rules or automatic readings.`)}</div>` : ''}
    <div id="cxPoints" class="card"></div>`;
  _cxPeindrePoints();
}

function _cxPeindrePoints() {
  const z = document.getElementById('cxPoints');
  if (!z) return;
  const q = (CxUI.rechPoints || '').trim();
  const liste = (CxUI.points || []).filter(p => !q || [p.Nom, p.Code, p.PasserelleNom, p.Unite].some(x => smartTextMatch(x || '', q)));
  if (!liste.length) {
    z.innerHTML = `<div class="no-results">${(CxUI.points || []).length ? 'Aucun résultat.' : _cxT(`Aucun point pour l'instant : « ${lk('search')} Découvrir » les propose quand l'équipement sait les lister, sinon « ＋ Nouveau point ».`,
                                                                                               `No points yet: “${lk('search')} Discover” suggests them when the device can list them, otherwise “＋ New point”.`)}</div>`;
    return;
  }
  const natures = Object.fromEntries(CX_NATURES);
  const sel = canEdit();
  z.innerHTML = (sel ? `<div class="cx-filtres" id="cxPtsSel" style="display:none;padding:8px 12px">
        <span id="cxPtsSelN"></span>
        <button type="button" class="btn btn-danger btn-sm" onclick="_cxSupprimerSelection()">${lk('trash')} ${_cxT(`Supprimer la sélection`, `Delete the selection`)}</button>
        <button type="button" class="btn btn-ghost btn-sm" onclick="_cxCocherPoints(false)">${_cxT(`Tout décocher`, `Untick all`)}</button></div>` : '')
    + `<div class="table-wrap"><table><thead><tr>
      ${sel ? `<th style="width:28px"><input type="checkbox" aria-label="${escHtml(_cxT(`Tout sélectionner`, `Select all`))}" onchange="_cxCocherPoints(this.checked)"></th>` : ''}
      <th>Point</th>${CxUI.filtrePa ? '' : '<th>Connexion</th>'}<th>Nature</th><th>Adresse</th><th>Valeur</th><th>Suivi</th><th>Actions</th>
    </tr></thead><tbody>
    ${_cxLignesParBatiment(liste, p => p.BatimentNom, (CxUI.filtrePa ? 6 : 7) + (sel ? 1 : 0), p => `<tr class="${+p.Actif ? '' : 'cx-inactif'}">
      ${sel ? `<td><input type="checkbox" class="cx-pt-sel" value="${p.Id}" aria-label="${escHtml(_cxT(`Sélectionner`, `Select`))}" onchange="_cxMajSelection()"></td>` : ''}
      <td><div>${escHtml(p.Nom)}</div>${p.Code ? `<div class="cx-aide"><code class="cx-code">${escHtml(p.Code)}</code></div>` : ''}${p.EquipementNumero ? `<div class="cx-aide">${lk('settings')} ${escHtml(p.EquipementNumero)}</div>` : ''}</td>
      ${CxUI.filtrePa ? '' : `<td>${escHtml(p.PasserelleNom || '')}</td>`}
      <td>${escHtml(natures[p.Nature] || p.Nature)}${+p.EcritureAutorisee ? ('<div><span class="badge badge-blue" title="Écriture autorisée">' + lk('pencil') + ' ') + _cxNombre(p.MinEcriture, 4) + ' ' + _cxT(`à`, `to`) + ' ' + _cxNombre(p.MaxEcriture, 4) + '</span></div>' : ''}</td>
      <td><code class="cx-code">${escHtml(_cxAdresseResume(p.PasserelleProtocole, p.Adresse))}</code>${+p.Coefficient !== 1 || +p.Decalage ? `<div class="cx-aide">× ${_cxNombre(p.Coefficient, 6)}${+p.Decalage ? (p.Decalage > 0 ? ' + ' : ' − ') + _cxNombre(Math.abs(p.Decalage), 6) : ''}</div>` : ''}</td>
      <td style="white-space:nowrap">${_cxValeurHtml(p)}${_cxQualite(p.DerniereQualite, p.DerniereErreur)}${p.CodeErreur ? ' ' + codeErreurBadge(p.CodeErreur) : ''}<div class="cx-aide">${p.DerniereLecture ? _cxDepuis(p.DerniereLecture) : _cxT(`jamais lue`, `never read`)}</div></td>
      <td>
        <a href="#" aria-label="Règles d'alarme" data-cx-info="action.regles" onclick="event.preventDefault();_cxReglesPoint(${p.Id})">${lk('bell')} ${+p.NbRegles || 0}</a>
        <a href="#" aria-label="Relevés automatiques" data-cx-info="action.releves" style="margin-left:8px" onclick="event.preventDefault();_cxFormLiaison(0, ${p.Id})">${lk('calendar')} ${+p.NbLiaisons || 0}</a>
        ${+p.NbAlarmes ? `<span class="badge badge-red" style="margin-left:6px">${_cxT(`${p.NbAlarmes} active(s)`, `${p.NbAlarmes} active`)}</span>` : ''}
      </td>
      <td><div class="actions-col">
        <button class="icon-btn" aria-label="Courbe" data-cx-info="action.courbe" onclick="_cxHistorique(${p.Id})">${lk('trending-up')}</button>
        <button class="icon-btn" aria-label="Modifier" data-cx-info="action.point.modifier" onclick="_cxFormPoint(${p.Id})">${lk('pencil')}</button>
        ${+p.EcritureAutorisee && +p.Actif ? `<button class="icon-btn" aria-label="Écrire" data-cx-info="action.ecrire" onclick="_cxEcrire(${p.Id})">${lk('sliders-horizontal')}</button>` : ''}
        <button class="icon-btn delete" aria-label="Supprimer" data-cx-info="action.point.supprimer" onclick="_cxSupprimerPoint(${p.Id})">${lk('trash')}</button>
      </div></td>
    </tr>`, items => { const n = items.reduce((k, p) => k + (+p.NbAlarmes || 0), 0); return n ? `<span class="badge badge-red">${lk('bell')} ${n}</span>` : ''; })}
    </tbody></table></div>`;
}

function _cxNouveauPointChoix() {
  const pas = (CxUI.passerelles || []).filter(p => p.Protocole !== 'ocpp');
  if (!pas.length) { toast(`Créez d'abord une connexion.`, 'error'); return; }
  openModal('Nouveau point — sur quelle connexion ?', `<div class="cx-liste-choix">${pas.map(p =>
    `<button type="button" class="cx-proto-choix" onclick="_cxFormPoint(0, ${p.Id})"><span aria-hidden="true">${CX_PROTO_ICONE[p.Protocole] || '•'}</span>
      <b>${escHtml(p.Nom)}</b><span>${escHtml((CxUI.catalogue[p.Protocole] || {}).libelle || p.Protocole)}</span></button>`).join('')}</div>`,
    null, 'Enregistrer', 'Annuler', '640px');
}

async function _cxFormPoint(id, passerelleId, pre) {
  pre = pre || {};
  let p = null;
  try {
    await _cxEtat();
    if (id) p = await apiRequest('connect_points', 'GET', null, id);
    if (!CxUI.passerelles) CxUI.passerelles = await apiRequest('connect_passerelles');
  } catch (err) { toast(err.message, 'error'); return; }
  const paId = p ? p.PasserelleId : passerelleId;
  const pa = (CxUI.passerelles || []).find(x => x.Id == paId);
  if (!pa) { toast('Connexion introuvable.', 'error'); return; }
  const d = CxUI.catalogue[pa.Protocole] || {};
  const v = p || {};
  const adr = (p && p.Adresse) || pre.adresse || {};
  const eqs = await _cxRef('equipements');
  const libEq = e => [e.Numero || ('#' + e.Id), [e.Famille, e.SousFamille, e.Marque, e.Modele].filter(Boolean).join(' '), e.Batiment ? '(' + e.Batiment + ')' : ''].filter(Boolean).join(' — ');
  const req = ' <span class="cx-requis" aria-hidden="true">*</span>';
  let h = '<div class="cx-form"><div class="form-grid">'
    + `<div class="form-group"><label class="form-label" for="cxpNom">Nom${req}</label><input id="cxpNom" class="form-control" maxlength="150" value="${escHtml(v.Nom || pre.nom || '')}" placeholder="ex. Température départ chauffage"></div>`
    + `<div class="form-group"><label class="form-label" for="cxpCode">Code (repère) ${_cxBulle('pt.code')}</label><input id="cxpCode" class="form-control" maxlength="120" value="${escHtml(v.Code || pre.code || '')}" spellcheck="false"></div>`
    + `<div class="form-group"><label class="form-label" for="cxpNature">Nature ${_cxBulle('pt.nature')}</label><select id="cxpNature" class="form-control">${_cxOptions(CX_NATURES, v.Nature || pre.nature || 'mesure')}</select>
        <div class="cx-aide">« Compteur » pour un index qui ne fait que croître (énergie, eau, heures de marche).</div></div>`
    + `<div class="form-group"><label class="form-label" for="cxpUnite">Unité</label><input id="cxpUnite" class="form-control" maxlength="20" value="${escHtml(v.Unite || pre.unite || '')}" placeholder="°C, kWh, m³, %…"></div>`
    + '</div>';
  if ((d.adresse || []).length) {
    h += `<div class="cx-form-section">Adresse dans l'équipement</div><div class="form-grid">${d.adresse.map(ch => _cxChamp(ch, adr[ch.cle], 'cxpA_')).join('')}</div>`;
  }
  h += `<div class="cx-form-section">Mise à l'échelle et historique</div><div class="form-grid cx-grille-4">
      <div class="form-group"><label class="form-label" for="cxpCoef">Coefficient ${_cxBulle('pt.coef')}</label><input id="cxpCoef" class="form-control" type="number" step="any" value="${escHtml(v.Coefficient ?? 1)}"></div>
      <div class="form-group"><label class="form-label" for="cxpDec">Décalage</label><input id="cxpDec" class="form-control" type="number" step="any" value="${escHtml(v.Decalage ?? 0)}"></div>
      <div class="form-group"><label class="form-label" for="cxpDecim">Décimales</label><input id="cxpDecim" class="form-control" type="number" min="0" max="6" value="${escHtml(v.Decimales ?? 2)}"></div>
      <div class="form-group"><label class="form-label" for="cxpBande">Bande morte ${_cxBulle('pt.bande')}</label><input id="cxpBande" class="form-control" type="number" min="0" step="any" value="${escHtml(v.BandeMorte ?? 0)}"></div>
    </div>
    <div class="cx-aide">${_cxT(`Valeur gardée = valeur lue × coefficient + décalage (ex. 0,1 pour un registre en dixièmes de degré). Une variation plus petite que la bande morte n'est pas historisée ; une valeur stable l'est quand même ${_cxRythme(60 * ((CxUI.etat || {}).periodique_minutes || 15))}.`,
                                `Value kept = value read × factor + offset (e.g. 0.1 for a register in tenths of a degree). A change smaller than the deadband is not recorded; a steady value is still recorded ${_cxRythme(60 * ((CxUI.etat || {}).periodique_minutes || 15))}.`)}</div>`;
  h += `<div class="cx-form-section">Rattachement</div>
    <div class="form-group"><label class="form-label" for="cxpEq">Équipement de l'inventaire ${_cxBulle('pt.equipement')}</label>
      <select id="cxpEq" class="form-control"><option value="">— aucun —</option>${eqs.map(e => `<option value="${e.Id}"${e.Id == v.EquipementId ? ' selected' : ''}>${escHtml(libEq(e))}</option>`).join('')}</select>
      <div class="cx-aide">Une alarme sur ce point créera sa demande d'intervention sur cet équipement.</div></div>`;
  if (d.ecriture) {
    h += `<div class="cx-form-section">Écriture depuis Larka ${_cxBulle('pt.ecriture')}</div>
      <label class="cx-case"><input type="checkbox" id="cxpEcr" ${+v.EcritureAutorisee ? 'checked' : ''} onchange="document.getElementById('cxpPlage').hidden=!this.checked">
        Autoriser la modification de cette valeur (consigne) depuis Larka</label>
      <div id="cxpPlage" class="form-grid" ${+v.EcritureAutorisee ? '' : 'hidden'} style="margin-top:8px">
        <div class="form-group"><label class="form-label" for="cxpMin">Minimum permis *</label><input id="cxpMin" class="form-control" type="number" step="any" value="${escHtml(v.MinEcriture ?? '')}"></div>
        <div class="form-group"><label class="form-label" for="cxpMax">Maximum permis *</label><input id="cxpMax" class="form-control" type="number" step="any" value="${escHtml(v.MaxEcriture ?? '')}"></div>
        <div class="cx-aide" style="grid-column:1/-1">Chaque écriture exige un gestionnaire qui confirme son identité, et reste tracée. Hors de la plage, elle est refusée.</div>
      </div>`;
  }
  h += `<label class="cx-case" style="margin-top:12px"><input type="checkbox" id="cxpActif" ${v.Actif === undefined || +v.Actif ? 'checked' : ''}> Point suivi (lu à chaque collecte)</label>`;
  h += '</div>';
  openModal(id ? _cxT(`Modifier le point « ${v.Nom} »`, `Edit point “${v.Nom}”`) : _cxT(`Nouveau point — `, `New point — `) + pa.Nom, h,
    () => _cxEnregistrerPoint(id, pa.Id), id ? 'Enregistrer' : 'Créer le point', 'Annuler', '780px');
  if (typeof enhanceSelectTypeahead === 'function' && eqs.length > 15) {
    try { enhanceSelectTypeahead('cxpEq'); } catch (_) { /* liste simple */ }
  }
}

async function _cxEnregistrerPoint(id, passerelleId) {
  const pa = (CxUI.passerelles || []).find(x => x.Id == passerelleId) || {};
  const d = CxUI.catalogue[pa.Protocole] || {};
  const o = {
    passerelleId,
    nom: gv('cxpNom').trim(), code: gv('cxpCode').trim(), nature: gv('cxpNature'), unite: gv('cxpUnite').trim(),
    coefficient: gv('cxpCoef'), decalage: gv('cxpDec'), decimales: gv('cxpDecim'), bandeMorte: gv('cxpBande'),
    equipementId: gv('cxpEq') || null,
    actif: !!(document.getElementById('cxpActif') || {}).checked,
    adresse: {},
  };
  (d.adresse || []).forEach(ch => { const val = _cxValeurChamp(ch, 'cxpA_'); if (val !== undefined) o.adresse[ch.cle] = val; });
  if (document.getElementById('cxpEcr')) {
    o.ecritureAutorisee = document.getElementById('cxpEcr').checked;
    o.minEcriture = gv('cxpMin');
    o.maxEcriture = gv('cxpMax');
  }
  if (!o.nom) { toast('Le nom du point est obligatoire.', 'error'); return; }
  try {
    if (id) await apiRequest('connect_points', 'PUT', o, id);
    else await apiRequest('connect_points', 'POST', o);
    closeModal();
    toast(id ? 'Point enregistré.' : 'Point créé : il sera lu à la prochaine collecte.', 'success');
    _cxRafraichir();
  } catch (err) { toast(err.message, 'error'); }
}

function _cxMajSelection() {
  const n = document.querySelectorAll('.cx-pt-sel:checked').length;
  const bar = document.getElementById('cxPtsSel');
  if (bar) bar.style.display = n === 0 ? 'none' : '';
  const t = document.getElementById('cxPtsSelN');
  if (t) t.textContent = _cxT(`${n} point(s) sélectionné(s)`, `${_cxN(n, `point`, `points`)} selected`);
}

function _cxCocherPoints(v) {
  document.querySelectorAll('.cx-pt-sel').forEach(c => { if (c.closest('tr').style.display !== 'none') c.checked = v; });
  _cxMajSelection();
}

function _cxSupprimerSelection() {
  const ids = [...document.querySelectorAll('.cx-pt-sel:checked')].map(c => +c.value);
  if (!ids.length) return;
  showConfirm(_cxT(`Supprimer ${ids.length} point(s) ? Leur historique, leurs règles d'alarme et leurs relevés automatiques seront supprimés.`,
                   `Delete ${_cxN(ids.length, `point`, `points`)}? Their history, alarm rules and automatic readings will be deleted.`), async () => {
    try {
      const r = await apiRequest('connect_points_supprimer', 'POST', { ids });
      toast(_cxT(`${r.supprimes} point(s) supprimé(s).`, `${_cxN(r.supprimes, `point`, `points`)} deleted.`), 'success');
      _cxRafraichir();
    } catch (err) { toast(err.message, 'error'); }
  });
}

// ── Détection d'un appareil hors réseau par le PC releveur ──────────────────
// Le serveur ne joint pas ces appareils : au prochain relevé, le PC regarde ce
// qu'ils exposent (page web, API JSON, Modbus) et l'envoie. Ici : les réglages
// et les points proposés, appliqués seulement sur accord.

/** « 1.3.4 » est-elle antérieure à « 1.4.1 » ? */
function _cxVersionAvant(a, b) {
  const pa = String(a).split('.').map(n => +n || 0), pb = String(b).split('.').map(n => +n || 0);
  for (let i = 0; i < Math.max(pa.length, pb.length); i++) { if ((pa[i] || 0) !== (pb[i] || 0)) return (pa[i] || 0) < (pb[i] || 0); }
  return false;
}

/** PC releveurs de la connexion : qui fera la détection, et lequel doit retélécharger son collecteur. */
function _cxReconPcs(r) {
  const pcs = r.releveurs || [];
  if (!pcs.length) return `<div class="cx-bandeau">${_cxT(`Aucun PC releveur ne relève cet appareil : ajoutez-en un (ou cochez cet appareil dans le sien), onglet ${lk('wifi')} Hors réseau.`, `No reader PC reads this device: add one (or tick this device in its list), ${lk('wifi')} Off the network tab.`)}</div>`;
  const vieux = pcs.filter(p => !p.Detection);
  return `<p class="cx-aide">${_cxT(`Relevé par : `, `Read by: `)}${pcs.map(p => `<strong>${escHtml(p.Nom)}</strong> (${_cxT(`collecteur`, `collector`)} ${escHtml(p.Version || _cxT(`1.3 ou avant`, `1.3 or earlier`))}${p.DernierContact ? ', ' + _cxDepuis(p.DernierContact) : ''})`).join(', ')}</p>`
    + (vieux.length ? `<div class="cx-bandeau" role="alert">${lk('triangle-exclamation-point', 'warn')} ${_cxT(`Collecteur trop ancien sur ${vieux.map(p => '« ' + escHtml(p.Nom) + ' »').join(', ')} : il ne fait pas la détection. Retéléchargez-le (${lk('arrow-down')} dans la liste des PC releveurs) et remplacez le dossier sur le PC.`, `Collector too old on ${vieux.map(p => '“' + escHtml(p.Nom) + '”').join(', ')}: it does not detect. Download it again (${lk('arrow-down')} in the reader PC list) and replace the folder on the PC.`)}</div>` : '');
}

async function _cxReconDemander(id) {
  try {
    await apiRequest('connect_reconnaissance', 'POST', { op: 'demander', id });
    closeModal();
    toast(_cxT(`Au prochain relevé (double-clic sur releveur.cmd), le PC releveur regardera ce que l'appareil expose et l'enverra ici.`,
               `At the next reading (double-click releveur.cmd), the reader PC will look at what the device exposes and send it here.`), 'success');
    if (App.currentPage === 'connect_config') _cxRafraichir();
  } catch (err) { toast(err.message, 'error'); }
}

async function _cxReconnaissance(id) {
  let r;
  try { r = await apiRequest('connect_reconnaissance', 'GET', null, id); } catch (err) { toast(err.message, 'error'); return; }
  const pa = (CxUI.hotspots || []).find(x => +x.Id === +id) || (CxUI.passerelles || []).find(x => +x.Id === +id) || {};
  const titre = `${_cxT(`Détection — `, `Detection — `)}${pa.Nom || ''}`;
  if (!r.date) {
    openModal(titre, `<p>${_cxT(`Le PC releveur n'a encore rien envoyé pour cet appareil.`, `The reader PC has not sent anything for this device yet.`)}</p>
      ${_cxReconPcs(r)}
      ${r.demandee || r.protocole === 'auto'
        ? `<p class="cx-aide">${_cxT(`La détection se fera au prochain relevé : double-cliquez releveur.cmd sur le PC. Elle part à Larka quand le PC a retrouvé son réseau ; si rien n'arrive, « releveur.cmd etat » sur le PC dit si une détection y attend, et pourquoi le réseau n'est pas revenu.`, `Detection will happen at the next reading: double-click releveur.cmd on the PC. It is sent to Larka once the PC is back on its network; if nothing arrives, “releveur.cmd etat” on the PC says whether a detection is waiting there, and why the network did not come back.`)}</p>`
        : `<p class="cx-aide">${_cxT(`Demandez-la : au prochain relevé, le PC regardera ce que l'appareil expose (ports, page web et API JSON, registres Modbus, automate BACnet et ses points), sans rien y écrire, et proposera ici les réglages et les points.`, `Request it: at the next reading, the PC will look at what the device exposes (ports, web page and JSON API, Modbus registers, BACnet controller and its points), without writing anything, and will suggest the settings and points here.`)}</p>`}`,
      r.demandee || r.protocole === 'auto' ? null : () => _cxReconDemander(id), _cxT(`Demander une détection`, `Request a detection`), _cxT(`Fermer`, `Close`), '640px');
    return;
  }
  CxUI.decouverte = { id, props: r.points || [], recon: r };
  const sug = r.suggestion;
  const change = sug && (r.protocole === 'auto' || r.protocole !== sug.protocole);
  const nbPts = +pa.NbPoints || 0;
  const props = r.points || [];
  let h = `<p class="cx-aide" style="margin-top:0">${_cxT(`Reçu`, `Received`)} ${_cxDepuis(r.date)}${r.version ? ' · ' + _cxT(`collecteur`, `collector`) + ' ' + escHtml(r.version) : ''} — ${(r.resume || []).map(escHtml).join(' · ')}
      · <a href="#" onclick="event.preventDefault();_cxReconDemander(${id})">${_cxT(`refaire la détection`, `detect again`)}</a></p>`;
  if (sug) {
    h += `<div class="cx-apercu" style="margin-bottom:10px">${lk('circle-check', 'ok')} ${_cxT(`Réglages proposés :`, `Suggested settings:`)} <strong>${escHtml(sug.libelle || '')}</strong>
      <label class="cx-case" style="margin-top:6px"><input type="checkbox" id="cxrReglages" ${change ? 'checked' : ''}> ${_cxT(`Appliquer ces réglages à la connexion`, `Apply these settings to the connection`)}${r.protocole === sug.protocole ? ' ' + _cxT(`(déjà ce protocole : ne cochez que pour remettre l'adresse et le chemin proposés)`, `(already this protocol: tick only to reset the suggested address and path)`) : ''}</label>
      ${change && nbPts ? `<label class="cx-case"><input type="checkbox" id="cxrSuppr"> ${_cxT(`Supprimer les ${nbPts} point(s) existant(s) (leurs adresses ne valent plus avec un autre protocole)`, `Delete the ${nbPts} existing point(s) (their addresses no longer apply with another protocol)`)}</label>` : ''}</div>`;
  } else {
    h += `<div class="cx-bandeau">${_cxT(`Rien d'exploitable trouvé : ni réponse JSON avec des valeurs, ni registre Modbus. Le détail est sur le PC, dans exploration-….txt (dossier du collecteur).`, `Nothing usable found: no JSON answer with values, no Modbus register. Details are on the PC, in exploration-….txt (collector folder).`)}</div>`;
  }
  if (props.length) {
    const nouveaux = props.filter(p => !p.deja).length;
    h += `<p class="cx-aide">${sug && sug.protocole === 'bacnet'
      ? _cxT(`${props.length} point(s) lu(s) dans l'automate, avec le nom et l'unité qu'il leur donne ; ${nouveaux} pas encore suivi(s). Les compteurs sont cochés d'office ; cochez ce que vous voulez suivre. Nom, nature et unité se corrigent ici ou plus tard.`,
             `${_cxN(props.length, `point`, `points`)} read from the controller, with the name and unit it gives them; ${nouveaux} not monitored yet. Counters are ticked by default; tick what you want to monitor. Name, kind and unit can be corrected here or later.`)
      : _cxT(`${props.length} valeur(s) trouvée(s), dont ${nouveaux} pas encore suivie(s). Les totaux (somme des phases d'un départ) sont cochés d'office ; cochez les autres au besoin. Nom, nature et unité se corrigent ici ou plus tard.`,
             `${_cxN(props.length, `value`, `values`)} found, ${nouveaux} not monitored yet. Totals (sum of a feeder's phases) are ticked by default; tick the others if needed. Name, kind and unit can be corrected here or later.`)}</p>
      <div class="cx-filtres" style="margin-bottom:8px">
        <div class="search-input-wrap"><span class="search-icon">${lk('search')}</span><input class="search-input" type="search" placeholder="Filtrer…" oninput="_cxFiltrerDecouverte(this.value)"></div>
        <button type="button" class="btn btn-ghost btn-sm" onclick="_cxCocherDecouverte(true)">Tout cocher</button>
        <button type="button" class="btn btn-ghost btn-sm" onclick="_cxCocherDecouverte(false)">Tout décocher</button>
      </div>
      <div class="table-wrap cx-decouverte"><table><thead><tr><th></th><th>Nom</th><th>Nature</th><th>Unité</th><th>${_cxT(`Valeur lue`, `Value read`)}</th><th>${_cxT(`Adresse`, `Address`)}</th></tr></thead><tbody>
      ${props.map((p, i) => `<tr data-cx-cherche="${escHtml((p.nom || '') + ' ' + (p.code || ''))}">
        <td><input type="checkbox" class="cx-dec-case" id="cxd_${i}" ${p.deja ? 'disabled' : (p.coche ? 'checked' : '')} aria-label="Ajouter"></td>
        <td><input class="form-control cx-input-compact" id="cxdn_${i}" value="${escHtml(p.nom || '')}" style="min-width:330px" title="${escHtml(p.nom || '')}" ${p.deja ? 'disabled' : ''}></td>
        <td><select class="form-control cx-input-compact" id="cxdt_${i}" ${p.deja ? 'disabled' : ''}>${_cxOptions(CX_NATURES, p.nature || 'mesure')}</select></td>
        <td><input class="form-control cx-input-compact" id="cxdu_${i}" value="${escHtml(p.unite || '')}" style="width:70px" ${p.deja ? 'disabled' : ''}></td>
        <td style="white-space:nowrap">${p.valeur !== null && p.valeur !== undefined && p.valeur !== '' ? escHtml(typeof p.valeur === 'number' ? _cxNombre(p.valeur, 4) : String(p.valeur)) : '<span class="cx-aide">—</span>'}</td>
        <td style="word-break:break-all;font-size:11px"><code class="cx-code">${escHtml(p.code || '')}</code>${p.deja ? ` <span class="badge badge-gray">${_cxT(`déjà suivi`, `already monitored`)}</span>` : ''}</td>
      </tr>`).join('')}
      </tbody></table></div>`;
  }
  openModal(titre, h, sug || props.length ? _cxReconAppliquer : null, _cxT(`Appliquer`, `Apply`), _cxT(`Fermer`, `Close`), '1180px');
}

async function _cxReconAppliquer() {
  const dec = CxUI.decouverte;
  if (!dec || !dec.recon) return;
  const points = [];
  dec.props.forEach((p, i) => {
    const c = document.getElementById('cxd_' + i);
    if (!c || !c.checked || c.disabled) return;
    points.push({ code: p.code, nom: gv('cxdn_' + i).trim() || p.nom, nature: gv('cxdt_' + i), unite: gv('cxdu_' + i).trim() });
  });
  const reglages = !!(document.getElementById('cxrReglages') || {}).checked;
  const supprimerPoints = !!(document.getElementById('cxrSuppr') || {}).checked;
  if (!reglages && !points.length) { toast(_cxT(`Rien à appliquer : cochez les réglages ou des points.`, `Nothing to apply: tick the settings or some points.`), 'error'); return; }
  if (!reglages && dec.recon.protocole === 'auto') { toast(_cxT(`Cochez d'abord « Appliquer ces réglages » : le protocole de l'appareil n'est pas encore choisi.`, `Tick “Apply these settings” first: the device's protocol is not chosen yet.`), 'error'); return; }
  try {
    const r = await apiRequest('connect_reconnaissance', 'POST', { op: 'appliquer', id: dec.id, reglages, supprimerPoints, points });
    closeModal();
    toast((reglages ? _cxT(`Réglages appliqués. `, `Settings applied. `) : '') + _cxT(`${r.crees} point(s) ajouté(s).`, `${_cxN(r.crees, `point`, `points`)} added.`)
          + _cxT(` Retéléchargez le collecteur du PC si ses pilotes datent d'avant la 1.4.`, ` Download the PC's collector again if its drivers predate 1.4.`), 'success');
    if (r.erreurs && r.erreurs.length) toast(_cxT(`Non ajoutés : `, `Not added: `) + r.erreurs.slice(0, 3).join(' ; '), 'error');
    if (App.currentPage === 'connect_config') { CxUI.filtrePa = String(dec.id); _cxRafraichir(); }
  } catch (err) { toast(err.message, 'error'); }
}

function _cxSupprimerPoint(id) {
  const p = (CxUI.points || []).find(x => x.Id == id) || {};
  showConfirm(_cxT(`Supprimer le point « ${p.Nom || ''} » ? Son historique, ses règles d'alarme et ses relevés automatiques seront supprimés.`,
                   `Delete point “${p.Nom || ''}”? Its history, alarm rules and automatic readings will be deleted.`), async () => {
    try {
      await apiRequest('connect_points', 'DELETE', null, id);
      toast('Point supprimé.', 'success');
      _cxRafraichir();
    } catch (err) { toast(err.message, 'error'); }
  });
}

// ── Règles d'alarme ─────────────────────────────────────────────────────────

async function _cxOngletRegles(z) {
  const regles = await apiRequest('connect_regles' + _cxLg()) || [];
  if (!regles.length) {
    z.innerHTML = `<div class="card cx-ferme"><div class="cx-ferme-icone">${lk('bell')}</div><h3>Aucune règle d'alarme</h3>
      <p class="cx-aide">${_cxT(`Une règle surveille un point : seuil dépassé, capteur muet, défaut remonté, palier d'heures de marche franchi… Elle ouvre une alarme, prévient les gestionnaires et peut créer une demande d'intervention.`,
                                `A rule watches a point: threshold exceeded, silent sensor, reported fault, running-hours step crossed… It opens an alarm, notifies the managers and can create a work request.`)}</p>
      <p class="cx-aide">${_cxT(`Elles s'ajoutent depuis l'onglet`, `They are added from the`)} <a href="#" onclick="event.preventDefault();_cxOnglet('points')">Points</a> ${_cxT(`(colonne ${lk('bell')}).`, `tab (${lk('bell')} column).`)}</p></div>`;
    return;
  }
  z.innerHTML = `<div class="card"><div class="table-wrap"><table><thead><tr>
      <th>Point</th><th>Condition</th><th>Gravité</th><th>Actions automatiques</th><th>État</th><th></th>
    </tr></thead><tbody>
    ${_cxLignesParBatiment(regles, r => r.BatimentNom, 6, r => `<tr class="${+r.Actif ? '' : 'cx-inactif'}">
      <td>${escHtml(r.PointNom || '')}<div class="cx-aide">${escHtml(r.PasserelleNom || '')}</div></td>
      <td>${r.Nom ? `<div>${escHtml(r.Nom)}</div>` : ''}<div class="${r.Nom ? 'cx-aide' : ''}">${escHtml(r.Description || '')}</div></td>
      <td>${_cxGravite(r.Gravite)}</td>
      <td>${+r.CreerDemande ? _cxT(`${lk('file-pen-line')} demande d'intervention`, `${lk('file-pen-line')} work request`) : ''}${+r.CreerDemande && +r.Notifier ? '<br>' : ''}${+r.Notifier ? _cxT(`${lk('megaphone')} notification`, `${lk('megaphone')} notification`) : ''}${!+r.CreerDemande && !+r.Notifier ? '<span class="cx-aide">alarme seule</span>' : ''}</td>
      <td>${r.EnCoursDepuis ? `<span class="badge badge-orange" title="Condition remplie, délai en cours">${_cxT(`en attente depuis `, `pending for `)}${_cxDuree((Date.now() - (_cxDateObj(r.EnCoursDepuis) || new Date()).getTime()) / 1000)}</span>`
                          : (+r.Actif ? `<span class="badge badge-teal">${_cxT(`active`, `active`)}</span>` : '<span class="badge badge-gray">désactivée</span>')}</td>
      <td><div class="actions-col">
        <button class="icon-btn" aria-label="Modifier" data-cx-info="action.regle.modifier" onclick="_cxFormRegle(${r.Id}, ${r.PointId})">${lk('pencil')}</button>
        <button class="icon-btn delete" aria-label="Supprimer" data-cx-info="action.regle.supprimer" onclick="_cxSupprimerRegle(${r.Id}, ${r.PointId}, false)">${lk('trash')}</button>
      </div></td>
    </tr>`)}
    </tbody></table></div></div>`;
}

async function _cxReglesPoint(pointId) {
  let p, regles;
  try {
    [p, regles] = await Promise.all([apiRequest('connect_points', 'GET', null, pointId), apiRequest('connect_regles&point_id=' + pointId + _cxLg())]);
  } catch (err) { toast(err.message, 'error'); return; }
  const corps = `
    <div class="cx-aide" style="margin-bottom:10px">${_cxT(`Valeur actuelle :`, `Current value:`)} <strong>${_cxValeurHtml(p)}</strong>${p.DerniereLecture ? _cxT(` · lue `, ` · read `) + _cxDepuis(p.DerniereLecture) : ''}</div>
    ${(regles || []).length ? `<ul class="cx-liste-regles">${regles.map(r => `<li class="${+r.Actif ? '' : 'cx-inactif'}">
        ${_cxGravite(r.Gravite)} <span class="cx-regle-texte">${r.Nom ? '<strong>' + escHtml(r.Nom) + '</strong> — ' : ''}${escHtml(r.Description || '')}</span>
        <span class="cx-aide">${+r.CreerDemande ? lk('file-pen-line') : ''}${+r.Notifier ? (' ' + lk('megaphone')) : ''}${+r.Actif ? '' : _cxT(` (désactivée)`, ` (disabled)`)}</span>
        <span class="actions-col" style="margin-left:auto">
          <button class="icon-btn" aria-label="Modifier" data-cx-info="action.regle.modifier" onclick="_cxFormRegle(${r.Id}, ${p.Id})">${lk('pencil')}</button>
          <button class="icon-btn delete" aria-label="Supprimer" data-cx-info="action.regle.supprimer" onclick="_cxSupprimerRegle(${r.Id}, ${p.Id}, true)">${lk('trash')}</button>
        </span></li>`).join('')}</ul>`
      : '<p class="cx-aide">Aucune règle sur ce point.</p>'}
    <button type="button" class="btn btn-primary btn-sm" onclick="_cxFormRegle(0, ${p.Id})">＋ Ajouter une règle</button>`;
  openModal(_cxT(`Règles d'alarme — `, `Alarm rules — `) + p.Nom, corps, null, 'Enregistrer', 'Fermer', '720px');
}

async function _cxFormRegle(id, pointId) {
  let p, r = null;
  try {
    p = await apiRequest('connect_points', 'GET', null, pointId);
    if (id) r = (await apiRequest('connect_regles&point_id=' + pointId) || []).find(x => x.Id == id) || null;
  } catch (err) { toast(err.message, 'error'); return; }
  const v = r || { Operateur: p.Nature === 'alarme' ? 'egal' : (p.Nature === 'compteur' ? 'palier' : 'sup'), Gravite: 'majeure', CreerDemande: 1, Notifier: 1, Actif: 1, DelaiMinutes: 0 };
  if (!r && p.Nature === 'alarme') v.Seuil = 1;
  const corps = `<div class="cx-form">
    <div class="cx-aide" style="margin-bottom:10px">${_cxT(`Point « ${escHtml(p.Nom)} » — valeur actuelle :`, `Point “${escHtml(p.Nom)}” — current value:`)} <strong>${_cxValeurHtml(p)}</strong></div>
    <div class="form-grid">
      <div class="form-group" style="grid-column:1/-1"><label class="form-label" for="cxrOp">Déclencher une alarme quand la valeur… ${_cxBulle('rg.condition')}</label>
        <select id="cxrOp" class="form-control" onchange="_cxRegleChamps()">${_cxOptions(CX_OPERATEURS, v.Operateur)}</select></div>
      <div class="form-group" data-cxr="seuil"><label class="form-label" for="cxrSeuil" id="cxrSeuilLib">Seuil</label>
        <input id="cxrSeuil" class="form-control" type="number" step="any" value="${escHtml(v.Seuil ?? '')}"><div class="cx-aide" id="cxrSeuilAide"></div></div>
      <div class="form-group" data-cxr="seuil2"><label class="form-label" for="cxrSeuil2" id="cxrSeuil2Lib">Seuil 2</label>
        <input id="cxrSeuil2" class="form-control" type="number" step="any" value="${escHtml(v.Seuil2 ?? '')}"><div class="cx-aide" id="cxrSeuil2Aide"></div></div>
      <div class="form-group" data-cxr="texte"><label class="form-label" for="cxrTexte">… ou l'état (texte)</label>
        <input id="cxrTexte" class="form-control" maxlength="100" value="${escHtml(v.ValeurTexte || '')}" placeholder="ex. Défaut, Faulted, Alarm">
        <div class="cx-aide">Pour une valeur transmise en mots plutôt qu'en nombre.</div></div>
      <div class="form-group" data-cxr="delai"><label class="form-label" for="cxrDelai" id="cxrDelaiLib" data-bulle="rg.delai">Pendant au moins (minutes)</label>
        <input id="cxrDelai" class="form-control" type="number" min="0" max="10080" value="${escHtml(v.DelaiMinutes ?? 0)}"><div class="cx-aide" id="cxrDelaiAide"></div></div>
      <div class="form-group"><label class="form-label" for="cxrGrav">Gravité ${_cxBulle('rg.gravite')}</label>
        <select id="cxrGrav" class="form-control">${_cxOptions(CX_GRAVITES.map(g => [g[0], g[1]]), v.Gravite)}</select>
        <div class="cx-aide">Fixe l'urgence de la demande d'intervention (critique → Urgente).</div></div>
      <div class="form-group"><label class="form-label" for="cxrNom">Nom (facultatif)</label>
        <input id="cxrNom" class="form-control" maxlength="150" value="${escHtml(v.Nom || '')}" placeholder="ex. Surchauffe chaufferie"></div>
    </div>
    <div class="cx-form-section">Quand l'alarme se déclenche</div>
    <label class="cx-case"><input type="checkbox" id="cxrDI" ${+v.CreerDemande ? 'checked' : ''}> <span>Créer une demande d'intervention</span>${p.EquipementNumero ? _cxT(` (équipement `, ` (equipment `) + escHtml(p.EquipementNumero) + ')' : ''} ${_cxBulle('rg.demande')}</label>
    <label class="cx-case"><input type="checkbox" id="cxrNotif" ${+v.Notifier ? 'checked' : ''}> Prévenir les gestionnaires (notification) ${_cxBulle('rg.notifier')}</label>
    <label class="cx-case"><input type="checkbox" id="cxrActif" ${+v.Actif ? 'checked' : ''}> Règle active</label>
    <div class="cx-aide" style="margin-top:8px">Une alarme déjà ouverte ne crée pas de seconde demande : elle se termine d'elle-même quand la condition cesse.</div>
  </div>`;
  openModal(id ? 'Modifier la règle' : "Nouvelle règle d'alarme", corps, () => _cxEnregistrerRegle(id, pointId), 'Enregistrer', 'Annuler', '680px');
  CxUI.reglePoint = p;
  _cxRegleChamps();
}

function _cxRegleChamps() {
  const op = gv('cxrOp');
  const u = (CxUI.reglePoint && CxUI.reglePoint.Unite) ? ' (' + CxUI.reglePoint.Unite + ')' : '';
  const montrer = (cle, oui) => { const el = document.querySelector(`[data-cxr="${cle}"]`); if (el) el.hidden = !oui; };
  // Libellé et unité en nœuds séparés : le libellé seul est reconnu par le pack de langue.
  const lib = (id, t, avecUnite) => {
    const el = document.getElementById(id);
    if (!el) return;
    el.textContent = '';
    if (!t) return;
    const s = document.createElement('span');
    s.textContent = t;
    el.append(s);
    if (avecUnite && u) el.append(u);
    if (el.dataset.bulle) el.insertAdjacentHTML('beforeend', ' ' + _cxBulle(el.dataset.bulle));
  };
  montrer('seuil', ['sup', 'inf', 'hors_plage', 'egal', 'different', 'palier'].includes(op));
  montrer('seuil2', ['sup', 'inf', 'hors_plage'].includes(op));
  montrer('texte', ['egal', 'different'].includes(op));
  montrer('delai', op !== 'palier');
  lib('cxrSeuilAide', '');
  lib('cxrSeuil2Aide', '');
  if (op === 'sup') {
    lib('cxrSeuilLib', 'Seuil', true); lib('cxrSeuil2Lib', 'Retour à la normale sous (facultatif)', true);
    lib('cxrSeuil2Aide', _cxT(`Hystérésis : l'alarme ne se termine que sous cette valeur, pour éviter le battement autour du seuil.`, `Hysteresis: the alarm only ends below this value, to avoid flapping around the threshold.`));
  }
  if (op === 'inf') {
    lib('cxrSeuilLib', 'Seuil', true); lib('cxrSeuil2Lib', 'Retour à la normale au-dessus de (facultatif)', true);
    lib('cxrSeuil2Aide', _cxT(`Hystérésis : l'alarme ne se termine qu'au-dessus de cette valeur.`, `Hysteresis: the alarm only ends above this value.`));
  }
  if (op === 'hors_plage') { lib('cxrSeuilLib', 'Minimum', true); lib('cxrSeuil2Lib', 'Maximum', true); }
  if (op === 'egal' || op === 'different') { lib('cxrSeuilLib', 'Valeur'); lib('cxrSeuilAide', 'Pour un défaut transmis en 0/1 : 1.'); }
  if (op === 'palier') {
    lib('cxrSeuilLib', 'Pas du palier', true);
    lib('cxrSeuilAide', _cxT(`ex. 2000 : une alarme (et une demande d'entretien) à 2 000, 4 000, 6 000 h…`, `e.g. 2000: an alarm (and a maintenance request) at 2,000, 4,000, 6,000 h…`));
  }
  if (op === 'absence') {
    lib('cxrDelaiLib', 'Sans valeur depuis (minutes) *');
    lib('cxrDelaiAide', _cxT(`ex. 60 : le capteur n'a rien transmis depuis une heure (pile, radio, panne).`, `e.g. 60: the sensor has sent nothing for an hour (battery, radio, failure).`));
  } else {
    lib('cxrDelaiLib', 'Pendant au moins (minutes)');
    lib('cxrDelaiAide', _cxT(`0 = dès la première valeur. Évite les alarmes pour un pic passager.`, `0 = from the first value. Avoids alarms for a passing spike.`));
  }
}

async function _cxEnregistrerRegle(id, pointId) {
  const o = {
    pointId, operateur: gv('cxrOp'), seuil: gv('cxrSeuil'), seuil2: gv('cxrSeuil2'), valeurTexte: gv('cxrTexte').trim(),
    delaiMinutes: gv('cxrDelai') || 0, gravite: gv('cxrGrav'), nom: gv('cxrNom').trim(),
    creerDemande: document.getElementById('cxrDI').checked, notifier: document.getElementById('cxrNotif').checked,
    actif: document.getElementById('cxrActif').checked,
  };
  const champ = n => { const el = document.querySelector(`[data-cxr="${n}"]`); return el && !el.hidden; };
  if (!champ('seuil')) o.seuil = '';
  if (!champ('seuil2')) o.seuil2 = '';
  if (!champ('texte')) o.valeurTexte = '';
  try {
    if (id) await apiRequest('connect_regles', 'PUT', o, id);
    else await apiRequest('connect_regles', 'POST', o);
    toast('Règle enregistrée.', 'success');
    if (App.currentPage === 'connect_config' && CxUI.onglet === 'regles') { closeModal(); _cxOnglet('regles'); }
    else { _cxReglesPoint(pointId); if (App.currentPage === 'connect_config') _cxOnglet(CxUI.onglet); }
  } catch (err) { toast(err.message, 'error'); }
}

function _cxSupprimerRegle(id, pointId, depuisPoint) {
  showConfirm(_cxT(`Supprimer cette règle d'alarme ?`, `Delete this alarm rule?`), async () => {
    try {
      await apiRequest('connect_regles', 'DELETE', null, id);
      toast('Règle supprimée.', 'success');
      if (depuisPoint) _cxReglesPoint(pointId);
      _cxRafraichir();
    } catch (err) { toast(err.message, 'error'); }
  });
}

// ── Relevés automatiques (liaisons vers le module Énergie) ────────────────

async function _cxOngletReleves(z) {
  const [liaisons, enAttente] = await Promise.all([
    apiRequest('connect_liaisons' + _cxLg()),
    apiRequest('connect_executions&statut=attente'),
  ]);
  CxUI.attente = enAttente || [];
  let h = '';
  if (CxUI.attente.length) {
    h += `<div class="card cx-bloc-attente"><div class="card-header"><div class="card-title">${lk('hourglass')} Relevés à valider</div></div>
      <div class="cx-aide" style="padding:0 16px 8px">${_cxT(`Rien n'est écrit dans le module Énergie tant qu'un gestionnaire n'a pas tranché. Valider la plus récente échéance clôt les plus anciennes du même relevé, sans les écrire.`,
                                                         `Nothing is written to the Energy module until a manager decides. Confirming the most recent one closes the older ones of the same reading, without writing them.`)}</div>
      <div class="table-wrap"><table><thead><tr><th>Échéance</th><th>Relevé</th><th>Valeur lue</th><th>Index précédent</th><th>Consommation</th><th>Motif</th><th></th></tr></thead><tbody>
      ${_cxLignesParBatiment(CxUI.attente, x => x.BatimentNom, 7, x => {
        const st = CX_EXEC[x.Statut] || [x.Statut, 'gray'];
        const anciennes = +x.PlusAnciennes || 0;
        return `<tr>
        <td style="white-space:nowrap">${_cxDate(x.Prevue)}${anciennes ? `<div class="cx-aide">${_cxT(`+ ${anciennes} plus ancienne${anciennes > 1 ? 's' : ''} en attente`, `+ ${anciennes} older pending`)}</div>` : ''}</td>
        <td>${escHtml(x.LiaisonNom || x.PointNom || '')}<div class="cx-aide">${escHtml(x.PointNom || '')} → ${escHtml(x.CibleNom || '?')}</div></td>
        <td style="white-space:nowrap">${_cxNombre(x.Valeur, 3)} ${escHtml(x.PointUnite || '')}</td>
        <td style="white-space:nowrap">${_cxNombre(x.ValeurPrecedente, 3)}</td>
        <td style="white-space:nowrap">${_cxNombre(x.Consommation, 3)}</td>
        <td><span class="badge badge-${st[1]}">${st[0]}</span><div class="cx-aide">${escHtml(x.Message || '')}</div></td>
        <td><div class="actions-col cx-actions-releve">
          <button class="btn btn-ghost btn-sm" data-cx-info="action.valider" onclick="_cxValiderReleve(${x.Id}, ${anciennes})">✓ Valider</button>
          ${anciennes && !+x.PlusAnciennesAnomalies && x.Statut === 'a_valider' ? `<button class="btn btn-ghost btn-sm" onclick="_cxValiderTout(${x.LiaisonId}, ${anciennes + 1})">${_cxT(`✓ Tout écrire`, `✓ Write all`)} (${anciennes + 1})</button>` : ''}
          <button class="btn btn-ghost btn-sm" data-cx-info="action.rejeter" onclick="_cxRejeterReleve(${x.Id})">✕ Rejeter</button>
          <button class="icon-btn" aria-label="Historique" data-cx-info="action.historique" onclick="_cxHistoriqueLiaison(${x.LiaisonId})">${lk('scroll-text')}</button>
        </div></td></tr>`;
      })}
      </tbody></table></div></div>`;
  }
  h += `<div class="cx-filtres" style="margin:${enAttente.length ? '14px' : '0'} 0 10px">
      <span class="cx-aide">${_cxT(`Un relevé automatique recopie, à heure fixe, la valeur d'un compteur raccordé dans un compteur du module Énergie — comme une saisie, sans saisie.`,
                                  `An automatic reading copies, at a set time, the value of a connected meter into a meter of the Energy module — like a manual entry, without typing.`)}</span>
      <div style="flex:1"></div>
      <button class="btn btn-primary btn-sm" onclick="_cxFormLiaison(0, 0)">＋ Nouveau relevé automatique</button>
    </div>`;
  if (!(liaisons || []).length) {
    h += `<div class="card"><div class="no-results">${_cxT(`Aucun relevé automatique. Exemple : « chaque jour à 23 h 55, l'index du compteur général va dans le compteur Électricité du bâtiment A ».`,
                                                       `No automatic reading. Example: “every day at 23:55, the main meter's index goes into building A's Electricity meter”.`)}</div></div>`;
  } else {
    h += `<div class="card"><div class="table-wrap"><table><thead><tr><th>Relevé</th><th>Vers</th><th>Quand</th><th>Prochain</th><th>Dernier</th><th></th></tr></thead><tbody>
      ${_cxLignesParBatiment(liaisons, l => l.BatimentNom, 6, l => `<tr class="${+l.Actif ? '' : 'cx-inactif'}">
        <td>${escHtml(l.Nom || l.PointNom || '')}<div class="cx-aide">${escHtml(l.PointNom || '')} · ${escHtml(l.PasserelleNom || '')}${+l.Coefficient !== 1 ? ' · × ' + _cxNombre(l.Coefficient, 6) : ''}</div></td>
        <td>${lk('zap')} ${escHtml(l.CibleNom || '?')}${+l.ValidationManuelle ? '<div class="cx-aide">validation manuelle</div>' : ''}</td>
        <td>${escHtml(l.Description || '')}</td>
        <td style="white-space:nowrap">${+l.Actif ? _cxDate(l.ProchaineExecution) : '<span class="badge badge-gray">désactivé</span>'}</td>
        <td style="white-space:nowrap">${l.DerniereExecution ? _cxDate(l.DerniereExecution) : `<span class="cx-aide">${_cxT(`jamais`, `never`)}</span>`}</td>
        <td><div class="actions-col">
          <button class="icon-btn" aria-label="Historique" data-cx-info="action.historique" onclick="_cxHistoriqueLiaison(${l.Id})">${lk('scroll-text')}</button>
          <button class="icon-btn" aria-label="Modifier" data-cx-info="action.releve.modifier" onclick="_cxFormLiaison(${l.Id}, ${l.PointId})">${lk('pencil')}</button>
          <button class="icon-btn delete" aria-label="Supprimer" data-cx-info="action.releve.supprimer" onclick="_cxSupprimerLiaison(${l.Id})">${lk('trash')}</button>
        </div></td></tr>`)}
      </tbody></table></div></div>`;
  }
  z.innerHTML = h;
}

async function _cxFormLiaison(id, pointId) {
  let l = null, points, compteurs;
  try {
    [points, compteurs] = await Promise.all([apiRequest('connect_points'), _cxRef('compteurs')]);
    if (id) l = (await apiRequest('connect_liaisons') || []).find(x => x.Id == id) || null;
  } catch (err) { toast(err.message, 'error'); return; }
  const v = l || { PointId: pointId || '', Frequence: 'quotidien', Heure: '23:55', Coefficient: 1, ToleranceMinutes: 15, FacteurAnomalie: 5, ValidationManuelle: 0, Actif: 1, JourMois: 1 };
  const jours = String(v.JoursSemaine || '').split(',').filter(Boolean).map(Number);
  const mois = String(v.MoisActifs || '').split(',').filter(Boolean).map(Number);
  const tries = (points || []).slice().sort((a, b) => (a.Nature === 'compteur' ? 0 : 1) - (b.Nature === 'compteur' ? 0 : 1) || String(a.PasserelleNom).localeCompare(String(b.PasserelleNom), 'fr'));
  const corps = `<div class="cx-form" id="cxlForm">
    <div class="form-grid">
      <div class="form-group" style="grid-column:1/-1"><label class="form-label" for="cxlPoint">Valeur à relever * ${_cxBulle('rl.valeur')}</label>
        <select id="cxlPoint" class="form-control"><option value="">— choisir un point —</option>
          ${tries.map(p => `<option value="${p.Id}"${p.Id == v.PointId ? ' selected' : ''}>${escHtml(p.PasserelleNom + ' — ' + p.Nom + (p.Unite ? ' (' + p.Unite + ')' : '') + (p.Nature === 'compteur' ? '' : ' · ' + _cxTr(Object.fromEntries(CX_NATURES)[p.Nature] || p.Nature).toLowerCase()))}</option>`).join('')}
        </select></div>
      <div class="form-group"><label class="form-label" for="cxlCible">Compteur du module Énergie qui reçoit le relevé * ${_cxBulle('rl.cible')}</label>
        <select id="cxlCible" class="form-control"><option value="">— choisir —</option>
          ${(compteurs || []).map(c => `<option value="${c.Id}"${c.Id == v.CibleId ? ' selected' : ''}>${escHtml((c.Nom || '#' + c.Id) + (c.Type ? ' · ' + c.Type : '') + (c.Unite ? ' (' + c.Unite + ')' : ''))}</option>`).join('')}
        </select>
        ${(compteurs || []).length ? '' : `<div class="cx-aide">${_cxT(`Aucun compteur : créez-le d'abord dans Énergie.`, `No meter: create it first in Energy.`)}</div>`}</div>
      <div class="form-group"><label class="form-label" for="cxlCoef">Coefficient ${_cxBulle('rl.coef')}</label>
        <input id="cxlCoef" class="form-control" type="number" step="any" value="${escHtml(v.Coefficient ?? 1)}">
        <div class="cx-aide">${_cxT(`1 si les unités sont les mêmes ; 0,001 pour passer des Wh aux kWh.`, `1 if the units are the same; 0.001 to go from Wh to kWh.`)}</div></div>
      <div class="form-group" style="grid-column:1/-1"><label class="form-label" for="cxlNom">Nom (facultatif)</label>
        <input id="cxlNom" class="form-control" maxlength="150" value="${escHtml(v.Nom || '')}" placeholder="ex. Relevé quotidien — électricité bâtiment A"></div>
    </div>
    <div class="cx-form-section">Quand</div>
    <div class="form-grid">
      <div class="form-group"><label class="form-label" for="cxlFreq">Fréquence ${_cxBulle('rl.frequence')}</label>
        <select id="cxlFreq" class="form-control" onchange="_cxLiaisonChamps()">${_cxOptions([['quotidien', 'Chaque jour'], ['hebdomadaire', 'Certains jours de la semaine'], ['mensuel', 'Une fois par mois'], ['releve', 'À chaque relevé du PC releveur (appareil hors réseau)'], ['personnalise', 'Expression cron (avancé)']], v.Frequence)}</select></div>
      <div class="form-group" data-cxl="heure"><label class="form-label" for="cxlHeure">${_cxT(`À`, `At`)}</label>
        <input id="cxlHeure" class="form-control" type="time" value="${escHtml(v.Heure || '23:55')}"></div>
      <div class="form-group" data-cxl="jours" style="grid-column:1/-1"><span class="form-label" id="cxlJoursLib">Jours</span>
        <div class="cx-jours" role="group" aria-labelledby="cxlJoursLib">${_cxJoursCourts().map((j, i) => `<label class="cx-jour"><input type="checkbox" class="cxl-jour" value="${i + 1}" ${jours.includes(i + 1) ? 'checked' : ''}>${j}</label>`).join('')}</div>
        <div class="cx-aide" id="cxlJoursAide"></div></div>
      <div class="form-group" data-cxl="jourmois"><label class="form-label" for="cxlJM">Jour du mois</label>
        <select id="cxlJM" class="form-control">${_cxOptions(Array.from({ length: 28 }, (_, i) => [i + 1, _cxQuantieme(i + 1)]).concat([[-1, _cxT(`le dernier jour du mois`, `the last day of the month`)]]), v.JourMois ?? 1)}</select></div>
      <div class="form-group" data-cxl="cron" style="grid-column:1/-1"><label class="form-label" for="cxlCron">Expression cron</label>
        <input id="cxlCron" class="form-control" value="${escHtml(v.ExpressionCron || '')}" placeholder="55 23 * * 1-5" spellcheck="false">
        <div class="cx-aide">${_cxT(`minute heure jour mois jour-de-semaine (1 = lundi). ex. « 0 6 1,15 * * » : le 1er et le 15 à 6 h.`, `minute hour day month day-of-week (1 = Monday). e.g. “0 6 1,15 * *”: the 1st and the 15th at 6:00.`)}</div></div>
      <div class="form-group" data-cxl="mois" style="grid-column:1/-1"><span class="form-label" id="cxlMoisLib">Seulement certains mois <span class="cx-aide">${_cxT(`(aucun coché = toute l'année)`, `(none ticked = all year)`)}</span></span>
        <div class="cx-jours" role="group" aria-labelledby="cxlMoisLib">${_cxMoisCourts().map((m, i) => `<label class="cx-jour"><input type="checkbox" class="cxl-mois" value="${i + 1}" ${mois.includes(i + 1) ? 'checked' : ''}>${m}</label>`).join('')}</div></div>
    </div>
    <details class="cx-avance"><summary>Contrôles</summary>
      <div class="form-grid" style="margin-top:8px">
        <div class="form-group"><label class="form-label" for="cxlTol">Tolérance (minutes) ${_cxBulle('rl.tolerance')}</label>
          <input id="cxlTol" class="form-control" type="number" min="1" max="720" value="${escHtml(v.ToleranceMinutes ?? 15)}">
          <div class="cx-aide">La valeur la plus proche de l'heure prévue, à ± cette durée. Sans valeur : relevé « manquant » et alarme mineure.</div></div>
        <div class="form-group"><label class="form-label" for="cxlFact">Anomalie si la consommation dépasse (× la médiane) ${_cxBulle('rl.anomalie')}</label>
          <input id="cxlFact" class="form-control" type="number" min="0" step="0.5" value="${escHtml(v.FacteurAnomalie ?? 5)}">
          <div class="cx-aide">${_cxT(`0 = pas de contrôle. Un index qui recule est toujours mis en anomalie.`, `0 = no check. An index that goes backwards is always flagged as an anomaly.`)}</div></div>
      </div>
      <label class="cx-case"><input type="checkbox" id="cxlValid" ${+v.ValidationManuelle ? 'checked' : ''}> Faire valider chaque relevé par un gestionnaire avant de l'écrire ${_cxBulle('rl.validation')}</label>
    </details>
    <label class="cx-case" style="margin-top:8px"><input type="checkbox" id="cxlActif" ${+v.Actif ? 'checked' : ''}> Relevé actif</label>
    <div class="cx-form-section">Aperçu</div>
    <div id="cxlApercu" class="cx-apercu" role="status">Choisissez la valeur à relever et le compteur.</div>
  </div>`;
  openModal(id ? 'Modifier le relevé automatique' : 'Nouveau relevé automatique', corps, () => _cxEnregistrerLiaison(id), 'Enregistrer', 'Annuler', '780px');
  const form = document.getElementById('cxlForm');
  if (form) {
    form.addEventListener('input', _cxApercuLiaisonDiffere);
    form.addEventListener('change', _cxApercuLiaisonDiffere);
  }
  _cxLiaisonChamps();
  _cxApercuLiaison();
}

function _cxLiaisonChamps() {
  const f = gv('cxlFreq');
  const montrer = (cle, oui) => { const el = document.querySelector(`[data-cxl="${cle}"]`); if (el) el.hidden = !oui; };
  montrer('heure', f !== 'personnalise' && f !== 'releve');
  montrer('jours', f === 'quotidien' || f === 'hebdomadaire');
  montrer('jourmois', f === 'mensuel');
  montrer('cron', f === 'personnalise');
  montrer('mois', f !== 'personnalise' && f !== 'releve');
  const aide = document.getElementById('cxlJoursAide');
  if (aide) aide.textContent = f === 'quotidien'
    ? _cxT(`Aucun coché = tous les jours. ex. du lundi au vendredi pour un bâtiment fermé le week-end.`, `None ticked = every day. e.g. Monday to Friday for a building closed at weekends.`)
    : 'Au moins un jour.';
}

function _cxLireLiaison() {
  return {
    pointId: gv('cxlPoint'), destination: 'releve_energie', cibleId: gv('cxlCible'), coefficient: gv('cxlCoef'),
    nom: gv('cxlNom').trim(), frequence: gv('cxlFreq'), heure: gv('cxlHeure') || '23:55',
    joursSemaine: [...document.querySelectorAll('.cxl-jour:checked')].map(c => +c.value),
    moisActifs: [...document.querySelectorAll('.cxl-mois:checked')].map(c => +c.value),
    jourMois: gv('cxlJM'), expressionCron: gv('cxlCron').trim(),
    toleranceMinutes: gv('cxlTol'), facteurAnomalie: gv('cxlFact'),
    validationManuelle: !!(document.getElementById('cxlValid') || {}).checked,
    actif: !!(document.getElementById('cxlActif') || {}).checked,
  };
}

function _cxApercuLiaisonDiffere() {
  if (CxUI.apercuMinuteur) clearTimeout(CxUI.apercuMinuteur);
  CxUI.apercuMinuteur = setTimeout(_cxApercuLiaison, 350);
}

async function _cxApercuLiaison() {
  const z = document.getElementById('cxlApercu');
  if (!z) return;
  const o = _cxLireLiaison();
  if (!o.pointId) { z.textContent = 'Choisissez la valeur à relever.'; return; }
  try {
    const r = await apiRequest('connect_liaison_apercu' + _cxLg(), 'POST', o);
    if (!document.getElementById('cxlApercu')) return;
    let h = `${lk('calendar')} <strong>${escHtml(r.libelle || '')}</strong>`;
    if ((r.echeances || []).length) h += '<br>' + _cxT(`Prochains relevés : `, `Next readings: `) + r.echeances.map(d => _cxDate(d)).join(' · ');
    else if (r.sans_echeance) h += '<br>' + _cxT(`Chaque fois que le PC releveur dépose un relevé de ce point, à l'heure réelle de la lecture.`, `Each time the reader PC deposits a reading of this point, at the real time of the reading.`);
    else h += '<br>' + _cxT(`${lk('triangle-exclamation-point', 'warn')} Aucune échéance dans les deux ans : vérifiez le calendrier.`, `${lk('triangle-exclamation-point', 'warn')} No due date within two years: check the schedule.`);
    if (r.valeur_actuelle !== null && r.valeur_actuelle !== undefined) h += `<br>Valeur actuelle (coefficient appliqué) : <strong>${_cxNombre(r.valeur_actuelle, 3)}</strong>${r.lecture ? _cxT(` — lue `, ` — read `) + _cxDepuis(r.lecture) : ''}`;
    else h += '<br>' + _cxT(`Le point n'a pas encore de valeur.`, `The point has no value yet.`);
    if (r.dernier_index !== null && r.dernier_index !== undefined) h += '<br>' + _cxT(`Dernier index du compteur Énergie : `, `Last index of the Energy meter: `) + `${_cxNombre(r.dernier_index, 3)} (${_cxDate(r.date_dernier_index, false)})`;
    if (r.alerte) h += `<div class="cx-erreur" style="margin:8px 0 0">${lk('triangle-exclamation-point', 'warn')} ${escHtml(r.alerte)}</div>`;
    if ((r.erreurs || []).length) h += `<div class="cx-erreur-texte" style="margin-top:6px">${r.erreurs.map(escHtml).join('<br>')}</div>`;
    z.innerHTML = h;
  } catch (err) {
    z.innerHTML = `<span class="cx-erreur-texte">${escHtml(err.message)}</span>`;
  }
}

async function _cxEnregistrerLiaison(id) {
  const o = _cxLireLiaison();
  if (!o.pointId) { toast('Choisissez la valeur à relever.', 'error'); return; }
  if (!o.cibleId) { toast('Choisissez le compteur qui reçoit le relevé.', 'error'); return; }
  try {
    if (id) await apiRequest('connect_liaisons', 'PUT', o, id);
    else await apiRequest('connect_liaisons', 'POST', o);
    closeModal();
    toast('Relevé automatique enregistré.', 'success');
    if (App.currentPage === 'connect_config') { CxUI.onglet = CxUI.onglet === 'points' ? 'points' : 'releves'; _cxOnglet(CxUI.onglet); }
  } catch (err) { toast(err.message, 'error'); }
}

function _cxSupprimerLiaison(id) {
  showConfirm('Supprimer ce relevé automatique ? Les relevés déjà écrits dans le module Énergie restent.', async () => {
    try {
      await apiRequest('connect_liaisons', 'DELETE', null, id);
      toast('Relevé automatique supprimé.', 'success');
      _cxRafraichir();
    } catch (err) { toast(err.message, 'error'); }
  });
}

async function _cxHistoriqueLiaison(id) {
  let ex;
  try { ex = await apiRequest('connect_executions&liaison_id=' + id + '&limite=200') || []; } catch (err) { toast(err.message, 'error'); return; }
  const corps = ex.length ? `<div class="table-wrap"><table><thead><tr><th>Échéance</th><th>Statut</th><th>Valeur</th><th>Consommation</th><th>Détail</th></tr></thead><tbody>
    ${ex.map(x => { const s = CX_EXEC[x.Statut] || [x.Statut, 'gray']; return `<tr>
      <td style="white-space:nowrap">${_cxDate(x.Prevue)}${x.Executee ? `<div class="cx-aide">${_cxT(`traité `, `processed `)}${_cxDate(x.Executee)}</div>` : ''}</td>
      <td><span class="badge badge-${s[1]}">${s[0]}</span>${x.ValidePar ? `<div class="cx-aide">${_cxT(`par `, `by `)}${escHtml(x.ValidePar)}</div>` : ''}</td>
      <td>${_cxNombre(x.Valeur, 3)}${x.MesureHorodatage ? `<div class="cx-aide">${_cxT(`mesure du `, `measured `)}${_cxDate(x.MesureHorodatage)}</div>` : ''}</td>
      <td>${_cxNombre(x.Consommation, 3)}</td>
      <td class="cx-aide">${escHtml(x.Message || '')}${x.ReleveId ? _cxT(` · relevé n° `, ` · reading #`) + x.ReleveId : ''}</td></tr>`; }).join('')}
    </tbody></table></div>` : `<p class="cx-aide">Aucune échéance passée pour l'instant.</p>`;
  openModal('Historique du relevé automatique', corps, null, 'Enregistrer', 'Fermer', '860px');
}

function _cxValiderTout(liaisonId, n) {
  showConfirm(_cxT(`Écrire les ${n} relevés en attente dans le module Énergie, du plus ancien au plus récent ?`,
                   `Write the ${n} pending readings to the Energy module, from the oldest to the most recent?`), async () => {
    try {
      const r = await apiRequest('connect_execution_valider', 'POST', { liaison: liaisonId });
      toast(r.message || 'Relevés écrits.', 'success');
      if (typeof _refreshBadgeConnect === 'function') _refreshBadgeConnect();
      _cxRafraichir();
    } catch (err) { toast(err.message, 'error'); }
  }, 'Tout écrire');
}

function _cxValiderReleve(execId, anciennes) {
  anciennes = +anciennes || 0;
  const corps = `<p class="cx-aide" style="margin-top:0">${_cxT(`Le relevé sera écrit dans le compteur du module Énergie. Si l'index a reculé parce que le compteur a été remplacé, validez : il servira de nouveau point de départ.`,
                                                             `The reading will be written to the Energy module meter. If the index went backwards because the meter was replaced, confirm: it becomes the new starting point.`)}</p>
    ${anciennes ? `<p class="cx-aide">${_cxT(`Les ${anciennes} échéance(s) plus ancienne(s) de ce relevé, encore en attente, seront closes sans écriture.`,
                                         `The ${anciennes} older pending due date${anciennes > 1 ? 's' : ''} of this reading will be closed without writing.`)}</p>` : ''}
    <div class="form-group"><label class="form-label" for="cxvValeur">Corriger la valeur (facultatif)</label>
      <input id="cxvValeur" class="form-control" type="number" step="any" placeholder="laisser vide pour garder la valeur lue"></div>`;
  openModal('Valider le relevé', corps, async () => {
    try {
      const v = gv('cxvValeur').trim();
      const r = await apiRequest('connect_execution_valider', 'POST', v !== '' ? { id: execId, valeur: v } : { id: execId });
      closeModal();
      toast(r.message || 'Relevé validé et écrit.', 'success');
      if (typeof _refreshBadgeConnect === 'function') _refreshBadgeConnect();
      _cxRafraichir();
    } catch (err) { toast(err.message, 'error'); }
  }, 'Valider et écrire', 'Annuler', '480px');
}

function _cxRejeterReleve(execId) {
  const corps = `<div class="form-group"><label class="form-label" for="cxvMotif">Motif</label>
      <input id="cxvMotif" class="form-control" maxlength="200" placeholder="ex. valeur aberrante, capteur en défaut ce jour-là"></div>`;
  openModal('Rejeter le relevé', corps, async () => {
    try {
      await apiRequest('connect_execution_rejeter', 'POST', { id: execId, motif: gv('cxvMotif').trim() });
      closeModal();
      toast(`Relevé rejeté : rien n'est écrit.`, 'success');
      if (typeof _refreshBadgeConnect === 'function') _refreshBadgeConnect();
      _cxRafraichir();
    } catch (err) { toast(err.message, 'error'); }
  }, 'Rejeter', 'Annuler', '480px');
}

// ── Bornes de recharge ──────────────────────────────────────────────────────

async function _cxOngletBornes(z) {
  const [pas, sessions, sup] = await Promise.all([
    apiRequest('connect_passerelles'),
    apiRequest('connect_sessions&limite=300'),
    apiRequest('connect_supervision'),
  ]);
  CxUI.passerelles = pas || [];
  CxUI.sup = sup;
  const bornes = CxUI.passerelles.filter(p => p.Protocole === 'ocpp');
  const e = CxUI.etat || {};
  const o = e.ocpp || {};
  const etatService = !o.actif ? _cxT(`désactivé (Configuration → Serveur → Larka Connect)`, `disabled (Settings → Server → Larka Connect)`)
    : (o.en_marche ? _cxT(`en écoute sur `, `listening on `) + `<code>${escHtml(o.ecoute || '')}</code>` : `<strong>${_cxT(`ne tourne pas`, `not running`)}</strong>`);
  let h = `<div class="cx-bandeau cx-bandeau-info">${_cxT(`${lk('plug')} Service OCPP : ${etatService}. Les bornes se connectent à Larka (et non l'inverse) : seul ce port doit être ouvert, des bornes vers le serveur.`,
                                                          `${lk('plug')} OCPP service: ${etatService}. Chargers connect to Larka (not the other way round): only this port must be open, from the chargers to the server.`)}</div>`;
  if (!bornes.length) {
    h += `<div class="card cx-ferme"><div class="cx-ferme-icone">${lk('car')}</div><h3>Aucune borne déclarée</h3>
      <p class="cx-aide">Déclarez chaque borne avec son identifiant (ChargeBoxId) : Larka affiche alors l'adresse à saisir dans la borne.</p>
      <button class="btn btn-primary" onclick="_cxFormConnexion(0, 'ocpp')">＋ Déclarer une borne</button></div>`;
    z.innerHTML = h;
    return;
  }
  const parId = {};
  ((sup && sup.passerelles) || []).forEach(p => { parId[p.Id] = p; });
  h += `<div class="cx-grille">${_cxCartesParBatiment(bornes, b => b.BatimentNom, b => {
    const s = parId[b.Id] || {};
    const pts = s.Points || [];
    const statuts = pts.filter(p => String(p.Code || '').startsWith('ocpp.statut.'));
    const energie = pts.find(p => p.Code === 'ocpp.energie');
    const puissance = pts.find(p => p.Code === 'ocpp.puissance');
    const connectee = pts.find(p => p.Code === 'ocpp.connexion');
    const enLigne = connectee && +connectee.DerniereValeur === 1;
    const adr = String(o.adresse || '').replace('<identifiant>', encodeURIComponent(b.Identifiant || ''));
    return `<div class="card cx-carte">
      <div class="cx-carte-tete"><span class="cx-proto" aria-hidden="true">${lk('plug')}</span>
        <div class="cx-carte-titre"><div class="cx-nom">${escHtml(b.Nom)}</div><div class="cx-sous">${escHtml(b.Identifiant || '')}${b.BatimentNom ? ' · ' + escHtml(b.BatimentNom) : ''}</div></div>
        <span class="badge badge-${enLigne ? 'teal' : (b.DernierContact ? 'red' : 'gray')}">${enLigne ? 'En ligne' : (b.DernierContact ? 'Hors ligne' : 'Jamais connectée')}</span></div>
      <div class="cx-points">
        ${statuts.map(p => `<div class="cx-point" onclick="_cxHistorique(${p.Id})"><span class="cx-point-nom">${escHtml(p.Nom)}</span><span class="cx-point-val">${_cxValeurHtml(p)}</span></div>`).join('')}
        ${puissance ? `<div class="cx-point" onclick="_cxHistorique(${puissance.Id})"><span class="cx-point-nom">Puissance</span><span class="cx-point-val">${_cxValeurHtml(puissance)}</span></div>` : ''}
        ${energie ? `<div class="cx-point" onclick="_cxHistorique(${energie.Id})"><span class="cx-point-nom">Énergie (index)</span><span class="cx-point-val">${_cxValeurHtml(energie)}</span></div>` : ''}
        ${!pts.length ? '<div class="cx-vide">Points créés à la première connexion de la borne.</div>' : ''}
      </div>
      <div class="cx-carte-meta" style="padding-top:8px">Adresse à saisir dans la borne :<br><code class="cx-code-bloc">${escHtml(adr)}</code></div>
      <div class="cx-carte-actions">
        <button class="btn btn-ghost btn-sm" onclick="_cxCommandesBorne(${b.Id})">${lk('zap')} Commandes</button>
        <button class="btn btn-ghost btn-sm" onclick="_cxFormConnexion(${b.Id})">${lk('pencil')} Modifier</button>
      </div></div>`;
  }, _cxResumeConnexions)}</div>`;
  h += `<div class="card" style="margin-top:14px"><div class="card-header"><div class="card-title">Sessions de recharge</div></div>
    ${(sessions || []).length ? `<div class="table-wrap"><table><thead><tr><th>Borne</th><th>Début</th><th>Durée</th><th>Énergie</th><th>Puissance max</th><th>Badge</th><th>Fin</th></tr></thead><tbody>
      ${_cxLignesParBatiment(sessions, s => s.BatimentNom, 7, s => {
        const d0 = _cxDateObj(s.Debut), d1 = _cxDateObj(s.Fin);
        const duree = d0 ? ((d1 || new Date()).getTime() - d0.getTime()) / 1000 : null;
        return `<tr>
          <td>${escHtml(s.PasserelleNom || '')}<div class="cx-aide">${_cxT(`connecteur `, `connector `)}${escHtml(s.Connecteur ?? '')}</div></td>
          <td style="white-space:nowrap">${_cxDate(s.Debut)}</td>
          <td>${duree !== null && s.Statut !== 'interrompue' ? _cxDuree(duree) : '—'}${s.Statut === 'en_cours' ? ' <span class="badge badge-blue">en cours</span>' : ''}
            ${s.Statut === 'interrompue' ? `<span class="badge badge-orange" title="La borne n'a jamais envoyé la fin de cette charge (redémarrage, message perdu)">${_cxT(`interrompue`, `interrupted`)}</span>` : ''}
            ${s.Statut === 'refusee' ? '<span class="badge badge-red">refusée</span>' : ''}</td>
          <td>${s.EnergieWh !== null && s.EnergieWh !== undefined ? _cxNombre(s.EnergieWh / 1000, 2) + ' kWh' : '—'}</td>
          <td>${s.PuissanceMaxW ? _cxNombre(s.PuissanceMaxW / 1000, 1) + ' kW' : '—'}</td>
          <td><code class="cx-code">${escHtml(s.Badge || '—')}</code></td>
          <td>${s.Fin ? _cxDate(s.Fin) : '—'}${s.Motif ? `<div class="cx-aide">${escHtml(s.Motif)}</div>` : ''}${+s.RecuEnDiffere ? '<div class="cx-aide" title="La borne a transmis la fin de charge après coup (coupure réseau)">reçue en différé</div>' : ''}</td>
        </tr>`;
      })}
      </tbody></table></div>` : '<div class="no-results">Aucune session de recharge enregistrée.</div>'}</div>`;
  z.innerHTML = h;
}

// ── Journal : commandes et fichiers ─────────────────────────────────────────

async function _cxOngletJournal(z) {
  const [cmds, pas] = await Promise.all([apiRequest('connect_commandes&limite=200'), apiRequest('connect_passerelles'), CodesErreurUI.catalogue().catch(() => null)]);
  CxUI.passerelles = pas || [];
  const csv = CxUI.passerelles.filter(p => p.Protocole === 'csv');
  const statuts = { reussie: ['Réussie', 'teal'], echec: ['Échec', 'red'], expiree: ['Expirée', 'orange'], en_attente: ['En attente', 'gray'], envoyee: ['Envoyée', 'blue'], en_cours: ['En cours', 'blue'] };
  const f = CxUI.filtreEvt = CxUI.filtreEvt || { passerelle: '', niveau: 'incidents', jours: '30', q: '' };
  let h = `<div class="card"><div class="card-header"><div class="card-title">${lk('receipt')} ${_cxT(`Événements de supervision`, `Supervision events`)}</div>
      <button class="btn btn-ghost btn-sm" onclick="ouvrirCatalogueCodes()">${lk('book-open')} ${_cxT(`Codes d'erreur`, `Error codes`)}</button></div>
    <div class="cx-evt-filtres">
      <select class="form-control" id="cxEvtPa" aria-label="${escHtml(_cxT(`Connexion`, `Connection`))}" onchange="_cxEvtFiltrer()">
        <option value="">${_cxT(`Toutes les connexions`, `All connections`)}</option>
        ${CxUI.passerelles.map(p => `<option value="${p.Id}" ${String(f.passerelle) === String(p.Id) ? 'selected' : ''}>${escHtml(p.Nom)}</option>`).join('')}</select>
      <select class="form-control" id="cxEvtNiveau" aria-label="${escHtml(_cxT(`Gravité`, `Severity`))}" onchange="_cxEvtFiltrer()">
        ${[['incidents', _cxT(`Incidents (avertissements et erreurs)`, `Incidents (warnings and errors)`)], ['erreurs', _cxT(`Erreurs seulement`, `Errors only`)], ['', _cxT(`Tout (tests, écritures…)`, `Everything (tests, writes…)`)]]
          .map(([v, l]) => `<option value="${v}" ${f.niveau === v ? 'selected' : ''}>${l}</option>`).join('')}</select>
      <select class="form-control" id="cxEvtJours" aria-label="${escHtml(_cxT(`Période`, `Period`))}" onchange="_cxEvtFiltrer()">
        ${[['1', '24 h'], ['7', _cxT('7 jours', '7 days')], ['30', _cxT('30 jours', '30 days')], ['90', _cxT('90 jours', '90 days')], ['365', _cxT('1 an', '1 year')]]
          .map(([v, l]) => `<option value="${v}" ${f.jours === v ? 'selected' : ''}>${l}</option>`).join('')}</select>
      <input class="form-control" id="cxEvtQ" placeholder="${escHtml(_cxT(`Rechercher dans les messages…`, `Search messages…`))}" value="${escHtml(f.q)}" onkeydown="if(event.key==='Enter')_cxEvtFiltrer()">
    </div>
    <div id="cxEvtCodes" class="cx-evt-codes"></div>
    <div id="cxEvtListe"></div>
    <div class="cx-aide" style="padding:8px 16px 14px">${_cxT(`Une ligne quand un équipement tombe en défaut, ne répond plus qu'en partie, ou se rétablit ; quand une trame radio est refusée ; à chaque test et chaque écriture. Chaque code explique ce qui s'est passé et quoi faire (cliquer dessus).`,
      `One line when a device fails, only partly answers, or recovers; when a radio frame is refused; for each test and each write. Each code explains what happened and what to do (click it).`)}</div></div>`;
  h += `<div class="card" style="margin-top:14px"><div class="card-header"><div class="card-title">Écritures et commandes envoyées aux équipements</div></div>
    ${(cmds || []).length ? `<div class="table-wrap"><table><thead><tr><th>Date</th><th>Par</th><th>Équipement</th><th>Commande</th><th>Statut</th><th>Réponse</th></tr></thead><tbody>
      ${_cxLignesParBatiment(cmds, c => c.BatimentNom, 6, c => { const s = statuts[c.Statut] || [c.Statut, 'gray']; return `<tr>
        <td style="white-space:nowrap">${_cxDate(c.DateDemande)}</td>
        <td>${escHtml(c.Demandeur || '')}</td>
        <td>${escHtml(c.PasserelleNom || '')}${c.PointNom ? `<div class="cx-aide">${escHtml(c.PointNom)}</div>` : ''}</td>
        <td><code class="cx-code">${escHtml(c.Commande)}</code>${c.Valeur !== null && c.Valeur !== undefined && c.Valeur !== '' ? ' = ' + escHtml(c.Valeur) : ''}</td>
        <td><span class="badge badge-${s[1]}">${s[0]}</span></td>
        <td class="cx-aide">${escHtml(c.Reponse || '')}</td></tr>`; })}
      </tbody></table></div>` : `<div class="no-results">Aucune écriture ni commande pour l'instant.</div>`}
    <div class="cx-aide" style="padding:8px 16px 14px">Chaque écriture est aussi inscrite au journal de sécurité du serveur, avec le compte, la valeur précédente et le résultat.</div></div>`;
  if (csv.length) {
    h += `<div class="card" style="margin-top:14px"><div class="card-header"><div class="card-title">Fichiers CSV traités</div>
        <select class="form-control" style="width:auto" aria-label="Connexion CSV" onchange="_cxFichiersCsv(this.value)">
          ${csv.map(p => `<option value="${p.Id}">${escHtml(p.Nom)}</option>`).join('')}</select></div>
      <div id="cxFichiers"></div></div>`;
  }
  z.innerHTML = h;
  _cxEvtCharger();
  if (csv.length) _cxFichiersCsv(csv[0].Id);
}

function _cxEvtFiltrer(code) {
  CxUI.filtreEvt = { passerelle: document.getElementById('cxEvtPa')?.value || '', niveau: document.getElementById('cxEvtNiveau')?.value ?? 'incidents',
                     jours: document.getElementById('cxEvtJours')?.value || '30', q: (document.getElementById('cxEvtQ')?.value || '').trim(),
                     code: code === undefined ? (CxUI.filtreEvt || {}).code || '' : code };
  _cxEvtCharger();
}

/** Journal de supervision : pannes, rétablissements, refus, tests — avec leurs codes. */
async function _cxEvtCharger() {
  const z = document.getElementById('cxEvtListe');
  if (!z) return;
  const f = CxUI.filtreEvt || {};
  z.innerHTML = `<div class="cx-aide" style="padding:12px 16px">${lk('hourglass')} ${_cxT(`Chargement…`, `Loading…`)}</div>`;
  const qs = Object.entries({ passerelle: f.passerelle, niveau: f.niveau, jours: f.jours, q: f.q, code: f.code })
    .filter(([, v]) => v).map(([k, v]) => `&${k}=${encodeURIComponent(v)}`).join('');
  let r;
  try { r = await apiRequest('connect_evenements' + qs) || {}; }
  catch (err) { z.innerHTML = `<div class="no-results">${escHtml(err.message)}</div>`; return; }
  CodesErreurUI.retenir(r.codes);
  const codes = document.getElementById('cxEvtCodes');
  if (codes) codes.innerHTML = (r.parCode || []).length
    ? `<span class="cx-aide">${_cxT(`Par code :`, `By code:`)}</span> ` + r.parCode.map(c => `<button type="button" class="cx-evt-puce ${f.code === c.Code ? 'actif' : ''}"
        onclick="_cxEvtFiltrer(${jsArg(f.code === c.Code ? '' : c.Code)})" title="${escHtml((r.codes[c.Code] || {}).titre || '')}">${escHtml(c.Code)} <strong>${c.Total}</strong></button>`).join(' ')
      + (f.code ? ` <a href="#" onclick="event.preventDefault();_cxEvtFiltrer('')">${_cxT(`tous les codes`, `all codes`)}</a>` : '')
    : '';
  const items = r.items || [];
  if (!items.length) {
    z.innerHTML = `<div class="no-results">${f.niveau ? _cxT(`Aucun incident sur la période : tout a fonctionné.`, `No incident over the period: everything worked.`)
                                                    : _cxT(`Aucun événement sur la période.`, `No event over the period.`)}</div>`;
    return;
  }
  const icone = { error: ['ko', 'circle-x'], critique: ['ko', 'circle-x'], warning: ['warn', 'triangle-exclamation-point'], info: ['ok', 'circle-check'] };
  z.innerHTML = `<div class="table-wrap"><table class="cx-evt-table"><thead><tr><th>${_cxT(`Date`, `Date`)}</th><th>${_cxT(`Connexion`, `Connection`)}</th>
      <th>${_cxT(`Ce qui s'est passé`, `What happened`)}</th><th>${_cxT(`Que faire`, `What to do`)}</th></tr></thead><tbody>
    ${items.map(e => {
      const fi = e.Code ? r.codes[e.Code] : null;
      const cause = e.CodeCause && e.CodeCause !== e.Code ? r.codes[e.CodeCause] : null;
      const [cl, ic] = icone[e.Niveau] || icone.info;
      return `<tr class="cx-evt-${escHtml(e.Niveau)}">
        <td style="white-space:nowrap">${lk(ic, cl)} ${_cxDate(e.DateHeure)}</td>
        <td>${e.PasserelleNom ? `<a href="#" onclick="event.preventDefault();document.getElementById('cxEvtPa').value='${+e.PasserelleId}';_cxEvtFiltrer()">${escHtml(e.PasserelleNom)}</a>` : '<span class="cx-aide">—</span>'}
          ${e.Protocole ? `<div class="cx-aide">${escHtml(e.Protocole)}</div>` : ''}</td>
        <td>${escHtml(e.Message || '')}${e.Detail ? `<div class="cx-aide">${escHtml(e.Detail)}</div>` : ''}${e.Login ? `<div class="cx-aide">${_cxT(`par`, `by`)} ${escHtml(e.Login)}</div>` : ''}</td>
        <td>${fi ? `${codeErreurBadge(e.Code)} <strong>${escHtml(fi.titre)}</strong>${e.Niveau !== 'info' ? `<div class="ce-remede-court">${_ceTexte((fi.remedes || [])[0] || '')}</div>` : ''}` : ''}
          ${cause ? `<div class="ce-remede-court">${codeErreurBadge(e.CodeCause)} ${escHtml(cause.titre)}</div>` : ''}</td></tr>`;
    }).join('')}</tbody></table></div>`;
}

async function _cxFichiersCsv(id) {
  const z = document.getElementById('cxFichiers');
  if (!z) return;
  showLoading(z);
  let f;
  try { f = await apiRequest('connect_fichiers&passerelle_id=' + encodeURIComponent(id)) || []; } catch (err) { z.innerHTML = `<div class="no-results">${escHtml(err.message)}</div>`; return; }
  const st = { importe: ['Importé', 'teal'], incomplet: ['Incomplet', 'orange'], doublon: ['Doublon', 'gray'], rejete: ['Rejeté', 'red'] };
  z.innerHTML = f.length ? `<div class="table-wrap"><table><thead><tr><th>Fichier</th><th>Daté du</th><th>Traité le</th><th>Statut</th><th>Valeurs</th><th>Détail</th></tr></thead><tbody>
    ${f.map(x => { const s = st[x.Statut] || [x.Statut, 'gray']; return `<tr>
      <td><code class="cx-code">${escHtml(x.NomFichier)}</code><div class="cx-aide">${x.Taille ? _cxNombre(x.Taille / 1024, 1) + _cxT(` Ko`, ` KB`) : ''}</div></td>
      <td style="white-space:nowrap">${_cxDate(x.DateFichier)}</td>
      <td style="white-space:nowrap">${_cxDate(x.DateTraitement)}</td>
      <td><span class="badge badge-${s[1]}">${s[0]}</span></td>
      <td>${x.NbValeurs ?? '—'}</td>
      <td class="cx-aide">${escHtml(x.Message || '')}</td></tr>`; }).join('')}
    </tbody></table></div>` : `<div class="no-results">Aucun fichier traité pour l'instant.</div>`;
}

// ══ Bâtiments et réseaux ═══════════════════════════════════════════════════
//
// Chaque bâtiment déclare ses réseaux IP (et des mots-clés) : la détection
// y range d'office ce qu'elle trouve, et les connexions sans bâtiment se
// rangent en un clic. Sans rien déclarer, Larka apprend d'après les connexions
// déjà rangées (api/connect/Batiments.php).

function _cxPlagesHtml(b, avecGarder) {
  const plages = b.Plages || [];
  if (!plages.length) return `<span class="cx-aide">${_cxT(`aucun réseau`, `no network`)}</span>`;
  return plages.map(p => p.origine === 'appris'
    ? `<span class="cx-reseau cx-reseau-appris" title="${escHtml(_cxT(`Appris d'après les connexions déjà rangées`, `Learned from the connections already filed`))}">${escHtml(p.texte)} <small>${_cxT(`appris`, `learned`)}</small>${avecGarder
        ? ` <a href="#" onclick="event.preventDefault();_cxGarderAppris(${b.Id})">${_cxT(`garder`, `keep`)}</a>` : ''}</span>`
    : `<span class="cx-reseau">${escHtml(p.texte)}</span>`).join(' ');
}

async function _cxOngletBatiments(z) {
  const r = await apiRequest('connect_batiments') || {};
  const bats = CxUI.batiments = r.batiments || [];
  const rangeables = CxUI.rangeables = r.rangeables || [];
  let h = '';
  if (!bats.length) {
    z.innerHTML = `<div class="card cx-ferme"><div class="cx-ferme-icone">${lk('building-complex')}</div><h3>${_cxT(`Aucun bâtiment pour l'instant`, `No building yet`)}</h3>
      <p class="cx-aide">${_cxT(`Les bâtiments sont ceux des plans. Créez-les d'abord (un nom suffit), puis revenez indiquer leurs réseaux.`,
                                `Buildings are those of the floor plans. Create them first (a name is enough), then come back to give their networks.`)}</p>
      <button class="btn btn-primary" onclick="navigate('plans')">${lk('map')} ${_cxT(`Créer les bâtiments dans Plans`, `Create buildings in Plans`)}</button></div>`;
    return;
  }
  if (rangeables.length) {
    h += `<div class="cx-bandeau cx-bandeau-info">${lk('compass')} ${_cxT(`<strong>${rangeables.length} connexion(s) sans bâtiment</strong> peuvent être rangées d'après leur adresse ou leur nom.`,
                                                          `<strong>${_cxN(rangeables.length, `connection`, `connections`)} without a building</strong> can be filed from their address or name.`)}
      <button class="btn btn-ghost btn-sm" style="margin-left:8px" onclick="_cxRanger()">${_cxT(`Voir et ranger`, `Review and file`)}</button></div>`;
  } else if (r.sans_batiment) {
    h += `<div class="cx-bandeau">${_cxT(`${r.sans_batiment} connexion(s) sans bâtiment, que Larka ne sait pas ranger : indiquez le réseau ou un mot-clé du bon bâtiment, ou choisissez-le dans la connexion (${lk('pencil')}).`,
                                         `${_cxN(r.sans_batiment, `connection`, `connections`)} without a building that Larka cannot file: give the right building's network or a keyword, or choose it in the connection (${lk('pencil')}).`)}</div>`;
  }
  const avecReseau = bats.filter(b => (b.Plages || []).length).length;
  h += `<div class="card"${h ? ' style="margin-top:12px"' : ''}><div class="card-header">
      <div class="card-title">${_cxT(`${avecReseau} bâtiment(s) sur ${bats.length} avec un réseau`, `${avecReseau} of ${_cxN(bats.length, `building`, `buildings`)} with a network`)}</div>
      <button class="btn btn-primary btn-sm" onclick="_cxDetection('tous')" ${avecReseau ? '' : 'disabled'}${_cxInfo('action.bat.tous')}>${lk('satellite')} ${_cxT(`Détecter dans tous les bâtiments`, `Detect in all buildings`)}</button>
    </div>
    <div class="table-wrap"><table><thead><tr>
      <th>${_cxT(`Bâtiment`, `Building`)}</th><th>${_cxT(`Réseaux`, `Networks`)} ${_cxBulle('bat.reseaux')}</th><th>${_cxT(`Mots-clés`, `Keywords`)} ${_cxBulle('bat.motscles')}</th>
      <th>${_cxT(`Équipements`, `Devices`)}</th><th>${_cxT(`Actions`, `Actions`)}</th></tr></thead><tbody>
    ${bats.map(b => {
      const e = b.Etats || {};
      return `<tr>
        <td><div class="cx-cellule-nom"><span class="cx-pastille" style="background:${escHtml(b.Couleur || '#3b82f6')}" aria-hidden="true"></span><div><strong>${escHtml(b.Nom)}</strong></div></div></td>
        <td>${_cxPlagesHtml(b, true)}${!(b.Plages || []).length ? ` <a href="#" onclick="event.preventDefault();_cxFormBatiment(${b.Id})">${_cxT(`indiquer`, `set`)}</a>` : ''}</td>
        <td>${b.MotsCles ? escHtml(b.MotsCles) : '<span class="cx-aide">—</span>'}</td>
        <td>${b.NbConnexions ? `<a href="#" onclick="event.preventDefault();CxUI.bat=${jsArg(b.Nom)};navigate('connect')">${_cxN(b.NbConnexions, `connexion`, `connexions`)}</a>
            <div class="cx-aide">${e.ok ? '✓ ' + e.ok : ''}${e.erreur ? ` <span class="cx-erreur-texte">✕ ${e.erreur}</span>` : ''}${e.alarmes ? ` · ${lk('bell')} ${e.alarmes}` : ''}</div>`
          : `<span class="cx-aide">${_cxT(`aucun`, `none`)}</span>`}</td>
        <td><div class="actions-col cx-actions">
          <button class="icon-btn" aria-label="${escHtml(_cxT(`Réseaux et mots-clés`, `Networks and keywords`))}" onclick="_cxFormBatiment(${b.Id})"${_cxInfo('action.bat.modifier')}>${lk('pencil')}</button>
          <button class="icon-btn" aria-label="${escHtml(_cxT(`Détecter dans ce bâtiment`, `Detect in this building`))}" onclick="_cxDetection(${b.Id})"${_cxInfo('action.bat.detecter')}>${lk('satellite')}</button>
        </div></td></tr>`;
    }).join('')}
    </tbody></table></div></div>`;
  z.innerHTML = h;
}

function _cxFormBatiment(id) {
  const b = (CxUI.batiments || []).find(x => x.Id == id);
  if (!b) return;
  const appris = (b.Plages || []).filter(p => p.origine === 'appris').map(p => p.texte);
  const corps = `<div class="cx-form">
    <div class="form-group"><label class="form-label" for="cxbReseaux">${_cxT(`Réseaux (adresses IP des équipements)`, `Networks (IP addresses of the devices)`)} ${_cxBulle('bat.reseaux')}</label>
      <textarea id="cxbReseaux" class="form-control" rows="2" spellcheck="false" placeholder="192.168.21.0/24, 10.4.0.10-60">${escHtml(b.Reseaux || '')}</textarea>
      <div class="cx-aide">${_cxT(`Une plage (192.168.21.0/24 = les 254 adresses 192.168.21.x), un intervalle (192.168.21.10-60) ou une adresse, séparés par des virgules.`,
                                  `A range (192.168.21.0/24 = the 254 addresses 192.168.21.x), an interval (192.168.21.10-60) or an address, separated by commas.`)}
        ${appris.length ? `<br>${_cxT(`Appris d'après les connexions :`, `Learned from the connections:`)} <a href="#" onclick="event.preventDefault();_cxAjouterReseaux(${jsArg(appris.join(', '))})">${escHtml(appris.join(', '))}</a> ${_cxT(`(cliquer pour reprendre)`, `(click to use)`)}` : ''}</div></div>
    <div class="form-group"><label class="form-label" for="cxbMots">${_cxT(`Mots-clés`, `Keywords`)} ${_cxBulle('bat.motscles')}</label>
      <input id="cxbMots" class="form-control" maxlength="400" value="${escHtml(b.MotsCles || '')}" placeholder="${escHtml(_cxT(`ex. HDV, mairie`, `e.g. TH, town hall`))}">
      <div class="cx-aide">${_cxT(`Séparés par des virgules. Un équipement dont le nom contient l'un d'eux (« Borne HDV-VE-01 », « TGBT mairie ») ira dans ce bâtiment si son adresse ne suffit pas.`,
                                  `Separated by commas. A device whose name contains one of them (“Charger TH-EV-01”, “Town hall main board”) goes to this building when its address is not enough.`)}</div></div>
    <div class="cx-apercu">${_cxT(`Larka range un équipement d'abord d'après les réseaux déclarés, puis d'après ceux qu'il a appris, puis d'après son nom. Dans le doute, il ne range rien.`,
                                  `Larka files a device first by the declared networks, then by the learned ones, then by its name. When in doubt, it files nothing.`)}</div>
  </div>`;
  openModal(_cxT(`Réseaux — `, `Networks — `) + b.Nom, corps, () => _cxEnregistrerBatiment(id), 'Enregistrer', 'Annuler', '640px');
}

function _cxAjouterReseaux(texte) {
  const el = document.getElementById('cxbReseaux');
  if (!el) return;
  const deja = el.value.split(/[\s,;]+/).filter(Boolean);
  texte.split(/[\s,;]+/).filter(Boolean).forEach(t => { if (!deja.includes(t)) deja.push(t); });
  el.value = deja.join(', ');
}

async function _cxEnregistrerBatiment(id, reseaux, motsCles) {
  const o = { id, reseaux: reseaux ?? gv('cxbReseaux'), motsCles: motsCles ?? gv('cxbMots') };
  let r;
  try { r = await _cxApi('connect_batiment', 'POST', o); } catch (err) { toast(err.message, 'error'); return; }
  if (reseaux === undefined) closeModal();
  toast(_cxT(`Réseaux enregistrés.`, `Networks saved.`), 'success');
  (r.avertissements || []).forEach(a => toast(a, 'warning'));
  CxUI.parcoursLu = 0;
  _cxRafraichir();
}

function _cxGarderAppris(id) {
  const b = (CxUI.batiments || []).find(x => x.Id == id);
  if (!b) return;
  const tout = (b.Plages || []).map(p => p.texte);
  _cxEnregistrerBatiment(id, tout.join(', '), b.MotsCles || '');
}

function _cxRanger() {
  const liste = CxUI.rangeables || [];
  if (!liste.length) return;
  const par = { reseau: _cxT(`réseau du bâtiment`, `building network`), appris: _cxT(`réseau appris`, `learned network`), nom: _cxT(`d'après le nom`, `from the name`) };
  openModal(_cxT(`Ranger les connexions sans bâtiment`, `File the connections without a building`), `
    <p class="cx-aide" style="margin-top:0">${_cxT(`Larka propose un bâtiment pour chacune. Décochez celles qui ne vont pas où il le dit : vous les rangerez à la main (${lk('pencil')}).`,
                                                   `Larka suggests a building for each. Untick those that do not go where it says: you will file them by hand (${lk('pencil')}).`)}</p>
    <div class="table-wrap"><table><thead><tr><th></th><th>${_cxT(`Connexion`, `Connection`)}</th><th>${_cxT(`Bâtiment proposé`, `Suggested building`)}</th><th>${_cxT(`Pourquoi`, `Why`)}</th></tr></thead><tbody>
    ${liste.map(x => `<tr><td><input type="checkbox" class="cx-ranger-case" value="${x.id}" checked aria-label="${escHtml(x.nom)}"></td>
      <td>${escHtml(x.nom)}</td><td><strong>${escHtml(x.batiment)}</strong></td><td class="cx-aide">${escHtml(par[x.par] || x.par)}</td></tr>`).join('')}
    </tbody></table></div>`, async () => {
      const ids = [...document.querySelectorAll('.cx-ranger-case:checked')].map(c => +c.value);
      if (!ids.length) { closeModal(); return; }
      let r;
      try { r = await _cxApi('connect_batiments_ranger', 'POST', { ids }); } catch (err) { toast(err.message, 'error'); return; }
      closeModal();
      toast(_cxT(`${r.rangees} connexion(s) rangée(s).`, `${_cxN(r.rangees, `connection`, `connections`)} filed.`), 'success');
      CxUI.parcoursLu = 0;
      _cxRafraichir();
    }, _cxT(`Ranger la sélection`, `File the selection`), 'Annuler', '720px');
}

// ── Supervision : une carte par bâtiment ────────────────────────────────────

function _cxSyntheseBatiments() {
  const z = document.getElementById('cxSynthese');
  if (!z || !CxUI.sup) return;
  const pas = CxUI.sup.passerelles || [];
  const bats = CxUI.sup.batiments || [];
  const charges = CxUI.sup.charges || {};
  const alarmesPar = {};
  (CxUI.sup.alarmes || []).forEach(a => { alarmesPar[a.PasserelleId] = (alarmesPar[a.PasserelleId] || 0) + 1; });
  const groupes = new Map();
  const groupe = (cle, nom, couleur, plages) => {
    if (!groupes.has(cle)) groupes.set(cle, { cle, nom, couleur, plages: plages || [], pas: [] });
    return groupes.get(cle);
  };
  bats.forEach(b => { if ((b.Plages || []).length) groupe(b.Nom, b.Nom, b.Couleur, b.Plages); });
  pas.forEach(p => {
    const b = bats.find(x => x.Nom === p.BatimentNom);
    groupe(p.BatimentNom || CX_SANS_BAT, p.BatimentNom || _cxT(`Sans bâtiment`, `No building`), b ? b.Couleur : '', b ? b.Plages : []).pas.push(p);
  });
  if (!groupes.size) { z.innerHTML = ''; return; }
  const gestion = canEdit();
  const cartes = [...groupes.values()].sort((a, b) => (a.cle === CX_SANS_BAT) - (b.cle === CX_SANS_BAT) || a.nom.localeCompare(b.nom, 'fr')).map(g => {
    const actives = g.pas.filter(p => +p.Actif);
    const ko = actives.filter(p => p.Etat === 'erreur').length;
    const partiel = actives.filter(p => p.Etat === 'partiel').length;
    const ok = actives.filter(p => p.Etat === 'ok').length;
    const attente = actives.length - ko - partiel - ok;                 // jamais interrogées, ou rien reçu encore
    const alarmes = g.pas.reduce((n, p) => n + (alarmesPar[p.Id] || 0), 0);
    const bornes = g.pas.filter(p => p.Protocole === 'ocpp');
    const enCharge = bornes.filter(p => charges[p.Id] > 0).length;      // bornes en charge (une borne à deux connecteurs compte une fois)
    const derniers = g.pas.map(p => p.DerniereCollecte || p.DernierContact).filter(Boolean).sort();
    const dernier = derniers.length ? derniers[derniers.length - 1] : null;
    const etat = !g.pas.length ? 'vide' : (ko || alarmes ? 'ko' : (partiel ? 'moyen' : (attente && !ok ? 'attente' : 'ok')));
    const filtre = g.cle === CX_SANS_BAT ? '__sans' : g.nom;
    const choisi = CxUI.bat === filtre;
    const libEtat = { ok: _cxT(`Tout va bien`, `All good`), moyen: _cxT(`À surveiller`, `To watch`), ko: _cxT(`À traiter`, `Needs action`),
                      attente: _cxT(`En attente`, `Waiting`), vide: _cxT(`Rien de raccordé`, `Nothing connected`) }[etat];
    return `<div class="cx-bat cx-bat-${etat}${choisi ? ' cx-bat-choisi' : ''}" role="button" tabindex="0" aria-pressed="${choisi}"
        onclick="_cxFiltrerBatiment(${jsArg(filtre)})" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();_cxFiltrerBatiment(${jsArg(filtre)})}">
      <div class="cx-bat-tete"><span class="cx-pastille" style="background:${escHtml(g.couleur || 'var(--gray-border)')}" aria-hidden="true"></span>
        <span class="cx-bat-nom" title="${escHtml(g.nom)}">${escHtml(g.nom)}</span></div>
      ${g.pas.length ? `<div class="cx-bat-chiffres"><span class="cx-bat-etat">${libEtat}</span>
          ${ok ? `<span title="${escHtml(_cxT(`connexion(s) en service`, `connection(s) in service`))}">✓ ${ok}</span>` : ''}
          ${ko ? `<span class="cx-bat-ko" title="${escHtml(_cxT(`en défaut : l'équipement ne répond plus`, `faulty: the device no longer answers`))}">✕ ${ko}</span>` : ''}
          ${partiel ? `<span class="cx-bat-moyen" title="${escHtml(_cxT(`partielle : certains points ne se lisent pas`, `partial: some points cannot be read`))}">! ${partiel}</span>` : ''}
          ${attente ? `<span class="cx-bat-gris" title="${escHtml(_cxT(`en attente de données : pas encore interrogée(s)`, `waiting for data: not polled yet`))}">${lk('hourglass')} ${attente}</span>` : ''}
          ${alarmes ? `<span class="cx-bat-ko" title="${escHtml(_cxT(`alarme(s) active(s)`, `active alarm(s)`))}">${lk('bell')} ${alarmes}</span>` : ''}
          ${bornes.length ? `<span title="${escHtml(_cxT(`bornes en charge / bornes`, `chargers charging / chargers`))}">${lk('plug')} ${enCharge}/${bornes.length}</span>` : ''}
        </div>
        <div class="cx-aide">${dernier ? _cxT(`Dernier échange `, `Last exchange `) + _cxDepuis(dernier) : _cxT(`Aucun échange pour l'instant`, `No exchange yet`)}</div>`
      : `<div class="cx-bat-chiffres"><span class="cx-bat-etat">${libEtat}</span></div>
         <div class="cx-aide">${g.plages.length ? escHtml(g.plages.join(', ')) : ''}</div>
         ${gestion && g.cle !== CX_SANS_BAT ? `<button class="btn btn-ghost btn-sm cx-bat-detecter" onclick="event.stopPropagation();_cxDetection(${(bats.find(b => b.Nom === g.nom) || {}).Id || 'null'})">${lk('satellite')} ${_cxT(`Détecter`, `Detect`)}</button>` : ''}`}
      ${g.cle === CX_SANS_BAT && gestion ? `<a href="#" class="cx-aide" onclick="event.preventDefault();event.stopPropagation();CxUI.onglet='batiments';navigate('connect_config')">${_cxT(`Ranger ces connexions`, `File these connections`)}</a>` : ''}
    </div>`;
  }).join('');
  z.innerHTML = `<div class="cx-bats" aria-label="${escHtml(_cxT(`Bâtiments`, `Buildings`))}">${cartes}</div>
    ${CxUI.bat ? `<div class="cx-aide">${_cxT(`Filtré sur un bâtiment.`, `Filtered on one building.`)} <a href="#" onclick="event.preventDefault();_cxFiltrerBatiment('')">${_cxT(`Tout afficher`, `Show all`)}</a></div>` : ''}`;
}

function _cxFiltrerBatiment(nom) {
  CxUI.bat = CxUI.bat === nom ? '' : nom;
  const sel = document.getElementById('cxBat');
  if (sel) sel.value = CxUI.bat;
  _cxPeindreSupervision();
}

// ══ Explications : à quoi sert chaque écran, bulles « ? », parcours guidé ══

const CxAide = {
  ecran: '',               // écran (ou onglet) affiché : page « Cet écran » du mode d'emploi
  replies: null,
  charger() {
    if (this.replies) return;
    try { this.replies = new Set(JSON.parse(localStorage.getItem('cx_intros_replies') || '[]')); } catch (_) { this.replies = new Set(); }
  },
  garder() { try { localStorage.setItem('cx_intros_replies', JSON.stringify([...this.replies])); } catch (_) { /* navigation privée */ } },
};

/** Ce que fait chaque écran, quand s'en servir, un exemple, et sa fiche d'aide. */
function _cxIntros() {
  return {
    supervision: { icone: lk('satellite-dish'), fiche: 'connect-supervision',
      titre: _cxT(`Supervision : vos bâtiments, en direct`, `Supervision: your buildings, live`),
      quoi: _cxT(`La dernière valeur de chaque point suivi (température, index de compteur, état d'une pompe, charge d'une borne). En haut, une carte par bâtiment résume ce qui va et ce qui ne va pas.`,
                 `The latest value of each monitored point (temperature, meter index, pump state, charger session). At the top, one card per building sums up what works and what does not.`),
      quand: _cxT(`Pour vérifier d'un coup d'œil qu'un bâtiment fonctionne, retrouver une valeur ou voir son historique : cliquez sur une valeur pour sa courbe.`,
                  `To check at a glance that a building works, find a value or see its history: click a value for its chart.`),
      exemple: _cxT(`La carte « Groupe scolaire » est rouge : cliquez dessus pour n'afficher que ce bâtiment, repérez l'équipement en défaut, puis ouvrez la courbe de la valeur suspecte.`,
                    `The “School” card is red: click it to show only that building, spot the faulty device, then open the chart of the suspicious value.`) },
    alarmes: { icone: lk('bell'), fiche: 'connect-alarmes',
      titre: _cxT(`Alarmes techniques : ce qui demande une action`, `Technical alarms: what needs action`),
      quoi: _cxT(`Les alarmes ouvertes par vos règles (seuil dépassé, défaut, capteur muet) et par les équipements eux-mêmes (défaut de borne, alarme d'un superviseur).`,
                 `Alarms opened by your rules (threshold exceeded, fault, silent sensor) and by the devices themselves (charger fault, supervisor alarm).`),
      quand: _cxT(`Chaque jour : acquitter une alarme signale à l'équipe qu'elle est prise en charge. Elle se termine seule quand la condition cesse.`,
                  `Every day: acknowledging an alarm tells the team it is being handled. It ends by itself when the condition stops.`),
      exemple: _cxT(`« Chambre froide à 8,6 °C » : acquittez-la avec un commentaire (« technicien prévenu ») ; la demande d'intervention créée par la règle s'ouvre d'un clic.`,
                    `“Cold room at 8.6 °C”: acknowledge it with a comment (“technician called”); the work request created by the rule opens with one click.`) },
    batiments: { icone: lk('building-complex'), fiche: 'connect-batiments',
      titre: _cxT(`Bâtiments : où sont vos équipements`, `Buildings: where your devices are`),
      quoi: _cxT(`Pour chaque bâtiment, les adresses IP de ses équipements techniques (son réseau) et des mots-clés. Larka s'en sert pour ranger tout seul ce qu'il détecte, et pour détecter bâtiment par bâtiment.`,
                 `For each building, the IP addresses of its technical devices (its network) and keywords. Larka uses them to file what it detects by itself, and to detect building by building.`),
      quand: _cxT(`Une fois, au début : votre service informatique connaît ces réseaux. Larka les apprend aussi d'après les connexions déjà rangées (« appris »).`,
                  `Once, at the start: your IT department knows these networks. Larka also learns them from the connections already filed (“learned”).`),
      exemple: _cxT(`Hôtel de Ville : 192.168.21.0/24, mots-clés « HDV, mairie ». Un automate trouvé en 192.168.21.12, ou une borne nommée HDV-VE-01, va d'office à l'Hôtel de Ville.`,
                    `Town hall: 192.168.21.0/24, keywords “TH, town hall”. A controller found at 192.168.21.12, or a charger named TH-EV-01, goes straight to the town hall.`) },
    connexions: { icone: lk('plug'), fiche: 'connect-raccorder',
      titre: _cxT(`Connexions : les équipements avec lesquels Larka dialogue`, `Connections: the devices Larka talks to`),
      quoi: _cxT(`Une connexion, c'est un équipement : automate de GTB, compteur, broker de capteurs, superviseur, dossier d'exports, borne de recharge. Le serveur l'interroge à son rythme, même quand personne n'a Larka ouvert.`,
                 `A connection is a device: BMS controller, meter, sensor broker, supervisor, export folder, EV charger. The server polls it at its own pace, even when nobody has Larka open.`),
      quand: _cxT(`Pour raccorder un équipement (Détecter le fait pour vous), corriger une adresse ou un mot de passe, ou comprendre pourquoi un équipement ne répond plus.`,
                  `To connect a device (Detect does it for you), fix an address or password, or understand why a device no longer answers.`),
      exemple: _cxT(`Une connexion est « en défaut » : Tester dit ce qui bloque (pare-feu, mot de passe, adresse) et rédige au besoin le message pour le service informatique.`,
                    `A connection is “faulty”: Test tells what blocks (firewall, password, address) and drafts the message for IT if needed.`) },
    points: { icone: lk('map-pin'), fiche: 'connect-points',
      titre: _cxT(`Points : les valeurs que vous suivez`, `Points: the values you monitor`),
      quoi: _cxT(`Un point, c'est une valeur précise lue sur une connexion : température de départ, index du compteur général, état d'un brûleur. Ce sont les points qui s'affichent, s'historisent et déclenchent les alarmes.`,
                 `A point is one value read on a connection: flow temperature, main meter index, burner state. Points are what is displayed, recorded and triggers alarms.`),
      quand: _cxT(`Après avoir raccordé un équipement : gardez ce qui vous sert. Découvrir propose la liste quand l'équipement sait la donner ; pour Modbus, Explorer aide à trouver les registres.`,
                  `After connecting a device: keep what is useful. Discover suggests the list when the device can give it; for Modbus, Explore helps find the registers.`),
      exemple: _cxT(`Sur une chaufferie : « Température départ », « Défaut brûleur » et « Pression réseau ». Inutile de suivre les deux cents variables internes de l'automate.`,
                    `On a boiler room: “Flow temperature”, “Burner fault” and “Network pressure”. No need to follow the controller's two hundred internal variables.`) },
    regles: { icone: lk('bell'), fiche: 'connect-regles',
      titre: _cxT(`Règles d'alarme : être prévenu plutôt que surveiller`, `Alarm rules: be warned instead of watching`),
      quoi: _cxT(`Une règle surveille un point et ouvre une alarme quand sa condition est remplie : seuil dépassé, état anormal, valeur qui n'arrive plus, palier d'heures de marche franchi.`,
                 `A rule watches a point and opens an alarm when its condition is met: threshold exceeded, abnormal state, value no longer arriving, running-hours step crossed.`),
      quand: _cxT(`Pour chaque valeur qui compte : la règle prévient les gestionnaires et peut créer d'elle-même la demande d'intervention. Elles se créent depuis l'onglet Points (colonne).`,
                  `For every value that matters: the rule warns the managers and can create the work request by itself. They are created from the Points tab (column).`),
      exemple: _cxT(`« Chambre froide au-dessus de 6 °C pendant 15 minutes → alarme critique et demande d'intervention » : une porte ouverte un instant ne déclenche rien, une panne si.`,
                    `“Cold room above 6 °C for 15 minutes → critical alarm and work request”: a door opened briefly triggers nothing, a failure does.`) },
    releves: { icone: lk('calendar'), fiche: 'connect-releves',
      titre: _cxT(`Relevés automatiques : vos compteurs relevés tout seuls`, `Automatic readings: your meters read by themselves`),
      quoi: _cxT(`Un relevé automatique recopie, à heure fixe, la valeur d'un compteur raccordé dans un compteur du module Énergie, comme une saisie à la main, sans la saisie.`,
                 `An automatic reading copies, at a set time, a connected meter's value into a meter of the Energy module, like a manual entry, without typing.`),
      quand: _cxT(`Pour alimenter le suivi des consommations (et le bilan carbone) sans tournée de relevés. Une valeur suspecte est mise de côté pour validation.`,
                  `To feed consumption monitoring (and the carbon report) without meter rounds. A suspicious value is set aside for approval.`),
      exemple: _cxT(`Chaque jour à 23 h 55, l'index du TGBT de l'Hôtel de Ville va dans le compteur « Électricité Hôtel de Ville ». Un index qui recule n'est jamais écrit sans votre accord.`,
                    `Every day at 23:55, the town hall main board index goes into the “Town hall electricity” meter. An index that goes backwards is never written without your approval.`) },
    bornes: { icone: lk('car'), fiche: 'connect-bornes',
      titre: _cxT(`Bornes de recharge : état, charges et commandes`, `EV chargers: status, sessions and commands`),
      quoi: _cxT(`Les bornes OCPP : leur état, leurs sessions de charge (énergie, durée, badge) et les commandes à distance (arrêter une charge, redémarrer, rendre indisponible).`,
                 `OCPP chargers: their status, charging sessions (energy, duration, badge) and remote commands (stop a session, restart, make unavailable).`),
      quand: _cxT(`Pour suivre l'usage des bornes, débloquer une borne à distance, ou déclarer une nouvelle borne. Ce sont les bornes qui appellent Larka, pas l'inverse.`,
                  `To follow charger use, unlock a charger remotely, or declare a new one. Chargers call Larka, not the other way round.`),
      exemple: _cxT(`Une borne est « refusée » : elle a appelé Larka sans être déclarée. Détecter la propose ; une fois déclarée, elle se reconnecte seule.`,
                    `A charger is “refused”: it called Larka without being declared. Detect suggests it; once declared, it reconnects by itself.`) },
    hotspots: { icone: lk('wifi'), fiche: 'connect-hotspot',
      titre: _cxT(`Hors réseau : les appareils que le serveur ne joint pas`, `Off the network: devices the server cannot reach`),
      quoi: _cxT(`Certains appareils (enregistreur, station météo, centrale de mesure, compteur autonome) ne sont pas sur le réseau : ils diffusent leur propre Wi-Fi, ou on s'y branche par câble. Un PC « releveur » les relève quand on lance son collecteur (un double-clic), garde les valeurs et les envoie à Larka quand il retrouve le réseau.`,
                 `Some devices (logger, weather station, power meter, standalone meter) are not on the network: they broadcast their own Wi-Fi, or you plug into them by cable. A “reader” PC reads them when you start its collector (a double-click), keeps the values and sends them to Larka when it is back on the network.`),
      quand: _cxT(`Une seule fois : déclarer l'appareil, ajouter le PC, décompresser son collecteur. Ensuite, pour relever : double-cliquer releveur.cmd — rien ne se lance tout seul. Les relevés arrivent avec leur heure réelle, même envoyés plus tard.`,
                  `Once: declare the device, add the PC, unzip its collector. Then, to read: double-click releveur.cmd — nothing starts by itself. Readings arrive with their real time, even when sent later.`),
      exemple: _cxT(`L'enregistreur du local technique diffuse « LOGGER-CTA ». Lors de sa ronde, l'agent double-clique releveur.cmd sur son portable : le PC le relève puis revient sur son Wi-Fi ; sans Internet à ce moment, il garde les valeurs et les envoie au relevé suivant.`,
                    `The plant room logger broadcasts “LOGGER-CTA”. On their round, the technician double-clicks releveur.cmd on the laptop: the PC reads it, then goes back to its Wi-Fi; without Internet at that moment, it keeps the values and sends them at the next reading.`) },
    journal: { icone: lk('receipt'), fiche: 'connect-journal',
      titre: _cxT(`Journal : ce qui a été envoyé aux équipements`, `Log: what was sent to the devices`),
      quoi: _cxT(`Chaque écriture de consigne et chaque commande de borne (qui, quand, quelle valeur, résultat), et les fichiers CSV lus.`,
                 `Every setpoint write and charger command (who, when, which value, result), and the CSV files read.`),
      quand: _cxT(`Pour répondre à « qui a changé cette consigne ? », ou vérifier qu'un export CSV a bien été lu.`,
                  `To answer “who changed this setpoint?”, or check that a CSV export was read.`),
      exemple: _cxT(`La consigne de soufflage a changé : le journal indique le compte, l'heure, la valeur envoyée et la réponse de l'automate.`,
                    `The supply setpoint changed: the log shows the account, the time, the value sent and the controller's answer.`) },
  };
}

/*
 * MODE D'EMPLOI DE LA SUPERVISION : une première page, puis un « ? ».
 *
 * Les explications occupaient le haut de chaque écran (Supervision, Alarmes,
 * chaque onglet des Connexions) : trois colonnes de texte avant la première
 * valeur. Elles sont maintenant regroupées dans un MODE D'EMPLOI :
 *   - montré une fois, en première page, à la première visite d'un écran de
 *     supervision (par compte, sur ce poste) ;
 *   - « Passer » le ferme et ne le remontre plus ;
 *   - le bouton « ? » de la barre du haut le rouvre : fonctionnement général,
 *     puis ce que fait l'écran affiché (ou l'onglet ouvert).
 * _cxIntro(cle) ne dessine donc plus rien : il note l'écran affiché, pour que
 * le « ? » s'ouvre sur la bonne page.
 */
function _cxIntro(cle) {
  CxAide.ecran = cle;
  return '';
}

/** Le bouton « ? » de la barre du haut. */
function _cxBoutonAide() {
  return `<button type="button" class="btn btn-ghost btn-sm cx-aide-btn" onclick="_cxGuide()" aria-label="${escHtml(_cxT(`Mode d'emploi de la supervision`, `How supervision works`))}"
    title="${escHtml(_cxT(`Comment ça marche ? (mode d'emploi)`, `How does it work? (guide)`))}">?</button>`;
}

function _cxGuideCle() {
  const login = (typeof App !== 'undefined' && App.currentUser && App.currentUser.Login) || '';
  return 'cx_guide_vu_' + login;
}
function _cxGuideVu() {
  try { return localStorage.getItem(_cxGuideCle()) === '1'; } catch (_) { return true; }   // sans stockage : jamais imposé
}
function _cxGuideMarquer() {
  try { localStorage.setItem(_cxGuideCle(), '1'); } catch (_) { /* navigation privée */ }
}

/** À la première visite d'un écran de supervision : le mode d'emploi, en première page. */
function _cxGuideAuto() {
  if (_cxGuideVu()) return;
  if (document.getElementById('modalOverlay')?.classList.contains('open')) return;   // une fiche est déjà ouverte
  _cxGuide(0, true);
}

/** Les étapes du fonctionnement général : ce qui se règle, et où. */
function _cxGuideEtapes() {
  const T = (fr, en) => _cxT(fr, en);
  return [
    [lk('plug'), T(`Raccorder`, `Connect`), T(`Connexions → Détecter les équipements (ou Nouvelle connexion) : automates de GTB, compteurs, capteurs, superviseurs, bornes. Tester dit ce qui bloque.`,
      `Connections → Detect devices (or New connection): BMS controllers, meters, sensors, supervisors, chargers. Test tells what blocks.`)],
    [lk('map-pin'), T(`Choisir les points`, `Pick the points`), T(`Sur chaque connexion, gardez les valeurs utiles (température départ, index général, défaut brûleur) : ce sont elles qui s'affichent et s'historisent.`,
      `On each connection, keep the useful values (flow temperature, main index, burner fault): they are what is displayed and recorded.`)],
    [lk('bell'), T(`Être prévenu`, `Be warned`), T(`Une règle sur un point ouvre une alarme (seuil, état, valeur muette) et peut créer la demande d'intervention. Les alarmes s'acquittent dans Alarmes techniques.`,
      `A rule on a point opens an alarm (threshold, state, silent value) and can create the work request. Alarms are acknowledged in Technical alarms.`)],
    [lk('calendar'), T(`Relever tout seul`, `Read automatically`), T(`Un relevé automatique recopie l'index d'un compteur raccordé dans le module Énergie, à heure fixe. Une valeur douteuse attend votre validation.`,
      `An automatic reading copies a connected meter's index into the Energy module at a set time. A doubtful value waits for your approval.`)],
    [lk('satellite-dish'), T(`Suivre`, `Monitor`), T(`Supervision : une carte par bâtiment, la dernière valeur de chaque point, sa courbe d'un clic, les consommations du mois.`,
      `Supervision: one card per building, the latest value of each point, its chart in one click, the month's consumption.`)],
  ];
}

/**
 * Le mode d'emploi. page 0 : fonctionnement général ; page 1 : l'écran affiché.
 * premiereFois : montré tout seul (bouton « Passer »).
 */
function _cxGuide(page = 0, premiereFois = false) {
  const T = (fr, en) => _cxT(fr, en);
  const d = _cxIntros()[CxAide.ecran] || null;
  const nb = d ? 2 : 1;
  page = Math.max(0, Math.min(nb - 1, page));
  // Ouvert à la main ou montré d'office : vu, dans les deux cas — il ne s'imposera plus.
  _cxGuideMarquer();
  let corps;
  if (page === 0) {
    corps = `<p style="margin-top:0">${escHtml(T(`La supervision relie Larka aux équipements techniques de vos bâtiments. Tout se règle en cinq temps :`,
                                         `Supervision links Larka to your buildings' technical devices. Everything is set up in five steps:`))}</p>
      <ol class="cx-guide-etapes">${_cxGuideEtapes().map(([ic, t, x]) => `<li><span class="cx-guide-ic" aria-hidden="true">${ic}</span><div><b>${escHtml(t)}</b><p>${escHtml(x)}</p></div></li>`).join('')}</ol>
      <div class="cx-guide-notes">
        <p>${lk('server')} ${escHtml(T(`Les équipements sont interrogés par le serveur (ou par votre serveur de supervision), à leur rythme, jamais par votre navigateur : ouvrir un écran ne sollicite aucun automate.`,
                                     `Devices are polled by the server (or by your supervision server), at their own pace, never by your browser: opening a screen does not load any controller.`))}</p>
        <p>${lk('lock')} ${escHtml(T(`Rien n'est écrit sur un équipement sans confirmation de votre identité ; chaque écriture est tracée (Connexions → Journal).`,
                                   `Nothing is written to a device without confirming your identity; every write is logged (Connections → Log).`))}</p>
      </div>`;
  } else {
    corps = `<div class="cx-intro-tete" style="margin-bottom:8px"><span class="cx-intro-icone" aria-hidden="true">${d.icone}</span><h3 style="margin:0">${escHtml(d.titre)}</h3></div>
      <div class="cx-guide-ecran">
        <div><b>${T(`À quoi ça sert`, `What it is for`)}</b><p>${escHtml(d.quoi)}</p></div>
        <div><b>${T(`Quand s'en servir`, `When to use it`)}</b><p>${escHtml(d.quand)}</p></div>
        <div><b>${T(`Exemple`, `Example`)}</b><p>${escHtml(d.exemple)}</p></div>
      </div>`;
  }
  const points = nb > 1 ? `<span class="cx-guide-points" aria-hidden="true">${[0, 1].map(i => `<span class="${i === page ? 'actif' : ''}"></span>`).join('')}</span>` : '';
  const html = `<div class="cx-guide" id="cxGuide">
      <div class="cx-guide-onglets" role="tablist">
        <button type="button" role="tab" class="${page === 0 ? 'actif' : ''}" aria-selected="${page === 0}" onclick="_cxGuide(0)">${escHtml(T(`Fonctionnement général`, `How it works`))}</button>
        ${d ? `<button type="button" role="tab" class="${page === 1 ? 'actif' : ''}" aria-selected="${page === 1}" onclick="_cxGuide(1)">${escHtml(T(`Cet écran`, `This screen`))}</button>` : ''}
      </div>
      <div class="cx-guide-corps">${corps}</div>
      <div class="cx-guide-pied">
        <button type="button" class="btn btn-ghost btn-sm" onclick="closeModal()">${premiereFois ? escHtml(T(`Passer`, `Skip`)) : escHtml(T(`Fermer`, `Close`))}</button>
        ${points}
        <span style="flex:1"></span>
        ${d && d.fiche ? `<button type="button" class="btn btn-ghost btn-sm" onclick="_cxOuvrirFiche('${d.fiche}')">${lk('book-open')} ${escHtml(T(`Fiche d'aide complète`, `Full help sheet`))}</button>` : ''}
        ${page < nb - 1 ? `<button type="button" class="btn btn-primary btn-sm" onclick="_cxGuide(${page + 1}, ${premiereFois})">${escHtml(T(`Suivant`, `Next`))} ›</button>`
                        : `<button type="button" class="btn btn-primary btn-sm" onclick="closeModal()">${escHtml(T(`C'est parti`, `Let's go`))}</button>`}
      </div>
      <p class="cx-guide-revoir">${escHtml(T(`Pour le revoir : le bouton « ? » en haut de l'écran.`, `To see it again: the “?” button at the top of the screen.`))}</p>
    </div>`;
  if (document.getElementById('cxGuide')) {
    document.getElementById('cxGuide').outerHTML = html;
  } else {
    openModal(T(`Supervision : mode d'emploi`, `Supervision: how it works`), html, null, null, T(`Fermer`, `Close`), '720px');
    const pied = document.querySelector('#modalOverlay .modal-footer');
    if (pied) pied.style.display = 'none';      // les boutons du mode d'emploi suffisent
  }
}

/** Ouvre l'aide intégrée sur une fiche précise. */
function _cxOuvrirFiche(id) {
  App._aideFiche = id;
  try { closeModal(); } catch (_) { /* pas de fenêtre ouverte */ }
  navigate('aide');
}

// ── Bulles « ? » (réglages) et bulles des boutons d'action ──────────────────

/** [titre, explication] de chaque bulle. */
function _cxBulles() {
  const T = (fr, en) => _cxT(fr, en);
  return {
    // Boutons d'action
    'action.modifier': [T(`Modifier`, `Edit`), T(`Ouvre la fiche de la connexion : nom, bâtiment, adresse, identifiants, rythme d'interrogation. Rien n'est envoyé à l'équipement.`, `Opens the connection: name, building, address, credentials, polling rate. Nothing is sent to the device.`)],
    'action.tester': [T(`Tester la connexion`, `Test the connection`), T(`Larka contacte l'équipement maintenant et dit, étape par étape, ce qui fonctionne et ce qui bloque : réseau et pare-feu, dialogue, identifiants, lecture des points. N'enregistre rien.`, `Larka contacts the device now and tells, step by step, what works and what blocks: network and firewall, dialogue, credentials, reading of points. Saves nothing.`)],
    'action.decouvrir': [T(`Découvrir les points`, `Discover points`), T(`Demande à l'équipement la liste de ses valeurs (objets BACnet, sujets MQTT, points de l'API, colonnes du CSV). Vous cochez celles à suivre : elles deviennent des points.`, `Asks the device for the list of its values (BACnet objects, MQTT topics, API points, CSV columns). You tick those to follow: they become points.`)],
    'action.explorer': [T(`Explorer les registres`, `Explore registers`), T(`Modbus ne sait pas lister ses valeurs : l'explorateur lit une plage de registres pour vous aider à trouver la bonne adresse, documentation du compteur en main.`, `Modbus cannot list its values: the explorer reads a range of registers to help you find the right address, with the meter's documentation at hand.`)],
    'action.lire': [T(`↻ Lire maintenant`, `↻ Read now`), T(`Interroge l'équipement tout de suite au lieu d'attendre le prochain passage du serveur, et met ses valeurs à jour.`, `Polls the device right away instead of waiting for the server's next run, and updates its values.`)],
    'action.commandes': [T(`Commandes de la borne`, `Charger commands`), T(`Arrêter ou démarrer une charge, rendre la borne indisponible, la redémarrer, déverrouiller un connecteur. Les commandes sensibles demandent de confirmer votre identité.`, `Stop or start a session, make the charger unavailable, restart it, unlock a connector. Sensitive commands ask you to confirm your identity.`)],
    'action.supprimer': [T(`Supprimer la connexion`, `Delete the connection`), T(`Supprime la connexion, ses points, leur historique et leurs règles. Irréversible : dans le doute, désactivez-la plutôt (puis « Connexion active »).`, `Deletes the connection, its points, their history and rules. Irreversible: when in doubt, disable it instead (then “Active connection”).`)],
    'action.courbe': [T(`Courbe`, `Chart`), T(`L'historique de la valeur sur 24 heures, 7 jours, 30 jours ou 1 an.`, `The value's history over 24 hours, 7 days, 30 days or 1 year.`)],
    'action.point.modifier': [T(`Modifier le point`, `Edit the point`), T(`Nom, adresse dans l'équipement, mise à l'échelle, historique, rattachement à un équipement de l'inventaire, écriture autorisée.`, `Name, address in the device, scaling, history, link to an inventory equipment, write permission.`)],
    'action.ecrire': [T(`Écrire une valeur`, `Write a value`), T(`Envoie une nouvelle consigne à l'équipement, dans la plage permise. Larka vous demande de confirmer votre identité et garde la trace de chaque écriture.`, `Sends a new setpoint to the device, within the allowed range. Larka asks you to confirm your identity and keeps track of every write.`)],
    'action.point.supprimer': [T(`Supprimer le point`, `Delete the point`), T(`Supprime le point, son historique, ses règles d'alarme et ses relevés automatiques.`, `Deletes the point, its history, its alarm rules and automatic readings.`)],
    'action.regles': [T(`Règles d'alarme du point`, `Point's alarm rules`), T(`Le nombre de règles qui surveillent ce point. Cliquez pour les voir ou en ajouter une (seuil, durée, gravité, demande d'intervention).`, `The number of rules watching this point. Click to see them or add one (threshold, duration, severity, work request).`)],
    'action.releves': [T(`Relevés automatiques du point`, `Point's automatic readings`), T(`Le nombre de relevés qui recopient cette valeur dans le module Énergie. Cliquez pour en créer un.`, `The number of readings copying this value into the Energy module. Click to create one.`)],
    'action.historique': [T(`Historique du relevé`, `Reading history`), T(`Chaque échéance : faite, rattrapée, manquante ou en anomalie, avec la valeur écrite.`, `Each due date: done, caught up, missing or anomalous, with the value written.`)],
    'action.regle.modifier': [T(`Modifier la règle`, `Edit the rule`), T(`Condition, seuil, durée minimale, gravité, demande d'intervention et notification.`, `Condition, threshold, minimum duration, severity, work request and notification.`)],
    'action.regle.supprimer': [T(`Supprimer la règle`, `Delete the rule`), T(`Les alarmes déjà ouvertes par cette règle restent dans l'historique.`, `Alarms already opened by this rule stay in the history.`)],
    'action.releve.modifier': [T(`Modifier le relevé`, `Edit the reading`), T(`Valeur relevée, compteur qui la reçoit, calendrier et contrôles.`, `Value read, meter receiving it, schedule and checks.`)],
    'action.releve.supprimer': [T(`Supprimer le relevé`, `Delete the reading`), T(`Les relevés déjà écrits dans le module Énergie restent.`, `Readings already written to the Energy module stay.`)],
    'action.valider': [T(`✓ Valider`, `✓ Confirm`), T(`Écrit la valeur dans le compteur du module Énergie, comme une saisie.`, `Writes the value into the Energy module meter, like a manual entry.`)],
    'action.rejeter': [T(`✕ Rejeter`, `✕ Reject`), T(`N'écrit rien et clôt l'échéance. À faire pour une valeur manifestement fausse (compteur changé, index remis à zéro).`, `Writes nothing and closes the due date. For an obviously wrong value (meter replaced, index reset).`)],
    'action.bat.detecter': [T(`Détecter dans ce bâtiment`, `Detect in this building`), T(`Parcourt les réseaux de ce bâtiment et propose les équipements trouvés, déjà rangés dans ce bâtiment.`, `Scans this building's networks and suggests the devices found, already filed in this building.`)],
    'action.bat.tous': [T(`Détecter dans tous les bâtiments`, `Detect in all buildings`), T(`Parcourt les réseaux de tous les bâtiments : chaque équipement trouvé est proposé dans le bâtiment dont il porte l'adresse.`, `Scans every building's networks: each device found is suggested in the building whose address it has.`)],
    'action.bat.modifier': [T(`Réseaux et mots-clés`, `Networks and keywords`), T(`Les adresses IP des équipements de ce bâtiment et les mots par lesquels ils se nomment.`, `The IP addresses of this building's devices and the words they are named by.`)],
    // Bâtiments
    'bat.reseaux': [T(`Réseaux du bâtiment`, `Building networks`), T(`Les adresses IP de ses équipements techniques : une plage (192.168.21.0/24 = les 254 adresses 192.168.21.x), un intervalle (192.168.21.10-60) ou une adresse. Votre service informatique les connaît ; Larka les apprend aussi d'après les connexions déjà rangées (« appris »).`, `The IP addresses of its technical devices: a range (192.168.21.0/24 = the 254 addresses 192.168.21.x), an interval (192.168.21.10-60) or an address. Your IT department knows them; Larka also learns them from the connections already filed (“learned”).`)],
    'bat.motscles': [T(`Mots-clés`, `Keywords`), T(`Les mots par lesquels les équipements de ce bâtiment se nomment : sigle (HDV), ancien nom (mairie), repère des bornes… Quand l'adresse ne suffit pas, Larka range un équipement dont le nom contient l'un d'eux. Le nom du bâtiment et son sigle comptent déjà.`, `The words this building's devices are named by: acronym, former name, charger labels… When the address is not enough, Larka files a device whose name contains one of them. The building's name and acronym already count.`)],
    // Détection
    'det.ou': [T(`Où chercher ?`, `Where to look?`), T(`Un bâtiment : Larka parcourt ses réseaux et y range ce qu'il trouve. Tous les bâtiments : chaque équipement va au bâtiment dont il porte l'adresse. Autres adresses : une plage saisie à la main ; Larka devine alors le bâtiment d'après les réseaux et les noms.`, `One building: Larka scans its networks and files what it finds there. All buildings: each device goes to the building whose address it has. Other addresses: a range typed by hand; Larka then guesses the building from networks and names.`)],
    'det.plage': [T(`Adresses à parcourir`, `Addresses to scan`), T(`Seules les adresses du réseau interne sont parcourues. Larka ne fait que lire (poignée de main, Who-Is, page d'accueil) : rien n'est écrit, aucun mot de passe n'est essayé.`, `Only internal network addresses are scanned. Larka only reads (handshake, Who-Is, home page): nothing is written, no password is tried.`)],
    'det.protocoles': [T(`Rechercher`, `Look for`), T(`Les familles d'équipements à reconnaître. Décocher ce qui n'existe pas chez vous accélère la détection. « Bornes refusées » : les bornes qui ont appelé Larka sans être déclarées.`, `The device families to recognise. Unticking what you do not have speeds up detection. “Refused chargers”: chargers that called Larka without being declared.`)],
    'det.ports': [T(`Ports particuliers`, `Custom ports`), T(`Chaque protocole écoute d'habitude sur un port connu (Modbus 502, BACnet 47808, MQTT 1883, web 80/443). Ajoutez un port si un équipement en utilise un autre : passerelle Modbus sur 503, superviseur sur 8081…`, `Each protocol usually listens on a known port (Modbus 502, BACnet 47808, MQTT 1883, web 80/443). Add a port if a device uses another one: Modbus gateway on 503, supervisor on 8081…`)],
    'det.batiment': [T(`Bâtiment proposé`, `Suggested building`), T(`Deviné d'après le réseau déclaré du bâtiment, le réseau appris, ou le nom de l'équipement (sous la liste : pourquoi). Changez-le avant de raccorder si besoin.`, `Guessed from the building's declared network, the learned network, or the device's name (below the list: why). Change it before connecting if needed.`)],
    'det.ecartes': [T(`Écartés d'office`, `Set aside`), T(`Ce qui répond mais n'est pas un équipement technique : poste de travail Windows, imprimante, NAS, box ou point d'accès, caméra, ou simple page web sans API. Larka le reconnaît aux ports ouverts de la machine et à sa page d'accueil. Un vrai protocole technique (Modbus, BACnet, MQTT) n'est jamais écarté, même sur un PC Windows : c'est souvent un PC relais.`, `What answers but is not a technical device: Windows workstation, printer, NAS, router or access point, camera, or a plain web page with no API. Larka recognises it from the machine's open ports and its home page. A real technical protocol (Modbus, BACnet, MQTT) is never set aside, even on a Windows PC: it is often a relay PC.`)],
    'det.exclusions': [T(`Exclusions`, `Exclusions`), T(`Ce que la détection ne doit plus proposer : des adresses jamais parcourues (plage des postes utilisateurs, téléphonie, Wi-Fi invités), et les appareils ou machines ignorés d'un clic ().`, `What detection must no longer suggest: addresses never scanned (user workstation range, telephony, guest Wi-Fi), and devices or machines ignored with one click ().`)],
    'action.ignorer': [T(`Ignorer`, `Ignore`), T(`La détection ne proposera plus cet appareil (ce protocole à cette adresse). Réversible dans « Exclusions ».`, `Detection will no longer suggest this device (this protocol at this address). Reversible in “Exclusions”.`)],
    'action.ignorer.selection': [T(`Ignorer la sélection`, `Ignore the selection`), T(`Les appareils cochés ne seront plus proposés par la détection.`, `Ticked devices will no longer be suggested by detection.`)],
    'action.ignorer.machine': [T(`Ignorer la machine`, `Ignore the machine`), T(`Plus rien de cette adresse ne sera proposé. Pour des postes à adresse dynamique, préférez « Adresses à ne jamais parcourir ».`, `Nothing from this address will be suggested again. For workstations with dynamic addresses, prefer “Addresses never to scan”.`)],
    'action.proposer': [T(`Proposer quand même`, `Suggest anyway`), T(`Remet l'appareil dans la liste à raccorder, non coché : utile si Larka s'est trompé.`, `Puts the device back in the list to connect, unticked: useful if Larka got it wrong.`)],
    'det.confiance': [T(`Ce que Larka a reconnu`, `What Larka recognised`), T(`« Reconnu » : l'équipement a répondu dans son protocole. « Probable » : signes concordants. « À vérifier » : un port est ouvert, sans certitude ; décoché par défaut.`, `“Recognised”: the device answered in its protocol. “Likely”: matching signs. “To check”: a port is open, without certainty; unticked by default.`)],
    // Connexion
    'cx.batiment': [T(`Bâtiment`, `Building`), T(`Range la connexion : supervision, alarmes et listes se regroupent par bâtiment. La détection le devine d'après les réseaux des bâtiments.`, `Files the connection: supervision, alarms and lists are grouped by building. Detection guesses it from the buildings' networks.`)],
    'cx.hote': [T(`Adresse`, `Address`), T(`L'adresse IP (192.168.21.12) ou le nom réseau de l'équipement, tel que le serveur Larka le joint.`, `The device's IP address (192.168.21.12) or network name, as the Larka server reaches it.`)],
    'cx.port': [T(`Port`, `Port`), T(`Le numéro du service sur l'équipement. Gardez la valeur proposée, sauf indication contraire de sa documentation.`, `The service number on the device. Keep the suggested value unless its documentation says otherwise.`)],
    'cx.identifiant': [T(`Identifiant`, `Identifier`), T(`BACnet : le numéro d'automate (device instance) ; vide, Larka le demande. Borne : son identifiant OCPP (ChargeBoxId), tel que réglé dans la borne.`, `BACnet: the controller number (device instance); empty, Larka asks for it. Charger: its OCPP identifier (ChargeBoxId), as set in the charger.`)],
    'cx.intervalle': [T(`Interroger toutes les`, `Poll every`), T(`Le rythme auquel le serveur lit l'équipement, même quand personne n'a Larka ouvert. Plus court, c'est plus réactif mais plus de trafic : 1 à 5 minutes conviennent à presque tout.`, `How often the server reads the device, even when nobody has Larka open. Shorter is more reactive but more traffic: 1 to 5 minutes suits almost everything.`)],
    'cx.identifiants': [T(`Identifiants`, `Credentials`), T(`Ceux qu'exige l'équipement (compte de l'API, mot de passe du broker, jeton). Chiffrés sur le serveur et jamais réaffichés : laissez vide pour garder la valeur enregistrée.`, `Those the device requires (API account, broker password, token). Encrypted on the server and never shown again: leave empty to keep the saved value.`)],
    'cx.acces': [T(`Accès à l'appareil`, `Device access`), T(`« Par le réseau » : le serveur Larka le joint et l'interroge lui-même. « Par son hotspot Wi-Fi » : l'appareil n'est joignable que sur son propre Wi-Fi ; un PC releveur s'y connecte, lit ses valeurs et les envoie. « Par câble, en direct » : l'appareil n'est sur aucun réseau ; on branche un PC releveur dessus, qui relève hors ligne et envoie au retour sur le réseau (onglet Hors réseau).`, `“Through the network”: the Larka server reaches and polls it itself. “Through its Wi-Fi hotspot”: the device is only reachable on its own Wi-Fi; a reader PC connects to it, reads its values and sends them. “By cable, directly”: the device is on no network; a reader PC is plugged into it, reads offline and sends once back on the network (Off the network tab).`)],
    'cx.ssid': [T(`Wi-Fi de l'appareil`, `Device Wi-Fi`), T(`Le nom (SSID) du Wi-Fi que diffuse l'appareil, exactement comme il apparaît dans la liste des Wi-Fi du PC. L'adresse ci-dessous est alors celle de l'appareil sur ce Wi-Fi (souvent 192.168.4.1 ou 10.0.0.1, voir sa notice).`, `The name (SSID) of the Wi-Fi the device broadcasts, exactly as it appears in the PC's Wi-Fi list. The address below is then the device's address on that Wi-Fi (often 192.168.4.1 or 10.0.0.1, see its manual).`)],
    'cx.cle_wifi': [T(`Clé du Wi-Fi`, `Wi-Fi key`), T(`Le mot de passe du hotspot (8 à 63 caractères). Chiffré sur le serveur, il n'est remis qu'au collecteur du PC releveur, qui le range dans un profil Wi-Fi de Windows.`, `The hotspot's password (8 to 63 characters). Encrypted on the server, it is only handed to the reader PC's collector, which stores it in a Windows Wi-Fi profile.`)],
    'action.hotspot': [T(`Relevé par un PC`, `Read by a PC`), T(`Cet appareil est hors réseau (son hotspot Wi-Fi, ou un câble en direct) : le serveur ne peut ni le tester ni le lire. Un PC releveur le fait pour lui ; ouvre l'onglet Hors réseau.`, `This device is off the network (its Wi-Fi hotspot, or a direct cable): the server can neither test nor read it. A reader PC does it instead; opens the Off the network tab.`)],
    'action.paquet': [T(`Télécharger le collecteur`, `Download the collector`), T(`Un zip déjà réglé pour ce PC : adresse de Larka, jeton, programme de lecture. Sur le PC, décompressez-le et double-cliquez releveur.cmd. Chaque téléchargement crée un nouveau jeton : l'ancien collecteur cesse de fonctionner.`, `A zip already set up for this PC: Larka's address, token, reading program. On the PC, unzip it and double-click releveur.cmd. Each download creates a new token: the old collector stops working.`)],
    'action.releveur.modifier': [T(`Modifier le PC releveur`, `Edit the reader PC`), T(`Son nom, les appareils qu'il relève, actif ou non. Le collecteur déjà installé prend les changements à son prochain relevé.`, `Its name, the devices it reads, active or not. The installed collector picks up the changes at its next reading.`)],
    'action.releveur.supprimer': [T(`Supprimer le PC releveur`, `Delete the reader PC`), T(`Son jeton ne fonctionne plus ; les relevés déjà reçus restent. Sur le PC, « releveur.cmd retirer » enlève ses profils Wi-Fi (et d'éventuelles tâches planifiées d'une ancienne version).`, `Its token stops working; readings already received are kept. On the PC, “releveur.cmd retirer” removes its Wi-Fi profiles (and any scheduled tasks left by an older version).`)],
    'rl.appareils': [T(`Appareils qu'il relève`, `Devices it reads`), T(`Ceux à portée de ce PC. Plusieurs PC peuvent relever le même appareil ; un relevé déjà reçu n'est pas compté deux fois.`, `Those within reach of this PC. Several PCs can read the same device; a reading already received is not counted twice.`)],
    'cx.actif': [T(`Connexion active`, `Active connection`), T(`Décochée, le serveur ne l'interroge plus mais garde tout : points, historique, règles. Pratique pendant des travaux.`, `Unticked, the server stops polling it but keeps everything: points, history, rules. Handy during works.`)],
    // Paramètres des protocoles
    'p.base_adresse': [T(`Numérotation des registres`, `Register numbering`), T(`La documentation du compteur numérote ses registres à partir de 0 ou de 1 : choisissez la même chose. Les écritures « 40001 » ou « 30001 » (notation Modicon) sont reconnues d'office. Une adresse réelle de 40001 à 49999 (onduleur SunSpec) s'écrit alors en 6 chiffres : 440084 pour 40083.`, `The meter's documentation numbers its registers from 0 or 1: choose the same. “40001” or “30001” (Modicon notation) are recognised. A real address from 40001 to 49999 (SunSpec inverter) is then written with 6 digits: 440084 for 40083.`)],
    'p.ordre_defaut': [T(`Ordre des mots`, `Word order`), T(`Une valeur sur 32 bits occupe deux registres ; certains fabricants mettent le mot de poids fort en premier (ABCD), d'autres en second (CDAB). Une valeur absurde (énorme ou minuscule) signale souvent le mauvais ordre.`, `A 32-bit value takes two registers; some manufacturers put the high word first (ABCD), others second (CDAB). An absurd value (huge or tiny) often means the wrong order.`)],
    'p.ordre': [T(`Ordre des mots`, `Word order`), T(`Pour ce point seulement (sinon celui de la connexion). ABCD : mot de poids fort d'abord ; CDAB : mots inversés.`, `For this point only (otherwise the connection's). ABCD: high word first; CDAB: words swapped.`)],
    'p.unite_defaut': [T(`Unit id`, `Unit id`), T(`Le numéro de l'appareil derrière une passerelle Modbus (1 le plus souvent). Plusieurs compteurs derrière la même passerelle ont chacun le leur.`, `The device number behind a Modbus gateway (usually 1). Several meters behind the same gateway each have their own.`)],
    'p.unite': [T(`Unit id`, `Unit id`), T(`Le numéro de l'appareil derrière la passerelle, pour ce point (vide : celui de la connexion).`, `The device number behind the gateway, for this point (empty: the connection's).`)],
    'p.table': [T(`Table`, `Table`), T(`Registres de maintien (fonction 03, les plus courants), registres d'entrée (04), bobines (01) ou entrées TOR (02) : la documentation du compteur l'indique.`, `Holding registers (function 03, the most common), input registers (04), coils (01) or discrete inputs (02): the meter's documentation says which.`)],
    'p.type': [T(`Type de donnée`, `Data type`), T(`Comment lire les registres : entier sur 16 ou 32 bits, signé ou non, réel (float). Un réel ou un 32 bits occupe deux registres consécutifs.`, `How to read the registers: 16 or 32-bit integer, signed or not, real (float). A real or a 32-bit value takes two consecutive registers.`)],
    'p.registre': [T(`Registre`, `Register`), T(`L'adresse de la valeur dans l'équipement, d'après sa documentation (table des registres). Explorer aide à la vérifier.`, `The value's address in the device, from its documentation (register table). Explore helps check it.`)],
    'p.bit': [T(`Bit`, `Bit`), T(`Pour lire un seul indicateur dans un registre qui en regroupe seize (mot d'état) : 0 pour le premier.`, `To read one flag in a register grouping sixteen (status word): 0 for the first.`)],
    'p.lecture_groupee': [T(`Lecture groupée`, `Grouped reading`), T(`Lit plusieurs valeurs en une seule requête (ReadPropertyMultiple). Plus rapide ; à décocher si un vieil automate refuse.`, `Reads several values in one request (ReadPropertyMultiple). Faster; untick if an old controller refuses.`)],
    'p.priorite_ecriture': [T(`Priorité d'écriture`, `Write priority`), T(`BACnet garde 16 niveaux de priorité pour une consigne ; la plus haute l'emporte. 8 (opérateur manuel) convient en général ; 16 est la plus faible.`, `BACnet keeps 16 priority levels for a setpoint; the highest wins. 8 (manual operator) usually suits; 16 is the lowest.`)],
    'p.objet': [T(`Type d'objet`, `Object type`), T(`analog-input : une mesure ; analog-value : une consigne ou un calcul ; binary-input/value : un état marche/arrêt ou un défaut ; multi-state : un mode.`, `analog-input: a measurement; analog-value: a setpoint or computed value; binary-input/value: an on/off state or fault; multi-state: a mode.`)],
    'p.instance': [T(`Instance`, `Instance`), T(`Le numéro de l'objet dans l'automate. Découvrir les liste tous, avec leur nom.`, `The object's number in the controller. Discover lists them all, with their names.`)],
    'p.sujets': [T(`Sujets à suivre`, `Topics to follow`), T(`Les sujets MQTT écoutés, un par ligne. « + » remplace un niveau, « # » tout ce qui suit : batiment/+/temperature, capteurs/#.`, `The MQTT topics listened to, one per line. “+” replaces one level, “#” everything after: building/+/temperature, sensors/#.`)],
    'p.sujet': [T(`Sujet`, `Topic`), T(`Le sujet MQTT exact qui porte cette valeur.`, `The exact MQTT topic carrying this value.`)],
    'p.chemin': [T(`Chemin de la valeur`, `Value path`), T(`Où se trouve la valeur dans le message JSON : temperature, ou data.temperature pour un champ imbriqué. Vide si le message est la valeur elle-même.`, `Where the value is in the JSON message: temperature, or data.temperature for a nested field. Empty if the message is the value itself.`)],
    'p.session_persistante': [T(`Session persistante`, `Persistent session`), T(`Le broker garde les messages arrivés pendant une coupure et les remet à la reconnexion : aucune valeur perdue.`, `The broker keeps messages that arrived during an outage and delivers them on reconnection: no value lost.`)],
    'p.tls': [T(`Connexion chiffrée`, `Encrypted connection`), T(`À cocher si le broker l'exige (port 8883 le plus souvent).`, `Tick if the broker requires it (usually port 8883).`)],
    'p.url_base': [T(`Adresse de l'API`, `API address`), T(`Le début commun des adresses de l'API, par exemple http://192.168.21.15/api. Les chemins des points s'y ajoutent.`, `The common start of the API's addresses, e.g. http://192.168.21.15/api. The points' paths are added to it.`)],
    'p.auth': [T(`Authentification`, `Authentication`), T(`Comment l'API reconnaît Larka : aucune, jeton (en-tête Authorization: Bearer), clé dans un en-tête, ou identifiant et mot de passe (Basic).`, `How the API recognises Larka: none, token (Authorization: Bearer header), key in a header, or username and password (Basic).`)],
    'p.dossier': [T(`Dossier des fichiers`, `Files folder`), T(`Le dossier du serveur où l'ancienne GTB dépose ses exports. Il doit se trouver sous une racine autorisée par l'administrateur (par défaut data/connect/imports).`, `The server folder where the old BMS drops its exports. It must be under a root allowed by the administrator (by default data/connect/imports).`)],
    'p.motif': [T(`Noms de fichiers`, `File names`), T(`Les fichiers à lire, avec * pour « n'importe quoi » : GTB_EXPORT_*.csv. Un fichier n'est jamais lu deux fois.`, `The files to read, with * for “anything”: BMS_EXPORT_*.csv. A file is never read twice.`)],
    'p.format': [T(`Disposition`, `Layout`), T(`Long : une ligne par valeur (date, repère, valeur). Large : une colonne par point, une ligne par date.`, `Long: one line per value (date, label, value). Wide: one column per point, one line per date.`)],
    'p.separateur': [T(`Séparateur`, `Separator`), T(`Le caractère entre les colonnes ; « auto » le devine (point-virgule, virgule, tabulation).`, `The character between columns; “auto” guesses it (semicolon, comma, tab).`)],
    'p.encodage': [T(`Encodage`, `Encoding`), T(`« auto » reconnaît UTF-8 et Windows-1252 (fréquent sur les vieilles GTB : accents illisibles sinon).`, `“auto” recognises UTF-8 and Windows-1252 (common on old BMS: unreadable accents otherwise).`)],
    'p.stabilite_secondes': [T(`Fichier stable depuis`, `File stable for`), T(`Un fichier encore en cours d'écriture n'est pas lu : Larka attend qu'il ne bouge plus depuis ce délai.`, `A file still being written is not read: Larka waits until it has not changed for this long.`)],
    'p.badges': [T(`Badges acceptés`, `Accepted badges`), T(`Tous, ou seulement ceux de la liste : les autres sont refusés par la borne.`, `All, or only those in the list: the others are refused by the charger.`)],
    'p.intervalle_battement': [T(`Battement de cœur`, `Heartbeat`), T(`La borne signale qu'elle est en vie à ce rythme ; sans nouvelles, Larka la passe hors ligne.`, `The charger signals it is alive at this rate; without news, Larka marks it offline.`)],
    'p.delai_secondes': [T(`Délai de réponse`, `Response timeout`), T(`Le temps laissé à l'équipement pour répondre. À allonger pour un automate lent ou une liaison radio.`, `The time given to the device to answer. Lengthen for a slow controller or a radio link.`)],
    // Point
    'pt.code': [T(`Code (repère)`, `Code (label)`), T(`Le repère de la valeur dans l'équipement ou sur les plans (ex. CTA1_TSOUF). Facultatif ; aide à retrouver un point.`, `The value's label in the device or on the plans (e.g. AHU1_TSUP). Optional; helps find a point.`)],
    'pt.nature': [T(`Nature`, `Nature`), T(`Mesure : une valeur qui monte et descend. Compteur : un index qui ne fait que croître (énergie, eau, heures de marche). État ou alarme : 0/1, marche/arrêt, défaut. Consigne : une valeur qu'on peut écrire.`, `Measurement: a value going up and down. Meter: an index that only grows (energy, water, running hours). State or alarm: 0/1, on/off, fault. Setpoint: a value that can be written.`)],
    'pt.coef': [T(`Coefficient et décalage`, `Factor and offset`), T(`Valeur gardée = valeur lue × coefficient + décalage. Exemple : un registre en dixièmes de degré (215 pour 21,5 °C) prend un coefficient de 0,1.`, `Value kept = value read × factor + offset. Example: a register in tenths of a degree (215 for 21.5 °C) takes a factor of 0.1.`)],
    'pt.bande': [T(`Bande morte`, `Deadband`), T(`Une variation plus petite que cette valeur n'est pas historisée : l'historique reste léger. Une valeur stable est quand même enregistrée régulièrement.`, `A change smaller than this is not recorded: the history stays light. A steady value is still recorded regularly.`)],
    'pt.equipement': [T(`Équipement de l'inventaire`, `Inventory equipment`), T(`La chaudière, la CTA ou le compteur de l'inventaire auquel la valeur se rapporte. Une alarme sur ce point crée sa demande d'intervention sur cet équipement.`, `The boiler, AHU or meter of the inventory the value relates to. An alarm on this point creates its work request on this equipment.`)],
    'pt.ecriture': [T(`Écriture depuis Larka`, `Writing from Larka`), T(`Permet de modifier la valeur (une consigne) depuis la supervision, dans la plage minimum–maximum. Chaque écriture exige un gestionnaire qui confirme son identité, et reste tracée.`, `Allows changing the value (a setpoint) from supervision, within the minimum–maximum range. Every write requires a manager confirming their identity, and is logged.`)],
    // Règle
    'rg.condition': [T(`Condition`, `Condition`), T(`Supérieur ou inférieur à un seuil, hors d'une plage, égal à un état (défaut = 1), sans valeur depuis un moment (capteur muet), ou palier franchi (heures de marche : 2 000, 4 000…).`, `Above or below a threshold, outside a range, equal to a state (fault = 1), no value for a while (silent sensor), or step crossed (running hours: 2,000, 4,000…).`)],
    'rg.delai': [T(`Pendant au moins`, `For at least`), T(`La condition doit durer ce temps avant l'alarme : un pic passager (porte ouverte, démarrage) ne déclenche rien. 0 : dès la première valeur.`, `The condition must last this long before the alarm: a passing spike (door opened, start-up) triggers nothing. 0: from the first value.`)],
    'rg.gravite': [T(`Gravité`, `Severity`), T(`Mineure, majeure ou critique. Elle fixe l'urgence de la demande d'intervention créée (critique : urgente) et l'ordre des alarmes.`, `Minor, major or critical. It sets the urgency of the work request created (critical: urgent) and the order of alarms.`)],
    'rg.demande': [T(`Demande d'intervention`, `Work request`), T(`À l'ouverture de l'alarme, une demande est créée d'office, sur l'équipement rattaché au point. Une alarme déjà ouverte n'en crée pas de seconde.`, `When the alarm opens, a request is created automatically, on the equipment linked to the point. An alarm already open does not create a second one.`)],
    'rg.notifier': [T(`Prévenir les gestionnaires`, `Notify managers`), T(`Une notification (cloche de Larka, et notification du téléphone si elle est activée) à l'ouverture de l'alarme.`, `A notification (Larka bell, and phone notification if enabled) when the alarm opens.`)],
    // Relevé
    'rl.valeur': [T(`Valeur à relever`, `Value to read`), T(`Le point qui porte l'index : de préférence un point de nature « Compteur ».`, `The point carrying the index: preferably a “Meter” point.`)],
    'rl.cible': [T(`Compteur qui reçoit`, `Receiving meter`), T(`Le compteur du module Énergie où le relevé est écrit, comme une saisie. Créez-le d'abord dans Énergie s'il n'existe pas.`, `The Energy module meter where the reading is written, like a manual entry. Create it first in Energy if it does not exist.`)],
    'rl.coef': [T(`Coefficient`, `Factor`), T(`Pour passer d'une unité à l'autre : 1 si ce sont les mêmes, 0,001 des Wh aux kWh.`, `To convert units: 1 if they are the same, 0.001 from Wh to kWh.`)],
    'rl.frequence': [T(`Fréquence`, `Frequency`), T(`Chaque jour, certains jours, une fois par mois, ou une expression cron pour les cas particuliers.`, `Every day, some days, once a month, or a cron expression for special cases.`)],
    'rl.tolerance': [T(`Tolérance`, `Tolerance`), T(`Larka prend la valeur la plus proche de l'heure prévue, à plus ou moins ce délai. Sans valeur dans ce créneau : relevé « manquant » et alarme mineure.`, `Larka takes the value closest to the planned time, within this margin. No value in that window: “missing” reading and minor alarm.`)],
    'rl.anomalie': [T(`Contrôle d'anomalie`, `Anomaly check`), T(`Une consommation plus de N fois supérieure à la médiane habituelle est mise de côté pour validation au lieu d'être écrite. Un index qui recule l'est toujours.`, `A consumption more than N times the usual median is set aside for approval instead of being written. An index going backwards always is.`)],
    'rl.validation': [T(`Validation manuelle`, `Manual approval`), T(`Chaque relevé attend l'accord d'un gestionnaire avant d'être écrit. Utile les premières semaines, le temps de vérifier les coefficients.`, `Each reading waits for a manager's approval before being written. Useful in the first weeks, while checking the factors.`)],
  };
}

let _cxClesBulles = null;
function _cxABulle(cle) {
  if (!_cxClesBulles) _cxClesBulles = new Set(Object.keys(_cxBulles()));
  return _cxClesBulles.has(cle);
}

function _cxBulle(cle) {
  return `<button type="button" class="cx-bulle" data-cx-bulle="${escHtml(cle)}" aria-expanded="false" aria-label="${escHtml(_cxT(`Aide`, `Help`))}">?</button>`;
}

/** Bulle d'un bouton d'action : au survol ou au focus (remplace l'infobulle d'un mot). */
function _cxInfo(cle) { return ` data-cx-info="${escHtml(cle)}"`; }

(function _cxInstallerBulles() {
  if (typeof document === 'undefined' || window._cxBullesPretes) return;
  window._cxBullesPretes = true;
  let pop = null, cible = null, minuterie = null;
  const fermer = () => {
    clearTimeout(minuterie);
    if (pop) { pop.remove(); pop = null; }
    if (cible && cible.classList.contains('cx-bulle')) cible.setAttribute('aria-expanded', 'false');
    cible = null;
  };
  const ouvrir = (el, cle) => {
    const d = _cxBulles()[cle];
    if (!d || !el.isConnected) return;
    fermer();
    const r0 = el.getBoundingClientRect();
    if (!r0.width && !r0.height) return;                               // caché, ou retiré entre-temps
    pop = document.createElement('div');
    pop.className = 'cx-bulle-pop';
    pop.setAttribute('role', 'tooltip');
    const t = document.createElement('strong');
    t.textContent = d[0];
    const p = document.createElement('div');
    p.textContent = d[1];
    pop.append(t, p);
    document.body.appendChild(pop);
    const r = el.getBoundingClientRect();
    const l = Math.max(8, Math.min(r.left + r.width / 2 - pop.offsetWidth / 2, window.innerWidth - pop.offsetWidth - 8));
    let h = r.bottom + 8;
    if (h + pop.offsetHeight > window.innerHeight - 8) h = Math.max(8, r.top - pop.offsetHeight - 8);
    pop.style.left = l + 'px';
    pop.style.top = h + 'px';
    cible = el;
    if (el.classList.contains('cx-bulle')) el.setAttribute('aria-expanded', 'true');
  };
  document.addEventListener('click', e => {
    const b = e.target.closest && e.target.closest('.cx-bulle');
    if (b) {
      e.preventDefault();
      e.stopPropagation();
      if (cible === b) fermer(); else ouvrir(b, b.dataset.cxBulle);
      return;
    }
    // Un clic (sur un bouton d'action compris) annule la bulle en attente : l'écran va changer.
    if (pop && !(e.target.closest && e.target.closest('.cx-bulle-pop'))) fermer();
    else clearTimeout(minuterie);
  }, true);
  document.addEventListener('mouseover', e => {
    const el = e.target.closest && e.target.closest('[data-cx-info]');
    if (!el || el === cible) return;
    clearTimeout(minuterie);
    minuterie = setTimeout(() => { if (el.matches(':hover')) ouvrir(el, el.dataset.cxInfo); }, 350);
  });
  document.addEventListener('mouseout', e => {
    const el = e.target.closest && e.target.closest('[data-cx-info]');
    if (!el || el.contains(e.relatedTarget)) return;
    clearTimeout(minuterie);
    if (cible === el) fermer();
  });
  document.addEventListener('focusin', e => {
    const el = e.target.closest && e.target.closest('[data-cx-info]');
    if (el && el.matches(':focus-visible')) ouvrir(el, el.dataset.cxInfo);
  });
  document.addEventListener('focusout', e => {
    const el = e.target.closest && e.target.closest('[data-cx-info]');
    if (el && cible === el) fermer();
  });
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && pop) fermer(); });
  window.addEventListener('scroll', () => { if (pop) fermer(); }, { passive: true, capture: true });
})();

// ── Parcours guidé : les étapes du raccordement, cochées d'après l'état réel ──

function _cxParcoursMasque() { try { return localStorage.getItem('cx_parcours_masque') === '1'; } catch (_) { return false; } }

async function _cxParcours() {
  const z = document.getElementById('cxParcours');
  if (!z) return;
  if (_cxParcoursMasque()) { z.innerHTML = ''; return; }
  let d;
  try { d = await apiRequest('connect_parcours'); } catch (_) { z.innerHTML = ''; return; }
  if (!document.getElementById('cxParcours')) return;
  const etapes = [
    { fait: d.batiments > 0, titre: _cxT(`Créer les bâtiments`, `Create the buildings`),
      texte: _cxT(`Ceux des plans : un nom suffit.`, `Those of the floor plans: a name is enough.`),
      bouton: [_cxT(`Ouvrir Plans`, `Open Plans`), `navigate('plans')`] },
    { fait: d.batiments_avec_reseau > 0, titre: _cxT(`Indiquer le réseau de chaque bâtiment`, `Give each building's network`),
      texte: _cxT(`Les adresses IP de ses équipements : la détection y rangera d'office ce qu'elle trouve.`, `The IP addresses of its devices: detection will file what it finds there.`),
      bouton: [_cxT(`Onglet Bâtiments`, `Buildings tab`), `_cxOnglet('batiments')`], detail: d.batiments ? `${d.batiments_avec_reseau}/${d.batiments}` : '' },
    { fait: d.connexions > 0, titre: _cxT(`Détecter et raccorder les équipements`, `Detect and connect the devices`),
      texte: _cxT(`Larka parcourt les réseaux et propose ce qu'il reconnaît.`, `Larka scans the networks and suggests what it recognises.`),
      bouton: [_cxT(`Détecter`, `Detect`), `_cxDetection(${d.batiments_avec_reseau ? `'tous'` : ''})`], detail: d.connexions ? String(d.connexions) : '' },
    { fait: d.connexions > 0 && !d.sans_batiment, titre: _cxT(`Ranger chaque équipement dans son bâtiment`, `File each device in its building`),
      texte: d.sans_batiment ? _cxT(`${d.sans_batiment} connexion(s) sans bâtiment.`, `${_cxN(d.sans_batiment, `connection`, `connections`)} without a building.`) : _cxT(`Supervision et alarmes se regroupent par bâtiment.`, `Supervision and alarms are grouped by building.`),
      bouton: [_cxT(`Ranger`, `File`), `_cxOnglet('batiments')`] },
    { fait: d.connexions > 0 && !d.en_defaut, titre: _cxT(`Régler les connexions en défaut`, `Fix the faulty connections`),
      texte: d.en_defaut ? _cxT(`${d.en_defaut} connexion(s) ne répondent pas : Tester dit pourquoi (identifiants, pare-feu…).`, `${_cxN(d.en_defaut, `connection does`, `connections do`)} not answer: Test tells why (credentials, firewall…).`)
                         : _cxT(`Toutes les connexions répondent.`, `All connections answer.`),
      bouton: [_cxT(`Onglet Connexions`, `Connections tab`), `_cxOnglet('connexions')`] },
    { fait: d.points > 0 && !d.sans_points, titre: _cxT(`Choisir les valeurs à suivre`, `Choose the values to follow`),
      texte: d.sans_points ? _cxT(`${d.sans_points} connexion(s) sans point : Découvrir les propose.`, `${_cxN(d.sans_points, `connection`, `connections`)} without points: Discover suggests them.`)
                           : _cxT(`Les points s'affichent dans la supervision et s'historisent.`, `Points show in supervision and are recorded.`),
      bouton: [_cxT(`Onglet Points`, `Points tab`), `_cxOnglet('points')`], detail: d.points ? String(d.points) : '' },
    { fait: d.regles > 0, titre: _cxT(`Être prévenu d'une anomalie`, `Be warned of an anomaly`),
      texte: _cxT(`Une règle d'alarme sur chaque valeur qui compte (colonne des points).`, `An alarm rule on each value that matters (column of points).`),
      bouton: [_cxT(`Onglet Points`, `Points tab`), `_cxOnglet('points')`], detail: d.regles ? String(d.regles) : '' },
    { fait: d.liaisons > 0, facultatif: true, titre: _cxT(`Relever les compteurs automatiquement`, `Read meters automatically`),
      texte: _cxT(`Vers le module Énergie, chaque jour, sans saisie.`, `Into the Energy module, every day, without typing.`),
      bouton: [_cxT(`Relevés automatiques`, `Automatic readings`), `_cxOnglet('releves')`] },
  ];
  if (d.bornes || d.bornes_inconnues) {
    etapes.push({ fait: !d.bornes_inconnues, facultatif: true, titre: _cxT(`Déclarer les bornes de recharge`, `Declare the EV chargers`),
      texte: d.bornes_inconnues ? _cxT(`${d.bornes_inconnues} borne(s) refusée(s) attendent d'être déclarées.`, `${_cxN(d.bornes_inconnues, `refused charger waits`, `refused chargers wait`)} to be declared.`)
                                : _cxT(`Toutes les bornes qui appellent Larka sont déclarées.`, `All chargers calling Larka are declared.`),
      bouton: [_cxT(`Onglet Bornes`, `Chargers tab`), `_cxOnglet('bornes')`] });
  }
  if (d.hotspots) {
    etapes.push({ fait: !d.hotspots_sans_releveur && !d.hotspots_jamais_releves, facultatif: true, titre: _cxT(`Relever les appareils hors réseau`, `Read the off-network devices`),
      texte: d.hotspots_sans_releveur ? _cxT(`${d.hotspots_sans_releveur} appareil(s) hors réseau sans PC releveur.`, `${_cxN(d.hotspots_sans_releveur, `off-network device`, `off-network devices`)} without a reader PC.`)
           : (d.hotspots_jamais_releves ? _cxT(`${d.hotspots_jamais_releves} appareil(s) hors réseau jamais relevé(s) : installez le collecteur sur le PC.`, `${_cxN(d.hotspots_jamais_releves, `off-network device`, `off-network devices`)} never read: install the collector on the PC.`)
                                        : _cxT(`Chaque appareil hors réseau est relevé par un PC.`, `Each off-network device is read by a PC.`)),
      bouton: [_cxT(`Onglet Hors réseau`, `Off the network tab`), `_cxOnglet('hotspots')`] });
  }
  const utiles = etapes.filter(e => !e.facultatif);
  const faites = utiles.filter(e => e.fait).length;
  const fini = faites === utiles.length;
  const prochaine = etapes.findIndex(e => !e.fait);
  // Pas de classe « card » : l'export global (ui.js) se pose sur la première carte, qui doit rester celle du tableau.
  z.innerHTML = `<section class="cx-parcours${fini ? ' cx-parcours-fini' : ''}" aria-label="${escHtml(_cxT(`Guide de démarrage`, `Getting started guide`))}">
    <div class="cx-parcours-tete">
      <strong>${lk('compass')} ${fini ? _cxT(`Raccordement terminé`, `Connection complete`) : _cxT(`Guide de démarrage`, `Getting started guide`)}</strong>
      <span class="cx-parcours-barre" aria-hidden="true"><span style="width:${Math.round(100 * faites / utiles.length)}%"></span></span>
      <span class="cx-aide">${faites}/${utiles.length}</span>
      <button type="button" class="btn btn-ghost btn-sm" onclick="_cxMasquerParcours(true)">${_cxT(`Masquer`, `Hide`)}</button>
    </div>
    ${fini ? `<p class="cx-aide" style="margin:6px 0 0">${_cxT(`Tout est en place. Les étapes facultatives restent ci-dessous ; le guide se rouvre depuis le bouton ${lk('compass')} Guide.`, `Everything is in place. Optional steps stay below; the guide reopens from the ${lk('compass')} Guide button.`)}</p>` : ''}
    <ol class="cx-parcours-etapes">${etapes.map((e, i) => `
      <li class="${e.fait ? 'fait' : (i === prochaine ? 'prochaine' : '')}">
        <span class="cx-parcours-coche" aria-hidden="true">${e.fait ? '✓' : i + 1}</span>
        <div class="cx-parcours-corps"><div><strong>${escHtml(e.titre)}</strong>${e.facultatif ? ` <span class="badge badge-gray">${_cxT(`facultatif`, `optional`)}</span>` : ''}${e.detail ? ` <span class="cx-aide">· ${escHtml(e.detail)}</span>` : ''}
          <span class="cx-sr">${e.fait ? _cxT(`(fait)`, `(done)`) : _cxT(`(à faire)`, `(to do)`)}</span></div>
          <div class="cx-aide">${escHtml(e.texte)}</div>
          ${!e.fait ? `<button type="button" class="btn ${i === prochaine ? 'btn-primary' : 'btn-ghost'} btn-sm cx-parcours-btn" onclick="${e.bouton[1]}">${escHtml(e.bouton[0])}</button>` : ''}</div>
      </li>`).join('')}</ol>
  </section>`;
}

function _cxMasquerParcours(masquer) {
  try { localStorage.setItem('cx_parcours_masque', masquer ? '1' : '0'); } catch (_) { /* navigation privée */ }
  if (App.currentPage === 'connect_config') renderConnectConfig();
  else _cxParcours();
}

// ══ Appareils en hotspot Wi-Fi : PC releveurs ══════════════════════════════
//
// Un appareil qu'on ne joint que par son propre hotspot Wi-Fi est relevé hors
// ligne par un PC : il s'y connecte, lit, revient sur son réseau, envoie les
// valeurs (api/connect/Releveur.php, api/connect/releveur/).

async function _cxOngletHotspots(z) {
  const r = await apiRequest('connect_releveurs') || {};
  const hotspots = CxUI.hotspots = r.hotspots || [];
  const releveurs = CxUI.releveurs = r.releveurs || [];
  const vCollecteur = String(r.version_collecteur || '');
  const nomDe = id => (hotspots.find(h => +h.Id === +id) || {}).Nom || ('#' + id);
  const enClair = location.protocol === 'http:' && !/^(localhost|127\.|\[::1\])/.test(location.hostname);
  let h = enClair ? `<div class="cx-bandeau" role="alert" style="margin-bottom:12px">${lk('triangle-exclamation-point', 'warn')} ${_cxT(`Larka est ouvert en <strong>http</strong> : le jeton du PC releveur, les clés Wi-Fi et les valeurs voyageraient en clair sur le réseau. Servez Larka en <strong>https</strong> avant d'installer un collecteur (le collecteur vérifie le certificat).`,
                                                                                                        `Larka is open over <strong>http</strong>: the reader PC's token, the Wi-Fi keys and the values would travel in clear over the network. Serve Larka over <strong>https</strong> before installing a collector (the collector checks the certificate).`)}</div>` : '';
  h += `<div class="cx-etapes-hotspot">
      <div><span class="cx-parcours-coche">1</span><div><strong>${_cxT(`Déclarer l'appareil`, `Declare the device`)}</strong>
        <div class="cx-aide">${_cxT(`« ＋ Déclarer un appareil hors réseau » ci-dessous : son Wi-Fi (hotspot) ou le câble direct, son adresse, puis ses points.`, `“＋ Declare an off-network device” below: its Wi-Fi (hotspot) or the direct cable, its address, then its points.`)}</div></div></div>
      <div><span class="cx-parcours-coche">2</span><div><strong>${_cxT(`Ajouter le PC qui relève`, `Add the reading PC`)}</strong>
        <div class="cx-aide">${_cxT(`Un PC qui passe à portée du hotspot, ou qu'on branche sur l'appareil (même le portable d'un agent), puis ${lk('arrow-down')} son collecteur, déjà réglé.`, `A PC that comes within reach of the hotspot, or that is plugged into the device (even an agent's laptop), then ${lk('arrow-down')} its collector, already set up.`)}</div></div></div>
      <div><span class="cx-parcours-coche">3</span><div><strong>${_cxT(`Sur ce PC, une seule fois`, `On that PC, once`)}</strong>
        <div class="cx-aide">${_cxT(`Décompresser, puis double-cliquer releveur.cmd à chaque relevé. Rien ne se lance tout seul.`, `Unzip, then double-click releveur.cmd for each reading. Nothing starts by itself.`)}</div></div></div>
    </div>`;
  const peutGerer = canEdit();
  h += `<div class="card" style="margin-top:12px"><div class="card-header"><div class="card-title">${lk('phone-off')} ${_cxT(`Appareils hors réseau`, `Off-network devices`)} (${hotspots.length})</div>
      ${peutGerer ? `<button class="btn btn-primary btn-sm" onclick="_cxNouveauHorsReseau()">＋ ${_cxT(`Déclarer un appareil hors réseau`, `Declare an off-network device`)}</button>` : ''}</div>
    ${hotspots.length ? `<div class="table-wrap"><table><thead><tr><th>${_cxT(`Appareil`, `Device`)}</th><th>${_cxT(`Accès`, `Access`)}</th><th>${_cxT(`Adresse de l'appareil`, `Device address`)}</th><th>Points</th><th>${_cxT(`Dernier relevé`, `Last reading`)}</th><th></th></tr></thead><tbody>
      ${hotspots.map(x => { const et = _cxEtatConnexion(x); return `<tr class="${+x.Actif ? '' : 'cx-inactif'}">
        <td><div>${CX_PROTO_ICONE[x.Protocole] || '•'} ${escHtml(x.Nom)}</div>${x.BatimentNom ? `<div class="cx-aide">${escHtml(x.BatimentNom)}</div>` : ''}</td>
        <td>${x.Acces === 'direct' ? `${lk('link')} ${_cxT(`câble direct`, `direct cable`)}` : `${lk('wifi')} <code class="cx-code">${escHtml(x.Ssid || '—')}</code>`}</td>
        <td>${x.Protocole === 'auto' ? `<span class="badge badge-gray">${lk('search')} ${_cxT(`protocole à détecter`, `protocol to detect`)}</span>` : `<code class="cx-code">${escHtml(x.Protocole === 'rest' ? (x.UrlBase || '—') : (x.Hote || '—') + (x.Port ? ':' + x.Port : ''))}</code>`}
          ${x.ReconEtat === 'attente' ? `<div class="cx-aide">${lk('search')} ${_cxT(`détection au prochain relevé du PC`, `detection at the PC's next reading`)}</div>` : ''}
          ${x.ReconEtat === 'pret' ? `<div><a href="#" onclick="event.preventDefault();_cxReconnaissance(${x.Id})">${lk('search')} ${_cxT(`ce que le PC a trouvé`, `what the PC found`)}</a></div>` : ''}</td>
        <td><a href="#" onclick="event.preventDefault();CxUI.filtrePa='${x.Id}';_cxOnglet('points')">${+x.NbPoints || 0}</a></td>
        <td>${x.DerniereCollecte ? _cxDepuis(x.DerniereCollecte) : `<span class="cx-aide">${_cxT(`jamais`, `never`)}</span>`} <span class="badge badge-${et.classe}">${et.libelle}</span>
          ${x.DerniereErreur && x.Etat !== 'ok' ? `<div class="cx-aide cx-erreur-texte">${escHtml(x.DerniereErreur)}</div>` : ''}</td>
        <td><div class="actions-col"><button class="icon-btn" aria-label="${escHtml(_cxT(`Détecter le protocole et les points`, `Detect the protocol and the points`))}" title="${escHtml(_cxT(`Détecter le protocole et les points (par le PC releveur)`, `Detect the protocol and the points (through the reader PC)`))}" onclick="_cxReconnaissance(${x.Id})">${lk('search')}</button>
          <button class="icon-btn" aria-label="Modifier" data-cx-info="action.modifier" onclick="_cxFormConnexion(${x.Id})">${lk('pencil')}</button></div></td></tr>`; }).join('')}
      </tbody></table></div>`
    : `<div class="no-results">${_cxT(`Aucun appareil hors réseau. « ＋ Déclarer un appareil hors réseau » : un appareil qui diffuse son propre Wi-Fi, ou sur lequel on branche un PC par câble. Une connexion existante passe aussi hors réseau : ${lk('pencil')} dans l'onglet Connexions, puis « Comment Larka joint-il l'appareil ? ».`,
                                                                 `No off-network device. “＋ Declare an off-network device”: a device that broadcasts its own Wi-Fi, or that a PC is plugged into by cable. An existing connection can also go off-network: ${lk('pencil')} in the Connections tab, then “How does Larka reach the device?”.`)}</div>`}
  </div>`;
  h += `<div class="card" style="margin-top:12px"><div class="card-header"><div class="card-title">${lk('laptop')} ${_cxT(`PC releveurs`, `Reader PCs`)} (${releveurs.length})</div>
      <button class="btn btn-primary btn-sm" onclick="_cxFormReleveur(0)">＋ ${_cxT(`Ajouter un PC releveur`, `Add a reader PC`)}</button></div>
    ${releveurs.length ? `<div class="table-wrap"><table><thead><tr><th>PC</th><th>${_cxT(`Appareils confiés`, `Assigned devices`)}</th><th>${_cxT(`Dernier contact`, `Last contact`)}</th><th>${_cxT(`Actions`, `Actions`)}</th></tr></thead><tbody>
      ${releveurs.map(x => { const ids = String(x.Connexions || '').split(',').filter(Boolean); const journal = Array.isArray(x.Journal) ? x.Journal : []; return `<tr class="${+x.Actif ? '' : 'cx-inactif'}">
        <td><strong>${escHtml(x.Nom)}</strong>${+x.Actif ? '' : ` <span class="badge badge-gray">${_cxT(`désactivé`, `disabled`)}</span>`}</td>
        <td>${ids.length ? ids.map(i => escHtml(nomDe(i))).join(', ') : `<span class="cx-aide">${_cxT(`tous les appareils hors réseau`, `all off-network devices`)}</span>`}</td>
        <td>${x.DernierContact ? _cxDepuis(x.DernierContact) : `<span class="cx-aide">${_cxT(`jamais : collecteur pas encore installé`, `never: collector not installed yet`)}</span>`}
          ${x.DernierContact ? `<div class="cx-aide">${_cxT(`collecteur`, `collector`)} ${escHtml(x.Version || _cxT(`1.3 ou avant`, `1.3 or earlier`))}${vCollecteur && (!x.Version || _cxVersionAvant(x.Version, vCollecteur)) ? ` — <a href="#" onclick="event.preventDefault();_cxPaquetReleveur(${x.Id})">${lk('arrow-down')} ${_cxT(`${vCollecteur} disponible`, `${vCollecteur} available`)}</a>` : ''}</div>` : ''}
          ${x.DerniereErreur ? `<div class="cx-aide cx-erreur-texte">${escHtml(x.DerniereErreur)}</div>` : ''}
          ${_cxMaterielPc(x.Materiel)}
          ${journal.length ? `<details class="cx-avance"><summary>${_cxT(`Journal du PC`, `PC log`)}</summary><pre class="cx-journal-pc">${escHtml(journal.slice(-15).join('\n'))}</pre></details>` : ''}</td>
        <td><div class="actions-col cx-actions">
          <button class="icon-btn" aria-label="${escHtml(_cxT(`Télécharger le collecteur`, `Download the collector`))}" data-cx-info="action.paquet" onclick="_cxPaquetReleveur(${x.Id})">${lk('arrow-down')}</button>
          <button class="icon-btn" aria-label="Modifier" data-cx-info="action.releveur.modifier" onclick="_cxFormReleveur(${x.Id})">${lk('pencil')}</button>
          <button class="icon-btn delete" aria-label="Supprimer" data-cx-info="action.releveur.supprimer" onclick="_cxSupprimerReleveur(${x.Id})">${lk('trash')}</button>
        </div></td></tr>`; }).join('')}
      </tbody></table></div>`
    : `<div class="no-results">${_cxT(`Aucun PC releveur. Ajoutez celui qui passera à portée des hotspots : son collecteur se télécharge déjà réglé.`, `No reader PC. Add the one that will be within reach of the hotspots: its collector downloads already set up.`)}</div>`}
    <div class="cx-aide" style="padding:8px 16px 14px">${_cxT(`Hotspot : pendant un relevé (une minute environ), un PC à une seule carte Wi-Fi quitte son réseau habituel, puis le reprend ; avec une seconde carte Wi-Fi ou en Ethernet, il relève sur la carte libre sans rien couper. Câble direct : brancher le câble, puis double-cliquer releveur.cmd ; les valeurs attendent sur le PC et partent dès qu'il retrouve le réseau.`,
                                                                 `Hotspot: during a reading (about a minute), a PC with a single Wi-Fi card leaves its usual network, then takes it back; with a second Wi-Fi card or on Ethernet, it reads on the free card without cutting anything. Direct cable: plug the cable in, then double-click releveur.cmd; values wait on the PC and leave as soon as it is back on the network.`)}</div>
  </div>`;
  z.innerHTML = h;
}

function _cxFormReleveur(id) {
  const x = (CxUI.releveurs || []).find(r => +r.Id === +id) || {};
  const ids = String(x.Connexions || '').split(',').filter(Boolean);
  const hs = CxUI.hotspots || [];
  const corps = `<div class="cx-form">
    <div class="form-group"><label class="form-label" for="cxrlNom">${_cxT(`Nom du PC`, `PC name`)} <span class="cx-requis" aria-hidden="true">*</span></label>
      <input id="cxrlNom" class="form-control" maxlength="100" value="${escHtml(x.Nom || '')}" placeholder="${escHtml(_cxT(`ex. Portable de l'agent technique`, `e.g. Technician's laptop`))}"></div>
    <div class="form-group" style="margin-top:14px"><span class="form-label">${_cxT(`Appareils qu'il relève`, `Devices it reads`)} ${_cxBulle('rl.appareils')}</span>
      ${hs.length ? hs.map(h => `<label class="cx-case"><input type="checkbox" class="cxrl-cx" value="${h.Id}" ${ids.includes(String(h.Id)) ? 'checked' : ''}> ${escHtml(h.Nom)} <span class="cx-aide">· ${h.Acces === 'direct' ? (lk('link') + ' ') + _cxT(`câble direct`, `direct cable`) : (lk('wifi') + ' ') + escHtml(h.Ssid || '')}</span></label>`).join('')
                  : `<div class="cx-aide">${_cxT(`Aucun appareil hors réseau pour l'instant : ce PC relèvera ceux que vous déclarerez.`, `No off-network device yet: this PC will read those you declare.`)}</div>`}
      <div class="cx-aide">${_cxT(`Aucun coché : tous les appareils hors réseau, y compris ceux déclarés plus tard.`, `None ticked: all off-network devices, including those declared later.`)}</div></div>
    ${id ? `<label class="cx-case"><input type="checkbox" id="cxrlActif" ${+x.Actif ? 'checked' : ''}> ${_cxT(`PC releveur actif (désactivé, Larka refuse ses relevés)`, `Active reader PC (disabled, Larka refuses its readings)`)}</label>` : ''}
  </div>`;
  openModal(id ? _cxT(`PC releveur — `, `Reader PC — `) + x.Nom : _cxT(`Nouveau PC releveur`, `New reader PC`), corps, async () => {
    const o = { id, nom: gv('cxrlNom').trim(), connexions: [...document.querySelectorAll('.cxrl-cx:checked')].map(c => +c.value) };
    if (id) o.actif = !!(document.getElementById('cxrlActif') || {}).checked;
    if (!o.nom) { toast(_cxT(`Donnez un nom au PC.`, `Name the PC.`), 'error'); return; }
    let r;
    try { r = await _cxApi('connect_releveur_enregistrer', 'POST', o); } catch (err) { toast(err.message, 'error'); return; }
    closeModal();
    await _cxOnglet('hotspots');
    _cxParcours();
    if (!id) showConfirm(_cxT(`PC releveur créé. Télécharger maintenant son collecteur (déjà réglé pour lui) ?`, `Reader PC created. Download its collector now (already set up for it)?`),
                         () => _cxPaquetReleveur(r.id, true), _cxT(`Télécharger`, `Download`));
  }, id ? 'Enregistrer' : _cxT(`Créer`, `Create`), 'Annuler', '600px');
}

/** « ＋ Déclarer un appareil hors réseau » : comment on le joint, puis comment il communique. */
async function _cxNouveauHorsReseau() {
  try { await _cxEtat(); } catch (err) { toast(err.message, 'error'); return; }
  const proto = p => { const d = CxUI.catalogue[p] || {}; return `<button type="button" class="cx-proto-choix" onclick="_cxFormConnexion(0, '${p}', { acces: (document.querySelector('input[name=cxhrAcces]:checked') || {}).value || 'hotspot' })">
      <span class="cx-proto-choix-icone" aria-hidden="true">${CX_PROTO_ICONE[p] || '•'}</span><b>${escHtml(d.libelle || p)}</b><span>${escHtml(d.resume || '')}</span></button>`; };
  openModal(_cxT(`Déclarer un appareil hors réseau`, `Declare an off-network device`),
    `<div class="cx-form-section" style="margin-top:0">${_cxT(`1. Comment le joindre ?`, `1. How to reach it?`)}</div>
     <div class="cx-acces">
       <label class="cx-acces-choix actif"><input type="radio" name="cxhrAcces" value="hotspot" checked onchange="this.closest('.cx-acces').querySelectorAll('.cx-acces-choix').forEach(l=>l.classList.toggle('actif',l.contains(this)))">
         <span><strong>${lk('wifi')} ${_cxT(`Il diffuse son propre Wi-Fi (hotspot)`, `It broadcasts its own Wi-Fi (hotspot)`)}</strong><span class="cx-aide">${_cxT(`Au double-clic sur le collecteur, le PC s'y connecte, relève, puis revient sur son réseau.`, `The PC connects to it by itself at the chosen times, then goes back to its network.`)}</span></span></label>
       <label class="cx-acces-choix"><input type="radio" name="cxhrAcces" value="direct" onchange="this.closest('.cx-acces').querySelectorAll('.cx-acces-choix').forEach(l=>l.classList.toggle('actif',l.contains(this)))">
         <span><strong>${lk('link')} ${_cxT(`On branche un PC dessus, par câble (RJ45)`, `A PC is plugged into it by cable (RJ45)`)}</strong><span class="cx-aide">${_cxT(`Hors réseau : le collecteur relève quand l'appareil répond, garde les valeurs et les envoie quand le PC retrouve le réseau.`, `Off the network: the collector reads when the device answers, keeps the values and sends them when the PC is back on the network.`)}</span></span></label>
     </div>
     <div class="cx-form-section">${_cxT(`2. Comment communique-t-il ? (sa notice l'indique)`, `2. How does it communicate? (its manual says)`)}</div>
     <div class="cx-protos" style="margin-top:6px">${CX_PROTOS_HOTSPOT.map(proto).join('')}</div>`,
    null, 'Enregistrer', 'Annuler', '760px');
}

/** Télécharge le collecteur du PC (zip). Un nouveau jeton remplace l'ancien. */
async function _cxPaquetReleveur(id, sansConfirmer) {
  const x = (CxUI.releveurs || []).find(r => +r.Id === +id) || {};
  const lancer = async () => {
    const base = location.origin + location.pathname.replace(/[^/]*$/, '');
    const demander = () => fetch(`${API_BASE}?action=connect_releveur_paquet`, { method: 'POST', credentials: 'same-origin', cache: 'no-store',
        headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id, url: base.replace(/\/$/, '') }) });
    let res;
    try {
      res = await demander();
      // Le collecteur contient des clés Wi-Fi et des identifiants : identité confirmée d'abord.
      if (res.status === 428) {
        const ok = await _cxConfirmerIdentite(_cxT(`Le collecteur contient les clés Wi-Fi et les identifiants des appareils qu'il relève : confirmez que c'est bien vous.`,
                                                   `The collector contains the Wi-Fi keys and credentials of the devices it reads: confirm that it is really you.`));
        if (!ok) return;
        res = await demander();
      }
    } catch (err) { toast(err.message, 'error'); return; }
    const type = res.headers.get('Content-Type') || '';
    if (!res.ok || !type.includes('zip')) {
      let msg = `HTTP ${res.status}`;
      try { const j = await res.json(); msg = j.error || msg; } catch (_) { /* réponse non JSON */ }
      toast(msg, 'error');
      return;
    }
    const blob = await res.blob();
    const nom = ((res.headers.get('Content-Disposition') || '').match(/filename="([^"]+)"/) || [])[1] || 'larka-releveur.zip';
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = nom;
    document.body.appendChild(a);
    a.click();
    setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
    toast(_cxT(`Collecteur téléchargé : décompressez-le sur le PC, puis double-cliquez releveur.cmd.`, `Collector downloaded: unzip it on the PC, then double-click releveur.cmd.`), 'success');
  };
  if (sansConfirmer || !x.DernierContact) { lancer(); return; }
  showConfirm(_cxT(`Un nouveau collecteur porte un nouveau jeton : celui déjà installé sur « ${x.Nom} » cessera de fonctionner. Continuer ?`,
                   `A new collector carries a new token: the one already installed on “${x.Nom}” will stop working. Continue?`), lancer, _cxT(`Télécharger`, `Download`));
}

function _cxSupprimerReleveur(id) {
  const x = (CxUI.releveurs || []).find(r => +r.Id === +id) || {};
  showConfirm(_cxT(`Supprimer le PC releveur « ${x.Nom} » ? Son collecteur cessera de fonctionner ; les relevés déjà reçus restent.`,
                   `Delete reader PC “${x.Nom}”? Its collector will stop working; readings already received are kept.`), async () => {
    try { await _cxApi('connect_releveur_supprimer', 'POST', { id }); } catch (err) { toast(err.message, 'error'); return; }
    toast(_cxT(`PC releveur supprimé.`, `Reader PC deleted.`), 'success');
    _cxOnglet('hotspots');
    _cxParcours();
  }, 'Supprimer');
}

// ══ Serveur de supervision séparé (api/SupervisionLien.php) ══════════════════
//
// Réglé par le gestionnaire : ce serveur est-il le serveur principal (la GMAO)
// ou un serveur de supervision posé sur le réseau technique ? Dans quel sens
// passe le lien (pont sortant, ou port ouvert) ? L'appairage se fait par un
// code à usage unique (30 min) créé d'un côté et collé de l'autre ; les deux
// écrans affichent alors la même empreinte. Créer, utiliser un code et délier
// demandent de confirmer son identité.

const CxServeur = { etat: null, role: null, mode: 'pont', vue: 'creer', code: null, identite: null };

async function _cxServeurOuvrir() {
  openModal(_cxT(`Serveur de supervision`, `Supervision server`), `<div id="cxSrv">${lk('hourglass')} Chargement…</div>`, null, null, _cxT(`Fermer`, `Close`), '760px');
  await _cxServeurCharger();
}

async function _cxServeurCharger() {
  try { CxServeur.etat = await apiRequest('supervision_etat'); }
  catch (err) { const z = document.getElementById('cxSrv'); if (z) z.innerHTML = errorHtml(err.message); return; }
  try { CxServeur.identite = await apiRequest('connect_identite_duree'); } catch (_) { CxServeur.identite = null; }
  if (!CxServeur.role) CxServeur.role = CxServeur.etat.role || 'principal';
  if (CxServeur.etat.mode && CxServeur.etat.mode !== 'local') CxServeur.mode = CxServeur.etat.mode;
  _cxServeurPeindre();
}

function _cxServeurChoix(nom, valeur, courant, titre, aide, fn) {
  return `<label class="cx-srv-choix${valeur === courant ? ' actif' : ''}"><input type="radio" name="${nom}" value="${valeur}" ${valeur === courant ? 'checked' : ''}
      onchange="${fn}('${valeur}')"><span><strong>${titre}</strong><br><span class="cx-aide">${aide}</span></span></label>`;
}

function _cxServeurPeindre() {
  const z = document.getElementById('cxSrv');
  const e = CxServeur.etat;
  if (!z || !e) return;
  const T = (fr, en) => _cxT(fr, en);
  let h = `<p style="margin-top:0">${T(`La supervision peut vivre sur un <strong>serveur de supervision</strong> posé sur le réseau technique, avec sa propre base de données : lui seul parle aux équipements, la GMAO ne parle qu'à lui. Le lien entre les deux est <strong>chiffré par Larka</strong> (clés épinglées à l'appairage), même sans HTTPS.`,
    `Supervision can live on a <strong>supervision server</strong> on the technical network, with its own database: only it talks to devices, the CMMS only talks to it. The link is <strong>encrypted by Larka</strong> (keys pinned at pairing), even without HTTPS.`)}</p>`;
  if (!e.sodium) h += `<div class="cx-bandeau">${lk('triangle-exclamation-point', 'warn')} ${T(`Extension PHP <code>sodium</code> absente sur ce serveur : le lien chiffré est impossible (paquet php-sodium).`, `PHP <code>sodium</code> extension missing.`)}</div>`;
  if (e.appaire) {
    const role = e.role === 'supervision' ? T(`serveur de supervision`, `supervision server`) : T(`serveur principal`, `main server`);
    const autre = e.role === 'supervision' ? T(`serveur principal`, `main server`) : T(`serveur de supervision`, `supervision server`);
    const mode = e.mode === 'pont' ? T(`pont sortant (la supervision appelle le principal ; aucun port ouvert vers le réseau technique)`, `outbound bridge`)
                                   : T(`port ouvert (le principal appelle la supervision)`, `open port`);
    const ok = e.etat === 'ok' && e.dernier_contact;
    h += `<div class="cx-srv-etat ${ok ? 'ok' : 'ko'}">
      <div><span class="cx-aide">${T(`Ce serveur`, `This server`)}</span><br><strong>${escHtml(role)}</strong></div>
      <div><span class="cx-aide">${escHtml(autre)}</span><br><strong>${escHtml(e.url || '—')}</strong></div>
      <div><span class="cx-aide">${T(`Sens du lien`, `Direction`)}</span><br>${escHtml(mode)}</div>
      <div><span class="cx-aide">${T(`Dernier échange`, `Last exchange`)}</span><br>${e.dernier_contact ? escHtml(_cxDate(e.dernier_contact)) : T(`jamais`, `never`)}</div>
    </div>
    <div class="cx-srv-empreinte"><span class="cx-aide">${T(`Empreinte du lien — la même doit s'afficher sur l'autre serveur :`, `Link fingerprint — the same must show on the other server:`)}</span><br><code>${escHtml(e.empreinte || '')}</code></div>
    ${e.derniere_erreur ? `<div class="cx-bandeau">${lk('triangle-exclamation-point', 'warn')} ${escHtml(e.derniere_erreur)}</div>` : ''}
    ${e.role === 'supervision' && e.sortants_en_attente ? `<p class="cx-aide">${e.sortants_en_attente} ${T(`écriture(s) (demandes, relevés) en attente d'envoi au serveur principal : elles partiront au prochain échange.`, `pending write(s) to send.`)}</p>` : ''}
    ${e.role === 'supervision' && e.mode === 'pont' ? `<p class="cx-aide">${T(`Le relais du pont se lance tout seul avec la collecte (chaque minute). Pour un service permanent : <code>php api/outils/supervision-pont.php --permanent</code>.`, `The bridge relay starts with the collection.`)}</p>` : ''}
    <div id="cxSrvRes"></div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">
      ${e.role === 'principal' ? `<button class="btn btn-primary btn-sm" onclick="_cxServeurAction('tester')">${lk('activity')} ${T(`Tester le lien`, `Test the link`)}</button>
        <button class="btn btn-ghost btn-sm" onclick="_cxServeurAction('synchroniser')">${lk('refresh-cw')} ${T(`Synchroniser maintenant`, `Sync now`)}</button>` : `<button class="btn btn-ghost btn-sm" onclick="_cxServeurAction('tester')">${lk('activity')} ${T(`État du lien`, `Link status`)}</button>`}
      <button class="btn btn-ghost btn-sm" onclick="_cxServeurAdresse()">${lk('pencil')} ${T(`Changer l'adresse`, `Change address`)}</button>
      <span style="flex:1"></span>
      <button class="btn btn-ghost btn-sm" style="color:var(--red)" onclick="_cxServeurDelier()">${lk('unlink')} ${T(`Délier`, `Unlink`)}</button>
    </div>` + _cxIdentiteBloc();
    z.innerHTML = h;
    return;
  }
  // ── Pas encore appairé ──
  const r = CxServeur.role, m = CxServeur.mode;
  h += `<h4 style="margin:14px 0 6px">1. ${T(`Ce serveur est…`, `This server is…`)}</h4>
    <div class="cx-srv-choix-grille">
      ${_cxServeurChoix('cxSrvRole', 'principal', r, T(`Le serveur principal (la GMAO)`, `The main server (CMMS)`), T(`Celui des utilisateurs. Il n'interrogera plus aucun équipement lui-même.`, `The users' server.`), '_cxServeurRole')}
      ${_cxServeurChoix('cxSrvRole', 'supervision', r, T(`Un serveur de supervision`, `A supervision server`), T(`Sur le réseau technique, avec sa base : il collecte et garde mesures et alarmes.`, `On the technical network, with its database.`), '_cxServeurRole')}
    </div>
    <h4 style="margin:14px 0 6px">2. ${T(`Sens du lien`, `Link direction`)}</h4>
    <div class="cx-srv-choix-grille">
      ${_cxServeurChoix('cxSrvMode', 'pont', m, T(`Pont sortant (recommandé)`, `Outbound bridge (recommended)`), T(`Le serveur de supervision appelle le principal et attend ses demandes. Aucun port à ouvrir vers le réseau technique : seulement une sortie vers le principal.`, `No port to open towards the technical network.`), '_cxServeurMode')}
      ${_cxServeurChoix('cxSrvMode', 'direct', m, T(`Port ouvert`, `Open port`), T(`Le principal appelle le serveur de supervision : un port TCP ouvert vers lui (HTTPS conseillé).`, `The main server calls the supervision server.`), '_cxServeurMode')}
    </div>
    <p class="cx-aide" style="margin:6px 0 0">${T(`Les équipements qui appellent eux-mêmes (bornes OCPP, passerelles LoRaWAN en UDP, capteurs MQTT) s'adressent directement au serveur de supervision : ni pont ni port pour eux côté principal.`, `Devices that call in (OCPP, LoRaWAN, MQTT) talk directly to the supervision server.`)}</p>
    <h4 style="margin:14px 0 6px">3. ${T(`Appairer les deux serveurs`, `Pair both servers`)}</h4>
    <div class="cx-onglets" role="tablist" style="margin-bottom:10px">
      <button type="button" class="cx-onglet${CxServeur.vue === 'creer' ? ' actif' : ''}" onclick="CxServeur.vue='creer';_cxServeurPeindre()">${T(`Créer un code ici`, `Create a code here`)}</button>
      <button type="button" class="cx-onglet${CxServeur.vue === 'coller' ? ' actif' : ''}" onclick="CxServeur.vue='coller';_cxServeurPeindre()">${T(`J'ai le code de l'autre serveur`, `I have the other server's code`)}</button>
    </div>`;
  const champUrl = (id, lib, val, aide) => `<div class="form-group"><label class="form-label">${lib}</label>
      <input class="form-control" id="${id}" value="${escHtml(val || '')}" placeholder="https://serveur:port"><div class="cx-aide">${aide}</div></div>`;
  const champLocale = r === 'supervision' && m === 'pont'
    ? champUrl('cxSrvUrlLocale', T(`Adresse de ce serveur pour son relais (boucle locale)`, `Local address for the relay`), e.url_locale || e.url_locale_suggeree,
               T(`Le relais du pont remet les demandes à ce serveur par cette adresse ; en général http://127.0.0.1:port.`, `Usually http://127.0.0.1:port.`)) : '';
  if (CxServeur.vue === 'creer') {
    h += champUrl('cxSrvUrl', T(`Adresse de CE serveur, vue par l'autre`, `Address of THIS server, as seen by the other`), e.url_suggeree,
                  r === 'principal' && m === 'direct' ? T(`En port ouvert, c'est le principal qui appelle : cette adresse ne sert qu'à l'appairage.`, `Used for pairing only.`)
                                                      : T(`L'autre serveur l'utilisera pour joindre celui-ci.`, `The other server will use it to reach this one.`))
      + champLocale
      + `<button class="btn btn-primary btn-sm" onclick="_cxServeurCreerCode()">${lk('key')} ${T(`Créer le code d'appairage`, `Create pairing code`)}</button>`
      + (CxServeur.code ? `<div class="cx-srv-code"><div class="cx-aide">${T(`À coller sur l'autre serveur (Connexions → Serveur de supervision, ou <code>php api/outils/supervision.php appairer &lt;code&gt;</code>). Valable jusqu'à`, `Paste on the other server. Valid until`)} ${escHtml(CxServeur.code.expire)}, ${T(`une seule fois`, `single use`)}.</div>
          <textarea class="form-control" rows="3" readonly onclick="this.select()" style="font-family:monospace;font-size:12px">${escHtml(CxServeur.code.code)}</textarea>
          <button class="btn btn-ghost btn-sm" onclick="navigator.clipboard&&navigator.clipboard.writeText(${jsArg(CxServeur.code.code)}).then(()=>toast('Code copié','success'))">${lk('copy')} ${T(`Copier`, `Copy`)}</button>
          <button class="btn btn-ghost btn-sm" onclick="_cxServeurCharger()">${lk('refresh-cw')} ${T(`L'autre serveur l'a utilisé`, `The other server used it`)}</button></div>` : '');
  } else {
    h += `<div class="form-group"><label class="form-label">${T(`Code de l'autre serveur (LKS1.…)`, `Other server's code (LKS1.…)`)}</label>
        <textarea class="form-control" id="cxSrvCode" rows="3" style="font-family:monospace;font-size:12px" placeholder="LKS1.…"></textarea></div>`
      + champUrl('cxSrvUrl', T(`Adresse de CE serveur, vue par l'autre (si c'est lui qui appelle)`, `Address of THIS server (if the other calls it)`), r === 'supervision' && m === 'direct' ? e.url_suggeree : '',
                 T(`Utile au serveur de supervision en port ouvert : le principal l'appellera à cette adresse. Laissez vide sinon.`, `Leave empty otherwise.`))
      + champLocale
      + `<button class="btn btn-primary btn-sm" onclick="_cxServeurUtiliserCode()">${lk('link')} ${T(`Appairer`, `Pair`)}</button>`;
  }
  h += `<div id="cxSrvRes" style="margin-top:10px"></div>` + _cxIdentiteBloc();
  z.innerHTML = h;
}

/**
 * Durée de validité de la confirmation d'identité (« retaper son mot de passe
 * tous les X ») : réglée par le gestionnaire, sous confirmation. Sur un serveur
 * de supervision, elle plafonne aussi la confirmation transmise par le principal.
 */
function _cxIdentiteBloc() {
  const d = CxServeur.identite;
  if (!d) return '';
  const T = (fr, en) => _cxT(fr, en);
  const sup = CxServeur.etat && CxServeur.etat.appaire && CxServeur.etat.role === 'supervision';
  const opts = (d.durees || []).map(m => `<option value="${+m}"${+m === +d.minutes ? ' selected' : ''}>${_cxDureeIdentite(m)}</option>`).join('');
  return `<div class="cx-srv-identite">
    <h4 style="margin:18px 0 6px">${lk('lock-keyhole')} ${T(`Confirmation d'identité`, `Identity confirmation`)}</h4>
    <p class="cx-aide" style="margin:0 0 8px">${T(`Agir sur un équipement, télécharger un collecteur, appairer ou délier : Larka redemande le mot de passe (Larka, Microsoft ou Google selon le compte) au-delà de cette durée.`,
      `Acting on a device, downloading a collector, pairing or unlinking: Larka asks for the password again after this time.`)}
      ${sup ? T(` Sur ce serveur de supervision, c'est aussi la durée maximale acceptée pour une confirmation faite sur le serveur principal.`, ` On this supervision server, it also caps a confirmation made on the main server.`) : ''}</p>
    <label class="form-label" for="cxIdDuree">${T(`Confirmation valable pendant`, `Confirmation valid for`)}</label>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <select id="cxIdDuree" class="form-control" style="width:auto">${opts}</select>
      <button class="btn btn-ghost btn-sm" onclick="_cxIdentiteRegler()">${lk('check')} ${T(`Enregistrer`, `Save`)}</button>
      <span class="cx-aide" id="cxIdDureeRes">${d.restant > 0 ? T(`Votre confirmation vaut encore ${Math.ceil(d.restant / 60)} min.`, `Your confirmation is valid for ${Math.ceil(d.restant / 60)} more min.`) : ''}</span>
    </div>
  </div>`;
}

async function _cxIdentiteRegler() {
  const sel = document.getElementById('cxIdDuree');
  if (!sel) return;
  const minutes = +sel.value;
  try {
    const r = await _cxAvecIdentite(() => apiRequest('connect_identite_duree', 'POST', { minutes }));
    if (!r) return;
    CxServeur.identite = r;
    if (CxUI.etat) CxUI.etat.identite_minutes = r.minutes;
    toast(_cxT(`Confirmation d'identité valable ${_cxDureeIdentite(r.minutes)}`, `Identity confirmation valid for ${_cxDureeIdentite(r.minutes)}`), 'success');
    _cxServeurPeindre();
  } catch (err) { toast(err.message, 'error'); }
}

function _cxServeurRole(v) { CxServeur.role = v; CxServeur.code = null; _cxServeurPeindre(); }
function _cxServeurMode(v) { CxServeur.mode = v; CxServeur.code = null; _cxServeurPeindre(); }
function _cxServeurVal(id) { const el = document.getElementById(id); return el ? el.value.trim() : ''; }
function _cxServeurRes(html, ok) {
  const z = document.getElementById('cxSrvRes');
  if (z) z.innerHTML = `<div class="cx-bandeau" style="${ok ? 'border-color:var(--teal);background:var(--teal-pale,#e6f7f4)' : ''}">${html}</div>`;
}

async function _cxServeurCreerCode() {
  try {
    const r = await _cxAvecIdentite(() => apiRequest('supervision_code', 'POST', { role: CxServeur.role, mode: CxServeur.mode,
      url: _cxServeurVal('cxSrvUrl'), url_locale: _cxServeurVal('cxSrvUrlLocale') }));
    if (!r) return;
    CxServeur.code = r;
    _cxServeurPeindre();
  } catch (err) { _cxServeurRes(escHtml(err.message), false); }
}

async function _cxServeurUtiliserCode() {
  try {
    const r = await _cxAvecIdentite(() => apiRequest('supervision_utiliser_code', 'POST', { code: _cxServeurVal('cxSrvCode'),
      url: _cxServeurVal('cxSrvUrl'), url_locale: _cxServeurVal('cxSrvUrlLocale') }));
    if (!r) return;
    toast(_cxT(`Serveurs appairés`, `Servers paired`), 'success');
    await _cxServeurCharger();
    _cxServeurRes(`${lk('circle-check', 'ok')} ${_cxT(`Appairé. Vérifiez que l'empreinte <code>${escHtml(r.empreinte)}</code> est la même sur l'autre serveur.`, `Paired. Check the fingerprint on the other server.`)}`, true);
  } catch (err) { _cxServeurRes(escHtml(err.message), false); }
}

async function _cxServeurAction(quoi) {
  _cxServeurRes(`${lk('hourglass')} …`, true);
  try {
    const r = await apiRequest('supervision_' + quoi, 'POST', {});
    if (quoi === 'tester') _cxServeurRes(escHtml(r.message || ''), !!r.ok);
    else _cxServeurRes(`${lk('circle-check', 'ok')} ${r.sortants} ${_cxT(`écriture(s) reçue(s)`, `write(s) received`)}${r.referentiel ? _cxT(`, référentiel envoyé`, `, reference data sent`) : ''}.`, true);
  } catch (err) { _cxServeurRes(escHtml(err.message), false); }
}

async function _cxServeurAdresse() {
  const e = CxServeur.etat;
  const url = prompt(_cxT(`Nouvelle adresse de l'autre serveur (les clés ne changent pas) :`, `New address of the other server:`), e.url || '');
  if (url === null) return;
  try { CxServeur.etat = Object.assign(CxServeur.etat, await apiRequest('supervision_url', 'POST', { url })); _cxServeurPeindre(); }
  catch (err) { _cxServeurRes(escHtml(err.message), false); }
}

async function _cxServeurDelier() {
  const e = CxServeur.etat;
  const msg = e.role === 'principal'
    ? _cxT(`Délier ? La supervision reviendra sur CE serveur (ses propres connexions, souvent vides) ; le serveur de supervision garde ses données.`, `Unlink?`)
    : _cxT(`Délier ? Le serveur principal ne joindra plus ce serveur.`, `Unlink?`);
  if (!confirm(msg)) return;
  try {
    const r = await _cxAvecIdentite(() => apiRequest('supervision_delier', 'POST', {}));
    if (!r) return;
    CxServeur.etat = Object.assign(CxServeur.etat, r); CxServeur.code = null;
    toast(_cxT(`Lien délié`, `Unlinked`), 'success');
    _cxServeurPeindre();
  } catch (err) { _cxServeurRes(escHtml(err.message), false); }
}

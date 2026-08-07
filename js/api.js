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
 * Larka — Client API
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Centralise TOUTES les communications avec le backend PHP (api/index.php).
 *
 * CONTENU :
 *   - apiRequest()    → fonction générique fetch + gestion erreurs/timeout/401
 *   - BiensApi        → CRUD biens (GET/POST/PUT/DELETE)
 *   - EquipementsApi  → CRUD équipements
 *   - ContratsApi     → CRUD contrats
 *   - InterventionsApi→ CRUD interventions
 *   - StockApi        → CRUD stock
 *   - DemandesApi     → CRUD demandes d'intervention
 *   - GestionMaterielApi → CRUD fiches matériel
 *   - DocumentsApi    → upload/download/suppression de fichiers joints
 *   - ListesApi       → gestion des listes déroulantes configurables
 *   - ConfigApi       → lecture/écriture de la table Configuration
 *   - ConfigServeurApi→ lecture/écriture de config.json (paramètres serveur)
 *   - NotesInfoApi    → banderole d'information pour les demandeurs
 *   - AssistantApi    → assistant IA (recherche en langage naturel)
 *   - ArchivesApi     → boîtes, dossiers, bordereaux d'archives
 *
 * CONFIGURATION :
 *   - API_BASE        → chemin vers api/index.php
 *   - API_TIMEOUT_MS  → timeout global des requêtes (20s)
 * ═══════════════════════════════════════════════════════════════════════════════
 */

const API_BASE = './api/index.php';
const API_TIMEOUT_MS = 20000;

// ── Déduplication des GET concurrents ────────────────────────────────────────
// OPTI : si plusieurs appelants demandent EXACTEMENT le même GET pendant qu'une
// requête est déjà en vol (cas fréquent au boot : la sidebar et la page lisent
// la même clé de config en parallèle), on partage la même promesse au lieu
// d'ouvrir 2 requêtes réseau identiques. L'entrée est retirée dès que la
// promesse est réglée — donc aucune mise en cache des résultats (pas de risque
// de données périmées), uniquement une coalescence des appels simultanés.
const _inflightGets = new Map();

async function apiRequest(action, method = 'GET', body = null, id = null) {
  // Coalescence uniquement pour les GET sans corps (lectures idempotentes).
  if (method === 'GET' && !body) {
    const key = `${action}::${id ?? ''}`;
    const existing = _inflightGets.get(key);
    if (existing) return existing;
    const p = _apiRequestRaw(action, method, body, id);
    _inflightGets.set(key, p);
    // finally : on libère l'entrée que la requête ait réussi ou échoué.
    p.finally(() => { _inflightGets.delete(key); });
    return p;
  }
  return _apiRequestRaw(action, method, body, id);
}

async function _apiRequestRaw(action, method = 'GET', body = null, id = null) {
  let url = `${API_BASE}?action=${action}`;
  if (id !== null) url += `&id=${id}`;

  const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
  const timeoutId = controller ? setTimeout(() => controller.abort(), API_TIMEOUT_MS) : null;

  const opts = {
    method,
    headers: { 'Content-Type': 'application/json' },
    credentials: 'same-origin',
    cache: 'no-store',
  };
  if (controller) opts.signal = controller.signal;
  if (body) opts.body = JSON.stringify(body);

  let res;
  let text = '';
  let json = null;

  try {
    res = await fetch(url, opts);
    text = await res.text();
  } catch (err) {
    if (timeoutId) clearTimeout(timeoutId);
    if (err?.name === 'AbortError') {
      throw new Error(`Délai dépassé (${Math.round(API_TIMEOUT_MS / 1000)} s).`);
    }
    throw new Error('Impossible de joindre le serveur.');
  } finally {
    if (timeoutId) clearTimeout(timeoutId);
  }

  try {
    json = text ? JSON.parse(text) : null;
  } catch (_) {
    json = null;
  }

  if (!json || typeof json !== 'object') {
    const extrait = (text || '').trim().slice(0, 220);
    throw new Error(extrait ? `Réponse serveur invalide : ${extrait}` : 'Réponse serveur vide ou invalide.');
  }

  if (!json.success) {
    if (res.status === 401) {
      // Ne déclencher forceLogout que si l'utilisateur était connecté
      // (évite une boucle de reload infinie sur la page de login)
      if (window.App?.currentUser) {
        window.App.forceLogout();
      }
      throw new Error('Session expirée. Veuillez vous reconnecter.');
    }
    const err = new Error(json.error || `Erreur HTTP ${res.status}` || 'Erreur inconnue');
    if (json.warnings) err._warnings = json.warnings;
    err._status = res.status;
    err._body = text;
    throw err;
  }
  return json.data;
}

// ── Auth ───────────────────────────────────────────────────────────────────────
const Auth = {
  login:  (login, password) => apiRequest('login',  'POST', { login, password }),
  logout: ()                => apiRequest('logout', 'POST'),
  me:     ()                => apiRequest('me'),
};

// ── Dashboard ──────────────────────────────────────────────────────────────────
const DashboardApi = {
  get: () => apiRequest('dashboard'),
  // Interventions agrégées par mois pour le mini-graphe.
  // `opts` : soit un nombre de mois (preset 3/6/12/24…), soit {debut, fin} en 'YYYY-MM'.
  interventionsMensuelles: (opts) => {
    let qs = '';
    if (opts && typeof opts === 'object') {
      if (opts.debut) qs += '&debut=' + encodeURIComponent(opts.debut);
      if (opts.fin)   qs += '&fin='   + encodeURIComponent(opts.fin);
    } else {
      qs = '&mois=' + encodeURIComponent(opts || 6);
    }
    return apiRequest('interventions_mensuelles' + qs);
  },
};

// ── Biens ──────────────────────────────────────────────────────────────────────
const BiensApi = {
  getAll:  ()         => apiRequest('biens'),
  create:  (data)     => apiRequest('biens', 'POST',   data),
  update:  (id, data) => apiRequest('biens', 'PUT',    data, id),
  delete:  (id)       => apiRequest('biens', 'DELETE', null, id),
};

// ── Équipements ────────────────────────────────────────────────────────────────
const EquipementsApi = {
  getAll:  ()         => apiRequest('equipements'),
  create:  (data)     => apiRequest('equipements', 'POST',   data),
  update:  (id, data) => apiRequest('equipements', 'PUT',    data, id),
  delete:  (id)       => apiRequest('equipements', 'DELETE', null, id),
};

// ── Contrats ───────────────────────────────────────────────────────────────────
const ContratsApi = {
  getAll:  ()         => apiRequest('contrats'),
  create:  (data)     => apiRequest('contrats', 'POST',   data),
  update:  (id, data) => apiRequest('contrats', 'PUT',    data, id),
  delete:  (id)       => apiRequest('contrats', 'DELETE', null, id),
};

// ── Interventions ──────────────────────────────────────────────────────────────
const InterventionsApi = {
  getAll:  ()           => apiRequest('interventions'),
  create:  (data)       => apiRequest('interventions', 'POST',   data),
  update:  (id, data)   => apiRequest('interventions', 'PUT',    data, id),
  // delete(id) — sans raison (compat historique).
  // delete(id, raison) — raison libre logguée côté serveur pour traçabilité.
  delete:  (id, raison) => apiRequest('interventions', 'DELETE', raison ? { raison } : null, id),
};

// ── Stock ──────────────────────────────────────────────────────────────────────
const StockApi = {
  getAll:  ()         => apiRequest('stock'),
  create:  (data)     => apiRequest('stock', 'POST',   data),
  update:  (id, data) => apiRequest('stock', 'PUT',    data, id),
  delete:  (id)       => apiRequest('stock', 'DELETE', null, id),
};

// ── Gestion matériel ───────────────────────────────────────────────────────────────
const GestionMaterielApi = {
  getAll:    ()           => apiRequest('gestion_materiel'),
  create:    (data)       => apiRequest('gestion_materiel', 'POST',   data),
  update:    (id, data)   => apiRequest('gestion_materiel', 'PUT',    data, id),
  delete:    (id)         => apiRequest('gestion_materiel', 'DELETE', null, id),
  cloturer:  (id, motif)  => apiRequest('gestion_materiel_cloturer', 'POST', { id, motif }),
};

// ── Utilisateurs ───────────────────────────────────────────────────────────────
const UtilisateursApi = {
  getAll:  ()         => apiRequest('utilisateurs'),
  create:  (data)     => apiRequest('utilisateurs', 'POST',   data),
  update:  (id, data) => apiRequest('utilisateurs', 'PUT',    data, id),
  delete:  (id)       => apiRequest('utilisateurs', 'DELETE', null, id),
};

// ── Historique ─────────────────────────────────────────────────────────────────
const HistoriqueApi = {
  getAll: () => apiRequest('historique'),
};

// ── Listes configurables ───────────────────────────────────────────────────────
const _listesCache = {};
const _listesCacheTTL = 60000; // 60s

const ListesApi = {
  getAll:         ()                           => apiRequest('listes'),
  getByCategorie: (categorie)                  => {
    const now = Date.now();
    const cached = _listesCache[categorie];
    if (cached && (now - cached.ts) < _listesCacheTTL) return Promise.resolve(cached.data);
    return apiRequest(`listes&categorie=${encodeURIComponent(categorie)}`).then(data => {
      _listesCache[categorie] = { data, ts: now };
      return data;
    });
  },
  invalidateCache: ()                          => { Object.keys(_listesCache).forEach(k => delete _listesCache[k]); },
  create:         (categorie, valeur, ordre, obligatoire, saisieLibre) => { ListesApi.invalidateCache(); return apiRequest('listes', 'POST', { categorie, valeur, ordre, obligatoire: obligatoire||0, saisieLibre: saisieLibre||0 }); },
  update:         (id, valeur, ordre, actif, obligatoire, saisieLibre) => { ListesApi.invalidateCache(); return apiRequest('listes', 'PUT', { valeur, ordre, actif, obligatoire: obligatoire||0, saisieLibre: saisieLibre||0 }, id); },
  delete:         (id, force = false)          => { ListesApi.invalidateCache(); return apiRequest('listes', 'DELETE', force ? { force: true } : {}, id); },
  getUsage:       (id)                         => apiRequest(`listes_usage`, 'GET', null, id),
};

// ── Notes info (banderole demandeur) ──────────────────────────────────────────
const NotesInfoApi = {
  getAll:  ()         => apiRequest('notes_info'),
  create:  (message)  => apiRequest('notes_info', 'POST', { message }),
  update:  (id, data) => apiRequest('notes_info', 'PUT', data, id),
  delete:  (id)       => apiRequest('notes_info', 'DELETE', null, id),
};

// ── Stats avancées ─────────────────────────────────────────────────────────────
const StatsApi = {
  get: () => apiRequest('stats'),
};

// ── OAuth Microsoft ────────────────────────────────────────────────────────────
const MicrosoftAuth = {
  getLoginUrl: () => apiRequest('oauth_microsoft_url'),
};

// ── Demandes d'intervention ────────────────────────────────────────────────────
const DemandesApi = {
  getAll:   ()         => apiRequest('demandes'),
  create:   (data)     => apiRequest('demandes', 'POST', data),
  update:   (id, data) => apiRequest('demandes', 'PUT',  data, id),
  relancer: (id)       => apiRequest('demandes', 'PUT',  { action: 'relancer' }, id),
};

// ── Configuration (SuperAdmin) ─────────────────────────────────────────────────
const ConfigApi = {
  getAll: ()             => apiRequest('configuration'),
  set:    (cle, valeur)  => apiRequest('configuration', 'POST', { cle, valeur }),
};

// ── Configuration serveur (config.json) ────────────────────────────────────────
const ConfigServeurApi = {
  get:  ()       => apiRequest('config_serveur'),
  save: (patch)  => apiRequest('config_serveur', 'POST', patch),
};

// ── Chorus Pro ──────────────────────────────────────────────────────────────
const ChorusApi = {
  visibility: ()              => apiRequest('chorus_visibility'),
  status:     ()              => apiRequest('chorus_status'),
  tokenTest:  ()              => apiRequest('chorus_token_test', 'POST'),
  call:       (path, payload) => apiRequest('chorus_call', 'POST', { path, payload }),
};

// ── France / Légifrance ─────────────────────────────────────────────────────
const LegifranceApi = {
  status:  async () => {
    const raw = await apiRequest('france_legifrance_config');
    return {
      service_active: raw.actif ?? false,
      client_configured: raw.configured ?? false,
      environment: (raw.api_base_url || '').includes('sandbox') ? 'sandbox' : 'production',
      host: raw.host || '',
      api_base_url: raw.api_base_url || '',
    };
  },
  search:  async (payload) => { const r = await apiRequest('france_legifrance_search', 'POST', payload); return r?.data ?? r; },
  suggest: async (payload) => { const r = await apiRequest('france_legifrance_suggest', 'POST', payload); return r?.data ?? r; },
  consult: async (endpoint, payload) => { const r = await apiRequest('france_legifrance_call', 'POST', { endpoint, payload }); return r?.data ?? r; },
};

// ── Lignes stock/intervention ───────────────────────────────────────────────────
const IntervLignesApi = {
  getAll:  (intervId)       => apiRequest(`interv_lignes&interv_id=${intervId}`),
  create:  (intervId, data) => apiRequest(`interv_lignes&interv_id=${intervId}`, 'POST', data),
  delete:  (id)             => apiRequest('interv_lignes', 'DELETE', null, id),
};

// ── Lignes devis/intervention ────────────────────────────────────────────────
const IntervDevisApi = {
  getAll:  (intervId)       => apiRequest(`interv_devis&interv_id=${intervId}`),
  create:  (intervId, data) => apiRequest(`interv_devis&interv_id=${intervId}`, 'POST', data),
  update:  (id, data)       => apiRequest('interv_devis', 'PUT', data, id),
  delete:  (id)             => apiRequest('interv_devis', 'DELETE', null, id),
};

// ── Factures (regroupement d'interventions) ──────────────────────────────────
const FacturesApi = {
  getAll:        ()           => apiRequest('factures'),
  create:        (data)       => apiRequest('factures', 'POST',   data),
  update:        (id, data)   => apiRequest('factures', 'PUT',    data, id),
  delete:        (id)         => apiRequest('factures', 'DELETE', null, id),
  autonumero:    ()           => apiRequest('facture_autonumero'),
  interventions: (factureId)  => apiRequest(`facture_interventions&facture_id=${factureId}`),
};

// ── Rattachement facture ↔ intervention (fiche intervention) ─────────────────
const IntervFacturesApi = {
  getAll:  (intervId)       => apiRequest(`interv_factures&interv_id=${intervId}`),
  create:  (intervId, data) => apiRequest(`interv_factures&interv_id=${intervId}`, 'POST', data),
  update:  (id, data)       => apiRequest('interv_factures', 'PUT', data, id),
  delete:  (id)             => apiRequest('interv_factures', 'DELETE', null, id),
  // Lier une facture à une intervention CHOISIE (body: factureId, interventionId, montantLigne)
  link:    (data)           => apiRequest('facture_link', 'POST', data),
};

// ── Documents ───────────────────────────────────────────────────────────────────
const DocumentsApi = {
  getAll:   (type, eid)                     => apiRequest(`documents&type=${type}&eid=${eid}`),
  upload:   (type, eid, nom, mime, cat, b64) => apiRequest(`documents&type=${type}&eid=${eid}`, 'POST', { nom, mime, categorie: cat, data: b64 }),
  delete:   (id)                             => apiRequest('documents', 'DELETE', null, id),
  download: (id, nom) => {
    const a = document.createElement('a');
    a.href = `api/index.php?action=document_download&id=${id}`;
    a.download = nom;
    a.click();
  },
};

// ── Journal applicatif ────────────────────────────────────────────────────────
const JournalApi = {
  liste:  (params = {}) => apiRequest('journal' + Object.entries(params)
            .filter(([, v]) => v !== '' && v !== null && v !== undefined)
            .map(([k, v]) => `&${k}=${encodeURIComponent(v)}`).join('')),
  stats:  (jours = 7)   => apiRequest(`journal_stats&jours=${encodeURIComponent(jours)}`),
  presence: (minutes = 15) => apiRequest(`presence_apercu&minutes=${encodeURIComponent(minutes)}`),
  effectif: ()             => apiRequest('presence_effectif'),
  detail: (requestId)   => apiRequest(`journal_detail&requestId=${encodeURIComponent(requestId)}`),
  purge:  (jours)       => apiRequest('journal_purge', 'POST', { jours }),
  // Signale un changement de page. Volontairement « fire and forget » : la
  // navigation ne doit jamais attendre le journal.
  page:   (page, precedente) => fetch('api/index.php?action=log_page', {
      method: 'POST', credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ page, precedente }),
    }).catch(() => {}),
  // Remontée d'une erreur navigateur. Volontairement en fetch brut : si c'est
  // justement apiRequest() qui a échoué, on ne veut pas boucler dessus.
  client: (payload) => fetch('api/index.php?action=log_client', {
      method: 'POST', credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    }).catch(() => {}),
};

// ── Stats complètes ─────────────────────────────────────────────────────────────
const StatsCompletesApi = {
  get: (filtres = {}) => {
    const qs = Object.entries(filtres).filter(([,v])=>v).map(([k,v])=>`${k}=${encodeURIComponent(v)}`).join('&');
    return apiRequest('stats_completes' + (qs ? '&'+qs : ''));
  },
};

// ── Actions interventions ────────────────────────────────────────────────────
const InterventionActionsApi = {
  // valider(id) — comportement historique : passe l'intervention en "Validée".
  // valider(id, { commentaire, cloturer_demande }) — ajoute un commentaire de
  // clôture côté intervention ET, si cloturer_demande=true, marque la demande
  // liée comme "Traité" avec le même commentaire (visible par le demandeur).
  valider:  (id, payload = null) => apiRequest('valider_intervention',  'POST', payload, id),
  archiver: (id) => apiRequest('archiver_intervention', 'POST', null, id),
  getArchivees: () => apiRequest('interventions_archivees'),
};

// ── Interventions prévues (dashboard) ────────────────────────────────────────
const IntervPrevuesApi = {
  getAll:         ()           => apiRequest('interventions_prevues'),
  modifierDate:   (id, data)   => apiRequest('modifier_date_prevue', 'POST', data, id),
  realiser:       (id)         => apiRequest('realiser_prevue', 'POST', null, id),
};

// ── Migration base de données ───────────────────────────────────────────────
const MigrationApi = {
  test:       (dest)             => apiRequest('db_migrate_test', 'POST', { destination: dest }),
  migrate:    (dest, backupPath) => apiRequest('db_migrate',      'POST', { destination: dest, backup_path: backupPath || null }),
};

// ── Archives ────────────────────────────────────────────────────────────────
const ArchivesApi = {
  // Boîtes
  getAllBoites:    ()         => apiRequest('archives_boites'),
  createBoite:    (data)     => apiRequest('archives_boites', 'POST',   data),
  updateBoite:    (id, data) => apiRequest('archives_boites', 'PUT',    data, id),
  deleteBoite:    (id)       => apiRequest('archives_boites', 'DELETE', null, id),
  // Dossiers
  getAllDossiers:  ()         => apiRequest('archives_dossiers'),
  createDossier:  (data)     => apiRequest('archives_dossiers', 'POST',   data),
  updateDossier:  (id, data) => apiRequest('archives_dossiers', 'PUT',    data, id),
  deleteDossier:  (id)       => apiRequest('archives_dossiers', 'DELETE', null, id),
  // Recherche avancée
  rechercher: (filtres) => {
    const qs = Object.entries(filtres).filter(([,v])=>v!==''&&v!==null&&v!==undefined).map(([k,v])=>`${k}=${encodeURIComponent(v)}`).join('&');
    return apiRequest('archives_recherche' + (qs ? '&'+qs : ''));
  },
  // Bordereaux
  getAllBordereaux:  ()         => apiRequest('archives_bordereaux'),
  createBordereau:  (data)     => apiRequest('archives_bordereaux', 'POST', data),
  deleteBordereau:  (id)       => apiRequest('archives_bordereaux', 'DELETE', null, id),
  downloadBordereau: (id, nom) => {
    const a = document.createElement('a');
    a.href = `api/index.php?action=archives_bordereau_download&id=${id}`;
    a.download = nom || `bordereau_${id}`;
    a.click();
  },
};

// ── Assistant IA ─────────────────────────────────────────────────────────────
const AssistantApi = {
  ask: (question, history = []) => apiRequest('assistant', 'POST', { question, history }),
};

// ── Plans interactifs ────────────────────────────────────────────────────────
const PlansApi = {
  // Bâtiments
  getBatiments:    ()         => apiRequest('plans_batiments'),
  addBatiment:     (data)     => apiRequest('plans_batiments', 'POST', data),
  updateBatiment:  (id, data) => apiRequest('plans_batiments', 'PUT', data, id),
  deleteBatiment:  (id)       => apiRequest('plans_batiments', 'DELETE', null, id),
  // Étages
  getEtages:       (batId)    => apiRequest('plans_etages&batiment_id=' + (batId||''), 'GET'),
  addEtage:        (data)     => apiRequest('plans_etages', 'POST', data),
  updateEtage:     (id, data) => apiRequest('plans_etages', 'PUT', data, id),
  deleteEtage:     (id)       => apiRequest('plans_etages', 'DELETE', null, id),
  // Éléments
  getElements:     (etageId)  => apiRequest('plans_elements&etage_id=' + (etageId||''), 'GET'),
  addElement:      (data)     => apiRequest('plans_elements', 'POST', data),
  updateElement:   (id, data) => apiRequest('plans_elements', 'PUT', data, id),
  deleteElement:   (id)       => apiRequest('plans_elements', 'DELETE', null, id),
  // Liens
  getLiens:        (elemId)   => apiRequest('plans_liens&element_id=' + (elemId||''), 'GET'),
  addLien:         (data)     => apiRequest('plans_liens', 'POST', data),
  deleteLien:      (id)       => apiRequest('plans_liens', 'DELETE', null, id),
  setPersonne:     (data)     => apiRequest('plans_set_personne', 'POST', data), // rattacher/détacher une personne
  // Calques
  renameCalque:    (etageId, oldName, newName) => apiRequest('plans_calques', 'PUT', { etageId, oldName, newName }),
  deleteCalque:    (etageId, name) => apiRequest('plans_calques&etage_id=' + etageId + '&name=' + encodeURIComponent(name), 'DELETE'),
  // Upload fond
  uploadFond: async (etageId, file) => {
    const fd = new FormData();
    fd.append('file', file);
    fd.append('etage_id', etageId);
    const res = await fetch(API_BASE + '?action=plans_upload_fond', { method:'POST', body:fd, credentials:'same-origin' });
    const json = await res.json();
    if (!json.success) throw new Error(json.error || 'Erreur upload');
    return json.data;
  },
  // Import fichiers (DXF, CSV, GeoJSON, DWG)
  importFile: async (etageId, file) => {
    const fd = new FormData();
    fd.append('file', file);
    fd.append('etage_id', etageId);
    const res = await fetch(API_BASE + '?action=plans_import', { method:'POST', body:fd, credentials:'same-origin' });
    const json = await res.json();
    if (!json.success) throw new Error(json.error || 'Erreur import');
    return json.data;
  },
};

// ── Annuaire cartographié (Plans côté Demandeur) ───────────────────
const AnnuaireApi = {
  status:       ()        => apiRequest('annuaire_status'),
  getBatiments: ()        => apiRequest('annuaire_batiments'),
  getEtages:    (batId)   => apiRequest('annuaire_etages&batiment_id=' + (batId || '')),
  getPoints:    (etageId) => apiRequest('annuaire_points&etage_id=' + (etageId || '')),
  getTextes:    (etageId) => apiRequest('annuaire_textes&etage_id=' + (etageId || '')),
  setConsent:   (val)     => apiRequest('annuaire_consent', 'POST', { partage: val ? 1 : 0 }),
  getConsent:   ()        => apiRequest('annuaire_consent'),                       // état opt-out
  getPresence:  (userIds) => apiRequest('annuaire_presence', 'POST', { userIds }), // statut temps réel
  // Créneaux occupés. start/end en ISO UTC (« Y-m-dTH:i:s ») ;
  // omis = 10 h à venir. Le serveur valide et plafonne la fenêtre à 31 j.
  getAgenda:    (userId, start, end) => apiRequest('annuaire_agenda', 'POST', { userId, start, end }),
};

// ⚠️ FIX : exposer explicitement sur window.
// « const AnnuaireApi = {…} » au niveau racine d'un script classique crée une
// liaison dans la portée lexicale globale, mais PAS une propriété de window
// (contrairement à var). js/pages/annuaire.js teste « !window.AnnuaireApi »
// avant d'appeler la présence et l'agenda : la garde était donc TOUJOURS vraie
// et les deux fonctionnalités sortaient sans jamais émettre la moindre requête
// — d'où « Statut indisponible » et l'agenda vide, sans erreur ni log.
window.AnnuaireApi = AnnuaireApi;
// ── Procédures d'urgence : médias (photos / vidéos) ───────────────────────────
// Les fichiers sont stockés hors webroot (data/urgences/) et servis uniquement
// par l'action `urgences_media`, qui applique le contrôle d'accès « Gestionnaires
// uniquement ». On ne construit donc JAMAIS d'URL directe vers data/.
const UrgencesApi = {
  /**
   * Envoie une photo ou une vidéo. Retourne { id, type, fichier, nom, mime, taille }.
   * `onProgress` (optionnel) reçoit un pourcentage 0-100 — utile pour les vidéos
   * lourdes, où un simple fetch() ne donne aucun retour à l'utilisateur.
   */
  uploadMedia: (file, onProgress) => new Promise((resolve, reject) => {
    const fd = new FormData();
    fd.append('file', file);
    const xhr = new XMLHttpRequest();
    xhr.open('POST', API_BASE + '?action=urgences_media_upload', true);
    xhr.withCredentials = true;
    if (typeof onProgress === 'function' && xhr.upload) {
      xhr.upload.onprogress = (e) => {
        if (e.lengthComputable) onProgress(Math.round((e.loaded / e.total) * 100));
      };
    }
    xhr.onload = () => {
      let json = null;
      try { json = JSON.parse(xhr.responseText); } catch(_) {}
      if (!json) return reject(new Error('Réponse serveur illisible (HTTP ' + xhr.status + ').'));
      if (!json.success) return reject(new Error(json.error || 'Erreur upload'));
      resolve(json.data);
    };
    xhr.onerror   = () => reject(new Error('Erreur réseau pendant l\'envoi.'));
    xhr.ontimeout = () => reject(new Error('Délai dépassé pendant l\'envoi.'));
    xhr.send(fd);
  }),

  /** Supprime le fichier physique. Idempotent côté serveur. */
  deleteMedia: (fichier) => apiRequest('urgences_media_delete', 'DELETE', { fichier }),

  /** URL de lecture d'un média (image src / video src). */
  mediaUrl: (fichier) => API_BASE + '?action=urgences_media&f=' + encodeURIComponent(fichier),
};
window.UrgencesApi = UrgencesApi;

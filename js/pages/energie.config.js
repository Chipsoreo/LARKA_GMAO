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
 * Larka — Module Énergie : Configuration
 * Paramètres spécifiques au module énergie.
 */

const EnergieState = {
  onglet: 'Electricite', // Electricite | Eau | Chauffage
};

const ENERGIE_CONFIG = {
  Electricite: { icon: lk('zap'), unite: 'kWh',  couleur: 'orange' },
  Eau:         { icon: lk('droplet'), unite: 'm³',   couleur: 'blue'   },
  Chauffage:   { icon: lk('flame'), unite: 'MWh',  couleur: 'red'    },
  Gaz:         { icon: lk('circle', 'p bleu'), unite: 'm³',   couleur: 'gray'   },
};

// ── Relevés : cache de rendu ─────────────────────────────────────────────────
// L'écran d'un fluide relisait les relevés de chaque compteur DEUX fois (les
// statistiques, puis un bloc par compteur), un compteur après l'autre ; le bilan
// carbone, une troisième. Les relevés lus sont gardés 5 secondes — le temps
// d'un rendu — et toute écriture vers l'API vide le cache (api.js).
const _RELEVES_TTL = 5000;
const _relevesCache = new Map();   // compteurId → { ts, gen, promesse }
let _relevesGen = 0;
if (typeof enregistrerCacheCourt === 'function') {
  enregistrerCacheCourt(() => { _relevesGen++; _relevesCache.clear(); });
}
function _relevesFrais(id) {
  const e = _relevesCache.get(String(id));
  return e && e.gen === _relevesGen && (Date.now() - e.ts) < _RELEVES_TTL ? e : null;
}
// Chaque appelant reçoit ses propres objets : certains écrans les annotent.
function _relevesCopie(l) { return Array.isArray(l) ? l.map(x => (x && typeof x === 'object') ? { ...x } : x) : l; }
function _relevesLire(cId) {
  const e = _relevesFrais(cId);
  if (e) return e.promesse.then(_relevesCopie);
  const gen = _relevesGen;
  const promesse = apiRequest(`energie_releves&compteur_id=${cId}`);
  _relevesCache.set(String(cId), { ts: Date.now(), gen, promesse });
  promesse.catch(() => { _relevesCache.delete(String(cId)); });
  return promesse.then(_relevesCopie);
}
async function _relevesPrecharger(ids) {
  const manquants = [...new Set((ids || []).map(Number).filter(i => i > 0))].filter(i => !_relevesFrais(i));
  if (manquants.length < 2) return;
  const gen = _relevesGen;
  try {
    const parCompteur = await apiRequest(`energie_releves&compteur_ids=${manquants.join(',')}`);
    if (gen !== _relevesGen || !parCompteur || typeof parCompteur !== 'object') return;
    const ts = Date.now();
    manquants.forEach(i => {
      const l = parCompteur[String(i)];
      if (Array.isArray(l)) _relevesCache.set(String(i), { ts, gen, promesse: Promise.resolve(l) });
    });
  } catch (_) { /* repli : lecture compteur par compteur */ }
}

// ── API ──────────────────────────────────────────────────────────────────────
const EnergieApi = {
  getCompteurs:  ()         => apiRequest('energie_compteurs'),
  addCompteur:   (d)        => apiRequest('energie_compteurs', 'POST', d),
  updateCompteur:(id, d)    => apiRequest('energie_compteurs', 'PUT', d, id),
  deleteCompteur:(id)       => apiRequest('energie_compteurs', 'DELETE', null, id),

  getRelevers:   (cId)      => _relevesLire(cId),
  // Lit d'un coup les relevés de plusieurs compteurs et les garde le temps
  // du rendu : les boucles « pour chaque compteur » qui suivent ne font plus
  // d'aller-retour.
  prechargerReleves: (ids)  => _relevesPrecharger(ids),
  addReleve:     (d)        => apiRequest('energie_releves', 'POST', d),
  updateReleve:  (id, d)    => apiRequest('energie_releves', 'PUT', d, id),
  deleteReleve:  (id)       => apiRequest('energie_releves', 'DELETE', null, id),

  // Facteurs carbone — 2 tables séparées (officielle / simplifiée)
  getFacteursCarbone:  (methode) => apiRequest(`energie_facteurs_carbone&methode=${methode || ''}`),
  upsertFacteurCarbone:(d)     => apiRequest('energie_facteurs_carbone', 'POST', d),
  deleteFacteurCarbone:(id, methode) => apiRequest(`energie_facteurs_carbone&methode=${methode || 'officielle'}`, 'DELETE', null, id),
  getFacteurCarbone: (type, annee, methode) =>
    apiRequest(`energie_facteur_carbone&type=${type}&annee=${annee}&methode=${methode || 'officielle'}`),

  // Sync — méthode officielle (ADEME API)
  syncCarboneADEME:      (data) => apiRequest('energie_sync_carbone_ademe', 'POST', data),
  // Sync — méthode simplifiée (Impact CO2 / Datagir, valeurs hardcodées côté serveur)
  syncCarboneSimplifiee: (data) => apiRequest('energie_sync_carbone_simplifiee', 'POST', data),

  // Recalcul CO₂ en masse (accepte methode dans le body)
  recalculCarbone: (methode) => apiRequest('energie_recalcul_carbone', 'POST', { methode: methode || 'officielle' }),

  // Purge tous les facteurs d'une méthode
  purgerFacteurs: (methode) => apiRequest('energie_purger_facteurs', 'POST', { methode: methode || 'officielle' }),

  proxyADEME:        (params)   => apiRequest('energie_proxy_ademe&' + new URLSearchParams(params).toString()),
};

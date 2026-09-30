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
  Electricite: { icon: '⚡', unite: 'kWh',  couleur: 'orange' },
  Eau:         { icon: '💧', unite: 'm³',   couleur: 'blue'   },
  Chauffage:   { icon: '🔥', unite: 'MWh',  couleur: 'red'    },
  Gaz:         { icon: '🔵', unite: 'm³',   couleur: 'gray'   },
};

// ── API ──────────────────────────────────────────────────────────────────────
const EnergieApi = {
  getCompteurs:  ()         => apiRequest('energie_compteurs'),
  addCompteur:   (d)        => apiRequest('energie_compteurs', 'POST', d),
  updateCompteur:(id, d)    => apiRequest('energie_compteurs', 'PUT', d, id),
  deleteCompteur:(id)       => apiRequest('energie_compteurs', 'DELETE', null, id),

  getRelevers:   (cId)      => apiRequest(`energie_releves&compteur_id=${cId}`),
  addReleve:     (d)        => apiRequest('energie_releves', 'POST', d),
  updateReleve:  (id, d)    => apiRequest('energie_releves', 'PUT', d, id),
  deleteReleve:  (id)       => apiRequest('energie_releves', 'DELETE', null, id),

  getStats:      (type)     => apiRequest(`energie_stats&type=${type}`),

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

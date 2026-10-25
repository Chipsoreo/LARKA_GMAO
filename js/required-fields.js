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
 * Larka — Helpers globaux pour les champs obligatoires
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Ces fonctions sont utilisées par tous les formulaires (biens, équipements,
 * interventions, contrats, stock, demandes) pour appliquer dynamiquement
 * l'attribut `required` + l'étoile rouge sur les labels.
 *
 * Avant la refonte lazy-loading, elles étaient définies dans configuration.js
 * (toujours chargé au boot). Comme configuration.js est maintenant chargé à la
 * demande, on a extrait ces helpers ici dans un fichier indépendant qui reste
 * chargé statiquement dans index.html — sinon les pages qui les appellent
 * sans avoir jamais ouvert "Configuration" plantent avec
 * `getRequiredFields is not defined`.
 *
 * Bonus : on cache la réponse de ConfigApi.getAll() pendant 30s pour éviter
 * que chaque ouverture de formulaire ne refasse un round-trip serveur.
 *
 * Dépendances : ConfigApi (défini dans api.js, chargé avant ce fichier).
 * ═══════════════════════════════════════════════════════════════════════════════
 */

(function() {
  'use strict';

  let _cache = null;     // { data: string[]/object, t: timestamp }
  const TTL_MS = 30 * 1000;

  async function _fetchAll() {
    const now = Date.now();
    if (_cache && (now - _cache.t) < TTL_MS) return _cache.data;
    try {
      const config = await ConfigApi.getAll();
      const raw = config.find(r => r.Cle === 'champs_obligatoires')?.Valeur;
      const parsed = raw ? JSON.parse(raw) : {};
      _cache = { data: parsed, t: now };
      return parsed;
    } catch (_) {
      return {};
    }
  }

  /**
   * Récupère la liste des champs obligatoires pour un type d'entité.
   * @param {string} entite - 'biens', 'equipements', 'interventions', etc.
   * @returns {Promise<string[]>} ex: ['Numero', 'Famille', 'Batiment']
   */
  async function getRequiredFields(entite) {
    const all = await _fetchAll();
    return all[entite] || [];
  }

  /**
   * Génère un label + étoile rouge si le champ est obligatoire.
   * @param {string} text - Le texte du label
   * @param {string} fieldKey - La clé du champ (ex: 'Batiment')
   * @param {string[]} reqFields - La liste des champs obligatoires
   */
  function reqLabel(text, fieldKey, reqFields) {
    return reqFields.includes(fieldKey) ? `${text} <span class="req">*</span>` : text;
  }

  /** Attribut required si le champ est obligatoire */
  function reqAttr(fieldKey, reqFields) {
    return reqFields.includes(fieldKey) ? 'required' : '';
  }

  /** Invalide le cache (appelé après sauvegarde de la config) */
  function invalidateRequiredFieldsCache() {
    _cache = null;
  }

  // Expose globalement (les pages utilisent ces fonctions sans imports ES6)
  window.getRequiredFields = getRequiredFields;
  window.reqLabel = reqLabel;
  window.reqAttr = reqAttr;
  window.invalidateRequiredFieldsCache = invalidateRequiredFieldsCache;
})();

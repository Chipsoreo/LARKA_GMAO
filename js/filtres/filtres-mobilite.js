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
 * Larka — Filtres : Mobilité carbone
 */
FiltresEngine.register('mobilite_carbone', {
  fields: [
    {
      key: 'Categorie',
      label: 'Catégorie',
      type: 'chips',
      options: ['Individuel', 'Mobilité douce', 'Transport en commun', 'Aérien'],
    },
    {
      key: 'Transport',
      label: 'Transport',
      type: 'select',
      // ⚠️ Corrigé : la variable s'appelle TRANSPORTS_ADEME. L'ancien code
      // testait `typeof TRANSPORTS`, jamais défini, donc le garde-fou renvoyait
      // silencieusement une liste vide et le filtre restait inutilisable.
      // La table étant désormais alimentée par l'API, elle est lue à l'ouverture
      // du panneau et non figée au chargement du fichier.
      options: () => (typeof TRANSPORTS_ADEME !== 'undefined' ? TRANSPORTS_ADEME : [])
        .filter(t => t.id !== 'personnalise')
        .map(t => ({ value: t.id, label: t.label })),
    },
    {
      key: 'DistanceKm',
      label: 'Distance (km)',
      type: 'number',
    },
  ],
  onFilter(row, state) {
    if (state.Categorie && state.Categorie !== 'Tous') {
      const t = typeof _getTransport === 'function' ? _getTransport(row.Transport) : {};
      if ((t.cat || 'Autre') !== state.Categorie) return false;
    }
    if (state.Transport && state.Transport !== 'Tous') {
      if (row.Transport !== state.Transport) return false;
    }
    const dist = parseFloat(row.DistanceKm) || 0;
    if (state.DistanceKm_min && dist < parseFloat(state.DistanceKm_min)) return false;
    if (state.DistanceKm_max && dist > parseFloat(state.DistanceKm_max)) return false;
    return true;
  },
});

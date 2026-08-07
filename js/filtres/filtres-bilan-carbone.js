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
 * Larka — Filtres : Bilan Carbone (vue admin — mobilité + énergie)
 *
 * Filtres disponibles :
 *   - Type de déplacement (Transport)
 *   - Distance km (min / max)
 *   - Agent (utilisateur)
 *   - Service
 *   - Catégorie de mobilité (Individuel, Mobilité douce, TC, Aérien)
 *   - Année
 */
FiltresEngine.register('bilan_carbone', {
  fields: [
    {
      key: 'CatMobilite',
      label: 'Catégorie mobilité',
      type: 'chips',
      options: ['Tous', 'Individuel', 'Mobilité douce', 'Transport en commun', 'Aérien'],
    },
    {
      key: 'Transport',
      label: 'Type de déplacement',
      type: 'select',
      options: function() {
        if (typeof TRANSPORTS_ADEME === 'undefined') return [];
        return [{value:'',label:'Tous'}].concat(
          TRANSPORTS_ADEME.filter(function(t) { return t.id !== 'personnalise'; })
                          .map(function(t) { return { value: t.id, label: t.label }; })
        );
      },
    },
    {
      key: 'DistanceKm',
      label: 'Distance (km)',
      type: 'number',
    },
    {
      key: 'Agent',
      label: 'Agent',
      type: 'select',
      options: function() {
        // Populated dynamically from _bcAgentsList
        if (typeof window._bcAgentsList === 'undefined') return [];
        return [{value:'',label:'Tous'}].concat(
          window._bcAgentsList.map(function(a) {
            return { value: String(a.id), label: a.nom };
          })
        );
      },
    },
    {
      key: 'Service',
      label: 'Service',
      type: 'select',
      options: function() {
        if (typeof window._bcServicesList === 'undefined') return [];
        return [{value:'',label:'Tous'}].concat(
          window._bcServicesList.map(function(s) {
            return { value: s, label: s };
          })
        );
      },
    },
  ],
  onFilter: function(row, state) {
    // Category filter
    if (state.CatMobilite && state.CatMobilite !== 'Tous') {
      var t = typeof _getTransport === 'function' ? _getTransport(row.Transport) : {};
      if ((t.cat || 'Autre') !== state.CatMobilite) return false;
    }
    // Transport type filter
    if (state.Transport && state.Transport !== '' && state.Transport !== 'Tous') {
      if (row.Transport !== state.Transport) return false;
    }
    // Distance filter
    var dist = parseFloat(row.DistanceKm) || 0;
    if (state.DistanceKm_min && dist < parseFloat(state.DistanceKm_min)) return false;
    if (state.DistanceKm_max && dist > parseFloat(state.DistanceKm_max)) return false;
    // Agent filter
    if (state.Agent && state.Agent !== '' && state.Agent !== 'Tous') {
      if (String(row.UtilisateurId) !== String(state.Agent)) return false;
    }
    // Service filter
    if (state.Service && state.Service !== '' && state.Service !== 'Tous') {
      if ((row._service || '') !== state.Service) return false;
    }
    return true;
  },
  // Re-render only the mobilite section, not the entire page
  onRefresh: function() {
    if (typeof _bcRenderMobiliteSection === 'function') {
      _bcRenderMobiliteSection();
    }
  },
});

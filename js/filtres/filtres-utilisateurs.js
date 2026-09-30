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
 * Larka — Filtres : Utilisateurs
 *
 * Filtres disponibles :
 *  - Rôle       : Gestionnaire / Visionneur / Demandeur
 *  - Type auth  : Local / Microsoft / Google
 *  - Statut     : Actif / Désactivé
 */
FiltresEngine.register('utilisateurs', {
  fields: [
    {
      key: 'Role',
      label: 'Rôle',
      type: 'chips',
      options: ['Gestionnaire', 'Visionneur', 'Demandeur'],
    },
    {
      key: 'Provider',
      label: 'Type de connexion',
      type: 'chips',
      options: [
        { value: 'local',     label: '🔑 Local'     },
        { value: 'microsoft', label: '🔵 Microsoft'  },
        { value: 'google',    label: '🔴 Google'     },
      ],
    },
    {
      key: 'Actif',
      label: 'Statut',
      type: 'chips',
      options: [
        { value: '1', label: '✅ Actif'      },
        { value: '0', label: '🚫 Désactivé' },
      ],
    },
  ],

  /** Matcher personnalisé pour gérer les types numériques (Actif = 0/1) */
  onFilter(row, state) {
    if (state.Role && state.Role !== 'Tous') {
      if ((row.Role || '') !== state.Role) return false;
    }
    if (state.Provider && state.Provider !== 'Tous') {
      const p = row.Provider || 'local';
      if (p !== state.Provider) return false;
    }
    if (state.Actif && state.Actif !== 'Tous') {
      if (String(row.Actif ?? '1') !== state.Actif) return false;
    }
    return true;
  },
});

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
 * Larka — Filtres : Demandes
 */
FiltresEngine.register('demandes', {
  fields: [
    { key: 'Urgence',  label: 'Urgence',   type: 'select', options: ['Normale','Haute','Urgente'] },
    { key: 'Statut',   label: 'Statut',    type: 'chips',  options: () => {
      const isManager = ['Admin','Gestionnaire'].includes(App.currentUser?.Role);
      return isManager
        ? ['En attente','En cours','Traité','Refusé']
        : ['En attente','En cours'];
    }},
    { key: 'Batiment', label: 'Bâtiment',  type: 'select', options: () => _fl('Batiment') },
    { key: 'dates',    label: 'Période',    type: 'daterange', dateKey: 'DateCreation' },
    { key: 'NomDeclarant', label: 'Demandeur', type: 'text', placeholder: 'Nom du demandeur…' },
  ],
});

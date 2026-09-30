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
 * Larka — Filtres : Historique
 *
 * L'historique regroupe plusieurs sections hétérogènes (interventions archivées,
 * suppressions, demandes clôturées, fiches gestion matériel, sorties stock).
 * onFilter est volontairement un no-op : chaque section lit l'état via
 * FiltresEngine.getState('historique') et applique elle-même les critères.
 *
 * Filtres disponibles :
 *  - Période     : plage de dates (s'applique à toutes les sections)
 *  - Module      : source de la suppression (Biens, Equipements, Interventions…)
 *  - Statut dem. : statut des demandes archivées (Traité / Refusé)
 *  - Type interv.: type des interventions archivées
 *  - Auteur      : texte libre sur le login / auteur de l'action
 */
FiltresEngine.register('historique', {
  fields: [
    {
      key:     'dates',
      label:   'Période',
      type:    'daterange',
      dateKey: 'DateArchivage',   // clé indicative, appliquée manuellement
    },
    {
      key:     'TableSource',
      label:   'Module (suppressions)',
      type:    'select',
      options: ['Biens', 'Equipements', 'Interventions', 'Demandes', 'Contrats', 'Stock', 'GestionMateriel'],
    },
    {
      key:     'StatutDemande',
      label:   'Statut demande',
      type:    'chips',
      options: [
        { value: 'Traité', label: '✅ Traité' },
        { value: 'Refusé', label: '❌ Refusé' },
      ],
    },
    {
      key:     'TypeInterv',
      label:   'Type intervention',
      type:    'select',
      options: ['Préventive', 'Curative', 'Contrôle réglementaire', 'Divers'],
    },
    {
      key:         'Auteur',
      label:       'Auteur',
      type:        'text',
      placeholder: 'Login / nom…',
    },
  ],

  // Matching délégué à chaque section — pas de traitement générique
  onFilter: () => true,
});

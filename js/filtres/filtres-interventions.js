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
 * Larka — Filtres : Interventions
 */
FiltresEngine.register('interventions', {
  fields: [
    // Note: la valeur "Validée" est conservée pour matcher la base, mais on
    // l'affiche "Terminée" (vocabulaire utilisateur). "Réalisée" ajouté car
    // c'est un statut valide intermédiaire avant clôture.
    { key: 'Statut',    label: 'Statut',    type: 'chips',  options: [
      { value: 'Planifiée', label: 'Planifiée' },
      { value: 'En cours',  label: 'En cours' },
      { value: 'Réalisée',  label: 'Réalisée' },
      { value: 'Validée',   label: 'Terminée' },
    ] },
    { key: 'Type',      label: 'Type',      type: 'select', options: ['Préventive','Curative','Contrôle réglementaire','Divers'] },
    { key: 'Priorite',  label: 'Priorité',  type: 'select', options: ['Basse','Normale','Haute','Urgente'] },
    { key: 'dates',     label: 'Période',    type: 'daterange', dateKey: 'DateRealisation' },
    { key: 'SocieteManuelle', label: 'Société', type: 'text', placeholder: 'Société…' },
  ],
});

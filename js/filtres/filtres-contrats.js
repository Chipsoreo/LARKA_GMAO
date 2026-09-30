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
 * Larka — Filtres : Contrats
 */
FiltresEngine.register('contrats', {
  fields: [
    { key: 'Type',       label: 'Type',        type: 'select', options: () => _fl('TypeContrat') },
    { key: 'Societe',    label: 'Société',      type: 'text',   placeholder: 'Société…' },
    { key: 'Statut',     label: 'Statut',       type: 'chips',  options: ['Actif','Échu','Résilié'] },
    { key: 'dates',      label: 'Échéance',     type: 'daterange', dateKey: 'DateFin' },
  ],
});

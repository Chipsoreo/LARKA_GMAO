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
 * Larka — Filtres : Biens
 */
FiltresEngine.register('biens', {
  fields: [
    { key: 'Famille',     label: 'Famille',      type: 'select', options: () => _fl('FamilleBien') },
    { key: 'SousFamille', label: 'Sous-famille',  type: 'select', options: () => _fl('SousFamilleBien') },
    { key: 'Statut',      label: 'Statut',        type: 'select', options: () => _fl('StatutBien') },
    { key: 'Etat',        label: 'État',          type: 'select', options: ['Utilise','Stock','Jete/Recycle','Don/Vendu'] },
    { key: 'Batiment',    label: 'Bâtiment',      type: 'select', options: () => _fl('Batiment') },
    { key: 'NomPrenom',   label: 'Affecté à',     type: 'text',   placeholder: 'Nom ou prénom…' },
    { key: 'Prix',        label: 'Prix (€)',       type: 'number' },
  ],
});

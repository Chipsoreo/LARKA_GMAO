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
 * Larka — Filtres : Équipements
 */
FiltresEngine.register('equipements', {
  fields: [
    { key: 'Famille',      label: 'Famille',      type: 'select', options: () => _fl('FamilleEquipement') },
    { key: 'SousFamille',  label: 'Sous-famille',  type: 'select', options: () => _fl('SousFamilleEquipement') },
    { key: 'Statut',       label: 'Statut',        type: 'select', options: () => _fl('StatutEquipement') },
    { key: 'Etat',         label: 'État',          type: 'select', options: ['Utilise','Stock','Jete/Recycle','Don/Vendu'] },
    { key: 'Marque',       label: 'Marque',        type: 'text',   placeholder: 'Marque…' },
    { key: 'Fournisseur',  label: 'Fournisseur',   type: 'text',   placeholder: 'Fournisseur…' },
    { key: 'Batiment',     label: 'Bâtiment',      type: 'select', options: () => _fl('Batiment') },
    { key: 'Prix',         label: 'Prix (€)',       type: 'number' },
  ],
});

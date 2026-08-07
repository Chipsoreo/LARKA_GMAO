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
 * Larka — Filtres : Gestion matériel
 */
FiltresEngine.register('gestion_materiel', {
  fields: [
    { key: 'CompteImmo', label: 'Compte immo',  type: 'text',   placeholder: 'Compte…' },
    { key: 'Exercice',   label: 'Exercice',      type: 'text',   placeholder: 'Ex: 2024' },
    { key: 'AssetEtat',  label: 'État actif',    type: 'select', options: ['Utilise','Stock','Jete/Recycle','Don/Vendu'] },
    { key: 'ValeurAchat', label: 'Valeur achat (€)', type: 'number' },
    { key: 'dates',      label: 'Date achat',    type: 'daterange', dateKey: 'DateAchat' },
  ],
});

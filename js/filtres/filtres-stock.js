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
 * Larka — Filtres : Stock
 */
FiltresEngine.register('stock', {
  fields: [
    { key: 'Categorie',    label: 'Catégorie',     type: 'select', options: () => _fl('CategorieStock') },
    { key: '_alerte',      label: 'Alerte stock',   type: 'chips',  options: [{ value: '1', label: '⚠️ En alerte' }] },
    { key: 'Marque',       label: 'Marque',        type: 'text',   placeholder: 'Marque…' },
    { key: 'Fournisseur',  label: 'Fournisseur',   type: 'text',   placeholder: 'Fournisseur…' },
    { key: 'Emplacement',  label: 'Emplacement',   type: 'text',   placeholder: 'Emplacement…' },
    { key: 'Quantite',     label: 'Quantité',      type: 'number' },
    { key: 'PrixUnitaire', label: 'Prix unitaire (€)', type: 'number' },
  ],
  // Custom filter: ajouter le filtre "Alerte"
  onFilter(row, state) {
    for (const [k, v] of Object.entries(state)) {
      if (!v || v === '' || v === 'Tous') continue;
      // Alerte spéciale via Categorie
      if (k === '_alerte' && v === '1' && row.Quantite > row.SeuilAlerte) return false;
      // Select / chips
      if (k === 'Categorie' && row.Categorie !== v) return false;
      // Text
      if ((k === 'Marque' || k === 'Fournisseur' || k === 'Emplacement') && !String(row[k]||'').toLowerCase().includes(v.toLowerCase())) return false;
      // Number range
      if (k === 'Quantite_min' && (parseFloat(row.Quantite)||0) < parseFloat(v)) return false;
      if (k === 'Quantite_max' && (parseFloat(row.Quantite)||0) > parseFloat(v)) return false;
      if (k === 'PrixUnitaire_min' && (parseFloat(row.PrixUnitaire)||0) < parseFloat(v)) return false;
      if (k === 'PrixUnitaire_max' && (parseFloat(row.PrixUnitaire)||0) > parseFloat(v)) return false;
    }
    return true;
  },
});

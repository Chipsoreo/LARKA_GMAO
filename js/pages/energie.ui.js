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
 * Larka — Module Énergie : Helpers UI
 * Fonctions utilitaires spécifiques à l'interface énergie.
 */
// NB : toggleFiltreAvance() était redéfini ici, écrasant en portée globale la
// version générique de js/ui.js — laquelle gère déjà les panneaux énergie via
// son paramètre (`toggleFiltreAvance('stats')`). Les deux étaient sans appelant
// (panneaux désormais pilotés par FiltresEngine) : doublon mort supprimé.

// Appelé par les checkboxes relevé/facture dans le panneau stats
function toggleStatsCourbes() {
  const elR = document.getElementById('fs_showReleve');
  const elF = document.getElementById('fs_showFacture');
  if (elR) EnergieStatsState.showReleve  = elR.checked;
  if (elF) EnergieStatsState.showFacture = elF.checked;
  _refreshEnergieStatsOnly();
}
function switchEnergieOnglet(type) {
  EnergieState.onglet = type;
  App.searchTerm = '';
  renderEnergie();
}
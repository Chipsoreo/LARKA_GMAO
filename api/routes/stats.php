<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka — Routes : Statistiques
 *
 * Actions : dashboard_stats, stats_completes
 * Données agrégées pour le tableau de bord et les rapports.
 */

// ── Dashboard ─────────────────────────────────────────────────────────────────
if ($action === 'dashboard' && $method === 'GET') {
    $user = require_auth(); require_lecture($user, ['Admin','Gestionnaire','Visionneur'], 'dashboard', $db);
    json_ok([
        'stats'          => $db->getDashboardStats(),
        'interventionsPrevues'  => $db->getInterventionsPrevues(),
        'interventionsEnCours'  => $db->getInterventionsEnCours(),
        'stockAlerte'    => $db->getStockEnAlerte(),
        'contratsAlerte' => $db->getContratsEnAlerte(),
    ]);
}

// ── Interventions agrégées par mois (mini-graphe du dashboard, période réglable) ──
if ($action === 'interventions_mensuelles' && $method === 'GET') {
    $user = require_auth(); require_lecture($user, ['Admin','Gestionnaire','Visionneur'], 'statsAvancees', $db);
    $mois  = isset($_GET['mois']) ? (int)$_GET['mois'] : 6;
    $debut = isset($_GET['debut']) && $_GET['debut'] !== '' ? (string)$_GET['debut'] : null;
    $fin   = isset($_GET['fin'])   && $_GET['fin']   !== '' ? (string)$_GET['fin']   : null;
    json_ok($db->getInterventionsParMois($mois, $debut, $fin));
}

// ── Stats avancées (Admin) ────────────────────────────────────────────────────
if ($action === 'stats' && $method === 'GET') {
    $user = require_auth(); require_role($user, ['Admin','Gestionnaire']);
    json_ok($db->getStatsAvancees());
}

// ── Stats complètes avec filtres date ─────────────────────────────────────────
if ($action === 'stats_completes' && $method === 'GET') {
    $user = require_auth(); require_lecture($user, ['Admin','Gestionnaire','Visionneur'], 'statsAvancees', $db);
    $filtres = [
        'dateDebut'    => $_GET['dateDebut']    ?? null,
        'dateFin'      => $_GET['dateFin']      ?? null,
        'typeInterv'   => $_GET['typeInterv']   ?? null,
        'statutInterv' => $_GET['statutInterv'] ?? null,
    ];
    json_ok($db->getStatsAvanceesCompletes($filtres));
}

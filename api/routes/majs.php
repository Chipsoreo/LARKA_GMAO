<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka — Routes : mises à jour de l'application (voir api/MiseAJour.php).
 *
 *   maj_etat        GET   version installée + dernière détection (cache)
 *   maj_verifier    POST  interroge la source maintenant
 *   maj_installer   POST  {version, confirme:true} — installation validée à la main
 *   maj_historique  GET   installations passées + sauvegardes disponibles
 *   maj_restaurer   POST  {id} — revient à la version d'avant une installation
 *
 * Droits : mêmes que les paramètres serveur — Super Administrateur ; en
 * mono-tenant, aussi Admin et Gestionnaire. Le code est commun à tous les
 * clients d'un serveur : en multi-tenant, seul le Super Admin décide.
 */

if (in_array($action, ['maj_etat', 'maj_verifier', 'maj_installer', 'maj_historique', 'maj_restaurer'], true)) {
    require_once __DIR__ . '/../MiseAJour.php';

    $qui = null;
    if (!empty($_SESSION['superadmin']['authenticated'])) {
        $qui = 'superadmin:' . ($_SESSION['superadmin']['login'] ?? '?');
    } elseif (!empty($_SESSION['user'])) {
        $multi = class_exists('TenantResolver', false) && TenantResolver::isMultiTenant();
        if (!$multi && in_array($_SESSION['user']['Role'] ?? '', ['Admin', 'Gestionnaire'], true)) {
            $qui = (string)($_SESSION['user']['Login'] ?? '?');
        }
    }
    $maj = new LarkaMiseAJour();

    if ($action === 'maj_etat') {
        if (!$qui) json_ok(['droits' => false, 'locale' => $maj->locale()]);
        json_ok(['droits' => true] + $maj->etat());
    }
    if (!$qui) json_error('Réservé à l\'administrateur du serveur.', 403);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    if ($action === 'maj_historique') json_ok($maj->historique());
    if ($method !== 'POST') json_error('Méthode non supportée.', 405);
    $b = get_body();

    if ($action === 'maj_verifier') {
        check_rate_limit('maj_verifier', 20, 3600);
        json_ok(['droits' => true] + $maj->verifier());
    }
    if ($action === 'maj_installer') {
        if (empty($b['confirme'])) json_error('Confirmation manquante.', 400);
        ignore_user_abort(true);   // une fois lancée, l'installation va au bout
        try {
            json_ok($maj->installer((string)($b['version'] ?? ''), $qui));
        } catch (\Throwable $e) {
            json_error($e->getMessage(), 409);
        }
    }
    if ($action === 'maj_restaurer') {
        ignore_user_abort(true);
        try {
            json_ok($maj->restaurer((string)($b['id'] ?? ''), $qui));
        } catch (\Throwable $e) {
            json_error($e->getMessage(), 409);
        }
    }
}

<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka — Routes : réglage du serveur de supervision séparé (gestionnaire).
 * Voir api/SupervisionLien.php.
 *
 * Créer ou utiliser un code d'appairage, et délier, demandent une identité
 * confirmée depuis moins de X minutes (comme écrire sur un équipement) : ce
 * sont ces gestes qui décident quel serveur reçoit les écrans et les secrets
 * des connexions.
 */

if (str_starts_with($action, 'supervision_')) {
    require_once __DIR__ . '/../connect/Connect.php';
    require_once __DIR__ . '/../SupervisionLien.php';
    Connect::charger();

    $svUser = require_auth();
    require_role($svUser, ['Admin', 'Gestionnaire']);
    $svOrg = Connect::organisationCourante();
    $svIdentite = function () use ($svUser): void {
        if ((int)($_SESSION['connect_elevation'] ?? 0) < time()) {
            json_error('Confirmez votre identité : ce réglage décide quel serveur reçoit la supervision et les identifiants des équipements.', 428);
        }
    };
    $svJournal = function (string $quoi) use ($svUser, $svOrg): void {
        if (class_exists('SecurityLog')) SecurityLog::audit('supervision_lien', ['login' => (string)$svUser['Login'], 'tenant' => $svOrg, 'target_action' => $quoi]);
    };

    if ($action === 'supervision_etat' && $method === 'GET') {
        $e = SupervisionLien::pourEcran($db);
        $e['module_ouvert'] = SupervisionLien::moduleOuvert($db);
        $e['connect_serveur'] = Connect::actifServeur();
        $e['url_suggeree'] = defined('APP_URL') ? (string)APP_URL : '';
        $e['url_locale_suggeree'] = 'http://127.0.0.1:' . (defined('APP_PORT') ? APP_PORT : 8000);
        $e['base'] = $db->getDriver();
        json_ok($e);
    }

    if ($action === 'supervision_code' && $method === 'POST') {
        $svIdentite();
        $b = get_body();
        try {
            if (($b['role'] ?? '') === 'supervision' && isset($b['url_locale'])) {
                SupervisionLien::reglerUrlLocale($db, (string)$b['url_locale']);
            }
            $code = SupervisionLien::creerCode($db, (string)($b['role'] ?? ''), (string)($b['mode'] ?? ''), (string)($b['url'] ?? ''), $svOrg, (string)$svUser['Login']);
        } catch (\Throwable $e) {
            json_error($e->getMessage(), 400);
        }
        $svJournal('code d\'appairage créé (' . ($b['role'] ?? '') . ', ' . ($b['mode'] ?? '') . ')');
        json_ok(['code' => $code, 'expire' => date('H:i', time() + SupervisionLien::DUREE_CODE)]);
    }

    if ($action === 'supervision_utiliser_code' && $method === 'POST') {
        $svIdentite();
        $b = get_body();
        try {
            if (isset($b['url_locale']) && $b['url_locale'] !== '') SupervisionLien::reglerUrlLocale($db, (string)$b['url_locale']);
            $r = SupervisionLien::utiliserCode($db, (string)($b['code'] ?? ''), isset($b['url']) ? (string)$b['url'] : null, $svOrg, (string)$svUser['Login']);
        } catch (\Throwable $e) {
            json_error($e->getMessage(), 400);
        }
        $svJournal('appairage (' . $r['role'] . ', ' . $r['mode'] . ', ' . $r['url'] . ')');
        json_ok($r);
    }

    if ($action === 'supervision_tester' && $method === 'POST') {
        $l = SupervisionLien::lire($db);
        if ($l['Role'] !== 'principal') {
            json_ok(['ok' => !empty($l['DernierContact']) && strtotime((string)$l['DernierContact']) > time() - 120,
                     'message' => $l['DernierContact'] ? 'Dernier échange avec le serveur principal : ' . date('d/m/Y H:i:s', strtotime((string)$l['DernierContact'])) : 'Aucun échange encore.']);
        }
        $t0 = microtime(true);
        try {
            $r = SupervisionLien::requete($db, 'ping', [], 15);
        } catch (\Throwable $e) {
            json_ok(['ok' => false, 'message' => $e->getMessage()]);
        }
        json_ok(['ok' => true, 'ms' => (int)round((microtime(true) - $t0) * 1000), 'distant' => $r,
                 'message' => 'Le serveur de supervision répond (' . (int)round((microtime(true) - $t0) * 1000) . ' ms, base ' . ($r['base'] ?? '?') . ').']);
    }

    if ($action === 'supervision_synchroniser' && $method === 'POST') {
        try { json_ok(SupervisionLien::synchroniser($db)); }
        catch (\Throwable $e) { json_error($e->getMessage(), 502); }
    }

    if ($action === 'supervision_url' && $method === 'POST') {
        $b = get_body();
        try {
            if (isset($b['url_locale'])) SupervisionLien::reglerUrlLocale($db, (string)$b['url_locale']);
            if (isset($b['url'])) SupervisionLien::reglerUrl($db, (string)$b['url']);
        } catch (\Throwable $e) {
            json_error($e->getMessage(), 400);
        }
        $svJournal('adresse modifiée');
        json_ok(SupervisionLien::pourEcran($db));
    }

    if ($action === 'supervision_delier' && $method === 'POST') {
        $svIdentite();
        SupervisionLien::delier($db, (string)$svUser['Login']);
        $svJournal('lien délié');
        json_ok(SupervisionLien::pourEcran($db));
    }
}

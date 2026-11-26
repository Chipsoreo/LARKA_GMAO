<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka — relais du PONT SORTANT, sur le serveur de supervision.
 *
 * Appelle le serveur principal, attend ses requêtes (20 s par attente), les
 * fait traiter par ce serveur (son point d'entrée local) et rapporte les
 * réponses. Les messages restent scellés de bout en bout : le relais ne les
 * déchiffre pas. Aucun port n'est à ouvrir vers le réseau technique.
 *
 * Lancé tout seul par la collecte (chaque minute, --duree=58) ; un seul relais
 * à la fois (verrou data/supervision-pont.lock). Pour un service permanent
 * (systemd) : --permanent.
 *
 *   php api/outils/supervision-pont.php [--duree=58] [--permanent] [--silencieux]
 */

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('LARKA_SANS_SESSION', true);
$racine = dirname(__DIR__, 2);
chdir($racine);
require_once $racine . '/api/config.php';
require_once $racine . '/api/SecurityLog.php';
require_once $racine . '/api/Journal.php';
require_once $racine . '/api/Database.php';
require_once $racine . '/api/connect/Connect.php';
Connect::charger();

$args = array_slice($argv, 1);
$silencieux = in_array('--silencieux', $args, true);
$permanent = in_array('--permanent', $args, true);
$duree = 58;
foreach ($args as $a) if (str_starts_with($a, '--duree=')) $duree = max(5, min(3600, (int)substr($a, 8)));

@mkdir($racine . '/data', 0755, true);
$verrou = fopen($racine . '/data/supervision-pont.lock', 'c');
if (!$verrou || !flock($verrou, LOCK_EX | LOCK_NB)) {
    if (!$silencieux) echo "Un relais tourne déjà.\n";
    exit(0);
}
$log = function (string $m) use ($silencieux) {
    if (!$silencieux) echo '[' . date('Y-m-d H:i:s') . "] $m\n";
};
do {
    try {
        SupervisionLien::relayer(new Database(), $permanent ? 300 : $duree, $log);
    } catch (\Throwable $e) {
        $log('Relais : ' . $e->getMessage());
        if ($permanent) sleep(10);
    }
} while ($permanent);
flock($verrou, LOCK_UN);

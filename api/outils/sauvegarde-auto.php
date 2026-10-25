<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka — sauvegardes automatiques (voir api/SauvegardesAuto.php).
 *
 * Lancé toutes les 10 minutes par le déclencheur de l'installation (cron en
 * production, minuterie systemd, planificateur de start.sh, lanceur Windows) :
 * ne fait que les sauvegardes DUES, une seule à la fois.
 *
 *   php api/outils/sauvegarde-auto.php              sauvegardes dues
 *   php api/outils/sauvegarde-auto.php --forcer     toutes, maintenant
 *   php api/outils/sauvegarde-auto.php --etat       réglages, dernière, prochaine
 *   php api/outils/sauvegarde-auto.php --silencieux n'écrit que ce qui a été fait ou a échoué
 *
 * Code de sortie : 0 si rien n'a échoué, 1 sinon (le cron le signale).
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
require_once $racine . '/api/SauvegardesAuto.php';

$args = array_slice($argv, 1);
$silencieux = in_array('--silencieux', $args, true);
$quand = date('Y-m-d H:i:s');

if (in_array('--etat', $args, true)) {
    $e = LarkaSauvegardesAuto::etat();
    echo "Dernier passage du planificateur : " . ($e['dernier_passage'] ?: 'jamais') . "\n";
    foreach ($e['travaux'] as $w) {
        $r = $w['reglage'];
        echo "\n« {$w['nom']} » (" . ($w['cle']) . ") : " . ($r['actif'] ? "toutes les {$r['intervalle_minutes']} min, {$r['garder']} gardées, dans {$r['dossier']}" : 'désactivée') . "\n";
        echo "  dernière  : " . ($w['derniere'] ?: 'jamais') . ($w['fichier'] ? " ({$w['fichier']})" : '') . "\n";
        if ($r['actif']) echo "  prochaine : " . ($w['prochaine'] ?: '—') . ($w['en_retard'] ? '  ⚠ EN RETARD' : '') . "\n";
        if ($w['erreur']) echo "  ⚠ dernier échec : {$w['erreur']}\n";
    }
    exit(0);
}

try {
    $res = LarkaSauvegardesAuto::executer(in_array('--forcer', $args, true));
} catch (\Throwable $e) {
    echo "[$quand] ✗ Sauvegardes automatiques : " . $e->getMessage() . "\n";
    exit(1);
}
if (!$res['verrou']) {
    if (!$silencieux) echo "[$quand] Une sauvegarde est déjà en cours : rien à faire.\n";
    exit(0);
}
$echec = false;
foreach ($res['resultats'] as $r) {
    if (!empty($r['fait'])) {
        echo "[$quand] ✓ « {$r['nom']} » : {$r['fichier']} (" . round(($r['octets'] ?? 0) / 1024) . " Ko, {$r['duree']} s"
           . (($r['fichiers']['fait'] ?? false) ? ', fichiers compris' : '')
           . ($r['supprimees'] ? ', ' . count($r['supprimees']) . ' ancien(s) fichier(s) supprimé(s)' : '') . ")\n";
    } elseif (!empty($r['erreur'])) {
        $echec = true;
        echo "[$quand] ✗ « {$r['nom']} » : {$r['erreur']}\n";
    } elseif (!$silencieux) {
        echo "[$quand] · « {$r['nom']} » : {$r['raison']}" . (!empty($r['prochaine']) ? " (prochaine : {$r['prochaine']})" : '') . "\n";
    }
}
exit($echec ? 1 : 0);

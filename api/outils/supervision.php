<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka — serveur de supervision séparé, en ligne de commande.
 * Mêmes gestes que l'écran (Connexions → Serveur de supervision), pour un
 * serveur sans navigateur ou une installation scriptée.
 *
 *   php api/outils/supervision.php etat
 *   php api/outils/supervision.php code --role=supervision --mode=pont --url=https://supervision.local
 *   php api/outils/supervision.php appairer LKS1.xxxxx [--url=https://moi:8443] [--url-locale=http://127.0.0.1:8000]
 *   php api/outils/supervision.php tester            (serveur principal)
 *   php api/outils/supervision.php synchroniser      (serveur principal)
 *   php api/outils/supervision.php delier
 *
 * --organisation=cle : en multi-organisation, l'organisation concernée (serveur principal).
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
$cmd = $args[0] ?? 'etat';
$opt = function (string $nom, ?string $defaut = null) use ($args): ?string {
    foreach ($args as $a) if (str_starts_with($a, "--$nom=")) return substr($a, strlen($nom) + 3);
    return $defaut;
};
$orgCle = $opt('organisation', 'default');
$db = null;
foreach (Connect::organisations() as $o) if ($o['cle'] === $orgCle) $db = $o['base'] === null ? new Database() : new Database($o['base']);
if (!$db) { fwrite(STDERR, "Organisation inconnue : $orgCle\n"); exit(2); }
$qui = 'console:' . (get_current_user() ?: 'cli');

try {
    switch ($cmd) {
        case 'etat':
            $e = SupervisionLien::pourEcran($db);
            echo 'Rôle      : ' . ($e['role'] === 'supervision' ? 'serveur de supervision' : 'serveur principal') . "\n";
            echo 'Mode      : ' . ['local' => 'supervision sur ce serveur (pas de séparation)', 'direct' => 'port ouvert (le principal appelle la supervision)', 'pont' => 'pont sortant (la supervision appelle le principal)'][$e['mode']] . "\n";
            echo 'Appairé   : ' . ($e['appaire'] ? 'oui, avec ' . $e['url'] : 'non') . "\n";
            if ($e['empreinte']) echo 'Empreinte : ' . $e['empreinte'] . "  (doit être la même sur les deux serveurs)\n";
            echo 'Contact   : ' . ($e['dernier_contact'] ?: 'jamais') . ($e['derniere_erreur'] ? "\n⚠ " . $e['derniere_erreur'] : '') . "\n";
            if ($e['role'] === 'supervision') echo 'En attente d\'envoi au principal : ' . $e['sortants_en_attente'] . "\n";
            if (!$e['sodium']) echo "⚠ Extension PHP sodium absente : le lien chiffré est impossible.\n";
            break;
        case 'code':
            $c = SupervisionLien::creerCode($db, (string)$opt('role', 'supervision'), (string)$opt('mode', 'pont'), (string)$opt('url', ''), $orgCle, $qui);
            if ($u = $opt('url-locale')) SupervisionLien::reglerUrlLocale($db, $u);
            echo "Code d'appairage (valable 30 minutes, une seule fois) — à coller sur l'autre serveur :\n\n$c\n";
            break;
        case 'appairer':
            if ($u = $opt('url-locale')) SupervisionLien::reglerUrlLocale($db, $u);
            $r = SupervisionLien::utiliserCode($db, (string)($args[1] ?? ''), $opt('url'), $orgCle, $qui);
            echo "✓ Appairé : ce serveur est le " . ($r['role'] === 'supervision' ? 'serveur de supervision' : 'serveur principal') . " ; l'autre : {$r['url']}\n";
            echo "  Empreinte : {$r['empreinte']}  (vérifiez qu'elle est identique sur l'autre écran)\n";
            break;
        case 'tester':
            $t = microtime(true);
            $r = SupervisionLien::requete($db, 'ping', [], 15);
            echo '✓ Le serveur de supervision répond en ' . (int)round((microtime(true) - $t) * 1000) . " ms (base {$r['base']}, PHP {$r['php']}).\n";
            break;
        case 'synchroniser':
            $r = SupervisionLien::synchroniser($db);
            echo "✓ {$r['sortants']} écriture(s) reçue(s)" . ($r['referentiel'] ? ", référentiel envoyé ({$r['tables']} table(s))" : ', référentiel inchangé') . ".\n";
            break;
        case 'delier':
            SupervisionLien::delier($db, $qui);
            echo "✓ Lien délié : la supervision revient sur ce serveur (mode local).\n";
            break;
        default:
            echo "Commandes : etat | code | appairer <code> | tester | synchroniser | delier\n";
            exit(2);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, '✗ ' . $e->getMessage() . "\n");
    exit(1);
}

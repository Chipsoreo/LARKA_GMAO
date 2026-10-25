<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Épreuve « tenants » — création d'un tenant PostgreSQL et démonstration.
 *
 *   1. Noms de base cités exactement : « mairie-nord » était créée sous le nom
 *      « mairie_nord », puis cherchée sous son vrai nom — tenant en erreur.
 *   2. Le test de connexion dit la vérité : sans droit de créer la base (ou d'y
 *      créer des tables), il ne répond plus « OK ». (Volet PostgreSQL, si
 *      LARKA_EPREUVE_PG est défini.)
 *   3. Rien n'est enregistré en silence : la création est vérifiée avant
 *      l'enregistrement, les échecs restants sont signalés comme tels, la liste
 *      des tenants n'est plus servie d'un cache périmé.
 *   4. La démonstration se pose aussi sous Linux (start.sh), PostgreSQL compris.
 *
 * php outils/epreuves/test-tenants.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$racine = dirname(__DIR__, 2);
require_once $racine . '/api/Chemins.php';
require_once $racine . '/api/TenantResolver.php';

$ok = 0; $ko = 0;
function verif(bool $c, string $m, string $d = ''): void
{
    global $ok, $ko;
    if ($c) { $ok++; printf("  ✅  %-60s %s\n", $m, $d); }
    else    { $ko++; printf("  ❌  %-60s %s\n", $m, $d); }
}

echo "\n  Noms de base\n";
verif(TenantResolver::identifiantSql('mairie-nord', 'pgsql') === '"mairie-nord"', 'PostgreSQL : nom cité tel quel', TenantResolver::identifiantSql('mairie-nord', 'pgsql'));
verif(TenantResolver::identifiantSql('Ma"Base', 'pgsql') === '"Ma""Base"', 'PostgreSQL : guillemet doublé');
verif(TenantResolver::identifiantSql('a`b', 'mysql') === '`a``b`', 'MariaDB : accent grave doublé');
$refus = 0;
foreach (["", "a\nb", str_repeat('x', 64)] as $mauvais) {
    try { TenantResolver::identifiantSql($mauvais, 'pgsql'); } catch (InvalidArgumentException $e) { $refus++; }
}
verif($refus === 3, 'vide, caractère de contrôle, plus de 63 octets : refusés');

echo "\n  Plus aucun nom « assaini » dans CREATE / DROP DATABASE\n";
foreach (['api/TenantResolver.php', 'api/routes/superadmin.php'] as $f) {
    $src = (string)file_get_contents("$racine/$f");
    $assaini = preg_match('/preg_replace\(\'\/\[\^a-zA-Z0-9_\]\/\',\s*\'_\',\s*\$(db|dbn|dbname)\b/', $src);
    verif(!$assaini && !preg_match('/(CREATE|DROP) DATABASE[^;\n]*\{\$safeName\}/', $src), $f);
}

echo "\n  Création de base : template0, jamais template1\n";
// Après une mise à jour du système qui change la version de collation,
// PostgreSQL refuse de copier template1 : « template database "template1"
// has a collation version mismatch » — le tenant n'était pas créé.
verif(str_contains((string)file_get_contents("$racine/api/TenantResolver.php"), "ENCODING 'UTF8' TEMPLATE template0\");"), 'tenant (TenantResolver)');
verif(substr_count((string)file_get_contents("$racine/start.sh"), 'ENCODING UTF8 TEMPLATE template0') === 2, 'start.sh setup (2 bases)');
verif(substr_count((string)file_get_contents("$racine/deploy/install.sh"), 'ENCODING UTF8 TEMPLATE template0') === 2, 'deploy/install.sh (2 bases)');

echo "\n  Enregistrement d'un tenant\n";
$sa = (string)file_get_contents("$racine/api/routes/superadmin.php");
verif(str_contains($sa, "TenantResolver::diagnosticPg(\$tenant['base_de_donnees']"), 'base vérifiée avant enregistrement');
verif(substr_count($sa, "'avertissement' => true") >= 4, 'échecs restants signalés (avertissement)');
verif(str_contains($sa, "@unlink(__DIR__ . '/../../data/superadmin_tenants_cache.json')"), 'cache de la liste vidé à chaque modification');
verif(str_contains($sa, "'db_erreur' => \$dbErreur"), 'raison de l\'erreur transmise à l\'écran');
$js = (string)file_get_contents("$racine/js/pages/superadmin.js");
verif(str_contains($js, "json.data?.avertissement ? 'error' : 'success'"), 'écran : avertissement affiché en erreur');
verif(str_contains($js, 'saRefresh(true)'), 'écran : liste relue après enregistrement');

$pg = getenv('LARKA_EPREUVE_PG') ?: '';
if ($pg === '' || !extension_loaded('pdo_pgsql')) {
    echo "\n  ⏭️  PostgreSQL : LARKA_EPREUVE_PG non défini — volet sauté.\n";
} else {
    echo "\n  Test de connexion PostgreSQL (rôle de l'épreuve)\n";
    parse_str(str_replace(';', '&', $pg), $p);
    $c = ['host' => $p['host'] ?? '127.0.0.1', 'port' => (int)($p['port'] ?? 5432), 'dbname' => $p['dbname'] ?? '',
          'user' => $p['user'] ?? '', 'password' => $p['password'] ?? '', 'sslmode' => 'prefer'];
    $d = TenantResolver::diagnosticPg($c);
    verif($d['ok'] && $d['existe'], 'base existante et possédée : acceptée', $d['message']);
    $pdo = new PDO(sprintf('pgsql:host=%s;port=%d;dbname=%s', $c['host'], $c['port'], $c['dbname']), $c['user'], $c['password']);
    $peutCreer = (bool)$pdo->query("SELECT rolcreatedb OR rolsuper FROM pg_roles WHERE rolname = current_user")->fetchColumn();
    $d2 = TenantResolver::diagnosticPg(['dbname' => 'larka_epreuve_absente_' . bin2hex(random_bytes(3))] + $c);
    verif($d2['ok'] === $peutCreer && !$d2['existe'], 'base absente : OK seulement si le rôle peut la créer',
        $peutCreer ? '(rôle CREATEDB)' : '(rôle sans CREATEDB : refus)');
    if (!$peutCreer) verif(str_contains($d2['message'], 'CREATE DATABASE'), 'refus : commande à exécuter indiquée');
    $d3 = TenantResolver::diagnosticPg(['password' => 'mauvais-mot-de-passe'] + $c);
    verif(!$d3['ok'], 'mot de passe faux : refusé', $d3['message']);
}

echo "\n  Démonstration sous Linux\n";
$sh = (string)file_get_contents("$racine/start.sh");
verif(str_contains($sh, 'demo|demonstration) cmd_demo ;;'), 'start.sh demo');
verif(substr_count($sh, "        offer_demo\n") + substr_count($sh, "    offer_demo\n") >= 2, 'proposée à la fin de setup ET au démarrage (start)');
verif(str_contains($sh, '--proposer') && str_contains($sh, 'data/run/demo-refusee'), '… une seule fois (un « non » est retenu)');
$lkp = (string)file_get_contents("$racine/deploy/windows/larka.php");
verif(str_contains($lkp, "\$proposer && (!lk_interactif()") && str_contains($lkp, "['oui' => false] + \$o"), '… jamais sans quelqu\'un pour répondre, même avec -y');
verif(str_contains($lkp, 'function lk_multi_tenant') && str_contains($lkp, "'pgsql'") , '… ni sur une installation multi-tenant (registre SQLite ou PostgreSQL)');
$lk = (string)file_get_contents("$racine/deploy/windows/larka.php");
verif(str_contains($lk, "'--single-transaction'") && str_contains($lk, "'pg'       => '/-postgresql"), 'PostgreSQL : dump chargé en une transaction');
verif(!preg_match("/'--mot-de-passe=/", $lk) && str_contains($lk, "\$env['PGPASSWORD']"), 'mot de passe par PGPASSWORD, jamais en argument');

echo "\n  Démonstration livrée dans demo/\n";
$demo = "$racine/demo/sauvegarde-demo-Larkaville.zip";
$noms = [];
if (is_file($demo) && class_exists('ZipArchive')) {
    $z = new ZipArchive();
    if ($z->open($demo) === true) { for ($i = 0; $i < $z->numFiles; $i++) $noms[] = (string)$z->getNameIndex($i); $z->close(); }
}
$a = fn(string $suffixe) => (bool)array_filter($noms, fn($n) => str_ends_with($n, $suffixe));
verif($a('-sqlite.db.gz') && $a('-postgresql.sql.gz') && $a('-fichiers.tar.gz') && $a('comptes-demo.csv'),
    'archive présente et complète (SQLite, PostgreSQL, fichiers, comptes)', count($noms) . ' fichier(s)');
verif(fnmatch('*demo-Larkaville*.zip', basename($demo)) && preg_match('/demo-Larkaville.*\.zip$/i', basename($demo)) === 1,
    'nom reconnu par start.sh et par Larka.cmd');
verif(str_contains($sh, 'for d in "$SCRIPT_DIR" "$(dirname "$SCRIPT_DIR")" "$SCRIPT_DIR/demo"; do')
    && str_contains($lk, "\$dossiers = [LK_RACINE, LK_RACINE . LK_SEP . 'demo'];"), 'cherchée dans demo/ (start.sh, Larka.cmd)');
verif(preg_match("/^\s*'php' 'demo' /m", (string)file_get_contents("$racine/deploy/install.sh")) === 1
    && str_contains((string)file_get_contents("$racine/outils/publier-version.php"), '|demo/|'),
    'ni copiée en production, ni emportée par les mises à jour');
verif(str_contains((string)file_get_contents("$racine/router.php"), "'demo/',")
    && str_contains((string)file_get_contents("$racine/deploy/nginx.conf"), 'location ^~ /demo/ '),
    'refusée par le serveur web (routeur intégré, nginx)');
verif(str_contains((string)file_get_contents("$racine/.gitignore"), "\n!demo/sauvegarde-demo-Larkaville.zip"),
    'versionnée (exception du .gitignore)');

$total = $ok + $ko;
echo "\n───────────────────────────────────────────────────────────────────────\n";
echo $ko === 0 ? " ✅ $ok/$total — un tenant PostgreSQL enregistré est un tenant qui marche.\n"
               : " ❌ $ko échec(s) sur $total.\n";
echo "───────────────────────────────────────────────────────────────────────\n";
exit($ko === 0 ? 0 : 1);

<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * ═══════════════════════════════════════════════════════════════════════════
 *  Larka — PostgreSQL après une mise à jour du système : version de collation
 * ═══════════════════════════════════════════════════════════════════════════
 *
 *  L'ordre de tri des textes (la « collation ») vient de la bibliothèque C du
 *  système (glibc) ou d'ICU. Quand une mise à jour du système en change la
 *  version (glibc 2.42 → 2.43, par exemple), PostgreSQL le remarque :
 *
 *    - chaque connexion à une base créée avant affiche un avertissement
 *      (« database … has a collation version mismatch ») : ses index sur du
 *      texte ont été construits avec l'ancien ordre et peuvent ne plus
 *      retrouver toutes les lignes ;
 *    - créer une base à partir de template1 est REFUSÉ (« template database
 *      "template1" has a collation version mismatch ») — c'est ce qui
 *      empêchait de créer un tenant.
 *
 *  Le remède ne touche pas aux données : reconstruire les index de chaque base
 *  (REINDEX DATABASE), puis y enregistrer la version actuelle (ALTER DATABASE
 *  … REFRESH COLLATION VERSION). Si une reconstruction échoue (doublons entrés
 *  pendant que l'index était faux), la version n'est PAS enregistrée : le
 *  problème reste visible, avec le message de PostgreSQL.
 *
 *  Usage (depuis le dossier de Larka) :
 *    php api/outils/postgresql.php collation             état — code 0 : à jour,
 *                                                        3 : bases à réindexer, 2 : sans objet / injoignable
 *    php api/outils/postgresql.php collation --court     une ligne par serveur concerné (start.sh)
 *    php api/outils/postgresql.php collation --reparer   répare les bases appartenant au rôle de Larka
 *                                                        (code 0 : tout est réparé, 4 : il en reste)
 *    php api/outils/postgresql.php collation --liste-su  bases système (template1, postgres) d'un serveur
 *                                                        local à réparer en administrateur : « port<TAB>base »
 *    php api/outils/postgresql.php connexion | createdb  base principale vue par Larka : OK / ERREUR: …,
 *                                                        droit de créer des bases : YES / NO / ERR
 *
 *  Serveurs examinés : ceux de la base principale et du registre Super Admin
 *  (config.json + .env). Bases concernées : celles du rôle de Larka (base
 *  principale, registre, tenants créés par Larka) et les bases système
 *  template1 et postgres. Celles d'autres applications sont signalées dans le
 *  rapport, jamais modifiées, et ne comptent pas dans le code de retour.
 *
 *  PostgreSQL 14 et antérieurs ne suivent pas la version de collation des
 *  bases : rien à vérifier.
 * ═══════════════════════════════════════════════════════════════════════════
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$racine = dirname(__DIR__, 2);
require_once $racine . '/api/ConfigCrypto.php';
require_once $racine . '/api/env.php';
require_once $racine . '/api/EnvFile.php';

const PG_BASES_SYSTEME = ['template1', 'postgres'];

/** config.json et secrets de .env, comme api/config.php — sans session ni connexion. */
function pg_config(string $racine): array
{
    $f = $racine . '/config.json';
    if (!is_file($f)) { fwrite(STDERR, "config.json introuvable : lancez d'abord ./start.sh setup.\n"); exit(2); }
    $t = (string)file_get_contents($f);
    if (str_starts_with($t, "\xEF\xBB\xBF")) $t = substr($t, 3);
    $cfg = json_decode($t, true);
    if (!is_array($cfg)) { fwrite(STDERR, "config.json illisible (JSON invalide).\n"); exit(2); }
    try { ConfigCrypto::decryptSensitiveFields($cfg); } catch (\Throwable $e) { /* valeur laissée telle quelle */ }
    loadEnv();
    foreach (EnvFile::allEnvMap() as $chemin => $nom) {
        $v = env($nom, null);
        if ($v === null || $v === '') continue;
        [$s, $k] = explode('.', $chemin, 2);
        if (!isset($cfg[$s]) || !is_array($cfg[$s])) $cfg[$s] = [];
        $cfg[$s][$k] = $v;
    }
    return $cfg;
}

/** Serveurs PostgreSQL de la configuration, sans doublon (hôte, port, rôle). */
function pg_serveurs(array $cfg): array
{
    $serveurs = [];
    foreach (['base_de_donnees' => 'gmao', 'superadmin_db' => 'gmao_admin'] as $section => $baseDefaut) {
        $c = $cfg[$section] ?? null;
        if (!is_array($c) || strtolower((string)($c['driver'] ?? 'sqlite')) !== 'pgsql') continue;
        $c = [
            'host' => (string)(($c['host'] ?? '') ?: '127.0.0.1'), 'port' => (int)(($c['port'] ?? 0) ?: 5432),
            'dbname' => (string)(($c['dbname'] ?? '') ?: $baseDefaut), 'user' => (string)($c['user'] ?? ''),
            'password' => (string)($c['password'] ?? ''), 'sslmode' => (string)(($c['sslmode'] ?? '') ?: 'prefer'),
        ];
        $serveurs[strtolower($c['host']) . ':' . $c['port'] . ':' . $c['user']] ??= $c;
    }
    return array_values($serveurs);
}

function pg_connexion(array $c, string $base): PDO
{
    $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=%s;connect_timeout=5', $c['host'], $c['port'], $base, $c['sslmode']);
    $pdo = new PDO($dsn, $c['user'], $c['password'] === '' ? null : $c['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
}

function pg_id(string $nom): string { return '"' . str_replace('"', '""', $nom) . '"'; }

function pg_local(string $hote): bool
{
    return in_array(strtolower($hote), ['127.0.0.1', 'localhost', '::1'], true) || str_starts_with($hote, '/');
}

/**
 * Bases du serveur dont la version de collation enregistrée diffère de celle
 * du système. null : PostgreSQL < 15 (version non suivie).
 */
function pg_ecarts(PDO $pdo): ?array
{
    if ((int)$pdo->query('SHOW server_version_num')->fetchColumn() < 150000) return null;
    return $pdo->query(
        "SELECT datname, datcollversion AS enregistree, pg_database_collation_actual_version(oid) AS systeme,
                pg_get_userbyid(datdba) AS proprietaire, pg_get_userbyid(datdba) = current_user AS a_nous
           FROM pg_database
          WHERE datallowconn AND datcollversion IS NOT NULL
            AND pg_database_collation_actual_version(oid) IS NOT NULL
            AND datcollversion <> pg_database_collation_actual_version(oid)
          ORDER BY datname")->fetchAll(PDO::FETCH_ASSOC);
}

/** Message d'erreur PDO sans son préfixe SQLSTATE. */
function pg_msg(\Throwable $e): string
{
    $m = preg_replace('/^SQLSTATE\[\w+\]:?\s*(\[\d+\]\s*)?/', '', $e->getMessage());
    $m = preg_replace('/^[A-Za-z][\w \/-]*:\s*\d+\s+/', '', (string)$m);      // « Unique violation: 7 »
    return trim((string)preg_replace('/^(ERROR|ERREUR|FATAL):\s*/', '', (string)$m));
}

// ── Arguments ──────────────────────────────────────────────────────────────
$args = array_slice($argv, 1);
$commande = $args[0] ?? 'aide';

// « connexion » / « createdb » : base principale telle que Larka l'utilisera
// (start.sh doctor / setup). Sortie : OK | ERREUR: … — YES | NO | ERR.
if ($commande === 'connexion' || $commande === 'createdb') {
    $c = pg_config($racine)['base_de_donnees'] ?? [];
    if (!is_array($c) || strtolower((string)($c['driver'] ?? 'sqlite')) !== 'pgsql') { echo $commande === 'connexion' ? 'OK' : 'YES'; exit(0); }
    $s = pg_serveurs(['base_de_donnees' => $c])[0];
    try {
        $pdo = pg_connexion($s, $s['dbname']);
        if ($commande === 'connexion') { echo 'OK'; exit(0); }
        echo $pdo->query('SELECT rolcreatedb OR rolsuper FROM pg_roles WHERE rolname = current_user')->fetchColumn() ? 'YES' : 'NO';
    } catch (\Throwable $e) {
        echo $commande === 'connexion' ? 'ERREUR: ' . pg_msg($e) . " ({$s['user']}@{$s['host']}:{$s['port']}/{$s['dbname']})" : 'ERR';
    }
    exit(0);
}
if ($commande !== 'collation') {
    echo "Usage : php api/outils/postgresql.php collation [--court | --reparer | --liste-su] | connexion | createdb\n";
    exit($commande === 'aide' || $commande === '--help' ? 0 : 2);
}
$court   = in_array('--court', $args, true);
$reparer = in_array('--reparer', $args, true);
$listeSu = in_array('--liste-su', $args, true);

if (!extension_loaded('pdo_pgsql')) {
    if (!$court && !$listeSu) echo "Extension PHP pdo_pgsql absente : rien à vérifier.\n";
    exit(2);
}
$serveurs = pg_serveurs(pg_config($racine));
if (!$serveurs) {
    if (!$court && !$listeSu) echo "Cette installation n'utilise pas PostgreSQL : rien à vérifier.\n";
    exit(2);
}

$code = 0;
$restent = 0;
foreach ($serveurs as $c) {
    $ou = "{$c['host']}:{$c['port']}";
    try {
        $pdo = pg_connexion($c, $c['dbname']);
        $ecarts = pg_ecarts($pdo);
        $super = (bool)$pdo->query('SELECT rolsuper FROM pg_roles WHERE rolname = current_user')->fetchColumn();
    } catch (\Throwable $e) {
        if (!$court && !$listeSu) echo "PostgreSQL $ou : connexion impossible — " . pg_msg($e) . "\n";
        $code = max($code, 2);
        continue;
    }
    if ($ecarts === null) {
        if (!$court && !$listeSu) echo "PostgreSQL $ou : version 14 ou antérieure — la version de collation des bases n'y est pas suivie.\n";
        continue;
    }

    if ($listeSu) {
        // Bases système d'un serveur local, que le rôle de Larka ne possède pas.
        if (!pg_local($c['host'])) continue;
        foreach ($ecarts as $r) {
            if (!$r['a_nous'] && !$super && in_array($r['datname'], PG_BASES_SYSTEME, true)) echo $c['port'] . "\t" . $r['datname'] . "\n";
        }
        continue;
    }

    if (!$ecarts) {
        if (!$court) echo "PostgreSQL $ou : collation à jour (aucune base à réindexer).\n";
        continue;
    }
    // Bases d'autres applications : signalées seulement (rapport complet).
    $autres = array_values(array_filter($ecarts, fn($r) => !$r['a_nous'] && !in_array($r['datname'], PG_BASES_SYSTEME, true)));
    $ecarts = array_values(array_filter($ecarts, fn($r) => $r['a_nous'] || in_array($r['datname'], PG_BASES_SYSTEME, true)));
    if (!$ecarts) {
        if (!$court) {
            echo "PostgreSQL $ou : bases de Larka à jour.";
            echo $autres ? ' Bases d\'autres applications à réindexer par leur administrateur : ' . implode(', ', array_column($autres, 'datname')) . ".\n" : "\n";
        }
        continue;
    }
    $versions = implode(', ', array_unique(array_map(fn($r) => "{$r['enregistree']} → {$r['systeme']}", $ecarts)));
    $noms = array_column($ecarts, 'datname');

    if ($court) {
        echo "PostgreSQL $ou : " . count($ecarts) . ' base(s) à réindexer (collation ' . $versions . ') : ' . implode(', ', $noms) . "\n";
        $code = max($code, 3);
        continue;
    }

    echo "PostgreSQL $ou : le système fournit une autre version de collation que celle des bases (" . $versions . ").\n";
    if (!$reparer) {
        foreach ($ecarts as $r) {
            echo "  - {$r['datname']} (propriétaire : {$r['proprietaire']})\n";
        }
        if ($autres) echo "  Autres applications (non modifiées par Larka) : " . implode(', ', array_column($autres, 'datname')) . "\n";
        echo "  Remède, sans toucher aux données : ./start.sh doctor --fix\n";
        $code = max($code, 3);
        continue;
    }

    // ── Réparation ─────────────────────────────────────────────────────────
    foreach ($ecarts as $r) {
        $nom = (string)$r['datname'];
        if (!$r['a_nous'] && !$super) {
            // Bases système : réservées à l'administrateur PostgreSQL.
            $restent++;
            echo "  · $nom : base système — à réparer en administrateur PostgreSQL"
               . (pg_local($c['host']) ? " (start.sh s'en charge sur ce serveur).\n"
                                       : " : REINDEX DATABASE " . pg_id($nom) . "; puis ALTER DATABASE " . pg_id($nom) . " REFRESH COLLATION VERSION;\n");
            continue;
        }
        $t0 = microtime(true);
        try {
            $cible = $nom === $c['dbname'] ? $pdo : pg_connexion($c, $nom);
            // REINDEX DATABASE ne vise que la base où l'on est connecté.
            $cible->exec('REINDEX DATABASE ' . pg_id($nom));
            if ($cible !== $pdo) $cible = null;
            $pdo->exec('ALTER DATABASE ' . pg_id($nom) . ' REFRESH COLLATION VERSION');
            printf("  ✓ %s : index reconstruits, version %s enregistrée (%.1f s)\n", $nom, $r['systeme'], microtime(true) - $t0);
        } catch (\Throwable $e) {
            $restent++;
            echo "  ✗ $nom : " . pg_msg($e) . "\n"
               . "    La version n'a pas été enregistrée : le problème reste signalé. Si PostgreSQL parle de doublons\n"
               . "    (« could not create unique index »), des lignes en double se sont glissées pendant que l'index\n"
               . "    était faux : supprimez-les (sauvegarde d'abord), puis relancez ./start.sh doctor --fix.\n";
        }
    }
}

if ($reparer) exit($restent > 0 ? 4 : ($code === 2 ? 2 : 0));
exit($code);

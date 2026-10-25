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
 *  Larka — Vérification des prérequis
 * ═══════════════════════════════════════════════════════════════════════════
 *
 *  Une seule liste d'exigences, écrite dans version.json (« prerequis ») et
 *  donc PORTÉE PAR CHAQUE VERSION : avant d'installer une mise à jour, Larka
 *  confronte l'environnement aux exigences de la NOUVELLE version, pas aux
 *  siennes. Les quatre portes d'entrée l'utilisent :
 *    - start.sh (démarrage, doctor), deploy/install.sh (production) ;
 *    - le lanceur Windows (Larka.cmd) ;
 *    - la mise à jour depuis l'interface (api/MiseAJour.php) ;
 *    - la page d'aide, qui l'affiche.
 *
 *  Chaque point rend un statut :
 *    ok           rien à faire ;
 *    avertissement Larka fonctionne, mais quelque chose manque ou vieillit
 *                 (extension facultative, version sans correctifs de sécurité,
 *                 version plus récente que celles éprouvées) ;
 *    bloquant     Larka ne peut pas fonctionner : on n'installe pas, on ne
 *                 démarre pas, et on dit quoi faire.
 *
 *  Sans dépendance : utilisable avant que config.php ne soit chargeable (une
 *  installation neuve, un PHP trop ancien pour le reste du code).
 * ═══════════════════════════════════════════════════════════════════════════
 */

final class LarkaPrerequis
{
    /** Exigences par défaut, si version.json n'en porte pas (versions antérieures). */
    public const DEFAUT = [
        'php'         => '8.1',
        'php_eprouve' => '8.5',
        'extensions'  => [
            'requises'     => ['pdo' => 'accès aux bases', 'mbstring' => 'textes accentués', 'ctype' => 'contrôle des saisies',
                               'openssl' => 'chiffrement des secrets', 'session' => 'connexion', 'fileinfo' => 'type des documents déposés'],
            'recommandees' => ['curl' => 'mises à jour, OAuth, facteurs carbone', 'zip' => 'modules, thèmes, mises à jour',
                               'sodium' => 'signature des mises à jour', 'intl' => 'recherche sans accents',
                               'dom' => 'import de fichiers XML', 'gd' => 'images'],
            'par_base'     => ['sqlite' => 'pdo_sqlite', 'pgsql' => 'pdo_pgsql', 'mysql' => 'pdo_mysql'],
        ],
        'sqlite'      => '3.24',
        'sqlite_sauvegarde' => '3.27',
        'postgresql'  => '10',
        'mariadb'     => '10.3',
        'mysql'       => '5.7',
    ];

    /** Fin des correctifs de sécurité (php.net/supported-versions, postgresql.org/support/versioning). */
    private const FIN_PHP = ['8.1' => '2025-12-31', '8.2' => '2026-12-31', '8.3' => '2027-12-31', '8.4' => '2028-12-31', '8.5' => '2029-12-31'];
    private const FIN_PG  = ['10' => '2022-11-10', '11' => '2023-11-09', '12' => '2024-11-21', '13' => '2025-11-13',
                             '14' => '2026-11-12', '15' => '2027-11-11', '16' => '2028-11-09', '17' => '2029-11-08', '18' => '2030-11-14'];

    /** Exigences d'une version (version.json, de l'installation ou d'un paquet). */
    public static function exigences(?array $versionJson = null): array
    {
        if ($versionJson === null) {
            $f = dirname(__DIR__) . '/version.json';
            $versionJson = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
        }
        $p = is_array($versionJson['prerequis'] ?? null) ? $versionJson['prerequis'] : [];
        $e = array_replace_recursive(self::DEFAUT, $p);
        // Ancien format : « php_min » à la racine de version.json.
        if (empty($p['php']) && !empty($versionJson['php_min']) && preg_match('/^\d+\.\d+/', (string)$versionJson['php_min'], $m)) {
            $e['php'] = $m[0];
        }
        return $e;
    }

    /**
     * Vérifie l'environnement.
     *
     * @param array $base  ['driver' => …, + connexion] pour vérifier aussi le
     *                     serveur de base (null : non vérifié)
     * @param ?string $racine  dossier de l'installation (droits d'écriture)
     * @return array{ok: bool, bloquants: int, avertissements: int, points: list<array>}
     */
    public static function verifier(?array $exigences = null, ?array $base = null, ?string $racine = null): array
    {
        $e = $exigences ?? self::exigences();
        $points = [];
        $ajouter = static function (string $id, string $libelle, string $statut, string $detail = '', string $conseil = '') use (&$points): void {
            $points[] = compact('id', 'libelle', 'statut', 'detail', 'conseil');
        };

        // ── PHP ───────────────────────────────────────────────────────────────
        $branche = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        if (version_compare(PHP_VERSION, $e['php'], '<')) {
            $ajouter('php', 'PHP ' . PHP_VERSION, 'bloquant', "Larka demande PHP {$e['php']} ou plus récent.",
                "Installez PHP {$e['php']} ou plus récent, puis relancez.");
        } elseif (!empty($e['php_eprouve']) && version_compare($branche, $e['php_eprouve'], '>')) {
            $ajouter('php', 'PHP ' . PHP_VERSION, 'avertissement', "Plus récent que les versions éprouvées (jusqu'à {$e['php_eprouve']}).",
                'Larka devrait fonctionner ; signalez toute anomalie.');
        } elseif (isset(self::FIN_PHP[$branche]) && date('Y-m-d') > self::FIN_PHP[$branche]) {
            $ajouter('php', 'PHP ' . PHP_VERSION, 'avertissement', 'Plus de correctifs de sécurité depuis le ' . self::dateFr(self::FIN_PHP[$branche]) . '.',
                'Prévoyez de passer à une version de PHP encore suivie.');
        } else {
            $ajouter('php', 'PHP ' . PHP_VERSION, 'ok', isset(self::FIN_PHP[$branche]) ? 'Suivi jusqu\'au ' . self::dateFr(self::FIN_PHP[$branche]) . '.' : '');
        }

        // ── Extensions ────────────────────────────────────────────────────────
        $manque = [];
        foreach ((array)$e['extensions']['requises'] as $ext => $usage) {
            if (!extension_loaded($ext)) $manque[] = "$ext ($usage)";
        }
        $driver = strtolower((string)($base['driver'] ?? ''));
        $pdoBase = $driver !== '' ? ($e['extensions']['par_base'][$driver === 'mariadb' ? 'mysql' : $driver] ?? null) : null;
        if ($pdoBase && !extension_loaded($pdoBase)) $manque[] = "$pdoBase (base $driver)";
        $ajouter('extensions', 'Extensions PHP indispensables', $manque ? 'bloquant' : 'ok',
            $manque ? 'Absente(s) : ' . implode(', ', $manque) . '.' : implode(', ', array_keys((array)$e['extensions']['requises'])) . ($pdoBase ? ", $pdoBase" : ''),
            $manque ? 'Installez-les (paquets php-…), puis redémarrez PHP.' : '');
        $facultatives = [];
        foreach ((array)$e['extensions']['recommandees'] as $ext => $usage) {
            if (!extension_loaded($ext)) $facultatives[] = "$ext ($usage)";
        }
        $ajouter('extensions_facultatives', 'Extensions PHP conseillées', $facultatives ? 'avertissement' : 'ok',
            $facultatives ? 'Absente(s) : ' . implode(', ', $facultatives) . '.' : 'Toutes présentes.',
            $facultatives ? 'Les fonctions citées restent indisponibles tant qu\'elles manquent.' : '');

        // ── SQLite (bibliothèque) : registre Super Admin, base par défaut ─────
        if (extension_loaded('pdo_sqlite')) {
            try {
                $v = (string)(new PDO('sqlite::memory:'))->query('SELECT sqlite_version()')->fetchColumn();
                if (version_compare($v, $e['sqlite'], '<')) {
                    $ajouter('sqlite', "SQLite $v", 'bloquant', "Larka demande SQLite {$e['sqlite']} ou plus récent.", 'Mettez PHP à jour (il embarque sa bibliothèque SQLite).');
                } elseif (version_compare($v, $e['sqlite_sauvegarde'], '<')) {
                    $ajouter('sqlite', "SQLite $v", 'avertissement', "Sauvegarde à chaud impossible avant SQLite {$e['sqlite_sauvegarde']} (VACUUM INTO).", 'Mettez PHP à jour.');
                } else {
                    $ajouter('sqlite', "SQLite $v", 'ok');
                }
            } catch (\Throwable $x) { /* pdo_sqlite inutilisable : signalé par l'extension elle-même */ }
        }

        // ── Serveur de base de données ────────────────────────────────────────
        if ($base !== null && in_array($driver, ['pgsql', 'mysql', 'mariadb'], true)) {
            $ajouter(...self::verifierServeur($e, $base, $driver));
        }

        // ── Fichiers : écriture, place ────────────────────────────────────────
        if ($racine !== null) {
            $nonInscriptibles = [];
            foreach (['data', 'data/logs', 'data/backups'] as $d) {
                $p = $racine . '/' . $d;
                if (is_dir($p) && !is_writable($p)) $nonInscriptibles[] = $d;
                if (!is_dir($p) && is_dir(dirname($p)) && !is_writable(dirname($p))) $nonInscriptibles[] = $d;
            }
            $ajouter('ecriture', 'Droits d\'écriture', $nonInscriptibles ? 'bloquant' : 'ok',
                $nonInscriptibles ? 'Dossier(s) non inscriptible(s) par PHP : ' . implode(', ', $nonInscriptibles) . '.' : 'data/ inscriptible.',
                $nonInscriptibles ? 'Donnez ces dossiers au compte qui exécute PHP (chown).' : '');
            if (function_exists('disk_free_space') && ($libre = @disk_free_space($racine)) !== false) {
                $mo = (int)round($libre / 1048576);
                $ajouter('disque', 'Espace disque', $mo < 500 ? 'avertissement' : 'ok', number_format($mo, 0, ',', ' ') . ' Mo libres.',
                    $mo < 500 ? 'Les sauvegardes et les mises à jour ont besoin de place (500 Mo conseillés).' : '');
            }
            $env = $racine . '/.env';
            if (PHP_OS_FAMILY !== 'Windows' && is_file($env) && (fileperms($env) & 0007)) {
                $ajouter('secrets', 'Fichier .env', 'avertissement', 'Lisible par tous les comptes du système.', 'chmod 600 .env');
            }
        }

        $b = count(array_filter($points, fn($p) => $p['statut'] === 'bloquant'));
        $a = count(array_filter($points, fn($p) => $p['statut'] === 'avertissement'));
        return ['ok' => $b === 0, 'bloquants' => $b, 'avertissements' => $a, 'points' => $points];
    }

    /** Version du serveur PostgreSQL / MariaDB / MySQL, comparée à l'exigence. */
    private static function verifierServeur(array $e, array $base, string $driver): array
    {
        try {
            if ($driver === 'pgsql') {
                $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;connect_timeout=5', $base['host'] ?? '127.0.0.1', (int)($base['port'] ?? 5432), $base['dbname'] ?? 'postgres');
            } else {
                $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s', $base['host'] ?? '127.0.0.1', (int)($base['port'] ?? 3306), $base['dbname'] ?? '');
            }
            $pdo = new PDO($dsn, (string)($base['user'] ?? ''), (string)($base['password'] ?? ''), [PDO::ATTR_TIMEOUT => 5, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $brut = (string)$pdo->query($driver === 'pgsql' ? 'SHOW server_version' : 'SELECT VERSION()')->fetchColumn();
        } catch (\Throwable $x) {
            return ['base', 'Serveur de base de données', 'avertissement', 'Version non vérifiée : connexion impossible pour l\'instant.', ''];
        }
        if ($driver === 'pgsql') {
            $v = preg_match('/^(\d+)(?:\.(\d+))?/', $brut, $m) ? $m[1] . (isset($m[2]) && (int)$m[1] < 10 ? '.' . $m[2] : '') : $brut;
            $majeure = (string)(int)$v;
            if (version_compare($v, $e['postgresql'], '<')) {
                return ['base', "PostgreSQL $v", 'bloquant', "Larka demande PostgreSQL {$e['postgresql']} ou plus récent.", 'Mettez PostgreSQL à jour (sauvegarde puis pg_upgrade).'];
            }
            if (isset(self::FIN_PG[$majeure]) && date('Y-m-d') > self::FIN_PG[$majeure]) {
                return ['base', "PostgreSQL $v", 'avertissement', 'Plus de correctifs de sécurité depuis le ' . self::dateFr(self::FIN_PG[$majeure]) . '.', 'Prévoyez une mise à jour de PostgreSQL.'];
            }
            return ['base', "PostgreSQL $v", 'ok', isset(self::FIN_PG[$majeure]) ? 'Suivi jusqu\'au ' . self::dateFr(self::FIN_PG[$majeure]) . '.' : ''];
        }
        $estMaria = stripos($brut, 'mariadb') !== false;
        $v = preg_match('/^(\d+\.\d+(?:\.\d+)?)/', $brut, $m) ? $m[1] : $brut;
        $min = $estMaria ? $e['mariadb'] : $e['mysql'];
        $nom = $estMaria ? 'MariaDB' : 'MySQL';
        return version_compare($v, $min, '<')
            ? ['base', "$nom $v", 'avertissement', "Versions éprouvées : $nom $min ou plus récent.", "Mettez $nom à jour."]
            : ['base', "$nom $v", 'ok', ''];
    }

    private static function dateFr(string $d): string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) ? "{$m[3]}/{$m[2]}/{$m[1]}" : $d;
    }

    /** Texte d'un rapport (outil en ligne de commande, lanceur). */
    public static function texte(array $r, bool $court = false): string
    {
        $l = [];
        foreach ($r['points'] as $p) {
            if ($court && $p['statut'] === 'ok') continue;
            $s = ['ok' => '✓', 'avertissement' => '⚠', 'bloquant' => '✗'][$p['statut']] ?? '·';
            $l[] = "$s {$p['libelle']}" . ($p['detail'] !== '' ? " — {$p['detail']}" : '') . ($p['conseil'] !== '' ? " → {$p['conseil']}" : '');
        }
        return implode("\n", $l);
    }
}

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
 *  Larka pour Windows — le lanceur
 * ═══════════════════════════════════════════════════════════════════════════
 *
 *  Appelé par Larka.cmd (à la racine), avec le PHP que Larka.cmd installe dans
 *  le dossier php\ — le PHP officiel de php.net, simplement décompressé : rien
 *  n'est installé dans Windows, rien n'est écrit hors du dossier de Larka.
 *
 *    Larka.cmd                         démarre Larka (prépare tout la 1re fois)
 *    Larka.cmd menu                    toutes les actions, au choix
 *    Larka.cmd demarrer [options]      --port N · --reseau · --local · --sans-navigateur
 *    Larka.cmd demo [archive]          installe la démonstration « Ville de Larkaville »
 *    Larka.cmd arreter | etat          arrête Larka / indique s'il tourne
 *    Larka.cmd raccourci               raccourcis sur le Bureau et dans le menu Démarrer
 *    Larka.cmd diagnostic              vérifie l'installation
 *    Larka.cmd php [archive.zip]       installe ou met à jour PHP (traité par Larka.cmd)
 *    Larka.cmd aide
 *
 *  Le serveur est celui qu'intègre PHP (php -S), avec le routeur de Larka
 *  (router.php) : sous Windows, il ne traite qu'une requête à la fois. Il
 *  convient à un poste ou à une petite équipe ; pour un service partagé par
 *  beaucoup d'utilisateurs, préférez un serveur Linux (deploy/install.sh).
 *
 *  Ce script fonctionne aussi sous Linux et macOS, mais start.sh y fait
 *  davantage (PostgreSQL, production nginx, systemd).
 * ═══════════════════════════════════════════════════════════════════════════
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('LK_RACINE', dirname(__DIR__, 2));
define('LK_WIN', PHP_OS_FAMILY === 'Windows');
define('LK_SEP', DIRECTORY_SEPARATOR);
define('LK_PORT_DEFAUT', 8000);
define('LK_LICENCE', LK_RACINE . '/data/.license_accepted');      // même marqueur que start.sh
define('LK_ETAT', LK_RACINE . '/data/run/larka-windows.json');
define('LK_JOURNAL_SERVEUR', LK_RACINE . '/data/logs/serveur.log');
// Code de sortie qui demande à Larka.cmd de (ré)installer PHP : PHP ne peut pas
// remplacer ses propres fichiers pendant qu'il s'exécute.
define('LK_SORTIE_MAJ_PHP', 10);
// Fonctions refusées au serveur, comme dans le pool PHP-FPM de production
// (deploy/php-fpm-gmao.conf). Le lanceur, lui, en a besoin : ce n'est pas le
// même processus.
define('LK_FONCTIONS_INTERDITES', 'exec,passthru,shell_exec,system,popen,proc_open,pcntl_exec,dl');

require_once LK_RACINE . '/api/Chemins.php';

// Tout se fait depuis le dossier de Larka, où qu'il soit : les commandes lancées
// (serveur, scripts) s'y écrivent en chemins relatifs. Voir lk_commande_php().
@chdir(LK_RACINE);

// ═══════════════════════════════════════════════════════════════════════════
//  Affichage
// ═══════════════════════════════════════════════════════════════════════════

function lk_couleurs(): bool
{
    static $oui = null;
    if ($oui !== null) return $oui;
    $oui = getenv('NO_COLOR') === false && function_exists('stream_isatty') && @stream_isatty(STDOUT);
    if ($oui && LK_WIN) {
        $oui = function_exists('sapi_windows_vt100_support') && @sapi_windows_vt100_support(STDOUT, true);
    }
    return $oui;
}
function lk_c(string $code, string $t): string { return lk_couleurs() ? "\033[{$code}m{$t}\033[0m" : $t; }
// Symboles du jeu WGL4 : présents dans les polices de la console Windows
// (Consolas, Lucida Console), contrairement à ✓ ou ⚠.
function info(string $m): void   { echo lk_c('1;36', '►') . " $m\n"; }
function ok(string $m): void     { echo lk_c('1;32', '√') . " $m\n"; }
function alerte(string $m): void { echo lk_c('1;33', '!') . " $m\n"; }
function echec(string $m, int $code = 1): never
{
    fwrite(STDERR, lk_c('1;31', '×') . " $m\n");
    exit($code);
}
function titre(string $m): void
{
    $l = str_repeat('═', 66);
    echo "\n" . lk_c('36', $l) . "\n" . lk_c('1;36', "  $m") . "\n" . lk_c('36', $l) . "\n\n";
}
/** Chemin tel que Windows l'écrit (pour les messages). */
function lk_aff(string $p): string { return LK_WIN ? str_replace('/', '\\', $p) : $p; }
/** Chemin relatif à la racine de Larka, pour les messages. */
function lk_rel(string $p): string
{
    $r = str_replace('\\', '/', LK_RACINE) . '/';
    $q = str_replace('\\', '/', $p);
    return lk_aff(stripos($q, $r) === 0 ? substr($q, strlen($r)) : $p);
}

function lk_interactif(): bool
{
    return !function_exists('stream_isatty') || @stream_isatty(STDIN);
}
function demander(string $question, string $defaut = ''): string
{
    echo $question . ' ';
    $r = fgets(STDIN);
    if ($r === false) { echo "\n"; return $defaut; }
    $r = trim($r);
    return $r === '' ? $defaut : $r;
}
function confirmer(string $question, bool $defaut, array $o): bool
{
    if (!empty($o['oui'])) return true;
    if (!lk_interactif()) return $defaut;
    $r = strtolower(demander($question . ($defaut ? ' [O/n]' : ' [o/N]')));
    if ($r === '') return $defaut;
    return $r[0] === 'o' || $r[0] === 'y';
}

// ═══════════════════════════════════════════════════════════════════════════
//  Fichiers et configuration
// ═══════════════════════════════════════════════════════════════════════════

/** Écrit un fichier d'un coup : fichier temporaire, puis remplacement. */
function lk_ecrire(string $f, string $contenu): void
{
    $tmp = $f . '.tmp-' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $contenu) === false) echec('Écriture impossible : ' . lk_aff($f));
    if (!@rename($tmp, $f)) {
        @unlink($tmp);
        echec('Écriture impossible : ' . lk_aff($f) . ' (fichier ouvert par un autre programme ?)');
    }
}

function lk_lire_json(string $f): ?array
{
    if (!is_file($f)) return null;
    $t = (string)@file_get_contents($f);
    $t = preg_replace('/^\xEF\xBB\xBF/', '', $t);      // BOM du Bloc-notes
    $j = json_decode($t, true);
    return is_array($j) ? $j : null;
}

function lk_config(): array { return lk_lire_json(LK_RACINE . '/config.json') ?? []; }

function lk_config_ecrire(array $c): void
{
    lk_ecrire(LK_RACINE . '/config.json',
        json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
    @chmod(LK_RACINE . '/config.json', 0640);
}

/** Version de Larka (version.json). */
function lk_version(): string
{
    $v = lk_lire_json(LK_RACINE . '/version.json');
    return (string)($v['version'] ?? '?');
}

/** Base principale d'après config.json (mêmes défauts que api/config.php). */
function lk_base(): array
{
    $b = lk_config()['base_de_donnees'] ?? [];
    $b = is_array($b) ? $b : [];
    $driver = strtolower((string)($b['driver'] ?? 'sqlite'));
    $chemin = (string)(($b['path'] ?? '') ?: 'data/gmao.db');
    if (!larka_chemin_absolu($chemin)) $chemin = LK_RACINE . LK_SEP . str_replace('/', LK_SEP, $chemin);
    // PostgreSQL : mêmes défauts que api/config.php ; mot de passe dans .env.
    $mdp = lk_env_valeur('GMAO_DB_PASSWORD');
    if ($mdp === '') $mdp = (string)($b['password'] ?? '');
    return ['driver' => $driver, 'chemin' => $chemin,
            'host' => (string)(($b['host'] ?? '') ?: '127.0.0.1'), 'port' => (int)(($b['port'] ?? 0) ?: 5432),
            'dbname' => (string)(($b['dbname'] ?? '') ?: 'gmao'), 'user' => (string)(($b['user'] ?? '') ?: 'gmao'),
            'password' => $mdp, 'sslmode' => (string)(($b['sslmode'] ?? '') ?: 'prefer')];
}

/** Valeur d'une clé de .env (guillemets retirés), '' si absente. */
function lk_env_valeur(string $cle): string
{
    $f = LK_RACINE . '/.env';
    if (!is_file($f)) return '';
    foreach (preg_split('/\r\n|\n|\r/', preg_replace('/^\xEF\xBB\xBF/', '', (string)file_get_contents($f))) as $l) {
        if (preg_match('/^\s*' . preg_quote($cle, '/') . '\s*=\s*(.*)$/', $l, $m)) {
            $v = trim($m[1]);
            if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[strlen($v) - 1] === $v[0]) $v = substr($v, 1, -1);
            return $v;
        }
    }
    return '';
}

/** Connexion PostgreSQL à la base de Larka (mot de passe vide : libpq lit PGPASSWORD / .pgpass). */
function lk_pdo_pg(array $b): PDO
{
    if (!extension_loaded('pdo_pgsql')) echec('Extension PHP « pdo_pgsql » absente.');
    $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=%s;connect_timeout=5', $b['host'], $b['port'], $b['dbname'], $b['sslmode']);
    $pdo = new PDO($dsn, $b['user'], $b['password'] === '' ? null : $b['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
}

/** Chemin d'un outil en ligne de commande (psql, pg_dump), ou null. */
function lk_outil(string $nom): ?string
{
    foreach (explode(PATH_SEPARATOR, (string)getenv('PATH')) as $d) {
        foreach (LK_WIN ? [$nom . '.exe'] : [$nom] as $n) {
            $f = rtrim($d, '/\\') . LK_SEP . $n;
            if ($d !== '' && is_file($f) && is_executable($f)) return $f;
        }
    }
    return null;
}

/** Environnement d'un outil PostgreSQL : le mot de passe passe par PGPASSWORD, jamais par la ligne de commande. */
function lk_env_pg(array $b): array
{
    $env = getenv();
    if ($b['password'] !== '') $env['PGPASSWORD'] = $b['password'];
    $env['PGSSLMODE'] = $b['sslmode'];
    $env['PGCONNECT_TIMEOUT'] = '5';
    return $env;
}

function lk_pdo_sqlite(string $f): ?PDO
{
    if (!is_file($f) || !extension_loaded('pdo_sqlite')) return null;
    try {
        $pdo = new PDO('sqlite:' . $f);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA busy_timeout = 3000');
        return $pdo;
    } catch (Throwable $e) {
        return null;
    }
}

// ═══════════════════════════════════════════════════════════════════════════
//  Installation : licence, PHP, .env, config.json, dossiers
// ═══════════════════════════════════════════════════════════════════════════

/** Acceptation de la licence — même marqueur et mêmes règles que start.sh. */
function lk_licence(array $o): void
{
    if (is_file(LK_LICENCE)) return;
    @mkdir(dirname(LK_LICENCE), 0775, true);
    $qui = (getenv('USERNAME') ?: getenv('USER') ?: '?') . '@' . (gethostname() ?: '?');
    $tampon = 'date=' . gmdate('Y-m-d\TH:i:s\Z') . ' by=' . $qui;

    $env = strtolower((string)(getenv('LARKA_LICENSE_ACCEPTED') ?: getenv('GMAO_LICENSE_ACCEPTED') ?: ''));
    if (in_array($env, ['1', 'true', 'oui', 'yes', 'y', 'o'], true)) {
        @file_put_contents(LK_LICENCE, "accepted=env $tampon\n");
        return;
    }
    if (!empty($o['oui'])) {
        @file_put_contents(LK_LICENCE, "accepted=yes-flag $tampon\n");
        return;
    }
    if (!lk_interactif()) {
        echec("Licence non acceptée. En mode non interactif, définissez LARKA_LICENSE_ACCEPTED=1 ou ajoutez "
            . "l'option -y (l'organisation accepte alors la licence pour son compte — voir LICENSE, §16).");
    }
    titre("LICENCE D'UTILISATION Larka — licence propriétaire");
    echo "  Logiciel propriétaire — © 2025-2026 Mickaël Larcin, tous droits réservés.\n";
    echo "  Conditions complètes : fichier LICENSE (à lire intégralement).\n\n";
    echo "  • L'installation ou l'utilisation du logiciel vaut acceptation de la licence.\n";
    echo "  • En entreprise, l'acceptation est donnée pour le compte de l'organisation,\n";
    echo "    qui informe, forme et supervise ses utilisateurs et répond de\n";
    echo "    leurs actes (voir LICENSE, §16).\n\n";
    $r = demander("  Tapez « j'accepte » pour continuer :");
    $r = str_replace(["\u{2019}", '`'], "'", function_exists('mb_strtolower') ? mb_strtolower($r) : strtolower($r));
    if (in_array($r, ["j'accepte", 'jaccepte', 'j accepte', 'oui', 'yes', 'o'], true)) {
        @file_put_contents(LK_LICENCE, "accepted=interactive $tampon\n");
        ok('Licence acceptée — merci.');
        echo "\n";
        return;
    }
    echec('Licence non acceptée — opération annulée.');
}

/** Extensions PHP dont Larka a besoin (nom => usage). */
function lk_extensions_requises(): array
{
    return [
        'pdo_sqlite' => 'base de données SQLite',
        'mbstring'   => 'textes accentués',
        'intl'       => 'recherche sans accents',
        'curl'       => 'connexions HTTPS (mises à jour, OAuth, facteurs carbone)',
        'openssl'    => 'chiffrement, notifications',
        'sodium'     => 'signature des mises à jour',
        'zip'        => 'modules, thèmes, mises à jour',
        'fileinfo'   => 'type des documents déposés',
        'exif'       => 'images des modules et des thèmes',
    ];
}

function lk_verifier_php(): void
{
    if (version_compare(PHP_VERSION, '8.1.0', '<')) {
        echec('PHP ' . PHP_VERSION . ' est trop ancien : Larka demande PHP 8.1 ou plus récent. '
            . (LK_WIN ? 'Réinstallez-le : Larka.cmd php' : ''));
    }
    $manquantes = [];
    foreach (lk_extensions_requises() as $ext => $usage) {
        if (!extension_loaded($ext)) $manquantes[] = "$ext ($usage)";
    }
    if ($manquantes) {
        echec("Extension(s) PHP manquante(s) : " . implode(', ', $manquantes) . ".\n  "
            . (LK_WIN && !getenv('LARKA_PHP')
                ? 'Réinstallez PHP : Larka.cmd php'
                : 'Activez-les dans le php.ini de ce PHP (' . (php_ini_loaded_file() ?: 'aucun php.ini chargé') . ').'));
    }
    // Prérequis écrits dans version.json (api/Prerequis.php) : ceux de CETTE
    // version — un PHP « assez récent » pour le lanceur peut ne pas l'être pour elle.
    if (is_file(LK_RACINE . '/api/Prerequis.php')) {
        require_once LK_RACINE . '/api/Prerequis.php';
        $r = LarkaPrerequis::verifier(null, ['driver' => lk_base()['driver'] ?? 'sqlite'], LK_RACINE);
        if (!$r['ok']) {
            $bloquants = array_values(array_filter($r['points'], fn($p) => $p['statut'] === 'bloquant'));
            echec("Prérequis non satisfaits :\n  " . implode("\n  ", array_map(
                fn($p) => "{$p['libelle']} — {$p['detail']}" . ($p['conseil'] !== '' ? " {$p['conseil']}" : ''), $bloquants))
                . (LK_WIN && !getenv('LARKA_PHP') ? "\n  Réinstallez PHP : Larka.cmd php" : ''));
        }
    }
}

function lk_dossiers(): void
{
    foreach (['data/logs', 'data/sessions', 'data/backups', 'data/run', 'data/security'] as $d) {
        $p = LK_RACINE . '/' . $d;
        if (!is_dir($p) && !@mkdir($p, 0775, true) && !is_dir($p)) {
            echec('Impossible de créer ' . lk_aff($d) . ' : le dossier de Larka est-il en lecture seule ?');
        }
    }
}

/** .env : une clé secrète aléatoire, rien d'autre (la base SQLite n'a pas de mot de passe). */
function lk_env(bool $bavard): void
{
    $f = LK_RACINE . '/.env';
    $texte = is_file($f) ? preg_replace('/^\xEF\xBB\xBF/', '', (string)file_get_contents($f)) : '';
    $lignes = $texte === '' ? [] : preg_split('/\r\n|\n|\r/', $texte);
    foreach ($lignes as $l) {
        if (preg_match('/^\s*GMAO_SECRET_KEY\s*=\s*(.*)$/', $l, $m) && trim($m[1], " \t\"'") !== '') {
            if ($bavard) ok('.env existant : conservé');
            return;
        }
    }
    $lignes = array_values(array_filter($lignes, fn($l) => !preg_match('/^\s*GMAO_SECRET_KEY\s*=/', $l)));
    while ($lignes && trim((string)end($lignes)) === '') array_pop($lignes);
    if (!$lignes) $lignes[] = '# Larka — Secrets. NE PAS COMMITTER. Documentation : .env.example';
    $lignes[] = 'GMAO_SECRET_KEY=' . bin2hex(random_bytes(32));
    lk_ecrire($f, implode("\n", $lignes) . "\n");
    @chmod($f, 0600);
    ok('.env créé (clé secrète aléatoire)');
}

/** config.json pour un poste Windows : SQLite, ce poste seulement. */
function lk_config_initiale(bool $bavard): void
{
    $f = LK_RACINE . '/config.json';
    if (is_file($f)) {
        $t = (string)file_get_contents($f);
        if (!str_contains($t, 'CHANGER_CE_MOT_DE_PASSE') && !str_contains($t, 'GENERER_UNE_CLE')) {
            if (lk_lire_json($f) === null) {
                echec('config.json est illisible (JSON invalide). Corrigez-le, ou renommez-le pour que Larka en crée un neuf.');
            }
            if ($bavard) ok('config.json existant : conservé');
            return;
        }
        $copie = $f . '.bak-' . date('Ymd-His');
        @copy($f, $copie);
        alerte('config.json contenait des valeurs à remplacer : régénéré (ancien fichier : ' . basename($copie) . ')');
    }
    lk_config_ecrire([
        '_' => 'Configuration créée par Larka.cmd (Windows). Secrets : .env (voir .env.example). '
             . 'Modèle complet et commenté : config.example.json',
        'serveur'         => ['host' => '127.0.0.1', 'port' => LK_PORT_DEFAUT, 'env' => 'prod'],
        'base_de_donnees' => ['driver' => 'sqlite', 'path' => 'data/gmao.db'],
        'superadmin_db'   => ['driver' => 'sqlite', 'path' => 'data/superadmin.db'],
        'logs'            => ['actif' => true, 'dossier' => 'data/logs', 'niveau' => 'warning', 'rotation_jours' => 30],
        'sharepoint'      => ['actif' => false],
        'microsoft_oauth' => ['actif' => false],
        'google_oauth'    => ['actif' => false],
        'plans_presence'  => ['actif' => false],
        'push'            => ['actif' => false],
        'assistant'       => ['actif' => false],
        'extensions'      => ['actif' => false],
    ]);
    ok('config.json créé (base SQLite : ' . lk_aff('data/gmao.db') . ', accès depuis ce poste seulement)');
}

/** Tout ce qu'il faut avant de démarrer ; muet si tout est déjà là. */
function lk_preparer(array $o): void
{
    lk_licence($o);
    lk_verifier_php();
    lk_dossiers();
    lk_env(false);
    lk_config_initiale(false);
}

function cmd_installer(array $o): int
{
    titre('Larka ' . lk_version() . ' — installation');
    lk_licence($o);
    lk_verifier_php();
    ok('PHP ' . PHP_VERSION . ' et ses extensions');
    lk_dossiers();
    ok('Dossiers de données prêts (' . lk_aff('data/') . ')');
    lk_env(true);
    lk_config_initiale(true);
    lk_avertir_emplacement();
    echo "\n";
    ok('Installation prête. Démarrez Larka : double-cliquez sur Larka.cmd');
    return 0;
}

/** Mises en garde sur l'emplacement du dossier (SQLite n'aime ni le réseau ni la synchronisation). */
function lk_avertir_emplacement(): void
{
    if (function_exists('disk_free_space') && ($libre = @disk_free_space(LK_RACINE)) !== false && $libre < 300 * 1024 * 1024) {
        alerte('Moins de 300 Mo libres sur ce disque : les sauvegardes et les mises à jour pourraient échouer.');
    }
    if (!LK_WIN) return;
    $r = LK_RACINE;
    if (str_starts_with($r, '\\\\')) {
        alerte("Larka est dans un dossier réseau ($r). La base SQLite y risque verrous et corruption : "
            . 'copiez le dossier sur un disque de ce poste, par exemple C:\\Larka.');
        return;
    }
    foreach (['ProgramFiles', 'ProgramFiles(x86)', 'ProgramW6432'] as $v) {
        $d = getenv($v);
        if ($d && stripos($r . '\\', rtrim($d, '\\') . '\\') === 0) {
            alerte("Larka est dans $d : Windows y protège les fichiers, et Larka doit écrire dans son dossier "
                . '(base, journaux). Placez-le plutôt dans C:\\Larka.');
            return;
        }
    }
    foreach (['OneDrive', 'OneDriveCommercial', 'OneDriveConsumer'] as $v) {
        $d = getenv($v);
        if ($d && stripos($r . '\\', rtrim($d, '\\') . '\\') === 0) {
            alerte("Larka est dans un dossier synchronisé par OneDrive ($d). La synchronisation peut verrouiller "
                . 'ou dupliquer la base pendant que Larka s\'en sert : préférez un dossier non synchronisé, par exemple C:\\Larka.');
            return;
        }
    }
}

// ═══════════════════════════════════════════════════════════════════════════
//  Serveur
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Chaîne en UTF-8, comme tout le reste du lanceur.
 *
 * ⚠️ SOUS WINDOWS, PHP_BINARY N'EST PAS EN UTF-8.
 * PHP le lit avec GetModuleFileName() en version « ANSI » : « é » y vaut l'octet
 * E9 (Windows-1252), alors que __DIR__, lui, est en UTF-8 (C3 A9). Or
 * proc_open() convertit la commande d'un bloc : dès qu'un morceau n'est pas de
 * l'UTF-8 valide, il relit TOUTE la commande en ANSI — et l'UTF-8 du dossier de
 * Larka devient « Ã© ». Dans « C:\Users\Hélène Martin\… », le serveur
 * cherchait « HÃ©lÃ¨ne » et refusait de démarrer (« Directory … does not
 * exist »).
 */
function lk_utf8(string $s): string
{
    if ($s === '' || preg_match('//u', $s)) return $s;
    $cp = function_exists('sapi_windows_cp_get') ? (int)sapi_windows_cp_get('ansi') : 1252;
    if (function_exists('sapi_windows_cp_conv')) {
        $c = @sapi_windows_cp_conv($cp, 65001, $s);
        if (is_string($c) && $c !== '') return $c;
    }
    return mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
}

/** Chemin relatif au dossier de Larka quand il s'y trouve (sinon inchangé, en UTF-8). */
function lk_relatif(string $p): string
{
    $p = lk_utf8($p);
    $r = rtrim(str_replace('/', LK_SEP, LK_RACINE), '\\/') . LK_SEP;
    $q = str_replace('/', LK_SEP, $p);
    if (LK_WIN ? stripos($q, $r) === 0 : str_starts_with($q, $r)) return substr($q, strlen($r));
    return $p;
}

/** Le php.exe du dossier php\ de Larka (chemin bâti depuis le dossier, donc en UTF-8), sinon PHP_BINARY. */
function lk_exe_php(): string
{
    $local = LK_RACINE . LK_SEP . 'php' . LK_SEP . (LK_WIN ? 'php.exe' : 'php');
    if (!getenv('LARKA_PHP') && is_file($local)) return $local;
    return lk_utf8(PHP_BINARY);
}

/**
 * Commande pour lancer PHP : même PHP, même php.ini que ce lanceur, puis $args.
 *
 * À lancer avec le dossier de Larka pour dossier courant : php.ini, routeur et
 * scripts y sont désignés en chemins relatifs. La commande ne porte ainsi
 * presque plus le chemin du dossier — seulement celui de php.exe, en UTF-8 —
 * et ne dépend plus de la façon dont Windows écrit ses accents.
 *
 * Sous Windows, php.ini tire ses chemins de la variable LARKA_PHP_DIR, que les
 * processus lancés héritent. Sans php.ini, les valeurs sont passées entre
 * guillemets : sans eux, PHP lit « ( », « ) » ou « & » d'un chemin
 * (« Documents (2026) ») comme une expression, et le tronque.
 */
function lk_commande_php(array $args): array
{
    $cmd = [lk_exe_php()];
    $ini = php_ini_loaded_file();
    if ($ini) {
        array_push($cmd, '-c', lk_relatif($ini));
    } else {
        $cmd[] = '-n';
        foreach (['extension_dir', 'curl.cainfo', 'openssl.cafile'] as $k) {
            $v = (string)ini_get($k);
            if ($v !== '') { $cmd[] = '-d'; $cmd[] = $k . '="' . lk_utf8($v) . '"'; }
        }
        foreach (array_keys(lk_extensions_requises()) as $e) {
            if (extension_loaded($e)) { $cmd[] = '-d'; $cmd[] = "extension=$e"; }
        }
    }
    return array_merge($cmd, $args);
}

/** openssl.cnf livré avec PHP pour Windows (sans lui, OpenSSL ne crée aucune clé). */
function lk_openssl_conf(): void
{
    if (!LK_WIN || getenv('OPENSSL_CONF')) return;
    $cnf = dirname(lk_exe_php()) . '\\extras\\ssl\\openssl.cnf';
    if (is_file($cnf)) putenv('OPENSSL_CONF=' . $cnf);
}

function lk_hote_local(string $hote): string
{
    return in_array($hote, ['0.0.0.0', '::', '[::]', ''], true) ? '127.0.0.1' : $hote;
}
function lk_url(string $hote, int $port): string
{
    return 'http://' . lk_hote_local($hote) . ':' . $port . '/';
}

/** Réponse HTTP simple (null si personne ne répond). */
function lk_http(string $url, int $delai = 5): ?array
{
    $ctx = stream_context_create(['http' => [
        'timeout' => $delai, 'ignore_errors' => true, 'header' => "Accept: application/json\r\n",
    ]]);
    $corps = @file_get_contents($url, false, $ctx);
    if ($corps === false) return null;
    $entetes = function_exists('http_get_last_response_headers')
        ? (http_get_last_response_headers() ?? [])
        : ($http_response_header ?? []);
    $code = (isset($entetes[0]) && preg_match('#\s(\d{3})(\s|$)#', $entetes[0], $m)) ? (int)$m[1] : 0;
    return ['code' => $code, 'corps' => $corps];
}

/** Version de Larka qui répond à cette adresse, ou null. */
function lk_larka_repond(string $hote, int $port): ?string
{
    $r = lk_http(lk_url($hote, $port) . 'api/index.php?action=auth_config', 3);
    if (!$r) return null;
    $j = json_decode($r['corps'], true);
    return (is_array($j) && !empty($j['success']) && isset($j['data']['version'])) ? (string)$j['data']['version'] : null;
}

function lk_port_occupe(string $hote, int $port): bool
{
    $s = @fsockopen(lk_hote_local($hote), $port, $no, $msg, 0.5);
    if ($s) { fclose($s); return true; }
    return false;
}

function lk_etat_lire(): ?array { return lk_lire_json(LK_ETAT); }
function lk_etat_ecrire(array $e): void
{
    @mkdir(dirname(LK_ETAT), 0775, true);
    @file_put_contents(LK_ETAT, json_encode($e, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
}
function lk_etat_effacer(): void { @unlink(LK_ETAT); }

/** Larka en marche ? ['hote','port','version','pid'] ou null. */
function lk_en_marche(): ?array
{
    $e = lk_etat_lire();
    if ($e && isset($e['port'])) {
        $v = lk_larka_repond((string)$e['hote'], (int)$e['port']);
        if ($v !== null) return ['hote' => (string)$e['hote'], 'port' => (int)$e['port'], 'version' => $v, 'pid' => (int)($e['pid'] ?? 0)];
        lk_etat_effacer();                     // fichier d'état d'un arrêt brutal
    }
    [$hote, $port] = lk_adresse_config();
    $v = lk_larka_repond($hote, $port);
    return $v !== null ? ['hote' => $hote, 'port' => $port, 'version' => $v, 'pid' => 0] : null;
}

/** Adresse mémorisée dans config.json (section « serveur »), sinon les défauts. */
function lk_adresse_config(): array
{
    $s = lk_config()['serveur'] ?? [];
    $hote = is_array($s) && is_string($s['host'] ?? null) && $s['host'] !== '' ? $s['host'] : '127.0.0.1';
    $port = is_array($s) && (int)($s['port'] ?? 0) > 0 ? (int)$s['port'] : LK_PORT_DEFAUT;
    return [$hote, $port];
}

/** Adresse effective : options explicites (mémorisées, comme start.sh) > config.json > défauts. */
function lk_adresse(array $o): array
{
    [$hote, $port] = lk_adresse_config();
    $explicite = false;
    if (!empty($o['reseau'])) { $hote = '0.0.0.0'; $explicite = true; }
    if (!empty($o['local']))  { $hote = '127.0.0.1'; $explicite = true; }
    if (isset($o['port'])) {
        $p = (int)$o['port'];
        if ($p < 1 || $p > 65535) echec('Port invalide : ' . $o['port']);
        $port = $p; $explicite = true;
    }
    if ($explicite) {
        $c = lk_config();
        if ($c) {
            $c['serveur'] = array_merge(is_array($c['serveur'] ?? null) ? $c['serveur'] : [], ['host' => $hote, 'port' => $port]);
            lk_config_ecrire($c);
            ok("Adresse mémorisée : $hote:$port (config.json — nouveau défaut)");
        }
    }
    return [$hote, $port];
}

function lk_adresses_reseau(): array
{
    $ips = @gethostbynamel((string)gethostname()) ?: [];
    return array_values(array_filter(array_unique($ips),
        fn($ip) => !str_starts_with($ip, '127.') && !str_starts_with($ip, '169.254.')));
}

function lk_ouvrir_navigateur(string $url): void
{
    if (!preg_match('#^https?://[A-Za-z0-9.:\[\]/_-]+$#', $url)) return;
    if (LK_WIN) {
        @pclose(@popen('start "" "' . $url . '"', 'r'));
    } elseif (PHP_OS_FAMILY === 'Darwin') {
        @exec('open ' . escapeshellarg($url) . ' >/dev/null 2>&1 &');
    } else {
        @exec('xdg-open ' . escapeshellarg($url) . ' >/dev/null 2>&1 &');
    }
}

/** Fin du journal du serveur (diagnostic d'un démarrage raté). */
function lk_fin_journal(string $f, int $n = 12): string
{
    if (!is_file($f)) return '';
    $l = @file($f, FILE_IGNORE_NEW_LINES) ?: [];
    return implode("\n", array_map(fn($x) => '    ' . $x, array_slice($l, -$n)));
}

/**
 * Mot de passe initial du compte « admin », s'il est encore en vigueur.
 * Le fichier (data/security/admin-initial.txt) est effacé dès que le mot de
 * passe a été changé : il ne sert plus, et n'a pas à traîner.
 */
function lk_mot_de_passe_initial(): ?string
{
    $f = LK_RACINE . '/data/security/admin-initial.txt';
    if (!is_file($f)) return null;
    if (!preg_match('/Mot de passe\s*:\s*(\S+)/u', (string)file_get_contents($f), $m)) return null;
    $mdp = $m[1];
    $base = lk_base();
    if ($base['driver'] === 'sqlite' && ($pdo = lk_pdo_sqlite($base['chemin']))) {
        try {
            $h = $pdo->query("SELECT MotDePasse FROM Utilisateurs WHERE Login = 'admin'")->fetchColumn();
            if (is_string($h) && $h !== '' && !password_verify($mdp, $h)) {
                @unlink($f);
                return null;
            }
        } catch (Throwable $e) { /* base en cours de création : on affiche */ }
    }
    return $mdp;
}

/** Lance « php -S » ; rend la ressource du processus. */
function lk_lancer_serveur(string $hote, int $port, bool $opcache = true)
{
    $journal = LK_JOURNAL_SERVEUR;
    @mkdir(dirname($journal), 0775, true);
    if (is_file($journal) && filesize($journal) > 10 * 1024 * 1024) {
        @unlink($journal . '.1');
        @rename($journal, $journal . '.1');
    }
    @file_put_contents($journal, "\n=== " . date('Y-m-d H:i:s') . " — démarrage sur $hote:$port (PHP " . PHP_VERSION . ") ===\n", FILE_APPEND);

    // OPcache pour le seul serveur : php.ini le laisse coupé en ligne de
    // commande. Sous Windows, des processus PHP qui partagent la même mémoire
    // OPcache se gênent — l'un se bloque, ou meurt au démarrage (« Opcode
    // handlers are unusable due to ASLR », observé avec deux Larka lancés).
    // Chaque serveur reçoit donc sa propre mémoire (opcache.cache_id).
    $op = ['-d', 'opcache.enable_cli=' . ($opcache ? '1' : '0')];
    if ($opcache && LK_WIN) {
        $op[] = '-d';
        $op[] = 'opcache.cache_id=larka-' . substr(sha1(LK_RACINE . '|' . $port . '|' . getmypid() . '|' . microtime(true)), 0, 16);
    }
    // Racine « . » et routeur « router.php » : relatifs au dossier courant, que
    // proc_open() fixe au dossier de Larka (voir lk_commande_php()).
    // « -q » : pas de ligne par requête dans serveur.log — une URL peut porter
    // un jeton à usage unique (retour OAuth, échange de session). Les erreurs PHP
    // vont dans data/logs/php_errors.log ; les échecs de démarrage restent écrits.
    // LARKA_JOURNAL_REQUETES=1 rétablit ces lignes, pour un diagnostic.
    $discret = getenv('LARKA_JOURNAL_REQUETES') === '1' ? [] : ['-q'];
    $cmd = lk_commande_php(array_merge($op, $discret, [
        '-d', 'disable_functions=' . LK_FONCTIONS_INTERDITES,
        '-S', "$hote:$port", '-t', '.', 'router.php',
    ]));
    lk_openssl_conf();
    if (!LK_WIN && getenv('PHP_CLI_SERVER_WORKERS') === false) {
        putenv('PHP_CLI_SERVER_WORKERS=' . (getenv('PHP_WORKERS') ?: '8'));   // non pris en charge sous Windows
    }
    $desc = [0 => ['file', LK_WIN ? 'NUL' : '/dev/null', 'r'], 1 => ['file', $journal, 'a'], 2 => ['redirect', 1]];
    $proc = @proc_open($cmd, $desc, $tubes, LK_RACINE);
    if (!is_resource($proc)) echec('Impossible de lancer le serveur PHP (' . lk_exe_php() . ').');
    return $proc;
}

function lk_attendre_serveur(string $hote, int $port, $proc, float $max = 20.0): bool
{
    $fin = microtime(true) + $max;
    while (microtime(true) < $fin) {
        if (!proc_get_status($proc)['running']) return false;
        if (lk_port_occupe($hote, $port)) return true;
        usleep(150000);
    }
    return false;
}

function cmd_demarrer(array $o): int
{
    lk_preparer($o);
    $premiere = !is_file(lk_base()['chemin']) && lk_base()['driver'] === 'sqlite';
    // Première fois, et une démonstration posée à côté de Larka : on la propose.
    if ($premiere && empty($o['sans-demo']) && lk_interactif() && empty($o['oui'])
        && ($paquet = lk_demo_chercher(null, false)) !== null) {
        echo "\n";
        info('Une démonstration est disponible : ' . lk_aff(basename($paquet)));
        if (confirmer('  L\'installer maintenant (commune fictive, 5 ans d\'activité) ?', true, [])) {
            return cmd_demo($o, [$paquet]);
        }
    }
    lk_avertir_emplacement();
    [$hote, $port] = lk_adresse($o);

    if (($v = lk_larka_repond($hote, $port)) !== null) {
        ok("Larka ($v) tourne déjà : " . lk_url($hote, $port));
        if (empty($o['sans-navigateur'])) lk_ouvrir_navigateur(lk_url($hote, $port));
        return 0;
    }
    if (lk_port_occupe($hote, $port)) {
        $libre = null;
        for ($p = $port + 1; $p <= $port + 20; $p++) {
            if (!lk_port_occupe($hote, $p)) { $libre = $p; break; }
        }
        if ($libre === null) echec("Le port $port et les 20 suivants sont occupés. Choisissez-en un autre : Larka.cmd demarrer --port 8123");
        alerte("Le port $port est pris par un autre programme : Larka utilise le port $libre pour cette fois.");
        $port = $libre;
    }

    titre('Larka ' . lk_version() . ' — démarrage');
    $proc = null;
    foreach ([true, false] as $avecOpcache) {
        $proc = lk_lancer_serveur($hote, $port, $avecOpcache);
        if (lk_attendre_serveur($hote, $port, $proc)) break;
        $st = proc_get_status($proc);
        if ($st['running']) proc_terminate($proc);
        proc_close($proc);
        $proc = null;
        // OPcache qui refuse de démarrer : on retente sans lui, une fois.
        if (!$avecOpcache || !preg_match('/opcache|opcode/i', lk_fin_journal(LK_JOURNAL_SERVEUR, 6))) break;
        alerte('OPcache ne démarre pas sur ce poste : Larka démarre sans lui (un peu plus lentement).');
    }
    if ($proc === null) {
        echec("Le serveur n'a pas démarré. Fin du journal (" . lk_rel(LK_JOURNAL_SERVEUR) . ") :\n" . lk_fin_journal(LK_JOURNAL_SERVEUR));
    }
    $pid = (int)(proc_get_status($proc)['pid'] ?? 0);
    lk_etat_ecrire(['pid' => $pid, 'hote' => $hote, 'port' => $port, 'debut' => date('c'), 'php' => lk_exe_php()]);

    // Première requête : la base (et le compte initial) se crée si besoin.
    info('Ouverture de la base…');
    $r = lk_http(lk_url($hote, $port) . 'api/index.php?action=me', 180);
    if ($r === null || $r['code'] >= 500) {
        alerte('Larka répond, mais la base n\'a pas pu être ouverte. Voir ' . lk_aff('data/logs/') . ' (php_errors.log, serveur.log).');
    }

    $url = lk_url($hote, $port);
    echo "\n";
    ok('Larka est démarré : ' . lk_c('1', $url));
    if (lk_hote_local($hote) !== $hote) {
        foreach (lk_adresses_reseau() as $ip) echo "    depuis le réseau local : http://$ip:$port/\n";
        echo "    (Windows peut demander d'autoriser « php » dans le pare-feu : acceptez pour les réseaux privés.)\n";
    }
    if (($mdp = lk_mot_de_passe_initial()) !== null) {
        echo "\n    Première connexion : identifiant " . lk_c('1;33', 'admin') . ', mot de passe ' . lk_c('1;33', $mdp) . "\n";
        echo "    (Larka vous demandera aussitôt d'en choisir un autre.)\n";
    }
    echo "\n    Super Admin (sauvegardes, multi-tenant) : {$url}?superadmin\n";
    echo "    (la première fois, depuis ce poste : choisissez son identifiant et son mot de passe.)\n";
    echo "\n    Gardez cette fenêtre ouverte : vous pouvez la réduire.\n";
    echo "    Pour arrêter Larka : fermez-la, ou appuyez sur Ctrl+C.\n";
    echo '    Autres actions (démonstration, réseau, raccourci…) : ' . (LK_WIN ? 'Larka.cmd menu' : 'php deploy/windows/larka.php menu') . "\n\n";
    if (empty($o['sans-navigateur'])) lk_ouvrir_navigateur($url);

    $code = lk_veiller_serveur($proc);   // attend la fin du serveur
    $e = lk_etat_lire();
    lk_etat_effacer();
    if (!empty($e['arret_demande'])) { ok('Larka a été arrêté.'); return 0; }
    if ($code !== 0) {
        alerte("Le serveur s'est arrêté (code $code). Fin du journal :\n" . lk_fin_journal(LK_JOURNAL_SERVEUR));
        return 1;
    }
    ok('Larka est arrêté.');
    return 0;
}

/**
 * Attend la fin du serveur et rend son code de sortie. En attendant, lance les
 * sauvegardes automatiques : le serveur PHP intégré ne sait rien faire à heure
 * fixe. L'outil (api/outils/sauvegarde-auto.php) part 2 minutes après le
 * démarrage puis toutes les 10 minutes, et ne fait que les sauvegardes DUES
 * (aucune si rien n'est réglé). Sa sortie va dans data/logs/sauvegardes.log.
 */
function lk_veiller_serveur($proc): int
{
    $journal = LK_RACINE . '/data/logs/sauvegardes.log';
    $prochaine = time() + 120;
    $tache = null;
    while (true) {
        $st = proc_get_status($proc);
        if (!$st['running']) {
            // Le code n'est fiable qu'au premier constat (PHP < 8.3).
            $code = (int)$st['exitcode'];
            proc_close($proc);
            break;
        }
        if ($tache !== null && !proc_get_status($tache)['running']) { proc_close($tache); $tache = null; }
        if ($tache === null && time() >= $prochaine) {
            $prochaine = time() + 600;
            if (is_file($journal) && filesize($journal) > 5 * 1024 * 1024) { @unlink($journal . '.1'); @rename($journal, $journal . '.1'); }
            $desc = [0 => ['file', LK_WIN ? 'NUL' : '/dev/null', 'r'], 1 => ['file', $journal, 'a'], 2 => ['redirect', 1]];
            $t = @proc_open(lk_commande_php(['api/outils/sauvegarde-auto.php', '--silencieux']), $desc, $tubes, LK_RACINE);
            $tache = is_resource($t) ? $t : null;
        }
        usleep(500000);
    }
    // Une sauvegarde en cours va jusqu'au bout.
    if ($tache !== null) proc_close($tache);
    return $code;
}

function lk_tuer(int $pid): bool
{
    if ($pid <= 0) return false;
    if (LK_WIN) {
        @exec('taskkill /PID ' . $pid . ' /T /F 2>&1', $sortie, $code);
        return $code === 0;
    }
    if (function_exists('posix_kill')) return @posix_kill($pid, 15);
    @exec('kill ' . $pid . ' 2>&1', $sortie, $code);
    return $code === 0;
}

function cmd_arreter(array $o): int
{
    $m = lk_en_marche();
    if ($m === null) { info('Larka n\'est pas démarré.'); return 0; }
    $e = lk_etat_lire();
    if (!$e || empty($e['pid'])) {
        echec('Larka tourne sur ' . lk_url($m['hote'], $m['port']) . ', mais n\'a pas été lancé par ce lanceur : fermez sa fenêtre.');
    }
    $e['arret_demande'] = true;
    lk_etat_ecrire($e);
    if (!lk_tuer((int)$e['pid'])) echec('Impossible d\'arrêter Larka : fermez sa fenêtre.');
    for ($i = 0; $i < 50 && lk_port_occupe($m['hote'], $m['port']); $i++) usleep(100000);
    if (lk_port_occupe($m['hote'], $m['port'])) echec('Larka répond encore : fermez sa fenêtre.');
    // Le lanceur resté au premier plan efface lui-même l'état ; au cas où il
    // aurait disparu avec le serveur, on le fait aussi.
    usleep(300000);
    lk_etat_effacer();
    ok('Larka est arrêté.');
    return 0;
}

function cmd_etat(array $o): int
{
    $m = lk_en_marche();
    if ($m === null) {
        [$h, $p] = lk_adresse_config();
        info("Larka est arrêté (adresse prévue : " . lk_url($h, $p) . ').');
        return 1;
    }
    ok("Larka {$m['version']} tourne : " . lk_url($m['hote'], $m['port']) . ($m['pid'] ? " (processus {$m['pid']})" : ''));
    return 0;
}

// ═══════════════════════════════════════════════════════════════════════════
//  Démonstration « Ville de Larkaville »
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Cherche le paquet de démonstration : l'emplacement donné, sinon le dossier
 * de Larka et son dossier demo\ (où Larka la livre), puis (si $large) le
 * dossier parent, Téléchargements et le Bureau. La plus récente l'emporte.
 * Rend le chemin d'une archive .zip ou d'un dossier, ou null.
 */
function lk_demo_chercher(?string $donne, bool $large = true): ?string
{
    $estPaquet = function (string $p): bool {
        if (is_file($p)) return (bool)preg_match('/demo-Larkaville.*\.zip$|-sqlite\.db\.gz$/i', basename($p));
        return is_dir($p) && (glob($p . '/*-sqlite.db.gz') ?: []) !== [];
    };
    if ($donne !== null && $donne !== '') {
        $p = larka_chemin_absolu($donne) ? $donne : getcwd() . LK_SEP . $donne;
        if (!file_exists($p)) echec('Introuvable : ' . lk_aff($donne));
        if (is_file($p) && str_ends_with(strtolower($p), '.db.gz')) return dirname($p);
        if (is_dir($p)) {
            foreach (array_merge([$p], glob($p . '/*', GLOB_ONLYDIR) ?: []) as $d) if ($estPaquet($d)) return $d;
            foreach (glob($p . '/*demo-Larkaville*.zip') ?: [] as $z) return $z;
            echec('Aucune démonstration dans ' . lk_aff($donne) . ' (fichier « …-sqlite.db.gz » attendu).');
        }
        if (!$estPaquet($p)) echec(lk_aff($donne) . " n'est pas une démonstration Larka (archive « …-sauvegarde-demo-Larkaville.zip » attendue).");
        return $p;
    }
    $dossiers = [LK_RACINE, LK_RACINE . LK_SEP . 'demo'];
    if ($large) {
        $dossiers[] = dirname(LK_RACINE);
        $profil = getenv('USERPROFILE') ?: getenv('HOME') ?: '';
        if ($profil !== '') {
            foreach (['Downloads', 'Téléchargements', 'Desktop', 'Bureau'] as $d) $dossiers[] = $profil . LK_SEP . $d;
        }
    }
    $trouves = [];
    foreach (array_unique($dossiers) as $d) {
        if (!is_dir($d)) continue;
        foreach (array_merge(glob($d . '/*demo-Larkaville*.zip') ?: [], glob($d . '/*demo-Larkaville*', GLOB_ONLYDIR) ?: []) as $c) {
            if ($estPaquet($c)) $trouves[$c] = (int)@filemtime($c);
        }
    }
    if (!$trouves) return null;
    arsort($trouves);
    return (string)array_key_first($trouves);
}

/** Copie les fichiers utiles du paquet (archive ou dossier) dans $tmp ; rend leurs chemins. */
function lk_demo_deballer(string $paquet, string $tmp, string $driver = 'sqlite'): array
{
    $voulus = [
        'base'     => '/-sqlite\.db\.gz$/i',
        'pg'       => '/-postgresql\.sql\.gz$/i',
        'fichiers' => '/-fichiers\.tar\.gz$/i',
        'recaler'  => '/^recaler-dates\.php$/i',
        'comptes'  => '/^comptes-demo\.csv$/i',
        'guide'    => '/^LISEZ-MOI-DEMO\.md$/i',
        'sommes'   => '/^SHA256SUMS$/',
    ];
    $trouve = [];
    if (is_file($paquet)) {
        if (!class_exists('ZipArchive')) echec('Extension PHP « zip » absente : impossible de lire ' . lk_aff(basename($paquet)));
        $zip = new ZipArchive();
        if ($zip->open($paquet) !== true) echec('Archive illisible : ' . lk_aff($paquet));
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nom = (string)$zip->getNameIndex($i);
            if (str_ends_with($nom, '/')) continue;
            $base = basename(str_replace('\\', '/', $nom));
            foreach ($voulus as $cle => $motif) {
                if (isset($trouve[$cle]) || !preg_match($motif, $base)) continue;
                $dest = $tmp . LK_SEP . $base;
                $in = $zip->getStream($nom);
                $out = @fopen($dest, 'wb');
                if (!$in || !$out) echec('Extraction impossible : ' . $base);
                stream_copy_to_stream($in, $out);
                fclose($in); fclose($out);
                $trouve[$cle] = $dest;
            }
        }
        $zip->close();
    } else {
        foreach (scandir($paquet) ?: [] as $base) {
            foreach ($voulus as $cle => $motif) {
                if (!isset($trouve[$cle]) && preg_match($motif, $base) && is_file($paquet . LK_SEP . $base)) {
                    $trouve[$cle] = $paquet . LK_SEP . $base;
                }
            }
        }
    }
    $suffixes = ['base' => '-sqlite.db.gz', 'pg' => '-postgresql.sql.gz', 'fichiers' => '-fichiers.tar.gz'];
    foreach ([$driver === 'pgsql' ? 'pg' : 'base', 'fichiers'] as $cle) {
        if (!isset($trouve[$cle])) echec('Paquet incomplet : fichier « …' . $suffixes[$cle] . ' » absent de ' . lk_aff($paquet));
    }
    // Empreintes publiées avec le paquet : un fichier abîmé au téléchargement
    // est refusé avant d'avoir remplacé quoi que ce soit.
    if (isset($trouve['sommes'])) {
        foreach (file($trouve['sommes'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
            if (!preg_match('/^([a-f0-9]{64})\s+\*?(.+)$/i', trim($l), $m)) continue;
            foreach ($trouve as $cle => $f) {
                if ($cle !== 'sommes' && basename($f) === basename($m[2]) && !hash_equals(strtolower($m[1]), hash_file('sha256', $f))) {
                    echec('Empreinte SHA-256 incorrecte pour ' . basename($f) . ' : paquet abîmé. Téléchargez-le de nouveau.');
                }
            }
        }
        ok('Empreintes du paquet vérifiées');
    }
    return $trouve;
}

/** Lit exactement $n octets d'un flux gzip (moins à la fin du flux). */
function lk_gz_lire($gz, int $n): string
{
    $s = '';
    while (strlen($s) < $n && !gzeof($gz)) {
        $b = gzread($gz, $n - strlen($s));
        if ($b === false || $b === '') break;
        $s .= $b;
    }
    return $s;
}

/**
 * Déplie une archive .tar.gz sous $racine.
 *
 * Lecteur volontairement minimal (ustar, noms longs GNU et pax) : il refuse
 * tout chemin qui sortirait de $racine ou que $autorise rejette, et tout ce
 * qui n'est ni fichier ni dossier (liens…). Rend le nombre de fichiers écrits.
 */
function lk_tar_deplier(string $tgz, string $racine, callable $autorise): int
{
    $gz = @gzopen($tgz, 'rb');
    if (!$gz) throw new RuntimeException('Archive illisible : ' . basename($tgz));
    $n = 0;
    $nomForce = null;
    try {
        while (true) {
            $h = lk_gz_lire($gz, 512);
            if ($h === '' || trim($h, "\0") === '') break;
            if (strlen($h) < 512) throw new RuntimeException('Archive tronquée.');
            $somme = 0;
            for ($i = 0; $i < 512; $i++) $somme += ($i >= 148 && $i < 156) ? 32 : ord($h[$i]);
            if ($somme !== octdec(trim(substr($h, 148, 8), " \0"))) throw new RuntimeException('Archive abîmée (somme de contrôle).');

            $type   = $h[156];
            $taille = octdec(trim(substr($h, 124, 12), " \0"));
            $nom    = rtrim(substr($h, 0, 100), "\0");
            if (substr($h, 257, 5) === 'ustar' && ($pre = rtrim(substr($h, 345, 155), "\0")) !== '') $nom = $pre . '/' . $nom;
            $bourrage = (512 - $taille % 512) % 512;

            if ($type === 'L' || $type === 'x' || $type === 'g') {          // métadonnées
                $d = lk_gz_lire($gz, $taille);
                lk_gz_lire($gz, $bourrage);
                if ($type === 'L') $nomForce = rtrim($d, "\0");
                if ($type === 'x' && preg_match('/^\d+ path=(.*)$/m', $d, $m)) $nomForce = $m[1];
                continue;
            }
            if ($nomForce !== null) { $nom = $nomForce; $nomForce = null; }

            $rel = preg_replace('#^(\./)+#', '', $nom);
            $parties = explode('/', trim($rel, '/'));
            if ($rel === '' || str_contains($rel, '\\') || str_contains($rel, "\0") || str_contains($rel, ':')
                || $rel[0] === '/' || in_array('..', $parties, true) || in_array('.', $parties, true)) {
                throw new RuntimeException("Chemin refusé dans l'archive : $nom");
            }
            $rel = implode('/', $parties);
            if (!$autorise($rel)) throw new RuntimeException("Fichier inattendu dans l'archive : $rel");
            $cible = $racine . LK_SEP . str_replace('/', LK_SEP, $rel);

            if ($type === '5') {                                           // dossier
                if (!is_dir($cible) && !@mkdir($cible, 0775, true) && !is_dir($cible)) throw new RuntimeException("Création impossible : $rel");
                continue;
            }
            if ($type !== '0' && $type !== "\0") throw new RuntimeException("Type d'entrée refusé ($type) : $rel");
            if (!is_dir(dirname($cible))) @mkdir(dirname($cible), 0775, true);
            $out = @fopen($cible, 'wb');
            if (!$out) throw new RuntimeException("Écriture impossible : $rel");
            $reste = $taille;
            while ($reste > 0) {
                $b = lk_gz_lire($gz, min($reste, 1 << 20));
                if ($b === '') { fclose($out); throw new RuntimeException('Archive tronquée.'); }
                fwrite($out, $b);
                $reste -= strlen($b);
            }
            fclose($out);
            lk_gz_lire($gz, $bourrage);
            $n++;
        }
    } finally {
        gzclose($gz);
    }
    return $n;
}

/** Instantané cohérent d'une base SQLite (WAL compris), ou simple copie à défaut. */
function lk_sqlite_instantane(string $src, string $dest): bool
{
    if ($pdo = lk_pdo_sqlite($src)) {
        try {
            $pdo->exec('VACUUM INTO ' . $pdo->quote($dest));
            return is_file($dest) && filesize($dest) > 0;
        } catch (Throwable $e) { /* base abîmée : copie brute ci-dessous */ }
        $pdo = null;
    }
    $ok = @copy($src, $dest);
    foreach (['-wal', '-shm'] as $s) if (is_file($src . $s)) @copy($src . $s, $dest . $s);
    return $ok;
}

/** Tables de Larka déjà remplies (plus d'un compte, ou la moindre fiche) : quelqu'un s'en sert. */
function lk_base_utilisee(PDO $pdo, bool $pg): bool
{
    foreach (['Utilisateurs' => 1, 'DemandesIntervention' => 0, 'Interventions' => 0, 'Biens' => 0, 'Equipements' => 0] as $table => $seuil) {
        try {
            $t = $pg ? strtolower($table) : $table;
            if ((int)$pdo->query("SELECT COUNT(*) FROM \"$t\"")->fetchColumn() > $seuil) return true;
        } catch (Throwable $e) { /* table absente : rien à compter */ }
    }
    return false;
}

/**
 * Lance un outil PostgreSQL ; $entree (flux) est versé sur son entrée,
 * $sortie (flux) reçoit sa sortie. Rend [code, messages d'erreur].
 */
function lk_outil_pg(array $cmd, array $b, $entree = null, $sortie = null): array
{
    $desc = [0 => $entree ? ['pipe', 'r'] : ['file', LK_WIN ? 'NUL' : '/dev/null', 'r'],
             1 => $sortie ? ['pipe', 'w'] : ['file', LK_WIN ? 'NUL' : '/dev/null', 'w'],
             2 => ['pipe', 'w']];
    $p = proc_open($cmd, $desc, $tubes, LK_RACINE, lk_env_pg($b));
    if (!is_resource($p)) return [-1, 'lancement impossible'];
    $err = '';
    stream_set_blocking($tubes[2], false);
    if ($entree) {
        while (!feof($entree)) {
            $bloc = fread($entree, 1 << 16);
            if ($bloc === false || $bloc === '') { if (feof($entree)) break; continue; }
            if (@fwrite($tubes[0], $bloc) === false) break;
            $err .= (string)stream_get_contents($tubes[2]);
        }
        fclose($tubes[0]);
    }
    if ($sortie) {
        while (!feof($tubes[1])) {
            $bloc = fread($tubes[1], 1 << 16);
            if ($bloc !== false && $bloc !== '') fwrite($sortie, $bloc);
            $err .= (string)stream_get_contents($tubes[2]);
        }
        fclose($tubes[1]);
    }
    stream_set_blocking($tubes[2], true);
    $err .= (string)stream_get_contents($tubes[2]);
    fclose($tubes[2]);
    return [proc_close($p), trim($err)];
}

/** Arguments de connexion communs à psql et pg_dump (jamais le mot de passe). */
function lk_args_pg(array $b): array
{
    return ['-h', $b['host'], '-p', (string)$b['port'], '-U', $b['user'], '-d', $b['dbname']];
}

/** Ce qui empêcherait de remplacer le contenu de la base PostgreSQL, ou null. */
function lk_pg_obstacle(PDO $pdo, array $b): ?string
{
    $creer = (bool)$pdo->query("SELECT has_schema_privilege('public', 'CREATE')")->fetchColumn();
    if (!$creer) {
        return "l'utilisateur « {$b['user']} » ne peut pas créer de tables dans le schéma public de « {$b['dbname']} ».\n"
             . "  En administrateur PostgreSQL : ALTER DATABASE \"{$b['dbname']}\" OWNER TO \"{$b['user']}\";";
    }
    $autres = $pdo->query("SELECT string_agg(tablename, ', ') FROM pg_tables WHERE schemaname = 'public' AND tableowner <> current_user")->fetchColumn();
    if ($autres) {
        return "des tables de « {$b['dbname']} » appartiennent à un autre rôle que « {$b['user']} » ($autres) :\n"
             . "  la démonstration ne peut pas les remplacer. Utilisez une base appartenant à « {$b['user']} ».";
    }
    return null;
}

/** Des tenants sont déclarés (registre Super Admin SQLite ou PostgreSQL). */
function lk_multi_tenant(): bool
{
    $sa = lk_config()['superadmin_db'] ?? ['driver' => 'sqlite'];
    if (!is_array($sa)) return false;
    try {
        if (strtolower((string)($sa['driver'] ?? 'sqlite')) === 'pgsql') {
            if (!extension_loaded('pdo_pgsql')) return false;
            $mdp = lk_env_valeur('GMAO_SUPERADMIN_DB_PASSWORD');
            if ($mdp === '') $mdp = (string)($sa['password'] ?? '');
            $b = ['host' => (string)(($sa['host'] ?? '') ?: '127.0.0.1'), 'port' => (int)(($sa['port'] ?? 0) ?: 5432),
                  'dbname' => (string)(($sa['dbname'] ?? '') ?: 'gmao_admin'), 'user' => (string)($sa['user'] ?? ''),
                  'password' => $mdp, 'sslmode' => (string)(($sa['sslmode'] ?? '') ?: 'prefer')];
            return (int)lk_pdo_pg($b)->query('SELECT COUNT(*) FROM sa_tenants')->fetchColumn() > 0;
        }
        $f = (string)(($sa['path'] ?? '') ?: 'data/superadmin.db');
        if (!larka_chemin_absolu($f)) $f = LK_RACINE . LK_SEP . $f;
        $pdo = lk_pdo_sqlite($f);
        return $pdo !== null && (int)$pdo->query('SELECT COUNT(*) FROM sa_tenants')->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;   // registre absent ou vide : installation simple
    }
}

/** Refus retenu : la démonstration n'est plus proposée d'elle-même (start.sh). */
function lk_demo_refus_noter(): void
{
    @mkdir(LK_RACINE . '/data/run', 0775, true);
    @file_put_contents(LK_RACINE . '/data/run/demo-refusee', date('c') . "\n");
}

/**
 * --proposer : appelé par start.sh au démarrage et en fin d'installation.
 * Muet s'il n'y a pas de démonstration à proposer ; un « non » est retenu ;
 * un obstacle (multi-tenant, base inaccessible) n'empêche pas de démarrer.
 */
function cmd_demo(array $o, array $args): int
{
    $proposer = !empty($o['proposer']);
    // Ne pas proposer ce qui ne pourrait pas s'installer : renoncer sans bruit
    // (le démarrage continue), ou échouer quand la pose a été demandée.
    // $definitif : l'obstacle ne se lèvera pas de lui-même (multi-tenant) —
    // inutile de le redire à chaque démarrage.
    $renoncer = function (string $m, bool $definitif = false) use ($proposer): int {
        if (!$proposer) echec($m);
        alerte('Démonstration non proposée : ' . $m);
        if ($definitif) lk_demo_refus_noter();
        return 0;
    };
    // Proposer suppose quelqu'un pour répondre.
    if ($proposer && (!lk_interactif() || lk_demo_chercher($args[0] ?? null) === null)) return 0;

    if (!$proposer) titre('Larka ' . lk_version() . ' — démonstration « Ville de Larkaville »');
    $base = lk_base();
    $pg = $base['driver'] === 'pgsql';
    if (LK_WIN && !$pg) {
        lk_preparer($o);
    } else {
        // Linux (start.sh), ou PostgreSQL : la configuration existe déjà ; on ne
        // vérifie que ce dont la pose a besoin (start.sh a ses propres exigences).
        lk_licence($o);
        lk_dossiers();
        foreach (['zip' => 'lire le paquet', 'zlib' => 'décompresser la base', $pg ? 'pdo_pgsql' : 'pdo_sqlite' => 'ouvrir la base'] as $e => $u) {
            if (!extension_loaded($e)) echec("Extension PHP « $e » absente ($u).");
        }
    }
    if (!in_array($base['driver'], ['sqlite', 'pgsql'], true)) {
        echec("Cette installation utilise « {$base['driver']} » (config.json) : la démonstration se pose sur SQLite ou PostgreSQL.");
    }

    $paquet = lk_demo_chercher($args[0] ?? null);
    if ($paquet === null) {
        echec("Démonstration introuvable.\n"
            . "  Elle est livrée dans le dossier demo de Larka. Remettez-y l'archive « …-sauvegarde-demo-Larkaville.zip »\n"
            . "  (ou posez-la dans le dossier de Larka, dans Téléchargements), ou indiquez son chemin : "
            . (LK_WIN ? 'Larka.cmd demo C:\\chemin\\archive.zip' : './start.sh demo /chemin/archive.zip'));
    }
    if (!$proposer) info('Paquet : ' . lk_aff($paquet));

    if (lk_multi_tenant()) {
        return $renoncer("cette installation est multi-tenant : la démonstration vise une installation simple.\n"
            . "  Voir la rubrique « Multi-tenant » de LISEZ-MOI-DEMO.md.", true);
    }

    // La base doit être libre : sous Windows, un fichier ouvert ne se remplace pas.
    if (($m = lk_en_marche()) !== null) {
        if (!confirmer('  Larka tourne (' . lk_url($m['hote'], $m['port']) . ') : l\'arrêter pour installer la démonstration ?', true, $o)) {
            echec('Opération annulée.');
        }
        if (cmd_arreter($o) !== 0) echec('Arrêtez Larka (fermez sa fenêtre), puis relancez.');
    }

    // Base actuelle : vierge, ou déjà utilisée ?
    $utilisee = false;
    $tables = 0;
    if ($pg) {
        if (!lk_outil('psql')) return $renoncer('outil « psql » introuvable : installez le client PostgreSQL (paquet postgresql-client).');
        try {
            $pdo = lk_pdo_pg($base);
        } catch (Throwable $e) {
            return $renoncer("connexion à PostgreSQL impossible ({$base['user']}@{$base['host']}:{$base['port']}/{$base['dbname']}) : "
                . preg_replace('/^SQLSTATE\[\w+\] \[\d+\] /', '', $e->getMessage()));
        }
        if (($obstacle = lk_pg_obstacle($pdo, $base)) !== null) return $renoncer($obstacle);
        $tables = (int)$pdo->query("SELECT COUNT(*) FROM pg_tables WHERE schemaname = 'public'")->fetchColumn();
        $utilisee = lk_base_utilisee($pdo, true);
        $pdo = null;
    } elseif (is_file($base['chemin']) && ($pdo = lk_pdo_sqlite($base['chemin']))) {
        $utilisee = lk_base_utilisee($pdo, false);
        $pdo = null;
    }
    $cible = $pg ? "la base PostgreSQL « {$base['dbname']} »" : 'la base de Larka';
    if ($proposer) {
        titre('Larka ' . lk_version() . ' — démonstration « Ville de Larkaville »');
        echo "  Une démonstration est disponible : une commune fictive, cinq ans d'activité dans tous\n"
           . "  les modules, 54 comptes (" . lk_aff(basename($paquet)) . ").\n";
    }
    echo "\n  La démonstration REMPLACE $cible et l'état de ses modules.\n";
    if ($pg ? $tables > 0 : is_file($base['chemin'])) echo "  La base actuelle sera d'abord mise de côté dans " . lk_aff('data/backups/') . ".\n";
    if ($utilisee) alerte('La base actuelle contient déjà des données : la démonstration est faite pour une installation dédiée.');
    if ($proposer) {
        // La question est posée même avec --oui : proposer n'est pas installer.
        if (!confirmer('  Installer la démonstration maintenant ?', !$utilisee, ['oui' => false] + $o)) {
            lk_demo_refus_noter();
            info('Elle ne sera plus proposée. Pour l\'installer plus tard : ' . (LK_WIN ? 'Larka.cmd demo' : './start.sh demo'));
            return 0;
        }
    } elseif (!confirmer('  Installer la démonstration ?', !$utilisee, $o)) {
        echec('Opération annulée.');
    }

    $tmp = LK_RACINE . LK_SEP . 'data' . LK_SEP . 'tmp-demo-' . bin2hex(random_bytes(4));
    @mkdir($tmp, 0775, true);
    try {
        $f = lk_demo_deballer($paquet, $tmp, $base['driver']);

        if ($pg) {
            // 1. Mise de côté de la base actuelle (pg_dump, mot de passe par l'environnement)
            if ($tables > 0) {
                $copie = LK_RACINE . LK_SEP . 'data' . LK_SEP . 'backups' . LK_SEP . 'avant-demo-' . date('Ymd-His') . '.sql.gz';
                $dump = lk_outil('pg_dump');
                $code = -1; $err = 'pg_dump introuvable';
                if ($dump) {
                    $gz = gzopen($copie, 'wb6');
                    [$code, $err] = lk_outil_pg(array_merge([$dump, '--no-owner', '--no-privileges'], lk_args_pg($base)), $base, null, $gz);
                    gzclose($gz);
                }
                if ($code !== 0) {
                    @unlink($copie);
                    if ($utilisee) echec("Impossible de mettre la base actuelle de côté ($err) : rien n'a été modifié.");
                    alerte("Base actuelle non sauvegardée ($err) — elle ne contenait aucune donnée.");
                } else {
                    ok('Base actuelle mise de côté : ' . lk_rel($copie));
                }
            }

            // 2. La base de démonstration : vidage et chargement en UNE transaction —
            //    au moindre échec, la base reste telle qu'elle était.
            $vider = "DO \$\$ DECLARE r record; BEGIN\n"
                . "  FOR r IN SELECT format('%I.%I', schemaname, viewname) AS n FROM pg_views WHERE schemaname = 'public' LOOP EXECUTE 'DROP VIEW IF EXISTS ' || r.n || ' CASCADE'; END LOOP;\n"
                . "  FOR r IN SELECT format('%I.%I', schemaname, tablename) AS n FROM pg_tables WHERE schemaname = 'public' LOOP EXECUTE 'DROP TABLE IF EXISTS ' || r.n || ' CASCADE'; END LOOP;\n"
                . "  FOR r IN SELECT format('%I.%I', sequence_schema, sequence_name) AS n FROM information_schema.sequences WHERE sequence_schema = 'public' LOOP EXECUTE 'DROP SEQUENCE IF EXISTS ' || r.n || ' CASCADE'; END LOOP;\n"
                . "END \$\$;\n";
            $flux = fopen('php://temp', 'w+b');
            fwrite($flux, $vider);
            $gz = gzopen($f['pg'], 'rb');
            while (!gzeof($gz)) { $bloc = gzread($gz, 1 << 20); if ($bloc === false) break; fwrite($flux, $bloc); }
            gzclose($gz);
            rewind($flux);
            info('Chargement de la base de démonstration dans PostgreSQL…');
            [$code, $err] = lk_outil_pg(array_merge([lk_outil('psql'), '-X', '-q', '-v', 'ON_ERROR_STOP=1', '--single-transaction'], lk_args_pg($base)), $base, $flux, null);
            fclose($flux);
            if ($code !== 0) {
                echec("Le chargement a échoué : la base est restée telle qu'elle était.\n  " . str_replace("\n", "\n  ", mb_substr($err, 0, 800)));
            }
            $comptes = 0;
            try { $comptes = (int)lk_pdo_pg($base)->query('SELECT COUNT(*) FROM utilisateurs')->fetchColumn(); } catch (Throwable $e) {}
            if ($comptes === 0) echec('La base de démonstration est illisible après chargement : paquet abîmé ?');
        } else {
            // 1. Mise de côté de la base actuelle
            if (is_file($base['chemin'])) {
                $copie = LK_RACINE . LK_SEP . 'data' . LK_SEP . 'backups' . LK_SEP . 'avant-demo-' . date('Ymd-His') . '.db';
                if (!lk_sqlite_instantane($base['chemin'], $copie)) echec('Impossible de mettre la base actuelle de côté : rien n\'a été modifié.');
                ok('Base actuelle mise de côté : ' . lk_rel($copie));
            }

            // 2. La base de démonstration
            $neuve = $base['chemin'] . '.demo-' . bin2hex(random_bytes(3));
            $in = @gzopen($f['base'], 'rb');
            $out = @fopen($neuve, 'wb');
            if (!$in || !$out) echec('Décompression impossible : ' . basename($f['base']));
            while (!gzeof($in)) {
                $b = gzread($in, 1 << 20);
                if ($b === false) break;
                fwrite($out, $b);
            }
            gzclose($in); fclose($out);
            $pdo = lk_pdo_sqlite($neuve);
            $comptes = 0;
            try { $comptes = $pdo ? (int)$pdo->query('SELECT COUNT(*) FROM Utilisateurs')->fetchColumn() : 0; } catch (Throwable $e) {}
            $pdo = null;
            if ($comptes === 0) { @unlink($neuve); echec('La base de démonstration est illisible : paquet abîmé ?'); }
            foreach (['', '-wal', '-shm', '-journal'] as $s) {
                if (is_file($base['chemin'] . $s) && !@unlink($base['chemin'] . $s)) {
                    @unlink($neuve);
                    echec('Impossible de remplacer ' . lk_rel($base['chemin'] . $s) . ' : un programme l\'utilise encore (Larka ?).');
                }
            }
            if (!@rename($neuve, $base['chemin'])) echec('Impossible de poser la base de démonstration.');
        }
        foreach (glob(LK_RACINE . '/data/.schema/*.flag') ?: [] as $drapeau) @unlink($drapeau);
        @unlink(LK_RACINE . '/data/security/admin-initial.txt');    // le compte admin est celui de la démonstration
        ok("Base de démonstration posée ($comptes comptes)");

        // 3. Les fichiers (plans, modules, thèmes, photos des procédures d'urgence)
        $prefixes = ['data/plans', 'data/fonds', 'data/extensions', 'data/thematiques', 'data/urgences', 'extensions/config'];
        $n = lk_tar_deplier($f['fichiers'], LK_RACINE, function (string $rel) use ($prefixes): bool {
            foreach ($prefixes as $p) if ($rel === $p || str_starts_with($rel, $p . '/')) return true;
            return false;
        });
        ok("Fichiers déposés : $n (plans, modules, thèmes, procédures d'urgence)");

        // 4. Réglages qu'elle suppose
        $c = lk_config();
        $c['extensions'] = array_merge(is_array($c['extensions'] ?? null) ? $c['extensions'] : [], ['actif' => true]);
        $logs = is_array($c['logs'] ?? null) ? $c['logs'] : [];
        if ((int)($logs['rotation_jours'] ?? 14) < 30) $logs['rotation_jours'] = 30;
        $c['logs'] = $logs;
        lk_config_ecrire($c);
        ok('config.json : modules activés, journal conservé 30 jours');

        // 5. Les comptes, hors de portée du serveur web (data/ n'est jamais servi)
        if (isset($f['comptes'])) {
            @mkdir(LK_RACINE . '/data/demo', 0775, true);
            @copy($f['comptes'], LK_RACINE . '/data/demo/comptes-demo.csv');
            if (isset($f['guide'])) @copy($f['guide'], LK_RACINE . '/data/demo/LISEZ-MOI-DEMO.md');
        }

        // 6. Les dates, recalées sur aujourd'hui
        if (isset($f['recaler']) && empty($o['sans-recalage'])) {
            info('Recalage des dates sur aujourd\'hui…');
            if ($pg) {
                // Mot de passe par PGPASSWORD (hérité), jamais sur la ligne de commande.
                $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=%s', $base['host'], $base['port'], $base['dbname'], $base['sslmode']);
                $cmd = lk_commande_php([lk_relatif($f['recaler']), $dsn, '--utilisateur=' . $base['user'], '--fichiers=.']);
                $env = lk_env_pg($base);
            } else {
                $cmd = lk_commande_php([lk_relatif($f['recaler']), lk_relatif($base['chemin']), '--fichiers=.']);
                $env = null;
            }
            $p = proc_open($cmd, [0 => ['file', LK_WIN ? 'NUL' : '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $tubes, LK_RACINE, $env);
            $sortie = is_resource($p) ? stream_get_contents($tubes[1]) : '';
            if (is_resource($p)) { fclose($tubes[1]); $code = proc_close($p); } else { $code = -1; }
            foreach (preg_split('/\R/', trim((string)$sortie)) as $l) if ($l !== '') echo "    $l\n";
            $code === 0 ? ok('Dates recalées') : alerte('Le recalage a échoué : la démonstration reste datée du 14 octobre 2026.');
        } elseif (!isset($f['recaler'])) {
            alerte('recaler-dates.php absent du paquet : la démonstration reste datée du 14 octobre 2026.');
        }
    } finally {
        foreach (glob($tmp . '/*') ?: [] as $x) @unlink($x);
        @rmdir($tmp);
    }

    echo "\n";
    ok('Démonstration installée.');
    echo "    Connexion : " . lk_c('1;33', 'claire.moreau') . ' (gestionnaire), ou ' . lk_c('1;33', 'admin')
        . ' — mot de passe ' . lk_c('1;33', 'Larka2026!') . " pour tous les comptes.\n";
    if (is_file(LK_RACINE . '/data/demo/comptes-demo.csv')) {
        echo '    Les 54 comptes : ' . lk_aff('data/demo/comptes-demo.csv') . "\n";
    }
    echo "    Si d'autres que vous peuvent joindre ce poste, changez ces mots de passe.\n\n";
    if (!empty($o['sans-demarrer'])) return 0;
    return cmd_demarrer(['sans-demo' => true] + $o);
}

// ═══════════════════════════════════════════════════════════════════════════
//  Raccourcis, diagnostic, menu, aide
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Mise à jour depuis l'archive d'une nouvelle version (LARKA_GMAO-x.y.z.zip).
 * Ne décompressez plus une nouvelle version par-dessus l'ancienne : cela
 * remplaçait aussi les réglages des modules et toute copie de config.json ou
 * de .env contenue dans l'archive. Ici, c'est la mise à jour de Larka qui écrit
 * (api/outils/mise-a-jour.php --archive) : config.json, .env, data\, php\ et
 * les réglages des modules restent tels quels ; fichiers et base sauvegardés avant.
 */
function cmd_maj(array $o, array $args): int
{
    // --cible <dossier> (ou --cible=<dossier>) : l'installation à mettre à jour,
    // quand on lance la commande depuis la nouvelle version décompressée à côté.
    $cible = null;
    if (isset($o['cible'])) $cible = is_string($o['cible']) ? $o['cible'] : array_shift($args);
    if ($cible !== null) {
        $r = realpath((string)$cible);
        if ($r === false || !is_file($r . '/api/index.php')) { alerte('Installation de Larka introuvable : ' . lk_aff((string)$cible)); return 2; }
        $cible = $r;
        if ($cible === realpath(LK_RACINE)) $cible = null;
    }
    $zip = $args[0] ?? null;
    if ($zip === null) {
        $dossiers = [LK_RACINE, dirname(LK_RACINE)];
        foreach (['USERPROFILE', 'HOME'] as $v) {
            $h = (string)getenv($v);
            if ($h !== '') { $dossiers[] = $h . '/Downloads'; $dossiers[] = $h . '/Téléchargements'; }
        }
        $meilleur = null; $t = 0;
        foreach ($dossiers as $d) {
            foreach (glob($d . '/LARKA_GMAO-*.zip') ?: [] as $f) {
                if (stripos($f, 'demo-Larkaville') !== false) continue;
                if (($m = (int)@filemtime($f)) > $t) { $meilleur = $f; $t = $m; }
            }
        }
        $zip = $meilleur;
    }
    if ($zip === null || !is_file($zip)) {
        alerte("Archive introuvable. Usage : Larka.cmd maj LARKA_GMAO-x.y.z.zip [--sans-signature] [--reinstaller]");
        return 2;
    }
    $zip = realpath($zip) ?: $zip;
    titre('Larka — mise à jour depuis une archive');
    info('Archive : ' . lk_aff($zip));
    if ($cible) info('Installation : ' . lk_aff($cible));
    $enMarche = $cible ? null : lk_en_marche();
    if ($enMarche) { info('Arrêt de Larka pendant la mise à jour…'); cmd_arreter($o); }
    $cmd = lk_commande_php(['api/outils/mise-a-jour.php', '--archive', $zip]);
    if ($cible) { $cmd[] = '--racine'; $cmd[] = $cible; }
    foreach (['sans-signature', 'reinstaller'] as $opt) if (!empty($o[$opt])) $cmd[] = '--' . $opt;
    if (!empty($o['oui'])) $cmd[] = '--oui';
    $p = @proc_open($cmd, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $tubes, LK_RACINE);
    $code = is_resource($p) ? proc_close($p) : 1;
    if ($code !== 0) { alerte('Mise à jour non appliquée (détail ci-dessus).'); }
    else ok('Mise à jour terminée — config.json, .env, data\\ et les réglages des modules sont intacts.');
    if ($enMarche) return cmd_demarrer($o);
    return $code === 0 ? 0 : 1;
}

function cmd_raccourci(array $o): int
{
    if (!LK_WIN) echec('Les raccourcis sont propres à Windows.');
    $q = fn(string $s): string => "'" . str_replace("'", "''", $s) . "'";
    $ps = '$ErrorActionPreference = "Stop"; $shell = New-Object -ComObject WScript.Shell; '
        . 'foreach ($d in @([Environment]::GetFolderPath("Desktop"), [Environment]::GetFolderPath("Programs"))) { '
        . '  if (-not $d) { continue }; $lnk = Join-Path $d "Larka.lnk"; '
        . '  $s = $shell.CreateShortcut($lnk); '
        . '  $s.TargetPath = ' . $q(LK_RACINE . '\\Larka.cmd') . '; '
        . '  $s.WorkingDirectory = ' . $q(LK_RACINE) . '; '
        . '  $s.IconLocation = ' . $q(LK_RACINE . '\\deploy\\windows\\larka.ico') . ' + ",0"; '
        . '  $s.Description = "Larka - GMAO"; $s.Save(); Write-Output $lnk }';
    $enc = base64_encode(function_exists('mb_convert_encoding') ? mb_convert_encoding($ps, 'UTF-16LE', 'UTF-8') : iconv('UTF-8', 'UTF-16LE', $ps));
    exec('powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -EncodedCommand ' . $enc . ' 2>&1', $sortie, $code);
    if ($code !== 0) echec("Création des raccourcis impossible :\n    " . implode("\n    ", $sortie));
    foreach ($sortie as $l) if (trim($l) !== '') ok('Raccourci : ' . trim($l));
    return 0;
}

function cmd_diagnostic(array $o): int
{
    titre('Larka ' . lk_version() . ' — diagnostic');
    $ko = 0;
    $ligne = function (bool $bon, string $m) use (&$ko): void { $bon ? ok($m) : alerte($m); if (!$bon) $ko++; };

    $ligne(version_compare(PHP_VERSION, '8.1.0', '>='), 'PHP ' . PHP_VERSION . ' — ' . lk_exe_php());
    echo '    php.ini : ' . (php_ini_loaded_file() ?: '(aucun)') . "\n";
    foreach (lk_extensions_requises() as $e => $u) $ligne(extension_loaded($e), "Extension $e ($u)");
    $ligne(extension_loaded('Zend OPcache'), 'OPcache (accélère le serveur)');
    if (is_file(LK_RACINE . '/api/Prerequis.php')) {
        require_once LK_RACINE . '/api/Prerequis.php';
        echo "\n    Prérequis de Larka " . lk_version() . " (version.json) :\n";
        $r = LarkaPrerequis::verifier(null, ['driver' => lk_base()['driver'] ?? 'sqlite'], LK_RACINE);
        foreach ($r['points'] as $p) {
            $m = $p['libelle'] . ($p['detail'] !== '' ? " — {$p['detail']}" : '') . ($p['conseil'] !== '' ? " → {$p['conseil']}" : '');
            if ($p['statut'] === 'ok') ok($m);
            elseif ($p['statut'] === 'avertissement') alerte($m);
            else $ligne(false, $m);
        }
        echo "\n";
    }
    $ca = (string)ini_get('curl.cainfo');
    $ligne($ca !== '' && is_file($ca), 'Certificats racine pour HTTPS' . ($ca !== '' ? ' : ' . lk_aff($ca) : ' (curl.cainfo non défini)'));
    if (LK_WIN) {
        $cnf = getenv('OPENSSL_CONF') ?: dirname(lk_exe_php()) . '\\extras\\ssl\\openssl.cnf';
        $ligne(is_file($cnf), 'Configuration OpenSSL : ' . $cnf);
    }

    echo "\n";
    $ligne(is_file(LK_LICENCE), 'Licence acceptée');
    $ligne(is_file(LK_RACINE . '/.env'), '.env présent');
    $c = lk_config();
    $ligne($c !== [], 'config.json lisible');
    $b = lk_base();
    echo "    Base : {$b['driver']}" . ($b['driver'] === 'sqlite' ? ' — ' . lk_aff($b['chemin']) : '') . "\n";
    if ($b['driver'] === 'sqlite') {
        if (!is_file($b['chemin'])) {
            info('Base pas encore créée (elle le sera au premier démarrage).');
        } elseif ($pdo = lk_pdo_sqlite($b['chemin'])) {
            try {
                $qc = (string)$pdo->query('PRAGMA quick_check')->fetchColumn();
                $n = (int)$pdo->query('SELECT COUNT(*) FROM Utilisateurs')->fetchColumn();
                $ligne($qc === 'ok', sprintf('Base saine (%s Mo, %d compte%s)', number_format(filesize($b['chemin']) / 1048576, 1, ',', ' '), $n, $n > 1 ? 's' : ''));
            } catch (Throwable $e) {
                $ligne(false, 'Base illisible : ' . $e->getMessage());
            }
        } else {
            $ligne(false, 'Base impossible à ouvrir');
        }
    }
    foreach (['data', 'data/logs', 'data/sessions', 'data/backups'] as $d) {
        $ligne(is_dir(LK_RACINE . '/' . $d) && is_writable(LK_RACINE . '/' . $d), 'Écriture possible dans ' . lk_aff($d));
    }
    lk_avertir_emplacement();

    echo "\n";
    $m = lk_en_marche();
    if ($m) ok("Larka {$m['version']} tourne : " . lk_url($m['hote'], $m['port']));
    else {
        [$h, $p] = lk_adresse_config();
        info('Larka est arrêté (adresse prévue : ' . lk_url($h, $p) . ')' . (lk_port_occupe($h, $p) ? ' — ce port est pris par un autre programme' : ''));
    }
    foreach ([LK_RACINE . '/data/logs/php_errors.log', LK_JOURNAL_SERVEUR] as $j) {
        if (is_file($j) && filesize($j) > 0) echo "\n    Fin de " . lk_rel($j) . " :\n" . lk_fin_journal($j, 6) . "\n";
    }
    echo "\n";
    $ko === 0 ? ok('Aucun problème détecté.') : alerte("$ko point(s) à examiner ci-dessus.");
    return $ko === 0 ? 0 : 1;
}

function cmd_aide(): int
{
    $l = LK_WIN ? 'Larka.cmd' : 'php deploy/windows/larka.php';
    $lignes = [
        ['',                       'démarre Larka et ouvre le navigateur'],
        ['menu',                   'toutes les actions, au choix'],
        ['demarrer [options]',     'démarre Larka'],
        ['  --port N',             'autre port (défaut 8000) — mémorisé'],
        ['  --reseau',             'accessible depuis le réseau local — mémorisé'],
        ['  --local',              'ce poste seulement (défaut) — mémorisé'],
        ['  --sans-navigateur',    "n'ouvre pas le navigateur"],
        ['demo [archive.zip]',     'installe la démonstration « Ville de Larkaville »'],
        ['  --sans-recalage',      "garde les dates d'origine (fin au 14 octobre 2026)"],
        ['  --sans-demarrer',      'installe sans démarrer'],
        ['arreter',                'arrête Larka'],
        ['etat',                   'indique si Larka tourne, et où'],
        ['raccourci',              'raccourcis sur le Bureau et dans le menu Démarrer'],
        ['diagnostic',             "vérifie l'installation"],
        ['installer',              'prépare la configuration sans démarrer'],
        ['php [archive.zip]',      'installe ou met à jour PHP (archive locale acceptée)'],
        ['maj [LARKA_GMAO-x.y.z.zip]', 'met Larka à jour sans toucher config.json, .env ni data'],
        ['  --cible <dossier>',    "installation à mettre à jour (défaut : celle-ci)"],
        ['  --sans-signature',     'archive non signée (provenance sûre)'],
        ['aide',                   'cette aide'],
        ['-y, --oui',              'répond « oui » à tout (accepte aussi la licence)'],
    ];
    $gauches = array_map(fn($x) => str_starts_with($x[0], ' ') || str_starts_with($x[0], '-') ? '    ' . $x[0] : rtrim("$l {$x[0]}"), $lignes);
    $largeur = max(array_map('strlen', $gauches)) + 2;
    echo "\n  Larka pour Windows — commandes\n\n";
    foreach ($lignes as $i => [, $d]) echo '    ' . str_pad($gauches[$i], $largeur) . "$d\n";
    echo "\n  Données : dossier data\\ (base data\\gmao.db, journaux data\\logs\\, sauvegardes data\\backups\\).\n";
    echo "  Réglages : config.json et .env — modifiables aussi dans Larka, Configuration → Serveur.\n\n";
    return 0;
}

function cmd_menu(array $o): int
{
    if (!lk_interactif()) return cmd_aide();
    while (true) {
        titre('Larka ' . lk_version() . ' — menu');
        $m = lk_en_marche();
        echo '  État : ' . ($m ? lk_c('1;32', 'démarré') . ' — ' . lk_url($m['hote'], $m['port']) : 'arrêté') . "\n\n";
        echo "   1  Démarrer Larka (ce poste seulement)\n";
        echo "   2  Démarrer Larka, accessible depuis le réseau local\n";
        echo "   3  Installer la démonstration « Ville de Larkaville »\n";
        echo "   4  Créer les raccourcis (Bureau et menu Démarrer)\n";
        echo "   5  Arrêter Larka\n";
        echo "   6  Diagnostic\n";
        echo "   7  Ouvrir le dossier de Larka\n";
        echo "   8  Mettre PHP à jour\n";
        echo "   0  Quitter\n\n";
        switch (demander('  Votre choix :', '0')) {
            case '1': return cmd_demarrer(['local' => true] + $o);
            case '2': return cmd_demarrer(['reseau' => true] + $o);
            case '3': return cmd_demo($o, []);
            case '4': cmd_raccourci($o); break;
            case '5': cmd_arreter($o); break;
            case '6': cmd_diagnostic($o); break;
            case '7':
                if (LK_WIN) @pclose(@popen('explorer .', 'r'));   // dossier courant = Larka
                else info(LK_RACINE);
                break;
            case '8': return LK_SORTIE_MAJ_PHP;
            case '0': case 'q': return 0;
            default: alerte('Choix inconnu.');
        }
        demander("\n  (Entrée pour revenir au menu)");
    }
}

// ═══════════════════════════════════════════════════════════════════════════
//  Point d'entrée
// ═══════════════════════════════════════════════════════════════════════════

function lk_options(array $argv): array
{
    $cmd = null; $o = []; $args = [];
    for ($i = 1; $i < count($argv); $i++) {
        $a = (string)$argv[$i];
        if ($a === '-y' || $a === '--oui' || $a === '--yes') { $o['oui'] = true; continue; }
        if ($a === '--port' && isset($argv[$i + 1])) { $o['port'] = $argv[++$i]; continue; }
        if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) { $o[$m[1]] = $m[2] ?? true; continue; }
        if ($a === '/?' || $a === '-h') { $cmd = 'aide'; continue; }
        if ($cmd === null) $cmd = strtolower($a); else $args[] = $a;
    }
    return [$cmd ?? 'demarrer', $o, $args];
}

[$commande, $options, $arguments] = lk_options($argv);
$alias = [
    'start' => 'demarrer', 'démarrer' => 'demarrer', 'lancer' => 'demarrer',
    'stop' => 'arreter', 'arrêter' => 'arreter', 'status' => 'etat', 'état' => 'etat',
    'démo' => 'demo', 'demonstration' => 'demo', 'install' => 'installer', 'setup' => 'installer',
    'doctor' => 'diagnostic', 'help' => 'aide', 'shortcut' => 'raccourci',
    'update' => 'maj', 'mise-a-jour' => 'maj', 'mettre-a-jour' => 'maj',
];
$commande = $alias[$commande] ?? $commande;

exit(match ($commande) {
    'demarrer'   => cmd_demarrer($options),
    'installer'  => cmd_installer($options),
    'demo'       => cmd_demo($options, $arguments),
    'maj'        => cmd_maj($options, $arguments),
    'arreter'    => cmd_arreter($options),
    'etat'       => cmd_etat($options),
    'raccourci'  => cmd_raccourci($options),
    'diagnostic' => cmd_diagnostic($options),
    'menu'       => cmd_menu($options),
    'aide'       => cmd_aide(),
    'version'    => (function () { echo 'Larka ' . lk_version() . ' — PHP ' . PHP_VERSION . "\n"; return 0; })(),
    'php'        => (function () {
        alerte('La mise à jour de PHP se lance depuis Larka.cmd : Larka.cmd php');
        return LK_SORTIE_MAJ_PHP;
    })(),
    default      => (function () use ($commande) { alerte("Commande inconnue : $commande"); cmd_aide(); return 2; })(),
});

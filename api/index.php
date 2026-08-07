<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * Larka — Point d'entrée API (routeur principal)
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Reçoit toutes les requêtes via ?action=xxx et les dispatch vers les routes.
 *
 * FLUX :
 *   1. Charge config.php (lecture config.json → constantes)
 *   2. Charge Database.php (connexion PDO, init schéma)
 *   3. Applique headers CORS / sécurité HTTP
 *   4. Dispatch vers le bon fichier routes/*.php selon $action
 *
 * FONCTIONS UTILITAIRES :
 *   - json_ok($data)         → réponse JSON succès
 *   - json_error($msg,$code) → réponse JSON erreur
 *   - get_body()             → lecture du body JSON de la requête
 *   - require_auth()         → vérifie la session utilisateur
 *   - require_role($user,$r) → vérifie le rôle (Admin a tous les droits)
 *
 * ROUTES INCLUSES :
 *   auth.php, inventaire.php, maintenance.php, energie.php, gestion.php,
 *   stats.php, documents.php, archives.php, france.php, chorus.php,
 *   assistant.php, superadmin.php, migration.php
 * ═══════════════════════════════════════════════════════════════════════════════
 */

// ── Bootstrap ─────────────────────────────────────────────────────────────────
ob_start();
ini_set('display_errors', '0');
error_reporting(0);

// Gestionnaire global : toutes les erreurs PHP produisent du JSON valide
set_error_handler(function ($severity, $message, $file, $line) {
    // Ignorer les erreurs supprimées avec @
    if (!(error_reporting() & $severity)) return true;
    throw new ErrorException($message, 0, $severity, $file, $line);
});
set_exception_handler(function (\Throwable $e) {
    if (ob_get_level()) ob_end_clean();
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
    }
    // ⚠️ FIX SÉCURITÉ (F1) : ne pas divulguer le détail de l'exception au client
    // (chemins, requêtes SQL, structure interne). On journalise côté serveur et
    // on renvoie un identifiant de corrélation. Le détail n'est renvoyé que si
    // securite.debug_errors=true (constante DEBUG_ERRORS).
    $ref = bin2hex(random_bytes(4));
    error_log('[Larka][' . $ref . '] Uncaught ' . get_class($e) . ': ' . $e->getMessage()
        . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $showDetail = defined('DEBUG_ERRORS') && DEBUG_ERRORS;
    echo json_encode([
        'success' => false,
        'error'   => $showDetail
            ? ('Erreur serveur : ' . $e->getMessage())
            : ('Erreur serveur interne. Référence : ' . $ref),
        'ref'     => $ref,
    ], JSON_UNESCAPED_UNICODE);
    exit;
});
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (ob_get_level()) ob_end_clean();
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
        }
        // ⚠️ FIX SÉCURITÉ (F1) : message générique + référence, détail en log.
        $ref = bin2hex(random_bytes(4));
        error_log('[Larka][' . $ref . '] Fatal: ' . $err['message']
            . ' @ ' . ($err['file'] ?? '?') . ':' . ($err['line'] ?? '?'));
        $showDetail = defined('DEBUG_ERRORS') && DEBUG_ERRORS;
        echo json_encode([
            'success' => false,
            'error'   => $showDetail
                ? ('Erreur fatale : ' . $err['message'])
                : ('Erreur fatale interne. Référence : ' . $ref),
            'ref'     => $ref,
        ], JSON_UNESCAPED_UNICODE);
    }
});

// S'assurer que le dossier data existe
$_dataDir = __DIR__ . '/../data';
if (!is_dir($_dataDir)) @mkdir($_dataDir, 0755, true);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/SecurityLog.php';
require_once __DIR__ . '/Journal.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/MsGraph.php';    // Helpers Microsoft Graph (présence, agenda)

// ── Journalisation : chrono + capteurs globaux ───────────────────────────────
// Installés le plus tôt possible pour que même une erreur de bootstrap soit
// tracée. Les entrées émises avant l'ouverture de la base sont mises en tampon
// par Journal et écrites dès que la connexion est disponible.
Journal::demarrerChrono();

// Exceptions non rattrapées.
set_exception_handler(function (\Throwable $e) {
    Journal::critique('php', 'Exception non rattrapée : ' . $e->getMessage(), [
        'exception' => $e, 'codeHttp' => 500, 'dureeMs' => Journal::dureeMs(),
    ]);
    if (!headers_sent()) { http_response_code(500); }
    if (ob_get_level()) ob_clean();
    echo json_encode(['success' => false, 'error' => 'Erreur serveur interne.',
                      'reference' => Journal::requestId()], JSON_UNESCAPED_UNICODE);
    exit;
});

// Erreurs PHP (warnings, notices, deprecated) — converties en entrées de journal.
set_error_handler(function ($niveau, $message, $fichier, $ligne) {
    if (!(error_reporting() & $niveau)) return false; // respecte @ et error_reporting
    $map = [
        E_WARNING => Journal::WARNING, E_USER_WARNING => Journal::WARNING,
        E_NOTICE  => Journal::INFO,    E_USER_NOTICE  => Journal::INFO,
        E_DEPRECATED => Journal::INFO, E_USER_DEPRECATED => Journal::INFO,
    ];
    Journal::log($map[$niveau] ?? Journal::WARNING, 'php', $message, [
        'fichier' => basename((string)$fichier), 'ligne' => $ligne, 'niveauPhp' => $niveau,
    ]);
    // On CONSERVE la stratégie « fail-fast » installée plus haut (ligne ~42) :
    // toute erreur PHP devient une exception, qui produit une réponse JSON
    // propre. Journaliser ne doit pas changer le comportement de l'application,
    // seulement le rendre observable.
    throw new ErrorException($message, 0, $niveau, $fichier, $ligne);
});

// Erreurs fatales + clôture de requête. Les routes se terminent par exit(),
// donc c'est le SEUL endroit où l'on peut journaliser la fin d'une requête.
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        Journal::critique('php', 'Erreur fatale : ' . $err['message'], [
            'fichier' => basename((string)$err['file']), 'ligne' => $err['line'],
            'codeHttp' => 500, 'dureeMs' => Journal::dureeMs(),
        ]);
        return;
    }
    // Requête terminée normalement : une ligne d'accès par appel API.
    if (!defined('LARKA_ACTION_COURANTE')) return;
    $code = http_response_code() ?: 200;
    $duree = Journal::dureeMs();
    // Les erreurs serveur et les lenteurs méritent d'être visibles d'un coup d'œil.
    $niveau = $code >= 500 ? Journal::ERROR
            : ($code >= 400 ? Journal::WARNING
            : (($duree !== null && $duree > 2000) ? Journal::WARNING : Journal::INFO));
    Journal::log($niveau, 'api', LARKA_ACTION_COURANTE, [
        'action'   => LARKA_ACTION_COURANTE,
        'codeHttp' => $code,
        'dureeMs'  => $duree,
        'lente'    => ($duree !== null && $duree > 2000) ?: null,
    ]);
});

if (ob_get_level()) ob_clean();

header('Content-Type: application/json; charset=utf-8');

// ── CORS ────────────────────────────────────────────────────────────────────
$_reqOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array('*', CORS_ORIGINS)) {
    // FIX SÉCURITÉ : `Access-Control-Allow-Origin: *` est incompatible avec
    // credentials. Et reflêter l'origin du client AVEC credentials est une
    // CORS misconfiguration classique (n'importe quel site peut faire des
    // requêtes authentifiées). Si la config est `origins:["*"]` + credentials,
    // on REFUSE et on log l'erreur — l'admin doit fournir une whitelist explicite.
    if (CORS_CREDENTIALS) {
        error_log('Larka SECURITY: refus CORS — cors.origins:["*"] avec cors.allow_credentials:true. Listez explicitement les origines autorisées.');
        // On n'envoie PAS de header CORS → le navigateur refusera la requête cross-origin.
        // Les requêtes same-origin continuent de fonctionner.
    } else {
        // Pas de credentials : le wildcard est sûr.
        header('Access-Control-Allow-Origin: *');
    }
} elseif ($_reqOrigin && in_array($_reqOrigin, CORS_ORIGINS)) {
    header('Access-Control-Allow-Origin: ' . $_reqOrigin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: ' . CORS_METHODS);
header('Access-Control-Allow-Headers: ' . CORS_HEADERS);
if (CORS_CREDENTIALS) header('Access-Control-Allow-Credentials: true');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ── En-têtes sécurité HTTP ───────────────────────────────────────────────────
header("Content-Security-Policy: "        . SEC_CSP);
header("X-Frame-Options: "                . SEC_X_FRAME);
header("X-Content-Type-Options: "         . SEC_X_CONTENT_TYPE);
header("Referrer-Policy: "                . SEC_REFERRER);
header("Permissions-Policy: camera=(self), microphone=(), geolocation=()");
header("X-Permitted-Cross-Domain-Policies: none");
header("Cache-Control: no-store, no-cache, must-revalidate, private");
header("Pragma: no-cache");
if (SEC_HSTS) header("Strict-Transport-Security: max-age=" . SEC_HSTS_MAX_AGE . "; includeSubDomains");

// ── Protection CSRF : vérifier Content-Type JSON sur les requêtes mutantes ──
// Liste blanche des actions qui acceptent légitimement multipart/form-data
// (upload de fichier). Pour toutes les autres, on impose JSON.
$MULTIPART_ALLOWED_ACTIONS = [
    'superadmin_tenant_import_db',
    'plans_upload_fond',
    'plans_import',
];
if (in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT', 'DELETE'], true)) {
    $ct = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    $_currentAction = $_GET['action'] ?? $_POST['action'] ?? '';
    $_isMultipartWhitelisted = in_array($_currentAction, $MULTIPART_ALLOWED_ACTIONS, true);

    if ($ct && !str_contains(strtolower($ct), 'application/json')) {
        // ⚠️ FIX SÉCURITÉ (CSRF) : liste blanche stricte. Tout Content-Type
        // non-JSON est refusé sur une requête mutante, SAUF le multipart des
        // actions d'upload explicitement whitelistées. L'ancienne liste noire
        // laissait passer 'text/plain' — un type "simple" CORS envoyable en
        // cross-site SANS preflight, alors que get_body() lit le JSON quel que
        // soit le Content-Type. C'était donc un contournement CSRF direct.
        // (Requêtes sans body → pas de Content-Type → non concernées.)
        $isWhitelistedMultipart = $_isMultipartWhitelisted
            && str_contains(strtolower($ct), 'multipart/form-data');
        if (!$isWhitelistedMultipart) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Content-Type non autorisé.']);
            exit;
        }
    }
}

// ── Helpers globaux ───────────────────────────────────────────────────────────
function json_ok($data): void {
    if (ob_get_level()) ob_clean();
    echo json_encode(['success'=>true,'data'=>$data], JSON_UNESCAPED_UNICODE); exit;
}
function json_error($message, $code = 400): void {
    if (ob_get_level()) ob_clean();
    http_response_code($code);
    echo json_encode(['success'=>false,'error'=>$message], JSON_UNESCAPED_UNICODE); exit;
}
function get_body(): array {
    $raw = file_get_contents('php://input');
    // Limiter la taille du body à 10 Mo
    if ($raw !== false && strlen($raw) > 10 * 1024 * 1024) {
        json_error('Requête trop volumineuse.', 413);
    }
    return json_decode($raw ?: '', true) ?? [];
}

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

// ── Expiration de session ────────────────────────────────────────────────────
if (!empty($_SESSION['user'])) {
    $lastActivity = $_SESSION['_last_activity'] ?? 0;
    $maxLifetime  = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : 86400;
    if ($lastActivity && (time() - $lastActivity) > $maxLifetime) {
        $_SESSION = [];
        session_destroy();
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    }
}
// Expiration session super admin (4h max)
if (!empty($_SESSION['superadmin']['authenticated'])) {
    $saTs = $_SESSION['superadmin']['ts'] ?? 0;
    if ($saTs && (time() - $saTs) > 14400) {
        unset($_SESSION['superadmin']);
    }
}
$_SESSION['_last_activity'] = time();

function require_auth(): array {
    if (!empty($_SESSION['user'])) return $_SESSION['user'];
    // Le Super Admin n'a pas de $_SESSION['user'] mais doit pouvoir
    // accéder aux routes admin (push_test, push_config, etc.) depuis la Configuration.
    // On retourne un user virtuel avec le rôle Admin.
    if (!empty($_SESSION['superadmin']['authenticated'])) {
        return [
            'Id'    => 0,
            'Login' => $_SESSION['superadmin']['login'] ?? 'superadmin',
            'Role'  => 'Gestionnaire',
            'Nom'   => 'Super',
            'Prenom'=> 'Admin',
        ];
    }
    json_error('Non authentifié.', 401);
}
function require_role(array $user, array $roles): void {
    // Gestionnaire = rôle administrateur (Admin conservé pour rétrocompatibilité)
    if ($user['Role'] === 'Gestionnaire' || $user['Role'] === 'Admin') return;
    if (!in_array($user['Role'], $roles)) json_error('Permission insuffisante.', 403);
}

/**
 * Onglets qu'un Demandeur donné est autorisé à CONSULTER, en plus de son
 * périmètre habituel.
 *
 * L'autorisation est STRICTEMENT INDIVIDUELLE : elle est portée par la colonne
 * Utilisateurs.AccesLecture et se règle sur la fiche du compte. Il n'existe
 * volontairement aucun réglage collectif — ouvrir un onglet à « tous les
 * demandeurs » d'un seul geste rendrait trop facile d'exposer des données à des
 * agents qui n'en ont pas l'usage.
 *
 * Lu EN BASE à chaque requête, jamais depuis la session : une révocation doit
 * prendre effet tout de suite, sans attendre que l'agent se reconnecte. Le
 * cache est indexé par utilisateur, le temps de la requête HTTP seulement.
 */
function demandeur_modules_lecture($db, array $user = []): array {
    static $cache = [];
    $uid = isset($user['Id']) ? (int)$user['Id'] : 0;
    if ($uid <= 0) return [];
    if (!array_key_exists($uid, $cache)) {
        try { $cache[$uid] = $db->getAccesLecture($uid) ?? []; }
        catch (\Throwable $e) { $cache[$uid] = []; }
    }
    return $cache[$uid];
}

/**
 * Garde de LECTURE seule, en remplacement de require_role() sur les routes GET.
 *
 * Comportement identique à require_role(), avec une exception : un Demandeur
 * est admis si cet onglet lui a été ouvert nominativement.
 *
 * ⚠️ À n'utiliser QUE sur des routes de lecture. L'écriture reste protégée par
 * require_role() : l'autorisation accordée ici ne confère jamais le droit de
 * modifier quoi que ce soit, quel que soit l'onglet ouvert.
 */
function require_lecture(array $user, array $roles, $module, $db = null): void {
    if ($user['Role'] === 'Gestionnaire' || $user['Role'] === 'Admin') return;
    if (in_array($user['Role'], $roles)) return;
    if ($user['Role'] === 'Demandeur' && $db !== null) {
        // $module accepte un tableau : certaines routes alimentent plusieurs
        // onglets (les compteurs servent aussi bien Énergie que Bilan Carbone).
        // Disposer de l'un OU l'autre suffit alors à consulter la donnée.
        $requis  = is_array($module) ? $module : [$module];
        $accordes = demandeur_modules_lecture($db, $user);
        if (array_intersect($requis, $accordes)) return;
    }
    json_error('Permission insuffisante.', 403);
}

// ── Paramètres de la requête ──────────────────────────────────────────────────
$action = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['action'] ?? '');
$id     = isset($_GET['id']) ? (int)$_GET['id'] : null;
$method = $_SERVER['REQUEST_METHOD'];
// Rendue disponible au hook de fin de requête (register_shutdown_function).
define('LARKA_ACTION_COURANTE', $action !== '' ? $action : '(vide)');

// Validation action
if (strlen($action) > 50) json_error('Action invalide.', 400);

// ── Blocage si l'utilisateur doit changer son mot de passe ──────────────────
// Pour les comptes par défaut (admin/admin) ou réinitialisés avec un flag forcé,
// on refuse toute action sauf : se déconnecter, lire son profil, changer son mdp.
if (!empty($_SESSION['user']['MustChangePassword']) && (int)$_SESSION['user']['MustChangePassword'] === 1) {
    $_allowedWhenMustChange = ['logout', 'me', 'change_password', 'auth_config', 'server_url'];
    if (!in_array($action, $_allowedWhenMustChange, true)) {
        json_error('Vous devez changer votre mot de passe avant de continuer.', 403);
    }
}

// ── Rate limiting simple (fichier) pour le login et l'API globale ───────────
// ⚠️ FIX BUG : la définition de cette fonction est volontairement placée
// AVANT ses appels. PHP hoist les fonctions nommées donc l'ancien ordre
// fonctionnait, mais c'était fragile (ex. si on remplaçait par une closure
// ou une fonction conditionnelle). Plus robuste de garder l'ordre logique.
function check_rate_limit(string $key, int $maxAttempts = 10, int $windowSeconds = 300): void {
    $dir = __DIR__ . '/../data/rate_limits';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $file = $dir . '/' . sha1($key) . '.json';
    $data = file_exists($file) ? (json_decode(file_get_contents($file), true) ?? []) : [];
    $now  = time();
    // Nettoyer les anciennes entrées
    $data = array_filter($data, fn($t) => ($now - $t) < $windowSeconds);
    if (count($data) >= $maxAttempts) {
        // Log de sécurité — utile pour détecter les bruteforce
        if (class_exists('SecurityLog')) {
            SecurityLog::rateLimited($key, $maxAttempts);
        }
        json_error('Trop de tentatives. Réessayez dans quelques minutes.', 429);
    }
    $data[] = $now;
    @file_put_contents($file, json_encode(array_values($data)));
}

// ── Rate limiting global par IP (200 req / 60s — anti-DDoS basique) ─────────
$_clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if ($action && !in_array($action, ['auth_config', 'server_url', 'push_vapid_key'])) {
    check_rate_limit('global_' . $_clientIp, 200, 60);
}


// Certaines routes publiques ne nécessitent pas la base de données.
// Les exécuter avant d'instancier Database() évite qu'un souci PDO empêche
// l'affichage des boutons SSO ou la découverte de l'URL serveur.
$dbOptionalActions = ['auth_config', 'server_url', 'oauth_microsoft_url', 'push_vapid_key',
    'legifrance_status', 'legifrance_search', 'legifrance_suggest', 'legifrance_consult',
    'france_legifrance_config', 'france_legifrance_ping', 'france_legifrance_search',
    'france_legifrance_suggest', 'france_legifrance_call',
    'chorus_visibility', 'chorus_status', 'chorus_token_test', 'chorus_call',
    'superadmin_login', 'superadmin_me', 'superadmin_logout', 'superadmin_status',
    'superadmin_tenants', 'superadmin_tenant_save', 'superadmin_tenant_delete',
    'superadmin_switch_tenant', 'superadmin_test_db', 'superadmin_provision',
    'superadmin_change_password', 'superadmin_setup',
    'superadmin_tenant_users', 'superadmin_tenant_user_save', 'superadmin_tenant_user_delete',
    'superadmin_local_accounts', 'superadmin_sync_accounts', 'superadmin_monitoring',
    'superadmin_branding_public',
    'config_serveur'];

if (in_array($action, $dbOptionalActions, true)) {
    try {
        require_once __DIR__ . '/routes/superadmin.php'; // Multi-tenant management
        require __DIR__ . '/routes/auth.php';
        require __DIR__ . '/routes/gestion.php';
        require_once __DIR__ . '/routes/france.php';    // Légifrance proxy (france_legifrance_*)
        require_once __DIR__ . '/routes/chorus.php';   // Chorus Pro proxy
        require_once __DIR__ . '/routes/assistant.php'; // Assistant IA
        require_once __DIR__ . '/routes/push.php';     // Push notifications (vapid_key public)
        json_error("Action inconnue : $action", 404);
    } catch (\Throwable $e) {
        json_error('Erreur serveur : ' . $e->getMessage(), 500);
    }
}

// ── Dispatch des routes ───────────────────────────────────────────────────────
try {
    $db = new Database();
    // À partir d'ici le journal écrit en base (et vide son tampon de bootstrap).
    Journal::attacherBase($db->getPdo());

    require_once __DIR__ . '/routes/superadmin.php'; // Multi-tenant (avant auth pour le switch)
    require __DIR__ . '/routes/auth.php';        // Login, logout, OAuth, session
    require __DIR__ . '/routes/inventaire.php';  // Biens, équipements, stock, lignes interventions
    require __DIR__ . '/routes/maintenance.php'; // Interventions, contrats
    require __DIR__ . '/routes/energie.php';     // Compteurs et relevés énergie
    require __DIR__ . '/routes/gestion.php';     // Gestion matériel, utilisateurs, historique, demandes, listes, config
    require __DIR__ . '/routes/stats.php';       // Dashboard, stats, stats complètes
    require __DIR__ . '/routes/documents.php';   // Upload, download, suppression de fichiers
    require __DIR__ . '/routes/archives.php';    // Boîtes, dossiers, bordereaux archives
    require_once __DIR__ . '/routes/france.php'; // Légifrance sandbox (france_legifrance_*)
    require_once __DIR__ . '/routes/chorus.php'; // Chorus Pro proxy
    require_once __DIR__ . '/routes/assistant.php'; // Assistant IA
    require_once __DIR__ . '/routes/push.php';      // Push notifications
    require __DIR__ . '/routes/mobilite.php';    // Mobilité carbone (demandeurs)
    require_once __DIR__ . '/routes/carbone.php'; // Facteurs d'émission ADEME (proxy + cache)
    require __DIR__ . '/routes/plans.php';       // Plans interactifs (bâtiments, étages, éléments)
    require __DIR__ . '/routes/annuaire.php';    // Annuaire cartographié (Plans côté Demandeur)
    // ⚠️ ORDRE IMPORTANT : journal.php DOIT être chargé APRÈS annuaire.php.
    //    L'action presence_effectif s'appuie sur _annuaireAppToken() et
    //    _annuaireGraphPost(), définies dans annuaire.php. Les routes étant
    //    de simples require successifs, une inclusion plus haut ferait
    //    échouer le test function_exists() et renverrait « module annuaire
    //    non chargé » alors que tout est correctement configuré.
    require __DIR__ . '/routes/journal.php';     // Journal applicatif + présence
    require __DIR__ . '/routes/urgences.php';    // Médias (photos/vidéos) des procédures d'urgence
    require __DIR__ . '/routes/migration.php';   // Migration inter-drivers (SQLite ↔ PG ↔ MariaDB)

    json_error("Action inconnue : $action", 404);

} catch (PDOException $e) {
    // ⚠️ FIX SÉCURITÉ (F1) : le diagnostic détaillé ci-dessous expose l'hôte, le
    // port, le nom de base, l'utilisateur et les chemins. Il ne doit JAMAIS sortir
    // en production. On journalise systématiquement, et on ne renvoie le détail
    // au client que si securite.debug_errors=true.
    $ref = bin2hex(random_bytes(4));
    error_log('[Larka][' . $ref . '][DB] ' . $e->getMessage()
        . ' (driver=' . DB_DRIVER . ', code=' . $e->getCode() . ')');
    if (!(defined('DEBUG_ERRORS') && DEBUG_ERRORS)) {
        json_error('Erreur base de données. Référence : ' . $ref . '. Consultez les journaux serveur.', 500);
    }

    $driver  = DB_DRIVER;
    $message = $e->getMessage();
    $code    = $e->getCode();
    $details = [];

    // ── Diagnostic selon le driver et le code d'erreur ────────────────────
    if ($driver === 'pgsql') {
        $ext = extension_loaded('pdo_pgsql') ? '✅ chargée' : '❌ MANQUANTE (activer php_pdo_pgsql dans php.ini)';
        $details[] = "Extension PHP pdo_pgsql : $ext";
        $details[] = "Serveur cible : " . DB_HOST . ":" . DB_PORT . " / base : " . DB_NAME . " / user : " . DB_USER;

        // Connection refused ou host unreachable
        if (str_contains($message, 'Connection refused') || str_contains($message, '10061') || $code === 7) {
            $details[] = "⚠️  PostgreSQL n'est pas démarré ou n'écoute pas sur ce port.";
            $details[] = "→ Installez PostgreSQL : https://www.postgresql.org/download/";
            $details[] = "→ Ou repassez en SQLite dans config.json : base_de_donnees.driver = \"sqlite\"";
        } elseif (str_contains($message, 'password authentication failed')) {
            $details[] = "⚠️  Mot de passe PostgreSQL incorrect.";
            $details[] = "→ Vérifiez base_de_donnees.password dans config.json";
        } elseif (str_contains($message, 'database') && str_contains($message, 'does not exist')) {
            $details[] = "⚠️  La base \"" . DB_NAME . "\" n'existe pas.";
            $details[] = "→ Créez-la : CREATE DATABASE " . DB_NAME . ";";
        } elseif (str_contains($message, 'could not find driver') || !extension_loaded('pdo_pgsql')) {
            $details[] = "⚠️  L'extension PHP pdo_pgsql n'est pas activée.";
            $details[] = "→ Ajoutez extension=php_pdo_pgsql.dll dans php.ini";
        } elseif (str_contains($message, 'role') && str_contains($message, 'does not exist')) {
            $details[] = "⚠️  L'utilisateur PostgreSQL \"" . DB_USER . "\" n'existe pas.";
            $details[] = "→ Créez-le : CREATE USER " . DB_USER . " WITH PASSWORD '...';";
        }

    } elseif ($driver === 'mariadb' || $driver === 'mysql') {
        $ext = extension_loaded('pdo_mysql') ? '✅ chargée' : '❌ MANQUANTE (activer php_pdo_mysql dans php.ini)';
        $details[] = "Extension PHP pdo_mysql : $ext";
        $details[] = "Serveur cible : " . DB_HOST . ":" . DB_PORT . " / base : " . DB_NAME . " / user : " . DB_USER;

        if (str_contains($message, 'Connection refused') || str_contains($message, '10061') || $code === 2002) {
            $details[] = "⚠️  MySQL/MariaDB n'est pas démarré ou refuse la connexion.";
            $details[] = "→ Installez MariaDB : https://mariadb.org/download/";
            $details[] = "→ Ou repassez en SQLite dans config.json : base_de_donnees.driver = \"sqlite\"";
        } elseif (str_contains($message, 'Access denied')) {
            $details[] = "⚠️  Accès refusé — mauvais identifiants MySQL.";
            $details[] = "→ Vérifiez base_de_donnees.user et base_de_donnees.password dans config.json";
        } elseif (str_contains($message, 'Unknown database')) {
            $details[] = "⚠️  La base \"" . DB_NAME . "\" n'existe pas.";
            $details[] = "→ Créez-la : CREATE DATABASE " . DB_NAME . " CHARACTER SET utf8mb4;";
        } elseif (!extension_loaded('pdo_mysql')) {
            $details[] = "⚠️  L'extension PHP pdo_mysql n'est pas activée.";
            $details[] = "→ Ajoutez extension=php_pdo_mysql.dll dans php.ini";
        }

    } elseif ($driver === 'sqlite') {
        $ext = extension_loaded('pdo_sqlite') ? '✅ chargée' : '❌ MANQUANTE';
        $details[] = "Extension PHP pdo_sqlite : $ext";
        $details[] = "Fichier DB : " . DB_PATH;
        if (!is_writable(dirname(DB_PATH)) && is_dir(dirname(DB_PATH))) {
            $details[] = "⚠️  Le dossier data/ n'est pas accessible en écriture.";
        }
    }

    $details[] = "Driver configuré : $driver";
    $details[] = "Code erreur PDO : $code";

    // (Atteint uniquement en mode debug_errors — voir garde plus haut.)
    json_error(
        "Erreur base de données : $message\n\n" . implode("\n", $details) . "\n\nRéférence : $ref",
        500
    );

} catch (RuntimeException $e) {
    // ⚠️ FIX SÉCURITÉ (F1) : erreur de config — détail en log, message générique.
    $ref = bin2hex(random_bytes(4));
    error_log('[Larka][' . $ref . '][CFG] ' . $e->getMessage());
    json_error((defined('DEBUG_ERRORS') && DEBUG_ERRORS)
        ? ('Erreur de configuration : ' . $e->getMessage())
        : ('Erreur de configuration. Référence : ' . $ref), 500);
} catch (\Throwable $e) {
    // ⚠️ FIX SÉCURITÉ (F1) : erreur générique — détail en log uniquement.
    $ref = bin2hex(random_bytes(4));
    error_log('[Larka][' . $ref . '] ' . get_class($e) . ': ' . $e->getMessage()
        . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_error((defined('DEBUG_ERRORS') && DEBUG_ERRORS)
        ? ('Erreur serveur : ' . $e->getMessage())
        : ('Erreur serveur interne. Référence : ' . $ref), 500);
}

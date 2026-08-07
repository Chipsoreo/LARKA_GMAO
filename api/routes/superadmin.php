<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka — Routes : Super Administration (multi-tenant)
 *
 * Actions : superadmin_login/logout/me/status, superadmin_tenants,
 *           superadmin_tenant_save/delete, superadmin_provision,
 *           superadmin_test_db, superadmin_tenant_users, etc.
 */

// ══════════════════════════════════════════════════════════════════════════════
//  HELPERS D'AUTHENTIFICATION SUPER ADMIN
//  Définis tout en haut pour être disponibles dans TOUS les handlers ci-dessous
//  (un handler comme superadmin_me, plus haut dans le flux, peut les appeler).
// ══════════════════════════════════════════════════════════════════════════════

// ── Helper : vérifier authentification super admin ────────────────────────────
if (!function_exists('require_superadmin')) {
    function require_superadmin(): void {
        if (empty($_SESSION['superadmin']['authenticated'])) {
            json_error('Accès réservé au super administrateur.', 403);
        }
    }
}

// ── Helper : le super admin courant est-il le compte PRINCIPAL (original) ? ────
// Seul ce compte a le droit de gérer (ajouter/supprimer) les autres comptes SA.
if (!function_exists('superadmin_is_primary')) {
    function superadmin_is_primary(): bool {
        if (empty($_SESSION['superadmin']['authenticated'])) return false;
        $main = TenantResolver::getSuperAdmin();
        if (!$main || empty($main['login'])) return false;
        $sessLogin = (string)($_SESSION['superadmin']['login'] ?? '');
        if ($sessLogin === '') return false;
        // Connexion locale : le login de session doit être le login principal.
        if (hash_equals((string)$main['login'], $sessLogin)) return true;
        // Connexion Microsoft / e-mail : on compare l'e-mail principal (insensible à la casse).
        $mainEmail = strtolower(trim((string)($main['email'] ?? '')));
        if ($mainEmail !== '' && strtolower($sessLogin) === $mainEmail) return true;
        return false;
    }
}

// ── Helper : exiger le super admin PRINCIPAL (gestion des comptes SA) ──────────
if (!function_exists('require_primary_superadmin')) {
    function require_primary_superadmin(): void {
        require_superadmin();
        if (!superadmin_is_primary()) {
            json_error('Seul le super administrateur principal peut ajouter ou supprimer des comptes Super Admin.', 403);
        }
    }
}

// ── Connexion Super Admin ─────────────────────────────────────────────────────
if ($action === 'superadmin_login' && $method === 'POST') {
    $body = get_body();
    $login = trim($body['login'] ?? '');
    $password = $body['password'] ?? '';

    // Rate limiting
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    check_rate_limit('sa_login_' . $clientIp, 5, 600);

    if (!$login || !$password) json_error('Identifiant et mot de passe requis.');

    if (!TenantResolver::authenticateSuperAdmin($login, $password)) {
        json_error('Identifiant ou mot de passe super admin incorrect.', 401);
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }

    $_SESSION['superadmin'] = [
        'login' => $login,
        'authenticated' => true,
        'ts' => time(),
    ];

    json_ok([
        'login' => $login,
        'role' => 'SuperAdmin',
        'is_primary' => superadmin_is_primary(),
        'tenants' => array_keys(TenantResolver::getAllTenants()),
    ]);
}

// ── Connexion Super Admin via Microsoft OAuth ─────────────────────────────────
if ($action === 'superadmin_microsoft_url' && $method === 'GET') {
    if (!MICROSOFT_ENABLED || !MICROSOFT_CLIENT_ID) {
        json_error('Microsoft OAuth non configuré.');
    }
    $state = 'sa_' . bin2hex(random_bytes(16));
    $_SESSION['sa_oauth_state'] = $state;
    // Stocker aussi dans un fichier per-state (cross-session)
    $stateDir = __DIR__ . '/../../data/oauth_states';
    if (!is_dir($stateDir)) @mkdir($stateDir, 0700, true);
    file_put_contents($stateDir . '/' . hash('sha256', $state) . '.json', json_encode(['state' => $state, 'ts' => time()]));

    $redirectUri = MICROSOFT_REDIRECT_URI;
    $params = http_build_query([
        'client_id'     => MICROSOFT_CLIENT_ID,
        'response_type' => 'code',
        'redirect_uri'  => $redirectUri,
        'scope'         => 'openid profile email User.Read',
        'response_mode' => 'query',
        'state'         => $state,
        'prompt'        => 'select_account',
    ]);
    json_ok(['url' => 'https://login.microsoftonline.com/' . MICROSOFT_TENANT_ID . '/oauth2/v2.0/authorize?' . $params]);
}

// ── Callback OAuth Super Admin (vérification après redirection) ───────────────
if ($action === 'superadmin_oauth_check' && $method === 'POST') {
    // ⚠️ FIX SÉCURITÉ (CRITIQUE) : ne JAMAIS accorder l'accès super-admin à
    // partir d'un email fourni dans le corps de la requête. L'identité est
    // toujours établie par le serveur après vérification Microsoft. Deux voies :
    //   (1) la session SA a déjà été posée par oauth/microsoft.php (même origine
    //       → même cookie de session) ;
    //   (2) un jeton à usage unique, écrit par le serveur, qui porte l'email
    //       VÉRIFIÉ (le client ne fournit que le jeton opaque).

    // ── Voie 1 : session déjà authentifiée côté serveur ──────────────────────
    if (!empty($_SESSION['superadmin']['authenticated'])) {
        json_ok([
            'login'      => $_SESSION['superadmin']['login'] ?? '',
            'role'       => 'SuperAdmin',
            'provider'   => $_SESSION['superadmin']['provider'] ?? 'microsoft',
            'is_primary' => superadmin_is_primary(),
            'tenants'    => array_keys(TenantResolver::getAllTenants()),
        ]);
    }

    // ── Voie 2 : jeton à usage unique émis par le serveur ────────────────────
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    check_rate_limit('sa_oauth_' . $clientIp, 10, 600);

    $body  = get_body();
    $token = trim($body['token'] ?? '');
    if ($token === '' || !preg_match('/^[a-f0-9]{48}$/', $token)) {
        json_error('Session OAuth invalide ou expirée. Veuillez réessayer.', 401);
    }

    $tokFile = __DIR__ . '/../../data/sa_oauth_result_' . $token . '.json';
    if (!is_file($tokFile)) {
        json_error('Session OAuth invalide ou expirée. Veuillez réessayer.', 401);
    }
    $data = json_decode((string)@file_get_contents($tokFile), true);
    @unlink($tokFile); // usage strictement unique

    $email = strtolower(trim($data['email'] ?? ''));
    if ($email === '' || (time() - (int)($data['ts'] ?? 0)) > 300) {
        json_error('Session OAuth invalide ou expirée. Veuillez réessayer.', 401);
    }

    // Re-vérifier côté serveur que cet email (vérifié par Microsoft) est autorisé.
    $authorized = false;
    foreach (TenantResolver::getSuperAdminEmails() as $allowed) {
        if (strtolower(trim((string)$allowed)) === $email) { $authorized = true; break; }
    }
    if (!$authorized) {
        json_error('Cet email n\'est pas autorisé comme super administrateur.', 403);
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    $_SESSION['superadmin'] = [
        'login'         => $email,
        'authenticated' => true,
        'provider'      => 'microsoft',
        'ts'            => time(),
    ];

    json_ok([
        'login'      => $email,
        'role'       => 'SuperAdmin',
        'provider'   => 'microsoft',
        'is_primary' => superadmin_is_primary(),
        'tenants'    => array_keys(TenantResolver::getAllTenants()),
    ]);
}

// ── Vérification session super admin ──────────────────────────────────────────
if ($action === 'superadmin_me' && $method === 'GET') {
    if (empty($_SESSION['superadmin']['authenticated'])) {
        json_error('Non authentifié en tant que super admin.', 401);
    }
    json_ok([
        'login' => $_SESSION['superadmin']['login'],
        'role' => 'SuperAdmin',
        'provider' => $_SESSION['superadmin']['provider'] ?? 'local',
        'is_primary' => superadmin_is_primary(),
        'tenants' => array_keys(TenantResolver::getAllTenants()),
    ]);
}

// ── Déconnexion Super Admin ───────────────────────────────────────────────────
if ($action === 'superadmin_logout' && $method === 'POST') {
    unset($_SESSION['superadmin'], $_SESSION['forced_tenant']);
    json_ok('Déconnecté du mode super admin.');
}

// ── Vérifier si le multi-tenant est activé ────────────────────────────────────
if ($action === 'superadmin_status' && $method === 'GET') {
    $sa = TenantResolver::getSuperAdmin();
    $hasSa = $sa !== null && !empty($sa['login']);
    $needsSetup = false;
    $isAuthenticated = !empty($_SESSION['superadmin']['authenticated']);
    // ⚠️ FIX SÉCURITÉ (F3) : ne JAMAIS révéler à un visiteur non authentifié que
    // le mot de passe super-admin par défaut est encore actif (password_verify du
    // défaut) — c'est une aide directe à la prise de contrôle. On n'expose sans
    // authentification que le cas « aucun super-admin configuré » (vrai premier
    // démarrage), nécessaire pour afficher l'assistant de configuration initiale.
    // Le paramètre ?check_setup n'ouvre plus cette divulgation.
    if (!$hasSa) {
        $needsSetup = true; // premier démarrage : aucun SA n'existe encore
    } elseif ($isAuthenticated && !empty($sa['mot_de_passe'])) {
        // Détail « mot de passe par défaut » réservé au super-admin authentifié.
        $needsSetup = password_verify('SuperAdmin2025!', $sa['mot_de_passe']);
    }
    json_ok([
        'multi_tenant' => TenantResolver::isMultiTenant(),
        'has_superadmin' => $hasSa,
        'needs_setup' => $needsSetup,
        'current_tenant' => TenantResolver::getTenantPublicInfo(),
        'microsoft_enabled' => MICROSOFT_ENABLED && MICROSOFT_CLIENT_ID !== '',
    ]);
}

// ── Gestion des comptes Super Admin ──────────────────────────────────────────
if ($action === 'superadmin_accounts' && $method === 'GET') {
    require_superadmin();
    $accounts = TenantResolver::getAllSuperAdmins();
    $emails = TenantResolver::getSuperAdminEmails();
    // Ne pas exposer les mots de passe hashés
    $safe = array_map(fn($a) => [
        'login' => $a['login'],
        'email' => $a['email'] ?? '',
        'is_primary' => ($a['login'] === TenantResolver::getSuperAdmin()['login']),
    ], $accounts);
    json_ok([
        'accounts' => $safe,
        'microsoft_emails' => $emails,
        // Indique si la session courante est le compte principal (seul habilité
        // à gérer les comptes SA). Permet au front de masquer les boutons.
        'current_is_primary' => superadmin_is_primary(),
    ]);
}

if ($action === 'superadmin_account_add' && $method === 'POST') {
    require_primary_superadmin();
    $body = get_body();
    $login = trim($body['login'] ?? '');
    $password = $body['password'] ?? '';
    $email = trim($body['email'] ?? '');
    if (!$login || !$password) json_error('Login et mot de passe requis.');
    if (strlen($password) < 8) json_error('Le mot de passe doit contenir au moins 8 caractères.');
    if (strlen($login) < 3) json_error('Le login doit contenir au moins 3 caractères.');
    // Caractères autorisés pour le login
    if (!preg_match('/^[a-zA-Z0-9._@-]+$/', $login)) json_error('Le login contient des caractères non autorisés.');
    $hashed = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    $ok = TenantResolver::addSuperAdminAccount($login, $hashed, $email);
    if (!$ok) json_error('Ce login existe déjà.');
    json_ok('Compte ajouté.');
}

if ($action === 'superadmin_account_remove' && $method === 'POST') {
    require_primary_superadmin();
    $body = get_body();
    $login = trim($body['login'] ?? '');
    if (!$login) json_error('Login requis.');
    // Empêcher la suppression du compte principal
    $main = TenantResolver::getSuperAdmin();
    if ($main && $main['login'] === $login) json_error('Impossible de supprimer le compte principal.');
    TenantResolver::removeSuperAdminAccount($login);
    json_ok('Compte supprimé.');
}

// ── Lister tous les tenants ───────────────────────────────────────────────────
if ($action === 'superadmin_tenants' && $method === 'GET') {
    require_superadmin();

    // ── Cache court (60s) pour éviter de re-tester toutes les DB à chaque
    //    appel. Si l'utilisateur force un refresh, il peut passer ?fresh=1.
    //    Le cache est stocké dans data/cache_assistant (déjà writable) pour
    //    éviter de créer un nouveau dossier.
    $cacheDir  = realpath(__DIR__ . '/../../data') ?: (__DIR__ . '/../../data');
    $cacheFile = $cacheDir . '/superadmin_tenants_cache.json';
    $fresh     = !empty($_GET['fresh']);
    if (!$fresh && is_file($cacheFile)) {
        $age = time() - filemtime($cacheFile);
        if ($age < 60) {
            $data = json_decode(@file_get_contents($cacheFile), true);
            if (is_array($data)) {
                // Ajouter un flag indiquant que ça vient du cache
                json_ok($data);
            }
        }
    }

    $tenants = TenantResolver::getAllTenants();
    $result = [];

    // ⚠️ FIX "RESTE EN CHARGEMENT" : budget de temps global. Même avec un
    //   connect_timeout par base, plusieurs tenants injoignables pourraient
    //   cumuler. Au-delà de ce budget, on cesse de TESTER la connectivité des
    //   tenants restants (statut 'unknown') et on renvoie quand même la liste :
    //   la page s'affiche, l'utilisateur peut cliquer « Rafraîchir » au besoin.
    $deadline = microtime(true) + 12.0;

    foreach ($tenants as $key => $tenant) {
        // Tester la connectivité DB
        $dbStatus = 'unknown';
        $dbSize = null;
        $userCount = null;
        $budgetDepasse = (microtime(true) > $deadline);
        try {
            $dbCfg = $tenant['base_de_donnees'] ?? null;
            if ($dbCfg) {
                $driver = $dbCfg['driver'] ?? 'sqlite';
                if ($driver === 'sqlite') {
                    $path = $dbCfg['path'] ?? '';
                    if (!str_starts_with($path, '/')) $path = __DIR__ . '/../../' . $path;
                    if (file_exists($path)) {
                        $dbStatus = 'ok';
                        $dbSize = filesize($path);   // taille du fichier .db
                        // Comptage SQLite : ouverture directe sans booter Database
                        if (!$budgetDepasse) {
                            try {
                                $pdo = new PDO('sqlite:' . $path);
                                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                                $userCount = (int) $pdo->query("SELECT COUNT(*) FROM Utilisateurs WHERE Actif=1")->fetchColumn();
                                $pdo = null;
                            } catch (\Exception $e) { /* table peut-être absente */ }
                        }
                    } else {
                        $dbStatus = 'missing';
                    }
                } elseif ($budgetDepasse) {
                    // Budget dépassé : on ne tente pas la connexion réseau.
                    $dbStatus = 'unknown';
                } else {
                    // Tester la connexion PG/MySQL avec un timeout court.
                    // ⚠️ FIX "CHARGE À L'INFINI" : PostgreSQL IGNORE PDO::ATTR_TIMEOUT
                    //   à la connexion → on borne via connect_timeout dans le DSN.
                    $dsn = ($driver === 'pgsql')
                        ? sprintf('pgsql:host=%s;port=%d;dbname=%s;connect_timeout=2', $dbCfg['host'] ?? '127.0.0.1', $dbCfg['port'] ?? 5432, $dbCfg['dbname'] ?? 'gmao')
                        : sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $dbCfg['host'] ?? '127.0.0.1', $dbCfg['port'] ?? 3306, $dbCfg['dbname'] ?? 'gmao');
                    $testPdo = new PDO($dsn, $dbCfg['user'] ?? '', $dbCfg['password'] ?? '', [
                        PDO::ATTR_TIMEOUT => 2,
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    ]);
                    $dbStatus = 'ok';
                    try {
                        $userCount = (int) $testPdo->query("SELECT COUNT(*) FROM Utilisateurs WHERE Actif=1")->fetchColumn();
                    } catch (\Exception $e) {
                        // Pour PG, la casse des noms de tables peut différer
                        try {
                            $userCount = (int) $testPdo->query('SELECT COUNT(*) FROM "utilisateurs" WHERE "actif"=1')->fetchColumn();
                        } catch (\Exception $e2) { /* abandonner */ }
                    }
                    // ⚠️ FIX "POIDS DU DRIVER" : la taille n'était calculée que pour
                    //   SQLite. On la calcule aussi pour PostgreSQL et MySQL afin que
                    //   la colonne « Taille » ne reste pas sur « — ».
                    try {
                        if ($driver === 'pgsql') {
                            $dbName = $dbCfg['dbname'] ?? 'gmao';
                            $stmt = $testPdo->prepare("SELECT pg_database_size(?)");
                            $stmt->execute([$dbName]);
                            $dbSize = (int) $stmt->fetchColumn();
                        } else { // mysql / mariadb
                            $dbName = $dbCfg['dbname'] ?? 'gmao';
                            $stmt = $testPdo->prepare("SELECT COALESCE(SUM(data_length + index_length),0) FROM information_schema.tables WHERE table_schema = ?");
                            $stmt->execute([$dbName]);
                            $dbSize = (int) $stmt->fetchColumn();
                        }
                    } catch (\Exception $e) { /* taille indisponible : on laisse null */ }
                    $testPdo = null;
                }
            }
        } catch (\Exception $e) {
            $dbStatus = 'error: ' . $e->getMessage();
        }

        $result[$key] = [
            'key' => $key,
            'nom' => $tenant['nom'] ?? $key,
            'actif' => $tenant['actif'] ?? true,
            'domaines_web' => $tenant['domaines_web'] ?? [],
            'domaines_email' => $tenant['domaines_email'] ?? [],
            'emails_exceptions' => $tenant['emails_exceptions'] ?? [],
            'base_de_donnees' => [
                'driver' => $tenant['base_de_donnees']['driver'] ?? 'sqlite',
                'path' => $tenant['base_de_donnees']['path'] ?? '',
                'host' => $tenant['base_de_donnees']['host'] ?? '',
                'port' => $tenant['base_de_donnees']['port'] ?? '',
                'dbname' => $tenant['base_de_donnees']['dbname'] ?? '',
                'user' => $tenant['base_de_donnees']['user'] ?? '',
                // NE PAS retourner le mot de passe
            ],
            'couleur' => $tenant['couleur'] ?? '#3b82f6',
            'db_status' => $dbStatus,
            'db_size' => $dbSize,
            'user_count' => $userCount,
        ];
    }

    // Écrire le cache
    @file_put_contents($cacheFile, json_encode($result, JSON_UNESCAPED_UNICODE));

    json_ok($result);
}

// ── Ajouter / modifier un tenant ──────────────────────────────────────────────
if ($action === 'superadmin_tenant_save' && $method === 'POST') {
    require_superadmin();
    $body = get_body();

    $key = trim($body['key'] ?? '');
    if (!$key) json_error('Clé du tenant requise.');
    if (!preg_match('/^[a-z0-9_.-]+$/', $key)) json_error('Clé invalide (a-z, 0-9, _, ., - uniquement).');

    $tenant = [
        'nom' => trim($body['nom'] ?? $key),
        'actif' => (bool)($body['actif'] ?? true),
        'domaines_web' => array_filter(array_map('trim', $body['domaines_web'] ?? [])),
        'domaines_email' => array_filter(array_map('trim', $body['domaines_email'] ?? [])),
        // Exceptions nominatives : adresses complètes autorisées en plus des domaines.
        'emails_exceptions' => TenantResolver::normalizeEmailExceptions($body['emails_exceptions'] ?? []),
        'base_de_donnees' => [],
        'couleur' => $body['couleur'] ?? '#3b82f6',
    ];

    // Config sauvegarde par tenant
    if (isset($body['sauvegarde']) && is_array($body['sauvegarde'])) {
        $sv = $body['sauvegarde'];
        $tenant['sauvegarde'] = [
            'actif'              => (bool)($sv['actif'] ?? false),
            'intervalle_minutes' => max(1, (int)($sv['intervalle_minutes'] ?? 360)),
            'garder'             => max(1, (int)($sv['garder'] ?? 30)),
            'compresser'         => (bool)($sv['compresser'] ?? true),
            'dossier'            => trim($sv['dossier'] ?? 'data/backups'),
        ];
    }

    // Config DB
    $dbDriver = $body['db_driver'] ?? 'sqlite';
    $tenant['base_de_donnees']['driver'] = $dbDriver;

    if ($dbDriver === 'sqlite') {
        $dbPath = trim($body['db_path'] ?? '');
        if (!$dbPath) $dbPath = 'data/tenant_' . $key . '.db';
        $tenant['base_de_donnees']['path'] = $dbPath;
    } else {
        $tenant['base_de_donnees']['host'] = trim($body['db_host'] ?? '127.0.0.1');
        $tenant['base_de_donnees']['port'] = (int)($body['db_port'] ?? ($dbDriver === 'pgsql' ? 5432 : 3306));
        $dbName = trim($body['db_dbname'] ?? '');
        if ($dbName === '') {
            json_error('Nom de la base de données requis pour ' . strtoupper($dbDriver) . '.');
        }
        $tenant['base_de_donnees']['dbname'] = $dbName;
        $tenant['base_de_donnees']['user'] = trim($body['db_user'] ?? '');
        // Mot de passe : garder l'ancien si non fourni
        $newPass = $body['db_password'] ?? null;
        if ($newPass !== null && $newPass !== '') {
            $tenant['base_de_donnees']['password'] = $newPass;
        } else {
            // Récupérer l'ancien
            $existing = TenantResolver::getAllTenants()[$key] ?? null;
            if ($existing) {
                $tenant['base_de_donnees']['password'] = $existing['base_de_donnees']['password'] ?? '';
            }
        }
        if ($dbDriver === 'pgsql') {
            $tenant['base_de_donnees']['sslmode'] = $body['db_sslmode'] ?? 'prefer';
        }
    }

    $ok = TenantResolver::upsertTenant($key, $tenant);
    if (!$ok) json_error('Erreur lors de la sauvegarde du tenant.');

    // ── Force drop optionnel AVANT le provisionnement ───────────────────
    // Si l'utilisateur a coché "Supprimer la base existante et la recréer",
    // on supprime physiquement la base (et les comptes locaux associés)
    // avant que provisionDatabase() ne s'exécute. C'est important quand
    // l'utilisateur veut vraiment repartir d'une base totalement vide,
    // typiquement après un DROP DATABASE manuel qui a échoué silencieusement.
    $forceDrop = (bool)($body['force_drop'] ?? false);
    if ($forceDrop && $dbDriver !== 'sqlite') {
        // PG / MariaDB : DROP DATABASE forcé
        try {
            $dbCfg = $tenant['base_de_donnees'];
            $host = $dbCfg['host'];
            $port = $dbCfg['port'];
            $dbn  = $dbCfg['dbname'];
            $usr  = $dbCfg['user'];
            $pwd  = $dbCfg['password'] ?? '';
            $safeName = preg_replace('/[^a-zA-Z0-9_]/', '_', $dbn);

            if ($dbDriver === 'pgsql') {
                $adminDsn = sprintf('pgsql:host=%s;port=%d;dbname=postgres;sslmode=%s;connect_timeout=5',
                    $host, $port, $dbCfg['sslmode'] ?? 'prefer');
                $adminPdo = new PDO($adminDsn, $usr, $pwd, [PDO::ATTR_TIMEOUT => 5]);
                $adminPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                // Tuer les connexions actives avant le DROP
                $kill = $adminPdo->prepare(
                    "SELECT pg_terminate_backend(pid) FROM pg_stat_activity
                     WHERE datname = ? AND pid <> pg_backend_pid()"
                );
                $kill->execute([$dbn]);
                $adminPdo->exec("DROP DATABASE IF EXISTS \"{$safeName}\"");
            } else {
                $adminDsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port);
                $adminPdo = new PDO($adminDsn, $usr, $pwd);
                $adminPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $adminPdo->exec("DROP DATABASE IF EXISTS `{$safeName}`");
            }
        } catch (\Throwable $e) {
            json_ok(['key' => $key,
                'message' => "Tenant sauvegardé mais suppression de la base échouée : " . $e->getMessage()
                    . " — Vérifiez les connexions actives puis utilisez le bouton « Purger »."]);
        }
    } elseif ($forceDrop && $dbDriver === 'sqlite') {
        // SQLite : supprimer le fichier (et ses fichiers WAL/SHM/journal associés)
        try {
            $dbPath = $tenant['base_de_donnees']['path'] ?? '';
            if (!str_starts_with($dbPath, '/')) $dbPath = __DIR__ . '/../../' . $dbPath;
            foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm', $dbPath . '-journal'] as $f) {
                if (is_file($f)) @unlink($f);
            }
        } catch (\Throwable $_) {}
    }
    // Si force_drop, nettoyer aussi les comptes locaux enregistrés sur ce tenant
    // (sinon des logins resteraient enregistrés sans utilisateur correspondant en base).
    if ($forceDrop) {
        try {
            $localLogins = TenantResolver::getLocalAccountsForTenant($key);
            foreach ($localLogins as $login) {
                TenantResolver::unregisterLocalAccount($login);
            }
        } catch (\Throwable $_) {}

        // ⚠️ Nettoyer les fichiers sentinelle de schéma (data/.schema/*.flag).
        // Ces flags court-circuitent initTables() s'ils sont présents et à jour.
        // Sans ce nettoyage, après un DROP DATABASE le code croirait que le
        // schéma est déjà en place et NE recréerait PAS les tables — provoquant
        // l'erreur "relation Utilisateurs does not exist" au premier accès.
        // Le hash sentinelle dépend de spl_object_id() qui change à chaque
        // instance PDO, donc impossible de cibler précisément : on vide tout.
        try {
            $sentinelDir = __DIR__ . '/../../data/.schema';
            if (is_dir($sentinelDir)) {
                foreach (glob($sentinelDir . '/*.flag') ?: [] as $flag) {
                    @unlink($flag);
                }
            }
        } catch (\Throwable $_) {}
    }

    // Provisionner la DB automatiquement (SQLite: fichier, PG: CREATE DATABASE, MariaDB: idem)
    $provision = TenantResolver::provisionDatabase($key);
    $provisionMsg = $provision['message'] ?? '';
    if (!$provision['success']) {
        json_ok(['key' => $key, 'message' => "Tenant sauvegardé, mais erreur provisionnement : {$provisionMsg}"]);
    }

    // Créer le compte admin local si demandé
    $adminLogin = trim($body['admin_login'] ?? '');
    $adminPassword = $body['admin_password'] ?? '';
    if ($adminLogin && $adminPassword) {
        // Vérifier doublon cross-tenant dans le registre centralisé
        if (TenantResolver::isLoginTaken($adminLogin)) {
            $existingTk = TenantResolver::resolveLocalAccount($adminLogin);
            if ($existingTk !== $key) {
                $existingNom = (TenantResolver::getAllTenants()[$existingTk]['nom'] ?? $existingTk);
                json_ok(['key' => $key, 'message' => "Tenant créé, mais le login \"{$adminLogin}\" existe déjà dans \"{$existingNom}\". Compte admin non créé."]);
            }
        }

        try {
            $tenantDb = new Database($tenant['base_de_donnees']);

            // ⚠️ Forcer la création du schéma complet AVANT d'ajouter l'admin.
            // Si la base vient d'être créée (force_drop OU base toute neuve)
            // et qu'un sentinelle traîne, le constructeur peut sauter initTables()
            // → la table Utilisateurs n'existe pas → INSERT échoue.
            // ensureFullSchema() relance toutes les CREATE TABLE IF NOT EXISTS
            // de manière idempotente : c'est ceinture + bretelles.
            $tenantDb->ensureFullSchema();

            // ⚠️ FIX PERFS : lookup direct par login au lieu de tirer toute
            // la table puis foreach. O(1) avec l'index idx_utilisateurs_login.
            $loginExists = $tenantDb->findUtilisateurByLogin($adminLogin) !== null;
            if (!$loginExists) {
                $tenantDb->addUtilisateur([
                    'nom'       => trim($body['admin_nom'] ?? 'Admin'),
                    'prenom'    => trim($body['admin_prenom'] ?? 'Super'),
                    'login'     => $adminLogin,
                    'email'     => trim($body['admin_email'] ?? $adminLogin),
                    'tel'       => '',
                    'motDePasse'=> $adminPassword,
                    'role'      => 'Gestionnaire',
                    'provider'  => 'local',
                ]);
                // Enregistrer dans le registre centralisé
                TenantResolver::registerLocalAccount($adminLogin, $key);
            }
        } catch (\Throwable $e) {
            json_ok(['key' => $key, 'message' => 'Tenant sauvegardé, mais erreur compte admin : ' . $e->getMessage()]);
        }
    }

    json_ok(['key' => $key, 'message' => 'Tenant sauvegardé.']);
}

// ── Lister les comptes d'un tenant ────────────────────────────────────────────
if ($action === 'superadmin_tenant_users' && $method === 'GET') {
    require_superadmin();
    $key = trim($_GET['key'] ?? '');
    if (!$key) json_error('Clé du tenant requise.');

    $tenants = TenantResolver::getAllTenants();
    if (!isset($tenants[$key])) json_error('Tenant inconnu.');

    try {
        $tenantDb = new Database($tenants[$key]['base_de_donnees']);
        $users = $tenantDb->getAllUtilisateurs();
        json_ok($users);
    } catch (\Throwable $e) {
        json_error('Impossible de lire les utilisateurs : ' . $e->getMessage());
    }
}

// ── Créer / modifier un compte dans un tenant ─────────────────────────────────
if ($action === 'superadmin_tenant_user_save' && $method === 'POST') {
    require_superadmin();
    $body = get_body();
    $key = trim($body['tenant_key'] ?? '');
    if (!$key) json_error('Clé du tenant requise.');

    $tenants = TenantResolver::getAllTenants();
    if (!isset($tenants[$key])) json_error('Tenant inconnu.');

    $login = trim($body['login'] ?? '');
    if (!$login) json_error('Login requis.');
    $provider = $body['provider'] ?? 'local';

    // ── Vérification doublon cross-tenant (comptes locaux uniquement) ─────────
    if ($provider === 'local') {
        $userId = (int)($body['id'] ?? 0);
        if ($userId > 0) {
            // Modification : vérifier si le nouveau login n'est pas pris par un autre tenant
            // Récupérer l'ancien login pour savoir s'il a changé
            $oldLogin = $body['old_login'] ?? $login;
            if (strtolower($oldLogin) !== strtolower($login)) {
                if (TenantResolver::isLoginTakenByOther($login, $key)) {
                    json_error("Le login \"{$login}\" est déjà utilisé dans un autre tenant.");
                }
            }
        } else {
            // Création : vérifier que le login n'existe nulle part
            if (TenantResolver::isLoginTaken($login)) {
                $existingTenant = TenantResolver::resolveLocalAccount($login);
                $existingNom = $tenants[$existingTenant]['nom'] ?? $existingTenant;
                json_error("Le login \"{$login}\" est déjà utilisé dans le tenant \"{$existingNom}\".");
            }
        }
    }

    try {
        $tenantDb = new Database($tenants[$key]['base_de_donnees']);
        $userId = (int)($body['id'] ?? 0);

        $userData = [
            'nom'       => trim($body['nom'] ?? ''),
            'prenom'    => trim($body['prenom'] ?? ''),
            'login'     => $login,
            'email'     => trim($body['email'] ?? ''),
            'tel'       => trim($body['tel'] ?? ''),
            'service'   => trim($body['service'] ?? ''),
            'poste'     => trim($body['poste'] ?? ''),
            'role'      => $body['role'] ?? 'Gestionnaire',
            'provider'  => $provider,
            'actif'     => (int)($body['actif'] ?? 1),
        ];

        if (!empty($body['motDePasse'])) {
            $userData['motDePasse'] = $body['motDePasse'];
        }

        if ($userId > 0) {
            // Si le login a changé, mettre à jour le registre
            $oldLogin = $body['old_login'] ?? $login;
            $tenantDb->updateUtilisateur($userId, $userData);
            if ($provider === 'local') {
                if (strtolower($oldLogin) !== strtolower($login)) {
                    TenantResolver::renameLocalAccount($oldLogin, $login, $key);
                }
            }
            json_ok(['message' => 'Utilisateur mis à jour.']);
        } else {
            if (empty($body['motDePasse'])) json_error('Mot de passe requis pour un nouveau compte.');
            $newId = $tenantDb->addUtilisateur($userData);
            // Enregistrer dans le registre centralisé
            if ($provider === 'local') {
                TenantResolver::registerLocalAccount($login, $key);
            }
            json_ok(['id' => $newId, 'message' => 'Utilisateur créé.']);
        }
    } catch (\Throwable $e) {
        json_error('Erreur : ' . $e->getMessage());
    }
}

// ── Supprimer un compte dans un tenant ────────────────────────────────────────
if ($action === 'superadmin_tenant_user_delete' && $method === 'POST') {
    require_superadmin();
    $body = get_body();
    $key = trim($body['tenant_key'] ?? '');
    $userId = (int)($body['user_id'] ?? 0);
    if (!$key || !$userId) json_error('Tenant et ID utilisateur requis.');

    $tenants = TenantResolver::getAllTenants();
    if (!isset($tenants[$key])) json_error('Tenant inconnu.');

    try {
        $tenantDb = new Database($tenants[$key]['base_de_donnees']);
        // Récupérer le login AVANT suppression pour le retirer du registre
        // ⚠️ FIX PERFS : lookup direct au lieu de scanner toute la table.
        $userToRemove = $tenantDb->findLocalUtilisateurById($userId);
        $loginToRemove = $userToRemove['Login'] ?? null;
        $tenantDb->deleteUtilisateur($userId);
        if ($loginToRemove) {
            TenantResolver::unregisterLocalAccount($loginToRemove);
        }
        json_ok(['message' => 'Utilisateur supprimé.']);
    } catch (\Throwable $e) {
        json_error('Erreur : ' . $e->getMessage());
    }
}

// ── Supprimer un tenant ───────────────────────────────────────────────────────
if ($action === 'superadmin_tenant_delete' && $method === 'POST') {
    require_superadmin();
    $body = get_body();
    $key = trim($body['key'] ?? '');
    if (!$key) json_error('Clé du tenant requise.');
    if ($key === 'default') json_error('Impossible de supprimer le tenant par défaut.');

    $dbSuppr = ['driver' => null, 'details' => null, 'erreurs' => []];

    // Récupérer la config BDD du tenant avant de le retirer du registre,
    // et nettoyer les comptes locaux liés dans le registre des super-admins.
    $tenantCfgDb = null;
    try {
        $tenants = TenantResolver::getAllTenants();
        if (isset($tenants[$key]) && isset($tenants[$key]['base_de_donnees'])) {
            $tenantCfgDb = $tenants[$key]['base_de_donnees'];
            // Nettoyer le registre des comptes locaux liés à ce tenant
            // ÉTAPE A : essayer via la BDD du tenant (méthode normale)
            try {
                $tenantDb = new Database($tenantCfgDb);
                $users = $tenantDb->getAllUtilisateurs();
                foreach ($users as $u) {
                    if (($u['Provider'] ?? 'local') === 'local' && !empty($u['Login'])) {
                        TenantResolver::unregisterLocalAccount($u['Login']);
                    }
                }
                unset($tenantDb);
            } catch (\Throwable $e) {
                // La DB du tenant peut être inaccessible — on continue quand même
            }
            // ÉTAPE B : fallback — purger le registre par tenant_key, peu importe l'état de la BDD
            // (couvre le cas où la BDD a été purgée / supprimée / corrompue avant)
            try {
                $regLogins = TenantResolver::getLocalAccountsForTenant($key);
                foreach ($regLogins as $login) {
                    TenantResolver::unregisterLocalAccount($login);
                }
            } catch (\Throwable $_) {}
        }
    } catch (\Throwable $_) {}

    // Nettoyer forced_tenant si c'est le tenant actif
    if (($_SESSION['forced_tenant'] ?? '') === $key) {
        unset($_SESSION['forced_tenant'], $_SESSION['user']);
    }

    // ── Supprimer physiquement la BDD du tenant ───────────────────────────────
    // SQLite : supprimer le fichier .db (+ fichiers -wal / -shm éventuels)
    // PG/MariaDB : DROP de toutes les tables du schéma (on ne DROP pas la base
    // elle-même car ça nécessiterait des privilèges admin et la config serait
    // à refaire en cas de recréation ultérieure du tenant).
    if (is_array($tenantCfgDb)) {
        $driverSup = $tenantCfgDb['driver'] ?? 'sqlite';
        $dbSuppr['driver'] = $driverSup;
        try {
            if ($driverSup === 'sqlite') {
                $dbPath = _sa_resolve_path($tenantCfgDb['path'] ?? '');
                if ($dbPath && is_file($dbPath)) {
                    foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm', $dbPath . '-journal'] as $f) {
                        if (is_file($f)) @unlink($f);
                    }
                    $dbSuppr['details'] = 'Fichier SQLite supprimé : ' . basename($dbPath);
                } else {
                    $dbSuppr['details'] = 'Fichier SQLite introuvable (déjà absent) — rien à supprimer.';
                }
            } else {
                // PG / MariaDB : purge du schéma via la même logique que l'import
                try {
                    $dbDel = new \Database($tenantCfgDb);
                    _sa_purge_database($dbDel->getPdo(), $driverSup);
                    $dbDel->invalidateSchemaSentinel(); // éviter un flag orphelin « à jour »
                    unset($dbDel);
                    $dbSuppr['details'] = 'Toutes les tables du schéma ont été supprimées (DROP CASCADE).';
                } catch (\Throwable $e) {
                    $dbSuppr['erreurs'][] = 'Purge ' . strtoupper($driverSup) . ' : ' . $e->getMessage();
                }
            }
        } catch (\Throwable $e) {
            $dbSuppr['erreurs'][] = $e->getMessage();
        }
    }

    $ok = TenantResolver::deleteTenant($key);
    if (!$ok) json_error('Erreur lors de la suppression du tenant (sa BDD a peut-être déjà été purgée).');

    json_ok([
        'message'   => 'Tenant supprimé (comptes locaux retirés du registre, base de données détruite).',
        'bdd'       => $dbSuppr,
    ]);
}

// ── Purger la base d'un tenant (vide la BDD mais garde le tenant) ─────────────
if ($action === 'superadmin_tenant_purge_db' && $method === 'POST') {
    require_superadmin();
    $body = get_body();
    $key = trim($body['key'] ?? '');
    if ($key === '') json_error('Clé tenant manquante.');

    $tenants = TenantResolver::getAllTenants();
    if (!isset($tenants[$key]) || !isset($tenants[$key]['base_de_donnees'])) {
        json_error('Tenant introuvable : ' . $key);
    }
    $tenantCfgDb = $tenants[$key]['base_de_donnees'];
    $driverSup = $tenantCfgDb['driver'] ?? 'sqlite';

    $result = ['driver' => $driverSup, 'message' => '', 'erreurs' => [], 'comptesLocauxNettoyes' => 0];

    // ─── ÉTAPE 1 : Nettoyer le registre des comptes locaux du tenant ───
    // Important AVANT la purge : sinon les logins resteraient enregistrés
    // dans `sa_comptes_locaux` mais seraient absents de la base purgée,
    // ce qui empêcherait de recréer un compte avec le même nom.
    try {
        $localLogins = TenantResolver::getLocalAccountsForTenant($key);
        foreach ($localLogins as $login) {
            TenantResolver::unregisterLocalAccount($login);
            $result['comptesLocauxNettoyes']++;
        }
    } catch (\Throwable $e) {
        $result['erreurs'][] = 'Nettoyage registre comptes locaux : ' . $e->getMessage();
    }

    // ─── ÉTAPE 2 : Purger physiquement la base ───
    try {
        if ($driverSup === 'sqlite') {
            // SQLite : supprimer le fichier puis le recréer vide
            $dbPath = _sa_resolve_path($tenantCfgDb['path'] ?? '');
            if ($dbPath && is_file($dbPath)) {
                foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm', $dbPath . '-journal'] as $f) {
                    if (is_file($f)) @unlink($f);
                }
                $result['message'] = 'Base SQLite supprimée. Une nouvelle base vide sera recréée à la prochaine connexion.';
            } else {
                $result['message'] = 'Fichier SQLite déjà absent.';
            }
        } else {
            // PG / MariaDB : DROP de tout puis recréation du schéma vide
            $dbDel = new \Database($tenantCfgDb);
            _sa_purge_database($dbDel->getPdo(), $driverSup);
            // ⚠️ La sentinelle de schéma de cette base dit encore « à jour » alors
            //    qu'on vient de DROP toutes les tables. Sans invalidation, le
            //    new Database() ci-dessous court-circuiterait initTables() et les
            //    tables ne seraient JAMAIS recréées → « relation utilisateurs
            //    does not exist » au prochain accès. On invalide donc d'abord.
            $dbDel->invalidateSchemaSentinel();
            unset($dbDel);
            // Recréer le schéma vide + seeds (admin) en se reconnectant via Database.
            new \Database($tenantCfgDb);
            $result['message'] = 'Base purgée et schéma recréé vide.';
        }
        // Compléter le message avec l'info des comptes nettoyés
        if ($result['comptesLocauxNettoyes'] > 0) {
            $result['message'] .= ' ' . $result['comptesLocauxNettoyes']
                . ' compte(s) local/aux retiré(s) du registre des super-admins.';
        }
    } catch (\Throwable $e) {
        $result['erreurs'][] = $e->getMessage();
        json_error('Erreur lors de la purge : ' . $e->getMessage());
    }

    // ─── ÉTAPE 3 : Si la session courante était sur ce tenant, la réinitialiser ───
    // (sinon le user reste connecté avec un compte qui n'existe plus)
    if (($_SESSION['forced_tenant'] ?? '') === $key) {
        unset($_SESSION['user']);
    }

    json_ok($result);
}

// ── Modules activés/désactivés du tenant ─────────────────────────────────────
// GET  : retourne la liste des modules désactivés pour le tenant actif
// POST : enregistre la liste {key: 'tenant_key', disabled: ['energie','chorus',...]}
if ($action === 'superadmin_tenant_modules') {
    require_superadmin();
    if ($method === 'GET') {
        $key = trim($_GET['key'] ?? '');
        if ($key === '') json_error('Clé tenant manquante.');
        $tenants = TenantResolver::getAllTenants();
        if (!isset($tenants[$key]) || !isset($tenants[$key]['base_de_donnees'])) {
            json_error('Tenant introuvable : ' . $key);
        }
        try {
            $tdb = new \Database($tenants[$key]['base_de_donnees']);
            $row = $tdb->fetchOne("SELECT Valeur FROM Configuration WHERE Cle = :k", ['k' => 'modules_disabled']);
            $val = $row['Valeur'] ?? '';
            $list = [];
            if ($val) {
                $decoded = json_decode($val, true);
                if (is_array($decoded)) $list = $decoded;
            }
            json_ok(['disabled' => $list]);
        } catch (\Throwable $e) {
            json_error('Erreur lecture : ' . $e->getMessage());
        }
    }
    if ($method === 'POST') {
        $body = get_body();
        $key = trim($body['key'] ?? '');
        $disabled = $body['disabled'] ?? [];
        if (!is_array($disabled)) $disabled = [];
        // Filtrer aux modules connus uniquement
        $known = ['dashboard','biens','equipements','stock','plans','interventions','contrats',
                  'gestion_materiel','historique','demandes','energie','carbone','mobilite_carbone',
                  'archives','statsAvancees','legifrance','chorus','configuration'];
        $disabled = array_values(array_filter($disabled, fn($m) => in_array($m, $known, true)));

        $tenants = TenantResolver::getAllTenants();
        if (!isset($tenants[$key]) || !isset($tenants[$key]['base_de_donnees'])) {
            json_error('Tenant introuvable : ' . $key);
        }
        try {
            $tdb = new \Database($tenants[$key]['base_de_donnees']);
            $val = json_encode($disabled, JSON_UNESCAPED_UNICODE);
            // Upsert
            $exists = $tdb->fetchOne("SELECT Id FROM Configuration WHERE Cle = :k", ['k' => 'modules_disabled']);
            if ($exists) {
                $tdb->execute("UPDATE Configuration SET Valeur = :v WHERE Cle = :k", ['v' => $val, 'k' => 'modules_disabled']);
            } else {
                $tdb->execute("INSERT INTO Configuration (Cle, Valeur) VALUES (:k, :v)", ['k' => 'modules_disabled', 'v' => $val]);
            }
            json_ok(['disabled' => $disabled, 'message' => count($disabled) . ' module(s) désactivé(s).']);
        } catch (\Throwable $e) {
            json_error('Erreur écriture : ' . $e->getMessage());
        }
    }
}

// ── Switcher de tenant (super admin) ──────────────────────────────────────────
if ($action === 'superadmin_switch_tenant' && $method === 'POST') {
    require_superadmin();
    $body = get_body();
    $key = trim($body['key'] ?? '');

    $tenants = TenantResolver::getAllTenants();
    if (!isset($tenants[$key])) json_error('Tenant inconnu.');

    // Vérifier que le tenant est actif
    if (isset($tenants[$key]['actif']) && !$tenants[$key]['actif']) {
        json_error('Ce tenant est inactif. Activez-le d\'abord dans la configuration.', 403);
    }

    $_SESSION['forced_tenant'] = $key;

    // Créer ou trouver un compte Demandeur pour le super admin dans ce tenant
    $saLogin = $_SESSION['superadmin']['login'] ?? 'superadmin';
    $saProfile = TenantResolver::getSuperAdminProfile();
    $saEmail = $saProfile['email'] ?: $saLogin;
    if (!str_contains($saEmail, '@')) {
        $saEmail = $saLogin . '@superadmin.local';
    }
    $saNom = $saProfile['nom'] ?: 'Super Admin';
    $saPrenom = $saProfile['prenom'] ?: '';

    try {
        $tenantDb = new Database($tenants[$key]['base_de_donnees']);
        // ⚠️ FIX PERFS : lookup direct au lieu de full-scan + foreach.
        $saUser = $tenantDb->findUtilisateurByLoginOrEmail($saLogin, $saEmail);

        if (!$saUser) {
            // ⚠️ FIX BUG : créer un compte Admin (accès complet) cohérent avec
            // le statut super-admin. Auparavant le rôle était 'Demandeur' alors
            // que le commentaire disait "accès complet" — un super-admin
            // basculant sur un tenant se retrouvait avec des droits de simple
            // demandeur, ce qui est à la fois incohérent et inutilisable.
            $tenantDb->addUtilisateur([
                'nom'       => $saNom,
                'prenom'    => $saPrenom,
                'login'     => $saLogin,
                'email'     => $saEmail,
                'tel'       => '',
                'service'   => 'Administration',
                'poste'     => 'Super Admin',
                'motDePasse'=> bin2hex(random_bytes(16)),
                'role'      => 'Admin',
                'provider'  => 'local',
            ]);
            $saUser = $tenantDb->findUtilisateurByLogin($saLogin);
        }

        if ($saUser) {
            $_SESSION['user'] = $saUser;
            $_SESSION['tenant_key'] = $key;
        }
    } catch (\Throwable $e) {
        // Si la DB du tenant n'est pas accessible, on bascule quand même
        // mais sans compte utilisateur — le super admin verra les erreurs
    }

    if (class_exists('SecurityLog')) {
        SecurityLog::superAdminAction('switch_tenant', ['target_tenant' => $key]);
    }
    json_ok(['message' => 'Basculé sur le tenant : ' . ($tenants[$key]['nom'] ?? $key), 'key' => $key, '_tenant' => $key]);
}

// ── Tester la connexion DB d'un tenant ────────────────────────────────────────
// Si `force_drop` est passé à true ET que la base existe, on la supprime AVANT
// de répondre. C'est utile quand le superadmin veut repartir d'une base
// totalement vide alors qu'une précédente version existe encore (notamment
// si un DROP DATABASE manuel a échoué silencieusement faute d'avoir tué
// les connexions actives, ce qui arrive souvent).
if ($action === 'superadmin_test_db' && $method === 'POST') {
    require_superadmin();
    $body = get_body();

    $driver = $body['driver'] ?? 'sqlite';
    $forceDrop = (bool)($body['force_drop'] ?? false);
    try {
        if ($driver === 'sqlite') {
            $path = $body['path'] ?? '';
            if (!str_starts_with($path, '/')) $path = __DIR__ . '/../' . $path;
            $dir = dirname($path);
            if (!is_dir($dir)) mkdir($dir, 0755, true);

            // Force drop SQLite : supprimer le fichier .db et ses fichiers WAL/SHM/journal
            // associés AVANT de tester. PDO recréera un fichier vide automatiquement
            // au premier INSERT par la suite, donc on touche() pour produire un fichier
            // vide propre. Le tableau couvre les 4 types de fichiers que SQLite peut
            // laisser (WAL mode, rollback journal, shared memory).
            $dropMsg = '';
            if ($forceDrop && is_file($path)) {
                foreach ([$path, $path . '-wal', $path . '-shm', $path . '-journal'] as $f) {
                    if (is_file($f)) @unlink($f);
                }
                $dropMsg = ' (fichier précédent supprimé)';
            }

            $pdo = new PDO('sqlite:' . $path);
            $pdo->exec("SELECT 1");
            json_ok(['status' => 'ok', 'message' => 'Connexion SQLite réussie.' . $dropMsg]);
        } else {
            $host = $body['host'] ?? '127.0.0.1';
            $port = $body['port'] ?? ($driver === 'pgsql' ? 5432 : 3306);
            $dbname = trim($body['dbname'] ?? '');
            $user = $body['user'] ?? '';
            $password = $body['password'] ?? '';

            // 1) Tester d'abord la connexion au SERVEUR (sans base spécifique)
            //    La base peut ne pas exister encore — elle sera créée à l'enregistrement.
            if ($driver === 'pgsql') {
                // Sur PostgreSQL il faut toujours une base pour se connecter ; on utilise "postgres".
                // connect_timeout borne la connexion (PG ignore PDO::ATTR_TIMEOUT au connect).
                $adminDsn = sprintf('pgsql:host=%s;port=%d;dbname=postgres;sslmode=%s;connect_timeout=5',
                    $host, $port, $body['sslmode'] ?? 'prefer');
            } else {
                // MySQL/MariaDB : on peut se connecter sans base
                $adminDsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port);
            }
            $adminPdo = new PDO($adminDsn, $user, $password, [PDO::ATTR_TIMEOUT => 5]);
            $adminPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // 2) Vérifier si la base cible existe déjà
            $dbExists = false;
            if ($dbname) {
                if ($driver === 'pgsql') {
                    $stmt = $adminPdo->prepare("SELECT 1 FROM pg_database WHERE datname = ?");
                    $stmt->execute([$dbname]);
                    $dbExists = (bool)$stmt->fetchColumn();
                } else {
                    $stmt = $adminPdo->prepare("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?");
                    $stmt->execute([$dbname]);
                    $dbExists = (bool)$stmt->fetchColumn();
                }
            }

            // 3) Si force_drop demandé ET base existante → DROP FORCÉ
            //    PostgreSQL refuse DROP DATABASE si des connexions sont actives :
            //    on les tue d'abord. Sur PG 13+, on pourrait utiliser WITH (FORCE),
            //    mais la double approche pg_terminate_backend() + DROP est portable
            //    et marche depuis PG 9.x. On échappe le nom via guillemets.
            $dropMsg = '';
            if ($forceDrop && $dbExists && $dbname) {
                try {
                    $safeName = preg_replace('/[^a-zA-Z0-9_]/', '_', $dbname);

                    if ($driver === 'pgsql') {
                        // Tuer les connexions actives sur cette base (sauf la nôtre)
                        $kill = $adminPdo->prepare(
                            "SELECT pg_terminate_backend(pid) FROM pg_stat_activity
                             WHERE datname = ? AND pid <> pg_backend_pid()"
                        );
                        $kill->execute([$dbname]);
                        // Puis drop
                        $adminPdo->exec("DROP DATABASE IF EXISTS \"{$safeName}\"");
                    } else {
                        // MySQL/MariaDB : pas de connexion bloquante, DROP direct
                        $adminPdo->exec("DROP DATABASE IF EXISTS `{$safeName}`");
                    }
                    $dbExists = false;
                    $dropMsg = " — base précédente supprimée";

                    // Nettoyer les fichiers sentinelle de schéma : sans ce nettoyage,
                    // la prochaine instanciation Database croirait que les tables
                    // existent déjà (le flag est sur disque) et NE recréerait PAS
                    // le schéma → erreur "relation does not exist" au premier accès.
                    try {
                        $sentinelDir = __DIR__ . '/../../data/.schema';
                        if (is_dir($sentinelDir)) {
                            foreach (glob($sentinelDir . '/*.flag') ?: [] as $flag) {
                                @unlink($flag);
                            }
                        }
                    } catch (\Throwable $_) {}
                } catch (\Throwable $e) {
                    // On ne fait pas échouer le test : on signale juste l'échec du drop.
                    // L'utilisateur saura qu'il doit vérifier les connexions manuellement.
                    json_ok(['status' => 'error',
                        'message' => "❌ Impossible de supprimer la base existante : " . $e->getMessage()
                            . " — Vérifiez qu'aucune autre application n'est connectée à « {$dbname} »."]);
                }
            }

            if (!$dbname) {
                json_ok(['status' => 'ok', 'message' => '✅ Serveur accessible. Renseignez un nom de base.']);
            } elseif ($dbExists) {
                json_ok(['status' => 'ok', 'message' => "✅ Serveur OK. La base « {$dbname} » existe déjà — elle sera réutilisée."]);
            } else {
                $createMsg = $forceDrop && $dropMsg
                    ? "✅ Serveur OK{$dropMsg}. La base « {$dbname} » sera recréée à l'enregistrement."
                    : "✅ Serveur OK. La base « {$dbname} » sera créée à l'enregistrement.";
                json_ok(['status' => 'ok', 'message' => $createMsg]);
            }
        }
    } catch (\Exception $e) {
        json_ok(['status' => 'error', 'message' => $e->getMessage()]);
    }
}

// ── Registre centralisé des comptes locaux ────────────────────────────────────
if ($action === 'superadmin_local_accounts' && $method === 'GET') {
    require_superadmin();
    $accounts = TenantResolver::getAllLocalAccounts();
    $tenants = TenantResolver::getAllTenants();
    $result = [];
    foreach ($accounts as $login => $tenantKey) {
        if ($login === '_comment') continue;
        $result[] = [
            'login' => $login,
            'tenant_key' => $tenantKey,
            'tenant_nom' => $tenants[$tenantKey]['nom'] ?? $tenantKey,
            'tenant_couleur' => $tenants[$tenantKey]['couleur'] ?? '#6b7280',
        ];
    }
    json_ok($result);
}

// ── Synchroniser le registre avec les DB de tous les tenants ──────────────────
if ($action === 'superadmin_sync_accounts' && $method === 'POST') {
    require_superadmin();
    $body = get_body();
    $singleKey = trim($body['key'] ?? '');           // si fourni : cibler 1 seul tenant
    $tenants = TenantResolver::getAllTenants();
    $totalChanges = 0;
    $errors = [];
    foreach ($tenants as $key => $tenant) {
        if ($singleKey !== '' && $key !== $singleKey) continue;
        if (!($tenant['actif'] ?? true)) continue;
        try {
            $tenantDb = new Database($tenant['base_de_donnees']);
            $users = $tenantDb->getAllUtilisateurs();
            $changes = TenantResolver::syncLocalAccountsForTenant($key, $users);
            $totalChanges += $changes;
        } catch (\Throwable $e) {
            // Si la BDD est inaccessible, on retire les orphelins du registre quand même
            // (cas d'une BDD purgée mais pas le tenant)
            if ($singleKey === $key || $singleKey === '') {
                try {
                    $regLogins = TenantResolver::getLocalAccountsForTenant($key);
                    foreach ($regLogins as $login) {
                        TenantResolver::unregisterLocalAccount($login);
                        $totalChanges++;
                    }
                } catch (\Throwable $_) {}
            }
            $errors[] = $key . ': ' . $e->getMessage();
        }
    }
    json_ok([
        'changes' => $totalChanges,
        'errors' => $errors,
        'message' => $totalChanges > 0
            ? "{$totalChanges} compte(s) synchronisé(s)."
            : 'Registre déjà à jour.',
    ]);
}

// ── Monitoring : stats de tous les tenants ────────────────────────────────────
if ($action === 'superadmin_monitoring' && $method === 'GET') {
    require_superadmin();
    $tenants = TenantResolver::getAllTenants();
    $result = [];

    foreach ($tenants as $key => $tenant) {
        $entry = [
            'key' => $key,
            'nom' => $tenant['nom'] ?? $key,
            'couleur' => $tenant['couleur'] ?? '#6b7280',
            'actif' => $tenant['actif'] ?? true,
            'driver' => $tenant['base_de_donnees']['driver'] ?? 'sqlite',
            'db_status' => 'unknown',
            'db_size' => null,
            'stats' => null,
            'error' => null,
        ];

        try {
            $dbCfg = $tenant['base_de_donnees'] ?? null;
            if (!$dbCfg) throw new \Exception('Pas de config DB');

            // Taille fichier SQLite
            $driver = $dbCfg['driver'] ?? 'sqlite';
            if ($driver === 'sqlite') {
                $path = $dbCfg['path'] ?? '';
                if (!str_starts_with($path, '/')) $path = __DIR__ . '/../../' . $path;
                if (file_exists($path)) {
                    $entry['db_size'] = filesize($path);
                } else {
                    $entry['db_status'] = 'missing';
                    $result[$key] = $entry;
                    continue;
                }
            }

            $tenantDb = new Database($dbCfg);
            $entry['db_status'] = 'ok';

            // Collecter les stats via le getter PDO public
            $pdoObj = $tenantDb->getPdo();

            $count = function(string $table, string $where = '1=1') use ($pdoObj) {
                try {
                    return (int) $pdoObj->query("SELECT COUNT(*) FROM {$table} WHERE {$where}")->fetchColumn();
                } catch (\Throwable $e) { return 0; }
            };

            $entry['stats'] = [
                'utilisateurs_actifs' => $count('Utilisateurs', 'Actif=1'),
                'utilisateurs_total'  => $count('Utilisateurs'),
                'biens'               => $count('Biens', "DateSuppression IS NULL AND (Etat IS NULL OR Etat NOT IN ('Jete/Recycle','Don/Vendu'))"),
                'equipements'         => $count('Equipements', "DateSuppression IS NULL AND (Etat IS NULL OR Etat NOT IN ('Jete/Recycle','Don/Vendu'))"),
                'interventions'       => $count('Interventions'),
                'interventions_actives' => $count('Interventions', "Statut IN ('Planifiée','En cours')"),
                'contrats'            => $count('Contrats'),
                'contrats_actifs'     => $count('Contrats', "Statut='Actif'"),
                'stock_articles'      => $count('Stock'),
                'stock_alertes'       => $count('Stock', 'Quantite <= SeuilAlerte'),
                'demandes'            => $count('DemandesIntervention'),
                'demandes_nouvelles'  => $count('DemandesIntervention', "Statut IN ('Nouveau','Demandeur')"),
                'gestion_materiel'        => $count('GestionMateriel'),
                'documents'           => $count('Documents'),
            ];

            // Dernière activité (dernier utilisateur créé ou dernière intervention)
            try {
                $lastActivity = $pdoObj->query("SELECT MAX(d) FROM (SELECT MAX(DateCreation) as d FROM Utilisateurs UNION SELECT MAX(CreatedAt) as d FROM Interventions)")->fetchColumn();
                $entry['stats']['derniere_activite'] = $lastActivity;
            } catch (\Throwable $e) {
                $entry['stats']['derniere_activite'] = null;
            }

        } catch (\Throwable $e) {
            $entry['db_status'] = 'error';
            $entry['error'] = $e->getMessage();
        }

        $result[$key] = $entry;
    }

    // Stats globales
    $global = [
        'total_tenants' => count($tenants),
        'tenants_actifs' => count(array_filter($tenants, fn($t) => $t['actif'] ?? true)),
        'total_comptes_locaux' => count(TenantResolver::getAllLocalAccounts()),
        'superadmin_db' => TenantResolver::getSuperAdminDbInfo(),
    ];

    json_ok(['tenants' => $result, 'global' => $global]);
}

// ── Initialiser les tables d'un tenant ────────────────────────────────────────
if ($action === 'superadmin_provision' && $method === 'POST') {
    require_superadmin();
    $body = get_body();
    $key = trim($body['key'] ?? '');

    $tenants = TenantResolver::getAllTenants();
    if (!isset($tenants[$key])) json_error('Tenant inconnu.');

    // 1. Provisionner (créer le fichier SQLite ou CREATE DATABASE PG/MariaDB)
    $provision = TenantResolver::provisionDatabase($key);

    // 2. Tester la connexion et créer les tables
    $dbCfg = $tenants[$key]['base_de_donnees'];
    try {
        $tenantDb = new Database($dbCfg);
        $msg = $provision['message'] ?? 'OK';
        json_ok(['message' => "Base provisionnée. {$msg} — Les tables ont été créées."]);
    } catch (\Throwable $e) {
        json_ok([
            'message' => ($provision['message'] ?? '') . ' — Erreur connexion : ' . $e->getMessage(),
            'provision' => $provision,
        ]);
    }
}

// ── Modifier le mot de passe super admin ──────────────────────────────────────
if ($action === 'superadmin_change_password' && $method === 'POST') {
    require_superadmin();
    $body = get_body();
    $oldPwd = $body['oldPassword'] ?? '';
    $newPwd = $body['newPassword'] ?? '';

    if (!$oldPwd || !$newPwd) json_error('Ancien et nouveau mot de passe requis.');
    if (strlen($newPwd) < 6) json_error('Minimum 6 caractères.');

    $sa = TenantResolver::getSuperAdmin();
    if (!$sa || !password_verify($oldPwd, $sa['mot_de_passe'])) {
        json_error('Ancien mot de passe incorrect.');
    }

    $ok = TenantResolver::updateSuperAdmin(
        $sa['login'],
        password_hash($newPwd, PASSWORD_BCRYPT),
        $sa['email'] ?? ''
    );
    if (!$ok) json_error('Erreur lors de la mise à jour.');
    json_ok('Mot de passe super admin modifié.');
}

// ── Gérer les emails super admin autorisés (Microsoft OAuth) ──────────────────
if ($action === 'superadmin_emails' && $method === 'GET') {
    require_superadmin();
    json_ok(TenantResolver::getSuperAdminEmails());
}

if ($action === 'superadmin_emails_save' && $method === 'POST') {
    require_superadmin();
    $body = get_body();
    $emails = $body['emails'] ?? [];
    if (!is_array($emails)) json_error('Liste d\'emails requise.');
    TenantResolver::setSuperAdminEmails($emails);
    json_ok(['message' => 'Emails super admin mis à jour.', 'emails' => TenantResolver::getSuperAdminEmails()]);
}

// ── Profil Larka du super admin (nom/prénom/email pour le compte Demandeur) ────
if ($action === 'superadmin_profile' && $method === 'GET') {
    require_superadmin();
    json_ok(TenantResolver::getSuperAdminProfile());
}

if ($action === 'superadmin_profile_save' && $method === 'POST') {
    require_superadmin();
    $body = get_body();
    TenantResolver::setSuperAdminProfile([
        'nom'    => trim($body['nom'] ?? ''),
        'prenom' => trim($body['prenom'] ?? ''),
        'email'  => trim($body['email'] ?? ''),
    ]);
    json_ok(['message' => 'Profil mis à jour.']);
}

// ── Personnalisation de l'écran de connexion (logo + texte) ───────────────────
if ($action === 'superadmin_branding' && $method === 'GET') {
    require_superadmin();
    json_ok(TenantResolver::getBranding());
}

if ($action === 'superadmin_branding_save' && $method === 'POST') {
    require_superadmin();
    $body = get_body();
    $mode = $body['mode'] ?? 'default';

    // Validation de l'image (mode "image") : data URL image, taille raisonnable.
    $logo = (string)($body['logo_image'] ?? '');
    if ($logo !== '' && !preg_match('#^data:image/(png|jpe?g|gif|webp|svg\+xml);base64,#i', $logo)) {
        json_error("Format d'image non supporté (PNG, JPEG, GIF, WEBP ou SVG attendu).");
    }
    if (strlen($logo) > 700000) { // ~512 Ko binaire ≈ 700 Ko encodés en base64
        json_error("Image trop volumineuse (≈ 500 Ko max). Réduisez-la avant l'envoi.");
    }

    // Les couleurs sont validées (hex strict) dans setBranding ; une valeur
    // invalide est silencieusement ramenée au thème de base.
    // Le CSS personnalisé est filtré : on remonte une erreur claire au SA.
    $css = (string)($body['custom_css'] ?? '');
    $cssErr = TenantResolver::loginCssError($css);
    if ($cssErr !== null) json_error($cssErr);

    TenantResolver::setBranding([
        'mode'         => $mode,
        'title'        => trim($body['title'] ?? ''),
        'subtitle'     => trim($body['subtitle'] ?? ''),
        'logo_image'   => $logo,
        'theme_accent' => $body['theme_accent'] ?? '',
        'bg_mode'      => $body['bg_mode'] ?? 'animated',
        'theme_bg1'    => $body['theme_bg1'] ?? '',
        'theme_bg2'    => $body['theme_bg2'] ?? '',
        'custom_mode'  => $body['custom_mode'] ?? 'classic',
        'custom_css'   => $css,
        'kofi_enabled' => !empty($body['kofi_enabled']),
    ]);
    json_ok(['message' => 'Apparence de la connexion mise à jour.']);
}

// ── Branding PUBLIC : lu par l'écran de connexion, AUCUNE authentification ─────
if ($action === 'superadmin_branding_public' && $method === 'GET') {
    try {
        $b = TenantResolver::getBranding();
        // Cache HTTP : le branding change rarement. Un ETag + max-age court évitent
        // une requête DB (et le renvoi du logo base64) à chaque affichage du login.
        $payload = json_encode(['success' => true, 'data' => $b], JSON_UNESCAPED_UNICODE);
        $etag    = '"' . md5($payload) . '"';
        header('Cache-Control: private, max-age=60');
        header('ETag: ' . $etag);
        $inm = trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
        if ($inm !== '' && $inm === $etag) {
            http_response_code(304);
            exit;
        }
        if (ob_get_level()) ob_clean();
        echo $payload;
        exit;
    } catch (\Throwable $e) {
        json_ok(['mode' => 'default']); // ne jamais bloquer le login
    }
}

// ── Setup initial du super admin (première utilisation) ───────────────────────
if ($action === 'superadmin_setup' && $method === 'POST') {
    // ⚠️ FIX SÉCURITÉ : le setup initial est NON authentifié (il faut bien
    // pouvoir créer le premier compte). Sur une installation fraîche encore au
    // mot de passe par défaut, n'importe qui pouvait donc appeler cette action
    // à distance, fixer SES propres identifiants super-admin et verrouiller
    // l'administrateur légitime. On restreint désormais le setup aux requêtes
    // provenant du serveur lui-même (IP de confiance / proxy local). Pour un
    // setup à distance délibéré, activer temporairement
    // securite.allow_remote_setup=true dans config.json.
    $__trusted = cfg('securite', 'trusted_proxies');
    if (!is_array($__trusted)) $__trusted = ['127.0.0.1', '::1'];
    $__ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $__allowRemote = (bool)(cfg('securite', 'allow_remote_setup') ?? false);
    if (!$__allowRemote && !in_array($__ip, $__trusted, true)) {
        error_log('[SECURITY] superadmin_setup refusé depuis une IP non locale (' . $__ip . '). '
            . 'Activez securite.allow_remote_setup pour autoriser un setup à distance.');
        json_error("Configuration initiale autorisée uniquement depuis le serveur. Pour un setup à distance, activez temporairement \"securite.allow_remote_setup\": true dans config.json.", 403);
    }

    $sa = TenantResolver::getSuperAdmin();
    // Autoriser le setup si :
    // 1. Pas de super admin configuré
    // 2. Le mot de passe est encore le mot de passe par défaut
    if ($sa && $sa['mot_de_passe']) {
        $isDefault = password_verify('SuperAdmin2025!', $sa['mot_de_passe']);
        if (!$isDefault) {
            json_error('Le super admin est déjà configuré. Connectez-vous avec vos identifiants puis changez le mot de passe depuis l\'interface.');
        }
    }

    $body = get_body();
    $login = trim($body['login'] ?? 'superadmin');
    $password = $body['password'] ?? '';
    $email = trim($body['email'] ?? '');

    if (!$login) json_error('Login requis.');
    if (strlen($password) < 8) json_error('Mot de passe de 8 caractères minimum.');

    $ok = TenantResolver::updateSuperAdmin(
        $login,
        password_hash($password, PASSWORD_BCRYPT),
        $email
    );
    if (!$ok) json_error('Erreur lors de la configuration.');
    json_ok('Super admin configuré avec succès.');
}

// ═══════════════════════════════════════════════════════════════════════════════
// ── SAUVEGARDES PAR TENANT ────────────────────────────────────────────────────
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Résout un chemin relatif au projet. Retourne un chemin absolu normalisé.
 */
function _sa_resolve_path(string $p): string {
    $p = trim($p);
    if ($p === '') return '';
    // Windows drive letter (C:\...) ou chemin absolu unix (/...)
    if (str_starts_with($p, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $p)) return $p;
    return __DIR__ . '/../../' . $p;
}

/**
 * Construit un nom de fichier de sauvegarde pour un tenant.
 * Ex: tenant_default_20260421_143022.db
 */
function _sa_backup_filename(string $tenantKey, string $driver, bool $compress): string {
    $stamp = date('Ymd_His');
    $safeKey = preg_replace('/[^a-z0-9_.-]/i', '_', $tenantKey);
    $ext = ($driver === 'sqlite') ? 'db' : 'sql';
    $name = "tenant_{$safeKey}_{$stamp}.{$ext}";
    if ($compress) $name .= '.gz';
    return $name;
}

/**
 * Effectue une sauvegarde d'un tenant.
 * - SQLite  → copie du fichier .db (+ gzip optionnel)
 * - PostgreSQL / MariaDB → dump SQL via PDO (+ gzip optionnel)
 *
 * @return array{path: string, size: int, name: string}
 */
function _sa_do_backup(array $tenant, ?string $customDir, bool $overrideCompress = null): array {
    $dbCfg = $tenant['base_de_donnees'] ?? [];
    $driver = $dbCfg['driver'] ?? 'sqlite';
    $sv = $tenant['sauvegarde'] ?? [];
    $compress = $overrideCompress !== null ? $overrideCompress : (bool)($sv['compresser'] ?? true);

    // Dossier de destination
    $dir = $customDir ?: ($sv['dossier'] ?? 'data/backups');
    $dir = _sa_resolve_path($dir);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        throw new \RuntimeException("Impossible de créer le dossier : {$dir}");
    }
    if (!is_writable($dir)) {
        throw new \RuntimeException("Dossier non inscriptible : {$dir}");
    }

    $tenantKey = $tenant['_key'] ?? 'tenant';
    $fname = _sa_backup_filename($tenantKey, $driver, $compress);
    $outPath = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $fname;

    if ($driver === 'sqlite') {
        $src = $dbCfg['path'] ?? '';
        $src = _sa_resolve_path($src);
        if (!is_file($src)) throw new \RuntimeException("Fichier SQLite introuvable : {$src}");

        if ($compress) {
            // copier vers .gz
            $in = fopen($src, 'rb');
            $out = gzopen($outPath, 'wb9');
            if (!$in || !$out) throw new \RuntimeException("Impossible d'ouvrir les fichiers pour compression.");
            while (!feof($in)) { gzwrite($out, fread($in, 65536)); }
            fclose($in); gzclose($out);
        } else {
            if (!copy($src, $outPath)) throw new \RuntimeException("Échec de la copie SQLite → {$outPath}");
        }
    } else {
        // pgsql / mariadb : dump SQL via PDO (fallback universel sans dépendre de pg_dump/mysqldump)
        $db = new \Database($dbCfg);
        $db->ensureFullSchema(); // s'assurer que toutes les colonnes lazy sont présentes
        $sql = _sa_dump_sql_via_pdo($db->getPdo(), $driver);
        if ($compress) {
            $gz = gzopen($outPath, 'wb9');
            if (!$gz) throw new \RuntimeException("Impossible d'ouvrir {$outPath}");
            gzwrite($gz, $sql);
            gzclose($gz);
        } else {
            if (file_put_contents($outPath, $sql) === false) {
                throw new \RuntimeException("Échec de l'écriture → {$outPath}");
            }
        }
    }

    return [
        'path' => $outPath,
        'size' => filesize($outPath) ?: 0,
        'name' => $fname,
    ];
}

/**
 * Tables exclues des imports/exports BDD. Les comptes ne sont jamais ni
 * exportés ni importés — si besoin, l'admin les recrée manuellement après
 * un import. Ça évite tous les conflits de Login / Email / Id entre deux
 * bases qui partageraient certains comptes.
 *
 * - Utilisateurs     : les comptes eux-mêmes (login/mdp/rôle/email…)
 * - PushSubscriptions: abonnements Web Push liés à un UserId — deviendraient
 *                      orphelins sans les comptes correspondants.
 */
const SA_TABLES_EXCLUES_IMPORT_EXPORT = ['Utilisateurs', 'PushSubscriptions'];

/**
 * Dump minimaliste compatible pgsql/mariadb via PDO (INSERT statements).
 * Produit des INSERT simples + des SETVAL pour recaler les séquences Postgres.
 *
 * IMPORTANT : ce dump ne contient pas de DROP/TRUNCATE/SET privilégiés.
 * C'est le CODE D'IMPORT qui vide la base cible au préalable via
 * _sa_purge_database() et recrée le schéma vide via `new Database($dbCfg)`.
 * Cela permet de fonctionner avec un utilisateur PostgreSQL non-superuser.
 *
 * Les tables listées dans SA_TABLES_EXCLUES_IMPORT_EXPORT ne sont PAS dumpées
 * afin que les comptes de la base cible soient préservés à l'import.
 */
function _sa_dump_sql_via_pdo(PDO $pdo, string $driver): string {
    $out = "-- Larka dump tenant — " . date('Y-m-d H:i:s') . "\n";
    $out .= "-- Driver: {$driver}\n";
    $out .= "-- IMPORTANT : l'import via Larka purge la base avant d'exécuter ce script.\n";
    $out .= "-- Tables exclues (comptes utilisateurs préservés côté cible) : "
          . implode(', ', SA_TABLES_EXCLUES_IMPORT_EXPORT) . "\n\n";

    // Lister les tables
    if ($driver === 'pgsql') {
        $tables = $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename")->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    }

    // Filtrer les tables exclues (case-insensitive pour robustesse)
    $exclusLower = array_map('strtolower', SA_TABLES_EXCLUES_IMPORT_EXPORT);
    $tables = array_values(array_filter($tables, function($t) use ($exclusLower) {
        return !in_array(strtolower((string)$t), $exclusLower, true);
    }));

    // Dump des données
    foreach ($tables as $table) {
        $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        $out .= "-- Table: {$safe}\n";
        $quoteTbl = ($driver === 'pgsql') ? "\"{$safe}\"" : "`{$safe}`";
        $rows = $pdo->query("SELECT * FROM {$quoteTbl}")->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) { $out .= "-- (vide)\n\n"; continue; }
        $cols = array_keys($rows[0]);
        if ($driver === 'pgsql') {
            $colList = '"' . implode('","', $cols) . '"';
        } else {
            $colList = '`' . implode('`,`', $cols) . '`';
        }
        foreach ($rows as $row) {
            $vals = array_map(function($v) use ($pdo) {
                if ($v === null) return 'NULL';
                if (is_int($v) || is_float($v)) return (string)$v;
                return $pdo->quote((string)$v);
            }, array_values($row));
            $out .= "INSERT INTO {$quoteTbl} ({$colList}) VALUES (" . implode(',', $vals) . ");\n";
        }
        $out .= "\n";
    }

    // Resync des séquences Postgres : après les INSERT explicites avec id forcé,
    // on pousse la séquence au MAX(id)+1 pour que les prochains INSERT sans id
    // ne génèrent pas de doublons. Ne nécessite AUCUN privilège spécial.
    if ($driver === 'pgsql') {
        $out .= "-- Resync des séquences après réinsertion (pas de privilège requis)\n";
        foreach ($tables as $table) {
            $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
            try {
                $seqRow = $pdo->query("SELECT column_name, pg_get_serial_sequence('\"{$safe}\"', column_name) AS seq
                    FROM information_schema.columns
                    WHERE table_schema='public' AND table_name='{$safe}'
                    AND pg_get_serial_sequence('\"{$safe}\"', column_name) IS NOT NULL
                    LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                if ($seqRow && !empty($seqRow['seq'])) {
                    $col = $seqRow['column_name'];
                    $out .= "SELECT setval('{$seqRow['seq']}', COALESCE((SELECT MAX(\"{$col}\") FROM \"{$safe}\"), 1), true);\n";
                }
            } catch (\Throwable $_) { /* table sans séquence, on ignore */ }
        }
    }

    return $out;
}

/**
 * Découpe un script SQL en instructions individuelles, en respectant les
 * chaînes literales (single quotes, avec '' pour échapper). Bien plus robuste
 * qu'un simple explode(';') qui casse dès qu'une valeur texte contient ";".
 *
 * @return string[]
 */
function _sa_split_sql_statements(string $sql): array {
    $stmts = [];
    $buf = '';
    $len = strlen($sql);
    $inString = false;     // true si on est dans une ' ... '
    $inLineComment = false; // true si on est dans -- ... \n
    $i = 0;
    while ($i < $len) {
        $c = $sql[$i];
        $next = $i + 1 < $len ? $sql[$i + 1] : '';

        if ($inLineComment) {
            $buf .= $c;
            if ($c === "\n") $inLineComment = false;
            $i++; continue;
        }

        if (!$inString && $c === '-' && $next === '-') {
            $inLineComment = true;
            $buf .= $c;
            $i++; continue;
        }

        if ($c === "'") {
            if ($inString && $next === "'") {
                // '' échappé = un seul ' dans la chaîne
                $buf .= "''";
                $i += 2; continue;
            }
            $inString = !$inString;
            $buf .= $c;
            $i++; continue;
        }

        if ($c === ';' && !$inString) {
            $stmts[] = $buf;
            $buf = '';
            $i++; continue;
        }

        $buf .= $c;
        $i++;
    }
    if (trim($buf) !== '') $stmts[] = $buf;
    return $stmts;
}

/**
 * Vide totalement une base PG/MariaDB : DROP de toutes les tables et séquences
 * du schéma courant. À utiliser avant un import de BDD complet.
 *
 * Stratégie : on évite SET session_replication_role (réservé au superuser PG)
 * et SET FOREIGN_KEY_CHECKS=0 (nécessite FILE/SUPER privilege sur certains MySQL).
 * À la place on boucle : on essaye DROP ... CASCADE en plusieurs passes pour
 * absorber les dépendances circulaires.
 *
 * Pour SQLite : n/a — on écrase le fichier directement.
 */
function _sa_purge_database(PDO $pdo, string $driver): void {
    if ($driver === 'pgsql') {
        // 1. DROP des tables (CASCADE pour gérer les FK) — boucle jusqu'à épuisement
        $maxPasses = 5;
        for ($pass = 0; $pass < $maxPasses; $pass++) {
            $tables = $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname='public'")->fetchAll(PDO::FETCH_COLUMN);
            if (empty($tables)) break;
            $dropped = 0;
            foreach ($tables as $t) {
                $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $t);
                try {
                    $pdo->exec("DROP TABLE IF EXISTS \"{$safe}\" CASCADE");
                    $dropped++;
                } catch (\Throwable $_) { /* réessayer à la passe suivante */ }
            }
            if ($dropped === 0) break;
        }

        // 2. Séquences orphelines
        try {
            $seqs = $pdo->query("SELECT sequence_name FROM information_schema.sequences WHERE sequence_schema='public'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($seqs as $s) {
                $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $s);
                try { $pdo->exec("DROP SEQUENCE IF EXISTS \"{$safe}\" CASCADE"); } catch (\Throwable $_) {}
            }
        } catch (\Throwable $_) {}

        // 3. Vues éventuelles
        try {
            $views = $pdo->query("SELECT viewname FROM pg_views WHERE schemaname='public'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($views as $v) {
                $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $v);
                try { $pdo->exec("DROP VIEW IF EXISTS \"{$safe}\" CASCADE"); } catch (\Throwable $_) {}
            }
        } catch (\Throwable $_) {}

        // 4. Fonctions / procédures stockées
        try {
            $funs = $pdo->query("
                SELECT n.nspname || '.' || p.proname || '(' ||
                       pg_get_function_identity_arguments(p.oid) || ')' AS sig
                FROM pg_proc p
                JOIN pg_namespace n ON n.oid = p.pronamespace
                WHERE n.nspname = 'public'
                  AND p.prokind IN ('f', 'p')
            ")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($funs as $sig) {
                try { $pdo->exec("DROP FUNCTION IF EXISTS $sig CASCADE"); } catch (\Throwable $_) {}
            }
        } catch (\Throwable $_) {}

        // 5. Types personnalisés / ENUMs / domaines
        try {
            $types = $pdo->query("
                SELECT t.typname FROM pg_type t
                JOIN pg_namespace n ON n.oid = t.typnamespace
                WHERE n.nspname = 'public'
                  AND t.typtype IN ('e', 'c', 'd')
                  AND NOT EXISTS (SELECT 1 FROM pg_class c WHERE c.relname = t.typname)
            ")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($types as $tname) {
                $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $tname);
                try { $pdo->exec("DROP TYPE IF EXISTS \"{$safe}\" CASCADE"); } catch (\Throwable $_) {}
            }
        } catch (\Throwable $_) {}

        // 6. Vérification finale : il ne doit rester aucune table
        try {
            $remaining = $pdo->query("SELECT COUNT(*) FROM pg_tables WHERE schemaname='public'")->fetchColumn();
            if ($remaining > 0) {
                throw new \RuntimeException("$remaining table(s) restante(s) après purge — vérifiez les permissions de l'utilisateur PostgreSQL.");
            }
        } catch (\Throwable $e) {
            // Remonte l'erreur pour information
            throw $e;
        }

    } elseif ($driver === 'mariadb' || $driver === 'mysql') {
        // Essayer SET FOREIGN_KEY_CHECKS (session-level, normalement dispo sans privilège spécial)
        try { $pdo->exec("SET FOREIGN_KEY_CHECKS = 0"); } catch (\Throwable $_) {}
        $maxPasses = 5;
        for ($pass = 0; $pass < $maxPasses; $pass++) {
            $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            if (empty($tables)) break;
            $dropped = 0;
            foreach ($tables as $t) {
                $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $t);
                try { $pdo->exec("DROP TABLE IF EXISTS `{$safe}`"); $dropped++; } catch (\Throwable $_) {}
            }
            if ($dropped === 0) break;
        }
        try { $pdo->exec("SET FOREIGN_KEY_CHECKS = 1"); } catch (\Throwable $_) {}
    }
    // SQLite : cette fonction n'est pas appelée (on écrase le fichier)
}

/**
 * Applique la rotation : supprime les sauvegardes en trop au-delà de "garder".
 */
function _sa_rotate_backups(string $dir, string $tenantKey, int $keep): array {
    if (!is_dir($dir)) return [];
    $safeKey = preg_replace('/[^a-z0-9_.-]/i', '_', $tenantKey);
    $prefix = "tenant_{$safeKey}_";
    $files = [];
    foreach (scandir($dir) as $f) {
        if (str_starts_with($f, $prefix)) {
            $files[] = ['name' => $f, 'path' => $dir . DIRECTORY_SEPARATOR . $f, 'mtime' => filemtime($dir . DIRECTORY_SEPARATOR . $f)];
        }
    }
    usort($files, fn($a, $b) => $b['mtime'] <=> $a['mtime']); // plus récents en premier
    $removed = [];
    if (count($files) > $keep) {
        foreach (array_slice($files, $keep) as $old) {
            if (@unlink($old['path'])) $removed[] = $old['name'];
        }
    }
    return $removed;
}

// ── Sauvegarde manuelle (à la demande) ────────────────────────────────────────
if ($action === 'superadmin_tenant_backup_now' && $method === 'POST') {
    require_superadmin();
    $body = get_body();
    $key = trim($body['key'] ?? '');
    $destination = $body['destination'] ?? null; // dossier personnalisé optionnel
    $compress = isset($body['compresser']) ? (bool)$body['compresser'] : null;

    if (!$key) json_error('Clé du tenant requise.');
    $tenants = TenantResolver::getAllTenants();
    if (!isset($tenants[$key])) json_error('Tenant inconnu.');

    $tenant = $tenants[$key];
    $tenant['_key'] = $key;

    try {
        $result = _sa_do_backup($tenant, $destination, $compress);
        // Rotation (basée sur le dossier effectif utilisé)
        $keep = (int)($tenant['sauvegarde']['garder'] ?? 30);
        $dirUsed = dirname($result['path']);
        $removed = _sa_rotate_backups($dirUsed, $key, $keep);

        // Mémoriser la date de la dernière sauvegarde dans le tenant
        $tenant['sauvegarde']['derniere'] = date('Y-m-d H:i:s');
        TenantResolver::upsertTenant($key, $tenant);

        json_ok([
            'message'  => 'Sauvegarde créée.',
            'fichier'  => $result['name'],
            'chemin'   => $result['path'],
            'taille'   => $result['size'],
            'rotation' => $removed,
        ]);
    } catch (\Throwable $e) {
        json_error('Erreur sauvegarde : ' . $e->getMessage(), 500);
    }
}

// ── Lister les sauvegardes existantes d'un tenant ─────────────────────────────
if ($action === 'superadmin_tenant_backup_list' && $method === 'GET') {
    require_superadmin();
    $key = trim($_GET['key'] ?? '');
    if (!$key) json_error('Clé du tenant requise.');
    $tenants = TenantResolver::getAllTenants();
    if (!isset($tenants[$key])) json_error('Tenant inconnu.');

    $tenant = $tenants[$key];
    $dir = _sa_resolve_path($tenant['sauvegarde']['dossier'] ?? 'data/backups');
    $safeKey = preg_replace('/[^a-z0-9_.-]/i', '_', $key);
    $prefix = "tenant_{$safeKey}_";

    $files = [];
    if (is_dir($dir)) {
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') continue;
            if (!str_starts_with($f, $prefix)) continue;
            $full = $dir . DIRECTORY_SEPARATOR . $f;
            $files[] = [
                'nom'     => $f,
                'taille'  => filesize($full) ?: 0,
                'date'    => date('Y-m-d H:i:s', filemtime($full)),
            ];
        }
    }
    usort($files, fn($a, $b) => strcmp($b['date'], $a['date']));

    json_ok([
        'dossier'    => $dir,
        'fichiers'   => $files,
        'derniere'   => $tenant['sauvegarde']['derniere'] ?? null,
    ]);
}

// ── Export de la BDD complète (téléchargement direct) ─────────────────────────
if ($action === 'superadmin_tenant_export_db' && $method === 'GET') {
    require_superadmin();
    $key = trim($_GET['key'] ?? '');
    $compress = ($_GET['compress'] ?? '1') === '1';
    if (!$key) json_error('Clé du tenant requise.');

    $tenants = TenantResolver::getAllTenants();
    if (!isset($tenants[$key])) json_error('Tenant inconnu.');

    $tenant = $tenants[$key];
    $tenant['_key'] = $key;
    $dbCfg = $tenant['base_de_donnees'];
    $driver = $dbCfg['driver'] ?? 'sqlite';
    $fname = _sa_backup_filename($key, $driver, $compress);

    // Reset output buffer
    while (ob_get_level()) ob_end_clean();

    if ($driver === 'sqlite') {
        $src = _sa_resolve_path($dbCfg['path'] ?? '');
        if (!is_file($src)) json_error('Fichier SQLite introuvable.', 404);

        // On ne peut pas streamer le fichier source directement car il contient
        // les comptes (Utilisateurs + PushSubscriptions). On fait une copie dans
        // un fichier temporaire, on vide ces tables dans la copie, et on streame
        // la copie — la base d'origine n'est évidemment pas touchée.
        $tmp = tempnam(sys_get_temp_dir(), 'gmao_exp_');
        if (!copy($src, $tmp)) { @unlink($tmp); json_error('Échec copie temporaire pour export.', 500); }
        try {
            $tmpPdo = new PDO('sqlite:' . $tmp);
            $tmpPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            foreach (SA_TABLES_EXCLUES_IMPORT_EXPORT as $tbl) {
                $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $tbl);
                try { $tmpPdo->exec("DELETE FROM \"{$safe}\""); } catch (\Throwable $_) { /* table absente : ignorer */ }
            }
            // VACUUM pour réduire la taille du fichier exporté (facultatif mais propre)
            try { $tmpPdo->exec('VACUUM'); } catch (\Throwable $_) {}
            $tmpPdo = null;
        } catch (\Throwable $e) {
            @unlink($tmp);
            json_error('Erreur lors du filtrage des comptes à l\'export : ' . $e->getMessage(), 500);
        }

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        if ($compress) {
            // Streamer gzippé depuis la copie filtrée
            $in = fopen($tmp, 'rb');
            $gz = gzopen('php://output', 'wb9');
            while (!feof($in)) { gzwrite($gz, fread($in, 65536)); }
            fclose($in); gzclose($gz);
        } else {
            header('Content-Length: ' . filesize($tmp));
            readfile($tmp);
        }
        @unlink($tmp);
    } else {
        try {
            $db = new \Database($dbCfg);
            $db->ensureFullSchema();
            $sql = _sa_dump_sql_via_pdo($db->getPdo(), $driver);
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $fname . '"');
            if ($compress) echo gzencode($sql, 9);
            else echo $sql;
        } catch (\Throwable $e) {
            json_error('Erreur export : ' . $e->getMessage(), 500);
        }
    }
    exit;
}

// ── Import de la BDD complète (upload d'un fichier) ───────────────────────────
if ($action === 'superadmin_tenant_import_db' && $method === 'POST') {
    require_superadmin();
    $key = trim($_POST['key'] ?? $_GET['key'] ?? '');
    if (!$key) json_error('Clé du tenant requise.');

    $tenants = TenantResolver::getAllTenants();
    if (!isset($tenants[$key])) json_error('Tenant inconnu.');

    if (!isset($_FILES['fichier']) || $_FILES['fichier']['error'] !== UPLOAD_ERR_OK) {
        $err = $_FILES['fichier']['error'] ?? 'aucun fichier';
        json_error('Upload échoué (code ' . $err . '). Vérifiez post_max_size / upload_max_filesize.', 400);
    }

    $tenant = $tenants[$key];
    $dbCfg = $tenant['base_de_donnees'];
    $driver = $dbCfg['driver'] ?? 'sqlite';

    $tmpFile = $_FILES['fichier']['tmp_name'];
    $origName = $_FILES['fichier']['name'] ?? 'import';
    $isGz = str_ends_with(strtolower($origName), '.gz');

    // Sauvegarde de sûreté AVANT l'import (automatique)
    $safetyBackup = null;
    try {
        $tenantWithKey = $tenant; $tenantWithKey['_key'] = $key;
        $safetyBackup = _sa_do_backup($tenantWithKey, null, true);
    } catch (\Throwable $e) {
        // On n'empêche pas l'import mais on signale
    }

    try {
        if ($driver === 'sqlite') {
            $dest = _sa_resolve_path($dbCfg['path'] ?? '');
            if (!$dest) json_error('Chemin SQLite non configuré.', 500);
            $dir = dirname($dest);
            if (!is_dir($dir)) @mkdir($dir, 0755, true);

            // ── Snapshot des comptes de la base cible AVANT écrasement ────────
            // On écrase le fichier SQLite en bloc, ce qui efface aussi les comptes
            // actuels. On les mémorise pour les restaurer juste après, afin que
            // l'import ne touche PAS aux comptes (ni import, ni perte).
            $comptesSnap = [];
            $debug = ['snapshot_avant' => [], 'vidage_apres' => [], 'reinjection' => []];
            if (is_file($dest)) {
                try {
                    $snapPdo = new PDO('sqlite:' . $dest);
                    $snapPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    foreach (SA_TABLES_EXCLUES_IMPORT_EXPORT as $tbl) {
                        $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $tbl);
                        try {
                            $rows = $snapPdo->query("SELECT * FROM \"{$safe}\"")->fetchAll(PDO::FETCH_ASSOC);
                            if (is_array($rows) && !empty($rows)) {
                                $comptesSnap[$safe] = [
                                    'cols' => array_keys($rows[0]),
                                    'rows' => array_map('array_values', $rows),
                                ];
                                $debug['snapshot_avant'][$safe] = count($rows);
                            } else {
                                $debug['snapshot_avant'][$safe] = 0;
                            }
                        } catch (\Throwable $e) {
                            $debug['snapshot_avant'][$safe] = 'err:' . substr($e->getMessage(), 0, 80);
                        }
                    }
                    $snapPdo = null;
                } catch (\Throwable $e) {
                    $debug['snapshot_avant']['_ouverture'] = 'err:' . substr($e->getMessage(), 0, 80);
                }
            } else {
                $debug['snapshot_avant']['_fichier'] = 'absent (nouvelle base)';
            }

            if ($isGz) {
                // Décompresser
                $in = gzopen($tmpFile, 'rb');
                $out = fopen($dest, 'wb');
                if (!$in || !$out) json_error("Impossible d'ouvrir les fichiers.", 500);
                while (!gzeof($in)) { fwrite($out, gzread($in, 65536)); }
                gzclose($in); fclose($out);
            } else {
                if (!copy($tmpFile, $dest)) json_error('Échec copie.', 500);
            }
            // Vérif minimale : entête SQLite
            $fh = fopen($dest, 'rb');
            $magic = fread($fh, 16); fclose($fh);
            if (!str_starts_with($magic, 'SQLite format 3')) {
                json_error('Fichier invalide (en-tête SQLite non détecté). La sauvegarde de sûreté a été conservée : ' . ($safetyBackup['name'] ?? 'n/a'), 400);
            }

            // ── Restauration des comptes cibles ───────────────────────────────
            // 1) On garantit que les tables de comptes existent (au cas où le
            //    dump importé était filtré sans ces tables).
            // 2) On vide tout ce que le dump aurait pu y mettre.
            // 3) On réinjecte le snapshot pris AVANT l'écrasement.
            // Les comptes de la base cible sont ainsi STRICTEMENT les mêmes
            // qu'avant l'import — rien n'a été importé, rien n'a été perdu.
            $comptesRestaures = [];
            try {
                $dbImp = new \Database($dbCfg);
                $dbImp->ensureFullSchemaWithoutSeeds();
                $pdoImp = $dbImp->getPdo();

                foreach (SA_TABLES_EXCLUES_IMPORT_EXPORT as $tbl) {
                    $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $tbl);

                    // (2) Vider la table dans le fichier importé
                    try {
                        // Compter avant DELETE pour debug
                        $nbAvant = (int)$pdoImp->query("SELECT COUNT(*) FROM \"{$safe}\"")->fetchColumn();
                        $pdoImp->exec("DELETE FROM \"{$safe}\"");
                        $debug['vidage_apres'][$safe] = "vidé ({$nbAvant} ligne(s) du dump importé supprimée(s))";
                    } catch (\Throwable $e) {
                        $debug['vidage_apres'][$safe] = 'err:' . substr($e->getMessage(), 0, 80);
                        continue;
                    }

                    // (3) Réinjecter le snapshot cible, s'il y en avait un
                    if (empty($comptesSnap[$safe])) {
                        $debug['reinjection'][$safe] = 'aucun snapshot (base cible était vide)';
                        continue;
                    }
                    $snap = $comptesSnap[$safe];
                    $colList = '"' . implode('","', $snap['cols']) . '"';
                    $nbOk = 0; $nbErr = 0;
                    foreach ($snap['rows'] as $row) {
                        $placeholders = implode(',', array_fill(0, count($row), '?'));
                        try {
                            $stmt = $pdoImp->prepare("INSERT INTO \"{$safe}\" ({$colList}) VALUES ({$placeholders})");
                            $stmt->execute($row);
                            $nbOk++;
                        } catch (\Throwable $_) { $nbErr++; /* skip */ }
                    }
                    $debug['reinjection'][$safe] = "{$nbOk} ligne(s) réinjectée(s)" . ($nbErr > 0 ? ", {$nbErr} skippée(s) pour incompatibilité schéma" : '');
                    if ($nbOk > 0) $comptesRestaures[] = "{$safe} ({$nbOk})";
                }
                unset($dbImp);
            } catch (\Throwable $e) {
                $debug['reinjection']['_erreur_globale'] = $e->getMessage();
            }

            // ── Synchroniser le registre sa_comptes_locaux ────────────────────
            // Les comptes actuels viennent d'être réinjectés. Le registre peut
            // contenir des entrées fantômes venues du dump importé ou manquer
            // des comptes locaux. On resynchronise avec la BDD qui est la vérité.
            try {
                $dbFinal = new \Database($dbCfg);
                $usersFinal = $dbFinal->getAllUtilisateurs();
                $nbSync = TenantResolver::syncLocalAccountsForTenant($key, $usersFinal);
                $debug['registre_sync'] = "{$nbSync} entrée(s) modifiée(s) dans sa_comptes_locaux";
                unset($dbFinal);
            } catch (\Throwable $e) {
                $debug['registre_sync'] = 'err:' . substr($e->getMessage(), 0, 80);
            }

            json_ok([
                'message'           => 'Import SQLite réussi. Les comptes de cette base ont été conservés (l\'import n\'a pas touché aux comptes).',
                'sauvegarde_avant'  => $safetyBackup['name'] ?? null,
                'comptes_preserves' => $comptesRestaures,
                'debug'             => $debug,
            ]);
        } else {
            // pgsql / mariadb : exécuter le SQL
            $sql = $isGz
                ? gzdecode(file_get_contents($tmpFile))
                : file_get_contents($tmpFile);
            if ($sql === false || $sql === null) json_error('Fichier illisible ou corrompu.', 400);

            $db = new \Database($dbCfg);
            $pdo = $db->getPdo();

            // ── Snapshot des comptes de la base cible AVANT purge ─────────────
            // La purge qui suit va tout DROP, y compris les comptes. On les
            // mémorise pour les réinjecter après import — ainsi les comptes
            // de la base cible ne sont PAS impactés (ni par perte, ni par
            // import depuis le dump source).
            $comptesSnap = [];
            foreach (SA_TABLES_EXCLUES_IMPORT_EXPORT as $tbl) {
                $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $tbl);
                $quoteTbl = ($driver === 'pgsql') ? "\"{$safe}\"" : "`{$safe}`";
                try {
                    $rows = $pdo->query("SELECT * FROM {$quoteTbl}")->fetchAll(PDO::FETCH_ASSOC);
                    if (is_array($rows) && !empty($rows)) {
                        $comptesSnap[$safe] = [
                            'cols' => array_keys($rows[0]),
                            'rows' => array_map('array_values', $rows),
                        ];
                    }
                } catch (\Throwable $_) { /* table absente : ignorer */ }
            }

            // ── PURGE : vider complètement la base cible avant d'importer ────
            // Principe : DROP toutes les tables du schéma public (PG) ou de la DB (MariaDB).
            // Le SQL importé doit contenir les CREATE TABLE (via pg_dump/mysqldump) OU nos
            // dumps internes qui s'appuient sur des tables déjà présentes — donc on laisse
            // Database->initTables() recréer le schéma via le ctor \Database ci-dessus si besoin.
            try {
                _sa_purge_database($pdo, $driver);
                // Recréer le schéma COMPLET SANS SEEDS : tables principales +
                // migrations lazy (Energie, Carbone…), mais AUCUN INSERT de
                // données par défaut (Listes, Configuration, admin) car le dump
                // importé fournira ses propres données avec des ids précis
                // qui entreraient en collision avec les seeds.
                // Le 2e paramètre `true` désactive les seeds dès le ctor.
                $db2 = new \Database($dbCfg, true);
                $db2->ensureFullSchemaWithoutSeeds();
                unset($db2);
            } catch (\Throwable $e) {
                json_error('Erreur lors de la purge de la base avant import : ' . $e->getMessage()
                    . '. La sauvegarde de sûreté est conservée : ' . ($safetyBackup['name'] ?? 'n/a'), 500);
            }

            // Parser SQL qui respecte les chaînes (single quote doublé = échappé en SQL standard)
            $stmts = _sa_split_sql_statements($sql);

            // Note : on NE peut PAS mettre SET session_replication_role dans une transaction
            // côté Postgres — il doit s'exécuter au niveau session. Donc pas de beginTransaction
            // pour pgsql. Pour MariaDB on exécute séquentiellement aussi (TRUNCATE autocommit).
            $errors = [];
            $executed = 0;
            try {
                foreach ($stmts as $s) {
                    $s = trim($s);
                    if ($s === '' || str_starts_with($s, '--')) continue;
                    try {
                        $pdo->exec($s);
                        $executed++;
                    } catch (\Throwable $e) {
                        // Conserver la trace mais continuer pour les warnings non bloquants
                        $errors[] = substr($e->getMessage(), 0, 200) . ' // stmt=' . substr($s, 0, 120);
                        if (count($errors) > 5) {
                            throw new \RuntimeException("Trop d'erreurs SQL lors de l'import. Première : " . $errors[0]);
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Réactiver les FK au cas où (MariaDB uniquement — PG n'en a pas besoin)
                try {
                    if ($driver !== 'pgsql') $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
                } catch (\Throwable $_) {}
                json_error('Erreur SQL à l\'import : ' . $e->getMessage() . '. La sauvegarde de sûreté est conservée : ' . ($safetyBackup['name'] ?? 'n/a'), 500);
            }

            // S'assurer que les FK MariaDB sont réactivées (PG : rien à faire)
            try {
                if ($driver !== 'pgsql') $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
            } catch (\Throwable $_) {}

            // ── Restauration des comptes cibles APRÈS import ─────────────────
            // 1) Vider les tables de comptes (enlève tout ce que l'import
            //    aurait pu y écrire en cas de dump non filtré).
            // 2) Réinjecter le snapshot pris AVANT la purge.
            // 3) Resync des séquences Postgres pour éviter les collisions.
            $comptesRestaures = [];
            foreach (SA_TABLES_EXCLUES_IMPORT_EXPORT as $tbl) {
                $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $tbl);
                $quoteTbl = ($driver === 'pgsql') ? "\"{$safe}\"" : "`{$safe}`";

                // (1) Vider
                try { $pdo->exec("DELETE FROM {$quoteTbl}"); }
                catch (\Throwable $_) { continue; }

                // (2) Réinjecter
                if (empty($comptesSnap[$safe])) continue;
                $snap = $comptesSnap[$safe];
                $colList = ($driver === 'pgsql')
                    ? '"' . implode('","', $snap['cols']) . '"'
                    : '`' . implode('`,`', $snap['cols']) . '`';
                $nbOk = 0;
                foreach ($snap['rows'] as $row) {
                    $placeholders = implode(',', array_fill(0, count($row), '?'));
                    try {
                        $stmt = $pdo->prepare("INSERT INTO {$quoteTbl} ({$colList}) VALUES ({$placeholders})");
                        $stmt->execute($row);
                        $nbOk++;
                    } catch (\Throwable $_) { /* incompatibilité de schéma : skip */ }
                }
                if ($nbOk > 0) $comptesRestaures[] = "{$safe} ({$nbOk})";

                // (3) Resync séquence Postgres sur MAX(id)+1
                if ($driver === 'pgsql') {
                    try {
                        $seqRow = $pdo->query("SELECT column_name, pg_get_serial_sequence('\"{$safe}\"', column_name) AS seq
                            FROM information_schema.columns
                            WHERE table_schema='public' AND table_name='{$safe}'
                            AND pg_get_serial_sequence('\"{$safe}\"', column_name) IS NOT NULL
                            LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                        if ($seqRow && !empty($seqRow['seq'])) {
                            $col = $seqRow['column_name'];
                            $pdo->exec("SELECT setval('{$seqRow['seq']}', COALESCE((SELECT MAX(\"{$col}\") FROM \"{$safe}\"), 1), true)");
                        }
                    } catch (\Throwable $_) {}
                }
            }

            // ── Synchroniser le registre sa_comptes_locaux ────────────────────
            // Même logique que pour SQLite (voir commentaire ci-dessus).
            $registreSync = null;
            try {
                $dbFinal = new \Database($dbCfg);
                $usersFinal = $dbFinal->getAllUtilisateurs();
                $nbSync = TenantResolver::syncLocalAccountsForTenant($key, $usersFinal);
                $registreSync = "{$nbSync} entrée(s) modifiée(s)";
                unset($dbFinal);
            } catch (\Throwable $e) {
                $registreSync = 'err:' . substr($e->getMessage(), 0, 80);
            }

            json_ok([
                'message'           => 'Import ' . strtoupper($driver) . ' réussi (' . $executed . ' instructions). Les comptes de cette base ont été conservés (l\'import n\'a pas touché aux comptes).',
                'sauvegarde_avant'  => $safetyBackup['name'] ?? null,
                'avertissements'    => $errors,
                'comptes_preserves' => $comptesRestaures,
                'registre_sync'     => $registreSync,
            ]);
        }
    } catch (\Throwable $e) {
        json_error('Erreur import : ' . $e->getMessage(), 500);
    }
}

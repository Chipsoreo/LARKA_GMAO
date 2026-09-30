<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka — Diagnostic Super Admin / Multi-Tenant
 * Appeler via: https://votre-serveur:8000/api/diagnostic.php
 * PROTÉGÉ : nécessite une session SUPER-ADMIN active.
 *
 * ⚠️ FIX SÉCURITÉ : ce script expose des infos d'infrastructure (driver DB,
 * chemins de fichiers, présence du super-admin, configuration multi-tenant)
 * qui ne doivent JAMAIS être visibles d'un Admin de tenant. Auparavant le
 * contrôle se faisait sur Role==='Admin' (admin de tenant), ce qui suffisait
 * à un compromis intra-tenant pour cartographier toute l'installation.
 *
 * Pour aller plus loin en production : commentez le bloc complet ou
 * supprimez ce fichier. Il n'est utile qu'en debug.
 */
header('Content-Type: application/json; charset=utf-8');

// Require authenticated SUPER-admin session
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/TenantResolver.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

// Désactivation possible en production via constante (à définir dans config.php)
if (defined('DIAGNOSTIC_DISABLED') && DIAGNOSTIC_DISABLED) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Not found.']);
    exit;
}

if (empty($_SESSION['superadmin']['authenticated'])) {
    http_response_code(403);
    // Réponse volontairement neutre — pas d'indication "vous êtes admin
    // mais pas super-admin" qui aiderait un attaquant à savoir qu'il est
    // sur la bonne piste.
    echo json_encode(['success' => false, 'error' => 'Accès refusé.']);
    exit;
}

$diag = [
    'larka_version' => defined('LARKA_VERSION') ? LARKA_VERSION : 'inconnue',
    'php_version' => PHP_VERSION,
    'timestamp' => date('Y-m-d H:i:s'),
    'errors' => [],
    'checks' => [],
];

// 1. Check config.json
$cfgFile = __DIR__ . '/../config.json';
if (file_exists($cfgFile)) {
    $cfg = json_decode(file_get_contents($cfgFile), true);
    $diag['checks']['config_json'] = $cfg !== null ? 'OK' : 'ERREUR JSON: ' . json_last_error_msg();
    if ($cfg) {
        $diag['checks']['config_db_driver'] = $cfg['base_de_donnees']['driver'] ?? 'NON DÉFINI';
        $diag['checks']['config_db_path'] = $cfg['base_de_donnees']['path'] ?? 'NON DÉFINI';
    }
} else {
    $diag['checks']['config_json'] = 'ABSENT';
    $diag['errors'][] = 'config.json introuvable';
}

// 2. Check tenants.json
$tenantsFile = __DIR__ . '/../tenants.json';
if (file_exists($tenantsFile)) {
    $tenants = json_decode(file_get_contents($tenantsFile), true);
    $diag['checks']['tenants_json'] = $tenants !== null ? 'OK' : 'ERREUR JSON: ' . json_last_error_msg();
    if ($tenants) {
        $diag['checks']['tenants_count'] = count($tenants['tenants'] ?? []);
        $diag['checks']['tenants_keys'] = array_keys($tenants['tenants'] ?? []);
        $diag['checks']['has_superadmin'] = isset($tenants['super_admin']);
    }
} else {
    $diag['checks']['tenants_json'] = 'ABSENT (normal si pas encore configuré)';
}

// 3. Check TenantResolver.php
$resolverFile = __DIR__ . '/TenantResolver.php';
$diag['checks']['tenant_resolver_exists'] = file_exists($resolverFile) ? 'OK' : 'ABSENT';

// 4. Try loading config.php
try {
    ob_start();
    // Simulate what index.php does
    $_cfgFile2 = __DIR__ . '/../config.json';
    if (file_exists($_cfgFile2)) {
        $_cfg2 = json_decode(file_get_contents($_cfgFile2), true);
    }
    
    if (file_exists($resolverFile)) {
        require_once $resolverFile;
        $diag['checks']['tenant_resolver_loaded'] = 'OK';
        
        try {
            $tenantResult = TenantResolver::resolve();
            $diag['checks']['tenant_resolved'] = $tenantResult['key'];
            $diag['checks']['tenant_has_config'] = $tenantResult['tenant'] !== null ? 'Oui' : 'Non (utilise config.json)';
            
            $dbCfg = TenantResolver::getDbConfig();
            $diag['checks']['tenant_db_config'] = $dbCfg !== null ? [
                'driver' => $dbCfg['driver'] ?? 'NON DÉFINI',
                'path' => $dbCfg['path'] ?? 'N/A',
            ] : 'null (utilise config.json)';
        } catch (\Throwable $e) {
            $diag['errors'][] = 'TenantResolver::resolve() erreur: ' . $e->getMessage();
        }
    }
    ob_end_clean();
} catch (\Throwable $e) {
    ob_end_clean();
    $diag['errors'][] = 'Erreur config: ' . $e->getMessage();
}

// 5. Check Database connectivity
try {
    if (!defined('DB_DRIVER')) {
        require_once __DIR__ . '/config.php';
    }
    $diag['checks']['db_constants'] = [
        'DB_DRIVER' => defined('DB_DRIVER') ? DB_DRIVER : 'NON DÉFINI',
        'DB_PATH' => defined('DB_PATH') ? DB_PATH : 'NON DÉFINI',
        'DB_HOST' => defined('DB_HOST') ? DB_HOST : 'NON DÉFINI',
    ];
    
    if (defined('DB_DRIVER') && DB_DRIVER === 'sqlite' && defined('DB_PATH')) {
        $diag['checks']['db_file_exists'] = file_exists(DB_PATH) ? 'OK (' . round(filesize(DB_PATH)/1024) . ' Ko)' : 'ABSENT: ' . DB_PATH;
        $diag['checks']['db_dir_writable'] = is_writable(dirname(DB_PATH)) ? 'OK' : 'NON WRITABLE';
    }
    
    // Try PDO connection
    require_once __DIR__ . '/Database.php';
    $db = new Database();
    $diag['checks']['database_connection'] = 'OK';
    
    // Count users
    $users = $db->getAllUtilisateurs();
    $diag['checks']['users_count'] = count($users);
    
} catch (\Throwable $e) {
    $diag['errors'][] = 'Database erreur: ' . $e->getMessage() . ' (line ' . $e->getLine() . ')';
}

// 6. Check HTTP Host
$diag['checks']['http_host'] = $_SERVER['HTTP_HOST'] ?? 'NON DÉFINI';
$diag['checks']['request_uri'] = $_SERVER['REQUEST_URI'] ?? 'NON DÉFINI';

// 7. Check all API route files exist
$routes = ['auth.php', 'superadmin.php', 'inventaire.php', 'maintenance.php', 'energie.php', 'gestion.php', 'stats.php', 'documents.php', 'archives.php', 'migration.php'];
foreach ($routes as $r) {
    $diag['checks']['route_' . str_replace('.php', '', $r)] = file_exists(__DIR__ . '/routes/' . $r) ? 'OK' : 'ABSENT';
}

// 8. Check JS files exist
$jsFiles = ['superadmin-auth.js', 'pages/superadmin.js'];
foreach ($jsFiles as $js) {
    $diag['checks']['js_' . str_replace(['/', '.'], '_', $js)] = file_exists(__DIR__ . '/../js/' . $js) ? 'OK' : 'ABSENT';
}

// Summary
$diag['status'] = empty($diag['errors']) ? 'OK - Tout semble fonctionnel' : 'ERREURS DÉTECTÉES';

echo json_encode($diag, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = ltrim($uri, '/');

function deny_request(int $code = 403, string $message = 'Accès interdit'): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

// Bloquer les fichiers / dossiers sensibles exposés à la racine web.
// Important : le serveur PHP de développement sert les fichiers statiques tels quels.
$blockedPrefixes = [
    'data/',
    '.git/',
    '.svn/',
    '.env',
];
foreach ($blockedPrefixes as $prefix) {
    if ($uri === rtrim($prefix, '/') || str_starts_with($uri, $prefix)) {
        deny_request();
    }
}

$blockedExact = [
    'config.json',
    'api/env.php',
];
if (in_array($uri, $blockedExact, true)) {
    deny_request();
}

if ($uri !== '') {
    $lowerUri = strtolower($uri);
    $blockedExtensions = [
        '.pem', '.key', '.crt', '.cer', '.p12', '.pfx',
        '.db', '.sqlite', '.sqlite3', '.log', '.bak', '.backup', '.sql', '.gz',
    ];
    foreach ($blockedExtensions as $ext) {
        if (str_ends_with($lowerUri, $ext)) {
            deny_request();
        }
    }
}

// Callback OAuth Microsoft
if ($uri === 'oauth/microsoft') {
    parse_str(parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY) ?? '', $_GET);
    require __DIR__ . '/oauth/microsoft.php'; exit;
}
if ($uri === 'oauth/google' || str_starts_with($uri, 'oauth/google')) {
    parse_str(parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY) ?? '', $_GET);
    require __DIR__ . '/oauth/google.php'; exit;
}

// ⚠️ FIX SÉCURITÉ : n'autoriser l'exécution QUE des points d'entrée PHP connus.
// Le serveur PHP de développement exécute TOUT fichier .php présent dans le
// webroot. Sans ce garde, un .php uploadé, ou un fichier interne (api/config.php,
// api/Database.php, api/diagnostic.php…) appelé directement, s'exécuterait.
// Miroir de la politique nginx (seuls index.php + oauth/*.php sont servis).
if (str_ends_with(strtolower($uri), '.php')) {
    $allowedPhp = ['api/index.php', 'oauth/microsoft.php', 'oauth/google.php'];
    if (!in_array($uri, $allowedPhp, true)) {
        deny_request(404, 'Introuvable');
    }
}

// Fichiers statiques
if ($uri !== '' && file_exists(__DIR__ . '/' . $uri) && !is_dir(__DIR__ . '/' . $uri)) {
    return false;
}

// API
if (str_starts_with($uri, 'api/')) {
    return false;
}

// SPA → index.html
include __DIR__ . '/index.html';
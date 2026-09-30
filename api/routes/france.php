<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka — Routes : Légifrance (proxy PISTE)
 *
 * Actions : france_legifrance_status, france_legifrance_search,
 *           france_legifrance_suggest, france_legifrance_consult
 * Proxy sécurisé vers l'API Légifrance via la plateforme PISTE.
 */

if (!function_exists('lf_cfg')) {
    function lf_cfg(string $key, $default = null) {
        // Chercher d'abord dans 'legifrance', puis fallback 'legifrance_sandbox'
        $value = cfg('legifrance', $key);
        if ($value !== null) return $value;
        $value = cfg('legifrance_sandbox', $key);
        return $value !== null ? $value : $default;
    }
}

if (!function_exists('lf_is_enabled')) {
    function lf_is_enabled(): bool {
        return (bool)(lf_cfg('actif', false));
    }
}

if (!function_exists('lf_is_configured')) {
    function lf_is_configured(): bool {
        return (bool)lf_cfg('client_id', '') && (bool)lf_cfg('client_secret', '');
    }
}

if (!function_exists('lf_oauth_url')) {
    function lf_oauth_url(): string {
        return rtrim((string)(lf_cfg('oauth_url', 'https://sandbox-oauth.piste.gouv.fr/api/oauth/token')), '/');
    }
}

if (!function_exists('lf_api_base_url')) {
    function lf_api_base_url(): string {
        return rtrim((string)(lf_cfg('api_base_url', 'https://sandbox-api.piste.gouv.fr/dila/legifrance/lf-engine-app')), '/');
    }
}

if (!function_exists('lf_scope')) {
    function lf_scope(): string {
        return (string)(lf_cfg('scope', 'openid') ?: 'openid');
    }
}

if (!function_exists('lf_token_cache_file')) {
    function lf_token_cache_file(): string {
        return __DIR__ . '/../../data/legifrance_sandbox_token.json';
    }
}

if (!function_exists('lf_http_request')) {
    function lf_http_request(string $method, string $url, array $headers = [], ?string $body = null): array {
        $method = strtoupper($method);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER         => true,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            if ($body !== null && $method !== 'GET') curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

            $raw = curl_exec($ch);
            if ($raw === false) {
                $err = curl_error($ch);
                throw new RuntimeException('Erreur réseau Légifrance : ' . $err);
            }
            $status     = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $rawHeaders = substr($raw, 0, $headerSize);
            $rawBody    = substr($raw, $headerSize);

            return [$status, $rawHeaders, $rawBody];
        }

        $ctx = stream_context_create([
            'http' => [
                'method'        => $method,
                'header'        => implode("
", $headers),
                'content'       => ($body !== null && $method !== 'GET') ? $body : '',
                'ignore_errors' => true,
                'timeout'       => 30,
            ],
        ]);

        $rawBody = @file_get_contents($url, false, $ctx);
        if ($rawBody === false) {
            $err = error_get_last();
            throw new RuntimeException('Erreur réseau Légifrance : ' . ($err['message'] ?? 'inconnue'));
        }

        $status = 0;
        $rawHeaders = '';
        foreach (($http_response_header ?? []) as $line) {
            $rawHeaders .= $line . "
";
            if (preg_match('#HTTP/\S+\s+(\d{3})#', $line, $m)) $status = (int)$m[1];
        }

        return [$status, $rawHeaders, $rawBody];
    }
}

if (!function_exists('lf_decode_response_body')) {
    function lf_decode_response_body(string $rawBody) {
        $trim = trim($rawBody);
        if ($trim === '') return [];
        $json = json_decode($trim, true);
        if (json_last_error() === JSON_ERROR_NONE) return $json;
        return ['raw' => $trim];
    }
}

if (!function_exists('lf_get_access_token')) {
    function lf_get_access_token(): string {
        if (!lf_is_enabled()) {
            throw new RuntimeException('Légifrance Sandbox désactivé dans la configuration serveur.');
        }
        if (!lf_is_configured()) {
            throw new RuntimeException('Client ID / Client Secret Légifrance Sandbox non configurés.');
        }

        $cacheFile = lf_token_cache_file();
        if (file_exists($cacheFile)) {
            $cached = json_decode((string)file_get_contents($cacheFile), true);
            if (!empty($cached['access_token']) && !empty($cached['expires_at']) && (int)$cached['expires_at'] > (time() + 60)) {
                return $cached['access_token'];
            }
        }

        $postFields = http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => (string)lf_cfg('client_id', ''),
            'client_secret' => (string)lf_cfg('client_secret', ''),
            'scope'         => lf_scope(),
        ], '', '&', PHP_QUERY_RFC3986);

        [$status, , $rawBody] = lf_http_request('POST', lf_oauth_url(), [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
        ], $postFields);

        $json = json_decode(trim($rawBody), true);
        if ($status < 200 || $status >= 300 || empty($json['access_token'])) {
            $msg = $json['error_description'] ?? $json['error'] ?? trim($rawBody) ?: ('HTTP ' . $status);
            throw new RuntimeException('Échec OAuth Légifrance Sandbox : ' . $msg);
        }

        $token = (string)$json['access_token'];
        $ttl   = max(300, (int)($json['expires_in'] ?? 3600));
        $cacheDir = dirname($cacheFile);
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0777, true);
        @file_put_contents($cacheFile, json_encode([
            'access_token' => $token,
            'expires_at'   => time() + $ttl - 90,
            'scope'        => $json['scope'] ?? lf_scope(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $token;
    }
}

if (!function_exists('lf_allowed_endpoints')) {
    function lf_allowed_endpoints(): array {
        return [
            'search',
            'suggest',
            'list/ping',
            'list/code',
            'consult/getArticle',
            'consult/legiPart',
            'consult/code',
            'consult/juri',
            'consult/jorf',
            'consult/jorfCont',
            'consult/lastNJo',
            'consult/kaliContIdcc',
            'consult/kaliCont',
            'consult/kaliArticle',
            'consult/acco',
            'consult/cnil',
        ];
    }
}

if (!function_exists('lf_api_call')) {
    function lf_api_call(string $method, string $endpoint, ?array $payload = null) {
        $endpoint = trim($endpoint, '/');
        if (!in_array($endpoint, lf_allowed_endpoints(), true)) {
            throw new RuntimeException('Endpoint Légifrance non autorisé : ' . $endpoint);
        }

        $token = lf_get_access_token();
        $url   = lf_api_base_url() . '/' . $endpoint;
        $body  = null;
        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
        ];

        if (strtoupper($method) !== 'GET') {
            $body = json_encode($payload ?? new stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $headers[] = 'Content-Type: application/json';
        }

        [$status, $rawHeaders, $rawBody] = lf_http_request($method, $url, $headers, $body);
        $data = lf_decode_response_body($rawBody);

        if ($status < 200 || $status >= 300) {
            $msg = is_array($data)
                ? ($data['error_description'] ?? $data['error'] ?? $data['message'] ?? null)
                : null;
            if (!$msg && is_array($data) && !empty($data['raw'])) $msg = $data['raw'];
            if (!$msg) $msg = 'HTTP ' . $status;
            throw new RuntimeException('Légifrance Sandbox a répondu avec une erreur : ' . $msg);
        }

        return [
            'status'   => $status,
            'endpoint' => $endpoint,
            'data'     => $data,
            'headers'  => $rawHeaders,
        ];
    }
}

if ($action === 'france_legifrance_config' && $method === 'GET') {
    $user = require_auth();
    json_ok([
        'service'         => 'Légifrance Sandbox',
        'actif'           => lf_is_enabled(),
        'configured'      => lf_is_configured(),
        'host'            => parse_url(lf_api_base_url(), PHP_URL_HOST),
        'api_base_url'    => lf_api_base_url(),
        'oauth_url'       => lf_oauth_url(),
        'scope'           => lf_scope(),
        'version'         => (string)(lf_cfg('version', '2.4.2') ?: '2.4.2'),
        'available_calls' => lf_allowed_endpoints(),
    ]);
}

if ($action === 'france_legifrance_ping' && $method === 'GET') {
    $user = require_auth();
    try {
        $resp = lf_api_call('GET', 'list/ping', null);
        json_ok($resp);
    } catch (Throwable $e) {
        json_error($e->getMessage(), 502);
    }
}

if ($action === 'france_legifrance_search' && $method === 'POST') {
    $user = require_auth();
    $b = get_body();
    if (empty($b['fond']) || empty($b['recherche']) || !is_array($b['recherche'])) {
        json_error('Payload de recherche Légifrance invalide. Les clés fond et recherche sont obligatoires.');
    }
    try {
        $resp = lf_api_call('POST', 'search', $b);
        json_ok($resp);
    } catch (Throwable $e) {
        json_error($e->getMessage(), 502);
    }
}

if ($action === 'france_legifrance_suggest' && $method === 'POST') {
    $user = require_auth();
    $b = get_body();
    try {
        $resp = lf_api_call('POST', 'suggest', $b);
        json_ok($resp);
    } catch (Throwable $e) {
        json_error($e->getMessage(), 502);
    }
}

if ($action === 'france_legifrance_call' && $method === 'POST') {
    $user = require_auth();
    $b = get_body();
    $endpoint = trim((string)($b['endpoint'] ?? ''), '/');
    $payload  = isset($b['payload']) && is_array($b['payload']) ? $b['payload'] : [];
    $httpVerb = strtoupper((string)($b['method'] ?? 'POST'));
    if (!$endpoint) json_error('Endpoint Légifrance manquant.');
    if (!in_array($httpVerb, ['GET', 'POST'], true)) json_error('Méthode HTTP non autorisée.');

    try {
        $resp = lf_api_call($httpVerb, $endpoint, $payload);
        json_ok($resp);
    } catch (Throwable $e) {
        json_error($e->getMessage(), 502);
    }
}

<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka — Routes : Chorus Pro (proxy PISTE)
 *
 * Actions : chorus_visibility, chorus_status, chorus_token_test, chorus_call
 * Proxy sécurisé vers l'API Chorus Pro (facturation publique).
 */

// ── Helpers Chorus ──────────────────────────────────────────────────────────
if (!function_exists('cpro_cfg')) {
function cpro_cfg(string $key, $default = null) {
    static $cfg = null;
    if ($cfg === null) {
        $cfgFile = __DIR__ . '/../../config.json';
        if (!file_exists($cfgFile)) json_error('config.json introuvable.', 404);
        $all = json_decode(file_get_contents($cfgFile), true);
        if ($all === null) json_error('config.json invalide : ' . json_last_error_msg(), 500);
        $cfg = $all['chorus_pro'] ?? [];
    }
    return $cfg[$key] ?? $default;
}

function cpro_is_active(): bool {
    return (bool)cpro_cfg('actif', false);
}

function cpro_is_client_configured(): bool {
    return trim((string)cpro_cfg('client_id', '')) !== ''
        && trim((string)cpro_cfg('client_secret', '')) !== '';
}

function cpro_is_technical_configured(): bool {
    return trim((string)cpro_cfg('technical_login', '')) !== ''
        && trim((string)cpro_cfg('technical_password', '')) !== '';
}

function cpro_resolve_hosts(): array {
    $env = strtolower((string)cpro_cfg('environnement', 'sandbox'));
    $apiBase  = trim((string)cpro_cfg('api_base_url', ''));
    $oauthUrl = trim((string)cpro_cfg('oauth_url', ''));

    // Si les URLs ne sont pas renseignées, déduire depuis l'environnement
    if ($apiBase === '') {
        $apiBase = $env === 'production'
            ? 'https://api.piste.gouv.fr'
            : 'https://sandbox-api.piste.gouv.fr';
    }
    if ($oauthUrl === '') {
        $oauthUrl = $env === 'production'
            ? 'https://oauth.piste.gouv.fr/api/oauth/token'
            : 'https://sandbox-oauth.piste.gouv.fr/api/oauth/token';
    }

    $apiHost   = parse_url($apiBase, PHP_URL_HOST) ?: '';
    $oauthHost = parse_url($oauthUrl, PHP_URL_HOST) ?: '';

    $official = (
        ($env === 'sandbox'    && $apiHost === 'sandbox-api.piste.gouv.fr' && $oauthHost === 'sandbox-oauth.piste.gouv.fr') ||
        ($env === 'production' && $apiHost === 'api.piste.gouv.fr'         && $oauthHost === 'oauth.piste.gouv.fr')
    );

    return compact('env', 'apiBase', 'oauthUrl', 'apiHost', 'oauthHost', 'official');
}

function cpro_tls_config(): array {
    $verify = (bool)cpro_cfg('tls_verify', true);
    $cafile = trim((string)cpro_cfg('ca_bundle_path', ''));
    if ($cafile !== '' && !str_starts_with($cafile, '/') && !preg_match('~^[A-Za-z]:~', $cafile)) {
        $base = realpath(__DIR__ . '/../../') ?: (__DIR__ . '/../../');
        $cafile = rtrim(str_replace('\\', '/', $base), '/') . '/' . ltrim(str_replace('\\', '/', $cafile), '/');
    }
    return ['verify' => $verify, 'cafile' => $cafile];
}

function cpro_http(string $method, string $url, array $headers = [], $body = null): array {
    $tls = cpro_tls_config();

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS, // H3 : ne suivre que des redirections HTTPS
            CURLOPT_MAXREDIRS      => 3,                // H3 : limiter les chaînes de redirection
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => $tls['verify'],
            CURLOPT_SSL_VERIFYHOST => $tls['verify'] ? 2 : 0,
        ]);
        if ($tls['verify'] && $tls['cafile'] !== '') {
            curl_setopt($ch, CURLOPT_CAINFO, $tls['cafile']);
        }
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            json_error('Erreur réseau Chorus Pro : ' . $err, 502);
        }
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status     = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $respBody   = substr($raw, $headerSize);
    } else {
        $ssl = [
            'verify_peer'      => $tls['verify'],
            'verify_peer_name' => $tls['verify'],
            'allow_self_signed' => !$tls['verify'],
        ];
        if ($tls['verify'] && $tls['cafile'] !== '') $ssl['cafile'] = $tls['cafile'];
        $opts = [
            'http' => [
                'method'        => strtoupper($method),
                'header'        => implode("\r\n", $headers),
                'ignore_errors' => true,
                'timeout'       => 45,
            ],
            'ssl' => $ssl,
        ];
        if ($body !== null) $opts['http']['content'] = $body;
        $ctx = stream_context_create($opts);
        $respBody = @file_get_contents($url, false, $ctx);
        if ($respBody === false) {
            json_error('Erreur réseau Chorus Pro : échec de connexion.', 502);
        }
        $status = 0;
        global $http_response_header;
        if (is_array($http_response_header ?? null)) {
            if (preg_match('#HTTP/\S+\s+(\d{3})#', implode("\n", $http_response_header), $m)) {
                $status = (int)$m[1];
            }
        }
    }

    return ['status' => $status, 'body' => $respBody];
}

function cpro_get_token(): string {
    $cacheDir  = __DIR__ . '/../../data';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
    $cacheFile = $cacheDir . '/chorus_token_' . sha1((string)cpro_cfg('oauth_url', '') . '|' . (string)cpro_cfg('client_id', '')) . '.json';

    if (file_exists($cacheFile)) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached) && !empty($cached['access_token']) && (int)($cached['expires_at'] ?? 0) > time() + 30) {
            return (string)$cached['access_token'];
        }
    }

    $hosts = cpro_resolve_hosts();
    $form  = http_build_query([
        'grant_type'    => 'client_credentials',
        'client_id'     => (string)cpro_cfg('client_id', ''),
        'client_secret' => (string)cpro_cfg('client_secret', ''),
        'scope'         => 'openid',
    ], '', '&', PHP_QUERY_RFC3986);

    $res = cpro_http('POST', $hosts['oauthUrl'], [
        'Accept: application/json',
        'Content-Type: application/x-www-form-urlencoded',
    ], $form);

    $json = json_decode((string)$res['body'], true);
    if ($res['status'] < 200 || $res['status'] >= 300 || !is_array($json) || empty($json['access_token'])) {
        $msg = is_array($json) ? ($json['error_description'] ?? $json['error'] ?? '') : '';
        json_error('Échec OAuth Chorus Pro' . ($msg ? ' : ' . $msg : '.'), 502);
    }

    $ttl = max(60, ((int)($json['expires_in'] ?? 3600)) - 60);
    @file_put_contents($cacheFile, json_encode([
        'access_token' => (string)$json['access_token'],
        'expires_at'   => time() + $ttl,
    ], JSON_UNESCAPED_UNICODE));

    return (string)$json['access_token'];
}

/**
 * Droit d'utiliser le proxy Chorus Pro.
 *
 * ⚠️ FIX SÉCURITÉ : chorus_call et chorus_token_test n'exigeaient qu'une
 * session. N'importe quel compte — Demandeur compris — pouvait donc appeler
 * N'IMPORTE QUEL point d'API /cpro/ (dépôt et traitement de factures inclus)
 * avec le COMPTE TECHNIQUE de l'organisation. Et les règles de visibilité
 * (domaines autorisés, connexion Microsoft exigée) n'étaient appliquées
 * qu'à l'affichage, côté navigateur.
 * Désormais : Administrateur / Gestionnaire uniquement, et mêmes règles de
 * visibilité que chorus_visibility, vérifiées ici.
 */
function cpro_exiger_acces(array $user): void {
    require_role($user, ['Admin', 'Gestionnaire']);
    $raison = cpro_refus_visibilite($user);
    if ($raison !== '') json_error($raison, 403);
}

/** Raison du refus selon chorus_pro.visibility, ou '' si l'accès est permis. */
function cpro_refus_visibilite(array $user): string {
    $vis = cpro_cfg('visibility', []);
    if (!is_array($vis)) return '';
    $domains = $vis['allowed_domains'] ?? [];
    if (!empty($domains) && is_array($domains)) {
        // Comptes Google et nombre de comptes locaux n'ont que leur login
        // comme adresse : on s'y rabat quand il en est une.
        $email  = (string)($user['Email'] ?? '');
        if ($email === '' && str_contains((string)($user['Login'] ?? ''), '@')) $email = (string)$user['Login'];
        $domain = strtolower(substr(strrchr($email, '@') ?: '', 1));
        // Sans adresse, le domaine ne peut pas être vérifié : refus (le
        // contrôle d'origine laissait passer les comptes sans email).
        if ($domain === '' || !in_array($domain, array_map('strtolower', $domains), true)) {
            return $domain === ''
                ? "Chorus Pro est réservé aux comptes d'un domaine autorisé (aucune adresse email sur ce compte)."
                : 'Votre domaine email (' . $domain . ') n\'est pas autorisé pour Chorus Pro.';
        }
    }
    if (!empty($vis['require_microsoft'])) {
        $provider = (string)($user['Provider'] ?? 'local');
        if (strtolower($provider) !== 'microsoft') {
            return 'L\'accès Chorus Pro nécessite une connexion Microsoft (actuellement : ' . $provider . ').';
        }
    }
    return '';
}
} // end function_exists('cpro_cfg')

// ── Visibilité (accès conditionnel) ─────────────────────────────────────────
if ($action === 'chorus_visibility') {
    $user = require_auth();
    $vis  = cpro_cfg('visibility', []);
    // Même règle que celle appliquée aux appels (cpro_exiger_acces).
    $reason = in_array($user['Role'] ?? '', ['Admin', 'Gestionnaire'], true)
        ? cpro_refus_visibilite($user)
        : 'Chorus Pro est réservé aux administrateurs et gestionnaires.';
    $allowed = $reason === '';

    json_ok([
        'allowed'          => $allowed,
        'reason'           => $reason,
        'current_email'    => $user['Email'] ?? '',
        'current_provider' => $user['Provider'] ?? 'local',
        'current_domain'   => strtolower(substr(strrchr($user['Email'] ?? '', '@') ?: '', 1)),
    ]);
}

// ── Statut de configuration ──────────────────────────────────────────────────
if ($action === 'chorus_status') {
    $user  = require_auth();
    require_role($user, ['Admin', 'Gestionnaire']);
    $hosts = cpro_resolve_hosts();

    json_ok([
        'service_active'               => cpro_is_active(),
        'client_configured'            => cpro_is_client_configured(),
        'technical_account_configured' => cpro_is_technical_configured(),
        'environment'                  => $hosts['env'],
        'api_host'                     => $hosts['apiHost'],
        'oauth_host'                   => $hosts['oauthHost'],
        'official_mode'                => $hosts['official'],
        'tls_verify'                   => (bool)cpro_cfg('tls_verify', true),
        'ca_bundle_configured'         => trim((string)cpro_cfg('ca_bundle_path', '')) !== '',
    ]);
}

// ── Test du token OAuth2 ─────────────────────────────────────────────────────
if ($action === 'chorus_token_test' && $method === 'POST') {
    $user = require_auth();
    cpro_exiger_acces($user);
    if (!cpro_is_active()) json_error('Le proxy Chorus Pro est désactivé.', 400);
    if (!cpro_is_client_configured()) json_error('Client PISTE non configuré.', 400);

    $token = cpro_get_token();
    json_ok([
        'success'      => true,
        'token_prefix' => substr($token, 0, 12) . '…',
        'message'      => 'Token OAuth2 obtenu avec succès.',
    ]);
}

// ── Appel proxy Chorus Pro (POST) ───────────────────────────────────────────
if ($action === 'chorus_call' && $method === 'POST') {
    $user = require_auth();
    cpro_exiger_acces($user);
    if (!cpro_is_active()) json_error('Le proxy Chorus Pro est désactivé.', 400);
    if (!cpro_is_client_configured()) json_error('Client PISTE non configuré.', 400);
    if (!cpro_is_technical_configured()) json_error('Compte technique Chorus non configuré.', 400);

    $body    = get_body();
    $path    = trim((string)($body['path'] ?? ''));
    $payload = $body['payload'] ?? [];

    if (!$path) json_error('Chemin API Chorus manquant.');
    // Sécurité : ne permettre que des chemins /cpro/...
    if (!str_starts_with($path, '/cpro/') && !str_starts_with($path, 'cpro/')) {
        json_error('Chemin API Chorus invalide (doit commencer par /cpro/).');
    }
    // Pas de « .. », de requête ni de fragment : « /cpro/../autre » sortait
    // du périmètre une fois le chemin normalisé par curl.
    if (!preg_match('~^/?cpro/[A-Za-z0-9_\-./]*$~', $path)
        || preg_match('~(^|/)\.{1,2}(/|$)~', $path)) {
        json_error('Chemin API Chorus invalide.');
    }
    if (!is_array($payload)) json_error('Payload JSON invalide.');

    $hosts = cpro_resolve_hosts();
    $token = cpro_get_token();

    $techLogin = (string)cpro_cfg('technical_login', '');
    $techPass  = (string)cpro_cfg('technical_password', '');
    $cproAccount = base64_encode($techLogin . ':' . $techPass);

    $url       = rtrim($hosts['apiBase'], '/') . '/' . ltrim($path, '/');
    $jsonBody  = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $res = cpro_http('POST', $url, [
        'Accept: application/json',
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
        'cpro-account: ' . $cproAccount,
    ], $jsonBody);

    $json = json_decode((string)$res['body'], true);

    if ($res['status'] < 200 || $res['status'] >= 300) {
        $msg = is_array($json) ? ($json['message'] ?? $json['error'] ?? json_encode($json)) : $res['body'];
        json_error('Erreur Chorus Pro (HTTP ' . $res['status'] . ') : ' . $msg, 502);
    }

    json_ok(is_array($json) ? $json : ['raw' => $res['body']]);
}

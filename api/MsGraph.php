<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka — Client Microsoft Graph (helpers partagés)
 *
 * Ce module regroupe les primitives d'appel à Microsoft Graph et la gestion du
 * jeton délégué. Il est indépendant de tout module fonctionnel : l'annuaire
 * cartographié (présence, agenda) et le journal d'activité s'en servent.
 *
 * Chargé inconditionnellement par api/index.php, avant les routes.
 */

// ── Appel Graph — interrompt la requête en cas d'erreur ──────────────────────
if (!function_exists('graphApi')) {
function graphApi(string $endpoint, string $accessToken): array {
    $url = 'https://graph.microsoft.com/v1.0/' . ltrim($endpoint, '/');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $resp     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) json_error('Erreur réseau Graph : ' . $curlErr, 502);
    $data = json_decode($resp, true);
    if ($httpCode === 401) json_error('Token Microsoft expiré. Reconnectez-vous.', 401);
    if ($httpCode === 403) json_error('Accès refusé. Votre compte n\'a pas les permissions Microsoft nécessaires.', 403);
    if ($httpCode >= 400)  json_error($data['error']['message'] ?? "Erreur Graph HTTP $httpCode", $httpCode);
    return $data;
}
}

// ── Appel Graph — n'interrompt PAS la requête ────────────────────────────────
//    Retourne ['status'=>int, 'data'=>array]. Permet de distinguer proprement
//    une ressource absente (404) d'une véritable erreur.
if (!function_exists('graphTry')) {
function graphTry(string $endpoint, string $accessToken): array {
    $url = 'https://graph.microsoft.com/v1.0/' . ltrim($endpoint, '/');
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $resp   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);
    if ($err) return ['status' => 0, 'data' => [], 'error' => $err];
    return ['status' => $status, 'data' => json_decode($resp, true) ?: []];
}
}

// ── POST vers Graph ─────────────────────────────────────────────────────────
if (!function_exists('graphPost')) {
function graphPost(string $endpoint, string $jsonBody, string $accessToken): array {
    $url = 'https://graph.microsoft.com/v1.0/' . ltrim($endpoint, '/');
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $jsonBody,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $resp   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($resp, true) ?: [];
    if ($status >= 400) json_error($data['error']['message'] ?? "Erreur Graph HTTP $status", $status);
    return $data;
}
}

// ── Jeton délégué : renvoie le jeton courant, rafraîchi si nécessaire ────────
//    Les scopes demandés au rafraîchissement doivent correspondre à ceux
//    accordés à la connexion (voir oauth/microsoft.php et api/routes/auth.php),
//    sans quoi Entra ID renvoie un jeton au périmètre réduit.
if (!function_exists('ensureMsToken')) {
function ensureMsToken(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $token   = $_SESSION['ms_access_token']  ?? '';
    $refresh = $_SESSION['ms_refresh_token'] ?? '';
    $expires = $_SESSION['ms_token_expires'] ?? 0;

    if (!$token) json_error('Aucun token Microsoft en session. Reconnectez-vous via Microsoft.', 401);

    // Si le token expire dans moins de 5 minutes, tenter un refresh
    if ($refresh && time() > ($expires - 300)) {
        $scopes = 'openid profile email offline_access User.Read User.ReadBasic.All';
        if (defined('PLANS_PRESENCE_ENABLED') && PLANS_PRESENCE_ENABLED) {
            $scopes .= ' Presence.Read.All Calendars.Read.Shared';
        }
        $ch = curl_init('https://login.microsoftonline.com/' . MICROSOFT_TENANT_ID . '/oauth2/v2.0/token');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'client_id'     => MICROSOFT_CLIENT_ID,
                'client_secret' => MICROSOFT_CLIENT_SECRET,
                'refresh_token' => $refresh,
                'grant_type'    => 'refresh_token',
                'scope'         => $scopes,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 200) {
            $newToken = json_decode($resp, true);
            if (!empty($newToken['access_token'])) {
                $_SESSION['ms_access_token']  = $newToken['access_token'];
                $_SESSION['ms_refresh_token'] = $newToken['refresh_token'] ?? $refresh;
                $_SESSION['ms_token_expires'] = time() + ($newToken['expires_in'] ?? 3600);
                return $newToken['access_token'];
            }
        }
    }

    return $token;
}
}

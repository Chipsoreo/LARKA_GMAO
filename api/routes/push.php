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
 * Larka — Routes Push Notifications
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Actions :
 *   - push_vapid_key   (GET)  → Retourne la clé publique VAPID (public)
 *   - push_subscribe   (POST) → Enregistre une souscription push
 *   - push_unsubscribe (POST) → Supprime une souscription push
 *   - push_test        (POST) → Envoie une notification de test à l'utilisateur courant
 *   - push_send        (POST) → Envoie une notification à des utilisateurs (Admin)
 *   - push_status      (GET)  → Statut des souscriptions de l'utilisateur courant
 *   - push_generate_vapid (POST) → Génère de nouvelles clés VAPID (Admin)
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 */

// ── Clé publique VAPID (public, pas d'auth requise) ─────────────────────────
if ($action === 'push_vapid_key' && $method === 'GET') {
    $pubKey = defined('VAPID_PUBLIC_KEY') ? VAPID_PUBLIC_KEY : '';
    if (!$pubKey) {
        json_error('Push non configuré : clé VAPID absente.');
    }
    json_ok(['publicKey' => $pubKey]);
}

// ── Souscrire aux push ──────────────────────────────────────────────────────
if ($action === 'push_subscribe' && $method === 'POST') {
    $user = require_auth();
    $body = get_body();

    $subscription = $body['subscription'] ?? null;
    if (!$subscription || empty($subscription['endpoint']) || empty($subscription['keys'])) {
        json_error('Souscription push invalide.');
    }

    $endpoint = $subscription['endpoint'];
    $p256dh   = $subscription['keys']['p256dh'] ?? '';
    $auth     = $subscription['keys']['auth']   ?? '';
    $platform = $body['platform']  ?? 'unknown';
    $ua       = $body['userAgent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');

    if (!$endpoint || !$p256dh || !$auth) {
        json_error('Clés de souscription manquantes (p256dh, auth).');
    }

    $db->savePushSubscription(
        (int) $user['Id'],
        $endpoint,
        $p256dh,
        $auth,
        $platform,
        $ua
    );

    json_ok(['subscribed' => true]);
}

// ── Se désabonner ───────────────────────────────────────────────────────────
if ($action === 'push_unsubscribe' && $method === 'POST') {
    $user = require_auth();
    $body = get_body();
    $endpoint = $body['endpoint'] ?? '';

    // ⚠️ FIX SÉCURITÉ : on filtre par UserId pour empêcher qu'un utilisateur
    // authentifié désabonne les notifications d'un autre utilisateur en
    // devinant ou en interceptant un endpoint push.
    if ($endpoint) {
        $db->deletePushSubscriptionForUser($endpoint, (int)$user['Id']);
    }

    json_ok(['unsubscribed' => true]);
}

// ── Statut push de l'utilisateur courant ─────────────────────────────────────
if ($action === 'push_status' && $method === 'GET') {
    $user = require_auth();
    $subs = $db->getPushSubscriptionsForUser((int) $user['Id']);
    json_ok([
        'count'     => count($subs),
        'endpoints' => array_map(fn($s) => [
            'platform'  => $s['Platform']  ?? 'unknown',
            'createdAt' => $s['CreatedAt'] ?? '',
        ], $subs),
    ]);
}

// ── Notification de test ─────────────────────────────────────────────────────
if ($action === 'push_test' && $method === 'POST') {
    $user = require_auth();

    if (!defined('VAPID_PRIVATE_PEM') || !VAPID_PRIVATE_PEM || !defined('VAPID_PUBLIC_KEY') || !VAPID_PUBLIC_KEY) {
        json_error('Push non configuré : clés VAPID absentes. Allez dans Configuration → Notifications Push.');
    }

    require_once __DIR__ . '/../WebPush.php';

    $body = get_body();
    $mode = $body['mode'] ?? 'me'; // 'me' = user courant, 'all' = tous, 'roles' = par rôles configurés

    if ($mode === 'all') {
        require_role($user, ['Admin', 'Gestionnaire']);
        $subs = $db->getAllPushSubscriptions();
    } elseif ($mode === 'roles') {
        require_role($user, ['Admin', 'Gestionnaire']);
        $roles = defined('PUSH_NOTIF_DEMANDE_ROLES') ? PUSH_NOTIF_DEMANDE_ROLES : ['Admin', 'Gestionnaire'];
        $subs = $db->getAllPushSubscriptions(null, $roles);
    } else {
        $subs = $db->getPushSubscriptionsForUser((int) $user['Id']);
    }

    if (empty($subs)) {
        $detail = $mode === 'me'
            ? 'Aucun appareil enregistré pour votre compte. Acceptez les notifications sur ce navigateur d\'abord.'
            : 'Aucun appareil enregistré pour les rôles ciblés. Les utilisateurs doivent d\'abord accepter les notifications.';
        json_error($detail);
    }

    $wp = new WebPush(VAPID_PRIVATE_PEM, VAPID_PUBLIC_KEY, VAPID_SUBJECT);

    $payload = json_encode([
        'title'   => '🔔 Test Larka',
        'body'    => 'Les notifications fonctionnent ! — ' . date('H:i'),
        'tag'     => 'gmao-test-' . time(),
        'icon'    => defined('PUSH_NOTIF_DEMANDE_ICON') ? PUSH_NOTIF_DEMANDE_ICON : '/apple-touch-icon.png',
        'data'    => ['page' => 'dashboard'],
    ], JSON_UNESCAPED_UNICODE);

    $sent = 0;
    $failed = 0;
    $errors = [];
    $cleaned = 0;
    $details = []; // Détails par appareil pour le diagnostic

    // ⚠️ FIX PERFS : envoi en parallèle via curl_multi.
    // Sur un test à 50 abonnés, on passe d'un cumul de N×timeout à un seul
    // timeout. Voir WebPush::sendBatch().
    $batchSubs = array_map(fn($s) => [
        'endpoint' => $s['Endpoint'],
        'keys'     => ['p256dh' => $s['P256dh'], 'auth' => $s['Auth']],
    ], $subs);

    $batchResults = $wp->sendBatch($batchSubs, $payload);

    foreach ($subs as $i => $sub) {
        $result = $batchResults[$i] ?? ['success' => false, 'statusCode' => 0, 'reason' => 'Pas de réponse'];

        $details[] = [
            'platform'   => $sub['Platform'] ?? 'unknown',
            'userId'     => $sub['UserId'] ?? '?',
            'statusCode' => $result['statusCode'],
            'success'    => $result['success'],
            'reason'     => $result['reason'],
            'endpoint'   => substr($sub['Endpoint'], 0, 80) . '…',
            'createdAt'  => $sub['CreatedAt'] ?? '',
        ];

        if ($result['success']) {
            $sent++;
        } else {
            $failed++;
            $errors[] = ($sub['Platform'] ?? '?') . ': ' . $result['reason'];
            if (in_array($result['statusCode'], [404, 410])) {
                $db->deletePushSubscription($sub['Endpoint']);
                $cleaned++;
            }
        }
    }

    if ($sent > 0) {
        json_ok(['sent' => $sent, 'failed' => $failed, 'total' => count($subs), 'cleaned' => $cleaned, 'details' => $details]);
    } else {
        json_error('Échec d\'envoi sur ' . count($subs) . ' appareil(s) : ' . implode(' | ', array_slice($errors, 0, 3)));
    }
}

// ── Liste des souscriptions (Admin) ─────────────────────────────────────────
if ($action === 'push_subscriptions' && $method === 'GET') {
    $user = require_auth();
    require_role($user, ['Admin','Gestionnaire']);

    $subs = $db->getAllPushSubscriptions();
    $list = [];
    foreach ($subs as $sub) {
        // Récupérer le nom de l'utilisateur
        $u = $db->fetchOne("SELECT Login, Nom, Prenom, Role FROM Utilisateurs WHERE Id = :id", ['id' => $sub['UserId']]);
        $list[] = [
            'userId'    => $sub['UserId'],
            'login'     => $u['Login'] ?? '?',
            'nom'       => trim(($u['Prenom'] ?? '') . ' ' . ($u['Nom'] ?? '')) ?: ($u['Login'] ?? '?'),
            'role'      => $u['Role'] ?? '?',
            'platform'  => $sub['Platform'] ?? 'unknown',
            'createdAt' => $sub['CreatedAt'] ?? '',
            'lastUsed'  => $sub['LastUsedAt'] ?? '',
            'endpoint'  => substr($sub['Endpoint'], 0, 60) . '…',
        ];
    }

    json_ok(['subscriptions' => $list, 'total' => count($list)]);
}

// ── Envoi de notification (Admin/Système) ────────────────────────────────────
if ($action === 'push_send' && $method === 'POST') {
    $user = require_auth();
    require_role($user, ['Admin', 'Gestionnaire']);

    if (!defined('VAPID_PRIVATE_PEM') || !VAPID_PRIVATE_PEM) {
        json_error('Push non configuré.');
    }

    require_once __DIR__ . '/../WebPush.php';

    $body    = get_body();
    $title   = $body['title']   ?? 'Larka';
    $message = $body['body']    ?? '';
    $page    = $body['page']    ?? '';
    $userIds = $body['userIds'] ?? null; // null = tous, array = spécifiques
    $roles   = $body['roles']   ?? null; // null = tous, array = rôles spécifiques

    $notifData = ['page' => $page ?: ''];
    $payload = json_encode([
        'title' => $title,
        'body'  => $message,
        'tag'   => 'gmao-' . time(),
        'icon'  => '/apple-touch-icon.png',
        'data'  => $notifData,
    ], JSON_UNESCAPED_UNICODE);

    $subs = $db->getAllPushSubscriptions($userIds, $roles);

    $wp = new WebPush(VAPID_PRIVATE_PEM, VAPID_PUBLIC_KEY, VAPID_SUBJECT);

    $sent = 0;
    $failed = 0;

    // ⚠️ FIX PERFS : envoi parallèle via curl_multi (cf. WebPush::sendBatch)
    $batchSubs = array_map(fn($s) => [
        'endpoint' => $s['Endpoint'],
        'keys'     => ['p256dh' => $s['P256dh'], 'auth' => $s['Auth']],
    ], $subs);
    $batchResults = $wp->sendBatch($batchSubs, $payload);

    foreach ($subs as $i => $sub) {
        $result = $batchResults[$i] ?? ['success' => false, 'statusCode' => 0];

        if ($result['success']) {
            $sent++;
        } else {
            $failed++;
            if (in_array($result['statusCode'], [404, 410])) {
                $db->deletePushSubscription($sub['Endpoint']);
            }
        }
    }

    json_ok(['sent' => $sent, 'failed' => $failed, 'total' => count($subs)]);
}

// ── Générer des clés VAPID (Admin, première config) ──────────────────────────
if ($action === 'push_generate_vapid' && $method === 'POST') {
    $user = require_auth();
    require_role($user, ['Admin','Gestionnaire']);

    require_once __DIR__ . '/../WebPush.php';
    require_once __DIR__ . '/../EnvFile.php';

    $keys = WebPush::generateVapidKeys();

    // La clé PRIVÉE est un secret → fichier .env (jamais config.json).
    // La clé PUBLIQUE et le sujet ne sont pas sensibles → config.json.
    try {
        EnvFile::set(['GMAO_VAPID_PRIVATE_KEY' => $keys['privateKeyPem']]);
    } catch (\Throwable $e) {
        json_error("Impossible d'enregistrer la clé privée VAPID dans .env : " . $e->getMessage()
            . " Vérifiez les droits du dossier (fichier .env inscriptible par le serveur web).", 500);
    }

    $cfgFile = __DIR__ . '/../../config.json';
    $cfgData = json_decode(file_get_contents($cfgFile), true) ?? [];
    if (!isset($cfgData['push']) || !is_array($cfgData['push'])) $cfgData['push'] = [];
    $cfgData['push']['vapid_public_key']  = $keys['publicKey'];
    $cfgData['push']['vapid_private_pem'] = ''; // déplacé dans .env
    if (empty($cfgData['push']['vapid_subject'])) {
        $cfgData['push']['vapid_subject'] = 'mailto:admin@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
    file_put_contents($cfgFile, json_encode($cfgData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    // Supprimer les anciennes souscriptions (elles sont liées aux anciennes clés)
    try {
        $db->execute("DELETE FROM PushSubscriptions WHERE 1=1", []);
    } catch (\Throwable $_) {}

    json_ok([
        'publicKey' => $keys['publicKey'],
        'message'   => 'Clés VAPID générées. La clé privée est stockée dans .env, la clé publique dans config.json. Les anciennes souscriptions ont été supprimées — les utilisateurs devront se réabonner.',
    ]);
}

// ── Configuration push (Admin) ─────────────────────────────────────────────
if ($action === 'push_config' && $method === 'GET') {
    $user = require_auth();
    require_role($user, ['Admin','Gestionnaire']);
    json_ok([
        'actif'                         => defined('PUSH_ACTIF') ? PUSH_ACTIF : true,
        'notif_demande_titre'           => defined('PUSH_NOTIF_DEMANDE_TITRE') ? PUSH_NOTIF_DEMANDE_TITRE : '📝 Nouvelle demande d\'intervention',
        'notif_demande_corps'           => defined('PUSH_NOTIF_DEMANDE_CORPS') ? PUSH_NOTIF_DEMANDE_CORPS : '{demandeur} a soumis une demande d\'intervention.',
        'notif_demande_icon'            => defined('PUSH_NOTIF_DEMANDE_ICON') ? PUSH_NOTIF_DEMANDE_ICON : '/apple-touch-icon.png',
        'notif_demande_image'           => defined('PUSH_NOTIF_DEMANDE_IMAGE') ? PUSH_NOTIF_DEMANDE_IMAGE : '',
        'notif_demande_roles'           => defined('PUSH_NOTIF_DEMANDE_ROLES') ? PUSH_NOTIF_DEMANDE_ROLES : ['Admin', 'Gestionnaire'],
        'notif_demande_require_interaction' => defined('PUSH_NOTIF_DEMANDE_REQUIRE_INTERACTION') ? PUSH_NOTIF_DEMANDE_REQUIRE_INTERACTION : false,
        'vapid_configured'              => !empty(VAPID_PUBLIC_KEY) && !empty(VAPID_PRIVATE_PEM),
        'subscriptions_count'           => count($db->getAllPushSubscriptions()),
    ]);
}

// ── Diagnostic VAPID (Admin) ─────────────────────────────────────────────────
if ($action === 'push_diagnose' && $method === 'GET') {
    $user = require_auth();
    require_role($user, ['Admin','Gestionnaire']);

    require_once __DIR__ . '/../WebPush.php';

    if (!defined('VAPID_PRIVATE_PEM') || !VAPID_PRIVATE_PEM) {
        json_ok([
            'ok'     => false,
            'errors' => ['Aucune clé VAPID configurée. Utilisez push_generate_vapid pour en générer.'],
            'info'   => ['vapid_public_key' => VAPID_PUBLIC_KEY ?: '(vide)', 'vapid_pem_length' => 0],
        ]);
    }

    $wp = new WebPush(VAPID_PRIVATE_PEM, VAPID_PUBLIC_KEY, VAPID_SUBJECT);

    // Récupérer une souscription réelle pour tester avec la vraie audience
    $subs = $db->getAllPushSubscriptions();
    $firstSub = !empty($subs) ? $subs[0] : null;
    $diag = $wp->diagnose($firstSub);

    // Ajouter des infos supplémentaires
    $diag['info']['subscriptions_count'] = count($db->getAllPushSubscriptions());
    $diag['info']['php_version'] = PHP_VERSION;
    $diag['info']['openssl_version'] = OPENSSL_VERSION_TEXT ?? 'unknown';

    json_ok($diag);
}

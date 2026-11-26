<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka — Route du PC releveur (Larka Connect, appareils en hotspot Wi-Fi).
 *
 *   GET  connect_releveur&op=mission   ce qu'il faut relever
 *   POST connect_releveur  {op: depot, releves: […], journal: […]}
 *   POST connect_releveur  {op: reconnaissance, connexion, rapport}   ce que l'appareil expose
 *
 * Pas de session : le PC présente son jeton dans l'en-tête X-Larka-Releveur
 * (ou Authorization: Bearer). Le jeton désigne l'organisation, sa base est
 * ouverte ici (api/connect/Releveur.php). Échecs limités par adresse.
 */

if ($action === 'connect_releveur') {
    require_once __DIR__ . '/../connect/Connect.php';
    Connect::charger();
    $jeton = trim((string)($_SERVER['HTTP_X_LARKA_RELEVEUR'] ?? ''));
    if ($jeton === '' && preg_match('/^Bearer\s+(\S+)$/i', (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '')), $m)) $jeton = $m[1];
    if ($jeton === '') json_error('Jeton du PC releveur manquant.', 401);
    // Un jeton faux coûte : 20 refus par quart d'heure et par adresse. La limite est
    // vérifiée AVANT l'essai (sinon un essai juste passerait même limite atteinte).
    $cleRefus = 'releveur_refus_' . client_ip();
    check_rate_limit($cleRefus, 20, 900, false);
    [$ctx, $erreur] = ConnectReleveur::authentifier($jeton);
    if ($ctx === null) {
        rate_limit_noter($cleRefus);
        if (class_exists('SecurityLog')) SecurityLog::audit('connect_releveur_refus', ['reason' => $erreur]);
        json_error($erreur, 403);
    }
    [$rdb, $rdepot, $releveur, $rorg] = $ctx;
    // Le journal écrit dans la base de l'organisation du PC : ses dépôts, pannes et
    // rétablissements y sont consultables (Connexions → Journal), comme le reste.
    if (class_exists('Journal')) { try { Journal::attacherBase($rdb->getPdo()); } catch (\Throwable $e) {} }
    // Jeton valide : un PC fait quelques appels par relevé (mission, puis lots de 400).
    // Un jeton volé ne doit pas pouvoir noyer l'historique.
    check_rate_limit('releveur_appels_' . $rorg . '_' . (int)$releveur['Id'], 60, 900);
    $op = (string)($_GET['op'] ?? '');
    if ($method === 'GET' && $op === 'mission') {
        $rdepot->noterContactReleveur((int)$releveur['Id'], $releveur['DerniereErreur'] ?? null, json_decode((string)($releveur['Journal'] ?? '[]'), true) ?: []);
        try { $rdepot->noterVersionReleveur((int)$releveur['Id'], (string)($_SERVER['HTTP_USER_AGENT'] ?? '')); } catch (\Throwable $e) { /* base pas encore en v20 */ }
        json_ok(ConnectReleveur::mission($rdb, $rdepot, $releveur, $rorg));
    }
    if ($method === 'POST') {
        $corps = get_body();
        if (($corps['op'] ?? '') === 'depot') json_ok(ConnectReleveur::deposer($rdb, $rdepot, $releveur, $rorg, $corps));
        if (($corps['op'] ?? '') === 'reconnaissance') json_ok(ConnectReleveur::recevoirReconnaissance($rdb, $rdepot, $releveur, $corps));
    }
    json_error('Opération inconnue.', 400);
}

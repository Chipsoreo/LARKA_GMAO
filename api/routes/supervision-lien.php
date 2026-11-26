<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka — Routes du lien chiffré entre serveur principal et serveur de supervision.
 *
 * SANS SESSION : l'authentification, c'est la cryptographie (api/SupervisionLien.php).
 * Un message qui ne s'ouvre pas avec la clé épinglée à l'appairage est refusé ;
 * les échecs sont comptés par adresse (30 par 5 minutes) pour qu'un inconnu ne
 * puisse pas marteler le point d'entrée.
 *
 *   supervision_appairer      (les deux)       présentation d'un code d'appairage
 *   supervision_lien          (supervision)    requête du principal (port ouvert, ou via le relais local)
 *   supervision_pont          (principal)      le relais attend des requêtes (20 s)
 *   supervision_pont_reponse  (principal)      le relais rapporte les réponses
 */

require_once __DIR__ . '/../connect/Connect.php';
require_once __DIR__ . '/../SupervisionLien.php';
Connect::charger();

if ($method !== 'POST') json_error('Méthode non supportée.', 405);
// Pas de session pour un serveur : rien n'est écrit (pas de fichier de session par message).
if (session_status() === PHP_SESSION_ACTIVE) session_abort();

$slIp = client_ip();
$slEchec = function (string $message, int $code = 403) use ($slIp): void {
    rate_limit_noter('supervision_lien_' . $slIp);
    SecurityLog::audit('supervision_lien_refus', ['ip' => $slIp, 'reason' => mb_substr($message, 0, 120)]);
    json_error($message, $code);
};
check_rate_limit('supervision_lien_' . $slIp, 30, 300, false);

/** Base de l'organisation désignée (multi-organisation) ; la base par défaut sinon. */
$slBase = function (?string $cle): Database {
    $cle = (string)$cle;
    if (class_exists('TenantResolver') && TenantResolver::isMultiTenant()) {
        foreach (Connect::organisations() as $o) {
            if ($o['cle'] === $cle) return new Database($o['base']);
        }
        throw new RuntimeException('Organisation inconnue.');
    }
    return new Database();
};

$slCorps = get_body();

// ── Appairage : l'autre serveur présente notre code ───────────────────────
if ($action === 'supervision_appairer') {
    if (!SupervisionLien::disponible()) json_error('Extension PHP sodium absente sur ce serveur.', 500);
    try {
        $db = $slBase(is_string($slCorps['cible'] ?? null) ? $slCorps['cible'] : 'default');
        $env = SupervisionLien::recevoirAppairage($db, $slCorps);
    } catch (\Throwable $e) {
        $slEchec('Appairage refusé : ' . $e->getMessage());
    }
    json_ok(['e' => $env]);
}

// ── Requête du principal (côté serveur de supervision) ────────────────────
if ($action === 'supervision_lien') {
    try {
        $db = new Database();
        $l = SupervisionLien::lire($db);
        if ($l['Role'] !== 'supervision') throw new RuntimeException('Ce serveur n\'est pas un serveur de supervision.');
        [$type, $d, $id] = SupervisionLien::ouvrir($db, $slCorps);
        if ($type !== 'requete' || !is_array($d)) throw new RuntimeException('Message inattendu.');
    } catch (\Throwable $e) {
        $slEchec($e->getMessage());
    }
    $op = (string)($d['op'] ?? '');
    if ($op === 'api') {
        try { SupervisionLien::executerApi($db, (array)($d['donnees'] ?? []), $id); }   // ne revient pas
        catch (\Throwable $e) { $res = ['ok' => false, 'erreur' => $e->getMessage()]; }
    } else {
        try {
            $res = ['ok' => true, 'donnees' => SupervisionLien::traiter($db, $op, (array)($d['donnees'] ?? []))];
        } catch (\Throwable $e) {
            Journal::erreur('connect', 'Serveur de supervision : ' . $op, ['exception' => $e]);
            $res = ['ok' => false, 'erreur' => $e->getMessage()];
        }
    }
    $res['version'] = SupervisionLien::VERSION;
    $env = SupervisionLien::sceller($db, 'reponse', $res, $id);
    unset($env['id']);
    json_ok(['e' => $env]);
}

// ── Pont sortant (côté principal) ─────────────────────────────────────────
if ($action === 'supervision_pont' || $action === 'supervision_pont_reponse') {
    try {
        $db = $slBase(is_string($slCorps['o'] ?? null) ? $slCorps['o'] : 'default');
        $l = SupervisionLien::lire($db);
        if ($l['Role'] !== 'principal' || $l['Mode'] !== 'pont') throw new RuntimeException('Aucun pont sortant attendu ici.');
        if ($action === 'supervision_pont') json_ok(['e' => SupervisionLien::pontAttendre($db, $slCorps)]);
        json_ok(['repondues' => SupervisionLien::pontRepondre($db, $slCorps)]);
    } catch (\Throwable $e) {
        $slEchec($e->getMessage());
    }
}

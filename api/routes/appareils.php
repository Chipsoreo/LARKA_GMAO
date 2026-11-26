<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka — Routes : appareils mobiles reliés au serveur.
 *
 * Chacun relie SES appareils depuis « Mon profil » : l'appareil est toujours relié au compte
 * connecté (aucun choix de compte), l'acceptation redemande le mot de passe de ce compte, et
 * seul celui qui a créé le code peut l'accepter. Les gestionnaires voient et délient tous les
 * appareils (appareil perdu, départ d'un agent), sans pouvoir en relier un au compte d'un autre.
 *
 *   appareils                   GET   mes appareils ; &tous=1 : tous (gestionnaires)
 *   appareils_app               GET   app mobile proposée par ce serveur (telechargements/larka.json) ou null
 *   appareils_revoquer          POST  id → l'appareil ne peut plus se synchroniser
 *   appareils_invitation        POST  → code d'appairage (QR) pour MON compte, adresses du serveur
 *   appareils_invitation_etat   GET   id → où en est l'appairage (code de vérification quand l'appareil s'est présenté)
 *   appareils_invitation_valider POST id, accepter, mot_de_passe
 *   appareils_invitation_retirer POST id
 */

require_once __DIR__ . '/../Synchro.php';

if (in_array($action, ['appareils', 'appareils_app', 'appareils_revoquer', 'appareils_invitation', 'appareils_invitation_etat', 'appareils_invitation_valider', 'appareils_invitation_retirer'], true)) {
    $user = require_auth();
    $moi = (int)$user['Id'];
    $gerant = in_array((string)($user['Role'] ?? ''), ['Admin', 'Gestionnaire'], true);
    $b = $method === 'POST' ? get_body() : [];
    // Une invitation n'est visible et pilotable que par celui qui l'a créée.
    $miennne = static function (int $id) use ($db, $moi): array {
        $e = Synchro::invitationEtat($db, $id);
        if (!$e || (int)$e['utilisateur'] !== $moi) json_error('Invitation introuvable.', 404);
        return $e;
    };

    if ($action === 'appareils_app' && $method === 'GET') {
        $app = Synchro::appMobile();
        // Gestionnaires : où en est la vérification GitHub (dernier passage, version trouvée, erreur).
        json_ok($app === null && !$gerant ? null : ($app ?? []) + ($gerant ? ['github' => Synchro::etatGithubPublic()] : []));
    }

    if ($action === 'appareils' && $method === 'GET') {
        $tous = !empty($_GET['tous']);
        if ($tous && !$gerant) json_error('Accès refusé.', 403);
        $l = Synchro::appareils($db);
        json_ok($tous ? $l : array_values(array_filter($l, fn($a) => (int)$a['utilisateur'] === $moi)));
    }

    if ($action === 'appareils_revoquer' && $method === 'POST') {
        $id = (int)($b['id'] ?? 0);
        $a = null;
        foreach (Synchro::appareils($db) as $x) if ($x['id'] === $id) $a = $x;
        if (!$a || (!$gerant && (int)$a['utilisateur'] !== $moi)) json_error('Appareil introuvable.', 404);
        Synchro::revoquer($db, $id);
        Journal::log(Journal::AUDIT, 'synchro', 'Appareil délié depuis le serveur : ' . $a['nom'] . ' (compte ' . $a['login'] . ')', ['action' => 'SYNCHRO_DELIER', 'login' => $user['Login']]);
        json_ok(['revoque' => true]);
    }

    if ($action === 'appareils_invitation' && $method === 'POST') {
        json_ok(Synchro::invitationCreer($db, $user, $moi) + ['adresses' => Synchro::adressesReseau()]);
    }

    if ($action === 'appareils_invitation_etat' && $method === 'GET') {
        json_ok($miennne((int)($_GET['id'] ?? 0)));
    }

    if ($action === 'appareils_invitation_valider' && $method === 'POST') {
        $id = (int)($b['id'] ?? 0);
        $miennne($id);
        $accepter = !empty($b['accepter']);
        if ($accepter) {
            // Le mot de passe du compte, redemandé : une session laissée ouverte ne suffit pas.
            $u = $db->fetchOne('SELECT * FROM Utilisateurs WHERE Id = :id', ['id' => $moi]);
            $local = strtolower((string)($u['Provider'] ?? 'local')) === 'local' || (string)($u['Provider'] ?? '') === '';
            if ($local) {
                check_rate_limit('appairage_mdp_' . $moi, 5, 600, false);
                if (!$u || !password_verify((string)($b['mot_de_passe'] ?? ''), (string)($u['MotDePasse'] ?? ''))) {
                    rate_limit_noter('appairage_mdp_' . $moi);
                    json_error('Mot de passe incorrect.', 403);
                }
            }
        }
        $err = Synchro::invitationValider($db, $id, $accepter, $moi);
        if ($err) json_error($err, 400);
        Journal::log(Journal::AUDIT, 'synchro', ($accepter ? 'Appairage accepté' : 'Appairage refusé') . ' par ' . $user['Login'] . ' (invitation n° ' . $id . ')',
            ['action' => 'SYNCHRO_APPAIRAGE', 'login' => $user['Login']]);
        json_ok(Synchro::invitationEtat($db, $id));
    }

    if ($action === 'appareils_invitation_retirer' && $method === 'POST') {
        $id = (int)($b['id'] ?? 0);
        $miennne($id);
        Synchro::invitationRetirer($db, $id);
        json_ok(['retiree' => true]);
    }

    json_error('Méthode non supportée', 405);
}

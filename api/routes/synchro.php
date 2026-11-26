<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka — Routes : synchronisation avec l'app mobile (voir api/Synchro.php).
 *
 *   synchro_lier            POST  identifiant + mot de passe → jeton de l'appareil
 *   (les suivantes : en-tête « Authorization: Bearer <jeton> », sans session)
 *   synchro_etat            GET   dernier numéro de modification, compte, appareil
 *   synchro_tables          GET   tables, clés, colonnes, nombre de lignes
 *   synchro_cliche          GET   une page d'une table (copie complète)
 *   synchro_empreintes      POST  empreintes de toutes les lignes d'une table
 *   synchro_lignes          POST  lignes demandées par clé
 *   synchro_tirer           GET   changements depuis un numéro
 *   synchro_pousser         POST  modifications de l'app (conflits rendus)
 *   synchro_reserver        POST  plages d'identifiants pour les fiches créées hors ligne
 *   synchro_fichiers        GET   inventaire des fichiers (plans, médias, thèmes, modules)
 *   synchro_fichier         GET   un fichier
 *   synchro_fichier_envoyer POST  un fichier (par morceaux, en base64)
 *   synchro_fichier_supprimer POST
 *   synchro_delier          POST  l'appareil rend son jeton
 *
 *   Appairage par QR code (sans session ni jeton ; voir aussi routes/appareils.php) :
 *   synchro_appairer        POST  code de l'invitation → code de vérification, secret de la demande
 *   synchro_appairage_confirmer POST l'appareil confirme le code de vérification
 *   synchro_appairage_attendre  POST décision du gestionnaire ; jeton une fois les deux confirmations faites
 */

require_once __DIR__ . '/../Synchro.php';

if ($action === 'synchro_lier') {
    if ($method !== 'POST') json_error('Méthode non supportée', 405);
    $b = get_body();
    $login = trim((string)($b['login'] ?? ''));
    $password = (string)($b['password'] ?? '');
    $ip = client_ip();
    check_rate_limit('login_' . $ip, 10, 300, false);
    if ($login !== '') check_rate_limit('login_user_' . strtolower($login), 20, 900, false);
    $echec = static function () use ($ip, $login): void {
        rate_limit_noter('login_' . $ip);
        if ($login !== '') rate_limit_noter('login_user_' . strtolower($login));
    };
    $org = 'default';
    $sdb = null;
    $res = tenant_du_compte($login);
    if ($res['erreur']) { $echec(); json_error($res['erreur'], $res['code']); }
    if ($res['cle']) {
        $org = (string)$res['cle'];
        $sdb = base_du_tenant($org);
        if (!$sdb) json_error('Configuration de l\'organisation indisponible.', 503);
    }
    $sdb ??= new Database();
    $user = $sdb->login($login, $password);
    if (!$user) {
        $echec();
        if (class_exists('SecurityLog')) SecurityLog::loginFailure($login);
        json_error('Identifiant ou mot de passe incorrect.', 401);
    }
    $r = Synchro::relier($sdb, $org, $user, trim((string)($b['nom'] ?? '')), (string)($b['version'] ?? ''));
    Journal::log(Journal::AUDIT, 'synchro', 'Appareil relié : ' . trim((string)($b['nom'] ?? '')) . ' (n° ' . $r['appareil']['numero'] . ') par ' . $login,
        ['action' => 'SYNCHRO_LIER', 'login' => $user['Login'], 'role' => $user['Role'] ?? '']);
    require_once __DIR__ . '/../Version.php';
    unset($user['MotDePasse']);
    json_ok($r + [
        'serveur' => ['version' => LarkaVersion::libelle(LarkaVersion::lire()), 'base' => $sdb->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME), 'organisation' => $org],
        'utilisateur' => $user,
        'seq' => Synchro::seq($sdb),
    ]);
}

// ── Appairage par QR code ────────────────────────────────────────────────────
if (in_array($action, ['synchro_appairer', 'synchro_appairage_confirmer', 'synchro_appairage_attendre'], true)) {
    if ($method !== 'POST') json_error('Méthode non supportée', 405);
    $b = get_body();
    $ip = client_ip();
    try {
        if ($action === 'synchro_appairer') {
            // Codes de 8 caractères (31^8) valables 15 minutes, et 10 essais par adresse en 10 minutes.
            check_rate_limit('appairage_' . $ip, 10, 600, false);
            try {
                $r = Synchro::appairageDemander((string)($b['code'] ?? ''), trim((string)($b['nom'] ?? '')), (string)($b['version'] ?? ''));
            } catch (\RuntimeException $e) { rate_limit_noter('appairage_' . $ip); json_error($e->getMessage(), 400); }
            require_once __DIR__ . '/../Version.php';
            json_ok($r + ['serveur' => ['version' => LarkaVersion::libelle(LarkaVersion::lire())]]);
        }
        check_rate_limit('appairage_attente_' . $ip, 600, 600);
        $ref = (string)($b['demande'] ?? ''); $secret = (string)($b['secret'] ?? '');
        if ($action === 'synchro_appairage_confirmer') { Synchro::appairageConfirmer($ref, $secret); json_ok(['confirme' => true]); }
        $r = Synchro::appairageAttendre($ref, $secret, (string)($b['version'] ?? ''));
        if ($r['etat'] === 'acceptee') {
            require_once __DIR__ . '/../Version.php';
            $l = $r['lien'];
            Journal::log(Journal::AUDIT, 'synchro', 'Appareil relié par QR code (n° ' . $l['appareil']['numero'] . ') au compte ' . ($l['utilisateur']['Login'] ?? ''),
                ['action' => 'SYNCHRO_LIER', 'login' => $l['utilisateur']['Login'] ?? '']);
            $r['lien']['serveur'] = ['version' => LarkaVersion::libelle(LarkaVersion::lire()), 'organisation' => $l['organisation']];
        }
        json_ok($r);
    } catch (\RuntimeException $e) { json_error($e->getMessage(), 404); }
}

// ── Routes à jeton ───────────────────────────────────────────────────────────
if (str_starts_with($action, 'synchro_')) {
    $jeton = '';
    if (preg_match('/^Bearer\s+(\S+)$/i', (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '')), $mj)) $jeton = $mj[1];
    if ($jeton === '') $jeton = trim((string)($_SERVER['HTTP_X_LARKA_SYNCHRO'] ?? ''));
    if ($jeton === '') json_error('Jeton de l\'appareil manquant.', 401);
    $cleRefus = 'synchro_refus_' . client_ip();
    check_rate_limit($cleRefus, 20, 900, false);
    [$ctx, $erreur] = Synchro::authentifier($jeton);
    if ($ctx === null) {
        rate_limit_noter($cleRefus);
        if (class_exists('SecurityLog')) SecurityLog::audit('synchro_refus', ['reason' => $erreur]);
        json_error($erreur, 403);
    }
    [$sdb, $appareil, $suser, $sorg] = $ctx;
    // L'ORGANISATION DES FICHIERS ÉTAIT CELLE DE L'ADRESSE, PAS CELLE DU JETON.
    // Sans session, config.php résout l'organisation d'après le nom d'hôte — à défaut,
    // « default » ou la PREMIÈRE organisation active. En multi-tenant, un appareil de
    // l'organisation B listait, téléchargeait — et, pour un gestionnaire, écrasait ou
    // supprimait — les plans, médias d'urgence, thèmes et modules d'une autre
    // organisation (data/<dossier>/tenants/<clé>, voir ExtPaquet::sousCheminTenant).
    // La base venait bien du jeton ; les dossiers, eux, doivent en venir aussi.
    if (class_exists('TenantResolver') && TenantResolver::isMultiTenant()) $GLOBALS['_currentTenantKey'] = (string)$sorg;
    // Une copie complète enchaîne beaucoup d'appels : limite par appareil, pas par adresse IP
    // (tous les téléphones d'un même réseau d'entreprise partagent souvent la même).
    check_rate_limit('synchro_appareil_' . $sorg . '_' . (int)$appareil['Id'], 1200, 60);
    Journal::attacherBase($sdb->getPdo());
    $gerant = in_array((string)($suser['Role'] ?? ''), ['Admin', 'Gestionnaire'], true);
    try { Synchro::purger($sdb); } catch (\Throwable $e) { /* purge best-effort */ }

    if ($action === 'synchro_etat' && $method === 'GET') {
        json_ok(['seq' => Synchro::seq($sdb), 'appareil' => ['id' => (int)$appareil['Id'], 'numero' => (int)$appareil['Numero'], 'nom' => $appareil['Nom']],
                 'utilisateur' => ['Id' => (int)$suser['Id'], 'Login' => $suser['Login'], 'Role' => $suser['Role'] ?? ''], 'texte_numerique' => Synchro::texteNumerique($sdb)]);
    }

    if ($action === 'synchro_tables' && $method === 'GET') {
        $out = [];
        // Nombre de lignes que CE compte peut lire (le total de la table en disait déjà trop).
        foreach (Synchro::tables($sdb) as $t => $d) $out[$t] = $d + ['lignes' => Synchro::compter($sdb, $t, $suser)];
        json_ok(['tables' => $out, 'seq' => Synchro::seq($sdb), 'texte_numerique' => Synchro::texteNumerique($sdb)]);
    }

    if ($action === 'synchro_cliche' && $method === 'GET') {
        try { json_ok(Synchro::cliche($sdb, (string)($_GET['table'] ?? ''), $_GET['apres'] ?? null, (int)($_GET['limite'] ?? 500), $suser)); }
        catch (\RuntimeException $e) { json_error($e->getMessage(), 404); }
    }

    if ($action === 'synchro_empreintes' && $method === 'POST') {
        $b = get_body();
        json_ok(Synchro::empreintes($sdb, (string)($b['table'] ?? ''), array_values(array_filter((array)($b['colonnes'] ?? []), 'is_string')), $suser));
    }

    if ($action === 'synchro_lignes' && $method === 'POST') {
        $b = get_body();
        $cles = array_values(array_filter((array)($b['cles'] ?? []), fn($c) => is_scalar($c)));
        if (count($cles) > 5000) json_error('Trop de lignes demandées d\'un coup (5000 au plus).', 400);
        json_ok(['lignes' => Synchro::lignes($sdb, (string)($b['table'] ?? ''), $cles, $suser)]);
    }

    if ($action === 'synchro_tirer' && $method === 'GET') {
        json_ok(Synchro::tirer($sdb, (int)($_GET['depuis'] ?? 0), (int)($_GET['limite'] ?? 1000), $suser));
    }

    if ($action === 'synchro_pousser' && $method === 'POST') {
        $b = get_body();
        $modifs = array_values(array_filter((array)($b['modifs'] ?? []), 'is_array'));
        if (count($modifs) > 2000) json_error('Trop de modifications d\'un coup (2000 au plus).', 400);
        $r = Synchro::pousser($sdb, $appareil, $suser, $modifs);
        if ($r['appliquees'] || $r['conflits']) {
            Journal::log(Journal::AUDIT, 'synchro', 'Synchronisation de « ' . $appareil['Nom'] . ' » : ' . count($r['appliquees']) . ' modification(s) appliquée(s), '
                . count($r['conflits']) . ' conflit(s), ' . count($r['refus']) . ' refus', ['action' => 'SYNCHRO_POUSSER', 'login' => $suser['Login'], 'role' => $suser['Role'] ?? '']);
        }
        json_ok($r + ['seq' => Synchro::seq($sdb)]);
    }

    if ($action === 'synchro_reserver' && $method === 'POST') {
        $b = get_body();
        $out = [];
        foreach ((array)($b['tables'] ?? []) as $t => $n) {
            if (!is_string($t) || count($out) >= 200) continue;
            if (!Synchro::peutCreer($suser, $t)) { $out[$t] = null; continue; }   // pas de fiche à créer là : pas de plage
            try { $out[$t] = Synchro::reserver($sdb, (int)$appareil['Id'], $t, (int)$n); }
            catch (\Throwable $e) { $out[$t] = null; }
        }
        // (passage à chaque synchronisation : l'app y apprend aussi la version proposée par le serveur)
        json_ok(['plages' => $out, 'app' => Synchro::appMobile()]);
    }

    if ($action === 'synchro_fichiers' && $method === 'GET') {
        json_ok(['fichiers' => Synchro::fichiers($sdb, $suser)]);
    }

    if ($action === 'synchro_fichier' && $method === 'GET') {
        $reel = Synchro::cheminReel((string)($_GET['chemin'] ?? ''), $sdb, $suser);
        if ($reel === null || !is_file($reel)) json_error('Fichier introuvable.', 404);
        while (ob_get_level()) ob_end_clean();
        header_remove('Content-Type');
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . filesize($reel));
        header('X-Content-Type-Options: nosniff');
        readfile($reel);
        exit;
    }

    if ($action === 'synchro_fichier_envoyer' && $method === 'POST') {
        if (!$gerant) json_error('Seul un gestionnaire peut envoyer des fichiers au serveur.', 403);
        $b = get_body();
        $chemin = (string)($b['chemin'] ?? '');
        $reel = Synchro::cheminReel($chemin);
        if ($reel === null) json_error('Emplacement non synchronisé : ' . $chemin, 400);
        if (($refus = Synchro::envoiRefuse($chemin)) !== null) json_error($refus, 400);
        $morceau = (int)($b['morceau'] ?? 0); $total = max(1, (int)($b['total'] ?? 1));
        $octets = base64_decode((string)($b['contenu'] ?? ''), true);
        if ($octets === false || $morceau < 0 || $morceau >= $total || $total > 2000) json_error('Morceau illisible.', 400);
        $tmp = dirname(__DIR__, 2) . '/data/run/synchro-' . (int)$appareil['Id'] . '-' . substr(sha1($chemin), 0, 16) . '.part';
        @mkdir(dirname($tmp), 0750, true);
        if (file_put_contents($tmp, $octets, $morceau === 0 ? 0 : FILE_APPEND) === false) json_error('Écriture impossible.', 500);
        if ($morceau === $total - 1) {
            @mkdir(dirname($reel), 0750, true);
            if (!@rename($tmp, $reel)) { @unlink($tmp); json_error('Écriture impossible.', 500); }
            @chmod($reel, 0640);
            Journal::log(Journal::AUDIT, 'synchro', 'Fichier reçu de « ' . $appareil['Nom'] . ' » : ' . $chemin, ['action' => 'SYNCHRO_FICHIER', 'login' => $suser['Login']]);
        }
        json_ok(['recu' => true, 'fini' => $morceau === $total - 1]);
    }

    if ($action === 'synchro_fichier_supprimer' && $method === 'POST') {
        if (!$gerant) json_error('Seul un gestionnaire peut supprimer des fichiers du serveur.', 403);
        $chemin = (string)(get_body()['chemin'] ?? '');
        $reel = Synchro::cheminReel($chemin);
        if ($reel === null) json_error('Emplacement non synchronisé.', 400);
        if (($refus = Synchro::envoiRefuse($chemin)) !== null) json_error($refus, 400);
        if (is_file($reel)) @unlink($reel);
        json_ok(['supprime' => true]);
    }

    if ($action === 'synchro_delier' && $method === 'POST') {
        $sdb->execute('UPDATE SynchroAppareils SET Actif = 0, JetonHash = :h WHERE Id = :id', ['h' => '', 'id' => (int)$appareil['Id']]);
        Journal::log(Journal::AUDIT, 'synchro', 'Appareil délié : ' . $appareil['Nom'], ['action' => 'SYNCHRO_DELIER', 'login' => $suser['Login']]);
        json_ok(['delie' => true]);
    }

    json_error('Opération de synchronisation inconnue.', 404);
}

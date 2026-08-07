<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka — Routes : Annuaire cartographié (« Plans » côté Demandeur)
 *
 * Vue EN LECTURE SEULE d'un plan d'étage sur lequel des points ont été
 * rattachés à des utilisateurs (cf. PlanElements.RefUtilisateurId). Permet à un
 * Demandeur de localiser une personne / un service / un étage et de voir sa
 * fiche (infos locales). La présence temps réel + l'agenda partagé (Microsoft
 * Graph) sont volontairement HORS de cette phase — voir INSTALL-phase1.md § 8.
 *
 * Réutilise :
 *   - la table PlanElements (points) + PlanEtages + PlanBatiments
 *   - le fond de plan servi par l'action existante `plans_fond_image`
 *     (n'exige que require_auth : un Demandeur peut donc l'afficher tel quel)
 *
 * Contrôle d'accès :
 *   - Admin / Gestionnaire / Visionneur : toujours autorisés (aperçu).
 *   - Demandeur : autorisé UNIQUEMENT si le gestionnaire a activé le module
 *     (Configuration → clé `plans_demandeur_actif` = '1'), à l'image de
 *     `urgences_actif` / `inventaire_agent_actif`.
 *
 * Actions :
 *   annuaire_status              → { actif, canView }  (pour le boot du menu)
 *   annuaire_batiments (GET)     → bâtiments disponibles
 *   annuaire_etages    (GET)     → étages d'un bâtiment (&batiment_id=)
 *   annuaire_points    (GET)     → points-personnes d'un étage (&etage_id=)
 *   annuaire_consent   (POST)    → l'utilisateur (dé)active SON partage présence
 */

// ── Garde d'accès commune ────────────────────────────────────────────────────
// Renvoie le $user si l'accès annuaire est permis, sinon coupe avec un 403.
if (!function_exists('_annuaire_guard')) {
    function _annuaire_guard($db): array {
        $user = require_auth();
        $role = $user['Role'] ?? '';
        $isManager = in_array($role, ['Admin', 'Gestionnaire', 'Visionneur'], true);
        if ($isManager) return $user;
        // Demandeur (et tout autre rôle) : soumis au flag gestionnaire
        $actif = ($db->getConfig('plans_demandeur_actif') === '1');
        if (!$actif) json_error("Module Plans non activé pour votre profil.", 403);
        return $user;
    }
}

// ── Statut : le module est-il visible pour cet utilisateur ? ──────────────────
if ($action === 'annuaire_status') {
    $user = require_auth();
    $role = $user['Role'] ?? '';
    $actif = ($db->getConfig('plans_demandeur_actif') === '1');
    $canView = in_array($role, ['Admin', 'Gestionnaire', 'Visionneur'], true) || $actif;
    json_ok(['actif' => $actif, 'canView' => $canView]);
}

// ── Bâtiments ─────────────────────────────────────────────────────────────────
if ($action === 'annuaire_batiments') {
    $user = _annuaire_guard($db);
    if ($method !== 'GET') json_error('Méthode non supportée', 405);
    json_ok($db->getAnnuaireBatiments());
}

// ── Étages d'un bâtiment ──────────────────────────────────────────────────────
if ($action === 'annuaire_etages') {
    $user = _annuaire_guard($db);
    if ($method !== 'GET') json_error('Méthode non supportée', 405);
    $batId = (int)($_GET['batiment_id'] ?? 0);
    json_ok($db->getAnnuaireEtages($batId ?: null));
}

// ── Points-personnes d'un étage ───────────────────────────────────────────────
// Renvoie chaque point rattaché à un utilisateur, enrichi de la fiche locale
// (champs publics uniquement). On fusionne côté PHP pour rester à l'abri des
// subtilités d'alias/casse entre SQLite et PostgreSQL.
if ($action === 'annuaire_textes') {
    $user = _annuaire_guard($db);
    if ($method !== 'GET') json_error('Méthode non supportée', 405);
    $etageId = (int)($_GET['etage_id'] ?? 0);
    if ($etageId <= 0) json_error('etage_id invalide');
    json_ok($db->getAnnuaireTextes($etageId));   // textes marqués « afficher dans l'annuaire »
}

if ($action === 'annuaire_points') {
    $user = _annuaire_guard($db);
    if ($method !== 'GET') json_error('Méthode non supportée', 405);
    $etageId = (int)($_GET['etage_id'] ?? 0);
    if ($etageId <= 0) json_error('etage_id invalide');

    $points = $db->getAnnuairePoints($etageId);           // points TypeElement='point' avec personne
    $ids = [];
    foreach ($points as $p) { $uid = (int)($p['RefUtilisateurId'] ?? 0); if ($uid > 0) $ids[$uid] = true; }
    $users = $db->getAnnuaireUsers(array_keys($ids));       // fiches publiques indexées par Id

    $out = [];
    foreach ($points as $p) {
        $uid = (int)($p['RefUtilisateurId'] ?? 0);
        $u = null;
        if ($uid > 0) {
            $u = $users[$uid] ?? null;               // compte lié
        } elseif (!empty($p['PersonneData'])) {
            $m = json_decode($p['PersonneData'], true);  // personne saisie manuellement
            if (is_array($m)) {
                $u = [
                    'Id' => null,
                    'Nom' => $m['Nom'] ?? '', 'Prenom' => $m['Prenom'] ?? '',
                    'Service' => $m['Service'] ?? '', 'Poste' => $m['Poste'] ?? '',
                    'Email' => $m['Email'] ?? '', 'Tel' => $m['Tel'] ?? '',
                    'TelPro' => $m['TelPro'] ?? '', 'TelMobile' => $m['TelMobile'] ?? '',
                    'OfficeLocation' => $m['OfficeLocation'] ?? '', 'PhotoUrl' => '',
                    'PartagePresencePlan' => 0,           // pas de présence Graph pour une saisie manuelle
                    'Manuel' => true,
                ];
            }
        }
        if (!$u) continue; // personne supprimée/inactive ou données illisibles : on masque le point
        $out[] = [
            'ElementId' => (int)$p['Id'],
            'Coords'    => $p['Coords'] ?? null,
            'Couleur'   => $p['Couleur'] ?? '#2b7be6',
            'Icone'     => $p['Icone'] ?? 'pin',
            'PointNom'  => $p['Nom'] ?? '',
            'Categorie' => $p['SousType'] ?? '',
            'user'      => $u,
        ];
    }
    json_ok($out);
}

// ── Consentement présence : l'utilisateur (dé)active SON partage ──────────────
// Modèle opt-out : la présence/agenda est PARTAGÉE PAR DÉFAUT ;
// l'utilisateur peut la masquer (MasquerPresence = 1). On ne modifie QUE son flag.
if ($action === 'annuaire_consent') {
    $user = require_auth();
    if ($method === 'GET') { json_ok(['partage' => $db->getMasquerPresence((int)$user['Id']) ? 0 : 1]); }
    if ($method !== 'POST') json_error('Méthode non supportée', 405);
    $body = get_body();
    $partage = !empty($body['partage']) ? 1 : 0;    // 1 = partager, 0 = masquer
    $db->setMasquerPresence((int)$user['Id'], $partage ? 0 : 1);
    json_ok(['partage' => $partage]);
}

// ── Helper POST Graph qui NE stoppe PAS la requête sur erreur ───────
if (!function_exists('_annuaireGraphPost')) {
    function _annuaireGraphPost(string $endpoint, array $body, string $token): array {
        $url = 'https://graph.microsoft.com/v1.0/' . ltrim($endpoint, '/');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer '.$token, 'Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT        => 15, CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $resp = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return ['status' => $status, 'data' => json_decode($resp, true) ?: []];
    }
}

// Token applicatif Microsoft (flux client_credentials, app-only).
// Permet de lire les agendas partagés SANS que le demandeur soit connecté via
// Microsoft (utile pour les comptes locaux). Prérequis Azure : l'app doit avoir
// la permission d'APPLICATION « Calendars.Read » avec consentement admin, et le
// tenant doit être un GUID précis (pas « common »). Renvoie '' si indisponible.
if (!function_exists('_annuaireAppToken')) {
    function _annuaireAppToken(): string {
        static $cached = null;              // mémoïsation pour la requête courante
        if ($cached !== null) return $cached;
        $cached = '';
        $tenant = defined('MICROSOFT_TENANT_ID') ? (string)MICROSOFT_TENANT_ID : '';
        $cid    = defined('MICROSOFT_CLIENT_ID') ? (string)MICROSOFT_CLIENT_ID : '';
        $secret = defined('MICROSOFT_CLIENT_SECRET') ? (string)MICROSOFT_CLIENT_SECRET : '';
        // client_credentials exige un tenant spécifique (les valeurs multi-tenant
        // « common/organizations/consumers » ne sont pas acceptées).
        if (!$cid || !$secret || !$tenant || in_array(strtolower($tenant), ['common', 'organizations', 'consumers'], true)) {
            return $cached;
        }
        $ch = curl_init('https://login.microsoftonline.com/' . $tenant . '/oauth2/v2.0/token');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'client_id'     => $cid,
                'client_secret' => $secret,
                'grant_type'    => 'client_credentials',
                'scope'         => 'https://graph.microsoft.com/.default',
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT        => 10, CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 200) {
            $j = json_decode($resp, true);
            if (!empty($j['access_token'])) $cached = (string)$j['access_token'];
        } else {
            error_log('[Larka][agenda] token applicatif (client_credentials) HTTP ' . $code . ' : ' . substr((string)$resp, 0, 300));
        }
        return $cached;
    }
}

// ── Présence temps réel (batch) — Microsoft Graph ──────────────────
// Body : { userIds: [Id, ...] }. Renvoie { enabled, available, presences: { localId: {availability, activity} } }.
if ($action === 'annuaire_presence') {
    $user = _annuaire_guard($db);
    if ($method !== 'POST') json_error('Méthode non supportée', 405);
    // ⚠️ FIX DIAGNOSTIC : chaque échec renvoyait « indisponible » sans la moindre
    // trace ni distinction — quatre causes très différentes, un seul message à
    // l'écran. On journalise la cause exacte ET on la renvoie dans « reason »,
    // que le client affiche en infobulle.
    if (!defined('PLANS_PRESENCE_ENABLED') || !PLANS_PRESENCE_ENABLED) {
        error_log('[Larka][presence] désactivé : plans_presence.actif absent ou false dans config.json.');
        json_ok(['enabled' => false, 'available' => false, 'reason' => 'disabled', 'presences' => []]);
    }
    if (!function_exists('ensureMsToken')) {
        error_log('[Larka][presence] ensureMsToken() indisponible (routes/sharepoint.php non chargé).');
        json_ok(['enabled' => false, 'available' => false, 'reason' => 'no_helper', 'presences' => []]);
    }

    // ⚠️ FIX : contrôler le token AVANT d'appeler ensureMsToken(). Celle-ci ne
    // lève PAS d'exception quand la session n'a pas de token : elle appelle
    // json_error(401) qui TERMINE la requête. Le try/catch ci-dessous ne se
    // déclenchait donc jamais, le front avalait le 401 en silence, et aucun log
    // n'était écrit — le cas le plus fréquent était le plus invisible.
    // Rappel : Presence.Read.All est une permission DÉLÉGUÉE ; Graph est appelé
    // AU NOM de l'utilisateur connecté. Sans connexion Microsoft (compte local
    // admin/admin, par exemple), la présence ne peut pas fonctionner.
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (empty($_SESSION['ms_access_token'] ?? '')) {
        error_log('[Larka][presence] aucun token Microsoft en session : l\'utilisateur courant ('
            . ($user['Login'] ?? '?') . ') n\'est PAS connecté via Microsoft SSO. '
            . 'La présence est une permission déléguée — reconnectez-vous via Microsoft.');
        json_ok(['enabled' => true, 'available' => false, 'reason' => 'no_ms_token', 'presences' => []]);
    }

    $ids  = array_map('intval', (get_body()['userIds'] ?? []));
    $refs = $db->getAnnuairePresenceRefs($ids);   // exclut les personnes qui ont masqué leur présence
    $msToLocal = []; $msIds = [];
    foreach ($refs as $localId => $r) {
        if (!empty($r['MicrosoftId'])) { $msIds[] = $r['MicrosoftId']; $msToLocal[$r['MicrosoftId']] = $localId; }
    }
    if (empty($msIds)) {
        error_log('[Larka][presence] aucun MicrosoftId exploitable pour les ids ' . implode(',', $ids)
            . ' — fiches créées à la main, jamais connectées via Microsoft, désactivées, '
            . 'ou présence masquée (MasquerPresence=1).');
        json_ok(['enabled' => true, 'available' => false, 'reason' => 'no_ms_id', 'presences' => []]);
    }
    try { $token = ensureMsToken(); }
    catch (\Throwable $e) {
        error_log('[Larka][presence] échec de récupération/rafraîchissement du token : ' . $e->getMessage());
        json_ok(['enabled' => true, 'available' => false, 'reason' => 'token_error', 'presences' => []]);
    }
    $presences = [];
    foreach (array_chunk($msIds, 640) as $chunk) {
        $res = _annuaireGraphPost('communications/getPresencesByUserId', ['ids' => array_values($chunk)], $token);
        if ($res['status'] >= 400) {
            // 403 = scope/consentement manquant → on renvoie "indisponible" sans casser
            error_log('[Larka][presence] Graph getPresencesByUserId HTTP ' . $res['status'] . ' : '
                . ($res['data']['error']['message'] ?? 'sans message')
                . ' | code=' . ($res['data']['error']['code'] ?? '?')
                . ' — si 403 : le token ne porte pas Presence.Read.All (reconnexion Microsoft requise).');
            json_ok(['enabled' => true, 'available' => false,
                     'reason' => ($res['status'] === 403 ? 'graph_403' : 'graph_' . $res['status']),
                     'presences' => $presences]);
        }
        foreach (($res['data']['value'] ?? []) as $p) {
            $lid = $msToLocal[$p['id'] ?? ''] ?? null;
            if ($lid !== null) $presences[$lid] = ['availability' => $p['availability'] ?? '', 'activity' => $p['activity'] ?? ''];
        }
    }
    json_ok(['enabled' => true, 'available' => true, 'presences' => $presences]);
}

// ── Agenda (créneaux occupés du jour) — Microsoft Graph getSchedule ─
// Body : { userId: Id }. Renvoie { enabled, available, slots: [{start, end, status}] } (heures UTC ISO).
if ($action === 'annuaire_agenda') {
    $user = _annuaire_guard($db);
    if ($method !== 'POST') json_error('Méthode non supportée', 405);
    if (!defined('PLANS_PRESENCE_ENABLED') || !PLANS_PRESENCE_ENABLED) {
        error_log('[Larka][agenda] désactivé : plans_presence.actif absent ou false dans config.json.');
        json_ok(['enabled' => false, 'available' => false, 'reason' => 'disabled', 'slots' => []]);
    }
    if (!function_exists('ensureMsToken')) {
        json_ok(['enabled' => false, 'available' => false, 'reason' => 'no_helper', 'slots' => []]);
    }
    // ensureMsToken() ne lève pas d'exception : elle appelle json_error(401) qui
    // TERMINE la requête. On teste donc la session AVANT (cf. annuaire_presence).
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();

    $body = get_body();
    $uid  = (int)($body['userId'] ?? 0);
    $refs = $db->getAnnuairePresenceRefs([$uid]);
    $email = $refs[$uid]['Email'] ?? '';
    if (!$email) json_ok(['enabled' => true, 'available' => false, 'reason' => 'no_ms_id', 'slots' => []]);

    // ── Fenêtre demandée ────────────────────────────────────────────────────
    // Bornes fournies par le client : jamais reprises telles quelles. On valide
    // le format, on force l'ordre, et on PLAFONNE la durée — sinon un client
    // pourrait réclamer plusieurs années à Graph à chaque clic (getSchedule
    // refuse au-delà de 42 jours et la charge exploserait).
    $MAX_DAYS = 31;
    $tz = new DateTimeZone('UTC');
    $parse = static function ($v, $tz) {
        if (!is_string($v) || $v === '') return null;
        $d = DateTime::createFromFormat('Y-m-d\TH:i:s', substr($v, 0, 19), $tz);
        return ($d && $d->format('Y') > 2000) ? $d : null;
    };
    $start = $parse($body['start'] ?? '', $tz);
    $end   = $parse($body['end'] ?? '', $tz);
    if (!$start || !$end) {                       // défaut historique : 10 h à venir
        $start = new DateTime('now', $tz);
        $end   = (clone $start)->modify('+10 hours');
    }
    if ($end <= $start) $end = (clone $start)->modify('+1 day');
    $span = $start->diff($end)->days;
    if ($span > $MAX_DAYS) $end = (clone $start)->modify("+{$MAX_DAYS} days");

    // Granularité : 30 min en vue jour/semaine, 60 min au-delà. Moins d'intervalles
    // = réponse plus légère, sans perte utile sur une vue mensuelle.
    $interval = ($span > 8) ? 60 : 30;
    // Le détail des réunions n'a de sens que sur une fenêtre courte ; au-delà on
    // ne renvoie que la trame d'occupation (availabilityView), bien plus compacte.
    $wantDetail = ($span <= 8);

    // Choix du token : d'abord le token DÉLÉGUÉ du demandeur (s'il est connecté
    // via Microsoft, comportement inchangé), sinon repli sur le token APPLICATIF
    // (client_credentials) pour couvrir les demandeurs en compte local.
    $token = '';
    $schedulePath = 'me/calendar/getSchedule';
    if (!empty($_SESSION['ms_access_token'] ?? '')) {
        try { $token = ensureMsToken(); }
        catch (\Throwable $e) { $token = ''; }
    }
    if ($token === '') {
        $appTok = _annuaireAppToken();
        if ($appTok !== '') {
            $token = $appTok;
            // En mode app-only, pas de « me » : on cible directement la boîte.
            $schedulePath = 'users/' . rawurlencode($email) . '/calendar/getSchedule';
        }
    }
    if ($token === '') {
        // Ni session Microsoft, ni token applicatif exploitable.
        $why = (defined('MICROSOFT_CLIENT_ID') && MICROSOFT_CLIENT_ID) ? 'no_app_token' : 'no_ms_token';
        json_ok(['enabled' => true, 'available' => false, 'reason' => $why, 'slots' => []]);
    }
    $res = _annuaireGraphPost($schedulePath, [
        'schedules' => [$email],
        'startTime' => ['dateTime' => $start->format('Y-m-d\TH:i:s'), 'timeZone' => 'UTC'],
        'endTime'   => ['dateTime' => $end->format('Y-m-d\TH:i:s'), 'timeZone' => 'UTC'],
        'availabilityViewInterval' => $interval,
    ], $token);

    if ($res['status'] >= 400) {
        error_log('[Larka][agenda] getSchedule HTTP ' . $res['status'] . ' : '
            . ($res['data']['error']['message'] ?? 'sans message'));
        json_ok(['enabled' => true, 'available' => false, 'reason' => 'graph_' . $res['status'], 'slots' => []]);
    }
    // getSchedule peut répondre 200 avec scheduleItems vide ET un champ « error »
    // par entrée (boîte non résolue, droits insuffisants…). Ignoré auparavant.
    $v0 = $res['data']['value'][0] ?? null;
    if ($v0 === null) {
        error_log('[Larka][agenda] réponse sans « value » : ' . substr(json_encode($res['data']), 0, 500));
        json_ok(['enabled' => true, 'available' => false, 'reason' => 'no_value', 'slots' => []]);
    }
    if (!empty($v0['error'])) {
        error_log('[Larka][agenda] getSchedule a renvoyé une erreur pour ' . $email . ' : '
            . json_encode($v0['error']));
        json_ok(['enabled' => true, 'available' => false,
                 'reason' => 'schedule_error:' . ($v0['error']['responseCode'] ?? '?'), 'slots' => []]);
    }

    $items = $v0['scheduleItems'] ?? [];
    error_log('[Larka][agenda] ' . $email . ' | ' . $start->format('c') . ' → ' . $end->format('c')
        . ' | interval=' . $interval . 'min | items=' . count($items)
        . ' | statuts=' . (implode(',', array_map(static fn($i) => $i['status'] ?? '?', $items)) ?: '(aucun)'));

    $slots = [];
    if ($wantDetail) {
        foreach ($items as $it) {
            $st = strtolower($it['status'] ?? '');
            if ($st === 'free') continue;
            $slots[] = [
                'start'   => ($it['start']['dateTime'] ?? '') . 'Z',
                'end'     => ($it['end']['dateTime'] ?? '') . 'Z',
                'status'  => $it['status'] ?? 'busy',
                // isPrivate : on n'expose PAS l'objet, mais on dit au client que
                // le créneau est privé. Sans ce drapeau il affichait « (sans
                // objet) », ce qui confond un rendez-vous protégé avec un
                // rendez-vous sans intitulé — deux choses très différentes.
                'subject' => (!empty($it['isPrivate'])) ? '' : (string)($it['subject'] ?? ''),
                'prive'   => !empty($it['isPrivate']),
            ];
            if (count($slots) >= 200) break;   // garde-fou de charge
        }
    }
    json_ok([
        'enabled'   => true,
        'available' => true,
        'slots'     => $slots,
        // Trame d'occupation : 1 chiffre par intervalle (0 libre, 1 provisoire,
        // 2 occupé, 3 absent, 4 ailleurs). Sert la vue mensuelle sans détail.
        'view'      => (string)($v0['availabilityView'] ?? ''),
        'interval'  => $interval,
        'start'     => $start->format('Y-m-d\TH:i:s') . 'Z',
        'end'       => $end->format('Y-m-d\TH:i:s') . 'Z',
        'detail'    => $wantDetail,
    ]);
}

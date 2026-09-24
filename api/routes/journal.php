<?php
/**
 * ═══════════════════════════════════════════════════════════════════════════
 *  Routes du journal applicatif
 * ═══════════════════════════════════════════════════════════════════════════
 *
 *  Actions :
 *    - journal          (GET)  Liste filtrée + paginée
 *    - journal_stats    (GET)  Compteurs par niveau / canal / jour
 *    - journal_detail   (GET)  Toutes les entrées d'une même requête (RequestId)
 *    - journal_export   (GET)  Export CSV du résultat filtré
 *    - journal_purge    (POST) Purge manuelle (Admin)
 *    - log_client       (POST) Réception des erreurs JavaScript du navigateur
 *
 *  Le journal contient des adresses IP, des identifiants de connexion et le
 *  détail des modifications : sa consultation est réservée aux Admin et
 *  Gestionnaires. `log_client` fait exception (tout utilisateur authentifié
 *  peut remonter une erreur de son propre navigateur).
 */

// ── Liste filtrée ────────────────────────────────────────────────────────────
if ($action === 'journal' && $method === 'GET') {
    /**
     * ⚠️ LE SUPER ADMIN N'Y AVAIT PAS ACCÈS.
     *
     * Le journal a rejoint la Super Administration — c'est la trace de ce que
     * TOUT LE MONDE a fait, administrateurs de tenant compris, et la laisser à
     * portée de ceux qu'elle surveille lui ôtait sa raison d'être. Mais la
     * route, elle, ne connaissait que les rôles de tenant : le super admin,
     * qui n'en porte aucun, recevait un 403 sur l'écran qu'on venait de lui
     * confier.
     *
     * Sa session est une identité distincte, pas un rôle : on la teste à part.
     */
    if (!empty($_SESSION['superadmin']['authenticated'])) {
        // Identité reconnue : on poursuit sans exiger de rôle de tenant.
    } else {
        $user = require_auth();
        require_role($user, ['Admin', 'Gestionnaire']);
    }

    $where = [];
    $p     = [];

    // Filtres simples, tous optionnels et combinables.
    foreach ([
        'niveau'     => 'Niveau',
        'canal'      => 'Canal',
        'login'      => 'Login',
        'entiteType' => 'EntiteType',
        'requestId'  => 'RequestId',
        'methode'    => 'Methode',
        'route'      => 'Route',
    ] as $param => $colonne) {
        $v = trim((string)($_GET[$param] ?? ''));
        if ($v !== '') { $where[] = "$colonne = :$param"; $p[$param] = $v; }
    }

    if (!empty($_GET['entiteId'])) { $where[] = "EntiteId = :entiteId"; $p['entiteId'] = (int)$_GET['entiteId']; }
    if (!empty($_GET['codeMin']))  { $where[] = "CodeHttp >= :codeMin"; $p['codeMin']  = (int)$_GET['codeMin']; }
    if (!empty($_GET['lentesMs'])) { $where[] = "DureeMs >= :lentesMs"; $p['lentesMs'] = (int)$_GET['lentesMs']; }
    if (!empty($_GET['depuis']))   { $where[] = "DateHeure >= :depuis";  $p['depuis']   = substr((string)$_GET['depuis'], 0, 19); }
    if (!empty($_GET['jusqua']))   { $where[] = "DateHeure <= :jusqua";  $p['jusqua']   = substr((string)$_GET['jusqua'], 0, 19); }

    // Recherche plein texte sur les champs libres.
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '') {
        $where[] = "(LOWER(Message) LIKE :q OR LOWER(Contexte) LIKE :q OR LOWER(Avant) LIKE :q OR LOWER(Apres) LIKE :q)";
        $p['q']  = '%' . strtolower($q) . '%';
    }

    // Niveau minimum : pratique pour ne voir que ce qui pose problème.
    if (!empty($_GET['niveauMin'])) {
        $ordre = ['debug' => 10, 'info' => 20, 'audit' => 30, 'warning' => 40, 'error' => 50, 'critique' => 60];
        $seuil = $ordre[strtolower((string)$_GET['niveauMin'])] ?? 0;
        $gardes = array_keys(array_filter($ordre, fn($v) => $v >= $seuil));
        if ($gardes) {
            $in = [];
            foreach ($gardes as $i => $n) { $in[] = ":nv$i"; $p["nv$i"] = $n; }
            $where[] = 'Niveau IN (' . implode(',', $in) . ')';
        }
    }

    $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $limit    = min(500, max(1, (int)($_GET['limit'] ?? 100)));
    $offset   = max(0, (int)($_GET['offset'] ?? 0));

    try {
        $total = (int)$db->fetchOne("SELECT COUNT(*) AS n FROM Journal $sqlWhere", $p)['n'];
        // LIMIT/OFFSET interpolés : déjà bornés et castés en entier ci-dessus.
        $rows  = $db->fetchAll("SELECT * FROM Journal $sqlWhere ORDER BY Id DESC LIMIT $limit OFFSET $offset", $p);
    } catch (\Throwable $e) {
        // Table absente (première utilisation avant tout écrit) : liste vide
        // plutôt qu'une erreur 500 côté interface.
        json_ok(['items' => [], 'total' => 0, 'limit' => $limit, 'offset' => $offset]);
    }

    json_ok(['items' => $rows, 'total' => $total, 'limit' => $limit, 'offset' => $offset]);
}

// ── Statistiques de synthèse ─────────────────────────────────────────────────
if ($action === 'journal_stats' && $method === 'GET') {
    // Même identité que la route « journal » : sans cela l'écran s'ouvre et
    // ses compteurs échouent en 403, ce qui est pire qu'un refus franc.
    if (empty($_SESSION['superadmin']['authenticated'])) {
        $user = require_auth();
        require_role($user, ['Admin', 'Gestionnaire']);
    }

    $depuis = date('Y-m-d H:i:s', strtotime('-' . max(1, (int)($_GET['jours'] ?? 7)) . ' days'));
    try {
        json_ok([
            'parNiveau' => $db->fetchAll("SELECT Niveau, COUNT(*) AS Total FROM Journal WHERE DateHeure >= :d GROUP BY Niveau ORDER BY Total DESC", ['d' => $depuis]),
            'parCanal'  => $db->fetchAll("SELECT Canal, COUNT(*) AS Total FROM Journal WHERE DateHeure >= :d GROUP BY Canal ORDER BY Total DESC", ['d' => $depuis]),
            'parJour'   => $db->fetchAll("SELECT SUBSTR(DateHeure,1,10) AS Jour, COUNT(*) AS Total FROM Journal WHERE DateHeure >= :d GROUP BY SUBSTR(DateHeure,1,10) ORDER BY Jour", ['d' => $depuis]),
            'topErreurs'=> $db->fetchAll("SELECT Message, COUNT(*) AS Total FROM Journal WHERE DateHeure >= :d AND Niveau IN ('error','critique') GROUP BY Message ORDER BY Total DESC LIMIT 10", ['d' => $depuis]),
            'lentes'    => $db->fetchAll("SELECT Route, COUNT(*) AS Total, MAX(DureeMs) AS PireMs FROM Journal WHERE DateHeure >= :d AND DureeMs >= 2000 GROUP BY Route ORDER BY PireMs DESC LIMIT 10", ['d' => $depuis]),
            'actifs'    => $db->fetchAll("SELECT Login, COUNT(*) AS Total FROM Journal WHERE DateHeure >= :d AND Login != '' GROUP BY Login ORDER BY Total DESC LIMIT 10", ['d' => $depuis]),
        ]);
    } catch (\Throwable $e) {
        json_ok(['parNiveau' => [], 'parCanal' => [], 'parJour' => [], 'topErreurs' => [], 'lentes' => [], 'actifs' => []]);
    }
}

// ── Toutes les entrées d'une même requête ────────────────────────────────────
if ($action === 'journal_detail' && $method === 'GET') {
    // Même identité que la route « journal » : sans cela l'écran s'ouvre et
    // ses compteurs échouent en 403, ce qui est pire qu'un refus franc.
    if (empty($_SESSION['superadmin']['authenticated'])) {
        $user = require_auth();
        require_role($user, ['Admin', 'Gestionnaire']);
    }
    $rid = trim((string)($_GET['requestId'] ?? ''));
    if ($rid === '') json_error('requestId requis.', 400);
    try {
        json_ok($db->fetchAll("SELECT * FROM Journal WHERE RequestId = :r ORDER BY Id", ['r' => $rid]));
    } catch (\Throwable $e) { json_ok([]); }
}

// ── Export CSV ───────────────────────────────────────────────────────────────
if ($action === 'journal_export' && $method === 'GET') {
    $user = require_auth();
    require_role($user, ['Admin']);

    $depuis = substr((string)($_GET['depuis'] ?? date('Y-m-d', strtotime('-7 days'))), 0, 19);
    try {
        $rows = $db->fetchAll("SELECT DateHeure,Niveau,Canal,Action,Message,Login,Role,Ip,Methode,Route,CodeHttp,DureeMs,EntiteType,EntiteId,RequestId FROM Journal WHERE DateHeure >= :d ORDER BY Id DESC LIMIT 50000", ['d' => $depuis]);
    } catch (\Throwable $e) { $rows = []; }

    Journal::info('journal', 'Export CSV du journal', ['action' => 'EXPORT', 'lignes' => count($rows)]);

    if (ob_get_level()) ob_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="journal-' . date('Ymd-Hi') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM : Excel ouvre l'UTF-8 correctement
    if ($rows) fputcsv($out, array_keys($rows[0]), ';');
    foreach ($rows as $r) fputcsv($out, array_values($r), ';');
    fclose($out);
    exit;
}

// ── Purge manuelle ───────────────────────────────────────────────────────────
if ($action === 'journal_purge' && $method === 'POST') {
    $user = require_auth();
    require_role($user, ['Admin']);

    $b     = get_body();
    $jours = max(0, (int)($b['jours'] ?? 30));

    try {
        if ($jours === 0) {
            // Purge totale : on conserve malgré tout la trace de la purge
            // elle-même, écrite juste après.
            $n = $db->fetchOne("SELECT COUNT(*) AS n FROM Journal")['n'] ?? 0;
            $db->execute("DELETE FROM Journal");
        } else {
            $limite = date('Y-m-d H:i:s', strtotime("-$jours days"));
            $n = $db->fetchOne("SELECT COUNT(*) AS n FROM Journal WHERE DateHeure < :l", ['l' => $limite])['n'] ?? 0;
            $db->execute("DELETE FROM Journal WHERE DateHeure < :l", ['l' => $limite]);
        }
    } catch (\Throwable $e) {
        json_error('Purge impossible : ' . $e->getMessage(), 500);
    }

    Journal::warning('journal', "Purge manuelle du journal : $n entrée(s) supprimée(s)", [
        'action' => 'PURGE', 'jours' => $jours, 'supprimees' => (int)$n,
    ]);
    json_ok(['supprimees' => (int)$n]);
}

// ── Aperçu de présence ───────────────────────────────────────────────────────
// « Environ N personnes en ligne » — ordre de grandeur, pas un pointage.
//
// Source : le journal applicatif, qui enregistre déjà le login et l'IP de
// chaque requête. Aucune dépendance à Microsoft Graph, aucun consentement
// admin, et surtout ça mesure l'activité RÉELLE dans l'outil plutôt qu'un
// statut Teams qui reflète l'activité d'un terminal.
//
// Sur site / à distance est déduit de l'IP : réseau privé (RFC 1918) ou sous
// réseau déclaré = sur site. C'est approximatif et ça DOIT le rester :
//   • un utilisateur en VPN sort avec une IP interne → compté « sur site » ;
//   • derrière un reverse proxy mal configuré, tout le monde a la même IP.
// D'où l'affichage volontairement arrondi et préfixé « environ ».
//
// Accessible à tous les utilisateurs connectés : c'est un compteur agrégé,
// jamais nominatif.
if ($action === 'presence_apercu' && $method === 'GET') {
    require_auth();

    $minutes = min(120, max(5, (int)($_GET['minutes'] ?? 15)));
    $depuis  = date('Y-m-d H:i:s', time() - $minutes * 60);

    // Sous-réseaux « bureau » supplémentaires, séparés par des virgules
    // (ex : « 172.20.,10.8. »). Les plages RFC 1918 sont déjà internes.
    $extra = array_filter(array_map('trim', explode(',', (string)$db->getConfig('reseaux_bureau'))));

    $estInterne = function (string $ip) use ($extra): bool {
        if ($ip === '' || $ip === 'cli') return false;
        if ($ip === '127.0.0.1' || $ip === '::1') return true;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE)) return true; // RFC 1918
        }
        foreach ($extra as $prefixe) {
            if ($prefixe !== '' && str_starts_with($ip, $prefixe)) return true;
        }
        return false;
    };

    try {
        $rows = $db->fetchAll(
            "SELECT Login, MAX(Ip) AS Ip FROM Journal
             WHERE DateHeure >= :d AND Login <> '' GROUP BY Login",
            ['d' => $depuis]
        );
    } catch (\Throwable $e) {
        json_ok(['disponible' => false, 'total' => 0, 'surSite' => 0, 'aDistance' => 0, 'minutes' => $minutes]);
    }

    $surSite = 0; $aDistance = 0;
    foreach ($rows as $r) {
        $estInterne((string)($r['Ip'] ?? '')) ? $surSite++ : $aDistance++;
    }

    json_ok([
        'disponible' => true,
        'total'      => count($rows),
        'surSite'    => $surSite,
        'aDistance'  => $aDistance,
        'minutes'    => $minutes,
        // Vrai si la distinction n'a aucun sens (tout le monde sur la même IP :
        // reverse proxy sans X-Forwarded-For). Mieux vaut le dire que mentir.
        'ipUniforme' => count($rows) > 3 && count(array_unique(array_column($rows, 'Ip'))) === 1,
    ]);
}

// ── Effectif connecté à l'échelle de l'organisation (Microsoft Teams) ────────
// Répond à « environ combien de personnes sont là ? » — pas « qui ».
//
// POURQUOI PAS LE JOURNAL : compter les connexions à la GMAO ne compte que les
// utilisateurs de la GMAO. Sur un site de cent personnes, c'est une poignée de
// techniciens. Teams est un signal moins précis, mais TOUT LE MONDE l'a ouvert :
// c'est la couverture qui compte ici, pas la finesse.
//
// POURQUOI PAS annuaire_presence : cette action-là ne balaie que les personnes
// déjà présentes dans la table Utilisateurs avec un MicrosoftId, c'est-à-dire
// celles qui se sont connectées à la GMAO. Même biais d'échantillon. Ici on
// énumère l'annuaire Entra directement.
//
// Jeton APPLICATIF (client_credentials) : aucun utilisateur n'a besoin d'être
// connecté en SSO. Permissions nécessaires côté Entra :
//   • Presence.Read.All  (application) — lire les présences en lot
//   • Calendars.Read     (application) — détecter le télétravail déclaré
//
// AUCUNE permission de répertoire n'est requise : la population vient de la
// table Utilisateurs locale, pas d'une énumération de l'annuaire. User.Read.All
// a été délibérément écartée — elle donnerait accès au profil complet de tous
// les comptes du tenant pour n'afficher qu'un compteur arrondi.
//
// Résultat mis en cache 60 s : le tableau de bord ne doit pas taper Graph à
// chaque affichage, et l'ordre de grandeur ne bouge pas à la seconde.
if ($action === 'presence_effectif' && $method === 'GET') {
    require_auth();

    // Minuscules sans dépendre de mbstring (absent d'un PHP minimal) : sinon
    // la comparaison lève une Error et la détection échoue en silence.
    $minus = function (string $v): string {
        return function_exists('mb_strtolower') ? mb_strtolower($v, 'UTF-8') : strtolower($v);
    };

    // Un mot-clé court comme « TT » ou « CP » est une sous-chaîne très commune
    // (« ne\u{ff}oyage », « a\u{ff}ente », « Récap »…). Pour ces abréviations on exige
    // une limite de mot ; pour les mots longs, une simple inclusion suffit et
    // tolère les variantes (« teletravail jeudi »).
    $correspond = function (string $sujet, string $mot): bool {
        $mot = trim(preg_replace('/\s+/u', ' ', $mot));
        if ($mot === '') return false;
        if (strlen($mot) <= 4) {
            return (bool)preg_match('/(?<![\p{L}\p{N}])' . preg_quote($mot, '/') . '(?![\p{L}\p{N}])/ui', $sujet);
        }
        return str_contains($sujet, $mot);
    };

    $indispo = function (string $raison) {
        json_ok(['disponible' => false, 'raison' => $raison, 'total' => 0, 'enLigne' => 0]);
    };

    if (!defined('PLANS_PRESENCE_ENABLED') || !PLANS_PRESENCE_ENABLED) $indispo('desactive');
    if (!function_exists('_annuaireAppToken') || !function_exists('_annuaireGraphPost')) {
        $indispo('helpers_absents');   // routes/annuaire.php non chargé
    }

    // ── Cache ────────────────────────────────────────────────────────────────
    $cache = rtrim(defined('LOG_PATH') ? LOG_PATH : __DIR__ . '/../../data/logs', '/') . '/.presence-cache.json';
    if (is_file($cache) && (time() - (int)@filemtime($cache)) < 60) {
        $c = json_decode((string)@file_get_contents($cache), true);
        if (is_array($c)) json_ok($c);
    }

    try {
        // ── Choix du jeton ───────────────────────────────────────────────────
        // Délégué EN PRIORITÉ : si la personne qui consulte est connectée via
        // Microsoft SSO, on interroge Graph EN SON NOM. Elle a déjà le droit de
        // voir la présence de ses collègues — c'est ce que fait l'annuaire — et
        // ça n'exige AUCUN consentement applicatif supplémentaire.
        //
        // Repli applicatif seulement si la session n'a pas de jeton Microsoft
        // (compte local). Ce repli, lui, réclame Presence.Read.All en type
        // Application, que l'administrateur peut refuser sans que le reste
        // cesse de fonctionner.
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $token   = '';
        $origine = '';
        if (!empty($_SESSION['ms_access_token'] ?? '') && function_exists('ensureMsToken')) {
            try { $token = ensureMsToken(); $origine = 'delegue'; }
            catch (\Throwable $e) { $token = ''; }
        }
        if ($token === '') {
            $token = _annuaireAppToken();
            $origine = 'applicatif';
        }
        if ($token === '') $indispo('token_applicatif');

        // ── 1. Population : les personnes connues de l'application ───────────
        // PAS d'énumération de l'annuaire Entra. Cela exigerait User.Read.All
        // en permission APPLICATIVE, qui donne accès au profil COMPLET de tous
        // les comptes du tenant (poste, matricule, manager, groupes, politiques
        // de mot de passe…). Disproportionné pour afficher un compteur arrondi.
        //
        // On part donc des utilisateurs déjà rattachés à un compte Microsoft
        // dans la base : la population se constitue d'elle-même dès que les
        // gens se connectent. Les opt-out (MasquerPresence) sont exclus à la
        // source, avant tout appel réseau.
        $population = $db->getPopulationPresence();
        $exclus     = $db->countPresenceMasquee();

        $ids = []; $upns = [];
        foreach ($population as $per) {
            $ids[] = $per['MicrosoftId'];
            if ($per['Email'] !== '') $upns[] = $per['Email'];
        }

        if (!$ids) {
            error_log('[Larka][presence_effectif] population vide — aucun utilisateur avec MicrosoftId '
                . 'dans la table Utilisateurs (' . $exclus . ' opt-out par ailleurs). '
                . 'La population se remplit à mesure que les gens se connectent via Microsoft.');
            $indispo($exclus > 0 ? 'tous_optout' : 'population_vide');
        }

        // ── 2. Présences, par lots de 650 (limite documentée de l'API) ───────
        // On retient l'état PAR PERSONNE : sans ça, impossible de soustraire
        // correctement les absents et les télétravailleurs — on ne saurait pas
        // si la personne en congé était par ailleurs comptée « en ligne ».
        $enLigneParMs = [];   // MicrosoftId => true
        $enLigne = 0; $occupes = 0; $absents = 0;
        foreach (array_chunk($ids, 650) as $lot) {
            $res = _annuaireGraphPost('communications/getPresencesByUserId', ['ids' => array_values($lot)], $token);
            if ($res['status'] >= 400) {
                error_log('[Larka][presence_effectif] getPresencesByUserId HTTP ' . $res['status']
                    . ' (jeton ' . $origine . ') — si 403 avec un jeton délégué : la session Microsoft ne porte '
                    . 'pas le scope Presence.Read.All (reconnexion nécessaire). Avec un jeton applicatif : '
                    . 'la permission de type Application n\'est pas consentie.');
                $indispo($res['status'] === 403
                    ? ($origine === 'delegue' ? 'presence_403_delegue' : 'graph_403_presence')
                    : 'graph_' . $res['status']);
            }
            foreach (($res['data']['value'] ?? []) as $p) {
                $a = (string)($p['availability'] ?? '');
                if ($a === '' || $a === 'Offline' || $a === 'PresenceUnknown' || $a === 'OutOfOffice') { $absents++; continue; }
                if (in_array($a, ['Busy', 'DoNotDisturb', 'BusyIdle'], true)) $occupes++;
                $enLigne++;
                if (!empty($p['id'])) $enLigneParMs[(string)$p['id']] = true;
            }
        }

        // ── 3. Télétravail / absence, d'après les mots-clés d'agenda ────────
        // Beaucoup de gens posent déjà « TT » ou « Congés » en journée entière :
        // c'est plus fiable que le champ « Lieu de travail » d'Outlook, que
        // presque personne ne remplit.
        //
        // ⚠️ CONFIDENTIALITÉ : l'objet des rendez-vous est comparé aux mots-clés
        // PUIS IMMÉDIATEMENT JETÉ. Il n'est ni stocké, ni journalisé, ni renvoyé
        // au client. Seuls les compteurs sortent d'ici. Lire l'intitulé des
        // réunions de toute une organisation serait disproportionné ; le
        // classer sans le conserver ne l'est pas.
        $motsTT  = array_filter(array_map('trim', explode(',', (string)($db->getConfig('presence_mots_teletravail')
                        ?: 'TT,télétravail,teletravail,tele-travail,remote,home office,distanciel'))));
        $motsAbs = array_filter(array_map('trim', explode(',', (string)($db->getConfig('presence_mots_absence')
                        ?: 'congé,conges,congés,CP,RTT,JRTT,absent,absence,vacances,arrêt,arret,maladie,repos,récup,recup,ASA,plage fixe,plage-fixe,plagefixe'))));

        $teletravail = 0; $absence = 0; $agendaLu = false;
        $classeParMail = [];   // email => 'tt' | 'abs'
        $agendaSujets = 0;   // objets réellement lisibles (ni privés, ni vides)
        // Pourquoi la répartition est-elle disponible, ou non. Sans ça, le
        // tableau de bord ne peut pas distinguer « personne n'est en TT » de
        // « je n'ai pas pu le savoir », et l'utilisateur reste devant un vide
        // inexploitable.
        $agendaRaison = 'non_tente';

        if ($upns && ($motsTT || $motsAbs)) {
            $agendaRaison = 'ok';
            $debutJour = date('Y-m-d\T00:00:00');
            $finJour   = date('Y-m-d\T23:59:00');
            $maintenant = time();   // ne retenir que le créneau en cours

            // getSchedule accepte 100 agendas par appel : 3 requêtes suffisent
            // pour 300 personnes, contre 300 avec calendarView.
            foreach (array_chunk($upns, 100) as $lotUpn) {
                // Chemin différent selon l'origine du jeton : avec un jeton
                // DÉLÉGUÉ, Graph n'autorise que /me/calendar/getSchedule (la
                // recherche de disponibilité se fait au nom de la personne
                // connectée). Avec un jeton APPLICATIF, il faut désigner une
                // boîte : users/{upn}/calendar/getSchedule.
                $cheminAgenda = ($origine === 'delegue')
                    ? 'me/calendar/getSchedule'
                    : 'users/' . rawurlencode($lotUpn[0]) . '/calendar/getSchedule';

                $res = _annuaireGraphPost($cheminAgenda, [
                    'schedules'                 => array_values($lotUpn),
                    'startTime'                 => ['dateTime' => $debutJour, 'timeZone' => date_default_timezone_get()],
                    'endTime'                   => ['dateTime' => $finJour,   'timeZone' => date_default_timezone_get()],
                    'availabilityViewInterval'  => 60,
                ], $token);

                if ($res['status'] >= 400) {
                    $agendaRaison = ($res['status'] === 403)
                        ? ($origine === 'delegue' ? 'agenda_403_delegue' : 'permission_agenda')
                        : 'graph_' . $res['status'];
                    error_log('[Larka][presence_effectif] getSchedule HTTP ' . $res['status']
                        . ' (jeton ' . $origine . ', chemin ' . $cheminAgenda . ')'
                        . ' — si 403 : la permission APPLICATIVE Calendars.Read n\'est pas consentie. '
                        . 'Le comptage des connectés reste valable, seul le détail télétravail manque.');
                    break;   // on garde ce qui a été compté, sans faire échouer le tout
                }
                $agendaLu = true;

                foreach (($res['data']['value'] ?? []) as $iSched => $sched) {
                    // scheduleId porte l'adresse interrogée ; en repli on
                    // s'appuie sur l'ordre, que Graph préserve.
                    $cle = strtolower(trim((string)($sched['scheduleId'] ?? ($lotUpn[$iSched] ?? ''))));
                    $classe = '';
                    foreach (($sched['scheduleItems'] ?? []) as $it) {
                        // Rendez-vous marqué « Privé » dans Outlook : l'objet
                        // n'est pas lu, même si Graph le renvoie. Un jeton
                        // APPLICATIF a un accès complet aux boîtes et peut voir
                        // ce que le partage délégué masquerait — c'est donc à
                        // NOUS de respecter le choix de l'utilisateur.
                        if (!empty($it['isPrivate'])) continue;

                        // ⚠️ Le créneau doit COUVRIR L'INSTANT PRÉSENT.
                        // Sans ce test, un « CP après-midi » posé de 14h à 18h
                        // comptait la personne absente dès 8h du matin, et un
                        // « TT le matin » la comptait à distance jusqu'au soir.
                        // Une demi-journée ne vaut que pendant sa demi-journée.
                        $deb = strtotime((string)($it['start']['dateTime'] ?? ''));
                        $fin = strtotime((string)($it['end']['dateTime']   ?? ''));
                        if ($deb && $fin && ($maintenant < $deb || $maintenant >= $fin)) continue;

                        // Objet lu, comparé, puis abandonné — jamais conservé.
                        // Espaces normalisés : sans ça, « Plage  fixe » (double
                        // espace) ou une tabulation collée échapperaient à un
                        // mot-clé en plusieurs mots.
                        $sujet = trim(preg_replace('/\s+/u', ' ', $minus((string)($it['subject'] ?? ''))));
                        if ($sujet === '') continue;   // privé côté Outlook, ou sans intitulé
                        $agendaSujets++;
                        foreach ($motsAbs as $m) {
                            if ($correspond($sujet, $minus($m))) { $classe = 'abs'; break 2; }
                        }
                        foreach ($motsTT as $m) {
                            if ($correspond($sujet, $minus($m))) { $classe = 'tt'; break 2; }
                        }
                    }
                    if ($classe !== '' && $cle !== '') $classeParMail[$cle] = $classe;
                    if ($classe === 'abs') $absence++;
                    elseif ($classe === 'tt') $teletravail++;
                }
            }
        }

        // Estimation « sur place » : connectés, moins ceux qui ont déclaré du
        // télétravail. Volontairement bornée à 0 et présentée comme une
        // estimation — un TT non déclaré est compté sur site, c'est la limite
        // structurelle de la méthode.
        // ⚠️ Correction : la soustraction ne vaut QUE parmi les personnes
        // effectivement en ligne. Deux erreurs symétriques sinon :
        //   • un salarié en RTT qui laisse Teams ouvert sur son téléphone était
        //     compté « sur place » — les absences n'étaient pas déduites ;
        //   • soustraire TOUTES les absences déclarées, y compris celles de
        //     personnes hors ligne, ferait passer le compteur sous la réalité.
        // On croise donc présence et agenda personne par personne.
        $ttEnLigne = 0; $absEnLigne = 0;
        foreach ($population as $per) {
            if (empty($enLigneParMs[$per['MicrosoftId']])) continue;   // hors ligne : hors calcul
            $c = $classeParMail[strtolower(trim($per['Email']))] ?? '';
            if ($c === 'tt')       $ttEnLigne++;
            elseif ($c === 'abs')  $absEnLigne++;
        }
        $surPlaceEstime = max(0, $enLigne - $ttEnLigne - $absEnLigne);

        // Agenda lu correctement mais aucun mot-clé trouvé : ce n'est pas un
        // échec, c'est un résultat — il faut le dire pour qu'on sache s'il faut
        // ajuster les mots-clés ou demander aux gens de poser leur TT.
        if ($agendaRaison === 'ok' && $teletravail === 0 && $absence === 0) {
            $agendaRaison = ($agendaSujets === 0) ? 'aucun_objet_lisible' : 'aucun_motcle';
        }

        $out = [
            'disponible'  => true,
            'total'       => count($ids),   // comptes actifs examinés
            'enLigne'     => $enLigne,
            'occupes'     => $occupes,
            'horsLigne'   => $absents,
            'teletravail' => $ttEnLigne,        // en ligne ET en TT déclaré
            'absence'     => $absEnLigne,       // en ligne MAIS déclaré absent
            'ttDeclare'   => $teletravail,      // toutes déclarations TT du jour
            'absDeclaree' => $absence,          // toutes déclarations d'absence du jour
            'surPlace'    => $surPlaceEstime,
            'agendaLu'    => $agendaLu,
            'agendaRaison'=> $agendaRaison, // ok | permission_agenda | aucun_motcle | aucun_objet_lisible
            'agendaSujets'=> $agendaSujets, // objets réellement analysés
            'exclus'      => $exclus,       // opt-out respectés (compteur anonyme)
            'source'      => 'utilisateurs_locaux',
            'jeton'        => $origine,   // delegue | applicatif
            'horodatage'  => date('c'),
        ];
        @file_put_contents($cache, json_encode($out));
        json_ok($out);

    } catch (\Throwable $e) {
        error_log('[Larka][presence_effectif] ' . $e->getMessage());
        $indispo('erreur');
    }
}

// ── Suivi de navigation ──────────────────────────────────────────────────────
// « Sur quelle page l'utilisateur est-il allé » ne se déduit pas des appels
// API : plusieurs pages tapent les mêmes routes, et certaines n'en appellent
// aucune. C'est le client qui signale explicitement chaque changement de page.
if ($action === 'log_page' && $method === 'POST') {
    $user = require_auth();
    $b    = get_body();
    $page = substr(preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($b['page'] ?? '')), 0, 40);
    if ($page === '') json_ok(['recu' => false]);

    Journal::info('navigation', 'Page consultée : ' . $page, [
        'action'    => 'PAGE',
        'page'      => $page,
        'precedente'=> substr(preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($b['precedente'] ?? '')), 0, 40),
        'login'     => $user['Login'] ?? '',
        'role'      => $user['Role']  ?? '',
    ]);
    json_ok(['recu' => true]);
}

// ── Réception des interactions détaillées ────────────────────────────────────
// Clics, saisies validées, soumissions, changements d'onglet. Envoyé par lots
// par js/journal-interactions.js.
//
// ⚠️ DÉSACTIVÉ PAR DÉFAUT. Enregistrer les actions détaillées de salariés est
// un traitement de contrôle d'activité : information préalable des personnes
// (C. trav. L1222-4), consultation du CSE (L2312-38), inscription au registre
// des traitements et durée de conservation limitée. L'activation est donc un
// acte volontaire de l'exploitant, pas un défaut silencieux.
//   Activation : clé `journal_interactions` = 1 dans la table Configuration.
if ($action === 'log_interactions' && $method === 'POST') {
    $user = require_auth();

    if ($db->getConfig('journal_interactions') !== '1') {
        // Le client cesse d'émettre en recevant actif=false.
        json_ok(['actif' => false]);
    }

    $b   = get_body();
    $evs = is_array($b['evenements'] ?? null) ? $b['evenements'] : [];
    $n   = 0;

    foreach (array_slice($evs, 0, 200) as $e) {
        if (!is_array($e)) continue;
        $type = substr((string)($e['type'] ?? ''), 0, 20);
        $quoi = substr(trim((string)($e['quoi'] ?? '')), 0, 160);
        if ($type === '' || $quoi === '') continue;

        $page = substr(preg_replace('/[^a-zA-Z0-9_\-#]/', '', (string)($e['page'] ?? '')), 0, 40);
        $val  = array_key_exists('valeur', $e) ? substr((string)$e['valeur'], 0, 200) : null;

        $libelle = match ($type) {
            'clic'        => 'Clic sur ' . $quoi,
            'saisie'      => 'Saisie dans ' . $quoi,
            'frappe'      => 'Frappe dans ' . $quoi,
            'soumission'  => 'Soumission ' . $quoi,
            'onglet'      => 'Onglet ' . $quoi,
            'inactivite'  => 'Inactivité — ' . $quoi,
            'action'      => $quoi,
            default       => $type . ' : ' . $quoi,
        };

        Journal::log(Journal::INFO, 'interaction', $libelle, [
            'action'  => strtoupper($type),
            'page'    => $page,
            'valeur'  => $val,
            'chemin'  => substr((string)($e['chemin'] ?? ''), 0, 140),
            'heure'   => substr((string)($e['t'] ?? ''), 0, 12),
            'login'   => $user['Login'] ?? '',
            'role'    => $user['Role']  ?? '',
        ]);
        $n++;
    }
    json_ok(['actif' => true, 'recus' => $n]);
}

// ── Réception des erreurs JavaScript ─────────────────────────────────────────
// Le navigateur poste ici ce qu'il a intercepté (window.onerror,
// unhandledrejection, échecs d'appels API). Sans ça, une erreur front reste
// invisible côté serveur : c'est le trou le plus courant d'une journalisation.
if ($action === 'log_client' && $method === 'POST') {
    $user = require_auth();
    $b    = get_body();

    $niveau = in_array(($b['niveau'] ?? ''), ['warning', 'error', 'critique'], true) ? $b['niveau'] : 'error';
    $msg    = substr(trim((string)($b['message'] ?? 'Erreur JavaScript')), 0, 500);

    Journal::log($niveau, 'client', $msg, [
        'action'    => 'JS',
        'source'    => substr((string)($b['source']   ?? ''), 0, 250),
        'ligne'     => (int)($b['ligne']    ?? 0),
        'colonne'   => (int)($b['colonne']  ?? 0),
        'pile'      => substr((string)($b['pile']     ?? ''), 0, 2000),
        'page'      => substr((string)($b['page']     ?? ''), 0, 250),
        'navigateur'=> substr((string)($b['navigateur'] ?? ''), 0, 250),
        'login'     => $user['Login'] ?? '',
        'role'      => $user['Role']  ?? '',
    ]);
    json_ok(['recu' => true]);
}

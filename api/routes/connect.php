<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka — Routes : Larka Connect (équipements raccordés). Voir api/connect/Connect.php.
 *
 * Lecture (supervision, mesures, alarmes, liaisons, sessions de recharge) :
 *   Gestionnaire, Visionneur, Technicien.
 * Configuration (connexions, points, règles, liaisons), acquittement,
 * validation des relevés en anomalie : Gestionnaire.
 *
 * ÉCRITURE VERS UN ÉQUIPEMENT (consigne, commande de borne) : Gestionnaire,
 * ET identité confirmée depuis moins de X minutes (mot de passe Larka, ou mot de
 * passe redemandé par Microsoft / Google ; X réglé par le gestionnaire,
 * api/IdentiteConfirmee.php), ET point déclaré inscriptible avec sa plage
 * permise. Chaque écriture — réussie ou non — laisse une trace dans le journal
 * de sécurité (data/security/audit.log) et dans ConnectCommandes.
 *
 * Aucune de ces routes ne déclenche de collecte implicite : ouvrir un écran
 * lit la base, jamais les équipements. Seuls « tester », « découvrir »,
 * « lire maintenant » et l'écriture parlent à un équipement, à la demande.
 */

if (str_starts_with($action, 'connect_')) {
    require_once __DIR__ . '/../connect/Connect.php';
    require_once __DIR__ . '/../SupervisionLien.php';
    require_once __DIR__ . '/../IdentiteConfirmee.php';

    // ── Supervision sur un serveur séparé : l'écran est servi par lui ─────
    // Chaque appel part, scellé, vers le serveur de supervision, qui l'exécute
    // avec les mêmes routes et revérifie les droits (api/SupervisionLien.php).
    // Pas pour un appel qui y est déjà relayé (le serveur de supervision, lui, est en local).
    // Sauf le contexte de l'import vers Énergie : l'index précédent d'un relevé se lit dans
    // le module Énergie D'ICI. La supervision n'a que les relevés qu'elle a écrits : une
    // saisie à la main sur le principal lui échappait, et l'index précédent proposé à
    // l'import était faux.
    if ($action === 'connect_import_contexte' && !isset($GLOBALS['_larka_utilisateur_delegue']) && SupervisionLien::distante($db)) {
        $user = require_auth(); require_role($user, ['Admin', 'Gestionnaire']);
        if (!SupervisionLien::moduleOuvert($db)) json_error('Larka Connect est désactivé pour votre organisation.', 409);
        require_once __DIR__ . '/../connect/Consommations.php';
        $avant = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['avant'] ?? '')) ? (string)$_GET['avant'] : null;
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date'] ?? '')) ? (string)$_GET['date'] : null;
        json_ok(ConnectConsommations::contexteImport($db, explode(',', (string)($_GET['compteurs'] ?? '')), $avant, $date));
    }
    if (!isset($GLOBALS['_larka_utilisateur_delegue']) && !in_array($action, SupervisionLien::ACTIONS_LOCALES, true)
        && SupervisionLien::distante($db)) {
        SupervisionLien::relayerApi($db, $action, $method);   // ne revient pas
    }
    Connect::charger();

    $cxLecture = ['Admin', 'Gestionnaire', 'Visionneur'];
    $cxEcriture = ['Admin', 'Gestionnaire'];
    $cxOrg = Connect::organisationCourante();
    $cxDepot = new ConnectDepot($db);

    /** Refus explicite si Larka Connect n'est pas ouvert ici. */
    $cxExigerActif = function () use ($db): void {
        if (!Connect::actifServeur()) json_error('Larka Connect est désactivé sur ce serveur (Configuration → Serveur → Larka Connect).', 409);
        if (!Connect::actifPour($db)) json_error('Larka Connect est désactivé pour votre organisation.', 409);
    };
    $cxCorps = function () use ($method): array {
        if (!in_array($method, ['POST', 'PUT'], true)) json_error('Méthode non supportée.', 405);
        return get_body();
    };
    $cxInt = fn($v) => ($v === null || $v === '') ? null : (int)$v;
    // Langue de l'écran (« &langue=en ») : seuls les libellés COMPOSÉS par le
    // serveur pour l'affichage (calendriers, conditions des règles) en dépendent.
    $cxEn = (($_GET['langue'] ?? '') === 'en');

    // ── État général (affiché même quand le module est fermé : l'écran explique pourquoi) ──
    if ($action === 'connect_etat' && $method === 'GET') {
        $user = require_auth(); require_role($user, $cxLecture);
        $ocpp = Connect::ocpp();
        $service = ConnectPiloteOcpp::etatService();
        $etat = ConnectCollecte::lireEtat();
        // Console du Super Admin (Config serveur) : il peut n'y avoir aucune
        // organisation derrière la requête. L'état du SERVEUR reste affiché.
        $actifOrg = null;
        $alarmes = 0;
        try {
            $actifOrg = Connect::actifPour($db);
            $alarmes = (Connect::actifServeur() && $actifOrg) ? $cxDepot->compterAlarmesActives() : 0;
        } catch (\Throwable $e) {
            $actifOrg = null;
        }
        json_ok([
            'actif_serveur' => Connect::actifServeur(),
            'actif_organisation' => $actifOrg,
            'planificateur_actif' => ConnectCollecte::planificateurActif(),
            'dernier_passage' => $etat['_passage'] ?? null,
            'ocpp' => ['actif' => $ocpp['actif'], 'en_marche' => (bool)($service['en_marche'] ?? false), 'ecoute' => $service['ecoute'] ?? null,
                       'inconnues' => ($service['en_marche'] ?? false) ? ($service['inconnues'] ?? []) : [],
                       'adresse' => str_replace(rawurlencode('<identifiant>'), '<identifiant>', ConnectPiloteOcpp::adresseBorne('<identifiant>', $cxOrg))],
            'lorawan' => (function () {
                $c = Connect::lorawan();
                $s = ConnectPiloteLorawan::etatService();
                $marche = (bool)($s['en_marche'] ?? false) && !empty($s['actif']);
                return ['actif' => $c['actif'], 'en_marche' => $marche, 'ecoute' => $s['ecoute'] ?? null, 'port' => $c['port'],
                        'passerelles' => $marche ? array_map(fn($eui, $g) => ['eui' => $eui] + $g, array_keys((array)($s['passerelles'] ?? [])), (array)($s['passerelles'] ?? [])) : [],
                        'inconnus' => $marche ? ($s['inconnus'] ?? []) : [],
                        // Adresse à saisir dans les passerelles : celle par laquelle on joint Larka.
                        'adresse_serveur' => (string)($_SERVER['SERVER_ADDR'] ?? '') ?: gethostbyname(gethostname())];
            })(),
            'catalogue' => ConnectPilotes::catalogue(),
            'csv_racines' => array_map(fn($r) => str_starts_with($r, dirname(__DIR__, 2) . DIRECTORY_SEPARATOR) ? substr($r, strlen(dirname(__DIR__, 2)) + 1) : $r,
                                       ConnectPiloteCsv::racines()),
            'alarmes_actives' => $alarmes,
            // Une valeur qui ne bouge pas est quand même historisée à ce rythme (aide du formulaire de point).
            'periodique_minutes' => max(1, (int)Connect::reglage('valeur_periodique_minutes', 15)),
            'peut_configurer' => in_array($user['Role'], $cxEcriture, true),
            // Installation simple : un gestionnaire peut ouvrir le module lui-même (même droit que
            // Configuration → Serveur). En multi-organisation, c'est le Super Administrateur.
            'peut_activer' => !Connect::actifServeur() && in_array($user['Role'], $cxEcriture, true)
                && !(class_exists('TenantResolver', false) && TenantResolver::isMultiTenant()),
            'elevation' => IdentiteConfirmee::restant(),
            // Compte local (mot de passe) ou Microsoft/Google (fenêtre du fournisseur) : l'écran de confirmation en dépend.
            'fournisseur' => IdentiteConfirmee::fournisseur($user),
            'identite_minutes' => IdentiteConfirmee::minutes($db),
        ]);
    }

    // ── Menu : le module est-il ouvert, et combien d'alarmes attendent ? (léger, appelé toutes les 30 s) ──
    if ($action === 'connect_badge' && $method === 'GET') {
        $user = require_auth(); require_role($user, $cxLecture);
        $ouvert = Connect::actifServeur() && Connect::actifPour($db);
        json_ok(['actif' => $ouvert, 'alarmes' => $ouvert ? $cxDepot->compterAlarmesActives() : 0,
                 'a_valider' => $ouvert ? $cxDepot->compterLiaisonsEnAttente() : 0]);
    }

    // ── Supervision : connexions, points et dernières valeurs ─────────────
    if ($action === 'connect_supervision' && $method === 'GET') {
        $user = require_auth(); require_role($user, $cxLecture); $cxExigerActif();
        $points = [];
        foreach ($cxDepot->points() as $p) {
            unset($p['Adresse']);
            $points[(int)$p['PasserelleId']][] = $p;
        }
        $out = [];
        $tous = $cxDepot->passerelles();
        $batsSup = ConnectBatiments::lister($db, $tous);
        foreach ($tous as $pa) {
            $pa['Hotspot'] = ConnectDepot::enHotspot($pa) ? 1 : 0;      // relevée par un PC releveur
            unset($pa['Parametres']);
            $pa['Points'] = $points[(int)$pa['Id']] ?? [];
            $out[] = $pa;
        }
        $bats = array_map(fn($b) => ['Id' => $b['Id'], 'Nom' => $b['Nom'], 'Couleur' => $b['Couleur'],
                                     'Plages' => array_column($b['Plages'], 'texte')], $batsSup);
        // Type d'énergie de chaque point (filtre « Type » de la supervision).
        $tousPoints = array_merge([], ...array_values($points));
        $types = (new ConnectConsommations($cxDepot))->typesPoints($tousPoints);
        json_ok(['passerelles' => $out, 'alarmes' => $cxDepot->alarmes('active', 100), 'maintenant' => date('Y-m-d H:i:s'),
                 'batiments' => $bats, 'charges' => $cxDepot->chargesEnCours(), 'types' => (object)$types]);
    }

    if ($action === 'connect_mesures' && $method === 'GET') {
        $user = require_auth(); require_role($user, $cxLecture); $cxExigerActif();
        $pid = (int)($_GET['point_id'] ?? 0);
        $fin = (string)($_GET['fin'] ?? '') ?: date('Y-m-d H:i:s');
        $debut = (string)($_GET['debut'] ?? '') ?: date('Y-m-d H:i:s', time() - 86400);
        foreach ([$debut, $fin] as $d) if (!preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $d)) json_error('Date invalide.');
        $pt = $cxDepot->point($pid);
        if (!$pt) json_error('Point introuvable.', 404);
        unset($pt['Adresse']);
        json_ok(['point' => $pt, 'mesures' => $cxDepot->mesures($pid, $debut, $fin, min(2000, max(50, (int)($_GET['max'] ?? 600))))]);
    }

    /**
     * Tendances pour les mini-courbes (supervision, mode avancé) : moyenne par
     * heure sur les dernières heures, pour une liste de points, en une requête.
     * Pour un compteur, l'index le plus haut de l'heure (la conso horaire est
     * la différence, faite à l'écran).
     */
    if ($action === 'connect_tendances' && $method === 'GET') {
        $user = require_auth(); require_role($user, $cxLecture); $cxExigerActif();
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_GET['points'] ?? '')))))), 0, 400);
        $heures = max(6, min(168, (int)($_GET['heures'] ?? 24)));
        $out = [];
        if ($ids) {
            $place = implode(',', array_fill(0, count($ids), '?'));
            $st = $db->getPdo()->prepare("SELECT PointId, SUBSTR(Horodatage, 1, 13) AS T, AVG(Valeur) AS Moy, MAX(Valeur) AS Mx FROM ConnectMesures
                                            WHERE PointId IN ($place) AND Horodatage >= ? AND Valeur IS NOT NULL
                                           GROUP BY PointId, SUBSTR(Horodatage, 1, 13) ORDER BY PointId, T");
            $st->execute(array_merge($ids, [date('Y-m-d H:i:s', time() - $heures * 3600)]));
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $r = array_change_key_case($r, CASE_LOWER);
                $out[(int)$r['pointid']][] = [$r['t'], round((float)$r['moy'], 4), (float)$r['mx']];
            }
        }
        json_ok(['heures' => $heures, 'points' => (object)$out]);
    }

    // ── Consommations mensuelles des compteurs (supervision) ──────────────
    if ($action === 'connect_consommations' && $method === 'GET') {
        $user = require_auth(); require_role($user, $cxLecture); $cxExigerActif();
        $mois = (string)($_GET['mois'] ?? '');
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mois)) $mois = date('Y-m');
        $hist = max(0, min(24, (int)($_GET['historique'] ?? 0)));
        $cs = new ConnectConsommations($cxDepot);
        json_ok($cs->tableau($mois, $hist) + ['peut_importer' => in_array($user['Role'], $cxEcriture, true)]);
    }

    // Avant l'import : dernier index connu de chaque compteur Énergie, à la date du relevé.
    if ($action === 'connect_import_contexte' && $method === 'GET') {
        $user = require_auth(); require_role($user, $cxEcriture); $cxExigerActif();
        $avant = (string)($_GET['avant'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $avant)) $avant = null;
        $date = (string)($_GET['date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = null;
        // Index précédent et relevé déjà posé ce jour-là ou après (celui-ci s'intercalerait).
        json_ok(ConnectConsommations::contexteImport($db, explode(',', (string)($_GET['compteurs'] ?? '')), $avant, $date));
    }

    /**
     * Import de consommations dans le module Énergie : une ligne par compteur,
     * avec les valeurs validées (et ajustées) à l'écran. « automatique » pose en
     * plus un relevé automatique mensuel (dernier jour du mois, 23 h 55).
     */
    if ($action === 'connect_importer_energie') {
        $user = require_auth(); require_role($user, $cxEcriture); $cxExigerActif();
        $b = $cxCorps();
        $cs = new ConnectConsommations($cxDepot);
        $liaisons = new ConnectLiaisons($cxDepot, new ConnectAlarmes($cxDepot, $cxOrg));
        $resultats = []; $ecrits = 0; $autos = 0;
        foreach (array_slice((array)($b['lignes'] ?? []), 0, 200) as $d) {
            if (!is_array($d)) continue;
            $pid = (int)($d['pointId'] ?? 0);
            $r = ['pointId' => $pid, 'ok' => false];
            if (!empty($d['ecrire'])) {
                [$v, $err] = $cs->normaliserImport($d);
                if ($err) { $r['message'] = $err; $resultats[] = $r; continue; }
                try {
                    $r['releve'] = $liaisons->importer($v, (string)$user['Login']);
                    $r['ok'] = true; $ecrits++;
                } catch (\Throwable $e) {
                    $r['message'] = 'Écriture impossible : ' . $e->getMessage();
                    $resultats[] = $r; continue;
                }
            }
            if (!empty($d['automatique'])) {
                $actives = array_filter($cxDepot->liaisons($pid), fn($l) => (int)$l['Actif'] && $l['Destination'] === 'releve_energie');
                if ($actives) {
                    $r['automatique'] = 'déjà en place';
                    $r['ok'] = true;
                } else {
                    [$lv, $lerr] = $cxDepot->normaliserLiaison([
                        'pointId' => $pid, 'destination' => 'releve_energie', 'cibleId' => (int)($d['compteurId'] ?? 0),
                        'coefficient' => $d['coefficient'] ?? 1, 'frequence' => 'mensuel', 'jourMois' => -1, 'heure' => '23:55',
                        'toleranceMinutes' => 120, 'validationManuelle' => !empty($d['validation']),
                        'nom' => 'Conso mensuelle (supervision)',
                    ], null);
                    if ($lv === null || $lerr) { $r['message'] = trim(($r['message'] ?? '') . ' Relevé automatique non créé : ' . implode(' ', $lerr)); }
                    else { $cxDepot->enregistrerLiaison(null, $lv, (string)$user['Login']); $r['automatique'] = 'créé'; $autos++; $r['ok'] = true; }
                }
            }
            $resultats[] = $r;
        }
        Connect::journal('info', "Supervision : $ecrits relevé(s) importé(s) dans le module Énergie" . ($autos ? ", $autos relevé(s) automatique(s) créé(s)" : '')
                                 . ' par ' . $user['Login']);
        json_ok(['resultats' => $resultats, 'ecrits' => $ecrits, 'automatiques' => $autos]);
    }

    /**
     * Historique des mesures à exporter : brut, ou regroupé par heure, jour ou
     * mois (moyenne, minimum, maximum ; pour un compteur, index de fin de
     * tranche et consommation de la tranche).
     */
    if ($action === 'connect_export' && $method === 'GET') {
        $user = require_auth(); require_role($user, $cxLecture); $cxExigerActif();
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_GET['points'] ?? '')))))), 0, 500);
        if (!$ids) json_error('Aucun point à exporter.');
        $debut = (string)($_GET['debut'] ?? ''); $fin = (string)($_GET['fin'] ?? '');
        foreach ([$debut, $fin] as $d) if (!preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $d)) json_error('Date invalide.');
        if (strlen($fin) === 10) $fin .= ' 23:59:59';
        $pas = (string)($_GET['pas'] ?? 'jour');
        $long = ['heure' => 13, 'jour' => 10, 'mois' => 7][$pas] ?? null;
        if ($pas !== 'brut' && $long === null) $pas = 'jour';
        $limite = 100000;
        $lignes = []; $tronque = false;
        $place = implode(',', array_fill(0, count($ids), '?'));
        $pts = [];
        foreach ($cxDepot->points() as $p) if (in_array((int)$p['Id'], $ids, true)) $pts[(int)$p['Id']] = $p;
        $pdo = $db->getPdo();
        if ($pas === 'brut') {
            $st = $pdo->prepare("SELECT PointId, Horodatage, Valeur, ValeurTexte, Qualite FROM ConnectMesures
                                  WHERE PointId IN ($place) AND Horodatage >= ? AND Horodatage <= ? ORDER BY PointId, Horodatage LIMIT " . ($limite + 1));
            $st->execute(array_merge($ids, [$debut, $fin]));
            // Clés en minuscules : PostgreSQL rend les noms de colonnes ainsi (Database::fetchAll les remet en forme, pas PDO).
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $r = array_change_key_case($r, CASE_LOWER);
                if (count($lignes) >= $limite) { $tronque = true; break; }
                $lignes[] = [(int)$r['pointid'], $r['horodatage'], $r['valeur'] === null ? null : (float)$r['valeur'], $r['valeurtexte'], $r['qualite']];
            }
        } else {
            $st = $pdo->prepare("SELECT PointId, SUBSTR(Horodatage, 1, $long) AS T, AVG(Valeur) AS Moy, MIN(Valeur) AS Mn, MAX(Valeur) AS Mx, COUNT(Valeur) AS N
                                   FROM ConnectMesures WHERE PointId IN ($place) AND Horodatage >= ? AND Horodatage <= ? AND Valeur IS NOT NULL
                                  GROUP BY PointId, SUBSTR(Horodatage, 1, $long) ORDER BY PointId, T LIMIT " . ($limite + 1));
            $st->execute(array_merge($ids, [$debut, $fin]));
            // Index d'avant la période : la première tranche d'un compteur a aussi sa consommation.
            $precIdx = [];
            foreach ($pts as $pid => $p) {
                if (($p['Nature'] ?? '') !== 'compteur') continue;
                $av = $db->fetchOne("SELECT Valeur FROM ConnectMesures WHERE PointId = :p AND Horodatage < :d AND Valeur IS NOT NULL
                                      ORDER BY Horodatage DESC LIMIT 1", ['p' => $pid, 'd' => $debut]);
                if ($av) $precIdx[$pid] = (float)$av['Valeur'];
            }
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $r = array_change_key_case($r, CASE_LOWER);
                if (count($lignes) >= $limite) { $tronque = true; break; }
                $pid = (int)$r['pointid'];
                $compteur = ($pts[$pid]['Nature'] ?? '') === 'compteur';
                $conso = null;
                if ($compteur && isset($precIdx[$pid]) && (float)$r['mx'] >= $precIdx[$pid]) $conso = round((float)$r['mx'] - $precIdx[$pid], 6);
                if ($compteur) $precIdx[$pid] = (float)$r['mx'];
                $lignes[] = [$pid, $pas === 'heure' ? $r['t'] . ':00' : $r['t'], round((float)$r['moy'], 4), (float)$r['mn'], (float)$r['mx'], (int)$r['n'], $conso];
            }
        }
        $infos = [];
        foreach ($pts as $id => $p) $infos[$id] = ['Nom' => $p['Nom'], 'Unite' => $p['Unite'], 'Nature' => $p['Nature'], 'Decimales' => $p['Decimales'],
                                                   'PasserelleNom' => $p['PasserelleNom'], 'BatimentNom' => $p['BatimentNom']];
        json_ok(['pas' => $pas, 'points' => $infos, 'lignes' => $lignes, 'tronque' => $tronque, 'limite' => $limite]);
    }

    if ($action === 'connect_alarmes' && $method === 'GET') {
        $user = require_auth(); require_role($user, $cxLecture); $cxExigerActif();
        $etat = in_array($_GET['etat'] ?? 'active', ['active', 'terminee', 'toutes'], true) ? $_GET['etat'] ?? 'active' : 'active';
        json_ok($cxDepot->alarmes($etat, (int)($_GET['limite'] ?? 300)));
    }

    if ($action === 'connect_alarme_acquitter') {
        $user = require_auth(); require_role($user, $cxEcriture); $cxExigerActif();
        $b = $cxCorps();
        $ids = array_filter(array_map('intval', (array)($b['ids'] ?? [$b['id'] ?? 0])));
        $n = 0;
        foreach ($ids as $aid) if ($cxDepot->acquitterAlarme($aid, (string)$user['Login'], (string)($b['commentaire'] ?? ''))) $n++;
        json_ok(['acquittees' => $n]);
    }

    // ── Connexions ────────────────────────────────────────────────────────
    if ($action === 'connect_passerelles') {
        $user = require_auth(); $cxExigerActif();
        if ($method === 'GET') {
            require_role($user, $cxLecture);
            if ($id) { $pa = $cxDepot->passerelle($id); $pa ? json_ok($pa) : json_error('Connexion introuvable.', 404); }
            json_ok($cxDepot->passerelles());
        }
        require_role($user, $cxEcriture);
        if ($method === 'DELETE') {
            if (!$id || !$cxDepot->passerelle($id)) json_error('Connexion introuvable.', 404);
            $cxDepot->supprimerPasserelle($id);
            Connect::journal('info', "Connexion n° $id supprimée par {$user['Login']}");
            json_ok('OK');
        }
        $b = $cxCorps();
        $existante = $method === 'PUT' ? ($id ? $db->fetchOne("SELECT * FROM ConnectPasserelles WHERE Id = :id", ['id' => $id]) : null) : null;
        if ($method === 'PUT' && !$existante) json_error('Connexion introuvable.', 404);
        if ($existante) $existante['Parametres'] = json_decode((string)$existante['Parametres'], true) ?: [];
        [$v, $err] = $cxDepot->normaliserPasserelle($b, $existante);
        if ($err) json_error(implode("\n", $err), 422);
        if ($method === 'POST') json_ok(['id' => $cxDepot->creerPasserelle($v, (string)$user['Login'])]);
        $cxDepot->modifierPasserelle($id, $v, (string)$user['Login']);
        json_ok('OK');
    }

    /** Connexion à tester : enregistrée (id), ou saisie pas encore enregistrée (secrets repris de l'existante). */
    $cxMsgHotspot = 'Cet appareil est relevé hors réseau (son hotspot Wi-Fi, ou un câble branché en direct) : le serveur ne peut pas l\'interroger lui-même. '
                  . 'C\'est le PC releveur qui s\'y connecte (onglet Hors réseau) ; pour le tester, sur ce PC : releveur tester.';
    $cxConnexionDemandee = function (array $b) use ($db, $cxDepot, $cxOrg, $cxMsgHotspot): array {
        $pid = (int)($b['id'] ?? 0);
        $existante = $pid ? $db->fetchOne("SELECT * FROM ConnectPasserelles WHERE Id = :id", ['id' => $pid]) : null;
        if ($pid && !$existante) json_error('Connexion introuvable.', 404);
        if (empty($b['connexion'])) {
            if (!$existante) json_error('Connexion à tester manquante.');
            if (ConnectDepot::enHotspot($existante)) json_error($cxMsgHotspot, 409);
            return [$cxDepot->connexionPilote($existante, $cxOrg), $existante];
        }
        if ($existante) $existante['Parametres'] = json_decode((string)$existante['Parametres'], true) ?: [];
        [$v, $err] = $cxDepot->normaliserPasserelle((array)$b['connexion'], $existante);
        if ($v === null) json_error(implode("\n", $err), 422);
        if (ConnectDepot::enHotspot($v)) json_error($cxMsgHotspot, 409);
        $v['Id'] = $pid;
        return [$cxDepot->connexionPilote($v, $cxOrg), $existante];
    };

    if ($action === 'connect_tester') {
        $user = require_auth(); require_role($user, $cxEcriture); $cxExigerActif();
        $b = $cxCorps();
        [$cx] = $cxConnexionDemandee($b);
        $points = $cx['Id'] ? $cxDepot->points((int)$cx['Id'], true) : [];
        @set_time_limit(90);
        $t0 = microtime(true);
        try {
            $pilote = ConnectPilotes::creer($cx);
            $etapes = $pilote->tester($points);
            $pilote->fermer();
        } catch (\Throwable $e) {
            $etapes = [['etape' => 'protocole', 'ok' => false, 'message' => 'Erreur : ' . $e->getMessage(), 'ms' => null]];
        }
        // Chaque étape en échec porte son code : l'écran du test l'explique et dit quoi faire.
        $defauts = ['reseau' => 'CXR-002', 'protocole' => 'CXP-008', 'droits' => 'SEC-004', 'points' => 'CXC-006'];
        foreach ($etapes as &$et) {
            if (($et['ok'] ?? null) !== false) continue;
            $et['code'] = $et['code'] ?? ConnectDepot::codeErreur((string)($et['message'] ?? ''), $defauts[$et['etape'] ?? ''] ?? 'CXC-002');
        }
        unset($et);
        $echec = array_values(array_filter($etapes, fn($e) => ($e['ok'] ?? null) === false));
        Connect::journal($echec ? 'avertissement' : 'info', "Test de la connexion « {$cx['Nom']} » par {$user['Login']} : "
                         . ($echec ? 'échec — ' . $echec[0]['message'] : 'réussi'),
                         ['etapes' => array_column($etapes, 'ok', 'etape'), 'code' => $echec[0]['code'] ?? null,
                          'entiteType' => 'ConnectPasserelle', 'entiteId' => (int)($cx['Id'] ?? 0) ?: null, 'protocole' => (string)($cx['Protocole'] ?? '')]);
        json_ok(['etapes' => $etapes, 'duree' => round(microtime(true) - $t0, 2)]);
    }

    // ── Journal de supervision : ce qui est arrivé aux équipements raccordés ──
    // Les entrées du canal « connect » du journal : pannes et rétablissements,
    // trames refusées, tests, écritures, alarmes. Lisible par qui voit la
    // supervision ; sans adresse IP ni navigateur (ce n'est pas le journal
    // d'audit). Chaque entrée porte son code, son explication et quoi faire.
    if ($action === 'connect_evenements' && $method === 'GET') {
        $user = require_auth(); require_role($user, $cxLecture);
        $w = ["Canal = 'connect'"]; $p = [];
        if (!empty($_GET['passerelle'])) { $w[] = "EntiteType = 'ConnectPasserelle' AND EntiteId = :pa"; $p['pa'] = (int)$_GET['passerelle']; }
        $nv = (string)($_GET['niveau'] ?? '');
        if ($nv === 'incidents') $w[] = "Niveau IN ('warning','error','critique')";
        elseif ($nv === 'erreurs') $w[] = "Niveau IN ('error','critique')";
        $code = strtoupper(trim((string)($_GET['code'] ?? '')));
        if ($code !== '' && preg_match('/^[A-Z]{3}(-\d{3})?$/', $code)) {
            if (strlen($code) === 3) { $w[] = 'Code LIKE :code'; $p['code'] = "$code-%"; } else { $w[] = 'Code = :code'; $p['code'] = $code; }
        }
        $jours = max(1, min(400, (int)($_GET['jours'] ?? 30)));
        $w[] = 'DateHeure >= :d'; $p['d'] = date('Y-m-d H:i:s', time() - 86400 * $jours);
        $q = trim((string)($_GET['q'] ?? ''));
        if ($q !== '') { $w[] = 'LOWER(Message) LIKE :q'; $p['q'] = '%' . mb_strtolower($q) . '%'; }
        $limite = max(1, min(500, (int)($_GET['limit'] ?? 200)));
        try {
            $rows = $db->fetchAll("SELECT Id, DateHeure, Niveau, Message, Code, Login, EntiteType, EntiteId, Contexte FROM Journal WHERE "
                                  . implode(' AND ', $w) . " ORDER BY Id DESC LIMIT $limite", $p);
            $parCode = $db->fetchAll("SELECT Code, COUNT(*) AS Total FROM Journal WHERE " . implode(' AND ', $w)
                                     . " AND Code IS NOT NULL AND Code != '' GROUP BY Code ORDER BY Total DESC", $p);
        } catch (\Throwable $e) { $rows = []; $parCode = []; }
        $noms = [];
        foreach ($db->fetchAll("SELECT Id, Nom, Protocole FROM ConnectPasserelles") as $pa) $noms[(int)$pa['Id']] = $pa;
        $fiches = [];
        $items = array_map(function ($r) use ($noms, &$fiches) {
            $ctx = json_decode((string)($r['Contexte'] ?? ''), true) ?: [];
            $c = (string)($r['Code'] ?? '');
            if ($c !== '' && !isset($fiches[$c])) $fiches[$c] = CodesErreur::fiche($c);
            $pid = ($r['EntiteType'] ?? '') === 'ConnectPasserelle' ? (int)$r['EntiteId'] : 0;
            return ['Id' => (int)$r['Id'], 'DateHeure' => $r['DateHeure'], 'Niveau' => $r['Niveau'], 'Message' => $r['Message'], 'Code' => $c ?: null,
                    'Login' => $r['Login'] ?: null, 'PasserelleId' => $pid ?: null, 'PasserelleNom' => $pid ? ($noms[$pid]['Nom'] ?? null) : null,
                    'Protocole' => $ctx['protocole'] ?? ($pid ? ($noms[$pid]['Protocole'] ?? null) : null),
                    'EtatAvant' => $ctx['etat_avant'] ?? null, 'Etat' => $ctx['etat'] ?? null, 'CodeCause' => $ctx['code_cause'] ?? null,
                    // Une exception : son message seulement (la pile reste au journal d'audit).
                    'Detail' => isset($ctx['exception']['message']) ? mb_substr((string)$ctx['exception']['message'], 0, 400) : null];
        }, $rows);
        foreach ($items as $it) if ($it['CodeCause'] && !isset($fiches[$it['CodeCause']])) $fiches[$it['CodeCause']] = CodesErreur::fiche($it['CodeCause']);
        json_ok(['items' => $items, 'codes' => array_filter($fiches), 'parCode' => $parCode, 'jours' => $jours]);
    }

    // ── Matériel radio branché sur le serveur (clés USB wM-Bus) ──
    // Lecture seule : noms de ports et identifiants USB. Réservé à qui règle les connexions.
    if ($action === 'connect_materiel' && $method === 'GET') {
        $user = require_auth(); require_role($user, $cxEcriture);
        $cxExigerActif();
        $ports = [];
        foreach (ConnectSerie::ports() as $p) {
            $p['acces'] = ConnectSerie::windows() ? null : (is_readable($p['port']) && is_writable($p['port']));
            $ports[] = array_intersect_key($p, array_flip(['port', 'vid', 'pid', 'fabricant', 'produit', 'modele', 'libelle', 'conseil', 'acces']));
        }
        $compte = function_exists('posix_getpwuid') && function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '') : '';
        json_ok(['ports' => $ports, 'systeme' => PHP_OS_FAMILY,
            'aide_droits' => "Accès refusé au port : ajoutez l'utilisateur du service web" . ($compte !== '' ? " ($compte)" : '')
                           . ' au groupe dialout (sudo usermod -aG dialout ' . ($compte !== '' ? $compte : '<utilisateur>') . '), puis redémarrez PHP-FPM ou le service.']);
    }

    if ($action === 'connect_decouvrir') {
        $user = require_auth(); require_role($user, $cxEcriture); $cxExigerActif();
        $b = $cxCorps();
        [$cx] = $cxConnexionDemandee($b);
        @set_time_limit(120);
        try {
            $pilote = ConnectPilotes::creer($cx);
            $props = $pilote->decouvrir();
            $pilote->fermer();
        } catch (\Throwable $e) {
            json_error('Découverte impossible : ' . $e->getMessage(), 502);
        }
        $existants = $cx['Id'] ? $cxDepot->points((int)$cx['Id']) : [];
        $codes = array_map('strval', array_column($existants, 'Code'));
        foreach ($props as &$p) {
            $p['deja'] = in_array((string)$p['code'], $codes, true);
            // Même adresse dans l'équipement (codes saisis autrement à la main) : déjà suivi aussi.
            $adr = is_array($p['adresse'] ?? null) ? $p['adresse'] : [];
            if (!$p['deja'] && $adr) {
                foreach ($existants as $ex) {
                    $ea = is_array($ex['Adresse'] ?? null) ? $ex['Adresse'] : [];
                    $pareil = true;
                    foreach ($adr as $k => $v) {
                        if ((string)($ea[$k] ?? '') !== (string)$v) { $pareil = false; break; }
                    }
                    if ($pareil) { $p['deja'] = true; break; }
                }
            }
        }
        unset($p);
        json_ok($props);
    }

    if ($action === 'connect_explorer') {
        $user = require_auth(); require_role($user, $cxEcriture); $cxExigerActif();
        $b = $cxCorps();
        [$cx] = $cxConnexionDemandee($b);
        if ($cx['Protocole'] !== 'modbus') json_error('L\'explorateur ne concerne que Modbus.');
        $pilote = ConnectPilotes::creer($cx);
        $r = $pilote->explorer((int)($b['unite'] ?? 1), (string)($b['table'] ?? 'holding'), (int)($b['debut'] ?? 0), (int)($b['nombre'] ?? 20));
        $pilote->fermer();
        json_ok($r);
    }

    if ($action === 'connect_collecter') {
        $user = require_auth(); require_role($user, $cxEcriture); $cxExigerActif();
        $b = $cxCorps();
        $pa = $db->fetchOne("SELECT * FROM ConnectPasserelles WHERE Id = :id", ['id' => (int)($b['id'] ?? 0)]);
        if (!$pa) json_error('Connexion introuvable.', 404);
        if ($pa['Protocole'] === 'ocpp') json_error('Une borne envoie ses données d\'elle-même : rien à lire.');
        if ($pa['Protocole'] === 'lorawan') json_error('Un capteur LoRaWAN envoie ses trames de lui-même : rien à lire. « Tester » montre la dernière reçue.');
        if (ConnectDepot::enHotspot($pa)) json_error($cxMsgHotspot, 409);
        $r = ConnectCollecte::connexion($cxDepot, new ConnectAlarmes($cxDepot, $cxOrg), $pa, $cxOrg);
        json_ok($r);
    }

    // ── Auto-détection : ce que Larka sait raccorder sur le réseau interne ──
    // Lecture seule (poignées de main, Who-Is, GET /) ; adresses internes seulement.
    if ($action === 'connect_detection' && $method === 'GET') {
        $user = require_auth(); require_role($user, $cxEcriture); $cxExigerActif();
        require_once __DIR__ . '/../connect/Detection.php';
        $bats = array_map(fn($b) => ['Id' => $b['Id'], 'Nom' => $b['Nom'], 'NbConnexions' => $b['NbConnexions'],
                                     'Plages' => array_map(fn($p) => ['texte' => $p['texte'], 'origine' => $p['origine']], $b['Plages'])],
                          ConnectBatiments::lister($db, $cxDepot->passerelles()));
        json_ok(['suggestions' => ConnectDetection::suggestions(), 'ports' => ConnectDetection::PORTS,
                 'protocoles' => ConnectDetection::PROTOCOLES, 'max_adresses' => ConnectDetection::MAX_ADRESSES,
                 'max_adresses_batiments' => ConnectBatiments::MAX_ADRESSES_DETECTION,
                 'sockets' => function_exists('socket_create'), 'batiments' => $bats,
                 'exclusions' => ['plages' => $cxDepot->plagesExclues(), 'appareils' => $cxDepot->exclusions()]]);
    }

    if ($action === 'connect_detecter') {
        $user = require_auth(); require_role($user, $cxEcriture); $cxExigerActif();
        require_once __DIR__ . '/../connect/Detection.php';
        $b = $cxCorps();
        $protocoles = array_values(array_intersect(ConnectDetection::PROTOCOLES, array_map('strval', (array)($b['protocoles'] ?? ConnectDetection::PROTOCOLES))));
        if (!$protocoles) json_error('Choisissez au moins un protocole.', 422);
        $reseau = array_diff($protocoles, ['csv', 'ocpp']);
        $pas = $cxDepot->passerelles();
        $batiments = ConnectBatiments::lister($db, $pas);
        // « batiment » : l'Id d'un bâtiment, ou « tous » — ses réseaux (déclarés et appris) remplacent la plage saisie.
        $cible = null; $parBatiments = false;
        if (isset($b['batiment']) && $b['batiment'] !== '' && $b['batiment'] !== null) {
            $parBatiments = true;
            $cible = $b['batiment'] === 'tous' ? null : (int)$b['batiment'];
            if ($cible !== null && !array_filter($batiments, fn($x) => $x['Id'] === $cible)) json_error('Bâtiment introuvable.', 404);
            [$plageB, $sans] = ConnectBatiments::plageDetection($batiments, $cible);
            if ($plageB === '' && $reseau) {
                json_error($cible !== null
                    ? 'Ce bâtiment n\'a pas encore de réseau : indiquez ses adresses (onglet Bâtiments), ou saisissez-les dans « Autres adresses ».'
                    : 'Aucun bâtiment n\'a de réseau pour l\'instant : indiquez-les dans l\'onglet Bâtiments, ou saisissez des adresses.', 422);
            }
            $b['plage'] = $plageB;
        }
        $max = $parBatiments ? ConnectBatiments::MAX_ADRESSES_DETECTION : ConnectDetection::MAX_ADRESSES;
        [$ips, $err] = ($reseau || trim((string)($b['plage'] ?? '')) !== '') ? ConnectDetection::cibles((string)($b['plage'] ?? ''), $max) : [[], []];
        if ($err && $parBatiments) $err[] = 'Lancez plutôt la détection bâtiment par bâtiment.';
        if ($err) json_error(implode("\n", $err), 422);
        // Plages jamais parcourues (postes utilisateurs, téléphonie…) : retirées avant de sonder.
        $exclues = 0;
        [$plagesEx] = ConnectBatiments::plages($cxDepot->plagesExclues());
        if ($plagesEx && $ips) {
            $avant = count($ips);
            $ips = array_values(array_filter($ips, fn($ip) => ConnectBatiments::contient($plagesEx, $ip) === null));
            $exclues = $avant - count($ips);
            if ($reseau && !$ips) json_error('Toutes ces adresses sont dans les « adresses à ne jamais parcourir ».', 422);
        }
        if ($reseau && !$ips) json_error('Indiquez les adresses à parcourir, par exemple 192.168.20.0/24.', 422);
        // Un parcours réseau n'est pas gratuit : 10 par quart d'heure et par compte.
        if ($reseau) check_rate_limit('connect_detecter_' . strtolower((string)$user['Login']), 10, 900);
        @set_time_limit(200);
        if (function_exists('session_write_close')) session_write_close();   // l'application reste utilisable pendant ce temps
        $t0 = microtime(true);
        // Budget : celui réglé, pour 1 024 adresses ; davantage (3 minutes au plus) pour les réseaux de plusieurs bâtiments.
        $budget = (float)Connect::reglage('detection_budget_secondes', 90);
        if ($parBatiments) $budget = min(180.0, max($budget, $budget * count($ips) / ConnectDetection::MAX_ADRESSES));
        $det = new ConnectDetection($budget);
        $trouves = $det->detecter($ips, $protocoles, is_array($b['ports'] ?? null) ? $b['ports'] : []);
        $trouves = ConnectDetection::rapprocher($trouves, $pas, $batiments, $cible);
        // Appareils et machines ignorés : renvoyés à part, pour que l'écran les montre repliés.
        $ignores = [];
        foreach ($cxDepot->exclusions() as $x) $ignores[$x['Type'] . '|' . $x['Valeur']] = (int)$x['Id'];
        foreach ($trouves as &$t) {
            $t['ignore'] = $ignores['appareil|' . ConnectDetection::cle($t)] ?? (($t['hote'] ?? '') !== '' ? ($ignores['machine|' . strtolower((string)$t['hote'])] ?? null) : null);
        }
        unset($t);
        Connect::journal('info', "Détection par {$user['Login']} : " . count($ips) . ' adresse(s), ' . implode(', ', $protocoles) . ' — '
            . count($trouves) . ' équipement(s) trouvé(s)', ['plage' => mb_substr((string)($b['plage'] ?? ''), 0, 200)]);
        json_ok(['equipements' => $trouves, 'duree' => round(microtime(true) - $t0, 1), 'incomplet' => $det->tempsEcoule(),
                 'plage' => (string)($b['plage'] ?? ''), 'adresses_exclues' => $exclues] + $det->statistiques());
    }

    /**
     * Ce que la détection ne propose plus : « ignorer » un appareil (ou une machine
     * entière), « retirer » une exclusion, « plages » jamais parcourues.
     */
    if ($action === 'connect_exclusions') {
        $user = require_auth(); require_role($user, $cxEcriture); $cxExigerActif();
        $b = $cxCorps();
        $op = (string)($b['op'] ?? '');
        if ($op === 'ignorer') {
            $ids = [];
            foreach (array_slice((array)($b['appareils'] ?? []), 0, 200) as $a) {
                if (!is_array($a)) continue;
                $machine = !empty($a['machine']) && filter_var((string)($a['hote'] ?? ''), FILTER_VALIDATE_IP);
                $cle = $machine ? strtolower((string)$a['hote']) : ConnectDetection::cle($a);
                if (preg_match('/^\w+\|\|0$/', $cle)) { $ids[] = null; continue; }   // ni adresse ni dossier : rien à ignorer
                $ids[] = $cxDepot->ignorer($machine ? 'machine' : 'appareil', $cle, (string)($a['nom'] ?? ''), (string)$user['Login']);
            }
            json_ok(['ids' => $ids]);
        }
        if ($op === 'retirer') { $cxDepot->retirerExclusion((int)($b['id'] ?? 0)); json_ok(['retire' => true]); }
        if ($op === 'plages') {
            $texte = trim((string)($b['plages'] ?? ''));
            [$pl, $err] = ConnectBatiments::plages($texte);
            if ($err) json_error(implode("\n", $err), 422);
            $cxDepot->enregistrerPlagesExclues(implode(', ', array_column($pl, 'texte')), (string)$user['Login']);
            json_ok(['plages' => implode(', ', array_column($pl, 'texte'))]);
        }
        json_error('Opération inconnue.', 400);
    }

    /**
     * Équipements détectés → connexions (et, si demandé, leurs points
     * découverts d'emblée). Chaque connexion passe par la même validation
     * qu'une saisie à la main.
     */
    if ($action === 'connect_detection_creer') {
        $user = require_auth(); require_role($user, $cxEcriture); $cxExigerActif();
        $b = $cxCorps();
        @set_time_limit(200);
        if (function_exists('session_write_close')) session_write_close();
        $avecPoints = !empty($b['decouvrirPoints']);
        $out = [];
        $echeance = microtime(true) + 150;
        foreach (array_slice((array)($b['equipements'] ?? []), 0, 100) as $e) {
            if (!is_array($e)) continue;
            $proto = (string)($e['protocole'] ?? '');
            $nom = trim((string)($e['nom'] ?? '')) ?: $proto;
            [$v, $err] = $cxDepot->normaliserPasserelle([
                'protocole' => $proto, 'nom' => $nom, 'hote' => (string)($e['hote'] ?? ''), 'port' => $e['port'] ?? null,
                'identifiant' => (string)($e['identifiant'] ?? ''), 'parametres' => is_array($e['parametres'] ?? null) ? $e['parametres'] : [],
                'batimentId' => $e['batimentId'] ?? ($b['batimentId'] ?? null), 'actif' => true,
                'description' => mb_substr('Détectée automatiquement le ' . date('d/m/Y') . '. ' . (string)($e['details'] ?? ''), 0, 1000),
            ]);
            if ($err) { $out[] = ['nom' => $nom, 'id' => null, 'points' => 0, 'erreur' => implode(' ', $err)]; continue; }
            $pid = $cxDepot->creerPasserelle($v, (string)$user['Login']);
            $ligne = ['nom' => $v['Nom'], 'id' => $pid, 'protocole' => $proto, 'points' => 0, 'erreur' => null];
            $desc = ConnectPilotes::description($proto) ?: [];
            if ($avecPoints && !empty($desc['decouverte']) && microtime(true) < $echeance) {
                try {
                    $pa = $db->fetchOne("SELECT * FROM ConnectPasserelles WHERE Id = :id", ['id' => $pid]);
                    $pilote = ConnectPilotes::creer($cxDepot->connexionPilote($pa, $cxOrg));
                    $props = $pilote->decouvrir();
                    $pilote->fermer();
                    foreach (array_slice($props, 0, 300) as $i => $p) {
                        [$vp, $errp] = $cxDepot->normaliserPoint([
                            'passerelleId' => $pid, 'nom' => $p['nom'] ?? '', 'code' => $p['code'] ?? '', 'nature' => $p['nature'] ?? 'mesure',
                            'unite' => $p['unite'] ?? '', 'adresse' => is_array($p['adresse'] ?? null) ? $p['adresse'] : [], 'ordre' => $i,
                        ]);
                        if ($errp) continue;
                        $cxDepot->creerPoint($vp, (string)$user['Login']);
                        $ligne['points']++;
                    }
                    if (!$props) $ligne['erreur'] = 'aucun point proposé par l\'équipement pour l\'instant';
                } catch (\Throwable $ex) {
                    $ligne['erreur'] = 'découverte des points impossible : ' . $ex->getMessage();
                }
            }
            $out[] = $ligne;
        }
        $nb = count(array_filter($out, fn($x) => $x['id']));
        Connect::journal('info', "Détection : $nb connexion(s) créée(s) par {$user['Login']}");
        json_ok(['connexions' => $out, 'creees' => $nb]);
    }

    // ── Bâtiments : réseaux, mots-clés, rangement des connexions ──────────
    if ($action === 'connect_batiments' && $method === 'GET') {
        $user = require_auth(); require_role($user, $cxLecture); $cxExigerActif();
        $pas = $cxDepot->passerelles();
        $bats = ConnectBatiments::lister($db, $pas);
        $etat = [];
        foreach ($pas as $pa) {
            $k = (int)($pa['BatimentId'] ?? 0);
            $etat[$k] ??= ['ok' => 0, 'erreur' => 0, 'autre' => 0, 'alarmes' => 0];
            $e = !+$pa['Actif'] ? 'autre' : (($pa['Etat'] ?? '') === 'ok' ? 'ok' : (($pa['Etat'] ?? '') === 'erreur' ? 'erreur' : 'autre'));
            $etat[$k][$e]++;
            $etat[$k]['alarmes'] += (int)($pa['NbAlarmes'] ?? 0);
        }
        foreach ($bats as &$b) {
            $b['Etats'] = $etat[$b['Id']] ?? ['ok' => 0, 'erreur' => 0, 'autre' => 0, 'alarmes' => 0];
            $b['Plages'] = array_map(fn($p) => ['texte' => $p['texte'], 'origine' => $p['origine']], $b['Plages']);
        }
        unset($b);
        json_ok(['batiments' => $bats, 'sans_batiment' => count(array_filter($pas, fn($p) => empty($p['BatimentId']))),
                 'rangeables' => ConnectBatiments::rangeables($pas, ConnectBatiments::lister($db, $pas))]);
    }

    if ($action === 'connect_batiment') {
        $user = require_auth(); require_role($user, $cxEcriture); $cxExigerActif();
        $b = $cxCorps();
        [$err, $avert] = ConnectBatiments::enregistrer($db, (int)($b['id'] ?? 0), (string)($b['reseaux'] ?? ''), (string)($b['motsCles'] ?? ''),
                                                      (string)$user['Login']);
        if ($err) json_error(implode("\n", $err), 422);
        Connect::journal('info', "Réseaux du bâtiment n° " . (int)$b['id'] . " modifiés par {$user['Login']}", ['reseaux' => mb_substr((string)($b['reseaux'] ?? ''), 0, 200)]);
        json_ok(['avertissements' => $avert]);
    }

    /** Connexions sans bâtiment → leur bâtiment deviné (toutes, ou celles listées dans « ids »). */
    if ($action === 'connect_batiments_ranger') {
        $user = require_auth(); require_role($user, $cxEcriture); $cxExigerActif();
        $b = $cxCorps();
        $pas = $cxDepot->passerelles();
        $liste = ConnectBatiments::rangeables($pas, ConnectBatiments::lister($db, $pas));
        $ids = isset($b['ids']) && is_array($b['ids']) ? array_map('intval', $b['ids']) : null;
        $n = 0;
        foreach ($liste as $r) {
            if ($ids !== null && !in_array($r['id'], $ids, true)) continue;
            $cxDepot->rangerPasserelle($r['id'], $r['batimentId'], (string)$user['Login']);
            $n++;
        }
        if ($n) Connect::journal('info', "$n connexion(s) rangée(s) dans leur bâtiment par {$user['Login']}");
        json_ok(['rangees' => $n]);
    }

    /** Parcours guidé : où en est le raccordement ? (compteurs seulement, rien n'est interrogé) */
    if ($action === 'connect_parcours' && $method === 'GET') {
        $user = require_auth(); require_role($user, $cxLecture); $cxExigerActif();
        $pas = $cxDepot->passerelles();
        $bats = ConnectBatiments::lister($db, $pas);
        $actives = array_filter($pas, fn($p) => +$p['Actif']);
        $service = ConnectPiloteOcpp::etatService();
        // Appareils en hotspot : relevés par un PC, pas par le serveur.
        $hs = array_filter($actives, fn($p) => ConnectDepot::enHotspot($p));
        $couverts = []; $tous = false;
        foreach ($cxDepot->releveurs() as $r) {
            if (!(int)$r['Actif']) continue;
            $ids = array_filter(array_map('intval', explode(',', (string)($r['Connexions'] ?? ''))));
            if (!$ids) $tous = true;
            foreach ($ids as $i) $couverts[$i] = true;
        }
        json_ok([
            'hotspots' => count($hs),
            'hotspots_sans_releveur' => $tous ? 0 : count(array_filter($hs, fn($p) => !isset($couverts[(int)$p['Id']]))),
            'hotspots_jamais_releves' => count(array_filter($hs, fn($p) => empty($p['DerniereCollecte']))),
            'batiments' => count($bats),
            'batiments_avec_reseau' => count(array_filter($bats, fn($b) => $b['Plages'])),
            'connexions' => count($pas),
            'en_defaut' => count(array_filter($actives, fn($p) => ($p['Etat'] ?? '') === 'erreur' && !ConnectDepot::enHotspot($p))),
            'sans_batiment' => count(array_filter($pas, fn($p) => empty($p['BatimentId']))),
            'sans_points' => count(array_filter($actives, fn($p) => $p['Protocole'] !== 'ocpp' && !(int)$p['NbPoints'])),
            'points' => array_sum(array_map(fn($p) => (int)$p['NbPoints'], $pas)),
            'regles' => count($cxDepot->regles()),
            'liaisons' => count($cxDepot->liaisons()),
            'bornes' => count(array_filter($pas, fn($p) => $p['Protocole'] === 'ocpp')),
            'bornes_inconnues' => ($service['en_marche'] ?? false) ? count($service['inconnues'] ?? []) : 0,
        ]);
    }

    // ── PC releveurs : appareils en hotspot Wi-Fi, relevés hors ligne ──────
    if ($action === 'connect_releveurs' && $method === 'GET') {
        $user = require_auth(); require_role($user, $cxEcriture); $cxExigerActif();
        $hotspots = [];
        foreach ($cxDepot->passerelles() as $pa) {
            if (!ConnectDepot::enHotspot($pa)) continue;
            $prm = is_array($pa['Parametres'] ?? null) ? $pa['Parametres'] : [];
            $hotspots[] = ['Id' => (int)$pa['Id'], 'Nom' => $pa['Nom'], 'Protocole' => $pa['Protocole'], 'Hote' => $pa['Hote'], 'Port' => $pa['Port'],
                           'Acces' => ConnectDepot::accesHorsReseau($pa), 'UrlBase' => (string)($prm['url_base'] ?? ''),
                           'Ssid' => (string)($prm['wifi_ssid'] ?? ''), 'NbPoints' => (int)$pa['NbPoints'], 'Etat' => $pa['Etat'], 'Actif' => (int)$pa['Actif'],
                           'DernierContact' => $pa['DernierContact'], 'DerniereCollecte' => $pa['DerniereCollecte'], 'DerniereErreur' => $pa['DerniereErreur'], 'BatimentNom' => $pa['BatimentNom'] ?? null,
                           'IntervalleSecondes' => (int)$pa['IntervalleSecondes'],
                           'ReconEtat' => $pa['ReconEtat'] ?? '', 'ReconDate' => $pa['ReconDate'] ?? null];
        }
        json_ok(['releveurs' => $cxDepot->releveurs(), 'hotspots' => $hotspots, 'url' => APP_URL, 'version_collecteur' => ConnectReleveur::versionCollecteur()]);
    }

    if ($action === 'connect_releveur_enregistrer') {
        $user = require_auth(); require_role($user, $cxEcriture); $cxExigerActif();
        $b = $cxCorps();
        $nom = trim((string)($b['nom'] ?? ''));
        if ($nom === '') json_error('Donnez un nom au PC (ex. « PC accueil mairie »).', 422);
        $ids = array_values(array_filter(array_map('intval', (array)($b['connexions'] ?? []))));
        $id = (int)($b['id'] ?? 0);
        if ($id) {
            if (!$cxDepot->releveur($id)) json_error('PC releveur introuvable.', 404);
            $cxDepot->modifierReleveur($id, $nom, $ids, !array_key_exists('actif', $b) || filter_var($b['actif'], FILTER_VALIDATE_BOOLEAN), (string)$user['Login']);
        } else {
            $id = $cxDepot->creerReleveur($nom, $ids, (string)$user['Login']);
        }
        Connect::journal('info', "PC releveur « $nom » enregistré par {$user['Login']}");
        json_ok(['id' => $id]);
    }

    if ($action === 'connect_releveur_supprimer') {
        $user = require_auth(); require_role($user, $cxEcriture); $cxExigerActif();
        $b = $cxCorps();
        $r = $cxDepot->releveur((int)($b['id'] ?? 0));
        if (!$r) json_error('PC releveur introuvable.', 404);
        $cxDepot->supprimerReleveur((int)$r['Id']);
        Connect::journal('info', "PC releveur « {$r['Nom']} » supprimé par {$user['Login']} (son jeton ne fonctionne plus)");
        json_ok(['supprime' => true]);
    }

    /**
     * Le collecteur pour ce PC, prêt à l'emploi (zip). Chaque téléchargement pose un
     * NOUVEAU jeton : celui d'un paquet téléchargé avant cesse de fonctionner.
     */
    if ($action === 'connect_releveur_paquet') {
        $user = require_auth(); require_role($user, $cxEcriture); $cxExigerActif();
        $b = $cxCorps();
        $r = $cxDepot->releveur((int)($b['id'] ?? 0));
        if (!$r) json_error('PC releveur introuvable.', 404);
        // Le collecteur ouvre les clés Wi-Fi et les identifiants des appareils qu'il relève :
        // identité confirmée (comme pour agir sur un équipement), et trace de sécurité.
        if ((int)($_SESSION['connect_elevation'] ?? 0) < time()) {
            json_error('Confirmez votre identité : le collecteur donne accès aux clés Wi-Fi et aux identifiants des appareils qu\'il relève.', 428);
        }
        if (class_exists('SecurityLog')) SecurityLog::audit('connect_releveur_paquet', ['login' => (string)$user['Login'], 'tenant' => $cxOrg,
                                                                                         'target_action' => 'PC releveur n° ' . (int)$r['Id']]);
        $url = trim((string)($b['url'] ?? ''));
        if (!preg_match('~^https?://[^\s"<>]+$~i', $url)) $url = (string)APP_URL;
        $jeton = ConnectReleveur::nouveauJeton($cxOrg, (int)$r['Id']);
        try { $octets = ConnectReleveur::paquet($jeton, (string)$r['Nom'], $url); }
        catch (\Throwable $e) { json_error('Paquet impossible : ' . $e->getMessage(), 500); }
        $cxDepot->poserJetonReleveur((int)$r['Id'], $jeton);
        Connect::journal('info', "Collecteur du PC releveur « {$r['Nom']} » téléchargé par {$user['Login']} (nouveau jeton)");
        $nomFichier = 'larka-releveur-' . (preg_replace('/[^a-z0-9]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string)$r['Nom']) ?: 'pc')) ?: 'pc') . '.zip';
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . trim($nomFichier, '-') . '"');
        header('Content-Length: ' . strlen($octets));
        echo $octets;
        exit;
    }

    // ── Points ────────────────────────────────────────────────────────────
    if ($action === 'connect_points') {
        $user = require_auth(); $cxExigerActif();
        if ($method === 'GET') {
            require_role($user, $cxLecture);
            if ($id) { $p = $cxDepot->point($id); $p ? json_ok($p) : json_error('Point introuvable.', 404); }
            json_ok($cxDepot->points($cxInt($_GET['passerelle_id'] ?? null)));
        }
        require_role($user, $cxEcriture);
        if ($method === 'DELETE') {
            if (!$id || !$cxDepot->point($id)) json_error('Point introuvable.', 404);
            $cxDepot->supprimerPoint($id);
            json_ok('OK');
        }
        $b = $cxCorps();
        $existant = $method === 'PUT' ? ($id ? $cxDepot->point($id) : null) : null;
        if ($method === 'PUT' && !$existant) json_error('Point introuvable.', 404);
        [$v, $err] = $cxDepot->normaliserPoint($b, $existant);
        if ($err) json_error(implode("\n", $err), 422);
        if ($method === 'POST') json_ok(['id' => $cxDepot->creerPoint($v, (string)$user['Login'])]);
        $cxDepot->modifierPoint($id, $v, (string)$user['Login']);
        json_ok('OK');
    }

    /** Points proposés par la découverte, ajoutés en une fois. */
    if ($action === 'connect_points_importer') {
        $user = require_auth(); require_role($user, $cxEcriture); $cxExigerActif();
        $b = $cxCorps();
        $pid = (int)($b['passerelleId'] ?? 0);
        $crees = 0; $erreurs = [];
        foreach (array_slice((array)($b['points'] ?? []), 0, 500) as $i => $p) {
            if (!is_array($p)) continue;
            [$v, $err] = $cxDepot->normaliserPoint([
                'passerelleId' => $pid, 'nom' => $p['nom'] ?? '', 'code' => $p['code'] ?? '', 'nature' => $p['nature'] ?? 'mesure',
                'unite' => $p['unite'] ?? '', 'adresse' => is_array($p['adresse'] ?? null) ? $p['adresse'] : [], 'ordre' => $i,
            ]);
            if ($err) { $erreurs[] = ($p['nom'] ?? '?') . ' : ' . implode(' ', $err); continue; }
            $cxDepot->creerPoint($v, (string)$user['Login']);
            $crees++;
        }
        json_ok(['crees' => $crees, 'erreurs' => $erreurs]);
    }

    /** Plusieurs points supprimés en une fois (sélection dans la liste). */
    if ($action === 'connect_points_supprimer' && $method === 'POST') {
        $user = require_auth(); require_role($user, $cxEcriture); $cxExigerActif();
        $b = $cxCorps();
        $n = 0;
        foreach (array_slice(array_unique(array_map('intval', (array)($b['ids'] ?? []))), 0, 500) as $pid) {
            if ($pid > 0 && $cxDepot->point($pid)) { $cxDepot->supprimerPoint($pid); $n++; }
        }
        json_ok(['supprimes' => $n]);
    }

    // ── Détection d'un appareil hors réseau par le PC releveur ────────────
    //   GET  &id=                            ce que le PC a trouvé, réglages et points proposés
    //   POST {op: demander, id}              à regarder au prochain passage du PC
    //   POST {op: appliquer, id, reglages, supprimerPoints, points: […]}
    if ($action === 'connect_reconnaissance') {
        $user = require_auth(); $cxExigerActif();
        if ($method === 'GET') {
            require_role($user, $cxLecture);
            $pa = $id ? $cxDepot->passerelle($id) : null;
            if (!$pa) json_error('Connexion introuvable.', 404);
            [$rapport, $date, $demandee] = $cxDepot->reconLire($id);
            $an = ConnectReconnaissance::analyser($rapport, $cxDepot->points($id), $pa);
            json_ok(['date' => $date, 'demandee' => $demandee, 'protocole' => $pa['Protocole'], 'quand' => $rapport['quand'] ?? null,
                     'version' => $rapport['version'] ?? null, 'releveurs' => ConnectReleveur::releveursDe($cxDepot->db(), $id),
                     'version_collecteur' => ConnectReleveur::versionCollecteur()] + $an);
        }
        require_role($user, $cxEcriture);
        $b = $cxCorps();
        $pid = (int)($b['id'] ?? 0);
        $pa = $pid ? $cxDepot->passerelle($pid) : null;
        if (!$pa) json_error('Connexion introuvable.', 404);
        if (!ConnectDepot::enHotspot($cxDepot->db()->fetchOne("SELECT * FROM ConnectPasserelles WHERE Id = :id", ['id' => $pid]) ?: [])) {
            json_error('La détection par le PC releveur concerne les appareils hors réseau (hotspot ou câble direct).', 422);
        }
        $op = (string)($b['op'] ?? '');
        if ($op === 'demander') { $cxDepot->reconDemander($pid); json_ok('OK'); }
        if ($op !== 'appliquer') json_error('Opération inconnue.', 400);
        [$rapport] = $cxDepot->reconLire($pid);
        $an = ConnectReconnaissance::analyser($rapport, $cxDepot->points($pid), $pa);
        if (!empty($b['reglages'])) {
            if (!$an['suggestion']) json_error('Aucun réglage à appliquer : le PC n\'a rien trouvé d\'exploitable.', 422);
            [$ok, $err] = $cxDepot->reconAppliquer($pid, $an['suggestion'], !empty($b['supprimerPoints']), (string)$user['Login']);
            if (!$ok) json_error(implode("\n", $err), 422);
        }
        // Points cochés : seulement ceux que l'analyse propose (adresse reprise d'elle, pas du navigateur).
        $parCode = [];
        foreach ($an['points'] as $p) $parCode[$p['code']] = $p;
        $crees = 0; $erreurs = [];
        foreach (array_slice((array)($b['points'] ?? []), 0, ConnectReconnaissance::MAX_POINTS) as $i => $c) {
            if (!is_array($c) || !isset($parCode[(string)($c['code'] ?? '')])) continue;
            $p = $parCode[(string)$c['code']];
            [$v, $err] = $cxDepot->normaliserPoint([
                'passerelleId' => $pid, 'nom' => trim((string)($c['nom'] ?? '')) ?: $p['nom'], 'code' => $p['code'],
                'nature' => (string)($c['nature'] ?? $p['nature']), 'unite' => (string)($c['unite'] ?? $p['unite']),
                'adresse' => $p['adresse'], 'coefficient' => $p['coefficient'] ?? 1, 'ordre' => $i,
            ]);
            if ($err) { $erreurs[] = $p['nom'] . ' : ' . implode(' ', $err); continue; }
            $cxDepot->creerPoint($v, (string)$user['Login']);
            $crees++;
        }
        json_ok(['crees' => $crees, 'erreurs' => $erreurs, 'protocole' => ($cxDepot->passerelle($pid)['Protocole'] ?? '')]);
    }

    // ── Règles d'alarme ───────────────────────────────────────────────────
    if ($action === 'connect_regles') {
        $user = require_auth(); $cxExigerActif();
        if ($method === 'GET') {
            require_role($user, $cxLecture);
            $pid = $cxInt($_GET['point_id'] ?? null);
            $al = new ConnectAlarmes($cxDepot, $cxOrg);
            $regles = $cxDepot->regles($pid);
            $pts = [];
            foreach ($regles as &$r) {
                $k = (int)$r['PointId'];
                if (!array_key_exists($k, $pts)) $pts[$k] = $cxDepot->point($k);
                $pt = $pts[$k];
                $r['Description'] = $pt ? $al->texteRegle($pt, $r, $cxEn) : '';
                $r['PointNom'] = $pt['Nom'] ?? '';
                $r['PointUnite'] = $pt['Unite'] ?? '';
                $r['PasserelleNom'] = $pt['PasserelleNom'] ?? '';
                $r['BatimentNom'] = $pt['BatimentNom'] ?? null;
            }
            unset($r);
            json_ok($regles);
        }
        require_role($user, $cxEcriture);
        if ($method === 'DELETE') { if ($id) $cxDepot->supprimerRegle($id); json_ok('OK'); }
        $b = $cxCorps();
        $existante = $method === 'PUT' ? ($id ? $db->fetchOne("SELECT * FROM ConnectRegles WHERE Id = :id", ['id' => $id]) : null) : null;
        if ($method === 'PUT' && !$existante) json_error('Règle introuvable.', 404);
        [$v, $err] = $cxDepot->normaliserRegle($b, $existante);
        if ($err) json_error(implode("\n", $err), 422);
        json_ok(['id' => $cxDepot->enregistrerRegle($method === 'PUT' ? $id : null, $v, (string)$user['Login'])]);
    }

    // ── Liaisons et échéances ─────────────────────────────────────────────
    if ($action === 'connect_liaisons') {
        $user = require_auth(); $cxExigerActif();
        if ($method === 'GET') {
            require_role($user, $cxLecture);
            $liste = $cxDepot->liaisons($cxInt($_GET['point_id'] ?? null));
            if ($cxEn) foreach ($liste as &$x) $x['Description'] = ConnectPlanif::libelle($x, true);
            unset($x);
            json_ok($liste);
        }
        require_role($user, $cxEcriture);
        if ($method === 'DELETE') { if ($id) $cxDepot->supprimerLiaison($id); json_ok('OK'); }
        $b = $cxCorps();
        $existante = $method === 'PUT' ? ($id ? $cxDepot->liaison($id) : null) : null;
        if ($method === 'PUT' && !$existante) json_error('Liaison introuvable.', 404);
        [$v, $err] = $cxDepot->normaliserLiaison($b, $existante);
        if ($err) json_error(implode("\n", $err), 422);
        json_ok(['id' => $cxDepot->enregistrerLiaison($method === 'PUT' ? $id : null, $v, (string)$user['Login'])]);
    }

    if ($action === 'connect_liaison_apercu') {
        $user = require_auth(); require_role($user, $cxEcriture); $cxExigerActif();
        $b = $cxCorps();
        [$v, $err] = $cxDepot->normaliserLiaison($b, null);
        if ($v === null) json_error(implode("\n", $err), 422);
        $l = new ConnectLiaisons($cxDepot, new ConnectAlarmes($cxDepot, $cxOrg));
        $apercu = $l->apercu($v);
        if ($cxEn) $apercu['libelle'] = ConnectPlanif::libelle($v, true);
        json_ok($apercu + ['erreurs' => $err]);
    }

    if ($action === 'connect_executions' && $method === 'GET') {
        $user = require_auth(); require_role($user, $cxLecture); $cxExigerActif();
        $st = $_GET['statut'] ?? null;
        if ($st === 'attente') json_ok($cxDepot->executionsEnAttente());
        json_ok($cxDepot->executions($cxInt($_GET['liaison_id'] ?? null), (int)($_GET['limite'] ?? 200),
            in_array($st, ['anomalie', 'a_valider', 'manquant', 'automatique', 'rattrape', 'valide', 'rejete', 'erreur'], true) ? $st : null));
    }

    if ($action === 'connect_execution_valider' || $action === 'connect_execution_rejeter') {
        $user = require_auth(); require_role($user, $cxEcriture); $cxExigerActif();
        $b = $cxCorps();
        $l = new ConnectLiaisons($cxDepot, new ConnectAlarmes($cxDepot, $cxOrg));
        if ($action === 'connect_execution_valider' && !empty($b['liaison'])) {
            $r = $l->validerTout((int)$b['liaison'], (string)$user['Login']);
        } else {
            $r = $action === 'connect_execution_valider'
                ? $l->valider((int)($b['id'] ?? 0), isset($b['valeur']) && $b['valeur'] !== '' ? ConnectPiloteBase::nombre($b['valeur']) : null, (string)$user['Login'])
                : $l->rejeter((int)($b['id'] ?? 0), (string)$user['Login'], mb_substr(trim((string)($b['motif'] ?? '')), 0, 200));
        }
        $r['ok'] ? json_ok($r) : json_error($r['message'], 409);
    }

    // ── Historiques ───────────────────────────────────────────────────────
    if ($action === 'connect_sessions' && $method === 'GET') {
        $user = require_auth(); require_role($user, $cxLecture); $cxExigerActif();
        json_ok($cxDepot->sessionsVE($cxInt($_GET['passerelle_id'] ?? null), (int)($_GET['limite'] ?? 200)));
    }
    if ($action === 'connect_commandes' && $method === 'GET') {
        $user = require_auth(); require_role($user, $cxLecture); $cxExigerActif();
        json_ok($cxDepot->commandes((int)($_GET['limite'] ?? 100)));
    }
    if ($action === 'connect_fichiers' && $method === 'GET') {
        $user = require_auth(); require_role($user, $cxLecture); $cxExigerActif();
        json_ok($cxDepot->fichiers((int)($_GET['passerelle_id'] ?? 0)));
    }

    // ── Confirmation d'identité (avant toute écriture vers un équipement) ──
    // Valable X minutes, X réglé par le gestionnaire (api/IdentiteConfirmee.php).
    // Compte local : le mot de passe Larka. Microsoft / Google : la fenêtre du
    // fournisseur qui redemande le mot de passe (connect_identite_sso).
    // (Pas réservées au module ouvert : appairer un serveur de supervision la demande aussi.)
    if ($action === 'connect_confirmer_identite') {
        $user = require_auth(); require_role($user, $cxEcriture);
        $b = $cxCorps();
        if (IdentiteConfirmee::fournisseur($user) !== 'local') {
            json_error('Votre compte se confirme dans la fenêtre ' . ucfirst(IdentiteConfirmee::fournisseur($user)) . '.', 409);
        }
        $cle = 'connect_identite_' . strtolower((string)$user['Login']);
        check_rate_limit($cle, 5, 600, false);
        if (!is_string($b['motDePasse'] ?? null) || !$db->motDePasseInchange((string)$user['Login'], (string)$b['motDePasse'])) {
            rate_limit_noter($cle);
            if (class_exists('SecurityLog')) SecurityLog::audit('connect_reauth_failure', ['login' => (string)$user['Login'], 'tenant' => $cxOrg]);
            json_error('Mot de passe incorrect.', 403);
        }
        $s = IdentiteConfirmee::accorder(IdentiteConfirmee::minutes($db) * 60);
        if (class_exists('SecurityLog')) SecurityLog::audit('connect_reauth', ['login' => (string)$user['Login'], 'tenant' => $cxOrg]);
        json_ok(['secondes' => $s]);
    }

    /** Adresse de la fenêtre Microsoft / Google qui redemande le mot de passe. */
    if ($action === 'connect_identite_sso' && $method === 'POST') {
        $user = require_auth(); require_role($user, $cxEcriture);
        check_rate_limit('connect_identite_sso_' . strtolower((string)$user['Login']), 10, 600);
        try { json_ok(['url' => IdentiteConfirmee::urlSso($user, $db)]); }
        catch (\Throwable $e) { json_error($e->getMessage(), 409); }
    }

    /** Durée de validité de la confirmation : lue par tous les gestionnaires, réglée sous confirmation. */
    if ($action === 'connect_identite_duree') {
        $user = require_auth(); require_role($user, $cxEcriture);
        if ($method === 'POST') {
            if (IdentiteConfirmee::restant() <= 0) json_error('Confirmez votre identité pour changer ce réglage.', 428);
            $m = (int)($cxCorps()['minutes'] ?? 0);
            try { IdentiteConfirmee::regler($db, $m); } catch (\Throwable $e) { json_error($e->getMessage(), 422); }
            if (class_exists('SecurityLog')) SecurityLog::audit('connect_reauth_duree', ['login' => (string)$user['Login'], 'tenant' => $cxOrg,
                                                                                       'target_action' => 'confirmation d\'identité valable ' . $m . ' min']);
        } elseif ($method !== 'GET') {
            json_error('Méthode non supportée.', 405);
        }
        json_ok(['minutes' => IdentiteConfirmee::minutes($db), 'durees' => IdentiteConfirmee::DUREES,
                 'restant' => IdentiteConfirmee::restant(), 'fournisseur' => IdentiteConfirmee::fournisseur($user)]);
    }

    $cxExigerIdentite = function (array $user): void {
        if ((int)($_SESSION['connect_elevation'] ?? 0) < time()) {
            json_error('Confirmez votre identité avant d\'agir sur un équipement.', 428);
        }
    };

    /**
     * Trace de sécurité d'une écriture vers un équipement (réussie ou non).
     * Le journal de sécurité ne garde que des clés connues (SecurityLog::ALLOWED_CONTEXT_KEYS) :
     * l'objet de l'écriture va dans « target_action », le résultat dans « reason ».
     */
    $cxAuditer = function (array $user, string $quoi, bool $succes, string $resultat) use ($cxOrg): void {
        if (class_exists('SecurityLog')) {
            SecurityLog::audit('connect_ecriture', ['login' => (string)$user['Login'], 'role' => (string)$user['Role'], 'tenant' => $cxOrg,
                'action' => defined('LARKA_ACTION_COURANTE') ? LARKA_ACTION_COURANTE : 'connect', 'target_action' => mb_substr($quoi, 0, 200),
                'success' => $succes, 'reason' => mb_substr($resultat, 0, 200)]);
        }
    };

    if ($action === 'connect_ecrire') {
        $user = require_auth(); require_role($user, $cxEcriture); $cxExigerActif(); $cxExigerIdentite($user);
        $b = $cxCorps();
        $pt = $cxDepot->point((int)($b['pointId'] ?? 0));
        if (!$pt) json_error('Point introuvable.', 404);
        $unite = trim((string)($pt['Unite'] ?? ''));
        $quoi = "point {$pt['Id']} « {$pt['Nom']} » (connexion « {$pt['PasserelleNom']} »)";
        // Une tentative refusée laisse aussi sa trace : c'est elle qu'on cherche après coup.
        if (!(int)$pt['EcritureAutorisee']) {
            $cxAuditer($user, $quoi, false, 'refusé : point non déclaré inscriptible');
            json_error('Ce point n\'est pas déclaré inscriptible.', 403);
        }
        $relacher = !empty($b['relacher']);
        $valeur = $relacher ? null : ConnectPiloteBase::nombre($b['valeur'] ?? null);
        if (!$relacher) {
            if ($valeur === null) json_error('Valeur non numérique.', 422);
            if (($pt['MinEcriture'] !== null && $valeur < (float)$pt['MinEcriture']) || ($pt['MaxEcriture'] !== null && $valeur > (float)$pt['MaxEcriture'])) {
                $plage = ConnectAlarmes::nombreFr((float)$pt['MinEcriture']) . ' à ' . ConnectAlarmes::nombreFr((float)$pt['MaxEcriture']) . ($unite !== '' ? " $unite" : '');
                $cxAuditer($user, "$quoi : " . ConnectAlarmes::nombreFr($valeur, 6) . ($unite !== '' ? " $unite" : ''), false, "refusé : hors de la plage permise ($plage)");
                json_error("Valeur hors de la plage permise ($plage).", 422);
            }
        }
        $pa = $db->fetchOne("SELECT * FROM ConnectPasserelles WHERE Id = :id", ['id' => (int)$pt['PasserelleId']]);
        if (!$pa || !(int)$pa['Actif']) json_error('Connexion désactivée.', 409);
        $pdo = $db->getPdo();
        $pdo->prepare("INSERT INTO ConnectCommandes (PasserelleId, PointId, Commande, Valeur, Statut, Demandeur, DateDemande)
                       VALUES (?, ?, ?, ?, 'en_cours', ?, ?)")
            ->execute([(int)$pa['Id'], (int)$pt['Id'], $relacher ? 'relacher' : 'ecrire', $relacher ? null : (string)$valeur, (string)$user['Login'], date('Y-m-d H:i:s')]);
        $cmd = (int)$pdo->lastInsertId();
        try {
            $pilote = ConnectPilotes::creer($cxDepot->connexionPilote($pa, $cxOrg));
            $r = $pilote->ecrire($pt, $relacher ? null : $valeur);
            $pilote->fermer();
        } catch (\Throwable $e) {
            $r = ['ok' => false, 'message' => 'Erreur : ' . $e->getMessage()];
        }
        $pdo->prepare("UPDATE ConnectCommandes SET Statut = ?, DateExecution = ?, Reponse = ? WHERE Id = ?")
            ->execute([$r['ok'] ? 'reussie' : 'echec', date('Y-m-d H:i:s'), mb_substr((string)$r['message'], 0, 300), $cmd]);
        $ancienne = $pt['DerniereValeur'];
        $cxAuditer($user, "$quoi : "
            . ($relacher ? 'relâcher la commande' : (ConnectAlarmes::nombreFr($valeur, 6) . ($unite !== '' ? " $unite" : '')))
            . ' — valeur précédente ' . ConnectAlarmes::nombreFr($ancienne === null ? null : (float)$ancienne, 6), (bool)$r['ok'], (string)$r['message']);
        Connect::journal($r['ok'] ? 'info' : 'avertissement', ($r['ok'] ? 'Écriture' : 'Écriture refusée') . " sur « {$pt['Nom']} » par {$user['Login']} : "
            . ($relacher ? 'relâcher' : ConnectAlarmes::nombreFr($valeur) . ($unite !== '' ? " $unite" : '')) . ' — ' . $r['message']);
        // La valeur affichée suit tout de suite : relecture de ce seul point.
        if ($r['ok']) {
            try {
                $p2 = ConnectPilotes::creer($cxDepot->connexionPilote($pa, $cxOrg));
                $lu = $p2->lire([$pt]);
                $p2->fermer();
                if ($lu) $cxDepot->enregistrerLectures([(int)$pt['Id'] => $pt], $lu);
            } catch (\Throwable $e) { /* la prochaine collecte rattrapera */ }
        }
        $r['ok'] ? json_ok($r) : json_error($r['message'], 502);
    }

    if ($action === 'connect_commande_borne') {
        $user = require_auth(); require_role($user, $cxEcriture); $cxExigerActif(); $cxExigerIdentite($user);
        $b = $cxCorps();
        $pa = $db->fetchOne("SELECT * FROM ConnectPasserelles WHERE Id = :id AND Protocole = 'ocpp'", ['id' => (int)($b['passerelleId'] ?? 0)]);
        if (!$pa) json_error('Borne introuvable.', 404);
        $commande = (string)($b['commande'] ?? '');
        if (!array_key_exists($commande, ConnectPiloteOcpp::COMMANDES)) json_error('Commande inconnue.', 422);
        if (!ConnectPiloteOcpp::etatService()['en_marche']) json_error('Le service OCPP ne tourne pas : la commande ne peut pas être transmise.', 409);
        $pdo = $db->getPdo();
        $pdo->prepare("INSERT INTO ConnectCommandes (PasserelleId, Commande, Valeur, Statut, Demandeur, DateDemande) VALUES (?, ?, ?, 'en_attente', ?, ?)")
            ->execute([(int)$pa['Id'], $commande, mb_substr(trim((string)($b['valeur'] ?? '')), 0, 40) ?: null, (string)$user['Login'], date('Y-m-d H:i:s')]);
        $cmd = (int)$pdo->lastInsertId();
        // Le service OCPP relève la file chaque seconde ; on attend sa réponse
        // (14 s au plus : l'écran abandonne une requête au bout de 20 s).
        if (function_exists('session_write_close')) session_write_close();
        $fin = microtime(true) + 14;
        $row = null;
        while (microtime(true) < $fin) {
            usleep(400000);
            $row = $db->fetchOne("SELECT Statut, Reponse FROM ConnectCommandes WHERE Id = :id", ['id' => $cmd]);
            if ($row && in_array($row['Statut'], ['reussie', 'echec', 'expiree'], true)) break;
        }
        $statut = $row['Statut'] ?? 'en_attente';
        $cxAuditer($user, "borne « {$pa['Nom']} » : $commande", $statut === 'reussie', $statut . ' — ' . (string)($row['Reponse'] ?? ''));
        Connect::journal('info', "Commande « " . ConnectPiloteOcpp::COMMANDES[$commande] . " » sur la borne « {$pa['Nom']} » par {$user['Login']} : $statut");
        if ($statut === 'reussie') json_ok(['ok' => true, 'message' => (string)$row['Reponse']]);
        if (in_array($statut, ['echec', 'expiree'], true)) json_error('Commande non exécutée : ' . (string)$row['Reponse'], 502);
        json_ok(['ok' => false, 'en_cours' => true, 'message' => 'Commande transmise, sans réponse de la borne pour l\'instant : suivez-la dans l\'historique des commandes.']);
    }

    json_error("Action inconnue : $action", 404);
}

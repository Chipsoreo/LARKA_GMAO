<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * ═══════════════════════════════════════════════════════════════════════════
 *  Larka Connect — démonstration : bâtiments simulés, avec leur historique
 * ═══════════════════════════════════════════════════════════════════════════
 *
 *  Les simulateurs Python (outils/simulateurs/) tournent en temps réel : pour
 *  voir une courbe sur un mois ou des relevés automatiques s'enchaîner, il
 *  fallait attendre un mois. Ce script pose en quelques secondes :
 *
 *    - deux connexions « Simulateur intégré » (Hôtel de Ville : bureaux ;
 *      Groupe scolaire : calendrier scolaire), rattachées aux bâtiments de la
 *      démonstration s'ils existent — 14 points : énergie, eau, chaleur,
 *      températures, CO₂, humidité, marche chaudière, consigne pilotable ;
 *    - N jours d'HISTORIQUE (60 par défaut, 400 au plus) : courbes complètes ;
 *    - des règles d'alarme, et les alarmes PASSÉES qu'elles auraient levées
 *      (panne de chauffage, salle surpeuplée…), terminées et acquittées ;
 *    - des liaisons vers les compteurs du module Énergie : les relevés
 *      automatiques des N jours sont RATTRAPÉS par le moteur des liaisons,
 *      exactement comme après un arrêt du serveur. Sur les 21 derniers jours
 *      (--releves=N), la télérelève remplace les relevés et factures saisis de
 *      ces compteurs : ils sont mis de côté (data/connect/simulation/) et remis
 *      par --retirer. Les index simulés prolongent le relevé qui précède, au
 *      rythme de la consommation passée du compteur : aucune « anomalie »
 *      artificielle. --releves=0 : rien n'est mis de côté, les relevés
 *      automatiques commencent après le dernier relevé existant.
 *
 *  Ensuite la collecte habituelle (chaque minute) continue les courbes en
 *  temps réel. Rien n'est envoyé sur le réseau.
 *
 *    php api/outils/connect-demo.php                 installe (60 jours d'historique)
 *    php api/outils/connect-demo.php --jours=180     … avec 180 jours
 *    php api/outils/connect-demo.php --activer       active aussi Larka Connect (config.json : connect.actif)
 *    php api/outils/connect-demo.php --refaire       retire puis réinstalle
 *    php api/outils/connect-demo.php --retirer       retire tout ce que ce script a créé
 *    php api/outils/connect-demo.php --organisation=CLE   (multi-organisation)
 *  Raccourcis : ./start.sh connect-demo …   ·   Larka.cmd connect-demo …
 *
 *  Code de sortie : 0 fait (ou déjà en place), 1 échec, 2 usage.
 * ═══════════════════════════════════════════════════════════════════════════
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('LARKA_SANS_SESSION', true);
$racine = dirname(__DIR__, 2);
chdir($racine);
require_once $racine . '/api/config.php';
require_once $racine . '/api/SecurityLog.php';
require_once $racine . '/api/Journal.php';
require_once $racine . '/api/Database.php';
require_once $racine . '/api/connect/Connect.php';
Connect::charger();

set_exception_handler(function (\Throwable $e): void {
    fwrite(STDERR, "✗ Démonstration Larka Connect interrompue : " . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n");
    exit(1);
});
$args = array_slice($argv, 1);
$opt = function (string $nom) use ($args): ?string {
    foreach ($args as $a) if (str_starts_with($a, "--$nom=")) return substr($a, strlen($nom) + 3);
    return null;
};
$a = fn(string $f) => in_array("--$f", $args, true);
$jours = max(1, min(400, (int)($opt('jours') ?? 60)));
// Relevés automatiques : sur les N derniers jours, la télérelève remplace les relevés saisis à la main des
// compteurs raccordés (mis de côté, remis par --retirer). 0 : seulement après le dernier relevé existant.
$joursReleves = max(0, min($jours, (int)($opt('releves') ?? 21)));
// Des équipements fictifs, et des relevés saisis mis de côté : pas sur un serveur en service.
if (APP_ENV === 'prod' && !$a('retirer') && !$a('meme-en-production')) {
    fwrite(STDERR, "Serveur de production (config.json : serveur.env = prod) : la démonstration Larka Connect est réservée aux postes\n"
                 . "de démonstration, de formation et de développement. Pour une connexion d'essai, créez un « Simulateur intégré »\n"
                 . "dans Bâtiments → Connexions (rien n'y est mis de côté).\n");
    exit(2);
}
const MARQUE = 'Démonstration Larka Connect';
const LOGIN = 'demo.connect';
const NUMERO_COMPTEUR = 'SIMULATION';

// ── Activation côté serveur (facultative) ───────────────────────────────────
if ($a('activer') && !Connect::actifServeur()) {
    $f = $racine . '/config.json';
    $sortie = []; $rc = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($racine . '/api/outils/config-json.php') . ' ecrire '
         . escapeshellarg($f) . ' connect.actif=true 2>&1', $sortie, $rc);
    $activeIci = $rc === 0;
    echo $rc === 0 ? "✓ Larka Connect activé sur ce serveur (config.json : connect.actif = true).\n"
                   : "⚠ Activation impossible : " . implode(' ', $sortie) . "\n";
}

// ── Organisation ────────────────────────────────────────────────────────────
$orgs = Connect::organisations();
$cle = $opt('organisation');
$org = null;
foreach ($orgs as $o) if ($cle === null || $o['cle'] === $cle) { $org = $o; break; }
if (!$org) { fwrite(STDERR, "Organisation introuvable" . ($cle ? " : $cle" : '') . ".\n"); exit(2); }
try { $db = $org['base'] === null ? new Database() : new Database($org['base']); }
catch (\Throwable $e) { fwrite(STDERR, "Base injoignable : " . $e->getMessage() . "\n"); exit(1); }
$db->initConnectTables();
$depot = new ConnectDepot($db);
$pdo = $db->getPdo();

$existantes = $db->fetchAll("SELECT * FROM ConnectPasserelles WHERE Protocole = 'simulation' AND Description LIKE :m", ['m' => MARQUE . '%']);

// ── Retrait ─────────────────────────────────────────────────────────────────
if ($a('retirer') || $a('refaire')) {
    [$n, $remis] = retirer($db, $existantes, $org['cle']);
    echo $n ? "✓ Démonstration Larka Connect retirée ($n connexion(s), leurs points, mesures, alarmes, liaisons et relevés)"
              . ($remis ? ", $remis relevé(s) et facture(s) saisis remis en place" : '') . ".\n"
            : ($remis ? "✓ $remis relevé(s) et facture(s) saisis remis en place.\n" : "Rien à retirer.\n");
    if ($a('retirer')) exit(0);
    $existantes = [];
}
if ($existantes) {
    echo "La démonstration Larka Connect est déjà en place (" . count($existantes) . " connexion(s)). Pour la refaire : --refaire\n";
    exit(0);
}

$t0 = microtime(true);
$maintenant = time();
$debut = $maintenant - $jours * 86400;
echo "Larka Connect — démonstration : $jours jour(s) d'historique, organisation « {$org['nom']} »…\n";

// ── Ce qu'on trouve dans la base (démonstration Larkaville ou installation vierge) ──
$batiment = fn(string $motif) => $db->fetchOne("SELECT Id, Nom FROM PlanBatiments WHERE Nom LIKE :m ORDER BY Id", ['m' => $motif]);
$compteur = function (string $site, string $type) use ($db): ?array {
    return $db->fetchOne("SELECT * FROM CompteurEnergie WHERE Type = :t AND (Site LIKE :s OR Nom LIKE :n) AND COALESCE(Actif, 1) = 1
                          AND COALESCE(NumeroCompteur, '') <> :sim ORDER BY Id",
                         ['t' => $type, 's' => '%' . $site . '%', 'n' => '%' . $site . '%', 'sim' => NUMERO_COMPTEUR])
        ?? $db->fetchOne("SELECT * FROM CompteurEnergie WHERE Type = :t AND NumeroCompteur = :sim AND Site LIKE :s ORDER BY Id",
                         ['t' => $type, 's' => '%' . $site . '%', 'sim' => NUMERO_COMPTEUR]);
};
$equipement = fn(string $motif, string $site) => $db->fetchOne(
    "SELECT Id FROM Equipements WHERE DateSuppression IS NULL AND (InfoProduit LIKE :m1 OR Famille LIKE :m2 OR SousFamille LIKE :m3)
       AND Batiment LIKE :s ORDER BY Id", ['m1' => $motif, 'm2' => $motif, 'm3' => $motif, 's' => '%' . $site . '%']);

$sites = [
    ['nom' => 'Hôtel de Ville', 'cherche' => 'Hôtel de Ville', 'occupation' => 'bureaux', 'points' => [
        ['Index électrique général', 'index_electrique', 'compteur', 'Electricite', 1],
        ['Puissance appelée', 'puissance', 'mesure', 'Electricite', 1],
        ['Index eau', 'index_eau', 'compteur', 'Eau', 2],
        ['Débit d\'eau', 'debit_eau', 'mesure', 'Eau', 3],
        ['Index chaleur (sous-station)', 'index_chaleur', 'compteur', 'Chauffage', 3],
        ['Température extérieure', 'temperature_exterieure', 'mesure', null, 1],
        ['Température bureau R+1', 'temperature_interieure', 'mesure', null, 1],
        ['Consigne de chauffage', 'consigne', 'consigne', null, 1],
        ['Marche chauffage (sous-station)', 'etat_chaudiere', 'etat', null, 0],
        ['CO₂ salle du conseil', 'co2', 'mesure', null, 0],
        ['Humidité salle du conseil', 'humidite', 'mesure', null, 0],
    ]],
    ['nom' => 'Groupe scolaire', 'cherche' => 'Groupe scolaire', 'occupation' => 'ecole', 'points' => [
        ['Index électrique', 'index_electrique', 'compteur', 'Electricite', 1],
        ['Température classe CM2', 'temperature_interieure', 'mesure', null, 1],
        ['CO₂ classe CM2', 'co2', 'mesure', null, 0],
    ]],
];

$calendriers = [
    'index_electrique' => ['frequence' => 'quotidien', 'heure' => '23:55'],
    'index_eau'        => ['frequence' => 'hebdomadaire', 'heure' => '07:00', 'joursSemaine' => '1'],
    'index_chaleur'    => ['frequence' => 'hebdomadaire', 'heure' => '06:00', 'joursSemaine' => '1'],
];
$stats = ['mis_de_cote' => 0, 'connexions' => 0, 'points' => 0, 'mesures' => 0, 'regles' => 0, 'alarmes' => 0, 'liaisons' => 0, 'releves' => 0, 'compteurs' => 0];
$liaisonsCreees = [];
$alarmes = new ConnectAlarmes($depot, $org['cle']);

foreach ($sites as $site) {
    $bat = $batiment('%' . $site['cherche'] . '%');
    $nomSite = $bat['Nom'] ?? $site['nom'];
    [$v, $err] = $depot->normaliserPasserelle([
        'protocole' => 'simulation', 'nom' => 'Simulateur — ' . $nomSite, 'intervalle' => 300,
        'batimentId' => $bat['Id'] ?? null,
        'description' => MARQUE . ' — équipements simulés par Larka (php api/outils/connect-demo.php --retirer pour les enlever).',
        'parametres' => ['historique_jours' => $jours, 'occupation' => $site['occupation'], 'incidents' => true],
    ]);
    if ($err) { fwrite(STDERR, "Connexion refusée : " . implode(' ', $err) . "\n"); exit(1); }
    $paId = $depot->creerPasserelle($v, LOGIN);
    $stats['connexions']++;
    $graine = 1;
    $ids = [];
    foreach ($site['points'] as $ordre => [$nom, $profil, $nature, $typeCompteur, $decimales]) {
        $adresse = ['profil' => $profil, 'graine' => $graine++];
        $unite = ConnectPiloteSimulation::PROFILS[$profil][1];
        $cpt = null;
        // Index : calé sur le compteur du module Énergie (unité, rythme, dernier relevé).
        if ($typeCompteur !== null) {
            $cpt = $compteur($site['cherche'], $typeCompteur);
            if (!$cpt) {
                $cpt = creerCompteur($db, $typeCompteur, $nomSite);
                $stats['compteurs']++;
            }
            if (in_array($profil, ['index_electrique', 'index_eau', 'index_chaleur', 'puissance', 'debit_eau'], true)) {
                [$adresse['conso_jour'], $unite] = calibrer($db, $cpt, $profil, $paId, $site['occupation']);
                if ($profil === 'puissance') $unite = 'kW';
                if ($profil === 'debit_eau') $unite = $unite . '/h';
            }
        }
        $eq = null;
        if ($profil === 'etat_chaudiere' || $profil === 'index_chaleur') $eq = $equipement('%haudi%', $site['cherche'])['Id'] ?? null;
        if ($profil === 'co2' || $profil === 'humidite') $eq = $equipement('%entilation%', $site['cherche'])['Id'] ?? null;
        $estConsigne = $profil === 'consigne';
        [$pv, $perr] = $depot->normaliserPoint([
            'passerelleId' => $paId, 'nom' => $nom, 'code' => $profil, 'nature' => $nature, 'unite' => $unite,
            'adresse' => $adresse, 'decimales' => $decimales, 'bandeMorte' => 0, 'equipementId' => $eq, 'ordre' => $ordre,
            'ecritureAutorisee' => $estConsigne, 'minEcriture' => $estConsigne ? 16 : null, 'maxEcriture' => $estConsigne ? 23 : null,
        ]);
        if ($perr) { fwrite(STDERR, "Point refusé ($nom) : " . implode(' ', $perr) . "\n"); exit(1); }
        $pid = $depot->creerPoint($pv, LOGIN);
        $ids[$profil] = ['id' => $pid, 'compteur' => $cpt, 'nom' => $nom];
        $stats['points']++;
    }

    // Index de départ : le simulateur prolonge le dernier relevé du compteur.
    foreach ($ids as $profil => $p) {
        if (!ConnectPiloteSimulation::estCompteur($profil) || !$p['compteur']) continue;
        // Compteur qui recevra les relevés automatiques : ses lignes des derniers jours sont mises de côté,
        // la télérelève les remplace (sinon une facture datée d'hier bloquerait le rattrapage).
        if ($joursReleves > 0 && isset($calendriers[$profil]) && !lieAilleurs($db, (int)$p['compteur']['Id'])) {
            $stats['mis_de_cote'] += mettreDeCote($db, (int)$p['compteur']['Id'], date('Y-m-d', $maintenant - $joursReleves * 86400), $org['cle']);
        }
        $pt = $depot->point($p['id']);
        $m = ConnectPiloteSimulation::modeleDe(['passerelle' => $paId, 'occupation' => $site['occupation'], 'incidents' => true], $pt['Adresse'], $p['id']);
        $dernier = $db->fetchOne("SELECT IndexFin, Date FROM ReleverEnergie WHERE CompteurId = :c AND IndexFin IS NOT NULL ORDER BY Date DESC, Id DESC LIMIT 1",
                                 ['c' => (int)$p['compteur']['Id']]);
        $depart = (int)(floor($debut / ConnectPiloteSimulation::PAS) * ConnectPiloteSimulation::PAS);
        $initial = 0.0;
        if ($dernier) {
            $tD = (int)strtotime((string)$dernier['Date'] . ' 23:55:00');
            $initial = (float)$dernier['IndexFin'] - ($tD >= $depart ? ConnectPiloteSimulation::integrale($m, $depart, $tD)
                                                                      : -ConnectPiloteSimulation::integrale($m, $tD, $depart));
            $initial = max(0.0, $initial);
        }
        $adr = $pt['Adresse']; $adr['index_initial'] = round($initial, 3);
        $pdo->prepare("UPDATE ConnectPoints SET Adresse = ? WHERE Id = ?")->execute([json_encode($adr), $p['id']]);
    }

    // ── Historique : une lecture du simulateur produit toute la période ──
    $pa = $depot->passerelle($paId);
    $points = [];
    foreach ($depot->points($paId, true) as $pt) $points[(int)$pt['Id']] = $pt;
    $pilote = ConnectPilotes::creer($depot->connexionPilote($db->fetchOne("SELECT * FROM ConnectPasserelles WHERE Id = :id", ['id' => $paId]), $org['cle']));
    $lectures = $pilote->lire(array_values($points));
    $s = $depot->enregistrerLectures($points, $lectures);
    $stats['mesures'] += $s['historisees'];
    $depot->noterEtatPasserelle($paId, 'ok', null, 0, date('Y-m-d H:i:s', $maintenant + 300), true);

    // ── Règles, et les alarmes qu'elles auraient levées sur la période ──
    $sans = ['creerDemande' => false, 'notifier' => false];
    $regles = [
        // Panne de chauffage : la pièce passe sous 16 °C (la nuit et le week-end, le réduit la tient vers 17,5 °C).
        'temperature_interieure' => ['operateur' => 'inf', 'seuil' => 16, 'seuil2' => 16.5, 'delaiMinutes' => 30, 'gravite' => 'mineure'] + $sans,
        'co2' => $site['occupation'] === 'ecole'
            // Classe confinée : une demande d'intervention est ouverte (ventilation à contrôler).
            ? ['operateur' => 'sup', 'seuil' => 1500, 'seuil2' => 1200, 'delaiMinutes' => 15, 'gravite' => 'majeure', 'creerDemande' => true, 'notifier' => false]
            : ['operateur' => 'sup', 'seuil' => 1300, 'seuil2' => 1100, 'delaiMinutes' => 15, 'gravite' => 'majeure'] + $sans,
        'temperature_exterieure' => ['operateur' => 'absence', 'delaiMinutes' => 60, 'gravite' => 'mineure'] + $sans,
    ];
    foreach ($regles as $profil => $r) {
        if (!isset($ids[$profil])) continue;
        [$rv, $rerr] = $depot->normaliserRegle(['pointId' => $ids[$profil]['id']] + $r);
        if ($rerr) continue;
        $rid = $depot->enregistrerRegle(null, $rv, LOGIN);
        $stats['regles']++;
        if ($r['operateur'] !== 'absence') $stats['alarmes'] += alarmesPassees($db, $depot, $alarmes, $ids[$profil]['id'], $rid, $maintenant);
    }
    // État présent des règles (une alarme en cours reste ouverte, comme en service).
    foreach ($s['valeurs'] as $pid => $lecture) {
        try { $alarmes->evaluerPoint($points[$pid], $lecture); } catch (\Throwable $e) {}
    }

    // ── Liaisons vers le module Énergie : relevés automatiques ──
    foreach ($calendriers as $profil => $cal) {
        if (empty($ids[$profil]['compteur'])) continue;
        $cpt = $ids[$profil]['compteur'];
        // Un compteur déjà relevé par une autre liaison n'en reçoit pas une seconde (deux index concurrents).
        if ($autre = lieAilleurs($db, (int)$cpt['Id'])) { echo "  « {$cpt['Nom']} » est déjà relevé par la liaison « {$autre['Nom']} » : pas de relevé automatique simulé.\n"; continue; }
        [$lv, $lerr] = $depot->normaliserLiaison(['pointId' => $ids[$profil]['id'], 'destination' => 'releve_energie', 'cibleId' => (int)$cpt['Id'],
            'nom' => $ids[$profil]['nom'] . ' → ' . $cpt['Nom'], 'toleranceMinutes' => 20, 'facteurAnomalie' => 5] + $cal);
        if ($lerr) { echo "⚠ Liaison « {$ids[$profil]['nom']} » non créée : " . implode(' ', $lerr) . "\n"; continue; }
        $lid = $depot->enregistrerLiaison(null, $lv, LOGIN);
        // Première échéance : après le début de l'historique ET après le dernier relevé du compteur.
        $dernier = $db->fetchOne("SELECT MAX(Date) AS D FROM ReleverEnergie WHERE CompteurId = :c", ['c' => (int)$cpt['Id']])['D'] ?? null;
        $apres = max($debut + 3600, $dernier ? (int)strtotime($dernier . ' 23:59:59') : 0);
        $prem = ConnectPlanif::prochaine($lv, (new DateTimeImmutable())->setTimestamp($apres));
        $pdo->prepare("UPDATE ConnectLiaisons SET ProchaineExecution = ? WHERE Id = ?")
            ->execute([$prem ? $prem->format('Y-m-d H:i:s') : null, $lid]);
        $liaisonsCreees[] = $lid;
        $stats['liaisons']++;
    }
}

// ── Rattrapage des relevés : le moteur des liaisons, comme après un arrêt ──
if ($liaisonsCreees) {
    $moteur = new ConnectLiaisons($depot, $alarmes);
    for ($i = 0; $i < 40; $i++) {
        $avant = $moteur->stats['echeances'];
        $moteur->executerDues(null, 500);
        if ($moteur->stats['echeances'] === $avant) break;
    }
    $stats['releves'] = $moteur->stats['releves'];
    if ($moteur->stats['anomalies']) echo "  (" . $moteur->stats['anomalies'] . " échéance(s) en anomalie : à valider dans Connect → Relevés automatiques)\n";
}

if ($stats['mis_de_cote']) echo "  {$stats['mis_de_cote']} relevé(s) et facture(s) saisis des $joursReleves derniers jours mis de côté : la télérelève les remplace (--retirer les remet).\n";
printf("✓ %d connexion(s) simulée(s), %d point(s), %s mesure(s) sur %d jour(s), %d règle(s), %d alarme(s) passée(s), %d liaison(s), %d relevé(s) automatique(s)%s — %.1f s\n",
    $stats['connexions'], $stats['points'], number_format($stats['mesures'], 0, ',', ' '), $jours, $stats['regles'], $stats['alarmes'],
    $stats['liaisons'], $stats['releves'], $stats['compteurs'] ? ", {$stats['compteurs']} compteur(s) créé(s)" : '', microtime(true) - $t0);
if (!Connect::actifServeur() && empty($activeIci)) {
    echo "ℹ Larka Connect est désactivé sur ce serveur : les écrans et la collecte s'ouvrent avec connect.actif = true (relancez avec --activer).\n";
} else {
    echo "  Les courbes continuent en temps réel avec la collecte habituelle (chaque minute). Écran : Bâtiments → Supervision.\n";
}
exit(0);

// ═════════════════════════════════════════════════════════════════════════════

/** Liaison active (hors démonstration) qui relève déjà ce compteur, ou null. */
function lieAilleurs(Database $db, int $compteurId): ?array
{
    return $db->fetchOne("SELECT l.Nom FROM ConnectLiaisons l JOIN ConnectPoints p ON p.Id = l.PointId
                            JOIN ConnectPasserelles g ON g.Id = p.PasserelleId
                           WHERE l.Destination = 'releve_energie' AND l.CibleId = :c AND l.Actif = 1
                             AND NOT (g.Protocole = 'simulation' AND g.Description LIKE :m)", ['c' => $compteurId, 'm' => MARQUE . '%']);
}

function fichierMisDeCote(string $organisation): string
{
    $d = dirname(__DIR__, 2) . '/data/connect/simulation';
    if (!is_dir($d)) @mkdir($d, 0770, true);
    return $d . '/mis-de-cote~' . preg_replace('/[^A-Za-z0-9_-]/', '_', $organisation) . '~releves.json';
}

/** Met de côté (fichier JSON) les relevés et factures du compteur datés après $depuis ; rend leur nombre. */
function mettreDeCote(Database $db, int $compteurId, string $depuis, string $organisation): int
{
    $rows = $db->fetchAll("SELECT * FROM ReleverEnergie WHERE CompteurId = :c AND Date > :d ORDER BY Id", ['c' => $compteurId, 'd' => $depuis]);
    if (!$rows) return 0;
    $f = fichierMisDeCote($organisation);
    $deja = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    if (file_put_contents($f, json_encode(array_merge($deja, $rows), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) === false) {
        throw new RuntimeException("impossible d'écrire $f : relevés laissés en place");
    }
    $db->getPdo()->prepare("DELETE FROM ReleverEnergie WHERE CompteurId = ? AND Date > ?")->execute([$compteurId, $depuis]);
    return count($rows);
}

/** Remet les relevés mis de côté (même identifiant) ; rend leur nombre. */
function remettre(Database $db, string $organisation): int
{
    $f = fichierMisDeCote($organisation);
    if (!is_file($f)) return 0;
    $rows = json_decode((string)file_get_contents($f), true);
    if (!is_array($rows)) return 0;
    $pdo = $db->getPdo();
    $n = 0;
    foreach ($rows as $r) {
        if (!is_array($r) || !isset($r['Id'])) continue;
        if ($db->fetchOne("SELECT Id FROM ReleverEnergie WHERE Id = :id", ['id' => (int)$r['Id']])) continue;
        $cols = array_keys($r);
        $pdo->prepare("INSERT INTO ReleverEnergie (" . implode(', ', $cols) . ") VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ")")
            ->execute(array_values($r));
        $n++;
    }
    @unlink($f);
    return $n;
}

/** Compteur du module Énergie dédié à la démonstration, quand la base n'en a pas. */
function creerCompteur(Database $db, string $type, string $site): array
{
    $unites = ['Electricite' => 'kWh', 'Eau' => 'm³', 'Chauffage' => 'MWh', 'Gaz' => 'kWh'];
    $noms = ['Electricite' => 'Électricité', 'Eau' => 'Eau', 'Chauffage' => 'Chauffage', 'Gaz' => 'Gaz'];
    $nom = ($noms[$type] ?? $type) . ' — ' . $site . ' (télérelève)';
    $db->addEnergieCompteur(['nom' => $nom, 'type' => $type, 'unite' => $unites[$type] ?? '', 'site' => $site,
                             'numeroCompteur' => NUMERO_COMPTEUR, 'actif' => 1]);
    $c = $db->fetchOne("SELECT * FROM CompteurEnergie WHERE Nom = :n AND NumeroCompteur = :s ORDER BY Id DESC", ['n' => $nom, 's' => NUMERO_COMPTEUR]);
    if (!$c) throw new RuntimeException("compteur « $nom » non créé");
    return $c;
}

/**
 * Consommation journalière du point d'après les relevés périodiques du compteur
 * (médiane des 12 derniers, comme le contrôle d'anomalie des liaisons), pour que
 * les index simulés suivent son rythme. Chaleur : par degré-jour simulé.
 * @return array{0: ?float, 1: string} [conso_jour, unité]
 */
function calibrer(Database $db, array $cpt, string $profil, int $paId, string $occupation): array
{
    $unite = (string)($cpt['Unite'] ?? '') ?: (ConnectPiloteSimulation::PROFILS[$profil][1] ?? '');
    $rows = $db->fetchAll("SELECT IndexDebut, IndexFin, Date, PeriodeDebut, PeriodeFin FROM ReleverEnergie
                            WHERE CompteurId = :c AND Type = 'Releve' AND IndexDebut IS NOT NULL AND IndexFin IS NOT NULL
                              AND PeriodeDebut IS NOT NULL ORDER BY Date DESC, Id DESC LIMIT 12", ['c' => (int)$cpt['Id']]);
    $taux = []; $conso = 0.0; $dju = 0.0;
    foreach ($rows as $r) {
        $c = (float)$r['IndexFin'] - (float)$r['IndexDebut'];
        $t1 = strtotime((string)$r['PeriodeDebut']); $t2 = strtotime((string)($r['PeriodeFin'] ?: $r['Date']));
        if ($c <= 0 || !$t1 || !$t2 || $t2 <= $t1) continue;
        $taux[] = $c / max(1, round(($t2 - $t1) / 86400));
        if ($profil === 'index_chaleur') {
            $conso += $c;
            for ($t = $t1; $t <= $t2; $t += 86400) $dju += max(0.0, 18 - ConnectPiloteSimulation::moyenneJour('site:' . $paId, $t + 43200));
        }
    }
    if ($profil === 'index_chaleur') return [$dju > 20 && $conso > 0 ? round($conso / $dju, 5) : null, $unite];
    if (count($taux) < 3) return [null, $unite];
    sort($taux);
    $n = count($taux);
    return [round($n % 2 ? $taux[intdiv($n, 2)] : ($taux[$n / 2 - 1] + $taux[$n / 2]) / 2, 3), $unite];
}

/**
 * Alarmes qu'une règle « seuil » aurait levées sur l'historique : terminées et
 * acquittées (la dernière, si elle dure encore, est laissée au moteur en service).
 */
function alarmesPassees(Database $db, ConnectDepot $depot, ConnectAlarmes $alarmes, int $pointId, int $regleId, int $maintenant): int
{
    $pt = $depot->point($pointId);
    $r = $db->fetchOne("SELECT * FROM ConnectRegles WHERE Id = :id", ['id' => $regleId]);
    if (!$pt || !$r) return 0;
    $rows = $db->fetchAll("SELECT Horodatage, Valeur FROM ConnectMesures WHERE PointId = :p ORDER BY Horodatage", ['p' => $pointId]);
    $s1 = (float)$r['Seuil']; $s2 = $r['Seuil2'] === null ? $s1 : (float)$r['Seuil2'];
    $sup = $r['Operateur'] === 'sup';
    $delai = (int)$r['DelaiMinutes'] * 60;
    $ins = $db->getPdo()->prepare("INSERT INTO ConnectAlarmes (PointId, RegleId, PasserelleId, Origine, Gravite, Message, ValeurDeclenchement,
                                       Debut, Fin, Etat, Acquittee, AcquitteePar, DateAcquittement, Commentaire)
                                   VALUES (?, ?, ?, 'regle', ?, ?, ?, ?, ?, 'terminee', 1, ?, ?, ?)");
    $n = 0; $debut = null; $active = false; $pire = null;
    $u = trim((string)($pt['Unite'] ?? ''));
    foreach ($rows as $m) {
        if ($m['Valeur'] === null) continue;
        $v = (float)$m['Valeur']; $t = (int)strtotime((string)$m['Horodatage']);
        $cond = $active ? ($sup ? $v > $s2 : $v < $s2) : ($sup ? $v > $s1 : $v < $s1);
        if ($cond) {
            if ($debut === null) $debut = $t;
            $pire = $pire === null ? $v : ($sup ? max($pire, $v) : min($pire, $v));
            if (!$active && $t - $debut >= $delai) $active = true;
            continue;
        }
        if ($active && $t < $maintenant - 1800) {
            $ouvert = $debut + $delai;
            $msg = '« ' . $pt['Nom'] . ' » ' . $alarmes->texteRegle($pt, $r) . ' — valeur : ' . ConnectAlarmes::nombreFr($pire, (int)$pt['Decimales']) . ($u !== '' ? " $u" : '');
            $ack = date('Y-m-d H:i:s', $t + 1200);
            $ins->execute([$pointId, $regleId, (int)$pt['PasserelleId'], (string)$r['Gravite'], mb_substr($msg, 0, 500), $pire,
                           date('Y-m-d H:i:s', $ouvert), date('Y-m-d H:i:s', $t), LOGIN, $ack, 'Démonstration : alarme reconstituée depuis l\'historique simulé.']);
            $n++;
        }
        $debut = null; $active = false; $pire = null;
    }
    return $n;
}

/** Retire tout ce que ce script a posé (et rien d'autre). */
function retirer(Database $db, array $passerelles, string $organisation): array
{
    $pdo = $db->getPdo();
    foreach ($passerelles as $pa) {
        $id = (int)$pa['Id'];
        $pts = array_map('intval', array_column($db->fetchAll("SELECT Id FROM ConnectPoints WHERE PasserelleId = :p", ['p' => $id]), 'Id'));
        if ($pts) {
            $in = implode(',', $pts);
            $lia = array_map('intval', array_column($db->fetchAll("SELECT Id FROM ConnectLiaisons WHERE PointId IN ($in)"), 'Id'));
            if ($lia) {
                $inL = implode(',', $lia);
                $rel = array_filter(array_map('intval', array_column($db->fetchAll("SELECT ReleveId FROM ConnectExecutions WHERE LiaisonId IN ($inL) AND ReleveId IS NOT NULL"), 'ReleveId')));
                if ($rel) $pdo->exec("DELETE FROM ReleverEnergie WHERE Id IN (" . implode(',', $rel) . ")");
                $pdo->exec("DELETE FROM ConnectExecutions WHERE LiaisonId IN ($inL)");
                $pdo->exec("DELETE FROM ConnectLiaisons WHERE Id IN ($inL)");
            }
            $pdo->exec("DELETE FROM ConnectRegles WHERE PointId IN ($in)");
            $pdo->exec("DELETE FROM ConnectMesures WHERE PointId IN ($in)");
            $pdo->exec("DELETE FROM ConnectCommandes WHERE PointId IN ($in)");
        }
        $pdo->prepare("DELETE FROM ConnectAlarmes WHERE PasserelleId = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM ConnectPoints WHERE PasserelleId = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM ConnectPasserelles WHERE Id = ?")->execute([$id]);
        foreach (glob(dirname(__DIR__, 2) . '/data/connect/simulation/*-' . $id . '.json') ?: [] as $f) @unlink($f);
    }
    // Compteurs créés pour la démonstration, s'ils n'ont plus de relevé.
    foreach ($db->fetchAll("SELECT Id FROM CompteurEnergie WHERE NumeroCompteur = :n", ['n' => NUMERO_COMPTEUR]) as $c) {
        $reste = (int)($db->fetchOne("SELECT COUNT(*) AS n FROM ReleverEnergie WHERE CompteurId = :c", ['c' => (int)$c['Id']])['n'] ?? 0);
        if ($reste === 0) $db->deleteEnergieCompteur((int)$c['Id']);
    }
    return [count($passerelles), remettre($db, $organisation)];
}

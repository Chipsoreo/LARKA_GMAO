<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka Connect — le passage du planificateur.
 *
 * Appelé chaque minute (api/outils/connect-collecte.php), pour chaque
 * organisation où Larka Connect est ouvert :
 *   1. interroge les connexions DUES (intervalle propre à chacune) et range
 *      les lectures ; une connexion en échec trois fois de suite lève une
 *      alarme « connexion » ;
 *   2. évalue les règles des points lus, puis les règles « absence » ;
 *   3. traite les échéances des liaisons (relevés d'énergie) ;
 *   4. une fois par jour, purge l'historique au-delà de la durée de rétention.
 *
 * Un verrou empêche deux passages simultanés ; un budget de temps borne un
 * passage (un équipement muet ne retarde pas les suivants indéfiniment : ce
 * qui n'a pas été fait l'est au passage suivant).
 */

declare(strict_types=1);

final class ConnectCollecte
{
    private static function racine(): string { return dirname(__DIR__, 2); }
    public static function fichierEtat(): string { return self::racine() . '/data/run/connect-collecte.json'; }

    public static function lireEtat(): array
    {
        $e = @json_decode((string)@file_get_contents(self::fichierEtat()), true);
        return is_array($e) ? $e : [];
    }

    private static function ecrireEtat(array $e): void
    {
        @mkdir(dirname(self::fichierEtat()), 0775, true);
        $tmp = self::fichierEtat() . '.' . bin2hex(random_bytes(3));
        if (@file_put_contents($tmp, json_encode($e, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) !== false) {
            @rename($tmp, self::fichierEtat());
        }
    }

    /** Le planificateur tourne-t-il ? (un passage dans les 3 dernières minutes) */
    public static function planificateurActif(): bool
    {
        $p = self::lireEtat()['_passage'] ?? null;
        return $p && (time() - (int)strtotime((string)$p)) < 180;
    }

    /**
     * Un passage complet.
     * @param bool        $forcer      toutes les connexions, dues ou non
     * @param string|null $organisation une seule organisation (clé)
     * @param int|null    $connexion    une seule connexion (Id)
     * @return array{verrou: bool, actif: bool, organisations: list<array>}
     */
    public static function executer(bool $forcer = false, ?string $organisation = null, ?int $connexion = null): array
    {
        if (!Connect::actifServeur()) return ['verrou' => true, 'actif' => false, 'organisations' => []];
        @mkdir(self::racine() . '/data/run', 0775, true);
        $verrou = @fopen(self::racine() . '/data/run/connect-collecte.lock', 'c');
        if (!$verrou || !flock($verrou, LOCK_EX | LOCK_NB)) return ['verrou' => false, 'actif' => true, 'organisations' => []];
        @set_time_limit(0);
        $budget = max(10, (int)Connect::reglage('budget_secondes', 50));
        $fin = microtime(true) + $budget;
        $etat = self::lireEtat();
        $etat['_passage'] = date('Y-m-d H:i:s');
        self::ecrireEtat($etat);
        $rapport = [];
        try {
            foreach (Connect::organisations() as $org) {
                if ($organisation !== null && $org['cle'] !== $organisation) continue;
                try {
                    $db = $org['base'] === null ? new Database() : new Database($org['base']);
                } catch (\Throwable $e) {
                    $rapport[] = ['cle' => $org['cle'], 'nom' => $org['nom'], 'erreur' => 'base injoignable : ' . $e->getMessage()];
                    continue;
                }
                if (!Connect::actifPour($db)) continue;
                if (class_exists('Journal')) { try { Journal::attacherBase($db->getPdo()); } catch (\Throwable $e) {} }
                $rapport[] = self::organisation($db, $org, $forcer, $connexion, $fin);
            }
        } finally {
            $etat = self::lireEtat();
            $etat['_passage'] = date('Y-m-d H:i:s');
            $etat['_dernier'] = ['quand' => date('Y-m-d H:i:s'), 'organisations' => array_map(fn($r) => array_diff_key($r, ['connexions' => 1]), $rapport)];
            self::ecrireEtat($etat);
            flock($verrou, LOCK_UN);
            fclose($verrou);
        }
        return ['verrou' => true, 'actif' => true, 'organisations' => $rapport];
    }

    private static function organisation(Database $db, array $org, bool $forcer, ?int $seule, float $fin): array
    {
        $depot = new ConnectDepot($db);
        $alarmes = new ConnectAlarmes($depot, $org['cle']);
        $r = ['cle' => $org['cle'], 'nom' => $org['nom'], 'connexions' => [], 'lues' => 0, 'en_defaut' => 0, 'historisees' => 0,
              'releves' => 0, 'anomalies' => 0, 'manquants' => 0, 'alarmes_ouvertes' => 0, 'alarmes_fermees' => 0, 'reportees' => 0];
        $n = date('Y-m-d H:i:s');
        $sql = "SELECT * FROM ConnectPasserelles WHERE Actif = 1 AND Protocole NOT IN ('ocpp', 'lorawan')";
        $p = [];
        if ($seule !== null) { $sql .= ' AND Id = :id'; $p['id'] = $seule; }
        elseif (!$forcer) { $sql .= ' AND (ProchaineCollecte IS NULL OR ProchaineCollecte <= :n)'; $p['n'] = $n; }
        foreach ($db->fetchAll($sql . ' ORDER BY ProchaineCollecte', $p) as $pa) {
            if (ConnectDepot::enHotspot($pa)) continue;                       // relevée hors ligne par un PC releveur
            if (microtime(true) > $fin) { $r['reportees']++; continue; }
            $c = self::connexion($depot, $alarmes, $pa, $org['cle']);
            $r['connexions'][] = $c;
            foreach (['lues', 'en_defaut', 'historisees'] as $k) $r[$k] += $c[$k] ?? 0;
        }
        try { $alarmes->evaluerAbsences(); } catch (\Throwable $e) { Connect::journal('erreur', 'Règles d\'absence', ['exception' => $e]); }
        self::bornesMuettes($depot, $alarmes, $org['cle']);
        try {
            $liaisons = new ConnectLiaisons($depot, $alarmes);
            $s = $liaisons->executerDues();
            $r['releves'] = $s['releves']; $r['anomalies'] = $s['anomalies']; $r['manquants'] = $s['manquants'];
        } catch (\Throwable $e) {
            Connect::journal('erreur', 'Liaisons : échéances non traitées', ['exception' => $e]);
            $r['erreur_liaisons'] = $e->getMessage();
        }
        self::purgeQuotidienne($depot, $org['cle']);
        $r['alarmes_ouvertes'] = count($alarmes->ouvertes);
        $r['alarmes_fermees'] = count($alarmes->fermees);
        self::modulesAlarmes($db, $org['cle'], $r);
        return $r;
    }

    /**
     * Registres des modules qui reçoivent les alarmes techniques.
     *
     * Les modules déclaratifs n'exécutent rien : c'est Larka qui inscrit les
     * alarmes du passage — et la fin de celles qui se sont terminées — dans les
     * jeux qu'ils ont désignés (voir api/extensions/declaratif/Connectivite.php).
     * Après les alarmes de tous les protocoles, OCPP compris : le service des
     * bornes ouvre les siennes à toute heure, ce passage les ramasse.
     * Une panne de module n'arrête jamais la collecte.
     */
    private static function modulesAlarmes(Database $db, string $organisation, array &$r): void
    {
        $f = dirname(__DIR__) . '/extensions/declaratif/Connectivite.php';
        if (!is_file($f)) return;
        try {
            require_once $f;
            $b = ExtConnectivite::synchroniserOrganisation($db, $organisation);
            if ($b['inscrites'] || $b['mises_a_jour']) {
                $r['modules_alarmes'] = $b['inscrites'] + $b['mises_a_jour'];
            }
            foreach ($b['erreurs'] as $err) {
                Connect::journal('erreur', 'Module : réception des alarmes impossible', ['detail' => $err]);
            }
        } catch (\Throwable $e) {
            Connect::journal('erreur', 'Modules : réception des alarmes impossible', ['exception' => $e]);
        }
    }

    /** Collecte d'une connexion : lecture, rangement, règles, état. */
    public static function connexion(ConnectDepot $depot, ConnectAlarmes $alarmes, array $pa, string $organisation): array
    {
        $t0 = microtime(true);
        $id = (int)$pa['Id'];
        $res = ['id' => $id, 'nom' => (string)$pa['Nom'], 'protocole' => (string)$pa['Protocole'], 'lues' => 0, 'en_defaut' => 0,
                'historisees' => 0, 'erreur' => null];
        $points = [];
        foreach ($depot->points($id, true) as $pt) $points[(int)$pt['Id']] = $pt;
        $prochaine = date('Y-m-d H:i:s', time() + max(10, (int)$pa['IntervalleSecondes']));
        $pilote = null;
        try {
            $cx = $depot->connexionPilote($pa, $organisation);
            if ($pa['Protocole'] === 'csv') $cx['_fichiers_traites'] = $depot->fichiersTraites($id);
            $pilote = ConnectPilotes::creer($cx);
            $lectures = $points ? $pilote->lire(array_values($points)) : [];
            if ($pilote instanceof ConnectPiloteCsv && $pilote->fichiersLus()) $depot->noterFichiers($id, $pilote->fichiersLus());
            if ($pilote instanceof ConnectPiloteRest) {
                $liste = $pilote->alarmes();
                if ($liste !== null) {
                    $alarmes->synchroniserEquipement($pa, $liste, filter_var($cx['params']['alarmes_vers_demande'] ?? false, FILTER_VALIDATE_BOOLEAN));
                }
            }
        } catch (\Throwable $e) {
            $lectures = [];
            foreach ($points as $pid => $_) $lectures[$pid] = ['valeur' => null, 'texte' => null, 'qualite' => 'mauvaise', 'erreur' => $e->getMessage()];
            Connect::journal('erreur', "Collecte « {$pa['Nom']} » : " . $e->getMessage(), ['exception' => $e, 'entiteType' => 'ConnectPasserelle', 'entiteId' => $id,
                                                                                    'protocole' => (string)$pa['Protocole']]);
        } finally {
            if ($pilote) { try { $pilote->fermer(); } catch (\Throwable $e) {} }
        }
        $s = $lectures ? $depot->enregistrerLectures($points, $lectures) : ['lus' => 0, 'en_defaut' => 0, 'historisees' => 0, 'valeurs' => []];
        $res['lues'] = $s['lus']; $res['en_defaut'] = $s['en_defaut']; $res['historisees'] = $s['historisees'];
        foreach ($s['valeurs'] as $pid => $lecture) {
            try { $alarmes->evaluerPoint($points[$pid], $lecture); }
            catch (\Throwable $e) { Connect::journal('erreur', 'Règles du point ' . $pid, ['exception' => $e]); }
        }
        // État de la connexion : tout en échec avec la même cause = connexion en défaut.
        $erreurs = array_values(array_unique(array_filter(array_map(fn($l) => ($l['valeur'] ?? null) === null && ($l['texte'] ?? null) === null
                                                               ? ($l['erreur'] ?? 'illisible') : null, $lectures))));
        $toutEchec = $points && $lectures && $s['lus'] === 0 && count($lectures) === count(array_filter($lectures, fn($l) => ($l['valeur'] ?? null) === null && ($l['texte'] ?? null) === null));
        $echecs = $toutEchec ? (int)$pa['EchecsConsecutifs'] + 1 : 0;
        $etat = !$points ? 'inconnu' : ($toutEchec ? 'erreur' : ($s['en_defaut'] > 0 ? 'partiel' : 'ok'));
        // Un protocole « abonnement » ou « fichier » sans nouveauté n'est pas en panne.
        if ($points && !$lectures && in_array($pa['Protocole'], ['mqtt', 'csv'], true)) $etat = (string)($pa['Etat'] === 'erreur' ? 'ok' : ($pa['Etat'] ?: 'ok'));
        $res['erreur'] = $toutEchec ? ($erreurs[0] ?? 'aucune réponse') : null;
        $depot->noterEtatPasserelle($id, $etat, $res['erreur'] ?? ($s['en_defaut'] ? implode(' ; ', array_slice($erreurs, 0, 3)) : null),
                                     $echecs, $prochaine, !$toutEchec && ($s['lus'] > 0 || in_array($pa['Protocole'], ['mqtt', 'csv'], true)));
        try { $alarmes->connexion($pa, $toutEchec, (string)$res['erreur'], $echecs); }
        catch (\Throwable $e) { Connect::journal('erreur', 'Alarme de connexion', ['exception' => $e]); }
        $res['etat'] = $etat;
        $res['duree'] = round(microtime(true) - $t0, 2);
        return $res;
    }

    /**
     * Relevé fait hors ligne par un PC releveur (appareil en hotspot Wi-Fi) : les
     * lectures, prises à l'heure « quand », passent par le même chemin qu'une
     * collecte — mise à l'échelle, historique, règles d'alarme, état.
     *
     * @param array $lectures  Id du point => [valeur, texte, qualite, erreur] (valeurs brutes du pilote)
     */
    public static function releve(ConnectDepot $depot, ConnectAlarmes $alarmes, array $pa, array $lectures, string $quand, ?string $erreur): array
    {
        $id = (int)$pa['Id'];
        // Plusieurs relevés d'un même envoi se suivent : l'état du précédent compte (échecs de suite).
        $frais = $depot->db()->fetchOne("SELECT Etat, EchecsConsecutifs FROM ConnectPasserelles WHERE Id = :id", ['id' => $id]);
        if ($frais) $pa = $frais + $pa;
        $pa['EchecsConsecutifs'] = (int)($frais['EchecsConsecutifs'] ?? $pa['EchecsConsecutifs'] ?? 0);
        $pa['Etat'] = (string)($frais['Etat'] ?? $pa['Etat'] ?? 'inconnu');
        $points = [];
        foreach ($depot->points($id, true) as $pt) $points[(int)$pt['Id']] = $pt;
        $propres = [];
        foreach ($lectures as $pid => $l) {
            if (!isset($points[(int)$pid]) || !is_array($l)) continue;          // un point d'une autre connexion : ignoré
            $propres[(int)$pid] = ['valeur' => is_numeric($l['valeur'] ?? null) ? (float)$l['valeur'] : null,
                                   'texte' => isset($l['texte']) && $l['texte'] !== null ? mb_substr((string)$l['texte'], 0, 200) : null,
                                   'qualite' => in_array($l['qualite'] ?? 'bonne', ['bonne', 'douteuse', 'mauvaise'], true) ? ($l['qualite'] ?? 'bonne') : 'douteuse',
                                   'erreur' => isset($l['erreur']) ? mb_substr((string)$l['erreur'], 0, 300) : null, 'horodatage' => $quand];
        }
        // Appareil injoignable pendant ce relevé : chaque point en défaut, avec la cause.
        if (!$propres && $erreur !== null) {
            foreach ($points as $pid => $_) $propres[$pid] = ['valeur' => null, 'texte' => null, 'qualite' => 'mauvaise', 'erreur' => $erreur, 'horodatage' => $quand];
        }
        // Relevé plus ancien que la dernière valeur du point (PC qui envoie sa file
        // d'attente après un autre) : historique seulement, à son heure réelle.
        $anciens = [];
        foreach ($propres as $pid => $l) {
            $der = (string)($points[$pid]['DerniereLecture'] ?? '');
            if ($der !== '' && $quand <= $der) { $anciens[$pid] = $l; unset($propres[$pid]); }
        }
        if ($anciens) {
            $depot->historiserAnterieures($points, $anciens);
            if (!$propres) return ['id' => $id, 'lues' => count($anciens), 'en_defaut' => 0, 'etat' => (string)($pa['Etat'] ?? 'inconnu'), 'anterieur' => true];
        }
        $s = $propres ? $depot->enregistrerLectures($points, $propres) : ['lus' => 0, 'en_defaut' => 0, 'historisees' => 0, 'valeurs' => []];
        foreach ($s['valeurs'] as $pid => $lecture) {
            try { $alarmes->evaluerPoint($points[$pid], $lecture); }
            catch (\Throwable $e) { Connect::journal('erreur', 'Règles du point ' . $pid, ['exception' => $e]); }
        }
        $toutEchec = $points && $s['lus'] === 0;
        $etat = !$points ? 'inconnu' : ($toutEchec ? 'erreur' : ($s['en_defaut'] > 0 ? 'partiel' : 'ok'));
        $cause = $toutEchec ? ($erreur ?? 'aucune valeur lue') : null;
        $echecs = $toutEchec ? (int)$pa['EchecsConsecutifs'] + 1 : 0;
        $depot->noterEtatPasserelle($id, $etat, $cause, $echecs, null, !$toutEchec);
        // Comme pour une connexion interrogée par le serveur : 3 relevés de suite en échec, une alarme.
        try { $alarmes->connexion($pa, $toutEchec, 'relevé du PC releveur : ' . $cause, $echecs); }
        catch (\Throwable $e) { Connect::journal('erreur', 'Alarme de connexion (hotspot)', ['exception' => $e]); }
        return ['id' => $id, 'lues' => $s['lus'], 'en_defaut' => $s['en_defaut'], 'etat' => $etat];
    }

    /**
     * Appareils « entrants » devenus muets : borne OCPP silencieuse plus de 3
     * battements de cœur, capteur LoRaWAN sans trame depuis son délai de silence.
     * Personne ne les interroge : c'est leur silence qui signale la panne.
     */
    private static function bornesMuettes(ConnectDepot $depot, ConnectAlarmes $alarmes, string $organisation): void
    {
        $db = $depot->db();
        foreach ($db->fetchAll("SELECT * FROM ConnectPasserelles WHERE Actif = 1 AND Protocole IN ('ocpp', 'lorawan')") as $pa) {
            $params = json_decode((string)($pa['Parametres'] ?? ''), true) ?: [];
            $borne = $pa['Protocole'] === 'ocpp';
            $limite = $borne ? max(180, 3 * (int)($params['intervalle_battement'] ?? 60))
                             : 60 * max(15, (int)($params['silence_minutes'] ?? 180));
            $vu = $pa['DernierContact'] ? (int)strtotime((string)$pa['DernierContact']) : 0;
            if (!$vu) continue;                          // jamais entendu : rien à signaler
            $muette = (time() - $vu) > $limite;
            $quoi = $borne ? 'de la borne' : 'du capteur';
            try {
                $alarmes->connexion($pa, $muette, "aucun message $quoi depuis le " . date('d/m/Y à H:i', $vu), $muette ? 3 : 0);
                if ($muette && $pa['Etat'] !== 'erreur') {
                    Connect::journal('avertissement', "« {$pa['Nom']} » silencieux : aucun message $quoi depuis le " . date('d/m/Y à H:i', $vu),
                                     ['code' => $borne ? 'CXB-004' : 'CXL-006', 'entiteType' => 'ConnectPasserelle', 'entiteId' => (int)$pa['Id'],
                                      'protocole' => (string)$pa['Protocole'], 'etat_avant' => (string)$pa['Etat'], 'etat' => 'erreur']);
                    $db->getPdo()->prepare("UPDATE ConnectPasserelles SET Etat = 'erreur', DerniereErreur = ? WHERE Id = ?")
                       ->execute([($borne ? 'borne' : 'capteur') . ' silencieux depuis le ' . date('d/m/Y à H:i', $vu), (int)$pa['Id']]);
                }
            } catch (\Throwable $e) {}
        }
    }

    private static function purgeQuotidienne(ConnectDepot $depot, string $organisation): void
    {
        $etat = self::lireEtat();
        $cle = 'purge_' . $organisation;
        if (($etat[$cle] ?? '') === date('Y-m-d')) return;
        $jours = max(30, (int)Connect::reglage('retention_jours', 400));
        try {
            $n = $depot->purgerMesures($jours);
            if ($n > 0) Connect::journal('info', "Historique Larka Connect : $n mesure(s) de plus de $jours jours supprimée(s)");
        } catch (\Throwable $e) {
            Connect::journal('erreur', 'Purge de l\'historique', ['exception' => $e]);
        }
        $etat = self::lireEtat();
        $etat[$cle] = date('Y-m-d');
        self::ecrireEtat($etat);
    }
}

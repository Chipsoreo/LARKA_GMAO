<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka Connect — liaisons : « le relevé d'aujourd'hui va ici ».
 *
 * Une liaison relie un point (l'index d'un compteur électrique, d'eau, de
 * calories…) à un compteur du module Énergie, selon un calendrier. À chaque
 * échéance, la collecte cherche la mesure la plus proche dans l'historique
 * (± tolérance) et écrit un relevé — exactement comme une saisie, mais avec
 * son origine tracée.
 *
 * Les cas tordus sont traités, pas ignorés :
 *   - serveur arrêté à l'échéance : au redémarrage, les échéances manquées
 *     sont rattrapées depuis l'historique (statut « rattrapé ») ;
 *   - aucune mesure dans la tolérance : « manquant », et une alarme mineure ;
 *   - index qui baisse (compteur remplacé, tour de compteur, erreur) ou
 *     consommation hors norme (N fois la consommation journalière habituelle) :
 *     rien n'est écrit, l'échéance attend une validation (« anomalie ») ;
 *   - validation manuelle demandée : tout attend un clic.
 * Chaque relevé écrit porte son origine dans son commentaire, et chaque
 * échéance laisse une ligne dans ConnectExecutions : on sait toujours d'où
 * vient un chiffre (déclarations annuelles, décret tertiaire).
 */

declare(strict_types=1);

final class ConnectLiaisons
{
    private ConnectDepot $depot;
    private Database $db;
    private PDO $pdo;
    private ConnectAlarmes $alarmes;
    public array $stats = ['echeances' => 0, 'releves' => 0, 'anomalies' => 0, 'manquants' => 0];

    public function __construct(ConnectDepot $depot, ConnectAlarmes $alarmes)
    {
        $this->depot = $depot;
        $this->db = $depot->db();
        $this->pdo = $this->db->getPdo();
        $this->alarmes = $alarmes;
    }

    /** Traite les échéances dues (et celles qui ont été manquées). */
    public function executerDues(?DateTimeImmutable $maintenant = null, int $maxParLiaison = 60): array
    {
        $now = $maintenant ?? new DateTimeImmutable('now');
        $liaisons = $this->db->fetchAll(
            "SELECT l.*, pt.Nom AS PointNom, pt.Unite AS PointUnite, pt.Decimales AS PointDecimales, pt.PasserelleId,
                    pt.DerniereValeur, pt.DerniereLecture, pt.DerniereQualite
               FROM ConnectLiaisons l JOIN ConnectPoints pt ON pt.Id = l.PointId
              WHERE l.Actif = 1 AND l.ProchaineExecution IS NOT NULL AND l.ProchaineExecution <= :n",
            ['n' => $now->format('Y-m-d H:i:s')]);
        foreach ($liaisons as $l) {
            $echeance = new DateTimeImmutable((string)$l['ProchaineExecution']);
            for ($i = 0; $i < $maxParLiaison && $echeance->getTimestamp() <= $now->getTimestamp(); $i++) {
                $r = $this->traiterEcheance($l, $echeance, $now);
                if (!empty($r['attendre'])) break;          // mesure attendue dans la tolérance : au prochain passage
                $suivante = ConnectPlanif::prochaine($l, $echeance);
                $this->pdo->prepare("UPDATE ConnectLiaisons SET ProchaineExecution = ?, DerniereExecution = ? WHERE Id = ?")
                          ->execute([$suivante ? $suivante->format('Y-m-d H:i:s') : null, $now->format('Y-m-d H:i:s'), (int)$l['Id']]);
                if (!$suivante) break;
                $echeance = $suivante;
            }
        }
        return $this->stats;
    }

    /** Une échéance. @return array{attendre?: bool, statut?: string} */
    private function traiterEcheance(array $l, DateTimeImmutable $T, DateTimeImmutable $now): array
    {
        $prevue = $T->format('Y-m-d H:i:s');
        // Déjà traitée (passage concurrent, reprise) : on n'écrit jamais deux fois.
        if ($this->db->fetchOne("SELECT Id FROM ConnectExecutions WHERE LiaisonId = :l AND Prevue = :p", ['l' => (int)$l['Id'], 'p' => $prevue])) {
            return ['statut' => 'deja'];
        }
        $tol = max(1, (int)$l['ToleranceMinutes']);
        $m = $this->depot->mesureProche((int)$l['PointId'], $prevue, $tol);
        // Valeur courante du point, si elle tombe dans la tolérance et n'est pas encore historisée.
        if (!$m && $l['DerniereLecture'] && $l['DerniereValeur'] !== null && ($l['DerniereQualite'] ?? '') !== 'mauvaise'
            && abs(strtotime((string)$l['DerniereLecture']) - $T->getTimestamp()) <= $tol * 60) {
            $m = ['Horodatage' => $l['DerniereLecture'], 'Valeur' => $l['DerniereValeur']];
        }
        $finFenetre = $T->getTimestamp() + $tol * 60;
        if (!$m && $now->getTimestamp() <= $finFenetre) return ['attendre' => true];
        $this->stats['echeances']++;
        if (!$m) {
            $this->noter($l, $prevue, 'manquant', null, null, null, null, null,
                "Aucune mesure de « {$l['PointNom']} » entre " . date('H:i', $T->getTimestamp() - $tol * 60) . ' et ' . date('H:i', $finFenetre) . '.');
            $this->stats['manquants']++;
            $this->alarmes->ouvrir([
                'origine' => 'liaison', 'passerelle' => (int)$l['PasserelleId'], 'point' => (int)$l['PointId'],
                'code' => 'liaison:' . $l['Id'], 'gravite' => 'mineure',
                'message' => "Relevé manquant : « {$l['PointNom']} » vers « " . ($this->nomCible($l) ?? '?') . ' » (' . $T->format('d/m/Y H:i') . ')',
                'demande' => false, 'notifier' => false,
            ]);
            return ['statut' => 'manquant'];
        }
        // Une liaison qui retrouve ses mesures clôt son alarme « manquant ».
        if ($a = $this->alarmes->alarmeActive('liaison', (int)$l['PasserelleId'], (int)$l['PointId'], null, 'liaison:' . $l['Id'])) {
            $this->alarmes->fermer((int)$a['Id']);
        }
        $valeur = round((float)$m['Valeur'] * (float)($l['Coefficient'] ?: 1), 6);
        $aTemps = ($now->getTimestamp() - $T->getTimestamp()) <= ($tol + 2) * 60;
        $statut = $aTemps ? 'automatique' : 'rattrape';
        if ($l['Destination'] === 'releve_energie') return $this->versReleve($l, $T, $valeur, (string)$m['Horodatage'], $statut);
        return ['statut' => 'ignore'];
    }

    /**
     * Liaisons « à chaque relevé » : une mesure vient d'arriver (dépôt du PC
     * releveur) ; chaque liaison du point écrit son relevé Énergie, daté de
     * la mesure — mêmes contrôles qu'une échéance (index qui baisse,
     * consommation hors norme, validation manuelle). Rend le nombre traité.
     */
    public function surReleve(int $pointId, string $horodatage, float $valeur): int
    {
        $liaisons = $this->db->fetchAll(
            "SELECT l.*, pt.Nom AS PointNom, pt.Unite AS PointUnite, pt.Decimales AS PointDecimales, pt.PasserelleId
               FROM ConnectLiaisons l JOIN ConnectPoints pt ON pt.Id = l.PointId
              WHERE l.Actif = 1 AND l.Frequence = 'releve' AND l.PointId = :p", ['p' => $pointId]);
        $n = 0;
        foreach ($liaisons as $l) {
            $deja = $this->db->fetchOne("SELECT Id FROM ConnectExecutions WHERE LiaisonId = :l AND Prevue = :p", ['l' => (int)$l['Id'], 'p' => $horodatage]);
            if ($deja) continue;
            $T = new DateTimeImmutable($horodatage);
            $this->stats['echeances']++;
            $index = round($valeur * (float)($l['Coefficient'] ?: 1), 6);
            if ($l['Destination'] === 'releve_energie') $this->versReleve($l, $T, $index, $horodatage, 'automatique');
            $this->pdo->prepare("UPDATE ConnectLiaisons SET DerniereExecution = ? WHERE Id = ?")->execute([date('Y-m-d H:i:s'), (int)$l['Id']]);
            $n++;
        }
        return $n;
    }

    private function nomCible(array $l): ?string
    {
        if ($l['Destination'] !== 'releve_energie') return null;
        return $this->db->fetchOne("SELECT Nom FROM CompteurEnergie WHERE Id = :id", ['id' => (int)$l['CibleId']])['Nom'] ?? null;
    }

    /**
     * Dernier index connu du compteur Énergie le jour $date ou avant : [index, date] ou [null, null].
     * Borné à la date du relevé : une échéance rattrapée depuis l'historique, ou validée
     * après coup, prenait pour « précédent » un relevé PLUS RÉCENT qu'elle (le dernier de
     * la table) — consommation fausse, ou index « en baisse » qui n'en était pas un.
     */
    private function precedent(int $compteurId, ?string $date = null): array
    {
        $r = $this->db->fetchOne("SELECT IndexFin, Date FROM ReleverEnergie WHERE CompteurId = :c AND IndexFin IS NOT NULL"
                                 . ($date !== null ? ' AND SUBSTR(Date, 1, 10) <= :d' : '') . "
                                   ORDER BY Date DESC, Id DESC LIMIT 1", ['c' => $compteurId] + ($date !== null ? ['d' => substr($date, 0, 10)] : []));
        return $r ? [(float)$r['IndexFin'], (string)$r['Date']] : [null, null];
    }

    /**
     * Consommation journalière habituelle, ou null : médiane des 12 derniers relevés,
     * et — pour les compteurs saisonniers (chauffage, gaz) — médiane des relevés de la
     * même saison les années passées (± 1 mois), si elle est plus forte. Sans cela,
     * chaque reprise du chauffage à l'automne passait pour une anomalie.
     */
    private function consoJournaliereHabituelle(int $compteurId, ?DateTimeImmutable $T = null): ?float
    {
        $rows = $this->db->fetchAll("SELECT IndexDebut, IndexFin, Date, PeriodeDebut, PeriodeFin FROM ReleverEnergie
                                      WHERE CompteurId = :c AND IndexDebut IS NOT NULL AND IndexFin IS NOT NULL
                                      ORDER BY Date DESC, Id DESC LIMIT 60", ['c' => $compteurId]);
        $taux = []; $saison = [];
        $mois = $T ? (int)$T->format('n') : null;
        foreach ($rows as $i => $r) {
            $conso = (float)$r['IndexFin'] - (float)$r['IndexDebut'];
            $d1 = strtotime((string)($r['PeriodeDebut'] ?: '')) ?: null;
            $d2 = strtotime((string)($r['PeriodeFin'] ?: $r['Date'])) ?: null;
            $jours = ($d1 && $d2) ? max(1, round(($d2 - $d1) / 86400)) : null;
            if ($conso <= 0 || !$jours) continue;
            if ($i < 12) $taux[] = $conso / $jours;
            if ($mois !== null) {
                $m = (int)date('n', (int)(($d1 + $d2) / 2));
                $ecart = min(abs($m - $mois), 12 - abs($m - $mois));
                if ($ecart <= 1) $saison[] = $conso / $jours;
            }
        }
        $mediane = function (array $t): ?float {
            if (count($t) < 3) return null;
            sort($t);
            $n = count($t);
            return $n % 2 ? $t[intdiv($n, 2)] : ($t[$n / 2 - 1] + $t[$n / 2]) / 2;
        };
        $hab = $mediane($taux);
        $sais = $mediane($saison);
        if ($hab === null) return $sais;
        return $sais !== null ? max($hab, $sais) : $hab;
    }

    private function versReleve(array $l, DateTimeImmutable $T, float $index, string $horoMesure, string $statut): array
    {
        $prevue = $T->format('Y-m-d H:i:s');
        $cpt = $this->db->fetchOne("SELECT * FROM CompteurEnergie WHERE Id = :id", ['id' => (int)$l['CibleId']]);
        if (!$cpt) {
            $this->noter($l, $prevue, 'erreur', $index, null, null, $horoMesure, null, 'Compteur Énergie introuvable (supprimé ?).');
            return ['statut' => 'erreur'];
        }
        [$prec, $datePrec] = $this->precedent((int)$cpt['Id'], $T->format('Y-m-d'));
        $conso = $prec === null ? null : round($index - $prec, 6);
        $anomalie = null;
        if ($prec !== null && $index < $prec) {
            $anomalie = 'L\'index baisse (' . ConnectAlarmes::nombreFr($prec) . ' → ' . ConnectAlarmes::nombreFr($index)
                      . ') : compteur remplacé, tour de compteur ou erreur de lecture. Rien n\'a été écrit.';
        } elseif ($conso !== null && (float)$l['FacteurAnomalie'] > 0 && $datePrec) {
            $hab = $this->consoJournaliereHabituelle((int)$cpt['Id'], $T);
            $jours = max(1 / 24, ($T->getTimestamp() - (int)strtotime($datePrec . ' 00:00:00')) / 86400);
            if ($hab !== null && $hab > 0 && ($conso / $jours) > (float)$l['FacteurAnomalie'] * $hab) {
                $anomalie = 'Consommation de ' . ConnectAlarmes::nombreFr($conso) . ' ' . ($cpt['Unite'] ?? '') . ', soit '
                          . ConnectAlarmes::nombreFr(($conso / $jours) / $hab, 1) . ' fois la consommation journalière habituelle. Rien n\'a été écrit.';
            }
        }
        if ($anomalie !== null) {
            $this->noter($l, $prevue, 'anomalie', $index, $prec, $conso, $horoMesure, null, $anomalie);
            $this->stats['anomalies']++;
            return ['statut' => 'anomalie'];
        }
        if (!empty($l['ValidationManuelle'])) {
            $this->noter($l, $prevue, 'a_valider', $index, $prec, $conso, $horoMesure, null, 'En attente de validation (liaison en validation manuelle).');
            return ['statut' => 'a_valider'];
        }
        $releve = $this->ecrireReleve($l, $cpt, $T->format('Y-m-d'), $index, $prec, $datePrec, $horoMesure, $statut, null);
        $this->noter($l, $prevue, $statut, $index, $prec, $conso, $horoMesure, $releve,
            $statut === 'rattrape' ? 'Échéance rattrapée depuis l\'historique.' : null);
        $this->stats['releves']++;
        return ['statut' => $statut];
    }

    /**
     * Import manuel depuis la supervision : relevé écrit avec les valeurs
     * VALIDÉES par l'utilisateur (index ajustés possibles), comme une saisie.
     * $v : ligne de ConnectConsommations::normaliserImport(). Rend l'Id du relevé.
     */
    public function importer(array $v, string $login): int
    {
        $l = ['PointNom' => (string)$v['point']['Nom']];
        $horo = $v['date'] . ' 00:00:00';
        return $this->ecrireReleve($l, $v['compteur'], $v['date'], (float)$v['indexFin'],
            $v['indexDebut'] === null ? null : (float)$v['indexDebut'], $v['periodeDebut'], $horo, 'import', $login, $v['note'] ?: null);
    }

    /** Écrit le relevé dans le module Énergie ; rend son Id. Idempotent (même date, même index). */
    private function ecrireReleve(array $l, array $cpt, string $date, float $index, ?float $prec, ?string $datePrec,
                                  string $horoMesure, string $statut, ?string $valideur, ?string $note = null): int
    {
        $existe = $this->db->fetchOne("SELECT Id FROM ReleverEnergie WHERE CompteurId = :c AND Date = :d AND IndexFin = :i",
                                      ['c' => (int)$cpt['Id'], 'd' => $date, 'i' => $index]);
        if ($existe) return (int)$existe['Id'];
        $conso = $prec === null ? null : round($index - $prec, 6);
        $carbone = null; $montant = null;
        if ($conso !== null && $conso > 0) {
            $type = (string)$cpt['Type'];
            if (in_array($type, ['Electricite', 'Gaz', 'Chauffage'], true)) {
                try {
                    $f = $this->db->getFacteurCarbonePlusProcheAnnee($type, (int)substr($date, 0, 4), 'officielle');
                    $fac = isset($f['FacteurCO2']) ? (float)$f['FacteurCO2'] : 0.0;
                    if ($fac > 0) $carbone = round($type === 'Chauffage' ? $conso * $fac * 1000 : $conso * $fac, 2);
                } catch (\Throwable $e) { /* facteur indisponible : le CO₂ reste à calculer à l'écran */ }
            }
            $pu = (float)($cpt['PrixUnitaireTTC'] ?? 0) ?: (float)($cpt['PrixTTC'] ?? 0);
            if ($pu > 0) $montant = round($conso * $pu, 2);
        }
        $libStatut = ['automatique' => 'relevé automatique', 'rattrape' => 'relevé rattrapé depuis l\'historique',
                      'valide' => 'relevé validé par ' . $valideur, 'import' => 'relevé importé depuis la supervision par ' . $valideur][$statut] ?? $statut;
        $commentaire = "Larka Connect — $libStatut : point « {$l['PointNom']} »"
                     . ($statut === 'import' ? ', période du ' . ($datePrec ? date('d/m/Y', (int)strtotime($datePrec)) : '?') . ' au ' . date('d/m/Y', (int)strtotime($date))
                                             : ', mesure du ' . date('d/m/Y à H:i', (int)strtotime($horoMesure)))
                     . ($montant !== null ? ' ; montant estimé au prix unitaire du compteur' : '')
                     . ($note !== null && $note !== '' ? ' — ' . $note : '');
        $st = $this->pdo->prepare("INSERT INTO ReleverEnergie (CompteurId, Type, Date, IndexDebut, IndexFin, Consommation, Montant,
                                        PeriodeDebut, PeriodeFin, Commentaire, SaisieParLogin, Carbone, DateSaisie)
                                    VALUES (?, 'Releve', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $st->execute([(int)$cpt['Id'], $date, $prec, $index, $conso, $montant, $datePrec, $date, $commentaire,
                      $valideur ?? Connect::LOGIN_TECHNIQUE, $carbone, date('Y-m-d H:i:s')]);
        $id = (int)$this->pdo->lastInsertId();
        // Serveur de supervision séparé : ce relevé est une copie locale (elle sert au
        // calcul du suivant) ; l'original est écrit par le serveur principal, dans SA base.
        if (class_exists('SupervisionLien') && SupervisionLien::estSupervision($this->db)) {
            SupervisionLien::sortant($this->db, 'releve', ['compteur' => (int)$cpt['Id'], 'date' => $date, 'index' => $index, 'prec' => $prec,
                'datePrec' => $datePrec, 'horo' => $horoMesure, 'statut' => $statut, 'valideur' => $valideur, 'note' => $note,
                'pointNom' => (string)$l['PointNom']]);
        }
        if (class_exists('Journal')) {
            try {
                Journal::log(Journal::AUDIT, 'donnees', 'CREATE ReleverEnergie#' . $id, ['action' => 'CREATE', 'entiteType' => 'ReleverEnergie',
                    'entiteId' => $id, 'champs' => ['compteur' => (int)$cpt['Id'], 'date' => $date, 'index' => $index, 'origine' => 'larka-connect']]);
            } catch (\Throwable $e) {}
        }
        return $id;
    }

    /**
     * Relevé venu du serveur de supervision, écrit par le serveur principal : même
     * calcul (conso, montant, CO₂), même commentaire, même garde contre les doublons.
     */
    public function ecrireReleveExterne(array $cpt, array $d): int
    {
        $date = (string)($d['date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !is_numeric($d['index'] ?? null)) throw new InvalidArgumentException('Relevé incomplet.');
        $statut = in_array($d['statut'] ?? '', ['automatique', 'rattrape', 'valide', 'import'], true) ? $d['statut'] : 'automatique';
        $valideur = isset($d['valideur']) && is_string($d['valideur']) ? mb_substr($d['valideur'], 0, 120) : null;
        $prec = is_numeric($d['prec'] ?? null) ? (float)$d['prec'] : null;
        $datePrec = is_string($d['datePrec'] ?? null) ? $d['datePrec'] : null;
        // L'index précédent vient d'ICI : le serveur de supervision ne connaît que les relevés
        // qu'il a écrits, pas ceux saisis à la main sur le principal. Seul un import validé à
        // l'écran garde l'index de début choisi par la personne.
        if ($statut !== 'import') {
            [$p, $dp] = $this->precedent((int)$cpt['Id'], $date);
            if ($p !== null && (float)$d['index'] >= $p) { $prec = $p; $datePrec = $dp; }
        }
        return $this->ecrireReleve(['PointNom' => mb_substr((string)($d['pointNom'] ?? ''), 0, 200)], $cpt, $date, (float)$d['index'],
            $prec, $datePrec,
            is_string($d['horo'] ?? null) ? $d['horo'] : $date . ' 00:00:00', $statut, $valideur,
            isset($d['note']) && is_string($d['note']) ? mb_substr($d['note'], 0, 300) : null);
    }

    private function noter(array $l, string $prevue, string $statut, ?float $valeur, ?float $prec, ?float $conso,
                           ?string $horoMesure, ?int $releve, ?string $message): void
    {
        $this->pdo->prepare("INSERT INTO ConnectExecutions (LiaisonId, Prevue, Executee, Statut, Valeur, ValeurPrecedente, Consommation,
                                  MesureHorodatage, ReleveId, Message) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                  ->execute([(int)$l['Id'], $prevue, date('Y-m-d H:i:s'), $statut, $valeur, $prec, $conso, $horoMesure, $releve,
                             $message === null ? null : mb_substr($message, 0, 500)]);
    }

    // ══ Validation des échéances en attente ════════════════════════════════

    /** Valide une échéance en anomalie ou en attente : écrit le relevé (valeur corrigée possible). */
    public function valider(int $executionId, ?float $valeur, string $login): array
    {
        $e = $this->db->fetchOne("SELECT * FROM ConnectExecutions WHERE Id = :id", ['id' => $executionId]);
        if (!$e) return ['ok' => false, 'message' => 'Échéance introuvable.'];
        if (!in_array($e['Statut'], ['anomalie', 'a_valider'], true)) return ['ok' => false, 'message' => 'Cette échéance n\'attend pas de validation.'];
        $l = $this->db->fetchOne("SELECT l.*, pt.Nom AS PointNom FROM ConnectLiaisons l JOIN ConnectPoints pt ON pt.Id = l.PointId WHERE l.Id = :id",
                                 ['id' => (int)$e['LiaisonId']]);
        $cpt = $l ? $this->db->fetchOne("SELECT * FROM CompteurEnergie WHERE Id = :id", ['id' => (int)$l['CibleId']]) : null;
        if (!$l || !$cpt) return ['ok' => false, 'message' => 'Liaison ou compteur introuvable.'];
        $index = $valeur ?? ($e['Valeur'] === null ? null : (float)$e['Valeur']);
        if ($index === null) return ['ok' => false, 'message' => 'Aucune valeur à écrire.'];
        $date = substr((string)$e['Prevue'], 0, 10);
        [$prec, $datePrec] = $this->precedent((int)$cpt['Id'], $date);
        // Index en baisse validé = compteur remplacé : le relevé repart de cet index, sans consommation négative.
        if ($prec !== null && $index < $prec) { $prec = null; $datePrec = null; }
        $releve = $this->ecrireReleve($l, $cpt, $date, $index, $prec, $datePrec, (string)($e['MesureHorodatage'] ?: $e['Prevue']), 'valide', $login);
        $this->db->execute("UPDATE ConnectExecutions SET Statut = 'valide', ValidePar = :v, DateValidation = :d, ReleveId = :r, Valeur = :val,
                                Message = :m WHERE Id = :id",
            ['v' => $login, 'd' => date('Y-m-d H:i:s'), 'r' => $releve, 'val' => $index,
             'm' => mb_substr('Validé' . ($valeur !== null && $e['Valeur'] !== null && abs($valeur - (float)$e['Valeur']) > 1e-9
                    ? ' avec correction (' . ConnectAlarmes::nombreFr((float)$e['Valeur']) . ' → ' . ConnectAlarmes::nombreFr($valeur) . ')' : '')
                    . '. ' . (string)$e['Message'], 0, 500), 'id' => $executionId]);
        // Les échéances PLUS ANCIENNES encore en attente sont dépassées : les
        // écrire maintenant mettrait les index dans le désordre (un relevé du
        // 3 après celui du 5). Elles sont closes, sans écriture, et le disent.
        $st = $this->db->getPdo()->prepare("UPDATE ConnectExecutions SET Statut = 'rejete', ValidePar = ?, DateValidation = ?, Message = ?
                                             WHERE LiaisonId = ? AND Statut IN ('anomalie', 'a_valider') AND Prevue < ? AND Id <> ?");
        $st->execute([$login, date('Y-m-d H:i:s'),
                      'Non écrit : le relevé plus récent du ' . date('d/m/Y à H:i', (int)strtotime((string)$e['Prevue'])) . ' a été validé.',
                      (int)$e['LiaisonId'], (string)$e['Prevue'], $executionId]);
        $closes = $st->rowCount();
        return ['ok' => true, 'releve' => $releve, 'closes' => $closes,
                'message' => 'Relevé écrit dans le module Énergie.' . ($closes ? " $closes échéance(s) plus ancienne(s) close(s) sans écriture." : '')];
    }

    /**
     * Valide, dans l'ordre chronologique, toutes les échéances en attente d'un
     * relevé automatique (validation manuelle rattrapée après des congés).
     * S'arrête à la première ANOMALIE : celle-là demande un regard.
     */
    public function validerTout(int $liaisonId, string $login): array
    {
        $attente = $this->db->fetchAll("SELECT Id, Statut FROM ConnectExecutions WHERE LiaisonId = :l AND Statut IN ('anomalie', 'a_valider')
                                         ORDER BY Prevue ASC", ['l' => $liaisonId]);
        $n = 0;
        foreach ($attente as $x) {
            if ($x['Statut'] === 'anomalie') {
                return ['ok' => $n > 0, 'valides' => $n, 'message' => "$n relevé(s) écrit(s). Arrêt sur une anomalie, à examiner seule."];
            }
            $r = $this->valider((int)$x['Id'], null, $login);
            if (!$r['ok']) return ['ok' => $n > 0, 'valides' => $n, 'message' => "$n relevé(s) écrit(s). " . $r['message']];
            $n++;
        }
        return ['ok' => true, 'valides' => $n, 'message' => "$n relevé(s) écrit(s) dans le module Énergie."];
    }

    public function rejeter(int $executionId, string $login, string $motif = ''): array
    {
        $e = $this->db->fetchOne("SELECT Statut, Message FROM ConnectExecutions WHERE Id = :id", ['id' => $executionId]);
        if (!$e || !in_array($e['Statut'], ['anomalie', 'a_valider'], true)) return ['ok' => false, 'message' => 'Cette échéance n\'attend pas de validation.'];
        $this->db->execute("UPDATE ConnectExecutions SET Statut = 'rejete', ValidePar = :v, DateValidation = :d, Message = :m WHERE Id = :id",
            ['v' => $login, 'd' => date('Y-m-d H:i:s'), 'm' => mb_substr('Rejeté' . ($motif !== '' ? " : $motif" : '') . '. ' . $e['Message'], 0, 500), 'id' => $executionId]);
        return ['ok' => true, 'message' => 'Échéance rejetée : aucun relevé écrit.'];
    }

    /** Aperçu pour l'assistant de création : prochaines échéances, valeur actuelle, dernier relevé. */
    public function apercu(array $v): array
    {
        $pt = $this->db->fetchOne("SELECT Nom, DerniereValeur, Unite, DerniereLecture FROM ConnectPoints WHERE Id = :id", ['id' => (int)$v['PointId']]);
        $echeances = [];
        $t = new DateTimeImmutable('now');
        for ($i = 0; $i < 3; $i++) {
            $t = ConnectPlanif::prochaine($v, $t);
            if (!$t) break;
            $echeances[] = $t->format('Y-m-d H:i:s');
        }
        $actuelle = $pt && $pt['DerniereValeur'] !== null ? round((float)$pt['DerniereValeur'] * (float)($v['Coefficient'] ?: 1), 6) : null;
        [$prec, $datePrec] = $v['Destination'] === 'releve_energie' && $v['CibleId'] ? $this->precedent((int)$v['CibleId']) : [null, null];
        $alerte = null;
        if ($actuelle !== null && $prec !== null && $actuelle < $prec) {
            $alerte = 'La valeur actuelle (' . ConnectAlarmes::nombreFr($actuelle) . ') est inférieure au dernier index du compteur ('
                    . ConnectAlarmes::nombreFr($prec) . ' le ' . date('d/m/Y', (int)strtotime((string)$datePrec)) . ') : le premier relevé sera '
                    . 'mis en anomalie. Vérifiez le compteur choisi et le coefficient.';
        }
        // « À chaque relevé du PC releveur » : sans échéance ; utile seulement si l'appareil est hors réseau.
        if ($v['Frequence'] === 'releve' && $alerte === null) {
            $pa = $this->db->fetchOne("SELECT p.* FROM ConnectPasserelles p JOIN ConnectPoints pt ON pt.PasserelleId = p.Id WHERE pt.Id = :id", ['id' => (int)$v['PointId']]);
            if ($pa && !ConnectDepot::enHotspot($pa)) {
                $alerte = 'Ce point est lu par le serveur, pas par un PC releveur : ce relevé ne se déclencherait jamais. '
                        . 'Choisissez « Chaque jour » (ou une autre fréquence).';
            }
        }
        return ['libelle' => ConnectPlanif::libelle($v), 'echeances' => $echeances, 'sans_echeance' => $v['Frequence'] === 'releve',
                'valeur_actuelle' => $actuelle, 'lecture' => $pt['DerniereLecture'] ?? null, 'dernier_index' => $prec,
                'date_dernier_index' => $datePrec, 'alerte' => $alerte];
    }
}

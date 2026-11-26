<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka Connect — consommations mensuelles des compteurs, import dans le module
 * Énergie, et données à exporter.
 *
 * CONSOMMATION D'UN MOIS : la soustraction des relevés manuels, faite sur
 * l'historique. Index de fin = dernière mesure avant minuit du 1er du mois
 * suivant (ou maintenant, pour le mois en cours) ; index de début = dernière
 * mesure avant minuit du 1er du mois. Les points concernés sont les points de
 * nature « compteur » (index cumulatif).
 *
 *   - Historique commencé en cours de mois : l'index de début est la première
 *     mesure du mois, et la consommation est marquée « partielle ».
 *   - Index en baisse (compteur remplacé, tour de compteur) : la consommation
 *     n'est pas calculée — une soustraction négative n'est pas une conso.
 *   - Mesures de qualité « mauvaise » ignorées, comme pour les relevés auto.
 *
 * IMPORT DANS ÉNERGIE : un relevé de type « Releve », écrit comme une saisie
 * (montant au prix unitaire du compteur, CO₂ au facteur de l'année, origine
 * en commentaire), avec les valeurs VALIDÉES par l'utilisateur : il peut tout
 * ajuster avant d'écrire. Rien n'est écrit sans son clic.
 */

declare(strict_types=1);

final class ConnectConsommations
{
    private Database $db;
    private ConnectDepot $depot;

    /** Types d'énergie proposés au filtre, alignés sur ceux du module Énergie. */
    public const TYPES = ['Electricite', 'Gaz', 'Eau', 'Chauffage', 'Ambiance', 'Autre'];

    public function __construct(ConnectDepot $depot)
    {
        $this->depot = $depot;
        $this->db = $depot->db();
    }

    // ══ Type d'énergie d'un point ══════════════════════════════════════════

    /**
     * Type d'énergie d'un point : celui du compteur Énergie qu'il alimente, sinon
     * deviné d'après son unité et son nom (« m³ » seul ne dit pas eau ou gaz).
     */
    public static function typeEnergie(array $p, ?string $typeCompteur = null): string
    {
        if ($typeCompteur && in_array($typeCompteur, ['Electricite', 'Gaz', 'Eau', 'Chauffage'], true)) return $typeCompteur;
        $u = mb_strtolower(trim((string)($p['Unite'] ?? '')));
        $txt = mb_strtolower(($p['Nom'] ?? '') . ' ' . ($p['Code'] ?? '') . ' ' . ($p['PasserelleNom'] ?? ''));
        $u = str_replace(['³', ' '], ['3', ''], $u);
        if (preg_match('/\b(gaz|gas|gn|grdf)\b/u', $txt)) return 'Gaz';
        if (preg_match('/chauf|chaleur|calori|thermi|cvc|frigori|froid|ecs|eau chaude|réseau de chaleur|reseau de chaleur/u', $txt)
            && !preg_match('/^(°c|°f|k|%.*)$/u', $u)) return 'Chauffage';
        if (preg_match('/^(k|m|g)?wh$|^(k|m)?w$|^(k)?va(r|rh)?$|^v$|^a$|^ma$|^hz$/u', $u)) return 'Electricite';
        if (preg_match('/^(m3|m3\/h|l|litres?|l\/h|l\/min|hl)$/u', $u)) return 'Eau';   // le gaz a été reconnu à son nom plus haut
        if (preg_match('/^(°c|°f|%|%hr|ppm|lux|pa|hpa|bar)$/u', $u) || preg_match('/temp|humid|co2|ambian|confort/u', $txt)) return 'Ambiance';
        if (preg_match('/eau|water/u', $txt)) return 'Eau';
        if (preg_match('/élec|elec|énergie active|energie active/u', $txt)) return 'Electricite';
        return 'Autre';
    }

    /** Type du compteur Énergie lié à chaque point (première liaison active). */
    private function typesLies(): array
    {
        $out = [];
        try {
            foreach ($this->db->fetchAll("SELECT l.PointId, c.Type FROM ConnectLiaisons l JOIN CompteurEnergie c ON c.Id = l.CibleId
                                           WHERE l.Destination = 'releve_energie' ORDER BY l.Actif DESC, l.Id") as $r) {
                $out[(int)$r['PointId']] ??= (string)$r['Type'];
            }
        } catch (\Throwable $e) { /* module Énergie jamais ouvert : pas de table */ }
        return $out;
    }

    /** Type d'énergie de chaque point : [pointId => type]. */
    public function typesPoints(array $points): array
    {
        $lies = $this->typesLies();
        $out = [];
        foreach ($points as $p) $out[(int)$p['Id']] = self::typeEnergie($p, $lies[(int)$p['Id']] ?? null);
        return $out;
    }

    // ══ Index et consommations ═════════════════════════════════════════════

    /** Dernière mesure valable à l'instant donné ou avant : [valeur, horodatage] ou null. */
    private function indexAvant(int $pointId, string $instant): ?array
    {
        $r = $this->db->fetchOne("SELECT Valeur, Horodatage FROM ConnectMesures
                                   WHERE PointId = :p AND Horodatage <= :t AND Valeur IS NOT NULL AND (Qualite IS NULL OR Qualite <> 'mauvaise')
                                   ORDER BY Horodatage DESC LIMIT 1", ['p' => $pointId, 't' => $instant]);
        return $r ? [(float)$r['Valeur'], (string)$r['Horodatage']] : null;
    }

    /** Première mesure valable dans [debut, fin] : [valeur, horodatage] ou null. */
    private function indexApres(int $pointId, string $debut, string $fin): ?array
    {
        $r = $this->db->fetchOne("SELECT Valeur, Horodatage FROM ConnectMesures
                                   WHERE PointId = :p AND Horodatage >= :d AND Horodatage <= :f AND Valeur IS NOT NULL AND (Qualite IS NULL OR Qualite <> 'mauvaise')
                                   ORDER BY Horodatage ASC LIMIT 1", ['p' => $pointId, 'd' => $debut, 'f' => $fin]);
        return $r ? [(float)$r['Valeur'], (string)$r['Horodatage']] : null;
    }

    /** Bornes d'un mois « AAAA-MM » : [début, fin (exclue, ou maintenant)] en « Y-m-d H:i:s ». */
    public static function bornes(string $mois, ?int $maintenant = null): array
    {
        $now = $maintenant ?? time();
        $d = DateTimeImmutable::createFromFormat('!Y-m', $mois) ?: new DateTimeImmutable('first day of this month 00:00');
        $f = $d->modify('first day of next month');
        $fin = min($f->getTimestamp(), $now);
        return [$d->format('Y-m-d H:i:s'), date('Y-m-d H:i:s', $fin), $f->getTimestamp() > $now];
    }

    /**
     * Consommation d'un point sur un mois. Le point peut porter sa dernière
     * valeur (DerniereValeur / DerniereLecture), plus récente que l'historique.
     */
    public function consoMois(array $p, string $mois): array
    {
        [$debut, $fin, $enCours] = self::bornes($mois);
        $id = (int)$p['Id'];
        $out = ['mois' => $mois, 'debut' => null, 'debut_date' => null, 'fin' => null, 'fin_date' => null,
                'conso' => null, 'partiel' => false, 'en_cours' => $enCours, 'anomalie' => null];
        if (strtotime($debut) > time()) return $out;
        $a = $this->indexAvant($id, $debut);
        if (!$a) {
            $a = $this->indexApres($id, $debut, $fin);
            if ($a) $out['partiel'] = true;
        }
        $b = $this->indexAvant($id, $fin);
        // Mois en cours : la valeur courante du point, si elle est plus récente que l'historique.
        if ($enCours && $p['DerniereValeur'] !== null && $p['DerniereLecture'] && ($p['DerniereQualite'] ?? '') !== 'mauvaise'
            && (!$b || strcmp((string)$p['DerniereLecture'], $b[1]) > 0) && strcmp((string)$p['DerniereLecture'], $debut) >= 0) {
            $b = [(float)$p['DerniereValeur'], (string)$p['DerniereLecture']];
        }
        if (!$a || !$b || strcmp($b[1], $debut) < 0) return $out;   // rien dans le mois
        $out['debut'] = $a[0]; $out['debut_date'] = $a[1];
        $out['fin'] = $b[0]; $out['fin_date'] = $b[1];
        $c = round($b[0] - $a[0], 6);
        if ($c < 0) {
            $out['anomalie'] = 'Index en baisse (compteur remplacé, tour de compteur ou erreur de lecture)';
        } else {
            $out['conso'] = $c;
        }
        return $out;
    }

    /**
     * Consommation d'un point entre deux instants quelconques (« Y-m-d H:i:s »,
     * fin exclue) : même règle que consoMois — index de fin moins index de
     * début, historique commencé en route = « partiel », index en baisse =
     * anomalie, jamais une conso négative. Sert à l'assistant (« combien a
     * consommé l'Hôtel de Ville du 1er janvier au 31 mars ? »).
     */
    public function consoPeriode(array $p, string $debut, string $fin): array
    {
        $now = date('Y-m-d H:i:s');
        if (strcmp($fin, $now) > 0) $fin = $now;
        $id = (int)$p['Id'];
        $out = ['debut' => null, 'debut_date' => null, 'fin' => null, 'fin_date' => null, 'conso' => null, 'partiel' => false, 'anomalie' => null];
        if (strcmp($debut, $fin) >= 0) return $out;
        $a = $this->indexAvant($id, $debut);
        if (!$a) {
            $a = $this->indexApres($id, $debut, $fin);
            if ($a) $out['partiel'] = true;
        }
        $b = $this->indexAvant($id, $fin);
        if ($fin === $now && ($p['DerniereValeur'] ?? null) !== null && !empty($p['DerniereLecture']) && ($p['DerniereQualite'] ?? '') !== 'mauvaise'
            && (!$b || strcmp((string)$p['DerniereLecture'], $b[1]) > 0) && strcmp((string)$p['DerniereLecture'], $debut) >= 0) {
            $b = [(float)$p['DerniereValeur'], (string)$p['DerniereLecture']];
        }
        if (!$a || !$b || strcmp($b[1], $debut) < 0) return $out;
        $out['debut'] = $a[0]; $out['debut_date'] = $a[1];
        $out['fin'] = $b[0]; $out['fin_date'] = $b[1];
        $c = round($b[0] - $a[0], 6);
        if ($c < 0) $out['anomalie'] = 'Index en baisse (compteur remplacé, tour de compteur ou erreur de lecture)';
        else $out['conso'] = $c;
        return $out;
    }

    /** Mois « AAAA-MM » précédent. */
    public static function moisPrecedent(string $mois, int $n = 1): string
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m', $mois) ?: new DateTimeImmutable('first day of this month');
        return $d->modify("-$n month")->format('Y-m');
    }

    /**
     * Tableau des consommations d'un mois, pour tous les compteurs (points de
     * nature « compteur ») : conso, mois précédent, série de $historique mois,
     * relevé automatique éventuel et relevé déjà importé ce mois-là.
     */
    public function tableau(string $mois, int $historique = 0): array
    {
        $points = array_values(array_filter($this->depot->points(), fn($p) => ($p['Nature'] ?? '') === 'compteur'));
        $types = $this->typesPoints($points);
        $liaisons = [];
        foreach ($this->depot->liaisons() as $l) {
            if ($l['Destination'] === 'releve_energie') $liaisons[(int)$l['PointId']][] = $l;
        }
        [$debutMois, $finMois] = self::bornes($mois);
        $lignes = [];
        foreach ($points as $p) {
            unset($p['Adresse']);
            $id = (int)$p['Id'];
            $c = $this->consoMois($p, $mois);
            $prec = $this->consoMois($p, self::moisPrecedent($mois));
            $serie = [];
            for ($i = $historique - 1; $i >= 0; $i--) {
                $m = self::moisPrecedent($mois, $i);
                $x = $i === 0 ? $c : $this->consoMois($p, $m);
                $serie[] = ['mois' => $m, 'conso' => $x['conso'], 'partiel' => $x['partiel']];
            }
            $ls = $liaisons[$id] ?? [];
            $auto = null;
            foreach ($ls as $l) if ((int)$l['Actif']) { $auto = ['id' => (int)$l['Id'], 'cible' => (int)$l['CibleId'], 'cible_nom' => $l['CibleNom'],
                                                                 'description' => $l['Description'], 'coefficient' => (float)$l['Coefficient'],
                                                                 'validation' => (int)$l['ValidationManuelle']]; break; }
            $cible = $auto['cible'] ?? (isset($ls[0]) ? (int)$ls[0]['CibleId'] : null);
            $lignes[] = [
                'point' => ['Id' => $id, 'Nom' => $p['Nom'], 'Code' => $p['Code'], 'Unite' => $p['Unite'], 'Decimales' => $p['Decimales'],
                            'PasserelleId' => (int)$p['PasserelleId'], 'PasserelleNom' => $p['PasserelleNom'], 'BatimentNom' => $p['BatimentNom'],
                            'Actif' => (int)$p['Actif'], 'DerniereValeur' => $p['DerniereValeur'], 'DerniereLecture' => $p['DerniereLecture']],
                'type' => $types[$id] ?? 'Autre',
                'conso' => $c, 'precedent' => ['mois' => $prec['mois'], 'conso' => $prec['conso'], 'partiel' => $prec['partiel']],
                'serie' => $serie,
                'automatique' => $auto,
                'cible' => $cible,
                'coefficient' => $auto['coefficient'] ?? (isset($ls[0]) ? (float)$ls[0]['Coefficient'] : null),
                'importe' => $cible ? $this->releveDuMois($cible, $debutMois, $finMois, (string)$p['Nom']) : null,
            ];
        }
        return ['mois' => $mois, 'lignes' => $lignes];
    }

    /** Relevé du compteur Énergie dont la période se termine dans le mois (venu de ce point, de préférence). */
    private function releveDuMois(int $compteurId, string $debut, string $fin, string $nomPoint): ?array
    {
        try {
            $rows = $this->db->fetchAll("SELECT Id, Date, IndexFin, Consommation, Commentaire, SaisieParLogin FROM ReleverEnergie
                                          WHERE CompteurId = :c AND Date >= :d AND Date < :f AND (Type IS NULL OR Type = 'Releve')
                                          ORDER BY Date DESC, Id DESC",
                                        ['c' => $compteurId, 'd' => substr($debut, 0, 10), 'f' => date('Y-m-d', strtotime($fin) + 1)]);
        } catch (\Throwable $e) { return null; }
        if (!$rows) return null;
        $r = null;
        foreach ($rows as $x) if (str_contains((string)$x['Commentaire'], '« ' . $nomPoint . ' »')) { $r = $x; break; }
        $r ??= $rows[0];
        return ['id' => (int)$r['Id'], 'date' => $r['Date'], 'index' => $r['IndexFin'] === null ? null : (float)$r['IndexFin'],
                'conso' => $r['Consommation'] === null ? null : (float)$r['Consommation'], 'par' => $r['SaisieParLogin'],
                'connect' => str_starts_with((string)$r['Commentaire'], 'Larka Connect')];
    }

    // ══ Import dans le module Énergie ══════════════════════════════════════

    /** Dernier index connu d'un compteur Énergie avant une date : [index, date] ou [null, null]. */
    public function dernierIndexCompteur(int $compteurId, ?string $avant = null): array
    {
        return self::dernierIndexDe($this->db, $compteurId, $avant);
    }
    private static function dernierIndexDe(Database $db, int $compteurId, ?string $avant): array
    {
        try {
            $r = $db->fetchOne("SELECT IndexFin, Date FROM ReleverEnergie WHERE CompteurId = :c AND IndexFin IS NOT NULL"
                                     . ($avant ? ' AND Date <= :a' : '') . " ORDER BY Date DESC, Id DESC LIMIT 1",
                                     ['c' => $compteurId] + ($avant ? ['a' => $avant] : []));
        } catch (\Throwable $e) { return [null, null]; }
        return $r ? [(float)$r['IndexFin'], (string)$r['Date']] : [null, null];
    }

    /**
     * Contexte de l'import vers le module Énergie, compteur par compteur : index précédent
     * du relevé et relevé déjà posé ce jour-là ou après. Statique : sur un serveur de
     * supervision séparé, le PRINCIPAL le calcule dans SA base (routes/connect.php) — la
     * supervision n'a que les relevés qu'elle a écrits elle-même, pas ceux saisis à la main.
     */
    public static function contexteImport(Database $db, array $compteurs, ?string $avant, ?string $date): array
    {
        $out = [];
        foreach (array_filter(array_map('intval', $compteurs)) as $cid) {
            // Index précédent du RELEVÉ (date du relevé), comme le formulaire du module Énergie ;
            // à défaut de date, l'ancienne règle (dernier relevé jusqu'au début de la période).
            [$idx, $d] = $date ? self::indexPrecedentDe($db, $cid, $date) : self::dernierIndexDe($db, $cid, $avant);
            [$sIdx, $sDate] = $date ? self::releveSuivantDe($db, $cid, $date) : [null, null];
            $out[$cid] = ['index' => $idx, 'date' => $d, 'suivant_index' => $sIdx, 'suivant_date' => $sDate];
        }
        return $out;
    }

    /**
     * Index PRÉCÉDENT d'un relevé daté $date sur un compteur Énergie : [index, date] ou [null, null].
     *
     * L'IMPORT DEPUIS LA SUPERVISION NE PRENAIT PAS LE BON INDEX PRÉCÉDENT.
     * Il cherchait le dernier relevé jusqu'au DÉBUT de la période (le 1er du mois) :
     * un relevé saisi en cours de mois était ignoré, et l'index de début proposé
     * était celui de l'historique du point, pas celui du compteur Énergie — la
     * consommation écrite ne prolongeait pas le relevé d'avant. Même règle que le
     * formulaire de relevé du module Énergie (energie.releve.js) : le relevé le plus
     * récent STRICTEMENT avant la date, un relevé (pas une facture) de préférence,
     * à date égale le dernier saisi.
     */
    public function indexPrecedent(int $compteurId, string $date): array
    {
        return self::indexPrecedentDe($this->db, $compteurId, $date);
    }
    private static function indexPrecedentDe(Database $db, int $compteurId, string $date): array
    {
        try {
            foreach (["AND (Type IS NULL OR Type = '' OR Type = 'Releve')", ''] as $filtre) {
                $r = $db->fetchOne("SELECT IndexFin, Date FROM ReleverEnergie WHERE CompteurId = :c AND IndexFin IS NOT NULL
                                           AND SUBSTR(Date, 1, 10) < :d $filtre ORDER BY Date DESC, Id DESC LIMIT 1",
                                         ['c' => $compteurId, 'd' => substr($date, 0, 10)]);
                if ($r) return [(float)$r['IndexFin'], substr((string)$r['Date'], 0, 10)];
            }
        } catch (\Throwable $e) {}
        return [null, null];
    }

    /** Premier relevé du compteur Énergie le même jour ou APRÈS une date : [index, date] ou [null, null]. */
    public function releveSuivant(int $compteurId, string $apres): array
    {
        return self::releveSuivantDe($this->db, $compteurId, $apres);
    }
    private static function releveSuivantDe(Database $db, int $compteurId, string $apres): array
    {
        try {
            // Le même jour compte : un relevé déjà posé ce jour-là, c'est lui que l'import doublerait.
            $r = $db->fetchOne("SELECT IndexFin, Date FROM ReleverEnergie WHERE CompteurId = :c AND IndexFin IS NOT NULL AND SUBSTR(Date, 1, 10) >= :a
                                       ORDER BY Date ASC, Id ASC LIMIT 1", ['c' => $compteurId, 'a' => substr($apres, 0, 10)]);
        } catch (\Throwable $e) { return [null, null]; }
        return $r ? [(float)$r['IndexFin'], substr((string)$r['Date'], 0, 10)] : [null, null];
    }

    /**
     * Valide une ligne d'import : [valeurs, erreur]. Les index sont ceux SAISIS
     * (déjà multipliés par le coefficient côté écran : ce qu'on voit est écrit).
     */
    public function normaliserImport(array $d): array
    {
        $pid = (int)($d['pointId'] ?? 0);
        $p = $pid ? $this->depot->point($pid) : null;
        if (!$p) return [null, 'Point introuvable.'];
        $cid = (int)($d['compteurId'] ?? 0);
        $cpt = $cid ? $this->db->fetchOne("SELECT * FROM CompteurEnergie WHERE Id = :id", ['id' => $cid]) : null;
        if (!$cpt) return [null, "« {$p['Nom']} » : choisissez le compteur Énergie qui reçoit le relevé."];
        $nb = fn($v) => ($v === null || $v === '') ? null : ConnectPiloteBase::nombre($v);
        $fin = $nb($d['indexFin'] ?? null);
        $deb = $nb($d['indexDebut'] ?? null);
        if ($fin === null) return [null, "« {$p['Nom']} » : index de fin manquant."];
        if ($deb !== null && $fin < $deb) return [null, "« {$p['Nom']} » : l'index de fin est inférieur à l'index de début."];
        $date = (string)($d['date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) return [null, "« {$p['Nom']} » : date du relevé invalide."];
        $pdeb = (string)($d['periodeDebut'] ?? '');
        if ($pdeb !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $pdeb) || $pdeb > $date)) return [null, "« {$p['Nom']} » : début de période invalide."];
        return [['point' => $p, 'compteur' => $cpt, 'indexFin' => $fin, 'indexDebut' => $deb, 'date' => $date,
                 'periodeDebut' => $pdeb !== '' ? $pdeb : null, 'note' => mb_substr(trim((string)($d['commentaire'] ?? '')), 0, 300)], null];
    }
}

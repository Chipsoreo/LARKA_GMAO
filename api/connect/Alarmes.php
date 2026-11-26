<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka Connect — règles, alarmes, demandes d'intervention.
 *
 * Une alarme naît de quatre façons :
 *   - une RÈGLE sur un point : seuil, plage, état, qualité, absence de valeur,
 *     ou PALIER (compteur d'heures, de démarrages : « tous les 2 000 h ») ;
 *   - l'ÉQUIPEMENT lui-même : alarmes importées d'un superviseur REST, défaut
 *     signalé par une borne OCPP ;
 *   - la CONNEXION : un équipement injoignable trois collectes de suite ;
 *   - une LIAISON : relevé attendu et introuvable.
 *
 * Garde-fous :
 *   - temporisation : la condition doit durer « DelaiMinutes » avant l'alarme ;
 *   - hystérésis : pour un seuil haut, la fin d'alarme peut exiger de
 *     redescendre sous un seuil de retour (Seuil2) — pas de battement autour
 *     d'une valeur limite ;
 *   - pas de rafale de demandes : une alarme qui revient alors que la demande
 *     créée par la précédente est encore ouverte se rattache à cette demande.
 */

declare(strict_types=1);

final class ConnectAlarmes
{
    private ConnectDepot $depot;
    private Database $db;
    private PDO $pdo;
    private string $organisation;
    /** Événements de ce passage, pour le compte rendu. */
    public array $ouvertes = [];
    public array $fermees = [];

    public function __construct(ConnectDepot $depot, string $organisation = 'default')
    {
        $this->depot = $depot;
        $this->db = $depot->db();
        $this->pdo = $this->db->getPdo();
        $this->organisation = $organisation;
    }

    public static function nombreFr(?float $v, int $dec = 2): string
    {
        if ($v === null) return '—';
        $t = number_format($v, $dec, ',', "\u{202F}");
        return str_contains($t, ',') ? rtrim(rtrim($t, '0'), ',') : $t;
    }

    private static function avecUnite(?float $v, array $point): string
    {
        $u = trim((string)($point['Unite'] ?? ''));
        return self::nombreFr($v, (int)($point['Decimales'] ?? 2)) . ($u !== '' ? ' ' . $u : '');
    }

    // ══ Règles d'un point ══════════════════════════════════════════════════

    /**
     * Évalue les règles d'un point après une lecture.
     * @param array $lecture ['valeur', 'texte', 'qualite', 'horodatage', 'ancienne'?]
     */
    public function evaluerPoint(array $point, array $lecture): void
    {
        $regles = $this->db->fetchAll("SELECT * FROM ConnectRegles WHERE PointId = :p AND Actif = 1 AND Operateur != 'absence'",
                                      ['p' => (int)$point['Id']]);
        if (!$regles) return;
        $v = isset($lecture['valeur']) ? (float)$lecture['valeur'] : null;
        $txt = isset($lecture['texte']) ? (string)$lecture['texte'] : null;
        $qualite = (string)($lecture['qualite'] ?? 'bonne');
        $maintenant = date('Y-m-d H:i:s');
        foreach ($regles as $r) {
            $op = (string)$r['Operateur'];
            $s1 = $r['Seuil'] === null ? null : (float)$r['Seuil'];
            $s2 = $r['Seuil2'] === null ? null : (float)$r['Seuil2'];
            if ($op === 'palier') { $this->palier($point, $r, $v); continue; }
            if ($op !== 'qualite' && $v === null && $txt === null) continue;     // pas de valeur : état inchangé
            $active = $this->alarmeActive('regle', (int)$point['PasserelleId'], (int)$point['Id'], (int)$r['Id'], null);
            $cond = false;
            switch ($op) {
                case 'sup':
                    $cond = $v !== null && $s1 !== null && ($active && $s2 !== null && $s2 < $s1 ? $v > $s2 : $v > $s1);
                    break;
                case 'inf':
                    $cond = $v !== null && $s1 !== null && ($active && $s2 !== null && $s2 > $s1 ? $v < $s2 : $v < $s1);
                    break;
                case 'hors_plage':
                    $cond = $v !== null && $s1 !== null && $s2 !== null && ($v < $s1 || $v > $s2);
                    break;
                case 'egal':
                case 'different':
                    $attendu = trim((string)($r['ValeurTexte'] ?? ''));
                    $egal = $attendu !== ''
                        ? (mb_strtolower(trim((string)($txt ?? self::nombreFr($v, 6)))) === mb_strtolower($attendu)
                           || ($v !== null && ConnectPiloteBase::nombre($attendu) !== null && abs($v - (float)ConnectPiloteBase::nombre($attendu)) < 1e-9))
                        : ($v !== null && $s1 !== null && abs($v - $s1) < 1e-9);
                    $cond = $op === 'egal' ? $egal : !$egal;
                    break;
                case 'qualite':
                    $cond = $qualite === 'mauvaise';
                    break;
            }
            $this->appliquer($point, $r, $cond, $active, $v, $txt, $maintenant);
        }
    }

    /** Temporisation, puis ouverture ou fermeture. */
    private function appliquer(array $point, array $r, bool $cond, ?array $active, ?float $v, ?string $txt, string $maintenant): void
    {
        if (!$cond) {
            if ($r['EnCoursDepuis'] !== null) $this->pdo->prepare("UPDATE ConnectRegles SET EnCoursDepuis = NULL WHERE Id = ?")->execute([(int)$r['Id']]);
            if ($active) $this->fermer((int)$active['Id']);
            return;
        }
        if ($active) return;
        $depuis = $r['EnCoursDepuis'];
        if ($depuis === null) {
            $depuis = $maintenant;
            $this->pdo->prepare("UPDATE ConnectRegles SET EnCoursDepuis = ? WHERE Id = ?")->execute([$depuis, (int)$r['Id']]);
        }
        $delai = (int)($r['DelaiMinutes'] ?? 0) * 60;
        if (time() - (int)strtotime((string)$depuis) < $delai) return;
        $this->ouvrir([
            'origine' => 'regle', 'passerelle' => (int)$point['PasserelleId'], 'point' => (int)$point['Id'], 'regle' => (int)$r['Id'],
            'gravite' => (string)$r['Gravite'], 'valeur' => $v,
            'message' => $this->messageRegle($point, $r, $v, $txt),
            'demande' => (bool)$r['CreerDemande'], 'notifier' => (bool)$r['Notifier'],
            'detail' => 'Règle : ' . $this->texteRegle($point, $r),
        ]);
    }

    /** « supérieur à 27 °C (fin sous 25 °C) » — ou en anglais pour l'écran (les alarmes, elles, restent écrites en français). */
    public function texteRegle(array $point, array $r, bool $en = false): string
    {
        $u = fn($x) => self::avecUnite($x === null ? null : (float)$x, $point);
        if ($en) {
            $txt = trim((string)($r['ValeurTexte'] ?? ''));
            $t = match ((string)$r['Operateur']) {
                'sup' => 'above ' . $u($r['Seuil']) . ($r['Seuil2'] !== null ? ' (ends below ' . $u($r['Seuil2']) . ')' : ''),
                'inf' => 'below ' . $u($r['Seuil']) . ($r['Seuil2'] !== null ? ' (ends above ' . $u($r['Seuil2']) . ')' : ''),
                'hors_plage' => 'outside the range ' . $u($r['Seuil']) . ' – ' . $u($r['Seuil2']),
                'egal' => 'equal to ' . ($txt !== '' ? "“{$txt}”" : $u($r['Seuil'])),
                'different' => 'different from ' . ($txt !== '' ? "“{$txt}”" : $u($r['Seuil'])),
                'qualite' => 'faulty value (sensor or device)',
                'absence' => 'no value received for ' . (int)$r['DelaiMinutes'] . ' min',
                'palier' => 'every ' . $u($r['Seuil']),
                default => (string)$r['Operateur'],
            };
            if ((int)($r['DelaiMinutes'] ?? 0) > 0 && !in_array($r['Operateur'], ['absence', 'palier'], true)) $t .= ' for ' . (int)$r['DelaiMinutes'] . ' min';
            return $t;
        }
        $t = match ((string)$r['Operateur']) {
            'sup' => 'supérieur à ' . $u($r['Seuil']) . ($r['Seuil2'] !== null ? ' (fin sous ' . $u($r['Seuil2']) . ')' : ''),
            'inf' => 'inférieur à ' . $u($r['Seuil']) . ($r['Seuil2'] !== null ? ' (fin au-dessus de ' . $u($r['Seuil2']) . ')' : ''),
            'hors_plage' => 'hors de la plage ' . $u($r['Seuil']) . ' – ' . $u($r['Seuil2']),
            'egal' => 'égal à ' . (trim((string)$r['ValeurTexte']) !== '' ? '« ' . $r['ValeurTexte'] . ' »' : $u($r['Seuil'])),
            'different' => 'différent de ' . (trim((string)$r['ValeurTexte']) !== '' ? '« ' . $r['ValeurTexte'] . ' »' : $u($r['Seuil'])),
            'qualite' => 'valeur en défaut (capteur ou équipement)',
            'absence' => 'aucune valeur reçue depuis ' . (int)$r['DelaiMinutes'] . ' min',
            'palier' => 'tous les ' . $u($r['Seuil']),
            default => (string)$r['Operateur'],
        };
        if ((int)($r['DelaiMinutes'] ?? 0) > 0 && !in_array($r['Operateur'], ['absence', 'palier'], true)) $t .= ' pendant ' . (int)$r['DelaiMinutes'] . ' min';
        return $t;
    }

    private function messageRegle(array $point, array $r, ?float $v, ?string $txt): string
    {
        $valeur = $txt !== null && $txt !== '' && $v === null ? "« $txt »" : self::avecUnite($v, $point);
        $nom = trim((string)($r['Nom'] ?? ''));
        $base = $nom !== '' ? $nom : '« ' . $point['Nom'] . ' » ' . $this->texteRegle($point, $r);
        return $base . ($r['Operateur'] === 'qualite' ? '' : " — valeur : $valeur");
    }

    /** Palier : maintenance conditionnelle (« toutes les 2 000 heures »). Événement ponctuel. */
    private function palier(array $point, array $r, ?float $v): void
    {
        $pas = (float)($r['Seuil'] ?? 0);
        if ($v === null || $pas <= 0) return;
        $atteint = floor($v / $pas) * $pas;
        if ($r['DernierPalier'] === null) {
            // Première lecture : on repère le palier courant sans rien déclencher.
            $this->pdo->prepare("UPDATE ConnectRegles SET DernierPalier = ? WHERE Id = ?")->execute([$atteint, (int)$r['Id']]);
            return;
        }
        $precedent = (float)$r['DernierPalier'];
        if ($atteint < $precedent) {
            // Compteur remis à zéro (remplacement) : on repart de là, sans alarme.
            $this->pdo->prepare("UPDATE ConnectRegles SET DernierPalier = ? WHERE Id = ?")->execute([$atteint, (int)$r['Id']]);
            return;
        }
        if ($atteint < $precedent + $pas) return;
        $this->pdo->prepare("UPDATE ConnectRegles SET DernierPalier = ? WHERE Id = ?")->execute([$atteint, (int)$r['Id']]);
        $nom = trim((string)($r['Nom'] ?? '')) ?: 'Maintenance conditionnelle';
        $id = $this->ouvrir([
            'origine' => 'regle', 'passerelle' => (int)$point['PasserelleId'], 'point' => (int)$point['Id'], 'regle' => (int)$r['Id'],
            'gravite' => (string)$r['Gravite'], 'valeur' => $v,
            'message' => "$nom : « {$point['Nom']} » a atteint " . self::avecUnite($atteint, $point),
            'demande' => (bool)$r['CreerDemande'], 'notifier' => (bool)$r['Notifier'],
            'detail' => 'Règle : ' . $this->texteRegle($point, $r) . ' — valeur lue : ' . self::avecUnite($v, $point),
            'ponctuelle' => true,
        ]);
        if ($id) $this->fermer($id);
    }

    /** Règles « absence de valeur » : à évaluer à chaque passage, même sans lecture. */
    public function evaluerAbsences(): void
    {
        $regles = $this->db->fetchAll(
            "SELECT r.*, pt.Nom AS PointNom, pt.PasserelleId, pt.DerniereLecture, pt.Unite, pt.Decimales, pt.Nom AS Nom_point
               FROM ConnectRegles r JOIN ConnectPoints pt ON pt.Id = r.PointId
               JOIN ConnectPasserelles pa ON pa.Id = pt.PasserelleId
              WHERE r.Actif = 1 AND r.Operateur = 'absence' AND pt.Actif = 1 AND pa.Actif = 1");
        foreach ($regles as $r) {
            $point = ['Id' => (int)$r['PointId'], 'Nom' => (string)$r['PointNom'], 'PasserelleId' => (int)$r['PasserelleId'],
                      'Unite' => $r['Unite'], 'Decimales' => $r['Decimales']];
            $derniere = $r['DerniereLecture'] ? (int)strtotime((string)$r['DerniereLecture']) : 0;
            $cond = (time() - $derniere) >= (int)$r['DelaiMinutes'] * 60;
            $active = $this->alarmeActive('regle', $point['PasserelleId'], $point['Id'], (int)$r['Id'], null);
            if (!$cond) { if ($active) $this->fermer((int)$active['Id']); continue; }
            if ($active) continue;
            $nom = trim((string)($r['Nom'] ?? ''));
            $this->ouvrir([
                'origine' => 'regle', 'passerelle' => $point['PasserelleId'], 'point' => $point['Id'], 'regle' => (int)$r['Id'],
                'gravite' => (string)$r['Gravite'], 'valeur' => null,
                'message' => ($nom !== '' ? "$nom : " : '') . "aucune valeur de « {$point['Nom']} » depuis "
                    . ($derniere ? date('d/m/Y à H:i', $derniere) : 'sa création'),
                'demande' => (bool)$r['CreerDemande'], 'notifier' => (bool)$r['Notifier'],
                'detail' => 'Règle : ' . $this->texteRegle($point, $r),
            ]);
        }
    }

    // ══ Alarmes de connexion et d'équipement ═══════════════════════════════

    /** Connexion injoignable plusieurs passages de suite ↔ alarme. */
    public function connexion(array $passerelle, bool $enDefaut, string $message, int $echecs): void
    {
        $active = $this->alarmeActive('connexion', (int)$passerelle['Id'], null, null, 'connexion');
        if (!$enDefaut) { if ($active) $this->fermer((int)$active['Id']); return; }
        if ($active || $echecs < 3) return;
        $this->ouvrir([
            'origine' => 'connexion', 'passerelle' => (int)$passerelle['Id'], 'code' => 'connexion', 'gravite' => 'majeure',
            'message' => "Connexion « {$passerelle['Nom']} » injoignable : $message", 'demande' => false, 'notifier' => true,
            'detail' => "$echecs collectes de suite en échec.",
        ]);
    }

    /**
     * Alarmes signalées par l'équipement (superviseur REST, borne OCPP) :
     * ouvre celles qui apparaissent, ferme celles qui ont disparu.
     * @param list<array{code: string, message: string, gravite: string, equipement?: string}> $liste
     */
    public function synchroniserEquipement(array $passerelle, array $liste, bool $versDemande): void
    {
        $vues = [];
        foreach ($liste as $a) {
            $code = mb_substr(trim(($a['equipement'] ?? '') . ':' . ($a['code'] ?? '')), 0, 120);
            $vues[$code] = true;
            if ($this->alarmeActive('equipement', (int)$passerelle['Id'], null, null, $code)) continue;
            $g = strtolower((string)($a['gravite'] ?? 'mineure'));
            $g = in_array($g, ConnectDepot::GRAVITES, true) ? $g : (in_array($g, ['major', 'high', 'haute'], true) ? 'majeure'
               : (in_array($g, ['critical', 'urgent', 'urgente'], true) ? 'critique' : 'mineure'));
            $this->ouvrir([
                'origine' => 'equipement', 'passerelle' => (int)$passerelle['Id'], 'code' => $code, 'gravite' => $g,
                'message' => trim(($a['equipement'] ?? '') !== '' ? $a['equipement'] . ' — ' : '') . ($a['message'] ?? 'Alarme'),
                'demande' => $versDemande && in_array($g, ['majeure', 'critique'], true), 'notifier' => true,
                'detail' => "Alarme signalée par l'équipement (connexion « {$passerelle['Nom']} »).",
            ]);
        }
        foreach ($this->db->fetchAll("SELECT Id, Code FROM ConnectAlarmes WHERE Origine = 'equipement' AND PasserelleId = :p AND Etat = 'active'",
                                     ['p' => (int)$passerelle['Id']]) as $a) {
            if (!isset($vues[(string)$a['Code']])) $this->fermer((int)$a['Id']);
        }
    }

    // ══ Ouverture, fermeture ═══════════════════════════════════════════════

    public function alarmeActive(string $origine, int $passerelle, ?int $point, ?int $regle, ?string $code): ?array
    {
        $w = ["Origine = :o", "PasserelleId = :pa", "Etat = 'active'"];
        $p = ['o' => $origine, 'pa' => $passerelle];
        if ($point !== null) { $w[] = 'PointId = :pt'; $p['pt'] = $point; }
        if ($regle !== null) { $w[] = 'RegleId = :r'; $p['r'] = $regle; }
        if ($code !== null) { $w[] = 'Code = :c'; $p['c'] = $code; }
        return $this->db->fetchOne("SELECT * FROM ConnectAlarmes WHERE " . implode(' AND ', $w), $p);
    }

    /**
     * Ouvre une alarme, crée la demande si voulu, notifie.
     * @param array $a origine, passerelle, point?, regle?, code?, gravite, message, valeur?, demande, notifier, detail?
     */
    public function ouvrir(array $a): ?int
    {
        $maintenant = date('Y-m-d H:i:s');
        $st = $this->pdo->prepare("INSERT INTO ConnectAlarmes (PointId, RegleId, PasserelleId, Origine, Code, Gravite, Message,
                                        ValeurDeclenchement, Debut, Etat, Acquittee) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', 0)");
        $st->execute([$a['point'] ?? null, $a['regle'] ?? null, $a['passerelle'] ?? null, $a['origine'], $a['code'] ?? null,
                      $a['gravite'] ?? 'majeure', mb_substr((string)$a['message'], 0, 500), $a['valeur'] ?? null, $maintenant]);
        $id = (int)$this->pdo->lastInsertId();
        if ($id <= 0) {
            $id = (int)($this->db->fetchOne("SELECT MAX(Id) AS Id FROM ConnectAlarmes WHERE Debut = :d", ['d' => $maintenant])['Id'] ?? 0);
        }
        $this->ouvertes[] = $id;
        Connect::journal(in_array($a['gravite'] ?? '', ['majeure', 'critique'], true) ? 'avertissement' : 'info',
            'Alarme : ' . $a['message'], ['alarme' => $id, 'organisation' => $this->organisation]);
        $demandeId = null;
        $a['_alarme'] = $id;
        if (!empty($a['demande'])) {
            try { $demandeId = $this->demande($a); } catch (\Throwable $e) {
                Connect::journal('erreur', 'Demande d\'intervention automatique impossible', ['exception' => $e]);
            }
            if ($demandeId) $this->pdo->prepare("UPDATE ConnectAlarmes SET DemandeId = ? WHERE Id = ?")->execute([$demandeId, $id]);
        }
        if (!empty($a['notifier'])) $this->notifier($a, $demandeId);
        return $id;
    }

    public function fermer(int $id): void
    {
        $this->pdo->prepare("UPDATE ConnectAlarmes SET Etat = 'terminee', Fin = ? WHERE Id = ? AND Etat = 'active'")
                  ->execute([date('Y-m-d H:i:s'), $id]);
        $this->fermees[] = $id;
    }

    /** Demande d'intervention : rattachée à la précédente si elle est encore ouverte (anti-rafale). */
    private function demande(array $a): ?int
    {
        // Même origine récemment, demande encore ouverte : on la réutilise.
        $w = "a.Origine = :o AND a.PasserelleId = :pa AND a.DemandeId IS NOT NULL AND d.Statut NOT IN ('Traité', 'Refusé')";
        $p = ['o' => $a['origine'], 'pa' => (int)($a['passerelle'] ?? 0)];
        if (!empty($a['regle'])) { $w .= ' AND a.RegleId = :r'; $p['r'] = (int)$a['regle']; }
        elseif (!empty($a['code'])) { $w .= ' AND a.Code = :c'; $p['c'] = (string)$a['code']; }
        if (empty($a['ponctuelle'])) {
            $prec = $this->db->fetchOne("SELECT a.DemandeId FROM ConnectAlarmes a JOIN DemandesIntervention d ON d.Id = a.DemandeId
                                          WHERE $w ORDER BY a.Id DESC LIMIT 1", $p);
            if ($prec) return (int)$prec['DemandeId'];
        }
        $pa = !empty($a['passerelle']) ? $this->db->fetchOne(
            "SELECT pa.Nom, b.Nom AS BatimentNom FROM ConnectPasserelles pa LEFT JOIN PlanBatiments b ON b.Id = pa.BatimentId WHERE pa.Id = :id",
            ['id' => (int)$a['passerelle']]) : null;
        $pt = !empty($a['point']) ? $this->db->fetchOne(
            "SELECT pt.Nom, pt.EquipementId, e.Numero AS EquipementNumero, e.Batiment, e.Etage, e.NumeroBureau
               FROM ConnectPoints pt LEFT JOIN Equipements e ON e.Id = pt.EquipementId WHERE pt.Id = :id", ['id' => (int)$a['point']]) : null;
        $batiment = (string)($pt['Batiment'] ?? '') ?: (string)($pa['BatimentNom'] ?? '');
        $urgence = ['critique' => 'Urgente', 'majeure' => 'Haute', 'mineure' => 'Normale', 'info' => 'Basse'][$a['gravite'] ?? 'majeure'] ?? 'Normale';
        $lignes = ['Alarme détectée par Larka Connect le ' . date('d/m/Y à H:i') . '.', '', (string)$a['message']];
        if (!empty($a['detail'])) $lignes[] = (string)$a['detail'];
        if ($pt) $lignes[] = 'Point : ' . $pt['Nom'] . (!empty($pt['EquipementNumero']) ? ' — équipement ' . $pt['EquipementNumero'] : '');
        if ($pa) $lignes[] = 'Connexion : ' . $pa['Nom'];
        $lignes[] = 'Gravité : ' . ($a['gravite'] ?? 'majeure');
        $donnees = [
            'titre' => mb_substr('Alarme technique : ' . $a['message'], 0, 150),
            'description' => implode("\n", $lignes),
            'batiment' => $batiment, 'bureau' => trim(((string)($pt['Etage'] ?? '')) . ' ' . ((string)($pt['NumeroBureau'] ?? ''))),
            'urgence' => $urgence, 'emailDemandeur' => '', 'typeLocalisation' => 'Standard',
        ];
        // Serveur de supervision séparé : la demande appartient à la GMAO. Elle est
        // transmise au serveur principal, qui la crée dans SA base ; son numéro
        // revient à la synchronisation suivante (moins d'une minute).
        if (class_exists('SupervisionLien') && SupervisionLien::estSupervision($this->db)) {
            SupervisionLien::sortant($this->db, 'demande', ['alarme' => (int)($a['_alarme'] ?? 0), 'donnees' => $donnees]);
            return null;
        }
        $technique = Connect::compteTechnique($this->db);
        $id = $this->db->addDemande($donnees, $technique);
        Connect::journal('info', "Demande d'intervention n° $id créée par une alarme", ['demande' => $id]);
        return $id;
    }

    /** Notification push aux gestionnaires (si les notifications sont configurées). */
    private function notifier(array $a, ?int $demandeId): void
    {
        // Serveur de supervision séparé : les abonnements sont ceux du serveur principal.
        if (class_exists('SupervisionLien') && SupervisionLien::estSupervision($this->db)) {
            SupervisionLien::sortant($this->db, 'notification', ['alarme' => (int)($a['_alarme'] ?? 0),
                'alarme_donnees' => array_intersect_key($a, array_flip(['message', 'gravite', 'origine', 'regle', 'code', 'passerelle']))]);
            return;
        }
        self::envoyerNotification($this->db, $a, $demandeId);
    }

    /** L'envoi lui-même (aussi appelé par le serveur principal pour une alarme du serveur de supervision). */
    public static function envoyerNotification(Database $db, array $a, ?int $demandeId): void
    {
        if (!(defined('PUSH_ACTIF') && PUSH_ACTIF && defined('VAPID_PRIVATE_PEM') && VAPID_PRIVATE_PEM)) return;
        try {
            require_once dirname(__DIR__) . '/WebPush.php';
            $subs = $db->getAllPushSubscriptions(null, ['Admin', 'Gestionnaire']);
            if (!$subs) return;
            $icone = ['critique' => '', 'majeure' => '', 'mineure' => '', 'info' => ''][$a['gravite'] ?? 'majeure'] ?? '';
            $payload = json_encode([
                'title' => "$icone Alarme technique",
                'body' => mb_substr((string)$a['message'], 0, 180) . ($demandeId ? " (demande n° $demandeId)" : ''),
                'tag' => 'larka-connect-' . ($a['origine'] ?? '') . '-' . ($a['regle'] ?? $a['code'] ?? '') . '-' . ($a['passerelle'] ?? ''),
                'icon' => '/icon.png', 'data' => ['page' => 'connect_alarmes'], 'renotify' => true,
                'requireInteraction' => ($a['gravite'] ?? '') === 'critique',
            ], JSON_UNESCAPED_UNICODE);
            $wp = new WebPush(VAPID_PRIVATE_PEM, VAPID_PUBLIC_KEY, VAPID_SUBJECT);
            $res = $wp->sendBatch(array_map(fn($s) => ['endpoint' => $s['Endpoint'], 'keys' => ['p256dh' => $s['P256dh'], 'auth' => $s['Auth']]], $subs), $payload);
            foreach ($subs as $i => $s) {
                if (in_array($res[$i]['statusCode'] ?? 0, [404, 410], true)) $db->deletePushSubscription($s['Endpoint']);
            }
        } catch (\Throwable $e) {
            error_log('[Larka Connect] notification : ' . $e->getMessage());
        }
    }
}

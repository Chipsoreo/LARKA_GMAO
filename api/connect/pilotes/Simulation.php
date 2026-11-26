<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka Connect — pilote « Simulateur intégré ».
 *
 * Des équipements FICTIFS calculés par Larka lui-même : démonstration,
 * formation, essais de règles d'alarme et de relevés automatiques — sans
 * matériel, sans réseau, sans programme à lancer à côté.
 *
 * Les simulateurs Python (outils/simulateurs/) imitent des équipements RÉELS,
 * protocole compris, et tournent en temps réel : une semaine de courbes
 * demande une semaine. Ici, chaque valeur est une fonction de l'instant
 * (saison, jour de la semaine, heure, occupation, météo simulée) :
 *   - à la première lecture, la connexion peut produire tout un HISTORIQUE
 *     (« historique_jours », 400 au plus) en quelques secondes : courbes,
 *     règles et relevés automatiques sont tout de suite exploitables ;
 *   - un serveur arrêté quelques jours retrouve, au redémarrage, les valeurs
 *     de la période manquée (comme une passerelle qui vide sa mémoire) ;
 *   - les index sont l'intégrale exacte de la puissance ou du débit simulés :
 *     puissance et index racontent la même histoire, et les relevés qui en
 *     sortent sont cohérents avec le module Énergie.
 * Des incidents reproductibles (panne de chauffage, fuite nocturne, salle
 * surpeuplée) déclenchent les règles d'alarme : c'est le but d'une démo.
 *
 * Tout est déterministe (même instant, même point → même valeur) : deux
 * lectures successives ne « sautent » pas, et une démonstration se rejoue.
 */

declare(strict_types=1);

final class ConnectPiloteSimulation extends ConnectPiloteBase
{
    /** Pas de l'historique produit (secondes). */
    public const PAS = 900;
    /** Pas d'intégration des index (secondes). */
    private const PAS_INTEGRATION = 300;

    /** profil => [libellé, unité, nature, consommation journalière type] */
    public const PROFILS = [
        'temperature_exterieure' => ['Température extérieure', '°C', 'mesure', null],
        'temperature_interieure' => ['Température intérieure', '°C', 'mesure', null],
        'humidite'               => ['Humidité relative', '%', 'mesure', null],
        'co2'                    => ['Qualité de l\'air (CO₂)', 'ppm', 'mesure', null],
        'puissance'              => ['Puissance électrique appelée', 'kW', 'mesure', 600.0],
        'index_electrique'       => ['Index électrique', 'kWh', 'compteur', 600.0],
        'debit_eau'              => ['Débit d\'eau', 'm³/h', 'mesure', 6.0],
        'index_eau'              => ['Index eau', 'm³', 'compteur', 6.0],
        'index_chaleur'          => ['Index chaleur (calories)', 'MWh', 'compteur', null],
        'etat_chaudiere'         => ['Marche chaudière', '', 'etat', null],
        'consigne'               => ['Consigne de chauffage', '°C', 'consigne', null],
    ];
    /** Énergie de chauffage par degré-jour (MWh/DJU) quand rien n'est précisé. */
    private const CHALEUR_PAR_DJU = 0.12;

    private static array $normes = [];

    public static function description(): array
    {
        $opts = [];
        foreach (self::PROFILS as $k => $p) $opts[] = [$k, $p[0] . ($p[1] !== '' ? " ({$p[1]})" : '')];
        return [
            'protocole' => 'simulation',
            'libelle' => 'Simulateur intégré',
            'resume' => 'Équipements fictifs calculés par Larka : démonstration, formation, essais de règles et de relevés automatiques — sans matériel ni programme à lancer.',
            'collecte' => 'interrogation',
            'port_defaut' => null,
            'intervalle_defaut' => 300,
            'hote' => null,
            'identifiant' => null,
            'champs' => [
                ['cle' => 'historique_jours', 'libelle' => 'Historique produit à la première lecture (jours)', 'type' => 'nombre', 'defaut' => 30,
                 'aide' => '0 : aucun. Jusqu\'à 400 jours, calculés en quelques secondes : courbes et relevés automatiques tout de suite disponibles.'],
                ['cle' => 'occupation', 'libelle' => 'Occupation du bâtiment', 'type' => 'choix', 'defaut' => 'bureaux',
                 'options' => [['bureaux', 'Bureaux (lundi au vendredi, 8 h - 18 h)'], ['ecole', 'École (vacances scolaires, mercredi après-midi)'],
                               ['continu', 'Continu (jour et nuit)']]],
                ['cle' => 'incidents', 'libelle' => 'Incidents simulés (panne de chauffage, fuite, salle surpeuplée, classe confinée)', 'type' => 'booleen', 'defaut' => true],
            ],
            'secrets' => [],
            'adresse' => [
                ['cle' => 'profil', 'libelle' => 'Grandeur simulée', 'type' => 'choix', 'requis' => true, 'defaut' => 'temperature_interieure',
                 'options' => $opts],
                ['cle' => 'conso_jour', 'libelle' => 'Consommation moyenne par jour', 'type' => 'nombre',
                 'aide' => 'Index, puissance, débit : kWh, m³ ou MWh par jour (index chaleur : MWh par degré-jour). Vide : valeur type.'],
                ['cle' => 'index_initial', 'libelle' => 'Index de départ', 'type' => 'nombre', 'defaut' => 0],
                ['cle' => 'graine', 'libelle' => 'Variante', 'type' => 'nombre', 'defaut' => 1,
                 'aide' => 'Deux points du même profil et de variantes différentes ne suivent pas la même courbe.'],
            ],
            'ecriture' => true,
            'decouverte' => true,
        ];
    }

    public function tester(array $points = []): array
    {
        return [self::etape('simulateur', true, 'Simulateur intégré prêt : aucune connexion réseau n\'est utilisée.', 0.0)];
    }

    public function decouvrir(): array
    {
        $out = [];
        $n = time();
        foreach (self::PROFILS as $k => $p) {
            $adr = ['profil' => $k, 'graine' => 1];
            $m = $this->modele($adr, 0);
            $out[] = ['nom' => $p[0], 'code' => $k, 'adresse' => $adr, 'unite' => $p[1], 'nature' => $p[2],
                      'valeur' => self::estCompteur($k) ? null : round(self::valeur($m, $n), 2), 'ecriture' => $k === 'consigne'];
        }
        return $out;
    }

    public function ecrire(array $point, $valeur): array
    {
        $a = is_array($point['Adresse'] ?? null) ? $point['Adresse'] : (json_decode((string)($point['Adresse'] ?? ''), true) ?: []);
        if (($a['profil'] ?? '') !== 'consigne') return ['ok' => false, 'message' => 'Seule la consigne d\'un simulateur se pilote.'];
        $v = self::nombre($valeur);
        if ($v === null) return ['ok' => false, 'message' => 'Valeur numérique attendue.'];
        $etat = $this->etat();
        $etat['consigne'] = round($v, 2);
        $this->ecrireEtat($etat);
        return ['ok' => true, 'message' => 'Consigne appliquée au simulateur : ' . str_replace('.', ',', (string)round($v, 2)) . ' °C.'];
    }

    public function lire(array $points): array
    {
        $now = time();
        $histo = max(0, min(400, (int)$this->param('historique_jours', 0)));
        $out = [];
        foreach ($points as $p) {
            $id = (int)$p['Id'];
            $a = is_array($p['Adresse'] ?? null) ? $p['Adresse'] : (json_decode((string)($p['Adresse'] ?? ''), true) ?: []);
            $profil = (string)($a['profil'] ?? '');
            if (!isset(self::PROFILS[$profil])) { $out[$id] = self::enDefaut('grandeur simulée inconnue'); continue; }
            $m = $this->modele($a, $id);
            $coef = (float)($p['Coefficient'] ?? 1) ?: 1.0;
            $dec = (float)($p['Decalage'] ?? 0);
            $derLecture = $p['DerniereLecture'] ? strtotime((string)$p['DerniereLecture']) : null;
            $derHisto = $p['DernierEnregistrement'] ? strtotime((string)$p['DernierEnregistrement']) : null;
            // Point d'appui des index : la dernière valeur connue, ramenée à l'échelle du simulateur.
            $t = null; $idx = null;
            if ($derLecture && $p['DerniereValeur'] !== null && ($p['DerniereQualite'] ?? '') !== 'mauvaise') {
                $t = $derLecture; $idx = ((float)$p['DerniereValeur'] - $dec) / $coef;
            }
            $depart = null;
            if (!$derLecture && !$derHisto && $histo > 0) $depart = $now - $histo * 86400;
            elseif ($derHisto && ($now - $derHisto) > 2 * self::PAS) $depart = $derHisto;   // période manquée : rattrapée
            $serie = [];
            if ($depart !== null) {
                $depart = min($now, max($depart, $now - 400 * 86400));
                $ts = (int)(floor($depart / self::PAS) * self::PAS) + self::PAS;
                if ($t === null) { $t = $ts - self::PAS; $idx = (float)($a['index_initial'] ?? 0); }
                for (; $ts < $now; $ts += self::PAS) {
                    if ($ts <= $t) continue;
                    if (self::estCompteur($profil)) { $idx += self::integrale($m, $t, $ts); $t = $ts; $v = $idx; }
                    else $v = self::valeur($m, $ts);
                    $serie[] = ['horodatage' => date('Y-m-d H:i:s', $ts), 'valeur' => round($v, 4), 'qualite' => 'bonne'];
                }
            }
            if (self::estCompteur($profil)) {
                if ($t === null) { $t = $now; $idx = (float)($a['index_initial'] ?? 0); }
                if ($now > $t) $idx += self::integrale($m, $t, $now);
                $v = $idx;
            } else {
                $v = self::valeur($m, $now);
            }
            $out[$id] = self::valeurLue(round($v, 4), $profil === 'etat_chaudiere' ? ($v >= 0.5 ? 'marche' : 'arrêt') : null, 'bonne', date('Y-m-d H:i:s', $now))
                      + ($serie ? ['serie' => $serie] : []);
        }
        return $out;
    }

    // ══ Modèle ══════════════════════════════════════════════════════════════

    /** Paramètres de calcul d'un point (statique : le script de démonstration s'en sert aussi). */
    public function modele(array $adresse, int $pointId): array
    {
        return self::modeleDe([
            'passerelle' => (int)($this->c['Id'] ?? 0),
            'occupation' => (string)$this->param('occupation', 'bureaux'),
            'incidents' => filter_var($this->param('incidents', true), FILTER_VALIDATE_BOOLEAN),
            'consigne' => $this->etat()['consigne'] ?? null,
        ], $adresse, $pointId);
    }

    public static function modeleDe(array $connexion, array $adresse, int $pointId): array
    {
        $profil = (string)($adresse['profil'] ?? '');
        $cj = self::nombre($adresse['conso_jour'] ?? null);
        $type = self::PROFILS[$profil][3] ?? null;
        if ($profil === 'index_chaleur') $cj = ($cj !== null && $cj > 0) ? $cj : self::CHALEUR_PAR_DJU;
        elseif ($cj === null || $cj <= 0) $cj = $type ?? 0.0;
        return [
            'profil' => $profil,
            'cj' => $cj,
            'graine' => (int)($adresse['graine'] ?? 1) . ':' . $pointId,
            'site' => 'site:' . (int)($connexion['passerelle'] ?? 0),
            'occupation' => in_array($connexion['occupation'] ?? '', ['bureaux', 'ecole', 'continu'], true) ? $connexion['occupation'] : 'bureaux',
            'incidents' => (bool)($connexion['incidents'] ?? true),
            'consigne' => is_numeric($connexion['consigne'] ?? null) ? (float)$connexion['consigne'] : 20.5,
        ];
    }

    public static function estCompteur(string $profil): bool
    {
        return in_array($profil, ['index_electrique', 'index_eau', 'index_chaleur'], true);
    }

    /** Valeur instantanée d'une grandeur non cumulée à l'instant $ts. */
    public static function valeur(array $m, int $ts): float
    {
        $h = (int)date('G', $ts) + (int)date('i', $ts) / 60;
        $occ = self::occupation($m['occupation'], $ts);
        $incident = $m['incidents'];
        switch ($m['profil']) {
            case 'temperature_exterieure':
                return self::exterieure($m['site'], $ts);
            case 'temperature_interieure': {
                $tmoy = self::moyenneJour($m['site'], $ts);
                if ($incident && self::incident($m['site'], 'panne', $ts, 0.05) && $tmoy < 15 && $h >= 5 && $h < 13) {
                    return 15.2 + 0.4 * self::bruit($m['graine'], $ts, 1800);
                }
                if ($tmoy < 15) {
                    $cible = $occ > 0 ? $m['consigne'] : 17.5;
                    return $cible + 0.35 * self::bruit($m['graine'], $ts, 2700);
                }
                $v = 21 + 0.35 * (self::exterieure($m['site'], $ts) - 15) + 0.8 * $occ;
                return max(19.0, min(28.5, $v + 0.3 * self::bruit($m['graine'], $ts, 2700)));
            }
            case 'humidite':
                return 47 + 7 * self::bruit($m['graine'], $ts, 10800) + 4 * $occ;
            case 'co2': {
                $v = 420 + $occ * (380 + 220 * (0.5 + 0.5 * self::bruit($m['graine'], $ts, 1800)));
                // Pointes de fin de matinée et de début d'après-midi
                $v += $occ * 220 * max(0.0, 1 - abs($h - 10.5) / 1.5) + $occ * 160 * max(0.0, 1 - abs($h - 15) / 1.5);
                if ($incident && $m['occupation'] === 'ecole') {
                    // Classe confinée l'après-midi (fenêtres fermées, ventilation en défaut)
                    if ($occ > 0 && $h >= 13.5 && $h < 16 && self::incident($m['graine'], 'confinement', $ts, 0.10)) {
                        $v = 1620 + 160 * self::bruit($m['graine'], $ts, 900);
                    }
                } elseif ($incident && self::incident($m['graine'], 'reunion', $ts, 0.12) && (int)date('N', $ts) <= 5 && $h >= 18 && $h < 21) {
                    $v = 1380 + 140 * self::bruit($m['graine'], $ts, 900);     // salle surpeuplée, ventilation insuffisante
                }
                return $v;
            }
            case 'puissance':
                return self::taux($m, $ts);
            case 'debit_eau':
                return self::taux($m, $ts);
            case 'etat_chaudiere': {
                $tmoy = self::moyenneJour($m['site'], $ts);
                if ($tmoy >= 15) return 0.0;
                if ($incident && self::incident($m['site'], 'panne', $ts, 0.05) && $h >= 5 && $h < 13) return 0.0;
                return ($occ > 0 || ($h >= 5 && $h < 8)) ? 1.0 : 0.0;
            }
            case 'consigne':
                return $m['consigne'];
        }
        return 0.0;
    }

    /** Débit de la grandeur cumulée (par heure) : kW, m³/h, MWh/h. */
    public static function taux(array $m, int $ts): float
    {
        $occ = self::occupation($m['occupation'], $ts);
        $h = (int)date('G', $ts) + (int)date('i', $ts) / 60;
        $doy = (int)date('z', $ts);
        switch ($m['profil']) {
            case 'puissance':
            case 'index_electrique': {
                $saison = 1 + 0.15 * cos(2 * M_PI * ($doy - 15) / 365.25);
                $forme = (0.4 + 1.6 * $occ) / self::norme($m['occupation'], 0.4, 1.6);
                return max(0.0, $m['cj'] / 24 * $saison * $forme * (1 + 0.06 * self::bruit($m['graine'], $ts, 1800)));
            }
            case 'debit_eau':
            case 'index_eau': {
                $forme = (0.15 + 1.85 * $occ) / self::norme($m['occupation'], 0.15, 1.85);
                $v = $m['cj'] / 24 * $forme * (1 + 0.2 * self::bruit($m['graine'], $ts, 1200));
                if ($m['incidents'] && self::incident($m['graine'], 'fuite', $ts, 0.03) && ($h < 5 || $h >= 22)) $v += 0.25 * $m['cj'] / 24;
                return max(0.0, $v);
            }
            case 'index_chaleur': {
                $dju = max(0.0, 18 - self::moyenneJour($m['site'], $ts));
                if ($dju <= 0) return 0.0;
                if ($m['incidents'] && self::incident($m['site'], 'panne', $ts, 0.05) && $h >= 5 && $h < 13) return 0.0;
                return $m['cj'] * $dju / 24 * self::formeChaleur($m['occupation'], $ts) / self::normeChaleur($m['occupation']);
            }
        }
        return 0.0;
    }

    /** Intégrale du taux entre deux instants (unités du compteur). */
    public static function integrale(array $m, int $t1, int $t2): float
    {
        if ($t2 <= $t1) return 0.0;
        $s = 0.0;
        for ($t = $t1; $t < $t2; $t += self::PAS_INTEGRATION) {
            $dt = min(self::PAS_INTEGRATION, $t2 - $t);
            $s += self::taux($m, (int)($t + $dt / 2)) * $dt / 3600;
        }
        return $s;
    }

    // ══ Environnement simulé ═════════════════════════════════════════════════

    /** Occupation 0..1 selon le type de bâtiment, le jour et l'heure. */
    public static function occupation(string $type, int $ts): float
    {
        $h = (int)date('G', $ts) + (int)date('i', $ts) / 60;
        $j = (int)date('N', $ts);
        if ($type === 'continu') return ($h >= 7 && $h < 21) ? 1.0 : 0.6;
        if ($type === 'ecole') {
            if (self::vacancesScolaires($ts) || $j >= 6) return 0.0;
            if ($j === 3 && $h >= 12) return 0.0;
            if ($h >= 8 && $h < 17) return 1.0;
            return ($h >= 7 && $h < 8) || ($h >= 17 && $h < 18.5) ? 0.4 : 0.0;
        }
        if ($j >= 6) return 0.0;
        if ($h >= 8 && $h < 18) return 1.0;
        return ($h >= 7 && $h < 8) || ($h >= 18 && $h < 19) ? 0.5 : 0.0;
    }

    /** Calendrier scolaire approché (zone métropolitaine moyenne). */
    private static function vacancesScolaires(int $ts): bool
    {
        $md = (int)date('n', $ts) * 100 + (int)date('j', $ts);
        foreach ([[101, 103], [212, 226], [412, 426], [705, 831], [1019, 1102], [1220, 1231]] as [$a, $b]) {
            if ($md >= $a && $md <= $b) return true;
        }
        return false;
    }

    /** Moyenne de la forme horaire sur une semaine type : la consommation journalière reste celle demandée. */
    private static function norme(string $type, float $base, float $amp): float
    {
        $k = "$type:$base:$amp";
        if (isset(self::$normes[$k])) return self::$normes[$k];
        $s = 0.0; $n = 0;
        $lundi = strtotime('2024-03-04 00:00:00');      // semaine hors vacances
        for ($t = $lundi; $t < $lundi + 7 * 86400; $t += 900) { $s += $base + $amp * self::occupation($type, $t); $n++; }
        return self::$normes[$k] = max(0.01, $s / $n);
    }

    /** Chauffage : relance du matin, régime d'occupation, ralenti. */
    private static function formeChaleur(string $type, int $ts): float
    {
        $h = (int)date('G', $ts) + (int)date('i', $ts) / 60;
        if ($h >= 5 && $h < 9) return 1.8;
        return self::occupation($type, $ts) > 0 ? 1.0 : 0.6;
    }

    private static function normeChaleur(string $type): float
    {
        $k = "chaleur:$type";
        if (isset(self::$normes[$k])) return self::$normes[$k];
        $s = 0.0; $n = 0;
        $lundi = strtotime('2024-03-04 00:00:00');
        for ($t = $lundi; $t < $lundi + 7 * 86400; $t += 900) { $s += self::formeChaleur($type, $t); $n++; }
        return self::$normes[$k] = max(0.01, $s / $n);
    }

    /** Température extérieure : saison + cycle du jour + météo lente. */
    public static function exterieure(string $site, int $ts): float
    {
        $doy = (int)date('z', $ts);
        $h = (int)date('G', $ts) + (int)date('i', $ts) / 60;
        $saison = 11.5 - 8.5 * cos(2 * M_PI * ($doy - 20) / 365.25);
        $jour = 4.0 * sin(2 * M_PI * ($h - 9) / 24);
        return $saison + $jour + 3.0 * self::bruit($site . ':meteo', $ts, 6 * 3600);
    }

    /** Température moyenne du jour (pour les degrés-jours et la saison de chauffe). */
    public static function moyenneJour(string $site, int $ts): float
    {
        $midi = strtotime(date('Y-m-d 12:00:00', $ts));
        $doy = (int)date('z', $ts);
        return 11.5 - 8.5 * cos(2 * M_PI * ($doy - 20) / 365.25) + 3.0 * self::bruit($site . ':meteo', $midi, 6 * 3600);
    }

    /** Un incident de ce type a-t-il lieu ce jour-là ? (reproductible) */
    public static function incident(string $graine, string $type, int $ts, float $proba): bool
    {
        return (crc32($graine . '|' . $type . '|' . date('Y-m-d', $ts)) % 10000) / 10000 < $proba;
    }

    /** Bruit lisse dans [-1, 1] : interpolation entre nœuds pseudo-aléatoires reproductibles. */
    public static function bruit(string $graine, int $ts, int $periode): float
    {
        $k = intdiv($ts, $periode);
        $f = ($ts - $k * $periode) / $periode;
        $f = $f * $f * (3 - 2 * $f);
        $a = (crc32($graine . '|' . $periode . '|' . $k) / 4294967295) * 2 - 1;
        $b = (crc32($graine . '|' . $periode . '|' . ($k + 1)) / 4294967295) * 2 - 1;
        return $a + ($b - $a) * $f;
    }

    // ══ État pilotable (consigne) ════════════════════════════════════════════

    private function fichierEtat(): string
    {
        $t = preg_replace('/[^A-Za-z0-9_.-]/', '_', (string)($this->c['_tenant'] ?? 'default'));
        return dirname(__DIR__, 3) . '/data/connect/simulation/' . $t . '-' . (int)($this->c['Id'] ?? 0) . '.json';
    }

    private function etat(): array
    {
        $j = json_decode((string)@file_get_contents($this->fichierEtat()), true);
        return is_array($j) ? $j : [];
    }

    private function ecrireEtat(array $e): void
    {
        $f = $this->fichierEtat();
        if (!is_dir(dirname($f))) @mkdir(dirname($f), 0775, true);
        @file_put_contents($f, json_encode($e), LOCK_EX);
    }
}

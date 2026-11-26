<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka Connect — bâtiments et réseaux : à quel bâtiment appartient un équipement ?
 *
 * Chaque bâtiment (ceux des plans) peut déclarer ses RÉSEAUX — les adresses IP
 * de ses équipements techniques (« 192.168.21.0/24 », « 10.4.0.10-60 ») — et
 * des MOTS-CLÉS (« HDV, mairie ») par lesquels ses équipements se nomment.
 *
 * Sans rien déclarer, Larka APPREND : un sous-réseau /24 où toutes les
 * connexions déjà rangées appartiennent au même bâtiment devient un réseau de
 * ce bâtiment. Les réseaux déclarés passent toujours avant ceux appris.
 *
 * Pour un équipement trouvé par la détection (ou une connexion sans bâtiment),
 * le bâtiment se devine dans cet ordre :
 *   1. son adresse est dans un réseau DÉCLARÉ (le plus précis l'emporte) ;
 *   2. son adresse est dans un réseau APPRIS ;
 *   3. son NOM (nom annoncé, emplacement BACnet, identifiant de borne, dossier
 *      d'exports) évoque un seul bâtiment : son nom, ses mots-clés, son sigle.
 * Dans le doute — deux bâtiments à égalité — rien n'est deviné : mieux vaut
 * une case vide qu'un équipement rangé au mauvais endroit.
 */

declare(strict_types=1);

final class ConnectBatiments
{
    /** Taille maximale d'une détection lancée sur les réseaux des bâtiments (4 × la détection libre). */
    public const MAX_ADRESSES_DETECTION = 4096;

    /** Mots trop courants dans les noms d'équipements pour désigner un bâtiment. */
    private const MOTS_VIDES = [
        'de', 'du', 'des', 'la', 'le', 'les', 'l', 'd', 'et', 'en', 'sur', 'sous', 'au', 'aux', 'a', 'un', 'une',
        'the', 'of', 'and', 'bat', 'batiment', 'batiments', 'site', 'salle', 'salles', 'local', 'locaux', 'bureau', 'bureaux',
        'accueil', 'hall', 'etage', 'niveau', 'rdc', 'chaufferie', 'cta', 'tgbt', 'compteur', 'compteurs', 'general', 'generale',
        'capteur', 'capteurs', 'borne', 'bornes', 'supervision', 'gestion', 'technique', 'techniques', 'automate', 'automates',
        'broker', 'mqtt', 'modbus', 'bacnet', 'api', 'rest', 'csv', 'ocpp', 'serveur', 'equipement', 'passerelle', 'centrale',
        'mesure', 'mesures', 'export', 'exports', 'gtb', 'gtc', 'larka', 'simulation', 'simulateur', 'reseau', 'ip', 'tcp', 'udp',
        'http', 'https', 'web', 'interface', 'regulation', 'climatisation', 'eclairage', 'onduleur', 'electrique', 'energie',
    ];

    // ── Réseaux : saisie, normalisation, appartenance ──────────────────────

    /**
     * Plages d'une saisie libre (« 192.168.21.0/24, 10.4.0.10-60, 10.4.1.7 »).
     * Adresses internes seulement ; aucune limite de taille (seule la détection en a une).
     *
     * @return array{0: list<array{texte: string, debut: int, fin: int}>, 1: list<string>}
     */
    public static function plages(string $saisie): array
    {
        $out = []; $erreurs = [];
        foreach (preg_split('/[\s,;]+/', trim($saisie), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $m) {
            $debut = $fin = null;
            if (preg_match('~^(\d{1,3}(?:\.\d{1,3}){3})/(\d{1,2})$~', $m, $x)) {
                $n = (int)$x[2];
                $base = ip2long($x[1]);
                if ($base === false || $n < 8 || $n > 32) { $erreurs[] = "« $m » : plage invalide (de /8 à /32)."; continue; }
                $masque = (0xFFFFFFFF << (32 - $n)) & 0xFFFFFFFF;
                $debut = $base & $masque; $fin = $debut | (~$masque & 0xFFFFFFFF);
                $m = long2ip($debut) . "/$n";
            } elseif (preg_match('~^(\d{1,3}(?:\.\d{1,3}){3})-(\d{1,3}(?:\.\d{1,3}){3}|\d{1,3})$~', $m, $x)) {
                $debut = ip2long($x[1]);
                $fin = str_contains($x[2], '.') ? ip2long($x[2]) : ($debut === false || (int)$x[2] > 255 ? false : (($debut & 0xFFFFFF00) | (int)$x[2]));
                if ($debut === false || $fin === false || $fin < $debut) { $erreurs[] = "« $m » : intervalle invalide."; continue; }
            } elseif (filter_var($m, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $debut = $fin = ip2long($m);
            } else {
                $erreurs[] = "« $m » : ni une adresse IPv4, ni une plage (ex. 192.168.21.0/24 ou 192.168.21.10-60).";
                continue;
            }
            if (!ConnectDetection::interne(long2ip($debut)) || !ConnectDetection::interne(long2ip($fin))) {
                $erreurs[] = "« $m » : ce n'est pas un réseau interne (10.x, 172.16-31.x, 192.168.x…).";
                continue;
            }
            $out[$m] = ['texte' => $m, 'debut' => $debut, 'fin' => $fin];
        }
        return [array_values($out), $erreurs];
    }

    /** Taille de la plus petite plage qui contient l'adresse, ou null. */
    public static function contient(array $plages, string $ip): ?int
    {
        $l = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? ip2long($ip) : false;
        if ($l === false) return null;
        $mieux = null;
        foreach ($plages as $p) {
            if ($l >= $p['debut'] && $l <= $p['fin']) {
                $t = $p['fin'] - $p['debut'] + 1;
                if ($mieux === null || $t < $mieux) $mieux = $t;
            }
        }
        return $mieux;
    }

    /** Adresse IPv4 d'une connexion (hôte, ou hôte de l'adresse d'une API), sinon ''. */
    public static function ipConnexion(array $pa): string
    {
        $hote = trim((string)($pa['Hote'] ?? $pa['hote'] ?? ''), '[]');
        $prm = $pa['Parametres'] ?? $pa['parametres'] ?? [];
        if (is_string($prm)) $prm = json_decode($prm, true) ?: [];
        if ($hote === '' && !empty($prm['url_base'])) $hote = (string)(parse_url((string)$prm['url_base'], PHP_URL_HOST) ?? '');
        return filter_var($hote, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $hote : '';
    }

    // ── Bâtiments, avec leurs réseaux déclarés et appris ───────────────────

    /**
     * Les bâtiments des plans, leurs réseaux et mots-clés.
     * Chaque ligne : Id, Nom, Couleur, Reseaux (saisie), MotsCles, Appris (/24 appris),
     * Plages (effectives, déclarées puis apprises), NbConnexions.
     */
    public static function lister(Database $db, array $passerelles): array
    {
        $db->initPlansTables();
        $lignes = $db->fetchAll("SELECT b.Id, b.Nom, b.Couleur, cb.Reseaux, cb.MotsCles
                                   FROM PlanBatiments b LEFT JOIN ConnectBatiments cb ON cb.BatimentId = b.Id ORDER BY b.Nom");
        $bats = [];
        foreach ($lignes as $b) {
            [$plages] = self::plages((string)($b['Reseaux'] ?? ''));
            foreach ($plages as &$p) $p['origine'] = 'declare';
            unset($p);
            $bats[(int)$b['Id']] = ['Id' => (int)$b['Id'], 'Nom' => (string)$b['Nom'], 'Couleur' => (string)($b['Couleur'] ?? ''),
                                    'Reseaux' => (string)($b['Reseaux'] ?? ''), 'MotsCles' => (string)($b['MotsCles'] ?? ''),
                                    'Appris' => [], 'Plages' => $plages, 'NbConnexions' => 0];
        }
        // Apprentissage : un /24 dont toutes les connexions rangées vont au même bâtiment.
        $parReseau = [];
        foreach ($passerelles as $pa) {
            $bid = (int)($pa['BatimentId'] ?? 0);
            if ($bid && isset($bats[$bid])) $bats[$bid]['NbConnexions']++;
            $ip = self::ipConnexion($pa);
            if ($ip === '') continue;
            if ($bid) $parReseau[long2ip(ip2long($ip) & 0xFFFFFF00)][$bid][] = $ip;   // les connexions non rangées n'apprennent rien
        }
        $declarees = [];
        foreach ($bats as $b) foreach ($b['Plages'] as $p) $declarees[] = $p;
        foreach ($parReseau as $reseau => $ids) {
            if (count($ids) !== 1) continue;                                // deux bâtiments sur ce sous-réseau : rien d'appris
            $bid = (int)array_key_first($ids);
            if (!$bid || !isset($bats[$bid])) continue;
            // Rien à apprendre si les réseaux déclarés couvrent déjà ces connexions.
            if (!array_filter($ids[$bid], fn($ip) => self::contient($declarees, $ip) === null)) continue;
            if (self::contient($declarees, $reseau) !== null && self::contient($declarees, long2ip(ip2long($reseau) + 255)) !== null) continue;
            $texte = "$reseau/24";
            $bats[$bid]['Appris'][] = $texte;
            $bats[$bid]['Plages'][] = ['texte' => $texte, 'debut' => ip2long($reseau), 'fin' => ip2long($reseau) + 255, 'origine' => 'appris'];
        }
        return array_values($bats);
    }

    /**
     * Enregistre les réseaux et mots-clés d'un bâtiment.
     * @return array{0: list<string>, 1: list<string>} [erreurs, avertissements]
     */
    public static function enregistrer(Database $db, int $id, string $reseaux, string $motsCles, string $login): array
    {
        $db->initPlansTables();
        if (!$db->fetchOne("SELECT Id FROM PlanBatiments WHERE Id = :id", ['id' => $id])) return [['Bâtiment introuvable.'], []];
        [$plages, $erreurs] = self::plages($reseaux);
        if ($erreurs) return [$erreurs, []];
        $motsCles = implode(', ', array_slice(array_values(array_unique(array_filter(array_map(
            fn($m) => mb_substr(trim(preg_replace('/\s+/u', ' ', $m) ?? ''), 0, 60), preg_split('/[,;\n]+/', $motsCles) ?: [])))), 0, 20));
        $texte = implode(', ', array_column($plages, 'texte'));
        // Chevauchements avec un autre bâtiment : permis (le plus précis l'emporte), mais signalés.
        $avert = [];
        foreach ($db->fetchAll("SELECT b.Nom, cb.Reseaux FROM ConnectBatiments cb JOIN PlanBatiments b ON b.Id = cb.BatimentId
                                 WHERE cb.BatimentId <> :id", ['id' => $id]) as $autre) {
            [$sp] = self::plages((string)$autre['Reseaux']);
            foreach ($plages as $p) foreach ($sp as $q) {
                if ($p['debut'] <= $q['fin'] && $q['debut'] <= $p['fin']) {
                    $avert[] = "{$p['texte']} chevauche {$q['texte']} ({$autre['Nom']}) : une adresse commune va au réseau le plus précis.";
                }
            }
        }
        $n = date('Y-m-d H:i:s');
        if ($db->fetchOne("SELECT BatimentId FROM ConnectBatiments WHERE BatimentId = :id", ['id' => $id])) {
            $db->execute("UPDATE ConnectBatiments SET Reseaux = :r, MotsCles = :m, UpdatedAt = :u, UpdatedBy = :l WHERE BatimentId = :id",
                         ['r' => $texte, 'm' => $motsCles, 'u' => $n, 'l' => $login, 'id' => $id]);
        } else {
            $db->execute("INSERT INTO ConnectBatiments (BatimentId, Reseaux, MotsCles, UpdatedAt, UpdatedBy) VALUES (:id, :r, :m, :u, :l)",
                         ['id' => $id, 'r' => $texte, 'm' => $motsCles, 'u' => $n, 'l' => $login]);
        }
        return [[], array_values(array_unique($avert))];
    }

    /**
     * Adresses à parcourir pour un bâtiment (ou tous) : ses réseaux déclarés et appris.
     * @return array{0: string, 1: list<string>} [plage pour ConnectDetection::cibles, noms des bâtiments sans réseau]
     */
    public static function plageDetection(array $batiments, ?int $id): array
    {
        $morceaux = []; $sans = [];
        foreach ($batiments as $b) {
            if ($id !== null && (int)$b['Id'] !== $id) continue;
            if (!$b['Plages']) { $sans[] = $b['Nom']; continue; }
            foreach ($b['Plages'] as $p) {
                // Une plage /n s'écrit telle quelle ; un intervalle en entier (début-fin).
                $morceaux[] = str_contains($p['texte'], '/') || $p['debut'] === $p['fin'] ? $p['texte'] : long2ip($p['debut']) . '-' . long2ip($p['fin']);
            }
        }
        return [implode(', ', array_values(array_unique($morceaux))), $sans];
    }

    // ── Deviner le bâtiment ─────────────────────────────────────────────────

    /**
     * Bâtiment d'un équipement (détecté ou déjà raccordé).
     * @param array $e  hote/ip, nom, details, identifiant, parametres (dossier, url_base…)
     * @return array{id: ?int, par: ?string, detail: string}  par : reseau | appris | nom
     */
    public static function deviner(array $e, array $batiments): array
    {
        $ip = self::ipConnexion($e);
        if ($ip !== '') {
            foreach (['declare' => 'reseau', 'appris' => 'appris'] as $origine => $par) {
                $tailles = [];
                foreach ($batiments as $b) {
                    $t = self::contient(array_filter($b['Plages'], fn($p) => $p['origine'] === $origine), $ip);
                    if ($t !== null) $tailles[(int)$b['Id']] = $t;
                }
                if (!$tailles) continue;
                asort($tailles);
                $ids = array_keys($tailles);
                if (count($ids) > 1 && $tailles[$ids[0]] === $tailles[$ids[1]]) return ['id' => null, 'par' => null, 'detail' => 'réseau commun à plusieurs bâtiments'];
                $b = self::parId($batiments, $ids[0]);
                return ['id' => $ids[0], 'par' => $par, 'detail' => $b ? $b['Nom'] : ''];
            }
        }
        $prm = $e['parametres'] ?? $e['Parametres'] ?? [];
        if (is_string($prm)) $prm = json_decode($prm, true) ?: [];
        $texte = implode(' ', array_filter([
            (string)($e['nom'] ?? $e['Nom'] ?? ''), (string)($e['details'] ?? ''), (string)($e['emplacement'] ?? ''),
            (string)($e['identifiant'] ?? $e['Identifiant'] ?? ''), (string)($prm['dossier'] ?? ''),
            preg_match('/[a-z]/i', (string)($e['hote'] ?? $e['Hote'] ?? '')) ? (string)($e['hote'] ?? $e['Hote']) : '',
        ]));
        $id = self::parNom($texte, $batiments);
        if ($id !== null) { $b = self::parId($batiments, $id); return ['id' => $id, 'par' => 'nom', 'detail' => $b ? $b['Nom'] : '']; }
        return ['id' => null, 'par' => null, 'detail' => ''];
    }

    private static function parId(array $batiments, int $id): ?array
    {
        foreach ($batiments as $b) if ((int)$b['Id'] === $id) return $b;
        return null;
    }

    /** Minuscules, sans accents ni ponctuation. */
    public static function normaliser(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $s = strtr($s, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e',
                        'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ì' => 'i', 'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ò' => 'o', 'õ' => 'o',
                        'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u', 'ÿ' => 'y', 'ñ' => 'n', 'œ' => 'oe', 'æ' => 'ae', '’' => ' ', "'" => ' ']);
        return trim(preg_replace('/[^a-z0-9]+/', ' ', $s) ?? '');
    }

    /** Mots significatifs d'un texte (sans mots vides ni nombres). */
    private static function mots(string $s, bool $garderCourts = false): array
    {
        $out = [];
        foreach (explode(' ', self::normaliser($s)) as $m) {
            if ($m === '' || ctype_digit($m) || in_array($m, self::MOTS_VIDES, true)) continue;
            if (!$garderCourts && strlen($m) < 3) continue;
            $out[$m] = true;
        }
        return array_keys($out);
    }

    /**
     * Bâtiment évoqué par un texte : mots-clés et nom complet (3 points), sigle,
     * mot du nom propre à un seul bâtiment, ou début propre d'un tel mot (2 points).
     * Retenu seulement s'il est seul en tête, avec 2 points au moins.
     */
    public static function parNom(string $texte, array $batiments): ?int
    {
        $phrase = ' ' . self::normaliser($texte) . ' ';
        if (trim($phrase) === '') return null;
        $motsTexte = self::mots($texte);
        $proprietaires = [];                                                 // mot du nom → bâtiments qui l'ont
        $motsBat = [];
        foreach ($batiments as $b) {
            $motsBat[(int)$b['Id']] = self::mots((string)$b['Nom']);
            foreach ($motsBat[(int)$b['Id']] as $m) $proprietaires[$m][(int)$b['Id']] = true;
        }
        $scores = [];
        foreach ($batiments as $b) {
            $id = (int)$b['Id'];
            $s = 0;
            $nom = self::normaliser((string)$b['Nom']);
            if ($nom !== '' && str_contains($phrase, " $nom ")) $s += 3;
            foreach (preg_split('/[,;\n]+/', (string)$b['MotsCles']) ?: [] as $mc) {
                $mc = self::normaliser($mc);
                if ($mc !== '' && str_contains($phrase, " $mc ")) { $s += 3; break; }
            }
            // Sigle : initiales de tous les mots (« Hôtel de Ville » → hdv), ou des seuls mots significatifs.
            $tous = array_filter(explode(' ', $nom), fn($m) => $m !== '' && !ctype_digit($m));
            foreach ([implode('', array_map(fn($m) => $m[0], $tous)), implode('', array_map(fn($m) => $m[0], $motsBat[$id]))] as $sigle) {
                if (strlen($sigle) >= 3 && str_contains($phrase, " $sigle ")) { $s += 2; break; }
            }
            foreach ($motsBat[$id] as $m) {
                if (count($proprietaires[$m]) !== 1) continue;               // mot partagé : ne désigne personne
                if (in_array($m, $motsTexte, true)) { $s += 2; continue; }
                if (strlen($m) < 5) continue;
                foreach ($motsTexte as $t) {
                    if (strlen($t) < 3 || strlen($t) >= strlen($m) || !str_starts_with($m, $t)) continue;
                    // Début propre à ce bâtiment (« GYM » pour gymnase, « POL » pour police).
                    $ailleurs = false;
                    foreach ($proprietaires as $autre => $ids) if (!isset($ids[$id]) && str_starts_with($autre, $t)) { $ailleurs = true; break; }
                    if (!$ailleurs) { $s += 2; break; }
                }
            }
            if ($s > 0) $scores[$id] = $s;
        }
        if (!$scores) return null;
        arsort($scores);
        $ids = array_keys($scores);
        if ($scores[$ids[0]] < 2 || (count($ids) > 1 && $scores[$ids[1]] === $scores[$ids[0]])) return null;
        return $ids[0];
    }

    /**
     * Connexions sans bâtiment dont le bâtiment se devine.
     * @return list<array{id: int, nom: string, batimentId: int, batiment: string, par: string}>
     */
    public static function rangeables(array $passerelles, array $batiments): array
    {
        $out = [];
        foreach ($passerelles as $pa) {
            if (!empty($pa['BatimentId'])) continue;
            $g = self::deviner($pa + ['details' => (string)($pa['Description'] ?? '')], $batiments);
            if ($g['id'] === null) continue;
            $out[] = ['id' => (int)$pa['Id'], 'nom' => (string)$pa['Nom'], 'batimentId' => $g['id'], 'batiment' => $g['detail'], 'par' => (string)$g['par']];
        }
        return $out;
    }
}

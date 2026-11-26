<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka Connect — accès aux tables Connect* (créées par Database::initConnectTables).
 *
 * Deux régimes d'écriture, et c'est voulu :
 *   - la CONFIGURATION (connexions, points, règles, liaisons) passe par
 *     Database::execute() : chaque modification est inscrite au journal
 *     d'audit, comme partout dans Larka ;
 *   - les MESURES et l'état courant des points sont écrits directement par
 *     PDO : une collecte toutes les minutes sur des centaines de points
 *     noierait le journal d'audit sous des lignes sans intérêt.
 *
 * Toute saisie est filtrée contre la description du pilote : une clé que le
 * pilote ne déclare pas n'est pas enregistrée, une valeur hors liste reprend
 * son défaut.
 */

declare(strict_types=1);

final class ConnectDepot
{
    public const NATURES = ['mesure', 'compteur', 'etat', 'alarme', 'consigne'];
    public const OPERATEURS = ['sup', 'inf', 'hors_plage', 'egal', 'different', 'qualite', 'absence', 'palier'];
    public const GRAVITES = ['info', 'mineure', 'majeure', 'critique'];
    /** « releve » : à chaque relevé du PC releveur (appareil hors réseau, relevé à la main, à des heures variables). */
    public const FREQUENCES = ['quotidien', 'hebdomadaire', 'mensuel', 'personnalise', 'releve'];
    public const DESTINATIONS = ['releve_energie'];

    private Database $db;
    private PDO $pdo;

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->pdo = $db->getPdo();
    }

    public function db(): Database
    {
        return $this->db;
    }

    private static function maintenant(): string
    {
        return date('Y-m-d H:i:s');
    }

    private static function texte($v, int $max): string
    {
        $v = is_scalar($v) ? trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string)$v) ?? '') : '';
        return mb_substr($v, 0, $max);
    }

    private static function reel($v): ?float
    {
        if ($v === null || $v === '') return null;
        $n = ConnectPiloteBase::nombre($v);
        return ($n === null || is_nan($n) || is_infinite($n)) ? null : $n;
    }

    private static function json(array $a): string
    {
        return json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function decoder(?string $j): array
    {
        $a = json_decode((string)$j, true);
        return is_array($a) ? $a : [];
    }

    /** Champs déclarés (champs de connexion ou d'adresse) → valeurs filtrées et typées. */
    private static function filtrerChamps(array $declares, array $saisie, array $existants = []): array
    {
        $out = [];
        foreach ($declares as $ch) {
            $cle = (string)$ch['cle'];
            $type = (string)($ch['type'] ?? 'texte');
            $v = array_key_exists($cle, $saisie) ? $saisie[$cle] : ($existants[$cle] ?? ($ch['defaut'] ?? null));
            switch ($type) {
                case 'nombre':
                    $n = self::reel($v);
                    $out[$cle] = $n === null ? null : (floor($n) == $n && abs($n) < 1e15 ? (int)$n : $n);
                    break;
                case 'booleen':
                    $out[$cle] = filter_var($v, FILTER_VALIDATE_BOOLEAN);
                    break;
                case 'choix':
                    $valides = array_map(fn($o) => (string)$o[0], $ch['options'] ?? []);
                    $out[$cle] = in_array((string)$v, $valides, true) ? (string)$v : (string)($ch['defaut'] ?? ($valides[0] ?? ''));
                    break;
                case 'secret':
                    break;                                     // jamais ici : voir les secrets
                case 'texte_long':
                    $out[$cle] = self::texte($v, 4000);
                    break;
                default:
                    $out[$cle] = self::texte($v, 500);
            }
        }
        return $out;
    }

    // ══ Connexions ═════════════════════════════════════════════════════════

    public function passerelles(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT p.*, b.Nom AS BatimentNom,
                    (SELECT COUNT(*) FROM ConnectPoints pt WHERE pt.PasserelleId = p.Id) AS NbPoints,
                    (SELECT COUNT(*) FROM ConnectAlarmes a WHERE a.PasserelleId = p.Id AND a.Etat = 'active') AS NbAlarmes
               FROM ConnectPasserelles p LEFT JOIN PlanBatiments b ON b.Id = p.BatimentId
              ORDER BY p.Nom");
        return array_map([$this, 'pourEcran'], $rows);
    }

    public function passerelle(int $id): ?array
    {
        $r = $this->db->fetchOne("SELECT * FROM ConnectPasserelles WHERE Id = :id", ['id' => $id]);
        return $r ? $this->pourEcran($r) : null;
    }

    /** Ligne pour l'écran : paramètres décodés, secrets réduits à « défini / non ». */
    private function pourEcran(array $r): array
    {
        $r['Parametres'] = self::decoder($r['Parametres'] ?? null);
        $secrets = Connect::dechiffrer($r['Secret'] ?? null);
        $r['SecretsDefinis'] = array_values(array_diff(array_keys($secrets), ['_illisible']));
        $r['SecretsIllisibles'] = !empty($secrets['_illisible']);
        // Rapport de détection du PC releveur : seulement son état ici (il peut peser lourd).
        $r['ReconEtat'] = !empty($r['ReconDemandee']) ? 'attente' : (!empty($r['Reconnaissance']) ? 'pret' : '');
        unset($r['Secret'], $r['Reconnaissance']);
        // Code de la panne en cours : l'écran en montre l'explication et la marche à suivre.
        $r['CodeErreur'] = self::codeErreur($r['DerniereErreur'] ?? null, ($r['Etat'] ?? '') === 'erreur' ? 'CXC-002' : 'CXC-006');
        return $r;
    }

    /** Code d'erreur d'un message de connexion ou de point (repli : $defaut). */
    public static function codeErreur(?string $message, string $defaut): ?string
    {
        if ($message === null || trim($message) === '' || !class_exists('CodesErreur')) return null;
        $c = CodesErreur::reconnaitre($message, 'connect', 'error');
        return $c && $c !== 'SYS-001' ? $c : $defaut;
    }

    /** Connexion telle que les pilotes l'attendent (secrets en clair, en mémoire seulement). */
    public function pourPilote(int $id, string $organisation = 'default'): ?array
    {
        $r = $this->db->fetchOne("SELECT * FROM ConnectPasserelles WHERE Id = :id", ['id' => $id]);
        if (!$r) return null;
        return $this->connexionPilote($r, $organisation);
    }

    public function connexionPilote(array $r, string $organisation = 'default'): array
    {
        return [
            'Id' => (int)$r['Id'], 'Nom' => (string)$r['Nom'], 'Protocole' => (string)$r['Protocole'],
            'Hote' => (string)($r['Hote'] ?? ''), 'Port' => (int)($r['Port'] ?? 0),
            'Identifiant' => (string)($r['Identifiant'] ?? ''),
            'params' => self::decoder($r['Parametres'] ?? null),
            'secret' => Connect::dechiffrer($r['Secret'] ?? null),
            '_tenant' => $organisation,
        ];
    }

    /** Protocoles qu'un PC releveur sait relever hors réseau (hotspot Wi-Fi ou câble direct). */
    public const PROTOCOLES_HOTSPOT = ['modbus', 'bacnet', 'mqtt', 'rest', 'auto'];

    /**
     * Accès hors réseau d'une connexion : « hotspot » (le Wi-Fi propre de l'appareil),
     * « direct » (un câble RJ45 entre le PC et l'appareil), ou '' (sur le réseau).
     */
    public static function accesHorsReseau(array $pa): string
    {
        $p = $pa['Parametres'] ?? null;
        if (!is_array($p)) $p = self::decoder(is_string($p) ? $p : null);
        $a = (string)($p['acces'] ?? '');
        return in_array($a, ['hotspot', 'direct'], true) ? $a : '';
    }

    /** Relevée hors réseau par un PC releveur (hotspot ou câble direct), jamais par le serveur ? */
    public static function enHotspot(array $pa): bool
    {
        return self::accesHorsReseau($pa) !== '';
    }

    /**
     * Adresse acceptable pour un appareil en hotspot : IP privée, de lien local,
     * ou de bouclage (essais, simulateur). Jamais un nom ni une adresse publique.
     */
    public static function adresseHotspot(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) return false;
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false
            || (bool)preg_match('/^(127\.|169\.254\.|::1$|fe[89ab][0-9a-f]:)/i', $ip);
    }

    /** Valide et normalise une saisie de connexion ; rend [valeurs, erreurs]. */
    public function normaliserPasserelle(array $d, ?array $existante = null): array
    {
        $erreurs = [];
        $protocole = (string)($existante['Protocole'] ?? ($d['protocole'] ?? ''));
        $desc = ConnectPilotes::description($protocole);
        if (!$desc) return [null, ['Protocole inconnu.']];
        $nom = self::texte($d['nom'] ?? ($existante['Nom'] ?? ''), 120);
        if ($nom === '') $erreurs[] = 'Le nom est obligatoire.';
        $hote = self::texte($d['hote'] ?? ($existante['Hote'] ?? ''), 253);
        if ($desc['hote'] !== null) {
            if ($hote === '' && !empty($desc['hote']['requis'])) $erreurs[] = 'L\'adresse de l\'équipement est obligatoire.';
            elseif ($hote !== '' && !ConnectPiloteBase::hoteValide($hote)) $erreurs[] = "Adresse invalide : « $hote » (une IP ou un nom, sans http:// ni port).";
        } else {
            $hote = '';
        }
        $port = array_key_exists('port', $d) ? (int)$d['port'] : (int)($existante['Port'] ?? 0);
        if ($port <= 0 || $port > 65535) $port = (int)($desc['port_defaut'] ?? 0);
        $ident = self::texte($d['identifiant'] ?? ($existante['Identifiant'] ?? ''), 120);
        if ($desc['identifiant'] !== null && !empty($desc['identifiant']['requis']) && $ident === '') {
            $erreurs[] = $desc['identifiant']['libelle'] . ' : obligatoire.';
        }
        if ($protocole === 'ocpp' && $ident !== '' && !preg_match('/^[A-Za-z0-9._\-]{1,48}$/', $ident)) {
            $erreurs[] = 'Identifiant de borne : lettres, chiffres, point, tiret et souligné seulement (48 au plus).';
        }
        $params = self::filtrerChamps($desc['champs'], is_array($d['parametres'] ?? null) ? $d['parametres'] : [],
                                      $existante['Parametres'] ?? []);
        if ($protocole === 'csv') {
            [$dossier, $raison] = ConnectPiloteCsv::dossierAutorise((string)($params['dossier'] ?? ''));
            if ($dossier === null) $erreurs[] = $raison;
        }
        if ($protocole === 'rest' && !preg_match('~^https?://[^\s/?#]+~i', (string)($params['url_base'] ?? ''))) {
            $erreurs[] = 'Adresse de l\'API : elle doit commencer par http:// ou https://.';
        }
        // Secrets : vide = inchangé ; « __effacer__ » = supprimé.
        $anciens = $existante ? Connect::dechiffrer($this->db->fetchOne("SELECT Secret FROM ConnectPasserelles WHERE Id = :id",
                                                                          ['id' => (int)$existante['Id']])['Secret'] ?? null) : [];
        unset($anciens['_illisible']);
        $saisis = is_array($d['secrets'] ?? null) ? $d['secrets'] : [];
        $secrets = [];
        foreach ($desc['secrets'] as $s) {
            $cle = (string)$s['cle'];
            $v = $saisis[$cle] ?? '';
            if ($v === '__effacer__') continue;
            $secrets[$cle] = (is_string($v) && $v !== '') ? mb_substr($v, 0, (int)($s['max'] ?? 500)) : ($anciens[$cle] ?? '');
        }
        // Accès par le hotspot Wi-Fi de l'appareil : le serveur ne l'interroge pas, un PC releveur
        // s'y connecte, relève, puis envoie les valeurs (api/connect/Releveur.php).
        if (in_array($protocole, self::PROTOCOLES_HOTSPOT, true)) {
            $avant = is_array($existante['Parametres'] ?? null) ? $existante['Parametres'] : [];
            $acces = (string)($d['acces'] ?? ($avant['acces'] ?? 'reseau'));
            if (in_array($acces, ['hotspot', 'direct'], true)) {
                // Hors réseau, l'appareil a une adresse privée (192.168.4.1, 192.168.1.10…), jamais publique.
                // Un nom (« powerelec.net », servi par l'appareil sur son hotspot) est admis : le collecteur
                // le résout une fois connecté et refuse tout ce qui ne mène pas à une adresse privée.
                $cible = $protocole === 'rest' ? trim((string)parse_url((string)($params['url_base'] ?? ''), PHP_URL_HOST), '[]') : $hote;
                if ($cible !== '' && filter_var($cible, FILTER_VALIDATE_IP) && !self::adresseHotspot($cible)) {
                    $erreurs[] = $acces === 'hotspot'
                        ? "Sur un hotspot, l'appareil a une adresse IP privée (souvent 192.168.4.1, 10.0.0.1…, voir sa notice) : « $cible » ne convient pas."
                        : "Branché en direct, l'appareil a une adresse IP privée (souvent 192.168.1.10, 192.168.0.100…, voir sa notice) : « $cible » ne convient pas.";
                }
                $params['acces'] = $acces;
            }
            if ($protocole === 'auto' && !in_array($acces, ['hotspot', 'direct'], true)) {
                $erreurs[] = 'La détection par le PC releveur concerne un appareil hors réseau (hotspot ou câble direct). Sur le réseau, utilisez la détection automatique de Connexions.';
            }
            if ($acces === 'hotspot') {
                $wifi = is_array($d['wifi'] ?? null) ? $d['wifi'] : [];
                $ssid = trim((string)($wifi['ssid'] ?? ($avant['wifi_ssid'] ?? '')));
                $secu = (string)($wifi['securite'] ?? ($avant['wifi_securite'] ?? 'wpa2'));
                if (!in_array($secu, ['wpa2', 'ouvert'], true)) $secu = 'wpa2';
                if ($ssid === '' || strlen($ssid) > 32 || preg_match('/[\x00-\x1f"<>&]/', $ssid)) $erreurs[] = 'Nom du réseau Wi-Fi (SSID) : obligatoire, 32 caractères au plus, sans guillemet ni < > &.';
                $v = $saisis['wifi_cle'] ?? '';
                $cleWifi = $v === '__effacer__' ? '' : ((is_string($v) && $v !== '') ? $v : ($anciens['wifi_cle'] ?? ''));
                if ($secu === 'wpa2' && (strlen($cleWifi) < 8 || strlen($cleWifi) > 63)) $erreurs[] = 'Clé du Wi-Fi : de 8 à 63 caractères (WPA2), ou choisissez « réseau ouvert ».';
                if (preg_match('/[<>&"]/', $cleWifi)) $erreurs[] = 'Clé du Wi-Fi : sans guillemet ni < > &.';
                $params['wifi_ssid'] = $ssid;
                $params['wifi_securite'] = $secu;
                if ($secu === 'wpa2' && $cleWifi !== '') $secrets['wifi_cle'] = mb_substr($cleWifi, 0, 63);
            }
        }
        if ($protocole === 'wmbus') {
            // Clé USB sur un PC releveur : relevée comme un appareil « en direct » (mission du PC).
            if (($params['emplacement'] ?? 'serveur') === 'pc') $params['acces'] = 'direct'; else unset($params['acces']);
            $port = trim((string)($params['port'] ?? 'auto'));
            if ($port === '' || strtolower($port) === 'auto') $params['port'] = 'auto';
            elseif (!ConnectSerie::portSaisi($port)) $erreurs[] = 'Port série : « auto », ou un nom de port (/dev/ttyUSB0, /dev/ttyACM0, /dev/serial/by-id/…, COM3).';
            $params['ecoute_secondes'] = max(5, min(45, (int)($params['ecoute_secondes'] ?? 30)));
            $mal = array_filter(preg_split('/[\s,;]+/', trim((string)($secrets['cles_aes'] ?? ''))) ?: [], fn($e) => $e !== '' && !preg_match('/^[0-9A-Fa-f]{8}[=:][0-9A-Fa-f]{32}$/', $e));
            if ($mal) $erreurs[] = 'Clés AES : « identifiant=clé » (8 chiffres = 32 caractères hexadécimaux), séparées par des virgules. Illisible : « ' . mb_substr((string)reset($mal), 0, 50) . ' ».';
        }
        if ($protocole === 'lorawan') {
            [$ident, $params, $secrets] = $this->normaliserLorawan($ident, $params, $secrets, $erreurs, (int)($existante['Id'] ?? 0));
        }
        $intervalle = (int)($d['intervalle'] ?? ($existante['IntervalleSecondes'] ?? ($desc['intervalle_defaut'] ?? 60)));
        // Protocoles « entrants » : l'appareil envoie de lui-même, personne ne l'interroge.
        $intervalle = in_array($protocole, ['ocpp', 'lorawan'], true) ? 0 : max(10, min(86400, $intervalle));
        $bat = $d['batimentId'] ?? ($existante['BatimentId'] ?? null);
        $bat = ($bat === null || $bat === '' || (int)$bat <= 0) ? null : (int)$bat;
        if ($bat !== null && !$this->db->fetchOne("SELECT Id FROM PlanBatiments WHERE Id = :id", ['id' => $bat])) $bat = null;
        return [[
            'Nom' => $nom, 'Protocole' => $protocole, 'Hote' => $hote, 'Port' => $port ?: null, 'Identifiant' => $ident,
            'Parametres' => self::json($params), 'Secret' => Connect::chiffrer($secrets),
            'IntervalleSecondes' => $intervalle,
            'Actif' => array_key_exists('actif', $d) ? (filter_var($d['actif'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0) : (int)($existante['Actif'] ?? 1),
            'BatimentId' => $bat, 'Description' => self::texte($d['description'] ?? ($existante['Description'] ?? ''), 1000),
        ], $erreurs];
    }

    /**
     * Capteur LoRaWAN : identifiants et clés en hexadécimal strict (une faute de
     * frappe donnerait un capteur muet sans explication), DevEUI unique.
     */
    private function normaliserLorawan(string $ident, array $params, array $secrets, array &$erreurs, int $idExistante): array
    {
        $eui = ConnectLorawan::hex($ident, 8);
        if ($ident !== '' && !$eui) $erreurs[] = 'DevEUI : 16 caractères hexadécimaux (0-9, A-F), ex. 70B3D57ED005A1B2.';
        if ($eui) {
            $ident = $eui;
            $doublon = $this->db->fetchOne("SELECT Id, Nom FROM ConnectPasserelles WHERE Protocole = 'lorawan' AND UPPER(Identifiant) = :e AND Id != :id",
                                           ['e' => $eui, 'id' => $idExistante]);
            if ($doublon) $erreurs[] = "Ce DevEUI est déjà déclaré (« {$doublon['Nom']} »).";
        }
        $je = trim((string)($params['join_eui'] ?? ''));
        if ($je !== '') {
            $h = ConnectLorawan::hex($je, 8);
            if (!$h) $erreurs[] = 'JoinEUI / AppEUI : 16 caractères hexadécimaux, ou vide.';
            else $params['join_eui'] = $h;
        }
        foreach (['app_key', 'nwk_s_key', 'app_s_key'] as $k) {
            if (($secrets[$k] ?? '') === '') continue;
            $h = ConnectLorawan::hex((string)$secrets[$k], 16);
            if (!$h) { $erreurs[] = strtoupper(str_replace('_', '', $k)) . ' : 32 caractères hexadécimaux.'; continue; }
            $secrets[$k] = $h;
        }
        if (($params['activation'] ?? 'otaa') === 'abp') {
            $a = ConnectLorawan::hex((string)($params['dev_addr'] ?? ''), 4);
            if (!$a) $erreurs[] = 'ABP : le DevAddr (8 caractères hexadécimaux) est obligatoire.';
            else $params['dev_addr'] = $a;
            if (($secrets['nwk_s_key'] ?? '') === '' || ($secrets['app_s_key'] ?? '') === '') $erreurs[] = 'ABP : NwkSKey et AppSKey sont obligatoires.';
        } elseif (($secrets['app_key'] ?? '') === '') {
            $erreurs[] = 'OTAA : l\'AppKey est obligatoire (32 caractères hexadécimaux, sur la fiche du capteur).';
        }
        return [$ident, $params, $secrets];
    }

    public function creerPasserelle(array $v, string $login): int
    {
        $n = self::maintenant();
        $this->db->execute("INSERT INTO ConnectPasserelles (Nom, Protocole, Hote, Port, Identifiant, Parametres, Secret, IntervalleSecondes,
                                Actif, BatimentId, Description, Etat, ProchaineCollecte, CreatedAt, CreatedBy, UpdatedAt, UpdatedBy)
                            VALUES (:nom, :pr, :h, :po, :id, :pa, :se, :iv, :ac, :ba, :de, 'inconnu', :pc, :ca, :cb, :ua, :ub)",
            ['nom' => $v['Nom'], 'pr' => $v['Protocole'], 'h' => $v['Hote'], 'po' => $v['Port'], 'id' => $v['Identifiant'],
             'pa' => $v['Parametres'], 'se' => $v['Secret'], 'iv' => $v['IntervalleSecondes'], 'ac' => $v['Actif'],
             'ba' => $v['BatimentId'], 'de' => $v['Description'], 'pc' => $n, 'ca' => $n, 'cb' => $login, 'ua' => $n, 'ub' => $login]);
        return $this->db->dernierIdInsere();
    }

    public function modifierPasserelle(int $id, array $v, string $login): void
    {
        $this->db->execute("UPDATE ConnectPasserelles SET Nom = :nom, Hote = :h, Port = :po, Identifiant = :ident, Parametres = :pa,
                                Secret = :se, IntervalleSecondes = :iv, Actif = :ac, BatimentId = :ba, Description = :de,
                                ProchaineCollecte = :pc, UpdatedAt = :ua, UpdatedBy = :ub WHERE Id = :id",
            ['nom' => $v['Nom'], 'h' => $v['Hote'], 'po' => $v['Port'], 'ident' => $v['Identifiant'], 'pa' => $v['Parametres'],
             'se' => $v['Secret'], 'iv' => $v['IntervalleSecondes'], 'ac' => $v['Actif'], 'ba' => $v['BatimentId'],
             'de' => $v['Description'], 'pc' => self::maintenant(), 'ua' => self::maintenant(), 'ub' => $login, 'id' => $id]);
    }

    /** Range une connexion dans un bâtiment (sans toucher au reste de sa fiche). */
    public function rangerPasserelle(int $id, int $batimentId, string $login): void
    {
        $this->db->execute("UPDATE ConnectPasserelles SET BatimentId = :b, UpdatedAt = :u, UpdatedBy = :l WHERE Id = :id AND (BatimentId IS NULL OR BatimentId = 0)",
                           ['b' => $batimentId, 'u' => self::maintenant(), 'l' => $login, 'id' => $id]);
    }

    // ── Exclusions de la détection ─────────────────────────────────────────

    /** Appareils et machines ignorés : [['Id', 'Type' (appareil|machine), 'Valeur', 'Nom'…]]. */
    public function exclusions(): array
    {
        return $this->db->fetchAll("SELECT Id, Type, Valeur, Nom, CreatedAt, CreatedBy FROM ConnectExclusions WHERE Type IN ('appareil', 'machine') ORDER BY Id DESC");
    }

    /** Plages que la détection ne parcourt jamais (saisie libre, une seule ligne en base). */
    public function plagesExclues(): string
    {
        $r = $this->db->fetchOne("SELECT Valeur FROM ConnectExclusions WHERE Type = 'plages'");
        return (string)($r['Valeur'] ?? '');
    }

    public function enregistrerPlagesExclues(string $texte, string $login): void
    {
        $this->db->execute("DELETE FROM ConnectExclusions WHERE Type = 'plages'");
        if (trim($texte) === '') return;
        $this->db->execute("INSERT INTO ConnectExclusions (Type, Valeur, Nom, CreatedAt, CreatedBy) VALUES ('plages', :v, '', :c, :l)",
                           ['v' => $texte, 'c' => self::maintenant(), 'l' => $login]);
    }

    /** Ignore un appareil (clé protocole|adresse|port) ou une machine entière (adresse). */
    public function ignorer(string $type, string $valeur, string $nom, string $login): ?int
    {
        if (!in_array($type, ['appareil', 'machine'], true) || $valeur === '') return null;
        $deja = $this->db->fetchOne("SELECT Id FROM ConnectExclusions WHERE Type = :t AND Valeur = :v", ['t' => $type, 'v' => $valeur]);
        if ($deja) return (int)$deja['Id'];
        $this->db->execute("INSERT INTO ConnectExclusions (Type, Valeur, Nom, CreatedAt, CreatedBy) VALUES (:t, :v, :n, :c, :l)",
                           ['t' => $type, 'v' => mb_substr($valeur, 0, 300), 'n' => mb_substr($nom, 0, 150), 'c' => self::maintenant(), 'l' => $login]);
        return $this->db->dernierIdInsere();
    }

    public function retirerExclusion(int $id): void
    {
        $this->db->execute("DELETE FROM ConnectExclusions WHERE Id = :id AND Type IN ('appareil', 'machine')", ['id' => $id]);
    }

    // ── PC releveurs (appareils en hotspot Wi-Fi, relevés hors ligne) ──────

    public function releveurs(): array
    {
        return array_map(function ($r) {
            unset($r['JetonHash']);
            $r['Journal'] = self::decoder($r['Journal'] ?? null);
            $r['Materiel'] = self::decoder($r['Materiel'] ?? null);
            return $r;
        }, $this->db->fetchAll("SELECT * FROM ConnectReleveurs ORDER BY Nom"));
    }

    public function releveur(int $id): ?array
    {
        return $this->db->fetchOne("SELECT * FROM ConnectReleveurs WHERE Id = :id", ['id' => $id]);
    }

    /** Nouveau PC releveur ; renvoie son Id. Le jeton se pose à part (jamais gardé en clair). */
    public function creerReleveur(string $nom, array $connexions, string $login): int
    {
        $n = self::maintenant();
        $this->db->execute("INSERT INTO ConnectReleveurs (Nom, JetonHash, Connexions, Actif, CreatedAt, CreatedBy, UpdatedAt, UpdatedBy)
                            VALUES (:n, NULL, :c, 1, :ca, :cb, :ua, :ub)",
            ['n' => mb_substr($nom, 0, 100), 'c' => implode(',', array_map('intval', $connexions)), 'ca' => $n, 'cb' => $login, 'ua' => $n, 'ub' => $login]);
        return $this->db->dernierIdInsere();
    }

    public function modifierReleveur(int $id, string $nom, array $connexions, bool $actif, string $login): void
    {
        $this->db->execute("UPDATE ConnectReleveurs SET Nom = :n, Connexions = :c, Actif = :a, UpdatedAt = :u, UpdatedBy = :l WHERE Id = :id",
            ['n' => mb_substr($nom, 0, 100), 'c' => implode(',', array_map('intval', $connexions)), 'a' => $actif ? 1 : 0,
             'u' => self::maintenant(), 'l' => $login, 'id' => $id]);
    }

    /** Nouveau jeton (l'ancien cesse de fonctionner) : seule son empreinte est gardée. */
    public function poserJetonReleveur(int $id, string $jeton): void
    {
        $this->db->execute("UPDATE ConnectReleveurs SET JetonHash = :h, UpdatedAt = :u WHERE Id = :id",
                           ['h' => hash('sha256', $jeton), 'u' => self::maintenant(), 'id' => $id]);
    }

    public function noterContactReleveur(int $id, ?string $erreur, array $journal): void
    {
        $this->db->execute("UPDATE ConnectReleveurs SET DernierContact = :d, DerniereErreur = :e, Journal = :j WHERE Id = :id",
            ['d' => self::maintenant(), 'e' => $erreur !== null ? mb_substr($erreur, 0, 500) : null,
             'j' => json_encode(array_slice($journal, -40), JSON_UNESCAPED_UNICODE), 'id' => $id]);
    }

    /** Matériel radio (ports série, récepteurs USB) vu par un PC releveur — déjà réduit par ConnectReleveur::materiel. */
    public function noterMaterielReleveur(int $id, array $materiel): void
    {
        $this->db->execute("UPDATE ConnectReleveurs SET Materiel = :m WHERE Id = :id",
            ['m' => json_encode($materiel, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'id' => $id]);
    }

    /** Version du collecteur, d'après son en-tête « User-Agent: Larka-Releveur/1.4.1 » (vide : avant la 1.4). */
    public function noterVersionReleveur(int $id, string $agent): void
    {
        $v = preg_match('#Larka-Releveur/([0-9][0-9.]{0,15})#i', $agent, $m) ? $m[1] : '';
        $this->db->execute("UPDATE ConnectReleveurs SET Version = :v WHERE Id = :id", ['v' => $v !== '' ? $v : null, 'id' => $id]);
    }

    /** Version du collecteur au moins égale à $min ? (inconnue : non). */
    public static function versionAuMoins(?string $v, string $min): bool
    {
        return $v !== null && $v !== '' && version_compare($v, $min, '>=');
    }

    public function supprimerReleveur(int $id): void
    {
        $this->db->execute("DELETE FROM ConnectReleveurs WHERE Id = :id", ['id' => $id]);
    }

    /** Charges en cours, par borne : [Id de la connexion => nombre]. */
    public function chargesEnCours(): array
    {
        $out = [];
        foreach ($this->db->fetchAll("SELECT PasserelleId, COUNT(*) FROM ConnectSessionsVE WHERE Statut = 'en_cours' GROUP BY PasserelleId") as $r) {
            [$pid, $n] = array_values($r);                                   // par position : la casse des noms varie selon la base
            $out[(int)$pid] = (int)$n;
        }
        return $out;
    }

    // ── Détection par le PC releveur (appareils hors réseau) ─────────────────

    /** Demande au PC releveur de regarder ce que l'appareil expose, à son prochain passage. */
    public function reconDemander(int $id): void
    {
        $this->db->execute("UPDATE ConnectPasserelles SET ReconDemandee = :n WHERE Id = :id", ['n' => self::maintenant(), 'id' => $id]);
    }

    /** Rapport reçu du PC (déjà normalisé) : gardé, la demande est close. */
    public function reconEnregistrer(int $id, array $rapport): void
    {
        $this->db->execute("UPDATE ConnectPasserelles SET Reconnaissance = :r, ReconDate = :n, ReconDemandee = NULL WHERE Id = :id",
            ['r' => json_encode($rapport, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'n' => self::maintenant(), 'id' => $id]);
    }

    /** [rapport ou null, date, demandée depuis]. */
    public function reconLire(int $id): array
    {
        $r = $this->db->fetchOne("SELECT Reconnaissance, ReconDate, ReconDemandee FROM ConnectPasserelles WHERE Id = :id", ['id' => $id]);
        if (!$r) return [null, null, null];
        $j = json_decode((string)($r['Reconnaissance'] ?? ''), true);
        return [is_array($j) ? $j : null, $r['ReconDate'] ?? null, $r['ReconDemandee'] ?? null];
    }

    /**
     * Applique les réglages proposés par la détection : protocole, adresse, paramètres.
     * L'accès hors réseau (hotspot / câble) et le Wi-Fi sont gardés. Changer de protocole
     * rend les adresses des points existants sans objet : $supprimerPoints est exigé
     * s'il y en a. Rend [ok, erreurs].
     */
    public function reconAppliquer(int $id, array $suggestion, bool $supprimerPoints, string $login): array
    {
        $ex = $this->passerelle($id);
        if (!$ex) return [false, ['Connexion introuvable.']];
        $proto = (string)($suggestion['protocole'] ?? '');
        if (!in_array($proto, ['rest', 'modbus', 'bacnet'], true)) return [false, ['Réglages proposés illisibles.']];
        $nbPoints = (int)($this->db->fetchOne("SELECT COUNT(*) AS n FROM ConnectPoints WHERE PasserelleId = :id", ['id' => $id])['n'] ?? 0);
        if ($proto !== $ex['Protocole'] && $nbPoints > 0 && !$supprimerPoints) {
            return [false, ["Passer en « $proto » rend les adresses des $nbPoints point(s) existant(s) sans objet : confirmez leur suppression."]];
        }
        $prm = is_array($ex['Parametres'] ?? null) ? $ex['Parametres'] : [];
        $d = ['nom' => $ex['Nom'], 'hote' => (string)($suggestion['hote'] ?? ''), 'port' => (int)($suggestion['port'] ?? 0),
              'identifiant' => (string)($suggestion['identifiant'] ?? ($proto === $ex['Protocole'] ? ($ex['Identifiant'] ?? '') : '')),
              'parametres' => (array)($suggestion['parametres'] ?? []) + $prm, 'acces' => $prm['acces'] ?? 'hotspot',
              'wifi' => ['ssid' => $prm['wifi_ssid'] ?? '', 'securite' => $prm['wifi_securite'] ?? 'wpa2']];
        $base = $ex; $base['Protocole'] = $proto;
        [$v, $err] = $this->normaliserPasserelle($d, $base);
        if ($err) return [false, $err];
        if ($proto !== $ex['Protocole'] && $nbPoints > 0) {
            foreach ($this->db->fetchAll("SELECT Id FROM ConnectPoints WHERE PasserelleId = :id", ['id' => $id]) as $p) $this->supprimerPoint((int)$p['Id']);
        }
        $this->modifierPasserelle($id, $v, $login);
        $this->db->execute("UPDATE ConnectPasserelles SET Protocole = :p WHERE Id = :id", ['p' => $proto, 'id' => $id]);
        return [true, []];
    }

    public function supprimerPasserelle(int $id): void
    {
        foreach ($this->db->fetchAll("SELECT Id FROM ConnectPoints WHERE PasserelleId = :id", ['id' => $id]) as $p) {
            $this->supprimerPoint((int)$p['Id']);
        }
        foreach (['ConnectAlarmes', 'ConnectCommandes', 'ConnectSessionsVE', 'ConnectFichiers', 'ConnectLorawan'] as $t) {
            $this->pdo->prepare("DELETE FROM $t WHERE PasserelleId = :id")->execute(['id' => $id]);
        }
        $this->db->execute("DELETE FROM ConnectPasserelles WHERE Id = :id", ['id' => $id]);
    }

    /** État de la connexion après une collecte. */
    public function noterEtatPasserelle(int $id, string $etat, ?string $erreur, int $echecs, ?string $prochaine, bool $contact): void
    {
        $avant = $this->db->fetchOne("SELECT Nom, Protocole, Etat, DerniereErreur FROM ConnectPasserelles WHERE Id = :id", ['id' => $id]);
        $n = self::maintenant();
        $sql = "UPDATE ConnectPasserelles SET Etat = :e, DerniereErreur = :er, EchecsConsecutifs = :ec, ProchaineCollecte = :pc,
                       DerniereCollecte = :dc" . ($contact ? ", DernierContact = :dc2" : '') . " WHERE Id = :id";
        $p = ['e' => $etat, 'er' => $erreur === null ? null : mb_substr($erreur, 0, 500), 'ec' => $echecs, 'pc' => $prochaine, 'dc' => $n, 'id' => $id];
        if ($contact) $p['dc2'] = $n;
        $this->pdo->prepare($sql)->execute($p);
        if ($avant) $this->journaliserEtat($id, $avant, $etat, $erreur);
    }

    /**
     * Journal de supervision : une entrée quand une connexion CHANGE d'état (en
     * défaut, partielle, rétablie) ou de cause de panne — jamais à chaque
     * passage, sinon une connexion en panne écrirait une ligne par minute.
     * Chaque entrée porte son code (api/CodesErreur.php) : l'écran en tire
     * l'explication et la marche à suivre.
     */
    public function journaliserEtat(int $id, array $avant, string $etat, ?string $erreur): void
    {
        $code = fn(?string $m, string $defaut) => self::codeErreur($m, $defaut);
        $ancien = (string)($avant['Etat'] ?? '');
        $cAvant = $ancien === 'erreur' ? $code($avant['DerniereErreur'] ?? null, 'CXC-002') : ($ancien === 'partiel' ? 'CXC-003' : null);
        $cApres = $etat === 'erreur' ? ($code($erreur, 'CXC-002') ?? 'CXC-002') : ($etat === 'partiel' ? 'CXC-003' : null);
        if ($etat === $ancien && $cAvant === $cApres) return;
        $nom = (string)($avant['Nom'] ?? "n° $id");
        $ctx = ['entiteType' => 'ConnectPasserelle', 'entiteId' => $id, 'protocole' => (string)($avant['Protocole'] ?? ''),
                'etat_avant' => $ancien, 'etat' => $etat];
        if ($etat === 'erreur') {
            Connect::journal('erreur', "« $nom » en défaut : " . ($erreur ?: 'aucune valeur lue'), $ctx + ['code' => $cApres]);
        } elseif ($etat === 'partiel') {
            // Partielle : la cause détaillée de chaque point (souvent une adresse) a son propre code.
            Connect::journal('avertissement', "« $nom » partielle : certains points ne sont pas lus" . ($erreur ? " — $erreur" : ''),
                             $ctx + ['code' => 'CXC-003', 'code_cause' => $erreur ? $code($erreur, 'CXC-006') : null]);
        } elseif (in_array($ancien, ['erreur', 'partiel'], true)) {
            Connect::journal('info', "« $nom » : communication rétablie" . ($ancien === 'erreur' ? ' (était en défaut)' : ' (tous les points sont lus)'),
                             $ctx + ['code' => 'CXC-004']);
        }
    }

    // ══ Points ═════════════════════════════════════════════════════════════

    public function points(?int $passerelleId = null, bool $actifsSeulement = false): array
    {
        $w = []; $p = [];
        if ($passerelleId !== null) { $w[] = 'pt.PasserelleId = :pid'; $p['pid'] = $passerelleId; }
        if ($actifsSeulement) $w[] = 'pt.Actif = 1';
        $rows = $this->db->fetchAll(
            "SELECT pt.*, pa.Nom AS PasserelleNom, pa.Protocole AS PasserelleProtocole, pa.Etat AS PasserelleEtat,
                    pa.BatimentId, b.Nom AS BatimentNom, e.Numero AS EquipementNumero,
                    (SELECT COUNT(*) FROM ConnectRegles r WHERE r.PointId = pt.Id) AS NbRegles,
                    (SELECT COUNT(*) FROM ConnectLiaisons l WHERE l.PointId = pt.Id) AS NbLiaisons,
                    (SELECT COUNT(*) FROM ConnectAlarmes a WHERE a.PointId = pt.Id AND a.Etat = 'active') AS NbAlarmes
               FROM ConnectPoints pt
               JOIN ConnectPasserelles pa ON pa.Id = pt.PasserelleId
               LEFT JOIN PlanBatiments b ON b.Id = pa.BatimentId
               LEFT JOIN Equipements e ON e.Id = pt.EquipementId"
            . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY pa.Nom, pt.Ordre, pt.Nom", $p);
        foreach ($rows as &$r) {
            $r['Adresse'] = self::decoder($r['Adresse'] ?? null);
            $r['CodeErreur'] = ($r['DerniereQualite'] ?? '') === 'mauvaise' ? self::codeErreur($r['DerniereErreur'] ?? null, 'CXC-006') : null;
        }
        return $rows;
    }

    public function point(int $id): ?array
    {
        $r = $this->db->fetchOne(
            "SELECT pt.*, pa.Nom AS PasserelleNom, pa.Protocole AS PasserelleProtocole, pa.BatimentId, b.Nom AS BatimentNom
               FROM ConnectPoints pt JOIN ConnectPasserelles pa ON pa.Id = pt.PasserelleId
               LEFT JOIN PlanBatiments b ON b.Id = pa.BatimentId WHERE pt.Id = :id", ['id' => $id]);
        if (!$r) return null;
        $r['Adresse'] = self::decoder($r['Adresse'] ?? null);
        return $r;
    }

    public function normaliserPoint(array $d, ?array $existant = null): array
    {
        $erreurs = [];
        $pid = (int)($existant['PasserelleId'] ?? ($d['passerelleId'] ?? 0));
        $pa = $pid ? $this->db->fetchOne("SELECT Id, Protocole FROM ConnectPasserelles WHERE Id = :id", ['id' => $pid]) : null;
        if (!$pa) return [null, ['Connexion introuvable.']];
        $desc = ConnectPilotes::description((string)$pa['Protocole']);
        $nom = self::texte($d['nom'] ?? ($existant['Nom'] ?? ''), 150);
        if ($nom === '') $erreurs[] = 'Le nom du point est obligatoire.';
        $nature = (string)($d['nature'] ?? ($existant['Nature'] ?? 'mesure'));
        if (!in_array($nature, self::NATURES, true)) $nature = 'mesure';
        $adresse = self::filtrerChamps($desc['adresse'] ?? [], is_array($d['adresse'] ?? null) ? $d['adresse'] : [],
                                       is_array($existant['Adresse'] ?? null) ? $existant['Adresse'] : []);
        foreach ($desc['adresse'] ?? [] as $ch) {
            $val = $adresse[$ch['cle']] ?? null;
            if (!empty($ch['requis']) && ($val === null || $val === '')) {
                $erreurs[] = 'Adresse : « ' . $ch['libelle'] . ' » est obligatoire.';
            }
        }
        $coef = self::reel($d['coefficient'] ?? ($existant['Coefficient'] ?? 1)) ?? 1.0;
        if ($coef == 0.0) { $erreurs[] = 'Le coefficient ne peut pas être nul.'; $coef = 1.0; }
        $ecr = filter_var($d['ecritureAutorisee'] ?? ($existant['EcritureAutorisee'] ?? false), FILTER_VALIDATE_BOOLEAN) && !empty($desc['ecriture']);
        $min = self::reel($d['minEcriture'] ?? ($existant['MinEcriture'] ?? null));
        $max = self::reel($d['maxEcriture'] ?? ($existant['MaxEcriture'] ?? null));
        if ($ecr && ($min === null || $max === null)) $erreurs[] = 'Écriture autorisée : indiquez la plage permise (minimum et maximum).';
        if ($min !== null && $max !== null && $min > $max) $erreurs[] = 'Plage d\'écriture : le minimum dépasse le maximum.';
        $eq = $d['equipementId'] ?? ($existant['EquipementId'] ?? null);
        $eq = ($eq === null || $eq === '' || (int)$eq <= 0) ? null : (int)$eq;
        if ($eq !== null && !$this->db->fetchOne("SELECT Id FROM Equipements WHERE Id = :id", ['id' => $eq])) $eq = null;
        return [[
            'PasserelleId' => $pid, 'Nom' => $nom, 'Code' => self::texte($d['code'] ?? ($existant['Code'] ?? ''), 120),
            'Nature' => $nature, 'Unite' => self::texte($d['unite'] ?? ($existant['Unite'] ?? ''), 20),
            'Adresse' => self::json($adresse), 'Coefficient' => $coef,
            'Decalage' => self::reel($d['decalage'] ?? ($existant['Decalage'] ?? 0)) ?? 0.0,
            'Decimales' => max(0, min(6, (int)($d['decimales'] ?? ($existant['Decimales'] ?? 2)))),
            'BandeMorte' => max(0.0, self::reel($d['bandeMorte'] ?? ($existant['BandeMorte'] ?? 0)) ?? 0.0),
            'EcritureAutorisee' => $ecr ? 1 : 0, 'MinEcriture' => $min, 'MaxEcriture' => $max, 'EquipementId' => $eq,
            'Actif' => array_key_exists('actif', $d) ? (filter_var($d['actif'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0) : (int)($existant['Actif'] ?? 1),
            'Ordre' => (int)($d['ordre'] ?? ($existant['Ordre'] ?? 0)),
        ], $erreurs];
    }

    public function creerPoint(array $v, string $login): int
    {
        $n = self::maintenant();
        $this->db->execute("INSERT INTO ConnectPoints (PasserelleId, Nom, Code, Nature, Unite, Adresse, Coefficient, Decalage, Decimales,
                                BandeMorte, EcritureAutorisee, MinEcriture, MaxEcriture, EquipementId, Actif, Ordre,
                                CreatedAt, CreatedBy, UpdatedAt, UpdatedBy)
                            VALUES (:pa, :nom, :code, :nat, :un, :ad, :co, :de, :dc, :bm, :ec, :mi, :ma, :eq, :ac, :or, :ca, :cb, :ua, :ub)",
            ['pa' => $v['PasserelleId'], 'nom' => $v['Nom'], 'code' => $v['Code'], 'nat' => $v['Nature'], 'un' => $v['Unite'],
             'ad' => $v['Adresse'], 'co' => $v['Coefficient'], 'de' => $v['Decalage'], 'dc' => $v['Decimales'], 'bm' => $v['BandeMorte'],
             'ec' => $v['EcritureAutorisee'], 'mi' => $v['MinEcriture'], 'ma' => $v['MaxEcriture'], 'eq' => $v['EquipementId'],
             'ac' => $v['Actif'], 'or' => $v['Ordre'], 'ca' => $n, 'cb' => $login, 'ua' => $n, 'ub' => $login]);
        return $this->db->dernierIdInsere();
    }

    public function modifierPoint(int $id, array $v, string $login): void
    {
        $this->db->execute("UPDATE ConnectPoints SET Nom = :nom, Code = :code, Nature = :nat, Unite = :un, Adresse = :ad, Coefficient = :co,
                                Decalage = :de, Decimales = :dc, BandeMorte = :bm, EcritureAutorisee = :ec, MinEcriture = :mi,
                                MaxEcriture = :ma, EquipementId = :eq, Actif = :ac, Ordre = :or, UpdatedAt = :ua, UpdatedBy = :ub
                             WHERE Id = :id",
            ['nom' => $v['Nom'], 'code' => $v['Code'], 'nat' => $v['Nature'], 'un' => $v['Unite'], 'ad' => $v['Adresse'],
             'co' => $v['Coefficient'], 'de' => $v['Decalage'], 'dc' => $v['Decimales'], 'bm' => $v['BandeMorte'],
             'ec' => $v['EcritureAutorisee'], 'mi' => $v['MinEcriture'], 'ma' => $v['MaxEcriture'], 'eq' => $v['EquipementId'],
             'ac' => $v['Actif'], 'or' => $v['Ordre'], 'ua' => self::maintenant(), 'ub' => $login, 'id' => $id]);
    }

    public function supprimerPoint(int $id): void
    {
        foreach ($this->db->fetchAll("SELECT Id FROM ConnectLiaisons WHERE PointId = :id", ['id' => $id]) as $l) {
            $this->pdo->prepare("DELETE FROM ConnectExecutions WHERE LiaisonId = :id")->execute(['id' => (int)$l['Id']]);
        }
        foreach (['ConnectMesures', 'ConnectRegles', 'ConnectLiaisons', 'ConnectAlarmes'] as $t) {
            $this->pdo->prepare("DELETE FROM $t WHERE PointId = :id")->execute(['id' => $id]);
        }
        $this->db->execute("DELETE FROM ConnectPoints WHERE Id = :id", ['id' => $id]);
    }

    /** Trouve un point par son code sur une connexion (bornes OCPP : points créés d'eux-mêmes). */
    public function pointParCode(int $passerelleId, string $code): ?array
    {
        return $this->db->fetchOne("SELECT * FROM ConnectPoints WHERE PasserelleId = :p AND Code = :c", ['p' => $passerelleId, 'c' => $code]);
    }

    // ══ Mesures (chemin chaud : PDO direct) ════════════════════════════════

    /**
     * Range les lectures d'une collecte.
     *
     * Une valeur est historisée si elle a bougé de plus que la bande morte, si
     * son texte ou sa qualité a changé, ou si la dernière valeur historisée a
     * plus de « valeur_periodique_minutes » (15 par défaut) : les liaisons
     * trouvent ainsi toujours une mesure proche de leur échéance.
     *
     * @param array<int,array> $points   lignes ConnectPoints indexées par Id
     * @param array<int,array> $lectures résultats des pilotes indexés par Id
     * @return array{lus: int, en_defaut: int, historisees: int, valeurs: array<int, array>}
     */
    public function enregistrerLectures(array $points, array $lectures): array
    {
        $n = self::maintenant();
        $periode = max(1, (int)Connect::reglage('valeur_periodique_minutes', 15)) * 60;
        $insMesure = $this->pdo->prepare("INSERT INTO ConnectMesures (PointId, Horodatage, Valeur, ValeurTexte, Qualite) VALUES (?, ?, ?, ?, ?)");
        $majOk = $this->pdo->prepare("UPDATE ConnectPoints SET DerniereValeur = ?, DerniereValeurTexte = ?, DerniereQualite = ?,
                                             DerniereLecture = ?, DernierEnregistrement = COALESCE(?, DernierEnregistrement), DerniereErreur = ?
                                       WHERE Id = ?");
        $majKo = $this->pdo->prepare("UPDATE ConnectPoints SET DerniereQualite = 'mauvaise', DerniereErreur = ? WHERE Id = ?");
        $majMesure = $this->pdo->prepare("UPDATE ConnectMesures SET Valeur = ?, ValeurTexte = ?, Qualite = ? WHERE PointId = ? AND Horodatage = ?");
        $stats = ['lus' => 0, 'en_defaut' => 0, 'historisees' => 0, 'valeurs' => []];
        $this->pdo->beginTransaction();
        try {
            foreach ($lectures as $id => $r) {
                $p = $points[$id] ?? null;
                if (!$p) continue;
                $coef = (float)($p['Coefficient'] ?? 1) ?: 1.0;
                $dec = (float)($p['Decalage'] ?? 0);
                $mettre = fn($v) => $v === null ? null : Connect::arrondi($v * $coef + $dec);
                // Valeurs accumulées (messages en file, lignes de fichiers) : historisées telles quelles.
                $dernierHisto = (string)($p['DernierEnregistrement'] ?? '');
                foreach ((array)($r['serie'] ?? []) as $s) {
                    $h = (string)($s['horodatage'] ?? '');
                    if ($h === '' || ($dernierHisto !== '' && $h <= $dernierHisto)) continue;
                    $this->executerInsert($insMesure, [(int)$id, $h, $mettre($s['valeur'] ?? null), $s['texte'] ?? null, $s['qualite'] ?? 'bonne']);
                    $stats['historisees']++;
                    $dernierHisto = $h;
                }
                $v = $mettre($r['valeur'] ?? null);
                $txt = isset($r['texte']) ? mb_substr((string)$r['texte'], 0, 200) : null;
                $qualite = (string)($r['qualite'] ?? 'bonne');
                if ($v === null && $txt === null) {
                    $stats['en_defaut']++;
                    $majKo->execute([mb_substr((string)($r['erreur'] ?? 'illisible'), 0, 300), (int)$id]);
                    $p['DerniereQualite'] = 'mauvaise';
                    $stats['valeurs'][$id] = ['valeur' => null, 'texte' => null, 'qualite' => 'mauvaise', 'horodatage' => $n,
                                              'erreur' => $r['erreur'] ?? null];
                    continue;
                }
                $stats['lus']++;
                $horo = (string)($r['horodatage'] ?? '') ?: $n;
                if ($dernierHisto !== '' && $horo < $dernierHisto) $horo = $n;
                $ancienne = $p['DerniereValeur'] === null ? null : (float)$p['DerniereValeur'];
                $bande = (float)($p['BandeMorte'] ?? 0);
                $bouge = $ancienne === null
                    || ($bande > 0 ? abs((float)$v - $ancienne) >= $bande : (float)$v !== $ancienne)
                    || (string)$txt !== (string)($p['DerniereValeurTexte'] ?? '')
                    || $qualite !== (string)($p['DerniereQualite'] ?? '');
                $vieille = $dernierHisto === '' || (strtotime($horo) - strtotime($dernierHisto)) >= $periode;
                $histo = null;
                if (($bouge || $vieille) && $horo !== $dernierHisto) {
                    $this->executerInsert($insMesure, [(int)$id, $horo, $v, $txt, $qualite]);
                    $stats['historisees']++;
                    $histo = $horo;
                } elseif ($bouge && $horo === $dernierHisto) {
                    // Changement dans la MÊME seconde que la dernière mesure (lecture
                    // « maintenant » juste après la collecte, rafale de messages d'une
                    // borne) : la dernière valeur de la seconde remplace la précédente.
                    // L'ignorer laissait l'historique — et les relevés — sur l'ancienne.
                    $majMesure->execute([$v, $txt, $qualite, (int)$id, $horo]);
                    $histo = $horo;
                }
                $majOk->execute([$v, $txt, $qualite, $horo, $histo, $qualite === 'mauvaise' ? mb_substr((string)($r['erreur'] ?? ''), 0, 300) : null, (int)$id]);
                $stats['valeurs'][$id] = ['valeur' => $v, 'texte' => $txt, 'qualite' => $qualite, 'horodatage' => $horo,
                                          'ancienne' => $ancienne, 'erreur' => $r['erreur'] ?? null];
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
        return $stats;
    }

    /**
     * Relevés plus anciens que la dernière valeur du point (file d'attente d'un PC
     * releveur, second PC) : rangés dans l'historique à leur heure réelle, sans
     * toucher la dernière valeur ; une mesure déjà présente à cette heure n'est
     * pas doublée (même envoi reçu deux fois).
     */
    public function historiserAnterieures(array $points, array $lectures): int
    {
        $existe = $this->pdo->prepare("SELECT COUNT(*) FROM ConnectMesures WHERE PointId = ? AND Horodatage = ?");
        $ins = $this->pdo->prepare("INSERT INTO ConnectMesures (PointId, Horodatage, Valeur, ValeurTexte, Qualite) VALUES (?, ?, ?, ?, ?)");
        $n = 0;
        foreach ($lectures as $id => $r) {
            $p = $points[$id] ?? null;
            $h = (string)($r['horodatage'] ?? '');
            if (!$p || $h === '') continue;
            $coef = (float)($p['Coefficient'] ?? 1) ?: 1.0;
            $v = isset($r['valeur']) && $r['valeur'] !== null ? Connect::arrondi((float)$r['valeur'] * $coef + (float)($p['Decalage'] ?? 0)) : null;
            $txt = isset($r['texte']) ? mb_substr((string)$r['texte'], 0, 200) : null;
            if ($v === null && $txt === null) continue;
            $existe->execute([(int)$id, $h]);
            if ((int)$existe->fetchColumn() > 0) continue;
            $this->executerInsert($ins, [(int)$id, $h, $v, $txt, (string)($r['qualite'] ?? 'bonne')]);
            $n++;
        }
        return $n;
    }

    /** INSERT avec reprise après désynchronisation de séquence PostgreSQL (import, migration). */
    private function executerInsert(PDOStatement $st, array $p): void
    {
        try {
            $st->execute($p);
        } catch (\PDOException $e) {
            if ($this->db->getDriver() === 'pgsql' && str_contains($e->getMessage(), 'duplicate key')) {
                $this->db->resyncAllPgSequences();
                $st->execute($p);
                return;
            }
            throw $e;
        }
    }

    /**
     * Mesures d'un point sur une période, ramenées à $max points au plus
     * (moyenne par tranche, avec minimum et maximum de la tranche).
     */
    public function mesures(int $pointId, string $debut, string $fin, int $max = 600): array
    {
        $rows = $this->db->fetchAll("SELECT Horodatage, Valeur, ValeurTexte, Qualite FROM ConnectMesures
                                      WHERE PointId = :p AND Horodatage >= :d AND Horodatage <= :f ORDER BY Horodatage",
                                     ['p' => $pointId, 'd' => $debut, 'f' => $fin]);
        if (count($rows) <= $max) return $rows;
        $t0 = strtotime($debut); $t1 = max($t0 + 1, strtotime($fin));
        $pas = ($t1 - $t0) / $max;
        $tranches = [];
        foreach ($rows as $r) {
            if ($r['Valeur'] === null) continue;
            $k = (int)floor((strtotime((string)$r['Horodatage']) - $t0) / $pas);
            $tranches[$k][] = (float)$r['Valeur'];
        }
        $out = [];
        ksort($tranches);
        foreach ($tranches as $k => $vals) {
            $out[] = ['Horodatage' => date('Y-m-d H:i:s', (int)($t0 + ($k + 0.5) * $pas)), 'Valeur' => array_sum($vals) / count($vals),
                      'ValeurMin' => min($vals), 'ValeurMax' => max($vals), 'Qualite' => 'bonne'];
        }
        return $out;
    }

    /** Mesure la plus proche d'un instant, dans ± $tolerance minutes. */
    public function mesureProche(int $pointId, string $instant, int $tolerance): ?array
    {
        $t = strtotime($instant);
        $rows = $this->db->fetchAll("SELECT Horodatage, Valeur, Qualite FROM ConnectMesures
                                      WHERE PointId = :p AND Horodatage >= :d AND Horodatage <= :f AND Valeur IS NOT NULL",
                                     ['p' => $pointId, 'd' => date('Y-m-d H:i:s', $t - $tolerance * 60), 'f' => date('Y-m-d H:i:s', $t + $tolerance * 60)]);
        $meilleure = null; $ecart = PHP_INT_MAX;
        foreach ($rows as $r) {
            if (($r['Qualite'] ?? 'bonne') === 'mauvaise') continue;
            $e = abs(strtotime((string)$r['Horodatage']) - $t);
            if ($e < $ecart) { $ecart = $e; $meilleure = $r; }
        }
        return $meilleure;
    }

    public function purgerMesures(int $jours): int
    {
        $limite = date('Y-m-d H:i:s', time() - max(30, $jours) * 86400);
        $st = $this->pdo->prepare("DELETE FROM ConnectMesures WHERE Horodatage < :l");
        $st->execute(['l' => $limite]);
        return $st->rowCount();
    }

    // ══ Règles ═════════════════════════════════════════════════════════════

    public function regles(?int $pointId = null): array
    {
        return $pointId === null
            ? $this->db->fetchAll("SELECT * FROM ConnectRegles ORDER BY PointId, Id")
            : $this->db->fetchAll("SELECT * FROM ConnectRegles WHERE PointId = :p ORDER BY Id", ['p' => $pointId]);
    }

    public function normaliserRegle(array $d, ?array $existante = null): array
    {
        $erreurs = [];
        $pid = (int)($existante['PointId'] ?? ($d['pointId'] ?? 0));
        if (!$pid || !$this->db->fetchOne("SELECT Id FROM ConnectPoints WHERE Id = :id", ['id' => $pid])) return [null, ['Point introuvable.']];
        $op = (string)($d['operateur'] ?? ($existante['Operateur'] ?? ''));
        if (!in_array($op, self::OPERATEURS, true)) return [null, ['Condition inconnue.']];
        $seuil = self::reel($d['seuil'] ?? ($existante['Seuil'] ?? null));
        $seuil2 = self::reel($d['seuil2'] ?? ($existante['Seuil2'] ?? null));
        $txt = self::texte($d['valeurTexte'] ?? ($existante['ValeurTexte'] ?? ''), 100);
        if (in_array($op, ['sup', 'inf'], true) && $seuil === null) $erreurs[] = 'Indiquez le seuil.';
        if ($op === 'hors_plage' && ($seuil === null || $seuil2 === null || $seuil > $seuil2)) $erreurs[] = 'Indiquez une plage valide (minimum puis maximum).';
        if (in_array($op, ['egal', 'different'], true) && $seuil === null && $txt === '') $erreurs[] = 'Indiquez la valeur ou l\'état attendu.';
        if ($op === 'palier' && ($seuil === null || $seuil <= 0)) $erreurs[] = 'Indiquez le pas du palier (ex. 2000 heures).';
        $delai = max(0, min(10080, (int)($d['delaiMinutes'] ?? ($existante['DelaiMinutes'] ?? 0))));
        if ($op === 'absence' && $delai < 1) $erreurs[] = 'Indiquez au bout de combien de minutes sans valeur l\'alarme se déclenche.';
        $grav = (string)($d['gravite'] ?? ($existante['Gravite'] ?? 'majeure'));
        if (!in_array($grav, self::GRAVITES, true)) $grav = 'majeure';
        $b = fn($k, $col, $def) => array_key_exists($k, $d) ? (filter_var($d[$k], FILTER_VALIDATE_BOOLEAN) ? 1 : 0) : (int)($existante[$col] ?? $def);
        return [[
            'PointId' => $pid, 'Nom' => self::texte($d['nom'] ?? ($existante['Nom'] ?? ''), 150), 'Operateur' => $op,
            'Seuil' => $seuil, 'Seuil2' => $seuil2, 'ValeurTexte' => $txt, 'DelaiMinutes' => $delai, 'Gravite' => $grav,
            'CreerDemande' => $b('creerDemande', 'CreerDemande', 1), 'Notifier' => $b('notifier', 'Notifier', 1), 'Actif' => $b('actif', 'Actif', 1),
        ], $erreurs];
    }

    public function enregistrerRegle(?int $id, array $v, string $login): int
    {
        $n = self::maintenant();
        if ($id) {
            $this->db->execute("UPDATE ConnectRegles SET Nom = :nom, Operateur = :op, Seuil = :s, Seuil2 = :s2, ValeurTexte = :vt,
                                    DelaiMinutes = :dm, Gravite = :g, CreerDemande = :cd, Notifier = :no, Actif = :ac,
                                    EnCoursDepuis = NULL, UpdatedAt = :ua, UpdatedBy = :ub WHERE Id = :id",
                ['nom' => $v['Nom'], 'op' => $v['Operateur'], 's' => $v['Seuil'], 's2' => $v['Seuil2'], 'vt' => $v['ValeurTexte'],
                 'dm' => $v['DelaiMinutes'], 'g' => $v['Gravite'], 'cd' => $v['CreerDemande'], 'no' => $v['Notifier'], 'ac' => $v['Actif'],
                 'ua' => $n, 'ub' => $login, 'id' => $id]);
            return $id;
        }
        $this->db->execute("INSERT INTO ConnectRegles (PointId, Nom, Operateur, Seuil, Seuil2, ValeurTexte, DelaiMinutes, Gravite,
                                CreerDemande, Notifier, Actif, CreatedAt, CreatedBy, UpdatedAt, UpdatedBy)
                            VALUES (:p, :nom, :op, :s, :s2, :vt, :dm, :g, :cd, :no, :ac, :ca, :cb, :ua, :ub)",
            ['p' => $v['PointId'], 'nom' => $v['Nom'], 'op' => $v['Operateur'], 's' => $v['Seuil'], 's2' => $v['Seuil2'],
             'vt' => $v['ValeurTexte'], 'dm' => $v['DelaiMinutes'], 'g' => $v['Gravite'], 'cd' => $v['CreerDemande'],
             'no' => $v['Notifier'], 'ac' => $v['Actif'], 'ca' => $n, 'cb' => $login, 'ua' => $n, 'ub' => $login]);
        return $this->db->dernierIdInsere();
    }

    public function supprimerRegle(int $id): void
    {
        $this->db->execute("DELETE FROM ConnectRegles WHERE Id = :id", ['id' => $id]);
    }

    // ══ Alarmes ════════════════════════════════════════════════════════════

    public function alarmes(string $etat = 'active', int $limite = 300): array
    {
        $w = $etat === 'toutes' ? '' : ($etat === 'terminee' ? "WHERE a.Etat = 'terminee'" : "WHERE a.Etat = 'active'");
        $limite = max(1, min(2000, $limite));
        return $this->db->fetchAll(
            "SELECT a.*, pt.Nom AS PointNom, pt.Unite AS PointUnite, pa.Nom AS PasserelleNom, pa.Protocole AS PasserelleProtocole,
                    b.Nom AS BatimentNom, d.Statut AS DemandeStatut
               FROM ConnectAlarmes a
               LEFT JOIN ConnectPoints pt ON pt.Id = a.PointId
               LEFT JOIN ConnectPasserelles pa ON pa.Id = a.PasserelleId
               LEFT JOIN PlanBatiments b ON b.Id = pa.BatimentId
               LEFT JOIN DemandesIntervention d ON d.Id = a.DemandeId
               $w ORDER BY a.Debut DESC LIMIT $limite");
    }

    public function acquitterAlarme(int $id, string $login, string $commentaire = ''): bool
    {
        $a = $this->db->fetchOne("SELECT Id FROM ConnectAlarmes WHERE Id = :id", ['id' => $id]);
        if (!$a) return false;
        $this->db->execute("UPDATE ConnectAlarmes SET Acquittee = 1, AcquitteePar = :l, DateAcquittement = :d,
                                Commentaire = :c WHERE Id = :id",
            ['l' => $login, 'd' => self::maintenant(), 'c' => self::texte($commentaire, 500) ?: null, 'id' => $id]);
        return true;
    }

    public function compterAlarmesActives(): int
    {
        return (int)($this->db->fetchOne("SELECT COUNT(*) AS Nb FROM ConnectAlarmes WHERE Etat = 'active' AND Acquittee = 0")['Nb'] ?? 0);
    }

    // ══ Liaisons ═══════════════════════════════════════════════════════════

    public function liaisons(?int $pointId = null): array
    {
        $rows = $this->db->fetchAll(
            "SELECT l.*, pt.Nom AS PointNom, pt.Unite AS PointUnite, pa.Nom AS PasserelleNom, c.Nom AS CibleNom, b.Nom AS BatimentNom
               FROM ConnectLiaisons l JOIN ConnectPoints pt ON pt.Id = l.PointId
               JOIN ConnectPasserelles pa ON pa.Id = pt.PasserelleId
               LEFT JOIN PlanBatiments b ON b.Id = pa.BatimentId
               LEFT JOIN CompteurEnergie c ON c.Id = l.CibleId"
            . ($pointId !== null ? ' WHERE l.PointId = :p' : '') . ' ORDER BY l.Id',
            $pointId !== null ? ['p' => $pointId] : []);
        foreach ($rows as &$r) $r['Description'] = ConnectPlanif::libelle($r);
        return $rows;
    }

    public function liaison(int $id): ?array
    {
        return $this->db->fetchOne("SELECT * FROM ConnectLiaisons WHERE Id = :id", ['id' => $id]);
    }

    public function normaliserLiaison(array $d, ?array $existante = null): array
    {
        $erreurs = [];
        $pid = (int)($existante['PointId'] ?? ($d['pointId'] ?? 0));
        if (!$pid || !$this->db->fetchOne("SELECT Id FROM ConnectPoints WHERE Id = :id", ['id' => $pid])) return [null, ['Point introuvable.']];
        $dest = (string)($d['destination'] ?? ($existante['Destination'] ?? 'releve_energie'));
        if (!in_array($dest, self::DESTINATIONS, true)) return [null, ['Destination inconnue.']];
        $cible = (int)($d['cibleId'] ?? ($existante['CibleId'] ?? 0));
        if ($dest === 'releve_energie' && !$this->db->fetchOne("SELECT Id FROM CompteurEnergie WHERE Id = :id", ['id' => $cible])) {
            $erreurs[] = 'Choisissez le compteur du module Énergie qui recevra les relevés.';
        }
        $freq = (string)($d['frequence'] ?? ($existante['Frequence'] ?? 'quotidien'));
        if (!in_array($freq, self::FREQUENCES, true)) $freq = 'quotidien';
        $heure = (string)($d['heure'] ?? ($existante['Heure'] ?? '23:55'));
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $heure)) { $erreurs[] = 'Heure invalide (HH:MM).'; $heure = '23:55'; }
        $liste = function ($v, int $min, int $max): string {
            $vals = is_array($v) ? $v : preg_split('/[\s,;]+/', (string)$v);
            $ok = array_values(array_unique(array_filter(array_map('intval', (array)$vals), fn($n) => $n >= $min && $n <= $max)));
            sort($ok);
            return implode(',', $ok);
        };
        $jours = $liste($d['joursSemaine'] ?? ($existante['JoursSemaine'] ?? ''), 1, 7);
        $mois = $liste($d['moisActifs'] ?? ($existante['MoisActifs'] ?? ''), 1, 12);
        $jm = (int)($d['jourMois'] ?? ($existante['JourMois'] ?? 1));
        if ($jm !== -1) $jm = max(1, min(28, $jm));
        $cron = self::texte($d['expressionCron'] ?? ($existante['ExpressionCron'] ?? ''), 100);
        if ($freq === 'hebdomadaire' && $jours === '') $erreurs[] = 'Choisissez au moins un jour de la semaine.';
        if ($freq === 'personnalise' && !ConnectPlanif::cronValide($cron)) $erreurs[] = 'Expression cron invalide (5 champs : minute heure jour mois jour-de-semaine).';
        $coef = self::reel($d['coefficient'] ?? ($existante['Coefficient'] ?? 1)) ?? 1.0;
        if ($coef == 0.0) { $erreurs[] = 'Le coefficient ne peut pas être nul.'; $coef = 1.0; }
        $fa = self::reel($d['facteurAnomalie'] ?? ($existante['FacteurAnomalie'] ?? 5)) ?? 5.0;
        $b = fn($k, $col, $def) => array_key_exists($k, $d) ? (filter_var($d[$k], FILTER_VALIDATE_BOOLEAN) ? 1 : 0) : (int)($existante[$col] ?? $def);
        return [[
            'Nom' => self::texte($d['nom'] ?? ($existante['Nom'] ?? ''), 150), 'PointId' => $pid, 'Destination' => $dest,
            'CibleId' => $cible ?: null, 'Coefficient' => $coef, 'Frequence' => $freq, 'Heure' => $heure,
            'JoursSemaine' => $jours ?: null, 'JourMois' => $jm, 'MoisActifs' => $mois ?: null, 'ExpressionCron' => $cron ?: null,
            'ToleranceMinutes' => max(1, min(720, (int)($d['toleranceMinutes'] ?? ($existante['ToleranceMinutes'] ?? 15)))),
            'FacteurAnomalie' => $fa > 0 ? max(1.5, $fa) : 0.0,
            'ValidationManuelle' => $b('validationManuelle', 'ValidationManuelle', 0), 'Actif' => $b('actif', 'Actif', 1),
        ], $erreurs];
    }

    public function enregistrerLiaison(?int $id, array $v, string $login): int
    {
        $n = self::maintenant();
        $prochaine = ConnectPlanif::prochaine($v, new DateTimeImmutable('now'));
        $v['ProchaineExecution'] = $prochaine ? $prochaine->format('Y-m-d H:i:s') : null;
        $p = ['nom' => $v['Nom'], 'pt' => $v['PointId'], 'de' => $v['Destination'], 'ci' => $v['CibleId'], 'co' => $v['Coefficient'],
              'fr' => $v['Frequence'], 'he' => $v['Heure'], 'js' => $v['JoursSemaine'], 'jm' => $v['JourMois'], 'ma' => $v['MoisActifs'],
              'cr' => $v['ExpressionCron'], 'to' => $v['ToleranceMinutes'], 'fa' => $v['FacteurAnomalie'], 'vm' => $v['ValidationManuelle'],
              'ac' => $v['Actif'], 'pe' => $v['ProchaineExecution'], 'ua' => $n, 'ub' => $login];
        if ($id) {
            $this->db->execute("UPDATE ConnectLiaisons SET Nom = :nom, PointId = :pt, Destination = :de, CibleId = :ci, Coefficient = :co,
                                    Frequence = :fr, Heure = :he, JoursSemaine = :js, JourMois = :jm, MoisActifs = :ma, ExpressionCron = :cr,
                                    ToleranceMinutes = :to, FacteurAnomalie = :fa, ValidationManuelle = :vm, Actif = :ac,
                                    ProchaineExecution = :pe, UpdatedAt = :ua, UpdatedBy = :ub WHERE Id = :id", $p + ['id' => $id]);
            return $id;
        }
        $this->db->execute("INSERT INTO ConnectLiaisons (Nom, PointId, Destination, CibleId, Coefficient, Frequence, Heure, JoursSemaine,
                                JourMois, MoisActifs, ExpressionCron, ToleranceMinutes, FacteurAnomalie, ValidationManuelle, Actif,
                                ProchaineExecution, CreatedAt, CreatedBy, UpdatedAt, UpdatedBy)
                            VALUES (:nom, :pt, :de, :ci, :co, :fr, :he, :js, :jm, :ma, :cr, :to, :fa, :vm, :ac, :pe, :ca, :cb, :ua, :ub)",
            $p + ['ca' => $n, 'cb' => $login]);
        return $this->db->dernierIdInsere();
    }

    public function supprimerLiaison(int $id): void
    {
        $this->pdo->prepare("DELETE FROM ConnectExecutions WHERE LiaisonId = :id")->execute(['id' => $id]);
        $this->db->execute("DELETE FROM ConnectLiaisons WHERE Id = :id", ['id' => $id]);
    }

    public function executions(?int $liaisonId = null, int $limite = 200, ?string $statut = null): array
    {
        $w = []; $p = [];
        if ($liaisonId !== null) { $w[] = 'e.LiaisonId = :l'; $p['l'] = $liaisonId; }
        if ($statut !== null) { $w[] = 'e.Statut = :s'; $p['s'] = $statut; }
        $limite = max(1, min(2000, $limite));
        return $this->db->fetchAll(
            "SELECT e.*, l.Nom AS LiaisonNom, pt.Nom AS PointNom, pt.Unite AS PointUnite, c.Nom AS CibleNom, b.Nom AS BatimentNom
               FROM ConnectExecutions e JOIN ConnectLiaisons l ON l.Id = e.LiaisonId
               JOIN ConnectPoints pt ON pt.Id = l.PointId LEFT JOIN CompteurEnergie c ON c.Id = l.CibleId
               LEFT JOIN ConnectPasserelles pa ON pa.Id = pt.PasserelleId LEFT JOIN PlanBatiments b ON b.Id = pa.BatimentId"
            . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY e.Prevue DESC LIMIT $limite", $p);
    }

    /**
     * Échéances qui attendent un gestionnaire, une ligne par relevé
     * automatique : la plus récente, et le nombre des plus anciennes.
     *
     * Un compteur remplacé produit une anomalie à CHAQUE échéance tant que
     * personne ne valide (l'index reste sous le dernier relevé écrit) : un
     * relevé horaire en accumule des dizaines en un week-end. La liste en
     * ferait un mur ; une ligne par relevé suffit à décider.
     */
    public function executionsEnAttente(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT e.*, l.Nom AS LiaisonNom, pt.Nom AS PointNom, pt.Unite AS PointUnite, c.Nom AS CibleNom, b.Nom AS BatimentNom
               FROM ConnectExecutions e JOIN ConnectLiaisons l ON l.Id = e.LiaisonId
               JOIN ConnectPoints pt ON pt.Id = l.PointId LEFT JOIN CompteurEnergie c ON c.Id = l.CibleId
               LEFT JOIN ConnectPasserelles pa ON pa.Id = pt.PasserelleId LEFT JOIN PlanBatiments b ON b.Id = pa.BatimentId
              WHERE e.Statut IN ('anomalie', 'a_valider')
              ORDER BY e.LiaisonId, e.Prevue DESC");
        $out = [];
        foreach ($rows as $r) {
            $k = (int)$r['LiaisonId'];
            if (!isset($out[$k])) {
                $r['PlusAnciennes'] = 0;
                $r['PlusAnciennesAnomalies'] = 0;
                $out[$k] = $r;
            } else {
                $out[$k]['PlusAnciennes']++;
                if ($r['Statut'] === 'anomalie') $out[$k]['PlusAnciennesAnomalies']++;
            }
        }
        usort($out, fn($a, $b) => strcmp((string)$b['Prevue'], (string)$a['Prevue']));
        return $out;
    }

    /** Nombre de relevés automatiques qui attendent une validation (pour le menu). */
    public function compterLiaisonsEnAttente(): int
    {
        return (int)($this->db->fetchOne("SELECT COUNT(DISTINCT LiaisonId) AS Nb FROM ConnectExecutions WHERE Statut IN ('anomalie', 'a_valider')")['Nb'] ?? 0);
    }

    // ══ Fichiers et sessions ═══════════════════════════════════════════════

    /** Empreintes des fichiers déjà lus d'une connexion CSV → nom du fichier. */
    public function fichiersTraites(int $passerelleId): array
    {
        $out = [];
        foreach ($this->db->fetchAll("SELECT Empreinte, NomFichier FROM ConnectFichiers WHERE PasserelleId = :p", ['p' => $passerelleId]) as $r) {
            $out[(string)$r['Empreinte']] = (string)$r['NomFichier'];
        }
        return $out;
    }

    public function noterFichiers(int $passerelleId, array $fichiers): void
    {
        $st = $this->pdo->prepare("INSERT INTO ConnectFichiers (PasserelleId, NomFichier, Taille, Empreinte, DateFichier, DateTraitement,
                                       Statut, NbValeurs, Message) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($fichiers as $f) {
            $this->executerInsert($st, [$passerelleId, mb_substr((string)$f['nom'], 0, 250), (int)$f['taille'], (string)$f['empreinte'],
                date('Y-m-d H:i:s', (int)$f['mtime']), self::maintenant(), (string)$f['statut'], (int)$f['nb'],
                mb_substr((string)($f['message'] ?? ''), 0, 500)]);
        }
    }

    public function fichiers(int $passerelleId, int $limite = 100): array
    {
        return $this->db->fetchAll("SELECT * FROM ConnectFichiers WHERE PasserelleId = :p ORDER BY Id DESC LIMIT " . max(1, min(1000, $limite)),
                                   ['p' => $passerelleId]);
    }

    public function sessionsVE(?int $passerelleId = null, int $limite = 200): array
    {
        return $this->db->fetchAll(
            "SELECT s.*, pa.Nom AS PasserelleNom, pa.Identifiant, b.Nom AS BatimentNom FROM ConnectSessionsVE s
               JOIN ConnectPasserelles pa ON pa.Id = s.PasserelleId LEFT JOIN PlanBatiments b ON b.Id = pa.BatimentId"
            . ($passerelleId !== null ? ' WHERE s.PasserelleId = :p' : '') . ' ORDER BY s.Debut DESC LIMIT ' . max(1, min(2000, $limite)),
            $passerelleId !== null ? ['p' => $passerelleId] : []);
    }

    public function commandes(int $limite = 100): array
    {
        return $this->db->fetchAll(
            "SELECT c.*, pa.Nom AS PasserelleNom, pt.Nom AS PointNom, b.Nom AS BatimentNom FROM ConnectCommandes c
               JOIN ConnectPasserelles pa ON pa.Id = c.PasserelleId LEFT JOIN ConnectPoints pt ON pt.Id = c.PointId
               LEFT JOIN PlanBatiments b ON b.Id = pa.BatimentId
              ORDER BY c.Id DESC LIMIT " . max(1, min(1000, $limite)));
    }
}

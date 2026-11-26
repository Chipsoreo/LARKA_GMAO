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
 *  Larka Connect — auto-détection généralisée des équipements
 * ═══════════════════════════════════════════════════════════════════════════
 *
 *  « Qu'y a-t-il sur ce réseau que Larka sait raccorder ? » Une seule passe,
 *  tous protocoles confondus :
 *
 *    Modbus TCP   port 502 ouvert, puis une vraie requête (lecture d'un
 *                 registre, identification de l'équipement — fonction 43) ;
 *    BACnet/IP    Who-Is adressé à chaque machine et diffusé sur chaque
 *                 sous-réseau, I-Am recueillis, puis nom et fabricant lus ;
 *    MQTT         CONNECT / CONNACK sur 1883 (et 8883 en TLS) : broker ouvert
 *                 ou protégé par un mot de passe ;
 *    API REST     interface web sur 80, 443, 8080, 8443 : en-têtes, titre,
 *                 réponse JSON — un superviseur de GTB ressort comme probable ;
 *    CSV          dossiers des racines autorisées qui contiennent des exports ;
 *    OCPP         bornes inconnues qui ont tenté de se connecter à Larka.
 *
 *  GARDE-FOUS. Larka est un outil interne : la détection ne vise QUE des
 *  adresses privées, locales ou de bouclage (jamais Internet), 1 024 adresses
 *  au plus, dans un budget de temps borné. Elle ne s'authentifie nulle part
 *  et n'écrit rien : chaque sonde est une lecture ou une poignée de main. Les
 *  connexions TCP sont ouvertes en parallèle (non bloquantes), un /24 complet
 *  sur tous les ports se parcourt en une vingtaine de secondes au pire.
 * ═══════════════════════════════════════════════════════════════════════════
 */

declare(strict_types=1);

final class ConnectDetection
{
    public const MAX_ADRESSES = 1024;
    /** Protocoles qu'une détection sait trouver, dans l'ordre de l'écran. */
    public const PROTOCOLES = ['modbus', 'bacnet', 'mqtt', 'rest', 'csv', 'ocpp'];
    /** Ports sondés par défaut. */
    public const PORTS = ['modbus' => [502], 'bacnet' => [47808], 'mqtt' => [1883, 8883], 'rest' => [80, 443, 8080, 8443]];
    private const PORTS_TLS = [443, 8443, 8883];
    private const PARALLELE = 192;

    /** Indices d'un superviseur technique dans une page ou un en-tête HTTP. */
    private const INDICES_GTB = '/niagara|desigo|sauter|ecostruxure|struxureware|schneider|trend|distech|wago|siemens|honeywell|metasys|'
        . 'johnson controls|delta controls|loytec|beckhoff|webvision|neuron|chirpstack|thingsboard|node-red|home assistant|lorawan|'
        . 'ocpp|supervis|gtb|gtc|bms|scada|hvac|cvc|chauffage|energy|énergie|compteur|meter/i';

    /**
     * Empreinte d'une machine : ports qui trahissent ce qu'elle est. Sondés
     * seulement sur les adresses où quelque chose a répondu (quelques
     * connexions de plus, pas un balayage).
     */
    private const PORTS_EMPREINTE = [135 => 'windows', 139 => 'windows', 445 => 'windows', 3389 => 'windows',
                                     9100 => 'imprimante', 631 => 'imprimante', 515 => 'imprimante', 62078 => 'mobile', 548 => 'mac'];
    /** Ce que disent l'en-tête « Server » et le titre d'une page web. */
    private const SIGNATURES = [
        'imprimante' => '/printer|imprimante|laserjet|officejet|deskjet|designjet|brother|epson|canon|kyocera|ricoh|xerox|lexmark|konica|bizhub|sharp mx|oki data|cups\b|ipp\b/i',
        'nas'        => '/synology|diskstation|\bdsm\b|qnap|truenas|freenas|readynas|asustor|unraid/i',
        'box'        => '/freebox|livebox|bbox|sfr box|fritz!?box|router|routeur|tp-?link|netgear|ubiquiti|unifi|mikrotik|routeros|openwrt|dd-wrt|draytek|zyxel|linksys|aruba|fortigate|fortinet|pfsense|opnsense|sophos|stormshield|meraki|access point|procurve/i',
        'camera'     => '/hikvision|dahua|axis communications|ip camera|caméra|webcam|network video|\bnvr\b|\bdvr\b|foscam|reolink|vivotek/i',
        'windows'    => '/microsoft-httpapi|microsoft-iis|iis windows|internet information services/i',
    ];
    /** Pourquoi un résultat est écarté d'office (il reste consultable, et « Proposer quand même » le reprend). */
    public const RAISONS = [
        'windows'    => 'PC Windows sans protocole technique : un poste de travail, sans doute',
        'imprimante' => 'imprimante',
        'nas'        => 'serveur de fichiers (NAS)',
        'box'        => 'box, routeur, pare-feu ou point d\'accès Wi-Fi',
        'camera'     => 'caméra ou enregistreur vidéo',
        'mobile'     => 'téléphone ou tablette',
        'mac'        => 'ordinateur Mac',
        'page'       => 'page web sans API ni indice de GTB',
    ];

    private float $fin;
    private float $delaiTcp;
    private float $delaiReponse;
    private array $stats = ['adresses' => 0, 'sondes_tcp' => 0, 'ports_ouverts' => 0, 'whois' => 0];

    public function __construct(float $budget = 90.0, float $delaiTcp = 1.0, float $delaiReponse = 1.5)
    {
        $this->fin = microtime(true) + max(5.0, min(170.0, $budget));
        $this->delaiTcp = max(0.2, min(5.0, $delaiTcp));
        $this->delaiReponse = max(0.3, min(5.0, $delaiReponse));
    }

    public function statistiques(): array
    {
        return $this->stats;
    }

    // ══ Plages ═════════════════════════════════════════════════════════════

    /** Adresse privée, locale, de lien local ou de bouclage : jamais Internet. */
    public static function interne(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) return false;
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    /**
     * Adresses à sonder, d'après une saisie libre : « 192.168.20.0/24 »,
     * « 10.0.5.10-60 », « 10.0.5.10-10.0.6.20 », « 192.168.1.7 », un nom,
     * plusieurs à la suite (espaces, virgules, points-virgules).
     *
     * @return array{0: list<string>, 1: list<string>} [adresses, erreurs]
     */
    public static function cibles(string $plage, int $max = self::MAX_ADRESSES): array
    {
        $ips = []; $erreurs = [];
        $morceaux = preg_split('/[\s,;]+/', trim($plage), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (!$morceaux) return [[], ['Indiquez les adresses à parcourir, par exemple 192.168.20.0/24.']];
        // true : ajoutée ; false : cette saisie est refusée (on passe à la suivante) ; null : trop d'adresses, on s'arrête.
        $ajouter = function (string $ip, string $saisie) use (&$ips, &$erreurs, $max): ?bool {
            if (!self::interne($ip)) {
                $erreurs[] = "« $saisie » : $ip n'est pas une adresse du réseau interne (Larka ne parcourt pas Internet).";
                return false;
            }
            $ips[$ip] = true;
            if (count($ips) > $max) { $erreurs[] = "Plus de $max adresses : réduisez la plage (un /22 au plus)."; return null; }
            return true;
        };
        foreach ($morceaux as $m) {
            if (preg_match('~^(\d{1,3}(?:\.\d{1,3}){3})/(\d{1,2})$~', $m, $x)) {
                $n = (int)$x[2];
                $base = ip2long($x[1]);
                if ($base === false || $n > 32) { $erreurs[] = "« $m » : plage invalide."; continue; }
                if ($n < 22) { $erreurs[] = "« $m » : plage trop large ($max adresses au plus, soit un /22)."; continue; }
                $masque = $n === 0 ? 0 : ((0xFFFFFFFF << (32 - $n)) & 0xFFFFFFFF);
                $debut = $base & $masque; $finP = $debut | (~$masque & 0xFFFFFFFF);
                if ($n <= 30) { $debut++; $finP--; }            // ni l'adresse du réseau, ni la diffusion
                for ($i = $debut; $i <= $finP; $i++) {
                    $r = $ajouter(long2ip($i), $m);
                    if ($r === null) break 2;
                    if ($r === false) continue 2;
                }
                continue;
            }
            if (preg_match('~^(\d{1,3}(?:\.\d{1,3}){3})-(\d{1,3}(?:\.\d{1,3}){3}|\d{1,3})$~', $m, $x)) {
                $a = ip2long($x[1]);
                $b = str_contains($x[2], '.') ? ip2long($x[2]) : (($a & 0xFFFFFF00) | (int)$x[2]);
                if ($a === false || $b === false || $b < $a || (int)$x[2] > 255 && !str_contains($x[2], '.')) { $erreurs[] = "« $m » : intervalle invalide."; continue; }
                if ($b - $a + 1 > $max) { $erreurs[] = "« $m » : plus de $max adresses."; continue; }
                for ($i = $a; $i <= $b; $i++) {
                    $r = $ajouter(long2ip($i), $m);
                    if ($r === null) break 2;
                    if ($r === false) continue 2;
                }
                continue;
            }
            if (filter_var($m, FILTER_VALIDATE_IP)) { if ($ajouter($m, $m) === null) break; continue; }
            if (ConnectPiloteBase::hoteValide($m)) {
                $ip = gethostbyname($m);
                if ($ip === $m || !filter_var($ip, FILTER_VALIDATE_IP)) { $erreurs[] = "« $m » : nom inconnu du serveur (DNS)."; continue; }
                if ($ajouter($ip, $m) === null) break;
                continue;
            }
            $erreurs[] = "« $m » : ni une adresse, ni une plage, ni un nom.";
        }
        return [array_keys($ips), $erreurs];
    }

    /** Sous-réseaux du serveur (un /24 autour de chaque adresse), proposés dans l'écran. */
    public static function suggestions(): array
    {
        $out = [];
        $ajouter = function (string $ip, string $masque = '255.255.255.0', string $iface = '') use (&$out) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || str_starts_with($ip, '127.') || !self::interne($ip)) return;
            $n = substr_count(decbin(ip2long($masque) ?: 0), '1');
            $n = max(22, min(30, $n ?: 24));
            if ($n < 24) $n = 24;                                  // un /24 : parcouru vite, le reste à la demande
            $reseau = long2ip(ip2long($ip) & ((0xFFFFFFFF << (32 - $n)) & 0xFFFFFFFF)) . "/$n";
            if (!isset($out[$reseau])) $out[$reseau] = ['plage' => $reseau, 'interface' => $iface, 'adresse' => $ip];
        };
        if (function_exists('net_get_interfaces')) {
            foreach ((@net_get_interfaces() ?: []) as $nom => $i) {
                foreach (($i['unicast'] ?? []) as $u) {
                    if ((int)($u['family'] ?? 0) === 2 && !empty($u['address'])) $ajouter((string)$u['address'], (string)($u['netmask'] ?? '255.255.255.0'), (string)$nom);
                }
            }
        }
        // Adresse de sortie par défaut (UDP « connecté » : rien n'est envoyé).
        $s = @stream_socket_client('udp://192.0.2.1:9', $e, $es, 1);
        if ($s) { $ajouter(preg_replace('/:\d+$/', '', (string)@stream_socket_get_name($s, false))); fclose($s); }
        return array_values($out);
    }

    // ══ Détection ══════════════════════════════════════════════════════════

    /**
     * @param list<string> $ips        adresses internes (voir cibles())
     * @param list<string> $protocoles parmi PROTOCOLES
     * @param array<string, list<int>> $ports ports par protocole (vide = ceux par défaut)
     * @return list<array> équipements trouvés : protocole, hote, port, identifiant, nom, details, parametres, confiance
     */
    public function detecter(array $ips, array $protocoles, array $ports = []): array
    {
        $protocoles = array_values(array_intersect(self::PROTOCOLES, $protocoles));
        $this->stats['adresses'] = count($ips);
        $ports = $this->ports($protocoles, $ports, count($ips));
        $trouves = [];

        // BACnet d'abord : l'UDP ne coûte rien, et les réponses arrivent pendant le reste.
        if (in_array('bacnet', $protocoles, true) && $ips) {
            foreach ($this->bacnet($ips, $ports['bacnet']) as $t) $trouves[] = $t;
        }

        // TCP : toutes les machines × tous les ports, en parallèle.
        $cibles = []; $protoDe = [];
        foreach (['modbus', 'mqtt', 'rest'] as $p) {
            if (!in_array($p, $protocoles, true)) continue;
            foreach ($ports[$p] as $port) { $protoDe[$port][] = $p; }
        }
        foreach ($ips as $ip) foreach (array_keys($protoDe) as $port) $cibles[] = [$ip, (int)$port];
        $ouverts = $cibles ? $this->ouvertsTcp($cibles) : [];
        $this->stats['ports_ouverts'] = count($ouverts);

        foreach ($ouverts as [$ip, $port]) {
            if (microtime(true) > $this->fin) break;
            foreach ($protoDe[$port] as $p) {
                $r = match ($p) {
                    'modbus' => $this->sondeModbus($ip, $port),
                    'mqtt'   => $this->sondeMqtt($ip, $port),
                    'rest'   => $this->sondeHttp($ip, $port),
                };
                if ($r) { $trouves[] = $r; break; }            // un port, un protocole : le premier qui répond
            }
        }

        // Ce que sont les machines qui ont répondu : PC Windows, imprimante, box… Une interface
        // web d'un poste de travail ou d'une imprimante est écartée ; un vrai protocole technique
        // (Modbus, BACnet, MQTT) est toujours gardé, même sur un PC Windows (PC relais).
        $machines = $this->empreintes($trouves);
        foreach ($trouves as &$t) self::qualifier($t, $machines[$t['hote'] ?? ''] ?? []);
        unset($t);

        if (in_array('csv', $protocoles, true)) foreach (self::dossiersCsv() as $t) $trouves[] = $t;
        if (in_array('ocpp', $protocoles, true)) foreach (self::bornesInconnues() as $t) $trouves[] = $t;

        usort($trouves, fn($a, $b) => [self::rangIp($a['hote'] ?? ''), $a['port'] ?? 0, $a['protocole']]
                                  <=> [self::rangIp($b['hote'] ?? ''), $b['port'] ?? 0, $b['protocole']]);
        return $trouves;
    }

    /** Ports d'empreinte ouverts, par adresse : ['windows' => true, 'imprimante' => true…]. */
    private function empreintes(array $trouves): array
    {
        $ips = [];
        foreach ($trouves as $t) {
            $ip = (string)($t['hote'] ?? '');
            if (filter_var($ip, FILTER_VALIDATE_IP)) $ips[$ip] = true;
        }
        if (!$ips || $this->fin - microtime(true) < 2.0) return [];
        $cibles = [];
        foreach (array_keys($ips) as $ip) foreach (array_keys(self::PORTS_EMPREINTE) as $port) $cibles[] = [$ip, $port];
        $out = [];
        foreach ($this->ouvertsTcp($cibles) as [$ip, $port]) $out[$ip][self::PORTS_EMPREINTE[$port]] = true;
        return $out;
    }

    /**
     * Nature de la machine (« machine ») et, pour ce qui n'est pas un équipement
     * technique, la raison de l'écarter (« ecarte »). Un protocole technique
     * n'est jamais écarté : sur un PC Windows, il est signalé comme relais possible.
     */
    public static function qualifier(array &$t, array $ports): void
    {
        $sig = (string)($t['_signature'] ?? '');
        unset($t['_signature']);
        $type = '';
        if ($sig !== '') foreach (self::SIGNATURES as $k => $re) if (preg_match($re, $sig)) { $type = $k; break; }
        if ($type === '') foreach (['imprimante', 'mobile', 'mac', 'windows'] as $k) if (!empty($ports[$k])) { $type = $k; break; }
        $t['machine'] = $type;
        $t['ecarte'] = null;
        if (($t['protocole'] ?? '') !== 'rest') {
            if ($type === 'windows') $t['details'] = rtrim((string)$t['details']) . ' Sur un PC Windows : un PC relais (passerelle logicielle) ?';
            return;
        }
        if (in_array($type, ['imprimante', 'nas', 'box', 'camera', 'mobile', 'mac'], true)) $t['ecarte'] = self::RAISONS[$type];
        elseif ($type === 'windows' && ($t['confiance'] ?? '') !== 'probable') $t['ecarte'] = self::RAISONS['windows'];
        elseif (($t['confiance'] ?? '') === 'possible') $t['ecarte'] = self::RAISONS['page'];
        elseif ($type === 'windows') $t['details'] = rtrim((string)$t['details']) . ' Sur un PC Windows (serveur de supervision ?).';
    }

    /** Clé d'un équipement trouvé : la même pour « déjà raccordé » et pour « ignoré ». */
    public static function cle(array $t): string
    {
        return match ((string)($t['protocole'] ?? '')) {
            'csv'  => 'csv|' . trim(str_replace('\\', '/', (string)($t['parametres']['dossier'] ?? '')), '/'),
            'ocpp' => 'ocpp|' . strtolower((string)($t['identifiant'] ?? '')),
            default => ($t['protocole'] ?? '') . '|' . strtolower((string)($t['hote'] ?? '')) . '|' . (int)($t['port'] ?? 0),
        };
    }

    public function tempsEcoule(): bool
    {
        return microtime(true) > $this->fin;
    }

    private static function rangIp(string $ip): string
    {
        $l = ip2long($ip);
        return $l === false ? 'z' . $ip : sprintf('%010u', $l);
    }

    private function ports(array $protocoles, array $demandes, int $adresses = self::MAX_ADRESSES): array
    {
        // Ports par protocole : 8 sur une grande plage, davantage sur quelques adresses
        // (plusieurs équipements derrière une même adresse), pour un coût au plus égal.
        $max = max(8, min(64, intdiv(8 * self::MAX_ADRESSES, max(1, $adresses))));
        $out = [];
        foreach (self::PORTS as $p => $defaut) {
            $liste = array_values(array_unique(array_filter(array_map('intval', (array)($demandes[$p] ?? [])), fn($x) => $x > 0 && $x < 65536)));
            $out[$p] = $liste ? array_slice($liste, 0, $max) : $defaut;
        }
        return $out;
    }

    private static function hp(string $ip, int $port): string
    {
        return (str_contains($ip, ':') ? "[$ip]" : $ip) . ':' . $port;
    }

    /**
     * Ports TCP ouverts parmi les cibles. Connexions non bloquantes, PARALLELE à
     * la fois : une machine absente ne coûte que le délai, pas une file d'attente.
     *
     * @param list<array{0: string, 1: int}> $cibles
     * @return list<array{0: string, 1: int}>
     */
    private function ouvertsTcp(array $cibles): array
    {
        $ouverts = []; $actifs = []; $i = 0; $n = count($cibles);
        while (($i < $n || $actifs) && microtime(true) < $this->fin) {
            while ($i < $n && count($actifs) < self::PARALLELE) {
                [$ip, $port] = $cibles[$i++];
                $this->stats['sondes_tcp']++;
                $s = @stream_socket_client('tcp://' . self::hp($ip, $port), $en, $es, 0, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
                if (!$s) continue;                                         // refus immédiat (machine locale)
                stream_set_blocking($s, false);
                $actifs[(int)$s] = ['s' => $s, 'ip' => $ip, 'port' => $port, 't' => microtime(true)];
            }
            if (!$actifs) continue;
            $w = []; $x = [];
            foreach ($actifs as $a) { $w[] = $a['s']; $x[] = $a['s']; }
            $r = null;
            $pret = @stream_select($r, $w, $x, 0, 50000);
            if ($pret > 0) {
                $echecs = [];
                foreach ($x as $s) $echecs[(int)$s] = true;                // Windows : l'échec arrive en « exception »
                foreach ($w as $s) {
                    $k = (int)$s;
                    if (!isset($actifs[$k])) continue;
                    if (empty($echecs[$k]) && @stream_socket_get_name($s, true) !== false) $ouverts[] = [$actifs[$k]['ip'], $actifs[$k]['port']];
                    @fclose($s);
                    unset($actifs[$k]);
                }
                foreach (array_keys($echecs) as $k) if (isset($actifs[$k])) { @fclose($actifs[$k]['s']); unset($actifs[$k]); }
            }
            $t = microtime(true);
            foreach ($actifs as $k => $a) if ($t - $a['t'] > $this->delaiTcp) { @fclose($a['s']); unset($actifs[$k]); }
        }
        foreach ($actifs as $a) @fclose($a['s']);
        return $ouverts;
    }

    /** Ouverture bloquante, avec délai court (sondes de confirmation). */
    private function ouvrir(string $ip, int $port, bool $tls = false, bool $verifier = false)
    {
        $ctx = stream_context_create($tls ? ['ssl' => ['verify_peer' => $verifier, 'verify_peer_name' => $verifier, 'SNI_enabled' => true,
                                                       'allow_self_signed' => !$verifier]] : []);
        $f = @stream_socket_client(($tls ? 'tls://' : 'tcp://') . self::hp($ip, $port), $en, $es, $this->delaiTcp + ($tls ? 1.0 : 0), STREAM_CLIENT_CONNECT, $ctx);
        if (!$f) return null;
        stream_set_timeout($f, (int)ceil($this->delaiReponse));
        return $f;
    }

    private static function lireExact($f, int $n, float $echeance): ?string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            $reste = $echeance - microtime(true);
            if ($reste <= 0) return null;
            $r = [$f]; $w = null; $x = null;
            $pret = @stream_select($r, $w, $x, (int)floor($reste), (int)(($reste - floor($reste)) * 1e6));
            if ($pret === false) return null;
            if ($pret === 0) continue;
            $m = @fread($f, $n - strlen($buf));
            if ($m === false || ($m === '' && feof($f))) return null;
            $buf .= $m;
        }
        return $buf;
    }

    private static function equipement(string $protocole, string $hote, ?int $port, string $nom, string $details, string $confiance,
                                       array $parametres = [], string $identifiant = '', array $plus = []): array
    {
        return ['protocole' => $protocole, 'hote' => $hote, 'port' => $port, 'identifiant' => $identifiant,
                'nom' => mb_substr(trim($nom), 0, 120), 'details' => mb_substr(trim($details), 0, 400),
                'confiance' => $confiance, 'parametres' => $parametres] + $plus;
    }

    // ── Modbus ─────────────────────────────────────────────────────────────

    private function sondeModbus(string $ip, int $port): ?array
    {
        $f = $this->ouvrir($ip, $port);
        if (!$f) return null;
        $requete = function (int $tid, int $unite, string $pdu) use (&$f): ?array {      // par référence : $f est rouvert pour l'unit id 255
            @fwrite($f, pack('nnnC', $tid, 0, strlen($pdu) + 1, $unite) . $pdu);
            $ech = microtime(true) + $this->delaiReponse;
            $h = self::lireExact($f, 7, $ech);
            if ($h === null) return null;
            $e = unpack('ntid/nproto/nlen/Cunite', $h);
            if ($e['proto'] !== 0 || $e['len'] < 2 || $e['len'] > 260) return ['invalide' => true];
            $corps = self::lireExact($f, $e['len'] - 1, $ech);
            if ($corps === null || $e['tid'] !== $tid) return ['invalide' => true];
            return ['pdu' => $corps];
        };
        $unite = 1;
        $r = $requete(0x4C31, 1, "\x03\x00\x00\x00\x01");
        if ($r === null) {
            // Certains équipements n'acceptent que l'unit id 255 (ou 0) en TCP.
            @fclose($f); $f = $this->ouvrir($ip, $port);
            if (!$f) return null;
            $unite = 255;
            $r = $requete(0x4C32, 255, "\x03\x00\x00\x00\x01");
        }
        if ($r === null || !empty($r['invalide'])) {
            @fclose($f);
            // Le port 502 est réservé à Modbus : ouvert mais muet, c'est presque sûrement lui.
            return $port === 502 ? self::equipement('modbus', $ip, $port, "Équipement Modbus $ip", 'Port 502 ouvert, mais aucune réponse Modbus à l\'unit id 1 ni 255.', 'possible',
                                                    ['unite_defaut' => 1]) : null;
        }
        $fc = ord($r['pdu'][0]);
        $detail = ($fc & 0x80)
            ? sprintf('Répond en Modbus TCP (unit id %d, exception 0x%02X%s).', $unite, ord($r['pdu'][1] ?? "\0"),
                      ord($r['pdu'][1] ?? "\0") === 0x0B ? ' : passerelle, aucun esclave derrière ce numéro' : '')
            : "Répond en Modbus TCP (unit id $unite, registre 0 lu).";
        // Identification (fonction 43 / 14) : fabricant, référence, version.
        $id = [];
        $ri = $requete(0x4C33, $unite, "\x2B\x0E\x01\x00");
        if ($ri && !empty($ri['pdu']) && ord($ri['pdu'][0]) === 0x2B && strlen($ri['pdu']) >= 7) {
            $b = $ri['pdu']; $nb = ord($b[6]); $pos = 7;
            for ($k = 0; $k < $nb && $pos + 2 <= strlen($b); $k++) {
                $oid = ord($b[$pos]); $len = ord($b[$pos + 1]);
                $id[$oid] = trim((string)@mb_convert_encoding(substr($b, $pos + 2, $len), 'UTF-8', 'ISO-8859-1'));
                $pos += 2 + $len;
            }
        }
        @fclose($f);
        $ident = trim(($id[0] ?? '') . ' ' . ($id[1] ?? ''));
        if ($ident !== '') $detail .= " $ident" . (!empty($id[2]) ? ' (' . $id[2] . ')' : '') . '.';
        return self::equipement('modbus', $ip, $port, ($ident !== '' ? $ident : 'Équipement Modbus') . " — $ip", $detail, 'sure',
                                ['unite_defaut' => $unite], '', ['fabricant' => $id[0] ?? '', 'modele' => $id[1] ?? '']);
    }

    // ── MQTT ───────────────────────────────────────────────────────────────

    private function sondeMqtt(string $ip, int $port): ?array
    {
        $tls = in_array($port, self::PORTS_TLS, true);
        $f = $this->ouvrir($ip, $port, $tls);
        if (!$f) return null;
        $chaine = fn(string $s) => pack('n', strlen($s)) . $s;
        $corps = $chaine('MQTT') . "\x04\x02" . pack('n', 10) . $chaine('larka-detection-' . bin2hex(random_bytes(3)));
        @fwrite($f, "\x10" . chr(strlen($corps)) . $corps);
        $rep = self::lireExact($f, 4, microtime(true) + $this->delaiReponse);
        if ($rep !== null && ord($rep[0]) === 0x20) @fwrite($f, "\xE0\x00");
        @fclose($f);
        if ($rep === null || ord($rep[0]) !== 0x20 || ord($rep[1]) !== 2) return null;
        $code = ord($rep[3]);
        $etat = match ($code) { 0 => 'ouvert (sans identifiants)', 4, 5 => 'protégé : identifiant et mot de passe requis',
                                default => "répond (code de refus $code)" };
        return self::equipement('mqtt', $ip, $port, "Broker MQTT — $ip", "Broker MQTT $etat" . ($tls ? ', chiffré (TLS).' : '.'),
                                'sure', ['tls' => $tls, 'verifier_certificat' => false], '', ['identifiants_requis' => in_array($code, [4, 5], true)]);
    }

    // ── Interface web / API ────────────────────────────────────────────────

    private function sondeHttp(string $ip, int $port): ?array
    {
        $tls = in_array($port, self::PORTS_TLS, true);
        $verifie = true;
        $f = $tls ? $this->ouvrir($ip, $port, true, true) : $this->ouvrir($ip, $port);
        if (!$f && $tls) { $verifie = false; $f = $this->ouvrir($ip, $port, true, false); }
        if (!$f) return null;
        @fwrite($f, "GET / HTTP/1.1\r\nHost: " . self::hp($ip, $port) . "\r\nUser-Agent: Larka-Connect-Detection\r\n"
                  . "Accept: application/json, text/html;q=0.8, */*;q=0.5\r\nConnection: close\r\n\r\n");
        $brut = ''; $ech = microtime(true) + $this->delaiReponse;
        while (strlen($brut) < 16384 && microtime(true) < $ech) {
            $r = [$f]; $w = null; $x = null;
            if (@stream_select($r, $w, $x, 0, 200000) < 1) { if (str_contains($brut, "\r\n\r\n") && strlen($brut) > 2048) break; continue; }
            $m = @fread($f, 4096);
            if ($m === false || $m === '') break;
            $brut .= $m;
        }
        @fclose($f);
        if (!preg_match('~^HTTP/\d(?:\.\d)?\s+(\d{3})~', $brut, $st)) return null;
        $code = (int)$st[1];
        [$entetes, $corps] = array_pad(explode("\r\n\r\n", $brut, 2), 2, '');
        $h = fn(string $nom) => preg_match('/^' . preg_quote($nom, '/') . ':\s*(.+)$/mi', $entetes, $m) ? trim($m[1]) : '';
        $serveur = $h('Server'); $type = strtolower($h('Content-Type')); $auth = $h('WWW-Authenticate');
        $titre = preg_match('~<title[^>]*>(.*?)</title>~is', $corps, $m) ? trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
        if ($titre !== '' && !mb_check_encoding($titre, 'UTF-8')) $titre = (string)@mb_convert_encoding($titre, 'UTF-8', 'ISO-8859-1');
        $titre = mb_substr(preg_replace('/\s+/u', ' ', $titre) ?? '', 0, 80);
        $json = str_contains($type, 'json');
        $indices = preg_match(self::INDICES_GTB, $serveur . ' ' . $titre . ' ' . mb_substr($corps, 0, 4000));
        $schema = $tls ? 'https' : 'http';
        $base = "$schema://" . self::hp($ip, $port);
        $quoi = $json ? 'API JSON' : 'Interface web';
        $nom = ($titre !== '' ? $titre : ($serveur !== '' ? $serveur : $quoi)) . " — $ip" . (in_array($port, [80, 443], true) ? '' : ":$port");
        $detail = "$quoi (HTTP $code" . ($serveur !== '' ? ", $serveur" : '') . ')' . ($titre !== '' ? " « $titre »" : '')
                . ($auth !== '' || $code === 401 ? ' — authentification demandée' : '')
                . ($tls && !$verifie ? ' — certificat non reconnu (auto-signé ?)' : '') . '.';
        return self::equipement('rest', $ip, $port, $nom, $detail, ($json || $indices) ? 'probable' : 'possible',
                                ['url_base' => $base, 'verifier_certificat' => $verifie,
                                 'auth' => stripos($auth, 'bearer') === 0 ? 'bearer' : (($auth !== '' || $code === 401) ? 'basic' : 'aucune')],
                                '', ['json' => $json, '_signature' => $serveur . ' ' . $titre . ' ' . $auth]);
    }

    // ── BACnet/IP ──────────────────────────────────────────────────────────

    /** Who-Is à chaque adresse et sur chaque sous-réseau ; I-Am recueillis ; identité lue. */
    private function bacnet(array $ips, array $ports): array
    {
        $voulues = array_fill_keys($ips, true);
        $diffusions = [];
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || str_starts_with($ip, '127.')) continue;
            $diffusions[long2ip(ip2long($ip) | 0xFF)] = true;               // x.y.z.255
        }
        $quiEst = ConnectPiloteBacnet::trameWhoIs(false);
        $quiEstDiffuse = ConnectPiloteBacnet::trameWhoIs(true);
        $reponses = [];                                                        // "ip:port" → n° d'automate

        $noter = function (string $dg, string $ip, int $port) use (&$reponses, $voulues): void {
            $num = ConnectPiloteBacnet::iAm($dg);
            if ($num !== null && isset($voulues[$ip])) $reponses["$ip|$port"] = [$ip, $port, $num];
        };

        if (function_exists('socket_create')) {
            $s = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            if (!$s) return [];
            @socket_set_option($s, SOL_SOCKET, SO_BROADCAST, 1);
            // Écouter sur 47808 si on le peut : certains automates répondent I-Am en diffusion.
            // Sans SO_REUSEADDR : si un logiciel BACnet de ce serveur tient déjà le port, on ne le
            // lui dispute pas (ses réponses lui reviendraient), on prend un port éphémère.
            if (!@socket_bind($s, '0.0.0.0', 47808)) @socket_bind($s, '0.0.0.0', 0);
            socket_set_nonblock($s);
            $lire = function () use ($s, $noter): void {
                $buf = ''; $de = ''; $port = 0;
                while (@socket_recvfrom($s, $buf, 2048, 0, $de, $port) > 0) $noter($buf, (string)$de, (int)$port);
            };
            $k = 0;
            foreach ($ports as $port) {
                foreach (array_keys($diffusions) as $b) { @socket_sendto($s, $quiEstDiffuse, strlen($quiEstDiffuse), 0, $b, $port); $this->stats['whois']++; }
                foreach ($ips as $ip) {
                    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) continue;
                    @socket_sendto($s, $quiEst, strlen($quiEst), 0, $ip, $port);
                    $this->stats['whois']++;
                    if (++$k % 64 === 0) { usleep(2000); $lire(); }
                }
            }
            $fin = min($this->fin, microtime(true) + 2.0);
            while (microtime(true) < $fin) { $lire(); usleep(50000); }
            socket_close($s);
        } else {
            // Sans l'extension sockets : pas de diffusion, Who-Is adressé seulement.
            $s = @stream_socket_server('udp://0.0.0.0:0', $en, $es, STREAM_SERVER_BIND);
            if (!$s) return [];
            stream_set_blocking($s, false);
            $lire = function () use ($s, $noter): void {
                while (($dg = @stream_socket_recvfrom($s, 2048, 0, $de)) !== false && $dg !== '') {
                    $p = strrpos((string)$de, ':');
                    $noter($dg, trim(substr((string)$de, 0, (int)$p), '[]'), (int)substr((string)$de, (int)$p + 1));
                }
            };
            foreach ($ports as $port) foreach ($ips as $ip) { @stream_socket_sendto($s, $quiEst, 0, self::hp($ip, $port)); $this->stats['whois']++; }
            $fin = min($this->fin, microtime(true) + 2.0);
            while (microtime(true) < $fin) { $lire(); usleep(50000); }
            fclose($s);
        }

        $out = [];
        foreach ($reponses as [$ip, $port, $num]) {
            $id = ['nom' => '', 'fabricant' => '', 'modele' => '', 'emplacement' => ''];
            if (microtime(true) < $this->fin) {
                try {
                    $p = new ConnectPiloteBacnet(['Id' => 0, 'Nom' => 'détection', 'Protocole' => 'bacnet', 'Hote' => $ip, 'Port' => $port,
                                                  'Identifiant' => (string)$num, 'params' => ['delai_secondes' => $this->delaiReponse], 'secret' => []]);
                    $id = $p->identite();
                    $p->fermer();
                } catch (\Throwable $e) { /* l'I-Am suffit */ }
            }
            $fab = trim(($id['fabricant'] ?? '') . ' ' . ($id['modele'] ?? ''));
            $nom = ($id['nom'] ?? '') !== '' ? $id['nom'] : "Automate BACnet n° $num";
            $empl = trim((string)($id['emplacement'] ?? ''));
            $out[] = self::equipement('bacnet', $ip, $port, "$nom — $ip", "Automate BACnet/IP n° $num" . ($fab !== '' ? " — $fab" : '') . '.'
                                      . ($empl !== '' ? " Emplacement : $empl." : ''),
                                      'sure', [], (string)$num, ['fabricant' => $id['fabricant'] ?? '', 'modele' => $id['modele'] ?? '', 'emplacement' => $empl]);
        }
        return $out;
    }

    // ── Dossiers CSV ───────────────────────────────────────────────────────

    /** Dossiers des racines autorisées qui contiennent des fichiers CSV (trois niveaux au plus). */
    public static function dossiersCsv(): array
    {
        $larka = realpath(dirname(__DIR__, 2)) ?: dirname(__DIR__, 2);
        $out = [];
        foreach (ConnectPiloteCsv::racines() as $racine) {
            $dossiers = [$racine];
            try {
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine, FilesystemIterator::SKIP_DOTS),
                                                    RecursiveIteratorIterator::SELF_FIRST);
                $it->setMaxDepth(2);
                foreach ($it as $f) if ($f->isDir() && !$f->isLink()) $dossiers[] = $f->getPathname();
            } catch (\Throwable $e) { /* racine illisible */ }
            foreach (array_slice($dossiers, 0, 200) as $d) {
                $fichiers = array_merge(glob($d . '/*.csv') ?: [], glob($d . '/*.CSV') ?: []);
                if (!$fichiers) continue;
                $recent = max(array_map(fn($x) => (int)@filemtime($x), $fichiers));
                $rel = str_starts_with($d, $larka . DIRECTORY_SEPARATOR) ? substr($d, strlen($larka) + 1) : $d;
                $rel = str_replace('\\', '/', $rel);
                $out[] = self::equipement('csv', '', null, 'Exports CSV — ' . basename($d), count($fichiers) . ' fichier(s) CSV, le plus récent du '
                    . date('d/m/Y à H:i', $recent) . " ($rel).", 'probable', ['dossier' => $rel], '', ['dossier' => $rel]);
            }
        }
        return $out;
    }

    // ── Bornes OCPP ────────────────────────────────────────────────────────

    /** Bornes non déclarées qui ont tenté de se connecter au service OCPP. */
    public static function bornesInconnues(): array
    {
        $service = ConnectPiloteOcpp::etatService();
        if (empty($service['en_marche'])) return [];
        $out = [];
        foreach ((array)($service['inconnues'] ?? []) as $i) {
            $chemin = (string)($i['chemin'] ?? '');
            $morceaux = array_values(array_filter(explode('/', explode('?', $chemin)[0])));
            $ident = $morceaux ? rawurldecode((string)end($morceaux)) : '';
            if ($ident === '' || !preg_match('/^[A-Za-z0-9._\-]{1,48}$/', $ident)) continue;
            $out[$ident] = self::equipement('ocpp', (string)($i['ip'] ?? ''), null, "Borne $ident",
                'Borne de recharge refusée (non déclarée) le ' . (string)($i['quand'] ?? '?') . ', depuis ' . (string)($i['ip'] ?? '?') . '.',
                'sure', [], $ident);
        }
        return array_values($out);
    }

    // ══ Rapprochements ═════════════════════════════════════════════════════

    /**
     * Marque ce qui est déjà raccordé (« deja » : Id de la connexion) et devine
     * le bâtiment (« batimentId », « batimentPar » : reseau, appris, nom ou
     * cible) : réseaux déclarés, puis appris, puis nom (ConnectBatiments).
     * Sans liste de bâtiments : d'après les connexions du même sous-réseau.
     * $cible : bâtiment dont on a parcouru les réseaux — le choix par défaut.
     */
    public static function rapprocher(array $trouves, array $passerelles, ?array $batiments = null, ?int $cible = null): array
    {
        $parCle = []; $batParReseau = [];
        foreach ($passerelles as $pa) {
            $prm = is_array($pa['Parametres'] ?? null) ? $pa['Parametres'] : [];
            $proto = (string)$pa['Protocole'];
            $hote = strtolower((string)($pa['Hote'] ?? ''));
            $port = (int)($pa['Port'] ?? 0);
            if ($proto === 'rest' && ($u = parse_url((string)($prm['url_base'] ?? ''))) && !empty($u['host'])) {
                $hote = strtolower(trim($u['host'], '[]'));
                $port = (int)($u['port'] ?? (strtolower((string)($u['scheme'] ?? 'http')) === 'https' ? 443 : 80));
            }
            if ($proto === 'csv') $parCle['csv|' . trim(str_replace('\\', '/', (string)($prm['dossier'] ?? '')), '/')] = (int)$pa['Id'];
            elseif ($proto === 'ocpp') $parCle['ocpp|' . strtolower((string)($pa['Identifiant'] ?? ''))] = (int)$pa['Id'];
            elseif ($hote !== '') $parCle["$proto|$hote|$port"] = (int)$pa['Id'];
            $ip = filter_var($hote, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $hote : '';
            if ($ip !== '' && !empty($pa['BatimentId'])) $batParReseau[self::reseau24($ip)][(int)$pa['BatimentId']] = true;
        }
        foreach ($trouves as &$t) {
            $t['deja'] = $parCle[self::cle($t)] ?? null;
            if ($batiments !== null) {
                $g = ConnectBatiments::deviner($t, $batiments);
                $t['batimentId'] = $g['id'];
                $t['batimentPar'] = $g['par'];
                if ($t['batimentId'] === null && $cible !== null) { $t['batimentId'] = $cible; $t['batimentPar'] = 'cible'; }
                continue;
            }
            $bats = ($t['hote'] ?? '') !== '' && filter_var($t['hote'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
                ? array_keys($batParReseau[self::reseau24($t['hote'])] ?? []) : [];
            $t['batimentId'] = count($bats) === 1 ? $bats[0] : null;   // un seul bâtiment sur ce sous-réseau : on le propose
            $t['batimentPar'] = $t['batimentId'] ? 'appris' : null;
        }
        unset($t);
        return $trouves;
    }

    private static function reseau24(string $ip): string
    {
        return long2ip(ip2long($ip) & 0xFFFFFF00);
    }
}

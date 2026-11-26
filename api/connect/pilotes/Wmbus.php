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
 *  Larka Connect — compteurs radio wM-Bus, par une clé USB réceptrice
 * ═══════════════════════════════════════════════════════════════════════════
 *
 *  Les compteurs d'eau, de chaleur et de gaz récents émettent leur index par
 *  radio (Wireless M-Bus, EN 13757-4, 868 MHz, profil OMS). Une clé USB
 *  réceptrice, branchée sur le serveur ou sur un PC releveur, les entend :
 *
 *    IMST iM871A-USB      le plus répandu (relève, intégrateurs) ;
 *    Amber AMB8465-M      idem, autre fabricant ;
 *    CUL (culfw)          clé radio « bricolage », très courante.
 *
 *  Larka écoute la clé quelques secondes à chaque relevé, retire les sommes
 *  de contrôle radio, déchiffre (AES-128, OMS mode 5) avec la clé de CHAQUE
 *  compteur, et lit les données M-Bus (index, énergie, débit, températures,
 *  date, codes d'erreur). Aucune bibliothèque, aucun pilote à installer :
 *
 *    Linux / macOS  le port série est réglé par « stty » et lu comme un fichier ;
 *    Windows        PowerShell (System.IO.Ports, présent sur tout Windows).
 *
 *  Le port est trouvé tout seul : l'inventaire du matériel reconnaît les clés
 *  par leur identifiant USB (VID:PID), et signale aussi ce qui ne convient
 *  pas (clé SDR, concentrateur LoRa à transformer en passerelle…).
 *
 *  Ce qui n'est PAS fait : modes de chiffrement 7 et 13 (OMS 4), trames
 *  compactes (CI 0x79), couche ELL des compteurs Kamstrup récents.
 * ═══════════════════════════════════════════════════════════════════════════
 */

declare(strict_types=1);

/**
 * Port série sans extension : inventaire du matériel, puis écoute.
 */
final class ConnectSerie
{
    /**
     * Matériel connu, par identifiant USB. « modele » : un des récepteurs pris
     * en charge, ou null (on dit ce qu'est l'appareil et quoi en faire).
     */
    public const CONNUS = [
        '10c4:87ed' => ['modele' => 'im871a', 'libelle' => 'IMST iM871A-USB (récepteur wM-Bus)', 'debit' => 57600],
        '03eb:204b' => ['modele' => 'cul', 'libelle' => 'CUL / culfw (récepteur radio 868 MHz)', 'debit' => 38400],
        '0403:6001' => ['modele' => null, 'libelle' => 'Adaptateur série FTDI (peut être un Amber AMB8465-M, ou un adaptateur Modbus RTU)', 'debit' => 9600],
        '0403:6015' => ['modele' => null, 'libelle' => 'Adaptateur série FTDI', 'debit' => 9600],
        '10c4:ea60' => ['modele' => null, 'libelle' => 'Adaptateur série Silicon Labs CP210x', 'debit' => 9600],
        '1a86:7523' => ['modele' => null, 'libelle' => 'Adaptateur série CH340', 'debit' => 9600],
        '067b:2303' => ['modele' => null, 'libelle' => 'Adaptateur série Prolific PL2303', 'debit' => 9600],
        '0bda:2838' => ['modele' => null, 'libelle' => 'Clé radio logicielle RTL-SDR',
                        'conseil' => 'Une clé SDR ne décode pas le wM-Bus d\'elle-même : préférez une clé IMST iM871A ou Amber AMB8465.'],
        '0bda:2832' => ['modele' => null, 'libelle' => 'Clé radio logicielle RTL-SDR',
                        'conseil' => 'Une clé SDR ne décode pas le wM-Bus d\'elle-même : préférez une clé IMST iM871A ou Amber AMB8465.'],
        '0483:5740' => ['modele' => null, 'libelle' => 'Port série STM32 (peut être un concentrateur LoRa USB, RAK2287 / SX1302)',
                        'conseil' => 'Un concentrateur LoRa se fait passerelle : installez-y le « packet forwarder » Semtech pointé vers Larka, port UDP 1700.'],
    ];
    public const MODELES = ['im871a' => 'IMST iM871A', 'amb8465' => 'Amber AMB8465', 'cul' => 'CUL (culfw)'];
    public const DEBITS = ['im871a' => 57600, 'amb8465' => 9600, 'cul' => 38400];

    public static function windows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    /** Nom saisi dans l'écran : port d'un Linux, d'un Mac ou d'un Windows (la clé peut être sur un PC releveur). */
    public static function portSaisi(string $p): bool
    {
        return (bool)preg_match('/^COM\d{1,3}$/i', $p) || self::portUnix($p);
    }

    private static function portUnix(string $p): bool
    {
        return (bool)preg_match('#^/dev/(tty(USB|ACM|AMA|S|XRUSB)\d{1,3}|pts/\d{1,4}|serial/by-id/[A-Za-z0-9._:+-]{1,120}|(tty|cu)\.(usbserial|usbmodem|SLAB_USBtoUART|wchusbserial)[A-Za-z0-9._-]{0,60})$#', $p);
    }

    /** Nom de port acceptable : un périphérique série, jamais un fichier quelconque. */
    public static function portValide(string $p): bool
    {
        if (self::windows()) return (bool)preg_match('/^COM\d{1,3}$/i', $p);
        return self::portUnix($p);
    }

    /**
     * Ports série présents et ce qu'on sait d'eux.
     * $racine : préfixe des chemins /sys et /dev — une fausse arborescence pour les
     * épreuves (variable LARKA_RADIO_RACINE), qui ne fait que changer où l'on lit.
     * @return list<array{port: string, vid: ?string, pid: ?string, fabricant: string, produit: string, serie: string,
     *                    modele: ?string, libelle: string, debit: ?int, conseil: ?string}>
     */
    public static function ports(string $racine = ''): array
    {
        if ($racine === '') $racine = rtrim((string)(getenv('LARKA_RADIO_RACINE') ?: ''), '/');
        $out = [];
        if (self::windows() && $racine === '') {
            foreach (self::portsWindows() as $p) $out[] = self::reconnaitre($p);
            return $out;
        }
        $vus = [];
        foreach (glob("$racine/dev/tty{USB,ACM}[0-9]*", GLOB_BRACE) ?: [] as $dev) {
            $nom = basename($dev);
            $p = ['port' => "/dev/$nom", 'vid' => null, 'pid' => null, 'fabricant' => '', 'produit' => '', 'serie' => ''];
            $d = @realpath("$racine/sys/class/tty/$nom/device");
            for ($i = 0; $d && $i < 5; $i++, $d = dirname($d)) {
                if (!is_file("$d/idVendor")) continue;
                $lire = fn($f) => trim((string)@file_get_contents("$d/$f"));
                $p['vid'] = strtolower($lire('idVendor')); $p['pid'] = strtolower($lire('idProduct'));
                $p['fabricant'] = $lire('manufacturer'); $p['produit'] = $lire('product'); $p['serie'] = $lire('serial');
                break;
            }
            $vus[] = $nom;
            $out[] = self::reconnaitre($p);
        }
        // macOS : pas de /sys, les noms disent la famille d'adaptateur.
        foreach (glob("$racine/dev/cu.{usbserial,usbmodem,SLAB_USBtoUART,wchusbserial}*", GLOB_BRACE) ?: [] as $dev) {
            $out[] = self::reconnaitre(['port' => '/dev/' . basename($dev), 'vid' => null, 'pid' => null, 'fabricant' => '', 'produit' => '', 'serie' => '']);
        }
        return $out;
    }

    /** Windows : ports COM d'après le gestionnaire de périphériques (WMI). */
    private static function portsWindows(): array
    {
        $ps = "Get-CimInstance Win32_PnPEntity | Where-Object { \$_.Name -match '\\(COM[0-9]+\\)' } | "
            . "Select-Object Name, DeviceID, Manufacturer | ConvertTo-Json -Compress";
        $json = self::powershell($ps, 20);
        $l = json_decode((string)$json, true);
        if (!is_array($l)) return [];
        if (isset($l['Name'])) $l = [$l];
        $out = [];
        foreach ($l as $e) {
            if (!preg_match('/\((COM\d+)\)/', (string)($e['Name'] ?? ''), $m)) continue;
            $id = (string)($e['DeviceID'] ?? '');
            $vid = preg_match('/VID_([0-9A-F]{4})/i', $id, $v) ? strtolower($v[1]) : null;
            $pid = preg_match('/PID_([0-9A-F]{4})/i', $id, $q) ? strtolower($q[1]) : null;
            $out[] = ['port' => strtoupper($m[1]), 'vid' => $vid, 'pid' => $pid, 'fabricant' => (string)($e['Manufacturer'] ?? ''),
                      'produit' => preg_replace('/\s*\(COM\d+\)/', '', (string)$e['Name']), 'serie' => ''];
        }
        return $out;
    }

    private static function reconnaitre(array $p): array
    {
        $k = $p['vid'] && $p['pid'] ? "{$p['vid']}:{$p['pid']}" : '';
        $c = self::CONNUS[$k] ?? null;
        $modele = $c['modele'] ?? null;
        $libelle = $c['libelle'] ?? ($p['produit'] !== '' ? $p['produit'] : 'Port série');
        // Amber : un FTDI, reconnu à son nom de produit.
        if (!$modele && preg_match('/AMB8465|AMB8665|Amber/i', $p['produit'] . ' ' . $p['fabricant'])) {
            $modele = 'amb8465'; $libelle = 'Amber AMB8465-M (récepteur wM-Bus)';
        }
        if (!$modele && preg_match('/iM871A|IMST/i', $p['produit'] . ' ' . $p['fabricant'])) { $modele = 'im871a'; $libelle = 'IMST iM871A-USB (récepteur wM-Bus)'; }
        if (!$modele && preg_match('/culfw|CUL\d*/i', $p['produit'])) { $modele = 'cul'; $libelle = 'CUL / culfw (récepteur radio 868 MHz)'; }
        return $p + ['modele' => $modele, 'libelle' => $libelle,
                     'debit' => $modele ? self::DEBITS[$modele] : ($c['debit'] ?? null), 'conseil' => $c['conseil'] ?? null];
    }

    /** Le premier récepteur wM-Bus reconnu, ou null. */
    public static function recepteur(string $racine = ''): ?array
    {
        foreach (self::ports($racine) as $p) if ($p['modele']) return $p;
        return null;
    }

    /**
     * Écoute un port pendant $secondes, après avoir envoyé $init (réglage du
     * récepteur). Rend les octets reçus.
     * @param list<string> $init trames binaires à envoyer d'abord
     */
    public static function ecouter(string $port, int $debit, float $secondes, array $init = []): string
    {
        if (!self::portValide($port)) throw new RuntimeException("port « $port » refusé : un port série est attendu (/dev/ttyUSB0, /dev/ttyACM0, COM3…).");
        $debit = in_array($debit, [9600, 19200, 38400, 57600, 115200], true) ? $debit : 9600;
        $secondes = max(0.5, min(120.0, $secondes));
        return self::windows() ? self::ecouterWindows($port, $debit, $secondes, $init) : self::ecouterUnix($port, $debit, $secondes, $init);
    }

    private static function ecouterUnix(string $port, int $debit, float $secondes, array $init): string
    {
        if (!file_exists($port)) throw new RuntimeException("$port absent : la clé est-elle branchée ? (débranchée, elle peut changer de nom : choisissez « automatique »)");
        if (!is_readable($port) || !is_writable($port)) {
            throw new RuntimeException("accès refusé à $port : l'utilisateur du service doit appartenir au groupe « dialout » "
                . '(sudo usermod -aG dialout <utilisateur>, puis redémarrer le service).');
        }
        // Réglage du port : brut, 8 bits, sans parité ni contrôle de flux. stty absent ou
        // fonctions shell désactivées : on lit quand même, au réglage déjà en place.
        if (function_exists('exec')) {
            $opt = PHP_OS_FAMILY === 'Darwin' ? '-f' : '-F';
            @exec('stty ' . $opt . ' ' . escapeshellarg($port) . " $debit raw -echo -echoe -echok -ixon -ixoff -crtscts cs8 -cstopb -parenb clocal 2>&1", $sortie, $code);
        }
        $f = @fopen($port, 'r+b');
        if (!$f) throw new RuntimeException("$port ne s'ouvre pas (occupé par un autre programme ?)");
        stream_set_blocking($f, false);
        try {
            foreach ($init as $t) { @fwrite($f, $t); usleep(150000); }
            $buf = '';
            $fin = microtime(true) + $secondes;
            while (($reste = $fin - microtime(true)) > 0 && strlen($buf) < 2_000_000) {
                $r = [$f]; $w = $x = null;
                $n = @stream_select($r, $w, $x, 0, (int)(min($reste, 0.25) * 1_000_000));
                if ($n === false) break;
                if ($n > 0) { $d = @fread($f, 8192); if ($d !== false) $buf .= $d; }
            }
            return $buf;
        } finally {
            @fclose($f);
        }
    }

    private static function ecouterWindows(string $port, int $debit, float $secondes, array $init): string
    {
        $port = strtoupper($port);
        $envois = implode(';', array_map(fn($t) => '$b=[Convert]::FromBase64String(\'' . base64_encode($t) . '\');$p.Write($b,0,$b.Length);Start-Sleep -Milliseconds 150', $init));
        $ms = (int)round($secondes * 1000);
        $ps = "\$ErrorActionPreference='Stop';\$p=New-Object System.IO.Ports.SerialPort '$port',$debit,'None',8,'One';"
            . "\$p.ReadTimeout=200;\$p.DtrEnable=\$true;\$p.Open();$envois;\$m=New-Object System.IO.MemoryStream;"
            . "\$w=[Diagnostics.Stopwatch]::StartNew();while(\$w.ElapsedMilliseconds -lt $ms){\$n=\$p.BytesToRead;"
            . "if(\$n -gt 0){\$t=New-Object byte[] \$n;\$r=\$p.Read(\$t,0,\$n);\$m.Write(\$t,0,\$r)}else{Start-Sleep -Milliseconds 40}};"
            . "\$p.Close();'OK:'+[Convert]::ToBase64String(\$m.ToArray())";
        $s = self::powershell($ps, (int)ceil($secondes) + 20);
        if ($s === null) throw new RuntimeException('PowerShell indisponible : lecture du port série impossible sur ce Windows.');
        if (!preg_match('/OK:([A-Za-z0-9+\/=]*)/', $s, $m)) {
            $err = trim(preg_replace('/\s+/', ' ', $s) ?? '');
            if (stripos($err, 'denied') !== false || stripos($err, 'refus') !== false) throw new RuntimeException("$port est occupé par un autre programme.");
            if (stripos($err, 'does not exist') !== false || stripos($err, 'existe pas') !== false) throw new RuntimeException("$port absent : la clé est-elle branchée ?");
            throw new RuntimeException("lecture de $port impossible : " . mb_substr($err, 0, 200));
        }
        return (string)base64_decode($m[1]);
    }

    /** Lance un script PowerShell (Windows) ; rend sa sortie, ou null. */
    private static function powershell(string $script, int $delai): ?string
    {
        if (!function_exists('proc_open')) return null;
        $cmd = 'powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -EncodedCommand ' . base64_encode(mb_convert_encoding($script, 'UTF-16LE', 'UTF-8'));
        $p = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubes);
        if (!is_resource($p)) return null;
        $sortie = '';
        $fin = time() + $delai;
        stream_set_blocking($tubes[1], false);
        while (time() < $fin) {
            $d = fread($tubes[1], 65536);
            if ($d !== false && $d !== '') { $sortie .= $d; continue; }
            if (!proc_get_status($p)['running']) { $sortie .= (string)stream_get_contents($tubes[1]); break; }
            usleep(50000);
        }
        $sortie .= (string)@stream_get_contents($tubes[2]);
        @proc_terminate($p);
        @proc_close($p);
        return $sortie;
    }
}

/**
 * Codec wM-Bus : trames des récepteurs, sommes de contrôle, en-têtes,
 * déchiffrement, données M-Bus (DIF/VIF).
 */
final class ConnectWmbus
{
    /** Champs C d'une trame émise par un compteur (SND_NR, SND_IR, ACC_NR, RSP_UD…). */
    private const C_VALIDES = [0x44, 0x46, 0x47, 0x48, 0x06, 0x08, 0x18, 0x28, 0x38, 0x53, 0x73];
    public const TYPES = [0x00 => 'autre', 0x02 => 'électricité', 0x03 => 'gaz', 0x04 => 'chaleur', 0x06 => 'eau chaude', 0x07 => 'eau',
                          0x08 => 'répartiteur de frais de chauffage', 0x0A => 'froid', 0x0C => 'chaleur (entrée)', 0x0D => 'chaleur / froid',
                          0x15 => 'eau chaude', 0x16 => 'eau froide', 0x1A => 'détecteur de fumée', 0x1B => 'capteur d\'ambiance', 0x25 => 'compteur d\'eau'];
    public const MODES_RADIO = ['T1' => 'T1 (le plus courant : eau, chaleur)', 'C1' => 'C1 (compteurs récents, plus rapides)', 'S1' => 'S1 (émission rare, longue portée)'];

    // ══ Réglage des récepteurs ═════════════════════════════════════════════

    /**
     * Trames d'initialisation : le récepteur écoute le mode radio voulu.
     * (Codes tirés des notices IMST, Amber et culfw.)
     * @return list<string>
     */
    public static function initialisation(string $modele, string $mode): array
    {
        $mode = in_array($mode, ['T1', 'C1', 'S1'], true) ? $mode : 'T1';
        switch ($modele) {
            case 'im871a':
                // DEVMGMT (0x01) SET_CONFIG (0x03) : non mémorisé, IIFlag1 = mode de liaison, mode.
                $lien = ['S1' => 0x00, 'T1' => 0x03, 'C1' => 0x06][$mode];
                $corps = "\x00\x02" . chr($lien);
                return ["\xA5\x01\x03" . chr(strlen($corps)) . $corps];
            case 'amb8465':
                // CMD_SET_MODE_REQ (0x04) : mode « collecteur » ; somme = OU exclusif de la trame.
                $m = ['S1' => 0x03, 'T1' => 0x08, 'C1' => 0x0E][$mode];
                $t = "\xFF\x04\x01" . chr($m);
                return [$t . chr(self::xorOctets($t))];
            case 'cul':
                return ["X21\r\n", ['S1' => "brs\r\n", 'T1' => "brt\r\n", 'C1' => "brc\r\n"][$mode]];
        }
        return [];
    }

    private static function xorOctets(string $s): int
    {
        $x = 0;
        for ($i = 0, $n = strlen($s); $i < $n; $i++) $x ^= ord($s[$i]);
        return $x;
    }

    // ══ Extraction des télégrammes d'un flux brut ═════════════════════════

    /**
     * Télégrammes trouvés dans ce que le récepteur a envoyé, quel qu'il soit
     * (iM871A, Amber en mode commande ou transparent, CUL en texte).
     * @return list<array{trame: string, rssi: ?int}> trames commençant par L, sans CRC
     */
    public static function extraire(string $flux): array
    {
        $out = [];
        // CUL : des lignes de texte « b44…» (T/S) ou « bY44…» (C).
        if (preg_match_all('/^b(Y?)([0-9A-Fa-f]{22,600})\s*$/m', $flux, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $t = self::sansCrc((string)hex2bin(strlen($m[2]) % 2 ? substr($m[2], 0, -1) : $m[2]), $m[1] === 'Y');
                if ($t !== null) $out[] = ['trame' => $t, 'rssi' => null];
            }
            return $out;
        }
        $n = strlen($flux);
        $i = 0;
        while ($i < $n - 10) {
            $o = ord($flux[$i]);
            // iM871A : A5 | contrôle·point d'accès | message | longueur | charge [| horodatage | RSSI | CRC]
            if ($o === 0xA5 && $i + 4 <= $n) {
                $ctrl = ord($flux[$i + 1]) >> 4; $ep = ord($flux[$i + 1]) & 0x0F; $msg = ord($flux[$i + 2]); $len = ord($flux[$i + 3]);
                $plus = (($ctrl & 0x2) ? 4 : 0) + (($ctrl & 0x4) ? 1 : 0) + (($ctrl & 0x8) ? 2 : 0);
                if ($ep === 0x02 && $msg === 0x03 && $len >= 10 && $i + 4 + $len + $plus <= $n) {
                    $charge = substr($flux, $i + 4, $len);
                    $rssi = ($ctrl & 0x4) ? ord($flux[$i + 4 + $len + (($ctrl & 0x2) ? 4 : 0)]) : null;
                    $t = self::sansCrc(self::avecL($charge));
                    if ($t !== null) { $out[] = ['trame' => $t, 'rssi' => $rssi !== null ? self::rssiIm871a($rssi) : null]; $i += 4 + $len + $plus; continue; }
                }
                if ($ep <= 0x03 && $i + 4 + $len + $plus <= $n && $len < 0xF0) { $i += 4 + $len + $plus; continue; }  // réponse à une commande
            }
            // Amber en mode commande : FF 03 longueur charge [RSSI] somme
            if ($o === 0xFF && $i + 3 < $n && ord($flux[$i + 1]) === 0x03) {
                $len = ord($flux[$i + 2]);
                foreach ([1, 0] as $avecRssi) {
                    $fin = $i + 3 + $len + $avecRssi;
                    if ($fin >= $n || self::xorOctets(substr($flux, $i, $fin - $i)) !== ord($flux[$fin])) continue;
                    $charge = substr($flux, $i + 3, $len);
                    $t = self::sansCrc(self::avecL($charge));
                    if ($t !== null) {
                        $out[] = ['trame' => $t, 'rssi' => $avecRssi ? self::rssiAmber(ord($flux[$i + 3 + $len])) : null];
                        $i = $fin + 1;
                        continue 2;
                    }
                }
            }
            if ($o === 0xFF && $i + 2 < $n && in_array(ord($flux[$i + 1]), [0x84, 0x80, 0x89], true)) {   // réponse à une commande Amber
                $i += 4 + ord($flux[$i + 2]); continue;
            }
            // Télégramme brut (Amber en mode transparent) : L, C, fabricant…
            if (in_array(ord($flux[$i + 1]), self::C_VALIDES, true) && $o >= 10) {
                foreach ([self::longueurA($o), $o + 1] as $lg) {
                    if ($i + $lg > $n) continue;
                    $t = self::sansCrc(substr($flux, $i, $lg));
                    if ($t !== null && self::fabricantPlausible($t)) { $out[] = ['trame' => $t, 'rssi' => null]; $i += $lg; continue 2; }
                }
            }
            $i++;
        }
        return $out;
    }

    /** Charge sans champ L (iM871A, Amber) : on le remet. */
    private static function avecL(string $p): string
    {
        $n = strlen($p);
        if ($n >= 11 && ord($p[0]) === $n - 1 && in_array(ord($p[1]), self::C_VALIDES, true)) return $p;
        return chr($n) . $p;
    }

    private static function fabricantPlausible(string $t): bool
    {
        $m = unpack('v', substr($t, 2, 2))[1];
        return $m >= 0x0421 && $m <= 0x6B5A;                                  // trois lettres A…Z
    }

    /** RSSI approché, en dBm (conversions des notices ; l'ordre de grandeur suffit à juger une réception). */
    private static function rssiIm871a(int $r): int { return (int)min(0, round(-120 + $r / 2)); }
    private static function rssiAmber(int $r): int { $v = $r >= 128 ? ($r - 256) / 2 - 74 : $r / 2 - 74; return (int)round($v); }

    // ══ Sommes de contrôle (EN 13757-4) ═════════════════════════════════════

    public static function crc(string $s): int
    {
        $c = 0;
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $c ^= ord($s[$i]) << 8;
            for ($b = 0; $b < 8; $b++) $c = ($c & 0x8000) ? (($c << 1) ^ 0x3D65) & 0xFFFF : ($c << 1) & 0xFFFF;
        }
        return $c ^ 0xFFFF;
    }

    /** Longueur sur l'air d'une trame format A de champ L donné (avec ses CRC). */
    public static function longueurA(int $l): int
    {
        $donnees = $l + 1;
        return $donnees + 2 * (1 + (int)ceil(max(0, $donnees - 10) / 16));
    }

    /** Ajoute les CRC d'une trame format A (épreuves, simulateurs). */
    public static function avecCrcA(string $t): string
    {
        $out = substr($t, 0, 10);
        $out .= pack('n', self::crc($out));
        for ($i = 10, $n = strlen($t); $i < $n; $i += 16) { $b = substr($t, $i, 16); $out .= $b . pack('n', self::crc($b)); }
        return $out;
    }

    /**
     * Trame débarrassée de ses CRC, si elle en a (format A, ou B), et coupée à
     * sa longueur. null : trame incohérente (CRC faux, trop courte).
     */
    public static function sansCrc(string $t, bool $formatB = false): ?string
    {
        $n = strlen($t);
        if ($n < 11) return null;
        $l = ord($t[0]);
        // Format A : CRC après le premier bloc de 10 octets, puis tous les 16.
        $la = self::longueurA($l);
        // Premier bloc suivi de son CRC : c'est le format A, et tout bloc faux rend la trame inutilisable.
        $formatA = !$formatB && $n >= 12 && self::crc(substr($t, 0, 10)) === unpack('n', substr($t, 10, 2))[1];
        if ($formatA && $n < $la) return null;
        if ($formatA) {
            $out = ''; $ok = true;
            $blocs = [[0, 10]];
            for ($p = 12, $reste = $l + 1 - 10; $reste > 0; $p += min(16, $reste) + 2, $reste -= 16) $blocs[] = [$p, min(16, $reste)];
            foreach ($blocs as [$p, $lg]) {
                $b = substr($t, $p, $lg);
                if (strlen($b) !== $lg || self::crc($b) !== unpack('n', substr($t, $p + $lg, 2))[1]) { $ok = false; break; }
                $out .= $b;
            }
            return $ok ? $out : null;
        }
        // Format B : L compte les CRC ; un CRC à la fin du 2e bloc (octet 125) et à la fin.
        if ($n >= $l + 1 && $l >= 12) {
            $b = substr($t, 0, $l + 1);
            if ($l + 1 <= 128 && self::crc(substr($b, 0, -2)) === unpack('n', substr($b, -2))[1]) {
                $s = substr($b, 0, -2);
                $s[0] = chr($l - 2);
                return $s;
            }
            if ($l + 1 > 128 && self::crc(substr($b, 0, 126)) === unpack('n', substr($b, 126, 2))[1]
                && self::crc(substr($b, 128, -2)) === unpack('n', substr($b, -2))[1]) {
                $s = substr($b, 0, 126) . substr($b, 128, -2);
                $s[0] = chr($l - 4);
                return $s;
            }
        }
        // Récepteur qui a déjà retiré les CRC (iM871A, Amber) : on coupe à L.
        return $n >= $l + 1 ? substr($t, 0, $l + 1) : null;
    }

    // ══ Télégramme ═════════════════════════════════════════════════════════

    public static function fabricant(int $m): string
    {
        return chr((($m >> 10) & 0x1F) + 64) . chr((($m >> 5) & 0x1F) + 64) . chr(($m & 0x1F) + 64);
    }

    /**
     * Lit un télégramme (sans CRC). $cles : identifiant (8 chiffres) => clé AES binaire.
     * @return array{id: string, fabricant: string, version: int, type: int, type_libelle: string, ci: int, acces: ?int,
     *               etat: ?int, chiffre: bool, mode: int, champs: array, erreur: ?string}
     */
    public static function lire(string $t, array $cles = []): array
    {
        $m = unpack('v', substr($t, 2, 2))[1];
        $id = strtoupper(bin2hex(strrev(substr($t, 4, 4))));
        $r = ['id' => $id, 'fabricant' => self::fabricant($m), 'version' => ord($t[8]), 'type' => ord($t[9]),
              'type_libelle' => self::TYPES[ord($t[9])] ?? sprintf('type %02X', ord($t[9])), 'ci' => ord($t[10]), 'acces' => null, 'etat' => null,
              'chiffre' => false, 'mode' => 0, 'champs' => [], 'erreur' => null];
        $ci = ord($t[10]);
        $iv8 = substr($t, 2, 8);                                                // M + A (identifiant, version, type)
        switch ($ci) {
            case 0x7A:                                                          // en-tête court
                if (strlen($t) < 15) { $r['erreur'] = 'télégramme tronqué'; return $r; }
                $acc = ord($t[11]); $r['acces'] = $acc; $r['etat'] = ord($t[12]);
                $cfg = unpack('v', substr($t, 13, 2))[1];
                $donnees = substr($t, 15);
                break;
            case 0x72:                                                          // en-tête long (compteur derrière un relais)
                if (strlen($t) < 23) { $r['erreur'] = 'télégramme tronqué'; return $r; }
                $r['id'] = strtoupper(bin2hex(strrev(substr($t, 11, 4))));
                $r['fabricant'] = self::fabricant(unpack('v', substr($t, 15, 2))[1]);
                $r['version'] = ord($t[17]); $r['type'] = ord($t[18]);
                $r['type_libelle'] = self::TYPES[$r['type']] ?? sprintf('type %02X', $r['type']);
                $iv8 = substr($t, 15, 2) . substr($t, 11, 4) . substr($t, 17, 2);
                $acc = ord($t[19]); $r['acces'] = $acc; $r['etat'] = ord($t[20]);
                $cfg = unpack('v', substr($t, 21, 2))[1];
                $donnees = substr($t, 23);
                break;
            case 0x78:                                                          // sans en-tête, jamais chiffré
                $cfg = 0; $acc = 0;
                $donnees = substr($t, 11);
                break;
            default:
                $r['erreur'] = sprintf('format de télégramme non pris en charge (CI %02X)', $ci);
                return $r;
        }
        $mode = ($cfg >> 8) & 0x1F;
        $r['mode'] = $mode;
        if ($mode !== 0) {
            $r['chiffre'] = true;
            if ($mode !== 5) { $r['erreur'] = "chiffrement mode $mode non pris en charge (seul le mode 5, OMS, l'est)"; return $r; }
            $cle = $cles[$r['id']] ?? null;
            if ($cle === null) { $r['erreur'] = "télégramme chiffré : ajoutez la clé AES du compteur {$r['id']} (fournie avec le compteur ou par le distributeur)"; return $r; }
            $nb = ($cfg >> 4) & 0x0F;
            $lg = $nb ? min(16 * $nb, strlen($donnees) - strlen($donnees) % 16) : strlen($donnees) - strlen($donnees) % 16;
            $clair = openssl_decrypt(substr($donnees, 0, $lg), 'aes-128-cbc', $cle, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv8 . str_repeat(chr($acc), 8));
            if ($clair === false || substr($clair, 0, 2) !== "\x2F\x2F") { $r['erreur'] = "clé AES du compteur {$r['id']} incorrecte (le déchiffrement ne donne rien de lisible)"; return $r; }
            $donnees = $clair . substr($donnees, $lg);
        }
        $r['champs'] = self::donnees($donnees);
        return $r;
    }

    // ══ Données M-Bus (EN 13757-3) ═════════════════════════════════════════

    /** @return array<string, array{0: ?float, 1: string, 2?: string}> nom => [valeur, unité, texte] */
    public static function donnees(string $d): array
    {
        $out = [];
        $i = 0; $n = strlen($d);
        while ($i < $n) {
            $dif = ord($d[$i++]);
            if ($dif === 0x2F) continue;                                        // bourrage
            if ($dif === 0x0F || $dif === 0x1F) break;                          // données propres au fabricant
            $codage = $dif & 0x0F;
            $fonction = ($dif >> 4) & 0x03;
            $stockage = ($dif >> 6) & 0x01;
            $tarif = 0; $sousUnite = 0; $rang = 0;
            $ext = $dif & 0x80;
            while ($ext && $i < $n) {
                $dife = ord($d[$i++]);
                $stockage |= ($dife & 0x0F) << (1 + 4 * $rang);
                $tarif |= (($dife >> 4) & 0x03) << (2 * $rang);
                $sousUnite |= (($dife >> 6) & 0x01) << $rang;
                $rang++;
                $ext = $dife & 0x80;
            }
            if ($i >= $n) break;
            $vif = ord($d[$i++]);
            $table = '';
            if ($vif === 0xFD || $vif === 0xFB) { $table = $vif === 0xFD ? 'FD' : 'FB'; if ($i >= $n) break; $vif = ord($d[$i++]); }
            $vifes = $vif & 0x80;
            while ($vifes && $i < $n) $vifes = ord($d[$i++]) & 0x80;           // modificateurs : ignorés
            if ($vif === 0x7C || $vif === 0xFC) {                               // unité en clair : sautée
                if ($i >= $n) break;
                $i += 1 + ord($d[$i]);
            }
            $taille = [0 => 0, 1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 4, 6 => 6, 7 => 8, 8 => 0, 9 => 1, 10 => 2, 11 => 3, 12 => 4, 14 => 6][$codage] ?? null;
            $texte = null;
            if ($codage === 13) {                                               // longueur variable
                if ($i >= $n) break;
                $lv = ord($d[$i++]);
                if ($lv > 0xBF || $i + $lv > $n) break;
                $texte = strrev(substr($d, $i, $lv));
                $i += $lv;
                $brut = null;
            } else {
                if ($taille === null || $i + $taille > $n) break;
                $b = substr($d, $i, $taille);
                $i += $taille;
                $brut = self::valeurBrute($b, $codage);
            }
            [$nom, $unite, $echelle, $genre] = self::vif($vif & 0x7F, $table);
            if ($nom === null) continue;
            $valeur = null;
            if ($genre === 'date' && $brut !== null) {
                $texte = self::date($b, $codage === 4 ? 'F' : 'G');
            } elseif ($genre === 'nombre' && $brut !== null) {
                $valeur = round($brut * $echelle, 6);
            } elseif ($genre === 'drapeaux' && $brut !== null) {
                $valeur = (float)$brut;
            }
            $cle = $nom . ($fonction === 1 ? '_max' : ($fonction === 2 ? '_min' : ($fonction === 3 ? '_erreur' : '')))
                 . ($tarif ? "_tarif$tarif" : '') . ($sousUnite ? "_sous$sousUnite" : '') . ($stockage ? "_hist$stockage" : '');
            $k = $cle; $x = 2;
            while (isset($out[$k])) $k = $cle . '_' . $x++;
            $out[$k] = $texte !== null ? [$valeur, $unite, $texte] : [$valeur, $unite];
        }
        return $out;
    }

    private static function valeurBrute(string $b, int $codage): ?float
    {
        $n = strlen($b);
        if ($codage === 5) return (float)unpack('g', $b)[1];
        if ($codage >= 9) {                                                     // BCD (chiffre de poids fort en dernier)
            $h = strtoupper(bin2hex(strrev($b)));
            $neg = $h !== '' && $h[0] === 'F';
            if ($neg) $h = substr($h, 1);
            if (!preg_match('/^[0-9]*$/', $h)) return null;
            return ($neg ? -1 : 1) * (float)($h === '' ? 0 : $h);
        }
        if ($n === 0) return null;
        $v = 0;
        for ($k = $n - 1; $k >= 0; $k--) $v = $v * 256 + ord($b[$k]);
        if (ord($b[$n - 1]) & 0x80) $v -= 2 ** (8 * $n);                       // entiers signés
        return (float)$v;
    }

    /** Date M-Bus : G (2 octets) ou F (4 octets, avec l'heure). */
    private static function date(string $b, string $type): ?string
    {
        if ($type === 'G' && strlen($b) >= 2) {
            $j = ord($b[0]) & 0x1F; $m = ord($b[1]) & 0x0F; $a = 2000 + (((ord($b[0]) & 0xE0) >> 5) | ((ord($b[1]) & 0xF0) >> 1));
            return $j && $m ? sprintf('%04d-%02d-%02d', $a, $m, $j) : null;
        }
        if (strlen($b) >= 4) {
            $mi = ord($b[0]) & 0x3F; $h = ord($b[1]) & 0x1F; $j = ord($b[2]) & 0x1F; $m = ord($b[3]) & 0x0F;
            $a = 2000 + (((ord($b[2]) & 0xE0) >> 5) | ((ord($b[3]) & 0xF0) >> 1));
            return $j && $m ? sprintf('%04d-%02d-%02d %02d:%02d', $a, $m, $j, $h, $mi) : null;
        }
        return null;
    }

    /** VIF → [nom, unité, échelle, genre]. Unités de travail : m³, kWh, kW, m³/h, °C, K, bar. */
    private static function vif(int $v, string $table): array
    {
        if ($table === 'FD') {
            return match (true) {
                $v === 0x17 => ['erreurs', '', 1, 'drapeaux'],
                $v === 0x74 => ['pile_jours', 'j', 1, 'nombre'],
                $v === 0x3A => ['sans_unite', '', 1, 'nombre'],
                $v >= 0x48 && $v <= 0x4F => ['tension', 'V', 10 ** (($v & 0x0F) - 9), 'nombre'],
                default => [null, '', 1, ''],
            };
        }
        if ($table === 'FB') {
            return match (true) {
                $v <= 0x01 => ['energie', 'kWh', 10 ** (($v & 1) - 1) * 1000, 'nombre'],        // MWh
                $v >= 0x08 && $v <= 0x09 => ['energie', 'kWh', 10 ** (($v & 1) - 1) * 277.7778, 'nombre'], // GJ
                $v >= 0x10 && $v <= 0x11 => ['volume', 'm³', 10 ** (($v & 1) + 2), 'nombre'],
                default => [null, '', 1, ''],
            };
        }
        $n = $v & 0x07;
        return match (true) {
            $v <= 0x07 => ['energie', 'kWh', 10 ** ($n - 3) / 1000, 'nombre'],                  // Wh
            $v <= 0x0F => ['energie', 'kWh', 10 ** $n / 3_600_000, 'nombre'],                   // J
            $v <= 0x17 => ['volume', 'm³', 10 ** ($n - 6), 'nombre'],
            $v <= 0x1F => ['masse', 'kg', 10 ** ($n - 3), 'nombre'],
            $v <= 0x23 => ['duree_marche', self::uniteDuree($v & 3), 1, 'nombre'],
            $v <= 0x27 => ['duree_fonctionnement', self::uniteDuree($v & 3), 1, 'nombre'],
            $v <= 0x2F => ['puissance', 'kW', 10 ** ($n - 3) / 1000, 'nombre'],                 // W
            $v <= 0x37 => ['puissance', 'kW', 10 ** $n / 3_600_000, 'nombre'],                  // J/h
            $v <= 0x3F => ['debit', 'm³/h', 10 ** ($n - 6), 'nombre'],
            $v <= 0x47 => ['debit', 'm³/h', 10 ** ($n - 7) * 60, 'nombre'],                     // m³/min
            $v <= 0x4F => ['debit', 'm³/h', 10 ** ($n - 9) * 3600, 'nombre'],                   // m³/s
            $v <= 0x57 => ['debit_masse', 'kg/h', 10 ** ($n - 3), 'nombre'],
            $v <= 0x5B => ['temperature_depart', '°C', 10 ** (($v & 3) - 3), 'nombre'],
            $v <= 0x5F => ['temperature_retour', '°C', 10 ** (($v & 3) - 3), 'nombre'],
            $v <= 0x63 => ['ecart_temperature', 'K', 10 ** (($v & 3) - 3), 'nombre'],
            $v <= 0x67 => ['temperature_exterieure', '°C', 10 ** (($v & 3) - 3), 'nombre'],
            $v <= 0x6B => ['pression', 'bar', 10 ** (($v & 3) - 3), 'nombre'],
            $v === 0x6C => ['date', '', 1, 'date'],
            $v === 0x6D => ['date_heure', '', 1, 'date'],
            $v === 0x6E => ['repartiteur', 'unités', 1, 'nombre'],
            $v === 0x78 => ['numero_fabrication', '', 1, 'nombre'],
            default => [null, '', 1, ''],
        };
    }

    private static function uniteDuree(int $u): string
    {
        return ['s', 'min', 'h', 'j'][$u];
    }

    /** Clés AES saisies « identifiant=clé », séparées par des virgules, points-virgules ou retours à la ligne. */
    public static function cles(string $saisie): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', trim($saisie)) ?: [] as $e) {
            if (!preg_match('/^([0-9A-Fa-f]{8})[=:]([0-9A-Fa-f]{32})$/', $e, $m)) continue;
            $out[strtoupper($m[1])] = hex2bin($m[2]);
        }
        return $out;
    }
}

final class ConnectPiloteWmbus extends ConnectPiloteBase
{
    public static function description(): array
    {
        return [
            'protocole' => 'wmbus',
            'libelle' => 'Compteurs radio wM-Bus (clé USB)',
            'resume' => 'Compteurs d\'eau, de chaleur, de gaz qui émettent par radio (OMS, 868 MHz), entendus par une clé USB réceptrice.',
            'collecte' => 'releve',
            'port_defaut' => null,
            'intervalle_defaut' => 900,
            'hote' => null,
            'identifiant' => null,
            'champs' => [
                ['cle' => 'emplacement', 'libelle' => 'La clé USB est branchée sur', 'type' => 'choix', 'defaut' => 'serveur',
                 'options' => [['serveur', 'le serveur Larka'], ['pc', 'un PC releveur (près des compteurs)']],
                 'aide' => 'Sur un PC releveur : il écoute à chacun de ses passages et envoie les index à Larka.'],
                ['cle' => 'port', 'libelle' => 'Port série', 'type' => 'texte', 'defaut' => 'auto',
                 'aide' => '« auto » : la clé est trouvée toute seule (conseillé). Sinon /dev/ttyUSB0, /dev/ttyACM0, COM3…'],
                ['cle' => 'modele', 'libelle' => 'Récepteur', 'type' => 'choix', 'defaut' => 'auto',
                 'options' => array_merge([['auto', 'Reconnu automatiquement']], array_map(fn($k, $v) => [$k, $v], array_keys(ConnectSerie::MODELES), ConnectSerie::MODELES))],
                ['cle' => 'mode', 'libelle' => 'Mode radio', 'type' => 'choix', 'defaut' => 'T1',
                 'options' => array_map(fn($k, $v) => [$k, $v], array_keys(ConnectWmbus::MODES_RADIO), ConnectWmbus::MODES_RADIO)],
                ['cle' => 'ecoute_secondes', 'libelle' => 'Durée d\'écoute à chaque relevé (secondes)', 'type' => 'nombre', 'defaut' => 30,
                 'aide' => 'Au moins l\'intervalle d\'émission des compteurs (souvent 8 à 30 s ; voir leur notice). 5 à 45 s.'],
            ],
            'secrets' => [
                ['cle' => 'cles_aes', 'libelle' => 'Clés AES des compteurs chiffrés', 'type' => 'secret', 'max' => 8000,
                 'aide' => 'Identifiant=clé, séparés par des virgules : 12345678=000102030405060708090A0B0C0D0E0F, 87654321=… '
                         . 'Inutile pour un compteur qui émet en clair.'],
            ],
            'adresse' => [
                ['cle' => 'compteur', 'libelle' => 'Identifiant du compteur', 'type' => 'texte', 'requis' => true,
                 'aide' => '8 chiffres, souvent gravés sur le compteur (numéro de série). « Découvrir » liste les compteurs entendus.'],
                ['cle' => 'champ', 'libelle' => 'Donnée', 'type' => 'texte', 'defaut' => 'volume',
                 'aide' => 'volume, energie, puissance, debit, temperature_depart, temperature_retour, erreurs… ou rssi.'],
            ],
            'ecriture' => false,
            'decouverte' => true,
            'aide_decouverte' => 'Larka écoute la clé radio : chaque compteur entendu est proposé, avec ses données.',
        ];
    }

    /** Port et récepteur effectifs : [port, modèle, débit] ou exception parlante. */
    private function recepteur(): array
    {
        $port = trim((string)$this->param('port', 'auto'));
        $modele = (string)$this->param('modele', 'auto');
        $racine = '';
        if ($port === '' || strtolower($port) === 'auto') {
            $r = ConnectSerie::recepteur($racine);
            if (!$r) {
                $vus = array_map(fn($p) => "{$p['port']} ({$p['libelle']})", ConnectSerie::ports($racine));
                throw new RuntimeException('aucun récepteur wM-Bus branché' . ($vus ? ' — ports vus : ' . implode(', ', $vus)
                    . '. Si l\'un d\'eux est le récepteur, choisissez-le et indiquez son modèle.' : ' (aucun port série USB).'));
            }
            $port = $r['port'];
            if ($modele === 'auto') $modele = (string)$r['modele'];
        } elseif ($modele === 'auto') {
            foreach (ConnectSerie::ports($racine) as $p) if ($p['port'] === $port && $p['modele']) $modele = $p['modele'];
            if ($modele === 'auto') $modele = 'amb8465';                         // le moins exigeant : il émet aussi en clair
        }
        if (!isset(ConnectSerie::MODELES[$modele])) throw new RuntimeException("récepteur « $modele » inconnu");
        return [$port, $modele, ConnectSerie::DEBITS[$modele]];
    }

    /**
     * Écoute la radio ; rend les compteurs entendus (dernier télégramme de chacun).
     * @return array{port: string, modele: string, octets: int, compteurs: array<string, array>}
     */
    public function ecouter(?float $secondes = null): array
    {
        [$port, $modele, $debit] = $this->recepteur();
        $s = $secondes ?? max(5.0, min(45.0, (float)$this->param('ecoute_secondes', 30)));
        $flux = ConnectSerie::ecouter($port, $debit, $s, ConnectWmbus::initialisation($modele, (string)$this->param('mode', 'T1')));
        $cles = ConnectWmbus::cles($this->secretVal('cles_aes'));
        $compteurs = [];
        foreach (ConnectWmbus::extraire($flux) as $t) {
            $l = ConnectWmbus::lire($t['trame'], $cles);
            $l['rssi'] = $t['rssi'];
            $l['vu'] = ($compteurs[$l['id']]['vu'] ?? 0) + 1;
            // Un télégramme lisible l'emporte sur un précédent illisible, jamais l'inverse.
            if (isset($compteurs[$l['id']]) && $l['erreur'] && !$compteurs[$l['id']]['erreur']) { $compteurs[$l['id']]['vu'] = $l['vu']; continue; }
            $compteurs[$l['id']] = $l;
        }
        return ['port' => $port, 'modele' => $modele, 'octets' => strlen($flux), 'secondes' => $s, 'compteurs' => $compteurs];
    }

    public function lire(array $points): array
    {
        $out = [];
        try {
            $e = $this->ecouter();
        } catch (\Throwable $ex) {
            foreach ($points as $p) $out[(int)$p['Id']] = self::enDefaut($ex->getMessage());
            return $out;
        }
        foreach ($points as $p) {
            $a = is_array($p['Adresse'] ?? null) ? $p['Adresse'] : [];
            $id = strtoupper(trim((string)($a['compteur'] ?? '')));
            $champ = trim((string)($a['champ'] ?? 'volume')) ?: 'volume';
            $c = $e['compteurs'][$id] ?? null;
            if (!$c) { $out[(int)$p['Id']] = self::enDefaut("compteur $id non entendu pendant {$e['secondes']} s (hors de portée, ou émet moins souvent ?)", 'douteuse'); continue; }
            if ($champ === 'rssi') { $out[(int)$p['Id']] = $c['rssi'] !== null ? self::valeurLue($c['rssi']) : self::enDefaut('RSSI non fourni par ce récepteur'); continue; }
            if ($c['erreur']) { $out[(int)$p['Id']] = self::enDefaut($c['erreur']); continue; }
            $v = $c['champs'][$champ] ?? null;
            if (!$v) { $out[(int)$p['Id']] = self::enDefaut("donnée « $champ » absente du télégramme (présentes : " . implode(', ', array_slice(array_keys($c['champs']), 0, 10)) . ')'); continue; }
            $out[(int)$p['Id']] = self::valeurLue($v[0], $v[2] ?? null);
        }
        return $out;
    }

    public function tester(array $points = []): array
    {
        $t0 = microtime(true);
        try {
            [$port, $modele] = $this->recepteur();
        } catch (\Throwable $e) {
            return [self::etape('reseau', false, ucfirst($e->getMessage()) . '.')];
        }
        $etapes = [self::etape('reseau', true, ConnectSerie::MODELES[$modele] . " sur $port.")];
        try {
            $e = $this->ecouter(min(15.0, max(5.0, (float)$this->param('ecoute_secondes', 30))));
        } catch (\Throwable $ex) {
            $etapes[] = self::etape('droits', false, ucfirst($ex->getMessage()) . '.', self::ms($t0));
            return $etapes;
        }
        if (!$e['compteurs']) {
            $etapes[] = self::etape('protocole', false, $e['octets']
                ? "La clé répond ({$e['octets']} octets en {$e['secondes']} s), mais aucun télégramme de compteur reconnu : essayez l'autre mode radio (T1 / C1), ou un autre modèle de récepteur."
                : "Rien reçu en {$e['secondes']} s : compteurs hors de portée, autre mode radio (T1 / C1), ou récepteur d'un autre modèle que celui choisi.", self::ms($t0));
            return $etapes;
        }
        $lignes = array_map(fn($c) => "{$c['id']} ({$c['fabricant']}, {$c['type_libelle']}" . ($c['rssi'] !== null ? ", {$c['rssi']} dBm" : '') . ')'
                                      . ($c['erreur'] ? ' ⚠ ' . $c['erreur'] : ''), array_values($e['compteurs']));
        $lisibles = array_filter($e['compteurs'], fn($c) => !$c['erreur']);
        $etapes[] = self::etape('protocole', true, count($e['compteurs']) . " compteur(s) entendu(s) en {$e['secondes']} s : " . implode(' ; ', array_slice($lignes, 0, 8)) . '.', self::ms($t0));
        if (count($lisibles) < count($e['compteurs'])) $etapes[] = self::etape('droits', null, (count($e['compteurs']) - count($lisibles)) . ' compteur(s) illisible(s) : voir ci-dessus (clé AES le plus souvent).');
        if ($points) {
            $lus = $this->lire($points);
            $ok = count(array_filter($lus, fn($l) => ($l['valeur'] ?? null) !== null || ($l['texte'] ?? null) !== null));
            $etapes[] = self::etape('points', $ok === count($points) ? true : ($ok ? null : false), "$ok / " . count($points) . ' point(s) lu(s).');
        }
        return $etapes;
    }

    public function decouvrir(): array
    {
        $e = $this->ecouter();
        $out = [];
        foreach ($e['compteurs'] as $c) {
            $prefixe = ucfirst($c['type_libelle']) . " {$c['id']}";
            if ($c['erreur']) {
                $out[] = ['nom' => "$prefixe — " . $c['erreur'], 'code' => "{$c['id']}.volume", 'adresse' => ['compteur' => $c['id'], 'champ' => 'volume'],
                          'unite' => 'm³', 'nature' => 'compteur', 'valeur' => null];
                continue;
            }
            foreach ($c['champs'] as $nom => $v) {
                if (str_contains($nom, '_hist') && !str_starts_with($nom, 'volume') && !str_starts_with($nom, 'energie')) continue;
                $nature = preg_match('/^(volume|energie|masse|repartiteur)/', $nom) ? 'compteur' : ($nom === 'erreurs' ? 'etat' : 'mesure');
                $out[] = ['nom' => "$prefixe — " . str_replace('_', ' ', $nom), 'code' => "{$c['id']}.$nom",
                          'adresse' => ['compteur' => $c['id'], 'champ' => $nom], 'unite' => (string)$v[1], 'nature' => $nature,
                          'valeur' => $v[0] ?? ($v[2] ?? null)];
            }
            if ($c['rssi'] !== null) {
                $out[] = ['nom' => "$prefixe — réception radio", 'code' => "{$c['id']}.rssi", 'adresse' => ['compteur' => $c['id'], 'champ' => 'rssi'],
                          'unite' => 'dBm', 'nature' => 'mesure', 'valeur' => $c['rssi']];
            }
        }
        return $out;
    }
}

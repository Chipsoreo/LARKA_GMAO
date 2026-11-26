<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka Connect — simulateur radio, sans matériel ni bibliothèque.
 *
 *   php outils/simulateurs/radio.php lorawan [--larka=127.0.0.1:1700] [--intervalle=20]
 *       Une passerelle LoRaWAN (packet forwarder Semtech) et deux capteurs :
 *         « Sonde salle »  OTAA, DevEUI 70B3D57ED0000001, AppKey ci-dessous, Cayenne LPP
 *                          (température, humidité, CO₂) ;
 *         « Compteur »     ABP, DevAddr 26011B01, octets bruts (index uint32 à l'octet 0).
 *       Le capteur OTAA fait son join, attend la réponse de Larka, puis émet.
 *
 *   php outils/simulateurs/radio.php wmbus [--duree=600]
 *       Une clé USB iM871A simulée (pseudo-terminal, Linux et macOS : python3) qui
 *       répète le télégramme d'un compteur de chaleur en clair, index croissant.
 *       Affiche le port à saisir dans Larka (/dev/pts/N, modèle « IMST iM871A »).
 *
 * À déclarer dans Larka (Connexions → Nouvelle connexion), voir README.md.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
$racine = dirname(__DIR__, 2);
if (!function_exists('cfg')) { function cfg(string ...$k) { return null; } }
require "$racine/api/connect/Pilote.php";
ConnectPilotes::charger();

const OTAA_EUI = '70B3D57ED0000001';
const OTAA_APPKEY = '2B7E151628AED2A6ABF7158809CF4F3C';
const ABP_EUI = '70B3D57ED0000002';
const ABP_ADDR = '26011B01';
const ABP_NWK = '000102030405060708090A0B0C0D0E0F';
const ABP_APP = '0F0E0D0C0B0A09080706050403020100';
const GW_EUI = 'AA555A00000000AA';

$opt = [];
foreach (array_slice($argv, 2) as $a) if (preg_match('/^--([a-z]+)=(.*)$/', $a, $m)) $opt[$m[1]] = $m[2];
$quoi = $argv[1] ?? '';

if ($quoi === 'lorawan') {
    $cible = $opt['larka'] ?? '127.0.0.1:1700';
    $pas = max(5, (int)($opt['intervalle'] ?? 20));
    $push = stream_socket_client("udp://$cible", $en, $es);
    $pull = stream_socket_client("udp://$cible", $en, $es);
    if (!$push || !$pull) { fwrite(STDERR, "UDP impossible vers $cible : $es\n"); exit(1); }
    stream_set_blocking($pull, false);
    echo "Passerelle " . GW_EUI . " → udp://$cible\n";
    echo "  Capteur OTAA : DevEUI " . OTAA_EUI . ", AppKey " . OTAA_APPKEY . ", décodeur Cayenne LPP\n";
    echo "  Capteur ABP  : DevEUI " . ABP_EUI . ", DevAddr " . ABP_ADDR . ", NwkSKey " . ABP_NWK . ", AppSKey " . ABP_APP . ", octets bruts\n\n";
    $tmst = 0; $t0 = microtime(true);
    $horloge = function () use ($t0) { return (int)((microtime(true) - $t0) * 1e6) & 0xFFFFFFFF; };
    $envoyer = function (string $trame, int $rssi = -85) use ($push, $horloge) {
        $rx = ['tmst' => $horloge(), 'freq' => 868.1, 'chan' => 0, 'rfch' => 0, 'stat' => 1, 'modu' => 'LORA', 'datr' => 'SF7BW125',
               'codr' => '4/5', 'rssi' => $rssi + random_int(-4, 4), 'lsnr' => 7.5, 'size' => strlen($trame), 'data' => base64_encode($trame)];
        fwrite($push, "\x02" . random_bytes(2) . "\x00" . hex2bin(GW_EUI) . json_encode(['rxpk' => [$rx]]));
    };
    $session = null; $fcntO = 0; $fcntA = (int)(time() % 1000); $index = 100000; $prochainPull = 0; $prochain = 0; $joinA = 0;
    while (true) {
        $t = microtime(true);
        if ($t >= $prochainPull) { fwrite($pull, "\x02" . random_bytes(2) . "\x02" . hex2bin(GW_EUI)); $prochainPull = $t + 10; }
        while (($p = @fread($pull, 65535)) !== false && $p !== '') {
            if (strlen($p) < 4 || ord($p[3]) !== 0x03) continue;                 // PULL_RESP : une trame à émettre
            $tx = json_decode(substr($p, 4), true)['txpk'] ?? null;
            $b = $tx ? base64_decode((string)$tx['data']) : '';
            if ($b === '' || ord($b[0]) !== 0x20 || !isset($nonce)) { if ($b !== '') echo "  ← accusé de réception (trame confirmée)\n"; continue; }
            $clair = openssl_encrypt(substr($b, 1), 'aes-128-ecb', hex2bin(OTAA_APPKEY), OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
            $addr = strtoupper(bin2hex(strrev(substr($clair, 6, 4))));
            $session = ConnectLorawan::clesSession(hex2bin(OTAA_APPKEY), substr($clair, 0, 3), substr($clair, 3, 3), $nonce) + ['addr' => $addr];
            $fcntO = 0;
            echo "  ← join accepté par Larka : DevAddr $addr\n";
        }
        if ($t >= $prochain) {
            $prochain = $t + $pas;
            if (!$session && $t >= $joinA) {
                $nonce = random_bytes(2);
                $jr = "\x00" . str_repeat("\0", 8) . strrev(hex2bin(OTAA_EUI)) . $nonce;
                $envoyer($jr . substr(ConnectLorawan::cmac(hex2bin(OTAA_APPKEY), $jr), 0, 4), -92);
                echo date('H:i:s') . " → demande de join (OTAA)\n";
                $joinA = $t + 15;
            }
            if ($session) {
                $h = (int)date('G');
                $co2 = (int)(450 + (($h >= 8 && $h <= 18) ? 700 : 0) * (0.6 + 0.4 * sin($t / 300)) + random_int(-20, 20));
                $temp = (int)round((20.5 + sin($t / 900)) * 10);
                $lpp = "\x01\x67" . pack('n', $temp & 0xFFFF) . "\x02\x68" . chr(80 + random_int(0, 10)) . "\x03\x7D" . pack('n', $co2);
                $le = strrev(hex2bin($session['addr']));
                $msg = "\x40" . $le . "\x00" . pack('v', ++$fcntO & 0xFFFF) . "\x01" . ConnectLorawan::chiffrerCharge($session['app'], $le, $fcntO, 0, $lpp);
                $envoyer($msg . ConnectLorawan::micDonnees($session['nwk'], $le, $fcntO, 0, $msg));
                printf("%s → sonde : %.1f °C, %d ppm (trame %d)\n", date('H:i:s'), $temp / 10, $co2, $fcntO);
            }
            $index += random_int(1, 9);
            $le = strrev(hex2bin(ABP_ADDR));
            $msg = "\x40" . $le . "\x00" . pack('v', ++$fcntA & 0xFFFF) . "\x05" . ConnectLorawan::chiffrerCharge(hex2bin(ABP_APP), $le, $fcntA, 0, pack('N', $index));
            $envoyer($msg . ConnectLorawan::micDonnees(hex2bin(ABP_NWK), $le, $fcntA, 0, $msg), -105);
            printf("%s → compteur : index %d (trame %d)\n", date('H:i:s'), $index, $fcntA);
        }
        usleep(200000);
    }
}

if ($quoi === 'wmbus') {
    $py = trim((string)shell_exec('command -v python3 2>/dev/null'));
    if ($py === '' || PHP_OS_FAMILY === 'Windows') { fwrite(STDERR, "python3 (Linux, macOS) est nécessaire pour simuler la clé USB.\n"); exit(1); }
    $duree = max(30, (int)($opt['duree'] ?? 600));
    // Télégrammes successifs : index de chaleur croissant, préparés ici, répétés par la « clé ».
    $fab = pack('v', ((ord('L') - 64) << 10) | ((ord('A') - 64) << 5) | (ord('R') - 64));
    $trames = [];
    for ($i = 0, $e = 45210, $v = 1234.56; $i < 200; $i++, $e += random_int(1, 3), $v += 0.05) {
        $bcd = fn(int $n, int $o) => strrev(hex2bin(str_pad((string)$n, 2 * $o, '0', STR_PAD_LEFT)));
        $t = "\x44" . $fab . "\x21\x43\x65\x87\x01\x04\x7A" . chr($i & 0xFF) . "\x00\x00\x00"
           . "\x0C\x06" . $bcd($e, 4) . "\x0C\x14" . $bcd((int)round($v * 100), 4)
           . "\x0A\x5A" . $bcd(700 + random_int(-15, 15), 2) . "\x0A\x5E" . $bcd(450 + random_int(-10, 10), 2) . "\x02\xFD\x17\x00\x00";
        $trames[] = bin2hex("\xA5\x42\x03" . chr(strlen($t)) . $t . chr(140 + random_int(0, 30)));
    }
    $script = <<<'PY'
import os, pty, sys, time, select, binascii, tty
m, s = pty.openpty(); tty.setraw(s)
print(os.ttyname(s), flush=True)
trames = [binascii.unhexlify(x) for x in sys.argv[1].split(',')]
fin = time.time() + float(sys.argv[2]); i = 0; prochain = 0
while time.time() < fin:
    r, _, _ = select.select([m], [], [], 0.1)
    if r:
        try: os.read(m, 4096)
        except OSError: pass
    if time.time() >= prochain:
        os.write(m, trames[i % len(trames)]); i += 1; prochain = time.time() + 4
PY;
    $f = tempnam(sys_get_temp_dir(), 'cle') . '.py';
    file_put_contents($f, $script);
    $p = proc_open([$py, '-I', $f, implode(',', $trames), (string)$duree], [1 => ['pipe', 'w']], $tubes);
    $pts = trim((string)fgets($tubes[1]));
    echo "Clé iM871A simulée sur $pts pendant $duree s — compteur de chaleur 87654321 (fabricant LAR), un télégramme toutes les 4 s.\n";
    echo "Dans Larka : Compteurs radio wM-Bus, branchée sur le serveur, port $pts, récepteur IMST iM871A, mode T1.\n";
    proc_close($p);
    @unlink($f);
    exit(0);
}

fwrite(STDERR, "Usage : php outils/simulateurs/radio.php lorawan [--larka=hote:1700] [--intervalle=20]\n"
             . "        php outils/simulateurs/radio.php wmbus [--duree=600]\n");
exit(1);

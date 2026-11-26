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
 *  Larka Connect — LoRaWAN sans serveur réseau extérieur
 * ═══════════════════════════════════════════════════════════════════════════
 *
 *  Jusqu'ici, un capteur LoRaWAN n'arrivait dans Larka que par MQTT : il
 *  fallait un serveur réseau (ChirpStack, TTN, Live Objects…) et un broker.
 *  Ce fichier rend ce détour facultatif. Les PASSERELLES LoRaWAN du bâtiment
 *  envoient directement à Larka, avec le protocole que presque toutes savent
 *  parler — le « Semtech UDP packet forwarder » (port 1700) — et Larka fait
 *  lui-même ce que faisait le serveur réseau :
 *
 *    - vérifie l'intégrité de chaque trame (MIC, AES-CMAC) ;
 *    - écarte les rejeux (compteur de trames) et les doublons reçus par
 *      plusieurs passerelles ;
 *    - accepte l'activation OTAA (join) et répond par la passerelle ;
 *    - déchiffre la charge utile (AES-128) ;
 *    - la décode : Cayenne LPP, Elsys, Milesight, ou des octets choisis.
 *
 *  Région EU868 (France), LoRaWAN 1.0.x, classe A. Aucune bibliothèque :
 *  AES par OpenSSL (extension requise de Larka), le reste en PHP.
 *
 *  CE FICHIER CONTIENT
 *    ConnectLorawan        le codec : trames, cryptographie, décodeurs ;
 *    ConnectPiloteLorawan  le pilote : description, test, découverte.
 *  Le service d'écoute (UDP) est dans api/connect/LorawanServeur.php ; il
 *  tourne dans le même processus que le service OCPP.
 *
 *  Ce qui n'est PAS fait : classes B et C, LoRaWAN 1.1, commandes MAC (ADR),
 *  envoi de données vers un capteur. Un capteur qui envoie des trames
 *  « confirmées » reçoit son accusé de réception.
 * ═══════════════════════════════════════════════════════════════════════════
 */

declare(strict_types=1);

final class ConnectLorawan
{
    public const MTYPE_JOIN_REQUEST = 0;
    public const MTYPE_JOIN_ACCEPT = 1;
    public const MTYPE_UNCONFIRMED_UP = 2;
    public const MTYPE_UNCONFIRMED_DOWN = 3;
    public const MTYPE_CONFIRMED_UP = 4;

    /** Décodeurs intégrés : clé → libellé. Liste close, comme tout le reste. */
    public const DECODEURS = [
        'lpp'       => 'Cayenne LPP (format générique, très répandu)',
        'elsys'     => 'Elsys (ERS, ERS CO2, ELT…)',
        'milesight' => 'Milesight (AM, EM, WS…)',
        'octets'    => 'Octets bruts (positions choisies point par point)',
    ];

    // ══ Cryptographie ══════════════════════════════════════════════════════

    public static function aes(string $cle, string $bloc): string
    {
        return (string)openssl_encrypt($bloc, 'aes-128-ecb', $cle, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
    }

    /** « Déchiffrement » AES : c'est l'opération qui CHIFFRE un join-accept (spécification LoRaWAN). */
    public static function aesInverse(string $cle, string $donnees): string
    {
        return (string)openssl_decrypt($donnees, 'aes-128-ecb', $cle, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
    }

    /** AES-CMAC (RFC 4493). */
    public static function cmac(string $cle, string $m): string
    {
        $L = self::aes($cle, str_repeat("\0", 16));
        $decaler = static function (string $b): array {
            $o = ''; $r = 0;
            for ($i = 15; $i >= 0; $i--) { $v = ord($b[$i]); $o = chr((($v << 1) & 0xFF) | $r) . $o; $r = ($v >> 7) & 1; }
            return [$o, $r];
        };
        [$k1, $r] = $decaler($L); if ($r) $k1[15] = chr(ord($k1[15]) ^ 0x87);
        [$k2, $r] = $decaler($k1); if ($r) $k2[15] = chr(ord($k2[15]) ^ 0x87);
        $n = max(1, (int)ceil(strlen($m) / 16));
        $complet = $m !== '' && strlen($m) % 16 === 0;
        $dernier = substr($m, ($n - 1) * 16);
        $dernier = $complet ? ($dernier ^ $k1) : (str_pad($dernier . "\x80", 16, "\0") ^ $k2);
        $x = str_repeat("\0", 16);
        for ($i = 0; $i < $n - 1; $i++) $x = self::aes($cle, $x ^ substr($m, $i * 16, 16));
        return self::aes($cle, $x ^ $dernier);
    }

    /** MIC d'une trame de données (B0 + message). $sens : 0 montant, 1 descendant. */
    public static function micDonnees(string $nwkSKey, string $devAddrLe, int $fcnt, int $sens, string $msg): string
    {
        $b0 = "\x49\0\0\0\0" . chr($sens) . $devAddrLe . pack('V', $fcnt & 0xFFFFFFFF) . "\0" . chr(strlen($msg));
        return substr(self::cmac($nwkSKey, $b0 . $msg), 0, 4);
    }

    /** Chiffre ou déchiffre une charge utile (même opération dans les deux sens). */
    public static function chiffrerCharge(string $cle, string $devAddrLe, int $fcnt, int $sens, string $charge): string
    {
        $s = '';
        for ($i = 1, $n = (int)ceil(strlen($charge) / 16); $i <= $n; $i++) {
            $s .= self::aes($cle, "\x01\0\0\0\0" . chr($sens) . $devAddrLe . pack('V', $fcnt & 0xFFFFFFFF) . "\0" . chr($i));
        }
        return $charge ^ substr($s, 0, strlen($charge));
    }

    /** Clés de session d'une activation OTAA (LoRaWAN 1.0.x). @return array{nwk: string, app: string} */
    public static function clesSession(string $appKey, string $appNonce3, string $netId3, string $devNonce2): array
    {
        $fin = $appNonce3 . $netId3 . $devNonce2 . str_repeat("\0", 7);
        return ['nwk' => self::aes($appKey, "\x01" . $fin), 'app' => self::aes($appKey, "\x02" . $fin)];
    }

    /** Trame join-accept, chiffrée, prête à émettre. */
    public static function joinAccept(string $appKey, string $appNonce3, string $netId3, string $devAddrLe,
                                      int $dlSettings = 0, int $rxDelay = 1): string
    {
        $mhdr = "\x20";
        $corps = $appNonce3 . $netId3 . $devAddrLe . chr($dlSettings & 0xFF) . chr($rxDelay & 0xFF);
        $mic = substr(self::cmac($appKey, $mhdr . $corps), 0, 4);
        return $mhdr . self::aesInverse($appKey, $corps . $mic);
    }

    /** Accusé de réception d'une trame confirmée (descendante, sans charge). */
    public static function accuse(string $nwkSKey, string $devAddrLe, int $fcntDown): string
    {
        $msg = "\x60" . $devAddrLe . "\x20" . pack('v', $fcntDown & 0xFFFF);
        return $msg . self::micDonnees($nwkSKey, $devAddrLe, $fcntDown, 1, $msg);
    }

    // ══ Trames ═════════════════════════════════════════════════════════════

    /**
     * Analyse une trame LoRaWAN (PHYPayload). Ne vérifie rien : le MIC exige les
     * clés, que l'appelant cherche d'après DevEUI ou DevAddr.
     * @return array|null null : trame illisible
     */
    public static function analyser(string $t): ?array
    {
        $n = strlen($t);
        if ($n < 12) return null;
        $mtype = (ord($t[0]) >> 5) & 0x07;
        if ($mtype === self::MTYPE_JOIN_REQUEST) {
            if ($n !== 23) return null;
            return ['mtype' => $mtype, 'join_eui' => strtoupper(bin2hex(strrev(substr($t, 1, 8)))),
                    'dev_eui' => strtoupper(bin2hex(strrev(substr($t, 9, 8)))), 'dev_nonce' => substr($t, 17, 2),
                    'mic' => substr($t, 19, 4), 'msg' => substr($t, 0, 19)];
        }
        if ($mtype !== self::MTYPE_UNCONFIRMED_UP && $mtype !== self::MTYPE_CONFIRMED_UP) return ['mtype' => $mtype];
        $fctrl = ord($t[5]);
        $foptsLen = $fctrl & 0x0F;
        $pos = 8 + $foptsLen;
        if ($n < $pos + 4) return null;
        $fport = null; $charge = '';
        if ($n > $pos + 4) {
            $fport = ord($t[$pos]);
            $charge = substr($t, $pos + 1, $n - $pos - 1 - 4);
        }
        return ['mtype' => $mtype, 'dev_addr_le' => substr($t, 1, 4), 'dev_addr' => strtoupper(bin2hex(strrev(substr($t, 1, 4)))),
                'fctrl' => $fctrl, 'adr' => (bool)($fctrl & 0x80), 'ack' => (bool)($fctrl & 0x20),
                'fcnt16' => unpack('v', substr($t, 6, 2))[1], 'fopts' => substr($t, 8, $foptsLen),
                'fport' => $fport, 'charge' => $charge, 'mic' => substr($t, -4), 'msg' => substr($t, 0, -4),
                'confirmee' => $mtype === self::MTYPE_CONFIRMED_UP];
    }

    /** Compteur 32 bits reconstitué d'après les 16 bits reçus et le dernier connu. */
    public static function fcnt32(int $fcnt16, ?int $dernier): int
    {
        if ($dernier === null) return $fcnt16;
        $c = ($dernier & ~0xFFFF) | $fcnt16;
        if ($c < $dernier && ($dernier - $c) > 0x8000) $c += 0x10000;
        return $c;
    }

    public static function hex(string $s, int $octets): ?string
    {
        $s = strtoupper(preg_replace('/[\s:\-]/', '', $s) ?? '');
        return preg_match('/^[0-9A-F]{' . (2 * $octets) . '}$/', $s) ? $s : null;
    }

    // ══ Semtech UDP packet forwarder ═══════════════════════════════════════

    /** Paquet UDP d'une passerelle : [version, jeton, type, eui, json] ou null. */
    public static function paquetUdp(string $p): ?array
    {
        if (strlen($p) < 4 || !in_array(ord($p[0]), [1, 2], true)) return null;
        $type = ord($p[3]);
        $eui = in_array($type, [0x00, 0x02, 0x05], true) && strlen($p) >= 12 ? strtoupper(bin2hex(substr($p, 4, 8))) : null;
        $json = null;
        if (($type === 0x00 || $type === 0x05) && strlen($p) > 12) $json = json_decode(substr($p, 12), true);
        return ['version' => ord($p[0]), 'jeton' => substr($p, 1, 2), 'type' => $type, 'eui' => $eui, 'json' => is_array($json) ? $json : null];
    }

    /** Ordre d'émission (PULL_RESP) pour la passerelle. */
    public static function pullResp(int $version, string $trame, int $tmst, float $freq, string $datr, int $puissance = 14): string
    {
        $txpk = ['imme' => false, 'tmst' => $tmst & 0xFFFFFFFF, 'freq' => round($freq, 6), 'rfch' => 0, 'powe' => $puissance,
                 'modu' => 'LORA', 'datr' => $datr, 'codr' => '4/5', 'ipol' => true, 'size' => strlen($trame),
                 'data' => base64_encode($trame)];
        return chr($version) . random_bytes(2) . "\x03" . json_encode(['txpk' => $txpk], JSON_UNESCAPED_SLASHES);
    }

    // ══ Décodeurs ══════════════════════════════════════════════════════════
    //
    // Chacun rend [nom => [valeur, unité]]. Une charge mal formée s'arrête au
    // premier octet incompris : ce qui précède reste lu, rien n'est inventé.

    public static function decoder(string $decodeur, string $charge): array
    {
        return match ($decodeur) {
            'lpp'       => self::lpp($charge),
            'elsys'     => self::elsys($charge),
            'milesight' => self::milesight($charge),
            default     => [],
        };
    }

    private static function s16(string $b): int { $v = unpack('n', $b)[1]; return $v >= 0x8000 ? $v - 0x10000 : $v; }
    private static function u16(string $b): int { return unpack('n', $b)[1]; }
    private static function s16le(string $b): int { $v = unpack('v', $b)[1]; return $v >= 0x8000 ? $v - 0x10000 : $v; }
    private static function u16le(string $b): int { return unpack('v', $b)[1]; }
    private static function s24(string $b): int { $v = (ord($b[0]) << 16) | (ord($b[1]) << 8) | ord($b[2]); return $v >= 0x800000 ? $v - 0x1000000 : $v; }

    /** Cayenne LPP : canal, type, valeur. Les noms portent le canal (temperature_1…). */
    public static function lpp(string $c): array
    {
        // type => [nom, octets, signé, diviseur, unité]
        $T = [0 => ['entree_tor', 1, false, 1, ''], 1 => ['sortie_tor', 1, false, 1, ''], 2 => ['entree_analogique', 2, true, 100, ''],
              3 => ['sortie_analogique', 2, true, 100, ''], 101 => ['luminosite', 2, false, 1, 'lx'], 102 => ['presence', 1, false, 1, ''],
              103 => ['temperature', 2, true, 10, '°C'], 104 => ['humidite', 1, false, 2, '%'], 115 => ['pression', 2, false, 10, 'hPa'],
              116 => ['tension', 2, false, 100, 'V'], 117 => ['courant', 2, false, 1000, 'A'], 120 => ['pourcentage', 1, false, 1, '%'],
              125 => ['concentration', 2, false, 1, 'ppm'], 128 => ['puissance', 2, false, 1, 'W'], 130 => ['distance', 4, false, 1000, 'm'],
              131 => ['energie', 4, false, 1000, 'kWh'], 142 => ['interrupteur', 1, false, 1, '']];
        $out = [];
        $i = 0; $n = strlen($c);
        while ($i + 2 <= $n) {
            $canal = ord($c[$i]); $type = ord($c[$i + 1]); $i += 2;
            if ($type === 113) {                                                // accéléromètre : 3 × 2 octets
                if ($i + 6 > $n) break;
                foreach (['x', 'y', 'z'] as $k => $axe) $out["acceleration_{$axe}_$canal"] = [self::s16(substr($c, $i + 2 * $k, 2)) / 1000, 'G'];
                $i += 6; continue;
            }
            if ($type === 136) {                                                // GPS : 3 + 3 + 3 octets
                if ($i + 9 > $n) break;
                $out["latitude_$canal"] = [self::s24(substr($c, $i, 3)) / 10000, '°'];
                $out["longitude_$canal"] = [self::s24(substr($c, $i + 3, 3)) / 10000, '°'];
                $out["altitude_$canal"] = [self::s24(substr($c, $i + 6, 3)) / 100, 'm'];
                $i += 9; continue;
            }
            $d = $T[$type] ?? null;
            if (!$d || $i + $d[1] > $n) break;
            $b = substr($c, $i, $d[1]); $i += $d[1];
            $v = match ($d[1]) { 1 => ord($b), 2 => $d[2] ? self::s16($b) : self::u16($b), 4 => unpack('N', $b)[1], default => 0 };
            $out[$d[0] . '_' . $canal] = [$v / $d[3], $d[4]];
        }
        return $out;
    }

    /** Elsys : type sur un octet, puis sa valeur (format publié par Elsys). */
    public static function elsys(string $c): array
    {
        $out = [];
        $i = 0; $n = strlen($c);
        $L = [0x01 => 2, 0x02 => 1, 0x03 => 3, 0x04 => 2, 0x05 => 1, 0x06 => 2, 0x07 => 2, 0x08 => 2, 0x09 => 6, 0x0A => 2, 0x0B => 4,
              0x0C => 2, 0x0D => 1, 0x0E => 2, 0x0F => 1, 0x10 => 4, 0x11 => 1, 0x12 => 1, 0x14 => 4, 0x15 => 2, 0x16 => 2, 0x17 => 4,
              0x18 => 2, 0x19 => 2, 0x1A => 1, 0x1B => 4, 0x1C => 2, 0x3D => 4];
        while ($i < $n) {
            $t = ord($c[$i++]);
            $l = $L[$t] ?? null;
            if ($l === null || $i + $l > $n) break;
            $b = substr($c, $i, $l); $i += $l;
            switch ($t) {
                case 0x01: $out['temperature'] = [self::s16($b) / 10, '°C']; break;
                case 0x02: $out['humidite'] = [ord($b), '%']; break;
                case 0x04: $out['luminosite'] = [self::u16($b), 'lx']; break;
                case 0x05: $out['mouvement'] = [ord($b), '']; break;
                case 0x06: $out['co2'] = [self::u16($b), 'ppm']; break;
                case 0x07: $out['batterie'] = [self::u16($b) / 1000, 'V']; break;
                case 0x08: $out['analogique_1'] = [self::u16($b) / 1000, 'V']; break;
                case 0x0A: $out['impulsions_1'] = [self::u16($b), '']; break;
                case 0x0B: $out['impulsions_1_total'] = [unpack('N', $b)[1], '']; break;
                case 0x0C: $out['temperature_externe'] = [self::s16($b) / 10, '°C']; break;
                case 0x0D: $out['entree_tor'] = [ord($b), '']; break;
                case 0x0E: $out['distance'] = [self::u16($b), 'mm']; break;
                case 0x0F: $out['mouvements'] = [ord($b), '']; break;
                case 0x10: $out['temperature_ir_interne'] = [self::s16(substr($b, 0, 2)) / 10, '°C'];
                           $out['temperature_ir_externe'] = [self::s16(substr($b, 2, 2)) / 10, '°C']; break;
                case 0x11: $out['occupation'] = [ord($b), '']; break;
                case 0x12: $out['fuite'] = [ord($b), '']; break;
                case 0x14: $out['pression'] = [unpack('N', $b)[1] / 1000, 'hPa']; break;
                case 0x15: $out['son_crete'] = [ord($b[0]), 'dB']; $out['son_moyen'] = [ord($b[1]), 'dB']; break;
                case 0x16: $out['impulsions_2'] = [self::u16($b), '']; break;
                case 0x17: $out['impulsions_2_total'] = [unpack('N', $b)[1], '']; break;
                case 0x18: $out['analogique_2'] = [self::u16($b) / 1000, 'V']; break;
                case 0x19: $out['temperature_externe_2'] = [self::s16($b) / 10, '°C']; break;
                case 0x1C: $out['cov'] = [self::u16($b), 'ppb']; break;
            }
        }
        return $out;
    }

    /** Milesight : canal, type, valeur (petit-boutiste). Types courants seulement. */
    public static function milesight(string $c): array
    {
        $out = [];
        $i = 0; $n = strlen($c);
        $noms7d = [0x07 => 'co2', 0x08 => 'cov', 0x0A => 'hcho', 0x0B => 'pm2_5', 0x0C => 'pm10', 0x0D => 'o3'];
        while ($i + 2 <= $n) {
            $canal = ord($c[$i]); $type = ord($c[$i + 1]); $i += 2;
            $l = match ($type) { 0x75, 0x68, 0x00, 0x01 => 1, 0x67, 0x7D, 0x73, 0x6A, 0xCB => 2, 0x65 => 6, default => null };
            if ($l === null || $i + $l > $n) break;
            $b = substr($c, $i, $l); $i += $l;
            switch ($type) {
                case 0x75: $out['batterie'] = [ord($b), '%']; break;
                case 0x67: $out['temperature' . ($canal === 0x03 ? '' : '_' . $canal)] = [self::s16le($b) / 10, '°C']; break;
                case 0x68: $out['humidite' . ($canal === 0x04 ? '' : '_' . $canal)] = [ord($b) / 2, '%']; break;
                case 0x7D: $out[$noms7d[$canal] ?? 'mesure_' . $canal] = [self::u16le($b), $canal === 0x07 ? 'ppm' : ($canal === 0x08 ? 'ppb' : 'µg/m³')]; break;
                case 0x73: $out['pression'] = [self::u16le($b) / 10, 'hPa']; break;
                case 0x6A: $out['activite'] = [self::u16le($b), '']; break;
                case 0x65: $out['luminosite'] = [self::u16le(substr($b, 0, 2)), 'lx']; break;
                case 0xCB: $out['luminosite_niveau'] = [self::u16le($b), '']; break;
                case 0x00: $out['entree_' . $canal] = [ord($b), '']; break;
                case 0x01: $out['sortie_' . $canal] = [ord($b), '']; break;
            }
        }
        return $out;
    }

    /**
     * Valeur d'un point d'après son adresse :
     *   {"champ": "co2"}                                   un champ décodé ;
     *   {"champ": "rssi"} / "snr" / "fcnt"                 la qualité radio ;
     *   {"octet": 2, "type": "uint16", "ordre": "be"}      des octets choisis.
     */
    public static function valeurPoint(array $adresse, array $trame): ?float
    {
        $champ = trim((string)($adresse['champ'] ?? ''));
        if ($champ !== '') {
            if (in_array($champ, ['rssi', 'snr', 'fcnt', 'sf'], true)) return isset($trame[$champ]) ? (float)$trame[$champ] : null;
            $v = $trame['champs'][$champ] ?? null;
            return is_array($v) && is_numeric($v[0]) ? (float)$v[0] : null;
        }
        if (!isset($adresse['octet']) || $adresse['octet'] === '') return null;
        $c = (string)($trame['charge'] ?? '');
        $pos = (int)$adresse['octet'];
        $type = (string)($adresse['type'] ?? 'uint8');
        $tailles = ['uint8' => 1, 'int8' => 1, 'uint16' => 2, 'int16' => 2, 'uint32' => 4, 'int32' => 4, 'float32' => 4];
        $t = $tailles[$type] ?? null;
        if ($t === null || $pos < 0 || $pos + $t > strlen($c)) return null;
        $b = substr($c, $pos, $t);
        if (($adresse['ordre'] ?? 'be') === 'le') $b = strrev($b);
        return match ($type) {
            'uint8'   => (float)ord($b),
            'int8'    => (float)(ord($b) >= 0x80 ? ord($b) - 0x100 : ord($b)),
            'uint16'  => (float)unpack('n', $b)[1],
            'int16'   => (float)self::s16($b),
            'uint32'  => (float)unpack('N', $b)[1],
            'int32'   => (float)(($u = unpack('N', $b)[1]) >= 0x80000000 ? $u - 0x100000000 : $u),
            'float32' => (float)unpack('G', $b)[1],
        };
    }
}

final class ConnectPiloteLorawan extends ConnectPiloteBase
{
    public static function description(): array
    {
        return [
            'protocole' => 'lorawan',
            'libelle' => 'LoRaWAN (serveur intégré)',
            'resume' => 'Capteurs radio LoRaWAN reçus directement par les passerelles du bâtiment : ni ChirpStack, ni TTN, ni broker.',
            'collecte' => 'entrant',
            'port_defaut' => null,
            'intervalle_defaut' => 0,
            'hote' => null,
            'identifiant' => ['libelle' => 'DevEUI du capteur', 'requis' => true,
                              'aide' => '16 caractères hexadécimaux, sur l\'étiquette du capteur ou dans sa notice, ex. 70B3D57ED005A1B2.'],
            'champs' => [
                ['cle' => 'activation', 'libelle' => 'Activation', 'type' => 'choix', 'defaut' => 'otaa',
                 'options' => [['otaa', 'OTAA (le capteur rejoint le réseau avec sa clé AppKey)'], ['abp', 'ABP (clés de session fixes)']]],
                ['cle' => 'join_eui', 'libelle' => 'JoinEUI / AppEUI (OTAA)', 'type' => 'texte', 'defaut' => '',
                 'aide' => '16 caractères hexadécimaux. Vide : accepté quel qu\'il soit.'],
                ['cle' => 'dev_addr', 'libelle' => 'DevAddr (ABP)', 'type' => 'texte', 'defaut' => '',
                 'aide' => '8 caractères hexadécimaux. En OTAA, Larka l\'attribue lui-même au join.'],
                ['cle' => 'decodeur', 'libelle' => 'Décodeur de la charge utile', 'type' => 'choix', 'defaut' => 'lpp',
                 'options' => array_map(fn($k, $v) => [$k, $v], array_keys(ConnectLorawan::DECODEURS), ConnectLorawan::DECODEURS)],
                ['cle' => 'silence_minutes', 'libelle' => 'Alarme si aucune trame depuis (minutes)', 'type' => 'nombre', 'defaut' => 180,
                 'aide' => 'Au moins 3 fois l\'intervalle d\'émission du capteur (souvent 10 à 60 min). 15 minimum.'],
                ['cle' => 'remise_a_zero', 'libelle' => 'Accepter un compteur de trames remis à zéro (ABP)', 'type' => 'booleen', 'defaut' => true,
                 'aide' => 'Un capteur ABP recompte depuis 0 à chaque redémarrage. Décocher protège mieux contre le rejeu.'],
            ],
            'secrets' => [
                ['cle' => 'app_key', 'libelle' => 'AppKey (OTAA)', 'type' => 'secret', 'aide' => '32 caractères hexadécimaux.'],
                ['cle' => 'nwk_s_key', 'libelle' => 'NwkSKey (ABP)', 'type' => 'secret', 'aide' => '32 caractères hexadécimaux.'],
                ['cle' => 'app_s_key', 'libelle' => 'AppSKey (ABP)', 'type' => 'secret', 'aide' => '32 caractères hexadécimaux.'],
            ],
            'adresse' => [
                ['cle' => 'champ', 'libelle' => 'Champ décodé', 'type' => 'texte',
                 'aide' => 'Ex. temperature_1, co2, humidite — ou rssi, snr, fcnt. « Découvrir » les propose d\'après la dernière trame.'],
                ['cle' => 'octet', 'libelle' => 'Ou : position dans la charge (octets bruts)', 'type' => 'nombre', 'aide' => 'À partir de 0.'],
                ['cle' => 'type', 'libelle' => 'Type', 'type' => 'choix', 'defaut' => 'uint16',
                 'options' => [['uint8', 'uint8'], ['int8', 'int8'], ['uint16', 'uint16'], ['int16', 'int16'], ['uint32', 'uint32'],
                               ['int32', 'int32'], ['float32', 'float32']]],
                ['cle' => 'ordre', 'libelle' => 'Ordre des octets', 'type' => 'choix', 'defaut' => 'be',
                 'options' => [['be', 'Gros-boutiste (le plus courant)'], ['le', 'Petit-boutiste']]],
            ],
            'ecriture' => false,
            'decouverte' => true,
            'aide_decouverte' => 'Les champs proposés sont ceux de la dernière trame reçue du capteur.',
        ];
    }

    public function lire(array $points): array
    {
        return [];       // les trames arrivent par le service d'écoute
    }

    /** État publié par le service d'écoute (data/run/connect-lorawan.json). */
    public static function etatService(): array
    {
        $f = dirname(__DIR__, 3) . '/data/run/connect-lorawan.json';
        $e = @json_decode((string)@file_get_contents($f), true);
        if (!is_array($e)) return ['en_marche' => false];
        $vivant = !empty($e['maj']) && (time() - (int)strtotime((string)$e['maj'])) < 30;
        return $e + ['en_marche' => $vivant];
    }

    /** Ce que le service sait de ce capteur : dernière trame, radio, champs décodés. */
    private function appareil(): ?array
    {
        $s = self::etatService();
        $eui = ConnectLorawan::hex((string)($this->c['Identifiant'] ?? ''), 8);
        return $eui ? ($s['appareils'][(string)($this->c['_tenant'] ?? 'default')][$eui] ?? null) : null;
    }

    public function tester(array $points = []): array
    {
        $cfg = function_exists('cfg') ? (cfg('connect', 'lorawan') ?? []) : [];
        $eui = ConnectLorawan::hex((string)($this->c['Identifiant'] ?? ''), 8);
        if (!$eui) return [self::etape('reseau', false, 'DevEUI invalide : 16 caractères hexadécimaux attendus.')];
        if (empty($cfg['actif'])) {
            return [self::etape('reseau', false, 'Le serveur LoRaWAN intégré est désactivé (Configuration → Serveur → Larka Connect → LoRaWAN).')];
        }
        $s = self::etatService();
        if (!$s['en_marche']) {
            return [self::etape('reseau', false, 'Le service d\'écoute ne tourne pas. Il démarre avec Larka (start.sh, service systemd larka-ocpp, lanceur Windows) : '
                . 'php api/outils/connect-ocpp.php --etat')];
        }
        $gw = (array)($s['passerelles'] ?? []);
        $recentes = array_filter($gw, fn($g) => !empty($g['vu']) && time() - (int)strtotime((string)$g['vu']) < 600);
        $etapes = [self::etape('reseau', $recentes ? true : false, $recentes
            ? count($recentes) . ' passerelle(s) LoRaWAN entendue(s) ces 10 dernières minutes, sur ' . ($s['ecoute'] ?? '?') . '.'
            : 'Aucune passerelle n\'a parlé à Larka depuis 10 minutes. Dans chaque passerelle : « packet forwarder Semtech (UDP) », '
              . 'serveur = l\'adresse de Larka, ports montant et descendant ' . ($cfg['port'] ?? 1700) . '. Le pare-feu doit laisser passer l\'UDP.')];
        $act = (string)$this->param('activation', 'otaa');
        $manque = $act === 'otaa'
            ? (ConnectLorawan::hex($this->secretVal('app_key'), 16) ? '' : 'AppKey manquante ou invalide (32 caractères hexadécimaux).')
            : ((ConnectLorawan::hex($this->secretVal('nwk_s_key'), 16) && ConnectLorawan::hex($this->secretVal('app_s_key'), 16)
                && ConnectLorawan::hex((string)$this->param('dev_addr', ''), 4)) ? '' : 'ABP : DevAddr, NwkSKey et AppSKey sont obligatoires.');
        if ($manque !== '') { $etapes[] = self::etape('droits', false, $manque); return $etapes; }
        $a = $this->appareil();
        if (!$a || empty($a['derniere'])) {
            $etapes[] = self::etape('protocole', false, $act === 'otaa'
                ? 'Le capteur ne s\'est pas encore activé (join). Redémarrez-le (souvent : retirer la pile, ou un aimant) près d\'une passerelle ; '
                  . 'vérifiez son DevEUI et son AppKey.'
                : 'Aucune trame reçue de ce capteur. Vérifiez son DevAddr et ses clés, et qu\'il est à portée d\'une passerelle.');
            if (!empty($a['rejet'])) $etapes[] = self::etape('droits', false, 'Dernière trame refusée : ' . $a['rejet']);
            return $etapes;
        }
        $etapes[] = self::etape('protocole', true, 'Dernière trame reçue le ' . date('d/m/Y à H:i:s', (int)strtotime((string)$a['derniere']))
            . (isset($a['rssi']) ? sprintf(' — RSSI %d dBm, SNR %.1f dB', (int)$a['rssi'], (float)($a['snr'] ?? 0)) : '')
            . (!empty($a['datr']) ? ', ' . $a['datr'] : '') . (!empty($a['passerelle']) ? ', passerelle ' . $a['passerelle'] : '')
            . ' (trame n° ' . (int)($a['fcnt'] ?? 0) . ').');
        $champs = (array)($a['champs'] ?? []);
        $etapes[] = self::etape('points', $champs ? true : null, $champs
            ? count($champs) . ' champ(s) décodé(s) : ' . implode(', ', array_slice(array_map(fn($k, $v) => $k . ' = ' . $v[0] . ($v[1] ? ' ' . $v[1] : ''),
                array_keys($champs), $champs), 0, 8)) . '.'
            : 'Trame déchiffrée, mais le décodeur choisi n\'y reconnaît rien : essayez un autre décodeur, ou « octets bruts » ('
              . strtoupper((string)($a['octets'] ?? '')) . ').');
        if (isset($a['rssi']) && (int)$a['rssi'] < -115) {
            $etapes[] = self::etape('reseau', null, 'Réception faible (RSSI ' . (int)$a['rssi'] . ' dBm) : une passerelle plus proche fiabiliserait les relevés.');
        }
        return $etapes;
    }

    public function decouvrir(): array
    {
        $a = $this->appareil();
        $out = [];
        foreach ((array)($a['champs'] ?? []) as $nom => $v) {
            if (!is_array($v)) continue;
            $u = (string)($v[1] ?? '');
            $nature = preg_match('/(total|energie|index|impulsions_\d_total)/', $nom) ? 'compteur'
                    : (preg_match('/^(entree|sortie|presence|mouvement|occupation|fuite|interrupteur)/', $nom) ? 'etat' : 'mesure');
            $out[] = ['nom' => ucfirst(str_replace('_', ' ', $nom)), 'code' => $nom, 'adresse' => ['champ' => $nom],
                      'unite' => $u, 'nature' => $nature, 'valeur' => $v[0]];
        }
        if ($a) {
            $out[] = ['nom' => 'Réception radio (RSSI)', 'code' => 'rssi', 'adresse' => ['champ' => 'rssi'], 'unite' => 'dBm', 'nature' => 'mesure', 'valeur' => $a['rssi'] ?? null];
        }
        return $out;
    }
}

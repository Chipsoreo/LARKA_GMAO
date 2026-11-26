<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka — épreuve de la radio : LoRaWAN intégré et récepteurs radio USB.
 *
 * Aucune bibliothèque ni aucun matériel : la cryptographie est vérifiée sur
 * les vecteurs publiés (RFC 4493, trame LoRaWAN de référence, télégramme OMS),
 * puis tout le chemin est rejoué sur une INSTALLATION JETABLE (SQLite) :
 *
 *   1. LoRaWAN, pièces seules : AES-CMAC, MIC et déchiffrement d'une trame de
 *      référence, join-accept relu côté capteur, compteur 32 bits, décodeurs
 *      Cayenne LPP / Elsys / Milesight, octets bruts ;
 *   2. LoRaWAN, service réel : une passerelle simulée (UDP, packet forwarder
 *      Semtech) et deux capteurs simulés — l'un OTAA (join, clés dérivées),
 *      l'autre ABP. Valeurs rangées dans les points, doublons de deux
 *      passerelles comptés une fois, rejeu refusé, MIC faux ignoré, trame
 *      confirmée accusée, capteur inconnu signalé, DevNonce rejoué refusé ;
 *   3. wM-Bus (compteurs radio, clé USB) : trames des récepteurs IMST, Amber
 *      et CUL, déchiffrement OMS mode 5, données DIF/VIF, inventaire du
 *      matériel (fausse arborescence /sys), lecture d'un port série simulé
 *      (pseudo-terminal) par le serveur ou par le PC releveur.
 *
 *   php outils/epreuves/test-radio.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$racine = dirname(__DIR__, 2);
$ok = 0; $ko = 0;
function verif(bool $c, string $m, string $d = ''): void
{
    global $ok, $ko;
    if ($c) { $ok++; printf("  ✅  %-66s %s\n", $m, $d); }
    else    { $ko++; printf("  ❌  %-66s %s\n", $m, $d); }
}
function titre(string $t): void { echo "\n  $t\n"; }
function port_libre_udp(): int
{
    $s = stream_socket_server('udp://127.0.0.1:0', $e, $es, STREAM_SERVER_BIND);
    $n = (string)stream_socket_get_name($s, false);
    fclose($s);
    return (int)substr((string)strrchr($n, ':'), 1);
}
function effacer(string $d): void
{
    if (!is_dir($d)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) ($f->isDir() && !$f->isLink()) ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    @rmdir($d);
}
function copier_dossier(string $de, string $vers): void
{
    @mkdir($vers, 0700, true);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($de, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $f) {
        $cible = $vers . '/' . substr($f->getPathname(), strlen($de) + 1);
        $f->isDir() ? @mkdir($cible, 0700, true) : copy($f->getPathname(), $cible);
    }
}

echo "\n═══════════════════════════════════════════════════════════════════════\n";
echo " Larka — radio : LoRaWAN intégré, compteurs wM-Bus, récepteurs USB\n";
echo "═══════════════════════════════════════════════════════════════════════\n";

if (!extension_loaded('openssl')) { echo "\n  ⏭️  openssl absent : épreuve sautée.\n"; exit(0); }

$GLOBALS['_cfgEpreuve'] = ['connect' => []];
if (!function_exists('cfg')) {
    function cfg(string ...$k)
    {
        $v = $GLOBALS['_cfgEpreuve'];
        foreach ($k as $x) { if (!is_array($v) || !array_key_exists($x, $v)) return null; $v = $v[$x]; }
        return $v;
    }
}
require_once "$racine/api/Chemins.php";
require_once "$racine/api/connect/Pilote.php";
ConnectPilotes::charger();
require_once "$racine/api/connect/Releveur.php";

// ═════════════════════════════════════════════════════════════════════════════
// 1. LoRaWAN — PIÈCES SEULES
// ═════════════════════════════════════════════════════════════════════════════
titre('LoRaWAN : cryptographie (vecteurs publiés)');
$k = hex2bin('2b7e151628aed2a6abf7158809cf4f3c');
verif(bin2hex(ConnectLorawan::cmac($k, '')) === 'bb1d6929e95937287fa37d129b756746'
   && bin2hex(ConnectLorawan::cmac($k, hex2bin('6bc1bee22e409f96e93d7e117393172a'))) === '070a16b46b4d4144f79bdd9dd04a287c'
   && bin2hex(ConnectLorawan::cmac($k, hex2bin('6bc1bee22e409f96e93d7e117393172aae2d8a571e03ac9c9eb76fac45af8e5130c81c46a35ce411')))
      === 'dfa66747de9ae63030ca32611497c827', 'AES-CMAC : les trois vecteurs de la RFC 4493');
$ref = hex2bin('40F17DBE4900020001954378762B11FF0D');
$a = ConnectLorawan::analyser($ref);
$nwk = hex2bin('44024241ed4ce9a68c6a8bc055233fd3'); $app = hex2bin('ec925802ae430ca77fd3dd73cb2cc588');
verif($a && $a['dev_addr'] === '49BE7DF1' && $a['fcnt16'] === 2 && $a['fport'] === 1, 'trame de référence lue : DevAddr 49BE7DF1, trame n° 2, port 1');
verif(hash_equals(ConnectLorawan::micDonnees($nwk, $a['dev_addr_le'], 2, 0, $a['msg']), $a['mic']), 'son MIC vérifié avec la NwkSKey');
verif(ConnectLorawan::chiffrerCharge($app, $a['dev_addr_le'], 2, 0, $a['charge']) === 'test', 'sa charge déchiffrée avec l\'AppSKey : « test »');
verif(!hash_equals(ConnectLorawan::micDonnees($app, $a['dev_addr_le'], 2, 0, $a['msg']), $a['mic']), 'une autre clé : MIC refusé');

// Join-accept relu comme le ferait le capteur (il « chiffre » pour déchiffrer).
$appKey = random_bytes(16);
$ja = ConnectLorawan::joinAccept($appKey, "\x01\x02\x03", "\0\0\0", strrev(hex2bin('01ABCDEF')));
$clair = openssl_encrypt(substr($ja, 1), 'aes-128-ecb', $appKey, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
verif(strlen($ja) === 17 && ord($ja[0]) === 0x20 && strrev(substr($clair, 6, 4)) === hex2bin('01ABCDEF')
   && substr(ConnectLorawan::cmac($appKey, "\x20" . substr($clair, 0, 12)), 0, 4) === substr($clair, 12, 4),
      'join-accept : 17 octets, relu et vérifié côté capteur (DevAddr, MIC)');
$cs = ConnectLorawan::clesSession($appKey, "\x01\x02\x03", "\0\0\0", "\xAA\xBB");
verif($cs['nwk'] !== $cs['app'] && strlen($cs['nwk']) === 16, 'clés de session dérivées : NwkSKey ≠ AppSKey');
verif(ConnectLorawan::fcnt32(5, null) === 5 && ConnectLorawan::fcnt32(3, 0xFFFE) === 0x10003 && ConnectLorawan::fcnt32(0x0010, 0x1000F) === 0x10010
   && ConnectLorawan::fcnt32(2, 500) === 2, 'compteur 32 bits reconstitué (passage de 65535, recul gardé tel quel)');
verif(ConnectLorawan::hex('70:b3:d5:7e:d0:05:a1:b2', 8) === '70B3D57ED005A1B2' && ConnectLorawan::hex('70B3D57ED005A1', 8) === null
   && ConnectLorawan::hex('ZZB3D57ED005A1B2', 8) === null, 'identifiants : hexadécimal strict, séparateurs tolérés');

titre('LoRaWAN : décodeurs de charge utile');
$lpp = ConnectLorawan::lpp(hex2bin('016700E10268500367FFD7047D0258'));
verif(($lpp['temperature_1'][0] ?? null) == 22.5 && ($lpp['humidite_2'][0] ?? null) == 40 && ($lpp['temperature_3'][0] ?? null) == -4.1
   && ($lpp['concentration_4'][0] ?? null) == 600 && ($lpp['temperature_1'][1] ?? '') === '°C', 'Cayenne LPP : 22,5 °C, 40 %, -4,1 °C, 600 ppm');
verif(count(ConnectLorawan::lpp(hex2bin('016700E1FF'))) === 1, 'Cayenne LPP : octet incompris → arrêt, ce qui précède reste lu');
$el = ConnectLorawan::elsys(hex2bin('0100E202290400270506060308070D62'));
verif(($el['temperature'][0] ?? null) == 22.6 && ($el['humidite'][0] ?? null) == 41 && ($el['luminosite'][0] ?? null) == 39
   && ($el['mouvement'][0] ?? null) == 6 && ($el['co2'][0] ?? null) == 776 && ($el['batterie'][0] ?? null) == 3.426,
      'Elsys : température, humidité, lumière, mouvement, CO₂, batterie');
$ms = ConnectLorawan::milesight(hex2bin('0175640367100104687107736027077D6F02'));
verif(($ms['batterie'][0] ?? null) == 100 && ($ms['temperature'][0] ?? null) == 27.2 && ($ms['humidite'][0] ?? null) == 56.5
   && ($ms['pression'][0] ?? null) == 1008.0 && ($ms['co2'][0] ?? null) == 623, 'Milesight : batterie, température, humidité, pression, CO₂');
$tr = ['champs' => $lpp, 'charge' => hex2bin('00FF1234ABCD0000'), 'rssi' => -97, 'snr' => 7.5, 'fcnt' => 12];
verif(ConnectLorawan::valeurPoint(['champ' => 'temperature_1'], $tr) == 22.5 && ConnectLorawan::valeurPoint(['champ' => 'rssi'], $tr) == -97.0
   && ConnectLorawan::valeurPoint(['octet' => 2, 'type' => 'uint16', 'ordre' => 'be'], $tr) == 0x1234
   && ConnectLorawan::valeurPoint(['octet' => 2, 'type' => 'uint16', 'ordre' => 'le'], $tr) == 0x3412
   && ConnectLorawan::valeurPoint(['octet' => 1, 'type' => 'int8'], $tr) == -1 && ConnectLorawan::valeurPoint(['octet' => 7, 'type' => 'uint16'], $tr) === null
   && ConnectLorawan::valeurPoint(['champ' => 'absent'], $tr) === null, 'adresse d\'un point : champ décodé, radio, octets choisis, hors charge → rien');

// ═════════════════════════════════════════════════════════════════════════════
// 2. LoRaWAN — SERVICE RÉEL, PASSERELLE ET CAPTEURS SIMULÉS
// ═════════════════════════════════════════════════════════════════════════════
$t = null;
$processus = [];
register_shutdown_function(function () use (&$processus, &$t) {
    foreach ($processus as $p) { if (is_resource($p)) { @proc_terminate($p); @proc_close($p); } }
    if ($t === null) return;
    if (getenv('LARKA_EPREUVE_GARDER')) { echo "  (installation gardée : $t)\n"; return; }
    effacer($t);
});

if (!extension_loaded('pdo_sqlite')) {
    echo "\n  ⏭️  pdo_sqlite absent : service LoRaWAN réel non éprouvé.\n";
} else {
    titre('LoRaWAN : service réel, passerelle et capteurs simulés');
    $t = sys_get_temp_dir() . '/larka-radio-' . bin2hex(random_bytes(4));
    copier_dossier("$racine/api", "$t/api");
    copy("$racine/version.json", "$t/version.json");
    @mkdir("$t/data/run", 0700, true);
    $portLw = port_libre_udp();
    file_put_contents("$t/config.json", json_encode([
        'serveur' => ['env' => 'dev'],
        'base_de_donnees' => ['driver' => 'sqlite', 'path' => 'data/gmao.db'],
        'connect' => ['actif' => true, 'ocpp' => ['actif' => false], 'lorawan' => ['actif' => true, 'hote' => '127.0.0.1', 'port' => $portLw]],
        'push' => ['actif' => false],
    ], JSON_PRETTY_PRINT));
    $php = escapeshellarg(PHP_BINARY);
    // Scénario : exécuté DANS l'installation jetable (sa config, sa base).
    file_put_contents("$t/scenario.php", <<<'PHP'
<?php
declare(strict_types=1);
define('LARKA_SANS_SESSION', true);
chdir(__DIR__);
require __DIR__ . '/api/config.php';
require_once __DIR__ . '/api/SecurityLog.php';
require_once __DIR__ . '/api/Journal.php';
require_once __DIR__ . '/api/Database.php';
require_once __DIR__ . '/api/connect/Connect.php';
Connect::charger();
$db = new Database();
$depot = new ConnectDepot($db);
$arg = json_decode($argv[2] ?? '{}', true) ?: [];
$s = [];
switch ($argv[1]) {
    case 'preparer':
        foreach ($arg['connexions'] as $cle => $c) {
            [$v, $err] = $depot->normaliserPasserelle($c);
            if ($err) { $s[$cle] = ['erreurs' => $err]; continue; }
            $s[$cle] = ['id' => $depot->creerPasserelle($v, 'epreuve')];
        }
        foreach ($arg['points'] as $p) {
            $p['passerelleId'] = $s[$p['de']]['id'] ?? 0;
            [$v, $err] = $depot->normaliserPoint($p);
            if (!$err) $s['points'][$p['code']] = $depot->creerPoint($v, 'epreuve');
        }
        break;
    case 'refus':
        [, $err] = $depot->normaliserPasserelle($arg);
        $s['erreurs'] = $err;
        break;
    case 'lire':
        foreach ($db->fetchAll("SELECT Code, DerniereValeur, DerniereLecture FROM ConnectPoints") as $p) $s['points'][$p['Code']] = $p['DerniereValeur'];
        $s['mesures'] = (int)$db->fetchOne("SELECT COUNT(*) AS n FROM ConnectMesures")['n'];
        foreach ($db->fetchAll("SELECT * FROM ConnectLorawan") as $l) { $l['Session'] = $l['Session'] ? 'chiffrée:' . strlen($l['Session']) : null; $s['sessions'][$l['PasserelleId']] = $l; }
        foreach ($db->fetchAll("SELECT Id, Etat, DernierContact FROM ConnectPasserelles") as $p) $s['etats'][$p['Id']] = $p;
        break;
    case 'journal':
        $s['entrees'] = $db->fetchAll("SELECT Niveau, Code, Message, EntiteId FROM Journal WHERE Canal = 'connect' ORDER BY Id");
        break;
    case 'tester':
        $pil = ConnectPilotes::creer($depot->pourPilote((int)$arg['id']));
        $s['etapes'] = $pil->tester([]);
        $s['decouverte'] = $pil->decouvrir();
        break;
}
echo json_encode($s);
PHP);
    $scen = function (string $quoi, array $arg = []) use ($t, $php): array {
        $o = shell_exec("cd " . escapeshellarg($t) . " && $php scenario.php $quoi " . escapeshellarg(json_encode($arg)) . ' 2>&1');
        $r = json_decode((string)$o, true);
        if (!is_array($r)) echo "      (scénario $quoi : " . substr((string)$o, 0, 400) . ")\n";
        return is_array($r) ? $r : [];
    };

    $euiOtaa = '70B3D57ED005A1B2'; $appKeyHex = strtoupper(bin2hex(random_bytes(16)));
    $euiAbp = '70B3D57ED005A1C3'; $addrAbp = '26011BDA';
    $nwkAbp = strtoupper(bin2hex(random_bytes(16))); $appAbp = strtoupper(bin2hex(random_bytes(16)));
    $prep = $scen('preparer', [
        'connexions' => [
            'otaa' => ['protocole' => 'lorawan', 'nom' => 'Sonde salle 12', 'identifiant' => strtolower(implode(':', str_split($euiOtaa, 2))),
                       'parametres' => ['activation' => 'otaa', 'decodeur' => 'lpp'], 'secrets' => ['app_key' => $appKeyHex]],
            'abp'  => ['protocole' => 'lorawan', 'nom' => 'Compteur à impulsions', 'identifiant' => $euiAbp,
                       'parametres' => ['activation' => 'abp', 'dev_addr' => $addrAbp, 'decodeur' => 'octets'],
                       'secrets' => ['nwk_s_key' => $nwkAbp, 'app_s_key' => $appAbp]],
        ],
        'points' => [
            ['de' => 'otaa', 'nom' => 'Température', 'code' => 'T', 'unite' => '°C', 'adresse' => ['champ' => 'temperature_1']],
            ['de' => 'otaa', 'nom' => 'CO₂', 'code' => 'CO2', 'unite' => 'ppm', 'adresse' => ['champ' => 'concentration_4']],
            ['de' => 'otaa', 'nom' => 'Réception', 'code' => 'RSSI', 'unite' => 'dBm', 'adresse' => ['champ' => 'rssi']],
            ['de' => 'abp', 'nom' => 'Index', 'code' => 'IDX', 'nature' => 'compteur', 'adresse' => ['octet' => 0, 'type' => 'uint32', 'ordre' => 'be']],
        ],
    ]);
    $idO = (int)($prep['otaa']['id'] ?? 0); $idA = (int)($prep['abp']['id'] ?? 0);
    verif($idO > 0 && $idA > 0 && count($prep['points'] ?? []) === 4, 'capteurs déclarés : un OTAA (DevEUI saisi avec « : »), un ABP ; 4 points',
          json_encode($prep['otaa']['erreurs'] ?? $prep['abp']['erreurs'] ?? ''));
    $r1 = $scen('refus', ['protocole' => 'lorawan', 'nom' => 'Doublon', 'identifiant' => $euiOtaa, 'parametres' => ['activation' => 'otaa'], 'secrets' => ['app_key' => 'XYZ']]);
    $r2 = $scen('refus', ['protocole' => 'lorawan', 'nom' => 'ABP incomplet', 'identifiant' => '0011223344556677', 'parametres' => ['activation' => 'abp']]);
    $e1 = implode(' ', $r1['erreurs'] ?? []); $e2 = implode(' ', $r2['erreurs'] ?? []);
    verif(str_contains($e1, 'déjà déclaré') && str_contains($e1, 'APPKEY') && str_contains($e2, 'DevAddr') && str_contains($e2, 'NwkSKey'),
          'saisie refusée : DevEUI en double, clé non hexadécimale, ABP sans DevAddr ni clés');

    $journal = "$t/data/service.log";
    $processus[] = proc_open("exec $php " . escapeshellarg("$t/api/outils/connect-ocpp.php") . ' --duree=40',
                             [0 => ['file', '/dev/null', 'r'], 1 => ['file', $journal, 'a'], 2 => ['file', $journal, 'a']], $tubes, $t);

    // ── La passerelle simulée : deux sockets, comme un vrai packet forwarder ──
    $gwEui = 'AA555A0000000101'; $gw2Eui = 'AA555A0000000202';
    $push = stream_socket_client("udp://127.0.0.1:$portLw", $en, $es);
    $pull = stream_socket_client("udp://127.0.0.1:$portLw", $en, $es);
    $push2 = stream_socket_client("udp://127.0.0.1:$portLw", $en, $es);
    foreach ([$push, $pull, $push2] as $s) stream_set_blocking($s, false);
    $recevoir = function ($s, float $delai = 3.0): ?string {
        $fin = microtime(true) + $delai;
        while (microtime(true) < $fin) {
            $r = [$s]; $w = $x = null;
            if (@stream_select($r, $w, $x, 0, 100000) > 0) { $p = @fread($s, 65535); if ($p !== false && $p !== '') return $p; }
        }
        return null;
    };
    $pret = false;
    for ($i = 0; $i < 60 && !$pret; $i++) {
        @fwrite($pull, "\x02\x12\x34\x02" . hex2bin($gwEui));
        $p = $recevoir($pull, 0.25);
        $pret = $p !== null && substr($p, 0, 4) === "\x02\x12\x34\x04";
    }
    verif($pret, 'le service écoute en UDP ; PULL_DATA → PULL_ACK (même jeton)');
    $tmst = 1000000;
    $rxpk = function (string $trame, int $rssi = -80, float $snr = 9.0) use (&$tmst): array {
        $tmst += 7_000_000;
        return ['tmst' => $tmst, 'chan' => 2, 'rfch' => 0, 'freq' => 868.5, 'stat' => 1, 'modu' => 'LORA', 'datr' => 'SF7BW125',
                'codr' => '4/5', 'rssi' => $rssi, 'lsnr' => $snr, 'size' => strlen($trame), 'data' => base64_encode($trame)];
    };
    $pousser = function ($s, string $eui, array $rx) use ($recevoir): bool {
        $jeton = random_bytes(2);
        @fwrite($s, "\x02" . $jeton . "\x00" . hex2bin($eui) . json_encode(['rxpk' => [$rx]]));
        $a = $recevoir($s, 2.0);
        return $a === "\x02" . $jeton . "\x01";
    };
    // Trame montante telle qu'un capteur la construit.
    $montante = function (string $addr, string $nwk, string $app, int $fcnt, int $port, string $clair, bool $confirmee = false): string {
        $le = strrev(hex2bin($addr));
        $msg = chr($confirmee ? 0x80 : 0x40) . $le . "\x00" . pack('v', $fcnt & 0xFFFF) . chr($port)
             . ConnectLorawan::chiffrerCharge($app, $le, $fcnt, 0, $clair);
        return $msg . ConnectLorawan::micDonnees($nwk, $le, $fcnt, 0, $msg);
    };

    // ── OTAA : join ──
    $appKeyB = hex2bin($appKeyHex);
    $nonce = random_bytes(2);
    $jr = "\x00" . strrev(hex2bin('0000000000000001')) . strrev(hex2bin($euiOtaa)) . $nonce;
    $jr .= substr(ConnectLorawan::cmac($appKeyB, $jr), 0, 4);
    $rxJoin = $rxpk($jr, -95, 4.0);
    verif($pousser($push, $gwEui, $rxJoin), 'PUSH_DATA → PUSH_ACK');
    $resp = $recevoir($pull, 3.0);
    $txpk = $resp !== null && ord($resp[3]) === 0x03 ? (json_decode(substr($resp, 4), true)['txpk'] ?? null) : null;
    $jaB = $txpk ? base64_decode((string)$txpk['data']) : '';
    $jaClair = strlen($jaB) === 17 ? openssl_encrypt(substr($jaB, 1), 'aes-128-ecb', $appKeyB, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING) : '';
    $micOk = $jaClair !== '' && hash_equals(substr(ConnectLorawan::cmac($appKeyB, "\x20" . substr($jaClair, 0, 12)), 0, 4), substr($jaClair, 12, 4));
    verif($txpk && $micOk && (int)$txpk['tmst'] === $rxJoin['tmst'] + 5000000 && $txpk['ipol'] === true && $txpk['datr'] === 'SF7BW125'
          && abs($txpk['freq'] - 868.5) < 1e-6, 'join accepté : réponse émise 5 s après, même canal, polarité inversée, MIC valide côté capteur');
    $addrO = $jaClair ? strtoupper(bin2hex(strrev(substr($jaClair, 6, 4)))) : '';
    $csO = $jaClair ? ConnectLorawan::clesSession($appKeyB, substr($jaClair, 0, 3), substr($jaClair, 3, 3), $nonce) : ['nwk' => '', 'app' => ''];
    verif($addrO !== '' && hexdec($addrO) <= 0x01FFFFFF, "DevAddr attribué dans le réseau privé (NetID 000000)", $addrO);

    // ── OTAA : trame de données, entendue par deux passerelles ──
    @fwrite($pull, "\x02\x56\x78\x02" . hex2bin($gw2Eui)); // la 2e passerelle se signale aussi
    $up1 = $montante($addrO, $csO['nwk'], $csO['app'], 1, 2, hex2bin('016700E1047D0258'));
    $okA = $pousser($push, $gwEui, $rxpk($up1, -101, 2.5));
    $okB = $pousser($push2, $gw2Eui, ['rssi' => -72, 'lsnr' => 10.0] + $rxpk($up1));
    usleep(900000);
    $l = $scen('lire');
    $s = $l['sessions'][$idO] ?? [];
    verif($okA && $okB && ($l['points']['T'] ?? null) == 22.5 && ($l['points']['CO2'] ?? null) == 600,
          'trame déchiffrée, décodée (LPP) et rangée : 22,5 °C, 600 ppm');
    verif((int)($s['NbTrames'] ?? 0) === 1 && ($l['points']['RSSI'] ?? null) == -72 && ($s['PasserelleRadio'] ?? '') === $gw2Eui,
          'reçue par deux passerelles : comptée une fois, la meilleure réception gardée', ($s['PasserelleRadio'] ?? '?') . ', RSSI ' . ($l['points']['RSSI'] ?? '?'));
    verif((int)($s['FCntUp'] ?? -1) === 1 && str_starts_with((string)($s['Session'] ?? ''), 'chiffrée:') && ($s['DevAddr'] ?? '') === $addrO,
          'session gardée en base : compteur, DevAddr, clés chiffrées');

    // ── Rejeu, MIC faux, trame confirmée ──
    $avant = $l['mesures'];
    $pousser($push, $gwEui, $rxpk($up1));                                     // même trame, plus tard : rejeu
    $faux = $montante($addrO, random_bytes(16), $csO['app'], 2, 2, hex2bin('016700FA'));
    $pousser($push, $gwEui, $rxpk($faux));
    usleep(700000);
    $l = $scen('lire');
    verif(($l['points']['T'] ?? null) == 22.5 && (int)($l['sessions'][$idO]['FCntUp'] ?? 0) === 1, 'rejeu refusé, trame au MIC faux ignorée : rien n\'a bougé');
    while ($recevoir($pull, 0.05) !== null) {}
    $up2 = $montante($addrO, $csO['nwk'], $csO['app'], 2, 2, hex2bin('016700F0'), true);
    $rx2 = $rxpk($up2);
    $pousser($push, $gwEui, $rx2);
    $resp = $recevoir($pull, 2.0);
    $ack = $resp !== null ? (json_decode(substr($resp, 4), true)['txpk'] ?? null) : null;
    $ackB = $ack ? base64_decode((string)$ack['data']) : '';
    $ackA = $ackB !== '' ? ConnectLorawan::analyser($ackB) : null;
    if (getenv('LARKA_EPREUVE_DETAIL')) echo '      accusé : ' . substr((string)$resp, 0, 300) . "\n";
    verif($ack && ord($ackB[0]) === 0x60 && (ord($ackB[5]) & 0x20) && (int)$ack['tmst'] === $rx2['tmst'] + 1000000
          && hash_equals(ConnectLorawan::micDonnees($csO['nwk'], strrev(hex2bin($addrO)), 0, 1, substr($ackB, 0, -4)), substr($ackB, -4)),
          'trame confirmée : accusé émis 1 s après (RX1), signé avec la NwkSKey');
    usleep(500000);
    $l = $scen('lire');
    verif(($l['points']['T'] ?? null) == 24.0, 'et sa valeur rangée : 24,0 °C');

    // ── ABP, compteur remis à zéro ──
    $nwkA = hex2bin($nwkAbp); $appA = hex2bin($appAbp);
    $pousser($push, $gwEui, $rxpk($montante($addrAbp, $nwkA, $appA, 40, 5, pack('N', 123456))));
    usleep(500000);
    $l1 = $scen('lire');
    usleep(600000);                                                           // relevés horodatés à la seconde
    $pousser($push, $gwEui, $rxpk($montante($addrAbp, $nwkA, $appA, 1, 5, pack('N', 123460))));
    usleep(500000);
    $l2 = $scen('lire');
    if (getenv('LARKA_EPREUVE_DETAIL')) echo '      ABP : ' . json_encode([$l1['points'] ?? null, $l2['points'] ?? null, $l2['sessions'][$idA] ?? null]) . "\n";
    verif(($l1['points']['IDX'] ?? null) == 123456 && ($l2['points']['IDX'] ?? null) == 123460 && (int)($l2['sessions'][$idA]['FCntUp'] ?? 0) === 1,
          'ABP : octets bruts (uint32) rangés ; capteur redémarré (compteur à 1) accepté');

    // ── Capteur inconnu, DevNonce rejoué ──
    $inc = "\x00" . str_repeat("\0", 8) . strrev(hex2bin('0011223344556677')) . "\x01\x00";
    $pousser($push, $gwEui, $rxpk($inc . substr(ConnectLorawan::cmac(random_bytes(16), $inc), 0, 4)));
    usleep(2000000);
    // Même trame de join qu'au début, renvoyée plus de 5 s après (avant, ce serait une copie) : rejeu.
    $pousser($push, $gwEui, $rxpk($jr));
    usleep(5800000);                                                          // état publié toutes les 5 s
    $etat = json_decode((string)@file_get_contents("$t/data/run/connect-lorawan.json"), true) ?: [];
    $inconnus = array_column($etat['inconnus'] ?? [], 'id');
    verif(in_array('0011223344556677', $inconnus, true), 'capteur non déclaré : signalé (DevEUI), pour qu\'on le déclare');
    $app = $etat['appareils']['default'][$euiOtaa] ?? [];
    if (getenv('LARKA_EPREUVE_DETAIL')) echo '      OTAA : ' . json_encode($app) . "\n";
    verif(str_contains((string)($app['rejet'] ?? ''), 'DevNonce'), 'join rejoué (même DevNonce) : refusé, raison visible');
    verif(isset($etat['passerelles'][$gwEui]['trames']) && !empty($etat['passerelles'][$gwEui]['emission'])
          && !str_contains((string)json_encode($etat), strtolower($appKeyHex)) && !str_contains((string)json_encode($etat), $appKeyHex),
          'état publié : passerelles vues, aucune clé dedans');
    $jr = $scen('journal');
    $codesJ = array_count_values(array_filter(array_column($jr['entrees'] ?? [], 'Code')));
    verif(($codesJ['CXL-002'] ?? 0) === 1 && ($codesJ['CXL-004'] ?? 0) === 1 && !isset($codesJ['CXL-005']),
          'journal de supervision : MIC faux (CXL-002), DevNonce rejoué (CXL-004) inscrits une fois dans la base de l\'organisation',
          json_encode($codesJ));
    // Tous les journaux du jour : le service peut dater son fichier dans un autre
    // fuseau que l'épreuve (échec observé juste après minuit, heure de Paris).
    $jsonl = implode("\n", array_map(fn($f) => (string)@file_get_contents($f), glob("$t/data/logs/journal-*.jsonl") ?: []));
    verif(str_contains($jsonl, '"Code":"CXL-005"'), 'capteur non déclaré (CXL-005) : au journal du service (fichiers), faute d\'organisation');
    $te = $scen('tester', ['id' => $idO]);
    $msgs = implode(' | ', array_column($te['etapes'] ?? [], 'message'));
    $codes = array_column($te['decouverte'] ?? [], 'code');
    verif(str_contains($msgs, 'passerelle(s) LoRaWAN entendue(s)') && str_contains($msgs, 'Dernière trame reçue') && str_contains($msgs, 'temperature_1'),
          '« Tester » : passerelles entendues, dernière trame, champs décodés');
    verif(in_array('temperature_1', $codes, true) && in_array('rssi', $codes, true), '« Découvrir » : propose les champs de la dernière trame', implode(', ', $codes));
    $etatCli = shell_exec("cd " . escapeshellarg($t) . " && $php api/outils/connect-ocpp.php --etat 2>&1");
    verif(str_contains((string)$etatCli, "passerelle $gwEui") && str_contains((string)$etatCli, 'capteur non déclaré'), 'connect-ocpp.php --etat : passerelles, capteurs, inconnus');
    foreach ($processus as $p) if (is_resource($p)) @proc_terminate($p);
}

// ═════════════════════════════════════════════════════════════════════════════
// 3. wM-Bus — COMPTEURS RADIO, CLÉ USB
// ═════════════════════════════════════════════════════════════════════════════
titre('wM-Bus : déchiffrement et données (vecteur OMS publié)');
$oms = hex2bin('2E4493157856341233037A2A0020255923C95AAA26D1B2E7493B013EC4A6F6D3529B520EDFF0EA6DEFC99D6D69EBF3');
$cleOms = hex2bin('0102030405060708090A0B0C0D0E0F11');
$lo = ConnectWmbus::lire($oms, ['12345678' => $cleOms]);
verif($lo['id'] === '12345678' && $lo['fabricant'] === 'ELS' && $lo['type_libelle'] === 'gaz' && $lo['chiffre'] && $lo['mode'] === 5,
      'en-tête lu : compteur 12345678, fabricant ELS, gaz, chiffré (mode 5)');
verif(($lo['champs']['volume'][0] ?? null) == 28504.27 && ($lo['champs']['volume'][1] ?? '') === 'm³'
   && ($lo['champs']['date_heure'][2] ?? '') === '2008-05-31 23:50' && ($lo['champs']['erreurs'][0] ?? null) === 0.0,
      'déchiffré (AES-128-CBC) et lu : 28 504,27 m³, date 31/05/2008 23:50, aucune erreur', (string)json_encode($lo['champs'], JSON_UNESCAPED_UNICODE));
$sans = ConnectWmbus::lire($oms, []);
$faux = ConnectWmbus::lire($oms, ['12345678' => random_bytes(16)]);
verif(!$sans['champs'] && str_contains((string)$sans['erreur'], 'ajoutez la clé AES') && str_contains((string)$faux['erreur'], 'incorrecte'),
      'sans clé : « ajoutez la clé » ; mauvaise clé : « incorrecte » — jamais de valeur inventée');
verif(ConnectWmbus::cles("12345678=0102030405060708090A0B0C0D0E0F11, 8765432a:00000000000000000000000000000000 ; nimporte")
      === ['12345678' => $cleOms, '8765432A' => str_repeat("\0", 16)], 'clés saisies « identifiant=clé » : lues, le reste ignoré');

titre('wM-Bus : trames des récepteurs (iM871A, Amber, CUL, transparent)');
$fab = fn(string $l) => pack('v', ((ord($l[0]) - 64) << 10) | ((ord($l[1]) - 64) << 5) | (ord($l[2]) - 64));
$chaleur = "\x44" . $fab('LAR') . "\x78\x56\x34\x12\x01\x04\x7A\x10\x00\x00\x00"
    . "\x0C\x06\x56\x34\x12\x00"        // énergie 123 456 kWh
    . "\x0C\x14\x67\x45\x23\x01"        // volume 12 345,67 m³
    . "\x0B\x2D\x50\x01\x00"            // puissance 150 × 100 W
    . "\x0B\x3B\x34\x12\x00"            // débit 1,234 m³/h
    . "\x0A\x5A\x05\x07"                // départ 70,5 °C
    . "\x0A\x5E\x20\x04"                // retour 42,0 °C
    . "\x4C\x06\x00\x00\x12\x00"        // énergie au 31/10 (mémoire 1) : 120 000 kWh
    . "\x42\x6C\x5F\x3A"                // date de la mémoire 1 : 31/10/2026
    . "\x02\xFD\x17\x00\x00";           // erreurs : aucune
$chaleur = chr(strlen($chaleur)) . $chaleur;
$lc = ConnectWmbus::lire($chaleur);
$ch = $lc['champs'];
verif(!$lc['chiffre'] && $lc['type_libelle'] === 'chaleur' && $lc['fabricant'] === 'LAR' && ($ch['energie'][0] ?? null) == 123456 && ($ch['volume'][0] ?? null) == 12345.67
   && ($ch['puissance'][0] ?? null) == 15 && ($ch['debit'][0] ?? null) == 1.234 && ($ch['temperature_depart'][0] ?? null) == 70.5
   && ($ch['temperature_retour'][0] ?? null) == 42 && ($ch['energie_hist1'][0] ?? null) == 120000 && ($ch['date_hist1'][2] ?? '') === '2026-10-31',
      'compteur de chaleur en clair : énergie, volume, puissance, débit, températures, mémoire du 31/10', implode(', ', array_keys($ch)));
$avecCrc = ConnectWmbus::avecCrcA($chaleur);
$abime = $avecCrc; $abime[20] = chr(ord($abime[20]) ^ 0x01);
verif(strlen($avecCrc) === ConnectWmbus::longueurA(ord($chaleur[0])) && ConnectWmbus::sansCrc($avecCrc) === $chaleur
   && ConnectWmbus::sansCrc($abime) === null && ConnectWmbus::sansCrc($chaleur) === $chaleur,
      'CRC radio (EN 13757-4) : retirés s\'ils sont justes, trame abîmée écartée, trame sans CRC gardée');
$im = "\xA5\x42\x03" . chr(strlen($chaleur) - 1) . substr($chaleur, 1) . "\x50";              // sans L, avec RSSI
$am = "\xFF\x03" . chr(strlen($chaleur) - 1) . substr($chaleur, 1) . "\x20";
$am .= chr(array_reduce(str_split($am), fn($x, $c) => $x ^ ord($c), 0));
$bruit = "\x00\x13\xA5\x01\x83\x01\x00";                                                         // réponse à une commande, octets perdus
foreach (['iM871A' => $bruit . $im . $im, 'Amber (commande)' => $am, 'Amber (transparent)' => "\x11\x22" . $avecCrc,
          'CUL' => "V 1.67 CUL868\r\nb" . strtoupper(bin2hex($avecCrc)) . "\r\n"] as $q => $flux) {
    $ex = ConnectWmbus::extraire($flux);
    verif(count($ex) >= 1 && $ex[0]['trame'] === $chaleur, "récepteur $q : télégramme retrouvé", count($ex) . ' trame(s)' . (isset($ex[0]['rssi']) ? ", RSSI {$ex[0]['rssi']} dBm" : ''));
}
verif(ConnectWmbus::initialisation('im871a', 'T1') === ["\xA5\x01\x03\x03\x00\x02\x03"] && ConnectWmbus::initialisation('cul', 'C1') === ["X21\r\n", "brc\r\n"]
   && strlen(ConnectWmbus::initialisation('amb8465', 'T1')[0]) === 5, 'réglage du mode radio envoyé à la clé (iM871A, Amber, CUL)');

titre('wM-Bus : inventaire du matériel (fausse arborescence /sys)');
$sys = sys_get_temp_dir() . '/larka-sys-' . bin2hex(random_bytes(3));
$appareil = function (string $tty, string $chemin, array $usb) use ($sys) {
    @mkdir("$sys/sys/devices/$chemin/$tty", 0700, true);
    foreach ($usb as $f => $v) file_put_contents("$sys/sys/devices/" . dirname($chemin) . "/$f", "$v\n");
    @mkdir("$sys/sys/class/tty/$tty", 0700, true);
    @symlink("$sys/sys/devices/$chemin/$tty", "$sys/sys/class/tty/$tty/device");
    @mkdir("$sys/dev", 0700, true);
    touch("$sys/dev/$tty");
};
$appareil('ttyUSB0', 'pci0/usb1/1-1/1-1:1.0', ['idVendor' => '10c4', 'idProduct' => '87ed', 'manufacturer' => 'IMST GmbH', 'product' => 'iM871A-USB']);
$appareil('ttyUSB1', 'pci0/usb1/1-2/1-2:1.0', ['idVendor' => '0403', 'idProduct' => '6001', 'manufacturer' => 'FTDI', 'product' => 'AMB8465-M']);
$appareil('ttyACM0', 'pci0/usb1/1-3/1-3:1.0', ['idVendor' => '0483', 'idProduct' => '5740', 'product' => 'STM32 Virtual ComPort']);
$appareil('ttyUSB2', 'pci0/usb1/1-4/1-4:1.0', ['idVendor' => '1a86', 'idProduct' => '7523']);
$inv = []; foreach (ConnectSerie::ports($sys) as $p) $inv[$p['port']] = $p;
verif(($inv['/dev/ttyUSB0']['modele'] ?? '') === 'im871a' && ($inv['/dev/ttyUSB0']['debit'] ?? 0) === 57600
   && ($inv['/dev/ttyUSB1']['modele'] ?? '') === 'amb8465' && isset($inv['/dev/ttyUSB2']) && $inv['/dev/ttyUSB2']['modele'] === null
   && str_contains((string)($inv['/dev/ttyACM0']['conseil'] ?? ''), '1700'),
      'clés reconnues par leur identifiant USB : iM871A, Amber (nom du produit) ; CH340 inconnu ; concentrateur LoRa → conseil', count($inv) . ' port(s)');
verif((ConnectSerie::recepteur($sys)['port'] ?? '') === '/dev/ttyUSB0', '« automatique » : le premier récepteur pris en charge est choisi');
verif(ConnectSerie::portSaisi('/dev/ttyUSB0') && ConnectSerie::portSaisi('COM7') && ConnectSerie::portSaisi('/dev/serial/by-id/usb-IMST_iM871A-if00-port0')
   && !ConnectSerie::portSaisi('/etc/passwd') && !ConnectSerie::portSaisi('/dev/../etc/shadow') && !ConnectSerie::portSaisi('COM1; calc'),
      'port saisi : un périphérique série seulement (ni fichier quelconque, ni commande)');
$ex = null;
try { ConnectSerie::ecouter('/etc/passwd', 9600, 1); } catch (RuntimeException $e) { $ex = $e->getMessage(); }
verif($ex !== null && str_contains($ex, 'refusé'), 'écoute d\'un chemin qui n\'est pas un port : refusée avant toute ouverture');

titre('wM-Bus : écoute réelle d\'un port série simulé (pseudo-terminal)');
$py = trim((string)shell_exec('command -v python3 2>/dev/null'));
if ($py === '' || PHP_OS_FAMILY === 'Windows' || !function_exists('proc_open')) {
    echo "  (non éprouvé ici : python3 absent — il simule la clé USB)\n";
} else {
    // La « clé » : un pseudo-terminal qui répète un télégramme iM871A, et garde ce qu'on lui envoie.
    $simu = sys_get_temp_dir() . '/larka-cle-' . bin2hex(random_bytes(3));
    @mkdir($simu, 0700, true);
    file_put_contents("$simu/cle.py", <<<'PY'
import os, pty, sys, time, select, binascii, tty
m, s = pty.openpty()
tty.setraw(s)
print(os.ttyname(s), flush=True)
trames = [binascii.unhexlify(x) for x in sys.argv[1].split(',')]
fin = time.time() + float(sys.argv[2]); recu = b''; prochain = 0; i = 0
while time.time() < fin:
    r, _, _ = select.select([m], [], [], 0.05)
    if r:
        try: recu += os.read(m, 4096)
        except OSError: pass
    if time.time() >= prochain:
        os.write(m, trames[i % len(trames)]); i += 1; prochain = time.time() + 0.3
        open(sys.argv[3], 'wb').write(recu)
PY);
    $imOms = "\xA5\x42\x03" . chr(strlen($oms) - 1) . substr($oms, 1) . "\x60";
    $pcle = proc_open([$py, '-I', "$simu/cle.py", bin2hex($im) . ',' . bin2hex($imOms), '45', "$simu/recu.bin"],
                      [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', "$simu/erreurs.txt", 'a']], $tubesCle);
    $processus[] = $pcle;
    $pts = trim((string)fgets($tubesCle[1]));
    verif(str_starts_with($pts, '/dev/pts/'), 'clé simulée prête', $pts);
    $pil = ConnectPilotes::creer(['Id' => 1, 'Nom' => 'Compteurs radio', 'Protocole' => 'wmbus', 'Hote' => '', 'Port' => 0, 'Identifiant' => '',
        'params' => ['port' => $pts, 'modele' => 'im871a', 'mode' => 'T1', 'ecoute_secondes' => 5], 'secret' => ['cles_aes' => '12345678=0102030405060708090A0B0C0D0E0F11'],
        '_tenant' => 'epreuve']);
    $pts1 = [['Id' => 1, 'Adresse' => ['compteur' => '12345678', 'champ' => 'energie']], ['Id' => 2, 'Adresse' => ['compteur' => '12345678', 'champ' => 'volume']],
             ['Id' => 3, 'Adresse' => ['compteur' => '99999999', 'champ' => 'volume']], ['Id' => 4, 'Adresse' => ['compteur' => '12345678', 'champ' => 'rssi']]];
    $t0 = microtime(true);
    $lu = $pil->lire($pts1);
    $duree = microtime(true) - $t0;
    // Deux compteurs portent le même identifiant (chaleur LAR, gaz ELS) : le dernier entendu l'emporte.
    $v2 = $lu[2]['valeur'] ?? null;
    verif(in_array($v2, [12345.67, 28504.27], true) && array_key_exists('valeur', $lu[3] ?? []) && $lu[3]['valeur'] === null && str_contains((string)($lu[3]['erreur'] ?? ''), 'non entendu')
          && $duree >= 4.5 && $duree < 9, 'lecture : le port est réglé (stty), écouté 5 s, les compteurs entendus sont lus', sprintf('%.1f s, volume %s', $duree, (string)$v2));
    $init = (string)@file_get_contents("$simu/recu.bin");
    verif(str_contains($init, "\xA5\x01\x03\x03\x00\x02\x03"), 'la clé a reçu le réglage « mode T1 » au début de l\'écoute');
    $dec = $pil->decouvrir();
    $codes = array_column($dec, 'code');
    verif(array_intersect(['12345678.volume', '12345678.rssi'], $codes) === ['12345678.volume', '12345678.rssi'] || (in_array('12345678.volume', $codes, true) && in_array('12345678.rssi', $codes, true)),
          '« Découvrir » : compteurs entendus et leurs données proposés en points', count($dec) . ' point(s)');

    // ── Le PC releveur : la clé est branchée sur LUI ──
    $pc = sys_get_temp_dir() . '/larka-pc-' . bin2hex(random_bytes(3));
    @mkdir("$pc/lib/connect/pilotes", 0700, true);
    copy("$racine/api/connect/releveur/releveur.php", "$pc/releveur.php");
    copy("$racine/api/connect/Pilote.php", "$pc/lib/connect/Pilote.php");
    foreach (glob("$racine/api/connect/pilotes/*.php") ?: [] as $f) copy($f, "$pc/lib/connect/pilotes/" . basename($f));
    file_put_contents("$pc/config.json", json_encode(['larka' => 'http://127.0.0.1:9', 'jeton' => 'lrk1.epreuve.1.' . str_repeat('ab', 24)]));
    file_put_contents("$pc/mission.json", json_encode(['maintenant' => date('Y-m-d H:i:s'), 'connexions' => [[
        'id' => 7, 'nom' => 'Compteurs radio du local', 'protocole' => 'wmbus', 'hote' => '', 'port' => 0, 'identifiant' => '', 'acces' => 'direct', 'wifi' => null,
        'parametres' => ['port' => $pts, 'modele' => 'im871a', 'mode' => 'T1', 'ecoute_secondes' => 5], 'secrets' => ['cles_aes' => '12345678=0102030405060708090A0B0C0D0E0F11'],
        'points' => [['Id' => 71, 'Nom' => 'Volume', 'Code' => 'V', 'Nature' => 'compteur', 'Unite' => 'm³', 'Adresse' => ['compteur' => '12345678', 'champ' => 'volume']]]]]]));
    exec('cd ' . escapeshellarg($pc) . ' && ' . escapeshellarg(PHP_BINARY) . ' releveur.php tester --sans-wifi 2>&1', $so, $sc);
    $sortie = implode("\n", $so);
    verif($sc === 0 && str_contains($sortie, 'Compteurs radio du local : 1 / 1 point(s) lu(s)'), 'PC releveur : la clé branchée sur lui est écoutée, le compteur lu (sans réseau)', $sc === 0 ? '' : substr($sortie, -300));
    $so = [];
    exec('cd ' . escapeshellarg($pc) . ' && LARKA_RADIO_RACINE=' . escapeshellarg($sys) . ' ' . escapeshellarg(PHP_BINARY) . ' releveur.php materiel 2>&1', $so, $sc);
    $sortie = implode("\n", $so);
    $mat = json_decode((string)@file_get_contents("$pc/materiel.json"), true) ?: [];
    verif(str_contains($sortie, '/dev/ttyUSB0 — IMST iM871A-USB') && str_contains($sortie, 'pris en charge') && count($mat['ports'] ?? []) === 4,
          '« releveur materiel » : clés listées, et gardées pour partir avec les relevés');
    $propre = ConnectReleveur::materiel(['quand' => 'hier', 'ports' => [['port' => '/dev/ttyUSB0<script>', 'vid' => '10c4', 'pid' => 'zz', 'modele' => 'pirate',
                                                                          'libelle' => str_repeat('x', 500)]]]);
    verif($propre['ports'][0]['port'] === '/dev/ttyUSB0script' && $propre['ports'][0]['pid'] === null && $propre['ports'][0]['modele'] === null
          && mb_strlen($propre['ports'][0]['libelle']) === 120 && preg_match('/^\d{4}-/', $propre['quand']), 'matériel reçu d\'un PC : champs connus, bornés, sans balise');
    effacer($pc);
    @proc_terminate($pcle);
    effacer($simu);
}
effacer($sys);

// ═════════════════════════════════════════════════════════════════════════════
// FIN
// ═════════════════════════════════════════════════════════════════════════════
echo "\n───────────────────────────────────────────────────────────────────────\n";
printf("  Bilan : %d vérification(s) réussie(s), %d échec(s)\n", $ok, $ko);
echo $ko ? "  ❌ Des vérifications échouent.\n" : "  ✅ Radio : tout est vérifié.\n";
exit($ko ? 1 : 0);

<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Épreuve « Larka Connect » — les équipements du bâtiment, de bout en bout.
 *
 * Larka parle à des équipements qu'il ne connaît pas d'avance : un automate qui
 * range ses mots à l'envers, un export CSV en Windows-1252 avec deux lignes de
 * bandeau, une borne qui redémarre au milieu d'une charge. L'épreuve vérifie
 * que chaque morceau tient, puis le tout, sur une installation jetable :
 *
 *   1. pièces seules (ni base ni réseau) : codage Modbus dans les quatre ordres,
 *      filtres MQTT, chemins JSON, nombres « à la française », calendriers des
 *      relevés (changements d'heure compris), analyse CSV, dossiers CSV permis ;
 *   2. collecte réelle contre des équipements simulés EN PHP sur 127.0.0.1
 *      (Modbus TCP, API REST, dossier d'exports) : valeurs mises à l'échelle,
 *      registres absents, esclave muet, règles d'alarme (hystérésis, absence,
 *      qualité), demande d'intervention automatique (une seule pour une rafale),
 *      relevé automatique vers le module Énergie, index en baisse en anomalie ;
 *   3. le service OCPP réel face à une borne simulée : borne inconnue, mauvais
 *      mot de passe, charge complète, défaut → alarme et demande, commande à
 *      distance, charge perdue close ;
 *   4. l'API : écriture refusée sans confirmation d'identité (428), mot de
 *      passe faux (403), trop d'essais (429), plage permise (422), point non
 *      inscriptible et Visionneur (403), trace dans le journal de sécurité,
 *      aucun secret renvoyé, module fermé (409) ;
 *   5. les déclencheurs (cron, systemd, start.sh, lanceur Windows, PHP-FPM,
 *      relais Nginx /ocpp/).
 *
 *   php outils/epreuves/test-connect.php
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
function port_libre(): int
{
    $s = stream_socket_server('tcp://127.0.0.1:0', $e, $es);
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

if (!extension_loaded('pdo_sqlite')) { echo "\n  ⏭️  pdo_sqlite absent : épreuve sautée.\n"; exit(0); }

$t = sys_get_temp_dir() . '/larka-connect-' . bin2hex(random_bytes(4));
@mkdir("$t/csv/permis/sous", 0700, true);
@mkdir("$t/csv/ailleurs", 0700, true);
$processus = [];
register_shutdown_function(function () use (&$processus, $t) {
    foreach ($processus as $p) { if (is_resource($p)) { @proc_terminate($p); @proc_close($p); } }
    // LARKA_EPREUVE_GARDER=1 : l'installation jetable est gardée pour examen.
    if (getenv('LARKA_EPREUVE_GARDER')) { echo "  (installation gardée : $t)\n"; return; }
    effacer($t);
});

// ═════════════════════════════════════════════════════════════════════════════
// 1. PIÈCES SEULES
// ═════════════════════════════════════════════════════════════════════════════
$GLOBALS['_cfgEpreuve'] = ['connect' => ['csv_racines' => ["$t/csv/permis"]]];
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
require_once "$racine/api/connect/Planif.php";

titre('Modbus : codage des valeurs');
$allerRetour = true; $detail = '';
foreach ([['uint16', 54321.0], ['int16', -1234.0], ['uint32', 3000000000.0], ['int32', -123456789.0], ['float32', 21.5],
          ['float64', 1234567.891], ['uint64', 81985529216486895.0], ['int64', -4294967297.0]] as [$type, $v]) {
    foreach (['ABCD', 'CDAB', 'BADC', 'DCBA'] as $ordre) {
        $r = ConnectPiloteModbus::decoder(ConnectPiloteModbus::encoder($v, $type, $ordre), $type, $ordre);
        if ($r === null || abs($r - $v) > abs($v) * 1e-12 + 1e-9) { $allerRetour = false; $detail = "$type $ordre : $v → $r"; }
    }
}
verif($allerRetour, 'aller-retour de 8 types dans les 4 ordres de mots', $detail);
verif(ConnectPiloteModbus::encoder(21.5, 'float32', 'ABCD') === [0x41AC, 0x0000], 'float32 21,5 = 41AC 0000 (ABCD)');
verif(ConnectPiloteModbus::encoder(123456789, 'uint32', 'CDAB') === [0xCD15, 0x075B]
   && ConnectPiloteModbus::encoder(123456789, 'uint32', 'BADC') === [0x5B07, 0x15CD]
   && ConnectPiloteModbus::encoder(123456789, 'uint32', 'DCBA') === [0x15CD, 0x5B07], 'uint32 123456789 en CDAB, BADC, DCBA');
verif(ConnectPiloteModbus::decoder([0xCD15, 0x075B], 'uint32', 'ABCD') === 3440707419.0,
      'le piège CDAB : lu en ABCD, l\'index devient absurde');
verif(ConnectPiloteModbus::decoder(ConnectPiloteModbus::encoder(22.2, 'float32'), 'float32') === 22.2, 'float32 22,2 rendu 22,2 (pas 22,200000762…)');
verif(ConnectPiloteModbus::decoder([0b0000000000100100], 'bool', 'ABCD', 5) === 1.0 && ConnectPiloteModbus::decoder([0b100100], 'bool', 'ABCD', 4) === 0.0,
      'un bit d\'un registre');
$m = new ReflectionMethod('ConnectPiloteModbus', 'normaliser');
$m->setAccessible(true);
$pm = new ConnectPiloteModbus(['Id' => 1, 'Nom' => 'x', 'Protocole' => 'modbus', 'Hote' => '127.0.0.1', 'Port' => 502, 'Identifiant' => '',
                               'params' => ['base_adresse' => '1', 'unite_defaut' => 3], 'secret' => [], '_tenant' => 'default']);
$a1 = $m->invoke($pm, ['registre' => 40014]);
$a2 = $m->invoke($pm, ['table' => 'input', 'registre' => 30001, 'type' => 'float32']);
$a3 = $m->invoke($pm, ['registre' => 10]);
verif($a1['registre'] === 13 && $a1['fc'] === 3 && $a2['registre'] === 0 && $a2['fc'] === 4 && $a2['nb'] === 2 && $a3['registre'] === 9 && $a3['unite'] === 3,
      'notations 40014, 30001 ; numérotation à partir de 1 ; unit id par défaut');

titre('MQTT, chemins JSON, nombres');
verif(ConnectPiloteMqtt::correspond('larka/eau/+/up', 'larka/eau/70B3D5/up') && ConnectPiloteMqtt::correspond('larka/#', 'larka/eau/x/up')
   && !ConnectPiloteMqtt::correspond('larka/+/up', 'larka/eau/x/up') && ConnectPiloteMqtt::correspond('#', 'a/b'), 'filtres « + » et « # »');
$x = ConnectPiloteMqtt::extraire(['sujet' => 'larka/eau/+/up', 'chemin' => 'mesures.index_m3', 'filtre_chemin' => 'type', 'filtre_valeur' => 'eau',
                                  'horodatage_chemin' => 'ts'], 'larka/eau/42/up', '{"type":"eau","ts":1793268000000,"mesures":{"index_m3":"18 452,317"}}', '2026-01-01 00:00:00');
$y = ConnectPiloteMqtt::extraire(['sujet' => 'larka/eau/+/up', 'chemin' => 'mesures.index_m3', 'filtre_chemin' => 'type', 'filtre_valeur' => 'eau'],
                                 'larka/eau/42/up', '{"type":"gaz","mesures":{"index_m3":1}}', '2026-01-01 00:00:00');
verif($x !== null && $x['valeur'] === 18452.317 && $x['horodatage'] === date('Y-m-d H:i:s', 1793268000) && $y === null,
      'extraction : chemin, filtre, horodatage en millisecondes', $x ? (string)$x['valeur'] : 'null');
$doc = ['data' => ['items' => [['id' => 'A', 'v' => 1], ['id' => 'B', 'v' => ['x' => 2]]]]];
verif(ConnectPiloteBase::suivreChemin($doc, 'data.items.[id=B].v.x') === 2 && ConnectPiloteBase::suivreChemin($doc, 'data.items.0.v') === 1
   && ConnectPiloteBase::suivreChemin($doc, 'data.rien') === null, 'chemins pointés, filtre [id=B]');
// « a.*.b » : les trois phases d'un départ additionnées (centrale de mesure multi-départs).
$pe6 = ['Connector' => [['Channel' => [['E' => 212110.48, 'P' => 14030.08], ['E' => 202815.77, 'P' => 25528.78], ['E' => 195019.88, 'P' => 13466.8]]]]];
verif(round((float)ConnectPiloteBase::sommeSiListe(ConnectPiloteBase::suivreChemin($pe6, 'Connector.0.Channel.*.E')), 2) === 609946.13
   && round((float)ConnectPiloteBase::sommeSiListe(ConnectPiloteBase::suivreChemin($pe6, 'Connector.0.Channel.*.P')), 2) === 53025.66
   && ConnectPiloteBase::suivreChemin($pe6, 'Connector.0.Channel.1.P') === 25528.78 && ConnectPiloteBase::suivreChemin($pe6, 'Connector.3.Channel.*.E') === null,
      'chemin « * » : les trois phases d\'un départ additionnées');
verif(ConnectPiloteBase::nombre('1 234,5') === 1234.5 && ConnectPiloteBase::nombre("1\u{202F}234,5") === 1234.5 && ConnectPiloteBase::nombre('1.234.567,8') === 1234567.8
   && ConnectPiloteBase::nombre('3.2e3') === 3200.0 && ConnectPiloteBase::nombre('abc') === null && ConnectPiloteBase::nombre(true) === 1.0,
      'nombres : « 1 234,5 », espace fine, « 1.234.567,8 », « 3.2e3 »');
verif(ConnectPiloteBacnet::typeObjet('analog-value') === 2 && ConnectPiloteBacnet::nomObjet(0) === 'analog-input'
   && ConnectPiloteBacnet::propriete('present-value') === 85, 'BACnet : types d\'objets et propriétés');

titre('Calendriers des relevés automatiques');
$tzAvant = date_default_timezone_get();
date_default_timezone_set('Europe/Paris');
$tz = new DateTimeZone('Europe/Paris');
$p = fn(array $l, string $apres) => ConnectPlanif::prochaine($l, new DateTimeImmutable($apres, $tz))?->format('Y-m-d H:i');
verif($p(['Frequence' => 'quotidien', 'Heure' => '23:55'], '2026-10-27 10:00') === '2026-10-27 23:55', 'chaque jour à 23 h 55');
verif($p(['Frequence' => 'hebdomadaire', 'Heure' => '23:55', 'JoursSemaine' => '1,5'], '2026-10-27 10:00') === '2026-10-30 23:55', 'lundi et vendredi');
verif($p(['Frequence' => 'mensuel', 'Heure' => '23:55', 'JourMois' => -1], '2026-02-10 08:00') === '2026-02-28 23:55', 'dernier jour du mois (février)');
verif($p(['Frequence' => 'quotidien', 'Heure' => '06:00', 'MoisActifs' => '1,2,3,4,10,11,12'], '2026-05-15 08:00') === '2026-10-01 06:00',
      'saison de chauffe : rien de mai à septembre');
verif($p(['Frequence' => 'personnalise', 'ExpressionCron' => '0 6 1,15 * *'], '2026-10-27 08:00') === '2026-11-01 06:00', 'cron « le 1er et le 15 à 6 h »');
verif($p(['Frequence' => 'personnalise', 'ExpressionCron' => '*/15 8-9 * * 1-5'], '2026-10-30 09:50') === '2026-11-02 08:00', 'cron jours ouvrés, quart d\'heure');
verif($p(['Frequence' => 'quotidien', 'Heure' => '02:30'], '2026-03-29 00:00') === '2026-03-29 03:30', 'passage à l\'heure d\'été : 2 h 30 n\'existe pas → 3 h 30');
$h1 = ConnectPlanif::prochaine(['Frequence' => 'quotidien', 'Heure' => '02:30'], new DateTimeImmutable('2026-10-25 00:00', $tz));
$h2 = $h1 ? ConnectPlanif::prochaine(['Frequence' => 'quotidien', 'Heure' => '02:30'], $h1) : null;
verif($h1 && $h2 && $h1->format('Y-m-d H:i P') === '2026-10-25 02:30 +02:00' && $h2->format('Y-m-d H:i') === '2026-10-26 02:30',
      'retour à l\'heure d\'hiver : 2 h 30 exécuté une seule fois');
verif(ConnectPlanif::cronValide('0 6 * * 1-5') && !ConnectPlanif::cronValide('61 * * * *') && !ConnectPlanif::cronValide('* * *'), 'expressions cron validées');
$lib = ConnectPlanif::libelle(['Frequence' => 'hebdomadaire', 'Heure' => '08:00', 'JoursSemaine' => '1,2,3,4,5', 'MoisActifs' => '1,2,3,4,10,11,12']);
verif(str_contains($lib, 'lundi au vendredi') && str_contains($lib, 'd\'octobre à avril'), 'libellé en français', "« $lib »");
// Interface anglaise : le serveur compose le même calendrier en anglais (« &langue=en »).
$libEn = ConnectPlanif::libelle(['Frequence' => 'hebdomadaire', 'Heure' => '08:00', 'JoursSemaine' => '1,2,3,4,5', 'MoisActifs' => '1,2,3,4,10,11,12'], true);
$libEn2 = ConnectPlanif::libelle(['Frequence' => 'mensuel', 'Heure' => '23:55', 'JourMois' => 22], true);
verif($libEn === 'Every week, from Monday to Friday at 08:00, from October to April' && $libEn2 === 'The 22nd of every month at 23:55',
      'libellé en anglais', "« $libEn » · « $libEn2 »");
date_default_timezone_set($tzAvant);

titre('Fichiers CSV');
$csv = new ConnectPiloteCsv(['Id' => 1, 'Nom' => 'x', 'Protocole' => 'csv', 'Hote' => '', 'Port' => 0, 'Identifiant' => '',
                             'params' => ['format' => 'long'], 'secret' => [], '_tenant' => 'default']);
$brut = mb_convert_encoding("Export automatique GTB - Site : Lycée\n\nDate;Heure;Repère;Libellé;Valeur;Unité;Etat\n"
    . "27/10/2026;10:00;T_HALL;Température hall;19,5;°C;Normal\n27/10/2026;10:00;CPT_EAU;Index eau;1 234,5;m³;Normal\n"
    . "27/10/2026;10:05;T_HALL;Température hall;19,7;°C;Défaut capteur\n27/10/2026;10:05;CPT", 'Windows-1252', 'UTF-8');
$an = $csv->analyser($brut);
verif($an['ok'] && $an['encodage'] === 'windows-1252' && $an['entete'] === 2 && count($an['lignes']) === 3 && $an['incompletes'] === 1
   && $an['lignes'][0]['Repère'] === 'T_HALL', 'Windows-1252, bandeau et ligne vide, ligne tronquée écartée');
verif(!$csv->analyser("a,b\n1,2")['ok'], 'colonnes attendues absentes : refus motivé');
[$okDos] = ConnectPiloteCsv::dossierAutorise("$t/csv/permis/sous");
[$ko1, $r1] = ConnectPiloteCsv::dossierAutorise("$t/csv/ailleurs");
[$ko2] = ConnectPiloteCsv::dossierAutorise("$t/csv/permis/../ailleurs");
@symlink("$t/csv/ailleurs", "$t/csv/permis/lien");
[$ko3] = ConnectPiloteCsv::dossierAutorise("$t/csv/permis/lien");
[$ko4] = ConnectPiloteCsv::dossierAutorise('/etc');
verif($okDos !== null && $ko1 === null && $ko2 === null && $ko3 === null && $ko4 === null && str_contains((string)$r1, 'hors des racines'),
      'dossiers : sous-dossier permis ; à côté, « .. », lien symbolique et /etc refusés');
verif(str_contains(ConnectPiloteBase::demandeIt('192.168.20.15', 502, 'TCP'), '502/tcp'), 'demande au service informatique : port et protocole');

// ═════════════════════════════════════════════════════════════════════════════
// 2. INSTALLATION JETABLE ET ÉQUIPEMENTS SIMULÉS
// ═════════════════════════════════════════════════════════════════════════════
copier_dossier("$racine/api", "$t/api");
copy("$racine/version.json", "$t/version.json");
copy("$racine/router.php", "$t/router.php");
@mkdir("$t/data/run", 0700, true);
@mkdir("$t/data/connect/imports/epreuve", 0700, true);
@mkdir("$t/sim", 0700, true);
$ports = ['modbus' => port_libre(), 'rest' => port_libre(), 'ocpp' => port_libre(), 'api' => port_libre()];
$configurer = function (bool $actif) use ($t, $ports): void {
    file_put_contents("$t/config.json", json_encode([
        'serveur' => ['env' => 'dev'],
        'base_de_donnees' => ['driver' => 'sqlite', 'path' => 'data/gmao.db'],
        'connect' => ['actif' => $actif, 'ocpp' => ['actif' => true, 'hote' => '127.0.0.1', 'port' => $ports['ocpp']]],
        'push' => ['actif' => false],
    ], JSON_PRETTY_PRINT));
};
$configurer(true);
$php = escapeshellarg(PHP_BINARY);

// Automate Modbus TCP : 50 registres (unit id 1), 0x02 au-delà, unit id 9 muet (0x0B).
file_put_contents("$t/sim/modbus.php", <<<'PHP'
<?php
[, $port, $duree] = $argv;
$regs = array_fill(0, 50, 0);
$regs[0] = 215;                                         // 21,5 °C en dixièmes
$regs[1] = 0x10000 - 10;                                // -10 (int16)
[$hi, $lo] = array_values(unpack('n2', pack('G', 12345.678)));
$regs[2] = $hi; $regs[3] = $lo;                         // float32 ABCD
$regs[4] = 123456789 & 0xFFFF; $regs[5] = (123456789 >> 16) & 0xFFFF;   // uint32 CDAB : index en Wh
$regs[10] = 220;                                        // consigne 22,0 °C (inscriptible)
$srv = stream_socket_server("tcp://127.0.0.1:$port", $e, $es);
$fin = microtime(true) + (float)$duree;
$clients = [];
while (microtime(true) < $fin) {
    $r = array_merge([$srv], array_values($clients)); $w = $x = null;
    if (@stream_select($r, $w, $x, 0, 200000) < 1) continue;
    foreach ($r as $s) {
        if ($s === $srv) { $c = @stream_socket_accept($srv, 0); if ($c) $clients[(int)$c] = $c; continue; }
        $h = @fread($s, 7);
        if ($h === false || strlen($h) < 7) { @fclose($s); unset($clients[(int)$s]); continue; }
        $m = unpack('ntid/npid/nlen/Cunit', $h);
        $pdu = (string)@fread($s, max(1, $m['len'] - 1));
        $fc = ord($pdu[0] ?? "\0");
        if ($m['unit'] === 9) $rep = chr($fc | 0x80) . chr(0x0B);
        elseif ($fc === 3 || $fc === 4) {
            $d = unpack('ndeb/nnb', substr($pdu, 1, 4));
            if ($d['deb'] + $d['nb'] > 50) $rep = chr($fc | 0x80) . chr(0x02);
            else { $rep = chr($fc) . chr(2 * $d['nb']); for ($i = 0; $i < $d['nb']; $i++) $rep .= pack('n', $regs[$d['deb'] + $i]); }
        } elseif ($fc === 6) {
            $d = unpack('nreg/nval', substr($pdu, 1, 4));
            if ($d['reg'] >= 50) $rep = chr(0x86) . chr(0x02); else { $regs[$d['reg']] = $d['val']; $rep = $pdu; }
        } elseif ($fc === 16) {
            $d = unpack('ndeb/nnb', substr($pdu, 1, 4));
            for ($i = 0; $i < $d['nb']; $i++) $regs[$d['deb'] + $i] = unpack('n', substr($pdu, 6 + 2 * $i, 2))[1];
            $rep = substr($pdu, 0, 5);
        } else $rep = chr($fc | 0x80) . chr(0x01);
        @fwrite($s, pack('nnnC', $m['tid'], 0, strlen($rep) + 1, $m['unit']) . $rep);
    }
}
PHP);

// Superviseur REST : jeton Bearer, liste dans « data », une consigne inscriptible.
file_put_contents("$t/sim/rest.php", <<<'PHP'
<?php
header('Content-Type: application/json');
$f = __DIR__ . '/rest-etat.json';
$etat = is_file($f) ? json_decode((string)file_get_contents($f), true) : ['CONS' => 20.0];
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer jeton-epreuve') { http_response_code(401); echo '{"erreur":"jeton"}'; return true; }
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($uri === '/api/points' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['data' => [
        ['id' => 'T1', 'nom' => 'Température hall', 'valeur' => 19.5, 'unite' => '°C', 'qualite' => 'bonne'],
        ['id' => 'CONS', 'nom' => 'Consigne hall', 'valeur' => $etat['CONS'], 'unite' => '°C', 'ecriture' => true],
        ['id' => 'DEF', 'nom' => 'Défaut pompe', 'valeur' => false],
    ]]);
    return true;
}
if (preg_match('#^/api/points/CONS$#', (string)$uri) && $_SERVER['REQUEST_METHOD'] === 'PUT') {
    $b = json_decode((string)file_get_contents('php://input'), true);
    $etat['CONS'] = (float)($b['valeur'] ?? 0);
    file_put_contents($f, json_encode($etat));
    echo json_encode(['ok' => true]);
    return true;
}
http_response_code(404); echo '{}'; return true;
PHP);

// Exports CSV : un fichier, son double sous un autre nom, un fichier d'un autre format.
$exportCsv = mb_convert_encoding("Export automatique GTB - Site : Lycée - Généré le 27/10/2026 10:05\n\nDate;Heure;Repère;Libellé;Valeur;Unité;Etat\n"
    . date('d/m/Y;H:i', time() - 600) . ";T_HALL;Température hall;19,5;°C;Normal\n"
    . date('d/m/Y;H:i', time() - 300) . ";T_HALL;Température hall;19,7;°C;Normal\n"
    . date('d/m/Y;H:i', time() - 300) . ";CPT_EAU;Index eau;1 234,5;m³;Normal\n"
    . date('d/m/Y;H:i', time() - 300) . ";CPT", 'Windows-1252', 'UTF-8');
file_put_contents("$t/data/connect/imports/epreuve/GTB_1.csv", $exportCsv);
file_put_contents("$t/data/connect/imports/epreuve/GTB_1_copie.csv", $exportCsv);
file_put_contents("$t/data/connect/imports/epreuve/AUTRE.csv", "x;y\n1;2\n");
foreach (glob("$t/data/connect/imports/epreuve/*.csv") as $f) touch($f, time() - 120);

$lancer = function (string $cmd) use (&$processus, $t) {
    $p = proc_open("exec $cmd", [0 => ['file', '/dev/null', 'r'], 1 => ['file', "$t/sim/sortie.log", 'a'], 2 => ['file', "$t/sim/sortie.log", 'a']], $tubes, $t);
    $processus[] = $p;
    return $p;
};
$lancer("$php " . escapeshellarg("$t/sim/modbus.php") . " {$ports['modbus']} 120");
$lancer("$php -S 127.0.0.1:{$ports['rest']} " . escapeshellarg("$t/sim/rest.php"));

// Scénario : exécuté DANS l'installation jetable (sa config, sa base).
file_put_contents("$t/epreuve-connect.php", <<<'PHP'
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
$pdo = $db->getPdo();
$arg = json_decode($argv[2] ?? '{}', true) ?: [];
$sortie = [];
$cree = function (array $d) use ($depot): array {
    [$v, $err] = $depot->normaliserPasserelle($d);
    if ($err) return ['erreurs' => $err];
    return ['id' => $depot->creerPasserelle($v, 'epreuve')];
};
$point = function (array $d) use ($depot): int {
    [$v, $err] = $depot->normaliserPoint($d);
    if ($err) throw new RuntimeException(implode(' ', $err));
    return $depot->creerPoint($v, 'epreuve');
};
switch ($argv[1]) {
    case 'preparer':
        $h = password_hash('Epreuve-2026!', PASSWORD_BCRYPT);
        foreach ([['gestion.epreuve', 'Gestionnaire'], ['gestion.bis', 'Gestionnaire'], ['lecture.epreuve', 'Visionneur']] as [$l, $r]) {
            $pdo->prepare("INSERT INTO Utilisateurs (Nom, Prenom, Login, MotDePasse, Role, Provider, DateCreation, Actif) VALUES ('Épreuve', 'Test', ?, ?, ?, 'local', ?, 1)")
                ->execute([$l, $h, $r, date('Y-m-d H:i:s')]);
        }
        $sortie['compteur'] = $db->addEnergieCompteur(['nom' => 'Électricité épreuve', 'type' => 'Electricite', 'unite' => 'kWh', 'actif' => 1]);
        // Normalisation des saisies
        $sortie['sans_nom'] = $cree(['protocole' => 'modbus', 'hote' => '127.0.0.1'])['erreurs'] ?? [];
        $sortie['hote_url'] = $cree(['protocole' => 'modbus', 'nom' => 'x', 'hote' => 'http://192.168.1.2:502'])['erreurs'] ?? [];
        $sortie['csv_hors'] = $cree(['protocole' => 'csv', 'nom' => 'x', 'parametres' => ['dossier' => '/etc']])['erreurs'] ?? [];
        $sortie['rest_url'] = $cree(['protocole' => 'rest', 'nom' => 'x', 'parametres' => ['url_base' => 'ftp://x']])['erreurs'] ?? [];
        $mb = $cree(['protocole' => 'modbus', 'nom' => 'Automate épreuve', 'hote' => '127.0.0.1', 'port' => $arg['modbus'], 'intervalle' => 3,
                     'parametres' => ['unite_defaut' => 1, 'delai_secondes' => 2]])['id'];
        $sortie['intervalle'] = (int)$db->fetchOne("SELECT IntervalleSecondes FROM ConnectPasserelles WHERE Id = :i", ['i' => $mb])['IntervalleSecondes'];
        $rest = $cree(['protocole' => 'rest', 'nom' => 'Superviseur épreuve', 'secrets' => ['jeton' => 'jeton-epreuve'],
                       'parametres' => ['url_base' => 'http://127.0.0.1:' . $arg['rest'] . '/api', 'auth' => 'bearer', 'mode' => 'liste',
                                        'url_liste' => '/points', 'chemin_liste' => 'data']])['id'];
        $csv = $cree(['protocole' => 'csv', 'nom' => 'Exports épreuve', 'parametres' => ['dossier' => 'data/connect/imports/epreuve', 'format' => 'long']])['id'];
        $borne = $cree(['protocole' => 'ocpp', 'nom' => 'Borne épreuve', 'identifiant' => 'BORNE-T1', 'secrets' => ['mot_de_passe' => 'secret-borne'],
                        'parametres' => ['defaut_vers_demande' => true]])['id'];
        // Secrets : vides = inchangés, « __effacer__ » = supprimés, jamais en clair en base.
        $avant = $db->fetchOne("SELECT * FROM ConnectPasserelles WHERE Id = :i", ['i' => $rest]);
        $avant['Parametres'] = json_decode((string)$avant['Parametres'], true);
        [$v] = $depot->normaliserPasserelle(['nom' => 'Superviseur épreuve', 'secrets' => ['jeton' => '']], $avant);
        $sortie['secret_garde'] = Connect::dechiffrer($v['Secret'])['jeton'] ?? null;
        [$v] = $depot->normaliserPasserelle(['nom' => 'Superviseur épreuve', 'secrets' => ['jeton' => '__effacer__']], $avant);
        $sortie['secret_efface'] = Connect::dechiffrer($v['Secret']);
        $sortie['secret_en_clair'] = str_contains((string)$avant['Secret'], 'jeton-epreuve');
        // Points
        $ids = [
            'T' => $point(['passerelleId' => $mb, 'nom' => 'Température local', 'nature' => 'mesure', 'unite' => '°C', 'coefficient' => 0.1, 'decimales' => 1,
                           'adresse' => ['registre' => 0, 'type' => 'uint16']]),
            'NEG' => $point(['passerelleId' => $mb, 'nom' => 'Température extérieure', 'nature' => 'mesure', 'unite' => '°C', 'adresse' => ['registre' => 1, 'type' => 'int16']]),
            'F' => $point(['passerelleId' => $mb, 'nom' => 'Débit', 'adresse' => ['registre' => 2, 'type' => 'float32', 'ordre' => 'ABCD']]),
            'IDX' => $point(['passerelleId' => $mb, 'nom' => 'Index TGBT', 'nature' => 'compteur', 'unite' => 'kWh', 'coefficient' => 0.001,
                             'adresse' => ['registre' => 4, 'type' => 'uint32', 'ordre' => 'CDAB']]),
            'CONS' => $point(['passerelleId' => $mb, 'nom' => 'Consigne local', 'nature' => 'consigne', 'unite' => '°C', 'coefficient' => 0.1,
                              'adresse' => ['registre' => 10, 'type' => 'uint16'], 'ecritureAutorisee' => true, 'minEcriture' => 15, 'maxEcriture' => 28]),
            'ABSENT' => $point(['passerelleId' => $mb, 'nom' => 'Registre absent', 'adresse' => ['registre' => 60]]),
            'MUET' => $point(['passerelleId' => $mb, 'nom' => 'Esclave muet', 'adresse' => ['unite' => 9, 'registre' => 0]]),
            'R_T1' => $point(['passerelleId' => $rest, 'nom' => 'Température hall', 'unite' => '°C', 'adresse' => ['id' => 'T1']]),
            'R_CONS' => $point(['passerelleId' => $rest, 'nom' => 'Consigne hall', 'nature' => 'consigne', 'adresse' => ['id' => 'CONS'],
                                'ecritureAutorisee' => true, 'minEcriture' => 10, 'maxEcriture' => 30]),
            'R_DEF' => $point(['passerelleId' => $rest, 'nom' => 'Défaut pompe', 'nature' => 'alarme', 'adresse' => ['id' => 'DEF']]),
            'R_DISPARU' => $point(['passerelleId' => $rest, 'nom' => 'Point disparu', 'adresse' => ['id' => 'DISPARU']]),
            'C_T' => $point(['passerelleId' => $csv, 'nom' => 'Température hall (CSV)', 'adresse' => ['repere' => 'T_HALL']]),
            'C_EAU' => $point(['passerelleId' => $csv, 'nom' => 'Index eau (CSV)', 'nature' => 'compteur', 'adresse' => ['repere' => 'CPT_EAU']]),
        ];
        try { $point(['passerelleId' => $mb, 'nom' => 'x', 'adresse' => ['registre' => 1], 'ecritureAutorisee' => true]); $sortie['ecriture_sans_plage'] = false; }
        catch (RuntimeException $e) { $sortie['ecriture_sans_plage'] = $e->getMessage(); }
        // Règles
        $regle = function (array $d) use ($depot): int { [$v, $err] = $depot->normaliserRegle($d); if ($err) throw new RuntimeException(implode(' ', $err)); return $depot->enregistrerRegle(null, $v, 'epreuve'); };
        $sortie['regles'] = [
            'chaud' => $regle(['pointId' => $ids['T'], 'operateur' => 'sup', 'seuil' => 21, 'seuil2' => 20, 'gravite' => 'critique', 'creerDemande' => true, 'notifier' => false]),
            'absence' => $regle(['pointId' => $ids['R_DISPARU'], 'operateur' => 'absence', 'delaiMinutes' => 1, 'gravite' => 'mineure', 'creerDemande' => false, 'notifier' => false]),
            'qualite' => $regle(['pointId' => $ids['MUET'], 'operateur' => 'qualite', 'gravite' => 'mineure', 'creerDemande' => false, 'notifier' => false]),
        ];
        // Relevé automatique : index TGBT → compteur Énergie, chaque jour, à une heure lointaine :
        // l'épreuve force elle-même les échéances (« maintenant »), et la suivante ne doit pas
        // tomber dans la même seconde qu'elles (un relevé chaque minute franchissait parfois la minute).
        [$v, $err] = $depot->normaliserLiaison(['nom' => 'Relevé épreuve', 'pointId' => $ids['IDX'], 'cibleId' => $sortie['compteur'],
                                                'frequence' => 'quotidien', 'heure' => date('H:i', time() + 6 * 3600),
                                                'toleranceMinutes' => 15, 'facteurAnomalie' => 0]);
        $sortie['liaison'] = $err ? $err : $depot->enregistrerLiaison(null, $v, 'epreuve');
        $sortie += ['modbus' => $mb, 'rest' => $rest, 'csv' => $csv, 'borne' => $borne, 'points' => $ids];
        break;

    case 'etat':
        $sortie['points'] = [];
        foreach ($db->fetchAll("SELECT Id, Code, Nom, DerniereValeur, DerniereValeurTexte, DerniereQualite, DerniereErreur, DerniereLecture FROM ConnectPoints") as $p) $sortie['points'][(int)$p['Id']] = $p;
        $sortie['passerelles'] = [];
        foreach ($db->fetchAll("SELECT Id, Etat, DerniereErreur, DernierContact FROM ConnectPasserelles") as $p) $sortie['passerelles'][(int)$p['Id']] = $p;
        $sortie['alarmes'] = $db->fetchAll("SELECT Id, Origine, RegleId, PointId, PasserelleId, Code, Etat, DemandeId, Gravite, Message FROM ConnectAlarmes ORDER BY Id");
        $sortie['demandes'] = $db->fetchAll("SELECT d.Id, d.Urgence, d.Statut, d.Titre, u.Login FROM DemandesIntervention d LEFT JOIN Utilisateurs u ON u.Id = d.UtilisateurId ORDER BY d.Id");
        $sortie['mesures'] = [];
        foreach ($db->fetchAll("SELECT PointId, COUNT(*) AS N FROM ConnectMesures GROUP BY PointId") as $m) $sortie['mesures'][(int)$m['PointId']] = (int)$m['N'];
        $sortie['executions'] = $db->fetchAll("SELECT Id, Statut, Valeur, ValeurPrecedente, Message FROM ConnectExecutions ORDER BY Id");
        $sortie['releves'] = $db->fetchAll("SELECT Id, IndexDebut, IndexFin, Consommation, SaisieParLogin, Commentaire FROM ReleverEnergie ORDER BY Id");
        $sortie['fichiers'] = $db->fetchAll("SELECT NomFichier, Statut, NbValeurs FROM ConnectFichiers ORDER BY Id");
        $sortie['sessions'] = $db->fetchAll("SELECT Id, TransactionId, Statut, EnergieWh, Badge, Motif FROM ConnectSessionsVE ORDER BY Id");
        $sortie['commandes'] = $db->fetchAll("SELECT Id, Commande, Statut, Reponse FROM ConnectCommandes ORDER BY Id");
        break;

    case 'registre':          // l'équipement change de lui-même (simulation)
        $pa = $depot->pourPilote((int)$arg['modbus']);
        $pilote = ConnectPilotes::creer($pa);
        $r = $pilote->ecrire(['Adresse' => ['registre' => $arg['registre'], 'type' => $arg['type'] ?? 'uint16', 'ordre' => $arg['ordre'] ?? 'ABCD'],
                              'Coefficient' => 1, 'Decalage' => 0], $arg['valeur']);
        $sortie = $r;
        break;

    case 'lire':
        $pa = $depot->pourPilote((int)$arg['modbus']);
        $pt = $depot->point((int)$arg['point']);
        $r = ConnectPilotes::creer($pa)->lire([$pt]);
        $sortie = $r[(int)$arg['point']] ?? null;
        break;

    case 'echeance':          // le relevé automatique arrive à échéance, maintenant (une seule)
        $pdo->prepare("UPDATE ConnectLiaisons SET ProchaineExecution = ? WHERE Id = ?")->execute([date('Y-m-d H:i:s'), (int)$arg['liaison']]);
        $l = new ConnectLiaisons($depot, new ConnectAlarmes($depot));
        $sortie = $l->executerDues();
        break;

    case 'valider':
        $l = new ConnectLiaisons($depot, new ConnectAlarmes($depot));
        $sortie = $l->valider((int)$arg['execution'], null, 'gestion.epreuve');
        break;

    case 'tester':            // test de connexion d'un pilote (étapes)
        $pa = $depot->pourPilote((int)$arg['id']);
        $pilote = ConnectPilotes::creer($pa);
        $sortie = $pilote->tester($depot->points((int)$arg['id'], true));
        break;

    case 'explorer':
        $pa = $depot->pourPilote((int)$arg['modbus']);
        $sortie = ConnectPilotes::creer($pa)->explorer(1, 'holding', 45, 10);
        break;

    case 'meme_seconde':      // deux valeurs dans la même seconde : la dernière reste dans l'historique
        $pt = $depot->point((int)$arg['point']);
        $h = '2030-01-01 12:00:00';
        $depot->enregistrerLectures([(int)$pt['Id'] => $pt], [(int)$pt['Id'] => ['valeur' => 10.0, 'qualite' => 'bonne', 'horodatage' => $h]]);
        $pt = $depot->point((int)$arg['point']);
        $depot->enregistrerLectures([(int)$pt['Id'] => $pt], [(int)$pt['Id'] => ['valeur' => 11.0, 'qualite' => 'bonne', 'horodatage' => $h]]);
        $sortie = $db->fetchAll("SELECT Valeur FROM ConnectMesures WHERE PointId = :p AND Horodatage = :h", ['p' => (int)$pt['Id'], 'h' => $h]);
        break;

    case 'commande':
        $pdo->prepare("INSERT INTO ConnectCommandes (PasserelleId, Commande, Valeur, Statut, Demandeur, DateDemande) VALUES (?, ?, ?, 'en_attente', 'epreuve', ?)")
            ->execute([(int)$arg['borne'], $arg['commande'], $arg['valeur'] ?? null, date('Y-m-d H:i:s')]);
        $sortie = ['id' => (int)$pdo->lastInsertId()];
        break;
}
echo json_encode($sortie, JSON_UNESCAPED_UNICODE);
PHP);

$scenario = function (string $etape, array $arg = []) use ($php, $t): array {
    $out = (string)shell_exec("cd " . escapeshellarg($t) . " && $php epreuve-connect.php $etape " . escapeshellarg(json_encode($arg)) . ' 2>&1');
    $j = json_decode(trim($out), true);
    if (!is_array($j)) { echo "      (scénario « $etape » : " . mb_substr(trim($out), 0, 400) . ")\n"; return []; }
    return $j;
};
$collecte = function () use ($php, $t): array {
    exec("cd " . escapeshellarg($t) . " && $php api/outils/connect-collecte.php --forcer 2>&1", $o, $c);
    return [implode("\n", $o), $c];
};

titre('Saisie des connexions et des points');
usleep(400000);
$prep = $scenario('preparer', ['modbus' => $ports['modbus'], 'rest' => $ports['rest']]);
$ids = $prep['points'] ?? [];
verif(!empty($prep['sans_nom']) && !empty($prep['hote_url']) && !empty($prep['csv_hors']) && !empty($prep['rest_url']),
      'refus : nom absent, « http:// » dans l\'adresse, dossier hors racine, URL non HTTP');
verif(($prep['intervalle'] ?? 0) === 10, 'interrogation : 10 s au plus souvent', 'demandé 3 s → ' . ($prep['intervalle'] ?? '?') . ' s');
verif(($prep['secret_garde'] ?? null) === 'jeton-epreuve' && ($prep['secret_efface'] ?? ['x']) === [] && ($prep['secret_en_clair'] ?? true) === false,
      'secrets : chiffrés en base ; champ vide = inchangé ; « effacer » = supprimé');
verif(is_string($prep['ecriture_sans_plage'] ?? null) && str_contains((string)$prep['ecriture_sans_plage'], 'plage'),
      'écriture autorisée sans plage permise : refusée');
verif(is_int($prep['liaison'] ?? null) && count($ids) === 13, 'relevé automatique et 13 points enregistrés');

titre('Collecte (Modbus TCP, API REST, exports CSV)');
[$sortie, $code] = $collecte();
$e = $scenario('etat');
$pts = $e['points'] ?? [];
$val = fn(string $k) => isset($ids[$k], $pts[$ids[$k]]) ? $pts[$ids[$k]] : [];
verif($code === 0, 'passage de collecte sans échec technique', $code ? mb_substr($sortie, 0, 160) : '');
verif((float)($val('T')['DerniereValeur'] ?? 0) === 21.5 && (float)($val('NEG')['DerniereValeur'] ?? 0) === -10.0
   && (float)($val('F')['DerniereValeur'] ?? 0) === 12345.68 && (float)($val('IDX')['DerniereValeur'] ?? 0) === 123456.789,
      'Modbus : × 0,1 ; int16 négatif ; float32 ; uint32 CDAB × 0,001',
      implode(' | ', array_map(fn($k) => $k . '=' . ($val($k)['DerniereValeur'] ?? '∅'), ['T', 'NEG', 'F', 'IDX'])));
verif(($val('ABSENT')['DerniereQualite'] ?? '') === 'mauvaise' && str_contains((string)($val('ABSENT')['DerniereErreur'] ?? ''), '0x02')
   && (float)($val('CONS')['DerniereValeur'] ?? 0) === 22.0, 'registre absent (0x02) isolé, ses voisins lus quand même');
verif(str_contains((string)($val('MUET')['DerniereErreur'] ?? ''), '0x0B')
   && (($e['passerelles'][$prep['modbus'] ?? 0]['Etat'] ?? '') === 'partiel'), 'esclave muet (0x0B) : connexion « partielle »');
verif((float)($val('R_T1')['DerniereValeur'] ?? 0) === 19.5 && (float)($val('R_DEF')['DerniereValeur'] ?? -1) === 0.0
   && str_contains((string)($val('R_DISPARU')['DerniereErreur'] ?? ''), 'DISPARU') === true || ($val('R_DISPARU')['DerniereValeur'] ?? null) === null,
      'REST : valeurs, booléen → 0, point absent de la liste sans valeur');
$f = array_column($e['fichiers'] ?? [], 'Statut', 'NomFichier');
verif((float)($val('C_T')['DerniereValeur'] ?? 0) === 19.7 && (float)($val('C_EAU')['DerniereValeur'] ?? 0) === 1234.5
   && ($f['GTB_1.csv'] ?? $f['GTB_1_copie.csv'] ?? '') === 'incomplet' && in_array('doublon', $f, true) && ($f['AUTRE.csv'] ?? '') === 'rejete',
      'CSV : dernière valeur, double ignoré, fichier tronqué signalé, autre format rejeté', json_encode($f));
verif(($e['mesures'][$ids['C_T'] ?? 0] ?? 0) === 2, 'CSV : la série entière est historisée (2 valeurs pour T_HALL)');

titre('Règles d\'alarme et demandes d\'intervention');
$al = fn(array $e, int $regle) => array_values(array_filter($e['alarmes'] ?? [], fn($a) => (int)$a['RegleId'] === $regle));
$chaud = $al($e, (int)($prep['regles']['chaud'] ?? 0));
$di1 = (int)($chaud[0]['DemandeId'] ?? 0);
$dem = array_column($e['demandes'] ?? [], null, 'Id');
verif(count($chaud) === 1 && $chaud[0]['Etat'] === 'active' && $di1 > 0, 'seuil dépassé (21,5 > 21) : alarme et demande d\'intervention');
verif(($dem[$di1]['Login'] ?? '') === 'larka.connect' && ($dem[$di1]['Urgence'] ?? '') === 'Urgente',
      'demande créée au nom du compte technique, urgence « Urgente » (critique)');
verif(count($al($e, (int)($prep['regles']['absence'] ?? 0))) === 1 && count($al($e, (int)($prep['regles']['qualite'] ?? 0))) === 1,
      'absence de valeur et équipement muet : alarmes, sans demande');
$scenario('registre', ['modbus' => $prep['modbus'], 'registre' => 0, 'valeur' => 205]);
$collecte();
$e = $scenario('etat');
verif(($al($e, (int)$prep['regles']['chaud'])[0]['Etat'] ?? '') === 'active', 'hystérésis : à 20,5 (sous 21, au-dessus de 20) l\'alarme dure');
$scenario('registre', ['modbus' => $prep['modbus'], 'registre' => 0, 'valeur' => 195]);
$collecte();
$e = $scenario('etat');
verif(($al($e, (int)$prep['regles']['chaud'])[0]['Etat'] ?? '') === 'terminee', 'à 19,5 l\'alarme se termine d\'elle-même');
$scenario('registre', ['modbus' => $prep['modbus'], 'registre' => 0, 'valeur' => 230]);
$collecte();
$e = $scenario('etat');
$chaud = $al($e, (int)$prep['regles']['chaud']);
verif(count($chaud) === 2 && (int)$chaud[1]['DemandeId'] === $di1 && count($e['demandes'] ?? []) === 1,
      'nouvelle alarme, demande encore ouverte : rattachée à la même (pas de rafale)');

$ms = $scenario('meme_seconde', ['point' => $ids['NEG']]);
verif(count($ms) === 1 && (float)($ms[0]['Valeur'] ?? 0) === 11.0, 'deux valeurs dans la même seconde : une mesure, la dernière');

titre('Relevés automatiques vers le module Énergie');
$r1 = $scenario('echeance', ['liaison' => $prep['liaison']]);
$e = $scenario('etat');
$rel = $e['releves'] ?? [];
verif(($r1['releves'] ?? 0) === 1 && count($rel) === 1 && (float)$rel[0]['IndexFin'] === 123456.789 && $rel[0]['SaisieParLogin'] === 'larka.connect',
      'échéance : relevé écrit (index 123 456,789 kWh) au nom de Larka Connect');
$scenario('registre', ['modbus' => $prep['modbus'], 'registre' => 4, 'type' => 'uint32', 'ordre' => 'CDAB', 'valeur' => 1000000]);
$collecte();
sleep(1);                                    // deux échéances distinctes (à la seconde près)
$r2 = $scenario('echeance', ['liaison' => $prep['liaison']]);
sleep(1);
$scenario('echeance', ['liaison' => $prep['liaison']]);
$e = $scenario('etat');
$anom = array_values(array_filter($e['executions'] ?? [], fn($x) => $x['Statut'] === 'anomalie'));
verif(($r2['anomalies'] ?? 0) === 1 && count($anom) === 2 && count($e['releves'] ?? []) === 1, 'index en baisse (compteur remplacé ?) : anomalie, rien d\'écrit');
$v = $scenario('valider', ['execution' => (int)end($anom)['Id']]);
$e = $scenario('etat');
$st = array_count_values(array_column($e['executions'] ?? [], 'Statut'));
verif(($v['ok'] ?? false) && ($v['closes'] ?? 0) === 1 && ($st['valide'] ?? 0) === 1 && ($st['rejete'] ?? 0) === 1 && count($e['releves'] ?? []) === 2
   && (float)end($e['releves'])['IndexFin'] === 1000.0 && end($e['releves'])['IndexDebut'] === null,
      'validation : nouveau départ à 1 000 kWh, l\'échéance plus ancienne close sans écriture');

titre('Explorateur et test de connexion');
$x = $scenario('explorer', ['modbus' => $prep['modbus']]);
$absents = array_values(array_filter($x['lignes'] ?? [], fn($l) => !empty($l['absent'])));
verif(($x['ok'] ?? false) && count($x['lignes'] ?? []) === 10 && count($absents) === 5 && ($x['lignes'][0]['registre'] ?? null) === 45,
      'plage 45-54 : registres 50 à 54 marqués absents, les autres lus');
$tm = $scenario('tester', ['id' => $prep['modbus']]);
$tr = $scenario('tester', ['id' => $prep['rest']]);
verif(($tm[0]['etape'] ?? '') === 'reseau' && ($tm[0]['ok'] ?? false) && in_array(false, array_column($tm, 'ok'), true),
      'test Modbus : réseau joignable, points en échec désignés');
$droits = array_values(array_filter($tr, fn($x) => $x['etape'] === 'droits'));
$pointsKo = array_values(array_filter($tr, fn($x) => $x['etape'] === 'points'));
verif(($droits[0]['ok'] ?? false) && str_contains(json_encode($tr, JSON_UNESCAPED_UNICODE), '401')
   && str_contains((string)($pointsKo[0]['message'] ?? ''), 'Point disparu'), 'test REST : jeton exigé (401 sans), accès accordé avec, point absent nommé');
// Port fermé : la machine RÉPOND (refus) — inutile de solliciter l'informatique.
$sonde = fn(string $hote, int $port) => (new ConnectPiloteModbus(['Id' => 0, 'Nom' => 'x', 'Protocole' => 'modbus', 'Hote' => $hote, 'Port' => $port,
    'Identifiant' => '', 'params' => ['delai_secondes' => 1], 'secret' => [], '_tenant' => 'default']))->tester([]);
$te = $sonde('127.0.0.1', port_libre());
verif(($te[0]['ok'] ?? true) === false && str_contains((string)($te[0]['message'] ?? ''), 'REFUSE') && empty($te[0]['demande_it']),
      'port fermé : « refuse la connexion », pas de demande au service informatique');
// Silence (paquets jetés par un pare-feu) ou réseau sans route : la demande à transmettre est prête.
// (Le réseau de la machine d'épreuve ne se laisse pas filtrer à volonté : on soumet au diagnostic
// les réponses système d'un pare-feu qui jette, et d'un VLAN sans route.)
$diag = new ReflectionMethod('ConnectPiloteBase', 'diagnosticEchec');
$diag->setAccessible(true);
$silence = $diag->invoke(null, '10.20.30.40', 502, 'TCP', 110, 'Connection timed out', 4000.0);
$sansRoute = $diag->invoke(null, '10.20.30.40', 47808, 'UDP', 113, 'No route to host', 3.0);
$dns = $diag->invoke(null, 'gtb-chaufferie', 502, 'TCP', 0, 'php_network_getaddresses: getaddrinfo for gtb-chaufferie failed: Name or service not known', 3.0);
verif(str_contains((string)$silence['message'], 'pare-feu') && str_contains((string)($silence['demande_it'] ?? ''), '502/tcp')
   && str_contains((string)($sansRoute['demande_it'] ?? ''), '47808/udp') && str_contains((string)$dns['message'], 'DNS') && empty($dns['demande_it']),
      'silence → pare-feu probable, sans route → VLAN : message pour l\'informatique ; nom inconnu → DNS');

// ═════════════════════════════════════════════════════════════════════════════
// 3. SERVICE OCPP ET BORNE SIMULÉE
// ═════════════════════════════════════════════════════════════════════════════
titre('Bornes de recharge (OCPP 1.6J)');
$ocpp = $lancer("$php " . escapeshellarg("$t/api/outils/connect-ocpp.php") . ' --duree=60');
$pret = false;
for ($i = 0; $i < 50 && !$pret; $i++) { usleep(200000); $s = @stream_socket_client("tcp://127.0.0.1:{$ports['ocpp']}", $en, $es, 0.2); if ($s) { $pret = true; fclose($s); } }
verif($pret, 'le service écoute (port de la configuration)');

function ws_ouvrir(int $port, string $chemin, ?string $auth): array
{
    $f = @stream_socket_client("tcp://127.0.0.1:$port", $en, $es, 3);
    if (!$f) return [null, 0];
    stream_set_timeout($f, 5);
    fwrite($f, "GET $chemin HTTP/1.1\r\nHost: 127.0.0.1:$port\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
        . 'Sec-WebSocket-Key: ' . base64_encode(random_bytes(16)) . "\r\nSec-WebSocket-Version: 13\r\nSec-WebSocket-Protocol: ocpp1.6\r\n"
        . ($auth !== null ? 'Authorization: Basic ' . base64_encode($auth) . "\r\n" : '') . "\r\n");
    $rep = '';
    while (!str_contains($rep, "\r\n\r\n")) { $c = fread($f, 1); if ($c === '' || $c === false) break; $rep .= $c; }
    $code = preg_match('#^HTTP/1\.1 (\d{3})#', $rep, $m) ? (int)$m[1] : 0;
    if ($code !== 101) { fclose($f); return [null, $code]; }
    return [$f, 101];
}
function ws_envoyer($f, string $texte): void
{
    $cle = random_bytes(4);
    $n = strlen($texte);
    $h = chr(0x81) . ($n < 126 ? chr(0x80 | $n) : chr(0x80 | 126) . pack('n', $n));
    $masque = '';
    for ($i = 0; $i < $n; $i++) $masque .= $texte[$i] ^ $cle[$i % 4];
    fwrite($f, $h . $cle . $masque);
}
function ws_recevoir($f, float $delai = 6.0): ?string
{
    $fin = microtime(true) + $delai;
    while (microtime(true) < $fin) {
        $h = fread($f, 2);
        if ($h === '' || $h === false || strlen($h) < 2) { usleep(50000); continue; }
        $op = ord($h[0]) & 0x0F; $n = ord($h[1]) & 0x7F;
        if ($n === 126) $n = unpack('n', fread($f, 2))[1];
        elseif ($n === 127) $n = (int)unpack('J', fread($f, 8))[1];
        $d = '';
        while (strlen($d) < $n) { $c = fread($f, $n - strlen($d)); if ($c === '' || $c === false) break; $d .= $c; }
        if ($op === 0x9) continue;                     // ping : sans importance ici
        if ($op === 0x1) return $d;
    }
    return null;
}
$appelsServeur = [];
function ocpp_appel($f, string $action, array $charge, array &$appelsServeur): ?array
{
    $id = bin2hex(random_bytes(6));
    ws_envoyer($f, json_encode([2, $id, $action, $charge]));
    for ($i = 0; $i < 20; $i++) {
        $m = ws_recevoir($f);
        if ($m === null) return null;
        $j = json_decode($m, true);
        if (($j[0] ?? 0) === 3 && ($j[1] ?? '') === $id) return $j[2];
        if (($j[0] ?? 0) === 4 && ($j[1] ?? '') === $id) return ['_erreur' => $j[2] ?? '?'];
        if (($j[0] ?? 0) === 2) $appelsServeur[] = $j;  // appel du serveur (commande) : traité plus loin
    }
    return null;
}
$iso = fn(int $dt = 0) => gmdate('Y-m-d\TH:i:s\Z', time() + $dt);
[, $c404] = ws_ouvrir($ports['ocpp'], '/ocpp/BORNE-INCONNUE', null);
[, $c401] = ws_ouvrir($ports['ocpp'], '/ocpp/BORNE-T1', 'BORNE-T1:mauvais');
[$ws] = ws_ouvrir($ports['ocpp'], '/ocpp/BORNE-T1', 'BORNE-T1:secret-borne');
verif($c404 === 404 && $c401 === 401 && $ws !== null, 'borne inconnue refusée (404), mot de passe faux (401), bonne borne admise');
if ($ws) {
    $boot = ocpp_appel($ws, 'BootNotification', ['chargePointVendor' => 'Épreuve', 'chargePointModel' => 'WallBox 22'], $appelsServeur);
    ocpp_appel($ws, 'StatusNotification', ['connectorId' => 1, 'status' => 'Available', 'errorCode' => 'NoError', 'timestamp' => $iso()], $appelsServeur);
    $auth = ocpp_appel($ws, 'Authorize', ['idTag' => 'BADGE-1'], $appelsServeur);
    $deb1 = ocpp_appel($ws, 'StartTransaction', ['connectorId' => 1, 'idTag' => 'BADGE-1', 'meterStart' => 1000000, 'timestamp' => $iso(-600)], $appelsServeur);
    // Redémarrage : la borne repart sur une nouvelle charge sans avoir clos la précédente.
    $deb2 = ocpp_appel($ws, 'StartTransaction', ['connectorId' => 1, 'idTag' => 'BADGE-1', 'meterStart' => 1004000, 'timestamp' => $iso(-300)], $appelsServeur);
    $tid = (int)($deb2['transactionId'] ?? 0);
    ocpp_appel($ws, 'MeterValues', ['connectorId' => 1, 'transactionId' => $tid, 'meterValue' => [['timestamp' => $iso(-60), 'sampledValue' => [
        ['value' => '1012000', 'measurand' => 'Energy.Active.Import.Register', 'unit' => 'Wh'],
        ['value' => '21.8', 'measurand' => 'Power.Active.Import', 'unit' => 'kW'], ['value' => '64', 'measurand' => 'SoC', 'unit' => 'Percent']]]]], $appelsServeur);
    ocpp_appel($ws, 'StatusNotification', ['connectorId' => 1, 'status' => 'Faulted', 'errorCode' => 'GroundFailure', 'timestamp' => $iso()], $appelsServeur);
    verif(($boot['status'] ?? '') === 'Accepted' && ($auth['idTagInfo']['status'] ?? '') === 'Accepted' && $tid > 0 && $tid !== (int)($deb1['transactionId'] ?? 0),
          'BootNotification, Authorize, StartTransaction acceptés');
    // Commande à distance : file d'attente → la borne la reçoit → réponse.
    $cmd = $scenario('commande', ['borne' => $prep['borne'], 'commande' => 'RemoteStopTransaction']);
    $recue = null;
    for ($i = 0; $i < 12 && !$recue; $i++) {
        foreach ($appelsServeur as $k => $a) if (($a[2] ?? '') === 'RemoteStopTransaction') { $recue = $a; unset($appelsServeur[$k]); }
        if (!$recue) { $m = ws_recevoir($ws, 1.0); $j = $m ? json_decode($m, true) : null; if (($j[0] ?? 0) === 2) $appelsServeur[] = $j; }
    }
    if ($recue) ws_envoyer($ws, json_encode([3, $recue[1], ['status' => 'Accepted']]));
    $fin = ocpp_appel($ws, 'StopTransaction', ['transactionId' => $tid, 'meterStop' => 1015500, 'timestamp' => $iso(), 'reason' => 'Remote'], $appelsServeur);
    ocpp_appel($ws, 'StatusNotification', ['connectorId' => 1, 'status' => 'Available', 'errorCode' => 'NoError', 'timestamp' => $iso()], $appelsServeur);
    sleep(2);
    $e = $scenario('etat');
    $ses = array_column($e['sessions'] ?? [], null, 'TransactionId');
    verif(($recue[3]['transactionId'] ?? 0) === $tid && (($e['commandes'][0]['Statut'] ?? '') === 'reussie'),
          'commande « arrêter la charge » transmise avec la bonne transaction, acceptée');
    verif(($ses[$tid]['Statut'] ?? '') === 'terminee' && (float)($ses[$tid]['EnergieWh'] ?? 0) === 11500.0
       && ($ses[(int)($deb1['transactionId'] ?? 0)]['Statut'] ?? '') === 'interrompue' && ($fin['idTagInfo']['status'] ?? '') === 'Accepted',
          'session close (11,5 kWh) ; la charge perdue avant le redémarrage : « interrompue »');
    $alb = array_values(array_filter($e['alarmes'] ?? [], fn($a) => $a['Origine'] === 'equipement' && (int)$a['PasserelleId'] === (int)$prep['borne']));
    verif(count($alb) === 1 && str_contains((string)$alb[0]['Code'], 'GroundFailure') && (int)$alb[0]['DemandeId'] > 0 && $alb[0]['Etat'] === 'terminee',
          'défaut de terre : alarme et demande d\'intervention, close quand la borne redevient libre');
    $noms = array_column(array_filter($e['points'] ?? [], fn($p) => str_starts_with((string)$p['Code'], 'ocpp.')), 'DerniereValeur', 'Code');
    verif(isset($noms['ocpp.energie'], $noms['ocpp.puissance'], $noms['ocpp.soc'], $noms['ocpp.statut.1']) && (float)$noms['ocpp.energie'] === 1015.5,
          'points de la borne créés d\'eux-mêmes (énergie 1 015,5 kWh, puissance, batterie, état)');
    $etatService = [];
    for ($i = 0; $i < 30 && !isset($etatService['bornes']['default']['BORNE-T1']); $i++) {
        usleep(200000);
        $etatService = json_decode((string)@file_get_contents("$t/data/run/connect-ocpp.json"), true) ?: [];
    }
    verif(isset($etatService['bornes']['default']['BORNE-T1']) && !empty($etatService['inconnues']), 'état publié : borne connectée, borne inconnue signalée');
    fclose($ws);
}
proc_terminate($ocpp);

// ═════════════════════════════════════════════════════════════════════════════
// 4. API : DROITS, CONFIRMATION D'IDENTITÉ, TRACES
// ═════════════════════════════════════════════════════════════════════════════
titre('API : écriture vers un équipement');
$lancer("$php -S 127.0.0.1:{$ports['api']} -t " . escapeshellarg($t) . ' ' . escapeshellarg("$t/router.php"));
$pret = false;
for ($i = 0; $i < 50 && !$pret; $i++) { usleep(150000); $s = @stream_socket_client("tcp://127.0.0.1:{$ports['api']}", $en, $es, 0.2); if ($s) { $pret = true; fclose($s); } }
$http = function (string $qui, string $action, ?array $corps = null, string $methode = 'POST') use ($t, $ports): array {
    $ch = curl_init("http://127.0.0.1:{$ports['api']}/api/index.php?action=$action");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => "$t/sim/cookies-$qui", CURLOPT_COOKIEFILE => "$t/sim/cookies-$qui",
        CURLOPT_CUSTOMREQUEST => $methode, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 40,
        CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*']);
    if ($corps !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($corps));
    $r = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    return [$code, json_decode($r, true) ?: [], $r];
};
[$cl] = $http('g', 'login', ['login' => 'gestion.epreuve', 'password' => 'Epreuve-2026!']);
[$cv] = $http('v', 'login', ['login' => 'lecture.epreuve', 'password' => 'Epreuve-2026!']);
[$cb] = $http('b', 'login', ['login' => 'gestion.bis', 'password' => 'Epreuve-2026!']);
verif($pret && $cl === 200 && $cv === 200 && $cb === 200, 'serveur jetable et connexions des comptes d\'épreuve');
[$c1] = $http('g', 'connect_ecrire', ['pointId' => $ids['CONS'], 'valeur' => 23.5]);
[$c2, $j2] = $http('g', 'connect_confirmer_identite', ['motDePasse' => 'faux']);
[$c3] = $http('g', 'connect_confirmer_identite', ['motDePasse' => 'Epreuve-2026!']);
[$c4, $j4] = $http('g', 'connect_ecrire', ['pointId' => $ids['CONS'], 'valeur' => 23.5]);
$lu = $scenario('lire', ['modbus' => $prep['modbus'], 'point' => $ids['CONS']]);
verif($c1 === 428, 'sans confirmation d\'identité : 428, rien n\'est envoyé');
verif($c2 === 403 && $c3 === 200, 'confirmation : mot de passe faux refusé (403), bon accepté');
verif($c4 === 200 && (float)($lu['valeur'] ?? 0) === 235.0, 'consigne 23,5 °C écrite dans l\'automate (relue : registre 235)', (string)($lu['valeur'] ?? '∅'));
[$c5, $j5] = $http('g', 'connect_ecrire', ['pointId' => $ids['CONS'], 'valeur' => 40]);
[$c6] = $http('g', 'connect_ecrire', ['pointId' => $ids['T'], 'valeur' => 20]);
verif($c5 === 422 && str_contains((string)($j5['error'] ?? ''), 'plage'), 'hors de la plage permise (15 à 28) : 422');
verif($c6 === 403, 'point non déclaré inscriptible : 403');
[$c7] = $http('g', 'connect_ecrire', ['pointId' => $ids['R_CONS'], 'valeur' => 19.5]);
$restEtat = json_decode((string)@file_get_contents("$t/sim/rest-etat.json"), true);
verif($c7 === 200 && (float)($restEtat['CONS'] ?? 0) === 19.5, 'écriture REST : PUT reçu par le superviseur');
[$c8] = $http('v', 'connect_ecrire', ['pointId' => $ids['CONS'], 'valeur' => 21]);
[$c9] = $http('v', 'connect_confirmer_identite', ['motDePasse' => 'Epreuve-2026!']);
[$c10, $j10] = $http('v', 'connect_supervision', null, 'GET');
verif($c8 === 403 && $c9 === 403 && $c10 === 200 && !empty($j10['data']['passerelles']), 'Visionneur : lit la supervision, ne peut ni confirmer ni écrire');
$codes = [];
for ($i = 0; $i < 6; $i++) $codes[] = $http('b', 'connect_confirmer_identite', ['motDePasse' => 'faux-' . $i])[0];
verif(array_slice($codes, 0, 5) === [403, 403, 403, 403, 403] && $codes[5] === 429, 'cinq mots de passe faux, puis 429 (limitation)', implode(',', $codes));
// Durée de la confirmation (« redemander le mot de passe toutes les X ») : réglée par le gestionnaire.
[$d1, $jd1] = $http('g', 'connect_identite_duree', null, 'GET');
verif($d1 === 200 && (int)($jd1['data']['minutes'] ?? 0) === 5 && (int)($jd1['data']['restant'] ?? 0) > 200
   && ($jd1['data']['fournisseur'] ?? '') === 'local', 'durée de confirmation : 5 min par défaut, confirmation en cours');
[$d2] = $http('b', 'connect_identite_duree', ['minutes' => 60]);
[$d3] = $http('g', 'configuration', ['cle' => 'securite_identite_minutes', 'valeur' => '480']);
[$d4] = $http('g', 'connect_identite_duree', ['minutes' => 7]);
[$d5, $jd5] = $http('g', 'connect_identite_duree', ['minutes' => 60]);
verif($d2 === 428 && $d3 === 403 && $d4 === 422 && $d5 === 200 && (int)($jd5['data']['minutes'] ?? 0) === 60,
      'durée : refusée sans confirmation (428), par la route générique (403), hors liste (422) ; 1 h acceptée');
[$d6, $jd6] = $http('g', 'connect_confirmer_identite', ['motDePasse' => 'Epreuve-2026!']);
verif($d6 === 200 && (int)($jd6['data']['secondes'] ?? 0) === 3600, 'confirmation suivante : valable 1 h', (string)($jd6['data']['secondes'] ?? ''));
[$d7] = $http('g', 'connect_identite_sso', []);
verif($d7 === 409, 'compte local : pas de fenêtre Microsoft/Google (409)');
$http('g', 'connect_identite_duree', ['minutes' => 5]);
[, , $brutPa] = $http('g', 'connect_passerelles', null, 'GET');
verif(!str_contains($brutPa, 'jeton-epreuve') && !str_contains($brutPa, 'secret-borne') && !str_contains($brutPa, '"Secret"')
   && str_contains($brutPa, 'SecretsDefinis'), 'liste des connexions : aucun secret renvoyé, seulement « défini »');
$audit = (string)@file_get_contents("$t/data/security/audit.log");
verif(substr_count($audit, '"connect_ecriture"') === 4 && str_contains($audit, 'hors de la plage permise') && str_contains($audit, 'non déclaré inscriptible')
   && str_contains($audit, 'valeur précédente 22') && str_contains($audit, 'connect_reauth_failure'),
      'journal de sécurité : écritures et tentatives refusées (valeur, précédente), échecs de confirmation');
$e = $scenario('etat');
verif(count(array_filter($e['commandes'] ?? [], fn($c) => $c['Commande'] === 'ecrire' && $c['Statut'] === 'reussie')) === 2,
      'écritures inscrites dans l\'historique des commandes');

titre('Auto-détection des équipements et regroupement par bâtiment');
require_once "$racine/api/connect/Detection.php";
[$ipsA] = ConnectDetection::cibles('192.168.20.0/24');
[$ipsB, $errB] = ConnectDetection::cibles('8.8.8.0/24, 10.0.0.0/16');
[$ipsC] = ConnectDetection::cibles('10.0.5.10-20, 10.0.5.15, 127.0.0.1');
verif(count($ipsA) === 254 && !$ipsB && count($errB) === 2 && count($ipsC) === 12, 'plages : /24 sans réseau ni diffusion ; Internet et plage trop large refusés ; intervalles, doublons',
      count($ipsA) . ' · ' . count($ipsC));
[$cd, $jd] = $http('g', 'connect_detection', null, 'GET');
[$cdv] = $http('v', 'connect_detecter', ['plage' => '127.0.0.1', 'protocoles' => ['modbus']]);
[$cdp, $jdp] = $http('g', 'connect_detecter', ['plage' => '8.8.8.8', 'protocoles' => ['modbus']]);
verif($cd === 200 && ($jd['data']['ports']['modbus'] ?? null) === [502] && $cdv === 403 && $cdp === 422 && str_contains((string)($jdp['error'] ?? ''), 'interne'),
      'détection : réservée aux gestionnaires (403), jamais vers Internet (422)');
$tDet = microtime(true);
[$cdd, $jdd] = $http('g', 'connect_detecter', ['plage' => '127.0.0.1', 'protocoles' => ['modbus', 'rest', 'mqtt', 'csv'],
                                               'ports' => ['modbus' => [$ports['modbus']], 'rest' => [$ports['rest']], 'mqtt' => [port_libre()]]]);
$trouves = $jdd['data']['equipements'] ?? [];
$parProto = [];
foreach ($trouves as $x) $parProto[$x['protocole']][] = $x;
$mbT = $parProto['modbus'][0] ?? [];
$reT = $parProto['rest'][0] ?? [];
$csT = array_values(array_filter($parProto['csv'] ?? [], fn($x) => ($x['parametres']['dossier'] ?? '') === 'data/connect/imports/epreuve'))[0] ?? [];
verif($cdd === 200 && ($mbT['confiance'] ?? '') === 'sure' && (int)($mbT['port'] ?? 0) === $ports['modbus'] && (int)($mbT['deja'] ?? 0) === (int)$prep['modbus']
   && empty($parProto['mqtt']), 'Modbus reconnu (vraie requête), déjà raccordé ; port fermé : rien', sprintf('%.1f s', microtime(true) - $tDet));
verif(($reT['parametres']['url_base'] ?? '') === "http://127.0.0.1:{$ports['rest']}" && (int)($reT['deja'] ?? 0) === (int)$prep['rest']
   && (int)($csT['deja'] ?? 0) === (int)$prep['csv'], 'API REST et dossier CSV reconnus, rapprochés des connexions existantes');
// Équipement qui accepte la connexion puis se tait (automate figé) : la sonde Modbus
// réessaie à l'unit id 255 sur une nouvelle connexion, sans planter sur l'ancienne.
$muet = stream_socket_server('tcp://127.0.0.1:0', $e, $es);
$portMuet = (int)substr((string)strrchr((string)stream_socket_get_name($muet, false), ':'), 1);
$tMuet = microtime(true);
try { $rMuet = (new ConnectDetection(10, 0.5, 0.3))->detecter(['127.0.0.1'], ['modbus'], ['modbus' => [$portMuet]]); $eMuet = ''; }
catch (\Throwable $ex) { $rMuet = null; $eMuet = $ex->getMessage(); }
fclose($muet);
verif($rMuet === [], 'équipement muet (connexion acceptée, aucune réponse) : ignoré sans erreur',
      $eMuet !== '' ? $eMuet : sprintf('%.1f s', microtime(true) - $tMuet));
// Une seule adresse, douze ports : le douzième n'est pas laissé de côté.
$douze = array_merge(array_map(fn() => port_libre(), range(1, 11)), [$ports['modbus']]);
$rDouze = (new ConnectDetection(20, 0.5, 1.0))->detecter(['127.0.0.1'], ['modbus'], ['modbus' => $douze]);
verif(count($rDouze) === 1 && (int)($rDouze[0]['port'] ?? 0) === $ports['modbus'], 'une seule adresse : plus de 8 ports particuliers parcourus',
      count($rDouze) . ' trouvé(s)');
$pdoT = new PDO("sqlite:$t/data/gmao.db");
$pdoT->exec("INSERT INTO PlanBatiments (Nom) VALUES ('Gymnase')");
$batG = (int)$pdoT->lastInsertId();
$pdoT->exec("UPDATE ConnectPasserelles SET BatimentId = $batG WHERE Id = " . (int)$prep['modbus']);
[, $jdd2] = $http('g', 'connect_detecter', ['plage' => '127.0.0.1', 'protocoles' => ['modbus'], 'ports' => ['modbus' => [$ports['modbus']]]]);
[$cc, $jcc] = $http('g', 'connect_detection_creer', ['equipements' => [
    ['protocole' => 'modbus', 'nom' => 'Compteur détecté', 'hote' => '127.0.0.1', 'port' => $ports['modbus'], 'parametres' => ['unite_defaut' => 1],
     'batimentId' => $batG, 'details' => 'Répond en Modbus TCP.'],
    ['protocole' => 'modbus', 'nom' => 'Adresse invalide', 'hote' => 'http://x'],
], 'decouvrirPoints' => true]);
$creee = $pdoT->query("SELECT * FROM ConnectPasserelles WHERE Nom = 'Compteur détecté'")->fetch(PDO::FETCH_ASSOC) ?: [];
verif((int)($jdd2['data']['equipements'][0]['batimentId'] ?? 0) === $batG, 'bâtiment deviné d\'après les connexions du même sous-réseau');
verif($cc === 200 && ($jcc['data']['creees'] ?? 0) === 1 && (int)($creee['BatimentId'] ?? 0) === $batG && str_contains((string)($creee['Description'] ?? ''), 'Détectée automatiquement')
   && !empty($jcc['data']['connexions'][1]['erreur']), 'raccordement : connexion créée dans son bâtiment ; saisie invalide refusée, sans bloquer les autres');
[, $jpa] = $http('g', 'connect_points', null, 'GET');
[, $jcm] = $http('g', 'connect_commandes&limite=5', null, 'GET');
[, $jrg] = $http('g', 'connect_regles', null, 'GET');
[, $jli] = $http('g', 'connect_liaisons', null, 'GET');
$batDe = fn(array $l) => array_values(array_unique(array_map(fn($x) => $x['BatimentNom'] ?? '∅', $l)));
verif(in_array('Gymnase', $batDe($jpa['data'] ?? []), true) && in_array('Gymnase', $batDe($jcm['data'] ?? []), true)
   && array_key_exists('BatimentNom', ($jrg['data'][0] ?? [])) && array_key_exists('BatimentNom', ($jli['data'][0] ?? [])),
      'points, commandes, règles et relevés portent le bâtiment (regroupement des onglets)');
// Réseaux des bâtiments : la détection d'un bâtiment parcourt ses réseaux et y range ce qu'elle trouve.
[$cbr1] = $http('g', 'connect_batiment', ['id' => $batG, 'reseaux' => '127.0.0.1, 10.9.0.4/30', 'motsCles' => 'GYM, salle de sport']);
[$cbr2, $jbr2] = $http('g', 'connect_batiment', ['id' => $batG, 'reseaux' => '8.8.8.0/24']);
[$cbr3] = $http('v', 'connect_batiment', ['id' => $batG, 'reseaux' => '127.0.0.1']);
[, $jbl] = $http('g', 'connect_batiments', null, 'GET');
$bG = array_values(array_filter($jbl['data']['batiments'] ?? [], fn($x) => (int)$x['Id'] === $batG))[0] ?? [];
verif($cbr1 === 200 && $cbr2 === 422 && str_contains((string)($jbr2['error'] ?? ''), 'interne') && $cbr3 === 403
   && ($bG['Reseaux'] ?? '') === '127.0.0.1, 10.9.0.4/30' && ($bG['MotsCles'] ?? '') === 'GYM, salle de sport',
      'réseaux d\'un bâtiment : enregistrés et relus ; Internet refusé ; réservé aux gestionnaires');
[$cdb, $jdb] = $http('g', 'connect_detecter', ['batiment' => (string)$batG, 'protocoles' => ['modbus'], 'ports' => ['modbus' => [$ports['modbus']]]]);
$eB = $jdb['data']['equipements'][0] ?? [];
verif($cdb === 200 && str_contains((string)($jdb['data']['plage'] ?? ''), '10.9.0.4/30') && (int)($eB['batimentId'] ?? 0) === $batG
   && ($eB['batimentPar'] ?? '') === 'reseau', 'détection d\'un bâtiment : ses réseaux parcourus, l\'équipement rangé d\'après le réseau déclaré');
// Une connexion sans bâtiment, sur ce réseau : « Voir et ranger » la range.
$pdoT->exec("INSERT INTO ConnectPasserelles (Nom, Protocole, Hote, Port, Actif, Etat) VALUES ('Sans bâtiment', 'modbus', '127.0.0.1', 1502, 0, 'inconnu')");
$sansB = (int)$pdoT->lastInsertId();
[, $jrg1] = $http('g', 'connect_batiments', null, 'GET');
$propose = array_values(array_filter($jrg1['data']['rangeables'] ?? [], fn($x) => (int)$x['id'] === $sansB))[0] ?? [];
[$crg, $jrg] = $http('g', 'connect_batiments_ranger', ['ids' => [$sansB]]);
$apres = (int)$pdoT->query("SELECT BatimentId FROM ConnectPasserelles WHERE Id = $sansB")->fetchColumn();
verif((int)($propose['batimentId'] ?? 0) === $batG && ($propose['par'] ?? '') === 'reseau' && $crg === 200 && ($jrg['data']['rangees'] ?? 0) === 1 && $apres === $batG,
      'connexion sans bâtiment : bâtiment proposé (réseau), rangée d\'un clic');
$pdoT->exec("DELETE FROM ConnectPasserelles WHERE Id = $sansB");
[$cpc, $jpc] = $http('g', 'connect_parcours', null, 'GET');
[, $jsv] = $http('v', 'connect_supervision', null, 'GET');
verif($cpc === 200 && ($jpc['data']['batiments_avec_reseau'] ?? 0) >= 1 && isset($jpc['data']['sans_points'], $jpc['data']['regles'])
   && array_filter($jsv['data']['batiments'] ?? [], fn($x) => (int)$x['Id'] === $batG && in_array('127.0.0.1', $x['Plages'], true)) && isset($jsv['data']['charges']),
      'guide de démarrage (compteurs) ; supervision : bâtiments et leurs réseaux pour la synthèse');
// Le nom, en secours : sigle, mot propre à un bâtiment ; dans le doute (« POL » : police ou polyvalente ?), rien.
require_once "$racine/api/connect/Batiments.php";
$bn = [['Id' => 1, 'Nom' => 'Hôtel de Ville', 'MotsCles' => '', 'Plages' => []], ['Id' => 2, 'Nom' => 'Gymnase du Parc', 'MotsCles' => 'GYM', 'Plages' => []],
       ['Id' => 3, 'Nom' => 'Salle polyvalente La Fabrique', 'MotsCles' => '', 'Plages' => []], ['Id' => 4, 'Nom' => 'Poste de Police municipale', 'MotsCles' => '', 'Plages' => []],
       ['Id' => 5, 'Nom' => 'Médiathèque des Tilleuls', 'MotsCles' => '', 'Plages' => []]];
verif(ConnectBatiments::parNom('Borne HDV-VE-01', $bn) === 1 && ConnectBatiments::parNom('CTA vestiaires gym', $bn) === 2
   && ConnectBatiments::parNom('simulateur-mediatheque', $bn) === 5 && ConnectBatiments::parNom('CTA salle du conseil', $bn) === null
   && ConnectBatiments::parNom('Borne POL-VE-01', $bn) === null && ConnectBatiments::parNom('Capteurs Police municipale', $bn) === 4,
      'bâtiment d\'après le nom : sigle, mot-clé, mot propre ; rien dans le doute');
$pdoT->exec("DELETE FROM ConnectBatiments");
$pdoT->exec("DELETE FROM ConnectPasserelles WHERE Id = " . (int)($creee['Id'] ?? 0));
$pdoT->exec("UPDATE ConnectPasserelles SET BatimentId = NULL WHERE Id = " . (int)$prep['modbus']);
$pdoT = null;

titre('Appareils en hotspot Wi-Fi : PC releveur, relevés hors ligne');
// Les sources du collecteur sont sous api/ (copié plus haut) : livrées partout, production
// comprise (deploy/install.sh ne copie pas deploy/). L'installeur de PHP y est une copie de
// deploy/windows/installer-php.ps1 : les deux doivent rester identiques.
verif(is_file("$t/api/connect/releveur/releveur.php") && !is_dir("$racine/deploy/releveur")
   && hash_file('sha256', "$racine/api/connect/releveur/installer-php.ps1") === hash_file('sha256', "$racine/deploy/windows/installer-php.ps1"),
      'collecteur : sources livrées avec api/ (pas sous deploy/, absent en production), installeur de PHP à jour');
$pdoT = new PDO("sqlite:$t/data/gmao.db");
$hsCorps = ['protocole' => 'modbus', 'nom' => 'Enregistreur en hotspot', 'hote' => '127.0.0.1', 'port' => $ports['modbus'], 'parametres' => ['unite_defaut' => 1],
            'acces' => 'hotspot', 'wifi' => ['ssid' => 'LOGGER-EPREUVE', 'securite' => 'wpa2'], 'secrets' => ['wifi_cle' => 'cle-epreuve-26']];
[$chs1, $jhs1] = $http('g', 'connect_passerelles', ['secrets' => ['wifi_cle' => 'court']] + $hsCorps);
[$chs2, $jhs2] = $http('g', 'connect_passerelles', ['wifi' => ['ssid' => '', 'securite' => 'wpa2']] + $hsCorps);
// Un nom (« powerelec.net », servi par l'appareil sur son hotspot) est admis : le collecteur le
// résout une fois connecté et refuse ce qui ne mène pas à une adresse privée. Une IP publique : jamais.
[$chs3, $jhs3] = $http('g', 'connect_passerelles', ['nom' => 'Compteur PE6', 'protocole' => 'rest', 'hote' => '', 'parametres' => ['url_base' => 'http://powerelec.net']] + $hsCorps);
[$chs4, $jhs4] = $http('g', 'connect_passerelles', ['hote' => '8.8.8.8'] + $hsCorps);
[$chs5] = $http('g', 'connect_passerelles', ['protocole' => 'rest', 'parametres' => ['url_base' => 'https://203.0.113.9/api']] + $hsCorps);
if ($chs3 === 200) $http('g', 'connect_passerelles&id=' . (int)($jhs3['data']['id'] ?? 0), null, 'DELETE');
verif($chs3 === 200 && $chs4 === 422 && str_contains((string)($jhs4['error'] ?? ''), 'privée') && $chs5 === 422,
      'appareil hors réseau : IP privée ou nom servi par l\'appareil (powerelec.net), jamais une IP publique', $chs3 !== 200 ? (string)($jhs3['error'] ?? '') : '');
[$chs, $jhs] = $http('g', 'connect_passerelles', $hsCorps);
$hsId = (int)($jhs['data']['id'] ?? 0);
$hsLigne = $pdoT->query("SELECT * FROM ConnectPasserelles WHERE Id = $hsId")->fetch(PDO::FETCH_ASSOC) ?: [];
verif($chs1 === 422 && str_contains((string)($jhs1['error'] ?? ''), '8 à 63') && $chs2 === 422 && str_contains((string)($jhs2['error'] ?? ''), 'SSID') && $chs === 200 && $hsId
   && str_contains((string)$hsLigne['Parametres'], '"acces":"hotspot"') && (string)$hsLigne['Secret'] !== '' && !str_contains((string)$hsLigne['Secret'], 'cle-epreuve-26'),
      'connexion en hotspot : nom du Wi-Fi et clé exigés ; clé chiffrée comme les autres secrets');
[$cpH] = $http('g', 'connect_points', ['passerelleId' => $hsId, 'nom' => 'Température chambre froide', 'unite' => '°C', 'adresse' => ['registre' => 0, 'type' => 'int16'], 'coefficient' => 0.1]);
[$ctH, $jtH] = $http('g', 'connect_tester', ['id' => $hsId]);
[$ccH] = $http('g', 'connect_collecter', ['id' => $hsId]);
$pdoT->exec("UPDATE ConnectPasserelles SET ProchaineCollecte = NULL");
exec('cd ' . escapeshellarg($t) . " && $php api/outils/connect-collecte.php --forcer 2>&1", $ocH);
$hsApres = $pdoT->query("SELECT DerniereCollecte FROM ConnectPasserelles WHERE Id = $hsId")->fetchColumn();
verif($cpH === 200 && $ctH === 409 && str_contains((string)($jtH['error'] ?? ''), 'PC releveur') && $ccH === 409 && $hsApres === null,
      'le serveur ne la teste, ne la lit ni ne la collecte : c\'est le PC releveur qui le fait');
[$crlv] = $http('v', 'connect_releveur_enregistrer', ['nom' => 'Portable', 'connexions' => [$hsId]]);
[$crl, $jrl] = $http('g', 'connect_releveur_enregistrer', ['nom' => 'Portable de l\'agent', 'connexions' => [$hsId]]);
$rlId = (int)($jrl['data']['id'] ?? 0);
[$crls, $jrls] = $http('g', 'connect_releveurs', null, 'GET');
$hsVu = array_values(array_filter($jrls['data']['hotspots'] ?? [], fn($x) => (int)$x['Id'] === $hsId))[0] ?? [];
$rlVu = array_values(array_filter($jrls['data']['releveurs'] ?? [], fn($x) => (int)$x['Id'] === $rlId))[0] ?? [];
verif($crlv === 403 && $crl === 200 && $rlId && ($hsVu['Ssid'] ?? '') === 'LOGGER-EPREUVE' && $rlVu && !array_key_exists('JetonHash', $rlVu),
      'PC releveur : créé par un gestionnaire (pas un visionneur) ; écran : appareils en hotspot, PC sans leur jeton');
$telecharger = function () use ($http, &$rlId, $ports, $t): array {
    [$c, , $brut] = $http('g', 'connect_releveur_paquet', ['id' => $rlId, 'url' => "http://127.0.0.1:{$ports['api']}"]);
    $dir = "$t/sim/pc-" . bin2hex(random_bytes(3));
    @mkdir($dir, 0700, true);
    file_put_contents("$dir/releveur.zip", $brut);
    $z = new ZipArchive();
    $ok = $c === 200 && $z->open("$dir/releveur.zip") === true;
    $noms = [];
    if ($ok) { for ($i = 0; $i < $z->numFiles; $i++) $noms[] = $z->getNameIndex($i); $z->extractTo($dir); $z->close(); }
    $cfg = json_decode((string)@file_get_contents("$dir/larka-releveur/config.json"), true) ?: [];
    return [$ok, $noms, $cfg, "$dir/larka-releveur"];
};
// Windows : le php.ini du PHP du collecteur lit ses chemins dans LARKA_PHP_DIR ; sans elle,
// ni openssl (HTTPS) ni mbstring : « Larka injoignable » alors que Larka répondait.
$cmdSrc = (string)@file_get_contents("$racine/api/connect/releveur/releveur.cmd");
verif(str_contains($cmdSrc, 'set "LARKA_PHP_DIR=%ICI%php"') && str_contains((string)@file_get_contents("$racine/api/connect/releveur/releveur.php"), "['openssl', 'mbstring']"),
      'collecteur Windows : son PHP charge ses extensions (LARKA_PHP_DIR) ; extension manquante dite clairement');
// Le collecteur ouvre des clés Wi-Fi : identité confirmée d'abord (428), trace de sécurité.
[$cpq0] = $http('b', 'connect_releveur_paquet', ['id' => $rlId, 'url' => "http://127.0.0.1:{$ports['api']}"]);
$http('g', 'connect_confirmer_identite', ['motDePasse' => 'Epreuve-2026!']);
[$zOk, $zNoms, $zCfg, $pc] = $telecharger();
$journalSecu = (string)@file_get_contents("$t/data/security/audit.log");
verif($cpq0 === 428 && $zOk && str_contains($journalSecu, 'connect_releveur_paquet'),
      'collecteur : confirmation d\'identité exigée (428 sinon), téléchargement tracé au journal de sécurité');
verif($zOk && in_array('larka-releveur/releveur.php', $zNoms, true) && in_array('larka-releveur/releveur.cmd', $zNoms, true)
   && in_array('larka-releveur/lib/connect/pilotes/Modbus.php', $zNoms, true) && str_starts_with((string)($zCfg['jeton'] ?? ''), 'lrk1.')
   && ($zCfg['larka'] ?? '') === "http://127.0.0.1:{$ports['api']}" && str_contains((string)@file_get_contents("$pc/releveur.cmd"), "\r\n"),
      'collecteur téléchargé : scripts, pilotes de Larka, config.json (adresse, jeton) ; lanceur Windows en CRLF', count($zNoms) . ' fichiers');
// Les sources du collecteur ne se servent pas telles quelles (seulement par la route, déjà réglées).
$brut = function (string $chemin) use ($ports): int {
    $ch = curl_init("http://127.0.0.1:{$ports['api']}/$chemin");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*']);
    curl_exec($ch);
    return (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
};
verif(!in_array($brut('api/connect/releveur/LISEZ-MOI.txt'), [200, 304], true) && !in_array($brut('api/connect/releveur/installer-php.ps1'), [200, 304], true),
      'sources du collecteur : jamais servies telles quelles par le serveur web');
$releveurApi = function (string $methode, string $op, ?string $jeton, ?array $corps = null) use ($ports): array {
    $ch = curl_init("http://127.0.0.1:{$ports['api']}/api/index.php?action=connect_releveur" . ($methode === 'GET' ? "&op=$op" : ''));
    $h = ['Content-Type: application/json'];
    if ($jeton !== null) $h[] = "X-Larka-Releveur: $jeton";
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $methode, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 30, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*']);
    if ($corps !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($corps + ['op' => $op]));
    $r = (string)curl_exec($ch);
    return [(int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), json_decode($r, true) ?: []];
};
[$cm0] = $releveurApi('GET', 'mission', null);
[$cm1] = $releveurApi('GET', 'mission', 'lrk1.epreuve.' . $rlId . '.' . str_repeat('0', 48));
[$cm2, $jm2] = $releveurApi('GET', 'mission', (string)$zCfg['jeton']);
$cxM = $jm2['data']['connexions'][0] ?? [];
verif($cm0 === 401 && $cm1 === 403 && $cm2 === 200 && (int)($cxM['id'] ?? 0) === $hsId && ($cxM['wifi']['ssid'] ?? '') === 'LOGGER-EPREUVE'
   && ($cxM['wifi']['cle'] ?? '') === 'cle-epreuve-26' && count($cxM['points'] ?? []) === 1 && !isset($cxM['secrets']['wifi_cle']),
      'mission : sans jeton 401, jeton faux 403 ; le bon jeton reçoit l\'appareil, son Wi-Fi et ses points');
$collecteur = function (string $args) use ($php, $pc): array {
    exec("cd " . escapeshellarg($pc) . " && $php releveur.php $args 2>&1", $o, $c);
    return [implode("\n", $o), $c];
};
// Le PC dans un autre fuseau (UTC+14) : ses relevés arrivent quand même à l'heure de Larka.
$collecteurLoin = function (string $args) use ($php, $pc): array {
    exec("cd " . escapeshellarg($pc) . " && $php -d date.timezone=Pacific/Kiritimati releveur.php $args 2>&1", $o, $c);
    return [implode("\n", $o), $c];
};
// Rien ne se lance tout seul : le collecteur ne pose aucune tâche, et retire une fois celles
// des anciennes versions (crontab ici ; schtasks sous Windows), sans toucher aux autres lignes.
$srcR = (string)@file_get_contents("$racine/api/connect/releveur/releveur.php");
$faux = sys_get_temp_dir() . '/lrk-cron-' . getmypid();
@mkdir($faux, 0700, true);
file_put_contents("$faux/tab", "0 3 * * * /usr/bin/sauvegarde\n30 8 * * * cd /x && ./releveur.sh collecter --planifie # larka-releveur\n*/10 * * * * cd /x && ./releveur.sh collecter --planifie --direct-seulement # larka-releveur cable\n");
file_put_contents("$faux/crontab", "#!/bin/sh\nif [ \"$1\" = -l ]; then cat " . escapeshellarg("$faux/tab") . "; else cp \"$1\" " . escapeshellarg("$faux/tab") . "; fi\n");
chmod("$faux/crontab", 0755);
$avecCron = function (string $args) use ($php, $pc, $faux): array {
    exec("cd " . escapeshellarg($pc) . " && PATH=" . escapeshellarg($faux) . ":\$PATH $php releveur.php $args 2>&1", $o, $c);
    return [implode("\n", $o), $c];
};
[$sn1] = $avecCron('etat');
$tab1 = (string)@file_get_contents("$faux/tab");
file_put_contents("$faux/tab", $tab1 . "0 9 * * * cd /y && ./releveur.sh collecter # larka-releveur\n");
[$sn2] = $avecCron('etat');
$tab2 = (string)@file_get_contents("$faux/tab");
verif(!preg_match('~schtasks[^\n]*/Create|--heures|toutes-les~i', $srcR) && !preg_match('~\bheures\b~i', $cmdSrc)
   && $tab1 === "0 3 * * * /usr/bin/sauvegarde\n" && str_contains($sn1, '2 tâche(s) planifiée(s)')
   && str_contains($sn1, 'à la main') && str_contains($tab2, '# larka-releveur') && !str_contains($sn2, 'tâche(s) planifiée(s)'),
      'collecteur : aucune tâche planifiée posée ; celles des anciennes versions retirées une fois, le reste du crontab intact', $tab1 === "0 3 * * * /usr/bin/sauvegarde\n" ? '' : $sn1);
effacer($faux);

// « explorer » : un appareil sans documentation montre ce qu'il expose. Faux appareil
// web sur 127.0.0.78:8000 : sa page charge un script qui interroge une adresse JSON.
$dev = sys_get_temp_dir() . '/lrk-dev-' . getmypid();
@mkdir("$dev/js", 0700, true);
file_put_contents("$dev/index.html", '<html><head><title>POWER-ADAPT</title><script src="js/app.js"></script></head><body>x</body></html>');
file_put_contents("$dev/js/app.js", "function maj(){ fetch('/api/realtime/measures').then(r => r.json()); }");
file_put_contents("$dev/r.php", '<?php if (parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH) === "/api/realtime/measures") { header("Content-Type: application/json"); echo "{\"voies\":[{\"U1\":231.4}]}"; return true; } return false;');
$pDev = proc_open("exec $php -S 127.0.0.78:8000 -t " . escapeshellarg($dev) . ' ' . escapeshellarg("$dev/r.php"), [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $tubesDev);
for ($i = 0; $i < 30 && !@fsockopen('127.0.0.78', 8000); $i++) usleep(100000);
exec('cd ' . escapeshellarg($pc) . " && $php releveur.php explorer --sans-wifi --hote=127.0.0.78 2>&1", $oe);
$se = implode("\n", $oe);
$rapE = (string)@file_get_contents(array_values(glob("$pc/exploration-*.txt") ?: [''])[0] ?: '/dev/null');
$srcR = (string)@file_get_contents("$racine/api/connect/releveur/releveur.php");
verif(str_contains($se, 'port 8000 (HTTP) : ouvert') && str_contains($se, '/api/realtime/measures → HTTP 200') && str_contains($rapE, '"U1":231.4')
   && !preg_match('/fwrite\(\$sock[^;]*pack\(\'nnnCCnn\'[^;]*, *(5|6|15|16)\b/', $srcR) && str_contains($srcR, 'function nomProfil(string $ssid): string { return $ssid; }'),
      'explorer : ports, page, script, adresse JSON, rapport ; profil Wi-Fi au nom du hotspot', mb_substr($se, -200));
proc_terminate($pDev); proc_close($pDev);
// Sur son hotspot, l'appareil est la passerelle de la carte Wi-Fi : elle sert quand
// le nom saisi (« powerelec.net ») mène, d'ici, à une adresse publique (cache DNS).
// Sortie d'ipconfig en français : espace insécable, IPv6 puis IPv4 sans libellé.
if (!function_exists('r_passerelles_ipconfig') && preg_match('/function r_passerelles_ipconfig.*?\n}\n/s', $srcR, $mIp)) eval($mIp[0]);
$nb = "\u{00A0}";
$ipc = "Carte réseau sans fil Wi-Fi$nb:\r\n\r\n   Adresse IPv4. . . . . . . . . . . . . .: 192.168.4.2\r\n   Passerelle par défaut. . . . . . . . . : fe80::1%12\r\n"
     . "                                       192.168.4.1\r\n\r\nCarte réseau sans fil Wi-Fi 2$nb:\r\n\r\n   Passerelle par défaut. . . . . . . . . : 10.0.0.1\r\n";
verif(function_exists('r_passerelles_ipconfig') && r_passerelles_ipconfig($ipc, 'Wi-Fi') === ['fe80::1%12', '192.168.4.1']
   && r_passerelles_ipconfig($ipc, 'Wi-Fi 2') === ['10.0.0.1'] && str_contains($srcR, 'r_hote_hotspot($c, (string)$carte[\'nom\'], $gwAvant)'),
      'hotspot : nom qui mène ailleurs → passerelle de la carte Wi-Fi (ipconfig lu, IPv4 après IPv6)', '');
effacer($dev);
foreach (glob("$pc/exploration-*.txt") ?: [] as $f) @unlink($f);
[$sc1, $cc1] = $collecteurLoin('collecter --sans-wifi --bavard');
$ptH = $pdoT->query("SELECT * FROM ConnectPoints WHERE PasserelleId = $hsId")->fetch(PDO::FETCH_ASSOC) ?: [];
$hsL = $pdoT->query("SELECT * FROM ConnectPasserelles WHERE Id = $hsId")->fetch(PDO::FETCH_ASSOC) ?: [];
$rlL = $pdoT->query("SELECT * FROM ConnectReleveurs WHERE Id = $rlId")->fetch(PDO::FETCH_ASSOC) ?: [];
$brutLu = preg_match('/chambre froide = (-?\d+)/', $sc1, $mL) ? (int)$mL[1] : null;
$ecartHeure = abs(strtotime((string)($ptH['DerniereLecture'] ?? '')) - strtotime((string)($hsL['DerniereCollecte'] ?? '')));
verif($cc1 === 0 && str_contains($sc1, '1 relevé(s) envoyé(s)') && $brutLu !== null && $ecartHeure < 60 && abs((float)($ptH['DerniereValeur'] ?? 0) - $brutLu * 0.1) < 0.001 && ($hsL['Etat'] ?? '') === 'ok'
   && !empty($hsL['DerniereCollecte']) && !empty($rlL['DernierContact']) && str_contains((string)($rlL['Journal'] ?? ''), 'point(s) lu(s)'),
      'collecteur (--sans-wifi) : lit l\'appareil avec les pilotes de Larka, envoie ; valeur, état et journal du PC dans Larka',
      ($ptH['DerniereValeur'] ?? '∅') . ' °C' . (getenv('BAVARD') ? " — $sc1" : ''));
// Larka qui redirige : le collecteur ne suit pas (le jeton ne part pas ailleurs) et dit pourquoi.
$portRedir = port_libre();
file_put_contents("$t/sim/redirige.php", '<?php header("Location: http://127.0.0.1:' . $ports['api'] . '/api/index.php", true, 302); return true;');
$lancer("$php -S 127.0.0.1:$portRedir " . escapeshellarg("$t/sim/redirige.php"));
for ($i = 0; $i < 30 && !@fsockopen('127.0.0.1', $portRedir); $i++) usleep(100000);
[$scR, $ccR] = $collecteur("configurer --sans-wifi --sans-tache --larka=http://127.0.0.1:$portRedir");
[, $ccR2] = $collecteur("configurer --sans-wifi --sans-tache --larka=http://127.0.0.1:{$ports['api']}");
verif($ccR === 1 && str_contains($scR, 'redirection') && $ccR2 === 0, 'Larka qui redirige : le collecteur s\'arrête et l\'explique, sans renvoyer le jeton ailleurs');
// Larka injoignable : le relevé attend sur le PC, puis part.
$nMes = fn() => (int)$pdoT->query("SELECT COUNT(*) FROM ConnectMesures WHERE PointId = " . (int)$ptH['Id'])->fetchColumn();
$avantFile = $nMes();
$pdoT->exec("UPDATE ConnectPoints SET DerniereValeur = 0, DernierEnregistrement = NULL WHERE Id = " . (int)$ptH['Id']);
sleep(1);                                                   // un relevé à la même seconde serait le même relevé
[$sc2, $cc2] = $collecteur('collecter --sans-wifi --larka=http://127.0.0.1:' . port_libre());
$attente = json_decode((string)@file_get_contents("$pc/en-attente.json"), true) ?: [];
[$sc3, $cc3] = $collecteur("envoyer --larka=http://127.0.0.1:{$ports['api']}");
$attente2 = json_decode((string)@file_get_contents("$pc/en-attente.json"), true) ?: [];
verif($cc2 === 1 && count($attente) === 1 && str_contains($sc2, 'gardé') && $cc3 === 0 && $attente2 === [] && $nMes() > $avantFile,
      'Larka injoignable : le relevé attend sur le PC (dernière mission gardée), puis part avec son heure', "file : " . count($attente) . (getenv('BAVARD') ? " — $cc2 $sc2 // $cc3 $sc3" : ''));
// Relevé plus ancien que la dernière valeur (file d'un second PC) : historique à son heure, dernière valeur intacte, jamais doublé.
$depotAncien = ['releves' => [['connexion' => $hsId, 'ts' => time() - 3600, 'lectures' => [(int)$ptH['Id'] => ['valeur' => 40, 'qualite' => 'bonne']]]]];
$derAvant = $pdoT->query("SELECT DerniereValeur FROM ConnectPoints WHERE Id = " . (int)$ptH['Id'])->fetchColumn();
[$cd1, $jd1] = $releveurApi('POST', 'depot', (string)$zCfg['jeton'], $depotAncien);
[$cd2] = $releveurApi('POST', 'depot', (string)$zCfg['jeton'], $depotAncien);
$nAncien = (int)$pdoT->query("SELECT COUNT(*) FROM ConnectMesures WHERE PointId = " . (int)$ptH['Id'] . " AND Horodatage < '" . $pdoT->query("SELECT DerniereLecture FROM ConnectPoints WHERE Id = " . (int)$ptH['Id'])->fetchColumn() . "' AND ABS(Valeur - 4) < 0.001")->fetchColumn();
$derApres = $pdoT->query("SELECT DerniereValeur FROM ConnectPoints WHERE Id = " . (int)$ptH['Id'])->fetchColumn();
[$cd3, $jd3] = $releveurApi('POST', 'depot', (string)$zCfg['jeton'], ['releves' => [['connexion' => (int)$prep['modbus'], 'quand' => date('Y-m-d H:i:s'), 'lectures' => []],
                                                                              ['connexion' => $hsId, 'quand' => '2020-01-01 00:00:00', 'lectures' => []]]]);
verif($cd1 === 200 && $cd2 === 200 && $nAncien === 1 && (float)$derAvant === (float)$derApres && ($jd3['data']['acceptes'] ?? -1) === 0 && count($jd3['data']['refus'] ?? []) === 2,
      'relevé ancien : rangé à son heure, sans changer la dernière valeur ni se doubler ; connexion non confiée ou date trop ancienne : refusées');
// Trois relevés de suite en échec (hotspot hors de portée) : alarme de connexion ; un relevé réussi la ferme.
$echec = fn(int $s) => ['connexion' => $hsId, 'ts' => time() + $s, 'lectures' => [], 'erreur' => 'hotspot « LOGGER-EPREUVE » introuvable'];
[$ce1] = $releveurApi('POST', 'depot', (string)$zCfg['jeton'], ['releves' => [$echec(1), $echec(2), $echec(3)]]);
$alH = fn() => $pdoT->query("SELECT Etat, Message FROM ConnectAlarmes WHERE PasserelleId = $hsId AND Origine = 'connexion' ORDER BY Id DESC")->fetch(PDO::FETCH_ASSOC) ?: [];
$al1 = $alH();
[$ce2] = $releveurApi('POST', 'depot', (string)$zCfg['jeton'], ['releves' => [['connexion' => $hsId, 'ts' => time() + 4, 'lectures' => [(int)$ptH['Id'] => ['valeur' => 50]]]]]);
$al2 = $alH();
verif($ce1 === 200 && ($al1['Etat'] ?? '') === 'active' && str_contains((string)($al1['Message'] ?? ''), 'introuvable') && $ce2 === 200 && ($al2['Etat'] ?? '') !== 'active',
      'trois relevés de suite en échec : alarme de connexion ; le relevé suivant réussi la termine', (string)($al1['Message'] ?? '∅'));
// Journal de supervision : la panne et le rétablissement, chacun avec son code et sa marche à suivre.
[$cev, $jev] = $http('v', 'connect_evenements&passerelle=' . $hsId . '&niveau=', null, 'GET');
$evs = $jev['data']['items'] ?? [];
if (getenv('LARKA_EPREUVE_DETAIL')) echo '      evenements : ' . substr(json_encode([$cev, $jev]), 0, 600) . "\n"
    . '      journal connect : ' . json_encode($pdoT->query("SELECT Niveau, Canal, Code, EntiteType, EntiteId, substr(Message,1,80) FROM Journal WHERE Canal = 'connect' ORDER BY Id DESC LIMIT 8")->fetchAll(PDO::FETCH_NUM)) . "\n";
$evPanne = array_values(array_filter($evs, fn($e) => ($e['Code'] ?? '') === 'CXH-003'))[0] ?? null;
$evRetour = array_values(array_filter($evs, fn($e) => ($e['Code'] ?? '') === 'CXC-004'))[0] ?? null;
verif($cev === 200 && $evPanne && str_contains((string)$evPanne['Message'], 'en défaut') && $evRetour && count(array_filter($evs, fn($e) => ($e['Code'] ?? '') === 'CXH-003')) === 1
   && !empty($jev['data']['codes']['CXH-003']['remedes']) && !isset($evs[0]['Ip']),
      'journal de supervision : panne (une seule ligne pour trois échecs) et rétablissement, codés, avec la marche à suivre ; lisible par le Visionneur',
      implode(' | ', array_map(fn($e) => ($e['Code'] ?? '∅') . ' ' . mb_substr((string)$e['Message'], 0, 50), array_slice($evs, 0, 4))));
[$cpc2, $jpc2] = $http('g', 'connect_parcours', null, 'GET');
verif(($jpc2['data']['hotspots'] ?? 0) === 1 && ($jpc2['data']['hotspots_sans_releveur'] ?? -1) === 0 && ($jpc2['data']['hotspots_jamais_releves'] ?? -1) === 0,
      'guide de démarrage : appareils en hotspot couverts par un PC et déjà relevés');
// Câble direct (RJ45, hors réseau) : pas de Wi-Fi ; relevé quand l'appareil répond, rien quand il n'est pas branché.
$dirCorps = ['protocole' => 'modbus', 'nom' => 'Centrale en direct', 'hote' => '127.0.0.1', 'port' => $ports['modbus'], 'parametres' => ['unite_defaut' => 1], 'acces' => 'direct'];
[$cdi1, $jdi1] = $http('g', 'connect_passerelles', $dirCorps);
[$cdi2, $jdi2] = $http('g', 'connect_passerelles', ['nom' => 'Centrale débranchée', 'port' => port_libre()] + $dirCorps);
[$cdi3, $jdi3] = $http('g', 'connect_passerelles', ['hote' => '8.8.4.4'] + $dirCorps);
[$cco, $jco] = $http('v', 'journal_codes&code=CXR-002', null, 'GET');
verif(($jdi3['code'] ?? '') === 'API-400' && $cco === 200 && ($jco['data']['qui'] ?? '') === 'service informatique' && count($jco['data']['remedes'] ?? []) >= 2,
      'erreur renvoyée avec son code ; la fiche d\'un code (explication, marche à suivre) est lisible par tout utilisateur connecté');
$dirId = (int)($jdi1['data']['id'] ?? 0); $dirOff = (int)($jdi2['data']['id'] ?? 0);
foreach ([$dirId, $dirOff] as $i) $http('g', 'connect_points', ['passerelleId' => $i, 'nom' => 'Index', 'adresse' => ['registre' => 0, 'type' => 'uint16']]);
[$cdt] = $http('g', 'connect_tester', ['id' => $dirId]);
$http('g', 'connect_releveur_enregistrer', ['id' => $rlId, 'nom' => 'Portable de l\'agent', 'connexions' => [$hsId, $dirId, $dirOff]]);
[, $jmd] = $releveurApi('GET', 'mission', (string)$zCfg['jeton']);
$mDir = array_values(array_filter($jmd['data']['connexions'] ?? [], fn($c) => (int)$c['id'] === $dirId))[0] ?? [];
verif($cdi1 === 200 && $cdi2 === 200 && $cdi3 === 422 && $cdt === 409 && ($mDir['acces'] ?? '') === 'direct' && array_key_exists('wifi', $mDir) && $mDir['wifi'] === null,
      'câble direct : déclaré sans Wi-Fi (adresse privée seulement), le serveur ne l\'interroge pas ; mission « direct », sans Wi-Fi',
      "$cdi1 $cdi2 $cdi3 $cdt " . json_encode([$mDir['acces'] ?? '∅', $mDir['wifi'] ?? 'absent', $jdi1['error'] ?? '']));
[$scd, $ccd] = $collecteur('collecter --sans-wifi --bavard');
$dL = $pdoT->query("SELECT DerniereCollecte, Etat FROM ConnectPasserelles WHERE Id = $dirId")->fetch(PDO::FETCH_ASSOC) ?: [];
$oL = $pdoT->query("SELECT DerniereCollecte, Etat FROM ConnectPasserelles WHERE Id = $dirOff")->fetch(PDO::FETCH_ASSOC) ?: [];
$alOff = (int)$pdoT->query("SELECT COUNT(*) FROM ConnectAlarmes WHERE PasserelleId = $dirOff")->fetchColumn();
verif($ccd === 0 && str_contains($scd, '🔗 Centrale en direct : 1 / 1') && str_contains($scd, 'Centrale débranchée : pas branché') && !empty($dL['DerniereCollecte'])
   && ($dL['Etat'] ?? '') === 'ok' && empty($oL['DerniereCollecte']) && $alOff === 0,
      'collecteur : l\'appareil branché est relevé ; le débranché est passé sans erreur ni alarme', getenv('BAVARD') ? $scd : '');
// Passage de 10 minutes (tâche « câble ») sans rien de branché : silencieux, rien au journal.
$pdoT->exec("UPDATE ConnectPasserelles SET Actif = 0 WHERE Id IN ($dirId, $hsId)");
clearstatcache();
$tailleLog = (int)@filesize("$pc/releveur.log");
[$scq, $ccq] = $collecteur('collecter --planifie --direct-seulement --sans-wifi');
clearstatcache();
verif($ccq === 0 && (int)@filesize("$pc/releveur.log") === $tailleLog, 'tâche de 10 minutes, rien de branché : rien au journal, sortie propre', trim($scq));
$pdoT->exec("UPDATE ConnectPasserelles SET Actif = 1 WHERE Id IN ($dirId, $hsId)");
// Ménage par l'API : points, mesures et alarmes partent avec la connexion (sinon des
// mesures orphelines resteraient accrochées aux numéros réutilisés plus loin).
foreach ([$dirId, $dirOff] as $i) $http('g', "connect_passerelles&id=$i", null, 'DELETE');

// Protocole « à détecter » : le PC releveur regarde ce que l'appareil expose (ici un faux
// compteur PE6 : page → script → /api/connector/data, trois phases par départ), Larka
// propose les réglages et les points, puis relève « à chaque relevé du PC » vers Énergie.
$pe6 = sys_get_temp_dir() . '/lrk-pe6-' . getmypid();
@mkdir("$pe6/static/js", 0700, true);
file_put_contents("$pe6/index.html", '<html><head><title>Power-Elec 6</title></head><body><a href="visualisation.html">Visualisation</a></body></html>');
file_put_contents("$pe6/visualisation.html", '<html><head><script src="/static/js/visu.js"></script></head><body>…</body></html>');
file_put_contents("$pe6/static/js/visu.js", "setInterval(function(){ fetch('/api/connector/data').then(r => r.json()).then(afficher); }, 2000);");
file_put_contents("$pe6/idx.txt", '203315.2');
file_put_contents("$pe6/r.php", '<?php if (parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH) !== "/api/connector/data") return false;
$i = (float)file_get_contents(__DIR__ . "/idx.txt");
$v = fn($ph, $e, $on = 1) => ["Enable" => $on, "Mode" => 1, "Phase" => $ph, "Name" => $on ? "Comptage general" : "", "IndexEnergyActImport" => $e,
                              "PuissanceActive" => 1200, "PF" => 0.93, "Current" => 4.2, "Voltage" => 231.4, "Freq" => 50.01];
header("Content-Type: application/json");
echo json_encode(["Connector" => [["Channel" => [$v(1, $i), $v(2, $i + 10), $v(3, $i + 20)]], ["Channel" => [$v(1, 0, 0), $v(2, 0, 0), $v(3, 0, 0)]]]]);');
// Adresse propre à cette épreuve : un faux appareil resté d'une épreuve interrompue ne répond pas à sa place.
$ipPe6 = '127.0.' . (20 + getmypid() % 200) . '.79';
$pPe6 = proc_open("exec $php -S $ipPe6:8000 -t " . escapeshellarg($pe6) . ' ' . escapeshellarg("$pe6/r.php"), [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $tubesPe6);
for ($i = 0; $i < 30 && !@fsockopen($ipPe6, 8000); $i++) usleep(100000);
$autoCorps = ['protocole' => 'auto', 'nom' => 'Compteur PE6 à détecter', 'hote' => $ipPe6, 'acces' => 'hotspot',
              'wifi' => ['ssid' => 'PE6-EPREUVE', 'securite' => 'wpa2'], 'secrets' => ['wifi_cle' => 'cle-epreuve-26']];
[$cau0, $jau0] = $http('g', 'connect_passerelles', ['acces' => 'reseau'] + $autoCorps);
[$cau, $jau] = $http('g', 'connect_passerelles', $autoCorps);
$autoId = (int)($jau['data']['id'] ?? 0);
[$ctau] = $http('g', 'connect_tester', ['id' => $autoId]);
$http('g', 'connect_releveur_enregistrer', ['id' => $rlId, 'nom' => 'Portable de l\'agent', 'connexions' => [$hsId, $autoId]]);
[, $jmA] = $releveurApi('GET', 'mission', (string)$zCfg['jeton']);
$mA = array_values(array_filter($jmA['data']['connexions'] ?? [], fn($c) => (int)$c['id'] === $autoId))[0] ?? [];
verif($cau0 === 422 && str_contains((string)($jau0['error'] ?? ''), 'hors réseau') && $cau === 200 && $autoId && $ctau === 409 && !empty($mA['reconnaissance']),
      'protocole « à détecter » : hors réseau seulement ; le serveur ne l\'interroge pas ; mission : détection demandée au PC',
      "$cau0 $cau $ctau " . (string)($jau0['error'] ?? $jau['error'] ?? ''));
[$sca, $cca] = $collecteur('collecter --sans-wifi --bavard');
$lA = $pdoT->query("SELECT Reconnaissance, ReconDate, ReconDemandee FROM ConnectPasserelles WHERE Id = $autoId")->fetch(PDO::FETCH_ASSOC) ?: [];
[$cra, $jra] = $http('g', "connect_reconnaissance&id=$autoId", null, 'GET');
$ra = $jra['data'] ?? [];
$ptsA = array_column($ra['points'] ?? [], null, 'code');
$vRl = (string)$pdoT->query("SELECT Version FROM ConnectReleveurs WHERE Id = $rlId")->fetchColumn();
$vSrc = preg_match("/const R_VERSION = '([^']+)'/", (string)@file_get_contents("$racine/api/connect/releveur/releveur.php"), $mV) ? $mV[1] : '?';
verif($vRl === $vSrc && ($ra['version_collecteur'] ?? '') === $vSrc && !empty($ra['releveurs'][0]['Detection']) && ($ra['releveurs'][0]['Version'] ?? '') === $vSrc,
      'version du collecteur notée à chaque mission (en-tête du PC) : l\'écran dit quel PC doit retélécharger le sien', "$vRl / $vSrc");
$tot = $ptsA['Connector.0.Channel.*.IndexEnergyActImport'] ?? [];
verif($cca === 0 && str_contains($sca, 'détection de ce que l\'appareil expose') && !empty($lA['ReconDate']) && empty($lA['ReconDemandee'])
   && !str_contains((string)$lA['Reconnaissance'], '"corps":"<html') && $cra === 200 && ($ra['suggestion']['protocole'] ?? '') === 'rest'
   && ($ra['suggestion']['parametres']['url_base'] ?? '') === "http://$ipPe6:8000" && ($ra['suggestion']['parametres']['url_point'] ?? '') === '/api/connector/data'
   && !empty($tot['coche']) && abs((float)($tot['valeur'] ?? 0) - 609975.6) < 0.01 && ($tot['unite'] ?? '') === 'kWh' && ($tot['nature'] ?? '') === 'compteur'
   && str_contains((string)($tot['nom'] ?? ''), 'Comptage general') && empty($ptsA['Connector.0.Channel.0.Voltage']['coche']) && !isset($ptsA['Connector.1.Channel.0.Voltage']),
      'détection par le PC : page → script → API JSON ; Larka propose l\'API REST et les points (total des 3 phases coché, voie éteinte ignorée)',
      json_encode([$cca, $ra['suggestion']['libelle'] ?? null, $tot['nom'] ?? null, $tot['valeur'] ?? null, count($ptsA)], JSON_UNESCAPED_UNICODE) . (getenv('BAVARD') ? " — $sca" : ''));
$choix = [['code' => 'Connector.0.Channel.*.IndexEnergyActImport', 'nom' => 'Comptage général — énergie', 'nature' => 'compteur', 'unite' => 'kWh'],
          ['code' => 'Connector.0.Channel.0.Voltage', 'nom' => '', 'nature' => 'mesure', 'unite' => 'V'],
          ['code' => 'Connector.0.Channel.0.Voltage/../../etc', 'nom' => 'Injecté', 'nature' => 'mesure', 'unite' => '']];
[$cap0] = $http('v', 'connect_reconnaissance', ['op' => 'appliquer', 'id' => $autoId, 'reglages' => true, 'points' => $choix]);
[$cap, $jap] = $http('g', 'connect_reconnaissance', ['op' => 'appliquer', 'id' => $autoId, 'reglages' => true, 'points' => $choix]);
$paA = $pdoT->query("SELECT Protocole, Parametres FROM ConnectPasserelles WHERE Id = $autoId")->fetch(PDO::FETCH_ASSOC) ?: [];
$ptA = $pdoT->query("SELECT Id, Nom, Adresse, Unite, Nature FROM ConnectPoints WHERE PasserelleId = $autoId ORDER BY Id")->fetchAll(PDO::FETCH_ASSOC);
verif($cap0 === 403 && $cap === 200 && ($jap['data']['crees'] ?? 0) === 2 && ($paA['Protocole'] ?? '') === 'rest' && str_contains((string)$paA['Parametres'], '"acces":"hotspot"')
   && str_contains((string)$paA['Parametres'], '"url_point":"/api/connector/data"') && count($ptA) === 2 && ($ptA[0]['Nom'] ?? '') === 'Comptage général — énergie'
   && str_contains((string)($ptA[0]['Adresse'] ?? ''), 'Connector.0.Channel.*.IndexEnergyActImport') && str_contains((string)($ptA[1]['Nom'] ?? ''), 'Tension'),
      'appliquer : connexion passée en API REST (Wi-Fi gardé), seuls les points proposés sont créés (adresse reprise de la détection)',
      json_encode([$cap0, $cap, $jap['data'] ?? $jap['error'] ?? null, $paA['Protocole'] ?? null, array_column($ptA, 'Nom')], JSON_UNESCAPED_UNICODE));
// Relevé « à chaque relevé du PC releveur » vers un compteur du module Énergie.
$pdoT->exec("INSERT INTO CompteurEnergie (Nom, Type, Unite, Site, Actif) VALUES ('Électricité — local PE6', 'Electricite', 'kWh', 'Épreuve', 1)");
$cptPe6 = (int)$pdoT->lastInsertId();
$ptTot = (int)($ptA[0]['Id'] ?? 0);
[$cli, $jli2] = $http('g', 'connect_liaisons', ['nom' => 'PE6 → Énergie', 'pointId' => $ptTot, 'cibleId' => $cptPe6, 'frequence' => 'releve', 'facteurAnomalie' => 0]);
$lia = $pdoT->query("SELECT Frequence, ProchaineExecution FROM ConnectLiaisons WHERE PointId = $ptTot")->fetch(PDO::FETCH_ASSOC) ?: [];
[$scb, $ccb] = $collecteur('collecter --sans-wifi --bavard');
file_put_contents("$pe6/idx.txt", '203325.2');
sleep(1);
[$scc, $ccc] = $collecteur('collecter --sans-wifi');
$relPe6 = $pdoT->query("SELECT IndexDebut, IndexFin, Consommation, SaisieParLogin FROM ReleverEnergie WHERE CompteurId = $cptPe6 ORDER BY Id")->fetchAll(PDO::FETCH_ASSOC);
verif($cli === 200 && ($lia['Frequence'] ?? '') === 'releve' && $lia['ProchaineExecution'] === null && $ccb === 0 && $ccc === 0 && count($relPe6) === 2
   && abs((float)$relPe6[0]['IndexFin'] - 609975.6) < 0.01 && abs((float)$relPe6[1]['Consommation'] - 30) < 0.01,
      'liaison « à chaque relevé du PC releveur » : chaque dépôt du PC écrit le relevé dans le module Énergie (index, consommation)',
      json_encode([$cli, $jli2['error'] ?? null, $lia, $relPe6], JSON_UNESCAPED_UNICODE) . (getenv('BAVARD') ? " — $scb // $scc" : ''));
// Suppression groupée depuis la liste des points.
[$cps0] = $http('v', 'connect_points_supprimer', ['ids' => [(int)($ptA[1]['Id'] ?? 0)]]);
[$cps, $jps] = $http('g', 'connect_points_supprimer', ['ids' => [(int)($ptA[1]['Id'] ?? 0), 999999]]);
$resteA = (int)$pdoT->query("SELECT COUNT(*) FROM ConnectPoints WHERE PasserelleId = $autoId")->fetchColumn();
verif($cps0 === 403 && $cps === 200 && ($jps['data']['supprimes'] ?? 0) === 1 && $resteA === 1,
      'points : suppression de plusieurs à la fois (gestionnaire), les numéros inconnus ignorés');
proc_terminate($pPe6); proc_close($pPe6);
effacer($pe6);
// Retour au réseau habituel. PC d'entreprise, localisation de Windows désactivée : netsh tait
// le Wi-Fi, et le nom de réseau de Windows est celui du domaine (« lesagencesdeleau.eu »), qui
// n'est pas un profil. Le collecteur prend le nom du Wi-Fi et le profil donnés à PowerShell (ou le
// journal Wi-Fi de Windows), et ne retient qu'un profil du PC — y compris ceux de stratégie de groupe.
$srcR = (string)@file_get_contents("$racine/api/connect/releveur/releveur.php");
foreach (['r_wifi_profils_netsh', 'r_wifi_powershell', 'r_wifi_choisir_origine'] as $fn) {
    if (!function_exists($fn) && preg_match('/function ' . $fn . '\(.*?\n}\n/s', $srcR, $mF)) eval($mF[0]);
}
$nb = "\u{00A0}";
$profFr = "\r\nProfils sur l'interface Wi-Fi$nb:\r\n\r\nProfils de stratégie de groupe (lecture seule)\r\n---------------------------------\r\n    AEAG-Corp\r\n    AEAG-Invites\r\n\r\n"
        . "Profils utilisateurs\r\n-------------------\r\n    Profil Tous les utilisateurs$nb  : PE6-80fd5534\r\n    Profil Tous les utilisateurs$nb  : Livebox-1234\r\n\r\n";
$profEn = "Profiles on interface Wi-Fi:\n\nGroup policy profiles (read only)\n---------------------------------\n    <None>\n\nUser profiles\n-------------\n    All User Profile     : Home\n    Current User Profile : Phone\n";
$psSortie = "C\tWi-Fi\tUp\tlesagencesdeleau.eu\tAEAG\tAEAG\t3F2504E0-4F89-11D3-9A0C-0305E82C3301\r\nE\t3F2504E0-4F89-11D3-9A0C-0305E82C3301\tPE6-80fd5534\tPE6-80fd5534\r\nE\t3F2504E0-4F89-11D3-9A0C-0305E82C3301\tAEAG-Corp\tAEAG\r\n";
$ssidDe = fn(string $p) => ['AEAG-Corp' => 'AEAG', 'AEAG-Invites' => 'AEAG-Guest', 'PE6-80fd5534' => 'PE6-80fd5534', 'Livebox-1234' => 'Livebox-1234'][$p] ?? '';
$connusFr = function_exists('r_wifi_profils_netsh') ? r_wifi_profils_netsh($profFr) : [];
$psL = function_exists('r_wifi_powershell') ? r_wifi_powershell($psSortie) : ['cartes' => [], 'evenements' => []];
$cands = [[$psL['cartes'][0]['profil'] ?? '', 'profil'], [$psL['cartes'][0]['ssid'] ?? '', 'ssid']];
foreach ($psL['evenements'] as $e) $cands[] = [$e['profil'], 'journal'];
$exclusH = ['PE6-80fd5534'];
$o1 = function_exists('r_wifi_choisir_origine') ? r_wifi_choisir_origine($cands, $connusFr, $exclusH, $ssidDe) : [null, ''];
// Resté sur le hotspot après un relevé manqué : le journal donne la connexion d'avant.
$o2 = function_exists('r_wifi_choisir_origine') ? r_wifi_choisir_origine([['PE6-80fd5534', 'ssid'], ['PE6-80fd5534', 'journal'], ['AEAG-Corp', 'journal']], $connusFr, $exclusH, $ssidDe) : [null, ''];
$o3 = function_exists('r_wifi_choisir_origine') ? r_wifi_choisir_origine([['lesagencesdeleau.eu', 'réseau']], $connusFr, $exclusH, $ssidDe) : ['x', ''];
verif($connusFr === ['AEAG-Corp', 'AEAG-Invites', 'PE6-80fd5534', 'Livebox-1234'] && r_wifi_profils_netsh($profEn) === ['Home', 'Phone']
   && ($psL['cartes'][0]['reseau'] ?? '') === 'lesagencesdeleau.eu' && ($psL['cartes'][0]['ssid'] ?? '') === 'AEAG' && count($psL['evenements']) === 2
   && ($o1[0] ?? null) === 'AEAG-Corp' && ($o2[0] ?? null) === 'AEAG-Corp' && $o3[0] === null,
      'retour au réseau : profils du PC (dont ceux de l\'entreprise, par stratégie de groupe) ; Wi-Fi « AEAG » → profil « AEAG-Corp », jamais le nom du domaine ni le hotspot',
      json_encode([$connusFr, $o1, $o2, $o3], JSON_UNESCAPED_UNICODE));
$fRendre = preg_match('/function r_rendre_reseau.*?\n}\n/s', $srcR, $mRr) ? $mRr[0] : '';
verif($fRendre !== '' && str_contains($fRendre, 'refusé par Windows"); return false; }') && str_contains($fRendre, 'array_intersect($g, $gwHotspots)) return false;')
   && substr_count($srcR, 'r_rendre_reseau($wifi, ') >= 2 && str_contains($srcR, '$wifi->origine($carte, $exclus)') && str_contains($srcR, "'GetConnectedSsid'") === false
   && str_contains($srcR, 'GetConnectedSsid()') && str_contains($srcR, 'WLAN-AutoConfig/Operational'),
      'retour au réseau : profil refusé = pas d\'attente inutile ; « revenu » seulement hors du hotspot (pas sur la seule foi de Larka joignable) ; chaque essai au journal');
foreach (glob("$pc/exploration-*.txt") ?: [] as $f) @unlink($f);
$http('g', "connect_passerelles&id=$autoId", null, 'DELETE');
$http('g', 'connect_releveur_enregistrer', ['id' => $rlId, 'nom' => 'Portable de l\'agent', 'connexions' => [$hsId]]);

// Retour au réseau, rejoué de bout en bout : la logique Windows du collecteur avec de faux netsh,
// powershell et ip. PC d'entreprise : localisation coupée (netsh tait le Wi-Fi), réseau Windows au
// nom du domaine, Wi-Fi « AEAG » d'un profil de stratégie de groupe « AEAG-Corp ».
$fw = sys_get_temp_dir() . '/lrk-fauxwifi-' . getmypid();
@mkdir($fw, 0700, true);
$etatW = fn() => trim((string)@file_get_contents("$fw/etat"));
$prepW = function (bool $winrt, bool $journal, bool $auto) use ($fw) {
    file_put_contents("$fw/etat", 'AEAG-Corp');
    if (!is_file("$fw/connus")) file_put_contents("$fw/connus", "AEAG-Corp\nAEAG-Invites\nLivebox-1234\n");   // les profils restent d'un passage à l'autre
    foreach (['winrt' => $winrt, 'journal' => $journal, 'auto' => $auto] as $f => $oui) { if ($oui) touch("$fw/$f"); else @unlink("$fw/$f"); }
    @unlink("$fw/hotspot");
};
file_put_contents("$fw/commun.sh", <<<'SH'
E="$FAUX_WIFI"
etat() { s=$(cat "$E/etat"); case "$s" in auto:*) t=${s#auto:}; if [ $(( $(date +%s) - t )) -ge 3 ]; then echo AEAG-Corp > "$E/etat"; s=AEAG-Corp; else s=none; fi;; esac; echo "$s"; }
SH);
file_put_contents("$fw/netsh", <<<'SH'
#!/bin/sh
. "$(dirname "$0")/commun.sh"
case "$1 $2 $3" in
  "wlan show interfaces") printf 'Il existe 1 interface sur le système :\n\nLes commandes de l’environnement réseau nécessitent une autorisation d’emplacement pour accéder aux informations WLAN.\n'; exit 0;;
  "wlan show profiles")
    printf "Profils sur l'interface Wi-Fi :\n\nProfils de stratégie de groupe (lecture seule)\n---------------------------------\n    AEAG-Corp\n    AEAG-Invites\n\nProfils utilisateurs\n-------------------\n"
    grep -v '^AEAG-' "$E/connus" | while read -r p; do printf '    Profil Tous les utilisateurs   : %s\n' "$p"; done; exit 0;;
  "wlan show profile") n="${4#name=}"; [ "$n" = AEAG-Corp ] && s=AEAG || s="$n"; printf '    Nom du SSID              : "%s"\n' "$s"; exit 0;;
  "wlan add profile") f="${4#filename=}"; sed -n 's:.*<name>\([^<]*\)</name><SSIDConfig>.*:\1:p' "$f" >> "$E/connus"; exit 0;;
  "wlan delete profile") exit 0;;
  "wlan disconnect interface=Wi-Fi") if [ -f "$E/auto" ]; then echo "auto:$(date +%s)" > "$E/etat"; else echo none > "$E/etat"; fi; exit 0;;
esac
if [ "$1 $2" = "wlan connect" ]; then
  n="${3#name=}"
  if grep -qxF "$n" "$E/connus"; then echo "$n" > "$E/etat"; [ "$n" != AEAG-Corp ] && touch "$E/hotspot"; echo "La demande de connexion a été effectuée."; exit 0; fi
  echo "Aucun profil « $n » n'est attribué à l'interface spécifiée."; exit 1
fi
exit 1
SH);
file_put_contents("$fw/ip", <<<'SH'
#!/bin/sh
. "$(dirname "$0")/commun.sh"
case "$(etat)" in none) ;; AEAG-Corp) echo "default via 10.1.0.1 dev Wi-Fi";; *) echo "default via 10.42.0.1 dev Wi-Fi";; esac
SH);
file_put_contents("$fw/powershell", <<<'SH'
#!/bin/sh
. "$(dirname "$0")/commun.sh"
s=$(etat); G=3F2504E0-4F89-11D3-9A0C-0305E82C3301
if [ "$s" = none ]; then printf 'C\tWi-Fi\tDisconnected\t\t\t\t%s\n' $G
else
  [ "$s" = AEAG-Corp ] && { r=lesagencesdeleau.eu; w=AEAG; } || { r="$s"; w="$s"; }
  [ -f "$E/winrt" ] || w=""
  printf 'C\tWi-Fi\tUp\t%s\t%s\t%s\t%s\n' "$r" "$w" "$w" $G
fi
case "$*" in *Get-WinEvent*) if [ -f "$E/journal" ]; then
  [ -f "$E/hotspot" ] && printf 'E\t%s\tLOGGER-EPREUVE\tLOGGER-EPREUVE\n' $G
  printf 'E\t%s\tAEAG-Corp\tAEAG\n' $G; fi;; esac
exit 0
SH);
foreach (['netsh', 'ip', 'powershell'] as $x) chmod("$fw/$x", 0755);
$collecteurWin = function (string $args) use ($php, $pc, $fw): array {
    exec('cd ' . escapeshellarg($pc) . ' && PATH=' . escapeshellarg($fw) . ':$PATH FAUX_WIFI=' . escapeshellarg($fw)
         . " LARKA_RELEVEUR_ESSAI_WIFI=windows $php releveur.php $args 2>&1", $o, $c);
    return [implode("\n", $o), $c];
};
$logAvant = (int)@filesize("$pc/releveur.log");
// 1. Windows donne le nom du Wi-Fi et le profil (WinRT) et le journal Wi-Fi : retour direct sur « AEAG-Corp ».
$prepW(true, true, false);
$t0 = time();
[$sw1] = $collecteurWin('tester --bavard');
$d1 = time() - $t0;
clearstatcache();
$log1 = substr((string)@file_get_contents("$pc/releveur.log"), $logAvant);
$e1 = $etatW();
// 2. Rien que le nom du domaine (ni WinRT, ni journal) : hotspot quitté, Windows reprend son réseau de lui-même.
$prepW(false, false, true);
[$sw2] = $collecteurWin('tester --bavard');
$e2 = $etatW();
// 3. « etat » : ce que le PC voit du Wi-Fi, pour comprendre un retour difficile.
$prepW(true, true, false);
[$sw3] = $collecteurWin('etat');
verif(str_contains($sw1, 'réseau habituel « AEAG-Corp » repris après le relevé') && str_contains($sw1, '→ Hotspot « LOGGER-EPREUVE »') && $e1 === 'AEAG-Corp'
   && str_contains($log1, 'retour : profil « AEAG-Corp » — réussi') && !str_contains($log1, 'lesagencesdeleau.eu » — refusé') && $d1 < 40,
      'retour au réseau (Windows, localisation coupée) : profil de l\'entreprise retrouvé sans le nom du domaine, rejoint aussitôt', "{$d1} s — $e1" . (getenv('BAVARD') ? "\n$sw1\n$log1" : ''));
verif(str_contains($sw2, 'réseau habituel non identifié') && str_contains($sw2, '→ Hotspot « LOGGER-EPREUVE »') && !str_contains($sw2, 'introuvable ou refusé')
   && str_contains($sw2, '← Retour sur le réseau habituel') && str_contains($sw2, 'réseau habituel repris par Windows') && $e2 === 'AEAG-Corp',
      'retour au réseau, profil introuvable : hotspot quitté, Windows reprend son réseau ; jamais bloqué sur le hotspot', $e2 . (getenv('BAVARD') ? "\n$sw2" : ''));
verif(str_contains($sw3, 'lu par powershell') && str_contains($sw3, 'connectée à « AEAG »') && str_contains($sw3, 'réseau Windows « lesagencesdeleau.eu »')
   && str_contains($sw3, 'Réseau repris après un relevé : « AEAG-Corp »') && str_contains($sw3, '« AEAG-Corp », « AEAG-Invites »'),
      '« releveur.cmd etat » : Wi-Fi vu, réseau qui sera repris et d\'où il vient, profils du PC', getenv('BAVARD') ? $sw3 : '');
effacer($fw);

// Automate BACnet/IP branché par câble (Sauter modu525, par exemple) : déclaré « à détecter » avec
// son adresse, le PC lit en lui qui il est et tous ses points (nom, unité, valeur) ; Larka propose
// BACnet, l'automate et les points ; appliqué, le relevé suivant les lit.
$ipBn = '127.0.' . (20 + (getmypid() + 7) % 200) . '.88';
file_put_contents("$t/sim/bacnet-faux.php", <<<'PHP'
<?php
// Faux automate BACnet/IP (lecture seule) pour l'épreuve : Who-Is → I-Am, ReadProperty, RPM refusé.
[, $ip, $port, $duree] = $argv + [1 => '127.0.0.88', 2 => '47808', 3 => '60'];
$s = stream_socket_server("udp://$ip:$port", $en, $es, STREAM_SERVER_BIND);
if (!$s) { fwrite(STDERR, "$es\n"); exit(1); }
const INST = 2012;
$oid = fn(int $t, int $i) => pack('N', (($t & 0x3FF) << 22) | ($i & 0x3FFFFF));
$chaine = function (string $t) { $d = "\x00" . $t; $n = strlen($d); return $n <= 4 ? chr(0x70 | $n) . $d : "\x75" . chr($n) . $d; };
$val = function (array $v) use ($chaine) {
    return match ($v[0]) { 's' => $chaine($v[1]), 'r' => "\x44" . pack('G', $v[1]), 'e' => "\x91" . chr($v[1]), default => '' };
};
$objets = [[8, INST], [0, 1], [2, 2], [0, 3], [3, 4]];
$props = [
    '8:' . INST => [77 => ['s', 'Chaufferie-AS1'], 121 => ['s', 'Fr. Sauter AG'], 70 => ['s', 'modu525'], 58 => ['s', 'Sous-sol']],
    '0:1' => [77 => ['s', 'Température départ chauffage'], 85 => ['r', 45.5], 117 => ['e', 62]],
    '2:2' => [77 => ['s', 'Consigne départ chauffage'], 85 => ['r', 50.0], 117 => ['e', 62]],
    '0:3' => [77 => ['s', 'Compteur énergie chaufferie'], 85 => ['r', 12345.5], 117 => ['e', 19]],
    '3:4' => [77 => ['s', 'Défaut brûleur'], 85 => ['e', 0]],
];
$trame = fn(string $apdu) => "\x81\x0A" . pack('n', 6 + strlen($apdu)) . "\x01\x00" . $apdu;
$fin = microtime(true) + (float)$duree;
while (microtime(true) < $fin) {
    $r = [$s]; $w = $x = null;
    if (@stream_select($r, $w, $x, 0, 200000) < 1) continue;
    $dg = stream_socket_recvfrom($s, 2048, 0, $de);
    if (!is_string($dg) || strlen($dg) < 8 || ord($dg[0]) !== 0x81) continue;
    $ctl = ord($dg[5]); $p = 6;
    if ($ctl & 0x20) { $p += 3 + ord($dg[$p + 2]); }
    if ($ctl & 0x08) { $p += 3 + ord($dg[$p + 2]); }
    if ($ctl & 0x20) $p++;
    $apdu = substr($dg, $p);
    if (ord($apdu[0]) === 0x10 && ord($apdu[1]) === 0x08) {                    // Who-Is
        stream_socket_sendto($s, $trame("\x10\x00\xC4" . $oid(8, INST) . "\x22\x05\xC4\x91\x03\x21\x50"), 0, $de);
        continue;
    }
    if ((ord($apdu[0]) >> 4) !== 0) continue;
    $inv = ord($apdu[2]); $svc = ord($apdu[3]); $b = substr($apdu, 4);
    if ($svc !== 12) { stream_socket_sendto($s, $trame("\x60" . chr($inv) . "\x09"), 0, $de); continue; }   // RPM et autres : refusés
    $v = unpack('N', substr($b, 1, 4))[1]; $t = $v >> 22; $i = $v & 0x3FFFFF; $q = 5;
    if (ord($b[$q]) === 0x19) { $prop = ord($b[$q + 1]); $q += 2; } else { $prop = unpack('n', substr($b, $q + 1, 2))[1]; $q += 3; }
    $idx = (isset($b[$q]) && ord($b[$q]) === 0x29) ? ord($b[$q + 1]) : null;
    if ($t === 8 && $i === 4194303) $i = INST;
    $valeurs = null;
    if ($t === 8 && $prop === 75) $valeurs = "\xC4" . $oid(8, INST);
    elseif ($t === 8 && $prop === 76) {
        if ($idx === null) $valeurs = implode('', array_map(fn($o) => "\xC4" . $oid($o[0], $o[1]), $objets));
        elseif ($idx === 0) $valeurs = "\x21" . chr(count($objets));
        elseif (isset($objets[$idx - 1])) $valeurs = "\xC4" . $oid($objets[$idx - 1][0], $objets[$idx - 1][1]);
    } elseif (isset($props["$t:$i"][$prop])) $valeurs = $val($props["$t:$i"][$prop]);
    if ($valeurs === null) { stream_socket_sendto($s, $trame("\x50" . chr($inv) . "\x0C\x91\x02\x91\x20"), 0, $de); continue; }
    $ack = "\x30" . chr($inv) . "\x0C\x0C" . $oid($t, $i) . ($prop < 256 ? "\x19" . chr($prop) : "\x1A" . pack('n', $prop))
         . ($idx !== null ? "\x29" . chr($idx) : '') . "\x3E" . $valeurs . "\x3F";
    stream_socket_sendto($s, $trame($ack), 0, $de);
}
PHP);
$pBn = proc_open("exec $php " . escapeshellarg("$t/sim/bacnet-faux.php") . " $ipBn 47808 120", [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $tubesBn);
usleep(400000);
[$cbn, $jbn] = $http('g', 'connect_passerelles', ['protocole' => 'auto', 'nom' => 'GTB chaufferie (câble)', 'hote' => $ipBn, 'acces' => 'direct']);
$bnId = (int)($jbn['data']['id'] ?? 0);
$http('g', 'connect_releveur_enregistrer', ['id' => $rlId, 'nom' => 'Portable de l\'agent', 'connexions' => [$hsId, $bnId]]);
[$sbn1] = $collecteur('collecter --sans-wifi --bavard');
[, $jrb] = $http('g', "connect_reconnaissance&id=$bnId", null, 'GET');
$rb = $jrb['data'] ?? [];
$pb = array_column($rb['points'] ?? [], null, 'code');
verif($cbn === 200 && $bnId && ($rb['suggestion']['protocole'] ?? '') === 'bacnet' && ($rb['suggestion']['identifiant'] ?? '') === '2012'
   && ($rb['suggestion']['hote'] ?? '') === $ipBn && count($pb) === 4 && ($pb['analog-input,3']['unite'] ?? '') === 'kWh' && !empty($pb['analog-input,3']['coche'])
   && ($pb['analog-input,1']['nom'] ?? '') === 'Température départ chauffage' && ($pb['binary-input,4']['nature'] ?? '') === 'alarme' && empty($pb['analog-input,1']['coche'])
   && str_contains(implode(' ', $rb['resume'] ?? []), 'Fr. Sauter AG modu525'),
      'câble direct, automate BACnet : le PC lit en lui son n°, son nom et tous ses points ; Larka propose BACnet et les points (compteurs cochés)',
      json_encode([$cbn, $rb['suggestion'] ?? null, array_keys($pb), $rb['resume'] ?? null], JSON_UNESCAPED_UNICODE) . (getenv('BAVARD') ? "\n$sbn1" : ''));
[$cab, $jab] = $http('g', 'connect_reconnaissance', ['op' => 'appliquer', 'id' => $bnId, 'reglages' => true, 'points' => [
    ['code' => 'analog-input,3', 'nom' => 'Compteur énergie chaufferie', 'nature' => 'compteur', 'unite' => 'kWh'],
    ['code' => 'analog-input,1', 'nom' => 'Température départ', 'nature' => 'mesure', 'unite' => '°C']]]);
$paB = $pdoT->query("SELECT Protocole, Hote, Port, Identifiant, Parametres FROM ConnectPasserelles WHERE Id = $bnId")->fetch(PDO::FETCH_ASSOC) ?: [];
sleep(1);
[$sbn2] = $collecteur('collecter --sans-wifi --bavard');
$vB = $pdoT->query("SELECT Nom, DerniereValeur FROM ConnectPoints WHERE PasserelleId = $bnId ORDER BY Id")->fetchAll(PDO::FETCH_KEY_PAIR);
verif($cab === 200 && ($jab['data']['crees'] ?? 0) === 2 && ($paB['Protocole'] ?? '') === 'bacnet' && ($paB['Identifiant'] ?? '') === '2012' && ($paB['Hote'] ?? '') === $ipBn
   && str_contains((string)$paB['Parametres'], '"acces":"direct"') && abs((float)($vB['Compteur énergie chaufferie'] ?? 0) - 12345.5) < 0.01 && abs((float)($vB['Température départ'] ?? 0) - 45.5) < 0.01,
      'appliquer : connexion BACnet (câble direct gardé, n° d\'automate) ; le relevé suivant lit les points choisis',
      json_encode([$cab, $jab['data'] ?? $jab['error'] ?? null, $paB, $vB], JSON_UNESCAPED_UNICODE) . (getenv('BAVARD') ? "\n$sbn2" : ''));
proc_terminate($pBn); proc_close($pBn);
$http('g', "connect_passerelles&id=$bnId", null, 'DELETE');
$http('g', 'connect_releveur_enregistrer', ['id' => $rlId, 'nom' => 'Portable de l\'agent', 'connexions' => [$hsId]]);
foreach (glob("$pc/exploration-*.txt") ?: [] as $f) @unlink($f);
// Larka en HTTPS avec un certificat auto-signé (réseau interne) : le collecteur épingle CE certificat.
$pk = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$x509 = openssl_csr_sign(openssl_csr_new(['commonName' => 'larka-interne.local'], $pk), null, $pk, 30);
openssl_x509_export($x509, $pemCert); openssl_pkey_export($pk, $pemCle);
file_put_contents("$t/sim/tls.pem", $pemCert . $pemCle);
$fpAttendu = openssl_x509_fingerprint($x509, 'sha256');
file_put_contents("$t/sim/tls.php", <<<'PHP'
<?php
// Terminaison TLS minimale devant le serveur de l'épreuve (certificat auto-signé).
[, $port, $arriere, $pem, $duree] = $argv;
$ctx = stream_context_create(['ssl' => ['local_cert' => $pem, 'verify_peer' => false, 'allow_self_signed' => true]]);
$srv = stream_socket_server("ssl://127.0.0.1:$port", $en, $es, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
$fin = microtime(true) + (float)$duree;
while (microtime(true) < $fin) {
    $r = [$srv]; $w = $x = null;
    if (@stream_select($r, $w, $x, 0, 200000) < 1) continue;
    $c = @stream_socket_accept($srv, 3);
    if (!$c) continue;                                   // poignée de main refusée par le client
    stream_set_timeout($c, 5);
    $req = '';
    while (!str_contains($req, "\r\n\r\n")) { $b = @fread($c, 8192); if ($b === '' || $b === false) break; $req .= $b; }
    if ($req === '') { @fclose($c); continue; }
    if (preg_match('/Content-Length:\s*(\d+)/i', $req, $m)) {
        while (strlen($req) - strpos($req, "\r\n\r\n") - 4 < (int)$m[1]) { $b = @fread($c, 8192); if ($b === '' || $b === false) break; $req .= $b; }
    }
    $a = @stream_socket_client("tcp://127.0.0.1:$arriere", $e, $es2, 5);
    if ($a) { fwrite($a, $req); $rep = stream_get_contents($a); fclose($a); @fwrite($c, $rep); }
    @fclose($c);
}
PHP);
$portTls = port_libre();
$lancer("$php " . escapeshellarg("$t/sim/tls.php") . " $portTls {$ports['api']} " . escapeshellarg("$t/sim/tls.pem") . ' 120');
for ($i = 0; $i < 30 && !@fsockopen('127.0.0.1', $portTls); $i++) usleep(100000);
[$cpqT, , $brutT] = $http('g', 'connect_releveur_paquet', ['id' => $rlId, 'url' => "https://127.0.0.1:$portTls"]);
$pcT = "$t/sim/pc-tls";
@mkdir($pcT, 0700, true);
file_put_contents("$pcT/c.zip", $brutT);
$zT = new ZipArchive(); $zT->open("$pcT/c.zip"); $zT->extractTo($pcT); $zT->close();
$pcT .= '/larka-releveur';
$cfgT = json_decode((string)@file_get_contents("$pcT/config.json"), true) ?: [];
$lanceT = function (string $args) use ($php, $pcT): array { exec('cd ' . escapeshellarg($pcT) . " && $php releveur.php $args 2>&1", $o, $c); return [implode("\n", $o), $c]; };
[$sT1, $cT1] = $lanceT('configurer --sans-wifi --sans-tache');
verif($cpqT === 200 && ($cfgT['empreinte_certificat'] ?? '') === $fpAttendu && $cT1 === 0,
      'HTTPS à certificat auto-signé : le collecteur téléchargé connaît déjà l\'empreinte du certificat de Larka et s\'y connecte', $cT1 ? str_replace("\n", " ¶ ", $sT1) . " cfg=" . json_encode($cfgT) : '');
// Sans empreinte : refus explicite (certificat), et « confiance » l'épingle ; une autre empreinte : refusée.
unset($cfgT['empreinte_certificat']);
file_put_contents("$pcT/config.json", json_encode($cfgT));
[$sT2, $cT2] = $lanceT('configurer --sans-wifi --sans-tache');
[$sT3, $cT3] = $lanceT('confiance --oui');
$cfgT3 = json_decode((string)@file_get_contents("$pcT/config.json"), true) ?: [];
$epingle = (string)($cfgT3['empreinte_certificat'] ?? '');
[$sT4, $cT4] = $lanceT('configurer --sans-wifi --sans-tache');
$cfgT3['empreinte_certificat'] = str_repeat('ab', 32);
file_put_contents("$pcT/config.json", json_encode($cfgT3));
[$sT5, $cT5] = $lanceT('configurer --sans-wifi --sans-tache');
verif($cT2 === 1 && str_contains($sT2, 'ne reconnaît pas son certificat') && !str_contains($sT2, 'injoignable')
   && $cT3 === 0 && $epingle === $fpAttendu && $cT4 === 0
   && $cT5 === 1 && str_contains($sT5, 'AUTRE certificat'),
      'certificat non reconnu : message clair (pas « injoignable ») ; « confiance » l\'épingle ; un autre certificat est refusé',
      getenv('BAVARD') ? str_replace("\n", ' ¶ ', "$sT2 // $sT5") : '');
// Nouveau téléchargement : nouveau jeton, l'ancien ne fonctionne plus ; PC supprimé : plus rien.
[, , $zCfg2] = $telecharger();
[$cn1] = $releveurApi('GET', 'mission', (string)$zCfg['jeton']);
[$cn2] = $releveurApi('GET', 'mission', (string)($zCfg2['jeton'] ?? ''));
[$csup] = $http('g', 'connect_releveur_supprimer', ['id' => $rlId]);
[$cn3] = $releveurApi('GET', 'mission', (string)($zCfg2['jeton'] ?? ''));
verif($cn1 === 403 && $cn2 === 200 && $csup === 200 && $cn3 === 403, 'nouveau collecteur : l\'ancien jeton est refusé ; PC releveur supprimé : son jeton aussi');
// Jetons faux à répétition : au-delà de 20 refus, même un jeton juste attend (la limite passe avant l'essai).
[$crl3, $jrl3] = $http('g', 'connect_releveur_enregistrer', ['nom' => 'PC témoin', 'connexions' => []]);
$rlId = (int)($jrl3['data']['id'] ?? 0);
[, , $zCfg3] = $telecharger();
[$cav] = $releveurApi('GET', 'mission', (string)($zCfg3['jeton'] ?? ''));
$refus = [];
for ($i = 0; $i < 20; $i++) $refus[] = $releveurApi('GET', 'mission', 'lrk1.epreuve.' . $rlId . '.' . bin2hex(random_bytes(24)))[0];
[$capres] = $releveurApi('GET', 'mission', (string)($zCfg3['jeton'] ?? ''));
verif($cav === 200 && in_array(429, $refus, true) && $capres === 429, 'jetons faux en rafale : bloqués, et le bon jeton aussi tant que dure la limite',
      "avant $cav · " . implode(', ', array_map(fn($k, $v) => "{$k}×{$v}", array_keys(array_count_values($refus)), array_count_values($refus))) . " · après $capres");
$http('g', "connect_passerelles&id=$hsId", null, 'DELETE');
$pdoT = null;

// Détection : ce qui n'est pas un équipement technique est écarté ; un vrai protocole jamais.
$q = fn(string $proto, string $conf, string $sig, array $ports) => (function () use ($proto, $conf, $sig, $ports) {
    $x = ['protocole' => $proto, 'confiance' => $conf, 'hote' => '10.0.0.5', 'details' => '', '_signature' => $sig];
    ConnectDetection::qualifier($x, $ports);
    return $x;
})();
$qa = $q('rest', 'possible', 'HP HTTP Server HP LaserJet M404', ['imprimante' => true]);
$qb = $q('modbus', 'sure', '', ['windows' => true]);
$qc = $q('rest', 'probable', 'Microsoft-IIS/10.0 Supervision GTB', ['windows' => true]);
$qd = $q('rest', 'possible', 'nginx Accueil', []);
verif(!empty($qa['ecarte']) && empty($qb['ecarte']) && str_contains((string)$qb['details'], 'PC relais') && empty($qc['ecarte']) && !empty($qd['ecarte']),
      'détection : imprimante et simple page web écartées ; Modbus sur un PC Windows gardé (« PC relais ? »), superviseur gardé',
      ($qa['ecarte'] ?? '-') . ' · ' . ($qd['ecarte'] ?? '-'));
[$cex1] = $http('g', 'connect_exclusions', ['op' => 'plages', 'plages' => '127.0.0.1']);
[$cdx, $jdx] = $http('g', 'connect_detecter', ['plage' => '127.0.0.1', 'protocoles' => ['modbus'], 'ports' => ['modbus' => [$ports['modbus']]]]);
[$cex2] = $http('g', 'connect_exclusions', ['op' => 'plages', 'plages' => '']);
[$cex3, $jex3] = $http('g', 'connect_exclusions', ['op' => 'ignorer', 'appareils' => [['protocole' => 'modbus', 'hote' => '127.0.0.1', 'port' => $ports['modbus'], 'nom' => 'Compteur']]]);
[, $jdx2] = $http('g', 'connect_detecter', ['plage' => '127.0.0.1', 'protocoles' => ['modbus'], 'ports' => ['modbus' => [$ports['modbus']]]]);
$ign = $jdx2['data']['equipements'][0] ?? [];
[$cex4] = $http('g', 'connect_exclusions', ['op' => 'retirer', 'id' => (int)($jex3['data']['ids'][0] ?? 0)]);
[, $jdx3] = $http('g', 'connect_detecter', ['plage' => '127.0.0.1', 'protocoles' => ['modbus'], 'ports' => ['modbus' => [$ports['modbus']]]]);
verif($cex1 === 200 && ($cdx === 422 || ($jdx['data']['equipements'] ?? ['x']) === []) && $cex2 === 200 && $cex3 === 200 && !empty($ign['ignore'])
   && $cex4 === 200 && empty(($jdx3['data']['equipements'][0] ?? [])['ignore']),
      'exclusions : adresses jamais parcourues ; appareil ignoré marqué, puis rétabli', "plage exclue → HTTP $cdx");

titre('Simulateur intégré et démonstration (historique instantané)');
if (!interface_exists('ConnectPilote')) require_once "$racine/api/connect/Pilote.php";
if (!class_exists('ConnectPiloteSimulation')) require_once "$racine/api/connect/pilotes/Simulation.php";
$mIdx = ConnectPiloteSimulation::modeleDe(['passerelle' => 7, 'occupation' => 'bureaux'], ['profil' => 'index_electrique', 'conso_jour' => 600], 3);
$lundi = strtotime('2026-01-12 00:00:00');
$sem = ConnectPiloteSimulation::integrale($mIdx, $lundi, $lundi + 7 * 86400);
$jourOuvre = ConnectPiloteSimulation::integrale($mIdx, $lundi + 86400, $lundi + 2 * 86400);
$dimanche = ConnectPiloteSimulation::integrale($mIdx, $lundi + 6 * 86400, $lundi + 7 * 86400);
verif(abs($sem / 7 / 600 - 1.15) < 0.12, 'index électrique : 600 kWh/j en moyenne (janvier, +15 % d\'hiver)', number_format($sem / 7, 0, ',', ' ') . ' kWh/j');
verif($jourOuvre > 1.8 * $dimanche, 'jour ouvré nettement plus consommateur que le dimanche', round($jourOuvre) . ' / ' . round($dimanche));
$mExt = ConnectPiloteSimulation::modeleDe(['passerelle' => 7], ['profil' => 'temperature_exterieure'], 4);
$mExt2 = ConnectPiloteSimulation::modeleDe(['passerelle' => 7], ['profil' => 'temperature_exterieure'], 5);
verif(ConnectPiloteSimulation::valeur($mExt, $lundi + 3600) === ConnectPiloteSimulation::valeur($mExt2, $lundi + 3600)
   && ConnectPiloteSimulation::valeur($mExt, strtotime('2026-07-15 15:00')) > ConnectPiloteSimulation::valeur($mExt, strtotime('2026-01-15 06:00')),
      'météo reproductible, commune au site ; l\'été plus chaud que l\'hiver');
$mCh = ConnectPiloteSimulation::modeleDe(['passerelle' => 7], ['profil' => 'index_chaleur', 'conso_jour' => 0.1], 6);
verif(ConnectPiloteSimulation::integrale($mCh, strtotime('2026-01-01'), strtotime('2026-02-01')) > 20 * ConnectPiloteSimulation::integrale($mCh, strtotime('2026-07-01'), strtotime('2026-08-01')),
      'chaleur : suit les degrés-jours (janvier ≫ juillet)');

$demo = function (string $opts) use ($php, $t): array {
    exec('cd ' . escapeshellarg($t) . " && $php api/outils/connect-demo.php $opts 2>&1", $o, $c);
    return [implode("\n", $o), $c];
};
// Un compteur « réel » de l'Hôtel de Ville, relevé à la main (le dernier il y a 5 jours) : la démonstration
// le raccorde, met de côté son relevé récent, et --retirer le remet.
$pdoT = new PDO("sqlite:$t/data/gmao.db");
$pdoT->exec("INSERT INTO CompteurEnergie (Nom, Type, Unite, Site, Actif) VALUES ('Électricité — Hôtel de Ville', 'Electricite', 'kWh', 'Hôtel de Ville', 1)");
$cptReel = (int)$pdoT->lastInsertId();
$insRel = $pdoT->prepare("INSERT INTO ReleverEnergie (CompteurId, Date, Type, IndexDebut, IndexFin, Consommation, PeriodeDebut, PeriodeFin, SaisieParLogin)
                          VALUES (?, ?, 'Releve', ?, ?, ?, ?, ?, 'epreuve')");
foreach ([[95, 65, 50000, 74000], [65, 35, 74000, 98000], [35, 5, 98000, 122000]] as [$de, $a, $i1, $i2]) {
    $insRel->execute([$cptReel, date('Y-m-d', time() - $a * 86400), $i1, $i2, $i2 - $i1, date('Y-m-d', time() - $de * 86400), date('Y-m-d', time() - $a * 86400)]);
}
$pdoT = null;
$tDemo = microtime(true);
// 60 jours (la durée par défaut) : les incidents simulés (classe confinée, panne de
// chauffage) sont tirés au sort par point ; sur 20 jours, il arrivait qu'aucun ne tombe.
[$sortie, $code] = $demo('--jours=60');
$dureeDemo = microtime(true) - $tDemo;
$pdoT = new PDO("sqlite:$t/data/gmao.db");
$n = fn(string $sql) => (int)$pdoT->query($sql)->fetchColumn();
$simPts = "SELECT p.Id FROM ConnectPoints p JOIN ConnectPasserelles g ON g.Id = p.PasserelleId WHERE g.Protocole = 'simulation'";
verif($code === 0 && $n("SELECT COUNT(*) FROM ConnectPasserelles WHERE Protocole = 'simulation'") === 2 && $n("SELECT COUNT(*) FROM ($simPts) x") === 14,
      'connect-demo : deux bâtiments simulés, 14 points', sprintf('%.1f s', $dureeDemo) . ($code === 0 ? '' : ' — ' . mb_substr($sortie, 0, 300)));
$nbMes = $n("SELECT COUNT(*) FROM ConnectMesures WHERE PointId IN ($simPts)");
verif($nbMes > 14 * 60 * 90, '60 jours d\'historique au quart d\'heure, sans attendre', number_format($nbMes, 0, ',', ' ') . ' mesures');
$idxPt = $n("SELECT p.Id FROM ConnectPoints p JOIN ConnectPasserelles g ON g.Id = p.PasserelleId WHERE g.Protocole = 'simulation' AND p.Code = 'index_electrique' ORDER BY p.Id LIMIT 1");
$serieIdx = array_map('floatval', $pdoT->query("SELECT Valeur FROM ConnectMesures WHERE PointId = $idxPt ORDER BY Horodatage")->fetchAll(PDO::FETCH_COLUMN));
$monotone = true;
for ($i = 1; $i < count($serieIdx); $i++) if ($serieIdx[$i] < $serieIdx[$i - 1]) { $monotone = false; break; }
verif($monotone && count($serieIdx) > 1900, 'index historisé croissant d\'un bout à l\'autre');
$nbRel = $n("SELECT COUNT(*) FROM ConnectExecutions WHERE Statut IN ('rattrape', 'automatique') AND ReleveId IS NOT NULL
              AND LiaisonId IN (SELECT Id FROM ConnectLiaisons WHERE PointId IN ($simPts))");
$nbRelEnergie = $n("SELECT COUNT(*) FROM ReleverEnergie WHERE Id IN (SELECT ReleveId FROM ConnectExecutions
              WHERE LiaisonId IN (SELECT Id FROM ConnectLiaisons WHERE PointId IN ($simPts)))");
verif($nbRel >= 18 && $nbRel === $nbRelEnergie && $n("SELECT COUNT(*) FROM CompteurEnergie WHERE NumeroCompteur = 'SIMULATION'") === 3,
      'relevés automatiques rattrapés dans le module Énergie (compteurs de télérelève créés)', "$nbRel relevés");
$relReel = $pdoT->query("SELECT Date, Type, IndexDebut, IndexFin, Consommation FROM ReleverEnergie WHERE CompteurId = $cptReel ORDER BY Date, Id")->fetchAll(PDO::FETCH_ASSOC);
$auto = array_values(array_filter($relReel, fn($r) => $r['IndexDebut'] !== null && (float)$r['IndexDebut'] >= 98000));
$consos = array_map(fn($r) => (float)$r['Consommation'], array_slice($auto, 1));
// 60 jours simulés : relevés quotidiens depuis le dernier relevé saisi conservé (il y a 35 jours).
verif(count($relReel) >= 2 + 32 && count($relReel) <= 2 + 35 && (float)$auto[0]['IndexDebut'] === 98000.0 && min($consos) > 200 && max($consos) < 1400,
      'compteur raccordé : relevé récent mis de côté, relevés quotidiens dans la continuité (≈ 800 kWh/j)',
      count($relReel) . ' lignes, ' . round(array_sum($consos) / max(1, count($consos))) . ' kWh/j');
verif($n("SELECT COUNT(*) FROM ConnectRegles WHERE PointId IN ($simPts)") === 5 && $n("SELECT COUNT(*) FROM ConnectAlarmes WHERE Etat = 'terminee' AND Acquittee = 1 AND PasserelleId IN (SELECT Id FROM ConnectPasserelles WHERE Protocole = 'simulation')") >= 1,
      'règles posées, alarmes passées reconstituées (terminées, acquittées)');
[$sortie2, $code2] = $demo('');
verif($code2 === 0 && str_contains($sortie2, 'déjà en place') && $n("SELECT COUNT(*) FROM ConnectPasserelles WHERE Protocole = 'simulation'") === 2,
      'relancé : rien en double');
$avant = $n("SELECT COUNT(*) FROM ConnectMesures WHERE PointId = $idxPt");
$pdoT->exec("UPDATE ConnectPasserelles SET ProchaineCollecte = NULL WHERE Protocole = 'simulation'");
exec('cd ' . escapeshellarg($t) . " && $php api/outils/connect-collecte.php 2>&1", $oc, $cc);
$dernier = $pdoT->query("SELECT DerniereValeur FROM ConnectPoints WHERE Id = $idxPt")->fetchColumn();
verif($cc === 0 && (float)$dernier >= end($serieIdx), 'la collecte habituelle prend le relais, index continu', number_format((float)$dernier, 1, ',', ' ') . ' kWh');
[$sortie3, $code3] = $demo('--retirer');
verif($code3 === 0 && $n("SELECT COUNT(*) FROM ConnectPasserelles WHERE Protocole = 'simulation'") === 0 && $n("SELECT COUNT(*) FROM ConnectMesures WHERE PointId = $idxPt") === 0
   && $n("SELECT COUNT(*) FROM CompteurEnergie WHERE NumeroCompteur = 'SIMULATION'") === 0 && $n("SELECT COUNT(*) FROM ConnectLiaisons") === $n("SELECT COUNT(*) FROM ConnectLiaisons WHERE PointId IN (SELECT Id FROM ConnectPoints)"),
      '--retirer : connexions, mesures, liaisons, relevés et compteurs de démonstration enlevés');
verif($n("SELECT COUNT(*) FROM ReleverEnergie WHERE CompteurId = $cptReel") === 3 && $n("SELECT MAX(IndexFin) FROM ReleverEnergie WHERE CompteurId = $cptReel") === 122000
   && str_contains($sortie3, 'remis en place'), '--retirer : le relevé saisi mis de côté est remis en place');
$pdoT = null;

titre('Module fermé');
$configurer(false);
[$c11] = $http('g', 'connect_supervision', null, 'GET');
[$c12, $j12] = $http('g', 'connect_badge', null, 'GET');
[$sortie, $code] = (function () use ($php, $t) { exec("cd " . escapeshellarg($t) . " && $php api/outils/connect-collecte.php 2>&1", $o, $c); return [implode("\n", $o), $c]; })();
verif($c11 === 409 && $c12 === 200 && ($j12['data']['actif'] ?? true) === false, 'connect.actif = false : écrans fermés (409), menu masqué');
verif($code === 0 && str_contains($sortie, 'désactivé'), 'collecte : rien à faire, sans erreur');
[, $je1] = $http('g', 'connect_etat', null, 'GET');
[, $je2] = $http('v', 'connect_etat', null, 'GET');
[$ca1] = $http('v', 'config_serveur', [['section' => 'connect', 'cle' => 'actif', 'valeur' => true]]);
[$ca2] = $http('g', 'config_serveur', [['section' => 'connect', 'cle' => 'actif', 'valeur' => true]]);
[, $j13] = $http('g', 'connect_badge', null, 'GET');
verif(($je1['data']['peut_activer'] ?? null) === true && ($je2['data']['peut_activer'] ?? null) === false && $ca1 === 403
   && $ca2 === 200 && ($j13['data']['actif'] ?? false) === true, 'module fermé : un gestionnaire peut l\'activer depuis l\'écran (le Visionneur non)');
// Radio : matériel USB du serveur (gestionnaire seulement), état LoRaWAN, passerelles Internet réservées.
[$cmat, $jmat] = $http('g', 'connect_materiel', null, 'GET');
[$cmatV] = $http('v', 'connect_materiel', null, 'GET');
[, $je3] = $http('g', 'connect_etat', null, 'GET');
[$clw, $jlw] = $http('g', 'config_serveur', [['section' => 'connect', 'sous_section' => 'lorawan', 'cle' => 'passerelles', 'valeur' => ['0011223344556677']],
                                             ['section' => 'connect', 'cle' => 'lorawan', 'valeur' => ['actif' => true, 'passerelles' => ['0011223344556677']]]]);
$cfgLw = json_decode((string)@file_get_contents("$t/config.json"), true)['connect']['lorawan'] ?? [];
verif($cmat === 200 && is_array($jmat['data']['ports'] ?? null) && $cmatV === 403 && array_key_exists('lorawan', $je3['data'] ?? [])
   && empty($cfgLw['passerelles']), 'radio : matériel USB du serveur (gestionnaire, pas le Visionneur), état LoRaWAN ; passerelles Internet réservées au Super Admin',
      json_encode($cfgLw));
$configurer(false);

// ═════════════════════════════════════════════════════════════════════════════
// 5. DÉCLENCHEURS
// ═════════════════════════════════════════════════════════════════════════════
titre('Déclencheurs : c\'est le serveur qui collecte, jamais le navigateur');
$lit = fn(string $f) => (string)@file_get_contents("$racine/$f");
$startSh = $lit('start.sh');
verif(str_contains($startSh, 'connect-collecte.php --silencieux') && str_contains($startSh, 'connect-ocpp.php --si-absent')
   && str_contains($startSh, 'data/run/connect-ocpp.pid'), 'start.sh : collecte à chaque tour du planificateur, service OCPP lancé et arrêté avec lui');
verif(str_contains($startSh, 'larka-connect.timer') && str_contains($startSh, 'OnUnitActiveSec=1min') && str_contains($startSh, 'larka-ocpp.service')
   && str_contains($startSh, 'PartOf=${AUTOSTART_UNIT}'), 'démarrage automatique : minuterie chaque minute, service OCPP lié à Larka');
$install = $lit('deploy/install.sh');
verif((bool)preg_match('#^\* \* \* \* \* www-data .*connect-collecte\.php --silencieux#m', $install) && str_contains($install, 'larka-ocpp.service')
   && str_contains($install, 'Restart=always'), 'production : tâche cron chaque minute, service OCPP relancé s\'il tombe');
verif(str_contains($install, 'location ^~ /ocpp/') && str_contains($lit('deploy/nginx.conf'), 'location ^~ /ocpp/')
   && str_contains($install, 'proxy_set_header Upgrade $http_upgrade'), 'Nginx : relais wss:// vers le service OCPP');
$win = $lit('deploy/windows/larka.php');
verif(str_contains($win, 'connect-collecte.php') && str_contains($win, 'connect-ocpp.php'), 'Windows : le lanceur collecte et fait tourner le service OCPP');
verif(str_contains($lit('api/index.php'), 'ConnectCollecte::executer()') && str_contains($lit('api/index.php'), 'fastcgi_finish_request'),
      'PHP-FPM : relais après la réponse si aucun passage depuis 2 minutes');
$ex = json_decode($lit('config.example.json'), true);
verif(isset($ex['connect']['actif'], $ex['connect']['ocpp']['port']) && $ex['connect']['actif'] === false, 'config.example.json : section connect documentée, fermée par défaut');
verif(str_contains($lit('api/routes/gestion.php'), "'connect.csv_racines'"), 'dossiers CSV permis : modifiables par le Super Administrateur seulement');

echo "\n───────────────────────────────────────────────────────────────────────\n";
echo $ko === 0 ? " ✅ $ok/" . ($ok + $ko) . " — les équipements du bâtiment parlent à Larka, et Larka ne leur parle qu'à bon escient.\n"
               : " ❌ $ko échec(s) sur " . ($ok + $ko) . "\n";
echo "───────────────────────────────────────────────────────────────────────\n";
exit($ko === 0 ? 0 : 1);

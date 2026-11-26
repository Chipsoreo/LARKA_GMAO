<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * Larka — épreuve du module ÉNERGIE : saisies, index précédents, vérification
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 *   - le serveur contrôle une saisie (date, compteur, nombres, index qui baisse)
 *     et garde un index à 0 ;
 *   - l'import depuis la supervision prend pour index précédent le dernier relevé
 *     du compteur Énergie AVANT la date du relevé (il prenait celui d'avant le 1er
 *     du mois), un relevé plutôt qu'une facture ; un relevé venu d'un serveur de
 *     supervision séparé reprend son précédent dans la base du principal ;
 *   - la vérification des relevés trouve index en baisse, consommations fausses,
 *     doublons, index qui reculent, ruptures, périodes inversées, dates à venir —
 *     et ne dit rien d'une série propre.
 *
 * Autonome : copie jetable, base SQLite, serveur intégré.
 *   php outils/epreuves/test-energie.php
 * ═══════════════════════════════════════════════════════════════════════════════
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit("Ligne de commande uniquement.\n"); }
foreach (['curl', 'pdo_sqlite'] as $x) {
    if (!extension_loaded($x)) { echo "  ⏭️  Épreuve sautée : extension « $x » absente.\n"; exit(0); }
}

$source = dirname(__DIR__, 2);
$ok = 0; $ko = [];
function verifier(string $titre, bool $vrai, string $detail, int &$ok, array &$ko): void {
    if ($vrai) { $ok++; printf("  ✅  %-62s %s\n", $titre, mb_substr($detail, 0, 30)); }
    else       { $ko[] = $titre; printf("  ❌  %-62s %s\n", $titre, mb_substr($detail, 0, 60)); }
}

// ── Copie jetable ─────────────────────────────────────────────────────────────
$dir = sys_get_temp_dir() . '/larka-energie-' . bin2hex(random_bytes(4));
$exclus = ['.git', 'outils', 'Documentations', 'config.json', 'config.key', '.env', 'tenants.json', '.user.ini'];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
foreach ($it as $f) {
    $rel = substr($f->getPathname(), strlen($source) + 1);
    $tete = explode('/', $rel)[0];
    if (in_array($tete, $exclus, true)) continue;
    if ($tete === 'data' && $f->isFile() && !in_array($f->getFilename(), ['.gitkeep', '.htaccess'], true)) continue;
    $cible = $dir . '/' . $rel;
    if ($f->isDir()) { @mkdir($cible, 0777, true); } else { @mkdir(dirname($cible), 0777, true); copy($f->getPathname(), $cible); }
}
$nettoyer = function () use ($dir) {
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    @rmdir($dir);
};
$s = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
$port = (int)substr(strrchr(stream_socket_get_name($s, false), ':'), 1);
fclose($s);
file_put_contents($dir . '/config.json', json_encode([
    'serveur'         => ['env' => 'prod'],
    'base_de_donnees' => ['driver' => 'sqlite', 'path' => 'data/gmao.db'],
    'superadmin_db'   => ['driver' => 'sqlite', 'path' => 'data/superadmin.db'],
    'securite'        => ['allowed_origin' => "http://127.0.0.1:$port", 'secret_key' => bin2hex(random_bytes(16))],
    'session'         => ['cookie_samesite' => 'Lax'],
], JSON_PRETTY_PRINT));

$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", 'router.php'],
                  [0 => ['pipe', 'r'], 1 => ['file', $dir . '/serveur.log', 'a'], 2 => ['file', $dir . '/serveur.log', 'a']], $tubes, $dir);
if (!is_resource($proc)) { $nettoyer(); echo "  ❌  Impossible de lancer le serveur intégré.\n"; exit(1); }
for ($i = 0; $i < 50; $i++) { if (@fsockopen('127.0.0.1', $port)) break; usleep(100000); }

$base = "http://127.0.0.1:$port";
$jarres = [];
$http = function (string $qui, string $methode, string $chemin, $corps = null, array $entetes = []) use ($base, $dir, &$jarres): array {
    $jarres[$qui] ??= $dir . "/cookies-$qui.txt";
    $ch = curl_init($base . $chemin);
    $h = $entetes;
    if (is_array($corps)) { $corps = json_encode($corps); $h[] = 'Content-Type: application/json'; }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $methode, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_COOKIEJAR => $jarres[$qui], CURLOPT_COOKIEFILE => $jarres[$qui], CURLOPT_HTTPHEADER => $h]);
    if ($corps !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $corps);
    $rep = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    return [$code, json_decode($rep, true) ?? $rep];
};
$api = fn(string $qui, string $m, string $action, $corps = null) => $http($qui, $m, "/api/index.php?action=$action", $corps);
/** Appel de synchronisation avec le jeton d'un appareil. */
$sync = function (string $jeton, string $m, string $action, $corps = null) use ($http): array {
    return $http('appareil', $m, "/api/index.php?action=$action", $corps, ['Authorization: Bearer ' . $jeton]);
};
$lier = function (string $login, string $mdp) use ($api): string {
    [$c, $r] = $api('lier-' . $login, 'POST', 'synchro_lier', ['login' => $login, 'password' => $mdp, 'nom' => "épreuve $login", 'version' => 'e']);
    if ($c !== 200 || empty($r['data']['jeton'])) throw new RuntimeException("appareil de $login non relié (HTTP $c)");
    return (string)$r['data']['jeton'];
};
$lignes = function (string $jeton, string $table) use ($sync): array {
    [$c, $r] = $sync($jeton, 'GET', 'synchro_cliche&limite=5000&table=' . rawurlencode($table));
    return $c === 200 ? (array)($r['data']['lignes'] ?? []) : [];
};
$pousser = function (string $jeton, array $modif) use ($sync): array {
    [, $r] = $sync($jeton, 'POST', 'synchro_pousser', ['modifs' => [$modif]]);
    return (array)($r['data'] ?? []);
};
$applique = fn(array $r) => count($r['appliquees'] ?? []) === 1;

echo "\n═══════════════════════════════════════════════════════════════════════\n";
echo " Larka — les relevés d'énergie s'enchaînent-ils juste ?\n";
echo "═══════════════════════════════════════════════════════════════════════\n";

try {
    $api('adm', 'GET', 'auth_config');
    $api('adm', 'POST', 'login', ['login' => 'admin', 'password' => 'admin']);
    $initial = preg_match('/Mot de passe : (\S+)/', (string)@file_get_contents($dir . '/data/security/admin-initial.txt'), $mm) ? $mm[1] : '';
    $api('adm', 'POST', 'login', ['login' => 'admin', 'password' => $initial]);
    $api('adm', 'POST', 'change_password', ['oldPassword' => $initial, 'newPassword' => 'Epreuve-Admin-1']);
    $api('adm', 'POST', 'logout');
    [$c] = $api('adm', 'POST', 'login', ['login' => 'admin', 'password' => 'Epreuve-Admin-1']);
    if ($c !== 200) throw new RuntimeException("connexion administrateur impossible (HTTP $c)");
    [, $r] = $api('adm', 'POST', 'energie_compteurs', ['nom' => 'Mairie élec', 'type' => 'Electricite', 'unite' => 'kWh', 'actif' => 1]);
    $cpt = (int)($r['data']['id'] ?? 0);
    if ($cpt <= 0) throw new RuntimeException('compteur non créé');
    $releve = fn(array $d) => $api('adm', 'POST', 'energie_releves', $d + ['compteurId' => $cpt, 'type' => 'Releve']);

    echo "\n  Saisie contrôlée par le serveur\n";
    [$c, $r] = $releve(['date' => '2026-13-40', 'indexDebut' => 0, 'indexFin' => 10, 'consommation' => 10]);
    verifier('date impossible → refusée', $c === 400, "HTTP $c", $ok, $ko);
    [$c, $r] = $releve(['date' => '2026-01-31', 'indexDebut' => 500, 'indexFin' => 400, 'consommation' => -100]);
    verifier('index sous l\'index précédent → refusé', $c === 400 && str_contains((string)($r['error'] ?? ''), 'inférieur'), "HTTP $c", $ok, $ko);
    [$c] = $api('adm', 'POST', 'energie_releves', ['compteurId' => 999999, 'date' => '2026-01-31', 'indexFin' => 1, 'consommation' => 1]);
    verifier('compteur inexistant → refusé', $c === 400, "HTTP $c", $ok, $ko);
    [$c] = $releve(['date' => '2026-01-31', 'indexDebut' => 'douze', 'indexFin' => 400, 'consommation' => 1]);
    verifier('nombre illisible → refusé', $c === 400, "HTTP $c", $ok, $ko);
    [$c, $r] = $releve(['date' => '2026-01-31', 'indexDebut' => 0, 'indexFin' => '1 000,5', 'consommation' => '']);
    $id1 = (int)($r['data']['id'] ?? 0);
    $pdo = new PDO('sqlite:' . $dir . '/data/gmao.db');
    $l1 = $pdo->query("SELECT IndexDebut, IndexFin, Consommation FROM ReleverEnergie WHERE Id = $id1")->fetch(PDO::FETCH_ASSOC) ?: [];
    verifier('index à 0 gardé (compteur neuf)', $c === 200 && $l1 && $l1['IndexDebut'] !== null && (float)$l1['IndexDebut'] === 0.0, json_encode($l1), $ok, $ko);
    verifier('« 1 000,5 » lu 1000,5 ; consommation déduite des index', $l1 && (float)$l1['IndexFin'] === 1000.5 && (float)$l1['Consommation'] === 1000.5, '', $ok, $ko);

    // Série : janvier → mars, puis un relevé saisi en cours de mars.
    $releve(['date' => '2026-02-28', 'indexDebut' => 1000.5, 'indexFin' => 1800, 'consommation' => 799.5]);
    $releve(['date' => '2026-03-15', 'indexDebut' => 1800, 'indexFin' => 2200, 'consommation' => 400]);
    $releve(['date' => '2026-03-15', 'type' => 'Facture', 'indexDebut' => 1000.5, 'indexFin' => 2190, 'consommation' => 1189.5, 'numeroFacture' => 'F-1']);
    [, $r] = $api('adm', 'GET', "energie_releves&compteur_id=$cpt");
    $ordre = array_column((array)($r['data'] ?? []), 'Id');
    verifier('même jour : la dernière saisie d\'abord (ordre stable)', count($ordre) >= 2 && $ordre[0] > $ordre[1], json_encode(array_slice($ordre, 0, 2)), $ok, $ko);

    echo "\n  Index précédent de l'import depuis la supervision\n";
    // Les classes de Larka Connect, chargées dans l'installation jetable.
    file_put_contents("$dir/epreuve-energie.php", <<<'PHPX'
<?php
define('LARKA_SANS_SESSION', true);
chdir(__DIR__);
require __DIR__ . '/api/config.php';
require_once __DIR__ . '/api/SecurityLog.php';
require_once __DIR__ . '/api/Journal.php';
require_once __DIR__ . '/api/Database.php';
require_once __DIR__ . '/api/connect/Connect.php';
Connect::charger();
$db = new Database();
$cpt = (int)$argv[1];
$out = ['contexte' => ConnectConsommations::contexteImport($db, [$cpt], '2026-03-01', '2026-03-31')[$cpt] ?? null,
        'contexte_15' => ConnectConsommations::contexteImport($db, [$cpt], '2026-03-01', '2026-03-15')[$cpt] ?? null];
// Relevé venu d'un serveur de supervision séparé, rattrapé pour le 28 février,
// alors que des relevés plus récents existent : son précédent est celui d'AVANT lui.
$lia = new ConnectLiaisons(new ConnectDepot($db), new ConnectAlarmes(new ConnectDepot($db)));
$c = $db->fetchOne('SELECT * FROM CompteurEnergie WHERE Id = :id', ['id' => $cpt]);
$id = $lia->ecrireReleveExterne($c, ['date' => '2026-02-20', 'index' => 1500, 'prec' => 9999, 'datePrec' => '2025-12-31',
                                      'statut' => 'rattrape', 'pointNom' => 'Compteur général']);
$out['externe'] = $db->fetchOne('SELECT IndexDebut, PeriodeDebut, Consommation FROM ReleverEnergie WHERE Id = :id', ['id' => $id]);
echo json_encode($out);
PHPX);
    $sortie = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$dir/epreuve-energie.php") . ' ' . $cpt . ' 2>&1');
    $o = json_decode($sortie, true);
    if (!is_array($o)) throw new RuntimeException('script Connect : ' . mb_substr($sortie, 0, 200));
    verifier('relevé du 31/03 : précédent = relevé du 15/03 (pas celui du 1er)', (float)($o['contexte']['index'] ?? -1) === 2200.0 && ($o['contexte']['date'] ?? '') === '2026-03-15',
             json_encode($o['contexte']), $ok, $ko);
    verifier('… un relevé est préféré à une facture du même jour', (float)($o['contexte']['index'] ?? -1) === 2200.0, '', $ok, $ko);
    verifier('relevé du 15/03 : précédent = 28/02, et celui du 15 signalé', (float)($o['contexte_15']['index'] ?? -1) === 1800.0
             && ($o['contexte_15']['suivant_date'] ?? '') === '2026-03-15', json_encode($o['contexte_15']), $ok, $ko);
    verifier('relevé rattrapé (20/02) : précédent pris ici, avant lui (31/01)', (float)($o['externe']['IndexDebut'] ?? -1) === 1000.5
             && (float)($o['externe']['Consommation'] ?? -1) === 499.5, json_encode($o['externe']), $ok, $ko);

    echo "\n  Vérification des relevés\n";
    [$c, $r] = $api('adm', 'GET', 'energie_verifier&type=Electricite');
    $pbs = $r['data']['compteurs'][0]['problemes'] ?? [];
    $codes = array_count_values(array_column($pbs, 'code'));
    verifier('série saine sauf le relevé intercalé : signalé', $c === 200, "HTTP $c", $ok, $ko);
    // Le relevé du 28/02 commence à 1000,5, alors que celui du 20/02 finit à 1500 : rupture.
    verifier('index précédent qui ne prolonge pas le relevé d\'avant → « à vérifier »', ($codes['rupture'] ?? 0) >= 1, json_encode($codes), $ok, $ko);
    // Fautes écrites directement en base (anciennes saisies, import) : le contrôle les trouve.
    $pdo->exec("INSERT INTO ReleverEnergie (CompteurId, Date, Type, IndexDebut, IndexFin, Consommation) VALUES ($cpt, '2026-04-30', 'Releve', 2200, 2100, -100)");
    $pdo->exec("INSERT INTO ReleverEnergie (CompteurId, Date, Type, IndexDebut, IndexFin, Consommation) VALUES ($cpt, '2026-05-31', 'Releve', 2100, 2600, 50)");
    $pdo->exec("INSERT INTO ReleverEnergie (CompteurId, Date, Type, IndexDebut, IndexFin, Consommation) VALUES ($cpt, '2026-05-31', 'Releve', 2100, 2600, 500)");
    $pdo->exec("INSERT INTO ReleverEnergie (CompteurId, Date, Type, IndexDebut, IndexFin, Consommation, PeriodeDebut, PeriodeFin) VALUES ($cpt, '2026-06-30', 'Releve', 2600, 2700, 100, '2026-06-30', '2026-06-01')");
    $pdo->exec("INSERT INTO ReleverEnergie (CompteurId, Date, Type, IndexDebut, IndexFin, Consommation) VALUES ($cpt, '2099-01-31', 'Releve', 2700, 2800, 100)");
    [, $r] = $api('adm', 'GET', 'energie_verifier&type=Electricite');
    $codes = array_count_values(array_column($r['data']['compteurs'][0]['problemes'] ?? [], 'code'));
    foreach (['baisse' => 'index en baisse', 'conso_negative' => 'consommation négative', 'conso' => 'consommation ≠ index − index précédent',
              'doublon' => 'doublon (même jour, même index)', 'recul' => 'index qui recule', 'periode' => 'période inversée', 'futur' => 'date à venir'] as $k => $lib) {
        verifier("détecté : $lib", ($codes[$k] ?? 0) >= 1, json_encode($codes), $ok, $ko);
    }
    verifier('erreurs comptées', (int)($r['data']['erreurs'] ?? 0) >= 6, (string)($r['data']['erreurs'] ?? '?'), $ok, $ko);
    // Un autre compteur, propre : rien à signaler.
    [, $r2] = $api('adm', 'POST', 'energie_compteurs', ['nom' => 'École eau', 'type' => 'Eau', 'unite' => 'm³', 'actif' => 1]);
    $cpt2 = (int)($r2['data']['id'] ?? 0);
    foreach ([['2026-01-31', 0, 10], ['2026-02-28', 10, 25], ['2026-03-31', 25, 31]] as [$d, $a, $b]) {
        $api('adm', 'POST', 'energie_releves', ['compteurId' => $cpt2, 'type' => 'Releve', 'date' => $d, 'indexDebut' => $a, 'indexFin' => $b, 'consommation' => $b - $a]);
    }
    [, $r] = $api('adm', 'GET', 'energie_verifier&type=Eau');
    verifier('série propre : rien à signaler', ($r['data']['compteurs'] ?? null) === [] && (int)($r['data']['releves'] ?? 0) === 3, json_encode($r['data'] ?? null), $ok, $ko);
    $pdo = null;
} catch (\Throwable $e) {
    $ko[] = 'déroulé : ' . $e->getMessage();
    echo "  ❌  " . $e->getMessage() . "\n";
}

proc_terminate($proc); proc_close($proc);
$nettoyer();
$total = $ok + count($ko);
echo "\n───────────────────────────────────────────────────────────────────────\n";
if ($ko === []) printf(" ✅ %d/%d — les relevés s'enchaînent, et leurs erreurs se voient.\n", $ok, $total);
else { printf(" ❌ %d échec(s) sur %d :\n", count($ko), $total); foreach ($ko as $t) echo "      • $t\n"; }
echo "───────────────────────────────────────────────────────────────────────\n\n";
exit($ko === [] ? 0 : 1);

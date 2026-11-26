<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka — épreuve du SERVEUR DE SUPERVISION SÉPARÉ et de l'assistant sur la supervision.
 *
 * Deux vraies installations (copies jetables, deux serveurs PHP, deux bases) :
 *   P = le serveur principal (la GMAO), Larka Connect FERMÉ (connect.actif = false) ;
 *   S = le serveur de supervision, avec deux connexions simulées et leur historique.
 *
 *   1. appairage en PORT OUVERT (code créé par S, collé sur P) : même empreinte des deux côtés ;
 *   2. les écrans de P montrent les données de S ; un Demandeur reste refusé ; une écriture
 *      (acquittement) part au nom de la personne ;
 *   3. sûreté du lien : message altéré, message rejoué, clé inconnue → refusés ;
 *   4. synchronisation : une alarme de S crée sa demande d'intervention dans la base de P,
 *      et l'alarme en reçoit le numéro ; le référentiel de P (bâtiments) est recopié sur S ;
 *   5. appairage en PONT SORTANT (code créé par P, collé sur S), relais lancé : P joint S
 *      sans jamais l'appeler ; relais arrêté → P le dit tout de suite ;
 *   6. l'assistant de P répond sur la supervision de S (consommation, alarmes) ;
 *   7. compréhension des questions de supervision et réponses sur une supervision simulée : collecte
 *      arrêtée, point désigné par son lieu, point d'état, bâtiment dit « l'école » ; le Demandeur reçoit
 *      une explication pour une question, une demande pour un signalement (sans serveur) ;
 *   8. confirmation d'identité Microsoft / Google : compte, fraîcheur, organisation (sans fournisseur).
 *
 *   php outils/epreuves/test-supervision.php
 */

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit("Ligne de commande uniquement.\n"); }
foreach (['curl', 'pdo_sqlite', 'sodium'] as $x) {
    if (!extension_loaded($x)) { echo "  ⏭️  Épreuve sautée : extension « $x » absente.\n"; exit(0); }
}

$source = dirname(__DIR__, 2);
$ok = 0; $ko = [];
function verifier(string $titre, bool $vrai, string $detail, int &$ok, array &$ko): void {
    if ($vrai) { $ok++; printf("  ✅  %-66s %s\n", $titre, mb_substr($detail, 0, 30)); }
    else       { $ko[] = $titre; printf("  ❌  %-66s %s\n", $titre, mb_substr($detail, 0, 160)); }
}
function port_libre(): int {
    $s = stream_socket_server('tcp://127.0.0.1:0', $e, $es);
    $p = (int)substr(strrchr(stream_socket_get_name($s, false), ':'), 1);
    fclose($s);
    return $p;
}

// ── Deux copies jetables ──────────────────────────────────────────────────────
$tmp = sys_get_temp_dir() . '/larka-sup-' . bin2hex(random_bytes(4));
$exclus = ['.git', 'outils', 'Documentations', 'config.json', 'config.key', '.env', 'tenants.json', '.user.ini', 'extensions', 'mobile'];
$copier = function (string $vers) use ($source, $exclus) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $f) {
        $rel = substr($f->getPathname(), strlen($source) + 1);
        $tete = explode('/', $rel)[0];
        if (in_array($tete, $exclus, true)) continue;
        if ($tete === 'data' && $f->isFile() && !in_array($f->getFilename(), ['.gitkeep', '.htaccess'], true)) continue;
        $c = $vers . '/' . $rel;
        if ($f->isDir()) @mkdir($c, 0777, true); else { @mkdir(dirname($c), 0777, true); copy($f->getPathname(), $c); }
    }
    @mkdir($vers . '/extensions', 0777, true);
};
$procs = [];
register_shutdown_function(function () use (&$procs, $tmp) {
    foreach ($procs as $p) if (is_resource($p)) { @proc_terminate($p); @proc_close($p); }
    if (!is_dir($tmp)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    @rmdir($tmp);
});

$srv = [];
foreach (['P' => false, 'S' => true] as $nom => $connect) {
    $dir = "$tmp/$nom"; $copier($dir);
    $port = port_libre();
    file_put_contents("$dir/config.json", json_encode([
        'serveur' => ['env' => 'prod', 'port' => $port],
        'base_de_donnees' => ['driver' => 'sqlite', 'path' => 'data/gmao.db'],
        'superadmin_db' => ['driver' => 'sqlite', 'path' => 'data/superadmin.db'],
        'securite' => ['allowed_origin' => "http://127.0.0.1:$port", 'secret_key' => bin2hex(random_bytes(16))],
        'session' => ['cookie_samesite' => 'Lax'],
        'connect' => ['actif' => $connect],
    ], JSON_PRETTY_PRINT));
    $env = array_merge(getenv(), ['PHP_CLI_SERVER_WORKERS' => '4']);
    $procs[] = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", 'router.php'],
        [0 => ['pipe', 'r'], 1 => ['file', "$dir/serveur.log", 'a'], 2 => ['file', "$dir/serveur.log", 'a']], $tubes, $dir, $env);
    for ($i = 0; $i < 60; $i++) { if (@fsockopen('127.0.0.1', $port)) break; usleep(100000); }
    $srv[$nom] = ['dir' => $dir, 'port' => $port, 'url' => "http://127.0.0.1:$port"];
}

$jarres = [];
$api = function (string $s, string $qui, string $methode, string $action, $corps = null, ?string $brut = null) use ($srv, &$jarres): array {
    $jarres["$s$qui"] ??= $srv[$s]['dir'] . "/cookies-$qui.txt";
    $ch = curl_init($srv[$s]['url'] . "/api/index.php?action=$action");
    $h = [];
    if (is_array($corps)) { $corps = json_encode($corps); $h[] = 'Content-Type: application/json'; }
    if ($brut !== null) { $corps = $brut; $h[] = 'Content-Type: application/json'; }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $methode, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40,
        CURLOPT_COOKIEJAR => $jarres["$s$qui"], CURLOPT_COOKIEFILE => $jarres["$s$qui"], CURLOPT_HTTPHEADER => $h,
        CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*']);
    if ($corps !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $corps);
    $rep = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $j = json_decode($rep, true);
    return [$code, $j['data'] ?? ($j ?? $rep), $j['error'] ?? ''];
};
/** Une commande PHP dans une installation : [code de sortie, sortie]. */
$cli = function (string $s, array $args) use ($srv): array {
    $p = proc_open(array_merge([PHP_BINARY], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $t, $srv[$s]['dir']);
    $out = stream_get_contents($t[1]) . stream_get_contents($t[2]);
    return [proc_close($p), $out];
};
$php = fn(string $s, string $code) => $cli($s, ['-r', "chdir('{$srv[$s]['dir']}'); require 'api/config.php'; require 'api/Database.php'; require 'api/connect/Connect.php'; Connect::charger(); \$db = new Database(); $code"]);
$sql = function (string $s, string $q) use ($srv) {
    $pdo = new PDO('sqlite:' . $srv[$s]['dir'] . '/data/gmao.db');
    return $pdo->query($q)->fetchAll(PDO::FETCH_ASSOC);
};

echo "\n═══════════════════════════════════════════════════════════════════════\n";
echo " Larka — serveur de supervision séparé, lien chiffré, assistant\n";
echo "═══════════════════════════════════════════════════════════════════════\n";

try {
    // Comptes : administrateur sur P et S, un demandeur sur P.
    foreach (['P', 'S'] as $s) {
        $api($s, 'adm', 'POST', 'login', ['login' => 'admin', 'password' => 'admin']);
        $ini = preg_match('/Mot de passe : (\S+)/', (string)@file_get_contents($srv[$s]['dir'] . '/data/security/admin-initial.txt'), $m) ? $m[1] : '';
        $api($s, 'adm', 'POST', 'login', ['login' => 'admin', 'password' => $ini]);
        $api($s, 'adm', 'POST', 'change_password', ['oldPassword' => $ini, 'newPassword' => 'Epreuve-Admin-1']);
        $api($s, 'adm', 'POST', 'logout');
        [$c] = $api($s, 'adm', 'POST', 'login', ['login' => 'admin', 'password' => 'Epreuve-Admin-1']);
        if ($c !== 200) throw new RuntimeException("connexion administrateur impossible sur $s (HTTP $c)");
    }
    $api('P', 'adm', 'POST', 'utilisateurs', ['nom' => 'dem', 'prenom' => 'E', 'login' => 'dem', 'motDePasse' => 'Epreuve-dem-1', 'role' => 'Demandeur', 'provider' => 'local']);
    $api('P', 'dem', 'POST', 'login', ['login' => 'dem', 'password' => 'Epreuve-dem-1']);

    // S : deux connexions simulées et 30 jours d'historique.
    [$c, $o] = $cli('S', ['api/outils/connect-demo.php', '--jours=30', '--releves=0', '--meme-en-production']);
    $nbS = (int)($sql('S', 'SELECT COUNT(*) n FROM ConnectPasserelles')[0]['n'] ?? 0);
    verifier('S : connexions simulées en place', $nbS >= 2, "$nbS connexion(s) — " . trim(substr($o, -120)), $ok, $ko);

    // ── 1. Port ouvert ──────────────────────────────────────────────────────
    echo "\n  1. Appairage en port ouvert\n";
    [, $o] = $cli('S', ['api/outils/supervision.php', 'code', '--role=supervision', '--mode=direct', '--url=' . $srv['S']['url']]);
    $code = preg_match('/(LKS1\.\S+)/', $o, $m) ? $m[1] : '';
    verifier('S crée un code d\'appairage', $code !== '', mb_substr($o, 0, 80), $ok, $ko);
    [$cP, $o] = $cli('P', ['api/outils/supervision.php', 'appairer', $code]);
    verifier('P utilise le code : appairé', $cP === 0 && str_contains($o, 'Appairé'), $o, $ok, $ko);
    [$cP2, $o2] = $cli('P', ['api/outils/supervision.php', 'appairer', $code]);
    verifier('le même code ne sert pas deux fois', $cP2 !== 0, $o2, $ok, $ko);
    $emp = fn(string $s) => preg_match('/Empreinte : ([0-9A-F ]{29})/', $cli($s, ['api/outils/supervision.php', 'etat'])[1], $m) ? $m[1] : '';
    verifier('même empreinte affichée des deux côtés', $emp('P') !== '' && $emp('P') === $emp('S'), $emp('P') . ' / ' . $emp('S'), $ok, $ko);
    [$c, $o] = $cli('P', ['api/outils/supervision.php', 'tester']);
    verifier('P joint S (ping chiffré)', $c === 0 && str_contains($o, 'répond'), $o, $ok, $ko);

    // ── 2. Écrans relayés ───────────────────────────────────────────────────
    echo "\n  2. Écrans de P servis par S\n";
    [$c, $d] = $api('P', 'adm', 'GET', 'connect_supervision');
    $noms = array_column((array)($d['passerelles'] ?? []), 'Nom');
    verifier('Supervision de P : les connexions de S', $c === 200 && count($noms) === $nbS, json_encode($noms, JSON_UNESCAPED_UNICODE), $ok, $ko);
    [$c, $d] = $api('P', 'adm', 'GET', 'connect_etat');
    verifier('connect_etat de P : module ouvert (par S), lien signalé', $c === 200 && !empty($d['actif_serveur']) && ($d['supervision_distante']['mode'] ?? '') === 'direct', json_encode($d['supervision_distante'] ?? null), $ok, $ko);
    [$c, , $e] = $api('P', 'dem', 'GET', 'connect_supervision');
    verifier('un Demandeur reste refusé (contrôlé par P avant tout relais)', $c === 403, "HTTP $c $e", $ok, $ko);
    $aid = (int)($sql('S', "SELECT Id FROM ConnectAlarmes ORDER BY Id DESC LIMIT 1")[0]['Id'] ?? 0);
    $sql('S', "UPDATE ConnectAlarmes SET Acquittee = 0, AcquitteePar = NULL WHERE Id = $aid");
    [$c, $d] = $api('P', 'adm', 'POST', 'connect_alarme_acquitter', ['id' => $aid, 'commentaire' => 'depuis P']);
    $par = (string)($sql('S', "SELECT AcquitteePar FROM ConnectAlarmes WHERE Id = $aid")[0]['AcquitteePar'] ?? '');
    verifier('écriture relayée au nom de la personne (principal:admin)', $c === 200 && $par === 'principal:admin', "HTTP $c, $par", $ok, $ko);

    // ── 3. Sûreté du lien ───────────────────────────────────────────────────
    echo "\n  3. Sûreté du lien\n";
    [, $o] = $php('P', "\$e = SupervisionLien::sceller(\$db, 'requete', ['op' => 'ping', 'donnees' => []]); unset(\$e['id']); echo json_encode(\$e);");
    $env = json_decode(trim($o), true);
    [$c1] = $api('S', 'x', 'POST', 'supervision_lien', null, json_encode($env));
    [$c2, , $e2] = $api('S', 'x', 'POST', 'supervision_lien', null, json_encode($env));
    verifier('message valide accepté, puis le même REJOUÉ refusé', $c1 === 200 && $c2 === 403 && str_contains($e2, 'rejeu'), "$c1 / $c2 $e2", $ok, $ko);
    [, $o] = $php('P', "\$e = SupervisionLien::sceller(\$db, 'requete', ['op' => 'ping', 'donnees' => []]); unset(\$e['id']); echo json_encode(\$e);");
    $alt = json_decode(trim($o), true);
    $alt['c'][10] = $alt['c'][10] === 'A' ? 'B' : 'A';
    [$c, , $e] = $api('S', 'x', 'POST', 'supervision_lien', null, json_encode($alt));
    verifier('message altéré d\'un caractère : refusé', $c === 403 && str_contains($e, 'appairé'), "$c $e", $ok, $ko);
    $kp = sodium_crypto_box_keypair();
    $n = random_bytes(24);
    $faux = ['v' => '1', 'o' => 'x', 'n' => rtrim(strtr(base64_encode($n), '+/', '-_'), '='),
             'c' => rtrim(strtr(base64_encode(sodium_crypto_box((string)json_encode(['t' => time(), 'i' => bin2hex(random_bytes(16)), 'y' => 'requete', 'd' => ['op' => 'ping']]), $n,
                    sodium_crypto_box_keypair_from_secretkey_and_publickey(sodium_crypto_box_secretkey($kp), sodium_crypto_box_publickey($kp)))), '+/', '-_'), '=')];
    [$c, , $e] = $api('S', 'x', 'POST', 'supervision_lien', null, json_encode($faux));
    verifier('message scellé par une clé inconnue : refusé', $c === 403, "$c $e", $ok, $ko);
    [$c] = $api('S', 'x', 'POST', 'supervision_appairer', ['cible' => 'default', 'k' => str_repeat('A', 43), 'r' => 'principal', 'p' => 'x']);
    verifier('appairage sans code en cours : refusé', $c === 403, "HTTP $c", $ok, $ko);

    // ── 4. Synchronisation ──────────────────────────────────────────────────
    echo "\n  4. Synchronisation\n";
    [, $o] = $php('S', "\$a = new ConnectAlarmes(new ConnectDepot(\$db)); echo \$a->ouvrir(['origine' => 'equipement', 'passerelle' => 1, 'code' => 'EPREUVE', 'gravite' => 'critique', 'message' => 'Épreuve : pompe en défaut', 'demande' => true, 'notifier' => true]);");
    $alarme = (int)trim($o);
    $avant = (int)$sql('S', "SELECT COUNT(*) n FROM DemandesIntervention")[0]['n'];
    verifier('S ne crée pas la demande lui-même (elle part en sortant)', $alarme > 0 && $avant === 0, "alarme $alarme, demandes S = $avant", $ok, $ko);
    $sql('P', "INSERT INTO PlanBatiments (Nom) VALUES ('Bâtiment de l''épreuve')");
    [$c, $o] = $cli('P', ['api/outils/supervision.php', 'synchroniser']);
    $dem = $sql('P', "SELECT Id, Titre FROM DemandesIntervention WHERE Titre LIKE '%pompe en défaut%'");
    verifier('la demande est créée dans la base de P', count($dem) === 1, $o, $ok, $ko);
    $lien = (int)($sql('S', "SELECT DemandeId FROM ConnectAlarmes WHERE Id = $alarme")[0]['DemandeId'] ?? 0);
    verifier('l\'alarme de S reçoit le numéro de la demande de P', $dem && $lien === (int)$dem[0]['Id'], "$lien", $ok, $ko);
    $bat = $sql('S', "SELECT COUNT(*) n FROM PlanBatiments WHERE Nom = 'Bâtiment de l''épreuve'")[0]['n'];
    verifier('référentiel : le bâtiment créé sur P est recopié sur S', (int)$bat === 1, '', $ok, $ko);
    [, $o] = $cli('P', ['api/outils/supervision.php', 'synchroniser']);
    verifier('rien n\'est livré deux fois, référentiel inchangé non renvoyé', str_contains($o, '0 écriture') && str_contains($o, 'inchangé'), trim($o), $ok, $ko);

    // ── 5. Pont sortant ─────────────────────────────────────────────────────
    echo "\n  5. Pont sortant\n";
    [, $o] = $cli('P', ['api/outils/supervision.php', 'code', '--role=principal', '--mode=pont', '--url=' . $srv['P']['url']]);
    $code = preg_match('/(LKS1\.\S+)/', $o, $m) ? $m[1] : '';
    [$c, $o] = $cli('S', ['api/outils/supervision.php', 'appairer', $code, '--url-locale=' . $srv['S']['url']]);
    verifier('S utilise le code de P : appairé en pont', $c === 0 && str_contains($o, 'Appairé'), $o, $ok, $ko);
    [$c, $o] = $cli('P', ['api/outils/supervision.php', 'tester']);
    verifier('relais arrêté : P le dit tout de suite (pas d\'attente)', $c !== 0 && str_contains($o, 'relais'), trim($o), $ok, $ko);
    $relais = proc_open([PHP_BINARY, 'api/outils/supervision-pont.php', '--duree=60'], [1 => ['file', $srv['S']['dir'] . '/pont.log', 'a'], 2 => ['file', $srv['S']['dir'] . '/pont.log', 'a']], $t, $srv['S']['dir']);
    $procs[] = $relais;
    sleep(2);
    $t0 = microtime(true);
    [$c, $o] = $cli('P', ['api/outils/supervision.php', 'tester']);
    verifier('relais lancé : P joint S par le pont', $c === 0 && str_contains($o, 'répond'), trim($o), $ok, $ko);
    [$c, $d] = $api('P', 'adm', 'GET', 'connect_supervision');
    verifier('écran Supervision de P servi par le pont', $c === 200 && count((array)($d['passerelles'] ?? [])) === $nbS, "HTTP $c", $ok, $ko);

    // ── 6. Assistant de P sur la supervision de S ───────────────────────────
    echo "\n  6. Assistant\n";
    [, $o] = $php('P', "require 'api/AssistantMoteur.php'; \$t = new AssistantTools(\$db, null, ['user' => ['Id' => 1, 'Role' => 'Gestionnaire', 'Login' => 'admin']]); \$m = new AssistantMoteur(\$t);"
        . "echo \$m->repondre('combien d alarmes critiques ?')['texte'], \"\\n@@\\n\", \$m->repondre('quelle consommation d électricité depuis le début de l année ?')['texte'];");
    [$al, $co] = array_pad(explode("\n@@\n", $o, 2), 2, '');
    verifier('« combien d\'alarmes critiques ? » : l\'alarme de S', str_contains($al, 'Épreuve : pompe en défaut') && str_contains($al, 'critique'), mb_substr($al, 0, 160), $ok, $ko);
    verifier('« consommation d\'électricité… » : des kWh calculés sur S', str_contains($co, 'Électricité') && str_contains($co, 'kWh'), mb_substr($co, 0, 160), $ok, $ko);
    @proc_terminate($relais);
} catch (\Throwable $e) {
    $ko[] = 'exception';
    echo "  ❌  " . $e->getMessage() . "\n";
}

// ── 7. Compréhension (sans serveur) ──────────────────────────────────────────
echo "\n  7. Compréhension des questions de supervision\n";
require_once $source . '/api/AssistantLangue.php';
require_once $source . '/api/AssistantSupervision.php';
$q = fn(string $s) => AssistantSupervision::detecter(AssistantLangue::preparer($s));
verifier('conso, alarmes, état, valeur reconnus', $q('combien a consommé l\'hôtel de ville ?') === 'conso' && $q('nombre d\'alarmes ce mois') === 'alarmes'
    && $q('la GTB fonctionne ?') === 'etat' && $q('quelle est la température du bureau 12 ?') === 'valeur', '', $ok, $ko);
verifier('« où est la sonde de température ? » n\'est pas une valeur', $q('où est la sonde de température ?') === null, (string)$q('où est la sonde de température ?'), $ok, $ko);
verifier('une question de GMAO n\'est pas de la supervision', $q('combien d\'extincteurs au bâtiment A ?') === null && $q('contrats qui expirent') === null, '', $ok, $ko);
$p = AssistantSupervision::periode(' du 01/02/2026 au 15/03/2026 ', []);
verifier('« du 01/02/2026 au 15/03/2026 »', $p && $p[0] === '2026-02-01' && $p[1] === '2026-03-15', json_encode($p), $ok, $ko);
$p = AssistantSupervision::periode(' entre janvier et mars 2025 ', []);
verifier('« entre janvier et mars 2025 » : mois entiers', $p && $p[0] === '2025-01-01' && $p[1] === '2025-03-31', json_encode($p), $ok, $ko);
$p = AssistantSupervision::periode(' les 30 derniers jours ', []);
verifier('« les 30 derniers jours » : jusqu\'à aujourd\'hui', $p && $p[1] === date('Y-m-d') && $p[0] === date('Y-m-d', strtotime('-29 days')), json_encode($p), $ok, $ko);
$p = AssistantSupervision::periode(' x ', ['mois' => '2099-05']);
verifier('un mois encore à venir, sans année écrite : celui de l\'an dernier', $p && $p[0] === '2098-05-01', json_encode($p), $ok, $ko);
verifier('nombres à la française', AssistantSupervision::nombre(27525.956) === "27\u{202F}526" && AssistantSupervision::nombre(126.619) === '126,6', AssistantSupervision::nombre(27525.956), $ok, $ko);
verifier('tournures courantes : « combien d\'électricité », « il fait combien », « température extérieure »',
         $q('on a dépensé combien d\'électricité à l\'école ?') === 'conso' && $q('combien d\'eau le mois dernier ?') === 'conso'
      && $q('il fait combien dans le bureau R+1 ?') === 'valeur' && $q('température extérieure') === 'valeur'
      && $q('est-ce qu\'il y a un problème sur les compteurs ?') === 'etat' && $q('est-ce que la sous-station est en marche ?') === 'valeur', '', $ok, $ko);
verifier('une fiche de la GMAO citée reste de la GMAO (« interventions sur les capteurs en panne »)',
         $q('combien d\'interventions sur les capteurs en panne ?') === null && $q('consigne de sécurité incendie') === null
      && $q('combien d\'interventions pour panne de compteur ?') === null && $q('combien d\'alarmes ont donné lieu à une demande ?') === 'alarmes', '', $ok, $ko);

// Réponses, sur une supervision simulée (la lecture est une simple fonction).
require_once $source . '/api/connect/Lecture.php';
$resume = fn(string $passage) => ['connexions' => 2, 'connexions_actives' => 2, 'points' => 14, 'en_defaut' => [], 'points_en_defaut' => [],
    'alarmes_actives' => 0, 'dernier_passage' => $passage, 'batiments' => ['Hôtel de Ville', 'Groupe scolaire Les Marronniers']];
$repEtat = function (string $question, string $passage) use ($resume): string {
    $c = ['question' => $question, 'filtres' => [], 'sup' => ['quoi' => 'etat']];
    return AssistantSupervision::repondre($c, fn(string $quoi, array $a) => $resume($passage))['texte'];
};
$arrete = $repEtat('la GTB fonctionne ?', date('Y-m-d H:i:s', time() - 3 * 86400));
verifier('collecte arrêtée : jamais « tout fonctionne », la date du dernier passage et CXC-001',
         !str_contains($arrete, 'Tout fonctionne') && str_contains($arrete, 'La collecte ne passe plus') && str_contains($arrete, 'CXC-001'), $arrete, $ok, $ko);
$frais = $repEtat('capteurs en défaut ?', date('Y-m-d H:i:s', time() - 60));
verifier('« capteurs en défaut ? » sans défaut : la réponse le dit d\'abord', str_starts_with($frais, '**Aucune connexion ni point en défaut**'), $frais, $ok, $ko);
$points = [
    ['nom' => 'CO₂ classe CM2', 'batiment' => 'Groupe scolaire Les Marronniers', 'valeur' => 420.0, 'decimales' => 0, 'unite' => 'ppm', 'lecture' => date('Y-m-d H:i:s'), 'alarmes' => 0],
    ['nom' => 'CO₂ salle du conseil', 'batiment' => 'Hôtel de Ville', 'valeur' => 1461.0, 'decimales' => 0, 'unite' => 'ppm', 'lecture' => date('Y-m-d H:i:s'), 'alarmes' => 0],
];
$lireValeurs = function (string $quoi, array $a) use ($points, $resume): array {
    if ($quoi === 'resume') return $resume(date('Y-m-d H:i:s'));
    // Comme ConnectLecture : un point doit contenir TOUS les mots.
    $r = array_values(array_filter($points, function ($p) use ($a) {
        $nom = ' ' . ConnectLecture::norm($p['nom'] . ' ' . $p['batiment']) . ' ';
        foreach ((array)($a['mots'] ?? []) as $w) if (!str_contains($nom, ' ' . $w . ' ')) return false;
        return true;
    }));
    return ['points' => $r, 'total' => count($r)];
};
$v1 = AssistantSupervision::repondre(['question' => 'quel est le taux de CO2 dans la classe CM2 ?', 'filtres' => [], 'sup' => ['quoi' => 'valeur']], $lireValeurs)['texte'];
$v2 = AssistantSupervision::repondre(['question' => 'quel est le CO2 de la classe CM2 côté cour ?', 'filtres' => [], 'sup' => ['quoi' => 'valeur']], $lireValeurs)['texte'];
verifier('« CO2 dans la classe CM2 » : ce point-là, pas tous les CO₂ du parc', str_contains($v1, 'classe CM2') && !str_contains($v1, 'salle du conseil')
      && str_contains($v2, 'classe CM2') && !str_contains($v2, 'salle du conseil'), $v1 . ' | ' . $v2, $ok, $ko);

// Le demandeur n'a pas la supervision : une QUESTION reçoit une explication, un SIGNALEMENT reste une demande.
require_once $source . '/api/AssistantDemandeur.php';
$dem = new AssistantDemandeur(['categories' => ['Électricité', 'Sécurité incendie', 'Plomberie']]);
$i = fn(string $m, array $h = []) => $dem->repondre($m, $h)['intention'];
$refus = $dem->repondre('combien d\'alarmes ce mois-ci ?');
verifier('demandeur : « combien d\'alarmes », « conso d\'électricité », « la GTB fonctionne ? » → explication, pas de demande',
         $refus['intention'] === 'supervision' && !str_contains($refus['texte'], '[ACTION:') && $i('consommation d\'électricité du groupe scolaire depuis le début du mois') === 'supervision'
      && $i('est-ce que la GTB fonctionne ?') === 'supervision'
      && $i('et en eau ?', [['role' => 'user', 'content' => 'conso de l\'école ?'], ['role' => 'assistant', 'content' => $refus['texte']]]) === 'supervision',
         $refus['intention'], $ok, $ko);
verifier('demandeur : « l\'alarme sonne dans le couloir », « capteur en panne salle 3 » → demande préparée',
         $i('l\'alarme sonne dans le couloir du 2e') === 'creer' && $i('capteur en panne salle 3') === 'creer' && $i('il fait trop froid dans mon bureau 214') === 'creer', '', $ok, $ko);
$titre = fn(string $m) => (string)($dem->repondre($m)['comprehension']['demande']['titre'] ?? '');
verifier('demandeur : une gêne (« température trop basse », « CO2 trop élevé ») → demande au titre clair',
         $titre('température trop basse au bureau 12') === 'Température trop basse' && $titre('co2 trop élevé en classe') === "Qualité de l'air (CO₂ élevé)"
      && $titre('température trop élevée salle 3') === 'Température trop élevée', $titre('température trop basse au bureau 12'), $ok, $ko);
// Bâtiment dit avec les mots de tous les jours, point d'état lu en mots.
$lireBat = function (string $quoi, array $a) use ($resume): array {
    if ($quoi === 'resume') return $resume(date('Y-m-d H:i:s'));
    if ($quoi === 'conso') return ['nb_compteurs' => 1, 'totaux' => [['batiment' => $a['batiment'] ?? '—', 'type' => 'electricite', 'total' => 1000.0, 'unite' => 'kWh', 'compteurs' => 1]], 'compteurs' => []];
    $pts = [['nom' => 'Marche chauffage (sous-station)', 'nature' => 'etat', 'batiment' => 'Hôtel de Ville', 'valeur' => 0.0, 'texte' => 'arrêt', 'decimales' => 0, 'unite' => '', 'lecture' => date('Y-m-d H:i:s'), 'alarmes' => 0],
            ['nom' => 'Index chaleur (sous-station)', 'nature' => 'compteur', 'batiment' => 'Hôtel de Ville', 'valeur' => 6053.4, 'texte' => null, 'decimales' => 2, 'unite' => 'MWh', 'lecture' => date('Y-m-d H:i:s'), 'alarmes' => 0]];
    $pts = array_values(array_filter($pts, fn($p) => empty($a['nature']) || $p['nature'] === $a['nature']));
    return ['points' => $pts, 'total' => count($pts)];
};
$ecole = AssistantSupervision::repondre(['question' => 'on a dépensé combien d\'électricité à l\'école en octobre ?', 'filtres' => [], 'sup' => ['quoi' => 'conso']], $lireBat)['texte'];
$marche = AssistantSupervision::repondre(['question' => 'est-ce que la sous-station est en marche ?', 'filtres' => [], 'sup' => ['quoi' => 'valeur']], $lireBat)['texte'];
verifier('« l\'école » = le groupe scolaire ; « en marche ? » : le point d\'état, lu « arrêt » et non 0',
         str_contains($ecole, 'Groupe scolaire Les Marronniers') && str_contains($marche, '**arrêt**') && !str_contains($marche, 'Index chaleur'), $ecole . ' | ' . $marche, $ok, $ko);

// ── 8. Confirmation d'identité Microsoft / Google (sans fournisseur) ─────────
echo "\n  8. Confirmation d'identité (retour de la fenêtre Microsoft / Google)\n";
require_once $source . '/api/IdentiteConfirmee.php';
$_SESSION = ['user' => ['Login' => 'jeanne@ville.fr', 'Provider' => 'microsoft'], 'tenant_key' => ''];
$etat = ['reauth' => ['fournisseur' => 'microsoft', 'login' => 'jeanne@ville.fr', 'courriel' => 'jeanne@ville.fr',
                      'debut' => time() - 20, 'secondes' => 1800, 'tenant' => '']];
[$r1] = IdentiteConfirmee::conclure($etat, 'microsoft', 'paul@ville.fr', ['auth_time' => time()]);
[$r2] = IdentiteConfirmee::conclure($etat, 'microsoft', 'jeanne@ville.fr', ['auth_time' => time() - 3600]);
[$r3] = IdentiteConfirmee::conclure($etat, 'google', 'jeanne@ville.fr', ['auth_time' => time()]);
$_SESSION['tenant_key'] = 'autre';
[$r4] = IdentiteConfirmee::conclure($etat, 'microsoft', 'jeanne@ville.fr', ['auth_time' => time()]);
$_SESSION['tenant_key'] = '';
verifier('refusée : autre compte, mot de passe non redemandé, autre fournisseur, autre organisation',
         !$r1 && !$r2 && !$r3 && !$r4 && IdentiteConfirmee::restant() === 0, json_encode([$r1, $r2, $r3, $r4]), $ok, $ko);
[$r5, $s5] = IdentiteConfirmee::conclure($etat, 'microsoft', 'JEANNE@ville.fr', ['auth_time' => time() - 5]);
verifier('acceptée : même compte, mot de passe redemandé à l\'instant → 30 min', $r5 && $s5 === 1800 && IdentiteConfirmee::restant() >= 1795, (string)$s5, $ok, $ko);
$_SESSION['connect_elevation'] = 0;
$etatG = ['reauth' => ['fournisseur' => 'google'] + $etat['reauth']];
[$g1] = IdentiteConfirmee::conclure($etatG, 'google', 'jeanne@ville.fr', []);
[$m1] = IdentiteConfirmee::conclure($etat, 'microsoft', 'jeanne@ville.fr', []);
verifier('Google sans heure d\'authentification : refusée ; Microsoft (prompt=login) : acceptée', !$g1 && $m1, json_encode([$g1, $m1]), $ok, $ko);
verifier('liste des durées fermée (5 min à 8 h)', IdentiteConfirmee::DUREES[0] === 5 && max(IdentiteConfirmee::DUREES) === 480
   && IdentiteConfirmee::accorder(999999) === 480 * 60, '', $ok, $ko);
$_SESSION = [];

echo "\n" . ($ko ? '❌ ' . count($ko) . ' échec(s) : ' . implode(', ', $ko) . ' — ' : '✅ ') . "$ok vérification(s) réussie(s)\n";
exit($ko ? 1 : 0);

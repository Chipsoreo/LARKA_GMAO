<?php
/*
 * SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
 * SPDX-License-Identifier: LicenseRef-Larka-Proprietary
 *
 * This file is part of Larka, proprietary software by Mickaël Larcin.
 * All rights reserved. Use is subject to the license terms; copying,
 * distribution, modification or reverse-engineering without the author's
 * prior written permission is prohibited. See the LICENSE file for details.
 */

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * Larka — épreuve des cases des fiches : historique et ressemblances
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Les cases complémentaires des biens et des équipements :
 *   - une case retirée n'est pas oubliée : elle passe dans un historique (quand,
 *     par qui, combien de fiches gardent une valeur), et ses valeurs restent ;
 *   - la recréer sous sa clé la restaure, valeurs comprises, et la sort de
 *     l'historique ;
 *   - une case nouvelle qui ressemble à 75 % ou plus à une case existante ou
 *     retirée (« Garanti » / « Garantie », « N° inventaire » / « N° d'inventaire »)
 *     n'est créée qu'après confirmation ; deux libellés différents ne gênent pas ;
 *   - la mesure de ressemblance est la même côté serveur et côté écran (le
 *     même avertissement à la saisie et à l'enregistrement) ;
 *   - l'import signale les cases qu'il créerait en double ;
 *   - le Journal garde la trace ; un visionneur ne gère pas les cases.
 *
 *   php outils/epreuves/test-champs-perso.php
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
    else       { $ko[] = $titre; printf("  ❌  %-62s %s\n", $titre, mb_substr($detail, 0, 120)); }
}

// ── Copie jetable ─────────────────────────────────────────────────────────────
$dir = sys_get_temp_dir() . '/larka-cases-' . bin2hex(random_bytes(4));
$exclus = ['.git', 'outils', 'Documentations', 'config.json', 'config.key', '.env', 'tenants.json', '.user.ini', 'extensions'];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
                                    RecursiveIteratorIterator::SELF_FIRST);
foreach ($it as $f) {
    $rel = substr($f->getPathname(), strlen($source) + 1);
    $tete = explode('/', $rel)[0];
    if (in_array($tete, $exclus, true)) continue;
    if ($tete === 'data' && $f->isFile() && !in_array($f->getFilename(), ['.gitkeep', '.htaccess'], true)) continue;
    $cible = $dir . '/' . $rel;
    if ($f->isDir()) { @mkdir($cible, 0777, true); }
    else { @mkdir(dirname($cible), 0777, true); copy($f->getPathname(), $cible); }
}
@mkdir($dir . '/extensions', 0777, true);
$nettoyer = function () use ($dir) {
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                                        RecursiveIteratorIterator::CHILD_FIRST);
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
                  [0 => ['pipe', 'r'], 1 => ['file', $dir . '/serveur.log', 'a'], 2 => ['file', $dir . '/serveur.log', 'a']],
                  $tubes, $dir);
if (!is_resource($proc)) { $nettoyer(); echo "  ❌  Impossible de lancer le serveur intégré.\n"; exit(1); }
for ($i = 0; $i < 50; $i++) { if (@fsockopen('127.0.0.1', $port)) break; usleep(100000); }

$base = "http://127.0.0.1:$port";
$jarres = [];
$api = function (string $qui, string $methode, string $action, $corps = null) use ($base, $dir, &$jarres): array {
    $jarres[$qui] ??= $dir . "/cookies-$qui.txt";
    $ch = curl_init("$base/api/index.php?action=$action");
    $h = [];
    if (is_array($corps)) { $corps = json_encode($corps); $h[] = 'Content-Type: application/json'; }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $methode, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_COOKIEJAR => $jarres[$qui], CURLOPT_COOKIEFILE => $jarres[$qui], CURLOPT_HTTPHEADER => $h,
        CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*']);
    if ($corps !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $corps);
    $rep = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $j = json_decode($rep, true);
    return [$code, $j['data'] ?? ($j ?? $rep), $j['error'] ?? ''];
};

echo "\n═══════════════════════════════════════════════════════════════════════\n";
echo " Larka — cases des fiches : historique et ressemblances\n";
echo "═══════════════════════════════════════════════════════════════════════\n";

// ── 1. Ressemblance (sans serveur) ────────────────────────────────────────────
echo "\n  Ressemblance de deux libellés\n";
// Database.php exige une configuration à son chargement : on en extrait les
// deux fonctions pures, telles qu'elles sont écrites.
$srcDb = (string)file_get_contents($source . '/api/Database.php');
preg_match('/public static function champsPersoMots.*?\n    }\n/s', $srcDb, $m1);
preg_match('/public static function similariteLibelles.*?\n    }\n/s', $srcDb, $m2);
eval('final class EpreuveSimilarite { ' . ($m1[0] ?? '') . ($m2[0] ?? '') . ' }');
$paires = [
    ["N° d'inventaire", 'N° inventaire', true], ['Garantie', 'Garanti', true], ['Code analytique', 'Code analityque', true],
    ['Date de mise en service', 'Date mise en service', true], ['Fournisseur', 'Fournisseurs', true],
    ['Date achat', 'Date de sortie', false], ['Couleur', 'Couloir', false], ['Marque', 'Modèle', false], ['Numéro de série', 'N° série', false],
];
$fautes = [];
foreach ($paires as [$a, $b, $attendu]) {
    $s = EpreuveSimilarite::similariteLibelles($a, $b);
    if (($s >= 0.75) !== $attendu) $fautes[] = "« $a » / « $b » : $s";
}
verifier('seuil de 75 % : fautes de frappe et petits mots attrapés, libellés distincts laissés', !$fautes, implode(' ; ', $fautes), $ok, $ko);

// Même calcul côté écran (js/ui.js, ChampsPerso.similarite) : node, s'il est là.
$node = trim((string)@shell_exec('command -v node 2>/dev/null'));
if ($node !== '') {
    $js = (string)file_get_contents($source . '/js/ui.js');
    $a = strpos($js, 'const ChampsPerso = {'); $b = strpos($js, 'window.ChampsPerso = ChampsPerso;');
    $script = tempnam(sys_get_temp_dir(), 'cp') . '.js';
    file_put_contents($script, "global.window={};global.document={};\n" . str_replace('const ChampsPerso', 'global.CP', substr($js, $a, $b - $a))
        . "\nconst p=" . json_encode(array_map(fn($x) => [$x[0], $x[1]], $paires), JSON_UNESCAPED_UNICODE) . ";\nconsole.log(JSON.stringify(p.map(([a,b])=>CP.similarite(a,b))));\n");
    $sortieJs = json_decode((string)shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($script) . ' 2>&1'), true);
    @unlink($script);
    $ecarts = [];
    foreach ($paires as $i => [$a, $b]) {
        $php = EpreuveSimilarite::similariteLibelles($a, $b);
        if (!is_array($sortieJs) || abs(($sortieJs[$i] ?? -1) - $php) > 0.001) $ecarts[] = "« $a » / « $b » : PHP $php, JS " . ($sortieJs[$i] ?? '?');
    }
    verifier('même mesure à l\'écran et au serveur', !$ecarts, implode(' ; ', $ecarts), $ok, $ko);
} else {
    echo "  ⊘  même mesure à l'écran et au serveur      (node absent : non vérifié)\n";
}

try {
    $api('adm', 'POST', 'login', ['login' => 'admin', 'password' => 'admin']);   // crée la base
    $initial = preg_match('/Mot de passe : (\S+)/', (string)@file_get_contents($dir . '/data/security/admin-initial.txt'), $mm) ? $mm[1] : '';
    $api('adm', 'POST', 'login', ['login' => 'admin', 'password' => $initial]);
    $api('adm', 'POST', 'change_password', ['oldPassword' => $initial, 'newPassword' => 'Epreuve-Admin-1']);
    $api('adm', 'POST', 'logout');
    [$c] = $api('adm', 'POST', 'login', ['login' => 'admin', 'password' => 'Epreuve-Admin-1']);
    if ($c !== 200) throw new RuntimeException("connexion administrateur impossible (HTTP $c)");
    $api('adm', 'POST', 'utilisateurs', ['nom' => 'vis', 'prenom' => 'E', 'login' => 'vis', 'motDePasse' => 'Epreuve-vis-1', 'role' => 'Visionneur', 'provider' => 'local']);
    $api('vis', 'POST', 'login', ['login' => 'vis', 'password' => 'Epreuve-vis-1']);
    $cases = fn(array $champs, bool $confirmer = false) => $api('adm', 'POST', 'champs_perso&cible=biens',
        ['cible' => 'biens', 'champs' => $champs, 'confirmerRessemblances' => $confirmer]);

    // ── 2. Historique ─────────────────────────────────────────────────────────
    echo "\n  Cases retirées\n";
    [$c, $r] = $cases([['libelle' => 'Garantie', 'type' => 'date'], ['libelle' => "N° d'inventaire", 'type' => 'texte']]);
    verifier('deux cases créées, avec leur date et leur auteur',
             $c === 200 && count((array)$r) === 2 && ($r[0]['cle'] ?? '') === 'garantie' && ($r[0]['creee_par'] ?? '') === 'admin' && !empty($r[0]['creee_le']),
             json_encode($r, JSON_UNESCAPED_UNICODE), $ok, $ko);
    [, $b1] = $api('adm', 'POST', 'biens', ['numero' => 'B-CASES-1', 'designation' => 'Bureau', 'champsPerso' => ['garantie' => '2027-03-01', 'n_d_inventaire' => 'INV-77']]);
    [, $b2] = $api('adm', 'POST', 'biens', ['numero' => 'B-CASES-2', 'designation' => 'Armoire', 'champsPerso' => ['garantie' => '2028-01-15']]);
    $pdo = new PDO('sqlite:' . $dir . '/data/gmao.db');
    $valeurs = fn(int $id) => json_decode((string)$pdo->query('SELECT ChampsPerso FROM Biens WHERE Id = ' . $id)->fetchColumn(), true) ?: [];
    [$c, $r] = $cases([['cle' => 'n_d_inventaire', 'libelle' => "N° d'inventaire", 'type' => 'texte']]);
    [, $h] = $api('adm', 'GET', 'champs_perso_historique&cible=biens');
    verifier('« Garantie » retirée : dans l\'historique, avec la date, l\'auteur et 2 fiches qui gardent une valeur',
             count((array)$h) === 1 && ($h[0]['cle'] ?? '') === 'garantie' && ($h[0]['supprimee_par'] ?? '') === 'admin'
             && !empty($h[0]['supprimee_le']) && (int)($h[0]['nb_fiches'] ?? 0) === 2,
             json_encode($h, JSON_UNESCAPED_UNICODE), $ok, $ko);
    verifier('les valeurs des fiches sont intactes', ($valeurs((int)($b1['id'] ?? 0))['garantie'] ?? '') === '2027-03-01', json_encode($valeurs((int)($b1['id'] ?? 0))), $ok, $ko);

    // ── 3. Ressemblances ──────────────────────────────────────────────────────
    echo "\n  Ressemblances\n";
    $garder = ['cle' => 'n_d_inventaire', 'libelle' => "N° d'inventaire", 'type' => 'texte'];
    [$c, $r] = $cases([$garder, ['libelle' => 'Garanti', 'type' => 'date'], ['libelle' => 'N° inventaire']]);
    $par = [];
    foreach ((array)($r['ressemblances'] ?? []) as $x) $par[$x['libelle']] = $x;
    verifier('« Garanti » et « N° inventaire » : rien n\'est créé, la question est posée',
             $c === 200 && !empty($r['aConfirmer']) && isset($par['Garanti'], $par['N° inventaire']),
             json_encode($r, JSON_UNESCAPED_UNICODE), $ok, $ko);
    verifier('… avec ce à quoi elles ressemblent : la case retirée (et ses 2 fiches), la case existante',
             ($par['Garanti']['statut'] ?? '') === 'supprimee' && ($par['Garanti']['ressemble_a'] ?? '') === 'Garantie' && (int)($par['Garanti']['nb_fiches'] ?? 0) === 2
             && ($par['N° inventaire']['statut'] ?? '') === 'existante' && (float)($par['N° inventaire']['similarite'] ?? 0) === 1.0,
             '', $ok, $ko);
    [, $defs] = $api('adm', 'GET', 'champs_perso&cible=biens');
    verifier('rien n\'a été enregistré sans réponse', count((array)$defs) === 1, json_encode($defs, JSON_UNESCAPED_UNICODE), $ok, $ko);
    [$c, $r] = $cases([$garder, ['libelle' => 'Date de sortie', 'type' => 'date']]);
    verifier('un libellé qui ne ressemble à rien est créé sans question', is_array($r) && !isset($r['aConfirmer']) && count($r) === 2,
             json_encode($r, JSON_UNESCAPED_UNICODE), $ok, $ko);
    [$c, $r] = $cases([$garder, ['cle' => 'date_de_sortie', 'libelle' => 'Date de sortie', 'type' => 'date'], ['libelle' => 'Garanti', 'type' => 'date']], true);
    verifier('confirmé : « Garanti » est créée à côté (le choix reste à l\'utilisateur)',
             is_array($r) && in_array('garanti', array_column($r, 'cle'), true), json_encode($r, JSON_UNESCAPED_UNICODE), $ok, $ko);

    // ── 4. Restauration ───────────────────────────────────────────────────────
    echo "\n  Restauration\n";
    [$c, $r] = $cases([$garder, ['cle' => 'date_de_sortie', 'libelle' => 'Date de sortie', 'type' => 'date'],
                       ['cle' => 'garantie', 'libelle' => 'Garantie', 'type' => 'date']]);
    [, $h] = $api('adm', 'GET', 'champs_perso_historique&cible=biens');
    $clesH = array_column((array)$h, 'cle');
    verifier('« Garantie » recréée sous sa clé : restaurée, sortie de l\'historique, sa date de création d\'origine gardée',
             is_array($r) && in_array('garantie', array_column($r, 'cle'), true) && !in_array('garantie', $clesH, true)
             && (current(array_filter($r, fn($x) => $x['cle'] === 'garantie'))['creee_par'] ?? '') === 'admin',
             json_encode($h, JSON_UNESCAPED_UNICODE), $ok, $ko);
    verifier('… et « Garanti », retirée à son tour, y entre', in_array('garanti', $clesH, true), implode(', ', $clesH), $ok, $ko);
    verifier('les valeurs de la case restaurée sont là', ($valeurs((int)($b2['id'] ?? 0))['garantie'] ?? '') === '2028-01-15', '', $ok, $ko);
    $journal = (int)$pdo->query("SELECT COUNT(*) FROM Journal WHERE Message LIKE 'Cases des fiches biens%'")->fetchColumn();
    $restau  = (int)$pdo->query("SELECT COUNT(*) FROM Journal WHERE Message LIKE '%restaurée(s) : « Garantie »%'")->fetchColumn();
    verifier('le Journal garde chaque ajout, retrait et restauration', $journal >= 4 && $restau === 1, "$journal entrée(s), $restau restauration", $ok, $ko);

    // ── 5. Import ─────────────────────────────────────────────────────────────
    echo "\n  Import\n";
    [$c, $r] = $api('adm', 'POST', 'import_biens', ['dryRun' => true, 'target' => 'biens', 'dedup' => 'update',
        'biens' => [['numero' => 'B-CASES-3', 'designation' => 'Chaise', 'champsPerso' => ['date_sortie' => '2026-01-01']]],
        'champsPersoNouveaux' => [['cle' => 'date_sortie', 'libelle' => 'Date sortie', 'type' => 'date']],
        'champsMappes' => ['numero', 'designation']]);
    $rs = (array)($r['casesRessemblances'] ?? []);
    verifier('l\'aperçu de l\'import signale « Date sortie », qui ressemble à « Date de sortie »',
             $c === 200 && count(array_filter($rs, fn($x) => ($x['libelle'] ?? '') === 'Date sortie' && ($x['ressemble_a'] ?? '') === 'Date de sortie')) === 1,
             json_encode($rs, JSON_UNESCAPED_UNICODE), $ok, $ko);

    // ── 6. Droits ─────────────────────────────────────────────────────────────
    echo "\n  Droits\n";
    [$cv] = $api('vis', 'POST', 'champs_perso&cible=biens', ['cible' => 'biens', 'champs' => []]);
    [$ch] = $api('vis', 'GET', 'champs_perso_historique&cible=biens');
    [$cl] = $api('vis', 'GET', 'champs_perso&cible=biens');
    verifier('visionneur : lit les cases, mais ni les modifier ni voir l\'historique', $cv === 403 && $ch === 403 && $cl === 200, "POST $cv, historique $ch, lecture $cl", $ok, $ko);
    // (« interventions » et « stock » ont des cases depuis la v23 : la cible
    // inconnue est donc un nom qui n'en a pas.)
    [$cx] = $api('adm', 'GET', 'champs_perso_historique&cible=contrats');
    [$cy] = $api('adm', 'GET', 'champs_perso&cible=..%2Fbiens');
    verifier('cible inconnue refusée', $cx === 400 && $cy === 400, "HTTP $cx / $cy", $ok, $ko);

    // ── 7. Icônes Lucide choisies dans la bibliothèque complète ──────────────
    // (même installation jetable : le sélecteur d'icône des plans et des
    // urgences enregistre ses icônes hors du jeu embarqué ici.)
    echo "\n  Icônes choisies hors du jeu embarqué\n";
    [$c, $r] = $api('adm', 'POST', 'icones_choisies', ['nom' => 'sailboat']);
    verifier('une icône de la bibliothèque est enregistrée, et son dessin rendu',
             $c === 200 && str_starts_with((string)($r['css'] ?? ''), '.lk-sailboat{--lk:url("data:image/svg+xml,'), substr((string)($r['css'] ?? ''), 0, 60), $ok, $ko);
    [$c, $r] = $api('vis', 'GET', 'icones_choisies');
    verifier('tout utilisateur connecté reçoit le dessin des icônes choisies', $c === 200 && ($r['noms'] ?? []) === ['sailboat'] && str_contains((string)($r['css'] ?? ''), '.lk-sailboat{'),
             json_encode($r['noms'] ?? null), $ok, $ko);
    [$c1] = $api('adm', 'POST', 'icones_choisies', ['nom' => 'pas-une-icone']);
    [$c2] = $api('adm', 'POST', 'icones_choisies', ['nom' => '"><script>']);
    [$c3] = $api('vis', 'POST', 'icones_choisies', ['nom' => 'anchor']);
    verifier('nom inconnu ou forgé refusé ; un visionneur n\'en ajoute pas', $c1 === 400 && $c2 === 400 && $c3 === 403, "HTTP $c1 / $c2 / $c3", $ok, $ko);
} catch (Throwable $e) {
    $ko[] = 'exception : ' . $e->getMessage();
    echo "  ❌  " . $e->getMessage() . "\n";
}

proc_terminate($proc); proc_close($proc);
$nettoyer();

$total = $ok + count($ko);
echo "\n───────────────────────────────────────────────────────────────────────\n";
if ($ko === []) {
    printf(" ✅ %d/%d — une case retirée n'est pas perdue, et une faute de frappe ne crée pas de doublon.\n", $ok, $total);
    exit(0);
}
printf(" ❌ %d échec(s) sur %d :\n", count($ko), $total);
foreach ($ko as $t) echo "      • $t\n";
exit(1);

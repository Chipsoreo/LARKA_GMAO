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
 * Larka — épreuve de l'import du stock (Excel / CSV)
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Ce qu'un fichier d'articles (inventaire tenu à la main, export d'un
 * fournisseur) doit donner dans Larka :
 *   - référence obligatoire ; désignation obligatoire pour un nouvel article
 *     (un bon de livraison « référence + quantité » met à jour l'existant) ;
 *   - l'aperçu n'écrit rien, et annonce exactement ce que l'import fera ;
 *   - un article déjà là est reconnu à sa référence (et à son type si la ligne
 *     en donne un), mis à jour — seules les colonnes du fichier — ou laissé ;
 *     plusieurs articles sous la référence sans type : erreur, pas de hasard ;
 *     deux fois dans le fichier : refusé ;
 *   - la quantité remplace (inventaire) ou s'ajoute (livraison), jamais négative ;
 *   - une catégorie nouvelle est proposée, décochable ; une connue est reprise
 *     sous son nom exact (« consommable » → « Consommable ») ;
 *   - un contrat connu passe l'article en « fourni par contrat » ;
 *   - les colonnes sans champ deviennent des cases des fiches du stock ;
 *   - les droits : visionneur et demandeur ne peuvent pas importer.
 *
 * Autonome : copie jetable de l'application, SQLite, serveur intégré.
 *
 *   php outils/epreuves/test-import-stock.php
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
$dir = sys_get_temp_dir() . '/larka-impstock-' . bin2hex(random_bytes(4));
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
echo " Larka — import du stock depuis Excel / CSV\n";
echo "═══════════════════════════════════════════════════════════════════════\n";

try {
    $api('adm', 'POST', 'login', ['login' => 'admin', 'password' => 'admin']);   // crée la base
    $initial = preg_match('/Mot de passe : (\S+)/', (string)@file_get_contents($dir . '/data/security/admin-initial.txt'), $mm) ? $mm[1] : '';
    $api('adm', 'POST', 'login', ['login' => 'admin', 'password' => $initial]);
    $api('adm', 'POST', 'change_password', ['oldPassword' => $initial, 'newPassword' => 'Epreuve-Admin-1']);
    $api('adm', 'POST', 'logout');
    [$c] = $api('adm', 'POST', 'login', ['login' => 'admin', 'password' => 'Epreuve-Admin-1']);
    if ($c !== 200) throw new RuntimeException("connexion administrateur impossible (HTTP $c)");
    foreach (['vis' => 'Visionneur', 'dem' => 'Demandeur'] as $l => $r) {
        $api('adm', 'POST', 'utilisateurs', ['nom' => $l, 'prenom' => 'E', 'login' => $l,
             'motDePasse' => "Epreuve-$l-1", 'role' => $r, 'provider' => 'local']);
        $api($l, 'POST', 'login', ['login' => $l, 'password' => "Epreuve-$l-1"]);
    }
    $api('adm', 'POST', 'contrats', ['numero' => 'CT-1', 'societe' => 'ACME Fournitures', 'type' => 'Maintenance',
         'dateDebut' => '2026-01-01', 'dateFin' => '2027-01-01']);
    // Un article déjà en stock : 10 ampoules LED.
    [$c0] = $api('adm', 'POST', 'stock', ['reference' => 'LUM-001', 'designation' => 'Ampoule E27', 'categorie' => 'Consommable',
         'typeArticle' => 'LED', 'quantite' => 10, 'seuilAlerte' => 2, 'prixUnitaire' => 3.5, 'emplacement' => 'Armoire A']);
    if ($c0 !== 200) throw new RuntimeException("article d'essai non créé (HTTP $c0)");
    $pdo = new PDO('sqlite:' . $dir . '/data/gmao.db');
    $nb = fn() => (int)$pdo->query('SELECT COUNT(*) FROM Stock')->fetchColumn();
    $art = fn(string $ref, string $type = '') => $pdo->query("SELECT * FROM Stock WHERE Reference = " . $pdo->quote($ref)
                                                             . " AND COALESCE(TypeArticle,'') = " . $pdo->quote($type))->fetch(PDO::FETCH_ASSOC) ?: [];
    $ct1 = (int)$pdo->query("SELECT Id FROM Contrats WHERE Numero = 'CT-1'")->fetchColumn();

    $items = [
        ['_row' => 2, 'reference' => 'LUM-001', 'typeArticle' => 'LED', 'designation' => 'Ampoule LED E27 10W', 'quantite' => '5'],
        ['_row' => 3, 'reference' => 'LUM-001', 'typeArticle' => 'Halogène', 'designation' => 'Ampoule halogène E27', 'quantite' => '4', 'categorie' => 'consommable'],
        ['_row' => 4, 'reference' => 'FIL-120', 'designation' => 'Filtre G4 592x592', 'categorie' => 'Filtration', 'quantite' => '8,0',
         'seuilAlerte' => '3', 'prixUnitaire' => '12,50 €', 'contrat' => 'CT-1', 'champsPerso' => ['code_analytique' => 'AN-44']],
        ['_row' => 5, 'reference' => '', 'designation' => 'Sans référence', 'quantite' => '1'],
        ['_row' => 6, 'reference' => 'FIL-120', 'designation' => 'Filtre en double', 'quantite' => '2'],
        ['_row' => 7, 'reference' => 'VIS-6', 'designation' => 'Vis 6x40', 'quantite' => '-3', 'prixUnitaire' => 'sur devis', 'contrat' => 'CT-INCONNU'],
        ['_row' => 8, 'reference' => 'GANT-L', 'designation' => 'Gants nitrile L', 'quantite' => '2,5', 'source' => 'n\'importe quoi'],
    ];
    $mappes = ['reference', 'typeArticle', 'designation', 'quantite', 'categorie', 'seuilAlerte', 'prixUnitaire', 'contrat', 'source'];
    $nouv = [['cle' => 'code_analytique', 'libelle' => 'Code analytique', 'type' => 'texte']];
    $corps = fn(array $plus = []) => array_merge(['items' => $items, 'champsMappes' => $mappes, 'champsPersoNouveaux' => $nouv], $plus);

    // ── 1. Aperçu ─────────────────────────────────────────────────────────────
    echo "\n  Aperçu (rien n'est écrit)\n";
    $avant = $nb();
    [$c, $a] = $api('adm', 'POST', 'import_stock', $corps(['dryRun' => true]));
    $L = [];
    foreach (($a['lignes'] ?? []) as $l) $L[$l['row']] = $l;
    verifier('aperçu : 4 à créer, 1 à mettre à jour, 2 en erreur',
             $c === 200 && ($a['aCreer'] ?? -1) === 4 && ($a['aMettreAJour'] ?? -1) === 1 && ($a['enErreur'] ?? -1) === 2,
             "HTTP $c " . json_encode(array_intersect_key((array)$a, array_flip(['aCreer', 'aMettreAJour', 'enErreur']))), $ok, $ko);
    verifier("l'aperçu n'a rien écrit (ni article, ni case)", $nb() === $avant && $api('adm', 'GET', 'champs_perso&cible=stock')[1] === [],
             $nb() . " / $avant", $ok, $ko);
    verifier('même référence, autre type d\'article : un article distinct', ($L[2]['action'] ?? '') === 'maj' && ($L[3]['action'] ?? '') === 'creer', '', $ok, $ko);
    verifier('référence vide : en erreur ; référence en double dans le fichier : en erreur',
             ($L[5]['action'] ?? '') === 'erreur' && ($L[6]['action'] ?? '') === 'erreur', json_encode([$L[5]['erreurs'] ?? [], $L[6]['erreurs'] ?? []]), $ok, $ko);
    verifier('quantité remplacée par défaut : 10 → 5', ($L[2]['quantiteAvant'] ?? null) === 10 && ($L[2]['quantite'] ?? null) === 5,
             json_encode([$L[2]['quantiteAvant'] ?? null, $L[2]['quantite'] ?? null]), $ok, $ko);
    verifier('catégorie « consommable » reprise sous son nom ; « Filtration » proposée',
             ($L[3]['categorie'] ?? '') === 'Consommable' && ($a['listesACreer']['CategorieStock'] ?? []) === ['Filtration'],
             json_encode($a['listesACreer'] ?? []), $ok, $ko);
    $msgs = fn(int $r) => implode(' | ', array_column($L[$r]['avert'] ?? [], 'message'));
    verifier('quantité négative, prix illisible, contrat inconnu : signalés',
             str_contains($msgs(7), 'négatif') && str_contains($msgs(7), 'prix non reconnu') && str_contains($msgs(7), 'contrat introuvable'), $msgs(7), $ok, $ko);
    verifier('quantité non entière arrondie, source inconnue : signalées',
             str_contains($msgs(8), 'arrondi') && str_contains($msgs(8), 'source inconnue'), $msgs(8), $ok, $ko);
    verifier('case « Code analytique » annoncée', ($a['casesACreer'] ?? null) === ['Code analytique'], json_encode($a['casesACreer'] ?? null), $ok, $ko);

    // ── 2. Import ─────────────────────────────────────────────────────────────
    echo "\n  Import\n";
    [$c, $r] = $api('adm', 'POST', 'import_stock', $corps(['listesNePasCreer' => ['CategorieStock' => []]]));
    verifier('import : 4 créés, 1 mis à jour, aucun échec', $c === 200 && ($r['crees'] ?? 0) === 4 && ($r['misesAJour'] ?? 0) === 1 && !($r['echecs'] ?? [1]),
             json_encode($r), $ok, $ko);
    $led = $art('LUM-001', 'LED');
    verifier('mise à jour : seules les colonnes du fichier (quantité, désignation), le reste gardé',
             (int)$led['Quantite'] === 5 && $led['Designation'] === 'Ampoule LED E27 10W' && (float)$led['PrixUnitaire'] === 3.5
             && $led['Emplacement'] === 'Armoire A' && (int)$led['SeuilAlerte'] === 2,
             json_encode(array_intersect_key($led, array_flip(['Quantite', 'Designation', 'PrixUnitaire', 'Emplacement', 'SeuilAlerte']))), $ok, $ko);
    $fil = $art('FIL-120');
    verifier('nouvel article : nombres lus (8,0 → 8 ; 12,50 € → 12,5), contrat → « fourni par contrat »',
             (int)$fil['Quantite'] === 8 && (float)$fil['PrixUnitaire'] === 12.5 && (int)$fil['SeuilAlerte'] === 3
             && $fil['Source'] === 'Contrat' && (int)$fil['ContratId'] === $ct1,
             json_encode(array_intersect_key($fil, array_flip(['Quantite', 'PrixUnitaire', 'Source', 'ContratId']))), $ok, $ko);
    $vis = $art('VIS-6');
    verifier('quantité négative ramenée à 0 ; sans catégorie : « Consommable » ; source par défaut : Achat',
             (int)$vis['Quantite'] === 0 && $vis['Categorie'] === 'Consommable' && $vis['Source'] === 'Achat' && $vis['ContratId'] === null,
             json_encode(array_intersect_key($vis, array_flip(['Quantite', 'Categorie', 'Source', 'ContratId']))), $ok, $ko);
    $cats = $pdo->query("SELECT Valeur FROM Listes WHERE Categorie = 'CategorieStock'")->fetchAll(PDO::FETCH_COLUMN);
    verifier('catégorie « Filtration » ajoutée à la liste', in_array('Filtration', $cats, true), json_encode($cats), $ok, $ko);
    [, $defs] = $api('adm', 'GET', 'champs_perso&cible=stock');
    verifier('case des fiches créée et remplie', array_column((array)$defs, 'cle') === ['code_analytique']
             && json_decode((string)$fil['ChampsPerso'], true) === ['code_analytique' => 'AN-44'],
             json_encode([$defs, $fil['ChampsPerso'] ?? null]), $ok, $ko);

    // ── 3. Livraison : les quantités s'ajoutent ───────────────────────────────
    echo "\n  Livraison et réimport\n";
    $livraison = ['items' => [['_row' => 2, 'reference' => 'LUM-001', 'typeArticle' => 'LED', 'quantite' => '12'],
                              ['_row' => 3, 'reference' => 'FIL-120', 'quantite' => '2']],
                  'champsMappes' => ['reference', 'typeArticle', 'quantite'], 'quantites' => 'ajouter'];
    [$c, $a] = $api('adm', 'POST', 'import_stock', $livraison + ['dryRun' => true]);
    $L = []; foreach (($a['lignes'] ?? []) as $l) $L[$l['row']] = $l;
    verifier('aperçu « ajouter » : 5 → 17 et 8 → 10', ($L[2]['quantite'] ?? null) === 17 && ($L[3]['quantite'] ?? null) === 10,
             json_encode([$L[2]['quantite'] ?? null, $L[3]['quantite'] ?? null]), $ok, $ko);
    $api('adm', 'POST', 'import_stock', $livraison);
    verifier('livraison importée : quantités ajoutées, désignation gardée',
             (int)$art('LUM-001', 'LED')['Quantite'] === 17 && (int)$art('FIL-120')['Quantite'] === 10
             && $art('FIL-120')['Designation'] === 'Filtre G4 592x592', '', $ok, $ko);
    [, $a] = $api('adm', 'POST', 'import_stock', ['items' => [['_row' => 2, 'reference' => 'NOUV-1', 'quantite' => '3']],
                                                 'champsMappes' => ['reference', 'quantite'], 'dryRun' => true]);
    verifier('référence inconnue sans désignation : en erreur (un nouvel article en exige une)',
             ($a['enErreur'] ?? 0) === 1 && str_contains(json_encode($a['lignes'][0]['erreurs'] ?? [], JSON_UNESCAPED_UNICODE), 'nouvel article'), '', $ok, $ko);
    [, $a] = $api('adm', 'POST', 'import_stock', $livraison + ['dedup' => 'skip', 'dryRun' => true]);
    verifier('« laisser la fiche » : rien à mettre à jour', ($a['laissees'] ?? 0) === 2 && ($a['aMettreAJour'] ?? -1) === 0, json_encode($a['laissees'] ?? null), $ok, $ko);

    // Fichier sans colonne Type : la référence suffit quand elle est seule…
    [, $a] = $api('adm', 'POST', 'import_stock', ['items' => [['_row' => 2, 'reference' => 'fil-120', 'quantite' => '9'],
                                                              ['_row' => 3, 'reference' => 'LUM-001', 'quantite' => '1']],
                                                 'champsMappes' => ['reference', 'quantite'], 'dryRun' => true]);
    $L = []; foreach (($a['lignes'] ?? []) as $l) $L[$l['row']] = $l;
    verifier('sans colonne Type : référence seule reconnue (casse ignorée), aucun doublon créé',
             ($L[2]['action'] ?? '') === 'maj' && ($a['aCreer'] ?? -1) === 0, json_encode([$L[2]['action'] ?? null, $a['aCreer'] ?? null]), $ok, $ko);
    // … et plusieurs articles sous la même référence : on ne choisit pas au hasard.
    verifier('plusieurs articles sous la référence, pas de type : erreur qui le dit',
             ($L[3]['action'] ?? '') === 'erreur' && str_contains(json_encode($L[3]['erreurs'] ?? [], JSON_UNESCAPED_UNICODE), 'précisez le type'),
             json_encode($L[3]['erreurs'] ?? [], JSON_UNESCAPED_UNICODE), $ok, $ko);

    // ── 4. Droits et garde-fous ───────────────────────────────────────────────
    echo "\n  Droits et garde-fous\n";
    foreach (['vis' => 'Visionneur', 'dem' => 'Demandeur'] as $l => $role) {
        $api('adm', 'POST', 'utilisateurs', ['nom' => $l, 'prenom' => 'E', 'login' => $l, 'motDePasse' => "Epreuve-$l-1", 'role' => $role, 'provider' => 'local']);
        $api($l, 'POST', 'login', ['login' => $l, 'password' => "Epreuve-$l-1"]);
    }
    [$cv] = $api('vis', 'POST', 'import_stock', $livraison + ['dryRun' => true]);
    [$cd] = $api('dem', 'POST', 'import_stock', $livraison + ['dryRun' => true]);
    verifier('visionneur et demandeur : refusés', $cv === 403 && $cd === 403, "HTTP $cv / $cd", $ok, $ko);
    [$cg] = $api('adm', 'GET', 'import_stock');
    [$cf, , $ef] = $api('adm', 'POST', 'import_stock', ['items' => 'pas une liste']);
    verifier('GET refusé ; corps invalide → 400 expliqué', $cg === 405 && $cf === 400 && $ef !== '', "HTTP $cg / $cf : $ef", $ok, $ko);
    // Une ligne forgée qui tente d'écrire une colonne hors liste : ignorée.
    $avant = $nb();
    [, $rf] = $api('adm', 'POST', 'import_stock', ['items' => [['reference' => 'X-1', 'designation' => '<img src=x onerror=alert(1)>', 'Id' => 1, 'CreatedBy' => 'pirate']],
                                                   'champsMappes' => ['reference', 'designation', 'Id', 'CreatedBy']]);
    $x = $art('X-1');
    verifier('colonnes hors liste ignorées ; texte gardé tel quel (échappé à l\'affichage)',
             ($rf['crees'] ?? 0) === 1 && $nb() === $avant + 1 && $x['CreatedBy'] === 'admin' && (int)$x['Id'] !== 1, json_encode([$x['Id'] ?? null, $x['CreatedBy'] ?? null]), $ok, $ko);
} catch (Throwable $e) {
    $ko[] = 'exception : ' . $e->getMessage();
    echo "  ❌  " . $e->getMessage() . "\n";
}

proc_terminate($proc); proc_close($proc);
$nettoyer();

$total = $ok + count($ko);
echo "\n───────────────────────────────────────────────────────────────────────\n";
if ($ko === []) {
    printf(" ✅ %d/%d — un fichier de stock entre dans Larka sans perte ni doublon.\n", $ok, $total);
    exit(0);
}
printf(" ❌ %d échec(s) sur %d :\n", count($ko), $total);
foreach ($ko as $t) echo "      • $t\n";
exit(1);

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
 * Larka — épreuve de l'import d'interventions (Excel / CSV)
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Ce qu'un fichier venu d'ailleurs (export d'une autre GMAO, tableau tenu à la
 * main) doit donner dans Larka :
 *   - les lectures : dates françaises, Excel et « 10 janvier 2026 », montants
 *     « 1 234,50 € », durées « 2h30 » ; une date impossible n'est jamais inventée ;
 *   - les noms usuels : « Préventif », « correctif », « VGP », « Terminée »,
 *     « à faire », « haute »… deviennent les valeurs de Larka ;
 *   - le rattachement : équipements (plusieurs par ligne), bien, contrat — et ce
 *     qui est introuvable est signalé, pas perdu ;
 *   - l'aperçu n'écrit rien, et annonce exactement ce que l'import fera ;
 *   - les doublons : un fichier réimporté ne crée rien ; un n° connu met à jour
 *     les seules colonnes du fichier (ou laisse la fiche, au choix) ;
 *   - les listes de choix : un type nouveau est proposé, décochable ; les
 *     natures Préventive / Contrôle réglementaire n'y sont jamais ajoutées ;
 *   - les préventives importées sont « hors plan » ; les terminées sont validées ;
 *   - les colonnes sans champ deviennent des cases des fiches (créées par
 *     l'import, remplies ligne à ligne ; une clé inventée ne crée rien) ;
 *   - les droits : visionneur et demandeur ne peuvent pas importer.
 *
 * Autonome : copie jetable de l'application, SQLite, serveur intégré.
 *
 *   php outils/epreuves/test-import-interventions.php
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
$dir = sys_get_temp_dir() . '/larka-impint-' . bin2hex(random_bytes(4));
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
echo " Larka — import d'interventions depuis Excel / CSV\n";
echo "═══════════════════════════════════════════════════════════════════════\n";

// ── 1. Lectures (sans serveur) ────────────────────────────────────────────────
echo "\n  Lectures des cellules\n";
require_once $source . '/api/ImportOutils.php';
verifier('dates : JJ/MM/AAAA, ISO, n° de série Excel, « 10 janvier 2026 »',
         ImportOutils::date('15/01/2026') === '2026-01-15' && ImportOutils::date('2026-01-15') === '2026-01-15'
         && ImportOutils::date(46037) === '2026-01-15' && ImportOutils::date('10 janvier 2026') === '2026-01-10',
         (string)ImportOutils::date(46037), $ok, $ko);
verifier('date impossible (31/02) : jamais inventée, signalée illisible',
         ImportOutils::date('31/02/2026') === null && ImportOutils::dateIllisible('31/02/2026') && !ImportOutils::dateIllisible(''),
         '', $ok, $ko);
verifier('montants : « 1 234,50 € », « 1.234,50 », vide = 0, texte = illisible',
         ImportOutils::nombre('1 234,50 €') === 1234.5 && ImportOutils::nombre('1.234,50') === 1234.5
         && ImportOutils::nombre('') === 0.0 && ImportOutils::nombre('sur devis') === null,
         var_export(ImportOutils::nombre('1 234,50 €'), true), $ok, $ko);
verifier('durées : « 2h30 », « 1:45 », « 90 min », « 1,5 »',
         ImportOutils::heures('2h30') === 2.5 && ImportOutils::heures('1:45') === 1.75
         && ImportOutils::heures('90 min') === 1.5 && ImportOutils::heures('1,5') === 1.5,
         json_encode([ImportOutils::heures('2h30'), ImportOutils::heures('1:45'), ImportOutils::heures('90 min')]), $ok, $ko);

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
    // Le parc : deux équipements, un bien, un contrat.
    $api('adm', 'POST', 'equipements', ['numero' => 'EQ-1', 'famille' => 'Chauffage']);
    $api('adm', 'POST', 'equipements', ['numero' => 'EQ-2', 'famille' => 'Chauffage']);
    $api('adm', 'POST', 'biens', ['numero' => 'BI-1']);
    $api('adm', 'POST', 'contrats', ['numero' => 'CT-1', 'societe' => 'ACME Thermique', 'type' => 'Maintenance',
         'dateDebut' => '2026-01-01', 'dateFin' => '2027-01-01']);
    $pdo = new PDO('sqlite:' . $dir . '/data/gmao.db');
    $idDe = fn(string $t, string $n) => (int)$pdo->query("SELECT Id FROM $t WHERE Numero = " . $pdo->quote($n))->fetchColumn();
    $eq1 = $idDe('Equipements', 'EQ-1'); $eq2 = $idDe('Equipements', 'EQ-2'); $bi1 = $idDe('Biens', 'BI-1'); $ct1 = $idDe('Contrats', 'CT-1');
    if (!$eq1 || !$eq2 || !$bi1 || !$ct1) throw new RuntimeException("parc d'essai non créé ($eq1/$eq2/$bi1/$ct1)");
    $nbInterv = fn() => (int)$pdo->query('SELECT COUNT(*) FROM Interventions')->fetchColumn();

    // Le fichier, tel qu'une autre GMAO l'exporte (valeurs déjà associées aux champs).
    $futur = date('d/m/Y', strtotime('+20 days'));
    $items = [
        ['_row' => 2, 'type' => 'Préventif', 'statut' => 'Terminée', 'priorite' => 'haute', 'description' => 'Entretien annuel chaudière',
         'actif' => 'EQ-1; EQ-2', 'contrat' => 'CT-1', 'dateRealisation' => '15/01/2026', 'dureeHeures' => '2h30', 'montantHT' => '1 234,50 €'],
        ['_row' => 3, 'type' => 'correctif', 'statut' => 'à faire', 'description' => 'Fuite sur la nourrice',
         'actif' => 'EQ-INCONNU', 'contrat' => 'Plomberie Durand', 'dateIntervention' => '31/02/2026'],
        ['_row' => 4, 'type' => 'VGP', 'description' => '', 'statut' => 'Planifiée'],
        ['_row' => 5, 'type' => 'Élagage', 'description' => 'Taille des haies', 'actif' => 'BI-1', 'dateRealisation' => '2026-03-01'],
        ['_row' => 6, 'type' => 'Préventif', 'statut' => 'Terminée', 'description' => 'Entretien annuel chaudière',
         'actif' => 'EQ-1; EQ-2', 'dateRealisation' => '15/01/2026'],
        ['_row' => 7, 'type' => 'VGP', 'statut' => 'zz', 'priorite' => 'Très très haute', 'description' => 'Vérification ascenseur',
         'dateIntervention' => $futur, 'agentEmail' => 'pas-un-courriel'],
    ];
    $mappes = ['type', 'statut', 'priorite', 'description', 'actif', 'contrat', 'dateRealisation', 'dateIntervention', 'dureeHeures', 'montantHT', 'agentEmail'];
    $corps = fn(array $plus = []) => array_merge(['items' => $items, 'champsMappes' => $mappes], $plus);

    // ── 2. Aperçu ─────────────────────────────────────────────────────────────
    echo "\n  Aperçu (rien n'est écrit)\n";
    $avant = $nbInterv();
    [$c, $a] = $api('adm', 'POST', 'import_interventions', $corps(['dryRun' => true]));
    $L = [];
    foreach (($a['lignes'] ?? []) as $l) $L[$l['row']] = $l;
    $champsAv = fn(int $r) => array_column($L[$r]['avert'] ?? [], 'champ');
    verifier('aperçu : 4 à créer, 1 doublon du fichier, 1 en erreur',
             $c === 200 && ($a['aCreer'] ?? -1) === 4 && ($a['doublons'] ?? -1) === 1 && ($a['enErreur'] ?? -1) === 1 && $a['total'] === 6,
             "HTTP $c " . json_encode(array_intersect_key((array)$a, array_flip(['aCreer', 'doublons', 'enErreur']))), $ok, $ko);
    verifier("l'aperçu n'a rien écrit", $nbInterv() === $avant, $nbInterv() . " / $avant", $ok, $ko);
    verifier('« Préventif » / « Terminée » → Préventive / Validée',
             ($L[2]['type'] ?? '') === 'Préventive' && ($L[2]['statut'] ?? '') === 'Validée', ($L[2]['type'] ?? '?') . ' / ' . ($L[2]['statut'] ?? '?'), $ok, $ko);
    verifier('« correctif » / « à faire » → Curative / Planifiée ; « VGP » → Contrôle réglementaire',
             ($L[3]['type'] ?? '') === 'Curative' && ($L[3]['statut'] ?? '') === 'Planifiée' && ($L[7]['type'] ?? '') === 'Contrôle réglementaire',
             '', $ok, $ko);
    verifier('description vide : ligne en erreur, ignorée', ($L[4]['action'] ?? '') === 'erreur', json_encode($L[4]['erreurs'] ?? []), $ok, $ko);
    verifier('ligne identique plus haut dans le fichier : doublon ignoré', ($L[6]['action'] ?? '') === 'doublon', '', $ok, $ko);
    verifier('équipement introuvable, date impossible, contrat inconnu : signalés',
             count(array_filter($L[3]['avert'] ?? [], fn($e) => $e['valeur'] === 'EQ-INCONNU' || $e['valeur'] === '31/02/2026' || $e['valeur'] === 'Plomberie Durand')) === 3,
             json_encode(array_column($L[3]['avert'] ?? [], 'valeur')), $ok, $ko);
    verifier('statut inconnu, date prévue future réglementaire OK, courriel invalide : signalés',
             count($L[7]['avert'] ?? []) >= 2 && ($L[7]['statut'] ?? '') === 'Planifiée', json_encode($champsAv(7)), $ok, $ko);
    $lc = $a['listesACreer'] ?? [];
    verifier('listes proposées : le type « Élagage », la priorité nouvelle ; jamais une nature',
             in_array('Élagage', $lc['TypeIntervention'] ?? [], true) && in_array('Très très haute', $lc['PrioriteIntervention'] ?? [], true)
             && !array_intersect(['Préventive', 'Contrôle réglementaire'], $lc['TypeIntervention'] ?? []),
             json_encode($lc, JSON_UNESCAPED_UNICODE), $ok, $ko);
    verifier('répartition par type annoncée', ($a['parType']['Préventive'] ?? 0) === 1 && ($a['parType']['Contrôle réglementaire'] ?? 0) === 1,
             json_encode($a['parType'] ?? [], JSON_UNESCAPED_UNICODE), $ok, $ko);

    // ── 3. Import ─────────────────────────────────────────────────────────────
    echo "\n  Import\n";
    [$c, $r] = $api('adm', 'POST', 'import_interventions', $corps(['listesNePasCreer' => ['PrioriteIntervention' => ['Très très haute']]]));
    verifier('import : 4 créées, aucun échec', $c === 200 && ($r['crees'] ?? 0) === 4 && !($r['echecs'] ?? [1]),
             "HTTP $c " . json_encode($r['echecs'] ?? $r, JSON_UNESCAPED_UNICODE), $ok, $ko);
    $ids = array_map('intval', (array)($r['ids'] ?? []));
    $iv = [];
    foreach ($pdo->query('SELECT * FROM Interventions WHERE Id IN (' . implode(',', $ids ?: [0]) . ')')->fetchAll(PDO::FETCH_ASSOC) as $x) $iv[$x['Description']] = $x;
    $ch = $iv['Entretien annuel chaudière'] ?? [];
    verifier('préventive : deux équipements, contrat, montant, durée, priorité',
             ($ch['EquipementsIds'] ?? '') === "$eq1,$eq2" && (int)($ch['ContratId'] ?? 0) === $ct1 && (float)($ch['MontantHT'] ?? 0) === 1234.5
             && (float)($ch['DureeHeures'] ?? 0) === 2.5 && ($ch['Priorite'] ?? '') === 'Urgente',
             json_encode(array_intersect_key($ch, array_flip(['EquipementsIds', 'ContratId', 'MontantHT', 'DureeHeures', 'Priorite']))), $ok, $ko);
    verifier('terminée : validée à sa date de réalisation, hors plan, numérotée',
             ($ch['Statut'] ?? '') === 'Validée' && substr((string)($ch['DateValidation'] ?? ''), 0, 10) === '2026-01-15'
             && empty($ch['PlanId']) && preg_match('/^INT-\d{6}-\d{4}$/', (string)($ch['Numero'] ?? '')),
             ($ch['Numero'] ?? '') . ' ' . ($ch['DateValidation'] ?? ''), $ok, $ko);
    $fu = $iv['Fuite sur la nourrice'] ?? [];
    verifier('contrat inconnu gardé comme prestataire ; date impossible laissée vide',
             ($fu['SocieteManuelle'] ?? '') === 'Plomberie Durand' && empty($fu['ContratId']) && empty($fu['DateIntervention']),
             json_encode(array_intersect_key($fu, array_flip(['SocieteManuelle', 'DateIntervention'])), JSON_UNESCAPED_UNICODE), $ok, $ko);
    $el = $iv['Taille des haies'] ?? [];
    verifier('bien rattaché ; sans statut + réalisée → Réalisée',
             ($el['TypeLiaison'] ?? '') === 'Bien' && (int)($el['BienId'] ?? 0) === $bi1 && ($el['Statut'] ?? '') === 'Réalisée',
             ($el['TypeLiaison'] ?? '') . ' ' . ($el['Statut'] ?? ''), $ok, $ko);
    [, $types] = $api('adm', 'GET', 'listes&categorie=TypeIntervention');
    [, $prios] = $api('adm', 'GET', 'listes&categorie=PrioriteIntervention');
    $vt = array_column((array)$types, 'Valeur'); $vp = array_column((array)$prios, 'Valeur');
    verifier('liste des types : « Élagage » ajouté ; la priorité décochée ne l\'est pas ; aucune nature',
             in_array('Élagage', $vt, true) && !in_array('Très très haute', $vp, true) && !in_array('Préventive', $vt, true),
             implode(', ', $vt), $ok, $ko);
    [, $tout] = $api('adm', 'GET', 'interventions');
    $hp = array_filter((array)$tout, fn($x) => ($x['Type'] ?? '') === 'Préventive' && empty($x['PlanId']));
    verifier('la préventive importée figure dans « Hors plan »', count($hp) === 1, count($hp) . ' hors plan', $ok, $ko);
    $journal = (int)$pdo->query("SELECT COUNT(*) FROM Journal WHERE Message LIKE '%Import d''interventions%'")->fetchColumn();
    verifier("l'import est inscrit au Journal", $journal >= 1, "$journal entrée(s)", $ok, $ko);

    // ── 4. Réimport, doublons, mises à jour ───────────────────────────────────
    echo "\n  Réimport et mises à jour\n";
    [, $a2] = $api('adm', 'POST', 'import_interventions', $corps(['dryRun' => true]));
    verifier('le même fichier réimporté : rien à créer', ($a2['aCreer'] ?? -1) === 0 && ($a2['doublons'] ?? 0) === 5,
             json_encode(array_intersect_key((array)$a2, array_flip(['aCreer', 'doublons', 'enErreur']))), $ok, $ko);
    [, $a3] = $api('adm', 'POST', 'import_interventions', $corps(['dryRun' => true, 'doublons' => 'importer']));
    verifier('« importer quand même » : les doublons redeviennent à créer', ($a3['aCreer'] ?? 0) === 5, (string)($a3['aCreer'] ?? '?'), $ok, $ko);

    $num = (string)($ch['Numero'] ?? '');
    $maj = ['items' => [['_row' => 2, 'numero' => $num, 'description' => 'Entretien annuel chaudière (révisé)', 'commentaire' => 'Ramonage fait']],
            'champsMappes' => ['numero', 'description', 'commentaire']];
    [, $a4] = $api('adm', 'POST', 'import_interventions', $maj + ['dryRun' => true, 'dedup' => 'skip']);
    verifier('n° connu, « laisser la fiche » : ligne laissée', ($a4['lignes'][0]['action'] ?? '') === 'laisse', '', $ok, $ko);
    [$c, $r4] = $api('adm', 'POST', 'import_interventions', $maj + ['dedup' => 'update']);
    $apres = $pdo->query('SELECT * FROM Interventions WHERE Id = ' . (int)$ch['Id'])->fetch(PDO::FETCH_ASSOC);
    verifier('n° connu : mise à jour des seules colonnes du fichier',
             ($r4['misesAJour'] ?? 0) === 1 && $apres['Description'] === 'Entretien annuel chaudière (révisé)' && $apres['Commentaire'] === 'Ramonage fait'
             && (float)$apres['MontantHT'] === 1234.5 && $apres['EquipementsIds'] === "$eq1,$eq2" && $apres['Statut'] === 'Validée' && $apres['Type'] === 'Préventive',
             json_encode(array_intersect_key($apres, array_flip(['MontantHT', 'EquipementsIds', 'Statut']))), $ok, $ko);
    [, $a5] = $api('adm', 'POST', 'import_interventions', ['items' => [['numero' => 'X-1', 'description' => 'a'], ['numero' => 'x-1', 'description' => 'b']],
                                                           'dryRun' => true]);
    verifier('même n° deux fois dans le fichier : la seconde en erreur',
             ($a5['lignes'][0]['action'] ?? '') === 'creer' && ($a5['lignes'][1]['action'] ?? '') === 'erreur', '', $ok, $ko);

    // ── 4 bis. Lieu / bâtiment ────────────────────────────────────────────────
    echo "\n  Lieu / bâtiment\n";
    $planning = fn(array $extra = []) => ['items' => [
        ['_row' => 2, 'description' => 'Extincteurs', 'type' => 'Contrôle réglementaire', 'dateIntervention' => '2025-03-01', 'lieu' => 'Bordeaux'],
        ['_row' => 3, 'description' => 'Extincteurs', 'type' => 'Contrôle réglementaire', 'dateIntervention' => '2025-03-01', 'lieu' => 'Pau'],
      ], 'champsMappes' => ['description', 'type', 'dateIntervention', 'lieu']] + $extra;
    [, $l1] = $api('adm', 'POST', 'import_interventions', $planning());
    $lieux = $pdo->query("SELECT Lieu FROM Interventions WHERE Description = 'Extincteurs' ORDER BY Lieu")->fetchAll(PDO::FETCH_COLUMN);
    verifier('même contrôle, même jour, deux lieux : deux passages, chacun son lieu',
             ($l1['crees'] ?? 0) === 2 && $lieux === ['Bordeaux', 'Pau'], json_encode($lieux), $ok, $ko);
    [, $l2] = $api('adm', 'POST', 'import_interventions', $planning(['dryRun' => true]));
    verifier('réimporté : doublons (même lieu)', ($l2['doublons'] ?? 0) === 2 && ($l2['aCreer'] ?? -1) === 0, json_encode([$l2['doublons'] ?? null, $l2['aCreer'] ?? null]), $ok, $ko);
    // Ancien import : le site collé à la description, sans lieu.
    $pdo->exec("INSERT INTO Interventions (Numero, Type, Description, DateIntervention, Statut, EquipementsIds) VALUES
                ('INT-ANC-1', 'Contrôle réglementaire', 'Baes — Pau', '2025-04-02', 'Planifiée', '')");
    [, $l3] = $api('adm', 'POST', 'import_interventions', ['items' => [['_row' => 2, 'description' => 'Baes', 'type' => 'Contrôle réglementaire',
        'dateIntervention' => '2025-04-02', 'lieu' => 'Pau']], 'champsMappes' => ['description', 'type', 'dateIntervention', 'lieu']]);
    $anc = $pdo->query("SELECT Description, Lieu FROM Interventions WHERE Numero = 'INT-ANC-1'")->fetch(PDO::FETCH_ASSOC);
    verifier('passage d\'un ancien import (« Baes — Pau ») : lieu renseigné, description propre, pas de doublon',
             ($l3['crees'] ?? -1) === 0 && ($l3['misesAJour'] ?? 0) === 1 && $anc['Lieu'] === 'Pau' && $anc['Description'] === 'Baes',
             json_encode([$anc, $l3['crees'] ?? null]), $ok, $ko);
    // Fiche sans lieu, et le fichier donne le même passage à DEUX lieux : l'un la complète, l'autre est créé.
    $pdo->exec("INSERT INTO Interventions (Numero, Type, Description, DateIntervention, Statut, EquipementsIds) VALUES
                ('INT-ANC-2', 'Contrôle réglementaire', 'Désenfumage', '2025-05-03', 'Planifiée', '')");
    [, $l4] = $api('adm', 'POST', 'import_interventions', ['items' => [
        ['_row' => 2, 'description' => 'Désenfumage', 'type' => 'Contrôle réglementaire', 'dateIntervention' => '2025-05-03', 'lieu' => 'Bordeaux'],
        ['_row' => 3, 'description' => 'Désenfumage', 'type' => 'Contrôle réglementaire', 'dateIntervention' => '2025-05-03', 'lieu' => 'Pau'],
      ], 'champsMappes' => ['description', 'type', 'dateIntervention', 'lieu']]);
    $lx = $pdo->query("SELECT Lieu FROM Interventions WHERE Description = 'Désenfumage' ORDER BY Lieu")->fetchAll(PDO::FETCH_COLUMN);
    verifier('fiche sans lieu, deux lieux dans le fichier : l\'un la complète, l\'autre est créé',
             ($l4['crees'] ?? 0) === 1 && ($l4['misesAJour'] ?? 0) === 1 && $lx === ['Bordeaux', 'Pau'], json_encode([$lx, $l4['crees'] ?? null]), $ok, $ko);
    $pdo->exec("DELETE FROM Interventions WHERE Description IN ('Extincteurs', 'Baes', 'Désenfumage')");

    // ── 4 bis. Cases complémentaires des fiches ───────────────────────────────
    // Une colonne du fichier sans champ d'intervention devient une case des
    // fiches, comme à l'import des biens : créée par l'import, remplie ligne à
    // ligne ; une clé que le client invente ne crée rien.
    echo "\n  Cases complémentaires des fiches\n";
    $itemsCp = [
        ['_row' => 2, 'description' => 'Remplacement vanne 3 voies', 'type' => 'Curative', 'champsPerso' => ['n_de_marche' => 'M-2026-12', 'ref_client' => 'RC-7']],
        ['_row' => 3, 'description' => 'Contrôle du débit', 'type' => 'Curative', 'champsPerso' => ['n_de_marche' => 'M-2026-13', 'cle_inventee' => 'x', '../x' => 'y']],
    ];
    $nouv = [['cle' => 'n_de_marche', 'libelle' => 'N° de marché', 'type' => 'texte'], ['cle' => 'ref_client', 'libelle' => 'Réf. client', 'type' => 'texte'],
             ['cle' => 'Pas Une Clé', 'libelle' => 'Refusée', 'type' => 'texte']];
    [$c, $a] = $api('adm', 'POST', 'import_interventions', ['items' => $itemsCp, 'champsMappes' => ['description', 'type'], 'champsPersoNouveaux' => $nouv, 'dryRun' => true]);
    [, $defs0] = $api('adm', 'GET', 'champs_perso&cible=interventions');
    verifier("aperçu : les deux cases annoncées, aucune créée encore (clé invalide écartée)",
             $c === 200 && ($a['casesACreer'] ?? null) === ['N° de marché', 'Réf. client'] && $defs0 === [],
             json_encode([$a['casesACreer'] ?? null, $defs0]), $ok, $ko);
    [$c, $r] = $api('adm', 'POST', 'import_interventions', ['items' => $itemsCp, 'champsMappes' => ['description', 'type'], 'champsPersoNouveaux' => $nouv]);
    [, $defs] = $api('adm', 'GET', 'champs_perso&cible=interventions');
    verifier('import : deux cases créées dans les fiches d\'intervention',
             ($r['casesCreees'] ?? 0) === 2 && array_column((array)$defs, 'cle') === ['n_de_marche', 'ref_client'],
             json_encode([$r['casesCreees'] ?? null, array_column((array)$defs, 'cle')]), $ok, $ko);
    $cpDe = fn(string $desc) => json_decode((string)$pdo->query("SELECT ChampsPerso FROM Interventions WHERE Description = " . $pdo->quote($desc))->fetchColumn(), true);
    verifier('valeurs rangées dans la fiche ; la clé inventée et la clé malformée ne créent rien',
             $cpDe('Remplacement vanne 3 voies') === ['n_de_marche' => 'M-2026-12', 'ref_client' => 'RC-7']
             && $cpDe('Contrôle du débit') === ['n_de_marche' => 'M-2026-13'],
             json_encode([$cpDe('Remplacement vanne 3 voies'), $cpDe('Contrôle du débit')]), $ok, $ko);
    // La fiche (saisie) garde les valeurs qu'elle ne montre pas, et efface celle qu'on vide.
    $idv = (int)$pdo->query("SELECT Id FROM Interventions WHERE Description = 'Contrôle du débit'")->fetchColumn();
    $rowv = $pdo->query("SELECT * FROM Interventions WHERE Id = $idv")->fetch(PDO::FETCH_ASSOC);
    $api('adm', 'PUT', "interventions&id=$idv", ['description' => 'Contrôle du débit', 'type' => 'Curative', 'statut' => $rowv['Statut'],
         'numero' => $rowv['Numero'], 'champsPerso' => ['ref_client' => 'RC-9', 'n_de_marche' => '']]);
    verifier('fiche : une case remplie s\'ajoute, une case vidée s\'efface', $cpDe('Contrôle du débit') === ['ref_client' => 'RC-9'],
             json_encode($cpDe('Contrôle du débit')), $ok, $ko);
    [$cz] = $api('adm', 'GET', 'champs_perso&cible=zzz');
    [$cvis] = $api('vis', 'POST', 'champs_perso', ['cible' => 'interventions', 'champs' => []]);
    verifier('cible inconnue refusée ; un visionneur ne gère pas les cases', $cz === 400 && $cvis === 403, "HTTP $cz / $cvis", $ok, $ko);

    // ── 5. Droits et garde-fous ───────────────────────────────────────────────
    echo "\n  Droits et garde-fous\n";
    [$cv] = $api('vis', 'POST', 'import_interventions', $corps(['dryRun' => true]));
    [$cd] = $api('dem', 'POST', 'import_interventions', $corps(['dryRun' => true]));
    verifier('visionneur et demandeur : refusés', $cv === 403 && $cd === 403, "HTTP $cv / $cd", $ok, $ko);
    [$cg] = $api('adm', 'GET', 'import_interventions');
    [$cf, , $ef] = $api('adm', 'POST', 'import_interventions', ['items' => 'pas une liste']);
    verifier('GET refusé ; corps invalide → 400 expliqué', $cg === 405 && $cf === 400 && $ef !== '', "HTTP $cg / $cf : $ef", $ok, $ko);
    $avant = $nbInterv();
    [, $r6] = $api('adm', 'POST', 'import_interventions', ['items' => [['description' => '<script>alert(1)</script>Nettoyage', 'type' => 'Divers']],
                                                           'champsMappes' => ['description', 'type']]);
    verifier('le texte importé est gardé tel quel (échappé à l\'affichage), une seule fiche',
             ($r6['crees'] ?? 0) === 1 && $nbInterv() === $avant + 1, '', $ok, $ko);
} catch (Throwable $e) {
    $ko[] = 'exception : ' . $e->getMessage();
    echo "  ❌  " . $e->getMessage() . "\n";
}

proc_terminate($proc); proc_close($proc);
$nettoyer();

$total = $ok + count($ko);
echo "\n───────────────────────────────────────────────────────────────────────\n";
if ($ko === []) {
    printf(" ✅ %d/%d — un fichier d'interventions entre dans Larka sans perte ni doublon.\n", $ok, $total);
    exit(0);
}
printf(" ❌ %d échec(s) sur %d :\n", count($ko), $total);
foreach ($ko as $t) echo "      • $t\n";
exit(1);

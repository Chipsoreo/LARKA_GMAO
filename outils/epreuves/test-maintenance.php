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
 * Larka — épreuve des plans de maintenance (préventif, contrôle réglementaire)
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Un plan prévoit plusieurs passages du même genre dans l'année ; chaque
 * passage est une intervention. L'épreuve vérifie :
 *   - la création des passages, leurs dates, leur type ;
 *   - les trois façons de compter le prix : par passage, prix unique réparti
 *     (centimes compris, passages terminés intacts), forfait porté par le plan ;
 *   - la modification des dates : passage ajouté, déplacé, retiré — jamais un
 *     passage engagé ;
 *   - la suppression : les passages à venir partent, les autres restent ;
 *   - les droits : un visionneur lit, n'écrit pas ; un demandeur ne lit pas ;
 *   - les documents du plan : joints, comptés, protégés, supprimés avec lui ;
 *   - Préventive et Contrôle réglementaire ont quitté la liste des types ;
 *   - le tableau de bord n'affiche pas toute l'année d'un plan.
 *
 * Autonome : copie jetable de l'application, SQLite, serveur intégré.
 *
 *   php outils/epreuves/test-maintenance.php
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
$dir = sys_get_temp_dir() . '/larka-maint-' . bin2hex(random_bytes(4));
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
echo " Larka — plans préventifs et de contrôle réglementaire\n";
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
    $an = (int)date('Y');

    // ── Types d'intervention ──────────────────────────────────────────────────
    [, $types] = $api('adm', 'GET', 'listes&categorie=TypeIntervention');
    $valeurs = array_map(fn($x) => $x['Valeur'] ?? '', is_array($types) ? $types : []);
    verifier('listes : Préventive et Contrôle réglementaire retirés des types',
             $valeurs !== [] && !in_array('Préventive', $valeurs, true) && !in_array('Contrôle réglementaire', $valeurs, true),
             implode(', ', $valeurs), $ok, $ko);

    // ── Prix par passage ──────────────────────────────────────────────────────
    $dates = ["$an-01-15", "$an-04-15", "$an-07-15", "$an-10-15"];
    [$c, $r] = $api('adm', 'POST', 'plans_maintenance', ['nature' => 'Préventive', 'titre' => 'Entretien CTA', 'annee' => $an,
        'periodicite' => 'Trimestrielle', 'societeManuelle' => 'Clim+', 'modePrix' => 'passage', 'prixPassage' => 250,
        'passages' => $dates]);
    $p1 = (int)($r['id'] ?? 0);
    [, $plan1] = $api('adm', 'GET', "plans_maintenance&id=$p1");
    $pas = $plan1['Passages'] ?? [];
    verifier('plan préventif : 4 passages créés aux dates prévues',
             $c === 200 && count($pas) === 4 && array_column($pas, 'DateIntervention') === $dates, "HTTP $c", $ok, $ko);
    verifier('prix par passage : 250 € chacun, plan à 1 000 €',
             array_map('floatval', array_column($pas, 'MontantHT')) === [250.0, 250.0, 250.0, 250.0]
             && (float)($plan1['CoutTotal'] ?? 0) === 1000.0, json_encode(array_column($pas, 'MontantHT')), $ok, $ko);
    verifier('numéro du plan : PRV-année-0001', ($plan1['Numero'] ?? '') === "PRV-$an-0001", (string)($plan1['Numero'] ?? ''), $ok, $ko);

    [, $tout] = $api('adm', 'GET', 'interventions');
    $iv = array_values(array_filter($tout, fn($x) => (int)($x['PlanId'] ?? 0) === $p1));
    verifier('passages = interventions de type Préventive, rattachées au plan',
             count($iv) === 4 && count(array_filter($iv, fn($x) => $x['Type'] === 'Préventive' && $x['Statut'] === 'Planifiée'
                 && $x['SocieteManuelle'] === 'Clim+' && str_starts_with((string)$x['Description'], 'Entretien CTA'))) === 4,
             count($iv) . ' passage(s)', $ok, $ko);

    // ── Prix unique réparti, centimes compris ────────────────────────────────
    [$c, $r] = $api('adm', 'POST', 'plans_maintenance', ['nature' => 'Contrôle réglementaire', 'titre' => 'Vérification ascenseur',
        'annee' => $an, 'modePrix' => 'reparti', 'prixGlobal' => 1000, 'passages' => ["$an-02-01", "$an-06-01", "$an-11-01"]]);
    $p2 = (int)($r['id'] ?? 0);
    [, $plan2] = $api('adm', 'GET', "plans_maintenance&id=$p2");
    $m2 = array_map('floatval', array_column($plan2['Passages'] ?? [], 'MontantHT'));
    verifier('prix unique réparti : 333,33 + 333,33 + 333,34 = 1 000 €',
             $m2 === [333.33, 333.33, 333.34] && (float)$plan2['CoutTotal'] === 1000.0, json_encode($m2), $ok, $ko);
    verifier('numéro du contrôle : CRT-année-0001', ($plan2['Numero'] ?? '') === "CRT-$an-0001", (string)($plan2['Numero'] ?? ''), $ok, $ko);

    // Un passage terminé garde son prix ; le reste se partage entre les autres.
    $fait = (int)($plan2['Passages'][0]['Id'] ?? 0);
    $api('adm', 'POST', 'realiser_prevue&id=' . $fait);
    $corps2 = ['titre' => 'Vérification ascenseur', 'annee' => $an, 'modePrix' => 'reparti', 'prixGlobal' => 1200,
               'passages' => array_map(fn($x) => ['id' => (int)$x['Id'], 'date' => $x['DateIntervention']], $plan2['Passages'])];
    [$c] = $api('adm', 'PUT', "plans_maintenance&id=$p2", $corps2);
    [, $plan2] = $api('adm', 'GET', "plans_maintenance&id=$p2");
    $m2 = array_map('floatval', array_column($plan2['Passages'] ?? [], 'MontantHT'));
    verifier('prix porté à 1 200 € : le passage fait garde 333,33, le reste se partage',
             $c === 200 && $m2 === [333.33, 433.33, 433.34] && (float)$plan2['CoutTotal'] === 1200.0, json_encode($m2), $ok, $ko);

    // ── Forfait ───────────────────────────────────────────────────────────────
    [$c] = $api('adm', 'PUT', "plans_maintenance&id=$p1", ['titre' => 'Entretien CTA', 'annee' => $an, 'modePrix' => 'forfait',
        'prixGlobal' => 900, 'societeManuelle' => 'Clim+']);
    [, $plan1] = $api('adm', 'GET', "plans_maintenance&id=$p1");
    verifier('forfait : passages à 0 €, le plan porte 900 €',
             $c === 200 && array_sum(array_map('floatval', array_column($plan1['Passages'], 'MontantHT'))) == 0
             && (float)$plan1['CoutTotal'] === 900.0 && count($plan1['Passages']) === 4, (string)($plan1['CoutTotal'] ?? ''), $ok, $ko);

    // ── Dates : ajouter, déplacer, retirer ; un passage engagé ne bouge pas ──
    $pa = $plan1['Passages'];
    // Passage n°1 commencé (« En cours »), comme depuis sa fiche.
    $api('adm', 'PUT', 'interventions&id=' . (int)$pa[0]['Id'], ['type' => 'Préventive', 'statut' => 'En cours',
        'description' => 'Entretien CTA', 'societeManuelle' => 'Clim+', 'dateIntervention' => $pa[0]['DateIntervention']]);
    $liste = [
        ['id' => (int)$pa[0]['Id'], 'date' => "$an-01-20"],      // engagé : la date ne doit pas changer
        ['id' => (int)$pa[1]['Id'], 'date' => "$an-05-02"],      // déplacé
        // pa[2] retiré, pa[3] retiré
        ['date' => "$an-12-01"],                                  // ajouté
    ];
    [$c] = $api('adm', 'PUT', "plans_maintenance&id=$p1", ['titre' => 'Entretien CTA', 'annee' => $an, 'modePrix' => 'forfait',
        'prixGlobal' => 900, 'societeManuelle' => 'Clim+', 'passages' => $liste]);
    [, $plan1] = $api('adm', 'GET', "plans_maintenance&id=$p1");
    $d1 = array_column($plan1['Passages'], 'DateIntervention');
    verifier('dates : un ajouté, un déplacé, deux retirés ; le passage en cours intact',
             $c === 200 && $d1 === ["$an-01-15", "$an-05-02", "$an-12-01"]
             && ($plan1['Passages'][0]['Statut'] ?? '') === 'En cours', json_encode($d1), $ok, $ko);

    // Retirer le passage engagé de la liste : il reste.
    [$c] = $api('adm', 'PUT', "plans_maintenance&id=$p1", ['titre' => 'Entretien CTA', 'annee' => $an, 'modePrix' => 'forfait',
        'prixGlobal' => 900, 'passages' => [['date' => "$an-06-06"]]]);
    [, $plan1] = $api('adm', 'GET', "plans_maintenance&id=$p1");
    verifier('un passage engagé n\'est jamais supprimé par le plan',
             in_array((int)$pa[0]['Id'], array_map('intval', array_column($plan1['Passages'], 'Id')), true)
             && count($plan1['Passages']) === 2, count($plan1['Passages']) . ' passage(s)', $ok, $ko);

    // ── Refus ─────────────────────────────────────────────────────────────────
    [$c1] = $api('adm', 'POST', 'plans_maintenance', ['nature' => 'Curative', 'titre' => 'X', 'passages' => ["$an-01-01"]]);
    [$c2] = $api('adm', 'POST', 'plans_maintenance', ['nature' => 'Préventive', 'titre' => 'X', 'passages' => []]);
    [$c3] = $api('adm', 'POST', 'plans_maintenance', ['nature' => 'Préventive', 'titre' => 'X', 'passages' => ["$an-02-30"]]);
    [$c4] = $api('adm', 'POST', 'plans_maintenance', ['nature' => 'Préventive', 'titre' => '', 'passages' => ["$an-02-01"]]);
    verifier('refusés : nature inconnue, aucun passage, date impossible, sans titre',
             [$c1, $c2, $c3, $c4] === [400, 400, 400, 400], "$c1 $c2 $c3 $c4", $ok, $ko);

    // ── Droits ────────────────────────────────────────────────────────────────
    [$cv] = $api('vis', 'GET', 'plans_maintenance&nature=' . rawurlencode('Préventive'));
    [$cvw] = $api('vis', 'POST', 'plans_maintenance', ['nature' => 'Préventive', 'titre' => 'X', 'passages' => ["$an-01-01"]]);
    [$cvd] = $api('vis', 'DELETE', "plans_maintenance&id=$p1");
    [$cd] = $api('dem', 'GET', 'plans_maintenance');
    verifier('visionneur : lit, ne crée ni ne supprime ; demandeur : refusé',
             $cv === 200 && $cvw === 403 && $cvd === 403 && $cd === 403, "$cv $cvw $cvd $cd", $ok, $ko);

    // ── Filtre par nature ─────────────────────────────────────────────────────
    [, $prev] = $api('adm', 'GET', 'plans_maintenance&nature=' . rawurlencode('Préventive'));
    [, $ctrl] = $api('adm', 'GET', 'plans_maintenance&nature=' . rawurlencode('Contrôle réglementaire'));
    verifier('liste par nature : chaque écran ne voit que ses plans',
             count($prev) === 1 && count($ctrl) === 1 && (int)$prev[0]['Id'] === $p1 && (int)$ctrl[0]['Id'] === $p2,
             count($prev) . ' / ' . count($ctrl), $ok, $ko);

    // ── Tableau de bord ───────────────────────────────────────────────────────
    $loin = date('Y-m-d', strtotime('+200 days'));
    $proche = date('Y-m-d', strtotime('+10 days'));
    [, $r] = $api('adm', 'POST', 'plans_maintenance', ['nature' => 'Préventive', 'titre' => 'Graissage pompes', 'annee' => (int)date('Y'),
        'modePrix' => 'passage', 'prixPassage' => 80, 'delaiAffichage' => 30, 'passages' => [$proche, $loin]]);
    $p3 = (int)($r['id'] ?? 0);
    [, $prevues] = $api('adm', 'GET', 'interventions_prevues');
    $dp3 = array_column(array_filter($prevues, fn($x) => (int)($x['PlanId'] ?? 0) === $p3), 'DateIntervention');
    verifier('tableau de bord : le passage proche s\'affiche, pas celui dans 200 jours',
             $dp3 === [$proche], json_encode(array_values($dp3)), $ok, $ko);

    // ── Documents du plan ─────────────────────────────────────────────────────
    $pdf = base64_encode("%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
    [$cu] = $api('adm', 'POST', "documents&type=PlanMaintenance&eid=$p1", ['nom' => 'gamme-cta.pdf', 'mime' => 'application/pdf', 'categorie' => 'pdf', 'data' => $pdf]);
    [$cl, $docs] = $api('vis', 'GET', "documents&type=PlanMaintenance&eid=$p1");
    [$cvu] = $api('vis', 'POST', "documents&type=PlanMaintenance&eid=$p1", ['nom' => 'x.pdf', 'mime' => 'application/pdf', 'categorie' => 'pdf', 'data' => $pdf]);
    [$cdl] = $api('dem', 'GET', "documents&type=PlanMaintenance&eid=$p1");
    [, $prevDoc] = $api('adm', 'GET', 'plans_maintenance&nature=' . rawurlencode('Préventive'));
    $nbDoc = (int)(array_values(array_filter($prevDoc, fn($x) => (int)$x['Id'] === $p1))[0]['NbDocuments'] ?? 0);
    verifier('documents du plan : joints, lus par un visionneur, comptés sur la carte',
             $cu === 200 && $cl === 200 && count($docs) === 1 && $nbDoc === 1, "$cu $cl " . count((array)$docs) . " nb=$nbDoc", $ok, $ko);
    verifier('documents du plan : visionneur n\'écrit pas, demandeur ne lit pas', $cvu === 403 && in_array($cdl, [403, 404], true), "$cvu $cdl", $ok, $ko);
    $docId = (int)($docs[0]['Id'] ?? 0);

    // ── Suppression ───────────────────────────────────────────────────────────
    [$c, $r] = $api('adm', 'DELETE', "plans_maintenance&id=$p1");
    [, $tout] = $api('adm', 'GET', 'interventions');
    $restant = array_values(array_filter($tout, fn($x) => (int)$x['Id'] === (int)$pa[0]['Id']));
    verifier('suppression : passage à venir supprimé, passage en cours gardé (détaché)',
             $c === 200 && ($r['supprimes'] ?? -1) === 1 && ($r['gardes'] ?? -1) === 1
             && count($restant) === 1 && empty($restant[0]['PlanId']), json_encode($r), $ok, $ko);
    [$c] = $api('adm', 'GET', "plans_maintenance&id=$p1");
    verifier('le plan supprimé n\'existe plus', $c === 404, "HTTP $c", $ok, $ko);
    $resteDoc = (int)(new PDO('sqlite:' . $dir . '/data/gmao.db'))->query("SELECT COUNT(*) FROM Documents WHERE Id = $docId")->fetchColumn();
    verifier('ses documents partent avec lui', $docId > 0 && $resteDoc === 0, "doc $docId : $resteDoc", $ok, $ko);

    // ── Historique : un passage archivé garde son type ───────────────────────
    [, $arch] = $api('adm', 'GET', 'interventions_archivees');
    $a2 = array_values(array_filter($arch, fn($x) => (int)$x['Id'] === $fait));
    verifier('historique : le contrôle archivé garde son type et son plan',
             count($a2) === 1 && $a2[0]['Type'] === 'Contrôle réglementaire' && (int)$a2[0]['PlanId'] === $p2, '', $ok, $ko);
} catch (Throwable $e) {
    $ko[] = 'exception : ' . $e->getMessage();
    echo "  ❌  " . $e->getMessage() . "\n";
}

proc_terminate($proc); proc_close($proc);
$nettoyer();

$total = $ok + count($ko);
echo "\n───────────────────────────────────────────────────────────────────────\n";
if ($ko === []) {
    printf(" ✅ %d/%d — les plans prévoient l'année, et ne touchent pas au travail engagé.\n", $ok, $total);
} else {
    printf(" ❌ %d échec(s) sur %d :\n", count($ko), $total);
    foreach ($ko as $t) echo "      • $t\n";
}
echo "───────────────────────────────────────────────────────────────────────\n\n";
exit($ko === [] ? 0 : 1);

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
 * Larka — épreuve de la SYNCHRONISATION de l'app mobile (api/Synchro.php)
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Aucune épreuve ne gardait ces routes, et l'audit de la 3.0.0 y a trouvé :
 *
 *   CRITIQUE   un demandeur réécrivait le mot de passe de n'importe quel compte
 *              (la règle lisait l'« Id » envoyé, pas la clé visée) et s'y
 *              connectait ; il s'appropriait aussi la demande d'autrui ;
 *   IMPORTANT  tout compte relié lisait TOUTE la base (contrats, factures, comptes,
 *              Connect…), et les médias d'urgence réservés aux gestionnaires ;
 *              en multi-tenant, les fichiers synchronisés étaient ceux d'une autre
 *              organisation (celle de l'adresse, pas celle du jeton) ;
 *   FAIBLE     ligne du serveur rendue dans les refus, réglages de sécurité et
 *              jetons des PC releveurs réécrits sans identité confirmée, documents
 *              déposés sans contrôle de type, séquences de n'importe quelle table
 *              avancées par la réservation d'identifiants.
 *
 * Autonome : copie jetable de l'application, base SQLite, serveur intégré.
 *
 *   php outils/epreuves/test-synchro.php
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
$dir = sys_get_temp_dir() . '/larka-synchro-' . bin2hex(random_bytes(4));
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
echo " Larka — la synchronisation de l'app respecte-t-elle les rôles ?\n";
echo "═══════════════════════════════════════════════════════════════════════\n";

try {
    // ── Établissement de l'épreuve ────────────────────────────────────────────
    $api('adm', 'GET', 'auth_config');                       // crée la base
    $api('adm', 'POST', 'login', ['login' => 'admin', 'password' => 'admin']);
    $initial = preg_match('/Mot de passe : (\S+)/', (string)@file_get_contents($dir . '/data/security/admin-initial.txt'), $mm) ? $mm[1] : '';
    $api('adm', 'POST', 'login', ['login' => 'admin', 'password' => $initial]);
    $api('adm', 'POST', 'change_password', ['oldPassword' => $initial, 'newPassword' => 'Epreuve-Admin-1']);
    $api('adm', 'POST', 'logout');
    [$c] = $api('adm', 'POST', 'login', ['login' => 'admin', 'password' => 'Epreuve-Admin-1']);
    if ($c !== 200) throw new RuntimeException("connexion administrateur impossible (HTTP $c)");
    $ids = [];
    foreach (['dem1' => ['Demandeur', null], 'dem2' => ['Demandeur', null], 'dem3' => ['Demandeur', ['contrats']],
              'vis1' => ['Visionneur', null], 'tec1' => ['Technicien', null], 'ges1' => ['Gestionnaire', null]] as $l => [$r, $acces]) {
        $u = ['nom' => $l, 'prenom' => 'E', 'login' => $l, 'motDePasse' => "Epreuve-$l-1", 'role' => $r, 'provider' => 'local', 'email' => "$l@epreuve.test"];
        if ($acces) $u['accesLecture'] = $acces;
        $api('adm', 'POST', 'utilisateurs', $u);
        $api($l, 'POST', 'login', ['login' => $l, 'password' => "Epreuve-$l-1"]);
        [, $me] = $api($l, 'GET', 'me');
        $ids[$l] = (int)($me['data']['Id'] ?? 0);
    }
    $api('adm', 'POST', 'contrats', ['numero' => 'C-SYNC', 'societe' => 'ACME', 'type' => 'Maintenance', 'dateDebut' => '2026-01-01', 'dateFin' => '2027-01-01']);
    [, $r] = $api('dem1', 'POST', 'demandes', ['titre' => 'Fuite dem1', 'description' => 'épreuve']);
    $dem1 = (int)($r['data']['id'] ?? 0);
    [, $r] = $api('dem2', 'POST', 'demandes', ['titre' => 'Panne dem2', 'description' => 'épreuve']);
    $dem2 = (int)($r['data']['id'] ?? 0);
    $png = base64_encode("\x89PNG\r\n\x1a\nepreuve");
    $api('dem2', 'POST', "documents&type=Demande&eid=$dem2", ['categorie' => 'photo', 'nom' => 'dem2.png', 'mime' => 'image/png', 'data' => $png]);
    $api('adm', 'POST', 'documents&type=Contrat&eid=1', ['categorie' => 'pdf', 'nom' => 'contrat.pdf', 'mime' => 'application/pdf', 'data' => base64_encode('%PDF-1.4 epreuve')]);
    // Procédures d'urgence : une catégorie pour tous, une réservée aux gestionnaires.
    $api('adm', 'POST', 'configuration', ['cle' => 'urgences_config', 'valeur' => json_encode(['categories' => [
        ['id' => 'publique', 'titre' => 'Incendie', 'visibilite' => 'tous', 'medias' => [['fichier' => 'm_public01.jpg']]],
        ['id' => 'reservee', 'titre' => 'Coupure générale', 'visibilite' => 'gestionnaires', 'medias' => [['fichier' => 'm_reserve01.jpg']]],
    ]])]);
    @mkdir($dir . '/data/urgences', 0777, true);
    file_put_contents($dir . '/data/urgences/m_public01.jpg', "\xFF\xD8\xFFpublic");
    file_put_contents($dir . '/data/urgences/m_reserve01.jpg', "\xFF\xD8\xFFreserve");
    // Réglage de sécurité et PC releveur : écrits directement (leurs écrans exigent une identité confirmée).
    $pdo = new PDO('sqlite:' . $dir . '/data/gmao.db');
    $pdo->exec("INSERT OR REPLACE INTO Configuration (Cle, Valeur) VALUES ('securite_identite_minutes', '5')");
    $pdo->exec("UPDATE Configuration SET Valeur = 'epreuve.test' WHERE Cle = 'domaines_autorises'");
    // (Larka Connect n'est pas ouvert ici : sa table est créée telle que Database la crée.)
    $pdo->exec("CREATE TABLE IF NOT EXISTS ConnectReleveurs (Id INTEGER PRIMARY KEY AUTOINCREMENT, Nom TEXT NOT NULL, JetonHash VARCHAR(64), Connexions TEXT, Actif INTEGER DEFAULT 1)");
    $pdo->exec("INSERT INTO ConnectReleveurs (Nom, JetonHash, Actif) VALUES ('PC épreuve', '" . hash('sha256', 'jeton-releveur') . "', 1)");
    $hashAdmin = (string)$pdo->query("SELECT MotDePasse FROM Utilisateurs WHERE Login = 'admin'")->fetchColumn();
    $pdo = null;

    $jDem1 = $lier('dem1', 'Epreuve-dem1-1');
    $jDem3 = $lier('dem3', 'Epreuve-dem3-1');
    $jVis = $lier('vis1', 'Epreuve-vis1-1');
    $jTec = $lier('tec1', 'Epreuve-tec1-1');
    $jGes = $lier('ges1', 'Epreuve-ges1-1');

    // ── Lecture ───────────────────────────────────────────────────────────────
    echo "\n  Lecture : ce que chaque rôle reçoit\n";
    [, $r] = $sync($jDem1, 'GET', 'synchro_tables');
    $tables = (array)($r['data']['tables'] ?? []);
    verifier('tables du lien de supervision : jamais synchronisées', !isset($tables['SupervisionLien']) && !isset($tables['SupervisionFile']), count($tables) . ' tables', $ok, $ko);
    verifier('demandeur : nombre de contrats annoncé = 0', (int)($tables['Contrats']['lignes'] ?? -1) === 0, (string)($tables['Contrats']['lignes'] ?? '?'), $ok, $ko);
    verifier('demandeur : aucun contrat reçu', $lignes($jDem1, 'Contrats') === [], '', $ok, $ko);
    verifier('demandeur : aucune pièce de contrat', !array_filter($lignes($jDem1, 'Documents'), fn($d) => $d['EntiteType'] === 'Contrat'), '', $ok, $ko);
    $dd = array_column($lignes($jDem1, 'DemandesIntervention'), 'Id');
    verifier('demandeur : ses demandes seulement', $dd === [$dem1], json_encode($dd), $ok, $ko);
    $docs = $lignes($jDem1, 'Documents');
    verifier('demandeur : pas la photo de la demande d\'autrui', !array_filter($docs, fn($d) => (int)$d['EntiteId'] === $dem2), count($docs) . ' doc(s)', $ok, $ko);
    $us = array_column($lignes($jDem1, 'Utilisateurs'), null, 'Login');
    verifier('demandeur : comptes d\'autrui sans empreinte de mot de passe', ($us['admin']['MotDePasse'] ?? 'x') === '', '', $ok, $ko);
    verifier('demandeur : comptes d\'autrui sans droits ouverts', array_key_exists('AccesLecture', $us['dem3'] ?? []) && $us['dem3']['AccesLecture'] === null, '', $ok, $ko);
    verifier('demandeur : son compte complet', ($us['dem1']['MotDePasse'] ?? '') !== '', '', $ok, $ko);
    verifier('jetons de réinitialisation : jamais', !array_filter($us, fn($u) => ($u['ResetToken'] ?? null) !== null), '', $ok, $ko);
    $cfg = array_column($lignes($jDem1, 'Configuration'), 'Valeur', 'Cle');
    verifier('demandeur : réglages de sécurité non reçus', !isset($cfg['securite_identite_minutes']) && !isset($cfg['domaines_autorises']), implode(',', array_keys($cfg)), $ok, $ko);
    verifier('demandeur : procédure réservée retirée du réglage', isset($cfg['urgences_config']) && !str_contains($cfg['urgences_config'], 'Coupure'), '', $ok, $ko);
    verifier('demandeur : aucune ligne Larka Connect', $lignes($jDem1, 'ConnectReleveurs') === [], '', $ok, $ko);
    [, $r] = $sync($jDem1, 'POST', 'synchro_empreintes', ['table' => 'Contrats', 'colonnes' => ['Numero']]);
    verifier('demandeur : empreintes des contrats vides', ($r['data']['empreintes'] ?? null) === [], '', $ok, $ko);
    [, $r] = $sync($jDem1, 'POST', 'synchro_lignes', ['table' => 'Contrats', 'cles' => [1]]);
    verifier('demandeur : contrat demandé par sa clé : rien', ($r['data']['lignes'] ?? null) === [], '', $ok, $ko);
    [, $r] = $sync($jDem1, 'POST', 'synchro_lignes', ['table' => 'DemandesIntervention', 'cles' => [$dem2]]);
    verifier('demandeur : demande d\'autrui par sa clé : rien', ($r['data']['lignes'] ?? null) === [], '', $ok, $ko);
    verifier('demandeur à qui l\'onglet est ouvert : contrats reçus', count($lignes($jDem3, 'Contrats')) === 1, '', $ok, $ko);
    verifier('demandeur à qui l\'onglet est ouvert : pièces du contrat', (bool)array_filter($lignes($jDem3, 'Documents'), fn($d) => $d['EntiteType'] === 'Contrat'), '', $ok, $ko);
    verifier('visionneur : contrats reçus (consultation du web)', count($lignes($jVis, 'Contrats')) === 1, '', $ok, $ko);
    verifier('visionneur : toutes les demandes', count($lignes($jVis, 'DemandesIntervention')) === 2, '', $ok, $ko);
    $cfgV = array_column($lignes($jVis, 'Configuration'), 'Cle');
    verifier('visionneur : réglages de sécurité non reçus', !in_array('securite_identite_minutes', $cfgV, true), '', $ok, $ko);
    $usG = array_column($lignes($jGes, 'Utilisateurs'), null, 'Login');
    verifier('gestionnaire : empreintes des comptes (téléphone partagé)', ($usG['dem1']['MotDePasse'] ?? '') !== '', '', $ok, $ko);
    $rel = $lignes($jGes, 'ConnectReleveurs');
    verifier('gestionnaire : jeton du PC releveur masqué', $rel !== [] && array_key_exists('JetonHash', $rel[0]) && $rel[0]['JetonHash'] === null, '', $ok, $ko);

    // ── Fichiers ──────────────────────────────────────────────────────────────
    echo "\n  Fichiers : médias des procédures d'urgence\n";
    [, $r] = $sync($jDem1, 'GET', 'synchro_fichiers');
    $f = array_keys((array)($r['data']['fichiers'] ?? []));
    verifier('demandeur : média public listé', in_array('data/urgences/m_public01.jpg', $f, true), '', $ok, $ko);
    verifier('demandeur : média réservé non listé', !in_array('data/urgences/m_reserve01.jpg', $f, true), '', $ok, $ko);
    [$c] = $sync($jDem1, 'GET', 'synchro_fichier&chemin=' . rawurlencode('data/urgences/m_reserve01.jpg'));
    verifier('demandeur : média réservé refusé', $c === 404, "HTTP $c", $ok, $ko);
    [$c] = $sync($jGes, 'GET', 'synchro_fichier&chemin=' . rawurlencode('data/urgences/m_reserve01.jpg'));
    verifier('gestionnaire : média réservé servi', $c === 200, "HTTP $c", $ok, $ko);

    echo "\n  Fichiers envoyés par un appareil, et leur service par les plans\n";
    $envoyer = fn(string $j, string $chemin, string $contenu) => $sync($j, 'POST', 'synchro_fichier_envoyer',
        ['chemin' => $chemin, 'morceau' => 0, 'total' => 1, 'contenu' => base64_encode($contenu)])[0];
    $c = $envoyer($jGes, 'data/plans/piege.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>');
    verifier('gestionnaire : SVG envoyé dans les plans → refusé', $c === 400 && !is_file("$dir/data/plans/piege.svg"), "HTTP $c", $ok, $ko);
    $c = $envoyer($jGes, 'data/extensions/code/autre.module/extension.json', '{"id":"autre.module"}');
    verifier('gestionnaire : déclaration de module envoyée → refusée', $c === 400, "HTTP $c", $ok, $ko);
    $c = $envoyer($jGes, 'data/thematiques/autre/extension.json', '{}');
    verifier('gestionnaire : thème envoyé → refusé', $c === 400, "HTTP $c", $ok, $ko);
    $c = $envoyer($jGes, 'data/plans/plan-envoye.png', "\x89PNG\r\n\x1a\nok");
    verifier('gestionnaire : plan PNG envoyé → accepté', $c === 200 && is_file("$dir/data/plans/plan-envoye.png"), "HTTP $c", $ok, $ko);
    $c = $envoyer($jDem1, 'data/plans/plan-demandeur.png', "\x89PNG\r\n\x1a\nok");
    verifier('demandeur : envoi de fichier → refusé', $c === 403, "HTTP $c", $ok, $ko);
    // SVG et fichier de type inconnu déjà présents (ancienne version, copie à la main) :
    // affichés sans jamais s'exécuter, ou téléchargés.
    file_put_contents("$dir/data/plans/ancien.svg", '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
    file_put_contents("$dir/data/plans/piege.html", '<script>alert(1)</script>');
    $entetes = function (string $chemin) use ($base, $jarres): array {
        $ch = curl_init($base . $chemin);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_NOBODY => false,
            CURLOPT_COOKIEFILE => $jarres['adm'], CURLOPT_TIMEOUT => 20]);
        $r = (string)curl_exec($ch);
        $t = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        return [(int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), strtolower(substr($r, 0, $t))];
    };
    [$c, $h] = $entetes('/api/index.php?action=plans_file&name=ancien.svg');
    verifier('plans_file : SVG sous politique « sandbox »', $c === 200 && str_contains($h, 'content-security-policy:') && str_contains($h, 'sandbox'), "HTTP $c", $ok, $ko);
    [$c, $h] = $entetes('/api/index.php?action=plans_file&name=piege.html');
    verifier('plans_file : type inconnu téléchargé, jamais text/html', !str_contains($h, 'text/html') && ($c !== 200 || str_contains($h, 'attachment')), "HTTP $c", $ok, $ko);
    $api('adm', 'GET', 'plans_batiments');
    $pdo = new PDO('sqlite:' . $dir . '/data/gmao.db');
    $pdo->exec("INSERT INTO PlanBatiments (Nom) VALUES ('Bâtiment épreuve')");
    $bat = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO PlanEtages (BatimentId, Nom, FondImage) VALUES ($bat, 'RDC', 'data/plans/piege.html')");
    $etage = (int)$pdo->lastInsertId();
    $pdo = null;
    [$c, $h] = $entetes("/api/index.php?action=plans_fond_image&etage_id=$etage");
    verifier('plans_fond_image : « .html » ni deviné ni rendu', $c === 200 && !str_contains($h, 'text/html') && str_contains($h, 'attachment'), "HTTP $c", $ok, $ko);

    // ── Écriture ──────────────────────────────────────────────────────────────
    echo "\n  Écriture : on ne modifie que ce qui nous appartient\n";
    $pirate = password_hash('Pirate-2026!', PASSWORD_BCRYPT);
    $r = $pousser($jDem1, ['table' => 'Utilisateurs', 'cle' => (string)$ids['dem1'] === '1' ? '2' : '1', 'op' => 'U', 'forcer' => true,
                           'avant' => ['Id' => $ids['dem1'], 'MotDePasse' => ''], 'apres' => ['Id' => $ids['dem1'], 'MotDePasse' => $pirate]]);
    verifier('demandeur : mot de passe d\'« admin » réécrit → refusé', !$applique($r) && count($r['refus'] ?? []) === 1, (string)($r['refus'][0]['raison'] ?? json_encode($r)), $ok, $ko);
    [$c] = $api('pirate', 'POST', 'login', ['login' => 'admin', 'password' => 'Pirate-2026!']);
    verifier('… et la connexion avec ce mot de passe échoue', $c === 401, "HTTP $c", $ok, $ko);
    $pdo = new PDO('sqlite:' . $dir . '/data/gmao.db');
    verifier('… l\'empreinte d\'« admin » est intacte', (string)$pdo->query("SELECT MotDePasse FROM Utilisateurs WHERE Login = 'admin'")->fetchColumn() === $hashAdmin, '', $ok, $ko);
    $r = $pousser($jDem1, ['table' => 'Utilisateurs', 'cle' => (string)$ids['dem1'], 'op' => 'U',
                           'avant' => ['Role' => 'Demandeur'], 'apres' => ['Role' => 'Gestionnaire']]);
    verifier('demandeur : son propre rôle → refusé', !$applique($r), (string)($r['refus'][0]['raison'] ?? ''), $ok, $ko);
    $r = $pousser($jDem1, ['table' => 'Utilisateurs', 'cle' => (string)$ids['dem1'], 'op' => 'U',
                           'avant' => ['Tel' => ''], 'apres' => ['Tel' => '01 02 03 04 05']]);
    verifier('demandeur : son téléphone → appliqué', $applique($r), json_encode($r['refus'] ?? []), $ok, $ko);
    $r = $pousser($jDem1, ['table' => 'DemandesIntervention', 'cle' => (string)$dem2, 'op' => 'U', 'forcer' => true,
                           'avant' => ['UtilisateurId' => $ids['dem2'], 'Titre' => 'Panne dem2'], 'apres' => ['UtilisateurId' => $ids['dem1'], 'Titre' => 'Volée']]);
    verifier('demandeur : demande d\'autrui (UtilisateurId = soi) → refusé', !$applique($r), '', $ok, $ko);
    verifier('… sans livrer la ligne du serveur', array_key_exists('ligne_serveur', $r['refus'][0] ?? []) && $r['refus'][0]['ligne_serveur'] === null, '', $ok, $ko);
    verifier('… la demande d\'autrui est intacte', (string)$pdo->query("SELECT Titre FROM DemandesIntervention WHERE Id = $dem2")->fetchColumn() === 'Panne dem2', '', $ok, $ko);
    $r = $pousser($jDem1, ['table' => 'DemandesIntervention', 'cle' => (string)$dem1, 'op' => 'U',
                           'avant' => ['Statut' => 'Nouveau'], 'apres' => ['Statut' => 'Traité', 'CommentaireAdmin' => 'fait']]);
    verifier('demandeur : clore sa demande → refusé', !$applique($r), (string)($r['refus'][0]['raison'] ?? ''), $ok, $ko);
    $r = $pousser($jDem1, ['table' => 'DemandesIntervention', 'cle' => (string)$dem1, 'op' => 'D', 'avant' => null, 'apres' => null]);
    verifier('demandeur : supprimer sa demande → refusé', !$applique($r), '', $ok, $ko);
    $r = $pousser($jDem1, ['table' => 'DemandesIntervention', 'cle' => (string)$dem1, 'op' => 'U',
                           'avant' => ['Titre' => 'Fuite dem1'], 'apres' => ['Titre' => 'Fuite dem1 (précisé)']]);
    verifier('demandeur : préciser sa demande encore « Nouveau » → appliqué', $applique($r), json_encode($r['refus'] ?? []), $ok, $ko);
    $r = $pousser($jDem1, ['table' => 'DemandesIntervention', 'cle' => '900001', 'op' => 'I', 'avant' => null,
                           'apres' => ['Id' => 900001, 'Titre' => 'Hors ligne', 'Statut' => 'Traité', 'UtilisateurId' => $ids['dem1']]]);
    verifier('demandeur : nouvelle demande déjà « Traité » → refusé', !$applique($r), '', $ok, $ko);
    $r = $pousser($jDem1, ['table' => 'DemandesIntervention', 'cle' => '900002', 'op' => 'I', 'avant' => null,
                           'apres' => ['Id' => 900002, 'Titre' => 'Hors ligne', 'Statut' => 'Nouveau', 'UtilisateurId' => $ids['dem1']]]);
    verifier('demandeur : nouvelle demande hors ligne → appliqué', $applique($r), json_encode($r['refus'] ?? []), $ok, $ko);
    $r = $pousser($jDem1, ['table' => 'DemandesIntervention', 'cle' => '900003', 'op' => 'I', 'avant' => null,
                           'apres' => ['Id' => 900003, 'Titre' => 'Au nom d\'autrui', 'Statut' => 'Nouveau', 'UtilisateurId' => $ids['dem2']]]);
    verifier('demandeur : demande au nom d\'autrui → refusé', !$applique($r), '', $ok, $ko);
    $doc = fn(int $eid, string $mime, string $b64, string $type = 'Demande') => ['table' => 'Documents', 'cle' => (string)random_int(800000, 899999), 'op' => 'I', 'avant' => null,
        'provisoire' => true, 'apres' => ['EntiteType' => $type, 'EntiteId' => $eid, 'NomFichier' => 'x', 'TypeMime' => $mime, 'Categorie' => 'photo', 'Taille' => 10, 'Donnees' => $b64]];
    verifier('demandeur : photo sur SA demande → appliqué', $applique($pousser($jDem1, $doc($dem1, 'image/png', $png))), '', $ok, $ko);
    verifier('demandeur : photo sur la demande d\'autrui → refusé', !$applique($pousser($jDem1, $doc($dem2, 'image/png', $png))), '', $ok, $ko);
    verifier('demandeur : pièce sur un contrat → refusé', !$applique($pousser($jDem1, $doc(1, 'application/pdf', base64_encode('%PDF-1.4'), 'Contrat'))), '', $ok, $ko);
    verifier('document SVG → refusé', !$applique($pousser($jGes, $doc($dem1, 'image/svg+xml', base64_encode('<svg onload="alert(1)"/>')))), '', $ok, $ko);
    verifier('document HTML → refusé', !$applique($pousser($jGes, $doc($dem1, 'text/html', base64_encode('<script>alert(1)</script>')))), '', $ok, $ko);
    verifier('« PNG » qui n\'en est pas un → refusé', !$applique($pousser($jDem1, $doc($dem1, 'image/png', base64_encode('<html>')))), '', $ok, $ko);
    $r = $pousser($jDem1, ['table' => 'Contrats', 'cle' => '1', 'op' => 'U', 'avant' => ['Numero' => 'C-SYNC'], 'apres' => ['Numero' => 'X']]);
    verifier('demandeur : contrat modifié → refusé, ligne non livrée', !$applique($r) && array_key_exists('ligne_serveur', $r['refus'][0] ?? [])
             && $r['refus'][0]['ligne_serveur'] === null, '', $ok, $ko);
    $id = (string)$pdo->query("SELECT Id FROM Configuration WHERE Cle = 'securite_identite_minutes'")->fetchColumn();
    $r = $pousser($jGes, ['table' => 'Configuration', 'cle' => $id, 'op' => 'U', 'avant' => ['Valeur' => '5'], 'apres' => ['Valeur' => '99999']]);
    verifier('gestionnaire : réglage « securite_… » → refusé', !$applique($r), (string)($r['refus'][0]['raison'] ?? ''), $ok, $ko);
    $r = $pousser($jGes, ['table' => 'ConnectReleveurs', 'cle' => '700001', 'op' => 'I', 'avant' => null,
                          'apres' => ['Id' => 700001, 'Nom' => 'PC pirate', 'JetonHash' => hash('sha256', 'connu'), 'Actif' => 1]]);
    verifier('gestionnaire : PC releveur créé depuis l\'appareil → refusé', !$applique($r), '', $ok, $ko);
    $r = $pousser($jGes, ['table' => 'Contrats', 'cle' => '1', 'op' => 'U', 'avant' => ['Societe' => 'ACME'], 'apres' => ['Societe' => 'ACME SA']]);
    verifier('gestionnaire : contrat modifié → appliqué', $applique($r), json_encode($r['refus'] ?? []), $ok, $ko);
    $r = $pousser($jTec, ['table' => 'Contrats', 'cle' => '1', 'op' => 'U', 'avant' => ['Societe' => 'ACME SA'], 'apres' => ['Societe' => 'X']]);
    verifier('technicien : contrat modifié → refusé', !$applique($r), '', $ok, $ko);
    [, $r] = $sync($jDem1, 'POST', 'synchro_reserver', ['tables' => ['Interventions' => 100, 'DemandesIntervention' => 100]]);
    verifier('demandeur : plage d\'identifiants des interventions → aucune', array_key_exists('Interventions', $r['data']['plages'] ?? []) && $r['data']['plages']['Interventions'] === null, '', $ok, $ko);
    verifier('demandeur : plage d\'identifiants de ses demandes → accordée', is_array($r['data']['plages']['DemandesIntervention'] ?? null), '', $ok, $ko);
    $pdo = null;

    // ── Multi-tenant : les fichiers sont ceux de l'organisation du jeton ─────
    echo "\n  Multi-tenant : fichiers de l'organisation du jeton\n";
    $saCorps = ['login' => 'sa', 'password' => 'Epreuve-SA-12', 'email' => 'sa@epreuve.test'];
    $api('sa', 'POST', 'superadmin_setup', $saCorps);
    [$c] = $api('sa', 'POST', 'superadmin_login', ['login' => 'sa', 'password' => 'Epreuve-SA-12']);
    if ($c !== 200) throw new RuntimeException("connexion super admin impossible (HTTP $c)");
    foreach (['alpha' => 'a1', 'beta' => 'b1'] as $t => $u) {
        $api('sa', 'POST', 'superadmin_tenant_save', ['key' => $t, 'nom' => $t, 'actif' => true, 'domaines_email' => ["$t.test"], 'db_driver' => 'sqlite']);
        $api('sa', 'POST', 'superadmin_provision', ['key' => $t]);
        $api('sa', 'POST', 'superadmin_tenant_user_save', ['tenant_key' => $t, 'login' => $u, 'nom' => $u, 'prenom' => 'E',
             'role' => 'Gestionnaire', 'provider' => 'local', 'motDePasse' => "Epreuve-$u-1"]);
        @mkdir("$dir/data/plans/tenants/$t", 0777, true);
        file_put_contents("$dir/data/plans/tenants/$t/plan-$t.png", "\x89PNG\r\n\x1a\n$t");
    }
    $jB = $lier('b1', 'Epreuve-b1-1');
    [, $r] = $sync($jB, 'GET', 'synchro_fichiers');
    $f = array_keys((array)($r['data']['fichiers'] ?? []));
    verifier('appareil de beta : ses plans listés', in_array('data/plans/plan-beta.png', $f, true), implode(',', $f), $ok, $ko);
    verifier('appareil de beta : plans d\'alpha absents', !in_array('data/plans/plan-alpha.png', $f, true), '', $ok, $ko);
    [$c, $r] = $sync($jB, 'GET', 'synchro_fichier&chemin=' . rawurlencode('data/plans/plan-alpha.png'));
    verifier('appareil de beta : plan d\'alpha introuvable', $c === 404, "HTTP $c", $ok, $ko);
    [$c] = $sync($jB, 'POST', 'synchro_fichier_envoyer', ['chemin' => 'data/plans/plan-alpha.png', 'morceau' => 0, 'total' => 1, 'contenu' => base64_encode('ecrase')]);
    verifier('appareil de beta : envoi écrit chez beta', is_file("$dir/data/plans/tenants/beta/plan-alpha.png"), "HTTP $c", $ok, $ko);
    verifier('… et pas chez alpha', (string)@file_get_contents("$dir/data/plans/tenants/alpha/plan-alpha.png") === "\x89PNG\r\n\x1a\nalpha", '', $ok, $ko);
} catch (\Throwable $e) {
    $ko[] = 'déroulé : ' . $e->getMessage();
    echo "  ❌  " . $e->getMessage() . "\n";
}

proc_terminate($proc); proc_close($proc);
$nettoyer();
$total = $ok + count($ko);
echo "\n───────────────────────────────────────────────────────────────────────\n";
if ($ko === []) printf(" ✅ %d/%d — la synchronisation respecte les rôles.\n", $ok, $total);
else { printf(" ❌ %d échec(s) sur %d :\n", count($ko), $total); foreach ($ko as $t) echo "      • $t\n"; }
echo "───────────────────────────────────────────────────────────────────────\n\n";
exit($ko === [] ? 0 : 1);

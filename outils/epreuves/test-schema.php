<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * Larka — épreuve du schéma et de la migration entre moteurs
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Défauts reproduits (2.1.1) sur une base de démonstration complète, migrée de
 * SQLite vers PostgreSQL puis comparée route par route à l'originale :
 *
 *   #1  migration    la liste des tables migrées était écrite en dur : factures,
 *                    archives, plans, mobilité, journal… restaient derrière ;
 *   #2  migration    la structure de destination était une copie figée du
 *                    schéma : toute colonne récente faisait échouer la copie ;
 *   #3  migration    rien n'empêchait de choisir la base en service comme
 *                    destination — l'étape « vider » l'effaçait ;
 *   #4  PostgreSQL   des colonnes absentes de la table de correspondance
 *                    revenaient en minuscules : champs vides à l'écran
 *                    (archives, contrats eau/gaz, échelle des plans…).
 *
 * Autonome, comme test-acces-coeur.php : copie jetable de l'application, base
 * SQLite jetable, serveur intégré sur un port libre.
 *
 * Facultatif : LARKA_EPREUVE_PG="host=127.0.0.1;port=5432;dbname=…;user=…;password=…"
 * (base VIDE dédiée) ajoute la migration vers PostgreSQL et le contrôle des types.
 *
 *   php outils/epreuves/test-schema.php
 * ═══════════════════════════════════════════════════════════════════════════════
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit("Ligne de commande uniquement.\n"); }
foreach (['curl', 'pdo_sqlite'] as $x) {
    if (!extension_loaded($x)) { echo "  ⏭️  Épreuve sautée : extension « $x » absente.\n"; exit(0); }
}

$source = dirname(__DIR__, 2);
$ok = 0; $ko = [];

function verifier(string $titre, bool $vrai, string $detail, int &$ok, array &$ko): void {
    if ($vrai) { $ok++; printf("  ✅  %-56s %s\n", $titre, mb_substr($detail, 0, 40)); }
    else       { $ko[] = $titre; printf("  ❌  %-56s %s\n", $titre, mb_substr($detail, 0, 160)); }
}

// ── 1. Copie jetable de l'application ─────────────────────────────────────────
$dir = sys_get_temp_dir() . '/larka-schema-' . bin2hex(random_bytes(4));
$exclus = ['.git', 'outils', 'Documentations', 'config.json', 'config.key', '.env', 'tenants.json', '.user.ini'];
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
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

$jarre = $dir . '/cookies.txt';
$api = function (string $methode, string $action, $corps = null) use ($port, $jarre): array {
    $ch = curl_init("http://127.0.0.1:$port/api/index.php?action=$action");
    $h = [];
    if (is_array($corps)) { $corps = json_encode($corps); $h[] = 'Content-Type: application/json'; }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $methode, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
        CURLOPT_COOKIEJAR => $jarre, CURLOPT_COOKIEFILE => $jarre, CURLOPT_HTTPHEADER => $h]);
    if ($corps !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $corps);
    $rep = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    return [$code, json_decode($rep, true) ?? $rep];
};

/** Tables et nombre de lignes d'une base (tables internes exclues). */
$inventaire = function (PDO $pdo): array {
    $pilote = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $tables = $pilote === 'pgsql'
        ? $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname = current_schema()")->fetchAll(PDO::FETCH_COLUMN)
        : $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
    $out = [];
    foreach ($tables as $t) $out[strtolower((string)$t)] = (int)$pdo->query('SELECT COUNT(*) FROM "' . $t . '"')->fetchColumn();
    ksort($out);
    return $out;
};

echo "\n═══════════════════════════════════════════════════════════════════════\n";
echo " Larka — le schéma et la migration entre moteurs tiennent-ils ?\n";
echo "═══════════════════════════════════════════════════════════════════════\n";

try {
    // ── Compte administrateur ─────────────────────────────────────────────────
    $api('GET', 'auth_config');
    $api('POST', 'login', ['login' => 'admin', 'password' => 'admin']);   // crée la base et le compte initial
    $initial = preg_match('/Mot de passe : (\S+)/', (string)@file_get_contents($dir . '/data/security/admin-initial.txt'), $mm) ? $mm[1] : '';
    [$c1, $j1] = $api('POST', 'login', ['login' => 'admin', 'password' => $initial]);
    [$c2, $j2] = $api('POST', 'change_password', ['oldPassword' => $initial, 'newPassword' => 'Epreuve-Schema-1']);
    $api('POST', 'logout');
    [$c, $j] = $api('POST', 'login', ['login' => 'admin', 'password' => 'Epreuve-Schema-1']);
    if ($c !== 200) throw new RuntimeException("connexion administrateur impossible (HTTP $c1/$c2/$c) "
        . json_encode([$j1, $j2, $j], JSON_UNESCAPED_UNICODE));

    // Les tables créées « à la demande » par leur écran.
    foreach (['energie_compteurs', 'energie_facteurs_carbone', 'plans_batiments', 'mobilite_carbone',
              'facteurs_carbone_millesimes', 'archives_boites', 'factures'] as $a) $api('GET', $a);

    // ── 2. Table de correspondance PostgreSQL ─────────────────────────────────
    echo "\n  Correspondance des colonnes (PostgreSQL rend tout en minuscules)\n";
    $src = (string)file_get_contents($source . '/api/Database.php');
    preg_match('/public static function colonnesCamelCase\(\): array \{\s*return \[(.*?)\];\s*\}/s', $src, $m);
    preg_match_all("/'([A-Za-z0-9_]+)'/", $m[1] ?? '', $mm);
    $carte = [];
    foreach ($mm[1] as $n) $carte[strtolower($n)] = $n;
    verifier('liste colonnesCamelCase() lisible', count($carte) > 300, count($carte) . ' noms', $ok, $ko);

    // FacteurMobilite.Co2Acv entre en collision avec ReseauChaleur.CO2ACV ; la
    // route des facteurs mobilité normalise ses clés elle-même.
    $tolerees = ['facteurmobilite.co2acv'];
    $pdo = new PDO('sqlite:' . $dir . '/data/gmao.db');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $manquantes = [];
    $nbCol = 0;
    foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' AND name NOT LIKE 'ext_%'")->fetchAll(PDO::FETCH_COLUMN) as $t) {
        foreach ($pdo->query('PRAGMA table_info("' . $t . '")')->fetchAll(PDO::FETCH_ASSOC) as $col) {
            $n = (string)$col['name'];
            if ($n === strtolower($n)) continue;      // déjà en minuscules : rien à rétablir
            $nbCol++;
            if (in_array(strtolower("$t.$n"), $tolerees, true)) continue;
            if (($carte[strtolower($n)] ?? null) !== $n) $manquantes[] = "$t.$n";
        }
    }
    verifier('toute colonne du schéma a sa casse rétablie', $manquantes === [],
        $manquantes ? 'manquantes : ' . implode(', ', array_slice($manquantes, 0, 12)) : "$nbCol colonnes", $ok, $ko);

    // ── 3. Migration SQLite → SQLite : toutes les tables suivent ──────────────
    echo "\n  Migration entre moteurs\n";
    $maintenant = date('Y-m-d H:i:s');
    $pdo->exec("INSERT INTO Factures (Numero, TypeBudget, MontantHT, MontantTTC, Fournisseur, DateFacture) VALUES ('F-1', 'Fonctionnement', 1000, 1200, 'ACME', '2026-01-15')");
    $pdo->exec("INSERT INTO FactureInterventions (FactureId, InterventionId, MontantLigne) VALUES (1, 1, 1200)");
    $pdo->exec("INSERT INTO ArchivesBoite (NumeroBoite, Service, Statut) VALUES ('B-001', 'Finances', 'Archive')");
    $pdo->exec("INSERT INTO PlanBatiments (Nom, Latitude, Longitude) VALUES ('Mairie', 48.85661, 2.35222)");
    $pdo->exec("INSERT INTO MobiliteCarbone (UtilisateurId, Transport, DistanceKm) VALUES (1, 'voiture', 12.5)");
    // Une table de module, comme l'installation d'un module la crée.
    $pdo->exec('CREATE TABLE "ext_epreuve_jeu" ("id" INTEGER PRIMARY KEY AUTOINCREMENT, "nom" TEXT, "montant" REAL, "quantite" INTEGER, "cree_le" TEXT)');
    $pdo->exec("INSERT INTO \"ext_epreuve_jeu\" (nom, montant, quantite, cree_le) VALUES ('a', 300000.45, 3, '$maintenant'), ('b', NULL, NULL, '$maintenant')");
    // Valeurs que SQLite accepte n'importe où, mais que PostgreSQL refuserait.
    $pdo->exec("INSERT INTO Stock (Designation, Quantite, PrixUnitaire) VALUES ('Joint', '', 'n/a')");
    $avant = $inventaire($pdo);
    $pdo = null;

    [$c, $j] = $api('POST', 'db_migrate', ['destination' => ['driver' => 'sqlite', 'path' => 'data/gmao.db']]);
    verifier('destination = base en service : refusée', $c === 400, "HTTP $c", $ok, $ko);

    [$c, $j] = $api('POST', 'db_migrate', ['destination' => ['driver' => 'sqlite', 'path' => 'data/migree.db']]);
    $rapport = $j['data']['report'] ?? $j['report'] ?? [];
    $erreurs = array_values(array_filter($rapport, fn($l) => ($l['status'] ?? '') === 'error'));
    verifier('migration SQLite → SQLite réussie', $c === 200 && $erreurs === [],
        "HTTP $c " . ($erreurs ? json_encode($erreurs[0], JSON_UNESCAPED_UNICODE) : (is_string($j) ? substr($j, 0, 120) : ($j['error'] ?? ''))), $ok, $ko);

    $dest = new PDO('sqlite:' . $dir . '/data/migree.db');
    $apres = $inventaire($dest);
    $perdues = [];
    foreach ($avant as $t => $n) {
        if (!array_key_exists($t, $apres)) $perdues[] = "$t (absente)";
        elseif ($apres[$t] < $n && $t !== 'journal') $perdues[] = "$t ({$apres[$t]}/$n)";
    }
    verifier('toutes les tables et toutes les lignes ont suivi', $perdues === [],
        $perdues ? implode(', ', $perdues) : count($avant) . ' tables', $ok, $ko);
    foreach (['factures', 'factureinterventions', 'archivesboite', 'planbatiments', 'mobilitecarbone', 'ext_epreuve_jeu'] as $t) {
        verifier("  · $t migrée", ($apres[$t] ?? 0) >= 1, ($apres[$t] ?? 0) . ' ligne(s)', $ok, $ko);
    }
    $cfg = json_decode((string)file_get_contents($dir . '/config.json'), true) ?: [];
    verifier('config.json désigne la nouvelle base', ($cfg['base_de_donnees']['path'] ?? '') === 'data/migree.db',
        (string)($cfg['base_de_donnees']['path'] ?? ''), $ok, $ko);

    // ── 4. Facultatif : PostgreSQL ────────────────────────────────────────────
    $pg = getenv('LARKA_EPREUVE_PG') ?: '';
    if ($pg === '' || !extension_loaded('pdo_pgsql')) {
        echo "\n  ⏭️  PostgreSQL : LARKA_EPREUVE_PG non défini — volet sauté.\n";
    } else {
        echo "\n  Migration vers PostgreSQL\n";
        parse_str(str_replace(';', '&', $pg), $p);
        // On repart de la base SQLite (la migration précédente a basculé config.json).
        $cfg['base_de_donnees'] = ['driver' => 'sqlite', 'path' => 'data/gmao.db'];
        file_put_contents($dir . '/config.json', json_encode($cfg, JSON_PRETTY_PRINT));
        $api('POST', 'login', ['login' => 'admin', 'password' => 'Epreuve-Schema-1']);
        [$c, $j] = $api('POST', 'db_migrate', ['destination' => ['driver' => 'pgsql',
            'host' => $p['host'] ?? '127.0.0.1', 'port' => (int)($p['port'] ?? 5432), 'dbname' => $p['dbname'] ?? '',
            'user' => $p['user'] ?? '', 'password' => $p['password'] ?? '', 'sslmode' => 'prefer']]);
        $rapport = $j['data']['report'] ?? [];
        $erreurs = array_values(array_filter($rapport, fn($l) => ($l['status'] ?? '') === 'error'));
        verifier('migration SQLite → PostgreSQL réussie (rôle non superutilisateur)', $c === 200 && $erreurs === [],
            "HTTP $c " . ($erreurs ? json_encode($erreurs[0], JSON_UNESCAPED_UNICODE) : (is_array($j) ? ($j['error'] ?? '') : substr((string)$j, 0, 160))), $ok, $ko);
        $pgPdo = new PDO(sprintf('pgsql:host=%s;port=%d;dbname=%s', $p['host'] ?? '127.0.0.1', (int)($p['port'] ?? 5432), $p['dbname'] ?? ''),
                         $p['user'] ?? '', $p['password'] ?? '');
        $apresPg = $inventaire($pgPdo);
        $perdues = [];
        foreach ($avant as $t => $n) {
            if (!array_key_exists($t, $apresPg)) $perdues[] = "$t (absente)";
            elseif ($apresPg[$t] < $n && $t !== 'journal') $perdues[] = "$t ({$apresPg[$t]}/$n)";
        }
        verifier('PostgreSQL : toutes les tables et lignes ont suivi', $perdues === [],
            $perdues ? implode(', ', $perdues) : count($apresPg) . ' tables', $ok, $ko);
        $reels = (int)$pgPdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = current_schema() AND data_type = 'real'")->fetchColumn();
        verifier('PostgreSQL : aucune colonne float4 (REAL)', $reels === 0, "$reels colonne(s) real", $ok, $ko);
        $montant = $pgPdo->query('SELECT montant FROM "ext_epreuve_jeu" WHERE nom = \'a\'')->fetchColumn();
        verifier('PostgreSQL : 300 000,45 garde ses centimes', abs((float)$montant - 300000.45) < 0.001, (string)$montant, $ok, $ko);
    }
} catch (\Throwable $e) {
    $ko[] = 'exception';
    echo "  ❌  " . $e->getMessage() . "\n";
} finally {
    if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); }
    $nettoyer();
}

echo "\n  " . ($ko ? '❌ ' . count($ko) . ' échec(s) — ' . implode(' · ', $ko) : "✅ $ok vérifications passées") . "\n";
exit($ko ? 1 : 0);

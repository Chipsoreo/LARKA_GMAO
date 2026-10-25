<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Épreuve « secrets » — les mots de passe restent hors du dépôt, hors des
 * journaux et hors de l'écran.
 *
 *   1. Dépôt : .env, config.json, config.key, clés et bases ignorés par git.
 *   2. Registre Super Admin : mots de passe des bases de tenants chiffrés au
 *      repos (config.key), y compris ceux d'avant 2.1.0 (migration).
 *   3. Journaux : pas de chaîne de requête (jetons OAuth) dans le journal du
 *      serveur intégré ni dans celui de Nginx.
 *   4. Écran et terminal : l'installation n'affiche plus le mot de passe de la
 *      base ; il ne passe plus en ligne de commande (visible de tous : ps).
 *   5. Mot de passe initial : son fichier disparaît une fois changé ; une mise
 *      à jour ne touche jamais config.key.
 *
 * php outils/epreuves/test-secrets.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$racine = dirname(__DIR__, 2);
$ok = 0; $ko = 0;
function verif(bool $c, string $m, string $d = ''): void
{
    global $ok, $ko;
    if ($c) { $ok++; printf("  ✅  %-62s %s\n", $m, $d); }
    else    { $ko++; printf("  ❌  %-62s %s\n", $m, $d); }
}
$lire = fn(string $f) => (string)@file_get_contents("$racine/$f");

echo "\n  Dépôt\n";
$git = trim((string)@shell_exec('command -v git 2>/dev/null'));
if ($git === '') {
    echo "  ⏭️  git absent : volet sauté.\n";
} else {
    $d = sys_get_temp_dir() . '/larka-gi-' . bin2hex(random_bytes(4));
    @mkdir("$d/sous/dossier", 0700, true);
    copy("$racine/.gitignore", "$d/.gitignore");
    $secrets = ['.env', '.env.production', 'config.json', 'config.key', 'tenants.json', 'sous/dossier/serveur.key',
                'sous/cle.pem', 'sous/dossier/id_ed25519', 'data/gmao.db', 'sauvegarde.sql.gz', 'data/security/audit.log'];
    $gardes = ['.env.example', 'config.example.json', 'api/config.php'];
    @mkdir("$d/data/security", 0700, true); @mkdir("$d/api", 0700, true);
    foreach (array_merge($secrets, $gardes) as $f) { @mkdir(dirname("$d/$f"), 0700, true); touch("$d/$f"); }
    shell_exec('cd ' . escapeshellarg($d) . ' && git init -q . 2>/dev/null');
    $ignores = array_filter(explode("\n", (string)shell_exec('cd ' . escapeshellarg($d) . ' && git check-ignore ' . implode(' ', array_map('escapeshellarg', array_merge($secrets, $gardes))) . ' 2>/dev/null')));
    verif(array_diff($secrets, $ignores) === [], 'secrets, clés, bases et journaux de sécurité : ignorés', implode(', ', array_diff($secrets, $ignores)));
    verif(array_intersect($gardes, $ignores) === [], 'modèles (.env.example, config.example.json) : versionnés');
    shell_exec('rm -rf ' . escapeshellarg($d));
}

echo "\n  Registre Super Admin : mots de passe chiffrés au repos\n";
if (!extension_loaded('pdo_sqlite') || !extension_loaded('openssl')) {
    echo "  ⏭️  pdo_sqlite ou openssl absent : volet sauté.\n";
} else {
    $t = sys_get_temp_dir() . '/larka-secrets-' . bin2hex(random_bytes(4));
    @mkdir("$t/data", 0700, true);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$racine/api", FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $f) {
        $cible = "$t/api/" . substr($f->getPathname(), strlen("$racine/api") + 1);
        if ($f->isDir()) { @mkdir($cible, 0700, true); continue; }
        @mkdir(dirname($cible), 0700, true);
        copy($f->getPathname(), $cible);
    }
    copy("$racine/version.json", "$t/version.json");
    file_put_contents("$t/config.json", json_encode(['serveur' => ['env' => 'dev'], 'base_de_donnees' => ['driver' => 'sqlite', 'path' => 'data/gmao.db']]));
    $code = <<<'PHP'
define('LARKA_SANS_SESSION', true);
chdir($argv[1]);
require 'api/config.php';
TenantResolver::upsertTenant('alpha', ['nom' => 'Alpha', 'actif' => true, 'domaines_email' => ['alpha.test'],
    'base_de_donnees' => ['driver' => 'pgsql', 'host' => '127.0.0.1', 'dbname' => 'alpha', 'user' => 'alpha', 'password' => 'marqueur-mdp-alpha']]);
$pdo = new PDO('sqlite:data/superadmin.db');
$brut = $pdo->query("SELECT db_password FROM sa_tenants WHERE cle='alpha'")->fetchColumn();
$lu = TenantResolver::getTenant('alpha')['base_de_donnees']['password'];
// Une installation d'avant 2.1.0 : mot de passe en clair, migration pas encore faite.
$pdo->exec("UPDATE sa_tenants SET db_password='marqueur-ancien' WHERE cle='alpha'");
$pdo->exec("DELETE FROM sa_config WHERE cle='mdp_tenants_chiffres'");
echo json_encode(['brut' => $brut, 'lu' => $lu]), "\n";
PHP;
    file_put_contents("$t/a.php", "<?php\n$code");
    $r1 = json_decode((string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$t/a.php") . ' ' . escapeshellarg($t) . ' 2>&1'), true);
    verif(is_array($r1) && str_starts_with((string)$r1['brut'], 'enc:v1:') && !str_contains((string)$r1['brut'], 'marqueur'), 'écrit chiffré (enc:v1:…), illisible dans une copie de data/');
    verif(is_array($r1) && $r1['lu'] === 'marqueur-mdp-alpha', 'relu en clair par Larka (connexion à la base du tenant)');
    // Nouvelle ouverture du registre : la migration chiffre l'ancien mot de passe.
    file_put_contents("$t/b.php", "<?php\ndefine('LARKA_SANS_SESSION', true);\nchdir(\$argv[1]);\nrequire 'api/config.php';\n"
        . "\$lu = TenantResolver::getTenant('alpha')['base_de_donnees']['password'];\n"
        . "\$brut = (new PDO('sqlite:data/superadmin.db'))->query(\"SELECT db_password FROM sa_tenants WHERE cle='alpha'\")->fetchColumn();\n"
        . "echo json_encode(['brut' => \$brut, 'lu' => \$lu]);\n");
    $r2 = json_decode((string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$t/b.php") . ' ' . escapeshellarg($t) . ' 2>&1'), true);
    verif(is_array($r2) && str_starts_with((string)$r2['brut'], 'enc:v1:') && $r2['lu'] === 'marqueur-ancien', 'mot de passe d\'avant 2.1.0 : chiffré à la première ouverture');
    verif(is_file("$t/config.key") && (fileperms("$t/config.key") & 0077) === 0, 'config.key : hors de data/, lisible du seul serveur (600)');
    shell_exec('rm -rf ' . escapeshellarg($t));
}

echo "\n  Journaux\n";
$sh = $lire('start.sh');
verif(str_contains($sh, 'PHP_S_DISCRET=(-q)') && str_contains($sh, 'nohup php "${PHP_S_DISCRET[@]}" -S'), 'start.sh -d : serveur sans lignes de requête (jetons dans l\'URL)');
verif(str_contains($sh, 'ExecStart=${phpbin} -q -S'), 'démarrage automatique (systemd) : idem');
verif(str_contains($lire('deploy/windows/larka.php'), "\$discret = getenv('LARKA_JOURNAL_REQUETES') === '1' ? [] : ['-q'];"), 'Windows (Larka.cmd) : idem');
foreach (['deploy/nginx.conf', 'deploy/install.sh'] as $f) {
    $n = $lire($f);
    $fmt = preg_match("/log_format larka_sans_jetons '([^;]*);/s", $n, $m) ? $m[1] : '';
    verif($fmt !== '' && !str_contains($fmt, '$request ') && !str_contains($fmt, '$request_uri') && !str_contains($fmt, '$args')
          && !str_contains($fmt, '$http_referer') && str_contains($n, 'gmao_access.log larka_sans_jetons;'), "Nginx ($f) : chemin et action seulement, ni requête ni Referer");
}

echo "\n  Écran, terminal, liste des processus\n";
$inst = $lire('deploy/install.sh');
verif(!preg_match('/(echo|warn|info|ok)\s+"[^"\n]*\$\{?DB_PASSWORD/', $inst), 'deploy/install.sh n\'affiche plus le mot de passe de la base');
verif(str_contains($inst, '--db-password-file') && str_contains($inst, 'LARKA_DB_PASSWORD'), '… et accepte un fichier ou une variable plutôt qu\'un argument');
verif(!preg_match("/php -r \"[^\"]*'\\$\\{DB_PASS\\}'/s", $sh) && substr_count($sh, "getenv('LARKA_PG_MDP')") === 2, 'start.sh : mot de passe par l\'environnement, pas par la ligne de commande');
$cfg = $lire('api/routes/gestion.php');
verif(str_contains($cfg, "\$mask = '••••••••';"), 'Configuration → Serveur : secrets masqués, jamais renvoyés');
verif(str_contains($lire('api/routes/superadmin.php'), '// NE PAS retourner le mot de passe'), 'Super Admin : mot de passe des bases de tenants jamais renvoyé');
verif(str_contains($lire('api/routes/auth.php'), "@unlink(__DIR__ . '/../../data/security/admin-initial.txt');"), 'mot de passe initial : fichier effacé une fois changé');
verif(str_contains($lire('api/MiseAJour.php'), "'.env', 'config.key',"), 'mise à jour : config.key jamais remplacé');

$total = $ok + $ko;
echo "\n───────────────────────────────────────────────────────────────────────\n";
echo $ko === 0 ? " ✅ $ok/$total — les secrets restent hors du dépôt, des journaux et de l'écran.\n"
               : " ❌ $ko échec(s) sur $total.\n";
echo "───────────────────────────────────────────────────────────────────────\n";
exit($ko === 0 ? 0 : 1);

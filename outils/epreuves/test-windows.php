<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Épreuve « Windows » — ce qui permet à Larka de tourner sous Windows.
 *
 *   1. Chemins : un chemin absolu Windows (« C:\… », « \\serveur\… ») est
 *      reconnu comme tel. En 2.1.1, il était préfixé une seconde fois : la
 *      base SQLite d'une installation Windows n'était jamais ouverte.
 *   2. Aucun « est-ce absolu ? » écrit à la main ne subsiste dans le code qui
 *      résout la base, les journaux, les sauvegardes ou les tenants.
 *   3. Lanceur : Larka.cmd en ASCII et CRLF (cmd.exe lit mal les étiquettes
 *      d'un fichier en LF), installer-php.ps1 en UTF-8 avec BOM (sans lui,
 *      Windows PowerShell 5.1 lit les accents de travers), php.ini qui tire ses
 *      chemins de LARKA_PHP_DIR (aucun chemin livré en dur).
 *   4. Les commandes que lance le lanceur (serveur, recalage) ne mêlent pas
 *      PHP_BINARY, que Windows donne en « ANSI », aux chemins en UTF-8 : un
 *      dossier « C:\Users\Hélène Martin\… » empêchait le serveur de démarrer.
 *   5. Le lanceur PHP se charge et répond, y compris sous Linux.
 *
 * Aucune dépendance, aucune base : php outils/epreuves/test-windows.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$racine = dirname(__DIR__, 2);
require_once $racine . '/api/Chemins.php';

$ok = 0; $ko = 0;
function verif(bool $c, string $m, string $d = ''): void
{
    global $ok, $ko;
    if ($c) { $ok++; printf("  ✅  %-56s %s\n", $m, $d); }
    else    { $ko++; printf("  ❌  %-56s %s\n", $m, $d); }
}

echo "\n  Chemins\n";
$absolus = ['/var/www/larka/data/gmao.db', 'C:\\Larka\\data\\gmao.db', 'c:/larka/data/gmao.db',
            'D:\\', '\\\\serveur\\partage\\gmao.db', '\\Larka\\data\\gmao.db'];
$relatifs = ['data/gmao.db', 'data\\gmao.db', './data/gmao.db', 'gmao.db', 'C:gmao.db', ''];
foreach ($absolus as $p) verif(larka_chemin_absolu($p), 'absolu : ' . $p);
foreach ($relatifs as $p) verif(!larka_chemin_absolu($p), 'relatif : ' . ($p === '' ? '(vide)' : $p));
verif(larka_chemin('data/gmao.db', 'C:\\Larka') === 'C:\\Larka/data/gmao.db', 'rattaché à la racine', larka_chemin('data/gmao.db', 'C:\\Larka'));
verif(larka_chemin('C:\\Base\\gmao.db', '/srv/larka') === 'C:\\Base\\gmao.db', 'absolu rendu tel quel');

echo "\n  Le jugement passe partout par Chemins.php\n";
$fichiers = ['api/config.php', 'api/Database.php', 'api/TenantResolver.php', 'api/MiseAJour.php',
             'api/routes/migration.php', 'api/routes/superadmin.php', 'api/extensions/outils/construire-paquet.php'];
foreach ($fichiers as $f) {
    $src = (string)@file_get_contents("$racine/$f");
    // L'ancien test : « le chemin commence-t-il par / ? », suivi d'un préfixe.
    $ancien = preg_match('/\$\w+\[0\]\s*!==\s*\'\/\'\s*\)\s*\$\w+\s*=\s*__DIR__/', $src)
           || preg_match('/!str_starts_with\(\$\w+,\s*\'\/\'\)\s*\)\s*\$\w+\s*=\s*__DIR__/', $src);
    verif($src !== '' && !$ancien && str_contains($src, 'larka_chemin'), $f);
}

echo "\n  Lanceur Windows\n";
$cmd = (string)@file_get_contents("$racine/Larka.cmd");
verif($cmd !== '' && !preg_match('/[^\x00-\x7F]/', $cmd), 'Larka.cmd en ASCII');
verif($cmd !== '' && substr_count($cmd, "\n") === substr_count($cmd, "\r\n"), 'Larka.cmd en CRLF', substr_count($cmd, "\r\n") . ' lignes');
verif(str_contains($cmd, 'set "LARKA_PHP_DIR=%PHP_DIR%"'), 'Larka.cmd pose LARKA_PHP_DIR');
$ps1 = (string)@file_get_contents("$racine/deploy/windows/installer-php.ps1");
verif(str_starts_with($ps1, "\xEF\xBB\xBF"), 'installer-php.ps1 avec BOM UTF-8');
verif($ps1 !== '' && substr_count($ps1, "\n") === substr_count($ps1, "\r\n"), 'installer-php.ps1 en CRLF');
verif(str_contains($ps1, 'extension_dir = "${LARKA_PHP_DIR}\\ext"'), 'php.ini : extension_dir tiré de LARKA_PHP_DIR');
verif(!preg_match('/\?\?|\?\.\w|&&|\|\|/', preg_replace('/^\s*#.*$/m', '', $ps1)), 'installer-php.ps1 sans syntaxe PowerShell 7 seule');
foreach (['pdo_sqlite', 'mbstring', 'intl', 'curl', 'openssl', 'sodium', 'zip', 'fileinfo', 'exif'] as $e) {
    if (!preg_match('/\$ExtensionsExigees\s*=\s*@\([^)]*\'' . $e . '\'/', $ps1)) { verif(false, "extension exigée : $e"); continue 1; }
}
verif(true, 'extensions exigées par l\'installeur');
verif(is_file("$racine/deploy/windows/larka.ico") && substr((string)file_get_contents("$racine/deploy/windows/larka.ico"), 0, 4) === "\0\0\1\0", 'icône des raccourcis');

echo "\n  Commandes lancées par le lanceur\n";
$src = (string)file_get_contents("$racine/deploy/windows/larka.php");
// PHP_BINARY est en « ANSI » sous Windows, le dossier de Larka en UTF-8 : les
// mêler dans une commande la fait relire en ANSI (« Hélène » → « HÃ©lÃ¨ne »).
verif(!preg_match('/\[\s*PHP_BINARY\s*[,\]]/', $src), 'PHP_BINARY jamais placé tel quel dans une commande');
verif(str_contains($src, "'-t', '.', 'router.php'"), 'serveur lancé avec racine et routeur relatifs');
verif(str_contains($src, '@chdir(LK_RACINE)'), 'dossier courant = dossier de Larka');
verif(str_contains($src, "'--fichiers=.'"), 'recalage des dates en chemins relatifs');

echo "\n  Lanceur PHP\n";
$lanceur = "$racine/deploy/windows/larka.php";
exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($lanceur) . ' 2>&1', $s, $c);
verif($c === 0, 'larka.php : syntaxe');
$s = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($lanceur) . ' aide 2>&1', $s, $c);
$aide = implode("\n", $s);
verif($c === 0 && str_contains($aide, 'demo') && str_contains($aide, 'raccourci'), 'larka.php aide');
$s = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($lanceur) . ' version 2>&1', $s, $c);
$v = json_decode((string)file_get_contents("$racine/version.json"), true)['version'] ?? '?';
verif($c === 0 && str_contains(implode(' ', $s), "Larka $v"), 'larka.php version', implode(' ', $s));

$total = $ok + $ko;
echo "\n───────────────────────────────────────────────────────────────────────\n";
echo $ko === 0 ? " ✅ $ok/$total — Larka sait où sont ses fichiers, sous Windows aussi.\n"
               : " ❌ $ko échec(s) sur $total.\n";
echo "───────────────────────────────────────────────────────────────────────\n";
exit($ko === 0 ? 0 : 1);

<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Épreuve « système » — Larka ne met pas à jour les composants du système sans
 * le dire, et une mise à jour du système ne casse pas ses bases.
 *
 *   1. Paquets (deploy/systeme.sh) : seul ce qui manque est installé. Si cela
 *      devait mettre à jour des paquets déjà là, la liste est montrée et
 *      l'accord demandé — « non » par défaut quand PostgreSQL en dépend
 *      (bibliothèque C, ICU, serveur) ; sans terminal, rien n'est fait sauf -y.
 *      Éprouvé avec un apt simulé : rien n'est réellement installé.
 *   2. PostgreSQL (api/outils/postgresql.php) : l'outil qui détecte et répare
 *      un changement de version de collation répond, sans rien modifier en
 *      simple vérification. (Volet PostgreSQL si LARKA_EPREUVE_PG est défini.)
 *
 * php outils/epreuves/test-systeme.php
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

if (PHP_OS_FAMILY === 'Windows' || !is_executable('/bin/bash')) {
    echo "\n  ⏭️  bash absent (Windows) : épreuve sautée.\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/larka-systeme-' . bin2hex(random_bytes(4));
@mkdir("$tmp/bin", 0700, true);
// apt simulé : dpkg-query lit la liste des paquets « installés » ; apt-get
// note ses appels et rend la simulation demandée.
file_put_contents("$tmp/bin/dpkg-query", "#!/bin/bash\np=\"\${@: -1}\"\nfor i in \$STUB_INSTALLES; do [[ \"\$i\" == \"\$p\" ]] && { echo -n installed; exit 0; }; done\necho -n not-installed\n");
file_put_contents("$tmp/bin/apt-get", "#!/bin/bash\necho \"apt-get \$*\" >> \"\$STUB_LOG\"\n[[ \"\$1\" == \"-s\" ]] && printf '%s\\n' \"\$STUB_SIMULATION\"\nexit 0\n");
file_put_contents("$tmp/bin/sudo", "#!/bin/bash\n\"\$@\"\n");
chmod("$tmp/bin/dpkg-query", 0755); chmod("$tmp/bin/apt-get", 0755); chmod("$tmp/bin/sudo", 0755);
file_put_contents("$tmp/essai.sh", "#!/bin/bash\nset -euo pipefail\nsource " . escapeshellarg("$racine/deploy/systeme.sh") . "\n"
    . "rc=0; sys_installer_paquets apt php8.3-cli php8.3-intl || rc=\$?\necho \"CODE=\$rc SENSIBLE=\$SYS_MAJ_SENSIBLE\"\n");

$sensible = "Inst libc6 [2.42-0ubuntu1] (2.43-0ubuntu1 Ubuntu:26.04/resolute [amd64])\n"
          . "Inst php8.3-common [8.3.6-0ubuntu0.24.04.5] (8.3.11-1 Ubuntu:26.04/resolute [amd64])\n"
          . "Inst php8.3-intl (8.3.11-1 Ubuntu:26.04/resolute [amd64])";
$ordinaire = "Inst php8.3-common [8.3.6-0ubuntu0.24.04.5] (8.3.11-1 Ubuntu:24.04/noble-updates [amd64])\n"
           . "Inst php8.3-intl (8.3.11-1 Ubuntu:24.04/noble-updates [amd64])";

/** Lance le harnais ; $terminal : réponses tapées dans un pseudo-terminal (script). */
$essai = function (string $simulation, string $installes, ?string $terminal, bool $oui = false) use ($tmp): array {
    $log = "$tmp/log"; @unlink($log); touch($log);
    $env = 'PATH=' . escapeshellarg("$tmp/bin:" . getenv('PATH')) . ' STUB_LOG=' . escapeshellarg($log)
         . ' STUB_INSTALLES=' . escapeshellarg($installes) . ' STUB_SIMULATION=' . escapeshellarg($simulation)
         . ($oui ? ' SYS_OUI=true' : '') . ' LC_ALL=C.UTF-8';
    if ($terminal !== null) {
        if (!is_executable('/usr/bin/script')) return ['', 0, true];
        $cmd = "printf %s " . escapeshellarg($terminal) . " | $env script -qec " . escapeshellarg("bash $tmp/essai.sh") . " /dev/null";
    } else {
        $cmd = "$env bash $tmp/essai.sh < /dev/null";
    }
    $sortie = (string)shell_exec($cmd . ' 2>&1');
    $installs = substr_count((string)file_get_contents($log), 'install -y');
    return [str_replace("\r", '', $sortie), $installs, false];
};

echo "\n  Paquets : installer ce qui manque, rien mettre à jour en silence\n";
[$s, $n] = $essai('', 'php8.3-cli php8.3-intl', null);
verif(str_contains($s, 'CODE=0') && $n === 0 && str_contains($s, 'rien à mettre à jour'), 'tout est là : rien n\'est lancé');
[$s, $n] = $essai($ordinaire, 'php8.3-cli', null);
verif(str_contains($s, 'CODE=1') && $n === 0, 'sans terminal, une mise à jour induite : refusée, rien installé');
[$s, $n] = $essai($sensible, 'php8.3-cli', null, true);
verif(str_contains($s, 'CODE=0 SENSIBLE=1') && $n === 1, '-y : accepté, mise à jour de libc6 signalée');
[$s, $n, $saute] = $essai($sensible, 'php8.3-cli', "\n");
if ($saute) {
    echo "  ⏭️  « script » absent : volet terminal sauté.\n";
} else {
    verif(str_contains($s, 'libc6 2.42-0ubuntu1 -> 2.43-0ubuntu1') && str_contains($s, '[o/N]'), 'libc6 montrée, question « non » par défaut');
    verif(str_contains($s, 'CODE=1') && $n === 0, 'Entrée : refus, rien installé ni mis à jour');
    [$s, $n] = $essai($sensible, 'php8.3-cli', "o\n");
    verif(str_contains($s, 'CODE=0 SENSIBLE=1') && $n === 1, '« o » : installé, réindexation à prévoir notée');
    [$s, $n] = $essai($ordinaire, 'php8.3-cli', "\n");
    verif(str_contains($s, '[O/n]') && str_contains($s, 'CODE=0 SENSIBLE=0') && $n === 1, 'mise à jour ordinaire (PHP) : « oui » par défaut');
}
$sys = (string)file_get_contents("$racine/deploy/systeme.sh");
verif(!preg_match('/pacman\s+-Sy\b/', $sys) && str_contains($sys, 'HOMEBREW_NO_INSTALL_UPGRADE=1'), 'ni « pacman -Sy » (mise à jour partielle), ni brew qui met à jour');
$sh = (string)file_get_contents("$racine/start.sh");
verif(str_contains($sh, "grep -qx installed; then\n                pkgs+=(postgresql postgresql-contrib)"), 'serveur PostgreSQL présent : pas de méta-paquet (nouvelle version majeure)');
verif(str_contains($sh, 'sys_installer_paquets "$pm"') && !preg_match('/apt-get install -y -qq "\$\{pkgs/', $sh), 'start.sh install passe par l\'accord');
verif(str_contains((string)file_get_contents("$racine/deploy/install.sh"), 'sys_installer_paquets apt'), 'deploy/install.sh aussi');
foreach (glob("$tmp/bin/*") ?: [] as $f) @unlink($f);
@rmdir("$tmp/bin"); @unlink("$tmp/essai.sh"); @unlink("$tmp/log"); @rmdir($tmp);

echo "\n  PostgreSQL : version de collation\n";
$outil = (string)file_get_contents("$racine/api/outils/postgresql.php");
verif(str_contains($outil, 'REINDEX DATABASE') && strpos($outil, 'REINDEX DATABASE') < strpos($outil, 'REFRESH COLLATION VERSION\');'),
    'réparation : index reconstruits AVANT d\'enregistrer la version');
verif(str_contains($outil, 'La version n\'a pas été enregistrée'), 'échec de reconstruction : version non enregistrée, problème visible');
verif(str_contains($outil, "!\$r['a_nous'] && !in_array(\$r['datname'], PG_BASES_SYSTEME, true)"), 'bases d\'autres applications : jamais modifiées');
verif(str_contains($sh, 'sys_pg_collation_proposer "$(larka_racine_config)"') && str_contains($sh, 'sys_pg_collation_reparer "$(larka_racine_config)"'),
    'start.sh : proposée au démarrage, réparée par doctor --fix');

$pg = getenv('LARKA_EPREUVE_PG') ?: '';
if ($pg === '' || !extension_loaded('pdo_pgsql')) {
    echo "\n  ⏭️  PostgreSQL : LARKA_EPREUVE_PG non défini — volet sauté.\n";
} else {
    parse_str(str_replace(';', '&', $pg), $p);
    $dir = sys_get_temp_dir() . '/larka-systeme-pg-' . bin2hex(random_bytes(4));
    @mkdir("$dir/api/outils", 0700, true);
    foreach (['api/outils/postgresql.php', 'api/ConfigCrypto.php', 'api/env.php', 'api/EnvFile.php'] as $f) copy("$racine/$f", "$dir/$f");
    file_put_contents("$dir/config.json", json_encode(['base_de_donnees' => ['driver' => 'pgsql', 'host' => $p['host'] ?? '127.0.0.1',
        'port' => (int)($p['port'] ?? 5432), 'dbname' => $p['dbname'] ?? '', 'user' => $p['user'] ?? '']]));
    file_put_contents("$dir/.env", 'GMAO_DB_PASSWORD=' . ($p['password'] ?? '') . "\n");
    $lancer = function (string $args) use ($dir): array {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$dir/api/outils/postgresql.php") . " $args 2>/dev/null", $o, $c);
        return [implode("\n", $o), $c];
    };
    [$o, $c] = $lancer('connexion');
    verif($o === 'OK', 'connexion : base vue comme Larka la voit (config.json + .env)', $o);
    [$o, $c] = $lancer('collation');
    verif(in_array($c, [0, 3], true) && str_contains($o, 'PostgreSQL'), 'vérification : rapport rendu (code 0 à jour, 3 à réindexer)', "code $c");
    [$o, $c] = $lancer('collation --court');
    verif($c === 0 ? $o === '' : $c === 3, '--court : muet quand tout va bien');
    file_put_contents("$dir/.env", "GMAO_DB_PASSWORD=mauvais\n");
    [$o, $c] = $lancer('connexion');
    verif(str_starts_with($o, 'ERREUR:'), 'mot de passe faux : ERREUR expliquée', mb_substr($o, 0, 50));
    foreach (['api/outils/postgresql.php', 'api/ConfigCrypto.php', 'api/env.php', 'api/EnvFile.php', 'config.json', '.env'] as $f) @unlink("$dir/$f");
    @rmdir("$dir/api/outils"); @rmdir("$dir/api"); @rmdir($dir);
}

$total = $ok + $ko;
echo "\n───────────────────────────────────────────────────────────────────────\n";
echo $ko === 0 ? " ✅ $ok/$total — rien n'est mis à jour sans accord, les bases survivent aux mises à jour.\n"
               : " ❌ $ko échec(s) sur $total.\n";
echo "───────────────────────────────────────────────────────────────────────\n";
exit($ko === 0 ? 0 : 1);

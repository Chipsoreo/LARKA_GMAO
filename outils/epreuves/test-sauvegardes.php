<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Épreuve « sauvegardes automatiques » — elles partent vraiment.
 *
 * Le réglage existait (case à cocher, intervalle, rotation) mais rien ne
 * déclenchait jamais la sauvegarde. L'épreuve pose une installation SQLite
 * jetable et lance l'outil comme le font les déclencheurs :
 *   1. une sauvegarde due est faite (base + état noté), pas une seconde fois ;
 *   2. la rotation garde N SAUVEGARDES (pas N fichiers), sans toucher à celles
 *      d'une organisation dont la clé commence pareil ;
 *   3. deux passages simultanés : un seul travaille (verrou) ;
 *   4. un échec est noté, signalé (code 1), et pas retenté à chaque passage ;
 *   5. aucun fichier tronqué ne porte le nom d'une sauvegarde ;
 *   6. les quatre déclencheurs existent (cron, systemd, start.sh, Windows).
 *
 * php outils/epreuves/test-sauvegardes.php
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
function copier_dossier(string $de, string $vers): void
{
    @mkdir($vers, 0700, true);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($de, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $f) {
        $cible = $vers . '/' . substr($f->getPathname(), strlen($de) + 1);
        $f->isDir() ? @mkdir($cible, 0700, true) : copy($f->getPathname(), $cible);
    }
}
function effacer(string $d): void
{
    if (!is_dir($d)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    @rmdir($d);
}

if (!extension_loaded('pdo_sqlite')) { echo "\n  ⏭️  pdo_sqlite absent : épreuve sautée.\n"; exit(0); }

// ── Installation jetable ────────────────────────────────────────────────────
$t = sys_get_temp_dir() . '/larka-sauvegardes-' . bin2hex(random_bytes(4));
copier_dossier("$racine/api", "$t/api");
copy("$racine/version.json", "$t/version.json");
@mkdir("$t/data/run", 0700, true);
$config = function (array $sv, string $base = 'data/gmao.db') use ($t): void {
    file_put_contents("$t/config.json", json_encode([
        'serveur' => ['env' => 'dev'],
        'base_de_donnees' => ['driver' => 'sqlite', 'path' => $base],
        'sauvegardes' => $sv + ['actif' => true, 'intervalle_minutes' => 60, 'garder' => 2, 'compresser' => true, 'dossier' => 'data/backups'],
    ]));
};
$config([]);
$pdo = new PDO("sqlite:$t/data/gmao.db");
$pdo->exec("CREATE TABLE Essai (Id INTEGER PRIMARY KEY, Libelle TEXT)");
$pdo->exec("INSERT INTO Essai (Libelle) VALUES ('marqueur-epreuve-sauvegarde')");
$pdo = null;

$outil = function (string $args = '') use ($t): array {
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$t/api/outils/sauvegarde-auto.php") . " $args 2>&1", $o, $c);
    return [implode("\n", $o), $c];
};
$sauvegardes = function () use ($t): array {
    $l = array_values(array_filter(scandir("$t/data/backups") ?: [], fn($f) => $f[0] !== '.'));
    sort($l);
    return $l;
};
$etat = fn() => json_decode((string)@file_get_contents("$t/data/run/sauvegardes-auto.json"), true) ?: [];

echo "\n  Une sauvegarde due part, une seule fois\n";
[$o, $c] = $outil('--etat');
verif($c === 0 && str_contains($o, 'dernière  : jamais'), '--etat : aucune sauvegarde pour l\'instant');
[$o, $c] = $outil('--silencieux');
$l = $sauvegardes();
verif($c === 0 && count($l) === 1 && preg_match('/^tenant_default_\d{8}_\d{6}\.db\.gz$/', $l[0] ?? ''), 'due (jamais faite) : faite', $l[0] ?? '(rien)');
$contenu = $l ? (string)gzdecode((string)file_get_contents("$t/data/backups/{$l[0]}")) : '';
verif(str_contains($contenu, 'marqueur-epreuve-sauvegarde'), 'la sauvegarde contient la base');
$e = $etat();
verif(!empty($e['default']['derniere']) && empty($e['default']['erreur']) && !empty($e['_passage']), 'état noté : dernière, passage du planificateur');
[$o, $c] = $outil();
verif($c === 0 && str_contains($o, 'pas encore due') && count($sauvegardes()) === 1, 'second passage : pas encore due, rien de fait');

echo "\n  Rotation : des sauvegardes, pas des fichiers\n";
// Deux de plus (une seconde d'écart : l'horodatage est à la seconde), avec
// un « …-fichiers.tar.gz » à côté de chacune, et une organisation voisine.
@mkdir("$t/data/backups", 0700, true);
foreach (['20200101_000000', '20200102_000000'] as $vieux) {
    touch("$t/data/backups/tenant_default_$vieux.db.gz");
    touch("$t/data/backups/tenant_default_$vieux-fichiers.tar.gz");
}
touch("$t/data/backups/tenant_default_x_20190101_000000.db.gz");   // clé « default_x » : pas la nôtre
sleep(1);
[$o, $c] = $outil('--forcer');
$l = $sauvegardes();
$groupes = array_unique(array_map(fn($f) => preg_match('/^tenant_default_(\d{8}_\d{6})/', $f, $m) ? $m[1] : '', $l));
verif(count(array_filter($l, fn($f) => str_starts_with($f, 'tenant_default_2'))) === 2 && !in_array('20200101_000000', $groupes, true)
      && !in_array('20200102_000000', $groupes, true), 'garder = 2 : les 2 plus récentes, base et fichiers ensemble', implode(', ', $l));
verif(in_array('tenant_default_x_20190101_000000.db.gz', $l, true), 'organisation « default_x » : jamais touchée');
verif(!array_filter($l, fn($f) => preg_match('/\.(partiel|tmpdb|tar)$/', $f)), 'aucun fichier provisoire laissé');

echo "\n  Verrou : un seul passage à la fois\n";
$v = fopen("$t/data/run/sauvegardes-auto.lock", 'c');
flock($v, LOCK_EX);
$avant = count($sauvegardes());
[$o, $c] = $outil('--forcer');
verif($c === 0 && str_contains($o, 'déjà en cours') && count($sauvegardes()) === $avant, 'passage concurrent : s\'efface, rien d\'écrit');
flock($v, LOCK_UN); fclose($v);

echo "\n  Échec : signalé, noté, pas retenté à chaque passage\n";
$config([], 'data/absente.db');
[$o, $c] = $outil('--forcer');
verif($c === 1 && str_contains($o, '✗'), 'base introuvable : ✗ et code 1 (le cron le signale)');
$e = $etat();
verif(!empty($e['default']['erreur']) && !empty($e['default']['derniere']), 'erreur notée, dernière réussite conservée', mb_substr((string)($e['default']['erreur'] ?? ''), 0, 40));
$config(['intervalle_minutes' => 5]);
$e['default']['derniere'] = date('Y-m-d H:i:s', time() - 3600);   // due depuis longtemps…
file_put_contents("$t/data/run/sauvegardes-auto.json", json_encode($e));
[$o, $c] = $outil();
verif(str_contains($o, 'pas encore due'), '… mais l\'échec date d\'une minute : pas d\'acharnement');
[$o, $c] = $outil('--etat');
verif(str_contains($o, 'dernier échec'), '--etat montre le dernier échec');
$config(['actif' => false]);
[$o, $c] = $outil();
verif($c === 0 && str_contains($o, 'désactivée'), 'désactivée : rien');

echo "\n  Les déclencheurs\n";
$sh = (string)file_get_contents("$racine/start.sh");
verif(substr_count($sh, 'planificateur_demarrer "') === 2 && str_contains($sh, "planificateur_arreter\n    if is_running"), 'start.sh : planificateur avec le serveur (démon et premier plan), arrêté avec lui');
verif(str_contains($sh, 'larka-sauvegardes.timer') && str_contains($sh, 'OnUnitActiveSec=10min'), 'démarrage automatique : minuterie systemd');
verif((bool)preg_match('#\*/10 \* \* \* \* www-data .*api/outils/sauvegarde-auto\.php --silencieux#', (string)file_get_contents("$racine/deploy/install.sh")), 'production : tâche cron toutes les 10 minutes');
$lk = (string)file_get_contents("$racine/deploy/windows/larka.php");
verif(str_contains($lk, '$code = lk_veiller_serveur($proc);') && str_contains($lk, "'api/outils/sauvegarde-auto.php', '--silencieux'"), 'Windows : le lanceur les lance pendant que Larka tourne');
$idx = (string)file_get_contents("$racine/api/index.php");
verif(str_contains($idx, 'fastcgi_finish_request') && str_contains($idx, 'LarkaSauvegardesAuto::executer()'), 'PHP-FPM : relais après la réponse si rien n\'est passé depuis 10 min');
$sa = (string)file_get_contents("$racine/js/pages/superadmin.js");
verif(!str_contains($sa, "Doit être déclenchée par un cron qui appelle l'API") && str_contains($sa, '_saChargerEtatSauvegardes'), 'écran : état réel (dernière, prochaine, planificateur), plus de promesse de cron');

effacer($t);

$total = $ok + $ko;
echo "\n───────────────────────────────────────────────────────────────────────\n";
echo $ko === 0 ? " ✅ $ok/$total — les sauvegardes automatiques partent, tournent et se signalent.\n"
               : " ❌ $ko échec(s) sur $total.\n";
echo "───────────────────────────────────────────────────────────────────────\n";
exit($ko === 0 ? 0 : 1);

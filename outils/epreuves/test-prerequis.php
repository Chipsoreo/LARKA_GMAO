<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Épreuve « prérequis » — une version incompatible est détectée AVANT d'être
 * installée ou démarrée.
 *
 *   1. Les exigences viennent de version.json (« prerequis ») : chaque version
 *      porte les siennes.
 *   2. PHP trop ancien, extension indispensable absente, SQLite trop ancien :
 *      bloquant. Extension conseillée absente, PHP plus récent qu'éprouvé :
 *      avertissement, sans bloquer.
 *   3. Une mise à jour est confrontée aux exigences de la NOUVELLE version
 *      (manifeste, puis version.json du paquet) ; rien n'est écrit sinon.
 *   4. Les quatre portes d'entrée l'utilisent : start.sh, deploy/install.sh,
 *      Larka.cmd, mise à jour depuis l'interface.
 *
 * php outils/epreuves/test-prerequis.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$racine = dirname(__DIR__, 2);
require_once $racine . '/api/Prerequis.php';
$ok = 0; $ko = 0;
function verif(bool $c, string $m, string $d = ''): void
{
    global $ok, $ko;
    if ($c) { $ok++; printf("  ✅  %-62s %s\n", $m, $d); }
    else    { $ko++; printf("  ❌  %-62s %s\n", $m, $d); }
}
$statut = fn(array $r, string $id) => array_values(array_filter($r['points'], fn($p) => $p['id'] === $id))[0]['statut'] ?? null;

echo "\n  Exigences\n";
$vj = json_decode((string)file_get_contents("$racine/version.json"), true);
$e = LarkaPrerequis::exigences();
verif(is_array($vj['prerequis'] ?? null) && $e['php'] === ($vj['prerequis']['php'] ?? null), 'lues dans version.json (portées par la version)', 'PHP ≥ ' . $e['php']);
verif(LarkaPrerequis::exigences(['php_min' => '8.4'])['php'] === '8.4', 'ancien format (« php_min ») compris');
$r = LarkaPrerequis::verifier();
verif($r['ok'] && $statut($r, 'php') !== 'bloquant', 'cet environnement : satisfait', $r['avertissements'] . ' avertissement(s)');

echo "\n  Bloquant ou simple avertissement\n";
$r = LarkaPrerequis::verifier(LarkaPrerequis::exigences(['prerequis' => ['php' => '99.0']]));
verif(!$r['ok'] && $statut($r, 'php') === 'bloquant', 'PHP plus ancien qu\'exigé : bloquant');
$r = LarkaPrerequis::verifier(LarkaPrerequis::exigences(['prerequis' => ['extensions' => ['requises' => ['extension_epreuve_absente' => 'essai']]]]));
verif(!$r['ok'] && $statut($r, 'extensions') === 'bloquant', 'extension indispensable absente : bloquant');
$r = LarkaPrerequis::verifier(null, ['driver' => 'mysql']);
verif(extension_loaded('pdo_mysql') ? $statut($r, 'extensions') === 'ok' : (!$r['ok'] && $statut($r, 'extensions') === 'bloquant'), 'extension de la base configurée (pdo_mysql) exigée');
$r = LarkaPrerequis::verifier(LarkaPrerequis::exigences(['prerequis' => ['extensions' => ['recommandees' => ['extension_epreuve_absente' => 'essai']]]]));
verif($r['ok'] && $statut($r, 'extensions_facultatives') === 'avertissement', 'extension conseillée absente : avertissement, pas de blocage');
$r = LarkaPrerequis::verifier(LarkaPrerequis::exigences(['prerequis' => ['php' => '7.0', 'php_eprouve' => '7.4']]));
verif($r['ok'] && $statut($r, 'php') === 'avertissement', 'PHP plus récent que les versions éprouvées : avertissement');
if (extension_loaded('pdo_sqlite')) {
    $r = LarkaPrerequis::verifier(LarkaPrerequis::exigences(['prerequis' => ['sqlite' => '99.0']]));
    verif(!$r['ok'] && $statut($r, 'sqlite') === 'bloquant', 'SQLite plus ancien qu\'exigé : bloquant');
}

echo "\n  Outil en ligne de commande\n";
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$racine/api/outils/prerequis.php") . ' --sans-base 2>&1', $o, $c);
verif($c === 0 && str_contains(implode("\n", $o), 'prérequis'), 'installation actuelle : code 0');
$d = sys_get_temp_dir() . '/larka-prerequis-' . bin2hex(random_bytes(4));
@mkdir($d, 0700, true);
file_put_contents("$d/version.json", json_encode(['version' => '9.9.9', 'prerequis' => ['php' => '99.0']]));
$o = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$racine/api/outils/prerequis.php") . ' --sans-base --court --paquet=' . escapeshellarg($d) . ' 2>&1', $o, $c);
verif($c === 1 && str_contains(implode("\n", $o), '✗ PHP'), '--paquet : exigences d\'une autre version, bloquant → code 1');
@unlink("$d/version.json"); @rmdir($d);

echo "\n  Mise à jour : exigences de la nouvelle version\n";
$maj = (string)file_get_contents("$racine/api/MiseAJour.php");
verif(str_contains($maj, "'prerequis'=> is_array(\$m['prerequis'] ?? null)") && str_contains($maj, '$this->installable($requis, $prerequis)'), 'détection : exigences du manifeste vérifiées avant le téléchargement');
verif(str_contains($maj, 'if ($bloquants = $this->prerequisBloquants($nv))') && strpos($maj, 'prerequisBloquants($nv)') < strpos($maj, '$this->planifier($zip, $prefixe)'),
    'installation : version.json du paquet vérifié AVANT toute écriture');
$pub = (string)file_get_contents("$racine/outils/publier-version.php");
verif(str_contains($pub, "\$vj['prerequis']['php'] = \$phpMin") && str_contains($pub, "'prerequis' => \$vj['prerequis'] ?? null"), 'publication : exigences gardées dans version.json et le manifeste');

echo "\n  Portes d'entrée\n";
$sh = (string)file_get_contents("$racine/start.sh");
verif(str_contains($sh, "    verifier_prerequis\n}") && str_contains($sh, 'php api/outils/prerequis.php 2>&1'), 'start.sh : avant chaque démarrage, et dans doctor');
verif(str_contains((string)file_get_contents("$racine/deploy/install.sh"), 'prerequis.php" --court --sans-base'), 'deploy/install.sh : avant de configurer quoi que ce soit');
$lk = (string)file_get_contents("$racine/deploy/windows/larka.php");
verif(substr_count($lk, 'LarkaPrerequis::verifier(') >= 2, 'Larka.cmd : au démarrage et dans le diagnostic');

$total = $ok + $ko;
echo "\n───────────────────────────────────────────────────────────────────────\n";
echo $ko === 0 ? " ✅ $ok/$total — une version incompatible est arrêtée avant d'être installée.\n"
               : " ❌ $ko échec(s) sur $total.\n";
echo "───────────────────────────────────────────────────────────────────────\n";
exit($ko === 0 ? 0 : 1);

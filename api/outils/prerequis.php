<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka — vérification des prérequis (voir api/Prerequis.php).
 *
 *   php api/outils/prerequis.php                 rapport complet (base comprise si configurée)
 *   php api/outils/prerequis.php --court         seulement ce qui ne va pas
 *   php api/outils/prerequis.php --sans-base     sans interroger le serveur de base
 *   php api/outils/prerequis.php --paquet=DOSSIER  exigences d'une AUTRE version
 *                                                (dossier ou version.json d'un paquet décompressé)
 *   php api/outils/prerequis.php --json          résultat brut
 *
 * Code de sortie : 0 tout va bien ou avertissements seulement, 1 bloquant.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$racine = dirname(__DIR__, 2);
require_once $racine . '/api/Prerequis.php';

$args = array_slice($argv, 1);
$opt = static function (string $nom) use ($args): ?string {
    foreach ($args as $a) {
        if ($a === "--$nom") return '';
        if (str_starts_with($a, "--$nom=")) return substr($a, strlen($nom) + 3);
    }
    return null;
};

// Exigences : celles de cette installation, ou celles d'un paquet à installer.
$versionJson = null;
if (($p = $opt('paquet')) !== null && $p !== '') {
    $f = is_dir($p) ? rtrim($p, '/\\') . '/version.json' : $p;
    $versionJson = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    if (!is_array($versionJson)) { fwrite(STDERR, "version.json introuvable ou illisible : $f\n"); exit(2); }
}
$exigences = LarkaPrerequis::exigences($versionJson);

// Base configurée (config.json + .env), sans charger config.php : ce script
// doit répondre même là où le reste de Larka ne démarrerait pas.
$base = null;
if ($opt('sans-base') === null && is_file($racine . '/config.json')) {
    $t = (string)file_get_contents($racine . '/config.json');
    if (str_starts_with($t, "\xEF\xBB\xBF")) $t = substr($t, 3);
    $cfg = json_decode($t, true);
    if (is_array($cfg)) {
        try {
            require_once $racine . '/api/ConfigCrypto.php';
            require_once $racine . '/api/env.php';
            require_once $racine . '/api/EnvFile.php';
            try { ConfigCrypto::decryptSensitiveFields($cfg); } catch (\Throwable $e) { /* laissée telle quelle */ }
            loadEnv();
            foreach (EnvFile::allEnvMap() as $chemin => $nom) {
                $v = env($nom, null);
                if ($v === null || $v === '') continue;
                [$s, $k] = explode('.', $chemin, 2);
                if (!isset($cfg[$s]) || !is_array($cfg[$s])) $cfg[$s] = [];
                $cfg[$s][$k] = $v;
            }
        } catch (\Throwable $e) { /* lecture minimale */ }
        $b = $cfg['base_de_donnees'] ?? null;
        if (is_array($b)) $base = $b + ['driver' => 'sqlite'];
    }
}

$r = LarkaPrerequis::verifier($exigences, $base, $racine);
if ($opt('json') !== null) {
    echo json_encode($r + ['exigences' => $exigences], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
} else {
    $t = LarkaPrerequis::texte($r, $opt('court') !== null);
    if ($t !== '') echo $t, "\n";
    if ($opt('court') === null) {
        echo $r['ok']
            ? ($r['avertissements'] ? "Prérequis satisfaits ({$r['avertissements']} avertissement(s)).\n" : "Tous les prérequis sont satisfaits.\n")
            : "{$r['bloquants']} prérequis bloquant(s) : Larka ne peut pas fonctionner tant qu'ils ne sont pas réglés.\n";
    }
}
exit($r['ok'] ? 0 : 1);

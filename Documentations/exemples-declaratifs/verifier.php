<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Vérifie les exemples déclaratifs de ce dossier.
 *
 * Il y avait ici une seconde implémentation du même contrôle que
 * outils/verifier-module.php : charger le schéma, valider, afficher les capacités. Deux
 * validateurs pour un seul schéma finissent toujours par diverger — l'un gagne
 * une vérification que l'autre n'a pas, et l'on ne sait plus lequel fait foi.
 *
 * Celui-ci ne fait donc plus que déléguer. Le vérificateur, c'est le SDK.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$racine = dirname(__DIR__, 2);
$sdk    = $racine . '/outils/verifier-module.php';

if (!is_file($sdk)) {
    fwrite(STDERR, "\n  outils/verifier-module.php introuvable.\n\n");
    exit(2);
}

$exemples = glob(__DIR__ . '/*.json') ?: [];
if ($exemples === []) {
    echo "\n  Aucun exemple dans ce dossier.\n\n";
    exit(0);
}

// Le SDK attend des DOSSIERS de module. Les exemples sont des fichiers JSON
// isolés : on les présente un par un dans un dossier temporaire.
$temp = sys_get_temp_dir() . '/larka-exemples-' . getmypid();
@mkdir($temp, 0700, true);

$ok = 0; $ko = 0;
foreach ($exemples as $f) {
    $nom = basename($f, '.json');
    $d   = $temp . '/' . $nom;
    @mkdir($d, 0700, true);
    copy($f, $d . '/extension.json');

    $sortie = [];
    exec(sprintf('php %s %s 2>&1', escapeshellarg($sdk), escapeshellarg($d)),
         $sortie, $code);

    foreach ($sortie as $ligne) {
        if (trim($ligne) === '' || str_contains($ligne, 'module(s) valide')) continue;
        printf("  %s\n", preg_replace('/^\s+/', '  ', $ligne));
    }
    $code === 0 ? $ok++ : $ko++;

    array_map('unlink', glob($d . '/*') ?: []);
    @rmdir($d);
}
@rmdir($temp);

echo "\n";
printf("  %d exemple(s) valide(s)%s contre le schéma courant.\n",
       $ok, $ko ? ", $ko en echec" : '');
echo "\n";
exit($ko === 0 ? 0 : 1);

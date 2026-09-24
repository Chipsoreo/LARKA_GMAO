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
 * Larka SDK — vérificateur de module
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * CE QUI A REMPLACÉ QUOI
 * Ce fichier était un « banc d'essai » : il montait une base SQLite et exécutait
 * les ACTIONS du module dans un contexte simulé. Il chargeait pour cela
 * sdk/banc/contexte-simule.php et sdk/banc/base-essai.php — disparus avec le bac
 * à sable, quand la voie « code de l'auteur » a été retirée. Le script échouait
 * donc sur son premier require, avant même de lire ses arguments. Et il n'y
 * avait plus rien à exécuter, puisqu'un module n'exécute plus rien.
 *
 * Ce qu'il reste à vérifier est en revanche tout ce qui compte désormais : la
 * déclaration est-elle valide, et que va-t-elle produire ? C'est ce que fait ce
 * script, en chargeant le SCHÉMA RÉEL du serveur — pas une copie qui finirait
 * par en diverger.
 *
 * UTILISATION
 *   php outils/verifier-module.php extensions/modules/acme.mon-module
 *   php outils/verifier-module.php extensions/modules/acme.mon-module --detail
 *   php outils/verifier-module.php --tous
 * ═══════════════════════════════════════════════════════════════════════════════
 */

if (PHP_SAPI !== 'cli') { exit("Ligne de commande uniquement.\n"); }

$racine = dirname(__DIR__);
if (!defined('LARKA_VERSION')) define('LARKA_VERSION', '1.0.0');
require_once $racine . '/api/extensions/declaratif/Schema.php';
require_once $racine . '/api/extensions/References.php';

$args   = array_slice($argv, 1);
$detail = in_array('--detail', $args, true);
$tous   = in_array('--tous', $args, true);
$cibles = array_values(array_filter($args, fn($a) => !str_starts_with($a, '--')));

if (!$tous && $cibles === []) {
    echo <<<AIDE

  Larka SDK — vérificateur de module

    php outils/verifier-module.php <dossier-du-module>
    php outils/verifier-module.php <dossier-du-module> --detail
    php outils/verifier-module.php --tous

  Valide une déclaration contre le schéma du serveur et annonce ce qu'elle
  produira : jeux de données, écrans, capacités déduites.

  Aucune base n'est nécessaire : rien n'est exécuté, rien n'est installé.


AIDE;
    exit(2);
}

if ($tous) {
    foreach (['modules', 'themes', 'langues', ''] as $sd) {
        $motif = $racine . '/extensions/' . ($sd ? $sd . '/' : '') . '*';
        foreach (glob($motif, GLOB_ONLYDIR) ?: [] as $d) {
            if (is_file($d . '/extension.json')) $cibles[] = $d;
        }
    }
    $cibles = array_values(array_unique($cibles));
}

echo "\n";
$ok = 0; $ko = 0;

foreach ($cibles as $dossier) {
    $dossier = rtrim($dossier, '/');
    $nom = basename($dossier);

    if (!is_file($dossier . '/extension.json')) {
        printf("  X  %-30s extension.json introuvable\n", $nom);
        $ko++;
        continue;
    }

    $brut = json_decode((string)file_get_contents($dossier . '/extension.json'), true);
    if (!is_array($brut)) {
        printf("  X  %-30s JSON invalide : %s\n", $nom, json_last_error_msg());
        $ko++;
        continue;
    }

    try {
        // Les références « $ref » sont résolues comme à l'installation : sans
        // cela, un module découpé en plusieurs fichiers serait déclaré invalide
        // ici alors qu'il s'installe très bien.
        if (ExtReferences::contientReference($brut)) {
            $brut = ExtReferences::resoudre($brut,
                ExtReferences::lecteurDossier($dossier));
        }
        $v = ExtSchemaDeclaratif::valider($brut);
    } catch (\Throwable $e) {
        printf("  X  %-30s %s\n", $nom, $e->getMessage());
        $ko++;
        continue;
    }

    $caps = ExtSchemaDeclaratif::capacitesRequises($v);
    printf("  OK %-30s %s\n", $nom, implode(', ', $caps) ?: '(aucune capacite)');
    $ok++;

    if (!$detail) continue;

    foreach ($v['donnees'] ?? [] as $jn => $jeu) {
        printf("       jeu « %s » — %d champ(s)\n", $jn, count($jeu['champs']));
        foreach ($jeu['champs'] as $cn => $c) {
            $notes = [];
            if (!empty($c['obligatoire'])) $notes[] = 'obligatoire';
            if (!empty($c['liste']))       $notes[] = 'liste ' . $c['liste'];
            if (!empty($c['modifiable']))  $notes[] = 'modifiable';
            printf("         - %-18s %-14s %s\n", $cn, $c['type'], implode(', ', $notes));
        }
    }
    foreach ($v['pages'] ?? [] as $pg) {
        printf("       ecran « %s » — %s — roles proposes : %s\n",
            $pg['titre'], $pg['vue']['type'], implode(', ', $pg['roles']));
    }
    foreach ($v['ancrages'] ?? [] as $a) {
        printf("       ancrage %s dans %s\n", $a['type'], $a['emplacement']);
    }
    foreach ($v['fichiers'] ?? [] as $n => $f) {
        printf("       dossier « %s » — %s%s\n", $n,
            implode(', ', $f['natures']), $f['import'] ? ' (import ouvert)' : '');
    }
    foreach ($v['langues'] ?? [] as $code => $t) {
        printf("       langue %s — %d libelle(s)\n", $code, count($t));
    }
    echo "\n";
}

echo "\n";
printf("  %d module(s) valide(s)%s\n", $ok, $ko ? ", $ko en echec" : '');
echo "\n";
exit($ko === 0 ? 0 : 1);

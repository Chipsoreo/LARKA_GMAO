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
 * Larka — Reprise des extensions vers l'arborescence par client
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * POURQUOI CE SCRIPT
 * Le code des extensions, leur état d'installation, les thématiques et les
 * images de fond vivaient dans des dossiers UNIQUES, partagés par tous les
 * clients d'un même serveur. Installer une extension chez l'un l'installait
 * donc chez tous.
 *
 * Ils vivent désormais sous « tenants/<client>/ ». Conséquence pour une
 * installation multi-tenant existante : les anciens dossiers ne sont plus lus,
 * et chaque client se retrouve sans aucune extension.
 *
 * Ce script recopie l'existant vers chaque client, une fois.
 *
 * CE QU'IL NE FAIT PAS
 * Il ne DÉPLACE rien et n'efface rien : les dossiers d'origine restent en
 * place. Une reprise qui détruit sa source ne se rejoue pas, et ne se vérifie
 * qu'une fois qu'il est trop tard. Le ménage se fait à la main, après contrôle.
 *
 * Il n'écrase aucun dossier client déjà présent : si un client a déjà ses
 * extensions, c'est qu'il a été repris ou qu'il les a installées lui-même.
 *
 * LES DONNÉES NE SONT PAS CONCERNÉES. Les tables d'un module vivent dans la
 * base du client, qui n'a pas bougé : une extension reprise retrouve ses
 * enregistrements.
 *
 * UTILISATION
 *   php outils/maintenance/reprise-multitenant.php            simulation, n'écrit rien
 *   php outils/maintenance/reprise-multitenant.php --appliquer
 *   php outils/maintenance/reprise-multitenant.php --appliquer --client=acme
 * ═══════════════════════════════════════════════════════════════════════════════
 */

// Ligne de commande uniquement. Tous les autres scripts du projet portent cette
// garde ; celui-ci ne l'avait pas, et rien au niveau du serveur web ne bloquait
// « scripts/ » — il était donc exécutable par une simple requête HTTP. Sans
// $argv, il serait parti en simulation, mais il aurait chargé la configuration
// et affiché l'arborescence des clients à qui la demandait.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Deux niveaux : ce script vit dans outils/<famille>/, pas à la racine.
$racine = dirname(__DIR__, 2);
require_once $racine . '/api/config.php';

$appliquer = in_array('--appliquer', $argv, true);
$seulement = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--client=')) $seulement = substr($a, 9);
}

echo "\n  Larka — reprise des extensions vers l'arborescence par client\n";
echo "  ───────────────────────────────────────────────────────────────\n";
if (!$appliquer) {
    echo "  MODE SIMULATION — rien ne sera écrit.\n";
    echo "  Ajoutez --appliquer pour exécuter.\n";
}
echo "\n";

// ── Les clients ─────────────────────────────────────────────────────────────
if (!class_exists('TenantResolver') || !TenantResolver::isMultiTenant()) {
    echo "  Cette installation n'est pas multi-tenant : les chemins n'ont pas\n";
    echo "  changé et il n'y a rien à reprendre.\n\n";
    exit(0);
}

$clients = [];
foreach (TenantResolver::getAllTenants() as $t) {
    $cle = $t['key'] ?? $t['Cle'] ?? null;
    if (is_string($cle) && $cle !== '') $clients[] = $cle;
}
if ($seulement !== null) {
    $clients = array_values(array_filter($clients, fn($c) => $c === $seulement));
    if ($clients === []) {
        fwrite(STDERR, "  ✗ Client « $seulement » inconnu.\n\n");
        exit(2);
    }
}
if ($clients === []) {
    echo "  Aucun client déclaré. Rien à faire.\n\n";
    exit(0);
}
printf("  %d client(s) : %s\n\n", count($clients), implode(', ', $clients));

// ── Ce qu'on reprend ────────────────────────────────────────────────────────
// Source et destination sont données SÉPARÉMENT, toutes deux à partir de la
// racine du projet. Les composer en accrochant un suffixe à la source produisait
// « data/extensions/code/tenants/acme/code » : le dossier des clients n'est pas
// au même niveau que celui qu'on recopie.
$sources = [
    ['data/extensions/code',    'data/extensions/tenants/%s/code',    'code déplié des modules'],
    ['data/extensions/paquets', 'data/extensions/tenants/%s/paquets', 'paquets reçus'],
    ['data/thematiques',        'data/thematiques/tenants/%s',        'thèmes et packs de langue'],
    ['data/plans',              'data/plans/tenants/%s',              'images de plan'],
];

// « data/fonds » n'est PAS repris : il est désormais découpé par utilisateur
// autant que par client, et rien ne dit à quel compte appartenait telle image
// dans l'ancien dossier commun. Les recopier chez tout le monde donnerait à
// chacun les fonds d'écran des autres — exactement ce que le découpage corrige.
// Les anciens fichiers restent en place ; chacun redépose le sien.

$copies = 0; $ignores = 0; $absents = 0;

foreach ($sources as [$rel, $motif, $libelle]) {
    $src = $racine . '/' . $rel;
    if (!is_dir($src) || copierEntrees($src, null, false) === 0) {
        printf("  ·  %-26s source absente ou vide\n", $libelle);
        $absents++;
        continue;
    }
    printf("  ─  %s\n", $libelle);

    foreach ($clients as $cle) {
        $dst = $racine . '/' . sprintf($motif,
            preg_replace('/[^A-Za-z0-9_\-]/', '_', $cle));
        if (is_dir($dst) && copierEntrees($dst, null, false) > 0) {
            printf("     ·  %-14s déjà repris, laissé tel quel\n", $cle);
            $ignores++;
            continue;
        }
        $n = $appliquer ? copierEntrees($src, $dst, true) : copierEntrees($src, null, false);
        printf("     %s  %-14s %d élément(s)%s\n",
            $appliquer ? '✓' : '→', $cle, $n, $appliquer ? '' : ' (simulation)');
        $copies++;
    }
}

// ── L'état d'installation, fichier unique à la racine ───────────────────────
$etat = $racine . '/data/extensions/etat.json';
if (is_file($etat)) {
    printf("\n  ─  état d'installation\n");
    foreach ($clients as $cle) {
        $sain = preg_replace('/[^A-Za-z0-9_\-]/', '_', $cle);
        $dst  = $racine . '/data/extensions/tenants/' . $sain . '/etat.json';
        if (is_file($dst)) {
            printf("     ·  %-14s déjà repris\n", $cle);
            continue;
        }
        if ($appliquer) {
            @mkdir(dirname($dst), 0750, true);
            @copy($etat, $dst);
        }
        printf("     %s  %-14s etat.json\n", $appliquer ? '✓' : '→', $cle);
    }
} else {
    printf("\n  ·  état d'installation      absent\n");
}

echo "\n  ───────────────────────────────────────────────────────────────\n";
if ($appliquer) {
    printf("  Terminé. %d reprise(s), %d déjà en place, %d source(s) absente(s).\n",
        $copies, $ignores, $absents);
    echo "\n  Vérifiez l'écran Modules de chaque client, PUIS supprimez à la main :\n";
    echo "    data/extensions/code/  data/extensions/paquets/  data/extensions/etat.json\n";
    echo "  (les sous-dossiers « tenants/ » sont à conserver)\n";
} else {
    echo "  Simulation terminée. Relancez avec --appliquer.\n";
}
echo "\n";

/**
 * Copie le contenu d'un dossier, en sautant « tenants ».
 *
 * Sans cette exclusion, la reprise se recopierait elle-même : le dossier des
 * clients est à l'intérieur de la source, et chaque passage l'imbriquerait un
 * niveau plus profond.
 *
 * @param  bool $ecrire false pour compter sans rien écrire (simulation)
 * @return int  nombre d'éléments concernés
 */
function copierEntrees(string $src, ?string $dst, bool $ecrire): int
{
    $n = 0;
    foreach (glob($src . '/*') ?: [] as $chemin) {
        $nom = basename($chemin);
        if ($nom === 'tenants') continue;
        $n++;
        if (!$ecrire || $dst === null) continue;
        if (!is_dir($dst)) @mkdir($dst, 0750, true);
        if (is_dir($chemin)) copierRecursif($chemin, $dst . '/' . $nom);
        else                 @copy($chemin, $dst . '/' . $nom);
    }
    return $n;
}

function copierRecursif(string $src, string $dst): void
{
    if (!is_dir($dst)) @mkdir($dst, 0750, true);
    foreach (scandir($src) ?: [] as $e) {
        if ($e === '.' || $e === '..') continue;
        is_dir("$src/$e") ? copierRecursif("$src/$e", "$dst/$e")
                          : @copy("$src/$e", "$dst/$e");
    }
}

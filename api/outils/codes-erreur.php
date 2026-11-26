<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka — codes d'erreur, en ligne de commande.
 *
 *   php api/outils/codes-erreur.php                 la liste des codes
 *   php api/outils/codes-erreur.php CXR-002         la fiche d'un code : explication, causes, marche à suivre
 *   php api/outils/codes-erreur.php --message="…"   le code qu'aurait un message (« database is locked »…)
 *   php api/outils/codes-erreur.php --journal[=N]   les incidents des N derniers jours (7), par code, d'après
 *                                                   les journaux texte data/logs/journal-*.jsonl
 *   php api/outils/codes-erreur.php --md            régénère Documentations/CODES-ERREUR.md
 *   php api/outils/codes-erreur.php --verifier      contrôle le catalogue (épreuves)
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$racine = dirname(__DIR__, 2);
require_once "$racine/api/CodesErreur.php";

$args = array_slice($argv, 1);
$opt = [];
$pos = [];
foreach ($args as $a) {
    if (preg_match('/^--([a-z]+)(?:=(.*))?$/s', $a, $m)) $opt[$m[1]] = $m[2] ?? true; else $pos[] = $a;
}

function fiche_texte(array $f): string
{
    $t = "{$f['code']} — {$f['titre']}\n" . str_repeat('─', 72) . "\n"
       . "Gravité : {$f['gravite']} · Domaine : {$f['domaine_libelle']} · À traiter par : {$f['qui']}\n\n"
       . wordwrap($f['description'], 76) . "\n\nCauses probables :\n";
    foreach ($f['causes'] as $c) $t .= '  • ' . wordwrap($c, 72, "\n    ") . "\n";
    $t .= "\nComment y remédier :\n";
    foreach ($f['remedes'] as $i => $r) $t .= '  ' . ($i + 1) . '. ' . wordwrap(str_replace('`', '', $r), 72, "\n     ") . "\n";
    return $t . "\nPour aller plus loin : {$f['doc']}\n";
}

/** Le document de référence, tiré du catalogue (jamais écrit à la main). */
function document_md(): string
{
    $md = "# Larka — codes d'erreur\n\n"
        . "> Document généré par `php api/outils/codes-erreur.php --md` à partir de `api/CodesErreur.php` : ne pas le modifier à la main.\n\n"
        . "Chaque incident du journal (écran **Journal**, onglet **Connexions → Journal** de la supervision, fichiers\n"
        . "`data/logs/activite-*.txt`) et chaque erreur affichée à l'écran porte un **code** : trois lettres de domaine,\n"
        . "trois chiffres. Un clic sur le code ouvre sa fiche ; en ligne de commande : `php api/outils/codes-erreur.php CODE`.\n"
        . "Un code ne change jamais de sens.\n\n"
        . "| Domaine | | Domaine | |\n| --- | --- | --- | --- |\n";
    $d = array_chunk(CodesErreur::DOMAINES, 2, true);
    foreach ($d as $paire) {
        $c = [];
        foreach ($paire as $k => $v) { $c[] = "`$k`"; $c[] = $v; }
        $md .= '| ' . implode(' | ', array_pad($c, 4, '')) . " |\n";
    }
    $md .= "\n## Index\n\n| Code | Titre | Gravité | Qui agit |\n| --- | --- | --- | --- |\n";
    foreach (CodesErreur::catalogue() as $f) $md .= "| [`{$f['code']}`](#" . strtolower($f['code']) . ") | {$f['titre']} | {$f['gravite']} | {$f['qui']} |\n";
    $dom = '';
    foreach (CodesErreur::catalogue() as $f) {
        if ($f['domaine'] !== $dom) { $dom = $f['domaine']; $md .= "\n## $dom — {$f['domaine_libelle']}\n"; }
        $md .= "\n### " . $f['code'] . "\n\n**{$f['titre']}** — gravité *{$f['gravite']}*, à traiter par : *{$f['qui']}*.\n\n{$f['description']}\n\n**Causes probables**\n\n";
        foreach ($f['causes'] as $c) $md .= "- $c\n";
        $md .= "\n**Comment y remédier**\n\n";
        foreach ($f['remedes'] as $i => $r) $md .= ($i + 1) . ". $r\n";
        $md .= "\nPour aller plus loin : {$f['doc']}.\n";
    }
    return $md;
}

if (isset($opt['verifier'])) {
    $pb = CodesErreur::verifier();
    echo $pb ? implode("\n", $pb) . "\n" : count(CodesErreur::CATALOGUE) . " codes, catalogue cohérent.\n";
    exit($pb ? 1 : 0);
}
if (isset($opt['md'])) {
    $f = "$racine/Documentations/CODES-ERREUR.md";
    file_put_contents($f, document_md());
    echo "Écrit : Documentations/CODES-ERREUR.md (" . count(CodesErreur::CATALOGUE) . " codes)\n";
    exit(0);
}
if (isset($opt['contenu'])) { echo document_md(); exit(0); }                 // épreuves : le document attendu
if (isset($opt['message'])) {
    $m = (string)$opt['message'];
    $c = CodesErreur::reconnaitre($m, (string)($opt['canal'] ?? 'connect'), 'error');
    if (!$c) { echo "Aucun code reconnu pour ce message.\n"; exit(1); }
    echo fiche_texte(CodesErreur::fiche($c));
    exit(0);
}
if (isset($opt['journal'])) {
    $jours = max(1, (int)($opt['journal'] === true ? 7 : $opt['journal']));
    $par = []; $exemples = [];
    for ($i = 0; $i < $jours; $i++) {
        $f = "$racine/data/logs/journal-" . date('Y-m-d', time() - 86400 * $i) . '.jsonl';
        $h = @fopen($f, 'rb');
        if (!$h) continue;
        while (($l = fgets($h)) !== false) {
            $e = json_decode($l, true);
            if (!is_array($e) || empty($e['Code']) || !in_array($e['Niveau'] ?? '', ['warning', 'error', 'critique'], true)) continue;
            $par[$e['Code']] = ($par[$e['Code']] ?? 0) + 1;
            $exemples[$e['Code']] = ($e['DateHeure'] ?? '') . '  ' . mb_substr((string)($e['Message'] ?? ''), 0, 90);
        }
        fclose($h);
    }
    if (!$par) { echo "Aucun incident codé sur $jours jour(s).\n"; exit(0); }
    arsort($par);
    echo "Incidents des $jours derniers jours, par code :\n\n";
    foreach ($par as $c => $n) {
        $f = CodesErreur::fiche($c);
        printf("  %-8s %5d  %s\n           dernier : %s\n           à faire : %s\n", $c, $n, $f['titre'] ?? '?', $exemples[$c],
               str_replace('`', '', (string)($f['remedes'][0] ?? '')));
    }
    echo "\nFiche complète : php api/outils/codes-erreur.php CODE\n";
    exit(0);
}
if ($pos) {
    $f = CodesErreur::fiche(strtoupper($pos[0]));
    if (!$f) { fwrite(STDERR, "Code « {$pos[0]} » inconnu.\n"); exit(1); }
    echo fiche_texte($f);
    exit(0);
}
$dom = '';
foreach (CodesErreur::catalogue() as $f) {
    if ($f['domaine'] !== $dom) { $dom = $f['domaine']; echo "\n{$f['domaine']} — {$f['domaine_libelle']}\n"; }
    printf("  %-8s %-62s %s\n", $f['code'], $f['titre'], $f['gravite']);
}
echo "\nFiche d'un code : php api/outils/codes-erreur.php CODE — depuis un message : --message=\"…\"\n";

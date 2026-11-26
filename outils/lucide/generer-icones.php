<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Génère css/lucide.css : les icônes Lucide dont Larka se sert.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Chaque icône est une classe CSS : <i class="lk lk-wrench"></i>, que le code
 * écrit avec lk('wrench') (js/icones.js). Le dessin est un masque (le SVG de
 * Lucide en data:), peint de la couleur du texte : l'icône suit la taille et la
 * couleur de ce qui l'entoure, sans aucun script.
 *
 * outils/lucide/icones.json liste :
 *   - "icones"  : les icônes embarquées (la bibliothèque complète, 1 600
 *                 icônes, pèserait sur chaque chargement) ;
 *   - "pleines" : celles qui existent aussi en version pleine (lk('circle', 'p')
 *                 pour une pastille) ;
 *   - "pictos"  : les pictogrammes proposés au choix (Plans, Procédures
 *                 d'urgence), avec leur libellé — css/lucide-pictos.css et js/pictos.js ;
 *   - "_teintes": les familles de couleur des styles « Colorées » et « Duotone »
 *                 du menu Interface (énergie ambre, eau bleu…).
 * Pour ajouter une icône : l'écrire dans « icones », relancer ce script.
 * Noms : https://lucide.dev/icons.
 *
 * Source : le paquet npm « lucide-static » (licence ISC), hors du dépôt :
 *   npm i lucide-static          (dans un dossier de travail quelconque)
 *   php outils/lucide/generer-icones.php chemin/vers/node_modules/lucide-static
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI uniquement.\n"); }

$racine = dirname(__DIR__, 2);
$source = rtrim((string)($argv[1] ?? ''), '/');
if ($source === '' || !is_file("$source/icon-nodes.json") || !is_file("$source/package.json")) {
    fwrite(STDERR, "Usage : php outils/lucide/generer-icones.php chemin/vers/node_modules/lucide-static\n");
    exit(1);
}
$version = (string)(json_decode((string)file_get_contents("$source/package.json"), true)['version'] ?? '?');
$noeuds = json_decode((string)file_get_contents("$source/icon-nodes.json"), true);
$def = json_decode((string)file_get_contents(__DIR__ . '/icones.json'), true);
if (!is_array($noeuds) || !is_array($def)) { fwrite(STDERR, "Fichier JSON illisible.\n"); exit(1); }

$icones = array_values(array_unique((array)($def['icones'] ?? [])));
$pleines = array_values(array_unique((array)($def['pleines'] ?? [])));
$teintes = (array)($def['_teintes'] ?? []);
unset($teintes['_doc']);
sort($icones);

$erreurs = [];
foreach (array_merge($icones, $pleines) as $n) if (!isset($noeuds[$n])) $erreurs[] = "« $n » : icône inconnue de Lucide $version";
foreach ($pleines as $n) if (!in_array($n, $icones, true)) $erreurs[] = "« $n » : dans « pleines » mais pas dans « icones »";
$familleDe = []; $couleurs = [];
foreach ($teintes as $famille => $f) {
    if (!preg_match('/^[a-z][a-z0-9-]*$/', (string)$famille) || !preg_match('/^#[0-9a-f]{3,8}$/i', (string)($f['couleur'] ?? ''))) {
        $erreurs[] = "famille « $famille » : nom (minuscules) ou couleur (#rrggbb) invalide"; continue;
    }
    $couleurs[$famille] = strtolower($f['couleur']);
    foreach ((array)($f['icones'] ?? []) as $n) {
        if (!in_array($n, $icones, true)) { $erreurs[] = "famille « $famille » : « $n » n'est pas dans « icones »"; continue; }
        if (isset($familleDe[$n])) { $erreurs[] = "« $n » est dans deux familles ({$familleDe[$n]}, $famille)"; continue; }
        $familleDe[$n] = $famille;
    }
}
if ($erreurs) { fwrite(STDERR, implode("\n", $erreurs) . "\n"); exit(1); }

/** SVG de l'icône en data: (au trait, ou plein), pour un masque CSS. */
$uri = function (string $nom, bool $plein) use ($noeuds): string {
    $corps = '';
    foreach ($noeuds[$nom] as [$balise, $attrs]) {
        $corps .= '<' . $balise;
        foreach ($attrs as $k => $v) $corps .= ' ' . $k . "='" . htmlspecialchars((string)$v, ENT_QUOTES | ENT_XML1) . "'";
        $corps .= '/>';
    }
    $svg = "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='" . ($plein ? 'black' : 'none') . "' stroke='black' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'>$corps</svg>";
    // Encodage minimal (lisible, et plus court que base64).
    return 'url("data:image/svg+xml,' . strtr(rawurlencode($svg), ['%20' => ' ', '%3D' => '=', '%3A' => ':', '%2F' => '/', '%27' => "'", '%2C' => ',', '%3B' => ';']) . '")';
};

$css = "/* Lucide $version — https://lucide.dev — ISC License, Copyright (c) Lucide Contributors (licenses/ISC.txt).\n"
     . " * Généré par outils/lucide/generer-icones.php : ne pas modifier à la main.\n"
     . " * <i class=\"lk lk-NOM\"> (écrit par lk('NOM'), js/icones.js) : l'icône, au trait, de la couleur du texte.\n"
     . " * Variantes : lk-p (pleine), lk-ok / lk-ko / lk-warn (statut), lk-rouge, lk-vert… (pastilles). */\n\n"
     . ".lk{display:inline-block;position:relative;width:1.15em;height:1.15em;vertical-align:-.2em;flex-shrink:0;font-style:normal;line-height:1;"
     // Nom inconnu : rien (et non un carré plein). Chaque .lk-NOM remplace ces valeurs.
     . "--lk:linear-gradient(transparent,transparent);--lkf:linear-gradient(transparent,transparent)}\n"
     // Trait : ::after. Intérieur teinté (style Duotone) : ::before, invisible sinon.
     . ".lk::after,.lk::before{content:'';position:absolute;inset:0;background:currentColor;pointer-events:none}\n"
     . ".lk::after{-webkit-mask:var(--lk) center/contain no-repeat;mask:var(--lk) center/contain no-repeat}\n"
     . ".lk::before{opacity:var(--lki-fond,0);-webkit-mask:var(--lkf) center/contain no-repeat;mask:var(--lkf) center/contain no-repeat}\n\n";
foreach ($icones as $n) {
    $css .= ".lk-$n{--lk:" . $uri($n, false) . (isset($familleDe[$n]) ? ';--lkf:' . $uri($n, true) : '') . "}\n";
}
$css .= "\n/* Pleines (pastilles, étoiles) */\n";
foreach ($pleines as $n) $css .= ".lk-p.lk-$n{--lk:" . $uri($n, true) . "}\n";

$css .= "\n/* Statuts et pastilles : couleur fixe, quel que soit le style choisi */\n"
     . ".lk.lk-ok{color:var(--teal,#1ab394)}.lk.lk-ko{color:var(--red,#e74c3c)}.lk.lk-warn{color:var(--orange,#f5a623)}\n"
     . ".lk.lk-rouge{color:#e74c3c}.lk.lk-vert{color:#1ab394}.lk.lk-bleu{color:#2b7be6}.lk.lk-jaune{color:#eab308}"
     . ".lk.lk-orange{color:#f5a623}.lk.lk-violet{color:#8b5cf6}.lk.lk-gris{color:#cbd5e1}\n";

// Styles « Colorées » et « Duotone » (Interface → Icônes, attribut data-icones de <html>).
// Variables posées sur l'élément qui porte data-icones : le plus proche l'emporte
// (l'aperçu du menu Interface montre chaque style quel que soit celui de la page).
$vars = fn($v) => implode(';', array_map(fn($f) => "--lki-$f:" . ($v ?? $couleurs[$f]), array_keys($couleurs)));
$css .= "\n/* Styles Simples / Colorées / Duotone */\n"
     . '[data-icones="couleur"],[data-icones="duotone"]{' . $vars(null) . "}\n"
     . '[data-icones="simple"]{' . $vars('initial') . "}\n"
     . "[data-icones=\"duotone\"]{--lki-fond:.22}[data-icones=\"couleur\"],[data-icones=\"simple\"]{--lki-fond:0}\n";
foreach ($familleDe as $n => $f) $css .= ".lk-$n{color:var(--lki-$f,currentColor)}\n";
// Sur un fond vif (bouton, onglet actif, notification) : couleur du texte (data-n, posé par js/icones.js).
$css .= ".lk.lk[data-n]{color:inherit}\n"
     . "@media print{.lk{color:#000 !important}}\n";

file_put_contents("$racine/css/lucide.css", $css);

// ── Pictogrammes au choix de l'utilisateur (Plans, Procédures d'urgence) ────
// Une feuille à part, chargée par les seuls écrans qui en affichent
// (LarkaIcones.chargerPictos) : 150 icônes de plus sur chaque page, pour deux
// écrans, ce serait faire payer tout le monde. Le catalogue — libellés
// français compris, pour la recherche — est écrit dans js/pictos.js : une
// seule source, ce fichier-ci, pour la feuille et pour le sélecteur.
$pictos = (array)($def['pictos'] ?? []);
unset($pictos['_doc']);
$catalogue = []; $cssPictos = '';
$vusPictos = [];
foreach ($pictos as $cat => $liste) {
    $entrees = [];
    foreach ((array)$liste as $e) {
        [$n, $lib] = [(string)($e[0] ?? ''), (string)($e[1] ?? '')];
        if (!isset($noeuds[$n])) { fwrite(STDERR, "pictos « $cat » : « $n » inconnue de Lucide $version\n"); exit(1); }
        if (isset($vusPictos[$n])) { fwrite(STDERR, "pictos : « $n » listée deux fois\n"); exit(1); }
        $vusPictos[$n] = true;
        $entrees[] = [$n, $lib];
        if (!in_array($n, $icones, true)) $cssPictos .= ".lk-$n{--lk:" . $uri($n, false) . "}\n";
    }
    $catalogue[] = ['categorie' => (string)$cat, 'icones' => $entrees];
}
// ── Bibliothèque complète, pour le sélecteur ─────────────────────────────────
// Toutes les icônes de Lucide (≈ 2 000) : mots-clés et dessin. Ce fichier n'est
// lu qu'à l'ouverture du sélecteur d'icône (≈ 750 Ko, ≈ 160 Ko compressé, puis
// en cache) ; une icône choisie dedans est enregistrée par l'organisation
// (action icones_choisies), et seul SON dessin est ensuite servi à tous.
$tags = is_file("$source/tags.json") ? (array)json_decode((string)file_get_contents("$source/tags.json"), true) : [];
$complet = [];
foreach ($noeuds as $n => $elements) {
    if (!preg_match('/^[a-z0-9-]+$/', (string)$n)) continue;
    $corps = '';
    foreach ($elements as [$balise, $attrs]) {
        $corps .= '<' . $balise;
        foreach ($attrs as $k => $v) $corps .= ' ' . $k . '="' . htmlspecialchars((string)$v, ENT_QUOTES | ENT_XML1) . '"';
        $corps .= '/>';
    }
    $complet[$n] = [array_values(array_filter((array)($tags[$n] ?? []), 'is_string')), $corps];
}
ksort($complet);
file_put_contents("$racine/js/lucide-complet.json", json_encode(['version' => $version, 'icones' => $complet], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$embarquees = array_values(array_unique(array_merge($icones, array_keys($vusPictos ?? []))));

if ($catalogue) {
    file_put_contents("$racine/css/lucide-pictos.css",
        "/* Lucide $version — https://lucide.dev — ISC License, Copyright (c) Lucide Contributors (licenses/ISC.txt).\n"
        . " * Pictogrammes au choix de l'utilisateur. Généré par outils/lucide/generer-icones.php : ne pas modifier à la main.\n"
        . " * Complète css/lucide.css (qui porte .lk et les icônes déjà embarquées). */\n" . $cssPictos);
    file_put_contents("$racine/js/pictos.js",
        "/* Généré par outils/lucide/generer-icones.php (outils/lucide/icones.json, « pictos ») : ne pas modifier à la main.\n"
        . " * Pictogrammes Lucide proposés par le sélecteur d'icône (js/icone-picker.js) : [nom, libellé]. */\n"
        . "window.LARKA_PICTOS = [\n" . implode(",\n", array_map(fn($c) => json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $catalogue)) . "\n];\n"
        . "/* Icônes déjà dessinées par css/lucide.css et css/lucide-pictos.css. Les autres, choisies dans la bibliothèque\n"
        . " * complète (js/lucide-complet.json), sont servies à la demande par l'action icones_choisies. */\n"
        . "window.LARKA_ICONES_EMBARQUEES = " . json_encode($embarquees) . ";\n");
    echo '✓ ' . count($vusPictos) . " pictogramme(s) au choix, dont " . substr_count($cssPictos, '.lk-') . " hors de css/lucide.css — css/lucide-pictos.css ("
       . round(strlen($cssPictos) / 1024) . " Ko), js/pictos.js\n";
}
@copy("$source/LICENSE", "$racine/licenses/LUCIDE-LICENSE.txt");
echo '✓ ' . count($icones) . ' icône(s) Lucide ' . $version . ', ' . count($pleines) . ' pleine(s), ' . count($familleDe) . ' colorée(s) en '
   . count($couleurs) . " famille(s) — css/lucide.css (" . round(strlen($css) / 1024) . " Ko)\n";

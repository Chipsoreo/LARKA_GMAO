<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
/**
 * Lecture et écriture du fichier de traduction unique : lang/traductions.json.
 *
 * Un seul fichier pour l'interface, au lieu de trois (catalogue français,
 * traduction anglaise, compléments) qu'il fallait tenir alignés. Seuls les
 * packs de langue (extensions/langues/…) restent des fichiers à part : ce sont
 * eux qu'on installe.
 *
 *   {
 *    "_": "…mode d'emploi…",
 *    "version": "2026-11-05",
 *    "langues": {"fr": "Français", "en": "English"},
 *    "complements": [ "kgCO₂", … ],
 *    "libelles": {
 *     "Texte français affiché": {"en": "English text"},
 *     "Libellé à corriger":     {"fr": "Libellé corrigé", "en": "…"},
 *     "Pas encore traduit":     {},
 *     …
 *    }
 *   }
 *
 *   - libelles : UNE LIGNE PAR LIBELLÉ. La clé est le texte français tel que le
 *     code l'affiche (on ne la modifie jamais) ; la valeur porte une traduction
 *     par langue. « fr » n'y figure que pour corriger un libellé sans toucher
 *     au code. Une langue absente d'une ligne = pas encore traduite (l'écran
 *     garde le français).
 *   - complements : libellés que le code assemble ou reçoit du serveur, et que
 *     la récolte (extraire-libelles.php) ne voit pas ; tenus à la main.
 *
 * Utilisé par extraire-libelles.php, construire-pack.php, verifier-traduction.php
 * (et lu par l'application : api/routes/thematiques.php, js/declaratif.js).
 */

const TRAD_CHEMIN = 'lang/traductions.json';
const TRAD_MODE_EMPLOI = "Fichier de traduction de l'interface de Larka. Une ligne par libellé : la clé est le "
    . "texte français affiché (ne jamais la modifier), la valeur donne une traduction par langue ({\"en\": \"…\"}). "
    . "« fr » sert seulement à corriger un libellé sans toucher au code. Une langue absente = pas encore traduite. "
    . "« complements » liste les libellés assemblés par le code, que la récolte ne voit pas. "
    . "Après un changement : php outils/langues/extraire-libelles.php, puis php outils/langues/construire-pack.php en.";

/** Contenu du fichier, normalisé. */
function trad_lire(string $racine): array
{
    $j = json_decode((string)@file_get_contents("$racine/" . TRAD_CHEMIN), true);
    if (!is_array($j)) $j = [];
    $libelles = [];
    foreach ((array)($j['libelles'] ?? []) as $k => $v) {
        $libelles[(string)$k] = is_array($v) ? array_map('strval', $v) : [];
    }
    return [
        'version'     => (string)($j['version'] ?? date('Y-m-d')),
        'langues'     => (array)($j['langues'] ?? ['fr' => 'Français']),
        'complements' => array_values(array_filter((array)($j['complements'] ?? []), 'is_string')),
        'libelles'    => $libelles,
    ];
}

/** Catalogue français : clé → texte affiché (la correction « fr » si elle existe). */
function trad_catalogue(array $t): array
{
    $cat = [];
    foreach ($t['libelles'] as $k => $e) $cat[$k] = $e['fr'] ?? $k;
    return $cat;
}

/** Une langue : clé → traduction, ou la clé elle-même si la ligne n'est pas traduite. */
function trad_langue(array $t, string $code): array
{
    $out = [];
    foreach ($t['libelles'] as $k => $e) $out[$k] = $e[$code] ?? $k;
    return $out;
}

/** Écrit le fichier : une ligne par libellé, « fr » en tête de chaque ligne. */
function trad_ecrire(string $racine, array $t): void
{
    $j = fn($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $lignes = [];
    foreach ($t['libelles'] as $k => $e) {
        $e = array_filter($e, fn($v, $c) => $v !== '' && !($c === 'fr' && $v === $k), ARRAY_FILTER_USE_BOTH);
        if (isset($e['fr'])) $e = ['fr' => $e['fr']] + $e;
        $lignes[] = '  ' . $j((string)$k) . ': ' . ($e ? $j($e) : '{}');
    }
    $compl = array_map(fn($c) => '  ' . $j($c), $t['complements']);
    $txt = "{\n"
         . ' "_": ' . $j(TRAD_MODE_EMPLOI) . ",\n"
         . ' "version": ' . $j($t['version'] ?? date('Y-m-d')) . ",\n"
         . ' "langues": ' . $j((object)$t['langues']) . ",\n"
         . " \"complements\": [\n" . implode(",\n", $compl) . "\n ],\n"
         . " \"libelles\": {\n" . implode(",\n", $lignes) . "\n }\n}\n";
    if (json_decode($txt, true) === null) throw new RuntimeException('Fichier de traduction invalide (écriture annulée).');
    file_put_contents("$racine/" . TRAD_CHEMIN, $txt);
}

<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
/**
 * Régénère les sections « catalogue » de FORMAT-DECLARATIF-REFERENCE.md à
 * partir du code.
 *
 * Ces sections étaient écrites à la main, et 41 fonctions sur 91 s'y trouvaient
 * sans la moindre description : le nom, rien d'autre. Personne ne s'en rendait
 * compte, parce que le contrôle vérifiait la PRÉSENCE des noms, pas leur
 * utilité.
 *
 * Chaque entrée porte désormais sa description et son exemple dans le code
 * (ExtExpression::AIDE, ExtCondition::AIDE), et ce script les met en tableaux.
 * Une fonction ajoutée sans aide fait échouer l'épreuve.
 *
 *   php Documentations/manuel-source/generer-reference.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit("Ligne de commande uniquement.\n"); }

$racine = dirname(__DIR__, 2);
require_once $racine . '/api/extensions/declaratif/Schema.php';

$GROUPES_FN = [
    'Agrégats' => ['sum','avg','min','max','count','median','unique','sortAsc'],
    'Texte'    => ['length','contains','startsWith','endsWith','lower','upper','trim',
                   'replace','concat','substring','padStart','padEnd','capitalize','title',
                   'initials','slug','repeat','reverse','indexOf','split','lines',
                   'wordCount','stripAccents','mask'],
    'Nombres'  => ['round','floor','ceil','abs','pow','sqrt','mod','clamp','percent',
                   'ratio','sign','roundTo','toNumber','euros'],
    'Dates'    => ['now','today','date','formatDate','addDays','addMonths','addYears',
                   'addHours','addMinutes','diffDays','diffMonths','diffYears','diffHours',
                   'diffMinutes','year','month','day','weekNumber','quarter','daysInMonth',
                   'dayName','monthName','isWeekend','isPast','isFuture','startOfMonth',
                   'endOfMonth','startOfYear','endOfYear','age','workDays'],
    'Logique'  => ['if','not','isEmpty','isNull','coalesce','ifEmpty','switch','between',
                   'first','last','nth','join','anyOf','allOf'],
];

$out = [];
$out[] = '## Fonctions d\'expression';
$out[] = '';
$out[] = 'Utilisables dans un champ `formule`, dans un `defaut` et dans la valeur d\'une';
$out[] = 'condition. ' . count(ExtExpression::FONCTIONS) . ' fonctions. Une fonction absente '
       . 'de cette liste est refusée à';
$out[] = 'l\'installation : elle n\'est jamais cherchée ailleurs dans PHP.';
$out[] = '';

$vus = [];
foreach ($GROUPES_FN as $groupe => $noms) {
    $out[] = '### ' . $groupe;
    $out[] = '';
    $out[] = '| Fonction | Description | Exemple | Résultat |';
    $out[] = '|---|---|---|---|';
    foreach ($noms as $n) {
        if (!isset(ExtExpression::AIDE[$n])) continue;
        [$desc, $ex, $res] = ExtExpression::AIDE[$n];
        [$mini, $maxi] = ExtExpression::FONCTIONS[$n];
        $vus[$n] = true;
        $out[] = sprintf('| `%s` | %s | `%s` | `%s` |', $n, $desc, $ex, $res);
    }
    $out[] = '';
}

// Filet : une fonction oubliée dans les groupes ne doit pas disparaître.
$orphelines = array_diff(array_keys(ExtExpression::AIDE), array_keys($vus));
if ($orphelines !== []) {
    $out[] = '### Autres';
    $out[] = '';
    $out[] = '| Fonction | Description | Exemple | Résultat |';
    $out[] = '|---|---|---|---|';
    foreach ($orphelines as $n) {
        [$desc, $ex, $res] = ExtExpression::AIDE[$n];
        $out[] = sprintf('| `%s` | %s | `%s` | `%s` |', $n, $desc, $ex, $res);
    }
    $out[] = '';
}

$out[] = '### Opérateurs et références';
$out[] = '';
$out[] = '* Arithmétique : `+` `-` `*` `/` `%`';
$out[] = '* Comparaison : `==` `!=` `>` `<` `>=` `<=`';
$out[] = '* Logique : `&&` `||` `!`, parenthèses';
$out[] = '* Références : `record.champ`, `user.nom`, `today`, `variables.x`';
$out[] = '* Une division par zéro rend une valeur vide, pas une erreur.';
$out[] = '';
$out[] = '### Bornes';
$out[] = '';
$out[] = '* 1000 caractères, 20 niveaux d\'imbrication, 300 éléments par expression.';
$out[] = '* Une formule est un calcul de tableur, pas un programme.';
$out[] = '';
$out[] = '---';
$out[] = '';

// ── Conditions ──────────────────────────────────────────────────────────────
$out[] = '## Composants de condition';
$out[] = '';
$out[] = 'Une seule grammaire, employée par `visible_si`, `surligner` et les filtres.';
$out[] = count(ExtCondition::OPERATEURS) . ' opérateurs. Un opérateur absent de cette liste '
       . 'est refusé à l\'installation.';
$out[] = '';
$out[] = '### Groupes';
$out[] = '';
$out[] = '* `tous` — toutes les clauses vraies';
$out[] = '* `au_moins_un` — au moins une clause vraie';
$out[] = '* `aucun` — aucune clause vraie';
$out[] = '* Imbrication jusqu\'à 10 niveaux, 50 clauses par groupe.';
$out[] = '';
$out[] = '### Opérateurs';
$out[] = '';
$out[] = '| Opérateur | Valeur | Description | Exemple |';
$out[] = '|---|---|---|---|';
foreach (ExtCondition::AIDE as $op => [$desc, $ex]) {
    $out[] = sprintf('| `%s` | %s | %s | %s |', $op,
        ExtCondition::OPERATEURS[$op] ? 'oui' : '—', $desc, $ex);
}
$out[] = '';
$out[] = '### Comportement de `a_change`';
$out[] = '';
$out[] = '* Rend **faux** à la création : rien n\'a changé sur un enregistrement qui vient';
$out[] = '  de naître. L\'inverse déclencherait toutes les règles de modification sur';
$out[] = '  chaque nouvelle ligne.';
$out[] = '';
$out[] = '### Valeurs dynamiques';
$out[] = '';
$out[] = '* La `valeur` d\'une clause accepte une expression : `"{{today}}"`.';
$out[] = '* Compilée à l\'installation, comme une formule.';
$out[] = '';
$out[] = '---';
$out[] = '';

// ── Formats de saisie ───────────────────────────────────────────────────────
$out[] = '## Formats de saisie';
$out[] = '';
$out[] = '| Nom | Vérifie | Exemple |';
$out[] = '|---|---|---|';
foreach (ExtSchemaDeclaratif::FORMATS_SAISIE as $n => $f) {
    $out[] = sprintf('| `%s` | %s | `%s` |', $n, $f['libelle'], $f['exemple']);
}
$out[] = '';
$out[] = '## Couleurs';
$out[] = '';
$out[] = implode(' · ', array_map(fn($c) => '`' . $c . '`',
                 array_keys(ExtSchemaDeclaratif::COULEURS)));
$out[] = '';
$out[] = '## Cibles de lien';
$out[] = '';
$out[] = '| Cible | Désigne | Affiché |';
$out[] = '|---|---|---|';
foreach (ExtSchemaDeclaratif::CIBLES_LIEN as $n => $c) {
    $out[] = sprintf('| `%s` | %s | %s |', $n, $c['libelle'], implode(', ', $c['affichage']));
}
$out[] = '';

$catalogues = implode("\n", $out);

$fichier = $racine . '/Documentations/FORMAT-DECLARATIF-REFERENCE-CATALOGUES.md';
file_put_contents($fichier, "# Catalogues du format déclaratif\n\n"
    . "Généré depuis le code : ne pas modifier à la main.\n\n"
    . $catalogues);

printf("✓ %s — %d lignes\n", basename($fichier), count($out));

/**
 * ── La référence complète ────────────────────────────────────────────────
 *
 * Elle est l'assemblage de deux moitiés qui n'ont pas la même nature :
 *
 *   SPEC.md     écrit à la main. Ce qu'on ne peut pas déduire du code : le
 *               pourquoi d'une règle, la forme d'une déclaration, les pièges.
 *   catalogues  produits ici. Ce qu'il serait absurde de recopier : 91
 *               fonctions, 33 opérateurs, les formats et les couleurs.
 *
 * Les tenir séparés puis les assembler évite le défaut qui a fait naître ce
 * script : une référence à moitié recopiée à la main, qui dérive du code sans
 * que personne s'en aperçoive. Ici, la moitié dérivable ne peut pas dériver —
 * elle est réécrite à chaque génération.
 *
 * `outils/epreuves/test-reference.php` vérifie ensuite que le résultat cite
 * bien tout ce que le code expose, et rien qui n'existe pas.
 */
$spec = $racine . '/Documentations/FORMAT-DECLARATIF-REFERENCE-SPEC.md';
if (is_file($spec)) {
    $reference = $racine . '/Documentations/FORMAT-DECLARATIF-REFERENCE.md';
    file_put_contents($reference,
        rtrim((string)file_get_contents($spec)) . "\n\n---\n\n" . $catalogues . "\n");
    printf("✓ %s — spécification + catalogues\n", basename($reference));
} else {
    fwrite(STDERR, "⚠ " . basename($spec) . " introuvable : référence non assemblée.\n");
}

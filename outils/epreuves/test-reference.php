<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
/**
 * Larka — la référence dit-elle vrai ?
 *
 * FORMAT-DECLARATIF-REFERENCE.md décrit chaque composant du format. Une
 * référence fausse est pire qu'une référence absente : on la croit, on écrit
 * une déclaration contre elle, et l'installation échoue sur un nom qui
 * n'existe pas.
 *
 * Cette épreuve extrait les identifiants cités par le document et vérifie
 * qu'ils existent dans le code — et l'inverse : qu'aucun composant du code
 * n'est absent du document.
 *
 *   php outils/epreuves/test-reference.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit("Ligne de commande uniquement.\n"); }

$racine = dirname(__DIR__, 2);
require_once $racine . '/api/extensions/declaratif/Schema.php';
require_once $racine . '/api/extensions/declaratif/Expression.php';
require_once $racine . '/api/extensions/declaratif/Condition.php';
require_once $racine . '/api/extensions/Ancrages.php';

$doc = (string)@file_get_contents($racine . '/Documentations/FORMAT-DECLARATIF-REFERENCE.md');
if ($doc === '') exit("  ✗ Référence introuvable.\n");

$ok = 0; $ko = [];

function verifier(string $famille, array $attendus, string $doc,
                  int &$ok, array &$ko): void
{
    $absents = [];
    foreach ($attendus as $nom) {
        // Cité entre accents graves, sous une forme ou une autre.
        if (!preg_match('/`[^`]*' . preg_quote($nom, '/') . '[^`]*`/u', $doc)) {
            $absents[] = $nom;
        }
    }
    if ($absents === []) {
        $ok++;
        printf("  ✅  %-34s %d élément(s) documenté(s)\n", $famille, count($attendus));
    } else {
        $ko[] = $famille . ' : ' . implode(', ', $absents);
        printf("  ❌  %-34s absent(s) du document : %s\n", $famille, implode(', ', $absents));
    }
}

echo "\n═══════════════════════════════════════════════════════════════════════\n";
echo " Larka — la référence correspond-elle au code ?\n";
echo "═══════════════════════════════════════════════════════════════════════\n\n";

$r = new ReflectionClass('ExtSchemaDeclaratif');

verifier('Types de champ',
    array_merge(array_keys(ExtSchemaDeclaratif::TYPES), ['formule']), $doc, $ok, $ko);
verifier('Cibles de lien',
    array_keys(ExtSchemaDeclaratif::CIBLES_LIEN), $doc, $ok, $ko);
verifier('Champs de traçabilité',
    array_keys(ExtSchemaDeclaratif::CHAMPS_AUDIT), $doc, $ok, $ko);
verifier('Types d\'ancrage',
    array_keys($r->getConstant('ANCRAGES')), $doc, $ok, $ko);
verifier('Emplacements d\'ancrage',
    array_keys(ExtAncrages::catalogue()), $doc, $ok, $ko);
verifier('Fonctions d\'expression',
    array_keys(ExtExpression::FONCTIONS), $doc, $ok, $ko);
verifier('Opérateurs de condition',
    array_keys(ExtCondition::OPERATEURS), $doc, $ok, $ko);

// ── Chaque entrée a-t-elle une DESCRIPTION et un exemple ? ─────────────────
//
// Le contrôle précédent vérifiait la seule PRÉSENCE des noms. Résultat : 41
// fonctions sur 91 figuraient dans la référence sans un mot d'explication —
// le nom, rien d'autre. Le document semblait complet, et ne servait à rien.
echo "\n  Descriptions\n";

$sansAide = [];
foreach (array_keys(ExtExpression::FONCTIONS) as $f) {
    if (!isset(ExtExpression::AIDE[$f])) { $sansAide[] = $f; continue; }
    [$desc, $ex, $res] = ExtExpression::AIDE[$f];
    if (trim($desc) === '' || trim($ex) === '') $sansAide[] = $f;
}
if ($sansAide === []) {
    $ok++;
    printf("  ✅  %-34s %d fonction(s) décrites\n", 'Aide de chaque fonction',
           count(ExtExpression::AIDE));
} else {
    $ko[] = 'fonctions sans aide : ' . implode(', ', $sansAide);
    printf("  ❌  fonctions sans aide : %s\n", implode(', ', array_slice($sansAide, 0, 8)));
}

$sansOp = [];
foreach (array_keys(ExtCondition::OPERATEURS) as $o) {
    if (!isset(ExtCondition::AIDE[$o]) || trim(ExtCondition::AIDE[$o][0]) === '') $sansOp[] = $o;
}
if ($sansOp === []) {
    $ok++;
    printf("  ✅  %-34s %d opérateur(s) décrits\n", 'Aide de chaque opérateur',
           count(ExtCondition::AIDE));
} else {
    $ko[] = 'opérateurs sans aide : ' . implode(', ', $sansOp);
    printf("  ❌  opérateurs sans aide : %s\n", implode(', ', $sansOp));
}

// ── Les exemples donnent-ils le résultat annoncé ? ──────────────────────────
// Un exemple faux est pire qu'une absence d'exemple : on le recopie.
$contexte = ['record' => ['q' => 3, 'rendue' => false, 'detenteur' => '', 'retour' => null,
                          'nom' => 'Dupont', 'surnom' => null, 'n' => 7, 'statut' => 'A',
                          'tags' => ['Urgent', 'Externe'], 'commentaire' => "a\nb\nc",
                          'echeance' => '2026-09-01', 'saisie' => '2026-08-23'],
             'today' => date('Y-m-d')];
$faux = [];
foreach (ExtExpression::AIDE as $nom => [$desc, $ex, $res]) {
    if (str_contains($res, '…') || in_array($res, ['vrai', 'faux'], true)) continue;
    $c = ExtExpression::valider($ex);
    if (!$c['ok']) { $faux[] = "$nom (exemple invalide)"; continue; }
    $v = ExtExpression::evaluer($c['arbre'], $contexte);
    $vs = is_bool($v) ? ($v ? 'vrai' : 'faux') : (string)$v;
    if (rtrim($vs, '.0') !== rtrim($res, '.0') && $vs !== $res) {
        $faux[] = sprintf('%s : %s → %s au lieu de %s', $nom, $ex, $vs, $res);
    }
}
if ($faux === []) {
    $ok++;
    printf("  ✅  %-34s résultats conformes\n", 'Exemples de la référence');
} else {
    $ko[] = 'exemples faux : ' . implode(' | ', $faux);
    foreach (array_slice($faux, 0, 5) as $f) printf("  ❌  %s\n", $f);
}

// ── L'inverse : le document cite-t-il des noms qui n'existent pas ? ─────────
echo "\n  Noms cités par le document\n";
$connus = array_merge(
    array_keys(ExtSchemaDeclaratif::TYPES), ['formule', 'lien'],
    array_keys(ExtSchemaDeclaratif::CIBLES_LIEN),
    array_keys(ExtSchemaDeclaratif::CHAMPS_AUDIT),
    array_keys($r->getConstant('ANCRAGES')),
    array_keys(ExtAncrages::catalogue()),
    array_keys(ExtExpression::FONCTIONS),
    array_keys(ExtCondition::OPERATEURS),
    ['dp_lister', 'dp_enregistrer', 'dp_supprimer', 'dp_exporter', 'dp_ancrage',
     'dp_cibles', 'SUPPRIMER', 'declaratif/1', 'somme', 'compte', 'moyenne',
     'minimum', 'maximum', 'tous', 'au_moins_un', 'aucun']);

// Les composants du format cités en tête de section, sous la forme « Ajouté `x` »
preg_match_all('/^### (?:Ajouté|Modifié|Retiré) `([a-z_]+)`/mu', $doc, $m);
$inconnus = [];
foreach (array_unique($m[1]) as $cite) {
    if (in_array($cite, $connus, true)) continue;
    // Champs de déclaration, vérifiés dans le schéma lui-même
    $schema = (string)file_get_contents($racine . '/api/extensions/declaratif/Schema.php');
    if (str_contains($schema, "'" . $cite . "'")) continue;
    $inconnus[] = $cite;
}
if ($inconnus === []) {
    $ok++;
    printf("  ✅  %-34s tous reconnus par le code\n", count($m[1]) . ' composant(s)');
} else {
    $ko[] = 'cités mais inexistants : ' . implode(', ', $inconnus);
    printf("  ❌  cités mais inexistants : %s\n", implode(', ', $inconnus));
}

$total = $ok + count($ko);
echo "\n───────────────────────────────────────────────────────────────────────\n";
if ($ko === []) {
    printf(" ✅ %d/%d — la référence correspond au code.\n", $ok, $total);
} else {
    printf(" ❌ %d écart(s) sur %d :\n", count($ko), $total);
    foreach ($ko as $e) echo "      • $e\n";
}
echo "───────────────────────────────────────────────────────────────────────\n\n";
exit($ko === [] ? 0 : 1);

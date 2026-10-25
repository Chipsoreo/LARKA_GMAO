<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
/**
 * Larka — épreuve du moteur d'expressions.
 *
 * Le moteur d'expressions est le composant le plus dangereux du format
 * déclaratif : c'est du calcul fourni par l'auteur du pack. Un `eval` déguisé
 * y ramènerait l'exécution de code, et les cinq frontières bâties par ailleurs
 * ne serviraient plus à rien.
 *
 * Cette épreuve vérifie donc deux choses : que le moteur calcule juste, et
 * qu'aucune expression ne peut atteindre PHP.
 *
 *   php outils/epreuves/test-expressions.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit("Ligne de commande uniquement.\n"); }
require_once dirname(__DIR__, 2) . '/api/extensions/declaratif/Expression.php';

$ctx = [
    'record' => ['q' => 4, 'p' => 12.5, 'statut' => 'urgent', 'nom' => '  Vanne  ',
                 'echeance' => '2026-09-15'],
    'user'   => ['nom' => 'mlarcin'],
    'today'  => '2026-08-20',
    'variables' => ['delai' => 30],
];

$ok = 0; $ko = [];

function calcul(string $expr, mixed $attendu, array $ctx, int &$ok, array &$ko): void
{
    $r = ExtExpression::valider($expr);
    $v = $r['ok'] ? ExtExpression::evaluer($r['arbre'], $ctx) : '⛔ ' . $r['erreur'];
    $juste = is_float($attendu) ? (abs((float)$v - $attendu) < 0.001) : ($v === $attendu);
    if ($juste) { $ok++; printf("  ✅  %-46s = %s\n", $expr, var_export($v, true)); }
    else { $ko[] = $expr; printf("  ❌  %-46s = %s (attendu %s)\n", $expr,
           var_export($v, true), var_export($attendu, true)); }
}

function attaque(string $expr, array $ctx, int &$ok, array &$ko): void
{
    $r = ExtExpression::valider($expr);
    if (!$r['ok']) { $ok++; printf("  ✅  %-46s ⛔ refusée\n", $expr); return; }
    $v = ExtExpression::evaluer($r['arbre'], $ctx);
    // Une expression acceptée doit rendre une valeur inoffensive, jamais le
    // résultat d'une commande ni le contenu d'un fichier.
    $fuite = is_string($v) && (str_contains($v, 'uid=') || str_contains($v, 'root:')
             || str_contains($v, '<?php'));
    if ($fuite) { $ko[] = $expr; printf("  ❌  %-46s → FUITE : %s\n", $expr, mb_substr((string)$v, 0, 40)); }
    else { $ok++; printf("  ✅  %-46s → %s (inoffensif)\n", $expr, var_export($v, true)); }
}

echo "\n═══════════════════════════════════════════════════════════════════════\n";
echo " Larka — épreuve du moteur d'expressions\n";
echo "═══════════════════════════════════════════════════════════════════════\n\n";

echo "  Calculs\n";
calcul('record.q * record.p',                       50.0, $ctx, $ok, $ko);
calcul('round(record.q * record.p * 1.2, 2)',       60.0, $ctx, $ok, $ko);
calcul('record.q > 3 && record.p < 20',             true, $ctx, $ok, $ko);
calcul("if(record.statut == 'urgent', 'P', 'N')",   'P',  $ctx, $ok, $ko);
calcul('upper(trim(record.nom))',                   'VANNE', $ctx, $ok, $ko);
calcul('diffDays(record.echeance, today)',          26,   $ctx, $ok, $ko);
calcul('coalesce(record.absent, variables.delai)',  30,   $ctx, $ok, $ko);
calcul('record.q / 0',                              null, $ctx, $ok, $ko);
calcul('isEmpty(record.absent)',                    true, $ctx, $ok, $ko);
calcul('-record.q + 10',                            6.0,  $ctx, $ok, $ko);

echo "\n  Tentatives d'exécution de code\n";
foreach (['system("id")', 'exec("id")', 'shell_exec("id")', 'eval("1")',
          'file_get_contents("/etc/passwd")', 'phpinfo()', 'passthru("id")',
          'call_user_func("system","id")', 'include("/etc/passwd")',
          '1; system("id")', '`id`', '$GLOBALS', 'record["q"]->getPdo()',
          'if(1, system("id"), 0)', 'sum(1)@system("id")',
          'record.constructor', 'user.__class__'] as $a) {
    attaque($a, $ctx, $ok, $ko);
}

echo "\n  Bornes\n";
attaque(str_repeat('1+', 400) . '1', $ctx, $ok, $ko);          // trop de nœuds
attaque(str_repeat('(', 40) . '1' . str_repeat(')', 40), $ctx, $ok, $ko);  // trop profond
attaque(str_repeat('a', 1200), $ctx, $ok, $ko);                 // trop long

$total = $ok + count($ko);
echo "\n───────────────────────────────────────────────────────────────────────\n";
if ($ko === []) {
    printf(" ✅ %d/%d — le moteur calcule juste et n'exécute aucun code.\n", $ok, $total);
} else {
    printf(" ❌ %d échec(s) sur %d :\n", count($ko), $total);
    foreach ($ko as $e) echo "      • $e\n";
}
echo "───────────────────────────────────────────────────────────────────────\n\n";
exit($ko === [] ? 0 : 1);

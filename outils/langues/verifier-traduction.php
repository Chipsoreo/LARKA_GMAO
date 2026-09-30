<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
/**
 * Vérifie un pack de langue contre le catalogue lang/fr.json.
 *
 * Trois défauts sont cherchés, dans cet ordre d'importance :
 *
 *   1. LIBELLÉS MANQUANTS — présents au catalogue, absents du pack. L'écran
 *      reste alors en français au milieu d'une interface traduite.
 *
 *   2. FRANÇAIS RÉSIDUEL — une traduction qui contient encore des mots
 *      français. C'est le pire des trois : « No work order en cours » fait
 *      douter du logiciel lui-même, alors qu'un mot resté en français se voit
 *      et se corrige.
 *
 *   3. NON TRADUITS — valeur identique à la clé. Souvent légitime (« Actions »,
 *      « CO₂ »), parfois un oubli : le contrôle les compte et signale ceux qui
 *      portent un accent ou un mot outil français.
 *
 *   php outils/langues/verifier-traduction.php extensions/larka.langue-en/extension.json
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit("Ligne de commande uniquement.\n"); }

// Deux niveaux : ce script vit dans outils/<famille>/, pas à la racine.
$racine = dirname(__DIR__, 2);
$fichier = $argv[1] ?? '';
if ($fichier === '' || !is_file($fichier)) {
    fwrite(STDERR, "Usage : php outils/langues/verifier-traduction.php <extension.json>\n");
    exit(2);
}

$cat = json_decode((string)file_get_contents($racine . '/lang/fr.json'), true)['libelles'] ?? [];
$pack = json_decode((string)file_get_contents($fichier), true);
$langues = $pack['langues'] ?? [];
if ($cat === [] || $langues === []) {
    fwrite(STDERR, "Catalogue ou pack illisible.\n");
    exit(2);
}

// « de » est retiré de la liste : « SHA-256 de-duplication » le contient, et
// signaler à tort use la confiance dans le contrôle plus vite qu'un oubli.
// Les vrais cas sont attrapés par les autres mots et par l'accent.
$MOTS_FR = '/\b(le|la|les|un|une|des|du|au|aux|pour|dans|sur|avec|par|sans|'
         . 'cette|ces|ce|qui|que|est|sont|aucun|aucune|votre|vos|nous|vous|'
         . 'tout|tous|toutes|ni|ou|en|si|renseigné|catégorie|fiche|matériel|'
         . 'bien|biens|équipement|équipements|demande|intervention)\b/i';
$ACCENT  = '/[àâäéèêëîïôöùûüçÀÂÄÉÈÊËÎÏÔÖÙÛÜÇ]/u';

$echec = false;
foreach ($langues as $code => $dico) {
    $manquants = [];
    foreach (array_keys($cat) as $k) {
        if (!array_key_exists($k, $dico)) $manquants[] = $k;
    }

    // Deux détections, parce qu'une seule ne suffit pas : la liste de mots
    // outils laissait passer « No article trouvé » — un mot français plein,
    // sans article devant. L'ACCENT le rattrape.
    $EXCEPT = '/(Légifrance|Mickaël|Larcin|télétravail|congé|récup|CO₂|kgCO₂|'
            . 'Datagir|ADEME|Français)/u';
    $residuel = [];
    foreach ($dico as $k => $v) {
        if ($k === $v) continue;
        $v = (string)$v;
        if (preg_match($EXCEPT, $v)) continue;
        if (preg_match($MOTS_FR, $v) || preg_match($ACCENT, $v)) $residuel[] = $k;
    }

    // Un libellé ABSENT du pack n'est pas traduit : le compter comme tel
    // annonçait « 99 % » pour un pack qui n'en couvre qu'un quart. Un chiffre
    // faux est pire que pas de chiffre — il fait croire le travail terminé.
    $identiques = [];
    $suspects   = [];
    foreach (array_keys($cat) as $k) {
        if (!array_key_exists($k, $dico)) { $identiques[] = $k; continue; }
        if ($dico[$k] !== $k) continue;
        $identiques[] = $k;
        // Un accent ou un mot outil français : probablement un oubli.
        if (preg_match($ACCENT, $k) || preg_match($MOTS_FR, $k)) $suspects[] = $k;
    }

    $total = count($cat);
    $traduits = $total - count($identiques);
    printf("\n  Langue « %s » — %d/%d traduits (%d %%)\n",
           $code, $traduits, $total, (int)(100 * $traduits / max($total, 1)));

    if ($manquants !== []) {
        $echec = true;
        printf("  ❌ %d libellé(s) manquant(s) : %s\n", count($manquants),
               implode(' | ', array_slice($manquants, 0, 4)));
    } else {
        printf("  ✅ aucun libellé manquant\n");
    }

    if ($residuel !== []) {
        $echec = true;
        printf("  ❌ %d traduction(s) contenant du français :\n", count($residuel));
        foreach (array_slice($residuel, 0, 5) as $k) {
            printf("       %s → %s\n", mb_substr($k, 0, 40), mb_substr($dico[$k], 0, 40));
        }
    } else {
        printf("  ✅ aucun français résiduel\n");
    }

    printf("  ⊘ %d non traduits (dont %d absents), %d à vérifier%s\n",
           count($identiques), count($manquants), count($suspects),
           $suspects === [] ? '' : ' : ' . implode(' | ', array_slice($suspects, 0, 3)));
}

echo "\n";
exit($echec ? 1 : 0);

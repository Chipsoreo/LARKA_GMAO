<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
/**
 * Extrait les libellés de l'interface vers lang/fr.json.
 *
 * Ce fichier est le CATALOGUE DE RÉFÉRENCE : la liste de ce qui est
 * traduisible, avec le texte français d'origine en clé ET en valeur.
 *
 * Il sert trois choses :
 *   1. corriger un libellé sans toucher au code — le JSON est prioritaire ;
 *   2. servir de MODÈLE à un traducteur : il copie le fichier et remplace les
 *      valeurs, sans avoir à deviner ce qui existe ;
 *   3. rester facultatif — supprimé, l'interface retombe sur le français écrit
 *      en dur dans les écrans. Une traduction perdue ne casse pas le produit.
 *
 *   php outils/langues/extraire-libelles.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit("Ligne de commande uniquement.\n"); }

// Deux niveaux : ce script vit dans outils/<famille>/, pas à la racine.
$racine = dirname(__DIR__, 2);
$fichiers = array_merge(
    [$racine . '/index.html'],
    glob($racine . '/js/*.js') ?: [],
    glob($racine . '/js/pages/*.js') ?: []
);

$vus = [];
$ajouter = function (string $t) use (&$vus): void {
    $t = trim($t);
    if ($t === '' || mb_strlen($t) < 2 || mb_strlen($t) > 90) return;
    // Ce qui n'est pas un libellé : fragments de code, nombres, gabarits.
    if (str_contains($t, '${') || str_contains($t, '</') || str_contains($t, '=>')) return;
    if (str_contains($t, '\\u') || str_contains($t, "' +") || str_contains($t, '" +')) return;
    // Fragments de code : une expression JavaScript coupée en deux par la
    // récolte ressemble à un libellé et n'en est pas un. Un catalogue pollué
    // est pire qu'un catalogue court : le traducteur perd son temps sur des
    // lignes qui ne s'afficheront jamais.
    // Signes qui trahissent du code. L'apostrophe et les parenthèses ont été
    // RETIRÉES de cette liste : elles écartaient « Prix (€) », « L'équipement »
    // et des centaines d'autres libellés parfaitement légitimes. Une règle
    // écrite pour chasser le code ne doit pas emporter le texte avec lui.
    // Un « + » en tête est un préfixe de bouton (« + Nouveau »), pas du code.
    // On le retire pour l'analyse, et on le remettra à l'enregistrement.
    $prefixe = '';
    if (preg_match('/^([+＋]\s*)(.+)$/u', $t, $mp)) { $prefixe = $mp[1]; $t = $mp[2]; }

    foreach (['"', '+', ';', '{', '}', '[', ']', '\\', '</', '/>',
              '&times', '&nbsp', '&amp', '&#'] as $signe) {
        if (str_contains($t, $signe)) return;
    }
    if (preg_match('/\b(var|function|return|const|let|null|undefined|innerHTML)\b/', $t)) return;
    // Un libellé commence par une lettre, un chiffre, ou un emoji : le tableau
    // de bord préfixe ses titres (« 📝 Dernières demandes »), et les écarter
    // laissait tout un écran hors du catalogue.
    if (!preg_match('/^[\p{L}\p{N}«—•✓✗€@#\-]/u', $t)
        && !preg_match('/^[\x{1F300}-\x{1FAFF}\x{2190}-\x{27BF}\x{FE0F}]/u', $t)) return;
    // Trop de signes techniques pour un texte lisible.
    if (preg_match_all('/[<>=&|$_]/u', $t) > 0) return;
    // Directives CSS et sélecteurs récoltés dans les blocs <style>.
    if (str_starts_with($t, '@') || str_starts_with($t, '#') || str_starts_with($t, '.')) return;
    if (preg_match('/^-?[a-z]+(-[a-z]+)+$/', $t)) return;   // propriétés CSS
    if (preg_match('/^[\d\s.,%€$+\-\/()]*$/u', $t)) return;
    if (preg_match('/^[a-z_]+$/', $t)) return;           // identifiants techniques
    // camelCase : « codeBarre », « idxStart ». Ce sont des identifiants, jamais
    // des libellés — un texte affiché ne colle pas une majuscule au milieu d'un
    // mot. Ils entraient par la récolte des clés d'objet.
    if (preg_match('/^[a-zà-ÿ]+[A-ZÀ-Ü]/u', $t)) return;
    if (!preg_match('/\p{L}{2}/u', $t)) return;          // au moins deux lettres
    $vus[$t] = true;
    // Forme préfixée « + Nouveau » en plus de « Nouveau » : le client recompose
    // le « + » et le traducteur ne remplit qu'une ligne.
    if ($prefixe !== '') $vus[$prefixe . $t] = true;

    // Un titre préfixé d'un emoji est aussi enregistré SANS lui : le client
    // sait recomposer « 📝 » + traduction, et le traducteur n'a alors qu'une
    // seule ligne à remplir au lieu de deux presque identiques.
    $nu = preg_replace('/^[\x{1F300}-\x{1FAFF}\x{2190}-\x{27BF}\x{FE0F}\s]+/u', '', $t);
    if ($nu !== $t && mb_strlen($nu) >= 2) $vus[$nu] = true;
};

foreach ($fichiers as $f) {
    $s = (string)@file_get_contents($f);
    if ($s === '') continue;

    // Les COMMENTAIRES sont retirés d'abord. Ils ne s'affichent jamais, et
    // leur prose française — souvent longue et pleine d'apostrophes — polluait
    // le catalogue de fragments que personne ne verra. Un traducteur qui tombe
    // sur « il se contente d » perd confiance dans tout le fichier.
    $s = preg_replace('!/\*.*?\*/!s', ' ', $s);
    $s = preg_replace('!^\s*//.*$!m', ' ', $s);

    if (preg_match_all('/>\s*([^<>{}\n]{2,90}?)\s*</u', $s, $m)) {
        foreach ($m[1] as $t) $ajouter($t);
    }
    if (preg_match_all('/(?:placeholder|title|aria-label)="([^"{}]{2,90})"/u', $s, $m)) {
        foreach ($m[1] as $t) $ajouter($t);
    }
    if (preg_match_all('/(?:toast|showConfirm)\(\s*[\'"]([^\'"{}]{3,90})/u', $s, $m)) {
        foreach ($m[1] as $t) $ajouter($t);
    }
    // Libellés passés en ARGUMENT : statCard(…, 'Biens inventoriés'), et toute
    // chaîne littérale contenant un mot accentué ou plusieurs mots.
    //
    // Le tableau de bord construit ses tuiles ainsi : aucun de ses libellés
    // n'était récolté, et le pack de langue les laissait en français au milieu
    // d'un écran traduit.
    // La chaîne doit être DÉLIMITÉE : ouverte après une parenthèse, une
    // virgule, un signe égal ou un blanc, et fermée de même. Sans cela,
    // l'apostrophe d'élision de « il se contente d'attendre » ouvrait une
    // fausse chaîne, et le catalogue se remplissait de moitiés de phrases.
    if (preg_match_all('/(?<=[\s(,=\[:])\'([^\'\\\\{}<>]{3,90})\'(?=[\s),;\]}])/u', $s, $m)) {
        foreach ($m[1] as $t) {
            // Au moins un espace ou un accent : sinon on ramasse les clés
            // techniques (« blue », « active », « dp_lister »).
            if (preg_match('/[\s\p{L}]*[àâäéèêëîïôöùûüçÀÂÄÉÈÊËÎÏÔÖÙÛÜÇ]/u', $t)
                || (str_contains($t, ' ') && preg_match('/^\p{Lu}/u', $t))) {
                $ajouter($t);
            }
        }
    }
    if (preg_match_all('/<option[^>]*>([^<{}]{2,60})<\/option>/u', $s, $m)) {
        foreach ($m[1] as $t) $ajouter($t);
    }

    // Valeurs de PROPRIÉTÉ : « title: 'Equipements' », « label: 'Historique' ».
    // Le menu et la table des pages sont écrits ainsi, et aucun de leurs
    // libellés n'était récolté : « Equipements » et « Historique » restaient en
    // français au milieu d'un menu traduit.
    $CLES = 'title|label|libelle|titre|nom|texte|placeholder|aide|description|'
          . 'legende|entete|message|resume|hint|tooltip';
    // Guillemets doubles : l'apostrophe est un caractère normal du texte.
    if (preg_match_all('/\b(?:' . $CLES . ')\s*:\s*"([^"{}<>\\\\]{2,90})"/u', $s, $m)) {
        foreach ($m[1] as $t) $ajouter($t);
    }
    // Apostrophes : le contenu ne peut pas contenir d'apostrophe non échappée.
    if (preg_match_all('/\b(?:' . $CLES . ')\s*:\s*\'([^\'{}<>\\\\]{2,90})\'/u', $s, $m)) {
        foreach ($m[1] as $t) $ajouter($t);
    }

    // Toute chaîne à GUILLEMETS DOUBLES contenant un accent ou plusieurs mots :
    // « Nouvelle fiche matériel », « Date d'achat ». Le tri par « ajouter »
    // écarte ce qui n'est pas un libellé.
    if (preg_match_all('/"([^"{}<>\\\\]{4,90})"/u', $s, $m)) {
        foreach ($m[1] as $t) {
            $t = trim($t);
            if (preg_match('/[àâäéèêëîïôöùûüçÀ-Ü]/u', $t)
                || (str_contains($t, ' ') && preg_match('/^[A-ZÀ-Ü+«\d]/u', $t))) {
                $ajouter($t);
            }
        }
    }

    // Fragments de concaténation : « html += '... Basculer → ' + (…) ».
    // Le libellé est alors collé à du code ; on ne garde que la partie
    // textuelle, et seulement si elle se tient seule.
    if (preg_match_all('/[\'"]([^\'"{}<>\\\\]{3,70})[\'"]\s*\+/u', $s, $m)) {
        foreach ($m[1] as $t) {
            $t = trim($t, " \t");
            // On coupe avant une balise ouvrante restée collée.
            $t = preg_replace('/\s*<.*$/u', '', $t);
            // Un libellé collé à un symbole : « ⚙️ Personnaliser ». On garde
            // les deux formes — avec et sans — le client sait recomposer.
            $t = trim($t);
            // Deux mots au moins, ou un accent : sinon c'est un séparateur.
            if (mb_strlen($t) >= 3
                && (preg_match('/[àâäéèêëîïôöùûüçÀ-Ü]/u', $t) || str_contains(trim($t), ' '))) {
                $ajouter($t);
            }
        }
    }

    // Clés d'objet servant de libellé : { 'Individuel':'#ef4444', … }.
    // Ces tables de couleurs ou d'icônes portent le texte affiché en CLÉ, et
    // rien ne les distinguait d'un identifiant technique — sauf l'accent ou
    // l'espace, qu'on exige ici.
    if (preg_match_all('/[\'"]([^\'"{}<>\\\\]{3,40})[\'"]\s*:/u', $s, $m)) {
        foreach ($m[1] as $t) {
            // Majuscule initiale et au moins quatre lettres : « Individuel »
            // n'a ni accent ni espace, et exiger l'un des deux le laissait
            // dehors. Les identifiants techniques sont en minuscules ou en
            // camelCase, donc écartés par la majuscule suivie de minuscules.
            if (preg_match('/^[A-ZÀ-Ü][a-zà-ÿ]{3,}/u', $t)) $ajouter($t);
        }
    }

    // Tableaux de libellés : ['Janvier', 'Février', …]
    if (preg_match_all('/\[\s*((?:[\'"][^\'"{}<>\\\\]{2,40}[\'"]\s*,\s*){2,}'
                     . '[\'"][^\'"{}<>\\\\]{2,40}[\'"])\s*\]/u', $s, $m)) {
        foreach ($m[1] as $liste) {
            if (preg_match_all('/[\'"]([^\'"]{2,40})[\'"]/u', $liste, $mm)) {
                foreach ($mm[1] as $t) $ajouter($t);
            }
        }
    }
}

$libelles = array_keys($vus);
sort($libelles, SORT_NATURAL | SORT_FLAG_CASE);

// Clé ET valeur en français : un traducteur remplace la valeur, jamais la clé.
$catalogue = [];
foreach ($libelles as $t) $catalogue[$t] = $t;

$sortie = $racine . '/lang/fr.json';
file_put_contents($sortie, json_encode([
    'langue'   => 'fr',
    'nom'      => 'Français',
    'version'  => date('Y-m-d'),
    'libelles' => $catalogue,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

printf("✓ lang/fr.json — %d libellés\n", count($catalogue));

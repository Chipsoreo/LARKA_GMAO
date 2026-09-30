<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * Larka — épreuve de la mise en page déclarative
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Une mise en page est la primitive la plus PROCHE du rendu que ce format
 * possède : elle décrit des positions, des tailles et des habillages. C'est
 * donc celle par laquelle on tenterait d'y glisser du style, du balisage ou du
 * comportement.
 *
 * Cette épreuve vérifie trois propriétés, dans cet ordre d'importance :
 *
 *   1. SÉCURITÉ    — une propriété hors catalogue est refusée, quelle qu'elle
 *                    soit. Ce n'est pas une liste d'interdits : c'est une liste
 *                    d'autorisés, et tout le reste tombe.
 *   2. AUTORISATION — une mise en page ne change RIEN aux droits. Elle ne crée
 *                    aucun champ, n'ouvre aucune action, ne demande aucune
 *                    capacité. C'est la raison pour laquelle cette primitive
 *                    est sûre, et elle se vérifie plutôt qu'elle ne se promet.
 *   3. BORNES      — nombre de composants, profondeur, dimensions, complexité.
 *                    Une déclaration aberrante échoue à l'installation, pas
 *                    devant l'utilisateur.
 *
 * Et une quatrième, qui n'est pas une propriété du layout mais du dépôt :
 *   4. RÉTROCOMPATIBILITÉ — les modules publiés avant cette primitive valident
 *                    toujours, à l'identique.
 *
 *   php outils/epreuves/test-layout.php
 * ═══════════════════════════════════════════════════════════════════════════════
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit("Ligne de commande uniquement.\n"); }

$racine = dirname(__DIR__, 2);
require_once $racine . '/api/extensions/declaratif/Schema.php';

$ok = 0; $ko = [];

/** Les champs dont disposent les déclarations d'épreuve. */
function champsEpreuve(): array
{
    return [
        // Aucun champ obligatoire ici : la plupart des cas ne placent qu'un ou
        // deux champs, et une obligation ferait échouer la validation pour une
        // raison étrangère à ce qu'ils éprouvent. L'obligation est fournie au
        // cas qui la vise.
        'equipement' => ['type' => 'lien', 'vers' => 'equipements', 'libelle' => 'Équipement'],
        'statut'     => ['type' => 'choix', 'libelle' => 'Statut',
                         'valeurs' => ['Ouvert', 'En cours', 'Clos']],
        'note'       => ['type' => 'texte_long', 'libelle' => 'Note', 'max' => 800],
        'cout'       => ['type' => 'decimal', 'libelle' => 'Coût', 'min' => 0],
        'realise_le' => ['type' => 'date', 'libelle' => 'Réalisé le'],
        'total'      => ['type' => 'formule', 'libelle' => 'Total',
                         'expression' => '{{record.cout * 1.2}}'],
    ];
}

/** Une déclaration complète portant la mise en page donnée. */
function declarationAvec(?array $layout, array $vueSup = [], ?array $champs = null): array
{
    $vue = array_merge(['type' => 'liste', 'source' => 'j'], $vueSup);
    if ($layout !== null) $vue['layout'] = $layout;
    return [
        'identifiant' => 'epreuve.layout', 'format' => 'declaratif/1',
        'nom' => 'Épreuve', 'version' => '1.0.0', 'auteur' => 'T',
        'description' => 'Mise en page.',
        'donnees' => ['j' => ['libelle' => 'Jeu', 'champs' => $champs ?? champsEpreuve()]],
        'pages' => [['cle' => 'p', 'titre' => 'P', 'roles' => ['Gestionnaire'], 'vue' => $vue]],
    ];
}

/** La déclaration doit être REFUSÉE. */
function refus(string $titre, ?array $layout, array $vueSup = [], ?array $champs = null): void
{
    global $ok, $ko;
    try {
        ExtSchemaDeclaratif::valider(declarationAvec($layout, $vueSup, $champs));
        $ko[] = $titre;
        printf("  ❌  %-50s ACCEPTÉE\n", $titre);
    } catch (\Throwable $e) {
        $ok++;
        printf("  ✅  %-50s ⛔ %s\n", $titre, mb_substr($e->getMessage(), 0, 44));
    }
}

/** La déclaration doit être ACCEPTÉE ; $verif inspecte le layout normalisé. */
function accepte(string $titre, ?array $layout, ?callable $verif = null,
                 array $vueSup = [], ?array $champs = null): void
{
    global $ok, $ko;
    try {
        $d = ExtSchemaDeclaratif::valider(declarationAvec($layout, $vueSup, $champs));
        $l = $d['pages'][0]['vue']['layout'];
        $detail = $verif ? $verif($l, $d) : '';
        if ($detail === false) {
            $ko[] = $titre;
            printf("  ❌  %-50s valeur inattendue\n", $titre);
            return;
        }
        $ok++;
        printf("  ✅  %-50s %s\n", $titre, is_string($detail) ? $detail : '');
    } catch (\Throwable $e) {
        $ko[] = $titre . ' : ' . $e->getMessage();
        printf("  ❌  %-50s %s\n", $titre, mb_substr($e->getMessage(), 0, 44));
    }
}

echo "\n═══════════════════════════════════════════════════════════════════════\n";
echo " Larka — mise en page déclarative\n";
echo "═══════════════════════════════════════════════════════════════════════\n\n";

// ═══════════════════════════════════════════════════════════════════════════
//  1. SÉCURITÉ — le catalogue est fermé
// ═══════════════════════════════════════════════════════════════════════════
echo "  Sécurité : rien hors du catalogue\n";

$unChamp = [['champ' => 'statut']];

refus('Propriété « style » sur un composant',
    ['type' => 'grille', 'composants' => [['champ' => 'statut', 'style' => 'color:red']]]);
refus('Propriété « onclick » sur un composant',
    ['type' => 'grille', 'composants' => [['champ' => 'statut', 'onclick' => 'alert(1)']]]);
refus('Propriété « html » sur un composant',
    ['type' => 'grille', 'composants' => [['champ' => 'statut', 'html' => '<b>x</b>']]]);
refus('Propriété « class » sur un composant',
    ['type' => 'grille', 'composants' => [['champ' => 'statut', 'class' => 'danger']]]);
refus('Propriété « css » à la racine',
    ['type' => 'grille', 'css' => 'body{display:none}', 'composants' => $unChamp]);
refus('Propriété « script » à la racine',
    ['type' => 'grille', 'script' => 'fetch("//x")', 'composants' => $unChamp]);
refus('Propriété inconnue sur une section',
    ['type' => 'grille', 'composants' => [
        ['section' => 'S', 'onmouseover' => 'x', 'composants' => $unChamp]]]);
refus('Propriété inconnue sur un palier responsive',
    ['type' => 'grille', 'responsive' => [['media' => 'print', 'colonnes' => 1]],
     'composants' => $unChamp]);
refus('Type de mise en page inventé',
    ['type' => 'absolu', 'composants' => $unChamp]);
refus('Habillage de section inventé',
    ['type' => 'grille', 'composants' => [
        ['section' => 'S', 'style' => 'neon', 'composants' => $unChamp]]]);
refus('Style de texte inventé',
    ['type' => 'grille', 'composants' => [['texte' => 'x', 'style' => 'blink']]]);
refus('Alignement inventé',
    ['type' => 'grille', 'composants' => [['champ' => 'statut', 'alignement_h' => 'justify']]]);
refus('Couleur de section non hexadécimale',
    ['type' => 'grille', 'composants' => [
        ['section' => 'S', 'couleur' => 'url(//x)', 'composants' => $unChamp]]]);
refus('Couleur de section en expression CSS',
    ['type' => 'grille', 'composants' => [
        ['section' => 'S', 'couleur' => 'expression(alert(1))', 'composants' => $unChamp]]]);
refus('Condition à opérateur inventé',
    ['type' => 'grille', 'composants' => [['champ' => 'statut',
        'visible_si' => ['champ' => 'statut', 'operateur' => 'exec', 'valeur' => 'x']]]]);
refus('Condition visant un champ inexistant',
    ['type' => 'grille', 'composants' => [['champ' => 'statut',
        'visible_si' => ['champ' => 'fantome', 'operateur' => 'egal', 'valeur' => 'x']]]]);

// ═══════════════════════════════════════════════════════════════════════════
//  2. COHÉRENCE — ce qui ne se dessinerait pas est refusé
// ═══════════════════════════════════════════════════════════════════════════
echo "\n  Cohérence : une mise en page qui ne se dessine pas est refusée\n";

refus('Champ inexistant',
    ['type' => 'grille', 'composants' => [['champ' => 'fantome']]]);
refus('Champ calculé placé dans un formulaire',
    ['type' => 'grille', 'composants' => [['champ' => 'total']]]);
refus('Même champ placé deux fois',
    ['type' => 'grille', 'composants' => [['champ' => 'statut'], ['champ' => 'statut']]]);
refus('Même champ placé dans deux sections',
    ['type' => 'grille', 'composants' => [
        ['section' => 'A', 'composants' => [['champ' => 'statut']]],
        ['section' => 'B', 'composants' => [['champ' => 'statut']]]]]);
refus('Composant débordant de la grille',
    ['type' => 'grille', 'colonnes' => 12,
     'composants' => [['champ' => 'statut', 'x' => 10, 'largeur' => 6]]]);
refus('Mise en page sans aucun composant',
    ['type' => 'grille', 'composants' => []]);
refus('Section sans aucun composant',
    ['type' => 'grille', 'composants' => [['section' => 'S', 'composants' => []]]]);
refus('Composant sans nature',
    ['type' => 'grille', 'composants' => [['x' => 1, 'y' => 1]]]);
refus('Composant à deux natures',
    ['type' => 'grille', 'composants' => [['champ' => 'statut', 'espace' => true]]]);
refus('Largeur non numérique',
    ['type' => 'grille', 'composants' => [['champ' => 'statut', 'largeur' => 'six']]]);
refus('Minimum supérieur au maximum',
    ['type' => 'grille', 'composants' => [
        ['champ' => 'statut', 'largeur_min_px' => 500, 'largeur_max_px' => 100]]]);
refus('Texte statique vide',
    ['type' => 'grille', 'composants' => [['texte' => '   ']]]);
refus('« layout » et « champs_formulaire » ensemble',
    ['type' => 'grille', 'composants' => $unChamp],
    ['champs_formulaire' => ['statut', 'note']]);

// Un formulaire dont un champ obligatoire est absent s'affiche, se remplit, et
// refuse l'enregistrement sur une valeur que personne ne pouvait saisir.
$avecObligatoire = array_merge(champsEpreuve(), ['reference' => [
    'type' => 'texte', 'libelle' => 'Référence', 'obligatoire' => true]]);
refus('Champ obligatoire non placé (sans défaut)',
    ['type' => 'grille', 'composants' => [['champ' => 'statut']]], [], $avecObligatoire);

$avecDefaut = array_merge(champsEpreuve(), ['reference' => [
    'type' => 'texte', 'libelle' => 'Référence', 'obligatoire' => true,
    'defaut' => 'À définir']]);
accepte('Champ obligatoire non placé mais pourvu d\'un défaut',
    ['type' => 'grille', 'composants' => [['champ' => 'statut']]],
    fn($l) => count($l['composants']) === 1 ? 'omission légitime' : false,
    [], $avecDefaut);

// ═══════════════════════════════════════════════════════════════════════════
//  3. BORNES
// ═══════════════════════════════════════════════════════════════════════════
echo "\n  Bornes : l'aberration échoue à l'installation\n";

$trop = [];
for ($i = 0; $i < 301; $i++) $trop[] = ['espace' => true];
refus('301 composants', ['type' => 'grille', 'composants' => $trop]);

$juste = [];
for ($i = 0; $i < 300; $i++) $juste[] = ['espace' => true];
accepte('300 composants (la limite tient)', ['type' => 'grille', 'composants' => $juste],
    fn($l) => count($l['composants']) . ' composants');

$profond = ['section' => 'S', 'composants' => [['champ' => 'statut']]];
for ($i = 0; $i < 8; $i++) $profond = ['section' => "S$i", 'composants' => [$profond]];
refus('Sections imbriquées sur 9 niveaux', ['type' => 'grille', 'composants' => [$profond]]);

$raisonnable = ['section' => 'S', 'composants' => [['champ' => 'statut']]];
for ($i = 0; $i < 3; $i++) $raisonnable = ['section' => "S$i", 'composants' => [$raisonnable]];
accepte('Sections imbriquées sur 4 niveaux',
    ['type' => 'grille', 'composants' => [$raisonnable]], fn($l) => 'acceptée');

refus('5 paliers responsive',
    ['type' => 'grille',
     'responsive' => [['en_dessous_de_px' => 300, 'colonnes' => 1],
                      ['en_dessous_de_px' => 500, 'colonnes' => 1],
                      ['en_dessous_de_px' => 700, 'colonnes' => 2],
                      ['en_dessous_de_px' => 900, 'colonnes' => 3],
                      ['en_dessous_de_px' => 1100, 'colonnes' => 4]],
     'composants' => $unChamp]);

accepte('99 colonnes ramenées à 24',
    ['type' => 'grille', 'colonnes' => 99, 'composants' => $unChamp],
    fn($l) => $l['colonnes'] === 24 ? 'colonnes = 24' : false);
accepte('Hauteur démesurée ramenée dans les bornes',
    ['type' => 'grille', 'composants' => [['champ' => 'statut', 'hauteur_px' => 999999]]],
    fn($l) => $l['composants'][0]['hauteur_px'] === 2000 ? 'hauteur_px = 2000' : false);
accepte('Espacement démesuré ramené dans les bornes',
    ['type' => 'grille', 'gap_px' => 9999, 'composants' => $unChamp],
    fn($l) => $l['gap_x_px'] === 64 ? 'gap = 64' : false);
accepte('Hauteur négative ramenée au minimum',
    ['type' => 'grille', 'composants' => [['champ' => 'statut', 'hauteur_px' => -50]]],
    fn($l) => $l['composants'][0]['hauteur_px'] === 16 ? 'hauteur_px = 16' : false);

// ═══════════════════════════════════════════════════════════════════════════
//  4. AUTORISATION — une mise en page ne donne aucun droit
// ═══════════════════════════════════════════════════════════════════════════
echo "\n  Autorisation : la mise en page ne change aucun droit\n";

/**
 * La propriété centrale, et la raison pour laquelle cette primitive est sûre :
 * ajouter une mise en page ne doit RIEN changer aux capacités demandées, aux
 * actions servies ni aux rôles. On compare donc la même déclaration avec et
 * sans layout, et l'on exige que tout le reste soit identique.
 */
$sansLayout = ExtSchemaDeclaratif::valider(declarationAvec(null));
$avecLayout = ExtSchemaDeclaratif::valider(declarationAvec([
    'type' => 'grille', 'colonnes' => 12,
    'composants' => [
        ['champ' => 'equipement', 'x' => 1, 'y' => 1, 'largeur' => 6],
        ['champ' => 'statut', 'x' => 7, 'y' => 1, 'largeur' => 6],
    ],
]));

$capSans = ExtSchemaDeclaratif::capacitesRequises($sansLayout);
$capAvec = ExtSchemaDeclaratif::capacitesRequises($avecLayout);
sort($capSans); sort($capAvec);

if ($capSans === $capAvec) {
    $ok++;
    printf("  ✅  %-50s %s\n", 'Aucune capacité supplémentaire', implode(', ', $capAvec));
} else {
    $ko[] = 'la mise en page a modifié les capacités';
    printf("  ❌  %-50s %s ≠ %s\n", 'Capacités modifiées',
        implode(',', $capSans), implode(',', $capAvec));
}

foreach ([['actions', 'Les actions servies sont inchangées'],
          ['source',  'La source de données est inchangée'],
          ['filtres', 'Les filtres sont inchangés']] as [$clef, $titre]) {
    $a = $sansLayout['pages'][0]['vue'][$clef];
    $b = $avecLayout['pages'][0]['vue'][$clef];
    if ($a === $b) { $ok++; printf("  ✅  %-50s\n", $titre); }
    else { $ko[] = $titre; printf("  ❌  %-50s\n", $titre); }
}

if ($sansLayout['pages'][0]['roles'] === $avecLayout['pages'][0]['roles']) {
    $ok++; printf("  ✅  %-50s\n", 'Les rôles de la page sont inchangés');
} else {
    $ko[] = 'rôles modifiés'; printf("  ❌  %-50s\n", 'Les rôles de la page sont inchangés');
}

// Les champs du jeu ne changent pas davantage : une mise en page place, elle
// ne déclare pas. Un champ absent du layout existe toujours en base.
if ($sansLayout['donnees'] === $avecLayout['donnees']) {
    $ok++; printf("  ✅  %-50s\n", 'Les champs du jeu sont inchangés');
} else {
    $ko[] = 'champs modifiés'; printf("  ❌  %-50s\n", 'Les champs du jeu sont inchangés');
}

// Un layout partiel ne retire aucun champ du jeu : il choisit ce que le
// FORMULAIRE montre, pas ce que la table contient.
accepte('Un layout partiel ne retire aucun champ du jeu',
    ['type' => 'grille', 'composants' => [['champ' => 'statut']]],
    fn($l, $d) => count($d['donnees']['j']['champs']) === 6
        ? '6 champs conservés' : false);

// ═══════════════════════════════════════════════════════════════════════════
//  5. CE QUI DOIT MARCHER
// ═══════════════════════════════════════════════════════════════════════════
echo "\n  Ce que la mise en page sait faire\n";

accepte('L\'exemple de la spécification',
    ['type' => 'grille', 'colonnes' => 12, 'gap_px' => 16,
     'composants' => [
         ['champ' => 'equipement', 'x' => 1, 'y' => 1, 'largeur' => 6, 'hauteur_px' => 40],
         ['champ' => 'statut', 'x' => 7, 'y' => 1, 'largeur' => 6, 'hauteur_px' => 40]]],
    fn($l) => $l['colonnes'] === 12 && $l['composants'][1]['x'] === 7
        ? '12 colonnes, 2 composants placés' : false);

accepte('Placement automatique (sans x ni y)',
    ['type' => 'grille', 'composants' => [
        ['champ' => 'statut', 'largeur' => 4], ['champ' => 'note', 'largeur' => 8]]],
    fn($l) => $l['composants'][0]['x'] === null ? 'au fil' : false);

accepte('Marges, espacements séparés et alignements',
    ['type' => 'grille', 'marges_px' => 12, 'gap_x_px' => 20, 'gap_y_px' => 4,
     'alignement_h' => 'centre', 'alignement_v' => 'haut',
     'composants' => [['champ' => 'statut', 'alignement_h' => 'droite']]],
    fn($l) => $l['alignement_h'] === 'center' && $l['alignement_v'] === 'start'
        && $l['gap_x_px'] === 20 && $l['gap_y_px'] === 4
        && $l['composants'][0]['alignement_h'] === 'end'
        ? 'alignements résolus en CSS' : false);

accepte('Minimums et maximums de dimension',
    ['type' => 'grille', 'composants' => [
        ['champ' => 'note', 'largeur_min_px' => 200, 'largeur_max_px' => 600,
         'hauteur_min_px' => 80, 'hauteur_max_px' => 400]]],
    fn($l) => $l['composants'][0]['largeur_min_px'] === 200
        && $l['composants'][0]['hauteur_max_px'] === 400 ? 'bornes conservées' : false);

accepte('Sections, cartes et panneaux',
    ['type' => 'grille', 'composants' => [
        ['section' => 'Identification', 'style' => 'carte', 'couleur' => 'bleu',
         'colonnes' => 6, 'composants' => [['champ' => 'equipement', 'largeur' => 6]]],
        ['section' => 'Suivi', 'style' => 'panneau',
         'composants' => [['champ' => 'statut']]]]],
    fn($l) => $l['composants'][0]['style'] === 'carte'
        && $l['composants'][0]['couleur'] === '#2563eb'
        && $l['composants'][1]['style'] === 'panneau' ? 'couleur résolue' : false);

accepte('Section repliable',
    ['type' => 'grille', 'composants' => [
        ['section' => 'Avancé', 'repliable' => true, 'repliee' => true,
         'composants' => [['champ' => 'note']]]]],
    fn($l) => $l['composants'][0]['repliable'] === true
        && $l['composants'][0]['repliee'] === true ? 'repliée au départ' : false);

accepte('« repliee » sans « repliable » reste dépliée',
    ['type' => 'grille', 'composants' => [
        ['section' => 'A', 'repliee' => true, 'composants' => [['champ' => 'note']]]]],
    fn($l) => $l['composants'][0]['repliee'] === false
        ? 'un volet non repliable ne peut pas être replié' : false);

accepte('Texte statique et espace',
    ['type' => 'grille', 'composants' => [
        ['texte' => 'Renseignez l\'équipement concerné', 'style' => 'aide', 'largeur' => 12],
        ['espace' => true, 'largeur' => 2],
        ['champ' => 'statut', 'largeur' => 4]]],
    fn($l) => count($l['composants']) === 3
        && $l['composants'][0]['nature'] === 'texte'
        && $l['composants'][1]['nature'] === 'espace' ? '3 natures' : false);

accepte('Affichage conditionnel d\'une section',
    ['type' => 'grille', 'composants' => [
        ['section' => 'Clôture', 'visible_si' => ['champ' => 'statut',
            'operateur' => 'egal', 'valeur' => 'Clos'],
         'composants' => [['champ' => 'note']]]]],
    fn($l) => ($l['composants'][0]['visible_si']['t'] ?? '') === 'clause'
        ? 'condition compilée' : false);

accepte('Affichage conditionnel composé (tous / au_moins_un)',
    ['type' => 'grille', 'composants' => [
        ['champ' => 'note', 'visible_si' => ['tous' => [
            ['champ' => 'statut', 'operateur' => 'egal', 'valeur' => 'Clos'],
            ['champ' => 'cout', 'operateur' => 'superieur_a', 'valeur' => 100]]]]]],
    fn($l) => ($l['composants'][0]['visible_si']['logique'] ?? '') === 'tous'
        ? 'groupe compilé' : false);

accepte('Paliers responsive, triés du plus large au plus étroit',
    ['type' => 'grille', 'colonnes' => 12,
     'responsive' => [['en_dessous_de_px' => 640, 'colonnes' => 1],
                      ['en_dessous_de_px' => 1024, 'colonnes' => 6]],
     'composants' => $unChamp],
    fn($l) => array_column($l['responsive'], 'en_dessous_de_px') === [1024, 640]
        ? '1024 puis 640' : false);

accepte('Le libellé d\'un champ peut être masqué',
    ['type' => 'grille', 'composants' => [['champ' => 'statut', 'masquer_libelle' => true]]],
    fn($l) => $l['composants'][0]['masquer_libelle'] === true ? 'libellé masqué' : false);

accepte('Les champs placés sont listés pour le client',
    ['type' => 'grille', 'composants' => [
        ['champ' => 'statut'],
        ['section' => 'S', 'composants' => [['champ' => 'note']]]]],
    fn($l) => $l['_champs_places'] === ['statut', 'note'] ? 'statut, note' : false);

// ═══════════════════════════════════════════════════════════════════════════
//  6. IMAGES — un fichier du paquet, et rien d'autre
// ═══════════════════════════════════════════════════════════════════════════
echo "\n  Images : un fichier du paquet, jamais autre chose\n";

accepte('Une image du paquet',
    ['composants' => [['image' => 'assets/photo.jpg', 'description' => 'Une photo',
                       'largeur' => 4], ['champ' => 'statut']]],
    fn($l) => $l['composants'][0]['image'] === 'assets/photo.jpg'
        && $l['composants'][0]['cadrage'] === 'contain' ? 'cadrage par défaut' : false);

accepte('Cadrage et arrondi nommés',
    ['composants' => [['image' => 'assets/p.png', 'description' => 'P',
                       'cadrage' => 'couvrir', 'arrondi' => 'cercle', 'largeur' => 3],
                      ['champ' => 'statut']]],
    fn($l) => $l['composants'][0]['cadrage'] === 'cover'
        && $l['composants'][0]['arrondi'] === '50%' ? 'résolus en CSS' : false);

/**
 * Une description VIDE est la façon correcte de déclarer une image décorative :
 * un lecteur d'écran doit alors l'ignorer, et non lire un nom de fichier.
 * Ce qu'on refuse, c'est l'ABSENCE de choix.
 */
accepte('Description vide = image décorative',
    ['composants' => [['image' => 'assets/deco.webp', 'description' => '',
                       'largeur' => 2], ['champ' => 'statut']]],
    fn($l) => $l['composants'][0]['description'] === '' ? 'décorative' : false);

refus('Image sans description',
    ['composants' => [['image' => 'assets/photo.jpg', 'largeur' => 4]]]);

// Le SVG accepte un <script> : il ne sera jamais servi comme image.
refus('Image SVG',
    ['composants' => [['image' => 'assets/logo.svg', 'description' => 'L']]]);
// Une adresse extérieure serait une sortie réseau — donc un moyen de pistage.
refus('Image sur une adresse extérieure',
    ['composants' => [['image' => 'https://pirate.fr/x.png', 'description' => 'X']]]);
// Une image encodée gonflerait la déclaration et la rendrait illisible.
refus('Image encodée en base64',
    ['composants' => [['image' => 'data:image/png;base64,iVBORw0KGgo=',
                       'description' => 'X']]]);
refus('Image hors du dossier assets/',
    ['composants' => [['image' => 'config/secret.png', 'description' => 'X']]]);
refus('Image par traversée de chemin',
    ['composants' => [['image' => 'assets/../../api/config.png', 'description' => 'X']]]);
refus('Cadrage inventé',
    ['composants' => [['image' => 'assets/p.png', 'description' => 'P',
                       'cadrage' => 'deformer']]]);
refus('Arrondi inventé',
    ['composants' => [['image' => 'assets/p.png', 'description' => 'P',
                       'arrondi' => 'etoile']]]);
refus('Propriété inconnue sur une image',
    ['composants' => [['image' => 'assets/p.png', 'description' => 'P',
                       'onerror' => 'alert(1)']]]);

/**
 * Une image déclarée mais absente du dossier s'installait sans un mot et
 * affichait un cadre vide. L'auteur ne le voit jamais : il a le fichier sur
 * son disque. Le relevé sert au constructeur de paquet, qui refuse.
 */
$avecImages = ExtSchemaDeclaratif::valider(declarationAvec(
    ['composants' => [['image' => 'assets/a.png', 'description' => 'A'],
                      ['image' => 'assets/b.jpg', 'description' => 'B'],
                      ['champ' => 'statut']]]));
$avecImages['logo'] = 'assets/logo.png';
$relevees = ExtSchemaDeclaratif::imagesDeclarees($avecImages);
sort($relevees);
if ($relevees === ['assets/a.png', 'assets/b.jpg', 'assets/logo.png']) {
    $ok++;
    printf("  ✅  %-50s %s\n", 'Les images déclarées sont relevées (logo compris)',
        implode(', ', $relevees));
} else {
    $ko[] = 'relevé des images incomplet';
    printf("  ❌  %-50s %s\n", 'Relevé des images', implode(', ', $relevees));
}

// ═══════════════════════════════════════════════════════════════════════════
//  7. TAILLES NOMMÉES — un auteur ne doit jamais avoir à compter des pixels
// ═══════════════════════════════════════════════════════════════════════════
echo "\n  Tailles nommées : les pixels ne sont jamais obligatoires\n";

accepte('Une mise en page sans AUCUN pixel',
    ['composants' => [['champ' => 'statut'], ['champ' => 'note']]],
    fn($l) => $l['colonnes'] === 12 && $l['gap_x_px'] === 12 && $l['marges_px'] === 0
        ? '12 colonnes, espacement normal, hauteurs naturelles' : false);

accepte('Espacement et marges nommés',
    ['espacement' => 'large', 'marges' => 'normale', 'composants' => $unChamp],
    fn($l) => $l['gap_x_px'] === 20 && $l['marges_px'] === 12
        ? 'large = 20 px, normale = 12 px' : false);

accepte('Taille de champ nommée',
    ['composants' => [['champ' => 'note', 'taille' => 'grande']]],
    fn($l) => $l['composants'][0]['hauteur_px'] === 96 ? 'grande = 96 px' : false);

/**
 * « normale » ne vaut PAS un nombre : elle laisse le champ à sa hauteur
 * naturelle. C'est une valeur distincte de « 38 px », parce qu'un thème plus
 * dense ou une police plus grande doivent pouvoir la faire varier.
 */
accepte('« normale » laisse la hauteur naturelle',
    ['composants' => [['champ' => 'statut', 'taille' => 'normale']]],
    fn($l) => !array_key_exists('hauteur_px', $l['composants'][0])
        ? 'aucune hauteur imposée' : false);

accepte('Seuil responsive nommé',
    ['responsive' => [['sous' => 'telephone', 'colonnes' => 1]],
     'composants' => $unChamp],
    fn($l) => $l['responsive'][0]['en_dessous_de_px'] === 640
        ? 'telephone = 640 px' : false);

accepte('Espace nommé',
    ['composants' => [['espace' => true, 'largeur' => 4, 'taille' => 'compacte'],
                      ['champ' => 'statut']]],
    fn($l) => $l['composants'][0]['hauteur_px'] === 32 ? 'compacte = 32 px' : false);

accepte('Les pixels restent acceptés pour le cas précis',
    ['gap_px' => 18, 'marges_px' => 6,
     'responsive' => [['en_dessous_de_px' => 700, 'colonnes' => 2]],
     'composants' => [['champ' => 'note', 'hauteur_px' => 140]]],
    fn($l) => $l['gap_x_px'] === 18 && $l['composants'][0]['hauteur_px'] === 140
        ? 'valeurs exactes conservées' : false);

// Deux façons de dire la même dimension finissent par se contredire.
refus('« espacement » et « gap_px » ensemble',
    ['espacement' => 'large', 'gap_px' => 20, 'composants' => $unChamp]);
refus('« marges » et « marges_px » ensemble',
    ['marges' => 'normale', 'marges_px' => 12, 'composants' => $unChamp]);
refus('« taille » et « hauteur_px » ensemble',
    ['composants' => [['champ' => 'note', 'taille' => 'grande', 'hauteur_px' => 96]]]);
refus('« sous » et « en_dessous_de_px » ensemble',
    ['responsive' => [['sous' => 'telephone', 'en_dessous_de_px' => 640, 'colonnes' => 1]],
     'composants' => $unChamp]);

refus('Taille nommée inventée',
    ['composants' => [['champ' => 'note', 'taille' => 'enorme']]]);
refus('Espacement nommé inventé',
    ['espacement' => 'aere', 'composants' => $unChamp]);
refus('Seuil nommé inventé',
    ['responsive' => [['sous' => 'montre', 'colonnes' => 1]], 'composants' => $unChamp]);

// ═══════════════════════════════════════════════════════════════════════════
//  8. RÉTROCOMPATIBILITÉ
// ═══════════════════════════════════════════════════════════════════════════
echo "\n  Rétrocompatibilité : les modules d'avant ne bougent pas\n";

accepte('Une page sans « layout » reste valide',
    null, fn($l) => $l === null ? 'layout = null' : false);

accepte('« champs_formulaire » fonctionne toujours seul',
    null, fn($l, $d) => $d['pages'][0]['vue']['champs_formulaire'] === ['statut', 'note']
        ? 'restriction conservée' : false,
    ['champs_formulaire' => ['statut', 'note']]);

accepte('« colonnes_formulaire » fonctionne toujours',
    null, fn($l, $d) => $d['pages'][0]['vue']['colonnes_formulaire'] === 4
        ? '4 colonnes' : false,
    ['colonnes_formulaire' => 4]);

/**
 * Les paquets RÉELLEMENT livrés valident-ils encore ? C'est la seule question
 * qui compte pour un dépôt en production : une primitive ajoutée ne doit pas
 * rendre ininstallable ce qui l'était.
 */
require_once $racine . '/api/extensions/References.php';

$sources = glob($racine . '/extensions/modules/*/extension.json') ?: [];
$nb = 0; $echecs = []; $avecLayout = []; $champsPlaces = 0;

foreach ($sources as $f) {
    $id   = basename(dirname($f));
    $brut = json_decode((string)file_get_contents($f), true);
    if (!is_array($brut)) continue;
    $nb++;
    try {
        // Les fragments « $ref » sont résolus comme à la construction du
        // paquet : les sauter laissait « larka.recharge » — le module le plus
        // composé, et le seul à porter deux écrans sur un même jeu — hors de
        // toute vérification.
        $d = ExtReferences::resoudre($brut, ExtReferences::lecteurDossier(dirname($f)));
        $v = ExtSchemaDeclaratif::valider($d);
        foreach ($v['pages'] as $p) {
            $l = $p['vue']['layout'] ?? null;
            if ($l === null) continue;
            $avecLayout[] = $id . '/' . $p['cle'];
            $champsPlaces += count($l['_champs_places']);
        }
    } catch (\Throwable $e) {
        $echecs[] = $id . ' : ' . $e->getMessage();
    }
}

if ($echecs === []) {
    $ok++;
    printf("  ✅  %-50s %d module(s) livré(s)\n",
        'Les modules livrés valident toujours', $nb);
} else {
    foreach ($echecs as $e) $ko[] = $e;
    printf("  ❌  %-50s %s\n", 'Un module livré ne valide plus',
        implode(' | ', $echecs));
}

/**
 * Les modules livrés ont été ADAPTÉS à la mise en page. Le vérifier ici évite
 * la régression silencieuse par excellence : un `layout` perdu au fil d'une
 * réécriture de déclaration, et les six écrans qui retombent sans bruit sur le
 * formulaire d'avant — personne ne s'en apercevrait, puisque rien ne casse.
 */
if ($avecLayout !== []) {
    $ok++;
    printf("  ✅  %-50s %d écran(s), %d champ(s) placé(s)\n",
        'Les modules livrés portent bien leur mise en page',
        count($avecLayout), $champsPlaces);
} else {
    $ko[] = 'aucun module livré ne déclare de mise en page';
    printf("  ❌  %-50s\n", 'Aucun module livré ne porte de mise en page');
}

// ═══════════════════════════════════════════════════════════════════════════
$total = $ok + count($ko);
echo "\n───────────────────────────────────────────────────────────────────────\n";
if ($ko === []) {
    printf(" ✅ %d/%d — la mise en page est close, bornée, et sans effet sur les droits.\n",
        $ok, $total);
} else {
    printf(" ❌ %d échec(s) sur %d :\n", count($ko), $total);
    foreach ($ko as $e) echo "      • $e\n";
}
echo "───────────────────────────────────────────────────────────────────────\n\n";
exit($ko === [] ? 0 : 1);

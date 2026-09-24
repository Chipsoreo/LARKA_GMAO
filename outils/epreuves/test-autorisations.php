<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * Larka — épreuve des autorisations d'un module déclaratif
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Les autres épreuves vérifient qu'une déclaration hostile est REFUSÉE et qu'une
 * requête hostile est REFUSÉE. Celle-ci vérifie autre chose, et c'est le défaut
 * qu'elle a fait apparaître : une déclaration PARFAITEMENT LÉGITIME, installée
 * et livrée, dont les frontières n'étaient pas appliquées.
 *
 * Un module déclare ses rôles page par page et ses actions vue par vue. C'est
 * le seul vocabulaire d'autorisation du format — il n'y en a pas d'autre. Il
 * était appliqué à « supprimer » et « exporter », et à rien d'autre :
 *
 *   • lister      → un rôle lisait le jeu d'une page qui ne lui est pas ouverte
 *   • enregistrer → aucun contrôle du tout : ni rôle, ni action, ni champ
 *   • ancrage     → même lecture, par une autre porte
 *   • réglages    → un demandeur reconfigurait le module
 *   • fichiers    → n'importe quel compte téléchargeait ceux de n'importe quel
 *                   module actif
 *
 * Chaque cas ci-dessous a d'abord été REPRODUIT sur un module livré, puis
 * corrigé. L'épreuve les garde fermés.
 *
 *   php outils/epreuves/test-autorisations.php
 * ═══════════════════════════════════════════════════════════════════════════════
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit("Ligne de commande uniquement.\n"); }

$racine = dirname(__DIR__, 2);
require_once $racine . '/api/config.php';
require_once $racine . '/api/Database.php';
require_once $racine . '/api/Journal.php';
require_once $racine . '/api/extensions/Registre.php';

$ok = 0; $ko = [];

/**
 * Le contrôle rend `true`, ou `[verdict, détail]`.
 *
 * ⚠️ IL ACCEPTAIT AUSSI UNE CHAÎNE COMME SUCCÈS, ce qui rendait vert un
 * contrôle qui expliquait son échec : « garde sans contrôle de rôle » passait
 * pour un commentaire. Une épreuve qui salue la description de sa propre panne
 * est pire que pas d'épreuve. Le verdict est donc toujours un booléen, et le
 * détail ne voyage qu'à côté.
 */
function verifier(string $titre, callable $f, int &$ok, array &$ko): void
{
    try {
        $r = $f();
        [$verdict, $detail] = is_array($r) ? [(bool)($r[0] ?? false), (string)($r[1] ?? '')]
                                           : [$r === true, ''];
        if ($verdict) {
            $ok++;
            printf("  ✅  %-52s %s\n", $titre, mb_substr($detail, 0, 30));
            return;
        }
        $ko[] = $titre . ($detail === '' ? ' : attendu vrai' : ' : ' . $detail);
        printf("  ❌  %-52s %s\n", $titre, mb_substr($detail ?: 'NON VÉRIFIÉ', 0, 40));
    } catch (\Throwable $e) {
        $ko[] = $titre . ' : ' . $e->getMessage();
        printf("  ❌  %-52s %s\n", $titre, mb_substr($e->getMessage(), 0, 40));
    }
}

/** L'appel doit être REFUSÉ. Réussir est l'échec. */
function refus(string $titre, callable $f, int &$ok, array &$ko): void
{
    try {
        $f();
        $ko[] = $titre . ' : ACCEPTÉ';
        printf("  ❌  %-52s ACCEPTÉ\n", $titre);
    } catch (\Throwable $e) {
        $ok++;
        printf("  ✅  %-52s ⛔ %s\n", $titre, mb_substr($e->getMessage(), 0, 34));
    }
}

echo "\n═══════════════════════════════════════════════════════════════════════\n";
echo " Larka — les rôles et les actions déclarés sont-ils APPLIQUÉS ?\n";
echo "═══════════════════════════════════════════════════════════════════════\n\n";

try {
    $db = new Database();
    $db->getPdo();
} catch (\Throwable $e) {
    echo "  ⏭️  Section sautée : aucune base accessible (" . get_class($e) . ").\n";
    exit(0);
}

$gestionnaire = ['Login' => 'patron',  'Role' => 'Gestionnaire'];
$demandeur    = ['Login' => 'mallory', 'Role' => 'Demandeur'];
$visionneur   = ['Login' => 'obs',     'Role' => 'Visionneur'];

/**
 * Installe un module livré si ce n'est pas déjà fait.
 *
 * ⚠️ L'ÉPREUVE COMPTAIT SUR UNE AUTRE POUR INSTALLER.
 *
 * Écrite d'abord sur une base où les modules se trouvaient déjà — posés par
 * test-execution —, elle passait ses trois quarts de contrôles. Sur une
 * installation neuve, les mêmes sections se SAUTAIENT : 20 contrôles au lieu de
 * 31, et un vert identique. Une épreuve qui vérifie moins sans le dire est le
 * défaut que ce projet a déjà payé une fois.
 *
 * Elle pose donc elle-même ce dont elle a besoin, et REFUSE de se taire si
 * elle n'y parvient pas.
 */
function assurerModule(string $id, $db, array $gestionnaire, int &$ok, array &$ko): bool
{
    if (is_dir((string)ExtPaquet::dossierCode($id))
        && isset(ExtRegistre::initialiser($db, $gestionnaire)->actives()[$id])) {
        return true;
    }

    $paquets = glob(dirname(__DIR__, 2) . '/extensions/*/' . $id . '-*.larka') ?: [];
    if ($paquets === []) {
        // Distribution allégée : le module n'est pas là, il n'y a rien à
        // éprouver. On le DIT, au lieu de sauter en silence.
        printf("  ⏭️  %-52s %s\n", "Module « $id »", 'absent de cette distribution');
        return false;
    }
    try {
        ExtPaquet::installer($paquets[0], $id);
        $reg = ExtRegistre::initialiser($db, $gestionnaire);
        $reg->definirUtilisateur($gestionnaire);
        $reg->installer($id, ExtManifeste::charger(ExtPaquet::dossierCode($id))->capacites(),
                        'épreuve');
        return true;
    } catch (\Throwable $e) {
        $ko[] = "Installation de « $id » : " . $e->getMessage();
        printf("  ❌  %-52s %s\n", "Installer « $id »", mb_substr($e->getMessage(), 0, 40));
        return false;
    }
}

// ═══════════════════════════════════════════════════════════════════════════
//  1. Deux jeux, deux publics — la répartition par page est-elle une frontière ?
// ═══════════════════════════════════════════════════════════════════════════
//
// Le cas le plus simple qui exhibait le défaut : un module à deux écrans, l'un
// ouvert au demandeur, l'autre réservé au gestionnaire. Le nom du jeu voyage
// dans la charge utile de « ext_appel » ; il suffisait de le changer.
echo "  Deux jeux, deux publics\n";

$deuxJeux = ExtSchemaDeclaratif::valider([
    'identifiant' => 'epreuve.deuxjeux', 'format' => 'declaratif/1',
    'nom' => 'Deux jeux', 'version' => '1.0.0', 'auteur' => 'Épreuve',
    'description' => 'Un écran pour le demandeur, un pour le gestionnaire.',
    'donnees' => [
        'demandes' => ['libelle' => 'Demande', 'champs' => [
            'objet'  => ['type' => 'texte', 'libelle' => 'Objet'],
            'statut' => ['type' => 'choix', 'libelle' => 'Décision',
                         'valeurs' => ['Demandé', 'Accordé', 'Refusé'],
                         'defaut' => 'Demandé'],
        ]],
        'budgets' => ['libelle' => 'Budget', 'champs' => [
            'montant' => ['type' => 'decimal', 'libelle' => 'Montant'],
        ]],
    ],
    'pages' => [
        ['cle' => 'ma_demande', 'titre' => 'Ma demande', 'roles' => ['Demandeur'],
         'vue' => ['type' => 'liste', 'source' => 'demandes',
                   'colonnes' => ['objet', 'statut'], 'actions' => ['creer'],
                   // La mise en page CHOISIT les champs du formulaire : le
                   // demandeur saisit un objet, pas une décision.
                   'layout' => ['type' => 'grille', 'colonnes' => 12, 'composants' => [
                       ['champ' => 'objet', 'x' => 1, 'y' => 1, 'largeur' => 12]]]]],
        ['cle' => 'arbitrage', 'titre' => 'Arbitrage', 'roles' => ['Gestionnaire'],
         'vue' => ['type' => 'liste', 'source' => 'demandes',
                   'colonnes' => ['objet', 'statut'],
                   'actions' => ['creer', 'modifier', 'supprimer', 'exporter']]],
        ['cle' => 'budgets', 'titre' => 'Budgets', 'roles' => ['Gestionnaire'],
         'vue' => ['type' => 'liste', 'source' => 'budgets',
                   'colonnes' => ['montant'], 'actions' => ['creer', 'exporter']]],
    ],
]);

$mG = new ExtMoteurDeclaratif('epreuve.deuxjeux', $deuxJeux, $db, $gestionnaire);
$mD = new ExtMoteurDeclaratif('epreuve.deuxjeux', $deuxJeux, $db, $demandeur);
$mG->preparer();
$mG->enregistrer('budgets', ['valeurs' => ['montant' => 92000]]);

refus('Demandeur : lire le jeu d\'une page réservée',
    fn() => $mD->lister('budgets', []), $ok, $ko);
refus('Demandeur : écrire dans le jeu d\'une page réservée',
    fn() => $mD->enregistrer('budgets', ['valeurs' => ['montant' => 1]]), $ok, $ko);
refus('Demandeur : exporter le jeu d\'une page réservée',
    fn() => $mD->exporter('budgets', []), $ok, $ko);
refus('Demandeur : suggestions sur un jeu réservé',
    fn() => $mD->suggestions('budgets', ['champ' => 'montant']), $ok, $ko);
verifier('Gestionnaire : son propre jeu reste lisible',
    fn() => $mG->lister('budgets', [])['nombre'] === 1, $ok, $ko);
verifier('Demandeur : son propre jeu reste lisible',
    fn() => is_array($mD->lister('demandes', [])['lignes']), $ok, $ko);

// ── L'action, pas seulement le jeu ───────────────────────────────────────
// La page du demandeur déclare « creer » et rien d'autre.
$sienne = $mD->enregistrer('demandes', ['valeurs' => ['objet' => 'Une demande']]);
verifier('Demandeur : créer, que sa page déclare',
    fn() => !empty($sienne['cree']), $ok, $ko);
refus('Demandeur : modifier, que sa page ne déclare pas',
    fn() => $mD->enregistrer('demandes',
        ['id' => $sienne['id'], 'valeurs' => ['objet' => 'Détournée']]), $ok, $ko);
refus('Demandeur : supprimer, que sa page ne déclare pas',
    fn() => $mD->supprimer('demandes', ['id' => $sienne['id']]), $ok, $ko);

// ── Le champ, pas seulement l'action ─────────────────────────────────────
//
// Le cœur du défaut : la mise en page du demandeur n'expose que « objet ».
// Le client ne dessinait donc que ce champ — et le serveur acceptait les
// autres. Le demandeur s'accordait lui-même ce qu'il demandait.
$force = $mD->enregistrer('demandes',
    ['valeurs' => ['objet' => 'Forcée', 'statut' => 'Accordé']]);
$relu = null;
foreach ($mG->lister('demandes', [])['lignes'] as $l) {
    if ((int)$l['id'] === (int)$force['id']) $relu = $l;
}
verifier('Demandeur : le champ hors formulaire est écarté',
    fn() => $relu !== null && $relu['statut'] === 'Demandé'
        ? [true,  'statut resté « Demandé »']
        : [false, 'statut = ' . var_export($relu['statut'] ?? null, true)], $ok, $ko);
verifier('Gestionnaire : sa page n\'expose aucune restriction',
    function () use ($mG, $force) {
        $mG->enregistrer('demandes', ['id' => $force['id'],
            'valeurs' => ['statut' => 'Accordé']]);
        foreach ($mG->lister('demandes', [])['lignes'] as $l) {
            if ((int)$l['id'] === (int)$force['id']) return $l['statut'] === 'Accordé';
        }
        return false;
    }, $ok, $ko);

// ═══════════════════════════════════════════════════════════════════════════
//  2. Compatibilité : une page SANS mise en page ne restreint rien
// ═══════════════════════════════════════════════════════════════════════════
//
// Tous les modules écrits avant la mise en page déclarent leurs champs et rien
// de plus. Leur comportement ne doit pas changer d'un iota : sans layout ni
// « champs_formulaire », la surface montre tout, donc autorise tout.
echo "\n  Rétrocompatibilité : une page sans mise en page\n";

$ancien = ExtSchemaDeclaratif::valider([
    'identifiant' => 'epreuve.ancien', 'format' => 'declaratif/1',
    'nom' => 'Ancien', 'version' => '1.0.0', 'auteur' => 'Épreuve',
    'description' => 'Module écrit avant la mise en page déclarative.',
    'donnees' => ['fiches' => ['libelle' => 'Fiche', 'champs' => [
        'nom'  => ['type' => 'texte', 'libelle' => 'Nom'],
        'note' => ['type' => 'texte', 'libelle' => 'Note'],
    ]]],
    'pages' => [['cle' => 'f', 'titre' => 'Fiches', 'roles' => ['Visionneur'],
                 'vue' => ['type' => 'liste', 'source' => 'fiches',
                           'colonnes' => ['nom', 'note'],
                           'actions' => ['creer', 'modifier']]]],
]);
$mV = new ExtMoteurDeclaratif('epreuve.ancien', $ancien, $db, $visionneur);
$mV->preparer();
verifier('Sans layout : tous les champs restent saisissables',
    function () use ($mV) {
        $r = $mV->enregistrer('fiches', ['valeurs' => ['nom' => 'A', 'note' => 'B']]);
        foreach ($mV->lister('fiches', [])['lignes'] as $l) {
            if ((int)$l['id'] === (int)$r['id']) {
                return $l['nom'] === 'A' && $l['note'] === 'B';
            }
        }
        return false;
    }, $ok, $ko);

// ═══════════════════════════════════════════════════════════════════════════
//  3. Sur un module LIVRÉ : larka.recharge
// ═══════════════════════════════════════════════════════════════════════════
//
// C'est sur lui que les quatre premiers défauts ont été constatés, et c'est lui
// qui impose la seule nuance du correctif : sa page de demandeur ne déclare pas
// « creer » — la création passe par un ancrage « formulaire ». Refuser tout ce
// que la page ne déclare pas aurait cassé le module en croyant le protéger.
echo "\n  Module livré : larka.recharge\n";

$recharge = assurerModule('larka.recharge', $db, $gestionnaire, $ok, $ko);
if ($recharge) {
    $decl = ExtManifeste::charger((string)ExtPaquet::dossierCode('larka.recharge'))
                ->declaration();
    $rD = new ExtMoteurDeclaratif('larka.recharge', $decl, $db, $demandeur);
    $rG = new ExtMoteurDeclaratif('larka.recharge', $decl, $db, $gestionnaire);
    $rD->preparer();

    $creneau = null;
    verifier('Demandeur : réserver par l\'ancrage « formulaire »',
        function () use ($rD, &$creneau) {
            $creneau = $rD->enregistrer('creneaux', ['valeurs' => [
                'date' => date('Y-m-d', strtotime('+10 days')),
                'horaire' => '08:00 – 10:00', 'demandeur' => 'Mallory',
                'immatriculation' => 'AA-123-AA']]);
            return !empty($creneau['cree']);
        }, $ok, $ko);

    verifier('Demandeur : « Décision » reste à sa valeur par défaut',
        function () use ($rD, $rG, &$creneau) {
            $force = $rD->enregistrer('creneaux', ['valeurs' => [
                'date' => date('Y-m-d', strtotime('+12 days')),
                'horaire' => '10:00 – 12:00', 'demandeur' => 'Mallory',
                'immatriculation' => 'BB-456-BB',
                'statut' => 'Accepté', 'reponse' => 'Approuvé par moi-même']]);
            foreach ($rG->lister('creneaux', [])['lignes'] as $l) {
                if ((int)$l['id'] !== (int)$force['id']) continue;
                return ($l['statut'] ?? '') !== 'Accepté' && ($l['reponse'] ?? '') === ''
                    ? [true,  'ni décision ni motif']
                    : [false, 'statut=' . ($l['statut'] ?? '?')
                              . ' reponse=' . ($l['reponse'] ?? '?')];
            }
            return [false, 'ligne introuvable'];
        }, $ok, $ko);

    $victime = $rG->enregistrer('creneaux', ['valeurs' => [
        'date' => date('Y-m-d', strtotime('+11 days')), 'horaire' => '14:00 – 16:00',
        'demandeur' => 'Victime', 'immatriculation' => 'ZZ-999-ZZ']]);
    refus('Demandeur : modifier la fiche d\'un autre',
        fn() => $rD->enregistrer('creneaux', ['id' => $victime['id'],
            'valeurs' => ['demandeur' => 'Mallory']]), $ok, $ko);
    refus('Demandeur : exporter le registre',
        fn() => $rD->exporter('creneaux', []), $ok, $ko);
    verifier('Gestionnaire : arbitrer depuis SA page',
        function () use ($rG, $victime) {
            $rG->enregistrer('creneaux',
                ['id' => $victime['id'], 'valeurs' => ['statut' => 'Demandé']]);
            return true;
        }, $ok, $ko);
    verifier('Gestionnaire : exporter reste possible',
        fn() => is_string($rG->exporter('creneaux', [])['contenu']), $ok, $ko);
}

// ═══════════════════════════════════════════════════════════════════════════
//  4. Réglages : une action d'administration, deux chemins, une permission
// ═══════════════════════════════════════════════════════════════════════════
echo "\n  Réglages du module\n";

$reg = ExtRegistre::initialiser($db, $demandeur);
$reg->definirUtilisateur($demandeur);
if ($recharge) {
    refus('Demandeur : réécrire les réglages par ext_appel',
        fn() => $reg->executerAction('larka.recharge', 'dp_definir_reglages',
            ['valeurs' => ['duree_max_heures' => 12]]), $ok, $ko);
    verifier('Gestionnaire : régler son module',
        function () use ($db, $gestionnaire) {
            $r = ExtRegistre::initialiser($db, $gestionnaire);
            $r->definirUtilisateur($gestionnaire);
            $v = $r->executerAction('larka.recharge', 'dp_definir_reglages',
                ['valeurs' => ['duree_max_heures' => 6]]);
            return ($v['duree_max_heures'] ?? null) === 6;
        }, $ok, $ko);
}
verifier('La liste des actions d\'administration est close',
    fn() => ExtRegistre::ACTIONS_ADMINISTRATION === ['dp_definir_reglages'], $ok, $ko);

// ═══════════════════════════════════════════════════════════════════════════
//  5. Fichiers d'un module : le rôle décide aussi
// ═══════════════════════════════════════════════════════════════════════════
//
// Les routes de fichiers appelaient require_auth() et s'arrêtaient là. On
// vérifie ici le contrôle qu'elles appellent désormais — la même règle que
// pour l'exécution d'une action.
echo "\n  Visibilité d'un module\n";

$regD = ExtRegistre::initialiser($db, $demandeur);   $regD->definirUtilisateur($demandeur);
$regV = ExtRegistre::initialiser($db, $visionneur);  $regV->definirUtilisateur($visionneur);
$regG = ExtRegistre::initialiser($db, $gestionnaire); $regG->definirUtilisateur($gestionnaire);

if (assurerModule('larka.cles', $db, $gestionnaire, $ok, $ko)) {
    // larka.cles : Gestionnaire, Admin, Visionneur — pas Demandeur.
    verifier('Visionneur : voit un module qui lui est ouvert',
        fn() => $regV->visiblePour('larka.cles') === true, $ok, $ko);
    verifier('Demandeur : ne voit pas un module qui ne l\'est pas',
        fn() => $regD->visiblePour('larka.cles') === false, $ok, $ko);
    verifier('Gestionnaire : voit tout module installé',
        fn() => $regG->visiblePour('larka.cles') === true, $ok, $ko);
}
verifier('Un module inconnu n\'est visible de personne',
    fn() => $regG->visiblePour('inexistant.module') === false, $ok, $ko);

// Le contrôle ne vaut que s'il est APPELÉ : c'est exactement ce qui manquait.
// On vérifie donc la route elle-même, à la source — une garde retirée ne se
// remarquerait autrement qu'en production.
verifier('Les routes de fichiers appellent la garde',
    function () use ($racine) {
        $src = (string)file_get_contents($racine . '/api/routes/extensions.php');
        if (!preg_match('/\$_extDossier\s*=\s*static function.*?\};/s', $src, $m)) {
            return [false, 'garde commune introuvable'];
        }
        if (!str_contains($m[0], 'visiblePour')) {
            return [false, 'garde sans contrôle de rôle'];
        }
        // Les quatre routes qui touchent aux fichiers passent par elle.
        foreach (['ext_fichiers', 'ext_fichier_importer',
                  'ext_fichier_supprimer', 'ext_fichier'] as $route) {
            if (!preg_match('/\$action === \'' . $route . '\'.{0,900}?\$_extDossier\(/s', $src)) {
                return [false, "route $route sans garde"];
            }
        }
        return [true, '4 routes gardées'];
    }, $ok, $ko);

// ═══════════════════════════════════════════════════════════════════════════
//  6. Cloisonnement des tables : un espace, un module
// ═══════════════════════════════════════════════════════════════════════════
echo "\n  Espaces de tables\n";

verifier('« larka.recharge » et « larka.recharge-x » : conflit',
    fn() => ExtPolitiqueDonnees::espacesEnConflit('larka.recharge', 'larka.recharge-x'),
    $ok, $ko);
verifier('« larka.recharge-x » et « larka-recharge.x » : même préfixe',
    fn() => ExtPolitiqueDonnees::prefixeExtension('larka.recharge-x')
            === ExtPolitiqueDonnees::prefixeExtension('larka-recharge.x')
        && ExtPolitiqueDonnees::espacesEnConflit('larka.recharge-x', 'larka-recharge.x'),
    $ok, $ko);
verifier('Deux identifiants distincts ne sont pas en conflit',
    fn() => !ExtPolitiqueDonnees::espacesEnConflit('larka.cles', 'larka.controles'),
    $ok, $ko);
verifier('Un module n\'est pas en conflit avec lui-même',
    fn() => !ExtPolitiqueDonnees::espacesEnConflit('larka.cles', 'larka.cles'), $ok, $ko);
verifier('La collision est refusée à l\'INSTALLATION',
    function () use ($db, $gestionnaire, $racine) {
        if (!is_dir((string)ExtPaquet::dossierCode('larka.recharge'))) {
            return [true, 'module absent : sans objet'];
        }
        // On fabrique un module dont l'identifiant empiète sur un installé.
        $intrus = (string)ExtPaquet::dossierCode('larka.recharge-x');
        @mkdir($intrus, 0750, true);
        file_put_contents($intrus . '/extension.json', json_encode([
            'identifiant' => 'larka.recharge-x', 'format' => 'declaratif/1',
            'nom' => 'Intrus', 'version' => '1.0.0', 'auteur' => 'Épreuve',
            'description' => 'Identifiant qui empiète sur l\'espace d\'un autre.',
            'donnees' => ['creneaux' => ['libelle' => 'Créneau', 'champs' => [
                'x' => ['type' => 'texte', 'libelle' => 'X']]]],
            'pages' => [['cle' => 'p', 'titre' => 'P', 'roles' => ['Gestionnaire'],
                         'vue' => ['type' => 'liste', 'source' => 'creneaux',
                                   'colonnes' => ['x']]]],
        ], JSON_UNESCAPED_UNICODE));
        $r = ExtRegistre::initialiser($db, $gestionnaire);
        $r->definirUtilisateur($gestionnaire);
        try {
            $r->installer('larka.recharge-x',
                ExtManifeste::charger($intrus)->capacites(), 'épreuve');
            ExtPaquet::supprimerRecursif($intrus);
            return [false, 'INSTALLATION ACCEPTÉE'];
        } catch (\Throwable $e) {
            ExtPaquet::supprimerRecursif($intrus);
            return str_contains($e->getMessage(), 'espace de tables')
                ? [true,  'refusée, espace partagé']
                : [false, 'refusée pour un autre motif : ' . $e->getMessage()];
        }
    }, $ok, $ko);

// ── Nettoyage : l'épreuve doit pouvoir être relancée ─────────────────────
foreach (['epreuve.deuxjeux' => ['demandes', 'budgets'],
          'epreuve.ancien'   => ['fiches']] as $id => $jeux) {
    foreach ($jeux as $j) {
        $db->getPdo()->exec('DROP TABLE IF EXISTS "'
            . ExtPolitiqueDonnees::prefixeExtension($id) . $j . '"');
    }
    $c = $racine . '/data/securite/compteurs/' . $id . '.json';
    if (is_file($c)) unlink($c);
}
ExtPolitiqueDonnees::oublier();

$total = $ok + count($ko);
echo "\n───────────────────────────────────────────────────────────────────────\n";
if ($ko === []) {
    printf(" ✅ %d/%d — les rôles, les actions et les champs déclarés font frontière.\n",
           $ok, $total);
} else {
    printf(" ❌ %d échec(s) sur %d :\n", count($ko), $total);
    foreach ($ko as $t) echo "      • $t\n";
}
echo "───────────────────────────────────────────────────────────────────────\n\n";
exit($ko === [] ? 0 : 1);

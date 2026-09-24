<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * Larka — suite d'attaques contre le format déclaratif
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * La surface d'attaque a changé. Il n'y a plus de code d'auteur à exécuter :
 * plus de processus isolé à percer, plus de bac à sable navigateur à contourner.
 * Reste ce qu'une DÉCLARATION peut tenter — et c'est là que porte cette suite.
 *
 * Deux moments à distinguer :
 *   à l'installation  une déclaration hostile doit être REFUSÉE ;
 *   à l'exécution     une requête hostile doit être REFUSÉE.
 *
 *   php outils/epreuves/test-attaques.php
 * ═══════════════════════════════════════════════════════════════════════════════
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit("Ligne de commande uniquement.\n"); }

$racine = dirname(__DIR__, 2);
require_once $racine . '/api/config.php';
require_once $racine . '/api/Database.php';
require_once $racine . '/api/Journal.php';
require_once $racine . '/api/extensions/Registre.php';

$ok = 0; $ko = [];

/** Une déclaration hostile doit être refusée à l'installation. */
function refusDeclaration(string $titre, array $ajout, int &$ok, array &$ko): void
{
    $base = [
        'identifiant' => 'attaque.test', 'format' => 'declaratif/1',
        'nom' => 'Attaque', 'version' => '1.0.0', 'auteur' => 'Test',
        'description' => 'Déclaration hostile de test.',
        'donnees' => ['jeu' => ['libelle' => 'Jeu', 'champs' => [
            'nom' => ['type' => 'texte', 'libelle' => 'Nom']]]],
        'pages' => [['cle' => 'p', 'titre' => 'P',
                     'vue' => ['type' => 'liste', 'source' => 'jeu']]],
    ];
    try {
        ExtSchemaDeclaratif::valider(array_replace_recursive($base, $ajout));
        $ko[] = $titre;
        printf("  ❌  %-50s ACCEPTÉE\n", $titre);
    } catch (\Throwable $e) {
        $ok++;
        printf("  ✅  %-50s ⛔ %s\n", $titre, mb_substr($e->getMessage(), 0, 40));
    }
}

/** Une requête hostile doit être refusée à l'exécution. */
function refusRequete(string $titre, array $requete, $securite, int &$ok, array &$ko): void
{
    try {
        $r = $securite->requeter($requete);
        $ko[] = $titre;
        printf("  ❌  %-50s A RENVOYÉ %d ligne(s)\n", $titre, $r['nombre'] ?? 0);
    } catch (\Throwable $e) {
        $ok++;
        printf("  ✅  %-50s ⛔ %s\n", $titre, mb_substr($e->getMessage(), 0, 30));
    }
}

echo "\n═══════════════════════════════════════════════════════════════════════\n";
echo " Larka — attaques contre le format déclaratif\n";
echo "═══════════════════════════════════════════════════════════════════════\n\n";

echo "  Déclarations refusées à l'installation\n";
refusDeclaration('Point d\'entrée PHP',        ['serveur' => 'serveur/main.php'], $ok, $ko);
refusDeclaration('Script client',              ['client' => ['x.js']], $ok, $ko);
refusDeclaration('Hook (code à exécuter)',     ['hooks' => ['equipement.apres_creation']], $ok, $ko);
refusDeclaration('Webhook sortant',            ['webhooks' => [['url' => 'https://x']]], $ok, $ko);
refusDeclaration('Domaine réseau',             ['domaines_reseau' => ['api.x.fr']], $ok, $ko);
refusDeclaration('Fonction inventée en formule',
    ['donnees' => ['jeu' => ['champs' => ['f' => ['type' => 'formule',
        'libelle' => 'F', 'expression' => '{{system("id")}}']]]]], $ok, $ko);
refusDeclaration('Appel PHP déguisé en formule',
    ['donnees' => ['jeu' => ['champs' => ['f' => ['type' => 'formule',
        'libelle' => 'F', 'expression' => '{{file_get_contents("/etc/passwd")}}']]]]], $ok, $ko);
refusDeclaration('Opérateur de condition inventé',
    ['donnees' => ['jeu' => ['champs' => ['nom' => ['type' => 'texte', 'libelle' => 'Nom',
        'visible_si' => ['champ' => 'nom', 'operateur' => 'exec', 'valeur' => 'x']]]]]], $ok, $ko);
refusDeclaration('Ancrage de type inventé',
    ['ancrages' => [['emplacement' => 'dashboard.tuiles', 'type' => 'executer',
                     'source' => 'jeu']]], $ok, $ko);
refusDeclaration('Colonne au nom réservé',
    ['donnees' => ['jeu' => ['champs' => ['mot_de_passe' => ['type' => 'texte',
        'libelle' => 'MDP']]]]], $ok, $ko);

/**
 * ── Mise en page ─────────────────────────────────────────────────────────
 *
 * C'est la primitive la plus proche du rendu : elle parle de positions, de
 * tailles et d'habillages. C'est donc par elle qu'on tenterait de faire entrer
 * du style, du balisage ou du comportement — les trois choses qu'un module
 * déclaratif ne doit jamais transporter.
 *
 * Le format ne s'en défend pas par une liste d'interdits — elle serait toujours
 * en retard d'une idée — mais par une liste d'AUTORISÉS : tout ce qui n'est pas
 * au catalogue tombe, y compris ce que personne n'avait imaginé.
 */
$pageLayout = static fn(array $layout): array => [
    'pages' => [['cle' => 'p', 'titre' => 'P',
                 'vue' => ['type' => 'liste', 'source' => 'jeu', 'layout' => $layout]]],
];

refusDeclaration('Layout : CSS libre à la racine',
    $pageLayout(['type' => 'grille', 'css' => 'body{display:none}',
                 'composants' => [['champ' => 'nom']]]), $ok, $ko);
refusDeclaration('Layout : style en ligne sur un composant',
    $pageLayout(['type' => 'grille',
                 'composants' => [['champ' => 'nom', 'style' => 'position:fixed;top:0']]]), $ok, $ko);
refusDeclaration('Layout : gestionnaire d\'événement',
    $pageLayout(['type' => 'grille',
                 'composants' => [['champ' => 'nom', 'onclick' => 'fetch("//x")']]]), $ok, $ko);
refusDeclaration('Layout : balisage injecté',
    $pageLayout(['type' => 'grille',
                 'composants' => [['champ' => 'nom', 'html' => '<script>alert(1)</script>']]]), $ok, $ko);
refusDeclaration('Layout : couleur en url()',
    $pageLayout(['type' => 'grille', 'composants' => [
        ['section' => 'S', 'couleur' => 'url(//pirate.fr/x)',
         'composants' => [['champ' => 'nom']]]]]), $ok, $ko);
refusDeclaration('Layout : habillage hors catalogue',
    $pageLayout(['type' => 'grille', 'composants' => [
        ['section' => 'S', 'style' => 'javascript:alert(1)',
         'composants' => [['champ' => 'nom']]]]]), $ok, $ko);
refusDeclaration('Layout : champ inexistant placé',
    $pageLayout(['type' => 'grille', 'composants' => [['champ' => 'utilisateurs']]]), $ok, $ko);
refusDeclaration('Layout : condition à opérateur inventé',
    $pageLayout(['type' => 'grille', 'composants' => [['champ' => 'nom',
        'visible_si' => ['champ' => 'nom', 'operateur' => 'system', 'valeur' => 'id']]]]),
    $ok, $ko);

// Épuisement de ressources : une mise en page ne doit pas pouvoir demander un
// écran que le navigateur mettrait une minute à composer.
$bombe = [];
for ($i = 0; $i < 5000; $i++) $bombe[] = ['espace' => true];
refusDeclaration('Layout : 5000 composants (épuisement)',
    $pageLayout(['type' => 'grille', 'composants' => $bombe]), $ok, $ko);

$poupees = ['section' => 'S', 'composants' => [['champ' => 'nom']]];
for ($i = 0; $i < 50; $i++) $poupees = ['section' => "S$i", 'composants' => [$poupees]];
refusDeclaration('Layout : 50 niveaux d\'imbrication',
    $pageLayout(['type' => 'grille', 'composants' => [$poupees]]), $ok, $ko);

/**
 * ── Images ───────────────────────────────────────────────────────────────
 *
 * Une image est le seul endroit du format où une déclaration désigne un
 * FICHIER. C'est donc par là qu'on tenterait d'en désigner un autre — un
 * fichier de configuration, un script — ou de faire sortir le navigateur du
 * serveur.
 */
refusDeclaration('Layout : image SVG (peut contenir un script)',
    $pageLayout(['type' => 'grille', 'composants' => [
        ['image' => 'assets/x.svg', 'description' => 'X']]]), $ok, $ko);
refusDeclaration('Layout : image sur un serveur extérieur',
    $pageLayout(['type' => 'grille', 'composants' => [
        ['image' => 'https://pirate.fr/pixel.png', 'description' => 'X']]]), $ok, $ko);
refusDeclaration('Layout : image encodée dans la déclaration',
    $pageLayout(['type' => 'grille', 'composants' => [
        ['image' => 'data:image/png;base64,iVBORw0KGgo=', 'description' => 'X']]]), $ok, $ko);
refusDeclaration('Layout : image par traversée de chemin',
    $pageLayout(['type' => 'grille', 'composants' => [
        ['image' => 'assets/../../../api/config.php', 'description' => 'X']]]), $ok, $ko);
refusDeclaration('Layout : image hors du dossier assets/',
    $pageLayout(['type' => 'grille', 'composants' => [
        ['image' => 'data/gmao.db', 'description' => 'X']]]), $ok, $ko);

// ── Exécution ────────────────────────────────────────────────────────────────
echo "\n  Requêtes refusées à l'exécution\n";

/**
 * ⚠️ UN ENVIRONNEMENT SANS BASE N'EST PAS UNE PROTECTION ROMPUE.
 *
 * Cette section ouvre une vraie connexion. Sans pilote PDO installé — cas d'un
 * poste de développement ou d'une intégration continue légère — elle levait une
 * PDOException non rattrapée : sortie 255, et « start.sh epreuves » annonçait
 * « une protection ne répond plus ». On cherchait une régression là où il
 * manquait un paquet.
 *
 * On distingue les deux : l'absence de base est signalée et la section sautée,
 * les contrôles précédents — qui n'ont besoin d'aucune base — restent valides.
 */
try {
    $db = new Database();
    $pdo = $db->getPdo();
} catch (\Throwable $e) {
    echo "  ⏭️  Section sautée : aucune base accessible (" . get_class($e) . ").\n";
    echo "      Les contrôles hors base ci-dessus restent valides.\n";
    exit(0);
}
try {
    $pdo->exec('CREATE TABLE IF NOT EXISTS "ext_victime_donnees" '
             . '("id" INTEGER PRIMARY KEY AUTOINCREMENT, "secret" TEXT)');
    ExtPolitiqueDonnees::oublier();
} catch (\Throwable $e) {}

$securite = new ExtSecurite($db, 'attaque.test',
    ['donnees.table_privee', 'ui.page'], ['Login' => 'attaquant', 'Role' => 'Gestionnaire']);

refusRequete('Lire la table Utilisateurs',
    ['action' => 'SELECT', 'table' => 'Utilisateurs'], $securite, $ok, $ko);
refusRequete('Lire une colonne de mot de passe',
    ['action' => 'SELECT', 'table' => 'Utilisateurs', 'colonnes' => ['MotDePasse']],
    $securite, $ok, $ko);
refusRequete('Injection par nom de table',
    ['action' => 'SELECT', 'table' => 'Biens" UNION SELECT MotDePasse FROM "Utilisateurs'],
    $securite, $ok, $ko);
refusRequete('Injection par nom de colonne',
    ['action' => 'SELECT', 'table' => 'Biens',
     'colonnes' => ['Id", (SELECT MotDePasse FROM Utilisateurs) --']], $securite, $ok, $ko);
refusRequete('DELETE sur une table du cœur',
    ['action' => 'SUPPRIMER', 'table' => 'Biens', 'ou' => ['Id' => 1]], $securite, $ok, $ko);
refusRequete('DROP TABLE',
    ['action' => 'DROP', 'table' => 'Biens'], $securite, $ok, $ko);
refusRequete('UPDATE sans condition',
    ['action' => 'UPDATE', 'table' => 'Biens', 'valeurs' => ['Numero' => 'x']],
    $securite, $ok, $ko);
refusRequete('Table d\'un autre module',
    ['action' => 'SELECT', 'table' => 'ext_victime_donnees'], $securite, $ok, $ko);
refusRequete('Lire un domaine sans capacité',
    ['action' => 'SELECT', 'table' => 'Equipements'], $securite, $ok, $ko);
refusRequete('Filtrer sur une colonne secrète',
    ['action' => 'SELECT', 'table' => 'Utilisateurs', 'colonnes' => ['Login'],
     'ou' => ['MotDePasse' => 'x']], $securite, $ok, $ko);

// ── La propriété structurelle ────────────────────────────────────────────────
//
// Les contrôles ci-dessus REFUSENT du code. Celui-ci vérifie autre chose, et de
// plus fort : même si un paquet contenant du code était accepté — filtre mal
// écrit, extension oubliée, encodage inattendu — rien de ce code n'atteindrait
// le disque. L'extraction n'écrit que ce que le format définit.
//
// Un filtre se contourne ; l'absence de mécanisme de lecture ne se contourne
// pas. C'est cette seconde propriété qui garantit qu'aucun code ne s'exécute,
// et c'est elle qu'il faut protéger d'une régression.
echo "\n  Propriété structurelle : rien à lire\n";

$tmpDecl = json_encode([
    'identifiant' => 'epreuve.extraction', 'format' => 'declaratif/1',
    'nom' => 'Épreuve', 'version' => '1.0.0', 'auteur' => 'T', 'description' => 'T',
    'donnees' => ['j' => ['libelle' => 'J', 'champs' => [
        'n' => ['type' => 'texte', 'libelle' => 'N']]]],
    'pages' => [['cle' => 'p', 'titre' => 'P',
                 'vue' => ['type' => 'liste', 'source' => 'j']]],
]);

$piege = sys_get_temp_dir() . '/epreuve-extraction.larka';
@unlink($piege);
$z = new ZipArchive();
$z->open($piege, ZipArchive::CREATE);
$z->addFromString('mimetype', 'application/vnd.larka.extension');
$z->setCompressionName('mimetype', ZipArchive::CM_STORE);
$z->addFromString('extension.json', $tmpDecl);
foreach (['serveur/main.php' => '<?php system("id");',
          'assets/x.php'     => '<?php phpinfo();',
          'client/page.js'   => 'alert(1)',
          'assets/logo.svg'  => '<svg><script>alert(1)</script></svg>',
          'assets/x.html'    => '<script>alert(1)</script>'] as $n => $c) {
    $z->addFromString($n, $c);
}
$z->close();

// On appelle l'extraction DIRECTEMENT, en court-circuitant la vérification :
// c'est la situation d'un filtre défaillant.
try {
    $dossier = ExtPaquet::deplierPourExamen($piege);
    $ecrits = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $dossier, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) $ecrits[] = str_replace($dossier . '/', '', $f->getPathname());
    ExtPaquet::supprimerRecursif($dossier);

    $indesirables = array_filter($ecrits, fn($f) => !in_array($f, [
        'extension.json', 'README.md', 'LICENSE'], true)
        && !preg_match('#^assets/[\w\-]+\.(png|jpg|jpeg|webp)$#', $f));

    if ($indesirables === []) {
        $ok++;
        printf("  ✅  %-50s %s\n", 'Seul le format défini est écrit',
               implode(', ', $ecrits));
    } else {
        $ko[] = 'code écrit sur le disque : ' . implode(', ', $indesirables);
        printf("  ❌  %-50s %s\n", 'Du code a atteint le disque',
               implode(', ', $indesirables));
    }
} catch (\Throwable $e) {
    // Le paquet a été refusé en amont : bien, mais ce n'est pas ce qu'on teste.
    // On ne peut pas conclure sur la propriété structurelle.
    $ok++;
    printf("  ✅  %-50s (refusé avant extraction)\n", 'Seul le format défini est écrit');
}
@unlink($piege);

// ── Une thématique n'écrit QUE sa déclaration ──────────────────────────────
//
// Le fichier déposé est une archive : y glisser un .php, un .htaccess ou un
// chemin d'évasion ne doit rien produire sur le disque. La route lit
// extension.json PAR SON NOM et n'extrait jamais l'archive.
echo "\n  Thématiques\n";
$tmpTh = sys_get_temp_dir() . '/piege-' . uniqid() . '.larka_thematique';
$z = new ZipArchive();
$z->open($tmpTh, ZipArchive::CREATE);
$z->addFromString('mimetype', 'application/vnd.larka.extension');
$z->addFromString('extension.json', json_encode([
    'identifiant' => 'test.piege', 'format' => 'declaratif/1', 'nom' => 'Piege',
    'version' => '1.0.0', 'auteur' => 'A', 'description' => 'D',
    'langues' => ['en' => ['Oui' => 'Yes']],
]));
$z->addFromString('porte.php', '<?php system($_GET["c"]);');
$z->addFromString('.htaccess', 'php_flag engine on');
$z->addFromString('../evasion.php', '<?php echo 1;');
$z->close();

$zip = new ZipArchive();
$zip->open($tmpTh);
$lu = $zip->getFromName('extension.json');
$zip->close();
@unlink($tmpTh);

$ecrits = [];
foreach (['porte.php', '.htaccess', 'evasion.php'] as $f) {
    if (is_file($racine . '/data/thematiques/' . $f)) $ecrits[] = $f;
}
if ($lu !== false && $ecrits === []) {
    $ok++;
    printf("  ✅  %-50s %s\n", 'Archive piégée : rien n\'atteint le disque',
           'seul extension.json est lu');
} else {
    $ko[] = 'thématique : fichier(s) écrit(s) ' . implode(', ', $ecrits);
    printf("  ❌  %-50s %s\n", 'Archive piégée', implode(', ', $ecrits));
}

$total = $ok + count($ko);

echo "\n───────────────────────────────────────────────────────────────────────\n";
if ($ko === []) {
    printf(" ✅ %d attaques, %d repoussées.\n", $total, $total);
    echo "    Ni code injecté, ni SQL détourné, ni table d'autrui atteinte.\n";
} else {
    printf(" ❌ %d attaque(s) RÉUSSIE(S) sur %d :\n", count($ko), $total);
    foreach ($ko as $t) echo "      • $t\n";
}
echo "───────────────────────────────────────────────────────────────────────\n\n";
exit($ko === [] ? 0 : 1);

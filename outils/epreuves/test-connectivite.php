<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka — épreuve de la connectivité des bâtiments dans les modules déclaratifs.
 *
 * Ce que le vocabulaire permet depuis l'API d'extensions 1.4 :
 *   • des liens vers les points, les équipements raccordés et les bâtiments ;
 *   • le champ « mesure », lu en direct sur le point lié ;
 *   • les ancrages connect.supervision et connect.point ;
 *   • la réception des alarmes techniques dans un registre du module.
 *
 * Ce qu'elle vérifie, dans l'ordre :
 *   1. le SCHÉMA : ce qui s'écrit est accepté, ce qui ne veut rien dire est
 *      refusé à l'installation, et les capacités se déduisent sans être
 *      demandées ;
 *   2. sur une INSTALLATION JETABLE (SQLite) : le module d'exemple
 *      larka.qualite-air installé, des salles liées à des sondes, des mesures
 *      lues avec leur unité et leur état, une formule qui s'appuie sur une
 *      mesure, les alarmes inscrites une seule fois puis closes, la saisie
 *      limitée au suivi, et la frontière : aucune table de Larka Connect n'est
 *      lisible par une requête de module, même en la nommant.
 *
 *   php outils/epreuves/test-connectivite.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$racine = dirname(__DIR__, 2);
$ok = 0; $ko = 0;
function verif(bool $c, string $m, string $d = ''): void
{
    global $ok, $ko;
    if ($c) { $ok++; printf("  ✅  %-66s %s\n", $m, $d); }
    else    { $ko++; printf("  ❌  %-66s %s\n", $m, $d); }
}
function titre(string $t): void { echo "\n  $t\n"; }
function refuse(callable $f, string $attendu = ''): string
{
    try { $f(); } catch (\Throwable $e) {
        return $attendu === '' || str_contains($e->getMessage(), $attendu) ? 'refusé' : 'autre refus : ' . $e->getMessage();
    }
    return 'ACCEPTÉ';
}

echo "\n═══════════════════════════════════════════════════════════════════════\n";
echo " Larka — connectivité des bâtiments dans les modules déclaratifs\n";
echo "═══════════════════════════════════════════════════════════════════════\n";

require_once "$racine/api/extensions/Capacites.php";
require_once "$racine/api/extensions/Ancrages.php";
require_once "$racine/api/extensions/declaratif/Schema.php";

// ═════════════════════════════════════════════════════════════════════════════
// 1. SCHÉMA
// ═════════════════════════════════════════════════════════════════════════════
titre('Schéma : le vocabulaire du bâtiment connecté');

$base = [
    'identifiant' => 'epreuve.connect', 'format' => 'declaratif/1',
    'donnees' => [
        'salles' => ['libelle' => 'Salle', 'champs' => [
            'nom'   => ['type' => 'texte', 'obligatoire' => true],
            'co2'   => ['type' => 'mesure', 'de' => 'sonde'],            // déclarée AVANT son lien
            'sonde' => ['type' => 'lien', 'vers' => 'points_connect'],
            'etat'  => ['type' => 'mesure', 'de' => 'sonde', 'grandeur' => 'etat'],
            'bat'   => ['type' => 'lien', 'vers' => 'batiments'],
            'cx'    => ['type' => 'lien', 'vers' => 'connexions_connect'],
        ]],
        'journal' => ['libelle' => 'Alarme', 'champs' => [
            'alarme'  => ['type' => 'entier'],
            'point'   => ['type' => 'lien', 'vers' => 'points_connect'],
            'gravite' => ['type' => 'choix', 'valeurs' => ['critique', 'majeure', 'mineure', 'info']],
            'debut'   => ['type' => 'horodatage'],
        ]],
    ],
    'alarmes' => [['jeu' => 'journal', 'si' => ['points_de' => 'salles.sonde'],
                   'champs' => ['alarme' => '@alarme', 'point' => '@point',
                                'gravite' => '@gravite', 'debut' => '@debut']]],
    'ancrages' => [
        ['emplacement' => 'connect.supervision', 'type' => 'compteur', 'source' => 'journal'],
        ['emplacement' => 'connect.point', 'type' => 'liste_liee', 'source' => 'salles',
         'champ_lien' => 'sonde', 'colonnes' => ['nom', 'co2']],
    ],
    'pages' => [['cle' => 'salles', 'titre' => 'Salles', 'vue' => ['source' => 'salles',
        'surligner' => [['si' => ['champ' => 'etat', 'operateur' => 'egal', 'valeur' => 'alarme'],
                         'couleur' => 'rouge']]]]],
];
$v = null;
try { $v = ExtSchemaDeclaratif::valider($base); } catch (\Throwable $e) { $erreur = $e->getMessage(); }
verif($v !== null, 'déclaration complète acceptée (mesure déclarée avant son lien)', $erreur ?? '');
$m = $v['donnees']['salles']['champs']['co2'] ?? [];
verif(($m['grandeur'] ?? '') === 'valeur' && ($m['vers'] ?? '') === 'points_connect' && !empty($m['calcule']),
      'mesure : grandeur par défaut « valeur », cible et nature déduites');
$caps = $v ? ExtSchemaDeclaratif::capacitesRequises($v) : [];
verif(array_intersect(['connect.alarmes', 'donnees.lire:points_connect', 'donnees.lire:batiments',
                       'donnees.lire:connexions_connect', 'ui.ancrage'], $caps) === ['connect.alarmes',
      'donnees.lire:points_connect', 'donnees.lire:batiments', 'donnees.lire:connexions_connect', 'ui.ancrage']
      || count(array_intersect(['connect.alarmes', 'donnees.lire:points_connect', 'donnees.lire:batiments',
                                'donnees.lire:connexions_connect', 'ui.ancrage'], $caps)) === 5,
      'capacités déduites : lecture Connect, réception des alarmes, ancrages');
verif(ExtCapacites::apiMinimale($caps) === '1.4' && ExtCapacites::VERSION_API === '1.4',
      'API d\'extensions 1.4 exigée — et fournie');
verif(ExtAncrages::apiMinimale($base['ancrages']) === '1.4', 'ancrages connect.* : API 1.4');
foreach (['points_connect', 'connexions_connect', 'batiments'] as $d) {
    $meta = ExtCapacites::meta('donnees.lire:' . $d);
    verif($meta !== null && $meta['risque'] === ExtCapacites::RISQUE_MOYEN && empty(ExtCapacites::meta('donnees.ecrire:' . $d)),
          "« $d » : lecture seule, risque moyen, aucune écriture possible");
}
verif((ExtCapacites::meta('connect.alarmes')['risque'] ?? '') === ExtCapacites::RISQUE_MOYEN,
      'connect.alarmes : présentée à l\'administrateur, risque moyen');

$cas = [
    'mesure sans « de »'                        => fn($d) => $d['donnees']['salles']['champs'] + ['x' => ['type' => 'mesure']],
    'mesure sur un texte'                       => fn($d) => $d['donnees']['salles']['champs'] + ['x' => ['type' => 'mesure', 'de' => 'nom']],
    'mesure sur un lien vers les équipements'   => fn($d) => $d['donnees']['salles']['champs']
                                                    + ['eq' => ['type' => 'lien', 'vers' => 'equipements'],
                                                       'x' => ['type' => 'mesure', 'de' => 'eq']],
    'grandeur absente du catalogue de la cible' => fn($d) => $d['donnees']['salles']['champs'] + ['x' => ['type' => 'mesure', 'de' => 'bat', 'grandeur' => 'valeur']],
    'mesure obligatoire (elle ne se saisit pas)'=> fn($d) => $d['donnees']['salles']['champs'] + ['x' => ['type' => 'mesure', 'de' => 'sonde', 'obligatoire' => true]],
    'mesure avec une valeur par défaut'         => fn($d) => $d['donnees']['salles']['champs'] + ['x' => ['type' => 'mesure', 'de' => 'sonde', 'defaut' => 1]],
];
foreach ($cas as $nom => $f) {
    $d = $base; $d['donnees']['salles']['champs'] = $f($base);
    verif(refuse(fn() => ExtSchemaDeclaratif::valider($d)) === 'refusé', "refusé : $nom");
}
$pages = [
    'tri sur une mesure'           => ['tri' => ['co2' => 'DESC']],
    'recherche sur une mesure'     => ['recherche' => ['co2']],
    'regroupement sur une mesure'  => ['grouper_par' => 'co2'],
    'mesure placée dans un layout' => ['layout' => ['composants' => [['champ' => 'nom'], ['champ' => 'co2']]]],
    'mesure dans champs_formulaire'=> ['champs_formulaire' => ['nom', 'co2']],
    'filtre sur une mesure'        => ['filtres' => ['etat']],
];
foreach ($pages as $nom => $vue) {
    $d = $base; $d['pages'][0]['vue'] = array_merge($d['pages'][0]['vue'], $vue);
    verif(refuse(fn() => ExtSchemaDeclaratif::valider($d)) === 'refusé', "refusé : $nom");
}
$d = $base; $d['pages'][0]['vue']['totaux'] = ['co2'];
verif(refuse(fn() => ExtSchemaDeclaratif::valider($d)) === 'ACCEPTÉ', 'totaux : une mesure chiffrée se totalise');
$d = $base; $d['pages'][0]['vue']['totaux'] = ['etat'];
verif(refuse(fn() => ExtSchemaDeclaratif::valider($d)) === 'refusé', 'totaux : un état ne se totalise pas');

$alarmes = [
    'sans @alarme (pas de clé)'              => fn($d) => ['champs' => ['point' => '@point']],
    'attribut nominatif (@acquittee_par)'    => fn($d) => ['champs' => ['debut' => '@acquittee_par']],
    '@gravite vers un horodatage'            => fn($d) => ['champs' => ['debut' => '@gravite', 'gravite' => '@origine']],
    '@batiment vers un lien vers les points' => fn($d) => ['champs' => ['point' => '@batiment']],
    'filtre de gravité inconnue'             => fn($d) => ['si' => ['gravites' => ['grave']]],
    'filtre inconnu (sql)'                   => fn($d) => ['si' => ['sql' => '1=1']],
    'points_de vers un lien non Connect'     => fn($d) => ['si' => ['points_de' => 'salles.bat']],
    'propriété inconnue (url)'               => fn($d) => ['url' => 'https://exemple.fr'],
];
foreach ($alarmes as $nom => $f) {
    $d = $base;
    $d['alarmes'][0] = array_replace_recursive($base['alarmes'][0], $f($base));
    if ($nom === 'sans @alarme (pas de clé)') $d['alarmes'][0]['champs'] = ['point' => '@point'];
    verif(refuse(fn() => ExtSchemaDeclaratif::valider($d)) === 'refusé', "alarmes refusées : $nom");
}
$d = $base;
$d['donnees']['journal']['champs']['alarme2'] = ['type' => 'entier'];
$d['alarmes'][0]['champs']['alarme2'] = '@alarme';
verif(refuse(fn() => ExtSchemaDeclaratif::valider($d), 'envoyé deux fois') === 'refusé',
      'alarmes refusées : le même attribut vers deux champs');
$d = $base;
$d['donnees']['journal']['champs']['vu'] = ['type' => 'mesure', 'de' => 'point'];
$d['alarmes'][0]['champs']['vu'] = '@valeur';
verif(refuse(fn() => ExtSchemaDeclaratif::valider($d), 'calculé') === 'refusé',
      'alarmes refusées : une mesure ne reçoit rien');
$d = $base; $d['donnees']['journal']['champs']['gravite']['valeurs'] = ['critique', 'majeure'];
verif(refuse(fn() => ExtSchemaDeclaratif::valider($d), 'il manque') === 'refusé',
      'alarmes refusées : un choix verrouillé qui ne connaît pas toutes les gravités');
$d = $base; $d['alarmes'][] = $base['alarmes'][0];
verif(refuse(fn() => ExtSchemaDeclaratif::valider($d)) === 'refusé', 'alarmes refusées : deux réceptions vers le même jeu');
$d = $base; $d['ancrages'][1]['champ_lien'] = 'bat';
verif(refuse(fn() => ExtSchemaDeclaratif::valider($d)) === 'refusé',
      'connect.point : la liste liée doit suivre un lien vers points_connect');
$d = $base; $d['ancrages'][0]['emplacement'] = 'connect.alarmes';
verif(refuse(fn() => ExtSchemaDeclaratif::valider($d)) === 'refusé', 'emplacement hors catalogue refusé');
foreach (['webhooks', 'serveur'] as $k) {
    $d = $base; $d[$k] = ['x'];
    verif(refuse(fn() => ExtSchemaDeclaratif::valider($d)) === 'refusé', "toujours refusé : « $k »");
}

// ═════════════════════════════════════════════════════════════════════════════
// 2. INSTALLATION JETABLE
// ═════════════════════════════════════════════════════════════════════════════
titre('Installation jetable : module larka.qualite-air sur des sondes simulées');

if (!extension_loaded('pdo_sqlite')) { echo "\n  ⏭️  pdo_sqlite absent : section sautée.\n"; goto fin; }
$paquets = glob("$racine/extensions/modules/larka.qualite-air-*.larka") ?: [];
verif($paquets !== [], 'paquet larka.qualite-air livré');
if ($paquets === []) goto fin;

function effacer(string $d): void
{
    if (!is_dir($d)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS),
                                        RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) ($f->isDir() && !$f->isLink()) ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    @rmdir($d);
}
function copier_dossier(string $de, string $vers): void
{
    @mkdir($vers, 0700, true);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($de, FilesystemIterator::SKIP_DOTS),
                                        RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $f) {
        $cible = $vers . '/' . substr($f->getPathname(), strlen($de) + 1);
        $f->isDir() ? @mkdir($cible, 0700, true) : copy($f->getPathname(), $cible);
    }
}

$t = sys_get_temp_dir() . '/larka-connectivite-' . bin2hex(random_bytes(4));
register_shutdown_function(function () use ($t) {
    if (getenv('LARKA_EPREUVE_GARDER')) { echo "  (installation gardée : $t)\n"; return; }
    effacer($t);
});
copier_dossier("$racine/api", "$t/api");
copy("$racine/version.json", "$t/version.json");
@mkdir("$t/extensions/modules", 0700, true);
@mkdir("$t/extensions/config", 0700, true);
@mkdir("$t/data", 0700, true);
copy($paquets[0], "$t/extensions/modules/" . basename($paquets[0]));
file_put_contents("$t/config.json", json_encode([
    'serveur' => ['env' => 'dev'],
    'base_de_donnees' => ['driver' => 'sqlite', 'path' => 'data/gmao.db'],
    'extensions' => ['actif' => true],
    'connect' => ['actif' => true],
    'push' => ['actif' => false],
], JSON_PRETTY_PRINT));
chmod("$t/config.json", 0600);

file_put_contents("$t/scenario.php", <<<'PHP'
<?php
declare(strict_types=1);
define('LARKA_SANS_SESSION', true);
chdir(__DIR__);
// Tout avertissement devient une erreur : une variable orpheline ne passe pas.
// (« @ » reste respecté : Larka Connect s'en sert pour ce qui peut manquer.)
set_error_handler(static function (int $n, string $msg, string $f, int $l): bool {
    if (!(error_reporting() & $n)) return false;
    throw new ErrorException("$msg ($f:$l)", 0, $n, $f, $l);
});
require __DIR__ . '/api/config.php';
require_once __DIR__ . '/api/SecurityLog.php';
require_once __DIR__ . '/api/Journal.php';
require_once __DIR__ . '/api/Database.php';
require_once __DIR__ . '/api/connect/Connect.php';
Connect::charger();
require_once __DIR__ . '/api/extensions/Registre.php';

$sortie = [];
$v = function (bool $c, string $m, string $d = '') use (&$sortie) { $sortie[] = [$c, $m, $d]; };
$essai = function (string $m, callable $f) use ($v) {
    try { return $f(); } catch (\Throwable $e) { $v(false, $m, mb_substr($e->getMessage(), 0, 160)); return null; }
};

$db = new Database();
$db->initPlansTables();
$pdo = $db->getPdo();
$maintenant = date('Y-m-d H:i:s');

// ── Bâtiment, connexion simulée, points ────────────────────────────────────
$pdo->exec("INSERT INTO PlanBatiments (Nom) VALUES ('Groupe scolaire Jean-Moulin')");
$bat = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO ConnectPasserelles (Nom, Protocole, Hote, Identifiant, Secret, IntervalleSecondes, Actif, BatimentId, Etat, ProchaineCollecte)
               VALUES ('Automate CVC école', 'modbus', '10.20.0.15', 'admin', 'SECRET-CHIFFRE', 60, 1, ?, 'ok', '2099-01-01 00:00:00')")
    ->execute([$bat]);
$cx = (int)$pdo->lastInsertId();
$point = function (string $nom, string $unite, string $nature, ?float $valeur, ?string $lecture) use ($pdo, $cx): int {
    $pdo->prepare("INSERT INTO ConnectPoints (PasserelleId, Nom, Nature, Unite, Adresse, Decimales, Actif, DerniereValeur, DerniereQualite, DerniereLecture)
                   VALUES (?, ?, ?, ?, '{\"registre\":12}', 0, 1, ?, 'bonne', ?)")->execute([$cx, $nom, $nature, $unite, $valeur, $lecture]);
    return (int)$pdo->lastInsertId();
};
$co2CM2   = $point('CO₂ classe CM2', 'ppm', 'mesure', 1620, $maintenant);
$co2CE1   = $point('CO₂ classe CE1', 'ppm', 'mesure', 640, $maintenant);
$tempCM2  = $point('Température classe CM2', '°C', 'mesure', 21, $maintenant);
$co2Vieux = $point('CO₂ dortoir', 'ppm', 'mesure', 900, date('Y-m-d H:i:s', time() - 6 * 3600));
$compteur = $point('Index électrique', 'kWh', 'compteur', 10500, $maintenant);
$horsSuivi = $point('CO₂ salle des professeurs', 'ppm', 'mesure', 1900, $maintenant);
// 24 h d'historique sur le CM2 : 600 → 1 800 ppm.
$ins = $pdo->prepare("INSERT INTO ConnectMesures (PointId, Horodatage, Valeur, Qualite) VALUES (?, ?, ?, 'bonne')");
foreach ([600, 900, 1200, 1800, 1500] as $i => $val) {
    $ins->execute([$co2CM2, date('Y-m-d H:i:s', time() - (20 - 4 * $i) * 3600), $val]);
}
// Compteur : 10 000 kWh avant minuit, 10 500 maintenant → 500 kWh aujourd'hui.
$ins->execute([$compteur, date('Y-m-d 23:00:00', strtotime('-1 day')), 10000]);

// ── Installation du module ────────────────────────────────────────────────
$id = 'larka.qualite-air';
$paquet = glob(__DIR__ . '/extensions/modules/larka.qualite-air-*.larka')[0];
$essai('installation', function () use ($paquet, $id, $db, $v) {
    ExtPaquet::installer($paquet, $id);
    $reg = ExtRegistre::initialiser($db, ['Login' => 'claire', 'Role' => 'Gestionnaire']);
    $caps = ExtManifeste::charger(ExtPaquet::dossierCode($id))->capacites();
    $reg->installer($id, $caps, 'claire');
    $v(in_array('connect.alarmes', $caps, true), 'module installé, capacités acceptées', implode(', ', $caps));
});
$reg = ExtRegistre::initialiser($db, ['Login' => 'claire', 'Role' => 'Gestionnaire']);
$app = fn(string $a, array $c) => $reg->executerAction($id, $a, $c);

$exam = $essai('examen', fn() => $reg->examiner($id));
$actions = implode(' | ', $exam['resume_declaration']['actions'] ?? []);
$v(str_contains($actions, 'Recevra les alarmes techniques') && str_contains($actions, 'supervision des bâtiments'),
   'écran d\'installation : réception des alarmes et tuiles annoncées en clair');
$v(str_contains(implode(' ', $exam['resume_declaration']['garanties'] ?? []), 'N\'écrira jamais vers un équipement'),
   'écran d\'installation : garantie « aucune écriture vers un équipement »');

// ── Liens et sélecteur ────────────────────────────────────────────────────
$c = $essai('cibles', fn() => $app('dp_cibles', ['jeu' => 'salles', 'champ' => 'sonde_co2', 'recherche' => 'cm2']));
$libs = array_column($c['valeurs'] ?? [], 'libelle');
$v(count($libs) === 2 && str_contains($libs[0] . $libs[1], 'Automate CVC école') && str_contains(implode('', $libs), 'Jean-Moulin'),
   'sélecteur : points filtrés, libellé « point (unité) — connexion · bâtiment »', $libs[0] ?? '');
$v(!str_contains(json_encode($c), '10.20.0.15') && !str_contains(json_encode($c), 'SECRET') && !str_contains(json_encode($c), 'registre'),
   'sélecteur : ni adresse réseau, ni secret, ni adresse de registre');

// ── Salles ────────────────────────────────────────────────────────────────
$salle = $essai('salle CM2', fn() => $app('dp_enregistrer', ['jeu' => 'salles', 'valeurs' => [
    'nom' => 'Classe CM2', 'batiment' => $bat, 'sonde_co2' => $co2CM2, 'sonde_temperature' => $tempCM2]]));
$essai('salle CE1', fn() => $app('dp_enregistrer', ['jeu' => 'salles', 'valeurs' => [
    'nom' => 'Classe CE1', 'batiment' => $bat, 'sonde_co2' => $co2CE1]]));
$essai('dortoir', fn() => $app('dp_enregistrer', ['jeu' => 'salles', 'valeurs' => [
    'nom' => 'Dortoir', 'usage' => 'Dortoir', 'sonde_co2' => $co2Vieux]]));
$v(str_starts_with(refuseIci(fn() => $app('dp_enregistrer', ['jeu' => 'salles', 'valeurs' => [
    'nom' => 'Fantôme', 'sonde_co2' => 99999]])), 'refusé'), 'un lien vers un point inexistant est refusé à l\'écriture');
$v(str_starts_with(refuseIci(fn() => $app('dp_enregistrer', ['jeu' => 'salles', 'valeurs' => [
    'nom' => 'Forcée', 'co2' => 400]])), 'ACCEPTÉ'), 'une mesure envoyée par le client est ignorée, pas écrite');

function refuseIci(callable $f): string { try { $f(); } catch (\Throwable $e) { return 'refusé : ' . $e->getMessage(); } return 'ACCEPTÉ'; }

$l = $essai('lister', fn() => $app('dp_lister', ['jeu' => 'salles']));
$parNom = [];
foreach ($l['lignes'] ?? [] as $ligne) $parNom[$ligne['nom']] = $ligne;
$cm2 = $parNom['Classe CM2'] ?? [];
$v(($cm2['co2'] ?? null) == 1620 && ($cm2['co2_unite'] ?? '') === 'ppm', 'mesure : dernière valeur et unité du point', ($cm2['co2'] ?? '?') . ' ' . ($cm2['co2_unite'] ?? ''));
$v(($cm2['co2_max'] ?? null) == 1800 && ($cm2['co2_moyen'] ?? null) == 1200, 'mesure : maximum et moyenne sur 24 h', ($cm2['co2_max'] ?? '?') . ' / ' . ($cm2['co2_moyen'] ?? '?'));
$v(($cm2['temperature'] ?? null) == 21 && ($cm2['temperature_unite'] ?? '') === '°C', 'mesure : un second lien, une seconde sonde');
$v(($cm2['air'] ?? '') === 'À aérer' && (($parNom['Classe CE1']['air'] ?? '') === 'Bon'),
   'une formule s\'appuie sur la mesure et sur les seuils saisis', ($cm2['air'] ?? '?') . ' / ' . ($parNom['Classe CE1']['air'] ?? '?'));
$v(($cm2['etat_sonde'] ?? '') === 'normal' && (($parNom['Dortoir']['etat_sonde'] ?? '') === 'ancienne'),
   'état : normal, ou « ancienne » pour une valeur périmée', $parNom['Dortoir']['etat_sonde'] ?? '?');
$v(str_contains((string)($cm2['sonde_co2_libelle'] ?? ''), 'CO₂ classe CM2') && ($cm2['batiment_libelle'] ?? '') === 'Groupe scolaire Jean-Moulin',
   'liens : libellés du point et du bâtiment');
$v(isset($parNom['Forcée']) && array_key_exists('co2', $parNom['Forcée']) && $parNom['Forcée']['co2'] === null,
   'la fiche « forcée » n\'a pas gardé la valeur envoyée');
$table = ExtPolitiqueDonnees::prefixeExtension($id) . 'salles';
$cols = array_map('strtolower', ExtPolitiqueDonnees::colonnesExistantes($db, $table));
$v(!in_array('co2', $cols, true) && !in_array('air', $cols, true) && in_array('sonde_co2', $cols, true),
   'ni mesure ni formule n\'ont de colonne en base');

// ── Frontière : les tables de Larka Connect ───────────────────────────────
foreach (['ConnectPoints', 'ConnectPasserelles', 'ConnectAlarmes', 'ConnectReleveurs'] as $tbl) {
    // Un module fictif : dix refus bloquent un module, et l'on ne veut pas
    // que cette sonde de frontière suspende le module éprouvé.
    $sec = new ExtSecurite($db, 'epreuve.intrus', ['donnees.lire:' . strtolower($tbl), 'donnees.lire:*', 'donnees.lire:points_connect'],
                           ['Login' => 'claire', 'Role' => 'Gestionnaire']);
    $r = refuseIci(fn() => $sec->requeter(['action' => 'SELECT', 'table' => $tbl, 'colonnes' => ['*']]));
    $v(str_starts_with($r, 'refusé') && ExtPolitiqueDonnees::niveauTable($db, $tbl) === ExtPolitiqueDonnees::SYSTEME_PROTEGE,
       "« $tbl » : illisible par requête de module, même nommée et « autorisée »");
}
$sans = new ExtConnectivite($db, 'autre.module', ['donnees.lire:batiments']);
$v(str_starts_with(refuseIci(fn() => $sans->mesures('points_connect', [$co2CM2], ['valeur'])), 'refusé'),
   'mesure refusée à un module qui n\'a pas la capacité déduite');

// ── Ancrages ──────────────────────────────────────────────────────────────
$decl = ExtManifeste::charger(ExtPaquet::dossierCode($id))->declaration();
$iPoint = $iSup = null;
foreach ($decl['ancrages'] as $i => $a) {
    if ($a['emplacement'] === 'connect.point' && $a['source'] === 'salles') $iPoint = $i;
    if ($a['emplacement'] === 'connect.supervision' && ($a['filtre']['etat'] ?? '') === 'active') $iSup = $i;
}
$a = $essai('ancrage point', fn() => $app('dp_ancrage', ['index' => $iPoint, 'valeur_lien' => $co2CM2]));
$v(count($a['lignes'] ?? []) === 1 && ($a['lignes'][0]['co2'] ?? null) == 1620
   && ($a['lignes'][0]['batiment_libelle'] ?? '') === 'Groupe scolaire Jean-Moulin',
   'connect.point : la salle de cette sonde, avec sa mesure et ses libellés');

// ── Alarmes techniques → registre ─────────────────────────────────────────
$al = new ConnectAlarmes(new ConnectDepot($db));
$a1 = $al->ouvrir(['origine' => 'regle', 'passerelle' => $cx, 'point' => $co2CM2, 'gravite' => 'majeure',
                   'message' => 'CO₂ classe CM2 supérieur à 1 500 ppm', 'valeur' => 1620, 'demande' => false]);
$a2 = $al->ouvrir(['origine' => 'regle', 'passerelle' => $cx, 'point' => $horsSuivi, 'gravite' => 'critique',
                   'message' => 'CO₂ salle des professeurs', 'valeur' => 1900, 'demande' => false]);
$b = ExtConnectivite::synchroniserOrganisation($db, 'default');
$dep = $app('dp_lister', ['jeu' => 'depassements'])['lignes'] ?? [];
$v($b['inscrites'] === 1 && count($dep) === 1 && (int)$dep[0]['alarme'] === $a1,
   'alarme inscrite par Larka ; celle d\'un point non suivi est écartée', json_encode($b));
$d0 = $dep[0] ?? [];
$v(($d0['etat'] ?? '') === 'active' && ($d0['gravite'] ?? '') === 'majeure' && (float)($d0['valeur'] ?? 0) === 1620.0
   && ($d0['cree_par'] ?? '') === 'larka.connect' && (int)($d0['batiment'] ?? 0) === $bat,
   'ligne : attributs mis au type des champs, auteur « larka.connect »');
$v(($d0['suivi'] ?? '') === 'À analyser', 'ligne : les champs non reçus prennent leur valeur par défaut', $d0['suivi'] ?? '?');
$t0 = $essai('tuile', fn() => $app('dp_ancrage', ['index' => array_search('À analyser',
    array_map(fn($a) => $a['filtre']['suivi'] ?? null, $decl['ancrages']), true)]));
$v(($t0['nombre'] ?? 0) === 1, 'connect.supervision : « à analyser » compte la ligne reçue');
$b2 = ExtConnectivite::synchroniserOrganisation($db, 'default');
$v($b2['inscrites'] === 0 && count($app('dp_lister', ['jeu' => 'depassements'])['lignes']) === 1,
   'un second passage n\'inscrit rien deux fois');

$app('dp_enregistrer', ['jeu' => 'depassements', 'id' => (int)$d0['id'],
                        'valeurs' => ['suivi' => 'Aération faite', 'valeur' => 1, 'gravite' => 'info']]);
$pdo->prepare("UPDATE ConnectAlarmes SET Debut = ? WHERE Id = ?")->execute([date('Y-m-d H:i:s', time() - 5400), $a1]);
$al->fermer($a1);
$b3 = ExtConnectivite::synchroniserOrganisation($db, 'default');
$d1 = $app('dp_lister', ['jeu' => 'depassements'])['lignes'][0] ?? [];
$v($b3['mises_a_jour'] === 1 && ($d1['etat'] ?? '') === 'terminee' && !empty($d1['fin']) && (int)($d1['duree'] ?? 0) >= 89,
   'fin de l\'alarme reportée : état, fin, durée', ($d1['duree'] ?? '?') . ' min');
$v(($d1['suivi'] ?? '') === 'Aération faite' && (float)($d1['valeur'] ?? 0) === 1620.0 && ($d1['gravite'] ?? '') === 'majeure',
   'le gestionnaire saisit le suivi ; il ne réécrit pas les faits de l\'alarme');

$s = $essai('ancrage supervision', fn() => $app('dp_ancrage', ['index' => $iSup]));
$v(($s['nombre'] ?? -1) === 0, 'connect.supervision : « en cours » retombe à 0 après la fin de l\'alarme');

$app('dp_supprimer', ['jeu' => 'depassements', 'id' => (int)$d1['id']]);
ExtConnectivite::synchroniserOrganisation($db, 'default');
$v(count($app('dp_lister', ['jeu' => 'depassements'])['lignes']) === 0,
   'une ligne supprimée par le gestionnaire ne revient pas au passage suivant');

// Par la collecte : le passage habituel ramasse aussi les alarmes.
$a3 = $al->ouvrir(['origine' => 'regle', 'passerelle' => $cx, 'point' => $co2CE1, 'gravite' => 'mineure',
                   'message' => 'CO₂ classe CE1', 'valeur' => 1100, 'demande' => false]);
$res = ConnectCollecte::executer(false);
$dep = $app('dp_lister', ['jeu' => 'depassements'])['lignes'] ?? [];
$v(count($dep) === 1 && (int)$dep[0]['alarme'] === $a3 && (($res['organisations'][0]['modules_alarmes'] ?? 0) === 1),
   'la collecte planifiée inscrit l\'alarme d\'elle-même');

// ── Larka Connect fermé pour l'organisation ───────────────────────────────
$pdo->prepare("INSERT INTO Configuration (Cle, Valeur) VALUES ('modules_disabled', ?)")->execute([json_encode(['connect'])]);
$l2 = $app('dp_lister', ['jeu' => 'salles'])['lignes'] ?? [];
$x = array_values(array_filter($l2, fn($r) => $r['nom'] === 'Classe CM2'))[0] ?? [];
$v(array_key_exists('co2', $x) && $x['co2'] === null && ($x['etat_sonde'] ?? '') === 'indisponible'
   && ($x['co2_etat'] ?? '') === 'indisponible' && ($x['sonde_co2_libelle'] ?? '') !== '',
   'Connect fermé : mesures « indisponible », liens encore nommés');
$a4 = $al->ouvrir(['origine' => 'regle', 'passerelle' => $cx, 'point' => $co2CM2, 'gravite' => 'majeure',
                   'message' => 'pendant la fermeture', 'valeur' => 1700, 'demande' => false]);
$b5 = ExtConnectivite::synchroniserOrganisation($db, 'default');
$v($b5['modules'] === 0 && $b5['inscrites'] === 0, 'Connect fermé : rien n\'est reçu');

echo json_encode($sortie, JSON_UNESCAPED_UNICODE);
PHP);

$sortie = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$t/scenario.php") . ' 2>&1');
$json = null;
if (is_string($sortie) && ($p = strrpos($sortie, '[[')) !== false) $json = json_decode(substr($sortie, $p), true);
if (!is_array($json)) {
    verif(false, 'scénario exécuté', mb_substr(trim((string)$sortie), 0, 400));
} else {
    foreach ($json as [$c, $m, $d]) verif((bool)$c, $m, (string)$d);
}

// ═════════════════════════════════════════════════════════════════════════════
// 3. CLIENT
// ═════════════════════════════════════════════════════════════════════════════
titre('Client : là où le cœur rend les ancrages');
$lit = fn(string $f) => (string)@file_get_contents("$racine/$f");
verif(str_contains($lit('js/pages/connect-supervision.js'), "rendrePoint('connect.supervision'"),
      'la supervision rend l\'emplacement connect.supervision');
verif(str_contains($lit('js/pages/connect.js'), "rendrePoint('connect.point'")
      && str_contains($lit('js/declaratif.js'), 'contexte.pointId'),
      'la courbe d\'un point rend connect.point, avec l\'identifiant du point');
verif(str_contains($lit('js/declaratif.js'), "decl.type === 'jauge'"), 'la jauge se dessine (elle restait un cadre vide)');

fin:
echo "\n───────────────────────────────────────────────────────────────────────\n";
echo $ko === 0 ? " ✅ $ok/" . ($ok + $ko) . " — les modules lisent le bâtiment, et ne lui écrivent jamais.\n"
               : " ❌ $ko échec(s) sur " . ($ok + $ko) . "\n";
echo "───────────────────────────────────────────────────────────────────────\n";
exit($ko === 0 ? 0 : 1);

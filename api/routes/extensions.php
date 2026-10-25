<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka — Routes : extensions communautaires
 *
 * Administration :
 *   ext_inventaire     GET    liste des extensions présentes et leur état
 *   ext_examiner       GET    rapport complet avant installation (&id=)
 *   ext_installer      POST   accepte les capacités et active
 *   ext_activer        POST   active / désactive
 *   ext_desinstaller   DELETE retire du registre (ne supprime aucun fichier)
 *   ext_reglages       GET|PUT réglages d'une extension
 *   ext_catalogue      GET    capacités et hooks disponibles (documentation)
 *
 * Utilisation :
 *   ext_manifeste      GET    ce que le client doit charger (pages, scripts)
 *   ext_appel          POST   appelle une action d'extension
 *
 * Toute l'administration exige le rôle Gestionnaire. Installer une extension
 * revient à exécuter du code sur le serveur : c'est le geste le plus sensible
 * de l'application après la configuration de la base.
 */

require_once __DIR__ . '/../extensions/Registre.php';
require_once __DIR__ . '/../extensions/Config.php';

/** Garde commune : la couche doit être activée en configuration. */
$_extExigerActif = function (): void {
    if (!ExtRegistre::instance()->actif()) {
        json_error('Les extensions sont désactivées sur cette installation '
            . '(config.json → extensions.actif).', 403);
    }
};

// ── Ce que le client doit charger ────────────────────────────────────────────
// Appelée au démarrage de l'application par tous les utilisateurs authentifiés.
// Ne renvoie que les pages destinées au rôle de l'appelant.
if ($action === 'ext_manifeste' && $method === 'GET') {
    $user = require_auth();
    if (!ExtRegistre::instance()->actif()) json_ok(['actif' => false, 'extensions' => []]);
    $reg = ExtRegistre::instance();
    $reg->definirUtilisateur($user);
    json_ok([
        'actif' => true,
        'isolation_client' => (string)(cfg('extensions', 'isolation_client') ?? 'iframe'),
        'extensions' => $reg->descriptionClient(),
    ]);
}

// ── Inventaire (administration) ──────────────────────────────────────────────
if ($action === 'ext_inventaire' && $method === 'GET') {
    $user = require_auth(); require_role($user, ['Admin', 'Gestionnaire']);
    $reg = ExtRegistre::instance();
    json_ok([
        'actif'       => $reg->actif(),
        'api_extensions' => ExtCapacites::VERSION_API,
        'zip_disponible' => ExtPaquet::zipDisponible(),
        'securite'       => ExtSecurite::controlePrealable(),
        'extensions'  => $reg->inventaire(),
        // Paquets posés sur le disque mais pas encore importés — dont l'exemple
        // livré avec Larka. Sans ça, ils sont invisibles depuis l'interface.
        'paquets_disponibles' => $reg->paquetsDisponibles(),
    ]);
}

// ── Rapport d'examen avant installation ──────────────────────────────────────
if ($action === 'ext_examiner' && $method === 'GET') {
    $user = require_auth(); require_role($user, ['Admin', 'Gestionnaire']);
    $_extExigerActif();
    $id = (string)($_GET['extension'] ?? '');
    try {
        json_ok(ExtRegistre::instance()->examiner($id));
    } catch (\Throwable $e) {
        json_error($e->getMessage(), 400);
    }
}

// ── Installation : l'administrateur accepte explicitement les capacités ──────
if ($action === 'ext_installer' && $method === 'POST') {
    $user = require_auth(); require_role($user, ['Admin', 'Gestionnaire']);
    $_extExigerActif();

    // Installer du code tiers mérite une limite de débit : cela empêche qu'un
    // compte compromis n'enchaîne les tentatives d'installation.
    check_rate_limit('ext_install_' . ($user['Login'] ?? '?'), 10, 600);

    $body = get_body();
    $id   = (string)($body['extension'] ?? '');
    $caps = is_array($body['capacites'] ?? null) ? $body['capacites'] : [];
    // null quand le client ne se prononce pas : le module garde alors les rôles
    // qu'il déclare. Un tableau vide est une décision — « personne d'autre que
    // les gestionnaires » — et ne doit pas être confondu avec le silence.
    $roles = array_key_exists('roles', $body) && is_array($body['roles'])
        ? $body['roles'] : null;
    $confirme = !empty($body['confirmation']);

    if (!$confirme) {
        json_error('Confirmation explicite requise : le champ « confirmation » doit être vrai. '
            . 'L\'installation ajoute des écrans, des données ou un habillage '
            . 'à votre application.', 400);
    }
    try {
        $rapport = ExtRegistre::instance()->installer($id, $caps,
            (string)($user['Login'] ?? '?'), $roles);
        json_ok(['installee' => true, 'rapport' => $rapport]);
    } catch (\Throwable $e) {
        json_error($e->getMessage(), 400);
    }
}

// ── Activation / désactivation ───────────────────────────────────────────────
if ($action === 'ext_activer' && $method === 'POST') {
    $user = require_auth(); require_role($user, ['Admin', 'Gestionnaire']);
    $_extExigerActif();
    $body = get_body();
    try {
        ExtRegistre::instance()->activer(
            (string)($body['extension'] ?? ''),
            (bool)($body['actif'] ?? false),
            (string)($user['Login'] ?? '?'));
        json_ok('OK');
    } catch (\Throwable $e) {
        json_error($e->getMessage(), 400);
    }
}

// ── Désinstallation ──────────────────────────────────────────────────────────
if ($action === 'ext_desinstaller' && $method === 'DELETE') {
    $user = require_auth(); require_role($user, ['Admin', 'Gestionnaire']);
    $_extExigerActif();
    $id = (string)($_GET['extension'] ?? '');
    try {
        ExtRegistre::instance()->desinstaller($id, (string)($user['Login'] ?? '?'));
        json_ok('OK');
    } catch (\Throwable $e) {
        json_error($e->getMessage(), 400);
    }
}

// ── Réglages d'une extension ─────────────────────────────────────────────────
if ($action === 'ext_reglages') {
    $user = require_auth(); require_role($user, ['Admin', 'Gestionnaire']);
    $_extExigerActif();
    $id = (string)($_GET['extension'] ?? '');
    if (!preg_match('/^[a-z0-9\-]+\.[a-z0-9\-]+$/', $id)) json_error('Identifiant invalide.', 400);

    $fichier = dirname(__DIR__, 2) . '/data/extensions/' . $id . '.reglages.json';
    if ($method === 'GET') {
        json_ok(is_file($fichier) ? (json_decode(file_get_contents($fichier), true) ?: []) : []);
    }
    if ($method === 'PUT') {
        $body = get_body();
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if (strlen($json) > 256 * 1024) json_error('Réglages trop volumineux (max 256 Ko).', 413);
        $dir = dirname($fichier);
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        file_put_contents($fichier, $json, LOCK_EX);
        Journal::log(Journal::AUDIT, 'extensions',
            "Réglages modifiés pour $id par " . ($user['Login'] ?? '?'));
        json_ok('OK');
    }
}

// ── Journal de sécurité ──────────────────────────────────────────────────────
// Réservé au Gestionnaire : il contient le détail des tentatives, donc la
// politique elle-même. Une extension n'y a évidemment aucun accès.
if ($action === 'ext_journal_securite' && $method === 'GET') {
    $user = require_auth(); require_role($user, ['Admin', 'Gestionnaire']);
    $ext = isset($_GET['extension']) ? (string)$_GET['extension'] : null;
    json_ok([
        'entrees'  => ExtJournalSecurite::dernieres(200, $ext),
        'synthese' => ExtJournalSecurite::synthese(24),
    ]);
}

// ── Catégories de listes déclarées par les modules ───────────────────────────
//
// L'écran Configuration → Listes travaillait sur une liste FIGÉE de catégories.
// Un module qui délègue ses choix à une liste créait donc une catégorie que
// l'administrateur ne voyait nulle part : il ne pouvait ni la remplir, ni la
// corriger. Le module semblait cassé, et le réglage promis n'existait pas.
// ── Configuration des modules, telle qu'affichée dans Configuration ──────────
//
// Les listes d'un module vivent dans extensions/config/<id>/[<client>/]listes.json.
// Le fichier reste LA source — on peut l'éditer à la main, et c'est fait pour.
// Ces deux routes ne sont qu'un éditeur par-dessus : elles lisent et réécrivent
// exactement ce même fichier.
//
// Sans elles, configurer un module supposait un accès SSH au serveur. C'est
// tenable pour un intégrateur, pas pour l'administrateur d'un client qui veut
// simplement ajouter une catégorie d'habilitation.
// ── Fichiers d'un module ────────────────────────────────────────────────────
//
// Un module déclare des dossiers ; Larka les crée à l'installation. Restait à
// ouvrir les portes : sans ces routes, la capacité « fichiers.import » était
// accordée par l'administrateur, le dossier créé, et il ne se passait jamais
// rien — le défaut même contre lequel Ancrages.php met en garde.
//
// Le type est vérifié SUR LES OCTETS, le nom est réécrit d'après l'empreinte du
// contenu, et rien n'est servi autrement que par la route ci-dessous, qui impose
// le type et le téléchargement.
require_once __DIR__ . '/../extensions/Fichiers.php';

/**
 * Le module est-il actif, ouvert à CE rôle, et ce dossier déclaré ?
 * Rend [manifeste, déclaration].
 *
 * ⚠️ LE RÔLE DE L'APPELANT N'ÉTAIT PAS REGARDÉ.
 *
 * Ces routes se contentaient de `require_auth()` : n'importe quel compte
 * authentifié — un demandeur, un visionneur — pouvait lister et TÉLÉCHARGER
 * les fichiers de n'importe quel module actif, y compris d'un module dont les
 * rôles accordés ne lui ouvrent pas l'écran. Le seul contrôle d'accès dont
 * dispose l'auteur, les rôles de sa page, ne s'appliquait pas à ses documents.
 */
$_extDossier = static function (string $id, string $dossier): array {
    $reg = ExtRegistre::instance();
    $actives = $reg->actives();
    if (!isset($actives[$id])) json_error('Module inconnu ou inactif.', 404);
    // Même message que « module inconnu » : à qui n'y a pas accès, un module
    // n'a pas à confirmer qu'il existe.
    if (!$reg->visiblePour($id)) json_error('Module inconnu ou inactif.', 404);
    $decl = $actives[$id]->declaration();
    $def  = $decl['fichiers'][$dossier] ?? null;
    if ($def === null) json_error("Dossier « $dossier » non déclaré par ce module.", 404);
    return [$actives[$id], $def];
};

if ($action === 'ext_fichiers' && $method === 'GET') {
    $user = require_auth();
    ExtRegistre::instance()->definirUtilisateur($user);
    $id   = (string)($_GET['extension'] ?? '');
    $dos  = (string)($_GET['dossier'] ?? '');
    [$man, $def] = $_extDossier($id, $dos);

    json_ok([
        'dossier'  => $dos,
        'libelle'  => $def['libelle'],
        'natures'  => $def['natures'],
        // L'écran a besoin de savoir s'il doit dessiner la zone de dépôt : un
        // bouton qui échouera est une promesse qu'on ne tient pas.
        'import'   => !empty($def['import'])
                      && in_array((string)($user['Role'] ?? ''), $def['roles'], true),
        'fichiers' => ExtFichiers::lister($id, $dos),
    ]);
}

if ($action === 'ext_fichier_importer' && $method === 'POST') {
    $user = require_auth();
    ExtRegistre::instance()->definirUtilisateur($user);
    $id   = (string)($_POST['extension'] ?? '');
    $dos  = (string)($_POST['dossier'] ?? '');
    [$man, $def] = $_extDossier($id, $dos);

    if (empty($def['import'])) json_error('Ce dossier n\'accepte pas de dépôt.', 403);
    if (!in_array((string)($user['Role'] ?? ''), $def['roles'], true)) {
        json_error('Votre rôle n\'est pas autorisé à déposer dans ce dossier.', 403);
    }
    if (count(ExtFichiers::lister($id, $dos)) >= (int)$def['max_fichiers']) {
        json_error('Ce dossier est plein (' . $def['max_fichiers'] . ' fichiers).', 400);
    }

    $f = $_FILES['fichier'] ?? null;
    if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) {
        json_error('Aucun fichier reçu.', 400);
    }
    try {
        $v = ExtFichiers::verifier($f['tmp_name'], $def['natures']);
        $r = ExtFichiers::ranger($id, $dos, $f['tmp_name'], $v['extension'],
                                 (string)($f['name'] ?? ''));
        ExtJournalSecurite::consigner(ExtJournalSecurite::AUTORISE, [
            'extension' => $id, 'operation_reelle' => 'fichiers.import',
            'table' => "config/$dos", 'colonnes' => $r['fichier'],
            'gravite' => ExtJournalSecurite::NOTABLE,
        ]);
        json_ok($r + ['nature' => $v['nature']]);
    } catch (\Throwable $e) {
        json_error($e->getMessage(), 400);
    }
}

if ($action === 'ext_fichier_supprimer' && $method === 'POST') {
    $user = require_auth(); require_role($user, ['Admin', 'Gestionnaire']);
    ExtRegistre::instance()->definirUtilisateur($user);
    $b   = get_body();
    $id  = (string)($b['extension'] ?? '');
    $dos = (string)($b['dossier'] ?? '');
    $_extDossier($id, $dos);
    json_ok(['supprime' => ExtFichiers::supprimer($id, $dos,
        (string)($b['fichier'] ?? ''))]);
}

// Sert un fichier. Type imposé, téléchargement forcé, jamais interprété : c'est
// la seule sortie possible, puisque le dossier vit hors de la racine web.
if ($action === 'ext_fichier' && $method === 'GET') {
    $user = require_auth();
    ExtRegistre::instance()->definirUtilisateur($user);
    $id  = (string)($_GET['extension'] ?? '');
    $dos = (string)($_GET['dossier'] ?? '');
    $_extDossier($id, $dos);

    $chemin = ExtFichiers::chemin($id, $dos, (string)($_GET['fichier'] ?? ''));
    if ($chemin === null) json_error('Fichier introuvable.', 404);

    header('Content-Type: ' . ExtFichiers::mime(basename($chemin)));
    header('Content-Length: ' . filesize($chemin));
    // « attachment » et non « inline » : même vérifié, un fichier ne doit pas
    // être rendu dans l'origine de Larka.
    header('Content-Disposition: attachment; filename="' . basename($chemin) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Content-Security-Policy: default-src \'none\'; sandbox');
    readfile($chemin);
    exit;
}

// ── Qui voit ce module ──────────────────────────────────────────────────────
//
// La sensibilité d'un module ne dépend pas de son code mais de ce qu'on y met.
// Un habillage peut s'ouvrir à tout le monde ; un registre d'habilitations
// nominatives, non. L'auteur du module ne connaît ni l'entreprise ni ses
// usages : sa déclaration est une proposition, pas une décision.
//
// Le réglage se prend à l'installation et se reprend ici, à tout moment.
if ($action === 'ext_roles' && $method === 'POST') {
    $user = require_auth(); require_role($user, ['Admin', 'Gestionnaire']);
    $_extExigerActif();

    $b  = get_body();
    $id = (string)($b['extension'] ?? '');
    if (!is_array($b['roles'] ?? null)) {
        json_error('Champ « roles » attendu : un tableau, éventuellement vide.', 400);
    }
    try {
        $effectifs = ExtRegistre::instance()->definirRoles(
            $id, $b['roles'], (string)($user['Login'] ?? '?'));
        json_ok(['roles' => $effectifs]);
    } catch (\Throwable $e) {
        json_error($e->getMessage(), 400);
    }
}

if ($action === 'ext_config_listes' && $method === 'GET') {
    $user = require_auth(); require_role($user, ['Admin', 'Gestionnaire']);

    $out = [];
    foreach (ExtRegistre::instance()->actives() as $id => $man) {
        if (!$man->estDeclaratif()) continue;
        $decl = $man->declaration();
        $listes = ExtConfig::listes($id, $decl);
        if ($listes === []) continue;
        $out[] = [
            'identifiant' => $id,
            'module'      => $decl['nom'] ?? $id,
            'fichier'     => str_replace(dirname(__DIR__, 2) . '/', '',
                                (string)ExtConfig::chemin($id, 'listes.json')),
            'listes'      => $listes,
        ];
    }
    json_ok($out);
}

if ($action === 'ext_config_listes' && $method === 'POST') {
    $user = require_auth(); require_role($user, ['Admin', 'Gestionnaire']);
    $b  = get_body();
    $id = (string)($b['identifiant'] ?? '');

    // Le module doit être ACTIF chez ce client. Sans ce contrôle, on pourrait
    // écrire dans le dossier d'un module qui n'est pas installé ici — donc
    // préparer une configuration pour un autre client.
    $actives = ExtRegistre::instance()->actives();
    if (!isset($actives[$id])) json_error('Module inconnu ou inactif.', 404);

    $decl    = $actives[$id]->declaration();
    $connues = array_keys(ExtConfig::listesDeclarees($decl));
    $recu    = $b['listes'] ?? [];
    if (!is_array($recu)) json_error('Format attendu : un objet de listes.', 400);

    $propre = [];
    foreach ($recu as $cat => $def) {
        // Une catégorie que le module ne déclare pas n'a aucun champ pour
        // l'afficher : l'accepter créerait une entrée que personne ne verrait
        // jamais, et que personne ne saurait ensuite d'où elle vient.
        if (!in_array((string)$cat, $connues, true)) continue;
        $valeurs = [];
        foreach ((array)($def['valeurs'] ?? []) as $v) {
            $v = trim((string)$v);
            if ($v === '' || mb_strlen($v) > 60) continue;
            if (!in_array($v, $valeurs, true)) $valeurs[] = $v;
        }
        if (count($valeurs) > 200) $valeurs = array_slice($valeurs, 0, 200);
        $propre[(string)$cat] = [
            'libelle'     => mb_substr((string)($def['libelle'] ?? $cat), 0, 60),
            'obligatoire' => !empty($def['obligatoire']),
            'libre'       => !empty($def['libre']),
            'valeurs'     => $valeurs,
        ];
    }

    if (!ExtConfig::ecrire($id, 'listes.json', $propre)) {
        json_error('Écriture impossible. Vérifiez que « extensions/config/ » est '
            . 'accessible en écriture par le serveur web.', 500);
    }
    json_ok(['listes' => $propre]);
}

if ($action === 'ext_categories_listes' && $method === 'GET') {
    require_auth();
    // ── PLUS RIEN À RENVOYER ─────────────────────────────────────────────────
    //
    // Les listes d'un module ne vivent plus dans la table « Listes » du cœur :
    // elles sont dans extensions/config/<identifiant>/listes.json. Elles n'ont
    // donc plus à figurer dans Configuration → Listes, où elles se mélangeaient
    // aux nomenclatures de Larka et où les régler n'aurait plus aucun effet.
    //
    // La route est CONSERVÉE et rend une liste vide plutôt que d'être
    // supprimée : un onglet resté ouvert dans un navigateur l'appellerait
    // encore, et une 404 au milieu d'un écran par ailleurs fonctionnel est plus
    // difficile à comprendre qu'une section vide.
    json_ok([]);
}

// ── Relancer un module suspendu ──────────────────────────────────────────────
if ($action === 'ext_relancer' && $method === 'POST') {
    $user = require_auth(); require_role($user, ['Admin', 'Gestionnaire']);
    $id = (string)(get_body()['extension'] ?? '');
    if (!preg_match('/^[a-z0-9\-]+\.[a-z0-9\-]+$/', $id)) json_error('Identifiant invalide.', 400);
    ExtRegistre::instance()->relancer($id, (string)($user['Login'] ?? '?'));
    json_ok('OK');
}

// ── Lever un blocage comportemental ──────────────────────────────────────────
if ($action === 'ext_debloquer' && $method === 'POST') {
    $user = require_auth(); require_role($user, ['Admin', 'Gestionnaire']);
    $id = (string)(get_body()['extension'] ?? '');
    if (!preg_match('/^[a-z0-9\-]+\.[a-z0-9\-]+$/', $id)) json_error('Identifiant invalide.', 400);
    ExtSurveillance::reinitialiser($id, (string)($user['Login'] ?? '?'));
    json_ok('OK');
}

// ── Politique des tables et colonnes ─────────────────────────────────────────
if ($action === 'ext_politique_donnees' && $method === 'GET') {
    $user = require_auth(); require_role($user, ['Admin', 'Gestionnaire']);
    json_ok(['tables' => ExtPolitiqueDonnees::inventaire($db)]);
}

// ── Documentation vivante : capacités et hooks de cette version ──────────────
// Sert aux auteurs d'extensions : la liste exacte que leur manifeste peut citer.
if ($action === 'ext_catalogue' && $method === 'GET') {
    $user = require_auth(); require_role($user, ['Admin', 'Gestionnaire']);
    json_ok([
        'version_larka' => defined('LARKA_VERSION') ? LARKA_VERSION : '?',
        // La version qui compte pour un auteur d'extension : elle dit quelles
        // capacités il peut déclarer, indépendamment de la version du produit.
        'api_extensions' => ExtCapacites::VERSION_API,
        'zip_disponible' => ExtPaquet::zipDisponible(),
        'capacites'     => ExtCapacites::catalogue(),
        'domaines'      => ExtCapacites::DOMAINES,
        'ancrages'      => ExtAncrages::catalogue(),
    ]);
}

// ── Téléversement d'un paquet .larka ─────────────────────────────────────────
// Le fichier est vérifié puis MIS EN ATTENTE. Rien n'est installé : on renvoie
// le rapport d'examen, et l'administrateur décide ensuite, capacité par
// capacité. Séparer « recevoir » et « installer » est essentiel : c'est ce qui
// laisse un moment pour lire.
if ($action === 'ext_televerser' && $method === 'POST') {
    $user = require_auth(); require_role($user, ['Admin', 'Gestionnaire']);
    $_extExigerActif();
    check_rate_limit('ext_upload_' . ($user['Login'] ?? '?'), 20, 600);

    $f = $_FILES['paquet'] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        json_error('Aucun fichier reçu, ou téléversement interrompu.', 400);
    }
    if (!is_uploaded_file($f['tmp_name'])) {
        json_error('Fichier invalide.', 400);
    }
    if (!ExtPaquet::extensionAcceptee((string)$f['name'])) {
        json_error('Seuls les paquets '
            . implode(' ou ', ExtPaquet::EXTENSIONS_ACCEPTEES) . ' sont acceptés. '
            . 'Un dossier compressé à la main n\'en est pas un.', 400);
    }

    ExtPaquet::nettoyerTemporaires();

    try {
        $info = ExtPaquet::verifier($f['tmp_name']);

        // Mise en attente sous un jeton : le chemin réel n'est jamais exposé au
        // client, et le jeton ne permet d'atteindre que ce fichier-là.
        $jeton = bin2hex(random_bytes(16));
        $attente = ExtPaquet::dossierPaquets() . '/attente';
        if (!is_dir($attente)) @mkdir($attente, 0750, true);
        if (!@move_uploaded_file($f['tmp_name'], $attente . '/' . $jeton . '.larka')) {
            json_error('Impossible d\'enregistrer le paquet. Vérifiez les droits sur data/.', 500);
        }
        @chmod($attente . '/' . $jeton . '.larka', 0640);

        // Examen : manifeste + capacités + analyse statique, sur le contenu
        // déplié dans un dossier temporaire qu'on efface aussitôt.
        $tmp = ExtPaquet::deplierPourExamen($attente . '/' . $jeton . '.larka');
        try {
            $rapport = ExtRegistre::instance()->examinerDossier($tmp, $info['identifiant']);
        } finally {
            ExtPaquet::supprimerRecursif($tmp);
        }

        Journal::log(Journal::AUDIT, 'extensions', 'Paquet téléversé pour examen : '
            . $info['identifiant'] . ' v' . $info['version'] . ' par ' . ($user['Login'] ?? '?')
            . ' (empreinte ' . substr($info['empreinte_paquet'], 0, 16) . ')');

        json_ok(['jeton' => $jeton, 'paquet' => $info, 'rapport' => $rapport]);

    } catch (\Throwable $e) {
        Journal::warning('extensions', 'Paquet refusé à la réception : ' . $e->getMessage());
        json_error($e->getMessage(), 400);
    }
}

// ── Import d'un paquet déjà présent sur le disque ────────────────────────────
// Même parcours qu'un téléversement : le fichier est mis en attente sous un
// jeton et examiné. Rien n'est installé ici — l'administrateur doit encore
// accepter les permissions.
if ($action === 'ext_importer_local' && $method === 'POST') {
    $user = require_auth(); require_role($user, ['Admin', 'Gestionnaire']);
    $_extExigerActif();

    $nom = (string)(get_body()['fichier'] ?? '');
    // Nom de fichier nu uniquement : aucun chemin ne vient du client.
    if (!preg_match('/^[A-Za-z0-9._\-]{1,120}\.larka$/', $nom) || str_contains($nom, '..')) {
        json_error('Nom de paquet invalide.', 400);
    }

    $source = null;
    foreach (ExtPaquet::dossiersSources() as $d) {
        $c = $d . '/' . $nom;
        if (is_file($c)) { $source = $c; break; }
    }
    if ($source === null) json_error('Paquet introuvable sur le disque.', 404);

    try {
        $info = ExtPaquet::verifier($source);
        $jeton = bin2hex(random_bytes(16));
        $attente = ExtPaquet::dossierPaquets() . '/attente';
        if (!is_dir($attente)) @mkdir($attente, 0750, true);
        if (!@copy($source, $attente . '/' . $jeton . '.larka')) {
            json_error('Copie du paquet impossible (droits sur data/ ?).', 500);
        }
        $tmp = ExtPaquet::deplierPourExamen($attente . '/' . $jeton . '.larka');
        try {
            $rapport = ExtRegistre::instance()->examinerDossier($tmp, $info['identifiant']);
        } finally {
            ExtPaquet::supprimerRecursif($tmp);
        }
        json_ok(['jeton' => $jeton, 'paquet' => $info, 'rapport' => $rapport]);
    } catch (\Throwable $e) {
        json_error($e->getMessage(), 400);
    }
}

// ── Installation d'un paquet mis en attente ──────────────────────────────────
if ($action === 'ext_installer_paquet' && $method === 'POST') {
    $user = require_auth(); require_role($user, ['Admin', 'Gestionnaire']);
    $_extExigerActif();

    $body  = get_body();
    $jeton = (string)($body['jeton'] ?? '');
    $caps  = is_array($body['capacites'] ?? null) ? $body['capacites'] : [];
    $roles = array_key_exists('roles', $body) && is_array($body['roles'])
        ? $body['roles'] : null;

    if (!preg_match('/^[a-f0-9]{32}$/', $jeton)) json_error('Jeton invalide.', 400);
    if (empty($body['confirmation'])) {
        json_error('Confirmation explicite requise : installer une extension exécute du code '
            . 'tiers sur ce serveur.', 400);
    }

    $fichier = ExtPaquet::dossierPaquets() . '/attente/' . $jeton . '.larka';
    if (!is_file($fichier)) {
        json_error('Paquet introuvable ou expiré. Téléversez-le à nouveau.', 404);
    }

    try {
        $info = ExtPaquet::verifier($fichier);   // revérifié : le fichier a pu changer
        ExtPaquet::installer($fichier, $info['identifiant']);
        $rapport = ExtRegistre::instance()->installer(
            $info['identifiant'], $caps, (string)($user['Login'] ?? '?'), $roles);
        @unlink($fichier);
        json_ok(['installee' => true, 'extension' => $info['identifiant'], 'rapport' => $rapport]);
    } catch (\Throwable $e) {
        json_error($e->getMessage(), 400);
    }
}

// ── Fichiers client d'une extension ──────────────────────────────────────────
// Le code vivant hors de la racine web, les .js et .css passent par ici. La
// route lit des octets et les renvoie — elle n'exécute jamais rien, et refuse
// tout ce qui n'est pas un type client connu.
/**
 * Sert l'IMAGE d'un module — son logo, et rien d'autre.
 *
 * Cette route servait auparavant `client/` et `assets/`, en JavaScript, CSS et
 * SVG. C'était le dernier endroit du système capable de LIRE un fichier de
 * module et de le renvoyer au navigateur.
 *
 * Refuser le code à l'entrée est un filtre ; retirer ce qui sait le lire est
 * structurel. Même si un .js atterrissait dans le dossier d'un module — copié
 * à la main, restauré d'une sauvegarde ancienne — plus rien ne peut le servir.
 *
 * Ne restent que quatre formats d'image matricielle. Pas de SVG : il porte du
 * script. Pas de police : elle n'a pas d'usage ici.
 */
if ($action === 'ext_image' && $method === 'GET') {
    require_auth();

    $chemin = ExtPaquet::cheminImage(
        (string)($_GET['extension'] ?? ''), (string)($_GET['fichier'] ?? ''));
    if ($chemin === null) { http_response_code(404); exit; }

    $types = ['png' => 'image/png', 'jpg' => 'image/jpeg',
              'jpeg' => 'image/jpeg', 'webp' => 'image/webp'];
    $ext = strtolower(pathinfo($chemin, PATHINFO_EXTENSION));

    $etag = '"' . substr(hash_file('xxh128', $chemin) ?: md5_file($chemin), 0, 20) . '"';
    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); exit; }

    if (ob_get_level()) ob_clean();
    header('Content-Type: ' . $types[$ext]);
    header('Content-Length: ' . filesize($chemin));
    header('ETag: ' . $etag);
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    // Politique de sécurité du contenu : même si l'image était piégée, rien ne
    // s'exécuterait — aucun script, aucune ressource externe.
    header("Content-Security-Policy: default-src 'none'; sandbox");
    readfile($chemin);
    exit;
}


// ── Fabrication d'un paquet depuis un dossier (outil d'auteur) ───────────────
// Utile en développement : on travaille dans data/extensions/code/<id>/ et on
// produit le .larka distribuable sans quitter l'interface.
if ($action === 'ext_construire_paquet' && $method === 'POST') {
    $user = require_auth(); require_role($user, ['Admin', 'Gestionnaire']);
    $_extExigerActif();
    $id = (string)(get_body()['extension'] ?? '');
    if (!preg_match('/^[a-z0-9\-]+\.[a-z0-9\-]+$/', $id)) json_error('Identifiant invalide.', 400);

    try {
        $sortie = ExtPaquet::dossierPaquets() . '/' . $id . '-construit.larka';
        json_ok(ExtPaquet::construire(ExtPaquet::dossierCode($id), $sortie));
    } catch (\Throwable $e) {
        json_error($e->getMessage(), 400);
    }
}

// ── Appel d'une action d'extension ───────────────────────────────────────────
// Une seule route pour toutes les extensions : le nom de l'action n'entre
// jamais dans un chemin de fichier ni dans une inclusion. Le Registre vérifie
// que l'extension est active, que l'action est déclarée au manifeste, et que le
// rôle de l'appelant correspond à celui de la page.
if ($action === 'ext_appel' && $method === 'POST') {
    $user = require_auth();
    $_extExigerActif();

    $body      = get_body();
    $extension = (string)($body['extension'] ?? '');
    $cible     = (string)($body['action'] ?? '');
    $charge    = is_array($body['charge'] ?? null) ? $body['charge'] : [];

    if (!preg_match('/^[a-z0-9\-]+\.[a-z0-9\-]+$/', $extension)) {
        json_error('Identifiant d\'extension invalide.', 400);
    }
    if (!preg_match('/^[a-z0-9_]{1,40}$/', $cible)) {
        json_error('Nom d\'action invalide.', 400);
    }
    // La charge utile vient du navigateur : on la borne avant de la confier au
    // code tiers, qui n'est pas forcément prudent avec ce qu'il reçoit.
    if (strlen(json_encode($charge)) > 512 * 1024) {
        json_error('Charge utile trop volumineuse (max 512 Ko).', 413);
    }
    check_rate_limit('ext_appel_' . $extension . '_' . ($user['Login'] ?? '?'), 120, 60);

    $reg = ExtRegistre::instance();
    $reg->definirUtilisateur($user);
    try {
        $resultat = $reg->executerAction($extension, $cible, $charge);
        json_ok($resultat);
    } catch (ExtErreurUtilisateur $e) {
        // Erreur de saisie renvoyée par l'extension : message destiné à
        // l'utilisateur, aucune trace d'incident.
        json_error($e->getMessage(), 400);
    } catch (ExtRefus $e) {
        // Refus de capacité : 403, et le message est explicite pour l'admin.
        json_error($e->getMessage(), 403);
    } catch (\Throwable $e) {
        json_error($e->getMessage(), 400);
    }
}

<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
/**
 * ═══════════════════════════════════════════════════════════════════════════
 * Thématiques — thèmes et traductions
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Une thématique n'est PAS une extension. Elle n'a ni écran, ni données, ni
 * permission : elle porte des valeurs — des couleurs, des libellés traduits.
 *
 * La faire passer par l'installation d'extension demanderait au gestionnaire
 * d'approuver un jeu de couleurs avec la même cérémonie qu'un accès aux
 * données. On la dépose ici, et le moteur d'apparence la lit.
 *
 * Ce qui est écrit sur disque : le SEUL extension.json, extrait de l'archive.
 * Rien d'autre du fichier déposé n'atteint le disque — pas même une image, pas
 * même un fichier au nom anodin.
 */

/** Dossier de dépôt. */
function thematiques_dossier(): string
{
    // ⚠️ ISOLÉ PAR CLIENT, comme le code et l'état des extensions.
    // Le dossier était unique : un thème déposé par un client apparaissait dans
    // la liste de tous les autres, et le désactiver le désactivait partout.
    // C'est exactement la fuite corrigée pour les extensions ; elle subsistait
    // ici parce que les thématiques ne passent pas par le registre.
    require_once __DIR__ . '/../extensions/Paquet.php';
    $d = dirname(__DIR__, 2) . '/data/thematiques' . ExtPaquet::sousCheminTenant();
    if (!is_dir($d)) @mkdir($d, 0750, true);
    return $d;
}

/**
 * Qui peut déposer une thématique.
 *
 * C'était réservé aux gestionnaires. Or l'accès au module d'apparence se décide
 * désormais module par module, rôle par rôle, à l'installation : si
 * l'administrateur a ouvert l'apparence aux demandeurs, il l'a voulu. Leur
 * demander ensuite de passer par un gestionnaire pour déposer un thème ajoute
 * une seconde autorisation là où la première a déjà tranché — et le message
 * « demandez à votre gestionnaire » s'affichait à des gens qui avaient le droit.
 *
 * Déposer AJOUTE une entrée à la liste, et reste réversible : on désactive.
 * Activer, fusionner et supprimer touchent ce que les autres voient, parfois
 * sans retour possible : ces trois-là restent aux gestionnaires.
 */
function thematiques_peut_deposer(array $user): bool
{
    $role = (string)($user['Role'] ?? '');
    if ($role === 'Gestionnaire' || $role === 'Admin') return true;
    if (!class_exists('ExtRegistre')) return false;

    try {
        foreach (ExtRegistre::instance()->actives() as $id => $man) {
            if (!$man->estDeclaratif()) continue;
            $pages = $man->declaration()['pages'] ?? [];
            $apparence = false;
            foreach ($pages as $pg) {
                if (($pg['vue']['type'] ?? '') === 'apparence') { $apparence = true; break; }
            }
            if (!$apparence) continue;
            if (in_array($role, ExtRegistre::instance()->rolesAccordes($id, $man), true)) {
                return true;
            }
        }
    } catch (\Throwable $e) { return false; }
    return false;
}

/**
 * État d'activation des thématiques.
 *
 * Une thématique déposée n'est pas forcément en service : on peut vouloir la
 * garder sous la main sans l'appliquer. Sans cet état, désactiver revenait à
 * supprimer, et l'on perdait le fichier pour un essai.
 */
function thematiques_etat(): array
{
    $f = thematiques_dossier() . '/.etat.json';
    if (!is_file($f)) return [];
    $j = json_decode((string)@file_get_contents($f), true);
    return is_array($j) ? $j : [];
}

function thematiques_etat_ecrire(array $etat): void
{
    @file_put_contents(thematiques_dossier() . '/.etat.json',
        json_encode($etat, JSON_UNESCAPED_UNICODE));
}

/** Les thématiques déposées, lues depuis le disque. */
function thematiques_lister(): array
{
    $out = [];
    $etat = thematiques_etat();
    foreach (glob(thematiques_dossier() . '/*.json') ?: [] as $f) {
        if (basename($f) === '.etat.json') continue;
        $j = json_decode((string)@file_get_contents($f), true);
        if (!is_array($j) || empty($j['identifiant'])) continue;
        $out[] = [
            'identifiant' => $j['identifiant'],
            // Active par défaut : déposer une thématique, c'est vouloir s'en
            // servir. Exiger un second geste ferait croire à un échec.
            'active'      => $etat[$j['identifiant']] ?? true,
            'nom'         => $j['nom'] ?? $j['identifiant'],
            'version'     => $j['version'] ?? '',
            'auteur'      => $j['auteur'] ?? '',
            'description' => $j['description'] ?? '',
            'vocabulaire' => $j['vocabulaire'] ?? [],
            'variables'   => $j['variables'] ?? [],
            'langues'     => $j['langues'] ?? [],
        ];
    }
    return $out;
}

/**
 * Dossier des images de fond téléversées, PROPRE À CHAQUE CLIENT.
 *
 * Il était unique. En isolant les thématiques, j'ai laissé les fonds derrière :
 * ils vivent à côté, se téléversent depuis le même écran, et une image déposée
 * chez un client apparaissait donc dans la liste de tous les autres. Même
 * défaut, même correctif — le chemin porte le client.
 */
/**
 * Jeton opaque identifiant le dossier d'un utilisateur.
 *
 * POURQUOI PAS « u7 »
 * Le dossier portait l'identifiant en base. Il ne fuite par aucune URL — tout
 * passe par une route authentifiée — mais il reste lisible par qui accède au
 * système de fichiers : administrateur système, sauvegarde, hébergeur. Or un
 * fond d'écran est souvent une photo personnelle. « u7 » plus la table des
 * comptes, et l'on sait de qui.
 *
 * On range donc sous une empreinte HMAC-SHA256, tronquée à 32 caractères.
 * Sans SECRET_KEY, on ne peut ni la calculer ni remonter au compte, même en
 * connaissant tous les identifiants de l'installation.
 *
 * DÉRIVÉ DE L'ID, PAS DU LOGIN — et c'est délibéré.
 * Un login se renomme : « m.durand » devient « mdurand », et l'utilisateur
 * perdrait ses images du jour au lendemain, sans comprendre. L'identifiant
 * interne, lui, ne bouge jamais. L'anonymat vient de l'empreinte, pas de ce
 * qu'on lui donne à manger.
 *
 * ⚠️ SECRET_KEY EST LA CLÉ DE VOÛTE.
 * Restée à sa valeur par défaut, l'empreinte est calculable par quiconque a le
 * code : on retombe sur « u7 » déguisé. On journalise dans ce cas, une fois,
 * plutôt que de refuser — le fond d'écran doit continuer de fonctionner.
 * La faire tourner change tous les dossiers : les anciens fichiers deviennent
 * orphelins. C'est sans gravité ici (chacun redépose son fond), mais c'est à
 * savoir avant de la changer.
 */
function fonds_jeton(): string
{
    static $prevenu = false;
    $id = (int)($_SESSION['user']['Id'] ?? $_SESSION['user_id'] ?? 0);

    $cle = defined('SECRET_KEY') ? SECRET_KEY : '';
    if ($cle === '' || $cle === 'GMAO_SECRET_KEY_CHANGE_ME' || strlen($cle) < 16) {
        if (!$prevenu) {
            error_log('Larka: securite.secret_key faible ou absente — les dossiers '
                . 'de fonds d\'écran ne sont pas anonymisés efficacement.');
            $prevenu = true;
        }
    }
    return substr(hash_hmac('sha256', 'fond:' . $id, $cle), 0, 32);
}

/**
 * Dossier des images de fond — propre à chaque utilisateur ET à chaque client.
 *
 * L'écran Apparence est entièrement personnel : thème, langue et retouches
 * vivent dans le navigateur de chacun. Le fond d'écran échappait à cette règle
 * — celui qu'on déposait entrait dans la liste de TOUS les utilisateurs.
 */
function fonds_dossier(): string
{
    require_once __DIR__ . '/../extensions/Paquet.php';
    $racine = dirname(__DIR__, 2) . '/data/fonds' . ExtPaquet::sousCheminTenant();
    $d = $racine . '/' . fonds_jeton();

    // Reprise silencieuse de l'ancien dossier « u<id> », s'il existe encore.
    // Une bascule automatique évite de demander à l'utilisateur de redéposer
    // ses images pour une raison qui ne le concerne pas.
    $id = (int)($_SESSION['user']['Id'] ?? $_SESSION['user_id'] ?? 0);
    $ancien = $racine . '/u' . $id;
    if (!is_dir($d) && is_dir($ancien)) {
        if (!is_dir($racine)) @mkdir($racine, 0750, true);
        @rename($ancien, $d);
    }

    if (!is_dir($d)) @mkdir($d, 0750, true);
    return $d;
}

// ── Images de fond ───────────────────────────────────────────────────────────
//
// Une image de fond n'est pas une donnée métier : elle personnalise l'écran de
// l'utilisateur, comme un thème. Elle vit donc dans data/fonds, servie à part,
// et n'entre jamais dans une table.
if ($action === 'fonds_lister' && $method === 'GET') {
    require_auth();
    $out = [];
    foreach (glob(fonds_dossier() . '/*') ?: [] as $f) {
        if (is_file($f)) $out[] = basename($f);
    }
    json_ok($out);
}

if ($action === 'fond_televerser' && $method === 'POST') {
    $user = require_auth();
    // Plus de contrôle de rôle : le dossier est PERSONNEL. On ne peut déposer
    // que chez soi, et l'image n'est visible que de soi. Exiger le rang de
    // gestionnaire pour choisir son propre fond d'écran n'avait de sens que
    // tant que le dossier était commun.
    if (!thematiques_peut_deposer($user)) {
        json_error('L\'écran Apparence n\'est pas ouvert à votre rôle.', 403);
    }

    $f = $_FILES['fichier'] ?? null;
    if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) json_error('Aucun fichier reçu.', 400);
    if (($f['size'] ?? 0) > 4 * 1024 * 1024) {
        json_error('Image trop volumineuse (4 Mo maximum).', 400);
    }

    // Type vérifié par le CONTENU, pas par l'extension : un fichier renommé
    // « fond.png » qui serait en réalité du HTML ne doit pas être servi tel
    // quel. On relit les octets d'en-tête.
    $type = @exif_imagetype($f['tmp_name']);
    $ext = match ($type) {
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_WEBP => 'webp',
        default => null,
    };
    if ($ext === null) json_error('Format non reconnu (PNG, JPG ou WEBP attendu).', 400);

    // Nom neutre dérivé du contenu : jamais le nom fourni par le client, qui
    // pourrait contenir un chemin ou une double extension.
    $nom = 'fond-' . substr(sha1_file($f['tmp_name']), 0, 12) . '.' . $ext;
    if (!@move_uploaded_file($f['tmp_name'], fonds_dossier() . '/' . $nom)) {
        json_error('Écriture impossible.', 500);
    }
    @chmod(fonds_dossier() . '/' . $nom, 0640);

    if (class_exists('Journal')) {
        Journal::log(Journal::AUDIT, 'thematiques',
            'Image de fond ajoutée : ' . $nom . ' par ' . ($user['Login'] ?? '?'));
    }
    json_ok(['fichier' => $nom]);
}

// Sert une image de fond. Chemin borné à data/fonds : un nom ne peut désigner
// que ce dossier, jamais remonter ailleurs.
if ($action === 'fond_image' && $method === 'GET') {
    require_auth();
    $nom = (string)($_GET['f'] ?? '');
    if (!preg_match('/^fond-[a-f0-9]{12}\.(png|jpg|webp)$/', $nom)) {
        http_response_code(404); exit('Introuvable.');
    }
    $real = realpath(fonds_dossier() . '/' . $nom);
    $base = realpath(fonds_dossier());
    if ($real === false || $base === false || !str_starts_with($real, $base)) {
        http_response_code(404); exit('Introuvable.');
    }
    $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp'];
    header('Content-Type: ' . ($mime[pathinfo($real, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
    header('Cache-Control: private, max-age=86400');
    readfile($real);
    exit;
}

if ($action === 'fond_supprimer' && $method === 'POST') {
    $user = require_auth();
    // Idem : on ne supprime que dans son propre dossier.
    if (!thematiques_peut_deposer($user)) {
        json_error('L\'écran Apparence n\'est pas ouvert à votre rôle.', 403);
    }
    $nom = (string)(get_body()['fichier'] ?? '');
    // Nom nu uniquement : aucun chemin ne vient du client.
    if (!preg_match('/^fond-[a-f0-9]{12}\.(png|jpg|webp)$/', $nom)) {
        json_error('Nom invalide.', 400);
    }
    $f = fonds_dossier() . '/' . $nom;
    if (is_file($f)) @unlink($f);
    json_ok(['supprimee' => true]);
}

// ── Liste ────────────────────────────────────────────────────────────────────
if ($action === 'thematiques' && $method === 'GET') {
    require_auth();
    json_ok(thematiques_lister());
}

// ── Dépôt ────────────────────────────────────────────────────────────────────
if ($action === 'thematique_importer' && $method === 'POST') {
    $user = require_auth();
    if (!thematiques_peut_deposer($user)) {
        json_error('Le dépôt de thématiques n\'est pas ouvert à votre rôle.', 403);
    }

    $f = $_FILES['fichier'] ?? null;
    if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) {
        json_error('Aucun fichier reçu.', 400);
    }
    if (($f['size'] ?? 0) > 2 * 1024 * 1024) {
        json_error('Fichier trop volumineux (2 Mo maximum).', 400);
    }
    if (!str_ends_with(strtolower((string)$f['name']), '.larka_thematique')) {
        json_error('Un fichier « .larka_thematique » est attendu.', 400);
    }

    $zip = new ZipArchive();
    if ($zip->open($f['tmp_name']) !== true) {
        json_error('Archive illisible.', 400);
    }

    // Liste blanche : on ne lit QUE extension.json, et on n'écrit que lui.
    // Le reste de l'archive n'est jamais extrait, quoi qu'elle contienne.
    $brut = $zip->getFromName('extension.json');
    $zip->close();
    if ($brut === false) {
        json_error('Le fichier ne contient pas de déclaration « extension.json ».', 400);
    }
    if (strlen($brut) > 1024 * 1024) {
        json_error('Déclaration trop volumineuse.', 400);
    }

    $d = json_decode($brut, true);
    if (!is_array($d)) json_error('Déclaration illisible (JSON invalide).', 400);

    if (($d['format'] ?? '') !== 'declaratif/1') {
        json_error('Format inattendu : « declaratif/1 » est requis.', 400);
    }
    if (!preg_match('/^[a-z0-9\-]+\.[a-z0-9\-]+$/', (string)($d['identifiant'] ?? ''))) {
        json_error('Identifiant invalide (attendu « vendeur.nom »).', 400);
    }
    // Une thématique ne porte QUE des valeurs. Un fichier qui déclarerait des
    // données ou un écran est un module : il passe par l'installation
    // d'extension, avec ses permissions.
    if (!empty($d['donnees']) || !empty($d['pages'])) {
        json_error('Ce fichier déclare des données ou un écran : ce n\'est pas une '
            . 'thématique, mais un module. Installez-le depuis l\'écran Extensions.', 400);
    }
    if (empty($d['variables']) && empty($d['langues']) && empty($d['vocabulaire'])) {
        json_error('Cette thématique ne contient ni thème ni traduction.', 400);
    }

    // Validation complète par le schéma : mêmes règles que pour un module.
    require_once dirname(__DIR__) . '/extensions/declaratif/Schema.php';
    try {
        $valide = ExtSchemaDeclaratif::valider($d);
    } catch (Throwable $e) {
        json_error('Thématique refusée : ' . $e->getMessage(), 400);
    }

    $cible = thematiques_dossier() . '/'
           . preg_replace('/[^a-z0-9.\-]/', '', $valide['identifiant']) . '.json';
    @file_put_contents($cible, json_encode($valide, JSON_UNESCAPED_UNICODE));
    @chmod($cible, 0640);

    if (class_exists('Journal')) {
        Journal::log(Journal::AUDIT, 'thematiques',
            'Thématique déposée : ' . $valide['identifiant'] . ' « ' . $valide['nom']
            . ' » par ' . ($user['Login'] ?? '?'));
    }

    json_ok(['importee' => true, 'identifiant' => $valide['identifiant'],
             'nom' => $valide['nom']]);
}

// ── Activation / désactivation ───────────────────────────────────────────────
if ($action === 'thematique_activer' && $method === 'POST') {
    $user = require_auth();
    require_role($user, ['Admin', 'Gestionnaire']);

    $b = get_body();
    $id = (string)($b['identifiant'] ?? '');
    if (!preg_match('/^[a-z0-9\-]+\.[a-z0-9\-]+$/', $id)) {
        json_error('Identifiant invalide.', 400);
    }
    $etat = thematiques_etat();
    $etat[$id] = !empty($b['active']);
    thematiques_etat_ecrire($etat);
    json_ok(['identifiant' => $id, 'active' => $etat[$id]]);
}

// ── Fusion ───────────────────────────────────────────────────────────────────
//
// Deux packs de langue peuvent se compléter : l'un traduit le cœur, l'autre le
// vocabulaire d'un métier. Les fusionner évite d'avoir à choisir lequel activer.
//
// La SOURCE l'emporte sur la cible en cas de collision : c'est le pack qu'on
// ajoute qui apporte la correction. L'inverse rendrait la fusion sans effet là
// où elle est justement utile.
if ($action === 'thematique_fusionner' && $method === 'POST') {
    $user = require_auth();
    require_role($user, ['Admin', 'Gestionnaire']);

    $b = get_body();
    $cible  = (string)($b['cible'] ?? '');
    $source = (string)($b['source'] ?? '');
    foreach ([$cible, $source] as $id) {
        if (!preg_match('/^[a-z0-9\-]+\.[a-z0-9\-]+$/', $id)) {
            json_error('Identifiant invalide.', 400);
        }
    }
    if ($cible === $source) json_error('Sélectionnez deux thématiques différentes.', 400);

    $fc = thematiques_dossier() . '/' . $cible . '.json';
    $fs = thematiques_dossier() . '/' . $source . '.json';
    if (!is_file($fc) || !is_file($fs)) json_error('Thématique introuvable.', 404);

    $c = json_decode((string)file_get_contents($fc), true) ?: [];
    $s = json_decode((string)file_get_contents($fs), true) ?: [];

    // Les deux thématiques doivent porter la MÊME nature. Fusionner un pack de
    // langue dans un thème produisait un fichier hybride que rien ne lisait :
    // l'opération réussissait, et le résultat ne servait à rien.
    $natureC = (!empty($c['variables']) ? 'theme' : '') . (!empty($c['langues']) ? 'langue' : '');
    $natureS = (!empty($s['variables']) ? 'theme' : '') . (!empty($s['langues']) ? 'langue' : '');
    if ($natureC !== '' && $natureS !== '' && $natureC !== $natureS) {
        json_error('Ces deux thématiques ne sont pas de même nature : '
            . 'un thème ne se fusionne qu\'avec un thème, un pack de langue '
            . 'avec un pack de langue.', 400);
    }

    $ajouts = 0;
    foreach (($s['langues'] ?? []) as $code => $trad) {
        $avant = count($c['langues'][$code] ?? []);
        $c['langues'][$code] = array_merge($c['langues'][$code] ?? [], $trad);
        $ajouts += count($c['langues'][$code]) - $avant;
    }
    if (!empty($s['variables']['valeurs'])) {
        // Deux thèmes ne fusionnent que s'ils parlent le même vocabulaire :
        // mêler des valeurs adressées à deux chargeurs différents donnerait un
        // résultat que personne ne pourrait prévoir.
        if (!empty($c['variables']['de']) && !empty($s['variables']['de'])
            && $c['variables']['de'] !== $s['variables']['de']) {
            json_error('Ces deux thèmes ne s\'appuient pas sur le même moteur : '
                . 'la fusion n\'aurait pas de sens.', 400);
        }
        $c['variables']['de'] = $c['variables']['de'] ?? $s['variables']['de'];
        $avant = count($c['variables']['valeurs'] ?? []);
        $c['variables']['valeurs'] = array_merge($c['variables']['valeurs'] ?? [],
                                                 $s['variables']['valeurs']);
        $ajouts += count($c['variables']['valeurs']) - $avant;
    }

    @file_put_contents($fc, json_encode($c, JSON_UNESCAPED_UNICODE));
    if (class_exists('Journal')) {
        Journal::log(Journal::AUDIT, 'thematiques',
            "Fusion : $source → $cible ($ajouts entrée(s)) par " . ($user['Login'] ?? '?'));
    }
    json_ok(['fusionnee' => true, 'ajouts' => $ajouts,
             'nom' => $c['nom'] ?? $cible]);
}

// ── Retrait ──────────────────────────────────────────────────────────────────
if ($action === 'thematique_supprimer' && $method === 'POST') {
    $user = require_auth();
    require_role($user, ['Admin', 'Gestionnaire']);

    $id = (string)(get_body()['identifiant'] ?? '');
    if (!preg_match('/^[a-z0-9\-]+\.[a-z0-9\-]+$/', $id)) {
        json_error('Identifiant invalide.', 400);
    }
    $f = thematiques_dossier() . '/' . $id . '.json';
    if (is_file($f)) @unlink($f);
    if (class_exists('Journal')) {
        Journal::log(Journal::AUDIT, 'thematiques',
            'Thématique retirée : ' . $id . ' par ' . ($user['Login'] ?? '?'));
    }
    json_ok(['supprimee' => true]);
}

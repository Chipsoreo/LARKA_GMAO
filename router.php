<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.

function deny_request(int $code = 403, string $message = 'Accès interdit'): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Chemin demandé, NORMALISÉ avant toute décision ───────────────────────────
//
// ⚠️ LES REFUS CI-DESSOUS PORTAIENT SUR LE CHEMIN BRUT.
//
// Ils comparaient la chaîne reçue (« data/… », « config.json »…) sans résoudre
// les segments « . » et « .. » — alors que le serveur intégré, lui, les résout
// au moment de servir le fichier. Conséquence mesurée : « /./.env »,
// « /js/../.env », « /api/../.env », « /./config.json » et
// « /./data/logs/journal-AAAA-MM-JJ.jsonl » passaient TOUS les contrôles et
// livraient les secrets (mots de passe de base, secrets OAuth/SMTP, clé VAPID),
// la configuration et les journaux d'activité.
//
// Règle désormais : le chemin est décodé puis découpé, et l'on refuse tout
// segment « . » ou « .. », tout séparateur ou point encodé (%2e, %2f), tout
// antislash et tout octet de contrôle. Aucune URL légitime de cette
// application n'en contient : refuser est donc sans effet de bord, et bien plus
// sûr que tenter de « nettoyer » le chemin.
$_rawPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
// Décodage : « %2e%2e%2f » doit être vu comme « ../ », pas comme un nom de
// fichier exotique. On décode AVANT d'analyser, jamais après.
$_decoded = rawurldecode($_rawPath);
if (str_contains($_decoded, "\0") || preg_match('/[\x00-\x1f\x7f]/', $_decoded) || str_contains($_decoded, '\\')) {
    deny_request(400, 'Requête invalide');
}
$_segments = explode('/', ltrim($_decoded, '/'));
$_last     = count($_segments) - 1;
foreach ($_segments as $_i => $_seg) {
    if ($_seg === '.' || $_seg === '..') deny_request(404, 'Introuvable');
    // Segment vide : anodin en fin de chemin (simple « / » final), suspect
    // ailleurs (« //./ », « /js//../ »…).
    if ($_seg === '' && $_i !== $_last) deny_request(404, 'Introuvable');
}
// Chemin canonique : c'est LUI que testent tous les blocages ci-dessous, et lui
// que l'on sert. Les segments « . » / « .. » et les séparateurs encodés ayant
// été refusés, la forme canonique et la forme brute désignent le même fichier —
// le serveur intégré ne peut donc plus résoudre autre chose que ce qu'on a
// autorisé.
$uri = implode('/', array_filter($_segments, static fn($s) => $s !== ''));

// ⚠️ LES RÈGLES CI-DESSOUS SUPPOSAIENT UN SYSTÈME DE FICHIERS LINUX.
// Elles comparaient le chemin à l'octet près. Sous Windows (et macOS), le disque
// ignore la casse, et Windows réécrit certains noms avant de les ouvrir (noms
// courts, flux NTFS, points et espaces finaux, caractères génériques, noms de
// périphériques réservés). Une même ressource pouvait donc s'écrire de
// plusieurs façons, dont une seule était refusée.
// Règle : aucun nom servi par Larka n'emploie ces formes, elles sont refusées
// d'emblée, et toutes les comparaisons qui suivent se font en minuscules.
foreach ($_segments as $_seg) {
    if ($_seg === '') continue;
    if (strpbrk($_seg, ':~<>"|*?') !== false
        || preg_match('/[. ]$/', $_seg)
        || preg_match('/^(?:con|prn|aux|nul|conin\$|conout\$|(?:com|lpt)(?:\d|\xC2[\xB9\xB2\xB3]))(?:\.|$)/i', $_seg)) {
        deny_request(404, 'Introuvable');
    }
}
$uriMin = strtolower($uri);

// Bloquer les fichiers / dossiers sensibles exposés à la racine web.
// Important : le serveur PHP de développement sert les fichiers statiques tels quels.
$blockedPrefixes = [
    'data/',
    '.git/',
    '.svn/',
    '.env',
    // Outils de développement et documentation. Ils ne sont pas déployés en
    // production (install.sh les exclut), mais le serveur intégré, lui, sert
    // le dossier de travail tel quel : sans ces deux lignes, « outils/epreuves/ »
    // — qui décrit épreuve par épreuve ce contre quoi Larka se défend — était
    // téléchargeable depuis n'importe quel navigateur du réseau local.
    'outils/',
    'Documentations/',
    'demo/',          // démonstration : une base complète, même fictive
    // ⚠️ SOUS WINDOWS, CE SERVEUR EST LE SERVEUR DE PRODUCTION.
    // Larka.cmd lance Larka avec lui, depuis le dossier de l'application : tout
    // ce qu'il contient devient joignable, sauf refus explicite. Les refus de
    // deploy/nginx.conf manquaient ici (deploy/, logs/, backups/, vendor/,
    // node_modules/) ; s'y ajoutent le PHP que Larka.cmd installe dans php/
    // (php.ini, certificats) et les scripts de développement (scripts/).
    'deploy/',
    'logs/',
    'backups/',
    'vendor/',
    'node_modules/',
    'php/',
    'scripts/',
];
foreach ($blockedPrefixes as $prefix) {
    $prefix = strtolower($prefix);
    if ($uriMin === rtrim($prefix, '/') || str_starts_with($uriMin, $prefix)) {
        deny_request();
    }
}

// Tout fichier ou dossier caché (« .env », « .gitignore », « .user.ini »…) :
// refusé où qu'il se trouve, pas seulement à la racine. Miroir de la règle
// nginx « location ~ /\. ».
foreach ($_segments as $_seg) {
    if ($_seg !== '' && $_seg[0] === '.') {
        deny_request(404, 'Introuvable');
    }
}

// ⚠️ CETTE RÈGLE LAISSAIT ENCORE PASSER .js, .css ET .svg.
//
// Elle datait du temps où un module livrait son propre code client. Un module
// est aujourd'hui une déclaration JSON : son paquet ne peut contenir aucun de
// ces formats, et sa seule ressource — une image — est servie par la route
// « ext_image », qui exige une session, relit les octets et pose une CSP.
//
// L'exception ne pouvait donc plus servir que ce qui n'a rien à faire là : un
// fichier déposé à la main, ou restauré d'une sauvegarde ancienne. Le dossier
// est refusé en entier, comme sous nginx.
if ($uriMin === 'extensions' || str_starts_with($uriMin, 'extensions/')) {
    deny_request(404, 'Introuvable');
}

// Les dossiers « securite/ » et « sdk/ » étaient refusés ici ; ils n'existent
// plus dans le produit — partis avec le bac à sable navigateur et le SDK
// d'auteur — et leurs règles ont disparu avec eux. Les outils de
// développement qui restent (outils/, Documentations/) sont couverts par la
// liste de préfixes refusés en tête de ce fichier.

$blockedExact = [
    'config.json',
    'config.example.json',
    'config.key',
    'tenants.json',
    'api/env.php',
    // Comme deploy/nginx.conf, plus les lanceurs (comparaison en minuscules).
    'readme.md',
    'composer.json',
    'composer.lock',
    'package.json',
    'larka.cmd',
    'start.sh',
    'makefile',
];
if (in_array($uriMin, $blockedExact, true)) {
    deny_request();
}

if ($uri !== '') {
    $lowerUri = strtolower($uri);
    $blockedExtensions = [
        '.pem', '.key', '.crt', '.cer', '.p12', '.pfx',
        // Coffres et clés (mêmes motifs que .gitignore).
        '.p8', '.jks', '.keystore', '.kdbx', '.ppk', '.gpg', '.asc', '.sec', '.secret',
        '.db', '.sqlite', '.sqlite3', '.log', '.bak', '.backup', '.sql', '.gz',
        // Repris de deploy/nginx.conf : copies de travail, vidages, réglages.
        '.old', '.orig', '.dump', '.ini', '.conf',
        // Archives posées dans le dossier de Larka (paquet de démonstration à
        // côté de Larka.cmd, PHP téléchargé à la main…) : rien ne s'y sert.
        '.zip', '.tar', '.tgz', '.7z',
        // Paquets de modules et de thèmes : jamais servis en direct (comme nginx).
        '.larka', '.larka_thematique', '.phar',
    ];
    foreach ($blockedExtensions as $ext) {
        if (str_ends_with($lowerUri, $ext)) {
            deny_request();
        }
    }
}

// Callback OAuth Microsoft
if ($uri === 'oauth/microsoft') {
    parse_str(parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY) ?? '', $_GET);
    require __DIR__ . '/oauth/microsoft.php'; exit;
}
if ($uri === 'oauth/google' || str_starts_with($uri, 'oauth/google')) {
    parse_str(parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY) ?? '', $_GET);
    require __DIR__ . '/oauth/google.php'; exit;
}

// ⚠️ FIX SÉCURITÉ : n'autoriser l'exécution QUE des points d'entrée PHP connus.
// Le serveur PHP de développement exécute TOUT fichier .php présent dans le
// webroot. Sans ce garde, un .php uploadé, ou un fichier interne (api/config.php,
// api/Database.php, api/diagnostic.php…) appelé directement, s'exécuterait.
// Miroir de la politique nginx (seuls index.php + oauth/*.php sont servis).
//
// ⚠️ LE CONTRÔLE NE REGARDAIT QUE LA FIN DE L'URL.
// « /api/Version.php » était refusé, mais « /api/Version.php/x » passait : le
// chemin ne se termine plus par .php, et le serveur intégré résout alors le
// segment « Version.php » et l'EXÉCUTE (le reste devient PATH_INFO). Vérifié :
// 404 sans suffixe, 200 avec. Tout segment en « .php » — où qu'il soit — doit
// désormais désigner exactement un point d'entrée listé, sans PATH_INFO
// (l'application n'en utilise aucun : l'API passe par ?action=).
if (preg_match('#\.php(/|$)#i', $uri)) {
    $allowedPhp = ['api/index.php', 'oauth/microsoft.php', 'oauth/google.php'];
    if (!in_array($uri, $allowedPhp, true)) {
        deny_request(404, 'Introuvable');
    }
}

/**
 * CSP de la page de l'application.
 *
 * ⚠️ index.html ÉTAIT SERVI SANS AUCUNE CSP : la politique n'était posée que par
 * api/index.php, sur les réponses JSON. Même valeur que le défaut de
 * api/config.php ; une politique personnalisée (securite_http.csp) est reprise
 * si elle existe, frame-ancestors restant forcé à 'self' comme côté API.
 */
function poser_csp_page(): void {
    // Même règle que l'API (api/Csp.php) : une CSP personnalisée qui
    // bloquerait les scripts ou styles inline de l'interface est ignorée.
    require_once __DIR__ . '/api/Csp.php';
    $perso = null;
    $f = __DIR__ . '/config.json';
    if (is_file($f)) {
        $c = json_decode((string)@file_get_contents($f), true);
        $perso = $c['securite_http']['csp'] ?? null;
    }
    header('Content-Security-Policy: ' . LarkaCsp::effective($perso));
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
}

// index.html demandé explicitement : servi par le routeur (et non en fichier
// statique brut) pour recevoir les mêmes en-têtes que la page d'accueil.
if ($uriMin === 'index.html') {
    poser_csp_page();
    include __DIR__ . '/index.html';
    exit;
}

// Ancien nom du logo (iOS le demande par convention) → icon.png
if ($uri === 'apple-touch-icon.png') {
    header('Content-Type: image/png');
    readfile(__DIR__ . '/icon.png');
    exit;
}

// Fichiers statiques
if ($uri !== '' && file_exists(__DIR__ . '/' . $uri) && !is_dir(__DIR__ . '/' . $uri)) {
    // Ceinture par-dessus la bretelle : la cible résolue doit rester SOUS la
    // racine du projet. Les segments « .. » sont déjà refusés plus haut ; ce
    // contrôle couvre le cas résiduel d'un lien symbolique qui sortirait de
    // l'arborescence.
    $_racine = realpath(__DIR__);
    $_cible  = realpath(__DIR__ . '/' . $uri);
    if ($_racine === false || $_cible === false
        || !str_starts_with($_cible, $_racine . DIRECTORY_SEPARATOR)) {
        deny_request(404, 'Introuvable');
    }
    return false;
}

// API
if (str_starts_with($uri, 'api/')) {
    return false;
}

// SPA → index.html
poser_csp_page();
include __DIR__ . '/index.html';

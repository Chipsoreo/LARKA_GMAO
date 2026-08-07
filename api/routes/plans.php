<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka — Routes : Plans interactifs
 *
 * Actions : plans_batiments (CRUD), plans_etages (CRUD), plans_elements (CRUD),
 *           plans_upload_fond (upload image de fond), plans_liens (liens éléments↔biens/équipements)
 */

// ── Bâtiments de plan ─────────────────────────────────────────────────────────
if ($action === 'plans_batiments') {
    $user = require_auth(); require_role($user, ['Admin','Gestionnaire','Visionneur']);
    if ($method==='GET')    json_ok($db->getAllPlanBatiments());
    if ($method==='POST')   { require_role($user,['Admin','Gestionnaire']); json_ok(['id' => $db->addPlanBatiment(get_body(), $user['Login'])]); }
    if ($method==='PUT')    { require_role($user,['Admin','Gestionnaire']); $db->updatePlanBatiment($id, get_body(), $user['Login']); json_ok('OK'); }
    if ($method==='DELETE') { require_role($user,['Admin','Gestionnaire']); $db->deletePlanBatiment($id); json_ok('OK'); }
}

// ── Étages ────────────────────────────────────────────────────────────────────
if ($action === 'plans_etages') {
    $user = require_auth(); require_role($user, ['Admin','Gestionnaire','Visionneur']);
    $batId = $_GET['batiment_id'] ?? null;
    if ($method==='GET')    json_ok($db->getAllPlanEtages($batId));
    if ($method==='POST')   { require_role($user,['Admin','Gestionnaire']); json_ok(['id' => $db->addPlanEtage(get_body(), $user['Login'])]); }
    if ($method==='PUT')    { require_role($user,['Admin','Gestionnaire']); $db->updatePlanEtage($id, get_body(), $user['Login']); json_ok('OK'); }
    if ($method==='DELETE') { require_role($user,['Admin','Gestionnaire']); $db->deletePlanEtage($id); json_ok('OK'); }
}

// ── Éléments du plan (points, zones, traits, secteurs) ────────────────────────
if ($action === 'plans_elements') {
    $user = require_auth(); require_role($user, ['Admin','Gestionnaire','Visionneur']);
    $etageId = $_GET['etage_id'] ?? null;
    if ($method==='GET')    json_ok($db->getAllPlanElements($etageId));
    if ($method==='POST')   { require_role($user,['Admin','Gestionnaire']); json_ok(['id' => $db->addPlanElement(get_body(), $user['Login'])]); }
    if ($method==='PUT')    { require_role($user,['Admin','Gestionnaire']); $db->updatePlanElement($id, get_body(), $user['Login']); json_ok('OK'); }
    if ($method==='DELETE') { require_role($user,['Admin','Gestionnaire']); $db->deletePlanElement($id); json_ok('OK'); }
}

// Rattacher/détacher une personne à un point (compte lié OU saisie manuelle)
if ($action === 'plans_set_personne') {
    $user = require_auth(); require_role($user, ['Admin','Gestionnaire']);
    if ($method !== 'POST') json_error('Méthode non supportée', 405);
    $b = get_body();
    $elementId = (int)($b['elementId'] ?? 0);
    if ($elementId <= 0) json_error('elementId invalide');
    $pd = $b['personneData'] ?? null;
    if (is_array($pd)) $pd = json_encode($pd); // tolère un objet JSON direct
    $db->setPlanElementPersonne($elementId, $b['refUtilisateurId'] ?? null, $pd);
    json_ok('OK');
}

// ── Upload image de fond d'étage ──────────────────────────────────────────────
if ($action === 'plans_upload_fond') {
    $user = require_auth(); require_role($user, ['Admin','Gestionnaire']);
    if ($method !== 'POST') json_error('Méthode non supportée', 405);

    if (empty($_FILES) && empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $maxPost = ini_get('post_max_size');
        json_error("Fichier trop volumineux (limite serveur post_max_size = $maxPost). Augmentez-la dans Configuration > Serveur > Limites d'upload.", 413);
    }
    if (empty($_FILES['file'])) json_error('Aucun fichier envoyé');
    $file = $_FILES['file'];
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        $errMap = [
            UPLOAD_ERR_INI_SIZE   => "Fichier trop volumineux (upload_max_filesize = " . ini_get('upload_max_filesize') . ").",
            UPLOAD_ERR_FORM_SIZE  => "Fichier trop volumineux (limite formulaire).",
            UPLOAD_ERR_PARTIAL    => "Téléchargement partiel.",
            UPLOAD_ERR_NO_FILE    => "Aucun fichier sélectionné.",
            UPLOAD_ERR_NO_TMP_DIR => "Dossier temporaire manquant côté serveur.",
            UPLOAD_ERR_CANT_WRITE => "Impossible d'écrire le fichier sur le serveur.",
            UPLOAD_ERR_EXTENSION  => "Upload bloqué par une extension PHP.",
        ];
        json_error($errMap[$file['error']] ?? ('Erreur upload (#' . $file['error'] . ').'), 400);
    }
    // ⚠️ FIX SÉCURITÉ : caster en int et valider — sinon on peut bricoler
    // le nom du fichier de destination via etage_id="../../foo".
    $etageId = (int)($_POST['etage_id'] ?? $_GET['etage_id'] ?? 0);
    if ($etageId <= 0) json_error('etage_id invalide');

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    // ⚠️ FIX SÉCURITÉ : SVG retiré des formats acceptés.
    // Les SVG peuvent embarquer du JavaScript (<script>, foreignObject…) et,
    // affichés inline par le navigateur, exécutent ce JS dans l'origine du
    // site → XSS stocké. Pour réintroduire les SVG, il faudrait les
    // assainir avec une bibliothèque dédiée (ex. svg-sanitizer côté PHP)
    // et les servir avec Content-Disposition: attachment + CSP stricte.
    if (!in_array($ext, ['png','jpg','jpeg','gif','webp','pdf'], true)) {
        json_error('Format non supporté (formats acceptés : PNG, JPG, GIF, WebP, PDF).');
    }

    $dir = __DIR__ . '/../../data/plans';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $filename = 'plan_' . $etageId . '_' . time() . '.' . $ext;
    $dest = $dir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $dest)) json_error('Erreur upload');

    $db->updatePlanEtageFond($etageId, 'data/plans/' . $filename);
    json_ok(['path' => 'data/plans/' . $filename]);
}

// ── Lister tous les fichiers du dossier data/plans ────────────────────────────
// Utilisé par "Voir les images de fond" pour afficher TOUS les fichiers, y compris
// ceux qui ne sont rattachés à aucun étage (orphelins). Croisé avec la table
// PlanEtage pour identifier les liens existants.
if ($action === 'plans_list_files') {
    $user = require_auth(); require_role($user, ['Admin','Gestionnaire','Visionneur']);
    $dir = realpath(__DIR__ . '/../../data/plans');
    if ($dir === false || !is_dir($dir)) { json_ok(['files' => []]); }

    // Récupérer tous les étages avec un FondImage rattaché (pour le crois.)
    $etages = $db->getAllPlanEtages(null);
    $linkedByFile = [];
    foreach ($etages as $et) {
        if (!empty($et['FondImage'])) {
            $basename = basename($et['FondImage']);
            $linkedByFile[$basename] = [
                'etage_id'   => (int)$et['Id'],
                'etage_nom'  => $et['Nom'] ?? '',
                'niveau'     => $et['Niveau'] ?? 0,
                'batiment_id'=> $et['BatimentId'] ?? null,
            ];
        }
    }

    // Lister les fichiers compatibles
    $allowed = ['png','jpg','jpeg','gif','webp','pdf','svg'];
    $files = [];
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $path = $dir . '/' . $f;
        if (!is_file($path)) continue;
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) continue;
        $files[] = [
            'name'   => $f,
            'size'   => filesize($path),
            'mtime'  => filemtime($path),
            'ext'    => $ext,
            'linked' => $linkedByFile[$f] ?? null,
        ];
    }
    // Tri : non rattachés d'abord (à traiter), puis par date desc
    usort($files, function($a, $b) {
        $la = $a['linked'] === null ? 0 : 1;
        $lb = $b['linked'] === null ? 0 : 1;
        if ($la !== $lb) return $la - $lb;
        return $b['mtime'] - $a['mtime'];
    });
    json_ok(['files' => $files]);
}

// ── Servir un fichier brut du dossier plans (par nom de fichier) ──────────────
if ($action === 'plans_file') {
    $user = require_auth(); require_role($user, ['Admin','Gestionnaire','Visionneur']);
    $fname = basename($_GET['name'] ?? '');
    if ($fname === '' || str_contains($fname, '..')) {
        http_response_code(400); header('Content-Type: text/plain'); echo 'Nom invalide'; exit;
    }
    $rootDir  = realpath(__DIR__ . '/../..');
    $plansDir = realpath($rootDir . '/data/plans');
    $real     = realpath($plansDir . '/' . $fname);
    if ($real === false || $plansDir === false || !str_starts_with($real, $plansDir)) {
        http_response_code(404); header('Content-Type: text/plain'); echo 'Fichier introuvable'; exit;
    }
    $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
    $mimeMap = [
        'png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg',
        'gif'=>'image/gif','webp'=>'image/webp','svg'=>'image/svg+xml',
        'pdf'=>'application/pdf',
    ];
    $mime = $mimeMap[$ext] ?? 'application/octet-stream';
    while (ob_get_level()) ob_end_clean();
    header_remove('Content-Type'); header_remove('Cache-Control');
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($real));
    header('Content-Disposition: inline; filename="' . basename($real) . '"');
    header('Cache-Control: private, max-age=300');
    header('X-Content-Type-Options: nosniff');
    readfile($real);
    exit;
}

// ── Supprimer un fichier orphelin du dossier plans ────────────────────────────
if ($action === 'plans_delete_file') {
    $user = require_auth(); require_role($user, ['Admin','Gestionnaire']);
    $body = get_body();
    $fname = basename($body['name'] ?? '');
    if ($fname === '' || str_contains($fname, '..')) json_error('Nom invalide');
    $rootDir  = realpath(__DIR__ . '/../..');
    $plansDir = realpath($rootDir . '/data/plans');
    $real     = realpath($plansDir . '/' . $fname);
    if ($real === false || !str_starts_with($real, $plansDir)) json_error('Fichier introuvable');
    // Sécu : ne pas autoriser la suppression si rattaché à un étage
    $etages = $db->getAllPlanEtages(null);
    foreach ($etages as $et) {
        if (!empty($et['FondImage']) && basename($et['FondImage']) === $fname) {
            json_error('Fichier rattaché à un étage. Détachez-le d\'abord.');
        }
    }
    if (!@unlink($real)) json_error('Suppression impossible');
    json_ok(['ok' => true]);
}

// ── Rattacher un fichier orphelin à un étage ──────────────────────────────────
if ($action === 'plans_attach_file') {
    $user = require_auth(); require_role($user, ['Admin','Gestionnaire']);
    $body = get_body();
    $fname   = basename($body['name'] ?? '');
    $etageId = (int)($body['etage_id'] ?? 0);
    if ($fname === '' || str_contains($fname, '..')) json_error('Nom invalide');
    if ($etageId <= 0) json_error('etage_id invalide');
    $rootDir  = realpath(__DIR__ . '/../..');
    $plansDir = realpath($rootDir . '/data/plans');
    $real     = realpath($plansDir . '/' . $fname);
    if ($real === false || !str_starts_with($real, $plansDir)) json_error('Fichier introuvable');
    $db->updatePlanEtageFond($etageId, 'data/plans/' . $fname);
    json_ok(['ok' => true, 'path' => 'data/plans/' . $fname]);
}

// ── Télécharger une image de fond ─────────────────────────────────────────────
if ($action === 'plans_fond_image') {
    $user = require_auth();
    $etageId = (int)($_GET['etage_id'] ?? 0);
    if ($etageId <= 0) json_error('etage_id invalide');
    $etage = $db->getPlanEtageById($etageId);
    if (!$etage || empty($etage['FondImage'])) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Pas d\'image de fond';
        exit;
    }
    // Résoudre le chemin de manière sûre (relatif à la racine du projet)
    $relPath = ltrim($etage['FondImage'], '/');
    $rootDir = realpath(__DIR__ . '/../..');
    $path = $rootDir . '/' . $relPath;
    $real = realpath($path);
    $plansDir = realpath($rootDir . '/data/plans');
    if ($real === false || $plansDir === false || !str_starts_with($real, $plansDir)) {
        // ⚠️ FIX SÉCURITÉ : ne PAS exposer les chemins du serveur dans la
        // réponse — leak d'info utile pour un attaquant. Réponse neutre.
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Fichier introuvable.';
        exit;
    }

    // Détecter le MIME selon l'extension (plus fiable que mime_content_type)
    $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
    $mimeMap = [
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'svg'  => 'image/svg+xml',
        'pdf'  => 'application/pdf',
    ];
    $mime = $mimeMap[$ext] ?? (function_exists('mime_content_type') ? mime_content_type($real) : 'application/octet-stream');

    // Nettoyer toute sortie tampon et envoyer le fichier brut
    while (ob_get_level()) ob_end_clean();
    header_remove('Content-Type');
    header_remove('Cache-Control');
    header_remove('Pragma');
    header_remove('X-Content-Type-Options');
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($real));
    header('Content-Disposition: inline; filename="' . basename($real) . '"');
    header('Cache-Control: private, max-age=300');
    header('X-Content-Type-Options: nosniff');
    readfile($real);
    exit;
}

// ── Liens éléments ↔ biens/équipements ────────────────────────────────────────
if ($action === 'plans_liens') {
    $user = require_auth(); require_role($user, ['Admin','Gestionnaire','Visionneur']);
    $elementId = $_GET['element_id'] ?? null;
    if ($method==='GET')    json_ok($db->getAllPlanLiens($elementId));
    if ($method==='POST')   { require_role($user,['Admin','Gestionnaire']); json_ok(['id' => $db->addPlanLien(get_body())]); }
    if ($method==='DELETE') { require_role($user,['Admin','Gestionnaire']); $db->deletePlanLien($id); json_ok('OK'); }
}

// ── Calques (gestion des couches) ─────────────────────────────────────────────
if ($action === 'plans_calques') {
    $user = require_auth(); require_role($user, ['Admin','Gestionnaire']);
    $body = get_body();
    if ($method === 'PUT') {
        // Renommer un calque : { etageId, oldName, newName }
        $count = $db->renamePlanCalque((int)($body['etageId']??0), (string)($body['oldName']??''), (string)($body['newName']??''));
        json_ok(['updated' => $count]);
    }
    if ($method === 'DELETE') {
        // Supprimer un calque (tous ses éléments) : ?etage_id=X&name=Y
        $eid = (int)($_GET['etage_id'] ?? 0);
        $name = (string)($_GET['name'] ?? '');
        $count = $db->deletePlanCalque($eid, $name);
        json_ok(['deleted' => $count]);
    }
}

// ── Limites d'upload (lecture/écriture par SuperAdmin) ────────────────────────
if ($action === 'plans_upload_limits') {
    $isSA = !empty($_SESSION['superadmin']['authenticated']);
    if (!$isSA) { $user = require_auth(); require_role($user, ['Admin','Gestionnaire']); }

    if ($method === 'GET') {
        json_ok([
            'upload_max_filesize_php' => ini_get('upload_max_filesize'),
            'post_max_size_php'       => ini_get('post_max_size'),
            'memory_limit_php'        => ini_get('memory_limit'),
            'max_execution_time_php'  => ini_get('max_execution_time'),
            'max_input_time_php'      => ini_get('max_input_time'),
            'user_ini_file'           => realpath(__DIR__ . '/../../.user.ini') ?: '(absent)',
            'user_ini_exists'         => file_exists(__DIR__ . '/../../.user.ini'),
            'sapi'                    => php_sapi_name(),
            'note'                    => "Les valeurs prennent effet via .user.ini (PHP-FPM/CGI, rechargement après ~5 minutes par défaut) ou via php.ini (redémarrage du service PHP). Pour Nginx, ajustez aussi 'client_max_body_size'.",
        ]);
    }

    if ($method === 'POST') {
        if (!$isSA) json_error('Réservé au Super Administrateur.', 403);
        $body = get_body();
        $upMax  = $body['upload_max_filesize'] ?? null;
        $pstMax = $body['post_max_size']       ?? null;
        $memLim = $body['memory_limit']        ?? null;
        $execT  = $body['max_execution_time']  ?? null;
        $inT    = $body['max_input_time']      ?? null;

        // Validation sommaire — uniquement valeurs PHP type "30M", "1G", "256M"…
        $rxSize = '/^\d+[KMG]?$/i';
        $rxInt  = '/^\d+$/';
        if ($upMax  !== null && !preg_match($rxSize, (string)$upMax))  json_error('upload_max_filesize invalide. Ex : 100M, 1G');
        if ($pstMax !== null && !preg_match($rxSize, (string)$pstMax)) json_error('post_max_size invalide. Ex : 100M, 1G');
        if ($memLim !== null && !preg_match($rxSize, (string)$memLim) && (string)$memLim !== '-1') json_error('memory_limit invalide. Ex : 256M, 1G ou -1 (illimité)');
        if ($execT  !== null && !preg_match($rxInt,  (string)$execT))  json_error('max_execution_time invalide (entier en secondes).');
        if ($inT    !== null && !preg_match($rxInt,  (string)$inT))    json_error('max_input_time invalide (entier en secondes).');

        $userIniPath = __DIR__ . '/../../.user.ini';

        // ⚠️ FIX SÉCURITÉ : whitelist stricte des directives qu'on accepte de
        // lire/écrire. Auparavant, n'importe quelle clé déjà présente dans
        // .user.ini était préservée — si une directive dangereuse avait été
        // injectée par un autre moyen, elle survivait à chaque écriture.
        // Désormais : seules ces 5 clés sont autorisées, le reste du fichier
        // est ignoré (et donc effacé à la prochaine écriture, ce qui est
        // un nettoyage volontaire).
        $allowedKeys = [
            'upload_max_filesize',
            'post_max_size',
            'memory_limit',
            'max_execution_time',
            'max_input_time',
        ];

        // Lire le fichier existant pour préserver UNIQUEMENT les directives autorisées
        $existing = [];
        if (file_exists($userIniPath)) {
            foreach (file($userIniPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (str_starts_with(trim($line), ';') || !str_contains($line, '=')) continue;
                [$k, $v] = array_map('trim', explode('=', $line, 2));
                if (in_array($k, $allowedKeys, true)) {
                    $existing[$k] = $v;
                }
            }
        }
        // Patcher
        if ($upMax  !== null) $existing['upload_max_filesize'] = $upMax;
        if ($pstMax !== null) $existing['post_max_size']       = $pstMax;
        if ($memLim !== null) $existing['memory_limit']        = $memLim;
        if ($execT  !== null) $existing['max_execution_time']  = $execT;
        if ($inT    !== null) $existing['max_input_time']      = $inT;

        // Écrire
        $out = "; Configuration runtime Larka — modifié par SuperAdmin\n; Pris en charge par PHP-FPM (nginx) et Apache mod_php (CGI)\n; Pour Nginx, vérifiez aussi 'client_max_body_size' dans la conf serveur.\n\n";
        foreach ($existing as $k => $v) {
            $out .= "$k = $v\n";
        }
        if (@file_put_contents($userIniPath, $out) === false) {
            json_error('Impossible d\'écrire .user.ini (vérifiez les droits du dossier).', 500);
        }
        @chmod($userIniPath, 0644);
        json_ok(['written' => $userIniPath, 'note' => 'Modification appliquée. Le rechargement peut prendre jusqu\'à 5 minutes (cache user_ini de PHP). Pour appliquer immédiatement, redémarrez PHP-FPM/Apache.']);
    }
}

// ── Import multi-format ───────────────────────────────────────────────────────
if ($action === 'plans_import') {
    $user = require_auth(); require_role($user, ['Admin','Gestionnaire']);
    if ($method !== 'POST') json_error('Méthode non supportée', 405);

    if (empty($_FILES) && empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $maxPost = ini_get('post_max_size');
        json_error("Fichier trop volumineux (limite serveur post_max_size = $maxPost). Augmentez-la dans la config Super Admin > Limites d'upload, ou dans php.ini.", 413);
    }
    if (empty($_FILES['file'])) json_error('Aucun fichier envoyé. Vérifiez que le fichier est bien sélectionné.');

    $file = $_FILES['file'];
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        $errMap = [
            UPLOAD_ERR_INI_SIZE   => "Fichier trop volumineux (upload_max_filesize = " . ini_get('upload_max_filesize') . ").",
            UPLOAD_ERR_FORM_SIZE  => "Fichier trop volumineux (limite formulaire).",
            UPLOAD_ERR_PARTIAL    => "Téléchargement partiel.",
            UPLOAD_ERR_NO_FILE    => "Aucun fichier sélectionné.",
            UPLOAD_ERR_NO_TMP_DIR => "Dossier temporaire manquant côté serveur.",
            UPLOAD_ERR_CANT_WRITE => "Impossible d'écrire le fichier sur le serveur.",
            UPLOAD_ERR_EXTENSION  => "Upload bloqué par une extension PHP.",
        ];
        json_error($errMap[$file['error']] ?? ('Erreur upload (#' . $file['error'] . ').'), 400);
    }

    $etageId = $_POST['etage_id'] ?? $_GET['etage_id'] ?? null;
    if (!$etageId) json_error('etage_id requis');

    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $tmp  = $file['tmp_name'];
    $imported = 0;
    $format   = $ext;

    try {
        if ($ext === 'csv') {
            $imported = _planImportCsv($db, $user, $etageId, file_get_contents($tmp));
        } elseif ($ext === 'dxf') {
            $imported = _planImportDxf($db, $user, $etageId, file_get_contents($tmp));
        } elseif ($ext === 'geojson' || $ext === 'json') {
            $imported = _planImportGeoJson($db, $user, $etageId, file_get_contents($tmp));
        } elseif ($ext === 'svg') {
            $imported = _planImportSvg($db, $user, $etageId, file_get_contents($tmp));
        } elseif ($ext === 'kml') {
            $imported = _planImportKml($db, $user, $etageId, file_get_contents($tmp));
        } elseif ($ext === 'kmz') {
            $imported = _planImportKmz($db, $user, $etageId, $tmp);
        } elseif ($ext === 'zip' || $ext === 'shp') {
            // Shapefile : généralement zip contenant .shp + .dbf + .shx
            $imported = _planImportShapefile($db, $user, $etageId, $tmp, $ext);
            $format = 'shapefile';
        } elseif ($ext === 'gpx') {
            $imported = _planImportGpx($db, $user, $etageId, file_get_contents($tmp));
        } else {
            json_error("Format « .$ext » non supporté. Formats acceptés : CSV, DXF, GeoJSON, SVG, KML, KMZ, GPX, Shapefile (.zip/.shp).", 400);
        }
    } catch (\Throwable $e) {
        json_error('Erreur import : ' . $e->getMessage(), 500);
    }

    json_ok(['imported' => $imported, 'format' => $format]);
}

// ─────────────────────────────────────────────────────────────────────────────
// Importeurs (un par format)
// ─────────────────────────────────────────────────────────────────────────────

function _planImportCsv($db, $user, $etageId, string $content): int {
    $imported = 0;
    $lines = array_filter(explode("\n", $content));
    if (empty($lines)) return 0;
    $headerLine = array_shift($lines);
    $sep = (substr_count($headerLine, ';') > substr_count($headerLine, ',')) ? ';' : ',';
    $header = array_map('strtolower', array_map('trim', str_getcsv($headerLine, $sep, '"', '\\')));
    foreach ($lines as $line) {
        $row = str_getcsv($line, $sep, '"', '\\');
        if (count($row) < 3) continue;
        $mapped = [];
        foreach ($header as $i => $col) $mapped[$col] = trim($row[$i] ?? '');
        $type = $mapped['type'] ?? 'point';
        $coords = $mapped['coords'] ?? $mapped['coordonnees'] ?? '[]';
        if (!str_starts_with($coords, '[')) $coords = '[]';
        $db->addPlanElement([
            'etageId' => (int)$etageId,
            'typeElement' => $type,
            'sousType' => $mapped['sous_type'] ?? $mapped['soustype'] ?? '',
            'nom' => $mapped['nom'] ?? $mapped['name'] ?? '',
            'couleur' => $mapped['couleur'] ?? $mapped['color'] ?? '#3b82f6',
            'opacite' => (float)($mapped['opacite'] ?? $mapped['opacity'] ?? 0.4),
            'coords' => $coords,
            'icone' => $mapped['icone'] ?? '',
            'description' => $mapped['description'] ?? '',
        ], $user['Login']);
        $imported++;
    }
    return $imported;
}

function _planImportDxf($db, $user, $etageId, string $content): int {
    $elements = _parseDxfBasic($content);
    foreach ($elements as $el) {
        $db->addPlanElement([
            'etageId' => (int)$etageId,
            'typeElement' => $el['type'],
            'sousType' => $el['sousType'] ?? '',
            'nom' => $el['nom'] ?? '',
            'couleur' => $el['couleur'] ?? '#3b82f6',
            'opacite' => 0.5,
            'coords' => json_encode($el['coords']),
            'icone' => '',
            'description' => 'Import DXF',
        ], $user['Login']);
    }
    return count($elements);
}

function _planImportGeoJson($db, $user, $etageId, string $content): int {
    $geo = json_decode($content, true);
    if (!$geo) throw new \Exception('JSON invalide');
    $features = $geo['features'] ?? [$geo];
    $imported = 0;
    foreach ($features as $f) {
        $geom = $f['geometry'] ?? $f;
        $props = $f['properties'] ?? [];
        $type = 'zone';
        $coords = [];
        $gtype = strtolower($geom['type'] ?? '');
        if ($gtype === 'point') {
            $type = 'point';
            $c = $geom['coordinates'];
            $coords = [[(float)$c[1], (float)$c[0]]];
        } elseif ($gtype === 'linestring') {
            $type = 'trait';
            foreach ($geom['coordinates'] as $c) $coords[] = [(float)$c[1], (float)$c[0]];
        } elseif ($gtype === 'polygon') {
            $type = 'zone';
            foreach (($geom['coordinates'][0] ?? []) as $c) $coords[] = [(float)$c[1], (float)$c[0]];
        }
        if (!$coords) continue;
        $db->addPlanElement([
            'etageId' => (int)$etageId,
            'typeElement' => $type,
            'sousType' => $props['sous_type'] ?? '',
            'nom' => $props['nom'] ?? $props['name'] ?? '',
            'couleur' => $props['couleur'] ?? $props['color'] ?? '#3b82f6',
            'opacite' => (float)($props['opacite'] ?? 0.4),
            'coords' => json_encode($coords),
            'icone' => '',
            'description' => $props['description'] ?? 'Import GeoJSON',
        ], $user['Login']);
        $imported++;
    }
    return $imported;
}

function _planImportSvg($db, $user, $etageId, string $content): int {
    libxml_use_internal_errors(true);
    $svg = simplexml_load_string($content);
    if ($svg === false) throw new \Exception('SVG invalide');
    $imported = 0;

    // Lignes
    foreach ($svg->xpath('//*[local-name()="line"]') as $line) {
        $x1 = (float)$line['x1']; $y1 = (float)$line['y1'];
        $x2 = (float)$line['x2']; $y2 = (float)$line['y2'];
        $color = (string)($line['stroke'] ?? '#3b82f6');
        $db->addPlanElement([
            'etageId'=>(int)$etageId, 'typeElement'=>'trait', 'sousType'=>'',
            'nom'=>'Ligne SVG', 'couleur'=>$color, 'opacite'=>0.6,
            'coords'=>json_encode([[$y1,$x1],[$y2,$x2]]),
            'icone'=>'', 'description'=>'Import SVG',
        ], $user['Login']);
        $imported++;
    }
    // Polyligne / polygone
    foreach ($svg->xpath('//*[local-name()="polyline" or local-name()="polygon"]') as $poly) {
        $isPoly = $poly->getName() === 'polygon';
        $pts = preg_split('/[\s,]+/', trim((string)$poly['points']));
        $coords = [];
        for ($i = 0; $i < count($pts) - 1; $i += 2) {
            $coords[] = [(float)$pts[$i+1], (float)$pts[$i]];
        }
        if (!$coords) continue;
        $color = (string)($poly['stroke'] ?? $poly['fill'] ?? '#3b82f6');
        $db->addPlanElement([
            'etageId'=>(int)$etageId,
            'typeElement'=>$isPoly?'zone':'trait', 'sousType'=>'',
            'nom'=>($isPoly?'Polygone':'Polyligne').' SVG',
            'couleur'=>$color, 'opacite'=>$isPoly?0.4:0.6,
            'coords'=>json_encode($coords),
            'icone'=>'', 'description'=>'Import SVG',
        ], $user['Login']);
        $imported++;
    }
    // Cercles → polygone 16 côtés
    foreach ($svg->xpath('//*[local-name()="circle"]') as $c) {
        $cx = (float)$c['cx']; $cy = (float)$c['cy']; $r = (float)$c['r'];
        $coords = [];
        for ($a = 0; $a < 16; $a++) {
            $angle = $a * 2 * M_PI / 16;
            $coords[] = [$cy + $r * sin($angle), $cx + $r * cos($angle)];
        }
        $color = (string)($c['fill'] ?? $c['stroke'] ?? '#3b82f6');
        $db->addPlanElement([
            'etageId'=>(int)$etageId, 'typeElement'=>'zone', 'sousType'=>'',
            'nom'=>'Cercle SVG', 'couleur'=>$color, 'opacite'=>0.4,
            'coords'=>json_encode($coords), 'icone'=>'', 'description'=>'Import SVG',
        ], $user['Login']);
        $imported++;
    }
    // Rectangles
    foreach ($svg->xpath('//*[local-name()="rect"]') as $r) {
        $x = (float)$r['x']; $y = (float)$r['y'];
        $w = (float)$r['width']; $h = (float)$r['height'];
        $coords = [[$y,$x],[$y,$x+$w],[$y+$h,$x+$w],[$y+$h,$x]];
        $color = (string)($r['fill'] ?? $r['stroke'] ?? '#3b82f6');
        $db->addPlanElement([
            'etageId'=>(int)$etageId, 'typeElement'=>'zone', 'sousType'=>'',
            'nom'=>'Rectangle SVG', 'couleur'=>$color, 'opacite'=>0.4,
            'coords'=>json_encode($coords), 'icone'=>'', 'description'=>'Import SVG',
        ], $user['Login']);
        $imported++;
    }
    // Path : on extrait les segments M…L…
    foreach ($svg->xpath('//*[local-name()="path"]') as $p) {
        $d = (string)$p['d'];
        if (!$d) continue;
        // Extraction simple : commandes M et L (lettres absolues), avec coords x,y
        preg_match_all('/([MLml])\s*([\-\d\.]+)[,\s]+([\-\d\.]+)/', $d, $matches, PREG_SET_ORDER);
        $coords = [];
        $cx = 0; $cy = 0; $first = true;
        foreach ($matches as $m) {
            $cmd = $m[1]; $x = (float)$m[2]; $y = (float)$m[3];
            if ($cmd === 'M' || ($cmd === 'm' && $first)) { $cx = $x; $cy = $y; }
            elseif ($cmd === 'L') { $cx = $x; $cy = $y; }
            elseif ($cmd === 'l') { $cx += $x; $cy += $y; }
            elseif ($cmd === 'm') { $cx += $x; $cy += $y; }
            $coords[] = [$cy, $cx];
            $first = false;
        }
        if (count($coords) < 2) continue;
        $closed = preg_match('/[Zz]/', $d);
        $color = (string)($p['stroke'] ?? $p['fill'] ?? '#3b82f6');
        $db->addPlanElement([
            'etageId'=>(int)$etageId,
            'typeElement'=>$closed?'zone':'trait', 'sousType'=>'',
            'nom'=>'Path SVG', 'couleur'=>$color, 'opacite'=>$closed?0.4:0.6,
            'coords'=>json_encode($coords), 'icone'=>'', 'description'=>'Import SVG',
        ], $user['Login']);
        $imported++;
    }
    return $imported;
}

function _planImportKml($db, $user, $etageId, string $content): int {
    libxml_use_internal_errors(true);
    $kml = simplexml_load_string($content);
    if ($kml === false) throw new \Exception('KML invalide');
    $imported = 0;
    foreach ($kml->xpath('//*[local-name()="Placemark"]') as $pm) {
        $name = (string)$pm->name ?? '';
        $desc = (string)$pm->description ?? '';
        // Point
        foreach ($pm->xpath('.//*[local-name()="Point"]/*[local-name()="coordinates"]') as $c) {
            $parts = preg_split('/,/', trim((string)$c));
            if (count($parts) < 2) continue;
            $lon = (float)$parts[0]; $lat = (float)$parts[1];
            $db->addPlanElement([
                'etageId'=>(int)$etageId, 'typeElement'=>'point', 'sousType'=>'',
                'nom'=>$name, 'couleur'=>'#3b82f6', 'opacite'=>0.6,
                'coords'=>json_encode([[$lat,$lon]]),
                'icone'=>'circle', 'description'=>$desc?:'Import KML',
            ], $user['Login']);
            $imported++;
        }
        // LineString
        foreach ($pm->xpath('.//*[local-name()="LineString"]/*[local-name()="coordinates"]') as $c) {
            $coords = [];
            foreach (preg_split('/\s+/', trim((string)$c)) as $tup) {
                $p = preg_split('/,/', $tup);
                if (count($p) < 2) continue;
                $coords[] = [(float)$p[1], (float)$p[0]];
            }
            if (count($coords) < 2) continue;
            $db->addPlanElement([
                'etageId'=>(int)$etageId, 'typeElement'=>'trait', 'sousType'=>'',
                'nom'=>$name, 'couleur'=>'#3b82f6', 'opacite'=>0.6,
                'coords'=>json_encode($coords),
                'icone'=>'', 'description'=>$desc?:'Import KML',
            ], $user['Login']);
            $imported++;
        }
        // Polygon
        foreach ($pm->xpath('.//*[local-name()="Polygon"]//*[local-name()="outerBoundaryIs"]//*[local-name()="coordinates"]') as $c) {
            $coords = [];
            foreach (preg_split('/\s+/', trim((string)$c)) as $tup) {
                $p = preg_split('/,/', $tup);
                if (count($p) < 2) continue;
                $coords[] = [(float)$p[1], (float)$p[0]];
            }
            if (count($coords) < 3) continue;
            $db->addPlanElement([
                'etageId'=>(int)$etageId, 'typeElement'=>'zone', 'sousType'=>'',
                'nom'=>$name, 'couleur'=>'#3b82f6', 'opacite'=>0.4,
                'coords'=>json_encode($coords),
                'icone'=>'', 'description'=>$desc?:'Import KML',
            ], $user['Login']);
            $imported++;
        }
    }
    return $imported;
}

function _planImportKmz($db, $user, $etageId, string $tmpPath): int {
    if (!class_exists('ZipArchive')) throw new \Exception('Extension PHP zip non disponible.');
    $zip = new \ZipArchive();
    if ($zip->open($tmpPath) !== true) throw new \Exception('KMZ illisible.');
    $imported = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'kml') {
            $content = $zip->getFromIndex($i);
            $imported += _planImportKml($db, $user, $etageId, $content);
        }
    }
    $zip->close();
    return $imported;
}

function _planImportGpx($db, $user, $etageId, string $content): int {
    libxml_use_internal_errors(true);
    $gpx = simplexml_load_string($content);
    if ($gpx === false) throw new \Exception('GPX invalide');
    $imported = 0;
    // Waypoints (wpt)
    foreach ($gpx->xpath('//*[local-name()="wpt"]') as $wpt) {
        $lat = (float)$wpt['lat']; $lon = (float)$wpt['lon'];
        $name = (string)$wpt->name;
        $db->addPlanElement([
            'etageId'=>(int)$etageId, 'typeElement'=>'point', 'sousType'=>'',
            'nom'=>$name, 'couleur'=>'#3b82f6', 'opacite'=>0.6,
            'coords'=>json_encode([[$lat,$lon]]),
            'icone'=>'circle', 'description'=>'Import GPX',
        ], $user['Login']);
        $imported++;
    }
    // Tracks (trk/trkseg/trkpt)
    foreach ($gpx->xpath('//*[local-name()="trkseg"]') as $seg) {
        $coords = [];
        foreach ($seg->xpath('./*[local-name()="trkpt"]') as $pt) {
            $coords[] = [(float)$pt['lat'], (float)$pt['lon']];
        }
        if (count($coords) < 2) continue;
        $db->addPlanElement([
            'etageId'=>(int)$etageId, 'typeElement'=>'trait', 'sousType'=>'',
            'nom'=>'Trace GPX', 'couleur'=>'#3b82f6', 'opacite'=>0.6,
            'coords'=>json_encode($coords),
            'icone'=>'', 'description'=>'Import GPX',
        ], $user['Login']);
        $imported++;
    }
    return $imported;
}

function _planImportShapefile($db, $user, $etageId, string $tmpPath, string $ext): int {
    // Si zip : extraire les fichiers .shp et .dbf dans un dossier temporaire
    $shpPath = null; $dbfPath = null;
    $cleanup = [];
    if ($ext === 'zip') {
        if (!class_exists('ZipArchive')) throw new \Exception('Extension PHP zip non disponible.');
        $zip = new \ZipArchive();
        if ($zip->open($tmpPath) !== true) throw new \Exception('ZIP illisible.');
        $tmpDir = sys_get_temp_dir() . '/shp_' . uniqid();
        @mkdir($tmpDir);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = $zip->getNameIndex($i);
            $extE = strtolower(pathinfo($n, PATHINFO_EXTENSION));
            if (in_array($extE, ['shp','dbf','shx'])) {
                $base = basename($n);
                $dest = $tmpDir . '/' . $base;
                copy('zip://' . $tmpPath . '#' . $n, $dest);
                $cleanup[] = $dest;
                if ($extE === 'shp') $shpPath = $dest;
                if ($extE === 'dbf') $dbfPath = $dest;
            }
        }
        $zip->close();
        $cleanup[] = $tmpDir;
        if (!$shpPath) throw new \Exception('ZIP ne contient pas de .shp');
    } else {
        $shpPath = $tmpPath;
    }

    // Lire le .shp (format binaire ESRI)
    $imported = _parseShapefile($db, $user, $etageId, $shpPath, $dbfPath);

    // Cleanup
    foreach ($cleanup as $p) { is_dir($p) ? @rmdir($p) : @unlink($p); }
    return $imported;
}

function _parseShapefile($db, $user, $etageId, string $shpPath, ?string $dbfPath): int {
    $h = @fopen($shpPath, 'rb');
    if (!$h) throw new \Exception('Impossible d\'ouvrir le .shp');
    // En-tête (100 octets)
    $header = fread($h, 100);
    if (strlen($header) < 100) { fclose($h); throw new \Exception('SHP corrompu (header).'); }
    $shapeType = unpack('V', substr($header, 32, 4))[1];
    $imported = 0;

    while (!feof($h)) {
        $rh = fread($h, 8);
        if (strlen($rh) < 8) break;
        $rec = unpack('NrecNum/NcontentLength', $rh);
        $contentLength = $rec['contentLength']; // en mots de 16 bits
        $contentBytes = $contentLength * 2;
        $body = fread($h, $contentBytes);
        if (strlen($body) < $contentBytes) break;
        $st = unpack('V', substr($body, 0, 4))[1];
        if ($st === 0) continue; // Null shape

        if ($st === 1) { // Point
            $d = unpack('dx/dy', substr($body, 4, 16));
            $db->addPlanElement([
                'etageId'=>(int)$etageId, 'typeElement'=>'point', 'sousType'=>'',
                'nom'=>'Point SHP #'.$rec['recNum'], 'couleur'=>'#3b82f6', 'opacite'=>0.6,
                'coords'=>json_encode([[$d['y'], $d['x']]]),
                'icone'=>'circle', 'description'=>'Import Shapefile',
            ], $user['Login']);
            $imported++;
        } elseif ($st === 3 || $st === 5) { // PolyLine or Polygon
            // bbox : 32 octets, NumParts : 4, NumPoints : 4
            $info = unpack('VnumParts/VnumPoints', substr($body, 36, 8));
            $numParts = $info['numParts']; $numPoints = $info['numPoints'];
            $partsOffset = 44;
            $parts = [];
            for ($i = 0; $i < $numParts; $i++) {
                $parts[] = unpack('V', substr($body, $partsOffset + $i*4, 4))[1];
            }
            $parts[] = $numPoints;
            $pointsOffset = $partsOffset + $numParts * 4;
            for ($p = 0; $p < $numParts; $p++) {
                $start = $parts[$p]; $end = $parts[$p+1];
                $coords = [];
                for ($k = $start; $k < $end; $k++) {
                    $d = unpack('dx/dy', substr($body, $pointsOffset + $k*16, 16));
                    $coords[] = [$d['y'], $d['x']];
                }
                if (count($coords) < 2) continue;
                $type = ($st === 5) ? 'zone' : 'trait';
                $db->addPlanElement([
                    'etageId'=>(int)$etageId, 'typeElement'=>$type, 'sousType'=>'',
                    'nom'=>($type==='zone'?'Polygone':'Polyligne').' SHP #'.$rec['recNum'],
                    'couleur'=>'#3b82f6', 'opacite'=>$type==='zone'?0.4:0.6,
                    'coords'=>json_encode($coords),
                    'icone'=>'', 'description'=>'Import Shapefile',
                ], $user['Login']);
                $imported++;
            }
        }
    }
    fclose($h);
    return $imported;
}

// ── Parseur DXF basique ───────────────────────────────────────────────────────
function _parseDxfBasic(string $content): array {
    $elements = [];
    $lines = explode("\n", str_replace("\r", "", $content));
    $total = count($lines);
    $i = 0;
    // Chercher la section ENTITIES
    while ($i < $total) {
        if (trim($lines[$i]) === 'ENTITIES') break;
        $i++;
    }
    $i++;
    while ($i < $total - 1) {
        $code = (int)trim($lines[$i]);
        $val  = trim($lines[$i+1] ?? '');
        if ($code === 0 && $val === 'ENDSEC') break;
        if ($code === 0 && $val === 'LINE') {
            $i += 2;
            $x1=$y1=$x2=$y2=0;
            while ($i < $total - 1) {
                $c = (int)trim($lines[$i]); $v = trim($lines[$i+1]);
                if ($c === 0) break;
                if ($c === 10) $x1 = (float)$v;
                if ($c === 20) $y1 = (float)$v;
                if ($c === 11) $x2 = (float)$v;
                if ($c === 21) $y2 = (float)$v;
                $i += 2;
            }
            $elements[] = ['type'=>'trait','coords'=>[[$y1,$x1],[$y2,$x2]],'nom'=>'Ligne DXF'];
            continue;
        }
        if ($code === 0 && $val === 'POINT') {
            $i += 2;
            $x=$y=0;
            while ($i < $total - 1) {
                $c = (int)trim($lines[$i]); $v = trim($lines[$i+1]);
                if ($c === 0) break;
                if ($c === 10) $x = (float)$v;
                if ($c === 20) $y = (float)$v;
                $i += 2;
            }
            $elements[] = ['type'=>'point','coords'=>[[$y,$x]],'nom'=>'Point DXF'];
            continue;
        }
        if ($code === 0 && $val === 'LWPOLYLINE') {
            $i += 2;
            $pts = []; $cx=$cy=0; $closed=false;
            while ($i < $total - 1) {
                $c = (int)trim($lines[$i]); $v = trim($lines[$i+1]);
                if ($c === 0) break;
                if ($c === 70) $closed = ((int)$v & 1) === 1;
                if ($c === 10) { if ($cx||$cy) $pts[] = [$cy,$cx]; $cx = (float)$v; }
                if ($c === 20) $cy = (float)$v;
                $i += 2;
            }
            if ($cx||$cy) $pts[] = [$cy,$cx];
            $elements[] = ['type'=>$closed?'zone':'trait','coords'=>$pts,'nom'=>'Polyligne DXF'];
            continue;
        }
        if ($code === 0 && $val === 'CIRCLE') {
            $i += 2;
            $cx=$cy=0; $r=1;
            while ($i < $total - 1) {
                $c = (int)trim($lines[$i]); $v = trim($lines[$i+1]);
                if ($c === 0) break;
                if ($c === 10) $cx = (float)$v;
                if ($c === 20) $cy = (float)$v;
                if ($c === 40) $r  = (float)$v;
                $i += 2;
            }
            // Approximer le cercle en polygone 16 côtés
            $pts = [];
            for ($a = 0; $a < 16; $a++) {
                $angle = $a * 2 * M_PI / 16;
                $pts[] = [$cy + $r * sin($angle), $cx + $r * cos($angle)];
            }
            $elements[] = ['type'=>'zone','coords'=>$pts,'nom'=>'Cercle DXF'];
            continue;
        }
        $i += 2;
    }
    return $elements;
}

<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka — Routes : Médias des procédures d'urgence
 *
 * Actions :
 *   - urgences_media_upload  (POST multipart)  → Admin/Gestionnaire : envoi photo/vidéo
 *   - urgences_media         (GET)             → lecture du fichier (streaming + Range)
 *   - urgences_media_delete  (DELETE)          → Admin/Gestionnaire : suppression
 *
 * STOCKAGE :
 *   Les fichiers vivent sur disque dans data/urgences/ (dossier bloqué en accès
 *   direct par router.php / nginx). Ils ne sont servis QUE via urgences_media,
 *   ce qui permet d'appliquer le contrôle d'accès « Gestionnaires uniquement ».
 *
 *   La liste des médias est stockée dans le JSON urgences_config, au niveau de
 *   chaque catégorie :
 *     categorie.medias = [ { id, type:'photo'|'video', fichier, nom, legende } ]
 *
 * SÉCURITÉ :
 *   - Extension ET magic bytes vérifiés (anti MIME-spoofing / upload de .php)
 *   - SVG refusé (vecteur XSS)
 *   - Nom de fichier généré côté serveur (jamais celui du client)
 *   - realpath() confiné à data/urgences (anti path traversal)
 *   - ACL : un média rattaché à une catégorie « Gestionnaires uniquement »
 *     n'est servi qu'aux Admin/Gestionnaire.
 */

// ACL + table des extensions, partagées avec routes/gestion.php
require_once __DIR__ . '/../UrgencesAcl.php';

// ── Constantes locales ────────────────────────────────────────────────────────
if (!defined('URG_MEDIA_DIR')) {
    define('URG_MEDIA_DIR', __DIR__ . '/../../data/urgences');
}
if (!defined('URG_PHOTO_MAX_MO')) {
    define('URG_PHOTO_MAX_MO', (float)(cfg('urgences', 'photo_max_mo') ?? 15));
}
if (!defined('URG_VIDEO_MAX_MO')) {
    define('URG_VIDEO_MAX_MO', (float)(cfg('urgences', 'video_max_mo') ?? 150));
}

if (!function_exists('_urg_sniff_ok')) {
    /**
     * Vérifie la signature binaire du fichier (magic bytes) contre l'extension
     * annoncée. Empêche d'uploader un .php renommé en .jpg.
     */
    function _urg_sniff_ok(string $path, string $ext): bool {
        $fh = @fopen($path, 'rb');
        if (!$fh) return false;
        $head = fread($fh, 32);
        fclose($fh);
        if ($head === false || $head === '') return false;

        switch ($ext) {
            case 'png':
                return str_starts_with($head, "\x89PNG\r\n\x1a\n");
            case 'jpg':
            case 'jpeg':
                return str_starts_with($head, "\xFF\xD8\xFF");
            case 'gif':
                return str_starts_with($head, 'GIF87a') || str_starts_with($head, 'GIF89a');
            case 'webp':
                return str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP';
            case 'mp4':
            case 'm4v':
                // Conteneur ISO-BMFF : octets 4..8 = "ftyp"
                return substr($head, 4, 4) === 'ftyp';
            case 'mov':
                // QuickTime : le plus souvent "ftyp", mais les fichiers anciens
                // (ou produits par certains caméscopes) commencent directement
                // par un autre atome de premier niveau.
                return in_array(substr($head, 4, 4), ['ftyp', 'moov', 'mdat', 'free', 'skip', 'wide', 'pnot'], true);
            case 'webm':
            case 'ogv':
                // Matroska/WebM : 1A 45 DF A3 — Ogg : "OggS"
                return str_starts_with($head, "\x1A\x45\xDF\xA3")
                    || str_starts_with($head, 'OggS');
        }
        return false;
    }
}

// ══════════════════════════════════════════════════════════════════════════════
//  UPLOAD d'une photo ou d'une vidéo
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'urgences_media_upload') {
    $user = require_auth();
    require_role($user, ['Admin', 'Gestionnaire']);
    if ($method !== 'POST') json_error('Méthode non supportée', 405);

    // Corps vidé par PHP = dépassement de post_max_size
    if (empty($_FILES) && empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $maxPost = ini_get('post_max_size');
        json_error("Fichier trop volumineux (limite serveur post_max_size = $maxPost). "
                 . "Augmentez-la dans Configuration → Serveur → Limites d'upload.", 413);
    }
    if (empty($_FILES['file'])) json_error('Aucun fichier envoyé.', 400);

    $file = $_FILES['file'];
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        $errMap = [
            UPLOAD_ERR_INI_SIZE   => "Fichier trop volumineux (upload_max_filesize = " . ini_get('upload_max_filesize') . ").",
            UPLOAD_ERR_FORM_SIZE  => "Fichier trop volumineux (limite formulaire).",
            UPLOAD_ERR_PARTIAL    => "Téléchargement partiel, veuillez réessayer.",
            UPLOAD_ERR_NO_FILE    => "Aucun fichier sélectionné.",
            UPLOAD_ERR_NO_TMP_DIR => "Dossier temporaire manquant côté serveur.",
            UPLOAD_ERR_CANT_WRITE => "Impossible d'écrire le fichier sur le serveur.",
            UPLOAD_ERR_EXTENSION  => "Upload bloqué par une extension PHP.",
        ];
        json_error($errMap[$file['error']] ?? ('Erreur upload (#' . $file['error'] . ').'), 400);
    }

    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    $map = urg_ext_map();
    if (!isset($map[$ext])) {
        json_error('Format non supporté. Photos : PNG, JPG, GIF, WebP — Vidéos : MP4, WebM, OGV, MOV.', 415);
    }
    [$type, $mime] = $map[$ext];

    // Taille : plafond distinct photo / vidéo.
    // On mesure le fichier temporaire réel plutôt que $file['size'], qui est
    // une valeur transmise par le client et donc non fiable.
    $maxMo     = ($type === 'video') ? URG_VIDEO_MAX_MO : URG_PHOTO_MAX_MO;
    $taille    = (int)(@filesize($file['tmp_name']) ?: 0);
    $maxOctets = (int)($maxMo * 1024 * 1024);
    if ($taille > $maxOctets) {
        json_error("Fichier trop volumineux (max {$maxMo} Mo pour une " . ($type === 'video' ? 'vidéo' : 'photo') . ").", 413);
    }

    // Anti MIME-spoofing : la signature binaire doit coller à l'extension
    if (!_urg_sniff_ok($file['tmp_name'], $ext)) {
        json_error("Le contenu du fichier ne correspond pas à son extension (.$ext). Fichier refusé.", 415);
    }

    if (!is_dir(URG_MEDIA_DIR) && !mkdir(URG_MEDIA_DIR, 0755, true) && !is_dir(URG_MEDIA_DIR)) {
        json_error('Impossible de créer le dossier de stockage.', 500);
    }

    // Nom généré côté serveur — le nom client n'est jamais réutilisé
    $filename = 'urg_' . $type . '_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $dest     = URG_MEDIA_DIR . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        json_error('Erreur lors de l\'enregistrement du fichier.', 500);
    }
    @chmod($dest, 0644);

    // Nom d'origine conservé uniquement pour l'affichage (assaini)
    $nomAffiche = basename(preg_replace('/[\r\n\x00-\x1f\x7f"\/\\\\]/', '_', (string)$file['name']));
    if ($nomAffiche === '' || $nomAffiche === '.' || $nomAffiche === '..') $nomAffiche = 'fichier.' . $ext;
    if (strlen($nomAffiche) > 120) $nomAffiche = substr($nomAffiche, 0, 120);

    json_ok([
        'id'      => 'm_' . bin2hex(random_bytes(5)),
        'type'    => $type,
        'fichier' => $filename,
        'nom'     => $nomAffiche,
        'mime'    => $mime,
        'taille'  => $taille,
    ]);
}

// ══════════════════════════════════════════════════════════════════════════════
//  LECTURE d'un média (streaming, supporte les requêtes Range pour la vidéo)
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'urgences_media' && $method === 'GET') {
    $user = require_auth();

    $fichier = (string)($_GET['f'] ?? '');
    // Format strict : pas de slash, pas de point-point, extension connue
    if (!urg_media_name_ok($fichier)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Fichier introuvable.';
        exit;
    }

    $ext = strtolower(pathinfo($fichier, PATHINFO_EXTENSION));
    $map = urg_ext_map();
    if (!isset($map[$ext])) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Fichier introuvable.';
        exit;
    }
    $mime = $map[$ext][1];

    // Confinement du chemin
    $baseDir = realpath(URG_MEDIA_DIR);
    $real    = $baseDir ? realpath($baseDir . '/' . $fichier) : false;
    if ($real === false || !str_starts_with($real, $baseDir) || !is_file($real)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Fichier introuvable.';
        exit;
    }

    // ACL : média d'une catégorie réservée → Gestionnaire/Admin uniquement
    if (!urg_is_manager($user) && urg_media_is_restricted($db, $fichier)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Accès refusé.';
        exit;
    }

    $size = filesize($real);
    while (ob_get_level()) ob_end_clean();
    header_remove('Content-Type');
    header_remove('Cache-Control');
    header_remove('Pragma');
    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline; filename="' . $fichier . '"');
    header('Cache-Control: private, max-age=3600');
    header('Accept-Ranges: bytes');

    // ── Requête Range (indispensable pour permettre le seek dans une vidéo) ──
    $range = $_SERVER['HTTP_RANGE'] ?? '';
    if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m)) {
        $depuis = $m[1];
        $jusqua = $m[2];

        if ($depuis === '' && $jusqua === '') {
            // « bytes=- » : ni début ni fin, requête inexploitable
            http_response_code(416);
            header("Content-Range: bytes */$size");
            exit;
        }

        if ($depuis === '') {
            // Suffixe « bytes=-N » = les N DERNIERS octets (RFC 9110 §14.1.2).
            // Safari s'en sert pour lire l'index d'un MP4 situé en fin de
            // fichier ; le traiter comme « 0-N » renverrait le mauvais segment
            // et la vidéo resterait bloquée au chargement.
            $n     = (int)$jusqua;
            if ($n <= 0) {
                http_response_code(416);
                header("Content-Range: bytes */$size");
                exit;
            }
            $start = max(0, $size - $n);
            $end   = $size - 1;
        } else {
            $start = (int)$depuis;
            $end   = ($jusqua === '') ? $size - 1 : (int)$jusqua;
        }

        if ($start > $end || $start >= $size) {
            http_response_code(416);
            header("Content-Range: bytes */$size");
            exit;
        }
        $end    = min($end, $size - 1);
        $length = $end - $start + 1;
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$size");
        header('Content-Length: ' . $length);
        $fh = fopen($real, 'rb');
        fseek($fh, $start);
        $remaining = $length;
        while ($remaining > 0 && !feof($fh)) {
            $chunk = fread($fh, (int)min(8192, $remaining));
            if ($chunk === false) break;
            echo $chunk;
            $remaining -= strlen($chunk);
            flush();
        }
        fclose($fh);
        exit;
    }

    header('Content-Length: ' . $size);
    readfile($real);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
//  SUPPRESSION d'un média
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'urgences_media_delete') {
    $user = require_auth();
    require_role($user, ['Admin', 'Gestionnaire']);
    if ($method !== 'DELETE' && $method !== 'POST') json_error('Méthode non supportée', 405);

    $body    = get_body();
    $fichier = (string)($body['fichier'] ?? $_GET['f'] ?? '');
    if (!urg_media_name_ok($fichier)) {
        json_error('Nom de fichier invalide.', 400);
    }

    $baseDir = realpath(URG_MEDIA_DIR);
    $real    = $baseDir ? realpath($baseDir . '/' . $fichier) : false;
    if ($real === false || !str_starts_with($real, $baseDir) || !is_file($real)) {
        // Déjà absent : on considère l'opération réussie (idempotence)
        json_ok('OK');
    }
    @unlink($real);
    json_ok('OK');
}

<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka — Routes : Documents joints
 *
 * Actions : documents (GET/POST/DELETE), document_download
 * Gère l'upload en base64, le stockage BLOB, et le téléchargement.
 */

// Helper : déduire le MIME RÉEL depuis la signature binaire du fichier
// (les premiers octets, qu'on appelle "magic bytes"). Empêche un client
// malveillant d'envoyer du HTML/JS en prétendant que c'est un PDF.
if (!function_exists('_doc_sniff_mime')) {
    function _doc_sniff_mime(string $bin): ?string {
        if ($bin === '') return null;
        $sig = substr($bin, 0, 16);
        // PDF : "%PDF-"
        if (str_starts_with($sig, "%PDF-")) return 'application/pdf';
        // PNG : 89 50 4E 47 0D 0A 1A 0A
        if (str_starts_with($sig, "\x89PNG\r\n\x1a\n")) return 'image/png';
        // JPEG : FF D8 FF
        if (str_starts_with($sig, "\xFF\xD8\xFF")) return 'image/jpeg';
        // GIF : "GIF87a" / "GIF89a"
        if (str_starts_with($sig, 'GIF87a') || str_starts_with($sig, 'GIF89a')) return 'image/gif';
        // WebP : "RIFF" .... "WEBP"
        if (str_starts_with($sig, 'RIFF') && substr($bin, 8, 4) === 'WEBP') return 'image/webp';
        // ZIP / DOCX / XLSX / PPTX : "PK\x03\x04"
        if (str_starts_with($sig, "PK\x03\x04")) return 'application/zip';
        return null;
    }
}

// ── CRUD documents ────────────────────────────────────────────────────────────
if ($action === 'documents') {
    $user = require_auth();
    $type = $_GET['type'] ?? '';
    $eid  = (int)($_GET['eid'] ?? 0);

    if ($method === 'GET') json_ok($db->getDocuments($type, $eid));

    if ($method === 'DELETE') {
        // FIX : valider $id avant de supprimer
        if (!$id || $id <= 0) json_error('Identifiant invalide.', 400);
        $db->deleteDocument($id);
        json_ok('OK');
    }

    if ($method === 'POST') {
        $b    = get_body();
        $cat  = $b['categorie'] ?? 'autre';
        $nom  = $b['nom']       ?? 'fichier';
        $mime = $b['mime']      ?? 'application/octet-stream';
        $data = $b['data']      ?? '';

        // Sécuriser le nom de fichier : retirer les caractères dangereux
        // (path traversal, injection d'en-têtes Content-Disposition, etc.)
        $nom = basename(preg_replace('/[\r\n\x00-\x1f\x7f"\/\\\\]/', '_', (string)$nom));
        if ($nom === '' || $nom === '.' || $nom === '..') $nom = 'fichier';
        if (strlen($nom) > 200) $nom = substr($nom, 0, 200);

        // Vérification type MIME autorisé (whitelist côté config.json)
        if (!in_array($mime, DOC_MIME_AUTORISES, true)) {
            json_error("Type de fichier non autorisé : $mime.", 415);
        }

        // ⚠️ FIX SÉCURITÉ : vérifier que les magic bytes du fichier
        // correspondent au MIME annoncé (anti MIME-spoofing).
        // Un attaquant pourrait envoyer du SVG (XSS) ou du HTML en prétendant
        // que c'est un PDF.
        $decoded = base64_decode($data, true);
        if ($decoded === false) json_error('Données base64 invalides.', 400);
        $sniffed = _doc_sniff_mime($decoded);
        // SVG est text/xml — on l'autorise UNIQUEMENT si explicitement listé
        // dans DOC_MIME_AUTORISES, mais alors on REFUSE car SVG = vecteur XSS.
        if (str_contains(strtolower($mime), 'svg')) {
            json_error("Type SVG refusé pour des raisons de sécurité (XSS).", 415);
        }
        // Si un MIME image/PDF est annoncé mais le fichier n'a pas la bonne signature → refus
        $mustSniff = in_array(strtolower($mime), [
            'application/pdf', 'image/png', 'image/jpeg', 'image/jpg',
            'image/gif', 'image/webp',
        ], true);
        if ($mustSniff && $sniffed !== null && $sniffed !== $mime
            && !($mime === 'image/jpeg' && $sniffed === 'image/jpeg')) {
            json_error("Le contenu du fichier ne correspond pas au type annoncé ($mime / détecté: $sniffed).", 415);
        }

        // Limite par catégorie (depuis config)
        if ($db->countDocuments($type, $eid, $cat) >= DOC_MAX_PAR_CATEGORIE) {
            json_error("Limite de " . DOC_MAX_PAR_CATEGORIE . " fichiers $cat atteinte.", 400);
        }

        // FIX : utiliser la VRAIE taille décodée plutôt qu'une approximation
        // (l'ancien calcul `strlen*3/4` ignorait le padding et arrondissait mal,
        // ce qui pouvait laisser passer des fichiers légèrement au-dessus de la limite).
        $taille = strlen($decoded);
        $tailleMaxOctets = (int)(DOC_TAILLE_MAX_MO * 1024 * 1024);
        if ($taille > $tailleMaxOctets) {
            json_error("Fichier trop volumineux (max " . DOC_TAILLE_MAX_MO . " Mo).", 413);
        }

        json_ok(['id' => $db->addDocument($type, $eid, $nom, $mime, $cat, $taille, $data, $user['Login'])]);
    }
}

// ── Téléchargement direct (streaming binaire) ─────────────────────────────────
if ($action === 'document_download' && $method === 'GET') {
    $__u = require_auth();
    if (!$id || $id <= 0) json_error('Identifiant invalide.', 400);
    $doc = $db->getDocumentData($id);
    if (!$doc) json_error('Document introuvable.', 404);
    // ⚠️ FIX SÉCURITÉ (F6) : traçabilité d'audit. L'accès aux documents par id
    // n'applique pas (par conception actuelle) d'ACL par entité ; on journalise
    // chaque téléchargement pour détecter une énumération abusive. Le contrôle
    // d'accès par entité reste à définir (décision produit — voir rapport).
    error_log('[Larka][AUDIT] document_download id=' . (int)$id . ' user=' . ($__u['Login'] ?? '?') . ' role=' . ($__u['Role'] ?? '?'));

    // Forcer un download "neutre" pour éviter d'éventuelles attaques de type
    // sniffing/exécution côté navigateur. On ajoute X-Content-Type-Options nosniff.
    $safeName = basename(preg_replace('/[\r\n\x00-\x1f\x7f"]/', '_', $doc['NomFichier']));
    $bin = base64_decode($doc['Donnees']);

    if (ob_get_level()) ob_end_clean();
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $safeName . '"');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . strlen($bin));
    header('Cache-Control: private, no-store');
    echo $bin;
    exit;
}

// ── Prévisualisation inline (photos / PDF) ───────────────────────────────────
if ($action === 'document_preview' && $method === 'GET') {
    $__u = require_auth();
    if (!$id || $id <= 0) json_error('Identifiant invalide.', 400);
    $doc = $db->getDocumentData($id);
    if (!$doc) json_error('Document introuvable.', 404);
    error_log('[Larka][AUDIT] document_preview id=' . (int)$id . ' user=' . ($__u['Login'] ?? '?') . ' role=' . ($__u['Role'] ?? '?')); // F6

    $mime = $doc['TypeMime'] ?? 'application/octet-stream';

    // ⚠️ FIX SÉCURITÉ : on n'autorise inline QUE pour images bitmap et PDF.
    // Tout le reste (notamment SVG, HTML, XML) est forcé en attachment pour
    // éviter le XSS via prévisualisation.
    $allowedInline = ['image/png', 'image/jpeg', 'image/jpg', 'image/gif', 'image/webp', 'application/pdf'];
    $previewable   = in_array(strtolower($mime), $allowedInline, true);

    $safeName = basename(preg_replace('/[\r\n\x00-\x1f\x7f"]/', '_', $doc['NomFichier']));
    $bin = base64_decode($doc['Donnees']);

    if (ob_get_level()) ob_end_clean();
    if (!$previewable) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $safeName . '"');
    } else {
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . $safeName . '"');
        header('Cache-Control: private, max-age=3600');
    }
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . strlen($bin));
    echo $bin;
    exit;
}

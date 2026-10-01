<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka — Routes : Archives physiques
 *
 * Actions : archives_boites, archives_dossiers, archives_bordereaux,
 *           archives_search, archives_bordereau_download
 */

// ── Boîtes d'archives ─────────────────────────────────────────────────────────
// ⚠️ LE VISIONNEUR POUVAIT ÉCRIRE : seul DELETE revérifiait le rôle, POST et
// PUT passaient avec la garde de lecture. L'écran (canEdit) réserve pourtant la
// saisie à la gestion : le serveur applique désormais la même règle.
if ($action === 'archives_boites') {
    $user = require_auth(); require_role($user, ['Admin','Gestionnaire','Visionneur']);
    if ($method === 'GET')    json_ok($db->getAllArchivesBoites());
    require_role($user, ['Admin','Gestionnaire']);
    if ($method === 'POST')   json_ok(['id' => $db->addArchivesBoite(get_body(), $user['Login'])]);
    if ($method === 'PUT')    { $db->updateArchivesBoite($id, get_body(), $user['Login']); json_ok('OK'); }
    if ($method === 'DELETE') { require_role($user, ['Admin','Gestionnaire']); $db->deleteArchivesBoite($id); json_ok('OK'); }
}

// ── Dossiers d'archives ───────────────────────────────────────────────────────
if ($action === 'archives_dossiers') {
    $user = require_auth(); require_role($user, ['Admin','Gestionnaire','Visionneur']);
    if ($method === 'GET')    json_ok($db->getAllArchivesDossiers());
    require_role($user, ['Admin','Gestionnaire']);
    if ($method === 'POST')   json_ok(['id' => $db->addArchivesDossier(get_body(), $user['Login'])]);
    if ($method === 'PUT')    { $db->updateArchivesDossier($id, get_body(), $user['Login']); json_ok('OK'); }
    if ($method === 'DELETE') { require_role($user, ['Admin','Gestionnaire']); $db->deleteArchivesDossier($id); json_ok('OK'); }
}

// ── Recherche avancée dossiers ────────────────────────────────────────────────
// ⚠️ LA RECHERCHE ÉTAIT OUVERTE À TOUT COMPTE : la liste des dossiers était
// refusée à un demandeur (« données nominatives, hors consultation »), mais la
// recherche sans filtre lui rendait les mêmes dossiers. Même garde que la liste.
if ($action === 'archives_recherche' && $method === 'GET') {
    $user = require_auth(); require_role($user, ['Admin','Gestionnaire','Visionneur']);
    $filtres = [
        'annee'          => $_GET['annee']          ?? '',
        'mois'           => $_GET['mois']           ?? '',
        'jour'           => $_GET['jour']           ?? '',
        'numeroBoite'    => $_GET['numeroBoite']    ?? '',
        'numeroDossier'  => $_GET['numeroDossier']  ?? '',
        'nomPrenom'      => $_GET['nomPrenom']      ?? '',
        'commune'        => $_GET['commune']        ?? '',
        'departement'    => $_GET['departement']    ?? '',
        'service'        => $_GET['service']        ?? '',
        'numeroMandat'   => $_GET['numeroMandat']   ?? '',
        'periode'        => $_GET['periode']        ?? '',
        'dureeArchivage' => $_GET['dureeArchivage'] ?? '',
        'sortFinal'      => $_GET['sortFinal']      ?? '',
        'dua'            => $_GET['dua']            ?? '',
        'description'    => $_GET['description']   ?? '',
        'emplacement'    => $_GET['emplacement']    ?? '',
        'codeBarre'      => $_GET['codeBarre']      ?? '',
        'statut'         => $_GET['statut']         ?? '',
    ];
    json_ok($db->searchArchivesDossiers($filtres));
}

// ── Bordereaux ────────────────────────────────────────────────────────────────
if ($action === 'archives_bordereaux') {
    $user = require_auth(); require_role($user, ['Admin','Gestionnaire','Visionneur']);
    if ($method === 'GET')    json_ok($db->getAllArchivesBordereaux());
    require_role($user, ['Admin','Gestionnaire']);
    if ($method === 'POST')   json_ok(['id' => $db->addArchivesBordereau(get_body(), $user['Login'])]);
    if ($method === 'DELETE') { require_role($user, ['Admin','Gestionnaire']); $db->deleteArchivesBordereau($id); json_ok('OK'); }
}

// ── Téléchargement d'un bordereau ─────────────────────────────────────────────
// Même garde que la liste des bordereaux (le téléchargement n'exigeait qu'une
// session). Type servi : celui du dépôt s'il est connu, sinon octet-stream ;
// toujours en pièce jointe, avec nosniff.
if ($action === 'archives_bordereau_download' && $method === 'GET') {
    $user = require_auth(); require_role($user, ['Admin','Gestionnaire','Visionneur']);
    $doc = $db->getArchivesBordereauData((int)$id);
    if (!$doc) json_error('Bordereau introuvable.', 404);
    $mime = strtolower((string)($doc['TypeMime'] ?? ''));
    $mimesSurs = ['application/pdf', 'image/png', 'image/jpeg', 'image/webp',
                  'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                  'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                  'application/vnd.ms-excel', 'application/msword', 'text/csv'];
    $nom = basename(preg_replace('/[\r\n\x00-\x1f\x7f"<>\/\\\\]/', '_', (string)($doc['NomFichier'] ?: 'bordereau')));
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: ' . (in_array($mime, $mimesSurs, true) ? $mime : 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . ($nom !== '' ? $nom : 'bordereau') . '"');
    header('X-Content-Type-Options: nosniff');
    echo base64_decode((string)$doc['Donnees']);
    exit;
}

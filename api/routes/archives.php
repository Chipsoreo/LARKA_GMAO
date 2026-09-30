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
if ($action === 'archives_boites') {
    $user = require_auth(); require_role($user, ['Admin','Gestionnaire','Visionneur']);
    if ($method === 'GET')    json_ok($db->getAllArchivesBoites());
    if ($method === 'POST')   json_ok(['id' => $db->addArchivesBoite(get_body(), $user['Login'])]);
    if ($method === 'PUT')    { $db->updateArchivesBoite($id, get_body(), $user['Login']); json_ok('OK'); }
    if ($method === 'DELETE') { require_role($user, ['Admin','Gestionnaire']); $db->deleteArchivesBoite($id); json_ok('OK'); }
}

// ── Dossiers d'archives ───────────────────────────────────────────────────────
if ($action === 'archives_dossiers') {
    $user = require_auth(); require_role($user, ['Admin','Gestionnaire','Visionneur']);
    if ($method === 'GET')    json_ok($db->getAllArchivesDossiers());
    if ($method === 'POST')   json_ok(['id' => $db->addArchivesDossier(get_body(), $user['Login'])]);
    if ($method === 'PUT')    { $db->updateArchivesDossier($id, get_body(), $user['Login']); json_ok('OK'); }
    if ($method === 'DELETE') { require_role($user, ['Admin','Gestionnaire']); $db->deleteArchivesDossier($id); json_ok('OK'); }
}

// ── Recherche avancée dossiers ────────────────────────────────────────────────
if ($action === 'archives_recherche' && $method === 'GET') {
    require_auth();
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
    if ($method === 'POST')   json_ok(['id' => $db->addArchivesBordereau(get_body(), $user['Login'])]);
    if ($method === 'DELETE') { require_role($user, ['Admin','Gestionnaire']); $db->deleteArchivesBordereau($id); json_ok('OK'); }
}

// ── Téléchargement d'un bordereau ─────────────────────────────────────────────
if ($action === 'archives_bordereau_download' && $method === 'GET') {
    require_auth();
    $doc = $db->getArchivesBordereauData($id);
    if (!$doc) json_error('Bordereau introuvable.', 404);
    header('Content-Type: '        . ($doc['TypeMime'] ?: 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . basename($doc['NomFichier'] ?: 'bordereau') . '"');
    echo base64_decode($doc['Donnees']);
    exit;
}

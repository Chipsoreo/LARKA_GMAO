<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka — Routes : Mobilité Carbone
 * Actions : mobilite_carbone (GET/POST/PUT/DELETE), mobilite_carbone_all (GET admin)
 */

// ── Helper : normaliser les clés des rows mobilité ───────────────────────────
// SQLite peut retourner des clés en minuscules si les colonnes ont été ajoutées
// par ALTER TABLE. On normalise vers le PascalCase attendu par le frontend.
function _normMobilite(?array $row): ?array {
    if (!$row) return null;
    $map = [
        'id'            => 'Id',
        'utilisateurid' => 'UtilisateurId',
        'transport'     => 'Transport',
        'distancekm'    => 'DistanceKm',
        'frequence'     => 'Frequence',
        'nbfrequence'   => 'NbFrequence',
        'facteurco2'    => 'FacteurCO2',
        'facteurco2horsconstr' => 'FacteurCO2HorsConstr',
        'facteurco2combustion' => 'FacteurCO2Combustion',
        'facteurco2seul'       => 'FacteurCO2Seul',
        'annee'         => 'Annee',
        'periode'       => 'Periode',
        'datedebut'     => 'DateDebut',
        'datefin'       => 'DateFin',
        'commentaire'   => 'Commentaire',
        'createdat'     => 'CreatedAt',
        'updatedat'     => 'UpdatedAt',
        'nom'           => 'Nom',
        'prenom'        => 'Prenom',
        'login'         => 'Login',
        'service'       => 'Service',
    ];
    $out = [];
    foreach ($row as $k => $v) {
        $key = $map[strtolower($k)] ?? $k;
        $out[$key] = $v;
    }
    return $out;
}
function _normMobiliteAll(array $rows): array {
    return array_map('_normMobilite', $rows);
}

// ── Helper : vérification propriétaire ────────────────────────────────────────
function _checkMobiliteOwner(array $row, array $user): void {
    $rowUid  = (int)($row['UtilisateurId'] ?? 0);
    $userUid = (int)($user['Id'] ?? 0);
    $isOwner = ($rowUid > 0 && $userUid > 0 && $rowUid === $userUid);
    $isAdmin = in_array($user['Role'] ?? '', ['Admin', 'Gestionnaire']);
    if ($rowUid === 0) return;
    if (!$isOwner && !$isAdmin) {
        json_error("Non autorisé.", 403);
    }
}

// ── Helper : s'assurer que la table MobiliteCarbone est complète ─────────────
function _ensureMobiliteSchema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $driver = DB_DRIVER;

    // Vérifier si la table existe
    try {
        $pdo->query("SELECT 1 FROM MobiliteCarbone LIMIT 1");
    } catch (\Throwable $e) {
        $AI = ($driver === 'pgsql') ? 'SERIAL PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $pdo->exec("CREATE TABLE MobiliteCarbone (
            Id $AI,
            UtilisateurId INTEGER NOT NULL DEFAULT 0,
            Transport     TEXT DEFAULT '',
            DistanceKm    REAL DEFAULT 0,
            Frequence     TEXT DEFAULT 'jour',
            NbFrequence   REAL DEFAULT 1,
            FacteurCO2    REAL DEFAULT 0,
            FacteurCO2HorsConstr REAL DEFAULT 0,
            FacteurCO2Combustion REAL DEFAULT 0,
            FacteurCO2Seul       REAL DEFAULT 0,
            Annee         INTEGER,
            Periode       TEXT,
            DateDebut     TEXT,
            DateFin       TEXT,
            Commentaire   TEXT,
            CreatedAt     TEXT,
            UpdatedAt     TEXT
        )");
        return;
    }

    // Vérifier colonnes manquantes
    $needed = [
        'Transport'  => "TEXT DEFAULT ''",
        'DistanceKm' => "REAL DEFAULT 0",
        'Frequence'  => "TEXT DEFAULT 'jour'",
        'NbFrequence'=> "REAL DEFAULT 1",
        'FacteurCO2' => "REAL DEFAULT 0",
        'FacteurCO2HorsConstr' => "REAL DEFAULT 0",
        'FacteurCO2Combustion' => "REAL DEFAULT 0",
        'FacteurCO2Seul'       => "REAL DEFAULT 0",
        'Annee'      => "INTEGER",
        'Periode'    => "TEXT", 'DateDebut' => "TEXT", 'DateFin' => "TEXT",
        'Commentaire'=> "TEXT", 'CreatedAt' => "TEXT", 'UpdatedAt' => "TEXT",
    ];
    // Lister les colonnes existantes (insensible à la casse)
    $existingCols = [];
    try {
        if ($driver === 'sqlite') {
            foreach ($pdo->query("PRAGMA table_info(MobiliteCarbone)")->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $existingCols[strtolower($c['name'])] = $c['name'];
            }
        }
    } catch (\Throwable $e) {}

    foreach ($needed as $col => $def) {
        if (isset($existingCols[strtolower($col)])) continue;
        try {
            $pdo->exec("ALTER TABLE MobiliteCarbone ADD COLUMN $col $def");
        } catch (\Throwable $e2) { /* déjà existante */ }
    }
}

// ── GET : Mes trajets ──────────────────────────────────────────────────────────
if ($action === 'mobilite_carbone' && $method === 'GET') {
    $user = require_auth();
    _ensureMobiliteSchema($db->getPdo());
    json_ok(_normMobiliteAll($db->getMobiliteByUser((int)$user['Id'])));
}

// ── POST : Ajouter un trajet ───────────────────────────────────────────────────
if ($action === 'mobilite_carbone' && $method === 'POST') {
    $user = require_auth();
    $pdo = $db->getPdo();
    _ensureMobiliteSchema($pdo);
    $body = get_body();

    if (empty($body['transport'])) {
        json_error('Le transport est obligatoire.');
    }

    $uid  = (int)$user['Id'];
    $now  = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("INSERT INTO MobiliteCarbone
        (UtilisateurId, Transport, DistanceKm, Frequence, NbFrequence, FacteurCO2,
         FacteurCO2HorsConstr, FacteurCO2Combustion, FacteurCO2Seul,
         Annee, Periode, DateDebut, DateFin, Commentaire, CreatedAt, UpdatedAt)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $uid,
        (string)($body['transport'] ?? ''),
        (float)($body['distanceKm'] ?? 0),
        (string)($body['frequence'] ?? 'jour'),
        (float)($body['nbFrequence'] ?? 1),
        (float)($body['facteurCO2'] ?? 0),
        (float)($body['facteurCO2HorsConstr'] ?? 0),
        (float)($body['facteurCO2Combustion'] ?? 0),
        (float)($body['facteurCO2Seul'] ?? 0),
        (int)($body['annee'] ?? date('Y')),
        (string)($body['periode'] ?? ''),
        $body['dateDebut'] ?? null,
        $body['dateFin'] ?? null,
        (string)($body['commentaire'] ?? ''),
        $now, $now,
    ]);
    json_ok(['id' => (int)$pdo->lastInsertId()]);
}

// ── PUT : Modifier un trajet ───────────────────────────────────────────────────
if ($action === 'mobilite_carbone' && $method === 'PUT') {
    $user = require_auth();
    $pdo = $db->getPdo();
    _ensureMobiliteSchema($pdo);
    $body = get_body();

    if (!$id) json_error('ID manquant.');
    $raw = $pdo->prepare("SELECT * FROM MobiliteCarbone WHERE Id = ?");
    $raw->execute([$id]);
    $row = _normMobilite($raw->fetch(PDO::FETCH_ASSOC) ?: null);
    if (!$row) json_error('Trajet introuvable.', 404);
    _checkMobiliteOwner($row, $user);

    $stmt = $pdo->prepare("UPDATE MobiliteCarbone
        SET Transport=?, DistanceKm=?, Frequence=?, NbFrequence=?, FacteurCO2=?,
            FacteurCO2HorsConstr=?, FacteurCO2Combustion=?, FacteurCO2Seul=?, Annee=?, Periode=?, DateDebut=?, DateFin=?, Commentaire=?, UpdatedAt=?
        WHERE Id=?");
    $stmt->execute([
        (string)($body['transport'] ?? ''),
        (float)($body['distanceKm'] ?? 0),
        (string)($body['frequence'] ?? 'jour'),
        (float)($body['nbFrequence'] ?? 1),
        (float)($body['facteurCO2'] ?? 0),
        (float)($body['facteurCO2HorsConstr'] ?? 0),
        (float)($body['facteurCO2Combustion'] ?? 0),
        (float)($body['facteurCO2Seul'] ?? 0),
        (int)($body['annee'] ?? date('Y')),
        (string)($body['periode'] ?? ''),
        $body['dateDebut'] ?? null,
        $body['dateFin'] ?? null,
        (string)($body['commentaire'] ?? ''),
        date('Y-m-d H:i:s'),
        $id,
    ]);
    json_ok('OK');
}

// ── DELETE : Supprimer un trajet ───────────────────────────────────────────────
if ($action === 'mobilite_carbone' && $method === 'DELETE') {
    $user = require_auth();
    _ensureMobiliteSchema($db->getPdo());
    if (!$id) json_error('ID manquant.');
    $row = _normMobilite($db->getMobiliteById($id));
    if (!$row) json_error('Trajet introuvable.', 404);
    _checkMobiliteOwner($row, $user);
    $db->deleteMobilite($id);
    json_ok('OK');
}

// ── GET ALL : Admin — tous les trajets ─────────────────────────────────────────
if ($action === 'mobilite_carbone_all' && $method === 'GET') {
    $user = require_auth();
    require_lecture($user, ['Admin', 'Gestionnaire', 'Visionneur'], 'carbone', $db);
    _ensureMobiliteSchema($db->getPdo());
    json_ok(_normMobiliteAll($db->getMobiliteAll()));
}

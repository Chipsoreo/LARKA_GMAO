<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka — sauvegarde SQL d'une base PostgreSQL ou MariaDB, par PDO.
 *
 * Même format que la sauvegarde du Super Admin (structure de chaque table,
 * puis INSERT, puis séquences recalées), que son import sait relire ; écrit au
 * fil de l'eau dans un fichier .sql.gz : la mémoire ne dépend pas de la taille
 * de la base. Aucun outil externe (pg_dump) : la production refuse à PHP de
 * lancer des programmes.
 *
 * Utilisée avant une mise à jour de Larka (api/MiseAJour.php) : le nouveau code
 * peut faire évoluer le schéma des bases à la première requête.
 *
 * Restaurer une base PostgreSQL vide :
 *   gunzip -c fichier.sql.gz | psql -h 127.0.0.1 -U <rôle> -d <base> -v ON_ERROR_STOP=1
 * (ou l'import d'une base dans l'écran Super Admin).
 */

/**
 * « CREATE TABLE IF NOT EXISTS » d'une table, dans le dialecte de la base.
 * PostgreSQL : reconstruit depuis information_schema (les colonnes « real »,
 * des float4, y sont remontées en DOUBLE PRECISION) ; MariaDB : SHOW CREATE TABLE.
 */
function _sa_ddl_table(PDO $pdo, string $driver, string $table): ?string {
    try {
        if ($driver === 'pgsql') {
            $st = $pdo->prepare("SELECT column_name, data_type, column_default, is_identity
                                   FROM information_schema.columns
                                  WHERE table_schema = current_schema() AND table_name = ?
                                  ORDER BY ordinal_position");
            $st->execute([$table]);
            $defs = [];
            $pk = false;
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $nom = '"' . str_replace('"', '""', (string)$c['column_name']) . '"';
                $auto = str_starts_with((string)($c['column_default'] ?? ''), 'nextval(') || ($c['is_identity'] ?? '') === 'YES';
                if ($auto && !$pk) { $defs[] = "$nom SERIAL PRIMARY KEY"; $pk = true; continue; }
                $defs[] = $nom . ' ' . match ((string)$c['data_type']) {
                    'integer' => 'INTEGER', 'bigint' => 'BIGINT', 'smallint' => 'SMALLINT',
                    'real', 'double precision' => 'DOUBLE PRECISION', 'numeric' => 'NUMERIC',
                    'boolean' => 'BOOLEAN', default => 'TEXT',
                };
            }
            return $defs ? 'CREATE TABLE IF NOT EXISTS "' . str_replace('"', '""', $table) . '" (' . implode(', ', $defs) . ')' : null;
        }
        $row = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '``', $table) . '`')->fetch(PDO::FETCH_NUM);
        return isset($row[1]) ? preg_replace('/^CREATE TABLE/i', 'CREATE TABLE IF NOT EXISTS', (string)$row[1]) : null;
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Sauvegarde complète (toutes les tables, comptes compris) dans $fichier
 * (.sql.gz). Lève une exception si quoi que ce soit échoue : le fichier
 * partiel est alors supprimé — mieux vaut pas de sauvegarde qu'une sauvegarde
 * tronquée qu'on croirait bonne.
 *
 * @return array{tables: int, lignes: int, octets: int}
 */
function larka_sauvegarde_sql(PDO $pdo, string $driver, string $fichier): array
{
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $gz = @gzopen($fichier, 'wb6');
    if (!$gz) throw new \RuntimeException("Impossible d'écrire $fichier");
    $tables = 0; $lignes = 0;
    $ecrire = function (string $s) use ($gz, $fichier): void {
        if (gzwrite($gz, $s) === false) throw new \RuntimeException("Écriture impossible dans $fichier (disque plein ?)");
    };
    try {
        // Une seule photographie cohérente de la base, même si elle est en service.
        if ($driver === 'pgsql') $pdo->exec('BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY');
        else $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');

        $ecrire("-- Larka : sauvegarde complète (comptes compris) — " . date('Y-m-d H:i:s') . "\n-- Driver: {$driver}\n\n");
        $liste = $driver === 'pgsql'
            ? $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname = current_schema() ORDER BY tablename")->fetchAll(PDO::FETCH_COLUMN)
            : $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $q = fn(string $n): string => $driver === 'pgsql' ? '"' . str_replace('"', '""', $n) . '"' : '`' . str_replace('`', '``', $n) . '`';

        foreach ($liste as $table) {
            $table = (string)$table;
            $tables++;
            $ecrire("-- Table: {$table}\n");
            $ddl = _sa_ddl_table($pdo, $driver, $table);
            if ($ddl !== null) $ecrire($ddl . ";\n");
            $st = $pdo->query('SELECT * FROM ' . $q($table));
            $cols = null;
            while (($row = $st->fetch(PDO::FETCH_ASSOC)) !== false) {
                if ($cols === null) $cols = implode(',', array_map($q, array_keys($row)));
                $vals = [];
                foreach ($row as $v) {
                    $vals[] = $v === null ? 'NULL' : ((is_int($v) || is_float($v)) ? (string)$v : $pdo->quote((string)$v));
                }
                $ecrire('INSERT INTO ' . $q($table) . " ($cols) VALUES (" . implode(',', $vals) . ");\n");
                $lignes++;
            }
            $st = null;
            $ecrire("\n");
        }

        if ($driver === 'pgsql') {
            $ecrire("-- Séquences recalées après réinsertion\n");
            foreach ($liste as $table) {
                $table = (string)$table;
                $seq = $pdo->prepare("SELECT column_name, pg_get_serial_sequence(?, column_name) AS seq
                                        FROM information_schema.columns
                                       WHERE table_schema = current_schema() AND table_name = ?
                                         AND pg_get_serial_sequence(?, column_name) IS NOT NULL LIMIT 1");
                $seq->execute([$q($table), $table, $q($table)]);
                if ($s = $seq->fetch(PDO::FETCH_ASSOC)) {
                    $ecrire("SELECT setval(" . $pdo->quote((string)$s['seq']) . ", COALESCE((SELECT MAX(" . $q((string)$s['column_name']) . ") FROM " . $q($table) . "), 1), true);\n");
                }
            }
        }
        $pdo->exec($driver === 'pgsql' ? 'COMMIT' : 'COMMIT');
    } catch (\Throwable $e) {
        try { $pdo->exec('ROLLBACK'); } catch (\Throwable $_) {}
        gzclose($gz);
        @unlink($fichier);
        throw $e;
    }
    if (!gzclose($gz)) { @unlink($fichier); throw new \RuntimeException("Fichier de sauvegarde incomplet : $fichier"); }
    return ['tables' => $tables, 'lignes' => $lignes, 'octets' => (int)@filesize($fichier)];
}

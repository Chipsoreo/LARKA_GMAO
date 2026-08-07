<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka — Résolveur Multi-Tenant (stockage DB centralisée)
 *
 * Stocke les tenants, comptes locaux et config super admin dans une base
 * PostgreSQL (ou SQLite en fallback). Lit config.json → section "superadmin_db".
 *
 * Bootstrap : si la DB est vide, importe tenants.json comme seed initial.
 * Après ça, tenants.json n'est plus utilisé — tout est géré en DB.
 */

class TenantResolver {

    private static ?PDO $pdo = null;
    private static bool $initialized = false;
    private static ?string $resolvedTenantKey = null;
    private static ?array $resolvedTenant = null;

    // ══════════════════════════════════════════════════════════════════════════
    //  CONNEXION DB SUPER ADMIN
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Retourne la connexion PDO à la base super admin.
     * Crée les tables si nécessaire. Importe tenants.json au premier lancement.
     */
    private static function db(): PDO {
        if (self::$pdo !== null) return self::$pdo;

        $cfg = self::readSuperAdminDbConfig();
        $driver = $cfg['driver'] ?? 'sqlite';

        try {
            if ($driver === 'pgsql') {
                // connect_timeout borne la connexion (PG ignore PDO::ATTR_TIMEOUT au connect).
                $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=%s;connect_timeout=3',
                    $cfg['host'] ?? '127.0.0.1', $cfg['port'] ?? 5432,
                    $cfg['dbname'] ?? 'gmao_admin', $cfg['sslmode'] ?? 'prefer');
                self::$pdo = new PDO($dsn, $cfg['user'] ?? '', $cfg['password'] ?? '', [PDO::ATTR_TIMEOUT => 3]);
            } elseif ($driver === 'mariadb' || $driver === 'mysql') {
                $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                    $cfg['host'] ?? '127.0.0.1', $cfg['port'] ?? 3306,
                    $cfg['dbname'] ?? 'gmao_admin');
                self::$pdo = new PDO($dsn, $cfg['user'] ?? '', $cfg['password'] ?? '', [PDO::ATTR_TIMEOUT => 3]);
            } else {
                $path = $cfg['path'] ?? 'data/superadmin.db';
                if (!str_starts_with($path, '/')) $path = __DIR__ . '/../' . $path;
                $dir = dirname($path);
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                self::$pdo = new PDO('sqlite:' . $path);
                self::$pdo->exec("PRAGMA journal_mode=WAL;");
            }

            self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

            self::initTables();
            self::seedFromJson();

        } catch (\Throwable $e) {
            throw new \RuntimeException("Impossible de se connecter à la base super admin : " . $e->getMessage());
        }

        return self::$pdo;
    }

    /**
     * Lit la config superadmin_db depuis config.json
     */
    private static function readSuperAdminDbConfig(): array {
        global $_cfg;
        if (isset($_cfg['superadmin_db'])) return $_cfg['superadmin_db'];

        $file = __DIR__ . '/../config.json';
        if (!file_exists($file)) return ['driver' => 'sqlite'];
        $data = json_decode(file_get_contents($file), true);
        return $data['superadmin_db'] ?? ['driver' => 'sqlite'];
    }

    /**
     * Crée les tables super admin
     */
    private static function initTables(): void {
        if (self::$initialized) return;
        self::$initialized = true;

        // Détecter le driver RÉEL de la connexion (pas celui de la config — peut être en fallback)
        $realDriver = self::$pdo->getAttribute(PDO::ATTR_DRIVER_NAME); // 'sqlite', 'pgsql', 'mysql'
        $isPg = ($realDriver === 'pgsql');
        $isMy = ($realDriver === 'mysql');
        $AI = $isPg ? 'SERIAL PRIMARY KEY' : ($isMy ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT');

        self::$pdo->exec("CREATE TABLE IF NOT EXISTS sa_config (
            id $AI,
            cle TEXT NOT NULL UNIQUE,
            valeur TEXT DEFAULT ''
        )");

        self::$pdo->exec("CREATE TABLE IF NOT EXISTS sa_tenants (
            id $AI,
            cle TEXT NOT NULL UNIQUE,
            nom TEXT NOT NULL,
            actif INTEGER DEFAULT 1,
            domaines_web TEXT DEFAULT '[]',
            domaines_email TEXT DEFAULT '[]',
            emails_exceptions TEXT DEFAULT '[]',
            db_driver TEXT DEFAULT 'sqlite',
            db_path TEXT DEFAULT '',
            db_host TEXT DEFAULT '127.0.0.1',
            db_port INTEGER DEFAULT 5432,
            db_dbname TEXT DEFAULT '',
            db_user TEXT DEFAULT '',
            db_password TEXT DEFAULT '',
            db_sslmode TEXT DEFAULT 'prefer',
            couleur TEXT DEFAULT '#3b82f6',
            sauvegarde TEXT DEFAULT '{}',
            date_creation TEXT,
            date_modification TEXT
        )");

        // Migration: ajouter la colonne "sauvegarde" pour les bases créées avant
        // l'introduction de la config de sauvegarde par tenant.
        try {
            if ($isPg) {
                self::$pdo->exec("ALTER TABLE sa_tenants ADD COLUMN IF NOT EXISTS sauvegarde TEXT DEFAULT '{}'");
            } elseif ($isMy) {
                // MySQL/MariaDB ne connaît pas IF NOT EXISTS avant 8.0 → on essaie et on ignore l'erreur si déjà là
                try { self::$pdo->exec("ALTER TABLE sa_tenants ADD COLUMN sauvegarde TEXT DEFAULT ('{}')"); } catch (\Throwable $e) {}
            } else {
                // SQLite : vérifier via PRAGMA
                $cols = self::$pdo->query("PRAGMA table_info(sa_tenants)")->fetchAll();
                $hasCol = false;
                foreach ($cols as $c) { if (($c['name'] ?? '') === 'sauvegarde') { $hasCol = true; break; } }
                if (!$hasCol) {
                    self::$pdo->exec("ALTER TABLE sa_tenants ADD COLUMN sauvegarde TEXT DEFAULT '{}'");
                }
            }
        } catch (\Throwable $e) { /* colonne déjà présente ou erreur non bloquante */ }

        // Migration : « emails_exceptions » — adresses email nominatives autorisées
        // EN PLUS des domaines (prestataires, intervenants externes…).
        try {
            if ($isPg) {
                self::$pdo->exec("ALTER TABLE sa_tenants ADD COLUMN IF NOT EXISTS emails_exceptions TEXT DEFAULT '[]'");
            } elseif ($isMy) {
                try { self::$pdo->exec("ALTER TABLE sa_tenants ADD COLUMN emails_exceptions TEXT DEFAULT ('[]')"); } catch (\Throwable $e) {}
            } else {
                $cols = self::$pdo->query("PRAGMA table_info(sa_tenants)")->fetchAll();
                $hasCol = false;
                foreach ($cols as $c) { if (($c['name'] ?? '') === 'emails_exceptions') { $hasCol = true; break; } }
                if (!$hasCol) {
                    self::$pdo->exec("ALTER TABLE sa_tenants ADD COLUMN emails_exceptions TEXT DEFAULT '[]'");
                }
            }
        } catch (\Throwable $e) { /* colonne déjà présente ou erreur non bloquante */ }

        self::$pdo->exec("CREATE TABLE IF NOT EXISTS sa_comptes_locaux (
            id $AI,
            login TEXT NOT NULL UNIQUE,
            tenant_cle TEXT NOT NULL,
            date_creation TEXT
        )");
    }

    /**
     * Import initial depuis tenants.json (une seule fois)
     */
    private static function seedFromJson(): void {
        // Vérifier si déjà seedé
        $count = (int) self::$pdo->query("SELECT COUNT(*) FROM sa_config WHERE cle='seeded'")->fetchColumn();
        if ($count > 0) return;

        $file = __DIR__ . '/../tenants.json';
        if (!file_exists($file)) {
            // Pas de fichier seed — marquer comme seedé avec des valeurs par défaut
            self::setConfig('seeded', '1');
            self::setConfig('sa_login', 'superadmin');
            self::setConfig('sa_password', password_hash('SuperAdmin2025!', PASSWORD_BCRYPT));
            self::setConfig('sa_email', 'admin@gmao.local');
            return;
        }

        $data = json_decode(file_get_contents($file), true);
        if (!$data) { self::setConfig('seeded', '1'); return; }

        // Super admin
        $sa = $data['super_admin'] ?? [];
        self::setConfig('sa_login', $sa['login'] ?? 'superadmin');
        self::setConfig('sa_password', $sa['mot_de_passe'] ?? password_hash('SuperAdmin2025!', PASSWORD_BCRYPT));
        self::setConfig('sa_email', $sa['email'] ?? '');

        // Tenants
        foreach (($data['tenants'] ?? []) as $key => $t) {
            $db = $t['base_de_donnees'] ?? [];
            $stmt = self::$pdo->prepare("INSERT INTO sa_tenants
                (cle, nom, actif, domaines_web, domaines_email, db_driver, db_path, db_host, db_port, db_dbname, db_user, db_password, db_sslmode, couleur, date_creation)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            try {
                $stmt->execute([
                    $key, $t['nom'] ?? $key, ($t['actif'] ?? true) ? 1 : 0,
                    json_encode($t['domaines_web'] ?? []), json_encode($t['domaines_email'] ?? []),
                    $db['driver'] ?? 'sqlite', $db['path'] ?? '', $db['host'] ?? '127.0.0.1',
                    $db['port'] ?? 5432, $db['dbname'] ?? '', $db['user'] ?? '',
                    $db['password'] ?? '', $db['sslmode'] ?? 'prefer',
                    $t['couleur'] ?? '#3b82f6', date('Y-m-d H:i:s'),
                ]);
            } catch (\PDOException $e) { /* doublon possible */ }
        }

        // Comptes locaux
        foreach (($data['comptes_locaux'] ?? []) as $login => $tenantKey) {
            if ($login === '_comment') continue;
            try {
                $stmt = self::$pdo->prepare("INSERT INTO sa_comptes_locaux (login, tenant_cle, date_creation) VALUES (?,?,?)");
                $stmt->execute([$login, $tenantKey, date('Y-m-d H:i:s')]);
            } catch (\PDOException $e) { /* doublon */ }
        }

        self::setConfig('seeded', '1');
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  CONFIG KEY-VALUE
    // ══════════════════════════════════════════════════════════════════════════

    private static function getConfig(string $cle): ?string {
        $stmt = self::db()->prepare("SELECT valeur FROM sa_config WHERE cle=?");
        $stmt->execute([$cle]);
        $row = $stmt->fetch();
        return $row ? $row['valeur'] : null;
    }

    private static function setConfig(string $cle, string $valeur): void {
        $pdo = self::db();
        $realDriver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($realDriver === 'pgsql') {
            $pdo->prepare("INSERT INTO sa_config (cle, valeur) VALUES (?,?) ON CONFLICT(cle) DO UPDATE SET valeur=EXCLUDED.valeur")->execute([$cle, $valeur]);
        } elseif ($realDriver === 'mysql') {
            $pdo->prepare("INSERT INTO sa_config (cle, valeur) VALUES (?,?) ON DUPLICATE KEY UPDATE valeur=VALUES(valeur)")->execute([$cle, $valeur]);
        } else {
            $pdo->prepare("INSERT INTO sa_config (cle, valeur) VALUES (?,?) ON CONFLICT(cle) DO UPDATE SET valeur=excluded.valeur")->execute([$cle, $valeur]);
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  TENANTS
    // ══════════════════════════════════════════════════════════════════════════

    public static function isMultiTenant(): bool {
        try {
            $count = (int) self::db()->query("SELECT COUNT(*) FROM sa_tenants")->fetchColumn();
            return $count > 0;
        } catch (\Throwable $e) { return false; }
    }

    /**
     * Retourne tous les tenants sous forme de tableau associatif key → data
     */
    public static function getAllTenants(): array {
        $rows = self::db()->query("SELECT * FROM sa_tenants ORDER BY nom")->fetchAll();
        $result = [];
        foreach ($rows as $r) {
            $result[$r['cle']] = self::rowToTenant($r);
        }
        return $result;
    }

    /**
     * Retourne un tenant par sa clé
     */
    public static function getTenant(string $key): ?array {
        $stmt = self::db()->prepare("SELECT * FROM sa_tenants WHERE cle=?");
        $stmt->execute([$key]);
        $r = $stmt->fetch();
        return $r ? self::rowToTenant($r) : null;
    }

    /**
     * Convertit une ligne DB en format tenant attendu par le reste du code
     */
    private static function rowToTenant(array $r): array {
        $sauvegarde = [];
        if (!empty($r['sauvegarde'])) {
            $decoded = json_decode($r['sauvegarde'], true);
            if (is_array($decoded)) $sauvegarde = $decoded;
        }
        // Valeurs par défaut pour les champs manquants
        $sauvegarde += [
            'actif'              => false,
            'intervalle_minutes' => 360,
            'garder'             => 30,
            'compresser'         => true,
            'dossier'            => 'data/backups',
            'derniere'           => null,
        ];

        return [
            'nom' => $r['nom'],
            'actif' => (bool)$r['actif'],
            'domaines_web' => json_decode($r['domaines_web'] ?? '[]', true) ?: [],
            'domaines_email' => json_decode($r['domaines_email'] ?? '[]', true) ?: [],
            'emails_exceptions' => json_decode($r['emails_exceptions'] ?? '[]', true) ?: [],
            'base_de_donnees' => [
                'driver'   => $r['db_driver'] ?? 'sqlite',
                'path'     => $r['db_path'] ?? '',
                'host'     => $r['db_host'] ?? '127.0.0.1',
                'port'     => (int)($r['db_port'] ?? 5432),
                'dbname'   => $r['db_dbname'] ?? '',
                'user'     => $r['db_user'] ?? '',
                'password' => $r['db_password'] ?? '',
                'sslmode'  => $r['db_sslmode'] ?? 'prefer',
            ],
            'couleur' => $r['couleur'] ?? '#3b82f6',
            'sauvegarde' => $sauvegarde,
        ];
    }

    public static function getDbConfigForTenant(string $key): ?array {
        $t = self::getTenant($key);
        return $t ? $t['base_de_donnees'] : null;
    }

    /**
     * Ajoute ou met à jour un tenant
     */
    public static function upsertTenant(string $key, array $data): bool {
        $db = $data['base_de_donnees'] ?? [];
        $now = date('Y-m-d H:i:s');
        $existing = self::getTenant($key);

        // Config sauvegarde : fusionner avec l'existant si partiel
        $sauvegardeArr = $data['sauvegarde'] ?? null;
        if (!is_array($sauvegardeArr)) {
            $sauvegardeArr = $existing['sauvegarde'] ?? [];
        } elseif ($existing) {
            // Conserver les clés non fournies (ex: "derniere" qui n'est pas dans le form)
            $sauvegardeArr += ($existing['sauvegarde'] ?? []);
        }
        $sauvegardeJson = json_encode($sauvegardeArr);

        if ($existing) {
            $stmt = self::db()->prepare("UPDATE sa_tenants SET
                nom=?, actif=?, domaines_web=?, domaines_email=?, emails_exceptions=?,
                db_driver=?, db_path=?, db_host=?, db_port=?, db_dbname=?, db_user=?, db_password=?, db_sslmode=?,
                couleur=?, sauvegarde=?, date_modification=?
                WHERE cle=?");
            $stmt->execute([
                $data['nom'] ?? $key, ($data['actif'] ?? true) ? 1 : 0,
                json_encode($data['domaines_web'] ?? []), json_encode($data['domaines_email'] ?? []),
                json_encode(self::normalizeEmailExceptions($data['emails_exceptions'] ?? $existing['emails_exceptions'] ?? [])),
                $db['driver'] ?? 'sqlite', $db['path'] ?? '', $db['host'] ?? '127.0.0.1',
                $db['port'] ?? 5432, $db['dbname'] ?? '', $db['user'] ?? '',
                $db['password'] ?? $existing['base_de_donnees']['password'] ?? '',
                $db['sslmode'] ?? 'prefer', $data['couleur'] ?? '#3b82f6',
                $sauvegardeJson, $now, $key,
            ]);
        } else {
            $stmt = self::db()->prepare("INSERT INTO sa_tenants
                (cle, nom, actif, domaines_web, domaines_email, emails_exceptions, db_driver, db_path, db_host, db_port, db_dbname, db_user, db_password, db_sslmode, couleur, sauvegarde, date_creation)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([
                $key, $data['nom'] ?? $key, ($data['actif'] ?? true) ? 1 : 0,
                json_encode($data['domaines_web'] ?? []), json_encode($data['domaines_email'] ?? []),
                json_encode(self::normalizeEmailExceptions($data['emails_exceptions'] ?? [])),
                $db['driver'] ?? 'sqlite', $db['path'] ?? '', $db['host'] ?? '127.0.0.1',
                $db['port'] ?? 5432, $db['dbname'] ?? '', $db['user'] ?? '',
                $db['password'] ?? '', $db['sslmode'] ?? 'prefer',
                $data['couleur'] ?? '#3b82f6', $sauvegardeJson, $now,
            ]);
        }
        self::$resolvedTenantKey = null;
        self::$resolvedTenant = null;
        return true;
    }

    public static function deleteTenant(string $key): bool {
        if ($key === 'default') return false;
        self::db()->prepare("DELETE FROM sa_tenants WHERE cle=?")->execute([$key]);
        self::db()->prepare("DELETE FROM sa_comptes_locaux WHERE tenant_cle=?")->execute([$key]);
        self::$resolvedTenantKey = null;
        return true;
    }

    /**
     * Provisionne la base de données d'un tenant.
     * - SQLite : crée le fichier
     * - PostgreSQL / MariaDB : CREATE DATABASE automatique via la connexion super admin
     * @return array{success: bool, message: string}
     */
    public static function provisionDatabase(string $key): array {
        $dbCfg = self::getDbConfigForTenant($key);
        if (!$dbCfg) return ['success' => false, 'message' => 'Pas de config DB pour ce tenant.'];

        $driver = $dbCfg['driver'] ?? 'sqlite';

        // ── SQLite : créer le fichier ────────────────────────────────────────
        if ($driver === 'sqlite') {
            $path = $dbCfg['path'] ?? '';
            if (!str_starts_with($path, '/')) $path = __DIR__ . '/../' . $path;
            $dir = dirname($path);
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            if (!file_exists($path)) touch($path);
            return ['success' => true, 'message' => 'Fichier SQLite créé : ' . basename($path)];
        }

        // ── PostgreSQL : CREATE DATABASE via la connexion admin ───────────────
        if ($driver === 'pgsql') {
            $dbname = $dbCfg['dbname'] ?? '';
            if (!$dbname) return ['success' => false, 'message' => 'Nom de base PostgreSQL requis.'];

            // Se connecter à la base "postgres" (toujours présente) avec les mêmes credentials
            try {
                $adminDsn = sprintf('pgsql:host=%s;port=%d;dbname=postgres;sslmode=%s',
                    $dbCfg['host'] ?? '127.0.0.1',
                    $dbCfg['port'] ?? 5432,
                    $dbCfg['sslmode'] ?? 'prefer'
                );
                $adminPdo = new \PDO($adminDsn, $dbCfg['user'] ?? '', $dbCfg['password'] ?? '');
                $adminPdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

                // Vérifier si la base existe déjà
                $stmt = $adminPdo->prepare("SELECT 1 FROM pg_database WHERE datname = ?");
                $stmt->execute([$dbname]);
                if ($stmt->fetchColumn()) {
                    return ['success' => true, 'message' => "Base PostgreSQL '{$dbname}' existe déjà."];
                }

                // Créer la base (on ne peut pas utiliser les paramètres bindés dans CREATE DATABASE)
                $safeName = preg_replace('/[^a-zA-Z0-9_]/', '_', $dbname);
                $owner = preg_replace('/[^a-zA-Z0-9_]/', '_', $dbCfg['user'] ?? 'gmao');
                $adminPdo->exec("CREATE DATABASE \"{$safeName}\" OWNER \"{$owner}\" ENCODING 'UTF8'");

                return ['success' => true, 'message' => "Base PostgreSQL '{$safeName}' créée automatiquement."];

            } catch (\PDOException $e) {
                $msg = $e->getMessage();
                // Si "already exists" ce n'est pas une erreur
                if (str_contains($msg, 'already exists')) {
                    return ['success' => true, 'message' => "Base PostgreSQL '{$dbname}' existe déjà."];
                }
                return ['success' => false, 'message' => "Erreur PostgreSQL : {$msg}"];
            }
        }

        // ── MariaDB / MySQL : CREATE DATABASE ────────────────────────────────
        if ($driver === 'mariadb' || $driver === 'mysql') {
            $dbname = $dbCfg['dbname'] ?? '';
            if (!$dbname) return ['success' => false, 'message' => 'Nom de base MariaDB requis.'];

            try {
                // Se connecter sans base spécifique
                $adminDsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4',
                    $dbCfg['host'] ?? '127.0.0.1',
                    $dbCfg['port'] ?? 3306
                );
                $adminPdo = new \PDO($adminDsn, $dbCfg['user'] ?? '', $dbCfg['password'] ?? '');
                $adminPdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

                $safeName = preg_replace('/[^a-zA-Z0-9_]/', '_', $dbname);
                $adminPdo->exec("CREATE DATABASE IF NOT EXISTS `{$safeName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

                return ['success' => true, 'message' => "Base MariaDB '{$safeName}' créée."];

            } catch (\PDOException $e) {
                return ['success' => false, 'message' => "Erreur MariaDB : " . $e->getMessage()];
            }
        }

        return ['success' => false, 'message' => "Driver inconnu : {$driver}"];
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  RÉSOLUTION
    // ══════════════════════════════════════════════════════════════════════════

    public static function resolve(): array {
        if (self::$resolvedTenantKey !== null) {
            return ['key' => self::$resolvedTenantKey, 'tenant' => self::$resolvedTenant];
        }

        if (!self::isMultiTenant()) {
            self::$resolvedTenantKey = 'default';
            self::$resolvedTenant = null;
            return ['key' => 'default', 'tenant' => null];
        }

        // 1. Super admin forçage
        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['forced_tenant'])) {
            $t = self::getTenant($_SESSION['forced_tenant']);
            if ($t) { self::$resolvedTenantKey = $_SESSION['forced_tenant']; self::$resolvedTenant = $t; return ['key' => $_SESSION['forced_tenant'], 'tenant' => $t]; }
        }

        // 2. Session tenant_key (après login par email)
        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['tenant_key'])) {
            $t = self::getTenant($_SESSION['tenant_key']);
            if ($t) { self::$resolvedTenantKey = $_SESSION['tenant_key']; self::$resolvedTenant = $t; return ['key' => $_SESSION['tenant_key'], 'tenant' => $t]; }
        }

        // 3. Host header
        $host = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
        $all = self::getAllTenants();
        foreach ($all as $key => $tenant) {
            if (!$tenant['actif']) continue;
            foreach ($tenant['domaines_web'] as $d) {
                if (strtolower($d) === $host) {
                    self::$resolvedTenantKey = $key; self::$resolvedTenant = $tenant;
                    return ['key' => $key, 'tenant' => $tenant];
                }
            }
        }

        // 4. Fallback default
        $def = self::getTenant('default');
        if ($def) { self::$resolvedTenantKey = 'default'; self::$resolvedTenant = $def; return ['key' => 'default', 'tenant' => $def]; }

        // 5. Premier tenant actif
        foreach ($all as $key => $tenant) {
            if ($tenant['actif']) { self::$resolvedTenantKey = $key; self::$resolvedTenant = $tenant; return ['key' => $key, 'tenant' => $tenant]; }
        }

        self::$resolvedTenantKey = 'default';
        self::$resolvedTenant = null;
        return ['key' => 'default', 'tenant' => null];
    }

    public static function getDbConfig(): ?array {
        $result = self::resolve();
        $t = $result['tenant'];
        return $t ? $t['base_de_donnees'] : null;
    }

    /**
     * Nettoie une liste d'adresses d'exception : trim, minuscules, doublons
     * supprimés, entrées vides ou sans « @ » écartées (un domaine seul se met
     * dans domaines_email, pas ici).
     */
    public static function normalizeEmailExceptions($list): array {
        if (is_string($list)) $list = preg_split('/[\s,;]+/', $list);
        if (!is_array($list)) return [];
        $out = [];
        foreach ($list as $e) {
            $e = strtolower(trim((string)$e));
            if ($e === '' || !str_contains($e, '@')) continue;
            if (!in_array($e, $out, true)) $out[] = $e;
        }
        return $out;
    }

    public static function resolveByEmailDomain(string $email): ?string {
        $emailLc = strtolower(trim($email));
        $domain = strtolower(substr(strrchr($email, '@'), 1));
        $all = self::getAllTenants();

        // 1) Exceptions nominatives : une adresse précise autorisée même si son
        //    domaine n'appartient à aucune organisation (prestataires, externes…).
        //    Prioritaires sur les domaines pour rester prévisibles.
        foreach ($all as $key => $tenant) {
            if (!$tenant['actif']) continue;
            foreach (($tenant['emails_exceptions'] ?? []) as $ex) {
                if (strtolower(trim((string)$ex)) === $emailLc) return $key;
            }
        }

        // 2) Correspondance par domaine (comportement historique).
        foreach ($all as $key => $tenant) {
            if (!$tenant['actif']) continue;
            foreach ($tenant['domaines_email'] as $d) {
                if ($d === '*') continue;
                if (strtolower($d) === $domain) return $key;
            }
        }
        return null;
    }

    public static function getTenantPublicInfo(): array {
        $result = self::resolve();
        $t = $result['tenant'];
        if (!$t) return ['key' => 'default', 'nom' => 'Larka', 'couleur' => '#3b82f6'];
        return ['key' => $result['key'], 'nom' => $t['nom'] ?? $result['key'], 'couleur' => $t['couleur'] ?? '#3b82f6'];
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  SUPER ADMIN AUTH
    // ══════════════════════════════════════════════════════════════════════════

    public static function getSuperAdmin(): ?array {
        $login = self::getConfig('sa_login');
        if (!$login) return null;
        return [
            'login' => $login,
            'mot_de_passe' => self::getConfig('sa_password') ?? '',
            'email' => self::getConfig('sa_email') ?? '',
        ];
    }

    /**
     * Retourne tous les comptes super admin locaux.
     * Le compte principal + les comptes supplémentaires stockés dans sa_accounts (JSON).
     * @return array [['login'=>..., 'mot_de_passe'=>..., 'email'=>...], ...]
     */
    public static function getAllSuperAdmins(): array {
        $accounts = [];
        // Compte principal
        $main = self::getSuperAdmin();
        if ($main && $main['login']) $accounts[] = $main;
        // Comptes supplémentaires
        $extra = self::getConfig('sa_accounts');
        if ($extra) {
            $decoded = json_decode($extra, true);
            if (is_array($decoded)) {
                foreach ($decoded as $acc) {
                    if (!empty($acc['login']) && !empty($acc['mot_de_passe'])) {
                        $accounts[] = $acc;
                    }
                }
            }
        }
        return $accounts;
    }

    public static function authenticateSuperAdmin(string $login, string $password): bool {
        // ⚠️ FIX SÉCURITÉ : refuser TOUT login si le compte principal a encore
        // le mot de passe par défaut « SuperAdmin2025! » (binaire connu).
        // Sans ça, n'importe qui qui découvre le projet a immédiatement accès
        // super-admin à toute installation non configurée.
        $main = self::getSuperAdmin();
        if ($main && !empty($main['mot_de_passe']) && password_verify('SuperAdmin2025!', $main['mot_de_passe'])) {
            // L'app DOIT passer par le flow superadmin_setup avant tout login.
            // On loggue mais on ne révèle pas la cause au client.
            error_log('[SECURITY] Tentative de login SA refusée : mot de passe par défaut encore actif. Forcer le setup.');
            if (class_exists('SecurityLog')) {
                SecurityLog::defaultPasswordAttempt($login);
            }
            return false;
        }

        // Vérifier parmi TOUS les comptes super admin (principal + supplémentaires)
        $accounts = self::getAllSuperAdmins();

        // ⚠️ FIX TIMING ATTACK : on parcourt TOUS les comptes même quand un
        // login a matché, et on utilise hash_equals pour la comparaison du
        // login. Sinon, le temps de réponse révèle si un login existe.
        $found = false;
        foreach ($accounts as $sa) {
            // hash_equals : comparaison à temps constant (login)
            $loginMatches = hash_equals((string)($sa['login'] ?? ''), $login);
            // password_verify est déjà à temps quasi-constant pour bcrypt
            $pwMatches = password_verify($password, (string)($sa['mot_de_passe'] ?? ''));
            if ($loginMatches && $pwMatches) {
                $found = true;
                // pas de break : on continue pour égaliser le temps
            }
        }
        return $found;
    }

    /**
     * Ajouter un compte super admin supplémentaire
     */
    public static function addSuperAdminAccount(string $login, string $hashedPassword, string $email = ''): bool {
        $extra = self::getConfig('sa_accounts');
        $accounts = $extra ? (json_decode($extra, true) ?? []) : [];
        // Vérifier doublon
        foreach ($accounts as $acc) {
            if ($acc['login'] === $login) return false; // déjà existant
        }
        // Vérifier aussi le compte principal
        $main = self::getSuperAdmin();
        if ($main && $main['login'] === $login) return false;
        $accounts[] = ['login' => $login, 'mot_de_passe' => $hashedPassword, 'email' => $email];
        self::setConfig('sa_accounts', json_encode($accounts));
        // Ajouter l'email aux emails autorisés Microsoft si fourni
        if ($email) {
            $emails = self::getSuperAdminEmails();
            if (!in_array(strtolower($email), array_map('strtolower', $emails))) {
                $emails[] = $email;
                self::setSuperAdminEmails($emails);
            }
        }
        return true;
    }

    /**
     * Supprimer un compte super admin supplémentaire (pas le principal)
     */
    public static function removeSuperAdminAccount(string $login): bool {
        $extra = self::getConfig('sa_accounts');
        $accounts = $extra ? (json_decode($extra, true) ?? []) : [];
        $accounts = array_values(array_filter($accounts, fn($a) => $a['login'] !== $login));
        self::setConfig('sa_accounts', json_encode($accounts));
        return true;
    }

    public static function updateSuperAdmin(string $login, string $hashedPassword, string $email = ''): bool {
        self::setConfig('sa_login', $login);
        self::setConfig('sa_password', $hashedPassword);
        self::setConfig('sa_email', $email);
        return true;
    }

    /**
     * Retourne la liste des emails autorisés comme super admin (Microsoft OAuth)
     * Stockés dans sa_config sous la clé 'sa_emails' (JSON array)
     * L'email principal du super admin est toujours inclus.
     */
    public static function getSuperAdminEmails(): array {
        $emails = [];
        // Email principal
        $mainEmail = self::getConfig('sa_email');
        if ($mainEmail) $emails[] = $mainEmail;
        // Emails supplémentaires (JSON array)
        $extra = self::getConfig('sa_emails');
        if ($extra) {
            $decoded = json_decode($extra, true);
            if (is_array($decoded)) $emails = array_merge($emails, $decoded);
        }
        return array_unique(array_filter($emails));
    }

    /**
     * Ajouter/remplacer la liste des emails autorisés comme super admin
     */
    public static function setSuperAdminEmails(array $emails): bool {
        self::setConfig('sa_emails', json_encode(array_values(array_unique(array_filter($emails)))));
        return true;
    }

    /**
     * Retourne le profil Larka du super admin (nom, prénom, email pour le compte Demandeur)
     */
    public static function getSuperAdminProfile(): array {
        $json = self::getConfig('sa_profile');
        if ($json) {
            $data = json_decode($json, true);
            if (is_array($data)) return $data;
        }
        return ['nom' => '', 'prenom' => '', 'email' => self::getConfig('sa_email') ?? ''];
    }

    /**
     * Mettre à jour le profil Larka du super admin
     */
    public static function setSuperAdminProfile(array $profile): bool {
        self::setConfig('sa_profile', json_encode($profile));
        return true;
    }

    /**
     * Personnalisation de l'écran de connexion, stockée sous 'sa_branding'.
     * Tous les champs sont publics (affichés sur la page de login).
     *   mode         : 'default' (texte seul) | 'image' (logo téléversé)
     *   theme_accent : couleur d'accentuation (#rrggbb) — vide = thème de base
     *   bg_mode      : 'animated' (fond animé river) | 'gradient' (dégradé fixe)
     *   theme_bg1/2  : dégradé de fond (#rrggbb), utilisé si bg_mode = 'gradient'
     *   custom_css   : CSS décoratif, filtré et scopé à #loginFx (animations perso)
     *
     * Note : la mention légale (auteur / copyright) est affichée en dur côté
     * client (#loginLegal dans index.html, d'après le fichier AUTHORS) et n'est
     * volontairement PAS configurable ici.
     *
     * Note sécurité : l'ancien mode 'html' (HTML brut sur page non authentifiée)
     * a été retiré. Le CSS personnalisé est filtré côté serveur (pas de url(),
     * @import, @font-face, position fixed/sticky, pointer-events…) et appliqué
     * uniquement à une couche décorative non-interactive — pas d'exfiltration
     * de saisie ni de recouvrement du formulaire possible.
     */
    public static function getBranding(): array {
        $defaults = [
            'mode'         => 'default',
            'title'        => '',
            'subtitle'     => '',
            'logo_image'   => '',
            'theme_accent' => '',
            'bg_mode'      => 'animated',
            'theme_bg1'    => '',
            'theme_bg2'    => '',
            'custom_mode'  => 'classic',
            'custom_css'   => '',
            'kofi_enabled' => true,
        ];
        $data = $defaults;
        $json = self::getConfig('sa_branding');
        if ($json) {
            $stored = json_decode($json, true);
            if (is_array($stored)) {
                // Liste blanche stricte : on ignore toute clé inconnue (ex. ancien
                // 'custom_html' encore en base) pour ne jamais la ré-exposer.
                foreach ($defaults as $k => $_) {
                    if (isset($stored[$k])) $data[$k] = $stored[$k];
                }
            }
        }
        // Garde-fous sur les valeurs énumérées.
        if (!in_array($data['mode'], ['default', 'image'], true)) $data['mode'] = 'default';
        if (!in_array($data['bg_mode'], ['animated', 'gradient'], true)) $data['bg_mode'] = 'animated';
        if (!in_array($data['custom_mode'], ['classic', 'css'], true)) $data['custom_mode'] = 'classic';
        $data['kofi_enabled'] = !empty($data['kofi_enabled']);
        return $data;
    }

    public static function setBranding(array $b): bool {
        $mode = in_array($b['mode'] ?? 'default', ['default', 'image'], true)
            ? $b['mode'] : 'default';
        $bgMode = in_array($b['bg_mode'] ?? 'animated', ['animated', 'gradient'], true)
            ? $b['bg_mode'] : 'animated';
        $customMode = in_array($b['custom_mode'] ?? 'classic', ['classic', 'css'], true)
            ? $b['custom_mode'] : 'classic';
        // Couleurs : on n'accepte QUE du hex #rgb / #rrggbb. Toute autre valeur
        // (y compris une tentative d'injection CSS) est réduite à '' = thème de base.
        $hex = static function ($v): string {
            $v = trim((string)$v);
            return preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $v) ? strtolower($v) : '';
        };
        // CSS : on ne stocke que s'il passe le filtre de sécurité, sinon '' .
        $css = (string)($b['custom_css'] ?? '');
        if (self::loginCssError($css) !== null) $css = '';
        $clean = [
            'mode'         => $mode,
            'title'        => (string)($b['title'] ?? ''),
            'subtitle'     => (string)($b['subtitle'] ?? ''),
            'logo_image'   => (string)($b['logo_image'] ?? ''),
            'theme_accent' => $hex($b['theme_accent'] ?? ''),
            'bg_mode'      => $bgMode,
            'theme_bg1'    => $hex($b['theme_bg1'] ?? ''),
            'theme_bg2'    => $hex($b['theme_bg2'] ?? ''),
            'custom_mode'  => $customMode,
            'custom_css'   => $css,
            'kofi_enabled' => !empty($b['kofi_enabled']),
        ];
        self::setConfig('sa_branding', json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return true;
    }

    /**
     * Filtre de sécurité du CSS personnalisé de la page de connexion.
     * Renvoie null si le CSS est acceptable, sinon un message d'erreur explicite.
     *
     * Le CSS est appliqué scopé à #loginFx (couche décorative, pointer-events:none),
     * ce qui interdit déjà de cibler le formulaire. Ce filtre bloque en plus les
     * vecteurs résiduels : chargements externes (exfiltration de saisie via url()),
     * positionnement fixed/sticky et pointer-events (recouvrement/clickjacking).
     */
    public static function loginCssError(string $css): ?string {
        if ($css === '') return null;
        if (strlen($css) > 8000) return "CSS personnalisé trop long (8 Ko maximum).";
        if (preg_match('/[<>]/', $css)) return "Le CSS ne doit pas contenir « < » ni « > ».";
        $c = preg_replace('/\s+/', '', strtolower($css));
        $forbidden = [
            'url('        => 'url()',
            '@import'     => '@import',
            '@charset'    => '@charset',
            '@font-face'  => '@font-face',
            '@scope'      => '@scope',
            'expression(' => 'expression()',
            'javascript:' => 'javascript:',
            'behavior:'   => 'behavior',
            '-moz-binding'=> '-moz-binding',
            'position:fixed'  => 'position:fixed',
            'position:sticky' => 'position:sticky',
            'pointer-events'  => 'pointer-events',
        ];
        foreach ($forbidden as $needle => $label) {
            if (strpos($c, $needle) !== false) {
                return "Élément CSS interdit pour des raisons de sécurité : « $label ». "
                     . "Les chargements externes (url(), @import, @font-face), le positionnement "
                     . "fixed/sticky et pointer-events ne sont pas autorisés. "
                     . "Utilisez transform / opacity / @keyframes pour vos animations.";
            }
        }
        return null;
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  COMPTES LOCAUX
    // ══════════════════════════════════════════════════════════════════════════

    public static function getAllLocalAccounts(): array {
        $rows = self::db()->query("SELECT login, tenant_cle FROM sa_comptes_locaux ORDER BY login")->fetchAll();
        $result = [];
        foreach ($rows as $r) $result[$r['login']] = $r['tenant_cle'];
        return $result;
    }

    public static function resolveLocalAccount(string $login): ?string {
        $stmt = self::db()->prepare("SELECT tenant_cle FROM sa_comptes_locaux WHERE LOWER(login)=LOWER(?)");
        $stmt->execute([$login]);
        $r = $stmt->fetch();
        return $r ? $r['tenant_cle'] : null;
    }

    public static function isLoginTaken(string $login): bool {
        return self::resolveLocalAccount($login) !== null;
    }

    public static function isLoginTakenByOther(string $login, string $currentTenantKey): bool {
        $existing = self::resolveLocalAccount($login);
        return $existing !== null && $existing !== $currentTenantKey;
    }

    public static function registerLocalAccount(string $login, string $tenantKey): true|string {
        $existing = self::resolveLocalAccount($login);
        if ($existing !== null && $existing !== $tenantKey) {
            $t = self::getTenant($existing);
            return "Le login \"{$login}\" est déjà utilisé dans le tenant \"" . ($t['nom'] ?? $existing) . "\".";
        }
        if ($existing === $tenantKey) return true; // déjà enregistré
        try {
            self::db()->prepare("INSERT INTO sa_comptes_locaux (login, tenant_cle, date_creation) VALUES (?,?,?)")
                ->execute([$login, $tenantKey, date('Y-m-d H:i:s')]);
        } catch (\PDOException $e) { /* doublon */ }
        return true;
    }

    public static function unregisterLocalAccount(string $login): bool {
        self::db()->prepare("DELETE FROM sa_comptes_locaux WHERE LOWER(login)=LOWER(?)")->execute([$login]);
        return true;
    }

    public static function renameLocalAccount(string $oldLogin, string $newLogin, string $tenantKey): true|string {
        if (strtolower($oldLogin) !== strtolower($newLogin)) {
            $taken = self::resolveLocalAccount($newLogin);
            if ($taken !== null && $taken !== $tenantKey) {
                $t = self::getTenant($taken);
                return "Le login \"{$newLogin}\" est déjà utilisé dans \"" . ($t['nom'] ?? $taken) . "\".";
            }
        }
        self::unregisterLocalAccount($oldLogin);
        return self::registerLocalAccount($newLogin, $tenantKey);
    }

    public static function getLocalAccountsForTenant(string $tenantKey): array {
        $stmt = self::db()->prepare("SELECT login FROM sa_comptes_locaux WHERE tenant_cle=? ORDER BY login");
        $stmt->execute([$tenantKey]);
        return array_column($stmt->fetchAll(), 'login');
    }

    public static function syncLocalAccountsForTenant(string $tenantKey, array $dbUsers): int {
        $changes = 0;
        $localLogins = array_filter($dbUsers, fn($u) => ($u['Provider'] ?? 'local') === 'local');

        // Ajouter les manquants
        foreach ($localLogins as $u) {
            $login = $u['Login'] ?? '';
            if (!$login) continue;
            $existing = self::resolveLocalAccount($login);
            if ($existing === null) {
                self::registerLocalAccount($login, $tenantKey);
                $changes++;
            }
        }

        // Supprimer les orphelins de ce tenant
        $dbLoginList = array_map(fn($u) => strtolower($u['Login'] ?? ''), $localLogins);
        $registered = self::getLocalAccountsForTenant($tenantKey);
        foreach ($registered as $regLogin) {
            if (!in_array(strtolower($regLogin), $dbLoginList)) {
                self::unregisterLocalAccount($regLogin);
                $changes++;
            }
        }

        return $changes;
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  COMPAT (méthodes utilisées par les anciennes routes)
    // ══════════════════════════════════════════════════════════════════════════

    /** @deprecated Use getAllTenants() */
    public static function loadConfig(): array {
        return [
            'super_admin' => self::getSuperAdmin(),
            'tenants' => self::getAllTenants(),
            'comptes_locaux' => self::getAllLocalAccounts(),
        ];
    }

    /** @deprecated Use upsertTenant() */
    public static function saveConfig(array $config): bool { return true; }

    /**
     * Info sur la base super admin elle-même (pour le monitoring)
     */
    public static function getSuperAdminDbInfo(): array {
        $cfg = self::readSuperAdminDbConfig();
        return [
            'driver' => $cfg['driver'] ?? 'sqlite',
            'host' => $cfg['host'] ?? '',
            'dbname' => $cfg['dbname'] ?? '',
        ];
    }
}

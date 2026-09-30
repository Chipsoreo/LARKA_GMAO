<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka — Routes : Migration inter-drivers
 *
 * Actions : migration_test, migration_run
 * Permet de migrer les données entre SQLite ↔ PostgreSQL ↔ MariaDB.
 */

// ── Test de connexion seul ─────────────────────────────────────────────────
if ($action === 'db_migrate_test') {
    $user = require_auth(); require_role($user, ['Admin','Gestionnaire']);
    if ($method !== 'POST') json_error('Méthode non supportée.', 405);
    $dest = get_body()['destination'] ?? null;
    if (!$dest) json_error('Paramètre destination manquant.', 400);
    try {
        $pdo = _mig_makePdo($dest);
        $pdo->query('SELECT 1');
        json_ok(['connected' => true]);
    } catch (\Exception $e) {
        json_ok(['connected' => false, 'error' => _mig_diagnose($dest, $e->getMessage())]);
    }
}

// ── Migration complète ────────────────────────────────────────────────────
if ($action === 'db_migrate') {
    $user = require_auth(); require_role($user, ['Admin','Gestionnaire']);
    if ($method !== 'POST') json_error('Méthode non supportée.', 405);

    $body       = get_body();
    $dest       = $body['destination']   ?? null;
    $backupPath = $body['backup_path']   ?? null; // chemin choisi par l'admin
    if (!$dest || empty($dest['driver'])) json_error('Paramètre destination manquant.', 400);

    // ── Ordre de migration ───────────────────────────────────────────────
    $TABLES = [
        'Configuration','Listes','Utilisateurs','Biens','Equipements',
        'Contrats','Stock','Interventions','DemandesIntervention',
        'IntervStockLignes','GestionMateriel','CompteurEnergie',
        'ReleverEnergie','Documents','HistoriqueSuppression','NotesInfo',
    ];

    $report   = [];
    $savedAt  = null; // chemin sauvegarde créée

    // ════════════════════════════════════════════════════════════════════
    // ÉTAPE 1 — Sauvegarde obligatoire
    // ════════════════════════════════════════════════════════════════════
    $savedAt = _mig_backup($backupPath, $report);
    // $savedAt = chemin absolu, ou null si source non-SQLite (pg/mariadb)

    // ════════════════════════════════════════════════════════════════════
    // ÉTAPE 2 — Test de connexion destination
    // ════════════════════════════════════════════════════════════════════
    $destPdo = null;
    try {
        $destPdo = _mig_makePdo($dest);
        $destPdo->query('SELECT 1');
        $report[] = ['table'=>'🔌 Connexion destination','rows'=>0,'status'=>'ok',
            'detail'=> strtoupper($dest['driver']) . ' joignable'];
    } catch (\Exception $e) {
        // Supprimer la sauvegarde si elle a été créée
        if ($savedAt && file_exists($savedAt)) unlink($savedAt);
        $diag = _mig_diagnose($dest, $e->getMessage());
        json_error("Connexion destination impossible — sauvegarde supprimée.\n\n" . $diag, 503);
    }

    // ════════════════════════════════════════════════════════════════════
    // ÉTAPE 3 — Migration des données
    // ════════════════════════════════════════════════════════════════════
    $srcPdo = null;
    try {
        $srcPdo = _mig_makeSrcPdo();

        // ── Tables des modules ───────────────────────────────────────────
        //
        // Les modules déclaratifs rangent leurs données dans de VRAIES tables
        // (ext_<identifiant>_<jeu>). Les sauvegardes les reprennent, puisqu'elles
        // énumèrent le schéma — mais la migration entre moteurs travaillait sur
        // une liste écrite en dur. On migrait donc de SQLite vers PostgreSQL et
        // les registres des modules restaient derrière, sans un mot.
        //
        // Ajoutées en FIN de liste : elles ne dépendent d'aucune autre table, et
        // l'ordre du cœur reste inchangé.
        try {
            $pilote = $srcPdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $req = match ($pilote) {
                'sqlite' => "SELECT name FROM sqlite_master WHERE type='table' "
                          . "AND name LIKE 'ext@_%' ESCAPE '@'",
                'pgsql'  => "SELECT tablename AS name FROM pg_tables "
                          . "WHERE schemaname='public' AND tablename LIKE 'ext@_%' ESCAPE '@'",
                default  => "SHOW TABLES LIKE 'ext@_%'",
            };
            foreach ($srcPdo->query($req)->fetchAll(PDO::FETCH_COLUMN) as $t) {
                if (!in_array((string)$t, $TABLES, true)) $TABLES[] = (string)$t;
            }
        } catch (\Throwable $e) {
            // Base sans module : rien à ajouter.
        }
    } catch (\Exception $e) {
        if ($savedAt && file_exists($savedAt)) unlink($savedAt);
        json_error('Connexion source impossible : ' . $e->getMessage(), 500);
    }

    $destDriver  = $dest['driver'];
    $isPgDest    = ($destDriver === 'pgsql');
    $isMysqlDest = ($destDriver === 'mariadb' || $destDriver === 'mysql');
    $AI = $isPgDest ? 'SERIAL PRIMARY KEY'
        : ($isMysqlDest ? 'INT AUTO_INCREMENT PRIMARY KEY'
        : 'INTEGER PRIMARY KEY AUTOINCREMENT');

    // Créer tables destination
    $createSqls = _mig_createTablesSql($AI);
    try {
        foreach ($createSqls as $sql) $destPdo->exec($sql);
        $report[] = ['table'=>'🏗️ Structure','rows'=>0,'status'=>'ok',
            'detail'=>'Tables créées/vérifiées'];
    } catch (\Exception $e) {
        if ($savedAt && file_exists($savedAt)) unlink($savedAt);
        json_error('Erreur création tables : ' . $e->getMessage(), 500);
    }

    // Vider la destination
    _mig_truncate($destPdo, $TABLES, $isPgDest, $isMysqlDest);
    $report[] = ['table'=>'🗑️ Nettoyage','rows'=>0,'status'=>'ok','detail'=>'Destination vidée'];

    // Désactiver FK pendant l'import
    if ($isPgDest)    $destPdo->exec("SET session_replication_role = replica");
    if ($isMysqlDest) $destPdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    if (!$isPgDest && !$isMysqlDest) $destPdo->exec("PRAGMA foreign_keys = OFF");

    $errors = [];
    foreach ($TABLES as $table) {
        try {
            if (!_mig_tableExists($srcPdo, DB_DRIVER, $table)) {
                $report[] = ['table'=>$table,'rows'=>0,'status'=>'skip','detail'=>'Absente en source'];
                continue;
            }
            $rows  = $srcPdo->query("SELECT * FROM $table")->fetchAll(PDO::FETCH_ASSOC);
            $total = count($rows);
            if ($total === 0) {
                $report[] = ['table'=>$table,'rows'=>0,'status'=>'ok','detail'=>'Vide'];
                continue;
            }

            $cols    = array_keys($rows[0]);
            // PostgreSQL: tables et colonnes sont en minuscules (pas de guillemets dans CREATE)
            // Il faut mapper les colonnes CamelCase de SQLite vers minuscules pour PG
            if ($isPgDest) {
                $colsLower = array_map('strtolower', $cols);
                $colList   = implode(', ', $colsLower);
                $params    = implode(', ', array_map(fn($c) => ":$c", $colsLower));
            } else {
                $colList = implode(', ', $cols);
                $params  = implode(', ', array_map(fn($c) => ":$c", $cols));
            }

            $insertSql = $isPgDest
                ? "INSERT INTO $table ($colList) OVERRIDING SYSTEM VALUE VALUES ($params) ON CONFLICT DO NOTHING"
                : ($isMysqlDest
                    ? "INSERT IGNORE INTO $table ($colList) VALUES ($params)"
                    : "INSERT OR IGNORE INTO $table ($colList) VALUES ($params)");

            if ($isPgDest) {
                try { $destPdo->exec("ALTER TABLE $table DISABLE TRIGGER ALL"); } catch(\Exception $e){}
            }

            $inserted = 0;
            foreach (array_chunk($rows, 500) as $batch) {
                $destPdo->beginTransaction();
                try {
                    $stmt = $destPdo->prepare($insertSql);
                    foreach ($batch as $row) {
                        // Pour PostgreSQL: convertir les clés en minuscules
                        if ($isPgDest) {
                            $mapped = [];
                            foreach ($row as $k => $v) {
                                $mapped[strtolower($k)] = $v;
                            }
                            $row = $mapped;
                        } else {
                            foreach ($row as $k => $v) {
                                if ($v === true)  $row[$k] = 1;
                                if ($v === false) $row[$k] = 0;
                            }
                        }
                        $stmt->execute($row);
                        $inserted++;
                    }
                    $destPdo->commit();
                } catch (\Exception $e) {
                    $destPdo->rollBack(); throw $e;
                }
            }

            if ($isPgDest) {
                try { $destPdo->exec("ALTER TABLE $table ENABLE TRIGGER ALL"); } catch(\Exception $e){}
            }

            $report[] = ['table'=>$table,'rows'=>$inserted,'status'=>'ok',
                'detail'=>"$inserted ligne(s)"];

        } catch (\Exception $e) {
            $errors[] = "$table : " . $e->getMessage();
            $report[] = ['table'=>$table,'rows'=>0,'status'=>'error','detail'=>$e->getMessage()];
        }
    }

    // Réactiver FK
    if ($isPgDest)    $destPdo->exec("SET session_replication_role = DEFAULT");
    if ($isMysqlDest) $destPdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    if (!$isPgDest && !$isMysqlDest) $destPdo->exec("PRAGMA foreign_keys = ON");

    // Si erreurs → rollback total : on ne touche pas config.json, on supprime la sauvegarde
    if (!empty($errors)) {
        if ($savedAt && file_exists($savedAt)) unlink($savedAt);
        json_error(
            "Migration échouée (" . count($errors) . " erreur(s)) — aucune modification apportée.\n\n"
            . implode("\n", $errors),
            500
        );
    }

    // ── Resync séquences PostgreSQL ──────────────────────────────────────
    if ($isPgDest) {
        $synced = [];
        foreach ($TABLES as $table) {
            try {
                $tl  = strtolower($table);
                $seq = $destPdo->query("SELECT pg_get_serial_sequence('$tl','id') AS seq")->fetch()['seq'] ?? null;
                if ($seq) {
                    $destPdo->exec("SELECT setval('$seq', COALESCE((SELECT MAX(id) FROM $tl),1))");
                    $synced[] = $table;
                }
            } catch (\Exception $e) {}
        }
        if ($synced) {
            $report[] = ['table'=>'🔄 Séquences PG','rows'=>count($synced),'status'=>'ok',
                'detail'=>'Resynchronisées : ' . implode(', ', $synced)];
        }
    }

    // ════════════════════════════════════════════════════════════════════
    // ÉTAPE 4 — Mise à jour config.json avec le nouveau driver
    // ════════════════════════════════════════════════════════════════════
    $cfgFile = __DIR__ . '/../../config.json';
    $cfg = json_decode(file_get_contents($cfgFile), true) ?? [];
    $cfg['base_de_donnees']['driver'] = $destDriver;
    if ($destDriver === 'sqlite') {
        $cfg['base_de_donnees']['path'] = $dest['path'] ?? 'data/gmao.db';
    } else {
        $cfg['base_de_donnees']['host']    = $dest['host']    ?? '127.0.0.1';
        $cfg['base_de_donnees']['port']    = (int)($dest['port'] ?? ($isPgDest ? 5432 : 3306));
        $cfg['base_de_donnees']['dbname']  = $dest['dbname']  ?? 'gmao';
        $cfg['base_de_donnees']['user']    = $dest['user']    ?? '';
        if (!empty($dest['password']) && !str_contains($dest['password'], '••••')) {
            $cfg['base_de_donnees']['password'] = $dest['password'];
        }
        if ($isPgDest) {
            $cfg['base_de_donnees']['sslmode'] = $dest['sslmode'] ?? 'prefer';
        }
    }
    file_put_contents($cfgFile, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $report[] = ['table'=>'⚙️ config.json','rows'=>0,'status'=>'ok',
        'detail'=>"driver mis à jour → $destDriver — redémarrez le serveur"];

    json_ok([
        'success'    => true,
        'src_driver' => DB_DRIVER,
        'dst_driver' => $destDriver,
        'backup'     => $savedAt ? basename($savedAt) : null,
        'report'     => $report,
    ]);
}

// ══════════════════════════════════════════════════════════════════════════
// FONCTIONS UTILITAIRES
// ══════════════════════════════════════════════════════════════════════════

function _mig_makeSrcPdo(): PDO {
    $driver = DB_DRIVER;
    if ($driver === 'sqlite') {
        if (!file_exists(DB_PATH)) throw new \RuntimeException("Fichier SQLite introuvable : " . DB_PATH);
        $pdo = new PDO('sqlite:' . DB_PATH);
    } elseif ($driver === 'pgsql') {
        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=%s', DB_HOST, DB_PORT, DB_NAME, DB_SSLMODE);
        $pdo = new PDO($dsn, DB_USER, DB_PASS);
    } else {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
        $pdo = new PDO($dsn, DB_USER, DB_PASS);
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}

function _mig_makePdo(array $d): PDO {
    $drv = $d['driver'];
    if ($drv === 'sqlite') {
        $path = $d['path'] ?? 'data/gmao.db';
        if (!str_starts_with($path, '/') && !preg_match('/^[A-Za-z]:/', $path))
            $path = __DIR__ . '/../../' . $path;
        $dir = dirname($path);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $pdo = new PDO('sqlite:' . $path);
    } elseif ($drv === 'pgsql') {
        $ssl = $d['sslmode'] ?? 'prefer';
        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
            $d['host'], (int)($d['port'] ?? 5432), $d['dbname'], $ssl);
        $pdo = new PDO($dsn, $d['user'] ?? '', $d['password'] ?? '');
    } else {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $d['host'], (int)($d['port'] ?? 3306), $d['dbname']);
        $pdo = new PDO($dsn, $d['user'] ?? '', $d['password'] ?? '', [
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
        ]);
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}

function _mig_backup(?string $customPath, array &$report): ?string {
    $driver = DB_DRIVER;
    // SQLite → copie du fichier .db
    if ($driver === 'sqlite' && file_exists(DB_PATH)) {
        if ($customPath) {
            // Chemin choisi par l'admin — résoudre si relatif
            if (!str_starts_with($customPath, '/') && !preg_match('/^[A-Za-z]:/', $customPath))
                $customPath = __DIR__ . '/../../' . $customPath;
            $dir = dirname($customPath);
        } else {
            $dir        = __DIR__ . '/../../data/backups';
            $customPath = $dir . '/pre_migration_' . date('Ymd_His') . '.db';
        }
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        if (!copy(DB_PATH, $customPath)) {
            json_error("Impossible de créer la sauvegarde dans : $customPath — vérifiez les permissions.", 500);
        }
        $report[] = ['table'=>'📦 Sauvegarde','rows'=>0,'status'=>'ok',
            'detail'=>'SQLite → ' . basename($customPath)];
        return $customPath;
    }
    // PostgreSQL / MariaDB → pas de fichier à copier, on signale
    $report[] = ['table'=>'📦 Sauvegarde','rows'=>0,'status'=>'info',
        'detail'=>'Source ' . strtoupper($driver) . ' : faites un pg_dump/mysqldump avant de continuer. Pas de sauvegarde automatique.'];
    return null;
}

function _mig_truncate(PDO $pdo, array $tables, bool $isPg, bool $isMysql): void {
    $rev = array_reverse($tables);
    if ($isPg) {
        $pdo->exec("SET session_replication_role = replica");
        foreach ($rev as $t) { try { $pdo->exec("TRUNCATE TABLE $t CASCADE"); } catch(\Exception $e){} }
        $pdo->exec("SET session_replication_role = DEFAULT");
    } elseif ($isMysql) {
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        foreach ($rev as $t) { try { $pdo->exec("TRUNCATE TABLE $t"); } catch(\Exception $e){} }
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    } else {
        $pdo->exec("PRAGMA foreign_keys = OFF");
        foreach ($rev as $t) { try { $pdo->exec("DELETE FROM $t"); } catch(\Exception $e){} }
        $pdo->exec("PRAGMA foreign_keys = ON");
    }
}

function _mig_tableExists(PDO $pdo, string $driver, string $table): bool {
    try {
        if ($driver === 'sqlite') {
            return (bool)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='$table'")->fetchColumn();
        } elseif ($driver === 'pgsql') {
            return (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_name=lower('$table')")->fetchColumn();
        } else {
            return (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='$table'")->fetchColumn();
        }
    } catch (\Exception $e) { return false; }
}

function _mig_diagnose(array $dest, string $msg): string {
    $drv  = $dest['driver'];
    $host = $dest['host'] ?? 'n/a';
    $port = $dest['port'] ?? 'n/a';
    $lines = ["Erreur : $msg", "Driver : $drv | Serveur : $host:$port"];

    if (str_contains($msg, 'Connection refused') || str_contains($msg, '10061') || str_contains($msg, 'refused')) {
        $lines[] = "⚠️  Le serveur $drv n'est pas démarré ou n'écoute pas sur ce port.";
        if ($drv === 'pgsql')   $lines[] = "→  Installez PostgreSQL : https://www.postgresql.org/download/";
        if ($drv !== 'sqlite')  $lines[] = "→  Ou repassez en SQLite dans la config.";
    } elseif (str_contains($msg, 'could not find driver') || !extension_loaded('pdo_' . ($drv==='mariadb'?'mysql':$drv))) {
        $ext = 'pdo_' . ($drv === 'mariadb' ? 'mysql' : $drv);
        $lines[] = "⚠️  Extension PHP $ext non activée.";
        $lines[] = "→  Ajoutez extension=php_{$ext}.dll dans php.ini";
    } elseif (str_contains($msg, 'password authentication') || str_contains($msg, 'Access denied')) {
        $lines[] = "⚠️  Identifiants incorrects (user / password).";
    } elseif (str_contains($msg, 'does not exist') || str_contains($msg, 'Unknown database')) {
        $lines[] = "⚠️  La base de données \"" . ($dest['dbname'] ?? '') . "\" n'existe pas.";
        $lines[] = $drv === 'pgsql'
            ? "→  Créez-la : CREATE DATABASE " . ($dest['dbname']??'gmao') . ";"
            : "→  Créez-la : CREATE DATABASE " . ($dest['dbname']??'gmao') . " CHARACTER SET utf8mb4;";
    }
    return implode("\n", $lines);
}

function _mig_createTablesSql(string $AI): array {
    return [
        "CREATE TABLE IF NOT EXISTS Configuration (Id $AI, Cle TEXT NOT NULL UNIQUE, Valeur TEXT DEFAULT '', Description TEXT)",
        "CREATE TABLE IF NOT EXISTS Listes (Id $AI, Categorie TEXT NOT NULL, Valeur TEXT NOT NULL, Ordre INTEGER DEFAULT 0, Actif INTEGER DEFAULT 1, Obligatoire INTEGER DEFAULT 0, SaisieLibre INTEGER DEFAULT 0)",
        "CREATE TABLE IF NOT EXISTS Utilisateurs (Id $AI, Nom TEXT NOT NULL, Prenom TEXT NOT NULL, Login TEXT NOT NULL UNIQUE, Email TEXT, Tel TEXT DEFAULT '', MotDePasse TEXT NOT NULL, Role TEXT DEFAULT 'Demandeur', Provider TEXT DEFAULT 'local', DateCreation TEXT, Actif INTEGER DEFAULT 1, ResetToken TEXT, ResetTokenExpiry TEXT)",
        "CREATE TABLE IF NOT EXISTS Biens (Id $AI, Numero TEXT, Famille TEXT, SousFamille TEXT, Statut TEXT, NumeroSerie TEXT, Etat TEXT, DateCommande TEXT, DateLivraison TEXT, DateSuppression TEXT, Prix REAL DEFAULT 0, InfoProduit TEXT, Batiment TEXT, Etage TEXT, NumeroBureau TEXT, NomPrenom TEXT, Documents TEXT, CommentaireSortie TEXT DEFAULT '', DateSortie TEXT, CreatedAt TEXT, CreatedBy TEXT, UpdatedAt TEXT, UpdatedBy TEXT)",
        "CREATE TABLE IF NOT EXISTS Equipements (Id $AI, Numero TEXT, Famille TEXT, SousFamille TEXT, Statut TEXT, NumeroSerie TEXT, Etat TEXT, DateCommande TEXT, DateLivraison TEXT, DateInstallation TEXT, DateSuppression TEXT, Prix REAL DEFAULT 0, InfoProduit TEXT, Marque TEXT, Modele TEXT, Fournisseur TEXT, Batiment TEXT, Etage TEXT, NumeroBureau TEXT, NomPrenom TEXT, Observations TEXT, Documents TEXT, CommentaireSortie TEXT DEFAULT '', DateSortie TEXT, CreatedAt TEXT, CreatedBy TEXT, UpdatedAt TEXT, UpdatedBy TEXT)",
        "CREATE TABLE IF NOT EXISTS Contrats (Id $AI, Numero TEXT, Societe TEXT NOT NULL, Type TEXT, Description TEXT, Perimetre TEXT, EquipementsIds TEXT, DateDebut TEXT, DateFin TEXT, AlerteJoursAvant INTEGER DEFAULT 30, MontantAnnuel REAL DEFAULT 0, Frequence TEXT, ContactNom TEXT, ContactTel TEXT, ContactEmail TEXT, Statut TEXT, Documents TEXT, Commentaire TEXT, OptionTarifaire TEXT, PuissanceSouscrite REAL, PrixBaseHT REAL, PrixHPHT REAL, PrixHCHT REAL, AbonnementHT REAL, FournisseurEnergie TEXT, Fluide TEXT, HCDebut TEXT, HCFin TEXT, AbonnementTTC REAL, PrixBaseTTC REAL, TVARate REAL, ChauffageR1 REAL, ChauffageR2 REAL, PrixHPTTC REAL, PrixHCTTC REAL, TVAKwh REAL, TVAAbo REAL, HtaHPHS REAL, HtaHPHSTTC REAL, HtaHCHS REAL, HtaHCHSTTC REAL, HtaHPBS REAL, HtaHPBSTTC REAL, HtaHCBS REAL, HtaHCBSTTC REAL, HtaCEE REAL, HtaCEETTC REAL, HtaCapa REAL, HtaCapaTTC REAL, HtaTVALignes TEXT, ReferentClient TEXT, ReferenceContrat TEXT, TypeCompteur TEXT, CreatedAt TEXT, CreatedBy TEXT, UpdatedAt TEXT, UpdatedBy TEXT)",
        "CREATE TABLE IF NOT EXISTS Stock (Id $AI, Reference TEXT, Designation TEXT NOT NULL, Categorie TEXT, Marque TEXT DEFAULT '', Modele TEXT DEFAULT '', TypeArticle TEXT DEFAULT '', Quantite INTEGER DEFAULT 0, SeuilAlerte INTEGER DEFAULT 5, PrixUnitaire REAL DEFAULT 0, Emplacement TEXT, Fournisseur TEXT, Source TEXT DEFAULT 'Achat', ContratId INTEGER, CreatedAt TEXT, CreatedBy TEXT, UpdatedAt TEXT, UpdatedBy TEXT)",
        "CREATE TABLE IF NOT EXISTS Interventions (Id $AI, Numero TEXT, Type TEXT, Priorite TEXT, TypeLiaison TEXT, BienId INTEGER, EquipementsIds TEXT, ContratId INTEGER, DemandeId INTEGER, SocieteManuelle TEXT, Description TEXT, DateRealisation TEXT, DureeHeures REAL DEFAULT 0, Montant REAL DEFAULT 0, MontantPieces REAL DEFAULT 0, MontantMainOeuvre REAL DEFAULT 0, EstRecurrente INTEGER DEFAULT 0, Recurrence TEXT, DateProchaine TEXT, InterventionParentId INTEGER, Statut TEXT, Statut2 TEXT, Commentaire TEXT, Documents TEXT, DateValidation TEXT, DateArchivage TEXT, CreatedAt TEXT, CreatedBy TEXT, UpdatedAt TEXT, UpdatedBy TEXT)",
        "CREATE TABLE IF NOT EXISTS DemandesIntervention (Id $AI, Titre TEXT NOT NULL, Description TEXT, Batiment TEXT, Bureau TEXT, Urgence TEXT DEFAULT 'Normale', Statut TEXT DEFAULT 'Demandeur', CommentaireAdmin TEXT, UtilisateurId INTEGER, NomDeclarant TEXT, DateCreation TEXT, DateTraitement TEXT, DelaiRelanceJours INTEGER DEFAULT 7, DateDerniereRelance TEXT, Priorite TEXT DEFAULT 'Normale', EmailDemandeur TEXT DEFAULT '', TelDemandeur TEXT DEFAULT '', PourAutrui INTEGER DEFAULT 0, NomAutrui TEXT DEFAULT '', EmailAutrui TEXT DEFAULT '', TelAutrui TEXT DEFAULT '', Categorie TEXT DEFAULT '')",
        "CREATE TABLE IF NOT EXISTS IntervStockLignes (Id $AI, InterventionId INTEGER NOT NULL, StockId INTEGER NOT NULL, Quantite INTEGER NOT NULL DEFAULT 1, PrixUnitaire REAL DEFAULT 0, Description TEXT, DateConsommation TEXT)",
        "CREATE TABLE IF NOT EXISTS GestionMateriel (Id $AI, AssetType TEXT, AssetId INTEGER, NumeroImmo TEXT, CompteImmo TEXT, Exercice TEXT, BonDeCommande TEXT, DateMiseEnService TEXT, NumeroMandat TEXT, DateAchat TEXT, ValeurAchat REAL, ValeurVenale REAL, DureeAmortissement INTEGER, MontantDuMarche REAL, DateBascule TEXT, AnnualiteAmortissement REAL DEFAULT 0, DepreciationTotal REAL DEFAULT 0, DateCalculDepreciation TEXT, User7 TEXT DEFAULT '', Item TEXT DEFAULT '', EstCloture INTEGER DEFAULT 0, DateCloture TEXT, MotifCloture TEXT DEFAULT '', ClotureParLogin TEXT)",
        "CREATE TABLE IF NOT EXISTS CompteurEnergie (Id $AI, Nom TEXT NOT NULL, Type TEXT NOT NULL, Unite TEXT, Site TEXT, NumeroCompteur TEXT, ContratId INTEGER, DateInstallation TEXT, Actif INTEGER DEFAULT 1, PrixTTC REAL, PrixHT REAL, PrixHP REAL, PrixHC REAL, Abonnement REAL, AbonnementTTC REAL, PrixUnitaireTTC REAL, TVARate REAL, ChauffageR1 REAL, ChauffageR2 REAL)",
        "CREATE TABLE IF NOT EXISTS ReleverEnergie (Id $AI, CompteurId INTEGER NOT NULL, Type TEXT DEFAULT 'Releve', Date TEXT NOT NULL, IndexDebut REAL, IndexFin REAL, Consommation REAL, Montant REAL, Fournisseur TEXT, NumeroFacture TEXT, PeriodeDebut TEXT, PeriodeFin TEXT, Commentaire TEXT, SaisieParLogin TEXT, DateSaisie TEXT, ModeFacture TEXT, DetailHTA TEXT, PlagesAvance TEXT, Carbone REAL, MontantHT REAL, PrixUnitHT REAL, TVAReleve REAL, TVA REAL)",
        "CREATE TABLE IF NOT EXISTS Documents (Id $AI, EntiteType TEXT NOT NULL, EntiteId INTEGER NOT NULL, NomFichier TEXT NOT NULL, TypeMime TEXT, Categorie TEXT DEFAULT 'autre', Taille INTEGER DEFAULT 0, Donnees TEXT, DateAjout TEXT, AjoutePar TEXT)",
        "CREATE TABLE IF NOT EXISTS HistoriqueSuppression (Id $AI, TableSource TEXT, IdOriginal INTEGER, Numero TEXT, Description TEXT, DateSuppression TEXT, SupprimeParLogin TEXT)",
        "CREATE TABLE IF NOT EXISTS NotesInfo (Id $AI, Message TEXT NOT NULL, Actif INTEGER DEFAULT 1, DateCreation TEXT, CreePar TEXT)",
    ];
}

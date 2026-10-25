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

/**
 * Qui peut migrer la base.
 *
 * ⚠️ UN ADMINISTRATEUR DE TENANT RÉÉCRIVAIT LA CONFIGURATION DU SERVEUR.
 * « config_serveur » refuse les admins de tenant en multi-tenant — mais
 * « db_migrate » réécrivait lui aussi config.json (section base_de_donnees,
 * commune à tout le serveur) et « db_migrate_test » ouvrait une connexion vers
 * n'importe quel hôte. Même règle que config_serveur et les mises à jour :
 *   • Super Administrateur : toujours ;
 *   • Admin / Gestionnaire : en mono-tenant seulement.
 */
if (!function_exists('_mig_exiger_droits')) {
    function _mig_exiger_droits(): array {
        if (!empty($_SESSION['superadmin']['authenticated'])) {
            return ['Login' => 'superadmin:' . ($_SESSION['superadmin']['login'] ?? '?'), 'Role' => 'SuperAdmin', 'Id' => 0];
        }
        $user = require_auth();
        require_role($user, ['Admin','Gestionnaire']);
        if (class_exists('TenantResolver', false) && TenantResolver::isMultiTenant()) {
            refuser_acces($user, 'db_migrate_reserve_superadmin',
                'En mode multi-tenant, la migration de base est réservée au Super Administrateur.');
        }
        return $user;
    }
}

// ── Test de connexion seul ─────────────────────────────────────────────────
if ($action === 'db_migrate_test') {
    $user = _mig_exiger_droits();
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
    $user = _mig_exiger_droits();
    if ($method !== 'POST') json_error('Méthode non supportée.', 405);

    $body       = get_body();
    $dest       = $body['destination']   ?? null;
    $backupPath = $body['backup_path']   ?? null; // nom de fichier souhaité (confiné, voir _mig_backup)
    if (!$dest || empty($dest['driver'])) json_error('Paramètre destination manquant.', 400);
    if (!in_array($dest['driver'], ['sqlite', 'pgsql', 'mariadb', 'mysql'], true)) {
        json_error('Driver de destination inconnu (sqlite, pgsql ou mariadb).', 400);
    }

    // ⚠️ DESTINATION = SOURCE : LA MIGRATION VIDAIT LA BASE EN SERVICE.
    // Rien n'empêchait de désigner comme destination la base même que l'on
    // migre (même fichier SQLite — le chemin proposé par défaut est celui de
    // la base principale —, ou même base PostgreSQL). L'étape « vider la
    // destination » effaçait alors toutes les tables de la source ; la copie
    // ne trouvait plus rien, et la configuration basculait sur une base vide.
    if (_mig_memeBase($dest)) {
        json_error('La destination désigne la base actuellement en service : '
            . 'choisissez une autre base (ou un autre fichier SQLite).', 400);
    }

    // Une base de plusieurs centaines de Mo ne se copie pas en 30 secondes.
    @set_time_limit(0);

    // ── Ordre de migration ───────────────────────────────────────────────
    // Les tables du cœur d'abord, dans l'ordre historique ; toutes les autres
    // tables de la source suivent (étape 3).
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
    try {
        $test = _mig_makePdo($dest);
        $test->query('SELECT 1');
        $test = null;
        $report[] = ['table'=>'🔌 Connexion destination','rows'=>0,'status'=>'ok',
            'detail'=> strtoupper($dest['driver']) . ' joignable'];
    } catch (\Exception $e) {
        // Supprimer la sauvegarde si elle a été créée
        if ($savedAt && file_exists($savedAt)) unlink($savedAt);
        $diag = _mig_diagnose($dest, $e->getMessage());
        json_error("Connexion destination impossible — sauvegarde supprimée.\n\n" . $diag, 503);
    }

    // ════════════════════════════════════════════════════════════════════
    // ÉTAPE 3 — Inventaire de la source
    // ════════════════════════════════════════════════════════════════════
    //
    // ⚠️ LA LISTE DES TABLES ÉTAIT ÉCRITE EN DUR — ET NE SUIVAIT PLUS LE SCHÉMA.
    // Seize tables du cœur, puis celles des modules. Tout ce qui est venu
    // ensuite restait derrière, sans un mot : factures et leur ventilation,
    // lignes de devis, archives (boîtes, dossiers, bordereaux), plans
    // interactifs, déclarations d'inventaire, mobilité, facteurs carbone,
    // réseaux de chaleur, journal… Le rapport affichait « Migration réussie »,
    // config.json basculait sur la nouvelle base, et ces données n'existaient
    // plus que dans l'ancienne. On énumère donc le schéma RÉEL de la source.
    $srcPdo = null;
    $nomsSrc = [];   // nom en minuscules => nom réel dans la source
    try {
        $srcPdo = _mig_makeSrcPdo();
        foreach (_mig_listerTables($srcPdo) as $t) $nomsSrc[strtolower($t)] = $t;
        $connues = array_map('strtolower', $TABLES);
        foreach ($nomsSrc as $l => $t) {
            if (!in_array($l, $connues, true)) { $TABLES[] = $t; $connues[] = $l; }
        }
    } catch (\Exception $e) {
        if ($savedAt && file_exists($savedAt)) unlink($savedAt);
        json_error('Connexion source impossible : ' . $e->getMessage(), 500);
    }

    $destDriver  = $dest['driver'];
    $isPgDest    = ($destDriver === 'pgsql');
    $isMysqlDest = ($destDriver === 'mariadb' || $destDriver === 'mysql');

    // ════════════════════════════════════════════════════════════════════
    // ÉTAPE 4 — Structure de la destination
    // ════════════════════════════════════════════════════════════════════
    //
    // ⚠️ LA STRUCTURE ÉTAIT RECOPIÉE ICI, À LA MAIN, FIGÉE À UNE VERSION ANCIENNE.
    // Seize CREATE TABLE où manquaient les colonnes apparues depuis : sur une
    // destination vierge, la première ligne portant une colonne récente faisait
    // échouer la migration. Le schéma vient désormais de Database — la même
    // source de vérité que l'application, sans aucune donnée par défaut —, puis
    // on complète, d'après la source, ce que Database ne crée pas (tables des
    // modules, journal, colonnes ajoutées par un module).
    $destPdo = null;
    $nomsDest = [];   // nom réel source => nom réel destination
    try {
        $dbDest = new Database(_mig_configDatabase($dest), true);
        $dbDest->ensureFullSchemaWithoutSeeds();
        $destPdo = $dbDest->getPdo();
        $tablesACreer = array_values(array_filter(array_values($nomsSrc), '_mig_nomValide'));
        $structure = _mig_completerStructure($srcPdo, $destPdo, $tablesACreer);
        $nomsDest  = $structure['noms'];
        $detail = 'Schéma Larka créé/vérifié';
        if ($structure['creees'])   $detail .= ' ; tables reprises de la source : ' . implode(', ', $structure['creees']);
        if ($structure['colonnes']) $detail .= ' ; colonnes ajoutées : ' . implode(', ', $structure['colonnes']);
        $report[] = ['table'=>'🏗️ Structure','rows'=>0,'status'=>'ok','detail'=>$detail];
    } catch (\Throwable $e) {
        if ($savedAt && file_exists($savedAt)) unlink($savedAt);
        json_error('Erreur création tables : ' . $e->getMessage(), 500);
    }

    // Vider la destination (tables concernées uniquement)
    _mig_truncate($destPdo, array_values(array_unique($nomsDest)), $isPgDest, $isMysqlDest);
    $report[] = ['table'=>'🗑️ Nettoyage','rows'=>0,'status'=>'ok','detail'=>'Destination vidée'];

    // ⚠️ « SET session_replication_role » EST RÉSERVÉ AU SUPERUTILISATEUR.
    // Sur l'installation recommandée — un rôle PostgreSQL dédié, propriétaire de
    // sa base mais sans privilège superutilisateur —, l'ordre levait « permission
    // denied to set parameter », non rattrapé : la migration vers PostgreSQL
    // échouait systématiquement. Le schéma de Larka ne déclare aucune clé
    // étrangère : la désactivation n'est qu'une précaution, tentée sans exiger.
    if ($isPgDest)    { try { $destPdo->exec("SET session_replication_role = replica"); } catch (\Throwable $e) {} }
    if ($isMysqlDest) { try { $destPdo->exec("SET FOREIGN_KEY_CHECKS = 0"); } catch (\Throwable $e) {} }
    if (!$isPgDest && !$isMysqlDest) $destPdo->exec("PRAGMA foreign_keys = OFF");

    // ════════════════════════════════════════════════════════════════════
    // ÉTAPE 5 — Copie des données
    // ════════════════════════════════════════════════════════════════════
    $dialSrc = $srcPdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $dialDst = $destPdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    // SQLite range n'importe quelle valeur dans n'importe quelle colonne :
    // PostgreSQL et MariaDB refusent '' ou « n/a » dans une colonne numérique.
    $strict  = ($dialDst !== 'sqlite');

    $errors = [];
    foreach ($TABLES as $table) {
        $reelSrc = $nomsSrc[strtolower($table)] ?? null;
        if ($reelSrc === null) {
            $report[] = ['table'=>$table,'rows'=>0,'status'=>'skip','detail'=>'Absente en source'];
            continue;
        }
        if (!_mig_nomValide($reelSrc) || !isset($nomsDest[$reelSrc])) {
            $report[] = ['table'=>$reelSrc,'rows'=>0,'status'=>'skip',
                'detail'=>'Nom de table non pris en charge — non migrée'];
            continue;
        }
        $reelDst = $nomsDest[$reelSrc];

        try {
            // Colonnes de la destination : nom réel et famille de type.
            $colsDst = [];
            foreach (_mig_colonnes($destPdo, $reelDst)['colonnes'] as $n => $fam) {
                $colsDst[strtolower($n)] = [$n, $fam];
            }

            $lecture  = $srcPdo->query('SELECT * FROM ' . _mig_q($dialSrc, $reelSrc));
            $total    = 0;
            $inserted = 0;
            $alteres  = 0;
            $insert   = null;
            $cles     = [];
            $familles = [];
            $lot      = 0;

            $destPdo->beginTransaction();
            while (($row = $lecture->fetch(PDO::FETCH_ASSOC)) !== false) {
                if ($insert === null) {
                    $noms = [];
                    $absentes = [];
                    foreach (array_keys($row) as $k) {
                        $m = $colsDst[strtolower((string)$k)] ?? null;
                        if ($m === null) { $absentes[] = $k; continue; }
                        $cles[]     = $k;
                        $noms[]     = _mig_q($dialDst, $m[0]);
                        $familles[] = $m[1];
                    }
                    if ($absentes) {
                        throw new \RuntimeException('colonne(s) absente(s) de la destination : ' . implode(', ', $absentes));
                    }
                    $colList = implode(', ', $noms);
                    $params  = implode(', ', array_fill(0, count($noms), '?'));
                    $cible   = _mig_q($dialDst, $reelDst);
                    $sql = match ($dialDst) {
                        'pgsql' => "INSERT INTO $cible ($colList) OVERRIDING SYSTEM VALUE VALUES ($params) ON CONFLICT DO NOTHING",
                        'mysql' => "INSERT IGNORE INTO $cible ($colList) VALUES ($params)",
                        default => "INSERT OR IGNORE INTO $cible ($colList) VALUES ($params)",
                    };
                    $insert = $destPdo->prepare($sql);
                }

                $valeurs = [];
                foreach ($cles as $i => $k) {
                    $v = $row[$k];
                    if (is_bool($v)) $v = $v ? 1 : 0;
                    $valeurs[] = $strict ? _mig_valeur($v, $familles[$i], $alteres) : $v;
                }
                $insert->execute($valeurs);
                $inserted += $insert->rowCount();
                $total++;

                if (++$lot >= 500) {
                    $destPdo->commit();
                    $destPdo->beginTransaction();
                    $lot = 0;
                }
            }
            $destPdo->commit();
            $lecture = null;

            if ($total === 0) {
                $report[] = ['table'=>$reelSrc,'rows'=>0,'status'=>'ok','detail'=>'Vide'];
                continue;
            }

            // Lignes ignorées : clé déjà présente (contrainte d'unicité plus
            // stricte à destination). Rare — mais signalé plutôt que passé sous silence.
            $ignorees = $total - $inserted;
            $detail = "$inserted ligne(s)";
            if ($ignorees > 0) $detail .= " — $ignorees ignorée(s) : clé déjà présente à destination";
            if ($alteres > 0)  $detail .= " — $alteres valeur(s) non numérique(s) remplacée(s) par NULL ou arrondie(s)";
            $report[] = ['table'=>$reelSrc,'rows'=>$inserted,
                'status'=>($ignorees > 0 || $alteres > 0) ? 'warn' : 'ok', 'detail'=>$detail];

        } catch (\Throwable $e) {
            if ($destPdo->inTransaction()) { try { $destPdo->rollBack(); } catch (\Throwable $_) {} }
            $errors[] = "$reelSrc : " . $e->getMessage();
            $report[] = ['table'=>$reelSrc,'rows'=>0,'status'=>'error','detail'=>$e->getMessage()];
        }
    }

    // Réactiver FK
    if ($isPgDest)    { try { $destPdo->exec("SET session_replication_role = DEFAULT"); } catch (\Throwable $e) {} }
    if ($isMysqlDest) { try { $destPdo->exec("SET FOREIGN_KEY_CHECKS = 1"); } catch (\Throwable $e) {} }
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
        foreach (array_unique(array_values($nomsDest)) as $reelDst) {
            try {
                $pk = _mig_colonnes($destPdo, $reelDst)['pk'];
                if ($pk === null) continue;
                $seq = $destPdo->query('SELECT pg_get_serial_sequence('
                    . $destPdo->quote(_mig_q('pgsql', $reelDst)) . ', ' . $destPdo->quote($pk) . ')')->fetchColumn();
                if ($seq) {
                    $c = _mig_q('pgsql', $pk);
                    $destPdo->query('SELECT setval(' . $destPdo->quote($seq) . ", COALESCE(MAX($c), 1), MAX($c) IS NOT NULL) FROM "
                        . _mig_q('pgsql', $reelDst));
                    $synced[] = $reelDst;
                }
            } catch (\Exception $e) {}
        }
        if ($synced) {
            $report[] = ['table'=>'🔄 Séquences PG','rows'=>count($synced),'status'=>'ok',
                'detail'=>'Resynchronisées : ' . implode(', ', $synced)];
        }
    }

    // ════════════════════════════════════════════════════════════════════
    // ÉTAPE 6 — Mise à jour config.json avec le nouveau driver
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
        // ⚠️ LE MOT DE PASSE ÉTAIT ÉCRIT EN CLAIR DANS config.json.
        // Deux défauts : (1) contraire à la règle « les secrets vivent dans
        // .env » ; (2) bug fonctionnel — .env PRIME sur config.json : si
        // GMAO_DB_PASSWORD y était déjà défini, le nouveau mot de passe était
        // ignoré et l'application, redémarrée sur le nouvel hôte, n'arrivait
        // plus à se connecter. Il est désormais rangé dans .env.
        if (!empty($dest['password']) && !str_contains($dest['password'], '••••')) {
            require_once __DIR__ . '/../env.php';
            require_once __DIR__ . '/../EnvFile.php';
            $envNom = EnvFile::envNameFor('base_de_donnees', 'password') ?? 'GMAO_DB_PASSWORD';
            if (EnvFile::isManagedByOsEnv($envNom)) {
                json_error("$envNom est défini par l'environnement système : mettez-le à jour côté serveur, puis relancez la migration.", 409);
            }
            try {
                EnvFile::set([$envNom => (string)$dest['password']]);
            } catch (\Throwable $e) {
                Journal::erreur('migration', 'Écriture du mot de passe dans .env impossible', ['exception' => $e]);
                json_error('Impossible d\'enregistrer le mot de passe dans .env (droits du fichier ?). config.json n\'a pas été modifié.', 500);
            }
            // Le secret vit maintenant dans .env : aucune copie dans config.json.
            // (Sans nouveau mot de passe, on ne touche à rien : un mot de passe
            // hérité, encore rangé dans config.json, reste utilisable.)
            unset($cfg['base_de_donnees']['password']);
        }
        if ($isPgDest) {
            $cfg['base_de_donnees']['sslmode'] = $dest['sslmode'] ?? 'prefer';
        }
    }
    file_put_contents($cfgFile, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    @chmod($cfgFile, 0600);
    if (class_exists('SecurityLog')) {
        SecurityLog::audit('config_change', [
            'login'  => (string)($user['Login'] ?? ''),
            'role'   => (string)($user['Role'] ?? ''),
            'action' => 'db_migrate',
            'reason' => 'base_de_donnees -> ' . $destDriver,
        ]);
    }

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

/** Chemin absolu d'un fichier SQLite de destination (relatif = racine de l'application). */
function _mig_cheminSqlite(string $path): string {
    if ($path === '') $path = 'data/gmao.db';
    if (!larka_chemin_absolu($path)) {
        $path = __DIR__ . '/../../' . $path;
    }
    return $path;
}

/** Configuration au format attendu par Database pour la destination. */
function _mig_configDatabase(array $d): array {
    $drv = (string)$d['driver'];
    if ($drv === 'sqlite') {
        return ['driver' => 'sqlite', 'path' => _mig_cheminSqlite((string)($d['path'] ?? ''))];
    }
    return [
        'driver'   => $drv,
        'host'     => $d['host'] ?? '127.0.0.1',
        'port'     => (int)($d['port'] ?? ($drv === 'pgsql' ? 5432 : 3306)),
        'dbname'   => $d['dbname'] ?? 'gmao',
        'user'     => $d['user'] ?? '',
        'password' => $d['password'] ?? '',
        'sslmode'  => $d['sslmode'] ?? 'prefer',
    ];
}

/** La destination désigne-t-elle la base actuellement en service ? */
function _mig_memeBase(array $d): bool {
    $norm = fn(string $drv) => $drv === 'mysql' ? 'mariadb' : $drv;
    $drv = $norm(strtolower((string)($d['driver'] ?? '')));
    if ($drv !== $norm(DB_DRIVER)) return false;
    if ($drv === 'sqlite') {
        $a = realpath(DB_PATH) ?: DB_PATH;
        $p = _mig_cheminSqlite((string)($d['path'] ?? ''));
        $b = realpath($p) ?: $p;
        return $a === $b;
    }
    $hote = function ($h): string {
        $h = strtolower(trim((string)$h));
        return in_array($h, ['', 'localhost', '::1', '127.0.0.1'], true) ? '127.0.0.1' : $h;
    };
    $port = (int)($d['port'] ?? 0);
    if ($port === 0) $port = ($drv === 'pgsql') ? 5432 : 3306;
    return $hote($d['host'] ?? '') === $hote(DB_HOST)
        && $port === (int)DB_PORT
        && strtolower(trim((string)($d['dbname'] ?? ''))) === strtolower((string)DB_NAME);
}

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
        $path = _mig_cheminSqlite((string)($d['path'] ?? ''));
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
        // ⚠️ LE CHEMIN VENAIT DU CLIENT, SANS AUCUNE BORNE.
        // copy(DB_PATH, <chemin reçu>) pouvait écrire n'importe où sur le disque
        // accessible au serveur — y compris PAR-DESSUS config.json, .env ou le
        // code de l'application. Seul le NOM est désormais retenu, réduit à un
        // jeu de caractères sûr, et la copie atterrit toujours dans data/backups.
        $dir  = __DIR__ . '/../../data/backups';
        $nom  = $customPath ? basename(str_replace('\\', '/', (string)$customPath)) : '';
        $nom  = preg_replace('/[^A-Za-z0-9._-]/', '_', $nom);
        $auto = ($nom === '' || $nom[0] === '.' || !str_ends_with(strtolower($nom), '.db'));
        if ($auto) $nom = 'pre_migration_' . date('Ymd_His') . '.db';
        if (!is_dir($dir)) mkdir($dir, 0750, true);
        // Nom choisi par l'utilisateur : on ne l'écrase pas. Nom automatique :
        // deux migrations dans la même seconde ne doivent pas se bloquer.
        for ($n = 2; $auto && file_exists($dir . '/' . $nom) && $n < 100; $n++) {
            $nom = 'pre_migration_' . date('Ymd_His') . "_$n.db";
        }
        $customPath = $dir . '/' . $nom;
        if (file_exists($customPath)) {
            json_error('Une sauvegarde porte déjà ce nom : choisissez-en un autre.', 409);
        }
        if (!copy(DB_PATH, $customPath)) {
            json_error("Impossible de créer la sauvegarde dans data/backups/$nom — vérifiez les permissions.", 500);
        }
        @chmod($customPath, 0640);
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
    $dial = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $rev = array_reverse($tables);
    if ($isPg) {
        // Réservé au superutilisateur : tenté, jamais exigé (voir db_migrate).
        try { $pdo->exec("SET session_replication_role = replica"); } catch (\Throwable $e) {}
        foreach ($rev as $t) { try { $pdo->exec('TRUNCATE TABLE ' . _mig_q($dial, $t) . ' CASCADE'); } catch(\Exception $e){} }
        try { $pdo->exec("SET session_replication_role = DEFAULT"); } catch (\Throwable $e) {}
    } elseif ($isMysql) {
        try { $pdo->exec("SET FOREIGN_KEY_CHECKS = 0"); } catch (\Throwable $e) {}
        foreach ($rev as $t) { try { $pdo->exec('TRUNCATE TABLE ' . _mig_q($dial, $t)); } catch(\Exception $e){} }
        try { $pdo->exec("SET FOREIGN_KEY_CHECKS = 1"); } catch (\Throwable $e) {}
    } else {
        $pdo->exec("PRAGMA foreign_keys = OFF");
        foreach ($rev as $t) { try { $pdo->exec('DELETE FROM ' . _mig_q($dial, $t)); } catch(\Exception $e){} }
        $pdo->exec("PRAGMA foreign_keys = ON");
    }
}

/** Identifiant cité selon le dialecte (« sqlite », « pgsql », « mysql »). */
function _mig_q(string $dialecte, string $nom): string {
    return $dialecte === 'mysql'
        ? '`' . str_replace('`', '``', $nom) . '`'
        : '"' . str_replace('"', '""', $nom) . '"';
}

/** Nom de table ou de colonne tel que Larka les crée (lettres, chiffres, « _ »). */
function _mig_nomValide(string $nom): bool {
    return (bool)preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/', $nom);
}

/**
 * Tables réelles d'une base, sans les tables internes du moteur (sqlite_*)
 * ni le registre super admin (sa_*), qui vit dans sa propre base et n'a rien à
 * faire dans celle d'un client.
 *
 * @return string[]
 */
function _mig_listerTables(PDO $pdo): array {
    $sql = match ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) {
        'sqlite' => "SELECT name FROM sqlite_master WHERE type='table' ORDER BY name",
        'pgsql'  => "SELECT tablename FROM pg_tables WHERE schemaname = current_schema() ORDER BY tablename",
        default  => "SELECT TABLE_NAME AS n FROM information_schema.TABLES "
                  . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME",
    };
    $out = [];
    foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $t = (string)$t;
        $l = strtolower($t);
        if (str_starts_with($l, 'sqlite_') || str_starts_with($l, 'sa_')) continue;
        $out[] = $t;
    }
    return $out;
}

/**
 * Colonnes d'une table, ramenées à trois familles (int, real, text) qui
 * suffisent au schéma de Larka, et colonne auto-incrémentée éventuelle.
 *
 * @return array{colonnes: array<string,string>, pk: ?string}
 */
function _mig_colonnes(PDO $pdo, string $table): array {
    $dial = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $cols = [];
    $pk   = null;
    if ($dial === 'sqlite') {
        $pks = [];
        foreach ($pdo->query('PRAGMA table_info(' . _mig_q('sqlite', $table) . ')')->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $n = (string)$c['name'];
            $cols[$n] = _mig_familleSqlite((string)($c['type'] ?? ''));
            if ((int)($c['pk'] ?? 0) > 0) $pks[] = $n;
        }
        // Seule une clé primaire ENTIÈRE et UNIQUE est un compteur (rowid).
        if (count($pks) === 1 && $cols[$pks[0]] === 'int') $pk = $pks[0];
    } elseif ($dial === 'pgsql') {
        $st = $pdo->prepare("SELECT column_name AS n, data_type AS t, column_default AS d, is_identity AS i
                               FROM information_schema.columns
                              WHERE table_schema = current_schema() AND table_name = ?
                              ORDER BY ordinal_position");
        $st->execute([$table]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $n = (string)$c['n'];
            $cols[$n] = _mig_famillePg((string)$c['t']);
            if ($pk === null && (str_starts_with((string)($c['d'] ?? ''), 'nextval(') || ($c['i'] ?? '') === 'YES')) $pk = $n;
        }
    } else {
        $st = $pdo->prepare("SELECT COLUMN_NAME AS n, DATA_TYPE AS t, EXTRA AS e
                               FROM information_schema.COLUMNS
                              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                              ORDER BY ORDINAL_POSITION");
        $st->execute([$table]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $n = (string)$c['n'];
            $cols[$n] = _mig_familleMysql((string)$c['t']);
            if ($pk === null && str_contains(strtolower((string)($c['e'] ?? '')), 'auto_increment')) $pk = $n;
        }
    }
    return ['colonnes' => $cols, 'pk' => $pk];
}

/** Famille d'un type déclaré SQLite (règles d'affinité de SQLite). */
function _mig_familleSqlite(string $type): string {
    $t = strtoupper($type);
    if (str_contains($t, 'INT')) return 'int';
    if (str_contains($t, 'CHAR') || str_contains($t, 'CLOB') || str_contains($t, 'TEXT')) return 'text';
    if ($t === '' || str_contains($t, 'BLOB')) return 'text';   // sans type : contenu libre, gardé en texte
    if (str_contains($t, 'REAL') || str_contains($t, 'FLOA') || str_contains($t, 'DOUB')) return 'real';
    if (str_contains($t, 'BOOL')) return 'int';
    if (str_contains($t, 'NUMERIC') || str_contains($t, 'DECIMAL')) return 'real';
    return 'text';   // DATE, DATETIME… : Larka y range des chaînes ISO
}

function _mig_famillePg(string $type): string {
    $t = strtolower($type);
    if (in_array($t, ['smallint', 'integer', 'bigint', 'boolean'], true)) return 'int';
    if (in_array($t, ['real', 'double precision', 'numeric'], true)) return 'real';
    return 'text';
}

function _mig_familleMysql(string $type): string {
    $t = strtolower($type);
    if (in_array($t, ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'bit'], true)) return 'int';
    if (in_array($t, ['float', 'double', 'decimal', 'real'], true)) return 'real';
    return 'text';
}

/** Type SQL d'une famille dans un dialecte. */
function _mig_typeSql(string $famille, string $dialecte): string {
    return match ($dialecte) {
        'pgsql' => match ($famille) { 'int' => 'BIGINT', 'real' => 'DOUBLE PRECISION', default => 'TEXT' },
        'mysql' => match ($famille) { 'int' => 'BIGINT', 'real' => 'DOUBLE',           default => 'LONGTEXT' },
        default => match ($famille) { 'int' => 'INTEGER', 'real' => 'REAL',            default => 'TEXT' },
    };
}

/** Tables de Larka, avec la casse sous laquelle l'application les crée. */
function _mig_tablesConnues(): array {
    return [
        'Configuration', 'Listes', 'Utilisateurs', 'Biens', 'Equipements', 'Contrats', 'Stock',
        'Interventions', 'DemandesIntervention', 'IntervStockLignes', 'IntervDevisLignes',
        'GestionMateriel', 'CompteurEnergie', 'ReleverEnergie', 'Documents', 'HistoriqueSuppression',
        'NotesInfo', 'Factures', 'FactureInterventions', 'ArchivesBoite', 'ArchivesDossier',
        'ArchivesBordereau', 'PushSubscriptions', 'DeclarationsInventaire', 'MobiliteCarbone',
        'FacteurCarboneOfficiel', 'FacteurCarboneSimple', 'FacteurMobilite', 'ReseauChaleur',
        'PlanBatiments', 'PlanEtages', 'PlanElements', 'PlanLiens', 'Journal',
    ];
}

/**
 * Nom à donner, dans la destination, à une table de la source.
 *
 * PostgreSQL range les noms non cités en minuscules ; MariaDB, sous Linux,
 * distingue la casse. Une table venue de PostgreSQL (« planbatiments ») doit
 * donc retrouver, dans MariaDB, la casse sous laquelle l'application la lit.
 */
function _mig_nomDestination(string $source, array $existantes, string $dialecte): string {
    $l = strtolower($source);
    foreach ($existantes as $t) {
        if (strtolower((string)$t) === $l) return (string)$t;
    }
    if ($dialecte === 'pgsql') return $l;
    foreach (_mig_tablesConnues() as $t) {
        if (strtolower($t) === $l) return $t;
    }
    return $source;
}

/**
 * Complète la destination d'après la source : crée les tables qu'elle n'a pas
 * (modules, journal…) et ajoute les colonnes qui lui manquent.
 *
 * @param string[] $tablesSrc noms réels dans la source
 * @return array{noms: array<string,string>, creees: string[], colonnes: string[]}
 */
function _mig_completerStructure(PDO $src, PDO $dst, array $tablesSrc): array {
    $dd = $dst->getAttribute(PDO::ATTR_DRIVER_NAME);
    $existantes = _mig_listerTables($dst);
    $noms = [];
    $creees = [];
    $ajoutees = [];

    foreach ($tablesSrc as $ts) {
        $td = _mig_nomDestination($ts, $existantes, $dd);
        $noms[$ts] = $td;
        $infoSrc = _mig_colonnes($src, $ts);
        $colsSrc = array_filter($infoSrc['colonnes'], fn($c) => _mig_nomValide((string)$c), ARRAY_FILTER_USE_KEY);
        if ($colsSrc === []) continue;

        $presente = false;
        foreach ($existantes as $e) {
            if (strtolower((string)$e) === strtolower($td)) { $presente = true; break; }
        }

        if (!$presente) {
            $defs = [];
            foreach ($colsSrc as $col => $fam) {
                $c = _mig_q($dd, $dd === 'pgsql' ? strtolower($col) : $col);
                if ($col === $infoSrc['pk']) {
                    $defs[] = $c . ' ' . match ($dd) {
                        'pgsql' => 'SERIAL PRIMARY KEY',
                        'mysql' => 'INT AUTO_INCREMENT PRIMARY KEY',
                        default => 'INTEGER PRIMARY KEY AUTOINCREMENT',
                    };
                } else {
                    $defs[] = $c . ' ' . _mig_typeSql($fam, $dd);
                }
            }
            $dst->exec('CREATE TABLE IF NOT EXISTS ' . _mig_q($dd, $td) . ' (' . implode(', ', $defs) . ')');
            $existantes[] = $td;
            $creees[] = $td;
            continue;
        }

        $dejaLa = array_map('strtolower', array_keys(_mig_colonnes($dst, $td)['colonnes']));
        foreach ($colsSrc as $col => $fam) {
            if (in_array(strtolower($col), $dejaLa, true)) continue;
            $c = $dd === 'pgsql' ? strtolower($col) : $col;
            $dst->exec('ALTER TABLE ' . _mig_q($dd, $td) . ' ADD COLUMN ' . _mig_q($dd, $c) . ' ' . _mig_typeSql($fam, $dd));
            $ajoutees[] = $td . '.' . $c;
        }
    }
    return ['noms' => $noms, 'creees' => $creees, 'colonnes' => $ajoutees];
}

/**
 * Valeur prête pour une colonne de PostgreSQL ou MariaDB. SQLite accepte tout,
 * partout : une chaîne vide ou « n/a » dans une colonne numérique y est
 * légale, mais ferait échouer l'insertion ailleurs — et avec elle la migration.
 */
function _mig_valeur($v, string $famille, int &$alteres) {
    if ($v === null || $famille === 'text') return $v;
    if (is_int($v)) return $v;
    if (is_float($v)) {
        if ($famille === 'int' && floor($v) != $v) { $alteres++; return (int)round($v); }
        return $famille === 'int' ? (int)$v : $v;
    }
    $s = trim((string)$v);
    if ($s === '') return null;                       // vide : aucune valeur
    if (!is_numeric($s)) { $alteres++; return null; } // « n/a », « 12 € »…
    if ($famille === 'int') {
        if (preg_match('/^[+-]?\d+$/', $s)) return $s;   // entier exact, même au-delà de 2^53
        $f = (float)$s;
        if (floor($f) != $f) $alteres++;
        return (int)round($f);
    }
    return $s;
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

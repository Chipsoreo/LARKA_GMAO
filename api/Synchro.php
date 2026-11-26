<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka — Synchronisation avec l'app mobile (Larka hors ligne).
 *
 * L'app travaille sur sa propre copie de l'établissement et se synchronise dès
 * que le serveur est joignable :
 *
 *   • RELIER    l'utilisateur saisit l'adresse du serveur et son compte ; le
 *               serveur enregistre l'appareil (SynchroAppareils) et lui remet un
 *               jeton (« LKS.<organisation>.<appareil>.<48 hex> »), dont seule
 *               l'empreinte est gardée.
 *   • CLICHÉ    l'app télécharge toutes les tables (copie complète).
 *   • TIRER     l'app reçoit ce qui a changé depuis son dernier passage. Chaque
 *               écriture en base est notée dans SynchroJournal par la connexion
 *               PDO elle-même (SynchroPdo) : aucune route n'a à y penser. Une
 *               écriture dont la ligne n'est pas identifiable (UPDATE … WHERE
 *               autre chose que Id) marque la table entière ; l'app compare
 *               alors les empreintes de ses lignes à celles du serveur.
 *   • POUSSER   l'app envoie ses modifications : pour chaque ligne, l'état
 *               qu'elle avait vu (« avant ») et le nouveau (« après »). Le
 *               serveur n'applique que les champs changés par l'app ; si l'un
 *               d'eux a aussi changé sur le serveur, autrement, c'est un CONFLIT,
 *               rendu à l'app où l'utilisateur choisit. Les quantités en stock se
 *               fusionnent par différence.
 *   • RÉSERVER  les nouvelles fiches créées hors ligne ont déjà leur identifiant
 *               définitif : le serveur réserve à chaque appareil des plages
 *               d'identifiants par table (séquence avancée d'autant), que ses
 *               propres insertions sautent. Rien à renuméroter au retour.
 *   • FICHIERS  plans, médias d'urgence, thèmes, modules : inventaire, envoi,
 *               réception.
 *
 * Droits : l'app applique déjà les règles de Larka (c'est le même code) ;
 * le serveur les rappelle table par table (voir droitsEcriture) pour qu'un
 * jeton dérobé ne vaille pas plus que le rôle de son compte.
 */

declare(strict_types=1);

/**
 * Connexion PDO qui note les écritures pour la synchronisation.
 *
 * Les notes (SynchroJournal) sont écrites AU DÉBUT de l'instruction suivante sur
 * la même connexion, et en fin de requête — jamais juste après l'écriture : une
 * insertion de plus déplacerait lastInsertId(), que Larka lit juste après ses
 * INSERT. Même connexion, même transaction : une écriture annulée l'est avec sa note.
 */
final class SynchroPdo extends PDO
{
    /** Notes en attente : [table, clé|null, appareil|null]. */
    public array $notes = [];

    public function __construct(string $dsn, ?string $user = null, ?string $pass = null, ?array $options = null)
    {
        parent::__construct($dsn, $user, $pass, $options ?? []);
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [SynchroInstruction::class, [$this]]);
        register_shutdown_function(function (): void { try { Synchro::vider($this); } catch (\Throwable $e) { /* best-effort */ } });
    }

    #[\ReturnTypeWillChange]
    public function prepare($query, $options = [])
    {
        if ($this->notes) Synchro::vider($this);
        return parent::prepare($query, $options);
    }

    #[\ReturnTypeWillChange]
    public function query($query, $fetchMode = null, ...$args)
    {
        if ($this->notes) Synchro::vider($this);
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$args);
    }

    #[\ReturnTypeWillChange]
    public function exec($sql)
    {
        if ($this->notes) Synchro::vider($this);
        $n = parent::exec($sql);
        if ($n !== false) Synchro::noter($this, (string)$sql, []);
        return $n;
    }

    #[\ReturnTypeWillChange]
    public function commit()
    {
        if ($this->notes) Synchro::vider($this);
        return parent::commit();
    }
}

/** Instruction préparée qui note les écritures après exécution. */
class SynchroInstruction extends PDOStatement
{
    private array $lies = [];
    protected function __construct(public PDO $connexion) {}

    #[\ReturnTypeWillChange]
    public function bindValue($param, $value, $type = PDO::PARAM_STR)
    {
        $this->lies[$param] = $value;
        return parent::bindValue($param, $value, $type);
    }

    #[\ReturnTypeWillChange]
    public function execute($params = null)
    {
        $r = parent::execute($params);
        if ($r) Synchro::noter($this->connexion, $this->queryString, $params ?? $this->lies);
        return $r;
    }
}

final class Synchro
{
    public const PREFIXE = 'LKS';
    /** Identifiants provisoires de l'app (plage épuisée) : jamais envoyés comme identifiants. */
    public const PROVISOIRE = 1099511627776;   // 2^40
    /**
     * Tables jamais synchronisées (propres à chaque installation).
     *
     * Les tables du lien avec le serveur de supervision en font partie : elles
     * portent la clé privée du lien, la clé épinglée de l'autre serveur et son
     * adresse. Recopiées sur un téléphone, et surtout réécrites par lui, elles
     * permettaient de réappairer la supervision — l'écran l'exige avec une
     * identité confirmée (routes/supervision.php), la synchronisation ne
     * demandait rien. Aucun écran de l'app n'en a besoin.
     * (Les tables du registre super admin, « sa_… », sont écartées par tables()
     * si ce registre partage la base d'une organisation.)
     */
    public const EXCLUES = ['journal', 'sessions', 'pushsubscriptions', 'synchroappareils', 'synchrojournal', 'synchroreserves', 'synchroinvitations', 'sqlite_sequence', 'sqlite_stat1',
                            'supervisionlien', 'supervisionfile', 'supervisionsortants', 'supervisionvus'];
    /**
     * Tables que l'appareil lit mais ne réécrit jamais : les jetons des PC releveurs
     * (empreintes, mais créer une ligne revenait à se fabriquer un jeton valide).
     */
    public const LECTURE_SEULE = ['connectreleveurs'];
    /** Colonnes jamais livrées à un appareil, quel que soit le rôle : table (minuscules) → colonnes. */
    private const MASQUEES = [
        'utilisateurs'     => ['ResetToken', 'ResetTokenExpiry'],
        'connectreleveurs' => ['JetonHash'],
    ];
    /** Colonnes masquées aux rôles qui ne configurent pas Larka Connect (secrets chiffrés, état de session radio). */
    private const MASQUEES_NON_GERANT = [
        'connectpasserelles' => ['Secret'],
        'connectlorawan'     => ['Session', 'DevNonces'],
    ];
    /**
     * Colonnes du compte d'AUTRUI livrées à un rôle qui n'est pas gestionnaire :
     * celles de l'annuaire (Database::getAnnuaireUsers), plus l'identifiant et le
     * rôle (noms affichés dans les fiches). Le reste — droits ouverts, fournisseur,
     * mot de passe à changer, responsable… — reste sur le serveur.
     */
    private const COLONNES_ANNUAIRE = ['Id', 'Nom', 'Prenom', 'Login', 'Role', 'Service', 'Poste', 'Email', 'Tel', 'TelPro', 'TelMobile',
                                       'OfficeLocation', 'CompanyName', 'PhotoUrl', 'PartagePresencePlan', 'MasquerPresence', 'Actif'];
    /**
     * Réglages (table Configuration) livrés à un rôle qui n'est pas gestionnaire :
     * ceux que config_check ouvre à tous, et ceux qui servent à l'affichage
     * (champs personnalisés, réglages des listes, icônes choisies, mots de présence).
     * Les autres — sécurité, domaines, réseaux de bureau, RGPD… — restent sur le serveur.
     */
    private const CONFIG_PUBLIQUE = ['inventaire_agent_actif', 'modules_disabled', 'urgences_actif', 'urgences_config', 'plans_demandeur_actif',
                                     'max_photos_demande', 'items_par_page', 'bandeau_vitesse', 'icones_choisies',
                                     'presence_mots_teletravail', 'presence_mots_absence'];
    private const CONFIG_PUBLIQUE_PREFIXES = ['champs_perso_', 'liste_cfg_'];
    /**
     * Onglets ouverts nominativement à un demandeur (Utilisateurs.AccesLecture) →
     * tables qu'ils consultent, comme les routes du web (require_lecture).
     */
    private const TABLES_DES_MODULES = [
        'biens'            => ['biens'],
        'equipements'      => ['equipements'],
        'stock'            => ['stock'],
        'interventions'    => ['interventions', 'intervstocklignes', 'intervdevislignes', 'plansmaintenance'],
        'contrats'         => ['contrats'],
        'energie'          => ['compteurenergie', 'releverenergie', 'reseauchaleur'],
        'carbone'          => ['compteurenergie', 'releverenergie', 'reseauchaleur', 'mobilitecarbone'],
        'gestion_materiel' => ['gestionmateriel'],
    ];
    /**
     * Ce que le web ouvre en CONSULTATION au Visionneur et au Technicien
     * (require_role / require_lecture avec « Visionneur ») : tous les modules,
     * la supervision comprise — pas la gestion des comptes ni la configuration.
     */
    private const TABLES_VISIONNEUR = [
        'demandesintervention', 'biens', 'equipements', 'stock', 'interventions', 'intervstocklignes', 'intervdevislignes',
        'plansmaintenance', 'contrats', 'compteurenergie', 'releverenergie', 'reseauchaleur', 'gestionmateriel', 'factures',
        'factureinterventions', 'historiquesuppression', 'archivesboite', 'archivesbordereau', 'archivesdossier', 'mobilitecarbone',
        'planbatiments', 'planetages', 'planelements', 'planliens',
        'connectalarmes', 'connectbatiments', 'connectcommandes', 'connectexclusions', 'connectexecutions', 'connectfichiers',
        'connectliaisons', 'connectmesures', 'connectpasserelles', 'connectpoints', 'connectregles', 'connectsessionsve', 'connectlorawan',
    ];
    /** Tables lisibles par tous les comptes : valeurs des listes, bandeaux, facteurs d'émission (référentiels). */
    private const TABLES_COMMUNES = ['listes', 'notesinfo', 'facteurmobilite', 'facteurcarboneofficiel', 'facteurcarbonesimple', 'utilisateurs'];
    /** Types de documents (Documents.EntiteType) rattachés à une demande, et à une déclaration d'inventaire. */
    private const DOC_DEMANDE = ['Demande', 'DemandesIntervention'];
    private const DOC_INVENTAIRE = 'Inventaire';
    /** Types de documents qu'un Technicien dépose depuis l'app (fiches qu'il écrit par la synchronisation). */
    private const DOC_TECHNICIEN = ['Intervention', 'ReleverEnergie', 'Stock'];
    /**
     * Doublons probables : une fiche créée sur un appareil qui a le même identifiant qu'une fiche du
     * serveur que l'appareil ne connaissait pas (créée ailleurs entre deux synchronisations). Champs
     * comparés (sans casse ni espaces autour) et, s'il y a lieu, portée (même bâtiment pour un étage).
     * Même liste côté appareil : mobile/src/serveur/synchro/client.js (DOUBLONS).
     */
    public const DOUBLONS = [
        'Biens' => [['Numero', 'NumeroSerie'], []],
        'Equipements' => [['Numero', 'NumeroSerie'], []],
        'Contrats' => [['Numero'], []],
        'Stock' => [['Reference', 'Designation'], []],
        'CompteurEnergie' => [['Nom'], []],
        'PlanBatiments' => [['Nom'], []],
        'PlanEtages' => [['Nom'], ['BatimentId']],
    ];
    private const NOMS_CHAMPS = ['Numero' => 'numéro', 'NumeroSerie' => 'numéro de série', 'Reference' => 'référence', 'Designation' => 'désignation', 'Nom' => 'nom'];
    /** Durée de validité d'un code d'appairage (QR), en minutes. */
    public const INVITATION_MINUTES = 15;
    /** Colonnes « compteurs » fusionnées par différence plutôt qu'en conflit. */
    public const COMPTEURS = ['Stock' => ['Quantite']];
    /** Dossiers de fichiers synchronisés (sous data/, propres à l'organisation). */
    // (Les fonds d'écran ne le sont pas : leur dossier dépend de la clé secrète de chaque installation.)
    public const DOSSIERS = ['plans', 'urgences', 'thematiques', 'extensions'];
    /** Conservation du journal des modifications : au-delà, l'appareil refait une comparaison complète. */
    public const CONSERVATION_JOURS = 60;

    private static array $actif = [];
    private static bool $enCours = false;
    /** Appareil dont on applique les modifications (attribué aux entrées du journal). */
    public static ?int $appareilCourant = null;

    // ═════════════════════════════════════════════════════════════════════════
    //  Capture des écritures
    // ═════════════════════════════════════════════════════════════════════════

    /** Au moins un appareil relié et actif sur cette base ? (une requête par connexion) */
    public static function actif(PDO $pdo): bool
    {
        $k = spl_object_id($pdo);
        if (!array_key_exists($k, self::$actif)) {
            self::$enCours = true;
            try { self::$actif[$k] = (bool)$pdo->query('SELECT 1 FROM SynchroAppareils WHERE Actif = 1 LIMIT 1')->fetchColumn(); }
            catch (\Throwable $e) { self::$actif[$k] = false; }
            finally { self::$enCours = false; }
        }
        return self::$actif[$k];
    }
    public static function oublierEtat(PDO $pdo): void { unset(self::$actif[spl_object_id($pdo)]); }

    /** Note une écriture : table et identifiant de la ligne (null : table entière). Jamais bloquant. */
    public static function noter(PDO $pdo, string $sql, array $params): void
    {
        if (self::$enCours) return;
        if (!preg_match('/^\s*(INSERT(?:\s+OR\s+\w+)?\s+INTO|REPLACE\s+INTO|UPDATE|DELETE\s+FROM)\s+["`]?([A-Za-z_][A-Za-z0-9_]*)["`]?/i', $sql, $m)) return;
        $table = $m[2];
        if (in_array(strtolower($table), self::EXCLUES, true)) return;
        try {
            if (!self::actif($pdo)) return;
            self::$enCours = true;
            $cle = null;
            $op = strtoupper(substr(ltrim($m[1]), 0, 1));
            if ($op === 'I' || $op === 'R') {
                $cle = self::idInsere($pdo, $sql, $params);
            } elseif (preg_match('/\bWHERE\s+(?:["`]?\w+["`]?\.)?["`]?Id["`]?\s*=\s*(:\w+|\?)\s*(?:$|;|\bAND\b|\)|\bLIMIT\b)/is', $sql, $w)
                      && !preg_match('/\bWHERE\b.*\bOR\b/is', $sql)) {
                $v = self::valeurParam($sql, $params, $w[1], (int)strpos($sql, $w[0]) + strlen($w[0]));
                if ($v !== null && is_numeric($v)) $cle = (string)(int)$v;
            }
            if ($pdo instanceof SynchroPdo) $pdo->notes[] = [$table, $cle, self::$appareilCourant];
        } catch (\Throwable $e) {
            /* table absente ou base en lecture : la comparaison complète rattrapera */
        } finally {
            self::$enCours = false;
        }
    }

    /** Écrit les notes en attente d'une connexion. */
    public static function vider(PDO $pdo): void
    {
        if (!($pdo instanceof SynchroPdo) || !$pdo->notes || self::$enCours) return;
        $notes = $pdo->notes;
        $pdo->notes = [];
        self::$enCours = true;
        try {
            $st = $pdo->prepare('INSERT INTO SynchroJournal (TableNom, Cle, Quand, Appareil) VALUES (?, ?, ?, ?)');
            $quand = date('Y-m-d H:i:s');
            $vus = [];
            foreach ($notes as [$t, $c, $a]) {
                $k = $t . "\0" . $c . "\0" . $a;
                if (isset($vus[$k])) continue;
                $vus[$k] = true;
                $st->execute([$t, $c, $quand, $a]);
            }
        } catch (\Throwable $e) {
            /* base fermée, table absente : la comparaison complète rattrapera */
        } finally {
            self::$enCours = false;
        }
    }

    private static function valeurParam(string $sql, array $params, string $marque, int $fin)
    {
        if ($marque !== '?') {
            $n = substr($marque, 1);
            return $params[$n] ?? $params[':' . $n] ?? null;
        }
        $idx = substr_count(substr($sql, 0, $fin), '?') - 1;
        $liste = array_values($params);
        return $liste[$idx] ?? null;
    }

    private static function idInsere(PDO $pdo, string $sql, array $params): ?string
    {
        // Identifiant explicite dans la liste des colonnes ?
        if (preg_match('/INTO\s+["`]?\w+["`]?\s*\(([^)]*)\)\s*VALUES\s*\(([^)]*)\)/is', $sql, $m)) {
            $cols = array_map(fn($c) => trim($c, " \t\n\r\"`"), explode(',', $m[1]));
            $vals = array_map('trim', explode(',', $m[2]));
            $i = array_search('id', array_map('strtolower', $cols), true);
            if ($i !== false && isset($vals[$i])) {
                $v = $vals[$i];
                if (preg_match('/^:(\w+)$/', $v, $p)) $v = $params[$p[1]] ?? $params[':' . $p[1]] ?? null;
                elseif ($v === '?') { $liste = array_values($params); $v = $liste[substr_count(implode(',', array_slice($vals, 0, $i)), '?')] ?? null; }
                return ($v !== null && is_numeric($v)) ? (string)(int)$v : null;
            }
            if ($i === false && !preg_match('/\bId\b/i', $m[1]) && self::sansId($sql)) return null;
        }
        try { $id = $pdo->lastInsertId(); return ($id !== false && $id !== '' && $id !== '0') ? (string)$id : null; }
        catch (\Throwable $e) { return null; }
    }
    /** Tables dont la clé n'est pas « Id » : noter la table entière. */
    private static function sansId(string $sql): bool
    {
        return (bool)preg_match('/INTO\s+["`]?(Configuration|ConnectBatiments|Listes_?Reglages)\b/i', $sql);
    }

    // ═════════════════════════════════════════════════════════════════════════
    //  Schéma
    // ═════════════════════════════════════════════════════════════════════════

    public static function schema(Database $db): void
    {
        $pdo = $db->getPdo();
        $drv = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $auto = $drv === 'pgsql' ? 'BIGSERIAL PRIMARY KEY' : ($drv === 'mysql' ? 'BIGINT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT');
        $txt = $drv === 'mysql' ? 'VARCHAR(190)' : 'TEXT';
        $big = $drv === 'sqlite' ? 'INTEGER' : 'BIGINT';
        self::$enCours = true;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS SynchroAppareils (Id $auto, Numero INTEGER, Nom $txt, UtilisateurId INTEGER, Login $txt,
                JetonHash $txt, CreeLe $txt, VuLe $txt, Version $txt, Actif INTEGER DEFAULT 1)");
            $pdo->exec("CREATE TABLE IF NOT EXISTS SynchroJournal (Seq $auto, TableNom $txt, Cle $txt, Quand $txt, Appareil INTEGER)");
            $pdo->exec("CREATE TABLE IF NOT EXISTS SynchroReserves (Id $auto, AppareilId INTEGER, TableNom $txt, Debut $big, Fin $big, CreeLe $txt)");
            // Appairage par code / QR : validé des deux côtés (appareil et gestionnaire), sans mot de passe sur l'appareil.
            $pdo->exec("CREATE TABLE IF NOT EXISTS SynchroInvitations (Id $auto, CodeHash $txt, CreeLe $txt, Expire $txt, CreePar $txt,
                UtilisateurId INTEGER, Etat $txt, AppareilNom $txt, Version $txt, Verif $txt, SecretHash $txt, TelOk INTEGER DEFAULT 0,
                PcOk INTEGER DEFAULT 0, AppareilId INTEGER, DemandeLe $txt)");
            try { $pdo->exec('CREATE INDEX idx_synchro_journal_quand ON SynchroJournal (Quand)'); } catch (\Throwable $e) { /* déjà là */ }
        } finally { self::$enCours = false; }
    }

    /** Purge du journal des modifications, au plus une fois par jour. */
    public static function purger(Database $db): void
    {
        $marque = __DIR__ . '/../data/run/.synchro-purge-' . substr(md5($db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) . spl_object_id($db)), 0, 6);
        $jour = date('Y-m-d');
        if (is_file($marque) && trim((string)@file_get_contents($marque)) === $jour) return;
        @mkdir(dirname($marque), 0750, true);
        @file_put_contents($marque, $jour);
        $db->getPdo()->prepare('DELETE FROM SynchroJournal WHERE Quand < ?')->execute([date('Y-m-d H:i:s', strtotime('-' . self::CONSERVATION_JOURS . ' days'))]);
    }

    // ═════════════════════════════════════════════════════════════════════════
    //  Appareils et jetons
    // ═════════════════════════════════════════════════════════════════════════

    public static function relier(Database $db, string $org, array $user, string $nom, string $version): array
    {
        self::schema($db);
        $n = (int)($db->fetchOne('SELECT MAX(Numero) AS n FROM SynchroAppareils')['n'] ?? 0) + 1;
        $now = date('Y-m-d H:i:s');
        $db->execute('INSERT INTO SynchroAppareils (Numero, Nom, UtilisateurId, Login, JetonHash, CreeLe, VuLe, Version, Actif) VALUES (:n, :nom, :u, :l, :h, :c, :v, :ver, 1)',
            ['n' => $n, 'nom' => mb_substr($nom !== '' ? $nom : 'Téléphone', 0, 80), 'u' => (int)$user['Id'], 'l' => (string)$user['Login'], 'h' => '', 'c' => $now, 'v' => $now, 'ver' => mb_substr($version, 0, 40)]);
        $id = (int)$db->dernierIdInsere();
        $jeton = self::PREFIXE . '.' . $org . '.' . $id . '.' . bin2hex(random_bytes(24));
        $db->execute('UPDATE SynchroAppareils SET JetonHash = :h WHERE Id = :id', ['h' => hash('sha256', $jeton), 'id' => $id]);
        self::oublierEtat($db->getPdo());
        return ['jeton' => $jeton, 'appareil' => ['id' => $id, 'numero' => $n]];
    }

    /** Organisations du serveur : [['cle', 'base' (config ou null)]]. */
    public static function organisations(): array
    {
        if (class_exists('TenantResolver') && TenantResolver::isMultiTenant()) {
            $out = [];
            foreach (TenantResolver::getAllTenants() as $cle => $t) {
                if (!empty($t['actif']) && !empty($t['base_de_donnees'])) $out[] = ['cle' => (string)$cle, 'base' => (array)$t['base_de_donnees']];
            }
            return $out;
        }
        return [['cle' => 'default', 'base' => null]];
    }

    // ═════════════════════════════════════════════════════════════════════════
    //  Appairage par code (QR) : le gestionnaire crée une invitation sur le serveur,
    //  l'appareil la présente, chacun voit le même code de vérification et le confirme ;
    //  le jeton n'est remis qu'une fois les deux confirmations faites.
    // ═════════════════════════════════════════════════════════════════════════

    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    private static function codeNormal(string $code): string { return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code)); }
    private static function min(?array $l): ?array { return $l === null ? null : array_change_key_case($l, CASE_LOWER); }

    /** Nouvelle invitation : ['id', 'code', 'expire']. Les invitations ouvertes plus anciennes de ce gestionnaire sont retirées. */
    public static function invitationCreer(Database $db, array $pc, int $compte): array
    {
        self::schema($db);
        $db->execute("UPDATE SynchroInvitations SET Etat = 'retiree' WHERE CreePar = :p AND Etat = 'ouverte'", ['p' => (string)$pc['Login']]);
        $code = '';
        for ($i = 0; $i < 8; $i++) $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        $now = time();
        $db->execute('INSERT INTO SynchroInvitations (CodeHash, CreeLe, Expire, CreePar, UtilisateurId, Etat, TelOk, PcOk) VALUES (:h, :c, :e, :p, :u, :etat, 0, 0)',
            ['h' => hash('sha256', $code), 'c' => date('Y-m-d H:i:s', $now), 'e' => date('Y-m-d H:i:s', $now + self::INVITATION_MINUTES * 60),
             'p' => (string)$pc['Login'], 'u' => $compte, 'etat' => 'ouverte']);
        return ['id' => (int)$db->dernierIdInsere(), 'code' => substr($code, 0, 4) . '-' . substr($code, 4), 'expire' => date('c', $now + self::INVITATION_MINUTES * 60)];
    }

    /** État d'une invitation, pour l'écran du gestionnaire. */
    public static function invitationEtat(Database $db, int $id): ?array
    {
        self::schema($db);
        $l = self::min($db->fetchOne('SELECT * FROM SynchroInvitations WHERE Id = :id', ['id' => $id]));
        if (!$l) return null;
        $etat = (string)$l['etat'];
        if (in_array($etat, ['ouverte', 'demandee', 'acceptee'], true) && strtotime((string)$l['expire']) < time()) $etat = 'expiree';
        return ['id' => (int)$l['id'], 'etat' => $etat, 'appareil' => $l['appareilnom'], 'version' => $l['version'],
                'verif' => in_array($etat, ['demandee', 'acceptee'], true) ? $l['verif'] : null,
                'telephone_confirme' => (bool)(int)$l['telok'], 'serveur_confirme' => (bool)(int)$l['pcok'],
                'utilisateur' => (int)$l['utilisateurid'], 'expire' => $l['expire'], 'appareil_id' => $l['appareilid'] !== null ? (int)$l['appareilid'] : null];
    }

    /** Le gestionnaire accepte (avec le compte choisi) ou refuse la demande. */
    public static function invitationValider(Database $db, int $id, bool $accepter, int $compte): ?string
    {
        $e = self::invitationEtat($db, $id);
        if (!$e) return 'Invitation introuvable.';
        if (!$accepter) { $db->execute("UPDATE SynchroInvitations SET Etat = 'refusee' WHERE Id = :id", ['id' => $id]); return null; }
        if ($e['etat'] !== 'demandee') return $e['etat'] === 'expiree' ? 'Le code a expiré : créez-en un nouveau.' : 'Aucun appareil n\'attend de validation.';
        $u = $db->fetchOne('SELECT * FROM Utilisateurs WHERE Id = :id', ['id' => $compte]);
        if (!$u) return 'Compte introuvable.';
        if (isset($u['Actif']) && (int)$u['Actif'] === 0) return 'Ce compte est désactivé.';
        $db->execute("UPDATE SynchroInvitations SET PcOk = 1, UtilisateurId = :u, Etat = 'acceptee' WHERE Id = :id", ['u' => $compte, 'id' => $id]);
        return null;
    }

    public static function invitationRetirer(Database $db, int $id): void
    {
        self::schema($db);
        $db->execute("UPDATE SynchroInvitations SET Etat = 'retiree' WHERE Id = :id AND Etat IN ('ouverte', 'demandee', 'acceptee')", ['id' => $id]);
    }

    /** L'appareil présente le code. Rend ['demande', 'secret', 'verif', 'organisation'] ou lève une exception (message pour l'appareil). */
    public static function appairageDemander(string $code, string $nom, string $version): array
    {
        $code = self::codeNormal($code);
        if (strlen($code) !== 8) throw new \RuntimeException('Code invalide : 8 caractères (exemple : K7QD-4MZP).');
        foreach (self::organisations() as $o) {
            $db = $o['base'] === null ? new Database() : new Database($o['base']);
            try { $l = self::min($db->fetchOne('SELECT * FROM SynchroInvitations WHERE CodeHash = :h', ['h' => hash('sha256', $code)])); }
            catch (\Throwable $e) { continue; }   // table pas encore créée sur cette base
            if (!$l) continue;
            if ($l['etat'] !== 'ouverte') throw new \RuntimeException('Ce code a déjà servi ou a été retiré : demandez-en un nouveau.');
            if (strtotime((string)$l['expire']) < time()) throw new \RuntimeException('Ce code a expiré : demandez-en un nouveau.');
            $secret = bin2hex(random_bytes(24));
            $verif = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $db->execute("UPDATE SynchroInvitations SET Etat = 'demandee', AppareilNom = :n, Version = :v, Verif = :vf, SecretHash = :s, DemandeLe = :d, Expire = :e WHERE Id = :id AND Etat = 'ouverte'",
                ['n' => mb_substr($nom !== '' ? $nom : 'Appareil', 0, 80), 'v' => mb_substr($version, 0, 40), 'vf' => $verif, 's' => hash('sha256', $secret),
                 'd' => date('Y-m-d H:i:s'), 'e' => date('Y-m-d H:i:s', time() + self::INVITATION_MINUTES * 60), 'id' => (int)$l['id']]);
            return ['demande' => $o['cle'] . '.' . (int)$l['id'], 'secret' => $secret, 'verif' => substr($verif, 0, 3) . ' ' . substr($verif, 3), 'organisation' => $o['cle']];
        }
        throw new \RuntimeException('Code inconnu : vérifiez-le, ou demandez-en un nouveau.');
    }

    /** [Database, ligne, organisation] de la demande d'un appareil, secret vérifié. */
    private static function demande(string $ref, string $secret): array
    {
        if (!preg_match('/^([A-Za-z0-9_-]{1,64})\.(\d{1,9})$/', $ref, $m)) throw new \RuntimeException('Demande inconnue.');
        foreach (self::organisations() as $o) {
            if ($o['cle'] !== $m[1]) continue;
            $db = $o['base'] === null ? new Database() : new Database($o['base']);
            $l = self::min($db->fetchOne('SELECT * FROM SynchroInvitations WHERE Id = :id', ['id' => (int)$m[2]]));
            if (!$l || !hash_equals((string)$l['secrethash'], hash('sha256', $secret))) break;
            return [$db, $l, $o['cle']];
        }
        throw new \RuntimeException('Demande inconnue.');
    }

    /** L'appareil confirme que le code de vérification est le même des deux côtés. */
    public static function appairageConfirmer(string $ref, string $secret): void
    {
        [$db, $l] = self::demande($ref, $secret);
        $db->execute('UPDATE SynchroInvitations SET TelOk = 1 WHERE Id = :id', ['id' => (int)$l['id']]);
    }

    /**
     * L'appareil attend la décision. ['etat' => attente|refusee|retiree|expiree|acceptee] ; une fois les
     * deux confirmations faites, l'appareil est créé et son jeton rendu (une seule fois).
     */
    public static function appairageAttendre(string $ref, string $secret, string $version): array
    {
        [$db, $l, $org] = self::demande($ref, $secret);
        $etat = (string)$l['etat'];
        if (in_array($etat, ['refusee', 'retiree'], true)) return ['etat' => $etat];
        if ($etat === 'livree') return ['etat' => 'retiree'];
        if (strtotime((string)$l['expire']) < time()) return ['etat' => 'expiree'];
        if ($etat !== 'acceptee' || !(int)$l['telok'] || !(int)$l['pcok']) return ['etat' => 'attente', 'telephone_confirme' => (bool)(int)$l['telok'], 'serveur_confirme' => (bool)(int)$l['pcok']];
        $user = $db->fetchOne('SELECT * FROM Utilisateurs WHERE Id = :id', ['id' => (int)$l['utilisateurid']]);
        if (!$user || (isset($user['Actif']) && (int)$user['Actif'] === 0)) return ['etat' => 'refusee', 'raison' => 'Le compte choisi n\'est plus actif.'];
        // Une seule remise : la ligne passe à « livree » avant de créer l'appareil.
        $n = $db->getPdo()->prepare("UPDATE SynchroInvitations SET Etat = 'livree' WHERE Id = ? AND Etat = 'acceptee'");
        $n->execute([(int)$l['id']]);
        if ($n->rowCount() !== 1) return ['etat' => 'retiree'];
        $r = self::relier($db, $org, $user, (string)$l['appareilnom'], $version !== '' ? $version : (string)$l['version']);
        $db->execute('UPDATE SynchroInvitations SET AppareilId = :a WHERE Id = :id', ['a' => (int)$r['appareil']['id'], 'id' => (int)$l['id']]);
        unset($user['MotDePasse'], $user['ResetToken'], $user['ResetTokenExpiry']);
        return ['etat' => 'acceptee', 'lien' => $r + ['utilisateur' => $user, 'seq' => self::seq($db), 'organisation' => $org]];
    }

    /** Appareils reliés (écran du gestionnaire). */
    public static function appareils(Database $db): array
    {
        self::schema($db);
        $out = [];
        foreach ($db->fetchAll('SELECT * FROM SynchroAppareils ORDER BY Id DESC') as $a) {
            $a = self::min($a);
            $out[] = ['id' => (int)$a['id'], 'numero' => (int)$a['numero'], 'nom' => $a['nom'], 'login' => $a['login'], 'utilisateur' => (int)$a['utilisateurid'],
                      'relie_le' => $a['creele'], 'vu_le' => $a['vule'], 'version' => $a['version'], 'actif' => (bool)(int)$a['actif']];
        }
        return $out;
    }

    public static function revoquer(Database $db, int $id): void
    {
        self::schema($db);
        $db->execute('UPDATE SynchroAppareils SET Actif = 0, JetonHash = :h WHERE Id = :id', ['h' => '', 'id' => $id]);
        self::oublierEtat($db->getPdo());
    }

    /**
     * App mobile proposée par ce serveur : telechargements/larka.json, écrit par « npm run apk » à côté de
     * telechargements/larka.apk. null s'il n'y en a pas.
     */
    public static function appMobile(): ?array
    {
        $dossier = dirname(__DIR__) . '/telechargements';
        if (!self::$obtenir) self::planifierVerificationApp();
        if (!is_file($dossier . '/larka.apk') || !is_file($dossier . '/larka.json')) return null;
        $j = json_decode((string)@file_get_contents($dossier . '/larka.json'), true);
        if (!is_array($j) || empty($j['versionCode'])) return null;
        return ['version' => (string)($j['version'] ?? ''), 'versionName' => (string)($j['versionName'] ?? ''), 'versionCode' => (int)$j['versionCode'],
                'taille' => (int)($j['taille'] ?? filesize($dossier . '/larka.apk')), 'sha256' => (string)($j['sha256'] ?? ''), 'date' => (string)($j['date'] ?? ''),
                'chemin' => 'telechargements/larka.apk'];
    }

    // ═════════════════════════════════════════════════════════════════════════
    //  App mobile : version plus récente sur GitHub. Le serveur regarde les releases du dépôt de
    //  ses propres mises à jour (config.json → mises_a_jour.depot) au plus toutes les 6 heures. S'il y
    //  trouve une app de LA MÊME version de Larka que lui, plus récente que celle de telechargements/
    //  (larka-3.0.0-r3.apk > r2), il la télécharge (empreinte vérifiée) et la propose : les appareils
    //  la voient à leur synchronisation suivante et la prennent ici, sans accès Internet. Une app
    //  d'une AUTRE version de Larka n'est jamais prise : elle arrive avec la mise à jour du serveur.
    // ═════════════════════════════════════════════════════════════════════════

    /** Pour les essais : remplace l'accès à GitHub — fn(string $url, ?string $dest): string. */
    public static $obtenir = null;
    private static bool $verificationPlanifiee = false;

    public static function etatGithubPublic(): array { return self::etatGithub(); }
    private static function etatGithub(): array
    {
        $f = dirname(__DIR__) . '/data/cache/app-mobile-github.json';
        $j = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
        return is_array($j) ? $j : [];
    }

    /** Vérification GitHub après la réponse (au plus toutes les 6 h ; 1 h après un échec). */
    public static function planifierVerificationApp(): void
    {
        if (self::$verificationPlanifiee) return;
        $e = self::etatGithub();
        $attente = !empty($e['erreur']) ? 3600 : 6 * 3600;
        if (!empty($e['verifie']) && time() - (int)strtotime((string)$e['verifie']) < $attente) return;
        self::$verificationPlanifiee = true;
        register_shutdown_function(static function () {
            if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
            try { self::verifierAppGithub(); } catch (\Throwable $_) { /* noté dans l'état */ }
        });
    }

    /** Meilleure app pour cette version de Larka dans les releases : [code, nom, asset] ou null. */
    public static function choisirAppGithub(array $releases, string $version, bool $beta): ?array
    {
        [$a, $b, $c] = array_map('intval', explode('.', $version . '.0.0'));
        $mieux = null;
        foreach ($releases as $rel) {
            if (!empty($rel['draft']) || (!$beta && !empty($rel['prerelease']))) continue;
            foreach ($rel['assets'] ?? [] as $as) {
                if (!preg_match('/^larka-(\d+)\.(\d+)\.(\d+)-r(\d+)\.apk$/i', (string)($as['name'] ?? ''), $m)) continue;
                if ((int)$m[1] !== $a || (int)$m[2] !== $b || (int)$m[3] !== $c) continue;
                $code = ($a * 10000 + $b * 100 + $c) * 100 + (int)$m[4];
                if (!$mieux || $code > $mieux[0]) $mieux = [$code, "$a.$b.$c-r" . (int)$m[4], $as];
            }
        }
        return $mieux;
    }

    public static function verifierAppGithub(): array
    {
        $racine = dirname(__DIR__);
        $cache = $racine . '/data/cache';
        if (!is_dir($cache)) @mkdir($cache, 0750, true);
        $verrou = @fopen($cache . '/app-mobile-github.lock', 'c');
        if (!$verrou || !flock($verrou, LOCK_EX | LOCK_NB)) return self::etatGithub();
        $cfg = static fn(string $k, $d = null) => (function_exists('cfg') && ($v = cfg('mises_a_jour', $k)) !== null && $v !== '') ? $v : $d;
        $etat = ['verifie' => date('c'), 'erreur' => null, 'disponible' => null, 'telechargee' => null];
        try {
            if (!(bool)$cfg('actif', true) || $cfg('source', 'github') !== 'github') throw new \RuntimeException('Vérification GitHub désactivée (mises_a_jour).');
            $depot = (string)$cfg('depot', 'Chipsoreo/LARKA_GMAO');
            if (!preg_match('#^[\w.-]+/[\w.-]+$#', $depot)) throw new \RuntimeException('Dépôt GitHub invalide.');
            if (!class_exists('LarkaVersion')) require_once __DIR__ . '/Version.php';
            $v = LarkaVersion::lire();
            $version = (string)($v['version'] ?? '');
            $beta = strtolower((string)$cfg('canal', strcasecmp((string)($v['canal'] ?? 'Stable'), 'Stable') === 0 ? 'stable' : 'beta')) !== 'stable';
            $rels = json_decode(self::obtenir("https://api.github.com/repos/$depot/releases?per_page=15", null), true);
            if (!is_array($rels)) throw new \RuntimeException('Réponse GitHub illisible.');
            $choix = self::choisirAppGithub($rels, $version, $beta);
            $locale = self::appMobile();
            if ($choix) $etat['disponible'] = ['versionName' => $choix[1], 'versionCode' => $choix[0]];
            if ($choix && $choix[0] > (int)($locale['versionCode'] ?? 0)) {
                $as = $choix[2];
                $dossier = $racine . '/telechargements';
                if (!is_dir($dossier)) @mkdir($dossier, 0755, true);
                // Dépôt privé : l'URL de l'API (avec le jeton) ; sinon le lien public.
                $url = (string)(getenv('GMAO_MAJ_GITHUB_TOKEN') || (function_exists('env') && env('GMAO_MAJ_GITHUB_TOKEN', '')) ? ($as['url'] ?? $as['browser_download_url']) : ($as['browser_download_url'] ?? ''));
                self::obtenir($url, $dossier . '/larka.apk.part');
                $taille = (int)@filesize($dossier . '/larka.apk.part');
                $sha = hash_file('sha256', $dossier . '/larka.apk.part');
                $attendu = preg_match('/^sha256:([a-f0-9]{64})$/i', (string)($as['digest'] ?? ''), $d) ? strtolower($d[1]) : null;
                if ((isset($as['size']) && (int)$as['size'] !== $taille) || ($attendu && $attendu !== $sha) || $taille < 1000) {
                    @unlink($dossier . '/larka.apk.part');
                    throw new \RuntimeException('Téléchargement de l\'app incomplet ou altéré (taille ou empreinte).');
                }
                rename($dossier . '/larka.apk.part', $dossier . '/larka.apk');
                file_put_contents($dossier . '/larka.json', json_encode(['version' => $version, 'versionName' => $choix[1], 'versionCode' => $choix[0],
                    'taille' => $taille, 'sha256' => $sha, 'date' => date('c'), 'source' => 'github'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                $etat['telechargee'] = $choix[1];
            }
        } catch (\Throwable $e) {
            $etat['erreur'] = $e->getMessage();
        }
        @file_put_contents($cache . '/app-mobile-github.json', json_encode($etat, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        flock($verrou, LOCK_UN); fclose($verrou);
        return $etat;
    }

    /** GET https (GitHub) : contenu, ou écrit dans $dest. */
    private static function obtenir(string $url, ?string $dest): string
    {
        if (self::$obtenir) return (string)(self::$obtenir)($url, $dest);
        if (!str_starts_with($url, 'https://')) throw new \RuntimeException('URL non HTTPS refusée.');
        if (!function_exists('curl_init')) throw new \RuntimeException('Extension PHP curl absente.');
        $entetes = ['User-Agent: Larka-Synchro', 'Accept: ' . ($dest ? 'application/octet-stream' : 'application/vnd.github+json')];
        $jeton = function_exists('env') ? (string)(env('GMAO_MAJ_GITHUB_TOKEN', '') ?? '') : (string)getenv('GMAO_MAJ_GITHUB_TOKEN');
        if ($jeton !== '' && str_contains((string)parse_url($url, PHP_URL_HOST), 'github.com')) $entetes[] = 'Authorization: Bearer ' . $jeton;
        $ch = curl_init($url);
        $fh = $dest ? fopen($dest, 'wb') : null;
        curl_setopt_array($ch, [CURLOPT_HTTPHEADER => $entetes, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => $dest ? 600 : 15, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
        if ($fh) curl_setopt($ch, CURLOPT_FILE, $fh); else curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $r = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($fh) fclose($fh);
        if ($r === false || $code >= 400) { if ($dest) @unlink($dest); throw new \RuntimeException('GitHub : ' . ($err ?: "HTTP $code")); }
        return $dest ? '' : (string)$r;
    }

    /** Adresses du serveur vues du réseau local (pour le QR code quand le navigateur est sur 127.0.0.1). */
    public static function adressesReseau(): array
    {
        $port = (int)($_SERVER['SERVER_PORT'] ?? 80);
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $chemin = rtrim(str_replace('\\', '/', dirname(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/api/index.php')))), '/');
        $ips = [];
        // Adresse de sortie (aucun paquet n'est envoyé : UDP non connecté).
        $s = @stream_socket_client('udp://192.0.2.1:9', $errno, $errstr, 1);
        if ($s) { $n = @stream_socket_get_name($s, false); fclose($s); if ($n && preg_match('/^(\d+\.\d+\.\d+\.\d+):/', $n, $m) && $m[1] !== '0.0.0.0' && !str_starts_with($m[1], '127.')) $ips[] = $m[1]; }
        $srv = (string)($_SERVER['SERVER_ADDR'] ?? '');
        if (filter_var($srv, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && !str_starts_with($srv, '127.') && $srv !== '0.0.0.0') $ips[] = $srv;
        $out = [];
        foreach (array_unique($ips) as $ip) {
            $out[] = ($https ? 'https' : 'http') . '://' . $ip . ((($https && $port === 443) || (!$https && $port === 80)) ? '' : ':' . $port) . $chemin;
        }
        return $out;
    }

    /** Jeton → [Database, appareil, utilisateur, organisation] ou [null, raison]. */
    public static function authentifier(string $jeton): array
    {
        if (!preg_match('/^' . self::PREFIXE . '\.([A-Za-z0-9_-]{1,64})\.(\d{1,9})\.([a-f0-9]{48})$/', $jeton, $m)) return [null, 'Jeton illisible.'];
        [, $org, $id] = $m;
        foreach (self::organisations() as $o) {
            if ($o['cle'] !== $org) continue;
            $db = $o['base'] === null ? new Database() : new Database($o['base']);
            try { $a = $db->fetchOne('SELECT * FROM SynchroAppareils WHERE Id = :id', ['id' => (int)$id]); }
            catch (\Throwable $e) { return [null, 'Jeton refusé.']; }
            if (!$a || empty($a['JetonHash']) || !hash_equals((string)$a['JetonHash'], hash('sha256', $jeton))) return [null, 'Jeton refusé.'];
            if (!(int)$a['Actif']) return [null, 'Cet appareil a été délié du serveur. Reliez-le de nouveau.'];
            $u = $db->fetchOne('SELECT * FROM Utilisateurs WHERE Id = :id', ['id' => (int)$a['UtilisateurId']]);
            $login = (string)($a['Login'] ?? '');
            if (!$u) return [null, "Le compte « $login » relié à cet appareil n'existe plus sur le serveur. Reliez de nouveau l'appareil (QR code : Utilisateurs → Appareils mobiles)."];
            if (isset($u['Actif']) && (int)$u['Actif'] === 0) return [null, "Le compte « $login » relié à cet appareil est désactivé sur le serveur. Un gestionnaire peut le réactiver, ou relier l'appareil à un autre compte."];
            unset($u['MotDePasse']);
            $db->execute('UPDATE SynchroAppareils SET VuLe = :v WHERE Id = :id', ['v' => date('Y-m-d H:i:s'), 'id' => (int)$a['Id']]);
            return [[$db, $a, $u, $org], null];
        }
        return [null, 'Jeton refusé.'];
    }

    // ═════════════════════════════════════════════════════════════════════════
    //  Tables, clés, empreintes
    // ═════════════════════════════════════════════════════════════════════════

    /** Tables synchronisées : nom → ['cle' => colonne, 'colonnes' => [...]] (noms tels que Larka les écrit). */
    public static function tables(Database $db): array
    {
        $pdo = $db->getPdo();
        $drv = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $out = [];
        if ($drv === 'sqlite') {
            foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $t) {
                if (self::exclue($t)) continue;
                $cols = []; $pk = [];
                foreach ($pdo->query('PRAGMA table_info("' . str_replace('"', '', $t) . '")')->fetchAll(PDO::FETCH_ASSOC) as $c) {
                    $cols[] = $c['name'];
                    if ((int)$c['pk'] > 0) $pk[(int)$c['pk']] = $c['name'];
                }
                if (count($pk) !== 1) continue;   // clé composée ou absente : hors synchronisation
                $out[$t] = ['cle' => reset($pk), 'colonnes' => $cols];
            }
            return $out;
        }
        // PostgreSQL / MariaDB : information_schema ; les noms PostgreSQL sont rendus avec la casse de Larka.
        $schema = $drv === 'pgsql' ? 'current_schema()' : 'DATABASE()';
        $lignes = $pdo->query("SELECT c.table_name AS t, c.column_name AS c FROM information_schema.columns c
            WHERE c.table_schema = $schema ORDER BY c.table_name, c.ordinal_position")->fetchAll(PDO::FETCH_ASSOC);
        $pks = [];
        foreach ($pdo->query("SELECT k.table_name AS t, k.column_name AS c FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage k ON k.constraint_name = tc.constraint_name AND k.table_schema = tc.table_schema AND k.table_name = tc.table_name
            WHERE tc.constraint_type = 'PRIMARY KEY' AND tc.table_schema = $schema")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $pks[strtolower($r['t'] ?? $r['T'])][] = $r['c'] ?? $r['C'];
        }
        $noms = $db->nomsColonnes();
        foreach ($lignes as $r) {
            $t = $r['t'] ?? $r['T']; $c = $r['c'] ?? $r['C'];
            $tl = strtolower($t);
            if (self::exclue($t) || count($pks[$tl] ?? []) !== 1) continue;
            $nomT = $noms['tables'][$tl] ?? $t;
            $out[$nomT] ??= ['cle' => $noms['colonnes'][strtolower($pks[$tl][0])] ?? $pks[$tl][0], 'colonnes' => []];
            $out[$nomT]['colonnes'][] = $noms['colonnes'][strtolower($c)] ?? $c;
        }
        return $out;
    }

    /** Valeur canonique (empreintes) : identique à src/serveur/synchro/empreinte.js de l'app. */
    public static function canon($v, bool $texteNumerique): string
    {
        if ($v === null) return "\0";
        if (is_bool($v)) return $v ? '1' : '0';
        if (is_int($v)) return (string)$v;
        if (is_float($v)) return self::nombre($v);
        $s = (string)$v;
        if ($texteNumerique && $s !== '' && is_numeric($s) && preg_match('/^-?(\d+\.?\d*|\.\d+)([eE][-+]?\d+)?$/', $s)) return self::nombre((float)$s);
        return $s;
    }
    public static function nombre(float $f): string
    {
        if (!is_finite($f)) return (string)$f;
        if (floor($f) === $f && abs($f) < 1e15) return sprintf('%.0f', $f);
        $e = sprintf('%.9e', $f);
        [$mant, $exp] = explode('e', $e);
        $mant = rtrim(rtrim($mant, '0'), '.');
        return $mant . 'e' . $exp;
    }
    public static function empreinte(array $ligne, array $colonnes, bool $texteNumerique): string
    {
        sort($colonnes, SORT_STRING);
        $p = [];
        foreach ($colonnes as $c) $p[] = $c . '=' . self::canon($ligne[$c] ?? null, $texteNumerique);
        return substr(sha1(implode("\x1f", $p)), 0, 16);
    }
    public static function texteNumerique(Database $db): bool
    {
        return $db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite';
    }

    /** Compte qui gère l'établissement (voit et écrit tout, comme dans le web). */
    public static function gerant(array $user): bool
    {
        return in_array((string)($user['Role'] ?? ''), ['Admin', 'Gestionnaire'], true);
    }

    /** Table hors synchronisation : EXCLUES, et le registre super admin (« sa_… ») s'il partage la base. */
    private static function exclue(string $table): bool
    {
        $t = strtolower($table);
        return in_array($t, self::EXCLUES, true) || str_starts_with($t, 'sa_');
    }

    /** Colonnes d'une table synchronisée (recherche sans casse), [] si inconnue. */
    private static function colonnesDe(Database $db, string $table): array
    {
        foreach (self::tables($db) as $nom => $d) if (strcasecmp($nom, $table) === 0) return $d['colonnes'];
        return [];
    }

    /** Lecture par l'appareil, mémorisée le temps de la requête : base + compte + rôle → règles. */
    private static array $lecture = [];

    /**
     * LECTURE PAR L'APPAREIL : ce que le rôle du compte relié peut recevoir, table par table.
     *
     * L'APP RECEVAIT TOUTE LA BASE, QUEL QUE SOIT LE RÔLE.
     * Un demandeur — n'importe quel agent, ou un compte Google créé tout seul — reliait un
     * téléphone avec son seul mot de passe (synchro_lier) et téléchargeait les 58 tables :
     * contrats et factures, interventions, tous les comptes avec courriels et téléphones,
     * Larka Connect, procédures d'urgence réservées aux gestionnaires… Le web lui refuse
     * tout cela. L'en-tête de ce fichier promettait qu'un jeton dérobé « ne vaille pas plus
     * que le rôle de son compte » : c'était vrai pour écrire (droitsEcriture), pas pour lire.
     *
     * Les règles sont celles des routes du web :
     *   • Gestionnaire / Admin : tout ;
     *   • Visionneur / Technicien : la consultation que require_role / require_lecture leur
     *     ouvrent (tous les modules, la supervision comprise) ;
     *   • Demandeur : SES demandes, déclarations et trajets, l'annuaire cartographié s'il lui
     *     est ouvert, et les onglets ouverts à SON compte (Utilisateurs.AccesLecture) ;
     *   • pour tous : documents selon api/DocumentsAcl.php, comptes des autres réduits à
     *     l'annuaire, configuration réduite aux réglages d'affichage, tables d'un module
     *     selon les pages que sa déclaration ouvre au rôle.
     * Une table sans règle ne livre aucune ligne : l'app garde son schéma, vide — exactement
     * ce que le même écran montrerait en ligne.
     *
     * @return array<string, true|array{sql:string,p:array}> table (minuscules) → true (toutes
     *         les lignes) ou condition SQL et ses paramètres ; « * » : tout ; absente : rien.
     */
    public static function droitsLecture(Database $db, array $user): array
    {
        $k = spl_object_id($db) . ':' . (int)($user['Id'] ?? 0) . ':' . (string)($user['Role'] ?? '');
        if (isset(self::$lecture[$k])) return self::$lecture[$k];
        if (self::gerant($user)) return self::$lecture[$k] = ['*' => true];

        $role  = (string)($user['Role'] ?? '');
        $uid   = (int)($user['Id'] ?? 0);
        $login = (string)($user['Login'] ?? '');
        // Le Technicien a les droits de LECTURE du Visionneur (require_role, api/index.php).
        $visionneur = in_array($role, ['Visionneur', 'Technicien'], true);
        $siennes = ['sql' => 'UtilisateurId = :sl_uid', 'p' => ['sl_uid' => $uid]];

        $d = array_fill_keys(self::TABLES_COMMUNES, true);
        // Réglages d'affichage seulement (config_check), jamais ceux de sécurité.
        $ou = []; $p = [];
        foreach (self::CONFIG_PUBLIQUE as $i => $c) { $ou[] = ':sl_c' . $i; $p['sl_c' . $i] = $c; }
        $ou = ['Cle IN (' . implode(',', $ou) . ')'];
        foreach (self::CONFIG_PUBLIQUE_PREFIXES as $i => $pre) {
            $ou[] = 'SUBSTR(Cle, 1, ' . strlen($pre) . ') = :sl_cp' . $i;
            $p['sl_cp' . $i] = $pre;
        }
        $d['configuration'] = ['sql' => implode(' OR ', $ou), 'p' => $p];
        // Déclarations d'inventaire : les siennes (route declarations_inventaire).
        $d['declarationsinventaire'] = ['sql' => 'LOWER(DeclarantLogin) = LOWER(:sl_login)', 'p' => ['sl_login' => $login]];

        $modules = [];
        if ($visionneur) {
            foreach (self::TABLES_VISIONNEUR as $t) $d[$t] = true;
        } else {
            // Demandeur, et tout rôle qui n'est pas listé : le moins, comme le web.
            $d['demandesintervention'] = $siennes;
            $d['mobilitecarbone'] = $siennes;
            try {
                $modules = function_exists('demandeur_modules_lecture') ? demandeur_modules_lecture($db, $user) : ($db->getAccesLecture($uid) ?? []);
            } catch (\Throwable $e) { $modules = []; }
            foreach ($modules as $m) foreach (self::TABLES_DES_MODULES[$m] ?? [] as $t) $d[$t] = true;
            // Inventaire par l'agent : les biens qui lui sont affectés (route inventaire_agent).
            if (!isset($d['biens']) && (string)$db->getConfig('inventaire_agent_actif') === '1'
                && in_array('AffecteUserId', self::colonnesDe($db, 'Biens'), true)) {
                $d['biens'] = ['sql' => 'AffecteUserId = :sl_uid', 'p' => ['sl_uid' => $uid]];
            }
            // Annuaire cartographié, s'il est ouvert aux demandeurs (routes/annuaire.php) :
            // bâtiments, étages, points rattachés à une personne et textes marqués pour l'annuaire.
            if ((string)$db->getConfig('plans_demandeur_actif') === '1') {
                $d['planbatiments'] = true;
                $d['planetages'] = true;
                $sql = "(TypeElement = 'point' AND (RefUtilisateurId IS NOT NULL OR (PersonneData IS NOT NULL AND PersonneData <> '')))";
                if (in_array('AfficherAnnuaire', self::colonnesDe($db, 'PlanElements'), true)) $sql .= " OR (TypeElement = 'texte' AND AfficherAnnuaire = 1)";
                $d['planelements'] = ['sql' => $sql, 'p' => []];
            }
        }
        $d['documents'] = self::regleDocuments($user, $visionneur, $modules);
        foreach (self::tablesModulesLisibles($db, $user) as $t) $d[$t] = true;
        return self::$lecture[$k] = $d;
    }

    /**
     * Documents lisibles : api/DocumentsAcl.php (doc_peut_lire), traduit en une condition
     * SQL — la table porte le contenu des fichiers, on ne la parcourt pas ligne par ligne.
     */
    private static function regleDocuments(array $user, bool $visionneur, array $modules): array
    {
        require_once __DIR__ . '/DocumentsAcl.php';
        $types = $visionneur ? self::DOC_DEMANDE : [];
        foreach (['Bien', 'Equipement', 'Stock', 'Contrat', 'Intervention', 'PlanMaintenance', 'ReleverEnergie'] as $t) {
            $m = doc_modules_de($t);
            if ($m !== null && ($visionneur || array_intersect($m, $modules))) $types[] = $t;
        }
        $ou = []; $p = ['sl_uid' => (int)($user['Id'] ?? 0), 'sl_login' => (string)($user['Login'] ?? '')];
        if ($types) {
            $ph = [];
            foreach (array_values($types) as $i => $t) { $ph[] = ':sl_t' . $i; $p['sl_t' . $i] = $t; }
            $ou[] = 'EntiteType IN (' . implode(',', $ph) . ')';
        }
        // Ceux de SES demandes, et de SES déclarations d'inventaire.
        $ou[] = "(EntiteType IN ('Demande', 'DemandesIntervention') AND EntiteId IN (SELECT Id FROM DemandesIntervention WHERE UtilisateurId = :sl_uid))";
        $ou[] = "(EntiteType = 'Inventaire' AND EntiteId IN (SELECT Id FROM DeclarationsInventaire WHERE LOWER(DeclarantLogin) = LOWER(:sl_login)))";
        return ['sql' => implode(' OR ', $ou), 'p' => $p];
    }

    /**
     * Tables des modules que ce rôle consulte : celles d'un jeu présenté par une page qui
     * lui est ouverte (ExtMoteur::lisibleSur) — ou d'un jeu sans page, atteint par ancrage,
     * que le moteur laisse passer lui aussi. Module masqué à ce rôle : rien.
     */
    private static function tablesModulesLisibles(Database $db, array $user): array
    {
        if (!function_exists('cfg') || !(bool)(cfg('extensions', 'actif') ?? false)) return [];
        try {
            require_once __DIR__ . '/extensions/Registre.php';
            $reg = ExtRegistre::pourBase($db, $user);
            $role = (string)($user['Role'] ?? '');
            $out = [];
            foreach ($reg->actives() as $id => $man) {
                if (!$man->estDeclaratif() || !$reg->visiblePour((string)$id, $user)) continue;
                $decl = $man->declaration();
                foreach (array_keys((array)($decl['donnees'] ?? [])) as $jeu) {
                    $pages = array_filter((array)($decl['pages'] ?? []), fn($pg) => ($pg['vue']['source'] ?? null) === $jeu);
                    $ouverte = array_filter($pages, fn($pg) => in_array($role, (array)($pg['roles'] ?? []), true));
                    if (!$pages || $ouverte) $out[] = strtolower(ExtPolitiqueDonnees::prefixeExtension((string)$id) . $jeu);
                }
            }
            return $out;
        } catch (\Throwable $e) {
            return [];   // registre illisible : on n'ouvre rien
        }
    }

    /** Règle de lecture d'une table pour ce compte : true, [sql, p], ou null (aucune ligne). */
    public static function regleLecture(Database $db, string $table, array $user): bool|array|null
    {
        $d = self::droitsLecture($db, $user);
        if (isset($d['*'])) return true;
        $r = $d[strtolower($table)] ?? null;
        return ($r === true || is_array($r)) ? $r : null;   // (PHP 8.1 : pas de type « true » seul)
    }

    /** Condition SQL d'une règle (« AND (…) ») et ses paramètres, à ajouter à une requête. */
    private static function clauseLecture(bool|array $regle): array
    {
        return $regle === true ? ['', []] : [' AND (' . $regle['sql'] . ')', $regle['p']];
    }

    /**
     * Ce qu'un appareil reçoit d'une ligne qu'il a le droit de lire : jetons et secrets
     * jamais ; empreintes de mot de passe des autres comptes seulement pour un gestionnaire
     * (connexion hors ligne de chacun sur un téléphone partagé) ; pour les autres rôles, les
     * comptes d'autrui réduits à l'annuaire, les secrets des connexions Connect retirés et le
     * réglage des procédures d'urgence privé de ses catégories réservées (comme config_check).
     */
    public static function filtrer(string $table, ?array $l, array $user): ?array
    {
        if ($l === null) return null;
        $t = strtolower($table);
        foreach (self::MASQUEES[$t] ?? [] as $c) if (array_key_exists($c, $l)) $l[$c] = null;
        if (self::gerant($user)) return $l;
        foreach (self::MASQUEES_NON_GERANT[$t] ?? [] as $c) if (array_key_exists($c, $l)) $l[$c] = null;
        if ($t === 'utilisateurs' && (int)($l['Id'] ?? 0) !== (int)($user['Id'] ?? 0)) {
            foreach ($l as $c => $v) {
                if (!in_array($c, self::COLONNES_ANNUAIRE, true)) $l[$c] = $c === 'MotDePasse' ? '' : null;
            }
        }
        if ($t === 'configuration' && (string)($l['Cle'] ?? '') === 'urgences_config' && (string)($l['Valeur'] ?? '') !== '') {
            require_once __DIR__ . '/UrgencesAcl.php';
            $cfg = json_decode((string)$l['Valeur'], true);
            if (is_array($cfg) && isset($cfg['categories']) && is_array($cfg['categories'])) {
                $l['Valeur'] = (string)json_encode(urg_filter_config_for_user($cfg, $user), JSON_UNESCAPED_UNICODE);
            }
        }
        return $l;
    }
    private static function filtrerTout(string $table, array $lignes, array $user): array
    {
        return array_map(fn($l) => self::filtrer($table, $l, $user), $lignes);
    }

    /** La ligne $cle de $table, telle que cet appareil peut la voir, ou null (absente ou hors de ses droits). */
    public static function ligneVisible(Database $db, string $table, string $cle, array $user): ?array
    {
        $l = self::lignes($db, $table, [$cle], $user);
        return $l[0] ?? null;
    }

    private static function q(string $nom): string { return '"' . str_replace('"', '', $nom) . '"'; }
    private static function qt(Database $db, string $nom): string
    {
        $drv = $db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($drv === 'mysql') return '`' . str_replace('`', '', $nom) . '`';
        // PostgreSQL : Larka crée ses tables et colonnes sans guillemets, donc en minuscules.
        return self::q($drv === 'pgsql' ? strtolower($nom) : $nom);
    }

    // ═════════════════════════════════════════════════════════════════════════
    //  Lecture : cliché, changements, empreintes
    // ═════════════════════════════════════════════════════════════════════════

    public static function seq(Database $db): int
    {
        return (int)($db->fetchOne('SELECT MAX(Seq) AS s FROM SynchroJournal')['s'] ?? 0);
    }

    /** Nombre de lignes de $table que cet appareil peut lire (écran « tables » de l'app). */
    public static function compter(Database $db, string $table, array $user): int
    {
        $regle = self::regleLecture($db, $table, $user);
        if ($regle === null) return 0;
        [$et, $p] = self::clauseLecture($regle);
        try { return (int)($db->fetchOne('SELECT COUNT(*) AS n FROM ' . self::qt($db, $table) . ' WHERE 1 = 1' . $et, $p)['n'] ?? 0); }
        catch (\Throwable $e) { return 0; }
    }

    /** Une page d'une table, dans l'ordre de la clé — les seules lignes que ce compte peut lire. */
    public static function cliche(Database $db, string $table, $apres, int $limite, array $user): array
    {
        $tables = self::tables($db);
        if (!isset($tables[$table])) throw new \RuntimeException('Table inconnue : ' . $table);
        $cle = $tables[$table]['cle'];
        $regle = self::regleLecture($db, $table, $user);
        if ($regle === null) return ['table' => $table, 'cle' => $cle, 'lignes' => [], 'suite' => false, 'apres' => $apres];
        [$et, $p] = self::clauseLecture($regle);
        $limite = max(1, min(5000, $limite));
        if ($apres !== null && $apres !== '') { $et = ' AND ' . self::qt($db, $cle) . ' > :a' . $et; $p['a'] = $apres; }
        $sql = 'SELECT * FROM ' . self::qt($db, $table) . ' WHERE 1 = 1' . $et . ' ORDER BY ' . self::qt($db, $cle) . ' LIMIT ' . ($limite + 1);
        $lignes = $db->fetchAll($sql, $p);
        $suite = count($lignes) > $limite;
        if ($suite) array_pop($lignes);
        return ['table' => $table, 'cle' => $cle, 'lignes' => self::filtrerTout($table, $lignes, $user), 'suite' => $suite,
                'apres' => $lignes ? end($lignes)[$cle] : $apres];
    }

    /** Empreintes des lignes lisibles d'une table, sur les colonnes communes avec l'app. */
    public static function empreintes(Database $db, string $table, array $colonnesApp, array $user): array
    {
        $tables = self::tables($db);
        if (!isset($tables[$table])) return ['cle' => null, 'colonnes' => [], 'empreintes' => []];
        $cle = $tables[$table]['cle'];
        $cols = array_values(array_intersect($tables[$table]['colonnes'], $colonnesApp));
        $regle = self::regleLecture($db, $table, $user);
        if ($regle === null) return ['cle' => $cle, 'colonnes' => $cols, 'empreintes' => []];
        [$et, $p] = self::clauseLecture($regle);
        $tn = self::texteNumerique($db);
        $out = [];
        foreach ($db->fetchAll('SELECT * FROM ' . self::qt($db, $table) . ' WHERE 1 = 1' . $et, $p) as $l) {
            $out[(string)$l[$cle]] = self::empreinte(self::filtrer($table, $l, $user), $cols, $tn);
        }
        return ['cle' => $cle, 'colonnes' => $cols, 'empreintes' => $out];
    }

    /**
     * Lignes demandées par clé. $user null : usage interne du serveur (lignes brutes) ;
     * sinon, seulement celles que ce compte peut lire, filtrées.
     */
    public static function lignes(Database $db, string $table, array $cles, ?array $user = null): array
    {
        $tables = self::tables($db);
        if (!isset($tables[$table]) || !$cles) return [];
        $cle = $tables[$table]['cle'];
        $et = ''; $p = [];
        if ($user !== null) {
            $regle = self::regleLecture($db, $table, $user);
            if ($regle === null) return [];
            [$et, $p] = self::clauseLecture($regle);
        }
        $out = [];
        foreach (array_chunk(array_values($cles), 400) as $lot) {
            $ph = []; $pl = $p;
            foreach ($lot as $i => $v) { $ph[] = ':sl_k' . $i; $pl['sl_k' . $i] = $v; }
            foreach ($db->fetchAll('SELECT * FROM ' . self::qt($db, $table) . ' WHERE ' . self::qt($db, $cle) . ' IN (' . implode(',', $ph) . ')' . $et, $pl) as $l) $out[] = $l;
        }
        return $user === null ? $out : self::filtrerTout($table, $out, $user);
    }

    /**
     * Changements depuis $depuis : [seq, complet, lignes: [{table, cle, ligne|null}], tables: [noms]].
     * « complet » : le journal ne remonte plus jusque-là (ou premier passage) → comparaison complète.
     * Une ligne sortie des droits du compte part comme supprimée (l'app l'efface) ; une table dont
     * il ne peut rien lire n'est pas citée du tout.
     */
    public static function tirer(Database $db, int $depuis, int $limite, array $user): array
    {
        $seq = self::seq($db);
        $plusAncien = (int)($db->fetchOne('SELECT MIN(Seq) AS s FROM SynchroJournal')['s'] ?? 0);
        if ($depuis > 0 && $plusAncien > 0 && $depuis < $plusAncien - 1) {
            return ['seq' => $seq, 'complet' => true, 'lignes' => [], 'tables' => [], 'suite' => false];
        }
        $limite = max(1, min(2000, $limite));
        // Clés en minuscules : PostgreSQL rend les colonnes sans casse, quel que soit le remappage.
        $entrees = array_map(fn($e) => array_change_key_case($e, CASE_LOWER),
            $db->fetchAll('SELECT Seq, TableNom, Cle FROM SynchroJournal WHERE Seq > :d ORDER BY Seq LIMIT ' . ($limite + 1), ['d' => $depuis]));
        $suite = count($entrees) > $limite;
        if ($suite) array_pop($entrees);
        $fin = $entrees ? (int)end($entrees)['seq'] : $seq;
        $tables = self::tables($db);
        // Le journal garde le nom tel que la requête l'écrivait : la casse varie (PostgreSQL rend
        // les noms en minuscules, le code de Larka les écrit en CamelCase). Rapprochement sans casse.
        $parNom = [];
        foreach (array_keys($tables) as $n) $parNom[strtolower($n)] = $n;
        $parTable = []; $entieres = [];
        foreach ($entrees as $e) {
            $t = $parNom[strtolower((string)$e['tablenom'])] ?? null;
            if ($t === null || self::regleLecture($db, $t, $user) === null) continue;
            if ($e['cle'] === null || $e['cle'] === '') { $entieres[$t] = true; continue; }
            $parTable[$t][(string)$e['cle']] = true;
        }
        $lignes = [];
        foreach ($parTable as $t => $cles) {
            if (isset($entieres[$t])) continue;
            $presentes = [];
            foreach (self::lignes($db, $t, array_keys($cles), $user) as $l) $presentes[(string)$l[$tables[$t]['cle']]] = $l;
            foreach (array_keys($cles) as $c) $lignes[] = ['table' => $t, 'cle' => (string)$c, 'ligne' => $presentes[(string)$c] ?? null];
        }
        return ['seq' => $fin, 'complet' => false, 'lignes' => $lignes, 'tables' => array_keys($entieres), 'suite' => $suite];
    }

    // ═════════════════════════════════════════════════════════════════════════
    //  Réservation d'identifiants
    // ═════════════════════════════════════════════════════════════════════════

    /** Réserve $n identifiants dans $table pour l'appareil : [debut, fin] ou null (table non réservable). */
    public static function reserver(Database $db, int $appareil, string $table, int $n): ?array
    {
        $tables = self::tables($db);
        if (!isset($tables[$table]) || strtolower($tables[$table]['cle']) !== 'id') return null;
        $n = max(10, min(1000000, $n));
        $pdo = $db->getPdo();
        $drv = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        self::$enCours = true;
        try {
            if ($drv === 'sqlite') {
                $ddl = (string)$pdo->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = " . $pdo->quote($table))->fetchColumn();
                if (stripos($ddl, 'AUTOINCREMENT') === false) return null;
                $dejaEnTransaction = $pdo->inTransaction();
                if (!$dejaEnTransaction) $pdo->exec('BEGIN IMMEDIATE');
                try {
                    $max = (int)$pdo->query('SELECT COALESCE(MAX(Id), 0) FROM ' . self::q($table))->fetchColumn();
                    $s = $pdo->query('SELECT seq FROM sqlite_sequence WHERE name = ' . $pdo->quote($table))->fetchColumn();
                    $debut = max($max, (int)$s) + 1;
                    $fin = $debut + $n - 1;
                    if ($s === false) $pdo->exec('INSERT INTO sqlite_sequence (name, seq) VALUES (' . $pdo->quote($table) . ', ' . $fin . ')');
                    else $pdo->exec('UPDATE sqlite_sequence SET seq = ' . $fin . ' WHERE name = ' . $pdo->quote($table));
                    if (!$dejaEnTransaction) $pdo->exec('COMMIT');
                } catch (\Throwable $e) { if (!$dejaEnTransaction) $pdo->exec('ROLLBACK'); throw $e; }
            } elseif ($drv === 'pgsql') {
                $tl = strtolower($table);
                $seq = $pdo->query("SELECT pg_get_serial_sequence('" . str_replace("'", '', $tl) . "', 'id')")->fetchColumn();
                if (!$seq) return null;
                $pdo->beginTransaction();
                $pdo->exec('LOCK TABLE "' . $tl . '" IN SHARE ROW EXCLUSIVE MODE');
                $fin = (int)$pdo->query("SELECT setval('$seq', GREATEST((SELECT last_value FROM $seq), COALESCE((SELECT MAX(id) FROM \"$tl\"), 0)) + $n, true)")->fetchColumn();
                $pdo->commit();
                $debut = $fin - $n + 1;
            } else {
                $a = (int)$pdo->query('SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' . $pdo->quote($table))->fetchColumn();
                if ($a <= 0) return null;
                $max = (int)$pdo->query('SELECT COALESCE(MAX(Id), 0) FROM `' . str_replace('`', '', $table) . '`')->fetchColumn();
                $debut = max($a, $max + 1) + 5;   // marge pour une insertion concurrente
                $fin = $debut + $n - 1;
                $pdo->exec('ALTER TABLE `' . str_replace('`', '', $table) . '` AUTO_INCREMENT = ' . ($fin + 1));
            }
        } finally { self::$enCours = false; }
        $db->execute('INSERT INTO SynchroReserves (AppareilId, TableNom, Debut, Fin, CreeLe) VALUES (:a, :t, :d, :f, :c)',
            ['a' => $appareil, 't' => $table, 'd' => $debut, 'f' => $fin, 'c' => date('Y-m-d H:i:s')]);
        return [$debut, $fin];
    }

    // ═════════════════════════════════════════════════════════════════════════
    //  Écriture : modifications de l'app
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Tables où ce rôle peut écrire par la synchronisation, et sur quelles lignes.
     * true : toutes ; callable($ligne, $user) : la ligne doit lui appartenir.
     *
     * LA PROPRIÉTÉ N'ÉTAIT VÉRIFIÉE QUE SUR LA LIGNE ENVOYÉE PAR L'APPAREIL.
     * La règle lisait « ($apres ?? $serveur)['UtilisateurId'] » : un appareil qui envoyait
     * « UtilisateurId = moi » dans la ligne modifiée passait, quelle que soit la ligne du
     * serveur qu'il visait. Un demandeur s'appropriait ainsi la demande d'autrui, et — la
     * règle des comptes lisant de même l'« Id » envoyé, pas la clé visée — RÉÉCRIVAIT LE
     * MOT DE PASSE DE N'IMPORTE QUEL COMPTE, gestionnaires compris, puis s'y connectait
     * (vérifié : « admin » repris par un demandeur en un seul appel synchro_pousser).
     * La règle porte désormais sur la ligne du SERVEUR (avant) ET sur la ligne qui en
     * résulte (après), et les comptes se désignent par la clé visée (voir refusEcriture).
     */
    public static function droitsEcriture(array $user): array
    {
        if (self::gerant($user)) return ['*' => true];
        $moi = (int)($user['Id'] ?? 0);
        $login = (string)($user['Login'] ?? '');
        $proprietaire = static fn(array $l): bool => (int)($l['UtilisateurId'] ?? 0) === $moi;
        $droits = [
            'demandesintervention'   => $proprietaire,
            'mobilitecarbone'        => $proprietaire,
            'declarationsinventaire' => static fn(array $l): bool => $login !== '' && strcasecmp((string)($l['DeclarantLogin'] ?? ''), $login) === 0,
            // Son propre compte (profil, mot de passe) — désigné par la CLÉ visée, voir refusEcriture.
            'utilisateurs'           => static fn(array $l): bool => (int)($l['Id'] ?? 0) === $moi,
            'documents'              => true,   // rattachement contrôlé dans refusEcriture (api/DocumentsAcl.php)
        ];
        if (($user['Role'] ?? '') === 'Technicien') {
            $droits += ['interventions' => true, 'intervstocklignes' => true, 'stock' => true, 'releverenergie' => true];
        }
        return $droits;
    }

    /**
     * Ce compte peut-il CRÉER des fiches dans $table depuis l'appareil ? (réservation
     * d'identifiants : un demandeur avançait la séquence de n'importe quelle table.)
     */
    public static function peutCreer(array $user, string $table): bool
    {
        $t = strtolower($table);
        if (in_array($t, self::LECTURE_SEULE, true)) return false;
        if (self::gerant($user)) return true;
        return $t !== 'utilisateurs' && isset(self::droitsEcriture($user)[$t]);
    }

    /** Colonnes qu'un appareil ne réécrit jamais : celles qu'il ne reçoit pas (MASQUEES…). */
    private static function colonnesNonEcrites(string $table, array $user): array
    {
        $t = strtolower($table);
        return array_merge(self::MASQUEES[$t] ?? [], self::gerant($user) ? [] : (self::MASQUEES_NON_GERANT[$t] ?? []));
    }

    /** Ligne telle qu'elle sera après la modification : celle du serveur, complétée par l'appareil. */
    private static function apresFusion(?array $serveur, ?array $apres): ?array
    {
        if ($apres === null) return null;
        return array_merge($serveur ?? [], $apres);
    }

    /**
     * Colonnes que $apres change par rapport à $serveur. Comparaison des valeurs canoniques
     * (comme les empreintes : « 7 » et 7 sont égaux sous PostgreSQL), vide et NULL confondus —
     * une app qui renvoie '' pour un champ resté NULL ne « change » rien.
     */
    private static function changees(?array $serveur, ?array $apres, array $colonnes, bool $tn = false): array
    {
        $out = [];
        foreach ($colonnes as $c) {
            if ($apres === null || !array_key_exists($c, $apres)) continue;
            if ($serveur !== null && self::canon($serveur[$c] ?? '', $tn) === self::canon($apres[$c] ?? '', false)) continue;
            $out[] = $c;
        }
        return $out;
    }

    /** Statuts d'une demande encore ouverte (routes/gestion.php, relance). */
    private const DEMANDE_OUVERTE = ['Nouveau', 'En cours', 'Relancé'];
    /** Colonnes d'une demande que seul celui qui la traite écrit (routes/gestion.php : updateDemande est réservé au gestionnaire). */
    private const DEMANDE_TRAITEMENT = ['UtilisateurId', 'Statut', 'CommentaireAdmin', 'DateTraitement', 'Priorite', 'DelaiRelanceJours', 'DateCreation', 'DateDerniereRelance'];
    /** Colonnes d'une déclaration d'inventaire que seul le gestionnaire écrit (validation, refus). */
    private const DECLARATION_TRAITEMENT = ['Statut', 'TraiteParLogin', 'TraiteParNom', 'DateTraitement', 'MotifRefus', 'DeclarantLogin'];
    /** Colonnes de son propre compte qu'un appareil ne change pas : identité et droits. */
    private const COMPTE_PROTEGE = ['Role', 'AccesLecture', 'Actif', 'Login', 'Provider', 'MicrosoftId'];

    /**
     * Raison de refus d'une modification venue d'un appareil, ou null si elle est permise.
     *
     * $serveur : la ligne du serveur visée (brute, null si elle n'existe pas) ; $apres : la
     * ligne envoyée (null pour une suppression). Les règles sont celles du web : on ne touche
     * qu'à ce qui nous appartient, avant ET après, et pas aux champs de celui qui traite.
     */
    private static function refusEcriture(Database $db, array $user, string $table, string $op, string $cle, ?array $serveur, ?array $apres): ?string
    {
        $t = strtolower($table);
        $nonPermis = 'Votre rôle ne permet pas de modifier ces données sur le serveur.';
        if (in_array($t, self::LECTURE_SEULE, true)) return 'Ces données se modifient sur le serveur, pas depuis un appareil.';
        $apresL = self::apresFusion($serveur, $apres);

        // Pour tous les rôles ─────────────────────────────────────────────
        if ($t === 'utilisateurs') {
            $garde = self::gardeCompte($op, $cle, $apres, $serveur, $user);
            if ($garde !== null) return $garde;
        }
        // Réglages de sécurité : leur écran exige une identité confirmée (routes/gestion.php).
        if ($t === 'configuration') {
            foreach ([$serveur, $apresL] as $l) {
                if ($l !== null && str_starts_with(strtolower((string)($l['Cle'] ?? '')), 'securite_')) {
                    return 'Ce réglage de sécurité se change depuis son écran, en ligne.';
                }
            }
        }
        // Document : mêmes contrôles de contenu que le dépôt en ligne (routes/documents.php).
        if ($t === 'documents' && $apres !== null && $op !== 'D') {
            $contenu = self::documentRefuse($apresL ?? []);
            if ($contenu !== null) return $contenu;
        }
        if (self::gerant($user)) return null;

        // Les autres rôles : la table, puis la ligne (avant et après) ─────
        $regle = self::droitsEcriture($user)[$t] ?? false;
        if ($regle === false) return $nonPermis;
        foreach ([$serveur, $apresL] as $l) {
            if ($l !== null && is_callable($regle) && !$regle($l)) return $nonPermis;
        }
        $role = (string)($user['Role'] ?? '');
        $cols = $apres !== null ? array_keys($apres) : [];
        $tn = self::texteNumerique($db);

        if ($t === 'utilisateurs') {
            // La clé visée, pas l'« Id » de la ligne envoyée : c'est elle que l'UPDATE modifie.
            if ((int)$cle !== (int)($user['Id'] ?? 0) || $serveur === null || $op === 'D') return $nonPermis;
            if (self::changees($serveur, $apres, self::COMPTE_PROTEGE, $tn)) {
                return 'L\'appareil ne peut pas changer l\'identifiant, le rôle, les droits ni l\'activation du compte.';
            }
            // « Mot de passe à changer » : levé seulement en changeant le mot de passe.
            if ((int)($serveur['MustChangePassword'] ?? 0) === 1 && array_key_exists('MustChangePassword', $apres ?? [])
                && (int)($apres['MustChangePassword'] ?? 1) !== 1 && !self::changees($serveur, $apres, ['MotDePasse'], $tn)) {
                return 'Le mot de passe doit être changé avant de lever cette obligation.';
            }
        }

        if ($t === 'demandesintervention') {
            if ($op === 'D') return 'Une demande déposée ne se supprime pas depuis un appareil.';
            if ($serveur === null) {
                // Nouvelle demande : déposée, pas encore traitée.
                if (!in_array((string)($apresL['Statut'] ?? 'Nouveau'), ['', 'Nouveau'], true)
                    || trim((string)($apresL['CommentaireAdmin'] ?? '')) !== '' || trim((string)($apresL['DateTraitement'] ?? '')) !== '') {
                    return 'Une nouvelle demande part au statut « Nouveau », sans réponse.';
                }
            } else {
                $ch = self::changees($serveur, $apres, array_intersect($cols, self::DEMANDE_TRAITEMENT), $tn);
                // Seule exception : la relance (routes/gestion.php), demande ouverte et délai écoulé.
                $relance = $ch && !array_diff($ch, ['Statut', 'DateDerniereRelance'])
                    && (string)($apresL['Statut'] ?? '') === 'Relancé'
                    && in_array((string)($serveur['Statut'] ?? ''), self::DEMANDE_OUVERTE, true);
                if ($relance) {
                    $delai = max(1, (int)($serveur['DelaiRelanceJours'] ?? 7));
                    $ref = strtotime((string)(($serveur['DateDerniereRelance'] ?? '') ?: ($serveur['DateCreation'] ?? ''))) ?: 0;
                    if ($ref && (time() - $ref) < $delai * 86400) return "Relance possible $delai jour(s) après la création ou la dernière relance.";
                } elseif ($ch) {
                    return 'Le statut, la réponse et le suivi d\'une demande sont écrits par le gestionnaire.';
                }
                // Le contenu d'une demande déjà prise en charge ne change plus.
                if (!$relance && (string)($serveur['Statut'] ?? '') !== 'Nouveau' && self::changees($serveur, $apres, $cols, $tn)) {
                    return 'Cette demande est prise en charge : elle ne se modifie plus depuis un appareil.';
                }
            }
        }

        if ($t === 'declarationsinventaire') {
            if ($serveur === null) {
                if (!in_array((string)($apresL['Statut'] ?? 'en_attente'), ['', 'en_attente'], true)
                    || trim((string)($apresL['TraiteParLogin'] ?? '')) !== '') return 'Une déclaration part « en attente » ; le gestionnaire la valide.';
            } else {
                if (self::changees($serveur, $apres, array_intersect($cols, self::DECLARATION_TRAITEMENT), $tn)) return 'La validation d\'une déclaration revient au gestionnaire.';
                if ($op === 'D' && (string)($serveur['Statut'] ?? '') !== 'en_attente') return 'Une déclaration traitée ne se supprime pas.';
            }
        }

        if ($t === 'documents') {
            // Rattachement, avant et après : celui que le web permet (doc_peut_ecrire) —
            // SES demandes et SES déclarations ; pour un Technicien, aussi les fiches
            // qu'il écrit par la synchronisation.
            foreach ([$serveur, $apresL] as $l) {
                if ($l === null) continue;
                $type = (string)($l['EntiteType'] ?? '');
                $eid = (int)($l['EntiteId'] ?? 0);
                if ($role === 'Technicien' && in_array($type, self::DOC_TECHNICIEN, true)) continue;
                if (in_array($type, self::DOC_DEMANDE, true) && self::demandeDe($db, $eid, $user)) continue;
                if ($type === self::DOC_INVENTAIRE && self::declarationDe($db, $eid, $user)) continue;
                return 'Ce document n\'est pas rattaché à une de vos demandes.';
            }
        }
        return null;
    }

    /** La demande $id a-t-elle été déposée par ce compte ? */
    private static function demandeDe(Database $db, int $id, array $user): bool
    {
        if ($id <= 0) return false;
        $r = $db->fetchOne('SELECT UtilisateurId FROM DemandesIntervention WHERE Id = :id', ['id' => $id]);
        return $r !== null && (int)($r['UtilisateurId'] ?? 0) === (int)($user['Id'] ?? 0);
    }

    /** La déclaration d'inventaire $id est-elle de ce compte ? */
    private static function declarationDe(Database $db, int $id, array $user): bool
    {
        if ($id <= 0) return false;
        $r = $db->fetchOne('SELECT DeclarantLogin FROM DeclarationsInventaire WHERE Id = :id', ['id' => $id]);
        return $r !== null && strcasecmp((string)($r['DeclarantLogin'] ?? ''), (string)($user['Login'] ?? '')) === 0;
    }

    /**
     * Contrôle du contenu d'un document venu d'un appareil : type autorisé, jamais SVG,
     * signature conforme au type annoncé, taille. Ceux du dépôt en ligne
     * (routes/documents.php) — l'appareil les contournait en écrivant la ligne.
     */
    private static function documentRefuse(array $l): ?string
    {
        $mime = strtolower(trim((string)($l['TypeMime'] ?? '')));
        if ($mime === '' || str_contains($mime, 'svg')) return 'Type de fichier refusé.';
        if (defined('DOC_MIME_AUTORISES') && is_array(DOC_MIME_AUTORISES) && !in_array($mime, array_map('strtolower', DOC_MIME_AUTORISES), true)) {
            return "Type de fichier non autorisé : $mime.";
        }
        $b64 = (string)($l['Donnees'] ?? '');
        $max = (int)((defined('DOC_TAILLE_MAX_MO') ? DOC_TAILLE_MAX_MO : 25) * 1024 * 1024);
        if (strlen($b64) > (int)($max * 4 / 3) + 4) return 'Fichier trop volumineux.';
        $bin = $b64 === '' ? '' : base64_decode($b64, true);
        if ($bin === false) return 'Contenu du document illisible.';
        $sig = substr($bin, 0, 12);
        $attendue = [
            'application/pdf' => str_starts_with($sig, '%PDF'),
            'image/png'       => str_starts_with($sig, "\x89PNG"),
            'image/jpeg'      => str_starts_with($sig, "\xFF\xD8\xFF"),
            'image/jpg'       => str_starts_with($sig, "\xFF\xD8\xFF"),
            'image/gif'       => str_starts_with($sig, 'GIF87a') || str_starts_with($sig, 'GIF89a'),
            'image/webp'      => str_starts_with($sig, 'RIFF') && substr($bin, 8, 4) === 'WEBP',
        ];
        if ($bin !== '' && array_key_exists($mime, $attendue) && !$attendue[$mime]) return 'Le contenu du fichier ne correspond pas au type annoncé.';
        return null;
    }

    /**
     * Applique une liste de modifications : [['table','cle','op','avant','apres','provisoire'?,'forcer'?]].
     * Rend ['appliquees' => [...], 'conflits' => [...], 'refus' => [...], 'nouvelles' => [cleProvisoire => cle]].
     * La « ligne_serveur » d'un refus ou d'un conflit n'est rendue que si l'appareil peut la lire.
     */
    public static function pousser(Database $db, array $appareil, array $user, array $modifs): array
    {
        $tables = self::tables($db);
        $tn = self::texteNumerique($db);
        $pdo = $db->getPdo();
        $res = ['appliquees' => [], 'conflits' => [], 'refus' => [], 'nouvelles' => []];
        self::$appareilCourant = (int)$appareil['Id'];
        $pdo->beginTransaction();
        try {
            foreach ($modifs as $m) {
                $t = (string)($m['table'] ?? ''); $cle = (string)($m['cle'] ?? ''); $op = (string)($m['op'] ?? '');
                $ref = ['table' => $t, 'cle' => $cle, 'op' => $op];
                if (!isset($tables[$t]) || !in_array($op, ['I', 'U', 'D'], true) || $cle === '') { $res['refus'][] = $ref + ['raison' => 'Table ou opération inconnue du serveur.']; continue; }
                $kc = $tables[$t]['cle'];
                // Colonnes du serveur, moins celles que l'appareil ne reçoit pas : il ne les réécrit pas.
                $colsServeur = array_values(array_diff($tables[$t]['colonnes'], self::colonnesNonEcrites($t, $user)));
                $avant = is_array($m['avant'] ?? null) ? array_intersect_key($m['avant'], array_flip($colsServeur)) : null;
                $apres = is_array($m['apres'] ?? null) ? array_intersect_key($m['apres'], array_flip($colsServeur)) : null;
                $forcer = !empty($m['forcer']);
                $provisoire = !empty($m['provisoire']);
                // Ligne du serveur, brute : c'est sur elle que portent les droits.
                $serveur = $provisoire ? null : ($db->fetchOne('SELECT * FROM ' . self::qt($db, $t) . ' WHERE ' . self::qt($db, $kc) . ' = :c', ['c' => $cle]) ?: null);
                $actuelle = self::filtrer($t, $serveur, $user);
                $visible = fn() => $serveur === null ? null : self::ligneVisible($db, $t, $cle, $user);

                $raison = self::refusEcriture($db, $user, $t, $op, $cle, $serveur, $apres);
                if ($raison !== null) { $res['refus'][] = $ref + ['raison' => $raison, 'ligne_serveur' => $visible()]; continue; }

                $pdo->exec('SAVEPOINT synchro_ligne');
                try {
                    $conflit = null;
                    $doublon = ($op === 'I' && $apres !== null && !$forcer && empty($m['doublon_ok']) && ($provisoire || $actuelle === null))
                        ? self::doublon($db, $t, $kc, $cle, $apres, array_map('strval', (array)($m['connus'] ?? [])), $tn, $user) : null;
                    if ($doublon) {
                        $conflit = $doublon;
                        $actuelle = $doublon['ligne'];
                        unset($conflit['ligne']);
                    } elseif ($op === 'I') {
                        if ($apres === null) throw new \RuntimeException('Ligne absente.');
                        if ($provisoire) {
                            unset($apres[$kc]);
                            self::inserer($db, $t, $apres);
                            $res['nouvelles'][$cle] = (string)$db->dernierIdInsere();
                        } elseif ($actuelle !== null && !$forcer) {
                            if (self::empreinte($actuelle, array_keys($apres), $tn) !== self::empreinte($apres, array_keys($apres), false)) {
                                $conflit = ['type' => 'existe', 'champs' => self::champsDifferents($apres, $actuelle, $tn)];
                            }
                        } elseif ($actuelle !== null) {
                            self::mettreAJour($db, $t, $kc, $cle, array_diff_key($apres, [$kc => 1]));
                        } else {
                            self::inserer($db, $t, $apres);
                        }
                    } elseif ($op === 'U') {
                        if ($apres === null) throw new \RuntimeException('Ligne absente.');
                        if ($actuelle === null) {
                            if ($forcer) self::inserer($db, $t, $apres);
                            else $conflit = ['type' => 'supprime_serveur', 'champs' => []];
                        } else {
                            $changes = []; $champsConflit = [];
                            foreach ($apres as $c => $v) {
                                if ($c === $kc) continue;
                                $va = $avant[$c] ?? null;
                                if (self::canon($va, false) === self::canon($v, false)) continue;     // pas changé par l'app
                                $vs = $actuelle[$c] ?? null;
                                if ($forcer || self::canon($vs, $tn) === self::canon($va, false) || self::canon($vs, $tn) === self::canon($v, false)) { $changes[$c] = $v; continue; }
                                if (in_array($c, self::COMPTEURS[$t] ?? [], true) && is_numeric($va) && is_numeric($v) && is_numeric($vs)) {
                                    $changes[$c] = $vs + ($v - $va);   // les deux mouvements sont gardés
                                    continue;
                                }
                                $champsConflit[] = ['champ' => $c, 'avant' => $va, 'telephone' => $v, 'serveur' => $vs];
                            }
                            if ($champsConflit) $conflit = ['type' => 'champs', 'champs' => $champsConflit];
                            elseif ($changes) self::mettreAJour($db, $t, $kc, $cle, $changes);
                        }
                    } else { // D
                        if ($actuelle !== null) {
                            $modifieServeur = !$forcer && $avant !== null
                                && self::empreinte($actuelle, array_keys($avant), $tn) !== self::empreinte($avant, array_keys($avant), false);
                            if ($modifieServeur) $conflit = ['type' => 'modifie_serveur', 'champs' => self::champsDifferents($avant, $actuelle, $tn)];
                            else $db->execute('DELETE FROM ' . self::qt($db, $t) . ' WHERE ' . self::qt($db, $kc) . ' = :c', ['c' => $cle]);
                        }
                    }
                    $pdo->exec('RELEASE SAVEPOINT synchro_ligne');
                    if ($conflit) {
                        // Ligne rendue pour arbitrer : seulement si l'appareil peut la lire.
                        $vue = ($conflit['type'] ?? '') === 'doublon'
                            ? ($actuelle !== null ? self::ligneVisible($db, $t, (string)($conflit['doublon_de'] ?? ''), $user) : null)
                            : $visible();
                        if ($vue === null && ($conflit['type'] ?? '') !== 'supprime_serveur') $conflit['champs'] = [];
                        $res['conflits'][] = $ref + $conflit + ['ligne_serveur' => $vue];
                    }
                    else $res['appliquees'][] = $ref;
                } catch (\Throwable $e) {
                    $pdo->exec('ROLLBACK TO SAVEPOINT synchro_ligne');
                    $pdo->exec('RELEASE SAVEPOINT synchro_ligne');
                    $res['refus'][] = $ref + ['raison' => 'Le serveur n\'a pas pu enregistrer cette ligne : ' . mb_substr($e->getMessage(), 0, 200), 'ligne_serveur' => $visible()];
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        } finally { self::$appareilCourant = null; }
        return $res;
    }

    /** Fiche du serveur qui a le même identifiant (et n'est pas connue de l'appareil), sous forme de conflit, ou null. */
    private static function doublon(Database $db, string $t, string $kc, string $cle, array $apres, array $connus, bool $tn, array $user): ?array
    {
        $regle = null;
        foreach (self::DOUBLONS as $nom => $r) if (strcasecmp($nom, $t) === 0) $regle = $r;
        if (!$regle) return null;
        [$champs, $portee] = $regle;
        $ou = []; $params = ['cle' => $cle]; $i = 0;
        foreach ($champs as $c) {
            $v = trim((string)($apres[$c] ?? ''));
            if ($v === '') continue;
            $ou[] = 'LOWER(TRIM(' . self::qt($db, $c) . ')) = :v' . $i;
            $params['v' . $i++] = mb_strtolower($v);
        }
        if (!$ou) return null;
        $et = [];
        foreach ($portee as $c) {
            if (($apres[$c] ?? null) === null) $et[] = self::qt($db, $c) . ' IS NULL';
            else { $et[] = self::qt($db, $c) . ' = :p' . $i; $params['p' . $i++] = $apres[$c]; }
        }
        $sql = 'SELECT * FROM ' . self::qt($db, $t) . ' WHERE ' . self::qt($db, $kc) . ' <> :cle AND (' . implode(' OR ', $ou) . ')'
             . ($et ? ' AND ' . implode(' AND ', $et) : '') . ' ORDER BY ' . self::qt($db, $kc) . ' DESC LIMIT 10';
        foreach ($db->fetchAll($sql, $params) as $l) {
            if (in_array((string)$l[$kc], $connus, true)) continue;   // l'appareil la connaissait : deux fiches voulues
            $memes = [];
            foreach ($champs as $c) if (trim((string)($apres[$c] ?? '')) !== '' && mb_strtolower(trim((string)($apres[$c] ?? ''))) === mb_strtolower(trim((string)($l[$c] ?? '')))) $memes[] = $c;
            $l = self::filtrer($t, $l, $user);
            return ['type' => 'doublon', 'champs' => array_values(array_filter(self::champsDifferents($apres, $l, $tn), fn($x) => $x['champ'] !== $kc)), 'ligne' => $l,
                    'raison' => 'Même ' . implode(' et même ', array_map(fn($c) => self::NOMS_CHAMPS[$c] ?? $c, $memes)) . ' qu\'une fiche créée sur le serveur entre-temps (fiche n° ' . $l[$kc] . ')', 'doublon_de' => (string)$l[$kc]];
        }
        return null;
    }

    /** Raison de refus d'une écriture de compte venue d'un appareil, ou null. */
    private static function gardeCompte(string $op, string $cle, ?array $apres, ?array $actuelle, array $user): ?string
    {
        if ($op === 'D') return $actuelle === null ? null : 'Un compte du serveur ne se supprime pas depuis un appareil : désactivez-le ou supprimez-le sur le serveur.';
        if ($actuelle === null || $apres === null || (int)$cle !== (int)($user['Id'] ?? 0)) return null;
        foreach (['Login' => 'l\'identifiant', 'Role' => 'le rôle', 'Actif' => 'l\'activation'] as $c => $quoi) {
            if (array_key_exists($c, $apres) && (string)($apres[$c] ?? '') !== (string)($actuelle[$c] ?? '')) {
                return "L'appareil ne peut pas changer $quoi du compte auquel il est relié (« " . ($actuelle['Login'] ?? '') . ' »).';
            }
        }
        return null;
    }

    private static function champsDifferents(array $a, array $serveur, bool $tn): array
    {
        $out = [];
        foreach ($a as $c => $v) {
            if (!array_key_exists($c, $serveur)) continue;
            if (self::canon($v, false) !== self::canon($serveur[$c], $tn)) $out[] = ['champ' => $c, 'telephone' => $v, 'serveur' => $serveur[$c]];
        }
        return $out;
    }
    private static function inserer(Database $db, string $t, array $l): void
    {
        $cols = array_keys($l);
        $db->execute('INSERT INTO ' . self::qt($db, $t) . ' (' . implode(',', array_map(fn($c) => self::qt($db, $c), $cols)) . ') VALUES ('
            . implode(',', array_map(fn($i) => ':p' . $i, array_keys($cols))) . ')', array_combine(array_map(fn($i) => 'p' . $i, array_keys($cols)), array_values($l)));
    }
    private static function mettreAJour(Database $db, string $t, string $kc, string $cle, array $l): void
    {
        if (!$l) return;
        $cols = array_keys($l);
        $p = array_combine(array_map(fn($i) => 'p' . $i, array_keys($cols)), array_values($l));
        $p['cle'] = $cle;
        $db->execute('UPDATE ' . self::qt($db, $t) . ' SET ' . implode(',', array_map(fn($i, $c) => self::qt($db, $c) . ' = :p' . $i, array_keys($cols), $cols))
            . ' WHERE ' . self::qt($db, $kc) . ' = :cle', $p);
    }

    // ═════════════════════════════════════════════════════════════════════════
    //  Fichiers
    // ═════════════════════════════════════════════════════════════════════════

    /** Dossier réel d'un dossier synchronisé (propre à l'organisation en multi-tenant). */
    private static function dossierReel(string $dossier): string
    {
        $sous = '';
        if (class_exists('ExtPaquet') || is_file(__DIR__ . '/extensions/Paquet.php')) {
            require_once __DIR__ . '/extensions/Paquet.php';
            try { $sous = ExtPaquet::sousCheminTenant(); } catch (\Throwable $e) { $sous = ''; }
        }
        return dirname(__DIR__) . '/data/' . $dossier . $sous;
    }

    /**
     * Chemin « data/<dossier>/… » de l'app → chemin réel, ou null s'il sort des dossiers
     * synchronisés — ou, avec $db et $user, d'un fichier que ce compte ne peut pas lire.
     */
    public static function cheminReel(string $chemin, ?Database $db = null, ?array $user = null): ?string
    {
        if (!preg_match('#^data/(' . implode('|', self::DOSSIERS) . ')/(.+)$#', $chemin, $m)) return null;
        $rel = $m[2];
        if (str_contains($rel, '..') || str_contains($rel, "\0") || str_starts_with($rel, '/')) return null;
        foreach (explode('/', $rel) as $seg) if ($seg === '' || $seg[0] === '.') return null;
        if (self::fichierLivre($m[1], $rel)) return null;
        if ($db !== null && $user !== null && !self::fichierLisible($db, $user, $m[1], $rel)) return null;
        return self::dossierReel($m[1]) . '/' . $rel;
    }

    /**
     * Fichier lisible par ce compte ? Les médias des procédures d'urgence suivent la règle
     * de la route urgences_media (api/UrgencesAcl.php) : ceux d'une catégorie réservée aux
     * gestionnaires — et ceux qu'aucune catégorie ne cite — ne partent pas sur le téléphone
     * d'un autre rôle. Ils y partaient tous.
     */
    private static function fichierLisible(Database $db, array $user, string $dossier, string $rel): bool
    {
        if ($dossier !== 'urgences' || self::gerant($user)) return true;
        require_once __DIR__ . '/UrgencesAcl.php';
        return !str_contains($rel, '/') && !urg_media_is_restricted($db, $rel);
    }

    /**
     * Un appareil peut-il ÉCRIRE (ou supprimer) ce fichier sur le serveur ? Raison du refus, ou null.
     *
     * L'APPAREIL D'UN GESTIONNAIRE ÉCRIVAIT N'IMPORTE QUEL FICHIER DANS CES DOSSIERS.
     * Un SVG à script dans data/plans, servi ensuite en ligne par les routes des plans ;
     * un extension.json de module réécrit sans passer par l'examen du paquet ni
     * l'acceptation de ses capacités ; un thème remplacé de même. Les plans et les médias
     * d'urgence reçoivent les seuls formats que leur écran accepte ; modules et thèmes
     * s'installent par leur écran (paquet vérifié), pas en recopiant leurs fichiers.
     */
    public static function envoiRefuse(string $chemin): ?string
    {
        if (!preg_match('#^data/(' . implode('|', self::DOSSIERS) . ')/(.+)$#', $chemin, $m)) return 'Emplacement non synchronisé.';
        $ext = strtolower(pathinfo($m[2], PATHINFO_EXTENSION));
        if ($m[1] === 'plans') {
            return in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'pdf'], true) ? null : 'Plans : PNG, JPG, GIF, WebP ou PDF seulement.';
        }
        if ($m[1] === 'urgences') {
            require_once __DIR__ . '/UrgencesAcl.php';
            return (urg_media_name_ok($m[2]) && isset(urg_ext_map()[$ext])) ? null : 'Procédures d\'urgence : photo ou vidéo seulement.';
        }
        return 'Les modules et les thèmes s\'installent depuis leur écran, sur le serveur.';
    }

    /**
     * Modules livrés avec Larka (« larka.* ») : chaque côté a ceux de sa version (fichiers sur le
     * serveur, embarqués dans l'app). Jamais comparés ni copiés.
     */
    public static function fichierLivre(string $dossier, string $rel): bool
    {
        return $dossier === 'extensions' && (bool)preg_match('#^code/larka\.[^/]+/#', $rel);
    }

    /** Inventaire des fichiers synchronisés : [chemin app => [taille, date]] — ceux que ce compte peut lire. */
    public static function fichiers(?Database $db = null, ?array $user = null): array
    {
        $out = [];
        foreach (self::DOSSIERS as $d) {
            $base = self::dossierReel($d);
            if (!is_dir($base)) continue;
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if (!$f->isFile()) continue;
                $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($base) + 1));
                // Dossiers d'autres organisations (multi-tenant, emplacement commun), fichiers cachés : ignorés.
                if ($rel === '' || str_starts_with($rel, 'tenants/') || preg_match('#(^|/)\.#', $rel)) continue;
                if (str_starts_with($rel, 'paquets/') || str_starts_with($rel, 'tmp/') || self::fichierLivre($d, $rel)) continue;
                if ($db !== null && $user !== null && !self::fichierLisible($db, $user, $d, $rel)) continue;
                $out['data/' . $d . '/' . $rel] = [(int)$f->getSize(), (int)$f->getMTime()];
            }
        }
        ksort($out);
        return $out;
    }
}

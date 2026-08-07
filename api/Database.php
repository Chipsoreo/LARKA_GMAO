<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * Larka — Classe Database (couche d'accès aux données)
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Abstraction PDO multi-driver : SQLite, PostgreSQL, MariaDB/MySQL.
 *
 * RESPONSABILITÉS :
 *   - Connexion PDO selon le driver configuré
 *   - Création automatique du schéma (CREATE TABLE IF NOT EXISTS)
 *   - Migrations automatiques (ALTER TABLE pour nouvelles colonnes)
 *   - Insertion des données par défaut (listes, utilisateur admin)
 *
 * SECTIONS :
 *   - Configuration    → getConfig(), setConfig(), getAllConfig()
 *   - Listes           → getListe(), getAllListes(), add/update/deleteListeValeur()
 *   - Utilisateurs     → login, findOrCreate, CRUD comptes
 *   - Biens            → getAllBiens(), addBien(), updateBien(), deleteBien()
 *   - Équipements      → idem biens + marque/modèle/fournisseur
 *   - Contrats         → CRUD + contacts multiples (ContactsJSON)
 *   - Interventions    → CRUD + auto-numéro + sync statut demande
 *   - Stock            → CRUD + consommer/restituer quantités
 *   - Gestion matériel     → fiches d'immobilisation + clôture
 *   - Demandes         → CRUD + relance + comptage par statut
 *   - Documents        → upload/download binaire (BLOB ou filesystem)
 *   - Énergie          → compteurs + relevés
 *   - Archives         → boîtes + dossiers + bordereaux
 *   - Assistant IA     → searchForAssistant() + getAssistantStats()
 *
 * HELPERS INTERNES :
 *   - isPg(), isMysql()        → détection du driver
 *   - sqlYearMonth(), sqlDatePlusCol() → SQL portable
 *   - fetchAll(), fetchOne()   → requêtes paramétrées
 *   - execute()                → INSERT/UPDATE/DELETE
 * ═══════════════════════════════════════════════════════════════════════════════
 */

require_once __DIR__ . '/config.php';

class Database {

    private PDO $pdo;
    private string $driver;
    /** @var array<string,string> lowercase→CamelCase column name map (PostgreSQL only) */
    private array $colMap = [];

    /**
     * Registre statique : config DB du tenant courant.
     * Alimenté par config.php après résolution du tenant.
     * Quand new Database() est appelé sans argument, cette config est utilisée.
     */
    private static ?array $_tenantConfig = null;

    public static function setTenantConfig(?array $cfg): void {
        self::$_tenantConfig = $cfg;
    }

    public static function getTenantConfig(): ?array {
        return self::$_tenantConfig;
    }

    /** Expose la connexion PDO interne (pour monitoring superadmin) */
    public function getPdo(): PDO {
        return $this->pdo;
    }

    public function getDriver(): string {
        return $this->driver;
    }

    public function __construct(?array $dbConfig = null, bool $skipSeeds = false) {
        $this->_skipSeeds = $skipSeeds;
        // Priorité : argument explicite > registre tenant > constantes PHP
        if ($dbConfig === null && self::$_tenantConfig !== null) {
            $dbConfig = self::$_tenantConfig;
        }

        $driver = $dbConfig['driver'] ?? DB_DRIVER;
        $this->driver = $driver;

        // ⚠️ FIX BUG MAJEUR (purge + multi-tenant) : identifiant UNIQUE par base
        // PHYSIQUE, utilisé pour nommer le fichier sentinelle de schéma. Avant, la
        // sentinelle était keyée sur driver + tenant_key DE SESSION. Or quand le
        // super admin opère sur plusieurs bases tenant via new Database($cfg), le
        // tenant_key de session est identique (souvent 'default') → toutes les
        // bases d'un même driver PARTAGEAIENT la même sentinelle. Conséquences :
        //   (1) dès qu'une base était marquée « à jour », les AUTRES bases ne
        //       créaient plus leurs tables (court-circuit) → tables manquantes ;
        //   (2) après une purge (DROP des tables), la recréation était
        //       court-circuitée → « relation "utilisateurs" does not exist ».
        // On identifie désormais la base par sa cible réelle (chemin / hôte+base).
        if ($driver === 'sqlite') {
            $this->_dbIdent = 'sqlite:' . ($dbConfig['path'] ?? DB_PATH);
        } else {
            $this->_dbIdent = $driver . ':'
                . ($dbConfig['host'] ?? DB_HOST) . ':'
                . ($dbConfig['port'] ?? DB_PORT) . '/'
                . ($dbConfig['dbname'] ?? DB_NAME);
        }

        if ($driver === 'sqlite') {
            $path = $dbConfig['path'] ?? DB_PATH;
            if (!str_starts_with($path, '/')) $path = __DIR__ . '/../' . $path;
            $dir = dirname($path);
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $this->pdo = new PDO('sqlite:' . $path);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->pdo->exec("PRAGMA foreign_keys = ON;");
            $this->pdo->exec("PRAGMA journal_mode = WAL;");

        } elseif ($driver === 'pgsql') {
            // connect_timeout borne la connexion : PostgreSQL ignore PDO::ATTR_TIMEOUT
            // au moment du connect, donc sans ce paramètre une base injoignable
            // bloquerait new PDO() pendant des dizaines de secondes (page figée).
            $dsn = sprintf(
                'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s;connect_timeout=5',
                $dbConfig['host'] ?? DB_HOST,
                $dbConfig['port'] ?? DB_PORT,
                $dbConfig['dbname'] ?? DB_NAME,
                $dbConfig['sslmode'] ?? DB_SSLMODE
            );
            $this->pdo = new PDO($dsn, $dbConfig['user'] ?? DB_USER, $dbConfig['password'] ?? DB_PASS, [
                PDO::ATTR_TIMEOUT => 5,
            ]);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->pdo->exec("SET client_encoding = 'UTF8'");

        } elseif ($driver === 'mariadb' || $driver === 'mysql') {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $dbConfig['host'] ?? DB_HOST,
                $dbConfig['port'] ?? DB_PORT,
                $dbConfig['dbname'] ?? DB_NAME
            );
            $this->pdo = new PDO($dsn, $dbConfig['user'] ?? DB_USER, $dbConfig['password'] ?? DB_PASS, [
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
            ]);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        } else {
            throw new \RuntimeException("Driver DB inconnu : $driver. Valeurs : sqlite, pgsql, mariadb.");
        }

        // ⚠️ FIX PERFS : ne rejouer les migrations + DDL d'index QUE si le schéma
        // n'était pas déjà à jour. Avant, migrateRoles(), l'ALTER TABLE et les 11
        // CREATE INDEX se rejouaient à CHAQUE requête (l'ALTER échouait
        // systématiquement et était rattrapé → round-trip + exception inutiles),
        // ce qui annulait en partie le court-circuit de initTables(). On capture
        // l'état AVANT initTables() (qui pose le flag), avec la même condition que
        // le court-circuit interne de initTables().
        $schemaWasCurrent = $this->schemaIsCurrent() && !$this->_skipSeeds;
        $this->initTables();
        if (!$schemaWasCurrent) {
            $this->migrateRoles();
            try { $this->pdo->exec("ALTER TABLE Utilisateurs ADD COLUMN Provider TEXT DEFAULT 'local'"); } catch(\Exception $e) {}
            // Note: Provider auto-detection only runs if not already set by OAuth login
            // ⚠️ FIX PERFS : indexes critiques manquants — ajoutés après les tables.
            $this->ensureCriticalIndexes();
        }

        // Build lowercase→CamelCase column map for PostgreSQL
        if ($this->driver === 'pgsql') {
            $this->buildColumnMap();
        }
    }

    /**
     * Build a mapping of lowercase column names → original CamelCase names.
     * PostgreSQL stores everything as lowercase but our JS frontend expects CamelCase.
     */
    private function buildColumnMap(): void {
        $camelCols = [
            'Id','Cle','Valeur','Description','Categorie','Ordre','Actif','Obligatoire','SaisieLibre',
            'Nom','Prenom','Login','Email','Tel','MotDePasse','Role','Provider','DateCreation',
            'ResetToken','ResetTokenExpiry','Service','Poste',
            'MicrosoftId','PhotoUrl','OfficeLocation','CompanyName','ManagerName',
            'TelMobile','TelPro','MustChangePassword',
            'Numero','Famille','SousFamille','Statut','NumeroSerie','Etat','DateCommande','DateLivraison',
            'DateSuppression','Prix','InfoProduit','Batiment','Etage','NumeroBureau','NomPrenom','Documents',
            'CommentaireSortie','DateSortie','CreatedAt','CreatedBy','UpdatedAt','UpdatedBy',
            'DateInstallation','Marque','Modele','Fournisseur','Observations',
            'Societe','Type','Perimetre','EquipementsIds','DateDebut','DateFin','AlerteJoursAvant',
            'MontantAnnuel','Frequence','ContactNom','ContactTel','ContactEmail','ContactsJSON','Commentaire',
            'OptionTarifaire','PuissanceSouscrite','PrixBaseHT','PrixHPHT','PrixHCHT','AbonnementHT',
            'FournisseurEnergie','Fluide','HCDebut','HCFin','AbonnementTTC','PrixBaseTTC','TVARate',
            'ChauffageR1','ChauffageR2','PrixHPTTC','PrixHCTTC','TVAKwh','TVAAbo',
            'HtaHPHS','HtaHPHSTTC','HtaHCHS','HtaHCHSTTC','HtaHPBS','HtaHPBSTTC','HtaHCBS','HtaHCBSTTC',
            'HtaCEE','HtaCEETTC','HtaCapa','HtaCapaTTC','HtaTVALignes',
            'ReferentClient','ReferenceContrat','TypeCompteur',
            'Reference','Designation','TypeArticle','Quantite','SeuilAlerte','PrixUnitaire','Emplacement','Source','ContratId','FamilleLien',
            'Priorite','TypeLiaison','BienId','DemandeId','SocieteManuelle','DateRealisation',
            'DureeHeures','Montant','MontantPieces','MontantMainOeuvre','EstRecurrente','Recurrence',
            'DateProchaine','InterventionParentId','Statut2','DateValidation','DateArchivage',
            'Titre','Bureau','Urgence','CommentaireAdmin','UtilisateurId','NomDeclarant','DateTraitement',
            'DelaiRelanceJours','DateDerniereRelance','EmailDemandeur','TelDemandeur',
            'PourAutrui','NomAutrui','EmailAutrui','TelAutrui',
            'InterventionId','StockId','DateConsommation',
            'AssetType','AssetId','NumeroImmo','CompteImmo','Exercice','BonDeCommande','DateMiseEnService',
            'NumeroMandat','DateAchat','ValeurAchat','ValeurVenale','DureeAmortissement','MontantDuMarche',
            'DateBascule','AnnualiteAmortissement','DepreciationTotal','DateCalculDepreciation',
            'User7','Item','EstCloture','DateCloture','MotifCloture','ClotureParLogin',
            'DelaiAffichageDashboard','HistoriqueModifDates',
            'AgentNom','AgentPrenom','AgentTel','AgentEmail',
            'DateDemande','DateIntervention','RelanceChamp','RelanceDate',
            'TypeBudget','DateEnvoiMail','CodeNumeroDemande','NotesJSON','SansSuiviAdmin',
            'DemandeTitre','BienNumero','BienLabel','ContratSociete',
            'BienFamille','BienSousFamille','BienInfoProduit',
            'SharePointUrl','SharePointDriveItemId','SharePointDriveId','SharePointSiteId','SharePointLastSync',
            'NumeroDevis','MontantTTC','Accepte','Raison',
            'TypeLocalisation','ArchiveData',
            'AssetNumero','AssetLabel','AssetEtat','AssetCommentaireSortie',
            'AssetCreatedAt','AssetCreatedBy','AssetUpdatedAt','AssetUpdatedBy',
            'Unite','Site','NumeroCompteur','PrixTTC','PrixHT','PrixHP','PrixHC','Abonnement',
            'PrixUnitaireTTC',
            'CompteurId','Date','IndexDebut','IndexFin','Consommation','NumeroFacture',
            'PeriodeDebut','PeriodeFin','SaisieParLogin','DateSaisie','ModeFacture','DetailHTA',
            'PlagesAvance','Carbone','MontantHT','PrixUnitHT','TVAReleve','TVA',
            'EntiteType','EntiteId','NomFichier','TypeMime','Taille','Donnees','DateAjout','AjoutePar',
            'TableSource','IdOriginal','SupprimeParLogin',
            'Message','CreePar',
            'UserId','Endpoint','P256dh','Auth','Platform','UserAgent','LastUsedAt',
            'AffecteUserId',
            'NumeroBien','DeclarantLogin','DeclarantNom','TraiteParLogin','TraiteParNom',
            'DateDeclaration','MotifRefus','RaisonDepart',
            'DestBatiment','DestEtage','DestBureau','DestPersonne','PersonneDeclaree',
            'AffecteActuel','BatimentActuel','EtageActuel','BureauActuel',
            'Annee','FacteurCO2','IdentifiantADEME','NomADEME','Incertitude','DateMaj',
            'DetailChauffage','DetailSousCompteurs','SousCompteurs','RepartitionPresta','ChauffageTED',
            'IdReseau','Gestionnaire','CO2','CO2ACV','TauxEnRR','TVAChauffage',
            // Plans interactifs
            'BatimentId','Adresse','Latitude','Longitude',
            'Niveau','FondImage','FondLargeur','FondHauteur','Echelle',
            'EtageId','TypeElement','SousType','Calque','Couleur','Opacite','Coords','Icone',
            'RefUtilisateurId','PersonneData','EstPersonne','PartagePresencePlan','MasquerPresence', // remappage PG
            'ElementId',
            // ⚠️ FIX : alias d'agrégats des requêtes de stats. Sous PostgreSQL,
            // « COUNT(*) as Total » revient en « total » (minuscule). Sans ces
            // entrées, remapRow() ne les repassait pas en CamelCase et le front
            // lisait r.Total = undefined → toutes les valeurs de stats à 0.
            'Total','Cout','Nb','CoutPieces','MontantTotal','QteTotal','Articles',
            'ValeurTotale','IntervNumero','CompteurNom','Methode','Mois',
            // ⚠️ FIX : facturation des interventions (tables Factures +
            // FactureInterventions). Sans ces entrées, sous PostgreSQL les
            // colonnes/alias reviennent en minuscules (numeroej, dateej,
            // montantligne, liaisonid…) et le front lisait undefined → le
            // bouton « détacher » envoyait un id null (erreur serveur).
            'NumeroEJ','DateEJ','DateFacture','FactureId','MontantLigne','Libelle',
            'LiaisonId','NbInterventions','TotalVentile','TotalFacture','NbFactures',
            'CbdcFactures','NbInterventionsLiees','AutresInterventions','NbInterv','DateReception',
            // ⚠️ FIX : onglets consultables par un demandeur. Sans cette entrée,
            // sous PostgreSQL la colonne revenait en « acceslecture » et la fiche
            // lisait u.AccesLecture = undefined : les cases se réaffichaient
            // décochées après un enregistrement pourtant réussi.
            'AccesLecture',
        ];
        foreach ($camelCols as $col) {
            $this->colMap[strtolower($col)] = $col;
        }
    }

    /**
     * Remap array keys from PostgreSQL lowercase to CamelCase
     */
    private function remapRow(array $row): array {
        if (empty($this->colMap)) return $row;
        $mapped = [];
        foreach ($row as $k => $v) {
            $mapped[$this->colMap[$k] ?? $k] = $v;
        }
        return $mapped;
    }

    // ── Migration automatique des anciens rôles ───────────────────────────────
    private function migrateRoles(): void {
        // Les couples old=>new sont des littéraux internes (pas d'entrée
        // utilisateur), mais on requête en paramétré par hygiène : aucune valeur
        // n'est interpolée dans le SQL, ce qui évite tout risque si cette table
        // de correspondance venait un jour à inclure des données externes.
        foreach (['SuperAdmin'=>'Gestionnaire','Admin'=>'Gestionnaire','Utilisateur'=>'Gestionnaire','Spectateur'=>'Visionneur','Nouveau'=>'Demandeur'] as $old=>$new) {
            $this->execute(
                "UPDATE Utilisateurs SET Role = :new WHERE Role = :old",
                ['new' => $new, 'old' => $old]
            );
        }
    }

    /**
     * Force l'initialisation complète du schéma : tables principales + toutes les
     * migrations lazy (Energie, Carbone, etc.). À utiliser notamment avant un
     * import de BDD pour que toutes les colonnes soient présentes.
     */
    public function ensureFullSchema(): void {
        $this->initTables();
        $this->initEnergieTable();
        $this->initCarboneTable();
    }

    /**
     * Comme ensureFullSchema() mais sans aucun INSERT de données par défaut
     * (pas de seed des Listes, pas de compte admin initial, etc.).
     * À utiliser avant un import où le dump va fournir ses propres données
     * avec des ids précis qui entreraient en collision avec les seeds.
     *
     * IMPORTANT : pour que ça fonctionne dès le constructeur, il faut instancier
     * avec `new Database($cfg, true)` (2e paramètre = skipSeeds).
     */
    public function ensureFullSchemaWithoutSeeds(): void {
        $previous = $this->_skipSeeds;
        $this->_skipSeeds = true;
        try {
            $this->initTables();
            $this->initEnergieTable();
            $this->initCarboneTable();
        } finally {
            $this->_skipSeeds = $previous;
        }
    }

    /** @var bool Flag interne — skip les INSERT de données par défaut dans initTables() */
    private bool $_skipSeeds = false;

    /** @var string Identifiant unique de la base physique (pour la sentinelle de schéma) */
    private string $_dbIdent = '';

    /**
     * Crée les indexes critiques pour les performances. Idempotent (IF NOT EXISTS).
     * Cible les colonnes utilisées par les requêtes les plus fréquentes :
     *  - Login (auth, lookup OAuth, profile)
     *  - Email (résolution tenant + OAuth check)
     *  - Provider + ProviderUserId (lookup OAuth dédupliqué)
     *  - PushSubscriptions (Endpoint + UserId pour le scoping cross-user)
     *
     * Sans ces indexes, sur une base de quelques milliers d'utilisateurs,
     * chaque login déclenche un full scan. Sur PG/MariaDB c'est encore
     * plus pénalisant qu'en SQLite local.
     */
    private function ensureCriticalIndexes(): void {
        $idx = [
            // Auth / lookup utilisateurs
            "CREATE INDEX IF NOT EXISTS idx_utilisateurs_login ON Utilisateurs(Login)",
            "CREATE INDEX IF NOT EXISTS idx_utilisateurs_email ON Utilisateurs(Email)",
            // Push subscriptions — cross-user filter critique pour la sécurité
            "CREATE INDEX IF NOT EXISTS idx_push_endpoint ON PushSubscriptions(Endpoint)",
            "CREATE INDEX IF NOT EXISTS idx_push_userid   ON PushSubscriptions(UserId)",
            // Documents — lookups par entité.
            // ⚠️ FIX : la table Documents n'a PAS de colonnes InterventionId /
            // BienId / EquipementId — le lien est polymorphe via
            // (EntiteType, EntiteId). Les 3 anciens index échouaient donc
            // silencieusement dans le catch, et chaque ouverture d'un panneau
            // documents faisait un full scan d'une table qui stocke du base64.
            "CREATE INDEX IF NOT EXISTS idx_doc_entite ON Documents(EntiteType, EntiteId)",
            // Interventions — filtres fréquents
            "CREATE INDEX IF NOT EXISTS idx_interv_statut    ON Interventions(Statut)",
            // ⚠️ FIX : AssigneA n'existe pas ; l'agent est en clair dans AgentNom/AgentPrenom.
            "CREATE INDEX IF NOT EXISTS idx_interv_agent     ON Interventions(AgentNom, AgentPrenom)",
            "CREATE INDEX IF NOT EXISTS idx_interv_bien      ON Interventions(BienId)",
            "CREATE INDEX IF NOT EXISTS idx_interv_demande   ON Interventions(DemandeId)",
            // Demandes intervention
            "CREATE INDEX IF NOT EXISTS idx_demande_statut    ON DemandesIntervention(Statut)",
            // ⚠️ FIX : la colonne s'appelle EmailDemandeur, pas DemandeurEmail.
            "CREATE INDEX IF NOT EXISTS idx_demande_demandeur ON DemandesIntervention(EmailDemandeur)",
            // Facturation — liaison facture ↔ intervention (lookups dans les 2 sens)
            "CREATE INDEX IF NOT EXISTS idx_factint_facture ON FactureInterventions(FactureId)",
            "CREATE INDEX IF NOT EXISTS idx_factint_interv  ON FactureInterventions(InterventionId)",
        ];
        foreach ($idx as $sql) {
            try {
                $this->pdo->exec($sql);
            } catch (\PDOException $e) {
                // La table peut ne pas exister sur une vieille base — ignorer.
                // Les indexes seront créés au prochain boot après upgrade.
            }
        }
    }

    /**
     * Version du schéma. À incrémenter à CHAQUE modification structurelle
     * (nouvelle colonne, nouvelle table, nouvel index critique). Le flag
     * sentinelle sur disque vérifie cette valeur — si elle change, on
     * relance `initTables()` complet.
     *
     * v2 : ajout de la colonne Interventions.MontantHT (prix HT du suivi
     *      administratif). Le bump force le rejeu des migrations ALTER TABLE
     *      sur les bases déjà déployées (sinon la sentinelle « v1 » les
     *      court-circuiterait et la colonne ne serait jamais créée).
     * v3 : ajout des tables Factures + FactureInterventions (regroupement de
     *      plusieurs interventions sur une même facture : CBDC/CHMA, n° EJ,
     *      date EJ, montant ; ventilation détaillée par intervention). Le bump
     *      force la création de ces tables sur les bases déjà déployées.
     * v4 : ajout de la colonne IntervDevisLignes.DateReception (date de
     *      réception du devis). Le bump force le rejeu de l'ALTER TABLE.
     * v5 : ajout de la colonne Documents.SharePointDriveId (identifiant du
     *      drive, nécessaire à la résolution d'un lien SharePoint par son
     *      identifiant stable). Le bump force le rejeu de l'ALTER TABLE sur
     *      les bases déjà déployées — sinon la sentinelle « v4 » l'empêche et
     *      getDocuments plante (« la colonne sharepointdriveid n'existe pas »).
     * v6 : module Plans/Annuaire (V1) — ajout des colonnes Utilisateurs
     *      PartagePresencePlan et MasquerPresence (opt-out présence). Le bump
     *      force le rejeu sur les bases existantes — sinon getAnnuaireUsers
     *      plante (« la colonne partagepresenceplan n'existe pas »).
     *      NB : les colonnes de plan (RefUtilisateurId, PersonneData, EstPersonne)
     *      sont créées par initPlansTables() (non soumis à la sentinelle).
     * v7 : (a) ajout de Interventions.SansSuiviAdmin (dispense de suivi
     *      administratif : clôture autorisée sans CBDC/CHMA ni dates) ;
     *      (b) ajout de Documents.SharePointLastSync (dernière confirmation des
     *      métadonnées d'un lien SharePoint, pour l'affichage hors ligne) ;
     *      (c) correction de 5 index critiques dont les noms de colonnes
     *      étaient erronés (Documents(EntiteType,EntiteId) au lieu de
     *      InterventionId/BienId/EquipementId, Interventions(AgentNom,
     *      AgentPrenom) au lieu d'AssigneA, DemandesIntervention(EmailDemandeur)
     *      au lieu de DemandeurEmail) — ils échouaient silencieusement.
     *      Le bump force le rejeu sur les bases déjà déployées : sans lui la
     *      sentinelle « v6 » court-circuite tout et la clôture plante
     *      (« la colonne sanssuiviadmin n'existe pas »).
     * v8 : ajout de Utilisateurs.AccesLecture (onglets ouverts en consultation
     *      à un demandeur, sans changer son rôle). Le bump est INDISPENSABLE :
     *      la sentinelle « v7 » court-circuite initTables(), la colonne n'est
     *      donc jamais créée et getAllUtilisateurs() plante — l'onglet Comptes
     *      renvoie alors « Erreur base de données ».
     */
    private const SCHEMA_VERSION = 8;

    /**
     * Fichier sentinelle utilisé pour court-circuiter initTables() quand
     * le schéma est à jour. Évite ~30 requêtes SQL par requête HTTP.
     */
    private function schemaSentinelPath(): string {
        $base = __DIR__ . '/../data/.schema';
        if (!is_dir($base)) @mkdir($base, 0755, true);
        // Identifiant unique par BASE PHYSIQUE (driver + cible réelle), calculé
        // dans le constructeur. Deux tenants pointant sur des bases différentes ont
        // donc des sentinelles distinctes, et la base principale garde la sienne.
        $key = sha1($this->_dbIdent !== '' ? $this->_dbIdent : $this->driver);
        return $base . '/' . $key . '.flag';
    }

    /**
     * Vérifie si le schéma est déjà à jour pour cette base. Si oui, retourne
     * true et on peut court-circuiter initTables(). Sinon (ou si en doute),
     * retourne false et on relance la création/migration complète.
     */
    private function schemaIsCurrent(): bool {
        $flag = $this->schemaSentinelPath();
        if (!file_exists($flag)) return false;
        $content = @file_get_contents($flag);
        if ($content === false) return false;
        // Format : "VERSION|TIMESTAMP"
        $parts = explode('|', trim($content), 2);
        return ($parts[0] ?? '') === (string)self::SCHEMA_VERSION;
    }

    /**
     * Marque le schéma comme à jour pour cette base après une exécution
     * réussie d'initTables().
     */
    private function markSchemaCurrent(): void {
        $flag = $this->schemaSentinelPath();
        @file_put_contents($flag, self::SCHEMA_VERSION . '|' . time());
    }

    /**
     * Invalide la sentinelle de schéma de CETTE base : le prochain
     * `new Database()` sur la même base relancera initTables() complet
     * (recréation des tables + seeds). Indispensable après une PURGE qui
     * a supprimé (DROP) les tables, sinon la sentinelle « à jour » ferait
     * court-circuiter la recréation et la base resterait sans tables.
     */
    public function invalidateSchemaSentinel(): void {
        $flag = $this->schemaSentinelPath();
        if (is_file($flag)) @unlink($flag);
    }

    private function initTables(): void {
        // ⚠️ FIX PERFS : court-circuit si le schéma est déjà à jour.
        // initTables() exécute ~30 requêtes CREATE TABLE IF NOT EXISTS à
        // chaque instanciation Database. C'est idempotent mais pas gratuit
        // (latence DB). On utilise un flag fichier sentinelle qui matérialise
        // "le schéma version X a déjà été appliqué sur cette base".
        // Si SCHEMA_VERSION change (nouvelle migration), le flag devient
        // périmé et initTables() relance.
        if ($this->schemaIsCurrent() && !$this->_skipSeeds) {
            return;
        }

        $driver  = DB_DRIVER;
        $isPg    = ($driver === 'pgsql');
        $isMysql = ($driver === 'mariadb' || $driver === 'mysql');
        $AI      = $isPg ? 'SERIAL PRIMARY KEY' : ($isMysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT');

        $insertIgnore = function(string $sql): void {
            try { $this->pdo->exec($sql); } catch (\PDOException $e) { }
        };

        $tables = [
            "CREATE TABLE IF NOT EXISTS Utilisateurs (
                Id $AI, Nom TEXT NOT NULL, Prenom TEXT NOT NULL,
                Login TEXT NOT NULL UNIQUE, MotDePasse TEXT NOT NULL,
                Role TEXT DEFAULT 'Demandeur', Provider TEXT DEFAULT 'local',
                DateCreation TEXT, Actif INTEGER DEFAULT 1)",
            "CREATE TABLE IF NOT EXISTS Biens (
                Id $AI, Numero TEXT, Famille TEXT DEFAULT 'Mobilier', SousFamille TEXT,
                Statut TEXT DEFAULT 'Commandé', NumeroSerie TEXT, Etat TEXT DEFAULT 'Stock',
                DateCommande TEXT, DateLivraison TEXT, DateSuppression TEXT,
                Prix REAL DEFAULT 0, InfoProduit TEXT, Batiment TEXT, Etage TEXT,
                NumeroBureau TEXT, NomPrenom TEXT, Documents TEXT,
                CreatedAt TEXT, CreatedBy TEXT, UpdatedAt TEXT, UpdatedBy TEXT)",
            "CREATE TABLE IF NOT EXISTS Equipements (
                Id $AI, Numero TEXT, Famille TEXT DEFAULT 'Plomberie', SousFamille TEXT,
                Statut TEXT DEFAULT 'Commandé', NumeroSerie TEXT, Etat TEXT DEFAULT 'Stock',
                DateCommande TEXT, DateLivraison TEXT, DateInstallation TEXT, DateSuppression TEXT,
                Prix REAL DEFAULT 0, InfoProduit TEXT, Marque TEXT, Modele TEXT,
                Fournisseur TEXT, Batiment TEXT, Etage TEXT, NumeroBureau TEXT,
                NomPrenom TEXT, Observations TEXT, Documents TEXT,
                CreatedAt TEXT, CreatedBy TEXT, UpdatedAt TEXT, UpdatedBy TEXT)",
            "CREATE TABLE IF NOT EXISTS Contrats (
                Id $AI, Numero TEXT, Societe TEXT NOT NULL, Type TEXT DEFAULT 'Maintenance',
                Description TEXT, Perimetre TEXT, EquipementsIds TEXT,
                DateDebut TEXT, DateFin TEXT, AlerteJoursAvant INTEGER DEFAULT 30,
                MontantAnnuel REAL DEFAULT 0, Frequence TEXT DEFAULT 'Annuelle',
                ContactNom TEXT, ContactTel TEXT, ContactEmail TEXT,
                Statut TEXT DEFAULT 'Actif', Documents TEXT, Commentaire TEXT,
                CreatedAt TEXT, CreatedBy TEXT, UpdatedAt TEXT, UpdatedBy TEXT)",
            "CREATE TABLE IF NOT EXISTS Interventions (
                Id $AI, Numero TEXT, Type TEXT DEFAULT 'Préventive', Priorite TEXT,
                TypeLiaison TEXT DEFAULT 'Equipement', BienId INTEGER, EquipementsIds TEXT,
                ContratId INTEGER, SocieteManuelle TEXT, Description TEXT,
                DateRealisation TEXT, DureeHeures REAL DEFAULT 0, Montant REAL DEFAULT 0,
                EstRecurrente INTEGER DEFAULT 0, Recurrence TEXT, DateProchaine TEXT,
                InterventionParentId INTEGER, Statut TEXT DEFAULT 'Planifiée',
                Commentaire TEXT, Documents TEXT,
                CreatedAt TEXT, CreatedBy TEXT, UpdatedAt TEXT, UpdatedBy TEXT)",
            "CREATE TABLE IF NOT EXISTS Stock (
                Id $AI, Reference TEXT, Designation TEXT NOT NULL, Categorie TEXT,
                Quantite INTEGER DEFAULT 0, SeuilAlerte INTEGER DEFAULT 5,
                PrixUnitaire REAL DEFAULT 0, Emplacement TEXT, Fournisseur TEXT,
                CreatedAt TEXT, CreatedBy TEXT, UpdatedAt TEXT, UpdatedBy TEXT)",
            "CREATE TABLE IF NOT EXISTS GestionMateriel (
                Id $AI, AssetType TEXT, AssetId INTEGER, NumeroImmo TEXT, CompteImmo TEXT,
                Exercice TEXT, BonDeCommande TEXT, DateMiseEnService TEXT, NumeroMandat TEXT,
                DateAchat TEXT, ValeurAchat REAL, ValeurVenale REAL,
                DureeAmortissement INTEGER, MontantDuMarche REAL, DateBascule TEXT,
                AnnualiteAmortissement REAL DEFAULT 0, DepreciationTotal REAL DEFAULT 0, DateCalculDepreciation TEXT, User7 TEXT, Item TEXT)",
            "CREATE TABLE IF NOT EXISTS HistoriqueSuppression (
                Id $AI, TableSource TEXT, IdOriginal INTEGER, Numero TEXT, Description TEXT,
                DateSuppression TEXT, SupprimeParLogin TEXT)",
            "CREATE TABLE IF NOT EXISTS Listes (
                Id $AI, Categorie TEXT NOT NULL, Valeur TEXT NOT NULL,
                Ordre INTEGER DEFAULT 0, Actif INTEGER DEFAULT 1)",
            "CREATE TABLE IF NOT EXISTS DemandesIntervention (
                Id $AI, Titre TEXT NOT NULL, Description TEXT, Batiment TEXT, Bureau TEXT,
                Urgence TEXT DEFAULT 'Normale', Statut TEXT DEFAULT 'Nouveau',
                CommentaireAdmin TEXT, UtilisateurId INTEGER, NomDeclarant TEXT,
                DateCreation TEXT, DateTraitement TEXT,
                DelaiRelanceJours INTEGER DEFAULT 7, DateDerniereRelance TEXT)",
            "CREATE TABLE IF NOT EXISTS Configuration (
                Id $AI, Cle TEXT NOT NULL UNIQUE, Valeur TEXT DEFAULT '', Description TEXT)",
            "CREATE TABLE IF NOT EXISTS CompteurEnergie (
                Id $AI, Nom TEXT, Type TEXT, Unite TEXT, Site TEXT,
                ContratId INTEGER, NumeroCompteur TEXT, DateInstallation TEXT, Actif INTEGER DEFAULT 1,
                SousCompteurs TEXT)",
            "CREATE TABLE IF NOT EXISTS ReleverEnergie (
                Id $AI, CompteurId INTEGER, Date TEXT, Type TEXT,
                IndexDebut REAL, IndexFin REAL, Consommation REAL, Montant REAL,
                Fournisseur TEXT, NumeroFacture TEXT, Commentaire TEXT,
                SaisieParLogin TEXT, DateSaisie TEXT,
                DetailSousCompteurs TEXT)",
            "CREATE TABLE IF NOT EXISTS IntervStockLignes (
                Id $AI, InterventionId INTEGER NOT NULL, StockId INTEGER NOT NULL,
                Quantite INTEGER NOT NULL DEFAULT 1, PrixUnitaire REAL DEFAULT 0,
                Description TEXT, DateConsommation TEXT)",
            "CREATE TABLE IF NOT EXISTS IntervDevisLignes (
                Id $AI, InterventionId INTEGER NOT NULL,
                NumeroDevis TEXT DEFAULT '',
                MontantHT REAL DEFAULT 0,
                MontantTTC REAL DEFAULT 0,
                Accepte TEXT DEFAULT '',
                Raison TEXT DEFAULT '',
                DateReception TEXT DEFAULT '')",
            "CREATE TABLE IF NOT EXISTS Documents (
                Id $AI, EntiteType TEXT NOT NULL, EntiteId INTEGER NOT NULL,
                NomFichier TEXT NOT NULL, TypeMime TEXT, Categorie TEXT DEFAULT 'autre',
                Taille INTEGER DEFAULT 0, Donnees TEXT, DateAjout TEXT, AjoutePar TEXT)",
            "CREATE TABLE IF NOT EXISTS NotesInfo (
                Id $AI, Message TEXT NOT NULL, Actif INTEGER DEFAULT 1,
                DateCreation TEXT, CreePar TEXT)",

            // ── Module Archives ───────────────────────────────────────────────
            "CREATE TABLE IF NOT EXISTS ArchivesBoite (
                Id $AI,
                NumeroBoite       TEXT NOT NULL,
                Service           TEXT,
                AgentResponsable  TEXT,
                DateVersement     TEXT,
                NombreBoites      TEXT,
                NumeroVersement   TEXT,
                DatesExtremes     TEXT,
                Localisation      TEXT,
                AnneeRevision     TEXT,
                Batiment          TEXT,
                Emplacement       TEXT,
                DUA               TEXT,
                SortFinal         TEXT,
                Periode           TEXT,
                DureeArchivage    TEXT,
                Description       TEXT,
                Statut            TEXT DEFAULT 'Active',
                CodeBarre         TEXT,
                CreatedAt TEXT, CreatedBy TEXT, UpdatedAt TEXT, UpdatedBy TEXT)",

            "CREATE TABLE IF NOT EXISTS ArchivesDossier (
                Id $AI,
                BoiteId           INTEGER,
                NumeroBoite       TEXT,
                NumeroDossier     TEXT,
                NomPrenom         TEXT,
                Commune           TEXT,
                Departement       TEXT,
                Service           TEXT,
                Annee             TEXT,
                Mois              TEXT,
                Jour              TEXT,
                NumeroMandat      TEXT,
                Periode           TEXT,
                DUA               TEXT,
                SortFinal         TEXT,
                DureeArchivage    TEXT,
                Description       TEXT,
                Emplacement       TEXT,
                DateSolde         TEXT,
                CodeBarre         TEXT,
                Statut            TEXT DEFAULT 'Archive',
                TypeDemande       TEXT,
                CreatedAt TEXT, CreatedBy TEXT, UpdatedAt TEXT, UpdatedBy TEXT)",

            "CREATE TABLE IF NOT EXISTS ArchivesBordereau (
                Id $AI,
                Type              TEXT NOT NULL,
                Numero            TEXT,
                DateBordereau     TEXT,
                Service           TEXT,
                Description       TEXT,
                NomFichier        TEXT,
                TypeMime          TEXT,
                Taille            INTEGER DEFAULT 0,
                Donnees           TEXT,
                CreatedAt TEXT, CreatedBy TEXT)",
            "CREATE TABLE IF NOT EXISTS PushSubscriptions (
                Id $AI,
                UserId            INTEGER NOT NULL,
                Endpoint          TEXT NOT NULL,
                P256dh            TEXT NOT NULL,
                Auth              TEXT NOT NULL,
                Platform          TEXT DEFAULT 'unknown',
                UserAgent         TEXT,
                CreatedAt         TEXT,
                LastUsedAt        TEXT,
                UNIQUE(Endpoint))",
            "CREATE TABLE IF NOT EXISTS DeclarationsInventaire (
                Id $AI,
                BienId            INTEGER,
                NumeroBien        TEXT NOT NULL,
                Type              TEXT NOT NULL DEFAULT 'presence',
                Batiment          TEXT,
                Etage             TEXT,
                NumeroBureau      TEXT,
                Commentaire       TEXT,
                RaisonDepart      TEXT,
                DestBatiment      TEXT,
                DestEtage         TEXT,
                DestBureau        TEXT,
                DestPersonne      TEXT,
                Statut            TEXT NOT NULL DEFAULT 'en_attente',
                DeclarantLogin    TEXT NOT NULL,
                DeclarantNom      TEXT,
                TraiteParLogin    TEXT,
                TraiteParNom      TEXT,
                DateDeclaration   TEXT NOT NULL,
                DateTraitement    TEXT,
                MotifRefus        TEXT)",

            "CREATE TABLE IF NOT EXISTS MobiliteCarbone (
                Id $AI,
                UtilisateurId     INTEGER NOT NULL,
                Transport         TEXT NOT NULL,
                DistanceKm        REAL NOT NULL DEFAULT 0,
                Frequence         TEXT NOT NULL DEFAULT 'jour',
                NbFrequence       REAL NOT NULL DEFAULT 1,
                FacteurCO2        REAL NOT NULL DEFAULT 0,
                Periode           TEXT,
                DateDebut         TEXT,
                DateFin           TEXT,
                Commentaire       TEXT,
                CreatedAt         TEXT,
                UpdatedAt         TEXT)",

            // ── Facturation des interventions ─────────────────────────────────
            // En-tête de facture pouvant regrouper une ou plusieurs interventions.
            //   TypeBudget = circuit budgétaire (CBDC / CHMA / autre)
            //   NumeroEJ / DateEJ = engagement juridique (référence + date)
            "CREATE TABLE IF NOT EXISTS Factures (
                Id $AI, Numero TEXT, TypeBudget TEXT DEFAULT '',
                NumeroEJ TEXT DEFAULT '', DateEJ TEXT,
                MontantHT REAL DEFAULT 0, MontantTTC REAL DEFAULT 0,
                Fournisseur TEXT DEFAULT '', DateFacture TEXT,
                Commentaire TEXT DEFAULT '',
                CreatedAt TEXT, CreatedBy TEXT, UpdatedAt TEXT, UpdatedBy TEXT)",
            // Liaison N–N facture ↔ intervention. MontantLigne = ventilation
            // (« détail ») du montant de la facture sur chaque intervention.
            "CREATE TABLE IF NOT EXISTS FactureInterventions (
                Id $AI, FactureId INTEGER NOT NULL, InterventionId INTEGER NOT NULL,
                MontantLigne REAL DEFAULT 0, Libelle TEXT DEFAULT '')",
        ];

        foreach ($tables as $sql) {
            $this->pdo->exec($sql);
        }

        // Admin par défaut : déplacé APRÈS la boucle de migration des colonnes
        // (voir « Admin par défaut » plus bas). Il insérait MustChangePassword
        // alors que cette colonne n'est ajoutée que par $cols_to_add, exécuté
        // plus loin → toute installation MONO-TENANT neuve plantait au premier
        // appel (« table Utilisateurs has no column named MustChangePassword »).


        // Configuration par défaut
        if (!$this->_skipSeeds) {
            $insertIgnore("INSERT INTO Configuration (Cle, Valeur, Description) VALUES ('domaines_autorises', '', 'Domaines email autorisés séparés par virgule.')");
        }

        // Migrations colonnes idempotentes
        $cols_to_add = [
            ["Interventions",       "MontantPieces",       "REAL DEFAULT 0"],
            ["Interventions",       "MontantMainOeuvre",   "REAL DEFAULT 0"],
            ["Interventions",       "DateValidation",      "TEXT"],
            ["Interventions",       "DateArchivage",       "TEXT"],
            ["Interventions",       "DemandeId",           "INTEGER"],
            ["Interventions",       "Statut2",             "TEXT DEFAULT 'Planifiée'"],
            ["Stock",               "Source",              "TEXT DEFAULT 'Achat'"],
            ["Stock",               "ContratId",           "INTEGER"],
            ["DemandesIntervention","DelaiRelanceJours",   "INTEGER DEFAULT 7"],
            ["DemandesIntervention","DateDerniereRelance", "TEXT"],
            ["DemandesIntervention","Priorite",            "TEXT DEFAULT 'Normale'"],
            ["Stock",               "Marque",              "TEXT DEFAULT ''"],
            ["Stock",               "Modele",              "TEXT DEFAULT ''"],
            ["Stock",               "TypeArticle",         "TEXT DEFAULT ''"],
            ["ReleverEnergie",      "PeriodeDebut",        "TEXT"],
            ["ReleverEnergie",      "PeriodeFin",          "TEXT"],
            ["ReleverEnergie",      "DetailSousCompteurs", "TEXT"],
            ["Contrats",            "OptionTarifaire",     "TEXT"],
            ["Contrats",            "PuissanceSouscrite",  "REAL"],
            ["Contrats",            "PrixBaseHT",          "REAL"],
            ["Contrats",            "PrixHPHT",            "REAL"],
            ["Contrats",            "PrixHCHT",            "REAL"],
            ["Contrats",            "AbonnementHT",        "REAL"],
            ["Contrats",            "FournisseurEnergie",  "TEXT"],
            ["Contrats",            "Fluide",              "TEXT"],
            ["Contrats",            "HCDebut",             "TEXT"],
            ["Contrats",            "HCFin",               "TEXT"],
            ["Contrats",            "AbonnementTTC",       "REAL"],
            ["Contrats",            "PrixBaseTTC",         "REAL"],
            ["Contrats",            "TVARate",             "REAL"],
            ["Contrats",            "ChauffageR1",         "REAL"],
            ["Contrats",            "ChauffageR2",         "REAL"],
            // Contrats : Eau & Gaz tarification
            ["Contrats",            "EauAbonnementHT",     "REAL"],
            ["Contrats",            "EauAbonnementTTC",    "REAL"],
            ["Contrats",            "EauTVAAbo",           "REAL"],
            ["Contrats",            "EauPrixM3HT",         "REAL"],
            ["Contrats",            "EauPrixM3TTC",        "REAL"],
            ["Contrats",            "EauTVAM3",            "REAL"],
            ["Contrats",            "GazAbonnementHT",     "REAL"],
            ["Contrats",            "GazAbonnementTTC",    "REAL"],
            ["Contrats",            "GazTVAAbo",           "REAL"],
            ["Contrats",            "GazPrixM3HT",         "REAL"],
            ["Contrats",            "GazPrixM3TTC",        "REAL"],
            ["Contrats",            "GazTVAM3",            "REAL"],
            // Contrats : HTA 5 plages + HP/HC TTC
            ["Contrats",            "PrixHPTTC",           "REAL"],
            ["Contrats",            "PrixHCTTC",           "REAL"],
            ["Contrats",            "TVAKwh",              "REAL"],
            ["Contrats",            "TVAAbo",              "REAL"],
            ["Contrats",            "HtaHPHS",             "REAL"],
            ["Contrats",            "HtaHPHSTTC",          "REAL"],
            ["Contrats",            "HtaHCHS",             "REAL"],
            ["Contrats",            "HtaHCHSTTC",          "REAL"],
            ["Contrats",            "HtaHPBS",             "REAL"],
            ["Contrats",            "HtaHPBSTTC",          "REAL"],
            ["Contrats",            "HtaHCBS",             "REAL"],
            ["Contrats",            "HtaHCBSTTC",          "REAL"],
            ["Contrats",            "HtaCEE",              "REAL"],
            ["Contrats",            "HtaCEETTC",           "REAL"],
            ["Contrats",            "HtaCapa",             "REAL"],
            ["Contrats",            "HtaCapaTTC",          "REAL"],
            ["Contrats",            "HtaTVALignes",        "TEXT"],
            ["Contrats",            "ReferentClient",      "TEXT"],
            ["Contrats",            "ReferenceContrat",    "TEXT"],
            ["Contrats",            "TypeCompteur",        "TEXT"],
            // Demandes : champs contact demandeur
            ["DemandesIntervention","EmailDemandeur",      "TEXT DEFAULT ''"],
            ["DemandesIntervention","TelDemandeur",        "TEXT DEFAULT ''"],
            ["DemandesIntervention","PourAutrui",          "INTEGER DEFAULT 0"],
            ["DemandesIntervention","NomAutrui",           "TEXT DEFAULT ''"],
            ["DemandesIntervention","EmailAutrui",         "TEXT DEFAULT ''"],
            ["DemandesIntervention","TelAutrui",           "TEXT DEFAULT ''"],
            ["DemandesIntervention","Categorie",           "TEXT DEFAULT ''"],
            // Biens/Equipements : commentaire sortie
            ["Biens",              "CommentaireSortie",    "TEXT DEFAULT ''"],
            ["Equipements",        "CommentaireSortie",    "TEXT DEFAULT ''"],
            // Listes : options obligatoire et saisie libre
            ["Listes",             "Obligatoire",         "INTEGER DEFAULT 0"],
            ["Listes",             "SaisieLibre",         "INTEGER DEFAULT 0"],
            // Utilisateurs : reset password + email + tel + service + poste
            // Autorisations de consultation individuelles (JSON). Vide = l'agent
            // suit le réglage global (Configuration → Accès demandeurs).
            ["Utilisateurs",       "AccesLecture",        "TEXT DEFAULT ''"],
            ["Utilisateurs",       "ResetToken",          "TEXT"],
            ["Utilisateurs",       "ResetTokenExpiry",    "TEXT"],
            ["Utilisateurs",       "Email",               "TEXT"],
            ["Utilisateurs",       "Tel",                 "TEXT"],
            ["Utilisateurs",       "Service",             "TEXT DEFAULT ''"],
            ["Utilisateurs",       "Poste",               "TEXT DEFAULT ''"],
            // ── Microsoft enrichi (photo, lieu, société, manager) ──
            ["Utilisateurs",       "MicrosoftId",         "TEXT DEFAULT ''"],
            ["Utilisateurs",       "PhotoUrl",            "TEXT DEFAULT ''"],
            ["Utilisateurs",       "OfficeLocation",      "TEXT DEFAULT ''"],
            ["Utilisateurs",       "CompanyName",         "TEXT DEFAULT ''"],
            ["Utilisateurs",       "ManagerName",         "TEXT DEFAULT ''"],
            ["Utilisateurs",       "TelMobile",           "TEXT DEFAULT ''"],
            ["Utilisateurs",       "TelPro",              "TEXT DEFAULT ''"],
            ["Utilisateurs",       "MustChangePassword",  "INTEGER DEFAULT 0"],
            ["Utilisateurs",       "PartagePresencePlan", "INTEGER DEFAULT 0"],  // (remplacé par MasquerPresence)
            ["Utilisateurs",       "MasquerPresence",     "INTEGER DEFAULT 0"],  // opt-out présence/agenda (0 = partagé par défaut)
            // ── Compta + métadonnées ──
            ["Biens",              "CreatedAt",            "TEXT"],
            ["Biens",              "CreatedBy",            "TEXT"],
            ["Biens",              "UpdatedAt",            "TEXT"],
            ["Biens",              "UpdatedBy",            "TEXT"],
            ["Equipements",              "CreatedAt",            "TEXT"],
            ["Equipements",              "CreatedBy",            "TEXT"],
            ["Equipements",              "UpdatedAt",            "TEXT"],
            ["Equipements",              "UpdatedBy",            "TEXT"],
            ["Contrats",              "CreatedAt",            "TEXT"],
            ["Contrats",              "CreatedBy",            "TEXT"],
            ["Contrats",              "UpdatedAt",            "TEXT"],
            ["Contrats",              "UpdatedBy",            "TEXT"],
            ["Interventions",              "CreatedAt",            "TEXT"],
            ["Interventions",              "CreatedBy",            "TEXT"],
            ["Interventions",              "UpdatedAt",            "TEXT"],
            ["Interventions",              "UpdatedBy",            "TEXT"],
            ["Stock",              "CreatedAt",            "TEXT"],
            ["Stock",              "CreatedBy",            "TEXT"],
            ["Stock",              "UpdatedAt",            "TEXT"],
            ["Stock",              "UpdatedBy",            "TEXT"],
            ["GestionMateriel",       "DateBascule",            "TEXT"],
            ["GestionMateriel",       "AnnualiteAmortissement",            "REAL DEFAULT 0"],
            ["GestionMateriel",       "DepreciationTotal",            "REAL DEFAULT 0"],
            ["GestionMateriel",       "DateCalculDepreciation",            "TEXT"],
            ["GestionMateriel",       "User7",            "TEXT DEFAULT ''"],
            ["GestionMateriel",       "Item",            "TEXT DEFAULT ''"],
            ["GestionMateriel",       "EstCloture",       "INTEGER DEFAULT 0"],
            ["GestionMateriel",       "DateCloture",      "TEXT"],
            ["GestionMateriel",       "MotifCloture",     "TEXT DEFAULT ''"],
            ["GestionMateriel",       "ClotureParLogin",  "TEXT"],
            ["Biens",              "DateSortie",       "TEXT"],
            ["Equipements",        "DateSortie",       "TEXT"],
            ["DemandesIntervention","TypeLocalisation", "TEXT DEFAULT 'Standard'"],
            ["DemandesIntervention","ArchiveData",      "TEXT"],
            ["DemandesIntervention","EmailDemandeur",   "TEXT"],
            ["DemandesIntervention","TelDemandeur",     "TEXT"],
            // Contrats : contacts multiples JSON
            ["Contrats",            "ContactsJSON",      "TEXT"],
            // Stock : lien famille pour agrégation biens
            ["Stock",               "FamilleLien",       "TEXT"],
            ["Stock",               "TypeArticle",       "TEXT DEFAULT ''"],
            // Interventions : nouveaux champs fiche
            ["Interventions",       "AgentNom",            "TEXT DEFAULT ''"],
            ["Interventions",       "AgentPrenom",         "TEXT DEFAULT ''"],
            ["Interventions",       "AgentTel",            "TEXT DEFAULT ''"],
            ["Interventions",       "AgentEmail",          "TEXT DEFAULT ''"],
            ["Interventions",       "DateDemande",         "TEXT"],
            ["Interventions",       "DateIntervention",    "TEXT"],
            ["Interventions",       "RelanceChamp",        "TEXT DEFAULT ''"],
            ["Interventions",       "RelanceDate",         "TEXT"],
            ["Interventions",       "TypeBudget",          "TEXT DEFAULT ''"],
            ["Interventions",       "DateEnvoiMail",       "TEXT"],
            ["Interventions",       "CodeNumeroDemande",   "TEXT DEFAULT ''"],
            // Suivi administratif : prix HT (montant hors taxes saisi côté admin)
            ["Interventions",       "MontantHT",           "REAL DEFAULT 0"],
            ["Interventions",       "NotesJSON",           "TEXT DEFAULT '[]'"],
            // Interventions prévues : délai affichage dashboard (curatif) + historique modif dates
            ["Interventions",       "DelaiAffichageDashboard", "INTEGER DEFAULT 0"],
            ["Interventions",       "HistoriqueModifDates",    "TEXT DEFAULT '[]'"],
            // Dispense de suivi administratif : quand = 1, l'intervention peut
            // être clôturée sans CBDC/CHMA ni dates (petit dépannage interne,
            // pas de bon de commande, pas de mail au prestataire…).
            ["Interventions",       "SansSuiviAdmin",          "INTEGER DEFAULT 0"],

            // Inventaire agent : lien direct utilisateur → bien
            ["Biens",               "AffecteUserId",           "INTEGER"],

            // Déclarations inventaire : colonnes destination départ
            ["DeclarationsInventaire", "RaisonDepart",   "TEXT"],
            ["DeclarationsInventaire", "DestBatiment",   "TEXT"],
            ["DeclarationsInventaire", "DestEtage",      "TEXT"],
            ["DeclarationsInventaire", "DestBureau",     "TEXT"],
            ["DeclarationsInventaire", "DestPersonne",   "TEXT"],

            // Déclaration présence : personne en possession déclarée par l'agent
            ["DeclarationsInventaire", "PersonneDeclaree", "TEXT"],

            // Documents : liens SharePoint (raccourci vers fichier externe)
            ["Documents", "SharePointUrl",         "TEXT"],
            ["Documents", "SharePointDriveItemId", "TEXT"],
            ["Documents", "SharePointDriveId",     "TEXT"],
            ["Documents", "SharePointSiteId",      "TEXT"],
            // Horodatage de la dernière confirmation des métadonnées auprès de
            // SharePoint. Sert à afficher « infos au JJ/MM » quand SharePoint est
            // injoignable, sans jamais rapatrier le contenu du fichier.
            ["Documents", "SharePointLastSync",   "TEXT"],

            // Mobilité carbone : colonnes ajoutées après création initiale
            ["MobiliteCarbone", "Transport",     "TEXT DEFAULT ''"],
            ["MobiliteCarbone", "DistanceKm",    "REAL DEFAULT 0"],
            ["MobiliteCarbone", "Frequence",     "TEXT DEFAULT 'jour'"],
            ["MobiliteCarbone", "NbFrequence",   "REAL DEFAULT 1"],
            ["MobiliteCarbone", "FacteurCO2",    "REAL DEFAULT 0"],
            ["MobiliteCarbone", "Periode",       "TEXT"],
            ["MobiliteCarbone", "DateDebut",     "TEXT"],
            ["MobiliteCarbone", "DateFin",       "TEXT"],
            ["MobiliteCarbone", "Commentaire",   "TEXT"],
            ["MobiliteCarbone", "CreatedAt",     "TEXT"],
            ["MobiliteCarbone", "UpdatedAt",     "TEXT"],

            // Date de réception du devis (ajout v4)
            ["IntervDevisLignes", "DateReception", "TEXT DEFAULT ''"],

        ];
        foreach ($cols_to_add as [$table, $col, $def]) {
            try {
                $alterSql = $isPg
                    ? "ALTER TABLE $table ADD COLUMN IF NOT EXISTS $col $def"
                    : "ALTER TABLE $table ADD COLUMN $col $def";
                $this->pdo->exec($alterSql);
            } catch (\PDOException $e) { /* colonne déjà existante */ }
        }

        // ── Admin par défaut — uniquement si PAS en multi-tenant ─────────────
        // ⚠️ FIX : ce bloc DOIT rester APRÈS la boucle $cols_to_add ci-dessus.
        // Il insère MustChangePassword, colonne absente du CREATE TABLE et
        // ajoutée uniquement par cette migration. Placé avant, il plantait :
        //   - sur toute base MONO-TENANT neuve (colonne pas encore créée) ;
        //   - sur toute base ancienne dont la table Utilisateurs existait déjà
        //     et était vide (CREATE TABLE IF NOT EXISTS n'ajoute rien).
        // En multi-tenant le seed est sauté, d'où un bug resté invisible.
        if (!$this->_skipSeeds) {
            $count = $this->pdo->query("SELECT COUNT(*) FROM Utilisateurs")->fetchColumn();
            if ($count == 0) {
                $isMultiTenant = class_exists('TenantResolver', false) && TenantResolver::isMultiTenant();
                if (!$isMultiTenant) {
                    // Mode classique (pas de tenants.json) : créer admin/admin
                    // MustChangePassword=1 force l'admin à changer son mot de passe au premier login.
                    $stmt = $this->pdo->prepare("INSERT INTO Utilisateurs (Nom, Prenom, Login, MotDePasse, Role, DateCreation, Actif, MustChangePassword) VALUES ('Admin', 'Super', 'admin', :pwd, 'Gestionnaire', :date, 1, 1)");
                    $stmt->execute(['pwd' => password_hash('admin', PASSWORD_BCRYPT), 'date' => date('Y-m-d')]);
                }
                // En multi-tenant : la DB reste vide, le Super Admin crée les comptes
            }
        }

        // Listes par défaut
        if (!$this->_skipSeeds) {
            $countListes = $this->pdo->query("SELECT COUNT(*) FROM Listes")->fetchColumn();
            if ($countListes == 0) {
                $defaults = [
                    ['FamilleBien','Mobilier',1],['FamilleBien','Informatique',2],['FamilleBien','Electromenager',3],['FamilleBien','Autre',4],
                    ['SousFamilleBien','Bureau',1],['SousFamilleBien','Chaise',2],['SousFamilleBien','Ordinateur',3],['SousFamilleBien','Ecran',4],
                    ['FamilleEquipement','Plomberie',1],['FamilleEquipement','Electricite',2],['FamilleEquipement','CVC',3],['FamilleEquipement','SSI',4],
                    ['SousFamilleEquipement','Robinetterie',1],['SousFamilleEquipement','Tableau electrique',2],['SousFamilleEquipement','Climatisation',3],['SousFamilleEquipement','Detecteur incendie',4],
                    ['CategorieStock','Piece',1],['CategorieStock','Consommable',2],['CategorieStock','Outillage',3],
                    ['StatutBien','Commande',1],['StatutBien','Livre',2],
                    ['EtatAsset','Stock',1],['EtatAsset','Utilise',2],['EtatAsset','Jete/Recycle',3],['EtatAsset','Don/Vendu',4],
                    ['StatutEquipement','Commande',1],['StatutEquipement','Livre',2],['StatutEquipement','Installe',3],
                    ['StatutIntervention','Planifiée',1],['StatutIntervention','En cours',2],['StatutIntervention','Réalisée',3],
                    ['TypeIntervention','Préventive',1],['TypeIntervention','Curative',2],['TypeIntervention','Contrôle réglementaire',3],['TypeIntervention','Divers',4],
                    ['PrioriteIntervention','Basse',1],['PrioriteIntervention','Normale',2],['PrioriteIntervention','Urgente',3],
                    ['StatutContrat','Actif',1],['StatutContrat','Expire',2],['StatutContrat','Resilie',3],
                    ['TypeContrat','Maintenance',1],['TypeContrat','Controle reglementaire',2],['TypeContrat','Location',3],['TypeContrat','Energie',4],['TypeContrat','Autre',5],
                    ['Batiment','Bâtiment A',1],['Batiment','Bâtiment B',2],['Batiment','Bâtiment C',3],
                    ['CategorieDemande','Plomberie',1],['CategorieDemande','Électricité',2],['CategorieDemande','Serrurerie',3],['CategorieDemande','Autre',4],
                ];
                $stmt = $this->pdo->prepare("INSERT INTO Listes (Categorie, Valeur, Ordre) VALUES (?, ?, ?)");
                foreach ($defaults as $row) { $stmt->execute($row); }
            }
        }
        // ⚠️ FIX PERFS : marquer le schéma comme à jour pour éviter de
        // refaire toutes les CREATE TABLE IF NOT EXISTS au prochain boot.
        // Best-effort — un échec ici n'a aucune conséquence fonctionnelle.
        $this->markSchemaCurrent();
    }

    // ══ CONFIGURATION ══
    public function getConfig(string $cle): string {
        $row = $this->fetchOne("SELECT Valeur FROM Configuration WHERE Cle = :cle", ['cle' => $cle]);
        return $row['Valeur'] ?? '';
    }
    public function setConfig(string $cle, string $valeur): void {
        $driver = DB_DRIVER;
        if ($driver === 'pgsql') {
            $this->execute(
                "INSERT INTO Configuration (Cle, Valeur) VALUES (:cle, :val) ON CONFLICT(Cle) DO UPDATE SET Valeur = EXCLUDED.Valeur",
                ['cle' => $cle, 'val' => $valeur]
            );
        } elseif ($driver === 'mariadb' || $driver === 'mysql') {
            $this->execute(
                "INSERT INTO Configuration (Cle, Valeur) VALUES (:cle, :val) ON DUPLICATE KEY UPDATE Valeur = VALUES(Valeur)",
                ['cle' => $cle, 'val' => $valeur]
            );
        } else {
            $this->execute(
                "INSERT INTO Configuration (Cle, Valeur) VALUES (:cle, :val) ON CONFLICT(Cle) DO UPDATE SET Valeur = excluded.Valeur",
                ['cle' => $cle, 'val' => $valeur]
            );
        }
    }
        public function getAllConfig(): array {
        return $this->fetchAll("SELECT * FROM Configuration ORDER BY Cle");
    }

    // ══ DOMAINES EMAIL ══
    public function isEmailDomainAllowed(string $email): bool {
        $domainesConfig = $this->getConfig('domaines_autorises');
        if (empty(trim($domainesConfig))) return true;
        $domaines = array_filter(array_map('trim', explode(',', $domainesConfig)));
        if (empty($domaines)) return true;
        $emailDomain = strtolower(substr(strrchr($email, '@'), 1));
        return in_array($emailDomain, array_map('strtolower', $domaines));
    }

    // ══ LISTES ══
    public function getListe(string $categorie): array {
        return $this->fetchAll("SELECT * FROM Listes WHERE Categorie = :cat AND Actif = 1 ORDER BY Ordre, Valeur", ['cat' => $categorie]);
    }
    public function getAllListes(): array {
        $rows = $this->fetchAll("SELECT * FROM Listes ORDER BY Categorie, Ordre, Valeur");
        $grouped = [];
        foreach ($rows as $row) { $grouped[$row['Categorie']][] = $row; }
        return $grouped;
    }
    public function addListeValeur(string $categorie, string $valeur, int $ordre = 0, int $obligatoire = 0, int $saisieLibre = 0): int {
        $this->execute(
            "INSERT INTO Listes (Categorie, Valeur, Ordre, Actif, Obligatoire, SaisieLibre) VALUES (:cat, :val, :ordre, 1, :oblig, :libre)",
            ['cat' => $categorie, 'val' => $valeur, 'ordre' => $ordre, 'oblig' => $obligatoire, 'libre' => $saisieLibre]
        );
        return $this->lastId();
    }
    public function updateListeValeur(int $id, string $valeur, int $ordre, int $actif, int $obligatoire = 0, int $saisieLibre = 0): void {
        $this->execute("UPDATE Listes SET Valeur = :val, Ordre = :ordre, Actif = :actif, Obligatoire = :oblig, SaisieLibre = :libre WHERE Id = :id", ['val' => $valeur, 'ordre' => $ordre, 'actif' => $actif, 'oblig' => $obligatoire, 'libre' => $saisieLibre, 'id' => $id]);
    }
    /**
     * Mapping Catégorie de liste → [ [Table, Colonne], ... ]
     */
    private function getListeCategorieMapping(): array {
        return [
            'FamilleBien'           => [['Biens','Famille']],
            'SousFamilleBien'       => [['Biens','SousFamille']],
            'StatutBien'            => [['Biens','Statut']],
            'EtatAsset'             => [['Biens','Etat'],['Equipements','Etat']],
            'Batiment'              => [['Biens','Batiment'],['Equipements','Batiment'],['DemandesIntervention','Batiment']],
            'FamilleEquipement'     => [['Equipements','Famille']],
            'SousFamilleEquipement' => [['Equipements','SousFamille']],
            'StatutEquipement'      => [['Equipements','Statut']],
            'TypeIntervention'      => [['Interventions','Type']],
            'PrioriteIntervention'  => [['Interventions','Priorite']],
            'StatutIntervention'    => [['Interventions','Statut']],
            'TypeContrat'           => [['Contrats','Type']],
            'StatutContrat'         => [['Contrats','Statut']],
            'CategorieStock'        => [['Stock','Categorie']],
            'CategorieDemande'      => [['DemandesIntervention','Categorie']],
        ];
    }

    /**
     * Vérifie si une valeur de liste est utilisée dans les données.
     * Retourne ['used' => bool, 'count' => int, 'tables' => [...]]
     */
    public function getListeValeurUsage(int $id): array {
        $row = $this->fetchOne("SELECT Categorie, Valeur FROM Listes WHERE Id = :id", ['id' => $id]);
        if (!$row) return ['used' => false, 'count' => 0, 'tables' => []];

        $mapping = $this->getListeCategorieMapping();
        $targets = $mapping[$row['Categorie']] ?? [];
        $totalCount = 0;
        $usedTables = [];

        foreach ($targets as [$table, $col]) {
            $sql = "SELECT COUNT(*) AS cnt FROM $table WHERE $col = :val";
            $r = $this->fetchOne($sql, ['val' => $row['Valeur']]);
            $cnt = (int)($r['cnt'] ?? 0);
            if ($cnt > 0) {
                $totalCount += $cnt;
                $usedTables[] = ['table' => $table, 'colonne' => $col, 'count' => $cnt];
            }
        }

        return [
            'used'   => $totalCount > 0,
            'count'  => $totalCount,
            'tables' => $usedTables,
            'valeur' => $row['Valeur'],
            'categorie' => $row['Categorie'],
        ];
    }

    /**
     * Supprime ou désactive une valeur de liste.
     * - Si non utilisée : suppression physique (DELETE)
     * - Si utilisée : soft-delete (Actif = 0)
     * Retourne ['action' => 'deleted'|'deactivated', 'count' => int]
     */
    public function deleteListeValeur(int $id): array {
        $usage = $this->getListeValeurUsage($id);

        if ($usage['used']) {
            // Soft-delete : désactiver seulement
            $this->execute("UPDATE Listes SET Actif = 0 WHERE Id = :id", ['id' => $id]);
            return ['action' => 'deactivated', 'count' => $usage['count'], 'tables' => $usage['tables']];
        } else {
            // Suppression physique : aucune donnée ne la référence
            $this->execute("DELETE FROM Listes WHERE Id = :id", ['id' => $id]);
            return ['action' => 'deleted', 'count' => 0];
        }
    }

    /**
     * Suppression forcée (physique) d'une valeur de liste, même si utilisée.
     * Les données existantes conserveront leur valeur textuelle.
     */
    public function forceDeleteListeValeur(int $id): void {
        $this->execute("DELETE FROM Listes WHERE Id = :id", ['id' => $id]);
    }

    // ══ NOTES INFO (banderole demandeur) ══
    public function getAllNotesInfo(): array {
        return $this->fetchAll("SELECT * FROM NotesInfo WHERE Actif=1 ORDER BY DateCreation DESC");
    }
    public function getAllNotesInfoAdmin(): array {
        return $this->fetchAll("SELECT * FROM NotesInfo ORDER BY DateCreation DESC");
    }
    public function addNoteInfo(string $message, string $login): int {
        $this->execute("INSERT INTO NotesInfo (Message, Actif, DateCreation, CreePar) VALUES (:msg, 1, :date, :login)",
            ['msg' => $message, 'date' => date('Y-m-d H:i:s'), 'login' => $login]);
        return $this->lastId();
    }
    public function updateNoteInfo(int $id, string $message, int $actif): void {
        $this->execute("UPDATE NotesInfo SET Message=:msg, Actif=:actif WHERE Id=:id",
            ['msg' => $message, 'actif' => $actif, 'id' => $id]);
    }
    public function deleteNoteInfo(int $id): void {
        $this->execute("DELETE FROM NotesInfo WHERE Id=:id", ['id' => $id]);
    }

    // ══ HELPERS ══
    // NB : `public` (et non `private`) car AssistantTools et routes/assistant.php
    // l'appellent depuis l'extérieur — comme fetchOne() juste en dessous.
    public function fetchAll(string $sql, array $params = []): array {
        $stmt = $this->pdo->prepare($sql); $stmt->execute($params);
        $rows = $stmt->fetchAll();
        if ($this->driver === 'pgsql' && !empty($rows)) {
            return array_map([$this, 'remapRow'], $rows);
        }
        return $rows;
    }
    public function fetchOne(string $sql, array $params = []): ?array {
        $stmt = $this->pdo->prepare($sql); $stmt->execute($params); $row = $stmt->fetch();
        if (!$row) return null;
        return ($this->driver === 'pgsql') ? $this->remapRow($row) : $row;
    }
    /** Indicateur paresseux : le resync préventif a-t-il été fait dans cette requête PHP ? */
    private static bool $_pgSequencesResynced = false;

    /**
     * Exécute une écriture SQL et l'inscrit au journal d'audit.
     *
     * Greffer l'audit ICI plutôt que dans chaque méthode add / update / delete
     * donne une couverture totale et automatique : toute écriture passant par
     * la couche d'accès est tracée, y compris celles ajoutées plus tard.
     */
    public function execute(string $sql, array $params = []): void {
        $this->executeInterne($sql, $params);

        // ⚠️ CAPITAL : figer lastInsertId() AVANT d'écrire au journal.
        // L'audit insère une ligne dans la table Journal, ce qui écrase
        // lastInsertId() sur la même connexion PDO. Sans cette capture, tous
        // les add*() — qui appellent lastId() juste après execute() —
        // retourneraient l'identifiant de la ligne de journal au lieu de celui
        // de l'entité créée. C'est lastId() qui lit cette valeur figée.
        try { $this->_dernierIdInsere = (int)$this->pdo->lastInsertId(); }
        catch (\Throwable $e) { $this->_dernierIdInsere = 0; }

        $this->auditerEcriture($sql, $params, $this->_dernierIdInsere);
    }

    /** Dernier identifiant auto-généré, figé avant l'écriture du journal. */
    private int $_dernierIdInsere = 0;

    /**
     * Inscrit une écriture au journal. Best-effort absolu : une panne de
     * journalisation ne doit jamais faire échouer l'écriture métier qui, elle,
     * a déjà réussi.
     */
    private function auditerEcriture(string $sql, array $params, int $idInsere = 0): void {
        try {
            if (!class_exists('Journal')) return;
            if (!preg_match('/^\s*(INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+"?([A-Za-z_][A-Za-z0-9_]*)"?/i', $sql, $m)) {
                return; // DDL, SELECT, PRAGMA… : hors périmètre
            }
            $table = $m[2];
            // Ne pas auditer le journal lui-même (bruit + risque de boucle).
            if (in_array(strtolower($table), ['journal', 'sessions', 'pushsubscriptions'], true)) return;

            $op = str_starts_with(strtoupper(ltrim($m[1])), 'INSERT') ? 'CREATE'
                : (str_starts_with(strtoupper(ltrim($m[1])), 'UPDATE') ? 'UPDATE' : 'DELETE');

            // Identifiant de l'enregistrement touché. Pour un UPDATE/DELETE on le
            // lit dans la clause WHERE (et non dans n'importe quel paramètre lié
            // nommé « id », qui peut désigner une tout autre table dans une
            // requête à plusieurs conditions). Pour un INSERT, lastInsertId().
            $entiteId = 0;
            if ($op === 'CREATE') {
                $entiteId = $idInsere;
            } elseif (preg_match('/\bWHERE\b.*?\bId\s*=\s*:(\w+)/is', $sql, $w)) {
                $cle = $w[1];
                if (isset($params[$cle]) && is_numeric($params[$cle])) $entiteId = (int)$params[$cle];
            }

            Journal::log(Journal::AUDIT, 'donnees', $op . ' ' . $table . ($entiteId ? '#' . $entiteId : ''), [
                'action'     => $op,
                'entiteType' => $table,
                'entiteId'   => $entiteId,
                'champs'     => $params,
            ]);
        } catch (\Throwable $e) { /* jamais bloquant */ }
    }

    private function executeInterne(string $sql, array $params = []): void {
        // Resync lazy : la PREMIÈRE fois qu'on fait un INSERT en Postgres dans cette requête,
        // on resynchronise toutes les séquences pour éviter les bugs 23505.
        if ($this->driver === 'pgsql'
            && !self::$_pgSequencesResynced
            && preg_match('/^\s*INSERT\s+/i', $sql)) {
            self::$_pgSequencesResynced = true;
            try { $this->resyncAllPgSequences(); } catch (\Throwable $e) { /* silencieux */ }
        }

        // Si on est dans une transaction Postgres, créer un savepoint interne
        // pour permettre le retry après resync (sinon la transaction passe en "aborted").
        $useInternalSP = false;
        $spName = null;
        if ($this->driver === 'pgsql'
            && $this->pdo->inTransaction()
            && preg_match('/^\s*INSERT\s+/i', $sql)) {
            try {
                $spName = 'sp_exec_' . substr(md5(uniqid('', true)), 0, 12);
                $this->pdo->exec("SAVEPOINT $spName");
                $useInternalSP = true;
            } catch (\Throwable $_) { $useInternalSP = false; }
        }

        try {
            $this->pdo->prepare($sql)->execute($params);
            // Tout s'est bien passé : release le savepoint
            if ($useInternalSP) {
                try { $this->pdo->exec("RELEASE SAVEPOINT $spName"); } catch (\Throwable $_) {}
            }
        } catch (\PDOException $e) {
            // Rollback au savepoint pour libérer la transaction de l'état "aborted"
            if ($useInternalSP) {
                try { $this->pdo->exec("ROLLBACK TO SAVEPOINT $spName"); } catch (\Throwable $_) {}
            }
            // Filet de sécurité : 23505 sur PK → on resynchronise et on retry.
            if ($this->driver === 'pgsql'
                && ($e->getCode() === '23505' || str_contains((string)$e->getMessage(), 'duplicate key'))
                && preg_match('/^\s*INSERT\s+INTO\s+["`]?([A-Za-z_][A-Za-z0-9_]*)["`]?/i', $sql, $m)
                && str_contains((string)$e->getMessage(), '_pkey')) {
                $table = $m[1];
                if ($this->resyncPgSequence($table, 'Id')) {
                    // Retry dans un nouveau savepoint
                    if ($useInternalSP) {
                        try { $this->pdo->exec("SAVEPOINT $spName"); } catch (\Throwable $_) {}
                    }
                    try {
                        $this->pdo->prepare($sql)->execute($params);
                        if ($useInternalSP) {
                            try { $this->pdo->exec("RELEASE SAVEPOINT $spName"); } catch (\Throwable $_) {}
                        }
                        return;
                    } catch (\Throwable $e2) {
                        if ($useInternalSP) {
                            try { $this->pdo->exec("ROLLBACK TO SAVEPOINT $spName"); $this->pdo->exec("RELEASE SAVEPOINT $spName"); } catch (\Throwable $_) {}
                        }
                        throw $e2;
                    }
                }
            }
            // Release le savepoint vide (le rollback a déjà annulé son contenu)
            if ($useInternalSP) {
                try { $this->pdo->exec("RELEASE SAVEPOINT $spName"); } catch (\Throwable $_) {}
            }
            throw $e;
        }
    }
    /**
     * Dernier identifiant inséré. Lit la valeur figée par execute() plutôt que
     * d'interroger PDO : l'écriture du journal d'audit qui suit chaque INSERT
     * décale sinon lastInsertId() vers la table Journal.
     */
    private function lastId(): int {
        if ($this->_dernierIdInsere > 0) return $this->_dernierIdInsere;
        return (int) $this->pdo->lastInsertId();
    }
    private function isPg(): bool    { return $this->driver === 'pgsql'; }
    private function isMysql(): bool { return $this->driver === 'mariadb' || $this->driver === 'mysql'; }

    /**
     * Resynchronise la séquence d'auto-incrément d'une table PostgreSQL.
     * Utile après un import de données qui aurait laissé la séquence en retard
     * (cause d'erreurs "duplicate key value violates unique constraint").
     *
     * @param string $table  Nom de table (insensible à la casse, ex: "Listes")
     * @param string $idCol  Nom de la colonne ID (par défaut "Id")
     * @return bool true si resync effectué, false sinon (driver non-pg, table inconnue, etc.)
     */
    private function resyncPgSequence(string $table, string $idCol = 'Id'): bool {
        if ($this->driver !== 'pgsql') return false;
        try {
            // Postgres met les identifiants non-quotés en minuscules
            $tableLc = strtolower($table);
            $idColLc = strtolower($idCol);
            // Récupérer le nom de la séquence associée
            $stmt = $this->pdo->query("SELECT pg_get_serial_sequence('$tableLc', '$idColLc') AS seq");
            $row  = $stmt->fetch();
            $seq  = $row['seq'] ?? null;
            if (!$seq) return false;
            // Resync : setval(seq, max(id), true) — true = la prochaine valeur sera max+1
            $this->pdo->exec("SELECT setval('$seq', COALESCE((SELECT MAX(\"$idColLc\") FROM \"$tableLc\"), 1), true)");
            return true;
        } catch (\Throwable $e) {
            // Échec silencieux : ne pas bloquer les opérations courantes
            return false;
        }
    }

    /**
     * Exécute un INSERT en re-synchronisant automatiquement la séquence Postgres
     * en cas d'erreur "unique violation" (SQLSTATE 23505) sur la clé primaire.
     * À utiliser pour les tables qui ont pu être importées avec des IDs explicites.
     *
     * @param string $sql      Requête INSERT
     * @param array  $params   Paramètres
     * @param string $table    Nom de table (pour la resync, ex: "Listes")
     * @param string $idCol    Colonne ID (défaut "Id")
     * @return void
     */
    private function executeWithSeqRetry(string $sql, array $params, string $table, string $idCol = 'Id'): void {
        try {
            $this->execute($sql, $params);
        } catch (\PDOException $e) {
            // SQLSTATE 23505 = unique_violation
            if ($this->driver === 'pgsql' && ($e->getCode() === '23505' || str_contains((string)$e->getMessage(), '23505'))) {
                if ($this->resyncPgSequence($table, $idCol)) {
                    // Réessayer une seule fois après resync
                    $this->execute($sql, $params);
                    return;
                }
            }
            throw $e;
        }
    }

    /**
     * Resynchronise les séquences Postgres de toutes les tables principales.
     * À appeler après un import de données ou pour réparer une base.
     * Retourne le nombre de séquences resynchronisées.
     */
    public function resyncAllPgSequences(): int {
        if ($this->driver !== 'pgsql') return 0;
        $tables = $this->_getKnownTables();
        $count = 0;
        foreach ($tables as $t) {
            if ($this->resyncPgSequence($t, 'Id')) $count++;
        }
        return $count;
    }

    /**
     * Diagnostic des séquences Postgres : pour chaque table connue,
     * retourne MAX(Id), valeur courante de la séquence, et nom de la séquence.
     * Très utile pour comprendre pourquoi un INSERT échoue avec 23505.
     */
    public function diagPgSequences(): array {
        if ($this->driver !== 'pgsql') return [];
        $out = [];
        foreach ($this->_getKnownTables() as $table) {
            try {
                $tableLc = strtolower($table);
                $idColLc = 'id';
                $stmt = $this->pdo->query("SELECT pg_get_serial_sequence('$tableLc', '$idColLc') AS seq");
                $row  = $stmt->fetch();
                $seq  = $row['seq'] ?? null;
                if (!$seq) continue;
                $maxId  = (int)($this->pdo->query("SELECT COALESCE(MAX(\"$idColLc\"),0) AS m FROM \"$tableLc\"")->fetch()['m'] ?? 0);
                $seqVal = (int)($this->pdo->query("SELECT last_value FROM $seq")->fetch()['last_value'] ?? 0);
                $out[] = ['table' => $table, 'sequence' => $seq, 'maxId' => $maxId, 'seqVal' => $seqVal, 'desync' => ($seqVal < $maxId)];
            } catch (\Throwable $e) { /* table inexistante : skip */ }
        }
        return $out;
    }

    /** Liste centrale des tables principales pour resync/diag. */
    private function _getKnownTables(): array {
        return [
            'Utilisateurs','Biens','Equipements','Contrats','Interventions','Stock',
            'GestionMateriel','HistoriqueSuppression','Listes','DemandesIntervention',
            'Configuration','CompteurEnergie','ReleverEnergie','IntervStockLignes',
            'IntervDevisLignes','Documents','NotesInfo','MobiliteCarbone',
            'PushSubscriptions','DeclarationsInventaire',
            'ArchivesBoite','ArchivesBordereau','ArchivesDossier',
            'PlanBatiments','PlanEtages','PlanElements','PlanLiens',
        ];
    }

    /**
     * Formate une date/colonne en 'YYYY-MM' selon le driver.
     * @param string $col  Nom de colonne ou expression SQL (ex: "DateRealisation")
     */
    private function sqlYearMonth(string $col): string {
        if ($this->isPg())    return "TO_CHAR(($col)::DATE, 'YYYY-MM')";
        if ($this->isMysql()) return "DATE_FORMAT($col, '%Y-%m')";
        return "strftime('%Y-%m', $col)";   // SQLite
    }

    /**
     * Expression SQL "date d'aujourd'hui + N jours" en utilisant une colonne entière.
     * Utilisé pour les alertes contrats : DATE(DateFin) <= dateNow + AlerteJoursAvant jours
     * @param string $intCol  Colonne INTEGER contenant le nombre de jours
     */
    private function sqlDatePlusCol(string $intCol): string {
        if ($this->isPg())    return "CURRENT_DATE + ($intCol || ' days')::INTERVAL";
        if ($this->isMysql()) return "DATE_ADD(CURRENT_DATE, INTERVAL $intCol DAY)";
        return "DATE('now', '+' || $intCol || ' days')";  // SQLite
    }

    /**
     * Concaténation de colonnes/chaînes.
     * @param string ...$parts  Colonnes SQL ou chaînes quotées (ex: "Prenom", "' '", "Nom")
     */
    private function sqlConcat(string ...$parts): string {
        if ($this->isMysql()) return 'CONCAT(' . implode(', ', $parts) . ')';
        return implode(' || ', $parts);  // SQLite + PostgreSQL
    }

    /**
     * Agrégation de chaînes (concaténation des valeurs d'un groupe), portable.
     * PG : STRING_AGG | MySQL : GROUP_CONCAT … SEPARATOR | SQLite : GROUP_CONCAT.
     * NB : on n'utilise PAS DISTINCT (incompatible avec un séparateur custom
     * sous SQLite) — dédupliquer côté appelant si besoin. L'$expr doit être du
     * texte (caster au besoin pour PG).
     */
    /**
     * Libellé « Famille / Sous-famille » d'un bien ou d'un équipement, portable
     * (SQLite, PostgreSQL, MySQL).
     *
     * C'est la nomenclature métier attendue pour identifier un bien lié à une
     * intervention. On ne retombe sur InfoProduit (description commerciale du
     * produit) que si la famille ET la sous-famille sont vides, pour ne pas
     * afficher une cellule vide sur les fiches anciennes/importées.
     *
     * @param string $alias Alias SQL de la table (ex: 'b' pour Biens)
     */
    private function sqlFamilleLabel(string $alias): string {
        $fam  = "COALESCE($alias.Famille,'')";
        $sfam = "COALESCE($alias.SousFamille,'')";
        $both = $this->sqlConcat($fam, "' / '", $sfam);
        return "CASE
                  WHEN $fam <> '' AND $sfam <> '' THEN $both
                  WHEN $fam  <> '' THEN $fam
                  WHEN $sfam <> '' THEN $sfam
                  ELSE COALESCE($alias.InfoProduit,'')
                END";
    }

    private function sqlGroupConcat(string $expr, string $sep = ', '): string {
        if ($this->isPg())    return "STRING_AGG($expr, '$sep')";
        if ($this->isMysql()) return "GROUP_CONCAT($expr SEPARATOR '$sep')";
        return "GROUP_CONCAT($expr, '$sep')"; // SQLite
    }

    
// ── Calculs compta (amortissement / dépréciation) ───────────────────────
private function calcAnnualite(?float $valeurAchat, ?int $dureeAmortissement): float {
    $va = $valeurAchat ?? 0;
    $d  = $dureeAmortissement ?? 0;
    if ($va <= 0 || $d <= 0) return 0.0;
    return round($va / $d, 2);
}

private function pickDepreciationStartDate(?string $dateBascule, ?string $dateMES, ?string $dateAchat): ?string {
    if (!empty($dateBascule)) return $dateBascule;
    if (!empty($dateMES))     return $dateMES;
    if (!empty($dateAchat))   return $dateAchat;
    return null;
}

private function calcDepreciationTotal(float $annualite, ?string $startDate, ?string $calcDate, ?float $valeurAchat): float {
    $va = $valeurAchat ?? 0;
    if ($annualite <= 0 || $va <= 0 || empty($startDate)) return 0.0;
    $calc = !empty($calcDate) ? $calcDate : date('Y-m-d');
    try {
        $d0 = new DateTime($startDate);
        $d1 = new DateTime($calc);
    } catch (Exception $e) { return 0.0; }
    if ($d1 < $d0) return 0.0;
    $days  = (float)$d0->diff($d1)->days;
    $years = $days / 365.25;
    $dep   = $annualite * $years;
    if ($dep > $va) $dep = $va;
    return round($dep, 2);
}

// ══ AUTH ══
    public function login(string $login, string $password): ?array {
        $user = $this->fetchOne("SELECT * FROM Utilisateurs WHERE LOWER(Login) = LOWER(:login) AND Actif = 1", ['login' => $login]);
        if (!$user || !password_verify($password, $user['MotDePasse'])) return null;
        unset($user['MotDePasse'], $user['ResetToken'], $user['ResetTokenExpiry']);
        return $user;
    }
public function findOrCreateGoogleUser(string $email, string $prenom, string $nom, string $googleId): ?array {
    // La colonne d'identification est Login (pas Email), comme pour Microsoft
    $user = $this->fetchOne("SELECT Id, Nom, Prenom, Login, Role, Provider, Actif FROM Utilisateurs WHERE LOWER(Login) = LOWER(:login)", ['login' => $email]);
    if ($user) {
        if (!$user['Actif']) return null;
        // Mettre à jour Provider + prénom/nom à chaque connexion
        $this->execute("UPDATE Utilisateurs SET Provider='google', Nom=:nom, Prenom=:prenom WHERE Id=:id",
            ['nom' => $nom ?: $user['Nom'], 'prenom' => $prenom ?: $user['Prenom'], 'id' => $user['Id']]);
        $user['Provider'] = 'google';
        return $user;
    }
    // Créer le nouvel utilisateur avec rôle Nouveau par défaut
    $this->execute(
        "INSERT INTO Utilisateurs (Nom, Prenom, Login, MotDePasse, Role, Provider, DateCreation, Actif)
         VALUES (:nom, :prenom, :login, :pwd, 'Demandeur', 'google', :date, 1)",
        [
            'nom'    => $nom    ?: $email,
            'prenom' => $prenom ?: 'Utilisateur',
            'login'  => $email,
            'pwd'    => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
            'date'   => date('Y-m-d H:i:s'),
        ]
    );
    return $this->fetchOne("SELECT Id, Nom, Prenom, Login, Role, Provider, Actif FROM Utilisateurs WHERE LOWER(Login) = LOWER(:login)", ['login' => $email]);
}
    public function findOrCreateMicrosoftUser(string $email, string $prenom, string $nom, string $msId, string $telMobile = '', string $telPro = '', string $service = '', string $poste = '', string $photoUrl = '', string $officeLocation = '', string $companyName = '', string $managerName = ''): ?array {
        $selectCols = "Id, Nom, Prenom, Login, Email, Tel, TelMobile, TelPro, Service, Poste, Role, Provider, Actif, MicrosoftId, PhotoUrl, OfficeLocation, CompanyName, ManagerName";
        $user = $this->fetchOne("SELECT $selectCols FROM Utilisateurs WHERE LOWER(Login) = LOWER(:login)", ['login' => $email]);
        if ($user) {
            if (!$user['Actif']) return null;
            // Tel principal = mobile en priorité, sinon pro
            $tel = $telMobile ?: $telPro ?: ($user['Tel'] ?? '');
            // Mettre à jour Provider + toutes les données MS à chaque connexion
            $this->execute(
                "UPDATE Utilisateurs SET Provider='microsoft', Nom=:nom, Prenom=:prenom, Email=:email, Tel=:tel, TelMobile=:telMobile, TelPro=:telPro, Service=:service, Poste=:poste, MicrosoftId=:msid, PhotoUrl=:photo, OfficeLocation=:office, CompanyName=:company, ManagerName=:manager WHERE Id=:id",
                [
                    'nom'       => $nom       ?: $user['Nom'],
                    'prenom'    => $prenom    ?: $user['Prenom'],
                    'email'     => $email,
                    'tel'       => $tel,
                    'telMobile' => $telMobile ?: ($user['TelMobile'] ?? ''),
                    'telPro'    => $telPro    ?: ($user['TelPro']    ?? ''),
                    'service'   => $service   ?: ($user['Service']   ?? ''),
                    'poste'     => $poste     ?: ($user['Poste']     ?? ''),
                    'msid'      => $msId      ?: ($user['MicrosoftId'] ?? ''),
                    'photo'     => $photoUrl  ?: ($user['PhotoUrl']  ?? ''),
                    'office'    => $officeLocation ?: ($user['OfficeLocation'] ?? ''),
                    'company'   => $companyName ?: ($user['CompanyName'] ?? ''),
                    'manager'   => $managerName ?: ($user['ManagerName'] ?? ''),
                    'id'        => $user['Id'],
                ]
            );
            $user['Provider']   = 'microsoft';
            $user['Email']      = $email;
            $user['Tel']        = $tel;
            if ($telMobile) $user['TelMobile'] = $telMobile;
            if ($telPro)    $user['TelPro']    = $telPro;
            if ($service) $user['Service'] = $service;
            if ($poste)   $user['Poste']   = $poste;
            if ($msId)    $user['MicrosoftId'] = $msId;
            if ($photoUrl) $user['PhotoUrl'] = $photoUrl;
            if ($officeLocation) $user['OfficeLocation'] = $officeLocation;
            if ($companyName) $user['CompanyName'] = $companyName;
            if ($managerName) $user['ManagerName'] = $managerName;
            return $user;
        }
        // Nouveau user → rôle Demandeur par défaut
        $tel = $telMobile ?: $telPro;
        $this->execute(
            "INSERT INTO Utilisateurs (Nom, Prenom, Login, Email, Tel, TelMobile, TelPro, Service, Poste, MicrosoftId, PhotoUrl, OfficeLocation, CompanyName, ManagerName, MotDePasse, Role, Provider, DateCreation, Actif) VALUES (:nom, :prenom, :login, :email, :tel, :telMobile, :telPro, :service, :poste, :msid, :photo, :office, :company, :manager, :pwd, 'Demandeur', 'microsoft', :date, 1)",
            [
                'nom'       => $nom     ?: $email,
                'prenom'    => $prenom  ?: '',
                'login'     => $email,
                'email'     => $email,
                'tel'       => $tel,
                'telMobile' => $telMobile,
                'telPro'    => $telPro,
                'service'   => $service,
                'poste'     => $poste,
                'msid'      => $msId,
                'photo'     => $photoUrl,
                'office'    => $officeLocation,
                'company'   => $companyName,
                'manager'   => $managerName,
                'pwd'       => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
                'date'      => date('Y-m-d H:i:s'),
            ]
        );
        return $this->fetchOne("SELECT $selectCols FROM Utilisateurs WHERE LOWER(Login) = LOWER(:login)", ['login' => $email]);
    }

    // ══ UTILISATEURS ══
    /** Cache d'existence de la colonne AccesLecture, le temps de la requête. */
    private ?bool $_colAccesLecture = null;

    public function getAllUtilisateurs(): array {
        $cols = "Id, Nom, Prenom, Login, Email, Tel, TelMobile, TelPro, Service, Poste, Role, Provider, DateCreation, Actif, MicrosoftId, PhotoUrl, OfficeLocation, CompanyName, ManagerName";
        // AccesLecture alimente le bloc « Onglets consultables » de la fiche.
        // La colonne est créée par la migration (SCHEMA_VERSION 8), mais on ne
        // fait pas dépendre TOUT l'onglet Comptes de sa présence : si la
        // migration n'a pas encore tourné sur cette base, mieux vaut une fiche
        // sans autorisations qu'un écran d'erreur.
        try {
            return $this->fetchAll("SELECT $cols, AccesLecture FROM Utilisateurs ORDER BY Nom");
        } catch (\Throwable $e) {
            return $this->fetchAll("SELECT $cols FROM Utilisateurs ORDER BY Nom");
        }
    }

    /**
     * Recherche un utilisateur par login (insensible à la casse par défaut).
     * Retourne null si non trouvé. Utilisé par le super-admin pour vérifier
     * l'existence d'un compte AVANT création.
     *
     * ⚠️ FIX PERFS : remplace les patterns `getAllUtilisateurs() + foreach`
     * qui sont O(N) en mémoire et en temps de transfert (transfèrent toute
     * la table au PHP juste pour un seul lookup).
     */
    public function findUtilisateurByLogin(string $login, bool $caseInsensitive = true): ?array {
        $cols = "Id, Nom, Prenom, Login, Email, Tel, TelMobile, TelPro, Service, Poste, Role, Provider, DateCreation, Actif";
        if ($caseInsensitive) {
            $row = $this->fetchOne(
                "SELECT $cols FROM Utilisateurs WHERE LOWER(Login) = LOWER(:login) LIMIT 1",
                ['login' => $login]
            );
        } else {
            $row = $this->fetchOne(
                "SELECT $cols FROM Utilisateurs WHERE Login = :login LIMIT 1",
                ['login' => $login]
            );
        }
        return $row ?: null;
    }

    /**
     * Recherche un utilisateur LOCAL (provider='local') par son ID.
     * Retourne null si l'utilisateur n'existe pas ou n'est pas local
     * (un compte OAuth ne doit pas être supprimé via le flow local).
     */
    public function findLocalUtilisateurById(int $id): ?array {
        $row = $this->fetchOne(
            "SELECT Id, Login, Provider FROM Utilisateurs WHERE Id = :id AND Provider = 'local' LIMIT 1",
            ['id' => $id]
        );
        return $row ?: null;
    }

    /**
     * Recherche un utilisateur par login OU email (insensible à la casse).
     * Utilisé pour la résolution super-admin → compte tenant : un super-admin
     * peut avoir un login différent de son email selon les tenants.
     */
    public function findUtilisateurByLoginOrEmail(string $login, string $email): ?array {
        $cols = "Id, Nom, Prenom, Login, Email, Role, Provider, Actif";
        $row = $this->fetchOne(
            "SELECT $cols FROM Utilisateurs WHERE LOWER(Login) = LOWER(:login) OR LOWER(Email) = LOWER(:email) LIMIT 1",
            ['login' => $login, 'email' => $email]
        );
        return $row ?: null;
    }
    public function addUtilisateur(array $u): int {
        $this->execute("INSERT INTO Utilisateurs (Nom, Prenom, Login, Email, Tel, Service, Poste, MotDePasse, Role, Provider, DateCreation, Actif) VALUES (:nom, :prenom, :login, :email, :tel, :service, :poste, :pwd, :role, :provider, :date, 1)",
            ['nom'=>$u['nom'],'prenom'=>$u['prenom'],'login'=>$u['login'],'email'=>$u['email']??'','tel'=>$u['tel']??'',
             'service'=>$u['service']??'','poste'=>$u['poste']??'',
             'pwd'=>isset($u['motDePasse'])&&$u['motDePasse'] ? password_hash($u['motDePasse'],PASSWORD_BCRYPT) : password_hash(bin2hex(random_bytes(16)),PASSWORD_DEFAULT),
             'role'=>$u['role'],'provider'=>$u['provider']??'local','date'=>date('Y-m-d')]);
        $nouvelId = (int)$this->lastId();
        // Les onglets cochés à la création étaient perdus : l'INSERT ne porte
        // pas la colonne, et seul updateUtilisateur les enregistrait.
        if ($nouvelId > 0 && array_key_exists('accesLecture', $u)) {
            $this->setAccesLecture($nouvelId, $u['accesLecture']);
        }
        return $nouvelId;
    }
    public function updateUtilisateur(int $id, array $u): void {
        $provider = $u['provider'] ?? 'local';
        if (!empty($u['motDePasse'])) {
            $this->execute("UPDATE Utilisateurs SET Nom=:nom,Prenom=:prenom,Login=:login,Email=:email,Tel=:tel,Service=:service,Poste=:poste,MotDePasse=:pwd,Role=:role,Provider=:provider,Actif=:actif WHERE Id=:id",
                ['nom'=>$u['nom'],'prenom'=>$u['prenom'],'login'=>$u['login'],'email'=>$u['email']??'','tel'=>$u['tel']??'','service'=>$u['service']??'','poste'=>$u['poste']??'','pwd'=>password_hash($u['motDePasse'],PASSWORD_BCRYPT),'role'=>$u['role'],'provider'=>$provider,'actif'=>(int)($u['actif']??1),'id'=>$id]);
        } else {
            $this->execute("UPDATE Utilisateurs SET Nom=:nom,Prenom=:prenom,Login=:login,Email=:email,Tel=:tel,Service=:service,Poste=:poste,Role=:role,Provider=:provider,Actif=:actif WHERE Id=:id",
                ['nom'=>$u['nom'],'prenom'=>$u['prenom'],'login'=>$u['login'],'email'=>$u['email']??'','tel'=>$u['tel']??'','service'=>$u['service']??'','poste'=>$u['poste']??'','role'=>$u['role'],'provider'=>$provider,'actif'=>(int)($u['actif']??1),'id'=>$id]);
        }
        // Autorisations de consultation, enregistrées APRÈS le compte : un
        // formulaire qui ne transporte pas le champ ne les efface pas, et si
        // leur écriture échoue, les données du compte sont déjà sauvegardées —
        // l'erreur remonte alors à l'utilisateur au lieu d'être avalée.
        if (array_key_exists('accesLecture', $u)) {
            $this->setAccesLecture($id, $u['accesLecture']);
        }
    }
    public function deleteUtilisateur(int $id): void { $this->execute("DELETE FROM Utilisateurs WHERE Id = :id", ['id' => $id]); }

    /**
     * Garantit l'existence de Utilisateurs.AccesLecture, en la créant au besoin.
     *
     * La colonne est normalement posée par la migration de schéma, mais celle-ci
     * est court-circuitée par un fichier sentinelle : si le témoin n'a pas été
     * invalidé (déploiement partiel, cache d'opcode, fichier non réinscriptible),
     * la colonne manque et TOUT enregistrement d'autorisation échoue en silence.
     * On ne dépend donc pas de la sentinelle : on vérifie, et on crée si besoin.
     *
     * @return bool true si la colonne est utilisable.
     */
    private function ensureColonneAccesLecture(): bool {
        if ($this->_colAccesLecture !== null) return $this->_colAccesLecture;
        try {
            $this->pdo->query("SELECT AccesLecture FROM Utilisateurs LIMIT 1");
            $this->_colAccesLecture = true;
        } catch (\Throwable $e) {
            try {
                $sql = (DB_DRIVER === 'pgsql')
                    ? "ALTER TABLE Utilisateurs ADD COLUMN IF NOT EXISTS AccesLecture TEXT DEFAULT ''"
                    : "ALTER TABLE Utilisateurs ADD COLUMN AccesLecture TEXT DEFAULT ''";
                $this->pdo->exec($sql);
                $this->_colAccesLecture = true;
            } catch (\Throwable $e2) {
                error_log('[Larka] Colonne AccesLecture absente et non créable : ' . $e2->getMessage());
                $this->_colAccesLecture = false;
            }
        }
        return $this->_colAccesLecture;
    }

    /**
     * Enregistre les onglets ouverts en consultation à UN utilisateur.
     * L'autorisation est strictement nominative : liste vide = aucun onglet
     * supplémentaire, l'agent retrouve l'accès habituel d'un demandeur.
     *
     * Lève une exception en cas d'échec : annoncer « enregistré » alors que
     * rien ne l'a été est pire que l'erreur elle-même.
     */
    public function setAccesLecture(int $id, $modules): void {
        $val = '';
        if (is_array($modules)) {
            $val = json_encode(array_values(array_filter($modules, 'is_string')));
        } elseif (is_string($modules) && $modules !== '') {
            $decoded = json_decode($modules, true);
            if (is_array($decoded)) $val = json_encode(array_values(array_filter($decoded, 'is_string')));
        }
        if (!$this->ensureColonneAccesLecture()) {
            throw new \RuntimeException("Impossible d'enregistrer les onglets consultables : la colonne AccesLecture est absente et n'a pas pu être créée. Vérifiez les droits de la base.");
        }
        $this->execute("UPDATE Utilisateurs SET AccesLecture = :v WHERE Id = :id", ['v' => $val, 'id' => $id]);
    }

    /**
     * Lit les autorisations individuelles. Volontairement lu en base à chaque
     * appel plutôt que depuis $_SESSION : une révocation doit prendre effet
     * immédiatement, sans attendre que l'agent se reconnecte.
     */
    public function getAccesLecture(int $id): ?array {
        // En cas d'échec → null, interprété comme « aucun onglet ouvert » :
        // en cas de doute on n'accorde rien plutôt que d'exposer des données.
        if (!$this->ensureColonneAccesLecture()) return null;
        try {
            $row = $this->fetchOne("SELECT AccesLecture FROM Utilisateurs WHERE Id = :id", ['id' => $id]);
        } catch (\Throwable $e) { return null; }
        if (!$row) return null;
        // Les deux casses sont acceptées : sous PostgreSQL la colonne revient
        // en minuscules si le dictionnaire de casse n'a pas été appliqué.
        $brut = $row['AccesLecture'] ?? $row['acceslecture'] ?? '';
        if ($brut === '' || $brut === null) return null;   // jamais renseigné = aucun onglet
        $parsed = json_decode($brut, true);
        return is_array($parsed) ? array_values(array_filter($parsed, 'is_string')) : null;
    }

    // ── Password reset ──
    public function setResetToken(string $login, string $token): bool {
        $user = $this->fetchOne("SELECT Id FROM Utilisateurs WHERE LOWER(Login) = LOWER(:login) AND Actif = 1", ['login' => $login]);
        if (!$user) return false;
        $expiry = date('Y-m-d H:i:s', time() + 900); // 15 min
        $this->execute("UPDATE Utilisateurs SET ResetToken = :token, ResetTokenExpiry = :expiry WHERE Id = :id",
            ['token' => password_hash($token, PASSWORD_BCRYPT), 'expiry' => $expiry, 'id' => $user['Id']]);
        return true;
    }
    public function verifyResetToken(string $login, string $token): bool {
        $user = $this->fetchOne("SELECT ResetToken, ResetTokenExpiry FROM Utilisateurs WHERE LOWER(Login) = LOWER(:login) AND Actif = 1", ['login' => $login]);
        if (!$user || !$user['ResetToken'] || !$user['ResetTokenExpiry']) return false;
        if (strtotime($user['ResetTokenExpiry']) < time()) return false;
        return password_verify($token, $user['ResetToken']);
    }
    public function resetPassword(string $login, string $token, string $newPassword): bool {
        if (!$this->verifyResetToken($login, $token)) return false;
        $this->execute("UPDATE Utilisateurs SET MotDePasse = :pwd, ResetToken = NULL, ResetTokenExpiry = NULL, MustChangePassword = 0 WHERE LOWER(Login) = LOWER(:login)",
            ['pwd' => password_hash($newPassword, PASSWORD_BCRYPT), 'login' => $login]);
        return true;
    }
    public function changePassword(int $userId, string $oldPassword, string $newPassword): bool {
        $user = $this->fetchOne("SELECT MotDePasse FROM Utilisateurs WHERE Id = :id", ['id' => $userId]);
        if (!$user || !password_verify($oldPassword, $user['MotDePasse'])) return false;
        $this->execute("UPDATE Utilisateurs SET MotDePasse = :pwd, MustChangePassword = 0 WHERE Id = :id",
            ['pwd' => password_hash($newPassword, PASSWORD_BCRYPT), 'id' => $userId]);
        return true;
    }
    public function getUserEmail(string $login): ?string {
        $user = $this->fetchOne("SELECT Login, Email, Provider FROM Utilisateurs WHERE LOWER(Login) = LOWER(:login) AND Actif = 1", ['login' => $login]);
        if (!$user) return null;
        // Comptes locaux : utiliser le champ Email dédié
        if (($user['Provider'] ?? 'local') === 'local') {
            return $user['Email'] ?: null;
        }
        // Comptes OAuth : le Login est déjà l'email
        return $user['Login'];
    }
    public function isLocalAccount(string $login): bool {
        $user = $this->fetchOne("SELECT Provider FROM Utilisateurs WHERE LOWER(Login) = LOWER(:login) AND Actif = 1", ['login' => $login]);
        return $user && (($user['Provider'] ?? 'local') === 'local');
    }

    // ══ BIENS ══
    public function getAllBiens(): array { return $this->fetchAll("SELECT * FROM Biens WHERE DateSuppression IS NULL ORDER BY Numero"); }

    /**
     * Version paginée de getAllBiens().
     * Cf. getInterventionsPaginated() pour la justification.
     */
    public function getBiensPaginated(int $limit = 100, int $offset = 0, array $filters = []): array {
        $limit  = max(1, min(1000, $limit));
        $offset = max(0, $offset);

        $where  = ["DateSuppression IS NULL"];
        $params = [];
        if (!empty($filters['search'])) {
            // Recherche simple sur Numero / InfoProduit
            $where[]          = "(LOWER(Numero) LIKE LOWER(:search) OR LOWER(InfoProduit) LIKE LOWER(:search))";
            $params['search'] = '%' . $filters['search'] . '%';
        }
        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $totalRow = $this->fetchOne("SELECT COUNT(*) AS n FROM Biens $whereSql", $params);
        $total    = (int)($totalRow['n'] ?? 0);

        $items = $this->fetchAll(
            "SELECT * FROM Biens $whereSql ORDER BY Numero LIMIT $limit OFFSET $offset",
            $params
        );

        return ['items' => $items, 'total' => $total, 'limit' => $limit, 'offset' => $offset];
    }
    public function getBienById(int $id): ?array { return $this->fetchOne("SELECT * FROM Biens WHERE Id = :id", ['id' => $id]); }
    public function addBien(array $b, string $login=''): int {
    $now = date('Y-m-d H:i:s');
    $p = $this->mapBien($b);
    $p['createdAt'] = $now; $p['createdBy'] = $login;
    $p['updatedAt'] = $now; $p['updatedBy'] = $login;
    $this->execute("INSERT INTO Biens (Numero,Famille,SousFamille,Statut,NumeroSerie,Etat,DateCommande,DateLivraison,Prix,InfoProduit,Batiment,Etage,NumeroBureau,NomPrenom,AffecteUserId,CommentaireSortie,DateSortie,CreatedAt,CreatedBy,UpdatedAt,UpdatedBy) VALUES (:numero,:famille,:sousFamille,:statut,:numeroSerie,:etat,:dateCommande,:dateLivraison,:prix,:infoProduit,:batiment,:etage,:numeroBureau,:nomPrenom,:affecteUserId,:commentaireSortie,:dateSortie,:createdAt,:createdBy,:updatedAt,:updatedBy)", $p);
    return $this->lastId();
}

public function updateBien(int $id, array $b, string $login=''): void {
    $p = $this->mapBien($b);
    $p['id'] = $id;
    $p['updatedAt'] = date('Y-m-d H:i:s');
    $p['updatedBy'] = $login;
    $this->execute("UPDATE Biens SET Numero=:numero,Famille=:famille,SousFamille=:sousFamille,Statut=:statut,NumeroSerie=:numeroSerie,Etat=:etat,DateCommande=:dateCommande,DateLivraison=:dateLivraison,Prix=:prix,InfoProduit=:infoProduit,Batiment=:batiment,Etage=:etage,NumeroBureau=:numeroBureau,NomPrenom=:nomPrenom,AffecteUserId=:affecteUserId,CommentaireSortie=:commentaireSortie,DateSortie=:dateSortie,UpdatedAt=:updatedAt,UpdatedBy=:updatedBy WHERE Id=:id", $p);
}

    public function deleteBien(int $id, string $login): void {
        $b = $this->getBienById($id);
        if ($b) { $this->logSuppression('Biens',$id,$b['Numero'],$b['InfoProduit']??'',$login); $this->execute("UPDATE Biens SET DateSuppression=:date WHERE Id=:id",['date'=>date('Y-m-d'),'id'=>$id]); }
    }
    private function mapBien(array $b): array {
        return ['numero'=>$b['numero']??'','famille'=>$b['famille']??'Mobilier','sousFamille'=>$b['sousFamille']??'','statut'=>$b['statut']??'Commandé','numeroSerie'=>$b['numeroSerie']??'','etat'=>$b['etat']??'Stock','dateCommande'=>($b['dateCommande']??'')?:null,'dateLivraison'=>($b['dateLivraison']??'')?:null,'prix'=>(float)($b['prix']??0),'infoProduit'=>$b['infoProduit']??'','batiment'=>$b['batiment']??'','etage'=>$b['etage']??'','numeroBureau'=>$b['numeroBureau']??'','nomPrenom'=>$b['nomPrenom']??'','affecteUserId'=>(!empty($b['affecteUserId']) ? (int)$b['affecteUserId'] : null),'commentaireSortie'=>$b['commentaireSortie']??'','dateSortie'=>$b['dateSortie']??''];
    }

    // ══ ÉQUIPEMENTS ══
    public function getAllEquipements(): array { return $this->fetchAll("SELECT * FROM Equipements WHERE DateSuppression IS NULL ORDER BY Numero"); }
    public function getEquipementById(int $id): ?array { return $this->fetchOne("SELECT * FROM Equipements WHERE Id = :id", ['id' => $id]); }
    public function addEquipement(array $e, string $login=''): int {
    $now = date('Y-m-d H:i:s');
    $p = $this->mapEquipement($e);
    $p['createdAt'] = $now; $p['createdBy'] = $login;
    $p['updatedAt'] = $now; $p['updatedBy'] = $login;
    $this->execute("INSERT INTO Equipements (Numero,Famille,SousFamille,Statut,NumeroSerie,Etat,DateCommande,DateLivraison,DateInstallation,Prix,InfoProduit,Marque,Modele,Fournisseur,Batiment,Etage,NumeroBureau,NomPrenom,Observations,CommentaireSortie,DateSortie,CreatedAt,CreatedBy,UpdatedAt,UpdatedBy) VALUES (:numero,:famille,:sousFamille,:statut,:numeroSerie,:etat,:dateCommande,:dateLivraison,:dateInstallation,:prix,:infoProduit,:marque,:modele,:fournisseur,:batiment,:etage,:numeroBureau,:nomPrenom,:observations,:commentaireSortie,:dateSortie,:createdAt,:createdBy,:updatedAt,:updatedBy)", $p);
    return $this->lastId();
}

public function updateEquipement(int $id, array $e, string $login=''): void {
    $p = $this->mapEquipement($e);
    $p['id'] = $id;
    $p['updatedAt'] = date('Y-m-d H:i:s');
    $p['updatedBy'] = $login;
    $this->execute("UPDATE Equipements SET Numero=:numero,Famille=:famille,SousFamille=:sousFamille,Statut=:statut,NumeroSerie=:numeroSerie,Etat=:etat,DateCommande=:dateCommande,DateLivraison=:dateLivraison,DateInstallation=:dateInstallation,Prix=:prix,InfoProduit=:infoProduit,Marque=:marque,Modele=:modele,Fournisseur=:fournisseur,Batiment=:batiment,Etage=:etage,NumeroBureau=:numeroBureau,NomPrenom=:nomPrenom,Observations=:observations,CommentaireSortie=:commentaireSortie,DateSortie=:dateSortie,UpdatedAt=:updatedAt,UpdatedBy=:updatedBy WHERE Id=:id", $p);
}

    public function deleteEquipement(int $id, string $login): void {
        $e = $this->getEquipementById($id);
        if ($e) { $this->logSuppression('Equipements',$id,$e['Numero'],"{$e['Marque']} {$e['Modele']}",$login); $this->execute("UPDATE Equipements SET DateSuppression=:date WHERE Id=:id",['date'=>date('Y-m-d'),'id'=>$id]); }
    }
    private function mapEquipement(array $e): array {
        return ['numero'=>$e['numero']??'','famille'=>$e['famille']??'Plomberie','sousFamille'=>$e['sousFamille']??'','statut'=>$e['statut']??'Commandé','numeroSerie'=>$e['numeroSerie']??'','etat'=>$e['etat']??'Stock','dateCommande'=>($e['dateCommande']??'')?:null,'dateLivraison'=>($e['dateLivraison']??'')?:null,'dateInstallation'=>($e['dateInstallation']??'')?:null,'prix'=>(float)($e['prix']??0),'infoProduit'=>$e['infoProduit']??'','marque'=>$e['marque']??'','modele'=>$e['modele']??'','fournisseur'=>$e['fournisseur']??'','batiment'=>$e['batiment']??'','etage'=>$e['etage']??'','numeroBureau'=>$e['numeroBureau']??'','nomPrenom'=>$e['nomPrenom']??'','observations'=>$e['observations']??'','commentaireSortie'=>$e['commentaireSortie']??'','dateSortie'=>$e['dateSortie']??''];
    }

    // ══ CONTRATS ══
    public function getAllContrats(): array { return $this->fetchAll("SELECT * FROM Contrats ORDER BY DateFin DESC"); }
    public function getContratById(int $id): ?array { return $this->fetchOne("SELECT * FROM Contrats WHERE Id = :id", ['id' => $id]); }
    public function getContratsEnAlerte(): array {
        $datePlusCol = $this->sqlDatePlusCol('AlerteJoursAvant');
        return $this->fetchAll(
            "SELECT * FROM Contrats WHERE Statut='Actif' AND DateFin IS NOT NULL
             AND DATE(DateFin) <= $datePlusCol ORDER BY DateFin"
        );
    }
    public function addContrat(array $c, string $login=''): int {
    $now = date('Y-m-d H:i:s');
    $p = $this->mapContrat($c);
    $p['createdAt'] = $now; $p['createdBy'] = $login;
    $p['updatedAt'] = $now; $p['updatedBy'] = $login;
    $this->execute(
        "INSERT INTO Contrats (Numero,Societe,Type,Description,Perimetre,EquipementsIds,DateDebut,DateFin,AlerteJoursAvant,MontantAnnuel,Frequence,ContactNom,ContactTel,ContactEmail,ContactsJSON,Statut,Commentaire,OptionTarifaire,PuissanceSouscrite,PrixBaseHT,PrixHPHT,PrixHCHT,AbonnementHT,FournisseurEnergie,Fluide,HCDebut,HCFin,AbonnementTTC,PrixBaseTTC,TVARate,TVAKwh,TVAAbo,PrixHPTTC,PrixHCTTC,HtaHPHS,HtaHPHSTTC,HtaHCHS,HtaHCHSTTC,HtaHPBS,HtaHPBSTTC,HtaHCBS,HtaHCBSTTC,HtaCEE,HtaCEETTC,HtaCapa,HtaCapaTTC,HtaTVALignes,ReferentClient,ReferenceContrat,TypeCompteur,ChauffageR1,ChauffageR2,EauAbonnementHT,EauAbonnementTTC,EauTVAAbo,EauPrixM3HT,EauPrixM3TTC,EauTVAM3,GazAbonnementHT,GazAbonnementTTC,GazTVAAbo,GazPrixM3HT,GazPrixM3TTC,GazTVAM3,CreatedAt,CreatedBy,UpdatedAt,UpdatedBy)
         VALUES (:numero,:societe,:type,:description,:perimetre,:equipementsIds,:dateDebut,:dateFin,:alerteJoursAvant,:montantAnnuel,:frequence,:contactNom,:contactTel,:contactEmail,:contactsJSON,:statut,:commentaire,:optionTarifaire,:puissanceSouscrite,:prixBaseHT,:prixHPHT,:prixHCHT,:abonnementHT,:fournisseurEnergie,:fluide,:hcDebut,:hcFin,:abonnementTTC,:prixBaseTTC,:tvaRate,:tvaKwh,:tvaAbo,:prixHPTTC,:prixHCTTC,:htaHPHS,:htaHPHSTTC,:htaHCHS,:htaHCHSTTC,:htaHPBS,:htaHPBSTTC,:htaHCBS,:htaHCBSTTC,:htaCEE,:htaCEETTC,:htaCapa,:htaCapaTTC,:htaTVALignes,:referentClient,:referenceContrat,:typeCompteur,:chauffageR1,:chauffageR2,:eauAbonnementHT,:eauAbonnementTTC,:eauTVAAbo,:eauPrixM3HT,:eauPrixM3TTC,:eauTVAM3,:gazAbonnementHT,:gazAbonnementTTC,:gazTVAAbo,:gazPrixM3HT,:gazPrixM3TTC,:gazTVAM3,:createdAt,:createdBy,:updatedAt,:updatedBy)",
        $p
    );
    return $this->lastId();
}

public function updateContrat(int $id, array $c, string $login=''): void {
    $p = $this->mapContrat($c);
    $p['id'] = $id;
    $p['updatedAt'] = date('Y-m-d H:i:s');
    $p['updatedBy'] = $login;
    $this->execute(
        "UPDATE Contrats SET Numero=:numero,Societe=:societe,Type=:type,Description=:description,Perimetre=:perimetre,EquipementsIds=:equipementsIds,DateDebut=:dateDebut,DateFin=:dateFin,AlerteJoursAvant=:alerteJoursAvant,MontantAnnuel=:montantAnnuel,Frequence=:frequence,ContactNom=:contactNom,ContactTel=:contactTel,ContactEmail=:contactEmail,ContactsJSON=:contactsJSON,Statut=:statut,Commentaire=:commentaire,OptionTarifaire=:optionTarifaire,PuissanceSouscrite=:puissanceSouscrite,PrixBaseHT=:prixBaseHT,PrixHPHT=:prixHPHT,PrixHCHT=:prixHCHT,AbonnementHT=:abonnementHT,FournisseurEnergie=:fournisseurEnergie,Fluide=:fluide,HCDebut=:hcDebut,HCFin=:hcFin,AbonnementTTC=:abonnementTTC,PrixBaseTTC=:prixBaseTTC,TVARate=:tvaRate,TVAKwh=:tvaKwh,TVAAbo=:tvaAbo,PrixHPTTC=:prixHPTTC,PrixHCTTC=:prixHCTTC,HtaHPHS=:htaHPHS,HtaHPHSTTC=:htaHPHSTTC,HtaHCHS=:htaHCHS,HtaHCHSTTC=:htaHCHSTTC,HtaHPBS=:htaHPBS,HtaHPBSTTC=:htaHPBSTTC,HtaHCBS=:htaHCBS,HtaHCBSTTC=:htaHCBSTTC,HtaCEE=:htaCEE,HtaCEETTC=:htaCEETTC,HtaCapa=:htaCapa,HtaCapaTTC=:htaCapaTTC,HtaTVALignes=:htaTVALignes,ReferentClient=:referentClient,ReferenceContrat=:referenceContrat,TypeCompteur=:typeCompteur,ChauffageR1=:chauffageR1,ChauffageR2=:chauffageR2,EauAbonnementHT=:eauAbonnementHT,EauAbonnementTTC=:eauAbonnementTTC,EauTVAAbo=:eauTVAAbo,EauPrixM3HT=:eauPrixM3HT,EauPrixM3TTC=:eauPrixM3TTC,EauTVAM3=:eauTVAM3,GazAbonnementHT=:gazAbonnementHT,GazAbonnementTTC=:gazAbonnementTTC,GazTVAAbo=:gazTVAAbo,GazPrixM3HT=:gazPrixM3HT,GazPrixM3TTC=:gazPrixM3TTC,GazTVAM3=:gazTVAM3,UpdatedAt=:updatedAt,UpdatedBy=:updatedBy WHERE Id=:id",
        $p
    );
}

public function deleteContrat(int $id): void {
    // Détacher les interventions liées à ce contrat
    $this->execute("UPDATE Interventions SET ContratId = NULL WHERE ContratId = :id", ['id' => $id]);
    // Détacher les compteurs énergie liés
    try { $this->execute("UPDATE CompteurEnergie SET ContratId = NULL WHERE ContratId = :id", ['id' => $id]); } catch (\Throwable $_) {}
    // Détacher le stock lié
    $this->execute("UPDATE Stock SET ContratId = NULL WHERE ContratId = :id", ['id' => $id]);
    $this->execute("DELETE FROM Contrats WHERE Id = :id", ['id' => $id]);
}
    private function mapContrat(array $c): array { return ['numero'=>$c['numero']??'','societe'=>$c['societe']??'','type'=>$c['type']??'Maintenance','description'=>$c['description']??'','perimetre'=>$c['perimetre']??'','equipementsIds'=>$c['equipementsIds']??'','dateDebut'=>$c['dateDebut']?:null,'dateFin'=>$c['dateFin']?:null,'alerteJoursAvant'=>(int)($c['alerteJoursAvant']??30),'montantAnnuel'=>(float)($c['montantAnnuel']??0),'frequence'=>$c['frequence']??'Annuelle','contactNom'=>$c['contactNom']??'','contactTel'=>$c['contactTel']??'','contactEmail'=>$c['contactEmail']??'','contactsJSON'=>$c['contactsJSON']??null,'statut'=>$c['statut']??'Actif','commentaire'=>$c['commentaire']??'',
'optionTarifaire'=>$c['optionTarifaire']??null,'puissanceSouscrite'=>isset($c['puissanceSouscrite'])?(float)$c['puissanceSouscrite']:null,
'prixBaseHT'=>isset($c['prixBaseHT'])?(float)$c['prixBaseHT']:null,'prixHPHT'=>isset($c['prixHPHT'])?(float)$c['prixHPHT']:null,
'prixHCHT'=>isset($c['prixHCHT'])?(float)$c['prixHCHT']:null,'abonnementHT'=>isset($c['abonnementHT'])?(float)$c['abonnementHT']:null,
'fournisseurEnergie'=>$c['fournisseurEnergie']??null,'fluide'=>$c['fluide']??null,
'hcDebut'=>$c['hcDebut']??null,'hcFin'=>$c['hcFin']??null,'abonnementTTC'=>isset($c['abonnementTTC'])?(float)$c['abonnementTTC']:null,'prixBaseTTC'=>isset($c['prixBaseTTC'])?(float)$c['prixBaseTTC']:null,'tvaRate'=>isset($c['tvaRate'])?(float)$c['tvaRate']:null,
'tvaKwh'=>isset($c['tvaKwh'])?(float)$c['tvaKwh']:null,'tvaAbo'=>isset($c['tvaAbo'])?(float)$c['tvaAbo']:null,
'prixHPTTC'=>isset($c['prixHPTTC'])?(float)$c['prixHPTTC']:null,'prixHCTTC'=>isset($c['prixHCTTC'])?(float)$c['prixHCTTC']:null,
'htaHPHS'=>isset($c['htaHPHS'])?(float)$c['htaHPHS']:null,'htaHPHSTTC'=>isset($c['htaHPHSTTC'])?(float)$c['htaHPHSTTC']:null,
'htaHCHS'=>isset($c['htaHCHS'])?(float)$c['htaHCHS']:null,'htaHCHSTTC'=>isset($c['htaHCHSTTC'])?(float)$c['htaHCHSTTC']:null,
'htaHPBS'=>isset($c['htaHPBS'])?(float)$c['htaHPBS']:null,'htaHPBSTTC'=>isset($c['htaHPBSTTC'])?(float)$c['htaHPBSTTC']:null,
'htaHCBS'=>isset($c['htaHCBS'])?(float)$c['htaHCBS']:null,'htaHCBSTTC'=>isset($c['htaHCBSTTC'])?(float)$c['htaHCBSTTC']:null,
'htaCEE'=>isset($c['htaCEE'])?(float)$c['htaCEE']:null,'htaCEETTC'=>isset($c['htaCEETTC'])?(float)$c['htaCEETTC']:null,
'htaCapa'=>isset($c['htaCapa'])?(float)$c['htaCapa']:null,'htaCapaTTC'=>isset($c['htaCapaTTC'])?(float)$c['htaCapaTTC']:null,
'htaTVALignes'=>$c['htaTVALignes']??null,'referentClient'=>$c['referentClient']??null,'referenceContrat'=>$c['referenceContrat']??null,'typeCompteur'=>$c['typeCompteur']??null,
'chauffageR1'=>isset($c['chauffageR1'])?(float)$c['chauffageR1']:null,'chauffageR2'=>isset($c['chauffageR2'])?(float)$c['chauffageR2']:null,
'eauAbonnementHT'=>isset($c['eauAbonnementHT'])?(float)$c['eauAbonnementHT']:null,'eauAbonnementTTC'=>isset($c['eauAbonnementTTC'])?(float)$c['eauAbonnementTTC']:null,
'eauTVAAbo'=>isset($c['eauTVAAbo'])?(float)$c['eauTVAAbo']:null,'eauPrixM3HT'=>isset($c['eauPrixM3HT'])?(float)$c['eauPrixM3HT']:null,
'eauPrixM3TTC'=>isset($c['eauPrixM3TTC'])?(float)$c['eauPrixM3TTC']:null,'eauTVAM3'=>isset($c['eauTVAM3'])?(float)$c['eauTVAM3']:null,
'gazAbonnementHT'=>isset($c['gazAbonnementHT'])?(float)$c['gazAbonnementHT']:null,'gazAbonnementTTC'=>isset($c['gazAbonnementTTC'])?(float)$c['gazAbonnementTTC']:null,
'gazTVAAbo'=>isset($c['gazTVAAbo'])?(float)$c['gazTVAAbo']:null,'gazPrixM3HT'=>isset($c['gazPrixM3HT'])?(float)$c['gazPrixM3HT']:null,
'gazPrixM3TTC'=>isset($c['gazPrixM3TTC'])?(float)$c['gazPrixM3TTC']:null,'gazTVAM3'=>isset($c['gazTVAM3'])?(float)$c['gazTVAM3']:null]; }

    // ══ INTERVENTIONS ══
    // getAllInterventions defined above with JOINs
    public function getInterventionById(int $id): ?array { return $this->fetchOne("SELECT * FROM Interventions WHERE Id = :id", ['id' => $id]); }
    public function generateInterventionNumero(): string {
        $year  = date('Y');
        $month = date('m');
        $ym    = "$year-$month";
        $colExpr = $this->isPg() ? "COALESCE(DateRealisation::DATE, CURRENT_DATE)" : "COALESCE(DateRealisation, DATE('now'))";
        $col   = $this->sqlYearMonth($colExpr);
        $stmt  = $this->pdo->prepare("SELECT COUNT(*) FROM Interventions WHERE $col = :ym");
        $stmt->execute([':ym' => $ym]);
        $count = (int)$stmt->fetchColumn();
        return sprintf("INT-%s%s-%04d", $year, $month, $count + 1);
    }
    public function addIntervention(array $i, string $login=''): int {
    if (empty($i['numero'])) { $i['numero'] = $this->generateInterventionNumero(); }
    $now = date('Y-m-d H:i:s');
    $p = $this->mapIntervention($i);
    $p['createdAt'] = $now; $p['createdBy'] = $login;
    $p['updatedAt'] = $now; $p['updatedBy'] = $login;

    $this->execute(
        "INSERT INTO Interventions (Numero,Type,Priorite,TypeLiaison,BienId,EquipementsIds,ContratId,DemandeId,SocieteManuelle,Description,DateRealisation,DureeHeures,Montant,MontantMainOeuvre,EstRecurrente,Recurrence,DateProchaine,Statut,Commentaire,AgentNom,AgentPrenom,AgentTel,AgentEmail,DateDemande,DateIntervention,RelanceChamp,RelanceDate,TypeBudget,DateEnvoiMail,CodeNumeroDemande,MontantHT,NotesJSON,DelaiAffichageDashboard,HistoriqueModifDates,SansSuiviAdmin,CreatedAt,CreatedBy,UpdatedAt,UpdatedBy)
         VALUES (:numero,:type,:priorite,:typeLiaison,:bienId,:equipementsIds,:contratId,:demandeId,:societeManuelle,:description,:dateRealisation,:dureeHeures,:montant,:montantMainOeuvre,:estRecurrente,:recurrence,:dateProchaine,:statut,:commentaire,:agentNom,:agentPrenom,:agentTel,:agentEmail,:dateDemande,:dateIntervention,:relanceChamp,:relanceDate,:typeBudget,:dateEnvoiMail,:codeNumeroDemande,:montantHT,:notesJSON,:delaiAffichageDashboard,:historiqueModifDates,:sansSuiviAdmin,:createdAt,:createdBy,:updatedAt,:updatedBy)",
        $p
    );
    $newId = $this->lastId();
    // Si lié à une demande, mettre à jour le statut de la demande
    if (!empty($i['demandeId'])) {
        $this->execute("UPDATE DemandesIntervention SET Statut='En cours' WHERE Id=:id AND Statut='Nouveau'", ['id'=>(int)$i['demandeId']]);
    }
    return $newId;
}

public function updateIntervention(int $id, array $i, string $login=''): void {
    $p = $this->mapIntervention($i);
    $p['id'] = $id;
    $p['updatedAt'] = date('Y-m-d H:i:s');
    $p['updatedBy'] = $login;

    $this->execute(
        "UPDATE Interventions SET Numero=:numero,Type=:type,Priorite=:priorite,TypeLiaison=:typeLiaison,BienId=:bienId,EquipementsIds=:equipementsIds,ContratId=:contratId,DemandeId=:demandeId,SocieteManuelle=:societeManuelle,Description=:description,DateRealisation=:dateRealisation,DureeHeures=:dureeHeures,Montant=:montant,MontantMainOeuvre=:montantMainOeuvre,EstRecurrente=:estRecurrente,Recurrence=:recurrence,DateProchaine=:dateProchaine,Statut=:statut,Commentaire=:commentaire,AgentNom=:agentNom,AgentPrenom=:agentPrenom,AgentTel=:agentTel,AgentEmail=:agentEmail,DateDemande=:dateDemande,DateIntervention=:dateIntervention,RelanceChamp=:relanceChamp,RelanceDate=:relanceDate,TypeBudget=:typeBudget,DateEnvoiMail=:dateEnvoiMail,CodeNumeroDemande=:codeNumeroDemande,MontantHT=:montantHT,NotesJSON=:notesJSON,DelaiAffichageDashboard=:delaiAffichageDashboard,HistoriqueModifDates=:historiqueModifDates,SansSuiviAdmin=:sansSuiviAdmin,UpdatedAt=:updatedAt,UpdatedBy=:updatedBy WHERE Id=:id",
        $p
    );

    // Sync statut demande liée
    $demandeId = $p['demandeId'] ?? null;
    if ($demandeId) {
        $statut = $p['statut'] ?? '';
        if ($statut === 'Validée' || $statut === 'Archivée') {
            $statutDemande = 'Traité';
        } elseif ($statut === 'En cours') {
            $statutDemande = 'En cours';
        } else {
            $statutDemande = null;
        }
        if ($statutDemande) {
            $this->execute("UPDATE DemandesIntervention SET Statut=:s WHERE Id=:id", ['s'=>$statutDemande,'id'=>$demandeId]);
        }
    }
}


    public function deleteIntervention(int $id, string $login): void {
        $i = $this->getInterventionById($id);
        if ($i) {
            // Restituer le stock consommé par cette intervention
            $lignes = $this->fetchAll("SELECT StockId, Quantite FROM IntervStockLignes WHERE InterventionId = :id", ['id' => $id]);
            foreach ($lignes as $l) {
                $this->restituerStock((int)$l['StockId'], (int)$l['Quantite']);
            }
            // Supprimer les lignes stock liées
            $this->execute("DELETE FROM IntervStockLignes WHERE InterventionId = :id", ['id' => $id]);
            // Supprimer les lignes de devis liées
            $this->execute("DELETE FROM IntervDevisLignes WHERE InterventionId = :id", ['id' => $id]);
            // ⚠️ FIX : supprimer les documents joints. La ligne d'intervention
            // étant physiquement supprimée, ces documents deviendraient
            // définitivement inatteignables — et comme le contenu est stocké en
            // base64 dans la colonne Donnees, ils gonflaient la base sans
            // aucun moyen de les retrouver ni de les purger.
            $this->execute("DELETE FROM Documents WHERE EntiteType = 'Intervention' AND EntiteId = :id", ['id' => $id]);
            // Détacher l'intervention de ses factures (les factures, qui peuvent
            // couvrir d'autres interventions, ne sont PAS supprimées).
            $this->execute("DELETE FROM FactureInterventions WHERE InterventionId = :id", ['id' => $id]);
            // Logger et supprimer l'intervention
            $this->logSuppression('Interventions', $id, $i['Numero'], $i['Description'], $login);
            $this->execute("DELETE FROM Interventions WHERE Id = :id", ['id' => $id]);
        }
    }
    private function mapIntervention(array $i): array { return ['numero'=>$i['numero']??'','type'=>$i['type']??'Préventive','priorite'=>$i['priorite']??'','typeLiaison'=>$i['typeLiaison']??'Bien','bienId'=>($i['bienId']??null)?(int)$i['bienId']:null,'equipementsIds'=>$i['equipementsIds']??'','contratId'=>($i['contratId']??null)?(int)$i['contratId']:null,'demandeId'=>($i['demandeId']??null)?(int)$i['demandeId']:null,'societeManuelle'=>$i['societeManuelle']??'','description'=>$i['description']??'','dateRealisation'=>($i['dateRealisation']??'')?:null,'dureeHeures'=>(float)($i['dureeHeures']??0),'montant'=>(float)($i['montant']??0),'montantMainOeuvre'=>(float)($i['montantMainOeuvre']??0),'estRecurrente'=>(int)(bool)($i['estRecurrente']??false),'recurrence'=>$i['recurrence']??'','dateProchaine'=>($i['dateProchaine']??'')?:null,'statut'=>$i['statut']??'Planifiée','commentaire'=>$i['commentaire']??'','agentNom'=>$i['agentNom']??'','agentPrenom'=>$i['agentPrenom']??'','agentTel'=>$i['agentTel']??'','agentEmail'=>$i['agentEmail']??'','dateDemande'=>($i['dateDemande']??'')?:null,'dateIntervention'=>($i['dateIntervention']??'')?:null,'relanceChamp'=>$i['relanceChamp']??'','relanceDate'=>($i['relanceDate']??'')?:null,'typeBudget'=>$i['typeBudget']??'','dateEnvoiMail'=>($i['dateEnvoiMail']??'')?:null,'codeNumeroDemande'=>$i['codeNumeroDemande']??'','montantHT'=>(float)($i['montantHT']??0),'notesJSON'=>$i['notesJSON']??'[]','delaiAffichageDashboard'=>(int)($i['delaiAffichageDashboard']??0),'historiqueModifDates'=>$i['historiqueModifDates']??'[]','sansSuiviAdmin'=>(int)(bool)($i['sansSuiviAdmin']??false)]; }

    // ══ STOCK ══
    public function getAllStock(): array { return $this->fetchAll("SELECT * FROM Stock ORDER BY Designation"); }
    public function getStockEnAlerte(): array { return $this->fetchAll("SELECT * FROM Stock WHERE Quantite <= SeuilAlerte ORDER BY Designation"); }
    public function addStock(array $s, string $login=''): int {
    $now = date('Y-m-d H:i:s');
    $p = $this->mapStock($s);
    $p['createdAt'] = $now; $p['createdBy'] = $login;
    $p['updatedAt'] = $now; $p['updatedBy'] = $login;
    $this->execute("INSERT INTO Stock (Reference,Designation,Categorie,Marque,Modele,TypeArticle,Quantite,SeuilAlerte,PrixUnitaire,Emplacement,Fournisseur,Source,ContratId,CreatedAt,CreatedBy,UpdatedAt,UpdatedBy) VALUES (:reference,:designation,:categorie,:marque,:modele,:typeArticle,:quantite,:seuilAlerte,:prixUnitaire,:emplacement,:fournisseur,:source,:contratId,:createdAt,:createdBy,:updatedAt,:updatedBy)", $p);
    return $this->lastId();
}

public function updateStock(int $id, array $s, string $login=''): void {
    $p = $this->mapStock($s);
    $p['id'] = $id;
    $p['updatedAt'] = date('Y-m-d H:i:s');
    $p['updatedBy'] = $login;
    $this->execute("UPDATE Stock SET Reference=:reference,Designation=:designation,Categorie=:categorie,Marque=:marque,Modele=:modele,TypeArticle=:typeArticle,Quantite=:quantite,SeuilAlerte=:seuilAlerte,PrixUnitaire=:prixUnitaire,Emplacement=:emplacement,Fournisseur=:fournisseur,Source=:source,ContratId=:contratId,UpdatedAt=:updatedAt,UpdatedBy=:updatedBy WHERE Id=:id", $p);
}

    public function consommerStock(int $stockId, int $qte): void {
        if ($this->isPg() || $this->isMysql()) {
            $this->execute("UPDATE Stock SET Quantite = GREATEST(0, Quantite - :qte) WHERE Id = :id", ['qte'=>$qte,'id'=>$stockId]);
        } else {
            $this->execute("UPDATE Stock SET Quantite = MAX(0, Quantite - :qte) WHERE Id = :id", ['qte'=>$qte,'id'=>$stockId]);
        }
    }
    public function restituerStock(int $stockId, int $qte): void {
        $this->execute("UPDATE Stock SET Quantite = Quantite + :qte WHERE Id = :id", ['qte'=>$qte,'id'=>$stockId]);
    }
    public function deleteStock(int $id): void {
        // Vérifier s'il y a des lignes d'intervention liées à ce stock
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM IntervStockLignes WHERE StockId = :id");
        $stmt->execute(['id' => $id]);
        $count = (int)$stmt->fetchColumn();
        if ($count > 0) {
            throw new \RuntimeException("Impossible de supprimer : $count ligne(s) d'intervention utilisent cet article de stock.");
        }
        $this->execute("DELETE FROM Stock WHERE Id = :id", ['id' => $id]);
    }
    private function mapStock(array $s): array { return ['reference'=>$s['reference']??'','designation'=>$s['designation']??'','categorie'=>$s['categorie']??'Consommable','marque'=>$s['marque']??'','modele'=>$s['modele']??'','typeArticle'=>$s['typeArticle']??'','quantite'=>(int)($s['quantite']??0),'seuilAlerte'=>(int)($s['seuilAlerte']??0),'prixUnitaire'=>(float)($s['prixUnitaire']??0),'emplacement'=>$s['emplacement']??'','fournisseur'=>$s['fournisseur']??'','source'=>$s['source']??'Achat','contratId'=>($s['contratId']??null)?(int)$s['contratId']:null]; }

    // ══ GESTION MATÉRIEL ══
    public function getAllGestionMateriel(): array {
    $equipLabel = $this->sqlConcat('e.Marque', "' '", 'e.Modele');
    return $this->fetchAll(
        "SELECT c.*,
                COALESCE(b.Numero, e.Numero) AS AssetNumero,
                COALESCE(b.InfoProduit, $equipLabel) AS AssetLabel,
                COALESCE(b.Etat, e.Etat) AS AssetEtat,
                COALESCE(b.CommentaireSortie, e.CommentaireSortie) AS AssetCommentaireSortie,
                COALESCE(b.CreatedAt, e.CreatedAt) AS AssetCreatedAt,
                COALESCE(b.CreatedBy, e.CreatedBy) AS AssetCreatedBy,
                COALESCE(b.UpdatedAt, e.UpdatedAt) AS AssetUpdatedAt,
                COALESCE(b.UpdatedBy, e.UpdatedBy) AS AssetUpdatedBy
         FROM GestionMateriel c
         LEFT JOIN Biens b ON c.AssetType='Bien' AND c.AssetId=b.Id
         LEFT JOIN Equipements e ON c.AssetType='Equipement' AND c.AssetId=e.Id
         ORDER BY c.Exercice DESC, c.NumeroImmo"
    );
}

    public function addGestionMateriel(array $c): int {
    $p = $this->mapGestionMateriel($c);

    $p['annualite'] = $this->calcAnnualite($p['valeurAchat'], $p['dureeAmortissement']);
    $startDate = $this->pickDepreciationStartDate($p['dateBascule'], $p['dateMiseEnService'], $p['dateAchat']);
    $p['depreciationTotal'] = $this->calcDepreciationTotal($p['annualite'], $startDate, $p['dateCalculDepreciation'], $p['valeurAchat']);

    $this->execute(
        "INSERT INTO GestionMateriel (AssetType,AssetId,NumeroImmo,CompteImmo,Exercice,BonDeCommande,DateMiseEnService,NumeroMandat,DateAchat,ValeurAchat,ValeurVenale,DureeAmortissement,MontantDuMarche,DateBascule,AnnualiteAmortissement,DepreciationTotal,DateCalculDepreciation,User7,Item,EstCloture,DateCloture,MotifCloture)
         VALUES (:assetType,:assetId,:numeroImmo,:compteImmo,:exercice,:bonDeCommande,:dateMiseEnService,:numeroMandat,:dateAchat,:valeurAchat,:valeurVenale,:dureeAmortissement,:montantDuMarche,:dateBascule,:annualite,:depreciationTotal,:dateCalculDepreciation,:user7,:item,:estCloture,:dateCloture,:motifCloture)",
        $p
    );
    return $this->lastId();
}

public function updateGestionMateriel(int $id, array $c): void {
    $p = $this->mapGestionMateriel($c);
    $p['id'] = $id;

    $p['annualite'] = $this->calcAnnualite($p['valeurAchat'], $p['dureeAmortissement']);
    $startDate = $this->pickDepreciationStartDate($p['dateBascule'], $p['dateMiseEnService'], $p['dateAchat']);
    $p['depreciationTotal'] = $this->calcDepreciationTotal($p['annualite'], $startDate, $p['dateCalculDepreciation'], $p['valeurAchat']);

    $this->execute(
        "UPDATE GestionMateriel SET AssetType=:assetType,AssetId=:assetId,NumeroImmo=:numeroImmo,CompteImmo=:compteImmo,Exercice=:exercice,BonDeCommande=:bonDeCommande,DateMiseEnService=:dateMiseEnService,NumeroMandat=:numeroMandat,DateAchat=:dateAchat,ValeurAchat=:valeurAchat,ValeurVenale=:valeurVenale,DureeAmortissement=:dureeAmortissement,MontantDuMarche=:montantDuMarche,DateBascule=:dateBascule,AnnualiteAmortissement=:annualite,DepreciationTotal=:depreciationTotal,DateCalculDepreciation=:dateCalculDepreciation,User7=:user7,Item=:item,EstCloture=:estCloture,DateCloture=:dateCloture,MotifCloture=:motifCloture WHERE Id=:id",
        $p
    );
}

public function deleteGestionMateriel(int $id): void { $this->execute("DELETE FROM GestionMateriel WHERE Id = :id", ['id' => $id]); }

public function isGestionMaterielAssetSorti(int $id): bool {
    $row = $this->fetchOne("SELECT COALESCE(b.Etat, e.Etat) AS AssetEtat FROM GestionMateriel c LEFT JOIN Biens b ON c.AssetType='Bien' AND c.AssetId=b.Id LEFT JOIN Equipements e ON c.AssetType='Equipement' AND c.AssetId=e.Id WHERE c.Id=:id", ['id'=>$id]);
    return $row && in_array($row['AssetEtat'] ?? '', ['Jete/Recycle','Don/Vendu']);
}

public function cloturerGestionMateriel(int $id, string $motif, string $login = ''): void {
    $this->execute(
        "UPDATE GestionMateriel SET EstCloture=1, DateCloture=:date, MotifCloture=:motif, ClotureParLogin=:login WHERE Id=:id",
        ['date' => date('Y-m-d'), 'motif' => $motif, 'login' => $login, 'id' => $id]
    );
}

private function mapGestionMateriel(array $c): array {
    return [
        'assetType' => $c['assetType'] ?? '',
        'assetId'   => (int)($c['assetId'] ?? 0),
        'numeroImmo'=> $c['numeroImmo'] ?? '',
        'compteImmo'=> $c['compteImmo'] ?? '',
        'exercice'  => $c['exercice'] ?? '',
        'bonDeCommande' => $c['bonDeCommande'] ?? '',
        'dateMiseEnService' => !empty($c['dateMiseEnService']) ? $c['dateMiseEnService'] : null,
        'numeroMandat' => $c['numeroMandat'] ?? '',
        'dateAchat' => !empty($c['dateAchat']) ? $c['dateAchat'] : null,
        'valeurAchat' => isset($c['valeurAchat']) ? (float)$c['valeurAchat'] : 0,
        'valeurVenale' => isset($c['valeurVenale']) ? (float)$c['valeurVenale'] : 0,
        'dureeAmortissement' => isset($c['dureeAmortissement']) ? (int)$c['dureeAmortissement'] : 0,
        'montantDuMarche' => isset($c['montantDuMarche']) ? (float)$c['montantDuMarche'] : 0,
        'dateBascule' => !empty($c['dateBascule']) ? $c['dateBascule'] : null,
        'dateCalculDepreciation' => !empty($c['dateCalculDepreciation']) ? $c['dateCalculDepreciation'] : date('Y-m-d'),
        'user7' => $c['user7'] ?? ($c['User7'] ?? ''),
        'item'  => $c['item']  ?? ($c['Item'] ?? ''),
        'estCloture' => (int)($c['estCloture'] ?? ($c['EstCloture'] ?? 0)),
        'dateCloture' => !empty($c['dateCloture']) ? $c['dateCloture'] : null,
        'motifCloture' => $c['motifCloture'] ?? ($c['MotifCloture'] ?? ''),
    ];
}


    // ══ DEMANDES D'INTERVENTION ══
    public function getAllDemandes(array $user): array {
        $concat = $this->sqlConcat('u.Prenom', "' '", 'u.Nom');
        $sql = "SELECT d.*, $concat AS NomDeclarant FROM DemandesIntervention d LEFT JOIN Utilisateurs u ON d.UtilisateurId=u.Id";
        if (in_array($user['Role'], ['Admin','Gestionnaire','Visionneur'])) {
            return $this->fetchAll($sql . " ORDER BY CASE d.Statut WHEN 'Nouveau' THEN 0 WHEN 'Relancé' THEN 1 WHEN 'En cours' THEN 2 ELSE 3 END, d.DateCreation DESC");
        }
        return $this->fetchAll($sql . " WHERE d.UtilisateurId=:uid ORDER BY d.DateCreation DESC", ['uid'=>$user['Id']]);
    }
    public function countDemandesNouveaux(): int {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM DemandesIntervention WHERE Statut='Nouveau'")->fetchColumn();
    }

    public function countDemandesDetail(): array {
        $actifWhere = "Statut NOT IN ('Traité','Refusé')";
        $tech    = (int)$this->pdo->query("SELECT COUNT(*) FROM DemandesIntervention WHERE $actifWhere AND (TypeLocalisation IS NULL OR TypeLocalisation != 'Archive')")->fetchColumn();
        $archive = (int)$this->pdo->query("SELECT COUNT(*) FROM DemandesIntervention WHERE $actifWhere AND TypeLocalisation = 'Archive'")->fetchColumn();
        // Stock en alerte
        $stock   = (int)$this->pdo->query("SELECT COUNT(*) FROM Stock WHERE Quantite <= SeuilAlerte")->fetchColumn();
        // Contrats proches expiration (30 jours)
        if ($this->isPg()) {
            $sqlContrats = "SELECT COUNT(*) FROM Contrats WHERE Statut='Actif' AND DateFin IS NOT NULL AND DateFin != '' AND DateFin::DATE <= CURRENT_DATE + INTERVAL '30 days' AND DateFin::DATE >= CURRENT_DATE";
        } elseif ($this->isMysql()) {
            $sqlContrats = "SELECT COUNT(*) FROM Contrats WHERE Statut='Actif' AND DateFin IS NOT NULL AND DateFin != '' AND DateFin <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND DateFin >= CURDATE()";
        } else {
            $sqlContrats = "SELECT COUNT(*) FROM Contrats WHERE Statut='Actif' AND DateFin IS NOT NULL AND DateFin != '' AND DateFin <= date('now','+30 days') AND DateFin >= date('now')";
        }
        $contrats = (int)$this->pdo->query($sqlContrats)->fetchColumn();
        return [
            'tech'           => $tech,
            'archive'        => $archive,
            'total'          => $tech + $archive,
            'count'          => $tech + $archive, // rétrocompat
            'stockAlerte'    => $stock,
            'contratsAlerte' => $contrats,
        ];
    }
    public function addDemande(array $d, array $user): int {
        $this->execute("INSERT INTO DemandesIntervention (Titre,Description,Batiment,Bureau,Urgence,Statut,UtilisateurId,NomDeclarant,DateCreation,EmailDemandeur,TelDemandeur,PourAutrui,NomAutrui,EmailAutrui,TelAutrui,TypeLocalisation,ArchiveData) VALUES (:titre,:description,:batiment,:bureau,:urgence,'Nouveau',:uid,:nom,:date,:email,:tel,:pourAutrui,:nomAutrui,:emailAutrui,:telAutrui,:typeLoc,:archData)",
            ['titre'=>$d['titre']??'','description'=>$d['description']??'','batiment'=>$d['batiment']??'','bureau'=>$d['bureau']??'','urgence'=>$d['urgence']??'Normale','uid'=>$user['Id'],'nom'=>$user['Prenom'].' '.$user['Nom'],'date'=>date('Y-m-d H:i:s'),
             'email'=>$d['emailDemandeur']??$user['Login']??'','tel'=>$d['telDemandeur']??'',
             'pourAutrui'=>(int)($d['pourAutrui']??0),'nomAutrui'=>$d['nomAutrui']??'','emailAutrui'=>$d['emailAutrui']??'','telAutrui'=>$d['telAutrui']??'',
             'typeLoc'=>$d['typeLocalisation']??'Standard','archData'=>$d['archiveData']??null]);
        return $this->lastId();
    }
    public function updateDemande(int $id, array $d): void {
        $delai = min(730, max(1, (int)($d['delaiRelanceJours'] ?? 7)));
        $sets = "Statut=:statut, CommentaireAdmin=:commentaire, Urgence=:urgence, DelaiRelanceJours=:delai, DateTraitement=:date";
        $params = [
            'statut'      => $d['statut']      ?? 'Nouveau',
            'commentaire' => $d['commentaire'] ?? '',
            'urgence'     => $d['urgence']     ?? 'Normale',
            'delai'       => $delai,
            'date'        => date('Y-m-d H:i:s'),
            'id'          => $id,
        ];
        // Catégorie (admin/gestionnaire)
        if (isset($d['categorie'])) {
            $sets .= ", Categorie=:categorie";
            $params['categorie'] = $d['categorie'];
        }
        // Modification titre/description par admin/gestionnaire
        if (isset($d['titre']) && $d['titre'] !== '') {
            $sets .= ", Titre=:titre";
            $params['titre'] = $d['titre'];
        }
        if (isset($d['description'])) {
            $sets .= ", Description=:descr";
            $params['descr'] = $d['description'];
        }
        if (isset($d['batiment'])) {
            $sets .= ", Batiment=:batiment";
            $params['batiment'] = $d['batiment'];
        }
        if (isset($d['bureau'])) {
            $sets .= ", Bureau=:bureau";
            $params['bureau'] = $d['bureau'];
        }
        $this->execute("UPDATE DemandesIntervention SET $sets WHERE Id=:id", $params);
    }

    public function relancerDemande(int $id): void {
        $this->execute(
            "UPDATE DemandesIntervention SET Statut='Relancé', DateDerniereRelance=:date WHERE Id=:id",
            ['date' => date('Y-m-d H:i:s'), 'id' => $id]
        );
    }

    // ══ DASHBOARD ══
    public function validerIntervention(int $id): void {
        $this->execute("UPDATE Interventions SET Statut='Validée', DateValidation=:d WHERE Id=:id",
            ['d'=>date('Y-m-d H:i:s'),'id'=>$id]);
    }
    public function archiverIntervention(int $id): void {
        $this->execute("UPDATE Interventions SET Statut='Archivée', DateArchivage=:d WHERE Id=:id",
            ['d'=>date('Y-m-d H:i:s'),'id'=>$id]);
    }

    /**
     * Interventions prévues pour le dashboard.
     * - Préventives/Contrôle réglementaire planifiées : toujours affichées
     * - Curatives/Divers planifiées : affichées si DateIntervention <= aujourd'hui + DelaiAffichageDashboard jours
     * Filtrage côté PHP pour compatibilité multi-driver (SQLite, PG, MySQL).
     */
    public function getInterventionsPrevues(): array {
        $bienLabel = $this->sqlFamilleLabel('b');
        $all = $this->fetchAll(
            "SELECT i.*, b.Numero AS BienNumero, $bienLabel AS BienLabel,
             b.Famille AS BienFamille, b.SousFamille AS BienSousFamille, b.InfoProduit AS BienInfoProduit,
             c.Societe AS ContratSociete
             FROM Interventions i
             LEFT JOIN Biens b ON i.BienId=b.Id
             LEFT JOIN Contrats c ON i.ContratId=c.Id
             WHERE i.Statut = 'Planifiée'
             ORDER BY i.DateIntervention ASC, i.Id DESC"
        );
        $today = strtotime('today');
        return array_values(array_filter($all, function($i) use ($today) {
            $type = $i['Type'] ?? '';
            if ($type === 'Préventive' || $type === 'Contrôle réglementaire') return true;
            // Curative / Divers : vérifier le délai
            $dateInterv = $i['DateIntervention'] ?? '';
            if (!$dateInterv) return false;
            $delai = (int)($i['DelaiAffichageDashboard'] ?? 0);
            $dateTs = strtotime($dateInterv);
            if (!$dateTs) return false;
            return $dateTs <= strtotime("+{$delai} days", $today);
        }));
    }

    public function getInterventionsEnCours(): array {
        $bienLabel = $this->sqlFamilleLabel('b');
        return $this->fetchAll(
            "SELECT i.*, b.Numero AS BienNumero, $bienLabel AS BienLabel,
             b.Famille AS BienFamille, b.SousFamille AS BienSousFamille, b.InfoProduit AS BienInfoProduit,
             c.Societe AS ContratSociete
             FROM Interventions i
             LEFT JOIN Biens b ON i.BienId=b.Id
             LEFT JOIN Contrats c ON i.ContratId=c.Id
             WHERE i.Statut = 'En cours'
             ORDER BY i.UpdatedAt DESC, i.Id DESC
             LIMIT 20"
        );
    }

    public function modifierDateInterventionPrevue(int $id, string $nouvelleDate, string $qui, string $raison): void {
        $inv = $this->getInterventionById($id);
        if (!$inv) return;
        // Charger historique existant
        $historique = json_decode($inv['HistoriqueModifDates'] ?? '[]', true) ?: [];
        $historique[] = [
            'ancienneDate' => $inv['DateIntervention'] ?? '',
            'nouvelleDate' => $nouvelleDate,
            'qui'          => $qui,
            'raison'       => $raison,
            'dateModif'    => date('Y-m-d H:i:s'),
        ];
        $this->execute(
            "UPDATE Interventions SET DateIntervention=:date, DateProchaine=:dateP, HistoriqueModifDates=:hist, UpdatedAt=:now WHERE Id=:id",
            ['date' => $nouvelleDate, 'dateP' => $nouvelleDate, 'hist' => json_encode($historique), 'now' => date('Y-m-d H:i:s'), 'id' => $id]
        );
    }

    public function realiserInterventionPrevue(int $id, string $login = ''): void {
        $now = date('Y-m-d H:i:s');
        $today = date('Y-m-d');
        $this->execute(
            "UPDATE Interventions SET Statut='Archivée', DateRealisation=:dr, DateValidation=:dv, DateArchivage=:da, UpdatedAt=:now, UpdatedBy=:login WHERE Id=:id",
            ['dr' => $today, 'dv' => $now, 'da' => $now, 'now' => $now, 'login' => $login, 'id' => $id]
        );
    }
    public function getAllInterventions(): array {
        $bienLabel = $this->sqlFamilleLabel('b');
        $cbdcConcat = $this->sqlGroupConcat('f.TypeBudget');
        return $this->fetchAll(
            "SELECT i.*, d.Titre AS DemandeTitre, b.Numero AS BienNumero, $bienLabel AS BienLabel,
             b.Famille AS BienFamille, b.SousFamille AS BienSousFamille, b.InfoProduit AS BienInfoProduit,
             c.Societe AS ContratSociete,
             (SELECT COALESCE(SUM(fi.MontantLigne),0) FROM FactureInterventions fi WHERE fi.InterventionId = i.Id) AS TotalFacture,
             (SELECT COUNT(*) FROM FactureInterventions fi WHERE fi.InterventionId = i.Id) AS NbFactures,
             (SELECT $cbdcConcat FROM FactureInterventions fi JOIN Factures f ON f.Id = fi.FactureId WHERE fi.InterventionId = i.Id AND COALESCE(f.TypeBudget,'') <> '') AS CbdcFactures,
             (SELECT COUNT(DISTINCT fi2.InterventionId) FROM FactureInterventions fi2 WHERE fi2.FactureId IN (SELECT fx.FactureId FROM FactureInterventions fx WHERE fx.InterventionId = i.Id) AND fi2.InterventionId <> i.Id) AS NbInterventionsLiees
             FROM Interventions i
             LEFT JOIN DemandesIntervention d ON i.DemandeId=d.Id
             LEFT JOIN Biens b ON i.BienId=b.Id
             LEFT JOIN Contrats c ON i.ContratId=c.Id
             WHERE i.Statut != 'Archivée'
             ORDER BY i.Id DESC"
        );
    }

    /**
     * Version paginée de getAllInterventions().
     *
     * ⚠️ FIX PERFS : permet aux clients qui en ont besoin de paginer côté
     * serveur sans tirer toute la table. Le frontend continue d'utiliser
     * getAllInterventions() tant qu'il n'a pas migré.
     *
     * @param int $limit  Nombre max de lignes à retourner (1-1000, défaut 100)
     * @param int $offset Position de départ (0-based)
     * @param array $filters Filtres optionnels : ['statut' => 'En cours', 'assignee' => 12]
     * @return array{items: array, total: int, limit: int, offset: int}
     */
    public function getInterventionsPaginated(int $limit = 100, int $offset = 0, array $filters = []): array {
        $limit  = max(1, min(1000, $limit));
        $offset = max(0, $offset);

        $where  = ["i.Statut != 'Archivée'"];
        $params = [];
        if (!empty($filters['statut'])) {
            $where[]         = "i.Statut = :statut";
            $params['statut'] = $filters['statut'];
        }
        if (!empty($filters['assignee'])) {
            // ⚠️ FIX : il n'existe pas de colonne AssigneA sur Interventions —
            // l'agent qui prend en charge est stocké en clair dans AgentNom /
            // AgentPrenom (cf. prise_en_charge_intervention). On résout donc
            // l'id utilisateur en nom/prénom et on filtre là-dessus. Avant, ce
            // filtre produisait « no such column: i.AssigneA » (HTTP 500).
            $u = $this->fetchOne("SELECT Nom, Prenom FROM Utilisateurs WHERE Id = :id", ['id' => (int)$filters['assignee']]);
            if ($u) {
                $where[]            = "(LOWER(COALESCE(i.AgentNom,'')) = LOWER(:agNom) AND LOWER(COALESCE(i.AgentPrenom,'')) = LOWER(:agPrenom))";
                $params['agNom']    = trim((string)($u['Nom'] ?? ''));
                $params['agPrenom'] = trim((string)($u['Prenom'] ?? ''));
            } else {
                // Utilisateur inconnu : aucun résultat plutôt qu'une erreur SQL.
                $where[] = "1 = 0";
            }
        }
        if (!empty($filters['bien_id'])) {
            $where[]          = "i.BienId = :bien_id";
            $params['bien_id'] = (int)$filters['bien_id'];
        }
        $whereSql = 'WHERE ' . implode(' AND ', $where);

        // Compter le total (sans LIMIT/OFFSET) pour la pagination côté client
        $totalRow = $this->fetchOne(
            "SELECT COUNT(*) AS n FROM Interventions i $whereSql",
            $params
        );
        $total = (int)($totalRow['n'] ?? 0);

        $bienLabel = $this->sqlFamilleLabel('b');

        // ⚠️ LIMIT/OFFSET en injection directe car PDO ne bind pas toujours
        // ces placeholders selon le driver. Les valeurs sont déjà castées en int.
        $items = $this->fetchAll(
            "SELECT i.*, d.Titre AS DemandeTitre, b.Numero AS BienNumero, $bienLabel AS BienLabel,
             b.Famille AS BienFamille, b.SousFamille AS BienSousFamille, b.InfoProduit AS BienInfoProduit,
             c.Societe AS ContratSociete
             FROM Interventions i
             LEFT JOIN DemandesIntervention d ON i.DemandeId=d.Id
             LEFT JOIN Biens b ON i.BienId=b.Id
             LEFT JOIN Contrats c ON i.ContratId=c.Id
             $whereSql
             ORDER BY i.Id DESC
             LIMIT $limit OFFSET $offset",
            $params
        );

        return [
            'items'  => $items,
            'total'  => $total,
            'limit'  => $limit,
            'offset' => $offset,
        ];
    }
    public function getInterventionsArchivees(): array {
        return $this->fetchAll(
            "SELECT i.*, d.Titre AS DemandeTitre, b.Numero AS BienNumero
             FROM Interventions i
             LEFT JOIN DemandesIntervention d ON i.DemandeId=d.Id
             LEFT JOIN Biens b ON i.BienId=b.Id
             WHERE i.Statut = 'Archivée'
             ORDER BY i.DateArchivage DESC"
        );
    }

        // ── IntervStockLignes ──────────────────────────────────────────────────
    public function getLignesIntervention(int $intervId): array {
        return $this->fetchAll(
            "SELECT l.*,s.Reference,s.Designation,s.Categorie FROM IntervStockLignes l
             JOIN Stock s ON l.StockId=s.Id WHERE l.InterventionId=:id ORDER BY l.Id",
            ['id'=>$intervId]
        );
    }
    public function addLigneIntervention(array $l): int {
        $this->execute(
            "INSERT INTO IntervStockLignes (InterventionId,StockId,Quantite,PrixUnitaire,Description,DateConsommation)
             VALUES (:intervId,:stockId,:qte,:prix,:desc,:date)",
            ['intervId'=>(int)$l['interventionId'],'stockId'=>(int)$l['stockId'],
             'qte'=>(int)($l['quantite']??1),'prix'=>(float)($l['prixUnitaire']??0),
             'desc'=>$l['description']??'','date'=>date('Y-m-d')]
        );
        $id = $this->lastId();
        // Décrémenter le stock
        $this->consommerStock((int)$l['stockId'], (int)($l['quantite']??1));
        // Recalculer MontantPieces sur l'intervention
        $this->recalcMontantPieces((int)$l['interventionId']);
        return $id;
    }
    public function deleteLigneIntervention(int $id): void {
        $l = $this->fetchOne("SELECT * FROM IntervStockLignes WHERE Id=:id", ['id'=>$id]);
        if ($l) {
            $this->restituerStock((int)$l['StockId'], (int)$l['Quantite']);
            $this->execute("DELETE FROM IntervStockLignes WHERE Id=:id", ['id'=>$id]);
            $this->recalcMontantPieces((int)$l['InterventionId']);
        }
    }
    public function recalcMontantPieces(int $intervId): void {
        $stmt = $this->pdo->prepare("SELECT COALESCE(SUM(Quantite*PrixUnitaire),0) FROM IntervStockLignes WHERE InterventionId=:id");
        $stmt->execute([':id' => $intervId]);
        $total = $stmt->fetchColumn();
        $this->execute("UPDATE Interventions SET MontantPieces=:v WHERE Id=:id", ['v'=>(float)$total,'id'=>$intervId]);
    }

    // ── IntervDevisLignes ────────────────────────────────────────────────────
    public function getDevisIntervention(int $intervId): array {
        return $this->fetchAll(
            "SELECT * FROM IntervDevisLignes WHERE InterventionId=:id ORDER BY Id",
            ['id'=>$intervId]
        );
    }
    public function addDevisIntervention(int $intervId, array $d): int {
        $this->execute(
            "INSERT INTO IntervDevisLignes (InterventionId,NumeroDevis,MontantHT,MontantTTC,Accepte,Raison,DateReception)
             VALUES (:intervId,:numero,:ht,:ttc,:accepte,:raison,:reception)",
            ['intervId'=>$intervId,'numero'=>$d['numeroDevis']??'','ht'=>(float)($d['montantHT']??0),
             'ttc'=>(float)($d['montantTTC']??0),'accepte'=>$d['accepte']??'','raison'=>$d['raison']??'',
             'reception'=>($d['dateReception']??'')!==''?$d['dateReception']:null]
        );
        return $this->lastId();
    }
    public function updateDevisIntervention(int $id, array $d): void {
        $this->execute(
            "UPDATE IntervDevisLignes SET NumeroDevis=:numero,MontantHT=:ht,MontantTTC=:ttc,Accepte=:accepte,Raison=:raison,DateReception=:reception WHERE Id=:id",
            ['id'=>$id,'numero'=>$d['numeroDevis']??'','ht'=>(float)($d['montantHT']??0),
             'ttc'=>(float)($d['montantTTC']??0),'accepte'=>$d['accepte']??'','raison'=>$d['raison']??'',
             'reception'=>($d['dateReception']??'')!==''?$d['dateReception']:null]
        );
    }
    public function deleteDevisIntervention(int $id): void {
        $this->execute("DELETE FROM IntervDevisLignes WHERE Id=:id", ['id'=>$id]);
    }

    // ── Factures (regroupement d'interventions) ──────────────────────────────────
    // Modèle : une Facture (en-tête : CBDC/CHMA, n° EJ, date EJ, montant) peut
    // regrouper plusieurs interventions via la table de liaison
    // FactureInterventions. MontantLigne y porte la ventilation (« détail ») du
    // montant de la facture sur chaque intervention.

    /** Numéro de facture auto-suggéré (l'utilisateur peut le remplacer par le n° fournisseur). */
    public function generateFactureNumero(): string {
        $year  = date('Y');
        $month = date('m');
        $count = (int)$this->pdo->query("SELECT COUNT(*) FROM Factures")->fetchColumn();
        return sprintf("FAC-%s%s-%04d", $year, $month, $count + 1);
    }

    /** Liste des factures + nb d'interventions rattachées et total ventilé. */
    public function getAllFactures(): array {
        return $this->fetchAll(
            "SELECT f.*,
                    (SELECT COUNT(*)               FROM FactureInterventions fi WHERE fi.FactureId = f.Id) AS NbInterventions,
                    (SELECT COALESCE(SUM(fi.MontantLigne),0) FROM FactureInterventions fi WHERE fi.FactureId = f.Id) AS TotalVentile
             FROM Factures f
             ORDER BY f.Id DESC"
        );
    }

    public function getFactureById(int $id): ?array {
        return $this->fetchOne("SELECT * FROM Factures WHERE Id = :id", ['id' => $id]);
    }

    public function addFacture(array $f, string $login=''): int {
        if (empty($f['numero'])) { $f['numero'] = $this->generateFactureNumero(); }
        $now = date('Y-m-d H:i:s');
        $this->execute(
            "INSERT INTO Factures (Numero,TypeBudget,NumeroEJ,DateEJ,MontantHT,MontantTTC,Fournisseur,DateFacture,Commentaire,CreatedAt,CreatedBy,UpdatedAt,UpdatedBy)
             VALUES (:numero,:typeBudget,:numeroEJ,:dateEJ,:ht,:ttc,:fournisseur,:dateFacture,:commentaire,:createdAt,:createdBy,:updatedAt,:updatedBy)",
            [
                'numero'      => $f['numero'],
                'typeBudget'  => $f['typeBudget']  ?? '',
                'numeroEJ'    => $f['numeroEJ']    ?? '',
                'dateEJ'      => ($f['dateEJ']      ?? '') !== '' ? $f['dateEJ']      : null,
                'ht'          => (float)($f['montantHT']  ?? 0),
                'ttc'         => (float)($f['montantTTC'] ?? 0),
                'fournisseur' => $f['fournisseur'] ?? '',
                'dateFacture' => ($f['dateFacture'] ?? '') !== '' ? $f['dateFacture'] : null,
                'commentaire' => $f['commentaire'] ?? '',
                'createdAt'   => $now, 'createdBy' => $login,
                'updatedAt'   => $now, 'updatedBy' => $login,
            ]
        );
        return $this->lastId();
    }

    public function updateFacture(int $id, array $f, string $login=''): void {
        $this->execute(
            "UPDATE Factures SET Numero=:numero,TypeBudget=:typeBudget,NumeroEJ=:numeroEJ,DateEJ=:dateEJ,MontantHT=:ht,MontantTTC=:ttc,Fournisseur=:fournisseur,DateFacture=:dateFacture,Commentaire=:commentaire,UpdatedAt=:updatedAt,UpdatedBy=:updatedBy WHERE Id=:id",
            [
                'id'          => $id,
                'numero'      => $f['numero']      ?? '',
                'typeBudget'  => $f['typeBudget']  ?? '',
                'numeroEJ'    => $f['numeroEJ']    ?? '',
                'dateEJ'      => ($f['dateEJ']      ?? '') !== '' ? $f['dateEJ']      : null,
                'ht'          => (float)($f['montantHT']  ?? 0),
                'ttc'         => (float)($f['montantTTC'] ?? 0),
                'fournisseur' => $f['fournisseur'] ?? '',
                'dateFacture' => ($f['dateFacture'] ?? '') !== '' ? $f['dateFacture'] : null,
                'commentaire' => $f['commentaire'] ?? '',
                'updatedAt'   => date('Y-m-d H:i:s'), 'updatedBy' => $login,
            ]
        );
    }

    /** Supprime une facture ET ses liaisons (les interventions ne sont pas touchées). */
    public function deleteFacture(int $id, string $login=''): void {
        $f = $this->getFactureById($id);
        if ($f) {
            $this->execute("DELETE FROM FactureInterventions WHERE FactureId = :id", ['id' => $id]);
            $this->logSuppression('Factures', $id, $f['Numero'] ?? '', $f['Commentaire'] ?? '', $login);
            $this->execute("DELETE FROM Factures WHERE Id = :id", ['id' => $id]);
        }
    }

    /** Factures rattachées à une intervention (avec la part ventilée de la ligne). */
    public function getFacturesIntervention(int $intervId): array {
        $autresConcat = $this->sqlGroupConcat('i2.Numero');
        return $this->fetchAll(
            "SELECT fi.Id AS LiaisonId, fi.MontantLigne, fi.Libelle, f.*,
                    (SELECT COUNT(*) FROM FactureInterventions fx WHERE fx.FactureId = f.Id) AS NbInterv,
                    (SELECT $autresConcat FROM FactureInterventions fi3 JOIN Interventions i2 ON i2.Id = fi3.InterventionId WHERE fi3.FactureId = f.Id AND fi3.InterventionId <> fi.InterventionId) AS AutresInterventions
             FROM FactureInterventions fi
             JOIN Factures f ON f.Id = fi.FactureId
             WHERE fi.InterventionId = :id
             ORDER BY fi.Id",
            ['id' => $intervId]
        );
    }

    /** Interventions rattachées à une facture (pour le détail / regroupement). */
    public function getInterventionsForFacture(int $factureId): array {
        return $this->fetchAll(
            "SELECT fi.Id AS LiaisonId, fi.MontantLigne, fi.Libelle,
                    i.Id, i.Numero, i.Description, i.Statut, i.DateRealisation
             FROM FactureInterventions fi
             JOIN Interventions i ON i.Id = fi.InterventionId
             WHERE fi.FactureId = :id
             ORDER BY i.Id",
            ['id' => $factureId]
        );
    }

    /**
     * Rattache une intervention à une facture.
     *   - $d['factureId'] fourni → liaison à une facture existante.
     *   - sinon → création d'une nouvelle facture à partir des champs de $d,
     *     puis liaison.
     * Si la liaison existe déjà (même facture/intervention), on met simplement
     * à jour le montant ventilé. Retourne l'Id de la ligne de liaison.
     */
    public function attachFactureToIntervention(int $intervId, array $d): int {
        if ($intervId <= 0) { throw new \RuntimeException('Intervention invalide.'); }

        $factureId = (int)($d['factureId'] ?? 0);
        if ($factureId <= 0) {
            // Création d'une nouvelle facture à la volée.
            $factureId = $this->addFacture($d, (string)($d['__login'] ?? ''));
        } else {
            // Garde-fou : la facture doit exister.
            if (!$this->getFactureById($factureId)) {
                throw new \RuntimeException('Facture introuvable.');
            }
        }

        $montantLigne = (float)($d['montantLigne'] ?? 0);
        $libelle      = (string)($d['libelle'] ?? '');

        // Dé-duplication : une intervention ne figure qu'une fois par facture.
        $existant = $this->fetchOne(
            "SELECT Id FROM FactureInterventions WHERE FactureId = :f AND InterventionId = :i",
            ['f' => $factureId, 'i' => $intervId]
        );
        if ($existant) {
            $this->execute(
                "UPDATE FactureInterventions SET MontantLigne = :m, Libelle = :l WHERE Id = :id",
                ['m' => $montantLigne, 'l' => $libelle, 'id' => (int)$existant['Id']]
            );
            return (int)$existant['Id'];
        }

        $this->execute(
            "INSERT INTO FactureInterventions (FactureId,InterventionId,MontantLigne,Libelle)
             VALUES (:f,:i,:m,:l)",
            ['f' => $factureId, 'i' => $intervId, 'm' => $montantLigne, 'l' => $libelle]
        );
        return $this->lastId();
    }

    /** Met à jour une ligne de liaison (part ventilée + libellé). */
    public function updateFactureLigne(int $ligneId, array $d): void {
        $this->execute(
            "UPDATE FactureInterventions SET MontantLigne = :m, Libelle = :l WHERE Id = :id",
            ['m' => (float)($d['montantLigne'] ?? 0), 'l' => (string)($d['libelle'] ?? ''), 'id' => $ligneId]
        );
    }

    /** Détache une intervention d'une facture (la facture survit). */
    public function detachFactureLigne(int $ligneId): void {
        $this->execute("DELETE FROM FactureInterventions WHERE Id = :id", ['id' => $ligneId]);
    }

    // ── Documents ───────────────────────────────────────────────────────────────
    public function getDocuments(string $type, int $id): array {
        return $this->fetchAll(
            "SELECT Id,EntiteType,EntiteId,NomFichier,TypeMime,Categorie,Taille,DateAjout,AjoutePar,"
            . "SharePointUrl AS \"SharePointUrl\","
            . "SharePointDriveItemId AS \"SharePointDriveItemId\","
            . "SharePointDriveId AS \"SharePointDriveId\","
            . "SharePointSiteId AS \"SharePointSiteId\","
            . "SharePointLastSync AS \"SharePointLastSync\" "
            . "FROM Documents WHERE EntiteType=:t AND EntiteId=:id ORDER BY DateAjout DESC",
            ['t'=>$type,'id'=>$id]
        );
    }
    public function addDocument(string $type, int $entiteId, string $nom, string $mime, string $categorie, int $taille, string $donnees, string $login): int {
        $this->execute(
            "INSERT INTO Documents (EntiteType,EntiteId,NomFichier,TypeMime,Categorie,Taille,Donnees,DateAjout,AjoutePar) VALUES (:t,:eid,:nom,:mime,:cat,:taille,:data,:date,:login)",
            ['t'=>$type,'eid'=>$entiteId,'nom'=>$nom,'mime'=>$mime,'cat'=>$categorie,'taille'=>$taille,'data'=>$donnees,'date'=>date('Y-m-d H:i:s'),'login'=>$login]
        );
        return $this->lastId();
    }
    /**
     * Met à jour l'instantané local des métadonnées d'un lien SharePoint.
     *
     * Appelé après un appel Graph réussi. On ne stocke QUE des métadonnées
     * (nom, type, taille) — jamais le contenu du fichier, qui reste sur
     * SharePoint. C'est ce qui permet d'afficher une fiche exploitable quand
     * SharePoint est injoignable, sans dupliquer les documents dans la base.
     */
    public function touchSharePointSync(int $id, string $nom = '', string $mime = '', ?int $taille = null): void {
        $sets   = ['SharePointLastSync = :sync'];
        $params = ['sync' => date('Y-m-d H:i:s'), 'id' => $id];
        if ($nom    !== '')   { $sets[] = 'NomFichier = :nom';   $params['nom']    = $nom; }
        if ($mime   !== '')   { $sets[] = 'TypeMime = :mime';    $params['mime']   = $mime; }
        if ($taille !== null) { $sets[] = 'Taille = :taille';    $params['taille'] = $taille; }
        $this->execute("UPDATE Documents SET " . implode(', ', $sets) . " WHERE Id = :id", $params);
    }

    public function addSharePointLink(string $type, int $entiteId, string $nom, string $mime, string $categorie, string $url, string $driveItemId, string $driveId, string $siteId, int $taille, string $login): int {
        $this->execute(
            "INSERT INTO Documents (EntiteType,EntiteId,NomFichier,TypeMime,Categorie,Taille,Donnees,DateAjout,AjoutePar,SharePointUrl,SharePointDriveItemId,SharePointDriveId,SharePointSiteId,SharePointLastSync) VALUES (:t,:eid,:nom,:mime,:cat,:taille,'',:date,:login,:spUrl,:spItem,:spDrive,:spSite,:date)",
            ['t'=>$type,'eid'=>$entiteId,'nom'=>$nom,'mime'=>$mime,'cat'=>$categorie,'taille'=>$taille,'date'=>date('Y-m-d H:i:s'),'login'=>$login,'spUrl'=>$url,'spItem'=>$driveItemId,'spDrive'=>$driveId,'spSite'=>$siteId]
        );
        return $this->lastId();
    }

    /** Lecture des champs SharePoint d'un document (pour résolution par identifiant stable). */
    public function getSharePointDoc(int $id): ?array {
        return $this->fetchOne(
            "SELECT Id,NomFichier,"
            . "SharePointUrl AS \"SharePointUrl\","
            . "SharePointDriveItemId AS \"SharePointDriveItemId\","
            . "SharePointDriveId AS \"SharePointDriveId\","
            . "SharePointSiteId AS \"SharePointSiteId\" "
            . "FROM Documents WHERE Id=:id",
            ['id'=>$id]
        );
    }

    /** Auto-réparation : met à jour l'URL et le nom mis en cache d'un lien SharePoint. */
    public function updateSharePointCache(int $id, string $url, string $nom): void {
        $this->execute("UPDATE Documents SET SharePointUrl=:url, NomFichier=:nom WHERE Id=:id", ['url'=>$url,'nom'=>$nom,'id'=>$id]);
    }
    public function deleteDocument(int $id): void {
        $this->execute("DELETE FROM Documents WHERE Id=:id", ['id'=>$id]);
    }
    public function getDocumentData(int $id): ?array {
        return $this->fetchOne("SELECT NomFichier,TypeMime,Donnees FROM Documents WHERE Id=:id", ['id'=>$id]);
    }
    public function countDocuments(string $type, int $id, string $categorie): int {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM Documents WHERE EntiteType=:t AND EntiteId=:id AND Categorie=:cat");
        $stmt->execute([':t'=>$type,':id'=>$id,':cat'=>$categorie]);
        return (int)$stmt->fetchColumn();
    }

    // ── Stats enrichies ─────────────────────────────────────────────────────────
    public function getStatsAvanceesCompletes(array $filtres = []): array {
        $dateDebut = $filtres['dateDebut'] ?? null;
        $dateFin   = $filtres['dateFin']   ?? null;
        // ⚠️ FIX : ces 2 filtres étaient proposés dans l'UI mais jamais appliqués
        // (la route ne les transmettait pas et la requête ne les utilisait pas).
        $typeInterv   = $filtres['typeInterv']   ?? null;
        $statutInterv = $filtres['statutInterv'] ?? null;
        $where     = '';
        $params    = [];
        if ($dateDebut)    { $where .= " AND DateRealisation >= :dd"; $params['dd'] = $dateDebut; }
        if ($dateFin)      { $where .= " AND DateRealisation <= :df"; $params['df'] = $dateFin; }
        if ($typeInterv)   { $where .= " AND Type = :ti";            $params['ti'] = $typeInterv; }
        if ($statutInterv) { $where .= " AND Statut = :si";          $params['si'] = $statutInterv; }

        return [
            'biensParFamille'      => $this->fetchAll("SELECT Famille,COUNT(*) as Total FROM Biens WHERE DateSuppression IS NULL AND (Etat IS NULL OR Etat NOT IN ('Jete/Recycle','Don/Vendu')) GROUP BY Famille ORDER BY Total DESC"),
            'biensParBatiment'     => $this->fetchAll("SELECT Batiment,COUNT(*) as Total FROM Biens WHERE DateSuppression IS NULL AND Batiment!='' AND (Etat IS NULL OR Etat NOT IN ('Jete/Recycle','Don/Vendu')) GROUP BY Batiment ORDER BY Total DESC"),
            'biensParStatut'       => $this->fetchAll("SELECT Statut,COUNT(*) as Total FROM Biens WHERE DateSuppression IS NULL AND (Etat IS NULL OR Etat NOT IN ('Jete/Recycle','Don/Vendu')) GROUP BY Statut"),
            'equipsParFamille'     => $this->fetchAll("SELECT Famille,COUNT(*) as Total FROM Equipements WHERE DateSuppression IS NULL AND (Etat IS NULL OR Etat NOT IN ('Jete/Recycle','Don/Vendu')) GROUP BY Famille ORDER BY Total DESC"),
            'equipsParStatut'      => $this->fetchAll("SELECT Statut,COUNT(*) as Total FROM Equipements WHERE DateSuppression IS NULL AND (Etat IS NULL OR Etat NOT IN ('Jete/Recycle','Don/Vendu')) GROUP BY Statut"),
            'intervParStatut'      => $this->fetchAll("SELECT Statut,COUNT(*) as Total FROM Interventions WHERE 1=1$where GROUP BY Statut", $params),
            'intervParType'        => $this->fetchAll("SELECT Type,COUNT(*) as Total FROM Interventions WHERE 1=1$where GROUP BY Type", $params),
            'intervParMois'        => $this->fetchAll("SELECT " . $this->sqlYearMonth('DateRealisation') . " as Mois,COUNT(*) as Total,SUM(MontantPieces+MontantMainOeuvre+Montant+MontantHT) as Cout FROM Interventions WHERE DateRealisation IS NOT NULL$where GROUP BY Mois ORDER BY Mois DESC LIMIT 12", $params),
            'coutParSociete'       => $this->fetchAll("SELECT SocieteManuelle as Societe,COUNT(*) as Nb,SUM(Montant+MontantPieces+MontantMainOeuvre+MontantHT) as Total FROM Interventions WHERE SocieteManuelle!=''$where GROUP BY SocieteManuelle ORDER BY Total DESC LIMIT 10", $params),
            'stockValeurParCat'    => $this->fetchAll("SELECT Categorie,COUNT(*) as Articles,SUM(Quantite) as QteTotal,SUM(Quantite*PrixUnitaire) as Valeur FROM Stock GROUP BY Categorie ORDER BY Valeur DESC"),
            'stockEnAlerte'        => $this->fetchAll("SELECT COUNT(*) as Total FROM Stock WHERE Quantite<=SeuilAlerte"),
            'contratsParStatut'    => $this->fetchAll("SELECT Statut,COUNT(*) as Total,COALESCE(SUM(MontantAnnuel),0) as MontantTotal FROM Contrats GROUP BY Statut"),
            // ── Factures / EJ (« avenants ») ──────────────────────────────────
            // Montant = TTC si renseigné, sinon repli sur le HT (certaines
            // factures ne sont saisies qu'en HT).
            'facturesParBudget'    => $this->fetchAll("SELECT COALESCE(NULLIF(TypeBudget,''),'Non renseigné') as TypeBudget,COUNT(*) as Nb,COALESCE(SUM(COALESCE(NULLIF(MontantTTC,0),MontantHT,0)),0) as MontantTotal FROM Factures GROUP BY COALESCE(NULLIF(TypeBudget,''),'Non renseigné') ORDER BY MontantTotal DESC"),
            'facturesParFournisseur'=> $this->fetchAll("SELECT COALESCE(NULLIF(Fournisseur,''),'Non renseigné') as Societe,COUNT(*) as Nb,COALESCE(SUM(COALESCE(NULLIF(MontantTTC,0),MontantHT,0)),0) as Total FROM Factures GROUP BY COALESCE(NULLIF(Fournisseur,''),'Non renseigné') ORDER BY Total DESC LIMIT 10"),
            // ⚠️ FIX PG 22007 : on n'utilise PAS sqlYearMonth() ici (qui ferait
            // (DateFacture)::DATE et planterait sur les dates héritées valant ''
            // au lieu de NULL). La date étant stockée en texte 'YYYY-MM-DD', on
            // extrait directement 'YYYY-MM' (aucune conversion = aucune erreur),
            // et on exclut explicitement NULL et chaîne vide.
            'facturesParMois'      => $this->fetchAll("SELECT SUBSTR(DateFacture,1,7) as Mois,COUNT(*) as Nb,COALESCE(SUM(COALESCE(NULLIF(MontantTTC,0),MontantHT,0)),0) as MontantTotal FROM Factures WHERE DateFacture IS NOT NULL AND DateFacture <> '' GROUP BY SUBSTR(DateFacture,1,7) ORDER BY Mois DESC LIMIT 12"),
            'coutStockParInterv'   => $this->fetchAll("SELECT i.Numero,SUM(l.Quantite*l.PrixUnitaire) as CoutPieces FROM IntervStockLignes l JOIN Interventions i ON l.InterventionId=i.Id GROUP BY i.Id ORDER BY CoutPieces DESC LIMIT 10"),
            'demandesParStatut'    => $this->fetchAll("SELECT Statut,COUNT(*) as Total FROM DemandesIntervention GROUP BY Statut"),
            'demandesParUrgence'   => $this->fetchAll("SELECT Urgence,COUNT(*) as Total FROM DemandesIntervention GROUP BY Urgence"),
            'utilisateursParRole'  => $this->fetchAll("SELECT Role,COUNT(*) as Total FROM Utilisateurs WHERE Actif=1 GROUP BY Role"),
        ];
    }

    public function getDashboardStats(): array {
        return [
            'totalBiens'          => (int)$this->pdo->query("SELECT COUNT(*) FROM Biens WHERE DateSuppression IS NULL AND (Etat IS NULL OR Etat NOT IN ('Jete/Recycle','Don/Vendu'))")->fetchColumn(),
            'totalEquipements'    => (int)$this->pdo->query("SELECT COUNT(*) FROM Equipements WHERE DateSuppression IS NULL AND (Etat IS NULL OR Etat NOT IN ('Jete/Recycle','Don/Vendu'))")->fetchColumn(),
            'interventionsActives'=> (int)$this->pdo->query("SELECT COUNT(*) FROM Interventions WHERE Statut IN ('Planifiée','En cours')")->fetchColumn(),
            'stockEnAlerte'       => (int)$this->pdo->query("SELECT COUNT(*) FROM Stock WHERE Quantite <= SeuilAlerte")->fetchColumn(),
            'contratsExpires'     => (int)$this->pdo->query("SELECT COUNT(*) FROM Contrats WHERE Statut='Expiré'")->fetchColumn(),
            'montantInterventions'=> (float)$this->pdo->query("SELECT COALESCE(SUM(Montant+MontantPieces+MontantMainOeuvre+MontantHT),0) FROM Interventions")->fetchColumn(),
            'demandesNouveaux'    => $this->countDemandesNouveaux(),
        ];
    }

    /**
     * Compte les interventions par mois sur les $months derniers mois (mois courant inclus).
     *
     * La date de référence d'une intervention est, par ordre de préférence :
     *   DateRealisation (réalisée) → DateIntervention (planifiée) → CreatedAt (création).
     *
     * Le regroupement est effectué en PHP pour rester portable entre SQLite / MySQL /
     * PostgreSQL, sans dépendre des fonctions de date propres à chaque moteur
     * (strftime vs DATE_FORMAT vs to_char). Les dates étant stockées au format ISO
     * (« YYYY-MM-DD[ HH:MM:SS] »), le pré-filtre WHERE peut se faire par simple
     * comparaison lexicographique sur la borne basse.
     *
     * @param  int   $months Nombre de mois affichés (borné entre 1 et 24).
     * @return array Liste ORDONNÉE du plus ancien au plus récent :
     *               [ ['mois'=>'YYYY-MM','annee'=>2026,'label'=>'Fév','count'=>3], … ]
     */
    public function getInterventionsParMois(int $months = 6, ?string $debut = null, ?string $fin = null): array {
        $labelsFr = ['01'=>'Jan','02'=>'Fév','03'=>'Mar','04'=>'Avr','05'=>'Mai','06'=>'Jun',
                     '07'=>'Jul','08'=>'Aoû','09'=>'Sep','10'=>'Oct','11'=>'Nov','12'=>'Déc'];

        // Convertit 'YYYY-MM' (ou 'YYYY-MM-DD') en 1er jour du mois ; null si invalide.
        $mkMonth = static function (?string $s): ?\DateTimeImmutable {
            if (!$s || !preg_match('/^(\d{4})-(\d{2})/', $s, $m)) return null;
            $d = \DateTimeImmutable::createFromFormat('Y-m-d', $m[1] . '-' . $m[2] . '-01');
            return $d ? $d->setTime(0, 0, 0) : null;
        };

        $startD = $mkMonth($debut);
        $endD   = $mkMonth($fin);

        if ($startD && $endD) {
            // Période personnalisée « de … à … » ; on tolère l'inversion des bornes.
            if ($startD > $endD) { $t = $startD; $startD = $endD; $endD = $t; }
        } else {
            // Preset : N derniers mois (mois courant inclus).
            $months = max(1, min(60, $months));
            $endD   = (new \DateTimeImmutable('first day of this month'))->setTime(0, 0, 0);
            $startD = $endD->modify('-' . ($months - 1) . ' months');
        }

        // Buckets pré-remplis à 0, du plus ancien au plus récent (borne de sécurité : 120 mois).
        $buckets = [];
        $cursor  = $startD;
        $guard   = 0;
        while ($cursor <= $endD && $guard < 120) {
            $buckets[$cursor->format('Y-m')] = 0;
            $cursor = $cursor->modify('+1 month');
            $guard++;
        }
        if (empty($buckets)) $buckets[$startD->format('Y-m')] = 0;

        $cut = $startD->format('Y-m-d'); // borne basse pour la comparaison ISO

        // On ne tire que les lignes potentiellement dans la fenêtre : au moins une des
        // trois colonnes de date >= borne basse (la borne haute est appliquée au bucketing).
        $rows = $this->fetchAll(
            "SELECT DateRealisation, DateIntervention, CreatedAt
             FROM Interventions
             WHERE COALESCE(DateRealisation,'') >= :c1
                OR COALESCE(DateIntervention,'') >= :c2
                OR COALESCE(CreatedAt,'')        >= :c3",
            ['c1' => $cut, 'c2' => $cut, 'c3' => $cut]
        );

        foreach ($rows as $r) {
            $date = $r['DateRealisation'] ?: ($r['DateIntervention'] ?: ($r['CreatedAt'] ?? ''));
            if (!$date) continue;
            $key = substr((string)$date, 0, 7); // YYYY-MM
            if (isset($buckets[$key])) $buckets[$key]++;
        }

        $out = [];
        foreach ($buckets as $key => $count) {
            $mm    = substr($key, 5, 2);
            $out[] = [
                'mois'  => $key,
                'annee' => (int)substr($key, 0, 4),
                'label' => $labelsFr[$mm] ?? $mm,
                'count' => $count,
            ];
        }
        return $out;
    }

    // ══ HISTORIQUE ══
    private function logSuppression(string $table, int $id, string $numero, string $desc, string $login): void {
        $this->execute("INSERT INTO HistoriqueSuppression (TableSource,IdOriginal,Numero,Description,DateSuppression,SupprimeParLogin) VALUES (:table,:id,:numero,:desc,:date,:login)",
            ['table'=>$table,'id'=>$id,'numero'=>$numero,'desc'=>$desc,'date'=>date('Y-m-d H:i:s'),'login'=>$login]);
    }
    public function getAllHistorique(): array { return $this->fetchAll("SELECT * FROM HistoriqueSuppression ORDER BY DateSuppression DESC"); }

    // ══ STATS AVANCÉES ══
    public function getStatsAvancees(): array {
        return [
            'biensParFamille'    => $this->fetchAll("SELECT Famille,COUNT(*) as Total FROM Biens WHERE DateSuppression IS NULL AND (Etat IS NULL OR Etat NOT IN ('Jete/Recycle','Don/Vendu')) GROUP BY Famille"),
            'equipsParFamille'   => $this->fetchAll("SELECT Famille,COUNT(*) as Total FROM Equipements WHERE DateSuppression IS NULL AND (Etat IS NULL OR Etat NOT IN ('Jete/Recycle','Don/Vendu')) GROUP BY Famille"),
            'intervParStatut'    => $this->fetchAll("SELECT Statut,COUNT(*) as Total FROM Interventions GROUP BY Statut"),
            'coutParSociete'     => $this->fetchAll("SELECT SocieteManuelle,SUM(Montant+MontantPieces+MontantMainOeuvre+MontantHT) as Total FROM Interventions WHERE SocieteManuelle!='' GROUP BY SocieteManuelle ORDER BY Total DESC LIMIT 10"),
            'stockValeur'        => $this->fetchAll("SELECT Categorie,SUM(Quantite*PrixUnitaire) as ValeurTotale FROM Stock GROUP BY Categorie"),
            'contratsParStatut'  => $this->fetchAll("SELECT Statut,COUNT(*) as Total,SUM(MontantAnnuel) as MontantTotal FROM Contrats GROUP BY Statut"),
            'utilisateursParRole'=> $this->fetchAll("SELECT Role,COUNT(*) as Total FROM Utilisateurs WHERE Actif=1 GROUP BY Role"),
            'demandesParStatut'  => $this->fetchAll("SELECT Statut,COUNT(*) as Total FROM DemandesIntervention GROUP BY Statut"),
        ];
    }
    // ── Énergie ──────────────────────────────────────────────────────────────────
private bool $_energieInitDone = false;
private function initEnergieTable(): void {
    if ($this->_energieInitDone) return;
    $this->_energieInitDone = true;
    $isPg    = $this->isPg();
    $isMysql = $this->isMysql();
    $AI      = $isPg ? 'SERIAL PRIMARY KEY' : ($isMysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT');

    $this->pdo->exec("CREATE TABLE IF NOT EXISTS CompteurEnergie (
        Id $AI,
        Nom TEXT NOT NULL, Type TEXT NOT NULL,
        Unite TEXT, Site TEXT, NumeroCompteur TEXT,
        ContratId INTEGER, DateInstallation TEXT,
        Actif INTEGER DEFAULT 1,
        SousCompteurs TEXT
    )");
    $this->pdo->exec("CREATE TABLE IF NOT EXISTS ReleverEnergie (
        Id $AI,
        CompteurId INTEGER NOT NULL,
        Type TEXT DEFAULT 'Releve',
        Date TEXT NOT NULL,
        IndexDebut REAL, IndexFin REAL,
        Consommation REAL, Montant REAL,
        Fournisseur TEXT, NumeroFacture TEXT,
        PeriodeDebut TEXT, PeriodeFin TEXT,
        Commentaire TEXT, SaisieParLogin TEXT,
        DetailSousCompteurs TEXT,
        DateSaisie TEXT DEFAULT CURRENT_TIMESTAMP
    )");
    // Colonnes tarification (migration idempotente)
    foreach ([
        ['CompteurEnergie','PrixTTC','REAL'],
        ['CompteurEnergie','PrixHT','REAL'],
        ['CompteurEnergie','PrixHP','REAL'],
        ['CompteurEnergie','PrixHC','REAL'],
        ['CompteurEnergie','Abonnement','REAL'],
        ['CompteurEnergie','AbonnementTTC','REAL'],
        ['CompteurEnergie','PrixUnitaireTTC','REAL'],
        ['CompteurEnergie','TVARate','REAL'],
        ['CompteurEnergie','ChauffageR1','REAL'],
        ['CompteurEnergie','ChauffageR2','REAL'],
        ['CompteurEnergie','RepartitionPresta','TEXT'],
        ['CompteurEnergie','ChauffageTED','TEXT'],
        ['CompteurEnergie','PuissanceSouscrite','REAL'],
        ['CompteurEnergie','SousCompteurs','TEXT'],
        ['ReleverEnergie','ModeFacture','TEXT'],
        ['ReleverEnergie','DetailHTA','TEXT'],
        ['ReleverEnergie','DetailChauffage','TEXT'],
        ['ReleverEnergie','PlagesAvance','TEXT'],
        ['ReleverEnergie','Carbone','REAL'],
        ['ReleverEnergie','MontantHT','REAL'],
        ['ReleverEnergie','PrixUnitHT','REAL'],
        ['ReleverEnergie','TVAReleve','REAL'],
        ['ReleverEnergie','TVA','REAL'],
    ] as [$t,$c,$d]) {
        try {
            $alterSql = $isPg
                ? "ALTER TABLE $t ADD COLUMN IF NOT EXISTS $c $d"
                : "ALTER TABLE $t ADD COLUMN $c $d";
            $this->pdo->exec($alterSql);
        } catch(\PDOException $e) {}
    }
}

public function getEnergieCompteurs(): array {
    $this->initEnergieTable();
    return $this->fetchAll("SELECT * FROM CompteurEnergie ORDER BY Type, Nom");
}

public function addEnergieCompteur(array $d): int {
    $this->initEnergieTable();
    $s = $this->pdo->prepare("INSERT INTO CompteurEnergie
        (Nom,Type,Unite,Site,NumeroCompteur,ContratId,DateInstallation,Actif,PrixTTC,PrixHT,PrixHP,PrixHC,Abonnement,AbonnementTTC,PrixUnitaireTTC,TVARate,ChauffageR1,ChauffageR2,RepartitionPresta,ChauffageTED,PuissanceSouscrite,SousCompteurs)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $s->execute([$d['nom'],$d['type'],$d['unite']??null,$d['site']??null,
        $d['numeroCompteur']??null,$d['contratId']??null,
        $d['dateInstallation']??null,$d['actif']??1,
        $d['prixTTC']??null,$d['prixHT']??null,$d['prixHP']??null,$d['prixHC']??null,$d['abonnement']??null,
        $d['abonnementTTC']??null,($d['prixUnitaireTTC']??($d['prixTTC']??null)),$d['tvaRate']??null,$d['chauffageR1']??null,$d['chauffageR2']??null,
        $d['repartitionPresta']??null,$d['chauffageTED']??null,$d['puissanceSouscrite']??null,$d['sousCompteurs']??null]);
    return $this->lastId();
}
public function updateEnergieCompteur(int $id, array $d): void {
    $s = $this->pdo->prepare("UPDATE CompteurEnergie SET
        Nom=?,Type=?,Unite=?,Site=?,NumeroCompteur=?,ContratId=?,DateInstallation=?,Actif=?,
        PrixTTC=?,PrixHT=?,PrixHP=?,PrixHC=?,Abonnement=?,AbonnementTTC=?,PrixUnitaireTTC=?,TVARate=?,ChauffageR1=?,ChauffageR2=?,RepartitionPresta=?,ChauffageTED=?,PuissanceSouscrite=?,SousCompteurs=?
        WHERE Id=?");
    $s->execute([$d['nom'],$d['type'],$d['unite']??null,$d['site']??null,
        $d['numeroCompteur']??null,$d['contratId']??null,
        $d['dateInstallation']??null,$d['actif']??1,
        $d['prixTTC']??null,$d['prixHT']??null,$d['prixHP']??null,$d['prixHC']??null,$d['abonnement']??null,
        $d['abonnementTTC']??null,($d['prixUnitaireTTC']??($d['prixTTC']??null)),$d['tvaRate']??null,$d['chauffageR1']??null,$d['chauffageR2']??null,
        $d['repartitionPresta']??null,$d['chauffageTED']??null,$d['puissanceSouscrite']??null,$d['sousCompteurs']??null,$id]);
}

public function deleteEnergieCompteur(int $id): void {
    $this->pdo->prepare("DELETE FROM ReleverEnergie WHERE CompteurId=?")->execute([$id]);
    $this->pdo->prepare("DELETE FROM CompteurEnergie WHERE Id=?")->execute([$id]);
}

public function getEnergieReleves(int $compteurId): array {
    $this->initEnergieTable();
    return $this->fetchAll(
        "SELECT * FROM ReleverEnergie WHERE CompteurId=? ORDER BY Date DESC",
        [$compteurId]
    );
}

public function addEnergieReleve(array $d): int {
    $this->initEnergieTable();
    $s = $this->pdo->prepare("INSERT INTO ReleverEnergie
        (CompteurId,Type,Date,IndexDebut,IndexFin,Consommation,Montant,
         Fournisseur,NumeroFacture,PeriodeDebut,PeriodeFin,Commentaire,SaisieParLogin,ModeFacture,DetailHTA,DetailChauffage,PlagesAvance,Carbone,MontantHT,PrixUnitHT,TVAReleve,TVA,DetailSousCompteurs)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $s->execute([
        $d['compteurId'],$d['type']??'Releve',$d['date'],
        $d['indexDebut']??null,$d['indexFin']??null,
        $d['consommation']??null,$d['montant']??null,
        $d['fournisseur']??null,$d['numeroFacture']??null,
        $d['periodeDebut']??null,$d['periodeFin']??null,
        $d['commentaire']??null,
        $_SESSION['user']['Login']??null,
        $d['modeFacture']??null,$d['detailHTA']??null,$d['detailChauffage']??null,$d['plagesAvance']??null,
        $d['carbone']??null,$d['montantHT']??null,$d['prixUnitHT']??null,$d['tvaReleve']??null,$d['tva']??null,
        $d['detailSousCompteurs']??null
    ]);
    return $this->lastId();
}

public function updateEnergieReleve(int $id, array $d): void {
    $s = $this->pdo->prepare("UPDATE ReleverEnergie SET
        CompteurId=?,Type=?,Date=?,IndexDebut=?,IndexFin=?,Consommation=?,Montant=?,
        Fournisseur=?,NumeroFacture=?,PeriodeDebut=?,PeriodeFin=?,Commentaire=?,ModeFacture=?,DetailHTA=?,DetailChauffage=?,PlagesAvance=?,Carbone=?,MontantHT=?,PrixUnitHT=?,TVAReleve=?,TVA=?,DetailSousCompteurs=?
        WHERE Id=?");
    $s->execute([$d['compteurId'],$d['type']??'Releve',$d['date'],
        $d['indexDebut']??null,$d['indexFin']??null,
        $d['consommation']??null,$d['montant']??null,
        $d['fournisseur']??null,$d['numeroFacture']??null,
        $d['periodeDebut']??null,$d['periodeFin']??null,
        $d['commentaire']??null,
        $d['modeFacture']??null,$d['detailHTA']??null,$d['detailChauffage']??null,$d['plagesAvance']??null,
        $d['carbone']??null,$d['montantHT']??null,$d['prixUnitHT']??null,$d['tvaReleve']??null,$d['tva']??null,$d['detailSousCompteurs']??null,$id]);
}

public function deleteEnergieReleve(int $id): void {
    $this->pdo->prepare("DELETE FROM ReleverEnergie WHERE Id=?")->execute([$id]);
}

// ── Facteurs Carbone (par année / type d'énergie) ──────────────────────────
private bool $_carboneInitDone = false;
private function initCarboneTable(): void {
    if ($this->_carboneInitDone) return;
    $this->_carboneInitDone = true;
    $isPg = $this->isPg();
    $isMysql = $this->isMysql();
    $AI = $isPg ? 'SERIAL PRIMARY KEY' : ($isMysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT');

    // ── Table 1 : Facteurs OFFICIELS (ADEME Base Carbone) ──
    $this->pdo->exec("CREATE TABLE IF NOT EXISTS FacteurCarboneOfficiel (
        Id $AI,
        Annee INTEGER NOT NULL,
        Type TEXT NOT NULL,
        FacteurCO2 REAL NOT NULL,
        Unite TEXT,
        Source TEXT,
        IdentifiantADEME TEXT,
        NomADEME TEXT,
        Incertitude REAL,
        DateMaj TEXT DEFAULT CURRENT_TIMESTAMP
    )");
    try { $this->pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_fco_annee_type ON FacteurCarboneOfficiel(Annee, Type)"); } catch (\PDOException $e) {}

    // ── Table 2 : Facteurs SIMPLIFIÉS (Impact CO2 / Datagir) ──
    $this->pdo->exec("CREATE TABLE IF NOT EXISTS FacteurCarboneSimple (
        Id $AI,
        IdReseau TEXT NOT NULL DEFAULT '',
        Annee INTEGER NOT NULL,
        Type TEXT NOT NULL,
        FacteurCO2 REAL NOT NULL,
        Unite TEXT,
        Source TEXT,
        NomADEME TEXT,
        Incertitude REAL,
        DateMaj TEXT DEFAULT CURRENT_TIMESTAMP
    )");
    try { $this->pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_fcs_annee_type ON FacteurCarboneSimple(Annee, Type)"); } catch (\PDOException $e) {}

    // ── Nettoyage des doublons éventuels (garder le plus récent) ──
    foreach (['FacteurCarboneOfficiel', 'FacteurCarboneSimple'] as $_t) {
        try {
            $this->pdo->exec("DELETE FROM $_t WHERE Id NOT IN (SELECT MAX(Id) FROM $_t GROUP BY Annee, Type)");
        } catch (\PDOException $e) {}
    }

    // ── Migration : supprimer l'ancienne table FacteurCarbone (données corrompues) ──
    // Les anciennes données mélangées ne sont PAS migrées : l'utilisateur fera un
    // "Sync ADEME" et "Enregistrer simplifiés" pour repartir propre.
    try {
        if (!$isPg && !$isMysql) {
            $cols = $this->pdo->query("PRAGMA table_info(FacteurCarbone)")->fetchAll(\PDO::FETCH_ASSOC);
            if (count($cols) > 0) {
                $this->pdo->exec("DROP TABLE IF EXISTS FacteurCarbone");
            }
        } else {
            try { $this->pdo->exec("DROP TABLE IF EXISTS FacteurCarbone"); } catch (\PDOException $e) {}
        }
    } catch (\PDOException $e) {
        // Table n'existe pas → OK
    }

    // ── Table ReseauChaleur (arrêté DPE) ──
    $this->pdo->exec("CREATE TABLE IF NOT EXISTS ReseauChaleur (
        Id $AI,
        IdReseau TEXT NOT NULL,
        Nom TEXT,
        Commune TEXT,
        Gestionnaire TEXT,
        CO2 REAL,
        CO2ACV REAL,
        TauxEnRR REAL,
        DateMaj TEXT DEFAULT CURRENT_TIMESTAMP
    )");
    try { $this->pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_reseau_id ON ReseauChaleur(IdReseau)"); } catch (\PDOException $e) {}
}

// ── Facteurs : accès unifié avec paramètre méthode ──────────────────────────
private function _carboneTable(string $methode): string {
    return $methode === 'simplifiee' ? 'FacteurCarboneSimple' : 'FacteurCarboneOfficiel';
}

public function getFacteursCarbone(string $methode = ''): array {
    $this->initCarboneTable();
    if ($methode === 'officielle') return $this->fetchAll("SELECT *, 'officielle' as Methode FROM FacteurCarboneOfficiel ORDER BY Annee DESC, Type");
    if ($methode === 'simplifiee') return $this->fetchAll("SELECT *, 'simplifiee' as Methode FROM FacteurCarboneSimple ORDER BY Annee DESC, Type");
    // Les deux
    $off = $this->fetchAll("SELECT *, 'officielle' as Methode FROM FacteurCarboneOfficiel ORDER BY Annee DESC, Type");
    $sim = $this->fetchAll("SELECT *, 'simplifiee' as Methode FROM FacteurCarboneSimple ORDER BY Annee DESC, Type");
    return array_merge($off, $sim);
}

public function getFacteurCarbone(string $type, int $annee, string $methode = 'officielle'): ?array {
    $this->initCarboneTable();
    $table = $this->_carboneTable($methode);
    $r = $this->fetchAll("SELECT * FROM $table WHERE Type=? AND Annee=?", [$type, $annee]);
    return $r[0] ?? null;
}

/**
 * Facteur applicable à une année, avec repli explicite.
 *
 * On cherche d'abord le millésime exact, puis en ARRIÈRE (le plus récent
 * antérieur), enfin en AVANT à défaut. Le sens du report est renvoyé dans
 * `_report` pour que l'interface puisse alerter : appliquer un facteur
 * POSTÉRIEUR à un exercice clos n'est pas défendable, alors qu'un facteur
 * antérieur reste conservateur.
 *
 * `_report` vaut 'exact', 'arriere' ou 'avant' ; `_anneeFacteur` porte l'année
 * réellement utilisée.
 */
public function getFacteurCarbonePlusProcheAnnee(string $type, int $annee, string $methode = 'officielle'): ?array {
    $this->initCarboneTable();
    $table = $this->_carboneTable($methode);

    $r = $this->fetchAll("SELECT * FROM $table WHERE Type=? AND Annee=? LIMIT 1", [$type, $annee]);
    if (!empty($r[0])) {
        $r[0]['_report'] = 'exact';
        $r[0]['_anneeFacteur'] = (int)($r[0]['Annee'] ?? $annee);
        return $r[0];
    }

    $r = $this->fetchAll("SELECT * FROM $table WHERE Type=? AND Annee<? ORDER BY Annee DESC LIMIT 1", [$type, $annee]);
    if (!empty($r[0])) {
        $r[0]['_report'] = 'arriere';
        $r[0]['_anneeFacteur'] = (int)($r[0]['Annee'] ?? $annee);
        return $r[0];
    }

    // Dernier recours : le millésime le plus ancien disponible, forcément
    // postérieur à l'année demandée. Mieux vaut une valeur tracée qu'un repli
    // muet sur les constantes du code — mais l'UI doit le signaler fortement.
    $r = $this->fetchAll("SELECT * FROM $table WHERE Type=? AND Annee>? ORDER BY Annee ASC LIMIT 1", [$type, $annee]);
    if (!empty($r[0])) {
        $r[0]['_report'] = 'avant';
        $r[0]['_anneeFacteur'] = (int)($r[0]['Annee'] ?? $annee);
        return $r[0];
    }

    return null;
}

public function upsertFacteurCarbone(array $d): int {
    $this->initCarboneTable();
    $annee   = (int)$d['annee'];
    $type    = $d['type'];
    $methode = $d['methode'] ?? 'officielle';
    $table   = $this->_carboneTable($methode);

    // DELETE + INSERT = aucun risque de conflit d'index
    $this->pdo->prepare("DELETE FROM $table WHERE Annee=? AND Type=?")->execute([$annee, $type]);

    if ($methode === 'simplifiee') {
        $s = $this->pdo->prepare("INSERT INTO FacteurCarboneSimple (Annee,Type,FacteurCO2,Unite,Source,NomADEME,Incertitude) VALUES (?,?,?,?,?,?,?)");
        $s->execute([$annee, $type, $d['facteurCO2'], $d['unite']??null, $d['source']??null, $d['nomADEME']??null, $d['incertitude']??null]);
    } else {
        $s = $this->pdo->prepare("INSERT INTO FacteurCarboneOfficiel (Annee,Type,FacteurCO2,Unite,Source,IdentifiantADEME,NomADEME,Incertitude) VALUES (?,?,?,?,?,?,?,?)");
        $s->execute([$annee, $type, $d['facteurCO2'], $d['unite']??null, $d['source']??null, $d['identifiantADEME']??null, $d['nomADEME']??null, $d['incertitude']??null]);
    }
    return $this->lastId();
}

public function deleteFacteurCarbone(int $id, string $methode = 'officielle'): void {
    $this->initCarboneTable();
    $table = $this->_carboneTable($methode);
    $this->pdo->prepare("DELETE FROM $table WHERE Id=?")->execute([$id]);
}


// ── Réseaux de chaleur (arrêté DPE) ─────────────────────────────────────────
public function rechercherReseaux(string $query): array {
    $this->initCarboneTable();
    $q = '%' . $query . '%';
    return $this->fetchAll(
        "SELECT * FROM ReseauChaleur WHERE Nom LIKE ? OR Commune LIKE ? OR IdReseau LIKE ? OR Gestionnaire LIKE ? ORDER BY Commune, Nom LIMIT 50",
        [$q, $q, $q, $q]
    );
}

public function upsertReseau(array $d): int {
    $this->initCarboneTable();
    $idReseau = $d['idReseau'] ?? '';
    $existing = $this->fetchAll("SELECT Id FROM ReseauChaleur WHERE IdReseau=?", [$idReseau]);
    if ($existing) {
        $id = (int)$existing[0]['Id'];
        $this->pdo->prepare("UPDATE ReseauChaleur SET Nom=?,Commune=?,Gestionnaire=?,CO2=?,CO2ACV=?,TauxEnRR=?,DateMaj=CURRENT_TIMESTAMP WHERE Id=?")
            ->execute([$d['nom']??null, $d['commune']??null, $d['gestionnaire']??null, $d['co2']??null, $d['co2acv']??null, $d['tauxEnRR']??null, $id]);
        return $id;
    } else {
        $this->pdo->prepare("INSERT INTO ReseauChaleur (IdReseau,Nom,Commune,Gestionnaire,CO2,CO2ACV,TauxEnRR) VALUES (?,?,?,?,?,?,?)")
            ->execute([$idReseau, $d['nom']??null, $d['commune']??null, $d['gestionnaire']??null, $d['co2']??null, $d['co2acv']??null, $d['tauxEnRR']??null]);
        return $this->lastId();
    }
}

public function countReseaux(): int {
    $this->initCarboneTable();
    $r = $this->fetchAll("SELECT COUNT(*) as cnt FROM ReseauChaleur");
    return (int)($r[0]['cnt'] ?? $r[0]['Cnt'] ?? 0);
}

// ═══════════════════════════════════════════════════════════════════════════
//  MODULE ARCHIVES
// ═══════════════════════════════════════════════════════════════════════════

// ── Boîtes ────────────────────────────────────────────────────────────────
public function getAllArchivesBoites(): array {
    return $this->fetchAll("SELECT * FROM ArchivesBoite ORDER BY NumeroBoite");
}
public function addArchivesBoite(array $d, string $login=''): int {
    $now = date('Y-m-d H:i:s');
    // Migration à la volée : ajouter les colonnes si elles n'existent pas encore
    $this->ensureArchivesBoiteColumns();
    $s = $this->pdo->prepare("INSERT INTO ArchivesBoite
        (NumeroBoite,Service,AgentResponsable,DateVersement,NombreBoites,NumeroVersement,DatesExtremes,Localisation,AnneeRevision,
         Batiment,Emplacement,DUA,SortFinal,Periode,DureeArchivage,Description,Statut,CodeBarre,CreatedAt,CreatedBy,UpdatedAt,UpdatedBy)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $s->execute([
        $d['numeroBoite'],$d['service']??null,$d['agentResponsable']??null,$d['dateVersement']??null,
        $d['nombreBoites']??null,$d['numeroVersement']??null,$d['datesExtremes']??null,
        $d['localisation']??null,$d['anneeRevision']??null,
        $d['batiment']??null,$d['emplacement']??null,
        $d['dua']??null,$d['sortFinal']??null,$d['periode']??null,$d['dureeArchivage']??null,
        $d['description']??null,$d['statut']??'Active',$d['codeBarre']??null,$now,$login,$now,$login]);
    return $this->lastId();
}
public function updateArchivesBoite(int $id, array $d, string $login=''): void {
    $now = date('Y-m-d H:i:s');
    $this->ensureArchivesBoiteColumns();
    $s = $this->pdo->prepare("UPDATE ArchivesBoite SET
        NumeroBoite=?,Service=?,AgentResponsable=?,DateVersement=?,NombreBoites=?,NumeroVersement=?,
        DatesExtremes=?,Localisation=?,AnneeRevision=?,
        Batiment=?,Emplacement=?,DUA=?,SortFinal=?,Periode=?,DureeArchivage=?,Description=?,Statut=?,CodeBarre=?,UpdatedAt=?,UpdatedBy=?
        WHERE Id=?");
    $s->execute([
        $d['numeroBoite'],$d['service']??null,$d['agentResponsable']??null,$d['dateVersement']??null,
        $d['nombreBoites']??null,$d['numeroVersement']??null,$d['datesExtremes']??null,
        $d['localisation']??null,$d['anneeRevision']??null,
        $d['batiment']??null,$d['emplacement']??null,
        $d['dua']??null,$d['sortFinal']??null,$d['periode']??null,$d['dureeArchivage']??null,
        $d['description']??null,$d['statut']??'Active',$d['codeBarre']??null,$now,$login,$id]);
}
private function ensureArchivesBoiteColumns(): void {
    $cols = array_column($this->fetchAll("PRAGMA table_info(ArchivesBoite)"), 'name');
    $toAdd = [
        'AgentResponsable' => 'TEXT',
        'DateVersement'    => 'TEXT',
        'NombreBoites'     => 'TEXT',
        'NumeroVersement'  => 'TEXT',
        'DatesExtremes'    => 'TEXT',
        'Localisation'     => 'TEXT',
        'AnneeRevision'    => 'TEXT',
    ];
    foreach ($toAdd as $col => $type) {
        if (!in_array($col, $cols)) {
            $this->pdo->exec("ALTER TABLE ArchivesBoite ADD COLUMN $col $type");
        }
    }
}
public function deleteArchivesBoite(int $id): void {
    $this->pdo->prepare("DELETE FROM ArchivesDossier WHERE BoiteId=?")->execute([$id]);
    $this->pdo->prepare("DELETE FROM ArchivesBoite WHERE Id=?")->execute([$id]);
}

// ── Dossiers ──────────────────────────────────────────────────────────────
public function getAllArchivesDossiers(): array {
    return $this->fetchAll("SELECT * FROM ArchivesDossier ORDER BY CreatedAt DESC");
}
public function searchArchivesDossiers(array $f): array {
    $where = ['1=1'];
    $params = [];
    if (!empty($f['annee']))          { $where[] = "Annee = ?";                    $params[] = $f['annee']; }
    if (!empty($f['mois']))           { $where[] = "Mois = ?";                     $params[] = $f['mois']; }
    if (!empty($f['jour']))           { $where[] = "Jour = ?";                     $params[] = $f['jour']; }
    if (!empty($f['numeroBoite']))    { $where[] = "NumeroBoite LIKE ?";            $params[] = '%'.$f['numeroBoite'].'%'; }
    if (!empty($f['numeroDossier']))  { $where[] = "NumeroDossier LIKE ?";         $params[] = '%'.$f['numeroDossier'].'%'; }
    if (!empty($f['nomPrenom']))      { $where[] = "NomPrenom LIKE ?";             $params[] = '%'.$f['nomPrenom'].'%'; }
    if (!empty($f['commune']))        { $where[] = "Commune LIKE ?";               $params[] = '%'.$f['commune'].'%'; }
    if (!empty($f['departement']))    { $where[] = "Departement LIKE ?";           $params[] = '%'.$f['departement'].'%'; }
    if (!empty($f['service']))        { $where[] = "Service LIKE ?";               $params[] = '%'.$f['service'].'%'; }
    if (!empty($f['numeroMandat']))   { $where[] = "NumeroMandat LIKE ?";          $params[] = '%'.$f['numeroMandat'].'%'; }
    if (!empty($f['periode']))        { $where[] = "Periode LIKE ?";               $params[] = '%'.$f['periode'].'%'; }
    if (!empty($f['dureeArchivage'])) { $where[] = "DureeArchivage = ?";           $params[] = $f['dureeArchivage']; }
    if (!empty($f['sortFinal']))      { $where[] = "SortFinal = ?";                $params[] = $f['sortFinal']; }
    if (!empty($f['dua']))            { $where[] = "DUA LIKE ?";                   $params[] = '%'.$f['dua'].'%'; }
    if (!empty($f['description']))    { $where[] = "Description LIKE ?";           $params[] = '%'.$f['description'].'%'; }
    if (!empty($f['emplacement']))    { $where[] = "Emplacement LIKE ?";           $params[] = '%'.$f['emplacement'].'%'; }
    if (!empty($f['codeBarre']))      { $where[] = "CodeBarre = ?";                $params[] = $f['codeBarre']; }
    if (!empty($f['statut']))         { $where[] = "Statut = ?";                   $params[] = $f['statut']; }
    $sql = "SELECT * FROM ArchivesDossier WHERE ".implode(' AND ',$where)." ORDER BY Annee DESC, NumeroDossier LIMIT 500";
    return $this->fetchAll($sql, $params);
}
public function addArchivesDossier(array $d, string $login=''): int {
    $now = date('Y-m-d H:i:s');
    $s = $this->pdo->prepare("INSERT INTO ArchivesDossier
        (BoiteId,NumeroBoite,NumeroDossier,NomPrenom,Commune,Departement,Service,Annee,Mois,Jour,
         NumeroMandat,Periode,DUA,SortFinal,DureeArchivage,Description,Emplacement,DateSolde,CodeBarre,Statut,TypeDemande,CreatedAt,CreatedBy,UpdatedAt,UpdatedBy)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $s->execute([
        $d['boiteId']??null,$d['numeroBoite']??null,$d['numeroDossier']??null,
        $d['nomPrenom']??null,$d['commune']??null,$d['departement']??null,$d['service']??null,
        $d['annee']??null,$d['mois']??null,$d['jour']??null,
        $d['numeroMandat']??null,$d['periode']??null,$d['dua']??null,$d['sortFinal']??null,
        $d['dureeArchivage']??null,$d['description']??null,$d['emplacement']??null,
        $d['dateSolde']??null,$d['codeBarre']??null,$d['statut']??'Archive',
        $d['typeDemande']??null,$now,$login,$now,$login
    ]);
    return $this->lastId();
}
public function updateArchivesDossier(int $id, array $d, string $login=''): void {
    $now = date('Y-m-d H:i:s');
    $s = $this->pdo->prepare("UPDATE ArchivesDossier SET
        BoiteId=?,NumeroBoite=?,NumeroDossier=?,NomPrenom=?,Commune=?,Departement=?,Service=?,
        Annee=?,Mois=?,Jour=?,NumeroMandat=?,Periode=?,DUA=?,SortFinal=?,DureeArchivage=?,
        Description=?,Emplacement=?,DateSolde=?,CodeBarre=?,Statut=?,TypeDemande=?,UpdatedAt=?,UpdatedBy=?
        WHERE Id=?");
    $s->execute([
        $d['boiteId']??null,$d['numeroBoite']??null,$d['numeroDossier']??null,
        $d['nomPrenom']??null,$d['commune']??null,$d['departement']??null,$d['service']??null,
        $d['annee']??null,$d['mois']??null,$d['jour']??null,
        $d['numeroMandat']??null,$d['periode']??null,$d['dua']??null,$d['sortFinal']??null,
        $d['dureeArchivage']??null,$d['description']??null,$d['emplacement']??null,
        $d['dateSolde']??null,$d['codeBarre']??null,$d['statut']??'Archive',
        $d['typeDemande']??null,$now,$login,$id
    ]);
}
public function deleteArchivesDossier(int $id): void {
    $this->pdo->prepare("DELETE FROM ArchivesDossier WHERE Id=?")->execute([$id]);
}

// ── Bordereaux ────────────────────────────────────────────────────────────
public function getAllArchivesBordereaux(): array {
    return $this->fetchAll("SELECT Id,Type,Numero,DateBordereau,Service,Description,NomFichier,TypeMime,Taille,CreatedAt,CreatedBy FROM ArchivesBordereau ORDER BY DateBordereau DESC");
}
public function addArchivesBordereau(array $d, string $login=''): int {
    $now = date('Y-m-d H:i:s');
    $s = $this->pdo->prepare("INSERT INTO ArchivesBordereau (Type,Numero,DateBordereau,Service,Description,NomFichier,TypeMime,Taille,Donnees,CreatedAt,CreatedBy)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)");
    $s->execute([$d['type'],$d['numero']??null,$d['dateBordereau']??null,$d['service']??null,
        $d['description']??null,$d['nomFichier']??null,$d['typeMime']??null,$d['taille']??0,$d['donnees']??null,$now,$login]);
    return $this->lastId();
}
public function deleteArchivesBordereau(int $id): void {
    $this->pdo->prepare("DELETE FROM ArchivesBordereau WHERE Id=?")->execute([$id]);
}
public function getArchivesBordereauData(int $id): ?array {
    return $this->fetchOne("SELECT * FROM ArchivesBordereau WHERE Id=?", [$id]) ?: null;
}
    // ══ ASSISTANT IA — Stats globales ══
    public function getAssistantStats(): array {
        $stats = [];
        try { $stats['biens']         = (int)($this->fetchOne("SELECT COUNT(*) AS c FROM Biens WHERE DateSuppression IS NULL")['c'] ?? 0); } catch (\Throwable $_) { $stats['biens'] = 0; }
        try { $stats['equipements']   = (int)($this->fetchOne("SELECT COUNT(*) AS c FROM Equipements WHERE DateSuppression IS NULL")['c'] ?? 0); } catch (\Throwable $_) { $stats['equipements'] = 0; }
        try { $stats['interventions'] = (int)($this->fetchOne("SELECT COUNT(*) AS c FROM Interventions")['c'] ?? 0); } catch (\Throwable $_) { $stats['interventions'] = 0; }
        try { $stats['contrats']      = (int)($this->fetchOne("SELECT COUNT(*) AS c FROM Contrats")['c'] ?? 0); } catch (\Throwable $_) { $stats['contrats'] = 0; }
        try { $stats['stock']         = (int)($this->fetchOne("SELECT COUNT(*) AS c FROM Stock")['c'] ?? 0); } catch (\Throwable $_) { $stats['stock'] = 0; }
        try { $stats['demandes']      = (int)($this->fetchOne("SELECT COUNT(*) AS c FROM DemandesIntervention")['c'] ?? 0); } catch (\Throwable $_) { $stats['demandes'] = 0; }
        try { $stats['documents']     = (int)($this->fetchOne("SELECT COUNT(*) AS c FROM Documents")['c'] ?? 0); } catch (\Throwable $_) { $stats['documents'] = 0; }
        return $stats;
    }

    // ══ ASSISTANT IA — Recherche globale exhaustive ══
    public function searchForAssistant(array $keywords, bool $anonymize = true): array {
        $results = [];
        $params = [];
        foreach ($keywords as $i => $kw) {
            $params["kw$i"] = "%$kw%";
        }

        // Helper : construit WHERE avec OR entre colonnes ET OR entre mots-clés
        // => un résultat matche si AU MOINS UN mot-clé apparaît dans AU MOINS UNE colonne
        $buildWhere = function(array $columns) use ($params) {
            $parts = [];
            foreach ($params as $pk => $pv) {
                foreach ($columns as $col) {
                    $parts[] = "LOWER(CAST($col AS TEXT)) LIKE LOWER(:{$pk}_{$col})";
                }
            }
            return implode(' OR ', $parts);
        };

        $bindAll = function(array $columns) use ($params) {
            $binds = [];
            foreach ($params as $pk => $pv) {
                foreach ($columns as $col) {
                    $binds["{$pk}_{$col}"] = $pv;
                }
            }
            return $binds;
        };

        $search = function(string $table, string $select, array $cols, string $extraWhere = '', string $order = '', int $limit = 30) use ($buildWhere, $bindAll, &$results) {
            $where = $buildWhere($cols);
            if (!$where) return;
            $fullWhere = $extraWhere ? "($extraWhere) AND ($where)" : $where;
            $sql = "SELECT $select FROM $table WHERE $fullWhere";
            if ($order) $sql .= " ORDER BY $order";
            $sql .= " LIMIT $limit";
            try {
                $rows = $this->fetchAll($sql, $bindAll($cols));
                if ($rows) $results[strtolower($table)] = $rows;
            } catch (\Throwable $_) {}
        };

        // ── RGPD : fonction d'anonymisation des champs personnels ──
        $anonFields = ['NomPrenom','NomDeclarant','ContactNom','ContactTel','ContactEmail',
                       'AjoutePar','SupprimeParLogin','SaisieParLogin','CreatedBy','UpdatedBy',
                       'AgentNom','AgentPrenom','AgentTel','AgentEmail',
                       'EmailDemandeur','TelDemandeur','NomAutrui','EmailAutrui','TelAutrui'];
        $anonymizeRows = function(array $rows) use ($anonymize, $anonFields) {
            if (!$anonymize) return $rows;
            foreach ($rows as &$row) {
                foreach ($anonFields as $field) {
                    if (isset($row[$field]) && !empty($row[$field])) {
                        $row[$field] = '[personne]';
                    }
                }
                // Anonymiser aussi les emails/tel dans d'autres champs potentiels
                foreach ($row as $k => &$v) {
                    if (is_string($v) && (stripos($k, 'email') !== false || stripos($k, 'mail') !== false) && !in_array($k, ['DateEnvoiMail'])) {
                        if (preg_match('/[a-z0-9._%+-]+@[a-z0-9.-]+/i', $v)) $v = '[email]';
                    }
                    if (is_string($v) && stripos($k, 'tel') !== false && preg_match('/\d{8,}/', preg_replace('/\s/', '', $v))) {
                        $v = '[téléphone]';
                    }
                }
            }
            return $rows;
        };

        // Interventions (avec nouveaux champs)
        $search('Interventions',
            'Id,Numero,Type,Priorite,Statut,Description,DateRealisation,SocieteManuelle,Commentaire,CreatedAt,TypeBudget,DateDemande,DateIntervention,CodeNumeroDemande,MontantHT',
            ['Numero','Type','Description','Commentaire','SocieteManuelle','DateRealisation','Statut','Priorite','CreatedAt','TypeBudget','DateDemande','DateIntervention','CodeNumeroDemande'],
            '', 'DateRealisation DESC NULLS LAST');

        // Biens
        $search('Biens',
            'Id,Numero,Famille,SousFamille,Statut,Etat,InfoProduit,Batiment,Etage,NumeroBureau,NumeroSerie,DateCommande,DateLivraison',
            ['Numero','Famille','SousFamille','InfoProduit','Batiment','NumeroBureau','NumeroSerie','Statut','Etat','DateCommande','DateLivraison','Etage'],
            'DateSuppression IS NULL', 'Numero');

        // Équipements
        $search('Equipements',
            'Id,Numero,Famille,SousFamille,Statut,Etat,Marque,Modele,Batiment,Etage,NumeroBureau,Fournisseur,DateInstallation,Observations',
            ['Numero','Famille','SousFamille','Marque','Modele','Batiment','NumeroBureau','Fournisseur','Statut','Etat','DateInstallation','Observations','Etage'],
            'DateSuppression IS NULL', 'Numero');

        // Contrats (sans données personnelles de contact)
        $search('Contrats',
            'Id,Numero,Societe,Type,Statut,DateDebut,DateFin,MontantAnnuel,Description,Perimetre,Frequence,Commentaire',
            ['Numero','Societe','Type','Description','Statut','DateDebut','DateFin','Perimetre','Commentaire'],
            '', 'DateFin DESC NULLS LAST');

        // Demandes (sans données personnelles)
        $search('DemandesIntervention',
            'Id,Titre,Description,Statut,Urgence,Batiment,Bureau,DateCreation,Categorie,CommentaireAdmin',
            ['Titre','Description','Batiment','Bureau','Statut','Categorie','DateCreation','CommentaireAdmin'],
            '', 'DateCreation DESC');

        // Stock
        $search('Stock',
            'Id,Reference,Designation,Categorie,Quantite,SeuilAlerte,PrixUnitaire,Emplacement,Marque,Modele,Fournisseur,FamilleLien',
            ['Reference','Designation','Categorie','Marque','Modele','Emplacement','Fournisseur','FamilleLien'],
            '', 'Designation');

        // Documents (sans champ AjoutePar)
        $search('Documents',
            'Id,EntiteType,EntiteId,NomFichier,Categorie,DateAjout',
            ['NomFichier','EntiteType','Categorie','DateAjout'],
            '', 'DateAjout DESC');

        // Gestion matériel
        $search('GestionMateriel',
            'Id,AssetType,AssetId,NumeroImmo,CompteImmo,NumeroMandat,Exercice,BonDeCommande,DateMiseEnService,DateAchat',
            ['NumeroImmo','CompteImmo','NumeroMandat','Exercice','BonDeCommande','AssetType','DateMiseEnService','DateAchat'],
            '', 'Id DESC');

        // Historique suppressions (sans login)
        $search('HistoriqueSuppression',
            'Id,TableSource,IdOriginal,Numero,Description,DateSuppression',
            ['TableSource','Numero','Description','DateSuppression'],
            '', 'DateSuppression DESC', 15);

        // Compteurs énergie
        try {
            $cols = ['Nom','Type','Unite','Site','NumeroCompteur'];
            $where = $buildWhere($cols);
            if ($where) {
                $rows = $this->fetchAll("SELECT Id,Nom,Type,Unite,Site,NumeroCompteur,Actif FROM CompteurEnergie WHERE $where ORDER BY Nom LIMIT 15", $bindAll($cols));
                if ($rows) $results['compteurs_energie'] = $rows;
            }
        } catch (\Throwable $_) {}

        // Relevés énergie
        try {
            $cols = ['Fournisseur','NumeroFacture','Commentaire','Date','Type'];
            $where = $buildWhere($cols);
            if ($where) {
                $rows = $this->fetchAll("SELECT r.Id,r.CompteurId,c.Nom as CompteurNom,r.Date,r.Type,r.Consommation,r.Montant,r.Fournisseur,r.NumeroFacture FROM ReleverEnergie r LEFT JOIN CompteurEnergie c ON r.CompteurId=c.Id WHERE $where ORDER BY r.Date DESC LIMIT 20", $bindAll($cols));
                if ($rows) $results['releves_energie'] = $rows;
            }
        } catch (\Throwable $_) {}

        // Devis interventions
        try {
            $cols = ['NumeroDevis','Raison'];
            $where = $buildWhere($cols);
            if ($where) {
                $rows = $this->fetchAll("SELECT d.Id,d.InterventionId,i.Numero as IntervNumero,d.NumeroDevis,d.MontantHT,d.MontantTTC,d.Accepte,d.Raison FROM IntervDevisLignes d LEFT JOIN Interventions i ON d.InterventionId=i.Id WHERE $where ORDER BY d.Id DESC LIMIT 20", $bindAll($cols));
                if ($rows) $results['devis'] = $rows;
            }
        } catch (\Throwable $_) {}

        // Archives — Boîtes
        try {
            $cols = ['Numero','Intitule','Service','Batiment','Emplacement','Description','CodeBarre'];
            $where = $buildWhere($cols);
            if ($where) {
                $rows = $this->fetchAll("SELECT Id,Numero,Intitule,Service,Statut,Batiment,Emplacement FROM ArchivesBoite WHERE $where ORDER BY Numero LIMIT 15", $bindAll($cols));
                if ($rows) $results['archives_boites'] = $rows;
            }
        } catch (\Throwable $_) {}

        // Archives — Dossiers
        try {
            $cols = ['Numero','Intitule','Service','NumeroMandat','Description','CodeBarre'];
            $where = $buildWhere($cols);
            if ($where) {
                $rows = $this->fetchAll("SELECT Id,Numero,Intitule,Service,Statut,NumeroMandat FROM ArchivesDossier WHERE $where ORDER BY Numero LIMIT 15", $bindAll($cols));
                if ($rows) $results['archives_dossiers'] = $rows;
            }
        } catch (\Throwable $_) {}


    // ── Helper : calcul de surface d'un polygone (formule du lacet de Gauss) ──
    // $coords est un tableau [[lat,lng], [lat,lng], ...] (cohérent avec le frontend).
    // $echelle est en mètres/pixel. Retourne la surface en m².
    $shoelaceArea = function(array $coords, float $echelle): float {
        $n = count($coords);
        if ($n < 3) return 0.0;
        $area = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $j = ($i + 1) % $n;
            if (!isset($coords[$i][0], $coords[$i][1], $coords[$j][0], $coords[$j][1])) return 0.0;
            $area += (float)$coords[$i][0] * (float)$coords[$j][1];
            $area -= (float)$coords[$j][0] * (float)$coords[$i][1];
        }
        $pxSquare = abs($area) / 2.0;
        return $pxSquare * $echelle * $echelle;  // px² × (m/px)² = m²
    };

    // ── Helper : longueur d'un trait (somme des segments) en mètres ──
    $polylineLength = function(array $coords, float $echelle): float {
        $n = count($coords);
        if ($n < 2) return 0.0;
        $total = 0.0;
        for ($i = 1; $i < $n; $i++) {
            if (!isset($coords[$i-1][0], $coords[$i-1][1], $coords[$i][0], $coords[$i][1])) continue;
            $dx = (float)$coords[$i][0] - (float)$coords[$i-1][0];
            $dy = (float)$coords[$i][1] - (float)$coords[$i-1][1];
            $total += sqrt($dx*$dx + $dy*$dy);
        }
        return $total * $echelle;
    };

    // ── 1. PlanBatiments ──────────────────────────────────────────────────────
    try {
        $cols = ['Nom','Adresse'];
        $where = $buildWhere($cols);
        if ($where) {
            $rows = $this->fetchAll(
                "SELECT Id, Nom, Adresse, Latitude, Longitude FROM PlanBatiments WHERE $where ORDER BY Nom LIMIT 15",
                $bindAll($cols)
            );
            if ($rows) $results['plan_batiments'] = $rows;
        }
    } catch (\Throwable $_) {}

    // ── 2. PlanEtages (joints au bâtiment parent) ─────────────────────────────
    try {
        $cols = ['Nom'];
        $where = $buildWhere($cols);
        if ($where) {
            // On cherche sur PlanEtages.Nom uniquement, mais on retourne aussi le nom du bâtiment.
            $bindings = $bindAll($cols);
            $sql = "SELECT e.Id, e.Nom AS EtageNom, e.Niveau, e.Echelle,
            e.FondLargeur, e.FondHauteur,
            b.Id AS BatimentId, b.Nom AS BatimentNom, b.Adresse AS BatimentAdresse
            FROM PlanEtages e
            LEFT JOIN PlanBatiments b ON e.BatimentId = b.Id
            WHERE " . str_replace('LOWER(CAST(', 'LOWER(CAST(e.', $where) . "
            ORDER BY b.Nom, e.Niveau LIMIT 20";
            // ⚠ Patch SQL : préfixer les colonnes par "e." dans la clause WHERE car
            // PlanEtages contient peu de colonnes mais on est en JOIN ambigu.
            $rows = $this->fetchAll($sql, $bindings);
            if ($rows) $results['plan_etages'] = $rows;
        }
    } catch (\Throwable $_) {}

    // ── 3. PlanElements (le plus important — zones, points, locaux dessinés) ──
    try {
        $cols = ['Nom','Description','TypeElement','SousType','Calque'];
        $where = $buildWhere($cols);
        if ($where) {
            $bindings = $bindAll($cols);
            // On préfixe les colonnes du WHERE par "el." pour lever l'ambiguïté dans le JOIN.
            $wherePrefixed = preg_replace('/LOWER\(CAST\((Nom|Description|TypeElement|SousType|Calque)/u',
                                          'LOWER(CAST(el.$1', $where);
            $sql = "SELECT el.Id, el.TypeElement, el.SousType, el.Calque, el.Nom, el.Description,
            el.Coords, el.Icone, el.Couleur,
            et.Id AS EtageId, et.Nom AS EtageNom, et.Niveau AS EtageNiveau, et.Echelle,
            b.Id AS BatimentId, b.Nom AS BatimentNom
            FROM PlanElements el
            LEFT JOIN PlanEtages et ON el.EtageId = et.Id
            LEFT JOIN PlanBatiments b ON et.BatimentId = b.Id
            WHERE $wherePrefixed
            ORDER BY b.Nom, et.Niveau, el.Nom
            LIMIT 25";
            $rows = $this->fetchAll($sql, $bindings);
            if ($rows) {
                // Post-traitement : décoder Coords, calculer surface/longueur, lister biens liés.
                foreach ($rows as &$r) {
                    $coords = null;
                    if (!empty($r['Coords'])) {
                        $decoded = json_decode($r['Coords'], true);
                        if (is_array($decoded)) $coords = $decoded;
                    }
                    $echelle = (float)($r['Echelle'] ?: 0.05);

                    if ($coords) {
                        $type = strtolower($r['TypeElement'] ?? '');
                        if (in_array($type, ['zone','polygon','rectangle','cercle','circle'], true) && count($coords) >= 3) {
                            $r['SurfaceM2'] = round($shoelaceArea($coords, $echelle), 2);
                        } elseif (in_array($type, ['trait','ligne','line','polyline'], true) && count($coords) >= 2) {
                            $r['LongueurM'] = round($polylineLength($coords, $echelle), 2);
                        } elseif ($type === 'point' && count($coords) >= 1) {
                            // Pour un point, on garde juste les coords (utile pour pointer sur le plan).
                            $r['PointCoords'] = [(float)$coords[0][0], (float)$coords[0][1]];
                        }
                    }
                    // On n'envoie pas le tableau Coords brut au LLM (verbeux et inutile).
                    unset($r['Coords']);

                    // Biens/équipements liés à cet élément graphique
                    try {
                        $links = $this->fetchAll(
                            "SELECT AssetType, AssetId FROM PlanLiens WHERE ElementId = :eid LIMIT 20",
                            ['eid' => (int)$r['Id']]
                        );
                        if ($links) {
                            $r['ElementsLies'] = array_map(fn($l) => [
                                'type' => $l['AssetType'],
                                'id'   => (int)$l['AssetId'],
                            ], $links);
                        }
                    } catch (\Throwable $_) {}
                }
                unset($r);
                $results['plan_elements'] = $rows;
            }
        }
    } catch (\Throwable $_) {}

    // ── 4. Cross-référence : enrichir les Biens et Équipements déjà trouvés ──
    // Pour chaque bien/équipement présent dans les résultats, on cherche dans
    // PlanLiens s'il existe un élément graphique le représentant, et on ajoute
    // alors la localisation visuelle (étage + bâtiment).
    foreach (['biens' => 'Bien', 'equipements' => 'Equipement'] as $resultKey => $assetType) {
        if (empty($results[$resultKey]) || !is_array($results[$resultKey])) continue;
        foreach ($results[$resultKey] as &$row) {
            if (empty($row['Id'])) continue;
            try {
                $linked = $this->fetchAll(
                    "SELECT el.Id AS ElementId, el.TypeElement, el.SousType, el.Nom AS ElementNom,
                    et.Id AS EtageId, et.Nom AS EtageNom, et.Niveau,
                    b.Id AS BatimentId, b.Nom AS BatimentNom
                    FROM PlanLiens pl
                    JOIN PlanElements el ON pl.ElementId = el.Id
                    LEFT JOIN PlanEtages et ON el.EtageId = et.Id
                    LEFT JOIN PlanBatiments b ON et.BatimentId = b.Id
                    WHERE pl.AssetType = :t AND pl.AssetId = :id
                    LIMIT 5",
                    ['t' => $assetType, 'id' => (int)$row['Id']]
                );
                if ($linked) $row['VuSurPlan'] = $linked;
            } catch (\Throwable $_) {}
        }
        unset($row);
    }

        // ── RGPD : anonymiser les résultats avant envoi au LLM ──
        foreach ($results as $table => &$rows) {
            $rows = $anonymizeRows($rows);
        }

        return $results;
    }

    // ══ DECLARATIONS INVENTAIRE ══
    public function getAllDeclarations(string $statut = ''): array {
        $concatNom = $this->sqlConcat("COALESCE(u2.Prenom,'')", "' '", "COALESCE(u2.Nom,'')");
        $sql = "SELECT d.*, b.Famille, b.SousFamille, b.InfoProduit, b.NumeroSerie,
                COALESCE(NULLIF(TRIM(b.NomPrenom),''), TRIM($concatNom), '') AS AffecteActuel,
                b.Batiment AS BatimentActuel, b.Etage AS EtageActuel, b.NumeroBureau AS BureauActuel
                FROM DeclarationsInventaire d
                LEFT JOIN Biens b ON d.BienId = b.Id
                LEFT JOIN Utilisateurs u2 ON b.AffecteUserId = u2.Id";
        if ($statut) {
            $sql .= " WHERE d.Statut = :statut";
            $sql .= " ORDER BY d.DateDeclaration DESC";
            return $this->fetchAll($sql, ['statut' => $statut]);
        }
        $sql .= " ORDER BY d.DateDeclaration DESC";
        return $this->fetchAll($sql);
    }

    public function getDeclarationsByUser(string $login): array {
        return $this->fetchAll(
            "SELECT d.*, b.Famille, b.SousFamille, b.InfoProduit, b.NumeroSerie
             FROM DeclarationsInventaire d LEFT JOIN Biens b ON d.BienId = b.Id
             WHERE d.DeclarantLogin = :login ORDER BY d.DateDeclaration DESC",
            ['login' => $login]
        );
    }

    public function addDeclaration(array $d): int {
        $this->execute(
            "INSERT INTO DeclarationsInventaire (BienId, NumeroBien, Type, Batiment, Etage, NumeroBureau, Commentaire, RaisonDepart, DestBatiment, DestEtage, DestBureau, DestPersonne, PersonneDeclaree, Statut, DeclarantLogin, DeclarantNom, DateDeclaration)
             VALUES (:bienId, :numeroBien, :type, :batiment, :etage, :numeroBureau, :commentaire, :raisonDepart, :destBat, :destEtage, :destBureau, :destPersonne, :personneDeclaree, 'en_attente', :login, :nom, :date)",
            [
                'bienId'     => $d['bienId'] ?: null,
                'numeroBien' => $d['numeroBien'],
                'type'       => $d['type'] ?? 'presence',
                'batiment'   => $d['batiment'] ?? '',
                'etage'      => $d['etage'] ?? '',
                'numeroBureau' => $d['numeroBureau'] ?? '',
                'commentaire'  => $d['commentaire'] ?? '',
                'raisonDepart' => $d['raisonDepart'] ?? '',
                'destBat'      => $d['destBatiment'] ?? '',
                'destEtage'    => $d['destEtage'] ?? '',
                'destBureau'   => $d['destBureau'] ?? '',
                'destPersonne' => $d['destPersonne'] ?? '',
                'personneDeclaree' => $d['personneDeclaree'] ?? '',
                'login'      => $d['login'],
                'nom'        => $d['nom'],
                'date'       => date('Y-m-d H:i:s'),
            ]
        );
        return $this->lastId();
    }

    public function traiterDeclaration(int $id, string $statut, string $login, string $nom, string $motif = ''): void {
        $this->execute(
            "UPDATE DeclarationsInventaire SET Statut=:statut, TraiteParLogin=:login, TraiteParNom=:nom, DateTraitement=:date, MotifRefus=:motif WHERE Id=:id",
            ['statut' => $statut, 'login' => $login, 'nom' => $nom, 'date' => date('Y-m-d H:i:s'), 'motif' => $motif, 'id' => $id]
        );
    }

    public function getDeclarationById(int $id): ?array {
        return $this->fetchOne("SELECT * FROM DeclarationsInventaire WHERE Id = :id", ['id' => $id]);
    }

    public function countDeclarations(string $statut = 'en_attente'): int {
        $row = $this->fetchOne("SELECT COUNT(*) AS c FROM DeclarationsInventaire WHERE Statut = :s", ['s' => $statut]);
        return (int)($row['c'] ?? 0);
    }

    public function checkDoublon(int $bienId, string $excludeLogin = ''): array {
        return $this->fetchAll(
            "SELECT * FROM DeclarationsInventaire WHERE BienId = :bid AND Statut = 'en_attente' AND DeclarantLogin != :login",
            ['bid' => $bienId, 'login' => $excludeLogin]
        );
    }

    // ══ PUSH NOTIFICATIONS ══
    public function savePushSubscription(int $userId, string $endpoint, string $p256dh, string $auth, string $platform = 'unknown', string $userAgent = ''): void {
        // Upsert : si l'endpoint existe déjà, mettre à jour
        $existing = $this->fetchOne("SELECT Id FROM PushSubscriptions WHERE Endpoint = :ep", ['ep' => $endpoint]);
        if ($existing) {
            $this->execute(
                "UPDATE PushSubscriptions SET UserId=:uid, P256dh=:p256dh, Auth=:auth, Platform=:platform, UserAgent=:ua, LastUsedAt=:now WHERE Endpoint=:ep",
                ['uid' => $userId, 'p256dh' => $p256dh, 'auth' => $auth, 'platform' => $platform, 'ua' => $userAgent, 'now' => date('Y-m-d H:i:s'), 'ep' => $endpoint]
            );
        } else {
            $this->execute(
                "INSERT INTO PushSubscriptions (UserId, Endpoint, P256dh, Auth, Platform, UserAgent, CreatedAt, LastUsedAt) VALUES (:uid, :ep, :p256dh, :auth, :platform, :ua, :now, :now2)",
                ['uid' => $userId, 'ep' => $endpoint, 'p256dh' => $p256dh, 'auth' => $auth, 'platform' => $platform, 'ua' => $userAgent, 'now' => date('Y-m-d H:i:s'), 'now2' => date('Y-m-d H:i:s')]
            );
        }
    }

    public function deletePushSubscription(string $endpoint): void {
        // ⚠️ Méthode interne — n'utiliser QUE pour le nettoyage suite à un endpoint
        // invalide remonté par le service push (410 Gone). Ne JAMAIS appeler depuis
        // une route exposée à l'utilisateur sans filtrer par UserId, sinon n'importe
        // quel utilisateur authentifié peut désabonner n'importe quel autre.
        $this->execute("DELETE FROM PushSubscriptions WHERE Endpoint = :ep", ['ep' => $endpoint]);
    }

    /**
     * Variante sûre : ne supprime que si l'endpoint appartient au user donné.
     * À utiliser depuis push_unsubscribe pour empêcher l'unsubscribe croisé.
     */
    public function deletePushSubscriptionForUser(string $endpoint, int $userId): bool {
        $stmt = $this->pdo->prepare("DELETE FROM PushSubscriptions WHERE Endpoint = :ep AND UserId = :uid");
        $stmt->execute(['ep' => $endpoint, 'uid' => $userId]);
        return $stmt->rowCount() > 0;
    }

    public function getPushSubscriptionsForUser(int $userId): array {
        return $this->fetchAll("SELECT * FROM PushSubscriptions WHERE UserId = :uid ORDER BY CreatedAt DESC", ['uid' => $userId]);
    }

    public function getAllPushSubscriptions(?array $userIds = null, ?array $roles = null): array {
        if ($userIds !== null && !empty($userIds)) {
            $placeholders = implode(',', array_fill(0, count($userIds), '?'));
            $stmt = $this->pdo->prepare("SELECT ps.* FROM PushSubscriptions ps WHERE ps.UserId IN ($placeholders)");
            $stmt->execute($userIds);
            return array_map([$this, 'remapRow'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
        }
        if ($roles !== null && !empty($roles)) {
            $placeholders = implode(',', array_fill(0, count($roles), '?'));
            $stmt = $this->pdo->prepare("SELECT ps.* FROM PushSubscriptions ps JOIN Utilisateurs u ON ps.UserId = u.Id WHERE u.Role IN ($placeholders) AND u.Actif = 1");
            $stmt->execute($roles);
            return array_map([$this, 'remapRow'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
        }
        return $this->fetchAll("SELECT * FROM PushSubscriptions");
    }

    public function cleanExpiredPushSubscriptions(): int {
        // Supprimer les souscriptions plus vieilles que 60 jours sans activité
        $cutoff = date('Y-m-d H:i:s', strtotime('-60 days'));
        $stmt = $this->pdo->prepare("DELETE FROM PushSubscriptions WHERE LastUsedAt < :cutoff");
        $stmt->execute(['cutoff' => $cutoff]);
        return $stmt->rowCount();
    }

    // ── Mobilité Carbone ─────────────────────────────────────────────────────

    // ── Mobilité : vérification table ──────────────────────────────────────────
    private bool $_mobiliteTableChecked = false;
    private function _ensureMobiliteTable(): void {
        if ($this->_mobiliteTableChecked) return;
        $this->_mobiliteTableChecked = true;
        $isPg = (DB_DRIVER === 'pgsql');
        // Vérifier si la colonne DistanceKm existe (indicateur de schéma complet)
        try {
            $this->pdo->query("SELECT DistanceKm FROM MobiliteCarbone LIMIT 1");
        } catch (\PDOException $e) {
            // La colonne n'existe pas → recréer la table proprement
            // Sauvegarder les données existantes si possibles
            $existing = [];
            try {
                $existing = $this->fetchAll("SELECT * FROM MobiliteCarbone");
            } catch (\Exception $ex) {}
            $this->pdo->exec("DROP TABLE IF EXISTS MobiliteCarbone");
            $AI = $isPg ? 'SERIAL PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
            $this->pdo->exec("CREATE TABLE MobiliteCarbone (
                Id $AI,
                UtilisateurId     INTEGER NOT NULL,
                Transport         TEXT NOT NULL DEFAULT '',
                DistanceKm        REAL NOT NULL DEFAULT 0,
                Frequence         TEXT NOT NULL DEFAULT 'jour',
                NbFrequence       REAL NOT NULL DEFAULT 1,
                FacteurCO2        REAL NOT NULL DEFAULT 0,
                Periode           TEXT,
                DateDebut         TEXT,
                DateFin           TEXT,
                Commentaire       TEXT,
                CreatedAt         TEXT,
                UpdatedAt         TEXT)");
            // Réinsérer les données existantes (avec les colonnes disponibles)
            foreach ($existing as $row) {
                $this->pdo->prepare("INSERT INTO MobiliteCarbone (UtilisateurId, Transport, Frequence, CreatedAt, UpdatedAt) VALUES (?, ?, ?, ?, ?)")
                    ->execute([
                        (int)($row['UtilisateurId'] ?? 0),
                        (string)($row['Transport'] ?? ''),
                        (string)($row['Frequence'] ?? 'jour'),
                        $row['CreatedAt'] ?? date('Y-m-d H:i:s'),
                        date('Y-m-d H:i:s'),
                    ]);
            }
        }
    }

    public function getMobiliteByUser(int $userId): array {
        $this->_ensureMobiliteTable();
        return $this->fetchAll("SELECT * FROM MobiliteCarbone WHERE UtilisateurId = :uid ORDER BY Id DESC", ['uid' => $userId]);
    }

    public function getMobiliteAll(): array {
        $this->_ensureMobiliteTable();
        return $this->fetchAll("SELECT m.*, u.Nom, u.Prenom, u.Login, u.Service FROM MobiliteCarbone m LEFT JOIN Utilisateurs u ON m.UtilisateurId = u.Id ORDER BY m.Id DESC");
    }

    public function addMobilite(array $d): int {
        $this->_ensureMobiliteTable();
        $stmt = $this->pdo->prepare("INSERT INTO MobiliteCarbone (UtilisateurId, Transport, DistanceKm, Frequence, NbFrequence, FacteurCO2, Periode, DateDebut, DateFin, Commentaire, CreatedAt, UpdatedAt) VALUES (:uid, :transport, :dist, :freq, :nb, :co2, :periode, :dd, :df, :com, :ca, :ua)");
        $stmt->execute([
            'uid'       => (int)($d['utilisateurId'] ?? 0),
            'transport' => (string)($d['transport'] ?? ''),
            'dist'      => (float)($d['distanceKm'] ?? 0),
            'freq'      => (string)($d['frequence'] ?? 'jour'),
            'nb'        => (float)($d['nbFrequence'] ?? 1),
            'co2'       => (float)($d['facteurCO2'] ?? 0),
            'periode'   => (string)($d['periode'] ?? ''),
            'dd'        => $d['dateDebut'] ?? null,
            'df'        => $d['dateFin'] ?? null,
            'com'       => (string)($d['commentaire'] ?? ''),
            'ca'        => date('Y-m-d H:i:s'),
            'ua'        => date('Y-m-d H:i:s'),
        ]);
        return $this->lastId();
    }

    public function updateMobilite(int $id, array $d): void {
        $stmt = $this->pdo->prepare("UPDATE MobiliteCarbone SET Transport=:transport, DistanceKm=:dist, Frequence=:freq, NbFrequence=:nb, FacteurCO2=:co2, Periode=:periode, DateDebut=:dd, DateFin=:df, Commentaire=:com, UpdatedAt=:ua WHERE Id=:id");
        $stmt->execute([
            'transport' => (string)($d['transport'] ?? ''),
            'dist'      => (float)($d['distanceKm'] ?? 0),
            'freq'      => (string)($d['frequence'] ?? 'jour'),
            'nb'        => (float)($d['nbFrequence'] ?? 1),
            'co2'       => (float)($d['facteurCO2'] ?? 0),
            'periode'   => (string)($d['periode'] ?? ''),
            'dd'        => $d['dateDebut'] ?? null,
            'df'        => $d['dateFin'] ?? null,
            'com'       => (string)($d['commentaire'] ?? ''),
            'ua'        => date('Y-m-d H:i:s'),
            'id'        => $id,
        ]);
    }

    public function deleteMobilite(int $id): void {
        $this->pdo->prepare("DELETE FROM MobiliteCarbone WHERE Id = :id")->execute(['id' => $id]);
    }

    public function getMobiliteById(int $id): ?array {
        $stmt = $this->pdo->prepare("SELECT * FROM MobiliteCarbone WHERE Id = :id");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // ══ PLANS INTERACTIFS ══
    // ══════════════════════════════════════════════════════════════════════════

    public function initPlansTables(): void {
        $isPg    = ($this->driver === 'pgsql');
        $isMysql = ($this->driver === 'mariadb' || $this->driver === 'mysql');
        $AI      = $isPg ? 'SERIAL PRIMARY KEY' : ($isMysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT');
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS PlanBatiments (
            Id $AI, Nom TEXT NOT NULL, Adresse TEXT, Couleur TEXT DEFAULT '#3b82f6',
            Latitude REAL, Longitude REAL,
            CreatedAt TEXT, CreatedBy TEXT, UpdatedAt TEXT, UpdatedBy TEXT)");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS PlanEtages (
            Id $AI, BatimentId INTEGER NOT NULL, Nom TEXT NOT NULL, Niveau INTEGER DEFAULT 0,
            FondImage TEXT, FondLargeur INTEGER DEFAULT 1000, FondHauteur INTEGER DEFAULT 700,
            Echelle REAL DEFAULT 0.05, RefMetre TEXT, TailleMarqueurs REAL DEFAULT 1,
            CreatedAt TEXT, CreatedBy TEXT, UpdatedAt TEXT, UpdatedBy TEXT)");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS PlanElements (
            Id $AI, EtageId INTEGER NOT NULL, TypeElement TEXT NOT NULL,
            SousType TEXT DEFAULT '',
            Calque TEXT DEFAULT 'Plan',
            Nom TEXT, Couleur TEXT DEFAULT '#3b82f6', Opacite REAL DEFAULT 0.3,
            Coords TEXT, Icone TEXT, Description TEXT, AfficherAnnuaire INTEGER DEFAULT 0,
            CreatedAt TEXT, CreatedBy TEXT, UpdatedAt TEXT, UpdatedBy TEXT)");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS PlanLiens (
            Id $AI, ElementId INTEGER NOT NULL, AssetType TEXT NOT NULL,
            AssetId INTEGER NOT NULL)");

        // Migrations : ajout de colonnes sur tables existantes
        $migrations = [
            ['PlanEtages',   'Echelle',  'REAL DEFAULT 0.05'],
            ['PlanEtages',   'RefMetre', 'TEXT'],            // mètre de référence tracé (JSON {coords,dist,hidden})
            ['PlanEtages',   'TailleMarqueurs', 'REAL DEFAULT 1'],   // facteur de taille des marqueurs de l'étage
            ['PlanElements', 'AfficherAnnuaire', 'INTEGER DEFAULT 0'], // texte visible dans l'annuaire (défaut non)
            ['PlanElements', 'SousType', "TEXT DEFAULT ''"],
            ['PlanElements', 'Calque',   "TEXT DEFAULT 'Plan'"],
            ['PlanElements', 'RefUtilisateurId', 'INTEGER'],   // point ↔ personne (compte lié)
            ['PlanElements', 'PersonneData', 'TEXT'],          // point ↔ personne (saisie manuelle, JSON)
            ['PlanElements', 'EstPersonne', 'INTEGER DEFAULT 0'], // point créé via l'outil Personne
        ];
        foreach ($migrations as [$table, $col, $def]) {
            try {
                $sql = $isPg
                    ? "ALTER TABLE $table ADD COLUMN IF NOT EXISTS $col $def"
                    : "ALTER TABLE $table ADD COLUMN $col $def";
                $this->pdo->exec($sql);
            } catch (\PDOException $e) { /* colonne déjà existante */ }
        }
    }

    // ── Bâtiments de plan ─────────────────────────────────────────────────────
    public function getAllPlanBatiments(): array {
        $this->initPlansTables();
        return $this->fetchAll("SELECT * FROM PlanBatiments ORDER BY Nom");
    }
    public function addPlanBatiment(array $d, string $login=''): int {
        $this->initPlansTables();
        $now = date('Y-m-d H:i:s');
        $this->execute("INSERT INTO PlanBatiments (Nom,Adresse,Couleur,Latitude,Longitude,CreatedAt,CreatedBy,UpdatedAt,UpdatedBy) VALUES (:nom,:adresse,:couleur,:lat,:lng,:ca,:cb,:ua,:ub)", [
            'nom'=>$d['nom']??'', 'adresse'=>$d['adresse']??'', 'couleur'=>$d['couleur']??'#3b82f6',
            'lat'=>$d['latitude']??null, 'lng'=>$d['longitude']??null,
            'ca'=>$now,'cb'=>$login,'ua'=>$now,'ub'=>$login
        ]);
        return $this->lastId();
    }
    public function updatePlanBatiment(int $id, array $d, string $login=''): void {
        $this->initPlansTables();
        $this->execute("UPDATE PlanBatiments SET Nom=:nom,Adresse=:adresse,Couleur=:couleur,Latitude=:lat,Longitude=:lng,UpdatedAt=:ua,UpdatedBy=:ub WHERE Id=:id", [
            'nom'=>$d['nom']??'', 'adresse'=>$d['adresse']??'', 'couleur'=>$d['couleur']??'#3b82f6',
            'lat'=>$d['latitude']??null, 'lng'=>$d['longitude']??null,
            'ua'=>date('Y-m-d H:i:s'),'ub'=>$login,'id'=>$id
        ]);
    }
    public function deletePlanBatiment(int $id): void {
        $this->initPlansTables();
        // Supprimer en cascade
        $etages = $this->fetchAll("SELECT Id FROM PlanEtages WHERE BatimentId=:id",['id'=>$id]);
        foreach ($etages as $et) {
            $this->pdo->prepare("DELETE FROM PlanLiens WHERE ElementId IN (SELECT Id FROM PlanElements WHERE EtageId=:eid)")->execute(['eid'=>$et['Id']]);
            $this->pdo->prepare("DELETE FROM PlanElements WHERE EtageId=:eid")->execute(['eid'=>$et['Id']]);
        }
        $this->pdo->prepare("DELETE FROM PlanEtages WHERE BatimentId=:id")->execute(['id'=>$id]);
        $this->pdo->prepare("DELETE FROM PlanBatiments WHERE Id=:id")->execute(['id'=>$id]);
    }

    // ── Étages de plan ────────────────────────────────────────────────────────
    public function getAllPlanEtages($batimentId=null): array {
        $this->initPlansTables();
        if ($batimentId) return $this->fetchAll("SELECT * FROM PlanEtages WHERE BatimentId=:bid ORDER BY Niveau", ['bid'=>$batimentId]);
        return $this->fetchAll("SELECT * FROM PlanEtages ORDER BY BatimentId, Niveau");
    }
    public function getPlanEtageById(int $id): ?array {
        $this->initPlansTables();
        return $this->fetchOne("SELECT * FROM PlanEtages WHERE Id=:id",['id'=>$id]);
    }
    public function addPlanEtage(array $d, string $login=''): int {
        $this->initPlansTables();
        $now = date('Y-m-d H:i:s');
        $this->execute("INSERT INTO PlanEtages (BatimentId,Nom,Niveau,FondImage,FondLargeur,FondHauteur,Echelle,CreatedAt,CreatedBy,UpdatedAt,UpdatedBy) VALUES (:bid,:nom,:niv,:fond,:fl,:fh,:ech,:ca,:cb,:ua,:ub)", [
            'bid'=>(int)($d['batimentId']??0), 'nom'=>$d['nom']??'', 'niv'=>(int)($d['niveau']??0),
            'fond'=>$d['fondImage']??null, 'fl'=>(int)($d['fondLargeur']??1000), 'fh'=>(int)($d['fondHauteur']??700),
            'ech'=>(float)($d['echelle']??0.05),
            'ca'=>$now,'cb'=>$login,'ua'=>$now,'ub'=>$login
        ]);
        return $this->lastId();
    }
    public function updatePlanEtage(int $id, array $d, string $login=''): void {
        $this->initPlansTables();
        // RefMetre (mètre de référence tracé) n'est mis à jour que s'il est explicitement
        // fourni, afin de ne pas l'effacer lors d'une simple modification de nom/échelle.
        $params = [
            'nom'=>$d['nom']??'', 'niv'=>(int)($d['niveau']??0),
            'fl'=>(int)($d['fondLargeur']??1000), 'fh'=>(int)($d['fondHauteur']??700),
            'ech'=>(float)($d['echelle']??0.05),
            'ua'=>date('Y-m-d H:i:s'),'ub'=>$login,'id'=>$id
        ];
        $setRef = '';
        if (array_key_exists('refMetre', $d)) {
            $setRef = ',RefMetre=:rm';
            $rm = $d['refMetre'];
            if ($rm === null || $rm === '') { $params['rm'] = null; }
            else { $params['rm'] = is_string($rm) ? $rm : json_encode($rm); }
        }
        // Taille des marqueurs (facteur) : idem, seulement si fourni.
        $setTaille = '';
        if (array_key_exists('tailleMarqueurs', $d)) {
            $setTaille = ',TailleMarqueurs=:tm';
            $tm = (float)$d['tailleMarqueurs'];
            $params['tm'] = ($tm > 0) ? $tm : 1;
        }
        $this->execute("UPDATE PlanEtages SET Nom=:nom,Niveau=:niv,FondLargeur=:fl,FondHauteur=:fh,Echelle=:ech{$setRef}{$setTaille},UpdatedAt=:ua,UpdatedBy=:ub WHERE Id=:id", $params);
    }
    public function updatePlanEtageFond(int $id, string $path): void {
        $this->initPlansTables();
        $this->execute("UPDATE PlanEtages SET FondImage=:path,UpdatedAt=:ua WHERE Id=:id", ['path'=>$path,'ua'=>date('Y-m-d H:i:s'),'id'=>$id]);
    }
    public function deletePlanEtage(int $id): void {
        $this->initPlansTables();
        $this->pdo->prepare("DELETE FROM PlanLiens WHERE ElementId IN (SELECT Id FROM PlanElements WHERE EtageId=:eid)")->execute(['eid'=>$id]);
        $this->pdo->prepare("DELETE FROM PlanElements WHERE EtageId=:eid")->execute(['eid'=>$id]);
        $this->pdo->prepare("DELETE FROM PlanEtages WHERE Id=:id")->execute(['id'=>$id]);
    }

    // ── Éléments de plan ──────────────────────────────────────────────────────
    public function getAllPlanElements($etageId=null): array {
        $this->initPlansTables();
        if ($etageId) return $this->fetchAll("SELECT * FROM PlanElements WHERE EtageId=:eid ORDER BY Id", ['eid'=>$etageId]);
        return $this->fetchAll("SELECT * FROM PlanElements ORDER BY EtageId, Id");
    }

    // ── Annuaire cartographié (lecture Demandeur) ─────────────────
    public function getAnnuaireBatiments(): array {
        $this->initPlansTables();
        return $this->fetchAll("SELECT Id, Nom, Adresse, Couleur FROM PlanBatiments ORDER BY Nom");
    }
    public function getAnnuaireEtages($batId): array {
        $this->initPlansTables();
        if ($batId) return $this->fetchAll("SELECT * FROM PlanEtages WHERE BatimentId=:b ORDER BY Niveau, Nom", ['b'=>(int)$batId]);
        return $this->fetchAll("SELECT * FROM PlanEtages ORDER BY BatimentId, Niveau, Nom");
    }
    public function getAnnuairePoints(int $etageId): array {
        $this->initPlansTables();
        return $this->fetchAll(
            "SELECT Id, Nom, Coords, Couleur, Icone, SousType, Calque, RefUtilisateurId, PersonneData
             FROM PlanElements
             WHERE EtageId=:e AND TypeElement='point'
               AND (RefUtilisateurId IS NOT NULL OR (PersonneData IS NOT NULL AND PersonneData <> ''))",
            ['e'=>$etageId]
        );
    }
    /** Textes d'un étage explicitement marqués « afficher dans l'annuaire ». */
    public function getAnnuaireTextes(int $etageId): array {
        $this->initPlansTables();
        return $this->fetchAll(
            "SELECT Id, Nom, Coords, Couleur, SousType, Calque
             FROM PlanElements
             WHERE EtageId=:e AND TypeElement='texte' AND AfficherAnnuaire=1",
            ['e'=>$etageId]
        );
    }
    /** Fiches publiques (champs sûrs) indexées par Id. */
    public function getAnnuaireUsers(array $ids): array {
        if (empty($ids)) return [];
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT Id, Nom, Prenom, Service, Poste, Email, Tel, TelPro, TelMobile,
                    OfficeLocation, PhotoUrl, PartagePresencePlan, MasquerPresence, MicrosoftId
             FROM Utilisateurs WHERE (Actif IS NULL OR Actif <> 0) AND Id IN ($ph)"
        );
        $stmt->execute($ids);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $row = ($this->driver === 'pgsql') ? $this->remapRow($row) : $row;
            $out[(int)$row['Id']] = $row;
        }
        return $out;
    }
    public function setPartagePresencePlan(int $userId, int $val): void {
        $this->execute("UPDATE Utilisateurs SET PartagePresencePlan=:v WHERE Id=:id",
            ['v'=>$val ? 1 : 0, 'id'=>$userId]);
    }
    // Opt-out présence : 1 = masquée, 0 = partagée (défaut)
    public function setMasquerPresence(int $userId, int $val): void {
        $this->execute("UPDATE Utilisateurs SET MasquerPresence=:v WHERE Id=:id",
            ['v'=>$val ? 1 : 0, 'id'=>$userId]);
    }
    public function getMasquerPresence(int $userId): int {
        $row = $this->fetchOne("SELECT MasquerPresence FROM Utilisateurs WHERE Id=:id", ['id'=>$userId]);
        return (int)($row['MasquerPresence'] ?? 0);
    }
    /** Réfs Graph (MicrosoftId, Email) des utilisateurs NON masqués, pour présence/agenda. */
    /**
     * Population interrogeable pour le comptage de présence.
     *
     * Source volontairement LOCALE : les personnes connues de l'application et
     * rattachées à un compte Microsoft. Cela évite d'exiger User.Read.All, une
     * permission applicative qui ouvrirait la lecture du profil complet de tout
     * l'annuaire — disproportionné pour afficher un compteur arrondi.
     *
     * Filtres appliqués :
     *   • MicrosoftId renseigné  → sans lui, ni présence ni agenda possibles
     *   • compte actif
     *   • MasquerPresence <> 1   → l'opt-out est respecté à la source
     *
     * @return array<int, array{MicrosoftId:string, Email:string}>
     */
    public function getPopulationPresence(): array {
        try {
            $rows = $this->fetchAll(
                "SELECT MicrosoftId, Email FROM Utilisateurs
                 WHERE MicrosoftId IS NOT NULL AND MicrosoftId <> ''
                   AND (Actif IS NULL OR Actif <> 0)
                   AND (MasquerPresence IS NULL OR MasquerPresence <> 1)"
            );
        } catch (\Throwable $e) { return []; }

        $out = [];
        foreach ($rows as $r) {
            $ms = trim((string)($r['MicrosoftId'] ?? ''));
            if ($ms === '') continue;
            $out[] = ['MicrosoftId' => $ms, 'Email' => trim((string)($r['Email'] ?? ''))];
        }
        return $out;
    }

    /** Nombre de personnes ayant explicitement masqué leur présence. */
    public function countPresenceMasquee(): int {
        try {
            $r = $this->fetchOne("SELECT COUNT(*) AS n FROM Utilisateurs
                                  WHERE MasquerPresence = 1 AND MicrosoftId IS NOT NULL AND MicrosoftId <> ''");
            return (int)($r['n'] ?? 0);
        } catch (\Throwable $e) { return 0; }
    }

    /**
     * Identités des personnes ayant masqué leur présence (MasquerPresence = 1).
     *
     * Renvoie MicrosoftId ET Email en minuscules, car l'exclusion doit pouvoir
     * s'appliquer à une population énumérée depuis Entra — où l'on ne dispose
     * pas de l'identifiant local. Sans ça, un opt-out posé dans l'application
     * serait contourné dès qu'on change de source de population.
     *
     * @return array{ms: array<string,true>, mails: array<string,true>}
     */
    public function getIdentitesPresenceMasquee(): array {
        $ms = []; $mails = [];
        try {
            $rows = $this->fetchAll("SELECT MicrosoftId, Email FROM Utilisateurs WHERE MasquerPresence = 1");
            foreach ($rows as $r) {
                $m = trim((string)($r['MicrosoftId'] ?? ''));
                $e = strtolower(trim((string)($r['Email'] ?? '')));
                if ($m !== '') $ms[$m] = true;
                if ($e !== '') $mails[$e] = true;
            }
        } catch (\Throwable $e) { /* table absente : aucun opt-out connu */ }
        return ['ms' => $ms, 'mails' => $mails];
    }

    public function getAnnuairePresenceRefs(array $ids): array {
        if (empty($ids)) return [];
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT Id, MicrosoftId, Email FROM Utilisateurs
             WHERE Id IN ($ph) AND (MasquerPresence IS NULL OR MasquerPresence = 0)
               AND (Actif IS NULL OR Actif <> 0)"
        );
        $stmt->execute($ids);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $row = ($this->driver === 'pgsql') ? $this->remapRow($row) : $row;
            $out[(int)$row['Id']] = ['MicrosoftId'=>$row['MicrosoftId'] ?? '', 'Email'=>$row['Email'] ?? ''];
        }
        return $out;
    }
    public function addPlanElement(array $d, string $login=''): int {
        $this->initPlansTables();
        $now = date('Y-m-d H:i:s');
        $coords = is_string($d['coords']??null) ? $d['coords'] : json_encode($d['coords']??[]);
        $this->execute("INSERT INTO PlanElements (EtageId,TypeElement,SousType,Calque,Nom,Couleur,Opacite,Coords,Icone,Description,AfficherAnnuaire,EstPersonne,CreatedAt,CreatedBy,UpdatedAt,UpdatedBy) VALUES (:eid,:type,:stype,:calque,:nom,:couleur,:opacite,:coords,:icone,:desc,:aff,:estp,:ca,:cb,:ua,:ub)", [
            'eid'=>(int)($d['etageId']??0), 'type'=>$d['typeElement']??'point',
            'stype'=>$d['sousType']??'',
            'calque'=>$d['calque']??'Plan',
            'nom'=>$d['nom']??'', 'couleur'=>$d['couleur']??'#3b82f6',
            'opacite'=>(float)($d['opacite']??0.3), 'coords'=>$coords,
            'icone'=>$d['icone']??'', 'desc'=>$d['description']??'',
            'aff'=> !empty($d['afficherAnnuaire']) ? 1 : 0,
            'estp'=> !empty($d['estPersonne']) ? 1 : 0,
            'ca'=>$now,'cb'=>$login,'ua'=>$now,'ub'=>$login
        ]);
        return $this->lastId();
    }
    public function updatePlanElement(int $id, array $d, string $login=''): void {
        $this->initPlansTables();
        $coords = is_string($d['coords']??null) ? $d['coords'] : json_encode($d['coords']??[]);
        // AfficherAnnuaire n'est mis à jour que s'il est explicitement fourni,
        // pour ne pas le réinitialiser lors d'un simple déplacement/renommage.
        $params = [
            'nom'=>$d['nom']??'', 'stype'=>$d['sousType']??'',
            'calque'=>$d['calque']??'Plan',
            'couleur'=>$d['couleur']??'#3b82f6',
            'opacite'=>(float)($d['opacite']??0.3), 'coords'=>$coords,
            'icone'=>$d['icone']??'', 'desc'=>$d['description']??'',
            'ua'=>date('Y-m-d H:i:s'),'ub'=>$login,'id'=>$id
        ];
        $setAff = '';
        if (array_key_exists('afficherAnnuaire', $d)) {
            $setAff = ',AfficherAnnuaire=:aff';
            $params['aff'] = !empty($d['afficherAnnuaire']) ? 1 : 0;
        }
        $this->execute("UPDATE PlanElements SET Nom=:nom,SousType=:stype,Calque=:calque,Couleur=:couleur,Opacite=:opacite,Coords=:coords,Icone=:icone,Description=:desc{$setAff},UpdatedAt=:ua,UpdatedBy=:ub WHERE Id=:id", $params);
    }

    /** Rattachement d'une personne à un point (compte lié OU saisie manuelle). */
    public function setPlanElementPersonne(int $id, $refUtilisateurId, $personneData): void {
        $this->initPlansTables();
        $this->execute("UPDATE PlanElements SET RefUtilisateurId=:ref, PersonneData=:pd WHERE Id=:id", [
            'ref' => !empty($refUtilisateurId) ? (int)$refUtilisateurId : null,
            'pd'  => ($personneData !== null && $personneData !== '') ? $personneData : null,
            'id'  => $id
        ]);
    }

    // Renommer un calque (mass update)
    public function renamePlanCalque(int $etageId, string $oldName, string $newName): int {
        $this->initPlansTables();
        $stmt = $this->pdo->prepare("UPDATE PlanElements SET Calque=:new WHERE EtageId=:eid AND Calque=:old");
        $stmt->execute(['new'=>$newName, 'eid'=>$etageId, 'old'=>$oldName]);
        return $stmt->rowCount();
    }
    // Supprimer un calque entier (et tous ses éléments)
    public function deletePlanCalque(int $etageId, string $name): int {
        $this->initPlansTables();
        // Supprimer les liens d'abord
        $this->pdo->prepare("DELETE FROM PlanLiens WHERE ElementId IN (SELECT Id FROM PlanElements WHERE EtageId=:eid AND Calque=:c)")
            ->execute(['eid'=>$etageId, 'c'=>$name]);
        $stmt = $this->pdo->prepare("DELETE FROM PlanElements WHERE EtageId=:eid AND Calque=:c");
        $stmt->execute(['eid'=>$etageId, 'c'=>$name]);
        return $stmt->rowCount();
    }
    public function deletePlanElement(int $id): void {
        $this->initPlansTables();
        $this->pdo->prepare("DELETE FROM PlanLiens WHERE ElementId=:eid")->execute(['eid'=>$id]);
        $this->pdo->prepare("DELETE FROM PlanElements WHERE Id=:id")->execute(['id'=>$id]);
    }

    // ── Liens éléments ↔ biens/équipements ────────────────────────────────────
    public function getAllPlanLiens($elementId=null): array {
        $this->initPlansTables();
        if ($elementId) return $this->fetchAll("SELECT * FROM PlanLiens WHERE ElementId=:eid", ['eid'=>$elementId]);
        return $this->fetchAll("SELECT * FROM PlanLiens ORDER BY ElementId");
    }
    public function addPlanLien(array $d): int {
        $this->initPlansTables();
        $this->execute("INSERT INTO PlanLiens (ElementId,AssetType,AssetId) VALUES (:eid,:type,:aid)", [
            'eid'=>(int)($d['elementId']??0), 'type'=>$d['assetType']??'Bien', 'aid'=>(int)($d['assetId']??0)
        ]);
        return $this->lastId();
    }
    public function deletePlanLien(int $id): void {
        $this->initPlansTables();
        $this->pdo->prepare("DELETE FROM PlanLiens WHERE Id=:id")->execute(['id'=>$id]);
    }

} // end class Database

// ── Initialisation automatique du registre tenant ─────────────────────────────
// config.php a stocké la config DB du tenant courant dans $GLOBALS.
// On la charge ici, après la définition de la classe.
if (!empty($GLOBALS['_gmao_tenant_db_config'])) {
    Database::setTenantConfig($GLOBALS['_gmao_tenant_db_config']);
}

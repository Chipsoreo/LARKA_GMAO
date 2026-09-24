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
 * Larka — Security API : la frontière
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Point de passage unique et obligatoire entre une extension et les données du
 * cœur. Toute opération y est identifiée, contrôlée, décidée, journalisée.
 *
 * CE QU'ELLE SAIT DE CHAQUE OPÉRATION
 *   qui       identifiant de l'extension, capacités accordées
 *   où        processus (pid), utilisateur Linux (uid)
 *   quoi      opération déclarée ET opération réellement demandée
 *   sur quoi  table, colonnes, volume, fichier
 *   verdict   autorisé, filtré, refusé — et le motif, qui reste interne
 *
 * L'ÉCART ENTRE LE DÉCLARÉ ET LE RÉEL
 * Une extension annonce « equipement.list » et demande la table Utilisateurs.
 * Les deux sont consignés séparément, et c'est l'opération RÉELLE qui décide.
 * Le nom déclaré ne sert qu'à documenter l'écart dans le journal — c'est
 * précisément cet écart qui signale une extension qui dissimule.
 *
 * FAIL CLOSED
 * Si une condition de sécurité manque — journal non inscriptible, secrets
 * lisibles par tous — les modules ne démarrent pas. Jamais « la sécurité est
 * indisponible, on continue quand même » : c'est exactement le moment où l'on
 * a besoin d'elle.
 *
 * (Cette liste mentionnait aussi le mode strict, le confinement noyau et
 *  proc_open. Ils ont disparu avec les modules à code : il n'y a plus de
 *  processus à isoler, donc plus de condition à vérifier de ce côté.)
 * ═══════════════════════════════════════════════════════════════════════════════
 */

require_once __DIR__ . '/JournalSecurite.php';
require_once __DIR__ . '/PolitiqueDonnees.php';
require_once __DIR__ . '/Requete.php';
require_once __DIR__ . '/Surveillance.php';

final class ExtSecurite
{
    private $db;
    private string $extension;
    private array $capacites;
    private array $utilisateur;
    private string $operationDeclaree = '?';

    public function __construct($db, string $extension, array $capacites, array $utilisateur = [])
    {
        $this->db          = $db;
        $this->extension   = $extension;
        $this->capacites   = $capacites;
        $this->utilisateur = $utilisateur;
    }

    /**
     * Nom de l'opération telle que l'extension l'annonce.
     * Purement déclaratif : rien n'en dépend, et c'est voulu. Il n'existe que
     * pour être comparé, dans le journal, à ce qui a réellement été demandé.
     */
    public function declarer(string $operation): void
    {
        $this->operationDeclaree = $operation;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Requêtes structurées
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Seul chemin d'une extension vers la base.
     *
     * @throws ExtRefusSecurite message opaque ; le détail va au journal
     */
    public function requeter(array $requete): array
    {
        if (ExtSurveillance::bloquee($this->extension)) {
            throw ExtJournalSecurite::refuser([
                'extension' => $this->extension,
                'operation_declaree' => $this->operationDeclaree,
                'operation_reelle' => 'base.' . strtolower((string)($requete['action'] ?? '?')),
                'motif' => 'extension_sous_blocage_comportemental',
            ]);
        }

        $table  = (string)($requete['table'] ?? '');
        $action = strtolower((string)($requete['action'] ?? '?'));

        try {
            $r = (new ExtRequete($this->db, $this->extension, $this->capacites))
                    ->executer($requete);
        } catch (ExtRefusSecurite $e) {
            // Un refus compte : dix refus dans la fenêtre bloquent l'extension.
            ExtSurveillance::observer($this->extension, 'base.' . $action, $table, 0, true);
            throw $e;
        }

        // « nombre » pour un SELECT/COUNT, « affectees » pour une écriture.
        // Les deux étaient nommés « lignes » : count() recevait un entier sur
        // les INSERT, et toute écriture échouait. Trouvé par la suite de
        // non-régression — la raison même de son existence.
        $volume = (int)($r['nombre'] ?? $r['affectees'] ?? 0);
        $niveau = ExtPolitiqueDonnees::niveauTable($this->db, $table);
        $etiquette = 'base.' . $action
                   . ($niveau === ExtPolitiqueDonnees::SENSIBLE ? '.sensible' : '');

        $verdict = ExtSurveillance::observer($this->extension, $etiquette, $table, $volume);
        if ($verdict['decision'] === ExtJournalSecurite::REFUSE) {
            // Le seuil est franchi PAR cette opération : on ne renvoie pas ce
            // qu'on vient de lire. Détecter après coup et livrer quand même
            // n'aurait aucun intérêt.
            throw new ExtRefusSecurite(ExtJournalSecurite::MESSAGE_OPAQUE);
        }
        return $r;
    }

    /**
     * Crée une table appartenant à l'extension.
     *
     * Le nom est IMPOSÉ : ext_<identifiant>_<nom>. L'extension ne choisit que le
     * suffixe. C'est ce qui garantit qu'elle ne peut ni écraser une table du
     * cœur, ni atteindre celle d'une autre extension — l'appartenance est
     * inscrite dans le nom, pas dans un registre qu'il faudrait tenir à jour.
     *
     * Seuls des types simples sont acceptés : pas de clé étrangère, pas de
     * déclencheur, pas de contrainte exotique — autant de surfaces inutiles ici.
     */
    public function creerTable(string $nom, array $colonnes): array
    {
        if (!in_array('donnees.table_privee', $this->capacites, true)) {
            throw ExtJournalSecurite::refuser([
                'extension' => $this->extension,
                'operation_declaree' => $this->operationDeclaree,
                'operation_reelle' => 'base.create_table',
                'motif' => 'capacite_table_privee_absente',
            ]);
        }
        if (!preg_match('/^[a-z][a-z0-9_]{0,40}$/', $nom)) {
            throw ExtJournalSecurite::refuser([
                'extension' => $this->extension, 'operation_reelle' => 'base.create_table',
                'motif' => 'nom_de_table_invalide',
            ]);
        }

        $TYPES = ['texte' => 'TEXT', 'entier' => 'INTEGER',
                  'decimal' => 'REAL', 'date' => 'TEXT'];

        $complet = ExtPolitiqueDonnees::prefixeExtension($this->extension) . $nom;
        if (ExtPolitiqueDonnees::nomReel($this->db, $complet) !== null) {
            return ['table' => $complet, 'creee' => false];   // déjà là : sans effet
        }

        $defs = ['"id" INTEGER PRIMARY KEY AUTOINCREMENT'];
        $pilote = $this->db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($pilote === 'pgsql') $defs = ['"id" SERIAL PRIMARY KEY'];
        if ($pilote === 'mysql') $defs = ['`id` INT AUTO_INCREMENT PRIMARY KEY'];

        foreach ($colonnes as $col => $type) {
            if (!preg_match('/^[a-z][a-z0-9_]{0,40}$/', (string)$col)) {
                throw ExtJournalSecurite::refuser([
                    'extension' => $this->extension, 'operation_reelle' => 'base.create_table',
                    'motif' => 'nom_de_colonne_invalide',
                ]);
            }
            if ($col === 'id') continue;
            // Une colonne dont le nom évoque un secret est refusée jusque dans
            // une table privée : sinon l'extension se fabrique un coffre que la
            // politique de colonnes ne protégerait pas.
            if (ExtPolitiqueDonnees::niveauColonne((string)$col)
                === ExtPolitiqueDonnees::REFUSE) {
                throw ExtJournalSecurite::refuser([
                    'extension' => $this->extension, 'operation_reelle' => 'base.create_table',
                    'colonnes' => $col, 'motif' => 'nom_de_colonne_reserve',
                    'gravite' => ExtJournalSecurite::NOTABLE,
                ]);
            }
            $t = $TYPES[strtolower((string)$type)] ?? null;
            if ($t === null) {
                throw ExtJournalSecurite::refuser([
                    'extension' => $this->extension, 'operation_reelle' => 'base.create_table',
                    'motif' => 'type_de_colonne_inconnu',
                ]);
            }
            $defs[] = '"' . $col . '" ' . $t;
        }
        if (count($defs) > 40) {
            throw ExtJournalSecurite::refuser([
                'extension' => $this->extension, 'operation_reelle' => 'base.create_table',
                'motif' => 'trop_de_colonnes',
            ]);
        }

        $this->db->getPdo()->exec(
            'CREATE TABLE "' . $complet . '" (' . implode(', ', $defs) . ')');
        ExtPolitiqueDonnees::oublier();

        ExtJournalSecurite::consigner(ExtJournalSecurite::AUTORISE, [
            'extension' => $this->extension, 'operation_reelle' => 'base.create_table',
            'table' => $complet, 'gravite' => ExtJournalSecurite::NOTABLE,
        ]);
        return ['table' => $complet, 'creee' => true];
    }

    /**
     * Ajoute à une table du module les colonnes déclarées qui lui manquent.
     *
     * La table était créée à l'installation, puis jamais mise à jour : un module
     * qui gagnait un champ en version suivante voyait TOUTE écriture refusée
     * par « colonne inexistante ». Côté utilisateur, un « operation_refusee »
     * opaque, et rien pour comprendre.
     *
     * On n'ajoute QUE des colonnes. Jamais de suppression, jamais de changement
     * de type : une colonne retirée d'une déclaration garde ses données, au cas
     * où le retrait serait une erreur ou la version suivante un retour arrière.
     *
     * @return string[] colonnes ajoutées
     */
    public function completerTable(string $nom, array $colonnes): array
    {
        if (!in_array('donnees.table_privee', $this->capacites, true)) return [];

        $table = ExtPolitiqueDonnees::prefixeExtension($this->extension) . $nom;
        $reel  = ExtPolitiqueDonnees::nomReel($this->db, $table);
        if ($reel === null) return [];

        $existantes = array_map('strtolower',
            ExtPolitiqueDonnees::colonnesExistantes($this->db, $reel));

        $TYPES = ['texte' => 'TEXT', 'entier' => 'INTEGER',
                  'decimal' => 'REAL', 'date' => 'TEXT'];
        $ajoutees = [];

        foreach ($colonnes as $cle => $type) {
            if (in_array(strtolower((string)$cle), $existantes, true)) continue;
            if (!preg_match('/^[a-z][a-z0-9_]{0,40}$/', (string)$cle)) continue;
            if (ExtPolitiqueDonnees::niveauColonne((string)$cle)
                === ExtPolitiqueDonnees::REFUSE) continue;
            $t = $TYPES[strtolower((string)$type)] ?? null;
            if ($t === null) continue;

            try {
                $this->db->getPdo()->exec(
                    'ALTER TABLE "' . $reel . '" ADD COLUMN "' . $cle . '" ' . $t);
                $ajoutees[] = (string)$cle;
            } catch (\Throwable $e) {
                // Une colonne qu'on ne peut pas ajouter n'empêche pas les autres.
            }
        }

        if ($ajoutees !== []) {
            ExtPolitiqueDonnees::oublier();
            ExtJournalSecurite::consigner(ExtJournalSecurite::AUTORISE, [
                'extension' => $this->extension,
                'operation_reelle' => 'base.alter_table',
                'table' => $reel, 'colonnes' => implode(',', $ajoutees),
                'gravite' => ExtJournalSecurite::NOTABLE,
            ]);
        }
        return $ajoutees;
    }

    /** Tables appartenant à cette extension. */
    public function mesTables(): array
    {
        $prefixe = ExtPolitiqueDonnees::prefixeExtension($this->extension);
        return array_values(array_filter(
            ExtPolitiqueDonnees::tablesExistantes($this->db),
            fn($t) => str_starts_with(strtolower($t), $prefixe)));
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Contrôle préalable : FAIL CLOSED
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Conditions minimales avant d'exécuter la moindre extension.
     *
     * @return array{ok:bool, bloquants:string[], avertissements:string[]}
     */
    public static function controlePrealable(): array
    {
        $bloquants = [];
        $avertissements = [];

        $prod = defined('APP_ENV') && APP_ENV === 'prod';


        // Les modes d'exécution et le confinement noyau ont disparu avec les
        // modules à code : il n'y a plus de code à isoler. Ce qu'un module peut
        // faire est borné par le format lui-même, pas par un réglage serveur.

        // ── 4. Le journal de sécurité doit être inscriptible ─────────────
        // Sans journal, un refus n'est plus tracé : on perd la seule preuve
        // qu'une tentative a eu lieu. Un système de sécurité muet n'en est pas un.
        $dossierJournal = dirname(__DIR__, 3) . '/data/securite';
        if (!is_dir($dossierJournal)) @mkdir($dossierJournal, 0700, true);
        if (!is_dir($dossierJournal) || !is_writable($dossierJournal)) {
            $bloquants[] = 'Le journal de sécurité (data/securite/) n\'est pas inscriptible : '
                . 'les décisions ne pourraient pas être tracées.';
        }

        // ── 5. Les secrets doivent être hors de portée ───────────────────
        $racine = dirname(__DIR__, 3);
        foreach (['.env', 'config.json', 'config.key'] as $f) {
            $chemin = $racine . '/' . $f;
            if (!is_file($chemin)) continue;
            $perms = substr(sprintf('%o', fileperms($chemin)), -3);
            if ((int)$perms[2] !== 0) {
                $message = 'Le fichier ' . $f . ' est lisible par tous (' . $perms . ') : '
                    . 'aucun compte dédié ne protégera de cela. Passez-le en 600.';
                $prod ? $bloquants[] = $message : $avertissements[] = $message;
            }
        }

        return ['ok' => $bloquants === [],
                'bloquants' => $bloquants,
                'avertissements' => $avertissements];
    }

    /**
     * Applique le contrôle. Lève si les conditions ne sont pas réunies.
     * @throws RuntimeException
     */
    public static function exigerConditions(): void
    {
        $c = self::controlePrealable();
        if ($c['ok']) return;

        ExtJournalSecurite::consigner(ExtJournalSecurite::REFUSE, [
            'extension' => '(toutes)',
            'operation_reelle' => 'demarrage',
            'motif' => 'conditions_de_securite_non_reunies',
            'gravite' => ExtJournalSecurite::CRITIQUE,
            'detail' => implode(' | ', $c['bloquants']),
        ]);

        throw new RuntimeException('Extensions désactivées : conditions de sécurité non '
            . 'réunies. ' . $c['bloquants'][0]);
    }
}

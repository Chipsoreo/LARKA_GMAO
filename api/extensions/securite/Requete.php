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
 * Larka — Sécurité des extensions : médiation des requêtes
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Une extension ne transmet JAMAIS de SQL. Elle décrit une intention :
 *
 *   {
 *     "action":  "SELECT",
 *     "table":   "ext_acme_cles",
 *     "colonnes":["id", "numero", "statut"],
 *     "ou":      {"statut": "actif"},
 *     "tri":     {"numero": "ASC"},
 *     "limite":  100,
 *     "depuis":  0
 *   }
 *
 * Le cœur vérifie chaque élément, puis CONSTRUIT lui-même la requête. Aucune
 * chaîne fournie par l'extension n'entre dans le SQL : les identifiants sont
 * remplacés par leur équivalent introspecté dans le schéma, les valeurs passent
 * en paramètres liés.
 *
 * POURQUOI PAS DU SQL FILTRÉ
 * Analyser du SQL pour décider s'il est inoffensif est un problème qu'on perd :
 * commentaires, encodages, sous-requêtes, fonctions du moteur, syntaxes
 * spécifiques. Le seul filtre sûr est de ne jamais accepter de SQL. Ici,
 * l'extension ne peut pas exprimer une injection : il n'y a aucun endroit où
 * l'écrire.
 * ═══════════════════════════════════════════════════════════════════════════════
 */

require_once __DIR__ . '/PolitiqueDonnees.php';
require_once __DIR__ . '/JournalSecurite.php';

final class ExtRequete
{
    public const ACTIONS = ['SELECT', 'INSERT', 'UPDATE', 'COUNT', 'SUPPRIMER'];

    /** Plafonds. DELETE, DROP, ALTER, TRUNCATE n'existent pas dans cette API. */
    private const LIMITE_DEFAUT = 100;
    private const LIMITE_MAX    = 1000;
    private const OU_MAX        = 20;
    private const COLONNES_MAX  = 60;

    private $db;
    private string $extension;
    private array $capacites;

    public function __construct($db, string $extension, array $capacites)
    {
        $this->db        = $db;
        $this->extension = $extension;
        $this->capacites = $capacites;
    }

    /**
     * Exécute une requête structurée après contrôle complet.
     *
     * @throws ExtRefusSecurite message opaque ; le détail va au journal
     */
    public function executer(array $r): array
    {
        $action = strtoupper((string)($r['action'] ?? ''));
        $tableDemandee = (string)($r['table'] ?? '');

        $refus = function (string $motif, array $extra = []) use ($action, $tableDemandee) {
            return ExtJournalSecurite::refuser(array_merge([
                'extension'          => $this->extension,
                'operation_declaree' => 'donnees.' . strtolower($action ?: 'inconnue'),
                'operation_reelle'   => 'base.' . strtolower($action ?: 'inconnue'),
                'table'              => $tableDemandee,
                'motif'              => $motif,
            ], $extra));
        };

        // ── 1. Action connue ─────────────────────────────────────────────
        if (!in_array($action, self::ACTIONS, true)) {
            // DELETE, DROP, TRUNCATE, ALTER tombent ici : elles n'existent pas.
            throw $refus('action_inconnue_ou_interdite', ['gravite' => ExtJournalSecurite::CRITIQUE]);
        }

        // ── 2. Table existante et autorisée ──────────────────────────────
        $table = ExtPolitiqueDonnees::nomReel($this->db, $tableDemandee);
        if ($table === null) {
            throw $refus('table_inexistante');
        }
        $niveau = ExtPolitiqueDonnees::niveauTable($this->db, $table);

        if ($niveau === ExtPolitiqueDonnees::SYSTEME_PROTEGE) {
            throw $refus('table_systeme_protegee', ['gravite' => ExtJournalSecurite::CRITIQUE]);
        }

        // Une table d'extension n'appartient qu'à son propriétaire.
        if ($niveau === ExtPolitiqueDonnees::EXTENSION
            && !ExtPolitiqueDonnees::appartientA($table, $this->extension)) {
            throw $refus('table_appartenant_a_une_autre_extension',
                         ['gravite' => ExtJournalSecurite::CRITIQUE]);
        }

        // ── SUPPRIMER : uniquement dans SA PROPRE table ───────────────────
        //
        // La suppression reste refusée sur toute donnée métier de Larka — c'est
        // irréversible et aucun usage ne le justifie. Mais un module qui tient
        // son propre registre doit pouvoir en retirer une ligne : un répertoire
        // sans suppression n'est pas utilisable.
        //
        // Le compromis est donc étroit : sa table à lui, une condition
        // obligatoire, et une trace.
        if ($action === 'SUPPRIMER') {
            if ($niveau !== ExtPolitiqueDonnees::EXTENSION) {
                throw $refus('suppression_hors_table_privee',
                             ['gravite' => ExtJournalSecurite::CRITIQUE]);
            }
        }

        $ecriture = in_array($action, ['INSERT', 'UPDATE', 'SUPPRIMER'], true);
        if ($ecriture && $niveau === ExtPolitiqueDonnees::SENSIBLE) {
            throw $refus('ecriture_interdite_sur_table_sensible');
        }
        if (!$this->capaciteSuffisante($niveau, $ecriture, $table)) {
            throw $refus($ecriture ? 'capacite_ecriture_absente' : 'capacite_lecture_absente');
        }

        // ── 3. Colonnes ──────────────────────────────────────────────────
        $existantes = ExtPolitiqueDonnees::colonnesExistantes($this->db, $table);
        if ($existantes === []) throw $refus('colonnes_introuvables');

        $demandees = $r['colonnes'] ?? ['*'];
        if (!is_array($demandees)) throw $refus('colonnes_malformees');
        if (count($demandees) > self::COLONNES_MAX) throw $refus('trop_de_colonnes');

        $colonnes = $this->resoudreColonnes($demandees, $existantes, $refus);

        // ── 4. Exécution ─────────────────────────────────────────────────
        return match ($action) {
            'SELECT' => $this->select($table, $colonnes, $r, $existantes, $refus),
            'COUNT'  => $this->compter($table, $r, $existantes, $refus),
            'INSERT' => $this->inserer($table, $r, $existantes, $refus),
            'UPDATE' => $this->modifier($table, $r, $existantes, $refus),
            'SUPPRIMER' => $this->supprimerLignes($table, $r, $existantes, $refus),
        };
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Contrôles
    // ═══════════════════════════════════════════════════════════════════════

    private function capaciteSuffisante(string $niveau, bool $ecriture, string $table): bool
    {
        // Sa propre table : l'extension en dispose librement, c'est la sienne.
        if ($niveau === ExtPolitiqueDonnees::EXTENSION) {
            return in_array('donnees.table_privee', $this->capacites, true);
        }
        $domaine = strtolower($table);
        return in_array('donnees.' . ($ecriture ? 'ecrire' : 'lire') . ':' . $domaine,
                        $this->capacites, true)
            || in_array('donnees.' . ($ecriture ? 'ecrire' : 'lire') . ':*',
                        $this->capacites, true);
    }

    /**
     * Résout les colonnes demandées contre celles qui existent réellement.
     *
     * Le nom retenu est TOUJOURS celui du schéma, jamais la chaîne fournie par
     * l'extension : c'est ce qui rend l'injection par nom de colonne impossible.
     */
    private function resoudreColonnes(array $demandees, array $existantes, callable $refus): array
    {
        // « * » : toutes les colonnes non protégées. On n'échoue pas, on réduit —
        // une extension légitime qui demande tout n'a pas à connaître la liste
        // des colonnes sensibles pour fonctionner.
        if ($demandees === ['*']) {
            $out = [];
            foreach ($existantes as $c) {
                if (ExtPolitiqueDonnees::niveauColonne($c) !== ExtPolitiqueDonnees::REFUSE) {
                    $out[] = $c;
                }
            }
            return $out ?: throw $refus('aucune_colonne_lisible');
        }

        $out = [];
        foreach ($demandees as $d) {
            if (!is_string($d)) throw $refus('colonne_malformee');
            $reel = null;
            foreach ($existantes as $c) {
                if (strcasecmp($c, $d) === 0) { $reel = $c; break; }
            }
            if ($reel === null) throw $refus('colonne_inexistante', ['colonnes' => $d]);

            // Demander explicitement une colonne secrète n'est pas une erreur
            // de frappe : c'est une tentative. On refuse l'opération entière.
            if (ExtPolitiqueDonnees::niveauColonne($reel) === ExtPolitiqueDonnees::REFUSE) {
                throw $refus('colonne_secrete_demandee', [
                    'colonnes' => $reel, 'gravite' => ExtJournalSecurite::CRITIQUE]);
            }
            $out[] = $reel;
        }
        return $out;
    }

    /** Conditions : uniquement des égalités sur des colonnes existantes. */
    private function conditions(array $r, array $existantes, callable $refus): array
    {
        $ou = $r['ou'] ?? [];
        if (!is_array($ou)) throw $refus('conditions_malformees');
        if (count($ou) > self::OU_MAX) throw $refus('trop_de_conditions');

        $clauses = [];
        $params = [];
        $i = 0;
        foreach ($ou as $col => $val) {
            if (!is_string($col)) throw $refus('condition_malformee');
            $reel = null;
            foreach ($existantes as $c) {
                if (strcasecmp($c, $col) === 0) { $reel = $c; break; }
            }
            if ($reel === null) throw $refus('condition_sur_colonne_inexistante');

            // Filtrer sur une colonne secrète permet de la deviner valeur par
            // valeur : un oracle aussi efficace que la lire.
            if (ExtPolitiqueDonnees::niveauColonne($reel) === ExtPolitiqueDonnees::REFUSE) {
                throw $refus('condition_sur_colonne_secrete',
                             ['gravite' => ExtJournalSecurite::CRITIQUE]);
            }
            if (!is_scalar($val) && $val !== null) throw $refus('valeur_de_condition_invalide');

            $cle = 'p' . $i++;
            $clauses[] = '"' . $reel . '" = :' . $cle;
            $params[$cle] = $val;
        }
        return [$clauses ? ' WHERE ' . implode(' AND ', $clauses) : '', $params];
    }

    private function limite(array $r): int
    {
        $l = (int)($r['limite'] ?? self::LIMITE_DEFAUT);
        return max(1, min($l, self::LIMITE_MAX));
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Constructions
    // ═══════════════════════════════════════════════════════════════════════

    private function select(string $table, array $colonnes, array $r,
                            array $existantes, callable $refus): array
    {
        [$where, $params] = $this->conditions($r, $existantes, $refus);

        $orderBy = '';
        if (!empty($r['tri']) && is_array($r['tri'])) {
            $morceaux = [];
            foreach ($r['tri'] as $col => $sens) {
                $reel = null;
                foreach ($existantes as $c) {
                    if (strcasecmp($c, (string)$col) === 0) { $reel = $c; break; }
                }
                if ($reel === null) throw $refus('tri_sur_colonne_inexistante');
                // Le sens est ramené à deux valeurs closes : rien de l'extension
                // n'atteint le SQL, même ici.
                $morceaux[] = '"' . $reel . '" '
                            . (strtoupper((string)$sens) === 'DESC' ? 'DESC' : 'ASC');
            }
            if ($morceaux) $orderBy = ' ORDER BY ' . implode(', ', $morceaux);
        }

        $limite = $this->limite($r);
        $depuis = max(0, min((int)($r['depuis'] ?? 0), 100000));

        $sql = 'SELECT ' . implode(', ', array_map(fn($c) => '"' . $c . '"', $colonnes))
             . ' FROM "' . $table . '"' . $where . $orderBy
             . ' LIMIT ' . $limite . ' OFFSET ' . $depuis;

        $st = $this->db->getPdo()->prepare($sql);
        $st->execute($params);
        $lignes = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Dernier filet : la politique de colonnes s'applique au résultat, même
        // si la construction l'avait laissée passer.
        //
        // Sauf dans la table du module : masquer « m.l***@… » dans SES propres
        // données rendrait le champ inutilisable — il l'a déclaré, il l'a saisi,
        // il doit pouvoir le relire.
        if (!ExtPolitiqueDonnees::appartientA($table, $this->extension)) {
            $lignes = array_map([ExtPolitiqueDonnees::class, 'filtrerLigne'], $lignes);
        }

        ExtJournalSecurite::consigner(ExtJournalSecurite::AUTORISE, [
            'extension' => $this->extension, 'operation_reelle' => 'base.select',
            'table' => $table, 'colonnes' => implode(',', $colonnes),
            'volume' => count($lignes),
        ]);
        return ['lignes' => $lignes, 'nombre' => count($lignes)];
    }

    private function compter(string $table, array $r, array $existantes, callable $refus): array
    {
        [$where, $params] = $this->conditions($r, $existantes, $refus);
        $st = $this->db->getPdo()->prepare('SELECT COUNT(*) FROM "' . $table . '"' . $where);
        $st->execute($params);
        return ['nombre' => (int)$st->fetchColumn()];
    }

    private function inserer(string $table, array $r, array $existantes, callable $refus): array
    {
        $valeurs = $r['valeurs'] ?? [];
        if (!is_array($valeurs) || $valeurs === []) throw $refus('valeurs_absentes');

        $cols = [];
        $params = [];
        $i = 0;
        foreach ($valeurs as $col => $val) {
            $reel = null;
            foreach ($existantes as $c) {
                if (strcasecmp($c, (string)$col) === 0) { $reel = $c; break; }
            }
            if ($reel === null) throw $refus('colonne_inexistante_en_ecriture');

            // La politique de colonnes protège les données du CŒUR. Dans la
            // table d'un module, elle refusait à l'auteur d'écrire dans son
            // propre champ « courriel » ou « telephone » — masqués par motif —
            // alors qu'il s'agit de SES données, qu'il a déclarées.
            //
            // Seuls les noms de secret restent interdits partout : ils sont
            // déjà refusés à la déclaration, ceci est le filet.
            $niveau = ExtPolitiqueDonnees::niveauColonne($reel);
            $sienne = ExtPolitiqueDonnees::appartientA($table, $this->extension);
            $tolere = $sienne && $niveau !== ExtPolitiqueDonnees::REFUSE;
            if (!$tolere && $niveau !== ExtPolitiqueDonnees::AUTORISE) {
                throw $refus('ecriture_sur_colonne_protegee',
                             ['gravite' => ExtJournalSecurite::CRITIQUE]);
            }
            if (!is_scalar($val) && $val !== null) throw $refus('valeur_invalide');
            if (is_string($val) && strlen($val) > 65535) throw $refus('valeur_trop_longue');

            $cle = 'v' . $i++;
            $cols[$reel] = ':' . $cle;
            $params[$cle] = $val;
        }

        $sql = 'INSERT INTO "' . $table . '" ('
             . implode(', ', array_map(fn($c) => '"' . $c . '"', array_keys($cols)))
             . ') VALUES (' . implode(', ', $cols) . ')';
        $pdo = $this->db->getPdo();
        $st = $pdo->prepare($sql);
        $st->execute($params);

        ExtJournalSecurite::consigner(ExtJournalSecurite::AUTORISE, [
            'extension' => $this->extension, 'operation_reelle' => 'base.insert',
            'table' => $table, 'colonnes' => implode(',', array_keys($cols)),
        ]);
        return ['id' => (int)$pdo->lastInsertId(), 'affectees' => 1];
    }

    /**
     * Suppression dans une table appartenant au module.
     * Condition obligatoire : sans elle on vide la table entière.
     */
    private function supprimerLignes(string $table, array $r,
                                     array $existantes, callable $refus): array
    {
        [$where, $params] = $this->conditions($r, $existantes, $refus);
        if ($where === '') {
            throw $refus('suppression_sans_condition',
                         ['gravite' => ExtJournalSecurite::CRITIQUE]);
        }

        $st = $this->db->getPdo()->prepare('DELETE FROM "' . $table . '"' . $where);
        $st->execute($params);

        ExtJournalSecurite::consigner(ExtJournalSecurite::AUTORISE, [
            'extension' => $this->extension, 'operation_reelle' => 'base.supprimer',
            'table' => $table, 'volume' => $st->rowCount(),
            'gravite' => ExtJournalSecurite::NOTABLE,
        ]);
        return ['affectees' => $st->rowCount()];
    }

    private function modifier(string $table, array $r, array $existantes, callable $refus): array
    {
        $valeurs = $r['valeurs'] ?? [];
        if (!is_array($valeurs) || $valeurs === []) throw $refus('valeurs_absentes');

        [$where, $params] = $this->conditions($r, $existantes, $refus);
        // Un UPDATE sans condition touche toute la table : c'est une corruption
        // de masse, pas une modification. On l'interdit sans exception.
        if ($where === '') {
            throw $refus('update_sans_condition', ['gravite' => ExtJournalSecurite::CRITIQUE]);
        }

        $sets = [];
        $i = 0;
        foreach ($valeurs as $col => $val) {
            $reel = null;
            foreach ($existantes as $c) {
                if (strcasecmp($c, (string)$col) === 0) { $reel = $c; break; }
            }
            if ($reel === null) throw $refus('colonne_inexistante_en_ecriture');

            // La politique de colonnes protège les données du CŒUR. Dans la
            // table d'un module, elle refusait à l'auteur d'écrire dans son
            // propre champ « courriel » ou « telephone » — masqués par motif —
            // alors qu'il s'agit de SES données, qu'il a déclarées.
            //
            // Seuls les noms de secret restent interdits partout : ils sont
            // déjà refusés à la déclaration, ceci est le filet.
            $niveau = ExtPolitiqueDonnees::niveauColonne($reel);
            $sienne = ExtPolitiqueDonnees::appartientA($table, $this->extension);
            $tolere = $sienne && $niveau !== ExtPolitiqueDonnees::REFUSE;
            if (!$tolere && $niveau !== ExtPolitiqueDonnees::AUTORISE) {
                throw $refus('ecriture_sur_colonne_protegee',
                             ['gravite' => ExtJournalSecurite::CRITIQUE]);
            }
            if (!is_scalar($val) && $val !== null) throw $refus('valeur_invalide');

            $cle = 's' . $i++;
            $sets[] = '"' . $reel . '" = :' . $cle;
            $params[$cle] = $val;
        }

        $st = $this->db->getPdo()->prepare(
            'UPDATE "' . $table . '" SET ' . implode(', ', $sets) . $where);
        $st->execute($params);

        ExtJournalSecurite::consigner(ExtJournalSecurite::AUTORISE, [
            'extension' => $this->extension, 'operation_reelle' => 'base.update',
            'table' => $table, 'volume' => $st->rowCount(),
        ]);
        return ['affectees' => $st->rowCount()];
    }
}

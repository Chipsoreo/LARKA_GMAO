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
 * Larka — Modules déclaratifs : le moteur
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Exécute un module déclaratif. Le code est celui de Larka ; le module ne
 * fournit qu'une description.
 *
 * Toutes les opérations passent malgré tout par la Security API. On aurait pu
 * s'en dispenser — la déclaration est validée, le moteur est le nôtre — mais ce
 * serait une exception dans le chemin d'accès aux données, et les exceptions
 * finissent par être empruntées. Un seul chemin, toujours le même : plus simple
 * à vérifier, et le journal reste complet.
 * ═══════════════════════════════════════════════════════════════════════════════
 */

require_once __DIR__ . '/Schema.php';
require_once __DIR__ . '/Expression.php';
require_once __DIR__ . '/../securite/Securite.php';
require_once __DIR__ . '/../Fichiers.php';
require_once __DIR__ . '/../Paquet.php';
require_once __DIR__ . '/../Config.php';

final class ExtMoteurDeclaratif
{
    private array $declaration;
    private ExtSecurite $securite;
    private string $identifiant;

    private $db;
    private array $utilisateur;

    public function __construct(string $identifiant, array $declaration, $db,
                                array $utilisateur = [])
    {
        $this->identifiant = $identifiant;
        $this->declaration = $declaration;
        $this->db          = $db;
        $this->utilisateur = $utilisateur;
        $this->securite = new ExtSecurite($db, $identifiant,
            ExtSchemaDeclaratif::capacitesRequises($declaration), $utilisateur);
    }

    /**
     * Résout un calcul portant sur un partage d'un AUTRE module.
     *
     * Le mécanisme tient en une phrase : c'est le module qui DÉTIENT la donnée
     * qui décide de ce qu'il publie, pas celui qui la demande.
     *
     * Concrètement :
     *   1. on retrouve le module fournisseur et son partage déclaré ;
     *   2. on vérifie qu'il est bien installé et actif ici ;
     *   3. on n'accepte que les champs qu'il a explicitement publiés ;
     *   4. on interroge SA table, en son nom à lui — donc avec ses droits.
     *
     * Le consommateur n'obtient jamais de droit de lecture sur le jeu complet :
     * il reçoit un nombre, calculé par le cœur. Un partage qui disparaît fait
     * simplement échouer le calcul, il n'ouvre rien.
     */
    private function calculer(array $calcul, array $ligne): mixed
    {
        [$moduleFournisseur, $nomPartage] = explode('/', $calcul['de'], 2);

        $registre = ExtRegistre::instance();
        $actives  = $registre->actives();
        if (!isset($actives[$moduleFournisseur])) {
            // Module absent ou non affecté à ce client : le calcul n'a pas de
            // valeur, il n'en invente pas.
            return null;
        }

        $decl = $actives[$moduleFournisseur]->declaration();
        $partage = $decl['partage'][$nomPartage] ?? null;
        if ($partage === null) return null;

        // Le champ demandé doit figurer parmi ceux que le fournisseur publie.
        if (isset($calcul['champ']) && !in_array($calcul['champ'], $partage['champs'], true)) {
            ExtJournalSecurite::consigner(ExtJournalSecurite::REFUSE, [
                'extension'        => $this->identifiant,
                'operation_reelle' => 'declaratif.calcul_externe',
                'ressource'        => $calcul['de'],
                'colonnes'         => $calcul['champ'],
                'motif'            => 'champ_non_publie_par_le_fournisseur',
                'gravite'          => ExtJournalSecurite::NOTABLE,
            ]);
            return null;
        }

        // Conditions : « @champ » prend la valeur de la ligne courante.
        $ou = [];
        foreach ($calcul['ou'] as $cle => $val) {
            if (!in_array($cle, $partage['champs'], true)) return null;
            $ou[$cle] = str_starts_with((string)$val, '@')
                ? ($ligne[substr((string)$val, 1)] ?? null)
                : $val;
        }

        // La lecture se fait AU NOM DU FOURNISSEUR : c'est sa table, ses droits.
        // Le consommateur n'acquiert rien au passage.
        $securiteFournisseur = new ExtSecurite($this->db, $moduleFournisseur,
            ['donnees.table_privee'], $this->utilisateur);

        try {
            $r = $securiteFournisseur->requeter([
                '_intention' => 'partage.' . $nomPartage,
                'action'     => 'SELECT',
                'table'      => ExtPolitiqueDonnees::prefixeExtension($moduleFournisseur)
                                . $partage['source'],
                'colonnes'   => $partage['champs'],
                'ou'         => $ou,
                'limite'     => 1000,
            ]);
        } catch (\Throwable $e) {
            return null;
        }

        $valeurs = array_column($r['lignes'], $calcul['champ'] ?? 'id');
        return match ($calcul['type']) {
            'compte'  => count($r['lignes']),
            'somme'   => array_sum(array_map('floatval', $valeurs)),
            'moyenne' => $valeurs ? array_sum(array_map('floatval', $valeurs)) / count($valeurs) : null,
            'minimum' => $valeurs ? min(array_map('floatval', $valeurs)) : null,
            'maximum' => $valeurs ? max(array_map('floatval', $valeurs)) : null,
            default   => null,
        };
    }

    /**
     * Évalue les champs « formule » de chaque ligne.
     *
     * L'arbre a été compilé une fois, à l'installation : ici on ne fait
     * qu'évaluer. Le contexte est volontairement pauvre — la ligne,
     * l'utilisateur, la date — pour qu'une formule ne puisse rien atteindre
     * d'autre que ce qu'elle a sous les yeux.
     */
    private function enrichirFormules(array $lignes, array $jeu): array
    {
        $formules = array_filter($jeu['champs'],
            fn($c) => ($c['type'] ?? '') === 'formule');
        if ($formules === []) return $lignes;

        $base = [
            'user'      => ['nom' => $this->utilisateur['Login'] ?? '',
                            'role' => $this->utilisateur['Role'] ?? ''],
            'reglages'  => $this->reglages(),
            'today'     => date('Y-m-d'),
            'variables' => $this->declaration['variables'] ?? [],
        ];

        foreach ($lignes as $i => $ligne) {
            foreach ($formules as $cle => $champ) {
                try {
                    $lignes[$i][$cle] = ExtExpression::evaluer(
                        $champ['arbre'], $base + ['record' => $ligne]);
                } catch (\Throwable $e) {
                    // Une formule qui échoue sur une ligne ne fait pas échouer
                    // la liste entière : la case reste vide.
                    $lignes[$i][$cle] = null;
                }
            }
        }
        return $lignes;
    }

    /**
     * Remplace les identifiants des champs « lien » par un libellé lisible.
     *
     * Sans cela, une liste affiche « 418 » — exact, et inutilisable. Les cibles
     * sont lues EN UNE FOIS par domaine, jamais ligne par ligne : cent lignes
     * liées à des équipements feraient sinon cent lectures.
     */
    private function enrichirLiens(array $lignes, array $jeu): array
    {
        $liens = array_filter($jeu['champs'], fn($c) => ($c['type'] ?? '') === 'lien');
        if ($liens === [] || $lignes === []) return $lignes;

        foreach ($liens as $cle => $champ) {
            $ids = array_values(array_unique(array_filter(
                array_column($lignes, $cle), fn($v) => (int)$v > 0)));
            if ($ids === []) continue;

            $affichage = ExtSchemaDeclaratif::CIBLES_LIEN[$champ['vers']]['affichage'] ?? ['Id'];
            $index = [];
            try {
                foreach ($this->securite->requeter([
                    '_intention' => 'lien.' . $champ['vers'],
                    'action'   => 'SELECT',
                    'table'    => $champ['vers'],
                    'colonnes' => array_merge(['Id'], $affichage),
                    'limite'   => 1000,
                ])['lignes'] as $c) {
                    $id = self::cleId($c);
                    $index[$id] = trim(implode(' ', array_filter(
                        array_map(fn($a) => self::valeur($c, $a), $affichage)))) ?: ('#' . $id);
                }
            } catch (\Throwable $e) {
                // Capacité retirée ou domaine indisponible : on garde l'identifiant
                // plutôt que d'interrompre la liste.
                continue;
            }

            foreach ($lignes as $i => $l) {
                $v = (int)($l[$cle] ?? 0);
                // La cible peut avoir été supprimée : on le DIT, plutôt que
                // d'afficher un vide qui ferait croire à une absence de lien.
                $lignes[$i][$cle . '_libelle'] = $v > 0
                    ? ($index[$v] ?? 'Supprimé (#' . $v . ')') : null;
            }
        }
        return $lignes;
    }

    /**
     * Valeur de la clé primaire d'une ligne, quelle que soit sa casse.
     *
     * Le code lisait `$ligne['Id']`. Cela fonctionne sur SQLite, qui conserve la
     * casse des identifiants — et échoue sur PostgreSQL et MariaDB, qui replient
     * les identifiants non quotés en minuscules : la colonne s'y nomme « id ».
     *
     * Le module tombait alors en « Undefined array key "Id" », trois fois de
     * suite, puis se suspendait. Le défaut était invisible en développement, où
     * l'on utilise SQLite, et systématique en production.
     */
    /**
     * Valeur d'une colonne, sans se fier à la casse.
     * Même raison que cleId() : « Numero » devient « numero » sur PostgreSQL.
     */
    private static function valeur(array $ligne, string $colonne): string
    {
        foreach ($ligne as $c => $v) {
            if (strcasecmp((string)$c, $colonne) === 0) return (string)$v;
        }
        return '';
    }

    private static function cleId(array $ligne): int
    {
        foreach ($ligne as $c => $v) {
            if (strcasecmp((string)$c, 'id') === 0) return (int)$v;
        }
        return 0;
    }

    /** Ajoute les calculs déclarés à chaque ligne renvoyée. */
    private function enrichirCalculs(array $lignes): array
    {
        $calculs = $this->declaration['calculs'] ?? [];
        if ($calculs === []) return $lignes;

        foreach ($lignes as $i => $ligne) {
            foreach ($calculs as $nom => $c) {
                $lignes[$i][$nom] = $this->calculer($c, $ligne);
            }
        }
        return $lignes;
    }

    /** Nom de la table portant un jeu de données. */
    private function table(string $jeu): string
    {
        return ExtPolitiqueDonnees::prefixeExtension($this->identifiant) . $jeu;
    }

    private function jeu(string $nom): array
    {
        $j = $this->declaration['donnees'][$nom] ?? null;
        if ($j === null) throw new ExtErreurUtilisateur('Jeu de données inconnu.');
        return $j;
    }

    /**
     * Crée les tables décrites. Sans effet si elles existent déjà.
     * Appelé à l'installation, et à chaque chargement — une table effacée à la
     * main réapparaît, plutôt que de laisser le module en panne silencieuse.
     */
    public function preparer(): array
    {
        $faites = [];
        foreach ($this->declaration['donnees'] as $nom => $jeu) {
            $colonnes = [];
            foreach ($jeu['champs'] as $cle => $champ) {
                // Un champ calculé n'a pas de colonne : il est recalculé à la
                // lecture. En créer une le laisserait se désynchroniser.
                if (($champ['type'] ?? '') === 'formule') continue;

                $colonnes[$cle] = ExtSchemaDeclaratif::TYPES[$champ['type']]['sql'];
            }
            // Traçabilité : posée par Larka, jamais déclarée par l'auteur — et
            // donc impossible à oublier. Sans elle, personne ne sait qui a saisi
            // une ligne, et l'on s'en aperçoit six mois trop tard.
            foreach (ExtSchemaDeclaratif::CHAMPS_AUDIT as $cle => $def) {
                $colonnes[$cle] = $def['type'];
            }
            $faites[] = $this->securite->creerTable($nom, $colonnes);

            // Mise à jour : une table déjà présente reçoit les colonnes que la
            // nouvelle version déclare.
            $this->preparerFichiers();
            $this->preparerConfig();

            $ajoutees = $this->securite->completerTable($nom, $colonnes);
            if ($ajoutees !== []) {
                $faites[count($faites) - 1]['colonnes_ajoutees'] = $ajoutees;
            }
        }
        return $faites;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Actions, toutes dérivées de la déclaration
    // ═══════════════════════════════════════════════════════════════════════

    public function lister(string $jeuNom, array $charge): array
    {
        $jeu = $this->jeu($jeuNom);
        // Le jeu est nommé PAR LE CLIENT : sans ce contrôle, un rôle admis sur
        // une page du module lisait n'importe quel autre jeu du même module,
        // y compris celui d'une page qui ne lui est pas ouverte.
        $this->lisibleSur($jeuNom);
        $page = $this->pageDe($jeuNom);
        $vue  = $page['vue'];

        $ou = [];
        foreach ($vue['filtres'] as $f) {
            $v = $charge['filtres'][$f['champ']] ?? null;
            if ($v !== null && $v !== '') $ou[$f['champ']] = $v;
        }

        // ── Pagination ───────────────────────────────────────────────────
        //
        // « par_page » était déclaré, validé… et jamais appliqué : toutes les
        // lignes partaient dans la réponse, jusqu'au plafond de 1000. Sur un
        // registre qui grossit, la page devient lente puis tronquée sans
        // prévenir — le pire ordre, puisque l'utilisateur ne voit pas ce qui
        // manque.
        //
        // La recherche, elle, porte sur l'ensemble : on récupère donc une page
        // ÉLARGIE quand une recherche est en cours, sinon chercher ne
        // trouverait que dans la page affichée.
        $parPage  = max(5, min((int)($vue['par_page'] ?? 50), 200));
        $page     = max(1, (int)($charge['page'] ?? 1));
        $recherche = trim((string)($charge['recherche'] ?? ''));

        $r = $this->securite->requeter([
            'action'   => 'SELECT',
            'table'    => $this->table($jeuNom),
            'colonnes' => ['*'],
            'ou'       => $ou,
            // Tri demandé par l'utilisateur, restreint aux champs du jeu : le
            // nom traverse ensuite resoudreColonnes(), qui n'accepte que ceux
            // du schéma. Une colonne inventée est refusée, pas interprétée.
            'tri'      => $this->triDemande($jeu, $charge) ?: ($vue['tri'] ?: ['id' => 'DESC']),
            'limite'   => $recherche !== '' ? 1000 : $parPage,
            'depuis'   => $recherche !== '' ? 0 : ($page - 1) * $parPage,
        ]);

        // Nombre total, pour savoir combien de pages existent.
        $total = $this->securite->requeter([
            'action' => 'COUNT', 'table' => $this->table($jeuNom), 'ou' => $ou,
        ])['nombre'] ?? 0;
        $lignes = $r['lignes'];

        // La recherche libre se fait ICI, en PHP, sur le résultat : rien de ce
        // que saisit l'utilisateur n'entre dans la requête.
        $q = mb_strtolower(trim((string)($charge['recherche'] ?? '')));
        if ($q !== '' && $vue['recherche']) {
            $lignes = array_values(array_filter($lignes, function ($l) use ($q, $vue) {
                foreach ($vue['recherche'] as $c) {
                    if (str_contains(mb_strtolower((string)($l[$c] ?? '')), $q)) return true;
                }
                return false;
            }));
        }

        $lignes = $this->enrichirLiens($lignes, $jeu);
        $lignes = $this->enrichirFormules($lignes, $jeu);
        $lignes = $this->enrichirCalculs($lignes);

        // Deux comptes, deux noms explicites. Ils s'appelaient « nombre » et
        // « total » : impossible de deviner lequel tenait compte de la
        // recherche, et l'auteur de ces lignes s'y est trompé en écrivant le
        // premier test. Un nom ambigu finit toujours par coûter un bug.
        // Totaux : calculés sur la PAGE affichée, et le client doit le dire.
        // Un total qui porterait sur toute la table demanderait une seconde
        // requête agrégée — et un total dont on ignore l'assiette est pire
        // qu'une absence de total.
        $totaux = [];
        foreach (($this->declaration['pages'][0]['vue']['totaux'] ?? []) as $c) {
            $totaux[$c] = array_sum(array_map('floatval', array_column($lignes, $c)));
        }

        return [
            'totaux'                 => $totaux,
            'page'                   => $page,
            'par_page'               => $parPage,
            'total'                  => $total,
            'pages'                  => (int)max(1, ceil($total / $parPage)),
            'lignes'                 => $lignes,
            'nombre'                 => count($lignes),      // après recherche
            'total_avant_recherche'  => $r['nombre'],
            'jeu'                    => $jeuNom,
        ];
    }

    /**
     * Valeur actuellement enregistrée pour un champ d'une ligne.
     *
     * Sert à reconnaître une valeur HÉRITÉE : celle que la fiche porte déjà et
     * que la liste ne propose plus. Passe par la couche de sécurité comme toute
     * autre lecture — le nom du jeu et celui du champ viennent de la
     * déclaration validée, jamais de la requête.
     */
    private function valeurActuelle(string $jeuNom, int $id, string $champ): ?string
    {
        try {
            $r = $this->securite->requeter([
                'action'   => 'SELECT',
                'table'    => $this->table($jeuNom),
                'colonnes' => [$champ],
                'ou'       => ['id' => $id],
                'limite'   => 1,
            ]);
            $ligne = $r['lignes'][0] ?? null;
            return $ligne === null ? null : (string)($ligne[$champ] ?? '');
        } catch (\Throwable $e) {
            // Lecture impossible : on ne tolère rien, la valeur sera refusée.
            // Un échec de vérification ne doit pas valoir autorisation.
            return null;
        }
    }

    public function enregistrer(string $jeuNom, array $charge): array
    {
        $jeu = $this->jeu($jeuNom);
        $id = (int)($charge['id'] ?? 0);

        // ── L'écriture était la seule opération SANS AUCUN contrôle ───────
        // « creer » et « modifier » se déclaraient page par page, s'affichaient
        // dans le rapport d'installation… et rien ne les appliquait. Voir
        // surfaces() pour ce que cela ouvrait exactement.
        $action    = $id > 0 ? 'modifier' : 'creer';
        $surfaces  = $this->surfaces($jeuNom, $action);
        if ($surfaces === []) {
            throw new ExtErreurUtilisateur($id > 0
                ? 'La modification n\'est pas prévue par cet écran.'
                : 'La création n\'est pas prévue par cet écran.');
        }

        $saisie = (array)($charge['valeurs'] ?? []);
        $saisie = $this->ecarterChampsNonExposes($saisie, $surfaces, $jeuNom);

        $valeurs = $this->valider($jeu, $saisie, $jeuNom, $id);

        // Audit : le moteur écrit ces quatre colonnes lui-même. Une déclaration
        // ne peut ni les fournir ni les falsifier — valider() les a écartées
        // avec les autres champs non déclarés.
        $qui = (string)($this->utilisateur['Login'] ?? '?');
        $valeurs['modifie_le']  = date('Y-m-d H:i:s');
        $valeurs['modifie_par'] = $qui;
        if ($id <= 0) {
            $valeurs['cree_le']  = date('Y-m-d H:i:s');
            $valeurs['cree_par'] = $qui;
        }

        if ($id > 0) {
            $this->securite->requeter([
                '_intention' => $jeuNom . '.modification',
                'action' => 'UPDATE', 'table' => $this->table($jeuNom),
                'valeurs' => $valeurs, 'ou' => ['id' => $id],
            ]);
            return ['id' => $id, 'cree' => false];
        }

        $r = $this->securite->requeter([
            '_intention' => $jeuNom . '.creation',
            'action' => 'INSERT', 'table' => $this->table($jeuNom), 'valeurs' => $valeurs,
        ]);
        return ['id' => $r['id'], 'cree' => true];
    }

    public function supprimer(string $jeuNom, array $charge): array
    {
        $this->jeu($jeuNom);
        $this->autoriseeSur($jeuNom, 'supprimer');
        $id = (int)($charge['id'] ?? 0);
        if ($id <= 0) throw new ExtErreurUtilisateur('Identifiant manquant.');

        $r = $this->securite->requeter([
            '_intention' => $jeuNom . '.suppression',
            'action' => 'SUPPRIMER', 'table' => $this->table($jeuNom), 'ou' => ['id' => $id],
        ]);
        return ['supprimees' => $r['affectees']];
    }

    /**
     * Alimente un ancrage déclaratif.
     *
     * Le client demande « donne-moi de quoi dessiner l'ancrage n° i » ; il ne
     * choisit ni la source, ni les colonnes, ni le filtre — tout vient de la
     * déclaration validée. Un ancrage ne peut donc pas servir de porte dérobée
     * pour lire autre chose que ce qu'il annonce.
     */
    /**
     * Valeurs proposables pour un champ « lien ».
     * Le client demande un champ, jamais une table : la cible vient de la
     * déclaration, pas du message.
     */
    public function cibles(string $jeuNom, array $charge): array
    {
        $jeu   = $this->jeu($jeuNom);
        $this->lisibleSur($jeuNom);
        $champ = $jeu['champs'][(string)($charge['champ'] ?? '')] ?? null;
        if (!$champ || ($champ['type'] ?? '') !== 'lien') {
            throw new ExtErreurUtilisateur('Champ de lien inconnu.');
        }

        $affichage = ExtSchemaDeclaratif::CIBLES_LIEN[$champ['vers']]['affichage'] ?? ['Id'];
        $r = $this->securite->requeter([
            '_intention' => 'lien.choix',
            'action' => 'SELECT', 'table' => $champ['vers'],
            'colonnes' => array_merge(['Id'], $affichage), 'limite' => 500,
        ]);

        $out = [];
        $q = mb_strtolower(trim((string)($charge['recherche'] ?? '')));
        foreach ($r['lignes'] as $l) {
            $id  = self::cleId($l);
            $lib = trim(implode(' ', array_filter(
                array_map(fn($a) => self::valeur($l, $a), $affichage)))) ?: ('#' . $id);
            if ($q !== '' && !str_contains(mb_strtolower($lib), $q)) continue;
            $out[] = ['id' => $id, 'libelle' => $lib];
        }
        usort($out, fn($a, $b) => strnatcasecmp($a['libelle'], $b['libelle']));
        return ['valeurs' => array_slice($out, 0, 200), 'total' => count($out)];
    }

    /**
     * Valeurs déjà saisies dans un champ, filtrées par ce qui est tapé.
     *
     * Sans cela, « Titulaire » se ressaisit à la main sur chaque ligne, et les
     * variantes s'accumulent — « M. Durand », « Durand », « durand » — jusqu'à
     * rendre un filtre ou un regroupement inexploitable.
     *
     * Ne lit que la table du module, et que le champ demandé.
     */
    public function suggestions(string $jeuNom, array $charge): array
    {
        $jeu   = $this->jeu($jeuNom);
        $this->lisibleSur($jeuNom);
        $cle   = (string)($charge['champ'] ?? '');
        $champ = $jeu['champs'][$cle] ?? null;

        if (!$champ || empty($champ['suggestions'])) {
            throw new ExtErreurUtilisateur('Ce champ ne propose pas de suggestions.');
        }

        $r = $this->securite->requeter([
            '_intention' => $jeuNom . '.suggestions',
            'action'   => 'SELECT',
            'table'    => $this->table($jeuNom),
            'colonnes' => [$cle],
            'limite'   => 2000,
        ]);

        $q = mb_strtolower(trim((string)($charge['recherche'] ?? '')));
        $vues = [];

        // Source du cœur, si le champ en déclare une : sans elle, la première
        // saisie n'a aucune aide — et c'est celle où l'orthographe se fixe.
        if (!empty($champ['suggestions_source'])) {
            $src = ExtSchemaDeclaratif::SOURCES_SUGGESTION[$champ['suggestions_source']];

            // Source servie par le cœur : requête FIXE, écrite ici, jamais
            // composée à partir de la déclaration. Le module obtient des noms,
            // pas un accès à la table qui les contient.
            // Cette source-là ne passe pas par la Security API : c'est une
            // requête FIXE écrite ici. La capacité déduite doit donc être
            // vérifiée à la main — sinon le seul accès du moteur à la table des
            // comptes serait le seul à ne pas la contrôler.
            if ($src['table'] === null
                && !in_array('donnees.lire:noms',
                    ExtSchemaDeclaratif::capacitesRequises($this->declaration), true)) {
                $src = ['table' => null, 'colonnes' => [], 'interdite' => true];
            }
            if ($src['table'] === null && empty($src['interdite'])) {
                try {
                    $st = $this->db->getPdo()->query(
                        'SELECT Prenom, Nom FROM Utilisateurs WHERE Actif = 1 '
                        . 'ORDER BY Nom, Prenom LIMIT 500');
                    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) {
                        $v = trim(self::valeur($u, 'Prenom') . ' ' . self::valeur($u, 'Nom'));
                        if ($v === '') continue;
                        if ($q !== '' && !str_contains(mb_strtolower($v), $q)) continue;
                        $vues[$v] = ($vues[$v] ?? 0);
                    }
                } catch (\Throwable $e) { /* comptes indisponibles */ }
            }

            // Source d'un domaine métier : passe par la Security API, avec la
            // capacité déduite du champ.
            try {
                if ($src['table'] === null) $src = null;   // déjà servie ci-dessus
                if ($src === null) throw new RuntimeException('sans table');
                $ext = $this->securite->requeter([
                    '_intention' => 'suggestions.' . $champ['suggestions_source'],
                    'action'   => 'SELECT',
                    'table'    => $src['table'],
                    'colonnes' => $src['colonnes'],
                    'limite'   => 1000,
                ]);
                foreach ($ext['lignes'] as $l) {
                    $v = trim(implode(' ', array_filter(
                        array_map(fn($c) => self::valeur($l, $c), $src['colonnes']))));
                    if ($v === '') continue;
                    // Le filtre s'applique AUSSI aux valeurs externes. Sans
                    // cela, taper « so » laissait passer « Apave » : la liste
                    // ne se resserrait pas, et l'aide devenait du bruit.
                    if ($q !== '' && !str_contains(mb_strtolower($v), $q)) continue;
                    $vues[$v] = ($vues[$v] ?? 0);
                }
            } catch (\Throwable $e) {
                // Source indisponible : on garde les valeurs déjà saisies.
            }
        }

        foreach ($r['lignes'] as $l) {
            $v = trim((string)($l[$cle] ?? ''));
            if ($v === '') continue;
            // Le filtre porte sur le DÉBUT puis sur le contenu : taper « jea »
            // doit remonter « Jean » avant « Marie-Jeanne ».
            $bas = mb_strtolower($v);
            if ($q !== '' && !str_contains($bas, $q)) continue;
            $vues[$v] = ($vues[$v] ?? 0) + 1;
        }

        // Les valeurs les plus employées d'abord, puis l'ordre alphabétique :
        // sur un registre, ce qu'on saisit ressemble à ce qu'on a déjà saisi.
        uksort($vues, function ($a, $b) use ($vues, $q) {
            $da = ($q !== '' && str_starts_with(mb_strtolower($a), $q)) ? 0 : 1;
            $db = ($q !== '' && str_starts_with(mb_strtolower($b), $q)) ? 0 : 1;
            if ($da !== $db) return $da <=> $db;
            if ($vues[$a] !== $vues[$b]) return $vues[$b] <=> $vues[$a];
            return strnatcasecmp($a, $b);
        });

        return ['valeurs' => array_slice(array_keys($vues), 0, 20),
                'total'   => count($vues)];
    }

    /**
     * Ajoute une valeur à la liste gérée par l'administrateur.
     * Bornée à la catégorie déclarée par le champ : un module ne peut pas
     * enrichir une liste dont il n'a pas l'usage.
     */
    public function ajouterValeurListe(string $jeuNom, array $charge): array
    {
        $jeu   = $this->jeu($jeuNom);
        $cle   = (string)($charge['champ'] ?? '');
        $champ = $jeu['champs'][$cle] ?? null;

        if (!$champ || empty($champ['liste'])) {
            throw new ExtErreurUtilisateur('Ce champ n\'accepte pas l\'ajout de valeurs.');
        }

        // Enrichir la liste d'un champ suppose de pouvoir le REMPLIR : l'ajout
        // n'a de sens que depuis le formulaire qui le montre. Sans ce contrôle,
        // un rôle nourrissait une nomenclature d'administration depuis un écran
        // où le champ n'apparaît pas.
        $surfaces = $this->surfaces($jeuNom, 'creer')
                  ?: $this->surfaces($jeuNom, 'modifier');
        $permis   = $surfaces === [] ? [] : $this->champsSaisissables($surfaces);
        if ($surfaces === [] || ($permis !== null && !in_array($cle, $permis, true))) {
            throw new ExtErreurUtilisateur('Ce champ n\'est pas à votre saisie sur cet écran.');
        }

        $categorie = (string)$champ['liste'];

        /**
         * ⚠️ CE CONTRÔLE ÉTAIT TOUJOURS VRAI, DONC L'AJOUT TOUJOURS REFUSÉ.
         *
         * `ajout_autorise` est bien une propriété du CHAMP — deux champs
         * peuvent puiser dans la même catégorie sans avoir le même droit de
         * l'enrichir. Mais la validation ne laissait pas cette clé sur le
         * champ : elle la repliait sous `amorce.ajout`. Le moteur cherchait
         * donc une clé que le schéma venait de ranger ailleurs, et refusait
         * tout ajout sur un champ que la déclaration ouvrait explicitement.
         * Le schéma la conserve désormais ; le contrôle retrouve son sens.
         *
         * `amorce` reste, pour une autre question : comment naît la catégorie.
         */
        $amorce = is_array($champ['amorce'] ?? null) ? $champ['amorce'] : [];
        if (empty($champ['ajout_autorise']) && empty($amorce['ajout'])) {
            throw new ExtErreurUtilisateur('Ce champ n\'accepte pas l\'ajout de valeurs.');
        }

        $listes = ExtConfig::listes($this->identifiant, $this->declaration);
        $def    = $listes[$categorie] ?? null;

        $valeur = trim((string)($charge['valeur'] ?? ''));
        if ($valeur === '' || mb_strlen($valeur) > 60) {
            throw new ExtErreurUtilisateur('Valeur vide ou trop longue (60 caractères).');
        }

        // La valeur va dans le FICHIER, plus dans la table « Listes ». En
        // multi-tenant, elle est écrite dans la surcharge du client : une valeur
        // ajoutée chez l'un ne doit pas apparaître chez les autres.
        if (in_array($valeur, $def['valeurs'] ?? [], true)) {
            return ['ajoutee' => false, 'valeur' => $valeur];
        }

        if (!ExtConfig::ajouterValeur($this->identifiant, $this->declaration,
                                       $categorie, $valeur)) {
            // Écriture refusée : on le DIT. Rendre « ajoutée » sans avoir écrit
            // ferait apparaître la valeur à l'écran puis disparaître au
            // rechargement, sans que personne comprenne.
            throw new ExtErreurUtilisateur('Impossible d\'écrire la configuration du '
                . 'module. Vérifiez que « extensions/config/ » est accessible en '
                . 'écriture par le serveur web.');
        }

        ExtJournalSecurite::consigner(ExtJournalSecurite::AUTORISE, [
            'extension' => $this->identifiant,
            'operation_reelle' => 'liste.ajout',
            'table' => 'config/listes.json',
            'colonnes' => $categorie . ' = ' . $valeur,
            'gravite' => ExtJournalSecurite::NOTABLE,
        ]);

        return ['ajoutee' => true, 'valeur' => $valeur];
    }

    public function ancrage(array $charge): array
    {
        $i = (int)($charge['index'] ?? -1);
        $ancrages = $this->declaration['ancrages'] ?? [];
        if (!isset($ancrages[$i])) {
            throw new ExtErreurUtilisateur('Ancrage inconnu.');
        }
        $a = $ancrages[$i];

        // Un ancrage lit un jeu : il obéit au même partage que les écrans. Sans
        // cela, il devenait le contournement de la répartition par rôle —
        // refusée à la page, accordée à la tuile qui affiche les mêmes lignes.
        $this->lisibleSur((string)($a['source'] ?? ''));

        $ou = $a['filtre'];
        // Le contexte de la page (équipement affiché, étage courant) est la
        // SEULE valeur que le client peut injecter, et elle atterrit dans le
        // champ que la déclaration a désigné.
        if ($a['type'] === 'liste_liee' && isset($charge['valeur_lien'])) {
            $ou[$a['champ_lien']] = (int)$charge['valeur_lien'];
        }
        if ($a['type'] === 'marqueurs' && isset($charge['etage'])) {
            $ou[$a['champ_etage']] = (int)$charge['etage'];
        }

        $r = $this->securite->requeter([
            'action'   => $a['type'] === 'compteur' ? 'COUNT' : 'SELECT',
            'table'    => $this->table($a['source']),
            'colonnes' => ['*'],
            'ou'       => $ou,
            'limite'   => 500,
        ]);

        // Une jauge agrège un champ, là où un compteur compte des lignes.
        // « 12 contrôles » et « 5 400 € de contrôles » ne disent pas la même
        // chose, et l'un ne se déduit pas de l'autre.
        $valeur = $r['nombre'] ?? 0;
        if ($a['type'] === 'jauge') {
            $nombres = array_map('floatval', array_filter(
                array_column($r['lignes'] ?? [], $a['champ']), 'is_numeric'));
            $valeur = match ($a['agregat']) {
                'somme'   => array_sum($nombres),
                'moyenne' => $nombres ? array_sum($nombres) / count($nombres) : 0,
                'minimum' => $nombres ? min($nombres) : 0,
                'maximum' => $nombres ? max($nombres) : 0,
                default   => 0,
            };
            $valeur = round($valeur, 2);
        }

        return ['type' => $a['type'], 'libelle' => $a['libelle'],
                'nombre' => $valeur,
                'lignes' => $r['lignes'] ?? [], 'declaration' => $a];
    }

    public function exporter(string $jeuNom, array $charge): array
    {
        $jeu = $this->jeu($jeuNom);
        $this->autoriseeSur($jeuNom, 'exporter');
        $lignes = $this->lister($jeuNom, $charge)['lignes'];

        $entetes = array_map(fn($c) => $c['libelle'], $jeu['champs']);
        $csv = [implode(';', array_map(fn($e) => '"' . str_replace('"', '""', $e) . '"',
                                       $entetes))];
        foreach ($lignes as $l) {
            $csv[] = implode(';', array_map(
                fn($c) => '"' . str_replace('"', '""', (string)($l[$c] ?? '')) . '"',
                array_keys($jeu['champs'])));
        }
        return ['nom' => $jeuNom . '-' . date('Y-m-d') . '.csv',
                'contenu' => implode("\r\n", $csv)];
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Validation, dérivée de la déclaration
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Valide une saisie contre les champs déclarés.
     *
     * Les messages sont écrits pour l'utilisateur final, pas pour l'auteur du
     * module : c'est le moteur qui les produit, donc leur qualité ne dépend plus
     * du soin qu'un tiers y aura mis.
     */
    private function valider(array $jeu, array $saisie, string $jeuNom, int $idCourant): array
    {
        $out = [];
        foreach ($jeu['champs'] as $cle => $champ) {

            // Défaut dynamique : évalué à la création seulement. L'appliquer à
            // chaque modification écraserait une valeur que l'utilisateur a
            // délibérément vidée.
            if ($idCourant <= 0 && !array_key_exists($cle, $saisie)
                && isset($champ['defaut_arbre'])) {
                try {
                    $saisie[$cle] = ExtExpression::evaluer($champ['defaut_arbre'], [
                        'today' => date('Y-m-d'),
                        'user'  => ['nom' => $this->utilisateur['Login'] ?? ''],
                    ]);
                } catch (\Throwable $e) { /* défaut indisponible : champ vide */ }
            }

            // Un champ en lecture seule n'est pas modifiable par le formulaire.
            // Le masquer côté client suffirait à l'affichage, pas à la sécurité :
            // un appel direct enverrait la valeur quand même.
            if (!empty($champ['lecture_seule']) && $idCourant > 0) continue;

            // ── Mise à jour PARTIELLE ────────────────────────────────────
            //
            // ⚠️ UN CHAMP ABSENT DE LA SAISIE ÉTAIT ÉCRASÉ.
            // La boucle reconstruit tout le jeu de champs : ce qui n'était pas
            // envoyé repartait à null ou à son défaut. Un appel qui ne porte
            // qu'une valeur — un bouton « Accepter », un script — vidait donc
            // silencieusement toute la fiche.
            //
            // Le formulaire, lui, envoie TOUS ses champs, y compris ceux que
            // l'utilisateur a vidés : la condition ne change rien pour lui.
            // Elle protège les appels partiels, qui n'avaient aucune raison
            // d'être destructeurs.
            if ($idCourant > 0 && !array_key_exists($cle, $saisie)) continue;

            // Un champ calculé n'a pas de colonne : il ne s'écrit pas.
            // Sans cette ligne, l'écriture était refusée par la Security API
            // — « colonne inexistante » — ce qui était exact, mais le message
            // ne désignait pas la cause.
            if (($champ['type'] ?? '') === 'formule') continue;
            $brut = $saisie[$cle] ?? null;
            $lib  = $champ['libelle'];

            if ($champ['type'] === 'booleen') {
                $out[$cle] = !empty($brut) ? 1 : 0;
                continue;
            }

            $vide = $brut === null || (is_string($brut) && trim($brut) === '');
            if ($vide) {
                // ⚠️ LE DÉFAUT PASSE AVANT LE CONTRÔLE D'OBLIGATION.
                //
                // Il était consulté APRÈS : un champ obligatoire absent du
                // formulaire levait « est obligatoire » alors qu'il avait une
                // valeur par défaut parfaitement utilisable.
                //
                // C'est ce qui empêchait un module de proposer deux écrans au
                // même jeu de données — un formulaire court pour le demandeur,
                // complet pour le gestionnaire. Le statut « Demandé », pourtant
                // déclaré en défaut, faisait échouer toute création depuis le
                // formulaire court.
                //
                // « obligatoire » veut dire « doit avoir une valeur », pas
                // « doit être saisi » : un défaut en fournit une.
                $defaut = $champ['defaut'] ?? null;
                if ($defaut !== null && $defaut !== '') {
                    $out[$cle] = $defaut;
                    continue;
                }
                if ($champ['obligatoire']) {
                    throw new ExtErreurUtilisateur("« $lib » est obligatoire.");
                }
                $out[$cle] = null;
                continue;
            }

            switch ($champ['type']) {
                case 'entier':
                case 'decimal':
                    if (!is_numeric($brut)) {
                        throw new ExtErreurUtilisateur("« $lib » doit être un nombre.");
                    }
                    $v = $champ['type'] === 'entier' ? (int)$brut : (float)$brut;
                    if ($champ['min'] !== null && $v < $champ['min']) {
                        throw new ExtErreurUtilisateur("« $lib » doit valoir au moins "
                            . $champ['min'] . '.');
                    }
                    if ($champ['max'] !== null && $v > $champ['max']) {
                        throw new ExtErreurUtilisateur("« $lib » ne peut pas dépasser "
                            . $champ['max'] . '.');
                    }
                    $out[$cle] = $v;
                    break;

                case 'date':
                    $s = trim((string)$brut);
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
                        throw new ExtErreurUtilisateur("« $lib » doit être une date "
                            . '(AAAA-MM-JJ).');
                    }
                    // Bornes vérifiées APRÈS le format : comparer une saisie
                    // qui n'est pas une date n'a pas de sens, et le message
                    // porterait à côté.
                    // Bornes RELATIVES, en jours depuis aujourd'hui. Le
                    // navigateur les applique déjà en grisant son calendrier,
                    // mais un contrôle côté client ne protège de rien : un
                    // appel direct passerait outre.
                    foreach ([['min_jours', '<', 'ne peut pas être antérieure au'],
                              ['max_jours', '>', 'ne peut pas être postérieure au']]
                             as [$b, $op, $texte]) {
                        if (!isset($champ[$b]) || $champ[$b] === null) continue;
                        $borne = date('Y-m-d', strtotime('+' . (int)$champ[$b] . ' days'));
                        $cmp = strcmp($s, $borne);
                        if (($op === '<' && $cmp < 0) || ($op === '>' && $cmp > 0)) {
                            throw new ExtErreurUtilisateur("« $lib » $texte "
                                . date('d/m/Y', strtotime($borne)) . '.');
                        }
                    }
                    foreach ([['min_date', '<', 'ne peut pas être antérieure au'],
                              ['max_date', '>', 'ne peut pas être postérieure au']]
                             as [$b, $op, $texte]) {
                        if (empty($champ[$b])) continue;
                        $borne = $champ[$b] === 'today' ? date('Y-m-d') : $champ[$b];
                        $cmp = strcmp($s, $borne);
                        if (($op === '<' && $cmp < 0) || ($op === '>' && $cmp > 0)) {
                            throw new ExtErreurUtilisateur("« $lib » $texte "
                                . date('d/m/Y', strtotime($borne)) . '.');
                        }
                    }
                    $out[$cle] = $s;
                    break;

                case 'lien':
                    // Un lien est un identifiant, et il doit désigner quelque
                    // chose : une référence vers un enregistrement absent
                    // s'afficherait « Supprimé (#42) » dès la saisie, ce qui
                    // n'est pas une donnée mais une erreur.
                    $id = (int)$brut;
                    if ($id <= 0) {
                        if (!empty($champ['obligatoire'])) {
                            throw new ExtErreurUtilisateur("« $lib » est obligatoire.");
                        }
                        $out[$cle] = null;
                        break;
                    }
                    $existe = $this->securite->requeter([
                        '_intention' => 'lien.verification',
                        'action' => 'COUNT', 'table' => $champ['vers'],
                        'ou' => ['Id' => $id],
                    ])['nombre'] ?? 0;
                    if ($existe < 1) {
                        throw new ExtErreurUtilisateur("« $lib » : l'enregistrement "
                            . "sélectionné n'existe pas ou plus.");
                    }
                    $out[$cle] = $id;
                    break;

                case 'heure':
                    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)$brut)) {
                        throw new ExtErreurUtilisateur("« $lib » : heure attendue au format "
                            . 'HH:MM, par exemple 14:30.');
                    }
                    $out[$cle] = (string)$brut;
                    break;

                case 'horodatage':
                    $t = strtotime((string)$brut);
                    if ($t === false) {
                        throw new ExtErreurUtilisateur("« $lib » : date et heure invalides.");
                    }
                    $out[$cle] = date('Y-m-d H:i', $t);
                    break;

                case 'couleur':
                    if (!preg_match('/^#[0-9a-f]{6}$/i', (string)$brut)) {
                        throw new ExtErreurUtilisateur("« $lib » : couleur attendue au format "
                            . '#rrggbb.');
                    }
                    $out[$cle] = strtolower((string)$brut);
                    break;

                case 'note':
                    $n = (int)$brut;
                    if ($n < 0 || $n > 5) {
                        throw new ExtErreurUtilisateur("« $lib » : note de 0 à 5.");
                    }
                    $out[$cle] = $n;
                    break;

                case 'pourcentage':
                    $n = (float)str_replace(',', '.', (string)$brut);
                    if ($n < 0 || $n > 100) {
                        throw new ExtErreurUtilisateur("« $lib » : pourcentage de 0 à 100.");
                    }
                    $out[$cle] = $n;
                    break;

                case 'duree':
                    // Accepte « 90 », « 1h30 » ou « 1:30 » : trois écritures
                    // spontanées pour la même chose.
                    $v = trim((string)$brut);
                    if (preg_match('/^(\d{1,3})\s*[h:]\s*(\d{1,2})?$/i', $v, $m)) {
                        $out[$cle] = (int)$m[1] * 60 + (int)($m[2] ?? 0);
                    } elseif (ctype_digit($v)) {
                        $out[$cle] = (int)$v;
                    } else {
                        throw new ExtErreurUtilisateur("« $lib » : durée attendue en minutes "
                            . '(90) ou en heures (1h30).');
                    }
                    if ($out[$cle] > 100000) {
                        throw new ExtErreurUtilisateur("« $lib » : durée trop longue.");
                    }
                    break;

                case 'choix_multiple':
                    $choisis = is_array($brut) ? $brut : array_filter(explode(',', (string)$brut));
                    $valides = [];
                    foreach ($choisis as $x) {
                        $x = trim((string)$x);
                        if ($x === '') continue;
                        if (!in_array($x, $champ['valeurs'], true)) {
                            throw new ExtErreurUtilisateur("« $lib » : valeur « $x » inattendue. "
                                . 'Choix : ' . implode(', ', $champ['valeurs']) . '.');
                        }
                        $valides[] = $x;
                    }
                    // Stocké en texte séparé par des virgules : lisible dans un
                    // export, et suffisant pour une liste close et courte.
                    $out[$cle] = implode(', ', array_unique($valides));
                    break;

                case 'choix':
                    // Liste gérée par l'administrateur : les valeurs autorisées
                    // sont relues maintenant. Se fier à celles de la déclaration
                    // refuserait une valeur ajoutée depuis l'installation.
                    if (!empty($champ['liste'])) {
                        $r = $this->resoudreListes([$jeuNom => $jeu])[$jeuNom]['champs'][$cle];
                        $champ['valeurs'] = $r['valeurs'] ?? [];
                        // « Saisie libre » réglée dans Configuration : elle doit
                        // valoir aussi à l'écriture, sinon la case cochée ne
                        // change rien et l'utilisateur est refusé sans savoir
                        // pourquoi.
                        if (!empty($r['libre'])) $champ['libre'] = true;
                        // Liste vide : on accepte la saisie, sinon le champ
                        // serait inutilisable tant que l'administrateur n'a
                        // rien créé. Mais on la BORNE — la branche renvoyait la
                        // valeur brute sans limite, là où la branche « libre »
                        // juste en dessous coupe à 120. Même champ, même
                        // colonne, deux longueurs maximales selon que la liste
                        // était vide ou non : la colonne est déclarée à 120.
                        if ($champ['valeurs'] === []) {
                            $out[$cle] = mb_substr((string)$brut, 0, 120);
                            break;
                        }
                    }
                    if (!empty($champ['libre'])) {
                        // Valeur hors liste tolérée : la nomenclature n'est pas
                        // toujours complète le jour où l'on saisit.
                        $out[$cle] = mb_substr((string)$brut, 0, 120);
                        break;
                    }
                    if (!in_array($brut, $champ['valeurs'], true)) {
                        // ── Valeur héritée ───────────────────────────────
                        // La fiche portait déjà cette valeur, et la liste ne la
                        // propose plus — désactivée depuis, ou saisie librement
                        // avant que l'option soit retirée. On l'ACCEPTE telle
                        // quelle.
                        //
                        // Refuser bloquerait toute modification de la fiche :
                        // corriger une date d'échéance deviendrait impossible
                        // parce qu'une catégorie a été désactivée entre-temps.
                        // Et remplacer d'office par une valeur de la liste
                        // détruirait la donnée sans le dire.
                        //
                        // On tolère la CONSERVATION, jamais l'introduction :
                        // seule la valeur déjà enregistrée passe, et une valeur
                        // hors liste inventée est toujours refusée.
                        if ($idCourant > 0 && $brut === $this->valeurActuelle($jeuNom, $idCourant, $cle)) {
                            $out[$cle] = $brut;
                            break;
                        }
                        throw new ExtErreurUtilisateur("« $lib » : valeur inattendue. "
                            . 'Choix possibles : ' . implode(', ', $champ['valeurs']) . '.');
                    }
                    $out[$cle] = $brut;
                    break;

                default:
                    $s = trim((string)$brut);
                    if (mb_strlen($s) > $champ['max']) {
                        throw new ExtErreurUtilisateur("« $lib » dépasse "
                            . $champ['max'] . ' caractères.');
                    }
                    // Format de saisie : vérifié ICI, côté serveur. Le
                    // navigateur peut le signaler plus tôt, mais c'est un
                    // confort — un appel direct contournerait le formulaire.
                    if ($s !== '' && !empty($champ['format_saisie'])) {
                        $f = ExtSchemaDeclaratif::FORMATS_SAISIE[$champ['format_saisie']];
                        if (!preg_match($f['motif'], $s)) {
                            throw new ExtErreurUtilisateur("« $lib » : "
                                . mb_strtolower($f['libelle']) . ' attendu, par exemple « '
                                . $f['exemple'] . ' ».');
                        }
                    }
                    $out[$cle] = $s;
            }

            // Unicité : vérifiée par une requête, pas par une contrainte SQL —
            // le message reste ainsi compréhensible.
            if (!empty($champ['unique']) && $out[$cle] !== null) {
                $existants = $this->securite->requeter([
                    'action' => 'SELECT', 'table' => $this->table($jeuNom),
                    'colonnes' => ['id'], 'ou' => [$cle => $out[$cle]], 'limite' => 2,
                ]);
                foreach ($existants['lignes'] as $l) {
                    if ((int)$l['id'] !== $idCourant) {
                        throw new ExtErreurUtilisateur("« $lib » : la valeur « "
                            . $out[$cle] . ' » est déjà utilisée.');
                    }
                }
            }
        }
        return $out;
    }

    /** Tri choisi par l'utilisateur, s'il porte sur un champ existant. */
    private function triDemande(array $jeu, array $charge): array
    {
        $t = $charge['tri'] ?? null;
        if (!is_array($t) || $t === []) return [];
        $out = [];
        foreach ($t as $champ => $sens) {
            $champ = (string)$champ;
            if (!isset($jeu['champs'][$champ])) continue;
            if (($jeu['champs'][$champ]['type'] ?? '') === 'formule') continue;
            $out[$champ] = strtoupper((string)$sens) === 'DESC' ? 'DESC' : 'ASC';
        }
        return $out;
    }

    private function pageDe(string $jeuNom): array
    {
        foreach ($this->declaration['pages'] as $p) {
            if ($p['vue']['source'] === $jeuNom) return $p;
        }
        throw new ExtErreurUtilisateur('Aucune page ne présente ce jeu de données.');
    }

    /**
     * L'action est-elle déclarée par une page que CET utilisateur peut utiliser ?
     *
     * ⚠️ LA PREMIÈRE PAGE DÉCLARÉE DÉCIDAIT POUR TOUTES LES AUTRES.
     *
     * Le contrôle lisait `pageDe()`, qui rend la PREMIÈRE page dont la source
     * correspond. Un module qui présente le même jeu à deux publics — c'est le
     * cas courant : un écran pour le demandeur, un autre pour le gestionnaire —
     * voyait donc les actions du premier écran s'appliquer au second.
     *
     * Concrètement, dans « larka.recharge » : la page du demandeur ne déclare
     * que « supprimer », celle du gestionnaire déclare « exporter ». L'export
     * était refusé au gestionnaire, sur un écran qui l'affiche, parce qu'une
     * autre page — la sienne, mais pas celle-là — se trouvait déclarée avant.
     * Aucun réglage ne permettait de s'en sortir : il fallait réordonner le
     * JSON.
     *
     * On considère désormais TOUTES les pages qui servent ce jeu, et l'on ne
     * retient que celles dont le rôle de l'utilisateur fait partie. C'est plus
     * juste dans les deux sens :
     *   — le gestionnaire retrouve les actions de SA page ;
     *   — le demandeur n'hérite pas de celles de la page du gestionnaire,
     *     ce qu'un simple « une page quelconque suffit » lui aurait donné.
     *
     * Le rôle était ignoré ici ; l'ignorer revenait à faire dépendre une
     * frontière d'autorisation de l'ordre d'écriture d'un fichier.
     */
    private function autoriseeSur(string $jeuNom, string $action): void
    {
        if ($this->surfaces($jeuNom, $action) !== []) return;

        throw new ExtErreurUtilisateur('Cette opération n\'est pas prévue par le module.');
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     *  LES SURFACES : ce que CET utilisateur peut atteindre sur ce jeu
     * ═══════════════════════════════════════════════════════════════════════
     *
     * Une « surface » est un endroit déclaré par lequel un rôle touche un jeu de
     * données : une page, ou un ancrage de type « formulaire ». C'est la seule
     * notion d'autorisation du format — l'auteur ne déclare rien d'autre — et
     * c'est donc elle qui doit décider, partout.
     *
     * ⚠️ ELLE NE DÉCIDAIT QUE POUR « supprimer » ET « exporter ».
     *
     * `lister`, `enregistrer`, `cibles`, `suggestions`, `ancrage` et l'ajout de
     * valeurs de liste ne la consultaient pas. Le seul contrôle qu'ils
     * subissaient était celui du REGISTRE, qui porte sur le MODULE entier. Un
     * module à deux écrans — le cas courant : un pour le demandeur, un pour le
     * gestionnaire — n'avait donc plus qu'une frontière décorative :
     *
     *   • un demandeur lisait le jeu réservé à la page du gestionnaire ;
     *   • il y écrivait, alors que sa page ne déclare pas « creer » ;
     *   • il modifiait la fiche d'autrui, alors qu'elle ne déclare pas
     *     « modifier » ;
     *   • il renseignait « Décision : validé », un champ que sa page n'affiche
     *     pas — il s'accordait lui-même ce qu'il demandait.
     *
     * Les quatre étaient vérifiés sur un module livré. La mise en page rendait
     * le dernier plus visible encore : elle choisit les champs du formulaire,
     * donc le client n'en montrait que cinq — et le serveur en acceptait douze.
     *
     * @param  ?string $action null = simple lecture : la page suffit, sans
     *                         qu'elle ait à déclarer une action.
     * @return array<int,array> pages (ou pages d'ancrage) atteignables
     */
    private function surfaces(string $jeuNom, ?string $action = null): array
    {
        $role = (string)($this->utilisateur['Role'] ?? '');
        $out  = [];

        foreach ($this->declaration['pages'] ?? [] as $p) {
            if (($p['vue']['source'] ?? null) !== $jeuNom) continue;
            // Une page sans rôle déclaré n'existe pas : validerPage() en pose
            // un par défaut. On compare donc franchement.
            if (!in_array($role, $p['roles'] ?? [], true)) continue;
            if ($action !== null
                && !in_array($action, $p['vue']['actions'] ?? [], true)) continue;
            $out[] = $p;
        }

        // Un ancrage « formulaire » est une surface de CRÉATION à part entière :
        // il porte le formulaire d'une page nommée, à l'endroit prévu par Larka.
        // C'est ainsi que le demandeur de « larka.recharge » réserve un créneau,
        // sur une page qui ne déclare que « supprimer ». L'ignorer ici reviendrait
        // à casser le module en croyant le protéger.
        //
        // Il ne donne que la création : modifier une fiche existante suppose de
        // l'avoir sous les yeux, donc une page qui le déclare.
        if ($action === null || $action === 'creer') {
            foreach ($this->declaration['ancrages'] ?? [] as $a) {
                if (($a['type'] ?? '') !== 'formulaire') continue;
                if (($a['source'] ?? null) !== $jeuNom) continue;
                $page = $this->pageCle((string)($a['page'] ?? ''));
                if ($page === null) continue;
                if (!in_array($role, $page['roles'] ?? [], true)) continue;
                $out[] = $page;
            }
        }
        return $out;
    }

    /**
     * Lecture d'un jeu : ce rôle dispose-t-il d'une page qui le présente ?
     *
     * Un jeu sans aucune page n'est atteint que par un ancrage, dont la
     * visibilité relève du module : on laisse alors passer, comme avant. Sinon,
     * la répartition déclarée par l'auteur s'applique — c'est tout l'objet des
     * rôles page par page.
     */
    private function lisibleSur(string $jeuNom): void
    {
        $avecPage = false;
        foreach ($this->declaration['pages'] ?? [] as $p) {
            if (($p['vue']['source'] ?? null) === $jeuNom) { $avecPage = true; break; }
        }
        if (!$avecPage) return;
        if ($this->surfaces($jeuNom) !== []) return;

        throw new ExtErreurUtilisateur('Cet écran ne vous est pas destiné.');
    }

    /** Page d'après sa clé. */
    private function pageCle(string $cle): ?array
    {
        foreach ($this->declaration['pages'] ?? [] as $p) {
            if ((string)($p['cle'] ?? '') === $cle) return $p;
        }
        return null;
    }

    /**
     * Champs qu'une surface expose réellement à la saisie.
     *
     * La mise en page CHOISIT les champs du formulaire — c'est écrit dans le
     * rendu client : « ce qui permet au demandeur de créer une fiche dont le
     * statut est Demandé sans jamais voir ce champ ». Le serveur ne le
     * vérifiait pas : la restriction était donc un habillage, et il suffisait
     * d'envoyer le champ absent pour l'écrire.
     *
     * @return ?array<int,string> null = aucune restriction (la surface montre
     *         tout : c'est le cas de tous les modules écrits avant la mise en
     *         page, et leur comportement ne change pas)
     */
    private static function champsExposes(array $page): ?array
    {
        $vue = $page['vue'] ?? [];
        if (!empty($vue['layout'])) {
            $out = [];
            $parcourir = static function (array $n) use (&$parcourir, &$out): void {
                foreach ($n['composants'] ?? [] as $c) {
                    if (isset($c['champ'])) $out[] = (string)$c['champ'];
                    if (isset($c['composants'])) $parcourir($c);
                }
            };
            $parcourir($vue['layout']);
            return $out;
        }
        if (!empty($vue['champs_formulaire']) && is_array($vue['champs_formulaire'])) {
            return array_map('strval', $vue['champs_formulaire']);
        }
        return null;
    }

    /**
     * Union des champs exposés par les surfaces atteignables.
     * Si l'une d'elles ne restreint rien, il n'y a rien à restreindre : un écran
     * qui montre tout autorise tout, et deux écrans ne se retirent pas des
     * droits l'un à l'autre.
     */
    private function champsSaisissables(array $surfaces): ?array
    {
        $union = [];
        foreach ($surfaces as $p) {
            $exposes = self::champsExposes($p);
            if ($exposes === null) return null;
            foreach ($exposes as $c) $union[$c] = true;
        }
        return array_keys($union);
    }

    /**
     * Retire d'une saisie les champs qu'aucune surface de cet utilisateur ne
     * montre. On ÉCARTE plutôt qu'on ne refuse : c'est déjà le traitement des
     * champs inconnus dans valider(), un client honnête ne les envoie pas, et
     * refuser transformerait une valeur surnuméraire en panne d'écran.
     *
     * Écarter suffit d'ailleurs à fermer la porte : à la création le champ
     * reprend son défaut déclaré — « Demandé » —, à la modification il garde sa
     * valeur, puisque l'UPDATE ne touche que les colonnes fournies.
     *
     * La tentative, elle, est TRACÉE : une saisie qui porte un champ absent du
     * formulaire n'est pas une maladresse d'utilisateur.
     */
    private function ecarterChampsNonExposes(array $saisie, array $surfaces,
                                             string $jeuNom): array
    {
        $permis = $this->champsSaisissables($surfaces);
        if ($permis === null) return $saisie;

        $ecartes = array_values(array_diff(array_keys($saisie), $permis));
        if ($ecartes === []) return $saisie;

        ExtJournalSecurite::consigner(ExtJournalSecurite::REFUSE, [
            'extension'          => $this->identifiant,
            'operation_declaree' => $jeuNom . '.enregistrement',
            'operation_reelle'   => 'declaratif.champ_hors_formulaire',
            'table'              => $jeuNom,
            'colonnes'           => implode(',', $ecartes),
            'motif'              => 'champ_absent_de_la_surface_de_cet_utilisateur',
            'utilisateur'        => (string)($this->utilisateur['Login'] ?? '?'),
            'gravite'            => ExtJournalSecurite::NOTABLE,
        ]);

        return array_intersect_key($saisie, array_flip($permis));
    }

    /**
     * Crée les dossiers de fichiers déclarés par le module.
     *
     * ⚠️ CETTE MÉTHODE N'ÉTAIT APPELÉE DE NULLE PART.
     * Le manifeste pouvait déclarer « fichiers », le schéma le validait, la
     * capacité apparaissait à l'installation — et aucun dossier n'était créé.
     * Un module qui livre des icônes n'avait donc nulle part où les ranger, et
     * l'écran d'installation promettait un espace qui n'existait pas. Une
     * capacité accordée sans effet est pire qu'une capacité absente.
     */
    private function preparerFichiers(): void
    {
        $declares = $this->declaration['fichiers'] ?? [];
        if ($declares === []) return;
        try {
            ExtFichiers::preparer($this->identifiant, $declares);
        } catch (\Throwable $e) {
            // Un dossier non créé ne doit pas empêcher l'installation du
            // module : ses données restent utilisables. On trace, on continue.
            ExtJournalSecurite::consigner(ExtJournalSecurite::AUTORISE, [
                'extension' => $this->identifiant,
                'operation_reelle' => 'fichiers.dossier',
                'table' => '-', 'colonnes' => 'création impossible : ' . $e->getMessage(),
                'gravite' => ExtJournalSecurite::NOTABLE,
            ]);
        }
    }

    /** Ce que le client a besoin de connaître pour dessiner les écrans. */
    /**
     * Crée extensions/config/<identifiant>/ et y dépose listes.json.
     *
     * Remplace amorcerListes(), qui insérait les valeurs dans la table
     * « Listes » du cœur. La nomenclature d'un module n'y était ni versionnable
     * ni livrable — impossible de préparer celle d'un client à l'avance, ou de
     * la retrouver après réinstallation, puisqu'elle vivait en base. Et l'écran
     * Configuration → Listes se remplissait de catégories qui ne relevaient pas
     * de Larka.
     *
     * Le fichier n'est jamais écrasé ; seules les listes qu'une mise à jour
     * déclare en plus y sont ajoutées.
     */
    private function preparerConfig(): void
    {
        if (empty($this->declaration['donnees'])) return;
        try {
            ExtConfig::initialiser($this->identifiant, $this->declaration);
        } catch (\Throwable $e) {
            // Un fichier non écrit ne doit pas empêcher l'installation : le
            // module retombe sur les valeurs de sa déclaration, qui sont
            // exactement celles qu'on aurait écrites.
            ExtJournalSecurite::consigner(ExtJournalSecurite::AUTORISE, [
                'extension' => $this->identifiant,
                'operation_reelle' => 'config.fichier',
                'table' => '-', 'colonnes' => 'écriture impossible : ' . $e->getMessage(),
                'gravite' => ExtJournalSecurite::NOTABLE,
            ]);
        }
    }

    /**
     * Résout les listes d'un module depuis extensions/config/<id>/listes.json.
     *
     * LA SOURCE EST LE FICHIER, plus la table « Listes » du cœur.
     *
     * Les valeurs y vivaient mélangées à celles de Larka : ni versionnables,
     * ni livrables, et impossibles à retrouver après une réinstallation. Elles
     * encombraient au passage l'écran Configuration → Listes avec des
     * catégories appartenant à des modules — ce que l'écran n'a pas à porter.
     *
     * Le fichier est relu à CHAQUE affichage : éditer listes.json rend la
     * nouvelle valeur disponible immédiatement, sans republier le module ni
     * redémarrer quoi que ce soit.
     *
     * Fichier absent ou illisible : on retombe sur les valeurs déclarées par le
     * module. Elles sont exactement celles qu'on y aurait écrites, donc l'écran
     * reste utilisable — une configuration manquante ne doit pas vider une liste.
     */
    private function resoudreListes(array $donnees): array
    {
        // Seules les listes OUVERTES sont concernées. Une liste verrouillée
        // porte ses valeurs dans la déclaration et n'a pas de catégorie.
        $categories = [];
        foreach ($donnees as $jeu) {
            foreach ($jeu['champs'] as $c) {
                if (!empty($c['liste'])) $categories[$c['liste']] = true;
            }
        }
        if ($categories === []) return $donnees;

        $listes = [];
        try {
            $listes = ExtConfig::listes($this->identifiant, $this->declaration);
        } catch (\Throwable $e) { /* config illisible : valeurs déclarées */ }

        foreach ($donnees as $n => $jeu) {
            foreach ($jeu['champs'] as $cle => $c) {
                if (empty($c['liste'])) continue;
                $cat = (string)$c['liste'];
                $def = $listes[$cat] ?? null;

                $donnees[$n]['champs'][$cle]['valeurs'] = is_array($def['valeurs'] ?? null)
                    ? array_values(array_map('strval', $def['valeurs']))
                    : ($c['valeurs'] ?? []);

                // « obligatoire » reste cumulatif : un champ que le module
                // déclare obligatoire le reste, car c'est une contrainte de ses
                // données et non une préférence d'affichage. Le fichier peut
                // l'ajouter, pas le retirer.
                $donnees[$n]['champs'][$cle]['obligatoire']
                    = !empty($def['obligatoire'])
                   || !empty($donnees[$n]['champs'][$cle]['obligatoire']);

                /**
                 * ── Saisie libre : au fichier, mais pas au prix du champ ─────
                 *
                 * « libre » appartient au fichier, donc à l'administrateur —
                 * c'est la règle, et elle est bonne. Mais elle était appliquée
                 * même quand le fichier ne dit RIEN : `!empty(null['libre'])`
                 * vaut faux, et un champ déclaré libre par son module perdait
                 * la saisie libre tant qu'aucune configuration n'avait été
                 * écrite. Une valeur absente n'est pas une valeur à faux.
                 *
                 * On ne retombe sur la déclaration que faute de définition.
                 *
                 * « ajout_autorise » n'est PAS touché ici : il appartient au
                 * champ (deux champs, une même catégorie, deux droits), et
                 * l'écraser par une valeur de catégorie les confondrait.
                 */
                $donnees[$n]['champs'][$cle]['libre'] = $def !== null
                    ? !empty($def['libre'])
                    : !empty($c['libre']);
            }
        }
        return $donnees;
    }

    /**
     * Valeurs courantes des réglages : celles posées par l'administrateur, et à
     * défaut celles déclarées par le module.
     */
    /**
     * Réglages tels que l'écran doit les proposer.
     *
     * Un réglage « langue » n'a pas de valeurs dans la déclaration : elles
     * dépendent des traductions fournies. On les compose ici, plutôt que
     * d'obliger l'auteur à répéter la liste — deux listes divergeraient.
     */
    public function reglagesAffichables(): array
    {
        $out = $this->declaration['reglages'] ?? [];
        foreach ($out as $cle => $r) {
            if (($r['applique'] ?? '') !== 'langue') continue;
            $codes = array_merge(['fr'], array_keys($this->declaration['langues'] ?? []));
            $out[$cle]['valeurs'] = array_values(array_unique($codes));
        }
        return $out;
    }

    /** Réglages RÉELLEMENT posés par l'administrateur, sans les défauts. */
    private function reglagesPoses(): array
    {
        $f = dirname(__DIR__, 3) . '/data/extensions/' . $this->identifiant . '.reglages.json';
        if (!is_file($f)) return [];
        $j = json_decode((string)@file_get_contents($f), true);
        return is_array($j) ? $j : [];
    }

    /**
     * Valeur courante de chaque réglage, par ordre de précédence :
     *
     *   1. ce que l'administrateur a posé dans l'écran
     *   2. extensions/config/<identifiant>.json          (déposé à la main)
     *   3. le « defaut » déclaré au manifeste
     *
     * Le fichier déposé fournit donc des DÉFAUTS, et l'écran l'emporte sur lui.
     * L'inverse donnerait un réglage qu'on modifie sans effet et sans
     * explication.
     */
    public function reglages(): array
    {
        $declares = $this->declaration['reglages'] ?? [];
        if ($declares === []) return [];

        $poses = [];
        $f = dirname(__DIR__, 3) . '/data/extensions/' . $this->identifiant . '.reglages.json';
        if (is_file($f)) {
            $j = json_decode((string)@file_get_contents($f), true);
            if (is_array($j)) $poses = $j;
        }

        $deposee = ExtPaquet::configDeposee($this->identifiant);

        $out = [];
        foreach ($declares as $cle => $r) {
            if (array_key_exists($cle, $poses))    { $out[$cle] = $poses[$cle];   continue; }
            if (array_key_exists($cle, $deposee))  { $out[$cle] = $deposee[$cle]; continue; }
            $out[$cle] = $r['defaut'] ?? null;
        }
        return $out;
    }

    /**
     * Enregistre les réglages, en refusant ce qui ne correspond pas au type
     * déclaré. Un réglage est saisi par un administrateur, pas par le module —
     * mais il transite par une requête, et rien ne se valide tout seul.
     */
    public function definirReglages(array $valeurs): array
    {
        $declares = $this->declaration['reglages'] ?? [];
        $out = [];
        foreach ($declares as $cle => $r) {
            if (!array_key_exists($cle, $valeurs)) continue;
            $v = $valeurs[$cle];
            $lib = $r['libelle'];
            switch ($r['type']) {
                case 'entier':  $out[$cle] = (int)$v; break;
                case 'decimal': $out[$cle] = (float)str_replace(',', '.', (string)$v); break;
                case 'booleen': $out[$cle] = (bool)$v; break;
                case 'couleur':
                    if (!preg_match('/^#[0-9a-f]{6}$/i', (string)$v)) {
                        throw new ExtErreurUtilisateur("« $lib » : couleur attendue (#rrggbb).");
                    }
                    $out[$cle] = strtolower((string)$v);
                    break;
                case 'choix':
                    if (($r['applique'] ?? '') === 'langue') {
                        $codes = array_merge(['fr'],
                            array_keys($this->declaration['langues'] ?? []));
                        if (!in_array((string)$v, $codes, true)) {
                            throw new ExtErreurUtilisateur("« $lib » : langue inconnue. "
                                . 'Langues : ' . implode(', ', $codes) . '.');
                        }
                        $out[$cle] = (string)$v;
                        break;
                    }
                    if (!in_array((string)$v, $r['valeurs'], true)) {
                        throw new ExtErreurUtilisateur("« $lib » : valeur inattendue. "
                            . 'Choix : ' . implode(', ', $r['valeurs']) . '.');
                    }
                    $out[$cle] = (string)$v;
                    break;
                case 'date':
                    if ((string)$v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v)) {
                        throw new ExtErreurUtilisateur("« $lib » : date attendue (AAAA-MM-JJ).");
                    }
                    $out[$cle] = (string)$v;
                    break;
                default:        $out[$cle] = mb_substr((string)$v, 0, 500);
            }
        }

        $dossier = dirname(__DIR__, 3) . '/data/extensions';
        if (!is_dir($dossier)) @mkdir($dossier, 0750, true);
        @file_put_contents($dossier . '/' . $this->identifiant . '.reglages.json',
            json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return $out;
    }

    /**
     * Applique les traductions du module à ses libellés.
     *
     * Seuls les LIBELLÉS changent. Les noms techniques restent intacts : ils
     * identifient les colonnes en base, et les traduire ferait dépendre le
     * schéma de la langue du navigateur.
     */
    private function traduire(array $decl, string $langue): array
    {
        $t = $decl['langues'][$langue] ?? null;
        if ($t === null) {
            // « fr-BE » retombe sur « fr » : une traduction régionale absente ne
            // doit pas renvoyer l'utilisateur à la langue d'origine.
            $court = explode('-', $langue)[0];
            $t = $decl['langues'][$court] ?? null;
        }
        if (!is_array($t) || $t === []) return $decl;

        $tr = fn(?string $v) => ($v !== null && isset($t[$v])) ? $t[$v] : $v;

        foreach ($decl['donnees'] as $jn => $jeu) {
            $decl['donnees'][$jn]['libelle'] = $tr($jeu['libelle']);
            foreach ($jeu['champs'] as $cn => $c) {
                $decl['donnees'][$jn]['champs'][$cn]['libelle'] = $tr($c['libelle'] ?? null);
                if (!empty($c['aide'])) {
                    $decl['donnees'][$jn]['champs'][$cn]['aide'] = $tr($c['aide']);
                }
                if (!empty($c['groupe'])) {
                    $decl['donnees'][$jn]['champs'][$cn]['groupe'] = $tr($c['groupe']);
                }
            }
        }
        foreach ($decl['pages'] as $i => $p) {
            $decl['pages'][$i]['titre'] = $tr($p['titre']);
        }
        return $decl;
    }

    public function descriptionClient(): array
    {
        // Langue : le réglage du module l'emporte sur celle de l'utilisateur.
        // C'est l'administrateur qui décide dans quelle langue SON module
        // s'affiche — sans quoi le choix n'était nulle part, et les traductions
        // déclarées restaient inaccessibles.
        $reglages = $this->reglages();
        $langue = null;
        foreach ($this->declaration['reglages'] ?? [] as $cle => $r) {
            if (($r['applique'] ?? '') !== 'langue') continue;
            // Seule une valeur POSÉE par l'administrateur l'emporte. Le défaut
            // déclaré ne compte pas : à « fr », il écrasait la langue de
            // l'utilisateur et rendait toutes les traductions inaccessibles.
            $pose = $this->reglagesPoses()[$cle] ?? null;
            if ($pose !== null && $pose !== '') $langue = (string)$pose;
        }
        $langue ??= (string)($this->utilisateur['Langue'] ?? $this->utilisateur['langue'] ?? 'fr');
        $decl = $this->traduire($this->declaration, $langue);

        return [
            'identifiant' => $this->identifiant,
            // Nom du module : l'écran de réglages l'affiche en titre. Sans lui,
            // la modale s'intitulait « Réglages — larka.formations ».
            'nom'         => $this->declaration['nom'] ?? $this->identifiant,
            'reglages'    => $this->reglages(),
            // La DÉCLARATION des réglages, pas seulement leurs valeurs : le
            // client a besoin de savoir ce que chacun pilote.
            'declaration_reglages' => $this->reglagesAffichables(),
            'themes'      => ExtSchemaDeclaratif::THEMES,
            'vocabulaire' => $this->declaration['vocabulaire'] ?? [],
            'variables'   => $this->declaration['variables'] ?? [],
            // Les traductions elles-mêmes : un module de langue n'a rien
            // d'autre à transmettre, et sans elles il arrivait vide.
            'langues'     => $this->declaration['langues'] ?? [],
            'langue'      => $langue,
            'donnees'     => $this->resoudreListes($decl['donnees']),
            'pages'       => $decl['pages'],
            'ancrages'    => $this->declaration['ancrages'] ?? [],
            // Les dossiers de fichiers : sans eux, le client ne peut pas savoir
            // qu'il y a une zone de dépôt à dessiner.
            'fichiers'    => $this->declaration['fichiers'] ?? [],
            'calculs'     => $this->declaration['calculs'] ?? [],
            'partage'     => $this->declaration['partage'] ?? [],
        ];
    }
}

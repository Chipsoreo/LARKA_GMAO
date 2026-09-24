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
 * Larka — Extensions : catalogue des capacités
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Une extension ne « peut » rien par défaut. Elle DÉCLARE dans son manifeste la
 * liste des capacités dont elle a besoin ; l'administrateur les voit une par une
 * au moment de l'installation, en français, avec leur niveau de risque, et c'est
 * lui qui accepte. Tout appel au Contexte vérifie la capacité correspondante.
 *
 * C'est le vocabulaire commun de toute la couche : le manifeste les déclare,
 * l'écran d'installation les affiche, le Contexte les fait respecter, le Journal
 * les trace. Ajouter une capacité = ajouter une entrée ici, et un seul point de
 * contrôle dans Contexte.php.
 *
 * NIVEAUX DE RISQUE
 *   faible    → ne sort pas de l'extension, aucune donnée métier lue.
 *   moyen     → lit des données métier non nominatives.
 *   eleve     → écrit des données métier, ou lit des données nominatives (RGPD),
 *               ou sort sur le réseau.
 *   critique  → contournerait le contrôle (SQL libre, accès brut au cœur).
 *               Plus aucune capacité ne porte ce niveau : il subsiste comme
 *               valeur par défaut d'une capacité INCONNUE, pour qu'un nom mal
 *               orthographié soit présenté à l'administrateur au pire niveau
 *               plutôt qu'au meilleur.
 * ═══════════════════════════════════════════════════════════════════════════════
 */

final class ExtCapacites
{
    /**
     * Version de l'API d'extensions, INDÉPENDANTE de LARKA_VERSION.
     *
     * Pourquoi une version à part : la version applicative bouge pour des
     * raisons qui ne concernent pas les extensions (correctif d'affichage,
     * nouveau module métier…), et l'inverse est vrai aussi. Un auteur a besoin
     * de savoir « quelles capacités puis-je demander », pas « quelle est la
     * version du produit ».
     *
     * À INCRÉMENTER dès qu'on ajoute une capacité, un hook ou un ancrage, et à
     * reporter dans le champ « depuis » de l'entrée ajoutée. Sans cela, une
     * extension écrite pour une version récente échoue sur une installation
     * plus ancienne avec un message qui accuse une faute de frappe — alors que
     * le manifeste est parfaitement correct.
     *
     *   1.0  couche initiale : domaines métier, ui.page, hooks, réseau, stockage
     *   1.1  domaine « plans » (lecture) + ui.ancrage + ancrages
     *   1.2  Security API : requêtes structurées, tables privées.
     *        RETRAIT de donnees.sql_lecture — le SQL brut n'existe plus.
     */
    public const VERSION_API = '1.3';

    public const RISQUE_FAIBLE   = 'faible';
    public const RISQUE_MOYEN    = 'moyen';
    public const RISQUE_ELEVE    = 'eleve';
    public const RISQUE_CRITIQUE = 'critique';

    /**
     * Domaines métier exposés en lecture/écriture par le Contexte.
     * La clé est le nom utilisé dans les capacités (donnees.lire:biens).
     * 'nominatif' marque les domaines contenant des données personnelles :
     * l'écran d'installation ajoute alors un avertissement RGPD explicite.
     */
    /**
     * Domaines du cœur qu'un module peut LIRE, via un champ « lien ».
     *
     * La liste comptait onze entrées, dont sept qu'aucune déclaration ne pouvait
     * atteindre — et une capacité d'ÉCRITURE sur cinq d'entre elles. Un pack de
     * données ne modifie jamais les données de Larka : il tient les siennes et
     * peut désigner celles du cœur.
     *
     * Un catalogue qui annonce des pouvoirs impossibles n'est pas prudent :
     * il inquiète l'administrateur pour rien, et masque les vraies permissions
     * dans une liste qu'il cesse de lire.
     *
     * Cette liste suit exactement ExtSchemaDeclaratif::CIBLES_LIEN. L'invariant
     * « catalogues alignés » vérifie qu'elles ne divergent pas.
     */
    public const DOMAINES = [
        'biens'         => ['libelle' => 'Biens immobiliers', 'nominatif' => false,
                            'lecture_seule' => true],
        'equipements'   => ['libelle' => 'Équipements',       'nominatif' => false,
                            'lecture_seule' => true],
        'interventions' => ['libelle' => 'Interventions',     'nominatif' => false,
                            'lecture_seule' => true],
        'contrats'      => ['libelle' => 'Contrats',          'nominatif' => false,
                            'lecture_seule' => true],
        'stock'         => ['libelle' => 'Stock et pièces',   'nominatif' => false,
                            'lecture_seule' => true],
        // Projection fixe servie par le cœur : prénom et nom des comptes
        // actifs. Pas un accès à la table des utilisateurs.
        'noms'          => ['libelle' => 'Noms des personnes', 'nominatif' => true,
                            'lecture_seule' => true],

        'documents'     => ['libelle' => 'Documents joints',  'nominatif' => false,
                            'lecture_seule' => true],
        'notesinfo'     => ['libelle' => 'Notes d\'information', 'nominatif' => false,
                            'lecture_seule' => true],
        'listes'        => ['libelle' => 'Valeurs de listes', 'nominatif' => false,
                            'lecture_seule' => true],
    ];

    /** Capacités hors domaines métier. */
    private const AUTRES = [
        'ui.page' => [
            'libelle' => 'Ajouter une page à l\'application',
            'detail'  => 'L\'extension apparaît dans le menu latéral et affiche son propre écran.',
            'risque'  => self::RISQUE_FAIBLE,
        ],
        'ui.ancrage' => [
            'depuis'  => '1.1',
            'libelle' => 'Ajouter des boutons dans les pages existantes',
            'detail'  => 'L\'extension s\'insère dans des emplacements prévus par Larka '
                       . '(barre d\'outils des plans, fiche équipement…). '
                       . '⚠️ Contrairement à sa page, ce code s\'exécute DANS la page de Larka, '
                       . 'sans isolement : il y a accès au contenu affiché et à la session de '
                       . 'l\'utilisateur. À n\'accorder qu\'à un module dont vous connaissez '
                       . 'l\'auteur.',
            'risque'  => self::RISQUE_ELEVE,
        ],
        'fichiers.dossier' => [
            'depuis'  => '1.3',
            'libelle' => 'Détenir des fichiers dans un dossier à lui',
            'detail'  => 'Le module dispose d\'un dossier sous data/, hors de la racine web. '
                       . 'Il y trouve les fichiers livrés avec le paquet (icônes, thèmes, '
                       . 'traductions). Un module n\'exécute aucun code : il ne peut pas y '
                       . 'écrire de lui-même.',
            'risque'  => self::RISQUE_FAIBLE,
        ],
        'fichiers.import' => [
            'depuis'  => '1.3',
            'libelle' => 'Laisser les utilisateurs déposer des fichiers',
            'detail'  => 'Une zone de dépôt apparaît sur l\'écran du module. Le type de '
                       . 'chaque fichier est vérifié sur ses octets, pas sur son nom, et '
                       . 'les fichiers ne sont jamais interprétés — seulement téléchargés.',
            'risque'  => self::RISQUE_MOYEN,
        ],
        'ui.retouche' => [
            'depuis'  => '1.3',
            'libelle' => 'Masquer ou renommer des entrées de menu',
            'detail'  => 'Le module adapte le menu : masquer un écran inutilisé, le '
                       . 'renommer, ou le faire pointer vers l\'un des siens. '
                       . '⚠️ C\'est la seule permission qui RETIRE quelque chose de '
                       . 'l\'interface. Le masquage est visuel : l\'écran reste joignable '
                       . 'et les droits sont inchangés. Le journal, les extensions, la '
                       . 'configuration et les comptes ne sont jamais retouchables.',
            'risque'  => self::RISQUE_MOYEN,
        ],
        'donnees.table_privee' => [
            'depuis'  => '1.2',
            'libelle' => 'Créer et utiliser ses propres tables',
            'detail'  => 'Le module dispose de tables à lui, nommées ext_<identifiant>_… '
                       . 'Il ne peut ni les partager, ni atteindre celles d\'un autre module, '
                       . 'ni toucher aux tables du cœur.',
            'risque'  => self::RISQUE_MOYEN,
        ],
    ];

    /** Toutes les capacités connues : identifiant → métadonnées. */
    /**
     * Catalogue adapté au FORMAT du module.
     *
     * Un même nom de capacité n'a pas la même portée selon qu'un module fournit
     * du code ou une déclaration. « ui.ancrage » laisse s'exécuter du JavaScript
     * dans la page pour un module à code — risque élevé — alors qu'un pack de
     * données ne fait que paramétrer une primitive que Larka dessine. Afficher
     * le même avertissement dans les deux cas serait faux une fois sur deux.
     */
    public static function catalogueSelonFormat(bool $declaratif): array
    {
        $cat = self::catalogue();
        if (!$declaratif) return $cat;

        $cat['ui.ancrage'] = [
            'depuis'  => '1.1',
            'libelle' => 'Afficher des encarts dans les pages existantes',
            'detail'  => 'Le module demande une primitive (tuile, repères, liste liée) '
                       . 'que LARKA dessine elle-même, aux emplacements prévus. Aucun code '
                       . 'du module ne s\'exécute dans la page.',
            'risque'  => self::RISQUE_MOYEN,
        ];
        return $cat;
    }

    public static function catalogue(): array
    {
        static $cat = null;
        if ($cat !== null) return $cat;

        $cat = self::AUTRES;
        foreach (self::DOMAINES as $cle => $d) {
            $depuis = $d['depuis'] ?? '1.0';
            $cat['donnees.lire:' . $cle] = [
                'depuis'  => $depuis,
                'libelle' => 'Lire : ' . $d['libelle'],
                'detail'  => $d['nominatif']
                    ? 'Contient des données personnelles (noms, contacts, affectations). Sortie RGPD à documenter.'
                    : 'Consultation seule, aucune modification possible avec cette capacité.',
                'risque'  => $d['nominatif'] ? self::RISQUE_ELEVE : self::RISQUE_MOYEN,
            ];
            // Certains domaines n'ont pas d'accesseur d'écriture dans le
            // Contexte : générer la capacité correspondante créerait un piège —
            // un auteur la déclare, l'administrateur l'accorde, et le premier
            // appel échoue sur « n'accepte pas de création ». Mieux vaut
            // qu'elle n'existe pas.
            if (!empty($d['lecture_seule'])) continue;
            $cat['donnees.ecrire:' . $cle] = [
                'depuis'  => $depuis,
                'libelle' => 'Créer et modifier : ' . $d['libelle'],
                'detail'  => 'L\'extension peut ajouter et modifier ces enregistrements. '
                           . 'La suppression reste refusée : elle n\'est jamais accordée à une extension.',
                'risque'  => self::RISQUE_ELEVE,
            ];
        }
        return $cat;
    }

    /**
     * Version d'API minimale exigée par un jeu de capacités.
     * Sert à l'outil de construction, qui remplit « api_min » tout seul plutôt
     * que de laisser l'auteur le deviner.
     */
    public static function apiMinimale(array $capacites): string
    {
        $min = '1.0';
        foreach ($capacites as $c) {
            $d = self::catalogue()[$c]['depuis'] ?? '1.0';
            if (version_compare($d, $min, '>')) $min = $d;
        }
        return $min;
    }

    public static function existe(string $capacite): bool
    {
        return isset(self::catalogue()[$capacite]);
    }

    public static function meta(string $capacite): ?array
    {
        return self::catalogue()[$capacite] ?? null;
    }

    public static function risque(string $capacite): string
    {
        return self::catalogue()[$capacite]['risque'] ?? self::RISQUE_CRITIQUE;
    }

    /** Le risque le plus élevé d'un jeu de capacités — sert au bandeau d'installation. */
    public static function risqueGlobal(array $capacites, bool $declaratif = false): string
    {
        // Le risque global se calcule sur le catalogue du FORMAT : sinon un pack
        // de données héritait du risque « élevé » de ui.ancrage, alors qu'aucune
        // de ses permissions n'était élevée. Un bandeau rouge sur un module
        // inoffensif est un bandeau qu'on cesse de lire.
        $cat = self::catalogueSelonFormat($declaratif);
        $ordre = [self::RISQUE_FAIBLE => 0, self::RISQUE_MOYEN => 1,
                  self::RISQUE_ELEVE  => 2, self::RISQUE_CRITIQUE => 3];
        $max = self::RISQUE_FAIBLE;
        foreach ($capacites as $c) {
            $r = $cat[$c]['risque'] ?? self::risque($c);
            if (($ordre[$r] ?? 3) > $ordre[$max]) $max = $r;
        }
        return $max;
    }

    /** Capacités refusées en mode strict — celles qui désactivent le contrôle. */
    /**
     * Conservée pour compatibilité : plus aucune capacité n'est interdite « en
     * mode strict », les modes ayant disparu avec les modules à code.
     */
    public static function interditesEnStrict(): array
    {
        return [];
    }

    /**
     * Rendu pour l'écran d'installation : une ligne lisible par capacité,
     * triée du plus dangereux au plus anodin, pour que l'administrateur voie
     * d'abord ce qui compte.
     */
    public static function pourAffichage(array $capacites, bool $declaratif = false): array
    {
        $ordre = [self::RISQUE_CRITIQUE => 0, self::RISQUE_ELEVE => 1,
                  self::RISQUE_MOYEN    => 2, self::RISQUE_FAIBLE => 3];
        // Le catalogue dépend du format : le même nom de capacité n'a pas la
        // même portée pour un module à code et pour un module de données.
        $cat = self::catalogueSelonFormat($declaratif);
        $lignes = [];
        foreach ($capacites as $c) {
            $m = $cat[$c] ?? self::meta($c);
            $lignes[] = [
                'capacite' => $c,
                'libelle'  => $m['libelle'] ?? $c,
                'detail'   => $m['detail']  ?? 'Capacité inconnue de cette version de Larka — sera refusée.',
                'risque'   => $m ? $m['risque'] : self::RISQUE_CRITIQUE,
                'connue'   => $m !== null,
            ];
        }
        usort($lignes, fn($a, $b) => ($ordre[$a['risque']] ?? 0) <=> ($ordre[$b['risque']] ?? 0));
        return $lignes;
    }
}

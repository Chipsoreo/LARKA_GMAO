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
 * Larka — Modules déclaratifs : validation du schéma
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * UN MODULE DÉCLARATIF NE CONTIENT AUCUN CODE.
 *
 * C'est la différence de nature avec un module classique, et elle change tout :
 *
 *   Module classique   du PHP et du JavaScript écrits par un tiers. Il faut
 *                      l'analyser, l'isoler dans un processus, le confiner au
 *                      niveau du noyau, le cloisonner dans une iframe — et
 *                      malgré tout, on ne peut jamais affirmer qu'il est sûr.
 *
 *   Module déclaratif  un fichier JSON qui DÉCRIT des données et des écrans.
 *                      C'est Larka qui exécute, avec son propre code. Il n'y a
 *                      rien à isoler : il n'y a pas de code étranger.
 *
 * Le parallèle est celui du greffon et du fichier de configuration. Un greffon
 * injecte du code dans le programme : puissant, et il faut faire confiance à son
 * auteur. Un fichier de configuration décrit des réglages que le programme
 * interprète : on peut le relire avant de l'appliquer, parce qu'il n'y a aucun
 * chemin d'exécution.
 *
 * CE QUE CELA COÛTE
 * Un module déclaratif ne peut faire QUE ce que le format prévoit. Pas de
 * calcul particulier, pas d'appel à un service externe, pas d'algorithme. Le
 * format couvre le cas très fréquent — tenir un registre, le chercher, le
 * filtrer, l'exporter — et rien d'autre. Pour le reste, il reste les modules
 * classiques, avec leurs contreparties.
 *
 * CE QUE CELA RAPPORTE
 * L'analyse statique, le processus isolé, le confinement noyau et le bac à sable
 * client deviennent sans objet. Le risque se réduit à ce que la déclaration
 * autorise — et la déclaration est lisible par un humain en une minute.
 * ═══════════════════════════════════════════════════════════════════════════════
 */

require_once __DIR__ . '/Expression.php';
require_once __DIR__ . '/Condition.php';
require_once __DIR__ . '/../securite/PolitiqueDonnees.php';
require_once __DIR__ . '/../Elements.php';

final class ExtSchemaDeclaratif
{
    public const FORMAT = 'declaratif/1';

    /** Types de champ, et leur traduction en colonne. */
    public const TYPES = [
        'texte'      => ['sql' => 'texte',   'libelle' => 'Texte court'],
        'texte_long' => ['sql' => 'texte',   'libelle' => 'Texte long'],
        'entier'     => ['sql' => 'entier',  'libelle' => 'Nombre entier'],
        'decimal'    => ['sql' => 'decimal', 'libelle' => 'Nombre décimal'],
        'date'       => ['sql' => 'date',    'libelle' => 'Date'],
        'choix'      => ['sql' => 'texte',   'libelle' => 'Liste de choix'],
        'booleen'    => ['sql' => 'entier',  'libelle' => 'Oui / non'],
        // Référence à une donnée du cœur : stocke un identifiant, affiche un
        // libellé, et propose un sélecteur avec recherche. Sans ce type, un
        // auteur range un « entier » nu — sans sélecteur, sans vérification, et
        // la référence pointe dans le vide dès que la cible disparaît.
        'lien'       => ['sql' => 'entier',  'libelle' => 'Lien vers une donnée Larka'],
        // ── Types ajoutés ────────────────────────────────────────────────
        'heure'          => ['sql' => 'texte',   'libelle' => 'Heure (HH:MM)'],
        'horodatage'     => ['sql' => 'texte',   'libelle' => 'Date et heure'],
        'couleur'        => ['sql' => 'texte',   'libelle' => 'Couleur'],
        'note'           => ['sql' => 'entier',  'libelle' => 'Note sur 5'],
        'pourcentage'    => ['sql' => 'decimal', 'libelle' => 'Pourcentage'],
        'choix_multiple' => ['sql' => 'texte',   'libelle' => 'Choix multiples'],
        'duree'          => ['sql' => 'entier',  'libelle' => 'Durée en minutes'],
    ];

    /**
     * Domaines auxquels un champ « lien » peut renvoyer.
     *
     * Liste close, et volontairement courte : ce sont les objets qu'un module
     * de terrain a des raisons de désigner. Chacun implique la capacité de
     * lecture correspondante, calculée automatiquement — l'auteur ne la demande
     * pas, elle découle de ce qu'il déclare.
     */
    public const CIBLES_LIEN = [
        'equipements'   => ['libelle' => 'Équipement',   'affichage' => ['Numero', 'Marque', 'Modele']],
        'biens'         => ['libelle' => 'Bien',         'affichage' => ['Numero', 'Famille']],
        'interventions' => ['libelle' => 'Intervention', 'affichage' => ['Numero', 'Type']],
        'contrats'      => ['libelle' => 'Contrat',      'affichage' => ['Numero', 'Societe']],
        'stock'         => ['libelle' => 'Article',      'affichage' => ['Reference', 'Designation']],
        // Domaines ajoutés : lisibles par un lien, jamais modifiables.
        'documents'     => ['libelle' => 'Document',     'affichage' => ['NomFichier', 'Categorie']],
        'notesinfo'     => ['libelle' => 'Note',         'affichage' => ['Message']],
        'listes'        => ['libelle' => 'Valeur de liste', 'affichage' => ['Categorie', 'Valeur']],
    ];

    /**
     * Formats de saisie vérifiés, désignés par un NOM.
     *
     * Volontairement pas d'expression régulière fournie par l'auteur : une
     * expression mal écrite peut bloquer le serveur (retour arrière
     * catastrophique), et personne ne relit celle d'un pack. Un nom désigne un
     * motif que Larka connaît, et la liste est close.
     */
    public const FORMATS_SAISIE = [
        'email'       => ['libelle' => 'Adresse électronique',
                          'motif' => '/^[^@\s]{1,64}@[^@\s]{1,190}\.[a-z]{2,12}$/iu',
                          'exemple' => 'nom@exemple.fr'],
        'telephone'   => ['libelle' => 'Numéro de téléphone',
                          'motif' => '/^[+0-9][0-9 .\-]{6,24}$/',
                          'exemple' => '01 23 45 67 89'],
        'code_postal' => ['libelle' => 'Code postal',
                          'motif' => '/^[0-9]{5}$/', 'exemple' => '75001'],
        'siret'       => ['libelle' => 'SIRET',
                          'motif' => '/^[0-9]{14}$/', 'exemple' => '12345678901234'],
        'url'         => ['libelle' => 'Adresse web',
                          'motif' => '#^https?://[^\s]{3,300}$#i', 'exemple' => 'https://exemple.fr'],
        'reference'   => ['libelle' => 'Référence',
                          'motif' => '/^[A-Za-z0-9][A-Za-z0-9 .\-\/]{0,39}$/',
                          'exemple' => 'EQ-2026-001'],
        'iban'        => ['libelle' => 'IBAN',
                          'motif' => '/^[A-Z]{2}[0-9]{2}[A-Z0-9 ]{10,30}$/i',
                          'exemple' => 'FR76 3000 1007 9412 3456 7890 185'],
        'tva'         => ['libelle' => 'Numéro de TVA',
                          'motif' => '/^[A-Z]{2}[A-Z0-9]{2,13}$/i', 'exemple' => 'FR12345678901'],
        'immatriculation' => ['libelle' => 'Plaque d\'immatriculation',
                          'motif' => '/^[A-Z]{2}-?[0-9]{3}-?[A-Z]{2}$/i', 'exemple' => 'AB-123-CD'],
        'numero_serie'=> ['libelle' => 'Numéro de série',
                          'motif' => '/^[A-Za-z0-9\-]{3,40}$/', 'exemple' => 'SN-2026-00042'],
        'code_barre'  => ['libelle' => 'Code-barres',
                          'motif' => '/^[0-9]{8,14}$/', 'exemple' => '3560070139101'],
        'ip'          => ['libelle' => 'Adresse IP',
                          'motif' => '/^((25[0-5]|2[0-4]\d|1?\d?\d)\.){3}(25[0-5]|2[0-4]\d|1?\d?\d)$/',
                          'exemple' => '192.168.1.10'],
        'mac'         => ['libelle' => 'Adresse MAC',
                          'motif' => '/^([0-9A-F]{2}[:\-]){5}[0-9A-F]{2}$/i',
                          'exemple' => '00:1B:44:11:3A:B7'],
        'rge'         => ['libelle' => 'Numéro RGE',
                          'motif' => '/^[A-Z0-9\-\/]{4,30}$/i', 'exemple' => 'QB\/12345'],
        'heure_texte' => ['libelle' => 'Heure',
                          'motif' => '/^([01]?\d|2[0-3])[:h][0-5]\d$/', 'exemple' => '14:30'],
        'coordonnees' => ['libelle' => 'Coordonnées GPS',
                          'motif' => '/^-?\d{1,3}\.\d{1,8}\s*,\s*-?\d{1,3}\.\d{1,8}$/',
                          'exemple' => '48.8566, 2.3522'],
    ];

    /**
     * Sources de suggestions autres que les données du module.
     *
     * Un champ « Titulaire » ne peut rien proposer tant que le registre est
     * vide : la première saisie n'a aucune aide, et c'est précisément celle où
     * l'orthographe se fixe pour toutes les suivantes. On autorise donc à
     * puiser dans une source du cœur.
     *
     * Liste close, en LECTURE seule, et sur des colonnes non sensibles : un
     * module ne choisit pas la table qu'il consulte.
     */
    public const SOURCES_SUGGESTION = [
        // Servie par le CŒUR, pas par un accès à la table.
        //
        // « Utilisateurs » est une table système : y donner accès livrerait les
        // empreintes de mots de passe et les rôles. On ne l'ouvre donc pas.
        // Larka répond en revanche à une question étroite — « quels sont les
        // noms ? » — avec une projection FIXE : prénom et nom des comptes
        // actifs, rien d'autre, et le module ne choisit ni la table, ni les
        // colonnes, ni le filtre.
        //
        // La distinction compte : un accès se détourne, une réponse non.
        'personnes'  => ['table' => null, 'colonnes' => [],
                         'libelle' => 'Noms des personnes'],

        // « Equipements.NomPrenom » a été essayée puis retirée : c'est une table SYSTÈME,
        // protégée avant même l'examen des capacités. La frontière est bonne —
        // la table des comptes porte les empreintes de mots de passe et les
        // rôles — et il ne fallait pas l'abaisser pour un confort de saisie.
        //
        // « Equipements.NomPrenom » a été essayée puis retirée aussi : la
        // politique de colonnes la MASQUE, à juste titre — c'est une donnée
        // nominative. Les suggestions renvoyaient « J•••••••• », ce qui est le
        // masquage correct d'une source qui n'aurait pas dû être proposée.
        //
        // Aucune source du cœur ne fournit donc des noms de personnes. C'est
        // cohérent : un module de suivi n'a pas à connaître l'annuaire. Les
        // noms restent proposés par les valeurs DÉJÀ SAISIES dans le module,
        // qui lui appartiennent.
        'intervenants' => ['table' => 'Interventions', 'colonnes' => ['Intervenant'],
                         'libelle' => 'Intervenants des interventions'],
        'batiments'  => ['table' => 'Biens',        'colonnes' => ['Batiment'],
                         'libelle' => 'Bâtiments des biens'],
        'fournisseurs' => ['table' => 'Equipements','colonnes' => ['Fournisseur'],
                         'libelle' => 'Fournisseurs des équipements'],
        'marques'    => ['table' => 'Equipements',  'colonnes' => ['Marque'],
                         'libelle' => 'Marques des équipements'],
    ];

    /**
     * Couleurs nommées — des RACCOURCIS, plus une liste close.
     *
     * Elles restaient le seul moyen de colorer un badge, et la palette de seize
     * ne couvrait pas les chartes graphiques : une entreprise dont le vert de
     * marque est #0B6E4F devait se rabattre sur « vert », qui ne lui ressemble
     * pas. Un nom est commode, il ne doit pas être obligatoire.
     *
     * Un hexadécimal `#rrggbb` est donc accepté partout où un nom l'est. Les
     * noms restent — ils se lisent mieux dans une déclaration, et les modules
     * existants continuent de fonctionner sans être retouchés.
     *
     * Pourquoi ce n'est pas « du CSS libre » : la valeur est vérifiée contre
     * `#rrggbb` et rien d'autre. Ni `url(...)`, ni `expression(...)`, ni
     * variable, ni fonction — une couleur entre, une couleur sort.
     */
    public const COULEURS = [
        'gris' => '#6b7280', 'vert' => '#16a34a', 'orange' => '#ea580c',
        'rouge' => '#b91c1c', 'bleu' => '#2563eb', 'violet' => '#7c3aed',
        'jaune' => '#ca8a04',
        'turquoise' => '#0d9488', 'rose' => '#db2777', 'indigo' => '#4f46e5',
        'ardoise' => '#475569', 'ambre' => '#d97706', 'emeraude' => '#059669',
        'brique' => '#9a3412', 'marine' => '#1b3a5c', 'lavande' => '#8b5cf6',
    ];

    private const MAX_RETOUCHES = 12;

    /**
     * Retouches de l'interface du cœur : masquer, renommer, rediriger.
     *
     * C'EST LA SEULE PRIMITIVE DE CE FORMAT QUI RETIRE QUELQUE CHOSE.
     * Toutes les autres ajoutent — une page, une tuile, un repère. Celle-ci
     * enlève, et cela demande deux garde-fous que les autres n'ont pas :
     *
     *   1. Une liste d'écrans INTOUCHABLES, indépendante des permissions. Un
     *      module qui pourrait masquer l'écran Extensions se rendrait
     *      indésinstallable depuis l'interface ; un module qui pourrait masquer
     *      le Journal effacerait la trace de ses propres accès. Aucun réglage
     *      ne lève cette barrière.
     *
     *   2. Le masquage est VISUEL, jamais un droit. L'entrée de menu disparaît,
     *      l'écran reste joignable et les permissions du rôle sont inchangées.
     *      Laisser un module retirer un accès réel reviendrait à lui confier la
     *      gestion des droits — et un module mal réglé enfermerait
     *      l'administrateur hors de son propre logiciel.
     */
    private static function validerInterface(mixed $liste): array
    {
        if (empty($liste)) return [];
        if (!is_array($liste)) {
            throw new RuntimeException('Champ « interface » : tableau attendu.');
        }
        if (count($liste) > self::MAX_RETOUCHES) {
            throw new RuntimeException('Douze retouches d\'interface au maximum.');
        }

        $out = [];
        foreach ($liste as $r) {
            if (!is_array($r)) {
                throw new RuntimeException('Une retouche est un objet. Exemple : '
                    . '{"element":"chorus","operation":"masquer"}');
            }
            $el = (string)($r['element'] ?? '');
            $op = (string)($r['operation'] ?? '');

            if (!in_array($op, ExtElements::OPERATIONS, true)) {
                throw new RuntimeException("Retouche : opération « $op » inconnue. "
                    . 'Opérations : ' . implode(', ', ExtElements::OPERATIONS));
            }
            if (ExtElements::intouchable($el)) {
                throw new RuntimeException("Retouche : « $el » ne peut pas être "
                    . 'retouché. Le journal, les extensions, la configuration et les '
                    . 'comptes sont les écrans par lesquels on constate ce qu\'un '
                    . 'module fait et par lesquels on le retire : un module qui '
                    . 'pourrait les masquer se mettrait hors de portée.');
            }
            if (!ExtElements::existe($el)) {
                throw new RuntimeException("Retouche : élément « $el » inconnu. "
                    . 'Éléments : ' . implode(', ', array_keys(ExtElements::catalogue())));
            }

            $v = ['element' => $el, 'operation' => $op, 'roles' => []];

            // Retouche ciblée : « masquer Chorus pour les Demandeurs, pas pour
            // les gestionnaires ». Sans rôles, la retouche vaut pour tous.
            foreach ((array)($r['roles'] ?? []) as $ro) {
                if (!in_array($ro, self::ROLES, true)) {
                    throw new RuntimeException("Retouche : rôle inconnu « $ro ».");
                }
                $v['roles'][] = $ro;
            }
            $v['roles'] = array_values(array_unique($v['roles']));

            if ($op === 'renommer') {
                $lib = trim((string)($r['libelle'] ?? ''));
                if ($lib === '' || mb_strlen($lib) > 40) {
                    throw new RuntimeException('Retouche « renommer » : « libelle » '
                        . 'obligatoire, 40 caractères au maximum.');
                }
                $v['libelle'] = $lib;
                if (!empty($r['icone'])) {
                    $v['icone'] = mb_substr((string)$r['icone'], 0, 4);
                }
            }

            if ($op === 'rediriger') {
                // La cible est une page DE CE MODULE, jamais une adresse ni un
                // écran du cœur. Rediriger vers l'extérieur ferait d'une entrée
                // de menu un lien sortant que personne n'aurait relu ; rediriger
                // vers un autre écran du cœur donnerait à un module le pouvoir
                // de réorganiser Larka.
                $vers = (string)($r['vers'] ?? '');
                if (!preg_match('/^[a-z0-9_]{1,30}$/', $vers)) {
                    throw new RuntimeException('Retouche « rediriger » : « vers » doit '
                        . 'nommer une page de ce module.');
                }
                $v['vers'] = $vers;
                if (!empty($r['libelle'])) {
                    $v['libelle'] = mb_substr(trim((string)$r['libelle']), 0, 40);
                }
            }

            $out[] = $v;
        }
        return $out;
    }

    /**
     * Natures de fichier qu'un module peut détenir dans un dossier à lui.
     *
     * Liste CLOSE, et chaque entrée dit comment le contenu est vérifié — par les
     * OCTETS, jamais par l'extension du nom. Un fichier appelé « logo.png » qui
     * serait en réalité du HTML ne doit pas pouvoir être déposé, encore moins
     * servi : c'est le même contrôle que celui des images de fond du cœur.
     *
     * `svg` est absent, et le restera : le format accepte un <script>, et une
     * image servie au navigateur deviendrait un moyen d'exécuter du code dans
     * la page de Larka. C'est aussi pour cela que `logo` l'exclut déjà.
     */
    public const NATURES_FICHIER = [
        'image'  => ['libelle' => 'Images',
                     'extensions' => ['png', 'jpg', 'jpeg', 'webp'],
                     'taille_max' => 4 * 1024 * 1024,
                     'controle' => 'image'],
        'langue' => ['libelle' => 'Traductions',
                     'extensions' => ['json'],
                     'taille_max' => 2 * 1024 * 1024,
                     'controle' => 'langue'],
        'theme'  => ['libelle' => 'Thèmes',
                     'extensions' => ['json'],
                     'taille_max' => 512 * 1024,
                     'controle' => 'theme'],
        'document' => ['libelle' => 'Documents',
                     'extensions' => ['pdf', 'txt', 'csv', 'md'],
                     'taille_max' => 8 * 1024 * 1024,
                     'controle' => 'document'],
    ];

    private const MAX_DOSSIERS = 8;
    private const ROLES = ['Gestionnaire', 'Admin', 'Visionneur', 'Demandeur'];

    /**
     * Dossiers de fichiers appartenant au module.
     *
     * POURQUOI CELA N'OUVRE PAS LA PORTE AU CODE
     * Un module déclaratif n'exécute rien : il ne peut donc pas écrire un
     * fichier de sa propre initiative. Ce qu'il déclare ici, c'est un ESPACE —
     * que Larka crée à l'installation, remplit avec ce que le paquet livre, et
     * dans lequel un utilisateur peut déposer si le module l'autorise.
     *
     * Trois écritures possibles, et aucune n'est le module lui-même :
     *   1. l'installation, qui recopie les fichiers livrés dans le paquet ;
     *   2. un utilisateur, via l'écran du module, si « import » est vrai ;
     *   3. rien d'autre.
     *
     * L'espace vit hors de la racine web, sous data/, et n'est servi que par
     * une route qui impose le type et le téléchargement — jamais interprété.
     */
    private static function validerFichiers(mixed $liste): array
    {
        if (empty($liste)) return [];
        if (!is_array($liste)) {
            throw new RuntimeException('Champ « fichiers » : objet attendu.');
        }
        if (count($liste) > self::MAX_DOSSIERS) {
            throw new RuntimeException('Huit dossiers de fichiers au maximum.');
        }

        $out = [];
        foreach ($liste as $nom => $d) {
            $nom = self::nomTechnique((string)$nom, 'dossier de fichiers');
            if (!is_array($d)) $d = ['natures' => [(string)$d]];

            $natures = $d['natures'] ?? $d['nature'] ?? [];
            if (is_string($natures)) $natures = [$natures];
            if (!is_array($natures) || $natures === []) {
                throw new RuntimeException("Dossier « $nom » : indiquez ses « natures ». "
                    . 'Natures : ' . implode(', ', array_keys(self::NATURES_FICHIER)));
            }
            $propres = [];
            foreach ($natures as $n) {
                $n = (string)$n;
                if (!isset(self::NATURES_FICHIER[$n])) {
                    throw new RuntimeException("Dossier « $nom » : nature « $n » inconnue. "
                        . 'Natures : ' . implode(', ', array_keys(self::NATURES_FICHIER)));
                }
                $propres[] = $n;
            }

            $out[$nom] = [
                'nom'      => $nom,
                'natures'  => array_values(array_unique($propres)),
                'libelle'  => mb_substr((string)($d['libelle'] ?? ucfirst($nom)), 0, 60),
                'aide'     => mb_substr((string)($d['aide'] ?? ''), 0, 160),
                // L'import est une PERMISSION distincte de la détention : un
                // module peut livrer des icônes sans pour autant vouloir que
                // n'importe qui en dépose.
                'import'   => !empty($d['import']),
                // Rôles autorisés à déposer. Par défaut le gestionnaire seul :
                // ouvrir à tous par défaut ferait d'un oubli une permission.
                'roles'    => self::rolesFichiers($d['roles'] ?? ['Gestionnaire', 'Admin'], $nom),
                'max_fichiers' => max(1, min((int)($d['max_fichiers'] ?? 100), 1000)),
            ];
        }
        return $out;
    }

    private static function rolesFichiers(mixed $roles, string $nom): array
    {
        $connus = ['Gestionnaire', 'Admin', 'Visionneur', 'Demandeur'];
        $out = [];
        foreach ((array)$roles as $r) {
            if (!in_array($r, $connus, true)) {
                throw new RuntimeException("Dossier « $nom » : rôle inconnu « $r ».");
            }
            $out[] = $r;
        }
        return $out === [] ? ['Gestionnaire', 'Admin'] : array_values(array_unique($out));
    }

    /**
     * Normalise une couleur : un nom du catalogue, ou un hexadécimal #rrggbb.
     *
     * Rend la valeur hexadécimale finale, ou null si l'entrée n'est ni l'un ni
     * l'autre. La forme courte #abc est acceptée et développée : on l'écrit
     * naturellement, et la refuser pour cette seule raison serait un piège.
     *
     * @throws RuntimeException si la valeur n'est pas une couleur
     */
    public static function couleur(mixed $v, string $ou): string
    {
        $v = trim((string)$v);
        if (isset(self::COULEURS[$v])) return self::COULEURS[$v];
        if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $v)) {
            if (strlen($v) === 4) {           // #abc → #aabbcc
                $v = '#' . $v[1] . $v[1] . $v[2] . $v[2] . $v[3] . $v[3];
            }
            return strtolower($v);
        }
        throw new RuntimeException("$ou : couleur « $v » invalide. Attendu : un "
            . 'hexadécimal (« #0b6e4f », « #abc ») ou un nom parmi '
            . implode(', ', array_keys(self::COULEURS)) . '.');
    }

    /** Champs posés par Larka sur chaque jeu, sans que l'auteur les déclare. */
    public const CHAMPS_AUDIT = [
        'cree_le'    => ['type' => 'date',  'libelle' => 'Créé le'],
        'cree_par'   => ['type' => 'texte', 'libelle' => 'Créé par'],
        'modifie_le' => ['type' => 'date',  'libelle' => 'Modifié le'],
        'modifie_par'=> ['type' => 'texte', 'libelle' => 'Modifié par'],
    ];

    // ── Plafonds ────────────────────────────────────────────────────────
    //
    // Ils ne sont pas là pour brider l'auteur : ils sont là pour qu'une
    // déclaration aberrante — produite par une boucle, un export raté, une
    // génération automatique — soit refusée avant de créer 4000 colonnes en
    // base ou de faire ramer un écran.
    //
    // Les valeurs d'origine (5 jeux, 30 champs, 5 pages) étaient calibrées sur
    // le registre simple. Elles refusaient des modules légitimes : un suivi
    // réglementaire sérieux dépasse 30 champs, un module métier dépasse 5
    // écrans. Relevées d'un facteur 4, elles laissent la place à un vrai module
    // sans cesser de border l'aberration.
    private const MAX_JEUX    = 20;
    private const MAX_CHAMPS  = 120;
    private const MAX_PAGES   = 20;
    private const MAX_CHOIX   = 200;

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * MISE EN PAGE DÉCLARATIVE — catalogues et plafonds
     * ═══════════════════════════════════════════════════════════════════════
     *
     * CE QUE C'EST
     * Une grille, des composants placés dessus, et des sections qui regroupent.
     * L'auteur dit OÙ vont les champs et COMBIEN de place ils prennent ; il ne
     * dit jamais COMMENT les dessiner. C'est la même frontière que partout
     * ailleurs dans ce format : on élargit le vocabulaire, on n'ouvre pas la
     * porte au code.
     *
     * POURQUOI UNE GRILLE, ET NON DES COORDONNÉES LIBRES
     * Des x/y en pixels produisent une interface qui ne survit ni à une police
     * plus grande, ni à un écran étroit, ni à une traduction plus longue — et
     * l'auteur ne peut pas le prévoir depuis son JSON. Une grille en COLONNES
     * garde la maîtrise de la disposition tout en laissant le rendu s'adapter :
     * c'est la seule façon d'être à la fois précis et responsive.
     *
     * CE QU'UNE MISE EN PAGE NE PEUT PAS FAIRE
     * Elle ne choisit pas les données, ne crée aucun champ, n'accorde aucune
     * action et ne touche à aucune permission. Elle ne fait que DISPOSER des
     * champs qui existent déjà dans le jeu. Un layout ne peut donc pas élargir
     * ce qu'un module voit ou écrit — cette propriété est vérifiée par une
     * épreuve dédiée, parce qu'elle est la raison pour laquelle cette primitive
     * est sûre.
     *
     * LES PLAFONDS
     * Ils bornent le COÛT de rendu, pas l'ambition : 300 composants suffisent à
     * décrire l'écran le plus chargé qu'on puisse encore lire, et une
     * imbrication de 4 niveaux dépasse déjà ce qu'un formulaire supporte. Au-
     * delà, une déclaration n'est plus une interface : c'est une génération
     * automatique partie en boucle, et elle doit échouer à l'installation
     * plutôt que devant l'utilisateur.
     */
    private const LAYOUT_MAX_COLONNES   = 24;   // colonnes de la grille
    private const LAYOUT_MAX_LIGNES     = 200;  // borne de « y » et des hauteurs
    private const LAYOUT_MAX_COMPOSANTS = 300;  // total, sections comprises
    private const LAYOUT_MAX_PROFONDEUR = 4;    // imbrication des sections
    private const LAYOUT_MAX_RESPONSIVE = 4;    // paliers d'adaptation
    private const LAYOUT_MAX_GAP_PX     = 64;   // espacements et marges
    private const LAYOUT_MAX_PX         = 2000; // toute dimension en pixels
    private const LAYOUT_MIN_PX         = 16;   // en deçà, un champ est inutilisable
    private const LAYOUT_MAX_TEXTE      = 200;  // longueur d'un texte statique

    /** Types de mise en page. Liste close : une mise en page ne s'invente pas. */
    private const LAYOUT_TYPES = ['grille'];

    /**
     * Habillages d'une section. Ce sont des NOMS, jamais du style.
     * Le client sait dessiner une carte ; la déclaration ne lui dit pas comment.
     */
    private const LAYOUT_STYLES_SECTION = ['carte', 'panneau', 'section', 'encadre', 'discret'];

    /** Styles d'un texte statique. */
    private const LAYOUT_STYLES_TEXTE = ['normal', 'titre', 'aide'];

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * IMAGES — un chemin vers un fichier du paquet, jamais une image en ligne
     * ═══════════════════════════════════════════════════════════════════════
     *
     * Une image de mise en page désigne un fichier livré dans `assets/`, comme
     * le logo. Trois raisons de ne jamais accepter autre chose :
     *
     *   · Pas de base64 dans la déclaration. Une image encodée gonflerait le
     *     JSON et le rendrait illisible, alors qu'on veut pouvoir relire une
     *     déclaration avant de l'installer.
     *   · Pas d'URL extérieure. Ce serait une sortie réseau — un module de
     *     données qui contacte un serveur tiers devient un moyen de pistage,
     *     et il faudrait le relire comme du code.
     *   · Pas de SVG. Le format accepte un <script>, et une image servie au
     *     navigateur deviendrait un moyen d'exécuter du code dans la page.
     *
     * Le fichier est servi par la route `ext_image`, qui exige une session,
     * vérifie le chemin, et contrôle que le contenu EST une image par ses
     * OCTETS — un fichier nommé `.png` qui serait du HTML est refusé là.
     */
    private const LAYOUT_IMAGE_CHEMIN = '#^assets/[a-zA-Z0-9_\-]{1,80}\.(png|jpg|jpeg|webp)$#';

    /** Comment l'image occupe la place qu'on lui donne. */
    private const LAYOUT_CADRAGES = [
        'contenir' => 'contain',   // entière, quitte à laisser du vide
        'couvrir'  => 'cover',     // remplit, quitte à rogner les bords
        'naturel'  => 'none',      // taille d'origine
    ];

    /** Arrondi des coins, résolu en valeur CSS dès la validation. */
    private const LAYOUT_ARRONDIS = [
        'aucun' => '0', 'leger' => '8px', 'fort' => '16px', 'cercle' => '50%',
    ];

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * TAILLES NOMMÉES — parce qu'un auteur ne devrait pas compter des pixels
     * ═══════════════════════════════════════════════════════════════════════
     *
     * Aucune dimension n'a jamais été OBLIGATOIRE : sans rien déclarer, la
     * grille fait douze colonnes, l'espacement douze pixels, et chaque champ
     * prend sa hauteur naturelle. Mais dès qu'un auteur voulait s'en écarter,
     * il devait écrire un nombre — et choisir entre 90 et 96 pixels n'est pas
     * une décision qu'on sait prendre depuis un fichier JSON.
     *
     * C'est le même problème que les couleurs, et il reçoit la même réponse :
     * un NOM du catalogue, ou la valeur exacte pour qui la veut. « serré »
     * plutôt que 8, « grande » plutôt que 96. Les noms couvrent tous les cas
     * courants ; les pixels restent pour le cas précis.
     *
     * Ce qu'on ne fait PAS : accepter les deux à la fois sur un même objet.
     * Deux façons de dire la même chose finissent par se contredire, et il
     * faudrait alors arbitrer en silence.
     */
    private const LAYOUT_ESPACEMENTS = [
        'aucun' => 0, 'serre' => 8, 'normal' => 12, 'large' => 20, 'tres_large' => 28,
    ];
    private const LAYOUT_MARGES = [
        'aucune' => 0, 'normale' => 12, 'large' => 20, 'tres_large' => 28,
    ];

    /**
     * Hauteur de la BOÎTE DE SAISIE.
     *
     * « normale » ne vaut pas un nombre : elle laisse le champ à sa hauteur
     * naturelle, celle du thème. C'est la bonne valeur pour la quasi-totalité
     * des champs, et la raison pour laquelle on n'écrit presque jamais de
     * hauteur.
     */
    private const LAYOUT_TAILLES = [
        'compacte' => 32, 'normale' => null, 'grande' => 96, 'tres_grande' => 160,
    ];

    /**
     * Seuils d'adaptation nommés. Un auteur sait ce qu'est un téléphone ; il
     * n'a pas à savoir que cela commence à 640 pixels.
     */
    private const LAYOUT_SEUILS = [
        'telephone' => 640, 'tablette' => 1024, 'ecran_etroit' => 1280,
    ];

    /**
     * Alignements — résolus en mots-clés CSS DÈS LA VALIDATION.
     *
     * Même raison que pour les couleurs : le client reçoit une valeur, jamais
     * un nom à retraduire. Deux tables de correspondance — une ici, une dans le
     * navigateur — finissent toujours par diverger.
     */
    private const LAYOUT_ALIGN_H = ['gauche' => 'start', 'centre' => 'center',
                                    'droite' => 'end',   'etire'  => 'stretch'];
    private const LAYOUT_ALIGN_V = ['haut'   => 'start', 'centre' => 'center',
                                    'bas'    => 'end',   'etire'  => 'stretch'];

    /**
     * Propriétés acceptées, par nature de composant.
     *
     * ⚠️ TOUT CE QUI N'EST PAS DANS CES LISTES EST REFUSÉ.
     * C'est la règle qui fait tenir la promesse « aucun code » : une propriété
     * inconnue ne peut pas être ignorée en silence, car on ne saurait pas dire
     * ce qu'elle voulait faire. Un « style », un « onclick » ou un « html »
     * glissés dans un composant échouent à l'installation, à l'endroit et au
     * moment où l'auteur peut encore corriger.
     */
    private const LAYOUT_CLES_RACINE = ['type', 'colonnes', 'espacement', 'gap_px',
        'gap_x_px', 'gap_y_px', 'marges', 'marges_px', 'alignement_h', 'alignement_v',
        'responsive', 'composants'];
    private const LAYOUT_CLES_SECTION = ['section', 'titre', 'style', 'couleur',
        'colonnes', 'espacement', 'gap_px', 'gap_x_px', 'gap_y_px', 'x', 'y',
        'largeur', 'hauteur', 'taille', 'hauteur_px', 'hauteur_min_px',
        'hauteur_max_px', 'alignement_h', 'alignement_v', 'visible_si',
        'composants', 'repliable', 'repliee'];
    private const LAYOUT_CLES_CHAMP = ['champ', 'x', 'y', 'largeur', 'hauteur',
        'taille', 'largeur_px', 'hauteur_px', 'largeur_min_px', 'largeur_max_px',
        'hauteur_min_px', 'hauteur_max_px', 'alignement_h', 'alignement_v',
        'visible_si', 'masquer_libelle'];
    private const LAYOUT_CLES_ESPACE = ['espace', 'x', 'y', 'largeur', 'hauteur',
        'taille', 'hauteur_px'];
    private const LAYOUT_CLES_TEXTE = ['texte', 'style', 'x', 'y', 'largeur',
        'hauteur', 'alignement_h', 'visible_si'];
    private const LAYOUT_CLES_IMAGE = ['image', 'description', 'x', 'y', 'largeur',
        'hauteur', 'taille', 'hauteur_px', 'hauteur_min_px', 'hauteur_max_px',
        'largeur_max_px', 'cadrage', 'arrondi', 'alignement_h', 'visible_si'];
    private const LAYOUT_CLES_PALIER = ['sous', 'en_dessous_de_px', 'colonnes',
        'espacement', 'gap_px'];

    /**
     * Valide une déclaration. Refuse au premier problème plutôt que de corriger :
     * une déclaration approximative décrit un écran qu'on ne comprend pas.
     *
     * @throws RuntimeException
     */
    public static function valider(array $d): array
    {
        if (($d['format'] ?? '') !== self::FORMAT) {
            throw new RuntimeException('Champ « format » attendu : « ' . self::FORMAT . ' ».');
        }

        // ── Aucun code, et on le vérifie ─────────────────────────────────
        // Un module déclaratif qui embarque un point d'entrée n'est plus
        // déclaratif. Le laisser passer reviendrait à offrir l'exécution de
        // code sous une étiquette rassurante — exactement ce qu'il ne faut pas.
        // « ancrages » n'est PAS dans cette liste, et la nuance est le cœur du
        // format : un module déclaratif ne fournit pas de code d'ancrage, il
        // DEMANDE une primitive que Larka dessine elle-même. C'est le principe
        // du module de données : on n'ouvre pas la porte au code, on élargit le
        // vocabulaire que le moteur sait lire.
        // ── Sortie réseau : réservée aux modules à code ──────────────────
        //
        // Un webhook est une primitive d'EXFILTRATION : un JSON capable de
        // poster {{record.*}} vers une URL de son choix annulerait le
        // confinement réseau et rendrait le pack non installable sans
        // relecture — c'est-à-dire qu'il cesserait d'être un module de données.
        //
        // Le besoin reste légitime ; il relève simplement de l'autre format,
        // qui passe par la capacité « reseau.sortant », des domaines déclarés
        // au manifeste et les cinq frontières de sécurité.
        foreach (['webhooks', 'webhook', 'integrations', 'domaines_reseau'] as $reseau) {
            if (!empty($d[$reseau])) {
                throw new RuntimeException("Un module déclaratif ne peut pas déclarer "
                    . "« $reseau » : la sortie réseau est réservée aux modules à code. "
                    . 'Un module de données pouvant contacter un serveur extérieur serait '
                    . 'un moyen d\'exfiltration, et devrait alors être relu comme du code. '
                    . 'Voir Documentations/EXTENSIONS.md, capacité « reseau.sortant ».');
            }
        }

        foreach (['serveur', 'client', 'hooks'] as $interdit) {
            if (!empty($d[$interdit])) {
                throw new RuntimeException("Un module déclaratif ne peut pas déclarer "
                    . "« $interdit » : il ne contient aucun code. Retirez ce champ, ou "
                    . "publiez un module classique.");
            }
        }

        // ── Jeux de données ──────────────────────────────────────────────
        $jeux = $d['donnees'] ?? [];
        // Un module d'HABILLAGE n'a ni données ni écran : il ne fait que
        // redéfinir l'apparence ou traduire des libellés. Exiger un jeu de
        // données l'obligerait à en déclarer un vide pour contourner le
        // contrôle — une table inutile créée en base pour rien.
        $habillage = !empty($d['vocabulaire']) || !empty($d['variables'])
                  || !empty($d['langues']);
        if ((!is_array($jeux) || $jeux === []) && !$habillage) {
            throw new RuntimeException('Un module doit décrire au moins un jeu de données '
                . '(« donnees »), ou habiller l\'application (« interface ») ou traduire '
                . 'des libellés (« langues »).');
        }
        // Un module d'habillage PEUT avoir une page — celle qui présente les
        // thèmes. Elle n'a pas de source de données : on la valide à part,
        // puis on finalise. Sans cela, finaliserHabillage() vidait « pages » et
        // l'écran déclaré n'existait nulle part.
        if (!is_array($jeux) || $jeux === []) {
            $pagesHabillage = [];
            foreach ((array)($d['pages'] ?? []) as $pg) {
                if (($pg['vue']['type'] ?? '') !== 'apparence') {
                    throw new RuntimeException('Un module sans jeu de données ne peut '
                        . 'déclarer qu\'une page de type « apparence ».');
                }
                $pagesHabillage[] = self::validerPage($pg, []);
            }
            $d = self::finaliserHabillage($d);
            $d['pages'] = $pagesHabillage;
            if ($pagesHabillage !== []) $d['_page_apparence'] = true;
            return $d;
        }
        if (count($jeux) > self::MAX_JEUX) {
            throw new RuntimeException('Trop de jeux de données (maximum ' . self::MAX_JEUX . ').');
        }

        $valides = [];
        foreach ($jeux as $nom => $jeu) {
            $valides[self::nomTechnique($nom, 'jeu de données')] = self::validerJeu($nom, $jeu);
        }
        $d['donnees'] = $valides;

        // ── Vocabulaire publié / valeurs fournies ────────────────────────
        $d['vocabulaire'] = self::validerVocabulaire($d['vocabulaire'] ?? []);
        $d['variables']   = self::validerVariables($d['variables'] ?? []);

        // ── Réglages du module ───────────────────────────────────────────
        $d['reglages'] = self::validerReglages($d['reglages'] ?? []);

        // ── Dossiers de fichiers, et retouches de l'interface ─────────────
        $d['fichiers']  = self::validerFichiers($d['fichiers'] ?? []);
        $d['interface'] = self::validerInterface($d['interface'] ?? []);

        // ── Traductions ──────────────────────────────────────────────────
        $d['langues'] = self::validerLangues($d['langues'] ?? []);

        // ── Partages et calculs externes ─────────────────────────────────
        $d['partage'] = self::validerPartages($d['partage'] ?? [], $d['donnees']);
        $d['calculs'] = self::validerCalculs($d['calculs'] ?? [], $d['donnees']);

        // ── Présentation ─────────────────────────────────────────────────
        // Le logo est un CHEMIN vers un fichier du paquet, jamais une image
        // encodée dans le JSON : une donnée en base64 gonflerait la déclaration
        // et la rendrait illisible, alors qu'on veut pouvoir la relire avant
        // d'installer.
        if (!empty($d['logo'])) {
            $logo = (string)$d['logo'];
            // SVG exclu : le format accepte un <script>, et un logo servi au
            // navigateur deviendrait un moyen d'exécuter du code dans la page.
            if (!preg_match('#^assets/[a-zA-Z0-9_\-]{1,60}\.(png|jpg|jpeg|webp)$#', $logo)) {
                throw new RuntimeException('Champ « logo » : chemin attendu sous assets/, '
                    . 'par exemple « assets/logo.png ». Formats : png, jpg, webp. '
                    . 'Le SVG n\'est pas accepté : il peut contenir du script.');
            }
            $d['logo'] = $logo;
        }

        // ── Ancrages déclaratifs ─────────────────────────────────────────
        $d['ancrages'] = self::validerAncrages($d['ancrages'] ?? [], $d['donnees']);

        // ── Pages ────────────────────────────────────────────────────────
        $pages = $d['pages'] ?? [];
        if (!is_array($pages) || $pages === []) {
            throw new RuntimeException('Aucune page déclarée (« pages »).');
        }
        if (count($pages) > self::MAX_PAGES) {
            throw new RuntimeException('Trop de pages (maximum ' . self::MAX_PAGES . ').');
        }
        foreach ($pages as $i => $page) {
            $pages[$i] = self::validerPage($page, $d['donnees']);
        }
        $d['pages'] = array_values($pages);

        return $d;
    }

    private static function nomTechnique(string $nom, string $quoi): string
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,30}$/', $nom)) {
            throw new RuntimeException("Nom de $quoi « $nom » invalide : minuscules, "
                . 'chiffres et underscore, commençant par une lettre.');
        }
        return $nom;
    }

    private static function validerJeu(string $nom, mixed $jeu): array
    {
        if (!is_array($jeu)) throw new RuntimeException("Jeu « $nom » : objet attendu.");

        $champs = $jeu['champs'] ?? [];
        if (!is_array($champs) || $champs === []) {
            throw new RuntimeException("Jeu « $nom » : aucun champ déclaré.");
        }
        if (count($champs) > self::MAX_CHAMPS) {
            throw new RuntimeException("Jeu « $nom » : trop de champs (maximum "
                . self::MAX_CHAMPS . ').');
        }

        $out = [];
        foreach ($champs as $cle => $champ) {
            $cle = self::nomTechnique((string)$cle, 'champ');
            if ($cle === 'id') {
                throw new RuntimeException('Le champ « id » est fourni par Larka : '
                    . 'ne le déclarez pas.');
            }
            if (!is_array($champ)) throw new RuntimeException("Champ « $cle » : objet attendu.");

            // ── Nom de colonne réservé ───────────────────────────────────
            //
            // Un champ nommé « mot_de_passe » ou « api_token » serait masqué par
            // la politique de colonnes à la lecture — mais laisser un pack en
            // créer un revient à l'inviter à stocker un secret dans une colonne
            // que rien ne chiffre, et dont il ne pourra ensuite plus relire la
            // valeur. Le refus est plus honnête que le masquage silencieux.
            //
            // Le contrôle existait à la création de table, à l'exécution ; il
            // manquait ici, à la validation — une attaque de la suite l'a montré.
            if (ExtPolitiqueDonnees::niveauColonne($cle) === ExtPolitiqueDonnees::REFUSE) {
                throw new RuntimeException("Champ « $cle » : ce nom est réservé. "
                    . 'Les colonnes évoquant un secret (mot de passe, jeton, clé, '
                    . 'empreinte) ne peuvent pas être déclarées : Larka ne les '
                    . 'renverrait jamais, et un module de données n\'a pas vocation '
                    . 'à conserver des secrets.');
            }

            $type = (string)($champ['type'] ?? 'texte');

            // ── Champ calculé ────────────────────────────────────────────
            // Un champ « formule » n'existe PAS en base : il est recalculé à
            // chaque lecture. Le stocker le désynchroniserait dès qu'une valeur
            // dont il dépend change — le défaut classique des totaux figés.
            if ($type === 'formule') {
                $expr = trim((string)($champ['expression'] ?? ''));
                if ($expr === '') {
                    throw new RuntimeException("Champ « $cle » de type « formule » : "
                        . 'le champ « expression » est obligatoire. '
                        . 'Exemple : "{{record.quantite * record.prix}}"');
                }
                if (str_starts_with($expr, '{{') && str_ends_with($expr, '}}')) {
                    $expr = trim(substr($expr, 2, -2));
                }
                // Compilée MAINTENANT : une formule fautive empêche
                // l'installation, elle n'attend pas le premier affichage.
                $v = ExtExpression::valider($expr);
                if (!$v['ok']) {
                    throw new RuntimeException("Champ « $cle » : " . $v['erreur']);
                }
                $out[$cle] = [
                    'type'        => 'formule',
                    'libelle'     => mb_substr((string)($champ['libelle'] ?? ucfirst($cle)), 0, 60),
                    'expression'  => $expr,
                    'arbre'       => $v['arbre'],
                    'format'      => in_array($champ['format'] ?? '',
                                        ['nombre', 'monnaie', 'pourcent', 'texte', 'date',
                                         'heure', 'duree', 'octets', 'entier', 'distance',
                                         'surface', 'temperature', 'telephone'], true)
                                     ? $champ['format'] : 'texte',
                    'calcule'     => true,
                    'obligatoire' => false,
                    'unique'      => false,
                    'aide'        => mb_substr((string)($champ['aide'] ?? ''), 0, 160),
                ];
                continue;
            }

            // ── Lien vers une donnée du cœur ─────────────────────────────
            if ($type === 'lien') {
                $vers = (string)($champ['vers'] ?? '');
                if (!isset(self::CIBLES_LIEN[$vers])) {
                    throw new RuntimeException("Champ « $cle » de type « lien » : "
                        . "cible « $vers » inconnue. Cibles possibles : "
                        . implode(', ', array_keys(self::CIBLES_LIEN)));
                }
                $out[$cle] = [
                    'type'        => 'lien',
                    'vers'        => $vers,
                    'libelle'     => mb_substr((string)($champ['libelle']
                                        ?? self::CIBLES_LIEN[$vers]['libelle']), 0, 60),
                    'obligatoire' => !empty($champ['obligatoire']),
                    'unique'      => false,
                    'aide'        => mb_substr((string)($champ['aide'] ?? ''), 0, 160),
                ];
                if (!empty($champ['groupe'])) {
                    $out[$cle]['groupe'] = mb_substr((string)$champ['groupe'], 0, 40);
                }
                continue;
            }

            if (!isset(self::TYPES[$type])) {
                throw new RuntimeException("Champ « $cle » : type « $type » inconnu. "
                    . 'Types disponibles : ' . implode(', ', array_keys(self::TYPES)) . '.');
            }

            $propre = [
                'type'        => $type,
                'libelle'     => mb_substr((string)($champ['libelle'] ?? ucfirst($cle)), 0, 60),
                'obligatoire' => !empty($champ['obligatoire']),
                'unique'      => !empty($champ['unique']),
                'aide'        => mb_substr((string)($champ['aide'] ?? ''), 0, 160),
            ];

            // ── Bornes de date ───────────────────────────────────────────
            // « pas de date future » se déclare, plutôt que de se découvrir à
            // la relecture des saisies.
            if (in_array($type, ['date', 'horodatage'], true)) {
                foreach (['min_date', 'max_date'] as $b) {
                    if (empty($champ[$b])) continue;
                    $v = trim((string)$champ[$b]);
                    if ($v !== 'today' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                        throw new RuntimeException("Champ « $cle » : « $b » attend une date "
                            . 'AAAA-MM-JJ ou le mot « today ».');
                    }
                    $propre[$b] = $v;
                }
            }

            // ── Pas de saisie pour un nombre ─────────────────────────────
            if (in_array($type, ['entier', 'decimal', 'pourcentage'], true)
                && isset($champ['pas'])) {
                $propre['pas'] = max(0.0001, min((float)$champ['pas'], 1000000));
            }

            // ── Largeur du champ dans le formulaire ──────────────────────
            //
            // Déclarée par l'auteur, pas devinée. Sans elle, la grille attribue
            // une place identique à un code postal et à une adresse, ou laisse
            // chaque champ prendre sa largeur naturelle — et le formulaire
            // paraît composé au hasard.
            if (!empty($champ['largeur'])) {
                $L = ['petit' => 1, 'moyen' => 2, 'grand' => 3, 'pleine' => 12];
                $l = mb_strtolower((string)$champ['largeur']);
                if (!isset($L[$l])) {
                    throw new RuntimeException("Champ « $cle » : largeur « $l » inconnue. "
                        . 'Largeurs : ' . implode(', ', array_keys($L)));
                }
                $propre['largeur'] = $l;
            }

            // ── Champ absent de la liste, présent dans le formulaire ─────
            // Une fiche peut porter vingt champs sans que la liste devienne
            // illisible.
            if (!empty($champ['masque_liste'])) $propre['masque_liste'] = true;

            // ── Valeurs d'un choix multiple ──────────────────────────────
            if ($type === 'choix_multiple') {
                $v = $champ['valeurs'] ?? [];
                if (!is_array($v) || $v === []) {
                    throw new RuntimeException("Champ « $cle » de type « choix_multiple » : "
                        . 'listez ses « valeurs ».');
                }
                if (count($v) > 120) throw new RuntimeException("Trop de valeurs pour « $cle » (120 maximum).");
                $propre['valeurs'] = array_values(array_map(
                    fn($x) => mb_substr((string)$x, 0, 60), $v));
            }

            // ── Texte d'exemple dans le champ vide ───────────────────────
            if (!empty($champ['exemple'])) {
                $propre['exemple'] = mb_substr((string)$champ['exemple'], 0, 60);
            }

            // ── Saisie assistée par les valeurs déjà enregistrées ────────
            //
            // « Titulaire » revient sur des dizaines de lignes : le saisir à la
            // main invite les variantes — « M. Durand », « Durand », « durand »
            // — et une liste devient inexploitable. On propose donc ce qui
            // existe déjà, filtré au fil de la frappe.
            if (!empty($champ['suggestions'])
                && in_array($type, ['texte', 'texte_long'], true)) {
                $propre['suggestions'] = true;

                // Source externe facultative, en plus des valeurs déjà saisies.
                if (!empty($champ['suggestions_source'])) {
                    $src = (string)$champ['suggestions_source'];
                    if (!isset(self::SOURCES_SUGGESTION[$src])) {
                        throw new RuntimeException("Champ « $cle » : source de suggestions "
                            . "« $src » inconnue. Sources : "
                            . implode(', ', array_keys(self::SOURCES_SUGGESTION)));
                    }
                    $propre['suggestions_source'] = $src;
                }
            }

            // ── Champ affiché mais non modifiable ────────────────────────
            // Utile pour une donnée alimentée ailleurs : on la montre sans
            // laisser croire qu'on peut la corriger ici.
            if (!empty($champ['lecture_seule'])) $propre['lecture_seule'] = true;

            // ── Unité affichée après la valeur ───────────────────────────
            // « 12 » ne dit pas s'il s'agit de mois, de kWh ou d'euros.
            if (!empty($champ['unite'])) {
                $propre['unite'] = mb_substr((string)$champ['unite'], 0, 12);
            }

            // ── Format de saisie vérifié ─────────────────────────────────
            if (!empty($champ['format_saisie'])) {
                $f = (string)$champ['format_saisie'];
                if (!isset(self::FORMATS_SAISIE[$f])) {
                    throw new RuntimeException("Champ « $cle » : format de saisie « $f » "
                        . 'inconnu. Formats : ' . implode(', ', array_keys(self::FORMATS_SAISIE)));
                }
                if (!in_array($type, ['texte', 'texte_long'], true)) {
                    throw new RuntimeException("Champ « $cle » : un format de saisie ne "
                        . "s'applique qu'à un champ texte (celui-ci est « $type »).");
                }
                $propre['format_saisie'] = $f;
            }

            // ── Couleurs des valeurs d'un choix ──────────────────────────
            // Un statut « Non conforme » en rouge se repère d'un coup d'œil
            // dans une liste de cent lignes ; en noir, il faut la lire.
            if ($type === 'choix' && !empty($champ['couleurs']) && is_array($champ['couleurs'])) {
                $couleurs = [];
                foreach ($champ['couleurs'] as $valeur => $couleur) {
                    // Résolue en hexadécimal DÈS LA VALIDATION : le client
                    // reçoit une couleur, jamais un nom à retraduire. Deux
                    // tables de correspondance — une côté serveur, une côté
                    // navigateur — finissent toujours par diverger.
                    $couleurs[(string)$valeur] = self::couleur($couleur, "Champ « $cle »");
                }
                $propre['couleurs'] = $couleurs;
            }

            // ── Liste gérée par l'administrateur ─────────────────────────
            //
            // ── Une liste de choix, deux modes, et c'est tout ────────────
            //
            // Il y avait deux mécanismes concurrents : « valeurs » figeait la
            // liste dans le module, « liste » la déléguait à Configuration —
            // avec des options (ajout_autorise, libre) déclarées par l'auteur
            // que l'administrateur devait ensuite pouvoir contredire. D'où un
            // arbitrage à trois états, pour départager deux parties qui
            // revendiquaient le même réglage.
            //
            // Le modèle actuel supprime le litige plutôt que de l'arbitrer :
            //
            //   modifiable: 0 (défaut) — VERROUILLÉE. Les valeurs sont celles
            //     du module, exactement. La liste n'apparaît PAS dans
            //     Configuration : il n'y a rien à y régler, et une catégorie
            //     qu'on peut ouvrir sans effet est pire qu'une absente.
            //
            //   modifiable: 1 — OUVERTE. Les valeurs du module amorcent une
            //     catégorie, et l'administrateur en devient seul maître :
            //     valeurs, caractère obligatoire, saisie libre, droit d'ajout
            //     et de retrait. Le module ne déclare plus rien là-dessus, donc
            //     il n'y a plus rien à arbitrer.
            //
            // L'auteur décide QUOI et S'IL OUVRE. L'administrateur décide du
            // reste, mais seulement s'il a été ouvert.
            if ($type === 'choix') {
                $valeurs = $champ['valeurs'] ?? $champ['valeurs_initiales'] ?? [];

                /**
                 * ⚠️ UNE LISTE TENUE PAR CONFIGURATION N'A PAS DE VALEURS ICI.
                 *
                 * Exiger « valeurs » de TOUS les champs « choix » refusait le cas
                 * que le modèle prévoit pourtant explicitement : un champ dont la
                 * nomenclature vit dans Configuration, et que le module se
                 * contente de DÉSIGNER par « liste ». Le moteur sait le résoudre
                 * — resoudreListes() lit la catégorie et retombe sur les valeurs
                 * déclarées quand il n'y en a pas — mais la déclaration n'arrivait
                 * jamais jusque-là : elle était refusée à l'installation.
                 *
                 * Un module qui délègue sa nomenclature à l'administrateur n'a
                 * rien à recopier dans son JSON. L'y obliger, c'est créer la
                 * seconde liste que tout le reste du format s'applique à éviter :
                 * elle diverge dès le premier ajout fait depuis Configuration.
                 *
                 * Reste refusé : un « choix » sans valeurs ET sans liste — il ne
                 * proposerait rien, et aucune saisie ne serait possible.
                 */
                $adosseeAConfiguration = !empty($champ['liste'])
                    || (array_key_exists('modifiable', $champ) && !empty($champ['modifiable']));

                if ((!is_array($valeurs) || $valeurs === []) && !$adosseeAConfiguration) {
                    throw new RuntimeException("Champ « $cle » de type choix : "
                        . '« valeurs » est obligatoire — c\'est la liste de base. '
                        . 'Elle peut être écrite ici, tenue à part : '
                        . '"valeurs": { "$ref": "parties/ma-liste.json" }, ou confiée '
                        . 'à Configuration en nommant sa catégorie : "liste": "MaCategorie".');
                }
                if (!is_array($valeurs)) $valeurs = [];
                if (count($valeurs) > self::MAX_CHOIX) {
                    throw new RuntimeException("Champ « $cle » : trop de valeurs ("
                        . self::MAX_CHOIX . ' au maximum).');
                }
                $valeurs = array_values(array_map(
                    fn($v) => mb_substr((string)$v, 0, 60), $valeurs));

                // « liste » désignait déjà une catégorie de Configuration :
                // sa présence vaut ouverture, sans quoi les modules publiés
                // avant ce changement se retrouveraient verrouillés du jour au
                // lendemain — leurs administrateurs perdraient la main sur des
                // nomenclatures qu'ils tiennent depuis des mois.
                $modifiable = array_key_exists('modifiable', $champ)
                    ? (int)(bool)$champ['modifiable']
                    : (!empty($champ['liste']) ? 1 : 0);

                $propre['valeurs']    = $valeurs;
                $propre['modifiable'] = $modifiable;

                if ($modifiable === 1) {
                    // Catégorie explicite, ou dérivée du module et du champ.
                    // La dérivation évite qu'un auteur distrait partage sans le
                    // vouloir la nomenclature d'un autre module.
                    $cat = (string)($champ['liste'] ?? $champ['categorie'] ?? '');
                    if ($cat === '') {
                        $cat = 'ext_' . preg_replace('/[^A-Za-z0-9]/', '_',
                            (string)($d['identifiant'] ?? 'module')) . '_' . $cle;
                    }
                    if (!preg_match('/^[A-Za-z][A-Za-z0-9_ -]{0,40}$/', $cat)) {
                        throw new RuntimeException("Champ « $cle » : nom de liste "
                            . "« $cat » invalide.");
                    }
                    $propre['liste'] = $cat;
                    $propre['max']   = 120;
                }

                /**
                 * ── Droit d'ajout et saisie libre ────────────────────────
                 *
                 * ⚠️ CES DEUX DRAPEAUX ÉTAIENT RANGÉS SOUS « amorce » ET NULLE
                 * PART AILLEURS — or TROIS endroits les lisent sur le CHAMP :
                 * le moteur (ajouterValeurListe), le client (bouton « + » et
                 * saisie hors liste) et l'épreuve fonctionnelle. Aucun ne les
                 * trouvait. Le droit d'ajout était donc déclarable, validé,
                 * affiché à l'installation… et systématiquement refusé.
                 *
                 * POURQUOI LE CHAMP ET NON LA CATÉGORIE
                 * Deux champs peuvent désigner la MÊME catégorie avec des
                 * droits différents : un champ « Catégorie » où le gestionnaire
                 * complète la nomenclature, et un champ « Libre » qui puise
                 * dans la même liste sans avoir le droit de l'enrichir. Un
                 * droit porté par la catégorie ne saurait pas les distinguer —
                 * il ouvrirait les deux, ou fermerait les deux.
                 *
                 * « amorce » demeure, et garde son rôle : régler la catégorie
                 * le jour où elle est créée. Les deux ne font pas double emploi,
                 * ils ne répondent pas à la même question — « ce champ peut-il
                 * ajouter ? » et « comment naît la catégorie ? ».
                 */
                $propre['ajout_autorise'] = !empty($champ['ajout_autorise']);
                $propre['libre']          = !empty($champ['libre']);

                if ($modifiable === 1) {
                    $propre['amorce'] = [
                        'ajout' => $propre['ajout_autorise'] ? 1 : 0,
                        'libre' => $propre['libre'] ? 1 : 0,
                    ];
                }
            }

            /**
             * ── Taille du champ, en pixels ───────────────────────────────
             *
             * Les largeurs étaient figées dans le CSS : « dp-large » ou rien.
             * Une immatriculation recevait donc la même boîte qu'un motif de
             * cent vingt caractères, et un auteur de module n'avait aucun moyen
             * d'y changer quoi que ce soit — sinon en éditant la feuille de
             * style de Larka, ce qu'un module ne fait pas.
             *
             * Bornes : 40 à 1200 px de large, 40 à 800 de haut. En deçà le
             * champ devient inutilisable, au-delà il sort de la colonne. On
             * préfère ramener dans les bornes plutôt que refuser : une taille
             * excessive est une maladresse, pas une faute.
             */
            if (isset($champ['largeur_px'])) {
                $propre['largeur_px'] = max(40, min((int)$champ['largeur_px'], 1200));
            }
            if (isset($champ['hauteur_px'])) {
                $propre['hauteur_px'] = max(40, min((int)$champ['hauteur_px'], 800));
            }

            if (in_array($type, ['entier', 'decimal'], true)) {
                $propre['min'] = isset($champ['min']) ? (float)$champ['min'] : null;
                $propre['max'] = isset($champ['max']) ? (float)$champ['max'] : null;
            } elseif ($type === 'date') {
                /**
                 * Plancher et plafond d'une date, en JOURS depuis aujourd'hui.
                 *
                 * « min » et « max » n'existaient que pour les nombres : une
                 * date acceptait n'importe quoi. Impossible d'exprimer « pas
                 * avant la semaine prochaine » — or c'est la règle la plus
                 * courante d'une réservation : on ne prend pas une place pour
                 * ce matin.
                 *
                 * Exprimé en jours relatifs, pas en date fixe : une borne
                 * absolue serait périmée le mois suivant, et personne ne pense
                 * à la mettre à jour.
                 */
                $propre['min_jours'] = isset($champ['min_jours'])
                    ? max(-3650, min((int)$champ['min_jours'], 3650)) : null;
                $propre['max_jours'] = isset($champ['max_jours'])
                    ? max(-3650, min((int)$champ['max_jours'], 3650)) : null;
                if ($propre['min_jours'] !== null && $propre['max_jours'] !== null
                    && $propre['min_jours'] > $propre['max_jours']) {
                    throw new RuntimeException("Champ « $cle » : « min_jours » dépasse "
                        . '« max_jours » — aucune date ne satisferait les deux.');
                }
            } elseif (in_array($type, ['texte', 'texte_long'], true)) {
                $propre['max'] = max(1, min((int)($champ['max'] ?? 200),
                                            $type === 'texte_long' ? 5000 : 500));
            }

            if (isset($champ['defaut']) && is_scalar($champ['defaut'])) {
                $propre['defaut'] = $champ['defaut'];
            }
            // ── Visibilité conditionnelle ────────────────────────────────
            // Validée ici, contre les champs du MÊME jeu : une condition qui
            // vise un champ inexistant échoue à l'installation, pas au premier
            // affichage devant l'utilisateur.
            if (isset($champ['visible_si'])) {
                $propre['visible_si'] = $champ['visible_si'];   // compilé en 2e passe
            }

            // Groupe d'affichage : purement visuel, il n'entre ni en base ni
            // dans aucun contrôle. Sans lui, une fiche de quinze champs est un
            // mur ; avec, elle se lit par blocs.
            if (!empty($champ['groupe'])) {
                $propre['groupe'] = mb_substr((string)$champ['groupe'], 0, 40);
            }

            $out[$cle] = $propre;
        }

        // ── Valeurs par défaut dynamiques ────────────────────────────────
        // « {{today}} » plutôt qu'une date figée dans le JSON : le moteur
        // d'expressions est déjà là, autant s'en servir. Compilée maintenant
        // pour qu'une faute échoue à l'installation.
        foreach ($out as $cle => $champ) {
            $d = $champ['defaut'] ?? null;
            if (!is_string($d) || !str_contains($d, '{{')) continue;
            $nue = trim($d);
            if (str_starts_with($nue, '{{') && str_ends_with($nue, '}}')) {
                $r = ExtExpression::valider(trim(substr($nue, 2, -2)));
                if (!$r['ok']) {
                    throw new RuntimeException("Champ « $cle », valeur par défaut : "
                        . $r['erreur']);
                }
                $out[$cle]['defaut_arbre'] = $r['arbre'];
            }
        }

        // Deuxième passe pour les conditions de visibilité : un champ peut en
        // viser un autre déclaré plus bas dans le JSON. Les compiler au fil de
        // l'eau aurait rejeté des déclarations valides selon l'ordre d'écriture.
        foreach ($out as $cle => $champ) {
            if (!isset($champ['visible_si'])) continue;
            $v = ExtCondition::valider($champ['visible_si'], $out);
            if (!$v['ok']) {
                throw new RuntimeException("Champ « $cle », visible_si : " . $v['erreur']);
            }
            $out[$cle]['visible_si'] = $v['arbre'];
        }

        return [
            'libelle'  => mb_substr((string)($jeu['libelle'] ?? ucfirst($nom)), 0, 60),
            'libelle_pluriel' => mb_substr((string)($jeu['libelle_pluriel']
                                ?? ($jeu['libelle'] ?? ucfirst($nom)) . 's'), 0, 60),
            'champs'   => $out,
        ];
    }

    private static function validerPage(mixed $page, array $jeux): array
    {
        if (!is_array($page)) throw new RuntimeException('Page : objet attendu.');

        $cle = (string)($page['cle'] ?? '');
        if (!preg_match('/^[a-z0-9_]{1,30}$/', $cle)) {
            throw new RuntimeException("Page : clé « $cle » invalide.");
        }
        $titre = (string)($page['titre'] ?? '');
        if ($titre === '' || mb_strlen($titre) > 40) {
            throw new RuntimeException('Page : « titre » obligatoire, 40 caractères maximum.');
        }

        $roles = $page['roles'] ?? ['Gestionnaire'];
        $connus = ['Gestionnaire', 'Admin', 'Visionneur', 'Demandeur'];
        foreach ((array)$roles as $r) {
            if (!in_array($r, $connus, true)) {
                throw new RuntimeException("Page : rôle inconnu « $r ».");
            }
        }

        $vue = $page['vue'] ?? [];

        // ── Vue « apparence » ────────────────────────────────────────────
        //
        // Un module d'habillage n'a pas de données à présenter : son écran
        // montre les thèmes installés et les variables de SON vocabulaire.
        // C'est Larka qui le dessine — le module dit seulement « je veux cet
        // écran-là », comme pour une tuile ou un repère de plan.
        //
        // Sans ce type, un module ne pouvait pas offrir d'écran sans déclarer
        // un jeu de données factice pour contourner le contrôle.
        if (is_array($vue) && ($vue['type'] ?? '') === 'apparence') {
            return [
                'cle'    => self::nomTechnique((string)($page['cle'] ?? ''), 'page'),
                'titre'  => mb_substr((string)($page['titre'] ?? 'Apparence'), 0, 40),
                'icone'  => preg_replace('/[^a-z0-9\-]/', '',
                                strtolower((string)($page['icone'] ?? 'palette'))),
                'roles'  => array_values((array)$roles),
                'vue'    => ['type' => 'apparence'],
            ];
        }

        /**
         * ── Type de vue ──────────────────────────────────────────────────
         *
         * ⚠️ IL Y AVAIT TROIS CONTRÔLES POUR UNE SEULE QUESTION, ET UNE
         * BRANCHE MORTE ENTRE LES DEUX.
         *
         * Le premier refusait tout type hors catalogue. Le deuxième traduisait
         * « cartes » en « liste » avec un drapeau `_cartes` — mais « cartes »
         * venait d'être refusé quatre lignes plus haut : cette branche n'était
         * jamais atteinte, et le drapeau `cartes` ressortait donc toujours à
         * faux. Aucun client ne le lit d'ailleurs. Le troisième refaisait le
         * premier, avec un message qui parlait de « cartes » — sans rapport
         * avec la vue effectivement refusée.
         *
         * Du code qui prétend offrir un rendu qu'il ne produit pas est pire
         * qu'un rendu absent : on le lit, on l'écrit dans un module, et rien
         * n'arrive. Un seul contrôle, un seul message, et le catalogue fait foi.
         *
         * « calendrier » : une grille jour × créneau, qui se lit d'un coup
         * d'œil et s'imprime — ce qu'une liste de deux cents lignes ne fait
         * pas, quelle que soit la façon dont on la trie.
         */
        $TYPES_VUE = ['liste', 'calendrier'];
        if (!is_array($vue) || !in_array($vue['type'] ?? 'liste', $TYPES_VUE, true)) {
            throw new RuntimeException('Page « ' . $cle . ' » : type de vue « '
                . (is_array($vue) ? (string)($vue['type'] ?? '?') : '?')
                . ' » inconnu. Types : ' . implode(', ', $TYPES_VUE) . '.');
        }

        $source = (string)($vue['source'] ?? '');
        if (!isset($jeux[$source])) {
            throw new RuntimeException("Page : source « $source » inconnue. "
                . 'Jeux déclarés : ' . implode(', ', array_keys($jeux)) . '.');
        }
        $champs = $jeux[$source]['champs'];

        $verifierChamps = function (mixed $liste, string $quoi) use ($champs, $source): array {
            $out = [];
            foreach ((array)$liste as $c) {
                $nom = is_array($c) ? (string)($c['champ'] ?? '') : (string)$c;
                if (!isset($champs[$nom])) {
                    throw new RuntimeException("Page, $quoi : le champ « $nom » n'existe pas "
                        . "dans « $source ». Champs : " . implode(', ', array_keys($champs)) . '.');
                }
                $out[] = is_array($c) ? array_merge($c, ['champ' => $nom]) : ['champ' => $nom];
            }
            return $out;
        };

        $colonnes = $vue['colonnes'] ?? array_slice(array_keys($champs), 0, 6);
        $actions = array_values(array_intersect(
            (array)($vue['actions'] ?? ['creer', 'modifier', 'supprimer', 'exporter']),
            ['creer', 'modifier', 'supprimer', 'exporter']));

        $tri = [];
        foreach ((array)($vue['tri'] ?? []) as $c => $sens) {
            if (!isset($champs[$c])) {
                throw new RuntimeException("Page, tri : champ « $c » inconnu.");
            }
            $tri[$c] = strtoupper((string)$sens) === 'DESC' ? 'DESC' : 'ASC';
        }

        // ── Totaux en pied de liste ──────────────────────────────────────
        // Sur un champ numérique uniquement : totaliser une colonne de texte
        // n'a pas de sens, et l'accepter afficherait un zéro trompeur.
        $totaux = [];
        foreach (array_column($verifierChamps($vue['totaux'] ?? [], 'totaux'), 'champ') as $c) {
            $t = $champs[$c]['type'] ?? '';
            if (!in_array($t, ['entier', 'decimal', 'formule'], true)) {
                throw new RuntimeException("Page « $cle » : « $c » ne peut pas être totalisé "
                    . "(type « $t »). Seuls les nombres et les formules le peuvent.");
            }
            $totaux[] = $c;
        }

        /**
         * ── Filtres ──────────────────────────────────────────────────────
         *
         * ⚠️ UN FILTRE N'EST DESSINÉ QUE POUR « choix » ET « booleen ».
         * L'écran construit un menu déroulant à partir des valeurs possibles ;
         * un texte, une date ou un champ calculé n'en ont pas. Le filtre était
         * accepté à la validation, puis le client ne dessinait rien : l'auteur
         * croyait avoir posé un filtre, l'utilisateur ne voyait aucun contrôle,
         * et personne ne pouvait dire pourquoi.
         *
         * On refuse à la validation, là où l'auteur peut encore corriger.
         */
        $verifierFiltres = static function (array $liste) use ($verifierChamps, $champs, $cle): array {
            $out = $verifierChamps($liste, 'filtres');
            foreach ($out as $f) {
                $t = $champs[$f['champ']]['type'] ?? '';
                if ($t !== 'choix' && $t !== 'booleen') {
                    throw new RuntimeException("Page « $cle » : filtre sur « {$f['champ']} » "
                        . "de type « $t ». Un filtre se dessine à partir de valeurs connues : "
                        . 'seuls « choix » et « booleen » en ont. Pour un texte ou une date, '
                        . 'utilisez la recherche ou le tri.');
                }
            }
            return $out;
        };

        // ── Regroupement ─────────────────────────────────────────────────
        $grouper = null;
        if (!empty($vue['grouper_par'])) {
            $g = array_column($verifierChamps([$vue['grouper_par']], 'grouper_par'), 'champ');
            $grouper = $g[0] ?? null;
            if ($grouper !== null && ($champs[$grouper]['type'] ?? '') === 'formule') {
                throw new RuntimeException("Page « $cle » : on ne regroupe pas sur un champ "
                    . 'calculé — il n\'existe pas en base, le regroupement ne porterait '
                    . 'que sur la page affichée.');
            }
        }

        // ── Mise en évidence conditionnelle des lignes ───────────────────
        // Une échéance dépassée doit sauter aux yeux dans la liste, sans qu'on
        // ait à lire la colonne.
        $surlignage = [];
        foreach ((array)($vue['surligner'] ?? []) as $regle) {
            if (!is_array($regle)) continue;
            $couleur = self::couleur($regle['couleur'] ?? 'orange',
                                     "Page « $cle », surlignage");
            $c = ExtCondition::valider($regle['si'] ?? null, $champs);
            if (!$c['ok']) {
                throw new RuntimeException("Page « $cle », surlignage : " . $c['erreur']);
            }
            $surlignage[] = ['si' => $c['arbre'], 'couleur' => $couleur,
                             'libelle' => mb_substr((string)($regle['libelle'] ?? ''), 0, 40)];
            if (count($surlignage) >= 20) break;
        }

        // ── Mise en page du formulaire ───────────────────────────────────
        $layout = self::validerLayout($vue['layout'] ?? null, $champs, $cle);

        /**
         * ⚠️ DEUX LISTES QUI DÉSIGNENT LES MÊMES CHAMPS FINISSENT PAR DIVERGER.
         *
         * « champs_formulaire » choisit et ordonne les champs saisissables ; un
         * layout les choisit AUSSI, en les plaçant. Les accepter ensemble
         * obligerait à trancher en silence lequel gagne — et l'auteur qui
         * ajoute un champ à l'un sans l'autre ne comprendrait pas pourquoi il
         * n'apparaît pas.
         *
         * On refuse donc la combinaison, en nommant celle qu'il faut retirer.
         * C'est le même principe que pour les listes de choix : supprimer le
         * litige plutôt que l'arbitrer.
         */
        if ($layout !== null && ($vue['champs_formulaire'] ?? []) !== []) {
            throw new RuntimeException("Page « $cle » : « layout » et "
                . '« champs_formulaire » décrivent tous deux les champs du '
                . 'formulaire. Gardez-en un seul — le layout place les champs et '
                . 'suffit donc à les choisir ; « champs_formulaire » reste utile '
                . 'quand on ne veut pas dessiner de mise en page.');
        }

        /**
         * ── Un formulaire qu'on ne peut pas soumettre ────────────────────
         *
         * Une mise en page CHOISIT les champs autant qu'elle les dispose : ce
         * qu'elle ne place pas n'apparaît nulle part. Oublier un champ
         * obligatoire produit alors le pire des écrans — celui qui s'affiche
         * correctement, se remplit entièrement, et refuse l'enregistrement sur
         * une valeur que l'utilisateur n'avait aucun moyen de saisir.
         *
         * Le serveur a raison de refuser : le champ est obligatoire. C'est la
         * déclaration qui est fautive, et elle doit échouer ICI, devant son
         * auteur, plutôt que là-bas, devant l'utilisateur.
         *
         * Un champ obligatoire pourvu d'une valeur par DÉFAUT reste légitime à
         * omettre : c'est ainsi qu'un demandeur crée une fiche dont le statut
         * vaut « Demandé » sans jamais voir ce champ. Le contrôle ne vise donc
         * que les obligatoires sans défaut.
         */
        if ($layout !== null) {
            $places = $layout['_champs_places'];
            $orphelins = [];
            foreach ($champs as $nom => $c) {
                if (($c['type'] ?? '') === 'formule') continue;
                if (empty($c['obligatoire'])) continue;
                if (in_array($nom, $places, true)) continue;
                if (array_key_exists('defaut', $c)) continue;
                $orphelins[] = $nom;
            }
            if ($orphelins !== []) {
                throw new RuntimeException("Page « $cle » : le formulaire ne place "
                    . 'pas « ' . implode(' », « ', $orphelins) . ' », '
                    . (count($orphelins) > 1 ? 'qui sont obligatoires' : 'qui est obligatoire')
                    . ' et sans valeur par défaut. L\'écran s\'afficherait, se '
                    . 'remplirait, et l\'enregistrement serait refusé sur une valeur '
                    . 'que personne ne pouvait saisir. Placez '
                    . (count($orphelins) > 1 ? 'ces champs' : 'ce champ')
                    . ' dans la mise en page, ou donnez-leur un « defaut ».');
            }
        }

        return [
            'cle'    => $cle,
            'titre'  => $titre,
            'icone'  => preg_replace('/[^a-z0-9\-]/', '', strtolower((string)($page['icone'] ?? 'puzzle'))),
            'roles'  => array_values((array)$roles),
            'vue'    => [
                // Le type était écrit en dur : « calendrier » validait puis
                // ressortait en « liste », et le client dessinait un tableau.
                // Une sortie qui contredit son entrée est pire qu'un refus.
                'type'      => $vue['type'] ?? 'liste',
                'source'    => $source,
                'colonnes'  => $verifierChamps($colonnes, 'colonnes'),
                'recherche' => array_column($verifierChamps($vue['recherche'] ?? [], 'recherche'), 'champ'),
                'filtres'   => $verifierFiltres($vue['filtres'] ?? []),
                'tri'       => $tri,
                'actions'   => $actions,
                'totaux'      => $totaux,
                'grouper_par' => $grouper,
                'surligner'   => $surlignage,
                // L'utilisateur peut trier en cliquant les en-têtes. Par défaut
                // oui : c'est un réflexe acquis, l'interdire surprend.
                'tri_utilisateur' => ($vue['tri_utilisateur'] ?? true) !== false,
                // Colonnes du FORMULAIRE : c'est l'auteur qui sait combien de
                // champs tiennent côte à côte dans sa fiche.
                'colonnes_formulaire' => max(1, min((int)($vue['colonnes_formulaire'] ?? 3), 6)),
                /**
                 * Champs du FORMULAIRE, et dans cet ordre.
                 *
                 * ⚠️ IL N'Y AVAIT AUCUN MOYEN DE RESTREINDRE UN FORMULAIRE.
                 * « colonnes » choisit ce que la LISTE affiche ;
                 * « colonnes_formulaire » est le nombre de colonnes de la mise
                 * en page (1 à 6). Rien ne disait quels CHAMPS proposer à la
                 * saisie : le formulaire prenait tout le jeu de données.
                 *
                 * Un module ne pouvait donc pas offrir deux écrans au même jeu
                 * — un formulaire court pour le demandeur, complet pour le
                 * gestionnaire. Les deux montraient la même chose, y compris
                 * les champs que seul l'arbitre devrait renseigner.
                 *
                 * Vide = tous les champs saisissables, comme avant.
                 */
                /**
                 * Boutons qui posent une valeur, directement dans la liste.
                 *
                 * Arbitrer une demande — accepter, refuser — passait par
                 * « Modifier », un formulaire de douze champs, un menu
                 * déroulant et un enregistrement. Pour changer un mot. Sur
                 * trente demandes, l'écran devient un travail de saisie là où
                 * il devrait être une suite de décisions.
                 *
                 * Forme : [{"champ":"statut","valeur":"Accepté","libelle":"Accepter","couleur":"#16a34a"}]
                 * Le champ doit être un « choix », et la valeur l'une des
                 * siennes : un bouton qui poserait une valeur hors liste
                 * créerait une donnée que les filtres ne retrouveraient pas.
                 */
                'actions_rapides' => self::validerActionsRapides(
                    $vue['actions_rapides'] ?? [], $champs, $cle),
                /**
                 * Grille du calendrier : quel champ porte le jour, lequel le
                 * créneau horaire, lequel la ressource occupée.
                 *
                 * Les trois sont obligatoires pour ce type de vue : sans eux,
                 * il n'y a pas de grille à dessiner, seulement une liste
                 * déguisée.
                 */
                'calendrier' => self::validerCalendrier(
                    $vue['calendrier'] ?? [], $champs, $cle,
                    ($vue['type'] ?? 'liste') === 'calendrier'),
                /**
                 * Largeur des colonnes du tableau, en pixels.
                 *
                 * Forme : {"date": 110, "demandeur": 200}. Une colonne absente
                 * garde son comportement automatique. Sans cela, un tableau
                 * étalait une date sur trois cents pixels et serrait un nom sur
                 * soixante, selon ce que la première ligne contenait.
                 */
                'largeurs' => self::validerLargeurs($vue['largeurs'] ?? [], $champs, $cle),
                'champs_formulaire' => array_column(
                    $verifierChamps($vue['champs_formulaire'] ?? [], 'champs_formulaire'),
                    'champ'),
                // « par_page » était accepté par la documentation, écrit par
                // les auteurs… et jeté ici. Le moteur retombait donc toujours
                // sur 50. Un champ qu'on documente sans le conserver est pire
                // qu'un champ absent : l'auteur croit l'avoir réglé.
                'par_page'    => max(5, min((int)($vue['par_page'] ?? 50), 200)),
                /**
                 * ── Mise en page du formulaire ───────────────────────────
                 *
                 * Facultative. Absente, le formulaire garde exactement le
                 * comportement qu'il avait : les groupes de champs, le nombre
                 * de colonnes et les largeurs déclarées suffisent au cas
                 * courant, et aucun module publié n'a à être retouché.
                 *
                 * Présente, elle DÉCIDE de la disposition — quels champs, où,
                 * et de quelle taille.
                 */
                'layout' => $layout,
            ],
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  MISE EN PAGE DÉCLARATIVE
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Valide la mise en page d'un FORMULAIRE.
     *
     * Rend une structure normalisée — tout y est entier borné, mot-clé d'un
     * catalogue fermé, nom de champ vérifié, couleur résolue ou condition
     * compilée. Aucune chaîne libre n'en ressort qui puisse devenir du style ou
     * du balisage : le client n'a plus qu'à disposer ce qu'on lui décrit.
     *
     * Absente, la mise en page rend `null` et le formulaire garde son
     * comportement d'avant — les modules publiés ne changent pas d'un pixel.
     */
    public static function validerLayout(mixed $layout, array $champs, string $page): ?array
    {
        if ($layout === null) return null;
        if (!is_array($layout) || $layout === []) {
            throw new RuntimeException("Page « $page » : « layout » attend un objet, "
                . 'par exemple {"type":"grille","colonnes":12,"composants":[…]}.');
        }

        $compteur = 0;
        $places   = [];
        $out = self::validerConteneurLayout($layout, $champs, $page, 0,
                                            $compteur, $places, true);
        $out['_champs_places'] = array_keys($places);
        return $out;
    }

    /**
     * Tous les fichiers image qu'une déclaration DÉSIGNE — logo compris.
     *
     * Sert au constructeur de paquet : un module qui déclare une image absente
     * de ses assets s'installe sans bruit et affiche un cadre vide. Personne ne
     * voit ce qui manque, et surtout pas l'auteur, qui a le fichier sur son
     * disque. On vérifie donc à la CONSTRUCTION, là où le dossier est sous la
     * main.
     */
    public static function imagesDeclarees(array $d): array
    {
        $out = [];
        if (!empty($d['logo'])) $out[] = (string)$d['logo'];

        /**
         * Le relevé doit fonctionner sur une déclaration BRUTE comme sur une
         * déclaration validée : le constructeur de paquet appelle cette méthode
         * avant toute validation — c'est justement son intérêt, puisqu'il veut
         * refuser avant de fabriquer. Un composant validé porte « nature » ;
         * un composant brut n'a que sa clé « image ».
         */
        $parcourir = static function ($noeud) use (&$parcourir, &$out): void {
            if (!is_array($noeud)) return;
            if (isset($noeud['image']) && is_string($noeud['image'])
                && preg_match(self::LAYOUT_IMAGE_CHEMIN, trim($noeud['image']))) {
                $out[] = trim($noeud['image']);
            }
            foreach ($noeud as $v) if (is_array($v)) $parcourir($v);
        };
        foreach ($d['pages'] ?? [] as $p) {
            if (!empty($p['vue']['layout'])) $parcourir($p['vue']['layout']);
        }
        return array_values(array_unique($out));
    }

    /**
     * ⚠️ TOUT CE QUI N'EST PAS AU CATALOGUE EST REFUSÉ.
     *
     * C'est cette règle — et non une liste d'interdits — qui tient la promesse
     * « aucun code ». Interdire « onclick », « style » et « html » nommément
     * laisserait passer le suivant ; n'accepter que ce qu'on sait dessiner ne
     * laisse rien passer du tout. Une propriété inconnue échoue donc à
     * l'installation, là où l'auteur peut encore corriger, plutôt que d'être
     * ignorée en silence — ce qui lui ferait croire à un réglage appliqué.
     */
    private static function refuserClesInconnues(array $obj, array $permises,
                                                 string $quoi, string $page): void
    {
        $inconnues = array_diff(array_keys($obj), $permises);
        if ($inconnues === []) return;
        sort($inconnues);
        throw new RuntimeException("Page « $page », $quoi : propriété(s) inconnue(s) « "
            . implode(' », « ', $inconnues) . " ». Propriétés acceptées : "
            . implode(', ', $permises) . '. Une mise en page décrit une disposition ; '
            . 'elle ne transporte ni style libre, ni balisage, ni comportement.');
    }

    /** Un entier borné. Hors bornes on ramène ; d'un autre type on refuse. */
    private static function layoutEntier(mixed $v, int $min, int $max, int $defaut,
                                         string $quoi, string $page): int
    {
        if ($v === null || $v === '') return $defaut;
        if (is_bool($v) || !is_numeric($v)) {
            throw new RuntimeException("Page « $page », layout : « $quoi » attend un "
                . 'nombre entier, reçu « '
                . (is_scalar($v) ? (string)$v : gettype($v)) . ' ».');
        }
        return max($min, min((int)$v, $max));
    }

    /**
     * Résout une dimension déclarée par un NOM ou par des pixels.
     *
     * Rend la valeur en pixels, ou null si rien n'est déclaré — auquel cas
     * l'appelant garde son défaut. Déclarer les deux formes est refusé : ce
     * serait deux vérités pour une seule dimension, et il faudrait choisir en
     * silence laquelle l'emporte.
     *
     * @param string $nomCle  la clé nommée   (« espacement », « taille »…)
     * @param string $pxCle   la clé en pixels (« gap_px », « hauteur_px »…)
     */
    private static function layoutDimension(array $obj, string $nomCle, string $pxCle,
            array $catalogue, int $min, int $max, string $page): ?int
    {
        $aNom = array_key_exists($nomCle, $obj) && $obj[$nomCle] !== null && $obj[$nomCle] !== '';
        $aPx  = array_key_exists($pxCle, $obj)  && $obj[$pxCle]  !== null && $obj[$pxCle]  !== '';

        if ($aNom && $aPx) {
            throw new RuntimeException("Page « $page », layout : « $nomCle » et « $pxCle » "
                . 'déclarent la même dimension. Gardez-en un seul — le nom pour le cas '
                . 'courant, les pixels quand il faut une valeur précise.');
        }
        if ($aPx) {
            return self::layoutEntier($obj[$pxCle], $min, $max, $min, $pxCle, $page);
        }
        if (!$aNom) return null;

        $k = mb_strtolower(trim((string)$obj[$nomCle]));
        if (!array_key_exists($k, $catalogue)) {
            throw new RuntimeException("Page « $page », layout : « $nomCle » — valeur "
                . "« $k » inconnue. Valeurs : " . implode(', ', array_keys($catalogue))
                . ", ou « $pxCle » pour une taille précise.");
        }
        // « normale » vaut délibérément null : le champ garde sa hauteur
        // naturelle, ce qui n'est pas la même chose qu'une hauteur imposée.
        return $catalogue[$k];
    }

    /** Un alignement, résolu en mot-clé CSS. Hors catalogue : refusé. */
    private static function layoutAlignement(mixed $v, array $catalogue, string $quoi,
                                             string $page, string $defaut): string
    {
        if ($v === null || $v === '') return $defaut;
        $k = mb_strtolower(trim((string)$v));
        if (!isset($catalogue[$k])) {
            throw new RuntimeException("Page « $page », layout : « $quoi » — alignement "
                . "« $k » inconnu. Valeurs : " . implode(', ', array_keys($catalogue)) . '.');
        }
        return $catalogue[$k];
    }

    /**
     * Un conteneur : la grille racine, ou une section qui en contient une autre.
     * Les deux ont des colonnes, des espacements et des composants — c'est la
     * même chose à deux échelles, donc le même code.
     */
    private static function validerConteneurLayout(array $c, array $champs, string $page,
            int $profondeur, int &$compteur, array &$places, bool $racine): array
    {
        if ($profondeur > self::LAYOUT_MAX_PROFONDEUR) {
            throw new RuntimeException("Page « $page » : sections imbriquées sur plus de "
                . self::LAYOUT_MAX_PROFONDEUR . ' niveaux. Au-delà, un formulaire ne se '
                . 'lit plus — et une imbrication sans fin ne se dessine pas.');
        }

        self::refuserClesInconnues($c,
            $racine ? self::LAYOUT_CLES_RACINE : self::LAYOUT_CLES_SECTION,
            $racine ? 'layout' : 'section', $page);

        $out = [];
        if ($racine) {
            $type = (string)($c['type'] ?? 'grille');
            if (!in_array($type, self::LAYOUT_TYPES, true)) {
                throw new RuntimeException("Page « $page » : mise en page de type « $type » "
                    . 'inconnue. Types : ' . implode(', ', self::LAYOUT_TYPES) . '.');
            }
            $out['type'] = $type;
        }

        $colonnes = self::layoutEntier($c['colonnes'] ?? null, 1,
            self::LAYOUT_MAX_COLONNES, 12, 'colonnes', $page);
        $out['colonnes'] = $colonnes;

        // « espacement » (un nom) ou « gap_px » (une valeur) — jamais les deux.
        $gap = self::layoutDimension($c, 'espacement', 'gap_px',
            self::LAYOUT_ESPACEMENTS, 0, self::LAYOUT_MAX_GAP_PX, $page) ?? 12;
        $out['gap_x_px'] = self::layoutEntier($c['gap_x_px'] ?? null, 0,
            self::LAYOUT_MAX_GAP_PX, $gap, 'gap_x_px', $page);
        $out['gap_y_px'] = self::layoutEntier($c['gap_y_px'] ?? null, 0,
            self::LAYOUT_MAX_GAP_PX, $gap, 'gap_y_px', $page);

        if ($racine) {
            $out['marges_px'] = self::layoutDimension($c, 'marges', 'marges_px',
                self::LAYOUT_MARGES, 0, self::LAYOUT_MAX_GAP_PX, $page) ?? 0;
            $out['responsive'] = self::validerResponsiveLayout($c['responsive'] ?? [], $page);
        }

        $out['alignement_h'] = self::layoutAlignement($c['alignement_h'] ?? null,
            self::LAYOUT_ALIGN_H, 'alignement_h', $page, 'stretch');
        $out['alignement_v'] = self::layoutAlignement($c['alignement_v'] ?? null,
            self::LAYOUT_ALIGN_V, 'alignement_v', $page, 'stretch');

        $liste = $c['composants'] ?? [];
        if (!is_array($liste)) {
            throw new RuntimeException("Page « $page », layout : « composants » attend "
                . 'un tableau.');
        }
        $out['composants'] = [];
        foreach ($liste as $el) {
            $out['composants'][] = self::validerComposantLayout(
                $el, $champs, $page, $colonnes, $profondeur, $compteur, $places);
        }

        if ($out['composants'] === []) {
            throw new RuntimeException("Page « $page », "
                . ($racine ? 'layout' : 'section')
                . ' : aucun composant. '
                . ($racine
                    ? 'Une mise en page vide masquerait tout le formulaire.'
                    : 'Une section vide dessine un cadre autour de rien.'));
        }
        return $out;
    }

    /** Un composant : un champ, une section, un texte ou un espace. */
    private static function validerComposantLayout(mixed $el, array $champs, string $page,
            int $colonnes, int $profondeur, int &$compteur, array &$places): array
    {
        if (!is_array($el) || $el === []) {
            throw new RuntimeException("Page « $page », layout : un composant est un objet. "
                . 'Exemple : {"champ":"statut","x":1,"y":1,"largeur":6}.');
        }

        if (++$compteur > self::LAYOUT_MAX_COMPOSANTS) {
            throw new RuntimeException("Page « $page » : plus de "
                . self::LAYOUT_MAX_COMPOSANTS . ' composants dans la mise en page. '
                . 'Au-delà, ce n\'est plus un écran qu\'on décrit : c\'est une génération '
                . 'automatique partie en boucle, et elle doit échouer ici plutôt que '
                . 'devant l\'utilisateur.');
        }

        // Exactement UN discriminant : sans quoi on ne saurait pas quoi dessiner.
        $natures = array_values(array_filter(['champ', 'section', 'espace', 'texte', 'image'],
            static fn($k) => array_key_exists($k, $el)));
        if (count($natures) !== 1) {
            throw new RuntimeException("Page « $page », layout : un composant déclare "
                . 'exactement une nature — « champ », « section », « texte », '
                . '« image » ou « espace ». Reçu : '
                . ($natures === [] ? 'aucune' : '« ' . implode(' », « ', $natures) . ' »') . '.');
        }
        $nature = $natures[0];

        // ── Section ──────────────────────────────────────────────────────
        if ($nature === 'section') {
            $interne = self::validerConteneurLayout($el, $champs, $page,
                $profondeur + 1, $compteur, $places, false);

            $v = array_merge($interne, ['nature' => 'section']);
            $v += self::placementLayout($el, $page, $colonnes, 'section');

            $titre = trim((string)($el['section'] ?? ''));
            if ($titre === '' || $titre === '1') $titre = trim((string)($el['titre'] ?? ''));
            $v['titre'] = mb_substr($titre, 0, 60);

            $style = (string)($el['style'] ?? 'section');
            if (!in_array($style, self::LAYOUT_STYLES_SECTION, true)) {
                throw new RuntimeException("Page « $page », layout : habillage de section "
                    . "« $style » inconnu. Habillages : "
                    . implode(', ', self::LAYOUT_STYLES_SECTION) . '.');
            }
            $v['style'] = $style;

            if (isset($el['couleur'])) {
                $v['couleur'] = self::couleur($el['couleur'], "Page « $page », section");
            }
            $v['repliable'] = !empty($el['repliable']);
            $v['repliee']   = $v['repliable'] && !empty($el['repliee']);

            $hauteurSection = self::layoutDimension($el, 'taille', 'hauteur_px',
                self::LAYOUT_TAILLES, self::LAYOUT_MIN_PX, self::LAYOUT_MAX_PX, $page);
            if ($hauteurSection !== null) $v['hauteur_px'] = $hauteurSection;

            foreach (['hauteur_min_px', 'hauteur_max_px'] as $k) {
                if (!array_key_exists($k, $el) || $el[$k] === null) continue;
                $v[$k] = self::layoutEntier($el[$k], self::LAYOUT_MIN_PX,
                    self::LAYOUT_MAX_PX, self::LAYOUT_MIN_PX, $k, $page);
            }
            self::verifierMinMaxLayout($v, $page);

            $v['visible_si'] = self::conditionLayout($el, $champs, $page, 'section');
            return $v;
        }

        // ── Champ ────────────────────────────────────────────────────────
        if ($nature === 'champ') {
            self::refuserClesInconnues($el, self::LAYOUT_CLES_CHAMP, 'composant', $page);

            $nom = (string)$el['champ'];
            if (!isset($champs[$nom])) {
                throw new RuntimeException("Page « $page », layout : le champ « $nom » "
                    . "n'existe pas dans ce jeu. Champs : "
                    . implode(', ', array_keys($champs)) . '.');
            }
            if (($champs[$nom]['type'] ?? '') === 'formule') {
                throw new RuntimeException("Page « $page », layout : « $nom » est un champ "
                    . 'calculé. Il se lit dans la liste, il ne se saisit pas — le placer '
                    . 'dans un formulaire offrirait une case dont la valeur serait écartée '
                    . 'à l\'enregistrement.');
            }
            /**
             * Un champ placé DEUX FOIS produit deux cases pour une même donnée.
             * L'utilisateur en remplit une, et rien ne dit laquelle est
             * enregistrée — c'est le genre de défaut qu'on ne diagnostique
             * jamais depuis l'écran.
             */
            if (isset($places[$nom])) {
                throw new RuntimeException("Page « $page », layout : le champ « $nom » est "
                    . 'placé deux fois. Deux cases pour une même donnée : l\'utilisateur en '
                    . 'remplit une, et rien ne dit laquelle est enregistrée.');
            }
            $places[$nom] = true;

            $v = ['nature' => 'champ', 'champ' => $nom]
               + self::placementLayout($el, $page, $colonnes, 'composant');

            // La hauteur de la boîte de saisie : un nom (« compacte », « grande »)
            // ou des pixels. « normale » rend null — le champ garde sa hauteur
            // naturelle, et c'est le cas de la quasi-totalité des champs.
            $hauteurChamp = self::layoutDimension($el, 'taille', 'hauteur_px',
                self::LAYOUT_TAILLES, self::LAYOUT_MIN_PX, self::LAYOUT_MAX_PX, $page);
            if ($hauteurChamp !== null) $v['hauteur_px'] = $hauteurChamp;

            foreach (['largeur_px', 'largeur_min_px', 'largeur_max_px',
                      'hauteur_min_px', 'hauteur_max_px'] as $k) {
                if (!array_key_exists($k, $el) || $el[$k] === null) continue;
                $v[$k] = self::layoutEntier($el[$k], self::LAYOUT_MIN_PX,
                    self::LAYOUT_MAX_PX, self::LAYOUT_MIN_PX, $k, $page);
            }
            self::verifierMinMaxLayout($v, $page);

            $v['alignement_h'] = self::layoutAlignement($el['alignement_h'] ?? null,
                self::LAYOUT_ALIGN_H, 'alignement_h', $page, 'stretch');
            $v['alignement_v'] = self::layoutAlignement($el['alignement_v'] ?? null,
                self::LAYOUT_ALIGN_V, 'alignement_v', $page, 'stretch');
            $v['masquer_libelle'] = !empty($el['masquer_libelle']);
            $v['visible_si'] = self::conditionLayout($el, $champs, $page, 'composant');
            return $v;
        }

        // ── Texte statique ───────────────────────────────────────────────
        if ($nature === 'texte') {
            self::refuserClesInconnues($el, self::LAYOUT_CLES_TEXTE, 'texte', $page);

            $texte = trim((string)$el['texte']);
            if ($texte === '') {
                throw new RuntimeException("Page « $page », layout : un composant « texte » "
                    . 'ne peut pas être vide.');
            }
            $style = (string)($el['style'] ?? 'normal');
            if (!in_array($style, self::LAYOUT_STYLES_TEXTE, true)) {
                throw new RuntimeException("Page « $page », layout : style de texte "
                    . "« $style » inconnu. Styles : "
                    . implode(', ', self::LAYOUT_STYLES_TEXTE) . '.');
            }

            // Le texte est une DONNÉE, échappée au rendu. Il est borné ici pour
            // qu'une déclaration ne puisse pas transporter une page entière.
            $v = ['nature' => 'texte',
                  'texte'  => mb_substr($texte, 0, self::LAYOUT_MAX_TEXTE),
                  'style'  => $style]
               + self::placementLayout($el, $page, $colonnes, 'texte');
            $v['alignement_h'] = self::layoutAlignement($el['alignement_h'] ?? null,
                self::LAYOUT_ALIGN_H, 'alignement_h', $page, 'start');
            $v['visible_si'] = self::conditionLayout($el, $champs, $page, 'texte');
            return $v;
        }

        // ── Image ────────────────────────────────────────────────────────
        if ($nature === 'image') {
            self::refuserClesInconnues($el, self::LAYOUT_CLES_IMAGE, 'image', $page);

            $chemin = trim((string)$el['image']);
            if (!preg_match(self::LAYOUT_IMAGE_CHEMIN, $chemin)) {
                throw new RuntimeException("Page « $page », layout : image « $chemin » — "
                    . 'chemin attendu sous assets/, par exemple « assets/photo.jpg ». '
                    . 'Formats : png, jpg, webp. Ni adresse extérieure (ce serait une '
                    . 'sortie réseau), ni image encodée dans le JSON, ni SVG — ce '
                    . 'dernier peut contenir un script.');
            }

            /**
             * ⚠️ LA DESCRIPTION EST OBLIGATOIRE, ET C'EST VOULU.
             *
             * Une image sans description n'existe pas pour qui ne la voit pas :
             * lecteur d'écran, connexion lente, fichier manquant. L'auteur est
             * le seul à savoir ce qu'elle montre, et il ne pensera jamais à
             * l'ajouter après coup — personne ne voit ce qui manque.
             *
             * La chaîne VIDE reste acceptée : c'est la façon correcte de
             * déclarer une image purement décorative, qu'un lecteur d'écran
             * doit justement ignorer. Ce qu'on refuse, c'est l'absence de
             * choix.
             */
            if (!array_key_exists('description', $el)) {
                throw new RuntimeException("Page « $page », layout : l'image "
                    . "« $chemin » n'a pas de « description ». Une image sans "
                    . 'description n\'existe pas pour qui ne la voit pas. Écrivez ce '
                    . 'qu\'elle montre, ou « "description": "" » si elle est purement '
                    . 'décorative.');
            }

            $v = ['nature' => 'image', 'image' => $chemin,
                  'description' => mb_substr(trim((string)$el['description']), 0,
                                             self::LAYOUT_MAX_TEXTE)]
               + self::placementLayout($el, $page, $colonnes, 'image');

            $cadrage = (string)($el['cadrage'] ?? 'contenir');
            if (!isset(self::LAYOUT_CADRAGES[$cadrage])) {
                throw new RuntimeException("Page « $page », layout : cadrage « $cadrage » "
                    . 'inconnu. Cadrages : ' . implode(', ', array_keys(self::LAYOUT_CADRAGES)) . '.');
            }
            $v['cadrage'] = self::LAYOUT_CADRAGES[$cadrage];

            $arrondi = (string)($el['arrondi'] ?? 'leger');
            if (!isset(self::LAYOUT_ARRONDIS[$arrondi])) {
                throw new RuntimeException("Page « $page », layout : arrondi « $arrondi » "
                    . 'inconnu. Arrondis : ' . implode(', ', array_keys(self::LAYOUT_ARRONDIS)) . '.');
            }
            $v['arrondi'] = self::LAYOUT_ARRONDIS[$arrondi];

            $hauteurImage = self::layoutDimension($el, 'taille', 'hauteur_px',
                self::LAYOUT_TAILLES, self::LAYOUT_MIN_PX, self::LAYOUT_MAX_PX, $page);
            if ($hauteurImage !== null) $v['hauteur_px'] = $hauteurImage;

            foreach (['hauteur_min_px', 'hauteur_max_px', 'largeur_max_px'] as $k) {
                if (!array_key_exists($k, $el) || $el[$k] === null) continue;
                $v[$k] = self::layoutEntier($el[$k], self::LAYOUT_MIN_PX,
                    self::LAYOUT_MAX_PX, self::LAYOUT_MIN_PX, $k, $page);
            }
            self::verifierMinMaxLayout($v, $page);

            $v['alignement_h'] = self::layoutAlignement($el['alignement_h'] ?? null,
                self::LAYOUT_ALIGN_H, 'alignement_h', $page, 'stretch');
            $v['visible_si'] = self::conditionLayout($el, $champs, $page, 'image');
            return $v;
        }

        // ── Espace ───────────────────────────────────────────────────────
        self::refuserClesInconnues($el, self::LAYOUT_CLES_ESPACE, 'espace', $page);
        $v = ['nature' => 'espace'] + self::placementLayout($el, $page, $colonnes, 'espace');
        $hauteurEspace = self::layoutDimension($el, 'taille', 'hauteur_px',
            self::LAYOUT_TAILLES, self::LAYOUT_MIN_PX, self::LAYOUT_MAX_PX, $page);
        if ($hauteurEspace !== null) $v['hauteur_px'] = $hauteurEspace;
        return $v;
    }

    /**
     * Position et étendue sur la grille du conteneur.
     *
     * « x » et « y » sont FACULTATIFS : sans eux, le composant se range à la
     * suite. C'est ce qui rend la mise en page simple à écrire pour un
     * formulaire ordinaire — on ne pose des coordonnées que là où l'on veut
     * vraiment décider.
     */
    private static function placementLayout(array $el, string $page, int $colonnes,
                                            string $quoi): array
    {
        $largeur = self::layoutEntier($el['largeur'] ?? null, 1, $colonnes, 1, 'largeur', $page);
        $hauteur = self::layoutEntier($el['hauteur'] ?? null, 1,
            self::LAYOUT_MAX_LIGNES, 1, 'hauteur', $page);

        $x = array_key_exists('x', $el) && $el['x'] !== null
            ? self::layoutEntier($el['x'], 1, $colonnes, 1, 'x', $page) : null;
        $y = array_key_exists('y', $el) && $el['y'] !== null
            ? self::layoutEntier($el['y'], 1, self::LAYOUT_MAX_LIGNES, 1, 'y', $page) : null;

        /**
         * Un composant qui déborde de la grille est une FAUTE, pas une
         * maladresse : le navigateur ajouterait une colonne pour l'accueillir,
         * et toute la mise en page glisserait d'un cran sans que rien ne
         * l'annonce. On le dit ici, avec les deux nombres en cause.
         */
        if ($x !== null && $x + $largeur - 1 > $colonnes) {
            throw new RuntimeException("Page « $page », $quoi : commence colonne $x et "
                . "occupe $largeur colonne(s) — il dépasse la grille de $colonnes "
                . 'colonnes. Réduisez « largeur », avancez « x », ou élargissez la grille.');
        }

        return ['x' => $x, 'y' => $y, 'largeur' => $largeur, 'hauteur' => $hauteur];
    }

    /** Un minimum au-dessus de son maximum ne décrit aucune taille possible. */
    private static function verifierMinMaxLayout(array $v, string $page): void
    {
        foreach ([['largeur_min_px', 'largeur_max_px'],
                  ['hauteur_min_px', 'hauteur_max_px']] as [$mi, $ma]) {
            if (isset($v[$mi], $v[$ma]) && $v[$mi] > $v[$ma]) {
                throw new RuntimeException("Page « $page », layout : « $mi » ("
                    . $v[$mi] . ') dépasse « ' . $ma . ' » (' . $v[$ma]
                    . ') — aucune dimension ne satisferait les deux.');
            }
        }
    }

    /**
     * Affichage conditionnel — la MÊME grammaire que « visible_si » d'un champ.
     *
     * Rien de nouveau à apprendre, et surtout rien de nouveau à sécuriser : la
     * condition est compilée par ExtCondition contre les champs du jeu, donc
     * contre un catalogue fermé d'opérateurs, à l'installation.
     */
    private static function conditionLayout(array $el, array $champs, string $page,
                                            string $quoi): ?array
    {
        if (!isset($el['visible_si'])) return null;
        $c = ExtCondition::valider($el['visible_si'], $champs);
        if (!$c['ok']) {
            throw new RuntimeException("Page « $page », $quoi, visible_si : " . $c['erreur']);
        }
        return $c['arbre'];
    }

    /**
     * Paliers d'adaptation : « en dessous de tant de pixels, tant de colonnes ».
     *
     * On ne laisse pas l'auteur écrire une requête de média : ce serait du CSS,
     * donc du code. Il déclare un seuil et un nombre de colonnes — deux entiers
     * bornés — et le client en fabrique la règle. Un téléphone reçoit ainsi une
     * colonne unique sans que personne ait écrit une ligne de style.
     */
    private static function validerResponsiveLayout(mixed $liste, string $page): array
    {
        if (empty($liste)) return [];
        if (!is_array($liste)) {
            throw new RuntimeException("Page « $page » : « responsive » attend un tableau "
                . 'de paliers, par exemple [{"en_dessous_de_px":640,"colonnes":1}].');
        }
        if (count($liste) > self::LAYOUT_MAX_RESPONSIVE) {
            throw new RuntimeException("Page « $page » : " . self::LAYOUT_MAX_RESPONSIVE
                . ' paliers responsive au maximum.');
        }

        $out = [];
        foreach ($liste as $p) {
            if (!is_array($p)) {
                throw new RuntimeException("Page « $page » : un palier responsive est un "
                    . 'objet {"en_dessous_de_px":640,"colonnes":1}.');
            }
            self::refuserClesInconnues($p, self::LAYOUT_CLES_PALIER, 'palier responsive', $page);

            // « sous »: "telephone" plutôt que « en_dessous_de_px »: 640 —
            // un auteur sait ce qu'est un téléphone, il n'a pas à savoir où
            // cela commence.
            $palier = [
                'en_dessous_de_px' => self::layoutDimension($p, 'sous', 'en_dessous_de_px',
                    self::LAYOUT_SEUILS, 240, 2560, $page) ?? 640,
                'colonnes' => self::layoutEntier($p['colonnes'] ?? null, 1,
                    self::LAYOUT_MAX_COLONNES, 1, 'colonnes', $page),
                'gap_px' => self::layoutDimension($p, 'espacement', 'gap_px',
                    self::LAYOUT_ESPACEMENTS, 0, self::LAYOUT_MAX_GAP_PX, $page),
            ];
            $out[] = $palier;
        }

        // Du plus large au plus étroit : c'est l'ordre dans lequel les règles
        // doivent se recouvrir pour que la plus étroite l'emporte.
        usort($out, static fn($a, $b) => $b['en_dessous_de_px'] <=> $a['en_dessous_de_px']);
        return $out;
    }

    /** Capacités effectivement nécessaires — déduites, jamais déclarées. */
    public static function capacitesRequises(array $d): array
    {
        // Un ancrage déclaratif exige la capacité, comme un ancrage à code :
        // l'administrateur doit voir que ce module s'insère dans ses écrans.
        // La différence — c'est Larka qui dessine — est dite dans le libellé.
        if (!empty($d['ancrages'])) {
            return array_values(array_unique(array_merge(
                self::capacitesBase($d), ['ui.ancrage'])));
        }
        return self::capacitesBase($d);
    }

    /**
     * Ancrages déclaratifs.
     *
     * Chacun est une PRIMITIVE : un type que le moteur de Larka sait dessiner,
     * paramétré par la déclaration. L'auteur choisit quoi afficher et d'où le
     * tirer ; il n'écrit jamais comment l'afficher.
     *
     * Ajouter un type ici enrichit le vocabulaire du format sans jamais
     * permettre l'exécution de code : la déclaration gagne un mot, le moteur
     * garde la main sur ce que ce mot produit.
     */
    private const ANCRAGES = [
        'compteur' => [                                  // tuile chiffrée
            'emplacements' => ['dashboard.tuiles'],
            'requis' => [],
        ],
        'jauge' => [                                     // tuile avec agrégat
            'emplacements' => ['dashboard.tuiles'],
            'requis' => ['champ'],
        ],
        'liste_liee' => [                                // enregistrements liés
            // Emplacements vérifiés à l'exécution contre ExtAncrages : deux
            // listes finissent par diverger, et c'est arrivé — la fiche
            // équipement a été retirée du catalogue sans que celle-ci suive,
            // rendant le module d'exemple ininstallable.
            'emplacements' => ['equipement.fiche', 'bien.fiche'],
            'requis' => ['champ_lien'],
        ],
        /**
         * Le module est un TYPE DE DEMANDE.
         *
         * Il ne pose pas un encart : son formulaire remplace celui du cœur dans
         * « Nouvelle demande », entre « Technique » et « Archive ». Un module
         * destiné aux demandeurs se présente ainsi comme les types existants,
         * au lieu d'occuper une barre latérale qu'ils n'ont pas.
         */
        'formulaire' => [                                // type de demande
            'emplacements' => ['demandes.type'],
            'requis' => [],
        ],
        'marqueurs' => [                                 // repères sur le plan
            'emplacements' => ['plans.barre_outils'],
            'requis' => ['champ_etage', 'champ_lat', 'champ_lng'],
        ],
    ];

    /**
     * Ce qu'un module de données EXPOSE aux autres.
     *
     * Le problème de fond : un module ne peut pas lire la table d'un autre —
     * c'est une règle de sécurité, pas une lacune. Comment, alors, additionner
     * une valeur venue d'ailleurs ?
     *
     * Par CONSENTEMENT EXPLICITE, dans le sens de l'offre. Le module qui détient
     * la donnée déclare ce qu'il accepte de publier, champ par champ. Rien n'est
     * partagé par défaut, et le module consommateur ne peut rien exiger : il ne
     * voit que ce qu'on lui tend.
     *
     * C'est l'inverse d'un droit de lecture accordé au consommateur, qui
     * donnerait accès à tout le jeu. Ici, le propriétaire garde la main, et
     * l'administrateur voit les deux côtés du contrat à l'installation.
     */
    private static function validerPartages(mixed $liste, array $jeux): array
    {
        if (empty($liste)) return [];
        if (!is_array($liste)) throw new RuntimeException('Champ « partage » : tableau attendu.');
        if (count($liste) > 20) throw new RuntimeException('Vingt partages au maximum.');

        $out = [];
        foreach ($liste as $p) {
            if (!is_array($p)) {
                throw new RuntimeException('Un partage est un objet : '
                    . '{"nom":"stock_cles","source":"cles","champs":["numero","exemplaires"]}');
            }
            $nom    = self::nomTechnique((string)($p['nom'] ?? ''), 'partage');
            $source = (string)($p['source'] ?? '');
            if (!isset($jeux[$source])) {
                throw new RuntimeException("Partage « $nom » : jeu « $source » inconnu.");
            }
            $champs = $jeux[$source]['champs'];

            $publies = [];
            foreach ((array)($p['champs'] ?? []) as $c) {
                $c = (string)$c;
                if (!isset($champs[$c])) {
                    throw new RuntimeException("Partage « $nom » : le champ « $c » n'existe "
                        . "pas dans « $source ».");
                }
                $publies[] = $c;
            }
            if ($publies === []) {
                throw new RuntimeException("Partage « $nom » : indiquez les champs publiés. "
                    . 'Un partage sans liste publierait tout — ce n\'est pas un partage.');
            }

            $out[$nom] = ['nom' => $nom, 'source' => $source, 'champs' => $publies,
                          'libelle' => trim((string)($p['libelle'] ?? $nom))];
        }
        return $out;
    }

    /**
     * Valeurs calculées à partir d'une source EXTERNE au module.
     *
     * Deux origines possibles, et une seule forme :
     *   { "type":"somme", "de":"exemple.stock/stock_cles", "champ":"quantite",
     *     "ou":{"reference":"@numero"} }
     *
     * « @champ » désigne un champ de la ligne courante : c'est le seul endroit
     * où une valeur du module entre dans la requête, et elle part en paramètre
     * lié, jamais dans le SQL.
     */
    private static function validerCalculs(mixed $liste, array $jeux): array
    {
        if (empty($liste)) return [];
        if (!is_array($liste)) throw new RuntimeException('Champ « calculs » : tableau attendu.');
        if (count($liste) > 40) throw new RuntimeException('Quarante calculs au maximum.');

        $OPERATIONS = ['somme', 'compte', 'moyenne', 'minimum', 'maximum'];
        $out = [];

        foreach ($liste as $nom => $c) {
            $nom = self::nomTechnique((string)$nom, 'calcul');
            if (!is_array($c)) throw new RuntimeException("Calcul « $nom » : objet attendu.");

            $type = (string)($c['type'] ?? '');
            if (!in_array($type, $OPERATIONS, true)) {
                throw new RuntimeException("Calcul « $nom » : opération « $type » inconnue. "
                    . 'Opérations : ' . implode(', ', $OPERATIONS));
            }

            $de = (string)($c['de'] ?? '');
            if (!preg_match('#^[a-z0-9]([a-z0-9\-]*[a-z0-9])?\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?/[a-z][a-z0-9_]*$#', $de)) {
                throw new RuntimeException("Calcul « $nom » : « de » doit désigner un partage "
                    . 'sous la forme « vendeur.module/nom_du_partage ».');
            }

            $v = ['nom' => $nom, 'type' => $type, 'de' => $de,
                  'libelle' => trim((string)($c['libelle'] ?? $nom)), 'ou' => []];

            if ($type !== 'compte') {
                if (empty($c['champ'])) {
                    throw new RuntimeException("Calcul « $nom » : « champ » obligatoire pour "
                        . "une opération « $type ».");
                }
                $v['champ'] = self::nomTechnique((string)$c['champ'], 'champ de calcul');
            }

            foreach ((array)($c['ou'] ?? []) as $cle => $val) {
                $cle = self::nomTechnique((string)$cle, 'condition de calcul');
                if (!is_scalar($val)) continue;
                $v['ou'][$cle] = (string)$val;   // « @champ » résolu à l'exécution
            }
            $out[$nom] = $v;
        }
        return $out;
    }

    /** Types de réglage. Liste close : un réglage se saisit, il ne s'invente pas. */
    private const TYPES_REGLAGE = ['texte', 'entier', 'decimal', 'booleen',
                                   'choix', 'couleur', 'date'];

    /**
     * Ce qu'un réglage peut PILOTER dans l'apparence du module.
     *
     * Sans cela, un réglage nommé « couleur » n'était qu'une valeur rangée dans
     * un fichier : l'administrateur la changeait, et rien ne bougeait à l'écran.
     * Le module promettait une personnalisation qu'il ne tenait pas.
     *
     * La liste est close et ne concerne QUE l'écran du module. Un pack ne
     * repeint pas Larka : il choisit l'aspect de ce qu'il ajoute.
     */
    public const APPARENCE = [
        'couleur_accent'   => ['type' => 'couleur',
                               'libelle' => 'Couleur d\'accent de l\'écran'],
        'densite'          => ['type' => 'choix',
                               'valeurs' => ['Compact', 'Confortable'],
                               'libelle' => 'Hauteur des lignes'],
        'lignes_alternees' => ['type' => 'booleen',
                               'libelle' => 'Lignes alternées'],
        'bordure_gauche'   => ['type' => 'booleen',
                               'libelle' => 'Filet coloré à gauche du tableau'],
        'taille_texte'     => ['type' => 'choix',
                               'valeurs' => ['Petit', 'Normal', 'Grand'],
                               'libelle' => 'Taille du texte'],

        // ── Ajouts ───────────────────────────────────────────────────────
        'couleur_fond'     => ['type' => 'couleur',
                               'libelle' => 'Fond du tableau'],
        'couleur_texte'    => ['type' => 'couleur',
                               'libelle' => 'Couleur du texte'],
        'couleur_entete'   => ['type' => 'couleur',
                               'libelle' => 'Texte de l\'en-tête'],
        'police'           => ['type' => 'choix',
                               'valeurs' => ['Système', 'Sans empattement', 'Avec empattement',
                                             'Chasse fixe'],
                               'libelle' => 'Police'],
        'style_bordures'   => ['type' => 'choix',
                               'valeurs' => ['Aucune', 'Fines', 'Marquées'],
                               'libelle' => 'Bordures du tableau'],
        'arrondi'          => ['type' => 'choix',
                               'valeurs' => ['Aucun', 'Léger', 'Marqué'],
                               'libelle' => 'Arrondi des angles'],
        'theme'            => ['type' => 'choix',
                               'valeurs' => ['Larka', 'Sobre', 'Contrasté', 'Papier',
                                             'Nuit', 'Pastel'],
                               'libelle' => 'Thème d\'ensemble'],
        // La langue est un réglage comme un autre : c'est l'administrateur qui
        // choisit dans quelle langue SON module s'affiche, pas l'auteur.
        'langue'           => ['type' => 'choix', 'valeurs' => [],
                               'libelle' => 'Langue du module'],
    ];

    /**
     * Cibles qu'une variable peut piloter.
     *
     * Le CŒUR ne connaît ni thème, ni police, ni langue : il sait seulement
     * poser une variable CSS ou remplacer un libellé. C'est un module
     * CHARGEUR qui déclare le vocabulaire — quelles variables existent, ce
     * qu'elles pilotent, entre quelles bornes.
     *
     * Sans cela, ajouter une propriété d'apparence demandait de modifier
     * Larka : le vocabulaire vivait dans le code du produit, et un module ne
     * pouvait qu'utiliser ce qui y avait été prévu.
     */
    public const CIBLES_VARIABLE = ['css', 'libelle'];

    /**
     * Types qu'une variable peut prendre. Liste close.
     *
     * « image » désigne un fichier du paquet (assets/…), jamais une adresse
     * distante : un thème qui chargerait une image depuis un serveur extérieur
     * signalerait chaque affichage à ce serveur, et cesserait de fonctionner
     * le jour où il disparaît.
     */
    public const TYPES_VARIABLE = ['couleur', 'px', 'texte', 'nombre', 'choix',
                                   'image', 'ombre', 'bordure'];

    /**
     * Thèmes prêts à l'emploi.
     *
     * Un réglage par élément permet tout, mais oblige à composer une palette —
     * ce que personne ne fait bien de tête. Un thème pose l'ensemble d'un coup ;
     * les réglages individuels le surchargent ensuite.
     */
    public const THEMES = [
        'Larka'      => ['accent' => '#1b3a5c', 'fond' => '',        'texte' => '',
                         'entete' => '#ffffff', 'bordures' => 'Fines',    'arrondi' => 'Léger'],
        'Sobre'      => ['accent' => '#475569', 'fond' => '#f8fafc', 'texte' => '#0f172a',
                         'entete' => '#ffffff', 'bordures' => 'Aucune',   'arrondi' => 'Aucun'],
        'Contrasté'  => ['accent' => '#000000', 'fond' => '#ffffff', 'texte' => '#000000',
                         'entete' => '#ffffff', 'bordures' => 'Marquées', 'arrondi' => 'Aucun'],
        'Papier'     => ['accent' => '#92400e', 'fond' => '#fdf6e3', 'texte' => '#3f2d16',
                         'entete' => '#fdf6e3', 'bordures' => 'Fines',    'arrondi' => 'Léger'],
        'Nuit'       => ['accent' => '#1e293b', 'fond' => '#0f172a', 'texte' => '#e2e8f0',
                         'entete' => '#e2e8f0', 'bordures' => 'Fines',    'arrondi' => 'Léger'],
        'Pastel'     => ['accent' => '#7c3aed', 'fond' => '#faf5ff', 'texte' => '#3b0764',
                         'entete' => '#ffffff', 'bordures' => 'Aucune',   'arrondi' => 'Marqué'],
    ];

    /**
     * Réglages qu'un module expose à l'administrateur.
     *
     * Un pack décrit des paramètres — couleur d'accent, seuil d'alerte, libellé
     * d'en-tête — que l'administrateur ajuste depuis l'écran Modules, sans
     * republier le pack. Ils sont ensuite lisibles dans les formules et les
     * conditions sous « reglages.<nom> ».
     *
     * Un réglage NE PEUT PAS modifier Larka : il ne touche qu'à ce que le module
     * affiche. Laisser un pack changer le thème global ou masquer un menu du
     * cœur reviendrait à lui donner la main sur l'application entière — un pack
     * mal réglé rendrait Larka inutilisable, et le rapport de force serait
     * inversé.
     */
    /**
     * Un module d'habillage : ni données, ni page, ni capacité.
     *
     * Il publie un vocabulaire, ou fournit des valeurs pour celui d'un autre.
     */
    private static function finaliserHabillage(array $d): array
    {
        $d['donnees']  = [];
        $d['pages']    = [];
        $d['ancrages'] = [];
        // « fichiers » et « interface » ne sont PAS vidés : ils ne dépendent
        // ni d'un jeu de données ni d'un écran. Les effacer ici retirerait
        // silencieusement à un pack de langue le dossier où déposer ses
        // traductions — le défaut qu'« ancrages » avait déjà connu.

        $d['partage']  = [];
        $d['calculs']  = [];
        $d['vocabulaire'] = self::validerVocabulaire($d['vocabulaire'] ?? []);
        $d['variables']   = self::validerVariables($d['variables'] ?? []);
        $d['langues']     = self::validerLangues($d['langues'] ?? []);
        $d['reglages']    = self::validerReglages($d['reglages'] ?? []);
        // Un module d'habillage a de bonnes raisons de détenir des fichiers —
        // c'est même son cas le plus naturel : un jeu d'icônes, des drapeaux,
        // des traductions déposées après coup.
        $d['fichiers']    = self::validerFichiers($d['fichiers'] ?? []);
        $d['interface']   = self::validerInterface($d['interface'] ?? []);
        $d['declaratif']  = true;
        return $d;
    }

    /**
     * VOCABULAIRE : les variables qu'un module chargeur met à disposition.
     *
     * Un module d'apparence ne connaît pas Larka ; il connaît le vocabulaire
     * publié par son chargeur. Le chargeur, lui, dit à quoi chaque variable se
     * rattache — une variable CSS du produit, ou un libellé à remplacer.
     *
     * Les bornes sont déclarées ICI, avec la variable : une taille de police
     * sans maximum permettrait 400 px, et l'utilisateur ne saurait pas d'où
     * vient l'écran devenu illisible.
     */
    private static function validerVocabulaire(mixed $decl): array
    {
        if (empty($decl)) return [];
        if (!is_array($decl)) {
            throw new RuntimeException('Champ « vocabulaire » : objet attendu.');
        }
        if (count($decl) > 500) {
            throw new RuntimeException('Cinq cents variables au maximum.');
        }

        $out = [];
        foreach ($decl as $nom => $v) {
            $nom = self::nomTechnique((string)$nom, 'variable');
            if (!is_array($v)) {
                throw new RuntimeException("Variable « $nom » : objet attendu.");
            }

            $type = (string)($v['type'] ?? 'texte');
            if (!in_array($type, self::TYPES_VARIABLE, true)) {
                throw new RuntimeException("Variable « $nom » : type « $type » inconnu. "
                    . 'Types : ' . implode(', ', self::TYPES_VARIABLE));
            }
            $cible = (string)($v['cible'] ?? 'css');
            if (!in_array($cible, self::CIBLES_VARIABLE, true)) {
                throw new RuntimeException("Variable « $nom » : cible « $cible » inconnue. "
                    . 'Cibles : ' . implode(', ', self::CIBLES_VARIABLE));
            }

            $prop = ['type' => $type, 'cible' => $cible,
                     'libelle' => mb_substr((string)($v['libelle'] ?? $nom), 0, 60)];

            if ($cible === 'css') {
                // Nom de variable CSS : borné à ce format, pour qu'une
                // déclaration ne puisse pas injecter autre chose qu'un nom.
                $css = (string)($v['variable'] ?? '');
                if (!preg_match('/^--[a-z][a-z0-9-]{0,40}$/', $css)) {
                    throw new RuntimeException("Variable « $nom » : « variable » attendue "
                        . 'sous la forme « --nom-css ».');
                }
                $prop['variable'] = $css;
            } else {
                $prop['libelle_source'] = mb_substr((string)($v['libelle_source'] ?? $nom), 0, 80);
            }

            if ($type === 'px' || $type === 'nombre') {
                $prop['min'] = isset($v['min']) ? (float)$v['min'] : 0;
                $prop['max'] = isset($v['max']) ? (float)$v['max'] : 1000;
                if ($prop['min'] >= $prop['max']) {
                    throw new RuntimeException("Variable « $nom » : « min » doit être "
                        . 'inférieur à « max ».');
                }
            }
            if ($type === 'choix') {
                if (empty($v['valeurs']) || !is_array($v['valeurs'])) {
                    throw new RuntimeException("Variable « $nom » de type « choix » : "
                        . 'listez ses « valeurs ».');
                }
                $prop['valeurs'] = array_values(array_map(
                    fn($x) => mb_substr((string)$x, 0, 120), array_slice($v['valeurs'], 0, 120)));
            }
            if (isset($v['defaut']) && is_scalar($v['defaut'])) $prop['defaut'] = $v['defaut'];

            $out[$nom] = $prop;
        }
        return $out;
    }

    /**
     * VARIABLES : les valeurs qu'un module fournit pour un vocabulaire.
     *
     * On ne peut pas les vérifier ici : le vocabulaire appartient à un autre
     * module, qui n'est pas forcément installé au moment où celui-ci est
     * validé. Le contrôle a lieu à l'application, quand les deux sont
     * présents — et une valeur hors bornes est alors ignorée, pas appliquée.
     */
    private static function validerVariables(mixed $decl): array
    {
        if (empty($decl)) return [];
        if (!is_array($decl)) {
            throw new RuntimeException('Champ « variables » : objet attendu.');
        }
        $de = (string)($decl['de'] ?? '');
        if (!preg_match('/^[a-z0-9\-]+\.[a-z0-9\-]+$/', $de)) {
            throw new RuntimeException('Champ « variables.de » : identifiant du module '
                . 'qui publie le vocabulaire, sous la forme « vendeur.module ».');
        }
        $valeurs = $decl['valeurs'] ?? [];
        if (!is_array($valeurs) || $valeurs === []) {
            throw new RuntimeException('Champ « variables.valeurs » : au moins une valeur.');
        }
        if (count($valeurs) > 500) {
            throw new RuntimeException('Cinq cents valeurs au maximum.');
        }

        $propre = static function (array $liste): array {
            $out = [];
            foreach ($liste as $nom => $v) {
                if (!is_scalar($v)) continue;
                $out[mb_substr((string)$nom, 0, 40)] = is_string($v)
                    ? mb_substr($v, 0, 200) : $v;
            }
            return $out;
        };

        /**
         * ── Variante sombre, facultative ─────────────────────────────────
         *
         * Un thème ne pouvait décrire qu'UNE palette. Il était donc clair ou
         * sombre, jamais les deux, et le mode sombre de Larka devait être
         * suspendu tant qu'un thème était choisi : les deux couches se
         * seraient battues. L'utilisateur devait choisir entre « mon thème » et
         * « mes yeux le soir ».
         *
         * « valeurs_sombre » ne remplace pas « valeurs » : elle la SURCHARGE.
         * Un thème n'a donc à redéclarer que ce qui change — les couleurs, en
         * général — et garde ses tailles, ses polices et ses bordures. Un thème
         * qui n'en fournit pas se comporte comme avant.
         */
        $sombre = $decl['valeurs_sombre'] ?? [];
        if (!is_array($sombre)) {
            throw new RuntimeException('Champ « variables.valeurs_sombre » : objet attendu.');
        }
        if (count($sombre) > 500) {
            throw new RuntimeException('Cinq cents valeurs au maximum en variante sombre.');
        }

        return ['de' => $de,
                'valeurs' => $propre($valeurs),
                'valeurs_sombre' => $propre($sombre),
                'libelle' => mb_substr((string)($decl['libelle'] ?? ''), 0, 60)];
    }

    private static function validerReglages(mixed $liste): array
    {
        if (empty($liste)) return [];
        if (!is_array($liste)) throw new RuntimeException('Champ « reglages » : objet attendu.');
        if (count($liste) > 60) throw new RuntimeException('Soixante réglages au maximum.');

        $out = [];
        foreach ($liste as $cle => $r) {
            $cle = self::nomTechnique((string)$cle, 'réglage');
            if (!is_array($r)) $r = ['type' => 'texte', 'defaut' => $r];

            $type = (string)($r['type'] ?? 'texte');
            if (!in_array($type, self::TYPES_REGLAGE, true)) {
                throw new RuntimeException("Réglage « $cle » : type « $type » inconnu. "
                    . 'Types : ' . implode(', ', self::TYPES_REGLAGE));
            }

            $v = ['type' => $type,
                  'libelle' => mb_substr((string)($r['libelle'] ?? ucfirst($cle)), 0, 60),
                  'aide' => mb_substr((string)($r['aide'] ?? ''), 0, 160)];

            if ($type === 'choix') {
                // « langue » est la seule exception : ses valeurs sont les
                // traductions fournies par le module, composées à l'affichage.
                // Les faire répéter par l'auteur créerait deux listes, qui
                // divergeraient dès la première langue ajoutée.
                $estLangue = ($r['applique'] ?? '') === 'langue';
                if (!$estLangue && (empty($r['valeurs']) || !is_array($r['valeurs']))) {
                    throw new RuntimeException("Réglage « $cle » de type « choix » : "
                        . 'listez ses « valeurs ».');
                }
                if ($estLangue) $r['valeurs'] = $r['valeurs'] ?? [];
                $v['valeurs'] = array_values(array_map(
                    fn($x) => mb_substr((string)$x, 0, 60), array_slice($r['valeurs'], 0, 120)));
            }
            if ($type === 'couleur' && isset($r['defaut'])) {
                $r['defaut'] = self::couleur($r['defaut'], "Réglage « $cle »");
            }
            if (isset($r['defaut']) && is_scalar($r['defaut'])) $v['defaut'] = $r['defaut'];

            // ── Ce que le réglage pilote ─────────────────────────────────
            if (!empty($r['applique'])) {
                $a = (string)$r['applique'];
                if (!isset(self::APPARENCE[$a])) {
                    throw new RuntimeException("Réglage « $cle » : « $a » n'est pas un "
                        . 'élément d\'apparence. Possibles : '
                        . implode(', ', array_keys(self::APPARENCE)));
                }
                // Le type doit correspondre : une couleur d'accent pilotée par
                // un entier ne produirait rien, et l'auteur ne le saurait qu'à
                // l'affichage.
                $attendu = self::APPARENCE[$a]['type'];
                if ($type !== $attendu) {
                    throw new RuntimeException("Réglage « $cle » : « $a » attend un réglage "
                        . "de type « $attendu », celui-ci est « $type ».");
                }
                // « langue » n'a pas de valeurs fixes : elles dépendent des
                // traductions que le module fournit. On les vérifie plus bas,
                // une fois « langues » validé.
                if ($a === 'langue') { $v['applique'] = $a; $out[$cle] = $v; continue; }

                if ($attendu === 'choix'
                    && array_diff($v['valeurs'], self::APPARENCE[$a]['valeurs']) !== []) {
                    throw new RuntimeException("Réglage « $cle » : valeurs possibles pour "
                        . "« $a » : " . implode(', ', self::APPARENCE[$a]['valeurs']));
                }
                $v['applique'] = $a;
            }

            $out[$cle] = $v;
        }
        return $out;
    }

    /**
     * Traductions des libellés du module.
     *
     * Le pack donne ses libellés dans plusieurs langues ; Larka choisit selon
     * celle de l'utilisateur. Seuls les LIBELLÉS sont traduits — jamais les
     * noms techniques, qui identifient les colonnes en base : les traduire
     * ferait dépendre le schéma de la langue du navigateur.
     */
    private static function validerLangues(mixed $liste): array
    {
        if (empty($liste)) return [];
        if (!is_array($liste)) throw new RuntimeException('Champ « langues » : objet attendu.');

        $out = [];
        foreach ($liste as $code => $trad) {
            if (!preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', (string)$code)) {
                throw new RuntimeException("Langue « $code » : code attendu de la forme "
                    . '« fr », « en » ou « fr-BE ».');
            }
            if (!is_array($trad)) continue;
            // Traduire une application entière demande des milliers de
            // libellés : un plafond à 300 obligeait à choisir lesquels laisser
            // en français. La borne reste, mais à une hauteur qui ne gêne pas
            // un pack sérieux — elle est là contre un fichier aberrant, pas
            // contre un traducteur consciencieux.
            if (count($trad) > 20000) {
                throw new RuntimeException("Langue « $code » : 20000 libellés au maximum.");
            }
            $t = [];
            foreach ($trad as $k => $v) {
                if (!is_scalar($v)) continue;
                $t[mb_substr((string)$k, 0, 80)] = mb_substr((string)$v, 0, 200);
            }
            $out[(string)$code] = $t;
        }
        return $out;
    }

    /** Grille d'un calendrier : jour, créneau, ressource — tous en « choix » ou date. */
    private static function validerCalendrier(mixed $c, array $champs, string $cle,
                                              bool $requis): array
    {
        if (!$requis) return [];
        if (!is_array($c)) {
            throw new RuntimeException("Page « $cle » : « calendrier » attend un objet.");
        }
        $out = [];
        foreach ([['jour', ['date', 'choix']],
                  ['creneau', ['choix']],
                  ['ressource', ['choix']]] as [$role, $types]) {
            $nom = (string)($c[$role] ?? '');
            if (!isset($champs[$nom])) {
                throw new RuntimeException("Page « $cle » : calendrier.$role — champ "
                    . "« $nom » inconnu. Une grille a besoin d'un jour, d'un créneau "
                    . 'et d\'une ressource.');
            }
            if (!in_array($champs[$nom]['type'], $types, true)) {
                throw new RuntimeException("Page « $cle » : calendrier.$role doit être "
                    . 'de type ' . implode(' ou ', $types) . ", « $nom » est "
                    . $champs[$nom]['type'] . '.');
            }
            $out[$role] = $nom;
        }
        // Le champ affiché DANS la case : qui occupe le créneau. Sans lui, une
        // case prise ne dirait pas par qui, et la grille perdrait l'essentiel.
        $occ = (string)($c['occupant'] ?? '');
        if ($occ !== '') {
            if (!isset($champs[$occ])) {
                throw new RuntimeException("Page « $cle » : calendrier.occupant — "
                    . "champ « $occ » inconnu.");
            }
            $out['occupant'] = $occ;
        }

        // Largeurs de la grille, en pixels. Les valeurs par défaut conviennent
        // à un nom et une plaque ; un module qui affiche davantage a besoin de
        // les élargir, et un module qui n'affiche qu'une initiale de les
        // resserrer.
        $out['largeur_jour_px'] = isset($c['largeur_jour_px'])
            ? max(60, min((int)$c['largeur_jour_px'], 400)) : null;
        $out['largeur_creneau_px'] = isset($c['largeur_creneau_px'])
            ? max(50, min((int)$c['largeur_creneau_px'], 300)) : null;

        // Une seconde ligne dans la case : l'immatriculation. Un nom seul ne
        // suffit pas à identifier un véhicule sur un parking — c'est la plaque
        // qu'on lit en passant devant la borne.
        $det = (string)($c['detail'] ?? '');
        if ($det !== '') {
            if (!isset($champs[$det])) {
                throw new RuntimeException("Page « $cle » : calendrier.detail — "
                    . "champ « $det » inconnu.");
            }
            $out['detail'] = $det;
        }

        // Le champ qui dit si la case est libre ou prise, et la valeur qui
        // vaut « pris ». Sans lui, tout créneau saisi occupe la case.
        $etat = (string)($c['etat'] ?? '');
        if ($etat !== '') {
            if (!isset($champs[$etat]) || $champs[$etat]['type'] !== 'choix') {
                throw new RuntimeException("Page « $cle » : calendrier.etat doit être "
                    . 'un champ « choix ».');
            }
            $out['etat'] = $etat;
            /**
             * Valeurs qui RETIRENT la ligne de la grille.
             *
             * Une demande refusée reste en base — on doit pouvoir dire pourquoi
             * elle l'a été, et le demandeur doit voir la réponse. Mais elle n'a
             * rien à faire sur le planning : la case qu'elle occupait est libre,
             * et l'afficher en rouge ferait croire l'inverse.
             *
             * Le calendrier montre ce qui est PRÉVU ; la liste garde tout.
             */
            $out['masquer'] = array_values(array_filter(
                array_map('strval', (array)($c['masquer'] ?? [])),
                fn($v) => in_array($v, $champs[$etat]['valeurs'] ?? [], true)));
            $out['occupe'] = array_values(array_filter(
                array_map('strval', (array)($c['occupe'] ?? [])),
                fn($v) => in_array($v, $champs[$etat]['valeurs'] ?? [], true)));
        }
        return $out;
    }

    /** Largeurs de colonnes : des champs connus, des pixels raisonnables. */
    private static function validerLargeurs(mixed $l, array $champs, string $cle): array
    {
        if (empty($l)) return [];
        if (!is_array($l)) {
            throw new RuntimeException("Page « $cle » : « largeurs » attend un objet "
                . '{ "colonne": pixels }.');
        }
        $out = [];
        foreach ($l as $col => $px) {
            if (!isset($champs[(string)$col])) {
                throw new RuntimeException("Page « $cle » : largeur sur « $col », "
                    . 'champ inconnu.');
            }
            $out[(string)$col] = max(30, min((int)$px, 800));
        }
        return $out;
    }

    /** Boutons d'arbitrage d'une liste : champ « choix » et valeur connue. */
    private static function validerActionsRapides(mixed $liste, array $champs, string $cle): array
    {
        if (empty($liste)) return [];
        if (!is_array($liste)) {
            throw new RuntimeException("Page « $cle » : « actions_rapides » attend un tableau.");
        }
        if (count($liste) > 6) {
            throw new RuntimeException("Page « $cle » : six actions rapides au maximum — "
                . 'au-delà, la colonne devient une barre d\'outils et la liste se lit mal.');
        }
        $out = [];
        foreach ($liste as $a) {
            $ch = (string)($a['champ'] ?? '');
            if (!isset($champs[$ch])) {
                throw new RuntimeException("Page « $cle » : action rapide sur « $ch », "
                    . 'champ inconnu.');
            }
            if (($champs[$ch]['type'] ?? '') !== 'choix') {
                throw new RuntimeException("Page « $cle » : action rapide sur « $ch » de type "
                    . '« ' . $champs[$ch]['type'] . ' ». Un bouton pose une valeur connue : '
                    . 'seuls les champs « choix » en ont.');
            }
            $v = (string)($a['valeur'] ?? '');
            $connues = $champs[$ch]['valeurs'] ?? [];
            if ($connues !== [] && !in_array($v, $connues, true)) {
                throw new RuntimeException("Page « $cle » : « $v » n'est pas une valeur de "
                    . "« $ch ». Valeurs : " . implode(', ', $connues));
            }
            $out[] = [
                'champ'   => $ch,
                'valeur'  => $v,
                'libelle' => mb_substr((string)($a['libelle'] ?? $v), 0, 24),
                'couleur' => self::couleur($a['couleur'] ?? '#2563eb', "action rapide de « $cle »"),
            ];
        }
        return $out;
    }

    private static function validerAncrages(mixed $liste, array $jeux): array
    {
        if (empty($liste)) return [];
        if (!is_array($liste)) throw new RuntimeException('Champ « ancrages » : tableau attendu.');
        if (count($liste) > 24) throw new RuntimeException('Vingt-quatre ancrages au maximum.');

        $out = [];
        foreach ($liste as $a) {
            if (!is_array($a)) {
                throw new RuntimeException('Un ancrage déclaratif est un objet, pas un nom. '
                    . 'Exemple : {"emplacement":"dashboard.tuiles","type":"compteur",'
                    . '"source":"cles","libelle":"Clés"}');
            }

            $type = (string)($a['type'] ?? '');
            if (!isset(self::ANCRAGES[$type])) {
                throw new RuntimeException("Type d'ancrage « $type » inconnu. Types : "
                    . implode(', ', array_keys(self::ANCRAGES)));
            }
            $spec = self::ANCRAGES[$type];

            $emplacement = (string)($a['emplacement'] ?? '');
            if (!in_array($emplacement, $spec['emplacements'], true)) {
                throw new RuntimeException("L'ancrage « $type » ne se place pas dans "
                    . "« $emplacement ». Emplacements possibles : "
                    . implode(', ', $spec['emplacements']));
            }

            $source = (string)($a['source'] ?? '');
            if (!isset($jeux[$source])) {
                throw new RuntimeException("Ancrage « $type » : jeu de données « $source » "
                    . 'inconnu.');
            }
            $champs = $jeux[$source]['champs'];

            $verifier = function (string $c) use ($champs, $type, $source): string {
                if (!isset($champs[$c])) {
                    throw new RuntimeException("Ancrage « $type » : le champ « $c » n'existe "
                        . "pas dans « $source ». Champs : " . implode(', ', array_keys($champs)));
                }
                return $c;
            };

            $v = [
                'type'        => $type,
                'emplacement' => $emplacement,
                'source'      => $source,
                'libelle'     => trim((string)($a['libelle'] ?? $jeux[$source]['libelle'])),
                'filtre'      => [],
            ];

            foreach ($spec['requis'] as $r) {
                if (empty($a[$r])) {
                    throw new RuntimeException("Ancrage « $type » : champ « $r » obligatoire.");
                }
                $v[$r] = $verifier((string)$a[$r]);
            }

            foreach ((array)($a['filtre'] ?? []) as $c => $val) {
                if (is_scalar($val)) $v['filtre'][$verifier((string)$c)] = $val;
            }

            if ($type === 'liste_liee') {
                $cols = [];
                foreach ((array)($a['colonnes'] ?? array_slice(array_keys($champs), 0, 4)) as $c) {
                    $cols[] = $verifier((string)$c);
                }
                $v['colonnes'] = $cols;
            }
            if ($type === 'formulaire') {
                // La page dont on reprend « champs_formulaire » : c'est elle qui
                // décide des champs proposés dans le modal.
                $pg = (string)($a['page'] ?? '');
                if ($pg === '' || !preg_match('/^[a-z][a-z0-9_]{0,39}$/', $pg)) {
                    throw new RuntimeException('Ancrage « formulaire » : champ « page » '
                        . 'obligatoire — la page dont le formulaire sera repris.');
                }
                $v['page']    = $pg;
                $v['libelle'] = mb_substr((string)($a['libelle'] ?? 'Module'), 0, 40);
                $v['icone']   = mb_substr((string)($a['icone'] ?? '🧩'), 0, 4);
            }
            if ($type === 'marqueurs') {
                $v['icone']     = mb_substr((string)($a['icone'] ?? '📍'), 0, 4);
                $v['etiquette'] = $verifier((string)($a['etiquette'] ?? array_key_first($champs)));
            }
            if ($type === 'jauge') {
                $OPS = ['somme', 'moyenne', 'minimum', 'maximum'];
                $op = (string)($a['agregat'] ?? 'somme');
                if (!in_array($op, $OPS, true)) {
                    throw new RuntimeException("Ancrage « jauge » : agrégat « $op » inconnu. "
                        . 'Agrégats : ' . implode(', ', $OPS));
                }
                $t = $champs[$v['champ']]['type'] ?? '';
                if (!in_array($t, ['entier', 'decimal', 'pourcentage', 'duree', 'formule'], true)) {
                    throw new RuntimeException("Ancrage « jauge » : « " . $v['champ']
                        . "\u{a0}» n'est pas un nombre (type « $t »).");
                }
                $v['agregat'] = $op;
                $v['unite']   = mb_substr((string)($a['unite'] ?? ''), 0, 12);
            }
            if ($type === 'compteur' || $type === 'jauge') {
                // Nom OU hexadécimal, comme partout ailleurs. La forme
                // silencieuse d'avant — retomber sur le bleu Larka sans rien
                // dire — masquait les fautes de frappe : l'auteur écrivait
                // « #1b3a5cc », voyait du bleu, et croyait sa couleur appliquée.
                $v['couleur'] = isset($a['couleur'])
                    ? self::couleur($a['couleur'], "Ancrage « $type »")
                    : '#1b3a5c';
            }

            $out[] = $v;
        }
        return $out;
    }

    private static function capacitesBase(array $d): array
    {
        // Un champ « lien » implique de lire le domaine visé, pour afficher un
        // libellé au lieu d'un numéro. La capacité est DÉDUITE : l'auteur ne la
        // demande pas, et ne peut donc pas en réclamer plus que nécessaire.
        $parLien = [];
        foreach ($d['donnees'] ?? [] as $jeu) {
            foreach ($jeu['champs'] ?? [] as $c) {
                if (($c['type'] ?? '') === 'lien') {
                    $parLien[] = 'donnees.lire:' . $c['vers'];
                }
                // Une source de suggestions lit une table du cœur : la capacité
                // est déduite, comme pour un lien. L'auteur ne la demande pas,
                // elle découle de ce qu'il déclare.
                if (!empty($c['suggestions_source'])) {
                    // Pas de « ?? '' » : la source « personnes » a
                    // délibérément une table nulle, et l'opérateur la
                    // transformait en chaîne vide — le match ne la
                    // reconnaissait plus, et la capacité n'était pas déduite.
                    $t = self::SOURCES_SUGGESTION[$c['suggestions_source']]['table'];
                    $parLien[] = match ($t) {
                        null            => 'donnees.lire:noms',
                        'Biens'         => 'donnees.lire:biens',
                        'Equipements'   => 'donnees.lire:equipements',
                        'Interventions' => 'donnees.lire:interventions',
                        default         => null,
                    };
                }
            }
        }
        $parLien = array_values(array_filter($parLien));
        // Un module déclaratif ne peut pas en demander d'autres : elles sont
        // calculées à partir de ce qu'il décrit. Il ne peut donc pas réclamer
        // un accès dont sa déclaration n'a pas l'usage.
        // Un module d'habillage n'a ni page ni table : lui attribuer ces
        // capacités ferait cocher à l'administrateur des accès dont le module
        // n'a aucun usage — et les permissions perdraient leur sens.
        $base = [];
        if (!empty($d['pages']))   $base[] = 'ui.page';
        if (!empty($d['donnees'])) $base[] = 'donnees.table_privee';
        if (!empty($d['fichiers'])) {
            $base[] = 'fichiers.dossier';
            // L'import est une capacité SÉPARÉE : détenir des fichiers livrés
            // avec le module et laisser un utilisateur en déposer ne sont pas
            // le même risque, et l'administrateur doit pouvoir accorder l'un
            // sans l'autre.
            foreach ($d['fichiers'] as $f) {
                if (!empty($f['import'])) { $base[] = 'fichiers.import'; break; }
            }
        }
        if (!empty($d['interface'])) $base[] = 'ui.retouche';

        return array_values(array_unique(array_merge($base, $parLien)));
    }

    /**
     * Libellé lisible d'un type de champ.
     *
     * ⚠️ « formule » N'EST PAS DANS self::TYPES, et c'est voulu : un champ
     * calculé n'a pas de colonne en base, donc pas de traduction SQL à y ranger.
     * Le résumé d'installation l'ignorait, et lisait TYPES['formule'] : deux
     * avertissements PHP par champ calculé, puis un type affiché vide —
     * « Total () ». L'écran par lequel l'administrateur DÉCIDE d'installer
     * présentait donc des champs sans nature, et l'épreuve d'exécution, qui
     * transforme les avertissements en erreurs, tombait sur le premier module
     * comportant une formule.
     */
    private static function libelleType(string $type): string
    {
        return self::TYPES[$type]['libelle']
            ?? match ($type) {
                'formule' => 'Champ calculé',
                default   => $type,
            };
    }

    /** Résumé lisible, pour l'écran d'installation. */
    public static function resume(array $d): array
    {
        $jeux = [];
        foreach ($d['donnees'] ?? [] as $nom => $jeu) {
            $jeux[] = [
                'nom'     => $nom,
                'libelle' => $jeu['libelle_pluriel'] ?? $jeu['libelle'] ?? $nom,
                'champs'  => count($jeu['champs']),
                'detail'  => implode(', ', array_map(
                    fn($c) => ($c['libelle'] ?? '') . ' (' . self::libelleType((string)($c['type'] ?? '')) . ')',
                    $jeu['champs'])),
            ];
        }
        /**
         * Une page « apparence » n'a pas d'actions — sa vue ne porte qu'un type.
         * Les lire sans précaution produisait un avertissement puis un
         * TypeError sur implode(null) : le résumé d'un module d'habillage
         * faisait tomber l'écran d'installation, celui-là même qui doit dire à
         * l'administrateur ce qu'il s'apprête à installer.
         */
        $pages = array_map(function ($p) {
            $actions = $p['vue']['actions'] ?? [];
            $actions = is_array($actions) ? $actions : [];
            $titre   = (string)($p['titre'] ?? '');
            return $actions === [] ? $titre : $titre . ' — ' . implode(', ', $actions);
        }, $d['pages'] ?? []);
        return ['jeux' => $jeux, 'pages' => array_values($pages)];
    }
}

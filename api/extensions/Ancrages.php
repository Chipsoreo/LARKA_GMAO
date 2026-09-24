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
 * Larka — Extensions : points d'ancrage dans l'interface
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Un ancrage permet à une extension d'ajouter un bouton ou un encart DANS une
 * page existante de Larka, là où une page dédiée ne suffit pas — un module de
 * gestion des clés a besoin d'un bouton sur le plan, pas d'un écran à part.
 *
 * DEUX PROPRIÉTÉS QUI RENDENT LA CHOSE TENABLE
 *
 *   1. La liste est FERMÉE. Une extension ne choisit pas où s'insérer : elle
 *      demande un ancrage de ce catalogue, l'administrateur le voit en clair à
 *      l'installation (« ajoute un bouton dans : Plans → barre d'outils »), et
 *      le cœur décide de l'endroit exact. Sans cela, chaque extension irait
 *      manipuler le DOM à sa guise et la moindre refonte d'écran casserait tout.
 *
 *   2. Chaque ancrage reçoit un conteneur À LUI et un contexte réduit. Le cœur
 *      appelle LarkaExtensions.rendrePoint() en lui passant un élément dédié :
 *      l'extension écrit dedans, pas ailleurs.
 *
 * N'INSCRIRE ICI QUE CE QUE LE CŒUR APPELLE VRAIMENT
 * Un emplacement au catalogue que personne ne rend est pire qu'un emplacement
 * absent : l'auteur le déclare, l'administrateur accorde la permission, et rien
 * ne s'affiche jamais — sans message, sans moyen de comprendre. Trois entrées
 * (panneau latéral des plans, fiche équipement, fiche bien) ont été retirées
 * pour cette raison. Elles reviendront le jour où le cœur les appellera.
 *
 * ⚠️ LIMITE ASSUMÉE, LA MÊME QUE POUR TOUT LE CODE CLIENT
 * Rien n'EMPÊCHE techniquement un script d'extension d'ignorer son conteneur et
 * de toucher au reste de la page : le JavaScript d'une extension a les mêmes
 * pouvoirs que celui de Larka (voir docs/EXTENSIONS.md, « Ce qui reste non
 * protégé »). Les ancrages sont une convention de bonne tenue et un contrat de
 * compatibilité, pas une barrière de sécurité. La barrière, côté client, elle
 * n'existe pas.
 * ═══════════════════════════════════════════════════════════════════════════════
 */

final class ExtAncrages
{
    /**
     * Catalogue : nom → page concernée, libellé pour l'écran d'installation,
     * et description de ce que le cœur fournit à l'extension à cet endroit.
     */
    private const CATALOGUE = [
        'demandes.type' => [
            'depuis'  => '1.3',
            'page'    => 'Mes demandes',
            'libelle' => "Ajouter un type dans « Nouvelle demande »",
            'detail'  => "Le module devient un TYPE DE DEMANDE, au même rang que "
                       . "« Technique » et « Archive » : son bouton s'insère entre les "
                       . "deux, et son formulaire s'affiche dans le même modal, à la "
                       . "place des champs du cœur. Un seul bouton d'envoi pour les "
                       . "trois. C'est la place d'un module destiné aux demandeurs, "
                       . "qui n'ont pas de barre latérale.",
            'fournit' => [],
        ],
        'plans.barre_outils' => [
            'depuis'  => '1.1',
            'page'    => 'Plans',
            'libelle' => 'Ajouter un outil dans la barre d\'outils des plans',
            'detail'  => 'Un bouton supplémentaire à côté des outils de dessin. L\'extension reçoit '
                       . 'la carte, l\'étage affiché, et de quoi capter un clic sur le plan.',
            'fournit' => ['map', 'etageId', 'batimentId', 'peutEditer', 'surProchainClic',
                          'surProchainElement', 'annulerSelection', 'elements', 'surligner'],
        ],
        'equipement.fiche' => [
            'depuis'  => '1.1',
            'page'    => 'Équipements',
            'libelle' => 'Ajouter un bloc dans la fiche d\'un équipement',
            'detail'  => 'Un encart en bas de la fiche, avec l\'identifiant de l\'équipement '
                       . 'affiché.',
            'fournit' => ['equipementId'],
        ],
        'bien.fiche' => [
            'depuis'  => '1.1',
            'page'    => 'Biens',
            'libelle' => 'Ajouter un bloc dans la fiche d\'un bien',
            'detail'  => 'Un encart en bas de la fiche, avec l\'identifiant du bien affiché.',
            'fournit' => ['bienId'],
        ],
        'dashboard.tuiles' => [
            'depuis'  => '1.1',
            'page'    => 'Tableau de bord',
            'libelle' => 'Ajouter une tuile au tableau de bord',
            'detail'  => 'Un encart supplémentaire sur la page d\'accueil.',
            'fournit' => [],
        ],
    ];

    /** Version d'API minimale exigée par un jeu d'ancrages. */
    public static function apiMinimale(array $ancrages): string
    {
        $min = '1.0';
        foreach ($ancrages as $a) {
            // Deux formes possibles : un NOM (module à code) ou un OBJET
            // paramétrant une primitive (module déclaratif). Ne traiter que la
            // première faisait échouer la construction des packs de données.
            $nom = is_array($a) ? (string)($a['emplacement'] ?? '') : (string)$a;
            $d = self::CATALOGUE[$nom]['depuis'] ?? '1.0';
            if (version_compare($d, $min, '>')) $min = $d;
        }
        return $min;
    }

    public static function existe(string $nom): bool
    {
        return isset(self::CATALOGUE[$nom]);
    }

    public static function catalogue(): array
    {
        return self::CATALOGUE;
    }

    public static function libelle(string $nom): string
    {
        return self::CATALOGUE[$nom]['libelle'] ?? $nom;
    }

    public static function meta(string $nom): ?array
    {
        return self::CATALOGUE[$nom] ?? null;
    }
}

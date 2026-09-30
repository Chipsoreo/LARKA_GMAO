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
 * Larka — Extensions : éléments d'interface retouchables
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Une entreprise n'utilise pas Chorus Pro, une autre appelle ses « Biens » des
 * « Sites », une troisième veut que « Stats avancées » ouvre son propre tableau
 * de bord. Jusqu'ici, la seule réponse était de modifier Larka.
 *
 * Un module peut désormais MASQUER, RENOMMER ou REDIRIGER une entrée de menu.
 *
 * ⚠️ LA RÈGLE QUI GOUVERNE CE FICHIER, ET QUI A DÉJÀ ÉTÉ APPRISE
 * `Ancrages.php` porte cet avertissement, payé par trois entrées retirées :
 * « N'inscrire ici que ce que le cœur rend vraiment. Un emplacement au
 * catalogue que personne ne rend est pire qu'un emplacement absent : l'auteur
 * le déclare, l'administrateur accorde la permission, et rien ne se produit
 * jamais — sans message, sans moyen de comprendre. »
 *
 * D'où la portée volontairement étroite de ce catalogue : les entrées de MENU,
 * et rien d'autre pour l'instant. Elles portent `data-page` dans nav.js, elles
 * sont adossées à la table PAGES, et elles sont stables. Les boutons d'action
 * à l'intérieur des pages du cœur n'ont PAS d'identifiant stable — les
 * cataloguer aujourd'hui reviendrait à inventer des sélecteurs qui casseraient
 * à la première refonte d'écran. Ils entreront ici quand le cœur les aura
 * marqués, pas avant.
 *
 * CE QUI N'EST JAMAIS RETOUCHABLE
 * Le journal, les extensions, la configuration, les comptes et le multi-tenant.
 * Ce sont les écrans par lesquels on constate ce qu'un module fait et par
 * lesquels on le retire. Un module capable de masquer l'écran des extensions
 * pourrait se rendre indésinstallable depuis l'interface ; un module capable de
 * masquer le journal effacerait la trace de ses propres accès. La liste
 * d'exclusion n'est pas un réglage : c'est ce qui permet d'accorder la
 * permission sans se mettre à la merci du module.
 * ═══════════════════════════════════════════════════════════════════════════════
 */

final class ExtElements
{
    /** Opérations possibles sur un élément. */
    public const OPERATIONS = ['masquer', 'renommer', 'rediriger'];

    /**
     * Entrées de menu retouchables : clé de page → libellé d'origine.
     *
     * Reprend la table PAGES de js/nav.js. Les deux listes doivent rester
     * d'accord ; l'épreuve `test-reference.php` est l'endroit où le vérifier.
     */
    private const MENUS = [
        'dashboard'        => 'Tableau de bord',
        'biens'            => 'Biens',
        'equipements'      => 'Équipements',
        'stock'            => 'Stock',
        'interventions'    => 'Interventions',
        'contrats'         => 'Contrats',
        'energie'          => 'Énergie',
        'carbone'          => 'Bilan Carbone',
        'gestion_materiel' => 'Gestion matériel',
        'plans'            => 'Plans',
        'annuaire'         => 'Annuaire',
        'historique'       => 'Historique',
        'archives'         => 'Archives',
        'statsAvancees'    => 'Stats avancées',
        'demandes'         => 'Demandes en cours',
        'demandesArchives' => 'Demandes clôturées',
        'scan'             => 'Scanner un code',
        'legifrance'       => 'Légifrance',
        'chorus'           => 'Chorus Pro',
        'inventaire_agent' => 'Mes biens',
        'mobilite_carbone' => 'Mobilité carbone',
        'urgences'         => "Procédures d'urgence",
    ];

    /**
     * Écrans hors d'atteinte, quelles que soient les permissions accordées.
     *
     * Ils ne figurent pas dans MENUS — les lister ici est une seconde barrière,
     * délibérément redondante : le jour où quelqu'un ajoutera « journal » au
     * catalogue par mégarde, ce contrôle-ci refusera quand même.
     */
    private const INTOUCHABLES = [
        'journal', 'extensions', 'configuration', 'utilisateurs',
        'superadmin', 'superadmin_branding',
    ];

    public static function existe(string $cle): bool
    {
        return isset(self::MENUS[$cle]) && !in_array($cle, self::INTOUCHABLES, true);
    }

    public static function intouchable(string $cle): bool
    {
        return in_array($cle, self::INTOUCHABLES, true);
    }

    public static function libelle(string $cle): string
    {
        return self::MENUS[$cle] ?? $cle;
    }

    public static function catalogue(): array
    {
        $out = [];
        foreach (self::MENUS as $cle => $lib) {
            if (in_array($cle, self::INTOUCHABLES, true)) continue;
            $out[$cle] = ['libelle' => $lib, 'genre' => 'menu'];
        }
        return $out;
    }

    /** Version d'API minimale exigée par une retouche d'interface. */
    public static function apiMinimale(array $retouches): string
    {
        return $retouches === [] ? '1.0' : '1.3';
    }

    /**
     * Phrase destinée à l'écran d'installation.
     *
     * L'administrateur doit lire ce que le module RETIRE avec la même netteté
     * que ce qu'il ajoute. Une retouche qui masque un écran est plus lourde de
     * conséquences qu'une tuile de plus, et doit se lire comme telle.
     */
    public static function phrase(array $r): string
    {
        $lib = self::libelle($r['element']);
        return match ($r['operation']) {
            'masquer'   => "Masquera l'entrée de menu « $lib » pour "
                         . self::qui($r) . '. L\'écran reste accessible par son adresse ; '
                         . 'seul le raccourci disparaît.',
            'renommer'  => "Renommera l'entrée de menu « $lib » en « "
                         . $r['libelle'] . ' » pour ' . self::qui($r) . '.',
            'rediriger' => "Fera pointer l'entrée de menu « $lib » vers l'écran « "
                         . $r['vers'] . ' » de ce module, pour ' . self::qui($r) . '.',
            default     => '',
        };
    }

    private static function qui(array $r): string
    {
        return empty($r['roles']) ? 'tous les rôles' : implode(', ', $r['roles']);
    }
}

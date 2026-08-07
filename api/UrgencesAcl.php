<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka — Contrôle d'accès des procédures d'urgence
 *
 * Fichier volontairement SANS effet de bord (que des fonctions) : il est
 * inclus à la fois par routes/gestion.php (filtrage de config_check) et par
 * routes/urgences.php (ACL sur les médias), quel que soit l'ordre de chargement.
 *
 * MODÈLE DE VISIBILITÉ (2 niveaux) :
 *
 *   1. Global — clé Configuration `urgences_actif`
 *        '1' → onglet visible par TOUT LE MONDE
 *        '0' → onglet visible par les GESTIONNAIRES/ADMIN uniquement
 *
 *   2. Par catégorie — champ `visibilite` dans urgences_config
 *        'tous'          → visible par tous les rôles (défaut)
 *        'gestionnaires' → réservée aux Gestionnaires/Admin
 *
 *   Le filtrage est fait CÔTÉ SERVEUR : le contenu réservé ne transite jamais
 *   vers un Demandeur / Technicien / Visionneur, même si le front est modifié.
 */

if (!function_exists('urg_ext_map')) {
    /**
     * Extensions autorisées → [type logique, MIME servi].
     * Source de vérité unique : l'upload, la lecture et la suppression s'y
     * réfèrent, de sorte qu'aucun chemin ne puisse accepter un type que les
     * autres refusent.
     */
    function urg_ext_map(): array {
        return [
            // Photos
            'png'  => ['photo', 'image/png'],
            'jpg'  => ['photo', 'image/jpeg'],
            'jpeg' => ['photo', 'image/jpeg'],
            'gif'  => ['photo', 'image/gif'],
            'webp' => ['photo', 'image/webp'],
            // Vidéos
            'mp4'  => ['video', 'video/mp4'],
            'm4v'  => ['video', 'video/mp4'],
            'webm' => ['video', 'video/webm'],
            'ogv'  => ['video', 'video/ogg'],
            'mov'  => ['video', 'video/quicktime'],
        ];
    }
}

if (!function_exists('urg_media_name_ok')) {
    /**
     * Valide un nom de fichier de média.
     *
     * Le motif est CONSTRUIT à partir de urg_ext_map() : une classe générique
     * comme [a-z0-9]{2,4} laisserait passer « .php ». Les ancres ^…$ combinées
     * à \z et l'absence de /m garantissent qu'aucun octet (nul, saut de ligne)
     * ne peut être ajouté après l'extension.
     */
    function urg_media_name_ok(string $nom): bool {
        $exts = implode('|', array_map('preg_quote', array_keys(urg_ext_map())));
        return (bool)preg_match('/^urg_(?:photo|video)_[0-9]+_[a-f0-9]{6,32}\.(?:' . $exts . ')\z/D', $nom);
    }
}

if (!function_exists('urg_is_manager')) {
    /** Rôles autorisés à voir les procédures restreintes. */
    function urg_is_manager(array $user): bool {
        return in_array($user['Role'] ?? '', ['Admin', 'Gestionnaire'], true);
    }
}

if (!function_exists('urg_filter_config_for_user')) {
    /**
     * Retire les catégories réservées aux gestionnaires quand l'utilisateur
     * n'en est pas un.
     *
     * @param array $cfg  Structure { categories: [...] }
     * @param array $user Utilisateur de session (doit contenir 'Role')
     */
    function urg_filter_config_for_user(array $cfg, array $user): array {
        if (urg_is_manager($user)) return $cfg;
        $cats = [];
        foreach (($cfg['categories'] ?? []) as $cat) {
            if (!is_array($cat)) continue;
            if (($cat['visibilite'] ?? 'tous') === 'gestionnaires') continue;
            $cats[] = $cat;
        }
        $cfg['categories'] = $cats;
        return $cfg;
    }
}

if (!function_exists('urg_read_config')) {
    /**
     * Lit et décode urgences_config depuis la table Configuration.
     *
     * Mémoïsé : une page d'urgence peut demander une dizaine de médias, et
     * chacun déclenche un contrôle d'accès. Sans ce cache on relirait la table
     * à chaque requête pour un résultat identique.
     */
    function urg_read_config($db): array {
        static $cache = null;
        if ($cache !== null) return $cache;

        $cache = ['categories' => []];
        try {
            $row = $db->fetchOne(
                "SELECT Valeur FROM Configuration WHERE Cle = :cle",
                ['cle' => 'urgences_config']
            );
            if (!empty($row['Valeur'])) {
                $parsed = json_decode($row['Valeur'], true);
                if (is_array($parsed) && isset($parsed['categories']) && is_array($parsed['categories'])) {
                    $cache = $parsed;
                }
            }
        } catch (\Throwable $e) { /* config absente → structure vide */ }
        return $cache;
    }
}

if (!function_exists('urg_media_is_restricted')) {
    /**
     * Un fichier donné appartient-il à une catégorie « Gestionnaires uniquement » ?
     * Un fichier orphelin (référencé nulle part) est considéré restreint, par
     * principe de précaution.
     */
    function urg_media_is_restricted($db, string $fichier): bool {
        $cfg = urg_read_config($db);
        foreach (($cfg['categories'] ?? []) as $cat) {
            if (!is_array($cat)) continue;
            foreach (($cat['medias'] ?? []) as $m) {
                if (is_array($m) && ($m['fichier'] ?? '') === $fichier) {
                    return (($cat['visibilite'] ?? 'tous') === 'gestionnaires');
                }
            }
        }
        return true;
    }
}

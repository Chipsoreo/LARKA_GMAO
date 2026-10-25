<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka — chemins de fichiers, sous Linux, macOS ET Windows.
 *
 * ⚠️ « ABSOLU » VOULAIT DIRE « COMMENCE PAR / ».
 * Partout, un chemin était jugé relatif s'il ne commençait pas par « / », puis
 * préfixé par le dossier de l'application. Sous Windows, un chemin absolu
 * s'écrit « C:\… » : la base SQLite, résolue en « C:\larka\api/../data/gmao.db »
 * par config.php, était préfixée une seconde fois par Database —
 * « C:\larka\api/../C:\larka\api/../data/gmao.db » —, la base de l'installation
 * n'était jamais ouverte, et Larka repartait d'une base vide (reproduit avec
 * PHP pour Windows). Ces deux fonctions sont désormais la seule façon d'en juger.
 */

if (!function_exists('larka_chemin_absolu')) {
    /**
     * Le chemin est-il absolu ? « /var/… », « C:\… », « C:/… », « \\serveur\partage\… »
     * (ou « \… », racine du lecteur courant sous Windows).
     */
    function larka_chemin_absolu(string $p): bool
    {
        if ($p === '') return false;
        if ($p[0] === '/' || $p[0] === '\\') return true;
        return (bool)preg_match('~^[A-Za-z]:[\\\\/]~', $p);
    }
}

if (!function_exists('larka_chemin')) {
    /**
     * Chemin absolu : rendu tel quel s'il l'est déjà, sinon rattaché à $base
     * (par défaut, la racine de l'application).
     */
    function larka_chemin(string $p, ?string $base = null): string
    {
        if (larka_chemin_absolu($p)) return $p;
        $base = $base ?? dirname(__DIR__);
        return rtrim($base, '/\\') . '/' . ltrim($p, '/\\');
    }
}

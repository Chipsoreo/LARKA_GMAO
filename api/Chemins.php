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
 * « ABSOLU » VOULAIT DIRE « COMMENCE PAR / ».
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

if (!function_exists('larka_pg_sslmode')) {
    /**
     * Mode TLS effectif d'une connexion PostgreSQL.
     *
     * « prefer » (le défaut) négocie TLS dès que le serveur le propose — c'est le
     * cas d'une installation Ubuntu standard. Sur la boucle locale, cela ne
     * protège rien (rien ne quitte la machine) et coûte une poignée de main TLS à
     * CHAQUE requête API, qui ouvre ses connexions : ~3 ms chacune, mesuré.
     * Pour un hôte local, « prefer » (et « allow ») deviennent donc « disable ».
     * Un mode exigeant (« require », « verify-ca », « verify-full ») est respecté
     * tel quel, et un hôte distant garde le mode configuré.
     */
    function larka_pg_sslmode(?string $hote, ?string $mode): string
    {
        $mode = strtolower(trim((string)$mode)) ?: 'prefer';
        $hote = strtolower(trim((string)$hote));
        if (in_array($mode, ['prefer', 'allow'], true)
            && in_array($hote, ['127.0.0.1', '::1', '[::1]', 'localhost'], true)) {
            return 'disable';
        }
        return $mode;
    }
}

if (!function_exists('larka_sqlite_reglages')) {
    /**
     * Réglages d'une connexion SQLite.
     *
     * Cas général : journal WAL (lectures et écritures simultanées).
     *
     * Dans l'app mobile (constante LARKA_EMBARQUE, posée par le moteur
     * embarqué — voir mobile/), PHP tourne dans le téléphone et ses fichiers
     * sont en mémoire : c'est le moteur qui les recopie sur l'appareil après
     * chaque requête. Un journal sur « disque » n'y protège donc rien, et le
     * WAL demande une mémoire partagée que ce système de fichiers n'a pas :
     * journal en mémoire, sans synchronisation disque.
     */
    function larka_sqlite_reglages(\PDO $pdo): void
    {
        if (defined('LARKA_EMBARQUE') && LARKA_EMBARQUE) {
            $pdo->exec('PRAGMA journal_mode = MEMORY;');
            $pdo->exec('PRAGMA synchronous = OFF;');
            $pdo->exec('PRAGMA temp_store = MEMORY;');
            return;
        }
        $pdo->exec('PRAGMA journal_mode = WAL;');
    }
}

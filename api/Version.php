<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka — Version de l'application.
 *
 * SOURCE UNIQUE : version.json à la racine. La page de connexion, l'écran des
 * mises à jour et l'outil de publication la lisent ici ; personne ne recopie
 * le numéro à la main (c'est ainsi qu'il finit par mentir).
 *
 *   { "version": "2.0.0", "canal": "Beta", "date": "2026-09-24" }
 *   → libellé affiché : « V.Beta 2.0.0 »  (canal « Stable » → « V 2.0.0 »)
 */
final class LarkaVersion
{
    public static function fichier(): string { return dirname(__DIR__) . '/version.json'; }

    public static function lire(?string $fichier = null): array
    {
        $f = $fichier ?? self::fichier();
        $d = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
        $v = preg_match('/^\d+\.\d+\.\d+/', (string)($d['version'] ?? ''), $m) ? $m[0] : '0.0.0';
        return [
            'version' => $v,
            'canal'   => trim((string)($d['canal'] ?? 'Stable')) ?: 'Stable',
            'date'    => (string)($d['date'] ?? ''),
            // Version de PHP exigée par ce paquet (publier-version.php --php-min), vérifiée avant installation.
            'php_min' => preg_match('/^\d+\.\d+/', (string)($d['php_min'] ?? ''), $p) ? $p[0] : null,
        ];
    }

    public static function libelle(?array $v = null): string
    {
        $v ??= self::lire();
        return strcasecmp($v['canal'], 'Stable') === 0 ? 'V ' . $v['version'] : 'V.' . $v['canal'] . ' ' . $v['version'];
    }
}

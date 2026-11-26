<?php
/*
 * SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
 * SPDX-License-Identifier: LicenseRef-Larka-Proprietary
 *
 * This file is part of Larka, proprietary software by Mickaël Larcin.
 * All rights reserved. Use is subject to the license terms; copying,
 * distribution, modification or reverse-engineering without the author's
 * prior written permission is prohibited. See the LICENSE file for details.
 */

/**
 * Icônes Lucide choisies par l'organisation dans la bibliothèque complète.
 *
 * Larka embarque le dessin d'environ 270 icônes (css/lucide.css, css/lucide-
 * pictos.css). Lucide en compte environ 2 000 : les embarquer toutes ferait
 * charger 1,4 Mo de CSS à chaque page, pour quelques icônes posées sur des plans.
 *
 * Le sélecteur charge donc la bibliothèque complète (js/lucide-complet.json)
 * seulement quand on l'ouvre. Une icône choisie dedans est ENREGISTRÉE par son
 * nom (Configuration « icones_choisies ») ; le serveur compose alors son dessin
 * à partir de ce même fichier — jamais à partir de ce qu'envoie le navigateur —
 * et le sert à tous, une fois, au chargement de l'application (quelques Ko).
 */
final class IconesLucide
{
    public const MAX = 500;
    private static ?array $bibliotheque = null;

    private static function bibliotheque(): array
    {
        if (self::$bibliotheque === null) {
            $j = json_decode((string)@file_get_contents(dirname(__DIR__) . '/js/lucide-complet.json'), true);
            self::$bibliotheque = is_array($j['icones'] ?? null) ? $j['icones'] : [];
        }
        return self::$bibliotheque;
    }

    public static function existe(string $nom): bool
    {
        return preg_match('/^[a-z0-9-]{1,60}$/', $nom) === 1 && isset(self::bibliotheque()[$nom]);
    }

    /** Règle CSS d'une icône : même forme que css/lucide.css (masque au trait). */
    public static function regle(string $nom): string
    {
        if (!self::existe($nom)) return '';
        $corps = (string)(self::bibliotheque()[$nom][1] ?? '');
        $svg = "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='black' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'>"
             . str_replace('"', "'", $corps) . '</svg>';
        $uri = strtr(rawurlencode($svg), ['%20' => ' ', '%3D' => '=', '%3A' => ':', '%2F' => '/', '%27' => "'", '%2C' => ',', '%3B' => ';']);
        return ".lk-$nom{--lk:url(\"data:image/svg+xml,$uri\")}";
    }

    /** Noms enregistrés par l'organisation (filtrés : seulement des icônes qui existent). */
    public static function choisies(Database $db): array
    {
        $j = json_decode($db->getConfig('icones_choisies'), true);
        return is_array($j) ? array_values(array_filter($j, fn($n) => is_string($n) && self::existe($n))) : [];
    }

    public static function css(array $noms): string
    {
        return implode("\n", array_filter(array_map([self::class, 'regle'], $noms)));
    }

    /** Ajoute une icône (idempotent). Rend sa règle CSS. */
    public static function ajouter(Database $db, string $nom): string
    {
        if (!self::existe($nom)) throw new \InvalidArgumentException('Icône inconnue de Lucide : « ' . mb_substr($nom, 0, 60) . ' ».');
        $noms = self::choisies($db);
        if (!in_array($nom, $noms, true)) {
            if (count($noms) >= self::MAX) throw new \InvalidArgumentException('Trop d\'icônes choisies (' . self::MAX . ' au plus).');
            $noms[] = $nom;
            $db->setConfig('icones_choisies', json_encode($noms));
        }
        return self::regle($nom);
    }
}

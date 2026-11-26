<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka — lecture des valeurs d'un fichier importé (Excel, CSV).
 *
 * Les mêmes règles que l'import des biens et des équipements, sorties dans une
 * classe pour servir aux autres imports (interventions…) :
 *
 *   date()    seules les dates RÉELLES passent (31/02 est refusé, pas « arrangé »
 *             en 3 mars) : JJ/MM/AAAA, AAAA-MM-JJ, numéro de série Excel,
 *             « 10 janvier 2026 » ; null = absente ou illisible ;
 *   nombre()  « 1 234,56 € », « 1.234,56 », « (12,50) » ; null = illisible ;
 *   texte()   espaces retirés, « 1234.0 » d'Excel ramené à « 1234 » ;
 *   cle()     forme de comparaison : sans accents ni casse ni ponctuation.
 */

declare(strict_types=1);

final class ImportOutils
{
    private const MOIS = ['janv' => 1, 'jan' => 1, 'fevr' => 2, 'fev' => 2, 'feb' => 2, 'mars' => 3, 'mar' => 3, 'avr' => 4, 'apr' => 4,
                          'mai' => 5, 'may' => 5, 'juin' => 6, 'jun' => 6, 'juil' => 7, 'jul' => 7, 'aout' => 8, 'aug' => 8,
                          'sept' => 9, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];

    /** « AAAA-MM-JJ », ou null si la cellule est vide ou n'est pas une date réelle. */
    public static function date(mixed $v): ?string
    {
        if ($v === null || $v === false) return null;
        $s = trim((string)$v);
        if ($s === '' || $s === '0' || strcasecmp($s, 'null') === 0) return null;
        $ok = static function ($y, $mo, $d): ?string {
            $y = (int)$y; $mo = (int)$mo; $d = (int)$d;
            if ($y < 100) $y += ($y < 50 ? 2000 : 1900);
            return ($y >= 1900 && $y <= 2100 && checkdate($mo, $d, $y)) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
        };
        if (preg_match('#^(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})#', $s, $m)) return $ok($m[1], $m[2], $m[3]);
        if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{2}|\d{4})(?!\d)#', $s, $m)) return $ok($m[3], $m[2], $m[1]);
        if (preg_match('/^\d{5}(\.\d+)?$/', $s) && (float)$s > 20000 && (float)$s < 80000) {
            return gmdate('Y-m-d', (int)round(((float)$s - 25569) * 86400));
        }
        $n = self::cle($s);
        if (preg_match('/^(?:(\d{1,2})(?:er)?\s+)?([a-z]{3,9})\s+(\d{4})$/', $n, $m)) {
            foreach (self::MOIS as $k => $no) if (str_starts_with($m[2], $k)) return $ok($m[3], $no, $m[1] !== '' ? $m[1] : 1);
        }
        return null;
    }

    /** La cellule est-elle remplie mais illisible comme date ? */
    public static function dateIllisible(mixed $v): bool
    {
        $s = trim((string)($v ?? ''));
        return $s !== '' && $s !== '0' && self::date($s) === null;
    }

    /** Nombre ; 0.0 pour une cellule vide ; null pour une valeur présente mais illisible. */
    public static function nombre(mixed $v): ?float
    {
        if ($v === null || $v === '' || $v === false) return 0.0;
        if (is_int($v) || is_float($v)) return (float)$v;
        $s = trim((string)$v);
        if ($s === '' || $s === '-') return 0.0;
        $neg = false;
        if (preg_match('/^\((.*)\)$/', $s, $m)) { $neg = true; $s = $m[1]; }
        $s = preg_replace('/[\s\x{00A0}\x{202F}\x{2009}\'’]|€|eur(os?)?|\bht\b|\bttc\b|\bh\b|heures?/iu', '', $s) ?? '';
        if (str_starts_with($s, '-')) { $neg = !$neg; $s = substr($s, 1); }
        if ($s === '' || !preg_match('/^[0-9.,]+$/', $s)) return null;
        $p = strrpos($s, '.'); $c = strrpos($s, ',');
        if ($p !== false && $c !== false) {
            $dec = $p > $c ? '.' : ',';
            $s = str_replace($dec === '.' ? ',' : '.', '', $s);
            $s = str_replace(',', '.', $s);
        } elseif ($c !== false) {
            $s = substr_count($s, ',') > 1 ? str_replace(',', '', $s) : str_replace(',', '.', $s);
        } elseif ($p !== false && substr_count($s, '.') > 1) {
            $s = str_replace('.', '', $s);
        }
        if (!is_numeric($s)) return null;
        return $neg ? -(float)$s : (float)$s;
    }

    /** Durée en heures : « 2,5 », « 2h30 », « 1:45 », « 90 min ». null = illisible. */
    public static function heures(mixed $v): ?float
    {
        $s = trim((string)($v ?? ''));
        if ($s === '') return 0.0;
        if (preg_match('/^(\d{1,4})\s*(?:h|:)\s*(\d{1,2})?\s*(?:min)?$/iu', $s, $m)) return (int)$m[1] + (isset($m[2]) && $m[2] !== '' ? (int)$m[2] / 60 : 0);
        if (preg_match('/^(\d+(?:[.,]\d+)?)\s*min(?:utes?)?$/iu', $s, $m)) return (float)str_replace(',', '.', $m[1]) / 60;
        return self::nombre($s);
    }

    public static function texte(mixed $v, int $max = 2000): string
    {
        if ($v === null) return '';
        $s = trim((string)$v);
        if (preg_match('/^(\d+)\.0+$/', $s, $m)) $s = $m[1];
        return mb_substr($s, 0, $max);
    }

    /** Forme de comparaison : minuscules, sans accents, ponctuation en espaces. */
    public static function cle(mixed $v): string
    {
        $s = mb_strtolower(trim((string)($v ?? '')));
        if (class_exists('Normalizer')) {
            $n = \Normalizer::normalize($s, \Normalizer::FORM_D);
            if (is_string($n)) $s = preg_replace('/\p{Mn}+/u', '', $n) ?? $s;
        } else {
            $s = strtr($s, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'î' => 'i', 'ï' => 'i',
                            'ô' => 'o', 'ö' => 'o', 'û' => 'u', 'ù' => 'u', 'ü' => 'u', 'ç' => 'c']);
        }
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9]+/u', ' ', $s) ?? '') ?? '');
    }
}

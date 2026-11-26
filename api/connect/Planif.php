<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka Connect — calendriers des liaisons.
 *
 * « Chaque jour à 23 h 55 », « le dernier jour du mois », « du lundi au
 * vendredi, d'octobre à avril », ou une expression cron pour le reste.
 *
 * Les heures sont des heures LÉGALES (fuseau de l'application), pas UTC : un
 * relevé « à 23 h 55 » reste à 23 h 55 après le changement d'heure. Une heure
 * qui n'existe pas (2 h 30 la nuit du passage à l'heure d'été) est reportée à
 * la première heure qui existe ; une heure qui existe deux fois (la nuit du
 * retour à l'heure d'hiver) n'est exécutée qu'une fois : les échéances se
 * comparent en instants absolus.
 */

declare(strict_types=1);

final class ConnectPlanif
{
    private const JOURS = [1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi', 5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche'];
    private const MOIS = [1 => 'janvier', 2 => 'février', 3 => 'mars', 4 => 'avril', 5 => 'mai', 6 => 'juin', 7 => 'juillet',
                          8 => 'août', 9 => 'septembre', 10 => 'octobre', 11 => 'novembre', 12 => 'décembre'];

    private static function liste(?string $v): array
    {
        if ($v === null || trim($v) === '') return [];
        return array_values(array_filter(array_map('intval', explode(',', $v))));
    }

    /** Première échéance strictement postérieure à $apres (null : aucune dans les deux ans). */
    public static function prochaine(array $l, DateTimeImmutable $apres): ?DateTimeImmutable
    {
        $tz = new DateTimeZone(date_default_timezone_get());
        $apres = $apres->setTimezone($tz);
        $freq = (string)($l['Frequence'] ?? 'quotidien');
        if ($freq === 'releve') return null;                       // pas de calendrier : déclenché par chaque relevé reçu
        if ($freq === 'personnalise') return self::prochaineCron((string)($l['ExpressionCron'] ?? ''), $apres);
        [$h, $m] = array_map('intval', explode(':', (string)($l['Heure'] ?? '23:55')) + [0, 0]);
        $jours = self::liste($l['JoursSemaine'] ?? null);
        $mois = self::liste($l['MoisActifs'] ?? null);
        $jm = (int)($l['JourMois'] ?? 1);
        $jour = $apres->setTime(0, 0);
        for ($i = 0; $i < 800; $i++, $jour = $jour->modify('+1 day')) {
            if ($mois && !in_array((int)$jour->format('n'), $mois, true)) continue;
            $dow = (int)$jour->format('N');
            if ($freq === 'hebdomadaire' && !in_array($dow, $jours ?: [1], true)) continue;
            if ($freq === 'quotidien' && $jours && !in_array($dow, $jours, true)) continue;
            if ($freq === 'mensuel') {
                $cible = $jm === -1 ? (int)$jour->format('t') : min($jm, (int)$jour->format('t'));
                if ((int)$jour->format('j') !== $cible) continue;
            }
            $c = $jour->setTime($h, $m);
            if ($c->getTimestamp() > $apres->getTimestamp()) return $c;
        }
        return null;
    }

    // ── cron ───────────────────────────────────────────────────────────────

    /** Valeurs d'un champ cron (« * », « 1,5 », « 1-5 », « *​/15 », « 1-10/2 »). */
    private static function champ(string $c, int $min, int $max): ?array
    {
        $vals = [];
        foreach (explode(',', $c) as $partie) {
            if (!preg_match('/^(\*|\d+(?:-\d+)?)(?:\/(\d+))?$/', $partie, $m)) return null;
            $pas = isset($m[2]) ? (int)$m[2] : 1;
            if ($pas < 1) return null;
            if ($m[1] === '*') { $a = $min; $b = $max; }
            elseif (str_contains($m[1], '-')) { [$a, $b] = array_map('intval', explode('-', $m[1])); }
            else { $a = (int)$m[1]; $b = isset($m[2]) ? $max : $a; }
            if ($a < $min || $b > $max || $a > $b) return null;
            for ($v = $a; $v <= $b; $v += $pas) $vals[$v] = true;
        }
        $out = array_keys($vals);
        sort($out);
        return $out;
    }

    /** @return array{0:list<int>,1:list<int>,2:list<int>,3:list<int>,4:list<int>,5:bool,6:bool}|null */
    private static function analyserCron(string $expr): ?array
    {
        $f = preg_split('/\s+/', trim($expr));
        if (!$f || count($f) !== 5) return null;
        $mi = self::champ($f[0], 0, 59); $he = self::champ($f[1], 0, 23); $jo = self::champ($f[2], 1, 31);
        $mo = self::champ($f[3], 1, 12); $js = self::champ(str_replace('7', '0', $f[4]), 0, 6);
        if ($mi === null || $he === null || $jo === null || $mo === null || $js === null) return null;
        return [$mi, $he, $jo, $mo, $js, $f[2] !== '*', $f[4] !== '*'];
    }

    public static function cronValide(string $expr): bool
    {
        return self::analyserCron($expr) !== null;
    }

    private static function prochaineCron(string $expr, DateTimeImmutable $apres): ?DateTimeImmutable
    {
        $c = self::analyserCron($expr);
        if ($c === null) return null;
        [$mi, $he, $jo, $mo, $js, $jourRestreint, $semRestreint] = $c;
        $jour = $apres->setTime(0, 0);
        for ($i = 0; $i < 800; $i++, $jour = $jour->modify('+1 day')) {
            if (!in_array((int)$jour->format('n'), $mo, true)) continue;
            $okJour = in_array((int)$jour->format('j'), $jo, true);
            $okSem = in_array((int)$jour->format('w'), $js, true);
            // Règle cron : si jour du mois ET jour de semaine sont restreints, l'un OU l'autre suffit.
            $ok = ($jourRestreint && $semRestreint) ? ($okJour || $okSem) : ($okJour && $okSem);
            if (!$ok) continue;
            foreach ($he as $h) foreach ($mi as $m) {
                $cand = $jour->setTime($h, $m);
                if ($cand->getTimestamp() > $apres->getTimestamp()) return $cand;
            }
        }
        return null;
    }

    // ── Libellé ────────────────────────────────────────────────────────────

    private const JOURS_EN = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
    private const MOIS_EN = [1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June', 7 => 'July',
                             8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'];

    /**
     * Une suite de jours ou de mois : PLAGE contiguë (« du lundi au vendredi »,
     * y compris à cheval sur l'année : octobre → avril) ou LISTE.
     * @return array{0: string, 1: int[]}  ['plage', [début, fin]] ou ['liste', valeurs]
     */
    private static function suite(array $vals, int $cycle = 0): array
    {
        sort($vals);
        if (count($vals) > 2 && $vals === range($vals[0], end($vals))) return ['plage', [$vals[0], end($vals)]];
        if ($cycle > 0 && count($vals) > 2) {
            for ($i = 1; $i < count($vals); $i++) {
                if ($vals[$i] - $vals[$i - 1] > 1) {
                    $tourne = array_merge(array_slice($vals, $i), array_slice($vals, 0, $i));
                    $ok = true;
                    for ($k = 1; $k < count($tourne); $k++) {
                        if (($tourne[$k - 1] % $cycle) + 1 !== $tourne[$k]) { $ok = false; break; }
                    }
                    if ($ok) return ['plage', [$tourne[0], end($tourne)]];
                    break;
                }
            }
        }
        return ['liste', $vals];
    }

    private static function enumeration(array $vals, array $noms, string $et): string
    {
        $txt = array_map(fn($v) => $noms[$v] ?? (string)$v, $vals);
        $dernier = array_pop($txt);
        return $txt ? implode(', ', $txt) . " $et " . $dernier : (string)$dernier;
    }

    /** « Chaque jour à 23 h 55, d'octobre à avril » — ou en anglais (« Every day at 23:55, from October to April »). */
    public static function libelle(array $l, bool $en = false): string
    {
        $freq = (string)($l['Frequence'] ?? 'quotidien');
        $hm = (string)($l['Heure'] ?? '23:55');
        if ($freq === 'releve') {
            return $en ? 'At every reading of the reader PC (off-network device), with the time of the reading'
                       : 'À chaque relevé du PC releveur (appareil hors réseau), à l\'heure du relevé';
        }
        if ($freq === 'personnalise') {
            $expr = (string)($l['ExpressionCron'] ?? '');
            return $en ? "According to the expression “{$expr}”" : "Selon l'expression « {$expr} »";
        }
        $heure = $en ? $hm : str_replace(':', ' h ', $hm);
        $a = $en ? 'at' : 'à';
        $jours = self::liste($l['JoursSemaine'] ?? null);
        $mois = self::liste($l['MoisActifs'] ?? null);
        $J = $en ? self::JOURS_EN : self::JOURS;
        if ($freq === 'hebdomadaire') {
            $jours = $jours ?: [1];
            if (count($jours) === 1) {
                $t = ($en ? 'Every ' : 'Chaque ') . $J[$jours[0]];
            } else {
                [$forme, $v] = self::suite($jours);
                $t = ($en ? 'Every week, ' : 'Chaque semaine, ') . ($forme === 'plage'
                    ? ($en ? "from {$J[$v[0]]} to {$J[$v[1]]}" : "du {$J[$v[0]]} au {$J[$v[1]]}")
                    : self::enumeration($v, $J, $en ? 'and' : 'et'));
            }
        } elseif ($freq === 'mensuel') {
            $jm = (int)($l['JourMois'] ?? 1);
            if ($en) {
                $suffixe = ($jm % 100 >= 11 && $jm % 100 <= 13) ? 'th' : ([1 => 'st', 2 => 'nd', 3 => 'rd'][$jm % 10] ?? 'th');
                $t = $jm === -1 ? 'The last day of the month' : "The {$jm}{$suffixe} of every month";
            } else {
                $t = $jm === -1 ? 'Le dernier jour du mois' : 'Le ' . ($jm === 1 ? '1er' : $jm) . ' de chaque mois';
            }
        } elseif ($jours && count($jours) < 7) {
            [$forme, $v] = self::suite($jours);
            $t = $forme === 'plage'
                ? ($en ? "From {$J[$v[0]]} to {$J[$v[1]]}" : "Du {$J[$v[0]]} au {$J[$v[1]]}")
                : ($en ? 'Every ' : 'Chaque ') . self::enumeration($v, $J, $en ? 'and' : 'et');
        } else {
            $t = $en ? 'Every day' : 'Tous les jours';
        }
        $t .= " $a $heure";
        if ($mois && count($mois) < 12) {
            $M = $en ? self::MOIS_EN : self::MOIS;
            [$forme, $v] = self::suite($mois, 12);
            if ($forme === 'plage') {
                $de = $M[$v[0]];
                $t .= $en ? ", from $de to {$M[$v[1]]}"
                          : ', ' . (preg_match('/^[aeiouyéèêâî]/u', $de) ? "d'" : 'de ') . $de . ' à ' . $M[$v[1]];
            } else {
                $t .= ($en ? ', in ' : ', en ') . self::enumeration($v, $M, $en ? 'and' : 'et');
            }
        }
        return $t;
    }
}

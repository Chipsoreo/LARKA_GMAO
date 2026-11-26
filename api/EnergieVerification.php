<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka — module Énergie : contrôle des saisies et vérification des relevés.
 *
 * POURQUOI CE FICHIER
 * Le serveur enregistrait un relevé tel que l'écran l'envoyait : date libre,
 * index de fin sous l'index de début, consommation sans rapport avec les index,
 * compteur inexistant. Et rien ne permettait de relire une série de relevés : un
 * index précédent faux (import, saisie à la main, relevé intercalé) se voyait
 * seulement des mois plus tard, sur un graphique qui ne tombait pas juste.
 *
 *   normaliser() : contrôle d'une saisie (routes/energie.php, POST et PUT) ;
 *   verifier()   : relecture de tous les relevés, compteur par compteur, avec
 *                  la règle d'enchaînement du formulaire (energie.releve.js) —
 *                  l'index de début d'un relevé est l'index de fin du relevé le
 *                  plus récent STRICTEMENT avant lui, du même type de saisie de
 *                  préférence.
 */
final class EnergieVerification
{
    /** Champs numériques d'une saisie (clé de la requête). */
    private const NOMBRES = ['indexDebut', 'indexFin', 'consommation', 'montant', 'montantHT', 'prixUnitHT', 'tva', 'tvaReleve', 'carbone'];

    /** Nombre saisi : null si vide, float sinon (virgule décimale et espaces acceptés), false si illisible. */
    private static function nombre($v): float|false|null
    {
        if ($v === null || $v === '') return null;
        if (is_int($v) || is_float($v)) return is_finite((float)$v) ? (float)$v : false;
        if (!is_string($v)) return false;
        $t = str_replace(["\u{00A0}", "\u{202F}", ' '], '', trim($v));
        if (preg_match('/^-?\d+,\d+$/', $t)) $t = str_replace(',', '.', $t);
        return is_numeric($t) ? (float)$t : false;
    }

    /** Date « AAAA-MM-JJ » réelle ? */
    private static function dateValide(string $d): bool
    {
        return (bool)preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
    }

    /**
     * Contrôle une saisie de relevé ou de facture. Rend [données corrigées, null] ou [null, message].
     * Un index à 0 reste 0 (il partait à « vide » depuis l'écran) ; une consommation absente
     * est déduite des index.
     */
    public static function normaliser(Database $db, array $d): array
    {
        $cid = (int)($d['compteurId'] ?? 0);
        if ($cid <= 0 || !$db->fetchOne('SELECT Id FROM CompteurEnergie WHERE Id = :id', ['id' => $cid])) {
            return [null, 'Compteur introuvable : choisissez le compteur du relevé.'];
        }
        $d['compteurId'] = $cid;
        $date = substr(trim((string)($d['date'] ?? '')), 0, 10);
        if (!self::dateValide($date)) return [null, 'Date du relevé manquante ou invalide (JJ/MM/AAAA).'];
        $d['date'] = $date;
        $type = (string)($d['type'] ?? '') ?: 'Releve';
        if (!in_array($type, ['Releve', 'Facture'], true)) return [null, 'Type de saisie inconnu : relevé ou facture.'];
        $d['type'] = $type;
        $libelles = ['indexDebut' => 'Index précédent', 'indexFin' => 'Index', 'consommation' => 'Consommation', 'montant' => 'Montant TTC',
                     'montantHT' => 'Montant HT', 'prixUnitHT' => 'Prix unitaire', 'tva' => 'TVA', 'tvaReleve' => 'TVA', 'carbone' => 'CO₂'];
        foreach (self::NOMBRES as $k) {
            if (!array_key_exists($k, $d)) continue;
            $n = self::nombre($d[$k]);
            if ($n === false) return [null, ($libelles[$k] ?? $k) . ' : nombre illisible.'];
            $d[$k] = $n;
        }
        $deb = $d['indexDebut'] ?? null;
        $fin = $d['indexFin'] ?? null;
        if ($deb !== null && $fin !== null && $fin < $deb) {
            return [null, "L'index (" . self::fr($fin) . ") est inférieur à l'index précédent (" . self::fr($deb) . ") : erreur de saisie, "
                        . "ou compteur remplacé — saisissez alors comme index précédent celui du nouveau compteur."];
        }
        if (($d['consommation'] ?? null) === null && $deb !== null && $fin !== null) $d['consommation'] = round($fin - $deb, 6);
        foreach (['periodeDebut' => 'Début de période', 'periodeFin' => 'Fin de période'] as $k => $lib) {
            $v = trim((string)($d[$k] ?? ''));
            if ($v === '') { $d[$k] = null; continue; }
            if (!self::dateValide(substr($v, 0, 10))) return [null, "$lib : date invalide."];
            $d[$k] = substr($v, 0, 10);
        }
        if ($d['periodeDebut'] !== null && $d['periodeFin'] !== null && $d['periodeDebut'] > $d['periodeFin']) {
            return [null, 'La période commence après sa fin.'];
        }
        return [$d, null];
    }

    /** Nombre en français, pour les messages. */
    private static function fr(float $n): string
    {
        return rtrim(rtrim(number_format($n, 3, ',', "\u{202F}"), '0'), ',');
    }

    /**
     * Relit tous les relevés : par compteur, les problèmes trouvés, du plus grave au moins grave.
     *   « erreur »     : index en baisse, consommation négative ou sans rapport avec les index,
     *                    index qui recule, doublon, date ou période impossible ;
     *   « a_verifier » : index de début qui ne prolonge pas le relevé précédent (relevé
     *                    manquant, compteur remplacé…), date à venir, deux relevés le même jour.
     */
    public static function verifier(Database $db, ?string $type = null): array
    {
        $compteurs = $db->fetchAll('SELECT Id, Nom, Type, Unite, Site FROM CompteurEnergie' . ($type ? ' WHERE Type = :t' : '') . ' ORDER BY Type, Nom',
                                   $type ? ['t' => $type] : []);
        $aujourdhui = date('Y-m-d');
        $out = []; $nbErr = 0; $nbVerif = 0; $nbReleves = 0;
        foreach ($compteurs as $c) {
            $rows = $db->fetchAll('SELECT Id, Date, Type, IndexDebut, IndexFin, Consommation, PeriodeDebut, PeriodeFin, NumeroFacture, DetailSousCompteurs
                                   FROM ReleverEnergie WHERE CompteurId = :c ORDER BY Date, Id', ['c' => (int)$c['Id']]);
            $nbReleves += count($rows);
            $pb = [];
            $note = function (array $r, string $g, string $code, string $m) use (&$pb) {
                $pb[] = ['releve_id' => (int)$r['Id'], 'date' => substr((string)$r['Date'], 0, 10), 'type' => (string)($r['Type'] ?: 'Releve'),
                         'gravite' => $g, 'code' => $code, 'message' => $m];
            };
            $nb = fn($v) => ($v === null || $v === '') ? null : (float)$v;
            foreach ($rows as $i => $r) {
                $date = substr((string)$r['Date'], 0, 10);
                $t = (string)($r['Type'] ?: 'Releve');
                $deb = $nb($r['IndexDebut']); $fin = $nb($r['IndexFin']); $conso = $nb($r['Consommation']);
                if (!self::dateValide($date)) { $note($r, 'erreur', 'date', 'Date invalide (« ' . mb_substr((string)$r['Date'], 0, 20) . ' »).'); continue; }
                if ($date > $aujourdhui) $note($r, 'a_verifier', 'futur', 'Date à venir.');
                $pd = substr((string)($r['PeriodeDebut'] ?? ''), 0, 10); $pf = substr((string)($r['PeriodeFin'] ?? ''), 0, 10);
                if ($pd !== '' && $pf !== '' && $pd > $pf) $note($r, 'erreur', 'periode', 'La période commence après sa fin (' . $pd . ' → ' . $pf . ').');
                if ($deb !== null && $fin !== null && $fin < $deb) {
                    $note($r, 'erreur', 'baisse', 'Index (' . self::fr($fin) . ') inférieur à l\'index précédent (' . self::fr($deb) . ').');
                }
                if ($conso !== null && $conso < 0) $note($r, 'erreur', 'conso_negative', 'Consommation négative (' . self::fr($conso) . ').');
                if ($t === 'Releve' && $deb !== null && $fin !== null && $conso !== null && $fin >= $deb
                    && trim((string)($r['DetailSousCompteurs'] ?? '')) === '' && abs($conso - ($fin - $deb)) > max(0.01, 0.001 * abs($fin - $deb))) {
                    $note($r, 'erreur', 'conso', 'Consommation (' . self::fr($conso) . ') différente de index − index précédent (' . self::fr($fin - $deb) . ').');
                }
                // Relevé précédent : le plus récent STRICTEMENT avant, du même type de préférence.
                $prec = null; $precAutre = null;
                for ($j = $i - 1; $j >= 0; $j--) {
                    $p = $rows[$j];
                    if (substr((string)$p['Date'], 0, 10) >= $date || $nb($p['IndexFin']) === null) continue;
                    if ((string)($p['Type'] ?: 'Releve') === $t) { $prec = $p; break; }
                    $precAutre ??= $p;
                }
                $prec ??= $precAutre;
                if ($prec !== null) {
                    $pfin = (float)$prec['IndexFin'];
                    $pdate = substr((string)$prec['Date'], 0, 10);
                    $tol = max(0.001, 1e-7 * abs($pfin));
                    if ($fin !== null && $fin < $pfin - $tol) {
                        $note($r, 'erreur', 'recul', 'L\'index (' . self::fr($fin) . ') recule par rapport au relevé du ' . self::dateFr($pdate) . ' (' . self::fr($pfin) . ').');
                    } elseif ($deb !== null && abs($deb - $pfin) > $tol) {
                        $note($r, 'a_verifier', 'rupture', 'Index précédent saisi : ' . self::fr($deb) . ' ; le relevé du ' . self::dateFr($pdate) . ' finissait à '
                                    . self::fr($pfin) . ' (écart ' . self::fr($deb - $pfin) . ') — relevé manquant, compteur remplacé ou erreur de saisie.');
                    }
                }
                // Doublons et relevés du même jour (signalés une fois, sur le second).
                for ($j = $i - 1; $j >= 0 && substr((string)$rows[$j]['Date'], 0, 10) === $date; $j--) {
                    $p = $rows[$j];
                    if ((string)($p['Type'] ?: 'Releve') !== $t) continue;
                    $memeFacture = $t === 'Facture' && trim((string)$r['NumeroFacture']) !== '' && trim((string)$r['NumeroFacture']) === trim((string)$p['NumeroFacture']);
                    if ($memeFacture || ($fin !== null && $nb($p['IndexFin']) !== null && abs($fin - (float)$p['IndexFin']) < 1e-9)) {
                        $note($r, 'erreur', 'doublon', 'Doublon du relevé n° ' . (int)$p['Id'] . ' (même jour, ' . ($memeFacture ? 'même n° de facture' : 'même index') . ').');
                    } else {
                        $note($r, 'a_verifier', 'meme_jour', 'Deux saisies le même jour (avec le relevé n° ' . (int)$p['Id'] . ') : l\'ordre entre elles est celui de la saisie.');
                    }
                    break;
                }
            }
            if (!$pb) continue;
            usort($pb, fn($a, $b) => [$a['gravite'] === 'erreur' ? 0 : 1, $a['date']] <=> [$b['gravite'] === 'erreur' ? 0 : 1, $b['date']]);
            foreach ($pb as $p) $p['gravite'] === 'erreur' ? $nbErr++ : $nbVerif++;
            $out[] = ['id' => (int)$c['Id'], 'nom' => (string)$c['Nom'], 'type' => (string)$c['Type'], 'unite' => (string)($c['Unite'] ?? ''),
                      'site' => (string)($c['Site'] ?? ''), 'problemes' => $pb];
        }
        return ['compteurs' => $out, 'erreurs' => $nbErr, 'a_verifier' => $nbVerif, 'releves' => $nbReleves, 'nb_compteurs' => count($compteurs)];
    }

    private static function dateFr(string $d): string
    {
        $t = strtotime($d);
        return $t ? date('d/m/Y', $t) : $d;
    }
}

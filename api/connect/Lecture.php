<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka Connect — lectures de synthèse : ce que l'assistant (et tout autre
 * lecteur) peut demander à la supervision, en LECTURE SEULE.
 *
 *   resume   : connexions (en service, en défaut), points, alarmes actives,
 *              dernier passage de la collecte ;
 *   conso    : consommation des compteurs sur une période quelconque, par
 *              compteur, par bâtiment et par type d'énergie ;
 *   alarmes  : alarmes d'une période (ou actives), comptées par gravité et
 *              par bâtiment, avec les plus récentes ;
 *   valeurs  : dernière valeur des points dont le nom correspond.
 *
 * UNE SEULE PORTE, DEUX CHEMINS. Quand la supervision vit sur un serveur
 * séparé (api/SupervisionLien.php), le serveur principal n'a pas ces données :
 * il envoie la MÊME demande (quoi + paramètres) par le lien chiffré, et le
 * serveur de supervision l'exécute ici, sur sa propre base. La réponse est
 * identique dans les deux cas : l'assistant n'a pas à savoir où sont les
 * mesures.
 *
 * Paramètres bornés et vérifiés ici (ils peuvent venir de l'autre serveur) :
 * dates « AAAA-MM-JJ », listes et textes tronqués, aucun SQL composé avec une
 * valeur reçue.
 */

declare(strict_types=1);

final class ConnectLecture
{
    public const QUOI = ['resume', 'conso', 'alarmes', 'valeurs'];
    private const MAX_LISTE = 30;

    public static function executer(Database $db, string $quoi, array $args): array
    {
        if (!in_array($quoi, self::QUOI, true)) return ['erreur' => 'Lecture inconnue : ' . $quoi];
        $depot = new ConnectDepot($db);
        return match ($quoi) {
            'resume'  => self::resume($db, $depot),
            'conso'   => self::conso($depot, $args),
            'alarmes' => self::alarmes($depot, $args),
            'valeurs' => self::valeurs($depot, $args),
        };
    }

    // ── Outils ─────────────────────────────────────────────────────────────

    /** Minuscules sans accents ni ponctuation : « Hôtel de Ville » → « hotel de ville ». */
    public static function norm(string $s): string
    {
        // « CO₂ », « m³ » : indices et exposants deviennent des chiffres, pour que
        // « co2 » tapé au clavier trouve le point « CO₂ salle du conseil ».
        $s = strtr($s, ['₀' => '0', '₁' => '1', '₂' => '2', '₃' => '3', '₄' => '4', '₅' => '5', '₆' => '6', '₇' => '7', '₈' => '8', '₉' => '9',
                        '⁰' => '0', '¹' => '1', '²' => '2', '³' => '3', '⁴' => '4', '⁵' => '5', '⁶' => '6', '⁷' => '7', '⁸' => '8', '⁹' => '9']);
        $s = mb_strtolower($s, 'UTF-8');
        $t = class_exists('Normalizer') ? \Normalizer::normalize($s, \Normalizer::FORM_D) : $s;
        $t = preg_replace('/\p{Mn}+/u', '', (string)$t) ?? $s;
        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $t) ?? $t);
    }

    /** Le bâtiment demandé correspond-il à ce nom ? (« hdv » ⊂ « hotel de ville » : non ; « ville » ⊂ : oui) */
    private static function batimentCorrespond(?string $voulu, ?string $nom): bool
    {
        if ($voulu === null || $voulu === '') return true;
        $v = self::norm($voulu); $n = self::norm((string)$nom);
        if ($v === '' || $n === '') return false;
        if (str_contains($n, $v) || str_contains($v, $n)) return true;
        // Tous les mots significatifs du nom demandé se retrouvent dans le nom du bâtiment.
        $mots = array_filter(explode(' ', $v), fn($w) => mb_strlen($w) > 2 && !in_array($w, ['les', 'des', 'batiment', 'bat'], true));
        return $mots && !array_filter($mots, fn($w) => !str_contains(' ' . $n . ' ', ' ' . $w));
    }

    /** « AAAA-MM-JJ » valide, ou null. */
    private static function date(?string $d): ?string
    {
        if (!is_string($d) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) return null;
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $d : null;
    }

    /**
     * Unité commune pour additionner : l'énergie en kWh, les volumes en m³.
     * [unité de somme, facteur] — ou [unité d'origine, 1] si elle n'a pas d'équivalent connu.
     */
    public static function uniteSomme(string $unite): array
    {
        $u = str_replace(['³', ' '], ['3', ''], mb_strtolower(trim($unite)));
        return match ($u) {
            'wh' => ['kWh', 0.001], 'kwh' => ['kWh', 1.0], 'mwh' => ['kWh', 1000.0], 'gwh' => ['kWh', 1000000.0],
            'l', 'litre', 'litres' => ['m³', 0.001], 'hl' => ['m³', 0.1], 'm3' => ['m³', 1.0],
            default => [trim($unite) !== '' ? trim($unite) : '', 1.0],
        };
    }

    // ── Résumé ─────────────────────────────────────────────────────────────

    private static function resume(Database $db, ConnectDepot $depot): array
    {
        $pas = $depot->passerelles();
        $points = $depot->points(null, true);
        $defaut = array_values(array_filter($pas, fn($p) => (int)($p['Actif'] ?? 1) && in_array((string)($p['Etat'] ?? ''), ['erreur', 'defaut', 'injoignable', 'hors_ligne'], true)));
        $muets = array_values(array_filter($points, fn($p) => ($p['DerniereQualite'] ?? '') === 'mauvaise'));
        $etat = class_exists('ConnectCollecte') ? ConnectCollecte::lireEtat() : [];
        return [
            'connexions' => count($pas),
            'connexions_actives' => count(array_filter($pas, fn($p) => (int)($p['Actif'] ?? 1))),
            'en_defaut' => array_map(fn($p) => ['nom' => (string)$p['Nom'], 'batiment' => $p['BatimentNom'] ?? null,
                                               'depuis' => $p['DernierContact'] ?? null, 'erreur' => mb_substr((string)($p['DerniereErreur'] ?? ''), 0, 160)],
                                     array_slice($defaut, 0, self::MAX_LISTE)),
            'nb_en_defaut' => count($defaut),
            'points' => count($points),
            'points_en_defaut' => array_map(fn($p) => ['nom' => (string)$p['Nom'], 'connexion' => (string)$p['PasserelleNom'],
                                                       'batiment' => $p['BatimentNom'] ?? null], array_slice($muets, 0, self::MAX_LISTE)),
            'nb_points_en_defaut' => count($muets),
            'alarmes_actives' => $depot->compterAlarmesActives(),
            'dernier_passage' => $etat['_passage'] ?? null,
            'batiments' => array_values(array_unique(array_filter(array_map(fn($p) => $p['BatimentNom'] ?? null, $pas)))),
        ];
    }

    // ── Consommations ──────────────────────────────────────────────────────

    /**
     * args : debut, fin (AAAA-MM-JJ, fin incluse), batiment?, type? (Electricite|Gaz|Eau|Chauffage|…), mots?[]
     */
    private static function conso(ConnectDepot $depot, array $args): array
    {
        $debut = self::date($args['debut'] ?? null) ?? date('Y-m-01');
        $fin = self::date($args['fin'] ?? null) ?? date('Y-m-d');
        if (strcmp($fin, $debut) < 0) [$debut, $fin] = [$fin, $debut];
        $bat = isset($args['batiment']) ? mb_substr((string)$args['batiment'], 0, 120) : null;
        $type = isset($args['type']) && in_array($args['type'], ConnectConsommations::TYPES, true) ? (string)$args['type'] : null;
        $mots = array_slice(array_filter(array_map(fn($w) => self::norm((string)$w), (array)($args['mots'] ?? []))), 0, 5);

        $cons = new ConnectConsommations($depot);
        $points = array_values(array_filter($depot->points(), fn($p) => ($p['Nature'] ?? '') === 'compteur' && (int)($p['Actif'] ?? 1)));
        $types = $cons->typesPoints($points);
        $finExcl = date('Y-m-d H:i:s', strtotime($fin . ' +1 day'));
        $compteurs = []; $totaux = []; $parType = [];
        $batimentsConnus = [];
        foreach ($points as $p) {
            $batimentsConnus[(string)($p['BatimentNom'] ?? '')] = true;
            if (!self::batimentCorrespond($bat, $p['BatimentNom'] ?? null)) continue;
            $t = $types[(int)$p['Id']] ?? 'Autre';
            if ($type && $t !== $type) continue;
            if ($mots) {
                $txt = self::norm(($p['Nom'] ?? '') . ' ' . ($p['Code'] ?? '') . ' ' . ($p['PasserelleNom'] ?? ''));
                if (array_filter($mots, fn($w) => !str_contains($txt, $w))) continue;
            }
            $c = $cons->consoPeriode($p, $debut . ' 00:00:00', $finExcl);
            [$uS, $f] = self::uniteSomme((string)($p['Unite'] ?? ''));
            $ligne = ['id' => (int)$p['Id'], 'nom' => (string)$p['Nom'], 'batiment' => $p['BatimentNom'] ?? null, 'type' => $t,
                      'unite' => (string)($p['Unite'] ?? ''), 'conso' => $c['conso'], 'partiel' => $c['partiel'], 'anomalie' => $c['anomalie'],
                      'debut_date' => $c['debut_date'], 'fin_date' => $c['fin_date']];
            $compteurs[] = $ligne;
            if ($c['conso'] === null) continue;
            $k = ($p['BatimentNom'] ?? '—') . '|' . $t . '|' . $uS;
            $totaux[$k] ??= ['batiment' => $p['BatimentNom'] ?? null, 'type' => $t, 'unite' => $uS, 'total' => 0.0, 'compteurs' => 0, 'partiel' => false];
            $totaux[$k]['total'] += $c['conso'] * $f;
            $totaux[$k]['compteurs']++;
            $totaux[$k]['partiel'] = $totaux[$k]['partiel'] || $c['partiel'];
            $kt = $t . '|' . $uS;
            $parType[$kt] ??= ['type' => $t, 'unite' => $uS, 'total' => 0.0, 'compteurs' => 0, 'partiel' => false];
            $parType[$kt]['total'] += $c['conso'] * $f;
            $parType[$kt]['compteurs']++;
            $parType[$kt]['partiel'] = $parType[$kt]['partiel'] || $c['partiel'];
        }
        $arrondi = function (array $l) { foreach ($l as &$x) $x['total'] = round($x['total'], 3); return array_values($l); };
        usort($compteurs, fn($a, $b) => [$a['batiment'], $a['nom'], $a['id']] <=> [$b['batiment'], $b['nom'], $b['id']]);
        return [
            'debut' => $debut, 'fin' => $fin, 'batiment' => $bat, 'type' => $type,
            'compteurs' => array_slice($compteurs, 0, 60), 'nb_compteurs' => count($compteurs),
            'totaux' => $arrondi($totaux), 'par_type' => $arrondi($parType),
            // Bâtiment demandé inconnu de la supervision : l'assistant le dit au lieu d'annoncer « 0 ».
            'batiments_connus' => array_values(array_filter(array_keys($batimentsConnus))),
        ];
    }

    // ── Alarmes ────────────────────────────────────────────────────────────

    /** args : debut?, fin? (AAAA-MM-JJ), etat? (active|toutes), batiment?, gravite? */
    private static function alarmes(ConnectDepot $depot, array $args): array
    {
        $debut = self::date($args['debut'] ?? null);
        $fin = self::date($args['fin'] ?? null);
        $etat = ($args['etat'] ?? '') === 'active' ? 'active' : 'toutes';
        $bat = isset($args['batiment']) ? mb_substr((string)$args['batiment'], 0, 120) : null;
        $grav = in_array($args['gravite'] ?? null, ['info', 'mineure', 'majeure', 'critique'], true) ? $args['gravite'] : null;
        $toutes = $depot->alarmes($etat === 'active' ? 'active' : 'toutes', 2000);
        $l = array_values(array_filter($toutes, function ($a) use ($debut, $fin, $bat, $grav) {
            $d = substr((string)$a['Debut'], 0, 10);
            if ($debut && strcmp($d, $debut) < 0 && ($a['Etat'] !== 'active')) return false;   // active : en cours sur la période
            if ($fin && strcmp($d, $fin) > 0) return false;
            if ($grav && $a['Gravite'] !== $grav) return false;
            return self::batimentCorrespond($bat, $a['BatimentNom'] ?? null);
        }));
        $parGrav = []; $parBat = [];
        foreach ($l as $a) {
            $g = (string)($a['Gravite'] ?: 'info');
            $parGrav[$g] = ($parGrav[$g] ?? 0) + 1;
            $b = (string)($a['BatimentNom'] ?? '');
            $parBat[$b] = ($parBat[$b] ?? 0) + 1;
        }
        arsort($parBat);
        return [
            'debut' => $debut, 'fin' => $fin, 'etat' => $etat, 'batiment' => $bat, 'gravite' => $grav,
            'total' => count($l),
            'actives' => count(array_filter($l, fn($a) => $a['Etat'] === 'active')),
            'acquittees' => count(array_filter($l, fn($a) => (int)$a['Acquittee'])),
            'avec_demande' => count(array_filter($l, fn($a) => !empty($a['DemandeId']))),
            'par_gravite' => $parGrav,
            'par_batiment' => $parBat,
            'liste' => array_map(fn($a) => ['message' => mb_substr((string)$a['Message'], 0, 160), 'gravite' => $a['Gravite'], 'etat' => $a['Etat'],
                                            'debut' => $a['Debut'], 'fin' => $a['Fin'], 'batiment' => $a['BatimentNom'] ?? null,
                                            'point' => $a['PointNom'] ?? null, 'connexion' => $a['PasserelleNom'] ?? null,
                                            'acquittee' => (bool)(int)$a['Acquittee'], 'demande' => $a['DemandeId'] ? (int)$a['DemandeId'] : null],
                                  array_slice($l, 0, 10)),
        ];
    }

    // ── Valeurs ────────────────────────────────────────────────────────────

    /** args : mots[] (tous présents dans le nom du point, de sa connexion ou de son bâtiment), batiment?, nature? */
    private static function valeurs(ConnectDepot $depot, array $args): array
    {
        $mots = array_slice(array_filter(array_map(fn($w) => self::norm((string)$w), (array)($args['mots'] ?? []))), 0, 6);
        $bat = isset($args['batiment']) ? mb_substr((string)$args['batiment'], 0, 120) : null;
        $nature = in_array($args['nature'] ?? null, ['compteur', 'mesure', 'etat', 'consigne'], true) ? $args['nature'] : null;
        $out = [];
        foreach ($depot->points(null, true) as $p) {
            if ($nature && ($p['Nature'] ?? '') !== $nature) continue;
            if (!self::batimentCorrespond($bat, $p['BatimentNom'] ?? null)) continue;
            $txt = ' ' . self::norm(($p['Nom'] ?? '') . ' ' . ($p['Code'] ?? '') . ' ' . ($p['PasserelleNom'] ?? '') . ' ' . ($p['BatimentNom'] ?? '')) . ' ';
            $score = 0;
            foreach ($mots as $w) {
                if (str_contains($txt, ' ' . $w . ' ')) $score += 3;
                elseif (str_contains($txt, ' ' . $w) || (mb_strlen($w) > 4 && str_contains($txt, mb_substr($w, 0, -1)))) $score += 2;
                else { $score = -1; break; }
            }
            if ($score < 0) continue;
            $out[] = ['score' => $score, 'id' => (int)$p['Id'], 'nom' => (string)$p['Nom'], 'nature' => $p['Nature'] ?? null,
                      'valeur' => $p['DerniereValeur'] !== null ? (float)$p['DerniereValeur'] : null, 'texte' => $p['DerniereValeurTexte'] ?? null,
                      'unite' => (string)($p['Unite'] ?? ''), 'lecture' => $p['DerniereLecture'] ?? null, 'qualite' => $p['DerniereQualite'] ?? null,
                      'decimales' => (int)($p['Decimales'] ?? 2), 'connexion' => (string)$p['PasserelleNom'], 'batiment' => $p['BatimentNom'] ?? null,
                      'alarmes' => (int)($p['NbAlarmes'] ?? 0)];
        }
        usort($out, fn($a, $b) => [$b['score'], $a['batiment'], $a['nom'], $a['id']] <=> [$a['score'], $b['batiment'], $b['nom'], $b['id']]);
        return ['total' => count($out), 'points' => array_slice($out, 0, self::MAX_LISTE)];
    }
}

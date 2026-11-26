<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka Connect — ce que le PC releveur a trouvé sur un appareil hors réseau.
 *
 * Le serveur ne joint pas ces appareils. Le PC releveur, une fois sur leur
 * hotspot (ou branché dessus), regarde ce qu'ils exposent et envoie un
 * rapport brut (op « reconnaissance ») : ports ouverts, réponses JSON des
 * adresses que leurs pages web interrogent, registres Modbus non nuls, et,
 * pour un automate BACnet/IP, ce qu'il dit de lui et de ses points.
 * Ici, on en tire :
 *   - le protocole et les réglages à proposer (API REST : adresse, chemin ;
 *     Modbus : unit id) ;
 *   - les points à proposer, chacun avec son adresse, un nom lisible, une
 *     unité et une nature devinées — et, pour les listes d'éléments qui se
 *     somment (les trois phases d'un départ), le total en un point (« * »).
 *
 * Rien n'est appliqué sans l'accord d'un gestionnaire (écran « Détection »).
 */

declare(strict_types=1);

final class ConnectReconnaissance
{
    /** Taille maximale d'un rapport (le PC tronque déjà chaque réponse). */
    public const MAX_OCTETS = 1500000;
    public const MAX_POINTS = 400;

    /** Clés de réglage ou d'état, pas des mesures. */
    private const IGNORER = '/^(enable|enabled|actif|active|mode|phase|id|slot|startup|powernoload|connectors|setupconnectors|partition|hw|sw\d*|swrelease|serial.*|language|lang)$/i';
    /** Grandeurs qui s'additionnent d'un élément à l'autre (énergie, puissance, volume). */
    private const SOMMABLE = '/(energ|index|puiss|power|conso|kwh|wh$|volume|debit|flow)/i';
    private const NON_SOMMABLE = '/(factor|^pf$|cos|volt|tension|freq|courant|current|thd|temp|humid|pression|pressure|percent|taux|ratio)/i';

    private const LIBELLES = [
        'indexenergyactimport' => 'Énergie active', 'indexenergyactexport' => 'Énergie active exportée',
        'indexenergyreactimportpositive' => 'Énergie réactive +', 'indexenergyreactimportnegative' => 'Énergie réactive −',
        'puissanceactive' => 'Puissance active', 'puissancereactive' => 'Puissance réactive', 'puissanceapparente' => 'Puissance apparente',
        'voltage' => 'Tension', 'tension' => 'Tension', 'current' => 'Intensité', 'courant' => 'Intensité',
        'freq' => 'Fréquence', 'frequency' => 'Fréquence', 'pf' => 'Facteur de puissance', 'thd' => 'Taux d\'harmoniques',
        'operatingtime' => 'Durée de fonctionnement', 'temperature' => 'Température', 'temp' => 'Température',
    ];

    /** Valide et réduit un rapport reçu du PC ; rend [rapport, erreur]. */
    public static function normaliser($r): array
    {
        if (!is_array($r)) return [null, 'Rapport illisible.'];
        $json = json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || strlen($json) > self::MAX_OCTETS) return [null, 'Rapport trop volumineux.'];
        $txt = fn($v, int $n) => mb_substr((string)$v, 0, $n);
        $out = ['quand' => date('Y-m-d H:i:s'), 'version' => $txt($r['version'] ?? '', 20), 'adresses' => []];
        foreach (array_slice((array)($r['adresses'] ?? []), 0, 4) as $a) {
            if (!is_array($a)) continue;
            $ip = (string)($a['ip'] ?? '');
            if (!filter_var($ip, FILTER_VALIDATE_IP) || !ConnectDepot::adresseHotspot($ip)) continue;   // jamais d'adresse publique
            $docs = [];
            foreach (array_slice((array)($a['documents'] ?? []), 0, 12) as $d) {
                if (!is_array($d)) continue;
                $url = (string)($d['url'] ?? '');
                if (!preg_match('~^https?://[^/\s]+(/[^\s]*)?$~i', $url)) continue;
                $docs[] = ['url' => $txt($url, 300), 'code' => (int)($d['code'] ?? 0), 'type' => $txt($d['type'] ?? '', 100),
                           'corps' => $txt($d['corps'] ?? '', 400000)];
            }
            $mb = is_array($a['modbus'] ?? null) ? $a['modbus'] : null;
            if ($mb) {
                $regs = [];
                foreach ((array)($mb['registres'] ?? []) as $k => $v) {
                    if (count($regs) >= 4000) break;
                    if (is_numeric($k) && is_numeric($v)) $regs[(int)$k] = (int)$v & 0xFFFF;
                }
                $mb = ['unite' => (int)($mb['unite'] ?? 1), 'table' => ($mb['table'] ?? 'holding') === 'input' ? 'input' : 'holding', 'registres' => $regs];
            }
            $out['adresses'][] = ['ip' => $ip, 'ports' => array_values(array_filter(array_map('intval', (array)($a['ports'] ?? [])), fn($p) => $p > 0 && $p < 65536)),
                                  'documents' => $docs, 'modbus' => $mb, 'bacnet' => self::normaliserBacnet($a['bacnet'] ?? null)];
        }
        return [$out, null];
    }

    /** Automate BACnet décrit par le PC : n°, identité, points (adresses BACnet seulement, rien d'autre). */
    private static function normaliserBacnet($b): ?array
    {
        if (!is_array($b) || !isset($b['instance']) || !is_numeric($b['instance'])) return null;
        $instance = (int)$b['instance'];
        if ($instance < 0 || $instance > 4194303) return null;
        $txt = fn($v, int $n) => mb_substr(trim((string)(is_scalar($v) ? $v : '')), 0, $n);
        $objets = [];
        foreach (array_slice((array)($b['objets'] ?? []), 0, self::MAX_POINTS) as $o) {
            if (!is_array($o) || !is_array($o['adresse'] ?? null)) continue;
            $type = (string)($o['adresse']['objet'] ?? '');
            $inst = $o['adresse']['instance'] ?? null;
            if (ConnectPiloteBacnet::typeObjet($type) === null || !is_numeric($inst) || (int)$inst < 0 || (int)$inst > 4194303) continue;
            $v = $o['valeur'] ?? null;
            $objets[] = ['nom' => $txt($o['nom'] ?? '', 150) ?: "$type $inst", 'code' => "$type," . (int)$inst,
                         'adresse' => ['objet' => $type, 'instance' => (int)$inst, 'propriete' => 'present-value'],
                         'unite' => $txt($o['unite'] ?? '', 20), 'nature' => in_array($o['nature'] ?? '', ['mesure', 'consigne', 'etat', 'alarme', 'compteur'], true) ? $o['nature'] : 'mesure',
                         'valeur' => is_int($v) || is_float($v) ? $v : (is_scalar($v) ? $txt($v, 100) : null)];
        }
        $port = (int)($b['port'] ?? 47808);
        return ['instance' => $instance, 'port' => $port > 0 && $port < 65536 ? $port : 47808, 'nom' => $txt($b['nom'] ?? '', 150),
                'fabricant' => $txt($b['fabricant'] ?? '', 100), 'modele' => $txt($b['modele'] ?? '', 100), 'emplacement' => $txt($b['emplacement'] ?? '', 150),
                'objets' => $objets];
    }

    /**
     * Ce que l'écran propose : [etat, suggestion, points, resume].
     * suggestion : null, ou ['protocole', 'hote', 'port', 'parametres', 'libelle'].
     */
    public static function analyser(?array $r, array $existants = [], ?array $pa = null): array
    {
        if (!$r) return ['suggestion' => null, 'points' => [], 'resume' => []];
        $meilleur = null;
        $resume = [];
        foreach ($r['adresses'] as $a) {
            $resume[] = $a['ip'] . ' : ' . ($a['ports'] ? 'ports ' . implode(', ', $a['ports']) : 'aucun port ouvert');
            foreach ($a['documents'] as $d) {
                $doc = json_decode($d['corps'], true);
                if (!is_array($doc) || $d['code'] < 200 || $d['code'] >= 300) continue;
                $pts = self::pointsJson($doc);
                $n = count(array_filter($pts, fn($p) => empty($p['total'])));
                if ($n === 0) continue;
                if (!$meilleur || $n > $meilleur['n']) $meilleur = ['n' => $n, 'doc' => $d, 'points' => $pts, 'ip' => $a['ip']];
            }
        }
        $suggestion = null; $points = [];
        // Un automate BACnet a répondu : il a dit lui-même qui il est et quels sont ses points.
        // C'est la description la plus sûre (noms et unités de l'automate) : elle passe en premier.
        $bacnet = null;
        foreach ($r['adresses'] as $a) if (!empty($a['bacnet'])) { $bacnet = $a['bacnet'] + ['ip' => $a['ip']]; break; }
        if ($bacnet) {
            $qui = trim($bacnet['nom'] . ($bacnet['fabricant'] !== '' || $bacnet['modele'] !== '' ? ' — ' . trim($bacnet['fabricant'] . ' ' . $bacnet['modele']) : ''));
            $resume[] = "BACnet : automate n° {$bacnet['instance']}" . ($qui !== '' ? " ($qui)" : '') . ', ' . count($bacnet['objets']) . ' point(s)';
            $suggestion = ['protocole' => 'bacnet', 'hote' => $bacnet['ip'], 'port' => $bacnet['port'], 'identifiant' => (string)$bacnet['instance'],
                           'parametres' => ['priorite_ecriture' => 8, 'lecture_groupee' => true, 'delai_secondes' => 3],
                           'libelle' => "BACnet/IP — {$bacnet['ip']}, automate n° {$bacnet['instance']}" . ($bacnet['nom'] !== '' ? " « {$bacnet['nom']} »" : '')];
            foreach ($bacnet['objets'] as $o) {
                // Rien n'est coché d'office, sauf les compteurs (index d'énergie, heures de marche) : ce qui sert au module Énergie.
                $points[] = $o + ['coche' => $o['nature'] === 'compteur'];
            }
            $meilleur = null;
        }
        if ($meilleur) {
            $u = parse_url($meilleur['doc']['url']);
            $base = strtolower($u['scheme'] ?? 'http') . '://' . ($u['host'] ?? $meilleur['ip']) . (isset($u['port']) ? ':' . $u['port'] : '');
            $chemin = ($u['path'] ?? '/') . (isset($u['query']) ? '?' . $u['query'] : '');
            $suggestion = ['protocole' => 'rest', 'hote' => '', 'port' => null,
                           'parametres' => ['url_base' => $base, 'mode' => 'par_point', 'url_point' => $chemin, 'delai_secondes' => 10, 'auth' => 'aucune'],
                           'libelle' => "API REST (JSON) — $base$chemin"];
            foreach ($meilleur['points'] as $p) {
                $points[] = ['nom' => $p['nom'], 'code' => mb_substr($p['chemin'], 0, 100), 'nature' => $p['nature'], 'unite' => $p['unite'],
                             'adresse' => ['id' => mb_substr($p['chemin'], 0, 100), 'chemin' => $p['chemin']],
                             'valeur' => $p['valeur'], 'coche' => !empty($p['total']) || !empty($p['seul'])];
            }
        } elseif (!$bacnet) {
            foreach ($r['adresses'] as $a) {
                if (!in_array(502, $a['ports'], true) || !$a['modbus'] || !$a['modbus']['registres']) continue;
                $suggestion = ['protocole' => 'modbus', 'hote' => $a['ip'], 'port' => 502,
                               'parametres' => ['unite_defaut' => $a['modbus']['unite'], 'base_adresse' => '0', 'ordre_defaut' => 'ABCD', 'delai_secondes' => 6],
                               'libelle' => "Modbus TCP — {$a['ip']}:502, unit id {$a['modbus']['unite']}"];
                $points = self::pointsModbus($a['modbus']);
                break;
            }
        }
        // Déjà suivis : même code ou même adresse.
        foreach ($points as &$p) {
            $p['deja'] = false;
            foreach ($existants as $ex) {
                $ea = is_array($ex['Adresse'] ?? null) ? $ex['Adresse'] : [];
                if ((string)($ex['Code'] ?? '') === $p['code'] || (isset($p['adresse']['chemin']) && (string)($ea['chemin'] ?? '') === $p['adresse']['chemin'])
                    || (isset($p['adresse']['objet']) && (string)($ea['objet'] ?? '') === $p['adresse']['objet'] && (int)($ea['instance'] ?? -1) === (int)$p['adresse']['instance'])
                    || (isset($p['adresse']['registre']) && (int)($ea['registre'] ?? -1) === (int)$p['adresse']['registre'])) { $p['deja'] = true; break; }
            }
        }
        unset($p);
        return ['suggestion' => $suggestion, 'points' => array_slice($points, 0, self::MAX_POINTS), 'resume' => $resume];
    }

    /** Points d'un document JSON : chaque valeur numérique, et les totaux des listes qui se somment. */
    public static function pointsJson(array $doc): array
    {
        $out = [];
        self::parcourir($doc, '', '', '', $out);
        // Un seul élément utile dans un document : coché d'office.
        if (count($out) === 1) $out[0]['seul'] = true;
        return $out;
    }

    private static function nomDans(array $el): string
    {
        foreach (['Name', 'name', 'Nom', 'nom', 'Label', 'label', 'Libelle', 'libelle', 'Description'] as $k) {
            if (isset($el[$k]) && is_string($el[$k]) && trim($el[$k]) !== '') return trim($el[$k]);
        }
        return '';
    }

    private static function inactif($el): bool
    {
        if (!is_array($el)) return false;
        foreach (['Enable', 'enable', 'Enabled', 'enabled', 'Actif', 'actif'] as $k) {
            if (array_key_exists($k, $el) && ($el[$k] === 0 || $el[$k] === false || $el[$k] === '0')) return true;
        }
        return false;
    }

    private static function libelleCle(string $cle): string
    {
        $k = strtolower($cle);
        if (isset(self::LIBELLES[$k])) return self::LIBELLES[$k];
        $t = trim(preg_replace('/(?<=[a-z0-9])(?=[A-Z])|[_\-]+/', ' ', $cle));
        return mb_strtoupper(mb_substr($t, 0, 1)) . mb_substr($t, 1);
    }

    public static function uniteDe(string $cle): string
    {
        $k = strtolower($cle);
        if (preg_match('/energ.*react|react.*energ/', $k)) return 'kVArh';
        if (preg_match('/energ|kwh/', $k)) return 'kWh';
        if (preg_match('/(puiss|power).*react|react/', $k)) return 'VAr';
        if (preg_match('/apparent/', $k)) return 'VA';
        if (preg_match('/puiss|power/', $k)) return 'W';
        if (preg_match('/volt|tension/', $k)) return 'V';
        if (preg_match('/current|courant|intens/', $k)) return 'A';
        if (preg_match('/freq/', $k)) return 'Hz';
        if (preg_match('/temp/', $k)) return '°C';
        if (preg_match('/humid/', $k)) return '%';
        return '';
    }

    private static function natureDe(string $cle): string
    {
        return preg_match('/energ|index|kwh|compteur|counter|totali/i', $cle) ? 'compteur' : 'mesure';
    }

    /**
     * $chemin : chemin du nœud ; $nom : nom de l'élément porteur (« Comptage général ») ;
     * $suffixe : rang dans une liste (« L2 », « 3 »).
     */
    private static function parcourir($noeud, string $chemin, string $nom, string $suffixe, array &$out): void
    {
        if (count($out) >= self::MAX_POINTS || !is_array($noeud) || self::inactif($noeud)) return;
        $nomIci = self::nomDans($noeud) ?: $nom;
        if (array_is_list($noeud)) {
            // Liste d'objets : un rang par élément ; et, pour les clés qui se somment, le total.
            $objets = array_values(array_filter($noeud, fn($e) => is_array($e) && !array_is_list($e) && !self::inactif($e)));
            $parent = strtolower(basename(str_replace('.', '/', $chemin)));
            $phases = count($noeud) === 3 && preg_match('/channel|phase|voie/', $parent);
            // Le nom du premier élément nomme souvent tout le groupe (« Comptage general » sur la phase 1).
            $nomGroupe = $nomIci ?: ($objets ? self::nomDans($objets[0]) : '');
            if (count($objets) >= 2) {
                $cles = [];
                foreach ($objets as $o) foreach ($o as $k => $v) if (is_int($v) || is_float($v)) $cles[$k] = ($cles[$k] ?? 0) + 1;
                foreach ($cles as $k => $n) {
                    $k = (string)$k;
                    if ($n < 2 || preg_match(self::IGNORER, $k) || !preg_match(self::SOMMABLE, $k) || preg_match(self::NON_SOMMABLE, $k)) continue;
                    $somme = 0.0;
                    foreach ($objets as $o) if (is_int($o[$k] ?? null) || is_float($o[$k] ?? null)) $somme += (float)$o[$k];
                    $out[] = ['chemin' => ($chemin === '' ? '' : "$chemin.") . "*.$k", 'nom' => trim(($nomGroupe ? "$nomGroupe — " : '') . self::libelleCle($k) . ' (total)'),
                              'unite' => self::uniteDe($k), 'nature' => self::natureDe($k), 'valeur' => round($somme, 4), 'total' => true];
                }
            }
            foreach ($noeud as $i => $e) {
                if (self::inactif($e)) continue;
                self::parcourir($e, ($chemin === '' ? '' : "$chemin.") . $i, $nomGroupe, $phases ? 'L' . ($i + 1) : (string)($i + 1), $out);
            }
            return;
        }
        foreach ($noeud as $k => $v) {
            $k = (string)$k;
            $c = ($chemin === '' ? '' : "$chemin.") . $k;
            if (is_array($v)) { self::parcourir($v, $c, $nomIci, $suffixe, $out); continue; }
            if (!(is_int($v) || is_float($v)) || preg_match(self::IGNORER, $k)) continue;
            if (count($out) >= self::MAX_POINTS) return;
            $out[] = ['chemin' => $c, 'nom' => trim(($nomIci ? "$nomIci — " : '') . self::libelleCle($k) . ($suffixe !== '' ? " ($suffixe)" : '')),
                      'unite' => self::uniteDe($k), 'nature' => self::natureDe($k), 'valeur' => is_float($v) ? round($v, 4) : $v];
        }
    }

    /** Registres Modbus qui ressemblent à une tension ou une fréquence (le reste se lit dans le rapport). */
    public static function pointsModbus(array $mb): array
    {
        $r = $mb['registres']; $out = [];
        $ajouter = function (string $nom, int $reg, string $type, string $ordre, string $unite, float $coef, float $v) use (&$out, $mb) {
            $out[] = ['nom' => $nom, 'code' => "mb-$reg-$type" . ($ordre !== 'ABCD' ? "-$ordre" : ''), 'nature' => 'mesure', 'unite' => $unite,
                      'adresse' => ['table' => $mb['table'], 'registre' => $reg, 'type' => $type, 'ordre' => $ordre],
                      'valeur' => round($v, 3), 'coche' => false, 'coefficient' => $coef];
        };
        foreach ($r as $i => $v) {
            if ($v >= 2100 && $v <= 2550) $ajouter("Tension ? (registre $i, ×0,1)", $i, 'uint16', 'ABCD', 'V', 0.1, $v / 10);
            elseif ($v >= 4950 && $v <= 5050) $ajouter("Fréquence ? (registre $i, ×0,01)", $i, 'uint16', 'ABCD', 'Hz', 0.01, $v / 100);
            if (isset($r[$i + 1])) {
                foreach (['ABCD' => [$v, $r[$i + 1]], 'CDAB' => [$r[$i + 1], $v]] as $ordre => [$a, $b]) {
                    $f = unpack('G', pack('nn', $a, $b))[1];
                    if (!is_finite($f)) continue;
                    if ($f >= 200 && $f <= 260) $ajouter("Tension ? (registres $i-" . ($i + 1) . ", réel $ordre)", $i, 'float32', $ordre, 'V', 1, $f);
                    elseif ($f >= 49.5 && $f <= 50.5) $ajouter("Fréquence ? (registres $i-" . ($i + 1) . ", réel $ordre)", $i, 'float32', $ordre, 'Hz', 1, $f);
                }
            }
            if (count($out) >= 60) break;
        }
        return $out;
    }
}

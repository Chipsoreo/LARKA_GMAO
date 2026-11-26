<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka Connect — pilote Modbus TCP.
 *
 * Fonctions gérées : 01 (bobines), 02 (entrées TOR), 03 (registres de
 * maintien), 04 (registres d'entrée) ; écriture 05, 06, 16.
 *
 * Les pièges du terrain sont gérés ici plutôt que laissés à l'utilisateur :
 *   - une valeur sur 32 ou 64 bits occupe plusieurs registres, et l'ordre des
 *     mots/octets dépend du fabricant (ABCD, CDAB, BADC, DCBA) ;
 *   - la documentation parle tantôt de « registre 0 », tantôt de « 40001 » :
 *     les deux écritures sont acceptées ;
 *   - plusieurs compteurs derrière une même passerelle : un « unit id » par
 *     point, et un compteur muet (exception 0x0B) n'empêche pas les autres.
 * Les lectures contiguës sont regroupées : une requête pour 20 points voisins.
 */

declare(strict_types=1);

final class ConnectPiloteModbus extends ConnectPiloteBase
{
    private const TYPES = ['uint16' => 1, 'int16' => 1, 'uint32' => 2, 'int32' => 2, 'float32' => 2,
                           'uint64' => 4, 'int64' => 4, 'float64' => 4, 'bool' => 1];
    private const EXCEPTIONS = [
        1 => 'fonction non gérée par l\'équipement',
        2 => 'adresse de registre invalide',
        3 => 'valeur ou quantité refusée',
        4 => 'défaut interne de l\'équipement',
        5 => 'requête acceptée, traitement long',
        6 => 'équipement occupé',
        10 => 'passerelle : chemin indisponible',
        11 => 'passerelle : l\'équipement derrière elle ne répond pas (unit id inexistant ou éteint)',
    ];
    /** Plus grand écart toléré entre deux registres pour les lire d'une seule requête. */
    private const ECART_MAX = 8;
    private const LOT_MAX = 120;

    /** @var resource|null */
    private $flux = null;
    private int $tid = 0;

    public static function description(): array
    {
        return [
            'protocole' => 'modbus',
            'libelle' => 'Modbus TCP',
            'resume' => 'Compteurs d\'énergie, centrales de mesure, automates, passerelles Modbus.',
            'collecte' => 'interrogation',
            'port_defaut' => 502,
            'intervalle_defaut' => 60,
            'hote' => ['libelle' => 'Adresse IP de l\'équipement ou de la passerelle', 'requis' => true],
            'identifiant' => null,
            'champs' => [
                ['cle' => 'unite_defaut', 'libelle' => 'Unit id par défaut', 'type' => 'nombre', 'defaut' => 1,
                 'aide' => 'Numéro d\'esclave (1 à 247). Une passerelle peut en avoir plusieurs derrière elle : chaque point peut préciser le sien.'],
                ['cle' => 'base_adresse', 'libelle' => 'Numérotation des registres', 'type' => 'choix', 'defaut' => '0',
                 'options' => [['0', 'À partir de 0 (registre 0 = premier)'], ['1', 'À partir de 1 (registre 1 = premier)']],
                 'aide' => 'La documentation de l\'équipement l\'indique. Les écritures « 40001 », « 30001 » sont reconnues dans les deux cas.'],
                ['cle' => 'ordre_defaut', 'libelle' => 'Ordre des mots par défaut (32/64 bits)', 'type' => 'choix', 'defaut' => 'ABCD',
                 'options' => [['ABCD', 'ABCD — poids fort en premier'], ['CDAB', 'CDAB — mots inversés'],
                               ['BADC', 'BADC — octets inversés'], ['DCBA', 'DCBA — tout inversé']],
                 'aide' => 'Si un index lu paraît absurde (des centaines de millions), essayez CDAB.'],
                ['cle' => 'delai_secondes', 'libelle' => 'Délai de réponse (s)', 'type' => 'nombre', 'defaut' => 4],
            ],
            'secrets' => [],
            'adresse' => [
                ['cle' => 'unite', 'libelle' => 'Unit id', 'type' => 'nombre', 'aide' => 'Vide = celui de la connexion.'],
                ['cle' => 'table', 'libelle' => 'Table', 'type' => 'choix', 'defaut' => 'holding',
                 'options' => [['holding', 'Registres de maintien (03)'], ['input', 'Registres d\'entrée (04)'],
                               ['coil', 'Bobines (01)'], ['discrete', 'Entrées TOR (02)']]],
                ['cle' => 'registre', 'libelle' => 'Registre', 'type' => 'nombre', 'requis' => true],
                ['cle' => 'type', 'libelle' => 'Type de donnée', 'type' => 'choix', 'defaut' => 'uint16',
                 'options' => [['uint16', 'Entier 16 bits non signé'], ['int16', 'Entier 16 bits signé'],
                               ['uint32', 'Entier 32 bits non signé (2 registres)'], ['int32', 'Entier 32 bits signé (2 registres)'],
                               ['float32', 'Réel 32 bits (2 registres)'], ['uint64', 'Entier 64 bits (4 registres)'],
                               ['int64', 'Entier 64 bits signé (4 registres)'], ['float64', 'Réel 64 bits (4 registres)'],
                               ['bool', 'Booléen (bobine, entrée TOR ou bit d\'un registre)']]],
                ['cle' => 'ordre', 'libelle' => 'Ordre des mots', 'type' => 'choix',
                 'options' => [['', 'Celui de la connexion'], ['ABCD', 'ABCD'], ['CDAB', 'CDAB'], ['BADC', 'BADC'], ['DCBA', 'DCBA']]],
                ['cle' => 'bit', 'libelle' => 'Bit (0 à 15)', 'type' => 'nombre', 'aide' => 'Pour lire un seul bit d\'un registre (type booléen).'],
            ],
            'ecriture' => true,
            'decouverte' => false,
            'aide_decouverte' => 'Modbus ne liste pas ses registres : reportez-les depuis la documentation de l\'équipement. L\'explorateur lit une plage brute pour les trouver.',
        ];
    }

    public function fermer(): void
    {
        if ($this->flux) { @fclose($this->flux); $this->flux = null; }
    }

    // ── Connexion et trames ────────────────────────────────────────────────

    private function connecter(): ?array
    {
        if ($this->flux) return null;
        [$f, $etape] = $this->ouvrirTcp($this->hote(), $this->port(502));
        $this->flux = $f;
        return $f ? null : $etape;
    }

    /**
     * Envoie une PDU et rend la PDU de réponse.
     * @return array{0: ?string, 1: ?string} [pdu, erreur]
     */
    private function requete(int $unite, string $pdu): array
    {
        if (!$this->flux) return [null, 'non connecté'];
        $this->tid = ($this->tid + 1) & 0xFFFF;
        $trame = pack('nnnC', $this->tid, 0, strlen($pdu) + 1, $unite) . $pdu;
        if (@fwrite($this->flux, $trame) !== strlen($trame)) {
            $this->fermer();
            return [null, 'connexion coupée pendant l\'envoi'];
        }
        $echeance = microtime(true) + $this->delaiReponse;
        while (true) {
            $entete = self::lireExact($this->flux, 7, $echeance);
            if ($entete === null) {
                $this->fermer();
                return [null, 'pas de réponse Modbus dans le délai'];
            }
            $e = unpack('ntid/nproto/nlen/Cunite', $entete);
            if ($e['len'] < 2 || $e['len'] > 260) { $this->fermer(); return [null, 'trame Modbus invalide']; }
            $corps = self::lireExact($this->flux, $e['len'] - 1, $echeance);
            if ($corps === null) { $this->fermer(); return [null, 'réponse Modbus incomplète']; }
            if ($e['tid'] !== $this->tid) continue;          // réponse en retard d'une requête précédente
            if ($e['proto'] !== 0) return [null, 'protocole inattendu (ce n\'est pas du Modbus TCP ?)'];
            $fc = ord($corps[0]);
            if ($fc & 0x80) {
                $code = isset($corps[1]) ? ord($corps[1]) : 0;
                return [null, sprintf('exception 0x%02X : %s', $code, self::EXCEPTIONS[$code] ?? 'code inconnu')];
            }
            return [$corps, null];
        }
    }

    // ── Adresses ───────────────────────────────────────────────────────────

    /** Adresse normalisée d'un point : unite, fc, registre (0-based), type, nb, ordre, bit. */
    private function normaliser(array $a): array
    {
        $table = (string)($a['table'] ?? 'holding');
        $fc = ['coil' => 1, 'discrete' => 2, 'holding' => 3, 'input' => 4][$table] ?? 3;
        $reg = (int)($a['registre'] ?? 0);
        $base = (int)$this->param('base_adresse', 0);
        // Notations « Modicon » : 40001 → maintien 0, 30001 → entrée 0, 10001 → TOR 0.
        if ($reg >= 40001 && $reg <= 49999 && $fc === 3)      { $reg -= 40001; $base = 0; }
        elseif ($reg >= 400001 && $reg <= 465536 && $fc === 3) { $reg -= 400001; $base = 0; }
        elseif ($reg >= 30001 && $reg <= 39999 && $fc === 4)  { $reg -= 30001; $base = 0; }
        elseif ($reg >= 10001 && $reg <= 19999 && $fc === 2)  { $reg -= 10001; $base = 0; }
        if ($base === 1 && $reg > 0) $reg -= 1;
        $type = (string)($a['type'] ?? ($fc <= 2 ? 'bool' : 'uint16'));
        if (!isset(self::TYPES[$type])) $type = 'uint16';
        if ($fc <= 2) $type = 'bool';
        $ordre = strtoupper((string)($a['ordre'] ?? ''));
        if (!in_array($ordre, ['ABCD', 'CDAB', 'BADC', 'DCBA'], true)) {
            $ordre = strtoupper((string)$this->param('ordre_defaut', 'ABCD'));
            if (!in_array($ordre, ['ABCD', 'CDAB', 'BADC', 'DCBA'], true)) $ordre = 'ABCD';
        }
        $unite = (isset($a['unite']) && $a['unite'] !== '' && $a['unite'] !== null)
            ? (int)$a['unite'] : (int)$this->param('unite_defaut', 1);
        $bit = (isset($a['bit']) && $a['bit'] !== '' && $a['bit'] !== null) ? max(0, min(15, (int)$a['bit'])) : null;
        return ['unite' => max(0, min(255, $unite)), 'fc' => $fc, 'registre' => max(0, min(65535, $reg)),
                'type' => $type, 'nb' => self::TYPES[$type], 'ordre' => $ordre, 'bit' => $bit];
    }

    // ── Codage des valeurs ─────────────────────────────────────────────────

    /** Registres reçus (dans l'ordre des adresses) → octets en big-endian selon l'ordre déclaré. */
    private static function octets(array $regs, string $ordre): string
    {
        if ($ordre === 'CDAB' || $ordre === 'DCBA') $regs = array_reverse($regs);
        $b = '';
        foreach ($regs as $r) {
            $hi = chr(($r >> 8) & 0xFF); $lo = chr($r & 0xFF);
            $b .= ($ordre === 'BADC' || $ordre === 'DCBA') ? $lo . $hi : $hi . $lo;
        }
        return $b;
    }

    public static function decoder(array $regs, string $type, string $ordre = 'ABCD', ?int $bit = null): ?float
    {
        if ($type === 'bool') {
            $v = (int)($regs[0] ?? 0);
            return (float)($bit === null ? ($v ? 1 : 0) : (($v >> $bit) & 1));
        }
        if ($type === 'uint16') return (float)($regs[0] & 0xFFFF);
        if ($type === 'int16') { $v = $regs[0] & 0xFFFF; return (float)($v >= 0x8000 ? $v - 0x10000 : $v); }
        $b = self::octets($regs, $ordre);
        switch ($type) {
            case 'uint32': return (float)unpack('N', $b)[1];
            case 'int32':  $v = unpack('N', $b)[1]; return (float)($v >= 0x80000000 ? $v - 0x100000000 : $v);
            case 'float32': return self::arrondiFloat32((float)unpack('G', $b)[1]);
            case 'float64': return (float)unpack('E', $b)[1];
            case 'uint64':
            case 'int64':
                $hi = unpack('N', substr($b, 0, 4))[1]; $lo = unpack('N', substr($b, 4, 4))[1];
                if ($type === 'int64' && $hi >= 0x80000000) {
                    return -(float)((0xFFFFFFFF - $hi) * 4294967296.0 + (0xFFFFFFFF - $lo) + 1);
                }
                return (float)($hi * 4294967296.0 + $lo);
        }
        return null;
    }

    /** Valeur → registres à écrire, selon type et ordre. */
    public static function encoder(float $v, string $type, string $ordre = 'ABCD'): array
    {
        switch ($type) {
            case 'uint16': case 'bool': return [((int)round($v)) & 0xFFFF];
            case 'int16': $i = (int)round($v); return [$i < 0 ? $i + 0x10000 : $i];
            case 'uint32': $b = pack('N', ((int)round($v)) & 0xFFFFFFFF); break;
            case 'int32': $i = (int)round($v); $b = pack('N', $i < 0 ? $i + 0x100000000 : $i); break;
            case 'float32': $b = pack('G', $v); break;
            case 'float64': $b = pack('E', $v); break;
            case 'uint64': case 'int64': $b = pack('J', (int)round($v)); break;
            default: return [((int)round($v)) & 0xFFFF];
        }
        $mots = [];
        for ($i = 0; $i < strlen($b); $i += 2) {
            $hi = ord($b[$i]); $lo = ord($b[$i + 1]);
            $mots[] = ($ordre === 'BADC' || $ordre === 'DCBA') ? (($lo << 8) | $hi) : (($hi << 8) | $lo);
        }
        if ($ordre === 'CDAB' || $ordre === 'DCBA') $mots = array_reverse($mots);
        return $mots;
    }

    // ── Lecture ────────────────────────────────────────────────────────────

    /**
     * Lit $nb registres (ou bits) à partir de $debut.
     * @return array{0: ?array, 1: ?string}
     */
    private function lirePlage(int $unite, int $fc, int $debut, int $nb): array
    {
        [$pdu, $err] = $this->requete($unite, pack('Cnn', $fc, $debut, $nb));
        if ($pdu === null) return [null, $err];
        $n = ord($pdu[1] ?? "\0");
        $donnees = substr($pdu, 2, $n);
        if (strlen($donnees) < $n) return [null, 'réponse tronquée'];
        if ($fc <= 2) {
            $bits = [];
            for ($i = 0; $i < $nb; $i++) $bits[] = (ord($donnees[intdiv($i, 8)] ?? "\0") >> ($i % 8)) & 1;
            return [$bits, null];
        }
        if (strlen($donnees) < 2 * $nb) return [null, 'nombre de registres inattendu'];
        return [array_values(unpack('n' . $nb, $donnees)), null];
    }

    public function lire(array $points): array
    {
        $out = [];
        if (!$points) return $out;
        if (($etape = $this->connecter()) !== null) {
            foreach ($points as $p) $out[(int)$p['Id']] = self::enDefaut($etape['message']);
            return $out;
        }
        // Regroupement : (unite, fc) puis plages contiguës.
        $groupes = [];
        foreach ($points as $p) {
            $a = $this->normaliser(is_array($p['Adresse'] ?? null) ? $p['Adresse'] : []);
            $groupes[$a['unite'] . ':' . $a['fc']][] = ['p' => $p, 'a' => $a];
        }
        $unitesMuettes = [];
        foreach ($groupes as $liste) {
            usort($liste, fn($x, $y) => $x['a']['registre'] <=> $y['a']['registre']);
            $lots = [];
            foreach ($liste as $el) {
                $a = $el['a'];
                $n = count($lots);
                if ($n > 0) {
                    $dernier = &$lots[$n - 1];
                    $fin = $a['registre'] + $a['nb'];
                    if ($a['registre'] - $dernier['fin'] <= self::ECART_MAX && $fin - $dernier['debut'] <= self::LOT_MAX) {
                        $dernier['fin'] = max($dernier['fin'], $fin);
                        $dernier['els'][] = $el;
                        unset($dernier);
                        continue;
                    }
                    unset($dernier);
                }
                $lots[] = ['debut' => $a['registre'], 'fin' => $a['registre'] + $a['nb'], 'els' => [$el]];
            }
            foreach ($lots as $lot) {
                $a0 = $lot['els'][0]['a'];
                if (isset($unitesMuettes[$a0['unite']])) {
                    foreach ($lot['els'] as $el) $out[(int)$el['p']['Id']] = self::enDefaut($unitesMuettes[$a0['unite']]);
                    continue;
                }
                if (!$this->flux && ($etape = $this->connecter()) !== null) {
                    foreach ($lot['els'] as $el) $out[(int)$el['p']['Id']] = self::enDefaut($etape['message']);
                    continue;
                }
                [$vals, $err] = $this->lirePlage($a0['unite'], $a0['fc'], $lot['debut'], $lot['fin'] - $lot['debut']);
                if ($vals === null && count($lot['els']) > 1 && $err !== null && str_contains($err, '0x02')) {
                    // Plage refusée (un trou non lisible au milieu) : point par point.
                    foreach ($lot['els'] as $el) {
                        $a = $el['a'];
                        [$v1, $e1] = $this->lirePlage($a['unite'], $a['fc'], $a['registre'], $a['nb']);
                        $out[(int)$el['p']['Id']] = $v1 === null ? self::enDefaut($e1)
                            : self::valeurLue(self::decoder($v1, $a['type'], $a['ordre'], $a['bit']));
                    }
                    continue;
                }
                if ($vals === null) {
                    if ($err !== null && str_contains($err, '0x0B')) $unitesMuettes[$a0['unite']] = $err;
                    foreach ($lot['els'] as $el) $out[(int)$el['p']['Id']] = self::enDefaut((string)$err);
                    continue;
                }
                foreach ($lot['els'] as $el) {
                    $a = $el['a'];
                    $tranche = array_slice($vals, $a['registre'] - $lot['debut'], $a['nb']);
                    $out[(int)$el['p']['Id']] = count($tranche) < $a['nb']
                        ? self::enDefaut('registres manquants dans la réponse')
                        : self::valeurLue(self::decoder($tranche, $a['type'], $a['ordre'], $a['bit']));
                }
            }
        }
        return $out;
    }

    /** Lecture brute d'une plage, pour aider à trouver les bons registres. */
    public function explorer(int $unite, string $table, int $debut, int $nombre): array
    {
        if (($etape = $this->connecter()) !== null) return ['ok' => false, 'message' => $etape['message'], 'demande_it' => $etape['demande_it'] ?? null];
        $fc = ['coil' => 1, 'discrete' => 2, 'holding' => 3, 'input' => 4][$table] ?? 3;
        $base = (int)$this->param('base_adresse', 0);
        $nombre = max(1, min(60, $nombre));
        $origine = max(0, $debut - ($base === 1 ? 1 : 0));
        [$vals, $err] = $this->lirePlage($unite, $fc, $origine, $nombre);
        $absents = 0;
        if ($vals === null && $nombre > 1 && $err !== null && str_contains($err, '0x02')) {
            // Plage refusée en bloc (registres inexistants au milieu) : un par
            // un, pour montrer ceux qui existent — c'est ce qu'on cherche.
            $vals = [];
            for ($i = 0; $i < $nombre; $i++) {
                [$v1] = $this->lirePlage($unite, $fc, $origine + $i, 1);
                $vals[$i] = $v1 === null ? null : $v1[0];
                if ($v1 === null) $absents++;
            }
            if ($absents === $nombre) return ['ok' => false, 'message' => (string)$err . ' (aucun registre lisible dans cette plage)'];
        }
        if ($vals === null) return ['ok' => false, 'message' => (string)$err];
        $lignes = [];
        foreach ($vals as $i => $v) {
            $l = ['registre' => $debut + $i, 'brut' => $v];
            if ($v === null) { $l['absent'] = true; $lignes[] = $l; continue; }
            if ($fc >= 3) {
                $l['int16'] = self::decoder([$v], 'int16');
                if (isset($vals[$i + 1]) && $vals[$i + 1] !== null) {
                    $paire = [$v, $vals[$i + 1]];
                    $l['uint32_abcd'] = self::decoder($paire, 'uint32', 'ABCD');
                    $l['uint32_cdab'] = self::decoder($paire, 'uint32', 'CDAB');
                    $f = self::decoder($paire, 'float32', 'ABCD');
                    $l['float32_abcd'] = (is_finite((float)$f) && abs((float)$f) < 1e12) ? round((float)$f, 4) : null;
                    $f = self::decoder($paire, 'float32', 'CDAB');
                    $l['float32_cdab'] = (is_finite((float)$f) && abs((float)$f) < 1e12) ? round((float)$f, 4) : null;
                }
            }
            $lignes[] = $l;
        }
        return ['ok' => true, 'lignes' => $lignes, 'absents' => $absents];
    }

    // ── Écriture ───────────────────────────────────────────────────────────

    public function ecrire(array $point, $valeur): array
    {
        $a = $this->normaliser(is_array($point['Adresse'] ?? null) ? $point['Adresse'] : []);
        if ($a['fc'] === 2 || $a['fc'] === 4) {
            return ['ok' => false, 'message' => 'Une entrée (TOR ou registre d\'entrée) ne s\'écrit pas : choisissez un registre de maintien ou une bobine.'];
        }
        $v = self::nombre($valeur);
        if ($v === null) return ['ok' => false, 'message' => 'Valeur non numérique.'];
        // La valeur saisie est en unité « affichée » : on retire coefficient et décalage.
        $coef = (float)($point['Coefficient'] ?? 1) ?: 1.0;
        $brut = ($v - (float)($point['Decalage'] ?? 0)) / $coef;
        if (($etape = $this->connecter()) !== null) return ['ok' => false, 'message' => $etape['message']];
        if ($a['fc'] === 1) {
            $pdu = pack('Cnn', 5, $a['registre'], $brut ? 0xFF00 : 0x0000);
        } elseif ($a['type'] === 'bool' && $a['bit'] !== null) {
            // Un bit dans un registre : lecture, modification, réécriture.
            [$regs, $err] = $this->lirePlage($a['unite'], 3, $a['registre'], 1);
            if ($regs === null) return ['ok' => false, 'message' => "Lecture préalable impossible : $err"];
            $r = $brut ? ($regs[0] | (1 << $a['bit'])) : ($regs[0] & ~(1 << $a['bit']));
            $pdu = pack('Cnn', 6, $a['registre'], $r & 0xFFFF);
        } else {
            $regs = self::encoder($brut, $a['type'], $a['ordre']);
            $pdu = count($regs) === 1
                ? pack('Cnn', 6, $a['registre'], $regs[0])
                : pack('CnnC', 16, $a['registre'], count($regs), 2 * count($regs)) . pack('n*', ...$regs);
        }
        [$rep, $err] = $this->requete($a['unite'], $pdu);
        if ($rep === null) return ['ok' => false, 'message' => "Écriture refusée : $err"];
        return ['ok' => true, 'message' => 'Valeur écrite.'];
    }

    // ── Test ───────────────────────────────────────────────────────────────

    public function tester(array $points = []): array
    {
        $etapes = [];
        [$f, $etape] = $this->ouvrirTcp($this->hote(), $this->port(502));
        $etapes[] = $etape;
        if (!$f) return $etapes;
        $this->flux = $f;
        $premier = $points[0] ?? null;
        $a = $this->normaliser($premier && is_array($premier['Adresse'] ?? null) ? $premier['Adresse'] : []);
        $t0 = microtime(true);
        [$vals, $err] = $this->lirePlage($a['unite'], $a['fc'], $a['registre'], $a['nb']);
        if ($vals === null) {
            $msg = $err === 'pas de réponse Modbus dans le délai'
                ? 'La connexion est acceptée mais l\'équipement ne répond pas en Modbus TCP (port d\'un autre service ? passerelle série mal réglée ?).'
                : "L'équipement répond, mais refuse la lecture du registre {$a['registre']} (unit {$a['unite']}) : $err.";
            // Une exception prouve que le protocole fonctionne : c'est l'adresse qui ne va pas.
            $etapes[] = self::etape('protocole', str_contains((string)$err, 'exception') ? true : false,
                str_contains((string)$err, 'exception') ? "Dialogue Modbus établi. $msg" : $msg, self::ms($t0));
            if (!str_contains((string)$err, 'exception')) return $etapes;
        } else {
            $etapes[] = self::etape('protocole', true, "Dialogue Modbus établi (unit {$a['unite']}, registre {$a['registre']} = "
                . implode(' ', $vals) . ').', self::ms($t0));
        }
        if ($points) {
            $res = $this->lire($points);
            $ok = count(array_filter($res, fn($r) => $r['valeur'] !== null));
            $ko = count($res) - $ok;
            $detail = [];
            foreach ($points as $p) {
                $r = $res[(int)$p['Id']] ?? null;
                if ($r && $r['valeur'] === null) $detail[] = "« {$p['Nom']} » : " . ($r['erreur'] ?? 'illisible');
            }
            $etapes[] = self::etape('points', $ko === 0, "$ok point(s) lu(s)" . ($ko ? ", $ko en échec — " . implode(' ; ', array_slice($detail, 0, 3)) : '.'));
        }
        return $etapes;
    }
}

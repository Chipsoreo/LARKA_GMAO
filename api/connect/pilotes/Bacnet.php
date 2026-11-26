<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka Connect — pilote BACnet/IP (ASHRAE 135, annexe J).
 *
 * Services : ReadProperty, ReadPropertyMultiple (lecture groupée, repli sur
 * ReadProperty si l'automate ne le connaît pas), WriteProperty avec priorité
 * (et « rendre la main » : écriture de NULL), Who-Is pour trouver le numéro de
 * l'automate.
 *
 * Le client émet depuis un port UDP éphémère et non depuis 47808 : il peut
 * tourner sur la machine même où un automate (ou un simulateur) écoute 47808,
 * sans se répondre à lui-même — piège constaté avec les outils courants.
 *
 * BACnet n'a aucune authentification. La protection des écritures (rôle,
 * confirmation d'identité, plage permise, journal) est faite par Larka avant
 * d'appeler ce pilote.
 */

declare(strict_types=1);

final class ConnectPiloteBacnet extends ConnectPiloteBase
{
    public const OBJETS = [
        'analog-input' => 0, 'analog-output' => 1, 'analog-value' => 2,
        'binary-input' => 3, 'binary-output' => 4, 'binary-value' => 5,
        'calendar' => 6, 'command' => 7, 'device' => 8, 'event-enrollment' => 9, 'file' => 10,
        'group' => 11, 'loop' => 12, 'multi-state-input' => 13, 'multi-state-output' => 14,
        'notification-class' => 15, 'program' => 16, 'schedule' => 17, 'averaging' => 18,
        'multi-state-value' => 19, 'trend-log' => 20, 'accumulator' => 23, 'pulse-converter' => 24,
        'integer-value' => 45, 'large-analog-value' => 46, 'positive-integer-value' => 48, 'network-port' => 56,
    ];
    public const PROPRIETES = [
        'present-value' => 85, 'object-name' => 77, 'object-list' => 76, 'units' => 117,
        'description' => 28, 'status-flags' => 111, 'reliability' => 103, 'out-of-service' => 81,
        'priority-array' => 87, 'relinquish-default' => 104, 'state-text' => 110, 'number-of-states' => 74,
        'active-text' => 4, 'inactive-text' => 46, 'event-state' => 36, 'vendor-name' => 121,
        'model-name' => 70, 'firmware-revision' => 44, 'object-type' => 79, 'object-identifier' => 75,
    ];
    /** Unités BACnet → libellé court (les plus courantes en bâtiment). */
    private const UNITES = [
        62 => '°C', 63 => 'K', 64 => '°F', 98 => '%', 29 => '%HR', 71 => 'h', 72 => 'min', 73 => 's',
        18 => 'Wh', 19 => 'kWh', 47 => 'W', 48 => 'kW', 80 => 'm³', 82 => 'L', 53 => 'Pa', 54 => 'kPa',
        55 => 'bar', 2 => 'mA', 3 => 'A', 5 => 'V', 95 => '',
    ];
    private const FIABILITE = [
        0 => 'aucun défaut', 1 => 'capteur absent', 2 => 'au-dessus de la plage', 3 => 'au-dessous de la plage',
        4 => 'boucle ouverte', 5 => 'boucle en court-circuit', 6 => 'sortie absente', 7 => 'non fiable',
        8 => 'erreur de procédé', 9 => 'défaut multi-états', 10 => 'erreur de configuration',
    ];
    private const ERREURS = [
        // classe:code → texte
        '1:31' => 'objet inconnu', '2:32' => 'propriété inconnue', '2:40' => 'écriture interdite (lecture seule)',
        '2:37' => 'valeur hors plage', '2:9' => 'type de donnée refusé', '5:5' => 'service non pris en charge',
        '2:50' => 'propriété non-tableau', '2:42' => 'index de tableau invalide', '0:0' => 'erreur de l\'équipement',
    ];

    /** @var resource|null */
    private $flux = null;
    private int $invoke = 0;
    private ?bool $rpmConnu = null;

    public static function description(): array
    {
        return [
            'protocole' => 'bacnet',
            'libelle' => 'BACnet/IP',
            'resume' => 'Automates de GTB : chauffage, ventilation, climatisation, éclairage.',
            'collecte' => 'interrogation',
            'port_defaut' => 47808,
            'intervalle_defaut' => 60,
            'hote' => ['libelle' => 'Adresse IP de l\'automate', 'requis' => true],
            'identifiant' => ['libelle' => 'N° d\'automate (device instance)', 'requis' => false,
                              'aide' => 'Vide : Larka le demande à l\'automate (Who-Is) pendant le test.'],
            'champs' => [
                ['cle' => 'priorite_ecriture', 'libelle' => 'Priorité d\'écriture (1 à 16)', 'type' => 'nombre', 'defaut' => 8,
                 'aide' => '8 = opérateur manuel (usage courant). 16 = la plus faible : une programmation horaire reprend la main.'],
                ['cle' => 'lecture_groupee', 'libelle' => 'Lecture groupée (ReadPropertyMultiple)', 'type' => 'booleen', 'defaut' => true],
                ['cle' => 'delai_secondes', 'libelle' => 'Délai de réponse (s)', 'type' => 'nombre', 'defaut' => 3],
            ],
            'secrets' => [],
            'adresse' => [
                ['cle' => 'objet', 'libelle' => 'Type d\'objet', 'type' => 'choix', 'defaut' => 'analog-input', 'requis' => true,
                 'options' => array_map(fn($k) => [$k, $k], array_keys(array_filter(self::OBJETS, fn($n) => $n !== 8 && $n !== 56)))],
                ['cle' => 'instance', 'libelle' => 'Instance', 'type' => 'nombre', 'requis' => true],
                ['cle' => 'propriete', 'libelle' => 'Propriété', 'type' => 'texte', 'defaut' => 'present-value'],
                ['cle' => 'priorite', 'libelle' => 'Priorité d\'écriture', 'type' => 'nombre', 'aide' => 'Vide = celle de la connexion.'],
            ],
            'ecriture' => true,
            'decouverte' => true,
            'avertissement' => 'BACnet n\'a aucune authentification : toute écriture passe par la confirmation d\'identité de Larka.',
        ];
    }

    public function __construct(array $connexion)
    {
        parent::__construct($connexion);
        if ((float)$this->param('delai_secondes', 0) <= 0) $this->delaiReponse = 3.0;
    }

    public function fermer(): void
    {
        if ($this->flux) { @fclose($this->flux); $this->flux = null; }
    }

    // ══ Codage ═════════════════════════════════════════════════════════════

    public static function typeObjet(string $nom): ?int
    {
        $nom = strtolower(trim($nom));
        if (isset(self::OBJETS[$nom])) return self::OBJETS[$nom];
        return ctype_digit($nom) ? (int)$nom : null;
    }

    public static function nomObjet(int $type): string
    {
        $n = array_search($type, self::OBJETS, true);
        return $n === false ? (string)$type : $n;
    }

    public static function propriete(string $nom): ?int
    {
        $nom = strtolower(trim($nom));
        if ($nom === '') return 85;
        if (isset(self::PROPRIETES[$nom])) return self::PROPRIETES[$nom];
        return ctype_digit($nom) ? (int)$nom : null;
    }

    private static function nonSigne(int $v): string
    {
        if ($v < 0x100) return chr($v);
        if ($v < 0x10000) return pack('n', $v);
        if ($v < 0x1000000) return substr(pack('N', $v), 1);
        return pack('N', $v);
    }

    /** Étiquette « contexte » : numéro n, contenu $c. */
    private static function ctx(int $n, string $c): string
    {
        $l = strlen($c);
        return $l <= 4 ? chr(($n << 4) | 0x08 | $l) . $c : chr(($n << 4) | 0x08 | 5) . chr($l) . $c;
    }

    private static function app(int $tag, string $c): string
    {
        $l = strlen($c);
        if ($l <= 4) return chr(($tag << 4) | $l) . $c;
        if ($l < 254) return chr(($tag << 4) | 5) . chr($l) . $c;
        return chr(($tag << 4) | 5) . chr(254) . pack('n', $l) . $c;
    }

    private static function objId(int $type, int $instance): string
    {
        return pack('N', (($type & 0x3FF) << 22) | ($instance & 0x3FFFFF));
    }

    private function trame(string $apdu, bool $reponseAttendue): string
    {
        $npdu = "\x01" . ($reponseAttendue ? "\x04" : "\x00");
        $l = 4 + strlen($npdu) + strlen($apdu);
        return "\x81\x0A" . pack('n', $l) . $npdu . $apdu;
    }

    /** Lit une étiquette à $pos. @return array{tag:int, ctx:bool, ouvre:bool, ferme:bool, len:int} */
    private static function lireTag(string $b, int &$pos): ?array
    {
        if ($pos >= strlen($b)) return null;
        $o = ord($b[$pos++]);
        $tag = $o >> 4;
        if ($tag === 15) { if ($pos >= strlen($b)) return null; $tag = ord($b[$pos++]); }
        $ctx = (bool)($o & 0x08);
        $lvt = $o & 0x07;
        if ($ctx && $lvt === 6) return ['tag' => $tag, 'ctx' => true, 'ouvre' => true, 'ferme' => false, 'len' => 0];
        if ($ctx && $lvt === 7) return ['tag' => $tag, 'ctx' => true, 'ouvre' => false, 'ferme' => true, 'len' => 0];
        if (!$ctx && $tag === 1) return ['tag' => 1, 'ctx' => false, 'ouvre' => false, 'ferme' => false, 'len' => 0, 'bool' => $lvt];
        $len = $lvt;
        if ($lvt === 5) {
            if ($pos >= strlen($b)) return null;
            $len = ord($b[$pos++]);
            if ($len === 254) { $len = unpack('n', substr($b, $pos, 2))[1]; $pos += 2; }
            elseif ($len === 255) { $len = unpack('N', substr($b, $pos, 4))[1]; $pos += 4; }
        }
        return ['tag' => $tag, 'ctx' => $ctx, 'ouvre' => false, 'ferme' => false, 'len' => $len];
    }

    private static function entier(string $c): int
    {
        $v = 0;
        for ($i = 0; $i < strlen($c); $i++) $v = ($v << 8) | ord($c[$i]);
        return $v;
    }

    /** Décode une valeur « application » ; rend [type, valeur]. */
    private static function valeurApp(array $t, string $b, int &$pos): array
    {
        if ($t['tag'] === 1 && !$t['ctx']) return ['bool', (int)$t['bool']];
        $c = substr($b, $pos, $t['len']);
        $pos += $t['len'];
        switch ($t['tag']) {
            case 0: return ['null', null];
            case 2: return ['unsigned', self::entier($c)];
            case 3:
                $v = self::entier($c); $bits = 8 * strlen($c);
                if ($bits > 0 && $v >= (1 << ($bits - 1))) $v -= (1 << $bits);
                return ['signed', $v];
            case 4: return ['real', strlen($c) === 4 ? self::arrondiFloat32((float)unpack('G', $c)[1]) : null];
            case 5: return ['double', strlen($c) === 8 ? (float)unpack('E', $c)[1] : null];
            case 6: return ['octets', bin2hex($c)];
            case 7:
                $jeu = ord($c[0] ?? "\0"); $txt = substr($c, 1);
                if ($jeu === 4) $txt = @mb_convert_encoding($txt, 'UTF-8', 'UCS-2BE');
                elseif ($jeu === 5) $txt = @mb_convert_encoding($txt, 'UTF-8', 'ISO-8859-1');
                elseif (!mb_check_encoding($txt, 'UTF-8')) $txt = @mb_convert_encoding($txt, 'UTF-8', 'ISO-8859-1');
                return ['texte', $txt];
            case 8:
                $inutilises = ord($c[0] ?? "\0"); $bits = [];
                for ($i = 1; $i < strlen($c); $i++) {
                    for ($k = 7; $k >= 0; $k--) $bits[] = (ord($c[$i]) >> $k) & 1;
                }
                if ($inutilises > 0) $bits = array_slice($bits, 0, max(0, count($bits) - $inutilises));
                return ['bits', $bits];
            case 9: return ['enum', self::entier($c)];
            case 10: return ['date', bin2hex($c)];
            case 11: return ['heure', bin2hex($c)];
            case 12:
                $v = self::entier($c);
                return ['objet', [($v >> 22) & 0x3FF, $v & 0x3FFFFF]];
        }
        return ['inconnu', bin2hex($c)];
    }

    /** Valeurs application jusqu'à la balise fermante n. */
    private static function valeursJusqua(string $b, int &$pos, int $fermante): array
    {
        $vals = [];
        while ($pos < strlen($b)) {
            $sauve = $pos;
            $t = self::lireTag($b, $pos);
            if ($t === null) break;
            if ($t['ferme'] && $t['tag'] === $fermante) return $vals;
            if ($t['ctx']) {
                // Valeur construite (rare en lecture simple) : on saute son contenu.
                if ($t['ouvre']) { self::valeursJusqua($b, $pos, $t['tag']); continue; }
                $pos += $t['len'];
                continue;
            }
            $vals[] = self::valeurApp($t, $b, $pos);
            if ($pos === $sauve) break;
        }
        return $vals;
    }

    // ══ Échanges ═══════════════════════════════════════════════════════════

    private function ouvrir(): ?array
    {
        if ($this->flux) return null;
        $hote = $this->hote();
        if ($hote === '' || !self::hoteValide($hote)) {
            return self::etape('reseau', false, $hote === '' ? "Adresse de l'automate non renseignée." : "Adresse invalide : « $hote ».");
        }
        $errno = 0; $errstr = '';
        $f = @stream_socket_client('udp://' . (str_contains($hote, ':') ? "[$hote]" : $hote) . ':' . $this->port(47808), $errno, $errstr, 2);
        if (!$f) return self::etape('reseau', false, "Impossible d'ouvrir une socket UDP vers $hote : $errstr");
        stream_set_blocking($f, false);
        $this->flux = $f;
        return null;
    }

    /**
     * Requête confirmée ; rend [apdu de réponse, erreur].
     * @return array{0: ?string, 1: ?string, 2?: string}
     */
    private function confirme(int $service, string $corps, int $essais = 2): array
    {
        if (($e = $this->ouvrir()) !== null) return [null, $e['message']];
        $derniere = 'pas de réponse BACnet';
        for ($essai = 0; $essai < $essais; $essai++) {
            $this->invoke = ($this->invoke + 1) & 0xFF;
            $apdu = "\x00\x05" . chr($this->invoke) . chr($service) . $corps;
            @fwrite($this->flux, $this->trame($apdu, true));
            $echeance = microtime(true) + $this->delaiReponse;
            while (($reste = $echeance - microtime(true)) > 0) {
                $r = [$this->flux]; $w = null; $x = null;
                if (@stream_select($r, $w, $x, (int)floor($reste), (int)(($reste - floor($reste)) * 1e6)) < 1) break;
                $dg = @fread($this->flux, 2048);
                if ($dg === false || $dg === '') {
                    // ICMP « port inaccessible » remonte comme une erreur de lecture.
                    $derniere = 'aucun service BACnet sur ce port (port UDP fermé)';
                    usleep(20000);
                    continue;
                }
                $rep = self::apduDe($dg);
                if ($rep === null) continue;
                $type = ord($rep[0]) >> 4;
                $id = ord($rep[1] ?? "\0");              // l'invoke id suit toujours le premier octet
                if ($type === 3 && (ord($rep[0]) & 0x08)) return [null, 'réponse segmentée (trop longue) non prise en charge'];
                if ($id !== $this->invoke) continue;
                if ($type === 2) return ['', null];                       // Simple-ACK
                if ($type === 3) return [substr($rep, 3), null];          // Complex-ACK : après invoke + service
                if ($type === 5) {
                    $pos = 3; $cl = self::lireTag($rep, $pos); $c1 = $cl ? self::valeurApp($cl, $rep, $pos) : [null, 0];
                    $co = self::lireTag($rep, $pos); $c2 = $co ? self::valeurApp($co, $rep, $pos) : [null, 0];
                    $cle = (int)$c1[1] . ':' . (int)$c2[1];
                    return [null, 'erreur BACnet : ' . (self::ERREURS[$cle] ?? "classe {$c1[1]}, code {$c2[1]}"), $cle];
                }
                if ($type === 6) return [null, 'requête rejetée par l\'automate (motif ' . ord($rep[2] ?? "\0") . ')', 'rejet'];
                if ($type === 7) return [null, 'requête interrompue par l\'automate (motif ' . ord($rep[2] ?? "\0") . ')', 'abandon'];
            }
        }
        return [null, $derniere];
    }

    /** APDU d'un datagramme BACnet/IP (BVLC + NPDU retirés), ou null. */
    private static function apduDe(string $dg): ?string
    {
        if (strlen($dg) < 6 || ord($dg[0]) !== 0x81) return null;
        $fn = ord($dg[1]);
        $pos = 4;
        if ($fn === 0x04) $pos += 6;                 // Forwarded-NPDU : adresse d'origine
        elseif ($fn !== 0x0A && $fn !== 0x0B) return null;
        if (ord($dg[$pos] ?? "\0") !== 0x01) return null;
        $ctl = ord($dg[$pos + 1] ?? "\0");
        $pos += 2;
        if ($ctl & 0x80) return null;                // message de couche réseau
        if ($ctl & 0x20) { $dl = ord($dg[$pos + 2] ?? "\0"); $pos += 3 + $dl; }
        if ($ctl & 0x08) { $sl = ord($dg[$pos + 2] ?? "\0"); $pos += 3 + $sl; }
        if ($ctl & 0x20) $pos += 1;                  // nombre de sauts
        return $pos < strlen($dg) ? substr($dg, $pos) : null;
    }

    /** ReadProperty ; rend [valeurs, erreur]. */
    public function lirePropriete(int $type, int $instance, int $prop, ?int $index = null): array
    {
        $corps = self::ctx(0, self::objId($type, $instance)) . self::ctx(1, self::nonSigne($prop));
        if ($index !== null) $corps .= self::ctx(2, self::nonSigne($index));
        [$rep, $err] = $this->confirme(12, $corps);
        if ($rep === null) return [null, $err];
        $pos = 0;
        while ($pos < strlen($rep)) {
            $t = self::lireTag($rep, $pos);
            if ($t === null) break;
            if ($t['ouvre'] && $t['tag'] === 3) return [self::valeursJusqua($rep, $pos, 3), null];
            if (!$t['ouvre'] && !$t['ferme']) $pos += $t['len'];
        }
        return [null, 'réponse sans valeur'];
    }

    /**
     * ReadPropertyMultiple : present-value + status-flags + reliability de plusieurs objets.
     * @param list<array{0:int,1:int,2:int}> $refs [type, instance, propriété]
     * @return array{0: ?array, 1: ?string} [ "type:instance:prop" => [valeurs|null, erreur, flags, fiabilite] ]
     */
    private function lireMultiple(array $refs): array
    {
        $corps = '';
        foreach ($refs as [$type, $inst, $prop]) {
            $corps .= self::ctx(0, self::objId($type, $inst)) . "\x1E"
                    . self::ctx(0, self::nonSigne($prop));
            if ($prop === 85) {
                $corps .= self::ctx(0, self::nonSigne(111));
                if (in_array($type, [0, 3, 13], true)) $corps .= self::ctx(0, self::nonSigne(103));
            }
            $corps .= "\x1F";
        }
        [$rep, $err, $code] = $this->confirme(14, $corps) + [2 => null];
        if ($rep === null) return [null, $err . ($code === 'rejet' || $code === '5:5' ? '|rpm-inconnu' : '')];
        $out = [];
        $pos = 0;
        $objet = null;
        while ($pos < strlen($rep)) {
            $t = self::lireTag($rep, $pos);
            if ($t === null) break;
            if ($t['ctx'] && $t['tag'] === 0 && !$t['ouvre'] && !$t['ferme']) {
                $v = self::entier(substr($rep, $pos, $t['len'])); $pos += $t['len'];
                $objet = (($v >> 22) & 0x3FF) . ':' . ($v & 0x3FFFFF);
                continue;
            }
            if ($t['ctx'] && $t['tag'] === 2 && !$t['ouvre']) {
                $prop = self::entier(substr($rep, $pos, $t['len'])); $pos += $t['len'];
                // index facultatif (contexte 3), puis valeur (4) ou erreur (5)
                $sauve = $pos;
                $t2 = self::lireTag($rep, $pos);
                if ($t2 && $t2['ctx'] && $t2['tag'] === 3 && !$t2['ouvre']) { $pos += $t2['len']; $t2 = self::lireTag($rep, $pos); }
                if ($t2 && $t2['ouvre'] && $t2['tag'] === 4) {
                    $vals = self::valeursJusqua($rep, $pos, 4);
                    $out[$objet . ':' . $prop] = [$vals, null];
                } elseif ($t2 && $t2['ouvre'] && $t2['tag'] === 5) {
                    $ev = self::valeursJusqua($rep, $pos, 5);
                    $cle = (int)($ev[0][1] ?? 0) . ':' . (int)($ev[1][1] ?? 0);
                    $out[$objet . ':' . $prop] = [null, self::ERREURS[$cle] ?? "erreur $cle"];
                } else {
                    $pos = $sauve;
                }
                continue;
            }
            if (!$t['ouvre'] && !$t['ferme']) $pos += $t['len'];
        }
        return [$out, null];
    }

    /** Adresse normalisée : [type, instance, propriété, priorité]. Accepte « analog-input,1 ». */
    private function adresse(array $a): ?array
    {
        $objet = (string)($a['objet'] ?? '');
        $instance = $a['instance'] ?? null;
        if (preg_match('/^\s*([a-z\-]+|\d+)\s*[,:]\s*(\d+)\s*$/i', $objet, $m)) { $objet = $m[1]; $instance = $m[2]; }
        $type = self::typeObjet($objet);
        if ($type === null || $instance === null || $instance === '' || !is_numeric($instance)) return null;
        $prop = self::propriete((string)($a['propriete'] ?? 'present-value'));
        if ($prop === null) return null;
        $prio = (int)(($a['priorite'] ?? '') !== '' && ($a['priorite'] ?? null) !== null ? $a['priorite'] : $this->param('priorite_ecriture', 8));
        return [$type, (int)$instance, $prop, max(1, min(16, $prio))];
    }

    /** Première valeur utile d'une liste de valeurs application. */
    private static function scalaire(?array $vals): array
    {
        if (!$vals) return [null, null];
        [$t, $v] = $vals[0];
        if (in_array($t, ['real', 'double', 'unsigned', 'signed', 'enum', 'bool'], true)) return [(float)$v, null];
        if ($t === 'texte') return [self::nombre($v), (string)$v];
        if ($t === 'bits') return [null, implode('', $v)];
        if ($t === 'null') return [null, 'null'];
        return [null, is_scalar($v) ? (string)$v : json_encode($v)];
    }

    public function lire(array $points): array
    {
        $out = [];
        $refs = [];
        foreach ($points as $p) {
            $a = $this->adresse(is_array($p['Adresse'] ?? null) ? $p['Adresse'] : []);
            if ($a === null) { $out[(int)$p['Id']] = self::enDefaut('adresse BACnet incomplète (type d\'objet et instance)'); continue; }
            $refs[(int)$p['Id']] = $a;
        }
        if (!$refs) return $out;
        if (($e = $this->ouvrir()) !== null) {
            foreach ($refs as $id => $_) $out[$id] = self::enDefaut($e['message']);
            return $out;
        }
        $groupee = filter_var($this->param('lecture_groupee', true), FILTER_VALIDATE_BOOLEAN) && $this->rpmConnu !== false;
        if ($groupee) {
            foreach (array_chunk($refs, 12, true) as $lot) {
                [$res, $err] = $this->lireMultiple(array_values(array_map(fn($a) => [$a[0], $a[1], $a[2]], $lot)));
                if ($res === null) {
                    if (str_contains((string)$err, 'rpm-inconnu')) { $this->rpmConnu = false; break; }
                    // Aucune réponse : inutile d'insister point par point.
                    if (str_contains((string)$err, 'pas de réponse') || str_contains((string)$err, 'port UDP fermé')) {
                        foreach ($refs as $id => $_) $out[$id] = $out[$id] ?? self::enDefaut((string)$err);
                        return $out;
                    }
                    foreach ($lot as $id => $_) $out[$id] = self::enDefaut((string)$err);
                    continue;
                }
                $this->rpmConnu = true;
                foreach ($lot as $id => [$type, $inst, $prop]) {
                    $cle = "$type:$inst";
                    [$vals, $e1] = $res["$cle:$prop"] ?? [null, 'propriété absente de la réponse'];
                    if ($vals === null) { $out[$id] = self::enDefaut((string)$e1); continue; }
                    [$v, $txt] = self::scalaire($vals);
                    $qualite = 'bonne';
                    $flags = $res["$cle:111"][0][0][1] ?? null;            // [faute en alarme, défaut, forcé, hors service]
                    $fiab = $res["$cle:103"][0][0][1] ?? 0;
                    if (is_array($flags) && (($flags[1] ?? 0) === 1 || ($flags[3] ?? 0) === 1)) $qualite = 'mauvaise';
                    if ((int)$fiab !== 0) $qualite = 'mauvaise';
                    $r = self::valeurLue($v, $txt, $qualite);
                    if ((int)$fiab !== 0) $r['erreur'] = 'automate : ' . (self::FIABILITE[(int)$fiab] ?? "fiabilité $fiab");
                    elseif ($qualite === 'mauvaise') $r['erreur'] = 'automate : point en défaut ou hors service';
                    $out[$id] = $r;
                }
            }
            if ($this->rpmConnu !== false) return $out;
        }
        foreach ($refs as $id => [$type, $inst, $prop]) {
            if (isset($out[$id])) continue;
            [$vals, $err] = $this->lirePropriete($type, $inst, $prop);
            if ($vals === null) { $out[$id] = self::enDefaut((string)$err); continue; }
            [$v, $txt] = self::scalaire($vals);
            $out[$id] = self::valeurLue($v, $txt);
        }
        return $out;
    }

    public function ecrire(array $point, $valeur): array
    {
        $a = $this->adresse(is_array($point['Adresse'] ?? null) ? $point['Adresse'] : []);
        if ($a === null) return ['ok' => false, 'message' => 'Adresse BACnet incomplète.'];
        [$type, $inst, $prop, $prio] = $a;
        if ($valeur === null || $valeur === 'relacher') {
            $val = self::app(0, '');
            $libelle = 'Commande relâchée (l\'automate reprend la main).';
        } else {
            $v = self::nombre($valeur);
            if ($v === null) return ['ok' => false, 'message' => 'Valeur non numérique.'];
            $coef = (float)($point['Coefficient'] ?? 1) ?: 1.0;
            $brut = ($v - (float)($point['Decalage'] ?? 0)) / $coef;
            if (in_array($type, [3, 4, 5], true)) $val = self::app(9, self::nonSigne($brut ? 1 : 0));
            elseif (in_array($type, [13, 14, 19, 48], true)) $val = self::app(2, self::nonSigne(max(1, (int)round($brut))));
            elseif ($type === 45) $val = self::app(3, pack('N', (int)round($brut)));
            else $val = self::app(4, pack('G', $brut));
            $libelle = 'Valeur écrite.';
        }
        $corps = self::ctx(0, self::objId($type, $inst)) . self::ctx(1, self::nonSigne($prop))
               . "\x3E" . $val . "\x3F" . self::ctx(4, self::nonSigne($prio));
        [$rep, $err] = $this->confirme(15, $corps, 1);
        if ($rep === null) return ['ok' => false, 'message' => 'Écriture refusée : ' . $err];
        return ['ok' => true, 'message' => $libelle . " (priorité $prio)"];
    }

    /** N° d'automate : celui saisi, sinon lecture « device 4194303 » (joker), sinon Who-Is. */
    public function numeroAutomate(): ?int
    {
        $id = trim((string)($this->c['Identifiant'] ?? ''));
        if ($id !== '' && ctype_digit($id)) return (int)$id;
        [$vals] = $this->lirePropriete(8, 4194303, 75);
        if ($vals && ($vals[0][0] ?? '') === 'objet') return (int)$vals[0][1][1];
        // Who-Is en unicast : certains automates répondent I-Am à l'émetteur.
        if (($e = $this->ouvrir()) !== null) return null;
        @fwrite($this->flux, $this->trame("\x10\x08", false));
        $echeance = microtime(true) + 1.5;
        while (($reste = $echeance - microtime(true)) > 0) {
            $r = [$this->flux]; $w = null; $x = null;
            if (@stream_select($r, $w, $x, 0, (int)($reste * 1e6)) < 1) break;
            $dg = @fread($this->flux, 2048);
            $apdu = $dg ? self::apduDe($dg) : null;
            if ($apdu !== null && ord($apdu[0]) === 0x10 && ord($apdu[1] ?? "\0") === 0x00) {
                $pos = 2; $t = self::lireTag($apdu, $pos);
                if ($t) { $v = self::valeurApp($t, $apdu, $pos); if ($v[0] === 'objet') return (int)$v[1][1]; }
            }
        }
        return null;
    }

    // ══ Détection (ConnectDetection) ═══════════════════════════════════════

    /** Datagramme Who-Is (sans bornes : tous les automates répondent), diffusé ou adressé. */
    public static function trameWhoIs(bool $diffusion): string
    {
        $npdu = "\x01\x00";
        $apdu = "\x10\x08";
        return "\x81" . ($diffusion ? "\x0B" : "\x0A") . pack('n', 4 + strlen($npdu) + strlen($apdu)) . $npdu . $apdu;
    }

    /** N° d'automate annoncé par un datagramme I-Am, ou null si ce n'en est pas un. */
    public static function iAm(string $dg): ?int
    {
        $apdu = self::apduDe($dg);
        if ($apdu === null || strlen($apdu) < 3 || ord($apdu[0]) !== 0x10 || ord($apdu[1]) !== 0x00) return null;
        $pos = 2;
        $t = self::lireTag($apdu, $pos);
        if (!$t || $t['ctx'] || $t['tag'] !== 12) return null;
        $v = self::valeurApp($t, $apdu, $pos);
        return ($v[0] === 'objet' && (int)$v[1][0] === 8) ? (int)$v[1][1] : null;
    }

    /** Nom, fabricant et modèle de l'automate (lus à la détection ; vides s'il ne les donne pas). */
    public function identite(): array
    {
        $num = $this->numeroAutomate();
        if ($num === null) return ['numero' => null, 'nom' => '', 'fabricant' => '', 'modele' => '', 'emplacement' => ''];
        $muet = false;
        $texte = function (int $prop) use ($num, &$muet): string {
            if ($muet) return '';
            [$v, $err] = $this->lirePropriete(8, $num, $prop);
            // Pas de réponse du tout : inutile d'attendre encore pour les propriétés suivantes.
            if ($v === null && $err !== null && !str_starts_with((string)$err, 'erreur BACnet')) $muet = true;
            return trim((string)(self::scalaire($v)[1] ?? ''));
        };
        // Emplacement (« location ») : souvent le bâtiment ou le local — il aide à ranger l'automate.
        return ['numero' => $num, 'nom' => $texte(77), 'fabricant' => $texte(121), 'modele' => $texte(70), 'emplacement' => $texte(58)];
    }

    public function tester(array $points = []): array
    {
        $etapes = [];
        if (($e = $this->ouvrir()) !== null) return [$e];
        $hote = $this->hote(); $port = $this->port(47808);
        $t0 = microtime(true);
        $num = $this->numeroAutomate();
        if ($num === null) {
            $etapes[] = self::etape('reseau', false, "Aucune réponse BACnet de $hote:$port (UDP). En UDP, ce silence ne distingue pas "
                . "un pare-feu d'un automate éteint ou d'un mauvais port : seule une réponse BACnet fait foi.", self::ms($t0),
                ['demande_it' => self::demandeIt($hote, $port, 'UDP')]);
            return $etapes;
        }
        [$nom, $err] = $this->lirePropriete(8, $num, 77);
        [$fab] = $this->lirePropriete(8, $num, 121);
        $etapes[] = self::etape('reseau', true, "$hote:$port répond en BACnet/IP.", self::ms($t0));
        $etapes[] = self::etape('protocole', $nom !== null,
            $nom !== null ? 'Automate n° ' . $num . ' « ' . self::scalaire($nom)[1] . ' »' . ($fab ? ' — ' . self::scalaire($fab)[1] : '') . '.'
                          : "Automate n° $num trouvé, mais son nom est illisible : $err.", self::ms($t0), ['identifiant' => $num]);
        if ($points) {
            $res = $this->lire($points);
            $ok = count(array_filter($res, fn($r) => $r['valeur'] !== null || ($r['texte'] ?? null) !== null));
            $ko = count($res) - $ok;
            $det = [];
            foreach ($points as $p) {
                $r = $res[(int)$p['Id']] ?? null;
                if ($r && $r['valeur'] === null && ($r['texte'] ?? null) === null) $det[] = "« {$p['Nom']} » : " . ($r['erreur'] ?? 'illisible');
            }
            $etapes[] = self::etape('points', $ko === 0, "$ok point(s) lu(s)" . ($ko ? ", $ko en échec — " . implode(' ; ', array_slice($det, 0, 3)) : '.'));
        }
        $etapes[] = self::etape('droits', null, 'BACnet n\'a pas d\'authentification : les écritures sont protégées par Larka (confirmation d\'identité, plage permise, journal).');
        return $etapes;
    }

    public function decouvrir(): array
    {
        $num = $this->numeroAutomate();
        if ($num === null) return [];
        [$liste, $err] = $this->lirePropriete(8, $num, 76);
        $objets = [];
        if ($liste === null) {
            // Liste trop longue pour une seule trame : élément par élément.
            [$n] = $this->lirePropriete(8, $num, 76, 0);
            $total = (int)($n[0][1] ?? 0);
            for ($i = 1; $i <= min($total, 400); $i++) {
                [$v] = $this->lirePropriete(8, $num, 76, $i);
                if ($v && $v[0][0] === 'objet') $objets[] = $v[0][1];
            }
        } else {
            foreach ($liste as [$t, $v]) if ($t === 'objet') $objets[] = $v;
        }
        $props = [];
        foreach (array_slice($objets, 0, 300) as [$type, $inst]) {
            if (in_array($type, [8, 56, 15, 6, 17, 20, 9, 10, 16], true)) continue;   // device, port réseau, calendriers…
            [$nomV] = $this->lirePropriete($type, $inst, 77);
            [$valV] = $this->lirePropriete($type, $inst, 85);
            $unite = '';
            if (in_array($type, [0, 1, 2, 46], true)) {
                [$u] = $this->lirePropriete($type, $inst, 117);
                $unite = self::UNITES[(int)($u[0][1] ?? 95)] ?? '';
            }
            $nom = (string)(self::scalaire($nomV)[1] ?? (self::nomObjet($type) . ' ' . $inst));
            [$val, $txt] = self::scalaire($valV);
            $nature = in_array($type, [1, 2], true) ? 'consigne' : (in_array($type, [3, 4, 5, 13, 14, 19], true) ? 'etat' : 'mesure');
            if (preg_match('/d[ée]faut|alarm|fault|panne/iu', $nom)) $nature = 'alarme';
            if (preg_match('/heure|d[ée]marrage|compteur|index|energy|énergie/iu', $nom) || $unite === 'h' || $unite === 'kWh') $nature = 'compteur';
            if ($type === 2 && !preg_match('/consigne|setpoint|cons/iu', $nom) && $nature === 'consigne') $nature = 'mesure';
            $props[] = [
                'nom' => $nom, 'code' => self::nomObjet($type) . ',' . $inst,
                'adresse' => ['objet' => self::nomObjet($type), 'instance' => $inst, 'propriete' => 'present-value'],
                'unite' => $unite, 'nature' => $nature, 'valeur' => $val ?? $txt,
                'ecriture' => in_array($type, [1, 2, 4, 5, 14, 19], true),
            ];
        }
        return $props;
    }
}

<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * ═══════════════════════════════════════════════════════════════════════════
 *  Larka Connect — contrat commun des pilotes de protocole
 * ═══════════════════════════════════════════════════════════════════════════
 *
 *  Larka ne connaît pas les protocoles : il connaît ce contrat. Chaque
 *  protocole (Modbus, BACnet, MQTT, REST, CSV, OCPP) est un pilote qui le
 *  respecte ; en ajouter un revient à ajouter un fichier dans pilotes/ et une
 *  ligne dans ConnectPilotes::CLASSES.
 *
 *  Un pilote :
 *    - se DÉCRIT (description()) : champs de la connexion et de l'adresse d'un
 *      point. L'écran dessine ses formulaires d'après cette description ;
 *    - se TESTE (tester()) en étapes lisibles : réseau, protocole, droits.
 *      Quand le réseau bloque, il rédige la demande à transmettre à l'IT ;
 *    - LIT une liste de points (lire()) et rend, pour chacun, une valeur et sa
 *      qualité — jamais d'exception pour un point illisible : un point en
 *      défaut ne doit pas priver les autres de leur relevé ;
 *    - ÉCRIT (ecrire()) quand le protocole le permet. L'autorisation (rôle,
 *      confirmation d'identité, plage permise) est vérifiée AVANT, par la
 *      route : le pilote ne fait qu'exécuter ;
 *    - propose des points (decouvrir()) quand l'équipement sait les lister.
 *
 *  Aucun pilote ne dépend d'une bibliothèque : sockets PHP (stream_socket_*),
 *  cURL quand il est là, sinon les flux HTTP de PHP.
 * ═══════════════════════════════════════════════════════════════════════════
 */

declare(strict_types=1);

interface ConnectPilote
{
    /** Ce que le pilote attend : champs de connexion, adresse d'un point, capacités. */
    public static function description(): array;

    /** Étapes du test de connexion : [['etape','ok','message','ms'], …]. */
    public function tester(array $points = []): array;

    /**
     * Lit les points de la connexion.
     * @param list<array> $points lignes ConnectPoints, « Adresse » déjà décodée en tableau
     * @return array<int, array{valeur: ?float, texte: ?string, qualite: string, horodatage?: ?string, erreur?: ?string, serie?: list<array>}>
     *         indexé par Id de point. « serie » : valeurs horodatées en plus de la
     *         dernière (fichier CSV, messages MQTT accumulés pendant l'absence).
     */
    public function lire(array $points): array;

    /** Écrit une valeur. @return array{ok: bool, message: string} */
    public function ecrire(array $point, $valeur): array;

    /** Points proposés par l'équipement. @return list<array{nom, code, adresse, unite?, nature?, valeur?}> */
    public function decouvrir(): array;

    public function fermer(): void;
}

/**
 * Outils partagés : paramètres, ouverture TCP avec diagnostic, étapes du test,
 * texte de la demande à l'IT.
 */
abstract class ConnectPiloteBase implements ConnectPilote
{
    /** Connexion normalisée : Id, Nom, Protocole, Hote, Port, Identifiant, params[], secret[]. */
    protected array $c;
    /** Délais (secondes). Bornés : un équipement muet ne doit pas figer la collecte. */
    protected float $delaiConnexion = 3.0;
    protected float $delaiReponse = 4.0;

    public function __construct(array $connexion)
    {
        $this->c = $connexion;
        $this->c['params'] = is_array($connexion['params'] ?? null) ? $connexion['params'] : [];
        $this->c['secret'] = is_array($connexion['secret'] ?? null) ? $connexion['secret'] : [];
        $d = (float)$this->param('delai_secondes', 0);
        if ($d > 0) $this->delaiReponse = max(0.5, min(30.0, $d));
    }

    public function fermer(): void {}

    public function decouvrir(): array
    {
        return [];
    }

    public function ecrire(array $point, $valeur): array
    {
        return ['ok' => false, 'message' => "Ce protocole n'accepte pas d'écriture."];
    }

    protected function param(string $cle, $defaut = null)
    {
        $v = $this->c['params'][$cle] ?? null;
        return ($v === null || $v === '') ? $defaut : $v;
    }

    protected function secretVal(string $cle): string
    {
        return (string)($this->c['secret'][$cle] ?? '');
    }

    protected function hote(): string
    {
        return trim((string)($this->c['Hote'] ?? ''));
    }

    protected function port(int $defaut): int
    {
        $p = (int)($this->c['Port'] ?? 0);
        return ($p > 0 && $p < 65536) ? $p : $defaut;
    }

    protected static function etape(string $code, ?bool $ok, string $message, ?float $ms = null, array $plus = []): array
    {
        return ['etape' => $code, 'ok' => $ok, 'message' => $message,
                'ms' => $ms === null ? null : (int)round($ms)] + $plus;
    }

    protected static function ms(float $t0): float
    {
        return (microtime(true) - $t0) * 1000;
    }

    /** Point lu sans valeur : la raison est gardée pour l'écran. */
    protected static function enDefaut(string $erreur, string $qualite = 'mauvaise'): array
    {
        return ['valeur' => null, 'texte' => null, 'qualite' => $qualite, 'erreur' => $erreur];
    }

    protected static function valeurLue($valeur, ?string $texte = null, string $qualite = 'bonne', ?string $horodatage = null): array
    {
        $v = (is_int($valeur) || is_float($valeur)) ? (float)$valeur : (is_numeric($valeur) ? (float)$valeur : null);
        if ($v !== null && (is_nan($v) || is_infinite($v))) { $v = null; $qualite = 'mauvaise'; }
        return ['valeur' => $v, 'texte' => $texte, 'qualite' => $qualite, 'horodatage' => $horodatage];
    }

    /**
     * Ouvre une connexion TCP et explique un échec en mots utiles :
     * refus (rien n'écoute), silence (pare-feu probable), nom inconnu, route.
     *
     * @return array{0: resource|null, 1: array} [flux, étape « reseau »]
     */
    protected function ouvrirTcp(string $hote, int $port, string $schema = 'tcp', array $ssl = []): array
    {
        if ($hote === '') {
            return [null, self::etape('reseau', false, "Adresse de l'équipement non renseignée.")];
        }
        if (!self::hoteValide($hote)) {
            return [null, self::etape('reseau', false, "Adresse invalide : « $hote ».")];
        }
        $t0 = microtime(true);
        $ctx = stream_context_create($ssl ? ['ssl' => $ssl] : []);
        $errno = 0; $errstr = '';
        $cible = $schema . '://' . (str_contains($hote, ':') && !str_starts_with($hote, '[') ? "[$hote]" : $hote) . ':' . $port;
        $flux = @stream_socket_client($cible, $errno, $errstr, $this->delaiConnexion, STREAM_CLIENT_CONNECT, $ctx);
        $ms = self::ms($t0);
        if ($flux) {
            stream_set_timeout($flux, (int)floor($this->delaiReponse), (int)(($this->delaiReponse - floor($this->delaiReponse)) * 1e6));
            return [$flux, self::etape('reseau', true, "$hote:$port joignable.", $ms)];
        }
        return [null, self::diagnosticEchec($hote, $port, 'TCP', $errno, $errstr, $ms)];
    }

    /** Étape « réseau » en échec, avec la demande à transmettre à l'IT quand c'est utile. */
    protected static function diagnosticEchec(string $hote, int $port, string $transport, int $errno, string $errstr, float $ms): array
    {
        $e = strtolower($errstr);
        $refuse  = in_array($errno, [111, 10061, 61], true) || str_contains($e, 'refused') || str_contains($e, 'refus');
        $delai   = in_array($errno, [110, 10060, 60], true) || str_contains($e, 'timed out') || str_contains($e, 'timeout') || $errno === 0 && $errstr === '';
        $nom     = str_contains($e, 'getaddrinfo') || str_contains($e, 'name or service') || str_contains($e, 'no such host')
                || str_contains($e, 'nodename') || str_contains($e, 'temporary failure in name resolution');
        $route   = in_array($errno, [113, 101, 10065, 10051, 65, 51], true) || str_contains($e, 'no route') || str_contains($e, 'unreachable');
        if ($nom) {
            return self::etape('reseau', false, "Le nom « $hote » est inconnu du serveur (DNS). Utilisez l'adresse IP, ou faites déclarer ce nom.", $ms);
        }
        if ($refuse) {
            return self::etape('reseau', false, "$hote:$port REFUSE la connexion : l'équipement répond, mais rien n'écoute sur ce port "
                . "(service arrêté, mauvais port, ou passerelle désactivée).", $ms);
        }
        if ($route) {
            return self::etape('reseau', false, "$hote est inaccessible depuis le serveur (aucune route) : réseau ou VLAN séparé.", $ms,
                ['demande_it' => self::demandeIt($hote, $port, $transport)]);
        }
        if ($delai) {
            return self::etape('reseau', false, "$hote:$port ne répond pas : probablement filtré par un pare-feu, ou équipement éteint.", $ms,
                ['demande_it' => self::demandeIt($hote, $port, $transport)]);
        }
        return self::etape('reseau', false, "$hote:$port injoignable : " . ($errstr !== '' ? $errstr : "erreur $errno") . '.', $ms,
            ['demande_it' => self::demandeIt($hote, $port, $transport)]);
    }

    /** Texte prêt à copier pour l'équipe informatique. */
    public static function demandeIt(string $hote, int $port, string $transport): string
    {
        $source = self::adresseServeur($hote);
        return "Bonjour,\n\nPour raccorder Larka à un équipement technique du bâtiment, merci d'autoriser le flux suivant :\n\n"
             . "  • source      : serveur Larka" . ($source !== '' ? " ($source)" : '') . "\n"
             . "  • destination : $hote\n"
             . "  • port        : $port/" . strtolower($transport) . "\n"
             . "  • sens        : du serveur Larka vers l'équipement uniquement\n"
             . "  • usage       : lecture des mesures (supervision et GMAO)\n\nMerci.";
    }

    /** Adresse IP par laquelle ce serveur sort vers $hote (sans rien envoyer : UDP « connecté »). */
    protected static function adresseServeur(string $hote): string
    {
        if (!self::hoteValide($hote)) return '';
        $s = @stream_socket_client('udp://' . (str_contains($hote, ':') ? "[$hote]" : $hote) . ':9', $n, $m, 1);
        if (!$s) return '';
        $nom = (string)@stream_socket_get_name($s, false);
        fclose($s);
        $ip = preg_replace('/:\d+$/', '', $nom);
        return trim((string)$ip, '[]');
    }

    /** Nom d'hôte ou IP plausible : pas d'espace, pas de schéma, pas de chemin. */
    public static function hoteValide(string $hote): bool
    {
        if ($hote === '' || strlen($hote) > 253) return false;
        if (filter_var($hote, FILTER_VALIDATE_IP)) return true;
        return (bool)preg_match('/^(?=.{1,253}$)[A-Za-z0-9](?:[A-Za-z0-9\-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9\-]{0,61}[A-Za-z0-9])?)*$/', $hote);
    }

    /** Lit exactement $n octets avant l'échéance (microtime), ou null. */
    protected static function lireExact($flux, int $n, float $echeance): ?string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            $reste = $echeance - microtime(true);
            if ($reste <= 0) return null;
            $r = [$flux]; $w = null; $x = null;
            $sec = (int)floor($reste); $usec = (int)(($reste - $sec) * 1e6);
            $pret = @stream_select($r, $w, $x, $sec, $usec);
            if ($pret === false) return null;
            if ($pret === 0) continue;
            $morceau = @fread($flux, $n - strlen($buf));
            if ($morceau === false || ($morceau === '' && feof($flux))) return null;
            $buf .= $morceau;
        }
        return $buf;
    }

    /**
     * Arrondi d'un réel sur 32 bits à sa précision réelle (7 chiffres
     * significatifs) : l'automate envoie 22,2, le flottant le restitue en
     * 22,200000762939453 — on garde 22,2.
     */
    public static function arrondiFloat32(float $v): float
    {
        if ($v == 0.0 || !is_finite($v)) return $v;
        $chiffres = 7 - (int)floor(log10(abs($v))) - 1;
        return round($v, max(0, min(12, $chiffres)));
    }

    /** Valeur numérique d'un texte « 21,5 », « 1 234,5 », « 3.2e3 »… ou null. */
    public static function nombre($v): ?float
    {
        if (is_int($v) || is_float($v)) return (float)$v;
        if (is_bool($v)) return $v ? 1.0 : 0.0;
        if (!is_string($v)) return null;
        $t = trim(str_replace(["\u{00A0}", "\u{202F}", ' '], '', $v));
        if ($t === '') return null;
        if (preg_match('/^-?\d{1,3}(\.\d{3})+(,\d+)?$/', $t)) $t = str_replace('.', '', $t);
        $t = str_replace(',', '.', $t);
        return is_numeric($t) ? (float)$t : null;
    }

    /**
     * Valeur au bout d'un chemin dans un document JSON décodé.
     *   « valeur »            → $doc['valeur']
     *   « data.items.0.v »    → $doc['data']['items'][0]['v']
     *   « [id=X].valeur »     → l'élément de la liste dont id vaut X, puis sa clé valeur
     *   « a.*.v »              → la liste des « v » de tous les éléments de a (que
     *                            sommeSiListe() additionne : trois phases → le total)
     *   chemin vide            → le document lui-même
     */
    public static function suivreChemin($doc, string $chemin)
    {
        $chemin = trim($chemin);
        if ($chemin === '' || $chemin === '$') return $doc;
        if (str_starts_with($chemin, '$.')) $chemin = substr($chemin, 2);
        preg_match_all('/\[([^\]=]+)=([^\]]*)\]|[^.\[\]]+/u', $chemin, $m, PREG_SET_ORDER);
        $cur = $doc;
        foreach ($m as $i => $seg) {
            if (($seg[0] ?? '') === '*' && (!isset($seg[1]) || $seg[1] === '')) {
                if (!is_array($cur)) return null;
                $reste = implode('.', array_map(fn($s) => $s[0], array_slice($m, $i + 1)));
                $out = [];
                foreach ($cur as $el) { $v = $reste === '' ? $el : self::suivreChemin($el, $reste); if ($v !== null) $out[] = $v; }
                return $out;
            }
            if (isset($seg[1]) && $seg[1] !== '') {
                if (!is_array($cur)) return null;
                $cle = $seg[1]; $attendu = $seg[2]; $trouve = null;
                foreach ($cur as $el) {
                    if (is_array($el) && array_key_exists($cle, $el) && (string)$el[$cle] === $attendu) { $trouve = $el; break; }
                }
                if ($trouve === null) return null;
                $cur = $trouve;
                continue;
            }
            $cle = $seg[0];
            if (is_array($cur) && array_key_exists($cle, $cur)) { $cur = $cur[$cle]; continue; }
            if (is_array($cur) && ctype_digit($cle) && array_key_exists((int)$cle, $cur)) { $cur = $cur[(int)$cle]; continue; }
            return null;
        }
        return $cur;
    }

    /** Liste de nombres (chemin avec « * ») → leur somme ; toute autre valeur inchangée. */
    public static function sommeSiListe($v)
    {
        if (!is_array($v) || $v === [] || !array_is_list($v)) return $v;
        $s = 0.0;
        foreach ($v as $x) { $n = self::nombre(is_scalar($x) ? $x : null); if ($n === null) return $v; $s += $n; }
        return $s;
    }
}

/** Registre des pilotes. */
final class ConnectPilotes
{
    /** protocole => classe ; l'ordre est celui de l'écran. */
    public const CLASSES = [
        'modbus' => 'ConnectPiloteModbus',
        'bacnet' => 'ConnectPiloteBacnet',
        'mqtt'   => 'ConnectPiloteMqtt',
        'rest'   => 'ConnectPiloteRest',
        'csv'    => 'ConnectPiloteCsv',
        'ocpp'   => 'ConnectPiloteOcpp',
        // Capteurs radio reçus par les passerelles du bâtiment (serveur intégré, LorawanServeur.php).
        'lorawan' => 'ConnectPiloteLorawan',
        // Compteurs radio (eau, chaleur, gaz) entendus par une clé USB réceptrice.
        'wmbus'   => 'ConnectPiloteWmbus',
        'simulation' => 'ConnectPiloteSimulation',
        // Appareil hors réseau au protocole encore inconnu : le PC releveur le détecte.
        'auto'   => 'ConnectPiloteAuto',
    ];

    public static function charger(): void
    {
        foreach (array_keys(self::CLASSES) as $p) {
            require_once __DIR__ . '/pilotes/' . ucfirst($p) . '.php';
        }
    }

    public static function existe(string $protocole): bool
    {
        return isset(self::CLASSES[$protocole]);
    }

    /** Descriptions de tous les pilotes, pour l'écran. */
    public static function catalogue(): array
    {
        self::charger();
        $out = [];
        foreach (self::CLASSES as $p => $classe) $out[] = $classe::description();
        return $out;
    }

    public static function description(string $protocole): ?array
    {
        if (!self::existe($protocole)) return null;
        self::charger();
        $classe = self::CLASSES[$protocole];
        return $classe::description();
    }

    public static function creer(array $connexion): ConnectPilote
    {
        $p = (string)($connexion['Protocole'] ?? '');
        if (!self::existe($p)) throw new InvalidArgumentException("Protocole inconnu : $p");
        self::charger();
        $classe = self::CLASSES[$p];
        return new $classe($connexion);
    }
}

<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka Connect — pilote MQTT 3.1.1 (capteurs radio LoRaWAN, passerelles IoT).
 *
 * En MQTT, ce sont les capteurs qui parlent quand ils veulent. Larka ne reste
 * pourtant pas connecté en permanence : il se connecte à chaque collecte avec
 * une SESSION PERSISTANTE (clean session = 0, identifiant client stable) et un
 * abonnement en QoS 1. Pendant son absence, le broker garde les messages ; à
 * la connexion suivante il les remet tous, chacun avec son horodatage. Aucun
 * relevé n'est perdu entre deux passages, et aucun service permanent n'est
 * nécessaire.
 *
 * Les doublons (même message remis deux fois, cas prévu par le QoS 1) sont
 * écartés ; le message le plus récent donne la valeur courante, les autres
 * complètent l'historique.
 */

declare(strict_types=1);

final class ConnectPiloteMqtt extends ConnectPiloteBase
{
    /** @var resource|null */
    private $flux = null;
    private int $paquetId = 0;
    private ?string $sessionPresente = null;

    public static function description(): array
    {
        return [
            'protocole' => 'mqtt',
            'libelle' => 'MQTT',
            'resume' => 'Capteurs radio (LoRaWAN via un serveur réseau), passerelles IoT, compteurs communicants.',
            'collecte' => 'abonnement',
            'port_defaut' => 1883,
            'intervalle_defaut' => 60,
            'hote' => ['libelle' => 'Adresse du broker', 'requis' => true],
            'identifiant' => ['libelle' => 'Identifiant client MQTT', 'requis' => false,
                              'aide' => 'Vide : Larka en choisit un, stable, pour que le broker garde les messages entre deux collectes.'],
            'champs' => [
                ['cle' => 'sujets', 'libelle' => 'Sujets à suivre (un par ligne)', 'type' => 'texte_long', 'defaut' => '',
                 'aide' => 'Filtres MQTT, « + » et « # » acceptés, ex. larka/eau/+/up. Vide : les sujets des points suffisent.'],
                ['cle' => 'tls', 'libelle' => 'Connexion chiffrée (TLS)', 'type' => 'booleen', 'defaut' => false],
                ['cle' => 'verifier_certificat', 'libelle' => 'Vérifier le certificat du broker', 'type' => 'booleen', 'defaut' => true],
                ['cle' => 'session_persistante', 'libelle' => 'Session persistante (aucun message perdu)', 'type' => 'booleen', 'defaut' => true],
                ['cle' => 'attente_secondes', 'libelle' => 'Écoute après le dernier message (s)', 'type' => 'nombre', 'defaut' => 1.5],
            ],
            'secrets' => [
                ['cle' => 'utilisateur', 'libelle' => 'Utilisateur', 'type' => 'texte'],
                ['cle' => 'mot_de_passe', 'libelle' => 'Mot de passe', 'type' => 'secret'],
            ],
            'adresse' => [
                ['cle' => 'sujet', 'libelle' => 'Sujet', 'type' => 'texte', 'requis' => true, 'aide' => 'Ex. larka/eau/70B3D5E75E000101/up'],
                ['cle' => 'chemin', 'libelle' => 'Chemin de la valeur dans le message', 'type' => 'texte',
                 'aide' => 'Ex. index_m3 ou data.temperature. Vide : le message entier est la valeur.'],
                ['cle' => 'filtre_chemin', 'libelle' => 'Filtrer sur le champ', 'type' => 'texte', 'aide' => 'Facultatif, ex. devEUI.'],
                ['cle' => 'filtre_valeur', 'libelle' => 'Valeur attendue', 'type' => 'texte'],
                ['cle' => 'horodatage_chemin', 'libelle' => 'Chemin de l\'horodatage', 'type' => 'texte',
                 'aide' => 'Facultatif, ex. horodatage. Vide : heure de réception.'],
                ['cle' => 'sujet_ecriture', 'libelle' => 'Sujet d\'écriture', 'type' => 'texte'],
                ['cle' => 'gabarit', 'libelle' => 'Message écrit', 'type' => 'texte', 'aide' => '{valeur} est remplacé, ex. {"consigne": {valeur}}'],
            ],
            'ecriture' => true,
            'decouverte' => true,
        ];
    }

    public function fermer(): void
    {
        if ($this->flux) {
            @fwrite($this->flux, "\xE0\x00");
            @fclose($this->flux);
            $this->flux = null;
        }
    }

    // ══ Codage ═════════════════════════════════════════════════════════════

    private static function longueur(int $n): string
    {
        $o = '';
        do {
            $b = $n % 128; $n = intdiv($n, 128);
            if ($n > 0) $b |= 0x80;
            $o .= chr($b);
        } while ($n > 0);
        return $o;
    }

    private static function chaine(string $s): string
    {
        return pack('n', strlen($s)) . $s;
    }

    private function envoyer(int $type, string $corps): bool
    {
        if (!$this->flux) return false;
        $p = chr($type) . self::longueur(strlen($corps)) . $corps;
        return @fwrite($this->flux, $p) === strlen($p);
    }

    /** Lit un paquet complet ; rend [type, drapeaux, corps] ou null (délai). */
    private function recevoir(float $echeance): ?array
    {
        $e = self::lireExact($this->flux, 1, $echeance);
        if ($e === null) return null;
        $mult = 1; $len = 0;
        for ($i = 0; $i < 4; $i++) {
            $b = self::lireExact($this->flux, 1, $echeance);
            if ($b === null) return null;
            $len += (ord($b) & 0x7F) * $mult;
            if ((ord($b) & 0x80) === 0) break;
            $mult *= 128;
        }
        $corps = $len > 0 ? self::lireExact($this->flux, $len, $echeance + 2) : '';
        if ($corps === null) return null;
        return [ord($e) >> 4, ord($e) & 0x0F, $corps];
    }

    /** Un filtre MQTT (« + », « # ») correspond-il au sujet ? */
    public static function correspond(string $filtre, string $sujet): bool
    {
        if ($filtre === $sujet || $filtre === '#') return true;
        $f = explode('/', $filtre); $s = explode('/', $sujet);
        foreach ($f as $i => $seg) {
            if ($seg === '#') return true;
            if (!array_key_exists($i, $s)) return false;
            if ($seg !== '+' && $seg !== $s[$i]) return false;
        }
        return count($f) === count($s);
    }

    /**
     * Identifiant de session MQTT.
     *
     * Celui que Larka choisit est propre à l'INSTALLATION (nom de la machine et
     * dossier), à l'organisation et à la connexion : une recette et une
     * production branchées sur le même broker, avec les mêmes numéros de
     * connexion, se voleraient sinon leurs messages — le broker ne garde
     * qu'une session par identifiant. 23 caractères au plus (MQTT 3.1.1).
     *
     * Le suffixe (« -test ») n'est jamais rogné : sans lui, le test ouvrirait
     * une session propre sous l'identifiant de la collecte et effacerait les
     * messages que le broker garde pour elle.
     */
    private function identifiantClient(string $suffixe = ''): string
    {
        $id = trim((string)($this->c['Identifiant'] ?? ''));
        if ($id !== '') return $id . $suffixe;          // choisi par l'administrateur du broker : tel quel
        $inst = substr(sha1(php_uname('n') . '|' . dirname(__DIR__, 3)), 0, 4);
        $tenant = substr(preg_replace('/[^a-z0-9]/i', '', (string)($this->c['_tenant'] ?? 'default')) ?: 'x', 0, 8);
        $id = 'lk' . $inst . '-' . $tenant . '-' . (int)($this->c['Id'] ?? 0);
        return substr($id, 0, 23 - strlen($suffixe)) . $suffixe;
    }

    /**
     * Connexion + CONNACK.
     * @return array list<étape>, dernière étape en échec si la connexion n'aboutit pas
     */
    private function connecter(bool $persistante, string $suffixe = ''): array
    {
        $etapes = [];
        $tls = filter_var($this->param('tls', false), FILTER_VALIDATE_BOOLEAN);
        $verif = filter_var($this->param('verifier_certificat', true), FILTER_VALIDATE_BOOLEAN);
        [$f, $etape] = $this->ouvrirTcp($this->hote(), $this->port($tls ? 8883 : 1883), $tls ? 'tls' : 'tcp',
            $tls ? ['verify_peer' => $verif, 'verify_peer_name' => $verif, 'SNI_enabled' => true] : []);
        $etapes[] = $etape;
        if (!$f) return $etapes;
        $this->flux = $f;
        $user = $this->secretVal('utilisateur'); $mdp = $this->secretVal('mot_de_passe');
        $flags = ($persistante ? 0x00 : 0x02) | ($user !== '' ? 0x80 : 0) | ($mdp !== '' ? 0x40 : 0);
        $corps = self::chaine('MQTT') . "\x04" . chr($flags) . pack('n', 60) . self::chaine($this->identifiantClient($suffixe));
        if ($user !== '') $corps .= self::chaine($user);
        if ($mdp !== '') $corps .= self::chaine($mdp);
        $t0 = microtime(true);
        $this->envoyer(0x10, $corps);
        $rep = $this->recevoir(microtime(true) + $this->delaiReponse);
        if ($rep === null || $rep[0] !== 2) {
            $etapes[] = self::etape('protocole', false, 'Le port répond, mais pas en MQTT (aucun CONNACK).', self::ms($t0));
            $this->fermer();
            return $etapes;
        }
        $code = ord($rep[2][1] ?? "\xFF");
        $this->sessionPresente = (ord($rep[2][0] ?? "\0") & 1) ? 'oui' : 'non';
        $textes = [1 => 'version de protocole refusée', 2 => 'identifiant client refusé', 3 => 'service MQTT indisponible',
                   4 => 'identifiant ou mot de passe incorrect', 5 => 'connexion non autorisée'];
        if ($code !== 0) {
            $etape = in_array($code, [4, 5], true) ? 'droits' : 'protocole';
            $etapes[] = self::etape($etape, false, 'Broker MQTT : ' . ($textes[$code] ?? "refus (code $code)") . '.', self::ms($t0));
            @fclose($this->flux); $this->flux = null;
            return $etapes;
        }
        $etapes[] = self::etape('protocole', true, 'Broker MQTT joint' . ($persistante ? ' (session persistante' . ($this->sessionPresente === 'oui' ? ' retrouvée' : ' créée') . ')' : '') . '.', self::ms($t0));
        return $etapes;
    }

    /** Abonnements ; rend [filtres acceptés, filtres refusés]. */
    private function abonner(array $filtres): array
    {
        $filtres = array_values(array_unique(array_filter(array_map('trim', $filtres), fn($f) => $f !== '')));
        if (!$filtres) return [[], []];
        $this->paquetId = ($this->paquetId % 65535) + 1;
        $corps = pack('n', $this->paquetId);
        foreach ($filtres as $f) $corps .= self::chaine($f) . "\x01";
        $this->envoyer(0x82, $corps);
        $echeance = microtime(true) + $this->delaiReponse;
        $tampon = [];
        while (($p = $this->recevoir($echeance)) !== null) {
            if ($p[0] === 9) {
                $codes = substr($p[2], 2);
                $ok = []; $ko = [];
                foreach ($filtres as $i => $f) (ord($codes[$i] ?? "\x80") === 0x80) ? $ko[] = $f : $ok[] = $f;
                $this->enAttente = $tampon;
                return [$ok, $ko];
            }
            if ($p[0] === 3) $tampon[] = $p;     // message déjà remis avant le SUBACK
        }
        return [[], $filtres];
    }

    private array $enAttente = [];

    /**
     * Collecte les messages jusqu'au silence.
     * @return list<array{sujet: string, message: string, recu: string}>
     */
    private function ecouter(float $silence, float $budget, int $max = 5000): array
    {
        $messages = [];
        $fin = microtime(true) + $budget;
        $vus = [];
        $traiter = function (array $p) use (&$messages, &$vus) {
            [, $drap, $c] = $p;
            $qos = ($drap >> 1) & 3;
            $l = unpack('n', substr($c, 0, 2))[1];
            $sujet = substr($c, 2, $l);
            $pos = 2 + $l;
            if ($qos > 0) {
                $pid = substr($c, $pos, 2); $pos += 2;
                $this->envoyer($qos === 2 ? 0x50 : 0x40, $pid);    // PUBREC (QoS 2) ou PUBACK
            }
            $msg = (string)substr($c, $pos);
            $cle = sha1($sujet . "\0" . $msg);
            if (isset($vus[$cle])) return;                          // doublon (QoS 1) : déjà vu
            $vus[$cle] = true;
            $messages[] = ['sujet' => $sujet, 'message' => $msg, 'recu' => date('Y-m-d H:i:s')];
        };
        foreach ($this->enAttente as $p) $traiter($p);
        $this->enAttente = [];
        while (count($messages) < $max && microtime(true) < $fin) {
            $p = $this->recevoir(min($fin, microtime(true) + $silence));
            if ($p === null) break;
            if ($p[0] === 3) $traiter($p);
            elseif ($p[0] === 6) $this->envoyer(0x70, substr($p[2], 0, 2));   // PUBREL → PUBCOMP
        }
        return $messages;
    }

    /** Filtres à suivre : ceux de la connexion + les sujets des points. */
    private function filtres(array $points): array
    {
        $f = preg_split('/[\r\n,]+/', (string)$this->param('sujets', '')) ?: [];
        foreach ($points as $p) {
            $s = trim((string)($p['Adresse']['sujet'] ?? ''));
            if ($s !== '') $f[] = $s;
        }
        return $f;
    }

    /** Valeur et horodatage d'un message pour un point, ou null s'il ne le concerne pas. */
    public static function extraire(array $adresse, string $sujet, string $message, string $recu): ?array
    {
        $filtre = trim((string)($adresse['sujet'] ?? ''));
        if ($filtre === '' || !self::correspond($filtre, $sujet)) return null;
        $doc = json_decode($message, true);
        $fc = trim((string)($adresse['filtre_chemin'] ?? ''));
        if ($fc !== '') {
            if (!is_array($doc)) return null;
            if ((string)self::suivreChemin($doc, $fc) !== (string)($adresse['filtre_valeur'] ?? '')) return null;
        }
        $chemin = trim((string)($adresse['chemin'] ?? ''));
        $brut = ($chemin === '') ? (is_array($doc) ? null : (json_last_error() === JSON_ERROR_NONE ? $doc : $message))
                                 : (is_array($doc) ? self::sommeSiListe(self::suivreChemin($doc, $chemin)) : null);
        if ($brut === null && $chemin !== '') return null;
        $horo = $recu;
        $hc = trim((string)($adresse['horodatage_chemin'] ?? ''));
        if ($hc !== '' && is_array($doc)) {
            $h = self::suivreChemin($doc, $hc);
            if (is_numeric($h)) $horo = date('Y-m-d H:i:s', (int)((float)$h > 1e11 ? (float)$h / 1000 : (float)$h));
            elseif (is_string($h) && ($t = strtotime($h)) !== false) $horo = date('Y-m-d H:i:s', $t);
        }
        if (is_bool($brut)) $brut = $brut ? 1 : 0;
        $v = self::nombre(is_scalar($brut) ? $brut : null);
        $txt = ($v === null && is_scalar($brut)) ? (string)$brut : null;
        return ['valeur' => $v, 'texte' => $txt, 'horodatage' => $horo];
    }

    public function lire(array $points): array
    {
        $out = [];
        if (!$points) return $out;
        $persistante = filter_var($this->param('session_persistante', true), FILTER_VALIDATE_BOOLEAN);
        $etapes = $this->connecter($persistante);
        $derniere = end($etapes);
        if (!$this->flux || !$derniere['ok']) {
            foreach ($points as $p) $out[(int)$p['Id']] = self::enDefaut((string)$derniere['message']);
            return $out;
        }
        [$ok, $ko] = $this->abonner($this->filtres($points));
        $messages = $this->ecouter(max(0.3, (float)$this->param('attente_secondes', 1.5)), 12.0);
        $this->fermer();
        // Ordre chronologique : le dernier message donne la valeur courante.
        foreach ($points as $p) {
            $a = is_array($p['Adresse'] ?? null) ? $p['Adresse'] : [];
            $serie = [];
            foreach ($messages as $m) {
                $x = self::extraire($a, $m['sujet'], $m['message'], $m['recu']);
                if ($x !== null) $serie[] = $x;
            }
            if (!$serie) continue;              // aucun message pour ce point : rien de nouveau (ce n'est pas une panne)
            usort($serie, fn($x, $y) => strcmp($x['horodatage'], $y['horodatage']));
            $dernier = end($serie);
            $r = self::valeurLue($dernier['valeur'], $dernier['texte'], $dernier['valeur'] === null && $dernier['texte'] === null ? 'mauvaise' : 'bonne', $dernier['horodatage']);
            $r['serie'] = array_slice($serie, 0, -1);
            $out[(int)$p['Id']] = $r;
        }
        foreach ($points as $p) {
            $s = trim((string)($p['Adresse']['sujet'] ?? ''));
            if ($s !== '' && in_array($s, $ko, true)) $out[(int)$p['Id']] = self::enDefaut("abonnement refusé par le broker : $s");
        }
        return $out;
    }

    public function ecrire(array $point, $valeur): array
    {
        $a = is_array($point['Adresse'] ?? null) ? $point['Adresse'] : [];
        $sujet = trim((string)($a['sujet_ecriture'] ?? ''));
        if ($sujet === '' || str_contains($sujet, '+') || str_contains($sujet, '#')) {
            return ['ok' => false, 'message' => 'Sujet d\'écriture non renseigné (ou contenant un joker).'];
        }
        $v = self::nombre($valeur);
        if ($v === null) return ['ok' => false, 'message' => 'Valeur non numérique.'];
        $coef = (float)($point['Coefficient'] ?? 1) ?: 1.0;
        $brut = ($v - (float)($point['Decalage'] ?? 0)) / $coef;
        $txt = rtrim(rtrim(number_format($brut, 6, '.', ''), '0'), '.');
        $message = str_replace('{valeur}', $txt, (string)($a['gabarit'] ?? '') ?: '{valeur}');
        $etapes = $this->connecter(false, '-ecr');
        if (!$this->flux || !end($etapes)['ok']) return ['ok' => false, 'message' => (string)end($etapes)['message']];
        $this->paquetId = ($this->paquetId % 65535) + 1;
        $this->envoyer(0x32, self::chaine($sujet) . pack('n', $this->paquetId) . $message);
        $echeance = microtime(true) + $this->delaiReponse;
        $acquitte = false;
        while (($p = $this->recevoir($echeance)) !== null) {
            if ($p[0] === 4) { $acquitte = true; break; }
        }
        $this->fermer();
        return $acquitte ? ['ok' => true, 'message' => "Message publié sur $sujet."]
                         : ['ok' => false, 'message' => 'Le broker n\'a pas accusé réception de la publication.'];
    }

    public function tester(array $points = []): array
    {
        $etapes = $this->connecter(false, '-test');
        if (!$this->flux || !end($etapes)['ok']) return $etapes;
        // Sans sujet ni point : tout écouter (une ligne vide n'est pas un filtre).
        $filtres = array_values(array_filter(array_map('trim', $this->filtres($points)), fn($f) => $f !== '')) ?: ['#'];
        [$ok, $ko] = $this->abonner($filtres);
        if ($ko) {
            $etapes[] = self::etape('droits', false, 'Abonnement refusé par le broker : ' . implode(', ', $ko) . '.');
        } else {
            $etapes[] = self::etape('droits', true, 'Abonnement accepté : ' . implode(', ', array_slice($ok, 0, 4)) . (count($ok) > 4 ? '…' : '') . '.');
        }
        $msgs = $this->ecouter(3.0, 6.0, 500);
        $this->fermer();
        if (!$msgs) {
            $etapes[] = self::etape('donnees', null, 'Aucun message reçu en quelques secondes : les capteurs émettent peut-être '
                . 'moins souvent (LoRaWAN : toutes les 15 min à 1 h). Ce n\'est pas une erreur.');
        } else {
            $sujets = array_values(array_unique(array_column($msgs, 'sujet')));
            $etapes[] = self::etape('donnees', true, count($msgs) . ' message(s) reçu(s) sur ' . count($sujets) . ' sujet(s) : '
                . implode(', ', array_slice($sujets, 0, 3)) . (count($sujets) > 3 ? '…' : '') . '.');
        }
        return $etapes;
    }

    public function decouvrir(): array
    {
        $etapes = $this->connecter(false, '-decouverte');
        if (!$this->flux || !end($etapes)['ok']) return [];
        $filtres = preg_split('/[\r\n,]+/', (string)$this->param('sujets', '')) ?: [];
        $filtres = array_values(array_filter(array_map('trim', $filtres))) ?: ['#'];
        $this->abonner($filtres);
        $msgs = $this->ecouter(4.0, 8.0, 2000);
        $this->fermer();
        $props = [];
        $vus = [];
        foreach (array_reverse($msgs) as $m) {                     // le plus récent d'abord
            if (isset($vus[$m['sujet']])) continue;
            $vus[$m['sujet']] = true;
            $doc = json_decode($m['message'], true);
            $court = basename(str_replace('/up', '', $m['sujet']));
            if (!is_array($doc)) {
                $v = self::nombre($m['message']);
                if ($v !== null) $props[] = ['nom' => $court, 'code' => $m['sujet'], 'adresse' => ['sujet' => $m['sujet'], 'chemin' => ''],
                                             'unite' => '', 'nature' => 'mesure', 'valeur' => $v];
                continue;
            }
            $nomBase = (string)($doc['compteur'] ?? $doc['nom'] ?? $doc['name'] ?? $doc['deviceName'] ?? $court);
            foreach (self::feuilles($doc) as $chemin => $v) {
                $chemin = (string)$chemin;   // clé numérique (message en liste JSON) : PHP l'a rendue entière
                if (preg_match('/(fcnt|rssi|snr|frequency|port|devaddr)$/i', $chemin)) continue;
                $props[] = ['nom' => "$nomBase — $chemin", 'code' => $m['sujet'] . '#' . $chemin,
                            'adresse' => ['sujet' => $m['sujet'], 'chemin' => $chemin,
                                          'horodatage_chemin' => isset($doc['horodatage']) ? 'horodatage' : (isset($doc['time']) ? 'time' : '')],
                            'unite' => self::uniteDevinee($chemin), 'nature' => self::natureDevinee($chemin), 'valeur' => $v];
            }
        }
        return array_slice($props, 0, 200);
    }

    /** Champs numériques (ou booléens) d'un message, en chemins « a.b.c ». */
    private static function feuilles(array $doc, string $prefixe = '', int $prof = 0): array
    {
        $out = [];
        foreach ($doc as $k => $v) {
            $chemin = $prefixe === '' ? (string)$k : "$prefixe.$k";
            if (is_array($v) && $prof < 3) $out += self::feuilles($v, $chemin, $prof + 1);
            elseif (is_int($v) || is_float($v) || is_bool($v)) $out[$chemin] = is_bool($v) ? (int)$v : $v;
        }
        return $out;
    }

    private static function uniteDevinee(string $c): string
    {
        foreach (['_m3' => 'm³', 'm3' => 'm³', '_lh' => 'L/h', 'kwh' => 'kWh', '_kw' => 'kW', 'pct' => '%', 'temp' => '°C', 'humid' => '%HR'] as $k => $u) {
            if (str_contains(strtolower($c), $k)) return $u;
        }
        return '';
    }

    private static function natureDevinee(string $c): string
    {
        $c = strtolower($c);
        if (str_contains($c, 'alarme') || str_contains($c, 'alarm') || str_contains($c, 'fuite') || str_contains($c, 'leak')) return 'alarme';
        if (str_contains($c, 'index') || str_contains($c, 'total') || str_contains($c, 'kwh')) return 'compteur';
        return 'mesure';
    }
}

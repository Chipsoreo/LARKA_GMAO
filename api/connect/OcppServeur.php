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
 *  Larka Connect — service OCPP 1.6J (bornes de recharge)
 * ═══════════════════════════════════════════════════════════════════════════
 *
 *  En OCPP, la borne ouvre une connexion WebSocket vers le superviseur et la
 *  garde. Ce service est ce superviseur : un processus PHP permanent (aucune
 *  bibliothèque, stream_select), lancé par api/outils/connect-ocpp.php.
 *
 *  Il :
 *    - accepte les bornes déclarées dans Larka (et elles seules), à l'adresse
 *      ws://<serveur>:<port>/ocpp/<identifiant> — /ocpp/<organisation>/<id> en
 *      multi-tenant ; derrière nginx, wss://<domaine>/ocpp/… ;
 *    - vérifie le mot de passe de la borne quand il est défini (profil 1) ;
 *    - tient à jour les points de la borne (état, énergie, puissance, charge,
 *      défaut), créés d'eux-mêmes à la première connexion ;
 *    - range les sessions de recharge — y compris une fin de charge reçue en
 *      différé après une coupure réseau, datée de son heure réelle ;
 *    - ouvre une alarme (et une demande, si réglé) quand la borne passe en
 *      défaut, la ferme quand elle redevient disponible ;
 *    - exécute les commandes déposées par l'écran (ConnectCommandes) :
 *      arrêt de charge, mise hors service, redémarrage, déverrouillage.
 *
 *  Une borne inconnue est refusée (HTTP 404) et notée dans l'état du service :
 *  l'écran la montre, pour qu'on la déclare si elle est légitime.
 *
 *  Le même processus écoute aussi les passerelles LoRaWAN (UDP, voir
 *  LorawanServeur.php) : un seul service permanent à lancer et surveiller,
 *  quel que soit le nombre de protocoles « entrants ». Chacun s'active
 *  séparément dans la configuration ; la boucle tourne dès que l'un l'est.
 * ═══════════════════════════════════════════════════════════════════════════
 */

declare(strict_types=1);

require_once __DIR__ . '/LorawanServeur.php';

final class ConnectOcppServeur
{
    private const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
    private const MAX_MESSAGE = 1048576;
    private const MAX_CLIENTS = 500;
    private const STATUTS = ['Available' => 0, 'Preparing' => 1, 'Charging' => 2, 'SuspendedEVSE' => 3, 'SuspendedEV' => 4,
                             'Finishing' => 5, 'Reserved' => 6, 'Unavailable' => 7, 'Faulted' => 8];
    private const LIBELLES = ['Available' => 'Disponible', 'Preparing' => 'En préparation', 'Charging' => 'En charge',
                              'SuspendedEVSE' => 'Suspendue (borne)', 'SuspendedEV' => 'Suspendue (véhicule)', 'Finishing' => 'Fin de charge',
                              'Reserved' => 'Réservée', 'Unavailable' => 'Hors service', 'Faulted' => 'En défaut'];

    /** @var resource|null */
    private $serveur = null;
    private string $ecoute = '';
    /** @var array<int, array> clients indexés par (int)flux */
    private array $clients = [];
    /** [organisation][identifiant] => ['id' => passerelle, 'secret' => …, 'params' => …] */
    private array $index = [];
    private float $indexMaj = 0;
    /** Bases par organisation. @var array<string, Database> */
    private array $bases = [];
    private array $inconnues = [];
    private float $etatMaj = 0;
    private float $cmdMaj = 0;
    private float $cfgMaj = 0;
    private bool $actif = false;
    private bool $arret = false;
    private $journal;
    private ConnectLorawanServeur $lorawan;
    private float $lwMaj = 0;

    public function __construct($journal = null)
    {
        $this->journal = $journal ?? STDOUT;
        ConnectPilotes::charger();
        $this->lorawan = new ConnectLorawanServeur(fn(string $org, callable $f) => $this->avecBase($org, $f), fn(string $m) => $this->log($m));
    }

    private function log(string $msg): void
    {
        @fwrite($this->journal, '[' . date('Y-m-d H:i:s') . "] $msg\n");
    }

    public function arreter(): void
    {
        $this->arret = true;
    }

    // ══ Boucle principale ══════════════════════════════════════════════════

    public function tourner(?float $duree = null): void
    {
        $fin = $duree !== null ? microtime(true) + $duree : null;
        $this->log('Service Larka Connect démarré (OCPP, LoRaWAN — processus ' . getmypid() . ').');
        while (!$this->arret && ($fin === null || microtime(true) < $fin)) {
            if (microtime(true) - $this->cfgMaj > 10) $this->appliquerReglages();
            if (microtime(true) - $this->lwMaj > 10) { $this->lwMaj = microtime(true); $this->lorawan->appliquer(); }
            $udp = $this->lorawan->flux();
            $lecture = [];
            if ($this->serveur) {
                $lecture[] = $this->serveur;
                foreach ($this->clients as $c) $lecture[] = $c['flux'];
            }
            if ($udp) $lecture[] = $udp;
            if (!$lecture) {                                    // rien d'activé : on attend un changement de réglages
                if (microtime(true) - $this->etatMaj > 5) $this->publierEtat();
                usleep(500000);
                if (function_exists('pcntl_signal_dispatch')) pcntl_signal_dispatch();
                continue;
            }
            $w = null; $x = null;
            $n = @stream_select($lecture, $w, $x, 0, (int)($this->lorawan->delai(0.3) * 1_000_000));
            if ($n === false) { usleep(100000); continue; }
            foreach ($lecture as $f) {
                if ($udp && $f === $udp) { $this->lorawan->lire(); continue; }
                if ($f === $this->serveur) { $this->accepter(); continue; }
                $this->lireClient((int)$f);
            }
            $this->lorawan->tic();
            $t = microtime(true);
            foreach ($this->clients as $k => $c) {
                // Poignée de main trop lente, ou silence sans fin d'une connexion à moitié ouverte.
                if ($c['etat'] === 'poignee' && $t - $c['ouvert'] > 10) $this->fermerClient($k, 'poignée de main trop lente');
                elseif ($c['etat'] === 'ws' && $t - $c['vu'] > max(300, 4 * $c['battement'])) $this->fermerClient($k, 'silence prolongé');
                elseif ($c['etat'] === 'ws' && $t - $c['ping'] > 60) { $this->trame($k, '', 0x9); $this->clients[$k]['ping'] = $t; }
            }
            if ($this->serveur && $t - $this->cmdMaj > 1) { $this->cmdMaj = $t; $this->commandes(); }
            if ($t - $this->etatMaj > 5) $this->publierEtat();
            if (function_exists('pcntl_signal_dispatch')) pcntl_signal_dispatch();
        }
        foreach (array_keys($this->clients) as $k) $this->fermerClient($k, 'arrêt du service');
        if ($this->serveur) @fclose($this->serveur);
        $this->serveur = null;
        $this->actif = false;
        $this->lorawan->tic(true);
        $this->lorawan->fermer();
        $this->publierEtat(true);
        $this->log('Service Larka Connect arrêté.');
    }

    /** Relit config.json : ouvre, déplace ou ferme l'écoute. */
    private function appliquerReglages(): void
    {
        $this->cfgMaj = microtime(true);
        $cfg = self::lireConfig();
        $voulu = $cfg['actif'] ? $cfg['hote'] . ':' . $cfg['port'] : '';
        if ($voulu === $this->ecoute && ($this->serveur || $voulu === '')) return;
        if ($this->serveur) {
            foreach (array_keys($this->clients) as $k) $this->fermerClient($k, 'réglages modifiés');
            @fclose($this->serveur);
            $this->serveur = null;
            $this->log("Écoute $this->ecoute fermée.");
        }
        $this->ecoute = $voulu;
        $this->actif = $voulu !== '';
        if (!$this->actif) { $this->log('OCPP désactivé dans la configuration : en attente.'); return; }
        $h = str_contains($cfg['hote'], ':') ? '[' . $cfg['hote'] . ']' : $cfg['hote'];
        $s = @stream_socket_server("tcp://$h:{$cfg['port']}", $errno, $errstr);
        if (!$s) {
            $this->log("Impossible d'écouter sur $voulu : $errstr");
            $this->ecoute = '';
            $this->cfgMaj = microtime(true) + 20;           // nouvel essai dans 30 s
            return;
        }
        stream_set_blocking($s, false);
        $this->serveur = $s;
        $this->log("En écoute sur ws://$voulu/ocpp/<identifiant>");
    }

    /** Réglages OCPP relus à chaud depuis config.json. */
    public static function lireConfig(): array
    {
        $f = dirname(__DIR__, 2) . '/config.json';
        $brut = (string)@file_get_contents($f);
        if (str_starts_with($brut, "\xEF\xBB\xBF")) $brut = substr($brut, 3);
        $c = json_decode($brut, true);
        $cx = is_array($c['connect'] ?? null) ? $c['connect'] : [];
        $o = is_array($cx['ocpp'] ?? null) ? $cx['ocpp'] : [];
        return [
            'actif' => filter_var($cx['actif'] ?? false, FILTER_VALIDATE_BOOLEAN) && filter_var($o['actif'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'hote' => (string)($o['hote'] ?? '0.0.0.0') ?: '0.0.0.0',
            'port' => max(1, min(65535, (int)($o['port'] ?? 9000))),
        ];
    }

    // ══ Bornes déclarées ═══════════════════════════════════════════════════

    private function base(string $org): ?Database
    {
        if (isset($this->bases[$org])) return $this->bases[$org];
        foreach (Connect::organisations() as $o) {
            if ($o['cle'] !== $org) continue;
            try {
                $db = $o['base'] === null ? new Database() : new Database($o['base']);
                if (!Connect::actifPour($db)) return null;
                return $this->bases[$org] = $db;
            } catch (\Throwable $e) {
                $this->log("Base de « $org » injoignable : " . $e->getMessage());
                return null;
            }
        }
        return null;
    }

    /** Exécute $f avec la base de l'organisation, en rouvrant la connexion une fois si elle est tombée. */
    private function avecBase(string $org, callable $f)
    {
        for ($i = 0; $i < 2; $i++) {
            $db = $this->base($org);
            if (!$db) return null;
            // Le journal écrit dans la base de CETTE organisation le temps du traitement :
            // les évènements de supervision (états, refus) y sont consultables.
            if (class_exists('Journal')) { try { Journal::attacherBase($db->getPdo()); } catch (\Throwable $e) {} }
            try {
                return $f($db, new ConnectDepot($db));
            } catch (\PDOException $e) {
                unset($this->bases[$org]);
                if ($i === 1) { $this->log("Base de « $org » : " . $e->getMessage()); return null; }
            } catch (\Throwable $e) {
                $this->log("Erreur ($org) : " . $e->getMessage());
                return null;
            } finally {
                if (class_exists('Journal')) { try { Journal::detacherBase(); } catch (\Throwable $e) {} }
            }
        }
        return null;
    }

    private function rafraichirIndex(bool $force = false): void
    {
        if (!$force && microtime(true) - $this->indexMaj < 30) return;
        $this->indexMaj = microtime(true);
        $index = [];
        foreach (Connect::organisations() as $o) {
            $this->avecBase($o['cle'], function (Database $db) use (&$index, $o) {
                foreach ($db->fetchAll("SELECT Id, Identifiant, Parametres, Secret FROM ConnectPasserelles WHERE Protocole = 'ocpp' AND Actif = 1") as $p) {
                    $id = trim((string)$p['Identifiant']);
                    if ($id === '') continue;
                    $index[$o['cle']][$id] = ['id' => (int)$p['Id'], 'params' => json_decode((string)$p['Parametres'], true) ?: [],
                                              'secret' => Connect::dechiffrer($p['Secret'] ?? null)];
                }
            });
        }
        $this->index = $index;
    }

    /** [organisation, identifiant, fiche] d'après le chemin /ocpp/[org/]id, ou null. */
    private function resoudre(string $chemin): ?array
    {
        $chemin = rawurldecode(parse_url($chemin, PHP_URL_PATH) ?: '');
        if (!preg_match('#^/ocpp/(?:([^/]+)/)?([^/]+)/?$#', $chemin, $m)) return null;
        $org = $m[1] !== '' ? $m[1] : null;
        $id = $m[2];
        foreach ([false, true] as $force) {
            $this->rafraichirIndex($force);
            if ($org !== null && isset($this->index[$org][$id])) return [$org, $id, $this->index[$org][$id]];
            if ($org === null) {
                $trouve = [];
                foreach ($this->index as $o => $bornes) if (isset($bornes[$id])) $trouve[] = $o;
                if (count($trouve) === 1) return [$trouve[0], $id, $this->index[$trouve[0]][$id]];
                if (count($trouve) > 1) return null;          // ambigu : le chemin doit préciser l'organisation
            }
        }
        return null;
    }

    // ══ Connexions WebSocket ═══════════════════════════════════════════════

    private function accepter(): void
    {
        $f = @stream_socket_accept($this->serveur, 0, $pair);
        if (!$f) return;
        if (count($this->clients) >= self::MAX_CLIENTS) { @fclose($f); return; }
        stream_set_blocking($f, false);
        $t = microtime(true);
        $this->clients[(int)$f] = ['flux' => $f, 'ip' => preg_replace('/:\d+$/', '', (string)$pair), 'etat' => 'poignee', 'tampon' => '',
                                   'ouvert' => $t, 'vu' => $t, 'ping' => $t, 'battement' => 60, 'org' => null, 'id' => null, 'fiche' => null,
                                   'fragments' => '', 'attente' => [], 'contact' => 0.0, 'auth' => false, 'modele' => ''];
    }

    private function lireClient(int $k): void
    {
        if (!isset($this->clients[$k])) return;
        $donnees = @fread($this->clients[$k]['flux'], 65536);
        if ($donnees === false || ($donnees === '' && feof($this->clients[$k]['flux']))) { $this->fermerClient($k, 'connexion fermée par la borne'); return; }
        if ($donnees === '') return;
        $this->clients[$k]['tampon'] .= $donnees;
        if (strlen($this->clients[$k]['tampon']) > self::MAX_MESSAGE + 16) { $this->fermerClient($k, 'message trop long'); return; }
        if ($this->clients[$k]['etat'] === 'poignee') {
            $pos = strpos($this->clients[$k]['tampon'], "\r\n\r\n");
            if ($pos === false) {
                if (strlen($this->clients[$k]['tampon']) > 8192) $this->fermerClient($k, 'en-têtes trop longs');
                return;
            }
            $entete = substr($this->clients[$k]['tampon'], 0, $pos);
            $this->clients[$k]['tampon'] = (string)substr($this->clients[$k]['tampon'], $pos + 4);
            $this->poignee($k, $entete);
            if (!isset($this->clients[$k]) || $this->clients[$k]['etat'] !== 'ws') return;
        }
        $this->lireTrames($k);
    }

    private function repondreHttp(int $k, int $code, string $texte): void
    {
        @fwrite($this->clients[$k]['flux'], "HTTP/1.1 $code $texte\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
        $this->fermerClient($k, "HTTP $code", true);
    }

    private function poignee(int $k, string $entete): void
    {
        $lignes = explode("\r\n", $entete);
        if (!preg_match('#^GET\s+(\S+)\s+HTTP/1\.1$#', $lignes[0] ?? '', $m)) { $this->repondreHttp($k, 400, 'Bad Request'); return; }
        $chemin = $m[1];
        $h = [];
        foreach (array_slice($lignes, 1) as $l) {
            $p = strpos($l, ':');
            if ($p !== false) $h[strtolower(trim(substr($l, 0, $p)))] = trim(substr($l, $p + 1));
        }
        // Derrière le relais Nginx (wss://…/ocpp/, chiffré), l'adresse vue est
        // celle du relais : on affiche celle de la borne qu'il transmet. Pour
        // l'affichage et les journaux seulement — elle ne décide de rien.
        if (in_array(trim($this->clients[$k]['ip'], '[]'), ['127.0.0.1', '::1'], true)) {
            $relayee = trim(explode(',', (string)($h['x-real-ip'] ?? $h['x-forwarded-for'] ?? ''))[0]);
            if ($relayee !== '' && filter_var($relayee, FILTER_VALIDATE_IP)) $this->clients[$k]['ip'] = $relayee . ' (via relais)';
        }
        $ip = $this->clients[$k]['ip'];
        if (stripos($h['upgrade'] ?? '', 'websocket') === false || empty($h['sec-websocket-key'])) {
            $this->repondreHttp($k, 426, 'Upgrade Required');
            return;
        }
        $r = $this->resoudre($chemin);
        if ($r === null) {
            $this->log("Borne inconnue refusée : $chemin depuis $ip");
            array_unshift($this->inconnues, ['chemin' => mb_substr($chemin, 0, 120), 'ip' => $ip, 'quand' => date('Y-m-d H:i:s')]);
            $this->inconnues = array_slice($this->inconnues, 0, 20);
            $this->etatMaj = 0.0;
            $this->repondreHttp($k, 404, 'Not Found');
            return;
        }
        [$org, $id, $fiche] = $r;
        $mdp = (string)($fiche['secret']['mot_de_passe'] ?? '');
        $auth = false;
        if ($mdp !== '') {
            $ok = false;
            if (preg_match('/^Basic\s+(.+)$/i', $h['authorization'] ?? '', $mb)) {
                $paire = (string)base64_decode($mb[1], true);
                $sep = strpos($paire, ':');
                if ($sep !== false) $ok = hash_equals($id, substr($paire, 0, $sep)) & hash_equals($mdp, substr($paire, $sep + 1));
            }
            if (!$ok) {
                $this->log("Borne « $id » ($org) : mot de passe invalide depuis $ip");
                @fwrite($this->clients[$k]['flux'], "HTTP/1.1 401 Unauthorized\r\nWWW-Authenticate: Basic realm=\"OCPP\"\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
                $this->fermerClient($k, 'authentification refusée', true);
                return;
            }
            $auth = true;
        }
        $protos = array_map('trim', explode(',', strtolower($h['sec-websocket-protocol'] ?? '')));
        $accepte = base64_encode(sha1($h['sec-websocket-key'] . self::GUID, true));
        $rep = "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: $accepte\r\n";
        if (in_array('ocpp1.6', $protos, true)) $rep .= "Sec-WebSocket-Protocol: ocpp1.6\r\n";
        @fwrite($this->clients[$k]['flux'], $rep . "\r\n");
        // Une borne qui se reconnecte remplace son ancienne connexion.
        foreach ($this->clients as $k2 => $c2) {
            if ($k2 !== $k && $c2['org'] === $org && $c2['id'] === $id) $this->fermerClient($k2, 'remplacée par une nouvelle connexion');
        }
        $this->clients[$k] = array_merge($this->clients[$k], ['etat' => 'ws', 'org' => $org, 'id' => $id, 'fiche' => $fiche, 'auth' => $auth,
            'battement' => max(10, (int)($fiche['params']['intervalle_battement'] ?? 60)), 'depuis' => date('Y-m-d H:i:s')]);
        $this->log("Borne « $id » ($org) connectée depuis $ip" . ($auth ? ' (authentifiée)' : ''));
        $this->etatMaj = 0.0;
        $this->contact($k, true);
        $this->pointValeur($k, 'ocpp.connexion', 1, 'Connectée');
    }

    private function lireTrames(int $k): void
    {
        while (isset($this->clients[$k])) {
            $b = $this->clients[$k]['tampon'];
            if (strlen($b) < 2) return;
            $o1 = ord($b[0]); $o2 = ord($b[1]);
            $fin = (bool)($o1 & 0x80); $op = $o1 & 0x0F; $masque = (bool)($o2 & 0x80); $len = $o2 & 0x7F; $pos = 2;
            if ($len === 126) { if (strlen($b) < 4) return; $len = unpack('n', substr($b, 2, 2))[1]; $pos = 4; }
            elseif ($len === 127) { if (strlen($b) < 10) return; $len = (int)unpack('J', substr($b, 2, 8))[1]; $pos = 10; }
            if ($len > self::MAX_MESSAGE) { $this->fermerClient($k, 'trame trop longue'); return; }
            if (!$masque) { $this->fermerClient($k, 'trame non masquée (RFC 6455)'); return; }
            if (strlen($b) < $pos + 4 + $len) return;
            $cle = substr($b, $pos, 4);
            $charge = substr($b, $pos + 4, $len);
            $this->clients[$k]['tampon'] = (string)substr($b, $pos + 4 + $len);
            $clair = '';
            for ($i = 0; $i < $len; $i++) $clair .= $charge[$i] ^ $cle[$i % 4];
            $this->clients[$k]['vu'] = microtime(true);
            if ($op === 0x8) { $this->trame($k, substr($clair, 0, 2), 0x8); $this->fermerClient($k, 'fermeture demandée par la borne'); return; }
            if ($op === 0x9) { $this->trame($k, $clair, 0xA); continue; }
            if ($op === 0xA) continue;
            if ($op === 0x1 || $op === 0x0) {
                $this->clients[$k]['fragments'] .= $clair;
                if (!$fin) continue;
                $msg = $this->clients[$k]['fragments'];
                $this->clients[$k]['fragments'] = '';
                $this->message($k, $msg);
            }
        }
    }

    private function trame(int $k, string $donnees, int $op = 0x1): void
    {
        if (!isset($this->clients[$k])) return;
        $l = strlen($donnees);
        $t = chr(0x80 | $op) . ($l < 126 ? chr($l) : ($l < 65536 ? chr(126) . pack('n', $l) : chr(127) . pack('J', $l))) . $donnees;
        $f = $this->clients[$k]['flux'];
        stream_set_blocking($f, true);
        @fwrite($f, $t);
        stream_set_blocking($f, false);
    }

    private function envoyer(int $k, array $msg): void
    {
        $this->trame($k, json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function fermerClient(int $k, string $raison, bool $silencieux = false): void
    {
        if (!isset($this->clients[$k])) return;
        $c = $this->clients[$k];
        @fclose($c['flux']);
        unset($this->clients[$k]);
        if ($c['etat'] === 'ws' && $c['id'] !== null) {
            $this->etatMaj = 0.0;                                  // l'écran le saura au prochain tour
            $this->log("Borne « {$c['id']} » ({$c['org']}) déconnectée : $raison");
            $this->avecBase($c['org'], function (Database $db, ConnectDepot $d) use ($c) {
                $pt = $d->pointParCode((int)$c['fiche']['id'], 'ocpp.connexion');
                if ($pt) $d->enregistrerLectures([(int)$pt['Id'] => $pt], [(int)$pt['Id'] => ['valeur' => 0, 'texte' => 'Déconnectée', 'qualite' => 'bonne']]);
                // Commandes en vol : la borne ne répondra plus.
                foreach ($c['attente'] as $uid => $cmd) {
                    $db->getPdo()->prepare("UPDATE ConnectCommandes SET Statut = 'echec', DateExecution = ?, Reponse = ? WHERE Id = ?")
                       ->execute([date('Y-m-d H:i:s'), 'borne déconnectée avant de répondre', (int)$cmd['commande']]);
                }
            });
        } elseif (!$silencieux && $c['etat'] === 'poignee') {
            $this->log("Connexion de {$c['ip']} fermée : $raison");
        }
    }

    // ══ Messages OCPP ══════════════════════════════════════════════════════

    private static function horodatage($iso): string
    {
        $t = is_string($iso) && $iso !== '' ? strtotime($iso) : false;
        return date('Y-m-d H:i:s', $t !== false ? $t : time());
    }

    private static function maintenantIso(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    private function message(int $k, string $texte): void
    {
        $m = json_decode($texte, true);
        if (!is_array($m) || !isset($m[0], $m[1]) || !is_string($m[1])) {
            $this->log("Message illisible de « {$this->clients[$k]['id']} » : " . mb_substr($texte, 0, 120));
            return;
        }
        $this->contact($k);
        $type = (int)$m[0];
        if ($type === 2) {
            $action = (string)($m[2] ?? '');
            $charge = is_array($m[3] ?? null) ? $m[3] : [];
            try {
                $res = $this->action($k, $action, $charge);
            } catch (\Throwable $e) {
                $this->log("Erreur sur $action de « {$this->clients[$k]['id']} » : " . $e->getMessage());
                $this->envoyer($k, [4, $m[1], 'InternalError', 'Erreur du superviseur', (object)[]]);
                return;
            }
            if ($res === null) $this->envoyer($k, [4, $m[1], 'NotImplemented', "Action $action non prise en charge", (object)[]]);
            else $this->envoyer($k, [3, $m[1], (object)$res]);
            return;
        }
        if ($type === 3 || $type === 4) {
            $att = $this->clients[$k]['attente'][$m[1]] ?? null;
            if (!$att) return;
            unset($this->clients[$k]['attente'][$m[1]]);
            $ok = $type === 3 && in_array((string)(($m[2]['status'] ?? 'Accepted')), ['Accepted', 'Unlocked', 'Scheduled'], true);
            $reponse = $type === 3 ? 'Réponse de la borne : ' . (string)($m[2]['status'] ?? 'Accepted')
                                   : 'Erreur de la borne : ' . (string)($m[2] ?? '') . ' ' . (string)($m[3] ?? '');
            $this->avecBase($this->clients[$k]['org'], function (Database $db) use ($att, $ok, $reponse) {
                $db->getPdo()->prepare("UPDATE ConnectCommandes SET Statut = ?, DateExecution = ?, Reponse = ? WHERE Id = ?")
                   ->execute([$ok ? 'reussie' : 'echec', date('Y-m-d H:i:s'), mb_substr($reponse, 0, 300), (int)$att['commande']]);
            });
            $this->log("Borne « {$this->clients[$k]['id']} » : {$att['action']} → " . ($ok ? 'acceptée' : 'refusée') . " ($reponse)");
        }
    }

    /** Dernier contact noté en base (au plus toutes les 15 s). */
    private function contact(int $k, bool $force = false): void
    {
        $c = $this->clients[$k] ?? null;
        if (!$c || $c['id'] === null) return;
        if (!$force && microtime(true) - $c['contact'] < 15) return;
        $this->clients[$k]['contact'] = microtime(true);
        $this->avecBase($c['org'], function (Database $db) use ($c) {
            $db->getPdo()->prepare("UPDATE ConnectPasserelles SET DernierContact = ?, Etat = CASE WHEN Etat = 'erreur' OR Etat = 'inconnu' THEN 'ok' ELSE Etat END,
                                           DerniereErreur = NULL, EchecsConsecutifs = 0 WHERE Id = ?")
               ->execute([date('Y-m-d H:i:s'), (int)$c['fiche']['id']]);
        });
        // Une borne qui parle à nouveau termine son alarme « connexion ».
        $this->avecBase($c['org'], function (Database $db, ConnectDepot $d) use ($c) {
            $al = new ConnectAlarmes($d, (string)$c['org']);
            $pa = $db->fetchOne("SELECT * FROM ConnectPasserelles WHERE Id = :id", ['id' => (int)$c['fiche']['id']]);
            if ($pa) $al->connexion($pa, false, '', 0);
        });
    }

    /** Badge accepté ? */
    private function badge(array $fiche, string $idTag): string
    {
        if (($fiche['params']['badges'] ?? 'tous') !== 'liste') return 'Accepted';
        foreach (preg_split('/\r\n|\n|\r/', (string)($fiche['params']['liste_badges'] ?? '')) ?: [] as $l) {
            $tag = trim(explode(';', $l)[0]);
            if ($tag !== '' && strcasecmp($tag, $idTag) === 0) return 'Accepted';
        }
        return 'Invalid';
    }

    private function action(int $k, string $action, array $p): ?array
    {
        $c = $this->clients[$k];
        $pid = (int)$c['fiche']['id'];
        switch ($action) {
            case 'BootNotification':
                $modele = trim(($p['chargePointVendor'] ?? '') . ' ' . ($p['chargePointModel'] ?? ''));
                $this->clients[$k]['modele'] = mb_substr($modele, 0, 80);
                $this->log("Borne « {$c['id']} » : BootNotification $modele, micrologiciel " . ($p['firmwareVersion'] ?? '?'));
                $this->avecBase($c['org'], function (Database $db) use ($pid, $p, $modele) {
                    $desc = $modele . (!empty($p['chargePointSerialNumber']) ? ' — n° ' . $p['chargePointSerialNumber'] : '')
                          . (!empty($p['firmwareVersion']) ? ' — micrologiciel ' . $p['firmwareVersion'] : '');
                    $db->getPdo()->prepare("UPDATE ConnectPasserelles SET Description = CASE WHEN Description IS NULL OR Description = '' THEN ? ELSE Description END WHERE Id = ?")
                       ->execute([mb_substr($desc, 0, 300), $pid]);
                });
                return ['status' => 'Accepted', 'currentTime' => self::maintenantIso(), 'interval' => $c['battement']];
            case 'Heartbeat':
                return ['currentTime' => self::maintenantIso()];
            case 'StatusNotification':
                $this->statut($k, $p);
                return [];
            case 'Authorize':
                $st = $this->badge($c['fiche'], (string)($p['idTag'] ?? ''));
                $this->log("Borne « {$c['id']} » : badge " . ($p['idTag'] ?? '?') . ' → ' . ($st === 'Accepted' ? 'accepté' : 'refusé'));
                return ['idTagInfo' => ['status' => $st]];
            case 'StartTransaction':
                return $this->debutCharge($k, $p);
            case 'MeterValues':
                $this->releves($k, $p);
                return [];
            case 'StopTransaction':
                $this->finCharge($k, $p);
                return ['idTagInfo' => ['status' => 'Accepted']];
            case 'DataTransfer':
                return ['status' => 'UnknownVendorId'];
            case 'DiagnosticsStatusNotification':
            case 'FirmwareStatusNotification':
                return [];
        }
        return null;
    }

    /** Point d'une borne, créé à la première valeur. */
    private function point(ConnectDepot $d, int $pid, string $code): array
    {
        $pt = $d->pointParCode($pid, $code);
        if ($pt) return $pt;
        $def = [
            'ocpp.connexion' => ['Connexion de la borne', 'etat', ''],
            'ocpp.energie' => ['Énergie délivrée (index)', 'compteur', 'kWh'],
            'ocpp.puissance' => ['Puissance de charge', 'mesure', 'kW'],
            'ocpp.soc' => ['Charge de la batterie', 'mesure', '%'],
            'ocpp.defaut' => ['Défaut de la borne', 'alarme', ''],
        ][$code] ?? null;
        if ($def === null && preg_match('/^ocpp\.statut\.(\d+)$/', $code, $m)) $def = ['État du connecteur ' . $m[1], 'etat', ''];
        if ($def === null) $def = [$code, 'mesure', ''];
        [$v] = $d->normaliserPoint(['passerelleId' => $pid, 'nom' => $def[0], 'code' => $code, 'nature' => $def[1], 'unite' => $def[2],
                                    'decimales' => $def[2] === 'kWh' ? 2 : 1]);
        $d->creerPoint($v, Connect::LOGIN_TECHNIQUE);
        return $d->pointParCode($pid, $code) ?? [];
    }

    private function pointValeur(int $k, string $code, ?float $valeur, ?string $texte, ?string $horo = null, string $qualite = 'bonne'): void
    {
        $c = $this->clients[$k] ?? null;
        if (!$c) return;
        $this->avecBase($c['org'], function (Database $db, ConnectDepot $d) use ($c, $code, $valeur, $texte, $horo, $qualite) {
            $pt = $this->point($d, (int)$c['fiche']['id'], $code);
            if (!$pt) return;
            $d->enregistrerLectures([(int)$pt['Id'] => $pt], [(int)$pt['Id'] => ['valeur' => $valeur, 'texte' => $texte, 'qualite' => $qualite,
                                                                                'horodatage' => $horo]]);
        });
    }

    private function statut(int $k, array $p): void
    {
        $c = $this->clients[$k];
        $con = (int)($p['connectorId'] ?? 0);
        $statut = (string)($p['status'] ?? '');
        $err = (string)($p['errorCode'] ?? 'NoError');
        $horo = self::horodatage($p['timestamp'] ?? null);
        $libelle = self::LIBELLES[$statut] ?? $statut;
        $this->pointValeur($k, 'ocpp.statut.' . max(1, $con), (float)(self::STATUTS[$statut] ?? -1), $libelle, $horo);
        $enDefaut = $statut === 'Faulted' || ($err !== 'NoError' && $err !== '');
        $this->pointValeur($k, 'ocpp.defaut', $enDefaut ? 1.0 : 0.0, $enDefaut ? $err : 'Aucun', $horo);
        $this->log("Borne « {$c['id']} » connecteur $con : $statut" . ($err !== 'NoError' ? " ($err)" : ''));
        $this->avecBase($c['org'], function (Database $db, ConnectDepot $d) use ($c, $con, $statut, $err, $enDefaut, $p) {
            // Connecteur libre alors qu'une charge y est encore « en cours » : sa fin s'est perdue.
            if ($statut === 'Available' && $con > 0) self::clorePerdues($db->getPdo(), (int)$c['fiche']['id'], $con, 'connecteur redevenu libre sans fin de charge reçue');
            $al = new ConnectAlarmes($d, (string)$c['org']);
            $pa = $db->fetchOne("SELECT * FROM ConnectPasserelles WHERE Id = :id", ['id' => (int)$c['fiche']['id']]);
            if (!$pa) return;
            $code = "connecteur $con:";
            $actives = $db->fetchAll("SELECT Id, Code FROM ConnectAlarmes WHERE Origine = 'equipement' AND PasserelleId = :p AND Etat = 'active'",
                                     ['p' => (int)$pa['Id']]);
            if (!$enDefaut) {
                foreach ($actives as $a) if (str_starts_with((string)$a['Code'], $code)) $al->fermer((int)$a['Id']);
                return;
            }
            foreach ($actives as $a) if ((string)$a['Code'] === $code . $err) return;     // déjà ouverte
            $info = trim((string)($p['info'] ?? '') . ' ' . (string)($p['vendorErrorCode'] ?? ''));
            $al->ouvrir([
                'origine' => 'equipement', 'passerelle' => (int)$pa['Id'], 'code' => $code . $err, 'gravite' => 'majeure',
                'message' => "Borne « {$pa['Nom']} » en défaut : " . self::defautLisible($err) . ($con ? " (connecteur $con)" : '') . ($info !== '' ? " — $info" : ''),
                'demande' => filter_var($c['fiche']['params']['defaut_vers_demande'] ?? true, FILTER_VALIDATE_BOOLEAN), 'notifier' => true,
                'detail' => "Défaut OCPP « $err » signalé par la borne « {$c['id']} ».",
            ]);
        });
    }

    private static function defautLisible(string $code): string
    {
        return [
            'ConnectorLockFailure' => 'verrouillage du connecteur', 'EVCommunicationError' => 'communication avec le véhicule',
            'GroundFailure' => 'défaut de terre (différentiel)', 'HighTemperature' => 'température trop élevée', 'InternalError' => 'erreur interne',
            'LocalListConflict' => 'liste de badges incohérente', 'OtherError' => 'autre défaut', 'OverCurrentFailure' => 'surintensité',
            'OverVoltage' => 'surtension', 'PowerMeterFailure' => 'compteur d\'énergie', 'PowerSwitchFailure' => 'contacteur de puissance',
            'ReaderFailure' => 'lecteur de badge', 'ResetFailure' => 'redémarrage impossible', 'UnderVoltage' => 'sous-tension',
            'WeakSignal' => 'signal réseau faible',
        ][$code] ?? $code;
    }

    private function debutCharge(int $k, array $p): array
    {
        $c = $this->clients[$k];
        $badge = (string)($p['idTag'] ?? '');
        $st = $this->badge($c['fiche'], $badge);
        $tid = $this->avecBase($c['org'], function (Database $db) use ($c, $p, $badge, $st) {
            $pdo = $db->getPdo();
            // Une charge encore « en cours » sur ce connecteur n'a jamais reçu sa fin
            // (borne redémarrée, message perdu) : elle est close comme interrompue.
            // Si la fin arrive plus tard (file d'attente de la borne), elle la complète.
            self::clorePerdues($pdo, (int)$c['fiche']['id'], (int)($p['connectorId'] ?? 1), 'nouvelle charge démarrée sans fin de la précédente');
            $pdo->prepare("INSERT INTO ConnectSessionsVE (PasserelleId, Connecteur, Badge, Debut, IndexDebutWh, Statut, Motif)
                           VALUES (?, ?, ?, ?, ?, ?, ?)")
                ->execute([(int)$c['fiche']['id'], (int)($p['connectorId'] ?? 1), mb_substr($badge, 0, 40), self::horodatage($p['timestamp'] ?? null),
                           (float)($p['meterStart'] ?? 0), $st === 'Accepted' ? 'en_cours' : 'refusee', $st === 'Accepted' ? null : 'badge refusé']);
            $id = (int)$pdo->lastInsertId();
            $pdo->prepare("UPDATE ConnectSessionsVE SET TransactionId = ? WHERE Id = ?")->execute([$id, $id]);
            return $id;
        }) ?? (int)(microtime(true) * 10) % 2147483647;
        $this->pointValeur($k, 'ocpp.energie', (float)($p['meterStart'] ?? 0) / 1000, null, self::horodatage($p['timestamp'] ?? null));
        $this->log("Borne « {$c['id']} » : début de charge n° $tid (badge $badge, index " . round((float)($p['meterStart'] ?? 0) / 1000, 2) . ' kWh)');
        return ['transactionId' => $tid, 'idTagInfo' => ['status' => $st]];
    }

    /** Ferme les charges restées « en cours » sur un connecteur (fin jamais reçue). */
    private static function clorePerdues(\PDO $pdo, int $passerelleId, int $connecteur, string $raison): void
    {
        $pdo->prepare("UPDATE ConnectSessionsVE SET Statut = 'interrompue', Motif = ? WHERE PasserelleId = ? AND Connecteur = ? AND Statut = 'en_cours'")
            ->execute(['interrompue : ' . $raison, $passerelleId, $connecteur]);
    }

    private function releves(int $k, array $p): void
    {
        $c = $this->clients[$k];
        $tid = isset($p['transactionId']) ? (int)$p['transactionId'] : null;
        foreach ((array)($p['meterValue'] ?? []) as $mv) {
            $horo = self::horodatage($mv['timestamp'] ?? null);
            foreach ((array)($mv['sampledValue'] ?? []) as $sv) {
                if (!empty($sv['phase'])) continue;                        // on garde les totaux
                $mesure = (string)($sv['measurand'] ?? 'Energy.Active.Import.Register');
                $unite = (string)($sv['unit'] ?? '');
                $v = ConnectPiloteBase::nombre($sv['value'] ?? null);
                if ($v === null) continue;
                if ($mesure === 'Energy.Active.Import.Register') {
                    $kwh = $unite === 'kWh' ? $v : $v / 1000;
                    $this->pointValeur($k, 'ocpp.energie', $kwh, null, $horo);
                    if ($tid) $this->avecBase($c['org'], function (Database $db) use ($tid, $v, $unite, $c) {
                        $db->getPdo()->prepare("UPDATE ConnectSessionsVE SET IndexFinWh = ?, EnergieWh = ? - IndexDebutWh
                                                 WHERE Id = ? AND PasserelleId = ? AND Statut = 'en_cours'")
                           ->execute([$unite === 'kWh' ? $v * 1000 : $v, $unite === 'kWh' ? $v * 1000 : $v, $tid, (int)$c['fiche']['id']]);
                    });
                } elseif ($mesure === 'Power.Active.Import') {
                    $kw = $unite === 'kW' ? $v : $v / 1000;
                    $this->pointValeur($k, 'ocpp.puissance', $kw, null, $horo);
                    if ($tid) $this->avecBase($c['org'], function (Database $db) use ($tid, $kw, $c) {
                        $db->getPdo()->prepare("UPDATE ConnectSessionsVE SET PuissanceMaxW = CASE WHEN PuissanceMaxW IS NULL OR PuissanceMaxW < ? THEN ? ELSE PuissanceMaxW END
                                                 WHERE Id = ? AND PasserelleId = ?")
                           ->execute([$kw * 1000, $kw * 1000, $tid, (int)$c['fiche']['id']]);
                    });
                } elseif ($mesure === 'SoC') {
                    $this->pointValeur($k, 'ocpp.soc', $v, null, $horo);
                }
            }
        }
    }

    private function finCharge(int $k, array $p): void
    {
        $c = $this->clients[$k];
        $tid = (int)($p['transactionId'] ?? 0);
        $horo = self::horodatage($p['timestamp'] ?? null);
        $differe = (time() - (int)strtotime($horo)) > 120;
        $stop = (float)($p['meterStop'] ?? 0);
        $this->avecBase($c['org'], function (Database $db) use ($c, $tid, $horo, $differe, $stop, $p) {
            $pdo = $db->getPdo();
            $s = $db->fetchOne("SELECT * FROM ConnectSessionsVE WHERE Id = :id AND PasserelleId = :p", ['id' => $tid, 'p' => (int)$c['fiche']['id']]);
            if (!$s) {
                // Transaction ouverte avant que Larka ne la connaisse (borne déjà en charge à la mise en service).
                $pdo->prepare("INSERT INTO ConnectSessionsVE (PasserelleId, TransactionId, Badge, Debut, Fin, IndexFinWh, Statut, Motif, RecuEnDiffere)
                               VALUES (?, ?, ?, NULL, ?, ?, 'terminee', ?, ?)")
                    ->execute([(int)$c['fiche']['id'], $tid, mb_substr((string)($p['idTag'] ?? ''), 0, 40), $horo, $stop,
                               'fin reçue sans début connu' . (!empty($p['reason']) ? ' — ' . $p['reason'] : ''), $differe ? 1 : 0]);
                return;
            }
            $pdo->prepare("UPDATE ConnectSessionsVE SET Fin = ?, IndexFinWh = ?, EnergieWh = ? - COALESCE(IndexDebutWh, 0), Motif = ?,
                                  Statut = 'terminee', RecuEnDiffere = ? WHERE Id = ?")
                ->execute([$horo, $stop, $stop, mb_substr((string)($p['reason'] ?? 'Local'), 0, 60), $differe ? 1 : 0, $tid]);
        });
        $this->pointValeur($k, 'ocpp.energie', $stop / 1000, null, $horo);
        $this->pointValeur($k, 'ocpp.puissance', 0.0, null, date('Y-m-d H:i:s'));
        $this->log("Borne « {$c['id']} » : fin de charge n° $tid" . ($differe ? " (reçue en différé, datée du " . date('d/m H:i', (int)strtotime($horo)) . ')' : '')
            . ', motif ' . ($p['reason'] ?? 'Local'));
    }

    // ══ Commandes de l'écran ═══════════════════════════════════════════════

    private function commandes(): void
    {
        $parBorne = [];
        foreach ($this->clients as $k => $c) if ($c['etat'] === 'ws') $parBorne[$c['org']][(int)$c['fiche']['id']] = $k;
        $orgs = array_unique(array_merge(array_keys($parBorne), array_keys($this->index)));
        foreach ($orgs as $org) {
            $this->avecBase($org, function (Database $db) use ($org, $parBorne) {
                $pdo = $db->getPdo();
                $rows = $db->fetchAll("SELECT c.*, pa.Protocole FROM ConnectCommandes c JOIN ConnectPasserelles pa ON pa.Id = c.PasserelleId
                                        WHERE c.Statut IN ('en_attente', 'envoyee') AND pa.Protocole = 'ocpp' ORDER BY c.Id");
                foreach ($rows as $cmd) {
                    $age = time() - (int)strtotime((string)$cmd['DateDemande']);
                    $k = $parBorne[$org][(int)$cmd['PasserelleId']] ?? null;
                    if ($cmd['Statut'] === 'envoyee') {
                        if ($age > 40) $pdo->prepare("UPDATE ConnectCommandes SET Statut = 'expiree', DateExecution = ?, Reponse = ? WHERE Id = ?")
                                           ->execute([date('Y-m-d H:i:s'), 'la borne n\'a pas répondu à temps', (int)$cmd['Id']]);
                        continue;
                    }
                    if ($k === null) {
                        if ($age > 30) $pdo->prepare("UPDATE ConnectCommandes SET Statut = 'echec', DateExecution = ?, Reponse = ? WHERE Id = ?")
                                           ->execute([date('Y-m-d H:i:s'), 'borne non connectée', (int)$cmd['Id']]);
                        continue;
                    }
                    [$action, $charge, $erreur] = $this->traduireCommande($db, $cmd);
                    if ($erreur !== null) {
                        $pdo->prepare("UPDATE ConnectCommandes SET Statut = 'echec', DateExecution = ?, Reponse = ? WHERE Id = ?")
                            ->execute([date('Y-m-d H:i:s'), $erreur, (int)$cmd['Id']]);
                        continue;
                    }
                    $uid = bin2hex(random_bytes(8));
                    $this->clients[$k]['attente'][$uid] = ['commande' => (int)$cmd['Id'], 'action' => $action, 'quand' => time()];
                    $this->envoyer($k, [2, $uid, $action, (object)$charge]);
                    $pdo->prepare("UPDATE ConnectCommandes SET Statut = 'envoyee' WHERE Id = ?")->execute([(int)$cmd['Id']]);
                    $this->log("Borne « {$this->clients[$k]['id']} » : commande $action envoyée (demandée par {$cmd['Demandeur']})");
                }
            });
        }
    }

    /** Commande Larka → [action OCPP, charge, erreur]. */
    private function traduireCommande(Database $db, array $cmd): array
    {
        [$nom, $arg] = explode(':', (string)$cmd['Commande'], 2) + [1 => null];
        switch ($nom) {
            case 'RemoteStopTransaction':
                $s = $db->fetchOne("SELECT TransactionId FROM ConnectSessionsVE WHERE PasserelleId = :p AND Statut = 'en_cours' ORDER BY Id DESC LIMIT 1",
                                   ['p' => (int)$cmd['PasserelleId']]);
                if (!$s) return [null, null, 'aucune charge en cours sur cette borne'];
                return ['RemoteStopTransaction', ['transactionId' => (int)$s['TransactionId']], null];
            case 'RemoteStartTransaction':
                $tag = trim((string)$cmd['Valeur']);
                if ($tag === '') return [null, null, 'badge à utiliser non renseigné'];
                return ['RemoteStartTransaction', ['idTag' => mb_substr($tag, 0, 20), 'connectorId' => 1], null];
            case 'ChangeAvailability':
                return ['ChangeAvailability', ['connectorId' => 0, 'type' => $arg === 'Operative' ? 'Operative' : 'Inoperative'], null];
            case 'Reset':
                return ['Reset', ['type' => $arg === 'Hard' ? 'Hard' : 'Soft'], null];
            case 'UnlockConnector':
                return ['UnlockConnector', ['connectorId' => max(1, (int)($cmd['Valeur'] ?: 1))], null];
            case 'TriggerMessage':
                return ['TriggerMessage', ['requestedMessage' => in_array($arg, ['StatusNotification', 'MeterValues', 'Heartbeat', 'BootNotification'], true) ? $arg : 'StatusNotification'], null];
        }
        return [null, null, 'commande inconnue'];
    }

    // ══ État publié pour l'écran ═══════════════════════════════════════════

    public static function fichierEtat(): string
    {
        return dirname(__DIR__, 2) . '/data/run/connect-ocpp.json';
    }

    private function publierEtat(bool $arret = false): void
    {
        $this->etatMaj = microtime(true);
        $bornes = [];
        foreach ($this->clients as $c) {
            if ($c['etat'] !== 'ws') continue;
            $bornes[$c['org']][$c['id']] = ['connecte' => true, 'depuis' => $c['depuis'] ?? date('Y-m-d H:i:s'), 'ip' => $c['ip'],
                                            'modele' => $c['modele'], 'auth' => $c['auth'], 'dernier_message' => date('Y-m-d H:i:s', (int)$c['vu'])];
        }
        $e = ['pid' => getmypid(), 'maj' => date('Y-m-d H:i:s'), 'actif' => $this->actif && !$arret, 'ecoute' => $this->ecoute,
              'bornes' => $bornes, 'inconnues' => $this->inconnues];
        @mkdir(dirname(self::fichierEtat()), 0775, true);
        $tmp = self::fichierEtat() . '.' . getmypid();
        if (@file_put_contents($tmp, json_encode($e, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) !== false) {
            @rename($tmp, self::fichierEtat());
        }
    }
}

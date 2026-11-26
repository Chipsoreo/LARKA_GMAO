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
 *  Larka Connect — écoute des passerelles LoRaWAN (Semtech UDP, port 1700)
 * ═══════════════════════════════════════════════════════════════════════════
 *
 *  Tourne dans le service permanent de Larka Connect (le même processus que le
 *  service OCPP, api/outils/connect-ocpp.php) : un seul programme à surveiller.
 *
 *  Une passerelle LoRaWAN « packet forwarder » envoie en UDP chaque trame radio
 *  entendue (PUSH_DATA) et se signale régulièrement (PULL_DATA) pour qu'on
 *  puisse lui confier une trame à émettre (PULL_RESP). Ce service :
 *
 *    1. répond aux passerelles (PUSH_ACK, PULL_ACK) ;
 *    2. attend 200 ms qu'une même trame arrive par toutes les passerelles qui
 *       l'ont entendue, et garde la meilleure réception (doublons écartés) ;
 *    3. reconnaît le capteur (DevEUI au join, DevAddr ensuite), vérifie le MIC
 *       avec SES clés, refuse un compteur de trames déjà vu (rejeu) ;
 *    4. répond au join (OTAA) et accuse les trames confirmées ;
 *    5. déchiffre, décode, et range les valeurs dans les points de la
 *       connexion — règles d'alarme, historique, relevés : comme partout.
 *
 *  Sécurité :
 *    - une trame dont le MIC ne correspond à aucun capteur déclaré est ignorée :
 *      sans les clés, personne ne peut injecter de valeur ;
 *    - par défaut, seules les passerelles d'un réseau privé (ou de la machine)
 *      sont écoutées ; une passerelle sur Internet doit être nommée dans la
 *      configuration (connect.lorawan.passerelles, liste d'EUI) ;
 *    - les clés de session sont chiffrées en base, comme les mots de passe des
 *      connexions ; elles ne figurent jamais dans le fichier d'état.
 * ═══════════════════════════════════════════════════════════════════════════
 */

declare(strict_types=1);

final class ConnectLorawanServeur
{
    /** Attente des copies d'une trame reçue par plusieurs passerelles. */
    private const FENETRE_DOUBLONS = 0.2;
    /** Délais LoRaWAN (EU868, valeurs par défaut de la spécification). */
    private const DELAI_JOIN = 5_000_000;          // µs : JOIN_ACCEPT_DELAY1
    private const DELAI_RX1 = 1_000_000;           // µs : RECEIVE_DELAY1 (RxDelay = 1)
    private const RX2_FREQ = 869.525;
    private const RX2_DATR = 'SF12BW125';
    private const MAX_INCONNUS = 30;

    /** @var resource|null */
    private $flux = null;
    private string $ecoute = '';
    private array $cfg = ['actif' => false];
    /** @var callable(string, callable): mixed */
    private $avecBase;
    /** @var callable(string): void */
    private $log;

    /** EUI => [ip, port, vu, pull (adresse de réponse) , version, trames] */
    private array $passerelles = [];
    /** Trames en attente de leurs copies : clé => [t, trame, receptions[]] */
    private array $attente = [];
    /** Empreintes récentes (copies tardives, 5 s) : clé => t */
    private array $vues = [];
    /** Capteurs déclarés : [eui][org] => fiche ; [addr] => [[org, pid], …] */
    private array $parEui = [];
    private array $parAddr = [];
    private float $indexMaj = 0;
    private float $indexForce = 0;
    /** Sessions : "org|pid" => ligne ConnectLorawan (clés en clair, en mémoire seulement) */
    private array $sessions = [];
    /** Ce que l'écran montre : [org][eui] => dernière trame, radio, champs */
    private array $appareils = [];
    private array $inconnus = [];
    private float $etatMaj = 0;
    private int $recues = 0;

    public function __construct(callable $avecBase, callable $log)
    {
        $this->avecBase = $avecBase;
        $this->log = $log;
    }

    // ══ Réglages, écoute ═══════════════════════════════════════════════════

    /** Réglages relus à chaud depuis config.json (même lecture que le service OCPP). */
    public static function lireConfig(): array
    {
        $f = dirname(__DIR__, 2) . '/config.json';
        $brut = (string)@file_get_contents($f);
        if (str_starts_with($brut, "\xEF\xBB\xBF")) $brut = substr($brut, 3);
        $c = json_decode($brut, true);
        $cx = is_array($c['connect'] ?? null) ? $c['connect'] : [];
        $l = is_array($cx['lorawan'] ?? null) ? $cx['lorawan'] : [];
        $gw = $l['passerelles'] ?? [];
        if (is_string($gw)) $gw = preg_split('/[\s,;]+/', $gw) ?: [];
        $gw = array_values(array_filter(array_map(fn($e) => ConnectLorawan::hex((string)$e, 8), (array)$gw)));
        return [
            'actif' => filter_var($cx['actif'] ?? false, FILTER_VALIDATE_BOOLEAN) && filter_var($l['actif'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'hote' => (string)($l['hote'] ?? '0.0.0.0') ?: '0.0.0.0',
            'port' => max(1, min(65535, (int)($l['port'] ?? 1700))),
            'passerelles' => $gw,
            'puissance' => max(2, min(27, (int)($l['puissance'] ?? 14))),
        ];
    }

    public function appliquer(): void
    {
        $cfg = self::lireConfig();
        $this->cfg = $cfg;
        $voulu = $cfg['actif'] ? $cfg['hote'] . ':' . $cfg['port'] : '';
        if ($voulu === $this->ecoute && ($this->flux || $voulu === '')) return;
        if ($this->flux) {
            @fclose($this->flux);
            $this->flux = null;
            ($this->log)("LoRaWAN : écoute $this->ecoute fermée.");
        }
        $this->ecoute = $voulu;
        if ($voulu === '') return;
        $h = str_contains($cfg['hote'], ':') ? '[' . $cfg['hote'] . ']' : $cfg['hote'];
        $s = @stream_socket_server("udp://$h:{$cfg['port']}", $errno, $errstr, STREAM_SERVER_BIND);
        if (!$s) {
            ($this->log)("LoRaWAN : impossible d'écouter sur $voulu (UDP) : $errstr");
            $this->ecoute = '';
            return;
        }
        stream_set_blocking($s, false);
        $this->flux = $s;
        ($this->log)("LoRaWAN : en écoute sur udp://$voulu (packet forwarder Semtech).");
    }

    /** @return resource|null */
    public function flux()
    {
        return $this->flux;
    }

    public function actif(): bool
    {
        return $this->flux !== null;
    }

    /** Délai d'attente conseillé à la boucle (secondes) : court tant qu'une trame attend ses copies. */
    public function delai(float $defaut): float
    {
        return $this->attente ? min($defaut, 0.05) : $defaut;
    }

    public function fermer(): void
    {
        if ($this->flux) @fclose($this->flux);
        $this->flux = null;
        $this->ecoute = '';
        $this->publierEtat(true);
    }

    // ══ Réception ══════════════════════════════════════════════════════════

    /** Vide la file UDP (appelé quand stream_select signale le flux). */
    public function lire(): void
    {
        if (!$this->flux) return;
        for ($i = 0; $i < 200; $i++) {
            $p = @stream_socket_recvfrom($this->flux, 65535, 0, $pair);
            if ($p === false || $p === '') break;
            $this->paquet($p, (string)$pair);
        }
    }

    /** Un paquet UDP, tel que reçu ; public pour les épreuves. */
    public function paquet(string $p, string $pair): void
    {
        $u = ConnectLorawan::paquetUdp($p);
        if (!$u || !$u['eui']) return;
        $ip = preg_replace('/:\d+$/', '', $pair);
        $ip = trim((string)$ip, '[]');
        if (!$this->passerelleAdmise($u['eui'], $ip)) return;
        $t = microtime(true);
        $g = &$this->passerelles[$u['eui']];
        $g = ($g ?? ['trames' => 0, 'pull' => null]) + ['ip' => $ip];
        $g['ip'] = $ip; $g['vu'] = $t; $g['version'] = $u['version'];
        switch ($u['type']) {
            case 0x00:                                                          // PUSH_DATA
                $this->envoyer(chr($u['version']) . $u['jeton'] . "\x01", $pair);
                foreach ((array)($u['json']['rxpk'] ?? []) as $rx) {
                    if (!is_array($rx) || (int)($rx['stat'] ?? 1) !== 1 || ($rx['modu'] ?? 'LORA') !== 'LORA') continue;
                    $trame = base64_decode((string)($rx['data'] ?? ''), true);
                    if ($trame === false || $trame === '') continue;
                    $g['trames']++;
                    $this->recues++;
                    $this->recevoir($trame, ['eui' => $u['eui'], 't' => $t, 'tmst' => (int)($rx['tmst'] ?? 0),
                        'freq' => (float)($rx['freq'] ?? 868.1), 'datr' => (string)($rx['datr'] ?? 'SF7BW125'),
                        'rssi' => (int)($rx['rssi'] ?? $rx['rsig'][0]['rssic'] ?? -120), 'lsnr' => (float)($rx['lsnr'] ?? $rx['rsig'][0]['lsnr'] ?? 0)]);
                }
                if (isset($u['json']['stat']) && is_array($u['json']['stat'])) {
                    $s = $u['json']['stat'];
                    $g['stat'] = array_intersect_key($s, array_flip(['rxnb', 'rxok', 'rxfw', 'txnb', 'lati', 'long']));
                }
                break;
            case 0x02:                                                          // PULL_DATA
                $g['pull'] = $pair;
                $this->envoyer(chr($u['version']) . $u['jeton'] . "\x04", $pair);
                break;
            case 0x05:                                                          // TX_ACK
                $err = (string)($u['json']['txpk_ack']['error'] ?? 'NONE');
                if ($err !== 'NONE' && $err !== '') {
                    $g['erreur_emission'] = $err;
                    ($this->log)("LoRaWAN : la passerelle {$u['eui']} n'a pas pu émettre ($err).");
                }
                break;
        }
        unset($g);
    }

    /** Passerelle écoutée ? Réseau privé ou machine ; Internet seulement si nommée. */
    private function passerelleAdmise(string $eui, string $ip): bool
    {
        $liste = (array)($this->cfg['passerelles'] ?? []);
        if ($liste && !in_array($eui, $liste, true)) return false;
        if ($liste) return true;
        return ConnectDepot::adresseHotspot($ip);
    }

    private function recevoir(string $trame, array $rx): void
    {
        $cle = hash('sha1', $trame);
        if (isset($this->vues[$cle])) return;                                   // copie tardive d'une trame déjà traitée
        if (isset($this->attente[$cle])) { $this->attente[$cle]['receptions'][] = $rx; return; }
        if (count($this->attente) > 500) return;                               // rafale anormale : on ne s'y noie pas
        $this->attente[$cle] = ['t' => $rx['t'], 'trame' => $trame, 'receptions' => [$rx]];
    }

    /** Travail périodique : trames mûres, ménage, état publié. */
    public function tic(bool $forcer = false): void
    {
        $t = microtime(true);
        foreach ($this->attente as $cle => $a) {
            if (!$forcer && $t - $a['t'] < self::FENETRE_DOUBLONS) continue;
            unset($this->attente[$cle]);
            $this->vues[$cle] = $t;
            usort($a['receptions'], fn($x, $y) => [$y['lsnr'] > 0 ? 0 : $y['lsnr'], $y['rssi']] <=> [$x['lsnr'] > 0 ? 0 : $x['lsnr'], $x['rssi']]);
            try {
                $this->traiter($a['trame'], $a['receptions']);
            } catch (\Throwable $e) {
                ($this->log)('LoRaWAN : trame non traitée : ' . $e->getMessage());
            }
        }
        // Les copies d'une trame arrivent en moins d'une seconde ; au-delà, une trame
        // identique est un rejeu, que les compteurs (ou les DevNonce) refusent.
        foreach ($this->vues as $k => $v) if ($t - $v > 5) unset($this->vues[$k]);
        if ($forcer || $t - $this->etatMaj > 5) $this->publierEtat();
    }

    // ══ Capteurs déclarés ══════════════════════════════════════════════════

    private function index(bool $force = false): void
    {
        $t = microtime(true);
        if (!$force && $t - $this->indexMaj < 30) return;
        if ($force && $t - $this->indexForce < 5) return;                      // trames inconnues en rafale : pas de base martelée
        if ($force) $this->indexForce = $t;
        $this->indexMaj = $t;
        $parEui = []; $parAddr = [];
        foreach (Connect::organisations() as $o) {
            ($this->avecBase)($o['cle'], function (Database $db) use (&$parEui, &$parAddr, $o) {
                $sessions = [];
                foreach ($db->fetchAll("SELECT PasserelleId, DevAddr FROM ConnectLorawan") as $s) $sessions[(int)$s['PasserelleId']] = (string)$s['DevAddr'];
                foreach ($db->fetchAll("SELECT * FROM ConnectPasserelles WHERE Protocole = 'lorawan' AND Actif = 1") as $p) {
                    $eui = ConnectLorawan::hex((string)$p['Identifiant'], 8);
                    if (!$eui) continue;
                    $params = json_decode((string)($p['Parametres'] ?? ''), true) ?: [];
                    $fiche = ['org' => $o['cle'], 'id' => (int)$p['Id'], 'eui' => $eui, 'nom' => (string)$p['Nom'], 'params' => $params,
                              'secret' => Connect::dechiffrer($p['Secret'] ?? null)];
                    $parEui[$eui][$o['cle']] = $fiche;
                    $addr = ($params['activation'] ?? 'otaa') === 'abp' ? ConnectLorawan::hex((string)($params['dev_addr'] ?? ''), 4)
                                                                         : ($sessions[(int)$p['Id']] ?? null);
                    if ($addr) $parAddr[$addr][] = $fiche;
                }
            });
        }
        $this->parEui = $parEui;
        $this->parAddr = $parAddr;
    }

    /** Session d'un capteur (clés en clair) ; créée vide au besoin. */
    private function session(array $f): array
    {
        $k = $f['org'] . '|' . $f['id'];
        if (isset($this->sessions[$k])) return $this->sessions[$k];
        $r = ($this->avecBase)($f['org'], fn(Database $db) => $db->fetchOne("SELECT * FROM ConnectLorawan WHERE PasserelleId = :id", ['id' => $f['id']]));
        $s = ['DevAddr' => null, 'FCntUp' => null, 'FCntDown' => 0, 'DevNonces' => [], 'cles' => [], 'NbTrames' => 0, 'existe' => false];
        if (is_array($r)) {
            $s = ['DevAddr' => $r['DevAddr'] ?: null, 'FCntUp' => $r['FCntUp'] === null ? null : (int)$r['FCntUp'],
                  'FCntDown' => (int)($r['FCntDown'] ?? 0), 'DevNonces' => json_decode((string)($r['DevNonces'] ?? ''), true) ?: [],
                  'cles' => Connect::dechiffrer($r['Session'] ?? null), 'NbTrames' => (int)($r['NbTrames'] ?? 0), 'existe' => true];
        }
        return $this->sessions[$k] = $s;
    }

    private function enregistrerSession(array $f, array $s, array $radio = []): void
    {
        $this->sessions[$f['org'] . '|' . $f['id']] = $s + ['existe' => true];
        ($this->avecBase)($f['org'], function (Database $db) use ($f, $s, $radio) {
            $v = ['id' => $f['id'], 'a' => $s['DevAddr'], 'u' => $s['FCntUp'], 'd' => $s['FCntDown'],
                  'n' => json_encode(array_slice($s['DevNonces'], -64)), 's' => $s['cles'] ? Connect::chiffrer($s['cles']) : null,
                  'j' => $s['RejointLe'] ?? null, 'dt' => $radio['quand'] ?? null, 'r' => $radio['rssi'] ?? null, 'sn' => $radio['snr'] ?? null,
                  'g' => $radio['passerelle'] ?? null, 'c' => $radio['charge'] ?? null, 'nb' => $s['NbTrames']];
            $existe = $db->fetchOne("SELECT PasserelleId FROM ConnectLorawan WHERE PasserelleId = :id", ['id' => $f['id']]);
            if ($existe) {
                $db->execute("UPDATE ConnectLorawan SET DevAddr = :a, FCntUp = :u, FCntDown = :d, DevNonces = :n, Session = :s,
                                     RejointLe = COALESCE(:j, RejointLe), DerniereTrame = COALESCE(:dt, DerniereTrame), Rssi = COALESCE(:r, Rssi),
                                     Snr = COALESCE(:sn, Snr), PasserelleRadio = COALESCE(:g, PasserelleRadio), Charge = COALESCE(:c, Charge),
                                     NbTrames = :nb WHERE PasserelleId = :id", $v);
            } else {
                $db->execute("INSERT INTO ConnectLorawan (PasserelleId, DevAddr, FCntUp, FCntDown, DevNonces, Session, RejointLe, DerniereTrame,
                                     Rssi, Snr, PasserelleRadio, Charge, NbTrames)
                              VALUES (:id, :a, :u, :d, :n, :s, :j, :dt, :r, :sn, :g, :c, :nb)", $v);
            }
        });
    }

    // ══ Traitement d'une trame ═════════════════════════════════════════════

    private function traiter(string $trame, array $receptions): void
    {
        $a = ConnectLorawan::analyser($trame);
        if (!$a) return;
        if ($a['mtype'] === ConnectLorawan::MTYPE_JOIN_REQUEST) { $this->join($a, $receptions); return; }
        if (!isset($a['dev_addr'])) return;                                      // trame descendante ou propriétaire : pas pour nous
        $this->donnees($a, $receptions);
    }

    private function join(array $a, array $rx): void
    {
        $this->index();
        $fiches = $this->parEui[$a['dev_eui']] ?? null;
        if (!$fiches) { $this->index(true); $fiches = $this->parEui[$a['dev_eui']] ?? null; }
        if (!$fiches) { $this->inconnu($a['dev_eui'], 'join', $a['join_eui'], $rx[0]); return; }
        foreach ($fiches as $f) {
            if (($f['params']['activation'] ?? 'otaa') !== 'otaa') { $this->rejet($f, 'demande de join, mais la connexion est réglée en ABP.'); continue; }
            $hex = ConnectLorawan::hex((string)($f['secret']['app_key'] ?? ''), 16);
            if (!$hex) { $this->rejet($f, 'demande de join, mais l\'AppKey n\'est pas renseignée.'); continue; }
            $appKey = hex2bin($hex);
            if (!hash_equals(substr(ConnectLorawan::cmac($appKey, $a['msg']), 0, 4), $a['mic'])) {
                $this->rejet($f, 'demande de join refusée : l\'AppKey ne correspond pas (MIC).');
                continue;
            }
            $joinEui = ConnectLorawan::hex((string)($f['params']['join_eui'] ?? ''), 8);
            if ($joinEui && $joinEui !== $a['join_eui']) { $this->rejet($f, "demande de join avec le JoinEUI {$a['join_eui']}, attendu $joinEui."); return; }
            $s = $this->session($f);
            $nonce = bin2hex($a['dev_nonce']);
            if (in_array($nonce, $s['DevNonces'], true)) { $this->rejet($f, 'demande de join rejouée (DevNonce déjà utilisé).'); return; }
            $g = $this->passerelleEmission($rx);
            if (!$g) { $this->rejet($f, 'demande de join reçue, mais aucune passerelle ne peut répondre (PULL_DATA jamais reçu).'); return; }
            $addr = $s['DevAddr'] ?: $this->nouvelleAdresse();
            $appNonce = random_bytes(3);
            $netId = "\0\0\0";
            $addrLe = strrev(hex2bin($addr));
            $cles = ConnectLorawan::clesSession($appKey, $appNonce, $netId, $a['dev_nonce']);
            $ja = ConnectLorawan::joinAccept($appKey, $appNonce, $netId, $addrLe, 0x00, 1);
            $this->emettre($g, $ja, self::DELAI_JOIN, $rx[0]);
            $s['DevAddr'] = $addr; $s['FCntUp'] = null; $s['FCntDown'] = 0;
            $s['DevNonces'][] = $nonce;
            $s['cles'] = ['nwk' => bin2hex($cles['nwk']), 'app' => bin2hex($cles['app'])];
            $s['RejointLe'] = date('Y-m-d H:i:s');
            $this->enregistrerSession($f, $s, ['quand' => date('Y-m-d H:i:s'), 'rssi' => $rx[0]['rssi'], 'snr' => $rx[0]['lsnr'], 'passerelle' => $rx[0]['eui']]);
            // Le DevAddr attribué compte dès maintenant (sans attendre la relecture de l'index).
            $this->parAddr[$addr] = array_values(array_filter($this->parAddr[$addr] ?? [], fn($x) => $x['org'] !== $f['org'] || $x['id'] !== $f['id']));
            $this->parAddr[$addr][] = $f;
            $this->noterAppareil($f, ['join' => date('Y-m-d H:i:s'), 'dev_addr' => $addr, 'rejet' => null], $rx[0]);
            ($this->log)("LoRaWAN : capteur {$a['dev_eui']} ({$f['nom']}) activé, DevAddr $addr.");
            return;
        }
    }

    /** DevAddr libre : NetID 000000 (réseau privé), 25 bits tirés au hasard. */
    private function nouvelleAdresse(): string
    {
        for ($i = 0; $i < 50; $i++) {
            $a = sprintf('%08X', random_int(1, 0x01FFFFFF));
            if (!isset($this->parAddr[$a])) return $a;
        }
        return sprintf('%08X', random_int(1, 0x01FFFFFF));
    }

    private function donnees(array $a, array $rx): void
    {
        $this->index();
        $cands = $this->parAddr[$a['dev_addr']] ?? null;
        if (!$cands) { $this->index(true); $cands = $this->parAddr[$a['dev_addr']] ?? null; }
        if (!$cands) { $this->inconnu($a['dev_addr'], 'addr', null, $rx[0]); return; }
        foreach ($cands as $f) {
            $abp = ($f['params']['activation'] ?? 'otaa') === 'abp';
            $s = $this->session($f);
            if ($abp) {
                $nwk = ConnectLorawan::hex((string)($f['secret']['nwk_s_key'] ?? ''), 16);
                $app = ConnectLorawan::hex((string)($f['secret']['app_s_key'] ?? ''), 16);
                // Des clés modifiées dans l'écran : nouveau départ des compteurs.
                $empreinte = substr(hash('sha256', $nwk . $app . $a['dev_addr']), 0, 16);
                if (($s['cles']['empreinte'] ?? '') !== $empreinte) {
                    $s['FCntUp'] = null; $s['FCntDown'] = 0; $s['DevAddr'] = $a['dev_addr'];
                }
                $s['cles'] = ['nwk' => $nwk, 'app' => $app, 'empreinte' => $empreinte];
            }
            if (empty($s['cles']['nwk']) || empty($s['cles']['app'])) continue;
            $nwkKey = hex2bin($s['cles']['nwk']);
            $fcnt = ConnectLorawan::fcnt32($a['fcnt16'], $s['FCntUp']);
            $mic = ConnectLorawan::micDonnees($nwkKey, $a['dev_addr_le'], $fcnt, 0, $a['msg']);
            if (!hash_equals($mic, $a['mic'])) {
                // Compteur remis à zéro (ABP, capteur redémarré) : le MIC se calcule sur les 16 bits seuls.
                if ($abp && $s['FCntUp'] !== null && hash_equals(ConnectLorawan::micDonnees($nwkKey, $a['dev_addr_le'], $a['fcnt16'], 0, $a['msg']), $a['mic'])) {
                    $fcnt = $a['fcnt16'];
                } else {
                    continue;                                                   // pas ce capteur (DevAddr partagé) ou trame altérée
                }
            }
            if ($s['FCntUp'] !== null && $fcnt <= $s['FCntUp']) {
                $reprise = $abp && !empty($f['params']['remise_a_zero'] ?? true) && $fcnt < 16 && $s['FCntUp'] >= 16;
                if ($fcnt === $s['FCntUp'] && $a['confirmee']) {               // répétition d'une trame confirmée : on accuse encore
                    $this->accuser($f, $s, $a, $rx);
                    return;
                }
                if (!$reprise) { $this->rejet($f, "trame n° $fcnt refusée : compteur déjà vu (n° {$s['FCntUp']}). Rejeu, ou capteur redémarré."); return; }
                ($this->log)("LoRaWAN : {$f['nom']} recompte depuis $fcnt (capteur redémarré).");
            }
            $s['FCntUp'] = $fcnt;
            $s['NbTrames']++;
            $charge = '';
            if ($a['fport'] !== null && $a['charge'] !== '') {
                $cle = hex2bin($a['fport'] === 0 ? $s['cles']['nwk'] : $s['cles']['app']);
                $charge = ConnectLorawan::chiffrerCharge($cle, $a['dev_addr_le'], $fcnt, 0, $a['charge']);
            }
            if ($a['confirmee']) $this->accuser($f, $s, $a, $rx);
            $quand = date('Y-m-d H:i:s');
            $this->enregistrerSession($f, $s, ['quand' => $quand, 'rssi' => $rx[0]['rssi'], 'snr' => $rx[0]['lsnr'], 'passerelle' => $rx[0]['eui'],
                                               'charge' => $a['fport'] ? bin2hex($charge) : null]);
            if (!$a['fport']) return;                                           // commandes MAC seulement : rien à ranger
            $decodeur = (string)($f['params']['decodeur'] ?? 'lpp');
            $champs = $decodeur === 'octets' ? [] : ConnectLorawan::decoder($decodeur, $charge);
            $trame = ['champs' => $champs, 'charge' => $charge, 'rssi' => $rx[0]['rssi'], 'snr' => $rx[0]['lsnr'], 'fcnt' => $fcnt,
                      'sf' => preg_match('/SF(\d+)/', $rx[0]['datr'], $m) ? (int)$m[1] : null];
            $this->noterAppareil($f, ['derniere' => $quand, 'fcnt' => $fcnt, 'fport' => $a['fport'], 'champs' => $champs,
                                      'octets' => bin2hex($charge), 'rejet' => null, 'dev_addr' => $a['dev_addr']], $rx[0], count($rx));
            $this->ranger($f, $trame, $quand);
            return;
        }
        // Aucun capteur de ce DevAddr n'a les bonnes clés.
        foreach ($cands as $f) $this->rejet($f, 'trame reçue avec ce DevAddr, mais le MIC ne correspond pas : clés différentes, ou trame altérée.');
    }

    /** Accusé de réception d'une trame confirmée, en RX1 si on est à l'heure, sinon en RX2. */
    private function accuser(array $f, array &$s, array $a, array $rx): void
    {
        $g = $this->passerelleEmission($rx);
        if (!$g) return;
        $msg = ConnectLorawan::accuse(hex2bin($s['cles']['nwk']), $a['dev_addr_le'], $s['FCntDown']);
        $s['FCntDown']++;
        $enRetard = microtime(true) - $rx[0]['t'] > 0.6;
        $this->emettre($g, $msg, $enRetard ? self::DELAI_RX1 + 1_000_000 : self::DELAI_RX1, $rx[0], $enRetard);
    }

    /** Passerelle par laquelle répondre : la meilleure réception parmi celles qui ont fait PULL_DATA. */
    private function passerelleEmission(array $rx): ?array
    {
        foreach ($rx as $r) {
            $g = $this->passerelles[$r['eui']] ?? null;
            if ($g && !empty($g['pull'])) return $g + ['eui' => $r['eui']];
        }
        return null;
    }

    private function emettre(array $g, string $trame, int $delaiUs, array $rx, bool $rx2 = false): void
    {
        $p = ConnectLorawan::pullResp((int)($g['version'] ?? 2), $trame, $rx['tmst'] + $delaiUs,
                                      $rx2 ? self::RX2_FREQ : $rx['freq'], $rx2 ? self::RX2_DATR : $rx['datr'], (int)($this->cfg['puissance'] ?? 14));
        $this->envoyer($p, (string)$g['pull']);
    }

    private function envoyer(string $donnees, string $pair): void
    {
        if ($this->flux) @stream_socket_sendto($this->flux, $donnees, 0, $pair);
    }

    /** Valeurs de la trame dans les points de la connexion (règles, historique, état). */
    private function ranger(array $f, array $trame, string $quand): void
    {
        ($this->avecBase)($f['org'], function (Database $db, ConnectDepot $d) use ($f, $trame, $quand) {
            $pa = $db->fetchOne("SELECT * FROM ConnectPasserelles WHERE Id = :id", ['id' => $f['id']]);
            if (!$pa) return;
            $lectures = [];
            foreach ($d->points($f['id'], true) as $pt) {
                $v = ConnectLorawan::valeurPoint(is_array($pt['Adresse']) ? $pt['Adresse'] : [], $trame);
                if ($v !== null) $lectures[(int)$pt['Id']] = ['valeur' => $v];
            }
            if (!$lectures) {
                // Trame sans les champs suivis (trame d'état, de configuration…) : le capteur vit, rien d'autre.
                $d->noterEtatPasserelle($f['id'], (string)($pa['Etat'] ?: 'ok'), null, 0, null, true);
                return;
            }
            ConnectCollecte::releve($d, new ConnectAlarmes($d, $f['org']), $pa, $lectures, $quand, null);
        });
    }

    // ══ Ce que l'écran montre ══════════════════════════════════════════════

    private function noterAppareil(array $f, array $infos, array $rx, int $copies = 1): void
    {
        $a = $this->appareils[$f['org']][$f['eui']] ?? [];
        $a = array_merge($a, $infos, ['rssi' => $rx['rssi'], 'snr' => $rx['lsnr'], 'datr' => $rx['datr'], 'freq' => $rx['freq'],
                                      'passerelle' => $rx['eui'], 'copies' => $copies, 'nom' => $f['nom']]);
        $this->appareils[$f['org']][$f['eui']] = $a;
    }

    /** Dernière entrée de journal par capteur et par code : une trame refusée en boucle n'écrit pas une ligne par trame. */
    private array $signales = [];

    private function rejet(array $f, string $raison): void
    {
        $code = CodesErreur::reconnaitre($raison, 'connect', 'warning') ?? 'CXL-004';
        $k = $f['org'] . '|' . $f['eui'] . '|' . $code;
        if (time() - ($this->signales[$k] ?? 0) >= 900) {
            $this->signales[$k] = time();
            ($this->avecBase)($f['org'], fn() => Connect::journal('avertissement', "LoRaWAN « {$f['nom']} » ({$f['eui']}) : $raison",
                ['code' => $code, 'entiteType' => 'ConnectPasserelle', 'entiteId' => $f['id'], 'protocole' => 'lorawan']));
        }
        $this->appareils[$f['org']][$f['eui']]['rejet'] = $raison;
        $this->appareils[$f['org']][$f['eui']]['rejet_le'] = date('Y-m-d H:i:s');
        $this->appareils[$f['org']][$f['eui']]['nom'] = $f['nom'];
    }

    /** Capteur entendu mais non déclaré : l'écran le propose (détection). */
    private function inconnu(string $id, string $genre, ?string $joinEui, array $rx): void
    {
        $k = $genre . '|' . $id;
        if (!isset($this->inconnus[$k]) && class_exists('Connect')) {
            Connect::journal('info', 'LoRaWAN : capteur non déclaré entendu (' . ($genre === 'join' ? "DevEUI $id" : "DevAddr $id") . ", passerelle {$rx['eui']})",
                             ['code' => 'CXL-005']);
        }
        $this->inconnus[$k] = ['genre' => $genre, 'id' => $id, 'join_eui' => $joinEui, 'quand' => date('Y-m-d H:i:s'),
                               'rssi' => $rx['rssi'], 'passerelle' => $rx['eui'], 'n' => (int)($this->inconnus[$k]['n'] ?? 0) + 1];
        if (count($this->inconnus) > self::MAX_INCONNUS) {
            uasort($this->inconnus, fn($x, $y) => strcmp($y['quand'], $x['quand']));
            $this->inconnus = array_slice($this->inconnus, 0, self::MAX_INCONNUS, true);
        }
    }

    public static function fichierEtat(): string
    {
        return dirname(__DIR__, 2) . '/data/run/connect-lorawan.json';
    }

    public function publierEtat(bool $arret = false): void
    {
        $this->etatMaj = microtime(true);
        $gw = [];
        foreach ($this->passerelles as $eui => $g) {
            $gw[$eui] = ['ip' => $g['ip'], 'vu' => date('Y-m-d H:i:s', (int)$g['vu']), 'trames' => $g['trames'],
                         'emission' => !empty($g['pull'])] + (isset($g['stat']) ? ['stat' => $g['stat']] : [])
                       + (isset($g['erreur_emission']) ? ['erreur_emission' => $g['erreur_emission']] : []);
        }
        $e = ['pid' => getmypid(), 'maj' => date('Y-m-d H:i:s'), 'actif' => $this->flux !== null && !$arret, 'ecoute' => $this->ecoute,
              'recues' => $this->recues, 'passerelles' => $gw, 'appareils' => $this->appareils, 'inconnus' => array_values($this->inconnus)];
        @mkdir(dirname(self::fichierEtat()), 0775, true);
        $tmp = self::fichierEtat() . '.' . getmypid();
        if (@file_put_contents($tmp, json_encode($e, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR)) !== false) {
            @rename($tmp, self::fichierEtat());
        }
    }
}

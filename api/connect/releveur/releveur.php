<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka — collecteur du PC releveur (appareils hors réseau : hotspot Wi-Fi ou câble direct)
 *
 * Téléchargé depuis Larka (Connexions → 📶 Hors réseau), déjà configuré : adresse
 * de Larka et jeton de CE PC dans config.json. Il utilise les pilotes du
 * serveur (lib/connect/), donc lit les appareils exactement comme lui.
 *
 * Il ne tourne QUE quand on le lance (double-clic sur releveur.cmd) : aucune
 * tâche planifiée. Les anciennes versions en posaient ; elles sont retirées
 * d'office au premier lancement.
 *
 * Un relevé :
 *   1. demande à Larka ce qu'il faut relever (sinon, reprend la dernière liste) ;
 *   2. hotspot : se connecte au Wi-Fi de l'appareil (profil Wi-Fi créé une fois) ;
 *      câble direct : relève l'appareil s'il répond ;
 *   3. lit ses points ;
 *   4. revient sur le réseau d'origine ;
 *   5. envoie les valeurs, avec leur heure de lecture. Larka injoignable :
 *      elles attendent ici (en-attente.json) et partent au relevé suivant.
 *
 * Une seule carte Wi-Fi ne se connecte qu'à un réseau à la fois : pendant le
 * relevé (une minute environ), le PC quitte son Wi-Fi habituel. S'il est en
 * câble, ou s'il a une seconde carte Wi-Fi libre, le relevé se fait sur
 * l'interface libre et le réseau habituel n'est jamais coupé.
 *
 *   releveur collecter  [--sans-wifi] [--bavard]   relever maintenant (le double-clic)
 *   releveur tester     [--sans-wifi]          lit et affiche, n'envoie rien
 *   releveur envoyer                           envoie ce qui attend
 *   releveur configurer                        vérifie Larka, prépare les profils Wi-Fi
 *   releveur etat                              réglages, relevés en attente, et ce que le PC voit
 *                                              du Wi-Fi (réseau à reprendre après un relevé)
 *   releveur retirer                           profils Wi-Fi (et tâches d'anciennes versions)
 *   releveur confiance [--oui]                 épingle le certificat HTTPS de Larka
 *                                              (réseau interne, certificat auto-signé)
 *   releveur explorer [--appareil=nom] [--hote=IP]
 *                                              ce que l'appareil expose (pages web, API,
 *                                              Modbus), quand sa documentation manque :
 *                                              lecture seule, rapport exploration-….txt
 *   releveur materiel                          ports série et récepteurs radio USB branchés
 *                                              (clé wM-Bus pour les compteurs d'eau, de chaleur)
 *   --larka=URL                                autre adresse de Larka
 *
 * Compteurs radio wM-Bus : une clé USB réceptrice branchée sur ce PC est
 * écoutée à chaque passage (comme un appareil en câble direct). La liste du
 * matériel radio vu ici part avec les relevés : Larka la montre.
 */

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (PHP_VERSION_ID < 80100) { fwrite(STDERR, "PHP 8.1 ou plus est nécessaire (ici " . PHP_VERSION . ").\n"); exit(1); }
set_time_limit(0);

const R_VERSION = '1.5.0';
// Extensions indispensables : sans elles, le collecteur échouait plus loin avec un
// message trompeur (« Larka injoignable » quand openssl manque, faute de HTTPS).
if ($manque = array_values(array_filter(['openssl', 'mbstring'], fn($e) => !extension_loaded($e)))) {
    fwrite(STDERR, "✕ Extension(s) PHP manquante(s) : " . implode(', ', $manque) . ".\n"
        . (PHP_OS_FAMILY === 'Windows'
            ? "  Lancez le collecteur par releveur.cmd (il charge les extensions de son PHP). Si cela ne suffit pas : supprimez le dossier php\\ du collecteur et relancez releveur.cmd, qui le réinstalle.\n"
            : "  Installez-les, par exemple : sudo apt install php-mbstring (openssl est fourni avec PHP).\n"));
    exit(1);
}
define('R_DIR', __DIR__);
define('R_WINDOWS', PHP_OS_FAMILY === 'Windows');
define('R_CMD', R_WINDOWS ? 'releveur.cmd' : './releveur.sh');

require R_DIR . '/lib/connect/Pilote.php';
ConnectPilotes::charger();

// ── Petits outils ──────────────────────────────────────────────────────────

function r_dire(string $t): void { echo $t, "\n"; r_journal($t); }
function r_erreur(string $t): void { fwrite(STDERR, "✕ $t\n"); r_journal("ERREUR $t"); }

$GLOBALS['r_journal'] = [];
function r_journal(string $t): void
{
    $l = date('Y-m-d H:i:s') . '  ' . trim($t);
    $GLOBALS['r_journal'][] = $l;
    // Passage discret (--planifie --direct-seulement, si quelqu'un le lance en boucle) : gardé en mémoire,
    // écrit seulement s'il s'est passé quelque chose (relevé, envoi, erreur).
    if (is_array($GLOBALS['r_tampon'] ?? null)) { $GLOBALS['r_tampon'][] = $l; return; }
    @file_put_contents(R_DIR . '/releveur.log', $l . "\n", FILE_APPEND);
    // Journal borné : les 2 000 dernières lignes.
    if (@filesize(R_DIR . '/releveur.log') > 400000) {
        $lignes = @file(R_DIR . '/releveur.log') ?: [];
        @file_put_contents(R_DIR . '/releveur.log', implode('', array_slice($lignes, -2000)));
    }
}

/** Fin d'un passage discret : écrit le journal gardé en mémoire s'il a servi. */
function r_vider_tampon(bool $ecrire): void
{
    $lignes = is_array($GLOBALS['r_tampon'] ?? null) ? $GLOBALS['r_tampon'] : [];
    $GLOBALS['r_tampon'] = null;
    if ($ecrire) foreach ($lignes as $l) @file_put_contents(R_DIR . '/releveur.log', $l . "\n", FILE_APPEND);
}

/** État local du collecteur (tâches posées…). */
function r_etat_local(?array $maj = null): array
{
    $e = r_lire_json(R_DIR . '/etat-local.json');
    if ($maj !== null) { $e = array_merge($e, $maj); r_ecrire_json(R_DIR . '/etat-local.json', $e); }
    return $e;
}

function r_options(array $argv): array
{
    $pos = []; $o = [];
    foreach (array_slice($argv, 1) as $a) {
        if (str_starts_with($a, '--')) { [$k, $v] = array_pad(explode('=', substr($a, 2), 2), 2, true); $o[$k] = $v; }
        else $pos[] = $a;
    }
    return [$pos, $o];
}

function r_lire_json(string $f): array
{
    $j = json_decode((string)@file_get_contents($f), true);
    return is_array($j) ? $j : [];
}

/** Écrit un fichier lisible par ce seul compte (il contient des clés Wi-Fi et des identifiants). */
function r_ecrire_json(string $f, array $d): void
{
    $tmp = $f . '.tmp';
    file_put_contents($tmp, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    if (!R_WINDOWS) @chmod($tmp, 0600);
    @rename($tmp, $f);
}

function r_executer(array $cmd, ?string &$sortie = null): int
{
    $p = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubes);
    if (!is_resource($p)) { $sortie = 'commande impossible : ' . $cmd[0]; return 127; }
    fclose($tubes[0]);
    $sortie = (string)stream_get_contents($tubes[1]) . (string)stream_get_contents($tubes[2]);
    fclose($tubes[1]); fclose($tubes[2]);
    $code = proc_close($p);
    // Windows : la console parle souvent en page de code OEM ; on ramène en UTF-8.
    if (!mb_check_encoding($sortie, 'UTF-8')) $sortie = (string)@mb_convert_encoding($sortie, 'UTF-8', 'CP850');
    return $code;
}

/** Un port TCP répond-il ? */
function r_joignable(string $hote, int $port, float $delai = 2.0): bool
{
    $f = @stream_socket_client('tcp://' . (str_contains($hote, ':') ? "[$hote]" : $hote) . ':' . $port, $en, $es, $delai);
    if (!$f) return false;
    fclose($f);
    return true;
}

// ── Larka ──────────────────────────────────────────────────────────────────

/** Larka répond, mais son certificat HTTPS n'est pas reconnu par ce PC (ou n'est plus celui épinglé). */
final class CertificatRefuse extends RuntimeException {}

final class Larka
{
    /**
     * @param string $empreinte empreinte SHA-256 du certificat de Larka, épinglée : certificat interne
     *                          ou auto-signé (réseau interne). Utilisée seulement si la vérification
     *                          habituelle échoue ; jamais de connexion « sans vérification ».
     */
    public function __construct(private string $url, private string $jeton, private string $empreinte = '') {}

    public function appeler(string $methode, string $op, ?array $corps = null, int $delai = 30): array
    {
        $url = rtrim($this->url, '/') . '/api/index.php?action=connect_releveur' . ($methode === 'GET' ? '&op=' . rawurlencode($op) : '');
        $entetes = "X-Larka-Releveur: {$this->jeton}\r\nAccept: application/json\r\nUser-Agent: Larka-Releveur/" . R_VERSION . "\r\n";
        // follow_location = 0 : une redirection renverrait l'en-tête du jeton vers une autre adresse.
        $opts = ['http' => ['method' => $methode, 'timeout' => $delai, 'ignore_errors' => true, 'header' => $entetes, 'follow_location' => 0]];
        if ($corps !== null) {
            $opts['http']['header'] .= "Content-Type: application/json\r\n";
            $opts['http']['content'] = json_encode($corps + ['op' => $op], JSON_UNESCAPED_UNICODE);
        }
        // 1. Vérification habituelle (autorités reconnues). 2. Certificat épinglé, s'il y en a un.
        // Le jeton ne part qu'une fois la poignée de main TLS réussie : un échec n'envoie rien.
        $essais = [self::sslVerifie()];
        if ($this->empreinte !== '' && str_starts_with(strtolower($this->url), 'https:')) $essais[] = self::sslEpingle($this->empreinte);
        $brut = false; $err = '';
        foreach ($essais as $n => $ssl) {
            $opts['ssl'] = $ssl;
            $http_response_header = [];
            // Tous les avertissements comptent : le dernier (« operation failed ») ne dit pas la cause.
            $msgs = [];
            set_error_handler(function (int $no, string $str) use (&$msgs): bool { $msgs[] = $str; return true; });
            try { $brut = file_get_contents($url, false, stream_context_create($opts)); }
            finally { restore_error_handler(); }
            if ($brut !== false) break;
            $err = implode(' | ', $msgs);
            if (!self::estErreurCertificat($err)) break;                 // panne réseau : inutile d'essayer l'épingle
        }
        if ($brut === false) throw self::expliquer($err, $this->url, $this->empreinte !== '');
        $code = 0; $vers = '';
        foreach (($http_response_header ?? []) as $h) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $h, $m)) $code = (int)$m[1];
            if (preg_match('~^Location:\s*(\S+)~i', $h, $m)) $vers = $m[1];
        }
        if ($code >= 300 && $code < 400) {
            throw new RuntimeException("Larka répond par une redirection" . ($vers !== '' ? " vers $vers" : '') . ' : indiquez sa vraie adresse, par exemple « '
                                       . R_CMD . ' etat --larka=https://… » (le jeton n\'est jamais renvoyé ailleurs).');
        }
        $j = json_decode($brut, true);
        if (!is_array($j)) throw new RuntimeException("Réponse illisible de Larka (HTTP $code)");
        if (empty($j['success'])) throw new RuntimeException((string)($j['error'] ?? "refus de Larka (HTTP $code)"));
        return (array)($j['data'] ?? []);
    }

    private static function sslVerifie(): array
    {
        $ssl = ['verify_peer' => true, 'verify_peer_name' => true];
        $ca = R_DIR . '/php/cacert.pem';                                     // PHP installé par installer-php.ps1
        if (is_file($ca)) $ssl['cafile'] = $ca;
        return $ssl;
    }

    /** Certificat épinglé : seul CE certificat est accepté (empreinte SHA-256), quel que soit son émetteur. */
    private static function sslEpingle(string $empreinte): array
    {
        return ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true,
                'peer_fingerprint' => ['sha256' => strtolower(str_replace(':', '', $empreinte))]];
    }

    public static function estErreurCertificat(string $err): bool
    {
        return (bool)preg_match('/certificate|certificat|SSL operation failed|Failed to enable crypto|did not match expected|peer_fingerprint|self.signed|unable to get local issuer/i', $err);
    }

    /** Message utile au lieu de « injoignable » : certificat, nom, réseau. */
    private static function expliquer(string $err, string $url, bool $epingle): RuntimeException
    {
        // Le premier avertissement porte la cause (réseau, nom…), sans le nom de la fonction PHP.
        $brut = trim(preg_replace('/^[a-z_]+\(\):\s*/i', '', explode(' | ', $err)[0]) ?? $err);
        if (stripos($err, 'peer_fingerprint') !== false) {
            return new CertificatRefuse("Larka ($url) présente un AUTRE certificat que celui que ce PC connaît (renouvelé ? interception ?). "
                . 'Vérifiez-le, puis « ' . R_CMD . ' confiance » pour accepter le nouveau, ou retéléchargez le collecteur depuis Larka.');
        }
        if (self::estErreurCertificat($err)) {
            return new CertificatRefuse("Larka ($url) répond, mais ce PC ne reconnaît pas son certificat HTTPS (certificat interne ou auto-signé : "
                . 'le cadenas barré du navigateur). Une fois : « ' . R_CMD . ' confiance » (comparez l\'empreinte affichée avec celle du navigateur), '
                . 'ou retéléchargez le collecteur depuis Larka : il connaît déjà son certificat.');
        }
        if (stripos($err, 'wrapper "https"') !== false) {
            return new RuntimeException('Le PHP de ce PC ne sait pas faire de HTTPS (extension openssl absente) : lancez le collecteur par ' . R_CMD . '.');
        }
        if (preg_match('/getaddrinfo|Name or service not known|No such host|nodename nor servname|Hôte inconnu/i', $err)) {
            return new RuntimeException("Larka injoignable : le nom de « " . (parse_url($url, PHP_URL_HOST) ?: $url) . " » est inconnu de ce PC (réseau interne, VPN non connecté ?).");
        }
        return new RuntimeException("Larka injoignable ($url)" . ($brut !== '' ? " : $brut" : '') . '.');
    }

    public function joignable(): bool
    {
        $u = parse_url($this->url);
        $port = (int)($u['port'] ?? (($u['scheme'] ?? 'http') === 'https' ? 443 : 80));
        return isset($u['host']) && r_joignable($u['host'], $port, 2.0);
    }
}

// ── Wi-Fi ──────────────────────────────────────────────────────────────────

/** Le réseau Wi-Fi du moment, la connexion au hotspot, le retour. */
abstract class Wifi
{
    public static function pour(): ?Wifi
    {
        // Épreuve automatique : la logique Windows rejouée ailleurs, avec de faux netsh et powershell.
        if (getenv('LARKA_RELEVEUR_ESSAI_WIFI') === 'windows') return new WifiWindows();
        if (R_WINDOWS) return new WifiWindows();
        if (PHP_OS_FAMILY === 'Linux') return new WifiLinux();
        return null;
    }

    /** Interfaces Wi-Fi : [['nom' => …, 'connecte' => bool, 'ssid' => …, 'profil' => …]]. */
    abstract public function interfaces(): array;
    abstract public function creerProfil(string $ssid, string $securite, string $cle): string;
    abstract public function connecter(string $profil, string $ssid, string $interface): bool;
    abstract public function revenir(?string $profil, string $interface): bool;
    abstract public function supprimerProfil(string $profil): void;
    /** Profils Wi-Fi enregistrés sur le PC (pour retrouver le réseau habituel). */
    public function profilsConnus(): array { return []; }

    /** D'où viennent les noms de réseau (« netsh », « powershell », « nmcli ») : affiché par « etat ». */
    public string $source = '';

    /**
     * Profil Wi-Fi du réseau habituel, à rejoindre après le relevé : [profil ou null, d'où il vient].
     * $exclus : profils des hotspots (jamais un « réseau habituel »).
     */
    public function origine(array $carte, array $exclus = []): array
    {
        if (!empty($carte['libre']) || empty($carte['connecte'])) return [null, 'carte libre'];
        $p = (string)($carte['profil'] ?? '');
        return $p !== '' && !in_array($p, $exclus, true) ? [$p, $this->source] : [null, 'non identifié'];
    }

    /**
     * Nom du profil Wi-Fi : celui du hotspot, tel quel. Windows affiche le nom du
     * profil dans la liste des réseaux : « Larka - PE6-… » faisait croire à un
     * réseau inexistant. Les profils « Larka - … » des versions 1.2 et avant sont
     * remplacés au premier passage (r_profils).
     */
    public static function nomProfil(string $ssid): string { return $ssid; }
    public static function nomProfilAncien(string $ssid): string { return 'Larka - ' . $ssid; }

    /** Ce que le système a répondu quand aucune carte n'a été trouvée (pour le dire à l'utilisateur). */
    public string $diagnostic = '';

    /**
     * L'interface du relevé : une carte Wi-Fi libre si le PC en a une (le réseau
     * habituel n'est alors jamais coupé), sinon la carte en service, rendue après.
     */
    public function choisir(): ?array
    {
        $liste = $this->interfaces();
        if (!$liste) return null;
        foreach ($liste as $i) if (!$i['connecte']) return $i + ['libre' => true];
        return $liste[0] + ['libre' => false];
    }

    /**
     * Attend que l'interface soit sur ce réseau (ou l'échéance). $joint : l'appareil
     * répond-il déjà ? — c'est le vrai but, et cela marche même quand Windows tait
     * le nom du Wi-Fi (localisation refusée, nom de réseau encore « Identification… »).
     */
    public function attendre(string $interface, string $ssid, int $secondes, ?callable $joint = null): bool
    {
        $fin = time() + $secondes;
        do {
            foreach ($this->interfaces() as $i) {
                if ($i['nom'] === $interface && $i['connecte']
                    && ($i['ssid'] === $ssid || str_starts_with((string)$i['ssid'], $ssid) || $i['profil'] === self::nomProfil($ssid))) return true;
            }
            if ($joint && $joint()) return true;
            sleep(1);
        } while (time() < $fin);
        return false;
    }
}

final class WifiWindows extends Wifi
{
    public function interfaces(): array
    {
        r_executer(['netsh', 'wlan', 'show', 'interfaces'], $s);
        // Windows en français : espace insécable (fine ou non) avant les « : ».
        $s = str_replace(["\u{00A0}", "\u{202F}"], ' ', (string)$s);
        $out = [];
        // Un bloc par interface. Libellés anglais ou français (Name/Nom, State/État, Profile/Profil).
        foreach (preg_split('/\R\s*\R/', $s) ?: [] as $bloc) {
            // Sortie ramenée en UTF-8 (r_executer) : « u » pour que « É » compte pour un caractère.
            if (!preg_match('/^\s*(?:Name|Nom)\s*:\s*(.+?)\s*$/mui', $bloc, $n)) continue;
            $etat = preg_match('/^\s*(?:State|.?tat)\s*:\s*(.+?)\s*$/mui', $bloc, $e) ? $e[1] : '';
            $connecte = (bool)preg_match('/connect/iu', $etat) && !preg_match('/(dis|d.)connect/iu', $etat);
            $ssid = preg_match('/^\s*SSID\s*:\s*(.+?)\s*$/mui', $bloc, $m) ? $m[1] : '';
            $profil = preg_match('/^\s*(?:Profile|Profil)\s*:\s*(.+?)\s*$/mui', $bloc, $m) ? $m[1] : '';
            $guid = preg_match('/^\s*GUID\s*:\s*\{?([0-9a-f-]{36})/mi', $bloc, $m) ? strtoupper($m[1]) : '';
            $out[] = ['nom' => $n[1], 'connecte' => $connecte, 'ssid' => $connecte ? $ssid : '', 'profil' => $connecte ? $profil : '', 'guid' => $guid];
        }
        if ($out) { $this->source = 'netsh'; return $out; }
        // Repli : Windows 11 (24H2) ne détaille plus le Wi-Fi aux programmes tant que la localisation
        // n'est pas autorisée (souvent bloquée sur les PC d'entreprise). PowerShell donne sans elle les
        // cartes, leur état, le nom du Wi-Fi et le profil utilisé — et non le nom du réseau de Windows,
        // qui est celui du domaine (« lesagencesdeleau.eu ») sur un PC d'entreprise.
        foreach ($this->powershell(false)['cartes'] as $c) {
            $out[] = ['nom' => $c['nom'], 'connecte' => $c['connecte'], 'ssid' => $c['connecte'] ? ($c['ssid'] ?: $c['reseau']) : '',
                      'profil' => $c['connecte'] ? $c['profil'] : '', 'guid' => $c['guid'], 'reseau' => $c['reseau']];
        }
        if ($out) $this->source = 'powershell';
        if (!$out) {
            $premiere = trim((string)(preg_split('/\R/', trim($s))[0] ?? ''));
            $this->diagnostic = preg_match('/locali[sz]ation|location|emplacement/i', $s)
                ? "Windows refuse de montrer le Wi-Fi aux programmes tant que la localisation n'est pas autorisée : Paramètres → Confidentialité et sécurité → "
                  . "Localisation → activer « Services de localisation » et « Autoriser les applications de bureau à accéder à votre position »."
                : ($premiere !== '' ? "Windows répond : « $premiere »." : 'Windows ne signale aucune carte Wi-Fi (désactivée, ou mode avion ?).');
        }
        return $out;
    }

    public function creerProfil(string $ssid, string $securite, string $cle): string
    {
        $nom = self::nomProfil($ssid);
        $x = fn(string $v) => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        // « manual » : Windows ne rejoint jamais ce hotspot de lui-même, seulement pendant un relevé.
        $secu = $securite === 'ouvert'
            ? '<authEncryption><authentication>open</authentication><encryption>none</encryption><useOneX>false</useOneX></authEncryption>'
            : '<authEncryption><authentication>WPA2PSK</authentication><encryption>AES</encryption><useOneX>false</useOneX></authEncryption>'
              . '<sharedKey><keyType>passPhrase</keyType><protected>false</protected><keyMaterial>' . $x($cle) . '</keyMaterial></sharedKey>';
        $xml = '<?xml version="1.0"?>' . "\r\n" . '<WLANProfile xmlns="http://www.microsoft.com/networking/WLAN/profile/v1">'
             . '<name>' . $x($nom) . '</name><SSIDConfig><SSID><name>' . $x($ssid) . '</name></SSID></SSIDConfig>'
             . '<connectionType>ESS</connectionType><connectionMode>manual</connectionMode>'
             . '<MSM><security>' . $secu . '</security></MSM></WLANProfile>';
        $f = R_DIR . '/profil-' . substr(sha1($ssid), 0, 8) . '.xml';
        r_executer(['netsh', 'wlan', 'delete', 'profile', 'name=' . $nom], $s);   // clé changée : profil refait
        file_put_contents($f, $xml);
        $code = r_executer(['netsh', 'wlan', 'add', 'profile', 'filename=' . $f, 'user=current'], $s);
        @unlink($f);                                                         // il contenait la clé
        if ($code !== 0) throw new RuntimeException("profil Wi-Fi « $ssid » refusé par Windows : " . trim($s));
        return $nom;
    }

    public function connecter(string $profil, string $ssid, string $interface): bool
    {
        return r_executer(['netsh', 'wlan', 'connect', 'name=' . $profil, 'ssid=' . $ssid, 'interface=' . $interface], $s) === 0;
    }

    public function revenir(?string $profil, string $interface): bool
    {
        if ($profil === null || $profil === '') return r_executer(['netsh', 'wlan', 'disconnect', 'interface=' . $interface], $s) === 0;
        return r_executer(['netsh', 'wlan', 'connect', 'name=' . $profil, 'interface=' . $interface], $s) === 0;
    }

    public function supprimerProfil(string $profil): void
    {
        r_executer(['netsh', 'wlan', 'delete', 'profile', 'name=' . $profil], $s);
    }

    /** Profils Wi-Fi du PC, dans l'ordre de préférence de Windows (ceux de l'entreprise d'abord). Sans localisation. */
    public function profilsConnus(): array
    {
        r_executer(['netsh', 'wlan', 'show', 'profiles'], $s);
        return r_wifi_profils_netsh((string)$s);
    }

    private array $ssids = [];

    /** Nom du Wi-Fi (SSID) d'un profil : un profil d'entreprise porte souvent un autre nom que son Wi-Fi. */
    public function ssidDuProfil(string $profil): string
    {
        if (!isset($this->ssids[$profil])) {
            r_executer(['netsh', 'wlan', 'show', 'profile', 'name=' . $profil], $s);
            $s = str_replace(["\u{00A0}", "\u{202F}"], ' ', (string)$s);
            $this->ssids[$profil] = preg_match('/^\s*(?:SSID name|Nom (?:du )?SSID|SSID-Name)\s*:\s*"?(.*?)"?\s*$/mui', $s, $m) ? $m[1] : '';
        }
        return $this->ssids[$profil];
    }

    /**
     * Le réseau habituel, sans dépendre de la localisation de Windows : le profil donné par
     * netsh s'il le dit ; sinon le profil et le nom du Wi-Fi que Windows donne à PowerShell ;
     * sinon la dernière connexion notée au journal Wi-Fi de Windows (événement 8001). Le nom
     * retenu doit être un profil du PC (sinon « netsh wlan connect » échoue).
     */
    public function origine(array $carte, array $exclus = []): array
    {
        if (!empty($carte['libre']) || empty($carte['connecte'])) return [null, 'carte libre'];
        $cands = [];
        if ($this->source === 'netsh' && (string)$carte['profil'] !== '') $cands[] = [(string)$carte['profil'], 'netsh'];
        $ps = $this->powershell(true);
        $guid = (string)($carte['guid'] ?? '');
        foreach ($ps['cartes'] as $c) {
            if ($c['nom'] !== $carte['nom']) continue;
            $guid = $guid ?: $c['guid'];
            if ($c['profil'] !== '') $cands[] = [$c['profil'], 'profil de la connexion Wi-Fi'];
            if ($c['ssid'] !== '') $cands[] = [$c['ssid'], 'nom du Wi-Fi'];
        }
        foreach ($ps['evenements'] as $e) {
            if ($guid !== '' && $e['guid'] !== '' && strcasecmp($e['guid'], $guid) !== 0) continue;
            if ($e['profil'] !== '') $cands[] = [$e['profil'], 'journal Wi-Fi de Windows'];
            if ($e['ssid'] !== '') $cands[] = [$e['ssid'], 'journal Wi-Fi de Windows'];
        }
        return r_wifi_choisir_origine($cands, $this->profilsConnus(), $exclus, fn(string $p) => $this->ssidDuProfil($p));
    }

    /**
     * Cartes Wi-Fi vues par PowerShell (sans localisation) et, si demandé, les dernières
     * connexions du journal Wi-Fi de Windows : ['cartes' => […], 'evenements' => […]].
     */
    private function powershell(bool $evenements): array
    {
        $ps = <<<'PS'
            [Console]::OutputEncoding=[Text.Encoding]::UTF8; $w=@{};
            try { $null=[Windows.Networking.Connectivity.NetworkInformation,Windows.Networking.Connectivity,ContentType=WindowsRuntime];
              foreach($p in [Windows.Networking.Connectivity.NetworkInformation]::GetConnectionProfiles()){
                if($p.IsWlanConnectionProfile -and $p.NetworkAdapter){ $s=$p.WlanConnectionProfileDetails.GetConnectedSsid();
                  if($s){ $w[[string]$p.NetworkAdapter.NetworkAdapterId]=@($s,$p.ProfileName) } } } } catch {};
            Get-NetAdapter -Physical | Where-Object { $_.NdisPhysicalMedium -eq 9 } | ForEach-Object {
              $n=Get-NetConnectionProfile -InterfaceAlias $_.Name -ErrorAction SilentlyContinue;
              $g=([string]$_.InterfaceGuid).Trim('{}'.ToCharArray()); $x=$w[$g]; $a=''; $b=''; if($x){ $a=$x[0]; $b=$x[1] };
              'C' + "`t" + $_.Name + "`t" + $_.Status + "`t" + (@($n.Name) -join '') + "`t" + $a + "`t" + $b + "`t" + $g }
            PS;
        if ($evenements) $ps .= <<<'PS'
            ; try { Get-WinEvent -FilterHashtable @{LogName='Microsoft-Windows-WLAN-AutoConfig/Operational';Id=8001} -MaxEvents 8 -ErrorAction Stop | ForEach-Object {
              $d=@{}; foreach($e in ([xml]$_.ToXml()).Event.EventData.Data){ $d[$e.Name]=$e.InnerText };
              'E' + "`t" + ([string]$d.InterfaceGuid).Trim('{}'.ToCharArray()) + "`t" + $d.ProfileName + "`t" + $d.SSID } } catch {}
            PS;
        r_executer(['powershell', '-NoProfile', '-NonInteractive', '-Command', preg_replace('/\s*\R\s*/', ' ', trim($ps))], $s);
        return r_wifi_powershell((string)$s);
    }
}

final class WifiLinux extends Wifi
{
    public function interfaces(): array
    {
        if (r_executer(['nmcli', '-t', '-f', 'DEVICE,TYPE,STATE,CONNECTION', 'device'], $s) !== 0) return [];
        $this->source = 'nmcli';
        $out = [];
        foreach (preg_split('/\R/', trim((string)$s)) ?: [] as $l) {
            $c = preg_split('/(?<!\\\\):/', $l);
            if (count($c) < 4 || $c[1] !== 'wifi') continue;
            $connecte = $c[2] === 'connected';
            $ssid = '';
            if ($connecte && r_executer(['nmcli', '-t', '-f', 'ACTIVE,SSID', 'device', 'wifi', 'list', 'ifname', $c[0]], $w) === 0) {
                foreach (preg_split('/\R/', (string)$w) ?: [] as $x) if (str_starts_with($x, 'yes:')) { $ssid = str_replace('\\:', ':', substr($x, 4)); break; }
            }
            $out[] = ['nom' => $c[0], 'connecte' => $connecte, 'ssid' => $ssid, 'profil' => $connecte ? str_replace('\\:', ':', $c[3]) : ''];
        }
        return $out;
    }

    public function creerProfil(string $ssid, string $securite, string $cle): string
    {
        $nom = self::nomProfil($ssid);
        r_executer(['nmcli', 'connection', 'delete', 'id', $nom], $s);
        // Jamais de route par défaut par le hotspot : Internet et le réseau habituel restent où ils sont.
        $cmd = ['nmcli', 'connection', 'add', 'type', 'wifi', 'con-name', $nom, 'ifname', '*', 'ssid', $ssid,
                'connection.autoconnect', 'no', 'ipv4.never-default', 'yes', 'ipv6.never-default', 'yes'];
        if ($securite !== 'ouvert') array_push($cmd, 'wifi-sec.key-mgmt', 'wpa-psk', 'wifi-sec.psk', $cle);
        if (r_executer($cmd, $s) !== 0) throw new RuntimeException("profil Wi-Fi « $ssid » refusé : " . trim($s));
        return $nom;
    }

    public function connecter(string $profil, string $ssid, string $interface): bool
    {
        return r_executer(['nmcli', 'connection', 'up', 'id', $profil, 'ifname', $interface], $s) === 0;
    }

    public function revenir(?string $profil, string $interface): bool
    {
        if ($profil === null || $profil === '') return r_executer(['nmcli', 'device', 'disconnect', $interface], $s) === 0;
        return r_executer(['nmcli', 'connection', 'up', 'id', $profil, 'ifname', $interface], $s) === 0;
    }

    public function profilsConnus(): array
    {
        if (r_executer(['nmcli', '-t', '-f', 'NAME,TYPE', 'connection', 'show'], $s) !== 0) return [];
        $out = [];
        foreach (preg_split('/\R/', trim((string)$s)) ?: [] as $l) {
            $c = preg_split('/(?<!\\\\):/', $l);
            if (count($c) >= 2 && str_contains($c[1], 'wireless')) $out[] = str_replace('\\:', ':', $c[0]);
        }
        return $out;
    }

    public function supprimerProfil(string $profil): void
    {
        r_executer(['nmcli', 'connection', 'delete', 'id', $profil], $s);
    }
}

/**
 * Profils de « netsh wlan show profiles », dans l'ordre : ceux de stratégie de groupe
 * (Wi-Fi de l'entreprise, listés sans « : ») puis ceux de l'utilisateur.
 */
function r_wifi_profils_netsh(string $s): array
{
    $s = str_replace(["\u{00A0}", "\u{202F}", "\r"], [' ', ' ', ''], $s);
    $out = []; $gpo = false;
    foreach (explode("\n", $s) as $l) {
        if (trim($l) === '' || preg_match('/^\s*-{3,}\s*$/', $l)) continue;
        if ($l[0] !== ' ' && $l[0] !== "\t") {                             // titre de section
            $gpo = (bool)preg_match('/group policy|strat.gie de groupe|gruppenrichtlinie|directiva de grupo/iu', $l);
            continue;
        }
        if (preg_match('/^\s*(?:All User Profile|Current User Profile|Profil [^:]*?|Benutzerprofil[^:]*?|Perfil [^:]*?)\s*:\s*(.+?)\s*$/ui', $l, $m)) { $out[] = $m[1]; continue; }
        if ($gpo && !str_contains($l, ':') && !preg_match('/^\s*</', $l)) $out[] = trim($l);   // « <Aucun> » / « <None> » : rien
    }
    return array_values(array_unique(array_filter($out, fn($p) => $p !== '')));
}

/** Sortie du script PowerShell de WifiWindows : ['cartes' => […], 'evenements' => […]]. */
function r_wifi_powershell(string $s): array
{
    $cartes = []; $ev = [];
    foreach (preg_split('/\R/', trim($s)) ?: [] as $l) {
        $c = explode("\t", $l);
        if (($c[0] ?? '') === 'C' && trim($c[1] ?? '') !== '') {
            $cartes[] = ['nom' => trim($c[1]), 'connecte' => strcasecmp(trim($c[2] ?? ''), 'Up') === 0, 'reseau' => trim($c[3] ?? ''),
                         'ssid' => trim($c[4] ?? ''), 'profil' => trim($c[5] ?? ''), 'guid' => strtoupper(trim($c[6] ?? ''))];
        } elseif (($c[0] ?? '') === 'E') {
            $ev[] = ['guid' => strtoupper(trim($c[1] ?? '')), 'profil' => trim($c[2] ?? ''), 'ssid' => trim($c[3] ?? '')];
        }
    }
    return ['cartes' => $cartes, 'evenements' => $ev];
}

/**
 * Parmi les noms candidats ([nom, source], du plus sûr au moins sûr), le premier qui est un
 * profil du PC, ou dont le Wi-Fi est celui d'un profil ; jamais un hotspot. [profil|null, explication].
 */
function r_wifi_choisir_origine(array $cands, array $connus, array $exclus, callable $ssidDe): array
{
    $cands = array_values(array_filter($cands, fn($c) => $c[0] !== '' && !in_array($c[0], $exclus, true) && !str_starts_with($c[0], 'Larka - ')));
    if (!$connus) return $cands ? $cands[0] : [null, 'aucun nom de réseau donné par Windows'];
    foreach ($cands as [$n, $src]) if (in_array($n, $connus, true)) return [$n, $src];
    foreach ($cands as [$n, $src]) {
        foreach ($connus as $p) {
            if (in_array($p, $exclus, true)) continue;
            if ($ssidDe($p) === $n) return [$p, "$src (Wi-Fi « $n »)"];
        }
    }
    return [null, $cands ? 'aucun profil du PC ne correspond à « ' . implode(' », « ', array_unique(array_column($cands, 0))) . ' »' : 'aucun nom de réseau donné par Windows'];
}

/** Profils Wi-Fi des hotspots (ceux du collecteur) : jamais pris pour le réseau habituel. */
function r_profils_hotspots(array $connexions = []): array
{
    $out = [];
    foreach (r_lire_json(R_DIR . '/profils.json') as $ssid => $p) { $out[] = (string)$ssid; if (is_array($p) && !empty($p['profil'])) $out[] = (string)$p['profil']; }
    foreach ($connexions as $c) if (($s = (string)($c['wifi']['ssid'] ?? '')) !== '') { $out[] = $s; $out[] = Wifi::nomProfil($s); }
    return array_values(array_unique($out));
}

// ── Relevé ─────────────────────────────────────────────────────────────────

/**
 * Heure de Larka : son fuseau (journal lisible) et l'écart de l'horloge de ce PC
 * (les relevés partent datés à l'heure du serveur, quel que soit le réglage du PC).
 */
function r_heure(array $m): void
{
    $f = (string)($m['fuseau'] ?? '');
    if ($f !== '' && in_array($f, timezone_identifiers_list(), true)) date_default_timezone_set($f);
    $GLOBALS['r_decalage'] = (int)($m['decalage'] ?? 0);
}

/** Maintenant, à l'horloge de Larka (horodatage Unix). */
function r_maintenant(): int { return time() + (int)($GLOBALS['r_decalage'] ?? 0); }

/** Mission : de Larka si possible, sinon la dernière gardée ici. */
function r_mission(Larka $larka, bool $exiger = false, int $delai = 20): array
{
    try {
        $m = $larka->appeler('GET', 'mission', null, $delai);
        $m['decalage'] = isset($m['horloge']) && is_numeric($m['horloge']) ? (int)$m['horloge'] - time() : 0;
        if (abs($m['decalage']) > 120) r_dire('  (horloge de ce PC décalée de ' . $m['decalage'] . ' s : relevés datés à l\'heure de Larka)');
        r_ecrire_json(R_DIR . '/mission.json', $m);
        r_heure($m);
        return $m;
    } catch (Throwable $e) {
        if ($exiger) throw $e;
        $m = r_lire_json(R_DIR . '/mission.json');
        if (!$m) throw new RuntimeException($e->getMessage() . ' — et aucune liste de relevés gardée : lancez « ' . R_CMD . ' configurer » une fois Larka joignable.');
        r_heure($m);
        r_dire('  (' . $e->getMessage() . ' — dernière liste de relevés, du ' . ($m['maintenant'] ?? '?') . ')');
        return $m;
    }
}

/**
 * Profils Wi-Fi des hotspots de la mission : créés s'ils manquent, refaits si le
 * nom, la sécurité ou la clé ont changé dans Larka. « Configurer une fois » reste
 * vrai quand on confie un nouvel appareil au PC, ou qu'une clé change.
 * @return array<string, string> ssid => nom du profil
 */
function r_profils(Wifi $wifi, array $cx, bool $bavard = true): array
{
    $garde = r_lire_json(R_DIR . '/profils.json');
    $out = []; $neuf = [];
    foreach ($cx as $c) {
        $ssid = (string)($c['wifi']['ssid'] ?? '');
        if ($ssid === '' || isset($out[$ssid])) continue;
        $secu = (string)($c['wifi']['securite'] ?? 'wpa2');
        $cle = (string)($c['wifi']['cle'] ?? '');
        $empreinte = hash('sha256', "$ssid\n$secu\n$cle");
        $avant = $garde[$ssid] ?? null;
        if (is_array($avant) && ($avant['empreinte'] ?? '') === $empreinte && ($avant['profil'] ?? '') === Wifi::nomProfil($ssid)) {
            $out[$ssid] = (string)$avant['profil'];
            $neuf[$ssid] = $avant;
            continue;
        }
        try {
            $profil = $wifi->creerProfil($ssid, $secu, $cle);
            // Ancien profil (« Larka - … », versions 1.2 et avant) : retiré.
            $ancien = is_array($avant) ? (string)($avant['profil'] ?? '') : '';
            if ($ancien !== '' && $ancien !== $profil) $wifi->supprimerProfil($ancien);
            $out[$ssid] = $profil;
            $neuf[$ssid] = ['profil' => $profil, 'empreinte' => $empreinte];
            if ($bavard) r_dire("  ✓ profil Wi-Fi « $profil » " . ($avant ? '(mis à jour)' : '(jamais rejoint de lui-même)'));
        } catch (Throwable $e) {
            r_erreur($e->getMessage());
        }
    }
    // Hotspots qui ne sont plus confiés à ce PC : leur profil est retiré.
    foreach ($garde as $ssid => $p) {
        if (isset($neuf[$ssid])) continue;
        $nom = is_array($p) ? (string)($p['profil'] ?? '') : (string)$p;
        if ($nom !== '') $wifi->supprimerProfil($nom);
    }
    r_ecrire_json(R_DIR . '/profils.json', $neuf);
    return $out;
}

/**
 * Le collecteur ne joint que l'appareil : une adresse IP privée ou de lien local
 * (celle de l'appareil sur son hotspot). Le bouclage (127.x : simulateur) seulement
 * en essai, avec --sans-wifi. Larka le vérifie aussi à la saisie.
 */
function r_adresse_permise(string $ip, bool $essai): bool
{
    $ip = trim($ip, '[]');
    // Un nom (ex. « powerelec.net », que l'appareil sert sur son hotspot) : toutes ses
    // adresses, une fois résolu ici, doivent être permises — jamais une adresse publique.
    if ($ip !== '' && !filter_var($ip, FILTER_VALIDATE_IP)) {
        if (!preg_match('/^[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?$/i', $ip)) return false;
        $ips = @gethostbynamel($ip) ?: [];
        if (!$ips) return false;
        foreach ($ips as $x) if (!r_adresse_permise($x, $essai)) return false;
        return true;
    }
    if (!filter_var($ip, FILTER_VALIDATE_IP)) return false;
    if (preg_match('/^(127\.|::1$)/', $ip)) return $essai;
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false || (bool)preg_match('/^(169\.254\.|fe[89ab][0-9a-f]:)/i', $ip);
}

/** Accès d'une connexion de la mission : « hotspot » ou « direct » (missions anciennes : d'après le Wi-Fi). */
function r_acces(array $c): string
{
    $a = (string)($c['acces'] ?? '');
    if ($a === 'hotspot' || $a === 'direct') return $a;
    return (string)($c['wifi']['ssid'] ?? '') !== '' ? 'hotspot' : 'direct';
}

/** Adresse et port de l'appareil (API REST : d'après l'adresse de l'API). */
function r_cible(array $c): array
{
    if (($c['protocole'] ?? '') === 'rest') {
        $u = parse_url((string)($c['parametres']['url_base'] ?? ''));
        $port = (int)($u['port'] ?? ((($u['scheme'] ?? 'http') === 'https') ? 443 : 80));
        return [trim((string)($u['host'] ?? ''), '[]'), $port];
    }
    return [(string)($c['hote'] ?? ''), (int)($c['port'] ?? 0)];
}

/**
 * Un appareil en direct répond-il (câble branché, appareil allumé) ? Rend
 * [oui/non, explication]. BACnet passe par UDP : on tente la lecture.
 */
function r_branche(array $c): array
{
    // Clé radio USB : branchée sur ce PC, ou pas. (Pas d'adresse IP en jeu.)
    if (($c['protocole'] ?? '') === 'wmbus') {
        $port = (string)($c['parametres']['port'] ?? 'auto');
        if ($port === '' || $port === 'auto') return ConnectSerie::recepteur() ? [true, ''] : [false, 'clé radio wM-Bus non branchée sur ce PC — rien à relever'];
        return R_WINDOWS || file_exists($port) ? [true, ''] : [false, "$port absent (clé radio débranchée ?) — rien à relever"];
    }
    [$ip, $port] = r_cible($c);
    if (($c['protocole'] ?? '') === 'bacnet' || $port <= 0) return [true, ''];
    if (r_joignable($ip, $port, 1.5)) return [true, ''];
    // Le PC a-t-il seulement une adresse dans le réseau de l'appareil ? (Sans objet en
    // bouclage — simulateur — ou en lien local, que Windows se donne tout seul.)
    $reseau = preg_match('/^(\d+\.\d+\.\d+)\.\d+$/', $ip, $m) && !preg_match('/^(127|169\.254)\./', $ip) ? $m[1] : '';
    $siennes = [];
    foreach ((function_exists('net_get_interfaces') ? (net_get_interfaces() ?: []) : []) as $carte) {
        foreach ((array)($carte['unicast'] ?? []) as $u) {
            $a = (string)($u['address'] ?? '');
            if (filter_var($a, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) $siennes[] = $a;
        }
    }
    if ($reseau !== '' && $siennes && !array_filter($siennes, fn($a) => str_starts_with($a, "$reseau."))) {
        $pc = $reseau . '.' . (str_ends_with($ip, '.250') ? '251' : '250');
        return [false, "pas joignable : ce PC n'a pas d'adresse dans le réseau de l'appareil ($reseau.x). Une fois, sur la carte Ethernet : "
                     . "Protocole Internet version 4 → Configuration alternative → $pc, masque 255.255.255.0, passerelle vide (voir LISEZ-MOI)."];
    }
    return [false, 'pas branché (ou éteint) — rien à relever'];
}

/** Passerelle de la carte qui n'était pas là avant de rejoindre le hotspot (l'appareil), ou ''. */
function r_passerelle_hotspot(string $carte, array $avant): string
{
    foreach (r_passerelles($carte) as $g) if (!in_array($g, $avant, true) && r_adresse_permise($g, false)) return $g;
    return '';
}

/**
 * Adresse saisie par un NOM (« powerelec.net ») qui ne mène pas, d'ici, à une
 * adresse privée : Windows a souvent gardé en cache la réponse d'Internet (le
 * même nom y existe, à une adresse publique), ou le hotspot ne résout pas les
 * noms. Sur son propre Wi-Fi, l'appareil est la passerelle : on l'interroge à
 * cette adresse, et on le dit (à reporter dans Larka).
 */
function r_hote_hotspot(array $c, string $carte, array $gwAvant): array
{
    [$h] = r_cible($c);
    if ($h === '' || filter_var($h, FILTER_VALIDATE_IP) || r_adresse_permise($h, false)) return $c;
    $g = r_passerelle_hotspot($carte, $gwAvant);
    if ($g === '') return $c;                                                // r_lire dira pourquoi
    r_dire("  « $h » ne mène pas à l'appareil d'ici : adresse du hotspot utilisée, $g (à saisir dans Larka à la place de « $h »)");
    if (($c['protocole'] ?? '') === 'rest') {
        $u = (string)($c['parametres']['url_base'] ?? '');
        $c['parametres']['url_base'] = preg_replace('#^(\w+://)(\[[^\]]+\]|[^/:?\#]+)#', '${1}' . $g, $u, 1);
    } else {
        $c['hote'] = $g;
    }
    return $c;
}

/** Ports série et récepteurs radio de ce PC, gardés pour Larka (materiel.json). */
function r_inventaire(): array
{
    try { $ports = ConnectSerie::ports(); } catch (Throwable $e) { $ports = []; }
    $ports = array_map(fn($p) => array_intersect_key($p, array_flip(['port', 'vid', 'pid', 'fabricant', 'produit', 'modele', 'libelle', 'conseil'])), $ports);
    $inv = ['quand' => date('Y-m-d H:i:s', r_maintenant()), 'systeme' => PHP_OS_FAMILY, 'ports' => array_slice($ports, 0, 20)];
    r_ecrire_json(R_DIR . '/materiel.json', $inv);
    return $inv;
}

function r_materiel(): int
{
    $inv = r_inventaire();
    if (!$inv['ports']) {
        r_dire('Aucun port série USB sur ce PC. Une clé radio wM-Bus branchée devrait apparaître ici ; sinon, essayez une autre prise USB'
            . (R_WINDOWS ? ' et regardez le Gestionnaire de périphériques (Ports COM et LPT).' : ' et regardez « dmesg | tail ».'));
        return 0;
    }
    foreach ($inv['ports'] as $p) {
        r_dire(($p['modele'] ? '📻 ' : '   ') . $p['port'] . ' — ' . $p['libelle'] . ($p['vid'] ? " [{$p['vid']}:{$p['pid']}]" : '')
            . ($p['modele'] ? ' : pris en charge' : '') . (!empty($p['conseil']) ? "
     " . $p['conseil'] : ''));
    }
    if (!R_WINDOWS) foreach ($inv['ports'] as $p) {
        if ($p['modele'] && is_file($p['port']) === false && file_exists($p['port']) && !is_writable($p['port'])) {
            r_dire("   ⚠ {$p['port']} : accès refusé à ce compte — sudo usermod -aG dialout \$USER, puis fermer et rouvrir la session.");
        }
    }
    return 0;
}

/** Lit les points d'une connexion avec le pilote du serveur. */
function r_lire(array $c, bool $essai = false): array
{
    $cible = ($c['protocole'] ?? '') === 'rest' ? (string)parse_url((string)($c['parametres']['url_base'] ?? ''), PHP_URL_HOST) : (string)($c['hote'] ?? '');
    // Clé radio USB : rien sur le réseau, le pilote n'ouvre qu'un port série local.
    if (($c['protocole'] ?? '') !== 'wmbus' && !r_adresse_permise($cible, $essai)) {
        throw new RuntimeException(filter_var(trim($cible, '[]'), FILTER_VALIDATE_IP)
            ? "adresse « $cible » refusée : un appareil hors réseau a une adresse IP privée (192.168.4.1, 10.0.0.1…)"
            : "« $cible » ne mène pas à une adresse privée d'ici (pas encore sur le Wi-Fi de l'appareil ?) : relevé refusé, par sécurité.");
    }
    $pilote = ConnectPilotes::creer(['Id' => (int)$c['id'], 'Nom' => (string)$c['nom'], 'Protocole' => (string)$c['protocole'],
                                     'Hote' => (string)($c['hote'] ?? ''), 'Port' => (int)($c['port'] ?? 0), 'Identifiant' => (string)($c['identifiant'] ?? ''),
                                     'params' => (array)$c['parametres'], 'secret' => (array)$c['secrets'], '_tenant' => 'releveur']);
    try {
        return $pilote->lire(array_values((array)$c['points']));
    } finally {
        try { $pilote->fermer(); } catch (Throwable $e) {}
    }
}

/**
 * Le cycle complet. $envoyer = false : affiche seulement (« tester »).
 * @return int code de sortie
 */
function r_collecter(Larka $larka, array $o, bool $envoyer): int
{
    $sansWifi = isset($o['sans-wifi']);
    $directSeul = isset($o['direct-seulement']);
    // Passage discret (--planifie --direct-seulement) : journal écrit seulement s'il se passe quelque chose.
    $discret = $directSeul && isset($o['planifie']);
    if ($discret) $GLOBALS['r_tampon'] = [];
    $mission = r_mission($larka, false, $discret ? 6 : 20);
    $tout = (array)($mission['connexions'] ?? []);
    if (!$tout) { r_dire('Aucun appareil à relever pour ce PC (Larka → Connexions → 📶 Hors réseau).'); $c = $envoyer ? r_envoyer($larka) : 0; r_vider_tampon(false); return $c; }
    $directs = array_values(array_filter($tout, fn($c) => r_acces($c) === 'direct'));
    $cx = $directSeul ? [] : array_values(array_filter($tout, fn($c) => r_acces($c) === 'hotspot'));
    $releves = [];
    // Ce qui attend d'un passage précédent (relevés, détection) part avant de quitter le réseau :
    // un retour au réseau manqué la dernière fois ne doit pas le bloquer indéfiniment.
    if ($envoyer && !$discret) {
        $attRecon = (bool)r_lire_json(R_DIR . '/reconnaissances.json');
        $attReleves = (bool)r_lire_json(R_DIR . '/en-attente.json');
        if (($attRecon || $attReleves) && $larka->joignable()) { if ($attReleves) r_envoyer($larka); else r_recon_envoyer($larka); }
    }

    // Matériel radio du PC : vu à chaque passage où une clé radio est attendue (sinon une fois par jour).
    $inv = r_lire_json(R_DIR . '/materiel.json');
    if (array_filter($tout, fn($c) => ($c['protocole'] ?? '') === 'wmbus') || (int)strtotime((string)($inv['quand'] ?? '')) < time() - 86400) r_inventaire();

    // 1. Appareils branchés par câble : rien à changer sur le PC, on relève s'ils répondent.
    foreach ($directs as $c) {
        if (!empty($c['reconnaissance']) && $envoyer) r_reconnaitre_silencieux($c, '', [], $sansWifi);
        if (($c['protocole'] ?? '') === 'auto') continue;                 // protocole encore inconnu : rien à lire
        [$branche, $pourquoi] = r_branche($c);
        if (!$branche) { r_dire("  🔗 {$c['nom']} : $pourquoi"); continue; }
        $ts = r_maintenant();
        try {
            $lectures = $c['points'] ? r_lire($c, $sansWifi) : [];
            $lues = count(array_filter($lectures, fn($l) => ($l['valeur'] ?? null) !== null || ($l['texte'] ?? null) !== null));
            // BACnet (UDP) : aucune réponse du tout, c'est un appareil débranché, pas une panne.
            if ($lues === 0 && ($c['protocole'] ?? '') === 'bacnet') { r_dire("  🔗 {$c['nom']} : pas de réponse (pas branché ?) — rien à relever"); continue; }
            r_dire("  🔗 {$c['nom']} : $lues / " . count($c['points']) . ' point(s) lu(s)');
            r_detail_points($c, $lectures, isset($o['bavard']) || !$envoyer);
            $releves[] = ['connexion' => (int)$c['id'], 'quand' => date('Y-m-d H:i:s', $ts), 'ts' => $ts, 'lectures' => $lectures, 'erreur' => null];
        } catch (Throwable $e) {
            r_erreur("{$c['nom']} : " . $e->getMessage());
            $releves[] = ['connexion' => (int)$c['id'], 'quand' => date('Y-m-d H:i:s', $ts), 'ts' => $ts, 'lectures' => [], 'erreur' => $e->getMessage()];
        }
    }
    if (!$cx) return r_terminer($larka, $releves, $envoyer, $sansWifi, $discret);

    // 2. Appareils en hotspot : le PC rejoint leur Wi-Fi, puis revient sur le sien.
    // Un appareil sans point n'a rien à relever : inutile de quitter le Wi-Fi pour lui.
    foreach ($cx as $c) if (empty($c['points']) && empty($c['reconnaissance'])) r_dire("  {$c['nom']} : aucun point à relever — ajoutez ses points dans Larka (Connexions → Points), ou demandez une détection (🔎). Wi-Fi laissé tel quel.");
    $cx = array_values(array_filter($cx, fn($c) => !empty($c['points']) || (!empty($c['reconnaissance']) && $envoyer)));
    if (!$cx) return r_terminer($larka, $releves, $envoyer, $sansWifi, $discret);
    $wifi = $sansWifi ? null : Wifi::pour();
    // Hotspots impossibles ici : ce qui a déjà été relevé (appareils en direct) part quand même.
    if (!$sansWifi && !$wifi) { r_erreur('Changement de Wi-Fi non pris en charge sur ce système : connectez-vous au hotspot à la main, puis « ' . R_CMD . ' collecter --sans-wifi ».'); r_terminer($larka, $releves, $envoyer, $sansWifi, false); return 2; }
    $carte = $wifi ? $wifi->choisir() : null;
    if ($wifi && !$carte) { r_erreur('Aucune carte Wi-Fi trouvée sur ce PC. ' . $wifi->diagnostic); r_terminer($larka, $releves, $envoyer, $sansWifi, false); return 2; }
    $exclus = r_profils_hotspots($tout);
    [$origine, $origineSrc] = $carte ? $wifi->origine($carte, $exclus) : [null, ''];
    $gwAvantTout = $carte ? r_passerelles((string)$carte['nom']) : [];
    $gwHotspots = [];
    $profils = $wifi ? r_profils($wifi, $cx, isset($o['bavard'])) : [];
    if ($carte) {
        r_dire('Carte Wi-Fi « ' . $carte['nom'] . ' » ' . ($carte['libre'] ? '(libre : le réseau habituel n\'est pas coupé)'
            : ($origine !== null ? "(réseau habituel « $origine » repris après le relevé)" : '(réseau habituel non identifié : ' . $origineSrc . ')')));
        r_journal('  réseau habituel : ' . ($origine ?? '?') . " — $origineSrc — passerelle(s) " . (implode(', ', $gwAvantTout) ?: 'aucune') . " — Wi-Fi vu par {$wifi->source}");
    }

    // Un passage par hotspot : plusieurs appareils peuvent partager le même.
    $parSsid = [];
    foreach ($cx as $c) $parSsid[(string)($c['wifi']['ssid'] ?? '')][] = $c;
    try {
        foreach ($parSsid as $ssid => $liste) {
            if ($wifi && $ssid !== '') {
                $profil = $profils[$ssid] ?? Wifi::nomProfil($ssid);
                r_dire("→ Hotspot « $ssid »…");
                [$ipA, $portA] = r_cible($liste[0]);
                // Passerelle de la carte AVANT le changement (réseau habituel) : celle
                // qu'on trouvera ensuite, si elle diffère, est l'appareil sur son hotspot.
                $gwAvant = r_passerelles((string)$carte['nom']);
                $joint = null;
                if (($liste[0]['protocole'] ?? '') !== 'bacnet' && $portA > 0) {
                    $joint = r_adresse_permise($ipA, $sansWifi)
                        ? fn() => r_joignable($ipA, $portA, 1.0)
                        : fn() => ($g = r_passerelle_hotspot((string)$carte['nom'], $gwAvant)) !== '' && r_joignable($g, $portA, 1.0);
                } elseif ($portA <= 0) {
                    // Protocole à détecter : le hotspot est rejoint dès qu'une nouvelle passerelle apparaît.
                    $joint = fn() => r_passerelle_hotspot((string)$carte['nom'], $gwAvant) !== '';
                }
                $ok = $wifi->connecter($profil, $ssid, $carte['nom']) && $wifi->attendre($carte['nom'], $ssid, 40, $joint);
                if (!$ok) {
                    $err = "hotspot « $ssid » introuvable ou refusé (appareil éteint, hors de portée, ou clé changée ?)";
                    r_erreur($err);
                    $ts = r_maintenant();
                    foreach ($liste as $c) $releves[] = ['connexion' => (int)$c['id'], 'quand' => date('Y-m-d H:i:s', $ts), 'ts' => $ts, 'lectures' => [], 'erreur' => $err];
                    continue;
                }
                sleep(2);                                                    // adresse IP du hotspot (DHCP)
                if (($g = r_passerelle_hotspot((string)$carte['nom'], $gwAvant)) !== '') $gwHotspots[] = $g;
            }
            // Détection demandée : ce que l'appareil expose, envoyé à Larka au retour sur le réseau habituel.
            foreach ($liste as $c) {
                if (!empty($c['reconnaissance']) && $envoyer) r_reconnaitre_silencieux($c, $wifi && $carte ? (string)$carte['nom'] : '', $gwAvant ?? [], $sansWifi);
            }
            if ($wifi && $ssid !== '') $liste = array_map(fn($c) => r_hote_hotspot($c, (string)$carte['nom'], $gwAvant), $liste);
            foreach ($liste as $c) {
                if (($c['protocole'] ?? '') === 'auto' || empty($c['points'])) continue;   // rien à lire (détection seulement)
                $ts = r_maintenant();
                $quand = date('Y-m-d H:i:s', $ts);
                try {
                    $lectures = $c['points'] ? r_lire($c, $sansWifi) : [];
                    $lues = count(array_filter($lectures, fn($l) => ($l['valeur'] ?? null) !== null || ($l['texte'] ?? null) !== null));
                    r_dire("  {$c['nom']} : $lues / " . count($c['points']) . ' point(s) lu(s)');
                    r_detail_points($c, $lectures, isset($o['bavard']) || !$envoyer);
                    $releves[] = ['connexion' => (int)$c['id'], 'quand' => $quand, 'ts' => $ts, 'lectures' => $lectures, 'erreur' => null];
                } catch (Throwable $e) {
                    r_erreur("{$c['nom']} : " . $e->getMessage());
                    $releves[] = ['connexion' => (int)$c['id'], 'quand' => $quand, 'ts' => $ts, 'lectures' => [], 'erreur' => $e->getMessage()];
                }
            }
        }
    } finally {
        // Toujours rendre le réseau d'origine, même après une erreur.
        if ($wifi && $carte) r_rendre_reseau($wifi, $carte, $origine, $gwAvantTout, $larka, $gwHotspots, $exclus);
    }
    return r_terminer($larka, $releves, $envoyer, $sansWifi, false, true);
}

/**
 * Fin d'un relevé : les valeurs vont dans la file d'attente (rien ne se perd si
 * Larka ne répond pas), puis la file part si Larka répond.
 */
function r_terminer(Larka $larka, array $releves, bool $envoyer, bool $sansWifi, bool $discret, bool $apresWifi = false): int
{
    if (!$envoyer) { r_vider_tampon($releves !== []); return 0; }
    $file = r_lire_json(R_DIR . '/en-attente.json');
    $limite = r_maintenant() - 85 * 86400;
    $file = array_values(array_filter(array_merge($file, $releves), fn($r) => (int)($r['ts'] ?? strtotime((string)($r['quand'] ?? '')) ?: 0) >= $limite));
    r_ecrire_json(R_DIR . '/en-attente.json', array_slice($file, -5000));
    if ($discret) {
        // Passage de 10 minutes : rien de lu, rien en attente → rien à dire. Larka
        // injoignable (PC encore branché sur l'appareil) → on garde, on repassera.
        if (!$file) { r_vider_tampon(false); return 0; }
        if (!$larka->joignable()) { r_vider_tampon($releves !== []); return 0; }
        $c = r_envoyer($larka);
        r_vider_tampon(true);
        return $c;
    }
    if ($apresWifi && !$sansWifi && !$larka->joignable()) {
        for ($i = 0; $i < 30 && !$larka->joignable(); $i++) sleep(2);         // le réseau revient
    }
    return r_envoyer($larka);
}

/** Envoie ce qui attend, par lots. */
function r_envoyer(Larka $larka): int
{
    if (r_lire_json(R_DIR . '/reconnaissances.json')) r_recon_envoyer($larka);
    $file = r_lire_json(R_DIR . '/en-attente.json');
    if (!$file) { r_dire('Rien à envoyer.'); return 0; }
    try {
        while ($file) {
            $lot = array_slice($file, 0, 400);
            $r = $larka->appeler('POST', 'depot', ['releves' => $lot, 'journal' => array_slice($GLOBALS['r_journal'], -40), 'version' => R_VERSION]
                                              + (($m = r_lire_json(R_DIR . '/materiel.json')) ? ['materiel' => $m] : []), 60);
            foreach ((array)($r['refus'] ?? []) as $x) r_erreur('refusé par Larka : ' . $x);
            $file = array_slice($file, count($lot));
            r_ecrire_json(R_DIR . '/en-attente.json', $file);
            r_dire('✓ ' . (int)($r['acceptes'] ?? 0) . ' relevé(s) envoyé(s) à Larka.');
        }
        @file_put_contents(R_DIR . '/dernier-envoi.txt', date('Y-m-d H:i:s', r_maintenant()));
        return 0;
    } catch (Throwable $e) {
        r_erreur($e->getMessage() . ' — ' . count($file) . ' relevé(s) gardé(s) pour le prochain passage.');
        return 1;
    }
}

// ── Préparation (facultative) et ménage ─────────────────────────────────────

function r_configurer(Larka $larka, array $o): int
{
    r_dire('Larka : ' . (r_lire_json(R_DIR . '/config.json')['larka'] ?? '?'));
    try { $m = r_mission($larka, true); }
    catch (CertificatRefuse $e) {
        // Premier réglage, fenêtre ouverte : on montre le certificat et on propose de l'épingler.
        r_erreur($e->getMessage());
        if (!(function_exists('stream_isatty') && stream_isatty(STDIN)) || r_confiance($o) !== 0) return 1;
        $cfg = r_lire_json(R_DIR . '/config.json');
        $larka = new Larka((string)$cfg['larka'], (string)$cfg['jeton'], (string)($cfg['empreinte_certificat'] ?? ''));
        try { $m = r_mission($larka, true); }
        catch (Throwable $e2) { r_erreur($e2->getMessage()); return 1; }
    }
    catch (Throwable $e) { r_erreur($e->getMessage()); return 1; }
    $cx = (array)($m['connexions'] ?? []);
    r_dire('PC releveur « ' . ($m['releveur']['nom'] ?? '?') . ' » : ' . count($cx) . ' appareil(s) à relever.');
    foreach ($cx as $c) r_dire("  • {$c['nom']} — " . (r_acces($c) === 'direct' ? 'câble direct' : 'hotspot « ' . ($c['wifi']['ssid'] ?? '?') . ' »') . ', ' . count($c['points']) . ' point(s)');
    $hotspots = array_values(array_filter($cx, fn($c) => r_acces($c) === 'hotspot'));
    $directs = array_values(array_filter($cx, fn($c) => r_acces($c) === 'direct'));
    if ($hotspots && !isset($o['sans-wifi'])) {
        $wifi = Wifi::pour();
        if (!$wifi) r_dire('  (Système sans changement de Wi-Fi automatique : relevés avec --sans-wifi, une fois connecté au hotspot.)');
        else {
            @unlink(R_DIR . '/profils.json');                                  // configurer : profils refaits à neuf
            r_profils($wifi, $hotspots);
        }
    }
    if ($directs) r_dire('  Appareil(s) en direct : branchez le câble, puis lancez le relevé.');
    r_dire('✓ Prêt. Pour relever : double-cliquez releveur.cmd (rien ne se lance tout seul).');
    return 0;
}

/**
 * Tâches planifiées posées par les anciennes versions (« Larka releveur », « Larka
 * releveur N », « Larka releveur cable », lignes « # larka-releveur » du crontab) :
 * retirées. Le collecteur ne tourne plus que quand on le lance.
 */
function r_nettoyer_taches(): int
{
    $n = 0;
    if (R_WINDOWS) {
        foreach (r_taches() as $t) if (r_executer(['schtasks', '/Delete', '/TN', $t, '/F'], $s) === 0) $n++;
        return $n;
    }
    if (r_executer(['crontab', '-l'], $actuel) !== 0) return 0;
    $toutes = preg_split('/\R/', (string)$actuel) ?: [];
    $gardees = array_filter($toutes, fn($l) => trim($l) !== '' && !str_contains($l, '# larka-releveur') && !str_contains($l, 'no crontab'));
    $n = count(array_filter($toutes, fn($l) => str_contains($l, '# larka-releveur')));
    if ($n) {
        $f = tempnam(sys_get_temp_dir(), 'cron');
        file_put_contents($f, implode("\n", $gardees) . "\n");
        r_executer(['crontab', $f], $s);
        @unlink($f);
    }
    return $n;
}

/** Une fois par PC : les tâches des anciennes versions partent d'elles-mêmes. */
function r_nettoyer_taches_une_fois(): void
{
    if (!empty(r_etat_local()['taches_retirees'])) return;
    $n = r_nettoyer_taches();
    if ($n) r_dire("✓ $n tâche(s) planifiée(s) d'une ancienne version retirée(s) : le collecteur ne tourne plus que quand vous le lancez.");
    r_etat_local(['taches_retirees' => true]);
}

/** Tâches Windows « Larka releveur… » (anciennes versions). */
function r_taches(): array
{
    if (!R_WINDOWS) return [];
    r_executer(['schtasks', '/Query', '/FO', 'CSV', '/NH'], $s);
    preg_match_all('/"\\\\?(Larka releveur(?: \d+| cable)?)"/', (string)$s, $m);
    return array_values(array_unique($m[1]));
}

function r_retirer(): int
{
    r_nettoyer_taches();
    $wifi = Wifi::pour();
    foreach (r_lire_json(R_DIR . '/profils.json') as $p) if ($wifi) $wifi->supprimerProfil(is_array($p) ? (string)($p['profil'] ?? '') : (string)$p);
    @unlink(R_DIR . '/profils.json');
    @unlink(R_DIR . '/mission.json');
    @unlink(R_DIR . '/etat-local.json');
    r_dire('✓ Profils Wi-Fi (et tâches d\'anciennes versions) retirés. Les relevés en attente restent dans en-attente.json ; le dossier peut être supprimé.');
    return 0;
}

function r_etat(Larka $larka): int
{
    $cfg = r_lire_json(R_DIR . '/config.json');
    $m = r_lire_json(R_DIR . '/mission.json');
    r_dire('PC releveur « ' . ($cfg['nom'] ?? '?') . ' » — Larka : ' . ($cfg['larka'] ?? '?') . ($larka->joignable() ? ' (joignable)' : ' (injoignable d\'ici)'));
    r_dire('Appareils : ' . count((array)($m['connexions'] ?? [])) . ' (liste du ' . ($m['maintenant'] ?? 'jamais') . ')');
    r_dire('En attente d\'envoi : ' . count(r_lire_json(R_DIR . '/en-attente.json')) . ' relevé(s) — dernier envoi : ' . (trim((string)@file_get_contents(R_DIR . '/dernier-envoi.txt')) ?: 'jamais'));
    $restes = R_WINDOWS ? r_taches() : [];
    r_dire('Lancement : à la main seulement' . ($restes ? ' — tâches d\'une ancienne version encore là : ' . implode(', ', $restes) . ' (« ' . R_CMD . ' retirer »)' : '') . '.');
    $nRecon = count(r_lire_json(R_DIR . '/reconnaissances.json'));
    if ($nRecon) r_dire("Détection(s) en attente d'envoi : $nRecon");
    $w = Wifi::pour();
    if ($w) {
        $cartes = $w->interfaces();
        r_dire('Wi-Fi (lu par ' . ($w->source ?: '—') . ($w->source === 'powershell' ? ' : la localisation de Windows est désactivée, netsh tait le nom du Wi-Fi' : '') . ') :');
        foreach ($cartes as $i) {
            r_dire("  • {$i['nom']} : " . ($i['connecte'] ? "connectée à « {$i['ssid']} »" . (($i['profil'] ?? '') !== '' ? ", profil « {$i['profil']} »" : '')
                   . (($i['reseau'] ?? '') !== '' && ($i['reseau'] ?? '') !== $i['ssid'] ? " (réseau Windows « {$i['reseau']} »)" : '') : 'libre')
                   . ' — passerelle(s) : ' . (implode(', ', r_passerelles($i['nom'])) ?: 'aucune'));
        }
        if (!$cartes) r_dire('  aucune carte vue. ' . $w->diagnostic);
        $carte = $w->choisir();
        if ($carte) {
            [$orig, $src] = $w->origine($carte, r_profils_hotspots((array)($m['connexions'] ?? [])));
            r_dire('  Réseau repris après un relevé : ' . ($carte['libre'] ? 'aucun à reprendre (carte libre pour les hotspots)' : ($orig !== null ? "« $orig » (d'après : $src)" : "non identifié — $src")));
        }
        $pc = $w->profilsConnus();
        r_dire('  Profils Wi-Fi du PC : ' . ($pc ? '« ' . implode(' », « ', $pc) . ' »' : 'aucun lu'));
    }
    return 0;
}

// ── Certificat de Larka (réseau interne, certificat auto-signé) ─────────────

/** Certificat présenté par Larka : [empreinte SHA-256 hex, sujet, émetteur, fin de validité] ou exception. */
function r_certificat(string $urlLarka): array
{
    $u = parse_url($urlLarka);
    if (strtolower((string)($u['scheme'] ?? '')) !== 'https' || empty($u['host'])) throw new RuntimeException('Larka est en http : pas de certificat à approuver.');
    if (!function_exists('openssl_x509_fingerprint')) throw new RuntimeException('L\'extension PHP openssl manque sur ce PC.');
    $hote = trim((string)$u['host'], '[]');
    $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'capture_peer_cert' => true,
                                            'SNI_enabled' => true, 'peer_name' => $hote]]);
    $f = @stream_socket_client('ssl://' . (str_contains($hote, ':') ? "[$hote]" : $hote) . ':' . (int)($u['port'] ?? 443), $en, $es, 10, STREAM_CLIENT_CONNECT, $ctx);
    if (!$f) throw new RuntimeException("Larka injoignable ($urlLarka) : " . ($es ?: 'pas de réponse'));
    $cert = stream_context_get_params($f)['options']['ssl']['peer_certificate'] ?? null;
    fclose($f);
    if (!$cert) throw new RuntimeException('Larka n\'a présenté aucun certificat.');
    $i = openssl_x509_parse($cert) ?: [];
    $nom = fn(array $x) => (string)($x['CN'] ?? $x['O'] ?? '?');
    return [strtolower((string)openssl_x509_fingerprint($cert, 'sha256')), $nom((array)($i['subject'] ?? [])), $nom((array)($i['issuer'] ?? [])),
            isset($i['validTo_time_t']) ? date('d/m/Y', (int)$i['validTo_time_t']) : '?'];
}

/**
 * « confiance » : épingle le certificat que présente Larka, après l'avoir montré
 * (empreinte à comparer avec celle du navigateur : cadenas → certificat → SHA-256).
 */
function r_confiance(array $o): int
{
    $cfg = r_lire_json(R_DIR . '/config.json');
    try { [$fp, $sujet, $emetteur, $fin] = r_certificat((string)($cfg['larka'] ?? '')); }
    catch (Throwable $e) { r_erreur($e->getMessage()); return 1; }
    $joli = strtoupper(implode(':', str_split($fp, 2)));
    r_dire("Certificat de Larka : « $sujet », émis par « $emetteur », valable jusqu'au $fin");
    r_dire("Empreinte SHA-256  : $joli");
    if (($cfg['empreinte_certificat'] ?? '') === $fp) { r_dire('✓ Ce certificat est déjà celui que ce PC connaît.'); return 0; }
    if (!isset($o['oui'])) {
        if (!(function_exists('stream_isatty') && stream_isatty(STDIN))) { r_erreur('À confirmer dans une fenêtre : « ' . R_CMD . ' confiance » (ou --oui).'); return 1; }
        echo "Comparez avec le navigateur (cadenas → certificat → empreinte SHA-256). Faire confiance à ce certificat ? [o/N] ";
        if (!preg_match('/^\s*[oOyY]/', (string)fgets(STDIN))) { r_dire('Rien n\'a été changé.'); return 1; }
    }
    $cfg['empreinte_certificat'] = $fp;
    r_ecrire_json(R_DIR . '/config.json', $cfg);
    r_dire('✓ Certificat épinglé : ce PC acceptera ce certificat-là, et lui seul.');
    return 0;
}

/**
 * Détail des points lus. Tous en --bavard et en « tester » ; sinon, seulement
 * ceux qui n'ont pas pu être lus, avec la raison (« 0 / 1 point lu » sans dire
 * pourquoi laissait deviner).
 */
function r_detail_points(array $c, array $lectures, bool $tout): void
{
    $noms = array_column((array)$c['points'], 'Nom', 'Id');
    foreach ((array)$c['points'] as $p) {
        $pid = $p['Id'] ?? null;
        $l = $lectures[$pid] ?? ['erreur' => 'aucune réponse pour ce point'];
        $lu = ($l['valeur'] ?? null) !== null || ($l['texte'] ?? null) !== null;
        if ($lu && !$tout) continue;
        r_dire('     ' . ($noms[$pid] ?? $pid) . ' = ' . ($lu ? ($l['valeur'] ?? $l['texte']) : ('✕ ' . ($l['erreur'] ?? 'non lu'))));
    }
    if (!$tout && array_filter((array)$c['points'], fn($p) => !(($lectures[$p['Id'] ?? null]['valeur'] ?? null) !== null || ($lectures[$p['Id'] ?? null]['texte'] ?? null) !== null))) {
        r_dire('     (adresse ou chemin inconnu ? « ' . R_CMD . ' explorer » montre ce que l\'appareil expose)');
    }
}

// ══ Explorer un appareil sans documentation ══════════════════════════════════
//
// Quand la documentation manque (table Modbus réservée aux intégrateurs, API non
// publiée), on regarde ce que l'appareil montre de lui-même : ses ports ouverts,
// ses pages web et les adresses que ces pages interrogent (souvent du JSON avec
// les mesures affichées), et, si Modbus répond, les registres qui ressemblent à
// une tension (≈ 230 V) ou une fréquence (≈ 50 Hz). LECTURE SEULE : requêtes GET
// et lectures Modbus 03/04, jamais d'écriture, aucun identifiant essayé ; adresses
// privées seulement (l'appareil, pas Internet ni le réseau de l'entreprise).

const R_EXPLO_PORTS = [80 => 'HTTP', 443 => 'HTTPS', 8080 => 'HTTP', 8000 => 'HTTP', 502 => 'Modbus TCP', 1883 => 'MQTT', 47808 => 'BACnet/IP (UDP)'];

const R_RAISON_BLOQUE = 'bloqué sur ce PC (accès refusé : pare-feu de Windows ou antivirus)';

/**
 * Un port répond-il ? [oui/non, raison lisible]. Délai long (appareils lents sur
 * leur Wi-Fi) ; la raison distingue « fermé », « pas de réponse » et « bloqué ici ».
 */
function r_sonder_port(string $ip, int $port): array
{
    $f = @stream_socket_client("tcp://$ip:$port", $no, $msg, 4.0);
    if ($f) { fclose($f); return [true, '']; }
    $m = mb_strtolower((string)$msg);
    if ($no === 10013 || $no === 13 || str_contains($m, 'permission') || str_contains($m, 'autorisations d\'accès') || str_contains($m, 'access permissions')) return [false, R_RAISON_BLOQUE];
    if ($no === 10061 || $no === 111 || str_contains($m, 'refused') || str_contains($m, 'refus')) return [false, 'fermé (l\'appareil refuse)'];
    if ($no === 10060 || $no === 110 || $no === 0 || str_contains($m, 'timed out') || str_contains($m, 'délai')) return [false, 'pas de réponse (filtré, ou appareil lent)'];
    return [false, trim("erreur $no : $msg")];
}

/** Passerelle(s) de l'interface $nom (l'appareil, sur son propre Wi-Fi). */
function r_passerelles(string $nom): array
{
    $out = [];
    if (R_WINDOWS) {
        r_executer(['ipconfig'], $s);
        $out = r_passerelles_ipconfig((string)$s, $nom);
    } else {
        r_executer(['ip', '-4', 'route', 'show', 'default'] + ($nom !== '' ? [5 => 'dev', 6 => $nom] : []), $s);
        if (preg_match_all('/default via (\d+\.\d+\.\d+\.\d+)/', (string)$s, $m)) $out = $m[1];
    }
    return array_values(array_unique(array_filter($out, fn($ip) => (bool)filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))));
}

/**
 * Passerelles d'une carte d'après la sortie d'ipconfig (français ou anglais).
 * La passerelle IPv4 suit souvent une IPv6 sur la ligne suivante, sans libellé.
 */
function r_passerelles_ipconfig(string $s, string $nom): array
{
    $s = str_replace(["\u{00A0}", "\u{202F}", "\r"], [' ', ' ', ''], $s);
    $out = []; $section = null; $dansPasserelle = false;
    foreach (explode("\n", $s) as $l) {
        if ($l !== '' && !ctype_space($l[0])) { $section = $l; $dansPasserelle = false; continue; }
        if ($section === null || ($nom !== '' && !preg_match('/\b' . preg_quote($nom, '/') . '\s*:?\s*$/iu', trim($section)))) continue;
        if (preg_match('/(?:Passerelle par d.faut|Default Gateway)[ .]*:\s*(.*)$/iu', $l, $m)) { $out[] = trim($m[1]); $dansPasserelle = true; continue; }
        if ($dansPasserelle && preg_match('/^\s+([0-9a-f.:%]+)\s*$/i', $l, $m)) { $out[] = $m[1]; continue; }
        $dansPasserelle = false;
    }
    return $out;
}

/** GET HTTP(S) borné : [code, type, corps]. Certificat non vérifié : on lit un appareil local, sans rien lui envoyer. */
function r_http_get(string $url, int $max = 400000, string $hote = ''): array
{
    $ctx = stream_context_create([
        'http' => ['method' => 'GET', 'timeout' => 6, 'ignore_errors' => true, 'follow_location' => 0, 'max_redirects' => 0,
                   'header' => "User-Agent: Larka-releveur/" . R_VERSION . "\r\nAccept: */*\r\n" . ($hote !== '' ? "Host: $hote\r\n" : '')],
        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $corps = @file_get_contents($url, false, $ctx, 0, $max);
    $entetes = $http_response_header ?? [];
    $code = 0; $type = '';
    foreach ($entetes as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $code = (int)$m[1];
        if (stripos($h, 'content-type:') === 0) $type = trim(substr($h, 13));
        if (stripos($h, 'location:') === 0) $type .= ' → ' . trim(substr($h, 9));
    }
    return [$code, $type, $corps === false ? '' : $corps];
}

/** Lecture Modbus TCP brute (03/04) : liste de mots, ou message d'erreur. */
function r_modbus_lire($sock, int &$tid, int $unite, int $fc, int $debut, int $nb)
{
    $tid = ($tid + 1) & 0xFFFF;
    $req = pack('nnnCCnn', $tid, 0, 6, $unite, $fc, $debut, $nb);
    if (@fwrite($sock, $req) === false) return 'écriture impossible';
    $tete = @fread($sock, 7);
    if (!is_string($tete) || strlen($tete) < 7) return 'pas de réponse';
    $h = unpack('ntid/nproto/nlong/Cunite', $tete);
    $reste = '';
    while (strlen($reste) < $h['long'] - 1) {
        $b = @fread($sock, $h['long'] - 1 - strlen($reste));
        if (!is_string($b) || $b === '') return 'réponse tronquée';
        $reste .= $b;
    }
    $f = ord($reste[0]);
    if ($f & 0x80) {
        $code = ord($reste[1] ?? "\0");
        return "exception $code" . ([1 => ' (fonction non gérée)', 2 => ' (adresse illégale)', 3 => ' (valeur illégale)', 4 => ' (erreur de l\'appareil)'][$code] ?? '');
    }
    $data = substr($reste, 2);
    return array_values(unpack('n*', $data) ?: []);
}

function r_explorer(Larka $larka, array $o): int
{
    $mission = r_mission($larka, false, 20);
    $tout = (array)($mission['connexions'] ?? []);
    $choix = is_string($o['appareil'] ?? null) ? mb_strtolower((string)$o['appareil']) : '';
    $c = null;
    foreach ($tout as $x) if ($choix === '' || str_contains(mb_strtolower((string)($x['nom'] ?? '')), $choix)) { $c = $x; break; }
    if (!$c && !is_string($o['hote'] ?? null)) { r_erreur('Aucun appareil hors réseau pour ce PC' . ($choix !== '' ? " dont le nom contient « $choix »" : '') . '.'); return 1; }
    $c = $c ?? ['nom' => 'appareil', 'protocole' => '', 'acces' => 'direct'];
    $sansWifi = isset($o['sans-wifi']);
    $rapport = [];
    $note = function (string $l) use (&$rapport) { $rapport[] = $l; echo $l, "\n"; };
    $note('Exploration de « ' . $c['nom'] . ' » — ' . date('Y-m-d H:i') . ' — collecteur ' . R_VERSION . ' — lecture seule');
    $wifi = null; $carte = null; $origine = null; $nomCarte = ''; $exclus = []; $gwHotspots = [];
    try {
        if (r_acces($c) === 'hotspot' && !$sansWifi) {
            $ssid = (string)($c['wifi']['ssid'] ?? '');
            $wifi = Wifi::pour();
            if (!$wifi) { r_erreur('Changement de Wi-Fi non pris en charge ici : connectez-vous au hotspot à la main, puis « ' . R_CMD . ' explorer --sans-wifi ».'); return 2; }
            $carte = $wifi->choisir();
            if (!$carte) { r_erreur('Aucune carte Wi-Fi trouvée sur ce PC. ' . $wifi->diagnostic); return 2; }
            $nomCarte = (string)$carte['nom'];
            $exclus = r_profils_hotspots($tout);
            [$origine, $origineSrc] = $wifi->origine($carte, $exclus);
            r_journal('  réseau habituel : ' . ($origine ?? '?') . " — $origineSrc — Wi-Fi vu par {$wifi->source}");
            // Tous les hotspots du PC : r_profils retire les profils de ceux qu'on ne lui passe pas.
            $profils = r_profils($wifi, array_values(array_filter($tout, fn($x) => r_acces($x) === 'hotspot')), false);
            $gwAvant = r_passerelles($nomCarte);
            $note("→ Hotspot « $ssid »…");
            $joint = fn() => r_passerelle_hotspot($nomCarte, $gwAvant) !== '';
            if (!$wifi->connecter($profils[$ssid] ?? Wifi::nomProfil($ssid), $ssid, $nomCarte) || !$wifi->attendre($nomCarte, $ssid, 40, $joint)) {
                r_erreur("hotspot « $ssid » introuvable ou refusé (appareil éteint, hors de portée, ou clé changée ?)");
                return 1;
            }
            sleep(3);
            if (($g = r_passerelle_hotspot($nomCarte, $gwAvant)) !== '') $gwHotspots[] = $g;
        }

        $donnees = r_reconnaitre($c, $nomCarte, $gwAvant ?? [], $sansWifi, $note, is_string($o['hote'] ?? null) ? (string)$o['hote'] : null);
        if (!empty($c['id']) && $donnees['adresses']) r_recon_mettre_en_file((int)$c['id'], (string)$c['nom'], $donnees);
        $note("\nRien n'a été écrit sur l'appareil. Envoyez ce rapport à qui configure Larka : il indique quoi saisir (API REST, Modbus ou BACnet).");
    } finally {
        if ($wifi && $carte) r_rendre_reseau($wifi, $carte, $origine, $gwAvant ?? [], $larka, $gwHotspots, $exclus);
        if ($rapport) {
            $f = R_DIR . '/exploration-' . (preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)$c['nom']) ?: 'appareil') . '.txt';
            @file_put_contents($f, implode(PHP_EOL, $rapport) . PHP_EOL);
            echo "✓ Rapport : " . basename($f) . " (dans le dossier du collecteur)\n";
            r_journal('explorer : rapport ' . basename($f));
        }
    }
    // Ce que l'appareil expose part aussi à Larka (écran « Détection » : réglages et points proposés).
    if (r_lire_json(R_DIR . '/reconnaissances.json') && $larka->joignable()) r_recon_envoyer($larka);
    return 0;
}

/**
 * Regarde ce que l'appareil expose, depuis le réseau où il est joignable (son
 * hotspot, ou le câble) : ports, pages web et adresses JSON qu'elles
 * interrogent, registres Modbus. Lecture seule. $note reçoit le rapport lisible ;
 * rend les données brutes pour Larka : ['version', 'adresses' => [[ip, ports,
 * documents, modbus]]].
 */
function r_reconnaitre(array $c, string $nomCarte, array $gwAvant, bool $sansWifi, callable $note, ?string $hoteForce = null): array
{
    $out = ['version' => R_VERSION, 'adresses' => []];
    // Adresses à sonder : celle saisie dans Larka (nom résolu ici), la passerelle de la
    // carte Wi-Fi (l'appareil, sur son propre hotspot), ou celle donnée à la main.
    [$h] = r_cible($c);
    $ips = $hoteForce !== null ? [$hoteForce] : [];
    if ($h !== '') { if (filter_var($h, FILTER_VALIDATE_IP)) $ips[] = $h; else foreach (gethostbynamel($h) ?: [] as $ip) $ips[] = $ip; }
    if ($nomCarte !== '' && ($g = r_passerelle_hotspot($nomCarte, $gwAvant)) !== '') { $ips[] = $g; $note("Passerelle du hotspot (l'appareil, en général) : $g"); }
    $ips = array_values(array_unique(array_filter($ips, fn($ip) => r_adresse_permise($ip, $sansWifi))));
    if (!$ips) { $note('✕ Aucune adresse privée à sonder (passerelle introuvable). Précisez-la : ' . R_CMD . ' explorer --hote=192.168.x.y'); return $out; }
    if ($h !== '' && !filter_var($h, FILTER_VALIDATE_IP)) $note("Nom « $h » → " . (implode(', ', gethostbynamel($h) ?: []) ?: 'non résolu'));
    $note('Adresse(s) sondée(s) : ' . implode(', ', $ips));
    // Chemins déjà connus de Larka (API REST réglée) : essayés eux aussi.
    $prm = (array)($c['parametres'] ?? []);
    $connus = array_values(array_filter([(string)($prm['url_liste'] ?? ''), str_contains((string)($prm['url_point'] ?? ''), '{') ? '' : (string)($prm['url_point'] ?? '')],
                                        fn($p) => $p !== '' && str_starts_with($p, '/')));
    foreach (array_slice($ips, 0, 4) as $ip) {
        $note("\n══ $ip ══");
        $ouverts = []; $raisons = [];
        foreach (R_EXPLO_PORTS as $port => $quoi) {
            if ($port === 47808) continue;
            [$okP, $raison] = r_sonder_port($ip, $port);
            if ($okP) { $ouverts[] = $port; $note("  port $port ($quoi) : ouvert"); }
            else { $raisons[$raison][] = $port; }
        }
        foreach ($raisons as $raison => $ports) $note('  port(s) ' . implode(', ', $ports) . " : $raison");
        if (!$ouverts && isset($raisons[R_RAISON_BLOQUE])) {
            $note('  ⚠ Windows (pare-feu ou antivirus) empêche le PHP du collecteur de joindre cet appareil, alors que');
            $note('    PowerShell y arrive peut-être (Test-NetConnection). Faites autoriser « php\\php.exe » du dossier du collecteur');
            $note('    (règle de pare-feu sortante, ou exception de l\'antivirus), ou demandez-le au service informatique.');
        }
        $docs = []; $mb = null;
        // Web : pages, scripts, et les adresses que ces scripts interrogent. HTTPS seulement si HTTP ne répond pas.
        $webs = array_values(array_intersect([80, 8080, 8000], $ouverts));
        if (!$webs && in_array(443, $ouverts, true)) $webs = [443];
        foreach ($webs as $port) {
            $base = ($port === 443 ? 'https' : 'http') . "://$ip" . (in_array($port, [80, 443], true) ? '' : ":$port");
            $hoteNom = ($h !== '' && !filter_var($h, FILTER_VALIDATE_IP)) ? $h : '';
            r_explorer_web($base, $hoteNom, $note, $docs, $connus);
        }
        if (in_array(502, $ouverts, true)) r_explorer_modbus($ip, (int)($prm['unite_defaut'] ?? 1), $note, $mb);
        // BACnet/IP (UDP : pas de port « ouvert » à sonder) : l'automate dit lui-même qui il est et
        // quels sont ses points (nom, unité, valeur) — de quoi tout proposer dans Larka.
        $bn = null;
        $portBn = ($c['protocole'] ?? '') === 'bacnet' && (int)($c['port'] ?? 0) > 0 ? (int)$c['port'] : 47808;
        r_explorer_bacnet($ip, $portBn, (string)($c['identifiant'] ?? ''), $note, $bn);
        $out['adresses'][] = ['ip' => $ip, 'ports' => $ouverts, 'documents' => $docs, 'modbus' => $mb, 'bacnet' => $bn];
    }
    return $out;
}

/** Détection pendant un relevé : rapport lisible dans exploration-….txt, données en file pour Larka. */
function r_reconnaitre_silencieux(array $c, string $nomCarte, array $gwAvant, bool $sansWifi): void
{
    $lignes = [];
    $note = function (string $l) use (&$lignes) { $lignes[] = $l; };
    r_dire("  🔎 {$c['nom']} : détection de ce que l'appareil expose…");
    try {
        $d = r_reconnaitre($c, $nomCarte, $gwAvant, $sansWifi, $note);
    } catch (Throwable $e) {
        r_erreur("détection de « {$c['nom']} » : " . $e->getMessage());
        return;
    }
    @file_put_contents(R_DIR . '/exploration-' . (preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)$c['nom']) ?: 'appareil') . '.txt',
        "Détection de « {$c['nom']} » — " . date('Y-m-d H:i') . ' — collecteur ' . R_VERSION . " — lecture seule\n" . implode(PHP_EOL, $lignes) . PHP_EOL);
    $docs = array_sum(array_map(fn($a) => count($a['documents']), $d['adresses']));
    $ports = array_unique(array_merge(...array_map(fn($a) => $a['ports'], $d['adresses'] ?: [['ports' => []]])));
    $bn = array_values(array_filter(array_map(fn($a) => $a['bacnet'] ?? null, $d['adresses'])))[0] ?? null;
    $fichier = 'exploration-' . (preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)$c['nom']) ?: 'appareil') . '.txt';
    r_dire('     ' . ($ports ? 'ports ouverts : ' . implode(', ', $ports) : 'aucun port TCP ouvert') . ($docs ? ", $docs réponse(s) JSON" : ', aucune réponse JSON')
           . ($bn ? ", BACnet : automate n° {$bn['instance']}, " . count($bn['objets']) . ' point(s)' : '') . " — détail : $fichier");
    if ($d['adresses']) {
        r_recon_mettre_en_file((int)$c['id'], (string)$c['nom'], $d);
        r_dire('     → part à Larka au retour sur le réseau habituel, puis 🔎 dans Larka (Connexions → 📶 Hors réseau)');
    } else {
        r_dire("     ✕ aucune adresse à sonder : rien à envoyer (voir $fichier)");
    }
}

/** Rapport de détection gardé jusqu'à ce que Larka réponde (le PC est souvent encore sur le hotspot). */
function r_recon_mettre_en_file(int $id, string $nom, array $donnees): void
{
    $file = r_lire_json(R_DIR . '/reconnaissances.json');
    $file[(string)$id] = ['connexion' => $id, 'nom' => $nom, 'rapport' => $donnees];
    r_ecrire_json(R_DIR . '/reconnaissances.json', $file);
}

/** Envoie les rapports de détection en attente ; ceux que Larka refuse sont abandonnés (dit). */
function r_recon_envoyer(Larka $larka): void
{
    $file = r_lire_json(R_DIR . '/reconnaissances.json');
    foreach ($file as $cle => $r) {
        try {
            $rep = $larka->appeler('POST', 'reconnaissance', ['connexion' => (int)$r['connexion'], 'rapport' => $r['rapport']], 60);
            if (!empty($rep['ok'])) {
                r_dire('✓ Détection de « ' . $r['nom'] . ' » envoyée à Larka' . (!empty($rep['proposition']) ? ' : ' . $rep['proposition'] . ', ' . (int)($rep['points'] ?? 0) . ' point(s) proposé(s)' : ' (rien d\'exploitable trouvé)')
                       . ' — à valider dans Larka (Connexions → 📶 Hors réseau → 🔎).');
            } else {
                r_erreur('Détection de « ' . $r['nom'] . ' » refusée par Larka : ' . ($rep['message'] ?? '?'));
            }
            unset($file[$cle]);
        } catch (Throwable $e) {
            r_erreur('Détection de « ' . $r['nom'] . ' » non envoyée (' . $e->getMessage() . ') : gardée pour le prochain passage.');
            break;
        }
    }
    r_ecrire_json(R_DIR . '/reconnaissances.json', $file);
}

/**
 * Rendre au PC son réseau habituel après un passage sur un hotspot. On juge au
 * résultat : la carte Wi-Fi a une passerelle qui n'est pas celle du hotspot (celle
 * d'avant, ou une autre avec Larka joignable). Dans l'ordre : le profil d'origine
 * (r_collecter l'a identifié sans dépendre de la localisation de Windows) ; quitter
 * le hotspot pour que Windows rejoigne de lui-même son réseau préféré ; les autres
 * profils du PC, dans l'ordre de préférence de Windows. Chaque essai est noté au
 * journal (releveur.log) : de quoi comprendre un retour difficile.
 */
function r_rendre_reseau(Wifi $wifi, array $carte, ?string $origine, array $gwAvant, ?Larka $larka, array $gwHotspots = [], array $exclus = []): bool
{
    $nom = (string)$carte['nom'];
    if (!empty($carte['libre'])) { $wifi->revenir(null, $nom); return true; }   // la carte libre redevient libre
    r_dire($origine !== null ? "← Retour sur « $origine »" : '← Retour sur le réseau habituel');
    $revenu = function () use ($nom, $gwAvant, $gwHotspots, $larka): bool {
        $g = r_passerelles($nom);
        if (!$g || array_intersect($g, $gwHotspots)) return false;          // pas encore d'adresse, ou toujours sur le hotspot
        if (!$gwAvant || array_intersect($g, $gwAvant)) return true;
        return $larka !== null && $larka->joignable();                      // réseau habituel par une autre borne
    };
    $attendre = function (int $s) use ($revenu): bool { $fin = time() + $s; do { if ($revenu()) return true; sleep(2); } while (time() < $fin); return false; };
    $essai = function (?string $profil, int $s, string $quoi) use ($wifi, $nom, $attendre): bool {
        $t0 = time();
        $ok = $wifi->revenir($profil, $nom);
        if (!$ok && $profil !== null) { r_journal("  retour : $quoi — refusé par Windows"); return false; }   // profil inconnu : inutile d'attendre
        $r = $attendre($s);
        r_journal("  retour : $quoi — " . ($r ? 'réussi en ' . (time() - $t0) . ' s' : "sans effet après {$s} s"));
        return $r;
    };
    if ($origine !== null && $essai($origine, 45, "profil « $origine »")) return true;
    if ($essai(null, 25, 'hotspot quitté, Windows choisit son réseau')) { r_dire('  (réseau habituel repris par Windows)'); return true; }
    $n = 0;
    foreach ($wifi->profilsConnus() as $p) {
        if ($p === $origine || in_array($p, $exclus, true) || str_starts_with($p, 'Larka - ')) continue;
        if (++$n > 4) break;
        if ($essai($p, 20, "profil « $p »")) { r_dire("  (réseau « $p » repris)"); return true; }
    }
    r_journal('  retour manqué — passerelle(s) de la carte : ' . (implode(', ', r_passerelles($nom)) ?: 'aucune') . ' ; avant : ' . (implode(', ', $gwAvant) ?: 'aucune'));
    r_erreur('Le PC n\'a pas retrouvé son réseau habituel : reconnectez-le à la main (icône Wi-Fi). Les relevés et la détection attendent sur le PC et partiront au prochain passage. '
           . 'Pour comprendre : « ' . R_CMD . ' etat » (ce que le PC voit du Wi-Fi) et releveur.log.');
    return false;
}

/** Adresses qui ressemblent à une action : jamais appelées par l'exploration. */
const R_EXPLO_ACTIONS = '#(?:generate|modify|change|reset|reboot|restart|factory|delete|remove|erase|clear|format|update|upgrade|firmware|write|set[A-Z_/]|/set\b|save|apply|commit|login|logout|password|passwd|pwd|code|token|key|license|licence|command|cmd|exec|action|start|stop)#i';

/** « /a/b/../c » → « /a/c » (liens relatifs des pages). */
function r_chemin_normal(string $p): string
{
    $out = [];
    foreach (explode('/', $p) as $s) {
        if ($s === '' || $s === '.') continue;
        if ($s === '..') { array_pop($out); continue; }
        $out[] = $s;
    }
    return '/' . implode('/', $out);
}

function r_explorer_web(string $base, string $hoteNom, callable $note, array &$docs = [], array $connus = []): void
{
    [$code, $type, $html] = r_http_get("$base/", 400000, $hoteNom);
    $titre = preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m) ? trim(html_entity_decode(strip_tags($m[1]))) : '';
    $note("  $base/ → HTTP $code" . ($type ? " ($type)" : '') . ($titre !== '' ? " — « $titre »" : '') . ' — ' . strlen($html) . ' octets');
    if ($code === 0) return;
    // Pages et scripts de l'appareil, de proche en proche : les mesures s'affichent
    // souvent sur une autre page que l'accueil (« visualisation.html »), avec son
    // propre script. Bibliothèques connues ignorées (jquery, bootstrap…).
    $textes = ['/' => $html];
    $file = ['/' => $html];
    $vus = ['/' => true];
    while ($file && count($vus) < 40) {
        $page = array_key_first($file); $contenu = array_shift($file);
        if (!preg_match_all('#(?:src|href)\s*=\s*["\']([^"\'\#?]+)(?:\?[^"\']*)?["\']#i', $contenu, $m)) continue;
        foreach (array_unique($m[1]) as $l) {
            if (preg_match('#^(?:[a-z]+:)?//#i', $l) || !preg_match('/\.(?:js|html?|php|cgi)$/i', $l)) continue;
            if (preg_match('#(?:jquery|bootstrap|i18next|moment|chart|d3|highcharts|plotly)[^/]*\.js$#i', $l)) continue;
            // Chemin relatif résolu à la main : dirname() rend « \ » sous Windows (« \/visualisation.html »).
            $chemin = r_chemin_normal(str_starts_with($l, '/') ? $l : substr($page, 0, strrpos($page, '/') + 1) . $l);
            if (isset($vus[$chemin]) || count($vus) >= 40) continue;
            $vus[$chemin] = true;
            [$c2, $t2, $b2] = r_http_get($base . $chemin, 600000, $hoteNom);
            $note("    $chemin → HTTP $c2" . ($t2 ? " ($t2)" : '') . ' — ' . strlen($b2) . ' octets');
            if ($c2 >= 200 && $c2 < 300) { $textes[$chemin] = $b2; if (preg_match('/\.html?$/i', $chemin)) $file[$chemin] = $b2; }
        }
    }

    // Comment les pages obtiennent leurs mesures : adresses d'API (même assemblées
    // par le script : « /api/connector/ » + n), WebSocket, EventSource.
    $cands = []; $contextes = [];
    $motif = '#["\'`](/?(?:[\w\-]+/)*[\w\-]*(?:api|json|cgi|data|donnee|mesure|measure|metering|value|valeur|live|realtime|instant|energ|power|puiss|meter|compteur|status|statut|ajax|get|read|lecture|sensor|channel|depart|circuit|connector|connecteur)[\w\-/.]*(?:\?[\w=&\-]*)?)["\'`]#i';
    foreach ($textes as $origine => $t) {
        if (preg_match_all($motif, $t, $m)) foreach ($m[1] as $p) {
            if (preg_match('/\.(?:js|css|png|jpe?g|gif|svg|ico|woff2?|ttf|map|html?)$/i', $p) || strlen($p) > 120 || !preg_match('#/|\.json|\.cgi#', $p)) continue;
            $p = '/' . ltrim($p, '/');
            $cands[$p] = true;
            if (str_ends_with($p, '/')) { $cands[$p . '1'] = true; $cands[$p . '0'] = true; }   // « /api/x/ » + numéro
        }
        // « "/api/connector/" + i + "/measures" » : reconstitué avec 1 (et 0) à la place de la variable.
        if (preg_match_all('#["\'](/[\w\-/.]*)["\']\s*\+\s*[\w.$\[\]()]+\s*\+\s*["\']([\w\-/.?=&]*)["\']#', $t, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) foreach (['1', '0'] as $num) $cands[$x[1] . $num . $x[2]] = true;
        }
        // Contexte sur une seule ligne : un appel jQuery s'étale souvent sur plusieurs.
        $plat = preg_replace('/\s+/', ' ', $t);
        if (preg_match('/\.js$|\.html?$|^\/$/i', $origine) && preg_match_all('#.{0,60}(?:/api/|WebSocket\s*\(|wss?://|EventSource\s*\(|socket\.io).{0,140}#', $plat, $m)) {
            foreach ($m[0] as $x) {
                $x = trim(preg_replace('/\s+/', ' ', $x));
                if (count($contextes) < 40 && !in_array($x, $contextes, true)) $contextes[] = "[$origine] $x";
            }
        }
    }
    if ($contextes) {
        $note('  Appels repérés dans les scripts (pour reconstituer les adresses) :');
        foreach ($contextes as $x) $note("    $x");
    }
    foreach ($connus as $g) $cands[$g] = true;                              // adresses déjà réglées dans Larka
    foreach (['/api', '/api/data', '/api/values', '/api/measures', '/data.json', '/values.json', '/status.json', '/api/status'] as $g) $cands[$g] ??= true;
    // Lecture seule, vraiment : une adresse qui ressemble à une action (générer un
    // code, changer un mot de passe, redémarrer…) n'est PAS appelée, même en GET —
    // certains appareils agissent sur un simple GET. Elle est seulement citée.
    $actions = array_values(array_filter(array_keys($cands), fn($p) => (bool)preg_match(R_EXPLO_ACTIONS, $p)));
    foreach ($actions as $p) unset($cands[$p]);
    if ($actions) $note('  Non appelées (ressemblent à des actions) : ' . implode(', ', $actions));
    $note('  Adresses essayées en GET (' . count($cands) . ') :');
    $n = 0; $pareil = 0; $absentes = 0;
    foreach (array_keys($cands) as $p) {
        if (++$n > 60) { $note('    … (liste tronquée)'); break; }
        [$c3, $t3, $b3] = r_http_get($base . $p, 400000, $hoteNom);
        if ($c3 === 404 || $c3 === 0 || $c3 >= 500) { $absentes++; continue; }
        if ($b3 === $html) { $pareil++; continue; }                          // l'appareil renvoie sa page d'accueil pour tout
        $extrait = trim(preg_replace('/\s+/', ' ', mb_substr($b3, 0, 700)));
        $json = json_decode($b3, true);
        if (is_array($json) && $c3 >= 200 && $c3 < 300 && count($docs) < 12) $docs[] = ['url' => $base . $p, 'code' => $c3, 'type' => $t3, 'corps' => $b3];
        $note("    $p → HTTP $c3" . ($t3 ? " ($t3)" : '') . ($json !== null ? ' — JSON' : '') . ($extrait !== '' ? "\n        $extrait" : ''));
    }
    if ($pareil) $note("    ($pareil autre(s) adresse(s) renvoient simplement la page d'accueil)");
    if ($absentes) $note("    ($absentes adresse(s) sans réponse utile : 404 ou erreur du serveur)");
}

/**
 * BACnet/IP : qui est l'automate (n°, nom, fabricant, modèle) et la liste de ses points
 * (nom, unité, valeur), lue en lui avec le pilote de Larka. Lecture seule (ReadProperty).
 */
function r_explorer_bacnet(string $ip, int $port, string $instance, callable $note, ?array &$res = null): void
{
    if (!class_exists('ConnectPiloteBacnet')) return;
    $p = new ConnectPiloteBacnet(['Id' => 0, 'Nom' => 'détection', 'Protocole' => 'bacnet', 'Hote' => $ip, 'Port' => $port,
                                  'Identifiant' => ctype_digit($instance) ? $instance : '', 'params' => ['delai_secondes' => 1.5], 'secret' => []]);
    try {
        $id = $p->identite();
        if ($id['numero'] === null) { $note("  BACnet/IP (UDP $port) : pas de réponse"); return; }
        $note("  BACnet/IP (UDP $port) : automate n° {$id['numero']}" . ($id['nom'] !== '' ? " « {$id['nom']} »" : '')
              . (trim($id['fabricant'] . ' ' . $id['modele']) !== '' ? ' — ' . trim($id['fabricant'] . ' ' . $id['modele']) : '')
              . ($id['emplacement'] !== '' ? " — {$id['emplacement']}" : ''));
        $t0 = microtime(true);
        $objets = $p->decouvrir();
        $note('    ' . count($objets) . ' point(s) lu(s) en ' . round(microtime(true) - $t0) . ' s');
        foreach (array_slice($objets, 0, 400) as $o) {
            $v = $o['valeur'];
            $note('    ' . str_pad((string)$o['code'], 24) . ' ' . $o['nom'] . ' = ' . (is_float($v) ? round($v, 3) : (is_scalar($v) ? (string)$v : '—'))
                  . ($o['unite'] !== '' ? ' ' . $o['unite'] : ''));
        }
        $res = ['port' => $port, 'instance' => (int)$id['numero'], 'nom' => (string)$id['nom'], 'fabricant' => (string)$id['fabricant'],
                'modele' => (string)$id['modele'], 'emplacement' => (string)$id['emplacement'], 'objets' => $objets];
    } catch (Throwable $e) {
        $note("  BACnet/IP (UDP $port) : " . $e->getMessage());
    } finally {
        try { $p->fermer(); } catch (Throwable $e) {}
    }
}

function r_explorer_modbus(string $ip, int $unite, callable $note, ?array &$res = null): void
{
    $unites = array_values(array_unique([$unite, 1, 255, 0]));
    $note('  Modbus TCP : lecture des registres 0 à 1999 (03 et 04), unit id ' . implode(', puis ', $unites) . ' si besoin');
    $sock = @stream_socket_client("tcp://$ip:502", $en, $es, 3);
    if (!$sock) { $note('    connexion refusée'); return; }
    stream_set_timeout($sock, 3);
    $tid = 0;
    foreach ([3 => 'de maintien (03)', 4 => 'd\'entrée (04)'] as $fc => $nomFc) {
        $mots = []; $erreurs = []; $uOk = null;
        foreach ($unites as $u) {
            $r = r_modbus_lire($sock, $tid, $u, $fc, 0, 10);
            if (is_array($r)) { $uOk = $u; break; }
            $erreurs[] = "unit $u : $r";
            if ($r === 'pas de réponse' || $r === 'réponse tronquée') { @fclose($sock); $sock = @stream_socket_client("tcp://$ip:502", $en, $es, 3); if (!$sock) return; stream_set_timeout($sock, 3); }
        }
        if ($uOk === null) { $note("    registres $nomFc : " . implode(' ; ', $erreurs)); continue; }
        for ($d = 0; $d < 2000; $d += 100) {
            $r = r_modbus_lire($sock, $tid, $uOk, $fc, $d, 100);
            if (is_array($r)) { foreach ($r as $i => $v) $mots[$d + $i] = $v; continue; }
            if ($r === 'pas de réponse' || $r === 'réponse tronquée') break;
            // Bloc refusé (trou dans la table) : par 10, pour ne pas perdre ce qui l'entoure.
            for ($e = $d; $e < $d + 100; $e += 10) { $r2 = r_modbus_lire($sock, $tid, $uOk, $fc, $e, 10); if (is_array($r2)) foreach ($r2 as $i => $v) $mots[$e + $i] = $v; }
        }
        $nonNuls = array_filter($mots, fn($v) => $v !== 0);
        $note("    registres $nomFc (unit $uOk) : " . count($mots) . ' lus, ' . count($nonNuls) . ' non nuls');
        if ($res === null && $nonNuls) $res = ['unite' => $uOk, 'table' => $fc === 4 ? 'input' : 'holding', 'registres' => $mots];
        if (!$mots) continue;
        $cand = [];
        foreach ($mots as $i => $v) {
            if ($v >= 2100 && $v <= 2550) $cand[] = "$i : $v → tension ×0,1 ? (" . ($v / 10) . ' V)';
            elseif ($v >= 210 && $v <= 255) $cand[] = "$i : $v → tension (V) ?";
            elseif ($v >= 4950 && $v <= 5050) $cand[] = "$i : $v → fréquence ×0,01 ? (" . ($v / 100) . ' Hz)';
            elseif ($v >= 495 && $v <= 505) $cand[] = "$i : $v → fréquence ×0,1 ? (" . ($v / 10) . ' Hz)';
            if (isset($mots[$i + 1])) {
                foreach (['ABCD' => [$v, $mots[$i + 1]], 'CDAB' => [$mots[$i + 1], $v]] as $ordre => [$a, $b]) {
                    $f = unpack('G', pack('nn', $a, $b))[1];
                    if (is_finite($f) && (($f >= 200 && $f <= 260) || ($f >= 49.5 && $f <= 50.5)))
                        $cand[] = "$i-" . ($i + 1) . " : réel 32 bits $ordre = " . round($f, 2) . ($f > 100 ? ' → tension ?' : ' → fréquence ?');
                }
            }
        }
        if ($cand) { $note('    ⇒ candidats :'); foreach (array_slice($cand, 0, 40) as $l) $note("       $l"); }
        $note('    premiers registres non nuls : ' . implode(', ', array_map(fn($k, $v) => "$k=$v", array_slice(array_keys($nonNuls), 0, 60), array_slice($nonNuls, 0, 60))));
    }
    if ($sock) @fclose($sock);
}

// ══ Point d'entrée ═════════════════════════════════════════════════════════

[$pos, $o] = r_options($argv);
$commande = strtolower($pos[0] ?? 'aide');
$cfg = r_lire_json(R_DIR . '/config.json');
if (!in_array($commande, ['aide', 'help'], true) && (empty($cfg['larka']) || empty($cfg['jeton']))) {
    r_erreur('config.json incomplet : téléchargez de nouveau le collecteur depuis Larka (Connexions → 📶 Hors réseau).');
    exit(1);
}
if (is_string($o['larka'] ?? null)) { $cfg['larka'] = rtrim($o['larka'], '/'); r_ecrire_json(R_DIR . '/config.json', $cfg); }
$larka = new Larka((string)($cfg['larka'] ?? ''), (string)($cfg['jeton'] ?? ''), (string)($cfg['empreinte_certificat'] ?? ''));
r_heure(r_lire_json(R_DIR . '/mission.json'));

if (!in_array($commande, ['aide', 'help', 'retirer'], true)) r_nettoyer_taches_une_fois();

// Un seul relevé à la fois (deux fenêtres lancées ensemble).
$verrou = fopen(R_DIR . '/releveur.lock', 'c');
if (in_array($commande, ['collecter', 'tester', 'envoyer', 'configurer', 'explorer'], true) && !flock($verrou, LOCK_EX | LOCK_NB)) {
    r_erreur('Un relevé est déjà en cours sur ce PC.');
    exit(1);
}
if (!(isset($o['planifie']) && isset($o['direct-seulement']))) r_journal('— ' . $commande . ' —');

$code = match ($commande) {
    'configurer' => r_configurer($larka, $o),
    'collecter'  => r_collecter($larka, $o, true),
    'tester'     => r_collecter($larka, $o, false),
    'envoyer'    => r_envoyer($larka),
    'etat', 'reseau' => r_etat($larka),
    'retirer'    => r_retirer(),
    'confiance'  => r_confiance($o),
    'explorer'   => r_explorer($larka, $o),
    'materiel', 'matériel' => r_materiel(),
    default      => (function () { echo preg_replace('/^ \* ?/m', '', (preg_match('#/\*\*\n(.*?)\*/#s', (string)file_get_contents(__FILE__), $m) ? $m[1] : '')); return 0; })(),
};
exit($code);

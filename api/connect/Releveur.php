<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka Connect — PC releveurs : les appareils joignables par leur seul hotspot Wi-Fi.
 *
 * Certains appareils (onduleurs, enregistreurs, passerelles radio) n'offrent
 * qu'un point d'accès Wi-Fi à eux. Le serveur ne peut pas les joindre, et une
 * carte Wi-Fi ne se connecte qu'à un réseau à la fois. Un PC « releveur » fait
 * donc le relevé hors ligne : il se connecte au hotspot, lit l'appareil avec
 * les mêmes pilotes que le serveur, revient sur son réseau habituel, puis
 * dépose ici les valeurs, avec leur heure de lecture (sources du collecteur :
 * api/connect/releveur/ — sous api/, pour être livrées partout, production comprise ;
 * deploy/ n'est pas copié en production).
 *
 * JETON : « lrk1.<organisation>.<id>.<secret> ». Seule l'empreinte SHA-256 du
 * secret est gardée ; télécharger de nouveau le collecteur pose un nouveau
 * jeton et l'ancien cesse de marcher. Le jeton n'ouvre que deux choses :
 * la « mission » (ce qu'il faut relever, avec les clés Wi-Fi et identifiants
 * des connexions confiées à CE PC) et le « dépôt » des valeurs de ces
 * connexions-là. Rien d'autre dans Larka.
 */

declare(strict_types=1);

final class ConnectReleveur
{
    public const PREFIXE = 'lrk1';
    /** Relevés acceptés dans un dépôt, et ancienneté maximale d'un relevé en attente sur le PC. */
    public const MAX_RELEVES = 500;
    public const MAX_JOURS = 90;

    /** Jeton neuf pour un PC releveur (le secret en est la dernière partie). */
    public static function nouveauJeton(string $organisation, int $id): string
    {
        return self::PREFIXE . '.' . $organisation . '.' . $id . '.' . bin2hex(random_bytes(24));
    }

    /**
     * Authentifie un jeton : [base, dépôt, releveur, organisation], ou une
     * erreur [null, message]. Comparaison à temps constant.
     */
    public static function authentifier(string $jeton): array
    {
        if (!preg_match('/^' . self::PREFIXE . '\.([A-Za-z0-9_-]{1,64})\.(\d{1,9})\.([a-f0-9]{48})$/', $jeton, $m)) return [null, 'Jeton illisible.'];
        [, $org, $id] = $m;
        if (!Connect::actifServeur()) return [null, 'Larka Connect est désactivé sur ce serveur.'];
        foreach (Connect::organisations() as $o) {
            if ($o['cle'] !== $org) continue;
            $db = $o['base'] === null ? new Database() : new Database($o['base']);
            $depot = new ConnectDepot($db);
            $r = $depot->releveur((int)$id);
            // L'empreinte est celle du jeton entier (ConnectDepot::poserJetonReleveur).
            // Jeton vérifié d'abord : un inconnu n'apprend rien de l'organisation.
            if (!$r || empty($r['JetonHash']) || !hash_equals((string)$r['JetonHash'], hash('sha256', $jeton))) return [null, 'Jeton refusé.'];
            if (!Connect::actifPour($db)) return [null, 'Larka Connect est désactivé pour cette organisation.'];
            if (!(int)$r['Actif']) return [null, 'Ce PC releveur est désactivé dans Larka.'];
            return [[$db, $depot, $r, $org], null];
        }
        return [null, 'Jeton refusé.'];
    }

    /** Version du collecteur que Larka distribue (celle du prochain téléchargement). */
    public static function versionCollecteur(): string
    {
        $src = (string)@file_get_contents(__DIR__ . '/releveur/releveur.php', false, null, 0, 8000);
        return preg_match("/const R_VERSION = '([^']+)'/", $src, $m) ? $m[1] : '';
    }

    /** Version la plus ancienne qui sait faire la détection (et l'envoyer). */
    public const VERSION_DETECTION = '1.4';

    /** PC releveurs qui relèvent cette connexion : [['Id', 'Nom', 'Version', 'DernierContact', 'AJour'], …]. */
    public static function releveursDe(Database $db, int $passerelleId): array
    {
        $out = [];
        foreach ($db->fetchAll("SELECT * FROM ConnectReleveurs WHERE Actif = 1 ORDER BY Nom") as $r) {
            if (!isset(self::connexions($db, $r)[$passerelleId])) continue;
            $v = $r['Version'] ?? null;
            $out[] = ['Id' => (int)$r['Id'], 'Nom' => $r['Nom'], 'Version' => $v, 'DernierContact' => $r['DernierContact'],
                      'Detection' => ConnectDepot::versionAuMoins($v, self::VERSION_DETECTION)];
        }
        return $out;
    }

    /** Connexions hors réseau (hotspot ou câble direct) confiées à ce PC (toutes, si sa liste est vide). */
    public static function connexions(Database $db, array $releveur): array
    {
        $ids = array_filter(array_map('intval', explode(',', (string)($releveur['Connexions'] ?? ''))));
        $out = [];
        foreach ($db->fetchAll("SELECT * FROM ConnectPasserelles WHERE Actif = 1 ORDER BY Nom") as $pa) {
            if (!ConnectDepot::enHotspot($pa)) continue;
            if ($ids && !in_array((int)$pa['Id'], $ids, true)) continue;
            $out[(int)$pa['Id']] = $pa;
        }
        return $out;
    }

    /** Ce que le PC doit relever : connexions, Wi-Fi, points (adresses dans l'appareil). */
    public static function mission(Database $db, ConnectDepot $depot, array $releveur, string $organisation): array
    {
        $cx = [];
        foreach (self::connexions($db, $releveur) as $id => $pa) {
            $c = $depot->connexionPilote($pa, $organisation);
            $params = $c['params'];
            $secret = $c['secret'];
            unset($secret['_illisible']);
            $acces = ConnectDepot::accesHorsReseau($pa);
            // Câble direct : pas de Wi-Fi à rejoindre, le collecteur relève quand l'appareil répond.
            $wifi = $acces === 'hotspot'
                ? ['ssid' => (string)($params['wifi_ssid'] ?? ''), 'securite' => (string)($params['wifi_securite'] ?? 'wpa2'), 'cle' => (string)($secret['wifi_cle'] ?? '')]
                : null;
            unset($params['acces'], $params['wifi_ssid'], $params['wifi_securite'], $secret['wifi_cle']);
            $points = [];
            foreach ($depot->points($id, true) as $p) {
                $points[] = ['Id' => (int)$p['Id'], 'Nom' => (string)$p['Nom'], 'Code' => (string)($p['Code'] ?? ''), 'Nature' => (string)($p['Nature'] ?? 'mesure'),
                             'Unite' => (string)($p['Unite'] ?? ''), 'Adresse' => is_array($p['Adresse'] ?? null) ? $p['Adresse'] : (json_decode((string)($p['Adresse'] ?? ''), true) ?: [])];
            }
            $cx[] = ['id' => $id, 'nom' => (string)$pa['Nom'], 'protocole' => (string)$pa['Protocole'], 'hote' => (string)($pa['Hote'] ?? ''),
                     'port' => (int)($pa['Port'] ?? 0), 'identifiant' => (string)($pa['Identifiant'] ?? ''), 'parametres' => $params,
                     'secrets' => $secret, 'acces' => $acces, 'wifi' => $wifi, 'intervalle' => (int)$pa['IntervalleSecondes'], 'points' => $points,
                     // Détection demandée (ou protocole encore inconnu) : le PC regarde ce que l'appareil expose.
                     'reconnaissance' => $pa['Protocole'] === 'auto' || !empty($pa['ReconDemandee'])];
        }
        return ['releveur' => ['id' => (int)$releveur['Id'], 'nom' => (string)$releveur['Nom']], 'organisation' => $organisation,
                'connexions' => $cx, 'maintenant' => date('Y-m-d H:i:s'), 'fuseau' => date_default_timezone_get(),
                // Horloge du serveur : le PC en déduit l'écart de la sienne et date ses relevés à l'heure de Larka.
                'horloge' => time()];
    }

    /**
     * Rapport de détection d'un appareil confié à ce PC (op « reconnaissance ») :
     * réduit, borné, gardé pour l'écran « Détection ». Rien n'est appliqué ici.
     */
    public static function recevoirReconnaissance(Database $db, ConnectDepot $depot, array $releveur, array $corps): array
    {
        $id = (int)($corps['connexion'] ?? 0);
        $permises = self::connexions($db, $releveur);
        if (!isset($permises[$id])) return ['ok' => false, 'message' => "connexion $id : non confiée à ce PC"];
        [$rapport, $err] = ConnectReconnaissance::normaliser($corps['rapport'] ?? null);
        if ($err !== null) return ['ok' => false, 'message' => $err];
        $depot->reconEnregistrer($id, $rapport);
        Connect::journal('info', "PC releveur « {$releveur['Nom']} » : détection reçue pour « {$permises[$id]['Nom']} »");
        $an = ConnectReconnaissance::analyser($rapport);
        return ['ok' => true, 'proposition' => $an['suggestion']['libelle'] ?? null, 'points' => count($an['points'])];
    }

    /**
     * Dépôt des relevés : chacun passe par le même chemin qu'une collecte
     * (ConnectCollecte::releve). Un relevé d'une connexion non confiée à ce PC,
     * trop ancien ou mal daté est refusé, sans bloquer les autres.
     */
    public static function deposer(Database $db, ConnectDepot $depot, array $releveur, string $organisation, array $corps): array
    {
        $permises = self::connexions($db, $releveur);
        $releves = array_slice(array_values(array_filter((array)($corps['releves'] ?? []), 'is_array')), 0, self::MAX_RELEVES);
        usort($releves, fn($a, $b) => strcmp((string)($a['quand'] ?? ''), (string)($b['quand'] ?? '')));
        $alarmes = new ConnectAlarmes($depot, $organisation);
        $maintenant = time();
        $acceptes = 0; $refus = []; $erreurs = [];
        foreach ($releves as $r) {
            $id = (int)($r['connexion'] ?? 0);
            // « ts » (horodatage Unix, corrigé de l'écart d'horloge du PC) l'emporte : le fuseau
            // du PC n'a pas à être celui de Larka. « quand » (heure locale) en secours.
            if (isset($r['ts']) && is_numeric($r['ts']) && (int)$r['ts'] > 0) {
                $t = (int)$r['ts'];
                $quand = date('Y-m-d H:i:s', $t);
            } else {
                $quand = (string)($r['quand'] ?? '');
                $t = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $quand) ? strtotime($quand) : false;
            }
            if (!isset($permises[$id])) { $refus[] = "connexion $id : non confiée à ce PC (ou plus en hotspot)"; continue; }
            if ($t === false || $t < $maintenant - self::MAX_JOURS * 86400) { $refus[] = "connexion $id : relevé mal daté ou trop ancien ($quand)"; continue; }
            if ($t > $maintenant + 300) $quand = date('Y-m-d H:i:s', $maintenant);   // horloge du PC en avance
            $erreur = isset($r['erreur']) && $r['erreur'] !== null && $r['erreur'] !== '' ? mb_substr((string)$r['erreur'], 0, 300) : null;
            try {
                ConnectCollecte::releve($depot, $alarmes, $permises[$id], (array)($r['lectures'] ?? []), $quand, $erreur);
                $acceptes++;
                // Relevés automatiques « à chaque relevé du PC releveur » : la valeur gardée (mise à l'échelle) va au module Énergie.
                foreach (array_keys((array)($r['lectures'] ?? [])) as $ptId) {
                    $pt = $db->fetchOne("SELECT Id, PasserelleId, DerniereValeur, DerniereLecture, DerniereQualite FROM ConnectPoints WHERE Id = :id", ['id' => (int)$ptId]);
                    if (!$pt || (int)$pt['PasserelleId'] !== $id || $pt['DerniereValeur'] === null || $pt['DerniereLecture'] !== $quand
                        || ($pt['DerniereQualite'] ?? '') === 'mauvaise') continue;
                    (new ConnectLiaisons($depot, $alarmes))->surReleve((int)$pt['Id'], $quand, (float)$pt['DerniereValeur']);
                }
                if ($erreur !== null) $erreurs[] = "{$permises[$id]['Nom']} : $erreur";
            } catch (\Throwable $e) {
                $refus[] = "connexion $id : " . $e->getMessage();
                Connect::journal('erreur', 'Dépôt d\'un PC releveur', ['exception' => $e]);
            }
        }
        $journal = array_map(fn($l) => mb_substr((string)$l, 0, 300), array_slice((array)($corps['journal'] ?? []), -40));
        if (is_array($corps['materiel'] ?? null)) $depot->noterMaterielReleveur((int)$releveur['Id'], self::materiel($corps['materiel']));
        $depot->noterContactReleveur((int)$releveur['Id'], $erreurs ? implode(' ; ', array_slice($erreurs, 0, 3)) : null, $journal);
        Connect::journal('info', "PC releveur « {$releveur['Nom']} » : $acceptes relevé(s) déposé(s)" . ($refus ? ', ' . count($refus) . ' refusé(s)' : ''));
        return ['acceptes' => $acceptes, 'refus' => $refus];
    }

    /**
     * Matériel radio annoncé par un PC : réduit à des champs connus, bornés.
     * (Ce sont des données venues du PC : jamais du HTML, jamais de longueur libre.)
     */
    public static function materiel(array $m): array
    {
        $txt = fn($v, int $n) => mb_substr(preg_replace('/[\x00-\x1f<>]/u', '', (string)$v) ?? '', 0, $n);
        $ports = [];
        foreach (array_slice(array_values(array_filter((array)($m['ports'] ?? []), 'is_array')), 0, 20) as $p) {
            $ports[] = ['port' => $txt($p['port'] ?? '', 80), 'vid' => preg_match('/^[0-9a-f]{4}$/', (string)($p['vid'] ?? '')) ? $p['vid'] : null,
                        'pid' => preg_match('/^[0-9a-f]{4}$/', (string)($p['pid'] ?? '')) ? $p['pid'] : null,
                        'libelle' => $txt($p['libelle'] ?? '', 120), 'produit' => $txt($p['produit'] ?? '', 120),
                        'modele' => isset(ConnectSerie::MODELES[(string)($p['modele'] ?? '')]) ? (string)$p['modele'] : null,
                        'conseil' => $txt($p['conseil'] ?? '', 200)];
        }
        return ['quand' => preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string)($m['quand'] ?? '')) ? $m['quand'] : date('Y-m-d H:i:s'),
                'systeme' => $txt($m['systeme'] ?? '', 20), 'ports' => $ports];
    }

    // ── Paquet à télécharger pour le PC ────────────────────────────────────

    /**
     * Empreinte SHA-256 du certificat que présente Larka à cette adresse (le serveur se
     * joint lui-même). Le collecteur s'en sert si la vérification habituelle échoue
     * (certificat interne ou auto-signé). null : http, ou adresse injoignable d'ici.
     */
    public static function empreinteCertificat(string $url): ?string
    {
        $u = parse_url($url);
        if (strtolower((string)($u['scheme'] ?? '')) !== 'https' || empty($u['host']) || !function_exists('openssl_x509_fingerprint')) return null;
        $hote = trim((string)$u['host'], '[]');
        $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'capture_peer_cert' => true,
                                                'SNI_enabled' => true, 'peer_name' => $hote]]);
        $f = @stream_socket_client('ssl://' . (str_contains($hote, ':') ? "[$hote]" : $hote) . ':' . (int)($u['port'] ?? 443), $en, $es, 4, STREAM_CLIENT_CONNECT, $ctx);
        if (!$f) return null;
        $cert = stream_context_get_params($f)['options']['ssl']['peer_certificate'] ?? null;
        fclose($f);
        $fp = $cert ? openssl_x509_fingerprint($cert, 'sha256') : false;
        return is_string($fp) && preg_match('/^[a-f0-9]{64}$/', $fp) ? $fp : null;
    }

    /** Le collecteur prêt à l'emploi : scripts, pilotes, configuration (avec le jeton), installeur de PHP. */
    public static function paquet(string $jeton, string $nom, string $urlLarka): string
    {
        if (!class_exists('ZipArchive')) throw new RuntimeException('L\'extension PHP zip manque sur le serveur.');
        $racine = dirname(__DIR__, 2);
        // Sources du collecteur : sous api/ (livré partout ; deploy/ ne l'est pas en production).
        $src = __DIR__ . '/releveur';
        foreach (['releveur.php', 'releveur.sh', 'LISEZ-MOI.txt', 'releveur.cmd', 'installer-php.ps1'] as $f) {
            if (!is_file("$src/$f")) throw new RuntimeException("Fichier du collecteur absent du serveur : api/connect/releveur/$f (installation incomplète ?).");
        }
        $fichier = tempnam(sys_get_temp_dir(), 'releveur');
        $zip = new ZipArchive();
        if ($zip->open($fichier, ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Archive impossible à créer.');
        $d = 'larka-releveur/';
        foreach (['releveur.php', 'releveur.sh', 'LISEZ-MOI.txt', 'installer-php.ps1'] as $f) $zip->addFile("$src/$f", $d . $f);
        // Lanceur Windows : fins de ligne CRLF, quoi que contienne le dépôt.
        $cmd = (string)file_get_contents("$src/releveur.cmd");
        $zip->addFromString($d . 'releveur.cmd', preg_replace("/\r?\n/", "\r\n", $cmd));
        $zip->setExternalAttributesName($d . 'releveur.sh', ZipArchive::OPSYS_UNIX, 0100755 << 16);
        $zip->addFile("$racine/api/connect/Pilote.php", $d . 'lib/connect/Pilote.php');
        foreach (glob("$racine/api/connect/pilotes/*.php") ?: [] as $f) $zip->addFile($f, $d . 'lib/connect/pilotes/' . basename($f));
        $cfg = ['larka' => rtrim($urlLarka, '/'), 'jeton' => $jeton, 'nom' => $nom, 'cree' => date('Y-m-d H:i:s')];
        // Réseau interne, certificat auto-signé ou d'une autorité interne : le collecteur épingle
        // CE certificat (empreinte), sans jamais renoncer à la vérification.
        $empreinte = self::empreinteCertificat($cfg['larka']);
        if ($empreinte !== null) $cfg['empreinte_certificat'] = $empreinte;
        $zip->addFromString($d . 'config.json', json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        // Le jeton : lisible par ce seul compte une fois décompressé (Linux, macOS).
        $zip->setExternalAttributesName($d . 'config.json', ZipArchive::OPSYS_UNIX, 0100600 << 16);
        $zip->setExternalAttributesName($d . 'releveur.cmd', ZipArchive::OPSYS_UNIX, 0100644 << 16);
        $zip->close();
        $octets = (string)file_get_contents($fichier);
        @unlink($fichier);
        return $octets;
    }
}

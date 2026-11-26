<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * Larka — Serveur de supervision séparé, et le lien chiffré qui le relie
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * POURQUOI
 *   La supervision parle au réseau technique (automates, compteurs, bornes) ;
 *   la GMAO parle aux utilisateurs, souvent depuis Internet. Les mettre sur la
 *   même machine et dans la même base, c'est ouvrir un chemin entre les deux
 *   réseaux. Ici, la supervision peut vivre sur un SERVEUR DE SUPERVISION : une
 *   installation Larka posée sur le réseau technique, avec SA base (PostgreSQL,
 *   MariaDB, MySQL ou SQLite), qui collecte, garde les mesures et les alarmes.
 *   Le serveur principal (la GMAO) ne joint plus aucun équipement : il dialogue
 *   avec le serveur de supervision, et lui seul.
 *
 * LE LIEN, CHIFFRÉ PAR LARKA LUI-MÊME
 *   Chaque côté a une paire de clés X25519 (sodium). Chaque message est scellé
 *   par crypto_box (X25519 + XSalsa20-Poly1305) pour la clé publique de l'autre :
 *   illisible et infalsifiable pour qui n'a pas la clé privée, même si le
 *   transport est du HTTP en clair ou si un proxy TLS l'ouvre. Clés ÉPINGLÉES à
 *   l'appairage (pas d'autorité de certification à qui faire confiance) ;
 *   chaque message porte son heure (±5 min acceptées) et un identifiant unique
 *   gardé 10 min (un message rejoué est refusé) ; une réponse cite la requête
 *   à laquelle elle répond (pas de réponse substituée). Le HTTPS reste conseillé
 *   en plus : il cache aussi la taille et le rythme des échanges.
 *
 * DEUX SENS POSSIBLES, au choix du gestionnaire
 *   - PORT OUVERT (« direct ») : le serveur principal appelle le serveur de
 *     supervision (un port TCP ouvert vers lui, HTTPS de préférence) ;
 *   - PONT SORTANT (« pont ») : le serveur de supervision appelle le principal
 *     et reste en attente (requête longue, 20 s) ; le principal lui passe ses
 *     demandes en réponse. Aucun port à ouvrir vers le réseau technique : seule
 *     une sortie vers le serveur principal. Le relais (api/outils/
 *     supervision-pont.php) se lance tout seul avec la collecte.
 *   Les protocoles où l'équipement appelle lui-même (OCPP des bornes, LoRaWAN
 *   en UDP, broker MQTT) parlent DIRECTEMENT au serveur de supervision, qui
 *   héberge ces communications : ni pont ni port pour eux côté principal.
 *
 * CE QUI PASSE PAR LE LIEN
 *   - les écrans Supervision / Alarmes / Connexions du principal : chaque appel
 *     « connect_* » est relayé tel quel, avec le compte et le rôle de la
 *     personne (le serveur de supervision revérifie les droits) ;
 *   - l'assistant : lectures de synthèse (ConnectLecture) ;
 *   - la SYNCHRONISATION, chaque minute (tâche de collecte du principal) :
 *       ↓ référentiel : bâtiments, équipements, compteurs du module Énergie
 *         (copies, pour les rattachements et les calculs) ;
 *       ↑ sortants : ce que la supervision produit pour la GMAO — demandes
 *         d'intervention des alarmes, relevés d'énergie, notifications. Ils
 *         sont écrits par le PRINCIPAL dans SA base ; une coupure du lien ne
 *         perd rien (file d'attente, rejouée au retour).
 *
 * QUI DÉCIDE : le gestionnaire de l'organisation (Connexions → Serveur de
 * supervision), comme le reste de la supervision ; le Super Admin garde son
 * interrupteur du module Larka Connect par organisation.
 * ═══════════════════════════════════════════════════════════════════════════════
 */

declare(strict_types=1);

final class SupervisionLien
{
    public const VERSION = '1';
    public const PREFIXE_CODE = 'LKS1.';
    /** Écart d'horloge toléré, et durée de mémoire des identifiants (anti-rejeu). */
    public const FENETRE = 300;
    public const MEMOIRE = 600;
    /** Attente d'une réponse, et durée d'une attente du pont (côté principal). */
    public const DELAI = 25;
    public const ATTENTE_PONT = 20;
    /** Durée de validité d'un code d'appairage. */
    public const DUREE_CODE = 1800;
    /** Actions de l'écran traitées par le principal lui-même (identité confirmée sur SA session). */
    public const ACTIONS_LOCALES = ['connect_confirmer_identite', 'connect_identite_sso', 'connect_identite_duree'];
    /** Tables recopiées du principal vers le serveur de supervision. */
    public const MIROIRS = ['PlanBatiments', 'Equipements', 'CompteurEnergie'];

    private static array $schemaFait = [];

    // ═══════════════════════════════════════════════════════════════════════
    //  Stockage
    // ═══════════════════════════════════════════════════════════════════════

    public static function schema(Database $db): void
    {
        $k = spl_object_id($db);
        if (isset(self::$schemaFait[$k])) return;
        self::$schemaFait[$k] = true;
        foreach ([
            "CREATE TABLE IF NOT EXISTS SupervisionLien (Id INTEGER PRIMARY KEY, Role VARCHAR(20), Mode VARCHAR(20), Url TEXT,
                UrlLocale TEXT, Organisation VARCHAR(64), ClePubliqueLocale TEXT, ClePriveeLocale TEXT, ClePubliqueDistante TEXT,
                Etat VARCHAR(20), DernierContact VARCHAR(32), DerniereErreur TEXT, VersionDistante VARCHAR(40),
                Code TEXT, CodeExpire BIGINT, Referentiel VARCHAR(64), MisAJourPar TEXT, MisAJourLe VARCHAR(32),
                DernierContactPont VARCHAR(32))",
            "CREATE TABLE IF NOT EXISTS SupervisionFile (Jeton VARCHAR(40) PRIMARY KEY, Requete TEXT, Etat VARCHAR(12),
                Reponse TEXT, Cree BIGINT, Pris BIGINT, Repondu BIGINT)",
            "CREATE TABLE IF NOT EXISTS SupervisionVus (Nonce VARCHAR(64) PRIMARY KEY, Recu BIGINT)",
            "CREATE TABLE IF NOT EXISTS SupervisionSortants (Jeton VARCHAR(40) PRIMARY KEY, Type VARCHAR(30), Donnees TEXT,
                Cree BIGINT, Etat VARCHAR(12), Resultat TEXT)",
        ] as $sql) {
            $db->getPdo()->exec($sql);
        }
        try { $db->getPdo()->query("SELECT DernierContactPont FROM SupervisionLien WHERE 1 = 0"); }
        catch (\Throwable $e) { $db->getPdo()->exec("ALTER TABLE SupervisionLien ADD COLUMN DernierContactPont VARCHAR(32)"); }
    }

    /**
     * PostgreSQL rend les noms de colonnes en minuscules ; Database ne connaît pas
     * ces tables-ci (créées ici) : on rend leurs noms tels que Larka les écrit.
     */
    private const COLONNES = ['Id', 'Role', 'Mode', 'Url', 'UrlLocale', 'Organisation', 'ClePubliqueLocale', 'ClePriveeLocale',
        'ClePubliqueDistante', 'Etat', 'DernierContact', 'DerniereErreur', 'VersionDistante', 'Code', 'CodeExpire', 'Referentiel',
        'MisAJourPar', 'MisAJourLe', 'DernierContactPont', 'Jeton', 'Requete', 'Reponse', 'Cree', 'Pris', 'Repondu', 'Nonce', 'Recu',
        'Type', 'Donnees', 'Resultat', 'DemandeId', 'Statut', 'n'];
    private static function camel(?array $r): ?array
    {
        if ($r === null) return null;
        static $map = null;
        $map ??= array_combine(array_map('strtolower', self::COLONNES), self::COLONNES);
        $out = [];
        foreach ($r as $k => $v) $out[is_string($k) ? ($map[strtolower($k)] ?? $k) : $k] = $v;
        return $out;
    }
    private static function un(Database $db, string $sql, array $p = []): ?array { return self::camel($db->fetchOne($sql, $p) ?: null); }
    private static function tous(Database $db, string $sql, array $p = []): array { return array_map(fn($r) => self::camel($r), $db->fetchAll($sql, $p)); }

    /** L'état du lien (une ligne), avec ses valeurs par défaut. */
    public static function lire(Database $db): array
    {
        self::schema($db);
        $r = self::un($db, "SELECT * FROM SupervisionLien WHERE Id = 1") ?: [];
        return $r + ['Role' => 'principal', 'Mode' => 'local', 'Url' => null, 'UrlLocale' => null, 'Organisation' => null,
                     'ClePubliqueLocale' => null, 'ClePriveeLocale' => null, 'ClePubliqueDistante' => null, 'Etat' => null,
                     'DernierContact' => null, 'DerniereErreur' => null, 'VersionDistante' => null, 'Code' => null,
                     'CodeExpire' => null, 'Referentiel' => null, 'DernierContactPont' => null];
    }

    private static function ecrire(Database $db, array $champs): void
    {
        self::schema($db);
        $champs['MisAJourLe'] = date('Y-m-d H:i:s');
        $cols = array_keys($champs);
        foreach ($cols as $c) if (!preg_match('/^[A-Za-z]+$/', $c)) throw new InvalidArgumentException('Colonne invalide');
        if (self::un($db, "SELECT Id FROM SupervisionLien WHERE Id = 1")) {
            $db->getPdo()->prepare('UPDATE SupervisionLien SET ' . implode(', ', array_map(fn($c) => "$c = :$c", $cols)) . ' WHERE Id = 1')->execute($champs);
        } else {
            $db->getPdo()->prepare('INSERT INTO SupervisionLien (Id, ' . implode(', ', $cols) . ') VALUES (1, :' . implode(', :', $cols) . ')')->execute($champs);
        }
    }

    /** Ce serveur (principal) délègue-t-il sa supervision à un serveur de supervision appairé ? */
    public static function distante(Database $db): bool
    {
        try { $l = self::lire($db); } catch (\Throwable $e) { return false; }
        return $l['Role'] === 'principal' && in_array($l['Mode'], ['direct', 'pont'], true) && !empty($l['ClePubliqueDistante']);
    }

    /** Ce serveur EST-il un serveur de supervision appairé ? */
    public static function estSupervision(Database $db): bool
    {
        try { $l = self::lire($db); } catch (\Throwable $e) { return false; }
        return $l['Role'] === 'supervision' && !empty($l['ClePubliqueDistante']);
    }

    /**
     * Le module Larka Connect est-il ouvert pour l'organisation ? (interrupteur du
     * Super Admin). Sans « connect.actif » du serveur : en supervision séparée, le
     * principal ne parle à aucun équipement.
     */
    public static function moduleOuvert(Database $db): bool
    {
        try {
            $row = $db->fetchOne("SELECT Valeur FROM Configuration WHERE Cle = :k", ['k' => 'modules_disabled']);
            $liste = json_decode((string)($row['Valeur'] ?? ''), true);
            return !(is_array($liste) && in_array('connect', $liste, true));
        } catch (\Throwable $e) {
            return true;
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Clés et enveloppes
    // ═══════════════════════════════════════════════════════════════════════

    public static function disponible(): bool
    {
        return function_exists('sodium_crypto_box') && function_exists('sodium_crypto_box_keypair');
    }

    private static function b64(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
    private static function deb64(string $s): string
    {
        $r = base64_decode(strtr($s, '-_', '+/'), true);
        if ($r === false) throw new RuntimeException('Encodage invalide.');
        return $r;
    }

    /** [publique, privée] de ce serveur (créées au premier besoin, privée chiffrée en base). */
    private static function cles(Database $db): array
    {
        if (!self::disponible()) throw new RuntimeException('Extension PHP sodium absente : le lien chiffré est impossible (paquet php-sodium).');
        $l = self::lire($db);
        if (!empty($l['ClePubliqueLocale']) && !empty($l['ClePriveeLocale'])) {
            $s = Connect::dechiffrer((string)$l['ClePriveeLocale']);
            if (!empty($s['cle'])) return [self::deb64((string)$l['ClePubliqueLocale']), self::deb64((string)$s['cle'])];
            throw new RuntimeException('Clé privée du lien illisible (config.key changée ?) : refaites l\'appairage.');
        }
        $kp = sodium_crypto_box_keypair();
        $pub = sodium_crypto_box_publickey($kp);
        $priv = sodium_crypto_box_secretkey($kp);
        self::ecrire($db, ['ClePubliqueLocale' => self::b64($pub), 'ClePriveeLocale' => Connect::chiffrer(['cle' => self::b64($priv)])]);
        return [$pub, $priv];
    }

    /** Empreinte commune aux deux côtés : à comparer à l'œil sur les deux écrans. */
    public static function empreinte(string $pubA, string $pubB): string
    {
        $x = [$pubA, $pubB];
        sort($x, SORT_STRING);
        return implode(' ', str_split(strtoupper(substr(hash('sha256', $x[0] . '|' . $x[1]), 0, 24)), 4));
    }

    /**
     * Scelle un message pour l'autre côté. $organisation : celle du DESTINATAIRE
     * quand il est multi-organisation (le principal), pour qu'il ouvre la bonne base.
     */
    public static function sceller(Database $db, string $type, $donnees, ?string $reponseA = null, ?string $clePublique = null): array
    {
        [, $priv] = self::cles($db);
        $l = self::lire($db);
        $pubDist = $clePublique ?? (string)$l['ClePubliqueDistante'];
        if ($pubDist === '') throw new RuntimeException('Lien non appairé.');
        $id = bin2hex(random_bytes(16));
        $clair = json_encode(['t' => time(), 'i' => $id, 'y' => $type, 'r' => $reponseA, 'd' => $donnees],
                             JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $n = random_bytes(SODIUM_CRYPTO_BOX_NONCEBYTES);
        $kp = sodium_crypto_box_keypair_from_secretkey_and_publickey($priv, self::deb64($pubDist));
        $c = sodium_crypto_box((string)$clair, $n, $kp);
        sodium_memzero($kp);
        return ['v' => self::VERSION, 'o' => $l['Role'] === 'supervision' ? (string)$l['Organisation'] : 'supervision',
                'n' => self::b64($n), 'c' => self::b64($c), 'id' => $id];
    }

    /**
     * Ouvre un message reçu : [type, données, id, répond à]. Refuse tout message
     * illisible, trop ancien, venu d'une autre clé ou déjà reçu.
     */
    public static function ouvrir(Database $db, $env, ?string $clePublique = null): array
    {
        if (!is_array($env) || ($env['v'] ?? '') !== self::VERSION || !is_string($env['n'] ?? null) || !is_string($env['c'] ?? null)) {
            throw new RuntimeException('Message illisible (format ou version du lien).');
        }
        [, $priv] = self::cles($db);
        $l = self::lire($db);
        $pubDist = $clePublique ?? (string)$l['ClePubliqueDistante'];
        if ($pubDist === '') throw new RuntimeException('Lien non appairé.');
        $kp = sodium_crypto_box_keypair_from_secretkey_and_publickey($priv, self::deb64($pubDist));
        $clair = sodium_crypto_box_open(self::deb64($env['c']), self::deb64($env['n']), $kp);
        sodium_memzero($kp);
        if ($clair === false) throw new RuntimeException('Message refusé : il ne vient pas du serveur appairé (clé différente ou contenu altéré).');
        $m = json_decode($clair, true);
        if (!is_array($m) || !isset($m['t'], $m['i'], $m['y'])) throw new RuntimeException('Message illisible.');
        if (abs(time() - (int)$m['t']) > self::FENETRE) throw new RuntimeException('Message refusé : horloges décalées de plus de 5 minutes entre les deux serveurs (ou message rejoué).');
        if (!preg_match('/^[a-f0-9]{32}$/', (string)$m['i'])) throw new RuntimeException('Message illisible.');
        // Anti-rejeu : chaque identifiant n'est accepté qu'une fois.
        $pdo = $db->getPdo();
        $pdo->prepare("DELETE FROM SupervisionVus WHERE Recu < ?")->execute([time() - self::MEMOIRE]);
        try {
            $pdo->prepare("INSERT INTO SupervisionVus (Nonce, Recu) VALUES (?, ?)")->execute([(string)$m['i'], time()]);
        } catch (\Throwable $e) {
            throw new RuntimeException('Message refusé : déjà reçu (rejeu).');
        }
        return [(string)$m['y'], $m['d'] ?? null, (string)$m['i'], isset($m['r']) ? (string)$m['r'] : null];
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Appairage
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Code à donner à l'autre serveur. $role : celui de CE serveur (principal ou
     * supervision) ; $mode : direct | pont ; $url : adresse par laquelle l'autre
     * côté joint CE serveur.
     */
    public static function creerCode(Database $db, string $role, string $mode, string $url, string $organisation, string $login): string
    {
        if (!in_array($role, ['principal', 'supervision'], true) || !in_array($mode, ['direct', 'pont'], true)) throw new InvalidArgumentException('Rôle ou mode invalide.');
        $url = self::urlValide($url);
        [$pub] = self::cles($db);
        $secret = random_bytes(24);
        self::ecrire($db, ['Role' => $role, 'Mode' => $role === 'principal' ? $mode : $mode, 'Organisation' => $role === 'principal' ? $organisation : null,
                           'Code' => Connect::chiffrer(['s' => self::b64($secret), 'mode' => $mode]), 'CodeExpire' => time() + self::DUREE_CODE,
                           'MisAJourPar' => $login]);
        return self::PREFIXE_CODE . self::b64(json_encode(['r' => $role, 'm' => $mode, 'u' => $url, 'o' => $role === 'principal' ? $organisation : 'default',
                                                          'k' => self::b64($pub), 's' => self::b64($secret)], JSON_UNESCAPED_SLASHES));
    }

    /** Adresse par laquelle le relais joint CE serveur de supervision (boucle locale, en général). */
    public static function reglerUrlLocale(Database $db, string $url): void
    {
        self::ecrire($db, ['UrlLocale' => trim($url) === '' ? null : self::urlValide($url)]);
    }

    /** Adresse de l'autre serveur (changement d'adresse sans refaire l'appairage : les clés restent). */
    public static function reglerUrl(Database $db, string $url): void
    {
        self::ecrire($db, ['Url' => self::urlValide($url)]);
    }

    private static function urlValide(string $url): string
    {
        $url = rtrim(trim($url), '/');
        if (!preg_match('~^https?://[A-Za-z0-9.\-\[\]:]+(?::\d{1,5})?(?:/[^\s"<>]*)?$~', $url)) {
            throw new InvalidArgumentException('Adresse invalide : « https://serveur:port » attendu.');
        }
        return $url;
    }

    private static function lireCode(string $code): array
    {
        $code = trim($code);
        if (!str_starts_with($code, self::PREFIXE_CODE)) throw new InvalidArgumentException('Ce n\'est pas un code d\'appairage Larka (« LKS1.… »).');
        $d = json_decode(self::deb64(substr($code, strlen(self::PREFIXE_CODE))), true);
        if (!is_array($d) || !in_array($d['r'] ?? '', ['principal', 'supervision'], true) || !in_array($d['m'] ?? '', ['direct', 'pont'], true)
            || !is_string($d['k'] ?? null) || !is_string($d['s'] ?? null) || !is_string($d['u'] ?? null) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', (string)($d['o'] ?? ''))) {
            throw new InvalidArgumentException('Code d\'appairage illisible ou incomplet.');
        }
        return $d;
    }

    /**
     * Ce serveur reçoit le code de l'autre et l'appelle pour s'appairer.
     * $monUrl : adresse par laquelle l'autre joint CE serveur (utile en mode
     * direct quand c'est le principal qui appelle la supervision).
     */
    public static function utiliserCode(Database $db, string $code, ?string $monUrl, string $organisation, string $login): array
    {
        $d = self::lireCode($code);
        $monRole = $d['r'] === 'principal' ? 'supervision' : 'principal';
        [$pub] = self::cles($db);
        $monUrl = ($monUrl !== null && trim($monUrl) !== '') ? self::urlValide($monUrl) : null;
        $preuve = hash_hmac('sha256', self::b64($pub) . '|' . $d['o'] . '|' . $monRole, self::deb64($d['s']));
        $corps = json_encode(['cible' => $d['o'], 'k' => self::b64($pub), 'r' => $monRole, 'u' => $monUrl,
                              'o' => $monRole === 'principal' ? $organisation : 'default', 'p' => $preuve, 'v' => self::VERSION]);
        $rep = json_decode(self::post($d['u'] . '/api/index.php?action=supervision_appairer', (string)$corps, 20), true);
        if (!is_array($rep) || empty($rep['success'])) throw new RuntimeException('Appairage refusé : ' . (string)($rep['error'] ?? 'réponse illisible de ' . $d['u']));
        // La réponse est scellée pour NOTRE clé par la clé du code : l'autre côté prouve qu'il la détient.
        [$type, $info] = self::ouvrir($db, $rep['data']['e'] ?? null, $d['k']);
        if ($type !== 'appaire') throw new RuntimeException('Réponse d\'appairage inattendue.');
        self::ecrire($db, ['Role' => $monRole, 'Mode' => $d['m'], 'Url' => $d['u'], 'ClePubliqueDistante' => $d['k'],
                           'Organisation' => $monRole === 'supervision' ? $d['o'] : $organisation,
                           'Etat' => 'appaire', 'DernierContact' => date('Y-m-d H:i:s'), 'DerniereErreur' => null,
                           'VersionDistante' => (string)($info['version'] ?? ''), 'Code' => null, 'CodeExpire' => null, 'Referentiel' => null,
                           'MisAJourPar' => $login]);
        self::journal('info', 'Appairé avec ' . ($monRole === 'principal' ? 'le serveur de supervision ' : 'le serveur principal ') . $d['u']
                      . ' (mode ' . ($d['m'] === 'pont' ? 'pont sortant' : 'port ouvert') . ') par ' . $login);
        return ['role' => $monRole, 'mode' => $d['m'], 'url' => $d['u'], 'empreinte' => self::empreinte(self::b64($pub), $d['k'])];
    }

    /** L'autre serveur présente notre code : on vérifie sa preuve, on épingle sa clé. */
    public static function recevoirAppairage(Database $db, array $b): array
    {
        $l = self::lire($db);
        $code = Connect::dechiffrer((string)($l['Code'] ?? ''));
        if (empty($code['s']) || (int)$l['CodeExpire'] < time()) throw new RuntimeException('Aucun code d\'appairage en cours sur ce serveur (ou code expiré : recréez-le).');
        $k = (string)($b['k'] ?? ''); $r = (string)($b['r'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $k) || $r === $l['Role'] || !in_array($r, ['principal', 'supervision'], true)) throw new RuntimeException('Demande d\'appairage invalide.');
        $attendu = hash_hmac('sha256', $k . '|' . ($l['Role'] === 'principal' ? (string)$l['Organisation'] : 'default') . '|' . $r, self::deb64((string)$code['s']));
        if (!hash_equals($attendu, (string)($b['p'] ?? ''))) {
            self::journal('avertissement', 'Appairage refusé : preuve invalide (code erroné ou intercepté)');
            throw new RuntimeException('Preuve d\'appairage invalide.');
        }
        // Sans adresse fournie : aucune — et non celle d'un ANCIEN partenaire, restée
        // dans l'état (« Appairé avec http://… » désignait un serveur délié depuis).
        $url = isset($b['u']) && is_string($b['u']) && $b['u'] !== '' ? self::urlValide($b['u']) : null;
        self::ecrire($db, ['ClePubliqueDistante' => $k, 'Url' => $url, 'Mode' => (string)$code['mode'], 'Etat' => 'appaire',
                           'Organisation' => $l['Role'] === 'supervision' ? (string)($b['o'] ?? 'default') : $l['Organisation'],
                           'DernierContact' => date('Y-m-d H:i:s'), 'DerniereErreur' => null, 'VersionDistante' => (string)($b['v'] ?? ''),
                           'Code' => null, 'CodeExpire' => null, 'Referentiel' => null]);
        self::journal('info', 'Appairage accepté (' . ($l['Role'] === 'principal' ? 'serveur de supervision' : 'serveur principal') . ($url ? ' ' . $url : '') . ')');
        [$pub] = self::cles($db);
        $env = self::sceller($db, 'appaire', ['version' => self::VERSION, 'role' => $l['Role'], 'empreinte' => self::empreinte(self::b64($pub), $k)]);
        unset($env['id']);
        return $env;
    }

    /** Délier : clés de l'autre oubliées (les nôtres sont régénérées), mode local. */
    public static function delier(Database $db, string $login): void
    {
        self::ecrire($db, ['Mode' => 'local', 'ClePubliqueDistante' => null, 'Etat' => null, 'Code' => null, 'CodeExpire' => null,
                           'ClePubliqueLocale' => null, 'ClePriveeLocale' => null, 'Referentiel' => null, 'MisAJourPar' => $login,
                           // L'adresse et les nouvelles de l'ancien partenaire partent avec lui.
                           'Url' => null, 'DernierContact' => null, 'DerniereErreur' => null]);
        try { $db->getPdo()->exec("DELETE FROM SupervisionFile"); } catch (\Throwable $e) {}
        self::journal('info', 'Lien avec le serveur ' . (self::lire($db)['Role'] === 'principal' ? 'de supervision' : 'principal') . ' délié par ' . $login);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Transport
    // ═══════════════════════════════════════════════════════════════════════

    /** POST JSON ; rend le corps de la réponse. */
    private static function post(string $url, string $corps, int $delai): string
    {
        $verifier = !filter_var(Connect::reglage('supervision_tls_verifier', true) ?? true, FILTER_VALIDATE_BOOLEAN) ? false : true;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => $corps, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_TIMEOUT => $delai + 5,
                CURLOPT_SSL_VERIFYPEER => $verifier, CURLOPT_SSL_VERIFYHOST => $verifier ? 2 : 0,
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            ]);
            $r = curl_exec($ch);
            $err = curl_error($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($r === false) throw new RuntimeException('Serveur injoignable (' . $url . ') : ' . $err);
            if ($code >= 500 || ($code >= 400 && !str_starts_with(ltrim((string)$r), '{'))) throw new RuntimeException("Le serveur $url répond HTTP $code.");
            return (string)$r;
        }
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
                                                 'content' => $corps, 'timeout' => $delai + 5, 'ignore_errors' => true, 'follow_location' => 0],
                                      'ssl' => ['verify_peer' => $verifier, 'verify_peer_name' => $verifier]]);
        $r = @file_get_contents($url, false, $ctx);
        if ($r === false) throw new RuntimeException('Serveur injoignable (' . $url . ').');
        return $r;
    }

    /**
     * PRINCIPAL → SUPERVISION : une requête et sa réponse, par le port ouvert ou
     * par le pont (file d'attente que le relais vient chercher).
     */
    public static function requete(Database $db, string $op, array $donnees, int $delai = self::DELAI)
    {
        $l = self::lire($db);
        if (!self::distante($db)) throw new RuntimeException('Aucun serveur de supervision appairé.');
        $env = self::sceller($db, 'requete', ['op' => $op, 'donnees' => $donnees]);
        $id = $env['id']; unset($env['id']);
        try {
            if ($l['Mode'] === 'direct') {
                $brut = self::post(rtrim((string)$l['Url'], '/') . '/api/index.php?action=supervision_lien', (string)json_encode($env), $delai);
                $rep = json_decode($brut, true);
                if (!is_array($rep) || empty($rep['success'])) throw new RuntimeException((string)($rep['error'] ?? 'réponse illisible du serveur de supervision'));
                $envRep = $rep['data']['e'] ?? null;
            } else {
                // Le relais rappelle au moins toutes les 20 s : sans nouvelles depuis 90 s, il est
                // arrêté ou coupé du principal — on le dit tout de suite au lieu de faire attendre
                // chaque écran 25 s.
                $vu = $l['DernierContactPont'] ?? null;
                if (!$vu || strtotime((string)$vu) < time() - 90) {
                    throw new RuntimeException('Le serveur de supervision n\'appelle plus ce serveur (pont sortant) depuis '
                        . ($vu ? date('d/m/Y H:i', strtotime((string)$vu)) : 'l\'appairage') . ' : son relais tourne-t-il (tâche de collecte, ou php api/outils/supervision-pont.php) ?');
                }
                $pdo = $db->getPdo();
                $pdo->prepare("DELETE FROM SupervisionFile WHERE Cree < ?")->execute([time() - 300]);
                $pdo->prepare("INSERT INTO SupervisionFile (Jeton, Requete, Etat, Cree) VALUES (?, ?, 'attente', ?)")
                    ->execute([$id, json_encode($env), time()]);
                $fin = microtime(true) + $delai;
                $envRep = null;
                while (microtime(true) < $fin) {
                    $r = self::un($db, "SELECT Etat, Reponse FROM SupervisionFile WHERE Jeton = :j", ['j' => $id]);
                    if ($r && $r['Etat'] === 'repondu') { $envRep = json_decode((string)$r['Reponse'], true); break; }
                    usleep(120000);
                }
                $pdo->prepare("DELETE FROM SupervisionFile WHERE Jeton = ?")->execute([$id]);
                if ($envRep === null) {
                    throw new RuntimeException('Le serveur de supervision ne répond pas (pont sortant) : son relais est-il lancé, et joint-il ce serveur ? Dernier contact : '
                        . ($l['DernierContact'] ? date('d/m/Y H:i', strtotime((string)$l['DernierContact'])) : 'jamais') . '.');
                }
            }
            [$type, $d, , $repondA] = self::ouvrir($db, $envRep);
            if ($type !== 'reponse' || $repondA !== $id) throw new RuntimeException('Réponse inattendue du serveur de supervision.');
            self::contact($db, null, $d['version'] ?? null);
            if (empty($d['ok'])) throw new RuntimeException((string)($d['erreur'] ?? 'erreur sur le serveur de supervision'));
            return $d['donnees'] ?? null;
        } catch (\Throwable $e) {
            self::contact($db, $e->getMessage());
            throw $e;
        }
    }

    private static function contact(Database $db, ?string $erreur, $version = null): void
    {
        try {
            $ch = $erreur === null ? ['DernierContact' => date('Y-m-d H:i:s'), 'DerniereErreur' => null, 'Etat' => 'ok']
                                   : ['DerniereErreur' => mb_substr($erreur, 0, 400), 'Etat' => 'erreur'];
            if ($version) $ch['VersionDistante'] = mb_substr((string)$version, 0, 40);
            self::ecrire($db, $ch);
        } catch (\Throwable $e) { /* l'état du lien ne doit jamais faire échouer la requête */ }
    }

    // ── Pont sortant : côté principal ──────────────────────────────────────

    /** Le relais du serveur de supervision attend des requêtes (jusqu'à 20 s). */
    public static function pontAttendre(Database $db, $env): array
    {
        [$type, $d] = self::ouvrir($db, $env);
        if ($type !== 'attente') throw new RuntimeException('Message inattendu.');
        self::contact($db, null, $d['version'] ?? null);
        self::ecrire($db, ['DernierContactPont' => date('Y-m-d H:i:s')]);
        if (function_exists('session_write_close')) @session_write_close();
        $fin = time() + min(self::ATTENTE_PONT, max(1, (int)($d['attente'] ?? self::ATTENTE_PONT)));
        $pdo = $db->getPdo();
        do {
            $rows = self::tous($db, "SELECT Jeton, Requete FROM SupervisionFile WHERE Etat = 'attente' AND Cree >= :c ORDER BY Cree LIMIT 10", ['c' => time() - 60]);
            if ($rows) {
                $liste = [];
                foreach ($rows as $r) {
                    $st = $pdo->prepare("UPDATE SupervisionFile SET Etat = 'pris', Pris = ? WHERE Jeton = ? AND Etat = 'attente'");
                    $st->execute([time(), $r['Jeton']]);
                    if ($st->rowCount()) $liste[] = ['jeton' => $r['Jeton'], 'e' => json_decode((string)$r['Requete'], true)];
                }
                if ($liste) { $e = self::sceller($db, 'requetes', ['liste' => $liste]); unset($e['id']); return $e; }
            }
            usleep(150000);
        } while (time() < $fin);
        $e = self::sceller($db, 'requetes', ['liste' => []]); unset($e['id']);
        return $e;
    }

    /** Le relais rapporte les réponses (enveloppes scellées par le serveur de supervision). */
    public static function pontRepondre(Database $db, $env): int
    {
        [$type, $d] = self::ouvrir($db, $env);
        if ($type !== 'reponses') throw new RuntimeException('Message inattendu.');
        $n = 0;
        foreach (array_slice((array)($d['liste'] ?? []), 0, 50) as $x) {
            if (!is_array($x) || !preg_match('/^[a-f0-9]{32}$/', (string)($x['jeton'] ?? ''))) continue;
            $st = $db->getPdo()->prepare("UPDATE SupervisionFile SET Etat = 'repondu', Reponse = ?, Repondu = ? WHERE Jeton = ? AND Etat = 'pris'");
            $st->execute([json_encode($x['e'] ?? null), time(), $x['jeton']]);
            $n += $st->rowCount();
        }
        return $n;
    }

    // ── Pont sortant : côté supervision (le relais) ────────────────────────

    /**
     * Le relais : attend les requêtes du principal, les fait traiter par CE
     * serveur (son propre point d'entrée, sur la boucle locale — les messages ne
     * sont jamais déchiffrés par le relais), rapporte les réponses.
     */
    public static function relayer(Database $db, int $duree, ?callable $log = null): void
    {
        $log ??= fn(string $m) => null;
        $l = self::lire($db);
        if ($l['Role'] !== 'supervision' || $l['Mode'] !== 'pont' || empty($l['ClePubliqueDistante'])) { $log('Pas de pont à relayer (rôle ou mode).'); return; }
        $principal = rtrim((string)$l['Url'], '/');
        $local = rtrim((string)($l['UrlLocale'] ?: ('http://127.0.0.1:' . (defined('APP_PORT') ? APP_PORT : 8000))), '/');
        $fin = time() + $duree;
        $pause = 1;
        while (time() < $fin) {
            try {
                $att = self::sceller($db, 'attente', ['version' => self::VERSION, 'attente' => min(self::ATTENTE_PONT, max(2, $fin - time()))]);
                unset($att['id']);
                $rep = json_decode(self::post($principal . '/api/index.php?action=supervision_pont', (string)json_encode($att), self::ATTENTE_PONT + 5), true);
                if (!is_array($rep) || empty($rep['success'])) throw new RuntimeException((string)($rep['error'] ?? 'réponse illisible du serveur principal'));
                [$type, $d] = self::ouvrir($db, $rep['data']['e'] ?? null);
                if ($type !== 'requetes') throw new RuntimeException('Message inattendu du serveur principal.');
                self::contact($db, null);
                $reponses = [];
                foreach ((array)($d['liste'] ?? []) as $x) {
                    try {
                        $r = json_decode(self::post($local . '/api/index.php?action=supervision_lien', (string)json_encode($x['e']), self::DELAI), true);
                        if (is_array($r) && !empty($r['success'])) $reponses[] = ['jeton' => (string)$x['jeton'], 'e' => $r['data']['e'] ?? null];
                        else $log('Requête non traitée : ' . (string)($r['error'] ?? 'réponse illisible du point d\'entrée local ' . $local));
                    } catch (\Throwable $e) {
                        $log('Point d\'entrée local injoignable (' . $local . ') : ' . $e->getMessage());
                    }
                }
                if ($reponses) {
                    $env = self::sceller($db, 'reponses', ['liste' => $reponses]); unset($env['id']);
                    self::post($principal . '/api/index.php?action=supervision_pont_reponse', (string)json_encode($env), 15);
                    $log(count($reponses) . ' requête(s) relayée(s)');
                }
                $pause = 1;
            } catch (\Throwable $e) {
                self::contact($db, $e->getMessage());
                $log('Pont : ' . $e->getMessage());
                sleep(min($pause, max(0, $fin - time())));
                $pause = min(30, $pause * 2);
            }
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Côté supervision : traitement des requêtes
    // ═══════════════════════════════════════════════════════════════════════

    /** Une requête du principal (hors « api », qui ne revient pas : voir executerApi). */
    public static function traiter(Database $db, string $op, array $d)
    {
        require_once __DIR__ . '/connect/Connect.php';
        Connect::charger();
        return match ($op) {
            'ping'      => ['version' => self::VERSION, 'heure' => date('Y-m-d H:i:s'), 'connect_actif' => Connect::actifServeur(),
                            'base' => $db->getDriver(), 'php' => PHP_VERSION],
            'lecture'   => ConnectLecture::executer($db, (string)($d['quoi'] ?? ''), (array)($d['args'] ?? [])),
            'sortants'  => self::sortantsLire($db),
            'synchro'   => self::appliquerSynchro($db, $d),
            default     => throw new RuntimeException('Opération inconnue : ' . $op),
        };
    }

    /**
     * Exécute un appel « connect_* » de l'écran du principal, comme s'il venait
     * d'un navigateur connecté ici : mêmes routes, mêmes contrôles de rôle — avec
     * le compte et le rôle de la personne, et sa confirmation d'identité (5 min).
     * Ne revient pas : la sortie de la route (JSON, fichier) est capturée et
     * repart scellée.
     */
    public static function executerApi(Database $db, array $req, string $repondA): void
    {
        $action = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($req['action'] ?? ''));
        $methode = strtoupper((string)($req['methode'] ?? 'GET'));
        if (!str_starts_with($action, 'connect_') || in_array($action, self::ACTIONS_LOCALES, true) || !in_array($methode, ['GET', 'POST', 'PUT', 'DELETE'], true)) {
            throw new RuntimeException('Appel non relayable : ' . $action);
        }
        $u = (array)($req['utilisateur'] ?? []);
        $role = in_array($u['role'] ?? '', ['Admin', 'Gestionnaire', 'Visionneur', 'Technicien', 'Demandeur'], true) ? $u['role'] : 'Visionneur';
        $GLOBALS['_larka_utilisateur_delegue'] = [
            'Id' => 0, 'Login' => 'principal:' . mb_substr(preg_replace('/[^\w.@\-]/u', '', (string)($u['login'] ?? '')), 0, 80),
            'Role' => $role, 'Nom' => mb_substr((string)($u['nom'] ?? ''), 0, 80), 'Prenom' => mb_substr((string)($u['prenom'] ?? ''), 0, 80),
            'Provider' => 'local', 'Actif' => 1,
        ];
        $GLOBALS['_larka_corps_delegue'] = is_array($req['corps'] ?? null) ? $req['corps'] : [];
        $get = ['action' => $action];
        foreach ((array)($req['get'] ?? []) as $k => $v) {
            if (is_string($k) && preg_match('/^[A-Za-z0-9_]{1,40}$/', $k) && (is_scalar($v)) && $k !== 'action') $get[$k] = mb_substr((string)$v, 0, 2000);
        }
        $_GET = $get;
        $_SERVER['REQUEST_METHOD'] = $methode;
        unset($_SERVER['HTTP_ACCEPT_ENCODING']);
        // Confirmation d'identité faite sur le principal : plafonnée par la durée que le
        // gestionnaire de CE serveur a choisie, s'il en a choisi une (api/IdentiteConfirmee.php).
        require_once __DIR__ . '/IdentiteConfirmee.php';
        $elev = max(0, min(IdentiteConfirmee::plafondDistant($db), (int)($req['elevation'] ?? 0)));
        $_SESSION['connect_elevation'] = $elev > 0 ? time() + $elev : 0;
        // Toute sortie de la route est capturée puis scellée (JSON, ou fichier : collecteur du PC releveur).
        while (ob_get_level()) ob_end_clean();
        ob_start();
        register_shutdown_function(function () use ($db, $repondA) {
            $corps = '';
            while (ob_get_level()) $corps = ob_get_clean() . $corps;
            $code = (int)(http_response_code() ?: 200);
            $type = 'application/json'; $nom = null;
            foreach (headers_list() as $h) {
                if (stripos($h, 'Content-Type:') === 0) $type = trim(substr($h, 13));
                if (stripos($h, 'Content-Disposition:') === 0 && preg_match('/filename="([^"]+)"/', $h, $m)) $nom = $m[1];
            }
            $json = stripos($type, 'json') !== false;
            try {
                $env = self::sceller($db, 'reponse', ['ok' => true, 'version' => self::VERSION,
                    'donnees' => ['code' => $code, 'type' => $type, 'nom' => $nom, 'corps' => $json ? $corps : base64_encode($corps), 'base64' => !$json]], $repondA);
                unset($env['id']);
                header_remove();
                http_response_code(200);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => true, 'data' => ['e' => $env]]);
            } catch (\Throwable $e) {
                header_remove();
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'error' => 'Réponse impossible à sceller.']);
            }
        });
        $method = $methode;
        $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
        require __DIR__ . '/routes/connect.php';
        json_error('Action inconnue : ' . $action, 404);
    }

    // ── Sortants (supervision → principal) ─────────────────────────────────

    /** Note ce que la GMAO doit écrire (demande, relevé, notification) : livré à la synchronisation. */
    public static function sortant(Database $db, string $type, array $donnees): string
    {
        self::schema($db);
        $j = bin2hex(random_bytes(16));
        $db->getPdo()->prepare("INSERT INTO SupervisionSortants (Jeton, Type, Donnees, Cree, Etat) VALUES (?, ?, ?, ?, 'attente')")
           ->execute([$j, $type, json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), (int)(microtime(true) * 1000)]);
        return $j;
    }

    private static function sortantsLire(Database $db): array
    {
        $rows = self::tous($db, "SELECT Jeton, Type, Donnees FROM SupervisionSortants WHERE Etat = 'attente' ORDER BY Cree LIMIT 200");
        $dem = self::tous($db, "SELECT DISTINCT DemandeId FROM ConnectAlarmes WHERE DemandeId IS NOT NULL AND (Etat = 'active' OR Debut >= :d)",
                             ['d' => date('Y-m-d H:i:s', strtotime('-60 days'))]);
        return ['sortants' => array_map(fn($r) => ['jeton' => $r['Jeton'], 'type' => $r['Type'], 'donnees' => json_decode((string)$r['Donnees'], true)], $rows),
                'demandes' => array_map(fn($r) => (int)$r['DemandeId'], $dem)];
    }

    /** Le principal a écrit les sortants ; il envoie aussi son référentiel s'il a changé. */
    private static function appliquerSynchro(Database $db, array $d): array
    {
        $pdo = $db->getPdo();
        $faits = 0;
        foreach ((array)($d['resultats'] ?? []) as $jeton => $res) {
            if (!preg_match('/^[a-f0-9]{32}$/', (string)$jeton)) continue;
            $s = self::un($db, "SELECT Type, Donnees FROM SupervisionSortants WHERE Jeton = :j AND Etat = 'attente'", ['j' => $jeton]);
            if (!$s) continue;
            $don = json_decode((string)$s['Donnees'], true) ?: [];
            // Demande créée par le principal : l'alarme la référence (Id du principal), et une copie
            // locale permet à l'anti-rafale de voir si elle est encore ouverte.
            if ($s['Type'] === 'demande' && !empty($res['demande'])) {
                $idD = (int)$res['demande'];
                if (!$db->fetchOne("SELECT Id FROM DemandesIntervention WHERE Id = :id", ['id' => $idD])) {
                    $pdo->prepare("INSERT INTO DemandesIntervention (Id, Titre, Statut, DateCreation, Batiment) VALUES (?, ?, 'Nouveau', ?, ?)")
                        ->execute([$idD, mb_substr((string)($don['donnees']['titre'] ?? 'Alarme technique'), 0, 150), date('Y-m-d H:i:s'), (string)($don['donnees']['batiment'] ?? '')]);
                }
                if (!empty($don['alarme'])) $pdo->prepare("UPDATE ConnectAlarmes SET DemandeId = ? WHERE Id = ?")->execute([$idD, (int)$don['alarme']]);
            }
            $pdo->prepare("UPDATE SupervisionSortants SET Etat = ?, Resultat = ? WHERE Jeton = ?")
                ->execute([!empty($res['erreur']) ? 'erreur' : 'livre', json_encode($res, JSON_UNESCAPED_UNICODE), $jeton]);
            $faits++;
        }
        $pdo->prepare("DELETE FROM SupervisionSortants WHERE Etat <> 'attente' AND Cree < ?")->execute([(int)((time() - 30 * 86400) * 1000)]);
        // Statuts des demandes suivies (copies locales).
        foreach ((array)($d['demandes'] ?? []) as $idD => $statut) {
            if (is_numeric($idD) && is_string($statut)) $pdo->prepare("UPDATE DemandesIntervention SET Statut = ? WHERE Id = ?")->execute([mb_substr($statut, 0, 30), (int)$idD]);
        }
        $tables = 0;
        if (is_array($d['referentiel'] ?? null)) $tables = self::appliquerReferentiel($db, $d['referentiel']);
        return ['livres' => $faits, 'tables' => $tables];
    }

    /** Copies des tables du principal (bâtiments, équipements, compteurs, derniers relevés). */
    private static function appliquerReferentiel(Database $db, array $ref): int
    {
        $pdo = $db->getPdo();
        $n = 0;
        foreach (array_merge(self::MIROIRS, ['ReleverEnergie']) as $table) {
            if (!isset($ref[$table]) || !is_array($ref[$table])) continue;
            $rows = array_slice($ref[$table], 0, 50000);
            $cols = self::colonnes($db, $table);
            if (!$cols) continue;
            $pdo->beginTransaction();
            try {
                $pdo->exec("DELETE FROM $table");
                foreach ($rows as $r) {
                    if (!is_array($r)) continue;
                    // Seules les colonnes connues ici (mêmes versions de Larka : toutes, en pratique).
                    $r = array_filter($r, fn($k) => is_string($k) && preg_match('/^[A-Za-z0-9_]+$/', $k) && isset($cols[strtolower($k)]), ARRAY_FILTER_USE_KEY);
                    if (!isset($r['Id']) && !isset($r['id'])) continue;
                    $c = array_keys($r);
                    $pdo->prepare("INSERT INTO $table (" . implode(', ', $c) . ') VALUES (' . implode(', ', array_fill(0, count($c), '?')) . ')')
                        ->execute(array_values($r));
                }
                $pdo->commit();
                $n++;
            } catch (\Throwable $e) {
                $pdo->rollBack();
                self::journal('erreur', "Copie de la table $table impossible : " . $e->getMessage());
                continue;
            }
            // PostgreSQL : la séquence suit les Id recopiés (une écriture locale ne doit pas en reprendre un).
            if ($db->getDriver() === 'pgsql') {
                $t = strtolower($table);
                try { $pdo->exec("SELECT setval(pg_get_serial_sequence('$t', 'id'), COALESCE((SELECT MAX(id) FROM $t), 1))"); } catch (\Throwable $e) {}
            }
        }
        return $n;
    }

    /** Colonnes d'une table, en minuscules (PostgreSQL les rend ainsi), lues sur une requête vide. */
    private static function colonnes(Database $db, string $table): array
    {
        if (!preg_match('/^[A-Za-z]+$/', $table)) return [];
        try {
            $st = $db->getPdo()->query("SELECT * FROM $table WHERE 1 = 0");
            $out = [];
            for ($i = 0; $i < $st->columnCount(); $i++) {
                $m = $st->getColumnMeta($i);
                if ($m && isset($m['name'])) $out[strtolower((string)$m['name'])] = true;
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Côté principal : synchronisation et application des sortants
    // ═══════════════════════════════════════════════════════════════════════

    /** Une synchronisation complète (tâche de collecte, chaque minute ; et après une écriture). */
    public static function synchroniser(Database $db): array
    {
        require_once __DIR__ . '/connect/Connect.php';
        Connect::charger();
        $r = self::requete($db, 'sortants', []);
        $resultats = [];
        $demandesParAlarme = [];
        foreach ((array)($r['sortants'] ?? []) as $s) {
            $j = (string)($s['jeton'] ?? '');
            if (!preg_match('/^[a-f0-9]{32}$/', $j)) continue;
            try {
                $resultats[$j] = self::appliquerSortant($db, (string)$s['type'], (array)($s['donnees'] ?? []), $demandesParAlarme);
            } catch (\Throwable $e) {
                $resultats[$j] = ['erreur' => mb_substr($e->getMessage(), 0, 300)];
                self::journal('erreur', 'Synchronisation supervision : ' . $s['type'] . ' non écrit — ' . $e->getMessage());
            }
        }
        // Statuts des demandes que les alarmes de la supervision suivent.
        $statuts = [];
        $ids = array_slice(array_filter(array_map('intval', (array)($r['demandes'] ?? []))), 0, 2000);
        if ($ids) {
            foreach ($db->fetchAll('SELECT Id, Statut FROM DemandesIntervention WHERE Id IN (' . implode(',', $ids) . ')') as $x) $statuts[(int)$x['Id']] = (string)$x['Statut'];
        }
        // Référentiel : envoyé seulement s'il a changé (empreinte).
        $ref = self::referentiel($db);
        $emp = hash('sha256', (string)json_encode($ref));
        $l = self::lire($db);
        $envoi = $emp !== ($l['Referentiel'] ?? '') ? $ref : null;
        $res = self::requete($db, 'synchro', ['resultats' => $resultats ?: (object)[], 'demandes' => $statuts ?: (object)[], 'referentiel' => $envoi]);
        if ($envoi !== null && (int)($res['tables'] ?? 0) > 0) self::ecrire($db, ['Referentiel' => $emp]);
        return ['sortants' => count($resultats), 'referentiel' => $envoi !== null, 'tables' => (int)($res['tables'] ?? 0)];
    }

    /** Synchronise chaque organisation reliée à un serveur de supervision (tâche de collecte). */
    public static function synchroniserTout(?callable $log = null): void
    {
        require_once __DIR__ . '/connect/Connect.php';
        Connect::charger();
        foreach (Connect::organisations() as $o) {
            try {
                $db = $o['base'] === null ? new Database() : new Database($o['base']);
                if (!self::distante($db) || !self::moduleOuvert($db)) continue;
                $r = self::synchroniser($db);
                if ($log && ($r['sortants'] || $r['referentiel'])) $log("« {$o['nom']} » : {$r['sortants']} écriture(s) reçue(s) du serveur de supervision" . ($r['referentiel'] ? ', référentiel envoyé' : ''));
            } catch (\Throwable $e) {
                if ($log) $log("« {$o['nom']} » : serveur de supervision — " . $e->getMessage());
            }
        }
    }

    /** Ce que le serveur de supervision recopie du principal. */
    private static function referentiel(Database $db): array
    {
        $ref = [];
        foreach (self::MIROIRS as $t) {
            try { $ref[$t] = $db->fetchAll("SELECT * FROM $t ORDER BY Id LIMIT 50000"); } catch (\Throwable $e) { /* module jamais ouvert */ }
        }
        // Derniers relevés de chaque compteur : l'index précédent d'un relevé automatique.
        try {
            $rel = [];
            foreach ($db->fetchAll("SELECT * FROM ReleverEnergie ORDER BY CompteurId, Date DESC, Id DESC") as $r) {
                $c = (int)$r['CompteurId'];
                if (count($rel[$c] ?? []) < 3) $rel[$c][] = $r;
            }
            $ref['ReleverEnergie'] = $rel ? array_merge(...array_values($rel)) : [];
        } catch (\Throwable $e) {}
        return $ref;
    }

    /** Écrit dans la base du principal ce que la supervision a produit. */
    private static function appliquerSortant(Database $db, string $type, array $d, array &$demandesParAlarme): array
    {
        switch ($type) {
            case 'demande':
                $don = (array)($d['donnees'] ?? []);
                $id = $db->addDemande([
                    'titre' => mb_substr((string)($don['titre'] ?? 'Alarme technique'), 0, 150),
                    'description' => mb_substr((string)($don['description'] ?? ''), 0, 4000),
                    'batiment' => mb_substr((string)($don['batiment'] ?? ''), 0, 200), 'bureau' => mb_substr((string)($don['bureau'] ?? ''), 0, 100),
                    'urgence' => in_array($don['urgence'] ?? '', ['Urgente', 'Haute', 'Normale', 'Basse'], true) ? $don['urgence'] : 'Normale',
                    'emailDemandeur' => '', 'typeLocalisation' => 'Standard',
                ], Connect::compteTechnique($db));
                if (!empty($d['alarme'])) $demandesParAlarme[(int)$d['alarme']] = $id;
                Connect::journal('info', "Demande d'intervention n° $id créée par une alarme du serveur de supervision", ['demande' => $id]);
                return ['demande' => $id];
            case 'notification':
                $a = (array)($d['alarme_donnees'] ?? []);
                ConnectAlarmes::envoyerNotification($db, $a, $demandesParAlarme[(int)($d['alarme'] ?? 0)] ?? (isset($d['demande']) ? (int)$d['demande'] : null));
                return ['ok' => true];
            case 'releve':
                $lia = new ConnectLiaisons(new ConnectDepot($db), new ConnectAlarmes(new ConnectDepot($db)));
                $cpt = $db->fetchOne("SELECT * FROM CompteurEnergie WHERE Id = :id", ['id' => (int)($d['compteur'] ?? 0)]);
                if (!$cpt) throw new RuntimeException('Compteur n° ' . (int)($d['compteur'] ?? 0) . ' introuvable');
                return ['releve' => $lia->ecrireReleveExterne($cpt, $d)];
            default:
                throw new RuntimeException('Type inconnu : ' . $type);
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Côté principal : relais des écrans « connect_* »
    // ═══════════════════════════════════════════════════════════════════════

    /** Relaye l'appel de l'écran au serveur de supervision et rend sa réponse telle quelle. Ne revient pas. */
    public static function relayerApi(Database $db, string $action, string $methode): void
    {
        $user = require_auth();
        if (!in_array($user['Role'] ?? '', ['Admin', 'Gestionnaire', 'Visionneur', 'Technicien'], true)) require_role($user, ['Admin', 'Gestionnaire', 'Visionneur']);
        if (!self::moduleOuvert($db)) json_error('Larka Connect est désactivé pour votre organisation.', 409);
        $get = $_GET; unset($get['action']);
        $req = ['action' => $action, 'methode' => $methode, 'get' => $get,
                'corps' => in_array($methode, ['POST', 'PUT', 'DELETE'], true) ? get_body() : null,
                'utilisateur' => ['login' => (string)($user['Login'] ?? ''), 'role' => (string)($user['Role'] ?? ''),
                                  'nom' => (string)($user['Nom'] ?? ''), 'prenom' => (string)($user['Prenom'] ?? '')],
                'elevation' => max(0, (int)(($_SESSION['connect_elevation'] ?? 0) - time()))];
        try {
            $r = self::requete($db, 'api', $req, $action === 'connect_detecter' || $action === 'connect_tester' ? 60 : self::DELAI);
        } catch (\Throwable $e) {
            json_error('Serveur de supervision : ' . $e->getMessage(), 502);
        }
        $code = max(200, min(599, (int)($r['code'] ?? 200)));
        $type = (string)($r['type'] ?? 'application/json');
        $corps = !empty($r['base64']) ? (string)base64_decode((string)($r['corps'] ?? ''), true) : (string)($r['corps'] ?? '');
        // Une écriture peut avoir produit des relevés ou des demandes : on les rapatrie tout de suite.
        if ($methode !== 'GET' && $code < 400) { try { self::synchroniser($db); } catch (\Throwable $e) {} }
        if ($action === 'connect_etat' && stripos($type, 'json') !== false) {
            $j = json_decode($corps, true);
            if (is_array($j) && isset($j['data']) && is_array($j['data'])) {
                // Ce qui dépend de la session et du compte d'ICI, pas du serveur de supervision.
                $j['data']['elevation'] = $req['elevation'];
                require_once __DIR__ . '/IdentiteConfirmee.php';
                $j['data']['fournisseur'] = IdentiteConfirmee::fournisseur($user);
                $j['data']['identite_minutes'] = IdentiteConfirmee::minutes($db);
                $j['data']['peut_activer'] = false;
                $l = self::lire($db);
                $j['data']['supervision_distante'] = ['mode' => $l['Mode'], 'url' => $l['Url'], 'dernier_contact' => $l['DernierContact']];
                $corps = (string)json_encode($j, JSON_UNESCAPED_UNICODE);
            }
        }
        if (ob_get_level()) ob_clean();
        http_response_code($code);
        if (!in_array(strtolower(trim(explode(';', $type)[0])), ['application/json', 'application/zip', 'text/csv', 'application/octet-stream',
                                                                   'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], true)) $type = 'application/octet-stream';
        header('Content-Type: ' . $type);
        if (!empty($r['nom']) && preg_match('/^[\w.\-]{1,120}$/', (string)$r['nom'])) header('Content-Disposition: attachment; filename="' . $r['nom'] . '"');
        echo $corps;
        exit;
    }

    // ═══════════════════════════════════════════════════════════════════════

    public static function journal(string $niveau, string $message): void
    {
        if (class_exists('Connect')) Connect::journal($niveau, 'Serveur de supervision : ' . $message);
    }

    /** L'état pour l'écran (jamais de clé privée ni de code). */
    public static function pourEcran(Database $db): array
    {
        $l = self::lire($db);
        $pubL = (string)($l['ClePubliqueLocale'] ?? '');
        $pubD = (string)($l['ClePubliqueDistante'] ?? '');
        return [
            'role' => $l['Role'], 'mode' => $l['Mode'], 'url' => $l['Url'], 'url_locale' => $l['UrlLocale'],
            'appaire' => $pubD !== '', 'etat' => $l['Etat'], 'dernier_contact' => $l['DernierContact'], 'derniere_erreur' => $l['DerniereErreur'],
            'version_distante' => $l['VersionDistante'], 'empreinte' => ($pubL !== '' && $pubD !== '') ? self::empreinte($pubL, $pubD) : null,
            'code_en_cours' => !empty($l['Code']) && (int)$l['CodeExpire'] > time(), 'code_expire' => $l['CodeExpire'] ? date('H:i', (int)$l['CodeExpire']) : null,
            'sodium' => self::disponible(), 'misajour_par' => $l['MisAJourPar'] ?? null, 'misajour_le' => $l['MisAJourLe'] ?? null,
            'sortants_en_attente' => self::compterSortants($db),
        ];
    }

    private static function compterSortants(Database $db): int
    {
        try { return (int)(self::un($db, "SELECT COUNT(*) AS n FROM SupervisionSortants WHERE Etat = 'attente'")['n'] ?? 0); } catch (\Throwable $e) { return 0; }
    }
}

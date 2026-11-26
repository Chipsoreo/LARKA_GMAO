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
 *  Larka Connect — équipements raccordés
 * ═══════════════════════════════════════════════════════════════════════════
 *
 *  Relie Larka aux équipements techniques du bâtiment — GTB/GTC, compteurs,
 *  capteurs, bornes de recharge — pour que la GMAO travaille avec leurs
 *  données : relevés de compteurs écrits tout seuls dans Énergie, alarmes qui
 *  deviennent des demandes d'intervention, compteurs d'heures qui déclenchent
 *  la maintenance, consignes modifiables avec confirmation d'identité.
 *
 *  ORGANISATION DU CODE (api/connect/)
 *    Connect.php      ce fichier : activation, organisations, réglages, secrets
 *    Pilote.php       contrat commun des protocoles + registre
 *    pilotes/*.php    Modbus TCP, BACnet/IP, MQTT, REST, CSV, OCPP
 *    Depot.php        accès aux tables Connect* (créées par Database.php)
 *    Planif.php       calendriers des liaisons (jours, mois, cron), heure légale
 *    Alarmes.php      règles → alarmes → demandes d'intervention, notifications
 *    Liaisons.php     « ce point alimente telle fiche » : relevés d'énergie
 *    Collecte.php     le passage du planificateur, pour toutes les organisations
 *    OcppServeur.php  le service permanent qui reçoit les bornes
 *
 *  ACTIVATION, À DEUX NIVEAUX
 *    - serveur : config.json « connect.actif » (défaut false). Raccorder des
 *      équipements fait parler le serveur au réseau technique : c'est à
 *      l'administrateur du serveur de le décider ;
 *    - organisation : module du cœur « connect », désactivable par le Super
 *      Admin comme les autres (liste modules_disabled).
 *
 *  LA COLLECTE EST FAITE PAR LE SERVEUR, JAMAIS PAR LE NAVIGATEUR
 *    api/outils/connect-collecte.php passe chaque minute (cron en production,
 *    minuterie systemd, planificateur de start.sh, lanceur Windows, relais
 *    PHP-FPM) et ne traite que ce qui est dû. Ouvrir un écran ne déclenche
 *    aucune lecture d'équipement : dix utilisateurs connectés ne font pas dix
 *    fois plus de requêtes vers les automates.
 * ═══════════════════════════════════════════════════════════════════════════
 */

declare(strict_types=1);

require_once __DIR__ . '/Pilote.php';

final class Connect
{
    /** Compte technique au nom duquel les demandes automatiques sont créées. */
    public const LOGIN_TECHNIQUE = 'larka.connect';

    public static function charger(): void
    {
        require_once dirname(__DIR__) . '/CodesErreur.php';   // codes d'erreur des évènements de supervision
        require_once __DIR__ . '/Depot.php';
        require_once __DIR__ . '/Planif.php';
        require_once __DIR__ . '/Alarmes.php';
        require_once __DIR__ . '/Liaisons.php';
        require_once __DIR__ . '/Consommations.php';
        require_once __DIR__ . '/Lecture.php';
        require_once dirname(__DIR__) . '/SupervisionLien.php';
        require_once __DIR__ . '/Collecte.php';
        require_once __DIR__ . '/Detection.php';
        require_once __DIR__ . '/Batiments.php';
        require_once __DIR__ . '/Releveur.php';
        require_once __DIR__ . '/Reconnaissance.php';
        ConnectPilotes::charger();
    }

    /** Réglage de la section « connect » de config.json, avec défaut. */
    public static function reglage(string $cle, $defaut = null)
    {
        $c = function_exists('cfg') ? cfg('connect') : null;
        if (!is_array($c) || !array_key_exists($cle, $c)) return $defaut;
        return $c[$cle];
    }

    public static function actifServeur(): bool
    {
        return filter_var(self::reglage('actif', false), FILTER_VALIDATE_BOOLEAN);
    }

    /** Réglages OCPP normalisés. */
    public static function ocpp(): array
    {
        $o = self::reglage('ocpp', []);
        $o = is_array($o) ? $o : [];
        return [
            'actif' => filter_var($o['actif'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'hote' => (string)($o['hote'] ?? '0.0.0.0'),
            'port' => max(1, min(65535, (int)($o['port'] ?? 9000))),
            'adresse_publique' => (string)($o['adresse_publique'] ?? ''),
        ];
    }

    /** Réglages du serveur LoRaWAN intégré, normalisés. */
    public static function lorawan(): array
    {
        $o = self::reglage('lorawan', []);
        $o = is_array($o) ? $o : [];
        $gw = $o['passerelles'] ?? [];
        if (is_string($gw)) $gw = preg_split('/[\s,;]+/', $gw) ?: [];
        return [
            'actif' => filter_var($o['actif'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'hote' => (string)($o['hote'] ?? '0.0.0.0'),
            'port' => max(1, min(65535, (int)($o['port'] ?? 1700))),
            'passerelles' => array_values(array_filter(array_map(fn($e) => strtoupper(trim((string)$e)), (array)$gw))),
            'puissance' => max(2, min(27, (int)($o['puissance'] ?? 14))),
        ];
    }

    /** Larka Connect est-il ouvert pour l'organisation de cette base ? */
    public static function actifPour(Database $db): bool
    {
        if (!self::actifServeur()) return false;
        try {
            $row = $db->fetchOne("SELECT Valeur FROM Configuration WHERE Cle = :k", ['k' => 'modules_disabled']);
            $liste = json_decode((string)($row['Valeur'] ?? ''), true);
            return !(is_array($liste) && in_array('connect', $liste, true));
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Organisations à traiter : une en installation simple, toutes les actives
     * en multi-tenant. @return list<array{cle: string, nom: string, base: ?array}>
     */
    public static function organisations(): array
    {
        if (class_exists('TenantResolver') && TenantResolver::isMultiTenant()) {
            $out = [];
            foreach (TenantResolver::getAllTenants() as $cle => $t) {
                if (empty($t['actif']) || empty($t['base_de_donnees'])) continue;
                $out[] = ['cle' => (string)$cle, 'nom' => (string)($t['nom'] ?? $cle), 'base' => (array)$t['base_de_donnees']];
            }
            return $out;
        }
        return [['cle' => 'default', 'nom' => 'Installation', 'base' => null]];
    }

    /** Clé de l'organisation de la requête en cours (multi-tenant), ou « default ». */
    public static function organisationCourante(): string
    {
        if (class_exists('TenantResolver') && TenantResolver::isMultiTenant()) {
            return (string)($_SESSION['tenant_key'] ?? $_SESSION['forced_tenant'] ?? 'default');
        }
        return 'default';
    }

    // ── Secrets des connexions ─────────────────────────────────────────────
    //
    // Mots de passe, jetons : chiffrés en base (AES-256-GCM, clé config.key),
    // jamais renvoyés à l'écran. Une sauvegarde restaurée sur un autre serveur
    // sans config.key garde ses connexions, mais leurs secrets sont à ressaisir.

    public static function chiffrer(array $secrets): ?string
    {
        $secrets = array_filter($secrets, fn($v) => is_string($v) && $v !== '');
        if (!$secrets) return null;
        require_once dirname(__DIR__) . '/ConfigCrypto.php';
        return ConfigCrypto::encrypt(json_encode($secrets, JSON_UNESCAPED_UNICODE));
    }

    public static function dechiffrer(?string $stocke): array
    {
        if ($stocke === null || $stocke === '') return [];
        require_once dirname(__DIR__) . '/ConfigCrypto.php';
        try {
            $clair = ConfigCrypto::decrypt($stocke);
        } catch (\Throwable $e) {
            return ['_illisible' => true];
        }
        $a = json_decode($clair, true);
        return is_array($a) ? $a : [];
    }

    // ── Journal ────────────────────────────────────────────────────────────

    public static function journal(string $niveau, string $message, array $contexte = []): void
    {
        if (!class_exists('Journal')) return;
        try {
            match ($niveau) {
                'erreur' => Journal::erreur('connect', $message, $contexte),
                'avertissement' => Journal::log(Journal::WARNING, 'connect', $message, $contexte),
                default => Journal::info('connect', $message, $contexte),
            };
        } catch (\Throwable $e) { /* jamais bloquant */ }
    }

    /** Compte technique (désactivé : il ne peut pas se connecter) ; le crée au besoin. */
    public static function compteTechnique(Database $db): array
    {
        $u = $db->fetchOne("SELECT Id, Login, Nom, Prenom FROM Utilisateurs WHERE LOWER(Login) = :l", ['l' => self::LOGIN_TECHNIQUE]);
        if (!$u) {
            $db->getPdo()->prepare("INSERT INTO Utilisateurs (Nom, Prenom, Login, MotDePasse, Role, DateCreation, Actif, Service, Poste)
                                    VALUES ('Connect', 'Larka', :l, :p, 'Demandeur', :d, 0, 'Larka Connect', :poste)")
                ->execute(['l' => self::LOGIN_TECHNIQUE, 'p' => password_hash(bin2hex(random_bytes(24)), PASSWORD_BCRYPT),
                           'd' => date('Y-m-d'), 'poste' => 'Compte technique des demandes automatiques (ne peut pas se connecter)']);
            $u = $db->fetchOne("SELECT Id, Login, Nom, Prenom FROM Utilisateurs WHERE LOWER(Login) = :l", ['l' => self::LOGIN_TECHNIQUE]);
        }
        return $u ?: ['Id' => 0, 'Login' => self::LOGIN_TECHNIQUE, 'Nom' => 'Connect', 'Prenom' => 'Larka'];
    }

    /** Arrondi d'une valeur lue : 6 décimales au plus, sans bruit de flottant. */
    public static function arrondi(?float $v): ?float
    {
        if ($v === null) return null;
        return round($v, 6);
    }
}

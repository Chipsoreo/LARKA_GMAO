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
 * Larka — Chiffrement des secrets de config.json
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * OBJECTIF
 * --------
 * Empêcher qu'un coup d'œil rapide sur config.json (collègue qui regarde le
 * serveur, capture d'écran, dump de fichiers, backup non chiffré) expose les
 * mots de passe BDD, secrets OAuth, identifiants SMTP, clés VAPID, etc.
 *
 * MODÈLE DE MENACE COUVERT
 * ------------------------
 * ✅ Lecture du fichier config.json par quelqu'un qui ne devrait pas
 *    (collègue curieux, capture d'écran, backup non chiffré copié ailleurs).
 * ✅ Push accidentel du config.json sur un dépôt git public/interne.
 *
 * NON COUVERT (limites honnêtes)
 * ------------------------------
 * ❌ Attaquant qui a un shell sur le serveur avec les droits du serveur web :
 *    il peut lire config.key et déchiffrer comme l'app. Aucune solution sans
 *    HSM/KMS ne protège contre ça.
 * ❌ Memory dump de PHP en cours d'exécution : les secrets sont déchiffrés
 *    en mémoire pour pouvoir servir.
 *
 * ARCHITECTURE
 * ------------
 * - Algorithme : AES-256-GCM (authenticated encryption, intégrité comprise).
 * - Clé : 32 octets aléatoires stockés dans config.key (chmod 600), généré
 *   automatiquement au premier démarrage s'il n'existe pas.
 * - Format des valeurs chiffrées dans config.json :
 *       "enc:v1:<base64(iv|tag|ciphertext)>"
 *   Le préfixe "enc:v1:" est le marqueur de version qui permet de :
 *     • distinguer une valeur chiffrée d'une valeur en clair (migration douce)
 *     • faire évoluer l'algo plus tard sans casser l'existant
 *
 * UTILISATION
 * -----------
 *   ConfigCrypto::encrypt('mon-mot-de-passe')  → 'enc:v1:...'
 *   ConfigCrypto::decrypt('enc:v1:...')        → 'mon-mot-de-passe'
 *   ConfigCrypto::isEncrypted($v)              → true si commence par 'enc:v1:'
 *   ConfigCrypto::decryptIfNeeded($v)          → déchiffre si chiffré, sinon retourne tel quel
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 */

class ConfigCrypto {
    private const VERSION = 'v1';
    private const PREFIX  = 'enc:v1:';
    private const CIPHER  = 'aes-256-gcm';
    private const IV_LEN  = 12;   // 96 bits, recommandé pour GCM
    private const TAG_LEN = 16;   // 128 bits, défaut GCM

    /** @var string|null Cache de la clé (32 octets binaires) — chargée à la 1re utilisation */
    private static ?string $key = null;

    /**
     * Retourne la clé de chiffrement (32 octets binaires).
     * - Si config.key existe : lit son contenu (hex 64 chars → 32 octets).
     * - Sinon : génère une nouvelle clé, l'écrit avec chmod 600, et la retourne.
     *
     * Le fichier config.key est volontairement placé À CÔTÉ de config.json,
     * pas dans data/ : ça permet de l'exclure facilement des backups du
     * répertoire data/ (qui contient justement les sauvegardes BDD, plans, etc.).
     */
    public static function getKey(): string {
        if (self::$key !== null) return self::$key;

        $keyFile = self::keyPath();

        if (file_exists($keyFile)) {
            $raw = trim((string)@file_get_contents($keyFile));
            // Format attendu : 64 caractères hex → 32 octets
            if (strlen($raw) === 64 && ctype_xdigit($raw)) {
                self::$key = hex2bin($raw);
                return self::$key;
            }
            // Fichier corrompu ou format inattendu : on refuse de générer une
            // nouvelle clé pour ne PAS perdre l'accès aux valeurs chiffrées.
            throw new \RuntimeException(
                "config.key existe mais son contenu est invalide (attendu : 64 caractères hexadécimaux). " .
                "Si la clé a été perdue, restaurez-la depuis une sauvegarde, ou supprimez config.key " .
                "ET remettez à zéro toutes les valeurs sensibles en clair dans config.json."
            );
        }

        // Génération d'une nouvelle clé
        $bin = random_bytes(32);
        $hex = bin2hex($bin);

        // chmod 600 AVANT d'écrire le contenu, pour éviter une fenêtre de
        // lecture par d'autres utilisateurs entre le touch et le chmod.
        $tmp = $keyFile . '.tmp';
        if (file_put_contents($tmp, $hex, LOCK_EX) === false) {
            throw new \RuntimeException("Impossible d'écrire la clé dans $keyFile. Vérifiez les droits du dossier.");
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $keyFile)) {
            @unlink($tmp);
            throw new \RuntimeException("Impossible de finaliser l'écriture de $keyFile.");
        }

        self::$key = $bin;
        return self::$key;
    }

    /** Chemin absolu du fichier config.key (à côté de config.json). */
    public static function keyPath(): string {
        return __DIR__ . '/../config.key';
    }

    /**
     * Chiffre une valeur en clair.
     * - Les valeurs vides sont retournées telles quelles (pas la peine de
     *   chiffrer une chaîne vide, ça simplifie aussi l'UI).
     * - Les valeurs déjà chiffrées sont retournées telles quelles (idempotent).
     */
    public static function encrypt(string $plain): string {
        if ($plain === '') return '';
        if (self::isEncrypted($plain)) return $plain;

        $key = self::getKey();
        $iv  = random_bytes(self::IV_LEN);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plain,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '', // pas d'AAD
            self::TAG_LEN
        );
        if ($ciphertext === false) {
            throw new \RuntimeException('Échec du chiffrement OpenSSL : ' . openssl_error_string());
        }
        return self::PREFIX . base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Déchiffre une valeur. Lève une exception si le format est invalide ou
     * si l'authentification GCM échoue (= la valeur a été modifiée ou la
     * mauvaise clé est utilisée).
     */
    public static function decrypt(string $cipher): string {
        if ($cipher === '') return '';
        if (!self::isEncrypted($cipher)) {
            // Tolérance : si on demande de déchiffrer une valeur en clair,
            // on la retourne telle quelle (utile pour la migration douce).
            return $cipher;
        }

        $payload = base64_decode(substr($cipher, strlen(self::PREFIX)), true);
        if ($payload === false || strlen($payload) < self::IV_LEN + self::TAG_LEN + 1) {
            throw new \RuntimeException('Valeur chiffrée invalide (format).');
        }
        $iv         = substr($payload, 0, self::IV_LEN);
        $tag        = substr($payload, self::IV_LEN, self::TAG_LEN);
        $ciphertext = substr($payload, self::IV_LEN + self::TAG_LEN);

        $plain = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            self::getKey(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        if ($plain === false) {
            throw new \RuntimeException(
                'Échec du déchiffrement (mauvaise clé, valeur altérée, ou config.key régénéré ?). ' .
                'Vérifiez que config.key correspond bien à celui utilisé pour chiffrer config.json.'
            );
        }
        return $plain;
    }

    /** Retourne true si la valeur a le marqueur de version "enc:v1:". */
    public static function isEncrypted(mixed $value): bool {
        return is_string($value) && str_starts_with($value, self::PREFIX);
    }

    /**
     * Déchiffre si la valeur est chiffrée, sinon retourne telle quelle.
     * Pratique pour appliquer en boucle sur les champs sensibles d'un config
     * partiellement migré (pendant la première lecture, certains champs sont
     * encore en clair et seront chiffrés à la première écriture).
     */
    public static function decryptIfNeeded(mixed $value): mixed {
        if (!is_string($value)) return $value;
        if (!self::isEncrypted($value)) return $value;
        return self::decrypt($value);
    }

    /**
     * Liste des chemins (section.cle ou section.sous_section.cle) considérés
     * comme sensibles et donc à chiffrer automatiquement à l'écriture / à
     * déchiffrer automatiquement à la lecture.
     *
     * ⚠️ Cette liste DOIT rester synchronisée entre :
     *   - la lecture (config.php décrypte ces champs après json_decode)
     *   - l'écriture (gestion.php config_serveur POST chiffre ces champs avant json_encode)
     *
     * Format : tableau de chemins en notation pointée.
     */
    public static function sensitivePaths(): array {
        return [
            'base_de_donnees.password',
            'superadmin_db.password',
            'securite.secret_key',
            'microsoft_oauth.client_secret',
            'google_oauth.client_secret',
            'smtp.password',
            'legifrance.client_secret',
            'push.vapid_private_pem',
        ];
    }

    /**
     * Applique encrypt() sur tous les champs sensibles d'un tableau de config,
     * en place. Utilisé avant l'écriture du fichier.
     */
    public static function encryptSensitiveFields(array &$cfg): void {
        foreach (self::sensitivePaths() as $path) {
            $parts = explode('.', $path);
            self::_apply($cfg, $parts, [self::class, 'encrypt']);
        }
    }

    /**
     * Applique decryptIfNeeded() sur tous les champs sensibles d'un tableau
     * de config, en place. Utilisé après la lecture du fichier.
     */
    public static function decryptSensitiveFields(array &$cfg): void {
        foreach (self::sensitivePaths() as $path) {
            $parts = explode('.', $path);
            self::_apply($cfg, $parts, [self::class, 'decryptIfNeeded']);
        }
    }

    /** Helper interne : applique $fn sur $cfg[$parts[0]][$parts[1]]... (récursif). */
    private static function _apply(array &$cfg, array $parts, callable $fn): void {
        if (count($parts) === 1) {
            $k = $parts[0];
            if (isset($cfg[$k]) && is_string($cfg[$k]) && $cfg[$k] !== '') {
                try {
                    $cfg[$k] = $fn($cfg[$k]);
                } catch (\Throwable $e) {
                    // En cas d'erreur de déchiffrement, on logge mais on ne
                    // casse pas le chargement de l'app : la valeur reste telle
                    // quelle et générera une erreur de connexion plus parlante
                    // (ex: "auth failed BDD") qui aidera au diagnostic.
                    error_log("[ConfigCrypto] Échec sur '$k' : " . $e->getMessage());
                }
            }
            return;
        }
        $head = array_shift($parts);
        if (!isset($cfg[$head]) || !is_array($cfg[$head])) return;
        self::_apply($cfg[$head], $parts, $fn);
    }
}

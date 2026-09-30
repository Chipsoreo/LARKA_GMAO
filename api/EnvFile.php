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
 * Larka — Gestion du fichier .env (secrets en variables d'environnement)
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * OBJECTIF
 * --------
 * Sortir les données sensibles (mots de passe BDD, secrets OAuth, identifiants
 * SMTP, clé privée VAPID, clé secrète applicative…) de config.json et les
 * stocker dans des variables d'environnement, chargées depuis un fichier .env
 * à la racine du projet (chmod 600).
 *
 * RÉPARTITION
 * -----------
 *   .env         → UNIQUEMENT les secrets (clés, mots de passe, jetons).
 *   config.json  → tout le reste, non sensible (hôtes, ports, options, CSP…).
 *
 * PRIORITÉ DE LECTURE (gérée dans config.php) :
 *   1. variable d'environnement réelle (export shell, systemd, conteneur…)
 *   2. fichier .env
 *   3. valeur en clair / chiffrée laissée dans config.json (rétro-compat)
 *
 * Cette classe fournit :
 *   - secretEnvMap()  : la table « section.clé » → « NOM_DE_VARIABLE »
 *   - read()          : lit le .env courant (clé => valeur)
 *   - set()           : écrit/met à jour des clés dans le .env (atomique, 600)
 *   - isManagedByFile(): la clé est-elle présente dans NOTRE fichier .env ?
 *   - isManagedByOsEnv(): la clé vient-elle d'une variable d'env hors fichier ?
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 */

class EnvFile {

    /** Chemin absolu du fichier .env (à la racine, à côté de config.json). */
    public static function path(): string {
        return __DIR__ . '/../.env';
    }

    /**
     * Table de correspondance des champs gérés en VARIABLES D'ENVIRONNEMENT
     * vers le fichier .env. Deux catégories :
     *   - secret=true  : secrets masqués dans l'interface (mots de passe, clés…)
     *   - secret=false : identifiants/adresses de connexion (Azure AD, Google…),
     *                    privés mais visibles/éditables dans l'interface.
     *
     * ⚠️ Toute évolution de cette table doit rester cohérente avec :
     *   - config.php           (injection depuis l'env vers $_cfg)
     *   - .env.example         (documentation des variables)
     *   - configuration.js     (UI : champs marqués « géré par .env »)
     *
     * Format : 'section.cle' => ['env' => 'NOM_VARIABLE', 'secret' => bool]
     */
    public static function envMap(): array {
        return [
            // ── Secrets (masqués dans l'interface) ──────────────────────────
            'base_de_donnees.password'      => ['env' => 'GMAO_DB_PASSWORD',              'secret' => true],
            'superadmin_db.password'        => ['env' => 'GMAO_SUPERADMIN_DB_PASSWORD',   'secret' => true],
            'securite.secret_key'           => ['env' => 'GMAO_SECRET_KEY',               'secret' => true],
            'microsoft_oauth.client_secret' => ['env' => 'GMAO_MICROSOFT_CLIENT_SECRET',  'secret' => true],
            'google_oauth.client_secret'    => ['env' => 'GMAO_GOOGLE_CLIENT_SECRET',     'secret' => true],
            'legifrance.client_secret'      => ['env' => 'GMAO_LEGIFRANCE_CLIENT_SECRET', 'secret' => true],
            'smtp.password'                 => ['env' => 'GMAO_SMTP_PASSWORD',            'secret' => true],
            'push.vapid_private_pem'        => ['env' => 'GMAO_VAPID_PRIVATE_KEY',        'secret' => true],
            'assistant.api_key'             => ['env' => 'GMAO_ASSISTANT_API_KEY',        'secret' => true],
            'carbone.impactco2_key'         => ['env' => 'GMAO_IMPACTCO2_API_KEY',        'secret' => true],

            // ── Connexions / identifiants / adresses (privés mais visibles) ──
            // Sortis de config.json à la demande : paramètres de connexion aux
            // fournisseurs d'identité (Azure AD Microsoft, Google) et adresses de
            // redirection associées. Non masqués (l'admin doit pouvoir les relire
            // et les éditer), mais stockés dans .env.
            'microsoft_oauth.client_id'            => ['env' => 'GMAO_MICROSOFT_CLIENT_ID',            'secret' => false],
            'microsoft_oauth.tenant_id'            => ['env' => 'GMAO_MICROSOFT_TENANT_ID',            'secret' => false],
            'microsoft_oauth.redirect_uri'         => ['env' => 'GMAO_MICROSOFT_REDIRECT_URI',         'secret' => false],
            'microsoft_oauth.redirect_uri_mobile'  => ['env' => 'GMAO_MICROSOFT_REDIRECT_URI_MOBILE',  'secret' => false],
            'google_oauth.client_id'               => ['env' => 'GMAO_GOOGLE_CLIENT_ID',               'secret' => false],
            'google_oauth.redirect_uri'            => ['env' => 'GMAO_GOOGLE_REDIRECT_URI',            'secret' => false],
            'google_oauth.redirect_uri_mobile'     => ['env' => 'GMAO_GOOGLE_REDIRECT_URI_MOBILE',     'secret' => false],
        ];
    }

    /**
     * Tous les champs gérés en environnement (secrets + connexions) sous forme
     * plate « section.cle » => « NOM_VARIABLE ». Utilisé pour l'injection, le
     * routage des sauvegardes et la migration.
     */
    public static function allEnvMap(): array {
        $out = [];
        foreach (self::envMap() as $path => $meta) $out[$path] = $meta['env'];
        return $out;
    }

    /**
     * Sous-ensemble SECRET uniquement (champs masqués dans l'interface).
     * Conservé pour le masquage côté UI/API.
     */
    public static function secretEnvMap(): array {
        $out = [];
        foreach (self::envMap() as $path => $meta) {
            if (!empty($meta['secret'])) $out[$path] = $meta['env'];
        }
        return $out;
    }

    /** true si le chemin « section.cle » est un secret (donc à masquer). */
    public static function isSecretPath(string $section, string $cle): bool {
        $meta = self::envMap()[$section . '.' . $cle] ?? null;
        return $meta !== null && !empty($meta['secret']);
    }

    /** Retourne le nom de variable d'env pour un chemin « section.cle », ou null. */
    public static function envNameFor(string $section, string $cle): ?string {
        return self::allEnvMap()[$section . '.' . $cle] ?? null;
    }

    /**
     * Lit le fichier .env et retourne un tableau associatif clé => valeur.
     * Les séquences \n sont conservées telles quelles (déséchappées à l'usage).
     */
    public static function read(): array {
        $file = self::path();
        $out  = [];
        if (!is_file($file) || !is_readable($file)) return $out;

        foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
            $trim = ltrim($line);
            if ($trim === '' || $trim[0] === '#') continue;
            if (!str_contains($line, '=')) continue;
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v);
            // Retirer les guillemets entourant éventuellement la valeur
            if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[strlen($v)-1] === $v[0]) {
                $v = substr($v, 1, -1);
            }
            if ($k !== '') $out[$k] = $v;
        }
        return $out;
    }

    /** true si la clé est définie dans NOTRE fichier .env (modifiable par l'app). */
    public static function isManagedByFile(string $key): bool {
        return array_key_exists($key, self::read());
    }

    /**
     * true si la clé provient d'une variable d'environnement SYSTÈME (export
     * shell, EnvironmentFile systemd, env de conteneur…), auquel cas elle est
     * prioritaire sur le fichier .env. L'application ne peut donc PAS la
     * modifier en réécrivant .env : c'est l'admin système qui gère la valeur,
     * et l'UI l'affiche en lecture seule.
     *
     * Comme env.php n'appelle jamais putenv(), getenv() ne reflète QUE
     * l'environnement système réel — jamais les valeurs issues du fichier .env.
     */
    public static function isManagedByOsEnv(string $key): bool {
        $val = getenv($key);
        if ($val !== false && $val !== '') return true;
        $val = $_SERVER[$key] ?? null;
        return is_string($val) && $val !== '';
    }

    /**
     * Écrit/met à jour un ensemble de clés dans le fichier .env.
     * - Préserve les commentaires et l'ordre des clés existantes.
     * - Ajoute les nouvelles clés à la fin.
     * - Échappe les valeurs multilignes (PEM VAPID) en \n entre guillemets.
     * - Écriture atomique (fichier temporaire + rename) avec chmod 600.
     *
     * @param array<string,string> $kv  clés => valeurs (valeurs en clair)
     * @throws \RuntimeException si l'écriture échoue
     */
    public static function set(array $kv): void {
        if (empty($kv)) return;
        $file = self::path();

        // Lire les lignes existantes pour préserver commentaires et ordre.
        $lines = is_file($file) ? file($file, FILE_IGNORE_NEW_LINES) : [];
        $seen  = [];

        foreach ($lines as $i => $line) {
            $trim = ltrim($line);
            if ($trim === '' || $trim[0] === '#' || !str_contains($line, '=')) continue;
            [$k] = explode('=', $line, 2);
            $k = trim($k);
            if (array_key_exists($k, $kv)) {
                $lines[$i] = $k . '=' . self::encodeValue((string)$kv[$k]);
                $seen[$k]  = true;
            }
        }

        // Ajouter les clés non encore présentes.
        $appended = [];
        foreach ($kv as $k => $v) {
            if (!empty($seen[$k])) continue;
            $appended[] = $k . '=' . self::encodeValue((string)$v);
        }
        if ($appended) {
            if ($lines && end($lines) !== '') $lines[] = '';
            foreach ($appended as $a) $lines[] = $a;
        }

        $content = implode("\n", $lines) . "\n";

        // Écriture atomique + permissions restrictives.
        $tmp = $file . '.tmp.' . getmypid();
        if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
            throw new \RuntimeException("Impossible d'écrire dans le fichier .env (dossier en lecture seule ?).");
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException("Impossible de finaliser l'écriture du fichier .env.");
        }
        @chmod($file, 0600);
    }

    /**
     * Encode une valeur pour le fichier .env.
     * Les valeurs contenant des sauts de ligne, des espaces de début/fin ou des
     * caractères spéciaux sont entourées de guillemets doubles ; les retours à
     * la ligne deviennent \n (déséchappés à la lecture par env()).
     */
    private static function encodeValue(string $v): string {
        $needsQuote = $v === ''
            || $v !== trim($v)
            || str_contains($v, "\n")
            || str_contains($v, '"')
            || str_contains($v, '#')
            || str_contains($v, ' ');
        if (!$needsQuote) return $v;
        $escaped = str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\"', '', '\n'], $v);
        return '"' . $escaped . '"';
    }
}

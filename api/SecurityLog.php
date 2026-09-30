<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka — SecurityLog
 *
 * Journalisation structurée des événements de sécurité. Évite d'avoir à
 * grep des `error_log` éparpillés. Les entrées sont stockées en JSONL
 * (un objet JSON par ligne) dans `data/security/audit.log` pour permettre
 * une analyse post-incident facile (jq, awk, ELK, ...).
 *
 * Évènements surveillés (cf. la méthode statique correspondante) :
 *   - login_failure   : échec d'authentification
 *   - login_success   : authentification réussie
 *   - rate_limited    : kick de rate-limit
 *   - access_denied   : 403 sur action sensible
 *   - sa_action       : opération super-admin (création tenant, switch, …)
 *   - oauth_check     : vérification OAuth (succès/échec)
 *   - config_change   : modification d'un paramètre sensible
 *   - sa_default_password : tentative de login avec MDP par défaut
 *
 * Utilisation :
 *   SecurityLog::audit('login_failure', ['login' => $login, 'ip' => $ip]);
 *
 * Garde-fous :
 *   - Le log est best-effort : un échec d'écriture n'impacte JAMAIS la
 *     requête en cours (try/catch silencieux).
 *   - Rotation simple : si le fichier > 10 MB, il est renommé en .1.log
 *     (rotation à 5 versions max).
 *   - PII : ne loggue PAS les mots de passe, les tokens OAuth bruts ni
 *     les contenus de session — seulement des identifiants et métadonnées.
 */
final class SecurityLog
{
    /** Taille au-delà de laquelle on rote le log (10 MB). */
    private const MAX_SIZE_BYTES = 10 * 1024 * 1024;

    /** Nombre de fichiers de rotation conservés. */
    private const MAX_ROTATIONS = 5;

    /** Champs autorisés dans le contexte (anti-leak PII). */
    private const ALLOWED_CONTEXT_KEYS = [
        // Identifiants utilisateur (login = identifiant, pas un secret)
        'login', 'email', 'user_id', 'role', 'tenant',
        // Métadonnées requête
        'ip', 'user_agent', 'method', 'action', 'path',
        // Détails événement
        'reason', 'rate_key', 'attempts', 'limit',
        'target_tenant', 'target_user', 'target_action',
        'success', 'http_code',
        // Identifiants OAuth (pas le token lui-même)
        'oauth_provider', 'oauth_subject',
    ];

    private static ?string $logDir = null;

    /**
     * Logge un événement de sécurité. Best-effort : ne lève jamais d'exception.
     *
     * @param string $event   Identifiant court de l'événement (snake_case).
     * @param array  $context Contexte additionnel (clés filtrées).
     */
    public static function audit(string $event, array $context = []): void
    {
        try {
            $entry = [
                'ts'    => date('c'),
                'event' => $event,
                'ip'    => $context['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
                'ua'    => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200),
            ];
            // Filtrer le contexte : pas de PII, pas de secrets
            foreach ($context as $k => $v) {
                if (!in_array($k, self::ALLOWED_CONTEXT_KEYS, true)) continue;
                if (is_scalar($v) || is_null($v)) {
                    $entry[$k] = $v;
                } elseif (is_array($v)) {
                    // Aplatir simplement les arrays scalaires
                    $entry[$k] = array_filter($v, 'is_scalar');
                }
            }

            $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($line === false) return; // payload non sérialisable, on abandonne

            $dir = self::getLogDir();
            if ($dir === null) return;

            $path = $dir . '/audit.log';

            // Rotation si nécessaire (best-effort)
            if (file_exists($path) && filesize($path) > self::MAX_SIZE_BYTES) {
                self::rotate($path);
            }

            // Append en lock partagé : sûr en concurrence basique
            @file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);

            // Pont vers le journal applicatif : les évènements de sécurité
            // doivent aussi être consultables depuis l'interface, au même
            // endroit que le reste. Le fichier JSONL ci-dessus reste la source
            // de vérité (il survit à une panne de base).
            if (class_exists('Journal')) {
                $grave = ['login_failure', 'rate_limited', 'access_denied',
                          'default_password_attempt', 'superadmin_action'];
                Journal::log(
                    in_array($event, $grave, true) ? Journal::WARNING : Journal::INFO,
                    'securite',
                    $event,
                    array_merge($context, ['action' => strtoupper($event)])
                );
            }
        } catch (\Throwable $e) {
            // Best-effort : un fail de log ne doit JAMAIS casser la requête.
            // En dev on peut décommenter pour debug :
            // error_log('[SecurityLog] ' . $e->getMessage());
        }
    }

    /**
     * Helpers spécialisés — plus expressifs à l'appel.
     */
    public static function loginFailure(string $login, ?string $reason = null): void
    {
        self::audit('login_failure', ['login' => $login, 'reason' => $reason ?? 'invalid_credentials']);
    }

    public static function loginSuccess(string $login, ?string $tenant = null): void
    {
        self::audit('login_success', ['login' => $login, 'tenant' => $tenant]);
    }

    public static function rateLimited(string $rateKey, int $limit): void
    {
        self::audit('rate_limited', ['rate_key' => $rateKey, 'limit' => $limit]);
    }

    public static function accessDenied(string $action, ?string $reason = null): void
    {
        self::audit('access_denied', ['action' => $action, 'reason' => $reason]);
    }

    public static function superAdminAction(string $action, array $details = []): void
    {
        self::audit('sa_action', array_merge(['target_action' => $action], $details));
    }

    public static function defaultPasswordAttempt(string $login): void
    {
        self::audit('sa_default_password', ['login' => $login, 'reason' => 'default_password_active']);
    }

    /**
     * Pour les outils de monitoring : retourne le chemin courant du log.
     * Retourne null si le répertoire n'a pas pu être créé.
     */
    public static function getLogPath(): ?string
    {
        $dir = self::getLogDir();
        return $dir === null ? null : $dir . '/audit.log';
    }

    private static function getLogDir(): ?string
    {
        if (self::$logDir !== null) return self::$logDir;

        $dir = __DIR__ . '/../data/security';
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0750, true) && !is_dir($dir)) {
                return null;
            }
        }
        // Protéger contre l'accès web (Apache, .htaccess basique)
        $htaccess = $dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "Require all denied\nDeny from all\n");
        }
        self::$logDir = $dir;
        return $dir;
    }

    private static function rotate(string $path): void
    {
        // audit.log → audit.1.log → audit.2.log → ... → audit.N.log (drop)
        for ($i = self::MAX_ROTATIONS; $i >= 1; $i--) {
            $src = $path . '.' . ($i - 1);
            $dst = $path . '.' . $i;
            if ($i === 1) $src = $path;
            if (file_exists($src)) {
                if ($i === self::MAX_ROTATIONS) {
                    @unlink($src); // on perd le plus ancien
                } else {
                    @rename($src, $dst);
                }
            }
        }
    }
}

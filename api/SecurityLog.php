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
        'reason', 'rate_key', 'attempts', 'limit', 'count',
        'target_tenant', 'target_user', 'target_action',
        'success', 'http_code',
        // Identifiants OAuth (pas le token lui-même)
        'oauth_provider', 'oauth_subject',
    ];

    private static ?string $logDir = null;

    /**
     * Évènements « bruyants » : déclenchables sans authentification ou en
     * rafale (échecs, refus). Ils sont soumis à un quota par IP pour qu'un
     * attaquant ne puisse pas NOYER le journal — et, via la rotation à 5
     * fichiers, en chasser les évènements réels. Les évènements rares et
     * décisifs (connexion super admin réussie, purge, changement de config,
     * action sensible réussie) ne sont JAMAIS limités.
     */
    private const BRUYANTS = [
        'login_failure', 'access_denied', 'sa_login_failure', 'rate_limited',
        'password_reset_failure', 'password_change_failure',
    ];
    /** Entrées par (évènement, IP) et par minute au-delà desquelles on résume. */
    private const QUOTA_PAR_MINUTE = 30;

    /**
     * Logge un événement de sécurité. Best-effort : ne lève jamais d'exception.
     *
     * @param string $event   Identifiant court de l'événement (snake_case).
     * @param array  $context Contexte additionnel (clés filtrées).
     */
    public static function audit(string $event, array $context = []): void
    {
        try {
            $ip = (string)($context['ip'] ?? (function_exists('client_ip') ? client_ip() : ($_SERVER['REMOTE_ADDR'] ?? 'unknown')));

            // Quota anti-inondation (évènements bruyants uniquement).
            if (in_array($event, self::BRUYANTS, true)) {
                $q = self::quota($event, $ip);
                if ($q['supprimes'] > 0) {
                    // La fenêtre précédente a débordé : une ligne de synthèse,
                    // pour que l'attaque reste visible sans remplir le disque.
                    self::ecrire([
                        'ts' => date('c'), 'event' => 'log_throttled', 'ip' => $ip,
                        'reason' => $event, 'attempts' => $q['supprimes'],
                        'limit' => self::QUOTA_PAR_MINUTE,
                    ]);
                }
                if (!$q['ecrire']) return;
            }

            $entry = [
                'ts'    => date('c'),
                'event' => $event,
                'ip'    => $ip,
                'ua'    => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200),
            ];
            // Corrélation avec le journal applicatif : même identifiant de
            // requête que les entrées en base (filtre « Corrélation »).
            if (class_exists('Journal')) {
                $entry['request_id'] = Journal::requestId();
            }
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

            self::ecrire($entry);

            // Pont vers le journal applicatif : les évènements de sécurité
            // doivent aussi être consultables depuis l'interface, au même
            // endroit que le reste. Le fichier JSONL ci-dessus reste la source
            // de vérité (il survit à une panne de base).
            if (class_exists('Journal')) {
                // ⚠️ NOMS DÉSALIGNÉS : la liste portait « default_password_attempt »
                // et « superadmin_action », alors que les helpers émettent
                // « sa_default_password » et « sa_action ». Ces deux évènements,
                // parmi les plus sensibles, arrivaient donc au journal en simple
                // INFO — noyés sous le trafic normal. Les deux graphies sont
                // gardées pour les entrées déjà écrites par d'anciennes versions.
                $grave = ['login_failure', 'rate_limited', 'access_denied',
                          'sa_default_password', 'default_password_attempt',
                          'sa_action', 'superadmin_action', 'sa_login_failure',
                          'session_revoked', 'journal_purge', 'journal_export',
                          'password_reset_failure', 'config_change'];
                // Une action sensible qui ÉCHOUE est aussi un signal (refus en
                // série, tentative sur une route d'administration…).
                $echec = array_key_exists('success', $context) && $context['success'] === false;
                // ⚠️ array_merge($context, ['action' => …]) ÉCRASAIT la route.
                // Le contexte porte souvent 'action' = la route visée
                // (« interventions_prevues »…) : elle était remplacée par le
                // nom de l'évènement, et l'écran Journal ne disait plus QUOI
                // avait été refusé. La route passe dans 'cible', et le message
                // la reprend avec le motif, lisibles d'un coup d'œil.
                $ctxJournal = $context;
                if (isset($ctxJournal['action']) && $ctxJournal['action'] !== '') {
                    $ctxJournal['cible'] = $ctxJournal['action'];
                }
                $ctxJournal['action'] = strtoupper($event);
                // Colonne CodeHttp du journal (badge « HTTP 403 » à l'écran).
                if (isset($context['http_code'])) $ctxJournal['codeHttp'] = (int)$context['http_code'];
                $message = $event
                    . (isset($context['action']) && $context['action'] !== '' ? ' : ' . $context['action'] : '')
                    . (isset($context['reason']) && $context['reason'] !== null && $context['reason'] !== ''
                        ? ' (' . $context['reason'] . ')' : '');
                Journal::log(
                    (in_array($event, $grave, true) || $echec) ? Journal::WARNING : Journal::INFO,
                    'securite',
                    $message,
                    $ctxJournal
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

    /** Écrit une entrée (déjà filtrée) dans audit.log, avec rotation. */
    private static function ecrire(array $entry): void
    {
        $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($line === false) return; // payload non sérialisable, on abandonne

        $dir = self::getLogDir();
        if ($dir === null) return;

        $path = $dir . '/audit.log';

        // Rotation si nécessaire (best-effort)
        if (file_exists($path) && filesize($path) > self::MAX_SIZE_BYTES) {
            self::rotate($path);
        }

        // Append sous verrou exclusif : sûr en concurrence basique
        @file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Quota par (évènement, IP), fenêtre fixe d'une minute.
     *
     * Retourne ['ecrire' => bool, 'supprimes' => int] : « supprimes » est le
     * nombre d'entrées écartées pendant la fenêtre PRÉCÉDENTE, à résumer en
     * une ligne. En cas de doute (stockage indisponible), on écrit : perdre
     * une trace est pire que d'en écrire une de trop.
     */
    private static function quota(string $event, string $ip): array
    {
        $dir = self::getLogDir();
        if ($dir === null) return ['ecrire' => true, 'supprimes' => 0];
        $qdir = $dir . '/.quota';
        if (!is_dir($qdir) && !@mkdir($qdir, 0750, true) && !is_dir($qdir)) {
            return ['ecrire' => true, 'supprimes' => 0];
        }
        $fh = @fopen($qdir . '/' . sha1($event . '|' . $ip) . '.json', 'c+');
        if (!$fh) return ['ecrire' => true, 'supprimes' => 0];

        $res = ['ecrire' => true, 'supprimes' => 0];
        try {
            flock($fh, LOCK_EX);
            $d = json_decode((string)stream_get_contents($fh), true);
            $fenetre = intdiv(time(), 60);
            if (!is_array($d) || (int)($d['f'] ?? -1) !== $fenetre) {
                // Nouvelle fenêtre : on rend le compte des supprimés de la précédente.
                $res['supprimes'] = is_array($d) ? (int)($d['s'] ?? 0) : 0;
                $d = ['f' => $fenetre, 'n' => 0, 's' => 0];
            }
            if ((int)$d['n'] >= self::QUOTA_PAR_MINUTE) {
                $d['s'] = (int)$d['s'] + 1;
                $res['ecrire'] = false;
            } else {
                $d['n'] = (int)$d['n'] + 1;
            }
            ftruncate($fh, 0); rewind($fh);
            fwrite($fh, json_encode($d));
            flock($fh, LOCK_UN);
        } finally {
            fclose($fh);
        }

        // Ménage occasionnel des compteurs inactifs (une IP = un fichier).
        if (random_int(1, 200) === 1) {
            foreach (glob($qdir . '/*.json') ?: [] as $f) {
                if (@filemtime($f) < time() - 3600) @unlink($f);
            }
        }
        return $res;
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

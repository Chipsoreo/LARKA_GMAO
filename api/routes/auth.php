<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka — Routes : Authentification
 *
 * Actions : login, logout, me, register, change_password, reset_password,
 *           oauth_microsoft_url, oauth_google_url, auth_config
 */

// ── Connexion locale (avec résolution multi-tenant) ──────────────────────────
if ($action === 'login' && $method === 'POST') {
    $body = get_body();
    $login = $body['login'] ?? '';
    $password = $body['password'] ?? '';
    $tenantKey = null;

    // Rate limiting par IP
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    check_rate_limit('login_' . $clientIp, 10, 300);
    // Rate limiting par login
    if ($login) check_rate_limit('login_user_' . strtolower($login), 5, 300);

    if (TenantResolver::isMultiTenant()) {
        if (str_contains($login, '@')) {
            // ── Email : résolution par domaine email ──────────────────────────
            $tenantKey = TenantResolver::resolveByEmailDomain($login);
            if (!$tenantKey) {
                // Chercher un tenant wildcard (*)
                foreach (TenantResolver::getAllTenants() as $k => $t) {
                    if (in_array('*', $t['domaines_email'] ?? [])) { $tenantKey = $k; break; }
                }
            }
            // Aussi vérifier le registre comptes locaux (un email peut être un login local)
            if (!$tenantKey) {
                $tenantKey = TenantResolver::resolveLocalAccount($login);
            }
            if (!$tenantKey) {
                $domain = strtolower(substr(strrchr($login, '@'), 1));
                json_error("Aucune organisation trouvée pour le domaine @{$domain}. Contactez l'administrateur.", 403);
            }
        } else {
            // ── Login sans @ : résolution par registre comptes locaux ─────────
            $tenantKey = TenantResolver::resolveLocalAccount($login);
            if (!$tenantKey) {
                // Fallback : chercher un tenant wildcard
                foreach (TenantResolver::getAllTenants() as $k => $t) {
                    if (in_array('*', $t['domaines_email'] ?? [])) { $tenantKey = $k; break; }
                }
            }
            if (!$tenantKey) {
                json_error("Compte \"{$login}\" non rattaché à une base de données. Contactez l'administrateur.", 403);
            }
        }

        // Connecter la bonne DB
        $tenantDbCfg = TenantResolver::getDbConfigForTenant($tenantKey);
        if ($tenantDbCfg) {
            $db = new Database($tenantDbCfg);
            $_SESSION['tenant_key'] = $tenantKey;
        } else {
            // ⚠️ FIX SÉCURITÉ/BUG : on a résolu un tenantKey mais sa config DB
            // n'est pas chargeable. On ne DOIT pas tenter le login sur la
            // base par défaut — sinon le password de l'utilisateur d'un
            // tenant peut être vérifié contre les utilisateurs d'un autre
            // tenant (bypass de l'isolation multi-tenant).
            error_log("[AUTH] Tenant '{$tenantKey}' résolu mais config DB introuvable — login refusé.");
            json_error('Configuration tenant indisponible. Contactez l\'administrateur.', 503);
        }
    }

    $user = $db->login($login, $password);
    if (!$user) {
        // Log de sécurité — corrélation possible avec les rate_limited
        if (class_exists('SecurityLog')) {
            SecurityLog::loginFailure($login);
        }
        json_error('Identifiant ou mot de passe incorrect.', 401);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    $_SESSION['user'] = $user;
    if ($tenantKey) {
        $_SESSION['tenant_key'] = $tenantKey;
    }
    $user['_tenant'] = $_SESSION['tenant_key'] ?? 'default';
    if (class_exists('SecurityLog')) {
        SecurityLog::loginSuccess($login, $user['_tenant']);
    }
    json_ok($user);
}

// ── Déconnexion ───────────────────────────────────────────────────────────────
if ($action === 'logout' && $method === 'POST') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'] ?? '/',
            $params['domain'] ?? '',
            (bool)($params['secure'] ?? false),
            (bool)($params['httponly'] ?? true)
        );
    }
    session_destroy();
    json_ok('Déconnecté');
}

// ── Vérification session courante ─────────────────────────────────────────────
if ($action === 'me' && $method === 'GET') {
    $user = require_auth();
    $user['_tenant'] = $_SESSION['tenant_key'] ?? $_SESSION['forced_tenant'] ?? 'default';
    json_ok($user);
}

// ── Vérification OAuth (postMessage + polling) ────────────────────────────────
if ($action === 'oauth_check' && $method === 'GET') {
    // 1. Session déjà initialisée (même cookie) → connexion directe
    if (!empty($_SESSION['user'])) {
        json_ok($_SESSION['user']);
    }
    // 2. Token fichier transmis via postMessage (cross-window)
    $oauthToken = $_GET['token'] ?? '';
    if ($oauthToken && preg_match('/^[a-f0-9]{48}$/', $oauthToken)) {
        $tokenFile = __DIR__ . '/../../data/oauth_result_' . $oauthToken . '.json';
        if (file_exists($tokenFile)) {
            $data = json_decode(file_get_contents($tokenFile), true);
            @unlink($tokenFile);
            if ($data && isset($data['user']) && (time() - ($data['ts'] ?? 0)) < 300) {
                $_SESSION['user'] = $data['user'];
                // Restaurer le tenant depuis le token OAuth
                if (!empty($data['tenant_key'])) {
                    $_SESSION['tenant_key'] = $data['tenant_key'];
                }
                json_ok($data['user']);
            }
        }
    }
    json_error('En attente', 202);
}

// ── Configuration OAuth publique (sans secrets) ───────────────────────────────
if ($action === 'auth_config' && $method === 'GET') {
    json_ok([
        'microsoft' => MICROSOFT_ENABLED && MICROSOFT_CLIENT_ID !== '',
        'google'    => GOOGLE_ENABLED    && GOOGLE_CLIENT_ID    !== '',
    ]);
}

// ── URL du serveur (redirect mobile OAuth) ────────────────────────────────────
if ($action === 'server_url' && $method === 'GET') {
    json_ok(['url' => APP_URL]);
}

// ── URL d'autorisation Microsoft ──────────────────────────────────────────────
if ($action === 'oauth_microsoft_url' && $method === 'GET') {
    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;
    // Stocker le state dans un fichier dédié (évite la race condition avec login concurrent)
    $stateDir = __DIR__ . '/../../data/oauth_states';
    if (!is_dir($stateDir)) @mkdir($stateDir, 0700, true);
    file_put_contents($stateDir . '/' . hash('sha256', $state) . '.json', json_encode(['state' => $state, 'ts' => time()]));

    $isMobile    = !empty($_GET['mobile']);
    $redirectUri = ($isMobile && MICROSOFT_REDIRECT_URI_MOBILE)
        ? MICROSOFT_REDIRECT_URI_MOBILE
        : MICROSOFT_REDIRECT_URI;
    if ($isMobile) $_SESSION['oauth_redirect_uri_ms'] = $redirectUri;

    $scopes = 'openid profile email offline_access User.Read User.ReadBasic.All';
    if (SHAREPOINT_ENABLED) $scopes .= ' Files.Read.All Sites.Read.All';
    if (defined('PLANS_PRESENCE_ENABLED') && PLANS_PRESENCE_ENABLED) $scopes .= ' Presence.Read.All Calendars.Read.Shared';

    $params = http_build_query([
        'client_id'     => MICROSOFT_CLIENT_ID,
        'response_type' => 'code',
        'redirect_uri'  => $redirectUri,
        'scope'         => $scopes,
        'response_mode' => 'query',
        'state'         => $state,
        'prompt'        => !empty($_GET['consent']) ? 'consent' : 'select_account',
    ]);
    json_ok(['url' => 'https://login.microsoftonline.com/' . MICROSOFT_TENANT_ID . '/oauth2/v2.0/authorize?' . $params]);
}

// ── Mot de passe oublié — demande de code ────────────────────────────────────
if ($action === 'forgot_password' && $method === 'POST') {
    $body  = get_body();
    $login = trim($body['login'] ?? '');
    $emailInput = trim($body['email'] ?? '');
    if (!$login) json_error('Identifiant requis.');
    if (!$emailInput || !str_contains($emailInput, '@')) json_error('Adresse email requise.');

    // Rate limiting
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    check_rate_limit('forgot_' . $clientIp, 3, 600);
    check_rate_limit('forgot_user_' . strtolower($login), 3, 600);

    // Vérifier que SMTP est configuré (erreur générique côté admin, pas côté UX)
    if (!SMTP_HOST) {
        error_log('Larka: forgot_password appelée mais SMTP non configuré.');
        json_ok(['email' => 'votre adresse email']);
    }

    // ⚠️ FIX SÉCURITÉ : pour éviter l'énumération d'utilisateurs, on retourne
    // toujours une réponse "succès" générique, que le compte existe ou non,
    // qu'il soit local ou OAuth, que l'email corresponde ou non. Les vrais
    // erreurs sont seulement loggées côté serveur.
    $genericOk = ['email' => 'votre adresse email enregistrée'];

    // Compte local ? (sinon on log + on retourne le OK générique)
    if (!$db->isLocalAccount($login)) {
        error_log("Larka: forgot_password — login \"$login\" non local ou inexistant.");
        json_ok($genericOk);
    }

    $emailDb = $db->getUserEmail($login);
    if (!$emailDb) {
        error_log("Larka: forgot_password — login \"$login\" sans email.");
        json_ok($genericOk);
    }
    if (strtolower(trim($emailInput)) !== strtolower(trim($emailDb))) {
        error_log("Larka: forgot_password — email saisi ne correspond pas pour \"$login\".");
        json_ok($genericOk);
    }

    // Générer un code à 6 chiffres
    $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $db->setResetToken($login, $code);

    // Envoyer le mail
    $subject = 'Larka — Code de réinitialisation';
    $body_html = "<div style='font-family:sans-serif;max-width:480px;margin:auto;padding:24px;border:1px solid #e5e7eb;border-radius:8px'>
        <h2 style='color:#1d4ed8;margin-top:0'>🔑 Réinitialisation de mot de passe</h2>
        <p>Votre code temporaire est :</p>
        <div style='font-size:32px;font-weight:700;letter-spacing:6px;text-align:center;padding:16px;background:#f0f9ff;border-radius:8px;color:#1d4ed8;margin:16px 0'>$code</div>
        <p style='color:#6b7280;font-size:13px'>Ce code expire dans <strong>15 minutes</strong>.<br>Si vous n'avez pas demandé cette réinitialisation, ignorez ce message.</p>
        <hr style='border:none;border-top:1px solid #e5e7eb;margin:16px 0'>
        <p style='color:#9ca3af;font-size:11px;text-align:center'>Larka — Gestion de Maintenance Assistée</p>
    </div>";

    $sent = gmao_send_mail($emailDb, $subject, $body_html);
    if (!$sent) {
        // Log uniquement — on retourne quand même OK (générique) pour ne pas
        // donner d'indice sur l'existence du compte vs. un échec SMTP.
        error_log('Larka: forgot_password — échec envoi email à ' . $emailDb);
    }

    // Masquer l'email pour la réponse
    $parts = explode('@', $emailDb);
    $masked = substr($parts[0], 0, 2) . str_repeat('•', max(1, strlen($parts[0]) - 2)) . '@' . $parts[1];
    json_ok(['email' => $masked]);
}

// ── Mot de passe oublié — vérifier code et reset ─────────────────────────────
if ($action === 'reset_password' && $method === 'POST') {
    $body     = get_body();
    $login    = trim($body['login'] ?? '');
    $code     = trim($body['code'] ?? '');
    $newPwd   = $body['newPassword'] ?? '';

    // Rate limiting — brute force code
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    check_rate_limit('reset_' . $clientIp, 5, 600);
    if ($login) check_rate_limit('reset_user_' . strtolower($login), 5, 600);

    if (!$login || !$code || !$newPwd) json_error('Tous les champs sont requis.');
    if (strlen($newPwd) < 8) json_error('Le mot de passe doit contenir au moins 8 caractères.');

    $ok = $db->resetPassword($login, $code, $newPwd);
    if (!$ok) json_error('Code invalide ou expiré. Veuillez redemander un code.');
    json_ok('Mot de passe réinitialisé avec succès.');
}

// ── Changer son mot de passe (connecté) ──────────────────────────────────────
if ($action === 'change_password' && $method === 'POST') {
    $user = require_auth();
    // Rate limiting
    check_rate_limit('chgpwd_' . (int)$user['Id'], 5, 600);
    $body = get_body();
    $oldPwd = $body['oldPassword'] ?? '';
    $newPwd = $body['newPassword'] ?? '';

    if (!$oldPwd || !$newPwd) json_error('Ancien et nouveau mot de passe requis.');
    if (strlen($newPwd) < 8) json_error('Le nouveau mot de passe doit contenir au moins 8 caractères.');

    $ok = $db->changePassword((int)$user['Id'], $oldPwd, $newPwd);
    if (!$ok) json_error('Ancien mot de passe incorrect.');
    json_ok('Mot de passe modifié avec succès.');
}

// ── Mise à jour du profil utilisateur connecté ────────────────────────────────
// Permet à un Demandeur ou un Gestionnaire de compléter ses informations
// personnelles (téléphone, service, poste, bureau...) — utile pour enrichir
// le contexte du chatbot et pour que les autres utilisateurs sachent qui
// contacter en cas de besoin.
//
// SÉCURITÉ — règles importantes :
//  - Les champs sensibles (Login, Role, Provider, MotDePasse, Id, Actif) ne sont
//    JAMAIS modifiables par cette route. Seul un admin via /superadmin peut y toucher.
//  - Pour les comptes non-locaux (Microsoft/Google), les champs synchronisés
//    depuis le provider (Email, Service, Poste, ManagerName...) sont en
//    LECTURE SEULE — la route les ignore silencieusement. C'est pour éviter
//    que la prochaine connexion OAuth écrase la modification de l'utilisateur.
//  - Validation stricte : longueur max, regex sur tél, emails ignorés.
if ($action === 'update_profile' && $method === 'POST') {
    $user = require_auth();
    check_rate_limit('updprof_' . (int)$user['Id'], 20, 600);
    $body = get_body();
    $provider = strtolower($user['Provider'] ?? 'local');
    $isLocal  = ($provider === 'local' || $provider === '');

    // Champs autorisés à la modification + leur validation.
    // Pour chaque champ : 'maxlen' (limite caractères), 'regex' (optionnel),
    //                     'local_only' (true = ignoré pour les comptes Microsoft/Google).
    $fieldSpec = [
        // Téléphone(s) — toujours modifiables même en OAuth (le provider ne les pousse pas)
        'Tel'        => ['maxlen' => 30, 'regex' => '/^[\d\s\+\-\.\(\)]*$/', 'local_only' => false],
        'TelMobile'  => ['maxlen' => 30, 'regex' => '/^[\d\s\+\-\.\(\)]*$/', 'local_only' => false],
        'TelPro'     => ['maxlen' => 30, 'regex' => '/^[\d\s\+\-\.\(\)]*$/', 'local_only' => false],
        // Identité — modifiable uniquement en local (Microsoft/Google la synchronisent)
        'Nom'        => ['maxlen' => 80, 'regex' => null, 'local_only' => true],
        'Prenom'     => ['maxlen' => 80, 'regex' => null, 'local_only' => true],
        // Organisation — modifiable uniquement en local
        'Service'    => ['maxlen' => 100, 'regex' => null, 'local_only' => true],
        'Poste'      => ['maxlen' => 100, 'regex' => null, 'local_only' => true],
        // Email — modifiable uniquement en local (OAuth gère son propre email)
        'Email'      => ['maxlen' => 150, 'regex' => '/^$|^[^\s@]+@[^\s@]+\.[^\s@]+$/', 'local_only' => true],
    ];

    // Construction de la mise à jour SQL en ne gardant que les champs autorisés ET fournis.
    $updates = [];
    $params  = ['id' => (int)$user['Id']];
    $ignored = []; // pour diagnostiquer : champs qu'on a refusés

    foreach ($fieldSpec as $col => $spec) {
        if (!array_key_exists($col, $body)) continue;        // pas fourni → on ne touche pas
        $val = trim((string)$body[$col]);

        // Champ verrouillé pour ce provider → on ignore
        if ($spec['local_only'] && !$isLocal) {
            $ignored[] = $col;
            continue;
        }
        // Validation longueur
        if (mb_strlen($val) > $spec['maxlen']) {
            json_error("Le champ '$col' dépasse la longueur maximale (" . $spec['maxlen'] . ').');
        }
        // Validation regex si présente (la regex doit accepter la chaîne vide)
        if ($spec['regex'] !== null && !preg_match($spec['regex'], $val)) {
            json_error("Le champ '$col' contient des caractères invalides.");
        }
        // OK — on ajoute à la mise à jour
        $updates[] = "\"$col\" = :$col";
        $params[$col] = $val;
    }

    if (empty($updates)) {
        json_ok([
            'message' => 'Aucun champ à mettre à jour.',
            'ignored' => $ignored,
        ]);
    }

    // Exécution
    try {
        $sql = "UPDATE Utilisateurs SET " . implode(', ', $updates) . " WHERE Id = :id";
        $db->execute($sql, $params);
    } catch (\Throwable $e) {
        json_error('Erreur lors de la mise à jour : ' . $e->getMessage());
    }

    // Rafraîchir la session avec les nouvelles valeurs
    $fresh = $db->fetchOne("SELECT * FROM Utilisateurs WHERE Id = :id", ['id' => (int)$user['Id']]);
    if ($fresh) {
        unset($fresh['MotDePasse'], $fresh['ResetToken'], $fresh['ResetTokenExpiry']);
        $_SESSION['user'] = $fresh;
    }

    json_ok([
        'message' => 'Profil mis à jour.',
        'user'    => $fresh,
        'ignored' => $ignored, // pour que le front puisse afficher "X champs ignorés car compte Microsoft"
    ]);
}

// ── Helper : envoi d'email via SMTP ──────────────────────────────────────────
function gmao_send_mail(string $to, string $subject, string $htmlBody): bool {
    $host = SMTP_HOST;
    $port = SMTP_PORT;
    $user = SMTP_USERNAME;
    $pass = SMTP_PASSWORD;
    $from = SMTP_FROM_EMAIL;
    $fromName = SMTP_FROM_NAME;
    $encryption = SMTP_ENCRYPTION;

    if (!$host) return false;

    $cleanHeader = static function (string $value): string {
        return trim(preg_replace('/[\r\n]+/', ' ', $value));
    };

    $to       = $cleanHeader($to);
    $subject  = $cleanHeader($subject);
    $from     = $cleanHeader($from);
    $fromName = $cleanHeader($fromName);

    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    try {
        $boundary = md5(time());
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: $fromName <$from>\r\n";
        $headers .= "Reply-To: $from\r\n";

        // Tenter d'utiliser SMTP via socket
        // ⚠️ FIX SÉCURITÉ (H2) : vérifier le certificat TLS du serveur SMTP par
        // défaut (anti interception/MITM sur le canal mail). Pour un serveur
        // interne à certificat auto-signé, mettre smtp.verify_peer=false dans
        // config.json en connaissance de cause.
        $_smtpVerify = function_exists('cfg') ? (bool)(cfg('smtp', 'verify_peer') ?? true) : true;
        $ctx = stream_context_create(['ssl' => [
            'verify_peer'       => $_smtpVerify,
            'verify_peer_name'  => $_smtpVerify,
            'allow_self_signed' => !$_smtpVerify,
        ]]);
        $prefix = ($encryption === 'ssl') ? 'ssl://' : '';
        $sock = @stream_socket_client("$prefix$host:$port", $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$sock) {
            // Fallback: PHP mail()
            return @mail($to, $subject, $htmlBody, $headers);
        }

        $read = function() use ($sock) {
            $resp = '';
            while (($line = fgets($sock, 512)) !== false) {
                $resp .= $line;
                if (preg_match('/^\d{3} /', $line)) break;
            }
            return $resp;
        };
        $write = function($cmd) use ($sock) { fwrite($sock, $cmd . "\r\n"); };
        $expect = static function (string $resp, array $codes): bool {
            foreach ($codes as $code) {
                if (str_starts_with(trim($resp), (string)$code)) return true;
            }
            return false;
        };

        $greeting = $read();
        if (!$expect($greeting, [220])) { fclose($sock); return false; }

        $write("EHLO " . gethostname());
        $ehlo = $read();
        if (!$expect($ehlo, [250])) { fclose($sock); return false; }

        // STARTTLS if needed
        if ($encryption === 'tls') {
            $write("STARTTLS");
            $startTls = $read();
            if (!$expect($startTls, [220])) { fclose($sock); return false; }
            if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                fclose($sock);
                return false;
            }
            $write("EHLO " . gethostname());
            $ehloTls = $read();
            if (!$expect($ehloTls, [250])) { fclose($sock); return false; }
        }

        // AUTH
        if ($user) {
            $write("AUTH LOGIN");
            if (!$expect($read(), [334])) { fclose($sock); return false; }
            $write(base64_encode($user));
            if (!$expect($read(), [334])) { fclose($sock); return false; }
            $write(base64_encode($pass));
            $resp = $read();
            if (!str_starts_with(trim($resp), '235')) { fclose($sock); return false; }
        }

        $write("MAIL FROM:<$from>");
        if (!$expect($read(), [250])) { fclose($sock); return false; }
        $write("RCPT TO:<$to>");
        if (!$expect($read(), [250, 251])) { fclose($sock); return false; }
        $write("DATA");
        if (!$expect($read(), [354])) { fclose($sock); return false; }
        $write("Subject: $subject");
        $write("To: $to");
        $write($headers);
        $write("");
        $write($htmlBody);
        $write(".");
        if (!$expect($read(), [250])) { fclose($sock); return false; }
        $write("QUIT");
        fclose($sock);
        return true;
    } catch (\Throwable $e) {
        error_log("SMTP error: " . $e->getMessage());
        return @mail($to, $subject, $htmlBody, $headers);
    }
}

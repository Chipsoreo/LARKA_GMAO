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
 * Larka — Configuration (lecture de config.json)
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Lit config.json à la racine et définit toutes les constantes PHP :
 *   - APP_HOST, APP_PORT, APP_ENV, APP_URL
 *   - DB_DRIVER, DB_PATH, DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
 *   - SECRET_KEY, SESSION_LIFETIME, ALLOWED_ORIGIN
 *   - CORS_*, SEC_* (sécurité HTTP)
 *   - DOC_* (documents : taille max, MIME autorisés)
 *   - LOG_* (logs : niveau, rotation)
 *   - BACKUP_* (sauvegardes automatiques)
 *
 * HELPER :
 *   - cfg('section', 'clé') → lit une valeur dans config.json
 *
 * NE PAS MODIFIER CE FICHIER — modifier config.json à la place.
 * ═══════════════════════════════════════════════════════════════════════════════
 */
// ── Version applicative ────────────────────────────────────────────────────
// Source de vérité unique pour la version du produit. Ne pas confondre avec
// le « ?v= » d'index.html, qui est un simple horodatage de cache navigateur
// régénéré à chaque déploiement par start.sh.
define('LARKA_VERSION', '1.0.0');
define('LARKA_VERSION_LABEL', 'V1');

// ── Lecture du config.json ─────────────────────────────────────────────────
$_cfgFile = __DIR__ . '/../config.json';
if (!file_exists($_cfgFile)) {
    // Essayer config.example.json en fallback
    $_cfgExample = __DIR__ . '/../config.example.json';
    if (file_exists($_cfgExample)) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Fichier config.json introuvable. Lancez "bash dev.sh" ou copiez config.example.json en config.json et adaptez les valeurs.']);
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Fichier config.json introuvable. Lancez "bash dev.sh" pour générer la configuration automatiquement.']);
    exit;
}
$_cfg = json_decode(file_get_contents($_cfgFile), true);
if ($_cfg === null) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erreur JSON dans config.json : ' . json_last_error_msg()]);
    exit;
}

// ── Déchiffrement des secrets ──────────────────────────────────────────────
// Les champs sensibles (mots de passe BDD, secrets OAuth, SMTP, VAPID…) peuvent
// être stockés chiffrés dans config.json avec le préfixe "enc:v1:". On les
// déchiffre ici, transparemment, pour que le reste de l'app continue de lire
// les valeurs en clair via cfg() comme avant.
//
// La clé est dans config.key (à côté de config.json, chmod 600). Si le fichier
// n'existe pas encore, il est généré automatiquement à la première utilisation
// (premier appel à ConfigCrypto::encrypt depuis l'UI SuperAdmin).
//
// Migration douce : les valeurs en clair restent acceptées et fonctionnent
// normalement ; elles seront chiffrées à la prochaine sauvegarde via l'UI.
require_once __DIR__ . '/ConfigCrypto.php';
try {
    ConfigCrypto::decryptSensitiveFields($_cfg);
} catch (\Throwable $e) {
    // Ne pas bloquer le boot complet : on log et on continue, les valeurs
    // chiffrées non déchiffrables resteront sous forme "enc:v1:..." et
    // produiront des erreurs claires côté connecteurs (DB, SMTP…).
    error_log('[config.php] Erreur de déchiffrement des secrets : ' . $e->getMessage());
}

// ── Variables d'environnement (.env) : les secrets prennent le pas sur config.json ──
// Les données sensibles (mots de passe BDD, secrets OAuth/SMTP, clé VAPID, clé
// secrète applicative…) sont désormais stockées dans des variables
// d'environnement, chargées depuis le fichier .env (chmod 600) à la racine.
//
// Priorité : variable d'environnement (système OU .env) > valeur de config.json.
//
// On injecte ces valeurs directement dans $_cfg pour que TOUT le code existant
// — qui lit via cfg('section','cle') ou via le tableau global $_cfg — reçoive
// la valeur de l'environnement de façon transparente, sans modification.
//
// Rétro-compatibilité : si aucune variable d'env n'est définie, on garde la
// valeur (en clair ou déchiffrée) de config.json comme avant. La migration
// vers .env est donc indolore.
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/EnvFile.php';
loadEnv();
foreach (EnvFile::allEnvMap() as $_path => $_envName) {
    $_val = env($_envName, null);
    if ($_val === null || $_val === '') continue;
    [$_section, $_cle] = explode('.', $_path, 2);
    if (!isset($_cfg[$_section]) || !is_array($_cfg[$_section])) {
        $_cfg[$_section] = [];
    }
    $_cfg[$_section][$_cle] = $_val;
}
unset($_path, $_envName, $_val, $_section, $_cle);

// ── Helper ─────────────────────────────────────────────────────────────────
function cfg(string ...$keys): mixed {
    global $_cfg;
    $v = $_cfg;
    foreach ($keys as $k) $v = $v[$k] ?? null;
    return $v;
}

// ── Serveur ────────────────────────────────────────────────────────────────
define('APP_HOST', cfg('serveur', 'host') ?? '127.0.0.1');
define('APP_PORT', (int)(cfg('serveur', 'port') ?? 8000));
define('APP_ENV',  cfg('serveur', 'env')  ?? 'dev');

// ── URL de base (construite automatiquement) ───────────────────────────────
$_scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$_host   = $_SERVER['HTTP_HOST'] ?? (APP_HOST . (APP_PORT !== 80 && APP_PORT !== 443 ? ':' . APP_PORT : ''));
define('APP_URL', $_scheme . '://' . $_host);

// ── Multi-tenant (résolution par domaine email) ─────────────────────────────
require_once __DIR__ . '/TenantResolver.php';

// ── Session (configurer AVANT session_start) ───────────────────────────────
// SESSION_LIFETIME doit être défini ICI, avant session_start(), pour pouvoir
// configurer gc_maxlifetime et cookie_lifetime correctement.
// (Sans ça, PHP utilise gc_maxlifetime=1440s par défaut → sessions détruites au bout de 24 min)
define('SESSION_LIFETIME', (int)(cfg('securite', 'session_duree_heures') ?? 24) * 3600);

// ⚠️ FIX SÉCURITÉ (F5) : le cookie de session doit être « Secure » par défaut
// dès que l'application est servie en HTTPS (directement, via le port 443, via
// X-Forwarded-Proto, ou d'après le schéma de APP_URL). On ne retombe sur false
// que pour un accès réellement en clair (HTTP local / dev). Une valeur explicite
// session.cookie_secure dans config.json reste prioritaire.
$_httpsContext = (
    (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    || str_starts_with(strtolower(APP_URL), 'https://')
);
define('SESSION_COOKIE_SECURE',   (bool)(cfg('session', 'cookie_secure')   ?? $_httpsContext));
define('SESSION_COOKIE_HTTPONLY', (bool)(cfg('session', 'cookie_httponly') ?? true));
// ⚠️ FIX SÉCURITÉ : défaut passé de 'Lax' à 'Strict'.
// 'Strict' = le cookie n'est jamais envoyé sur une navigation cross-site
// (même un clic sur un lien externe vers l'app n'enverra PAS le cookie).
// C'est le réglage le plus sûr pour une app interne ; protège totalement
// contre les attaques CSRF même les plus exotiques.
//
// Side-effect connu : si un email contient un lien vers l'app, le clic
// arrive sans cookie → l'utilisateur doit se reconnecter. Pour réautoriser
// ce flow, mettre cookie_samesite='Lax' dans config.json.
define('SESSION_COOKIE_SAMESITE', cfg('session', 'cookie_samesite')        ?? 'Strict');
define('SESSION_COOKIE_PATH',     cfg('session', 'cookie_path')            ?? '/');

// ── Dossier de sessions dédié ──────────────────────────────────
// IMPORTANT : Sans save_path dédié, PHP stocke les sessions dans /tmp.
// Le système (systemd-tmpfiles, cron, etc.) peut nettoyer /tmp à tout moment,
// ce qui détruit les sessions → "Session expirée" aléatoire sur tous les boutons.
// De plus, si d'autres apps PHP tournent avec gc_maxlifetime=1440 (24 min par défaut),
// leur GC supprime AUSSI nos sessions car elles partagent le même dossier /tmp.
$_sessionDir = __DIR__ . '/../data/sessions';
if (!is_dir($_sessionDir)) {
    @mkdir($_sessionDir, 0700, true);
}
if (is_dir($_sessionDir) && is_writable($_sessionDir)) {
    ini_set('session.save_path', $_sessionDir);
}

// ── Détection HTTPS derrière reverse proxy (Caddy, Nginx) ─────
// Caddy envoie X-Forwarded-Proto: https. PHP derrière le proxy ne voit que HTTP.
// Si cookie_secure=true mais PHP croit être en HTTP, le cookie n'est jamais
// renvoyé correctement dans certains cas.
//
// ⚠️ FIX SÉCURITÉ : ne faire confiance à X-Forwarded-Proto que si la requête
// provient d'une IP de proxy de confiance. Sinon n'importe quel client peut
// envoyer ce header et faire croire à PHP qu'il est en HTTPS.
// La liste de proxies de confiance est configurable via cfg('securite', 'trusted_proxies').
// Par défaut : localhost (127.0.0.1, ::1) — le proxy local est toujours acceptable.
$_trustedProxies = cfg('securite', 'trusted_proxies');
if (!is_array($_trustedProxies)) {
    $_trustedProxies = ['127.0.0.1', '::1'];
}
$_remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
$_isFromTrustedProxy = in_array($_remoteIp, $_trustedProxies, true);

$_behindHttpsProxy = $_isFromTrustedProxy && (
    (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
    (!empty($_SERVER['HTTP_X_FORWARDED_SSL'])   && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on')
);
if ($_behindHttpsProxy) {
    $_SERVER['HTTPS'] = 'on';
}

ini_set('session.cookie_secure',   SESSION_COOKIE_SECURE   ? '1' : '0');
ini_set('session.cookie_httponly', SESSION_COOKIE_HTTPONLY ? '1' : '0');
ini_set('session.cookie_samesite', SESSION_COOKIE_SAMESITE);
ini_set('session.cookie_path',     SESSION_COOKIE_PATH);
// ── Sécurité renforcée ──────────────────────────────────────────────────────
ini_set('session.use_strict_mode',  '1'); // Refuse les ID de session invalides
ini_set('session.use_only_cookies', '1'); // Pas de session ID dans l'URL
ini_set('session.use_trans_sid',    '0'); // Pas de réécriture d'URL transparente
ini_set('session.cookie_name', 'GMAO_SID'); // Nom de cookie non-standard (masque la techno)
// Auto-detect secure flag derrière proxy HTTPS
if ($_behindHttpsProxy && !SESSION_COOKIE_SECURE) {
    ini_set('session.cookie_secure', '1');
}
// Aligner gc_maxlifetime et cookie_lifetime sur la durée de session configurée
ini_set('session.gc_maxlifetime',  (string) SESSION_LIFETIME);
ini_set('session.cookie_lifetime', (string) SESSION_LIFETIME);
// Réduire la probabilité de GC pour éviter les ralentissements sur les requêtes normales
// (le GC tourne 1 fois sur 1000 requêtes au lieu de 1/100 par défaut)
ini_set('session.gc_probability', '1');
ini_set('session.gc_divisor',     '1000');

// Démarrer la session tôt pour lire le tenant stocké en session
if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
    session_start();
}

$_tenantDb = null;
$_currentTenantKey = null;
try {
    // Priorité 1 : super admin qui force un tenant
    if (!empty($_SESSION['forced_tenant'])) {
        $_currentTenantKey = $_SESSION['forced_tenant'];
        $_tenantDb = TenantResolver::getDbConfigForTenant($_currentTenantKey);
    }
    // Priorité 2 : tenant résolu et stocké en session (après login par email)
    elseif (!empty($_SESSION['tenant_key'])) {
        $_currentTenantKey = $_SESSION['tenant_key'];
        $_tenantDb = TenantResolver::getDbConfigForTenant($_currentTenantKey);
    }
    // Priorité 3 : résolution par Host header (fallback)
    else {
        $_tenantDb = TenantResolver::getDbConfig();
        $resolved = TenantResolver::resolve();
        $_currentTenantKey = $resolved['key'] ?? 'default';
    }
} catch (\Throwable $e) {
    $_tenantDb = null;
    $_currentTenantKey = 'default';
}

// ── Base de données ────────────────────────────────────────────────────────
// Si un tenant est résolu, ses paramètres DB écrasent ceux de config.json
// Sinon, on utilise config.json comme avant (comportement inchangé)
define('DB_DRIVER',  ($_tenantDb['driver'] ?? null)  ?: (cfg('base_de_donnees', 'driver')   ?? 'sqlite'));
$_dbPath = ($_tenantDb['path'] ?? null) ?: (cfg('base_de_donnees', 'path') ?? 'data/gmao.db');
define('DB_PATH',    str_starts_with($_dbPath, '/') ? $_dbPath : __DIR__ . '/../' . $_dbPath);
define('DB_HOST',    ($_tenantDb['host'] ?? null)    ?: (cfg('base_de_donnees', 'host')     ?? '127.0.0.1'));
define('DB_PORT',    (int)(($_tenantDb['port'] ?? null) ?: (cfg('base_de_donnees', 'port') ?? 5432)));
define('DB_NAME',    ($_tenantDb['dbname'] ?? null)  ?: (cfg('base_de_donnees', 'dbname')   ?? 'gmao'));
define('DB_USER',    ($_tenantDb['user'] ?? null)    ?: (cfg('base_de_donnees', 'user')      ?? ''));
define('DB_PASS',    ($_tenantDb['password'] ?? null) ?: (cfg('base_de_donnees', 'password')  ?? ''));
define('DB_SSLMODE', ($_tenantDb['sslmode'] ?? null) ?: (cfg('base_de_donnees', 'sslmode')   ?? 'prefer'));

// ── Alimenter le registre statique Database pour le multi-tenant ────────────
// Ainsi, tout new Database() (sans argument) utilisera automatiquement la bonne DB
if ($_tenantDb !== null) {
    // On diffère l'appel car Database.php n'est pas encore chargé (require_once dans index.php)
    // On stocke la config dans une globale que Database lira
    $GLOBALS['_gmao_tenant_db_config'] = $_tenantDb;
}

// ── Sécurité ───────────────────────────────────────────────────────────────
define('SECRET_KEY',     cfg('securite', 'secret_key')    ?? 'GMAO_SECRET_KEY_CHANGE_ME');
// ⚠️ FIX SÉCURITÉ (F1) : par défaut, les messages d'erreur renvoyés au client
// sont génériques (pas de détail SQL / chemins / structure interne). Passer
// securite.debug_errors=true dans config.json UNIQUEMENT en développement pour
// réafficher le détail des exceptions.
define('DEBUG_ERRORS',   (bool)(cfg('securite', 'debug_errors') ?? false));
// SESSION_LIFETIME est déjà défini plus haut (avant session_start) — ne pas redéfinir.
define('ALLOWED_ORIGIN', cfg('securite', 'allowed_origin') ?? '*');

// ── CORS ───────────────────────────────────────────────────────────────────
$_corsOrigins = cfg('cors', 'origins') ?? [ALLOWED_ORIGIN];
define('CORS_ORIGINS',      is_array($_corsOrigins) ? $_corsOrigins : [$_corsOrigins]);
$_corsMethods = cfg('cors', 'methods') ?? ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'];
define('CORS_METHODS',      is_array($_corsMethods) ? implode(', ', $_corsMethods) : $_corsMethods);
$_corsHeaders = cfg('cors', 'headers') ?? ['Content-Type', 'Authorization'];
define('CORS_HEADERS',      is_array($_corsHeaders) ? implode(', ', $_corsHeaders) : $_corsHeaders);
define('CORS_CREDENTIALS',  (bool)(cfg('cors', 'allow_credentials') ?? false));

// ── En-têtes de sécurité HTTP ──────────────────────────────────────────────
// NB : l'aperçu intégré des documents (iframe même origine) nécessite que
// l'encadrement en même origine soit autorisé. On FORCE donc frame-ancestors
// 'self' et X-Frame-Options SAMEORIGIN, même si un ancien config.json contient
// encore 'none'/'DENY' (sinon l'aperçu PDF reste bloqué par le navigateur).
// ⚠️ FIX SÉCURITÉ (F2) : retrait de https://unpkg.com (non utilisé au runtime)
// pour réduire la surface d'attaque de type supply-chain. cdnjs.cloudflare.com
// est conservé car réellement utilisé (xlsx, pdf.js). Ajout de base-uri 'self'
// (anti-injection de <base>) et form-action 'self'. NB : 'unsafe-inline' est
// conservé sur script-src car l'app repose sur de nombreux gestionnaires inline ;
// son retrait nécessite une migration vers des nonces (voir rapport).
$__csp = cfg('securite_http', 'csp') ?? "default-src 'self'; base-uri 'self'; form-action 'self'; img-src 'self' data: blob:; script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com blob:; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; worker-src 'self' blob:; connect-src 'self' https://cdnjs.cloudflare.com; object-src 'none'; frame-ancestors 'self'";
$__csp = preg_replace("/frame-ancestors\\s+'none'/i", "frame-ancestors 'self'", $__csp);
if (stripos($__csp, 'frame-ancestors') === false) { $__csp = rtrim($__csp, '; ') . "; frame-ancestors 'self'"; }
define('SEC_CSP', $__csp);
define('SEC_HSTS',             (bool)(cfg('securite_http', 'hsts')          ?? false));
define('SEC_HSTS_MAX_AGE',     (int)(cfg('securite_http', 'hsts_max_age')   ?? 31536000));
$__xf = cfg('securite_http', 'x_frame_options') ?? 'SAMEORIGIN';
if (strtoupper(trim($__xf)) === 'DENY') { $__xf = 'SAMEORIGIN'; }
define('SEC_X_FRAME', $__xf);
define('SEC_X_CONTENT_TYPE',   cfg('securite_http', 'x_content_type_options') ?? 'nosniff');
define('SEC_REFERRER',         cfg('securite_http', 'referrer_policy')     ?? 'no-referrer');

// ── Documents ─────────────────────────────────────────────────────────────
define('DOC_MAX_PAR_CATEGORIE', (int)(cfg('documents', 'max_par_categorie') ?? 4));
define('DOC_TAILLE_MAX_MO',     (float)(cfg('documents', 'taille_max_mo')   ?? 25));
$_mimeAuto = cfg('documents', 'mime_autorises') ?? ['application/pdf', 'image/png', 'image/jpeg', 'image/webp'];
define('DOC_MIME_AUTORISES',    $_mimeAuto);
define('DOC_DEDUP_SHA256',      (bool)(cfg('documents', 'dedup_sha256')     ?? true));

// ── Sauvegardes ───────────────────────────────────────────────────────────
define('BACKUP_ACTIF',        (bool)(cfg('sauvegardes', 'actif')             ?? false));
define('BACKUP_INTERVALLE',   (int)(cfg('sauvegardes', 'intervalle_minutes') ?? 360));
$_backupDir = cfg('sauvegardes', 'dossier') ?? 'data/backups';
define('BACKUP_DOSSIER',      str_starts_with($_backupDir, '/') ? $_backupDir : __DIR__ . '/../' . $_backupDir);
define('BACKUP_GARDER',       (int)(cfg('sauvegardes', 'garder')             ?? 30));
define('BACKUP_COMPRESSER',   (bool)(cfg('sauvegardes', 'compresser')        ?? true));

// ── Relay OAuth (optionnel) ────────────────────────────────────────────────
define('OAUTH_RELAY_URL', cfg('oauth_relay', 'url') ?? '');

function build_oauth_redirect(string $provider): string {
    $relay = OAUTH_RELAY_URL;
    if ($relay) {
        // ⚠️ FIX SÉCURITÉ (F4) : le relais OAuth signe l'URL serveur (HMAC-SHA256)
        // avec SECRET_KEY. Si la clé est restée à sa valeur par défaut, vide, ou
        // trop courte, la signature serait forgeable (n'importe qui connaissant le
        // défaut pourrait détourner le flux vers un serveur arbitraire). On refuse
        // alors d'utiliser le relais et on retombe sur la redirection locale directe.
        if (SECRET_KEY === '' || SECRET_KEY === 'GMAO_SECRET_KEY_CHANGE_ME' || strlen(SECRET_KEY) < 16) {
            error_log('Larka SECURITY (F4): relais OAuth désactivé — définissez une securite.secret_key robuste (>= 16 caractères) dans config.json.');
            return APP_URL . '/oauth/' . $provider;
        }
        $srv = APP_URL;
        $sig = hash_hmac('sha256', $srv, SECRET_KEY);
        return rtrim($relay, '/') . '?provider=' . $provider
             . '&srv=' . urlencode($srv)
             . '&sig=' . $sig;
    }
    return APP_URL . '/oauth/' . $provider;
}

// ── Microsoft OAuth ────────────────────────────────────────────────────────
define('MICROSOFT_ENABLED',       (bool)(cfg('microsoft_oauth', 'actif')        ?? false));
define('MICROSOFT_CLIENT_ID',     cfg('microsoft_oauth', 'client_id')           ?? '');
define('MICROSOFT_TENANT_ID',     cfg('microsoft_oauth', 'tenant_id')           ?? 'common');
define('MICROSOFT_CLIENT_SECRET', cfg('microsoft_oauth', 'client_secret')       ?? '');
define('MICROSOFT_REDIRECT_URI',
    (cfg('microsoft_oauth', 'redirect_uri') ?: '') ?: build_oauth_redirect('microsoft')
);
define('MICROSOFT_REDIRECT_URI_MOBILE',
    cfg('microsoft_oauth', 'redirect_uri_mobile') ?? ''
);

// ── SharePoint (via Microsoft Graph — autodétection par compte) ───────────
// Activé automatiquement dès que Microsoft OAuth est actif.
// Aucune config site/drive nécessaire : tout est découvert via le compte de l'utilisateur.
define('SHAREPOINT_ENABLED', MICROSOFT_ENABLED);

// ── Présence + agenda sur les plans (Microsoft Graph) ─────────
// Ajoute les scopes Presence.Read.All + Calendars.Read.Shared au login Microsoft.
// ⚠️ Ces scopes exigent le CONSENTEMENT ADMINISTRATEUR du tenant Azure. Tant qu'il
// n'est pas accordé, laissez ceci à false (sinon le login Microsoft échouera).
// Étapes : 1) Azure > App registrations > API permissions : ajouter Presence.Read.All
// et Calendars.Read.Shared (delegated) puis « Grant admin consent ». 2) Passez ce
// flag à true dans config.json ("plans_presence": { "actif": true }). 3) Les
// utilisateurs se reconnectent pour obtenir un token incluant ces scopes.
define('PLANS_PRESENCE_ENABLED', MICROSOFT_ENABLED && (bool)(cfg('plans_presence', 'actif') ?? false));

// ── Google OAuth ───────────────────────────────────────────────────────────
define('GOOGLE_ENABLED',       (bool)(cfg('google_oauth', 'actif')        ?? false));
define('GOOGLE_CLIENT_ID',     cfg('google_oauth', 'client_id')           ?? '');
define('GOOGLE_CLIENT_SECRET', cfg('google_oauth', 'client_secret')       ?? '');
define('GOOGLE_REDIRECT_URI',
    (cfg('google_oauth', 'redirect_uri') ?: '') ?: build_oauth_redirect('google')
);
define('GOOGLE_REDIRECT_URI_MOBILE',
    cfg('google_oauth', 'redirect_uri_mobile') ?? ''
);

// ── Accès ──────────────────────────────────────────────────────────────────
define('ALLOWED_EMAIL_DOMAIN', cfg('acces', 'domaine_email_autorise') ?? '');

// ── SMTP ───────────────────────────────────────────────────────────────────
define('SMTP_HOST',       cfg('smtp', 'host')       ?? '');
define('SMTP_PORT',       (int)(cfg('smtp', 'port') ?? 587));
define('SMTP_ENCRYPTION', cfg('smtp', 'secure') ?? cfg('smtp', 'encryption') ?? 'tls');
define('SMTP_USERNAME',   cfg('smtp', 'username')   ?? '');
define('SMTP_PASSWORD',   cfg('smtp', 'password')   ?? '');
define('SMTP_FROM_EMAIL', cfg('smtp', 'from') ?? cfg('smtp', 'from_email') ?? 'noreply@gmao.local');
define('SMTP_FROM_NAME',  cfg('smtp', 'from_name')  ?? 'Larka');

// ── Logs ───────────────────────────────────────────────────────────────────
define('LOG_ERRORS', (bool)(cfg('logs', 'actif') ?? true));

// ── Push Notifications (VAPID) ────────────────────────────────────────────
define('VAPID_PUBLIC_KEY',   cfg('push', 'vapid_public_key')  ?? '');
define('VAPID_SUBJECT',      cfg('push', 'vapid_subject')     ?? 'mailto:admin@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
// La clé privée PEM peut être stockée dans config.json (une ligne avec \n)
// ou dans un fichier séparé. On gère les deux cas.
$_vapidPem = cfg('push', 'vapid_private_pem') ?? '';
if ($_vapidPem && !str_contains($_vapidPem, '-----BEGIN')) {
    // C'est un chemin vers un fichier PEM
    $_pemPath = str_starts_with($_vapidPem, '/') ? $_vapidPem : __DIR__ . '/../' . $_vapidPem;
    $_vapidPem = file_exists($_pemPath) ? file_get_contents($_pemPath) : '';
}
define('VAPID_PRIVATE_PEM', $_vapidPem);

// Configuration des notifications push
define('PUSH_ACTIF',                    (bool)(cfg('push', 'actif') ?? true));
define('PUSH_NOTIF_DEMANDE_TITRE',      cfg('push', 'notif_demande_titre')  ?? '📝 Nouvelle demande d\'intervention');
define('PUSH_NOTIF_DEMANDE_CORPS',      cfg('push', 'notif_demande_corps')  ?? '{demandeur} a soumis une demande d\'intervention.');
define('PUSH_NOTIF_DEMANDE_ICON',       cfg('push', 'notif_demande_icon')   ?? '/apple-touch-icon.png');
define('PUSH_NOTIF_DEMANDE_IMAGE',      cfg('push', 'notif_demande_image')  ?? '');
$_pushRoles = cfg('push', 'notif_demande_roles') ?? ['Admin', 'Gestionnaire'];
define('PUSH_NOTIF_DEMANDE_ROLES',      is_array($_pushRoles) ? $_pushRoles : ['Admin', 'Gestionnaire']);
define('PUSH_NOTIF_DEMANDE_REQUIRE_INTERACTION', (bool)(cfg('push', 'notif_demande_require_interaction') ?? false));
$_logPath = cfg('logs', 'dossier') ?? 'data/logs';
define('LOG_PATH',   str_starts_with($_logPath, '/') ? $_logPath : __DIR__ . '/../' . $_logPath);
define('LOG_NIVEAU', cfg('logs', 'niveau')        ?? 'info');
define('LOG_ROTATE', (int)(cfg('logs', 'rotation_jours') ?? 14));

if (LOG_ERRORS) {
    if (!is_dir(LOG_PATH)) @mkdir(LOG_PATH, 0755, true);
    ini_set('log_errors',  '1');
    ini_set('error_log',   LOG_PATH . '/php_errors.log');
    if (APP_ENV === 'dev') {
        error_reporting(E_ALL);
        ini_set('display_errors', '0'); // toujours off, on lit les logs
    } else {
        error_reporting(0);
        ini_set('display_errors', '0');
    }
}

<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../api/Database.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
// Log des erreurs OAuth dans un fichier dédié
ini_set('display_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../data/logs/oauth_errors.log');

/* ── Détection mobile ────────────────────────────────────────────────────── */
function isMobileRequest(): bool {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    return (bool) preg_match('/Android|iPhone|iPad|iPod|Mobile/i', $ua);
}

/* ── Helper : page popup avec payload JS injecté ─────────────────────────── */
function popupHtml(string $jsPayloadJson): string {
    $safePayload = $jsPayloadJson;
    return '<!DOCTYPE html>
<html lang="fr"><head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Connexion — Larka</title>
<style>
  *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
  body{font-family:"Segoe UI",system-ui,sans-serif;background:linear-gradient(135deg,#0a1628 0%,#0d2137 50%,#0a1f33 100%);min-height:100vh;display:flex;align-items:center;justify-content:center;color:#e2eaf4;overflow:hidden}
  .bg-waves{position:fixed;inset:0;pointer-events:none;overflow:hidden}
  .bg-waves svg{position:absolute;bottom:-10px;width:100%;opacity:.12}
  .card{position:relative;z-index:10;background:rgba(15,32,56,.82);border:1px solid rgba(72,202,228,.18);backdrop-filter:blur(20px);border-radius:20px;padding:48px 44px 40px;width:360px;text-align:center;box-shadow:0 24px 64px rgba(0,0,0,.5);animation:slideUp .35s cubic-bezier(.16,1,.3,1) both}
  @keyframes slideUp{from{transform:translateY(24px);opacity:0}to{transform:translateY(0);opacity:1}}
  .logo{margin-bottom:28px}.logo svg{width:220px;height:auto;filter:drop-shadow(0 2px 12px rgba(72,202,228,.25))}
  .spinner-wrap{margin:0 auto 24px;width:52px;height:52px;position:relative}
  .spinner{width:52px;height:52px;border:3px solid rgba(72,202,228,.15);border-top-color:#48cae4;border-radius:50%;animation:spin .75s linear infinite}
  @keyframes spin{to{transform:rotate(360deg)}}
  .success-icon{display:none;align-items:center;justify-content:center;width:52px;height:52px;background:rgba(72,202,228,.12);border:2px solid rgba(72,202,228,.4);border-radius:50%;font-size:22px;animation:popIn .3s cubic-bezier(.16,1,.3,1)}
  @keyframes popIn{from{transform:scale(.6);opacity:0}to{transform:scale(1);opacity:1}}
  .state-success .spinner{display:none}.state-success .success-icon{display:flex!important}.state-success .status-title{color:#48cae4}
  .state-error .spinner{border-top-color:#ff6b6b;animation-duration:2s}.state-error .status-title{color:#ff8a8a}
  .status-title{font-size:16px;font-weight:700;color:#e2eaf4;margin-bottom:6px;transition:color .3s}
  .status-sub{font-size:12px;color:rgba(255,255,255,.38);letter-spacing:.3px;min-height:18px;transition:all .3s}
  .progress-bar{margin-top:28px;height:2px;background:rgba(72,202,228,.1);border-radius:2px;overflow:hidden}
  .progress-fill{height:100%;background:linear-gradient(90deg,#0096c7,#48cae4);border-radius:2px;width:0%;animation:progress 4s ease forwards}
  @keyframes progress{to{width:90%}}
</style>
</head><body>
<div class="bg-waves">
  <svg viewBox="0 0 1440 200" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg">
    <path d="M0,100C200,60 400,140 600,100C800,60 1000,140 1200,100C1320,76 1400,112 1440,100L1440,200L0,200Z" fill="#48cae4"/>
  </svg>
</div>
<div class="card" id="card">
  <div class="logo">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 340 100"><text x="170" y="64" text-anchor="middle" font-family="Segoe UI,Arial,sans-serif" font-size="44" font-weight="800" fill="white" letter-spacing="0.5">Larka</text></svg>
  </div>
  <div class="spinner-wrap" id="spinnerWrap">
    <div class="spinner" id="spinner"></div>
    <div class="success-icon" id="successIcon">&#10003;</div>
  </div>
  <div class="status-title" id="msg">Connexion en cours&hellip;</div>
  <div class="status-sub" id="sub">V&eacute;rification de votre compte Microsoft</div>
  <div class="progress-bar"><div class="progress-fill" id="progressFill"></div></div>
</div>
<script>
(function(){
  var payload = ' . $safePayload . ';
  var attempts=0, maxAttempts=30;
  var card=document.getElementById("card");
  var msgEl=document.getElementById("msg");
  var subEl=document.getElementById("sub");
  function setSuccess(){
    card.classList.add("state-success");
    msgEl.textContent="Connexion r\u00e9ussie !";
    subEl.textContent="Fermeture automatique\u2026";
    var pf=document.getElementById("progressFill");
    pf.style.animation="none";pf.style.width="100%";pf.style.transition="width .3s ease";
  }
  function setError(msg){
    card.classList.add("state-error");
    msgEl.textContent="\u00c9chec de la connexion";
    subEl.textContent=msg||"Une erreur est survenue";
    document.getElementById("progressFill").style.background="#ff6b6b";
  }
  // ⚠️ FIX SÉCURITÉ : on cible explicitement l\'origine de la fenêtre parente
  // (celle de notre app) au lieu de "*". Sans ça, n\'importe quelle page
  // ouverte ensuite dans la fenêtre opener pourrait recevoir les credentials
  // si elle est encore window.opener au moment du send().
  // window.location.origin = origine de cette page popup = même origine que l\'app
  // (les deux servies par PHP sur le même domaine).
  var TARGET_ORIGIN = window.location.origin;
  function send(){
    attempts++;
    if(window.opener&&!window.opener.closed){
      try{
        window.opener.postMessage(payload, TARGET_ORIGIN);
        if(payload.type==="MICROSOFT_LOGIN_SUCCESS") setSuccess();
        else setError(payload.error);
        setTimeout(function(){window.close();},1200);
        return;
      }catch(e){}
    }
    if(attempts<maxAttempts) setTimeout(send,200);
    else{if(window.opener&&!window.opener.closed)window.opener.location.reload();window.close();}
  }
  if(document.readyState==="complete") setTimeout(send,150);
  else window.addEventListener("load",function(){setTimeout(send,150);});
})();
</script>
</body></html>';
}

/* ── Token résultat cross-window ─────────────────────────────────────────── */
function writeOAuthResult(array $user): string {
    $token = bin2hex(random_bytes(24));
    $file  = __DIR__ . '/../data/oauth_result_' . $token . '.json';
    file_put_contents($file, json_encode([
        'user' => $user,
        'ts' => time(),
        'tenant_key' => $_SESSION['tenant_key'] ?? null,
    ]));
    return $token;
}

function cleanOldTokens(): void {
    foreach (glob(__DIR__ . '/../data/oauth_result_*.json') as $f) {
        $data = @json_decode(file_get_contents($f), true);
        if (!$data || (time() - ($data['ts'] ?? 0)) > 300) @unlink($f);
    }
}

function closeOk(array $user): void {
    $_SESSION['user'] = $user;
    session_write_close();

    if (isMobileRequest()) {
        // Sur mobile : redirection directe vers l'appli (pas de popup)
        // On reconstruit l'URL avec l'IP réelle du serveur (pas localhost)
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        // Si le host est localhost, utiliser l'IP réelle
        if (str_starts_with($host, 'localhost')) {
            $realIp = gethostbyname(gethostname());
            $port   = $_SERVER['SERVER_PORT'] ?? '8443';
            $host   = $realIp . ':' . $port;
        }
        $appUrl = $scheme . '://' . $host . '/';
        header('Location: ' . $appUrl);
        exit;
    }

    // Sur PC : flux popup normal
    cleanOldTokens();
    $token   = writeOAuthResult($user);
    $payload = ['type' => 'MICROSOFT_LOGIN_SUCCESS', 'user' => array_merge($user, ['oauth_token' => $token])];
    echo popupHtml(json_encode($payload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE));
    exit;
}

function closeError(string $msg): void {
    $payload = ['type' => 'MICROSOFT_LOGIN_ERROR', 'error' => $msg];
    echo popupHtml(json_encode($payload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT));
    exit;
}

/* ── Erreur MS directe ───────────────────────────────────────────────────── */
if (isset($_GET['error'])) {
    closeError($_GET['error_description'] ?? $_GET['error']);
}

/* ── Vérification state ──────────────────────────────────────────────────── */
$received  = $_GET['state'] ?? '';
$stateValid = false;
// 1. Vérifier via fichier per-state (pas de race condition)
if ($received) {
    $stateDir = __DIR__ . '/../data/oauth_states';
    $stateFile = $stateDir . '/' . hash('sha256', $received) . '.json';
    if (file_exists($stateFile)) {
        $stateData = json_decode(file_get_contents($stateFile), true);
        @unlink($stateFile);
        if ($stateData && ($stateData['state'] ?? '') === $received && (time() - ($stateData['ts'] ?? 0)) < 600) {
            $stateValid = true;
        }
    }
    // Nettoyage des vieux fichiers state (>10 min)
    foreach (glob($stateDir . '/*.json') as $f) {
        $d = @json_decode(@file_get_contents($f), true);
        if (!$d || (time() - ($d['ts'] ?? 0)) > 600) @unlink($f);
    }
    // Compat ancien fichier unique
    $oldFile = __DIR__ . '/../data/oauth_state.txt';
    if (!$stateValid && file_exists($oldFile)) {
        $saved = trim(file_get_contents($oldFile));
        @unlink($oldFile);
        if ($received === $saved) $stateValid = true;
    }
}
// 2. Vérifier via session OAuth normale ou SA
if (!$stateValid && $received === ($_SESSION['oauth_state'] ?? ''))    $stateValid = true;
if (!$stateValid && $received === ($_SESSION['sa_oauth_state'] ?? '')) $stateValid = true;
if (!$received || !$stateValid) {
    closeError('Sécurité : state invalide, veuillez réessayer.');
}
$isSuperAdminOAuth = str_starts_with($received, 'sa_');
unset($_SESSION['oauth_state'], $_SESSION['sa_oauth_state']);

/* ── Échange code → token ────────────────────────────────────────────────── */
$code = $_GET['code'] ?? '';
if (!$code) closeError('Code d\'autorisation manquant.');

// Utiliser la redirect_uri mobile si elle a été sauvegardée en session
$redirectUri = $_SESSION['oauth_redirect_uri_ms'] ?? MICROSOFT_REDIRECT_URI;
unset($_SESSION['oauth_redirect_uri_ms']);

$scopes = 'openid profile email offline_access User.Read User.ReadBasic.All';
// ⚠️ Le endpoint v2.0 émet un token portant EXACTEMENT les scopes demandés à la
// rédemption. Les scopes présence/agenda doivent donc être demandés ICI, à
// l'échange code → token, et pas seulement à l'autorisation : sinon le token du
// login ne porte pas Presence.Read.All et Graph répond 403, alors même que le
// consentement admin Azure est accordé. Cette ligne doit rester alignée avec
// celles de api/routes/auth.php et api/MsGraph.php.
if (defined('PLANS_PRESENCE_ENABLED') && PLANS_PRESENCE_ENABLED) $scopes .= ' Presence.Read.All Calendars.Read.Shared';

$ch = curl_init('https://login.microsoftonline.com/' . MICROSOFT_TENANT_ID . '/oauth2/v2.0/token');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'client_id'     => MICROSOFT_CLIENT_ID,
        'client_secret' => MICROSOFT_CLIENT_SECRET,
        'code'          => $code,
        'redirect_uri'  => $redirectUri,
        'grant_type'    => 'authorization_code',
        'scope'         => $scopes,
    ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_TIMEOUT        => 15,
]);
$resp    = curl_exec($ch);
$curlErr = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($curlErr) closeError('Erreur réseau : ' . $curlErr);
$token = json_decode($resp, true);
if ($httpCode !== 200 || empty($token['access_token'])) {
    closeError($token['error_description'] ?? $token['error'] ?? 'Erreur token HTTP ' . $httpCode);
}

/* ── Profil Microsoft Graph ──────────────────────────────────────────────── */
$accessToken = $token['access_token'];

// Stocker le token pour les appels Microsoft Graph ultérieurs (présence, agenda)
$_SESSION['ms_access_token']  = $accessToken;
$_SESSION['ms_refresh_token'] = $token['refresh_token'] ?? '';
$_SESSION['ms_token_expires'] = time() + ($token['expires_in'] ?? 3600);

$ch = curl_init('https://graph.microsoft.com/v1.0/me');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_TIMEOUT        => 10,
]);
$profile = json_decode(curl_exec($ch), true);
curl_close($ch);

$email  = $profile['mail'] ?? $profile['userPrincipalName'] ?? '';
$prenom = $profile['givenName']  ?? '';
$nom    = $profile['surname']    ?? '';
$msId   = $profile['id']         ?? '';
$telMobile = $profile['mobilePhone'] ?? '';
$telPro    = $profile['businessPhones'][0] ?? '';
$service= $profile['department'] ?? '';
$poste  = $profile['jobTitle']   ?? '';
$officeLocation = $profile['officeLocation'] ?? '';
$companyName    = $profile['companyName']    ?? '';

if (!$email) closeError('Email introuvable dans le profil Microsoft.');

/* ── Photo de profil Microsoft Graph (96×96) ────────────────────────────── */
$photoUrl = '';
try {
    // Demander la version 96x96 pour limiter la taille en DB
    $ch = curl_init('https://graph.microsoft.com/v1.0/me/photos/96x96/$value');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $photoData = curl_exec($ch);
    $photoHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $photoContentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    // Fallback vers la photo par défaut si 96x96 non disponible
    if ($photoHttpCode !== 200) {
        $ch = curl_init('https://graph.microsoft.com/v1.0/me/photo/$value');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $photoData = curl_exec($ch);
        $photoHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $photoContentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
    }

    // Max 500KB en binaire pour ne pas surcharger la DB
    if ($photoHttpCode === 200 && $photoData && strlen($photoData) > 100 && strlen($photoData) < 512000) {
        $mimeType = $photoContentType ?: 'image/jpeg';
        $photoUrl = 'data:' . $mimeType . ';base64,' . base64_encode($photoData);
    }
} catch (\Exception $e) {
    error_log('MS photo error: ' . $e->getMessage());
}

/* ── Manager (responsable hiérarchique) ─────────────────────────────────── */
$managerName = '';
try {
    $ch = curl_init('https://graph.microsoft.com/v1.0/me/manager');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $managerResp = curl_exec($ch);
    $managerCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($managerCode === 200) {
        $manager = json_decode($managerResp, true);
        $managerName = trim(($manager['givenName'] ?? '') . ' ' . ($manager['surname'] ?? ''));
        if (!$managerName || $managerName === ' ') {
            $managerName = $manager['displayName'] ?? '';
        }
    }
} catch (\Exception $e) {
    error_log('MS manager error: ' . $e->getMessage());
}

/* ── Super Admin OAuth ─────────────────────────────────────────────────────
 * ⚠️ FIX SÉCURITÉ (CRITIQUE) — Contournement d'authentification super-admin.
 *
 * AVANT : ce callback renvoyait l'email VÉRIFIÉ au navigateur, qui le
 * re-soumettait à `superadmin_oauth_check`. Ce dernier accordait la session
 * SA sur la seule foi de cet email. Résultat : n'importe qui pouvait POSTer
 * l'email d'un super-admin autorisé (souvent connu — le défaut est même
 * « admin@gmao.local » en dur) et obtenir une session super-admin complète,
 * sans jamais s'authentifier auprès de Microsoft.
 *
 * APRÈS : la session super-admin est établie ICI, CÔTÉ SERVEUR, à partir de
 * l'email réellement vérifié par Microsoft — jamais d'un email fourni par le
 * client. Un jeton à usage unique (écrit par le serveur) sert de repli
 * cross-window ; il porte l'email vérifié, le client ne fournit que le jeton.
 */
if ($isSuperAdminOAuth) {
    require_once __DIR__ . '/../api/TenantResolver.php';

    // Vérifier CÔTÉ SERVEUR que l'email vérifié par Microsoft est bien autorisé.
    $emailLc = strtolower(trim($email));
    $authorized = false;
    foreach (TenantResolver::getSuperAdminEmails() as $allowed) {
        if (strtolower(trim((string)$allowed)) === $emailLc) { $authorized = true; break; }
    }
    if (!$authorized) {
        closeError("Cet email n'est pas autorisé comme super administrateur.");
    }

    // Établir la session SA (le popup partage le cookie de session de l'app —
    // même origine — donc la fenêtre parente verra immédiatement la session).
    session_regenerate_id(true);
    $_SESSION['superadmin'] = [
        'login'         => $email,
        'authenticated' => true,
        'provider'      => 'microsoft',
        'ts'            => time(),
    ];

    // Jeton à usage unique (repli robuste, calqué sur oauth_result_*.json).
    // Il contient l'email VÉRIFIÉ côté serveur ; jamais fourni par le client.
    $saToken = bin2hex(random_bytes(24));
    @file_put_contents(
        __DIR__ . '/../data/sa_oauth_result_' . $saToken . '.json',
        json_encode(['email' => $email, 'ts' => time()])
    );
    session_write_close();

    // On ne renvoie PLUS l'email au client — uniquement le jeton opaque.
    $payload = ['type' => 'MICROSOFT_LOGIN_SUCCESS', 'sa_token' => $saToken];
    echo popupHtml(json_encode($payload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE));
    exit;
}

/* ── Résolution multi-tenant par domaine email ─────────────────────────── */
require_once __DIR__ . '/../api/TenantResolver.php';
$_tenantDbCfg = null;
if (TenantResolver::isMultiTenant()) {
    $tenantKey = TenantResolver::resolveByEmailDomain($email);
    if (!$tenantKey) {
        foreach (TenantResolver::getAllTenants() as $k => $t) {
            if (in_array('*', $t['domaines_email'] ?? [])) { $tenantKey = $k; break; }
        }
    }
    if ($tenantKey) {
        $_tenantDbCfg = TenantResolver::getDbConfigForTenant($tenantKey);
        $_SESSION['tenant_key'] = $tenantKey;
    } else {
        $domain = strtolower(substr(strrchr($email, '@'), 1));
        closeError("Aucune organisation trouvée pour le domaine @{$domain}.");
    }
}

/* ── Vérification domaine ────────────────────────────────────────────────── */
$db = $_tenantDbCfg ? new Database($_tenantDbCfg) : new Database();
if (!$db->isEmailDomainAllowed($email)) {
    $domain = strtolower(substr(strrchr($email, '@'), 1));
    closeError("Le domaine @{$domain} n'est pas autorisé.");
}

/* ── Création / récupération utilisateur ─────────────────────────────────── */
$user = $db->findOrCreateMicrosoftUser($email, $prenom, $nom, $msId, $telMobile, $telPro, $service, $poste, $photoUrl, $officeLocation, $companyName, $managerName);
if (!$user) closeError('Compte désactivé ou erreur lors de la création.');

closeOk($user);

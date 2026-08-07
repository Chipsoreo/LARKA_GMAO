<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../data/logs/google_oauth.log');
error_reporting(E_ALL);

require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../api/Database.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

/* ── Détection mobile ────────────────────────────────────────────────────── */
function isMobileRequest(): bool {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    return (bool) preg_match('/Android|iPhone|iPad|iPod|Mobile/i', $ua);
}

/* ── Helper : page popup avec payload JS injecté ─────────────────────────── */
function popupHtml(string $jsPayloadJson): string {
    // On encode le JSON pour l'insérer en valeur JS littérale
    $safePayload = $jsPayloadJson; // déjà du JSON valide
    return '<!DOCTYPE html>
<html lang="fr"><head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Connexion — Larka</title>
<style>
  *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
  body{font-family:"Segoe UI",system-ui,sans-serif;background:linear-gradient(135deg,#0a1628 0%,#0d2137 50%,#0a1f33 100%);min-height:100vh;display:flex;align-items:center;justify-content:center;color:#e2eaf4;overflow:hidden}
  .card{background:rgba(15,32,56,.82);border:1px solid rgba(72,202,228,.18);backdrop-filter:blur(20px);border-radius:20px;padding:48px 44px 40px;width:360px;text-align:center;box-shadow:0 24px 64px rgba(0,0,0,.5);animation:slideUp .35s cubic-bezier(.16,1,.3,1) both}
  @keyframes slideUp{from{transform:translateY(24px);opacity:0}to{transform:translateY(0);opacity:1}}
  .logo{margin-bottom:28px}.logo svg{width:220px;height:auto;filter:drop-shadow(0 2px 12px rgba(72,202,228,.25))}
  .spinner-wrap{margin:0 auto 24px;width:52px;height:52px;position:relative}
  .spinner{width:52px;height:52px;border:3px solid rgba(72,202,228,.15);border-top-color:#48cae4;border-radius:50%;animation:spin .75s linear infinite}
  @keyframes spin{to{transform:rotate(360deg)}}
  .success-icon{display:none;align-items:center;justify-content:center;width:52px;height:52px;background:rgba(72,202,228,.12);border:2px solid rgba(72,202,228,.4);border-radius:50%;font-size:22px;animation:popIn .3s cubic-bezier(.16,1,.3,1)}
  @keyframes popIn{from{transform:scale(.6);opacity:0}to{transform:scale(1);opacity:1}}
  .state-success .spinner{display:none}.state-success .success-icon{display:flex!important}.state-success .status-title{color:#48cae4}
  .state-error .spinner{border-top-color:#ff6b6b}.state-error .status-title{color:#ff8a8a}
  .status-title{font-size:16px;font-weight:700;color:#e2eaf4;margin-bottom:6px}
  .status-sub{font-size:12px;color:rgba(255,255,255,.38);min-height:18px}
  .progress-bar{margin-top:28px;height:2px;background:rgba(72,202,228,.1);border-radius:2px;overflow:hidden}
  .progress-fill{height:100%;background:linear-gradient(90deg,#0096c7,#48cae4);width:0%;animation:progress 4s ease forwards}
  @keyframes progress{to{width:90%}}
</style>
</head><body>
<div class="card" id="card">
  <div class="logo">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 340 100"><text x="170" y="64" text-anchor="middle" font-family="Segoe UI,Arial,sans-serif" font-size="44" font-weight="800" fill="white" letter-spacing="0.5">Larka</text></svg>
  </div>
  <div class="spinner-wrap">
    <div class="spinner" id="spinner"></div>
    <div class="success-icon" id="successIcon">&#10003;</div>
  </div>
  <div class="status-title" id="msg">Connexion en cours&hellip;</div>
  <div class="status-sub" id="sub">V&eacute;rification de votre compte Google</div>
  <div class="progress-bar"><div class="progress-fill"></div></div>
</div>
<script>
(function(){
  var payload = ' . $safePayload . ';
  var attempts = 0;
  // ⚠️ FIX SÉCURITÉ : cibler l\'origine au lieu de "*" (cf. microsoft.php)
  var TARGET_ORIGIN = window.location.origin;
  function send(){
    attempts++;
    if(window.opener && !window.opener.closed){
      try{
        window.opener.postMessage(payload, TARGET_ORIGIN);
        var card=document.getElementById("card");
        if(payload.type==="GOOGLE_LOGIN_SUCCESS"){
          card.classList.add("state-success");
          document.getElementById("msg").textContent="Connexion r\u00e9ussie !";
          document.getElementById("sub").textContent="Fermeture automatique\u2026";
        }else{
          card.classList.add("state-error");
          document.getElementById("msg").textContent="\u00c9chec de la connexion";
          document.getElementById("sub").textContent=payload.error||"Erreur";
        }
        setTimeout(function(){window.close();},1200);
        return;
      }catch(e){}
    }
    if(attempts<30) setTimeout(send,200);
    else{if(window.opener)window.opener.location.reload();window.close();}
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
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
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
    $payload = array_merge(['type' => 'GOOGLE_LOGIN_SUCCESS'], ['user' => array_merge($user, ['oauth_token' => $token])]);
    echo popupHtml(json_encode($payload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE));
    exit;
}

function closeError(string $msg): void {
    $payload = ['type' => 'GOOGLE_LOGIN_ERROR', 'error' => $msg];
    echo popupHtml(json_encode($payload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT));
    exit;
}

/* ── Vérification Google activé ──────────────────────────────────────────── */
if (!GOOGLE_ENABLED || !GOOGLE_CLIENT_ID) {
    closeError('Connexion Google non activée.');
}

/* ── Étape 1 : redirection vers Google ──────────────────────────────────── */
if (!isset($_GET['code'])) {
    $state = bin2hex(random_bytes(16));
    $stateDir = __DIR__ . '/../data/oauth_states';
    if (!is_dir($stateDir)) @mkdir($stateDir, 0700, true);
    file_put_contents($stateDir . '/' . hash('sha256', $state) . '.json', json_encode(['state' => $state, 'ts' => time()]));
    $_SESSION['google_oauth_state'] = $state;
    // Sur mobile, utiliser l'IP réelle au lieu de localhost
    $isMobile    = !empty($_GET['mobile']);
    $redirectUri = ($isMobile && GOOGLE_REDIRECT_URI_MOBILE)
        ? GOOGLE_REDIRECT_URI_MOBILE
        : GOOGLE_REDIRECT_URI;
    if ($isMobile) $_SESSION['google_redirect_uri_mobile'] = $redirectUri;
    session_write_close();
    $params = http_build_query([
        'client_id'     => GOOGLE_CLIENT_ID,
        'redirect_uri'  => $redirectUri,
        'response_type' => 'code',
        'scope'         => 'openid email profile',
        'state'         => $state,
        'access_type'   => 'online',
        'prompt'        => 'select_account',
    ]);
    header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $params);
    exit;
}

/* ── Erreur Google directe ───────────────────────────────────────────────── */
if (isset($_GET['error'])) {
    closeError($_GET['error_description'] ?? $_GET['error']);
}

/* ── Vérification state ──────────────────────────────────────────────────── */
$received  = $_GET['state'] ?? '';
$stateValid = false;
if ($received) {
    $stateDir = __DIR__ . '/../data/oauth_states';
    $sf = $stateDir . '/' . hash('sha256', $received) . '.json';
    if (file_exists($sf)) {
        $sd = json_decode(file_get_contents($sf), true);
        @unlink($sf);
        if ($sd && ($sd['state'] ?? '') === $received && (time() - ($sd['ts'] ?? 0)) < 600) {
            $stateValid = true;
        }
    }
    // Compat ancien fichier unique
    $oldFile = __DIR__ . '/../data/google_oauth_state.txt';
    if (!$stateValid && file_exists($oldFile)) {
        $saved = trim(file_get_contents($oldFile));
        @unlink($oldFile);
        if ($received === $saved) $stateValid = true;
    }
    // Session fallback
    if (!$stateValid && $received === ($_SESSION['google_oauth_state'] ?? '')) $stateValid = true;
}
unset($_SESSION['google_oauth_state']);
if (!$received || !$stateValid) {
    closeError('Sécurité : state invalide, veuillez réessayer.');
}

/* ── Échange code → token ────────────────────────────────────────────────── */
$code = $_GET['code'] ?? '';
if (!$code) closeError('Code d\'autorisation manquant.');

$redirectUri = $_SESSION['google_redirect_uri_mobile'] ?? GOOGLE_REDIRECT_URI;
unset($_SESSION['google_redirect_uri_mobile']);

$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'client_id'     => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'code'          => $code,
        'redirect_uri'  => $redirectUri,
        'grant_type'    => 'authorization_code',
    ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_TIMEOUT        => 15,
]);
$resp    = curl_exec($ch);
$curlErr = curl_error($ch);
curl_close($ch);

if ($curlErr) closeError('Erreur réseau : ' . $curlErr);
$token = json_decode($resp, true);
if (empty($token['access_token'])) {
    closeError($token['error_description'] ?? $token['error'] ?? 'Erreur token Google');
}

/* ── Profil Google ───────────────────────────────────────────────────────── */
$ch = curl_init('https://www.googleapis.com/oauth2/v3/userinfo');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token['access_token']],
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_TIMEOUT        => 10,
]);
$profile = json_decode(curl_exec($ch), true);
curl_close($ch);

$email  = $profile['email']       ?? '';
$prenom = $profile['given_name']  ?? '';
$nom    = $profile['family_name'] ?? '';
$gId    = $profile['sub']         ?? '';

if (!$email) closeError('Email introuvable dans le profil Google.');

/* ── Résolution multi-tenant par domaine email ─────────────────────────── */
require_once __DIR__ . '/../api/TenantResolver.php';
$_tenantDbCfg = null;
if (TenantResolver::isMultiTenant()) {
    $tenantKey = TenantResolver::resolveByEmailDomain($email);
    if (!$tenantKey) {
        // Chercher un tenant wildcard (*)
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
$user = $db->findOrCreateGoogleUser($email, $prenom, $nom, $gId);
if (!$user) closeError('Compte désactivé ou erreur lors de la création.');

closeOk($user);

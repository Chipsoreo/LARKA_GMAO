/*
 * SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
 * SPDX-License-Identifier: LicenseRef-Larka-Proprietary
 *
 * This file is part of Larka, proprietary software by Mickaël Larcin.
 * All rights reserved. Use is subject to the license terms; copying,
 * distribution, modification or reverse-engineering without the author's
 * prior written permission is prohibited. See the LICENSE file for details.
 */

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * Larka — Authentification
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Gère la connexion, la session et la déconnexion.
 *
 * MÉTHODES DE CONNEXION :
 *   - Compte local (email + mot de passe)
 *   - OAuth Microsoft (Azure AD / Entra ID)
 *   - OAuth Google
 *
 * FONCTIONS PRINCIPALES :
 *   - doLogin()        → connexion locale via formulaire
 *   - doLogout()       → déconnexion + retour écran de login
 *   - checkSession()   → vérifie la session active au chargement
 *   - initLoginScreen()→ affiche les boutons SSO selon la config serveur
 *
 * DÉPENDANCES :
 *   - api.js  → apiRequest() pour les appels auth
 *   - init.js → initApp() appelé après connexion réussie
 * ═══════════════════════════════════════════════════════════════════════════════
 */

// ── Connexion locale ──────────────────────────────────────────────────────────
async function doLogin() {
  const login    = document.getElementById('loginInput').value.trim();
  const password = document.getElementById('passwordInput').value;
  const errEl    = document.getElementById('loginError');
  const btn      = document.querySelector('.btn-login');
  const spinner  = document.querySelector('.login-spinner');
  errEl.style.display = 'none';
  errEl.style.color   = '';  // Reset en cas de changement par mdp oublié

  if (!login || !password) {
    errEl.textContent   = 'Identifiant et mot de passe requis.';
    errEl.style.display = 'block';
    return;
  }

  btn.style.display     = 'none';
  spinner.style.display = 'block';
  try {
    const user = await Auth.login(login, password);
    initApp(user);
  } catch(e) {
    errEl.textContent     = e.message;
    errEl.style.display   = 'block';
    btn.style.display     = 'block';
    spinner.style.display = 'none';
  }
}

async function doLogout() {
  // Nettoyer les intervalles
  if (window.App && App._badgeInterval) {
    clearInterval(App._badgeInterval);
    App._badgeInterval = null;
  }
  App.currentUser = null;

  // FIX: réinitialiser le flag de session (sinon un re-login dans le même onglet
  // passerait à travers checkSession sans appeler initApp)
  _initAppCalled = false;

  // Nettoyer la page sauvegardée
  try { sessionStorage.removeItem('gmao_current_page'); } catch(_) {}

  // Nettoyer le module push (supprimer le soft-prompt, timers)
  if (typeof PushManager_GMAO !== 'undefined') PushManager_GMAO.cleanup();

  // Attendre la destruction de la session côté serveur
  try { await Auth.logout(); } catch(_) {}

  // Forcer le rechargement complet (pas depuis le cache)
  window.location.href = window.location.pathname + '?t=' + Date.now();
}

// ── Session ───────────────────────────────────────────────────────────────────
let _initAppCalled = false;

async function checkSession() {
  try {
    const user = await Auth.me();
    if (!_initAppCalled) {
      _initAppCalled = true;
      initApp(user, true); // session restore → pas d'animation, restaure l'onglet
    }
  } catch(_) {}
}

// ── Détection mobile ──────────────────────────────────────────────────────────
function isMobile() {
  return /Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent) ||
         ('ontouchstart' in window && screen.width < 1024);
}

// ── Helpers popup OAuth ───────────────────────────────────────────────────────
function openOAuthPopup(url, name) {
  const w = 520, h = 680;
  const left = Math.round(screen.width  / 2 - w / 2);
  const top  = Math.round(screen.height / 2 - h / 2);
  return window.open(url, name,
    `width=${w},height=${h},left=${left},top=${top},toolbar=0,menubar=0,location=0`);
}

function isPopupClosed(popup) {
  try { return !popup || popup.closed; } catch(_) { return true; }
}
function closePopup(popup) {
  try { if (popup) popup.close(); } catch(_) {}
}

// Sur mobile : redirection directe (pas de popup)
function oauthRedirect(url) {
  sessionStorage.setItem('oauth_redirect_back', window.location.href);
  window.location.href = url;
}

// ── Flux OAuth générique (postMessage + polling) ──────────────────────────────
/**
 * Stratégie double :
 *  1. La popup envoie un postMessage avec un oauth_token (clé fichier temporaire).
 *  2. La fenêtre parente écoute ce message et appelle oauth_check?token=XXX pour
 *     initialiser sa propre session PHP et récupérer l'utilisateur.
 *  3. En parallèle, un polling sur oauth_check sans token couvre le cas où la session
 *     PHP est déjà partagée (même cookie, accès local, etc.)
 */
function startOAuthFlow(popup, errEl) {
  if (!popup) {
    errEl.textContent   = 'Popup bloquée — autorisez les popups pour ce site.';
    errEl.style.display = 'block';
    return;
  }

  let done = false;

  // 1. Écoute postMessage envoyé par la popup
  async function onMessage(evt) {
    // ⚠️ FIX SÉCURITÉ : refuser tout message ne venant pas de la même origine.
    // Sans ça, n'importe quelle page tierce (ex. via window.open) peut nous
    // envoyer un faux MICROSOFT_LOGIN_SUCCESS et compromettre la session.
    if (evt.origin !== window.location.origin) return;

    const d = evt.data;
    if (!d || typeof d !== 'object') return;

    const isSuccess = d.type === 'GOOGLE_LOGIN_SUCCESS' || d.type === 'MICROSOFT_LOGIN_SUCCESS';
    const isError   = d.type === 'GOOGLE_LOGIN_ERROR'   || d.type === 'MICROSOFT_LOGIN_ERROR';

    if (!isSuccess && !isError) return;
    if (done) return;

    if (isError) {
      done = true;
      window.removeEventListener('message', onMessage);
      clearInterval(pollInterval);
      errEl.textContent   = d.error || 'Erreur de connexion.';
      errEl.style.display = 'block';
      return;
    }

    // Succès : valider le token côté serveur
    const oauthToken = d.user && d.user.oauth_token;
    if (oauthToken) {
      try {
        const res  = await fetch('api/index.php?action=oauth_check&token=' + encodeURIComponent(oauthToken));
        const data = await res.json();
        if (data.success && data.data) {
          done = true;
          window.removeEventListener('message', onMessage);
          clearInterval(pollInterval);
          closePopup(popup);
          initApp(data.data);
          return;
        }
      } catch(_) {}
    }
    // Fallback : utiliser l'objet user du message directement
    if (d.user) {
      done = true;
      window.removeEventListener('message', onMessage);
      clearInterval(pollInterval);
      closePopup(popup);
      initApp(d.user);
    }
  }
  window.addEventListener('message', onMessage);

  // 2. Polling de secours (session PHP partagée)
  let attempts = 0;
  const pollInterval = setInterval(async () => {
    attempts++;

    if (done) { clearInterval(pollInterval); return; }

    if (isPopupClosed(popup)) {
      // Ne pas arrêter immédiatement : laisser ~800ms pour que le postMessage
      // en transit depuis la popup ait le temps d'arriver et d'être traité.
      setTimeout(() => {
        if (done) return;
        clearInterval(pollInterval);
        window.removeEventListener('message', onMessage);
      }, 800);
      return;
    }

    if (attempts > 120) {
      clearInterval(pollInterval);
      window.removeEventListener('message', onMessage);
      closePopup(popup);
      errEl.textContent   = 'Délai dépassé. Réessayez.';
      errEl.style.display = 'block';
      return;
    }

    try {
      const res  = await fetch('api/index.php?action=oauth_check');
      const data = await res.json();
      if (data.success && data.data) {
        done = true;
        clearInterval(pollInterval);
        window.removeEventListener('message', onMessage);
        closePopup(popup);
        initApp(data.data);
      }
    } catch(_) {}
  }, 1000);
}

// ── OAuth Microsoft ───────────────────────────────────────────────────────────
async function doLoginMicrosoft(forceConsent = false) {
  const errEl = document.getElementById('loginError');
  errEl.style.display = 'none';
  try {
    const mobile = isMobile() ? '&mobile=1' : '';
    const consent = forceConsent ? '&consent=1' : '';
    const res  = await fetch('api/index.php?action=oauth_microsoft_url' + mobile + consent);
    const data = await res.json();
    if (!data.success) throw new Error(data.error);
    if (isMobile()) {
      oauthRedirect(data.data.url);
    } else {
      const popup = openOAuthPopup(data.data.url, 'ms_login');
      startOAuthFlow(popup, errEl);
    }
  } catch(e) {
    errEl.textContent   = e.message || 'Erreur Microsoft';
    errEl.style.display = 'block';
  }
}

// ── OAuth Google ──────────────────────────────────────────────────────────────
async function doLoginGoogle() {
  const errEl = document.getElementById('loginError');
  errEl.style.display = 'none';
  const mobileParam = isMobile() ? '?mobile=1' : '';
  if (isMobile()) {
    oauthRedirect('oauth/google.php' + mobileParam);
  } else {
    const popup = openOAuthPopup('oauth/google.php', 'google_login');
    startOAuthFlow(popup, errEl);
  }
}

// ── Boutons SSO dynamiques ────────────────────────────────────────────────────
async function loadSSOButtons() {
  try {
    const res  = await fetch('api/index.php?action=auth_config');
    const data = await res.json();
    const cfg  = data.data || {};
    const container = document.getElementById('ssoButtons');
    if (!container) return;

    let html = '';
    if (cfg.microsoft || cfg.google) {
      html += `
        <div style="display:flex;align-items:center;gap:12px;margin:20px 0 4px">
          <div style="flex:1;height:1px;background:rgba(255,255,255,.1)"></div>
          <span style="color:rgba(255,255,255,.3);font-size:12px">ou</span>
          <div style="flex:1;height:1px;background:rgba(255,255,255,.1)"></div>
        </div>`;
    }
    if (cfg.microsoft) {
      html += `
        <button class="btn-microsoft" onclick="doLoginMicrosoft()" style="margin-bottom:10px">
          <svg width="18" height="18" viewBox="0 0 21 21" xmlns="http://www.w3.org/2000/svg">
            <rect x="1"  y="1"  width="9" height="9" fill="#f25022"/>
            <rect x="11" y="1"  width="9" height="9" fill="#7fba00"/>
            <rect x="1"  y="11" width="9" height="9" fill="#00a4ef"/>
            <rect x="11" y="11" width="9" height="9" fill="#ffb900"/>
          </svg>
          Se connecter avec Microsoft
        </button>`;
    }
    if (cfg.google) {
      html += `
        <button class="btn-google" onclick="doLoginGoogle()">
          <svg width="18" height="18" viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg">
            <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
            <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
            <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
            <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
          </svg>
          Se connecter avec Google
        </button>`;
    }
    container.innerHTML = html;
  } catch(e) {
    console.warn('[Larka] Chargement boutons SSO échoué:', e.message || e);
  }
}

// ── Mot de passe oublié (popup overlay sur l'écran login) ─────────────────────
function showForgotPassword() {
  // Supprimer une éventuelle popup précédente
  document.getElementById('forgotPopup')?.remove();

  const popup = document.createElement('div');
  popup.id = 'forgotPopup';
  popup.style.cssText = 'position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.6);backdrop-filter:blur(4px)';
  popup.innerHTML = `
    <div style="background:#1e293b;border-radius:16px;padding:32px;width:90%;max-width:400px;box-shadow:0 20px 60px rgba(0,0,0,.5);color:#e2e8f0;position:relative">
      <button onclick="document.getElementById('forgotPopup').remove()" style="position:absolute;top:12px;right:14px;background:none;border:none;color:rgba(255,255,255,.4);font-size:22px;cursor:pointer;line-height:1">×</button>
      <h2 style="margin:0 0 6px;font-size:18px;color:#fff">🔑 Mot de passe oublié</h2>
      <p style="font-size:12px;color:rgba(255,255,255,.5);margin:0 0 18px">Comptes locaux uniquement</p>

      <!-- ÉTAPE 1 : login + email -->
      <div id="forgotStep1">
        <div style="margin-bottom:12px">
          <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:rgba(255,255,255,.7)">Votre login</label>
          <input type="text" id="forgotLogin" placeholder="ex: jdupont" autocomplete="username"
            style="width:100%;padding:10px 12px;border-radius:8px;border:1px solid rgba(255,255,255,.15);background:rgba(255,255,255,.08);color:#fff;font-size:14px;box-sizing:border-box">
        </div>
        <div style="margin-bottom:14px">
          <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:rgba(255,255,255,.7)">Adresse email liée au compte</label>
          <input type="email" id="forgotEmail" placeholder="prenom.nom@entreprise.fr" autocomplete="email"
            style="width:100%;padding:10px 12px;border-radius:8px;border:1px solid rgba(255,255,255,.15);background:rgba(255,255,255,.08);color:#fff;font-size:14px;box-sizing:border-box">
        </div>
        <div id="forgotError" style="display:none;color:#fca5a5;font-size:12px;margin:0 0 10px;text-align:center"></div>
        <div id="forgotSuccess" style="display:none;color:#86efac;font-size:12px;margin:0 0 10px;text-align:center"></div>
        <button id="btnSendCode" onclick="doForgotSendCode()"
          style="width:100%;padding:10px;border:none;border-radius:8px;background:linear-gradient(135deg,#3b82f6,#2563eb);color:#fff;font-weight:600;font-size:14px;cursor:pointer">
          Envoyer le code
        </button>
        <div id="forgotSpinner" style="display:none;text-align:center;padding:10px;color:rgba(255,255,255,.5);font-size:13px">Envoi en cours…</div>
      </div>

      <!-- ÉTAPE 2 : code + nouveau mdp -->
      <div id="forgotStep2" style="display:none">
        <div style="margin-bottom:12px">
          <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:rgba(255,255,255,.7)">Code reçu par email</label>
          <input type="text" id="forgotCode" placeholder="000000" maxlength="6"
            style="width:100%;padding:12px;border-radius:8px;border:1px solid rgba(255,255,255,.15);background:rgba(255,255,255,.08);color:#fff;font-size:22px;font-weight:700;text-align:center;letter-spacing:8px;box-sizing:border-box">
        </div>
        <div style="margin-bottom:10px">
          <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:rgba(255,255,255,.7)">Nouveau mot de passe</label>
          <input type="password" id="forgotNewPwd" placeholder="••••••••" autocomplete="new-password"
            style="width:100%;padding:10px 12px;border-radius:8px;border:1px solid rgba(255,255,255,.15);background:rgba(255,255,255,.08);color:#fff;font-size:14px;box-sizing:border-box">
        </div>
        <div style="margin-bottom:14px">
          <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:rgba(255,255,255,.7)">Confirmer le mot de passe</label>
          <input type="password" id="forgotNewPwd2" placeholder="••••••••" autocomplete="new-password"
            style="width:100%;padding:10px 12px;border-radius:8px;border:1px solid rgba(255,255,255,.15);background:rgba(255,255,255,.08);color:#fff;font-size:14px;box-sizing:border-box">
        </div>
        <div id="resetError" style="display:none;color:#fca5a5;font-size:12px;margin:0 0 10px;text-align:center"></div>
        <button onclick="doForgotReset()"
          style="width:100%;padding:10px;border:none;border-radius:8px;background:linear-gradient(135deg,#10b981,#059669);color:#fff;font-weight:600;font-size:14px;cursor:pointer">
          Réinitialiser le mot de passe
        </button>
      </div>
    </div>`;

  document.body.appendChild(popup);
  // Clic sur le fond ferme la popup
  popup.addEventListener('click', e => { if (e.target === popup) popup.remove(); });
  document.getElementById('forgotLogin').focus();
}

async function doForgotSendCode() {
  const login = document.getElementById('forgotLogin')?.value?.trim();
  const email = document.getElementById('forgotEmail')?.value?.trim();
  const errEl = document.getElementById('forgotError');
  const sucEl = document.getElementById('forgotSuccess');
  const btn   = document.getElementById('btnSendCode');

  errEl.style.display = 'none';
  sucEl.style.display = 'none';

  if (!login) { errEl.textContent = 'Veuillez entrer votre login.'; errEl.style.display = 'block'; return; }
  if (!email || !email.includes('@')) { errEl.textContent = 'Veuillez entrer une adresse email valide.'; errEl.style.display = 'block'; return; }

  btn.style.display = 'none';
  document.getElementById('forgotSpinner').style.display = 'block';
  try {
    const res  = await fetch('api/index.php?action=forgot_password', {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ login, email })
    });
    const data = await res.json();
    document.getElementById('forgotSpinner').style.display = 'none';
    if (!data.success) {
      errEl.textContent = data.error || 'Erreur.';
      errEl.style.display = 'block';
      btn.style.display = 'block';
      return;
    }
    sucEl.textContent = `✅ Code envoyé à ${data.data.email}`;
    sucEl.style.display = 'block';
    // Passer à l'étape 2
    document.getElementById('forgotStep1').style.display = 'none';
    document.getElementById('forgotStep2').style.display = 'block';
    window._forgotLogin = login;
  } catch(e) {
    document.getElementById('forgotSpinner').style.display = 'none';
    errEl.textContent = 'Erreur réseau.';
    errEl.style.display = 'block';
    btn.style.display = 'block';
  }
}

async function doForgotReset() {
  const code   = document.getElementById('forgotCode')?.value?.trim();
  const pwd1   = document.getElementById('forgotNewPwd')?.value;
  const pwd2   = document.getElementById('forgotNewPwd2')?.value;
  const errEl  = document.getElementById('resetError');
  errEl.style.display = 'none';

  if (!code || code.length !== 6) { errEl.textContent = 'Le code doit contenir 6 chiffres.'; errEl.style.display = 'block'; return; }
  if (!pwd1 || pwd1.length < 8)   { errEl.textContent = 'Le mot de passe doit contenir au moins 8 caractères.'; errEl.style.display = 'block'; return; }
  if (pwd1 !== pwd2)              { errEl.textContent = 'Les mots de passe ne correspondent pas.'; errEl.style.display = 'block'; return; }

  try {
    const res  = await fetch('api/index.php?action=reset_password', {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ login: window._forgotLogin, code, newPassword: pwd1 })
    });
    const data = await res.json();
    if (!data.success) {
      errEl.textContent = data.error || 'Erreur.';
      errEl.style.display = 'block';
      return;
    }
    // Succès
    document.getElementById('forgotPopup')?.remove();
    const errLogin = document.getElementById('loginError');
    if (errLogin) {
      errLogin.textContent = '✅ Mot de passe réinitialisé ! Connectez-vous avec votre nouveau mot de passe.';
      errLogin.style.display = 'block';
      errLogin.style.color = '#86efac';
    }
  } catch(e) {
    errEl.textContent = 'Erreur réseau.';
    errEl.style.display = 'block';
  }
}

// ── Changer son mot de passe (connecté) ──────────────────────────────────────
function showChangePassword() {
  openModal('🔑 Changer mon mot de passe', `
    <div class="form-grid" style="grid-template-columns:1fr">
      <div class="form-group">
        <label class="form-label">Ancien mot de passe</label>
        <input class="form-control" type="password" id="f_oldPwd" autocomplete="current-password">
      </div>
      <div class="form-group">
        <label class="form-label">Nouveau mot de passe</label>
        <input class="form-control" type="password" id="f_newPwd" autocomplete="new-password">
      </div>
      <div class="form-group">
        <label class="form-label">Confirmer le nouveau mot de passe</label>
        <input class="form-control" type="password" id="f_newPwd2" autocomplete="new-password">
      </div>
    </div>
  `, async () => {
    const oldPwd = document.getElementById('f_oldPwd')?.value;
    const newPwd = document.getElementById('f_newPwd')?.value;
    const newPwd2 = document.getElementById('f_newPwd2')?.value;

    if (!oldPwd) { toast('Ancien mot de passe requis.', 'error'); return; }
    if (!newPwd || newPwd.length < 8) { toast('Minimum 8 caractères.', 'error'); return; }
    if (newPwd !== newPwd2) { toast('Les mots de passe ne correspondent pas.', 'error'); return; }

    try {
      const res = await fetch('api/index.php?action=change_password', {
        method: 'POST', headers: {'Content-Type':'application/json'},
        credentials: 'include',
        body: JSON.stringify({ oldPassword: oldPwd, newPassword: newPwd })
      });
      const data = await res.json();
      if (!data.success) { toast(data.error || 'Erreur.', 'error'); return; }
      toast('Mot de passe modifié avec succès !', 'success');
      closeModal();
    } catch(e) { toast('Erreur réseau.', 'error'); }
  });
}

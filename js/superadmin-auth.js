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
 * Larka — Authentification Super Administrateur
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Gère la connexion au panneau Super Admin (multi-tenant).
 * Le Super Admin peut créer/gérer des tenants, provisioner des bases,
 * et gérer les comptes utilisateurs de chaque tenant.
 *
 * Accès via ?superadmin dans l'URL.
 * ═══════════════════════════════════════════════════════════════════════════════
 */

// ── Détection du mode Super Admin ─────────────────────────────────────────────
function isSuperAdminMode() {
  return window.location.search.includes('superadmin');
}

// ── Afficher le lien "Accès Super Admin" sur le login normal ──────────────────
async function showSuperAdminLink() {
  try {
    const res = await fetch('api/index.php?action=superadmin_status');
    let json;
    try { json = await res.json(); } catch(_) { return; }
    if (json && json.success && json.data?.has_superadmin) {
      const container = document.getElementById('superadminLink');
      if (container) container.style.display = '';
    }
  } catch(_) {}
}

// ── Page de connexion Super Admin ─────────────────────────────────────────────
function showSuperAdminLogin() {
  const loginScreen = document.getElementById('loginScreen');
  if (!loginScreen) return;

  // Cacher le formulaire de login normal et afficher celui du super admin
  const loginCard = loginScreen.querySelector('.login-card');
  if (!loginCard) return;

  loginCard.innerHTML = `
    <div style="text-align:center;margin-bottom:24px">
      <div style="width:64px;height:64px;margin:0 auto 12px;background:linear-gradient(135deg,#f59e0b,#d97706);border-radius:16px;display:flex;align-items:center;justify-content:center;font-size:28px">🛡️</div>
      <h1 style="margin:0;font-size:22px;color:#fff;font-weight:700">Super Administration</h1>
      <p style="margin:6px 0 0;font-size:13px;color:rgba(255,255,255,.5)">Gestion multi-tenant</p>
    </div>

    <div id="saSetupBanner" style="display:none;margin-bottom:16px;padding:12px;border-radius:10px;background:rgba(245,166,35,.15);border:1px solid rgba(245,166,35,.3);font-size:12px;color:rgba(255,255,255,.8)">
      <strong>🔧 Configuration initiale</strong><br>
      Définissez vos identifiants super admin pour la première fois.
    </div>

    <div id="saLoginForm">
      <div style="margin-bottom:14px">
        <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:rgba(255,255,255,.7)">Login super admin</label>
        <input type="text" id="saLoginInput" placeholder="superadmin" autocomplete="username"
          style="width:100%;padding:10px 12px;border-radius:8px;border:1px solid rgba(255,255,255,.15);background:rgba(255,255,255,.08);color:#fff;font-size:14px;box-sizing:border-box"
          onkeydown="if(event.key==='Enter')doSuperAdminLogin()">
      </div>
      <div style="margin-bottom:14px">
        <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:rgba(255,255,255,.7)">Mot de passe</label>
        <input type="password" id="saPasswordInput" placeholder="••••••••" autocomplete="current-password"
          style="width:100%;padding:10px 12px;border-radius:8px;border:1px solid rgba(255,255,255,.15);background:rgba(255,255,255,.08);color:#fff;font-size:14px;box-sizing:border-box"
          onkeydown="if(event.key==='Enter')doSuperAdminLogin()">
      </div>

      <div id="saSetupFields" style="display:none">
        <div style="margin-bottom:14px">
          <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:rgba(255,255,255,.7)">Confirmer le mot de passe</label>
          <input type="password" id="saPasswordConfirm" placeholder="••••••••"
            style="width:100%;padding:10px 12px;border-radius:8px;border:1px solid rgba(255,255,255,.15);background:rgba(255,255,255,.08);color:#fff;font-size:14px;box-sizing:border-box">
        </div>
        <div style="margin-bottom:14px">
          <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:rgba(255,255,255,.7)">Email (optionnel)</label>
          <input type="email" id="saEmailInput" placeholder="admin@entreprise.fr"
            style="width:100%;padding:10px 12px;border-radius:8px;border:1px solid rgba(255,255,255,.15);background:rgba(255,255,255,.08);color:#fff;font-size:14px;box-sizing:border-box">
        </div>
      </div>

      <div id="saLoginError" style="display:none;color:#fca5a5;font-size:12px;margin:0 0 12px;text-align:center"></div>

      <button id="saLoginBtn" onclick="doSuperAdminLogin()"
        style="width:100%;padding:12px;border:none;border-radius:8px;background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;font-weight:600;font-size:14px;cursor:pointer;margin-bottom:12px">
        Connexion Super Admin
      </button>
      <div id="saLoginSpinner" style="display:none;text-align:center;padding:10px;color:rgba(255,255,255,.5);font-size:13px">Connexion…</div>

      <div id="saMicrosoftBtn" style="display:none">
        <div style="display:flex;align-items:center;gap:8px;margin:8px 0">
          <div style="flex:1;height:1px;background:rgba(255,255,255,.12)"></div>
          <span style="font-size:11px;color:rgba(255,255,255,.35)">ou</span>
          <div style="flex:1;height:1px;background:rgba(255,255,255,.12)"></div>
        </div>
        <button onclick="doSuperAdminMicrosoftLogin()"
          style="width:100%;padding:11px;border:1px solid rgba(255,255,255,.15);border-radius:8px;background:rgba(255,255,255,.06);color:#fff;font-weight:500;font-size:13px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px">
          <svg width="18" height="18" viewBox="0 0 21 21"><rect x="1" y="1" width="9" height="9" fill="#f25022"/><rect x="11" y="1" width="9" height="9" fill="#7fba00"/><rect x="1" y="11" width="9" height="9" fill="#00a4ef"/><rect x="11" y="11" width="9" height="9" fill="#ffb900"/></svg>
          Connexion Microsoft
        </button>
      </div>
    </div>

    <div style="text-align:center;margin-top:16px">
      <a href="?" style="color:rgba(255,255,255,.4);font-size:12px;text-decoration:none">← Retour à la connexion normale</a>
    </div>
  `;

  // Vérifier si c'est le setup initial
  checkSuperAdminSetup();

  setTimeout(() => document.getElementById('saLoginInput')?.focus(), 100);
}

async function checkSuperAdminSetup() {
  try {
    const res = await fetch('api/index.php?action=superadmin_status&check_setup=1');
    let json;
    try { json = await res.json(); } catch(_) { return; }
    if (json && json.success && json.data?.needs_setup) {
      // Afficher directement le formulaire de setup
      document.getElementById('saSetupBanner').style.display = '';
      document.getElementById('saSetupFields').style.display = '';
      document.getElementById('saLoginBtn').textContent = 'Configurer & Connexion';
    }
    // Afficher le bouton Microsoft si OAuth est configuré
    if (json && json.success && json.data?.microsoft_enabled) {
      const msBtn = document.getElementById('saMicrosoftBtn');
      if (msBtn) msBtn.style.display = '';
    }
  } catch(_) {}
}

async function doSuperAdminMicrosoftLogin() {
  const errEl = document.getElementById('saLoginError');
  try {
    const res = await fetch('api/index.php?action=superadmin_microsoft_url');
    const json = await res.json();
    if (!json.success) { 
      if (errEl) { errEl.textContent = json.error || 'Erreur'; errEl.style.display = ''; }
      return; 
    }

    // Ouvrir le popup Microsoft
    const w = 500, h = 650;
    const left = (screen.width - w) / 2, top = (screen.height - h) / 2;
    const popup = window.open(json.data.url, 'saOAuth', `width=${w},height=${h},left=${left},top=${top}`);

    // Écouter le postMessage du popup OAuth
    let resolved = false;
    
    const messageHandler = async (event) => {
      if (resolved) return;
      // ⚠️ FIX SÉCURITÉ : refuser tout message extérieur à notre origine.
      // Critique côté super-admin : un message frauduleux pouvait initier
      // un check OAuth avec un email arbitraire.
      if (event.origin !== window.location.origin) return;
      const data = event.data;
      if (!data || data.type !== 'MICROSOFT_LOGIN_SUCCESS') return;
      resolved = true;
      window.removeEventListener('message', messageHandler);
      
      // ⚠️ FIX SÉCURITÉ : on ne renvoie plus d'email au serveur. Le serveur a
      // déjà vérifié l'identité Microsoft et posé la session super-admin. On
      // confirme via un jeton opaque à usage unique (ou, à défaut, la session
      // déjà établie côté serveur — le handler gère les deux cas).
      const saToken = data.sa_token || '';
      try {
        const saRes = await fetch('api/index.php?action=superadmin_oauth_check', {
          method: 'POST',
          credentials: 'include',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({ token: saToken }),
        });
        const saJson = await saRes.json();
        if (saJson.success) {
          window.location.href = '?superadmin';
        } else {
          if (errEl) { errEl.textContent = saJson.error || 'Accès refusé.'; errEl.style.display = ''; }
        }
      } catch(e) {
        if (errEl) { errEl.textContent = 'Erreur de vérification : ' + e.message; errEl.style.display = ''; }
      }
    };
    
    window.addEventListener('message', messageHandler);

    // Fallback : si postMessage ne marche pas, polling via token file
    let attempts = 0;
    const poll = setInterval(async () => {
      attempts++;
      if (resolved || attempts > 120) { clearInterval(poll); return; }
      if (popup && popup.closed && !resolved) {
        clearInterval(poll);
        // Le popup est fermé sans postMessage. Si l'authentification Microsoft a
        // abouti, oauth/microsoft.php a déjà posé la session super-admin côté
        // serveur. On confirme SANS fournir d'email (le handler valide la session
        // déjà établie). Aucune donnée d'identité n'est acceptée depuis le client.
        try {
          const saRes = await fetch('api/index.php?action=superadmin_oauth_check', {
            method: 'POST',
            credentials: 'include',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({}),
          });
          const saJson = await saRes.json();
          if (saJson.success) {
            resolved = true;
            window.removeEventListener('message', messageHandler);
            window.location.href = '?superadmin';
          } else {
            if (errEl) { errEl.textContent = saJson.error || 'Connexion Microsoft annulée.'; errEl.style.display = ''; }
          }
        } catch(e) {
          if (errEl) { errEl.textContent = 'Erreur.'; errEl.style.display = ''; }
        }
      }
    }, 1000);
  } catch(e) {
    if (errEl) { errEl.textContent = 'Erreur : ' + e.message; errEl.style.display = ''; }
  }
}

async function doSuperAdminLogin() {
  const login = document.getElementById('saLoginInput')?.value?.trim();
  const password = document.getElementById('saPasswordInput')?.value;
  const errEl = document.getElementById('saLoginError');
  const btn = document.getElementById('saLoginBtn');
  const spinner = document.getElementById('saLoginSpinner');

  errEl.style.display = 'none';

  if (!login || !password) {
    errEl.textContent = 'Identifiant et mot de passe requis.';
    errEl.style.display = 'block';
    return;
  }

  // Vérifier si c'est un setup initial
  const confirmEl = document.getElementById('saPasswordConfirm');
  const setupFields = document.getElementById('saSetupFields');
  if (setupFields && setupFields.style.display !== 'none') {
    return doSuperAdminSetup();
  }

  btn.style.display = 'none';
  spinner.style.display = 'block';

  try {
    const res = await fetch('api/index.php?action=superadmin_login', {
      method: 'POST', headers: {'Content-Type':'application/json'},
      credentials: 'include',
      body: JSON.stringify({ login, password })
    });
    
    let json;
    try {
      json = await res.json();
    } catch(parseErr) {
      throw new Error('Réponse serveur invalide (non-JSON). Vérifiez la configuration PHP et les permissions du dossier data/.');
    }

    if (!json.success) {
      // ⚠️ FIX : ne PAS supposer qu'un échec = première configuration. Avant, toute
      // erreur « incorrect » affichait le formulaire de setup — y compris après un
      // changement de mot de passe, où un simple mauvais MDP donnait l'impression
      // d'une première connexion. On demande désormais au serveur si un setup
      // initial est RÉELLEMENT nécessaire (mot de passe par défaut encore actif ou
      // aucun super admin configuré) avant d'afficher quoi que ce soit.
      let needsSetup = false;
      try {
        const statusRes = await fetch('api/index.php?action=superadmin_status&check_setup=1');
        const statusJson = await statusRes.json();
        needsSetup = !!(statusJson && statusJson.success && statusJson.data && statusJson.data.needs_setup);
      } catch(_) {}

      if (needsSetup) {
        // Vraie première configuration : proposer le formulaire de setup.
        errEl.textContent = 'Première configuration : définissez vos identifiants super admin ci-dessous.';
        errEl.style.display = 'block';
        document.getElementById('saSetupBanner').style.display = '';
        document.getElementById('saSetupFields').style.display = '';
        document.getElementById('saLoginBtn').textContent = 'Configurer & Connexion';
        btn.style.display = 'block';
        spinner.style.display = 'none';
        return;
      }

      // Sinon : identifiants réellement incorrects (ou autre erreur, ex. rate limit).
      // On garde le formulaire de connexion normal et on s'assure que les champs de
      // setup restent masqués si jamais ils avaient été affichés précédemment.
      document.getElementById('saSetupBanner').style.display = 'none';
      document.getElementById('saSetupFields').style.display = 'none';
      document.getElementById('saLoginBtn').textContent = 'Connexion Super Admin';
      throw new Error(json.error || 'Identifiant ou mot de passe super admin incorrect.');
    }

    // Succès — initialiser l'interface super admin
    initSuperAdminApp(json.data);
  } catch(e) {
    errEl.textContent = e.message;
    errEl.style.display = 'block';
    btn.style.display = 'block';
    spinner.style.display = 'none';
  }
}

async function doSuperAdminSetup() {
  const login = document.getElementById('saLoginInput')?.value?.trim();
  const password = document.getElementById('saPasswordInput')?.value;
  const confirm = document.getElementById('saPasswordConfirm')?.value;
  const email = document.getElementById('saEmailInput')?.value?.trim();
  const errEl = document.getElementById('saLoginError');

  errEl.style.display = 'none';

  if (!login) { errEl.textContent = 'Login requis.'; errEl.style.display = 'block'; return; }
  if (!password || password.length < 6) { errEl.textContent = 'Minimum 6 caractères.'; errEl.style.display = 'block'; return; }
  if (password !== confirm) { errEl.textContent = 'Les mots de passe ne correspondent pas.'; errEl.style.display = 'block'; return; }

  try {
    const res = await fetch('api/index.php?action=superadmin_setup', {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ login, password, email })
    });
    const json = await res.json();
    if (!json.success) throw new Error(json.error);

    // Setup réussi, maintenant se connecter
    const loginRes = await fetch('api/index.php?action=superadmin_login', {
      method: 'POST', headers: {'Content-Type':'application/json'},
      credentials: 'include',
      body: JSON.stringify({ login, password })
    });
    const loginJson = await loginRes.json();
    if (!loginJson.success) throw new Error(loginJson.error);

    initSuperAdminApp(loginJson.data);
  } catch(e) {
    errEl.textContent = e.message;
    errEl.style.display = 'block';
  }
}

// ── Initialisation de l'app Super Admin ───────────────────────────────────────
function initSuperAdminApp(saUser) {
  document.getElementById('loginScreen').style.display = 'none';
  document.getElementById('app').classList.add('visible');
  document.body.classList.add('app-mode');

  // ── Nettoyage de la barre du haut en mode Super Admin ──────────────────────
  // Le bouton « Mon profil » de la topbar ouvre le profil d'un UTILISATEUR Larka
  // (via App.currentUser). Or un Super Admin n'est pas un utilisateur Larka : le
  // bouton est inutile ici et afficherait « Utilisateur non connecté ». Le profil
  // du Super Admin se gère dans « ⚙️ Paramètres › Mon profil Larka ». On le masque
  // donc pour éviter le doublon et la confusion.
  const _saProfBtn = document.getElementById('myProfileBtnTopbar');
  if (_saProfBtn) _saProfBtn.style.display = 'none';

  // Mémoriser si la session est le SA PRINCIPAL (seul habilité à gérer les
  // comptes Super Admin). Indice utilisé par l'UI ; la vérité fait toujours foi
  // côté serveur (require_primary_superadmin).
  if (typeof App !== 'undefined') App.saIsPrimary = !!saUser.is_primary;

  // Avatar et nom
  document.getElementById('sidebarAvatar').textContent = '🛡️';
  document.getElementById('sidebarAvatar').style.fontSize = '18px';
  document.getElementById('sidebarName').textContent = saUser.login || 'Super Admin';
  document.getElementById('sidebarRole').textContent = 'Super Admin';

  // Sidebar minimaliste
  const nav = document.getElementById('sidebarNav');
  nav.innerHTML = `
    <div class="nav-section-title" style="color:rgba(245,166,35,.6)">Super Administration</div>
    <div class="nav-item active" data-page="superadmin" onclick="navigate('superadmin')">
      <span class="icon">🏢</span><span class="nav-label">Multi-Tenant</span>
    </div>
    <div class="nav-item" data-page="superadmin_branding" onclick="navigate('superadmin_branding')">
      <span class="icon">🎨</span><span class="nav-label">Personnalisation</span>
    </div>
    <div class="nav-item" data-page="superadmin_accounts" onclick="saShowAllAccounts()">
      <span class="icon">👥</span><span class="nav-label">Registre comptes</span>
    </div>
    <div class="nav-item" data-page="superadmin_logs" onclick="saSuperAdminLogs()">
      <span class="icon">📊</span><span class="nav-label">Monitoring</span>
    </div>
    <div class="nav-item" data-page="superadmin_serveur" onclick="saShowServerConfig()">
      <span class="icon">🖥️</span><span class="nav-label">Config serveur</span>
    </div>
    <div class="nav-section-title" style="margin-top:12px">Actions</div>
    <div class="nav-item" onclick="saChangePasswordModal()">
      <span class="icon">🔑</span><span class="nav-label">Changer mot de passe</span>
    </div>
    <div class="nav-item" onclick="doSuperAdminLogout()">
      <span class="icon">🚪</span><span class="nav-label">Déconnexion SA</span>
    </div>
  `;

  // NB : la page « superadmin » est déjà enregistrée dans PAGES (js/nav.js) avec
  // sa clé renderFn:'renderSuperAdmin'. On ne la RÉÉCRIT PAS ici : le faire avec
  // { render: ... } cassait navigate() (qui résout via renderFn) → clic sur
  // « Multi-Tenant » menait à « Page indisponible ».

  // Cacher le pageTitle et le mettre à jour
  document.getElementById('pageTitle').textContent = 'Multi-Tenant';

  // Lancer le rendu — superadmin.js est en LAZY-LOAD. On garantit son chargement
  // AVANT d'appeler renderSuperAdmin(), sinon ReferenceError → écran figé
  // (« chargement infini ») et les onclick directs (Personnalisation, Registre…)
  // ne marchent qu'après un premier passage par Multi-Tenant.
  App.currentPage = 'superadmin';
  const _saInitialRender = () => {
    try { renderSuperAdmin(); }
    catch (e) {
      const m = document.getElementById('mainContent');
      if (m) m.innerHTML = '<div style="padding:40px;text-align:center;color:var(--red)">'
        + 'Erreur de chargement de l\'interface : ' + (e?.message || 'inconnue') + '</div>';
    }
  };
  if (window.PageLoader && typeof window.PageLoader.ensure === 'function') {
    window.PageLoader.ensure('superadmin').then(_saInitialRender).catch(_saInitialRender);
  } else {
    _saInitialRender();
  }
}

async function doSuperAdminLogout() {
  try {
    await fetch('api/index.php?action=superadmin_logout', { method: 'POST', credentials: 'include' });
  } catch(_) {}
  window.location.href = '?superadmin';
}

function saChangePasswordModal() {
  openModal('🔑 Changer le mot de passe Super Admin', `
    <div class="form-grid" style="grid-template-columns:1fr">
      <div class="form-group">
        <label class="form-label">Ancien mot de passe</label>
        <input class="form-control" type="password" id="sa_old_pwd">
      </div>
      <div class="form-group">
        <label class="form-label">Nouveau mot de passe (min. 6 car.)</label>
        <input class="form-control" type="password" id="sa_new_pwd">
      </div>
      <div class="form-group">
        <label class="form-label">Confirmer</label>
        <input class="form-control" type="password" id="sa_new_pwd2">
      </div>
    </div>
  `, async () => {
    const oldPwd = document.getElementById('sa_old_pwd')?.value;
    const newPwd = document.getElementById('sa_new_pwd')?.value;
    const newPwd2 = document.getElementById('sa_new_pwd2')?.value;

    if (!oldPwd) { toast('Ancien mot de passe requis.', 'error'); return; }
    if (!newPwd || newPwd.length < 6) { toast('Minimum 6 caractères.', 'error'); return; }
    if (newPwd !== newPwd2) { toast('Les mots de passe ne correspondent pas.', 'error'); return; }

    try {
      const res = await fetch('api/index.php?action=superadmin_change_password', {
        method: 'POST', headers: {'Content-Type':'application/json'}, credentials: 'include',
        body: JSON.stringify({ oldPassword: oldPwd, newPassword: newPwd })
      });
      const json = await res.json();
      if (!json.success) { toast(json.error, 'error'); return; }
      toast('Mot de passe modifié !', 'success');
      closeModal();
    } catch(e) { toast('Erreur réseau.', 'error'); }
  });
}

function saSuperAdminLogs() {
  // Cette entrée ne passe pas par navigate() : c'est donc à elle de vider la
  // barre d'actions, sinon les boutons de la page précédente persistent.
  if (typeof clearPageActionBar === 'function') clearPageActionBar();
  document.getElementById('pageTitle').textContent = 'Monitoring';
  document.querySelectorAll('.nav-item').forEach(el => {
    el.classList.toggle('active', el.dataset.page === 'superadmin_logs');
  });
  saLoadMonitoring();
}

// ── Config serveur (chargée depuis la page Configuration existante) ──────────
async function saShowServerConfig() {
  // Cette entrée ne passe pas par navigate() : c'est donc à elle de vider la
  // barre d'actions, sinon les boutons de la page précédente persistent.
  if (typeof clearPageActionBar === 'function') clearPageActionBar();
  document.getElementById('pageTitle').textContent = 'Configuration serveur';
  document.querySelectorAll('.nav-item').forEach(el => {
    el.classList.toggle('active', el.dataset.page === 'superadmin_serveur');
  });

  // Simuler une session admin pour que config_serveur accepte la requête
  // (le check dans gestion.php vérifie $_SESSION['superadmin'])
  // On utilise directement le rendu de l'onglet serveur de configuration.js
  const content = document.getElementById('mainContent');
  content.innerHTML = '<div id="cfg-srv-slot" style="padding:50px;text-align:center;color:var(--on-content-dim)">⏳ Chargement de la configuration serveur…</div>';

  // En mode Super Admin, configuration.js n'est pas chargé au démarrage car la
  // sidebar SA ne contient pas le menu "Configuration". On le charge donc à la
  // demande via PageLoader (le mapping 'configuration' est défini dans
  // js/page-loader.js).
  if (typeof _loadServeurConfig !== 'function') {
    if (window.PageLoader && typeof window.PageLoader.ensure === 'function') {
      try {
        await window.PageLoader.ensure('configuration');
      } catch(e) {
        content.innerHTML = `<div style="padding:40px;text-align:center;color:var(--red)">
          <p>❌ Impossible de charger la configuration serveur.</p>
          <p style="font-size:12px;margin-top:8px;color:var(--gray-text)">${e.message || ''}</p>
        </div>`;
        return;
      }
    }
  }

  if (typeof _loadServeurConfig === 'function') {
    _loadServeurConfig();
  } else {
    content.innerHTML = `<div style="padding:40px;text-align:center;color:var(--gray-text)">
      <p>La fonction de configuration serveur n'est pas disponible.</p>
      <p style="font-size:12px;margin-top:8px">Assurez-vous que <code>configuration.js</code> est chargé.</p>
    </div>`;
  }
}

async function saLoadMonitoring() {
  const content = document.getElementById('mainContent');
  content.innerHTML = `
    <div style="margin-bottom:20px;display:flex;align-items:center;gap:16px;flex-wrap:wrap">
      <h2 style="margin:0;font-size:20px;color:var(--on-content)">📊 Monitoring Multi-Tenant</h2>
      <div style="flex:1"></div>
      <button class="btn" onclick="saLoadMonitoring()" style="display:flex;align-items:center;gap:6px">🔄 Rafraîchir</button>
    </div>
    <div id="saMonGlobal" style="margin-bottom:20px"></div>
    <div id="saMonGrid" style="display:grid;gap:16px">
      <div style="text-align:center;padding:40px;color:var(--on-content-dim)">Chargement des données…</div>
    </div>`;

  try {
    const res = await fetch('api/index.php?action=superadmin_monitoring', { credentials: 'include' });
    const json = await res.json();
    if (!json.success) throw new Error(json.error);

    const data = json.data;
    const g = data.global;
    const tenants = Object.values(data.tenants);

    // Totaux agrégés
    let totalUsers = 0, totalBiens = 0, totalEquips = 0, totalInterv = 0, totalDemandes = 0, totalDocs = 0, totalSize = 0;
    tenants.forEach(t => {
      if (!t.stats) return;
      totalUsers += t.stats.utilisateurs_actifs || 0;
      totalBiens += t.stats.biens || 0;
      totalEquips += t.stats.equipements || 0;
      totalInterv += t.stats.interventions || 0;
      totalDemandes += t.stats.demandes_nouvelles || 0;
      totalDocs += t.stats.documents || 0;
      if (t.db_size) totalSize += t.db_size;
    });

    document.getElementById('saMonGlobal').innerHTML = `
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px">
        ${_monCard('🏢', 'Tenants', g.tenants_actifs + '/' + g.total_tenants, 'actifs')}
        ${_monCard('👥', 'Utilisateurs', totalUsers, 'actifs au total')}
        ${_monCard('🔑', 'Comptes locaux', g.total_comptes_locaux, 'dans le registre')}
        ${_monCard('🗄️', 'Base SA', (g.superadmin_db?.driver||'sqlite').toUpperCase(), g.superadmin_db?.dbname || 'fichier local')}
        ${_monCard('🏢', 'Biens', totalBiens, 'tous tenants')}
        ${_monCard('⚙️', 'Équipements', totalEquips, 'tous tenants')}
        ${_monCard('🔧', 'Interventions', totalInterv, 'tous tenants')}
        ${_monCard('📝', 'Demandes', totalDemandes, 'nouvelles')}
        ${_monCard('💾', 'Stockage', (totalSize / 1024 / 1024).toFixed(1) + ' Mo', 'total DBs')}
      </div>`;

    // Détail par tenant
    document.getElementById('saMonGrid').innerHTML = tenants.map(t => {
      const color = t.couleur || '#6b7280';
      const statusIcon = t.db_status === 'ok' ? '🟢' : t.db_status === 'missing' ? '🟡' : '🔴';
      const s = t.stats || {};
      const size = t.db_size ? (t.db_size / 1024 / 1024).toFixed(2) + ' Mo' : '—';
      const lastAct = s.derniere_activite ? _monTimeAgo(s.derniere_activite) : '—';

      const alertes = [];
      if ((s.stock_alertes || 0) > 0) alertes.push('📦 ' + s.stock_alertes + ' stock en alerte');
      if ((s.demandes_nouvelles || 0) > 0) alertes.push('📝 ' + s.demandes_nouvelles + ' demande(s) non traitée(s)');

      return `<div style="background:var(--card-bg);border:1px solid var(--gray-border);border-radius:12px;padding:18px;border-left:4px solid ${color}">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px">
          <div style="font-size:15px;font-weight:700;color:var(--navy);flex:1">
            ${statusIcon} ${_esc(t.nom)}
            <span style="font-size:12px;font-weight:400;color:var(--gray-text);margin-left:6px">${_esc(t.key)} · ${t.driver.toUpperCase()} · ${size}</span>
          </div>
          <span style="font-size:11px;color:var(--gray-text)">Activité : ${lastAct}</span>
        </div>
        ${t.error ? `<div style="color:#e74c3c;font-size:12px;margin-bottom:10px">Erreur : ${_esc(t.error)}</div>` : ''}
        ${t.stats ? `
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(100px,1fr));gap:8px;font-size:12px">
          ${_monMini('👥', 'Utilisateurs', s.utilisateurs_actifs + '/' + s.utilisateurs_total)}
          ${_monMini('🏢', 'Biens', s.biens)}
          ${_monMini('⚙️', 'Équipements', s.equipements)}
          ${_monMini('🔧', 'Interventions', s.interventions_actives + ' actives / ' + s.interventions)}
          ${_monMini('📋', 'Contrats', s.contrats_actifs + ' actifs / ' + s.contrats)}
          ${_monMini('📦', 'Stock', s.stock_articles + (s.stock_alertes > 0 ? ' ⚠️' + s.stock_alertes : ''))}
          ${_monMini('📝', 'Demandes', s.demandes)}
          ${_monMini('💰', 'Matériel', s.gestion_materiel)}
          ${_monMini('📎', 'Documents', s.documents)}
        </div>
        ${alertes.length > 0 ? `<div style="margin-top:10px;display:flex;flex-wrap:wrap;gap:6px">${alertes.map(a => `<span style="background:#fef3c7;color:#92400e;padding:3px 10px;border-radius:6px;font-size:11px;font-weight:600">${a}</span>`).join('')}</div>` : ''}
        ` : '<div style="font-size:12px;color:var(--gray-text)">Pas de données disponibles</div>'}
      </div>`;
    }).join('');

  } catch(e) {
    // ⚠️ FIX SÉCURITÉ : passer par textContent au lieu d'innerHTML pour
    // neutraliser toute injection HTML dans un message d'erreur backend.
    const grid = document.getElementById('saMonGrid');
    if (grid) {
      grid.innerHTML = '<div style="padding:20px;color:#e74c3c;text-align:center"></div>';
      grid.firstElementChild.textContent = e.message || 'Erreur';
    }
  }
}

function _monCard(icon, label, value, sub) {
  return `<div style="background:var(--card-bg);border:1px solid var(--gray-border);border-radius:10px;padding:14px;text-align:center">
    <div style="font-size:20px;margin-bottom:4px">${icon}</div>
    <div style="font-size:22px;font-weight:700;color:var(--navy)">${value}</div>
    <div style="font-size:11px;color:var(--gray-text)">${label}</div>
    <div style="font-size:10px;color:var(--gray-text);margin-top:2px">${sub}</div>
  </div>`;
}

function _monMini(icon, label, value) {
  return `<div style="background:var(--gray-bg);padding:6px 10px;border-radius:6px">
    <div style="color:var(--gray-text);font-size:10px">${icon} ${label}</div>
    <div style="font-weight:600;color:var(--navy)">${value}</div>
  </div>`;
}

function _monTimeAgo(dateStr) {
  if (!dateStr) return '—';
  const d = new Date(dateStr);
  const now = new Date();
  const diff = Math.floor((now - d) / 1000);
  if (diff < 60) return 'à l\'instant';
  if (diff < 3600) return Math.floor(diff/60) + ' min';
  if (diff < 86400) return Math.floor(diff/3600) + ' h';
  if (diff < 2592000) return Math.floor(diff/86400) + ' j';
  return d.toLocaleDateString('fr-FR');
}

// ── Registre centralisé des comptes locaux ────────────────────────────────────
async function saShowAllAccounts() {
  // Cette entrée ne passe pas par navigate() : c'est donc à elle de vider la
  // barre d'actions, sinon les boutons de la page précédente persistent.
  if (typeof clearPageActionBar === 'function') clearPageActionBar();
  const content = document.getElementById('mainContent');
  document.getElementById('pageTitle').textContent = 'Registre des comptes';

  content.innerHTML = `
    <div style="margin-bottom:20px;display:flex;align-items:center;gap:16px;flex-wrap:wrap">
      <h2 style="margin:0;font-size:20px;color:var(--on-content)">👥 Registre centralisé des comptes locaux</h2>
      <div style="flex:1"></div>
      <button class="btn" onclick="saSyncAccounts()" style="display:flex;align-items:center;gap:6px">
        🔄 Synchroniser
      </button>
    </div>
    <div id="saAccountsGrid">
      <div style="text-align:center;padding:40px;color:var(--on-content-dim)">Chargement…</div>
    </div>`;

  try {
    const res = await fetch('api/index.php?action=superadmin_local_accounts', { credentials: 'include' });
    const json = await res.json();
    if (!json.success) throw new Error(json.error);

    const accounts = json.data || [];
    const grid = document.getElementById('saAccountsGrid');

    if (accounts.length === 0) {
      grid.innerHTML = '<div style="padding:40px;text-align:center;color:var(--on-content-dim)">Aucun compte local enregistré. Cliquez sur "Synchroniser" pour scanner les bases.</div>';
      return;
    }

    // Grouper par tenant
    const byTenant = {};
    accounts.forEach(a => {
      if (!byTenant[a.tenant_key]) byTenant[a.tenant_key] = { nom: a.tenant_nom, couleur: a.tenant_couleur, logins: [] };
      byTenant[a.tenant_key].logins.push(a.login);
    });

    grid.innerHTML = `
      <div style="margin-bottom:12px;font-size:13px;color:var(--on-content-dim)">${accounts.length} compte(s) local(aux) répartis sur ${Object.keys(byTenant).length} tenant(s)</div>
      ${Object.entries(byTenant).map(([key, t]) => `
        <div style="background:var(--card-bg);border:1px solid var(--gray-border);border-radius:12px;padding:16px;margin-bottom:12px;border-left:4px solid ${t.couleur}">
          <div style="font-size:14px;font-weight:700;color:var(--navy);margin-bottom:10px">
            ${t.nom} <span style="font-weight:400;color:var(--gray-text);font-size:12px">(${key})</span>
            <span style="background:${t.couleur}22;color:${t.couleur};padding:2px 8px;border-radius:6px;font-size:11px;font-weight:600;margin-left:8px">${t.logins.length} compte(s)</span>
          </div>
          <div style="display:flex;flex-wrap:wrap;gap:6px">
            ${t.logins.map(l => `<code style="background:var(--gray-bg);padding:4px 10px;border-radius:6px;font-size:12px">${l}</code>`).join('')}
          </div>
        </div>
      `).join('')}
    `;
  } catch(e) {
    // ⚠️ FIX SÉCURITÉ : voir ci-dessus, même raison.
    const grid = document.getElementById('saAccountsGrid');
    if (grid) {
      grid.innerHTML = '<div style="padding:20px;color:#e74c3c;text-align:center"></div>';
      grid.firstElementChild.textContent = e.message || 'Erreur';
    }
  }
}

async function saSyncAccounts() {
  try {
    const res = await fetch('api/index.php?action=superadmin_sync_accounts', {
      method: 'POST', headers: {'Content-Type':'application/json'}, credentials: 'include',
      body: '{}'
    });
    const json = await res.json();
    if (!json.success) { toast(json.error, 'error'); return; }
    toast(json.data.message, 'success');
    if (json.data.errors?.length) {
      json.data.errors.forEach(e => toast(e, 'error'));
    }
    saShowAllAccounts();
  } catch(e) { toast('Erreur réseau.', 'error'); }
}

// ── Auto-check session Super Admin au chargement ──────────────────────────────
async function checkSuperAdminSession() {
  if (!isSuperAdminMode()) return false;
  try {
    const res = await fetch('api/index.php?action=superadmin_me', { credentials: 'include' });
    let json;
    try { json = await res.json(); } catch(_) { json = null; }
    if (json && json.success && json.data) {
      initSuperAdminApp(json.data);
      return true;
    }
  } catch(_) {}
  // Pas de session — afficher le login super admin
  showSuperAdminLogin();
  return true;
}

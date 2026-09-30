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
 * Larka — Contrôleur principal & Initialisation
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Point d'entrée de l'application après authentification.
 *
 * CONTENU :
 *   - App (objet global) → état de l'application (page courante, filtres,
 *                           tri, pagination, utilisateur connecté)
 *   - initApp(user)      → initialise la sidebar, la navigation, les badges,
 *                           l'assistant IA, et navigue vers le dashboard
 *   - canEdit/canView/canDelete/isAdmin → helpers de permissions basés sur le rôle
 *   - Gestion de la pagination, tri, recherche inter-pages
 *
 * FLUX :
 *   auth.js (login) → initApp(user) → buildSidebar → navigate('dashboard')
 *                                     → bootAssistant() (si configuré)
 * ═══════════════════════════════════════════════════════════════════════════════
 */

// ── État global de l'application ──────────────────────────────────────────────
const App = {
  currentUser:   null,
  currentPage:   'dashboard',
  currentFilter: 'Tous',
  searchTerm:    '',
  _advancedFilters: {},
  _searchTimer: null,
  _advancedDebounce: null,
  _badgeInterval: null,
  _panneauFiltreOuvert: false,
  _sortKey: null,
  _sortDir: 'asc',

  // ── Pagination (client) ─────────────────────────────────────────────────
  _pagination: {},            // { key: { page: 1 } }
  _rowsPerPageFallback: 25,   // valeur par défaut si rien en localStorage

  _ensurePager(key) {
    const k = key || App.currentPage;
    if (!App._pagination[k]) App._pagination[k] = { page: 1 };
    return App._pagination[k];
  },
  getPage(key) {
    return App._ensurePager(key).page || 1;
  },
  setPage(page, key) {
    const p = App._ensurePager(key);
    p.page = Math.max(1, parseInt(page, 10) || 1);
    App.renderCurrentPage();
  },
  setPageSilently(page, key) {
    const p = App._ensurePager(key);
    p.page = Math.max(1, parseInt(page, 10) || 1);
  },
  resetPage(key) {
    App.setPageSilently(1, key);
  },
  getRowsPerPage(key, fallback = null) {
    const k = key || App.currentPage;
    const v = localStorage.getItem('gmao_rows_' + k);
    if (v === 'all') return 'all';
    const n = parseInt(v || '', 10);
    if (!isNaN(n) && n > 0) return n;
    return fallback ?? App._rowsPerPageFallback;
  },
  setRowsPerPage(val, key) {
    const k = key || App.currentPage;
    const s = String(val);
    if (s === 'all') localStorage.setItem('gmao_rows_' + k, 'all');
    else {
      const n = parseInt(s, 10);
      if (!isNaN(n) && n > 0) localStorage.setItem('gmao_rows_' + k, String(n));
      else localStorage.removeItem('gmao_rows_' + k);
    }
    // Reset pages liées
    App.resetPage(k);
    if (k === 'historique') {
      App.resetPage('historique_sup');
      App.resetPage('historique_dem');
      App.resetPage('historique_interv');
    }
    App.renderCurrentPage();
  },

  forceLogout() {
    if (App._badgeInterval) {
      clearInterval(App._badgeInterval);
      App._badgeInterval = null;
    }
    App.currentUser = null;
    window.location.href = window.location.pathname + '?t=' + Date.now();
  },

  // ── Recherche & filtres ────────────────────────────────────────────────────
  // Recherche DYNAMIQUE : filtre en direct pendant la frappe (débounce court).
  // Le focus et la position du curseur survivent au re-render grâce à
  // _captureFilterFocus/_restoreFilterFocus (voir renderCurrentPage).
  handleSearch(val) {
    App.searchTerm = val;
    clearTimeout(App._searchDebounce);
    App._searchDebounce = setTimeout(() => {
      App.resetPage();
      App.renderCurrentPage();
    }, 250);
  },
  handleSearchKey(e) {
    if (e.key === 'Enter') {
      clearTimeout(App._searchDebounce);
      App.searchTerm = e.target.value;
      App._applyFilters();
    } else if (e.key === 'Escape') {
      // Échap : vide la recherche et réaffiche tout
      clearTimeout(App._searchDebounce);
      e.target.value = '';
      App.searchTerm = '';
      App.resetPage();
      App.renderCurrentPage();
    }
  },
  handleFilter(val) {
    App.currentFilter = val;
    App.resetPage();
    App.renderCurrentPage();
  },
  handleSort(key) {
    if (App._sortKey === key) {
      App._sortDir = App._sortDir === 'asc' ? 'desc' : 'asc';
    } else {
      App._sortKey = key;
      App._sortDir = 'asc';
    }
    App.renderCurrentPage();
  },
  resetPanneau() { App._panneauFiltreOuvert = false; },

  // ── Préservation du focus à travers les re-renders ─────────────────────────
  // PROBLÈME résolu ici : re-rendre la page reconstruit le HTML, donc l'input
  // dans lequel l'utilisateur était en train de taper est DÉTRUIT et recréé →
  // perte du focus et du curseur après chaque frappe (« la barre de recherche
  // arrête l'écriture »). On capture l'identité du champ actif + la position
  // du curseur AVANT le render, et on les restaure APRÈS.
  // Ciblé volontairement sur les champs de recherche/filtre (.search-input,
  // ff_* du FiltresEngine, fa_* de l'ancien panneau) pour ne pas interférer
  // avec les formulaires des modales.
  _captureFilterFocus() {
    const el = document.activeElement;
    if (!el || !['INPUT', 'SELECT', 'TEXTAREA'].includes(el.tagName)) return null;
    const isSearch = !!(el.classList && el.classList.contains('search-input'));
    const isFilter = !!(el.id && (el.id.startsWith('ff_') || el.id.startsWith('fa_')));
    if (!isSearch && !isFilter) return null;
    let selStart = null, selEnd = null;
    try { selStart = el.selectionStart; selEnd = el.selectionEnd; } catch (_) {} // type=number/date peuvent lever
    return { isSearch, id: el.id || null, selStart, selEnd };
  },
  _restoreFilterFocus(f) {
    if (!f) return;
    const el = f.isSearch
      ? document.querySelector('.search-input')
      : (f.id ? document.getElementById(f.id) : null);
    if (!el) return;
    try {
      el.focus({ preventScroll: true });
      if (f.selStart !== null && f.selStart !== undefined && typeof el.setSelectionRange === 'function') {
        const len = (el.value || '').length;
        const a = Math.min(f.selStart, len);
        const b = Math.min(f.selEnd ?? a, len);
        el.setSelectionRange(a, b);
      }
    } catch (_) { /* type=number/date : focus seul, sans curseur */ }
  },

  // Re-rend la page courante. Comme PAGES[...].renderFn est désormais le nom
  // (string) de la fonction et non la référence (le module a pu être chargé
  // après nav.js), on résout window[name] à chaque appel.
  // Retourne la promesse du render (les renderFn sont souvent async) pour que
  // `await App.renderCurrentPage()` attende réellement la fin du rendu.
  renderCurrentPage() {
    const meta = PAGES[App.currentPage];
    if (!meta) return;
    const fn = typeof meta.render === 'function' ? meta.render : window[meta.renderFn];
    if (typeof fn === 'function') {
      const focus = App._captureFilterFocus();
      let out;
      try { out = fn(); } catch (e) { console.error('[renderCurrentPage] erreur:', e); }
      if (out && typeof out.then === 'function') {
        return out
          .then(() => { App._restoreFilterFocus(focus); })
          .catch(e => { console.error('[renderCurrentPage] erreur:', e); App._restoreFilterFocus(focus); });
      }
      App._restoreFilterFocus(focus);
      return out;
    } else {
      // Le script de la page n'est peut-être pas encore chargé — déclencher
      // une navigation propre pour qu'il se charge.
      if (typeof navigate === 'function') navigate(App.currentPage);
    }
  },

  applyAdvancedFilterKey(e) {
    if (e.key === 'Enter') { App._saveFiltersFromDOM(); App._applyFilters(); }
  },
  applyAdvancedFilter()       { App._saveFiltersFromDOM(); App._applyFilters(); },
  applyAdvancedFilterChange() { App._saveFiltersFromDOM(); App._applyFilters(); },
  resetAdvancedFilter() {
    App._advancedFilters[App.currentPage] = {};
    App.searchTerm = '';
    document.querySelectorAll('[id^="fa_"]').forEach(el => el.value = '');
    const si = document.querySelector('.search-input');
    if (si) si.value = '';
    App.resetPage();
    App._applyFilters();
  },
  _saveFiltersFromDOM() {
    const page = App.currentPage;
    if (!App._advancedFilters[page]) App._advancedFilters[page] = {};
    document.querySelectorAll('[id^="fa_"]').forEach(el => {
      App._advancedFilters[page][el.id.replace('fa_', '')] = el.value || '';
    });
    const si = document.querySelector('.search-input');
    if (si) App.searchTerm = si.value;
  },
  handleAdvancedInput(el) {
    clearTimeout(App._advancedDebounce);
    App._advancedDebounce = setTimeout(() => {
      App._saveFiltersFromDOM();
      App._applyFilters();
    }, 300);
  },
  getAdvancedFilters() {
    return Object.assign({}, App._advancedFilters[App.currentPage] || {});
  },
  restoreFilters() {
    const saved = App._advancedFilters[App.currentPage] || {};
    const hasFilters = Object.values(saved).some(v => v);
    Object.entries(saved).forEach(([k, v]) => {
      if (!v) return;
      const el = document.getElementById('fa_' + k);
      if (!el) return;
      el.value = v;
      if (el.tagName === 'SELECT') {
        for (const opt of el.options) opt.selected = (opt.value === v);
      }
    });
    const si = document.querySelector('.search-input');
    if (si && App.searchTerm) si.value = App.searchTerm;

    const shouldOpen = hasFilters || App._panneauFiltreOuvert;
    if (shouldOpen) {
      const panneau = document.getElementById('panneauFiltreAvance');
      if (panneau) {
        panneau.style.display = 'block';
        const btn = document.getElementById('btnFiltreAvance');
        if (btn) {
          btn.style.background  = 'var(--blue-pale)';
          btn.style.color       = 'var(--blue)';
          btn.style.borderColor = 'var(--blue)';
          btn.style.fontWeight  = '600';
        }
      }
    }
  },
  async _applyFilters() {
    App._saveFiltersFromDOM();
    // Dès qu'on change un filtre / recherche, on revient en page 1
    App.resetPage();
    if (App.currentPage === 'historique') {
      App.resetPage('historique_sup');
      App.resetPage('historique_dem');
      App.resetPage('historique_interv');
    }
    await App.renderCurrentPage();
  },
};

// ── Helpers rôles ─────────────────────────────────────────────────────────────
function canEdit()      { return ['Admin','Gestionnaire'].includes(App.currentUser?.Role); }
function canView()      { return ['Admin','Gestionnaire','Visionneur'].includes(App.currentUser?.Role); }
function canDelete()    { return !['Visionneur','Demandeur'].includes(App.currentUser?.Role); }
function isAdmin()      { return ['Admin','Gestionnaire'].includes(App.currentUser?.Role); }
function isSuperAdmin() { return isAdmin(); } // alias conservé pour compatibilité

// ── Initialisation de l'app après connexion ───────────────────────────────────
let _initAppRunning = false;
function initApp(user, isSessionRestore = false) {
  if (_initAppRunning) return;
  _initAppRunning = true;
  // ── Reset complet de l'état ────────────────────────────────────────────────
  // Nettoyer les intervalles précédents (évite les doublons après re-login)
  if (App._badgeInterval) {
    clearInterval(App._badgeInterval);
    App._badgeInterval = null;
  }

  // Réinitialiser l'état global
  App.currentUser   = user;
  App.currentPage   = 'dashboard';
  App.currentFilter = 'Tous';
  App.searchTerm    = '';
  App._advancedFilters = {};
  App._panneauFiltreOuvert = false;
  App._sortKey = null;
  App._sortDir = 'asc';
  App._pagination = {};

  // Masquer la cloche de notifs (sera réactivée par refreshBadgeDemandes)
  const bellWrap = document.getElementById('notifBellWrap');
  if (bellWrap) bellWrap.style.display = 'none';
  // Fermer le panneau notif s'il était ouvert
  const notifPanel = document.getElementById('notifPanel');
  if (notifPanel) notifPanel.style.display = 'none';

  // Fermer le panneau de préférences s'il était ouvert
  if (typeof closeUiPrefsPanel === 'function') closeUiPrefsPanel();

  // Déterminer la page à restaurer
  // ⚠️ Cas spécial : si le Super Admin bascule sur un tenant via "Accéder",
  // la dernière page mémorisée est 'superadmin' — on l'ignore pour éviter
  // de re-rediriger l'utilisateur tenant sur l'écran multi-tenant.
  let restoredPage = null;
  if (isSessionRestore) {
    try {
      const savedPage = sessionStorage.getItem('gmao_current_page');
      if (savedPage && PAGES[savedPage] && savedPage !== 'superadmin') {
        restoredPage = savedPage;
      }
      if (savedPage === 'superadmin') {
        // Nettoyer pour éviter que ça ressorte plus tard
        sessionStorage.removeItem('gmao_current_page');
      }
    } catch(_) {}
  }

  // Fonction interne qui fait le vrai setup après l'éventuelle animation
  async function doSetup() {
    try {
    document.getElementById('loginScreen').style.display = 'none';
    document.getElementById('app').classList.add('visible');
    document.body.classList.add('app-mode');

    const initiales = ((user.Prenom?.[0]||'') + (user.Nom?.[0]||'')).toUpperCase() || '?';
    const avatarEl = document.getElementById('sidebarAvatar');
    if (user.PhotoUrl) {
      // Afficher la photo de profil Microsoft
      avatarEl.textContent = '';
      avatarEl.style.backgroundImage = `url(${user.PhotoUrl})`;
      avatarEl.style.backgroundSize = 'cover';
      avatarEl.style.backgroundPosition = 'center';
    } else {
      avatarEl.textContent = initiales;
      avatarEl.style.backgroundImage = '';
    }
    document.getElementById('sidebarName').textContent   = `${user.Prenom} ${user.Nom}`;
    // Afficher le poste ou le service sous le nom
    const roleText = user.Poste || user.Service || '';
    document.getElementById('sidebarRole').textContent   = roleText;

    // Afficher le tenant actif si multi-tenant
    if (user._tenant && user._tenant !== 'default') {
      fetch('api/index.php?action=superadmin_status').then(r=>r.json()).then(j=> {
        if (j.success && j.data?.current_tenant?.nom) {
          const el = document.getElementById('sidebarRole');
          if (el) el.textContent = j.data.current_tenant.nom;
        }
      }).catch(()=>{});
    }

    window._currentUser = user;
    await buildSidebarWithVisibility(user);

    // Extensions communautaires : charge les pages et scripts autorisés pour ce
    // rôle. Volontairement non bloquant — une extension en panne ne doit jamais
    // empêcher Larka de démarrer.
    if (typeof LarkaExtensions !== 'undefined') {
      LarkaExtensions.initialiser().catch(e =>
        console.warn('Extensions non chargées :', e));
    }

    // Appliquer les préférences d'interface (position, taille sidebar)
    if (typeof applyUiPrefs === 'function') applyUiPrefs();
    if (typeof initSidebarResize === 'function') initSidebarResize();

    // Le bouton "Changer mon mdp" de la sidebar a été retiré : la fonction est
    // disponible via « Mon profil » (icône en haut à droite). Rien à câbler ici.

    // Précharger les listes pour les filtres avancés
    if (typeof preloadFiltresListes === 'function') preloadFiltresListes();

    // Bouton retour SuperAdmin si l'utilisateur est un super admin
    _injectSuperAdminButton();

    if (user.Role === 'Demandeur') {
      // ⚠️ LE BOUTON « INTERFACE » ÉTAIT MASQUÉ ICI.
      //
      // J'avais ouvert le contenu du panneau aux demandeurs sans voir que le
      // bouton qui l'ouvre leur était retiré au démarrage. Le réglage existait,
      // fonctionnait, et restait hors d'atteinte.
      //
      // Un demandeur a une barre lui aussi, plus courte mais tout aussi
      // personnelle : position, couleurs, ordre de ses onglets, affichage des
      // notes. Rien là-dedans ne lui est « inutile ».
      const prefsBtn = document.getElementById('uiPrefsBtnTopbar');
      if (prefsBtn) prefsBtn.style.display = '';
      // Afficher la cloche pour les demandeurs (notifications push)
      // On la rend visible et on ajoute un bouton push si supporté
      const bellWrap2 = document.getElementById('notifBellWrap');
      if (bellWrap2) {
        bellWrap2.style.display = '';
        _setupDemandeurNotifBell();
      }
      navigate(restoredPage && restoredPage === 'historique' ? 'historique' : 'demandes');
    } else {
      refreshBadgeDemandes();
      App._badgeInterval = setInterval(refreshBadgeDemandes, 30000);
      navigate(restoredPage || 'dashboard');
    }

    // Préchargement des pages les plus utilisées pour le rôle, en arrière-plan
    // (après le navigate initial qui a déjà déclenché le chargement de la page
    // courante). Best-effort, silencieux.
    if (window.PageLoader && typeof window.PageLoader.preloadForRole === 'function') {
      window.PageLoader.preloadForRole(user?.Role);
    }

    if (typeof buildBottomNav === 'function') buildBottomNav();

    // Mises à jour : détection automatique pour l'administrateur du serveur
    // (le serveur vérifie les droits ; installation toujours validée à la main).
    if (typeof LarkaMaj !== 'undefined' && ['Admin', 'Gestionnaire'].includes(App.currentUser?.Role)) {
      setTimeout(() => LarkaMaj.demarrer(), 3000);
    }

    // Initialiser l'assistant IA — uniquement s'il est activé pour ce tenant.
    // On fait d'abord le check de statut léger (api.js, déjà chargé), puis on
    // ne télécharge le module assistant.js (~53 Ko) que si nécessaire. Les
    // tenants sans assistant ne paient donc jamais ce poids.
    if (typeof apiRequest === 'function' && window.PageLoader) {
      apiRequest('assistant_status').then(function (status) {
        if (status && status.actif) {
          window._assistantProvider = status.fournisseur || '';
          window._assistantStatus = status;
          return window.PageLoader.ensure('assistant').then(function () {
            if (typeof initAssistant === 'function') initAssistant();
          });
        }
      }).catch(function () { /* assistant non disponible */ });
    }

    // Afficher le bouton "Un problème ? Une suggestion ?" si activé
    if (typeof showFeedbackButtonIfEnabled === 'function') showFeedbackButtonIfEnabled();

    // Initialiser les notifications push (enregistre le SW, vérifie/propose la souscription)
    if (typeof PushManager_GMAO !== 'undefined') {
      PushManager_GMAO.init().catch(() => {});
    }
    } catch (err) {
      console.error('[initApp] erreur setup:', err);
    } finally {
      // FIX: toujours remettre le flag à false, sinon re-login impossible dans le même onglet
      _initAppRunning = false;
    }
  }

  // Au refresh (session restore) → pas d'animation, affichage instantané
  if (isSessionRestore) {
    doSetup();
  } else {
    animateLogin(doSetup);
  }
}


// ── Utilitaire HTML d'erreur ──────────────────────────────────────────────────
function errorHtml(msg) {
  const escaped = String(msg).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  return `<div class="card" style="color:var(--red);text-align:center;padding:48px">⚠️ ${escaped}</div>`;
}

// ── Boot ──────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  // Nettoyage : vider les caches API résiduels (Safari iOS)
  // Note : on ne supprime plus les Service Workers — le SW push est géré par push.js
  if ('caches' in window) {
    caches.keys().then(names => {
      names.forEach(n => caches.delete(n));
    }).catch(() => {});
  }

  // ── Super Admin : intercepter le boot si ?superadmin ──────────────────────
  // Le module superadmin-auth.js (~34 Ko) n'est plus chargé pour tout le monde :
  // on ne le télécharge qu'ici, à la demande, puis on démarre son flux.
  if (location.search.indexOf('superadmin') !== -1) {
    var saScript = document.createElement('script');
    /**
   * ⚠️ « ?v=11 » ÉTAIT FIGÉ DANS LE CODE.
   *
   * Ce fichier est chargé à la demande, donc il n'apparaît pas dans index.html
   * et le script de bump ne le voyait pas. Sa version n'avait pas bougé depuis
   * longtemps : toute correction du Super Admin restait dans le cache du
   * navigateur, exactement le piège dans lequel nous sommes déjà tombés pour
   * l'ensemble des ressources.
   *
   * On reprend la version d'une ressource d'index.html, que le bump met à jour.
   * Un seul numéro pour tout le monde, et plus rien à penser.
   */
  const versionApp = (document.querySelector('script[src*="?v="]')?.src || '')
    .replace(/^.*\?v=/, '') || Date.now();
  saScript.src = 'js/superadmin-auth.js?v=' + versionApp;
    saScript.onload = function () {
      if (typeof checkSuperAdminSession === 'function') checkSuperAdminSession();
    };
    saScript.onerror = function () {
      console.error('[boot] échec du chargement du module Super Admin');
    };
    document.head.appendChild(saScript);
    return; // Ne pas charger le login normal
  }

  checkSession();
  loadSSOButtons();

  document.getElementById('loginInput').addEventListener('keydown', e => {
    if (e.key === 'Enter') {
      const pwd = document.getElementById('passwordInput');
      if (pwd.value.trim()) doLogin(); else pwd.focus();
    }
  });
  document.getElementById('passwordInput').addEventListener('keydown', e => {
    if (e.key === 'Enter') doLogin();
  });

  setTimeout(() => document.getElementById('loginInput')?.focus(), 100);
});

// ═══════════════════════════════════════════════════════════════════════════════
//  CLOCHE NOTIFICATIONS POUR DEMANDEURS
// ═══════════════════════════════════════════════════════════════════════════════
// Les demandeurs n'ont pas le système de badges (demandes, stocks, contrats)
// mais doivent pouvoir activer/gérer les notifications push.

function _setupDemandeurNotifBell() {
  const notifList = document.getElementById('notifList');
  if (!notifList) return;

  const pushStatus = (typeof PushManager_GMAO !== 'undefined') ? PushManager_GMAO.getStatus() : null;
  const supported = pushStatus?.supported;
  const granted = pushStatus?.permission === 'granted' && pushStatus?.syncedWithServer;

  let pushHtml = '';
  if (!supported) {
    pushHtml = `
      <div style="padding:16px;text-align:center;color:var(--gray-text);font-size:13px">
        Les notifications push ne sont pas supportées sur ce navigateur.
        ${pushStatus?.isIOS && !pushStatus?.isIOSPWA ? '<br><br>📱 <strong>Sur iPhone</strong>, ajoutez l\'app à l\'écran d\'accueil (Partager → Sur l\'écran d\'accueil) pour les activer.' : ''}
      </div>`;
  } else if (granted) {
    pushHtml = `
      <div style="padding:16px;text-align:center">
        <div style="font-size:28px;margin-bottom:8px">🔔</div>
        <div style="font-size:13px;color:var(--green,#16a34a);font-weight:600;margin-bottom:4px">Notifications activées</div>
        <div style="font-size:12px;color:var(--gray-text);margin-bottom:12px">Vous recevrez les notifications sur cet appareil.</div>
        <button onclick="PushManager_GMAO.testNotification()" style="font-size:12px;padding:6px 14px;background:var(--blue);color:white;border:none;border-radius:6px;cursor:pointer;margin-right:6px">📤 Tester</button>
        <button onclick="PushManager_GMAO.disable().then(()=>{_setupDemandeurNotifBell();toast('Notifications désactivées','success')})" style="font-size:12px;padding:6px 14px;background:var(--gray-bg);color:var(--text);border:1px solid var(--gray-border);border-radius:6px;cursor:pointer">Désactiver</button>
      </div>`;
  } else {
    pushHtml = `
      <div style="padding:16px;text-align:center">
        <div style="font-size:28px;margin-bottom:8px">🔕</div>
        <div style="font-size:13px;font-weight:600;margin-bottom:4px">Notifications désactivées</div>
        <div style="font-size:12px;color:var(--gray-text);margin-bottom:12px">Activez-les pour être prévenu(e) quand votre demande est traitée.</div>
        <button onclick="PushManager_GMAO.promptNow();setTimeout(_setupDemandeurNotifBell,2000)" style="font-size:12px;padding:8px 18px;background:linear-gradient(135deg,#0096c7,#48cae4);color:white;border:none;border-radius:8px;cursor:pointer;font-weight:700">
          🔔 Activer les notifications
        </button>
      </div>`;
  }

  notifList.innerHTML = pushHtml;
}

// ══════════════════════════════════════════════════════════════════════════════
// Bouton retour Super Admin (visible uniquement pour les super admins)
// ══════════════════════════════════════════════════════════════════════════════
function _injectSuperAdminButton() {
  // Supprimer un éventuel bouton existant
  const old = document.getElementById('saFloatingBtn');
  if (old) old.remove();

  // Vérifier si l'utilisateur a une session superadmin active
  fetch('api/index.php?action=superadmin_me', { credentials: 'include' })
    .then(r => r.json())
    .then(j => {
      if (!j.success || !j.data) return;

      const btn = document.createElement('div');
      btn.id = 'saFloatingBtn';
      btn.title = 'Panneau Super Admin';
      btn.onclick = () => { window.location.href = '?superadmin'; };
      btn.innerHTML = '🛡️';
      // La bulle assistant occupe aussi le coin bas-droite (bottom:24px, 56px).
      // Si elle est présente, on remonte le bouton super-admin juste au-dessus
      // pour éviter qu'ils se chevauchent ; sinon on reste à 20px.
      const _bubble = document.getElementById('assistantBubble');
      const _bottom = _bubble ? '92px' : '20px';
      btn.style.cssText = `
        position:fixed; bottom:${_bottom}; right:20px; z-index:9999;
        width:44px; height:44px; border-radius:12px;
        background:linear-gradient(135deg,#f59e0b,#d97706);
        color:white; font-size:20px; cursor:pointer;
        display:flex; align-items:center; justify-content:center;
        box-shadow:0 4px 12px rgba(217,119,6,.4);
        transition:transform .15s,box-shadow .15s;
      `;
      btn.onmouseenter = () => { btn.style.transform = 'scale(1.1)'; btn.style.boxShadow = '0 6px 20px rgba(217,119,6,.5)'; };
      btn.onmouseleave = () => { btn.style.transform = ''; btn.style.boxShadow = '0 4px 12px rgba(217,119,6,.4)'; };
      document.body.appendChild(btn);
    })
    .catch(() => {});
}

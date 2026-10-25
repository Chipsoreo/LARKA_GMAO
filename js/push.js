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
 * Larka — Module Push Notifications
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Gère l'ensemble du cycle de vie des notifications push :
 *   - Enregistrement du Service Worker
 *   - Soft-prompt UX (pas de demande brute, timing intelligent)
 *   - Souscription Push (VAPID)
 *   - Envoi de la souscription au backend
 *   - Gestion des états : accepté, refusé, ignoré, non supporté
 *   - Détection iOS PWA et comportement spécifique
 *
 * STRATÉGIE UX :
 *   - On ne demande JAMAIS la permission au premier chargement
 *   - On attend que l'utilisateur ait utilisé l'app (2ème session ou 60s d'usage)
 *   - On affiche d'abord un soft-prompt (bannière Larka) avant le prompt natif
 *   - Si refusé, on ne redemande pas pendant 7 jours
 *   - Si ignoré (soft-prompt fermé), on réessaie à la prochaine session
 *
 * ROBUSTESSE RÉSEAU :
 *   - credentials: 'include' au lieu de 'same-origin' (fix mobile cross-origin)
 *   - Re-sync automatique au retour au premier plan (visibilitychange)
 *   - Retry avec backoff exponentiel si le serveur retourne 401 ou erreur réseau
 *   - Flag pendingSync pour ne pas perdre l'état "en attente d'envoi"
 *   - Détection Android améliorée
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 */

const PushManager_GMAO = (function() {
  'use strict';

  // ── Constantes ──────────────────────────────────────────────────────────────
  const STORAGE_KEY_STATE   = 'gmao_push_state';     // granted|denied|dismissed|pending
  const STORAGE_KEY_ASKED   = 'gmao_push_last_asked'; // timestamp
  const STORAGE_KEY_SESSIONS = 'gmao_push_sessions';  // compteur de sessions
  const STORAGE_KEY_SYNC    = 'gmao_push_last_sync';  // timestamp dernière sync serveur réussie
  const STORAGE_KEY_PENDING = 'gmao_push_pending_sync'; // flag: souscription en attente d'envoi
  const STORAGE_KEY_VAPID   = 'gmao_push_vapid_key';  // clé VAPID utilisée lors de la souscription
  const COOLDOWN_DENIED_MS  = 7 * 24 * 3600 * 1000;  // 7 jours après un refus
  const COOLDOWN_DISMISSED_MS = 24 * 3600 * 1000;     // 24h après un "pas maintenant"
  const MIN_USAGE_SECONDS   = 45;                     // 45s d'utilisation minimum
  const MIN_SESSIONS        = 2;                      // Au moins 2 sessions
  const RETRY_DELAYS        = [5000, 15000, 30000, 60000]; // Retry backoff
  const RESYNC_INTERVAL_MS  = 30 * 60 * 1000;         // Re-sync si > 30 min

  let _swRegistration = null;
  let _vapidPublicKey = null;
  let _initTimer = null;
  let _retryTimer = null;
  let _retryCount = 0;
  let _isIOS = false;
  let _isIOSPWA = false;
  let _isAndroid = false;
  let _visibilityHandler = null;

  // ── Détection plateforme ────────────────────────────────────────────────────
  // Détection robuste
  function detectPlatform() {
    const ua = navigator.userAgent || '';
    // iPad récents se déclarent comme Macintosh — on vérifie aussi la plateforme
    _isIOS = /iPhone|iPad|iPod/i.test(ua) ||
             (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    // navigator.standalone pour les anciennes versions iOS,
    // display-mode: standalone media query pour les nouvelles (iOS 16.4+)
    const isStandaloneDisplay = window.matchMedia?.('(display-mode: standalone)')?.matches;
    _isIOSPWA = _isIOS && (window.navigator.standalone === true || isStandaloneDisplay === true);
    _isAndroid = /Android/i.test(ua);
  }

  // ── Support Push ────────────────────────────────────────────────────────────
  function isPushSupported() {
    if (!('serviceWorker' in navigator)) return false;
    if (!('PushManager' in window)) return false;
    if (!('Notification' in window)) return false;
    // iOS : push uniquement en mode PWA (ajoutée à l'écran d'accueil, iOS 16.4+)
    // Safari mobile ne supporte pas le push en dehors du contexte PWA
    if (_isIOS && !_isIOSPWA) return false;
    return true;
  }

  // ── État persistant ─────────────────────────────────────────────────────────
  function getState()  { return localStorage.getItem(STORAGE_KEY_STATE) || 'pending'; }
  function setState(s) { localStorage.setItem(STORAGE_KEY_STATE, s); }

  function getLastAsked() { return parseInt(localStorage.getItem(STORAGE_KEY_ASKED) || '0', 10); }
  function setLastAsked() { localStorage.setItem(STORAGE_KEY_ASKED, String(Date.now())); }

  function getSessions() { return parseInt(localStorage.getItem(STORAGE_KEY_SESSIONS) || '0', 10); }
  function incSessions() {
    const n = getSessions() + 1;
    localStorage.setItem(STORAGE_KEY_SESSIONS, String(n));
    return n;
  }

  function setPendingSync(val) {
    if (val) {
      localStorage.setItem(STORAGE_KEY_PENDING, '1');
    } else {
      localStorage.removeItem(STORAGE_KEY_PENDING);
    }
  }
  function hasPendingSync() { return localStorage.getItem(STORAGE_KEY_PENDING) === '1'; }

  // ── Conversion VAPID key ────────────────────────────────────────────────────
  function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(base64);
    const arr = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; i++) arr[i] = raw.charCodeAt(i);
    return arr;
  }

  // ── Enregistrement Service Worker ───────────────────────────────────────────
  async function registerServiceWorker() {
    if (!('serviceWorker' in navigator)) return null;
    try {
      const reg = await navigator.serviceWorker.register('/sw.js', { scope: '/' });

      // Forcer la vérification de mise à jour du SW à chaque chargement
      // Sans ça, un SW obsolète peut rester actif et ne jamais traiter les push events
      reg.update().catch(() => {});

      await navigator.serviceWorker.ready;
      _swRegistration = reg;

      // Écouter les messages du SW (navigation push)
      navigator.serviceWorker.addEventListener('message', evt => {
        if (evt.data?.type === 'PUSH_NAVIGATE' && typeof navigate === 'function') {
          navigate(evt.data.page);
        }
      });

      return reg;
    } catch (err) {
      console.warn('[Push] SW registration failed:', err);
      return null;
    }
  }

  // ── Résolution de l'URL API ─────────────────────────────────────────────
  // Construire l'URL API de manière absolue pour garantir le
  // fonctionnement quel que soit le navigateur, l'appareil ou l'URL d'accès.
  // Les URLs relatives comme 'api/index.php' échouent silencieusement si
  // le document est servi depuis un chemin inattendu (PWA, bookmark, etc.)
  function getApiUrl(action) {
    // Utiliser la même base que le document actuel, en supprimant tout
    // après le dernier '/' pour obtenir le répertoire racine
    const base = document.baseURI || window.location.href;
    const origin = new URL(base).origin;
    return origin + '/api/index.php?action=' + action;
  }

  async function fetchVapidKey() {
    if (_vapidPublicKey) return _vapidPublicKey;
    try {
      const res = await fetch(getApiUrl('push_vapid_key'), {
        credentials: 'include',
      });
      if (!res.ok) return null;
      const json = await res.json();
      if (json.success && json.data?.publicKey) {
        _vapidPublicKey = json.data.publicKey;
        return _vapidPublicKey;
      }
    } catch (_) {}
    return null;
  }

  // ── Souscrire au Push ───────────────────────────────────────────────────────
  async function subscribePush() {
    if (!_swRegistration) return null;
    const vapidKey = await fetchVapidKey();
    if (!vapidKey) {
      console.warn('[Push] Pas de clé VAPID configurée côté serveur');
      return null;
    }

    // Diagnostic : afficher la clé et sa conversion
    const keyBytes = urlBase64ToUint8Array(vapidKey);
    console.log('[Push] VAPID public key:', vapidKey);
    console.log('[Push] VAPID key bytes length:', keyBytes.length, '(attendu: 65)');

    async function doSubscribe(reg) {
      // D'abord s'assurer qu'il n'y a aucune souscription résiduelle
      const existingSub = await reg.pushManager.getSubscription();
      if (existingSub) {
        console.log('[Push] Désinscription de la souscription existante…');
        await existingSub.unsubscribe();
        // Attendre un peu après unsubscribe
        await new Promise(r => setTimeout(r, 500));
      }
      return await reg.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: keyBytes,
      });
    }

    // Tentative 1 : subscribe normal
    try {
      const sub = await doSubscribe(_swRegistration);
      console.log('[Push] Souscription réussie ✓', sub.endpoint.slice(0, 60));
      return sub;
    } catch (err) {
      console.warn('[Push] Subscribe failed:', err.name, err.message);
    }

    // Tentative 2 : unregister SW + reregister + subscribe
    console.log('[Push] Tentative reset complet du SW…');
    try {
      await _swRegistration.unregister();
      _swRegistration = null;
      await new Promise(r => setTimeout(r, 1500));
      await registerServiceWorker();
      if (!_swRegistration) {
        console.error('[Push] Réenregistrement du SW échoué');
        return null;
      }
      const sub = await doSubscribe(_swRegistration);
      console.log('[Push] Re-souscription après reset SW réussie ✓');
      return sub;
    } catch (err2) {
      console.error('[Push] Échec après reset SW:', err2.name, err2.message);
    }

    // Tentative 3 : supprimer toutes les registrations SW et réessayer
    console.log('[Push] Tentative nettoyage complet de tous les SW…');
    try {
      const regs = await navigator.serviceWorker.getRegistrations();
      for (const reg of regs) {
        console.log('[Push] Unregister SW scope:', reg.scope);
        await reg.unregister();
      }
      await new Promise(r => setTimeout(r, 2000));
      await registerServiceWorker();
      if (!_swRegistration) {
        console.error('[Push] Réenregistrement final du SW échoué');
        return null;
      }
      const sub = await _swRegistration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: keyBytes,
      });
      console.log('[Push] Souscription après nettoyage complet réussie ✓');
      return sub;
    } catch (err3) {
      console.error('[Push] Échec complet définitif:', err3.name, err3.message);
      console.error('[Push] → L\'utilisateur doit vider le cache du navigateur pour ce site, ou aller dans about:serviceworkers pour tout supprimer.');
      return null;
    }
  }

  // ── Envoyer la souscription au backend ──────────────────────────────────────
  // URL absolue + credentials 'include' + retry sur tout échec
  async function sendSubscriptionToServer(subscription) {
    try {
      const res = await fetch(getApiUrl('push_subscribe'), {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          subscription: subscription.toJSON(),
          userAgent: navigator.userAgent,
          platform: _isIOS ? 'ios' : (_isAndroid ? 'android' : 'desktop'),
        }),
      });
      // Session expirée → marquer comme en attente pour retry
      if (res.status === 401) {
        console.warn('[Push] Session expirée lors de l\'enregistrement — retry programmé.');
        setPendingSync(true);
        scheduleRetry();
        return false;
      }
      const json = await res.json();
      if (json.success) {
        setPendingSync(false);
        _retryCount = 0;
        if (_retryTimer) { clearTimeout(_retryTimer); _retryTimer = null; }
        return true;
      }
      // Retry aussi sur les erreurs serveur (pas seulement 401)
      console.warn('[Push] sendSubscriptionToServer server error:', json.error || 'unknown');
      setPendingSync(true);
      scheduleRetry();
      return false;
    } catch (err) {
      console.warn('[Push] sendSubscriptionToServer network error:', err);
      setPendingSync(true);
      scheduleRetry();
      return false;
    }
  }

  // ── Retry avec backoff exponentiel ──────────────────────────────────────────
  // Mécanisme de retry
  function scheduleRetry() {
    if (_retryTimer) return; // déjà programmé
    if (_retryCount >= RETRY_DELAYS.length) {
      console.warn('[Push] Abandon du retry après', _retryCount, 'tentatives. Sera retentée au prochain visibilitychange ou init.');
      _retryCount = 0;
      return;
    }
    const delay = RETRY_DELAYS[_retryCount];
    console.log('[Push] Retry #' + (_retryCount + 1) + ' dans ' + (delay / 1000) + 's');
    _retryTimer = setTimeout(async () => {
      _retryTimer = null;
      _retryCount++;
      // Ne retenter que si l'utilisateur est connecté
      if ((typeof App !== 'undefined' && App)?.currentUser) {
        await checkExistingSubscription();
      }
    }, delay);
  }

  // ── Supprimer la souscription du backend ────────────────────────────────────
  async function removeSubscriptionFromServer(endpoint) {
    try {
      await fetch(getApiUrl('push_unsubscribe'), {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ endpoint }),
      });
    } catch (_) {}
  }

  // ══════════════════════════════════════════════════════════════════════════════
  //  SOFT-PROMPT UX
  // ══════════════════════════════════════════════════════════════════════════════

  function showSoftPrompt() {
    // Ne pas afficher si déjà présent
    if (document.getElementById('pushSoftPrompt')) return;

    const banner = document.createElement('div');
    banner.id = 'pushSoftPrompt';
    banner.style.cssText = `
      position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%);
      z-index: 10000; max-width: 420px; width: calc(100% - 32px);
      background: var(--navy, #0d2137); color: white;
      border-radius: 16px; padding: 20px 24px;
      box-shadow: 0 12px 40px rgba(0,0,0,.35);
      border: 1px solid rgba(72,202,228,.2);
      animation: pushSlideUp .4s cubic-bezier(.16,1,.3,1);
      font-family: "Segoe UI", system-ui, sans-serif;
    `;
    banner.innerHTML = `
      <style>
        @keyframes pushSlideUp {
          from { transform: translateX(-50%) translateY(100px); opacity: 0; }
          to   { transform: translateX(-50%) translateY(0);     opacity: 1; }
        }
        @keyframes pushSlideDown {
          from { transform: translateX(-50%) translateY(0);     opacity: 1; }
          to   { transform: translateX(-50%) translateY(100px); opacity: 0; }
        }
      </style>
      <div style="display:flex;align-items:flex-start;gap:14px">
        <div style="font-size:28px;line-height:1;flex-shrink:0;margin-top:2px">🔔</div>
        <div style="flex:1">
          <div style="font-weight:700;font-size:14px;margin-bottom:6px">
            Activer les notifications ?
          </div>
          <div style="font-size:12px;color:rgba(255,255,255,.65);line-height:1.5;margin-bottom:14px">
            Recevez une alerte instantanée quand une nouvelle demande arrive ou qu'un contrat arrive à échéance.
            ${_isIOS && !_isIOSPWA ? '<br><span style="color:#48cae4">📱 Sur iPhone, ajoutez d\'abord l\'app à l\'écran d\'accueil (Partager → Sur l\'écran d\'accueil).</span>' : ''}
          </div>
          <div style="display:flex;gap:8px;justify-content:flex-end">
            <button id="pushSoftNo" style="
              background:rgba(255,255,255,.1); color:rgba(255,255,255,.7);
              border:none; border-radius:8px; padding:8px 16px;
              font-size:12px; cursor:pointer; font-weight:500;
              transition: background .15s;
            " onmouseover="this.style.background='rgba(255,255,255,.18)'"
               onmouseout="this.style.background='rgba(255,255,255,.1)'">
              Pas maintenant
            </button>
            <button id="pushSoftYes" style="
              background:linear-gradient(135deg,#0096c7,#48cae4); color:white;
              border:none; border-radius:8px; padding:8px 20px;
              font-size:12px; cursor:pointer; font-weight:700;
              transition: transform .1s, box-shadow .15s;
              box-shadow: 0 2px 12px rgba(72,202,228,.3);
            " onmouseover="this.style.transform='scale(1.03)'"
               onmouseout="this.style.transform='scale(1)'">
              ✓ Activer
            </button>
          </div>
        </div>
      </div>
    `;

    document.body.appendChild(banner);

    // Handlers
    document.getElementById('pushSoftNo').addEventListener('click', () => {
      dismissSoftPrompt('dismissed');
    });
    document.getElementById('pushSoftYes').addEventListener('click', () => {
      dismissSoftPrompt('requesting');
      requestPushPermission();
    });
  }

  function dismissSoftPrompt(reason) {
    const el = document.getElementById('pushSoftPrompt');
    if (!el) return;
    el.style.animation = 'pushSlideDown .3s ease forwards';
    setTimeout(() => el.remove(), 350);

    if (reason === 'dismissed') {
      setState('dismissed');
      setLastAsked();
    }
  }

  // ── Demander la permission native ──────────────────────────────────────────
  async function requestPushPermission() {
    if (!isPushSupported()) return;

    try {
      const result = await Notification.requestPermission();

      if (result === 'granted') {
        setState('granted');
        setLastAsked();
        const sub = await subscribePush();
        if (sub) {
          const ok = await sendSubscriptionToServer(sub);
          if (ok) {
            localStorage.setItem(STORAGE_KEY_SYNC, String(Date.now()));
            if (_vapidPublicKey) localStorage.setItem(STORAGE_KEY_VAPID, _vapidPublicKey);
            if (typeof toast === 'function') {
              toast('Notifications activées ! 🔔', 'success');
            }
          } else {
            // Souscription dans le navigateur mais pas encore au serveur
            // Le retry ou visibilitychange s'en chargera
            if (_vapidPublicKey) localStorage.setItem(STORAGE_KEY_VAPID, _vapidPublicKey);
            if (typeof toast === 'function') {
              toast('Notifications activées ! Synchronisation serveur en cours…', 'success');
            }
          }
        }
      } else if (result === 'denied') {
        setState('denied');
        setLastAsked();
      } else {
        // 'default' = fermé sans répondre
        setState('dismissed');
        setLastAsked();
      }
    } catch (err) {
      console.warn('[Push] Permission error:', err);
    }
  }

  // ── Vérifier si on doit montrer le prompt ──────────────────────────────────
  function shouldShowPrompt() {
    // Pas supporté → jamais
    if (!isPushSupported()) return false;

    // iOS hors PWA → jamais
    if (_isIOS && !_isIOSPWA) return false;

    const browserPerm = typeof Notification !== 'undefined' ? Notification.permission : 'default';

    // Déjà accordé au niveau navigateur → synchronisation serveur gérée par checkExistingSubscription
    if (browserPerm === 'granted') {
      setState('granted');
      return false;
    }

    // Refusé au niveau navigateur → impossible de redemander
    if (browserPerm === 'denied') {
      setState('denied');
      return false;
    }

    const state = getState();
    const lastAsked = getLastAsked();
    const now = Date.now();
    const sessions = getSessions();

    // Déjà refusé récemment → cooldown
    if (state === 'denied' && (now - lastAsked) < COOLDOWN_DENIED_MS) return false;
    // Dismissed récemment → cooldown plus court
    if (state === 'dismissed' && (now - lastAsked) < COOLDOWN_DISMISSED_MS) return false;

    // Pas assez de sessions → trop tôt
    if (sessions < MIN_SESSIONS) return false;

    return true;
  }

  // ── Vérifier la souscription existante au boot ─────────────────────────────
  async function checkExistingSubscription() {
    if (!_swRegistration) {
      console.warn('[Push] checkExistingSubscription : _swRegistration est null, on réessaie l\'enregistrement du SW.');
      await registerServiceWorker();
      if (!_swRegistration) return; // échec définitif
    }
    if (Notification.permission !== 'granted') return;

    try {
      // Récupérer la clé VAPID actuelle du serveur
      const serverKey = await fetchVapidKey();
      const savedKey = localStorage.getItem(STORAGE_KEY_VAPID);

      // Forcer la re-souscription si :
      //   - on n'a jamais sauvé la clé VAPID localement (première souscription)
      //   - OU si la clé VAPID a changé côté serveur
      // Dans les deux cas, l'ancienne souscription est potentiellement liée à une
      // autre clé VAPID → le push service rejettera avec 403 BadJwtToken.
      const needsResub = serverKey && (!savedKey || savedKey !== serverKey);

      let sub = await _swRegistration.pushManager.getSubscription();

      if (needsResub && sub) {
        console.warn('[Push] Clé VAPID non alignée (saved=' + (savedKey ? savedKey.slice(0,12) + '…' : 'null') + ', server=' + serverKey.slice(0,12) + '…) — re-souscription forcée…');
        try { await sub.unsubscribe(); } catch (_) {}
        sub = null; // forcer re-souscription ci-dessous
      }

      if (sub) {
        // Renvoyer la souscription au serveur (endpoint peut avoir changé ou session fraîche)
        const ok = await sendSubscriptionToServer(sub);
        if (ok) {
          setState('granted');
          localStorage.setItem(STORAGE_KEY_SYNC, String(Date.now()));
          if (serverKey) localStorage.setItem(STORAGE_KEY_VAPID, serverKey);
          console.log('[Push] Souscription synchronisée avec le serveur ✓');
        }
        // Si ok===false → sendSubscriptionToServer a déjà programmé un retry
      } else {
        // Permission granted mais pas de souscription active → re-souscrire avec la bonne clé
        console.log('[Push] Re-souscription avec la clé VAPID actuelle…');
        const newSub = await subscribePush();
        if (newSub) {
          const ok = await sendSubscriptionToServer(newSub);
          if (ok) {
            setState('granted');
            localStorage.setItem(STORAGE_KEY_SYNC, String(Date.now()));
            if (serverKey) localStorage.setItem(STORAGE_KEY_VAPID, serverKey);
            console.log('[Push] Nouvelle souscription créée et synchronisée ✓');
          }
        }
      }
    } catch (err) {
      console.warn('[Push] checkExistingSubscription error:', err);
    }
  }

  // ── Gestion visibilitychange (retour au premier plan — critique pour mobile) ─
  // Mécanisme de re-sync au retour au premier plan
  function onVisibilityChange() {
    if (document.visibilityState !== 'visible') return;
    if (!isPushSupported()) return;
    if (Notification.permission !== 'granted') return;

    // Si l'utilisateur n'est pas connecté, ne rien faire
    if (!(typeof App !== 'undefined' && App)?.currentUser) return;

    const lastSync = parseInt(localStorage.getItem(STORAGE_KEY_SYNC) || '0', 10);
    const elapsed = Date.now() - lastSync;

    // Re-sync si :
    //   - Plus de 30 min depuis la dernière sync réussie
    //   - OU si une sync est en attente (échec précédent)
    if (elapsed > RESYNC_INTERVAL_MS || hasPendingSync()) {
      console.log('[Push] Retour au premier plan — re-sync souscription (dernière sync il y a ' + Math.round(elapsed / 60000) + ' min, pending=' + hasPendingSync() + ')');
      // Reset le compteur de retry pour donner une chance fraîche
      _retryCount = 0;
      checkExistingSubscription().catch(() => {});
    }
  }

  // ══════════════════════════════════════════════════════════════════════════════
  //  API PUBLIQUE
  // ══════════════════════════════════════════════════════════════════════════════

  /**
   * Initialiser le module push après le login.
   * Appelé depuis initApp() dans init.js
   */
  async function init() {
    detectPlatform();

    // Incrémenter le compteur de sessions
    incSessions();

    // Enregistrer le Service Worker
    await registerServiceWorker();

    // Écouter les messages du SW pour navigation push
    if ('serviceWorker' in navigator) {
      // Vérifier si on arrive via un clic notification (URL param)
      const urlParams = new URLSearchParams(window.location.search);
      const pushNav = urlParams.get('push_navigate');
      if (pushNav && typeof navigate === 'function') {
        // Nettoyer l'URL
        const cleanUrl = window.location.pathname;
        history.replaceState(null, '', cleanUrl);
        // Naviguer après un court délai (laisser l'app s'initialiser)
        setTimeout(() => navigate(pushNav), 500);
      }
    }

    // Vérifier la souscription existante
    await checkExistingSubscription();

    // Installer le listener visibilitychange pour re-sync au retour au premier plan
    // (critique pour le mobile qui perd souvent la session en arrière-plan)
    if (!_visibilityHandler) {
      _visibilityHandler = onVisibilityChange;
      document.addEventListener('visibilitychange', _visibilityHandler);
    }

    // Programmer le soft-prompt si nécessaire
    if (shouldShowPrompt()) {
      _initTimer = setTimeout(() => {
        showSoftPrompt();
      }, MIN_USAGE_SECONDS * 1000);
    }
  }

  /**
   * Forcer l'affichage du prompt (depuis les paramètres par exemple)
   */
  async function promptNow() {
    if (!isPushSupported()) {
      if (_isIOS && !_isIOSPWA) {
        if (typeof toast === 'function') {
          toast('Sur iPhone, ajoutez d\'abord l\'app à l\'écran d\'accueil pour recevoir les notifications.', 'error');
        }
        return;
      }
      if (typeof toast === 'function') {
        toast('Les notifications push ne sont pas supportées sur ce navigateur.', 'error');
      }
      return;
    }

    if (Notification.permission === 'denied') {
      if (typeof toast === 'function') {
        toast('Les notifications sont bloquées dans les paramètres de votre navigateur. Réactivez-les manuellement.', 'error');
      }
      return;
    }

    if (Notification.permission === 'granted') {
      // Déjà accordé → s'assurer que la souscription est active
      await checkExistingSubscription();
      const synced = !hasPendingSync() && parseInt(localStorage.getItem(STORAGE_KEY_SYNC) || '0', 10) > 0;
      if (typeof toast === 'function') {
        if (synced) {
          toast('Les notifications sont déjà activées ! 🔔', 'success');
        } else {
          toast('Notifications activées localement, synchronisation serveur en cours…', 'info');
        }
      }
      return;
    }

    await requestPushPermission();
  }

  /**
   * Désactiver les notifications push
   */
  async function disable() {
    if (!_swRegistration) return;
    try {
      const sub = await _swRegistration.pushManager.getSubscription();
      if (sub) {
        await removeSubscriptionFromServer(sub.endpoint);
        await sub.unsubscribe();
      }
      setState('pending');
      setPendingSync(false);
      localStorage.removeItem(STORAGE_KEY_SYNC);
      if (typeof toast === 'function') {
        toast('Notifications désactivées.', 'success');
      }
    } catch (err) {
      console.warn('[Push] Disable error:', err);
    }
  }

  /**
   * Obtenir l'état actuel pour l'UI
   */
  function getStatus() {
    const supported = isPushSupported();
    const browserPerm = typeof Notification !== 'undefined' ? Notification.permission : 'unsupported';
    const lastSync = parseInt(localStorage.getItem(STORAGE_KEY_SYNC) || '0', 10);
    const pendingSync = hasPendingSync();
    return {
      supported,
      permission: browserPerm,            // 'granted' | 'denied' | 'default'
      state: getState(),                  // 'granted' | 'denied' | 'dismissed' | 'pending'
      isIOS: _isIOS,
      isIOSPWA: _isIOSPWA,
      isAndroid: _isAndroid,
      isMobile: _isIOS || _isAndroid,
      sessions: getSessions(),
      // Indique si la souscription a bien été envoyée au serveur sur CET appareil
      syncedWithServer: browserPerm === 'granted' && lastSync > 0 && !pendingSync,
      pendingSync,
      lastSyncAt: lastSync ? new Date(lastSync).toLocaleString() : null,
    };
  }

  /**
   * Envoyer une notification de test
   */
  async function testNotification() {
    try {
      const res = await fetch(getApiUrl('push_test'), {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({}),
      });
      const json = await res.json();
      if (json.success) {
        if (typeof toast === 'function') toast('Notification de test envoyée !', 'success');
      } else {
        if (typeof toast === 'function') toast(json.error || 'Erreur d\'envoi', 'error');
      }
    } catch (err) {
      if (typeof toast === 'function') toast('Erreur réseau', 'error');
    }
  }

  /**
   * Nettoyage (appelé au logout)
   */
  function cleanup() {
    if (_initTimer) clearTimeout(_initTimer);
    if (_retryTimer) { clearTimeout(_retryTimer); _retryTimer = null; }
    _retryCount = 0;
    const el = document.getElementById('pushSoftPrompt');
    if (el) el.remove();
    // Réinitialiser l'état de sync (important si un autre utilisateur se connecte sur cet appareil)
    localStorage.removeItem(STORAGE_KEY_SYNC);
    setPendingSync(false);
    // Ne PAS retirer le visibilityHandler : il sera réutilisé au prochain init
    // et vérifie window.App.currentUser avant d'agir
  }

  // ── Export public ───────────────────────────────────────────────────────────
  return {
    init,
    promptNow,
    disable,
    getStatus,
    testNotification,
    cleanup,
  };

})();

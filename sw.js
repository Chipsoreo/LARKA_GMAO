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
 * Larka — Service Worker (Push Notifications)
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Robustesse cross-platform :
 *   - Gestion robuste du payload (JSON, texte, vide)
 *   - Fallback notification si le payload est corrompu
 *   - Gestion du clic notification sur tous les navigateurs
 *   - waitUntil correct pour éviter que le SW soit tué avant la fin
 *   - Compatibilité iOS PWA (16.4+), Android, Desktop
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 */

const SW_VERSION = '2.0.0';

// ── Événement push reçu ──────────────────────────────────────────────────────
self.addEventListener('push', event => {
  console.log('[SW] Push event reçu !');

  // Parser le payload de manière ultra-défensive
  // Sur certains navigateurs/OS le payload peut arriver vide, corrompu, ou en texte
  let data = { title: 'Larka', body: 'Nouvelle notification', tag: 'gmao-default' };

  if (event.data) {
    try {
      const text = event.data.text();
      if (text) {
        try {
          const json = JSON.parse(text);
          if (json && typeof json === 'object') {
            data = Object.assign(data, json);
          }
        } catch (jsonErr) {
          console.warn('[SW] Payload non-JSON, utilisation comme texte brut');
          data.body = text;
        }
      }
    } catch (readErr) {
      console.warn('[SW] Impossible de lire le payload push:', readErr);
      data.body = 'Nouvelle activité dans Larka';
    }
  } else {
    console.warn('[SW] Push event sans données (event.data est null)');
  }

  const options = {
    body:    data.body || '',
    icon:    data.icon || '/icon.png',
    badge:   data.badge || '/icon.png',
    tag:     data.tag || 'gmao-' + Date.now(),
    data:    data.data || {},
    vibrate: data.vibrate || [200, 100, 200],
    renotify: !!data.renotify,
    requireInteraction: !!data.requireInteraction,
    actions: data.actions || [],
    silent:  !!data.silent,
    timestamp: data.timestamp || Date.now(),
  };

  // Ne pas ajouter 'image' si elle est vide/undefined
  if (data.image) {
    options.image = data.image;
  }

  event.waitUntil(
    self.registration.showNotification(data.title, options)
      .catch(err => {
        console.error('[SW] showNotification failed, trying fallback:', err);
        return self.registration.showNotification('Larka', {
          body: 'Nouvelle notification',
          icon: '/icon.png',
          tag: 'gmao-fallback-' + Date.now(),
        });
      })
  );
});

// ── Clic sur notification ────────────────────────────────────────────────────
self.addEventListener('notificationclick', event => {
  event.notification.close();

  const notifData = event.notification.data || {};
  const targetPage = notifData.page || '';
  const actionClicked = event.action;
  const navTarget = actionClicked || targetPage;

  const scope = self.registration.scope;

  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then(windowClients => {
      for (const client of windowClients) {
        try {
          const clientOrigin = new URL(client.url).origin;
          const scopeOrigin = new URL(scope).origin;
          if (clientOrigin === scopeOrigin && 'focus' in client) {
            // Si une page cible est définie, naviguer vers elle
            if (navTarget) {
              client.postMessage({
                type: 'PUSH_NAVIGATE',
                page: navTarget,
              });
            }
            return client.focus();
          }
        } catch (_) {}
      }
      // Pas de fenêtre ouverte → en ouvrir une
      const targetUrl = navTarget
        ? scope + '?push_navigate=' + encodeURIComponent(navTarget)
        : scope;
      return clients.openWindow(targetUrl);
    }).catch(err => {
      console.error('[SW] notificationclick error:', err);
      return clients.openWindow(scope);
    })
  );
});

// ── Fermeture de notification ────────────────────────────────────────────────
self.addEventListener('notificationclose', event => {});

// ── Installation ─────────────────────────────────────────────────────────────
self.addEventListener('install', event => {
  console.log('[SW] Install v' + SW_VERSION);
  self.skipWaiting();
});

// ── Activation ───────────────────────────────────────────────────────────────
self.addEventListener('activate', event => {
  console.log('[SW] Activate v' + SW_VERSION);
  event.waitUntil(clients.claim());
});

// ── Messages depuis le client ────────────────────────────────────────────────
self.addEventListener('message', event => {
  if (event.data && event.data.type === 'SW_VERSION') {
    event.ports[0]?.postMessage({ version: SW_VERSION });
  }
});

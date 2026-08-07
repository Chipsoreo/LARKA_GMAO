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
 * Larka — Page : Super Administration (multi-tenant)
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Panneau d'administration des tenants (instances Larka).
 *
 * FONCTIONNALITÉS :
 *   - Création/suppression de tenants
 *   - Provisioning de bases de données
 *   - Gestion des comptes utilisateurs par tenant
 *   - Monitoring et synchronisation
 *
 * ACCÈS : via ?superadmin dans l'URL, avec mot de passe dédié
 * POINT D'ENTRÉE : renderSuperadmin()
 * ═══════════════════════════════════════════════════════════════════════════════
 */

// ── Données locales ───────────────────────────────────────────────────────────
let _saTenantsCache = null;

async function renderSuperAdmin() {
  const content = document.getElementById('mainContent');
  content.innerHTML = `
    <div style="margin-bottom:20px;display:flex;align-items:center;gap:16px;flex-wrap:wrap">
      <h2 style="margin:0;font-size:20px;color:var(--on-content)">🏢 Gestion Multi-Tenant</h2>
      <div style="flex:1"></div>
      <button class="btn btn-primary" onclick="saShowAddTenant()" style="display:flex;align-items:center;gap:6px">
        <span style="font-size:16px">+</span> Nouveau tenant
      </button>
      <button class="btn" onclick="saRefresh(true)" style="display:flex;align-items:center;gap:6px" title="Rafraîchir en re-testant chaque base (lent)">
        🔄 Rafraîchir
      </button>
      <button class="btn" onclick="saShowSettings()" style="display:flex;align-items:center;gap:6px">
        ⚙️ Paramètres
      </button>
    </div>
    <div id="saTenantsGrid" style="display:grid;gap:16px">
      ${_saSkeletonHtml()}
    </div>`;

  await saRefresh();
}

// ── Onglet dédié : Personnalisation de l'écran de connexion ───────────────────
async function renderSuperAdminBranding() {
  // État actif dans la barre latérale + titre
  document.querySelectorAll('#sidebarNav .nav-item').forEach(n => n.classList.remove('active'));
  const navIt = document.querySelector('#sidebarNav .nav-item[data-page="superadmin_branding"]');
  if (navIt) navIt.classList.add('active');
  const pt = document.getElementById('pageTitle'); if (pt) pt.textContent = 'Personnalisation';
  if (typeof App !== 'undefined') App.currentPage = 'superadmin_branding';

  const content = document.getElementById('mainContent');
  content.innerHTML = `
    <div style="margin-bottom:20px;display:flex;align-items:center;gap:16px;flex-wrap:wrap">
      <h2 style="margin:0;font-size:20px;color:var(--on-content)">🎨 Personnalisation de la connexion</h2>
    </div>
    <div class="card" style="max-width:760px;display:flex;flex-direction:column;gap:16px;padding:22px">

      <div class="form-grid" style="grid-template-columns:1fr 1fr">
        <div class="form-group">
          <label class="form-label">Titre</label>
          <input class="form-control" type="text" id="saBrandTitle" placeholder="Larka" oninput="saBrandingPreview()">
        </div>
        <div class="form-group">
          <label class="form-label">Sous-titre</label>
          <input class="form-control" type="text" id="saBrandSubtitle" placeholder="GMAO" oninput="saBrandingPreview()">
        </div>
      </div>

      <div class="form-group">
        <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer">
          <input type="checkbox" id="saBrandKofi" onchange="saBrandingPreview()" checked>
          Afficher le lien « Soutenir le développement » (Ko-fi) sur l'écran de connexion
        </label>
      </div>

      <div class="form-group">
        <label class="form-label">Logo</label>
        <select class="form-control" id="saBrandMode" onchange="saBrandingUpdateMode()">
          <option value="default">Sans logo (texte seul)</option>
          <option value="image">Image téléversée</option>
        </select>
      </div>
      <div id="saBrandImageBlock" class="form-group" style="display:none">
        <input type="file" id="saBrandImageInput" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml" onchange="saBrandingPickImage(this)">
        <div style="font-size:11px;color:var(--gray-text);margin-top:4px">PNG, JPEG, GIF, WEBP ou SVG &mdash; &asymp; 500 Ko max.</div>
        <div id="saBrandImagePreview" style="margin-top:10px"></div>
        <input type="hidden" id="saBrandImageData">
        <button type="button" class="btn btn-ghost btn-sm" onclick="saBrandingClearImage()" style="margin-top:6px;font-size:12px">Retirer l'image</button>
      </div>

      <div class="form-group">
        <label class="form-label">Apparence</label>
        <select class="form-control" id="saBrandCustomMode" onchange="saBrandingModeToggle()">
          <option value="classic">Classique &mdash; couleurs &amp; fond</option>
          <option value="css">CSS personnalisé (prioritaire)</option>
        </select>
        <div style="font-size:11px;color:var(--gray-text);margin-top:4px">
          « Classique » applique les couleurs ci-dessous. « CSS personnalisé » charge votre CSS en priorité (les couleurs sont ignorées).
        </div>
      </div>

      <div id="saBrandClassicBlock">
        <div id="saBrandThemeBlock" class="form-group">
          <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer">
            <input type="checkbox" id="saBrandThemeCustom" onchange="saBrandingThemeToggle()">
            Personnaliser les couleurs (sinon&nbsp;: thème par défaut)
          </label>
          <div id="saBrandThemeColors" style="display:none;margin-top:12px">
            <label class="form-label" style="font-size:11px">Accent (bouton, focus)</label>
            <div id="saWheelAccent"></div>
            <div class="form-group" style="margin-top:14px;margin-bottom:0">
              <label class="form-label">Fond</label>
              <select class="form-control" id="saBrandBgMode" onchange="saBrandingBgToggle()">
                <option value="animated">Animé (par défaut) &mdash; recoloré selon l'accent</option>
                <option value="gradient">Dégradé de couleurs</option>
              </select>
            </div>
            <div id="saBrandBgColors" style="display:none;margin-top:12px">
              <label class="form-label" style="font-size:11px">Fond &mdash; haut</label>
              <div id="saWheelBg1"></div>
              <label class="form-label" style="font-size:11px;margin-top:12px;display:block">Fond &mdash; bas</label>
              <div id="saWheelBg2"></div>
            </div>
            <button type="button" class="btn btn-ghost btn-sm" onclick="saBrandingThemeReset()" style="margin-top:12px;font-size:12px">↺ Réinitialiser au thème par défaut</button>
          </div>
        </div>
      </div>

      <div id="saBrandCssBlock" style="display:none">
        <div class="form-group">
          <label class="form-label">CSS personnalisé (animations)</label>
          <p style="font-size:11px;color:var(--gray-text);margin:0 0 8px">
            Appliqué à la couche <code>#loginFx</code> (derrière la carte). Utilisez <code>@keyframes</code>, <code>transform</code>, <code>opacity</code>, <code>filter</code>, les dégradés…
            Interdits&nbsp;: <code>url()</code>, <code>@import</code>, <code>@font-face</code>, <code>position:fixed/sticky</code>, <code>pointer-events</code>.
          </p>
          <textarea class="form-control" id="saBrandCss" rows="10" spellcheck="false" oninput="saBrandingPreview()" style="font-family:ui-monospace,monospace;font-size:12px" placeholder="#loginFx::before{content:'';position:absolute;inset:-10%;background:radial-gradient(circle at 50% 35%,rgba(120,200,255,.30),transparent 60%);animation:halo 7s ease-in-out infinite}&#10;@keyframes halo{0%,100%{opacity:.35;transform:scale(1)}50%{opacity:.9;transform:scale(1.12)}}"></textarea>
          <div id="saBrandCssError" style="display:none;font-size:11px;color:#ff8a7a;margin-top:6px"></div>
          <button type="button" class="btn btn-ghost btn-sm" onclick="saBrandingInsertExample()" style="margin-top:8px;font-size:12px">Insérer un exemple</button>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Aperçu en direct</label>
        <div id="saBrandPreview" style="border-radius:14px;overflow:hidden;border:1px solid var(--gray-border)">
          <div id="saPrevBg" style="position:relative;padding:26px 18px;display:flex;justify-content:center;background:linear-gradient(135deg,#0d1f3c,#13264a)">
            <div id="saPrevFx" style="position:absolute;inset:0;pointer-events:none;overflow:hidden"></div>
            <div style="position:relative;width:230px;background:rgba(15,30,51,0.78);border:1px solid rgba(255,255,255,0.12);border-radius:14px;padding:22px 20px;box-shadow:0 12px 30px rgba(0,0,0,0.4)">
              <div id="saPrevLogo" style="text-align:center;margin-bottom:14px"></div>
              <div style="background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.10);border-radius:7px;height:30px;margin-bottom:10px"></div>
              <div style="background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.10);border-radius:7px;height:30px;margin-bottom:14px"></div>
              <div id="saPrevBtn" style="text-align:center;color:#fff;font-size:13px;font-weight:600;padding:10px;border-radius:7px;background:#2b7be6">Se connecter</div>
            </div>
            <div id="saPrevLegal" style="position:absolute;right:10px;bottom:6px;font-size:9px;color:rgba(255,255,255,0.45);max-width:60%;text-align:right;line-height:1.3"></div>
            <div id="saPrevSupport" style="position:absolute;left:10px;bottom:6px;font-size:9px;color:rgba(255,255,255,0.6)">♥ Soutenir le développement</div>
          </div>
        </div>
      </div>
    </div>`;

  let branding = null;
  try {
    const res = await fetch('api/index.php?action=superadmin_branding', { credentials: 'include' });
    const json = await res.json();
    if (json.success) branding = json.data;
  } catch (_) {}
  saBrandingPrefill(branding);

  // Le bouton Enregistrer vit dans #pageActionBar plutôt qu'en tête de page :
  // le formulaire de personnalisation est long, et l'utilisateur qui règle les
  // couleurs tout en bas ne devrait pas avoir à remonter pour enregistrer.
  // navigate() vide cette barre en quittant la page.
  if (typeof setPageActionBar === 'function') {
    setPageActionBar('<button class="btn btn-primary" onclick="saBrandingSave()">💾 Enregistrer</button>');
  }
}

async function saBrandingSave() {
  const customMode = document.getElementById('saBrandCustomMode')?.value || 'classic';
  const useCustomTheme = document.getElementById('saBrandThemeCustom')?.checked;
  const bgMode = document.getElementById('saBrandBgMode')?.value || 'animated';
  const branding = {
    mode:         document.getElementById('saBrandMode')?.value || 'default',
    title:        document.getElementById('saBrandTitle')?.value?.trim() || '',
    subtitle:     document.getElementById('saBrandSubtitle')?.value?.trim() || '',
    logo_image:   document.getElementById('saBrandImageData')?.value || '',
    custom_mode:  customMode,
    // Couleurs/fond : envoyés seulement en mode « classique » avec perso activée.
    theme_accent: (customMode === 'classic' && useCustomTheme) ? (document.getElementById('saBrandAccent')?.value || '') : '',
    bg_mode:      (customMode === 'classic' && useCustomTheme) ? bgMode : 'animated',
    theme_bg1:    (customMode === 'classic' && useCustomTheme && bgMode === 'gradient') ? (document.getElementById('saBrandBg1')?.value || '') : '',
    theme_bg2:    (customMode === 'classic' && useCustomTheme && bgMode === 'gradient') ? (document.getElementById('saBrandBg2')?.value || '') : '',
    // CSS perso (validé côté serveur). Mention légale en dur.
    custom_css:   document.getElementById('saBrandCss')?.value || '',
    // Lien de soutien Ko-fi affiché (ou non) sur l'écran de connexion.
    kofi_enabled: !!document.getElementById('saBrandKofi')?.checked,
  };
  try {
    const res = await fetch('api/index.php?action=superadmin_branding_save', {
      method: 'POST', credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(branding),
    });
    const j = await res.json();
    if (!j.success) throw new Error(j.error || 'Erreur');
    toast('Apparence de la connexion enregistrée.');
  } catch (e) { toast(e.message, 'error'); }
}

/**
 * Skeleton minimal pour donner du feedback visuel pendant le chargement initial,
 * qui peut prendre 1-3s quand on a plusieurs tenants à tester (cache de 60s
 * derrière, mais le premier appel reste lent).
 */
function _saSkeletonHtml() {
  const row = `<div style="background:var(--white);border:1px solid var(--gray-border);border-radius:12px;padding:18px;display:flex;flex-direction:column;gap:10px">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px">
      <div style="display:flex;align-items:center;gap:10px;flex:1">
        <div style="width:42px;height:42px;border-radius:10px;background:var(--gray-bg);animation:_saPulse 1.4s infinite"></div>
        <div style="flex:1">
          <div style="height:14px;background:var(--gray-bg);border-radius:4px;width:60%;margin-bottom:6px;animation:_saPulse 1.4s infinite"></div>
          <div style="height:11px;background:var(--gray-bg);border-radius:4px;width:40%;animation:_saPulse 1.4s infinite"></div>
        </div>
      </div>
      <div style="height:30px;width:100px;background:var(--gray-bg);border-radius:6px;animation:_saPulse 1.4s infinite"></div>
    </div>
  </div>`;
  return '<style>@keyframes _saPulse { 0%,100% { opacity: 1 } 50% { opacity: .5 } }</style>'
       + row + row + row;
}

async function saRefresh(fresh = false) {
  // Si rafraîchissement forcé, on affiche aussi le skeleton (peut prendre du temps)
  if (fresh) {
    const grid = document.getElementById('saTenantsGrid');
    if (grid) grid.innerHTML = _saSkeletonHtml();
  }
  // ⚠️ FIX "CHARGE À L'INFINI" : on borne la requête dans le temps. Si une base
  // distante (PostgreSQL/MySQL) est injoignable, le backend peut être lent ;
  // sans ce garde-fou, le squelette resterait affiché indéfiniment. Le backend
  // borne déjà chaque connexion (connect_timeout), ce timeout front est une
  // sécurité supplémentaire (réseau coupé, PHP qui ne répond pas, etc.).
  const ctrl = new AbortController();
  const timer = setTimeout(() => ctrl.abort(), 25000);
  try {
    const url = 'api/index.php?action=superadmin_tenants' + (fresh ? '&fresh=1' : '');
    const res = await fetch(url, { credentials: 'include', signal: ctrl.signal });
    clearTimeout(timer);
    const json = await res.json();
    if (!json.success) throw new Error(json.error);
    _saTenantsCache = json.data;
    saRenderGrid(json.data);
  } catch(e) {
    clearTimeout(timer);
    // ⚠️ FIX SÉCURITÉ : textContent au lieu d'interpolation HTML.
    const grid = document.getElementById('saTenantsGrid');
    if (grid) {
      const aborted = (e && (e.name === 'AbortError' || e.code === 20));
      const msg = aborted
        ? "Le chargement des tenants a expiré (une base de données est peut-être injoignable). Réessayez."
        : (e.message || 'Erreur');
      grid.innerHTML = '<div style="padding:24px;text-align:center;color:var(--red)">'
        + '<div data-sa-err style="margin-bottom:12px"></div>'
        + '<button class="btn btn-sm" onclick="saRefresh(true)">🔄 Réessayer</button>'
        + '</div>';
      const errEl = grid.querySelector('[data-sa-err]');
      if (errEl) errEl.textContent = msg;
    }
  }
}

function saRenderGrid(tenants) {
  const grid = document.getElementById('saTenantsGrid');
  if (!tenants || Object.keys(tenants).length === 0) {
    grid.innerHTML = `<div style="padding:40px;text-align:center;color:var(--on-content-dim)">Aucun tenant configuré.</div>`;
    return;
  }

  grid.innerHTML = Object.values(tenants).map(t => {
    const statusIcon = t.db_status === 'ok' ? '🟢' : t.db_status === 'missing' ? '🟡' : '🔴';
    const statusLabel = t.db_status === 'ok' ? 'Connecté' : t.db_status === 'missing' ? 'DB absente' : 'Erreur';
    const size = t.db_size ? (t.db_size / 1024 / 1024).toFixed(2) + ' Mo' : '—';
    const users = t.user_count !== null ? t.user_count : '—';
    const color = t.couleur || '#3b82f6';
    const actifBadge = t.actif
      ? '<span style="background:var(--chip-ok-bg);color:var(--chip-ok-fg);padding:2px 8px;border-radius:6px;font-size:11px;font-weight:600">Actif</span>'
      : '<span style="background:var(--chip-danger-bg);color:var(--chip-danger-fg);padding:2px 8px;border-radius:6px;font-size:11px;font-weight:600">Inactif</span>';

    return `<div style="background:var(--card-bg);border:1px solid var(--gray-border);border-radius:12px;padding:20px;border-left:4px solid ${color}">
      <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px">
        <div style="width:40px;height:40px;border-radius:10px;background:${color}22;display:flex;align-items:center;justify-content:center;font-size:18px">🏢</div>
        <div style="flex:1">
          <div style="font-size:15px;font-weight:700;color:var(--navy)">${_escSa(t.nom)} ${actifBadge}</div>
          <div style="font-size:12px;color:var(--gray-text);margin-top:2px">Clé : <code style="background:var(--gray-bg);padding:1px 6px;border-radius:4px">${_escSa(t.key)}</code></div>
        </div>
        <div style="display:flex;gap:6px">
          ${t.actif ? `<button class="btn btn-primary btn-sm" onclick="saEnterGmao('${_escSa(t.key)}')" title="Accéder à Larka de ce tenant" style="padding:6px 12px;font-size:12px">🚀 Accéder</button>` : `<button class="btn btn-sm" disabled title="Tenant inactif" style="padding:6px 12px;font-size:12px;opacity:.4;cursor:not-allowed">🚀 Inactif</button>`}
          <button class="btn btn-sm" onclick="saShowEditTenant('${_escSa(t.key)}')" title="Modifier" style="padding:6px 10px">✏️</button>
          <button class="btn btn-sm" onclick="saShowTenantModules('${_escSa(t.key)}')" title="Activer/désactiver les modules" style="padding:6px 10px">🧩</button>
          <button class="btn btn-sm" onclick="saShowTenantUsers('${_escSa(t.key)}')" title="Gérer les comptes" style="padding:6px 10px">👥</button>
          <button class="btn btn-sm" onclick="saPurgeTenantDb('${_escSa(t.key)}')" title="Vider la base (garde le tenant)" style="padding:6px 10px;color:var(--orange)">🧹</button>
          ${t.key !== 'default' ? `<button class="btn btn-sm" onclick="saDeleteTenant('${_escSa(t.key)}')" title="Supprimer le tenant" style="padding:6px 10px;color:var(--red)">🗑️</button>` : ''}
        </div>
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;font-size:12px">
        <div style="background:var(--gray-bg);padding:8px 12px;border-radius:8px">
          <div style="color:var(--gray-text);margin-bottom:2px">Status DB</div>
          <div style="font-weight:600">${statusIcon} ${statusLabel}</div>
        </div>
        <div style="background:var(--gray-bg);padding:8px 12px;border-radius:8px">
          <div style="color:var(--gray-text);margin-bottom:2px">Driver</div>
          <div style="font-weight:600">${(t.base_de_donnees?.driver || 'sqlite').toUpperCase()}</div>
        </div>
        <div style="background:var(--gray-bg);padding:8px 12px;border-radius:8px">
          <div style="color:var(--gray-text);margin-bottom:2px">Taille</div>
          <div style="font-weight:600">${size}</div>
        </div>
        <div style="background:var(--gray-bg);padding:8px 12px;border-radius:8px">
          <div style="color:var(--gray-text);margin-bottom:2px">Utilisateurs</div>
          <div style="font-weight:600">${users}</div>
        </div>
      </div>
      <div style="margin-top:12px;font-size:12px">
        <div style="color:var(--gray-text);margin-bottom:4px">Domaines web :</div>
        <div style="display:flex;flex-wrap:wrap;gap:4px">
          ${(t.domaines_web || []).map(d => `<code style="background:var(--blue-pale);color:var(--blue);padding:2px 8px;border-radius:4px;font-size:11px">${_escSa(d)}</code>`).join('')}
          ${(t.domaines_web || []).length === 0 ? '<span style="color:var(--gray-text);font-style:italic">aucun</span>' : ''}
        </div>
      </div>
      <div style="margin-top:8px;font-size:12px">
        <div style="color:var(--gray-text);margin-bottom:4px">Domaines email :</div>
        <div style="display:flex;flex-wrap:wrap;gap:4px">
          ${(t.domaines_email || []).map(d => `<code style="background:var(--urg-resto-bg);color:var(--urg-resto-fg);padding:2px 8px;border-radius:4px;font-size:11px">${_escSa(d)}</code>`).join('')}
          ${(t.domaines_email || []).length === 0 ? '<span style="color:var(--gray-text);font-style:italic">aucun</span>' : ''}
        </div>
      </div>
      ${(t.emails_exceptions || []).length ? `
      <div style="margin-top:8px;font-size:12px">
        <div style="color:var(--gray-text);margin-bottom:4px">Exceptions email :</div>
        <div style="display:flex;flex-wrap:wrap;gap:4px">
          ${(t.emails_exceptions || []).map(d => `<code style="background:var(--chip-ok-bg);color:var(--chip-ok-fg);padding:2px 8px;border-radius:4px;font-size:11px">${_escSa(d)}</code>`).join('')}
        </div>
      </div>` : ''}
    </div>`;
  }).join('');
}

function _escSa(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g, '&#039;'); }

// ── Formulaire ajout / édition ────────────────────────────────────────────────
function saShowAddTenant() {
  _saShowTenantForm(null);
}

function saShowEditTenant(key) {
  const t = _saTenantsCache?.[key];
  if (!t) return;
  _saShowTenantForm(t);
}

function _saShowTenantForm(existing) {
  const isEdit = !!existing;
  const t = existing || {};
  const db = t.base_de_donnees || {};

  openModal(isEdit ? '✏️ Modifier le tenant' : '🏢 Nouveau tenant', `
    <div class="form-grid" style="grid-template-columns:1fr 1fr">
      <div class="form-group">
        <label class="form-label">Clé unique *</label>
        <input class="form-control" id="sa_key" value="${_escSa(t.key||'')}" ${isEdit?'readonly style="background:var(--gray-bg);cursor:not-allowed"':''} placeholder="ex: mairie-toulouse">
        ${!isEdit ? '<small style="color:var(--gray-text)">Minuscules, chiffres, tirets uniquement</small>' : ''}
      </div>
      <div class="form-group">
        <label class="form-label">Nom affiché *</label>
        <input class="form-control" id="sa_nom" value="${_escSa(t.nom||'')}" placeholder="ex: Mairie de Toulouse">
      </div>
      <div class="form-group">
        <label class="form-label">Couleur</label>
        <input type="color" id="sa_couleur" value="${t.couleur||'#3b82f6'}" style="width:60px;height:36px;border:none;cursor:pointer">
      </div>
      <div class="form-group">
        <label class="form-label">Actif</label>
        <select class="form-control" id="sa_actif">
          <option value="1" ${t.actif!==false?'selected':''}>Oui</option>
          <option value="0" ${t.actif===false?'selected':''}>Non</option>
        </select>
      </div>
      <div class="form-group" style="grid-column:1/-1">
        <label class="form-label">Domaines web (un par ligne) *</label>
        <textarea class="form-control" id="sa_domaines_web" rows="2" placeholder="gmao.entreprise.fr&#10;gmao-backup.entreprise.fr">${(t.domaines_web||[]).join('\n')}</textarea>
        <small style="color:var(--gray-text)">Les noms de domaine qui dirigeront vers cette base de données</small>
      </div>
      <div class="form-group" style="grid-column:1/-1">
        <label class="form-label">Domaines email autorisés (un par ligne)</label>
        <textarea class="form-control" id="sa_domaines_email" rows="2" placeholder="entreprise.fr&#10;sous-traitant.com">${(t.domaines_email||[]).join('\n')}</textarea>
        <small style="color:var(--gray-text)">Domaines email pouvant s'inscrire via OAuth. <code>*</code> = tous</small>
      </div>
      <div class="form-group" style="grid-column:1/-1">
        <label class="form-label">Exceptions email (une adresse par ligne)</label>
        <textarea class="form-control" id="sa_emails_exceptions" rows="2" placeholder="prestataire@externe.com&#10;stagiaire@autre-boite.fr">${(t.emails_exceptions||[]).join('\n')}</textarea>
        <small style="color:var(--gray-text)">Adresses <b>nominatives</b> autorisées en plus des domaines ci-dessus (intervenants externes…). Adresse complète avec <code>@</code>, prioritaire sur les domaines.</small>
      </div>
    </div>
    <hr style="margin:16px 0;border:none;border-top:1px solid var(--gray-border)">
    <h4 style="margin:0 0 12px;font-size:14px;color:var(--navy)">🗄️ Base de données</h4>
    <div class="form-grid" style="grid-template-columns:1fr 1fr">
      <div class="form-group">
        <label class="form-label">Driver</label>
        <select class="form-control" id="sa_db_driver" onchange="saToggleDbFields()">
          <option value="sqlite" ${(db.driver||'sqlite')==='sqlite'?'selected':''}>SQLite</option>
          <option value="pgsql" ${db.driver==='pgsql'?'selected':''}>PostgreSQL</option>
          <option value="mariadb" ${(db.driver==='mariadb'||db.driver==='mysql')?'selected':''}>MariaDB / MySQL</option>
        </select>
      </div>
      <div class="form-group" id="sa_db_path_wrap">
        <label class="form-label">Chemin fichier SQLite</label>
        <input class="form-control" id="sa_db_path" value="${_escSa(db.path||'')}" placeholder="data/tenant_xxx.db">
      </div>
      <div class="form-group sa-db-server" style="display:none">
        <label class="form-label">Hôte</label>
        <input class="form-control" id="sa_db_host" value="${_escSa(db.host||'127.0.0.1')}" placeholder="127.0.0.1">
      </div>
      <div class="form-group sa-db-server" style="display:none">
        <label class="form-label">Port</label>
        <input class="form-control" type="number" id="sa_db_port" value="${db.port||5432}">
      </div>
      <div class="form-group sa-db-server" style="display:none">
        <label class="form-label">Nom de la base *</label>
        <input class="form-control" id="sa_db_dbname" value="${_escSa(db.dbname||'')}" placeholder="ex : mairie_toulouse">
        <small style="color:var(--gray-text)">À choisir par le superadmin. La base sera créée sur le serveur lors de l'enregistrement.</small>
      </div>
      <div class="form-group sa-db-server" style="display:none">
        <label class="form-label">Utilisateur</label>
        <input class="form-control" id="sa_db_user" value="${_escSa(db.user||'')}">
      </div>
      <div class="form-group sa-db-server" style="display:none">
        <label class="form-label">Mot de passe</label>
        <input class="form-control" type="password" id="sa_db_password" placeholder="${isEdit?'(inchangé si vide)':''}">
      </div>
    </div>
    <div style="margin-top:10px">
      <button class="btn" onclick="saTestDb()" style="font-size:12px">🔌 Tester la connexion</button>
      <span id="saTestResult" style="margin-left:10px;font-size:12px"></span>
    </div>

    <!-- ── Option destructive : supprimer la base existante et la recréer ─────
         Utile typiquement quand une suppression manuelle a échoué (DROP DATABASE
         refusé par PostgreSQL car des connexions étaient encore ouvertes) et
         que l'utilisateur veut vraiment repartir d'une base totalement vide.
         Le flag est passé au backend lors du Test (qui drop alors la base AVANT
         de vérifier l'existence) ET du Save (qui drop avant le provisionnement). -->
    <div style="margin-top:10px;padding:10px 12px;background:var(--chip-warn-bg);border:1px solid var(--chip-warn-bd);border-radius:8px;font-size:12px">
      <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;line-height:1.5">
        <input type="checkbox" id="sa_db_force_drop" style="margin-top:2px">
        <span>
          <strong style="color:var(--chip-warn-fg)">⚠️ Forcer la suppression et recréer</strong><br>
          <span style="color:var(--gray-text)">Si cette case est cochée, la base existante (si elle existe) sera
          <strong>supprimée définitivement</strong> avant d'être recréée à vide. Les connexions
          actives seront tuées au passage. <em>À utiliser uniquement pour repartir d'une base totalement propre.</em></span>
        </span>
      </label>
    </div>

    <!-- ── Sauvegardes par tenant ──────────────────────────────────────────── -->
    <hr style="margin:16px 0;border:none;border-top:1px solid var(--gray-border)">
    <h4 style="margin:0 0 8px;font-size:14px;color:var(--navy)">💾 Sauvegardes</h4>
    <p style="font-size:12px;color:var(--gray-text);margin:0 0 12px">
      Chaque tenant gère sa propre politique de sauvegarde. L'exécution automatique requiert un
      job cron/planificateur externe qui appelle l'API. Les sauvegardes manuelles, l'export et
      l'import peuvent s'utiliser indépendamment.
    </p>
    <div class="form-grid" style="grid-template-columns:1fr 1fr">
      <div class="form-group">
        <label class="form-label" style="display:flex;align-items:center;gap:8px">
          <input type="checkbox" id="sa_bk_actif" ${(t.sauvegarde?.actif)?'checked':''}>
          Sauvegardes automatiques activées
        </label>
        <small style="color:var(--gray-text)">Doit être déclenchée par un cron qui appelle l'API.</small>
      </div>
      <div class="form-group">
        <label class="form-label" style="display:flex;align-items:center;gap:8px">
          <input type="checkbox" id="sa_bk_compress" ${(t.sauvegarde?.compresser!==false)?'checked':''}>
          Compression .gz
        </label>
      </div>
      <div class="form-group">
        <label class="form-label">Intervalle (minutes)</label>
        <input class="form-control" type="number" id="sa_bk_interval" min="1" value="${t.sauvegarde?.intervalle_minutes || 360}">
        <small style="color:var(--gray-text)">360 = toutes les 6 heures.</small>
      </div>
      <div class="form-group">
        <label class="form-label">Sauvegardes à conserver</label>
        <input class="form-control" type="number" id="sa_bk_keep" min="1" value="${t.sauvegarde?.garder || 30}">
        <small style="color:var(--gray-text)">Rotation : au-delà, les plus anciennes sont supprimées.</small>
      </div>
      <div class="form-group" style="grid-column:1/-1">
        <label class="form-label">Dossier de sauvegarde (relatif au projet, ou chemin absolu)</label>
        <input class="form-control" id="sa_bk_dossier" value="${_escSa(t.sauvegarde?.dossier || 'data/backups')}" placeholder="data/backups" style="font-family:monospace">
        <small style="color:var(--gray-text)">Exemples : <code>data/backups</code> · <code>/mnt/nas/gmao</code></small>
      </div>
    </div>
    ${isEdit ? `
    <div style="margin-top:12px;padding:12px;background:var(--gray-bg);border-radius:8px;display:flex;flex-wrap:wrap;gap:8px;align-items:center">
      <button type="button" class="btn" onclick="saBackupNow('${_escSa(t.key)}')" style="font-size:12px">
        💾 Sauvegarde manuelle…
      </button>
      <button type="button" class="btn" onclick="saListBackups('${_escSa(t.key)}')" style="font-size:12px">
        📋 Voir les sauvegardes
      </button>
      <button type="button" class="btn" onclick="saExportDb('${_escSa(t.key)}')" style="font-size:12px">
        ⬇️ Export BDD complète
      </button>
      <button type="button" class="btn" onclick="saImportDb('${_escSa(t.key)}')" style="font-size:12px;color:var(--chip-warn-fg)">
        ⬆️ Import BDD complète
      </button>
      ${t.sauvegarde?.derniere ? `<span style="margin-left:auto;font-size:11px;color:var(--gray-text)">
        Dernière sauvegarde : <strong>${_escSa(t.sauvegarde.derniere)}</strong>
      </span>` : ''}
    </div>
    ` : ''}

    ${!isEdit ? `
    <hr style="margin:16px 0;border:none;border-top:1px solid var(--gray-border)">
    <h4 style="margin:0 0 12px;font-size:14px;color:var(--navy)">👤 Compte gestionnaire</h4>
    <p style="font-size:12px;color:var(--gray-text);margin:0 0 12px">Ce compte Gestionnaire sera créé dans la nouvelle base de données pour pouvoir administrer ce tenant.</p>
    <div class="form-grid" style="grid-template-columns:1fr 1fr">
      <div class="form-group">
        <label class="form-label">Nom *</label>
        <input class="form-control" id="sa_admin_nom" value="" placeholder="Dupont">
      </div>
      <div class="form-group">
        <label class="form-label">Prénom *</label>
        <input class="form-control" id="sa_admin_prenom" value="" placeholder="Jean">
      </div>
      <div class="form-group">
        <label class="form-label">Login / Email *</label>
        <input class="form-control" id="sa_admin_login" value="" placeholder="jean.dupont@entreprise.fr">
        <small style="color:var(--gray-text)">Utilisez l'email pour le routage multi-tenant</small>
      </div>
      <div class="form-group">
        <label class="form-label">Mot de passe *</label>
        <input class="form-control" type="password" id="sa_admin_password" placeholder="Min. 4 caractères">
      </div>
    </div>
    ` : ''}
  `, async () => {
    const payload = {
      key: document.getElementById('sa_key').value.trim(),
      nom: document.getElementById('sa_nom').value.trim(),
      couleur: document.getElementById('sa_couleur').value,
      actif: document.getElementById('sa_actif').value === '1',
      domaines_web: document.getElementById('sa_domaines_web').value.split('\n').map(s=>s.trim()).filter(Boolean),
      domaines_email: document.getElementById('sa_domaines_email').value.split('\n').map(s=>s.trim()).filter(Boolean),
      emails_exceptions: (document.getElementById('sa_emails_exceptions')?.value || '').split('\n').map(s=>s.trim()).filter(Boolean),
      db_driver: document.getElementById('sa_db_driver').value,
      db_path: document.getElementById('sa_db_path').value.trim(),
      db_host: document.getElementById('sa_db_host').value.trim(),
      db_port: parseInt(document.getElementById('sa_db_port').value) || 5432,
      db_dbname: document.getElementById('sa_db_dbname').value.trim(),
      db_user: document.getElementById('sa_db_user').value.trim(),
      db_password: document.getElementById('sa_db_password').value,
      sauvegarde: {
        actif:              !!document.getElementById('sa_bk_actif')?.checked,
        intervalle_minutes: parseInt(document.getElementById('sa_bk_interval')?.value) || 360,
        garder:             parseInt(document.getElementById('sa_bk_keep')?.value) || 30,
        compresser:         !!document.getElementById('sa_bk_compress')?.checked,
        dossier:            (document.getElementById('sa_bk_dossier')?.value || 'data/backups').trim(),
      },
    };

    // Champs admin (nouveau tenant uniquement)
    const adminLoginEl = document.getElementById('sa_admin_login');
    if (adminLoginEl) {
      payload.admin_nom = document.getElementById('sa_admin_nom')?.value?.trim() || '';
      payload.admin_prenom = document.getElementById('sa_admin_prenom')?.value?.trim() || '';
      payload.admin_login = adminLoginEl.value.trim();
      payload.admin_email = adminLoginEl.value.trim();
      payload.admin_password = document.getElementById('sa_admin_password')?.value || '';
    }

    if (!payload.key) { toast('Clé requise.', 'error'); return; }
    if (!payload.nom) { toast('Nom requis.', 'error'); return; }

    // Pour PG/MariaDB : le nom de la base doit être explicitement renseigné
    if (payload.db_driver !== 'sqlite' && !payload.db_dbname) {
      toast('Nom de la base de données requis pour ' + payload.db_driver.toUpperCase() + '.', 'error');
      document.getElementById('sa_db_dbname')?.focus();
      return;
    }

    // Validation admin pour nouveau tenant
    if (adminLoginEl && payload.admin_login && !payload.admin_password) {
      toast('Mot de passe requis pour le compte admin.', 'error'); return;
    }

    // Propager le flag "supprimer la base existante" au backend.
    // Confirmation supplémentaire au save (en plus de celle déjà demandée au Test) :
    // l'utilisateur peut sauvegarder sans avoir cliqué Tester, on doit donc
    // re-confirmer ici si la case est cochée.
    const forceDropEl = document.getElementById('sa_db_force_drop');
    payload.force_drop = !!(forceDropEl && forceDropEl.checked);
    if (payload.force_drop) {
      const dbn = payload.db_dbname || payload.db_path || '(base ciblée)';
      if (!confirm(`⚠️ La case "Forcer la suppression" est cochée.\n\nLa base « ${dbn} » sera SUPPRIMÉE puis recréée vide.\n\nContinuer ?`)) return;
    }

    try {
      const res = await fetch('api/index.php?action=superadmin_tenant_save', {
        method: 'POST', headers: {'Content-Type':'application/json'}, credentials: 'include',
        body: JSON.stringify(payload)
      });
      const json = await res.json();
      if (!json.success) { toast(json.error, 'error'); return; }
      toast(json.data?.message || 'Tenant sauvegardé !', 'success');
      closeModal();
      await saRefresh();
    } catch(e) { toast('Erreur réseau.', 'error'); }
  });

  // Initialiser la visibilité des champs
  setTimeout(saToggleDbFields, 50);
}

function saToggleDbFields() {
  const driver = document.getElementById('sa_db_driver')?.value;
  const isSqlite = driver === 'sqlite';
  const pathWrap = document.getElementById('sa_db_path_wrap');
  if (pathWrap) pathWrap.style.display = isSqlite ? '' : 'none';
  document.querySelectorAll('.sa-db-server').forEach(el => el.style.display = isSqlite ? 'none' : '');

  // Auto-remplir les champs PG/MariaDB à partir de la config super admin
  if (!isSqlite) {
    const hostEl = document.getElementById('sa_db_host');
    const portEl = document.getElementById('sa_db_port');
    const userEl = document.getElementById('sa_db_user');

    // Nom de base : PAS d'auto-remplissage — le superadmin doit choisir explicitement
    // (évite de créer des bases "gmao_xxx" par inadvertance sur le serveur)

    // Remplir host/port/user depuis les valeurs par défaut (config super admin)
    if (hostEl && !hostEl.value) hostEl.value = '127.0.0.1';
    if (portEl && !portEl.value) portEl.value = driver === 'pgsql' ? '5432' : '3306';
    if (userEl && !userEl.value) userEl.value = 'gmao';
  }

  // Auto-remplir le path SQLite si vide
  if (isSqlite) {
    const pathEl = document.getElementById('sa_db_path');
    const keyEl = document.getElementById('sa_key');
    if (pathEl && !pathEl.value && keyEl?.value) {
      pathEl.value = 'data/tenant_' + keyEl.value.replace(/[^a-z0-9]/g, '_') + '.db';
    }
  }
}

async function saTestDb() {
  const resultEl = document.getElementById('saTestResult');
  const forceDropEl = document.getElementById('sa_db_force_drop');
  const forceDrop = !!(forceDropEl && forceDropEl.checked);

  // Si l'utilisateur a coché la case destructive : confirmation explicite
  // avant d'envoyer la requête. Mieux vaut un dialogue de trop qu'une base
  // de production effacée par erreur.
  if (forceDrop) {
    const dbn = document.getElementById('sa_db_dbname')?.value.trim()
             || document.getElementById('sa_db_path')?.value.trim()
             || '(base ciblée)';
    if (!confirm(`⚠️ Confirmer la suppression de la base « ${dbn} » ?\n\nCette action est IRRÉVERSIBLE. Toutes les données seront perdues.`)) {
      forceDropEl.checked = false;  // décocher pour éviter une suppression au save
      return;
    }
  }

  resultEl.textContent = '⏳ Test en cours…';
  resultEl.style.color = 'var(--gray-text)';

  const driver = document.getElementById('sa_db_driver').value;
  const payload = { driver, force_drop: forceDrop };
  if (driver === 'sqlite') {
    payload.path = document.getElementById('sa_db_path').value.trim();
  } else {
    payload.host = document.getElementById('sa_db_host').value.trim();
    payload.port = parseInt(document.getElementById('sa_db_port').value);
    payload.dbname = document.getElementById('sa_db_dbname').value.trim();
    payload.user = document.getElementById('sa_db_user').value.trim();
    payload.password = document.getElementById('sa_db_password').value;
  }

  try {
    const res = await fetch('api/index.php?action=superadmin_test_db', {
      method: 'POST', headers: {'Content-Type':'application/json'}, credentials: 'include',
      body: JSON.stringify(payload)
    });
    const json = await res.json();
    if (json.success && json.data?.status === 'ok') {
      resultEl.textContent = '✅ ' + json.data.message;
      resultEl.style.color = 'var(--chip-ok-fg)';
      // Si on vient de drop, on décoche : pas besoin de re-supprimer au save.
      // L'utilisateur peut toujours re-cocher s'il refait un test après modif.
      if (forceDrop && forceDropEl) forceDropEl.checked = false;
    } else {
      resultEl.textContent = '❌ ' + (json.data?.message || json.error);
      resultEl.style.color = 'var(--red)';
    }
  } catch(e) {
    resultEl.textContent = '❌ Erreur réseau';
    resultEl.style.color = 'var(--red)';
  }
}

async function saSwitchTenant(key) {
  try {
    const res = await fetch('api/index.php?action=superadmin_switch_tenant', {
      method: 'POST', headers: {'Content-Type':'application/json'}, credentials: 'include',
      body: JSON.stringify({ key })
    });
    const json = await res.json();
    if (!json.success) { toast(json.error, 'error'); return; }
    toast('Basculé sur : ' + key, 'success');
    await saRefresh();
  } catch(e) { toast('Erreur.', 'error'); }
}

async function saEnterGmao(key) {
  try {
    const res = await fetch('api/index.php?action=superadmin_switch_tenant', {
      method: 'POST', headers: {'Content-Type':'application/json'}, credentials: 'include',
      body: JSON.stringify({ key })
    });
    const json = await res.json();
    if (!json.success) { toast(json.error, 'error'); return; }
    // Rediriger vers Larka (sans ?superadmin)
    window.location.href = window.location.pathname;
  } catch(e) { toast('Erreur : ' + e.message, 'error'); }
}

// ══════════════════════════════════════════════════════════════════════════════
//  CONFIGURATION SERVEUR
// ══════════════════════════════════════════════════════════════════════════════
// NB : la fonction saShowServerConfig() est définie dans superadmin-auth.js.
// Elle est appelée par le menu sidebar SA "🖥️ Config serveur" et affiche la
// configuration directement dans #mainContent (pas dans une modale).

async function saDeleteTenant(key) {
  const msg = `⚠️ Supprimer le tenant "${key}" ?\n\n`
            + `Cette action est IRRÉVERSIBLE et va :\n`
            + `  • retirer la configuration du tenant\n`
            + `  • détruire sa base de données (fichier SQLite supprimé, ou toutes les tables DROP pour PG/MariaDB)\n`
            + `  • retirer ses comptes locaux du registre super-admin\n\n`
            + `Pensez à faire une sauvegarde avant si besoin.\n\n`
            + `Confirmer la suppression ?`;
  if (!confirm(msg)) return;
  try {
    const res = await fetch('api/index.php?action=superadmin_tenant_delete', {
      method: 'POST', headers: {'Content-Type':'application/json'}, credentials: 'include',
      body: JSON.stringify({ key })
    });
    const json = await res.json();
    if (!json.success) { toast(json.error, 'error'); return; }
    const details = json.data?.bdd?.details || '';
    toast('Tenant supprimé.' + (details ? ' ' + details : ''), 'success');
    await saRefresh();
  } catch(e) { toast('Erreur.', 'error'); }
}

// ══════════════════════════════════════════════════════════════════════════════
//  GESTION DES COMPTES PAR TENANT
// ══════════════════════════════════════════════════════════════════════════════

async function saShowTenantUsers(key) {
  const tenant = _saTenantsCache?.[key];
  const tenantNom = tenant?.nom || key;

  let users = [];
  try {
    const res = await fetch(`api/index.php?action=superadmin_tenant_users&key=${encodeURIComponent(key)}`, { credentials: 'include' });
    const json = await res.json();
    if (!json.success) throw new Error(json.error);
    users = json.data || [];
  } catch(e) {
    toast('Erreur chargement comptes : ' + e.message, 'error');
    return;
  }

  const roleColors = {
    'Gestionnaire': '#16a34a', 'Visionneur': '#8b5cf6',
    'Demandeur': '#f59e0b', 'Technicien': '#06b6d4',
    'Admin': '#16a34a' // rétro-compat : affiche comme Gestionnaire
  };

  const tableRows = users.map(u => {
    const rc = roleColors[u.Role] || '#6b7280';
    const actifBadge = u.Actif
      ? '<span style="color:var(--chip-ok-fg);font-weight:600">✓</span>'
      : '<span style="color:var(--red);font-weight:600">✗</span>';
    const providerIcon = u.Provider === 'google' ? '🔵' : u.Provider === 'microsoft' ? '🟠' : '🔑';
    return `<tr style="border-bottom:1px solid var(--gray-border)">
      <td style="padding:8px 12px">${actifBadge}</td>
      <td style="padding:8px 12px;font-weight:600">${_escSa(u.Prenom)} ${_escSa(u.Nom)}</td>
      <td style="padding:8px 12px;font-size:12px;color:var(--gray-text)">${_escSa(u.Login)}</td>
      <td style="padding:8px 12px"><span style="background:${rc}22;color:${rc};padding:2px 8px;border-radius:6px;font-size:11px;font-weight:600">${_escSa(u.Role)}</span></td>
      <td style="padding:8px 12px;font-size:12px">${providerIcon} ${_escSa(u.Provider||'local')}</td>
      <td style="padding:8px 12px;text-align:right">
        <button class="btn btn-sm" onclick="saEditTenantUser('${_escSa(key)}',${u.Id})" style="padding:4px 8px;font-size:11px">✏️</button>
        <button class="btn btn-sm" onclick="saDeleteTenantUser('${_escSa(key)}',${u.Id},'${_escSa(u.Login)}')" style="padding:4px 8px;font-size:11px;color:var(--red)">🗑️</button>
      </td>
    </tr>`;
  }).join('');

  openModal(`👥 Comptes — ${_escSa(tenantNom)}`, `
    <div style="margin-bottom:12px;display:flex;align-items:center;gap:10px">
      <span style="font-size:13px;color:var(--gray-text)">${users.length} compte(s)</span>
      <div style="flex:1"></div>
      <button class="btn btn-primary" onclick="closeModal();saAddTenantUser('${_escSa(key)}')" style="font-size:12px;padding:6px 14px">+ Nouveau compte</button>
    </div>
    ${users.length === 0 ? '<div style="padding:24px;text-align:center;color:var(--gray-text)">Aucun compte dans cette base.</div>' : `
    <div style="overflow-x:auto">
      <table style="width:100%;border-collapse:collapse;font-size:13px">
        <thead><tr style="border-bottom:2px solid var(--gray-border);text-align:left">
          <th style="padding:8px 12px;width:30px"></th>
          <th style="padding:8px 12px">Nom</th>
          <th style="padding:8px 12px">Login</th>
          <th style="padding:8px 12px">Rôle</th>
          <th style="padding:8px 12px">Provider</th>
          <th style="padding:8px 12px;text-align:right">Actions</th>
        </tr></thead>
        <tbody>${tableRows}</tbody>
      </table>
    </div>`}
  `);
}

function saAddTenantUser(tenantKey) {
  _saShowUserForm(tenantKey, null);
}

async function saEditTenantUser(tenantKey, userId) {
  // Charger les données de l'utilisateur
  try {
    const res = await fetch(`api/index.php?action=superadmin_tenant_users&key=${encodeURIComponent(tenantKey)}`, { credentials: 'include' });
    const json = await res.json();
    if (!json.success) throw new Error(json.error);
    const user = (json.data || []).find(u => u.Id === userId);
    if (!user) { toast('Utilisateur introuvable.', 'error'); return; }
    _saShowUserForm(tenantKey, user);
  } catch(e) { toast('Erreur : ' + e.message, 'error'); }
}

function _saShowUserForm(tenantKey, existing) {
  const isEdit = !!existing;
  const u = existing || {};
  const tenantNom = _saTenantsCache?.[tenantKey]?.nom || tenantKey;

  openModal(isEdit ? `✏️ Modifier — ${_escSa(u.Login)}` : `👤 Nouveau compte — ${_escSa(tenantNom)}`, `
    <div class="form-grid" style="grid-template-columns:1fr 1fr">
      <div class="form-group">
        <label class="form-label">Nom *</label>
        <input class="form-control" id="sau_nom" value="${_escSa(u.Nom||'')}">
      </div>
      <div class="form-group">
        <label class="form-label">Prénom *</label>
        <input class="form-control" id="sau_prenom" value="${_escSa(u.Prenom||'')}">
      </div>
      <div class="form-group">
        <label class="form-label">Login / Email *</label>
        <input class="form-control" id="sau_login" value="${_escSa(u.Login||'')}" ${isEdit?'readonly style="background:var(--gray-bg)"':''} placeholder="prenom.nom@entreprise.fr">
      </div>
      <div class="form-group">
        <label class="form-label">Email</label>
        <input class="form-control" id="sau_email" value="${_escSa(u.Email||'')}" placeholder="Si différent du login">
      </div>
      <div class="form-group">
        <label class="form-label">Rôle *</label>
        <select class="form-control" id="sau_role">
          <option value="Gestionnaire" ${u.Role==='Gestionnaire'||u.Role==='Admin'?'selected':''}>Gestionnaire</option>
          <option value="Visionneur" ${u.Role==='Visionneur'?'selected':''}>Visionneur</option>
          <option value="Demandeur" ${(!u.Role||u.Role==='Demandeur')?'selected':''}>Demandeur</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Actif</label>
        <select class="form-control" id="sau_actif">
          <option value="1" ${u.Actif!==0?'selected':''}>Oui</option>
          <option value="0" ${u.Actif===0?'selected':''}>Non</option>
        </select>
      </div>
      <div class="form-group" style="grid-column:1/-1">
        <label class="form-label">${isEdit ? 'Nouveau mot de passe (vide = inchangé)' : 'Mot de passe *'}</label>
        <input class="form-control" type="password" id="sau_password" placeholder="${isEdit?'Laisser vide pour ne pas changer':'Min. 4 caractères'}">
      </div>
    </div>
  `, async () => {
    const payload = {
      tenant_key: tenantKey,
      id: u.Id || 0,
      nom: document.getElementById('sau_nom').value.trim(),
      prenom: document.getElementById('sau_prenom').value.trim(),
      login: document.getElementById('sau_login').value.trim(),
      email: document.getElementById('sau_email').value.trim(),
      role: document.getElementById('sau_role').value,
      actif: parseInt(document.getElementById('sau_actif').value),
      provider: u.Provider || 'local',
    };
    const pwd = document.getElementById('sau_password').value;
    if (pwd) payload.motDePasse = pwd;

    if (!payload.nom || !payload.prenom) { toast('Nom et prénom requis.', 'error'); return; }
    if (!payload.login) { toast('Login requis.', 'error'); return; }
    if (!isEdit && !pwd) { toast('Mot de passe requis.', 'error'); return; }

    try {
      const res = await fetch('api/index.php?action=superadmin_tenant_user_save', {
        method: 'POST', headers: {'Content-Type':'application/json'}, credentials: 'include',
        body: JSON.stringify(payload)
      });
      const json = await res.json();
      if (!json.success) { toast(json.error, 'error'); return; }
      toast(isEdit ? 'Utilisateur mis à jour !' : 'Utilisateur créé !', 'success');
      closeModal();
      // Rouvrir la liste
      setTimeout(() => saShowTenantUsers(tenantKey), 300);
    } catch(e) { toast('Erreur réseau.', 'error'); }
  });
}

async function saDeleteTenantUser(tenantKey, userId, login) {
  if (!confirm(`Supprimer le compte "${login}" de ce tenant ?`)) return;
  try {
    const res = await fetch('api/index.php?action=superadmin_tenant_user_delete', {
      method: 'POST', headers: {'Content-Type':'application/json'}, credentials: 'include',
      body: JSON.stringify({ tenant_key: tenantKey, user_id: userId })
    });
    const json = await res.json();
    if (!json.success) { toast(json.error, 'error'); return; }
    toast('Compte supprimé.', 'success');
    closeModal();
    setTimeout(() => saShowTenantUsers(tenantKey), 300);
  } catch(e) { toast('Erreur.', 'error'); }
}

// ══════════════════════════════════════════════════════════════════════════════
// PARAMÈTRES SUPER ADMIN (emails Microsoft autorisés)
// ══════════════════════════════════════════════════════════════════════════════

async function saShowSettings() {
  let emails = [];
  let profile = { nom: '', prenom: '', email: '' };
  try {
    const res = await fetch('api/index.php?action=superadmin_emails', { credentials: 'include' });
    const json = await res.json();
    if (json.success) emails = json.data || [];
  } catch(_) {}
  try {
    const res = await fetch('api/index.php?action=superadmin_profile', { credentials: 'include' });
    const json = await res.json();
    if (json.success && json.data) profile = json.data;
  } catch(_) {}
  let branding = null;
  try {
    const res = await fetch('api/index.php?action=superadmin_branding', { credentials: 'include' });
    const json = await res.json();
    if (json.success) branding = json.data;
  } catch(_) {}

  openModal('⚙️ Paramètres Super Admin', `
    <div style="display:flex;flex-direction:column;gap:20px">

      <div>
        <div class="section-label">👤 Mon profil Larka</div>
        <p style="font-size:12px;color:var(--gray-text);margin:0 0 12px">
          Ces informations seront utilisées quand vous basculez sur un tenant (compte Demandeur créé automatiquement).
        </p>
        <div class="form-grid" style="grid-template-columns:1fr 1fr">
          <div class="form-group">
            <label class="form-label">Nom</label>
            <input class="form-control" type="text" id="saProfNom" value="${profile.nom||''}" placeholder="Dupont">
          </div>
          <div class="form-group">
            <label class="form-label">Prénom</label>
            <input class="form-control" type="text" id="saProfPrenom" value="${profile.prenom||''}" placeholder="Jean">
          </div>
          <div class="form-group">
            <label class="form-label">Email</label>
            <input class="form-control" type="email" id="saProfEmail" value="${profile.email||''}" placeholder="jean@entreprise.fr">
          </div>
        </div>
      </div>

      <div style="border-top:1px solid var(--gray-border);padding-top:16px">
        <div class="section-label">🎨 Page de connexion</div>
        <p style="font-size:12px;color:var(--gray-text);margin:0">
          La personnalisation de l'écran de connexion a son propre onglet :
          <strong>« 🎨 Personnalisation »</strong> dans le menu de gauche.
        </p>
      </div>

      <div style="border-top:1px solid var(--gray-border);padding-top:16px">
        <div class="section-label">👥 Comptes Super Admin locaux</div>
        <p style="font-size:12px;color:var(--gray-text);margin:0 0 12px">
          Plusieurs comptes peuvent se connecter au panneau Super Admin avec un login/mot de passe.
        </p>
        <div id="saAccountsList"></div>
        <button id="saAddAccountBtn" class="btn btn-ghost btn-sm" onclick="saAddAccountModal()" style="margin-top:8px;font-size:12px">
          + Ajouter un compte Super Admin
        </button>
        <div id="saAccountsPrimaryNote" style="display:none;margin-top:8px;font-size:12px;color:var(--gray-text);font-style:italic">
          🔒 Seul le super administrateur principal peut ajouter ou supprimer des comptes Super Admin.
        </div>
      </div>

      <div style="border-top:1px solid var(--gray-border);padding-top:16px">
        <div class="section-label">🔑 Connexion Microsoft (Super Admin)</div>
        <p style="font-size:12px;color:var(--gray-text);margin:0 0 12px">
          Les emails ci-dessous sont autorisés à se connecter au panneau Super Admin via Microsoft OAuth.
          L'email principal du super admin est toujours autorisé.
        </p>
        <div id="saEmailsList">
          ${emails.map((e, i) => `
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px" data-sa-email-row>
              <input class="form-control" type="email" value="${e}" style="flex:1;font-size:13px"
                onchange="this.dataset.val=this.value">
              <button class="icon-btn delete" onclick="this.parentElement.remove()" title="Retirer">🗑️</button>
            </div>
          `).join('')}
        </div>
        <button class="btn btn-ghost btn-sm" onclick="saAddEmailRow()" style="margin-top:6px;font-size:12px">
          + Ajouter un email
        </button>
      </div>

      <div style="border-top:1px solid var(--gray-border);padding-top:16px">
        <div class="section-label">🔒 Mot de passe local</div>
        <div class="form-grid">
          <div class="form-group">
            <label class="form-label">Ancien mot de passe</label>
            <input class="form-control" type="password" id="saOldPwd">
          </div>
          <div class="form-group">
            <label class="form-label">Nouveau mot de passe</label>
            <input class="form-control" type="password" id="saNewPwd">
          </div>
        </div>
        <button class="btn btn-sm" onclick="saChangePassword()" style="margin-top:8px">
          Changer le mot de passe
        </button>
      </div>
    </div>
  `, saSaveEmails, 'Enregistrer');
  // Charger la liste des comptes SA + préremplir l'apparence après le rendu
  setTimeout(() => { saLoadAccounts(); saBrandingPrefill(branding); }, 100);
}

// ── Personnalisation de l'écran de connexion ─────────────────────────────────
const DEFAULT_LOGIN_THEME = { accent: '#2b7be6', bg1: '#0d1f3c', bg2: '#13264a' };

// Conversions couleur pour la roue chromatique HSV.
function _c01(x){ return x<0?0:x>1?1:x; }
function saHexToRgb(h){ h=String(h||'').replace('#',''); if(h.length===3)h=h.split('').map(c=>c+c).join(''); const n=parseInt(h,16)||0; return {r:(n>>16)&255,g:(n>>8)&255,b:n&255}; }
function saRgbToHex(r,g,b){ const f=x=>('0'+Math.round(_c01(x/255)*255).toString(16)).slice(-2); return '#'+f(r)+f(g)+f(b); }
function saRgbToHsv(r,g,b){ r/=255;g/=255;b/=255; const mx=Math.max(r,g,b),mn=Math.min(r,g,b),d=mx-mn; let h=0; if(d){ if(mx===r)h=((g-b)/d)%6; else if(mx===g)h=(b-r)/d+2; else h=(r-g)/d+4; h*=60; if(h<0)h+=360; } return {h, s:(mx?d/mx:0), v:mx}; }
function saHsvToRgb(h,s,v){ h=(h%360+360)%360; const c=v*s, x=c*(1-Math.abs((h/60)%2-1)), m=v-c; let r=0,g=0,b=0; if(h<60){r=c;g=x;}else if(h<120){r=x;g=c;}else if(h<180){g=c;b=x;}else if(h<240){g=x;b=c;}else if(h<300){r=x;b=c;}else{r=c;b=x;} return {r:(r+m)*255,g:(g+m)*255,b:(b+m)*255}; }
function saHsvToHex(h,s,v){ const o=saHsvToRgb(h,s,v); return saRgbToHex(o.r,o.g,o.b); }

// Registre des setters de roue : saWheels.accent('#...'), .bg1, .bg2
const saWheels = {};

// Roue chromatique HSV montée dans #mountId, écrivant le hex dans #valueId.
function saMakeColorWheel(mountId, valueId, initial) {
  const mount = document.getElementById(mountId);
  if (!mount) return;
  const SIZE = 148, R = SIZE/2;
  mount.innerHTML =
    '<div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap">' +
      '<canvas width="'+SIZE+'" height="'+SIZE+'" style="border-radius:50%;cursor:crosshair;touch-action:none;box-shadow:0 2px 10px rgba(0,0,0,.15)"></canvas>' +
      '<div style="flex:1;min-width:130px">' +
        '<div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">' +
          '<span class="sw" style="width:28px;height:28px;border-radius:7px;border:1px solid var(--gray-border);flex:none"></span>' +
          '<input type="text" class="hex form-control" maxlength="7" style="font-family:ui-monospace,monospace;font-size:12px;flex:1">' +
        '</div>' +
        '<label style="font-size:11px;color:var(--gray-text)">Luminosité</label>' +
        '<input type="range" class="vsl" min="0" max="100" value="100" style="width:100%">' +
        '<input type="hidden" id="'+valueId+'">' +
      '</div>' +
    '</div>';
  const canvas = mount.querySelector('canvas');
  const ctx = canvas.getContext('2d', { willReadFrequently: true });
  const sw  = mount.querySelector('.sw');
  const hex = mount.querySelector('.hex');
  const vsl = mount.querySelector('.vsl');
  const hid = document.getElementById(valueId);
  let st = saRgbToHsv(...Object.values(saHexToRgb(initial || '#2b7be6')));
  let cache = null;

  function renderCache(){
    const img = ctx.createImageData(SIZE, SIZE), d = img.data;
    for (let y=0; y<SIZE; y++) for (let x=0; x<SIZE; x++){
      const dx=x-R, dy=y-R, r=Math.sqrt(dx*dx+dy*dy), i=(y*SIZE+x)*4;
      if (r>R){ d[i+3]=0; continue; }
      let h=Math.atan2(dy,dx)*180/Math.PI; if(h<0)h+=360;
      const o=saHsvToRgb(h, Math.min(r/R,1), st.v);
      d[i]=o.r; d[i+1]=o.g; d[i+2]=o.b; d[i+3]=255;
    }
    cache = img;
  }
  function paint(){
    if(!cache) renderCache();
    ctx.putImageData(cache,0,0);
    const a=st.h*Math.PI/180, rr=st.s*R;
    const cx=R+Math.cos(a)*rr, cy=R+Math.sin(a)*rr;
    ctx.beginPath(); ctx.arc(cx,cy,6,0,Math.PI*2); ctx.strokeStyle='#fff'; ctx.lineWidth=2; ctx.stroke();
    ctx.beginPath(); ctx.arc(cx,cy,6.8,0,Math.PI*2); ctx.strokeStyle='rgba(0,0,0,.55)'; ctx.lineWidth=1; ctx.stroke();
  }
  function sync(valueChanged){
    if(valueChanged) renderCache();
    const hx = saHsvToHex(st.h, st.s, st.v);
    if(hid) hid.value = hx;
    if(sw) sw.style.background = hx;
    if(hex && document.activeElement !== hex) hex.value = hx;
    paint();
    if(typeof saBrandingPreview === 'function') saBrandingPreview();
  }
  function pick(e){
    const rect = canvas.getBoundingClientRect();
    const px = (e.touches?e.touches[0].clientX:e.clientX)-rect.left;
    const py = (e.touches?e.touches[0].clientY:e.clientY)-rect.top;
    const dx=px-R, dy=py-R, r=Math.sqrt(dx*dx+dy*dy);
    st.s = Math.min(r/R, 1);
    let h=Math.atan2(dy,dx)*180/Math.PI; if(h<0)h+=360; st.h=h;
    sync(false);
  }
  let drag=false;
  canvas.addEventListener('pointerdown', e=>{ drag=true; try{canvas.setPointerCapture(e.pointerId);}catch(_){}{} pick(e); });
  canvas.addEventListener('pointermove', e=>{ if(drag) pick(e); });
  canvas.addEventListener('pointerup',   ()=>{ drag=false; });
  vsl.addEventListener('input', ()=>{ st.v=(+vsl.value)/100; sync(true); });
  hex.addEventListener('input', ()=>{ let v=hex.value.trim(); if(/^#?[0-9a-fA-F]{6}$/.test(v)){ if(v[0]!=='#')v='#'+v; st=saRgbToHsv(...Object.values(saHexToRgb(v))); if(vsl)vsl.value=Math.round(st.v*100); sync(true); } });

  const key = valueId.replace('saBrand','').toLowerCase(); // accent / bg1 / bg2
  saWheels[key] = function(hxv){ st=saRgbToHsv(...Object.values(saHexToRgb(hxv||'#2b7be6'))); if(vsl)vsl.value=Math.round(st.v*100); sync(true); };

  vsl.value = Math.round(st.v*100);
  sync(true);
}

function saBrandingBuildWheels() {
  saMakeColorWheel('saWheelAccent', 'saBrandAccent', DEFAULT_LOGIN_THEME.accent);
  saMakeColorWheel('saWheelBg1',    'saBrandBg1',    DEFAULT_LOGIN_THEME.bg1);
  saMakeColorWheel('saWheelBg2',    'saBrandBg2',    DEFAULT_LOGIN_THEME.bg2);
}

function saBrandingUpdateMode() {
  const mode = document.getElementById('saBrandMode')?.value || 'default';
  const img  = document.getElementById('saBrandImageBlock');
  if (img) img.style.display = (mode === 'image') ? '' : 'none';
  saBrandingPreview();
}

function saBrandingThemeToggle() {
  const on  = document.getElementById('saBrandThemeCustom')?.checked;
  const box = document.getElementById('saBrandThemeColors');
  if (box) box.style.display = on ? '' : 'none';
  saBrandingPreview();
}

function saBrandingBgToggle() {
  const m = document.getElementById('saBrandBgMode')?.value || 'animated';
  const c = document.getElementById('saBrandBgColors');
  if (c) c.style.display = (m === 'gradient') ? '' : 'none';
  saBrandingPreview();
}

// Bascule « Classique » / « CSS personnalisé ».
function saBrandingModeToggle() {
  const m = document.getElementById('saBrandCustomMode')?.value || 'classic';
  const cl = document.getElementById('saBrandClassicBlock');
  const cs = document.getElementById('saBrandCssBlock');
  if (cl) cl.style.display = (m === 'classic') ? '' : 'none';
  if (cs) cs.style.display = (m === 'css') ? '' : 'none';
  saBrandingPreview();
}

function saBrandingInsertExample() {
  const ta = document.getElementById('saBrandCss');
  if (!ta) return;
  ta.value =
    "/* Halo pulsant derrière la carte */\n" +
    "#loginFx::before{content:'';position:absolute;inset:-10%;" +
    "background:radial-gradient(circle at 50% 35%,rgba(120,200,255,.30),transparent 60%);" +
    "animation:halo 7s ease-in-out infinite}\n" +
    "@keyframes halo{0%,100%{opacity:.35;transform:scale(1)}50%{opacity:.9;transform:scale(1.12)}}";
  saBrandingPreview();
}

function saBrandingThemeReset() {
  if (saWheels.accent) saWheels.accent(DEFAULT_LOGIN_THEME.accent);
  if (saWheels.bg1)    saWheels.bg1(DEFAULT_LOGIN_THEME.bg1);
  if (saWheels.bg2)    saWheels.bg2(DEFAULT_LOGIN_THEME.bg2);
  const bm = document.getElementById('saBrandBgMode'); if (bm) bm.value = 'animated';
  saBrandingBgToggle();
}

// Miroir client de TenantResolver::loginCssError (feedback immédiat).
function saLoginCssError(css) {
  css = String(css||'');
  if (!css.trim()) return null;
  if (css.length > 8000) return 'CSS trop long (8 Ko max).';
  if (/[<>]/.test(css)) return 'Le CSS ne doit pas contenir « < » ni « > ».';
  const c = css.toLowerCase().replace(/\s+/g,'');
  const bad = ['url(','@import','@charset','@font-face','@scope','expression(','javascript:','behavior:','-moz-binding','position:fixed','position:sticky','pointer-events'];
  for (const t of bad) if (c.includes(t)) return 'Élément interdit : « '+t+' ». Pas de chargement externe, position fixed/sticky ni pointer-events.';
  return null;
}

function saBrandingPreview() {
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c =>
    ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
  const cssMode = (document.getElementById('saBrandCustomMode')?.value || 'classic') === 'css';
  const custom = !cssMode && document.getElementById('saBrandThemeCustom')?.checked;
  const val = (id, fb) => document.getElementById(id)?.value || fb;
  const accent = custom ? val('saBrandAccent', DEFAULT_LOGIN_THEME.accent) : DEFAULT_LOGIN_THEME.accent;
  const bgMode = custom ? (document.getElementById('saBrandBgMode')?.value || 'animated') : 'animated';
  const bg1 = val('saBrandBg1', DEFAULT_LOGIN_THEME.bg1);
  const bg2 = val('saBrandBg2', DEFAULT_LOGIN_THEME.bg2);
  const mode = document.getElementById('saBrandMode')?.value || 'default';
  const title = (document.getElementById('saBrandTitle')?.value || '').trim() || 'Larka';
  const sub   = (document.getElementById('saBrandSubtitle')?.value || '').trim() || 'GMAO';
  const img   = document.getElementById('saBrandImageData')?.value || '';

  const bgEl = document.getElementById('saPrevBg');
  if (bgEl) bgEl.style.background = (!cssMode && bgMode === 'gradient')
    ? 'linear-gradient(135deg, '+bg1+', '+bg2+')'
    : (!cssMode
        ? 'radial-gradient(ellipse at 30% 40%, '+accent+'55, transparent 60%), linear-gradient(135deg, #0d1f3c, #13264a)'
        : 'linear-gradient(135deg, #0d1f3c, #13264a)');
  const btn = document.getElementById('saPrevBtn');
  if (btn) btn.style.background = cssMode ? '#2b7be6' : accent;
  const logo = document.getElementById('saPrevLogo');
  if (logo) {
    const mark = (mode === 'image' && img)
      ? '<img src="'+esc(img)+'" style="max-width:150px;max-height:54px;object-fit:contain"><br>'
      : '';
    logo.innerHTML = mark +
      '<div style="color:#fff;font-size:15px;font-weight:700;margin-top:'+(mark?'8px':'0')+'">'+esc(title)+'</div>' +
      '<div style="color:rgba(255,255,255,0.5);font-size:10px;margin-top:2px">'+esc(sub)+'</div>';
  }
  const lg = document.getElementById('saPrevLegal');
  if (lg) lg.textContent = '© 2025-2026 Mickaël Larcin (Chipsoreo) · Tous droits réservés';
  const sp = document.getElementById('saPrevSupport');
  if (sp) sp.style.display = (document.getElementById('saBrandKofi')?.checked) ? '' : 'none';

  // Aperçu du CSS perso : injecté tel quel (mêmes sélecteurs que le runtime),
  // en remplaçant juste #loginFx par #saPrevFx pour viser la couche d'aperçu.
  const cssVal = document.getElementById('saBrandCss')?.value || '';
  const errEl = document.getElementById('saBrandCssError');
  const err = saLoginCssError(cssVal);
  if (errEl) { errEl.style.display = err ? '' : 'none'; errEl.textContent = err || ''; }
  let pst = document.getElementById('saPrevCssStyle');
  if (!pst) { pst = document.createElement('style'); pst.id='saPrevCssStyle'; document.head.appendChild(pst); }
  pst.textContent = (cssMode && cssVal.trim() && !err)
    ? cssVal.replace(/#loginFx\b/g, '#saPrevFx')
    : '';
}

function saBrandingPrefill(b) {
  const set = (id, v) => { const e = document.getElementById(id); if (e) e.value = v ?? ''; };
  saBrandingBuildWheels();
  set('saBrandMode', (b && b.mode) || 'default');
  set('saBrandTitle', b && b.title);
  set('saBrandSubtitle', b && b.subtitle);
  { const kf = document.getElementById('saBrandKofi'); if (kf) kf.checked = !(b && b.kofi_enabled === false); }
  set('saBrandImageData', b && b.logo_image);
  set('saBrandCss', (b && b.custom_css) || '');
  set('saBrandBgMode', (b && b.bg_mode) || 'animated');
  set('saBrandCustomMode', (b && b.custom_mode) || 'classic');
  const hasTheme = !!(b && (b.theme_accent || b.theme_bg1 || b.theme_bg2 || b.bg_mode === 'gradient'));
  if (saWheels.accent) saWheels.accent((b && b.theme_accent) || DEFAULT_LOGIN_THEME.accent);
  if (saWheels.bg1)    saWheels.bg1((b && b.theme_bg1) || DEFAULT_LOGIN_THEME.bg1);
  if (saWheels.bg2)    saWheels.bg2((b && b.theme_bg2) || DEFAULT_LOGIN_THEME.bg2);
  const chk = document.getElementById('saBrandThemeCustom');
  if (chk) chk.checked = hasTheme;
  saBrandingThemeToggle();
  saBrandingBgToggle();
  saBrandingModeToggle();
  if (b && b.logo_image) saBrandingRenderPreview(b.logo_image);
  saBrandingUpdateMode();
}

function saBrandingPickImage(input) {
  const f = input.files && input.files[0];
  if (!f) return;
  if (f.size > 500 * 1024) { toast('Image trop volumineuse (max 500 Ko).', 'error'); input.value = ''; return; }
  const reader = new FileReader();
  reader.onload = () => {
    const h = document.getElementById('saBrandImageData');
    if (h) h.value = reader.result;
    saBrandingRenderPreview(reader.result);
    saBrandingPreview();
  };
  reader.readAsDataURL(f);
}

function saBrandingRenderPreview(src) {
  const p = document.getElementById('saBrandImagePreview');
  if (p) p.innerHTML = src
    ? '<img src="'+src+'" style="max-width:220px;max-height:90px;object-fit:contain;background:#0b132b;padding:8px;border-radius:8px">'
    : '';
}

function saBrandingClearImage() {
  const h = document.getElementById('saBrandImageData'); if (h) h.value = '';
  const input = document.getElementById('saBrandImageInput'); if (input) input.value = '';
  saBrandingRenderPreview('');
  saBrandingPreview();
}

function saAddEmailRow() {
  const list = document.getElementById('saEmailsList');
  if (!list) return;
  const row = document.createElement('div');
  row.style.cssText = 'display:flex;align-items:center;gap:8px;margin-bottom:6px';
  row.setAttribute('data-sa-email-row', '');
  row.innerHTML = `
    <input class="form-control" type="email" placeholder="email@entreprise.fr" style="flex:1;font-size:13px">
    <button class="icon-btn delete" onclick="this.parentElement.remove()" title="Retirer">🗑️</button>
  `;
  list.appendChild(row);
  row.querySelector('input').focus();
}

async function saSaveEmails() {
  const rows = document.querySelectorAll('[data-sa-email-row] input[type=email]');
  const emails = Array.from(rows).map(i => i.value.trim()).filter(Boolean);
  
  // Sauvegarder le profil Larka
  const profNom = document.getElementById('saProfNom')?.value?.trim() || '';
  const profPrenom = document.getElementById('saProfPrenom')?.value?.trim() || '';
  const profEmail = document.getElementById('saProfEmail')?.value?.trim() || '';
  
  try {
    // Sauvegarder les emails autorisés
    await fetch('api/index.php?action=superadmin_emails_save', {
      method: 'POST', credentials: 'include',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({ emails }),
    }).then(r => r.json()).then(j => { if (!j.success) throw new Error(j.error); });
    
    // Sauvegarder le profil
    await fetch('api/index.php?action=superadmin_profile_save', {
      method: 'POST', credentials: 'include',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({ nom: profNom, prenom: profPrenom, email: profEmail }),
    }).then(r => r.json()).then(j => { if (!j.success) throw new Error(j.error); });


    toast('Paramètres sauvegardés.');
    closeModal();
  } catch(e) { toast(e.message, 'error'); }
}

async function saChangePassword() {
  const oldPwd = document.getElementById('saOldPwd')?.value;
  const newPwd = document.getElementById('saNewPwd')?.value;
  if (!oldPwd || !newPwd) { toast('Remplissez les deux champs.', 'error'); return; }
  if (newPwd.length < 6) { toast('Minimum 6 caractères.', 'error'); return; }
  try {
    const res = await fetch('api/index.php?action=superadmin_change_password', {
      method: 'POST', credentials: 'include',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({ oldPassword: oldPwd, newPassword: newPwd }),
    });
    const json = await res.json();
    if (!json.success) throw new Error(json.error);
    toast('Mot de passe modifié.');
    document.getElementById('saOldPwd').value = '';
    document.getElementById('saNewPwd').value = '';
  } catch(e) { toast(e.message, 'error'); }
}

// ═══════════════════════════════════════════════════════════════════════════════
// ── Gestion des comptes Super Admin multiples ───────────────────────────────
// ═══════════════════════════════════════════════════════════════════════════════
async function saLoadAccounts() {
  const list = document.getElementById('saAccountsList');
  if (!list) return;
  try {
    const res = await fetch('api/index.php?action=superadmin_accounts', { credentials: 'include' });
    const json = await res.json();
    if (!json.success) { list.innerHTML = '<div style="color:var(--gray-text);font-size:12px">Erreur de chargement.</div>'; return; }
    // Seul le SA principal peut gérer les comptes : on adapte l'UI en conséquence.
    // (Le serveur reste l'autorité : require_primary_superadmin refusera de toute façon.)
    const isPrimary = !!json.data.current_is_primary;
    const addBtn = document.getElementById('saAddAccountBtn');
    const note = document.getElementById('saAccountsPrimaryNote');
    if (addBtn) addBtn.style.display = isPrimary ? '' : 'none';
    if (note) note.style.display = isPrimary ? 'none' : '';

    const accounts = json.data.accounts || [];
    if (!accounts.length) {
      list.innerHTML = '<div style="color:var(--gray-text);font-size:12px">Aucun compte configuré.</div>';
      return;
    }
    list.innerHTML = accounts.map(a => `
      <div style="display:flex;align-items:center;gap:10px;padding:8px 12px;margin-bottom:4px;background:var(--gray-bg);border-radius:8px">
        <div style="width:28px;height:28px;border-radius:8px;background:${a.is_primary?'linear-gradient(135deg,#f59e0b,#d97706)':'linear-gradient(135deg,#6366f1,#4f46e5)'};display:flex;align-items:center;justify-content:center;font-size:14px;color:white">
          ${a.is_primary ? '👑' : '🛡️'}
        </div>
        <div style="flex:1">
          <div style="font-size:13px;font-weight:600">${a.login}</div>
          <div style="font-size:11px;color:var(--gray-text)">${a.email || 'Pas d\'email'} ${a.is_primary ? '— Compte principal' : ''}</div>
        </div>
        ${(!a.is_primary && isPrimary) ? `<button class="icon-btn delete" onclick="saRemoveAccount('${a.login.replace(/'/g,"\\'")}')" title="Supprimer ce compte" style="font-size:12px">🗑️</button>` : ''}
      </div>
    `).join('');
  } catch(e) { list.innerHTML = '<div style="color:var(--red);font-size:12px">Erreur réseau.</div>'; }
}

function saAddAccountModal() {
  openModal('➕ Ajouter un compte Super Admin', `
    <div class="form-grid" style="grid-template-columns:1fr">
      <div class="form-group">
        <label class="form-label">Login *</label>
        <input class="form-control" type="text" id="saNewAccLogin" placeholder="admin2" autocomplete="off">
      </div>
      <div class="form-group">
        <label class="form-label">Mot de passe * (min. 8 caractères)</label>
        <input class="form-control" type="password" id="saNewAccPwd" placeholder="••••••••" autocomplete="new-password">
      </div>
      <div class="form-group">
        <label class="form-label">Email (optionnel — sera ajouté aux emails Microsoft autorisés)</label>
        <input class="form-control" type="email" id="saNewAccEmail" placeholder="admin2@entreprise.fr">
      </div>
    </div>
    <div style="font-size:11px;color:var(--gray-text);margin-top:8px">
      Ce compte pourra se connecter au panneau Super Admin avec ces identifiants.
    </div>
  `, async function() {
    const login = document.getElementById('saNewAccLogin')?.value?.trim();
    const pwd = document.getElementById('saNewAccPwd')?.value;
    const email = document.getElementById('saNewAccEmail')?.value?.trim();
    if (!login || !pwd) { toast('Login et mot de passe requis.', 'error'); return; }
    if (pwd.length < 8) { toast('Minimum 8 caractères pour le mot de passe.', 'error'); return; }
    try {
      const res = await fetch('api/index.php?action=superadmin_account_add', {
        method: 'POST', credentials: 'include',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ login, password: pwd, email }),
      });
      const json = await res.json();
      if (!json.success) throw new Error(json.error);
      toast('Compte ajouté.', 'success');
      closeModal();
      saLoadAccounts();
    } catch(e) { toast(e.message, 'error'); }
  });
}

async function saRemoveAccount(login) {
  showConfirm('Supprimer le compte « ' + login + ' » ?', async function() {
    try {
      const res = await fetch('api/index.php?action=superadmin_account_remove', {
        method: 'POST', credentials: 'include',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ login }),
      });
      const json = await res.json();
      if (!json.success) throw new Error(json.error);
      toast('Compte supprimé.', 'success');
      saLoadAccounts();
    } catch(e) { toast(e.message, 'error'); }
  });
}

// ══════════════════════════════════════════════════════════════════════════════
//  SAUVEGARDES PAR TENANT — Manuelle, Liste, Export, Import
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Sauvegarde manuelle avec choix de destination (dossier).
 * Laisse vide pour utiliser le dossier configuré dans le tenant.
 */
function saBackupNow(tenantKey) {
  const tenant = _saTenantsCache?.[tenantKey];
  const defaultDir = tenant?.sauvegarde?.dossier || 'data/backups';
  const defaultCompress = tenant?.sauvegarde?.compresser !== false;

  openModal('💾 Sauvegarde manuelle', `
    <p style="font-size:13px;color:var(--gray-text);margin:0 0 14px">
      Tenant : <strong>${_escSa(tenant?.nom || tenantKey)}</strong>
    </p>
    <div class="form-group">
      <label class="form-label">Dossier de destination</label>
      <input class="form-control" id="saBkManualDest" value="${_escSa(defaultDir)}" style="font-family:monospace" placeholder="data/backups">
      <small style="color:var(--gray-text)">Relatif au projet, ou chemin absolu. Laissez le dossier par défaut pour suivre la config du tenant.</small>
    </div>
    <div class="form-group">
      <label class="form-label" style="display:flex;align-items:center;gap:8px">
        <input type="checkbox" id="saBkManualCompress" ${defaultCompress?'checked':''}>
        Compresser en .gz
      </label>
    </div>
    <div id="saBkManualStatus" style="margin-top:10px;font-size:12px"></div>
  `, async () => {
    const destination = document.getElementById('saBkManualDest')?.value?.trim() || null;
    const compresser = !!document.getElementById('saBkManualCompress')?.checked;
    const statusEl = document.getElementById('saBkManualStatus');
    if (statusEl) {
      statusEl.textContent = '⏳ Sauvegarde en cours…';
      statusEl.style.color = 'var(--gray-text)';
    }
    try {
      const res = await fetch('api/index.php?action=superadmin_tenant_backup_now', {
        method: 'POST', headers: {'Content-Type':'application/json'}, credentials: 'include',
        body: JSON.stringify({ key: tenantKey, destination, compresser }),
      });
      const json = await res.json();
      if (!json.success) throw new Error(json.error);
      const d = json.data || {};
      const sizeKo = d.taille ? (d.taille / 1024).toFixed(1) + ' Ko' : '';
      toast(`✅ Sauvegarde créée : ${d.fichier} (${sizeKo})`, 'success');
      closeModal();
      await saRefresh();
    } catch(e) {
      if (statusEl) {
        statusEl.textContent = '❌ ' + e.message;
        statusEl.style.color = 'var(--red)';
      } else {
        toast(e.message, 'error');
      }
    }
  }, '💾 Lancer la sauvegarde');
}

/**
 * Affiche la liste des sauvegardes existantes pour ce tenant.
 */
async function saListBackups(tenantKey) {
  const tenant = _saTenantsCache?.[tenantKey];
  try {
    const res = await fetch(`api/index.php?action=superadmin_tenant_backup_list&key=${encodeURIComponent(tenantKey)}`, {
      credentials: 'include'
    });
    const json = await res.json();
    if (!json.success) throw new Error(json.error);
    const { dossier, fichiers, derniere } = json.data;

    const rows = (fichiers || []).map(f => {
      const sizeKo = (f.taille / 1024).toFixed(1) + ' Ko';
      return `<tr style="border-bottom:1px solid var(--gray-border)">
        <td style="padding:6px 10px;font-family:monospace;font-size:12px">${_escSa(f.nom)}</td>
        <td style="padding:6px 10px;font-size:12px;color:var(--gray-text);white-space:nowrap">${_escSa(f.date)}</td>
        <td style="padding:6px 10px;font-size:12px;text-align:right">${sizeKo}</td>
      </tr>`;
    }).join('');

    openModal(`📋 Sauvegardes — ${_escSa(tenant?.nom || tenantKey)}`, `
      <div style="font-size:12px;color:var(--gray-text);margin-bottom:10px">
        Dossier : <code style="background:var(--gray-bg);padding:2px 6px;border-radius:4px">${_escSa(dossier)}</code>
        ${derniere ? `<br>Dernière opération : <strong>${_escSa(derniere)}</strong>` : ''}
      </div>
      ${(!fichiers || fichiers.length === 0)
        ? '<div style="padding:20px;text-align:center;color:var(--gray-text)">Aucune sauvegarde trouvée.</div>'
        : `<div style="max-height:400px;overflow:auto;border:1px solid var(--gray-border);border-radius:8px">
            <table style="width:100%;border-collapse:collapse;font-size:13px">
              <thead><tr style="background:var(--gray-bg)">
                <th style="padding:8px 10px;text-align:left">Fichier</th>
                <th style="padding:8px 10px;text-align:left">Date</th>
                <th style="padding:8px 10px;text-align:right">Taille</th>
              </tr></thead>
              <tbody>${rows}</tbody>
            </table>
           </div>
           <div style="margin-top:8px;font-size:11px;color:var(--gray-text)">
             Total : ${fichiers.length} fichier(s). Les plus anciennes sont supprimées automatiquement selon la politique de rotation.
           </div>`
      }
    `, null, null);
  } catch(e) {
    toast('Erreur : ' + e.message, 'error');
  }
}

/**
 * Télécharge la BDD complète du tenant (SQLite = copie du fichier, PG/MariaDB = dump SQL).
 */
function saExportDb(tenantKey) {
  const tenant = _saTenantsCache?.[tenantKey];
  const driver = tenant?.base_de_donnees?.driver || 'sqlite';
  openModal('⬇️ Export BDD complète', `
    <p style="font-size:13px;color:var(--gray-text);margin:0 0 14px">
      Tenant : <strong>${_escSa(tenant?.nom || tenantKey)}</strong><br>
      Driver : <code>${_escSa(driver.toUpperCase())}</code>
    </p>
    <div style="padding:10px;background:var(--blue-pale);border-radius:8px;font-size:12px;color:var(--navy);margin-bottom:12px">
      ${driver === 'sqlite'
        ? '📦 Le fichier SQLite complet sera téléchargé.'
        : '📦 Un dump SQL (structure + données) sera généré à la volée.'}
    </div>
    <div class="form-group">
      <label class="form-label" style="display:flex;align-items:center;gap:8px">
        <input type="checkbox" id="saExpCompress" checked>
        Compresser en .gz (recommandé)
      </label>
    </div>
  `, () => {
    const compress = document.getElementById('saExpCompress')?.checked ? 1 : 0;
    const url = `api/index.php?action=superadmin_tenant_export_db&key=${encodeURIComponent(tenantKey)}&compress=${compress}`;
    // Déclencher le téléchargement via un <a> temporaire
    const a = document.createElement('a');
    a.href = url;
    a.rel = 'noopener';
    document.body.appendChild(a);
    a.click();
    a.remove();
    closeModal();
    toast('Téléchargement lancé.', 'success');
  }, '⬇️ Télécharger');
}

/**
 * Upload et remplace complètement la BDD du tenant (sauvegarde de sûreté automatique avant).
 */
function saImportDb(tenantKey) {
  const tenant = _saTenantsCache?.[tenantKey];
  const driver = tenant?.base_de_donnees?.driver || 'sqlite';
  openModal('⬆️ Import BDD complète', `
    <div style="padding:12px;background:var(--chip-warn-bg);border:1px solid var(--chip-warn-bd);border-radius:8px;font-size:13px;color:var(--chip-warn-fg);margin-bottom:14px">
      ⚠️ <strong>Action destructive.</strong> La base actuelle du tenant
      <strong>${_escSa(tenant?.nom || tenantKey)}</strong> sera <strong>remplacée</strong>.
      Une sauvegarde de sûreté est automatiquement créée avant l'import.
    </div>
    <p style="font-size:12px;color:var(--gray-text);margin:0 0 10px">
      Driver : <code>${_escSa(driver.toUpperCase())}</code>
      · Formats acceptés : ${driver === 'sqlite' ? '<code>.db</code> · <code>.db.gz</code>' : '<code>.sql</code> · <code>.sql.gz</code>'}
    </p>
    <div class="form-group">
      <label class="form-label">Fichier à importer</label>
      <input class="form-control" type="file" id="saImpFile" accept="${driver === 'sqlite' ? '.db,.gz,.sqlite' : '.sql,.gz'}">
    </div>
    <div class="form-group">
      <label class="form-label" style="display:flex;align-items:center;gap:8px;color:var(--chip-warn-fg)">
        <input type="checkbox" id="saImpConfirm">
        Je confirme vouloir remplacer définitivement la base actuelle.
      </label>
    </div>
    <div id="saImpStatus" style="font-size:12px"></div>
  `, async () => {
    const fileInput = document.getElementById('saImpFile');
    const confirmEl = document.getElementById('saImpConfirm');
    const statusEl = document.getElementById('saImpStatus');
    if (!fileInput?.files?.length) { toast('Sélectionnez un fichier.', 'error'); return; }
    if (!confirmEl?.checked) { toast('Cochez la confirmation.', 'error'); return; }

    const fd = new FormData();
    fd.append('key', tenantKey);
    fd.append('fichier', fileInput.files[0]);

    if (statusEl) {
      statusEl.textContent = '⏳ Import en cours (sauvegarde de sûreté d\'abord)…';
      statusEl.style.color = 'var(--gray-text)';
    }
    try {
      const res = await fetch('api/index.php?action=superadmin_tenant_import_db', {
        method: 'POST', credentials: 'include', body: fd,
      });
      const json = await res.json();
      if (!json.success) throw new Error(json.error);
      const d = json.data || {};

      // Résumé détaillé pour permettre de voir ce qui s'est vraiment passé
      let lignes = [];
      lignes.push(d.message || 'Import réussi.');
      if (d.sauvegarde_avant)   lignes.push('💾 Sauvegarde de sûreté : ' + d.sauvegarde_avant);
      if (d.comptes_preserves?.length) lignes.push('🔐 Comptes conservés : ' + d.comptes_preserves.join(', '));
      if (d.registre_sync)      lignes.push('🔄 Registre : ' + d.registre_sync);
      if (d.debug) {
        if (d.debug.snapshot_avant)  lignes.push('• Snapshot avant : ' + JSON.stringify(d.debug.snapshot_avant));
        if (d.debug.vidage_apres)    lignes.push('• Vidage après : ' + JSON.stringify(d.debug.vidage_apres));
        if (d.debug.reinjection)     lignes.push('• Réinjection : ' + JSON.stringify(d.debug.reinjection));
        if (d.debug.registre_sync)   lignes.push('• Registre : ' + d.debug.registre_sync);
      }
      if (d.avertissements?.length) lignes.push('⚠️ ' + d.avertissements.length + ' avertissement(s) SQL.');

      // Afficher un modal de résumé plutôt qu'un simple toast
      closeModal();
      setTimeout(() => {
        openModal('✅ Import terminé', `
          <div style="font-size:13px;line-height:1.7">
            ${lignes.map(l => `<div style="padding:6px 10px;background:var(--gray-bg);border-radius:6px;margin-bottom:6px;font-family:ui-monospace,monospace;font-size:12px">${_escSa(l)}</div>`).join('')}
          </div>
        `, null, null);
      }, 100);
      await saRefresh();
    } catch(e) {
      if (statusEl) {
        statusEl.textContent = '❌ ' + e.message;
        statusEl.style.color = 'var(--red)';
      } else {
        toast(e.message, 'error');
      }
    }
  }, '⬆️ Importer (destructif)');
}

// ── Gestion des modules d'un tenant ─────────────────────────────────────────
const _SA_MODULES = [
  { group: 'Principal',     items: [{ key:'dashboard', icon:'📊', label:'Tableau de bord' }] },
  { group: 'Inventaire',    items: [
      { key:'biens',       icon:'🏢', label:'Biens' },
      { key:'equipements', icon:'⚙️', label:'Équipements' },
      { key:'stock',       icon:'📦', label:'Stock' },
  ]},
  { group: 'Plans',         items: [{ key:'plans', icon:'🗺️', label:'Plans' }] },
  { group: 'Maintenance',   items: [
      { key:'interventions', icon:'🔧', label:'Interventions' },
      { key:'contrats',      icon:'📋', label:'Contrats' },
  ]},
  { group: 'Gestion',       items: [
      { key:'gestion_materiel', icon:'💰', label:'Gestion matériel' },
      { key:'historique',   icon:'🗃️', label:'Historique' },
      { key:'demandes',     icon:'📝', label:'Demandes' },
  ]},
  { group: 'Fluides',       items: [
      { key:'energie',          icon:'⚡', label:'Énergie' },
      { key:'carbone',          icon:'🌿', label:'Bilan Carbone' },
      { key:'mobilite_carbone', icon:'🚗', label:'Mobilité carbone' },
  ]},
  { group: 'Archives',      items: [{ key:'archives', icon:'🗄️', label:'Archives' }] },
  { group: 'Analyse',       items: [{ key:'statsAvancees', icon:'📈', label:'Stats avancées' }] },
  { group: 'Administration', items: [{ key:'journal', icon:'📜', label:'Journal d\'activité' }] },
  { group: 'République',    items: [
      { key:'legifrance', icon:'📜', label:'Légifrance' },
      { key:'chorus',     icon:'🏛️', label:'Chorus Pro' },
  ]},
];

async function saShowTenantModules(tenantKey) {
  // 1. Charger l'état actuel
  let disabled = [];
  try {
    const res = await fetch('api/index.php?action=superadmin_tenant_modules&key=' + encodeURIComponent(tenantKey), { credentials: 'include' });
    const j = await res.json();
    if (j.success) disabled = j.data.disabled || [];
  } catch (e) { toast('Erreur de chargement : ' + e.message, 'error'); return; }

  // 2. Construire l'UI
  let html = '<div style="font-size:13px;color:var(--gray-text);margin-bottom:14px">Cochez les modules à <strong>activer</strong> pour ce tenant. Les modules décochés seront masqués pour tous les utilisateurs.</div>';
  html += '<div style="display:flex;gap:8px;margin-bottom:12px"><button class="btn btn-sm btn-secondary" onclick="_saModulesAll(true)">✅ Tout activer</button><button class="btn btn-sm btn-secondary" onclick="_saModulesAll(false)">❌ Tout désactiver</button></div>';
  html += '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px">';
  _SA_MODULES.forEach(g => {
    html += '<div style="border:1px solid var(--gray-border);border-radius:8px;padding:10px;background:var(--white)">';
    html += '<div style="font-size:11px;font-weight:700;color:var(--navy);margin-bottom:6px;text-transform:uppercase;letter-spacing:.5px">' + _escSa(g.group) + '</div>';
    g.items.forEach(it => {
      const checked = !disabled.includes(it.key);
      html += '<label style="display:flex;align-items:center;gap:8px;padding:4px 0;cursor:pointer;font-size:13px">';
      html += '<input type="checkbox" class="sa-module-cb" data-key="' + it.key + '" ' + (checked ? 'checked' : '') + '>';
      html += '<span>' + it.icon + ' ' + _escSa(it.label) + '</span>';
      html += '</label>';
    });
    html += '</div>';
  });
  html += '</div>';

  openModal('🧩 Modules du tenant', html, async () => {
    const cbs = document.querySelectorAll('.sa-module-cb');
    const newDisabled = [];
    cbs.forEach(cb => { if (!cb.checked) newDisabled.push(cb.dataset.key); });
    try {
      const res = await fetch('api/index.php?action=superadmin_tenant_modules', {
        method: 'POST', credentials: 'include',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ key: tenantKey, disabled: newDisabled })
      });
      const j = await res.json();
      if (!j.success) throw new Error(j.error || 'Erreur');
      toast(j.data.message || 'Modules enregistrés', 'success');
      closeModal();
    } catch (e) {
      toast('Erreur : ' + e.message, 'error');
    }
  }, '💾 Enregistrer');
}

function _saModulesAll(enable) {
  document.querySelectorAll('.sa-module-cb').forEach(cb => { cb.checked = enable; });
}

// ── Purger la BDD d'un tenant (vider sans supprimer le tenant) ───────────────
async function saPurgeTenantDb(tenantKey) {
  const msg = '⚠️ ATTENTION ⚠️\n\n'
    + 'Vider TOUTES les données de la BDD du tenant "' + tenantKey + '" ?\n\n'
    + '→ Toutes les tables seront vidées (biens, équipements, interventions, etc.)\n'
    + '→ Tous les comptes utilisateurs locaux seront supprimés\n'
    + '→ Le registre des comptes locaux sera nettoyé pour ce tenant\n'
    + '→ Le tenant et sa configuration de connexion sont conservés\n'
    + '→ Cette action est IRRÉVERSIBLE\n\n'
    + 'Continuer ?';
  if (!confirm(msg)) return;
  const confirmName = prompt('Pour confirmer, tapez exactement le nom du tenant à vider :\n\n' + tenantKey);
  if (confirmName !== tenantKey) { toast('Annulé.', 'info'); return; }
  try {
    const res = await fetch('api/index.php?action=superadmin_tenant_purge_db', {
      method: 'POST', credentials: 'include',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ key: tenantKey })
    });
    const j = await res.json();
    if (!j.success) throw new Error(j.error || 'Erreur');
    let userMsg = j.data.message || 'Base purgée.';
    if (j.data.comptesLocauxNettoyes > 0) {
      userMsg += ' (' + j.data.comptesLocauxNettoyes + ' compte(s) local/aux retiré(s))';
    }
    toast(userMsg, 'success');
    if (j.data.erreurs && j.data.erreurs.length > 0) {
      console.warn('Erreurs purge :', j.data.erreurs);
    }
    setTimeout(() => location.reload(), 1500);
  } catch (e) {
    toast('Erreur : ' + e.message, 'error');
  }
}

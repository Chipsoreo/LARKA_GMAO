/*
 * SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
 * SPDX-License-Identifier: LicenseRef-Larka-Proprietary
 *
 * This file is part of Larka, proprietary software by Mickaël Larcin.
 * All rights reserved. Use is subject to the license terms; copying,
 * distribution, modification or reverse-engineering without the author's
 * prior written permission is prohibited. See the LICENSE file for details.
 */

/* ─────────────────────────────────────────────────────────────────────────
 * profile.js — Modal "Mon profil"
 * ─────────────────────────────────────────────────────────────────────────
 *
 * Permet à un utilisateur connecté (Demandeur, Gestionnaire ou Admin)
 * de compléter ses informations personnelles dans Larka :
 *   - Identité (Nom, Prénom)
 *   - Coordonnées (Email, Tél fixe, Tél mobile, Tél pro)
 *   - Organisation (Service, Poste)
 *
 * RÈGLE DE VERROUILLAGE pour les comptes Microsoft / Google :
 *   Les champs synchronisés depuis le provider OAuth sont read-only,
 *   sinon ils seraient écrasés à la prochaine connexion. Seuls les
 *   téléphones restent modifiables (le provider ne les pousse pas
 *   systématiquement). Affichage clair pour l'utilisateur.
 *
 * Utilité pour le chatbot :
 *   Le prompt système du Demandeur ET du Gestionnaire est enrichi avec
 *   ces champs côté serveur. Avec un profil complet, l'IA peut dire
 *   "appelle Marie Dupont au 06 12 34 56 78" au lieu de "votre responsable
 *   de service". Compléter son profil = chatbot plus utile pour tout le monde.
 *
 * Note : pas de section "changement de mot de passe" pour l'instant
 * (choix utilisateur). Le bloc est commenté plus bas, à décommenter
 * si vous changez d'avis.
 * ─────────────────────────────────────────────────────────────────────── */

/**
 * Ouvre la modale "Mon profil". Récupère l'utilisateur courant via App,
 * détermine son provider, et construit dynamiquement le formulaire avec
 * les bons champs en lecture seule selon le cas.
 */
function openMyProfile() {
  const user = (typeof App !== 'undefined' && App.currentUser) ? App.currentUser : null;
  if (!user) {
    if (typeof toast === 'function') toast('Utilisateur non connecté', 'error');
    return;
  }

  // Provider : 'local', 'microsoft', 'google'. Détermine quels champs sont
  // synchronisés depuis l'extérieur et donc en lecture seule.
  const provider = String(user.Provider || 'local').toLowerCase();
  const isLocal = (provider === 'local' || provider === '');

  // Bandeau d'info en haut selon le provider (uniquement si compte non-local,
  // pour prévenir l'utilisateur que certains champs sont verrouillés).
  let bannerHtml = '';
  if (!isLocal) {
    const providerLabel = provider.charAt(0).toUpperCase() + provider.slice(1);
    bannerHtml = `
      <div style="display:flex;gap:10px;padding:10px 12px;margin-bottom:14px;background:#fff7e6;border:1px solid #ffd591;border-radius:8px;font-size:13px;line-height:1.5;color:#874d00">
        <span style="font-size:18px;line-height:1">🔒</span>
        <div>
          <strong>Compte ${_escHtmlSafe(providerLabel)}</strong> —
          ton identité, ton email et ton service viennent de ${_escHtmlSafe(providerLabel)}
          et ne sont pas modifiables ici (ils seraient écrasés à la prochaine connexion).
          Tu peux quand même renseigner tes téléphones.
        </div>
      </div>`;
  }
  // Note : pas de bandeau pour les comptes locaux — l'écran parle de lui-même.

  // Construit un champ avec gestion du lock automatique. `forceLocked=true` rend
  // le champ read-only quel que soit le provider (réservé aux champs sensibles).
  const fieldHtml = (id, label, value, opts = {}) => {
    const locked = opts.forceLocked || (opts.lockedIfOAuth && !isLocal);
    const lockIcon = locked ? ' 🔒' : '';
    const inputAttrs = [
      `id="prof_${id}"`,
      `class="form-control"`,
      `value="${_escHtmlSafe(value || '')}"`,
      opts.type ? `type="${opts.type}"` : 'type="text"',
      opts.placeholder ? `placeholder="${_escHtmlSafe(opts.placeholder)}"` : '',
      opts.maxlength ? `maxlength="${opts.maxlength}"` : '',
      locked ? 'disabled style="background:#f5f5f5;color:#888;cursor:not-allowed"' : '',
    ].filter(Boolean).join(' ');
    return `
      <div class="form-group" ${opts.span ? `style="grid-column:span ${opts.span}"` : ''}>
        <label class="form-label">${_escHtmlSafe(label)}${lockIcon}</label>
        <input ${inputAttrs}>
      </div>`;
  };

  // Login et rôle : toujours en lecture seule (sécurité). On les affiche
  // pour info mais l'utilisateur ne peut pas s'auto-promouvoir.
  const bodyHtml = `
    ${bannerHtml}
    <div class="form-grid" style="display:grid;grid-template-columns:repeat(2,1fr);gap:14px">

      <!-- Bloc identité — verrouillé pour OAuth -->
      <div style="grid-column:span 2;font-weight:600;font-size:12px;color:#666;text-transform:uppercase;letter-spacing:.5px;margin-top:4px">
        Identité
      </div>
      ${fieldHtml('Prenom', 'Prénom', user.Prenom, { lockedIfOAuth: true, maxlength: 80 })}
      ${fieldHtml('Nom',    'Nom',    user.Nom,    { lockedIfOAuth: true, maxlength: 80 })}

      <!-- Login + rôle : info uniquement, toujours verrouillé -->
      ${fieldHtml('Login', 'Identifiant', user.Login, { forceLocked: true })}
      ${fieldHtml('Role',  'Rôle',        user.Role,  { forceLocked: true })}

      <!-- Bloc contact -->
      <div style="grid-column:span 2;font-weight:600;font-size:12px;color:#666;text-transform:uppercase;letter-spacing:.5px;margin-top:12px">
        Contact
      </div>
      ${fieldHtml('Email',     'Email',          user.Email,     { lockedIfOAuth: true, type: 'email', placeholder: 'prenom.nom@exemple.fr', maxlength: 150 })}
      ${fieldHtml('Tel',       'Téléphone fixe', user.Tel,       { placeholder: '01 23 45 67 89', maxlength: 30 })}
      ${fieldHtml('TelMobile', 'Tél. mobile',    user.TelMobile, { placeholder: '06 12 34 56 78', maxlength: 30 })}
      ${fieldHtml('TelPro',    'Tél. pro/poste', user.TelPro,    { placeholder: 'Ex: 4521 (n° interne)', maxlength: 30 })}

      <!-- Bloc organisation -->
      <div style="grid-column:span 2;font-weight:600;font-size:12px;color:#666;text-transform:uppercase;letter-spacing:.5px;margin-top:12px">
        Organisation
      </div>
      ${fieldHtml('Service', 'Service',      user.Service, { lockedIfOAuth: true, placeholder: 'Ex: Maintenance, Bâtiments...', maxlength: 100 })}
      ${fieldHtml('Poste',   'Poste / Fonction', user.Poste, { lockedIfOAuth: true, placeholder: 'Ex: Technicien, Responsable...', maxlength: 100 })}

      <!-- Changement de mot de passe — visible uniquement pour les comptes locaux.
           Les comptes Microsoft/Google gèrent leur mot de passe chez leur provider. -->
      ${isLocal ? `
      <div style="grid-column:span 2;font-weight:600;font-size:12px;color:#666;text-transform:uppercase;letter-spacing:.5px;margin-top:12px;padding-top:14px;border-top:1px solid #eee">
        Changer le mot de passe
      </div>
      <div class="form-group">
        <label class="form-label">Mot de passe actuel</label>
        <input id="prof_oldPwd" class="form-control" type="password" autocomplete="current-password" placeholder="(laisser vide pour ne pas changer)">
      </div>
      <div class="form-group">
        <label class="form-label">Nouveau mot de passe (8 caractères min.)</label>
        <input id="prof_newPwd" class="form-control" type="password" autocomplete="new-password">
      </div>
      ` : ''}

    </div>
  `;

  // Ouvre la modale via le helper existant. saveFn = saveMyProfile.
  openModal('👤 Mon profil', bodyHtml, saveMyProfile, 'Enregistrer', 'Fermer', '640px');
}

/**
 * Récupère les valeurs du formulaire et les envoie au backend.
 * Le serveur applique les règles de sécurité (champs verrouillés pour OAuth,
 * validation regex, longueur max) et renvoie le user mis à jour.
 */
async function saveMyProfile() {
  // ── Étape 1 : changement de mot de passe (si demandé) ────────────────
  // Si l'utilisateur a rempli les champs MDP, on traite ça d'abord.
  // Si le MDP échoue (ancien incorrect, nouveau trop court...) on s'arrête là
  // pour éviter de mettre à jour le profil mais pas le MDP — ce qui serait
  // déroutant pour l'utilisateur.
  // Si les deux champs sont vides → MDP ignoré. Un seul rempli → erreur explicite.
  const oldPwdEl = document.getElementById('prof_oldPwd');
  const newPwdEl = document.getElementById('prof_newPwd');
  if (oldPwdEl && newPwdEl) {
    const oldPwd = oldPwdEl.value || '';
    const newPwd = newPwdEl.value || '';
    if (oldPwd || newPwd) {
      if (!oldPwd || !newPwd) {
        if (typeof toast === 'function') toast('Pour changer le mot de passe, remplissez les deux champs.', 'error');
        return;
      }
      const pwdOk = await _changePasswordFromProfile(oldPwd, newPwd);
      if (!pwdOk) return; // toast d'erreur déjà émis par le handler
      oldPwdEl.value = ''; newPwdEl.value = '';
    }
  }

  // ── Étape 2 : mise à jour des infos de profil ───────────────────────
  // On lit toujours tous les champs, le backend filtrera ceux qu'il doit ignorer
  // (champs verrouillés). Avantage : si l'utilisateur tape dans un champ qui
  // semble actif mais ne l'est pas, on lui dit clairement après coup.
  const get = id => {
    const el = document.getElementById('prof_' + id);
    return el && !el.disabled ? el.value.trim() : null; // disabled → on n'envoie pas
  };

  // On collecte uniquement les champs effectivement éditables (non disabled).
  // Les valeurs null sont ignorées côté payload.
  const payload = {};
  ['Prenom', 'Nom', 'Email', 'Tel', 'TelMobile', 'TelPro', 'Service', 'Poste'].forEach(k => {
    const v = get(k);
    if (v !== null) payload[k] = v;
  });

  // Validation rapide côté client (pour les feedbacks instantanés ; le serveur revérifie).
  if (payload.Email && payload.Email !== '' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(payload.Email)) {
    if (typeof toast === 'function') toast("L'email n'a pas un format valide", 'error');
    return;
  }

  try {
    const res = await fetch('api/index.php?action=update_profile', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    const data = await res.json();
    if (!res.ok || data.error) {
      if (typeof toast === 'function') toast(data.error || 'Erreur lors de la mise à jour', 'error');
      return;
    }

    // Mettre à jour App.currentUser avec les nouvelles valeurs renvoyées par le serveur.
    if (data.user && typeof App !== 'undefined') {
      App.currentUser = data.user;
    }

    if (typeof toast === 'function') toast('Profil mis à jour ✓', 'success');

    // Si certains champs ont été ignorés (compte OAuth), prévenir gentiment
    if (Array.isArray(data.ignored) && data.ignored.length > 0) {
      setTimeout(() => {
        if (typeof toast === 'function') {
          toast('Certains champs n\'ont pas été modifiés (synchronisés depuis votre compte externe)', 'info');
        }
      }, 1200);
    }

    if (typeof closeModal === 'function') closeModal();

    // ▼ Bonus : si le chatbot est ouvert, on lui suggère de rappeler que le
    //   profil est à jour. Optionnel.
    // if (typeof _assistantOpen !== 'undefined' && _assistantOpen) { ... }
  } catch (e) {
    if (typeof toast === 'function') toast('Erreur réseau : ' + (e.message || e), 'error');
  }
}

/* ───────────────────────────────────────────────────────────────────────
   Helper local : escape HTML pour usage dans innerHTML / attributs.
   On utilise une version locale plutôt que _escHtml d'assistant.js pour
   ne pas créer une dépendance dure (profile.js peut être chargé sans
   l'assistant si demain on retire ce dernier).
   ─────────────────────────────────────────────────────────────────── */
function _escHtmlSafe(s) {
  return String(s ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

/**
 * Handler de changement de mot de passe — appelé par saveMyProfile().
 * Appelle la route /api/index.php?action=change_password (qui existe déjà dans
 * auth.php avec rate limiting). Retourne true en cas de succès, false sinon
 * (avec toast d'erreur déjà émis).
 */
async function _changePasswordFromProfile(oldPwd, newPwd) {
  if (!oldPwd || !newPwd) return false;
  if (newPwd.length < 8) {
    if (typeof toast === 'function') toast('Nouveau mot de passe trop court (8 caractères minimum)', 'error');
    return false;
  }
  try {
    const r = await fetch('api/index.php?action=change_password', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ oldPassword: oldPwd, newPassword: newPwd }),
    });
    const d = await r.json();
    if (!r.ok || d.error) {
      if (typeof toast === 'function') toast(d.error || 'Erreur changement de mot de passe', 'error');
      return false;
    }
    if (typeof toast === 'function') toast('Mot de passe modifié ✓', 'success');
    return true;
  } catch(e) {
    if (typeof toast === 'function') toast('Erreur réseau lors du changement de mot de passe', 'error');
    return false;
  }
}

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
 * Larka — Page : Gestion des utilisateurs
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * CRUD des comptes utilisateurs par l'administrateur.
 * Onglet Notifications : envoi de notifications push ciblées.
 *
 * RÔLES :
 *   - Gestionnaire  → CRUD biens/équipements/interventions/contrats + configuration
 *   - Visionneur    → lecture seule
 *   - Demandeur     → uniquement formulaire de demande
 *
 * POINT D'ENTRÉE : renderUtilisateurs()
 * ═══════════════════════════════════════════════════════════════════════════════
 */

async function renderUtilisateurs() {
  document.getElementById('topbarActions').innerHTML = '';
  const c = document.getElementById('mainContent');
  showLoading(c);

  const canManage = isSuperAdmin() || isAdmin();

  try {
    const data = await UtilisateursApi.getAll();

    data.forEach(u => {
      const p = u.Provider || 'local';
      u._type = p === 'google' ? '🔴 Google' : p === 'microsoft' ? '🔵 Microsoft' : '🔑 Local';
      // Récapitulatif des onglets ouverts : visible directement dans la liste,
      // pour repérer qui a des accès élargis sans ouvrir chaque fiche.
      u._acces = _usrBadgeAcces(u);
      const initiales = ((u.Prenom?.[0]||'') + (u.Nom?.[0]||'')).toUpperCase() || '?';
      if (u.PhotoUrl) {
        u._avatar = `<img src="${escHtml(u.PhotoUrl||'')}" style="width:28px;height:28px;border-radius:50%;object-fit:cover;vertical-align:middle" alt="${initiales}">`;
      } else {
        u._avatar = `<span style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:50%;background:var(--blue);color:white;font-size:10px;font-weight:700;vertical-align:middle">${initiales}</span>`;
      }
    });

    c.innerHTML = `<div id="tableUtilisateurs"></div>`;

    renderTable({
      container: document.getElementById('tableUtilisateurs'), data,
      columns: [
        { key:'_avatar', label:'',  render: (v) => v || '', width:'40px' },
        { key:'Login',   label:'Login',      editFn: canManage ? 'editUser' : null, deleteFn: canManage ? 'deleteUser' : null },
        { key:'Prenom',  label:'Prénom' },
        { key:'Nom',     label:'Nom' },
        { key:'Email',   label:'Email' },
        { key:'TelMobile', label:'📱 Mobile' },
        { key:'TelPro',   label:'📞 Pro' },
        { key:'Service', label:'Service' },
        { key:'Poste',   label:'Poste' },
        { key:'Role',    label:'Rôle',  badge:true },
        { key:'_acces',  label:'👁️ Consultation', render: (v) => v || '' },
        { key:'_type',   label:'Type' },
      ],
      addBtnFn: canManage ? 'editUser' : null,
      searchTerm: App.searchTerm, currentFilter: App.currentFilter,
      canEdit: canManage, canDelete: canManage,
    });

  } catch(e) { c.innerHTML = errorHtml(e.message); }
}

// ── Édition utilisateur ───────────────────────────────────────────────────────
// ═══════════════════════════════════════════════════════════════════════════════
//  Onglets consultables — autorisation propre à CE compte
// ═══════════════════════════════════════════════════════════════════════════════
// L'autorisation est strictement nominative : ce qui est coché ici ne vaut que
// pour cette personne. Il n'existe aucun réglage collectif, afin qu'ouvrir un
// onglet reste une décision prise agent par agent.
//
// Le rôle ne change pas : la personne reste Demandeur, garde ses demandes, sa
// mobilité et son inventaire, et n'obtient qu'un droit de CONSULTATION. Tous
// les boutons d'action de ces pages passent par canEdit(), et le serveur refuse
// toute écriture même si l'interface était contournée.
function _usrBlocAccesLecture(u) {
  const items = (typeof DEMANDEUR_LECTURE_ITEMS !== 'undefined') ? DEMANDEUR_LECTURE_ITEMS : {};
  if (!Object.keys(items).length) return '';

  // Les deux casses sont acceptées : sous PostgreSQL la colonne peut revenir
  // en « acceslecture » si le dictionnaire de casse n'a pas été appliqué.
  const brutAcces = u.AccesLecture || u.acceslecture || '[]';
  let accordes = [];
  try { const p = JSON.parse(brutAcces); if (Array.isArray(p)) accordes = p; } catch(_) {}
  const visible = (u.Role || 'Demandeur') === 'Demandeur';

  let h = '<div class="form-group span-2" id="accesLectureGroup" style="display:' + (visible ? 'block' : 'none') + '">';
  h += '  <label class="form-label">👁️ Onglets consultables par cette personne</label>';
  h += '  <div style="border:1px solid var(--gray-border);border-radius:8px;padding:12px;background:var(--gray-bg)">';
  h += '    <div style="font-size:11px;color:var(--gray-text);margin-bottom:10px;line-height:1.5">';
  h += '      Onglets ajoutés au menu de <strong>ce compte uniquement</strong>, en lecture seule.';
  h += '      Le rôle reste <strong>Demandeur</strong> : ses demandes et sa mobilité sont conservées, et aucune modification ne lui sera possible.';
  h += '      Rien de coché = accès habituel du demandeur.';
  h += '    </div>';

  // Regroupement par section : dix pastilles en vrac deviennent illisibles,
  // et les sections reprennent celles du menu que verra la personne.
  const sections = {};
  Object.keys(items).forEach(function(page) {
    const sec = items[page].section || 'Autres';
    (sections[sec] = sections[sec] || []).push(page);
  });

  Object.keys(sections).forEach(function(sec) {
    h += '<div style="margin-bottom:8px">';
    h += '  <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--gray-text);margin-bottom:4px">' + sec + '</div>';
    h += '  <div style="display:flex;flex-wrap:wrap;gap:6px">';
    sections[sec].forEach(function(page) {
      const on = accordes.includes(page);
      const sensible = (page === 'contrats' || page === 'gestion_materiel');
      h += '<label class="acces-pastille" style="display:inline-flex;align-items:center;gap:6px;font-size:12px;padding:6px 11px;border-radius:16px;border:1px solid ' + (on ? 'var(--teal,#0e7490)' : 'var(--gray-border)') + ';background:var(--card-bg);cursor:pointer">';
      h += '  <input type="checkbox" class="acces-lecture-cb" value="' + page + '" ' + (on ? 'checked' : '') + ' onchange="_usrMajPastille(this)">';
      h += '  <span>' + items[page].icon + ' ' + items[page].label + '</span>';
      if (sensible) h += '<span title="Données financières ou contractuelles" style="font-size:9px;font-weight:700;padding:1px 5px;border-radius:7px;background:#fef3c7;color:#92400e;border:1px solid #fcd34d">SENSIBLE</span>';
      h += '</label>';
    });
    h += '  </div>';
    h += '</div>';
  });

  h += '    <div style="margin-top:10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">';
  h += '      <button type="button" class="btn btn-ghost btn-sm" onclick="_usrAccesTout(false)" style="font-size:11px">Tout décocher</button>';
  h += '      <span style="font-size:10px;color:var(--gray-text);font-style:italic">Les onglets Demandes, Ma mobilité, Plans et Procédures d\'urgence relèvent de leurs propres réglages.</span>';
  h += '    </div>';
  h += '  </div>';
  h += '</div>';
  return h;
}

/** Masque le bloc dès que le rôle n'est plus Demandeur : sans objet ailleurs. */
function _usrToggleAcces(role) {
  const el = document.getElementById('accesLectureGroup');
  if (el) el.style.display = (role === 'Demandeur') ? 'block' : 'none';
}

/** Retour visuel immédiat : la pastille cochée se teinte. */
function _usrMajPastille(cb) {
  const l = cb.closest('.acces-pastille');
  if (l) l.style.borderColor = cb.checked ? 'var(--teal,#0e7490)' : 'var(--gray-border)';
}

function _usrAccesTout(etat) {
  document.querySelectorAll('.acces-lecture-cb').forEach(function(cb) {
    cb.checked = !!etat; _usrMajPastille(cb);
  });
}

/** JSON des onglets cochés. Tableau vide = aucun accès supplémentaire. */
function _usrLireAccesCoches() {
  const coches = Array.from(document.querySelectorAll('.acces-lecture-cb'))
    .filter(function(cb) { return cb.checked; })
    .map(function(cb) { return cb.value; });
  return JSON.stringify(coches);
}

/** Pastille récapitulative pour la liste des comptes. */
function _usrBadgeAcces(u) {
  if ((u.Role || '') !== 'Demandeur') return '';
  const brut = u.AccesLecture || u.acceslecture || '[]';
  let liste = [];
  try { const p = JSON.parse(brut); if (Array.isArray(p)) liste = p; } catch(_) {}
  const n = liste.length;
  if (!n) return '<span style="color:var(--gray-text);font-size:11px">—</span>';
  const items = (typeof DEMANDEUR_LECTURE_ITEMS !== 'undefined') ? DEMANDEUR_LECTURE_ITEMS : {};
  const libelles = liste.map(function(p) { return (items[p] && items[p].label) || p; });
  return '<span title="' + escHtml(libelles.join(', ')) + '" style="display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;padding:2px 8px;border-radius:10px;background:#0e7490;color:#fff">👁️ ' + n + '</span>';
}

function editUser(id) {
  const isNew = !id;
  const isSA  = isSuperAdmin();
  const fetchFn = isNew
    ? Promise.resolve({})
    : UtilisateursApi.getAll().then(d => d.find(x => x.Id === id) || {});

  fetchFn.then(u => {

    const rolesDispos = ['Gestionnaire','Visionneur','Demandeur'];

    const provider = u.Provider || 'local';
    const isOAuthAccount = !isNew && provider !== 'local';

    openModal(isNew ? 'Nouvel utilisateur' : `Modifier ${escHtml(u.Login||'')}`, `
    <div class="form-grid">
      <div class="form-group">
        <label class="form-label">Prénom <span class="req">*</span></label>
        <input class="form-control" id="f_prenom" value="${escHtml(u.Prenom||'')}">
      </div>
      <div class="form-group">
        <label class="form-label">Nom <span class="req">*</span></label>
        <input class="form-control" id="f_nom" value="${escHtml(u.Nom||'')}">
      </div>
      <div class="form-group">
        <label class="form-label">Login <span class="req">*</span></label>
        <input class="form-control" id="f_login" value="${escHtml(u.Login||'')}" placeholder="${provider==='local'?'ex: jdupont':'email@domaine.com'}">
      </div>
      <div class="form-group" id="emailGroup" style="display:${provider==='local'?'block':'none'}">
        <label class="form-label">Adresse email</label>
        <input class="form-control" type="email" id="f_email" value="${escHtml(u.Email||'')}" placeholder="prenom.nom@entreprise.fr">
        <div style="font-size:10px;color:var(--gray-text);margin-top:2px">Utilisé pour la récupération de mot de passe</div>
      </div>
      <div class="form-group">
        <label class="form-label">📱 Tél. mobile</label>
        <input class="form-control" id="f_telMobile" type="tel" value="${u.TelMobile||u.Tel||''}" placeholder="06 12 34 56 78">
      </div>
      <div class="form-group">
        <label class="form-label">📞 Tél. professionnel</label>
        <input class="form-control" id="f_telPro" type="tel" value="${escHtml(u.TelPro||'')}" placeholder="05 61 00 00 00">
      </div>
      <div class="form-group">
        <label class="form-label">Service</label>
        <input class="form-control" id="f_service" value="${escHtml(u.Service||'')}" placeholder="ex: Direction des Systèmes d'Information">
      </div>
      <div class="form-group">
        <label class="form-label">Poste / Fonction</label>
        <input class="form-control" id="f_poste" value="${escHtml(u.Poste||'')}" placeholder="ex: Chargé de maintenance">
      </div>

      <div class="form-group">
        <label class="form-label">Rôle</label>
        <select class="form-control" id="f_role" onchange="_usrToggleAcces(this.value)">
          ${rolesDispos.map(x => `<option ${u.Role===x?'selected':''}>${x}</option>`).join('')}
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Type de compte</label>
        <select class="form-control" id="f_provider" onchange="document.getElementById('pwdGroup').style.display=(this.value==='local'?'block':'none');document.getElementById('emailGroup').style.display=(this.value==='local'?'block':'none')">
          <option value="local" ${provider==='local'?'selected':''}>Local</option>
          <option value="microsoft" ${provider==='microsoft'?'selected':''}>Microsoft</option>
          <option value="google" ${provider==='google'?'selected':''}>Google</option>
        </select>
      </div>

      ${_usrBlocAccesLecture(u)}

      <div class="form-group span-2" id="pwdGroup" style="display:${provider==='local'?'block':'none'}">
        <label class="form-label">Mot de passe ${isNew?'<span class="req">*</span>':'(vide = inchangé)'}</label>
        <input class="form-control" type="password" id="f_password" autocomplete="new-password">
      </div>

      <div class="form-group span-2">
        <label class="form-label">Statut</label>
        <select class="form-control" id="f_actif">
          <option value="1" ${u.Actif==1||isNew?'selected':''}>Actif</option>
          <option value="0" ${u.Actif==0?'selected':''}>Désactivé</option>
        </select>
      </div>
      ${isOAuthAccount && provider === 'microsoft' ? `
      <div class="form-group span-2" style="border-top:1px solid var(--border);padding-top:14px;margin-top:6px">
        <label class="form-label" style="font-weight:700;color:var(--blue);font-size:13px">
          <svg width="14" height="14" viewBox="0 0 21 21" style="vertical-align:-2px;margin-right:4px">
            <rect x="1" y="1" width="9" height="9" fill="#f25022"/><rect x="11" y="1" width="9" height="9" fill="#7fba00"/>
            <rect x="1" y="11" width="9" height="9" fill="#00a4ef"/><rect x="11" y="11" width="9" height="9" fill="#ffb900"/>
          </svg>
          Données synchronisées Microsoft
        </label>
        <div style="display:flex;gap:16px;align-items:flex-start;margin-top:8px">
          ${u.PhotoUrl ? `<img src="${escHtml(u.PhotoUrl||'')}" style="width:56px;height:56px;border-radius:50%;object-fit:cover;border:2px solid var(--border);flex-shrink:0" alt="Photo">` : ''}
          <div style="font-size:12px;color:var(--text-secondary);line-height:1.7">
            ${u.OfficeLocation ? `<div>📍 <strong>Bureau :</strong> ${escHtml(u.OfficeLocation||'')}</div>` : ''}
            ${u.CompanyName ? `<div>🏢 <strong>Société :</strong> ${escHtml(u.CompanyName||'')}</div>` : ''}
            ${u.ManagerName ? `<div>👤 <strong>Responsable :</strong> ${escHtml(u.ManagerName||'')}</div>` : ''}
            ${u.MicrosoftId ? `<div style="opacity:.5">🔑 ID : ${u.MicrosoftId.substring(0,12)}…</div>` : ''}
            ${!u.OfficeLocation && !u.CompanyName && !u.ManagerName ? '<div style="opacity:.5">Aucune donnée enrichie disponible</div>' : ''}
          </div>
        </div>
      </div>` : ''}
    </div>`, async () => {
      const prenom     = gv('f_prenom');
      const nom        = gv('f_nom');
      const login      = gv('f_login');
      const email      = gv('f_email');
      const telMobile  = gv('f_telMobile');
      const telPro     = gv('f_telPro');
      const service    = gv('f_service');
      const poste      = gv('f_poste');
      const motDePasse = gv('f_password');
      const providerSel = gv('f_provider') || 'local';

      if (!prenom || !nom || !login) { toast('Champs obligatoires manquants.', 'error'); return; }
      if (isNew && providerSel === 'local' && !motDePasse) { toast('Mot de passe requis pour un compte local.', 'error'); return; }

      const tel = telMobile || telPro || '';
      const payload = { prenom, nom, login, email, tel, service, poste, role: gv('f_role'), actif: parseInt(gv('f_actif')), provider: providerSel };
      // Onglets consultables : propres à ce compte, et uniquement pour un
      // Demandeur. Sur un autre rôle on envoie une liste vide, ce qui efface
      // des autorisations devenues sans objet — un Gestionnaire a déjà tout,
      // un Visionneur voit déjà l'ensemble en lecture.
      payload.accesLecture = (gv('f_role') === 'Demandeur') ? _usrLireAccesCoches() : '[]';
      if (motDePasse) payload.motDePasse = motDePasse;

      try {
        if (isNew) await UtilisateursApi.create(payload);
        else       await UtilisateursApi.update(id, payload);
        toast(isNew ? 'Utilisateur créé.' : 'Utilisateur modifié.', 'success');
        closeModal();
        if (_configTab === 'comptes' && typeof _loadComptesTab === 'function') _loadComptesTab();
        else if (typeof renderUtilisateurs === 'function') renderUtilisateurs();
      } catch(e) { toast(e.message, 'error'); }
    });
  });
}

async function deleteUser(id) {
  if (id === App.currentUser?.Id) { toast('Impossible de supprimer votre propre compte.', 'error'); return; }
  showConfirm('Supprimer cet utilisateur ?', async () => {
    try {
      await UtilisateursApi.delete(id);
      toast('Utilisateur supprimé.');
      if (_configTab === 'comptes' && typeof _loadComptesTab === 'function') _loadComptesTab();
      else if (typeof renderUtilisateurs === 'function') renderUtilisateurs();
    }
    catch(e) { toast(e.message, 'error'); }
  });
}


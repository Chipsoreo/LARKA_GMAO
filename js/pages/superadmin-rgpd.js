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
 * Larka — Super Admin : Conformité RGPD
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Une politique de protection des données COMMUNE (le modèle Larka, qui
 * s'adapte de lui-même à l'installation), que chaque organisation peut
 * personnaliser : coordonnées, sections réécrites, sections ajoutées. Toute
 * section réécrite ou ajoutée est signalée aux utilisateurs (« Personnalisé
 * par l'entreprise »), et la politique entière porte un bandeau qui le dit.
 *
 * Serveur : api/Rgpd.php, api/routes/rgpd.php (superadmin_rgpd).
 * POINT D'ENTRÉE : renderSuperAdminRgpd()
 * ═══════════════════════════════════════════════════════════════════════════════
 */

let _rgpdSa = null;   // { multi, modele, commun, identite, contexte, organisations, modele_date }

function _rgEsc(s) {
  return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}
function _rgDate(d) {
  const m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/.exec(String(d || ''));
  return m ? `${m[3]}/${m[2]}/${m[1]}${m[4] ? ' ' + m[4] + ':' + m[5] : ''}` : '';
}
async function _rgApi(params, corps) {
  const url = 'api/index.php?action=superadmin_rgpd' + (params ? '&' + params : '');
  const opts = { credentials: 'include' };
  if (corps) {
    opts.method = 'POST';
    opts.headers = { 'Content-Type': 'application/json' };
    opts.body = JSON.stringify(corps);
  }
  const r = await fetch(url, opts);
  const j = await r.json().catch(() => ({ success: false, error: 'Réponse illisible du serveur.' }));
  if (!j.success) throw new Error(j.error || 'Erreur');
  return j.data;
}

async function renderSuperAdminRgpd() {
  const main = document.getElementById('mainContent');
  document.querySelectorAll('#sidebarNav .nav-item').forEach(n => n.classList.toggle('active', n.dataset.page === 'superadmin_rgpd'));
  if (typeof App !== 'undefined') App.currentPage = 'superadmin_rgpd';
  const titre = document.getElementById('pageTitle');
  if (titre) titre.textContent = 'Conformité RGPD';
  main.innerHTML = '<div style="padding:40px;text-align:center;color:var(--gray-text)">Chargement…</div>';
  try {
    _rgpdSa = await _rgApi('');
  } catch (e) {
    main.innerHTML = '<div style="padding:40px;text-align:center;color:var(--red)" data-rg-err></div>';
    main.querySelector('[data-rg-err]').textContent = 'Conformité RGPD indisponible : ' + e.message;
    return;
  }
  _rgListe();
}

// ── Vue d'ensemble ────────────────────────────────────────────────────────────
function _rgListe() {
  const d = _rgpdSa;
  const c = d.contexte || {};
  const main = document.getElementById('mainContent');
  const puce = (t, ok) => `<span style="display:inline-block;margin:2px 4px 2px 0;padding:3px 9px;border-radius:999px;font-size:12px;`
    + `background:${ok ? 'var(--chip-ok-bg)' : 'var(--gray-bg)'};color:${ok ? 'var(--chip-ok-fg)' : 'var(--gray-text)'}">${_rgEsc(t)}</span>`;
  const ia = !c.ia ? 'Assistant IA : désactivé' : (c.ia_externe ? `Assistant IA : fournisseur externe (${c.fournisseur_ia})` : 'Assistant IA : sans service extérieur');
  const communRetouche = d.multi && ((d.commun?.sections && Object.keys(d.commun.sections).length) || (d.commun?.ajouts || []).length
    || (d.commun?.identite && Object.keys(d.commun.identite).length));

  const lignes = (d.organisations || []).map(o => {
    const etat = o.personnalisee
      ? `<span class="rgpd-badge">✎ Personnalisée</span> <span style="font-size:12px;color:var(--gray-text)">${o.sections} section(s) réécrite(s), ${o.ajouts} ajoutée(s)`
        + (o.mise_a_jour ? ` — le ${_rgEsc(_rgDate(o.mise_a_jour))}${o.par ? ' par ' + _rgEsc(o.par) : ''}` : '') + '</span>'
      : '<span style="font-size:12px;color:var(--gray-text)">Modèle commun' + (o.mise_a_jour ? ` (coordonnées renseignées le ${_rgEsc(_rgDate(o.mise_a_jour))})` : '') + '</span>';
    const champs = { organisation: 'organisation', contact: 'contact' };
    const coord = (o.manquants || []).length
      ? `<span style="font-size:12px;color:var(--orange,#b45309)">⚠️ À compléter : ${o.manquants.map(m => _rgEsc(champs[m] || m)).join(', ')}</span>`
      : '<span style="font-size:12px;color:var(--chip-ok-fg)">✓ Coordonnées renseignées</span>';
    const cle = _rgEsc(o.cle);
    return `<div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px;padding:12px 14px;border:1px solid var(--gray-border);border-radius:10px;background:var(--gray-bg)">
      <div style="flex:1;min-width:220px">
        <div style="font-weight:700;color:var(--navy)">${_rgEsc(o.nom)} ${d.multi ? `<code style="font-size:11px;background:var(--gray-bg);padding:1px 6px;border-radius:4px">${cle}</code>` : ''}</div>
        <div style="margin-top:3px">${etat}</div>
        <div style="margin-top:2px">${coord}</div>
      </div>
      <div style="display:flex;gap:6px;flex-wrap:wrap">
        <button class="btn btn-sm btn-primary" onclick="_rgEditer('org', '${cle}')">✏️ Personnaliser</button>
        <button class="btn btn-sm" onclick="_rgApercuOrg('${cle}')">👁 Aperçu</button>
        ${o.personnalisee || o.mise_a_jour ? `<button class="btn btn-sm" onclick="_rgReinitialiser('org', '${cle}')" title="Effacer la personnalisation et les coordonnées">↺ Modèle commun</button>` : ''}
      </div>
    </div>`;
  }).join('');

  main.innerHTML = `
    <div style="max-width:1000px;background:var(--card-bg);border:1px solid var(--gray-border);border-radius:14px;padding:18px 20px">
      <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:10px">
        <h2 style="margin:0;font-size:20px;color:var(--navy)">🛡️ Conformité RGPD</h2>
        <div style="flex:1"></div>
        <button class="btn" onclick="_rgApercuCommun()">👁 Politique commune</button>
      </div>
      <p style="font-size:13px;color:var(--text-mid);line-height:1.55;margin:0 0 12px">
        La politique de protection des données est <strong>commune</strong> à toutes les organisations : le modèle Larka
        décrit ce que fait le logiciel et s'adapte de lui-même à l'installation. Chaque organisation peut la
        <strong>personnaliser</strong> : ses coordonnées (responsable, contact, DPO), des sections réécrites, des sections ajoutées.
        Toute section réécrite ou ajoutée est signalée aux utilisateurs par la mention
        <span class="rgpd-badge">Personnalisé par l'entreprise</span>, et la politique par un bandeau daté.
        Elle s'ouvre depuis l'écran de connexion, le menu « Protection des données » et l'assistant IA.
      </p>
      <p style="font-size:12px;color:var(--gray-text);margin:0 0 14px">
        ⚖️ Modèle fourni à titre indicatif (révision du ${_rgEsc(_rgDate(d.modele_date))}) : la base légale et les durées de conservation
        propres à chaque organisation sont à faire valider par son délégué à la protection des données.
      </p>
      <div style="padding:12px 14px;border:1px solid var(--gray-border);border-radius:10px;background:var(--gray-bg);margin-bottom:18px">
        <div style="font-size:12px;color:var(--gray-text);margin-bottom:6px">Ce que le modèle adapte de lui-même à cette installation :</div>
        ${puce(ia, !!c.ia)}
        ${puce('Connexion externe : ' + ((c.sso || []).length ? c.sso.join(', ') : 'aucune'), (c.sso || []).length > 0)}
        ${puce('Notifications : ' + (c.push ? 'oui' : 'non'), !!c.push)}
        ${puce('Journaux : ' + c.journaux_jours + ' jours', true)}
        ${puce('Sessions : ' + c.session_heures + ' h', true)}
      </div>
      <h3 style="font-size:15px;margin:0 0 8px;color:var(--navy)">${d.multi ? 'Organisations' : 'Organisation'}</h3>
      <div style="display:grid;gap:10px;margin-bottom:22px">${lignes || '<div style="color:var(--gray-text)">Aucune organisation.</div>'}</div>
      ${d.multi ? `
      <h3 style="font-size:15px;margin:0 0 8px;color:var(--navy)">Modèle commun</h3>
      <div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px;padding:12px 14px;border:1px solid var(--gray-border);border-radius:10px;background:var(--gray-bg)">
        <div style="flex:1;min-width:220px;font-size:13px;color:var(--gray-text)">
          ${communRetouche
            ? `Retouché par le Super Admin${d.commun.mise_a_jour ? ' le ' + _rgEsc(_rgDate(d.commun.mise_a_jour)) : ''} : vaut pour toutes les organisations qui ne réécrivent pas la section.`
            : 'Le modèle Larka tel quel. Les retouches faites ici valent pour toutes les organisations (sans mention « personnalisé »).'}
        </div>
        <div style="display:flex;gap:6px">
          <button class="btn btn-sm" onclick="_rgEditer('modele', '')">✏️ Modifier le modèle commun</button>
          ${communRetouche ? `<button class="btn btn-sm" onclick="_rgReinitialiser('modele', '')">↺ Modèle Larka</button>` : ''}
        </div>
      </div>` : ''}
    </div>`;
}

// ── Éditeur ───────────────────────────────────────────────────────────────────
async function _rgEditer(portee, cle) {
  const d = _rgpdSa;
  const main = document.getElementById('mainContent');
  let perso = { identite: {}, sections: {}, ajouts: [] };
  let nom = 'Modèle commun';
  if (portee === 'org') {
    try {
      const r = await _rgApi('cle=' + encodeURIComponent(cle));
      perso = r.personnalisation || perso;
      nom = r.nom;
    } catch (e) { toast(e.message, 'error'); return; }
  } else {
    perso = d.commun || perso;
  }
  // Texte de référence de chaque section : le modèle Larka, retouché par le
  // modèle commun quand on personnalise une organisation.
  const base = {};
  Object.entries(d.modele || {}).forEach(([id, s]) => {
    base[id] = { titre: s.titre, texte: s.texte };
    if (portee === 'org' && d.commun?.sections?.[id]) Object.assign(base[id], d.commun.sections[id]);
  });
  const org = (d.organisations || []).find(o => o.cle === cle);
  const phOrg = portee === 'org' && d.multi ? (org?.nom || '') : 'ex. Commune de …';
  const ident = Object.entries(d.identite || {}).map(([k, lib]) => {
    const v = _rgEsc(perso.identite?.[k] || '');
    const champ = k === 'adresse'
      ? `<textarea class="form-control" rows="2" data-rg-id="${k}">${v}</textarea>`
      : `<input class="form-control" data-rg-id="${k}" value="${v}" placeholder="${_rgEsc(k === 'organisation' ? phOrg : (k === 'contact' ? 'ex. rgpd@organisation.fr' : (k === 'dpo' ? 'ex. Hélène Martin — dpo@organisation.fr' : '')))}">`;
    return `<div class="form-group" style="${k === 'adresse' ? 'grid-column:1/-1' : ''}"><label class="form-label">${_rgEsc(lib)}</label>${champ}</div>`;
  }).join('');

  const sections = Object.entries(base).map(([id, s]) => {
    const o = perso.sections?.[id];
    const coche = !!o;
    return `<div class="rg-sec" data-rg-sec="${_rgEsc(id)}" style="border:1px solid var(--gray-border);border-radius:10px;padding:10px 12px;margin-bottom:8px;background:var(--gray-bg)">
      <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
        <input type="checkbox" class="rg-perso" ${coche ? 'checked' : ''} onchange="_rgBascule(this)">
        <strong style="color:var(--navy)">${_rgEsc(s.titre)}</strong>
        <span style="font-size:12px;color:var(--gray-text)">— ${portee === 'org' ? 'réécrire pour cette organisation' : 'retoucher pour toutes'}</span>
      </label>
      <div class="rg-base" style="${coche ? 'display:none;' : ''}margin-top:6px;font-size:12.5px;color:var(--gray-text);white-space:pre-wrap;line-height:1.5">${_rgEsc(s.texte)}</div>
      <div class="rg-edit" style="${coche ? '' : 'display:none;'}margin-top:8px">
        <input class="form-control rg-titre" value="${_rgEsc(o?.titre || s.titre)}" maxlength="120" style="width:100%;margin-bottom:6px">
        <textarea class="form-control rg-texte" rows="7" maxlength="6000" style="width:100%;font-size:13px;line-height:1.5">${_rgEsc(o?.texte || s.texte)}</textarea>
      </div>
    </div>`;
  }).join('');

  main.innerHTML = `
    <div style="max-width:1000px;background:var(--card-bg);border:1px solid var(--gray-border);border-radius:14px;padding:18px 20px" id="rgEditeur" data-portee="${_rgEsc(portee)}" data-cle="${_rgEsc(cle)}">
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px">
        <button class="btn btn-sm" onclick="_rgListe()">← Retour</button>
        <h2 style="margin:0;font-size:18px;color:var(--navy)">🛡️ ${portee === 'org' ? 'Personnaliser — ' + _rgEsc(nom) : 'Modèle commun'}</h2>
        <div style="flex:1"></div>
        <button class="btn btn-sm" onclick="_rgApercuBrouillon()">👁 Aperçu</button>
        <button class="btn btn-sm btn-primary" onclick="_rgEnregistrer(this)">💾 Enregistrer</button>
      </div>
      <div style="font-size:12.5px;line-height:1.5;padding:10px 12px;border-radius:8px;margin-bottom:14px;${portee === 'org'
        ? 'background:var(--chip-warn-bg);color:var(--chip-warn-fg)">✎ Les sections réécrites ou ajoutées ici seront signalées aux utilisateurs : « Personnalisé par l\'entreprise », avec la date. Les coordonnées seules ne comptent pas comme une personnalisation.'
        : 'background:var(--gray-bg);color:var(--gray-text)">Les retouches du modèle commun valent pour toutes les organisations qui ne réécrivent pas la section, sans mention « personnalisé ».'}
      </div>
      <h3 style="font-size:14px;margin:0 0 8px;color:var(--navy)">Coordonnées${portee === 'modele' ? ' par défaut' : ''}</h3>
      <div class="form-grid" style="grid-template-columns:1fr 1fr;margin-bottom:14px">${ident}</div>
      <h3 style="font-size:14px;margin:0 0 4px;color:var(--navy)">Sections</h3>
      <p style="font-size:12px;color:var(--gray-text);margin:0 0 8px">
        Variables remplacées à l'affichage : <code>{organisation}</code> <code>{contact}</code> <code>{dpo}</code>
        <code>{journaux_jours}</code> <code>{session_heures}</code> <code>{fournisseur_ia}</code> <code>{fournisseurs_sso}</code>.
        Texte simple : une ligne vide sépare les paragraphes, « - » en début de ligne fait une liste ; les adresses web et électroniques deviennent des liens.
      </p>
      ${sections}
      <h3 style="font-size:14px;margin:14px 0 8px;color:var(--navy)">Sections ajoutées</h3>
      <div id="rgAjouts"></div>
      <button class="btn btn-sm" id="rgAjouter" onclick="_rgAjout()">＋ Ajouter une section</button>
      <div style="display:flex;gap:8px;justify-content:flex-end;margin:18px 0 30px">
        <button class="btn" onclick="_rgListe()">Annuler</button>
        <button class="btn" onclick="_rgApercuBrouillon()">👁 Aperçu</button>
        <button class="btn btn-primary" onclick="_rgEnregistrer(this)">💾 Enregistrer</button>
      </div>
    </div>`;
  (perso.ajouts || []).forEach(a => _rgAjout(a));
}

function _rgBascule(cb) {
  const sec = cb.closest('.rg-sec');
  sec.querySelector('.rg-base').style.display = cb.checked ? 'none' : '';
  sec.querySelector('.rg-edit').style.display = cb.checked ? '' : 'none';
  if (cb.checked) sec.querySelector('.rg-texte').focus();
}

function _rgAjout(a) {
  const zone = document.getElementById('rgAjouts');
  if (!zone) return;
  if (zone.children.length >= 6) { toast('Six sections ajoutées au plus.', 'error'); return; }
  const bloc = document.createElement('div');
  bloc.className = 'rg-ajout';
  bloc.style.cssText = 'border:1px dashed var(--gray-border);border-radius:10px;padding:10px 12px;margin-bottom:8px';
  bloc.innerHTML = `<div style="display:flex;gap:8px;margin-bottom:6px">
      <input class="form-control rg-titre" maxlength="120" placeholder="Titre de la section" style="flex:1">
      <button class="btn btn-sm" type="button" title="Retirer cette section">🗑️</button>
    </div>
    <textarea class="form-control rg-texte" rows="5" maxlength="6000" placeholder="Texte de la section" style="width:100%;font-size:13px"></textarea>`;
  bloc.querySelector('.rg-titre').value = a?.titre || '';
  bloc.querySelector('.rg-texte').value = a?.texte || '';
  bloc.querySelector('button').addEventListener('click', () => bloc.remove());
  zone.appendChild(bloc);
  if (!a) bloc.querySelector('.rg-titre').focus();
}

function _rgCollecter() {
  const ed = document.getElementById('rgEditeur');
  const donnees = { identite: {}, sections: {}, ajouts: [] };
  ed.querySelectorAll('[data-rg-id]').forEach(ch => { const v = ch.value.trim(); if (v) donnees.identite[ch.dataset.rgId] = v; });
  ed.querySelectorAll('.rg-sec').forEach(sec => {
    if (!sec.querySelector('.rg-perso').checked) return;
    const texte = sec.querySelector('.rg-texte').value.trim();
    if (texte) donnees.sections[sec.dataset.rgSec] = { titre: sec.querySelector('.rg-titre').value.trim(), texte };
  });
  ed.querySelectorAll('.rg-ajout').forEach(b => {
    const titre = b.querySelector('.rg-titre').value.trim(), texte = b.querySelector('.rg-texte').value.trim();
    if (titre && texte) donnees.ajouts.push({ titre, texte });
  });
  return { portee: ed.dataset.portee, cle: ed.dataset.cle, donnees };
}

async function _rgEnregistrer(btn) {
  const corps = _rgCollecter();
  const incomplets = [...document.querySelectorAll('#rgEditeur .rg-ajout')].filter(b =>
    !!b.querySelector('.rg-titre').value.trim() !== !!b.querySelector('.rg-texte').value.trim());
  if (incomplets.length) { toast('Une section ajoutée a besoin d\'un titre ET d\'un texte.', 'error'); return; }
  if (btn) btn.disabled = true;
  try {
    const r = await _rgApi('', corps);
    toast(corps.portee === 'org'
      ? (r.personnalisee ? 'Politique personnalisée enregistrée : elle est signalée aux utilisateurs.' : 'Enregistré : l\'organisation suit le modèle commun.')
      : 'Modèle commun enregistré.', 'success');
    _rgpdSa = await _rgApi('');
    _rgListe();
  } catch (e) {
    toast('Enregistrement impossible : ' + e.message, 'error');
  } finally {
    if (btn) btn.disabled = false;
  }
}

async function _rgApercuBrouillon() {
  try {
    const d = await _rgApi('', Object.assign(_rgCollecter(), { apercu: true }));
    LarkaRgpd.afficher({ politique: d, langue: 'fr' });
  } catch (e) { toast('Aperçu impossible : ' + e.message, 'error'); }
}

async function _rgApercuOrg(cle) {
  try {
    const r = await _rgApi('cle=' + encodeURIComponent(cle));
    LarkaRgpd.afficher({ politique: r.apercu, langue: 'fr' });
  } catch (e) { toast('Aperçu impossible : ' + e.message, 'error'); }
}

async function _rgApercuCommun() {
  try {
    const d = await _rgApi('', { portee: 'modele', apercu: true, donnees: _rgpdSa.commun || {} });
    LarkaRgpd.afficher({ politique: d, langue: 'fr' });
  } catch (e) { toast('Aperçu impossible : ' + e.message, 'error'); }
}

function _rgReinitialiser(portee, cle) {
  const org = (_rgpdSa.organisations || []).find(o => o.cle === cle);
  const msg = portee === 'org'
    ? `Effacer la personnalisation de « ${org?.nom || cle} » (sections et coordonnées) ? Elle suivra de nouveau le modèle commun.`
    : 'Effacer les retouches du modèle commun ? Toutes les organisations retrouveront le modèle Larka (sauf leurs propres sections).';
  showConfirm(msg, async () => {
    try {
      await _rgApi('', { portee, cle, reinitialiser: true });
      toast('C\'est fait.', 'success');
      _rgpdSa = await _rgApi('');
      _rgListe();
    } catch (e) { toast(e.message, 'error'); }
  }, 'Effacer');
}

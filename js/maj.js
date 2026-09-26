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
 * Larka — Mises à jour : détection automatique, installation validée à la main.
 *
 *   LarkaMaj.demarrer()   après connexion (administrateur du serveur uniquement) :
 *                         lit l'état, lance une vérification en arrière-plan si
 *                         la dernière est trop ancienne, affiche un bandeau si
 *                         une version plus récente existe.
 *   LarkaMaj.ouvrir()     fenêtre : nouveautés, contrôles, bouton Installer.
 *   LarkaMaj.verifier()   vérification manuelle (bouton « Vérifier maintenant »).
 *   LarkaMaj.panneau(el)  encadré de Configuration → Serveur : version, dernière
 *                         vérification, résultat, boutons — tout y est affiché.
 *
 * Rien ne s'installe sans clic : le bouton n'est actif qu'une fois la case
 * de confirmation cochée, et le serveur exige la version exacte affichée.
 */
const LarkaMaj = (() => {
  let _etat = null;
  let _panneau = null;       // encadré « Version et mises à jour » de Configuration → Serveur
  let _occupe = false;
  const API = (typeof API_BASE !== 'undefined' ? API_BASE : './api/index.php');

  async function appel(action, methode = 'GET', corps = null) {
    const opts = { method: methode, credentials: 'same-origin', cache: 'no-store', headers: {} };
    if (corps !== null) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(corps); }
    const r = await fetch(`${API}?action=${action}`, opts);
    const j = await r.json().catch(() => ({ success: false, error: `HTTP ${r.status}` }));
    if (!j.success) throw new Error(j.error || `HTTP ${r.status}`);
    return j.data;
  }
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  // L'application affiche ses messages avec toast() (js/ui.js). Ce module appelait
  // showToast(), qui n'existe pas : « Vérifier maintenant » ne disait RIEN quand
  // Larka était à jour ou quand la vérification échouait.
  const message = (texte, type) => {
    if (typeof toast === 'function') toast(esc(texte), type);
    else if (type === 'error') alert(texte);
  };
  const dateFr = iso => {
    if (!iso) return '';
    const d = new Date(iso);
    return isNaN(d) ? String(iso) : d.toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' });
  };

  async function demarrer() {
    try {
      _etat = await appel('maj_etat');
      if (!_etat.droits || !_etat.actif) return;
      if (_etat.a_verifier) _etat = await appel('maj_verifier', 'POST', {});
      banniere();
      rendrePanneau();
    } catch (e) { console.warn('[MAJ]', e.message); }
  }

  /**
   * Vérification manuelle. Le résultat est TOUJOURS dit : dans l'encadré de la
   * configuration s'il est affiché, sinon par un message — plus jamais un clic muet.
   */
  async function verifier() {
    if (_occupe) return _etat;
    _occupe = true;
    rendrePanneau('⏳ Interrogation de la source des versions…');
    try {
      _etat = await appel('maj_verifier', 'POST', {});
      _occupe = false;
      banniere();
      rendrePanneau();
      if (_etat.disponible) ouvrir();
      else if (!_panneau || !document.body.contains(_panneau)) {
        message(_etat.erreur ? `Vérification impossible : ${_etat.erreur}` : `Larka est à jour (${_etat.locale.libelle}).`, _etat.erreur ? 'warning' : 'success');
      }
      return _etat;
    } catch (e) {
      _occupe = false;
      rendrePanneau(null, e.message);
      if (!_panneau || !document.body.contains(_panneau)) message('Vérification impossible : ' + e.message, 'error');
      return null;
    }
  }

  /** Branche l'encadré de la configuration et affiche l'état connu (sans appel réseau vers la source). */
  async function panneau(el) {
    if (!el) return;
    _panneau = el;
    rendrePanneau('⏳ Lecture de l\'état…');
    try {
      _etat = await appel('maj_etat');
      rendrePanneau();
    } catch (e) {
      rendrePanneau(null, e.message);
    }
  }

  function rendrePanneau(attente = null, erreurAppel = null) {
    const el = _panneau;
    if (!el || !document.body.contains(el)) return;
    const e = _etat;
    const bouton = (id, libelle, primaire = false, actif = true) =>
      `<button class="btn${primaire ? ' btn-primary' : ''}" type="button" id="${id}"${actif ? '' : ' disabled'}>${libelle}</button>`;
    let etat = '', boutons = '';
    if (attente) {
      etat = `<div style="color:var(--gray-text,#64748b)">${esc(attente)}</div>`;
    } else if (erreurAppel) {
      etat = `<div style="color:#b91c1c">❌ ${esc(erreurAppel)}</div>`;
    } else if (e && e.droits === false) {
      etat = `<div style="color:var(--gray-text,#64748b)">Les mises à jour sont gérées par l'administrateur du serveur.</div>`;
    } else if (e && !e.actif) {
      etat = `<div style="color:#b45309">Détection désactivée (<code>mises_a_jour.actif = false</code> dans config.json). « Vérifier maintenant » interroge tout de même la source.</div>`;
    } else if (e && e.disponible && e.distante) {
      etat = `<div style="color:#15803d;font-weight:600">🔔 ${esc(e.distante.libelle)} est disponible${e.distante.date ? ' (publiée le ' + esc(e.distante.date) + ')' : ''}.</div>`
           + (e.erreur ? `<div style="color:#b45309;font-size:12.5px;margin-top:4px">⚠️ La dernière vérification a échoué (${esc(e.erreur)}) : information issue de la vérification précédente.</div>` : '');
    } else if (e && e.erreur) {
      etat = `<div style="color:#b45309">⚠️ Dernière vérification en échec : ${esc(e.erreur)}</div>`;
    } else if (e && e.verifie_le) {
      etat = `<div style="color:#15803d">✅ Larka est à jour.</div>`;
    } else if (e) {
      etat = `<div style="color:var(--gray-text,#64748b)">Aucune vérification pour l'instant.</div>`;
    }
    if (e && e.droits !== false && !attente) {
      const inst = e.installable || { ok: true, raisons: [] };
      if (e.disponible && !inst.ok) etat += `<div style="color:#b91c1c;font-size:12.5px;margin-top:4px">Installation impossible depuis l'interface : ${esc((inst.raisons || []).join(' '))} — sur le serveur : <code>php api/outils/mise-a-jour.php --installer</code></div>`;
      if (e.disponible && e.signature_exigee && e.distante && !e.distante.signature) etat += `<div style="color:#b91c1c;font-size:12.5px;margin-top:4px">Cette version n'est pas signée alors qu'une clé publique est configurée : son installation sera refusée.</div>`;
    }
    const peut = !!(e && e.droits !== false);
    boutons = bouton('cfgMajVerifier', _occupe ? '⏳ Vérification…' : 'Vérifier maintenant', !(e && e.disponible), peut && !_occupe)
            + (e && e.disponible ? bouton('cfgMajVoir', 'Voir et installer ' + esc(e.distante.libelle), true, !_occupe) : '')
            + bouton('cfgMajHist', 'Historique / restaurer', false, peut && !_occupe);
    const derniere = e && e.verifie_le ? `Dernière vérification : ${esc(dateFr(e.verifie_le))}` : '';
    el.innerHTML = `
      <div style="display:flex;gap:16px;align-items:baseline;flex-wrap:wrap;margin-bottom:8px">
        <span>Version installée : <strong>${esc(e && e.locale ? e.locale.libelle : '…')}</strong></span>
        <span style="font-size:12px;color:var(--gray-text,#64748b)">${derniere}</span>
      </div>
      <div style="margin-bottom:10px;font-size:13.5px">${etat}</div>
      <div style="display:flex;gap:10px;flex-wrap:wrap">${boutons}</div>`;
    el.querySelector('#cfgMajVerifier')?.addEventListener('click', verifier);
    el.querySelector('#cfgMajVoir')?.addEventListener('click', ouvrir);
    el.querySelector('#cfgMajHist')?.addEventListener('click', historique);
  }

  function banniere() {
    document.getElementById('larkaMajBanniere')?.remove();
    if (!_etat?.disponible || !_etat.distante) return;
    const v = _etat.distante.version;
    try { if (localStorage.getItem('larka_maj_plus_tard') === v + '|' + new Date().toDateString()) return; } catch (_) {}
    const b = document.createElement('div');
    b.id = 'larkaMajBanniere';
    b.style.cssText = 'position:fixed;top:12px;left:50%;transform:translateX(-50%);z-index:9500;background:#1a2e4a;color:#fff;'
      + 'padding:10px 14px;border-radius:10px;box-shadow:0 6px 24px rgba(0,0,0,.25);display:flex;gap:10px;align-items:center;'
      + 'font-size:13px;max-width:calc(100vw - 24px);flex-wrap:wrap';
    b.innerHTML = `<span>🔔 <strong>Larka ${esc(_etat.distante.libelle)}</strong> est disponible (installée : ${esc(_etat.locale.libelle)}).</span>
      <button type="button" style="background:#2b7be6;color:#fff;border:none;border-radius:6px;padding:5px 10px;cursor:pointer;font-weight:600">Voir la mise à jour</button>
      <button type="button" style="background:none;color:#cbd5e1;border:none;cursor:pointer;text-decoration:underline">Plus tard</button>`;
    const [voir, plusTard] = b.querySelectorAll('button');
    voir.onclick = ouvrir;
    plusTard.onclick = () => {
      try { localStorage.setItem('larka_maj_plus_tard', v + '|' + new Date().toDateString()); } catch (_) {}
      b.remove();
    };
    document.body.appendChild(b);
  }

  function fenetre(html) {
    document.getElementById('larkaMajFenetre')?.remove();
    const o = document.createElement('div');
    o.id = 'larkaMajFenetre';
    o.style.cssText = 'position:fixed;inset:0;z-index:9600;background:rgba(15,23,42,.55);display:flex;align-items:center;justify-content:center;padding:16px';
    o.innerHTML = `<div style="background:var(--white,#fff);color:var(--text,#1e293b);border-radius:14px;max-width:620px;width:100%;max-height:90vh;overflow:auto;padding:22px 24px;box-shadow:0 20px 60px rgba(0,0,0,.3)">${html}</div>`;
    o.addEventListener('click', e => { if (e.target === o && !o.dataset.occupe) o.remove(); });
    document.body.appendChild(o);
    return o;
  }

  function ouvrir() {
    const e = _etat;
    if (!e) return;
    if (!e.disponible) {
      fenetre(`<h3 style="margin:0 0 8px">Mises à jour</h3>
        <p>Version installée : <strong>${esc(e.locale.libelle)}</strong>. Aucune mise à jour disponible.</p>
        ${e.erreur ? `<p style="color:#b45309">Dernière vérification en échec : ${esc(e.erreur)}</p>` : ''}
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
          <button class="btn" onclick="LarkaMaj.historique()">Historique</button>
          <button class="btn" onclick="document.getElementById('larkaMajFenetre').remove()">Fermer</button></div>`);
      return;
    }
    const d = e.distante;
    const inst = e.installable || { ok: false, raisons: [] };
    const signe = d.signature ? (e.signature_exigee ? '✅ vérifiée à l\'installation' : 'présente (aucune clé publique configurée)')
                              : (e.signature_exigee ? '❌ absente — l\'installation sera refusée' : 'non signée');
    const o = fenetre(`
      <h3 style="margin:0 0 4px">Mise à jour ${esc(d.libelle)}</h3>
      <div style="font-size:12px;color:var(--gray-text,#64748b);margin-bottom:12px">Installée : ${esc(e.locale.libelle)}${d.date ? ' · publiée le ' + esc(d.date) : ''}${d.page ? ` · <a href="${esc(d.page)}" target="_blank" rel="noopener noreferrer">page de la version</a>` : ''}</div>
      <div style="font-weight:600;margin-bottom:4px">Nouveautés</div>
      <div style="white-space:pre-wrap;font-size:13px;background:var(--gray-bg,#f1f5f9);border-radius:8px;padding:10px 12px;max-height:220px;overflow:auto">${esc(d.notes || '(pas de notes de version)')}</div>
      <ul style="font-size:12.5px;line-height:1.7;margin:12px 0;padding-left:18px">
        <li>Empreinte SHA-256 : ${d.sha256 ? '✅ publiée, vérifiée avant installation' : '❌ absente — installation impossible'}</li>
        <li>Signature : ${signe}</li>
        <li><strong>Conservé tel quel :</strong> la base de données, les documents, plans et médias (<code>data/</code>), <code>config.json</code>, <code>.env</code> et les réglages des modules. Aucun fichier n'est supprimé.</li>
        <li>Une sauvegarde des fichiers remplacés (et de la base SQLite) est faite avant ; en cas d'erreur, la version actuelle est restaurée automatiquement.</li>
      </ul>
      ${inst.ok ? '' : `<div style="color:#b91c1c;font-size:13px;margin-bottom:10px">Installation impossible depuis l'interface : ${esc(inst.raisons.join(' '))}<br>Sur le serveur : <code>php api/outils/mise-a-jour.php --installer</code></div>`}
      <label style="display:flex;gap:8px;align-items:flex-start;font-size:13px;cursor:pointer">
        <input type="checkbox" id="larkaMajOk" style="margin-top:3px" ${inst.ok && d.sha256 ? '' : 'disabled'}>
        <span>J'ai lu les nouveautés et je lance l'installation de <strong>${esc(d.libelle)}</strong>. Les utilisateurs connectés devront recharger la page.</span>
      </label>
      <div id="larkaMajStatut" style="margin-top:10px;font-size:13px"></div>
      <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px;flex-wrap:wrap">
        <button class="btn" id="larkaMajHist">Historique</button>
        <button class="btn" id="larkaMajFermer">Plus tard</button>
        <button class="btn btn-primary" id="larkaMajGo" disabled>Installer maintenant</button>
      </div>`);
    const ok = o.querySelector('#larkaMajOk'), go = o.querySelector('#larkaMajGo');
    ok.onchange = () => { go.disabled = !ok.checked; };
    o.querySelector('#larkaMajFermer').onclick = () => o.remove();
    o.querySelector('#larkaMajHist').onclick = historique;
    go.onclick = () => installer(d.version, o);
  }

  async function installer(version, o) {
    const st = o.querySelector('#larkaMajStatut');
    o.dataset.occupe = '1';
    o.querySelectorAll('button,input').forEach(x => { x.disabled = true; });
    st.innerHTML = '⏳ Téléchargement, vérification, sauvegarde puis installation… Ne fermez pas cette page.';
    try {
      const r = await appel('maj_installer', 'POST', { version, confirme: true });
      delete o.dataset.occupe;
      document.getElementById('larkaMajBanniere')?.remove();
      st.innerHTML = `✅ <strong>${esc(r.libelle)}</strong> installée : ${r.fichiers} fichier(s) mis à jour dont ${r.nouveaux} nouveau(x).<br>
        Sauvegarde : <code>${esc(r.sauvegarde)}</code> · base : ${esc(r.sauvegarde_base)}`;
      const btn = o.querySelector('#larkaMajGo');
      btn.textContent = 'Recharger Larka'; btn.disabled = false; btn.onclick = () => location.reload();
      try { _etat = await appel('maj_etat'); rendrePanneau(); } catch (_) {}
    } catch (e) {
      delete o.dataset.occupe;
      st.innerHTML = `<span style="color:#b91c1c">❌ ${esc(e.message)}</span>`;
      o.querySelector('#larkaMajFermer').disabled = false;
      o.querySelector('#larkaMajHist').disabled = false;
    }
  }

  async function historique() {
    let h;
    try { h = await appel('maj_historique'); } catch (e) { alert(e.message); return; }
    const lignes = (h.installations || []).map(i => `<li>${esc((i.le || '').slice(0, 16).replace('T', ' '))} — ${i.restauration ? 'restauration ' + esc(i.restauration) : esc(i.de || '?') + ' → ' + esc(i.vers)} (${i.fichiers} fichiers, ${esc(i.par || '')})</li>`).join('') || '<li>Aucune installation.</li>';
    const sauv = (h.sauvegardes || []).map(s => `<li style="display:flex;gap:8px;align-items:center;justify-content:space-between"><code style="font-size:11.5px">${esc(s.id)}</code>
      <button class="btn btn-sm" data-id="${esc(s.id)}">Restaurer</button></li>`).join('') || '<li>Aucune sauvegarde.</li>';
    const o = fenetre(`<h3 style="margin:0 0 10px">Historique des mises à jour</h3>
      <ul style="font-size:13px;line-height:1.7;padding-left:18px">${lignes}</ul>
      <div style="font-weight:600;margin:12px 0 4px">Sauvegardes (retour à la version d'avant)</div>
      <ul style="font-size:13px;list-style:none;padding:0;display:grid;gap:6px">${sauv}</ul>
      <div id="larkaMajStatut" style="font-size:13px;margin-top:8px"></div>
      <div style="display:flex;justify-content:flex-end;margin-top:12px"><button class="btn" id="larkaMajFermer">Fermer</button></div>`);
    o.querySelector('#larkaMajFermer').onclick = () => o.remove();
    o.querySelectorAll('button[data-id]').forEach(b => b.onclick = async () => {
      if (!confirm(`Restaurer les fichiers sauvegardés « ${b.dataset.id} » ? Les données ne sont pas touchées.`)) return;
      const st = o.querySelector('#larkaMajStatut');
      st.textContent = '⏳ Restauration…';
      try {
        const r = await appel('maj_restaurer', 'POST', { id: b.dataset.id });
        st.innerHTML = `✅ ${r.fichiers} fichier(s) restauré(s) — version ${esc(r.version.libelle)}. <a href="#" onclick="location.reload();return false">Recharger</a>`;
        try { _etat = await appel('maj_etat'); rendrePanneau(); } catch (_) {}
      } catch (e) { st.innerHTML = `<span style="color:#b91c1c">❌ ${esc(e.message)}</span>`; }
    });
  }

  return { demarrer, ouvrir, verifier, historique, panneau, etat: () => _etat };
})();

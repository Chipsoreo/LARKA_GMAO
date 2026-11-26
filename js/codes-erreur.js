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
 * ═══════════════════════════════════════════════════════════════════════════
 *  Codes d'erreur — l'explication et la marche à suivre, partout où une
 *  erreur s'affiche
 * ═══════════════════════════════════════════════════════════════════════════
 *
 *  Le serveur pose un code (« CXR-002 ») sur ses erreurs et sur les entrées
 *  du journal (api/CodesErreur.php). Ce fichier en fait quelque chose de
 *  lisible :
 *
 *    codeErreurBadge(code)    une pastille cliquable « CXR-002 » ;
 *    ouvrirCodeErreur(code)   la fiche : ce que c'est, causes probables,
 *                             quoi faire dans l'ordre, qui peut agir ;
 *    ouvrirCatalogueCodes()   tous les codes, avec une recherche ;
 *    toast(…, 'error')        une erreur venue du serveur affiche son code
 *                             et un lien « Que faire ? ».
 *
 *  La fiche s'ouvre dans son propre panneau, au-dessus de tout : elle ne
 *  remplace pas la fenêtre en cours (un test de connexion, un formulaire).
 *  Le catalogue est demandé une fois, puis gardé en mémoire.
 */

const CodesErreurUI = {
  _fiches: {},
  _catalogue: null,

  async fiche(code) {
    if (!code) return null;
    if (this._fiches[code]) return this._fiches[code];
    if (this._catalogue) return this._catalogue.codes.find(c => c.code === code) || null;
    try {
      const f = await apiRequest('journal_codes&code=' + encodeURIComponent(code));
      this._fiches[code] = f;
      return f;
    } catch (_) { return null; }
  },

  async catalogue() {
    if (this._catalogue) return this._catalogue;
    this._catalogue = await apiRequest('journal_codes');
    for (const c of this._catalogue.codes || []) this._fiches[c.code] = c;
    return this._catalogue;
  },

  /** Fiches déjà connues (fournies avec une liste) : évite une requête par pastille. */
  retenir(fiches) {
    for (const [k, v] of Object.entries(fiches || {})) if (v) this._fiches[k] = v;
  },
};

const _CE_GRAVITE = {
  info:          { libelle: 'Information',   classe: 'ce-info' },
  avertissement: { libelle: 'Avertissement', classe: 'ce-avert' },
  erreur:        { libelle: 'Erreur',        classe: 'ce-erreur' },
  critique:      { libelle: 'Critique',      classe: 'ce-critique' },
};

/** Texte d'une marche à suivre : les commandes entre `…` deviennent du code. */
function _ceTexte(t) {
  return escHtml(String(t || '')).replace(/`([^`]+)`/g, '<code>$1</code>');
}

/** Pastille cliquable d'un code ; « titre » (facultatif) en infobulle. */
function codeErreurBadge(code, titre = '') {
  if (!code) return '';
  const f = CodesErreurUI._fiches[code];
  const g = _CE_GRAVITE[(f && f.gravite) || ''] || { classe: '' };
  const t = titre || (f && f.titre) || 'Explication et marche à suivre';
  return `<button type="button" class="ce-badge ${g.classe}" title="${escHtml(t)}" aria-label="${escHtml(code + ' : ' + t)}"
    onclick="event.stopPropagation();ouvrirCodeErreur(${jsArg(code)})">${escHtml(code)}</button>`;
}

/** Ferme le panneau de la fiche (Échap, clic à côté, bouton). */
function fermerCodeErreur() {
  const p = document.getElementById('ceFiche');
  if (p) p.remove();
  document.removeEventListener('keydown', _ceEchap, true);
}
function _ceEchap(e) { if (e.key === 'Escape') { e.stopPropagation(); fermerCodeErreur(); } }

/** La fiche d'un code : explication complète, causes, marche à suivre, qui agit. */
async function ouvrirCodeErreur(code, contexte = {}) {
  fermerCodeErreur();
  const p = document.createElement('div');
  p.id = 'ceFiche';
  p.className = 'ce-voile';
  p.innerHTML = `<div class="ce-panneau" role="dialog" aria-modal="true" aria-labelledby="ceTitre"><div class="ce-corps">${lk('hourglass')} Chargement…</div></div>`;
  p.addEventListener('click', e => { if (e.target === p) fermerCodeErreur(); });
  document.body.appendChild(p);
  document.addEventListener('keydown', _ceEchap, true);

  const f = await CodesErreurUI.fiche(code);
  const panneau = p.querySelector('.ce-panneau');
  if (!panneau) return;
  if (!f) {
    panneau.innerHTML = `<div class="ce-corps"><p>Code « ${escHtml(code)} » inconnu de cette version de Larka.</p>
      <div class="ce-actions"><button class="btn btn-ghost btn-sm" onclick="fermerCodeErreur()">Fermer</button></div></div>`;
    return;
  }
  const g = _CE_GRAVITE[f.gravite] || { libelle: f.gravite, classe: '' };
  const message = contexte.message ? `<div class="ce-message"><span>Message reçu</span>${escHtml(contexte.message)}</div>` : '';
  panneau.innerHTML = `
    <div class="ce-tete">
      <span class="ce-badge ce-grand ${g.classe}">${escHtml(f.code)}</span>
      <div style="flex:1;min-width:0">
        <h3 id="ceTitre">${escHtml(f.titre)}</h3>
        <div class="ce-meta">${escHtml(g.libelle)} · ${escHtml(f.domaine_libelle || f.domaine)} · à traiter par : <strong>${escHtml(f.qui)}</strong></div>
      </div>
      <button class="icon-btn" aria-label="Fermer" onclick="fermerCodeErreur()">${lk('x')}</button>
    </div>
    <div class="ce-corps">
      ${message}
      <p class="ce-description">${_ceTexte(f.description)}</p>
      <h4>${lk('search')} Causes probables</h4>
      <ul class="ce-causes">${(f.causes || []).map(c => `<li>${_ceTexte(c)}</li>`).join('')}</ul>
      <h4>${lk('wrench')} Comment y remédier</h4>
      <ol class="ce-remedes">${(f.remedes || []).map(r => `<li>${_ceTexte(r)}</li>`).join('')}</ol>
      <p class="ce-doc">${lk('book-open')} Pour aller plus loin : <code>${escHtml(f.doc)}</code></p>
      <div class="ce-actions">
        <button class="btn btn-ghost btn-sm" onclick="_ceCopier(${jsArg(f.code)}, ${jsArg(contexte.message || '')}, ${jsArg(contexte.reference || '')})">${lk('clipboard')} Copier pour le support</button>
        <button class="btn btn-ghost btn-sm" onclick="fermerCodeErreur();ouvrirCatalogueCodes()">${lk('clipboard-list')} Tous les codes</button>
        <button class="btn btn-primary btn-sm" onclick="fermerCodeErreur()">Compris</button>
      </div>
    </div>`;
  panneau.querySelector('.btn-primary')?.focus();
}

/** Le texte à coller dans un message au support : code, titre, message, référence, heure. */
async function _ceCopier(code, message, reference) {
  const f = await CodesErreurUI.fiche(code);
  const t = [`Larka — ${code} : ${f ? f.titre : ''}`, message ? `Message : ${message}` : '', reference ? `Référence : ${reference}` : '',
             `Date : ${new Date().toLocaleString('fr-FR')}`, `Page : ${location.hash || location.pathname}`].filter(Boolean).join('\n');
  try { await navigator.clipboard.writeText(t); toast('Copié : à coller dans votre message au support.', 'success'); }
  catch (_) { prompt('Copiez ce texte :', t); }
}

/** Tous les codes, par domaine, avec une recherche. */
async function ouvrirCatalogueCodes(recherche = '') {
  fermerCodeErreur();
  const p = document.createElement('div');
  p.id = 'ceFiche';
  p.className = 'ce-voile';
  p.innerHTML = `<div class="ce-panneau ce-large" role="dialog" aria-modal="true" aria-label="Codes d'erreur"><div class="ce-corps">${lk('hourglass')} Chargement…</div></div>`;
  p.addEventListener('click', e => { if (e.target === p) fermerCodeErreur(); });
  document.body.appendChild(p);
  document.addEventListener('keydown', _ceEchap, true);
  let cat;
  try { cat = await CodesErreurUI.catalogue(); }
  catch (e) { p.querySelector('.ce-corps').textContent = 'Catalogue indisponible : ' + (e.message || e); return; }
  p.querySelector('.ce-panneau').innerHTML = `
    <div class="ce-tete"><h3 style="flex:1">${lk('book-open')} Codes d'erreur de Larka (${cat.codes.length})</h3>
      <button class="icon-btn" aria-label="Fermer" onclick="fermerCodeErreur()">${lk('x')}</button></div>
    <div class="ce-corps">
      <input class="form-control" id="ceRecherche" placeholder="Code, mot du message ou de l'explication…" value="${escHtml(recherche)}" oninput="_ceFiltrer()">
      <div id="ceListe" class="ce-liste"></div>
    </div>`;
  _ceFiltrer();
  document.getElementById('ceRecherche')?.focus();
}

function _ceFiltrer() {
  const z = document.getElementById('ceListe');
  const cat = CodesErreurUI._catalogue;
  if (!z || !cat) return;
  const q = (document.getElementById('ceRecherche')?.value || '').trim().toLowerCase();
  const sans = s => String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
  const qq = sans(q);
  const garde = cat.codes.filter(c => !qq || sans([c.code, c.titre, c.description, ...(c.causes || []), ...(c.remedes || [])].join(' ')).includes(qq));
  let h = '';
  for (const [dom, lib] of Object.entries(cat.domaines)) {
    const l = garde.filter(c => c.domaine === dom);
    if (!l.length) continue;
    h += `<div class="ce-domaine">${escHtml(dom)} — ${escHtml(lib)}</div>`
       + l.map(c => `<button type="button" class="ce-ligne" onclick="ouvrirCodeErreur(${jsArg(c.code)})">
           ${codeErreurBadge(c.code)}<span>${escHtml(c.titre)}</span><span class="ce-qui">${escHtml(c.qui)}</span></button>`).join('');
  }
  z.innerHTML = h || '<div class="cx-aide" style="padding:12px">Aucun code ne correspond.</div>';
}

/**
 * Le toast d'erreur affiche le code de la dernière erreur reçue du serveur
 * quand c'est elle qu'il montre (même message, quelques secondes plus tard) :
 * aucun écran n'a eu à changer sa façon d'afficher ses erreurs.
 */
(function () {
  if (typeof toast !== 'function' || toast._avecCodes) return;
  const origine = toast;
  const avecCode = function (msg, type = '') {
    origine(msg, type);
    if (type !== 'error') return;
    const d = window._larkaDerniereErreur;
    const texte = msg == null ? '' : String(msg);
    if (!d || !d.code || Date.now() - d.t > 8000 || !texte.includes(d.message)) return;
    const z = document.getElementById('toastContainer');
    const div = z && z.lastElementChild;
    if (!div) return;
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'ce-toast-lien';
    b.textContent = d.code + ' · Que faire ?';
    b.addEventListener('click', e => { e.stopPropagation(); div.remove(); ouvrirCodeErreur(d.code, { message: d.message, reference: d.reference }); });
    div.appendChild(b);
  };
  avecCode._avecCodes = true;
  toast = avecCode;   // eslint-disable-line no-global-assign
})();

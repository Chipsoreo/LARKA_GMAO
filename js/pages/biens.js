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
 * Larka — Page : Biens mobiliers
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Gestion du parc de biens mobiliers (mobilier, informatique, etc.).
 *
 * FONCTIONNALITÉS :
 *   - Liste avec filtres, tri, pagination, recherche
 *   - Fiche détaillée : identification, localisation, affectation, finances
 *   - Gestion des états (Stock, Utilisé, Jeté/Recyclé, Don/Vendu)
 *   - Commentaire de sortie + date de sortie
 *   - Documents joints (photos, factures, etc.)
 *   - Champs obligatoires configurables via admin
 *   - Bâtiment en liste déroulante (configurable)
 *
 * POINTS D'ENTRÉE : renderBiens(), editBien(id), voirBien(id)
 * ═══════════════════════════════════════════════════════════════════════════════
 */

async function renderBiens() {
  document.getElementById('topbarActions').innerHTML = '';
  const c = document.getElementById('mainContent');
  showLoading(c);
  try {
    const allData = await BiensApi.getAll();
    // Charger la compta pour vérifier si les biens sortis ont été clôturés
    let comptaClotures = new Set();
    try {
      const compta = await GestionMaterielApi.getAll();
      compta.filter(cp => cp.AssetType === 'Bien' && cp.EstCloture == 1)
            .forEach(cp => comptaClotures.add(cp.AssetId));
    } catch(e) {}
    // Exclure uniquement les biens sortis ET clôturés en compta → les autres restent visibles
    const data = allData.filter(b => {
      if (!['Jete/Recycle','Don/Vendu'].includes(b.Etat)) return true;
      return !comptaClotures.has(b.Id); // sorti mais pas encore clôturé → garder
    });
    renderTable({
      container: c, data,
      columns: [
        { key:'Numero',      label:'N° Bien',     editFn:'editBien', deleteFn:'deleteBien' },
        { key:'Famille',     label:'Famille' },
        { key:'SousFamille', label:'Sous-famille' },
        { key:'Statut',      label:'Statut',  badge:true },
        { key:'Etat',        label:'État',    render: v => {
          if (v === 'Don/Vendu')     return '<span class="badge badge-blue">📤 Don/Vendu</span>';
          if (v === 'Jete/Recycle')  return '<span class="badge badge-orange">🗑️ Jeté/Recyclé</span>';
          return v || '—';
        }},
        { key:'Prix',        label:'Prix',    type:'money' },
        { key:'Batiment',    label:'Bâtiment' },
        { key:'NomPrenom',   label:'Affecté à' },
      ],
      addBtnFn: canEdit() ? 'editBien' : null,
      extraButtons: canEdit() ? '<button class="btn btn-ghost btn-sm" onclick="openImportBiens()" title="Importer des biens depuis un fichier Excel">📥 Importer</button>' : '',
      searchTerm: App.searchTerm, currentFilter: App.currentFilter,
      canEdit: canEdit(), canDelete: canDelete(),
    });
  } catch(e) { c.innerHTML = errorHtml(e.message); }
}

async function voirBien(id) { return editBien(id, true); }

async function editBien(id, readOnly = false) {
  const isNew = !id;
  const [b, familles, sousFamilles, statuts, etats, batiments, etages, _rf] = await Promise.all([
    isNew ? Promise.resolve({}) : BiensApi.getAll().then(d => d.find(x => x.Id === id) || {}),
    ListesApi.getByCategorie('FamilleBien'),
    ListesApi.getByCategorie('SousFamilleBien'),
    ListesApi.getByCategorie('StatutBien'),
    ListesApi.getByCategorie('EtatAsset'),
    ListesApi.getByCategorie('Batiment'),
    ListesApi.getByCategorie('Etage'),
    getRequiredFields('biens'),
  ]);
  const rf = _rf || [];
  // Afficher un bandeau info si bien sorti (mais fiche reste éditable)
  const isSorti = ['Jete/Recycle','Don/Vendu'].includes(b.Etat);
  const dis = readOnly ? 'disabled' : '';

  const titlePrefix = readOnly ? '👁️ Consultation —' : (isNew ? 'Nouveau bien' : 'Bien —');
  openModal(`${titlePrefix} ${escHtml(b.Numero||'')}`, `
  ${readOnly ? '<div style="padding:8px 12px;background:#fef3c7;border-radius:6px;margin-bottom:12px;font-size:12px;color:#92400e">🔒 Fiche en consultation seule</div>' : ''}
  ${!readOnly && isSorti ? '<div style="padding:8px 12px;background:#fef3c7;border-radius:6px;margin-bottom:12px;font-size:12px;color:#92400e">⚠️ Bien sorti du parc — Fiche toujours modifiable</div>' : ''}
  <div style="display:flex;flex-direction:column;gap:16px">

    <div>
      <div class="section-label">Identification</div>
      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">${reqLabel('Numéro','Numero',rf)}</label>
          <input class="form-control" id="f_numero" value="${escHtml(b.Numero||'')}" ${dis} ${reqAttr('Numero',rf)}>
        </div>
        <div class="form-group">
          <label class="form-label">${listLabelHtml('Famille', familles)}</label>
          ${readOnly ? `<input class="form-control" value="${escHtml(b.Famille||'')}" disabled>` : listFieldHtml('f_famille', familles, b.Famille)}
        </div>
        <div class="form-group">
          <label class="form-label">${listLabelHtml('Sous-famille', sousFamilles)}</label>
          ${readOnly ? `<input class="form-control" value="${escHtml(b.SousFamille||'')}" disabled>` : listFieldHtml('f_sousFamille', sousFamilles, b.SousFamille, {emptyOption:'—'})}
        </div>
        <div class="form-group">
          <label class="form-label">${listLabelHtml('Statut', statuts)}</label>
          ${readOnly ? `<input class="form-control" value="${escHtml(b.Statut||'')}" disabled>` : listFieldHtml('f_statut', statuts, b.Statut)}
        </div>
        <div class="form-group">
          <label class="form-label">${reqLabel('N° Série','NumeroSerie',rf)}</label>
          <input class="form-control" id="f_numeroSerie" value="${escHtml(b.NumeroSerie||'')}" ${dis} ${reqAttr('NumeroSerie',rf)}>
        </div>
        <div class="form-group">
          <label class="form-label">${listLabelHtml('État', etats)}</label>
          ${readOnly ? `<input class="form-control" value="${escHtml(b.Etat||'')}" disabled>` : listFieldHtml('f_etat', etats, b.Etat)}
        </div>
        <div class="form-group span-2">
          <label class="form-label">Informations produit</label>
          <textarea class="form-control" id="f_infoProduit" rows="2" ${dis}>${escHtml(b.InfoProduit||'')}</textarea>
        </div>
      </div>
    </div>

    <div>
      <div class="section-label">Localisation & Affectation</div>
      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">${listLabelHtml('Bâtiment', batiments)}</label>
          ${readOnly ? `<input class="form-control" value="${escHtml(b.Batiment||'')}" disabled>` : (batiments.length ? listFieldHtml('f_batiment', batiments, b.Batiment, {emptyOption:'—'}) : `<input class="form-control" id="f_batiment" value="${escHtml(b.Batiment||'')}" ${dis}>`)}
        </div>
        <div class="form-group">
          <label class="form-label">${reqLabel('Étage','Etage',rf)}</label>
          ${readOnly ? `<input class="form-control" value="${escHtml(b.Etage||'')}" disabled>` : (etages.length ? listFieldHtml('f_etage', etages, b.Etage, {emptyOption:'—'}) : `<input class="form-control" id="f_etage" value="${escHtml(b.Etage||'')}" ${dis} ${reqAttr('Etage',rf)}>`)}
        </div>
        <div class="form-group">
          <label class="form-label">${reqLabel('N° Bureau','NumeroBureau',rf)}</label>
          <input class="form-control" id="f_numeroBureau" value="${escHtml(b.NumeroBureau||'')}" ${dis} ${reqAttr('NumeroBureau',rf)}>
        </div>
        <div class="form-group">
          <label class="form-label">${reqLabel('Affecté à','NomPrenom',rf)}</label>
          <div style="position:relative">
            <input class="form-control" id="f_nomPrenom" value="${escHtml(b.NomPrenom||'')}" ${dis} ${reqAttr('NomPrenom',rf)}
              autocomplete="off" placeholder="Taper un nom…"
              oninput="_bienSearchUser(this.value)"
              onfocus="_bienSearchUser(this.value)"
            >
            <input type="hidden" id="f_affecteUserId" value="${b.AffecteUserId||''}">
            <div id="f_nomPrenom_dropdown" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:9999;max-height:200px;overflow-y:auto;background:var(--card-bg,#fff);border:1px solid var(--gray-border);border-radius:0 0 8px 8px;box-shadow:0 4px 12px rgba(0,0,0,.12)"></div>
          </div>
        </div>
      </div>
    </div>

    <div id="sortieCommentaireBien" style="${['Jete/Recycle','Don/Vendu'].includes(b.Etat)?'':'display:none'}">
      <div class="section-label">💬 Sortie</div>
      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Date de sortie</label>
          <input class="form-control" type="date" id="f_dateSortie" value="${b.DateSortie||''}" ${dis}>
        </div>
        <div class="form-group span-2" style="grid-column:span 2">
          <label class="form-label">Commentaire de sortie</label>
          <textarea class="form-control" id="f_commentaireSortie" rows="2" placeholder="Raison de sortie, destination…" style="width:100%" ${dis}>${escHtml(b.CommentaireSortie||'')}</textarea>
        </div>
      </div>
    </div>

    <div>
      <div class="section-label">Finances</div>
      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Date commande</label>
          <input class="form-control" type="date" id="f_dateCommande" value="${b.DateCommande||''}" ${dis}>
        </div>
        <div class="form-group">
          <label class="form-label">Date livraison</label>
          <input class="form-control" type="date" id="f_dateLivraison" value="${b.DateLivraison||''}" ${dis}>
        </div>
        <div class="form-group">
          <label class="form-label">${reqLabel('Prix (€)','Prix',rf)}</label>
          <input class="form-control" type="number" step="0.01" id="f_prix" value="${b.Prix||0}" ${dis}>
        </div>
      </div>
    </div>

    ${!readOnly ? `<div>
      <div class="section-label">Documents joints</div>
      ${isNew ? docsPanelHtml('Bien', 0, 'Bien') : docsPanelHtml('Bien', id)}
    </div>` : ''}

    ${!isNew ? '<div id="bienAncrageModules"></div>' : ''}

  </div>`, readOnly ? null : async () => {
    // Validation champs obligatoires
    const fieldMap = { Numero:'f_numero', NumeroSerie:'f_numeroSerie', Batiment:'f_batiment', Etage:'f_etage', NumeroBureau:'f_numeroBureau', NomPrenom:'f_nomPrenom', Prix:'f_prix' };
    for (const key of rf) {
      const fid = fieldMap[key];
      if (fid && !gv(fid).trim()) {
        const lbl = (CHAMPS_FORMULAIRE?.biens||[]).find(c=>c.key===key)?.label || key;
        toast(`Le champ « ${lbl} » est obligatoire.`, 'error'); return;
      }
    }
    const numero = gv('f_numero');
    const payload = {
      numero, famille: gv('f_famille'), sousFamille: gv('f_sousFamille'),
      statut: gv('f_statut'), etat: gv('f_etat'), numeroSerie: gv('f_numeroSerie'),
      dateCommande: gv('f_dateCommande'), dateLivraison: gv('f_dateLivraison'),
      prix: parseFloat(gv('f_prix'))||0,
      batiment: gv('f_batiment'), etage: gv('f_etage'),
      numeroBureau: gv('f_numeroBureau'), nomPrenom: gv('f_nomPrenom'),
      affecteUserId: gv('f_affecteUserId') || null,
      infoProduit: gv('f_infoProduit'),
      commentaireSortie: gv('f_commentaireSortie') || '',
      dateSortie: gv('f_dateSortie') || '',
    };
    try {
      let newId;
      if (isNew) {
        const result = await BiensApi.create(payload);
        newId = result?.id || result;
        if (newId) await uploadPendingDocs('Bien', 'Bien', newId);
      }
      else await BiensApi.update(id, payload);
      toast(isNew ? 'Bien créé.' : 'Bien modifié.', 'success');
      closeModal(); renderBiens();
    } catch(e) { toast(e.message, 'error'); }
  }, 'Enregistrer', readOnly ? 'Fermer' : 'Annuler');

  // Charger les documents après ouverture du modal
  setTimeout(() => {
    // Blocs des modules communautaires sur la fiche d'un bien.
    if (typeof LarkaExtensions !== 'undefined' && document.getElementById('bienAncrageModules')) {
      try {
        LarkaExtensions.rendrePoint('bien.fiche',
          document.getElementById('bienAncrageModules'), { bienId: id });
      } catch (e) { console.warn('Extensions (fiche bien) :', e); }
    }

    if (!readOnly) {
      if (isNew) { window._docsPending['Bien'] = []; }
      initDocsPanel('Bien', id, 'Bien');
    }
    // Show/hide commentaire sortie based on état
    const etatEl = document.getElementById('f_etat');
    if (etatEl && !readOnly) {
      const updateSortie = () => {
        const sortieDiv = document.getElementById('sortieCommentaireBien');
        if (sortieDiv) sortieDiv.style.display = ['Jete/Recycle','Don/Vendu'].includes(etatEl.value) ? '' : 'none';
      };
      etatEl.addEventListener('change', updateSortie);
      etatEl.addEventListener('input', updateSortie);
    }
  }, 150);
}

async function deleteBien(id) {
  showConfirm('Supprimer ce bien ?', async () => {
    try { await BiensApi.delete(id); toast('Bien supprimé.'); renderBiens(); }
    catch(e) { toast(e.message, 'error'); }
  });
}
// ═══════════════════════════════════════════════════════════════════════════════
//  Autocomplete « Affecté à » — recherche utilisateurs
// ═══════════════════════════════════════════════════════════════════════════════
let _bienUsersCache = null;
let _bienSearchTimer = null;

async function _bienSearchUser(query) {
  const dropdown = document.getElementById('f_nomPrenom_dropdown');
  if (!dropdown) return;

  // Réinitialiser l'UserId quand l'utilisateur tape manuellement
  const hidden = document.getElementById('f_affecteUserId');
  if (hidden) hidden.value = '';

  clearTimeout(_bienSearchTimer);
  _bienSearchTimer = setTimeout(async () => {
    // Charger le cache utilisateurs une seule fois
    if (!_bienUsersCache) {
      try { _bienUsersCache = await apiRequest('utilisateurs_liste'); }
      catch(_) { _bienUsersCache = []; }
    }

    const q = (query || '').toLowerCase().trim();
    if (!q) { dropdown.style.display = 'none'; return; }

    // Filtrer les utilisateurs actifs
    const results = _bienUsersCache
      .filter(u => u.Actif != 0)
      .filter(u => {
        const full = ((u.Prenom || '') + ' ' + (u.Nom || '')).toLowerCase();
        const rev  = ((u.Nom || '') + ' ' + (u.Prenom || '')).toLowerCase();
        const login = (u.Login || '').toLowerCase();
        return full.includes(q) || rev.includes(q) || login.includes(q);
      })
      .slice(0, 8);

    if (!results.length) {
      dropdown.innerHTML = '<div style="padding:10px 14px;font-size:12px;color:var(--gray-text)">Aucun utilisateur trouvé — saisie libre conservée</div>';
      dropdown.style.display = 'block';
      return;
    }

    dropdown.innerHTML = results.map(u => {
      const nom = ((u.Prenom || '') + ' ' + (u.Nom || '')).trim();
      const esc = s => String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
      return `<div class="ac-user-item" data-uid="${u.Id}" data-nom="${esc(nom)}"
        style="padding:8px 14px;cursor:pointer;font-size:13px;border-bottom:1px solid var(--gray-border);transition:background .15s"
        onmouseover="this.style.background='var(--gray-bg)'"
        onmouseout="this.style.background=''">
        <strong>${esc(nom)}</strong>
        <span style="color:var(--gray-text);font-size:11px;margin-left:8px">${esc(u.Role)} · ${esc(u.Login)}</span>
      </div>`;
    }).join('');
    // Event delegation pour les clics
    dropdown.querySelectorAll('.ac-user-item').forEach(el => {
      el.addEventListener('click', () => _bienSelectUser(el.dataset.uid, el.dataset.nom));
    });
    dropdown.style.display = 'block';
  }, 150);
}

function _bienSelectUser(userId, nom) {
  const input = document.getElementById('f_nomPrenom');
  const hidden = document.getElementById('f_affecteUserId');
  const dropdown = document.getElementById('f_nomPrenom_dropdown');
  if (input)    input.value = nom;
  if (hidden)   hidden.value = userId;
  if (dropdown) dropdown.style.display = 'none';
}

// Fermer le dropdown quand on clique ailleurs
document.addEventListener('click', function(e) {
  const dropdown = document.getElementById('f_nomPrenom_dropdown');
  if (dropdown && !e.target.closest('#f_nomPrenom') && !e.target.closest('#f_nomPrenom_dropdown')) {
    dropdown.style.display = 'none';
  }
});

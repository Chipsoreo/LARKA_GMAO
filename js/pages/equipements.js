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
 * Larka — Page : Équipements techniques
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Gestion des équipements techniques (plomberie, électricité, CVC, SSI…).
 *
 * FONCTIONNALITÉS :
 *   - Même structure que les biens + champs Marque, Modèle, Fournisseur
 *   - Date d'installation
 *   - Liaison possible avec les interventions et contrats
 *
 * POINTS D'ENTRÉE : renderEquipements(), editEquip(id), voirEquip(id)
 * ═══════════════════════════════════════════════════════════════════════════════
 */

async function renderEquipements() {
  document.getElementById('topbarActions').innerHTML = '';
  const c = document.getElementById('mainContent');
  showLoading(c);
  try {
    const allData = await EquipementsApi.getAll();
    // Charger la compta pour vérifier si les équipements sortis ont été clôturés
    let comptaClotures = new Set();
    try {
      const compta = await GestionMaterielApi.getAll();
      compta.filter(cp => cp.AssetType === 'Equipement' && cp.EstCloture == 1)
            .forEach(cp => comptaClotures.add(cp.AssetId));
    } catch(e) {}
    // Exclure uniquement les équipements sortis ET clôturés en compta
    const data = allData.filter(e => {
      if (!['Jete/Recycle','Don/Vendu'].includes(e.Etat)) return true;
      return !comptaClotures.has(e.Id);
    });
    renderTable({
      container: c, data,
      columns: [
        { key:'Numero',      label:'N° Équip.',   editFn:'editEquip', deleteFn:'deleteEquip' },
        { key:'Famille',     label:'Famille' },
        { key:'Marque',      label:'Marque' },
        { key:'Modele',      label:'Modèle' },
        { key:'Statut',      label:'Statut',      badge:true },
        { key:'Etat',        label:'État',    render: v => {
          if (v === 'Don/Vendu')     return '<span class="badge badge-blue">📤 Don/Vendu</span>';
          if (v === 'Jete/Recycle')  return '<span class="badge badge-orange">🗑️ Jeté/Recyclé</span>';
          return v || '—';
        }},
        { key:'Fournisseur', label:'Fournisseur' },
        { key:'Prix',        label:'Prix',         type:'money' },
        { key:'Batiment',    label:'Bâtiment' },
      ],
      addBtnFn: canEdit() ? 'editEquip' : null,
      extraButtons: canEdit() ? '<button class="btn btn-ghost btn-sm" onclick="openImportEquipements()" title="Importer des équipements depuis un fichier Excel">📥 Importer</button>' : '',
      searchTerm: App.searchTerm, currentFilter: App.currentFilter,
      canEdit: canEdit(), canDelete: canDelete(),
    });
  } catch(e) { c.innerHTML = errorHtml(e.message); }
}

async function voirEquip(id) { return editEquip(id, true); }

async function editEquip(id, readOnly = false) {
  const isNew = !id;
  const [eq, familles, sousFamilles, statuts, etats, batiments, _rf] = await Promise.all([
    isNew ? Promise.resolve({}) : EquipementsApi.getAll().then(d => d.find(x => x.Id === id) || {}),
    ListesApi.getByCategorie('FamilleEquipement'),
    ListesApi.getByCategorie('SousFamilleEquipement'),
    ListesApi.getByCategorie('StatutEquipement'),
    ListesApi.getByCategorie('EtatAsset'),
    ListesApi.getByCategorie('Batiment'),
    getRequiredFields('equipements'),
  ]);
  const rf = _rf || [];
  // Forcer readonly si équipement sorti
  const isSorti = ['Jete/Recycle','Don/Vendu'].includes(eq.Etat);
  const dis = readOnly ? 'disabled' : '';

  const titlePrefix = readOnly ? '👁️ Consultation —' : (isNew ? 'Nouvel équipement' : 'Équipement —');
  openModal(`${titlePrefix} ${escHtml(eq.Numero||'')}`, `
  ${readOnly ? '<div style="padding:8px 12px;background:#fef3c7;border-radius:6px;margin-bottom:12px;font-size:12px;color:#92400e">🔒 Fiche en consultation seule</div>' : ''}
  ${!readOnly && isSorti ? '<div style="padding:8px 12px;background:#fef3c7;border-radius:6px;margin-bottom:12px;font-size:12px;color:#92400e">⚠️ Équipement sorti du parc — Fiche toujours modifiable</div>' : ''}
  <div style="display:flex;flex-direction:column;gap:16px">

    <div>
      <div class="section-label">Identification</div>
      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">${reqLabel('Numéro','Numero',rf)}</label>
          <input class="form-control" id="f_numero" value="${escHtml(eq.Numero||'')}" ${dis}>
        </div>
        <div class="form-group">
          <label class="form-label">${listLabelHtml('Famille', familles)}</label>
          ${readOnly ? `<input class="form-control" value="${escHtml(eq.Famille||'')}" disabled>` : listFieldHtml('f_famille', familles, eq.Famille)}
        </div>
        <div class="form-group">
          <label class="form-label">${listLabelHtml('Sous-famille', sousFamilles)}</label>
          ${readOnly ? `<input class="form-control" value="${escHtml(eq.SousFamille||'')}" disabled>` : listFieldHtml('f_sousFamille', sousFamilles, eq.SousFamille, {emptyOption:'—'})}
        </div>
        <div class="form-group">
          <label class="form-label">${listLabelHtml('Statut', statuts)}</label>
          ${readOnly ? `<input class="form-control" value="${escHtml(eq.Statut||'')}" disabled>` : listFieldHtml('f_statut', statuts, eq.Statut)}
        </div>
        <div class="form-group">
          <label class="form-label">${reqLabel('N° Série','NumeroSerie',rf)}</label>
          <input class="form-control" id="f_numeroSerie" value="${escHtml(eq.NumeroSerie||'')}" ${dis} ${reqAttr('NumeroSerie',rf)}>
        </div>
        <div class="form-group">
          <label class="form-label">${listLabelHtml('État', etats)}</label>
          ${readOnly ? `<input class="form-control" value="${escHtml(eq.Etat||'')}" disabled>` : listFieldHtml('f_etat', etats, eq.Etat)}
        </div>
        <div class="form-group">
          <label class="form-label">${reqLabel('Marque','Marque',rf)}</label>
          <input class="form-control" id="f_marque" value="${escHtml(eq.Marque||'')}" ${dis} ${reqAttr('Marque',rf)}>
        </div>
        <div class="form-group">
          <label class="form-label">${reqLabel('Modèle','Modele',rf)}</label>
          <input class="form-control" id="f_modele" value="${escHtml(eq.Modele||'')}" ${dis} ${reqAttr('Modele',rf)}>
        </div>
        <div class="form-group">
          <label class="form-label">Fournisseur</label>
          <input class="form-control" id="f_fournisseur" value="${escHtml(eq.Fournisseur||'')}" ${dis}>
        </div>
      </div>
    </div>

    <div>
      <div class="section-label">Localisation</div>
      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">${listLabelHtml('Bâtiment', batiments)}</label>
          ${readOnly ? `<input class="form-control" value="${escHtml(eq.Batiment||'')}" disabled>` : (batiments.length ? listFieldHtml('f_batiment', batiments, eq.Batiment, {emptyOption:'—'}) : `<input class="form-control" id="f_batiment" value="${escHtml(eq.Batiment||'')}" ${dis}>`)}
        </div>
        <div class="form-group">
          <label class="form-label">Étage</label>
          <input class="form-control" id="f_etage" value="${escHtml(eq.Etage||'')}" ${dis}>
        </div>
        <div class="form-group">
          <label class="form-label">Bureau / Emplacement</label>
          <input class="form-control" id="f_numeroBureau" value="${escHtml(eq.NumeroBureau||'')}" ${dis}>
        </div>
        <div class="form-group">
          <label class="form-label">Affecté à</label>
          <div style="position:relative">
            <input class="form-control" id="f_nomPrenom" value="${escHtml(eq.NomPrenom||'')}" ${dis}
              autocomplete="off" placeholder="Taper un nom…"
              oninput="_bienSearchUser(this.value)"
              onfocus="_bienSearchUser(this.value)"
            >
            <div id="f_nomPrenom_dropdown" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:9999;max-height:200px;overflow-y:auto;background:var(--card-bg,#fff);border:1px solid var(--gray-border);border-radius:0 0 8px 8px;box-shadow:0 4px 12px rgba(0,0,0,.12)"></div>
          </div>
        </div>
      </div>
    </div>

    <div>
      <div class="section-label">Dates & Prix</div>
      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Date commande</label>
          <input class="form-control" type="date" id="f_dateCommande" value="${eq.DateCommande||''}" ${dis}>
        </div>
        <div class="form-group">
          <label class="form-label">Date installation</label>
          <input class="form-control" type="date" id="f_dateInstallation" value="${eq.DateInstallation||''}" ${dis}>
        </div>
        <div class="form-group">
          <label class="form-label">Prix (€)</label>
          <input class="form-control" type="number" step="0.01" id="f_prix" value="${eq.Prix||0}" ${dis}>
        </div>
      </div>
    </div>

    <div>
      <div class="section-label">Observations</div>
      <textarea class="form-control" id="f_observations" rows="2" style="width:100%" ${dis}>${eq.Observations||''}</textarea>
    </div>

    <div id="sortieCommentaireEquip" style="${['Jete/Recycle','Don/Vendu'].includes(eq.Etat)?'':'display:none'}">
      <div class="section-label">💬 Sortie</div>
      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Date de sortie</label>
          <input class="form-control" type="date" id="f_dateSortie" value="${eq.DateSortie||''}" ${dis}>
        </div>
        <div class="form-group span-2" style="grid-column:span 2">
          <label class="form-label">Commentaire de sortie</label>
          <textarea class="form-control" id="f_commentaireSortie" rows="2" placeholder="Raison de sortie, destination…" style="width:100%" ${dis}>${escHtml(eq.CommentaireSortie||'')}</textarea>
        </div>
      </div>
    </div>

    ${!readOnly ? `<div>
      <div class="section-label">Documents joints</div>
      ${isNew ? docsPanelHtml('Equipement', 0, 'Equipement') : docsPanelHtml('Equipement', id)}
    </div>` : ''}

    ${!isNew ? '<div id="eqAncrageModules"></div>' : ''}

  </div>`, readOnly ? null : async () => {
    const numero = gv('f_numero');
    if (!numero) { toast('Le numéro est obligatoire.', 'error'); return; }
    const payload = {
      numero, famille: gv('f_famille'), sousFamille: gv('f_sousFamille'),
      statut: gv('f_statut'), etat: gv('f_etat'), numeroSerie: gv('f_numeroSerie'),
      marque: gv('f_marque'), modele: gv('f_modele'), fournisseur: gv('f_fournisseur'),
      dateCommande: gv('f_dateCommande'), dateInstallation: gv('f_dateInstallation'),
      prix: parseFloat(gv('f_prix'))||0,
      batiment: gv('f_batiment'), etage: gv('f_etage')||'',
      numeroBureau: gv('f_numeroBureau'), nomPrenom: gv('f_nomPrenom')||'',
      observations: gv('f_observations'),
      commentaireSortie: gv('f_commentaireSortie') || '',
      dateSortie: gv('f_dateSortie') || '',
    };
    try {
      let newId;
      if (isNew) {
        const result = await EquipementsApi.create(payload);
        newId = result?.id || result;
        if (newId) await uploadPendingDocs('Equipement', 'Equipement', newId);
      }
      else await EquipementsApi.update(id, payload);
      toast(isNew ? 'Équipement créé.' : 'Équipement modifié.', 'success');
      closeModal(); renderEquipements();
    } catch(e) { toast(e.message, 'error'); }
  }, 'Enregistrer', readOnly ? 'Fermer' : 'Annuler');

  setTimeout(() => {
    if (!readOnly) {
      if (isNew) { window._docsPending['Equipement'] = []; }
      initDocsPanel('Equipement', id, 'Equipement');
    }

    // Blocs des modules communautaires. Après l'ouverture de la fiche, sinon le
    // conteneur n'existe pas encore ; jamais sur une création, faute
    // d'identifiant auquel rattacher quoi que ce soit.
    if (!isNew && typeof LarkaExtensions !== 'undefined') {
      try {
        LarkaExtensions.rendrePoint('equipement.fiche',
          document.getElementById('eqAncrageModules'), { equipementId: id });
      } catch (e) { console.warn('Extensions (fiche équipement) :', e); }
    }
    const etatEl = document.getElementById('f_etat');
    if (etatEl && !readOnly) {
      const updateSortie = () => {
        const sortieDiv = document.getElementById('sortieCommentaireEquip');
        if (sortieDiv) sortieDiv.style.display = ['Jete/Recycle','Don/Vendu'].includes(etatEl.value) ? '' : 'none';
      };
      etatEl.addEventListener('change', updateSortie);
      etatEl.addEventListener('input', updateSortie);
    }
  }, 150);
}

async function deleteEquip(id) {
  showConfirm('Supprimer cet équipement ?', async () => {
    try { await EquipementsApi.delete(id); toast('Équipement supprimé.'); renderEquipements(); }
    catch(e) { toast(e.message, 'error'); }
  });
}
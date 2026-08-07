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
 * Larka — Page : Gestion matériel / Immobilisations
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Gestion matérielle des biens et équipements.
 *
 * FONCTIONNALITÉS :
 *   - Fiches d'immobilisation (numéro immo, compte, exercice, mandat)
 *   - Valeur d'achat, valeur vénale, durée d'amortissement
 *   - Calcul d'annualité et dépréciation
 *   - Clôture de fiches matériel
 *   - Liaison automatique avec les biens et équipements
 *
 * POINT D'ENTRÉE : renderGestionMateriel()
 * ═══════════════════════════════════════════════════════════════════════════════
 */

const ETATS_SORTIE = ['Jete/Recycle', 'Don/Vendu'];
let _gmShowSortis = false;

function _escC(s) { return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

async function renderGestionMateriel() {
  document.getElementById('topbarActions').innerHTML = '';
  const c = document.getElementById('mainContent');
  showLoading(c);
  try {
    const data = await GestionMaterielApi.getAll();
    data.forEach(cp => {
      cp._assetDisplay = `${cp.AssetType === 'Bien' ? '🏢' : '⚙️'} ${escHtml(cp.AssetNumero || '')} ${escHtml(cp.AssetLabel || '')}`.trim();
      cp._isSorti = ETATS_SORTIE.includes(cp.AssetEtat);
      cp._isCloture = cp.EstCloture == 1;
    });

    const actifs  = data.filter(d => !d._isSorti && !d._isCloture);
    const sortis  = data.filter(d => d._isSorti && !d._isCloture);

    let shown = [...actifs];
    if (_gmShowSortis) shown = shown.concat(sortis);

    const nbActifs = actifs.length;
    const nbSortis = sortis.length;
    const totalActifs = actifs.reduce((s, d) => s + (parseFloat(d.ValeurAchat) || 0), 0);

    const search = (App.searchTerm || '').toLowerCase();
    const filtered = shown.filter(d => {
      const matchSearch = !search || JSON.stringify(d).toLowerCase().includes(search);
      const matchAdvanced = FiltresEngine.matchRow('gestion_materiel', d);
      return matchSearch && matchAdvanced;
    });

    const isEdit = canEdit();
    const isDel  = canDelete();

    c.innerHTML = `
      <div class="card" style="padding:18px 22px;margin-bottom:16px">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
          <div style="display:flex;gap:20px;flex-wrap:wrap">
            <div style="font-size:13px"><strong>${nbActifs}</strong> actif${nbActifs>1?'s':''} <span style="color:var(--gray-text)">(${totalActifs.toLocaleString('fr-FR',{style:'currency',currency:'EUR'})})</span></div>
            ${nbSortis > 0 ? `<div style="font-size:13px;color:var(--red)"><strong>${nbSortis}</strong> sorti${nbSortis>1?'s':''}</div>` : ''}
          </div>
          <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <div class="search-input-wrap" style="min-width:220px">
              <span class="search-icon">🔍</span>
              <input class="search-input" type="text" placeholder="Rechercher…"
                value="${App.searchTerm||''}" oninput="App.handleSearch(this.value)" onkeydown="App.handleSearchKey(event)">
            </div>
            ${FiltresEngine.renderButton('gestion_materiel')}
            ${nbSortis > 0 ? `
              <label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer;color:var(--gray-text)">
                <input type="checkbox" ${_gmShowSortis?'checked':''} onchange="_gmShowSortis=this.checked;renderGestionMateriel()">
                Afficher les sortis (${nbSortis})
              </label>
            ` : ''}
            ${isEdit ? `<button class="btn btn-primary btn-sm" onclick="editGM()">+ Nouvelle fiche</button>` : ''}
          </div>
        </div>
        ${FiltresEngine.renderPanel('gestion_materiel')}
      </div>

      <div class="card" style="overflow:auto">
        <table style="width:100%;border-collapse:collapse;font-size:13px">
          <thead>
            <tr>
              <th style="padding:10px 12px;text-align:left;font-size:11px;text-transform:uppercase;color:var(--gray-text);border-bottom:2px solid var(--gray-border)">N° Immo</th>
              <th style="padding:10px 12px;text-align:left;font-size:11px;text-transform:uppercase;color:var(--gray-text);border-bottom:2px solid var(--gray-border)">Bien / Équipement</th>
              <th style="padding:10px 12px;text-align:left;font-size:11px;text-transform:uppercase;color:var(--gray-text);border-bottom:2px solid var(--gray-border)">État</th>
              <th style="padding:10px 12px;text-align:left;font-size:11px;text-transform:uppercase;color:var(--gray-text);border-bottom:2px solid var(--gray-border)">Compte</th>
              <th style="padding:10px 12px;text-align:left;font-size:11px;text-transform:uppercase;color:var(--gray-text);border-bottom:2px solid var(--gray-border)">Exercice</th>
              <th style="padding:10px 12px;text-align:left;font-size:11px;text-transform:uppercase;color:var(--gray-text);border-bottom:2px solid var(--gray-border)">Date achat</th>
              <th style="padding:10px 12px;text-align:right;font-size:11px;text-transform:uppercase;color:var(--gray-text);border-bottom:2px solid var(--gray-border)">Valeur achat</th>
              <th style="padding:10px 12px;text-align:right;font-size:11px;text-transform:uppercase;color:var(--gray-text);border-bottom:2px solid var(--gray-border)">Amort.</th>
              <th style="padding:10px 12px;text-align:center;font-size:11px;text-transform:uppercase;color:var(--gray-text);border-bottom:2px solid var(--gray-border)">Actions</th>
            </tr>
          </thead>
          <tbody>
            ${filtered.length === 0 ? `<tr><td colspan="9" style="padding:30px;text-align:center;color:var(--gray-text)">Aucune fiche matériel.</td></tr>` : ''}
            ${filtered.map(d => {
              const isSorti = d._isSorti;
              const rowStyle = isSorti ? 'opacity:0.6;background:rgba(220,53,69,0.04)' : '';
              const etatBadge = isSorti
                ? `<span class="badge badge-red" style="font-size:10px">\u{1F6AB} ${_escC(d.AssetEtat)}</span>`
                : (d.AssetEtat ? `<span class="badge badge-teal" style="font-size:10px">\u2705 ${_escC(d.AssetEtat)}</span>` : '<span style="color:var(--gray-text)">\u2014</span>');
              const commentaire = isSorti && d.AssetCommentaireSortie
                ? `<div style="font-size:10px;color:var(--red);margin-top:2px" title="${_escC(d.AssetCommentaireSortie)}">\u{1F4AC} ${_escC(d.AssetCommentaireSortie.substring(0,40))}${d.AssetCommentaireSortie.length>40?'\u2026':''}</div>`
                : '';
              const dateAchat = d.DateAchat ? new Date(d.DateAchat).toLocaleDateString('fr-FR') : '\u2014';
              const valeur = d.ValeurAchat ? parseFloat(d.ValeurAchat).toLocaleString('fr-FR',{style:'currency',currency:'EUR'}) : '\u2014';
              return `<tr style="border-bottom:1px solid var(--gray-border);cursor:pointer;${rowStyle}" onclick="editGM(${d.Id})">
                <td style="padding:10px 12px;font-weight:600">${_escC(d.NumeroImmo || '\u2014')}</td>
                <td style="padding:10px 12px">
                  ${_escC(d._assetDisplay || '\u2014')}
                  ${commentaire}
                </td>
                <td style="padding:10px 12px">${etatBadge}</td>
                <td style="padding:10px 12px">${_escC(d.CompteImmo || '\u2014')}</td>
                <td style="padding:10px 12px">${_escC(d.Exercice || '\u2014')}</td>
                <td style="padding:10px 12px">${dateAchat}</td>
                <td style="padding:10px 12px;text-align:right">${valeur}</td>
                <td style="padding:10px 12px;text-align:right">${d.DureeAmortissement ? d.DureeAmortissement + ' ans' : '\u2014'}</td>
                <td style="padding:10px 12px;text-align:center" onclick="event.stopPropagation()">
                  ${isEdit ? `<button class="btn btn-sm" onclick="editGM(${d.Id})" title="Modifier">\u270F\uFE0F</button>` : ''}
                  ${isEdit ? (d._isSorti
                    ? `<button class="btn btn-sm" onclick="cloturerGMFromGM(${d.Id})" title="Clôturer définitivement" style="color:var(--orange)">🔒</button>`
                    : `<button class="btn btn-sm" disabled title="Clôture impossible : le bien doit être Jeté/Recyclé ou Don/Vendu" style="opacity:.35;cursor:not-allowed">🔒</button>`)
                    : ''}
                  ${isDel && isSorti ? `<button class="btn btn-sm" onclick="deleteGM(${d.Id})" title="Retirer de la liste">\u{1F5D1}\uFE0F</button>` : ''}
                </td>
              </tr>`;
            }).join('')}
          </tbody>
        </table>
      </div>
    `;
  } catch (e) { c.innerHTML = errorHtml(e.message); }
}

function editGM(id) {
  const isNew = !id;
  Promise.all([
    BiensApi.getAll(),
    EquipementsApi.getAll(),
    GestionMaterielApi.getAll(),
    isNew ? Promise.resolve({}) : GestionMaterielApi.getAll().then(d => d.find(x => x.Id === id) || {}),
  ]).then(([biens, equips, allGM, cp]) => {

    // Filtrer les assets déjà enregistrés en gestion matériel (sauf celui en cours d'édition)
    const dejaBienIds  = new Set(allGM.filter(r => r.AssetType === 'Bien'       && r.Id !== (cp.Id||0)).map(r => r.AssetId));
    const dejaEquipIds = new Set(allGM.filter(r => r.AssetType === 'Equipement' && r.Id !== (cp.Id||0)).map(r => r.AssetId));

    const biensDispos  = biens.filter(b => !dejaBienIds.has(b.Id));
    const equipsDispos = equips.filter(e => !dejaEquipIds.has(e.Id));

    const bienOpts  = biensDispos.map(b => {
      const sorti = ETATS_SORTIE.includes(b.Etat);
      return `<option value="Bien:${b.Id}" ${cp.AssetType === 'Bien' && cp.AssetId === b.Id ? 'selected' : ''}>${escHtml(b.Numero||'')}${b.InfoProduit ? ' — '+b.InfoProduit : (b.Famille ? ' — '+b.Famille : '')}${sorti ? ' \u{1F6AB}' : ''}</option>`;
    }).join('');
    const equipOpts = equipsDispos.map(e => {
      const sorti = ETATS_SORTIE.includes(e.Etat);
      return `<option value="Equipement:${e.Id}" ${cp.AssetType === 'Equipement' && cp.AssetId === e.Id ? 'selected' : ''}>${escHtml(e.Numero||'')}${e.Marque ? ' — '+e.Marque : ''}${e.Modele ? ' '+e.Modele : ''}${sorti ? ' \u{1F6AB}' : ''}</option>`;
    }).join('');

    const isSorti = ETATS_SORTIE.includes(cp.AssetEtat);
    const alerteSortie = isSorti ? `
      <div style="background:var(--red-light,rgba(220,53,69,0.08));border:1px solid rgba(220,53,69,0.3);border-radius:8px;padding:12px 16px;margin-bottom:16px;display:flex;align-items:flex-start;gap:10px">
        <span style="font-size:18px">\u{1F6AB}</span>
        <div>
          <div style="font-weight:700;color:var(--red);font-size:13px">Actif sorti \u2014 ${_escC(cp.AssetEtat)}</div>
          ${cp.AssetCommentaireSortie ? `<div style="font-size:12px;color:var(--gray-text);margin-top:4px">\u{1F4AC} ${_escC(cp.AssetCommentaireSortie)}</div>` : ''}
          <div style="font-size:11px;color:var(--gray-text);margin-top:4px">Vous pouvez supprimer cette fiche de la gestion mat\u00e9riel si l\u2019actif est d\u00e9finitivement sorti.</div>
        </div>
      </div>
    ` : '';

    openModal(isNew ? 'Nouvelle fiche matériel' : `Modifier ${escHtml(cp.NumeroImmo || 'fiche')}`, `
      ${alerteSortie}
      <div class="modal-tabs">
        <button type="button" class="tab-btn active" data-tab="tab-general">G\u00e9n\u00e9ral</button>
        <button type="button" class="tab-btn" data-tab="tab-extra">Informations suppl\u00e9mentaires</button>
      </div>

      <div id="tab-general" class="tab-panel active">
        <div class="form-grid">
          <div class="form-group span-2">
            <label class="form-label">Bien / \u00c9quipement li\u00e9 <span class="req">*</span></label>
            <select class="form-control" id="f_asset">
              <option value=""></option>
              <optgroup label="🏢 Biens">${bienOpts}</optgroup>
              <optgroup label="⚙️ Équipements">${equipOpts}</optgroup>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">N\u00b0 Immobilisation</label>
            <input class="form-control" id="f_numeroImmo" value="${escHtml(cp.NumeroImmo || '')}">
          </div>
          <div class="form-group">
            <label class="form-label">Compte immo.</label>
            <input class="form-control" id="f_compteImmo" value="${escHtml(cp.CompteImmo || '')}">
          </div>
          <div class="form-group">
            <label class="form-label">Exercice</label>
            <input class="form-control" id="f_exercice" value="${cp.Exercice || new Date().getFullYear()}">
          </div>
          <div class="form-group">
            <label class="form-label">Bon de commande</label>
            <input class="form-control" id="f_bc" value="${escHtml(cp.BonDeCommande || '')}">
          </div>
          <div class="form-group">
            <label class="form-label">Date d\u2019achat</label>
            <input class="form-control" type="date" id="f_dateAchat" value="${cp.DateAchat || ''}">
          </div>
          <div class="form-group">
            <label class="form-label">Date mise en service</label>
            <input class="form-control" type="date" id="f_dateMES" value="${cp.DateMiseEnService || ''}">
          </div>
          <div class="form-group">
            <label class="form-label">Date bascule</label>
            <input class="form-control" type="date" id="f_dateBascule" value="${cp.DateBascule || ''}">
          </div>
          <div class="form-group">
            <label class="form-label">N\u00b0 Mandat</label>
            <input class="form-control" id="f_mandat" value="${escHtml(cp.NumeroMandat || '')}">
          </div>
          <div class="form-group">
            <label class="form-label">Valeur d\u2019achat (\u20ac)</label>
            <input class="form-control" type="number" id="f_valeurAchat" value="${cp.ValeurAchat || 0}">
          </div>
          <div class="form-group">
            <label class="form-label">Valeur v\u00e9nale (\u20ac)</label>
            <input class="form-control" type="number" id="f_valeurVenale" value="${cp.ValeurVenale || 0}">
          </div>
          <div class="form-group">
            <label class="form-label">Dur\u00e9e amortissement (ans)</label>
            <input class="form-control" type="number" id="f_dureeAmort" value="${cp.DureeAmortissement || 5}">
          </div>
          <div class="form-group">
            <label class="form-label">Montant du march\u00e9 (\u20ac)</label>
            <input class="form-control" type="number" id="f_montantMarche" value="${cp.MontantDuMarche || 0}">
          </div>
        </div>
      </div>

      <div id="tab-extra" class="tab-panel">
        <div class="section-label">Informations suppl\u00e9mentaire</div>
        <div class="form-grid">
          <div class="form-group">
            <label class="form-label">Annuit\u00e9 d\u2019amortissement</label>
            <input class="form-control" type="number" id="f_annualite" readonly placeholder="\u2014">
          </div>
          <div class="form-group">
            <label class="form-label">D\u00e9pr\u00e9ciation total</label>
            <input class="form-control" type="number" id="f_depreciationTotal" readonly placeholder="\u2014">
          </div>
          <div class="form-group">
            <label class="form-label">Date calcul d\u00e9pr\u00e9ciation</label>
            <input class="form-control" type="date" id="f_dateCalcDep" value="${cp.DateCalculDepreciation || ''}">
          </div>
          <div class="form-group">
            <label class="form-label">User7</label>
            <input class="form-control" id="f_user7" value="${cp.User7 || ''}">
          </div>
          <div class="form-group span-2">
            <label class="form-label">Item</label>
            <input class="form-control" id="f_item" value="${cp.Item || ''}">
          </div>
        </div>
        <div class="section-label">Informatique</div>
        <div class="form-grid">
          <div class="form-group">
            <label class="form-label">Auteur derni\u00e8re maj</label>
            <input class="form-control" id="f_meta_auteurMaj" readonly placeholder="\u2014">
          </div>
          <div class="form-group">
            <label class="form-label">Date derni\u00e8re maj</label>
            <input class="form-control" id="f_meta_dateMaj" readonly placeholder="\u2014">
          </div>
          <div class="form-group">
            <label class="form-label">Identifiant premi\u00e8re cr\u00e9ation</label>
            <input class="form-control" id="f_meta_creator" readonly placeholder="\u2014">
          </div>
          <div class="form-group">
            <label class="form-label">Identifiant derni\u00e8re modification</label>
            <input class="form-control" id="f_meta_lastModifier" readonly placeholder="\u2014">
          </div>
        </div>
      </div>
    `, async () => {
      const assetParts = gv('f_asset').split(':');
      const payload = {
        assetType: assetParts[0], assetId: parseInt(assetParts[1]),
        numeroImmo: gv('f_numeroImmo'), compteImmo: gv('f_compteImmo'),
        exercice: gv('f_exercice'), bonDeCommande: gv('f_bc'),
        dateAchat: gv('f_dateAchat'), dateMiseEnService: gv('f_dateMES'),
        dateBascule: gv('f_dateBascule'),
        numeroMandat: gv('f_mandat'),
        valeurAchat:        parseFloat(gv('f_valeurAchat'))     || 0,
        valeurVenale:       parseFloat(gv('f_valeurVenale'))    || 0,
        dureeAmortissement: parseInt(gv('f_dureeAmort'))        || 0,
        montantDuMarche:    parseFloat(gv('f_montantMarche'))   || 0,
        dateCalculDepreciation: gv('f_dateCalcDep'),
        user7: gv('f_user7'),
        item:  gv('f_item'),
      };
      try {
        if (isNew) await GestionMaterielApi.create(payload);
        else       await GestionMaterielApi.update(id, payload);
        toast(isNew ? 'Fiche cr\u00e9\u00e9e.' : 'Fiche modifi\u00e9e.', 'success');
        closeModal();
        renderGestionMateriel();
      } catch (e) { toast(e.message, 'error'); }
    });

    // Tabs
    const tabs = document.querySelectorAll('.tab-btn');
    tabs.forEach(btn => {
      btn.addEventListener('click', () => {
        tabs.forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
        document.getElementById(btn.dataset.tab).classList.add('active');
      });
    });

    setTimeout(() => {
      if (window.enhanceSelectTypeahead) enhanceSelectTypeahead('f_asset', { placeholder: 'Rechercher un bien / \u00e9quipement\u2026', allowEmpty: false });
    }, 0);

    const biensById  = Object.fromEntries(biens.map(b => [String(b.Id), b]));
    const equipsById = Object.fromEntries(equips.map(e => [String(e.Id), e]));

    const setRO = (fid, v) => {
      const el = document.getElementById(fid);
      if (!el) return;
      el.value = v || '';
      el.placeholder = v ? '' : '\u2014';
    };

    const getSelectedAsset = () => {
      const parts = gv('f_asset').split(':');
      const t = parts[0] || '';
      const aid = parts[1] || '';
      if (t === 'Bien') return biensById[aid] || null;
      if (t === 'Equipement') return equipsById[aid] || null;
      return null;
    };

    const refreshAssetMeta = () => {
      const a = getSelectedAsset();
      if (!a) { setRO('f_meta_auteurMaj',''); setRO('f_meta_dateMaj',''); setRO('f_meta_creator',''); setRO('f_meta_lastModifier',''); return; }
      setRO('f_meta_auteurMaj', a.UpdatedBy || '');
      setRO('f_meta_dateMaj',  a.UpdatedAt || '');
      setRO('f_meta_creator',  a.CreatedBy || '');
      setRO('f_meta_lastModifier', a.UpdatedBy || '');
    };

    const toISO = (d) => d.toISOString().slice(0,10);
    const yearsBetween = (startISO, endISO) => {
      if (!startISO) return 0;
      const d0 = new Date(startISO);
      const d1 = endISO ? new Date(endISO) : new Date();
      if (isNaN(d0.getTime()) || isNaN(d1.getTime()) || d1 < d0) return 0;
      return (d1 - d0) / 86400000 / 365.25;
    };

    const refreshDerived = () => {
      const va = parseFloat(gv('f_valeurAchat')) || 0;
      const d  = parseInt(gv('f_dureeAmort')) || 0;
      const annualite = (va > 0 && d > 0) ? Math.round((va / d) * 100) / 100 : 0;
      let calcDate = gv('f_dateCalcDep');
      if (!calcDate) { calcDate = toISO(new Date()); sv('f_dateCalcDep', calcDate); }
      const start = gv('f_dateBascule') || gv('f_dateMES') || gv('f_dateAchat');
      const years = yearsBetween(start, calcDate);
      let dep = annualite * years;
      if (dep > va) dep = va;
      dep = Math.round(dep * 100) / 100;
      sv('f_annualite', annualite ? annualite : '');
      sv('f_depreciationTotal', dep ? dep : '');
    };

    document.getElementById('f_asset')?.addEventListener('change', refreshAssetMeta);
    ['f_valeurAchat','f_dureeAmort','f_dateAchat','f_dateMES','f_dateBascule','f_dateCalcDep'].forEach(fid => {
      document.getElementById(fid)?.addEventListener('input', refreshDerived);
      document.getElementById(fid)?.addEventListener('change', refreshDerived);
    });

    if (!gv('f_dateCalcDep')) sv('f_dateCalcDep', toISO(new Date()));
    refreshAssetMeta();
    refreshDerived();
  });
}

async function deleteGM(id) {
  // Vérifier que l'actif est en sortie avant de supprimer
  try {
    const all = await GestionMaterielApi.getAll();
    const fiche = all.find(x => x.Id === id);
    if (fiche && !ETATS_SORTIE.includes(fiche.AssetEtat)) {
      toast('Impossible de supprimer : l\'actif n\'est pas sorti du parc (Don/Vendu ou Jeté/Recyclé).', 'error');
      return;
    }
  } catch(_) {}
  showConfirm('Supprimer cette fiche matériel ? Cette action est irréversible.', async () => {
    try { await GestionMaterielApi.delete(id); toast('Fiche supprimée.'); renderGestionMateriel(); }
    catch (e) { toast(e.message, 'error'); }
  });
}

// Clôture depuis la page gestion matériel : ouvre modal, puis déplace vers historique
async function cloturerGMFromGM(id) {
  // Vérifier que l'actif est bien sorti avant de permettre la clôture
  try {
    const allGM = await GestionMaterielApi.getAll();
    const fiche = allGM.find(f => f.Id === id);
    if (fiche && !ETATS_SORTIE.includes(fiche.AssetEtat)) {
      toast('Clôture impossible : le bien/équipement doit être en état « Jeté/Recyclé » ou « Don/Vendu » avant de pouvoir être clôturé.', 'error');
      return;
    }
  } catch(e) { /* continue si erreur de vérification */ }

  openModal('🔒 Clôturer définitivement', `
  <div class="form-grid cols-1">
    <div class="form-group">
      <label class="form-label">Motif de clôture (optionnel)</label>
      <textarea class="form-control" id="f_motifCloture" rows="3"
        placeholder="Ex: Bien entièrement amorti, sorti d'inventaire…"></textarea>
    </div>
    <div style="background:var(--gray-bg);border:1px solid var(--gray-border);border-radius:8px;padding:12px;font-size:12px;color:var(--text-mid)">
      ⚠️ Cette action est <strong>définitive</strong>. La fiche sera archivée dans l'historique
      et disparaîtra de la vue gestion matériel.
    </div>
  </div>`, async () => {
    const motif = document.getElementById('f_motifCloture')?.value || '';
    try {
      await GestionMaterielApi.cloturer(id, motif);
      toast('Fiche clôturée — visible dans l\'historique.', 'success');
      closeModal();
      renderGestionMateriel();
    } catch(e) { toast(e.message, 'error'); }
  }, 'Clôturer définitivement');
}

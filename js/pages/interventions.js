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
 * Larka — Page : Interventions de maintenance
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Gestion des interventions préventives et curatives.
 *
 * FONCTIONNALITÉS :
 *   - Création/modification avec liaison bien OU équipement (sélecteur unifié)
 *   - Liaison avec contrat de maintenance et/ou société externe
 *   - Suite à une demande d'intervention (import)
 *   - Consommation de pièces du stock (lignes IntervStockLignes)
 *   - Cycle de vie : Planifiée → En cours → Réalisée → Validée → Archivée
 *   - Auto-numérotation (INT-YYYYMM-NNNN)
 *   - Documents joints
 *
 * POINTS D'ENTRÉE : renderInterventions(), editInterv(id)
 * ═══════════════════════════════════════════════════════════════════════════════
 */

function joursDepuisDate(d) {
  return d ? Math.floor((Date.now() - new Date(d).getTime()) / 864e5) : 0;
}
const fmtMon = v => new Intl.NumberFormat('fr-FR',{style:'currency',currency:'EUR'}).format(v||0);
const fmtD   = d => d ? new Date(d).toLocaleDateString('fr-FR') : '—';

// Cache des données de la liste (évite un re-fetch à chaque frappe de recherche).
// `equipements` sert à afficher la famille/sous-famille d'une intervention liée
// à un équipement : contrairement aux biens, la table Equipements n'est pas
// jointe côté SQL (EquipementsIds est une liste d'ids en texte).
let _intervCache = { data: [], biens: [], equipements: [] };
let _intervSearchTimer = null;

// Libellé métier d'un bien ou d'un équipement : « Famille / Sous-famille ».
// On ne retombe sur InfoProduit (description commerciale) que si les deux sont
// vides, pour ne pas afficher une ligne nue sur les fiches importées.
function _assetLabel(a) {
  if (!a) return '';
  const fam  = (a.Famille || '').trim();
  const sfam = (a.SousFamille || '').trim();
  if (fam && sfam) return `${fam} / ${sfam}`;
  return fam || sfam || (a.InfoProduit || '').trim();
}

// Texte d'une option du sélecteur « Bien / Équipement concerné ».
// Format : « N° — Famille / Sous-famille · Marque Info produit (📍 Bâtiment) ».
// La partie après « · » n'est là que pour la recherche typeahead : supprimer la
// ligne `detail` ci-dessous pour ne garder que la nomenclature famille.
function _optAssetText(a) {
  if (!a) return '';
  const lbl  = _assetLabel(a);
  const info = (a.InfoProduit || '').trim();
  // Si le libellé est déjà retombé sur l'info produit, ne pas la répéter.
  const detail = [(a.Marque || '').trim(), (info && info !== lbl) ? info : '']
    .filter(Boolean).join(' ');
  return [
    a.Numero || '—',
    lbl    ? '— ' + lbl    : '',
    detail ? '· ' + detail : '',
    a.Batiment ? '(📍 ' + a.Batiment + ')' : '',
  ].filter(Boolean).join(' ');
}

async function renderInterventions() {
  document.getElementById('topbarActions').innerHTML = '';
  const c = document.getElementById('mainContent');
  showLoading(c);
  try {
    const [data, biens, equipements] = await Promise.all([
      InterventionsApi.getAll(),
      BiensApi.getAll(),
      EquipementsApi.getAll().catch(() => []),
    ]);
    _intervCache = { data, biens, equipements };

    const perPage = App.getRowsPerPage();

    let html = `
    <div class="card" style="padding:0;margin-bottom:16px">
      <div class="search-bar" style="gap:8px">
        <div class="search-input-wrap" style="flex:1;max-width:480px">
          <span class="search-icon">🔍</span>
          <input class="search-input" type="text" placeholder="Rechercher (n°, type, CBDC, description…)"
            value="${App.searchTerm||''}" oninput="liveSearchInterv(this.value)">
        </div>
        ${FiltresEngine.renderButton('interventions')}
        <select class="form-control" style="font-size:13px;width:auto;padding:6px 10px" onchange="(e=>{const[k,d]=e.target.value.split(':');App._sortKey=k||null;App._sortDir=d||'asc';App.renderCurrentPage()})(event)">
          <option value="">↕ Trier par…</option>
          <option value="Numero:asc" ${App._sortKey==='Numero'&&App._sortDir==='asc'?'selected':''}>N° croissant</option>
          <option value="Numero:desc" ${App._sortKey==='Numero'&&App._sortDir==='desc'?'selected':''}>N° décroissant</option>
          <option value="Statut:asc" ${App._sortKey==='Statut'?'selected':''}>Statut</option>
          <option value="Type:asc" ${App._sortKey==='Type'?'selected':''}>Type</option>
          <option value="Priorite:desc" ${App._sortKey==='Priorite'?'selected':''}>Priorité</option>
          <option value="DateRealisation:desc" ${App._sortKey==='DateRealisation'?'selected':''}>Date (récent)</option>
          <option value="DateRealisation:asc" ${App._sortKey==='DateRealisation'&&App._sortDir==='asc'?'selected':''}>Date (ancien)</option>
        </select>

        <select class="form-control" style="font-size:13px;width:auto;padding:6px 10px" onchange="App.setRowsPerPage(this.value)" title="Lignes par page">
          <option value="10"  ${perPage===10?'selected':''}>10</option>
          <option value="25"  ${perPage===25?'selected':''}>25</option>
          <option value="50"  ${perPage===50?'selected':''}>50</option>
          <option value="100" ${perPage===100?'selected':''}>100</option>
          <option value="200" ${perPage===200?'selected':''}>200</option>
          <option value="all" ${perPage==='all'?'selected':''}>Tout</option>
        </select>
        <div style="flex:1"></div>
        ${canEdit() ? `<button class="btn btn-primary" onclick="editInterv(0)">+ Nouvelle</button>` : ''}
      </div>
      ${FiltresEngine.renderPanel('interventions')}
    </div>
    <div id="intervListBody">${_buildIntervListBody()}</div>`;

    c.innerHTML = html;
    App.restoreFilters();
  } catch(e) { c.innerHTML = errorHtml(e.message); }
}

// Recherche en direct : ne re-render QUE le corps de liste (la barre de
// recherche reste en place → l'input garde le focus), sans appel réseau.
function liveSearchInterv(val) {
  App.searchTerm = val;
  clearTimeout(_intervSearchTimer);
  _intervSearchTimer = setTimeout(() => {
    App.setPageSilently(1); // nouvelle recherche → on revient page 1
    const el = document.getElementById('intervListBody');
    if (el) el.innerHTML = _buildIntervListBody();
  }, 120);
}

// Construit le corps de la liste (filtrage + recherche + tri + pagination +
// lignes). Séparé de renderInterventions pour permettre la recherche live.
function _buildIntervListBody() {
  const data   = _intervCache.data || [];
  const search = (App.searchTerm||'').toLowerCase();

  // Index des équipements par Id : permet de résoudre EquipementsIds (texte)
  // en famille/sous-famille sans appel réseau supplémentaire.
  const equipById = new Map((_intervCache.equipements || []).map(e => [String(e.Id), e]));
  const equipDeInterv = (r) => {
    const first = String(r.EquipementsIds || '').split(',').map(s => s.trim()).filter(Boolean)[0];
    return first ? equipById.get(first) : null;
  };

  let filtered = data.filter(r => FiltresEngine.matchRow('interventions', r));
  if (search) filtered = filtered.filter(r => {
    const eq = equipDeInterv(r);
    return [r.Numero, r.Type, r.Description, r.SocieteManuelle, r.BienNumero, r.DemandeTitre,
            r.ContratSociete, r.TypeBudget, r.CbdcFactures,
            r.BienFamille, r.BienSousFamille,
            eq && eq.Numero, eq && eq.Famille, eq && eq.SousFamille]
      .some(v => (v||'').toLowerCase().includes(search));
  });
  // Tri
  if (App._sortKey) {
    const k = App._sortKey, d = App._sortDir === 'asc' ? 1 : -1;
    filtered.sort((a,b) => {
      let va = a[k]??'', vb = b[k]??'';
      if (!isNaN(parseFloat(va)) && !isNaN(parseFloat(vb))) return (parseFloat(va)-parseFloat(vb))*d;
      return String(va).localeCompare(String(vb),'fr',{sensitivity:'base'})*d;
    });
  }

  // Pagination (après filtrage/recherche)
  const perPage = App.getRowsPerPage();
  const total   = filtered.length;
  const totalPages = (perPage === 'all') ? 1 : Math.max(1, Math.ceil(total / perPage));
  let page = App.getPage();
  if (page > totalPages) { page = totalPages; App.setPageSilently(page); }
  const startIdx = (perPage === 'all') ? 0 : (page - 1) * perPage;
  const pageData = (perPage === 'all') ? filtered : filtered.slice(startIdx, startIdx + perPage);
  const from = total === 0 ? 0 : (startIdx + 1);
  const to   = total === 0 ? 0 : (startIdx + pageData.length);

  if (!total) return `<div class="card" style="padding:40px;text-align:center;color:var(--gray-text)">Aucune intervention.</div>`;

  const statutColors = {Planifiée:'blue','En cours':'orange','Réalisée':'cyan',Validée:'teal',Archivée:'gray'};
  const statutLabels = {Planifiée:'Planifiée','En cours':'En cours','Réalisée':'Réalisée',Validée:'Terminée',Archivée:'Archivée'};
  const typeColors   = {Préventive:'blue',Curative:'orange','Contrôle réglementaire':'purple',Divers:'gray'};

  let html = `<div style="display:flex;flex-direction:column;gap:10px">`;
    pageData.forEach(inv => {
      const coutTotal = (parseFloat(inv.MontantPieces)||0) + (parseFloat(inv.MontantMainOeuvre)||0) + (parseFloat(inv.Montant)||0) + (parseFloat(inv.MontantHT)||0);
      const estValidee = inv.Statut === 'Validée';
      const joursValid = joursDepuisDate(inv.DateValidation);
      const peutArchiver = estValidee && joursValid >= 7;
      const peutArchiverManuel = estValidee && joursValid < 7;
      const bSt = s => `<span class="badge badge-${statutColors[s]||'gray'}">${statutLabels[s]||s}</span>`;
      const bTy = t => `<span class="badge badge-${typeColors[t]||'gray'}">${t||'—'}</span>`;

      html += `
      <div class="card" style="padding:14px 18px">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px">
          <div style="flex:1;min-width:0">
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:5px">
              ${bSt(inv.Statut)} ${bTy(inv.Type)}
              ${inv.Priorite?`<span class="badge badge-red">${escHtml(inv.Priorite)}</span>`:''}
              <code style="font-size:12px;color:var(--blue);background:var(--gray-bg);padding:2px 6px;border-radius:4px">${escHtml(inv.Numero)}</code>
              ${inv.DateRealisation?`<span style="font-size:11px;color:var(--gray-text)">${fmtD(inv.DateRealisation)}</span>`:''}
            </div>
            <div style="font-size:14px;font-weight:600;margin-bottom:4px">${escHtml(inv.Description)}</div>
            <div style="font-size:12px;color:var(--gray-text);display:flex;gap:14px;flex-wrap:wrap">
              ${(function(){
                // Bien lié : « N° — Famille / Sous-famille » (BienLabel est
                // calculé côté SQL). Équipement lié : même logique, résolue
                // depuis le cache local puisque la table n'est pas jointe.
                if (inv.BienNumero) {
                  const lbl = inv.BienLabel || '';
                  return `<span title="Bien lié">🏢 ${escHtml(inv.BienNumero)}${lbl?' — '+escHtml(lbl):''}</span>`;
                }
                if (inv.EquipementsIds) {
                  const eq  = equipDeInterv(inv);
                  const lbl = _assetLabel(eq);
                  if (!eq) return `<span>⚙️ Équipement lié</span>`;
                  return `<span title="Équipement lié">⚙️ ${escHtml(eq.Numero||'')}${lbl?' — '+escHtml(lbl):''}</span>`;
                }
                return '';
              })()}
              ${inv.ContratSociete?`<span>📋 ${escHtml(inv.ContratSociete)}</span>`:inv.SocieteManuelle?`<span>🏭 ${escHtml(inv.SocieteManuelle)}</span>`:''}
              ${inv.DemandeTitre?`<span title="Demande liée">📝 ${escHtml(inv.DemandeTitre)}</span>`:''}
              ${coutTotal>0?`<span style="color:var(--blue);font-weight:600">${fmtMon(coutTotal)}</span>`:''}
              ${inv.TypeBudget?`<span title="Suivi administratif" style="color:var(--teal);font-weight:600">📄 ${escHtml(inv.TypeBudget)}${inv.DateEnvoiMail?` <span style="color:var(--gray-text);font-weight:400">(envoyé le ${fmtD(inv.DateEnvoiMail)})</span>`:''}</span>`:''}
              ${(!inv.TypeBudget && inv.SansSuiviAdmin)?`<span title="Cette intervention ne nécessite pas de CBDC/CHMA ni de dates de suivi" style="color:var(--gray-text)">🚫 Sans suivi admin.</span>`:''}
              ${(parseFloat(inv.MontantHT)||0)>0?`<span title="Prix HT (suivi administratif)" style="color:var(--teal);font-weight:600">💶 ${fmtMon(inv.MontantHT)} HT</span>`:''}
              ${(parseInt(inv.NbFactures)||0)>0?`<span title="Total facturé — ${inv.NbFactures} facture${(parseInt(inv.NbFactures)||0)>1?'s':''} rattachée${(parseInt(inv.NbFactures)||0)>1?'s':''}" style="color:var(--purple,#7c3aed);font-weight:600">🧾 ${fmtMon(inv.TotalFacture)}${inv.CbdcFactures?` · ${escHtml([...new Set((inv.CbdcFactures||'').split(', ').filter(Boolean))].join(', '))}`:''}</span>`:''}
              ${(parseInt(inv.NbInterventionsLiees)||0)>0?`<span title="Partage une facture avec ${inv.NbInterventionsLiees} autre(s) intervention(s)" style="color:var(--purple,#7c3aed);font-weight:600">🔗 liée à ${inv.NbInterventionsLiees} interv.</span>`:''}
            </div>
            ${estValidee && !peutArchiver?`<div style="font-size:11px;color:var(--orange);margin-top:3px">⏱ Archivable dans ${7-joursValid}j</div>`:''}
            ${inv.Statut === 'En cours' && (inv.AgentPrenom || inv.AgentNom) ? `
              <div style="font-size:11px;color:var(--blue);margin-top:3px;display:flex;align-items:center;gap:4px">
                👤 Pris en charge par <strong>${[inv.AgentPrenom,inv.AgentNom].filter(Boolean).join(' ')}</strong>
                ${inv.AgentEmail ? `<span style="color:var(--gray-text)">(${escHtml(inv.AgentEmail||'')})</span>` : ''}
              </div>` : ''}
          </div>
          <div style="display:flex;flex-direction:column;gap:6px;flex-shrink:0;align-items:flex-end">
            <div style="display:flex;gap:6px">
              ${canEdit()?`<button class="icon-btn" onclick="editInterv(${inv.Id})" title="Modifier">✏️</button>`:''}
              ${canDelete()?`<button class="icon-btn delete" onclick="deleteInterv(${inv.Id})" title="Supprimer">🗑️</button>`:''}
            </div>
            ${(function(){
              if (!canEdit() || !['En cours','Réalisée'].includes(inv.Statut)) return '';
              // Pré-requis de clôture : les 3 champs admin doivent être remplis
              // dans le formulaire d'édition (CBDC/CHMA, date de réalisation,
              // date d'envoi du mail). Si ce n'est pas le cas, on affiche un
              // bouton désactivé avec un tooltip qui explique quoi compléter.
              // Exception : la case « Pas de suivi administratif » de la fiche
              // dispense l'intervention de ces 3 champs.
              const manquants = [];
              if (!inv.SansSuiviAdmin) {
                if (!inv.TypeBudget)      manquants.push('CBDC/CHMA');
                if (!inv.DateRealisation) manquants.push('Date réalisation');
                if (!inv.DateEnvoiMail)   manquants.push('Date envoi mail');
              }
              if (manquants.length) {
                return `<button class="btn btn-ghost btn-sm" disabled
                  style="color:var(--gray-text);border-color:var(--gray-border);cursor:not-allowed;opacity:.55"
                  title="Clôture impossible — manque : ${manquants.join(', ')}. Modifiez l'intervention pour renseigner ces champs, ou cochez « Pas de suivi administratif » si elle n'en nécessite pas.">🏁 Clôturer</button>`;
              }
              return `<button class="btn btn-ghost btn-sm" style="color:var(--teal);border-color:var(--teal)"
                onclick="cloturerIntervention(${inv.Id})" title="${inv.DemandeId ? 'Clôture aussi la demande liée' : 'Clôturer l’intervention'}">🏁 Clôturer${inv.DemandeId ? ' + demande' : ''}</button>`;
            })()}
            ${canEdit() && inv.Statut === 'En cours' ? `
              <button class="btn btn-ghost btn-sm" style="color:var(--orange);border-color:var(--orange);font-size:11px"
                onclick="deleguerIntervention(${inv.Id})">🔄 Déléguer</button>` : ''}
            ${canEdit() && inv.Statut === 'En cours' && (inv.AgentNom || inv.AgentPrenom) ? `
              <button class="btn btn-ghost btn-sm" style="color:var(--red);border-color:var(--red);font-size:11px"
                onclick="delaissserIntervention(${inv.Id})">✋ Délaisser</button>` : ''}
            ${canEdit() && !['Validée','Archivée'].includes(inv.Statut) && !(inv.AgentNom || inv.AgentPrenom) ? `
              <button class="btn btn-ghost btn-sm" style="color:var(--blue);border-color:var(--blue)"
                onclick="prendreEnChargeIntervention(${inv.Id})">👁️ Je prends en charge</button>` : ''}
            ${canEdit() && peutArchiver ? `
              <button class="btn btn-ghost btn-sm" onclick="archiverIntervention(${inv.Id})">📁 Archiver</button>` : ''}
            ${canEdit() && peutArchiverManuel ? `
              <button class="btn btn-ghost btn-sm" style="color:var(--gray-text);border-color:var(--gray-border);font-size:11px"
                title="Archivage anticipé (délai normal : ${7-joursValid}j restant)"
                onclick="archiverIntervention(${inv.Id}, true)">📁 Archiver manuellement</button>` : ''}
          </div>
        </div>
      </div>`;
    });
    const canPrev = page > 1;
    const canNext = page < totalPages;
    const prevStyle = canPrev ? '' : 'opacity:.45;pointer-events:none;';
    const nextStyle = canNext ? '' : 'opacity:.45;pointer-events:none;';
    html += `</div>
      <div class="pagination" style="border:0;padding:10px 0;justify-content:space-between">
        <span>${total} intervention${total>1?'s':''} — Affichage ${from}-${to}</span>
        ${totalPages>1 ? `
          <div style="display:flex;align-items:center;gap:8px">
            <button class="btn btn-ghost btn-sm" style="${prevStyle}" onclick="App.setPage(${page-1})">←</button>
            <span style="font-size:12px;color:var(--gray-text)">Page ${page} / ${totalPages}</span>
            <button class="btn btn-ghost btn-sm" style="${nextStyle}" onclick="App.setPage(${page+1})">→</button>
          </div>` : ''}
      </div>`;
  return html;
}

async function editInterv(id) {
  const isNew = !id;
  _pendingStockLignes = []; // Reset des pièces en attente
  const [inv, types, priorites, biens, equipements, contrats, allDemandes, allInterventions, stock, _rf] = await Promise.all([
    isNew ? Promise.resolve({}) : InterventionsApi.getAll().then(d=>d.find(x=>x.Id===id)||{}),
    ListesApi.getByCategorie('TypeIntervention'),
    ListesApi.getByCategorie('PrioriteIntervention'),
    BiensApi.getAll(),
    EquipementsApi.getAll(),
    ContratsApi.getAll(),
    DemandesApi.getAll(),
    InterventionsApi.getAll(),
    StockApi.getAll(),
    getRequiredFields('interventions'),
  ]);
  const rf = _rf || [];
  // Demandes déjà liées à une intervention (sauf celle en cours d'édition)
  const demandesDejaLiees = new Set(allInterventions.filter(i => i.DemandeId && i.Id !== (inv.Id||0)).map(i => i.DemandeId));
  // Filtrer : non traitées/refusées ET non déjà liées (sauf la demande actuellement liée à cette intervention)
  const demandesOuvertes = allDemandes.filter(d =>
    !['Traité','Refusé'].includes(d.Statut) && !demandesDejaLiees.has(d.Id)
  );
  const lignes = isNew ? [] : await IntervLignesApi.getAll(id).catch(()=>[]);
  const devisLignes = isNew ? [] : await IntervDevisApi.getAll(id).catch(()=>[]);
  // Facturation : factures déjà rattachées à cette intervention + liste des
  // factures existantes (pour pouvoir regrouper plusieurs interventions).
  const facturesLignes = isNew ? [] : await IntervFacturesApi.getAll(id).catch(()=>[]);
  const allFactures    = isNew ? [] : await FacturesApi.getAll().catch(()=>[]);
  // NB : la liste des interventions (pour « Ajouter une intervention ») est déjà
  // disponible via `allInterventions`, chargée plus haut dans le Promise.all.

  // Auto-numéro
  const autoNumero = isNew ? await apiRequest('interv_autonumero').then(r=>r.numero||'').catch(()=>'') : '';

  const opts = (liste, val, inclEmpty=false) =>
    (inclEmpty?'<option value="">—</option>':'') +
    liste.map(x=>`<option ${val===x.Valeur?'selected':''}>${x.Valeur}</option>`).join('');

  // Sélecteur unifié Bien / Équipement
  const currentEquipIds = (inv.EquipementsIds||'').split(',').map(x=>parseInt(x)).filter(Boolean);
  const currentAssetKey = inv.BienId ? `bien_${inv.BienId}` : (currentEquipIds.length===1 ? `equip_${currentEquipIds[0]}` : '');
  const optsAssets = `<option value="">— Aucun —</option>
    <optgroup label="🏢 Biens">
      ${biens.map(b=>`<option value="bien_${b.Id}" ${currentAssetKey===('bien_'+b.Id)?'selected':''}>${escHtml(_optAssetText(b))}</option>`).join('')}
    </optgroup>
    <optgroup label="⚙️ Équipements">
      ${equipements.map(eq=>`<option value="equip_${eq.Id}" ${currentAssetKey===('equip_'+eq.Id)?'selected':''}>${escHtml(_optAssetText(eq))}</option>`).join('')}
    </optgroup>`;
  const optsContrats = `<option value=""></option>` +
    contrats.map(c=>`<option value="${c.Id}" ${inv.ContratId===c.Id?'selected':''}>${escHtml(c.Numero)} — ${escHtml(c.Societe)}</option>`).join('');
  const optsDemandes = `<option value=""></option>` +
    demandesOuvertes.map(d=>`<option value="${d.Id}" data-desc="${escHtml(d.Description||'')}" data-titre="${escHtml(d.Titre||'')}" data-bat="${escHtml(d.Batiment||'')}" data-bur="${escHtml(d.Bureau||'')}" ${inv.DemandeId===d.Id?'selected':''}>${escHtml(d.Titre||'')}${d.Batiment?' — '+escHtml(d.Batiment):''}${d.Bureau?' / Bureau '+escHtml(d.Bureau):''}</option>`).join('');

  const stockDispo = stock.filter(s=>s.Quantite>0);
  const optsStock  = stockDispo.map(s=>`<option value="${s.Id}" data-prix="${s.PrixUnitaire||0}" data-qte="${s.Quantite}">${escHtml(s.Designation||'')}${s.Marque?' ('+s.Marque+')':''} [${escHtml(s.Reference||'')}] — Stock: ${s.Quantite} — ${fmtMon(s.PrixUnitaire)}/u</option>`).join('');

  const montantPieces = lignes.reduce((s,l)=>s+(parseFloat(l.PrixUnitaire)||0)*(parseInt(l.Quantite)||0),0);
  const lignesHtml = lignes.length ? lignes.map(l=>`
    <div style="display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid var(--gray-border);font-size:13px">
      <span style="flex:1;font-weight:500">${l.Designation||l.Reference}</span>
      <span style="color:var(--gray-text)">${l.Quantite} × ${fmtMon(l.PrixUnitaire||0)}</span>
      <span style="font-weight:600;min-width:70px;text-align:right">${fmtMon((l.Quantite||0)*(l.PrixUnitaire||0))}</span>
      ${canEdit()?`<button class="icon-btn delete" onclick="supprimerLigneStock(${l.Id},${id})" title="Retirer">🗑️</button>`:''}
    </div>`).join('') :
    `<div style="color:var(--gray-text);font-size:13px;padding:6px 0">Aucune pièce.</div>`;

  // Formatage de date partagé (devis + factures). Défini AVANT devisHtml car
  // utilisé dès le rendu des lignes de devis (date de réception).
  const _fmtDate = (d) => (typeof fmtD === 'function' ? fmtD(d) : (d || ''));

  // Devis HTML
  const devisHtml = devisLignes.length ? devisLignes.map(d=>`
    <div style="display:flex;align-items:center;gap:8px;padding:8px 0;border-bottom:1px solid var(--gray-border);font-size:13px;flex-wrap:wrap">
      <span style="flex:1;min-width:90px;font-weight:500">${escHtml(d.NumeroDevis||'—')}</span>
      ${d.DateReception?`<span style="color:var(--gray-text)" title="Date de réception du devis">📅 ${escHtml(_fmtDate(d.DateReception))}</span>`:''}
      <span style="color:var(--gray-text)">HT: ${fmtMon(d.MontantHT)}</span>
      <span style="font-weight:600">TTC: ${fmtMon(d.MontantTTC)}</span>
      <span class="badge badge-${d.Accepte==='Oui'?'teal':d.Accepte==='Non'?'red':'gray'}">${d.Accepte||'—'}</span>
      ${d.Raison?`<span style="color:var(--gray-text);font-size:11px" title="${escHtml(d.Raison)}">💬</span>`:''}
      ${canEdit()?`<button class="icon-btn delete" onclick="supprimerDevisLigne(${d.Id},${id})" title="Supprimer">🗑️</button>`:''}
    </div>`).join('') :
    `<div style="color:var(--gray-text);font-size:13px;padding:6px 0">Aucun devis.</div>`;

  // ── Facturation : factures rattachées (avec édition/suppression par ligne) ──
  const facturesHtml = facturesLignes.length ? facturesLignes.map(f=>`
    <div style="padding:8px 0;border-bottom:1px solid var(--gray-border);font-size:13px">
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
        <span style="flex:1;min-width:130px;font-weight:500">🧾 ${escHtml(f.Numero||'—')}</span>
        <span class="badge badge-gray" title="CBDC / CHMA de la facture">${escHtml(f.TypeBudget||'—')}</span>
        ${f.NumeroEJ?`<span style="color:var(--gray-text)" title="N° engagement juridique">EJ ${escHtml(f.NumeroEJ)}</span>`:''}
        ${f.DateEJ?`<span style="color:var(--gray-text)" title="Date EJ">${escHtml(_fmtDate(f.DateEJ))}</span>`:''}
        <span style="color:var(--gray-text)" title="Montant total de la facture">Facture TTC: ${fmtMon(f.MontantTTC)}</span>
        <span style="font-weight:600;min-width:90px;text-align:right" title="Montant de cette facture pour cette intervention (peut être négatif : avoir / moins-value)">Pour cette interv. : ${fmtMon(f.MontantLigne)}</span>
        ${canEdit()?`<button class="icon-btn" onclick="toggleEditFacture(${f.LiaisonId})" title="Modifier cette facture">✏️</button>`:''}
        ${canEdit()?`<button class="icon-btn delete" onclick="detacherFacture(${f.LiaisonId},${id})" title="Détacher cette intervention de la facture">🗑️</button>`:''}
      </div>
      ${f.AutresInterventions?`<div style="font-size:11px;color:var(--purple,#7c3aed);margin-top:3px">🔗 Cette facture couvre aussi : ${escHtml(f.AutresInterventions)}</div>`:''}
      ${canEdit()?`
      <div id="editFacture_${f.LiaisonId}" style="display:none;margin-top:8px;padding:10px;border:1px solid var(--gray-border);border-radius:8px;background:var(--card-bg)">
        <div style="font-size:11px;font-weight:600;color:var(--gray-text);margin-bottom:8px">✏️ Modifier la facture <span style="font-weight:400">— les champs ci-dessous s'appliquent à toutes les interventions qu'elle couvre</span></div>
        <div class="form-grid">
          <div class="form-group"><label class="form-label">CBDC / CHMA</label><input class="form-control" id="ef_tb_${f.LiaisonId}" value="${escHtml(f.TypeBudget)}"></div>
          <div class="form-group"><label class="form-label">N° EJ</label><input class="form-control" id="ef_ej_${f.LiaisonId}" value="${escHtml(f.NumeroEJ)}"></div>
          <div class="form-group"><label class="form-label">Date EJ</label><input class="form-control" type="date" id="ef_dej_${f.LiaisonId}" value="${escHtml(f.DateEJ)}"></div>
          <div class="form-group"><label class="form-label">Date facture</label><input class="form-control" type="date" id="ef_df_${f.LiaisonId}" value="${escHtml(f.DateFacture)}"></div>
          <div class="form-group"><label class="form-label">N° facture</label><input class="form-control" id="ef_num_${f.LiaisonId}" value="${escHtml(f.Numero)}"></div>
          <div class="form-group"><label class="form-label">Montant HT (€)</label><input class="form-control" type="number" step="0.01" id="ef_ht_${f.LiaisonId}" value="${f.MontantHT!=null?f.MontantHT:''}"></div>
          <div class="form-group"><label class="form-label">Montant TTC (€)</label><input class="form-control" type="number" step="0.01" id="ef_ttc_${f.LiaisonId}" value="${f.MontantTTC!=null?f.MontantTTC:''}"></div>
          <div class="form-group span-2"><label class="form-label">Fournisseur</label><input class="form-control" id="ef_four_${f.LiaisonId}" value="${escHtml(f.Fournisseur)}"></div>
          <div class="form-group"><label class="form-label">Montant pour cette intervention (€)</label><input class="form-control" type="number" step="0.01" id="ef_part_${f.LiaisonId}" value="${f.MontantLigne!=null?f.MontantLigne:''}"></div>
        </div>
        <div style="margin-top:8px;display:flex;gap:8px">
          <button class="btn btn-primary btn-sm" onclick="enregistrerFacture(${f.LiaisonId}, ${f.Id}, ${id})">💾 Enregistrer</button>
          <button class="btn btn-ghost btn-sm" onclick="toggleEditFacture(${f.LiaisonId})">Annuler</button>
        </div>
      </div>`:''}
    </div>`).join('') :
    `<div style="color:var(--gray-text);font-size:13px;padding:6px 0">Aucune facture rattachée.</div>`;

  // Libellé d'une facture pour les menus déroulants
  const _factLabel = (f) => `${f.Numero||'(sans n°)'} — ${f.TypeBudget||'?'}${f.NumeroEJ?' — EJ '+f.NumeroEJ:''} — TTC ${fmtMon(f.MontantTTC)}${f.NbInterventions?` — ${f.NbInterventions} interv.`:''}`;
  // Factures existantes (pour rattacher une intervention à une facturation déjà créée)
  const optsFacturesPures = allFactures.map(f=>{
    const ttc = (f.MontantTTC!=null && f.MontantTTC!=='') ? f.MontantTTC : (f.MontantHT||0);
    return `<option value="${f.Id}" data-ttc="${ttc}">${escHtml(_factLabel(f))}</option>`;
  }).join('');
  // Interventions (pour choisir laquelle rattacher ; défaut = la fiche courante)
  const optsInterventions = allInterventions.map(iv=>
    `<option value="${iv.Id}" ${iv.Id===id?'selected':''}>${escHtml(`${iv.Numero||('#'+iv.Id)}${iv.Description?' — '+String(iv.Description).slice(0,45):''}`)}</option>`
  ).join('');

  // Auto-remplir l'agent avec l'utilisateur connecté pour les nouvelles interventions
  const _currentUser = App.currentUser || {};
  const _autoNom    = isNew ? (_currentUser.Nom || '')    : (inv.AgentNom || '');
  const _autoPrenom = isNew ? (_currentUser.Prenom || '') : (inv.AgentPrenom || '');
  const _autoTel    = isNew ? (_currentUser.TelMobile || _currentUser.TelPro || _currentUser.Tel || '') : (inv.AgentTel || '');
  const _autoEmail  = isNew ? (_currentUser.Email || '')  : (inv.AgentEmail || '');

  openModal(isNew?'Nouvelle intervention':`Modifier — ${escHtml(inv.Numero||'')}`, `
  <div style="display:flex;flex-direction:column;gap:16px">

    <!-- Agent créateur -->
    <div>
      <div class="section-label">Agent créateur de la fiche</div>
      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Nom</label>
          <input class="form-control" id="f_agentNom" value="${_autoNom}" placeholder="Nom">
        </div>
        <div class="form-group">
          <label class="form-label">Prénom</label>
          <input class="form-control" id="f_agentPrenom" value="${_autoPrenom}" placeholder="Prénom">
        </div>
        <div class="form-group">
          <label class="form-label">Téléphone</label>
          <input class="form-control" type="tel" id="f_agentTel" value="${_autoTel}" placeholder="N° téléphone">
        </div>
        <div class="form-group">
          <label class="form-label">Adresse e-mail</label>
          <input class="form-control" type="email" id="f_agentEmail" value="${_autoEmail}" placeholder="email@exemple.fr">
        </div>
      </div>
    </div>

    <!-- Origine : demande -->
    <div>
      <div class="section-label">Origine</div>
      <div class="form-group">
        <label class="form-label">Suite à une demande ?</label>
        <select class="form-control" id="f_demandeId" onchange="importerDemande()">${optsDemandes}</select>
      </div>
    </div>

    <!-- Infos générales -->
    <div>
      <div class="section-label">Informations générales</div>
      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">${reqLabel("Numéro","Numero",rf)}</label>
          <div style="display:flex;gap:6px">
            <input class="form-control" id="f_numero" value="${escHtml(inv.Numero||autoNumero)}" placeholder="Auto-généré si vide">
            ${isNew?`<button class="btn btn-ghost btn-sm" onclick="genererNumero()" title="Générer">🔄</button>`:''}
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">${reqLabel("Type","Type",rf)}</label>
          <select class="form-control" id="f_type">${opts(types,inv.Type)}</select>
        </div>
        <div class="form-group">
          <label class="form-label">${reqLabel("Priorité","Priorite",rf)}</label>
          <select class="form-control" id="f_priorite"><option value="">—</option>${opts(priorites,inv.Priorite)}</select>
        </div>
        <div class="form-group">
          <label class="form-label">${reqLabel("Statut","Statut",rf)}</label>
          <select class="form-control" id="f_statut">
            ${['Planifiée','En cours','Réalisée'].map(s=>`<option value="${s}" ${(inv.Statut||'Planifiée')===s?'selected':''}>${s}</option>`).join('')}
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Date de demande</label>
          <input class="form-control" type="date" id="f_dateDemande" value="${inv.DateDemande||''}">
        </div>
        <div class="form-group">
          <label class="form-label">Date d'intervention</label>
          <input class="form-control" type="date" id="f_dateIntervention" value="${inv.DateIntervention||''}">
        </div>
        <div class="form-group">
          <label class="form-label">${reqLabel("Date réalisation","DateRealisation",rf)}</label>
          <input class="form-control" type="date" id="f_dateRealisation" value="${inv.DateRealisation||''}">
        </div>
        <div class="form-group">
          <label class="form-label">Durée (heures)</label>
          <input class="form-control" type="number" step="0.5" id="f_duree" value="${inv.DureeHeures||0}">
        </div>
        <div class="form-group">
          <label class="form-label" title="Pour les curatives/divers : combien de jours avant la date d'intervention l'afficher sur le dashboard">Délai affichage dashboard (jours)</label>
          <input class="form-control" type="number" min="0" id="f_delaiAffichage" value="${inv.DelaiAffichageDashboard||0}" placeholder="0 = dès maintenant">
          <div style="font-size:11px;color:var(--gray-text);margin-top:2px">Curatif/Divers : affiché X jours avant sur le tableau de bord. Préventif : toujours affiché.</div>
        </div>
        ${(function(){
          let hist = [];
          try { hist = JSON.parse(inv.HistoriqueModifDates || '[]'); } catch(e){}
          if (!hist.length) return '';
          return '<div class="form-group span-2"><label class="form-label">📋 Historique des modifications de date</label><div style="max-height:120px;overflow-y:auto;border:1px solid var(--gray-border);border-radius:6px;padding:6px;font-size:12px">'
            + hist.map(h => '<div style="padding:4px 0;border-bottom:1px solid var(--gray-border);display:flex;gap:8px;flex-wrap:wrap">'
              + '<span style="color:var(--red);text-decoration:line-through">' + (h.ancienneDate ? new Date(h.ancienneDate).toLocaleDateString('fr-FR') : '—') + '</span>'
              + ' → <span style="color:var(--teal);font-weight:600">' + (h.nouvelleDate ? new Date(h.nouvelleDate).toLocaleDateString('fr-FR') : '—') + '</span>'
              + ' <span style="color:var(--gray-text)">par <strong>' + (h.qui||'?') + '</strong> — ' + (h.raison||'') + '</span>'
              + ' <span style="color:var(--gray-text);font-size:10px">' + (h.dateModif ? new Date(h.dateModif).toLocaleString('fr-FR') : '') + '</span>'
              + '</div>').join('')
            + '</div></div>';
        })()}
        <!-- Notes libres (texte + date) -->
        <div class="form-group span-2">
          <label class="form-label" style="display:flex;align-items:center;gap:8px">
            Notes / Suivi libre
            <button type="button" class="btn btn-ghost btn-sm" style="padding:2px 8px;font-size:14px;line-height:1" onclick="ajouterNoteLine()" title="Ajouter une note">＋</button>
          </label>
          <div id="f_notesContainer">
            ${(function(){
              let notes = [];
              try { notes = JSON.parse(inv.NotesJSON || '[]'); } catch(e){}
              if (!notes.length) return '<div style="color:var(--gray-text);font-size:12px">Aucune note. Cliquez + pour en ajouter.</div>';
              return notes.map((n,i) => '<div class="note-line" style="display:flex;gap:6px;align-items:center;margin-bottom:6px" data-idx="'+i+'">'
                +'<input class="form-control note-text" value="'+(n.texte||'').replace(/"/g,'&quot;')+'" placeholder="Texte libre" style="flex:1;font-size:12px">'
                +'<input class="form-control note-date" type="date" value="'+(n.date||'')+'" style="width:145px;font-size:12px">'
                +'<button type="button" class="icon-btn delete" onclick="supprimerNoteLine(this)" title="Supprimer" style="flex-shrink:0">🗑️</button>'
                +'</div>').join('');
            })()}
          </div>
        </div>
        <div class="form-group span-2">
          <label class="form-label">${reqLabel("Description","Description",rf)}</label>
          <textarea class="form-control" id="f_description" rows="3">${escHtml(inv.Description||'')}</textarea>
          <div id="infoImport" style="font-size:11px;color:var(--teal);margin-top:3px;display:none">✅ Importé depuis la demande</div>
        </div>
        <div class="form-group span-2">
          <label class="form-label">Commentaire</label>
          <textarea class="form-control" id="f_commentaire" rows="2">${escHtml(inv.Commentaire||'')}</textarea>
        </div>
      </div>
    </div>

    <!-- Suivi administratif -->
    <div>
      <div class="section-label">Suivi administratif</div>

      <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;font-weight:500;padding:8px 10px;background:var(--gray-bg);border-radius:6px;margin-bottom:8px">
        <input type="checkbox" id="f_sansSuiviAdmin" ${inv.SansSuiviAdmin?'checked':''} onchange="toggleSuiviAdmin()">
        Cette intervention ne nécessite pas de suivi administratif
        <span style="font-weight:400;color:var(--gray-text)">— ni CBDC/CHMA, ni dates</span>
      </label>

      <div id="hintSuiviAdmin" style="font-size:11px;color:var(--gray-text);margin-bottom:8px;padding:6px 10px;background:var(--gray-bg);border-radius:6px;border-left:3px solid var(--teal)">
        💡 Renseignez <strong>CHMA / CBDC</strong>, <strong>Date envoi mail</strong> et <strong>Date réalisation</strong> pour pouvoir clôturer cette intervention.
      </div>
      <div id="hintSansSuiviAdmin" style="display:none;font-size:11px;color:var(--gray-text);margin-bottom:8px;padding:6px 10px;background:var(--gray-bg);border-radius:6px;border-left:3px solid var(--gray-border)">
        🚫 Champs non requis : l'intervention pourra être clôturée sans CBDC/CHMA ni dates. Les valeurs déjà saisies sont conservées mais ignorées au contrôle.
      </div>

      <div class="form-grid" id="gridSuiviAdmin">
        <div class="form-group">
          <label class="form-label">CHMA / CBDC</label>
          <input class="form-control" id="f_typeBudget" value="${inv.TypeBudget||''}" placeholder="Ex: CHMA, CBDC, autre…">
        </div>
        <div class="form-group">
          <label class="form-label">Date envoi mail</label>
          <input class="form-control" type="date" id="f_dateEnvoiMail" value="${inv.DateEnvoiMail||''}">
        </div>
        <div class="form-group">
          <label class="form-label">Code n° demande</label>
          <input class="form-control" id="f_codeNumeroDemande" value="${inv.CodeNumeroDemande||''}" placeholder="Référence">
        </div>
        <div class="form-group">
          <label class="form-label">Prix HT (€)</label>
          <input class="form-control" type="number" step="0.01" min="0" id="f_montantHT" value="${inv.MontantHT!=null?inv.MontantHT:''}" placeholder="0.00">
        </div>
      </div>

      <!-- Facturation / EJ (sous le CBDC/CHMA du suivi administratif) -->
      <div style="margin-top:14px">
        <div style="font-size:13px;font-weight:600;margin-bottom:6px">🧾 Facturation / EJ</div>
        <div style="font-size:11px;color:var(--gray-text);margin-bottom:8px;padding:6px 10px;background:var(--gray-bg);border-radius:6px;border-left:3px solid var(--teal)">
          💡 Une facture (CBDC/CHMA, n° EJ, montant) peut couvrir plusieurs interventions. <strong>Ajouter une facturation</strong> = créer une nouvelle facture. <strong>Ajouter une intervention</strong> = rattacher une intervention (celle-ci ou une autre) à une facturation existante. Chaque ligne est modifiable (✏️) et détachable (🗑️). Les montants acceptent le négatif (avoir / moins-value).
        </div>
        <div id="facturesLignesContainer" style="margin-bottom:10px">${facturesHtml}</div>
        ${!isNew && canEdit() ? `
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <button class="btn btn-ghost btn-sm" style="border-color:var(--teal);color:var(--teal)" onclick="toggleAjoutFacturation()">+ Ajouter une facturation</button>
          <button class="btn btn-ghost btn-sm" style="border-color:var(--purple,#7c3aed);color:var(--purple,#7c3aed)" onclick="toggleAjoutIntervention()">+ Ajouter une intervention</button>
        </div>

        <!-- Formulaire A : nouvelle facturation -->
        <div id="formAjoutFacturation" style="display:none;flex-direction:column;gap:10px;margin-top:10px;padding:12px;background:var(--gray-bg);border-radius:8px">
          <div style="font-size:12px;font-weight:600;color:var(--gray-text)">🧾 Nouvelle facturation</div>
          <div class="form-grid">
            <div class="form-group"><label class="form-label">CBDC / CHMA</label><input class="form-control" id="f_factTypeBudget" value="${escHtml(inv.TypeBudget||'')}" placeholder="CBDC, CHMA…"></div>
            <div class="form-group"><label class="form-label">N° engagement juridique (EJ)</label><input class="form-control" id="f_factNumeroEJ" placeholder="N° EJ"></div>
            <div class="form-group"><label class="form-label">Date EJ</label><input class="form-control" type="date" id="f_factDateEJ"></div>
            <div class="form-group"><label class="form-label">Date facture</label><input class="form-control" type="date" id="f_factDateFacture"></div>
            <div class="form-group"><label class="form-label">Montant TTC (€)</label><input class="form-control" type="number" step="0.01" id="f_factMontantTTC" placeholder="0.00" oninput="syncMontantImpute()"></div>
            <div class="form-group"><label class="form-label">Montant HT (€) <span style="color:var(--gray-text);font-weight:400">— option.</span></label><input class="form-control" type="number" step="0.01" id="f_factMontantHT" placeholder="0.00"></div>
            <div class="form-group"><label class="form-label">N° facture <span style="color:var(--gray-text);font-weight:400">— auto si vide</span></label><input class="form-control" id="f_factNumero" placeholder="Réf. fournisseur"></div>
            <div class="form-group span-2"><label class="form-label">Fournisseur</label><input class="form-control" id="f_factFournisseur" placeholder="Nom du prestataire"></div>
            <div class="form-group span-2"><label class="form-label">Montant pour cette intervention (€)</label><input class="form-control" type="number" step="0.01" id="f_factMontantLigne" value="${inv.MontantHT!=null&&inv.MontantHT!==''?inv.MontantHT:''}" placeholder="0.00" oninput="this.dataset.touched='1'"><div style="font-size:10px;color:var(--gray-text);margin-top:3px">Se cale sur le montant TTC. Peut être négatif (avoir / moins-value). À diminuer si la facture couvre plusieurs interventions.</div></div>
          </div>
          <div><button class="btn btn-primary btn-sm" onclick="ajouterFacturation(${id})">Créer la facturation</button></div>
        </div>

        <!-- Formulaire B : rattacher une intervention à une facturation existante -->
        <div id="formAjoutIntervention" style="display:none;flex-direction:column;gap:10px;margin-top:10px;padding:12px;background:var(--gray-bg);border-radius:8px">
          <div style="font-size:12px;font-weight:600;color:var(--gray-text)">🔗 Rattacher une intervention à une facturation</div>
          ${optsFacturesPures ? `
          <div class="form-grid">
            <div class="form-group span-2"><label class="form-label">Facturation</label><select class="form-control" id="f_linkFacture" onchange="syncLinkMontant()">${optsFacturesPures}</select></div>
            <div class="form-group span-2"><label class="form-label">Intervention à rattacher</label><select class="form-control" id="f_linkInterv">${optsInterventions}</select><div style="font-size:10px;color:var(--gray-text);margin-top:3px">Par défaut l'intervention courante. Choisissez-en une autre pour la rattacher à cette facturation.</div></div>
            <div class="form-group span-2"><label class="form-label">Montant pour cette intervention (€)</label><input class="form-control" type="number" step="0.01" id="f_linkMontant" placeholder="0.00"></div>
          </div>
          <div><button class="btn btn-primary btn-sm" onclick="lierIntervention(${id})">Rattacher</button></div>
          ` : `<div style="font-size:12px;color:var(--gray-text)">Aucune facturation existante : créez-en une d'abord avec « + Ajouter une facturation ».</div>`}
        </div>
        ` : !isNew ? '' : `<div style="font-size:12px;color:var(--gray-text)">💡 Sauvegardez d'abord l'intervention pour pouvoir gérer sa facturation.</div>`}
      </div>
    </div>

    <!-- Liaisons -->
    <div>
      <div class="section-label">Liaisons</div>
      <div class="form-grid">
        <div class="form-group span-2">
          <label class="form-label">Bien / Équipement concerné</label>
          <select class="form-control" id="f_assetId">${optsAssets}</select>
        </div>
        <div class="form-group">
          <label class="form-label">Contrat de maintenance</label>
          <select class="form-control" id="f_contratId">${optsContrats}</select>
        </div>
        <div class="form-group">
          <label class="form-label">Société externe (si pas de contrat)</label>
          <input class="form-control" id="f_societe" value="${escHtml(inv.SocieteManuelle||'')}" placeholder="Nom de l'entreprise prestataire">
        </div>
      </div>
    </div>

    <!-- Devis -->
    <div>
      <div class="section-label">Devis</div>
      <div id="devisLignesContainer" style="margin-bottom:10px">${devisHtml}</div>
      ${!isNew && canEdit() ? `
      <div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;padding:8px;background:var(--gray-bg);border-radius:8px">
        <div style="flex:1;min-width:100px">
          <label class="form-label" style="font-size:11px">Devis n°</label>
          <input class="form-control" id="f_newDevisNumero" placeholder="N° devis" style="font-size:12px">
        </div>
        <div style="width:140px">
          <label class="form-label" style="font-size:11px">Date réception</label>
          <input class="form-control" type="date" id="f_newDevisDateReception" style="font-size:12px">
        </div>
        <div style="width:100px">
          <label class="form-label" style="font-size:11px">Montant HT (€)</label>
          <input class="form-control" type="number" step="0.01" id="f_newDevisHT" value="0" style="font-size:12px">
        </div>
        <div style="width:100px">
          <label class="form-label" style="font-size:11px">Montant TTC (€)</label>
          <input class="form-control" type="number" step="0.01" id="f_newDevisTTC" value="0" style="font-size:12px">
        </div>
        <div style="width:90px">
          <label class="form-label" style="font-size:11px">Accepté ?</label>
          <select class="form-control" id="f_newDevisAccepte" style="font-size:12px">
            <option value="">—</option>
            <option value="Oui">Oui</option>
            <option value="Non">Non</option>
          </select>
        </div>
        <div style="flex:1;min-width:100px">
          <label class="form-label" style="font-size:11px">Raison (si refusé)</label>
          <input class="form-control" id="f_newDevisRaison" placeholder="Motif" style="font-size:12px">
        </div>
        <button class="btn btn-primary btn-sm" onclick="ajouterDevisLigne(${id})">+ Ajouter</button>
      </div>` : !isNew ? '' : `<div style="font-size:12px;color:var(--gray-text)">💡 Sauvegardez d'abord pour ajouter des devis.</div>`}
    </div>

    <!-- Documents joints -->
    <div>
      <div class="section-label">Documents joints</div>
      ${isNew ? docsPanelHtml('Intervention', 0, 'Intervention') : docsPanelHtml('Intervention', id)}
    </div>

    <!-- Pièces stock -->
    <div>
      <div class="section-label">Pièces consommées (stock)</div>
      <div id="lignesStock" style="margin-bottom:10px">${lignesHtml}</div>
      ${canEdit() && stockDispo.length > 0 ? `
      <div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
        <div style="flex:2;min-width:180px">
          <label class="form-label" style="font-size:11px">Article</label>
          <select class="form-control" id="sel_stock" style="font-size:12px" onchange="prefillPrixStock()">${optsStock}</select>
        </div>
        <div style="width:70px">
          <label class="form-label" style="font-size:11px">Qté</label>
          <input class="form-control" type="number" id="sel_qte" value="1" min="1">
        </div>
        <div style="width:90px">
          <label class="form-label" style="font-size:11px">Prix/u (€)</label>
          <input class="form-control" type="number" step="0.01" id="sel_prix" value="0">
        </div>
        <button class="btn btn-primary btn-sm" onclick="${isNew ? 'ajouterLigneStockPending()' : `ajouterLigneStock(${id})`}">+ Ajouter</button>
      </div>` : ''}
      ${canEdit() && stockDispo.length === 0 ? `
      <div style="font-size:12px;color:var(--gray-text);background:var(--gray-bg);border:1px dashed var(--gray-border);border-radius:8px;padding:8px 12px">
        Aucun article disponible en stock. Ajoutez des articles (avec une quantité &gt; 0) dans l'onglet <strong>Stock</strong> pour pouvoir les consommer ici.
      </div>` : ''}
      ${isNew ? '<div id="pendingStockLignes" style="margin-top:8px"></div>' : ''}
    </div>
  </div>`, async () => {
    let description = gv('f_description').trim();
    if (!description) { toast('La description est requise.', 'error'); return; }
    // Parsing du sélecteur unifié bien/équipement
    const assetVal = gv('f_assetId');
    let bienId = null, equipementsIds = '', typeLiaison = 'Bien';
    if (assetVal.startsWith('bien_')) {
      bienId = parseInt(assetVal.replace('bien_',''));
      typeLiaison = 'Bien';
    } else if (assetVal.startsWith('equip_')) {
      equipementsIds = assetVal.replace('equip_','');
      typeLiaison = 'Equipement';
    }
    const payload = {
      numero:            gv('f_numero').trim() || null,
      type:              gv('f_type'),
      priorite:          gv('f_priorite'),
      statut:            gv('f_statut'),
      societeManuelle:   gv('f_societe'),
      description,
      dateRealisation:   gv('f_dateRealisation') || null,
      dureeHeures:       parseFloat(gv('f_duree'))||0,
      montant:           0,
      montantMainOeuvre: 0,
      bienId,
      equipementsIds,
      contratId:         gv('f_contratId') || null,
      demandeId:         gv('f_demandeId') || null,
      estRecurrente:     false,
      commentaire:       gv('f_commentaire'),
      typeLiaison,
      agentNom:          gv('f_agentNom'),
      agentPrenom:       gv('f_agentPrenom'),
      agentTel:          gv('f_agentTel'),
      agentEmail:        gv('f_agentEmail'),
      dateDemande:       gv('f_dateDemande') || null,
      dateIntervention:  gv('f_dateIntervention') || null,
      relanceChamp:      '',
      relanceDate:       null,
      typeBudget:        gv('f_typeBudget'),
      dateEnvoiMail:     gv('f_dateEnvoiMail') || null,
      codeNumeroDemande: gv('f_codeNumeroDemande'),
      montantHT:         parseFloat(gv('f_montantHT'))||0,
      notesJSON:         collectNotesJSON(),
      delaiAffichageDashboard: parseInt(gv('f_delaiAffichage'))||0,
      historiqueModifDates: inv.HistoriqueModifDates || '[]',
      sansSuiviAdmin:    document.getElementById('f_sansSuiviAdmin')?.checked ? 1 : 0,
    };
    try {
      if (isNew) {
        const result = await InterventionsApi.create(payload);
        const newId = result?.id || result;
        if (newId) await uploadPendingDocs('Intervention', 'Intervention', newId);
        if (newId) await _savePendingStockLignes(newId);
      }
      else       await InterventionsApi.update(id, payload);
      toast(isNew?'Intervention créée.':'Intervention modifiée.', 'success');
      closeModal(); renderInterventions();
    } catch(e) { toast(e.message, 'error'); }
  });

  setTimeout(() => {
    // Select demande : 1 barre + filtre en direct
    if (window.enhanceSelectTypeahead) enhanceSelectTypeahead('f_demandeId', { placeholder: 'Rechercher une demande…', allowEmpty: true });
    if (window.enhanceSelectTypeahead) enhanceSelectTypeahead('f_assetId', { placeholder: 'Rechercher un bien ou équipement…', allowEmpty: true });
    if (window.enhanceSelectTypeahead) enhanceSelectTypeahead('f_contratId', { placeholder: 'Rechercher un contrat…', allowEmpty: true });
    prefillPrixStock();
    toggleSuiviAdmin(); // applique l'état de la case au chargement de la fiche
    if (isNew) { window._docsPending['Intervention'] = []; }
    initDocsPanel('Intervention', id, 'Intervention');
  }, 150);
}

/**
 * Case « Cette intervention ne nécessite pas de suivi administratif ».
 *
 * Cochée : CBDC/CHMA, date d'envoi du mail et date de réalisation ne sont plus
 * exigés pour clôturer (contrôle levé côté client ET côté serveur via la
 * colonne SansSuiviAdmin). Les champs restent visibles mais grisés — on ne les
 * vide pas, pour ne rien perdre si l'utilisateur décoche ensuite.
 */
function toggleSuiviAdmin() {
  const sans = !!document.getElementById('f_sansSuiviAdmin')?.checked;

  const hintNormal = document.getElementById('hintSuiviAdmin');
  const hintSans   = document.getElementById('hintSansSuiviAdmin');
  if (hintNormal) hintNormal.style.display = sans ? 'none'  : 'block';
  if (hintSans)   hintSans.style.display   = sans ? 'block' : 'none';

  // Grisage visuel des champs concernés (y compris la date de réalisation,
  // qui vit dans la section « Informations générales »).
  ['f_typeBudget', 'f_dateEnvoiMail', 'f_dateRealisation'].forEach(fid => {
    const el = document.getElementById(fid);
    if (!el) return;
    el.disabled = sans;
    el.style.opacity = sans ? '.55' : '';
    el.title = sans ? 'Non requis : la case « pas de suivi administratif » est cochée.' : '';
  });
}

// Sélection d'une demande : aucun pré-remplissage automatique
function importerDemande() {
  // Les champs restent vides — le technicien les renseigne manuellement.
  const info = document.getElementById('infoImport');
  if (info) info.style.display = 'none';
}

async function ajouterDevisLigne(intervId) {
  const numeroDevis = gv('f_newDevisNumero');
  const montantHT   = parseFloat(gv('f_newDevisHT'))||0;
  const montantTTC  = parseFloat(gv('f_newDevisTTC'))||0;
  const accepte     = gv('f_newDevisAccepte');
  const raison      = gv('f_newDevisRaison');
  const dateReception = gv('f_newDevisDateReception');
  if (!numeroDevis && !montantHT && !montantTTC) { toast('Renseignez au moins le n° de devis ou un montant.', 'error'); return; }
  try {
    await IntervDevisApi.create(intervId, { numeroDevis, montantHT, montantTTC, accepte, raison, dateReception });
    toast('Devis ajouté.', 'success');
    closeModal(); setTimeout(() => editInterv(intervId), 50);
  } catch(e) { toast(e.message, 'error'); }
}

async function supprimerDevisLigne(ligneId, intervId) {
  showConfirm('Supprimer ce devis ?', async () => {
    try {
      await IntervDevisApi.delete(ligneId);
      toast('Devis supprimé.', 'success');
      closeModal(); setTimeout(() => editInterv(intervId), 50);
    } catch(e) { toast(e.message, 'error'); }
  }, 'Supprimer');
}

// ── Facturation : 2 actions (nouvelle facturation / rattacher une intervention) ──
// Affiche un formulaire et masque l'autre (mutuellement exclusifs).
function toggleAjoutFacturation() {
  const a = document.getElementById('formAjoutFacturation');
  const b = document.getElementById('formAjoutIntervention');
  if (b) b.style.display = 'none';
  if (a) a.style.display = (a.style.display === 'none' || !a.style.display) ? 'flex' : 'none';
}
function toggleAjoutIntervention() {
  const a = document.getElementById('formAjoutFacturation');
  const b = document.getElementById('formAjoutIntervention');
  if (a) a.style.display = 'none';
  if (b) {
    b.style.display = (b.style.display === 'none' || !b.style.display) ? 'flex' : 'none';
    if (b.style.display === 'flex') syncLinkMontant();
  }
}

// Recopie le montant TTC de la nouvelle facture dans le montant pour l'intervention, tant
// que l'utilisateur ne l'a pas personnalisé (cas fréquent : 1 facture = 1 interv).
function syncMontantImpute() {
  const ttc = document.getElementById('f_factMontantTTC');
  const imp = document.getElementById('f_factMontantLigne');
  if (!ttc || !imp) return;
  if (imp.dataset.touched === '1') return;
  imp.value = ttc.value;
}

// Pré-remplit le montant pour l'intervention du formulaire « rattacher une intervention »
// avec le TTC de la facturation sélectionnée.
function syncLinkMontant() {
  const sel = document.getElementById('f_linkFacture');
  const imp = document.getElementById('f_linkMontant');
  if (!sel || !imp) return;
  const opt = sel.options[sel.selectedIndex];
  const ttc = opt ? parseFloat(opt.dataset.ttc || '0') : 0;
  if (ttc) imp.value = ttc;
}

// Crée une nouvelle facturation et la rattache à l'intervention courante.
async function ajouterFacturation(intervId) {
  const numero      = gv('f_factNumero');
  const typeBudget  = gv('f_factTypeBudget');
  const numeroEJ    = gv('f_factNumeroEJ');
  const dateEJ      = gv('f_factDateEJ');
  const dateFacture = gv('f_factDateFacture');
  const montantHT   = parseFloat(gv('f_factMontantHT'))  || 0;
  const montantTTC  = parseFloat(gv('f_factMontantTTC')) || 0;
  const fournisseur = gv('f_factFournisseur');
  const montantLigne = parseFloat(gv('f_factMontantLigne')) || 0;
  if (!typeBudget && !numeroEJ && !numero && !montantTTC && !montantHT) {
    toast('Renseignez au moins le CBDC, le n° EJ, le n° de facture ou un montant.', 'error'); return;
  }
  try {
    await IntervFacturesApi.create(intervId, { numero, typeBudget, numeroEJ, dateEJ, dateFacture, montantHT, montantTTC, fournisseur, montantLigne });
    toast('Facturation ajoutée.', 'success');
    closeModal(); setTimeout(() => editInterv(intervId), 50);
  } catch(e) { toast(e.message, 'error'); }
}

// Rattache l'intervention choisie (courante ou autre) à une facturation existante.
async function lierIntervention(currentIntervId) {
  const factureId      = parseInt(gv('f_linkFacture')) || 0;
  const interventionId = parseInt(gv('f_linkInterv'))  || 0;
  const montantLigne   = parseFloat(gv('f_linkMontant')) || 0;
  if (!factureId)      { toast('Choisissez une facturation.', 'error'); return; }
  if (!interventionId) { toast('Choisissez une intervention.', 'error'); return; }
  try {
    await IntervFacturesApi.link({ factureId, interventionId, montantLigne });
    toast('Intervention rattachée à la facturation.', 'success');
    closeModal(); setTimeout(() => editInterv(currentIntervId), 50);
  } catch(e) { toast(e.message, 'error'); }
}

// Affiche/masque le formulaire d'édition d'une ligne de facture.
function toggleEditFacture(liaisonId) {
  const box = document.getElementById('editFacture_' + liaisonId);
  if (box) box.style.display = (box.style.display === 'none' || !box.style.display) ? 'block' : 'none';
}

// Enregistre les modifs : en-tête de la facture (partagé) + montant pour l'intervention (ligne).
async function enregistrerFacture(liaisonId, factureId, intervId) {
  const g = (k) => gv('ef_' + k + '_' + liaisonId);
  try {
    await FacturesApi.update(factureId, {
      numero:      g('num'),
      typeBudget:  g('tb'),
      numeroEJ:    g('ej'),
      dateEJ:      g('dej'),
      dateFacture: g('df'),
      montantHT:   parseFloat(g('ht'))  || 0,
      montantTTC:  parseFloat(g('ttc')) || 0,
      fournisseur: g('four'),
    });
    await IntervFacturesApi.update(liaisonId, { montantLigne: parseFloat(g('part')) || 0 });
    toast('Facture modifiée.', 'success');
    closeModal(); setTimeout(() => editInterv(intervId), 50);
  } catch(e) { toast(e.message, 'error'); }
}

// Détache l'intervention de la facture (la facture, et les autres interventions
// qui y sont rattachées, ne sont pas supprimées).
async function detacherFacture(ligneId, intervId) {
  if (!ligneId) { toast('Lien de facture introuvable (rechargez la fiche).', 'error'); return; }
  showConfirm('Détacher cette intervention de la facture ?\nLa facture elle-même n\'est pas supprimée.', async () => {
    try {
      await IntervFacturesApi.delete(ligneId);
      toast('Intervention détachée de la facture.', 'success');
      closeModal(); setTimeout(() => editInterv(intervId), 50);
    } catch(e) { toast(e.message, 'error'); }
  }, 'Détacher');
}

// ── Notes libres (texte + date) ──────────────────────────────────────────────
function ajouterNoteLine() {
  const container = document.getElementById('f_notesContainer');
  if (!container) return;
  // Retirer le message "Aucune note" s'il existe
  const placeholder = container.querySelector('div[style*="color:var(--gray-text)"]');
  if (placeholder) placeholder.remove();
  const idx = container.querySelectorAll('.note-line').length;
  const div = document.createElement('div');
  div.className = 'note-line';
  div.style.cssText = 'display:flex;gap:6px;align-items:center;margin-bottom:6px';
  div.dataset.idx = idx;
  div.innerHTML = `<input class="form-control note-text" value="" placeholder="Texte libre" style="flex:1;font-size:12px">
    <input class="form-control note-date" type="date" value="" style="width:145px;font-size:12px">
    <button type="button" class="icon-btn delete" onclick="supprimerNoteLine(this)" title="Supprimer" style="flex-shrink:0">🗑️</button>`;
  container.appendChild(div);
  div.querySelector('.note-text').focus();
}

function supprimerNoteLine(btn) {
  const line = btn.closest('.note-line');
  if (line) line.remove();
  const container = document.getElementById('f_notesContainer');
  if (container && !container.querySelectorAll('.note-line').length) {
    container.innerHTML = '<div style="color:var(--gray-text);font-size:12px">Aucune note. Cliquez + pour en ajouter.</div>';
  }
}

function collectNotesJSON() {
  const container = document.getElementById('f_notesContainer');
  if (!container) return '[]';
  const notes = [];
  container.querySelectorAll('.note-line').forEach(line => {
    const texte = (line.querySelector('.note-text')?.value || '').trim();
    const date  = line.querySelector('.note-date')?.value || '';
    if (texte || date) notes.push({ texte, date });
  });
  return JSON.stringify(notes);
}

async function genererNumero() {
  try {
    const r = await apiRequest('interv_autonumero');
    const el = document.getElementById('f_numero');
    if (el) el.value = r.numero || '';
  } catch(e) { toast('Erreur génération numéro', 'error'); }
}

function prefillPrixStock() {
  const sel = document.getElementById('sel_stock');
  if (!sel) return;
  const prix = parseFloat(sel.selectedOptions[0]?.dataset.prix||0);
  const el = document.getElementById('sel_prix');
  if (el && prix > 0) el.value = prix.toFixed(2);
}

async function ajouterLigneStock(intervId) {
  const stockId = parseInt(gv('sel_stock'));
  const qte     = parseInt(gv('sel_qte'))||1;
  const prix    = parseFloat(gv('sel_prix'))||0;
  const opt     = document.getElementById('sel_stock')?.selectedOptions[0];
  const maxQte  = parseInt(opt?.dataset.qte||0);
  if (!stockId) { toast('Sélectionnez un article.', 'error'); return; }
  if (qte > maxQte) { toast(`Stock insuffisant — disponible : ${maxQte}`, 'error'); return; }
  try {
    await IntervLignesApi.create(intervId, { stockId, quantite: qte, prixUnitaire: prix });
    toast('Pièce ajoutée, stock mis à jour.', 'success');
    closeModal(); setTimeout(() => editInterv(intervId), 50);
  } catch(e) { toast(e.message, 'error'); }
}

async function supprimerLigneStock(ligneId, intervId) {
  showConfirm('Retirer cette pièce ? La quantité sera restituée au stock.', async () => {
    try {
      await IntervLignesApi.delete(ligneId);
      toast('Pièce retirée, stock restitué.', 'success');
      closeModal(); setTimeout(() => editInterv(intervId), 50);
    } catch(e) { toast(e.message, 'error'); }
  }, 'Retirer');
}

// ═══════════════════════════════════════════════════════════════════════════════
// ── Pièces stock en attente (avant sauvegarde de l'intervention) ────────────
// Même pattern que les documents pending : stockées en mémoire, envoyées après
// ═══════════════════════════════════════════════════════════════════════════════
var _pendingStockLignes = [];

function ajouterLigneStockPending() {
  var sel = document.getElementById('sel_stock');
  var stockId = parseInt(gv('sel_stock'));
  var qte     = parseInt(gv('sel_qte')) || 1;
  var prix    = parseFloat(gv('sel_prix')) || 0;
  var opt     = sel?.selectedOptions[0];
  var maxQte  = parseInt(opt?.dataset.qte || 0);
  var nom     = opt?.textContent?.split(' — ')[0]?.trim() || 'Article';

  if (!stockId) { toast('Sélectionnez un article.', 'error'); return; }

  // Vérifier le stock disponible en tenant compte des lignes pending déjà ajoutées
  var dejaReserve = _pendingStockLignes.filter(function(l){ return l.stockId === stockId; })
    .reduce(function(s,l){ return s + l.quantite; }, 0);
  if (qte + dejaReserve > maxQte) {
    toast('Stock insuffisant — disponible : ' + (maxQte - dejaReserve) + ' (dont ' + dejaReserve + ' déjà en attente)', 'error');
    return;
  }

  _pendingStockLignes.push({ stockId: stockId, quantite: qte, prixUnitaire: prix, nom: nom });
  _renderPendingStockLignes();
  toast('Pièce ajoutée (en attente de sauvegarde).', 'info');
}

function retirerLigneStockPending(idx) {
  _pendingStockLignes.splice(idx, 1);
  _renderPendingStockLignes();
}

function _renderPendingStockLignes() {
  var container = document.getElementById('pendingStockLignes');
  if (!container) return;
  if (!_pendingStockLignes.length) {
    container.innerHTML = '';
    return;
  }
  var fmtMon = function(v) { return new Intl.NumberFormat('fr-FR',{style:'currency',currency:'EUR'}).format(v||0); };
  var html = '<div style="background:#eff6ff;border:1px solid #93c5fd;border-radius:8px;padding:8px 12px;margin-top:4px">';
  html += '<div style="font-size:10px;font-weight:700;color:#1d4ed8;margin-bottom:6px">📦 Pièces en attente de sauvegarde (' + _pendingStockLignes.length + ')</div>';
  for (var i = 0; i < _pendingStockLignes.length; i++) {
    var l = _pendingStockLignes[i];
    html += '<div style="display:flex;align-items:center;gap:8px;padding:4px 0;border-bottom:1px solid #bfdbfe;font-size:12px">';
    html += '<span style="flex:1;font-weight:500">' + l.nom + '</span>';
    html += '<span style="color:var(--gray-text)">' + l.quantite + ' × ' + fmtMon(l.prixUnitaire) + '</span>';
    html += '<span style="font-weight:600;min-width:60px;text-align:right">' + fmtMon(l.quantite * l.prixUnitaire) + '</span>';
    html += '<button class="icon-btn delete" onclick="retirerLigneStockPending(' + i + ')" title="Retirer" style="font-size:12px">🗑️</button>';
    html += '</div>';
  }
  var total = _pendingStockLignes.reduce(function(s,l){ return s + l.quantite * l.prixUnitaire; }, 0);
  html += '<div style="text-align:right;font-size:11px;font-weight:700;color:#1d4ed8;margin-top:4px">Total : ' + fmtMon(total) + '</div>';
  html += '</div>';
  container.innerHTML = html;
}

async function _savePendingStockLignes(intervId) {
  if (!_pendingStockLignes.length || !intervId) return;
  var success = 0;
  var failed = [];
  for (var i = 0; i < _pendingStockLignes.length; i++) {
    var l = _pendingStockLignes[i];
    try {
      await IntervLignesApi.create(intervId, { stockId: l.stockId, quantite: l.quantite, prixUnitaire: l.prixUnitaire });
      success++;
    } catch(e) {
      console.warn('Stock ligne error:', l.nom, e);
      failed.push(l.nom);
    }
  }
  _pendingStockLignes = [];
  if (success > 0) toast(success + ' pièce(s) consommée(s) du stock.', 'success');
  // ⚠️ Avant : les échecs étaient silencieux (console seulement). On informe
  // désormais l'utilisateur des pièces non enregistrées (stock insuffisant pris
  // entre-temps, etc.) pour qu'il puisse les ressaisir depuis la fiche.
  if (failed.length) toast('Non consommée(s) : ' + failed.join(', ') + '. Vérifiez le stock puis réessayez depuis la fiche.', 'error');
}

async function prendreEnChargeIntervention(id) {
  showConfirm('Prendre en charge cette intervention ? Votre nom sera associé comme agent.', async () => {
    try {
      await apiRequest('prise_en_charge_intervention', 'POST', {}, id);
      toast('Intervention prise en charge.', 'success');
      renderInterventions();
    } catch(e) { toast(e.message, 'error'); }
  }, 'Prendre en charge');
}

// ── Déléguer une intervention à quelqu'un d'autre ───────────────────────────
async function deleguerIntervention(id) {
  // Charger l'intervention pour connaître l'agent actuel
  const all = await InterventionsApi.getAll();
  const inv = all.find(x => x.Id === id) || {};
  const agentActuel = [inv.AgentPrenom, inv.AgentNom].filter(Boolean).join(' ') || 'Inconnu';

  // Charger la liste des utilisateurs éligibles (Admin / Gestionnaire)
  let users = [];
  try { users = await apiRequest('utilisateurs'); } catch(e) {}
  const eligibles = (users || []).filter(u => u.Role === 'Admin' || u.Role === 'Gestionnaire');
  // Exclure l'agent actuel de la liste pour éviter de se "déléguer à soi-même"
  const currentEmail = (inv.AgentEmail || '').toLowerCase();
  const currentName  = [(inv.AgentPrenom||''), (inv.AgentNom||'')].join(' ').trim().toLowerCase();
  const dispo = eligibles.filter(u => {
    const email = (u.Email || '').toLowerCase();
    const name  = [(u.Prenom||''), (u.Nom||'')].join(' ').trim().toLowerCase();
    if (email && email === currentEmail) return false;
    if (name && name === currentName) return false;
    return true;
  });

  // Construire le <select> : 1ère option vide (placeholder) pour forcer un choix conscient,
  // puis la liste triée, puis "Autre (saisir manuellement)"
  const optsUsers = dispo
    .sort((a,b) => (a.Nom||'').localeCompare(b.Nom||''))
    .map(u => {
      const label = [(u.Prenom||''), (u.Nom||'')].filter(Boolean).join(' ') + (u.Login ? ' (' + u.Login + ')' : '');
      // Infos encodées dans data-* pour remplir les champs sans re-fetch
      return '<option value="' + u.Id + '"'
           + ' data-nom="' + (u.Nom || '').replace(/"/g,'&quot;') + '"'
           + ' data-prenom="' + (u.Prenom || '').replace(/"/g,'&quot;') + '"'
           + ' data-tel="' + (u.TelMobile || u.TelPro || u.Tel || '').replace(/"/g,'&quot;') + '"'
           + ' data-email="' + (u.Email || '').replace(/"/g,'&quot;') + '"'
           + '>' + label + '</option>';
    })
    .join('');

  openModal('🔄 Déléguer l\'intervention', `
    <div style="margin-bottom:16px;background:#eff6ff;border:1px solid #93c5fd;border-radius:8px;padding:12px;font-size:12px;color:#1e40af">
      <strong>Agent actuel :</strong> ${agentActuel}
      ${inv.AgentEmail ? ' (' + inv.AgentEmail + ')' : ''}
      <br><span style="font-size:11px;color:var(--gray-text)">L'historique de prise en charge sera conservé dans le commentaire.</span>
    </div>
    <div class="form-grid">
      <div class="form-group" style="grid-column:span 2">
        <label class="form-label">Déléguer à *</label>
        <select class="form-control" id="del_user" onchange="_delFillFromUser(this)">
          <option value="">— Sélectionner un agent —</option>
          ${optsUsers}
          <option value="__manual__">✏️ Autre (saisir manuellement)</option>
        </select>
      </div>
      <div class="form-group" id="del_grp_nom" style="display:none">
        <label class="form-label">Nom *</label>
        <input class="form-control" id="del_nom" placeholder="Nom">
      </div>
      <div class="form-group" id="del_grp_prenom" style="display:none">
        <label class="form-label">Prénom</label>
        <input class="form-control" id="del_prenom" placeholder="Prénom">
      </div>
      <div class="form-group" id="del_grp_tel" style="display:none">
        <label class="form-label">Téléphone</label>
        <input class="form-control" id="del_tel" placeholder="06...">
      </div>
      <div class="form-group" id="del_grp_email" style="display:none">
        <label class="form-label">Email</label>
        <input class="form-control" id="del_email" placeholder="email@...">
      </div>
      <div class="form-group" style="grid-column:span 2">
        <label class="form-label">Motif de la délégation</label>
        <input class="form-control" id="del_motif" placeholder="Ex: Absence, changement d'affectation...">
      </div>
    </div>
  `, async () => {
    const sel = document.getElementById('del_user');
    const selectedVal = sel?.value || '';
    if (!selectedVal) { toast('Sélectionnez un agent.', 'error'); return; }

    let nom, prenom, tel, email;
    if (selectedVal === '__manual__') {
      nom    = (document.getElementById('del_nom')?.value || '').trim();
      prenom = (document.getElementById('del_prenom')?.value || '').trim();
      tel    = (document.getElementById('del_tel')?.value || '').trim();
      email  = (document.getElementById('del_email')?.value || '').trim();
      if (!nom) { toast('Le nom est obligatoire.', 'error'); return; }
    } else {
      const opt = sel.options[sel.selectedIndex];
      nom    = opt.getAttribute('data-nom') || '';
      prenom = opt.getAttribute('data-prenom') || '';
      tel    = opt.getAttribute('data-tel') || '';
      email  = opt.getAttribute('data-email') || '';
      if (!nom) { toast('Agent sélectionné invalide.', 'error'); return; }
    }

    try {
      await apiRequest('deleguer_intervention', 'POST', {
        agentNom:    nom,
        agentPrenom: prenom,
        agentTel:    tel,
        agentEmail:  email,
        motif:       (document.getElementById('del_motif')?.value || '').trim(),
      }, id);
      toast('Intervention déléguée à ' + (prenom ? prenom + ' ' : '') + nom + '.', 'success');
      closeModal();
      renderInterventions();
    } catch(e) { toast(e.message, 'error'); }
  }, 'Déléguer');
}

// Montre/cache les champs manuels selon la sélection du <select>
function _delFillFromUser(sel) {
  const isManual = sel.value === '__manual__';
  ['nom','prenom','tel','email'].forEach(k => {
    const grp = document.getElementById('del_grp_' + k);
    if (grp) grp.style.display = isManual ? '' : 'none';
    const inp = document.getElementById('del_' + k);
    if (inp && isManual) inp.value = '';
  });
}

async function delaissserIntervention(id) {
  showConfirm('Délaisser cette intervention ?\nL\'agent sera retiré. Le statut de l\'intervention est conservé. L\'historique sera enregistré dans le commentaire.', async function() {
    try {
      await apiRequest('delaisser_intervention', 'POST', {}, id);
      toast('Intervention délaissée — agent retiré, statut conservé.', 'success');
      renderInterventions();
    } catch(e) { toast(e.message, 'error'); }
  }, 'Délaisser');
}

/**
 * Clôture d'une intervention (statuts "En cours" ou "Réalisée").
 *
 * Pré-requis : les champs CBDC/CHMA (TypeBudget), Date envoi mail
 * (DateEnvoiMail) et Date de réalisation (DateRealisation) doivent être
 * remplis dans le formulaire d'édition — sauf si la case « Cette intervention
 * ne nécessite pas de suivi administratif » (SansSuiviAdmin) y a été cochée,
 * auquel cas aucun des trois n'est exigé. Le bouton "Clôturer" est masqué/
 * désactivé tant que ce n'est pas le cas (et le serveur revalide).
 *
 * Cette modale demande uniquement :
 *  - un commentaire de clôture (optionnel, visible côté demandeur si
 *    propagation à la demande)
 *  - si une demande est liée : la confirmation de la clôturer aussi
 *
 * Passe l'intervention au statut "Validée" (affiché "Terminée").
 */
async function cloturerIntervention(id) {
  // Récupérer l'intervention pour : confirmer les pré-requis côté client, savoir
  // si une demande est liée, afficher son titre, et résumer les bons dans la modale.
  let inv = null;
  try {
    const all = await InterventionsApi.getAll();
    inv = all.find(i => i.Id === id) || null;
  } catch(_) { /* tolérer : le serveur revalidera */ }

  if (!inv) {
    toast('Intervention introuvable.', 'error');
    return;
  }

  // Double-vérification côté client (le bouton ne devrait pas s'afficher sinon,
  // mais on protège contre un appel direct). La case « pas de suivi
  // administratif » de la fiche dispense des 3 champs.
  const sansSuiviAdmin = !!inv.SansSuiviAdmin;
  const manquants = [];
  if (!sansSuiviAdmin) {
    if (!inv.TypeBudget)      manquants.push('CBDC/CHMA');
    if (!inv.DateRealisation) manquants.push('Date de réalisation');
    if (!inv.DateEnvoiMail)   manquants.push('Date envoi mail');
  }
  if (manquants.length) {
    toast('Clôture impossible — manque : ' + manquants.join(', ') + '. Éditez l\'intervention pour renseigner ces champs, ou cochez « Pas de suivi administratif ».', 'error');
    return;
  }

  const aUneDemande  = !!inv.DemandeId;
  const titreDemande = inv.DemandeTitre || '';
  const escapeHtml = s => String(s || '').replace(/[<>&"']/g, c => ({'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;',"'":'&#39;'}[c]));

  const html = `
    <div style="display:flex;flex-direction:column;gap:14px">
      <div style="font-size:13px;color:var(--text-mid);line-height:1.5">
        L'intervention sera marquée comme <strong>terminée</strong>.
        ${aUneDemande
          ? `<br><br>📝 Elle est liée à la demande : <strong>${escapeHtml(titreDemande || 'Demande liée')}</strong>`
          : ''}
      </div>

      <!-- Rappel des bons (lecture seule) : on confirme ce qui a été saisi -->
      <div style="padding:12px 14px;background:var(--gray-bg);border-radius:8px;border-left:3px solid ${sansSuiviAdmin?'var(--gray-border)':'var(--teal)'};font-size:12.5px;line-height:1.7">
        <div style="font-weight:600;color:var(--navy);margin-bottom:4px">📄 Suivi administratif</div>
        ${sansSuiviAdmin
          ? `<div style="color:var(--gray-text)">🚫 Intervention marquée « sans suivi administratif » — pas de CBDC/CHMA ni de dates requis.</div>
             ${inv.TypeBudget ? `<div>Saisi malgré tout : <strong>${escapeHtml(inv.TypeBudget)}</strong></div>` : ''}
             ${inv.DateRealisation ? `<div>Réalisée le <strong>${fmtD(inv.DateRealisation)}</strong></div>` : ''}`
          : `<div><strong>${escapeHtml(inv.TypeBudget)}</strong> · envoyé le <strong>${fmtD(inv.DateEnvoiMail)}</strong></div>
             <div>Réalisée le <strong>${fmtD(inv.DateRealisation)}</strong></div>`}
        ${inv.CodeNumeroDemande ? `<div>Code n° demande : <strong>${escapeHtml(inv.CodeNumeroDemande)}</strong></div>` : ''}
        ${(parseFloat(inv.MontantHT)||0)>0 ? `<div>Prix HT : <strong>${fmtMon(inv.MontantHT)}</strong></div>` : ''}
      </div>

      <div>
        <label class="form-label" style="display:block;margin-bottom:4px">
          Commentaire de clôture ${aUneDemande ? '<span style="color:var(--gray-text);font-weight:400">(visible par le demandeur si vous clôturez la demande)</span>' : '<span style="color:var(--gray-text);font-weight:400">(optionnel)</span>'}
        </label>
        <textarea id="cl_commentaire" class="form-control" rows="4"
          placeholder="${aUneDemande ? 'Ex : Fuite réparée, robinet remplacé. Merci de signaler tout nouvel incident.' : 'Notes internes sur la clôture (optionnel)'}"
          maxlength="2000"></textarea>
      </div>

      ${aUneDemande ? `
      <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;padding:10px 12px;background:var(--gray-bg);border-radius:8px">
        <input type="checkbox" id="cl_cloturer_demande" checked>
        <span>Clôturer aussi la demande liée (statut « Traité »)</span>
      </label>` : ''}
    </div>
  `;

  const titre = aUneDemande ? '🏁 Clôturer l’intervention et la demande' : '🏁 Clôturer l’intervention';
  openModal(titre, html, async () => {
    const commentaire = (document.getElementById('cl_commentaire')?.value || '').trim();
    const cloturerDemande = aUneDemande
      ? !!document.getElementById('cl_cloturer_demande')?.checked
      : false;

    try {
      const res = await InterventionActionsApi.valider(id, {
        commentaire,
        cloturer_demande: cloturerDemande,
      });
      const msg = res?.demandeCloturee
        ? 'Intervention et demande clôturées.'
        : 'Intervention clôturée. Archivable dans 7 jours.';
      toast(msg, 'success');
      closeModal();
      renderInterventions();
    } catch(e) {
      toast(e.message || 'Erreur de clôture', 'error');
    }
  }, 'Clôturer', 'Annuler', '560px');
}

// Alias rétro-compat — si du code historique appelle encore validerIntervention.
async function validerIntervention(id) { return cloturerIntervention(id); }

async function archiverIntervention(id, manuel = false) {
  const msg = manuel
    ? 'Archiver manuellement cette intervention avant le délai de 7 jours ? Elle ira dans l\'historique.'
    : 'Archiver cette intervention ? Elle ira dans l\'historique.';
  showConfirm(msg, async () => {
    try {
      await InterventionActionsApi.archiver(id);
      toast('Archivée.', 'success');
      renderInterventions();
    } catch(e) { toast(e.message, 'error'); }
  }, 'Archiver');
}

/**
 * Suppression d'une intervention avec raison optionnelle.
 *
 * Pourquoi une raison ? Supprimer ≠ "résolu/fixé" : ça peut être un devis trop
 * cher qu'on abandonne, un problème qui s'est résolu seul, un doublon, une
 * erreur de saisie… La raison est libre, optionnelle, et journalisée côté
 * serveur (PHP error_log) pour garder une trace après la suppression
 * physique de la ligne.
 */
async function deleteInterv(id) {
  const html = `
    <div style="display:flex;flex-direction:column;gap:12px">
      <div style="font-size:13px;color:var(--text-mid);line-height:1.5">
        Cette intervention sera <strong style="color:var(--red)">définitivement supprimée</strong>.
        Cette action est irréversible.
      </div>
      <div style="font-size:12px;color:var(--gray-text);line-height:1.5;padding:10px 12px;background:var(--gray-bg);border-radius:6px">
        💡 Supprimer ≠ « problème résolu ». Si l'intervention a bien été réalisée,
        utilisez plutôt le bouton <strong>🏁 Clôturer</strong>. La suppression sert
        aux interventions abandonnées (devis trop cher, doublon, erreur de saisie…).
      </div>
      <div>
        <label class="form-label" style="display:block;margin-bottom:4px">
          Raison de la suppression <span style="color:var(--gray-text);font-weight:400">(optionnelle, conservée dans les logs)</span>
        </label>
        <textarea id="del_raison" class="form-control" rows="3"
          placeholder="Ex : devis refusé (trop cher), problème résolu spontanément, doublon avec INT-202501-0042…"
          maxlength="500"></textarea>
      </div>
    </div>
  `;
  openModal('🗑️ Supprimer l\'intervention', html, async () => {
    const raison = (document.getElementById('del_raison')?.value || '').trim();
    try {
      await InterventionsApi.delete(id, raison);
      toast('Intervention supprimée.', 'success');
      closeModal();
      renderInterventions();
    } catch(e) {
      toast(e.message || 'Erreur de suppression', 'error');
    }
  }, 'Supprimer', 'Annuler', '520px');
  // Donner un style "danger" au bouton de validation
  setTimeout(() => {
    const btn = document.getElementById('modalSaveBtn');
    if (btn) { btn.classList.remove('btn-primary'); btn.classList.add('btn-danger'); }
  }, 30);
}

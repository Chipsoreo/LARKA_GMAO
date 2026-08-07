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
 * Larka — Page : Historique des suppressions
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Trace de toutes les suppressions effectuées dans Larka.
 * Permet aux administrateurs de retrouver les éléments supprimés
 * avec la date, l'auteur, le type et les données au moment de la suppression.
 *
 * POINT D'ENTRÉE : renderHistorique()
 * ═══════════════════════════════════════════════════════════════════════════════
 */

const HistoriqueState = {
  showSuppressions:   true,
  showDemandes:       true,
  showInterventions:  true,
  showComptaClotures: true,
  showSorties:        true,
  module:             '',
  dateDebut:          '',
  dateFin:            '',
  statutDemande:      '',
};

async function renderHistorique() {
  document.getElementById('topbarActions').innerHTML = '';
  const c = document.getElementById('mainContent');
  showLoading(c);

  try {
    const user = App.currentUser;
    const isManager = ['Admin','Gestionnaire'].includes(user?.Role);

    const [suppressions, toutesLesDemandes, interventionsArchivees, toutesCompta, tousBiens, tousEquipements] = await Promise.all([
      isManager ? HistoriqueApi.getAll() : Promise.resolve([]),
      DemandesApi.getAll(),
      isManager ? InterventionActionsApi.getArchivees().catch(()=>[]) : Promise.resolve([]),
      isManager ? GestionMaterielApi.getAll().catch(()=>[]) : Promise.resolve([]),
      isManager ? BiensApi.getAll().catch(()=>[]) : Promise.resolve([]),
      isManager ? EquipementsApi.getAll().catch(()=>[]) : Promise.resolve([]),
    ]);

    // Toutes les demandes terminées (Traité ou Refusé)
    const demandes = toutesLesDemandes.filter(d => ['Traité','Refusé'].includes(d.Statut));
    // Fiches compta clôturées
    const comptaClotures = toutesCompta.filter(r => r.EstCloture == 1);

    // Set des assets qui ont une fiche compta (pour le badge "non comptabilisé")
    const comptaAssetKeys = new Set(toutesCompta.map(r => `${escHtml(r.AssetType||'')}-${r.AssetId}`));

    _renderHistorique(c, suppressions, demandes, interventionsArchivees, comptaClotures, tousBiens, tousEquipements, comptaAssetKeys, isManager);
  } catch(e) { c.innerHTML = errorHtml(e.message); }
}

async function renderDemandesArchives() {
  return renderHistorique();
}

function _renderHistorique(c, suppressions, demandes, interventionsArchivees, comptaClotures, tousBiens, tousEquipements, comptaAssetKeys, isManager) {
  const search = (App.searchTerm||'').toLowerCase();
  const st = HistoriqueState;
  const perPage = App.getRowsPerPage('historique');

  // ── Lire l'état FiltresEngine (remplace les anciens champs de HistoriqueState) ──
  const fe = FiltresEngine.getState('historique');
  const feFrom = fe.dates_from || '';
  const feTo   = fe.dates_to   || '';
  const feModule       = fe.TableSource    || '';
  const feStatutDem    = (fe.StatutDemande && fe.StatutDemande !== 'Tous') ? fe.StatutDemande : '';
  const feTypeInterv   = fe.TypeInterv     || '';
  const feAuteur       = (fe.Auteur        || '').toLowerCase();

  let html = `
  <!-- Barre contrôles -->
  <div class="card" style="padding:0;margin-bottom:20px">
    <div style="padding:14px 20px;border-bottom:1px solid var(--gray-border)">
      <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
        <div class="search-input-wrap" style="flex:1;min-width:200px">
          <span class="search-icon">🔍</span>
          <input class="search-input" type="text" placeholder="Rechercher…"
            value="${App.searchTerm||''}" oninput="App.handleSearch(this.value)" onkeydown="App.handleSearchKey(event)">
        </div>
        ${FiltresEngine.renderButton('historique')}
        <select class="form-control" style="font-size:13px;width:auto;padding:6px 10px" onchange="App.setRowsPerPage(this.value,'historique')" title="Lignes par page">
          <option value="10"  ${perPage===10?'selected':''}>10</option>
          <option value="25"  ${perPage===25?'selected':''}>25</option>
          <option value="50"  ${perPage===50?'selected':''}>50</option>
          <option value="100" ${perPage===100?'selected':''}>100</option>
          <option value="200" ${perPage===200?'selected':''}>200</option>
          <option value="all" ${perPage==='all'?'selected':''}>Tout</option>
        </select>
        ${isManager ? `
        <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;user-select:none">
          <input type="checkbox" id="chkSup" ${st.showSuppressions?'checked':''} onchange="toggleHisto('sup',this.checked)" style="width:15px;height:15px">
          <span>🗑️ Suppressions</span>
          <span style="background:var(--gray-border);border-radius:10px;padding:1px 8px;font-size:11px">${suppressions.length}</span>
        </label>` : ''}
        <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;user-select:none">
          <input type="checkbox" id="chkDem" ${st.showDemandes?'checked':''} onchange="toggleHisto('dem',this.checked)" style="width:15px;height:15px">
          <span>📁 Demandes clôturées</span>
          <span style="background:var(--gray-border);border-radius:10px;padding:1px 8px;font-size:11px">${demandes.length}</span>
        </label>
        ${isManager ? `
        <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;user-select:none">
          <input type="checkbox" id="chkInterv" ${st.showInterventions?'checked':''} onchange="toggleHisto('interv',this.checked)" style="width:15px;height:15px">
          <span>🔧 Interventions archivées</span>
          <span style="background:var(--gray-border);border-radius:10px;padding:1px 8px;font-size:11px">${interventionsArchivees.length}</span>
        </label>
        <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;user-select:none">
          <input type="checkbox" id="chkCompta" ${st.showComptaClotures?'checked':''} onchange="toggleHisto('compta',this.checked)" style="width:15px;height:15px">
          <span>🔒 Fiches compta clôturées</span>
          <span style="background:var(--gray-border);border-radius:10px;padding:1px 8px;font-size:11px">${comptaClotures.length}</span>
        </label>
        <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;user-select:none">
          <input type="checkbox" id="chkSorties" ${st.showSorties?'checked':''} onchange="toggleHisto('sorties',this.checked)" style="width:15px;height:15px">
          <span>📋 Inventaire complet</span>
          <span style="background:var(--gray-border);border-radius:10px;padding:1px 8px;font-size:11px">${tousBiens.length + tousEquipements.length}</span>
        </label>` : ''}
      </div>
    </div>

    ${FiltresEngine.renderPanel('historique')}
  </div>`;

  let hasContent = false;

  // ── Bloc interventions archivées ───────────────────────────────────────────────
  if (isManager && st.showInterventions) {
    let rows = interventionsArchivees;
    if (feFrom) rows = rows.filter(r => (r.DateArchivage||r.DateValidation||r.DateRealisation) >= feFrom);
    if (feTo)   rows = rows.filter(r => (r.DateArchivage||r.DateValidation||r.DateRealisation) <= feTo + 'T23:59:59');
    if (feTypeInterv) rows = rows.filter(r => r.Type === feTypeInterv);
    if (feAuteur)     rows = rows.filter(r => [r.SocieteManuelle, r.TechnicienNom, r.CreatedBy].some(v=>(v||'').toLowerCase().includes(feAuteur)));
    if (search) rows = rows.filter(r =>
      [r.Numero, r.Description, r.SocieteManuelle, r.BienNumero, r.DemandeTitre, r.Type].some(v=>(v||'').toLowerCase().includes(search))
    );

    // Pagination
    const keyInterv = 'historique_interv';
    const totalInterv = rows.length;
    const totalPagesInterv = (perPage === 'all') ? 1 : Math.max(1, Math.ceil(totalInterv / perPage));
    let pageInterv = App.getPage(keyInterv);
    if (pageInterv > totalPagesInterv) { pageInterv = totalPagesInterv; App.setPageSilently(pageInterv, keyInterv); }
    const startInterv = (perPage === 'all') ? 0 : (pageInterv - 1) * perPage;
    const pageRowsInterv = (perPage === 'all') ? rows : rows.slice(startInterv, startInterv + perPage);
    const fromInterv = totalInterv === 0 ? 0 : (startInterv + 1);
    const toInterv   = totalInterv === 0 ? 0 : (startInterv + pageRowsInterv.length);

    hasContent = true;
    const typeColors = {Préventive:'blue',Curative:'orange','Contrôle réglementaire':'purple',Divers:'gray'};
    const fmtMon = v => new Intl.NumberFormat('fr-FR',{style:'currency',currency:'EUR'}).format(v||0);

    html += `
    <div class="card" style="margin-bottom:20px">
      <div class="card-header">
        <div class="card-title">🔧 Interventions archivées</div>
        <span style="font-size:12px;color:var(--gray-text)">${totalInterv} entrée${totalInterv>1?'s':''}</span>
      </div>
      <div class="table-wrap"><table><thead><tr>
        <th>N°</th><th>Type</th><th>Description</th><th>Bien</th><th>Coût total</th><th>Date réalisation</th><th>Archivée le</th>
      </tr></thead><tbody>`;

    if (!totalInterv) {
      html += `<tr><td colspan="7" class="no-results">Aucune intervention archivée.</td></tr>`;
    } else {
      pageRowsInterv.forEach(r => {
        const coutTotal = (parseFloat(r.MontantPieces)||0) + (parseFloat(r.MontantMainOeuvre)||0) + (parseFloat(r.Montant)||0) + (parseFloat(r.MontantHT)||0);
        const color = typeColors[r.Type] || 'gray';
        html += `<tr style="cursor:pointer" onclick="voirInterventionArchivee(${r.Id})"
          onmouseover="this.style.background='var(--gray-bg)'" onmouseout="this.style.background=''">
          <td style="font-family:monospace;font-size:12px">${escHtml(r.Numero||'—')}</td>
          <td><span class="badge badge-${color}">${escHtml(r.Type||'—')}</span></td>
          <td style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${escHtml(r.Description||'—')}</td>
          <td style="color:var(--gray-text);font-size:12px">${r.BienNumero?`${escHtml(r.BienNumero||'')}${r.BienLabel?' — '+r.BienLabel:''}` : '—'}</td>
          <td style="font-weight:600;color:var(--blue)">${coutTotal>0?fmtMon(coutTotal):'—'}</td>
          <td style="color:var(--gray-text);font-size:12px">${r.DateRealisation ? new Date(r.DateRealisation).toLocaleDateString('fr-FR') : '—'}</td>
          <td style="color:var(--gray-text);font-size:12px">${r.DateArchivage ? new Date(r.DateArchivage).toLocaleDateString('fr-FR') : '—'}</td>
        </tr>`;
      });
    }

    const canPrev = pageInterv > 1;
    const canNext = pageInterv < totalPagesInterv;
    const prevStyle = canPrev ? '' : 'opacity:.45;pointer-events:none;';
    const nextStyle = canNext ? '' : 'opacity:.45;pointer-events:none;';
    html += `</tbody></table></div>
      <div class="pagination">
        <span>${totalInterv} enregistrement${totalInterv>1?'s':''} — Affichage ${fromInterv}-${toInterv}</span>
        ${totalPagesInterv>1 ? `
          <div style="display:flex;align-items:center;gap:8px">
            <button class="btn btn-ghost btn-sm" style="${prevStyle}" onclick="App.setPage(${pageInterv-1},'${keyInterv}')">←</button>
            <span style="font-size:12px;color:var(--gray-text)">Page ${pageInterv} / ${totalPagesInterv}</span>
            <button class="btn btn-ghost btn-sm" style="${nextStyle}" onclick="App.setPage(${pageInterv+1},'${keyInterv}')">→</button>
          </div>` : ''}
      </div>
    </div>`;
  }

  // ── Bloc suppressions ──────────────────────────────────────────────────────────
  if (isManager && st.showSuppressions) {
    let rows = suppressions;
    if (feModule)     rows = rows.filter(r => r.TableSource === feModule);
    if (feFrom)  rows = rows.filter(r => r.DateSuppression >= feFrom);
    if (feTo)    rows = rows.filter(r => r.DateSuppression <= feTo + 'T23:59:59');
    if (feAuteur)      rows = rows.filter(r => (r.SupprimeParLogin||'').toLowerCase().includes(feAuteur));
    if (search) rows = rows.filter(r =>
      [r.TableSource, r.Numero, r.Description, r.SupprimeParLogin].some(v=>(v||'').toLowerCase().includes(search))
    );

    // Pagination
    const keySup = 'historique_sup';
    const totalSup = rows.length;
    const totalPagesSup = (perPage === 'all') ? 1 : Math.max(1, Math.ceil(totalSup / perPage));
    let pageSup = App.getPage(keySup);
    if (pageSup > totalPagesSup) { pageSup = totalPagesSup; App.setPageSilently(pageSup, keySup); }
    const startSup = (perPage === 'all') ? 0 : (pageSup - 1) * perPage;
    const pageRowsSup = (perPage === 'all') ? rows : rows.slice(startSup, startSup + perPage);
    const fromSup = totalSup === 0 ? 0 : (startSup + 1);
    const toSup   = totalSup === 0 ? 0 : (startSup + pageRowsSup.length);

    hasContent = true;
    html += `
    <div class="card" style="margin-bottom:20px">
      <div class="card-header">
        <div class="card-title">🗑️ Historique des suppressions</div>
        <span style="font-size:12px;color:var(--gray-text)">${totalSup} entrée${totalSup>1?'s':''}</span>
      </div>
      <div class="table-wrap"><table><thead><tr>
        <th>Module</th><th>N° / Réf.</th><th>Description</th><th>Supprimé par</th><th>Date</th>
      </tr></thead><tbody>`;

    if (!totalSup) {
      html += `<tr><td colspan="5" class="no-results">Aucune suppression trouvée.</td></tr>`;
    } else {
      const modColors = {
        Biens:'blue', Equipements:'teal', Interventions:'orange',
        Contrats:'purple', Stock:'gray', GestionMateriel:'green'
      };
      pageRowsSup.forEach(r => {
        const color = modColors[r.TableSource] || 'gray';
        html += `<tr style="cursor:pointer" onclick="voirSuppression(${r.Id})"
          onmouseover="this.style.background='var(--gray-bg)'" onmouseout="this.style.background=''">
          <td><span class="badge badge-${color}">${r.TableSource||'—'}</span></td>
          <td style="font-family:monospace;font-size:12px">${escHtml(r.Numero||'—')}</td>
          <td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${escHtml(r.Description||'—')}</td>
          <td style="color:var(--gray-text)">${r.SupprimeParLogin||'—'}</td>
          <td style="color:var(--gray-text);font-size:12px">${r.DateSuppression ? new Date(r.DateSuppression).toLocaleDateString('fr-FR') : '—'}</td>
        </tr>`;
      });
    }

    const canPrev = pageSup > 1;
    const canNext = pageSup < totalPagesSup;
    const prevStyle = canPrev ? '' : 'opacity:.45;pointer-events:none;';
    const nextStyle = canNext ? '' : 'opacity:.45;pointer-events:none;';
    html += `</tbody></table></div>
      <div class="pagination">
        <span>${totalSup} enregistrement${totalSup>1?'s':''} — Affichage ${fromSup}-${toSup}</span>
        ${totalPagesSup>1 ? `
          <div style="display:flex;align-items:center;gap:8px">
            <button class="btn btn-ghost btn-sm" style="${prevStyle}" onclick="App.setPage(${pageSup-1},'${keySup}')">←</button>
            <span style="font-size:12px;color:var(--gray-text)">Page ${pageSup} / ${totalPagesSup}</span>
            <button class="btn btn-ghost btn-sm" style="${nextStyle}" onclick="App.setPage(${pageSup+1},'${keySup}')">→</button>
          </div>` : ''}
      </div>
    </div>`;
  }

  // ── Bloc demandes clôturées ────────────────────────────────────────────────────
  if (st.showDemandes) {
    let rows = demandes;
    if (feStatutDem) rows = rows.filter(d => d.Statut === feStatutDem);
    if (feFrom)     rows = rows.filter(d => (d.DateTraitement||d.DateCreation) >= feFrom);
    if (feTo)       rows = rows.filter(d => (d.DateTraitement||d.DateCreation) <= feTo + 'T23:59:59');
    if (search) rows = rows.filter(d =>
      [d.Titre, d.Description, d.NomDeclarant, d.Batiment, d.CommentaireAdmin].some(v=>(v||'').toLowerCase().includes(search))
    );

    // Pagination
    const keyDem = 'historique_dem';
    const totalDem = rows.length;
    const totalPagesDem = (perPage === 'all') ? 1 : Math.max(1, Math.ceil(totalDem / perPage));
    let pageDem = App.getPage(keyDem);
    if (pageDem > totalPagesDem) { pageDem = totalPagesDem; App.setPageSilently(pageDem, keyDem); }
    const startDem = (perPage === 'all') ? 0 : (pageDem - 1) * perPage;
    const pageRowsDem = (perPage === 'all') ? rows : rows.slice(startDem, startDem + perPage);
    const fromDem = totalDem === 0 ? 0 : (startDem + 1);
    const toDem   = totalDem === 0 ? 0 : (startDem + pageRowsDem.length);

    hasContent = true;
    const bU = u => { const m={Urgente:'red',Haute:'orange',Normale:'blue',Basse:'teal'}; return `<span class="badge badge-${m[u]||'gray'}">${u||'—'}</span>`; };
    const bS = s => { const m={Traité:'teal',Refusé:'red'}; return `<span class="badge badge-${m[s]||'gray'}">${s||'—'}</span>`; };

    html += `
    <div class="card">
      <div class="card-header">
        <div class="card-title">📁 Demandes clôturées ${!isManager ? '— Mes demandes' : ''}</div>
        <span style="font-size:12px;color:var(--gray-text)">${totalDem} demande${totalDem>1?'s':''}</span>
      </div>
      <div class="table-wrap"><table><thead><tr>
        <th>Titre</th><th>Urgence</th><th>Statut</th>
        ${isManager ? '<th>Déclarant</th>' : ''}
        <th>Lieu</th><th>Clôturée le</th><th>Réponse</th>
      </tr></thead><tbody>`;

    if (!totalDem) {
      html += `<tr><td colspan="${isManager?7:6}" class="no-results">Aucune demande clôturée.</td></tr>`;
    } else {
      pageRowsDem.forEach(d => {
        const lieu = [d.Batiment, d.Bureau?`Bureau ${escHtml(d.Bureau||'')}`:null].filter(Boolean).join(' · ') || '—';
        html += `<tr style="cursor:pointer" onclick="voirDemandeHistorique(${d.Id})"
          onmouseover="this.style.background='var(--gray-bg)'" onmouseout="this.style.background=''">
          <td style="font-weight:500;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${escHtml(d.Titre||'')}</td>
          <td>${bU(d.Urgence)}</td>
          <td>${bS(d.Statut)}</td>
          ${isManager ? `<td style="color:var(--gray-text);font-size:12px">${escHtml(d.NomDeclarant||'—')}</td>` : ''}
          <td style="color:var(--gray-text);font-size:12px">${lieu}</td>
          <td style="color:var(--gray-text);font-size:12px">${d.DateTraitement ? new Date(d.DateTraitement).toLocaleDateString('fr-FR') : '—'}</td>
          <td style="font-size:12px;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${escHtml(d.CommentaireAdmin||'—')}</td>
        </tr>`;
      });
    }

    const canPrev = pageDem > 1;
    const canNext = pageDem < totalPagesDem;
    const prevStyle = canPrev ? '' : 'opacity:.45;pointer-events:none;';
    const nextStyle = canNext ? '' : 'opacity:.45;pointer-events:none;';
    html += `</tbody></table></div>
      <div class="pagination">
        <span>${totalDem} enregistrement${totalDem>1?'s':''} — Affichage ${fromDem}-${toDem}</span>
        ${totalPagesDem>1 ? `
          <div style="display:flex;align-items:center;gap:8px">
            <button class="btn btn-ghost btn-sm" style="${prevStyle}" onclick="App.setPage(${pageDem-1},'${keyDem}')">←</button>
            <span style="font-size:12px;color:var(--gray-text)">Page ${pageDem} / ${totalPagesDem}</span>
            <button class="btn btn-ghost btn-sm" style="${nextStyle}" onclick="App.setPage(${pageDem+1},'${keyDem}')">→</button>
          </div>` : ''}
      </div>
    </div>`;
  }

  // ── Bloc fiches comptables clôturées ─────────────────────────────────────────
  if (isManager && st.showComptaClotures) {
    let rows = comptaClotures;
    if (feFrom) rows = rows.filter(r => (r.DateCloture||'') >= feFrom);
    if (feTo)   rows = rows.filter(r => (r.DateCloture||'') <= feTo + 'T23:59:59');
    if (search) rows = rows.filter(r =>
      [r.NumeroImmo, r.AssetNumero, r.AssetLabel, r.CompteImmo, r.Exercice, r.MotifCloture, r.ClotureParLogin].some(v=>(v||'').toLowerCase().includes(search))
    );
    hasContent = true;
    const fmtMon2 = v => v ? parseFloat(v).toLocaleString('fr-FR',{style:'currency',currency:'EUR'}) : '—';
    html += `
    <div class="card" style="margin-bottom:20px">
      <div class="card-header">
        <div class="card-title">🔒 Fiches comptables clôturées</div>
        <span style="font-size:12px;color:var(--gray-text)">${rows.length} fiche${rows.length>1?'s':''}</span>
      </div>
      <div class="table-wrap"><table><thead><tr>
        <th>N° Immo</th><th>Bien / Équipement</th><th>Compte</th><th>Exercice</th>
        <th style="text-align:right">Valeur achat</th><th>Amort.</th><th>Date clôture</th><th>Clôturé par</th><th>Motif</th>
      </tr></thead><tbody>`;
    if (!rows.length) {
      html += `<tr><td colspan="9" class="no-results">Aucune fiche clôturée.</td></tr>`;
    } else {
      rows.forEach(r => {
        const asset = `${r.AssetType === 'Bien' ? '🏢' : '⚙️'} ${escHtml(r.AssetNumero||'')} ${escHtml(r.AssetLabel||'')}`.trim();
        const amort = r.DureeAmortissement ? `${r.DureeAmortissement} ans` : '—';
        html += `<tr style="cursor:pointer" onclick="voirFicheComptaCloture(${r.Id})" onmouseover="this.style.background='var(--gray-bg)'" onmouseout="this.style.background=''">
          <td style="font-weight:600;font-size:12px;font-family:monospace">${escHtml(r.NumeroImmo||'—')}</td>
          <td style="font-size:13px">${asset||'—'}</td>
          <td style="font-size:12px;color:var(--gray-text)">${escHtml(r.CompteImmo||'—')}</td>
          <td style="font-size:12px;color:var(--gray-text)">${r.Exercice||'—'}</td>
          <td style="text-align:right;font-weight:600;color:var(--blue)">${fmtMon2(r.ValeurAchat)}</td>
          <td style="font-size:12px;color:var(--gray-text)">${amort}</td>
          <td style="font-size:12px;color:var(--gray-text)">${r.DateCloture ? new Date(r.DateCloture).toLocaleDateString('fr-FR') : '—'}</td>
          <td style="font-size:12px;color:var(--gray-text)">${r.ClotureParLogin||'—'}</td>
          <td style="font-size:12px;color:var(--gray-text);max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${r.MotifCloture||'—'}</td>
        </tr>`;
      });
    }
    html += `</tbody></table></div></div>`;
  }

  // ── Bloc inventaire complet (biens + équipements) — lecture seule ─────────────
  if (isManager && st.showSorties) {
    const allItems = [
      ...tousBiens.map(b => ({...b, _type:'Bien', _icon:'🏢', _label:b.Numero, _detail:b.InfoProduit||b.Famille||'', _dateSortie:b.DateSortie||'', _commentaire:b.CommentaireSortie||'', _prix:b.Prix, _compta: comptaAssetKeys.has(`Bien-${b.Id}`), _dateRef: b.DateSortie||b.UpdatedAt||b.CreatedAt||''})),
      ...tousEquipements.map(e => ({...e, _type:'Équipement', _icon:'⚙️', _label:e.Numero, _detail:[e.Marque,e.Modele].filter(Boolean).join(' ')||e.Famille||'', _dateSortie:e.DateSortie||'', _commentaire:e.CommentaireSortie||'', _prix:e.Prix, _compta: comptaAssetKeys.has(`Equipement-${e.Id}`), _dateRef: e.DateSortie||e.UpdatedAt||e.CreatedAt||''})),
    ];
    let rows = allItems;
    if (feFrom) rows = rows.filter(r => (r._dateRef||'') >= feFrom);
    if (feTo)   rows = rows.filter(r => (r._dateRef||'') <= feTo + 'T23:59:59');
    if (search) rows = rows.filter(r =>
      [r._label, r._detail, r._commentaire, r._type, r.Etat, r.Batiment, r.Statut].some(v=>(v||'').toLowerCase().includes(search))
    );

    const nbNonCompta = rows.filter(r => !r._compta).length;
    hasContent = true;
    const fmtMon3 = v => v ? parseFloat(v).toLocaleString('fr-FR',{style:'currency',currency:'EUR'}) : '—';
    const etatBadge = e => {
      if (e === 'Don/Vendu') return '<span class="badge badge-blue">Don/Vendu</span>';
      if (e === 'Jete/Recycle') return '<span class="badge badge-orange">Jeté/Recyclé</span>';
      if (e === 'Utilise') return '<span class="badge badge-teal">Utilisé</span>';
      if (e === 'Stock') return '<span class="badge badge-gray">Stock</span>';
      return e ? `<span class="badge badge-gray">${e}</span>` : '—';
    };

    html += `
    <div class="card" style="margin-bottom:20px">
      <div class="card-header">
        <div class="card-title">📋 Inventaire complet — Biens & Équipements</div>
        <div style="display:flex;gap:12px;align-items:center">
          ${nbNonCompta > 0 ? `<span style="font-size:12px;color:var(--red);font-weight:600">⚠️ ${nbNonCompta} non comptabilisé${nbNonCompta>1?'s':''}</span>` : ''}
          <span style="font-size:12px;color:var(--gray-text)">${rows.length} élément${rows.length>1?'s':''}</span>
        </div>
      </div>
      <div class="table-wrap"><table><thead><tr>
        <th>Type</th><th>N°</th><th>Désignation</th><th>État</th><th>Compta</th>
        <th style="text-align:right">Prix</th><th>Bâtiment</th><th>Créé par</th><th>Modifié par</th><th style="width:50px">Voir</th>
      </tr></thead><tbody>`;
    if (!rows.length) {
      html += `<tr><td colspan="10" class="no-results">Aucun bien ni équipement.</td></tr>`;
    } else {
      rows.forEach(r => {
        const viewFn = r._type === 'Bien' ? 'voirBienHistorique' : 'voirEquipHistorique';
        const isSorti = ['Jete/Recycle','Don/Vendu'].includes(r.Etat);
        const rowStyle = isSorti ? 'opacity:0.65;' : '';
        const comptaBadge = r._compta
          ? '<span class="badge badge-teal" style="font-size:10px">✅ Oui</span>'
          : '<span class="badge badge-red" style="font-size:10px">⚠️ Non</span>';
        html += `<tr style="cursor:pointer;${rowStyle}" onclick="${viewFn}(${r.Id})"
          onmouseover="this.style.background='var(--gray-bg)'" onmouseout="this.style.background=''">
          <td>${r._icon} ${r._type}</td>
          <td style="font-family:monospace;font-weight:600;font-size:12px">${r._label||'—'}</td>
          <td style="font-size:12px">${r._detail||'—'}</td>
          <td>${etatBadge(r.Etat)}</td>
          <td>${comptaBadge}</td>
          <td style="text-align:right;font-weight:600;color:var(--blue);font-size:12px">${fmtMon3(r._prix)}</td>
          <td style="font-size:12px;color:var(--gray-text)">${escHtml(r.Batiment||'—')}</td>
          <td style="font-size:11px;color:var(--gray-text)">${r.CreatedBy||'—'}</td>
          <td style="font-size:11px;color:var(--gray-text)">${r.UpdatedBy||'—'}</td>
          <td><button class="icon-btn" onclick="event.stopPropagation();${viewFn}(${r.Id})" title="Consulter la fiche">👁️</button></td>
        </tr>`;
      });
    }
    html += `</tbody></table></div></div>`;
  }

  if (!hasContent) {
    html += `<div class="card" style="padding:40px;text-align:center;color:var(--gray-text)">
      Cochez au moins une section à afficher.
    </div>`;
  }

  c.innerHTML = html;
  App.restoreFilters();
}

function toggleHisto(type, val) {
  if (type === 'sup')   HistoriqueState.showSuppressions  = val;
  if (type === 'dem')   HistoriqueState.showDemandes      = val;
  if (type === 'interv') HistoriqueState.showInterventions = val;
  if (type === 'compta') HistoriqueState.showComptaClotures = val;
  if (type === 'sorties') HistoriqueState.showSorties = val;
  App.renderCurrentPage();
}

function filtreHisto(key, val) {
  // Conservé pour rétro-compatibilité éventuelle
  FiltresEngine.setValue('historique', key, val);
  App.renderCurrentPage();
}

function resetFiltresHisto() {
  FiltresEngine.reset('historique');
  App.searchTerm = '';
  const si = document.querySelector('.search-input');
  if (si) si.value = '';
  App.renderCurrentPage();
}

// ── Vue détail fiche compta clôturée ─────────────────────────────────────────
async function voirFicheComptaCloture(id) {
  try {
    const all = await GestionMaterielApi.getAll();
    const r = all.find(x => x.Id === id);
    if (!r) { toast('Fiche introuvable.', 'error'); return; }

    const fmtM = v => v ? parseFloat(v).toLocaleString('fr-FR',{style:'currency',currency:'EUR'}) : '—';
    const fmtD = d => d ? new Date(d).toLocaleDateString('fr-FR') : '—';
    const asset = `${r.AssetType === 'Bien' ? '🏢 Bien' : '⚙️ Équipement'} — ${escHtml(r.AssetNumero||'')} ${escHtml(r.AssetLabel||'')}`.trim();

    openModal(`🔒 Fiche compta clôturée — ${escHtml(r.NumeroImmo||'N/A')}`, `
    <div style="display:flex;flex-direction:column;gap:16px;font-size:13px">
      <div style="background:var(--gray-bg);border-radius:8px;padding:14px 18px">
        <div style="font-weight:700;margin-bottom:8px">📋 Identification</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 20px">
          <div><strong>N° Immobilisation :</strong> ${escHtml(r.NumeroImmo||'—')}</div>
          <div><strong>Actif :</strong> ${asset}</div>
          <div><strong>Compte immo :</strong> ${escHtml(r.CompteImmo||'—')}</div>
          <div><strong>Exercice :</strong> ${r.Exercice||'—'}</div>
          <div><strong>Bon de commande :</strong> ${escHtml(r.BonDeCommande||'—')}</div>
          <div><strong>N° Mandat :</strong> ${escHtml(r.NumeroMandat||'—')}</div>
        </div>
      </div>

      <div style="background:var(--gray-bg);border-radius:8px;padding:14px 18px">
        <div style="font-weight:700;margin-bottom:8px">💰 Valorisation</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 20px">
          <div><strong>Valeur d'achat :</strong> <span style="color:var(--blue);font-weight:600">${fmtM(r.ValeurAchat)}</span></div>
          <div><strong>Valeur vénale :</strong> ${fmtM(r.ValeurVenale)}</div>
          <div><strong>Montant du marché :</strong> ${fmtM(r.MontantDuMarche)}</div>
          <div><strong>Durée amort. :</strong> ${r.DureeAmortissement ? r.DureeAmortissement+' ans' : '—'}</div>
          <div><strong>Annualité :</strong> ${fmtM(r.AnnualiteAmortissement)}</div>
          <div><strong>Dépréciation totale :</strong> ${fmtM(r.DepreciationTotal)}</div>
        </div>
      </div>

      <div style="background:var(--gray-bg);border-radius:8px;padding:14px 18px">
        <div style="font-weight:700;margin-bottom:8px">📅 Dates</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 20px">
          <div><strong>Date d'achat :</strong> ${fmtD(r.DateAchat)}</div>
          <div><strong>Mise en service :</strong> ${fmtD(r.DateMiseEnService)}</div>
          <div><strong>Bascule :</strong> ${fmtD(r.DateBascule)}</div>
          <div><strong>Calcul dépréciation :</strong> ${fmtD(r.DateCalculDepreciation)}</div>
        </div>
      </div>

      ${r.User7 || r.Item ? `
      <div style="background:var(--gray-bg);border-radius:8px;padding:14px 18px">
        <div style="font-weight:700;margin-bottom:8px">📝 Compléments</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 20px">
          ${r.User7 ? `<div><strong>User7 :</strong> ${r.User7}</div>` : ''}
          ${r.Item ? `<div><strong>Item :</strong> ${r.Item}</div>` : ''}
        </div>
      </div>` : ''}

      <div style="background:rgba(220,53,69,0.06);border:1px solid rgba(220,53,69,0.2);border-radius:8px;padding:14px 18px">
        <div style="font-weight:700;margin-bottom:8px;color:var(--red)">🔒 Clôture</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 20px">
          <div><strong>Date :</strong> ${fmtD(r.DateCloture)}</div>
          <div><strong>Clôturé par :</strong> ${r.ClotureParLogin||'—'}</div>
          <div class="span-2"><strong>Motif :</strong> ${r.MotifCloture||'—'}</div>
        </div>
      </div>

      <div style="background:var(--gray-bg);border-radius:8px;padding:14px 18px">
        <div style="font-weight:700;margin-bottom:8px">🔗 Traçabilité actif</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 20px">
          <div><strong>Créé par :</strong> ${r.AssetCreatedBy||'—'}</div>
          <div><strong>Modifié par :</strong> ${r.AssetUpdatedBy||'—'}</div>
          <div><strong>État actif :</strong> ${r.AssetEtat||'—'}</div>
          ${r.AssetCommentaireSortie ? `<div><strong>Commentaire sortie :</strong> ${r.AssetCommentaireSortie}</div>` : ''}
        </div>
      </div>
    </div>`, null, null, 'Fermer');
  } catch(e) { toast(e.message, 'error'); }
}

// ── Vue détail intervention archivée ─────────────────────────────────────────
async function voirInterventionArchivee(id) {
  try {
    const all = await InterventionActionsApi.getArchivees();
    const r = all.find(x => x.Id === id);
    if (!r) { toast('Intervention introuvable.', 'error'); return; }

    const fmtM = v => v ? parseFloat(v).toLocaleString('fr-FR',{style:'currency',currency:'EUR'}) : '—';
    const fmtD = d => d ? new Date(d).toLocaleDateString('fr-FR') : '—';
    const cPieces = parseFloat(r.MontantPieces)||0;
    const cMO     = parseFloat(r.MontantMainOeuvre)||0;
    const cAutre  = parseFloat(r.Montant)||0;
    const cHT     = parseFloat(r.MontantHT)||0;
    const coutTotal = cPieces + cMO + cAutre + cHT;
    const typeColors = {Préventive:'blue',Curative:'orange','Contrôle réglementaire':'purple',Divers:'gray'};
    const typeBadge = `<span class="badge badge-${typeColors[r.Type]||'gray'}">${escHtml(r.Type||'—')}</span>`;
    const prioriteBadge = r.Priorite ? `<span class="badge badge-red">${escHtml(r.Priorite||'')}</span>` : '';

    // Notes
    let notesHtml = '';
    try {
      const notes = JSON.parse(r.NotesJSON || '[]');
      if (notes.length) {
        notesHtml = notes.map(n =>
          `<div style="padding:4px 0;border-bottom:1px solid var(--gray-border);font-size:12px">
            ${n.texte||'—'} ${n.date ? `<span style="color:var(--gray-text);margin-left:8px">${fmtD(n.date)}</span>` : ''}
          </div>`
        ).join('');
      }
    } catch(e) {}

    // Devis liés
    let devisHtml = '';
    try {
      const devis = await IntervDevisApi.getAll(id);
      if (devis.length) {
        devisHtml = `
        <div style="background:var(--gray-bg);border-radius:8px;padding:14px 18px">
          <div style="font-weight:700;margin-bottom:8px">📄 Devis</div>
          ${devis.map(d => `
            <div style="display:flex;gap:12px;align-items:center;padding:6px 0;border-bottom:1px solid var(--gray-border);font-size:12px;flex-wrap:wrap">
              <span style="font-weight:500">${escHtml(d.NumeroDevis||'—')}</span>
              ${d.DateReception?`<span title="Date de réception du devis">📅 ${fmtD(d.DateReception)}</span>`:''}
              <span>HT: ${fmtM(d.MontantHT)}</span>
              <span style="font-weight:600">TTC: ${fmtM(d.MontantTTC)}</span>
              <span class="badge badge-${d.Accepte==='Oui'?'teal':d.Accepte==='Non'?'red':'gray'}">${d.Accepte||'—'}</span>
              ${d.Raison ? `<span style="color:var(--gray-text)">${escHtml(d.Raison)}</span>` : ''}
            </div>
          `).join('')}
        </div>`;
      }
    } catch(e) {}

    // Factures / EJ liées — détail complet par facture + total pour l'intervention
    let facturesHtml = '';
    try {
      const factures = await IntervFacturesApi.getAll(id);
      if (factures.length) {
        const totalImpute = factures.reduce((a,f)=>a+(parseFloat(f.MontantLigne)||0),0);
        const totalTTC    = factures.reduce((a,f)=>a+(parseFloat(f.MontantTTC)||0),0);
        const det = (label, val) => val ? `<div><span style="color:var(--gray-text)">${label} :</span> ${val}</div>` : '';
        facturesHtml = `
        <div style="border-top:1px solid var(--gray-border);margin-top:14px;padding-top:14px">
          <div style="font-weight:700;margin-bottom:10px">🧾 Factures / Engagement juridique <span style="color:var(--gray-text);font-weight:400;font-size:12px">(${factures.length})</span></div>
          <div style="display:flex;flex-direction:column;gap:10px">
          ${factures.map(f => `
            <div style="border:1px solid var(--gray-border);border-radius:8px;padding:10px 12px;background:var(--card-bg)">
              <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:6px">
                <span style="font-weight:600">${escHtml(f.Numero||'—')}</span>
                <span class="badge badge-gray" title="CBDC / CHMA">${escHtml(f.TypeBudget||'—')}</span>
                <span style="margin-left:auto;font-weight:700;color:var(--purple,#7c3aed)" title="Montant pour cette intervention (peut être négatif)">${fmtM(f.MontantLigne)}</span>
              </div>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px 18px;font-size:12px">
                ${det('N° EJ', f.NumeroEJ ? escHtml(f.NumeroEJ) : '')}
                ${det('Date EJ', f.DateEJ ? fmtD(f.DateEJ) : '')}
                ${det('Fournisseur', f.Fournisseur ? escHtml(f.Fournisseur) : '')}
                ${det('Date facture', f.DateFacture ? fmtD(f.DateFacture) : '')}
                ${det('Montant HT', (parseFloat(f.MontantHT)||0) ? fmtM(f.MontantHT) : '')}
                ${det('Montant TTC', (parseFloat(f.MontantTTC)||0) ? fmtM(f.MontantTTC) : '')}
                ${det('Montant pour cette intervention', fmtM(f.MontantLigne))}
              </div>
              ${f.AutresInterventions?`<div style="font-size:11px;color:var(--purple,#7c3aed);margin-top:6px">🔗 Cette facture couvre aussi : ${escHtml(f.AutresInterventions)}</div>`:''}
            </div>
          `).join('')}
          </div>
          <div style="display:flex;justify-content:space-between;font-size:13px;padding-top:10px;margin-top:4px;border-top:1px solid var(--gray-border)">
            <span style="font-weight:700">Total pour cette intervention</span>
            <span style="color:var(--purple,#7c3aed);font-weight:700">${fmtM(totalImpute)}</span>
          </div>
          ${factures.length>1?`<div style="display:flex;justify-content:space-between;font-size:11px;color:var(--gray-text);padding-top:2px">
            <span>Total TTC des factures rattachées</span><span>${fmtM(totalTTC)}</span>
          </div>`:''}
        </div>`;
      }
    } catch(e) {}

    openModal(`🔧 Intervention archivée — ${escHtml(r.Numero||'N/A')}`, `
    <div style="display:flex;flex-direction:column;gap:16px;font-size:13px">

      <div style="background:var(--gray-bg);border-radius:8px;padding:14px 18px">
        <div style="font-weight:700;margin-bottom:8px">📋 Informations générales</div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">
          ${typeBadge} ${prioriteBadge}
          <span class="badge badge-gray">Archivée</span>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 20px">
          <div><strong>N° :</strong> <code style="font-size:12px;background:var(--gray-border);padding:2px 6px;border-radius:4px">${escHtml(r.Numero||'—')}</code></div>
          <div><strong>Type :</strong> ${escHtml(r.Type||'—')}</div>
          <div style="grid-column:span 2"><strong>Description :</strong> ${escHtml(r.Description||'—')}</div>
        </div>
      </div>

      <div style="background:var(--gray-bg);border-radius:8px;padding:14px 18px">
        <div style="font-weight:700;margin-bottom:8px">👤 Agent créateur</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 20px">
          <div><strong>Nom :</strong> ${[r.AgentPrenom, r.AgentNom].filter(Boolean).join(' ') || '—'}</div>
          <div><strong>Téléphone :</strong> ${escHtml(r.AgentTel||'—')}</div>
          <div><strong>Email :</strong> ${escHtml(r.AgentEmail||'—')}</div>
          <div><strong>Créé par :</strong> ${r.CreatedBy||'—'}</div>
        </div>
      </div>

      <div style="background:var(--gray-bg);border-radius:8px;padding:14px 18px">
        <div style="font-weight:700;margin-bottom:8px">🔗 Liaisons</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 20px">
          <div><strong>Bien :</strong> ${r.BienNumero ? `${escHtml(r.BienNumero||'')}${r.BienLabel ? ' — '+r.BienLabel : ''}` : '—'}</div>
          <div><strong>Société :</strong> ${r.ContratSociete || r.SocieteManuelle || '—'}</div>
          <div><strong>Demande liée :</strong> ${escHtml(r.DemandeTitre||'—')}</div>
          <div><strong>CHMA / CBDC :</strong> ${escHtml(r.TypeBudget||'—')}</div>
        </div>
      </div>

      <div style="background:var(--gray-bg);border-radius:8px;padding:14px 18px">
        <div style="font-weight:700;margin-bottom:8px">📅 Dates</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 20px">
          <div><strong>Date demande :</strong> ${fmtD(r.DateDemande)}</div>
          <div><strong>Date intervention :</strong> ${fmtD(r.DateIntervention)}</div>
          <div><strong>Date réalisation :</strong> ${fmtD(r.DateRealisation)}</div>
          <div><strong>Durée :</strong> ${r.DureeHeures ? r.DureeHeures+'h' : '—'}</div>
          <div><strong>Code n° demande :</strong> ${r.CodeNumeroDemande||'—'}</div>
          <div><strong>Date envoi mail :</strong> ${fmtD(r.DateEnvoiMail)}</div>
        </div>
      </div>

      ${devisHtml}

      <div style="background:var(--gray-bg);border-radius:8px;padding:14px 18px">
        <div style="font-weight:700;margin-bottom:8px">💶 Coûts & facturation</div>
        <div style="display:flex;flex-direction:column;gap:6px;max-width:360px">
          <div style="display:flex;justify-content:space-between"><span>Pièces (stock consommé)</span><span style="font-weight:600">${cPieces>0?fmtM(cPieces):'—'}</span></div>
          <div style="display:flex;justify-content:space-between"><span>Main d'œuvre</span><span style="font-weight:600">${cMO>0?fmtM(cMO):'—'}</span></div>
          <div style="display:flex;justify-content:space-between"><span>Autre montant</span><span style="font-weight:600">${cAutre>0?fmtM(cAutre):'—'}</span></div>
          <div style="display:flex;justify-content:space-between"><span>Prix HT (suivi administratif)</span><span style="font-weight:600">${cHT>0?fmtM(cHT):'—'}</span></div>
          <div style="border-top:1px solid var(--gray-border);margin:4px 0"></div>
          <div style="display:flex;justify-content:space-between;font-size:15px"><span style="font-weight:700">Coût total</span><span style="color:var(--blue);font-weight:700">${coutTotal>0?fmtM(coutTotal):'—'}</span></div>
        </div>
        ${facturesHtml}
      </div>

      ${r.Commentaire ? `
      <div style="background:var(--gray-bg);border-radius:8px;padding:14px 18px">
        <div style="font-weight:700;margin-bottom:8px">💬 Commentaire</div>
        <div style="white-space:pre-wrap">${escHtml(r.Commentaire||'')}</div>
      </div>` : ''}

      ${notesHtml ? `
      <div style="background:var(--gray-bg);border-radius:8px;padding:14px 18px">
        <div style="font-weight:700;margin-bottom:8px">📝 Notes / Suivi</div>
        ${notesHtml}
      </div>` : ''}

      <div style="background:rgba(107,114,128,0.08);border:1px solid rgba(107,114,128,0.2);border-radius:8px;padding:14px 18px">
        <div style="font-weight:700;margin-bottom:8px;color:var(--gray-text)">📁 Archivage</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 20px">
          <div><strong>Validée le :</strong> ${fmtD(r.DateValidation)}</div>
          <div><strong>Archivée le :</strong> ${fmtD(r.DateArchivage)}</div>
        </div>
      </div>

    </div>`, null, null, 'Fermer');
  } catch(e) { toast(e.message, 'error'); }
}

// ── Vue détail suppression ───────────────────────────────────────────────────
async function voirSuppression(id) {
  try {
    const all = await HistoriqueApi.getAll();
    const r = all.find(x => x.Id === id);
    if (!r) { toast('Entrée introuvable.', 'error'); return; }

    const fmtD = d => d ? new Date(d).toLocaleDateString('fr-FR',{hour:'2-digit',minute:'2-digit'}) : '—';
    const modColors = {
      Biens:'blue', Equipements:'teal', Interventions:'orange',
      Contrats:'purple', Stock:'gray', GestionMateriel:'green'
    };
    const modIcons = {
      Biens:'🏢', Equipements:'⚙️', Interventions:'🔧',
      Contrats:'📋', Stock:'📦', GestionMateriel:'💰'
    };

    openModal(`🗑️ Suppression — ${r.Numero||r.TableSource||'N/A'}`, `
    <div style="display:flex;flex-direction:column;gap:16px;font-size:13px">

      <div style="background:rgba(220,53,69,0.06);border:1px solid rgba(220,53,69,0.2);border-radius:8px;padding:14px 18px">
        <div style="font-weight:700;margin-bottom:8px;color:var(--red)">🗑️ Élément supprimé</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 20px">
          <div><strong>Module :</strong> ${modIcons[r.TableSource]||'📄'} <span class="badge badge-${modColors[r.TableSource]||'gray'}">${r.TableSource||'—'}</span></div>
          <div><strong>N° / Réf. :</strong> <code style="font-size:12px;background:var(--gray-border);padding:2px 6px;border-radius:4px">${escHtml(r.Numero||'—')}</code></div>
        </div>
      </div>

      <div style="background:var(--gray-bg);border-radius:8px;padding:14px 18px">
        <div style="font-weight:700;margin-bottom:8px">📋 Description</div>
        <div style="white-space:pre-wrap">${escHtml(r.Description||'Aucune description enregistrée.')}</div>
      </div>

      <div style="background:var(--gray-bg);border-radius:8px;padding:14px 18px">
        <div style="font-weight:700;margin-bottom:8px">🔗 Traçabilité</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 20px">
          <div><strong>Supprimé par :</strong> ${r.SupprimeParLogin||'—'}</div>
          <div><strong>Date :</strong> ${fmtD(r.DateSuppression)}</div>
          <div><strong>ID original :</strong> ${r.IdOriginal||'—'}</div>
        </div>
      </div>

    </div>`, null, null, 'Fermer');
  } catch(e) { toast(e.message, 'error'); }
}


// ── Vue détail demande clôturée (lecture seule, sans actions) ─────────────────
async function voirDemandeHistorique(id) {
  try {
    const all = await DemandesApi.getAll();
    const d = all.find(x => x.Id === id);
    if (!d) { toast('Demande introuvable.', 'error'); return; }

    const fmtD = dt => dt ? new Date(dt).toLocaleDateString('fr-FR') : '—';
    const fmtDt = dt => dt ? new Date(dt).toLocaleString('fr-FR') : '—';
    const urgColors = {Urgente:'red',Haute:'orange',Normale:'blue',Basse:'teal'};
    const statColors = {Traité:'teal',Refusé:'red','En cours':'blue',Nouveau:'orange'};

    let archiveHtml = '';
    if (d.ArchiveData) {
      try {
        const a = JSON.parse(d.ArchiveData);
        archiveHtml = `
        <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:14px 18px">
          <div style="font-weight:700;margin-bottom:8px;color:#15803d">🗄️ Détails archivage</div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 20px">
            <div><strong>Prestation :</strong> ${a.prestaArchive||'—'}</div>
            <div><strong>Date de solde :</strong> ${a.dateSolde ? fmtD(a.dateSolde) : '—'}</div>
            ${a.nbDossiers ? `<div><strong>Nb dossiers :</strong> ${a.nbDossiers}</div>` : ''}
            ${a.serviceArchive ? `<div><strong>Service :</strong> ${a.serviceArchive}</div>` : ''}
            ${a.periodeArchive ? `<div><strong>Période :</strong> ${a.periodeArchive}</div>` : ''}
            ${a.duaArchive ? `<div><strong>DUA :</strong> ${a.duaArchive}</div>` : ''}
            ${a.numeroDossierArch ? `<div><strong>N° dossier :</strong> ${a.numeroDossierArch}</div>` : ''}
            ${a.nomPrenomArch ? `<div><strong>Bénéficiaire :</strong> ${a.nomPrenomArch}</div>` : ''}
            ${a.numeroBoiteArch ? `<div><strong>N° boîte :</strong> ${a.numeroBoiteArch}</div>` : ''}
            ${a.mandatArch ? `<div><strong>N° mandat :</strong> ${a.mandatArch}</div>` : ''}
          </div>
        </div>`;
      } catch(e) {}
    }

    openModal(`📝 Demande clôturée — ${escHtml(d.Titre||'')}`, `
    <div style="display:flex;flex-direction:column;gap:16px;font-size:13px">

      <div style="background:var(--gray-bg);border-radius:8px;padding:14px 18px">
        <div style="font-weight:700;margin-bottom:8px">📋 Informations générales</div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">
          <span class="badge badge-${urgColors[d.Urgence]||'gray'}">${d.Urgence||'—'}</span>
          <span class="badge badge-${statColors[d.Statut]||'gray'}">${escHtml(d.Statut||'—')}</span>
          ${d.PourAutrui == 1 ? '<span class="badge badge-purple">👥 Pour autrui</span>' : ''}
          ${d.TypeLocalisation && d.TypeLocalisation !== 'Standard' ? `<span class="badge badge-${d.TypeLocalisation==='Archive'?'teal':'purple'}">${d.TypeLocalisation==='Archive'?'🗄️ Archive':'🔧 Technique'}</span>` : ''}
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 20px">
          <div><strong>Titre :</strong> ${escHtml(d.Titre||'—')}</div>
          <div><strong>Catégorie :</strong> ${escHtml(d.Categorie||'—')}</div>
          <div style="grid-column:span 2"><strong>Description :</strong> ${escHtml(d.Description||'—')}</div>
        </div>
      </div>

      <div style="background:var(--gray-bg);border-radius:8px;padding:14px 18px">
        <div style="font-weight:700;margin-bottom:8px">📍 Localisation</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 20px">
          <div><strong>Bâtiment :</strong> ${escHtml(d.Batiment||'—')}</div>
          <div><strong>Bureau / Emplacement :</strong> ${escHtml(d.Bureau||'—')}</div>
        </div>
      </div>

      <div style="background:var(--gray-bg);border-radius:8px;padding:14px 18px">
        <div style="font-weight:700;margin-bottom:8px">👤 Demandeur</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 20px">
          <div><strong>Nom :</strong> ${escHtml(d.NomDeclarant||'—')}</div>
          <div><strong>Email :</strong> ${escHtml(d.EmailDemandeur||'—')}</div>
          <div><strong>Téléphone :</strong> ${escHtml(d.TelDemandeur||'—')}</div>
        </div>
      </div>

      ${d.PourAutrui == 1 ? `
      <div style="background:var(--gray-bg);border-radius:8px;padding:14px 18px">
        <div style="font-weight:700;margin-bottom:8px">👥 Demande pour un tiers</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 20px">
          <div><strong>Nom :</strong> ${escHtml(d.NomAutrui||'—')}</div>
          <div><strong>Email :</strong> ${escHtml(d.EmailAutrui||'—')}</div>
          <div><strong>Téléphone :</strong> ${escHtml(d.TelAutrui||'—')}</div>
        </div>
      </div>` : ''}

      ${archiveHtml}

      <div style="background:var(--gray-bg);border-radius:8px;padding:14px 18px">
        <div style="font-weight:700;margin-bottom:8px">📅 Dates</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 20px">
          <div><strong>Créée le :</strong> ${fmtDt(d.DateCreation)}</div>
          <div><strong>Traitée le :</strong> ${fmtDt(d.DateTraitement)}</div>
          ${d.DateDerniereRelance ? `<div><strong>Dernière relance :</strong> ${fmtDt(d.DateDerniereRelance)}</div>` : ''}
        </div>
      </div>

      ${d.CommentaireAdmin ? `
      <div style="background:rgba(107,114,128,0.08);border:1px solid rgba(107,114,128,0.2);border-radius:8px;padding:14px 18px">
        <div style="font-weight:700;margin-bottom:8px">💬 Réponse admin</div>
        <div style="white-space:pre-wrap">${escHtml(d.CommentaireAdmin||'')}</div>
      </div>` : ''}

    </div>`, null, null, 'Fermer');
  } catch(e) { toast(e.message, 'error'); }
}

// ── Vue détail bien — lecture seule historique ────────────────────────────────
async function voirBienHistorique(id) {
  try {
    const all = await BiensApi.getAll();
    const b = all.find(x => x.Id === id);
    if (!b) { toast('Bien introuvable.', 'error'); return; }

    const fmtD = d => d ? new Date(d).toLocaleDateString('fr-FR') : '—';
    const fmtM = v => v ? parseFloat(v).toLocaleString('fr-FR',{style:'currency',currency:'EUR'}) : '—';
    const isSorti = ['Jete/Recycle','Don/Vendu'].includes(b.Etat);
    const etatBdg = e => {
      if (e === 'Don/Vendu') return '<span class="badge badge-blue">📤 Don/Vendu</span>';
      if (e === 'Jete/Recycle') return '<span class="badge badge-orange">🗑️ Jeté/Recyclé</span>';
      if (e === 'Utilise') return '<span class="badge badge-teal">Utilisé</span>';
      if (e === 'Stock') return '<span class="badge badge-gray">Stock</span>';
      return e ? `<span class="badge badge-gray">${e}</span>` : '—';
    };

    openModal(`🏢 Bien — ${escHtml(b.Numero||'N/A')}`, `
    <div style="display:flex;flex-direction:column;gap:16px;font-size:13px">

      <div style="background:var(--gray-bg);border-radius:8px;padding:14px 18px">
        <div style="font-weight:700;margin-bottom:8px">📋 Identification</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 20px">
          <div><strong>Numéro :</strong> <code style="font-size:12px;background:var(--gray-border);padding:2px 6px;border-radius:4px">${escHtml(b.Numero||'—')}</code></div>
          <div><strong>N° Série :</strong> ${escHtml(b.NumeroSerie||'—')}</div>
          <div><strong>Famille :</strong> ${escHtml(b.Famille||'—')}</div>
          <div><strong>Sous-famille :</strong> ${escHtml(b.SousFamille||'—')}</div>
          <div><strong>Statut :</strong> ${b.Statut ? `<span class="badge badge-blue">${escHtml(b.Statut||'')}</span>` : '—'}</div>
          <div><strong>État :</strong> ${etatBdg(b.Etat)}</div>
          ${b.InfoProduit ? `<div style="grid-column:span 2"><strong>Informations produit :</strong> ${escHtml(b.InfoProduit||'')}</div>` : ''}
        </div>
      </div>

      <div style="background:var(--gray-bg);border-radius:8px;padding:14px 18px">
        <div style="font-weight:700;margin-bottom:8px">📍 Localisation & Affectation</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 20px">
          <div><strong>Bâtiment :</strong> ${escHtml(b.Batiment||'—')}</div>
          <div><strong>Étage :</strong> ${escHtml(b.Etage||'—')}</div>
          <div><strong>Bureau :</strong> ${escHtml(b.NumeroBureau||'—')}</div>
          <div><strong>Affecté à :</strong> ${escHtml(b.NomPrenom||'—')}</div>
        </div>
      </div>

      <div style="background:var(--gray-bg);border-radius:8px;padding:14px 18px">
        <div style="font-weight:700;margin-bottom:8px">💰 Finances</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 20px">
          <div><strong>Prix :</strong> <span style="color:var(--blue);font-weight:600">${fmtM(b.Prix)}</span></div>
          <div><strong>Date commande :</strong> ${fmtD(b.DateCommande)}</div>
          <div><strong>Date livraison :</strong> ${fmtD(b.DateLivraison)}</div>
        </div>
      </div>

      ${isSorti ? `
      <div style="background:rgba(220,53,69,0.06);border:1px solid rgba(220,53,69,0.2);border-radius:8px;padding:14px 18px">
        <div style="font-weight:700;margin-bottom:8px;color:var(--red)">📤 Sortie du parc</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 20px">
          <div><strong>État :</strong> ${etatBdg(b.Etat)}</div>
          <div><strong>Date de sortie :</strong> ${fmtD(b.DateSortie)}</div>
          ${b.CommentaireSortie ? `<div style="grid-column:span 2"><strong>Commentaire :</strong> ${escHtml(b.CommentaireSortie||'')}</div>` : ''}
        </div>
      </div>` : ''}

      <div style="background:var(--gray-bg);border-radius:8px;padding:14px 18px">
        <div style="font-weight:700;margin-bottom:8px">🔗 Traçabilité</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 20px">
          <div><strong>Créé par :</strong> ${b.CreatedBy||'—'}</div>
          <div><strong>Créé le :</strong> ${fmtD(b.CreatedAt)}</div>
          <div><strong>Modifié par :</strong> ${b.UpdatedBy||'—'}</div>
          <div><strong>Modifié le :</strong> ${fmtD(b.UpdatedAt)}</div>
        </div>
      </div>

    </div>`, null, null, 'Fermer');
  } catch(e) { toast(e.message, 'error'); }
}

// ── Vue détail équipement — lecture seule historique ──────────────────────────
async function voirEquipHistorique(id) {
  try {
    const all = await EquipementsApi.getAll();
    const eq = all.find(x => x.Id === id);
    if (!eq) { toast('Équipement introuvable.', 'error'); return; }

    const fmtD = d => d ? new Date(d).toLocaleDateString('fr-FR') : '—';
    const fmtM = v => v ? parseFloat(v).toLocaleString('fr-FR',{style:'currency',currency:'EUR'}) : '—';
    const isSorti = ['Jete/Recycle','Don/Vendu'].includes(eq.Etat);
    const etatBdg = e => {
      if (e === 'Don/Vendu') return '<span class="badge badge-blue">📤 Don/Vendu</span>';
      if (e === 'Jete/Recycle') return '<span class="badge badge-orange">🗑️ Jeté/Recyclé</span>';
      if (e === 'Utilise') return '<span class="badge badge-teal">Utilisé</span>';
      if (e === 'Stock') return '<span class="badge badge-gray">Stock</span>';
      return e ? `<span class="badge badge-gray">${e}</span>` : '—';
    };

    openModal(`⚙️ Équipement — ${escHtml(eq.Numero||'N/A')}`, `
    <div style="display:flex;flex-direction:column;gap:16px;font-size:13px">

      <div style="background:var(--gray-bg);border-radius:8px;padding:14px 18px">
        <div style="font-weight:700;margin-bottom:8px">📋 Identification</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 20px">
          <div><strong>Numéro :</strong> <code style="font-size:12px;background:var(--gray-border);padding:2px 6px;border-radius:4px">${escHtml(eq.Numero||'—')}</code></div>
          <div><strong>N° Série :</strong> ${escHtml(eq.NumeroSerie||'—')}</div>
          <div><strong>Famille :</strong> ${escHtml(eq.Famille||'—')}</div>
          <div><strong>Sous-famille :</strong> ${escHtml(eq.SousFamille||'—')}</div>
          <div><strong>Marque :</strong> ${escHtml(eq.Marque||'—')}</div>
          <div><strong>Modèle :</strong> ${escHtml(eq.Modele||'—')}</div>
          <div><strong>Fournisseur :</strong> ${escHtml(eq.Fournisseur||'—')}</div>
          <div><strong>Statut :</strong> ${eq.Statut ? `<span class="badge badge-blue">${escHtml(eq.Statut||'')}</span>` : '—'}</div>
          <div><strong>État :</strong> ${etatBdg(eq.Etat)}</div>
        </div>
      </div>

      <div style="background:var(--gray-bg);border-radius:8px;padding:14px 18px">
        <div style="font-weight:700;margin-bottom:8px">📍 Localisation</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 20px">
          <div><strong>Bâtiment :</strong> ${escHtml(eq.Batiment||'—')}</div>
          <div><strong>Étage :</strong> ${escHtml(eq.Etage||'—')}</div>
          <div><strong>Bureau :</strong> ${escHtml(eq.NumeroBureau||'—')}</div>
          <div><strong>Affecté à :</strong> ${escHtml(eq.NomPrenom||'—')}</div>
        </div>
      </div>

      <div style="background:var(--gray-bg);border-radius:8px;padding:14px 18px">
        <div style="font-weight:700;margin-bottom:8px">💰 Dates & Prix</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 20px">
          <div><strong>Prix :</strong> <span style="color:var(--blue);font-weight:600">${fmtM(eq.Prix)}</span></div>
          <div><strong>Date commande :</strong> ${fmtD(eq.DateCommande)}</div>
          <div><strong>Date installation :</strong> ${fmtD(eq.DateInstallation)}</div>
        </div>
      </div>

      ${eq.Observations ? `
      <div style="background:var(--gray-bg);border-radius:8px;padding:14px 18px">
        <div style="font-weight:700;margin-bottom:8px">📝 Observations</div>
        <div style="white-space:pre-wrap">${eq.Observations}</div>
      </div>` : ''}

      ${isSorti ? `
      <div style="background:rgba(220,53,69,0.06);border:1px solid rgba(220,53,69,0.2);border-radius:8px;padding:14px 18px">
        <div style="font-weight:700;margin-bottom:8px;color:var(--red)">📤 Sortie du parc</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 20px">
          <div><strong>État :</strong> ${etatBdg(eq.Etat)}</div>
          <div><strong>Date de sortie :</strong> ${fmtD(eq.DateSortie)}</div>
          ${eq.CommentaireSortie ? `<div style="grid-column:span 2"><strong>Commentaire :</strong> ${escHtml(eq.CommentaireSortie||'')}</div>` : ''}
        </div>
      </div>` : ''}

      <div style="background:var(--gray-bg);border-radius:8px;padding:14px 18px">
        <div style="font-weight:700;margin-bottom:8px">🔗 Traçabilité</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 20px">
          <div><strong>Créé par :</strong> ${eq.CreatedBy||'—'}</div>
          <div><strong>Créé le :</strong> ${fmtD(eq.CreatedAt)}</div>
          <div><strong>Modifié par :</strong> ${eq.UpdatedBy||'—'}</div>
          <div><strong>Modifié le :</strong> ${fmtD(eq.UpdatedAt)}</div>
        </div>
      </div>

    </div>`, null, null, 'Fermer');
  } catch(e) { toast(e.message, 'error'); }
}

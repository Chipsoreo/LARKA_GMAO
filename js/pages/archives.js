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
 * Larka — Page : Archives physiques
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Gestion des archives physiques : boîtes, dossiers, bordereaux.
 *
 * FONCTIONNALITÉS :
 *   - Boîtes d'archives (numéro, intitulé, service, bâtiment, emplacement)
 *   - Dossiers dans les boîtes (numéro mandat, période, DUA, sort final)
 *   - Bordereaux de versement/élimination (avec PJ)
 *   - Codes-barres pour identification physique
 *   - Recherche multi-critères
 *
 * POINT D'ENTRÉE : renderArchives()
 * ═══════════════════════════════════════════════════════════════════════════════
 */

// ── Constantes ──────────────────────────────────────────────────────────────
const ARCHIVES_ONGLETS = ['dossiers', 'boites', 'bordereaux', 'demandes', 'recherche'];
const ArchivesState = {
  onglet: 'dossiers',
  recherche: {},
  resultatsRecherche: null,
};

const SORT_FINAL_OPTIONS = ['Conservation permanente', 'Destruction', 'Tri'];
const DUA_EXEMPLES = ['1 an', '2 ans', '3 ans', '5 ans', '10 ans', '30 ans', 'Illimité'];
const STATUT_DOSSIER = ['Archive', 'Desarchive', 'Detruit', 'En cours de traitement'];

function _esc_a(s) { return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function fmtDateFr(d) { return d ? new Date(d.substring(0,10)).toLocaleDateString('fr-FR') : '—'; }
function badgeStatutArch(s) {
  const colors = { 'Archive':'teal', 'Desarchive':'blue', 'Detruit':'red', 'En cours de traitement':'orange' };
  return `<span class="badge badge-${colors[s]||'gray'}">${s||'—'}</span>`;
}

// ── Rendu principal ──────────────────────────────────────────────────────────
async function renderArchives() {
  document.getElementById('topbarActions').innerHTML = '';
  const c = document.getElementById('mainContent');
  showLoading(c);
  try {
    await _renderArchivesPage(c);
  } catch(e) { c.innerHTML = errorHtml(e.message); }
}

async function _renderArchivesPage(c) {
  const ong = ArchivesState.onglet;

  const tabBtn = (id, icon, label) =>
    `<button onclick="switchArchivesOnglet('${id}')"
      style="padding:8px 18px;border:none;background:${ong===id?'var(--blue)':'transparent'};
      color:${ong===id?'white':'var(--gray-text)'};border-radius:6px;cursor:pointer;
      font-size:13px;font-weight:${ong===id?'700':'400'};display:flex;align-items:center;gap:6px">
      ${icon} ${label}
    </button>`;

  let content = '';
  if (ong === 'dossiers')   content = await _renderDossiers();
  if (ong === 'boites')     content = await _renderBoites();
  if (ong === 'bordereaux') content = await _renderBordereaux();
  if (ong === 'demandes')   content = await _renderDemandesArchive();
  if (ong === 'recherche')  content = _renderRecherche();

  c.innerHTML = `
  <div class="card" style="padding:0;margin-bottom:16px">
    <div style="padding:16px 20px 0;border-bottom:1px solid var(--gray-border)">
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding-bottom:12px">
        ${tabBtn('dossiers',  '🗂️', 'Dossiers')}
        ${tabBtn('boites',    '📦', 'Boîtes')}
        ${tabBtn('bordereaux','📄', 'Bordereaux')}
        ${tabBtn('demandes',  '📬', 'Demandes')}
        ${tabBtn('recherche', '🔍', 'Recherche avancée')}
      </div>
    </div>
    <div id="archivesOngletContent">
      ${content}
    </div>
  </div>`;
}

function switchArchivesOnglet(ong) {
  ArchivesState.onglet = ong;
  renderArchives();
}

// ══════════════════════════════════════════════════════════════════════════════
//  ONGLET : DOSSIERS
// ══════════════════════════════════════════════════════════════════════════════
async function _renderDossiers() {
  const dossiers = await ArchivesApi.getAllDossiers();
  const search = (App.searchTerm||'').toLowerCase();
  const filtered = search ? dossiers.filter(d =>
    [d.NomPrenom, d.NumeroDossier, d.NumeroBoite, d.Service, d.Commune, d.Departement, d.NumeroMandat].some(v => (v||'').toLowerCase().includes(search))
  ) : dossiers;

  return `
  <div style="padding:16px 20px">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap">
      <div style="flex:1;max-width:400px;position:relative">
        <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--gray-text)">🔍</span>
        <input class="form-control" type="text" placeholder="Rechercher un dossier…"
          style="padding-left:32px" value="${_esc_a(App.searchTerm||'')}"
          oninput="App.handleSearch(this.value)">
      </div>
      <button class="btn btn-primary" onclick="openModalNouveauDossier()">+ Nouveau dossier</button>
      <button class="btn" onclick="switchArchivesOnglet('recherche')" style="background:#f0f9ff;color:var(--blue);border:1px solid #bae6fd">🔍 Recherche avancée</button>
    </div>

    ${filtered.length === 0 ? `
    <div style="text-align:center;padding:60px 20px;color:var(--gray-text)">
      <div style="font-size:48px;margin-bottom:12px">🗂️</div>
      <div style="font-size:16px;font-weight:600">Aucun dossier archivé</div>
      <div style="font-size:13px;margin-top:4px">Créez votre premier dossier avec le bouton ci-dessus</div>
    </div>` : `
    <div class="table-wrap" style="border:1px solid var(--gray-border);border-radius:8px;overflow:hidden">
      <table>
        <thead><tr>
          <th>N° Dossier</th>
          <th>Nom / Prénom</th>
          <th>Boîte</th>
          <th>Service</th>
          <th>Commune</th>
          <th>Année</th>
          <th>Date de solde</th>
          <th>DUA</th>
          <th>Sort final</th>
          <th>Statut</th>
          <th>Actions</th>
        </tr></thead>
        <tbody>
          ${filtered.map(d => `
          <tr>
            <td><strong>${_esc_a(d.NumeroDossier||'—')}</strong></td>
            <td>${_esc_a(d.NomPrenom||'—')}</td>
            <td>${d.NumeroBoite ? `<span style="background:#f0f9ff;padding:2px 8px;border-radius:4px;font-size:12px">📦 ${_esc_a(d.NumeroBoite)}</span>` : '—'}</td>
            <td>${_esc_a(d.Service||'—')}</td>
            <td>${_esc_a(d.Commune||'—')}</td>
            <td>${_esc_a(d.Annee||'—')}</td>
            <td>${fmtDateFr(d.DateSolde)}</td>
            <td><span style="font-size:11px;background:#fef9c3;padding:2px 6px;border-radius:4px">${_esc_a(d.DUA||'—')}</span></td>
            <td><span style="font-size:11px;background:${d.SortFinal==='Destruction'?'#fee2e2':'#f0fdf4'};padding:2px 6px;border-radius:4px">${_esc_a(d.SortFinal||'—')}</span></td>
            <td>${badgeStatutArch(d.Statut)}</td>
            <td style="white-space:nowrap">
              <button class="btn btn-sm" onclick="openModalEditDossier(${d.Id})" title="Modifier">✏️ Modifier</button>
              ${canEdit() ? `<button class="btn btn-sm btn-danger" onclick="supprimerDossier(${d.Id})" title="Supprimer">🗑️ Supprimer</button>` : ''}
            </td>
          </tr>`).join('')}
        </tbody>
      </table>
    </div>
    <div style="font-size:12px;color:var(--gray-text);margin-top:8px">${filtered.length} dossier(s)</div>
    `}
  </div>`;
}

// ══════════════════════════════════════════════════════════════════════════════
//  ONGLET : BOÎTES
// ══════════════════════════════════════════════════════════════════════════════
async function _renderBoites() {
  const boites = await ArchivesApi.getAllBoites();
  const search = (App.searchTerm||'').toLowerCase();
  const filtered = search ? boites.filter(b =>
    [b.NumeroBoite, b.Service, b.Batiment, b.Emplacement].some(v => (v||'').toLowerCase().includes(search))
  ) : boites;

  return `
  <div style="padding:16px 20px">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px">
      <div style="flex:1;max-width:400px;position:relative">
        <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--gray-text)">🔍</span>
        <input class="form-control" type="text" placeholder="Rechercher une boîte…"
          style="padding-left:32px" value="${_esc_a(App.searchTerm||'')}"
          oninput="App.handleSearch(this.value)">
      </div>
      <button class="btn btn-primary" onclick="openModalNouvelleBoite()">+ Nouvelle boîte</button>
    </div>

    ${filtered.length === 0 ? `
    <div style="text-align:center;padding:60px 20px;color:var(--gray-text)">
      <div style="font-size:48px;margin-bottom:12px">📦</div>
      <div style="font-size:16px;font-weight:600">Aucune boîte enregistrée</div>
    </div>` : `
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:12px">
      ${filtered.map(b => `
      <div style="border:1px solid var(--gray-border);border-radius:10px;padding:0;background:white;transition:box-shadow .15s;overflow:hidden" onmouseover="this.style.boxShadow='0 4px 12px rgba(0,0,0,.08)'" onmouseout="this.style.boxShadow=''">

        <!-- En-tête boîte -->
        <div style="background:linear-gradient(135deg,#1565c0,#1976d2);color:white;padding:12px 14px;display:flex;justify-content:space-between;align-items:flex-start">
          <div>
            <div style="font-size:16px;font-weight:700">📦 ${_esc_a(b.NumeroBoite)}</div>
            <div style="font-size:11px;opacity:.85;margin-top:2px">${_esc_a(b.Service||'—')}</div>
          </div>
          <div style="display:flex;gap:6px">
            <button class="btn btn-sm" onclick="openFicheBoite(${b.Id})" title="Voir la fiche officielle"
              style="background:rgba(255,255,255,.2);color:white;border:1px solid rgba(255,255,255,.4);font-size:11px;padding:5px 10px">📋 Fiche</button>
            <button class="btn btn-sm" onclick="openModalEditBoite(${b.Id})" title="Modifier"
              style="background:rgba(255,255,255,.2);color:white;border:1px solid rgba(255,255,255,.4);font-size:11px;padding:5px 10px">✏️ Modifier</button>
            ${canEdit() ? `<button class="btn btn-sm btn-danger" onclick="supprimerBoite(${b.Id})" title="Supprimer"
              style="background:rgba(220,38,38,.5);color:white;border:1px solid rgba(220,38,38,.6);font-size:11px;padding:5px 10px">🗑️ Suppr.</button>` : ''}
          </div>
        </div>

        <!-- Corps boîte -->
        <div style="padding:12px;font-size:12px;display:grid;grid-template-columns:1fr 1fr;gap:6px">
          ${b.AgentResponsable ? `<div style="grid-column:span 2"><span style="color:var(--gray-text)">👤 Agent :</span> <strong>${_esc_a(b.AgentResponsable)}</strong></div>` : ''}
          ${b.NumeroVersement  ? `<div><span style="color:var(--gray-text)">N° versement :</span> ${_esc_a(b.NumeroVersement)}</div>` : ''}
          ${b.DateVersement    ? `<div><span style="color:var(--gray-text)">Date versement :</span> ${fmtDateFr(b.DateVersement)}</div>` : ''}
          ${b.DatesExtremes    ? `<div><span style="color:var(--gray-text)">Dates extrêmes :</span> ${_esc_a(b.DatesExtremes)}</div>` : ''}
          ${b.NombreBoites     ? `<div><span style="color:var(--gray-text)">Nb boîtes :</span> ${_esc_a(b.NombreBoites)}</div>` : ''}
          <div><span style="color:var(--gray-text)">Bâtiment :</span> ${_esc_a(b.Batiment||'—')}</div>
          <div><span style="color:var(--gray-text)">Localisation :</span> ${_esc_a(b.Localisation||b.Emplacement||'—')}</div>
          <div><span style="color:var(--gray-text)">DUA :</span> <span style="background:#fef9c3;padding:1px 6px;border-radius:4px">${_esc_a(b.DUA||'—')}</span></div>
          <div><span style="color:var(--gray-text)">Sort final :</span> <span style="background:${b.SortFinal==='Destruction'?'#fee2e2':'#f0fdf4'};padding:1px 6px;border-radius:4px">${_esc_a(b.SortFinal||'—')}</span></div>
          ${b.AnneeRevision    ? `<div><span style="color:var(--gray-text)">Révision bordereau :</span> ${_esc_a(b.AnneeRevision)}</div>` : ''}
          ${b.Periode          ? `<div><span style="color:var(--gray-text)">Période :</span> ${_esc_a(b.Periode)}</div>` : ''}
          ${b.Description      ? `<div style="grid-column:span 2;color:var(--gray-text);font-style:italic;border-top:1px solid var(--gray-border);padding-top:6px;margin-top:2px">${_esc_a(b.Description.substring(0,120))}${b.Description.length>120?'…':''}</div>` : ''}
        </div>
        ${b.CodeBarre ? `<div style="padding:4px 12px 10px;font-size:11px;color:var(--gray-text)">🔖 ${_esc_a(b.CodeBarre)}</div>` : ''}
      </div>`).join('')}
    </div>
    <div style="font-size:12px;color:var(--gray-text);margin-top:12px">${filtered.length} boîte(s)</div>
    `}
  </div>`;
}

// ══════════════════════════════════════════════════════════════════════════════
//  ONGLET : BORDEREAUX
// ══════════════════════════════════════════════════════════════════════════════
async function _renderBordereaux() {
  const bordereaux = await ArchivesApi.getAllBordereaux();

  const versements   = bordereaux.filter(b => b.Type === 'Versement');
  const eliminations = bordereaux.filter(b => b.Type === 'Elimination');

  const renderListe = (items, type) => items.length === 0
    ? `<div style="text-align:center;padding:30px;color:var(--gray-text);font-size:13px">Aucun bordereau de ${type.toLowerCase()}</div>`
    : `<div class="table-wrap" style="border:1px solid var(--gray-border);border-radius:8px;overflow:hidden">
        <table>
          <thead><tr><th>N° Bordereau</th><th>Date</th><th>Service</th><th>Statut</th><th>Localisation</th><th>Fichier</th><th>Actions</th></tr></thead>
          <tbody>
            ${items.map(b => `
            <tr>
              <td><strong>${_esc_a(b.Numero||'—')}</strong></td>
              <td>${fmtDateFr(b.DateBordereau)}</td>
              <td>${_esc_a(b.Service||'—')}</td>
              <td>${_renderStatutBordereau(b.Statut)}</td>
              <td>${_renderLocBordereau(b.Localisation)}</td>
              <td>${b.NomFichier ? `<button class="btn btn-sm" onclick="ArchivesApi.downloadBordereau(${b.Id},'${_esc_a(b.NomFichier)}')" style="font-size:11px">📥 ${_esc_a(b.NomFichier)}</button>` : '—'}</td>
              <td>${canEdit() ? `<button class="btn btn-sm btn-danger" onclick="supprimerBordereau(${b.Id})">🗑️ Supprimer</button>` : ''}</td>
            </tr>`).join('')}
          </tbody>
        </table>
      </div>`;

  return `
  <div style="padding:16px 20px">
    <div style="display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap">
      <button class="btn btn-primary" onclick="openModalNouveauBordereau('Versement')">+ Bordereau de versement</button>
      <button class="btn" onclick="openModalNouveauBordereau('Elimination')" style="background:#fff5f5;color:#e11d48;border:1px solid #fecdd3">+ Bordereau d'élimination</button>
    </div>

    <div style="margin-bottom:24px">
      <div style="font-size:13px;font-weight:700;color:var(--blue);margin-bottom:10px;display:flex;align-items:center;gap:8px">
        <span style="background:#eff6ff;padding:4px 10px;border-radius:20px">📋 Bordereaux de versement (${versements.length})</span>
      </div>
      ${renderListe(versements,'Versement')}
    </div>

    <div>
      <div style="font-size:13px;font-weight:700;color:#e11d48;margin-bottom:10px;display:flex;align-items:center;gap:8px">
        <span style="background:#fff5f5;padding:4px 10px;border-radius:20px">🗑️ Bordereaux d'élimination (${eliminations.length})</span>
      </div>
      ${renderListe(eliminations,'Élimination')}
    </div>
  </div>`;
}

// ══════════════════════════════════════════════════════════════════════════════
//  ONGLET : DEMANDES D'ARCHIVE
// ══════════════════════════════════════════════════════════════════════════════
async function _renderDemandesArchive() {
  const all = await DemandesApi.getAll();
  const demArchives = all.filter(d => d.TypeLocalisation === 'Archive');

  const enCours  = demArchives.filter(d => !['Traité','Refusé'].includes(d.Statut));
  const traitees = demArchives.filter(d => ['Traité','Refusé'].includes(d.Statut));

  const STATUT_COLORS = { Demandeur:'orange', 'En cours':'blue', Traité:'teal', Refusé:'red' };
  const PRESTA_LABELS = { Archivage:'📦 Archivage', Desarchivage:'🗂️ Désarchivage' };

  const renderCard = (d) => {
    let archInfo = null;
    try { archInfo = d.ArchiveData ? JSON.parse(d.ArchiveData) : null; } catch(e) {}
    const prestaLabel = archInfo?.prestaArchive ? (PRESTA_LABELS[archInfo.prestaArchive] || archInfo.prestaArchive) : '—';
    const isManager = canEdit();

    return `
    <div style="border:1px solid var(--gray-border);border-radius:10px;padding:16px;background:white;cursor:pointer;transition:box-shadow .15s"
      onmouseover="this.style.boxShadow='0 2px 12px rgba(0,0,0,.1)'" onmouseout="this.style.boxShadow=''"
      onclick="voirDetailDemandeArchive(${d.Id})">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px">
        <div style="flex:1">
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;flex-wrap:wrap">
            <span class="badge badge-${STATUT_COLORS[d.Statut]||'gray'}">${escHtml(d.Statut||'')}</span>
            <span style="background:#f0fdf4;color:#15803d;font-size:11px;padding:2px 8px;border-radius:4px;font-weight:600">${prestaLabel}</span>
            <span style="font-size:11px;color:var(--gray-text)">${fmtDt(d.DateCreation)}</span>
          </div>
          <div style="font-size:13px;margin-bottom:4px">
            <strong>👤 ${escHtml(d.NomDeclarant||'—')}</strong>
            ${d.EmailDemandeur ? `<span style="color:var(--gray-text)"> — 📧 ${escHtml(d.EmailDemandeur||'')}</span>` : ''}
            ${d.TelDemandeur   ? `<span style="color:var(--gray-text)"> — 📱 ${escHtml(d.TelDemandeur||'')}</span>` : ''}
          </div>
          ${d.Batiment || d.Bureau ? `<div style="font-size:12px;color:var(--gray-text)">🏢 ${[d.Batiment,d.Bureau].filter(Boolean).join(' • ')}</div>` : ''}
          ${archInfo ? `<div style="margin-top:8px;font-size:12px;display:flex;gap:12px;flex-wrap:wrap;color:var(--gray-text)">
            ${archInfo.dateSolde   ? `<span>📅 Solde : ${new Date(archInfo.dateSolde).toLocaleDateString('fr-FR')}</span>` : ''}
            ${archInfo.numeroDossierArch ? `<span>📁 ${archInfo.numeroDossierArch}</span>` : ''}
            ${archInfo.nbDossiers  ? `<span>📦 ${archInfo.nbDossiers} dossiers</span>` : ''}
            ${archInfo.serviceArchive ? `<span>🏢 ${archInfo.serviceArchive}</span>` : ''}
          </div>` : ''}
          ${d.Description ? `<div style="font-size:12px;color:var(--gray-text);margin-top:6px;font-style:italic">${d.Description.substring(0,100)}${d.Description.length>100?'…':''}</div>` : ''}
          ${d.CommentaireAdmin ? `<div style="margin-top:8px;padding:8px 10px;background:#f0fdf4;border-radius:6px;font-size:12px">💬 ${_esc_a(d.CommentaireAdmin.substring(0,120))}</div>` : ''}
        </div>
        ${isManager && !['Traité','Refusé'].includes(d.Statut) ? `
        <div style="flex-shrink:0">
          <button class="btn btn-primary btn-sm" onclick="event.stopPropagation();traiterDemandeArchive_byId(${d.Id})">✏️ Traiter</button>
        </div>` : ''}
      </div>
    </div>`;
  };

  return `
  <div style="padding:16px 20px">
    ${enCours.length === 0 && traitees.length === 0 ? `
    <div style="text-align:center;padding:60px 20px;color:var(--gray-text)">
      <div style="font-size:48px;margin-bottom:12px">📬</div>
      <div style="font-size:16px;font-weight:600">Aucune demande d'archive</div>
      <div style="font-size:13px;margin-top:4px">Les demandes soumises via le formulaire "Archive" apparaîtront ici</div>
    </div>` : `

    ${enCours.length > 0 ? `
    <div style="margin-bottom:24px">
      <div style="font-size:13px;font-weight:700;color:var(--orange);margin-bottom:12px;display:flex;align-items:center;gap:8px">
        <span style="background:#fff7ed;padding:4px 10px;border-radius:20px;border:1px solid #fed7aa">📬 En attente de traitement (${enCours.length})</span>
      </div>
      <div style="display:flex;flex-direction:column;gap:10px">
        ${enCours.map(renderCard).join('')}
      </div>
    </div>` : ''}

    ${traitees.length > 0 ? `
    <div>
      <div style="font-size:13px;font-weight:700;color:var(--gray-text);margin-bottom:12px;display:flex;align-items:center;gap:8px">
        <span style="background:var(--gray-bg);padding:4px 10px;border-radius:20px">✅ Traitées / Refusées (${traitees.length})</span>
      </div>
      <div style="display:flex;flex-direction:column;gap:10px;opacity:.8">
        ${traitees.map(renderCard).join('')}
      </div>
    </div>` : ''}
    `}
  </div>`;
}

async function traiterDemandeArchive_byId(id) {
  const all = await DemandesApi.getAll();
  const d = all.find(x => x.Id === id);
  if (!d) return;
  await traiterDemandeArchive(d);
}

async function voirDetailDemandeArchive(id) {
  const all = await DemandesApi.getAll();
  const d = all.find(x => x.Id === id);
  if (!d) return;

  let archInfo = null;
  try { archInfo = d.ArchiveData ? JSON.parse(d.ArchiveData) : null; } catch(e) {}
  const isManager = canEdit();
  const PRESTA_LABELS = { Archivage:'📦 Archivage de dossiers', Desarchivage:'🗂️ Désarchivage d\'un dossier' };

  openModal(`🗄️ Demande archive — ${escHtml(d.NomDeclarant||'—')}`, `
  <div style="display:flex;flex-direction:column;gap:14px;font-size:13px">
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <span class="badge badge-${{Demandeur:'orange','En cours':'blue',Traité:'teal',Refusé:'red'}[d.Statut]||'gray'}">${escHtml(d.Statut||'')}</span>
      ${archInfo?.prestaArchive ? `<span style="background:#f0fdf4;color:#15803d;padding:2px 10px;border-radius:4px;font-size:12px;font-weight:600">${PRESTA_LABELS[archInfo.prestaArchive]||archInfo.prestaArchive}</span>` : ''}
    </div>

    <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:12px">
      <strong>👤 ${escHtml(d.NomDeclarant||'—')}</strong><br>
      📧 ${escHtml(d.EmailDemandeur||'—')} &nbsp;|&nbsp; 📱 ${escHtml(d.TelDemandeur||'—')}
      ${d.Batiment||d.Bureau ? `<br>🏢 ${[d.Batiment,d.Bureau].filter(Boolean).join(' • ')}` : ''}
    </div>

    ${d.Description ? `<div><strong>Description :</strong><br>${_esc_a(d.Description)}</div>` : ''}

    ${archInfo ? `
    <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:12px">
      <div style="font-weight:700;color:#15803d;margin-bottom:8px">📋 Détails</div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px">
        ${archInfo.dateSolde ? `<div><strong>Date de solde :</strong> ${new Date(archInfo.dateSolde).toLocaleDateString('fr-FR')}</div>` : ''}
        ${archInfo.numeroDossierArch ? `<div><strong>N° dossier :</strong> ${_esc_a(archInfo.numeroDossierArch)}</div>` : ''}
        ${archInfo.numeroBoiteArch   ? `<div><strong>N° boîte :</strong> ${_esc_a(archInfo.numeroBoiteArch)}</div>` : ''}
        ${archInfo.mandatArch        ? `<div><strong>N° mandat :</strong> ${_esc_a(archInfo.mandatArch)}</div>` : ''}
        ${archInfo.nbDossiers        ? `<div><strong>Nb dossiers :</strong> ${archInfo.nbDossiers}</div>` : ''}
        ${archInfo.serviceArchive    ? `<div><strong>Service :</strong> ${_esc_a(archInfo.serviceArchive)}</div>` : ''}
        ${archInfo.periodeArchive    ? `<div><strong>Période :</strong> ${_esc_a(archInfo.periodeArchive)}</div>` : ''}
        ${archInfo.duaArchive        ? `<div><strong>DUA :</strong> ${_esc_a(archInfo.duaArchive)}</div>` : ''}
      </div>
    </div>` : ''}

    <div><strong>Créée le :</strong> ${fmtDateFr(d.DateCreation)}</div>
    ${d.CommentaireAdmin ? `<div style="background:#f0fdf4;border-radius:8px;padding:12px"><strong>💬 Réponse :</strong><br>${_esc_a(d.CommentaireAdmin)}</div>` : ''}

    <div style="display:flex;gap:8px">
      ${isManager && !['Traité','Refusé'].includes(d.Statut) ? `<button class="btn btn-primary btn-sm" onclick="closeModal();traiterDemandeArchive_byId(${d.Id})">✏️ Traiter</button>` : ''}
    </div>
  </div>`, null, null, 'Fermer');
}

// ══════════════════════════════════════════════════════════════════════════════
//  ONGLET : RECHERCHE AVANCÉE
// ══════════════════════════════════════════════════════════════════════════════
function _renderRecherche() {
  const f = ArchivesState.recherche || {};
  const r = ArchivesState.resultatsRecherche;

  const inp = (id, label, type='text', placeholder='') =>
    `<div class="form-group">
      <label class="form-label">${label}</label>
      <input class="form-control" type="${type}" id="rch_${id}" value="${_esc_a(f[id]||'')}" placeholder="${placeholder}"
        onkeydown="if(event.key==='Enter')lancerRechercheArchives()">
    </div>`;

  const sel = (id, label, options) =>
    `<div class="form-group">
      <label class="form-label">${label}</label>
      <select class="form-control" id="rch_${id}">
        <option value="">— Tous —</option>
        ${options.map(o => `<option value="${o}" ${f[id]===o?'selected':''}>${o}</option>`).join('')}
      </select>
    </div>`;

  let resultatsHtml = '';
  if (r !== null) {
    if (r.length === 0) {
      resultatsHtml = `<div style="text-align:center;padding:40px;color:var(--gray-text)">
        <div style="font-size:32px;margin-bottom:8px">🔍</div>
        <div>Aucun résultat pour ces critères</div>
      </div>`;
    } else {
      resultatsHtml = `
      <div style="margin-top:20px">
        <div style="font-size:13px;font-weight:600;color:var(--blue);margin-bottom:10px">${r.length} résultat(s) trouvé(s)</div>
        <div class="table-wrap" style="border:1px solid var(--gray-border);border-radius:8px;overflow:hidden">
          <table>
            <thead><tr>
              <th>N° Dossier</th><th>Nom / Prénom</th><th>Boîte</th><th>Service</th>
              <th>Commune</th><th>Dépt</th><th>Année</th><th>Date de solde</th>
              <th>Mandat</th><th>DUA</th><th>Sort final</th><th>Emplacement</th><th>Statut</th><th>Actions</th>
            </tr></thead>
            <tbody>
              ${r.map(d => `
              <tr>
                <td><strong>${_esc_a(d.NumeroDossier||'—')}</strong></td>
                <td>${_esc_a(d.NomPrenom||'—')}</td>
                <td>${_esc_a(d.NumeroBoite||'—')}</td>
                <td>${_esc_a(d.Service||'—')}</td>
                <td>${_esc_a(d.Commune||'—')}</td>
                <td>${_esc_a(d.Departement||'—')}</td>
                <td>${_esc_a(d.Annee||'—')}</td>
                <td>${fmtDateFr(d.DateSolde)}</td>
                <td>${_esc_a(d.NumeroMandat||'—')}</td>
                <td>${_esc_a(d.DUA||'—')}</td>
                <td>${_esc_a(d.SortFinal||'—')}</td>
                <td>${_esc_a(d.Emplacement||'—')}</td>
                <td>${badgeStatutArch(d.Statut)}</td>
                <td><button class="btn btn-sm" onclick="openModalEditDossier(${d.Id})">✏️ Modifier</button></td>
              </tr>`).join('')}
            </tbody>
          </table>
        </div>
      </div>`;
    }
  }

  return `
  <div style="padding:16px 20px">
    <div style="background:linear-gradient(135deg,#1565c0,#1976d2);color:white;padding:14px 18px;border-radius:10px;margin-bottom:20px">
      <div style="font-size:14px;font-weight:700;margin-bottom:4px">🔍 Recherche avancée de dossiers</div>
      <div style="font-size:12px;opacity:.85">Renseignez un ou plusieurs critères pour retrouver un dossier spécifique</div>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;margin-bottom:16px">
      ${inp('annee',         'Année',             'text', 'Ex: 2022')}
      ${inp('mois',          'Mois',              'text', 'Ex: 03')}
      ${inp('jour',          'Jour',              'text', 'Ex: 15')}
      ${inp('numeroBoite',   'N° de boîte',       'text', 'Ex: BOX-001')}
      ${inp('numeroDossier', 'N° de dossier',     'text', 'Ex: DOS-2022-001')}
      ${inp('nomPrenom',     'Nom / Prénom',      'text', 'Ex: Dupont Jean')}
      ${inp('commune',       'Commune',           'text', 'Ex: Toulouse')}
      ${inp('departement',   'Département',       'text', 'Ex: 31')}
      ${inp('service',       'Service',           'text', 'Ex: Direction finances')}
      ${inp('numeroMandat',  'N° de mandat / Réf. paiement', 'text', '')}
      ${inp('periode',       'Période',           'text', 'Ex: 2020-2022')}
      ${inp('dureeArchivage','Durée d\'archivage','text', 'Ex: 5 ans')}
      ${sel('sortFinal',     'Sort final',         [...SORT_FINAL_OPTIONS])}
      ${inp('dua',           'DUA',               'text', 'Ex: 10 ans')}
      ${inp('description',   'Description',       'text', '')}
      ${inp('emplacement',   'Emplacement',       'text', 'Ex: Salle 3, étagère B')}
      ${inp('codeBarre',     '🔖 Code barre',     'text', '')}
      ${sel('statut',        'Statut',             [...STATUT_DOSSIER])}
    </div>

    <div style="display:flex;gap:10px;margin-bottom:8px">
      <button class="btn btn-primary" onclick="lancerRechercheArchives()">🔍 Lancer la recherche</button>
      <button class="btn" onclick="reinitialiserRechercheArchives()">↺ Réinitialiser</button>
    </div>

    ${resultatsHtml}
  </div>`;
}

async function lancerRechercheArchives() {
  const ids = ['annee','mois','jour','numeroBoite','numeroDossier','nomPrenom','commune','departement','service','numeroMandat','periode','dureeArchivage','sortFinal','dua','description','emplacement','codeBarre','statut'];
  const filtres = {};
  ids.forEach(id => {
    const el = document.getElementById('rch_' + id);
    if (el) filtres[id] = el.value.trim();
  });
  ArchivesState.recherche = filtres;

  const btn = document.querySelector('[onclick="lancerRechercheArchives()"]');
  if (btn) { btn.disabled = true; btn.textContent = '⏳ Recherche…'; }

  try {
    const results = await ArchivesApi.rechercher(filtres);
    ArchivesState.resultatsRecherche = results;
  } catch(e) {
    toast('Erreur lors de la recherche : ' + e.message, 'error');
    ArchivesState.resultatsRecherche = [];
  }

  renderArchives();
}

function reinitialiserRechercheArchives() {
  ArchivesState.recherche = {};
  ArchivesState.resultatsRecherche = null;
  renderArchives();
}

// ══════════════════════════════════════════════════════════════════════════════
//  MODALS — DOSSIER
// ══════════════════════════════════════════════════════════════════════════════
function _formDossier(d = {}) {
  const sel = (id, label, options, val, req=false) =>
    `<div class="form-group">
      <label class="form-label">${label}${req?'<span class="req"> *</span>':''}</label>
      <select class="form-control" id="${id}">
        <option value="">— Choisir —</option>
        ${options.map(o => `<option value="${o}" ${(val||'')===(o)?'selected':''}>${o}</option>`).join('')}
      </select>
    </div>`;

  const inp = (id, label, val, type='text', req=false, placeholder='') =>
    `<div class="form-group">
      <label class="form-label">${label}${req?'<span class="req"> *</span>':''}</label>
      <input class="form-control" type="${type}" id="${id}" value="${_esc_a(val||'')}" placeholder="${placeholder}">
    </div>`;

  return `
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;padding:4px">
    <div style="grid-column:span 2;background:var(--blue-pale);border-radius:8px;padding:12px;font-size:12px;color:var(--navy)">
      ℹ️ Tous les champs marqués <span class="req">*</span> sont obligatoires
    </div>

    ${inp('fd_numeroDossier', 'N° de dossier', d.NumeroDossier, 'text', true, 'Ex: DOS-2024-001')}
    ${inp('fd_dateSolde', 'Date de solde', d.DateSolde, 'date', true)}
    ${inp('fd_nomPrenom',     'Nom / Prénom',   d.NomPrenom,    'text', false, 'Ex: Dupont Jean')}
    ${inp('fd_service',       'Service',        d.Service,      'text', false, 'Ex: Direction finances')}
    ${inp('fd_commune',       'Commune',        d.Commune)}
    ${inp('fd_departement',   'Département',    d.Departement,  'text', false, 'Ex: 31')}
    ${inp('fd_numeroBoite',   'N° de boîte',    d.NumeroBoite,  'text', false, 'Ex: BOX-001')}
    ${inp('fd_numeroMandat',  'N° mandat / Réf. paiement', d.NumeroMandat)}
    ${inp('fd_annee',         'Année',          d.Annee,        'text', false, 'Ex: 2022')}
    ${inp('fd_mois',          'Mois',           d.Mois,         'text', false, 'Ex: 03')}
    ${inp('fd_jour',          'Jour',           d.Jour,         'text', false, 'Ex: 15')}
    ${inp('fd_periode',       'Période',        d.Periode,      'text', false, 'Ex: 2020-2022')}
    ${inp('fd_dua',           'DUA (Durée d\'Utilité Administrative)', d.DUA, 'text', false, 'Ex: 10 ans')}
    ${inp('fd_dureeArchivage','Durée d\'archivage', d.DureeArchivage, 'text', false, 'Ex: 5 ans')}
    ${sel('fd_sortFinal',     'Sort final',     SORT_FINAL_OPTIONS, d.SortFinal)}
    ${sel('fd_statut',        'Statut',         STATUT_DOSSIER, d.Statut||'Archive')}
    <div class="form-group span-2">
      <label class="form-label">Emplacement</label>
      <input class="form-control" id="fd_emplacement" value="${_esc_a(d.Emplacement||'')}" placeholder="Ex: Salle 3, étagère B, rangée 2">
    </div>
    <div class="form-group span-2">
      <label class="form-label">Description</label>
      <textarea class="form-control" id="fd_description" rows="2">${_esc_a(d.Description||'')}</textarea>
    </div>
    <div class="form-group">
      <label class="form-label">🔖 Code barre (optionnel)</label>
      <input class="form-control" id="fd_codeBarre" value="${_esc_a(d.CodeBarre||'')}" placeholder="Scanner ou saisir">
    </div>
    <div class="form-group">
      <label class="form-label">Type de demande</label>
      <select class="form-control" id="fd_typeDemande">
        <option value="">— Non spécifié —</option>
        <option value="Archivage" ${(d.TypeDemande||'')==='Archivage'?'selected':''}>Archivage (traitement de dossiers)</option>
        <option value="Desarchivage" ${(d.TypeDemande||'')==='Desarchivage'?'selected':''}>Désarchivage (sortir un dossier)</option>
      </select>
    </div>
  </div>`;
}

function _collectDossierData() {
  const gv = id => document.getElementById(id)?.value || '';
  return {
    numeroDossier: gv('fd_numeroDossier'),
    dateSolde:     gv('fd_dateSolde'),
    nomPrenom:     gv('fd_nomPrenom'),
    service:       gv('fd_service'),
    commune:       gv('fd_commune'),
    departement:   gv('fd_departement'),
    numeroBoite:   gv('fd_numeroBoite'),
    numeroMandat:  gv('fd_numeroMandat'),
    annee:         gv('fd_annee'),
    mois:          gv('fd_mois'),
    jour:          gv('fd_jour'),
    periode:       gv('fd_periode'),
    dua:           gv('fd_dua'),
    dureeArchivage:gv('fd_dureeArchivage'),
    sortFinal:     gv('fd_sortFinal'),
    statut:        gv('fd_statut') || 'Archive',
    emplacement:   gv('fd_emplacement'),
    description:   gv('fd_description'),
    codeBarre:     gv('fd_codeBarre'),
    typeDemande:   gv('fd_typeDemande'),
  };
}

function openModalNouveauDossier() {
  openModal('🗂️ Nouveau dossier archivé', _formDossier(), async () => {
    const data = _collectDossierData();
    if (!data.numeroDossier) { toast('Le N° de dossier est obligatoire', 'error'); return; }
    if (!data.dateSolde)     { toast('La date de solde est obligatoire', 'error'); return; }
    await ArchivesApi.createDossier(data);
    toast('Dossier créé', 'success');
    renderArchives();
  }, null, null, '800px');
}

async function openModalEditDossier(id) {
  const all = await ArchivesApi.getAllDossiers();
  const d = all.find(x => x.Id === id);
  if (!d) return;
  openModal('✏️ Modifier le dossier', _formDossier(d), async () => {
    const data = _collectDossierData();
    if (!data.numeroDossier) { toast('Le N° de dossier est obligatoire', 'error'); return; }
    if (!data.dateSolde)     { toast('La date de solde est obligatoire', 'error'); return; }
    await ArchivesApi.updateDossier(id, data);
    toast('Dossier mis à jour', 'success');
    renderArchives();
  }, null, null, '800px');
}

async function supprimerDossier(id) {
  if (!confirm('Supprimer ce dossier définitivement ?')) return;
  await ArchivesApi.deleteDossier(id);
  toast('Dossier supprimé');
  renderArchives();
}

// ══════════════════════════════════════════════════════════════════════════════
//  FICHE OFFICIELLE BOÎTE (aperçu bordereau de versement)
// ══════════════════════════════════════════════════════════════════════════════
async function openFicheBoite(id) {
  const all = await ArchivesApi.getAllBoites();
  const b   = all.find(x => x.Id === id);
  if (!b) return;

  // Récupérer les dossiers de cette boîte
  const tousLesDossiers = await ArchivesApi.getAllDossiers();
  const dossiers = tousLesDossiers.filter(d => d.NumeroBoite === b.NumeroBoite || d.BoiteId === b.Id);

  const ligne = (label, val, bold=false) =>
    `<tr><td style="padding:5px 10px;font-size:12px;border:1px solid #ccc;white-space:nowrap;background:#f8faff;font-weight:600;width:220px">${label}</td>
         <td style="padding:5px 10px;font-size:12px;border:1px solid #ccc;${bold?'font-weight:700':'color:#222'}">${val||'—'}</td></tr>`;

  // Tableau feuille 2 — dossiers de la boîte
  const rowsDossiers = dossiers.length === 0
    ? `<tr><td colspan="8" style="text-align:center;padding:10px;font-size:12px;color:#888;border:1px solid #ccc">Aucun dossier associé à cette boîte</td></tr>`
    : dossiers.map((d, i) => `
      <tr style="background:${i%2===0?'white':'#f8fbff'}">
        <td style="border:1px solid #ccc;padding:4px 8px;font-size:11px;text-align:center">${i+1}</td>
        <td style="border:1px solid #ccc;padding:4px 8px;font-size:11px">${_esc_a(d.NumeroDossier||'—')} — ${_esc_a(d.NomPrenom||d.Description||'—')}</td>
        <td style="border:1px solid #ccc;padding:4px 8px;font-size:11px;text-align:center">${_esc_a(d.Periode||d.Annee||'—')}</td>
        <td style="border:1px solid #ccc;padding:4px 8px;font-size:11px;text-align:center">${_esc_a(d.DUA||b.DUA||'—')}</td>
        <td style="border:1px solid #ccc;padding:4px 8px;font-size:11px;text-align:center">${_esc_a(d.DureeArchivage||'—')}</td>
        <td style="border:1px solid #ccc;padding:4px 8px;font-size:11px;text-align:center">${fmtDateFr(d.DateSolde)}</td>
        <td style="border:1px solid #ccc;padding:4px 8px;font-size:11px;text-align:center">${_esc_a(b.AnneeRevision||'—')}</td>
        <td style="border:1px solid #ccc;padding:4px 8px;font-size:11px;color:${d.SortFinal==='Destruction'?'#dc2626':'#15803d'};font-weight:600">${_esc_a(d.SortFinal||b.SortFinal||'—')}</td>
      </tr>`).join('');

  openModal(`📋 Fiche boîte — ${escHtml(b.NumeroBoite||'')}`, `
  <div id="ficheBoiteContent" style="font-family:'Segoe UI',Arial,sans-serif;max-width:820px">

    <!-- Titre général -->
    <div style="text-align:center;border:2px solid #1565c0;border-radius:8px 8px 0 0;background:#1565c0;color:white;padding:10px 16px;font-size:14px;font-weight:700;letter-spacing:.5px">
      BORDEREAU DE VERSEMENT AUX ARCHIVES
    </div>

    <!-- ══ FEUILLE 1 ══ -->
    <div style="border:1px solid #1565c0;border-top:none;margin-bottom:18px">

      <div style="background:#dbeafe;padding:6px 14px;font-size:11px;font-weight:700;color:#1e40af;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid #93c5fd">
        📤 Partie réservée au service versant
      </div>
      <table style="width:100%;border-collapse:collapse">
        ${ligne('Nom du service',                   b.Service)}
        ${ligne('Nom de l\'agent responsable',      b.AgentResponsable)}
        ${ligne('Date de versement',                b.DateVersement ? new Date(b.DateVersement.substring(0,10)).toLocaleDateString('fr-FR') : null)}
        ${ligne('Nombre de boîtes',                 b.NombreBoites)}
      </table>

      <div style="background:#bfdbfe;padding:6px 14px;font-size:11px;font-weight:700;color:#1e40af;text-transform:uppercase;letter-spacing:.5px;border-top:1px solid #93c5fd;border-bottom:1px solid #93c5fd">
        🗄️ Partie réservée au service d'archivage
      </div>
      <table style="width:100%;border-collapse:collapse">
        ${ligne('Numéro du versement',              b.NumeroVersement || b.NumeroBoite)}
        ${ligne('Dates extrêmes du versement',      b.DatesExtremes || b.Periode)}
        ${ligne('Localisation',                     b.Localisation || b.Emplacement)}
        ${ligne('Année de révision du bordereau',   b.AnneeRevision)}
      </table>

      <!-- Tableau descriptif sommaire -->
      <div style="background:#dbeafe;padding:6px 14px;font-size:11px;font-weight:700;color:#1e40af;text-transform:uppercase;letter-spacing:.5px;border-top:1px solid #93c5fd;border-bottom:1px solid #93c5fd">
        📋 N° d'articles — Descriptif sommaire et dates extrêmes des principales catégories de documents versés
      </div>
      <div style="padding:10px 14px;font-size:12px;min-height:60px;white-space:pre-line;color:#222;border-bottom:1px solid #d1d5db">
        ${b.Description ? _esc_a(b.Description) : '<span style="color:#888;font-style:italic">— Non renseigné —</span>'}
      </div>
    </div>

    <!-- ══ FEUILLE 2 ══ -->
    <div style="border:1px solid #0891b2;border-radius:6px;overflow:hidden">

      <div style="background:#0891b2;color:white;padding:8px 14px;font-size:13px;font-weight:700;letter-spacing:.5px">
        📦 FEUILLE 2 — DÉTAIL DU CONTENU
      </div>

      <div style="padding:6px 10px;background:#f0f9ff;border-bottom:1px solid #bae6fd;display:flex;gap:20px;font-size:12px">
        <div><span style="color:#0369a1;font-weight:600">Service versant :</span> ${_esc_a(b.Service||'—')}</div>
        <div><span style="color:#0369a1;font-weight:600">N° de versement :</span> ${_esc_a(b.NumeroVersement||b.NumeroBoite||'—')}</div>
      </div>

      <div style="overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;font-size:11px">
          <thead>
            <tr style="background:#e0f2fe">
              <th style="border:1px solid #ccc;padding:6px 8px;text-align:center;white-space:nowrap">N° d'ordre</th>
              <th style="border:1px solid #ccc;padding:6px 8px;text-align:left">Résumé du contenu de la boîte / du registre</th>
              <th style="border:1px solid #ccc;padding:6px 8px;text-align:center;white-space:nowrap">Date extrême</th>
              <th style="border:1px solid #ccc;padding:6px 8px;text-align:center">DUA</th>
              <th style="border:1px solid #ccc;padding:6px 8px;text-align:center">Délai</th>
              <th style="border:1px solid #ccc;padding:6px 8px;text-align:center">Date</th>
              <th style="border:1px solid #ccc;padding:6px 8px;text-align:center;white-space:nowrap">Année révision</th>
              <th style="border:1px solid #ccc;padding:6px 8px;text-align:center;white-space:nowrap">Sort final</th>
            </tr>
          </thead>
          <tbody>
            ${rowsDossiers}
          </tbody>
        </table>
      </div>

      ${dossiers.length > 0 ? `<div style="font-size:11px;color:#0369a1;padding:6px 10px;background:#f0f9ff;border-top:1px solid #bae6fd">${dossiers.length} dossier(s) associé(s)</div>` : ''}
    </div>

    <!-- Bouton impression -->
    <div style="text-align:right;margin-top:14px">
      <button class="btn btn-primary btn-sm" onclick="printFicheBoite()" style="font-size:12px">🖨️ Imprimer la fiche</button>
    </div>
  </div>`, null, null, 'Fermer', '880px');
}

function printFicheBoite() {
  const content = document.getElementById('ficheBoiteContent');
  if (!content) return;
  const win = window.open('', '_blank', 'width=900,height=700');
  win.document.write(`<!DOCTYPE html><html><head><title>Fiche bordereau</title>
    <style>body{font-family:'Segoe UI',Arial,sans-serif;padding:20px;color:#111}
    table{width:100%;border-collapse:collapse}
    @media print{button{display:none}}</style>
    </head><body>${content.innerHTML}</body></html>`);
  win.document.close();
  win.focus();
  setTimeout(() => win.print(), 400);
}

// ══════════════════════════════════════════════════════════════════════════════
//  MODALS — BOÎTE
// ══════════════════════════════════════════════════════════════════════════════
function _formBoite(b = {}) {
  const inp = (id, label, val, type='text', req=false, placeholder='') =>
    `<div class="form-group">
      <label class="form-label">${label}${req?'<span class="req"> *</span>':''}</label>
      <input class="form-control" type="${type}" id="${id}" value="${_esc_a(val||'')}" placeholder="${placeholder}">
    </div>`;
  const sel = (id, label, options, val) =>
    `<div class="form-group">
      <label class="form-label">${label}</label>
      <select class="form-control" id="${id}">
        <option value="">— Choisir —</option>
        ${options.map(o => `<option value="${o}" ${(val||'')===(o)?'selected':''}>${o}</option>`).join('')}
      </select>
    </div>`;

  return `
  <div style="display:flex;flex-direction:column;gap:16px;padding:4px">

    <!-- ════ FEUILLE 1 ════ -->
    <div style="border:2px solid var(--blue);border-radius:10px;overflow:hidden">

      <!-- Partie service versant -->
      <div style="background:#1565c0;color:white;padding:8px 14px;font-size:12px;font-weight:700;letter-spacing:.5px">
        📤 PARTIE RÉSERVÉE AU SERVICE VERSANT
      </div>
      <div style="padding:14px;display:grid;grid-template-columns:1fr 1fr;gap:12px;background:var(--gray-bg)">
        ${inp('fb_service',         'Nom du service',                  b.Service,          'text', true,  'Ex: Direction des finances')}
        ${inp('fb_agentResponsable','Nom de l\'agent responsable du versement', b.AgentResponsable, 'text', false, 'Nom Prénom')}
        ${inp('fb_dateVersement',   'Date de versement',               b.DateVersement,    'date', false)}
        ${inp('fb_nombreBoites',    'Nombre de boîtes',                b.NombreBoites,     'number', false, 'Ex: 5')}
      </div>

      <!-- Partie service archivage -->
      <div style="background:#0d47a1;color:white;padding:8px 14px;font-size:12px;font-weight:700;letter-spacing:.5px">
        🗄️ PARTIE RÉSERVÉE AU SERVICE D'ARCHIVAGE
      </div>
      <div style="padding:14px;display:grid;grid-template-columns:1fr 1fr;gap:12px;background:var(--blue-pale)">
        ${inp('fb_numeroBoite',     'N° de boîte (référence)',         b.NumeroBoite,      'text', true,  'Ex: BOX-001')}
        ${inp('fb_numeroVersement', 'Numéro du versement',             b.NumeroVersement,  'text', false, 'Ex: VERS-2024-001')}
        ${inp('fb_datesExtremes',   'Dates extrêmes du versement',     b.DatesExtremes,    'text', false, 'Ex: 2018-2023')}
        ${inp('fb_localisation',    'Localisation',                    b.Localisation,     'text', false, 'Ex: Salle 2, étagère C')}
        ${inp('fb_anneeRevision',   'Année de révision du bordereau',  b.AnneeRevision,    'text', false, 'Ex: 2030')}
        ${inp('fb_batiment',        'Bâtiment',                        b.Batiment,         'text', false, 'Ex: Bâtiment A')}
      </div>

      <!-- Tableau : N° d'articles / Descriptif sommaire -->
      <div style="background:#1565c0;color:white;padding:8px 14px;font-size:12px;font-weight:700;letter-spacing:.5px">
        📋 TABLEAU — DESCRIPTIF SOMMAIRE DES DOCUMENTS VERSÉS
      </div>
      <div style="padding:14px;background:var(--gray-bg)">
        <div style="font-size:11px;color:var(--navy);margin-bottom:8px;font-style:italic">
          Indiquez les principales catégories de documents et leurs dates extrêmes
        </div>
        <div class="form-group">
          <label class="form-label">N° d'articles — Descriptif sommaire et dates extrêmes</label>
          <textarea class="form-control" id="fb_description" rows="4"
            placeholder="Ex:&#10;Art.1 — Délibérations du conseil municipal (2018-2022)&#10;Art.2 — Marchés publics travaux (2019-2023)&#10;Art.3 — Actes d'état civil (2020-2022)"
          >${_esc_a(b.Description||'')}</textarea>
        </div>
      </div>
    </div>

    <!-- ════ FEUILLE 2 ════ -->
    <div style="border:2px solid #0891b2;border-radius:10px;overflow:hidden">
      <div style="background:#0891b2;color:white;padding:8px 14px;font-size:12px;font-weight:700;letter-spacing:.5px">
        📦 FEUILLE 2 — DÉTAIL DU CONTENU PAR BOÎTE / REGISTRE
      </div>
      <div style="padding:14px;display:grid;grid-template-columns:1fr 1fr;gap:12px;background:var(--gray-bg)">
        ${inp('fb_dua',           'DUA (Durée d\'Utilité Administrative)', b.DUA, 'text', false, 'Ex: 10 ans')}
        ${inp('fb_periode',       'Période couverte',   b.Periode,     'text', false, 'Ex: 2020-2024')}
        ${inp('fb_dureeArchivage','Durée d\'archivage', b.DureeArchivage, 'text', false, 'Ex: 5 ans')}
        ${inp('fb_emplacement',   'Emplacement précis', b.Emplacement, 'text', false, 'Salle 3, étagère B, niveau 2')}
        ${sel('fb_sortFinal',     'Sort final',          SORT_FINAL_OPTIONS, b.SortFinal)}
        ${sel('fb_statut',        'Statut', ['Active','Pleine','Archivée','Détruite'], b.Statut||'Active')}
        <div class="form-group" style="grid-column:span 2">
          <label class="form-label">🔖 Code barre (optionnel)</label>
          <input class="form-control" id="fb_codeBarre" value="${_esc_a(b.CodeBarre||'')}" placeholder="Scanner ou saisir">
        </div>
      </div>
    </div>

  </div>`;
}

function _collectBoiteData() {
  const gv = id => document.getElementById(id)?.value || '';
  return {
    numeroBoite:      gv('fb_numeroBoite'),
    service:          gv('fb_service'),
    agentResponsable: gv('fb_agentResponsable'),
    dateVersement:    gv('fb_dateVersement'),
    nombreBoites:     gv('fb_nombreBoites'),
    numeroVersement:  gv('fb_numeroVersement'),
    datesExtremes:    gv('fb_datesExtremes'),
    localisation:     gv('fb_localisation'),
    anneeRevision:    gv('fb_anneeRevision'),
    batiment:         gv('fb_batiment'),
    emplacement:      gv('fb_emplacement'),
    dua:              gv('fb_dua'),
    dureeArchivage:   gv('fb_dureeArchivage'),
    sortFinal:        gv('fb_sortFinal'),
    periode:          gv('fb_periode'),
    description:      gv('fb_description'),
    codeBarre:        gv('fb_codeBarre'),
    statut:           gv('fb_statut') || 'Active',
  };
}

function openModalNouvelleBoite() {
  openModal('📦 Nouvelle boîte d\'archives', _formBoite(), async () => {
    const data = _collectBoiteData();
    if (!data.numeroBoite) { toast('Le N° de boîte est obligatoire', 'error'); return; }
    await ArchivesApi.createBoite(data);
    toast('Boîte créée', 'success');
    renderArchives();
  }, null, null, '700px');
}

async function openModalEditBoite(id) {
  const all = await ArchivesApi.getAllBoites();
  const b = all.find(x => x.Id === id);
  if (!b) return;
  openModal('✏️ Modifier la boîte', _formBoite(b), async () => {
    const data = _collectBoiteData();
    if (!data.numeroBoite) { toast('Le N° de boîte est obligatoire', 'error'); return; }
    await ArchivesApi.updateBoite(id, data);
    toast('Boîte mise à jour', 'success');
    renderArchives();
  }, null, null, '700px');
}

async function supprimerBoite(id) {
  if (!confirm('Supprimer cette boîte et tous ses dossiers associés ?')) return;
  await ArchivesApi.deleteBoite(id);
  toast('Boîte supprimée');
  renderArchives();
}

// ══════════════════════════════════════════════════════════════════════════════
//  MODALS — BORDEREAU
// ══════════════════════════════════════════════════════════════════════════════
function openModalNouveauBordereau(type) {
  const isElim = type === 'Elimination';
  ArchivesApi.getAllBoites().then(boites => {
  const boiteOpts = boites.map(b => `<option value="${b.Id}">${escHtml(b.NumeroBoite||'')}${b.Service ? ' — '+b.Service : ''}</option>`).join('');
  openModal(
    isElim ? '🗑️ Nouveau bordereau d\'élimination' : '📋 Nouveau bordereau de versement',
    `<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;padding:4px">
      <div class="form-group">
        <label class="form-label">N° de bordereau <span class="req">*</span></label>
        <input class="form-control" id="fbo_numero" placeholder="Ex: BV-2024-001">
      </div>
      <div class="form-group">
        <label class="form-label">Date</label>
        <input class="form-control" type="date" id="fbo_date">
      </div>
      <div class="form-group">
        <label class="form-label">Service</label>
        <input class="form-control" id="fbo_service" placeholder="Service émetteur">
      </div>
      <div class="form-group">
        <label class="form-label">Statut <span class="req">*</span></label>
        <select class="form-control" id="fbo_statut">
          <option value="Attente Archivage">⏳ Attente Archivage</option>
          <option value="Desarchivage">📤 Désarchivage</option>
          <option value="Archive">✅ Archivé</option>
        </select>
      </div>
      <div class="form-group span-2">
        <label class="form-label">📍 Localisation de la boîte</label>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px">
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer;border:1px solid var(--gray-border);border-radius:6px;padding:8px 10px" id="lbl_loc_nous" onclick="selectBordereauLoc('nous')">
            <input type="radio" name="fbo_loc" value="nous" style="accent-color:var(--blue)"> <span style="font-size:12px;font-weight:600">🏢 Nous</span>
          </label>
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer;border:1px solid var(--gray-border);border-radius:6px;padding:8px 10px" id="lbl_loc_presta" onclick="selectBordereauLoc('presta')">
            <input type="radio" name="fbo_loc" value="presta" style="accent-color:var(--blue)"> <span style="font-size:12px;font-weight:600">🏭 Presta</span>
          </label>
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer;border:1px solid var(--gray-border);border-radius:6px;padding:8px 10px" id="lbl_loc_boite" onclick="selectBordereauLoc('boite')">
            <input type="radio" name="fbo_loc" value="boite" style="accent-color:var(--blue)"> <span style="font-size:12px;font-weight:600">📦 Dans la boîte</span>
          </label>
        </div>
      </div>
      <div class="form-group span-2">
        <label class="form-label">📦 Boîte associée</label>
        <select class="form-control" id="fbo_boiteId">
          <option value="">— Aucune boîte liée —</option>
          ${boiteOpts}
        </select>
      </div>
      <div class="form-group span-2">
        <label class="form-label">Description</label>
        <textarea class="form-control" id="fbo_description" rows="2" placeholder="Contenu du bordereau…"></textarea>
      </div>
      <div class="form-group span-2">
        <label class="form-label">📎 Fichier joint (PDF, Word, Excel…)</label>
        <input type="file" id="fbo_fichier" accept=".pdf,.doc,.docx,.xls,.xlsx,.odt,.ods"
          style="font-size:13px;padding:6px 10px;border:1px solid var(--gray-border);border-radius:6px;width:100%">
        <div style="font-size:11px;color:var(--gray-text);margin-top:4px">Formats acceptés : PDF, Word, Excel — max 20 Mo</div>
      </div>
    </div>`,
    async () => {
      const gv = id => document.getElementById(id)?.value || '';
      const numero = gv('fbo_numero');
      if (!numero) { toast('Le N° de bordereau est obligatoire', 'error'); return; }

      let nomFichier = null, typeMime = null, taille = 0, donnees = null;
      const fileEl = document.getElementById('fbo_fichier');
      if (fileEl?.files?.length > 0) {
        const file = fileEl.files[0];
        if (file.size > 20 * 1024 * 1024) { toast('Fichier trop volumineux (max 20 Mo)', 'error'); return; }
        nomFichier = file.name;
        typeMime = file.type;
        taille = file.size;
        donnees = await new Promise((res, rej) => {
          const r = new FileReader();
          r.onload = () => res(r.result.split(',')[1]);
          r.onerror = () => rej(new Error('Lecture fichier échouée'));
          r.readAsDataURL(file);
        });
      }

      const loc = document.querySelector('input[name="fbo_loc"]:checked')?.value || '';
      await ArchivesApi.createBordereau({
        type, numero,
        dateBordereau: gv('fbo_date'),
        service: gv('fbo_service'),
        statut: gv('fbo_statut') || 'Attente Archivage',
        localisation: loc,
        boiteId: gv('fbo_boiteId') || null,
        description: gv('fbo_description'),
        nomFichier, typeMime, taille, donnees,
      });
      toast('Bordereau enregistré', 'success');
      renderArchives();
    },
    null, null, '650px'
  );
  }); // end ArchivesApi.getAllBoites().then
}

function selectBordereauLoc(val) {
  ['nous','presta','boite'].forEach(v => {
    const lbl = document.getElementById('lbl_loc_' + v);
    if (lbl) lbl.style.borderColor = v === val ? 'var(--blue)' : 'var(--gray-border)';
    const inp = lbl?.querySelector('input');
    if (inp) inp.checked = v === val;
  });
}

async function supprimerBordereau(id) {
  if (!confirm('Supprimer ce bordereau définitivement ?')) return;
  await ArchivesApi.deleteBordereau(id);
  toast('Bordereau supprimé');
  renderArchives();
}

// ── Badges statut et localisation bordereau ──────────────────────────────────
function _renderStatutBordereau(s) {
  const MAP = {
    'Attente Archivage': 'background:#fef3c7;color:#92400e;border:1px solid #fcd34d',
    'Desarchivage':      'background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe',
    'Archive':           'background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0',
  };
  const style = MAP[s] || 'background:var(--gray-bg);color:var(--gray-text)';
  const labels = { 'Attente Archivage':'⏳ Attente Archivage', 'Desarchivage':'📤 Désarchivage', 'Archive':'✅ Archivé' };
  return `<span style="font-size:11px;padding:2px 8px;border-radius:4px;${style}">${labels[s]||s||'—'}</span>`;
}

function _renderLocBordereau(loc) {
  const MAP = { 'nous':'🏢 Nous', 'presta':'🏭 Presta', 'boite':'📦 Boîte' };
  return loc ? `<span style="font-size:11px;background:#f8fafc;border:1px solid #e2e8f0;padding:2px 7px;border-radius:4px">${MAP[loc]||loc}</span>` : '—';
}

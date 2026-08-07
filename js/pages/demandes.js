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
 * Larka — Page : Demandes d'intervention
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Double interface : demandeur (formulaire simplifié) / gestionnaire (traitement).
 *
 * FONCTIONNALITÉS :
 *   - Formulaire demandeur : titre, description, bâtiment, photos, pour autrui
 *   - Deux types : Technique (maintenance) et Archive (archivage)
 *   - Détection de doublons en temps réel
 *   - Traitement gestionnaire : statut, priorité, catégorie, commentaire
 *   - Relance automatique configurable (délai en jours)
 *   - Bâtiment en liste déroulante configurable
 *
 * POINTS D'ENTRÉE : renderDemandes(), nouvelleDemande(), traiterDemande(id)
 * ═══════════════════════════════════════════════════════════════════════════════
 */

const URGENCE_COLORS = { Urgente:'red', Haute:'orange', Normale:'blue', Basse:'teal' };
const STATUT_D_COLORS = { Nouveau:'orange', 'En cours':'blue', Traité:'teal', Refusé:'red', Relancé:'purple' };

function _escDem(s) { return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function badgeU(u) { return `<span class="badge badge-${URGENCE_COLORS[u]||'blue'}">${u||'—'}</span>`; }
function badgeS(s) { return `<span class="badge badge-${STATUT_D_COLORS[s]||'gray'}">${s||'—'}</span>`; }
function fmtDt(d)  { return d ? new Date(d).toLocaleDateString('fr-FR',{day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'}) : '—'; }
function joursDepuis(d) { return d ? Math.floor((Date.now()-new Date(d).getTime())/(864e5)) : 0; }
function peutRelancer(d) {
  if (!['Nouveau','En cours','Relancé'].includes(d.Statut)) return false;
  const delai = d.DelaiRelanceJours || 7;
  const ref   = d.DateDerniereRelance || d.DateCreation;
  return ref && (Date.now()-new Date(ref).getTime())/(864e5) >= delai;
}

// État pagination & photos
let _demDefaultItemsPerPage = 25;
let _demPendingPhotos = [];
let _demMaxPhotos = 4;

async function renderDemandes() {
  const isManager = canEdit();
  document.getElementById('topbarActions').innerHTML = '';
  const c = document.getElementById('mainContent');
  showLoading(c);
  try {
    // Charger config photos, pagination et vitesse bandeau
    let _bandeauVitesse = 8;
    try {
      const configs = await ConfigApi.getAll();
      const cfgMax = configs.find(r => r.Cle === 'max_photos_demande');
      if (cfgMax) _demMaxPhotos = parseInt(cfgMax.Valeur) || 4;
      const cfgPag = configs.find(r => r.Cle === 'items_par_page');
      if (cfgPag) _demDefaultItemsPerPage = parseInt(cfgPag.Valeur) || 25;
      const cfgBdx = configs.find(r => r.Cle === 'bandeau_vitesse');
      if (cfgBdx) _bandeauVitesse = parseInt(cfgBdx.Valeur) || 8;
    } catch(_) {}

    const [all, interventions] = await Promise.all([
      DemandesApi.getAll(),
      isManager ? InterventionsApi.getAll().catch(()=>[]) : Promise.resolve([]),
    ]);
    // Map demande Id → intervention liée
    const intervParDemande = {};
    interventions.forEach(i => { if (i.DemandeId) intervParDemande[i.DemandeId] = i; });
    const actifs = all.filter(d => !['Traité','Refusé'].includes(d.Statut) && d.TypeLocalisation !== 'Archive');

    // Notes info pour les demandeurs
    let notesHtml = '';
    if (!isManager) {
      try {
        const notes = await NotesInfoApi.getAll();
        if (notes.length > 0) {
          const mode = (typeof getNotesMode === 'function') ? getNotesMode() : 'defilant';

          if (mode === 'fixe') {
            // Mode note fixe : chaque note dans un bloc statique
            notes.forEach(n => {
              notesHtml += `
              <div style="background:linear-gradient(135deg,#0d47a1,#1565c0);color:white;padding:14px 20px;border-radius:8px;margin-bottom:12px;display:flex;align-items:flex-start;gap:12px">
                <span style="font-size:18px;flex-shrink:0">📌</span>
                <div style="font-size:14px;line-height:1.5">${escHtml(n.Message||'')}</div>
              </div>`;
            });
          } else {
            // Mode bandeau défilant (défaut)
            const textes = notes.map(n => n.Message).join('&nbsp;&nbsp;&nbsp;•&nbsp;&nbsp;&nbsp;');
            notesHtml += `
            <div style="background:linear-gradient(135deg,#1565c0,#1976d2);color:white;padding:10px 20px;border-radius:8px;margin-bottom:16px;overflow:hidden;position:relative">
              <div style="display:flex;align-items:center;gap:10px">
                <span style="font-size:18px;flex-shrink:0">📢</span>
                <div style="overflow:hidden;flex:1">
                  <div class="notes-scroll" style="white-space:nowrap;animation:scrollNotes ${_bandeauVitesse * 2}s linear infinite">
                    ${textes}
                  </div>
                </div>
              </div>
              <style>
                @keyframes scrollNotes {
                  0%   { transform: translateX(100%); }
                  100% { transform: translateX(-100%); }
                }
              </style>
            </div>`;
          }
        }
      } catch(_) {}
    }

    _renderDemandes(c, actifs, isManager, notesHtml, intervParDemande);
  } catch(e) { c.innerHTML = errorHtml(e.message); }
}

function _renderDemandes(c, data, isManager, notesHtml, intervParDemande = {}) {
  let filtered = data;
  // FiltresEngine avancé
  filtered = filtered.filter(d => FiltresEngine.matchRow('demandes', d));
  const search = (App.searchTerm||'').toLowerCase();
  if (search) filtered = filtered.filter(d =>
    [d.Titre, d.Description, d.NomDeclarant, d.NomDemandeur, d.Batiment, d.Bureau].some(v => (v||'').toLowerCase().includes(search))
  );

  // Pagination
  const perPage = App.getRowsPerPage('demandes', _demDefaultItemsPerPage);
  const total = filtered.length;
  const totalPages = (perPage === 'all') ? 1 : Math.max(1, Math.ceil(total / perPage));
  let page = App.getPage('demandes');
  if (page > totalPages) { page = totalPages; App.setPageSilently(page, 'demandes'); }
  const start = (perPage === 'all') ? 0 : (page - 1) * perPage;
  const pageData = (perPage === 'all') ? filtered : filtered.slice(start, start + perPage);
  const from = total === 0 ? 0 : (start + 1);
  const to   = total === 0 ? 0 : (start + pageData.length);

  let html = notesHtml || '';

  html += `
  <div class="card" style="padding:0;margin-bottom:16px">
    <div class="search-bar" style="gap:8px">
      <div class="search-input-wrap" style="flex:1;max-width:480px">
        <span class="search-icon">🔍</span>
        <input class="search-input" type="text" placeholder="Rechercher dans toutes les demandes…"
          value="${App.searchTerm||''}" oninput="App.handleSearch(this.value)" onkeydown="App.handleSearchKey(event)">
      </div>
      ${FiltresEngine.renderButton('demandes')}

      <select class="form-control" style="font-size:13px;width:auto;padding:6px 10px" onchange="App.setRowsPerPage(this.value,'demandes')" title="Demandes par page">
        <option value="10"  ${perPage===10?'selected':''}>10</option>
        <option value="25"  ${perPage===25?'selected':''}>25</option>
        <option value="50"  ${perPage===50?'selected':''}>50</option>
        <option value="100" ${perPage===100?'selected':''}>100</option>
        <option value="200" ${perPage===200?'selected':''}>200</option>
        <option value="all" ${perPage==='all'?'selected':''}>Tout</option>
      </select>
      <div style="flex:1"></div>
      <button class="btn btn-primary" onclick="nouvelleDemande()">+ Nouvelle demande</button>
    </div>
    ${FiltresEngine.renderPanel('demandes')}
  </div>`;

  if (filtered.length === 0) {
    html += `<div class="card" style="padding:48px;text-align:center;color:var(--gray-text)">
      📭 Aucune demande en cours.
      <br><br><button class="btn btn-primary" onclick="nouvelleDemande()">+ Créer une demande</button>
    </div>`;
    c.innerHTML = html;
    App.restoreFilters();
    return;
  }

  html += `<div style="display:flex;flex-direction:column;gap:10px">`;
  pageData.forEach(d => {
    const j     = joursDepuis(d.DateCreation);
    const ancien = j > (d.DelaiRelanceJours||7);
    const peutR  = !isManager && peutRelancer(d);
    const pourAutrui = d.PourAutrui == 1;
    html += `
    <div class="card" style="padding:16px 20px;cursor:pointer"
      onclick="voirDetailDemande(${d.Id})"
      onmouseover="this.style.boxShadow='0 2px 12px rgba(0,0,0,.1)'"
      onmouseout="this.style.boxShadow=''">
      <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px">
        <div style="flex:1;min-width:0">
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;flex-wrap:wrap">
            ${badgeU(d.Urgence)} ${badgeS(d.Statut)}
            ${pourAutrui ? '<span class="badge badge-purple" style="font-size:10px">👥 Pour autrui</span>' : ''}
            ${d.Categorie ? `<span class="badge badge-gray" style="font-size:10px">🏷️ ${_escDem(d.Categorie)}</span>` : ''}
            ${ancien && !isManager ? `<span style="font-size:11px;color:var(--orange)">⏰ ${j}j sans réponse</span>` : ''}
            <span style="font-size:11px;color:var(--gray-text)">${fmtDt(d.DateCreation)}</span>
          </div>
          <div style="font-weight:600;font-size:14px;margin-bottom:3px">${_escDem(d.Titre)}</div>
          ${d.Description ? `<div style="font-size:13px;color:var(--gray-text);margin-bottom:3px">${_escDem((d.Description||'').substring(0,120))}${(d.Description||'').length>120?'…':''}</div>` : ''}
          <div style="font-size:12px;color:var(--gray-text)">
            ${d.Batiment ? `🏢 ${_escDem(d.Batiment)}` : ''} ${d.Bureau ? `• Bureau ${_escDem(d.Bureau)}` : ''}
            ${isManager ? `• 👤 ${_escDem(d.NomDeclarant||'—')}` : ''}
            ${isManager && d.EmailDemandeur ? `• 📧 ${_escDem(d.EmailDemandeur)}` : ''}
            ${isManager && d.TelDemandeur ? `• 📱 ${_escDem(d.TelDemandeur)}` : ''}
          </div>
          ${d.CommentaireAdmin ? `<div style="margin-top:8px;padding:8px 12px;background:var(--gray-bg);border-radius:6px;font-size:12px">💬 ${_escDem(d.CommentaireAdmin)}</div>` : ''}
          ${(() => {
            const interv = intervParDemande[d.Id];
            if (interv) {
              const sc = {Planifiée:'blue','En cours':'orange','Réalisée':'cyan',Validée:'teal'}[interv.Statut]||'gray';
              const sLabel = interv.Statut === 'Validée' ? 'Terminée' : interv.Statut;
              return `<div style="margin-top:8px;padding:8px 12px;background:var(--gray-bg);border-radius:6px;font-size:12px;display:flex;align-items:center;gap:8px">
                🔧 <strong>Intervention liée :</strong>
                <span class="badge badge-${sc}" style="font-size:10px">${sLabel}</span>
                <span style="font-family:monospace;font-weight:600">${escHtml(interv.Numero||'')}</span>
                ${interv.Type ? `<span style="color:var(--gray-text)">• ${escHtml(interv.Type||'')}</span>` : ''}
                <button class="btn btn-ghost btn-sm" style="margin-left:auto;font-size:11px" onclick="event.stopPropagation();editInterv(${interv.Id})">Voir →</button>
              </div>`;
            }
            return '';
          })()}
        </div>
        <div style="display:flex;gap:8px;flex-shrink:0;padding-top:2px;flex-direction:column;align-items:flex-end">
          <div style="display:flex;gap:8px">
            ${isManager ? `<button class="btn btn-ghost btn-sm" onclick="event.stopPropagation();traiterDemande(${d.Id})">✏️ Traiter</button>` : ''}
            ${peutR ? `<button class="btn btn-ghost btn-sm" style="color:var(--orange);border-color:var(--orange)" onclick="event.stopPropagation();relancerDemande(${d.Id})">🔔 Relancer</button>` : ''}
          </div>
          ${isManager && !intervParDemande[d.Id] && d.TypeLocalisation !== 'Archive' ? `<button class="btn btn-ghost btn-sm" style="color:var(--teal);border-color:var(--teal);font-size:11px" onclick="event.stopPropagation();creerIntervDepuisDemande(${d.Id})">🔧 Créer intervention</button>` : ''}
        </div>
      </div>
    </div>`;
  });
  html += `</div>`;

  // Pagination
  if (totalPages > 1) {
    html += `
    <div style="display:flex;align-items:center;justify-content:center;gap:12px;margin-top:16px;padding:12px">
      <button class="btn btn-ghost btn-sm" ${page<=1?'disabled':''} onclick="App.setPage(${page-1},'demandes')">← Précédent</button>
      <span style="font-size:13px;color:var(--gray-text)">Page ${page} / ${totalPages} — ${total} demande${total>1?'s':''} • Affichage ${from}-${to}</span>
      <button class="btn btn-ghost btn-sm" ${page>=totalPages?'disabled':''} onclick="App.setPage(${page+1},'demandes')">Suivant →</button>
    </div>`;
  } else {
    html += `<div style="margin-top:10px;font-size:12px;color:var(--gray-text);text-align:right">${total} demande${total>1?'s':''} • Affichage ${from}-${to} • Cliquez pour le détail</div>`;
  }

  c.innerHTML = html;
  App.restoreFilters();
}

// ── Détection doublons ──────────────────────────────────────────────────────────
async function checkDoublons(batiment, bureau, titre) {
  try {
    const all  = await DemandesApi.getAll();
    const t    = titre.toLowerCase();
    return all.filter(d => {
      if (['Traité','Refusé'].includes(d.Statut)) return false;
      const lieu  = (batiment && d.Batiment===batiment) || (bureau && d.Bureau===bureau);
      const simil = d.Titre.toLowerCase().split(' ').some(m => m.length>3 && t.includes(m));
      return lieu || simil;
    });
  } catch(_) { return []; }
}

// ── Nouvelle demande (champs obligatoires + photos + pour autrui) ──────────────
async function nouvelleDemande() {
  _demPendingPhotos = [];
  const user = App.currentUser || {};
  const batiments = await ListesApi.getByCategorie('Batiment').catch(()=>[]);
  const batFieldHtml = batiments.length
    ? listFieldHtml('f_batiment', batiments, '', {emptyOption:'—', placeholder:'Bâtiment'})
    : `<input class="form-control" id="f_batiment" placeholder="Ex: Bâtiment A" onblur="verifierDoublons()">`;

  openModal("Nouvelle demande", `
  <div class="form-grid">

    <!-- Sélection type de demande en onglets -->
    <div class="form-group span-2">
      <label class="form-label" style="margin-bottom:8px">Type de demande</label>
      <div style="display:flex;border:2px solid var(--gray-border);border-radius:10px;overflow:hidden;background:var(--gray-bg)">
        <button type="button" id="tab_technique"
          onclick="switchTypeDemande('Technique')"
          style="flex:1;padding:10px 16px;border:none;background:var(--blue);color:white;font-size:13px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px">
          🔧 Technique
        </button>
        <button type="button" id="tab_archive"
          onclick="switchTypeDemande('Archive')"
          style="flex:1;padding:10px 16px;border:none;background:transparent;color:var(--gray-text);font-size:13px;font-weight:400;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px">
          🗄️ Archive
        </button>
      </div>
      <input type="hidden" id="f_typeLocalisation" value="Technique">
    </div>

    <!-- Titre — caché en mode Archive -->
    <div class="form-group span-2" id="bloc_titre">
      <label class="form-label">Titre <span class="req">*</span></label>
      <input class="form-control" id="f_titre" placeholder="Ex: Luminaire cassé couloir B" onblur="verifierDoublons()">
    </div>
    <div class="form-group span-2" id="warnDoublons" style="display:none"></div>

    <div class="form-group span-2">
      <label class="form-label" id="lbl_description">Description</label>
      <textarea class="form-control" id="f_description" rows="3" placeholder="Décrivez le problème…"></textarea>
    </div>

    <div class="form-group">
      <label class="form-label">Votre adresse email <span class="req">*</span></label>
      <input class="form-control" id="f_email" type="email" placeholder="nom@exemple.fr" value="${escHtml(user.Login||'')}">
    </div>
    <div class="form-group">
      <label class="form-label">Votre numéro de téléphone <span class="req">*</span></label>
      <input class="form-control" id="f_tel" type="tel" placeholder="06 12 34 56 78" value="${user.TelMobile||user.Tel||''}">${user.TelPro ? `<div style="font-size:10px;color:var(--gray-text);margin-top:2px">📞 Pro : ${escHtml(user.TelPro||'')}</div>` : ''}
    </div>

    <div class="form-group">
      <label class="form-label">${listLabelHtml('Bâtiment', batiments)}</label>
      ${batFieldHtml}
    </div>
    <div class="form-group">
      <label class="form-label">Bureau / Emplacement / Service</label>
      <input class="form-control" id="f_bureau" placeholder="Ex: Bureau 204" onblur="verifierDoublons()">
    </div>

    <!-- Panneau spécifique Archives -->
    <div id="panelArchive" class="form-group span-2" style="display:none">
      <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:16px">
        <div style="font-size:13px;font-weight:700;color:#15803d;margin-bottom:12px">🗄️ Informations d'archivage</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
          <div style="grid-column:span 2">
            <label class="form-label">Type de prestation <span class="req">*</span></label>
            <select class="form-control" id="f_prestaArchive" onchange="onPrestaArchiveChange()">
              <option value="">— Choisir —</option>
              <option value="Archivage">📦 Archivage (traitement de dossiers)</option>
              <option value="Desarchivage">🗂️ Désarchivage (sortir un ou plusieurs dossiers)</option>
            </select>
          </div>

          <!-- Archivage : service + nb + période -->
          <div id="panelArchivage_detail" style="grid-column:span 2;display:none">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
              <div>
                <label class="form-label">Nombre de dossiers estimé</label>
                <input class="form-control" type="number" id="f_nbDossiers" placeholder="Ex: 50" min="1">
              </div>
              <div>
                <label class="form-label">Service concerné</label>
                <input class="form-control" id="f_serviceArchive" placeholder="Direction des finances">
              </div>
              <div style="grid-column:span 2">
                <label class="form-label">Période couverte</label>
                <input class="form-control" id="f_periodeArchive" placeholder="Ex: 2018-2023">
              </div>
            </div>
          </div>

          <!-- Désarchivage : date de solde + liste N° dossiers -->
          <div id="panelDesarchivage_detail" style="grid-column:span 2;display:none">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
              <div>
                <label class="form-label">Date de solde <span class="req">*</span></label>
                <input class="form-control" type="date" id="f_dateSolde">
              </div>
              <div style="grid-column:span 2">
                <label class="form-label">N° de dossier(s) demandé(s) <span class="req">*</span></label>
                <div id="archDossiersList" style="display:flex;flex-direction:column;gap:6px;margin-bottom:6px">
                  <div class="arch-dossier-row" style="display:flex;gap:6px;align-items:center">
                    <input class="form-control arch-dossier-input" type="text" placeholder="Ex: DOS-2020-001" style="flex:1">
                    <button type="button" onclick="this.closest('.arch-dossier-row').remove()" style="background:none;border:none;color:#e74c3c;cursor:pointer;font-size:20px;padding:0;line-height:1;width:30px">×</button>
                  </div>
                </div>
                <button type="button" onclick="addArchDossierRow()" style="background:#e0f2fe;border:1px solid #7dd3fc;color:#0369a1;cursor:pointer;border-radius:5px;padding:4px 12px;font-size:12px">+ Ajouter un N° de dossier</button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="form-group span-2" id="bloc_urgence">
      <label class="form-label">Urgence</label>
      <select class="form-control" id="f_urgence">
        <option value="Basse">Basse</option>
        <option value="Normale" selected>Normale</option>
        <option value="Haute">Haute</option>
        <option value="Urgente">Urgente</option>
      </select>
    </div>

    <!-- Pour autrui — technique uniquement -->
    <div class="form-group span-2" id="bloc_pourAutrui">
      <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;font-weight:500">
        <input type="checkbox" id="f_pourAutrui" onchange="togglePourAutrui()">
        Je fais cette demande pour quelqu'un d'autre
      </label>
    </div>
    <div id="panelPourAutrui" style="display:none" class="form-group span-2">
      <div style="background:var(--gray-bg);border-radius:8px;padding:16px;display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div>
          <label class="form-label">Nom / Prénom de la personne <span class="req">*</span></label>
          <input class="form-control" id="f_nomAutrui" placeholder="Nom et prénom">
        </div>
        <div>
          <label class="form-label">Email de la personne <span class="req">*</span></label>
          <input class="form-control" id="f_emailAutrui" type="email" placeholder="email@exemple.fr">
        </div>
        <div style="grid-column:span 2">
          <label class="form-label">Téléphone de la personne <span class="req">*</span></label>
          <input class="form-control" id="f_telAutrui" type="tel" placeholder="06 12 34 56 78">
        </div>
      </div>
    </div>

    <!-- Photos -->
    <div class="form-group span-2">
      <label class="form-label">📷 Photos (max ${_demMaxPhotos})</label>
      <input type="file" id="f_photos" accept="image/*" multiple onchange="handlePhotosSelect(this)"
        style="font-size:13px">
      <div id="photosPreview" style="display:flex;flex-wrap:wrap;gap:8px;margin-top:8px"></div>
      <div style="font-size:11px;color:var(--gray-text);margin-top:4px">Formats acceptés : JPG, PNG, GIF — max ${_demMaxPhotos} photos</div>
    </div>
  </div>`, async () => {
    const typeLocalisation = document.getElementById('f_typeLocalisation')?.value || 'Technique';
    const isArchive = typeLocalisation === 'Archive';

    // Titre obligatoire seulement en Technique
    const titre = isArchive ? ('Demande archive — ' + (gv('f_prestaArchive') || 'archivage')) : gv('f_titre').trim();
    const email = gv('f_email').trim();
    const tel   = gv('f_tel').trim();
    if (!isArchive && !gv('f_titre').trim()) { toast('Le titre est obligatoire.', 'error'); return; }
    if (!email) { toast("L'adresse email est obligatoire.", 'error'); return; }
    if (!tel)   { toast('Le numéro de téléphone est obligatoire.', 'error'); return; }

    const pourAutrui = (!isArchive && document.getElementById('f_pourAutrui')?.checked) ? 1 : 0;
    if (pourAutrui) {
      if (!gv('f_nomAutrui').trim())   { toast("Le nom de la personne est obligatoire.", 'error'); return; }
      if (!gv('f_emailAutrui').trim()) { toast("L'email de la personne est obligatoire.", 'error'); return; }
      if (!gv('f_telAutrui').trim())   { toast("Le téléphone de la personne est obligatoire.", 'error'); return; }
    }

    // Validation champs Archives
    let archiveData = null;
    if (isArchive) {
      const prestaArchive = gv('f_prestaArchive');
      if (!prestaArchive) { toast("Le type de prestation d'archivage est obligatoire.", 'error'); return; }

      if (prestaArchive === 'Desarchivage') {
        const dateSolde = gv('f_dateSolde');
        if (!dateSolde) { toast("La date de solde est obligatoire pour un désarchivage.", 'error'); return; }
        const numDossiers = Array.from(document.querySelectorAll('.arch-dossier-input'))
          .map(el => el.value.trim()).filter(Boolean);
        if (numDossiers.length === 0) { toast("Saisissez au moins un N° de dossier.", 'error'); return; }
        archiveData = { prestaArchive, dateSolde, numerosDossiers: numDossiers };
      } else {
        archiveData = {
          prestaArchive,
          nbDossiers:     gv('f_nbDossiers'),
          serviceArchive: gv('f_serviceArchive'),
          periodeArchive: gv('f_periodeArchive'),
        };
      }
    }

    try {
      const result = await DemandesApi.create({
        titre, description:gv('f_description'), batiment:gv('f_batiment'), bureau:gv('f_bureau'),
        urgence: isArchive ? 'Normale' : gv('f_urgence'),
        emailDemandeur: email, telDemandeur: tel,
        pourAutrui,
        nomAutrui:   pourAutrui ? gv('f_nomAutrui')   : '',
        emailAutrui: pourAutrui ? gv('f_emailAutrui') : '',
        telAutrui:   pourAutrui ? gv('f_telAutrui')   : '',
        typeLocalisation,
        archiveData: archiveData ? JSON.stringify(archiveData) : null,
      });

      if (_demPendingPhotos.length > 0 && result.id) {
        for (const photo of _demPendingPhotos) {
          try { await DocumentsApi.upload('Demande', result.id, photo.name, photo.mime, 'photo', photo.data); }
          catch(pe) { console.warn('Photo upload error:', pe); }
        }
      }

      toast(isArchive ? 'Demande d\'archivage envoyée !' : 'Demande envoyée !', 'success');
      closeModal(); renderDemandes();
    } catch(e) { toast(e.message, 'error'); }
  }, 'Envoyer');
}

function togglePourAutrui() {
  const checked = document.getElementById('f_pourAutrui')?.checked;
  const panel = document.getElementById('panelPourAutrui');
  if (panel) panel.style.display = checked ? 'block' : 'none';
}

// ── Basculement onglet Technique / Archive dans la nouvelle demande ───────────
function switchTypeDemande(type) {
  document.getElementById('f_typeLocalisation').value = type;
  const isArchive = type === 'Archive';

  // Onglets visuels
  const tabT = document.getElementById('tab_technique');
  const tabA = document.getElementById('tab_archive');
  if (tabT) { tabT.style.background = isArchive ? 'transparent' : 'var(--blue)'; tabT.style.color = isArchive ? 'var(--gray-text)' : 'white'; tabT.style.fontWeight = isArchive ? '400' : '700'; }
  if (tabA) { tabA.style.background = isArchive ? '#16a34a' : 'transparent'; tabA.style.color = isArchive ? 'white' : 'var(--gray-text)'; tabA.style.fontWeight = isArchive ? '700' : '400'; }

  // Masquer/afficher le titre (Technique uniquement)
  const blocTitre = document.getElementById('bloc_titre');
  if (blocTitre) blocTitre.style.display = isArchive ? 'none' : '';

  // Panneau archive
  const panelArchive = document.getElementById('panelArchive');
  if (panelArchive) panelArchive.style.display = isArchive ? 'block' : 'none';

  // Urgence et Pour autrui — technique uniquement
  const blocUrgence = document.getElementById('bloc_urgence');
  const blocPourAutrui = document.getElementById('bloc_pourAutrui');
  const panelPourAutrui = document.getElementById('panelPourAutrui');
  if (blocUrgence) blocUrgence.style.display = isArchive ? 'none' : '';
  if (blocPourAutrui) blocPourAutrui.style.display = isArchive ? 'none' : '';
  if (panelPourAutrui && isArchive) panelPourAutrui.style.display = 'none';

  // Description placeholder
  const desc = document.getElementById('f_description');
  if (desc) desc.placeholder = isArchive ? 'Décrivez au mieux la recherche…' : 'Décrivez le problème…';
  const lblDesc = document.getElementById('lbl_description');
  if (lblDesc) lblDesc.textContent = isArchive ? 'Description de la recherche' : 'Description';
}

function onPrestaArchiveChange() {
  const val = document.getElementById('f_prestaArchive')?.value;
  const pArch = document.getElementById('panelArchivage_detail');
  const pDes  = document.getElementById('panelDesarchivage_detail');
  if (pArch) pArch.style.display = (val === 'Archivage') ? 'block' : 'none';
  if (pDes)  pDes.style.display  = (val === 'Desarchivage') ? 'block' : 'none';
}

function addArchDossierRow() {
  const container = document.getElementById('archDossiersList');
  if (!container) return;
  const div = document.createElement('div');
  div.className = 'arch-dossier-row';
  div.style.cssText = 'display:flex;gap:6px;align-items:center';
  div.innerHTML = '<input class="form-control arch-dossier-input" type="text" placeholder="Ex: DOS-2020-001" style="flex:1">'
    + '<button type="button" onclick="this.closest(\'.arch-dossier-row\').remove()" style="background:none;border:none;color:#e74c3c;cursor:pointer;font-size:20px;padding:0;line-height:1;width:30px">×</button>';
  container.appendChild(div);
  div.querySelector('input').focus();
}

function handlePhotosSelect(input) {
  const files = Array.from(input.files);
  const remaining = _demMaxPhotos - _demPendingPhotos.length;
  if (files.length > remaining) {
    toast(`Maximum ${_demMaxPhotos} photos. Vous pouvez encore en ajouter ${remaining}.`, 'error');
  }
  const toAdd = files.slice(0, remaining);
  toAdd.forEach(file => {
    const reader = new FileReader();
    reader.onload = () => {
      const base64 = reader.result.split(',')[1];
      _demPendingPhotos.push({ name: file.name, mime: file.type, data: base64 });
      renderPhotosPreview();
    };
    reader.readAsDataURL(file);
  });
  input.value = '';
}

function renderPhotosPreview() {
  const container = document.getElementById('photosPreview');
  if (!container) return;
  container.innerHTML = _demPendingPhotos.map((p, i) => `
    <div style="position:relative;width:80px;height:80px;border-radius:6px;overflow:hidden;border:1px solid var(--gray-border)">
      <img src="data:${p.mime};base64,${p.data}" style="width:100%;height:100%;object-fit:cover">
      <button onclick="_demPendingPhotos.splice(${i},1);renderPhotosPreview()"
        style="position:absolute;top:2px;right:2px;background:rgba(0,0,0,.6);color:white;border:none;border-radius:50%;width:20px;height:20px;font-size:12px;cursor:pointer;display:flex;align-items:center;justify-content:center">✕</button>
    </div>
  `).join('');
}

async function verifierDoublons() {
  const titre = document.getElementById('f_titre')?.value.trim()||'';
  const bat   = document.getElementById('f_batiment')?.value.trim()||'';
  const bur   = document.getElementById('f_bureau')?.value.trim()||'';
  const div   = document.getElementById('warnDoublons');
  if (!div||(!titre&&!bat&&!bur)) return;
  const doublons = await checkDoublons(bat, bur, titre);
  if (!doublons.length) { div.style.display='none'; return; }
  div.style.display = 'block';
  div.innerHTML = `<div style="background:#fff3cd;border:1px solid #ffc107;border-radius:8px;padding:12px 16px;font-size:13px">
    ⚠️ <strong>${doublons.length} demande(s) similaire(s) déjà ouverte(s) :</strong>
    <ul style="margin:8px 0 0 16px;padding:0">
      ${doublons.map(d=>`<li>${_escDem(d.Titre)} — ${badgeS(d.Statut)} ${d.Batiment?`🏢 ${_escDem(d.Batiment)}`:''}</li>`).join('')}
    </ul>
    <div style="margin-top:6px;color:#856404">Vous pouvez quand même soumettre si c'est différent.</div>
  </div>`;
}

// ── Traiter (Admin / Gestionnaire) ───────────────────────────────────────────────
async function traiterDemande(id) {
  try {
    const all = await DemandesApi.getAll();
    const d = all.find(x => x.Id === id);
    if (!d) return;

    // ── Demande Archive : interface spécifique ──────────────────────────────
    if (d.TypeLocalisation === 'Archive') {
      await traiterDemandeArchive(d);
      return;
    }

    // ── Demande Technique : interface standard ──────────────────────────────
    let categoriesOpts = '<option value=""></option>';
    let catObligatoire = false;
    let catSaisieLibre = false;
    try {
      const cats = await ListesApi.getByCategorie('CategorieDemande');
      if (cats.length > 0) {
        catObligatoire = cats.some(x => x.Obligatoire == 1);
        catSaisieLibre = cats.some(x => x.SaisieLibre == 1);
      }
      categoriesOpts += cats.map(c => `<option ${c.Valeur===(d.Categorie||'')?'selected':''}>${c.Valeur}</option>`).join('');
    } catch(_) {}

    const batiments = await ListesApi.getByCategorie('Batiment').catch(()=>[]);
    const rf = await getRequiredFields('demandes').catch(()=>[]);

    let docsHtml = '';
    try {
      const docs = await DocumentsApi.getAll('Demande', id);
      if (docs.length > 0) {
        docsHtml = `<div class="form-group span-2">
          <label class="form-label">📷 Photos jointes (${docs.length})</label>
          <div id="demandePhotos" style="display:flex;flex-wrap:wrap;gap:8px">
            ${docs.map(doc => `
              <div style="position:relative;width:100px;height:100px;border-radius:6px;overflow:hidden;border:1px solid var(--gray-border);display:flex;align-items:center;justify-content:center;background:var(--gray-bg);font-size:11px;color:var(--gray-text)">
                <div style="cursor:pointer;text-align:center;padding:4px" onclick="DocumentsApi.download(${doc.Id},'${(doc.NomFichier||'').replace(/'/g,"\\'")}')">
                  📷 ${(doc.NomFichier||'').substring(0,12)}
                </div>
                <button onclick="supprimerPhotoDemande(${doc.Id},${id})" style="position:absolute;top:2px;right:2px;background:#e74c3c;color:#fff;border:none;border-radius:50%;width:20px;height:20px;font-size:12px;cursor:pointer;line-height:1;display:flex;align-items:center;justify-content:center" title="Supprimer">×</button>
              </div>
            `).join('')}
          </div>
        </div>`;
      }
    } catch(_) {}

    const pourAutruiInfo = d.PourAutrui == 1 ? `
      <div class="form-group span-2">
        <div style="background:var(--gray-bg);border:1px solid var(--gray-border);border-radius:8px;padding:12px 16px;font-size:13px">
          👥 <strong>Demande faite pour un tiers :</strong><br>
          Nom : ${_escDem(d.NomAutrui||'—')} &nbsp;|&nbsp; Email : ${_escDem(d.EmailAutrui||'—')} &nbsp;|&nbsp; Tél : ${_escDem(d.TelAutrui||'—')}
        </div>
      </div>` : '';

    const contactInfo = `
      <div class="form-group span-2">
        <div style="background:var(--blue-pale,#e8f2fd);border:1px solid var(--blue,#2b7be6);border-radius:8px;padding:12px 16px;font-size:13px;color:var(--text)">
          👤 <strong>Contact demandeur :</strong> ${_escDem(d.NomDeclarant||'—')}<br>
          📧 ${_escDem(d.EmailDemandeur||'—')} &nbsp;|&nbsp; 📱 ${_escDem(d.TelDemandeur||'—')}
        </div>
      </div>`;

    openModal('Traiter la demande', `
    <div class="form-grid">
      ${contactInfo}
      ${pourAutruiInfo}
      ${docsHtml}
      <div class="form-group span-2">
        <label class="form-label">${reqLabel('Titre (modifiable)','Titre',rf)}</label>
        <input class="form-control" id="f_titre" value="${_escDem(d.Titre||'')}" ${reqAttr('Titre',rf)}>
      </div>
      <div class="form-group span-2">
        <label class="form-label">${reqLabel('Description (modifiable)','Description',rf)}</label>
        <textarea class="form-control" id="f_description" rows="2" ${reqAttr('Description',rf)}>${_escDem(d.Description||'')}</textarea>
      </div>
      <div class="form-group">
        <label class="form-label">Statut</label>
        <select class="form-control" id="f_statut">
          ${['Nouveau','En cours','Traité','Refusé'].map(s=>`<option ${s===d.Statut?'selected':''}>${s}</option>`).join('')}
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Priorité</label>
        <select class="form-control" id="f_urgence">
          ${['Basse','Normale','Haute','Urgente'].map(u=>`<option ${u===(d.Urgence||'Normale')?'selected':''}>${u}</option>`).join('')}
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Catégorie${catObligatoire ? ' <span class="req">*</span>' : ''}</label>
        ${catSaisieLibre
          ? `<div style="display:flex;gap:0">
               <input class="form-control" list="dl_categorie" id="f_categorie" value="${_escDem(d.Categorie||'')}" placeholder="Choisir ou saisir…" autocomplete="off">
               <datalist id="dl_categorie">${categoriesOpts}</datalist>
             </div>`
          : `<select class="form-control" id="f_categorie">${categoriesOpts}</select>`
        }
      </div>
      <div class="form-group">
        <label class="form-label">Délai de relance (jours, max 730)</label>
        <input class="form-control" type="number" id="f_delai" value="${d.DelaiRelanceJours||7}" min="1" max="730">
      </div>
      <div class="form-group span-2">
        <label class="form-label">${reqLabel('Bâtiment / Bureau','Batiment',rf)}</label>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
          ${batiments.length
            ? listFieldHtml('f_batiment', batiments, d.Batiment||'', {emptyOption:'—'})
            : `<input class="form-control" id="f_batiment" value="${_escDem(d.Batiment||'')}" placeholder="Bâtiment">`}
          <input class="form-control" id="f_bureau" value="${_escDem(d.Bureau||'')}" placeholder="Bureau">
        </div>
      </div>
      <div class="form-group span-2">
        <label class="form-label">Commentaire / Réponse</label>
        <textarea class="form-control" id="f_commentaire" rows="3">${escHtml(d.CommentaireAdmin||'')}</textarea>
      </div>
    </div>`, async () => {
      try {
        if (catObligatoire && !gv('f_categorie').trim()) {
          toast('La catégorie est obligatoire.', 'error'); return;
        }
        await DemandesApi.update(id, {
          statut: gv('f_statut'), commentaire: gv('f_commentaire'),
          urgence: gv('f_urgence'), delaiRelanceJours: Math.min(730, Math.max(1, parseInt(gv('f_delai'))||7)),
          categorie: gv('f_categorie'),
          titre: gv('f_titre'), description: gv('f_description'),
          batiment: gv('f_batiment'), bureau: gv('f_bureau'),
        });
        toast('Demande mise à jour.', 'success'); closeModal(); renderDemandes(); refreshBadgeDemandes?.();
      } catch(e) { toast(e.message, 'error'); }
    }, 'Enregistrer');
  } catch(e) { toast(e.message, 'error'); }
}

// ── Traiter une demande d'archive (interface spécifique) ──────────────────────
async function traiterDemandeArchive(d) {
  let archInfo = null;
  try { archInfo = d.ArchiveData ? JSON.parse(d.ArchiveData) : null; } catch(e) {}

  const prestaLabel = archInfo?.prestaArchive === 'Desarchivage'
    ? '🗂️ Désarchivage d\'un dossier'
    : '📦 Archivage (traitement de dossiers)';

  // Affichage des numéros de dossiers (nouveau format liste)
  const numsDossiers = Array.isArray(archInfo?.numerosDossiers) ? archInfo.numerosDossiers
    : (archInfo?.numeroDossierArch ? [archInfo.numeroDossierArch] : []);

  const detailsHtml = archInfo ? `
    <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:12px;font-size:13px">
      <div style="font-weight:700;color:#15803d;margin-bottom:8px">🗄️ ${prestaLabel}</div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px">
        ${archInfo.dateSolde    ? `<div><strong>Date de solde :</strong> ${new Date(archInfo.dateSolde).toLocaleDateString('fr-FR')}</div>` : ''}
        ${archInfo.nbDossiers   ? `<div><strong>Nb dossiers :</strong> ${archInfo.nbDossiers}</div>` : ''}
        ${archInfo.serviceArchive ? `<div><strong>Service :</strong> ${archInfo.serviceArchive}</div>` : ''}
        ${archInfo.periodeArchive ? `<div><strong>Période :</strong> ${archInfo.periodeArchive}</div>` : ''}
        ${numsDossiers.length > 0 ? `<div style="grid-column:span 2"><strong>N° dossier(s) :</strong> ${numsDossiers.map(n => `<span style="background:#dcfce7;padding:1px 7px;border-radius:4px;margin:0 3px">${n}</span>`).join(' ')}</div>` : ''}
      </div>
    </div>` : '';

  openModal('🗄️ Traiter la demande d\'archive', `
  <div style="display:flex;flex-direction:column;gap:14px">
    <div style="background:var(--blue-pale,#e8f2fd);border:1px solid var(--blue,#2b7be6);border-radius:8px;padding:12px;font-size:13px">
      👤 <strong>${_escDem(d.NomDeclarant||'—')}</strong><br>
      📧 ${_escDem(d.EmailDemandeur||'—')} &nbsp;|&nbsp; 📱 ${_escDem(d.TelDemandeur||'—')}
      ${d.Batiment ? `<br>🏢 ${_escDem(d.Batiment)}${d.Bureau ? ' • '+_escDem(d.Bureau) : ''}` : ''}
    </div>
    ${d.Description ? `<div style="font-size:13px"><strong>Description :</strong> ${_escDem(d.Description)}</div>` : ''}
    ${detailsHtml}

    <div>
      <label class="form-label" style="margin-bottom:8px">Mode de transmission <span class="req">*</span></label>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px" id="modeTransChoix">
        <label style="cursor:pointer;border:2px solid var(--gray-border);border-radius:10px;padding:14px;display:flex;flex-direction:column;align-items:center;gap:6px;text-align:center;transition:all .15s" id="lbl_mode_envoi"
          onclick="selectModeTransmission('Envoi')">
          <span style="font-size:24px">📤</span>
          <span style="font-weight:700;font-size:13px">Envoyer le fichier</span>
          <span style="font-size:11px;color:var(--gray-text)">Joindre un fichier à envoyer au demandeur</span>
          <input type="radio" name="f_modeTransmission" value="Envoi" style="display:none">
        </label>
        <label style="cursor:pointer;border:2px solid var(--gray-border);border-radius:10px;padding:14px;display:flex;flex-direction:column;align-items:center;gap:6px;text-align:center;transition:all .15s" id="lbl_mode_chercher"
          onclick="selectModeTransmission('VenirChercher')">
          <span style="font-size:24px">🚶</span>
          <span style="font-weight:700;font-size:13px">Venir chercher</span>
          <span style="font-size:11px;color:var(--gray-text)">Le demandeur se présente au service Archives</span>
          <input type="radio" name="f_modeTransmission" value="VenirChercher" style="display:none">
        </label>
      </div>
    </div>

    <div id="panelModeEnvoi" style="display:none">
      <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:12px">
        <div style="font-size:13px;font-weight:600;margin-bottom:8px">📎 Fichier à envoyer</div>
        <input type="file" id="f_fichierEnvoi" accept=".pdf,.doc,.docx,.xls,.xlsx,.odt,.jpg,.png"
          style="font-size:13px;padding:6px 10px;border:1px solid var(--gray-border);border-radius:6px;width:100%">
        <div style="font-size:11px;color:var(--gray-text);margin-top:4px">PDF, Word, Excel, image — max 20 Mo</div>
      </div>
    </div>
    <div id="panelModeChercher" style="display:none">
      <div style="background:#fefce8;border:1px solid #fde68a;border-radius:8px;padding:10px;font-size:13px">
        🚶 Le demandeur sera invité à se présenter au service Archives.
      </div>
    </div>

    <div>
      <label class="form-label">Statut de la demande</label>
      <select class="form-control" id="f_statut_arch">
        ${['En cours','Traité','Refusé'].map(s=>`<option ${s===(d.Statut||'')?'selected':''}>${s}</option>`).join('')}
      </select>
    </div>
    <div>
      <label class="form-label">Message / Commentaire pour le demandeur</label>
      <textarea class="form-control" id="f_commentaire_arch" rows="3" placeholder="Informations complémentaires pour le demandeur…">${_escDem(d.CommentaireAdmin||'')}</textarea>
    </div>
  </div>`, async () => {
    const mode = document.querySelector('input[name="f_modeTransmission"]:checked')?.value;
    if (!mode) { toast('Choisissez un mode de transmission.', 'error'); return; }

    // Fichier joint si mode Envoi
    let fichierJoint = null;
    if (mode === 'Envoi') {
      const fileEl = document.getElementById('f_fichierEnvoi');
      if (fileEl?.files?.length > 0) {
        const file = fileEl.files[0];
        if (file.size > 20 * 1024 * 1024) { toast('Fichier trop volumineux (max 20 Mo)', 'error'); return; }
        const b64 = await new Promise((res, rej) => {
          const r = new FileReader();
          r.onload = () => res(r.result.split(',')[1]);
          r.onerror = () => rej(new Error('Lecture fichier échouée'));
          r.readAsDataURL(file);
        });
        fichierJoint = { name: file.name, mime: file.type, data: b64 };
      }
    }

    let commentaire = gv('f_commentaire_arch');
    if (mode === 'VenirChercher') {
      commentaire = (commentaire ? commentaire + '\n\n' : '') + '🚶 Mode : Venir chercher au service Archives';
    } else if (fichierJoint) {
      commentaire = (commentaire ? commentaire + '\n\n' : '') + `📎 Fichier joint : ${fichierJoint.name}`;
    }

    await DemandesApi.update(d.Id, {
      statut: gv('f_statut_arch'),
      commentaire,
    });

    // Upload fichier si présent
    if (fichierJoint) {
      try {
        await DocumentsApi.upload('Demande', d.Id, fichierJoint.name, fichierJoint.mime, 'document', fichierJoint.data);
      } catch(fe) { console.warn('Fichier upload error:', fe); }
    }

    toast('Demande d\'archive traitée.', 'success');
    closeModal();
    // Rafraîchir l'onglet demandes de l'archives si ouvert
    if (typeof renderArchives === 'function' && ArchivesState?.onglet === 'demandes') renderArchives();
  }, 'Enregistrer');
}

function selectModeTransmission(mode) {
  ['Envoi','VenirChercher'].forEach(m => {
    const lbl = document.getElementById('lbl_mode_' + (m==='Envoi'?'envoi':'chercher'));
    if (lbl) {
      const active = m === mode;
      lbl.style.borderColor = active ? (m==='Envoi'?'var(--blue)':'#d97706') : 'var(--gray-border)';
      lbl.style.background  = active ? (m==='Envoi'?'#eff6ff':'#fefce8') : 'white';
      const radio = lbl.querySelector('input[type="radio"]');
      if (radio) radio.checked = active;
    }
  });
  document.getElementById('panelModeEnvoi').style.display    = mode === 'Envoi' ? 'block' : 'none';
  document.getElementById('panelModeChercher').style.display = mode === 'VenirChercher' ? 'block' : 'none';
}

// ── Relancer ────────────────────────────────────────────────────────────────────
async function relancerDemande(id) {
  try { await DemandesApi.relancer(id); toast('Relance envoyée.', 'success'); renderDemandes(); }
  catch(e) { toast(e.message, 'error'); }
}

// ── Détail clic ─────────────────────────────────────────────────────────────────
async function voirDetailDemande(id) {
  try {
    const all = await DemandesApi.getAll();
    const d   = all.find(x => x.Id === id);
    if (!d) return;
    const isManager = canEdit();
    // Vérifier si une intervention est liée
    let _detailIntervLiee = null;
    try {
      const intervs = await InterventionsApi.getAll();
      _detailIntervLiee = intervs.find(i => i.DemandeId === id) || null;
    } catch(_) {}

    // Charger photos
    let photosHtml = '';
    try {
      const docs = await DocumentsApi.getAll('Demande', id);
      if (docs.length > 0) {
        // Le demandeur peut supprimer ses photos quand la demande est traitée/refusée
        const canDeletePhoto = !isManager && ['Traité','Refusé'].includes(d.Statut);
        photosHtml = `<div>
          <strong>📷 Photos jointes (${docs.length}) :</strong>
          <div id="demandePhotosView" style="display:flex;flex-wrap:wrap;gap:8px;margin-top:8px">
            ${docs.map(doc => `
              <div style="position:relative;width:80px;height:80px;border-radius:6px;overflow:hidden;border:1px solid var(--gray-border);display:flex;align-items:center;justify-content:center;background:var(--gray-bg);font-size:10px;color:var(--gray-text)">
                <div style="cursor:pointer;text-align:center;padding:4px" onclick="DocumentsApi.download(${doc.Id},'${(doc.NomFichier||'').replace(/'/g,"\\'")}')">
                  📷 ${(doc.NomFichier||'').substring(0,10)}
                </div>
                ${canDeletePhoto || isManager ? `<button onclick="supprimerPhotoDemande(${doc.Id},${id})" style="position:absolute;top:1px;right:1px;background:#e74c3c;color:#fff;border:none;border-radius:50%;width:18px;height:18px;font-size:11px;cursor:pointer;line-height:1;display:flex;align-items:center;justify-content:center" title="Supprimer">×</button>` : ''}
              </div>
            `).join('')}
          </div>
          ${canDeletePhoto ? '<div style="font-size:10px;color:var(--gray-text);margin-top:4px">Vous pouvez supprimer vos photos maintenant que la demande est traitée.</div>' : ''}
        </div>`;
      }
    } catch(_) {}

    const pourAutruiHtml = d.PourAutrui == 1 ? `
      <div style="padding:12px;background:var(--gray-bg);border-radius:8px">
        <strong>👥 Demande pour un tiers :</strong><br>
        ${escHtml(d.NomAutrui||'—')} — 📧 ${escHtml(d.EmailAutrui||'—')} — 📱 ${escHtml(d.TelAutrui||'—')}
      </div>` : '';

    openModal(`📝 ${escHtml(d.Titre||'')}`, `
    <div style="display:flex;flex-direction:column;gap:14px;font-size:13px">
      <div style="display:flex;gap:8px;flex-wrap:wrap">${badgeU(d.Urgence)} ${badgeS(d.Statut)}
        ${d.PourAutrui == 1 ? '<span class="badge badge-purple">👥 Pour autrui</span>' : ''}
      </div>
      <div><strong>Description :</strong><br>${escHtml(d.Description||'<em style="color:var(--gray-text)">Aucune</em>')}</div>
      <div style="display:flex;gap:24px;flex-wrap:wrap">
        <div><strong>Bâtiment :</strong> ${escHtml(d.Batiment||'—')}</div>
        <div><strong>Bureau / Emplacement :</strong> ${escHtml(d.Bureau||'—')}</div>
        ${d.TypeLocalisation && d.TypeLocalisation !== 'Standard' ? `<div><strong>Type :</strong> <span class="badge badge-${d.TypeLocalisation==='Archive'?'teal':'purple'}">${d.TypeLocalisation==='Archive'?'🗄️ Archive':'🔧 Technique'}</span></div>` : ''}
      </div>
      ${(()=>{
        if (!d.ArchiveData) return '';
        try {
          const a = JSON.parse(d.ArchiveData);
          return `<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:12px">
            <div style="font-size:12px;font-weight:700;color:#15803d;margin-bottom:8px">🗄️ Détails archivage</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;font-size:13px">
              <div><strong>Prestation :</strong> ${a.prestaArchive||'—'}</div>
              <div><strong>Date de solde :</strong> ${a.dateSolde ? new Date(a.dateSolde).toLocaleDateString('fr-FR') : '—'}</div>
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
        } catch(e) { return ''; }
      })()}
      ${isManager ? `<div style="padding:12px;background:var(--blue-pale,#e8f2fd);border-radius:8px">
        <strong>👤 Déclarant :</strong> ${_escDem(d.NomDeclarant||'—')}<br>
        📧 ${_escDem(d.EmailDemandeur||'—')} &nbsp;|&nbsp; 📱 ${_escDem(d.TelDemandeur||'—')}
      </div>` : ''}
      ${pourAutruiHtml}
      <div><strong>Créée le :</strong> ${fmtDt(d.DateCreation)}</div>
      ${d.DateTraitement ? `<div><strong>Traitée le :</strong> ${fmtDt(d.DateTraitement)}</div>` : ''}
      ${_detailIntervLiee ? `<div style="padding:12px;background:var(--gray-bg);border-radius:8px;display:flex;align-items:center;gap:8px;flex-wrap:wrap">
        🔧 <strong>Intervention liée :</strong>
        <span style="font-family:monospace;font-weight:600">${escHtml(_detailIntervLiee.Numero||'')}</span>
        <span class="badge badge-${({Planifiée:'blue','En cours':'orange','Réalisée':'cyan',Validée:'teal'})[_detailIntervLiee.Statut]||'gray'}" style="font-size:10px">${escHtml(_detailIntervLiee.Statut === 'Validée' ? 'Terminée' : (_detailIntervLiee.Statut||''))}</span>
        <button class="btn btn-ghost btn-sm" style="margin-left:auto;font-size:11px" onclick="closeModal();editInterv(${_detailIntervLiee.Id})">Ouvrir →</button>
      </div>` : ''}
      ${d.DateDerniereRelance ? `<div><strong>Dernière relance :</strong> ${fmtDt(d.DateDerniereRelance)}</div>` : ''}
      ${d.CommentaireAdmin ? `<div style="padding:12px;background:var(--gray-bg);border-radius:8px"><strong>💬 Réponse :</strong><br>${escHtml(d.CommentaireAdmin||'')}</div>` : ''}
      ${photosHtml}
      <div style="display:flex;gap:8px">
        ${isManager && !['Traité','Refusé'].includes(d.Statut) ? `<button class="btn btn-primary btn-sm" onclick="closeModal();traiterDemande(${d.Id})">✏️ Traiter</button>` : ''}
        ${isManager && !_detailIntervLiee && d.TypeLocalisation !== 'Archive' ? `<button class="btn btn-ghost btn-sm" style="color:var(--teal);border-color:var(--teal)" onclick="closeModal();creerIntervDepuisDemande(${d.Id})">🔧 Créer intervention</button>` : ''}
        ${!isManager && peutRelancer(d) ? `<button class="btn btn-ghost btn-sm" style="color:var(--orange)" onclick="closeModal();relancerDemande(${d.Id})">🔔 Relancer</button>` : ''}
      </div>
    </div>`,
    null, 'Enregistrer', 'Fermer');
  } catch(e) { toast(e.message, 'error'); }
}

async function supprimerPhotoDemande(docId, demandeId) {
  showConfirm('Supprimer cette photo ?', async () => {
    try {
      await DocumentsApi.delete(docId);
      toast('Photo supprimée.', 'success');
      closeModal();
      setTimeout(() => voirDetailDemande(demandeId), 200);
    } catch(e) { toast(e.message, 'error'); }
  });
}

// ── Créer une intervention pré-remplie depuis une demande ────────────────────
async function creerIntervDepuisDemande(demandeId) {
  try {
    const all = await DemandesApi.getAll();
    const d = all.find(x => x.Id === demandeId);
    if (!d) { toast('Demande introuvable.', 'error'); return; }

    // Ouvrir le formulaire intervention avec les données pré-remplies
    // On passe par editInterv(0) puis on remplit les champs après ouverture
    await editInterv(0);

    // Remplir les champs depuis la demande
    setTimeout(() => {
      const selDemande = document.getElementById('f_demandeId');
      if (selDemande) {
        // Sélectionner la demande
        for (const opt of selDemande.options) {
          if (opt.value == demandeId) { opt.selected = true; break; }
        }
        // Déclencher l'import auto
        if (typeof importerDemande === 'function') importerDemande();
      }
    }, 200);
  } catch(e) { toast(e.message, 'error'); }
}
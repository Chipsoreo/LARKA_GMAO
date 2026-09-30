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
 * Larka — Page : Stock (pièces détachées et consommables)
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Gestion du stock avec alertes de seuil et vue parc consolidée.
 *
 * FONCTIONNALITÉS :
 *   - Articles groupés par catégorie (tableau)
 *   - Alerte visuelle quand quantité ≤ seuil
 *   - Source : Achat ou Contrat (lien vers un contrat fournisseur)
 *   - Vue Parc : agrégation des biens en état "Stock" par sous-famille
 *     avec drill-down vers le détail de chaque bien disponible
 *   - Documents joints par article
 *
 * POINTS D'ENTRÉE : renderStock(), editStock(id), voirBiensEnStock(sousFamille)
 * ═══════════════════════════════════════════════════════════════════════════════
 */

async function renderStock() {
  document.getElementById('topbarActions').innerHTML = '';
  const c = document.getElementById('mainContent');
  showLoading(c);
  try {
    const [data, categories, allBiens] = await Promise.all([
      StockApi.getAll(),
      ListesApi.getByCategorie('CategorieStock'),
      BiensApi.getAll().catch(()=>[]),
    ]);

    // ── Agrégation Biens en état "Stock" uniquement ──────────────
    const biensEnStock = allBiens.filter(b => b.Etat === 'Stock');
    const parcAgreg = {};
    biensEnStock.forEach(b => {
      const key = b.SousFamille || b.Famille || 'Autre';
      if (!parcAgreg[key]) parcAgreg[key] = { count:0, famille: b.Famille||'', biens:[], totalPrix:0 };
      parcAgreg[key].count++;
      parcAgreg[key].biens.push(b);
      parcAgreg[key].totalPrix += parseFloat(b.Prix)||0;
    });

    // Enrichissement affichage
    data.forEach(s => {
      s._label = [s.Marque, s.Modele, s.TypeArticle].filter(Boolean).join(' · ') || '—';
      s._valeur = ((s.Quantite||0) * (s.PrixUnitaire||0));
      s._alerte = s.Quantite <= s.SeuilAlerte;
    });

    const search = (App.searchTerm||'').toLowerCase();

    let filtered = data;
    filtered = filtered.filter(s => FiltresEngine.matchRow('stock', s));
    if (search) filtered = filtered.filter(s =>
      [s.Reference, s.Designation, s.Marque, s.Modele, s.TypeArticle, s.Categorie, s.Fournisseur, s.Emplacement]
        .some(v => (v||'').toLowerCase().includes(search))
    );
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

    const chips = ['Tous', '⚠ Alerte', ...categories.map(x=>x.Valeur)];
    const fmtM = v => new Intl.NumberFormat('fr-FR',{style:'currency',currency:'EUR'}).format(v||0);

    let html = `
    <div class="card" style="padding:0;margin-bottom:16px">
      <div class="search-bar" style="gap:8px">
        <div class="search-input-wrap" style="flex:1;max-width:480px">
          <span class="search-icon">🔍</span>
          <input class="search-input" type="text" placeholder="Rechercher dans le stock…"
            value="${App.searchTerm||''}" oninput="App.handleSearch(this.value)" onkeydown="App.handleSearchKey(event)">
        </div>
        ${FiltresEngine.renderButton('stock')}
        <select class="form-control" style="font-size:13px;width:auto;padding:6px 10px" onchange="(e=>{const[k,d]=e.target.value.split(':');App._sortKey=k||null;App._sortDir=d||'asc';App.renderCurrentPage()})(event)">
          <option value="">↕ Trier par…</option>
          <option value="Reference:asc" ${App._sortKey==='Reference'?'selected':''}>Référence A→Z</option>
          <option value="Designation:asc" ${App._sortKey==='Designation'?'selected':''}>Désignation A→Z</option>
          <option value="Categorie:asc" ${App._sortKey==='Categorie'?'selected':''}>Catégorie</option>
          <option value="Marque:asc" ${App._sortKey==='Marque'?'selected':''}>Marque</option>
          <option value="Quantite:asc" ${App._sortKey==='Quantite'&&App._sortDir==='asc'?'selected':''}>Quantité ↑</option>
          <option value="Quantite:desc" ${App._sortKey==='Quantite'&&App._sortDir==='desc'?'selected':''}>Quantité ↓</option>
          <option value="PrixUnitaire:desc" ${App._sortKey==='PrixUnitaire'?'selected':''}>Prix ↓</option>
          <option value="_valeur:desc" ${App._sortKey==="_valeur"?'selected':''}>Valeur stock ↓</option>
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
        ${canEdit() ? `<button class="btn btn-primary" onclick="editStock(0)">+ Nouvel article</button>` : ''}
      </div>
      ${FiltresEngine.renderPanel('stock')}
    </div>`;

    // ── Panneau Parc (biens en stock) ──────────────────────────
    if (Object.keys(parcAgreg).length > 0) {
      const fmtM = v => new Intl.NumberFormat('fr-FR',{style:'currency',currency:'EUR'}).format(v||0);
      html += `
      <div class="card" style="margin-bottom:16px">
        <div class="card-header" style="cursor:pointer" onclick="document.getElementById('parcDetail').style.display=document.getElementById('parcDetail').style.display==='none'?'block':'none';this.querySelector('.parc-arrow').textContent=document.getElementById('parcDetail').style.display==='none'?'▶':'▼'">
          <div class="card-title">📦 Biens en stock — Vue consolidée</div>
          <span style="display:flex;align-items:center;gap:8px">
            <span style="font-size:12px;color:var(--gray-text)">${biensEnStock.length} biens en stock</span>
            <span class="parc-arrow" style="font-size:11px">▶</span>
          </span>
        </div>
        <div id="parcDetail" style="display:none">
          <div class="table-wrap"><table><thead><tr>
            <th>Sous-famille</th><th>Famille</th><th style="text-align:center">Quantité</th>
            <th style="text-align:right">Valeur stock</th><th>Détails</th>
          </tr></thead><tbody>
          ${Object.entries(parcAgreg).sort((a,b)=>b[1].count-a[1].count).map(([sf, g]) => `
            <tr>
              <td style="font-weight:600">${sf}</td>
              <td style="font-size:12px;color:var(--gray-text)">${g.famille}</td>
              <td style="text-align:center;font-weight:700;color:var(--blue)">${g.count}</td>
              <td style="text-align:right;font-size:12px">${fmtM(g.totalPrix)}</td>
              <td><button class="btn btn-ghost btn-sm" style="font-size:11px" onclick="voirBiensEnStock('${sf.replace(/'/g,"\\'")}')">👁️ Voir</button></td>
            </tr>
          `).join('')}
          </tbody></table></div>
        </div>
      </div>`;
    }

    if (total === 0) {
      html += `<div class="card" style="padding:40px;text-align:center;color:var(--gray-text)">Aucun article trouvé.</div>`;
      c.innerHTML = html;
  App.restoreFilters(); return;
    }

    // Grouper par Catégorie pour une meilleure lisibilité
    const byCategorie = {};
    pageData.forEach(s => {
      const cat = s.Categorie || 'Sans catégorie';
      if (!byCategorie[cat]) byCategorie[cat] = [];
      byCategorie[cat].push(s);
    });

    Object.entries(byCategorie).forEach(([cat, items]) => {
      html += `
      <div class="card" style="margin-bottom:16px">
        <div class="card-header">
          <div class="card-title">📦 ${cat}</div>
          <span style="font-size:12px;color:var(--gray-text)">${items.length} article${items.length>1?'s':''}</span>
        </div>
        <div class="table-wrap"><table><thead><tr>
          <th>Référence</th><th>Désignation</th><th>Marque / Modèle / Type</th>
          <th>Qté</th><th>Seuil</th><th>Prix unit.</th><th>Valeur stock</th>
          <th>Emplacement</th><th>Source</th><th>Actions</th>
        </tr></thead><tbody>`;

      items.forEach(s => {
        const alertStyle = s._alerte ? 'color:var(--red);font-weight:700' : '';
        html += `<tr ${s._alerte?'style="background:#fff5f5"':''}>
          <td style="font-family:monospace;font-size:12px">${escHtml(s.Reference||'')}</td>
          <td style="font-weight:500">${escHtml(s.Designation||'')}</td>
          <td style="font-size:12px;color:var(--gray-text)">${s._label}</td>
          <td style="${alertStyle}">${s.Quantite} ${s._alerte?'⚠':''}</td>
          <td style="color:var(--gray-text)">${s.SeuilAlerte}</td>
          <td>${fmtM(s.PrixUnitaire)}</td>
          <td style="color:var(--blue)">${fmtM(s._valeur)}</td>
          <td style="font-size:12px;color:var(--gray-text)">${escHtml(s.Emplacement||'—')}</td>
          <td><span class="badge badge-${s.Source==='Contrat'?'teal':'blue'}">${s.Source||'Achat'}</span></td>
          <td><div class="actions-col">
            ${canEdit()?`<button class="icon-btn" onclick="editStock(${s.Id})" title="Modifier">✏️</button>`:''}
            ${canDelete()?`<button class="icon-btn delete" onclick="deleteStock(${s.Id})" title="Supprimer">🗑️</button>`:''}
          </div></td>
        </tr>`;
      });

      html += `</tbody></table></div></div>`;
    });

    // Résumé valeur totale
    const totalValeur = filtered.reduce((s,a) => s + a._valeur, 0);
    const canPrev = page > 1;
    const canNext = page < totalPages;
    const prevStyle = canPrev ? '' : 'opacity:.45;pointer-events:none;';
    const nextStyle = canNext ? '' : 'opacity:.45;pointer-events:none;';
    html += `<div class="pagination" style="border:0;padding:10px 0;justify-content:space-between">
      <span>${total} article${total>1?'s':''} — Affichage ${from}-${to} • Valeur totale filtrée : <strong style="color:var(--blue)">${fmtM(totalValeur)}</strong></span>
      ${totalPages>1 ? `
        <div style="display:flex;align-items:center;gap:8px">
          <button class="btn btn-ghost btn-sm" style="${prevStyle}" onclick="App.setPage(${page-1})">←</button>
          <span style="font-size:12px;color:var(--gray-text)">Page ${page} / ${totalPages}</span>
          <button class="btn btn-ghost btn-sm" style="${nextStyle}" onclick="App.setPage(${page+1})">→</button>
        </div>` : ''}
    </div>`;

    c.innerHTML = html;
  App.restoreFilters();
  } catch(e) { c.innerHTML = errorHtml(e.message); }
}

async function editStock(id) {
  const isNew = !id;
  const [s, categories, contrats, _rf] = await Promise.all([
    isNew ? Promise.resolve({}) : StockApi.getAll().then(d => d.find(x=>x.Id===id)||{}),
    ListesApi.getByCategorie('CategorieStock'),
    ContratsApi.getAll(),
    getRequiredFields('stock'),
  ]);
  const rf = _rf || [];

  const opts = (liste, val, extra='') => extra + liste.map(x=>`<option ${val===x.Valeur?'selected':''}>${x.Valeur}</option>`).join('');
  const optsContrats = `<option value="">— Aucun —</option>` +
    contrats.map(c=>`<option value="${c.Id}" ${s.ContratId===c.Id?'selected':''}>${escHtml(c.Numero||'')} — ${escHtml(c.Societe||'')}</option>`).join('');

  openModal(isNew?'Nouvel article stock':`Modifier — ${escHtml(s.Reference||'')}`, `
  <div style="display:flex;flex-direction:column;gap:16px">

    <div>
      <div class="section-label">Identification</div>
      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">${reqLabel('Référence','Reference',rf)}</label>
          <input class="form-control" id="f_reference" value="${escHtml(s.Reference||'')}" placeholder="Ex: LUM-001" ${reqAttr('Reference',rf)}>
        </div>
        <div class="form-group">
          <label class="form-label">${reqLabel('Catégorie','Categorie',rf)}</label>
          <select class="form-control" id="f_categorie">${opts(categories, s.Categorie)}</select>
        </div>
        <div class="form-group span-2">
          <label class="form-label">${reqLabel('Désignation','Designation',rf)}</label>
          <input class="form-control" id="f_designation" value="${escHtml(s.Designation||'')}" placeholder="Ex: Ampoule LED E27 10W" ${reqAttr('Designation',rf)}>
        </div>
        <div class="form-group">
          <label class="form-label">Marque</label>
          <input class="form-control" id="f_marque" value="${escHtml(s.Marque||'')}" placeholder="Ex: Philips">
        </div>
        <div class="form-group">
          <label class="form-label">Modèle</label>
          <input class="form-control" id="f_modele" value="${escHtml(s.Modele||'')}" placeholder="Ex: CorePro">
        </div>
        <div class="form-group span-2">
          <label class="form-label">Type d'article</label>
          <input class="form-control" id="f_typeArticle" value="${escHtml(s.TypeArticle||'')}" placeholder="Ex: LED, Néon, Halogène, Spot...">
          <div style="font-size:11px;color:var(--gray-text);margin-top:3px">Précise le sous-type dans la même référence (ex: même douille mais technologie différente)</div>
        </div>
      </div>
    </div>

    <div>
      <div class="section-label">Stock & Prix</div>
      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Quantité</label>
          <input class="form-control" type="number" id="f_quantite" value="${s.Quantite||0}" min="0">
        </div>
        <div class="form-group">
          <label class="form-label">Seuil d'alerte</label>
          <input class="form-control" type="number" id="f_seuil" value="${s.SeuilAlerte||0}" min="0">
        </div>
        <div class="form-group">
          <label class="form-label">Prix unitaire (€)</label>
          <input class="form-control" type="number" step="0.01" id="f_prix" value="${s.PrixUnitaire||0}" min="0">
        </div>
        <div class="form-group">
          <label class="form-label">Emplacement</label>
          <input class="form-control" id="f_emplacement" value="${escHtml(s.Emplacement||'')}" placeholder="Ex: Armoire A, Étagère 2">
        </div>
        <div class="form-group">
          <label class="form-label">Fournisseur</label>
          <input class="form-control" id="f_fournisseur" value="${escHtml(s.Fournisseur||'')}" placeholder="Ex: Rexel, Prolux">
        </div>
      </div>
    </div>

    <div>
      <div class="section-label">Approvisionnement</div>
      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Source</label>
          <select class="form-control" id="f_source" onchange="toggleContratStock()">
            <option value="Achat"   ${(s.Source||'Achat')==='Achat'  ?'selected':''}>Achat (budget propre)</option>
            <option value="Contrat" ${s.Source==='Contrat'?'selected':''}>Fourni par contrat</option>
          </select>
        </div>
        <div class="form-group" id="grp_contratId" style="${s.Source==='Contrat'?'':'display:none'}">
          <label class="form-label">Contrat associé</label>
          <select class="form-control" id="f_contratId">${optsContrats}</select>
        </div>
      </div>
    </div>
    <div>
      <div class="section-label">Documents joints</div>
      ${isNew ? docsPanelHtml('Stock', 0, 'Stock') : docsPanelHtml('Stock', id)}
    </div>
  </div>`, async () => {
    const reference  = gv('f_reference').trim();
    const designation = gv('f_designation').trim();
    if (!reference||!designation) { toast('Référence et désignation requises.', 'error'); return; }
    const payload = {
      reference, designation,
      categorie:   gv('f_categorie'),
      marque:      gv('f_marque'),
      modele:      gv('f_modele'),
      typeArticle: gv('f_typeArticle'),
      quantite:    parseInt(gv('f_quantite'))||0,
      seuilAlerte: parseInt(gv('f_seuil'))||0,
      prixUnitaire:parseFloat(gv('f_prix'))||0,
      emplacement: gv('f_emplacement'),
      fournisseur: gv('f_fournisseur'),
      source:      gv('f_source'),
      contratId:   gv('f_contratId') || null,
    };
    try {
      if (isNew) {
        const result = await StockApi.create(payload);
        const newId = result?.id || result;
        if (newId) await uploadPendingDocs('Stock', 'Stock', newId);
      }
      else       await StockApi.update(id, payload);
      toast(isNew?'Article créé.':'Article modifié.', 'success');
      closeModal(); renderStock();
    } catch(e) { toast(e.message, 'error'); }
  });

  setTimeout(() => {
    if (isNew) { window._docsPending['Stock'] = []; }
    initDocsPanel('Stock', id, 'Stock');
  }, 150);
}

function toggleContratStock() {
  const src = document.getElementById('f_source')?.value;
  const grp = document.getElementById('grp_contratId');
  if (grp) grp.style.display = src === 'Contrat' ? '' : 'none';
}

async function deleteStock(id) {
  showConfirm('Supprimer cet article du stock ?', async () => {
    try { await StockApi.delete(id); toast('Article supprimé.'); renderStock(); }
    catch(e) { toast(e.message, 'error'); }
  });
}

// ═══════════════════════════════════════════════════════════════
//  DRILL-DOWN : Biens en stock d'une sous-famille
// ═══════════════════════════════════════════════════════════════
async function voirBiensEnStock(sousFamille) {
  try {
    const allBiens = await BiensApi.getAll();
    const biens = allBiens.filter(b => b.Etat === 'Stock' && (b.SousFamille||b.Famille||'Autre') === sousFamille);
    const fmtM = v => new Intl.NumberFormat('fr-FR',{style:'currency',currency:'EUR'}).format(v||0);

    let body = `
    <div style="margin-bottom:12px;display:flex;gap:12px;flex-wrap:wrap">
      <div style="background:var(--blue-pale);padding:8px 14px;border-radius:8px;font-size:13px">
        <strong style="color:var(--blue)">${biens.length}</strong> disponible${biens.length>1?'s':''}
      </div>
      <div style="background:#ecfdf5;padding:8px 14px;border-radius:8px;font-size:13px">
        Valeur : <strong style="color:#0e8a6e">${fmtM(biens.reduce((s,b)=>s+(parseFloat(b.Prix)||0),0))}</strong>
      </div>
    </div>
    <div class="table-wrap"><table><thead><tr>
      <th>Numéro</th><th>Info produit</th><th>N° série</th><th>Bâtiment</th><th>Emplacement</th><th>Prix</th><th>Date livraison</th>
    </tr></thead><tbody>`;

    if (!biens.length) {
      body += `<tr><td colspan="7" style="text-align:center;color:var(--gray-text);padding:20px">Aucun bien en stock pour cette famille.</td></tr>`;
    } else {
      biens.forEach(b => {
        body += `<tr>
          <td style="font-family:monospace;font-size:12px">${escHtml(b.Numero||'—')}</td>
          <td style="font-weight:500">${escHtml(b.InfoProduit||'—')}</td>
          <td style="font-size:12px;color:var(--gray-text)">${escHtml(b.NumeroSerie||'—')}</td>
          <td style="font-size:12px">${escHtml(b.Batiment||'—')}</td>
          <td style="font-size:12px">${[b.Etage?'Ét.'+b.Etage:'', b.NumeroBureau?'Bur.'+b.NumeroBureau:''].filter(Boolean).join(' • ')||'—'}</td>
          <td style="font-size:12px">${fmtM(b.Prix)}</td>
          <td style="font-size:12px;color:var(--gray-text)">${b.DateLivraison||'—'}</td>
        </tr>`;
      });
    }

    body += `</tbody></table></div>`;

    openModal(`📦 En stock — ${sousFamille}`, body, null, null, 'Fermer', '850px');
  } catch(e) { toast(e.message, 'error'); }
}

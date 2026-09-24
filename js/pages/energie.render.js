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
 * Larka — Module Énergie : Rendu principal
 * Page de suivi énergétique (compteurs, relevés, graphiques, alertes).
 * POINT D'ENTRÉE : renderEnergie()
 */
// ── Render principal ─────────────────────────────────────────────────────────
async function renderEnergie() {
  document.getElementById('topbarActions').innerHTML = '';
  const c = document.getElementById('mainContent');
  showLoading(c);
  try {
    await _renderEnergieOnglet(c);
  } catch(e) { c.innerHTML = errorHtml(e.message); }
}

async function _renderEnergieOnglet(c) {
  const type   = EnergieState.onglet;

  // ── Onglet standard ──

  const cfg    = ENERGIE_CONFIG[type];
  const search = (App.searchTerm||'').toLowerCase();

  const [compteurs, contrats] = await Promise.all([
    EnergieApi.getCompteurs(),
    ContratsApi.getAll(),
  ]);
  window._energieCompteurs = compteurs; // Accessible pour le calcul CO₂ chauffage

  const mesCompteurs = compteurs.filter(x => x.Type === type);

  let html = `
  <!-- Onglets fluides -->
  <div class="card" style="padding:0;margin-bottom:16px">
    <div style="display:flex;align-items:center;padding:0 20px;border-bottom:1px solid var(--gray-border)">
      ${Object.entries(ENERGIE_CONFIG).map(([k,v]) => `
        <div onclick="switchEnergieOnglet('${k}')"
          style="padding:14px 20px;cursor:pointer;font-size:14px;font-weight:500;
            border-bottom:3px solid ${type===k?'var(--blue)':'transparent'};
            color:${type===k?'var(--blue)':'var(--gray-text)'};
            transition:all .15s">
          ${v.icon} ${k}
        </div>`).join('')}
      <div style="flex:1"></div>
      ${canEdit() ? `<button class="btn btn-primary btn-sm" onclick="editCompteur(0,'${type}')">+ Compteur</button>` : ''}
    </div>
  </div>`;

  if (!mesCompteurs.length) {
    html += `<div class="card" style="padding:48px;text-align:center;color:var(--gray-text)">
      Aucun compteur ${cfg.icon} ${type}.<br>
      ${canEdit() ? `<button class="btn btn-primary" style="margin-top:16px" onclick="editCompteur(0,'${type}')">+ Ajouter un compteur</button>` : ''}
    </div>`;
    c.innerHTML = html;
  App.restoreFilters();
    return;
  }

// Barre recherche + filtres avancés (FiltresEngine facturation + Stats)
  html += `
  <div class="card" style="padding:0;margin-bottom:16px">
    <!-- Barre principale -->
    <div style="display:flex;align-items:center;gap:10px;padding:12px 16px">
      <div class="search-input-wrap">
        <span class="search-icon">🔍</span>
        <input class="search-input" type="text" placeholder="Rechercher (N° facture, fournisseur, commentaire…)"
          value="${App.searchTerm||''}" oninput="App.handleSearch(this.value)" onkeydown="App.handleSearchKey(event)">
      </div>
      ${renderEnergieFilterButtons()}

      <div style="flex:1"></div>
      ${canEdit() ? `<button class="btn btn-primary" onclick="editReleve(0,null,'${type}')">+ Saisie</button>` : ''}
    </div>

    ${renderEnergieFilterPanels()}

  </div>`;

  // Stats globales du fluide
  // ── Graphique stats — utilise TOUS les relevés (non filtrés) ──
  html += await _renderEnergieStats(type, mesCompteurs, cfg);

  // ── Un bloc par compteur — séparé en 2 sous-blocs (Relevés / Factures) ──────
  for (const cpt of mesCompteurs) {
    let tousReleves = await EnergieApi.getRelevers(cpt.Id);
    const fa = App.getAdvancedFilters();
    const fmtMon = v => new Intl.NumberFormat('fr-FR',{style:'currency',currency:'EUR'}).format(v||0);
    const fmtNum = v => new Intl.NumberFormat('fr-FR',{maximumFractionDigits:1}).format(v||0);
    const fmtDate = d => d ? new Date(d).toLocaleDateString('fr-FR') : '—';

    // ── Filtres via FiltresEngine (remplace App.getAdvancedFilters pour facturation) ──
    const applyFilters = (liste) => _applyEnergieFilters(liste);

    // TypeSaisie : lu depuis FiltresEngine
    const feTypeSaisie = (() => {
      const v = FiltresEngine.getState('energie_facture').TypeSaisie || '';
      return v === 'Tous' ? '' : v;
    })();

    // Séparer AVANT filtre TypeSaisie (on gère les 2 côtés indépendamment)
    const tousManuels  = tousReleves.filter(r => r.Type !== 'Facture');
    const tousFactures = tousReleves.filter(r => r.Type === 'Facture');

    // Appliquer les filtres sur chaque côté
    const manuels  = applyFilters(feTypeSaisie === 'Facture' ? [] : tousManuels);
    const factures = applyFilters(feTypeSaisie === 'Releve'  ? [] : tousFactures);

    // ── Agrégats par côté ──
    const consoManuels  = manuels.reduce((s,r)  => s+(parseFloat(r.Consommation)||0), 0);
    const consoFactures = factures.reduce((s,r) => s+(parseFloat(r.Consommation)||0), 0);
    const coutManuels   = manuels.reduce((s,r)  => s+(parseFloat(r.Montant)||0), 0);
    const coutFactures  = factures.reduce((s,r) => s+(parseFloat(r.Montant)||0), 0);
    const moyManuels    = manuels.length  ? consoManuels  / manuels.length  : 0;
    const moyFactures   = factures.length ? consoFactures / factures.length : 0;
    const ecartConso    = consoManuels - consoFactures;
    const ecartCol      = (consoManuels > 0 && Math.abs(ecartConso) < consoManuels * 0.03) ? '#27ae60' : 'var(--orange)';

    const contrat = contrats.find(x => x.Id === cpt.ContratId);

    // Multi-compteurs (sous-compteurs)
    let scCount = 0;
    let scList = [];
    try {
      scList = JSON.parse(cpt.SousCompteurs || '[]');
      if (!Array.isArray(scList)) scList = [];
      scCount = scList.length;
    } catch(e) { scList = []; scCount = 0; }
    const isMulti = scCount >= 2;
    const iconHtml = isMulti
      ? `<span style="font-size:18px;letter-spacing:-6px">${cfg.icon}${cfg.icon}${cfg.icon}</span>`
      : `<span style="font-size:20px">${cfg.icon}</span>`;
    const multiBadge = isMulti ? `<span style="font-size:11px;background:#eef2ff;color:#4338ca;border:1px solid #c7d2fe;padding:2px 8px;border-radius:999px">${scCount} compteurs</span>` : '';
    const multiSub = isMulti ? `<div style="font-size:11px;color:var(--gray-text);margin-top:2px">${scList.map(x=>x?.numero).filter(Boolean).slice(0,4).join(', ')}${scCount>4?'…':''}</div>` : '';

    // ── Helper : rendu d'un tableau de lignes ──
    const renderTableau = (liste, isFactureSide) => {
      // Parse repartition for this compteur
      let repartPresta = [];
      try { repartPresta = cpt.RepartitionPresta ? JSON.parse(cpt.RepartitionPresta) : []; } catch(e) {}

      const defaultSaisie = isFactureSide ? 'Facture' : 'Releve';
      if (!liste.length) return `<tr><td colspan="8" class="no-results">
        Aucune saisie. <a href="#" onclick="editReleve(0,${cpt.Id},'${type}','${defaultSaisie}');return false">+ Ajouter</a>
      </td></tr>`;
      return liste.map(r => {
        // Build repartition sub-rows for factures
        let repartHtml = '';
        if (isFactureSide && repartPresta.length > 0 && r.Montant) {
          const colors = ['#3b82f6','#9b59b6','#16a34a','#f59e0b','#ef4444','#0ea5e9'];
          repartHtml = `<tr><td colspan="8" style="padding:4px 8px 8px 20px;background:#fffbeb;border-bottom:1px solid #fde68a">
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
              <span style="font-size:10px;font-weight:600;color:#92400e">💰 Répartition :</span>
              ${repartPresta.map((rp,idx) => {
                const col = colors[idx % colors.length];
                const partMontant = (parseFloat(r.Montant)||0) * (rp.pourcentage / 100);
                const displayName = rp.nom?.split(' — ').pop()?.trim() || rp.nom || '?';
                return `<span style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;background:white;border:1px solid ${col};border-radius:12px;font-size:10px">
                  <span style="color:${col};font-weight:600">${displayName}</span>
                  <span style="color:var(--gray-text)">${rp.pourcentage}%</span>
                  <span style="color:${col};font-weight:700">${fmtMon(partMontant)}</span>
                </span>`;
              }).join('')}
            </div>
          </td></tr>`;
        }

        // Sous-compteurs : lignes de détail
        let sousHtml = '';
        if (r.DetailSousCompteurs) {
          try {
            const arr = JSON.parse(r.DetailSousCompteurs);
            if (Array.isArray(arr) && arr.length) {
              const cols56 = isFactureSide ? `<td></td><td></td>` : `<td colspan="2"></td>`;
              sousHtml = arr.map(sc => {
                const nom = sc?.nom || sc?.Nom || 'Sous-compteur';
                const numero = sc?.numero || sc?.Numero || '';
                const d0 = sc?.idxDebut ?? sc?.IndexDebut;
                const d1 = sc?.idxFin ?? sc?.IndexFin;
                const c  = sc?.conso ?? sc?.Consommation;
                return `
                  <tr style="background:#f8fafc">
                    <td style="padding-left:18px;font-size:11px;color:var(--gray-text)">↳ ${nom}${numero ? ' • N° '+numero : ''}</td>
                    <td style="font-family:monospace;font-size:11px;color:var(--gray-text)">${d0!=null?fmtNum(d0):'—'}</td>
                    <td style="font-family:monospace;font-size:11px;color:var(--gray-text)">${d1!=null?fmtNum(d1):'—'}</td>
                    <td style="font-size:11px;color:var(--gray-text)">${c!=null?fmtNum(c)+' '+cfg.unite:'—'}</td>
                    <td></td>
                    ${cols56}
                    <td></td>
                  </tr>`;
              }).join('');
            }
          } catch(e) {}
        }

        return `<tr>
        <td style="font-weight:500">${fmtDate(r.Date)}</td>
        <td style="font-family:monospace;font-size:12px">${r.IndexDebut!=null?fmtNum(r.IndexDebut):'—'}</td>
        <td style="font-family:monospace;font-size:12px">${r.IndexFin!=null?fmtNum(r.IndexFin):'—'}</td>
        <td style="font-weight:600;color:var(--blue)">${r.Consommation!=null?fmtNum(r.Consommation)+' '+cfg.unite:'—'}</td>
        <td style="color:var(--orange);font-weight:600">${r.Montant?fmtMon(r.Montant):'—'}${isFactureSide && repartPresta.length > 0 && r.Montant ? '<span style="font-size:9px;color:#92400e;margin-left:3px" title="Répartie entre '+repartPresta.length+' prestataires">💰</span>' : ''}</td>
        ${isFactureSide ? `
          <td style="font-family:monospace;font-size:12px">${escHtml(r.NumeroFacture||'—')}</td>
          <td style="font-size:12px">${r.PeriodeDebut&&r.PeriodeFin ? fmtDate(r.PeriodeDebut)+' → '+fmtDate(r.PeriodeFin) : '—'}</td>
        ` : `
          <td colspan="2" style="font-size:12px;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--gray-text)">${escHtml(r.Commentaire||'—')}</td>
        `}
        <td><div class="actions-col">
          ${canEdit()?`<button class="icon-btn" onclick="editReleve(${r.Id},${cpt.Id},'${type}')" title="Modifier">✏️</button>`:''}
          ${canDelete()?`<button class="icon-btn delete" onclick="deleteReleve(${r.Id},${cpt.Id},'${type}')" title="Supprimer">🗑️</button>`:''}
        </div></td>
      </tr>${sousHtml}${repartHtml}`;
      }).join('');
    };

    html += `
    <div class="card" style="margin-bottom:16px">

      <!-- En-tête compteur -->
      <div class="card-header">
        <div style="display:flex;align-items:center;gap:10px">
          ${iconHtml}
          <div>
            <div class="card-title" style="margin:0">${escHtml(cpt.Nom||'')}</div>
            <div style="font-size:11px;color:var(--gray-text)">${escHtml(cpt.NumeroCompteur||'')}${cpt.Site?' — '+cpt.Site:''}</div>
          </div>
          ${contrat ? `<span class="badge badge-blue" style="margin-left:8px">📋 ${escHtml(contrat.Societe||'')}</span>` : ''}
          ${!cpt.Actif ? `<span class="badge badge-gray">Inactif</span>` : ''}
          ${(() => {
            let rep = [];
            try { rep = cpt.RepartitionPresta ? JSON.parse(cpt.RepartitionPresta) : []; } catch(e) {}
            if (!rep.length) return '';
            return `<span class="badge" style="margin-left:8px;background:#fef3c7;color:#92400e;border:1px solid #fbbf24;font-size:10px;padding:3px 8px;border-radius:12px;cursor:pointer"
              onclick="event.stopPropagation();toggleRepartitionDetail(${cpt.Id})" title="Cliquer pour voir le détail">
              💰 Répartition : ${rep.map(r => r.nom?.split(' — ').pop()?.trim() || r.nom || '?').join(', ')}
            </span>`;
          })()}
        </div>
        <div style="display:flex;gap:6px;align-items:center">
          <div style="display:flex;gap:2px;margin-right:8px" title="Période affichée dans les tableaux">
            ${[['12','1 an'],['24','2 ans'],['all','Tout']].map(([v,lbl]) => {
              const a = String(EnergieTablePeriod.months) === v;
              return `<button onclick="switchEnergieTablePeriod('${v}')" style="padding:3px 8px;border:1px solid ${a?'var(--blue)':'var(--gray-border)'};background:${a?'var(--blue)':'white'};color:${a?'white':'var(--gray-text)'};border-radius:4px;cursor:pointer;font-size:11px;font-weight:${a?'600':'400'}">${lbl}</button>`;
            }).join('')}
          </div>
          ${canEdit() ? `
            <button class="btn btn-ghost btn-sm" onclick="editReleve(0,${cpt.Id},'${type}')">+ Saisie</button>
            <button class="icon-btn" onclick="editCompteur(${cpt.Id},'${type}')" title="Modifier compteur">✏️</button>
            <button class="icon-btn delete" onclick="deleteCompteur(${cpt.Id})" title="Supprimer compteur">🗑️</button>
          ` : ''}
        </div>
      </div>

      <!-- Bande récap : écart entre les 2 côtés -->
      ${(manuels.length > 0 && factures.length > 0) ? `
      <div style="padding:10px 20px;background:#f8f5ff;border-bottom:1px solid var(--gray-border);display:flex;gap:20px;align-items:center;flex-wrap:wrap;justify-content:center">
        <span style="font-size:11px;font-weight:700;color:#7c3aed">⚖️ Comparaison relevé / facture</span>
        <span style="font-size:12px">Conso relevée : <strong style="color:var(--blue)">${fmtNum(consoManuels)} ${cfg.unite}</strong></span>
        <span style="font-size:12px">Estimé relevés : <strong style="color:var(--orange)">${fmtMon(coutManuels)}</strong></span>
        <span style="font-size:12px">Conso facturée : <strong style="color:#9b59b6">${fmtNum(consoFactures)} ${cfg.unite}</strong></span>
        <span style="font-size:12px">Coût facturé : <strong style="color:var(--orange)">${fmtMon(coutFactures)}</strong></span>
        <span style="font-size:12px">Écart conso :
          <strong style="color:${ecartCol}">${ecartConso>=0?'+':''}${fmtNum(ecartConso)} ${cfg.unite}</strong>
        </span>
      </div>` : ''}

      <!-- Répartition prestataires (si configurée) -->
      ${(() => {
        let rep = [];
        try { rep = cpt.RepartitionPresta ? JSON.parse(cpt.RepartitionPresta) : []; } catch(e) {}
        if (!rep.length) return '';
        const fmtMon = v => new Intl.NumberFormat('fr-FR',{style:'currency',currency:'EUR'}).format(v||0);
        const totalFacture = factures.reduce((s,r) => s+(parseFloat(r.Montant)||0), 0);
        return `
        <div id="repartDetail_${cpt.Id}" style="display:none;padding:12px 20px;background:#fffbeb;border-bottom:1px solid #fbbf24">
          <div style="font-size:11px;font-weight:700;color:#92400e;margin-bottom:8px">💰 Répartition des factures entre prestataires</div>
          <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:stretch">
            ${rep.map((r,i) => {
              const colors = ['#3b82f6','#9b59b6','#16a34a','#f59e0b','#ef4444','#0ea5e9'];
              const col = colors[i % colors.length];
              const partMontant = totalFacture * (r.pourcentage / 100);
              const displayName = r.nom?.split(' — ').pop()?.trim() || r.nom || 'Prestataire';
              return `<div style="flex:1;min-width:160px;background:white;border:2px solid ${col};border-radius:8px;padding:10px 14px;text-align:center">
                <div style="font-size:12px;font-weight:600;color:${col};margin-bottom:4px">${displayName}</div>
                <div style="font-size:22px;font-weight:800;color:${col}">${r.pourcentage}%</div>
                ${totalFacture > 0 ? `<div style="font-size:11px;color:var(--gray-text);margin-top:4px">≈ ${fmtMon(partMontant)} sur ${fmtMon(totalFacture)}</div>` : ''}
                <div style="width:100%;height:6px;background:#f1f5f9;border-radius:3px;margin-top:6px">
                  <div style="width:${r.pourcentage}%;height:100%;background:${col};border-radius:3px"></div>
                </div>
              </div>`;
            }).join('')}
          </div>
        </div>`;
      })()}

      <!-- 2 colonnes côte à côte -->
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(400px,1fr));gap:0;border-bottom:1px solid var(--gray-border)">

        <!-- ── COLONNE GAUCHE : Relevés manuels ── -->
        <div style="border-right:1px solid var(--gray-border)">
          <div style="padding:10px 16px;background:#eff8ff;border-bottom:1px solid var(--gray-border);display:flex;align-items:center;justify-content:space-between">
            <div style="display:flex;align-items:center;gap:8px">
              <span style="display:inline-block;width:10px;height:10px;background:var(--blue);border-radius:50%"></span>
              <span style="font-size:12px;font-weight:700;color:var(--blue)">Relevés manuels</span>
              <span style="font-size:11px;color:var(--gray-text)">(${manuels.length} saisie${manuels.length>1?'s':''})</span>
            </div>
            <div style="display:flex;gap:16px;font-size:11px">
              <span>Conso : <strong style="color:var(--blue)">${fmtNum(consoManuels)} ${cfg.unite}</strong></span>
              ${coutManuels>0?`<span>Estimé : <strong style="color:var(--orange)">${fmtMon(coutManuels)}</strong></span>`:''}
              ${manuels.length>1?`<span>Moy : <strong>${fmtNum(moyManuels)} ${cfg.unite}</strong></span>`:''}
            </div>
          </div>
          <div class="table-wrap" style="overflow:auto;max-width:100%"><table style="min-width:500px"><thead><tr>
            <th>Date</th><th>Idx début</th><th>Idx fin</th>
            <th>Conso (${cfg.unite})</th><th>Montant</th><th colspan="2">Commentaire</th><th>Actions</th>
          </tr></thead><tbody>
            ${renderTableau(manuels, false)}
          </tbody></table></div>
        </div>

        <!-- ── COLONNE DROITE : Factures ── -->
        <div>
          <div style="padding:10px 16px;background:#fdf4ff;border-bottom:1px solid var(--gray-border);display:flex;align-items:center;justify-content:space-between">
            <div style="display:flex;align-items:center;gap:8px">
              <span style="display:inline-block;width:10px;height:10px;background:#9b59b6;border-radius:50%"></span>
              <span style="font-size:12px;font-weight:700;color:#9b59b6">Factures</span>
              <span style="font-size:11px;color:var(--gray-text)">(${factures.length} saisie${factures.length>1?'s':''})</span>
            </div>
            <div style="display:flex;gap:16px;font-size:11px">
              <span>Conso : <strong style="color:#9b59b6">${fmtNum(consoFactures)} ${cfg.unite}</strong></span>
              ${coutFactures>0?`<span>Coût : <strong style="color:var(--orange)">${fmtMon(coutFactures)}</strong></span>`:''}
              ${factures.length>1?`<span>Moy : <strong>${fmtNum(moyFactures)} ${cfg.unite}</strong></span>`:''}
            </div>
          </div>
          <div class="table-wrap" style="overflow:auto;max-width:100%"><table style="min-width:550px"><thead><tr>
            <th>Date</th><th>Idx début</th><th>Idx fin</th>
            <th>Conso (${cfg.unite})</th><th>Montant</th><th>N° Facture</th><th>Période</th><th>Actions</th>
          </tr></thead><tbody>
            ${renderTableau(factures, true)}
          </tbody></table></div>
        </div>

      </div>
    </div>`;
  }

  c.innerHTML = html;
  App.restoreFilters();
}

// ── Toggle répartition detail panel ──────────────────────────────────────────
function toggleRepartitionDetail(compteurId) {
  const el = document.getElementById('repartDetail_' + compteurId);
  if (!el) return;
  el.style.display = el.style.display === 'none' ? 'block' : 'none';
}

// ── Tooltip info ─────────────────────────────────────────────────────────────
function infoTooltip(texte) {
  const id = 'tt_' + Math.random().toString(36).slice(2,7);
  return `<span class="info-tooltip" tabindex="0"
    style="display:inline-flex;align-items:center;justify-content:center;
      width:16px;height:16px;border-radius:50%;background:#3b82f6;color:white;
      font-size:10px;font-weight:700;cursor:pointer;margin-left:5px;flex-shrink:0;
      position:relative;vertical-align:middle;user-select:none"
    onmouseenter="showTooltip(event,'${id}')"
    onmouseleave="hideTooltip('${id}')"
    onfocus="showTooltip(event,'${id}')"
    onblur="hideTooltip('${id}')">ⓘ
    <div id="${id}" role="tooltip"
      style="display:none;position:fixed;z-index:9999;max-width:340px;
        background:#1e293b;color:#f8fafc;font-size:12px;line-height:1.6;
        padding:10px 14px;border-radius:8px;box-shadow:0 4px 20px rgba(0,0,0,0.35);
        pointer-events:none;white-space:pre-wrap">${texte}</div>
  </span>`;
}
function showTooltip(e, id) {
  const el = document.getElementById(id);
  if (!el) return;
  el.style.display = 'block';
  const r = e.currentTarget.getBoundingClientRect();
  let top = r.bottom + 8, left = r.left;
  if (top + 150 > window.innerHeight) top = r.top - 150;
  if (left + 340 > window.innerWidth) left = window.innerWidth - 350;
  el.style.top  = top  + 'px';
  el.style.left = left + 'px';
}
function hideTooltip(id) {
  const el = document.getElementById(id);
  if (el) el.style.display = 'none';
}
const EnergieTablePeriod = { months: 12 }; // default 12 mois pour les tableaux de relevés

const EnergieStatsState = {
  graphType: 'barres',    // barres | ligne | area | comparaison
  metrique: 'conso',      // conso | cout | prixUnit
  periode: '12',         // all | 12 | 6 | 3
  showReleve: true,
  showFacture: true,
  kpiVisible: {
    kpi_conso:    true,
    kpi_cout:     true,
    kpi_contrat:  true,
    kpi_tendance: true,
    kpi_extremes: false,
    kpi_carbone:  true,
  },
};

function toggleKpiGroupe(id) {
  EnergieStatsState.kpiVisible = EnergieStatsState.kpiVisible || {};
  const defaults = { kpi_conso:true, kpi_cout:true, kpi_contrat:true, kpi_tendance:true, kpi_extremes:false, kpi_carbone:true };
  const current  = EnergieStatsState.kpiVisible[id] ?? defaults[id] ?? true;
  EnergieStatsState.kpiVisible[id] = !current;

  // Toggle le bloc
  const bloc = document.getElementById(id);
  if (bloc) bloc.style.display = EnergieStatsState.kpiVisible[id] ? 'flex' : 'none';

  // Toggle le bouton
  const btn = document.getElementById('kpiBtn_' + id);
  if (btn) {
    const on = EnergieStatsState.kpiVisible[id];
    btn.style.background   = on ? 'var(--blue)' : 'white';
    btn.style.color        = on ? 'white' : 'var(--gray-text)';
    btn.style.borderColor  = on ? 'var(--blue)' : 'var(--gray-border)';
    btn.style.fontWeight   = on ? '600' : '400';
  }
}

function switchEnergieGraph(graphType) {
  EnergieStatsState.graphType = graphType;
  _refreshEnergieStatsOnly();
}
function switchEnergieMetrique(metrique) {
  EnergieStatsState.metrique = metrique;
  _refreshEnergieStatsOnly();
}
function switchEnergiePeriode(periode) {
  EnergieStatsState.periode = periode;
  _refreshEnergieStatsOnly();
}

// ── État indépendant du graphe CO₂ ──────────────────────────────────────────
const CarboneGraphState = {
  periode:   '12',     // '3' | '6' | '12' | 'all'
  graphType: 'barres', // 'barres' | 'ligne'
  showReleve:  true,   // afficher les relevés manuels dans le graphe CO₂
  showFacture: true,   // afficher les factures dans le graphe CO₂
};

function switchCarbonePeriode(p) {
  CarboneGraphState.periode = p;
  _refreshEnergieStatsOnly();
}
function switchCarboneGraphType(t) {
  CarboneGraphState.graphType = t;
  _refreshEnergieStatsOnly();
}
function switchCarboneShow(serie) {
  CarboneGraphState[serie] = !CarboneGraphState[serie];
  // Au moins une série doit rester active
  if (!CarboneGraphState.showReleve && !CarboneGraphState.showFacture) {
    CarboneGraphState[serie] = true;
  }
  _refreshEnergieStatsOnly();
}

function switchEnergieTablePeriod(months) {
  EnergieTablePeriod.months = months;
  App.renderCurrentPage();
}

async function _refreshEnergieStatsOnly() {
  const wrapper = document.getElementById('energieStatsWrapper');
  if (!wrapper) return;

  const type = EnergieState?.onglet;
  const cfg  = ENERGIE_CONFIG[type];
  if (!cfg) return;

  const compteurs = (await EnergieApi.getCompteurs()).filter(x => x.Type === type);
  const html = await _renderEnergieStats(type, compteurs, cfg);
  if (!html) return;

  // Remplace le wrapper entier (outerHTML) — innerHTML créerait un wrapper niché
  wrapper.outerHTML = html.trim();

  // Re-rendre les panneaux filtres ouverts pour que leurs boutons reflètent l'état actuel
  ['energie_graph','energie_stats'].forEach(p => {
    const el = document.getElementById('filtresPanel_' + p);
    if (el) el.outerHTML = FiltresEngine.renderPanel(p);
  });
}


async function _renderEnergieStats(type, compteurs, cfg) {
  const tousReleves = [];
  for (const cpt of compteurs) {
    const r = await EnergieApi.getRelevers(cpt.Id);
    r.forEach(x => { x._compteurNom = cpt.Nom; tousReleves.push(x); });
  }
  if (!tousReleves.length) return '';

  const fmtMon = v => new Intl.NumberFormat('fr-FR',{style:'currency',currency:'EUR'}).format(v||0);
  const fmtNum = v => new Intl.NumberFormat('fr-FR',{maximumFractionDigits:1}).format(v||0);

  // ── Plage de mois : basée sur les données réelles ─────────────────────────
  const datesReleves = tousReleves.map(r => r.Date?.substring(0,7)).filter(Boolean).sort();
  const premierMoisStr = datesReleves[0];
  const dernierMoisStr = datesReleves[datesReleves.length - 1];

  // "maintenant" = le plus tard entre aujourd'hui et le dernier relevé (données futures incluses)
  const aujourdhui = new Date();
  const dernierReleve = dernierMoisStr
    ? new Date(parseInt(dernierMoisStr.substring(0,4)), parseInt(dernierMoisStr.substring(5,7))-1, 1)
    : aujourdhui;
  const maintenant = dernierReleve > aujourdhui ? dernierReleve : aujourdhui;

  const premierMois = premierMoisStr
    ? new Date(parseInt(premierMoisStr.substring(0,4)), parseInt(premierMoisStr.substring(5,7))-1, 1)
    : new Date(maintenant.getFullYear(), maintenant.getMonth() - 11, 1);

  const periodeN = EnergieStatsState.periode === 'all' ? 9999 : parseInt(EnergieStatsState.periode);

  // Pour "Tout" : du premier au dernier relevé
  // Pour "12 mois" : année complète Jan–Déc centrée sur la dernière donnée
  // Pour "N mois" : les N derniers mois en remontant depuis le dernier relevé
  let moisDebut;
  if (periodeN === 9999) {
    moisDebut = new Date(premierMois);
  } else if (periodeN === 12) {
    // Année civile complète (Jan–Déc) de la dernière année avec des données
    const annee = maintenant.getFullYear();
    moisDebut = new Date(annee, 0, 1); // 1er janvier
  } else {
    moisDebut = new Date(maintenant.getFullYear(), maintenant.getMonth() - periodeN + 1, 1);
  }

  const tousLesMois = [];
  const d0 = new Date(premierMois);
  while (d0 <= maintenant) {
    const key = `${d0.getFullYear()}-${String(d0.getMonth()+1).padStart(2,'0')}`;
    tousLesMois.push({ key, label: d0.toLocaleDateString('fr-FR',{month:'short',year:'2-digit'}) });
    d0.setMonth(d0.getMonth() + 1);
  }

  const mois = [];
  const moisFin = (periodeN === 12)
    ? new Date(moisDebut.getFullYear(), 11, 1) // Jusqu'en décembre
    : maintenant;
  const dM = new Date(moisDebut < premierMois && periodeN !== 12 ? premierMois : moisDebut);
  while (dM <= moisFin) {
    const key = `${dM.getFullYear()}-${String(dM.getMonth()+1).padStart(2,'0')}`;
    mois.push({ key, label: dM.toLocaleDateString('fr-FR',{month:'short',year:'2-digit'}) });
    dM.setMonth(dM.getMonth() + 1);
  }

  // Séparer relevés manuels et factures (selon filtres stats)
  const showR = EnergieStatsState.showReleve !== false;
  const showF = EnergieStatsState.showFacture !== false;
  const releves  = tousReleves.filter(r => r.Type !== 'Facture' ? showR : false);
  const factures = tousReleves.filter(r => r.Type === 'Facture' ? showF : false);

  // Agréger PAR MOIS — initialisé sur tousLesMois pour la tendance, mois pour le graphique
  const parMoisReleves  = {};
  const parMoisFactures = {};
  const parMois         = {};
  // Initialiser sur tous les mois connus (pour tendance et calculs globaux)
  tousLesMois.forEach(m => {
    parMoisReleves[m.key]  = { conso:0, cout:0, nb:0 };
    parMoisFactures[m.key] = { conso:0, cout:0, nb:0 };
    parMois[m.key]         = { conso:0, cout:0, nb:0 };
  });
  // Aussi initialiser les mois de la plage sélectionnée (peuvent déborder de tousLesMois)
  mois.forEach(m => {
    if (!parMoisReleves[m.key])  parMoisReleves[m.key]  = { conso:0, cout:0, nb:0 };
    if (!parMoisFactures[m.key]) parMoisFactures[m.key] = { conso:0, cout:0, nb:0 };
    if (!parMois[m.key])         parMois[m.key]         = { conso:0, cout:0, nb:0 };
  });
  releves.forEach(r => {
    const k = r.Date?.substring(0,7);
    if (parMoisReleves[k]) {
      parMoisReleves[k].conso += parseFloat(r.Consommation)||0;
      parMoisReleves[k].cout  += parseFloat(r.Montant)||0;
      parMoisReleves[k].nb++;
      parMois[k].conso += parseFloat(r.Consommation)||0;
      parMois[k].cout  += parseFloat(r.Montant)||0;
      parMois[k].nb++;
    }
  });
  factures.forEach(r => {
    const k = r.Date?.substring(0,7);
    if (parMoisFactures[k]) {
      parMoisFactures[k].conso += parseFloat(r.Consommation)||0;
      parMoisFactures[k].cout  += parseFloat(r.Montant)||0;
      parMoisFactures[k].nb++;
      parMois[k].conso += parseFloat(r.Consommation)||0;
      parMois[k].cout  += parseFloat(r.Montant)||0;
      parMois[k].nb++;
    }
  });

  // KPIs globaux (tous types confondus)
  const totalConsoReleves  = releves.reduce((s,r)  => s+(parseFloat(r.Consommation)||0), 0);
  const totalCoutReleves   = releves.reduce((s,r)  => s+(parseFloat(r.Montant)||0), 0);
  const totalConsoFactures = factures.reduce((s,r) => s+(parseFloat(r.Consommation)||0), 0);
  const totalCoutFactures  = factures.reduce((s,r) => s+(parseFloat(r.Montant)||0), 0);
  const totalConso = totalConsoReleves + totalConsoFactures;
  const totalCout  = totalCoutReleves  + totalCoutFactures;
  const hasReleves  = releves.length > 0;
  const hasFactures = factures.length > 0;
  const hasBoth     = hasReleves && hasFactures;

  // ── Carbone ─────────────────────────────────────────────────────────────────
  // Calcule TOUJOURS dynamiquement avec la méthode active.
  // Le champ r.Carbone en BDD est un cache du dernier "Recalculer CO₂" — on ne l'utilise
  // que si aucun facteur n'est disponible pour la méthode active.
  const _methodeActive = typeof getMethodeCarbone === 'function' ? getMethodeCarbone() : 'officielle';
  const _getCarbone = (r) => {
    const conso = parseFloat(r.Consommation);
    if (!conso || conso <= 0) return 0;
    const rType = r._type || type;
    // Calcul dynamique depuis facteur de la méthode active
    // Pour le chauffage : si l'utilisateur a choisi "réseau spécifique", utiliser le coeff réseau
    if (rType === 'Chauffage' && typeof BilanCarboneState !== 'undefined' && BilanCarboneState.chauffSource === 'reseau' && BilanCarboneState.coeffCO2Chauffage > 0) {
      return conso * BilanCarboneState.coeffCO2Chauffage * 1000;
    }
    const def = _methodeActive === 'simplifiee'
      ? (typeof IMPACT_CO2_FACTEURS !== 'undefined' ? IMPACT_CO2_FACTEURS[rType] : null)
      : ADEME_FACTEURS[rType];
    if (def) {
      if (rType === 'Chauffage') return conso * def.facteurCO2 * 1000;
      return conso * def.facteurCO2;
    }
    // Fallback sur la valeur stockée en BDD (ancien calcul)
    const stored = parseFloat(r.Carbone);
    if (!isNaN(stored) && stored > 0) return stored;
    return 0;
  };
  const totalCarboneReleves  = releves.reduce((s,r)  => s+_getCarbone(r), 0);
  const totalCarboneFactures = factures.reduce((s,r) => s+_getCarbone(r), 0);
  const totalCarbone = totalCarboneReleves + totalCarboneFactures;
  const hasCarbone   = tousReleves.some(r => _getCarbone(r) > 0);
  // Accumulation séparée relevé / facture pour le graphe CO₂ différencié
  const parMoisCarbone        = {};  // combiné (pour badge tendance)
  const parMoisCarboneReleve  = {};  // relevés manuels seulement
  const parMoisCarboneFacture = {};  // factures seulement
  tousLesMois.forEach(m => {
    parMoisCarbone[m.key] = 0;
    parMoisCarboneReleve[m.key] = 0;
    parMoisCarboneFacture[m.key] = 0;
  });
  mois.forEach(m => {
    if (!parMoisCarbone[m.key])        parMoisCarbone[m.key]        = 0;
    if (!parMoisCarboneReleve[m.key])  parMoisCarboneReleve[m.key]  = 0;
    if (!parMoisCarboneFacture[m.key]) parMoisCarboneFacture[m.key] = 0;
  });
  tousReleves.forEach(r => {
    const k = r.Date?.substring(0,7);
    const v = _getCarbone(r);
    if (parMoisCarbone[k] !== undefined) {
      parMoisCarbone[k] += v;
      if (r.Type === 'Facture') parMoisCarboneFacture[k] += v;
      else                      parMoisCarboneReleve[k]  += v;
    }
  });

  // Prix contractuel : depuis le compteur ou son contrat lié
  let prixContractuel = null;
  let abonnementContractuel = null;
  const contrats = await ContratsApi.getAll();
  for (const cpt of compteurs) {
    const px = parseFloat(cpt.PrixHT) || 0;
    const abo = parseFloat(cpt.Abonnement) || 0;
    if (px > 0) { prixContractuel = px; abonnementContractuel = abo; break; }
    if (!px && cpt.ContratId) {
      const ct = contrats.find(x => x.Id === parseInt(cpt.ContratId));
      if (ct && parseFloat(ct.PrixBaseHT) > 0) {
        prixContractuel = parseFloat(ct.PrixBaseHT);
        abonnementContractuel = abonnementContractuel || parseFloat(ct.AbonnementHT) || 0;
        break;
      }
    }
  }

  // Coût moyen constaté / kWh (hors abonnement estimé)
  const nbMoisReleves = tousLesMois.length || 1;
  const totalAboEstime = abonnementContractuel != null ? abonnementContractuel * nbMoisReleves : 0;
  const coutConsoSeul  = totalCout - totalAboEstime;
  const prixMoyenConstate = totalConso > 0 ? totalCout / totalConso : 0;
  const prixMoyenConsoSeul = (totalConso > 0 && prixContractuel != null)
    ? coutConsoSeul / totalConso : null;
  const nbMoisAvecDonnees = Object.values(parMois).filter(x => x.cout > 0 || x.conso > 0).length || 1;
  const moyenneMensuelle  = totalConso / tousLesMois.length;
  const moyenneCoutMens   = totalCout  / tousLesMois.length;

  // Tendance : comparaison entre les 2 derniers mois ayant des données
  const moisAvecDonnees = tousLesMois.filter(m => {
    const d = parMoisReleves[m.key] || {conso:0};
    const f = parMoisFactures[m.key] || {conso:0};
    return d.conso > 0 || f.conso > 0;
  });
  const dernierMoisData   = moisAvecDonnees.length >= 1 ? parMois[moisAvecDonnees[moisAvecDonnees.length-1].key] : null;
  const avantDernierData  = moisAvecDonnees.length >= 2 ? parMois[moisAvecDonnees[moisAvecDonnees.length-2].key] : null;
  const tendanceConso = (dernierMoisData && avantDernierData && avantDernierData.conso > 0)
    ? ((dernierMoisData.conso - avantDernierData.conso) / avantDernierData.conso * 100).toFixed(1)
    : null;

  // ── Agrégats séparés relevés / factures ──────────────────────────────────────
  const nbMoisAvecReleves  = mois.filter(m => parMoisReleves[m.key].conso  > 0).length || 1;
  const nbMoisAvecFactures = mois.filter(m => parMoisFactures[m.key].conso > 0).length || 1;
  const moyMensReleves     = totalConsoReleves  / (tousLesMois.length || 1);
  const moyMensFactures    = totalConsoFactures / (tousLesMois.length || 1);
  const moyMensCoutReleves  = totalCoutReleves  / (tousLesMois.length || 1);
  const moyMensCoutFactures = totalCoutFactures / (tousLesMois.length || 1);
  const prixMoyenReleves   = totalConsoReleves  > 0 ? totalCoutReleves  / totalConsoReleves  : 0;
  const prixMoyenFactures  = totalConsoFactures > 0 ? totalCoutFactures / totalConsoFactures : 0;

  // Meilleur / pire mois
  const moisValides = mois.filter(m => parMois[m.key].conso > 0);
  const meilleurMois = moisValides.reduce((best, m) => (!best || parMois[m.key].conso < parMois[best.key].conso) ? m : best, null);
  const pireMois     = moisValides.reduce((worst, m) => (!worst || parMois[m.key].conso > parMois[worst.key].conso) ? m : worst, null);

  // ── Graphique selon le type sélectionné ────────────────────────────────────
  const metrique = EnergieStatsState.metrique;
  const graphType = EnergieStatsState.graphType;

  // Valeurs du graphique basées uniquement sur ce qui est affiché (showR/showF)
  const empty = {conso:0, cout:0, nb:0};
  const getVal = (d) => { if (!d) return 0; return metrique==='cout' ? d.cout : metrique==='prixUnit' ? (d.conso>0?d.cout/d.conso:0) : d.conso; };
  const valsR  = mois.map(m => showR ? getVal(parMoisReleves[m.key]  || empty) : 0);
  const valsF  = mois.map(m => showF ? getVal(parMoisFactures[m.key] || empty) : 0);
  const valsCombined = mois.map((_,i) => valsR[i] + valsF[i]);

  const labelMetrique = metrique === 'cout' ? 'Coût (€)' : metrique === 'prixUnit' ? `Prix unitaire (€/${cfg.unite})` : `Consommation (${cfg.unite})`;
  const couleur = metrique === 'cout' ? 'var(--orange)' : metrique === 'prixUnit' ? 'var(--teal)' : 'var(--blue)';
  const fmtVal  = v => metrique === 'conso' ? fmtNum(v)+' '+cfg.unite : fmtMon(v);

  let graphHtml = '';

  if (graphType === 'barres') {
    const mxAll = Math.max(...valsR, ...valsF, 1);
    if (showR && showF) {
      // Barres groupées côte à côte
      graphHtml = `
        <div style="display:flex;align-items:center;gap:16px;margin-bottom:8px;font-size:11px">
          <span><span style="display:inline-block;width:12px;height:12px;background:var(--blue);border-radius:2px;margin-right:4px;vertical-align:middle"></span>Relevés</span>
          <span><span style="display:inline-block;width:12px;height:12px;background:#9b59b6;border-radius:2px;margin-right:4px;vertical-align:middle"></span>Factures</span>
        </div>
        <div style="display:flex;gap:3px;align-items:flex-end;height:130px;padding:0 4px">` +
        mois.map((m,i) => {
          const vr=valsR[i], vf=valsF[i];
          const hr=mxAll>0?Math.round((vr/mxAll)*110):0;
          const hf=mxAll>0?Math.round((vf/mxAll)*110):0;
          return `<div style="display:flex;flex-direction:column;align-items:center;gap:3px;flex:1;min-width:0">
            <div style="display:flex;gap:1px;align-items:flex-end;height:110px;width:100%">
              <div style="flex:1;height:${hr}px;background:var(--blue);border-radius:3px 3px 0 0;opacity:0.85;min-height:${vr>0?1:0}px" title="Relevé ${m.label}: ${fmtVal(vr)}"></div>
              <div style="flex:1;height:${hf}px;background:#9b59b6;border-radius:3px 3px 0 0;opacity:0.85;min-height:${vf>0?1:0}px" title="Facture ${m.label}: ${fmtVal(vf)}"></div>
            </div>
            <div style="font-size:9px;color:var(--gray-text);text-align:center;white-space:nowrap">${m.label}</div>
          </div>`;
        }).join('') + `</div>`;
    } else {
      // Barres simples (un seul type visible)
      const vals = showR ? valsR : valsF;
      const col  = showR ? 'var(--blue)' : '#9b59b6';
      const mx   = Math.max(...vals, 1);
      graphHtml = `<div style="display:flex;gap:4px;align-items:flex-end;height:130px;padding:0 4px">` +
        mois.map((m,i) => {
          const v=vals[i], h=mx>0?Math.round((v/mx)*110):0;
          return `<div style="display:flex;flex-direction:column;align-items:center;gap:3px;flex:1;min-width:0">
            <div style="font-size:9px;color:var(--gray-text);text-align:center;white-space:nowrap;overflow:hidden;max-width:100%">${v>0?fmtVal(v):''}</div>
            <div style="width:100%;background:var(--gray-border);border-radius:3px;height:110px;display:flex;align-items:flex-end">
              <div style="width:100%;height:${h}px;background:${col};border-radius:3px 3px 0 0;opacity:0.85" title="${m.label}: ${fmtVal(v)}"></div>
            </div>
            <div style="font-size:9px;color:var(--gray-text);text-align:center;white-space:nowrap">${m.label}</div>
          </div>`;
        }).join('') + `</div>`;
    }

  } else if (graphType === 'ligne') {
    const W=800, H=120, pad=10;
    const xStep=(W-pad*2)/Math.max(mois.length-1,1);
    const mkPts = (vals) => vals.map((v,i)=>({ x:pad+i*xStep, y:H-pad-(Math.max(...vals,1)>0?(v/Math.max(...vals,1))*(H-pad*2):0) }));

    if (showR && showF) {
      const mx=Math.max(...valsR,...valsF,1);
      const ptsR=valsR.map((v,i)=>({x:pad+i*xStep,y:H-pad-(v/mx)*(H-pad*2)}));
      const ptsF=valsF.map((v,i)=>({x:pad+i*xStep,y:H-pad-(v/mx)*(H-pad*2)}));
      const areaR=`M${ptsR[0].x},${H} `+ptsR.map(p=>`L${p.x},${p.y}`).join(' ')+` L${ptsR[ptsR.length-1].x},${H} Z`;
      const areaF=`M${ptsF[0].x},${H} `+ptsF.map(p=>`L${p.x},${p.y}`).join(' ')+` L${ptsF[ptsF.length-1].x},${H} Z`;
      graphHtml = `
        <div style="display:flex;align-items:center;gap:16px;margin-bottom:6px;font-size:11px">
          <span><span style="display:inline-block;width:20px;height:3px;background:var(--blue);border-radius:2px;margin-right:4px;vertical-align:middle"></span>Relevés</span>
          <span><span style="display:inline-block;width:20px;height:3px;background:#9b59b6;border-radius:2px;margin-right:4px;vertical-align:middle;stroke-dasharray:6,3"></span>Factures</span>
        </div>
        <svg viewBox="0 0 ${W} ${H+14}" style="width:100%;height:130px;overflow:visible">
          <defs>
            <linearGradient id="gR" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="var(--blue)" stop-opacity="0.2"/><stop offset="100%" stop-color="var(--blue)" stop-opacity="0.02"/></linearGradient>
            <linearGradient id="gF" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#9b59b6" stop-opacity="0.15"/><stop offset="100%" stop-color="#9b59b6" stop-opacity="0.02"/></linearGradient>
          </defs>
          <path d="${areaR}" fill="url(#gR)"/>
          <path d="${areaF}" fill="url(#gF)"/>
          <polyline points="${ptsR.map(p=>`${p.x},${p.y}`).join(' ')}" fill="none" stroke="var(--blue)" stroke-width="2.5" stroke-linejoin="round"/>
          <polyline points="${ptsF.map(p=>`${p.x},${p.y}`).join(' ')}" fill="none" stroke="#9b59b6" stroke-width="2.5" stroke-linejoin="round" stroke-dasharray="6,3"/>
          ${ptsR.map((p,i)=>valsR[i]>0?`<circle cx="${p.x}" cy="${p.y}" r="3.5" fill="var(--blue)" stroke="white" stroke-width="1.5"><title>Relevé ${mois[i].label}: ${fmtVal(valsR[i])}</title></circle>`:'').join('')}
          ${ptsF.map((p,i)=>valsF[i]>0?`<circle cx="${p.x}" cy="${p.y}" r="3.5" fill="#9b59b6" stroke="white" stroke-width="1.5"><title>Facture ${mois[i].label}: ${fmtVal(valsF[i])}</title></circle>`:'').join('')}
          ${mois.map((m,i)=>`<text x="${ptsR[i].x}" y="${H+12}" text-anchor="middle" font-size="9" fill="var(--gray-text)">${m.label}</text>`).join('')}
        </svg>`;
    } else {
      const vals = showR ? valsR : valsF;
      const col  = showR ? 'var(--blue)' : '#9b59b6';
      const gradId = showR ? 'gSingle_R' : 'gSingle_F';
      const mx=Math.max(...vals,1);
      const pts=vals.map((v,i)=>({x:pad+i*xStep,y:H-pad-(v/mx)*(H-pad*2)}));
      const area=`M${pts[0].x},${H} `+pts.map(p=>`L${p.x},${p.y}`).join(' ')+` L${pts[pts.length-1].x},${H} Z`;
      graphHtml = `<svg viewBox="0 0 ${W} ${H+14}" style="width:100%;height:130px;overflow:visible">
        <defs><linearGradient id="${gradId}" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stop-color="${col}" stop-opacity="0.25"/><stop offset="100%" stop-color="${col}" stop-opacity="0.02"/>
        </linearGradient></defs>
        <path d="${area}" fill="url(#${gradId})"/>
        <polyline points="${pts.map(p=>`${p.x},${p.y}`).join(' ')}" fill="none" stroke="${col}" stroke-width="2.5" stroke-linejoin="round"/>
        ${pts.map((p,i)=>vals[i]>0?`<circle cx="${p.x}" cy="${p.y}" r="4" fill="${col}" stroke="white" stroke-width="2"><title>${mois[i].label}: ${fmtVal(vals[i])}</title></circle>`:'').join('')}
        ${mois.map((m,i)=>`<text x="${pts[i].x}" y="${H+12}" text-anchor="middle" font-size="9" fill="var(--gray-text)">${m.label}</text>`).join('')}
      </svg>`;
    }

  } else if (graphType === 'area') {
    // Double aire conso + cout (données combinées ou par type selon filtres)
    const vConso = showR && showF ? mois.map(m=>parMoisReleves[m.key].conso+parMoisFactures[m.key].conso)
                  : showR ? mois.map(m=>parMoisReleves[m.key].conso)
                  : mois.map(m=>parMoisFactures[m.key].conso);
    const vCout  = showR && showF ? mois.map(m=>parMoisReleves[m.key].cout+parMoisFactures[m.key].cout)
                  : showR ? mois.map(m=>parMoisReleves[m.key].cout)
                  : mois.map(m=>parMoisFactures[m.key].cout);
    const W2=800,H2=100,pad2=10;
    const xS=(W2-pad2*2)/Math.max(mois.length-1,1);
    const mxC=Math.max(...vConso,1),mxM=Math.max(...vCout,1);
    const ptsC=vConso.map((v,i)=>({x:pad2+i*xS,y:H2-pad2-(v/mxC)*(H2-pad2*2)}));
    const ptsM=vCout.map((v,i)=>({x:pad2+i*xS,y:H2-pad2-(v/mxM)*(H2-pad2*2)}));
    const areaC=`M${ptsC[0].x},${H2} `+ptsC.map(p=>`L${p.x},${p.y}`).join(' ')+` L${ptsC[ptsC.length-1].x},${H2} Z`;
    const areaM=`M${ptsM[0].x},${H2} `+ptsM.map(p=>`L${p.x},${p.y}`).join(' ')+` L${ptsM[ptsM.length-1].x},${H2} Z`;
    graphHtml = `<div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap">
      <div style="flex:1;min-width:280px">
        <div style="font-size:11px;font-weight:600;color:var(--blue);margin-bottom:4px">Consommation (${cfg.unite})</div>
        <svg viewBox="0 0 ${W2} ${H2+12}" style="width:100%;height:110px">
          <path d="${areaC}" fill="var(--blue)" opacity="0.2"/>
          <polyline points="${ptsC.map(p=>`${p.x},${p.y}`).join(' ')}" fill="none" stroke="var(--blue)" stroke-width="2"/>
          ${mois.map((m,i)=>`<text x="${ptsC[i].x}" y="${H2+10}" text-anchor="middle" font-size="8" fill="var(--gray-text)">${m.label}</text>`).join('')}
        </svg>
      </div>
      <div style="flex:1;min-width:280px">
        <div style="font-size:11px;font-weight:600;color:var(--orange);margin-bottom:4px">Coût (€)</div>
        <svg viewBox="0 0 ${W2} ${H2+12}" style="width:100%;height:110px">
          <path d="${areaM}" fill="var(--orange)" opacity="0.2"/>
          <polyline points="${ptsM.map(p=>`${p.x},${p.y}`).join(' ')}" fill="none" stroke="var(--orange)" stroke-width="2"/>
          ${mois.map((m,i)=>`<text x="${ptsM[i].x}" y="${H2+10}" text-anchor="middle" font-size="8" fill="var(--gray-text)">${m.label}</text>`).join('')}
        </svg>
      </div>
    </div>`;

  } else if (graphType === 'comparaison') {
    // Comparaison par compteur (barres groupées horizontales)
    const parCompteurMois = {};
    compteurs.forEach(cpt => { parCompteurMois[cpt.Nom] = { conso:0, cout:0 }; });
    tousReleves.forEach(r => {
      if (parCompteurMois[r._compteurNom]) {
        parCompteurMois[r._compteurNom].conso += parseFloat(r.Consommation)||0;
        parCompteurMois[r._compteurNom].cout  += parseFloat(r.Montant)||0;
      }
    });
    const entries = Object.entries(parCompteurMois);
    const maxEntry = Math.max(...entries.map(([,d]) => metrique==='cout'?d.cout:d.conso), 1);
    const COLORS = ['var(--blue)','var(--orange)','var(--teal)','#9b59b6','#e74c3c','#27ae60'];
    graphHtml = `<div style="display:flex;flex-direction:column;gap:10px">` +
      entries.map(([nom,d],i) => {
        const v = metrique==='cout' ? d.cout : d.conso;
        const pct = Math.round((v/maxEntry)*100);
        return `<div>
          <div style="display:flex;justify-content:space-between;font-size:11px;margin-bottom:3px">
            <span style="font-weight:500">${nom}</span>
            <span style="color:${COLORS[i%COLORS.length]};font-weight:600">${fmtVal(v)}</span>
          </div>
          <div style="background:var(--gray-border);border-radius:4px;height:14px">
            <div style="width:${pct}%;height:100%;background:${COLORS[i%COLORS.length]};border-radius:4px;opacity:0.8"></div>
          </div>
        </div>`;
      }).join('') + `</div>`;
  }

  // Tableau par compteur
  const parCompteur = {};
  tousReleves.forEach(r => {
    if (!parCompteur[r._compteurNom]) parCompteur[r._compteurNom] = { conso:0, cout:0, nb:0, carbone:0 };
    parCompteur[r._compteurNom].conso += parseFloat(r.Consommation)||0;
    parCompteur[r._compteurNom].cout  += parseFloat(r.Montant)||0;
    parCompteur[r._compteurNom].carbone += _getCarbone(r);
    parCompteur[r._compteurNom].nb++;
  });

  // Agréger aussi par compteur + type
  const parCompteurType = {};
  tousReleves.forEach(r => {
    if (!parCompteurType[r._compteurNom]) parCompteurType[r._compteurNom] = { releve:{conso:0,cout:0,nb:0}, facture:{conso:0,cout:0,nb:0} };
    const t = r.Type === 'Facture' ? 'facture' : 'releve';
    parCompteurType[r._compteurNom][t].conso += parseFloat(r.Consommation)||0;
    parCompteurType[r._compteurNom][t].cout  += parseFloat(r.Montant)||0;
    parCompteurType[r._compteurNom][t].nb++;
  });

  const lignesCompteur = Object.entries(parCompteur).map(([nom,d]) => {
    const pt = parCompteurType[nom] || { releve:{conso:0,cout:0,nb:0}, facture:{conso:0,cout:0,nb:0} };
    const hasR = pt.releve.nb > 0, hasF = pt.facture.nb > 0;
    const ecart = hasR && hasF ? pt.releve.conso - pt.facture.conso : null;
    const ecartCol = ecart === null ? '' : Math.abs(ecart) < d.conso*0.03 ? '#27ae60' : 'var(--orange)';
    return `
    <tr>
      <td style="font-weight:500">${nom}</td>
      <td style="color:var(--blue)">
        ${hasR ? `<div style="font-weight:600">${fmtNum(pt.releve.conso)} ${cfg.unite}</div><div style="font-size:10px;color:var(--gray-text)">${pt.releve.nb} relevé${pt.releve.nb>1?'s':''}</div>` : '<span style="color:var(--gray-text)">—</span>'}
      </td>
      <td style="color:#9b59b6">
        ${hasF ? `<div style="font-weight:600">${fmtNum(pt.facture.conso)} ${cfg.unite}</div><div style="font-size:10px;color:var(--gray-text)">${pt.facture.nb} facture${pt.facture.nb>1?'s':''} · ${fmtMon(pt.facture.cout)}</div>` : '<span style="color:var(--gray-text)">—</span>'}
      </td>
      <td style="color:${ecartCol};font-weight:${ecart!==null?'600':'400'}">
        ${ecart !== null ? (ecart>=0?'+':'')+fmtNum(ecart)+' '+cfg.unite : '—'}
      </td>
      <td style="color:var(--teal)">${d.conso>0?fmtMon(d.cout/d.conso)+'/'+cfg.unite:'—'}</td>
      <td style="color:#16a34a">${d.carbone>0?fmtNum(d.carbone)+' kgCO₂':'—'}</td>
    </tr>`;
  }).join('');


  const nbMoisTotal = tousLesMois.length;
  const periodeLabel = EnergieStatsState.periode === 'all' ? `${nbMoisTotal} mois (tout)` : `${EnergieStatsState.periode} derniers mois`;

  return `
  <div id="energieStatsWrapper" style="margin-bottom:16px"><div id="energieStatsContainer" class="card">
    <div class="card-header">
      <div class="card-title">${cfg.icon} Statistiques ${type} — ${periodeLabel}</div>
    </div>

    <!-- KPIs avec toggle par groupe -->
    <div style="padding:10px 20px 0;border-bottom:1px solid var(--gray-border)">

      <!-- Boutons toggle groupes -->
      <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px;align-items:center">
        <span style="font-size:11px;font-weight:600;color:var(--gray-text);margin-right:2px">Afficher :</span>
        ${[
          ['kpi_conso',   '📊 Consommation', true],
          ['kpi_cout',    '💰 Coûts',        true],
          ['kpi_contrat', '📋 Contrat',      true],
          ['kpi_tendance','📈 Tendance',      true],
          ['kpi_carbone', '🌿 Carbone',      true],
          ['kpi_extremes','🏆 Extrêmes',     false],
        ].map(([id,label,defOn]) => {
          const on = EnergieStatsState.kpiVisible?.[id] ?? defOn;
          return `<button onclick="toggleKpiGroupe('${id}')" id="kpiBtn_${id}"
            style="padding:4px 10px;border:1px solid ${on?'var(--blue)':'var(--gray-border)'};
              background:${on?'var(--blue)':'white'};color:${on?'white':'var(--gray-text)'};
              border-radius:20px;cursor:pointer;font-size:11px;font-weight:${on?'600':'400'};transition:all .15s">
            ${label}</button>`;
        }).join('')}
      </div>

      <!-- Groupe : Consommation -->
      <div id="kpi_conso" style="display:${(EnergieStatsState.kpiVisible?.kpi_conso ?? true)?'flex':'none'};gap:0;flex-wrap:wrap;border-top:1px solid var(--gray-border);padding:12px 0">
        <div style="font-size:10px;font-weight:700;color:var(--gray-text);text-transform:uppercase;letter-spacing:.5px;width:100%;margin-bottom:8px">📊 Consommation</div>
        <div style="display:flex;gap:16px;flex-wrap:wrap">
          ${hasBoth ? `
          <div style="text-align:center;min-width:110px">
            <div style="font-size:10px;color:var(--gray-text);display:flex;align-items:center;justify-content:center;gap:3px">
              <span style="display:inline-block;width:8px;height:8px;background:var(--blue);border-radius:2px"></span>Conso relevée
              ${infoTooltip('Consommation issue des relevés manuels uniquement.')}</div>
            <div style="font-size:18px;font-weight:700;color:var(--blue)">${fmtNum(totalConsoReleves)} <span style="font-size:11px">${cfg.unite}</span></div>
            <div style="font-size:9px;color:var(--gray-text)">${releves.length} relevé${releves.length>1?'s':''}</div>
          </div>
          <div style="text-align:center;min-width:110px">
            <div style="font-size:10px;color:var(--gray-text);display:flex;align-items:center;justify-content:center;gap:3px">
              <span style="display:inline-block;width:8px;height:8px;background:#9b59b6;border-radius:2px"></span>Conso facturée
              ${infoTooltip('Consommation issue des factures reçues uniquement.')}</div>
            <div style="font-size:18px;font-weight:700;color:#9b59b6">${fmtNum(totalConsoFactures)} <span style="font-size:11px">${cfg.unite}</span></div>
            <div style="font-size:9px;color:var(--gray-text)">${factures.length} facture${factures.length>1?'s':''}</div>
          </div>
          <div style="text-align:center;min-width:110px">
            <div style="font-size:10px;color:var(--gray-text);display:flex;align-items:center;justify-content:center;gap:3px">Écart relevés/factures
              ${infoTooltip('Différence relevée - facturée.\n~0 = cohérence, + = fournisseur facture moins, - = fournisseur facture plus.')}</div>
            ${(()=>{const e=totalConsoReleves-totalConsoFactures; const pct=totalConsoFactures>0?Math.abs(e/totalConsoFactures*100).toFixed(1):null; const col=Math.abs(e)<totalConsoReleves*0.03?'#27ae60':e>0?'var(--orange)':'#e74c3c'; return '<div style="font-size:18px;font-weight:700;color:'+col+'">'+(e>=0?'+':'')+fmtNum(e)+' '+cfg.unite+'</div><div style="font-size:9px;color:var(--gray-text)">'+(pct?pct+'%':'')+'</div>'; })()}
          </div>
          <div style="width:1px;background:var(--gray-border);align-self:stretch;margin:0 4px"></div>
          <div style="text-align:center;min-width:110px">
            <div style="font-size:10px;color:var(--gray-text);display:flex;align-items:center;justify-content:center;gap:3px">
              <span style="display:inline-block;width:8px;height:8px;background:var(--blue);border-radius:2px"></span>Moy. mensuelle relevés
              ${infoTooltip('Consommation moyenne par mois — relevés manuels.')}</div>
            <div style="font-size:16px;font-weight:700;color:var(--blue)">${fmtNum(moyMensReleves)} <span style="font-size:11px">${cfg.unite}</span></div>
          </div>
          <div style="text-align:center;min-width:110px">
            <div style="font-size:10px;color:var(--gray-text);display:flex;align-items:center;justify-content:center;gap:3px">
              <span style="display:inline-block;width:8px;height:8px;background:#9b59b6;border-radius:2px"></span>Moy. mensuelle factures
              ${infoTooltip('Consommation moyenne par mois — factures.')}</div>
            <div style="font-size:16px;font-weight:700;color:#9b59b6">${fmtNum(moyMensFactures)} <span style="font-size:11px">${cfg.unite}</span></div>
          </div>
          ` : `
          <div style="text-align:center;min-width:110px">
            <div style="font-size:10px;color:var(--gray-text)">Conso totale</div>
            <div style="font-size:20px;font-weight:700;color:var(--blue)">${fmtNum(totalConso)} <span style="font-size:11px">${cfg.unite}</span></div>
          </div>
          <div style="text-align:center;min-width:110px">
            <div style="font-size:10px;color:var(--gray-text)">Moy. mensuelle</div>
            <div style="font-size:18px;font-weight:700">${fmtNum(moyenneMensuelle)} <span style="font-size:11px">${cfg.unite}</span></div>
          </div>
          `}
        </div>
      </div>

      <!-- Groupe : Coûts -->
      <div id="kpi_cout" style="display:${(EnergieStatsState.kpiVisible?.kpi_cout ?? true)?'flex':'none'};gap:0;flex-wrap:wrap;border-top:1px solid var(--gray-border);padding:12px 0">
        <div style="font-size:10px;font-weight:700;color:var(--gray-text);text-transform:uppercase;letter-spacing:.5px;width:100%;margin-bottom:8px">💰 Coûts</div>
        <div style="display:flex;gap:16px;flex-wrap:wrap">
          ${hasBoth ? `
          <div style="text-align:center;min-width:110px">
            <div style="font-size:10px;color:var(--gray-text);display:flex;align-items:center;justify-content:center;gap:3px">
              <span style="display:inline-block;width:8px;height:8px;background:var(--blue);border-radius:2px"></span>Coût estimé (relevés)
              ${infoTooltip('Montant total estimé depuis les relevés manuels.')}</div>
            <div style="font-size:18px;font-weight:700;color:var(--orange)">${fmtMon(totalCoutReleves)}</div>
            <div style="font-size:9px;color:var(--gray-text)">moy ${fmtMon(moyMensCoutReleves)}/mois</div>
          </div>
          <div style="text-align:center;min-width:110px">
            <div style="font-size:10px;color:var(--gray-text);display:flex;align-items:center;justify-content:center;gap:3px">
              <span style="display:inline-block;width:8px;height:8px;background:#9b59b6;border-radius:2px"></span>Coût réel (factures)
              ${infoTooltip('Montant total réellement facturé.')}</div>
            <div style="font-size:18px;font-weight:700;color:var(--orange)">${fmtMon(totalCoutFactures)}</div>
            <div style="font-size:9px;color:var(--gray-text)">moy ${fmtMon(moyMensCoutFactures)}/mois</div>
          </div>
          <div style="text-align:center;min-width:110px">
            <div style="font-size:10px;color:var(--gray-text);display:flex;align-items:center;justify-content:center;gap:3px">
              <span style="display:inline-block;width:8px;height:8px;background:var(--blue);border-radius:2px"></span>Prix moyen relevés
              ${infoTooltip('Coût estimé ÷ Conso relevée (abo inclus)')}</div>
            <div style="font-size:16px;font-weight:700;color:#9b59b6">${prixMoyenReleves>0?fmtMon(prixMoyenReleves)+'/'+cfg.unite:'—'}</div>
          </div>
          <div style="text-align:center;min-width:110px">
            <div style="font-size:10px;color:var(--gray-text);display:flex;align-items:center;justify-content:center;gap:3px">
              <span style="display:inline-block;width:8px;height:8px;background:#9b59b6;border-radius:2px"></span>Prix moyen factures
              ${infoTooltip('Coût facturé ÷ Conso facturée (abo inclus)')}</div>
            <div style="font-size:16px;font-weight:700;color:#9b59b6">${prixMoyenFactures>0?fmtMon(prixMoyenFactures)+'/'+cfg.unite:'—'}</div>
          </div>
          <div style="text-align:center;min-width:110px">
            <div style="font-size:10px;color:var(--gray-text);display:flex;align-items:center;justify-content:center;gap:3px">Projection annuelle (factures)
              ${infoTooltip('Projection 12 mois basée sur le coût mensuel moyen facturé.')}</div>
            <div style="font-size:16px;font-weight:700">${fmtMon(moyMensCoutFactures*12)}</div>
          </div>
          ` : `
          <div style="text-align:center;min-width:110px">
            <div style="font-size:10px;color:var(--gray-text)">Coût total</div>
            <div style="font-size:20px;font-weight:700;color:var(--orange)">${fmtMon(totalCout)}</div>
          </div>
          <div style="text-align:center;min-width:110px">
            <div style="font-size:10px;color:var(--gray-text)">Coût moyen constaté</div>
            <div style="font-size:18px;font-weight:700;color:#9b59b6">${prixMoyenConstate>0?fmtMon(prixMoyenConstate)+'/'+cfg.unite:'—'}</div>
            <div style="font-size:9px;color:var(--gray-text)">(abo inclus)</div>
          </div>
          <div style="text-align:center;min-width:110px">
            <div style="font-size:10px;color:var(--gray-text)">Projection annuelle</div>
            <div style="font-size:18px;font-weight:700">${fmtMon(moyenneCoutMens*12)}</div>
          </div>
          `}
        </div>
      </div>

      <!-- Groupe : Contrat -->
      <div id="kpi_contrat" style="display:${(EnergieStatsState.kpiVisible?.kpi_contrat ?? true)?'flex':'none'};gap:0;flex-wrap:wrap;border-top:1px solid var(--gray-border);padding:12px 0">
        <div style="font-size:10px;font-weight:700;color:var(--gray-text);text-transform:uppercase;letter-spacing:.5px;width:100%;margin-bottom:8px">📋 Contrat</div>
        <div style="display:flex;gap:16px;flex-wrap:wrap">
          <div style="text-align:center;min-width:110px">
            <div style="font-size:10px;color:var(--gray-text);display:flex;align-items:center;justify-content:center;gap:3px">Prix contractuel
              ${infoTooltip('Prix unitaire HT défini dans la fiche compteur ou le contrat lié.')}</div>
            <div style="font-size:18px;font-weight:700;color:var(--teal)">${prixContractuel!=null?fmtMon(prixContractuel)+'/'+cfg.unite:'—'}</div>
            ${abonnementContractuel>0?`<div style="font-size:10px;color:var(--gray-text)">+ ${fmtMon(abonnementContractuel)} abo/mois</div>`:''}
          </div>
        </div>
      </div>

      <!-- Groupe : Tendance -->
      <div id="kpi_tendance" style="display:${(EnergieStatsState.kpiVisible?.kpi_tendance ?? true)?'flex':'none'};gap:0;flex-wrap:wrap;border-top:1px solid var(--gray-border);padding:12px 0">
        <div style="font-size:10px;font-weight:700;color:var(--gray-text);text-transform:uppercase;letter-spacing:.5px;width:100%;margin-bottom:8px">📈 Tendance</div>
        <div style="display:flex;gap:16px;flex-wrap:wrap">
          ${tendanceConso !== null ? `
          <div style="text-align:center;min-width:110px">
            <div style="font-size:10px;color:var(--gray-text);display:flex;align-items:center;justify-content:center;gap:3px">Dernier mois vs précédent
              ${infoTooltip('Évolution de la consommation entre le dernier mois et le mois précédent.')}</div>
            <div style="font-size:20px;font-weight:700;color:${parseFloat(tendanceConso)>0?'var(--orange)':'#27ae60'}">
              ${parseFloat(tendanceConso)>0?'▲':'▼'} ${Math.abs(tendanceConso)}%
            </div>
          </div>` : `<div style="font-size:12px;color:var(--gray-text);padding:4px 0">Pas assez de données (min. 2 mois)</div>`}
        </div>
      </div>

      <!-- Groupe : Extrêmes -->
      <div id="kpi_extremes" style="display:${(EnergieStatsState.kpiVisible?.kpi_extremes ?? false)?'flex':'none'};gap:0;flex-wrap:wrap;border-top:1px solid var(--gray-border);padding:12px 0">
        <div style="font-size:10px;font-weight:700;color:var(--gray-text);text-transform:uppercase;letter-spacing:.5px;width:100%;margin-bottom:8px">🏆 Extrêmes</div>
        <div style="display:flex;gap:16px;flex-wrap:wrap">
          ${meilleurMois ? `
          <div style="text-align:center;min-width:110px">
            <div style="font-size:10px;color:var(--gray-text);display:flex;align-items:center;justify-content:center;gap:3px">Mois le + sobre
              ${infoTooltip('Mois avec la plus faible consommation sur la période.')}</div>
            <div style="font-size:13px;font-weight:700;color:#27ae60">${meilleurMois.label}<br><span style="font-size:11px">${fmtNum(parMois[meilleurMois.key].conso)} ${cfg.unite}</span></div>
          </div>` : ''}
          ${pireMois ? `
          <div style="text-align:center;min-width:110px">
            <div style="font-size:10px;color:var(--gray-text);display:flex;align-items:center;justify-content:center;gap:3px">Mois le + consommateur
              ${infoTooltip('Mois avec la plus forte consommation sur la période.')}</div>
            <div style="font-size:13px;font-weight:700;color:var(--orange)">${pireMois.label}<br><span style="font-size:11px">${fmtNum(parMois[pireMois.key].conso)} ${cfg.unite}</span></div>
          </div>` : ''}
        </div>
      </div>

      <!-- Groupe : Carbone -->
      <div id="kpi_carbone" style="display:${(EnergieStatsState.kpiVisible?.kpi_carbone ?? true)?'flex':'none'};gap:0;flex-wrap:wrap;border-top:1px solid var(--gray-border);padding:12px 0">
        <div style="font-size:10px;font-weight:700;color:#15803d;text-transform:uppercase;letter-spacing:.5px;width:100%;margin-bottom:8px;display:flex;align-items:center;gap:8px">🌿 Empreinte carbone
          <span style="text-transform:none;letter-spacing:0;font-weight:500;font-size:10px;padding:1px 8px;border-radius:10px;background:${_methodeActive==='simplifiee'?'#f5f3ff':'#f0fdf4'};color:${_methodeActive==='simplifiee'?'#7c3aed':'#16a34a'};border:1px solid ${_methodeActive==='simplifiee'?'#c4b5fd':'#86efac'}">
            ${_methodeActive==='simplifiee'?'📦 Simplifiée':'🏛️ Officielle'}
          </span>
          ${type==='Chauffage' ? '<span style="text-transform:none;letter-spacing:0;font-weight:400;font-size:9px;color:var(--gray-text)">Chauffage : facteur ' + (_methodeActive==='simplifiee'?'Impact CO2':'ADEME') + ' (mix moyen réseau)</span>' : ''}
        </div>
        <div style="display:flex;gap:16px;flex-wrap:wrap">
          ${hasCarbone ? `
          <div style="text-align:center;min-width:110px">
            <div style="font-size:10px;color:var(--gray-text);display:flex;align-items:center;justify-content:center;gap:3px">CO₂ total
              ${infoTooltip('Total des émissions carbone sur la période.')}</div>
            <div style="font-size:20px;font-weight:700;color:#16a34a">${fmtNum(totalCarbone)} <span style="font-size:11px">kgCO₂</span></div>
            <div style="font-size:9px;color:var(--gray-text)">${fmtNum(totalCarbone/1000)} t CO₂</div>
          </div>
          ${totalCarboneReleves > 0 && totalCarboneFactures > 0 ? `
          <div style="text-align:center;min-width:110px">
            <div style="font-size:10px;color:var(--gray-text);display:flex;align-items:center;justify-content:center;gap:3px">
              <span style="display:inline-block;width:8px;height:8px;background:var(--blue);border-radius:2px"></span>CO₂ relevés</div>
            <div style="font-size:16px;font-weight:700;color:var(--blue)">${fmtNum(totalCarboneReleves)} <span style="font-size:10px">kg</span></div>
          </div>
          <div style="text-align:center;min-width:110px">
            <div style="font-size:10px;color:var(--gray-text);display:flex;align-items:center;justify-content:center;gap:3px">
              <span style="display:inline-block;width:8px;height:8px;background:#9b59b6;border-radius:2px"></span>CO₂ factures</div>
            <div style="font-size:16px;font-weight:700;color:#9b59b6">${fmtNum(totalCarboneFactures)} <span style="font-size:10px">kg</span></div>
          </div>
          <div style="width:1px;background:var(--gray-border);align-self:stretch;margin:0 4px"></div>
          ` : ''}
          <div style="text-align:center;min-width:110px">
            <div style="font-size:10px;color:var(--gray-text);display:flex;align-items:center;justify-content:center;gap:3px">CO₂ mensuel moyen
              ${infoTooltip('Moyenne mensuelle des émissions carbone.')}</div>
            <div style="font-size:16px;font-weight:700;color:#16a34a">${fmtNum(totalCarbone / (tousLesMois.length || 1))} <span style="font-size:11px">kgCO₂/mois</span></div>
          </div>
          <div style="text-align:center;min-width:110px">
            <div style="font-size:10px;color:var(--gray-text);display:flex;align-items:center;justify-content:center;gap:3px">Intensité carbone
              ${infoTooltip('Ratio moyen émissions / consommation.')}</div>
            <div style="font-size:16px;font-weight:700;color:#0e7490">${totalConso > 0 ? (totalCarbone / totalConso * 1000).toFixed(1) : '—'} <span style="font-size:11px">gCO₂/${cfg.unite}</span></div>
          </div>
          <div style="text-align:center;min-width:110px">
            <div style="font-size:10px;color:var(--gray-text);display:flex;align-items:center;justify-content:center;gap:3px">Projection annuelle
              ${infoTooltip('Projection 12 mois basée sur la moyenne mensuelle.')}</div>
            <div style="font-size:16px;font-weight:700">${fmtNum(totalCarbone / (tousLesMois.length || 1) * 12)} <span style="font-size:11px">kgCO₂/an</span></div>
            <div style="font-size:9px;color:var(--gray-text)">${fmtNum(totalCarbone / (tousLesMois.length || 1) * 12 / 1000)} t/an</div>
          </div>
          <div style="text-align:center;min-width:110px">
            <div style="font-size:10px;color:var(--gray-text);display:flex;align-items:center;justify-content:center;gap:3px">Coût carbone estimé
              ${infoTooltip('Estimation basée sur le prix EU ETS configurable dans l\'onglet Bilan Carbone.')}</div>
            <div style="font-size:16px;font-weight:700;color:var(--orange)">${fmtMon(totalCarbone / 1000 * (BilanCarboneState?.prixCO2 || 80))}</div>
            <div style="font-size:9px;color:var(--gray-text)">${BilanCarboneState?.prixCO2 || 80} €/tCO₂</div>
          </div>
          ` : `
          <div style="font-size:12px;color:var(--gray-text);padding:4px 0">
            Aucune donnée carbone saisie. Ajoutez les émissions CO₂ dans vos relevés ou factures.
          </div>
          `}
        </div>
      </div>

    </div>
    </div>

    <!-- Graphique principal -->
    <div style="padding:16px 20px;border-bottom:1px solid var(--gray-border)">
      <div style="font-size:11px;font-weight:600;color:var(--gray-text);margin-bottom:10px">${labelMetrique}</div>
      ${graphHtml}
    </div>

    <!-- Graphique Carbone dédié — Relevés & Factures différenciés -->
    ${hasCarbone ? `
    <div style="padding:16px 20px;border-bottom:1px solid var(--gray-border)">

      <!-- En-tête + contrôles indépendants -->
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;flex-wrap:wrap">
        <div style="font-size:12px;font-weight:700;color:#16a34a">🌿 Émissions CO₂ — Évolution mensuelle</div>
        <div style="flex:1;border-top:1px dashed #bbf7d0"></div>
        ${(()=>{
          const cPer = CarboneGraphState.periode;
          const cTyp = CarboneGraphState.graphType;
          const cSR  = CarboneGraphState.showReleve  !== false;
          const cSF  = CarboneGraphState.showFacture !== false;
          const btnStyle = (active, col) =>
            `padding:3px 9px;border:1px solid ${active ? col : 'var(--gray-border)'};` +
            `background:${active ? col : 'transparent'};color:${active ? 'white' : 'var(--gray-text)'};` +
            `border-radius:4px;cursor:pointer;font-size:11px;font-weight:${active ? '600' : '400'}`;
          return `
          <div style="display:flex;gap:4px;align-items:center">
            <span style="font-size:10px;color:var(--gray-text)">Série :</span>
            <button onclick="switchCarboneShow('showReleve')"  style="${btnStyle(cSR,'var(--blue)')}">📋 Relevés</button>
            <button onclick="switchCarboneShow('showFacture')" style="${btnStyle(cSF,'#9b59b6')}">🧾 Factures</button>
          </div>
          <div style="width:1px;background:var(--gray-border);align-self:stretch;margin:0 2px"></div>
          <div style="display:flex;gap:4px;align-items:center">
            <span style="font-size:10px;color:var(--gray-text)">Période :</span>
            ${[['3','3 m'],['6','6 m'],['12','1 an'],['all','Tout']].map(([v,l]) =>
              `<button onclick="switchCarbonePeriode('${v}')" style="${btnStyle(cPer===v,'#16a34a')}">${l}</button>`
            ).join('')}
          </div>
          <div style="display:flex;gap:4px;align-items:center;margin-left:8px">
            <button onclick="switchCarboneGraphType('barres')" title="Barres" style="${btnStyle(cTyp==='barres','#16a34a')}">📊</button>
            <button onclick="switchCarboneGraphType('ligne')"  title="Ligne"  style="${btnStyle(cTyp==='ligne','#16a34a')}">📈</button>
          </div>`;
        })()}
        ${(()=>{
          // Badge tendance (basé sur la série active ou combinée)
          const cPer = CarboneGraphState.periode;
          const cSR  = CarboneGraphState.showReleve  !== false;
          const cSF  = CarboneGraphState.showFacture !== false;
          const periodeN = cPer === 'all' ? 9999 : parseInt(cPer);
          const maintenant2 = new Date();
          let moisDebut2;
          if (periodeN === 9999) moisDebut2 = new Date(premierMois);
          else if (periodeN === 12) { moisDebut2 = new Date(maintenant2.getFullYear(),0,1); }
          else moisDebut2 = new Date(maintenant2.getFullYear(), maintenant2.getMonth()-periodeN+1, 1);
          const moisFin2 = periodeN === 12 ? new Date(moisDebut2.getFullYear(),11,1) : maintenant2;
          const moisC = []; const dC = new Date(moisDebut2 < premierMois ? premierMois : moisDebut2);
          while (dC <= moisFin2) {
            moisC.push(`${dC.getFullYear()}-${String(dC.getMonth()+1).padStart(2,'0')}`);
            dC.setMonth(dC.getMonth()+1);
          }
          const vCarb = moisC.map(k =>
            (cSR ? (parMoisCarboneReleve[k]||0) : 0) + (cSF ? (parMoisCarboneFacture[k]||0) : 0)
          ).filter(v => v > 0);
          if (vCarb.length >= 2) {
            const last = vCarb[vCarb.length-1], prev = vCarb[vCarb.length-2];
            const pct = prev > 0 ? ((last-prev)/prev*100).toFixed(1) : null;
            if (pct !== null) {
              const up = parseFloat(pct) > 0;
              return `<span style="font-size:12px;font-weight:700;color:${up?'var(--orange)':'#16a34a'}">${up?'▲':'▼'} ${Math.abs(pct)}% vs mois préc.</span>`;
            }
          }
          return '';
        })()}
      </div>

      <!-- Graphique CO₂ différencié Relevés / Factures -->
      ${(() => {
        const cPer = CarboneGraphState.periode;
        const cTyp = CarboneGraphState.graphType;
        const cSR  = CarboneGraphState.showReleve  !== false;
        const cSF  = CarboneGraphState.showFacture !== false;
        const periodeN = cPer === 'all' ? 9999 : parseInt(cPer);
        const maintenant3 = new Date();
        let moisDebut3;
        if (periodeN === 9999) moisDebut3 = new Date(premierMois);
        else if (periodeN === 12) { moisDebut3 = new Date(maintenant3.getFullYear(),0,1); }
        else moisDebut3 = new Date(maintenant3.getFullYear(), maintenant3.getMonth()-periodeN+1, 1);
        const moisFin3 = periodeN === 12 ? new Date(moisDebut3.getFullYear(),11,1) : maintenant3;
        const moisC = []; const dC3 = new Date(moisDebut3 < premierMois ? premierMois : moisDebut3);
        while (dC3 <= moisFin3) {
          const k = `${dC3.getFullYear()}-${String(dC3.getMonth()+1).padStart(2,'0')}`;
          moisC.push({ key:k, label:dC3.toLocaleDateString('fr-FR',{month:'short',year:'2-digit'}) });
          dC3.setMonth(dC3.getMonth()+1);
        }

        const vRel = moisC.map(m => cSR ? (parMoisCarboneReleve[m.key]  || 0) : 0);
        const vFac = moisC.map(m => cSF ? (parMoisCarboneFacture[m.key] || 0) : 0);
        const vTot = moisC.map((_,i) => vRel[i] + vFac[i]);
        const mxCarb = Math.max(...vTot, 1);
        const avgCarb = vTot.reduce((a,b)=>a+b,0) / (vTot.filter(v=>v>0).length || 1);

        const COL_REL = 'var(--blue)';   // bleu pour relevés
        const COL_FAC = '#9b59b6';       // violet pour factures

        if (cTyp === 'ligne') {
          const W=800, H=100, pad=10;
          const xStep=(W-pad*2)/Math.max(moisC.length-1,1);
          let svgContent = `<defs>
            <linearGradient id="gCO2R" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stop-color="#3b82f6" stop-opacity="0.2"/>
              <stop offset="100%" stop-color="#3b82f6" stop-opacity="0"/>
            </linearGradient>
            <linearGradient id="gCO2F" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stop-color="#9b59b6" stop-opacity="0.2"/>
              <stop offset="100%" stop-color="#9b59b6" stop-opacity="0"/>
            </linearGradient>
          </defs>`;
          // Ligne relevés
          if (cSR && vRel.some(v=>v>0)) {
            const pts = vRel.map((v,i) => ({ x:pad+i*xStep, y:H-pad-(mxCarb>0?(v/mxCarb)*(H-pad*2):0) }));
            const area = `M${pts[0].x},${H} `+pts.map(p=>`L${p.x},${p.y}`).join(' ')+` L${pts[pts.length-1].x},${H} Z`;
            svgContent += `<path d="${area}" fill="url(#gCO2R)"/>`;
            svgContent += `<polyline points="${pts.map(p=>`${p.x},${p.y}`).join(' ')}" fill="none" stroke="#3b82f6" stroke-width="2" stroke-linejoin="round" stroke-dasharray="5,3"/>`;
            svgContent += pts.map((p,i) => vRel[i]>0 ? `<circle cx="${p.x}" cy="${p.y}" r="3" fill="#3b82f6" stroke="white" stroke-width="1.5"><title>📋 ${moisC[i].label}: ${fmtNum(vRel[i])} kgCO₂ (relevé)</title></circle>` : '').join('');
          }
          // Ligne factures
          if (cSF && vFac.some(v=>v>0)) {
            const pts = vFac.map((v,i) => ({ x:pad+i*xStep, y:H-pad-(mxCarb>0?(v/mxCarb)*(H-pad*2):0) }));
            const area = `M${pts[0].x},${H} `+pts.map(p=>`L${p.x},${p.y}`).join(' ')+` L${pts[pts.length-1].x},${H} Z`;
            svgContent += `<path d="${area}" fill="url(#gCO2F)"/>`;
            svgContent += `<polyline points="${pts.map(p=>`${p.x},${p.y}`).join(' ')}" fill="none" stroke="${COL_FAC}" stroke-width="2.5" stroke-linejoin="round"/>`;
            svgContent += pts.map((p,i) => vFac[i]>0 ? `<circle cx="${p.x}" cy="${p.y}" r="3.5" fill="${COL_FAC}" stroke="white" stroke-width="1.5"><title>🧾 ${moisC[i].label}: ${fmtNum(vFac[i])} kgCO₂ (facture)</title></circle>` : '').join('');
          }
          // Labels mois
          svgContent += moisC.map((m,i) => `<text x="${pad+i*xStep}" y="${H+12}" text-anchor="middle" font-size="9" fill="var(--gray-text)">${m.label}</text>`).join('');

          return `<svg viewBox="0 0 ${W} ${H+14}" style="width:100%;height:120px;overflow:visible">${svgContent}</svg>
          <div style="display:flex;align-items:center;gap:16px;margin-top:4px;font-size:11px;color:var(--gray-text)">
            ${cSR ? `<span><span style="display:inline-block;width:18px;height:2px;background:#3b82f6;border-top:2px dashed #3b82f6;vertical-align:middle;margin-right:4px"></span>📋 Relevés</span>` : ''}
            ${cSF ? `<span><span style="display:inline-block;width:18px;height:2px;background:${COL_FAC};vertical-align:middle;margin-right:4px"></span>🧾 Factures</span>` : ''}
            <span style="margin-left:4px">Moy. combinée : ${fmtNum(avgCarb)} kgCO₂</span>
          </div>`;
        }

        // Barres empilées (relevés bas, factures haut) ou simples selon sélection
        return '<div style="display:flex;gap:3px;align-items:flex-end;height:130px;padding:0 4px">'
          + moisC.map((m,i) => {
            const vR = vRel[i];
            const vF = vFac[i];
            const tot = vR + vF;
            const hR = mxCarb > 0 ? Math.round((vR/mxCarb)*100) : 0;
            const hF = mxCarb > 0 ? Math.round((vF/mxCarb)*100) : 0;
            const label = tot > 0 ? fmtNum(tot) : '';
            return '<div style="display:flex;flex-direction:column;align-items:center;gap:2px;flex:1;min-width:0">'
              + `<div style="font-size:9px;color:var(--gray-text);text-align:center;white-space:nowrap;overflow:hidden;max-width:100%">${label}</div>`
              + `<div style="display:flex;flex-direction:column;justify-content:flex-end;align-items:stretch;height:100px;width:100%;gap:0">`
              + (cSF && hF > 0 ? `<div style="width:100%;height:${hF}px;background:${COL_FAC};border-radius:${hR>0?'0':'3px 3px'} 0 0;opacity:0.85;min-height:2px" title="${m.label} 🧾 Facture: ${fmtNum(vF)} kgCO₂"></div>` : '')
              + (cSR && hR > 0 ? `<div style="width:100%;height:${hR}px;background:#3b82f6;border-radius:3px 3px 0 0;opacity:0.85;min-height:2px" title="${m.label} 📋 Relevé: ${fmtNum(vR)} kgCO₂"></div>` : '')
              + `</div>`
              + `<div style="font-size:9px;color:var(--gray-text);text-align:center;white-space:nowrap">${m.label}</div>`
              + '</div>';
          }).join('')
          + '</div>'
          + `<div style="display:flex;align-items:center;gap:16px;margin-top:8px;font-size:11px;color:var(--gray-text)">`
          + (cSR ? `<span><span style="display:inline-block;width:10px;height:10px;background:#3b82f6;border-radius:2px;vertical-align:middle;margin-right:4px;opacity:0.85"></span>📋 Relevés manuels</span>` : '')
          + (cSF ? `<span><span style="display:inline-block;width:10px;height:10px;background:${COL_FAC};border-radius:2px;vertical-align:middle;margin-right:4px;opacity:0.85"></span>🧾 Factures</span>` : '')
          + (cSR && cSF ? `<span style="color:var(--gray-text);font-size:10px">Barres empilées</span>` : '')
          + `</div>`;
      })()}
    </div>` : ''}

    <!-- Tableau par compteur -->
    <div style="padding:0 20px 20px">
      <div style="font-size:11px;font-weight:600;color:var(--gray-text);margin-bottom:10px;padding-top:16px">Détail par compteur (toutes périodes)</div>
      <div class="table-wrap" style="border:1px solid var(--gray-border);border-radius:8px;overflow:hidden">
        <table><thead><tr>
          <th>Compteur</th>
          <th style="color:var(--blue)">🔵 Conso relevée</th>
          <th style="color:#9b59b6">🟣 Conso facturée</th>
          <th>Écart</th>
          <th>Prix unit. moyen</th>
          <th style="color:#16a34a">🌿 CO₂</th>
        </tr></thead><tbody>${lignesCompteur}</tbody></table>
      </div>
    </div>
  </div></div>`;
}

// ═══════════════════════════════════════════════════════════════════════════════
//  ONGLET BILAN CARBONE
// ═══════════════════════════════════════════════════════════════════════════════

// ═══════════════════════════════════════════════════════════════════════════════
// ── Unités : conversion et agrégation cohérentes ────────────────────────────
//
// Le module manipule quatre unités qui ne vivent pas dans le même monde :
//   ⚡ Électricité  kWh    │ ┐ même grandeur physique,
//   🔥 Chauffage    MWh    │ ┘ donc convertibles entre elles
//   💧 Eau          m³     │ ┐ volumes, convertibles entre eux
//   🔵 Gaz          m³     │ ┘ mais PAS avec les kWh sans passer par un pouvoir
//                            calorifique (voir _bcGazEnKwh)
//
// D'où deux règles appliquées partout : on convertit systématiquement à
// l'intérieur d'une même famille, et on refuse de sommer entre familles.
// ═══════════════════════════════════════════════════════════════════════════════
const BC_UNITES = {
  'Wh':  { famille: 'energie', versBase: 0.001 },
  'kWh': { famille: 'energie', versBase: 1 },
  'MWh': { famille: 'energie', versBase: 1000 },
  'GWh': { famille: 'energie', versBase: 1000000 },
  'm³':  { famille: 'volume',  versBase: 1 },
  'm3':  { famille: 'volume',  versBase: 1 },
  'L':   { famille: 'volume',  versBase: 0.001 },
  'l':   { famille: 'volume',  versBase: 0.001 },
};
// Unité de référence de chaque famille (celle dans laquelle on cumule).
const BC_UNITE_BASE = { energie: 'kWh', volume: 'm³' };

/** Normalise une unité saisie librement (« KWH », « m3 », « Mwh »…). */
function _bcNormUnite(u) {
  if (!u) return '';
  var s = String(u).trim();
  var direct = { 'wh':'Wh', 'kwh':'kWh', 'mwh':'MWh', 'gwh':'GWh',
                 'm³':'m³', 'm3':'m³', 'l':'L', 'litre':'L', 'litres':'L' };
  return direct[s.toLowerCase()] || s;
}

/** Famille d'une unité, ou '' si inconnue (on ne cumulera pas à l'aveugle). */
function _bcFamilleUnite(u) {
  var n = _bcNormUnite(u);
  return (BC_UNITES[n] && BC_UNITES[n].famille) || '';
}

/** Convertit une valeur vers l'unité de base de sa famille. */
function _bcVersBase(valeur, unite) {
  var n = _bcNormUnite(unite);
  var def = BC_UNITES[n];
  if (!def) return null;
  return { famille: def.famille, valeur: (parseFloat(valeur) || 0) * def.versBase };
}

/**
 * Formate une valeur exprimée dans l'unité de base, en choisissant l'échelle
 * lisible : 12 000 kWh s'affiche « 12 MWh », 102 kWh reste « 102 kWh ».
 */
function _bcFmtDepuisBase(valeurBase, famille) {
  var fmt = function(v, dec) {
    return new Intl.NumberFormat('fr-FR', { maximumFractionDigits: dec }).format(v || 0);
  };
  if (famille === 'energie') {
    if (Math.abs(valeurBase) >= 1000000) return fmt(valeurBase / 1000000, 2) + ' GWh';
    if (Math.abs(valeurBase) >= 1000)    return fmt(valeurBase / 1000, 2) + ' MWh';
    return fmt(valeurBase, 1) + ' kWh';
  }
  if (famille === 'volume') return fmt(valeurBase, 1) + ' m³';
  return fmt(valeurBase, 1);
}

/**
 * Pouvoir calorifique du gaz naturel, en kWh par m³.
 *
 * Ce n'est PAS une conversion d'unité : c'est une propriété physique du gaz
 * distribué, qui varie avec sa composition et la région (de ~10,7 à ~12,8 sur
 * le réseau français). Les factures GRDF affichent ce « coefficient de
 * conversion » ; 11,2 est la valeur la plus courante et sert de défaut.
 *
 * Sans cette étape, un relevé de gaz en m³ était multiplié directement par un
 * facteur exprimé en kgCO₂e/kWh — les émissions du gaz étaient donc environ
 * onze fois trop basses.
 */
const BC_GAZ_KWH_PAR_M3_DEFAUT = 11.2;
function _bcCoeffGaz() {
  var v = parseFloat(BilanCarboneState.coeffGazKwhParM3);
  if (isFinite(v) && v > 0) return v;
  // Repli sur la valeur persistée, puis sur le défaut national.
  try {
    var stocke = parseFloat(localStorage.getItem('larka_bc_coeff_gaz'));
    if (isFinite(stocke) && stocke > 0) return stocke;
  } catch(e) {}
  return BC_GAZ_KWH_PAR_M3_DEFAUT;
}

/**
 * Consommation ramenée en kWh pour le calcul du CO₂, quelle que soit l'unité
 * du compteur. Retourne null si la conversion est impossible (eau : un m³
 * d'eau ne contient pas d'énergie).
 */
function _bcConsoEnKwh(conso, unite, type) {
  var base = _bcVersBase(conso, unite);
  if (!base) return null;
  if (base.famille === 'energie') return base.valeur;          // déjà des kWh
  if (base.famille === 'volume' && type === 'Gaz') {
    return base.valeur * _bcCoeffGaz();                         // m³ de gaz → kWh
  }
  return null;                                                  // eau, etc.
}

const BilanCarboneState = {
  prixCO2: 80,          // €/tCO₂ — EU ETS par défaut, modifiable dans l'interface
  coeffCO2Chauffage: 0.112, // kgCO₂/kWh — Réseau de chaleur mix moyen (ADEME/DPE), modifiable
  chauffSource: 'methode', // 'methode' = utilise le facteur ADEME/simplifié, 'reseau' = coeff réseau spécifique
  coeffGazKwhParM3: (function() {
    // Persisté hors du localStorage d'UI : c'est un paramètre de calcul, pas
    // une préférence d'affichage.
    try {
      var v = parseFloat(localStorage.getItem('larka_bc_coeff_gaz'));
      if (isFinite(v) && v > 0) return v;
    } catch(e) {}
    return BC_GAZ_KWH_PAR_M3_DEFAUT;
  })(), // kWh/m³ — coefficient de conversion figurant sur les factures GRDF
};
// ═══════════════════════════════════════════════════════════════════════════════
// ── UI du Bilan Carbone — refonte modulaire ─────────────────────────────────
// Choix esthétiques et repliements par module. Les préférences (type de
// graphique, couleurs, période) sont persistées en localStorage ; les états
// « panneau ouvert » restent en session pour ne pas s'imposer à chaque visite.
//
// Modèle de surcharge : chaque module de détail peut « détacher » ses
// paramètres du bandeau global (année, méthode). null = suit le global.
// ═══════════════════════════════════════════════════════════════════════════════
const BilanCarboneUI = {
  _cache: null,
  _def() {
    return {
      chart: {
        type:   'bar',      // 'bar' | 'line' | 'stack'
        metric: 'co2',      // 'co2' | 'prix_ttc' | 'prix_ht' | 'quantite'
        period: 'mois',     // 'mois' | 'trimestre' | 'annee'
        colors: { Electricite:'#f59e0b', Gaz:'#6b7280', Chauffage:'#ef4444', Eau:'#3b82f6', Mobilite:'#059669' },
      },
      energies: { chartOpen: false, chartType: 'bar', prixMode: 'ttc', override: null },
      mobilite: { chartOpen: false, chartType: 'bar', override: null },
      methodoOpen: false,
      customizeOpen: false,
      aideConsoOpen: false,
      aidePerimOpen: false,
    };
  },
  _load() {
    if (this._cache) return this._cache;
    var def = this._def();
    try {
      var raw = localStorage.getItem('larka_bc_ui');
      if (raw) {
        var p = JSON.parse(raw) || {};
        // Fusion défensive : un schéma stocké obsolète (clé manquante côté def
        // ou l'inverse) ne doit pas mettre l'onglet à genoux.
        Object.keys(def).forEach(function(k) {
          if (typeof def[k] === 'object' && def[k] !== null && p[k] && typeof p[k] === 'object') {
            Object.assign(def[k], p[k]);
            if (def[k].colors && p[k].colors) Object.assign(def[k].colors, p[k].colors);
          } else if (p[k] !== undefined) {
            def[k] = p[k];
          }
        });
      }
    } catch(e) {}
    this._cache = def;
    return def;
  },
  save() { try { localStorage.setItem('larka_bc_ui', JSON.stringify(this._cache)); } catch(e) {} },
  get chart()          { return this._load().chart; },
  get energies()       { return this._load().energies; },
  get mobilite()       { return this._load().mobilite; },
  get methodoOpen()    { return this._load().methodoOpen; },
  set methodoOpen(v)   { this._load().methodoOpen = !!v; this.save(); },
  get customizeOpen()  { return this._load().customizeOpen; },
  set customizeOpen(v) { this._load().customizeOpen = !!v; this.save(); },
  get aideConsoOpen()  { return this._load().aideConsoOpen; },
  set aideConsoOpen(v) { this._load().aideConsoOpen = !!v; this.save(); },
  get aidePerimOpen()  { return this._load().aidePerimOpen; },
  set aidePerimOpen(v) { this._load().aidePerimOpen = !!v; this.save(); },
};

function _bcSetCoeffGaz(v) {
  var n = parseFloat(v);
  if (!isFinite(n) || n <= 0 || n > 20) {
    if (typeof toast === 'function') toast('Coefficient attendu entre 1 et 20 kWh/m³.', 'error');
    return;
  }
  BilanCarboneState.coeffGazKwhParM3 = n;
  try { localStorage.setItem('larka_bc_coeff_gaz', String(n)); } catch(e) {}
  // Le coefficient entre dans le calcul du CO₂ : il faut tout recalculer,
  // pas seulement redessiner.
  if (typeof renderCarbone === 'function') renderCarbone();
}

// ── Aides dépliables ────────────────────────────────────────────────────────
function _bcToggleAideConso() {
  BilanCarboneUI.aideConsoOpen = !BilanCarboneUI.aideConsoOpen;
  var el = document.getElementById('bc_aide_conso');
  if (el) el.style.display = BilanCarboneUI.aideConsoOpen ? 'block' : 'none';
}
function _bcToggleAidePerimetres() {
  BilanCarboneUI.aidePerimOpen = !BilanCarboneUI.aidePerimOpen;
  _bcRenderMobiliteSection();
}

// ── Setters graphique module 2 ──────────────────────────────────────────────
function _bcSetChartType(t) {
  if (['bar','line','stack'].indexOf(t) < 0) return;
  BilanCarboneUI.chart.type = t; BilanCarboneUI.save();
  _bcRenderChartModule();
}
function _bcSetChartMetric(m) {
  if (['co2','prix_ttc','prix_ht','quantite'].indexOf(m) < 0) return;
  BilanCarboneUI.chart.metric = m; BilanCarboneUI.save();
  _bcRenderChartModule();
}
function _bcSetChartPeriod(p) {
  if (['mois','trimestre','annee'].indexOf(p) < 0) return;
  BilanCarboneUI.chart.period = p; BilanCarboneUI.save();
  _bcRenderChartModule();
}
function _bcSetChartColor(type, color) {
  if (!type || !color) return;
  BilanCarboneUI.chart.colors[type] = color; BilanCarboneUI.save();
  _bcRenderChartModule();
  _bcRenderSynthese();
}
function _bcToggleCustomize() {
  BilanCarboneUI.customizeOpen = !BilanCarboneUI.customizeOpen;
  _bcRenderChartModule();
}
function _bcToggleMethodo() {
  BilanCarboneUI.methodoOpen = !BilanCarboneUI.methodoOpen;
  var el = document.getElementById('bc_methodo_detail');
  var chev = document.getElementById('bc_methodo_chev');
  if (el)  el.style.display = BilanCarboneUI.methodoOpen ? 'block' : 'none';
  if (chev) chev.textContent = BilanCarboneUI.methodoOpen ? '▴' : '▾';
}

// ── Toggle graphique de détail (modules 3 et 4) ─────────────────────────────
function _bcToggleModuleChart(module) {
  var s = module === 'energies' ? BilanCarboneUI.energies : BilanCarboneUI.mobilite;
  s.chartOpen = !s.chartOpen; BilanCarboneUI.save();
  if (module === 'energies') _bcRenderDetailEnergies();
  else _bcRenderMobiliteSection();
}
function _bcSetDetailChartType(module, t) {
  if (['bar','line','stack'].indexOf(t) < 0) return;
  var s = module === 'energies' ? BilanCarboneUI.energies : BilanCarboneUI.mobilite;
  s.chartType = t; BilanCarboneUI.save();
  if (module === 'energies') _bcRenderDetailEnergies();
  else _bcRenderMobiliteSection();
}
function _bcSetPrixMode(mode) {
  if (['ht','ttc'].indexOf(mode) < 0) return;
  BilanCarboneUI.energies.prixMode = mode; BilanCarboneUI.save();
  _bcRenderDetailEnergies();
}

// ── Surcharge locale des paramètres d'un module ─────────────────────────────
// Le module se détache du bandeau global : c'est l'échappatoire pour comparer
// deux configurations (ex. mobilité 2023 en ACV pendant que le global est 2024
// en combustion). null = suit à nouveau le global.
function _bcToggleOverride(module) {
  var s = module === 'energies' ? BilanCarboneUI.energies : BilanCarboneUI.mobilite;
  if (s.override) {
    s.override = null;
  } else {
    s.override = {
      annee: (typeof MobiliteConfig !== 'undefined') ? MobiliteConfig.annee : new Date().getFullYear(),
      methode: (typeof MobiliteConfig !== 'undefined') ? MobiliteConfig.methode : 'acv',
      source: (typeof getMethodeCarbone === 'function') ? getMethodeCarbone() : 'officielle',
    };
  }
  BilanCarboneUI.save();
  if (module === 'energies') _bcRenderDetailEnergies();
  else _bcRenderMobiliteSection();
}
function _bcSetOverrideAnnee(module, a) {
  var s = module === 'energies' ? BilanCarboneUI.energies : BilanCarboneUI.mobilite;
  if (!s.override) return;
  s.override.annee = parseInt(a) || s.override.annee; BilanCarboneUI.save();
  if (module === 'energies') _bcRenderDetailEnergies();
  else _bcRenderMobiliteSection();
}
function _bcSetOverrideMethode(module, m) {
  var s = module === 'energies' ? BilanCarboneUI.energies : BilanCarboneUI.mobilite;
  if (!s.override) return;
  s.override.methode = m; BilanCarboneUI.save();
  if (module === 'energies') _bcRenderDetailEnergies();
  else _bcRenderMobiliteSection();
}

// ═══════════════════════════════════════════════════════════════════════════════
// ── Années disponibles — source unique de vérité ────────────────────────────
// Trois gisements distincts alimentent le bilan : les factures énergie, les
// trajets mobilité et les millésimes de facteurs. Le sélecteur d'exercice doit
// refléter leur union, sinon deux pannes se produisent :
//
//  1) la page Mobilité ajoute d'office N, N-1 et N-2 à SON sélecteur, même
//     vides, et mémorise le choix dans MobiliteConfig (localStorage). On
//     revenait donc ici avec une année sans la moindre donnée.
//  2) pire, si cette année mémorisée ne figurait dans aucune liste, aucune
//     <option> n'était marquée selected : le menu affichait la première année
//     pendant que les calculs tournaient sur l'année mémorisée. Le bilan
//     paraissait alors ne contenir que la mobilité.
// ═══════════════════════════════════════════════════════════════════════════════
function _bcAnneesDisponibles() {
  var map = {};
  var add = function(a, cle) {
    a = parseInt(a);
    if (!a || a < 2000 || a > 2100) return;
    if (!map[a]) map[a] = { annee: a, energie: false, mobilite: false, facteurs: false };
    map[a][cle] = true;
  };
  (window._bcAllFactures || []).forEach(function(f) { add(f._annee, 'energie'); });
  if (typeof _anneeLigne === 'function') {
    (window._bcMobiliteData || []).forEach(function(l) { add(_anneeLigne(l), 'mobilite'); });
  }
  (window._bcFacteursMillesimes || []).forEach(function(a) { add(a, 'facteurs'); });

  var liste = Object.keys(map).map(function(k) { return map[k]; })
                              .sort(function(a, b) { return b.annee - a.annee; });
  if (!liste.length) {
    liste = [{ annee: new Date().getFullYear(), energie: false, mobilite: false, facteurs: false }];
  }
  return liste;
}

/** Années réellement chiffrables. Un millésime de facteurs seul ne suffit pas :
 *  il dit avec quoi calculer, pas qu'il y ait quelque chose à calculer. */
function _bcAnneesAvecDonnees() {
  var avec = _bcAnneesDisponibles().filter(function(a) { return a.energie || a.mobilite; });
  return avec.length ? avec : _bcAnneesDisponibles();
}

/** Étiquette d'une option d'année. La liste étant déjà filtrée sur les
 *  exercices qui contiennent quelque chose, l'année seule suffit : détailler
 *  le contenu alourdissait le menu sans rien apprendre. */
function _bcLabelAnnee(d) {
  return String(d.annee);
}

/**
 * Exercice effectivement utilisé. Recale MobiliteConfig si l'année mémorisée
 * ne correspond à rien, pour que le menu et les calculs racontent la même
 * histoire. Retourne l'année retenue.
 */
function _bcAnneeEffective() {
  var courante = (typeof MobiliteConfig !== 'undefined') ? MobiliteConfig.annee : new Date().getFullYear();
  var avecDonnees = _bcAnneesAvecDonnees();
  var ok = avecDonnees.some(function(d) { return d.annee === courante; });
  if (ok) return courante;
  var cible = avecDonnees[0].annee;
  if (typeof MobiliteConfig !== 'undefined') MobiliteConfig.annee = cible;
  return cible;
}

/**
 * Charge les trajets AVANT le premier rendu HTML. _bcBuildMobiliteSection le
 * faisait déjà, mais trop tard : le sélecteur d'année était dessiné avant, donc
 * aveugle aux années qui n'existent que côté mobilité.
 */
async function _bcPreloadMobilite() {
  if (window._bcMobiliteData) return;
  try {
    var role = App.currentUser && App.currentUser.Role;
    window._bcMobiliteData = (role === 'Admin' || role === 'Gestionnaire')
      ? await MobiliteApi.adminAll()
      : await MobiliteApi.getAll();
  } catch (e) {
    // Mobilité indisponible : on continue avec l'énergie seule plutôt que
    // de faire échouer tout l'onglet.
    window._bcMobiliteData = [];
  }
}

// ── Export CSV du bilan (année globale) ─────────────────────────────────────
function _bcExportCsv() {
  var yd  = window._bcYearData || {};
  var yt  = window._bcYearTotals || {};
  var an  = _bcAnneeEffective();
  var types = window._bcTypesActifs || [];
  var lignes = [];
  lignes.push(['Poste','Consommation','Unité','Coût HT (€)','Coût TTC (€)','CO2 (kg)']);
  types.forEach(function(t) {
    var d = (yd[an] && yd[an][t]) || { conso:0, coutHT:0, coutTTC:0, co2:0 };
    // yearData cumule les consommations brutes, toutes unités confondues pour
    // un même type. On réagrège ici depuis les factures pour exporter une
    // valeur dans une unité unique et explicite.
    var base = null, fam = '';
    (window._bcAllFactures || []).forEach(function(f) {
      if (f._type !== t || f._annee !== an) return;
      var b = _bcVersBase(f.Consommation, f._unite);
      if (!b) return;
      fam = b.famille; base = (base || 0) + b.valeur;
    });
    var uniteExport = fam ? BC_UNITE_BASE[fam] : ((typeof ENERGIE_CONFIG !== 'undefined' && ENERGIE_CONFIG[t] && ENERGIE_CONFIG[t].unite) || '');
    lignes.push([t, base !== null ? base : d.conso, uniteExport, d.coutHT, d.coutTTC, d.co2]);
  });
  var mobKg = 0;
  var mob = window._bcMobiliteData || [];
  if (typeof _co2Annuel === 'function' && typeof _anneeLigne === 'function') {
    var meth = (typeof MobiliteConfig !== 'undefined') ? MobiliteConfig.methode : 'acv';
    // Mêmes filtres que l'affichage : un export qui ne correspond pas à ce
    // qui est à l'écran est un piège.
    mob.filter(function(l){ return _anneeLigne(l) === an; })
       .filter(function(l){ return (typeof FiltresEngine === 'undefined') || FiltresEngine.matchRow('bilan_carbone', l); })
       .forEach(function(l){ mobKg += _co2Annuel(l, meth); });
  }
  lignes.push(['Mobilité agents','','','','', mobKg]);
  var tot = yt[an] || { coutHT:0, coutTTC:0, co2:0 };
  lignes.push([]);
  lignes.push(['TOTAL', '', '', tot.coutHT, tot.coutTTC, tot.co2 + mobKg]);
  var csv = lignes.map(function(r) {
    return r.map(function(v) {
      if (v === null || v === undefined) return '';
      var s = String(v).replace(/"/g, '""');
      return /[";\n]/.test(s) ? '"' + s + '"' : s;
    }).join(';');
  }).join('\r\n');
  var blob = new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8' });
  var url  = URL.createObjectURL(blob);
  var a = document.createElement('a');
  a.href = url; a.download = 'bilan-carbone-' + an + '.csv';
  document.body.appendChild(a); a.click();
  setTimeout(function(){ document.body.removeChild(a); URL.revokeObjectURL(url); }, 100);
  if (typeof toast === 'function') toast('Export CSV téléchargé.', 'success');
}


async function _renderBilanCarbone(c) {
  const compteurs = await EnergieApi.getCompteurs();
  const facteurs  = await EnergieApi.getFacteursCarbone(getMethodeCarbone());
  // Contrats requis par le module 3 (colonne « Contrat » de la table détail
  // énergies). Un échec de chargement est silencieux — la colonne affichera
  // « — Aucun — » plutôt que casser tout le bilan.
  const contrats  = await (typeof ContratsApi !== 'undefined' ? ContratsApi.getAll() : Promise.resolve([])).catch(function(){ return []; });
  window._bcContrats = contrats;

  const fmtMon = v => new Intl.NumberFormat('fr-FR',{style:'currency',currency:'EUR'}).format(v||0);
  const fmtNum = v => new Intl.NumberFormat('fr-FR',{maximumFractionDigits:1}).format(v||0);
  const fmtCO2 = v => {
    if (!v || v === 0) return '—';
    if (v >= 1000) return fmtNum(v/1000) + ' t';
    return fmtNum(v) + ' kg';
  };
  const fmtPct = v => {
    if (v === null || v === undefined || !isFinite(v)) return '—';
    const sign = v > 0 ? '+' : '';
    return sign + v.toFixed(1) + '%';
  };
  const pctColor = v => {
    if (v === null || !isFinite(v)) return 'var(--gray-text)';
    return v < 0 ? '#16a34a' : v > 0 ? '#dc2626' : 'var(--gray-text)';
  };

  const typeIcons  = { Electricite:'⚡', Eau:'💧', Chauffage:'🔥', Gaz:'🔵' };
  const typeColors = { Electricite:'#f59e0b', Eau:'#3b82f6', Chauffage:'#ef4444', Gaz:'#6b7280' };
  const typesActifs = [...new Set(compteurs.map(c => c.Type))].filter(t => ENERGIE_CONFIG[t]);

  // ── Collecter TOUTES les factures de tous les compteurs ──
  const allFactures = [];
  for (const cpt of compteurs) {
    const releves = await EnergieApi.getRelevers(cpt.Id);
    const cfg = ENERGIE_CONFIG[cpt.Type] || {};
    releves.filter(r => r.Type === 'Facture').forEach(r => {
      const annee = r.Date ? parseInt(r.Date.substring(0, 4)) : null;
      if (!annee) return;
      allFactures.push({
        ...r,
        _type: cpt.Type,
        _compteurNom: cpt.Nom,
        _compteurId: cpt.Id,
        // Unité du COMPTEUR en priorité : c'est elle qui décrit la donnée
        // saisie. L'unité du type n'est qu'un défaut de création de fiche.
        _unite: _bcNormUnite(cpt.Unite || cfg.unite || ''),
        _annee: annee,
      });
    });
  }

  // ── Facteurs par année (indexés pour lookup rapide) ──
  const methodeActive = getMethodeCarbone();
  const facteursParAnnee = {};
  facteurs.forEach(f => {
    if (!facteursParAnnee[f.Annee]) facteursParAnnee[f.Annee] = {};
    facteursParAnnee[f.Annee][f.Type] = f;
  });
  // Trouver le facteur le plus proche <= année donnée
  const _findFacteur = (type, annee) => {
    if (facteursParAnnee[annee] && facteursParAnnee[annee][type]) return facteursParAnnee[annee][type];
    const sorted = Object.keys(facteursParAnnee).map(Number).filter(a => a <= annee && facteursParAnnee[a][type]).sort((a,b) => b - a);
    return sorted.length > 0 ? facteursParAnnee[sorted[0]][type] : null;
  };
  // Calculer CO₂ : TOUJOURS recalcule dynamiquement avec le facteur de la méthode active.
  // Le champ Carbone en BDD est ignoré pour garantir la cohérence avec la méthode choisie.
  const _calcCO2 = (f) => {
    const conso = parseFloat(f.Consommation);
    if (!conso || conso <= 0) return 0;

    // Conversion en kWh selon l'unité RÉELLE du compteur, et non selon une
    // hypothèse figée. L'ancien code multipliait le chauffage par 1000 en
    // supposant des MWh : un compteur chauffage saisi en kWh donnait alors un
    // CO₂ mille fois trop élevé. Le gaz, lui, était pris en m³ et multiplié
    // par un facteur au kWh — dix fois trop bas.
    const kwh = _bcConsoEnKwh(conso, f._unite, f._type);
    if (kwh === null) return 0;   // eau : pas d'énergie, donc pas de CO₂ ici

    // Chauffage sur réseau spécifique : coefficient saisi par l'utilisateur.
    if (f._type === 'Chauffage' && BilanCarboneState.chauffSource === 'reseau' && BilanCarboneState.coeffCO2Chauffage > 0) {
      return kwh * BilanCarboneState.coeffCO2Chauffage;
    }
    // 1. Chercher le facteur stocké en BDD pour la méthode active
    const fc = _findFacteur(f._type, f._annee);
    if (fc && fc.FacteurCO2) {
      const facteur = parseFloat(fc.FacteurCO2);
      if (!isNaN(facteur) && facteur > 0) return kwh * facteur;
    }
    // 2. Fallback sur valeurs codées en dur selon la méthode active
    const def = methodeActive === 'simplifiee' ? IMPACT_CO2_FACTEURS[f._type] : ADEME_FACTEURS[f._type];
    if (!def) return 0;
    return kwh * def.facteurCO2;
  };

  // ── Grouper par année et type ──
  const yearData = {};
  const anneesSet = new Set();
  let nbSansCarboneStocke = 0;
  allFactures.forEach(f => {
    anneesSet.add(f._annee);
    if (!yearData[f._annee]) yearData[f._annee] = {};
    if (!yearData[f._annee][f._type]) yearData[f._annee][f._type] = { conso:0, co2:0, coutHT:0, coutTTC:0, nb:0 };
    const d = yearData[f._annee][f._type];
    d.conso   += parseFloat(f.Consommation) || 0;
    d.co2     += _calcCO2(f);
    d.coutHT  += parseFloat(f.MontantHT) || 0;
    d.coutTTC += parseFloat(f.Montant) || 0;
    d.nb++;
    // Compter les factures sans carbone stocké en BDD (utile pour proposer le recalcul)
    const storedC = parseFloat(f.Carbone);
    if (isNaN(storedC) || storedC <= 0) nbSansCarboneStocke++;
  });
  const annees = [...anneesSet].sort((a,b) => b - a);

  // Nombre de mois distincts réellement renseignés, par année : sans lui,
  // impossible de dire si un total énergie couvre l'exercice ou un seul mois.
  const moisParAnnee = {};
  allFactures.forEach(f => {
    const d = f.Date || f.DateFacture || f.DatePeriode || '';
    const m = String(d).substring(5, 7);
    if (!m) return;
    if (!moisParAnnee[f._annee]) moisParAnnee[f._annee] = new Set();
    moisParAnnee[f._annee].add(m);
  });

  // ── Totaux par année ──
  const yearTotals = {};
  annees.forEach(a => {
    yearTotals[a] = { conso:0, co2:0, coutHT:0, coutTTC:0, nb:0 };
    Object.values(yearData[a] || {}).forEach(d => {
      yearTotals[a].conso   += d.conso;
      yearTotals[a].co2     += d.co2;
      yearTotals[a].coutHT  += d.coutHT;
      yearTotals[a].coutTTC += d.coutTTC;
      yearTotals[a].nb      += d.nb;
    });
  });
  // Exposition pour les modules de rendu asynchrones (module 1, module 2, ...)
  window._bcYearData     = yearData;
  window._bcYearTotals   = yearTotals;
  window._bcMoisParAnnee = {};
  Object.keys(moisParAnnee).forEach(function(a) { window._bcMoisParAnnee[a] = moisParAnnee[a].size; });
  window._bcAllFactures  = allFactures;
  window._bcCompteurs    = compteurs;
  window._bcTypesActifs  = typesActifs;
  window._bcTypeIcons    = typeIcons;
  window._bcTypeColors   = typeColors;
  window._bcAnneesEnergie = annees;
  window._bcCalcCO2       = _calcCO2;
  // Millésimes de facteurs disponibles (paramétrage serveur) — ils complètent
  // la liste d'années sans pour autant prouver qu'il y a des données.
  window._bcFacteursMillesimes = facteurs.map(function(f) { return parseInt(f.Annee); })
                                         .filter(function(a) { return a > 2000 && a < 2100; });

  // Trajets chargés MAINTENANT : le sélecteur d'exercice ci-dessous doit voir
  // les années qui n'existent que côté mobilité.
  await _bcPreloadMobilite();
  window._bcAnnees = _bcAnneesDisponibles().map(function(d) { return d.annee; });

  // ═══════════════════════════════════════════════════════════════════════════
  // ── HTML — refonte modulaire ──────────────────────────────────────────────
  // Ordre : bandeau source → bilan total → graphique comparatif → détails
  // énergies → détail mobilité → pied facteurs d'émission.
  // ═══════════════════════════════════════════════════════════════════════════
  const anneeGlobale = _bcAnneeEffective();
  const methodeSrc   = getMethodeCarbone();               // 'officielle' | 'simplifiee'
  const anneesOptions = _bcAnneesAvecDonnees();

  // Millésime mobilité chargé avant le premier paint : sinon la première
  // peinture utilise les facteurs de repli et le bandeau annonce « valeurs
  // intégrées » à tort, jusqu'au rafraîchissement différé.
  if (typeof loadFacteursCarbone === 'function') {
    try { await loadFacteursCarbone(anneeGlobale); } catch (e) {}
  }

  // ── Bandeau source + paramètres globaux (module 0) ──────────────────────
  const chipOfficielle = methodeSrc === 'officielle';
  const facteurCourant = facteurs.find(function(f){ return f.Annee === anneeGlobale && f.Type === 'Electricite'; })
                     || facteurs.find(function(f){ return f.Type === 'Electricite'; });
  const anneeFacteurAffichee = (facteurCourant && facteurCourant.Annee) || anneeGlobale;
  const millesimeMob = (typeof FACTEURS_SOURCE !== 'undefined' && FACTEURS_SOURCE.anneeFacteurs) || anneeGlobale;
  const sourceMobLabel = (typeof FACTEURS_SOURCE !== 'undefined' && FACTEURS_SOURCE.libelle) || 'Valeurs intégrées';

  let html = '';
  html += '<div class="card" style="margin-bottom:16px;padding:0;overflow:hidden">';

  // Ligne du haut — badge source (cliquable pour déplier la méthodo) + bascule
  html += '<div style="display:flex;align-items:center;gap:10px;padding:12px 20px;flex-wrap:wrap;background:' + (chipOfficielle?'#f0fdf4':'#f5f3ff') + ';border-bottom:1px solid var(--gray-border)">';
  html += '  <div onclick="_bcToggleMethodo()" style="display:inline-flex;align-items:center;gap:8px;padding:6px 12px;border-radius:20px;background:var(--card-bg);border:1px solid ' + (chipOfficielle?'#86efac':'#c4b5fd') + ';cursor:pointer;font-size:12px;font-weight:700;color:' + (chipOfficielle?'#16a34a':'#7c3aed') + '">';
  html += '    ' + (chipOfficielle ? '🏛️ ADEME officielle' : '📦 Version simplifiée');
  html += '    <span style="font-weight:400;color:var(--gray-text);font-size:11px">· millésime ' + anneeFacteurAffichee + '</span>';
  html += '    <span id="bc_methodo_chev" style="font-size:10px;margin-left:2px">' + (BilanCarboneUI.methodoOpen ? '▴' : '▾') + '</span>';
  html += '  </div>';
  html += '  <button onclick="setMethodeCarbone(\'' + (chipOfficielle?'simplifiee':'officielle') + '\');renderCarbone()" ';
  html += '     style="padding:5px 10px;font-size:11px;border-radius:16px;background:transparent;border:1px solid var(--gray-border);color:var(--gray-text);cursor:pointer" ';
  html += '     title="Basculer entre méthode officielle ADEME et méthode simplifiée grand public">';
  html += '    Basculer → ' + (chipOfficielle ? '📦 Simplifiée' : '🏛️ Officielle');
  html += '  </button>';
  html += '  <div style="flex:1"></div>';
  html += '  <span style="font-size:11px;color:var(--gray-text);font-style:italic">' + valides_len(facteurs) + ' facteur(s) énergie · millésime mobilité ' + millesimeMob + '</span>';
  html += '</div>';

  // Détail méthodologique (déplié via _bcToggleMethodo)
  html += '<div id="bc_methodo_detail" style="display:' + (BilanCarboneUI.methodoOpen ? 'block' : 'none') + ';padding:12px 20px;background:var(--gray-bg);border-bottom:1px solid var(--gray-border);font-size:12px;line-height:1.7">';
  html += '  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px 20px">';
  html += '    <div><strong style="color:var(--navy)">Source</strong><br>';
  html += (chipOfficielle
    ? '<a href="https://base-empreinte.ademe.fr" target="_blank" style="color:#16a34a">Base Empreinte® ADEME</a> — API officielle. Facteurs ACV précis par identifiant, conforme au bilan GES réglementaire (article L. 229-25 du code de l\'environnement).'
    : '<a href="https://impactco2.fr" target="_blank" style="color:#7c3aed">Impact CO2 / Datagir</a> — Moyennes nationales vulgarisées à partir des données ADEME. Estimation rapide sans dépendance API externe.'
  ) + '</div>';
  html += '    <div><strong style="color:var(--navy)">Facteurs d\'émission ' + anneeFacteurAffichee + '</strong><br>';
  var _fmtF = function(t) {
    var f = facteurs.find(function(x){ return x.Annee === anneeFacteurAffichee && x.Type === t; })
         || facteurs.find(function(x){ return x.Type === t; });
    if (!f) {
      var def = chipOfficielle ? ADEME_FACTEURS[t] : IMPACT_CO2_FACTEURS[t];
      return def ? (def.facteurCO2 + ' ' + def.unite + ' (repli)') : 'non défini';
    }
    return f.FacteurCO2 + ' ' + (f.Unite || 'kgCO2e/kWh');
  };
  html += '⚡ Électricité : ' + _fmtF('Electricite') + '<br>';
  html += '🔥 Chauffage : '   + _fmtF('Chauffage')   + '<br>';
  html += '🔵 Gaz : '         + _fmtF('Gaz')         + '</div>';
  html += '    <div><strong style="color:var(--navy)">Périmètre mobilité</strong><br>Facteurs issus de : <em>' + sourceMobLabel + '</em>. Le périmètre retenu (ACV complet, hors construction, combustion, CO₂ seul) se règle dans les paramètres globaux ci-dessous.</div>';
  html += '    <div><strong style="color:var(--navy)">Règle des millésimes</strong><br>Facteurs figés par année. Une facture ' + anneeFacteurAffichee + ' utilise le facteur ' + anneeFacteurAffichee + ', pas celui du jour — même si les valeurs futures changent.</div>';
  html += '  </div>';

  // ── Conversion du gaz ─────────────────────────────────────────────────
  // Affiché seulement si un compteur gaz est relevé en volume : sinon c'est
  // un réglage sans objet qui encombre le panneau.
  var _gazEnVolume = compteurs.some(function(c) {
    return c.Type === 'Gaz' && _bcFamilleUnite(_bcUniteCompteur(c)) === 'volume';
  });
  if (_gazEnVolume) {
    html += '  <div style="margin-top:12px;padding:10px 12px;border-radius:8px;background:var(--card-bg);border:1px solid var(--gray-border);border-left:3px solid #6b7280">';
    html += '    <div style="font-weight:700;color:var(--navy);font-size:12px;margin-bottom:3px">🔵 Conversion du gaz — m³ vers kWh</div>';
    html += '    <div style="color:var(--gray-text);font-size:11px;line-height:1.55;margin-bottom:6px">';
    html += '      Vos compteurs gaz sont relevés en m³, mais le facteur d\'émission s\'exprime au kWh. La conversion passe par le <strong>pouvoir calorifique</strong> du gaz distribué — ce n\'est pas un simple changement d\'unité, la valeur dépend de la composition du gaz et de votre zone. Elle figure sur vos factures GRDF sous le nom « coefficient de conversion » (généralement entre 10,7 et 12,8).';
    html += '    </div>';
    html += '    <label style="font-size:11px;display:inline-flex;align-items:center;gap:6px">Coefficient :';
    html += '      <input type="number" step="0.1" min="1" max="20" value="' + _bcCoeffGaz() + '" onchange="_bcSetCoeffGaz(this.value)" style="width:70px;padding:3px 6px;border-radius:6px;border:1px solid var(--gray-border);background:var(--card-bg);color:var(--text);font-family:inherit">';
    html += '      <span style="color:var(--gray-text)">kWh/m³</span>';
    html += '    </label>';
    html += '  </div>';
  }

  // ── Les 3 scopes ──────────────────────────────────────────────────────
  // Le terme est partout dans la réglementation mais rarement défini dans les
  // outils. Sans cette grille, « scope 3 » ne dit rien à un gestionnaire, et
  // surtout on ne sait pas quel poste de CET écran tombe dans quel périmètre.
  html += '  <div style="margin-top:14px;padding-top:12px;border-top:1px solid var(--gray-border)">';
  html += '    <div style="font-weight:700;color:var(--navy);margin-bottom:2px">Qu\'est-ce qu\'un « scope » ?</div>';
  html += '    <div style="color:var(--gray-text);margin-bottom:10px">Le GHG Protocol, repris par la réglementation française, classe les émissions selon leur degré de responsabilité directe. Trois périmètres, du plus direct au plus diffus :</div>';
  html += '    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:10px">';
  [
    ['1', '#ef4444', 'Émissions directes',
     'Ce que brûlent vos propres installations et véhicules. La combustion a lieu chez vous, sur un équipement que vous possédez.',
     '🔵 Gaz naturel · flotte thermique'],
    ['2', '#3b82f6', 'Énergie achetée',
     'L\'énergie est produite ailleurs mais consommée par vous. La combustion a lieu à la centrale ou à la chaufferie urbaine, pas sur votre site.',
     '⚡ Électricité · 🔥 Réseau de chaleur'],
    ['3', '#059669', 'Autres émissions indirectes',
     'Tout le reste de la chaîne de valeur : ce que votre activité provoque sans que vous en ayez la maîtrise directe. C\'est en général le poste le plus lourd.',
     '🚗 Déplacements des agents'],
  ].forEach(function(s) {
    html += '<div style="padding:10px 12px;border-radius:8px;background:var(--card-bg);border:1px solid var(--gray-border);border-left:3px solid ' + s[1] + '">';
    html += '  <div style="font-weight:700;color:' + s[1] + ';font-size:12px">Scope ' + s[0] + ' — ' + s[2] + '</div>';
    html += '  <div style="color:var(--gray-text);font-size:11px;line-height:1.55;margin:4px 0 6px">' + s[3] + '</div>';
    html += '  <div style="font-size:11px;color:var(--text);font-weight:500">Dans cet onglet : ' + s[4] + '</div>';
    html += '</div>';
  });
  html += '    </div>';
  html += '    <div style="margin-top:8px;font-size:11px;color:var(--gray-text);font-style:italic">Les scopes 1 et 2 sont obligatoires dans un BEGES réglementaire ; le scope 3 l\'est depuis 2022 pour les entités concernées. Cet onglet couvre les trois, dans la limite des données saisies.</div>';
  html += '  </div>';

  html += '</div>';

  // Ligne du bas — paramètres globaux (Année, Type de conso, Recherche avancée, Export)
  var styleSelectG = 'font-size:12px;padding:5px 10px;border-radius:6px;border:1px solid var(--gray-border);background:var(--card-bg);color:var(--text);cursor:pointer;font-family:inherit';
  html += '<div style="display:flex;align-items:center;gap:8px;padding:10px 20px;flex-wrap:wrap">';
  html += '  <span style="font-size:11px;font-weight:700;color:var(--gray-text);text-transform:uppercase;letter-spacing:.5px">Paramètres globaux</span>';
  html += '  <label style="display:inline-flex;align-items:center;gap:4px;font-size:12px;color:var(--text)">📅 <select onchange="_bcSetMobiliteAnnee(this.value)" title="Exercice — seules les années contenant des données sont proposées" style="' + styleSelectG + '">';
  anneesOptions.forEach(function(d) {
    html += '<option value="' + d.annee + '"' + (d.annee === anneeGlobale ? ' selected' : '') + '>' + _bcLabelAnnee(d) + '</option>';
  });
  html += '  </select></label>';
  if (typeof METHODES !== 'undefined') {
    html += '  <label style="display:inline-flex;align-items:center;gap:4px;font-size:12px;color:var(--text)">⚗️ <select onchange="_bcSetMobiliteMethode(this.value)" title="Type de consommation / périmètre de calcul mobilité" style="' + styleSelectG + '">';
    METHODES.forEach(function(m) {
      html += '<option value="' + m.id + '"' + (m.id === MobiliteConfig.methode ? ' selected' : '') + '>'
           +  m.label + (m.source === 'derive' ? ' *' : '') + '</option>';
    });
    html += '  </select></label>';
  }
  html += '  <label style="display:inline-flex;align-items:center;gap:4px;font-size:12px;color:var(--text)" title="Comment additionner une énergie constatée et une mobilité projetée">';
  html += '    <select onchange="_bcSetConsolidation(this.value)" style="' + styleSelectG + '">';
  [['constate','Énergie constatée'],['annualise','Énergie annualisée'],['separe','Ne pas consolider']].forEach(function(o) {
    html += '<option value="' + o[0] + '"' + (BilanConsolidation.mode === o[0] ? ' selected' : '') + '>' + o[1] + '</option>';
  });
  html += '    </select></label>';
  html += '  <button onclick="_bcToggleAideConso()" title="Que veulent dire ces trois modes ?" style="width:20px;height:20px;border-radius:50%;border:1px solid var(--gray-border);background:var(--card-bg);color:var(--gray-text);cursor:pointer;font-size:11px;font-weight:700;line-height:1;padding:0">?</button>';
  html += '  <div style="flex:1"></div>';
  html += '  ' + (typeof FiltresEngine !== 'undefined' ? FiltresEngine.renderButton('bilan_carbone') : '');
  html += '  <button class="btn btn-ghost btn-sm" onclick="_bcExportCsv()" title="Exporter le bilan (CSV)" style="font-size:11px">⬇️ Exporter</button>';
  html += '</div>';

  // ── Aide : les 3 modes de consolidation ────────────────────────────────
  // Le problème de fond : l'énergie est CONSTATÉE (ce qui a été facturé, donc
  // souvent partiel en cours d'année) tandis que la mobilité est PROJETÉE
  // (trajets récurrents × jours ouvrés, donc toujours annuelle). Les
  // additionner brut donne un nombre qui n'est ni l'un ni l'autre.
  html += '<div id="bc_aide_conso" style="display:' + (BilanCarboneUI.aideConsoOpen ? 'block' : 'none') + ';padding:12px 20px;background:var(--gray-bg);border-top:1px solid var(--gray-border);font-size:12px;line-height:1.6">';
  html += '  <div style="font-weight:700;color:var(--navy);margin-bottom:2px">Pourquoi ce réglage existe</div>';
  html += '  <div style="color:var(--gray-text);margin-bottom:10px">Les deux moitiés du bilan ne se mesurent pas de la même façon. L\'<strong>énergie est constatée</strong> : elle vient des factures réellement saisies, donc en cours d\'année elle ne couvre que les mois déjà facturés. La <strong>mobilité est projetée</strong> : un trajet domicile-travail quotidien est multiplié par le nombre de jours ouvrés de l\'année entière. Additionner 4 mois d\'électricité avec 12 mois de trajets donnerait un total trompeur. Ce réglage dit quoi faire de cet écart.</div>';
  html += '  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:10px">';
  [
    ['Énergie constatée', '#16a34a',
     'On additionne tel quel : l\'énergie réellement facturée + la mobilité annuelle. Le total est prudent côté énergie mais hétérogène, et un avertissement le signale tant que l\'année est incomplète.',
     'Défaut. Bon pour suivre ce qui est effectivement payé et émis à ce jour.'],
    ['Énergie annualisée', '#2b7be6',
     'L\'énergie est extrapolée au prorata des mois renseignés (4 mois saisis → × 12/4). Les deux moitiés couvrent alors la même durée.',
     'Bon pour estimer où finira l\'exercice en cours, ou pour comparer à une année complète.'],
    ['Ne pas consolider', '#7a8a9e',
     'Aucun total unique : énergie et mobilité restent affichées séparément.',
     'Bon quand vous refusez toute approximation, ou pour un rapport qui présente les postes distinctement.'],
  ].forEach(function(m) {
    html += '<div style="padding:10px 12px;border-radius:8px;background:var(--card-bg);border:1px solid var(--gray-border);border-left:3px solid ' + m[1] + '">';
    html += '  <div style="font-weight:700;color:' + m[1] + ';font-size:12px">' + m[0] + '</div>';
    html += '  <div style="color:var(--gray-text);font-size:11px;line-height:1.55;margin:4px 0 6px">' + m[2] + '</div>';
    html += '  <div style="font-size:11px;color:var(--text)">' + m[3] + '</div>';
    html += '</div>';
  });
  html += '  </div>';
  html += '  <div style="margin-top:8px;font-size:11px;color:var(--gray-text);font-style:italic">Sur un exercice révolu et complet, les trois modes donnent le même résultat : le choix ne pèse que sur l\'année en cours.</div>';
  html += '</div>';

  // Panneau de filtres avancés (rendu par FiltresEngine, ouvert par le bouton)
  html += (typeof FiltresEngine !== 'undefined' ? FiltresEngine.renderPanel('bilan_carbone') : '');
  html += '</div>';   // ── fin de la carte « bandeau source + paramètres » ──

  // ── Bandeau alerte (existant, conservé) : factures sans CO₂ stocké ─────
  if (nbSansCarboneStocke > 0) {
    html += '<div style="background:#fffbeb;border:1px solid #fbbf24;border-radius:8px;padding:10px 16px;margin-bottom:16px;font-size:12px;color:#92400e;display:flex;align-items:center;gap:10px;flex-wrap:wrap">';
    html += '  <span>⚠️ <strong>' + nbSansCarboneStocke + '</strong> facture(s) sans CO₂ enregistré en base.</span>';
    html += '  <span style="color:#78716c">Les valeurs affichées ci-dessous sont <strong>calculées dynamiquement</strong> (méthode ' + (methodeSrc === 'simplifiee' ? 'simplifiée' : 'officielle') + ').</span>';
    html += '  <button class="btn btn-sm" onclick="recalculerCarboneTout(getMethodeCarbone())" style="background:#0e7490;color:white;border:none;font-size:11px;margin-left:auto">🔄 Enregistrer le CO₂ sur tout</button>';
    html += '</div>';
  }

  // ── Placeholders des modules dynamiques ─────────────────────────────────
  // Module 1 (bilan total consolidé) et module 2 (graphique comparatif) sont
  // remplis après le premier paint — module 1 attend le chargement asynchrone
  // du millésime mobilité (voir _bcBuildMobiliteSection).
  html += '<div id="bc_synthese"></div>';
  html += '<div id="bc_chart_module"></div>';
  html += '<div id="bc_detail_energies"></div>';

  // ── Module 4 : détail mobilité ──────────────────────────────────────────
  html += await _bcBuildMobiliteSection();

  // ── Pied : facteurs d'émission (conservé de l'existant) ─────────────────
  const anneeFacteur = new Date().getFullYear();
  html += _bcFacteursFooterHtml(facteurs, typeIcons, anneeFacteur);

  c.innerHTML = html;

  // Rendus dynamiques post-innerHTML
  _bcRenderChartModule();
  _bcRenderDetailEnergies();
  _bcRenderSynthese();
}

// ── Helper : compte non-null pour la ligne d'info ──────────────────────────
function valides_len(facteurs) {
  return (facteurs || []).filter(function(f) {
    return f.Annee && f.FacteurCO2 && parseFloat(f.FacteurCO2) > 0;
  }).length;
}

// ── Pied de page des facteurs — extrait pour lisibilité ────────────────────
function _bcFacteursFooterHtml(facteurs, typeIcons, anneeFacteur) {
  const meth  = getMethodeCarbone();
  const isSimp = meth === 'simplifiee';
  const couleur = isSimp ? '#7c3aed' : '#16a34a';
  const bgHead  = isSimp ? '#f5f3ff' : '#f0fdf4';
  var h = '';
  h += '<div class="card" style="margin-bottom:16px">';
  h += '  <div class="card-header" style="cursor:pointer" onclick="var e=document.getElementById(\'blocFacteursDetail\');e.style.display=e.style.display===\'none\'?\'block\':\'none\'">';
  h += '    <div class="card-title" style="font-size:14px;color:' + couleur + '">⚙️ Facteurs d\'émission CO₂';
  h += '      <span style="font-size:11px;font-weight:400;margin-left:8px;padding:2px 8px;border-radius:10px;background:' + bgHead + ';color:' + couleur + ';border:1px solid ' + (isSimp?'#c4b5fd':'#86efac') + '">' + (isSimp?'📦 Simplifiée':'🏛️ Officielle') + '</span>';
  h += '    </div>';
  h += '    <div style="display:flex;gap:8px;align-items:center">';
  h += '      ' + (isSimp
    ? '<button class="btn btn-primary btn-sm" onclick="event.stopPropagation();syncCarboneSimplifiee(' + anneeFacteur + ')" id="btnSyncSimplifie" style="font-size:11px;background:#7c3aed;border-color:#7c3aed">📦 Sync simplifiée ' + anneeFacteur + '</button>'
    : '<button class="btn btn-primary btn-sm" onclick="event.stopPropagation();syncCarboneADEME('     + anneeFacteur + ')" id="btnSyncCarbone"   style="font-size:11px;background:#16a34a;border-color:#16a34a">🌐 Sync ADEME '     + anneeFacteur + '</button>');
  h += '      <button class="btn btn-sm" onclick="event.stopPropagation();recalculerCarboneTout(getMethodeCarbone())" style="font-size:11px;background:#0e7490;border-color:#0e7490;color:white" title="Recalculer le CO₂ sur toutes les factures/relevés">🔄 Recalculer CO₂</button>';
  h += '      <button class="btn btn-ghost btn-sm" onclick="event.stopPropagation();openFacteursCarbone()" style="font-size:11px;border:1px solid ' + couleur + ';color:' + couleur + '">📋 Gérer facteurs</button>';
  h += '      <span style="font-size:18px;color:var(--gray-text)">▾</span>';
  h += '    </div>';
  h += '  </div>';
  h += '  <div id="blocFacteursDetail" style="display:none;padding:12px 20px 16px">';
  if (facteurs.length === 0) {
    h += '    <div style="padding:16px;text-align:center;color:var(--gray-text);font-size:13px">Aucun facteur ' + (isSimp?'simplifié':'officiel') + ' enregistré. Cliquez <strong>' + (isSimp?'Sync simplifiée':'Sync ADEME') + '</strong> pour charger les valeurs.</div>';
  } else {
    h += '    <table style="width:100%;border-collapse:collapse;font-size:12px">';
    h += '      <thead><tr style="background:' + bgHead + '">';
    h += '        <th style="padding:6px 10px;text-align:left">Année</th>';
    h += '        <th style="padding:6px 10px;text-align:left">Type</th>';
    h += '        <th style="padding:6px 10px;text-align:right">Facteur</th>';
    h += '        <th style="padding:6px 10px;text-align:left">Unité</th>';
    h += '        <th style="padding:6px 10px;text-align:left">Source</th>';
    h += '      </tr></thead><tbody>';
    facteurs.forEach(function(f) {
      h += '        <tr style="border-bottom:1px solid #e2e8f0">';
      h += '          <td style="padding:5px 10px;font-weight:600">' + f.Annee + '</td>';
      h += '          <td style="padding:5px 10px">' + (typeIcons[f.Type]||'') + ' ' + escHtml(f.Type||'') + '</td>';
      h += '          <td style="padding:5px 10px;text-align:right;font-weight:700;color:' + couleur + '">' + f.FacteurCO2 + '</td>';
      h += '          <td style="padding:5px 10px;color:var(--gray-text)">' + escHtml(f.Unite||'—') + '</td>';
      h += '          <td style="padding:5px 10px;color:var(--gray-text);font-size:11px;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + (f.NomADEME||'') + '">' + (f.Source||'—') + '</td>';
      h += '        </tr>';
    });
    h += '      </tbody></table>';
  }
  h += '  </div>';
  h += '</div>';
  return h;
}


// ── Page Bilan Carbone (standalone) ─────────────────────────────────────────
async function renderCarbone() {
  document.getElementById('topbarActions').innerHTML = '';
  const c = document.getElementById('mainContent');
  showLoading(c);
  // Vider le cache mobilité pour forcer le rechargement depuis l'API
  window._bcMobiliteData = null;
  try {
    await _renderBilanCarbone(c);
  } catch(e) { c.innerHTML = errorHtml(e.message); }
}

// ═══════════════════════════════════════════════════════════════════════════════
// ── Section Mobilité : préparation des données + insertion du placeholder ────
// ═══════════════════════════════════════════════════════════════════════════════
// Factorisé pour pouvoir être appelé dans les 2 cas du _renderBilanCarbone :
//   - cas normal (au moins 1 facture énergie)
//   - cas "aucune facture" (le bilan mobilité reste indépendant)
// Retourne le HTML à appender (placeholder rempli ensuite par
// _bcRenderMobiliteSection via setTimeout, comme avant).
async function _bcBuildMobiliteSection() {
  try {
    const role = App.currentUser?.Role;
    if (!window._bcMobiliteData) {
      window._bcMobiliteData = (role === 'Admin' || role === 'Gestionnaire')
        ? await MobiliteApi.adminAll()
        : await MobiliteApi.getAll();
    }
    const mobiliteData = window._bcMobiliteData || [];

    // Build agents & services lists for filter dropdowns
    const agentsMap = {};
    const servicesSet = new Set();
    mobiliteData.forEach(l => {
      const uid = l.UtilisateurId;
      if (uid && !agentsMap[uid]) {
        agentsMap[uid] = { id: uid, nom: [l.Prenom, l.Nom].filter(Boolean).join(' ') || l.Login || ('Agent #' + uid) };
      }
      const svc = l.Service || l._service || '';
      if (svc) servicesSet.add(svc);
      l._service = svc;
    });
    window._bcAgentsList = Object.values(agentsMap);
    window._bcServicesList = [...servicesSet].sort();
    window._bcAgentsMap = agentsMap;
    window._bcRole = role;

    // Placeholder rempli par _bcRenderMobiliteSection après injection DOM
    // Le millésime doit être chargé AVANT le premier rendu, sinon la section
    // s'affiche avec la table de repli et un bandeau « valeurs intégrées »
    // trompeur, jusqu'à ce que l'utilisateur touche un sélecteur.
    setTimeout(async () => {
      if (typeof loadFacteursCarbone === 'function' && typeof MobiliteConfig !== 'undefined') {
        try { await loadFacteursCarbone(MobiliteConfig.annee); } catch (_e) {}
      }
      // Le graphique comparatif porte lui aussi une série mobilité : sans ce
      // rappel il restait sur les facteurs de repli jusqu'à ce que
      // l'utilisateur touche un réglage.
      _bcRenderMobiliteSection();
      _bcRenderSynthese();
      if (typeof _bcRenderChartModule === 'function') _bcRenderChartModule();
    }, 50);
    return `<div id="bc_mobilite_section"></div>`;
  } catch(_mobErr) {
    // Mobilité non disponible → on n'affiche rien plutôt que de casser la page.
    return '';
  }
}

// ═══════════════════════════════════════════════════════════════════════════════
// ── Module 3 — Détail énergies (par bâtiment / contrat / type) ──────────────
// Table agrégée par (Site, Type, Contrat) pour l'année en cours, avec coûts
// HT/TTC et CO₂. Le graphique de détail (barres par bâtiment) est repliable et
// masqué par défaut. Chaque module peut se détacher du bandeau global via
// _bcToggleOverride('energies') pour comparer une autre année ou méthode.
// ═══════════════════════════════════════════════════════════════════════════════
function _bcRenderDetailEnergies() {
  const el = document.getElementById('bc_detail_energies');
  if (!el) return;

  const s   = BilanCarboneUI.energies;
  const uiOverride = s.override;
  // La surcharge locale ne porte que sur l'exercice : le CO₂ est recalculé en
  // amont avec la méthode globale, un « source » local ici serait décoratif.
  const annee = uiOverride ? uiOverride.annee : _bcAnneeEffective();

  const compteurs = window._bcCompteurs || [];
  const contrats  = window._bcContrats  || [];
  // Index par clé texte : les identifiants remontent tantôt en nombre, tantôt
  // en chaîne selon la route API, et une comparaison stricte vidait la colonne
  // Contrat sans rien signaler.
  const contratById = {};
  contrats.forEach(function(c) { contratById[String(c.Id)] = c; });
  const compteurById = {};
  compteurs.forEach(function(c) { compteurById[String(c.Id)] = c; });

  const factures  = window._bcAllFactures || [];
  const typeIcons = window._bcTypeIcons  || { Electricite:'⚡', Gaz:'🔵', Chauffage:'🔥', Eau:'💧' };
  const typeColors = BilanCarboneUI.chart.colors;
  const calc = window._bcCalcCO2 || function(f) { return parseFloat(f.Carbone) || 0; };

  // Agrégation par compteur (chaque compteur = 1 ligne de la table)
  const parCompteur = {};
  factures.forEach(function(f) {
    if (f._annee !== annee) return;
    // _compteurId est posé à la collecte depuis le compteur lui-même : c'est
    // la seule clé garantie cohérente avec la liste des compteurs.
    const cid = (f._compteurId !== undefined && f._compteurId !== null) ? f._compteurId : f.CompteurId;
    const key = (cid !== undefined && cid !== null) ? String(cid) : (f._type + '::' + f._compteurNom);
    if (!parCompteur[key]) {
      var cpt = compteurById[String(cid)] || { Nom: f._compteurNom, Site: '', Type: f._type, ContratId: null };
      parCompteur[key] = { compteur: cpt, conso: 0, coutHT: 0, coutTTC: 0, co2: 0, nb: 0 };
    }
    parCompteur[key].conso   += parseFloat(f.Consommation) || 0;
    parCompteur[key].coutHT  += parseFloat(f.MontantHT)    || 0;
    parCompteur[key].coutTTC += parseFloat(f.Montant)      || 0;
    parCompteur[key].co2     += calc(f);
    parCompteur[key].nb      += 1;
  });

  const lignes = Object.values(parCompteur).sort(function(a, b) {
    return (b.co2 || 0) - (a.co2 || 0);
  });

  // Totaux. Les coûts et le CO₂ s'additionnent (€ et kgCO₂e sont homogènes),
  // mais PAS les consommations : un compteur chauffage en MWh et un compteur
  // électrique en kWh ne se somment pas. « 10 MWh + 102 kWh = 112 » n'a aucun
  // sens — c'est 10 000 kWh + 102 kWh. On totalise donc par unité, et on
  // affiche chaque sous-total séparément.
  const tot = lignes.reduce(function(t, l) {
    t.coutHT += l.coutHT; t.coutTTC += l.coutTTC; t.co2 += l.co2;
    // Conversion vers l'unité de base de la famille : kWh et MWh se cumulent
    // (10 MWh + 102 kWh = 10 102 kWh), les m³ d'eau restent à part.
    var base = _bcVersBase(l.conso, _bcUniteCompteur(l.compteur));
    if (base) t.parFamille[base.famille] = (t.parFamille[base.famille] || 0) + base.valeur;
    else      t.inconnues += l.conso;   // unité non reconnue : jamais fusionnée
    return t;
  }, { coutHT:0, coutTTC:0, co2:0, parFamille:{}, inconnues:0 });

  const fmtMon  = function(v) { return new Intl.NumberFormat('fr-FR', {style:'currency', currency:'EUR', maximumFractionDigits:0}).format(v||0); };
  const fmtNum  = function(v) { return new Intl.NumberFormat('fr-FR', {maximumFractionDigits:1}).format(v||0); };

  let h = '';
  h += '<div class="card" style="margin-bottom:16px">';
  h += _bcModuleHeader('energies', '⚡ Détail énergies', lignes.length + ' compteur(s) actif(s) en ' + annee + ' — ' + fmtMon(tot.coutTTC) + ' TTC');

  // Panneau de surcharge locale (visible seulement si override actif)
  if (uiOverride) {
    h += _bcOverridePanel('energies', uiOverride);
  }

  // Toggle prix HT/TTC — petit sélecteur local
  h += '<div style="padding:10px 20px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;background:var(--gray-bg);border-bottom:1px solid var(--gray-border);font-size:11px;color:var(--gray-text)">';
  h += '  <span style="font-weight:600;text-transform:uppercase;letter-spacing:.5px">Affichage prix</span>';
  h += _bcPillGroup('', ['ht','ttc'], s.prixMode, '_bcSetPrixMode', { ht:'HT', ttc:'TTC' });
  h += '  <div style="flex:1"></div>';
  h += '  <span>Total conso : <strong style="color:var(--text)">' + _bcFmtConsoTotal(tot) + '</strong></span>';
  h += '  <span>· Total CO₂ : <strong style="color:#16a34a">' + _bcFmt(tot.co2) + '</strong></span>';
  h += '</div>';

  // Table
  if (!lignes.length) {
    h += '<div style="padding:32px;text-align:center;color:var(--gray-text);font-size:13px">Aucune facture énergie en ' + annee + '.</div>';
  } else {
    h += '<div style="padding:8px 20px 12px;overflow-x:auto">';
    h += '<table style="width:100%;border-collapse:collapse;font-size:12px">';
    h += '  <thead><tr style="background:var(--gray-bg);border-bottom:1px solid var(--gray-border)">';
    h += '    <th style="padding:8px 10px;text-align:left;font-weight:600;color:var(--gray-text)">Bâtiment</th>';
    h += '    <th style="padding:8px 10px;text-align:left;font-weight:600;color:var(--gray-text)">Contrat</th>';
    h += '    <th style="padding:8px 10px;text-align:left;font-weight:600;color:var(--gray-text)">Type</th>';
    h += '    <th style="padding:8px 10px;text-align:right;font-weight:600;color:var(--gray-text)">Consommation</th>';
    h += '    <th style="padding:8px 10px;text-align:right;font-weight:600;color:var(--gray-text)">Coût ' + s.prixMode.toUpperCase() + '</th>';
    h += '    <th style="padding:8px 10px;text-align:right;font-weight:600;color:var(--gray-text)">CO₂</th>';
    h += '    <th style="padding:8px 10px;text-align:right;font-weight:600;color:var(--gray-text)">Fact.</th>';
    h += '  </tr></thead><tbody>';
    lignes.forEach(function(l) {
      var cpt = l.compteur;
      var contrat = cpt.ContratId ? contratById[String(cpt.ContratId)] : null;
      var contratTxt = contrat
        ? '<div style="font-weight:500">' + escHtml(contrat.Numero || '') + '</div><div style="font-size:10px;color:var(--gray-text)">' + escHtml(contrat.Societe || '') + '</div>'
        : '<span style="color:var(--gray-text);font-style:italic">— Aucun —</span>';
      var typeC = typeColors[cpt.Type] || '#6b7280';
      var unite = _bcUniteCompteur(cpt);
      var prix  = s.prixMode === 'ht' ? l.coutHT : l.coutTTC;
      h += '<tr style="border-bottom:1px solid var(--gray-border)">';
      h += '  <td style="padding:8px 10px">';
      h += '    <div style="font-weight:600;color:var(--navy)">' + escHtml(cpt.Nom || cpt.Site || '(sans nom)') + '</div>';
      if (cpt.Site && cpt.Nom && cpt.Site !== cpt.Nom) {
        h += '    <div style="font-size:10px;color:var(--gray-text)">' + escHtml(cpt.Site) + '</div>';
      }
      h += '  </td>';
      h += '  <td style="padding:8px 10px;font-size:11px">' + contratTxt + '</td>';
      h += '  <td style="padding:8px 10px"><span style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:10px;background:' + typeC + '20;color:' + typeC + ';font-weight:600;font-size:11px">' + (typeIcons[cpt.Type]||'') + ' ' + cpt.Type + '</span></td>';
      h += '  <td style="padding:8px 10px;text-align:right;font-family:DM Mono,monospace">' + (l.conso > 0 ? fmtNum(l.conso) + ' ' + unite : '—') + '</td>';
      h += '  <td style="padding:8px 10px;text-align:right;font-family:DM Mono,monospace;color:var(--blue);font-weight:600">' + (prix > 0 ? fmtMon(prix) : '—') + '</td>';
      h += '  <td style="padding:8px 10px;text-align:right;font-family:DM Mono,monospace;color:#16a34a;font-weight:700">' + _bcFmt(l.co2) + '</td>';
      h += '  <td style="padding:8px 10px;text-align:right;color:var(--gray-text)">' + l.nb + '</td>';
      h += '</tr>';
    });
    // Ligne totale
    h += '<tr style="background:#f0fdf4;font-weight:700;border-top:2px solid #86efac">';
    h += '  <td colspan="3" style="padding:10px;color:var(--navy)">TOTAL ' + annee + '</td>';
    h += '  <td style="padding:10px;text-align:right;font-family:DM Mono,monospace;font-size:11px;line-height:1.5">' + _bcFmtConsoTotal(tot, true) + '</td>';
    h += '  <td style="padding:10px;text-align:right;font-family:DM Mono,monospace;color:var(--blue)">' + fmtMon(s.prixMode === 'ht' ? tot.coutHT : tot.coutTTC) + '</td>';
    h += '  <td style="padding:10px;text-align:right;font-family:DM Mono,monospace;color:#16a34a">' + _bcFmt(tot.co2) + '</td>';
    h += '  <td></td>';
    h += '</tr>';
    h += '  </tbody></table></div>';
  }

  // Graphique de détail (replié par défaut)
  if (s.chartOpen && lignes.length) {
    h += _bcRenderDetailChart('energies', lignes.map(function(l) {
      return { label: l.compteur.Nom || l.compteur.Site || '?', value: l.co2, color: typeColors[l.compteur.Type] || '#6b7280' };
    }), 'Émissions par bâtiment — ' + annee, 'kgCO₂e');
  }

  h += '</div>';
  el.innerHTML = h;
}

// ═══════════════════════════════════════════════════════════════════════════════
// ── Module 4 — Détail mobilité des agents ───────────────────────────────────
// Remplace _bcRenderMobiliteSection : mêmes données (catégories + top agents
// filtré, règles de confidentialité conservées) mais recadré dans le nouveau
// gabarit modulaire (en-tête commun avec override et toggle graphique).
// ═══════════════════════════════════════════════════════════════════════════════
function _bcRenderMobiliteSection() {
  const container = document.getElementById('bc_mobilite_section');
  if (!container) return;
  const mobiliteData = window._bcMobiliteData;
  const role = window._bcRole || (App.currentUser && App.currentUser.Role);

  // État vide (aucun trajet en base)
  if (!mobiliteData || !mobiliteData.length) {
    container.innerHTML =
      '<div class="card" style="margin-bottom:16px;border-left:4px solid #059669">' +
      '  <div class="card-header"><div class="card-title" style="font-size:14px;color:#059669">🚗 Détail mobilité</div></div>' +
      '  <div style="padding:24px 20px;text-align:center;color:var(--gray-text);font-size:13px">' +
      '    Aucun trajet renseigné pour le moment.<br>' +
      '    <span style="font-size:12px">Ajoutez vos trajets domicile-travail et déplacements professionnels pour visualiser le bilan mobilité.</span>' +
      '    <div style="margin-top:12px"><button class="btn btn-ghost btn-sm" onclick="navigate(\'mobilite_carbone\')" style="font-size:11px">✏️ ' + (role === 'Demandeur' ? 'Saisir mes trajets' : 'Mes trajets') + '</button></div>' +
      '  </div>' +
      '</div>';
    return;
  }

  const s = BilanCarboneUI.mobilite;
  const uiOverride = s.override;
  const annee    = uiOverride ? uiOverride.annee   : _bcAnneeEffective();
  const methode  = uiOverride ? uiOverride.methode : ((typeof MobiliteConfig !== 'undefined') ? MobiliteConfig.methode : 'acv');
  const meth     = (typeof _getMethode === 'function') ? _getMethode(methode) : { label: 'ACV complet', court: 'ACV' };
  const anneeDe  = function(l) { return (typeof _anneeLigne === 'function') ? _anneeLigne(l) : new Date().getFullYear(); };
  const agentsMap = window._bcAgentsMap || {};

  const mobDeLAnnee = mobiliteData.filter(function(l) { return anneeDe(l) === annee; });
  const filtered    = mobDeLAnnee.filter(function(l) {
    return (typeof FiltresEngine === 'undefined') || FiltresEngine.matchRow('bilan_carbone', l);
  });

  // Agrégations
  let totalKm = 0, totalKgCO2 = 0;
  const parCat = {}, parAgent = {}, parTransport = {};
  filtered.forEach(function(l) {
    const t = _getTransport(l.Transport);
    const co2 = (typeof _co2Annuel === 'function')
      ? _co2Annuel(l, methode)
      : (parseFloat(l.FacteurCO2) || t.co2) * (parseFloat(l.DistanceKm) || 0) * (parseFloat(l.NbFrequence) || 1) * _getFreq(l.Frequence).facAnnuel;
    const dist = (parseFloat(l.DistanceKm) || 0) * (parseFloat(l.NbFrequence) || 1) * _getFreq(l.Frequence).facAnnuel;
    totalKgCO2 += co2; totalKm += dist;
    const cat = t.cat || 'Autre';
    if (!parCat[cat]) parCat[cat] = { co2:0, km:0, n:0 };
    parCat[cat].co2 += co2; parCat[cat].km += dist; parCat[cat].n += 1;
    const nomA = (agentsMap[l.UtilisateurId] && agentsMap[l.UtilisateurId].nom) || 'Inconnu';
    if (!parAgent[nomA]) parAgent[nomA] = { co2:0, km:0 };
    parAgent[nomA].co2 += co2; parAgent[nomA].km += dist;
    const nomT = t.label || l.Transport;
    if (!parTransport[nomT]) parTransport[nomT] = { co2:0, km:0, cat: cat };
    parTransport[nomT].co2 += co2; parTransport[nomT].km += dist;
  });

  const catColors = { 'Individuel':'#ef4444', 'Mobilité douce':'#22c55e', 'Transport en commun':'#3b82f6', 'Aérien':'#f59e0b', 'Autre':'#6b7280' };
  const fmtNum = function(v) { return new Intl.NumberFormat('fr-FR', {maximumFractionDigits:0}).format(v||0); };

  let h = '';
  h += '<div class="card" style="margin-bottom:16px;border-left:4px solid #059669">';
  h += _bcModuleHeader('mobilite', '🚗 Détail mobilité', filtered.length + ' trajet(s)' + (filtered.length !== mobDeLAnnee.length ? ' (filtré sur ' + mobDeLAnnee.length + ')' : '') + ' en ' + annee + ' — ' + fmtNum(totalKm) + ' km · ' + _bcFmt(totalKgCO2));

  if (uiOverride) {
    h += _bcOverridePanel('mobilite', uiOverride);
  }

  // Filtres avancés (déjà rendus par FiltresEngine) + méthode active
  h += '<div style="padding:10px 20px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;background:var(--gray-bg);border-bottom:1px solid var(--gray-border);font-size:11px;color:var(--gray-text)">';
  h += '  <span>Périmètre : <strong style="color:var(--text)">' + meth.label + '</strong></span>';
  h += '  <button onclick="_bcToggleAidePerimetres()" title="Quelle différence entre les 4 périmètres ?" style="width:18px;height:18px;border-radius:50%;border:1px solid var(--gray-border);background:var(--card-bg);color:var(--gray-text);cursor:pointer;font-size:10px;font-weight:700;line-height:1;padding:0">?</button>';
  h += '  <span>· Millésime : <strong style="color:var(--text)">' + ((typeof FACTEURS_SOURCE !== 'undefined' && FACTEURS_SOURCE.anneeFacteurs) || annee) + '</strong></span>';
  if (typeof FACTEURS_SOURCE !== 'undefined' && FACTEURS_SOURCE.origine === 'report_avant') {
    h += '  <span style="color:#dc2626">⛔ POSTÉRIEUR à l\'exercice</span>';
  } else if (typeof FACTEURS_SOURCE !== 'undefined' && FACTEURS_SOURCE.origine === 'report') {
    h += '  <span>(reporté)</span>';
  } else if (typeof FACTEURS_SOURCE !== 'undefined' && FACTEURS_SOURCE.origine === 'local') {
    h += '  <span>(valeurs intégrées)</span>';
  }
  h += '  <div style="flex:1"></div>';
  h += '  <button class="btn btn-ghost btn-sm" onclick="navigate(\'mobilite_carbone\')" style="font-size:10px">✏️ ' + (role === 'Demandeur' ? 'Modifier mes trajets' : 'Mes trajets') + '</button>';
  h += '</div>';

  // ── Aide : les 4 périmètres de calcul mobilité ────────────────────────
  // Le même trajet peut donner quatre chiffres différents selon ce qu'on
  // décide de compter. Les afficher côte à côte sur les données réelles de
  // l'exercice rend la différence tangible.
  if (BilanCarboneUI.aidePerimOpen) {
    h += '<div style="padding:12px 20px;background:#ecfdf5;border-bottom:1px solid #a7f3d0;font-size:12px;line-height:1.6">';
    h += '  <div style="font-weight:700;color:#065f46;margin-bottom:2px">Que compte-t-on dans un kilomètre ?</div>';
    h += '  <div style="color:var(--gray-text);margin-bottom:10px">Un même trajet donne quatre résultats différents selon la profondeur retenue. Du plus large au plus étroit — chaque périmètre retire une brique au précédent. Les montants ci-dessous sont vos trajets ' + annee + ' recalculés dans chaque périmètre.</div>';
    if (typeof METHODES !== 'undefined') {
      var explications = {
        acv:        'Tout le cycle de vie : fabrication du véhicule, extraction et raffinage du carburant, combustion, et forçage radiatif de l\'aviation. C\'est le périmètre attendu dans un bilan carbone d\'organisation.',
        usage:      'Comme l\'ACV, mais sans la fabrication du véhicule. Utile quand la flotte n\'est pas à vous, ou pour isoler l\'effet d\'un changement de carburant.',
        combustion: 'Uniquement ce qui sort du pot d\'échappement, sans l\'amont carburant ni le véhicule. Nul pour l\'électrique et le vélo. C\'est le scope 1 au sens strict.',
        co2seul:    'La combustion, mais en CO₂ pur — sans le méthane ni le protoxyde d\'azote. Le chiffre le plus bas des quatre ; à ne pas confondre avec un CO₂e.',
      };
      h += '  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:10px">';
      METHODES.forEach(function(m) {
        var totalM = 0;
        if (typeof _co2Annuel === 'function') {
          filtered.forEach(function(l) { totalM += _co2Annuel(l, m.id); });
        }
        var actif = (m.id === methode);
        var ecart = (totalKgCO2 > 0 && !actif) ? Math.round((totalM - totalKgCO2) / totalKgCO2 * 100) : null;
        h += '<div style="padding:10px 12px;border-radius:8px;background:var(--card-bg);border:1px solid ' + (actif ? '#059669' : 'var(--gray-border)') + ';' + (actif ? 'box-shadow:0 0 0 1px #059669' : '') + '">';
        h += '  <div style="display:flex;align-items:baseline;gap:6px;flex-wrap:wrap">';
        h += '    <span style="font-weight:700;color:#065f46;font-size:12px">' + m.label + '</span>';
        if (actif) h += '<span style="font-size:9px;padding:1px 6px;border-radius:8px;background:#059669;color:white;font-weight:600">ACTIF</span>';
        if (m.source === 'derive') h += '<span title="Valeur dérivée par calcul, non fournie telle quelle par la source" style="font-size:9px;color:var(--gray-text);cursor:help">dérivé *</span>';
        h += '  </div>';
        h += '  <div style="font-size:17px;font-weight:700;color:#065f46;margin:2px 0">' + _bcFmt(totalM) + (m.id === 'co2seul' ? ' CO₂' : ' CO₂e') + '';
        if (ecart !== null && ecart !== 0) {
          h += ' <span style="font-size:11px;font-weight:500;color:var(--gray-text)">(' + (ecart > 0 ? '+' : '') + ecart + ' % vs actif)</span>';
        }
        h += '  </div>';
        h += '  <div style="color:var(--gray-text);font-size:11px;line-height:1.5">' + (explications[m.id] || m.desc || '') + '</div>';
        h += '</div>';
      });
      h += '  </div>';
    }
    h += '  <div style="margin-top:10px;padding:8px 12px;border-radius:8px;background:#fffbeb;border:1px solid #fcd34d;font-size:11px;color:#92400e">⚠️ Seul <strong>ACV complet</strong> se consolide avec l\'énergie. Les facteurs énergie sont des totaux sans déclinaison de périmètre : les additionner à une mobilité en combustion seule mélangerait deux grandeurs, le bilan total est donc refusé dans ce cas.</div>';
    h += '</div>';
  }

  // Corps : barres par catégorie
  h += '<div style="padding:14px 20px 12px">';
  if (!filtered.length) {
    h += '  <div style="text-align:center;padding:20px;color:var(--gray-text);font-size:13px">' + (mobDeLAnnee.length === 0 ? '📅 Aucun trajet enregistré pour ' + annee + '.' : '🔍 Aucun trajet ne correspond aux filtres.') + '</div>';
  } else {
    Object.entries(parCat).sort(function(a,b){ return b[1].co2 - a[1].co2; }).forEach(function(entry) {
      var cat = entry[0], d = entry[1];
      var pct = totalKgCO2 > 0 ? (d.co2 / totalKgCO2 * 100) : 0;
      var color = catColors[cat] || '#6b7280';
      h += '<div style="display:flex;align-items:center;gap:10px;margin-bottom:6px">';
      h += '  <div style="width:150px;font-size:12px;font-weight:500">' + cat + ' <span style="color:var(--gray-text);font-weight:400">(' + d.n + ')</span></div>';
      h += '  <div style="flex:1;height:16px;background:var(--gray-bg);border-radius:8px;overflow:hidden">';
      h += '    <div style="height:100%;width:' + Math.max(pct, 1) + '%;background:' + color + ';border-radius:8px;transition:width .3s"></div>';
      h += '  </div>';
      h += '  <div style="width:80px;text-align:right;font-size:12px;font-weight:700;color:' + color + '">' + _bcFmt(d.co2) + '</div>';
      h += '  <div style="width:40px;text-align:right;font-size:11px;color:var(--gray-text)">' + pct.toFixed(0) + ' %</div>';
      h += '</div>';
    });

    // Top agents : uniquement quand un filtre est actif (confidentialité conservée)
    const filtresActifs = (typeof FiltresEngine !== 'undefined' && typeof FiltresEngine.activeCount === 'function') ? FiltresEngine.activeCount('bilan_carbone') : 0;
    if (filtresActifs > 0 && (role === 'Admin' || role === 'Gestionnaire') && Object.keys(parAgent).length >= 1) {
      h += '<div style="margin-top:14px;padding-top:10px;border-top:1px solid var(--gray-border)"><div style="font-size:11px;font-weight:700;color:var(--navy);margin-bottom:8px">👤 Par agent (filtré)</div>';
      Object.entries(parAgent).sort(function(a,b){ return b[1].co2 - a[1].co2; }).slice(0, 10).forEach(function(entry) {
        var nom = entry[0], d = entry[1];
        var pct = totalKgCO2 > 0 ? (d.co2 / totalKgCO2 * 100) : 0;
        h += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">';
        h += '  <div style="width:140px;font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + escHtml(nom) + '</div>';
        h += '  <div style="flex:1;height:10px;background:var(--gray-bg);border-radius:5px;overflow:hidden"><div style="height:100%;width:' + Math.max(pct,2) + '%;background:#059669;border-radius:5px"></div></div>';
        h += '  <div style="width:70px;text-align:right;font-size:11px;font-weight:600">' + _bcFmt(d.co2) + '</div>';
        h += '</div>';
      });
      h += '</div>';
    } else if ((role === 'Admin' || role === 'Gestionnaire') && Object.keys(parAgent).length > 1) {
      h += '<div style="margin-top:14px;padding-top:10px;border-top:1px solid var(--gray-border);font-size:11px;color:var(--gray-text);font-style:italic">🔒 Pour voir le détail nominatif par agent, utilisez le bouton "Filtres" du bandeau global (filtre Agent, Service, Catégorie…).</div>';
    }
  }
  h += '</div>';

  // Graphique de détail (masqué par défaut, activable via l'en-tête)
  if (s.chartOpen && filtered.length) {
    var chartData = Object.entries(parTransport)
                    .sort(function(a,b){ return b[1].co2 - a[1].co2; })
                    .slice(0, 8)
                    .map(function(entry) {
      return { label: entry[0], value: entry[1].co2, color: catColors[entry[1].cat] || '#6b7280' };
    });
    h += _bcRenderDetailChart('mobilite', chartData, 'Émissions par type de transport — ' + annee, 'kgCO₂e');
  }

  h += '</div>';
  container.innerHTML = h;
}

// ═══════════════════════════════════════════════════════════════════════════════
// ── Fragments partagés modules 3 et 4 ───────────────────────────────────────
// ═══════════════════════════════════════════════════════════════════════════════
function _bcModuleHeader(module, titre, sousTitre) {
  const s = module === 'energies' ? BilanCarboneUI.energies : BilanCarboneUI.mobilite;
  const couleur = module === 'energies' ? '#16a34a' : '#059669';
  var h = '<div class="card-header">';
  h += '  <div>';
  h += '    <div class="card-title" style="font-size:14px;color:' + couleur + '">' + titre + (s.override ? ' <span title="Ce module affiche un autre exercice que le reste de la page" style="font-size:10px;padding:2px 6px;border-radius:8px;background:#dbeafe;color:#1e40af;margin-left:6px;font-weight:600">📌 Exercice indépendant</span>' : '') + '</div>';
  h += '    <div style="font-size:11px;color:var(--gray-text);margin-top:2px">' + sousTitre + '</div>';
  h += '  </div>';
  h += '  <div style="display:flex;gap:6px;align-items:center">';
  // « Détacher » ne disait rien à personne : le bouton annonce désormais ce
  // qu'il permet de faire, pas le mécanisme sous-jacent.
  h += '    <button class="btn btn-ghost btn-sm" onclick="_bcToggleOverride(\'' + module + '\')" title="' + (s.override
        ? 'Refaire suivre ce module à l\'année choisie en haut de page'
        : 'Figer ce module sur un autre exercice, pour le comparer à celui affiché en haut de page. Les autres modules ne bougent pas.') + '" style="font-size:11px">'
        + (s.override ? '↩️ Resuivre le global' : '📌 Comparer un autre exercice') + '</button>';
  h += '    <button class="btn btn-ghost btn-sm" onclick="_bcToggleModuleChart(\'' + module + '\')" style="font-size:11px">📊 Graphique ' + (s.chartOpen ? '▴' : '▾') + '</button>';
  h += '  </div>';
  h += '</div>';
  return h;
}

function _bcOverridePanel(module, override) {
  var styleS = 'font-size:11px;padding:3px 8px;border-radius:6px;border:1px solid var(--gray-border);background:var(--card-bg);color:var(--text);cursor:pointer';
  // Même source de vérité que le bandeau global : un module détaché ne doit
  // pas pouvoir viser une année que le reste de la page ignore.
  var dispo = _bcAnneesAvecDonnees();
  if (!dispo.some(function(d) { return d.annee === override.annee; })) {
    dispo = dispo.concat([{ annee: override.annee, energie: false, mobilite: false, facteurs: false }])
                 .sort(function(a, b) { return b.annee - a.annee; });
  }
  var anneeGlobale = _bcAnneeEffective();

  var h = '<div style="padding:10px 20px;background:#eff6ff;border-bottom:1px solid #bfdbfe;font-size:11px">';
  h += '  <div style="color:#1e40af;margin-bottom:8px;line-height:1.6">';
  h += '    <strong>📌 Exercice indépendant.</strong> Ce module seul affiche l\'année choisie ci-dessous ; le reste de la page reste sur <strong>' + anneeGlobale + '</strong>. Pratique pour comparer deux exercices côte à côte sans perdre la vue d\'ensemble.';
  h += '  </div>';
  h += '  <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center">';
  h += '    <label>📅 Afficher ici l\'année <select onchange="_bcSetOverrideAnnee(\'' + module + '\', this.value)" style="' + styleS + '">';
  dispo.forEach(function(d) {
    h += '<option value="' + d.annee + '"' + (d.annee === override.annee ? ' selected' : '') + '>' + _bcLabelAnnee(d) + '</option>';
  });
  h += '    </select></label>';
  if (module === 'mobilite' && typeof METHODES !== 'undefined') {
    h += '    <label>⚗️ Périmètre <select onchange="_bcSetOverrideMethode(\'' + module + '\', this.value)" style="' + styleS + '">';
    METHODES.forEach(function(m) {
      h += '<option value="' + m.id + '"' + (m.id === override.methode ? ' selected' : '') + '>' + m.label + (m.source === 'derive' ? ' *' : '') + '</option>';
    });
    h += '    </select></label>';
  }
  h += '    <div style="flex:1"></div>';
  h += '    <button class="btn btn-ghost btn-sm" onclick="_bcToggleOverride(\'' + module + '\')" style="font-size:10px">↩️ Resuivre le global (' + anneeGlobale + ')</button>';
  h += '  </div>';
  h += '</div>';
  return h;
}

// Wrappers nommés : passer une IIFE dans un attribut onclick fonctionnait mais
// imbriquait trois niveaux de guillemets, ce qui casse dès qu'un libellé bouge.
function _bcSetDetailChartTypeEnergies(t) { _bcSetDetailChartType('energies', t); }
function _bcSetDetailChartTypeMobilite(t) { _bcSetDetailChartType('mobilite', t); }

// Petit graphique horizontal (barres ou lignes) pour les détails
function _bcRenderDetailChart(module, items, titre, unite) {
  unite = unite || 'kgCO₂e';
  var cfg = module === 'energies' ? BilanCarboneUI.energies : BilanCarboneUI.mobilite;
  if (!items.length) return '';
  var max = Math.max.apply(null, items.map(function(i){ return i.value; }));
  if (max <= 0) max = 1;

  var setter = module === 'energies' ? '_bcSetDetailChartTypeEnergies' : '_bcSetDetailChartTypeMobilite';
  var h = '<div style="padding:0 20px 16px;border-top:1px solid var(--gray-border);margin-top:8px">';
  h += '  <div style="display:flex;align-items:center;justify-content:space-between;margin:12px 0 8px">';
  h += '    <div style="font-size:12px;font-weight:600;color:var(--gray-text)">📊 ' + titre + ' <span style="font-weight:400">(en ' + unite + ')</span></div>';
  h += '    <div style="display:flex;gap:4px">';
  h += _bcPillGroup('', ['bar','line'], cfg.chartType, setter, { bar:'📊', line:'📈' });
  h += '    </div>';
  h += '  </div>';

  if (cfg.chartType === 'line') {
    // Ligne = simple polyline horizontale
    var W = 660, H = 160, padL = 6, padR = 6, padT = 8, padB = 40;
    var innerW = W - padL - padR, innerH = H - padT - padB;
    var xStep = innerW / Math.max(items.length - 1, 1);
    var pts = items.map(function(i, idx) {
      return (padL + xStep * idx) + ',' + (padT + innerH - (i.value / max * innerH));
    }).join(' ');
    h += '<svg viewBox="0 0 ' + W + ' ' + H + '" style="width:100%;height:auto;max-height:180px">';
    h += '<polyline points="' + pts + '" fill="none" stroke="#16a34a" stroke-width="2" stroke-linejoin="round"/>';
    items.forEach(function(i, idx) {
      // Les libellés viennent de noms de compteurs saisis par l'utilisateur :
      // ils doivent être échappés avant d'entrer dans le SVG.
      var nom = escHtml(i.label);
      var cx = padL + xStep * idx, cy = padT + innerH - (i.value / max * innerH);
      h += '<circle cx="' + cx + '" cy="' + cy + '" r="3" fill="' + i.color + '"><title>' + nom + ' — ' + _bcFmt(i.value) + ' CO₂e</title></circle>';
      var lbl = escHtml(String(i.label).length > 12 ? (String(i.label).substring(0, 11) + '…') : i.label);
      h += '<text x="' + cx + '" y="' + (H - 24) + '" text-anchor="middle" font-size="9" fill="var(--gray-text)" font-family="DM Sans, sans-serif">' + lbl + '</text>';
      h += '<text x="' + cx + '" y="' + (H - 12) + '" text-anchor="middle" font-size="8" fill="var(--text)" font-family="DM Sans, sans-serif" font-weight="600">' + _bcFmtShort(i.value) + '</text>';
    });
    h += '</svg>';
  } else {
    // Barres horizontales
    items.forEach(function(i) {
      var pct = i.value / max * 100;
      h += '<div style="display:flex;align-items:center;gap:10px;margin-bottom:5px">';
      h += '  <div style="width:180px;font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="' + escHtml(i.label) + '">' + escHtml(i.label) + '</div>';
      h += '  <div style="flex:1;height:14px;background:var(--gray-bg);border-radius:7px;overflow:hidden"><div style="height:100%;width:' + Math.max(pct, 1) + '%;background:' + i.color + '"></div></div>';
      h += '  <div style="width:80px;text-align:right;font-size:11px;font-weight:600;color:' + i.color + '">' + _bcFmt(i.value) + '</div>';
      h += '</div>';
    });
  }
  h += '</div>';
  return h;
}

/* ── Bilan carbone : bascule d'année et de périmètre pour la section Mobilité ─ */
async function _bcSetMobiliteAnnee(a) {
  if (typeof MobiliteConfig === 'undefined') return;
  MobiliteConfig.annee = a;
  // Le millésime doit être chargé AVANT le redessin, sinon les barres
  // utiliseraient encore les facteurs de l'année précédente.
  if (typeof loadFacteursCarbone === 'function') {
    try { await loadFacteursCarbone(MobiliteConfig.annee); } catch (e) {}
  }
  // Refonte : l'année globale gouverne aussi le graphique comparatif et la
  // table détail énergies, en plus de la synthèse et de la mobilité.
  _bcRenderMobiliteSection();
  _bcRenderSynthese();
  if (typeof _bcRenderChartModule   === 'function') _bcRenderChartModule();
  if (typeof _bcRenderDetailEnergies === 'function') _bcRenderDetailEnergies();
}

function _bcSetMobiliteMethode(m) {
  if (typeof MobiliteConfig === 'undefined') return;
  MobiliteConfig.methode = m;
  _bcRenderMobiliteSection();
  _bcRenderSynthese();
  if (typeof _bcRenderChartModule   === 'function') _bcRenderChartModule();
  if (typeof _bcRenderDetailEnergies === 'function') _bcRenderDetailEnergies();
}

// ═══════════════════════════════════════════════════════════════════════════════
// ── Synthèse consolidée du bilan carbone ────────────────────────────────────
// ═══════════════════════════════════════════════════════════════════════════════
/**
 * Mode de consolidation énergie + mobilité. Le problème : l'énergie est
 * CONSTATÉE (relevés réellement saisis, souvent partiels en cours d'année),
 * la mobilité est PROJETÉE (trajets récurrents × 228 jours ouvrés). Les
 * additionner brut donne un nombre qui n'est ni l'émis ni le prévisionnel.
 *
 *   constate   énergie telle quelle + mobilité annuelle, écart signalé (défaut)
 *   annualise  énergie extrapolée au prorata des mois renseignés
 *   separe     pas de total consolidé, les deux blocs restent côte à côte
 *
 * Sur une année révolue les trois modes convergent : le choix ne pèse que sur
 * l'exercice en cours.
 */
const BilanConsolidation = {
  _mode: null,
  get mode() {
    if (this._mode) return this._mode;
    try { this._mode = localStorage.getItem('larka_bilan_consolidation') || 'constate'; }
    catch (e) { this._mode = 'constate'; }
    return this._mode;
  },
  set mode(v) {
    this._mode = ['constate', 'annualise', 'separe'].includes(v) ? v : 'constate';
    try { localStorage.setItem('larka_bilan_consolidation', this._mode); } catch (e) {}
  },
};

function _bcSetConsolidation(m) {
  BilanConsolidation.mode = m;
  _bcRenderSynthese();
}

function _bcFmt(kg) {
  if (!kg || kg === 0) return '0 kg';
  const n = v => new Intl.NumberFormat('fr-FR', { maximumFractionDigits: v >= 100 ? 0 : 2 }).format(v);
  return kg >= 1000 ? n(kg / 1000) + ' t' : n(kg) + ' kg';
}

// ═══════════════════════════════════════════════════════════════════════════════
// ── Module 1 — Bilan total consolidé ────────────────────────────────────────
// Remplace la synthèse historique. Reprend les mêmes agrégats (énergie + mobilité,
// filtre annuel, modes de consolidation) mais présente le tout comme une carte
// unique : grande valeur totale, delta vs N-1, barre segmentée en 3 postes,
// légende détaillée. Les décisions de consolidation restent gérées par
// BilanConsolidation et le sélecteur global au-dessus.
// ═══════════════════════════════════════════════════════════════════════════════
function _bcRenderSynthese() {
  const el = document.getElementById('bc_synthese');
  if (!el) return;

  const annee   = _bcAnneeEffective();
  const methode = (typeof MobiliteConfig !== 'undefined') ? MobiliteConfig.methode : 'acv';
  const meth    = (typeof _getMethode === 'function') ? _getMethode(methode) : { label: 'ACV complet' };

  // ── Postes énergie (typesActifs, agrégés depuis les factures) ─────────
  const yd = (window._bcYearData || {})[annee] || {};
  const colors = BilanCarboneUI.chart.colors;
  const typeIcons = window._bcTypeIcons || { Electricite:'⚡', Gaz:'🔵', Chauffage:'🔥', Eau:'💧' };
  // Rattachement au périmètre GHG, expliqué en détail dans le bandeau source.
  const scopeParType = { Gaz: 1, Electricite: 2, Chauffage: 2, Eau: 3 };

  const postes = [];
  let totalEnergie = 0;
  let totalHT = 0, totalTTC = 0;
  Object.keys(yd).forEach(function(type) {
    const co2 = yd[type].co2 || 0;
    totalEnergie     += co2;
    totalHT          += yd[type].coutHT  || 0;
    totalTTC         += yd[type].coutTTC || 0;
    postes.push({ nom: type, co2: co2, couleur: colors[type] || '#6b7280', icon: typeIcons[type] || '', scope: scopeParType[type] || 3 });
  });
  // N-1 lu sur les totaux consolidés : boucler sur les types de N ferait
  // disparaître une énergie présente l'an dernier et absente cette année,
  // gonflant artificiellement la baisse affichée.
  const totalEnergiePrev = ((window._bcYearTotals || {})[annee - 1] || {}).co2 || 0;

  // Annualisation (mode « constate » / « annualise » / « separe »)
  const moisRenseignes = (window._bcMoisParAnnee || {})[annee] || 0;
  const anneeCourante  = (annee === new Date().getFullYear());
  const partiel        = anneeCourante && moisRenseignes > 0 && moisRenseignes < 12;
  let energieAffichee = totalEnergie;
  if (BilanConsolidation.mode === 'annualise' && moisRenseignes > 0) {
    energieAffichee = totalEnergie * 12 / moisRenseignes;
  }

  // ── Poste mobilité (agents) ─────────────────────────────────────────
  let totalMob = 0, totalMobPrev = 0;
  const mob = window._bcMobiliteData || [];
  if (typeof _co2Annuel === 'function' && typeof _anneeLigne === 'function') {
    mob.forEach(function(l) {
      var a = _anneeLigne(l);
      if ((typeof FiltresEngine === 'undefined') || FiltresEngine.matchRow('bilan_carbone', l)) {
        if (a === annee)     totalMob     += _co2Annuel(l, methode);
        if (a === annee - 1) totalMobPrev += _co2Annuel(l, methode);
      }
    });
  }
  if (totalMob > 0) {
    postes.push({ nom: 'Mobilité', co2: totalMob, couleur: colors.Mobilite || '#059669', icon: '🚗', scope: 3 });
  }

  // ── Consolidation possible ? ────────────────────────────────────────
  // Sommer une énergie ACV et une mobilité en combustion pure serait une
  // fraude comptable : on refuse plutôt que de calculer un nombre faux.
  const perimetreCompatible = (methode === 'acv');
  const consolide = perimetreCompatible && BilanConsolidation.mode !== 'separe';
  const total      = energieAffichee + totalMob;
  const totalPrev  = totalEnergiePrev + totalMobPrev;
  const totalPostes = postes.reduce(function(s, p) { return s + p.co2; }, 0) || 1;

  // ── Delta N/N-1 ─────────────────────────────────────────────────────
  const delta = totalPrev > 0 ? ((total - totalPrev) / totalPrev * 100) : null;
  const _fmtDelta = function(v) {
    if (v === null || !isFinite(v)) return '';
    const sign  = v > 0 ? '+' : '';
    const color = v < 0 ? '#16a34a' : v > 0 ? '#dc2626' : 'var(--gray-text)';
    const arrow = v < 0 ? '↓' : v > 0 ? '↑' : '→';
    return '<span style="color:' + color + ';font-weight:600;font-size:13px;margin-left:8px">' + arrow + ' ' + sign + v.toFixed(1) + ' % vs ' + (annee - 1) + '</span>';
  };

  const fmtMon = function(v) { return new Intl.NumberFormat('fr-FR', {style:'currency', currency:'EUR', maximumFractionDigits:0}).format(v||0); };

  let h = '';
  h += '<div class="card" style="margin-bottom:16px;padding:18px 22px;border-left:4px solid #16a34a">';

  // En-tête : label + total XXL + delta
  h += '<div style="display:flex;align-items:flex-start;gap:20px;flex-wrap:wrap;margin-bottom:14px">';
  h += '  <div style="flex:1;min-width:220px">';
  h += '    <div style="font-size:12px;color:var(--gray-text);text-transform:uppercase;letter-spacing:.5px;font-weight:600">Bilan carbone ' + annee + (consolide ? ' — consolidé' : ' — non consolidé') + '</div>';
  if (consolide) {
    h += '    <div style="font-size:34px;font-weight:800;color:var(--navy);line-height:1.15;margin-top:2px">' + _bcFmt(total) + ' <span style="font-size:16px;color:var(--gray-text);font-weight:500">CO₂e</span></div>';
    h += '    <div>' + _fmtDelta(delta) + '</div>';
  } else {
    h += '    <div style="font-size:22px;font-weight:700;color:var(--navy);line-height:1.3;margin-top:2px">' + _bcFmt(energieAffichee) + ' énergie <span style="color:var(--gray-text);font-weight:400">·</span> ' + _bcFmt(totalMob) + ' mobilité</div>';
  }
  h += '  </div>';

  // Colonne coût (à droite du chiffre principal) — utile côté gestionnaire
  if (totalHT > 0 || totalTTC > 0) {
    h += '  <div style="text-align:right;min-width:150px">';
    h += '    <div style="font-size:12px;color:var(--gray-text);text-transform:uppercase;letter-spacing:.5px;font-weight:600">Coût énergie ' + annee + '</div>';
    h += '    <div style="font-size:22px;font-weight:700;color:var(--blue);line-height:1.2;margin-top:2px">' + fmtMon(totalTTC) + '<span style="font-size:11px;color:var(--gray-text);margin-left:4px">TTC</span></div>';
    h += '    <div style="font-size:12px;color:var(--gray-text)">' + fmtMon(totalHT) + ' HT</div>';
    h += '  </div>';
  }
  h += '</div>';

  // Barre segmentée en 3 postes (élec / chauffage / mobilité)
  if (postes.length && totalPostes > 0) {
    h += '<div style="display:flex;height:36px;border-radius:10px;overflow:hidden;border:1px solid var(--gray-border);margin-bottom:10px">';
    postes.forEach(function(p) {
      var pct = p.co2 / totalPostes * 100;
      if (pct < 0.5) return; // segment invisible → on omet plutôt que d'afficher un fil
      var showLabel = pct >= 8; // en dessous, le libellé serait tronqué
      h += '<div title="' + p.icon + ' ' + p.nom + ' — ' + _bcFmt(p.co2) + ' (' + pct.toFixed(1) + ' %) — scope ' + p.scope + '" ';
      h += 'style="width:' + pct + '%;background:' + p.couleur + ';display:flex;align-items:center;justify-content:center;color:white;font-size:12px;font-weight:600;text-shadow:0 1px 1px rgba(0,0,0,.15);padding:0 6px;overflow:hidden;white-space:nowrap">';
      h += showLabel ? (p.icon + ' ' + p.nom + ' · ' + pct.toFixed(0) + ' %') : '';
      h += '</div>';
    });
    h += '</div>';
  }

  // Légende détaillée sous la barre, avec le scope de chaque poste : c'est le
  // seul endroit où le lecteur voit d'un coup d'œil quel périmètre pèse quoi.
  if (postes.length) {
    h += '<div style="display:flex;flex-wrap:wrap;gap:14px;font-size:12px;color:var(--text);margin-top:6px">';
    postes.forEach(function(p) {
      var pct = p.co2 / totalPostes * 100;
      h += '<div style="display:inline-flex;align-items:center;gap:6px">';
      h += '  <span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:' + p.couleur + '"></span>';
      h += '  <span>' + p.icon + ' ' + p.nom + '</span>';
      h += '  <span title="Périmètre GHG Protocol — détail dans le bandeau ⚙️ en haut de page" style="font-size:10px;padding:1px 6px;border-radius:8px;background:var(--gray-bg);border:1px solid var(--gray-border);color:var(--gray-text);cursor:help">scope ' + p.scope + '</span>';
      h += '  <strong style="color:var(--navy)">' + _bcFmt(p.co2) + '</strong>';
      h += '  <span style="color:var(--gray-text)">· ' + pct.toFixed(0) + ' %</span>';
      h += '</div>';
    });
    h += '</div>';
  }

  if (!postes.length) {
    h += '<div style="font-size:13px;color:var(--gray-text);text-align:center;padding:8px">Aucune donnée pour ' + annee + '.</div>';
  }

  // Mentions (mêmes règles que l'existant : consolidation impossible, énergie partielle, etc.)
  const mentions = [];

  // Exercice déséquilibré : une barre d'un seul bloc n'est pas une erreur de
  // calcul, c'est une année où l'autre source est vide. Le dire évite de
  // chercher un bug qui n'existe pas.
  const aEnergie  = Object.keys(yd).length > 0;
  const aMobilite = totalMob > 0;
  if (!aEnergie && aMobilite) {
    mentions.push({ n:'info', t: 'Aucune facture énergie saisie pour ' + annee + ' : le bilan ne reflète que la mobilité des agents. Les autres exercices disponibles sont proposés dans le sélecteur 📅 en haut de page.' });
  } else if (aEnergie && !aMobilite) {
    mentions.push({ n:'info', t: 'Aucun trajet renseigné pour ' + annee + ' : le bilan ne couvre que les énergies (scopes 1 et 2), le scope 3 déplacements est absent.' });
  }

  if (!perimetreCompatible) {
    mentions.push({ n:'alerte', t: 'Périmètre mobilité « ' + meth.label + ' » : pas de total consolidé. Les facteurs énergie sont des totaux Base Carbone sans déclinaison de périmètre — additionner les deux mélangerait deux grandeurs. Repassez la mobilité en ACV complet pour consolider.' });
  } else if (BilanConsolidation.mode === 'separe') {
    mentions.push({ n:'info', t: 'Consolidation désactivée dans le sélecteur global au-dessus.' });
  } else if (BilanConsolidation.mode === 'annualise' && moisRenseignes > 0 && moisRenseignes < 12) {
    mentions.push({ n:'info', t: 'Énergie extrapolée : ' + _bcFmt(totalEnergie) + ' constatés sur ' + moisRenseignes + ' mois, ramenés à 12. La mobilité est déjà annuelle.' });
  } else if (partiel) {
    mentions.push({ n:'alerte', t: 'Énergie constatée sur ' + moisRenseignes + ' mois seulement, mobilité projetée sur 12 : ce total additionne un réalisé partiel et une projection annuelle. Choisissez « Énergie annualisée » pour une base homogène.' });
  }
  if (typeof FACTEURS_SOURCE !== 'undefined' && FACTEURS_SOURCE.origine === 'report_avant') {
    mentions.push({ n:'alerte', t: 'Mobilité calculée avec le millésime ' + FACTEURS_SOURCE.anneeFacteurs + ', postérieur à l\'exercice.' });
  }
  mentions.forEach(function(m) {
    var alerte = m.n === 'alerte';
    h += '<div style="margin-top:10px;padding:8px 12px;border-radius:8px;font-size:11px;line-height:1.6;'
      +  'background:' + (alerte ? '#fef3c7' : 'var(--gray-bg)') + ';'
      +  'border:1px solid ' + (alerte ? '#fcd34d' : 'var(--gray-border)') + ';'
      +  'color:' + (alerte ? '#92400e' : 'var(--gray-text)') + '">'
      +  (alerte ? '⚠️ ' : 'ℹ️ ') + m.t + '</div>';
  });

  h += '</div>';
  el.innerHTML = h;
}

// ═══════════════════════════════════════════════════════════════════════════════
// ── Module 2 — Graphique comparatif personnalisable ─────────────────────────
// Un seul canvas visuel (SVG) qui rend indifféremment barres groupées, barres
// empilées ou lignes. La métrique (CO₂, prix HT, prix TTC, quantité) et la
// période (mois, trimestre, année) sont pilotées par BilanCarboneUI.chart et
// persistées entre visites. Les couleurs sont modifiables via input[type=color]
// et synchronisées avec le module 1 (barre du bilan total).
// ═══════════════════════════════════════════════════════════════════════════════
function _bcRenderChartModule() {
  const el = document.getElementById('bc_chart_module');
  if (!el) return;

  const cfg    = BilanCarboneUI.chart;
  const annee  = _bcAnneeEffective();
  const meth   = (typeof MobiliteConfig !== 'undefined') ? MobiliteConfig.methode : 'acv';
  const factures = window._bcAllFactures || [];
  const typesActifs = window._bcTypesActifs || ['Electricite','Chauffage','Gaz'];
  const calc = window._bcCalcCO2 || function(f) { return parseFloat(f.Carbone) || 0; };

  // Séries : chaque type d'énergie + mobilité (si des données existent)
  const series = typesActifs.map(function(t) {
    return { key: t, label: t, color: cfg.colors[t] || '#6b7280' };
  });
  const mob = window._bcMobiliteData || [];
  if (mob.length) series.push({ key: 'Mobilite', label: 'Mobilité', color: cfg.colors.Mobilite || '#059669' });

  // ── Agrégation selon la période ───────────────────────────────────────
  // « mois » et « trimestre » sont bornés à l'année globale ; « annee »
  // compare N-2, N-1, N pour donner une vraie tendance.
  var buckets = [];
  var data = {}; // data[serieKey][bucketKey] = valeur

  if (cfg.period === 'annee') {
    // Années de l'union énergie + mobilité : se limiter aux années de factures
    // faisait disparaître du graphique un exercice couvert par la seule
    // mobilité.
    var anneesU = _bcAnneesAvecDonnees().map(function(d) { return d.annee; })
                                        .sort(function(a, b) { return a - b; });
    if (!anneesU.length) anneesU = [annee];
    buckets = anneesU.slice(-5).map(function(y) { return { key: y, label: String(y) }; });
    series.forEach(function(s) {
      data[s.key] = {};
      buckets.forEach(function(b) {
        data[s.key][b.key] = _bcAggregateSerie(s.key, b.key, 'annee', factures, calc, mob, meth, cfg.metric);
      });
    });
  } else if (cfg.period === 'trimestre') {
    buckets = [1,2,3,4].map(function(q) { return { key: annee + '-Q' + q, label: 'T' + q + ' ' + annee, q: q, y: annee }; });
    series.forEach(function(s) {
      data[s.key] = {};
      buckets.forEach(function(b) {
        data[s.key][b.key] = _bcAggregateSerie(s.key, { y: b.y, q: b.q }, 'trimestre', factures, calc, mob, meth, cfg.metric);
      });
    });
  } else {
    buckets = [];
    for (var m = 0; m < 12; m++) {
      var key = annee + '-' + String(m+1).padStart(2,'0');
      buckets.push({ key: key, label: new Date(annee, m, 1).toLocaleDateString('fr-FR', { month:'short' }), y: annee, m: m+1 });
    }
    series.forEach(function(s) {
      data[s.key] = {};
      buckets.forEach(function(b) {
        data[s.key][b.key] = _bcAggregateSerie(s.key, { y: b.y, m: b.m }, 'mois', factures, calc, mob, meth, cfg.metric);
      });
    });
  }

  // ── HTML : en-tête, panneau perso, chart ──────────────────────────────
  var meta       = _bcMetricMeta(cfg.metric);
  var typeLabels = { bar:'Barres groupées', line:'Lignes', stack:'Barres empilées' };

  var h = '';
  h += '<div class="card" style="margin-bottom:16px">';
  h += '  <div class="card-header">';
  h += '    <div>';
  h += '      <div class="card-title" style="font-size:14px;color:#16a34a">📊 Comparatif des postes</div>';
  h += '      <div style="font-size:11px;color:var(--gray-text);margin-top:2px">Mesure : <strong style="color:var(--text)">' + meta.label + '</strong> en <strong style="color:var(--text)">' + meta.unite + '</strong> · ' + typeLabels[cfg.type] + ' · par ' + ({mois:'mois', trimestre:'trimestre', annee:'année'}[cfg.period]) + '</div>';
  h += '    </div>';
  h += '    <button class="btn btn-ghost btn-sm" onclick="_bcToggleCustomize()" style="font-size:11px">⚙️ Personnaliser ' + (BilanCarboneUI.customizeOpen ? '▴' : '▾') + '</button>';
  h += '  </div>';

  // Panneau de personnalisation
  if (BilanCarboneUI.customizeOpen) {
    h += '<div style="padding:12px 20px;background:var(--gray-bg);border-bottom:1px solid var(--gray-border);display:flex;flex-wrap:wrap;gap:18px;align-items:center;font-size:12px">';
    h += _bcPillGroup('Type', ['bar','line','stack'], cfg.type, '_bcSetChartType', { bar:'📊 Barres', line:'📈 Lignes', stack:'📚 Empilé' });
    h += _bcPillGroup('Métrique', ['co2','prix_ttc','prix_ht','quantite'], cfg.metric, '_bcSetChartMetric', { co2:'CO₂', prix_ttc:'Prix TTC', prix_ht:'Prix HT', quantite:'Quantité' });
    h += _bcPillGroup('Période', ['mois','trimestre','annee'], cfg.period, '_bcSetChartPeriod', { mois:'Mois', trimestre:'Trim.', annee:'Année' });
    h += '<div style="display:flex;align-items:center;gap:6px">';
    h += '  <span style="color:var(--gray-text);font-weight:600">Couleurs</span>';
    series.forEach(function(s) {
      h += '  <label title="' + s.label + '" style="display:inline-flex;align-items:center;gap:4px;cursor:pointer">';
      h += '    <input type="color" value="' + s.color + '" onchange="_bcSetChartColor(\'' + s.key + '\', this.value)" style="width:24px;height:24px;border:1px solid var(--gray-border);border-radius:4px;padding:0;cursor:pointer;background:transparent">';
      h += '  </label>';
    });
    h += '</div>';
    h += '</div>';
  }

  // Zone de rendu SVG
  h += '<div style="padding:14px 20px 16px">';
  h += _bcRenderSvgChart(series, buckets, data, cfg, meta);
  h += '  <div style="display:flex;justify-content:center;gap:16px;flex-wrap:wrap;margin-top:10px;font-size:11px">';
  series.forEach(function(s) {
    h += '<div style="display:inline-flex;align-items:center;gap:5px"><span style="display:inline-block;width:11px;height:11px;background:' + s.color + ';border-radius:2px"></span>' + s.label + '</div>';
  });
  h += '  </div>';
  if (meta.avertissement) {
    h += '  <div style="margin-top:8px;padding:8px 12px;border-radius:8px;background:#fffbeb;border:1px solid #fcd34d;font-size:11px;color:#92400e">⚠️ ' + meta.avertissement + '</div>';
  }
  if (mob.length && cfg.period !== 'annee' && cfg.metric === 'co2') {
    h += '  <div style="margin-top:8px;font-size:10px;color:var(--gray-text);text-align:center;font-style:italic">Mobilité : projection annuelle répartie uniformément sur les ' + (cfg.period === 'mois' ? '12 mois' : '4 trimestres') + '.</div>';
  }
  h += '</div>';
  h += '</div>';
  el.innerHTML = h;
}

// ── Helper : agrégation d'une série pour un bucket donné ──────────────────
function _bcAggregateSerie(serieKey, bucket, period, factures, calc, mob, meth, metric) {
  if (serieKey === 'Mobilite') {
    if (typeof _co2Annuel !== 'function' || typeof _anneeLigne !== 'function' || metric !== 'co2') return 0;
    var totalAnnee = 0, y = (period === 'annee') ? bucket : bucket.y;
    mob.forEach(function(l) {
      if (_anneeLigne(l) !== y) return;
      if (typeof FiltresEngine !== 'undefined' && !FiltresEngine.matchRow('bilan_carbone', l)) return;
      totalAnnee += _co2Annuel(l, meth);
    });
    if (period === 'annee')     return totalAnnee;
    if (period === 'trimestre') return totalAnnee / 4;
    return totalAnnee / 12;
  }
  // Énergie : on somme les factures qui tombent dans le bucket
  var acc = 0;
  factures.forEach(function(f) {
    if (f._type !== serieKey) return;
    if (period === 'annee' && f._annee !== bucket) return;
    if (period !== 'annee' && f._annee !== bucket.y) return;
    if (period === 'mois') {
      var m = parseInt(String(f.Date || '').substring(5, 7));
      if (m !== bucket.m) return;
    } else if (period === 'trimestre') {
      var mo = parseInt(String(f.Date || '').substring(5, 7));
      if (!mo || Math.ceil(mo / 3) !== bucket.q) return;
    }
    if (metric === 'co2')      acc += calc(f);
    else if (metric === 'prix_ttc') acc += parseFloat(f.Montant)   || 0;
    else if (metric === 'prix_ht')  acc += parseFloat(f.MontantHT) || 0;
    else if (metric === 'quantite') {
      // Chaque série est un type d'énergie, mais deux compteurs du même type
      // peuvent avoir des unités différentes. On cumule dans l'unité de base
      // de la famille plutôt que d'additionner des nombres nus.
      var b = _bcVersBase(f.Consommation, f._unite);
      acc += b ? b.valeur : (parseFloat(f.Consommation) || 0);
    }
  });
  return acc;
}

// ── Helper : petit groupe de pastilles cliquables (utilisé dans le panneau) ─
function _bcPillGroup(label, options, current, setter, labels) {
  var h = '<div style="display:flex;align-items:center;gap:6px"><span style="color:var(--gray-text);font-weight:600">' + label + '</span>';
  options.forEach(function(o) {
    var active = o === current;
    h += '<span onclick="' + setter + '(\'' + o + '\')" style="padding:4px 10px;border-radius:14px;cursor:pointer;font-size:11px;font-weight:500;'
      +  'background:' + (active ? '#16a34a' : 'var(--card-bg)') + ';'
      +  'color:' + (active ? '#fff' : 'var(--text)') + ';'
      +  'border:1px solid ' + (active ? '#16a34a' : 'var(--gray-border)') + '">' + (labels[o] || o) + '</span>';
  });
  h += '</div>';
  return h;
}

// ── Helper : rendu SVG du graphique (bar / line / stack) ──────────────────
function _bcRenderSvgChart(series, buckets, data, cfg, meta) {
  meta = meta || _bcMetricMeta(cfg.metric);
  if (!buckets.length || !series.length) {
    return '<div style="text-align:center;color:var(--gray-text);font-size:12px;padding:30px">Aucune donnée à afficher pour cette période.</div>';
  }
  var W = 700, H = 240;
  var padL = 44, padR = 12, padT = 12, padB = 34;
  var innerW = W - padL - padR, innerH = H - padT - padB;

  // Max Y (empilé : somme des séries)
  var maxY = 0;
  buckets.forEach(function(b) {
    var sum = 0;
    series.forEach(function(s) {
      var v = data[s.key][b.key] || 0;
      if (cfg.type === 'stack') sum += v;
      else if (v > maxY) maxY = v;
    });
    if (cfg.type === 'stack' && sum > maxY) maxY = sum;
  });
  if (maxY <= 0) {
    // Toutes les séries à zéro : montre un état vide plutôt qu'un axe fantôme.
    return '<div style="text-align:center;color:var(--gray-text);font-size:12px;padding:30px">Aucune donnée disponible pour la période et la métrique choisies.</div>';
  }

  // Arrondi visuel : cherche un pas « propre » (1/2/5 × 10^n) qui laisse au
  // moins 3 graduations sous maxY. maxY=1000 doit donner 250 (pas 1000), pour
  // éviter un axe démesurément haut.
  var _niceStep = function(target) {
    var exp = Math.floor(Math.log10(target)); if (!isFinite(exp)) exp = 0;
    var mag = Math.pow(10, exp);
    var frac = target / mag;
    var niceFrac = frac <= 1 ? 1 : frac <= 2 ? 2 : frac <= 5 ? 5 : 10;
    return niceFrac * mag;
  };
  var step = _niceStep(maxY / 4);
  if (step === 0) step = 1;
  var maxYRounded = step * 4;
  while (maxYRounded < maxY) { step = _niceStep(step * 1.5); maxYRounded = step * 4; }

  var xStep = innerW / buckets.length;
  var yScale = function(v) { return padT + innerH - (v / maxYRounded * innerH); };

  var svg = '<svg viewBox="0 0 ' + W + ' ' + H + '" style="width:100%;height:auto;max-height:280px" preserveAspectRatio="xMidYMid meet">';
  // Unité de l'axe vertical, sans quoi les graduations ne veulent rien dire.
  svg += '<text x="2" y="9" font-size="9" font-weight="600" fill="var(--gray-text)" font-family="DM Sans, sans-serif">' + meta.court + '</text>';

  // Grille horizontale + labels Y
  for (var g = 0; g <= 4; g++) {
    var y = padT + innerH * g / 4;
    var val = maxYRounded * (1 - g / 4);
    svg += '<line x1="' + padL + '" y1="' + y + '" x2="' + (W - padR) + '" y2="' + y + '" stroke="var(--gray-border)" stroke-width="0.5" stroke-dasharray="' + (g === 4 ? '' : '2,3') + '"/>';
    svg += '<text x="' + (padL - 6) + '" y="' + (y + 3) + '" text-anchor="end" font-size="9" fill="var(--gray-text)" font-family="DM Sans, sans-serif">' + _bcFmtShort(val) + '</text>';
  }

  // Rendu selon le type
  if (cfg.type === 'line') {
    series.forEach(function(s) {
      var pts = buckets.map(function(b, i) {
        var v = data[s.key][b.key] || 0;
        return (padL + xStep * (i + 0.5)) + ',' + yScale(v);
      }).join(' ');
      svg += '<polyline points="' + pts + '" fill="none" stroke="' + s.color + '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>';
      buckets.forEach(function(b, i) {
        var v = data[s.key][b.key] || 0;
        svg += '<circle cx="' + (padL + xStep * (i + 0.5)) + '" cy="' + yScale(v) + '" r="3" fill="' + s.color + '"><title>' + s.label + ' — ' + b.label + ' — ' + _bcFmtShort(v) + ' ' + meta.court + '</title></circle>';
      });
    });
  } else if (cfg.type === 'stack') {
    buckets.forEach(function(b, i) {
      var yCursor = padT + innerH;
      series.forEach(function(s) {
        var v = data[s.key][b.key] || 0;
        if (v <= 0) return;
        var hSeg = v / maxYRounded * innerH;
        var barX = padL + xStep * i + xStep * 0.15;
        var barW = xStep * 0.7;
        yCursor -= hSeg;
        svg += '<rect x="' + barX + '" y="' + yCursor + '" width="' + barW + '" height="' + hSeg + '" fill="' + s.color + '"><title>' + s.label + ' — ' + b.label + ' — ' + _bcFmtShort(v) + ' ' + meta.court + '</title></rect>';
      });
    });
  } else { // 'bar' groupées
    var groupW = xStep * 0.72;
    var barW = groupW / series.length;
    buckets.forEach(function(b, i) {
      series.forEach(function(s, si) {
        var v = data[s.key][b.key] || 0;
        if (v <= 0) return;
        var hSeg = v / maxYRounded * innerH;
        var barX = padL + xStep * i + xStep * 0.14 + si * barW;
        svg += '<rect x="' + barX + '" y="' + (padT + innerH - hSeg) + '" width="' + (barW - 1) + '" height="' + hSeg + '" fill="' + s.color + '" rx="1"><title>' + s.label + ' — ' + b.label + ' — ' + _bcFmtShort(v) + ' ' + meta.court + '</title></rect>';
      });
    });
  }

  // Labels X
  buckets.forEach(function(b, i) {
    var cx = padL + xStep * (i + 0.5);
    svg += '<text x="' + cx + '" y="' + (H - padB + 14) + '" text-anchor="middle" font-size="10" fill="var(--gray-text)" font-family="DM Sans, sans-serif">' + b.label + '</text>';
  });

  svg += '</svg>';
  return svg;
}

/**
 * Unité d'un compteur. Priorité au champ saisi sur la fiche compteur, repli
 * sur l'unité par défaut du type. Sans unité connue, on ne totalise pas :
 * mieux vaut ne rien afficher qu'un chiffre faux.
 */
function _bcUniteCompteur(cpt) {
  if (!cpt) return '';
  if (cpt.Unite) return String(cpt.Unite).trim();
  if (typeof ENERGIE_CONFIG !== 'undefined' && ENERGIE_CONFIG[cpt.Type] && ENERGIE_CONFIG[cpt.Type].unite) {
    return ENERGIE_CONFIG[cpt.Type].unite;
  }
  return '';
}

/**
 * Rend un total de consommation ventilé par unité.
 *
 * Additionner les consommations toutes unités confondues produisait des
 * totaux absurdes : 10 MWh de chauffage + 102 kWh d'électricité affichait
 * « 112 », alors que ce sont 10 000 kWh et 102 kWh. Convertir automatiquement
 * serait pire — l'eau en m³ n'est pas convertible en kWh, et une conversion
 * masquée fausserait la lecture sans prévenir. On affiche donc un sous-total
 * par unité.
 *
 * @param {Object} parUnite  { 'kWh': 102, 'MWh': 10 }
 * @param {boolean} multiligne  empile les sous-totaux (cellule de tableau)
 */
/**
 * Rend un total de consommation. Les unités d'une même famille sont converties
 * puis cumulées (10 MWh + 102 kWh → 10,1 MWh) ; les familles incompatibles
 * restent séparées, parce qu'un m³ d'eau ne s'ajoute pas à des kWh.
 *
 * @param {Object}  tot         { parFamille: {energie: kWh, volume: m³}, inconnues }
 * @param {boolean} multiligne  empile les sous-totaux (cellule de tableau)
 */
function _bcFmtConsoTotal(tot, multiligne) {
  if (!tot) return '—';
  var parts = [];
  // Ordre stable : énergie puis volumes, quel que soit l'ordre d'arrivée.
  ['energie', 'volume'].forEach(function(fam) {
    var v = (tot.parFamille || {})[fam];
    if (v > 0) parts.push(_bcFmtDepuisBase(v, fam));
  });
  if (tot.inconnues > 0) {
    parts.push(new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 1 }).format(tot.inconnues) + ' (unité inconnue)');
  }
  if (!parts.length) return '—';
  return parts.join(multiligne ? '<br>' : ' · ');
}

// ── Helper : format court pour axes et tooltips ──────────────────────────
function _bcFmtShort(v) {
  if (!v || !isFinite(v)) return '0';
  if (v >= 1000000) return (v/1000000).toFixed(1) + ' M';
  if (v >= 1000)    return (v/1000).toFixed(v >= 10000 ? 0 : 1) + ' k';
  return v.toFixed(v < 10 ? 1 : 0);
}

/**
 * Ce que mesure chaque métrique, et dans quelle unité. Un graphique sans
 * unité n'est pas lisible : 4 000 peut être des kilos, des euros ou des kWh.
 *
 * Cas particulier « quantite » : les compteurs ne partagent pas la même unité
 * (kWh pour l'électricité et le gaz, MWh pour un réseau de chaleur, m³ pour
 * l'eau). Les additionner donne un nombre sans signification physique — c'est
 * dit explicitement plutôt que masqué derrière une unité inventée.
 */
function _bcMetricMeta(metric) {
  var m = {
    co2:      { label: 'Émissions',   unite: 'kgCO₂e',        court: 'kgCO₂e' },
    prix_ttc: { label: 'Coût TTC',    unite: '€ TTC',         court: '€' },
    prix_ht:  { label: 'Coût HT',     unite: '€ HT',          court: '€' },
    quantite: { label: 'Consommation', unite: 'kWh ou m³ selon le poste', court: 'kWh / m³',
                avertissement: 'Les kWh et les MWh sont convertis et cumulés en kWh. En revanche l\'eau se compte en m³ : ne comparez pas sa courbe à celles des énergies, et évitez l\'empilement qui les additionnerait.' },
  };
  return m[metric] || m.co2;
}

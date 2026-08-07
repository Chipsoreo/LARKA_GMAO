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
 * Larka — Page : Statistiques & Rapports
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Tableaux de bord analytiques avec graphiques (canvas 2D).
 * Répartition par famille, statut, coûts, tendances temporelles.
 * Filtres par période, bâtiment, famille.
 *
 * POINT D'ENTRÉE : renderStats()
 * ═══════════════════════════════════════════════════════════════════════════════
 */

// Couleurs par défaut
const PALETTE = ['#3b82f6','#10b981','#f59e0b','#ef4444','#8b5cf6','#06b6d4','#ec4899','#84cc16','#f97316','#6366f1'];

function couleur(i) { return PALETTE[i % PALETTE.length]; }
function fmtM(v) { return new Intl.NumberFormat('fr-FR',{style:'currency',currency:'EUR',maximumFractionDigits:0}).format(v||0); }
function fmtN(v) { return new Intl.NumberFormat('fr-FR').format(v||0); }

async function renderStatsAvancees() {
  document.getElementById('topbarActions').innerHTML = '';
  const c = document.getElementById('mainContent');
  showLoading(c);

  const filtres = App.getAdvancedFilters?.() || {};

  try {
    const s = await StatsCompletesApi.get(filtres);

    let html = `
    <!-- Filtres -->
    <div class="card" style="padding:0;margin-bottom:20px">
      <div style="padding:14px 20px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;border-bottom:1px solid var(--gray-border)">
        <span style="font-size:13px;font-weight:700;color:var(--gray-text)">🔍 Filtres</span>
        <div>
          <label style="font-size:11px;color:var(--gray-text);display:block;margin-bottom:2px">Période du</label>
          <input type="date" class="form-control" id="fa_dateDebut" style="font-size:13px" value="${filtres.dateDebut||''}" onchange="App.renderCurrentPage()">
        </div>
        <div>
          <label style="font-size:11px;color:var(--gray-text);display:block;margin-bottom:2px">Au</label>
          <input type="date" class="form-control" id="fa_dateFin" style="font-size:13px" value="${filtres.dateFin||''}" onchange="App.renderCurrentPage()">
        </div>
        <div>
          <label style="font-size:11px;color:var(--gray-text);display:block;margin-bottom:2px">Type d'intervention</label>
          <select class="form-control" id="fa_typeInterv" style="font-size:13px" onchange="App.renderCurrentPage()">
            <option value="${filtres.typeInterv||''}">Tous les types</option>
            ${['Préventive','Curative','Contrôle réglementaire','Divers'].map(t=>`<option value="${t}" ${filtres.typeInterv===t?'selected':''}>${t}</option>`).join('')}
          </select>
        </div>
        <div>
          <label style="font-size:11px;color:var(--gray-text);display:block;margin-bottom:2px">Statut intervention</label>
          <select class="form-control" id="fa_statutInterv" style="font-size:13px" onchange="App.renderCurrentPage()">
            <option value="">Tous</option>
            ${[
              {v:'Planifiée', l:'Planifiée'},
              {v:'En cours',  l:'En cours'},
              {v:'Réalisée',  l:'Réalisée'},
              {v:'Validée',   l:'Terminée'},
              {v:'Archivée',  l:'Archivée'},
            ].map(s=>`<option value="${s.v}" ${filtres.statutInterv===s.v?'selected':''}>${s.l}</option>`).join('')}
          </select>
        </div>
        <button class="btn btn-ghost btn-sm" onclick="App.resetAdvancedFilter()" style="margin-top:14px">✕ Réinitialiser</button>
      </div>
      <div style="padding:10px 20px;background:var(--gray-bg);display:flex;gap:20px;flex-wrap:wrap">
        <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
          <input type="checkbox" id="fa_showBiens" ${filtres.showBiens!=='0'?'checked':''} onchange="App.renderCurrentPage()"> Biens
        </label>
        <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
          <input type="checkbox" id="fa_showEquip" ${filtres.showEquip!=='0'?'checked':''} onchange="App.renderCurrentPage()"> Équipements
        </label>
        <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
          <input type="checkbox" id="fa_showInterv" ${filtres.showInterv!=='0'?'checked':''} onchange="App.renderCurrentPage()"> Interventions
        </label>
        <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
          <input type="checkbox" id="fa_showStock" ${filtres.showStock!=='0'?'checked':''} onchange="App.renderCurrentPage()"> Stock
        </label>
        <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
          <input type="checkbox" id="fa_showDemandes" ${filtres.showDemandes!=='0'?'checked':''} onchange="App.renderCurrentPage()"> Demandes
        </label>
        <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
          <input type="checkbox" id="fa_showContrats" ${filtres.showContrats!=='0'?'checked':''} onchange="App.renderCurrentPage()"> Contrats
        </label>
        <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
          <input type="checkbox" id="fa_showFactures" ${filtres.showFactures!=='0'?'checked':''} onchange="App.renderCurrentPage()"> Factures
        </label>
        <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
          <input type="checkbox" id="fa_showUsers" ${filtres.showUsers!=='0'?'checked':''} onchange="App.renderCurrentPage()"> Utilisateurs
        </label>
      </div>
    </div>

    <!-- Grille de stats -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">`;

    const show = (key) => filtres[key] !== '0';

    // ── Interventions ──
    if (show('showInterv')) {
    html += graphPie('🔧 Interventions par statut', s.intervParStatut, 'Statut', 'Total');

    // ── Interventions par type ──
    html += graphPie('⚙️ Interventions par type', s.intervParType, 'Type', 'Total');

    // ── Coût par mois (courbe) ──
    html += graphLigne('📈 Coût interventions / mois (12 derniers)', s.intervParMois, 'Mois', 'Cout', true);

    // ── Nb interventions par mois ──
    html += graphBarre('📅 Nombre interventions / mois', s.intervParMois, 'Mois', 'Total', false, 'blue');

    // ── Coût par société ──
    html += graphBarreH('💼 Coût par société / prestataire', s.coutParSociete, 'Societe', 'Total', true);

    } // end interventions

    // ── Biens ──
    if (show('showBiens')) {
    html += graphPie('🏢 Biens par famille', s.biensParFamille, 'Famille', 'Total');

    // ── Biens par bâtiment ──
    html += graphBarreH('🗺️ Biens par bâtiment', s.biensParBatiment, 'Batiment', 'Total', false);

    } // end biens

    // ── Équipements ──
    if (show('showEquip')) {
    html += graphPie('⚙️ Équipements par famille', s.equipsParFamille, 'Famille', 'Total');

    } // end équipements

    // ── Stock ──
    if (show('showStock')) {
    html += graphBarreH('📦 Valeur stock par catégorie', s.stockValeurParCat, 'Categorie', 'Valeur', true);

    // ── Coût pièces par intervention ──
    html += graphBarreH('🔩 Top coût pièces par intervention', s.coutStockParInterv, 'Numero', 'CoutPieces', true);

    } // end stock

    // ── Demandes ──
    if (show('showDemandes')) {
    html += graphPie('📝 Demandes par statut', s.demandesParStatut, 'Statut', 'Total');

    // ── Demandes par urgence ──
    html += graphPie('🚨 Demandes par urgence', s.demandesParUrgence, 'Urgence', 'Total');

    } // end demandes

    // ── Contrats ──
    if (show('showContrats')) {
    html += graphBarre('📋 Contrats par statut', s.contratsParStatut, 'Statut', 'Total', false, 'teal');

    } // end contrats

    // ── Factures / EJ (« avenants ») ──
    if (show('showFactures')) {
    const factTotal = (s.facturesParBudget||[]).reduce((a,r)=>a+(parseFloat(r.MontantTotal)||0),0);
    const factNb    = (s.facturesParBudget||[]).reduce((a,r)=>a+(parseInt(r.Nb)||0),0);
    html += cardStat('🧾 Total facturé (factures / EJ)', `
      <div style="display:flex;align-items:baseline;gap:10px;flex-wrap:wrap">
        <div style="font-size:30px;font-weight:800;color:var(--teal)">${fmtM(factTotal)}</div>
        <div style="font-size:13px;color:var(--gray-text)">sur ${fmtN(factNb)} facture${factNb>1?'s':''}</div>
      </div>
      <div style="font-size:11px;color:var(--gray-text);margin-top:6px">Montant TTC (ou HT si le TTC n'est pas renseigné), toutes interventions confondues.</div>`);
    html += graphBarre('🏷️ Montant facturé par budget (CBDC / CHMA)', s.facturesParBudget, 'TypeBudget', 'MontantTotal', true, 'teal');
    html += graphBarreH('🏢 Montant facturé par prestataire', s.facturesParFournisseur, 'Societe', 'Total', true);
    html += graphLigne('💶 Montant facturé / mois (12 derniers)', s.facturesParMois, 'Mois', 'MontantTotal', true);

    } // end factures

    // ── Utilisateurs ──
    if (show('showUsers')) {
    html += graphPie('👥 Utilisateurs par rôle', s.utilisateursParRole, 'Role', 'Total');

    } // end users
    html += `</div>`;
    c.innerHTML = html;
  App.restoreFilters();

    // Rendu SVG
    document.querySelectorAll('[data-chart]').forEach(el => {
      const type = el.dataset.chart;
      const data = JSON.parse(el.dataset.vals);
      if (type === 'pie') renderPie(el, data);
      else if (type === 'barH') renderBarH(el, data);
      else if (type === 'bar') renderBar(el, data);
      else if (type === 'line') renderLine(el, data);
    });

  } catch(e) { c.innerHTML = errorHtml(e.message); }
}

// ── Templates cartes ───────────────────────────────────────────────────────────
function cardStat(title, content) {
  return `<div class="card">
    <div class="card-header"><div class="card-title">${title}</div></div>
    <div style="padding:16px">${content}</div>
  </div>`;
}

function emptyCard(title) {
  return cardStat(title, `<div style="color:var(--gray-text);font-size:13px;text-align:center;padding:20px">Aucune donnée</div>`);
}

// ── Graphique Pie (SVG) ────────────────────────────────────────────────────────
function graphPie(title, rows, keyLabel, keyVal) {
  if (!rows?.length) return emptyCard(title);
  const total = rows.reduce((s,r)=>s+(parseFloat(r[keyVal])||0),0);
  const valsJson = JSON.stringify({rows,keyLabel,keyVal,total});
  return cardStat(title, `
    <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap">
      <svg data-chart="pie" data-vals='${escJ(valsJson)}' width="140" height="140" style="flex-shrink:0"></svg>
      <div style="flex:1;font-size:12px">
        ${rows.map((r,i)=>`<div style="display:flex;align-items:center;gap:6px;margin-bottom:4px">
          <div style="width:10px;height:10px;border-radius:50%;background:${couleur(i)};flex-shrink:0"></div>
          <span style="flex:1">${r[keyLabel]||'(vide)'}</span>
          <strong>${fmtN(r[keyVal])}</strong>
          <span style="color:var(--gray-text)">${total?Math.round((r[keyVal]/total)*100):'0'}%</span>
        </div>`).join('')}
      </div>
    </div>`);
}

// ── Graphique barres horizontales ──────────────────────────────────────────────
function graphBarreH(title, rows, keyLabel, keyVal, isMoney) {
  if (!rows?.length) return emptyCard(title);
  const max = Math.max(...rows.map(r=>parseFloat(r[keyVal])||0),1);
  const valsJson = JSON.stringify({rows,keyLabel,keyVal,max,isMoney});
  return cardStat(title, `<div data-chart="barH" data-vals='${escJ(valsJson)}'></div>`);
}

// ── Graphique barres verticales ────────────────────────────────────────────────
function graphBarre(title, rows, keyLabel, keyVal, isMoney, color='blue') {
  if (!rows?.length) return emptyCard(title);
  const valsJson = JSON.stringify({rows,keyLabel,keyVal,isMoney,color});
  return cardStat(title, `<div data-chart="bar" data-vals='${escJ(valsJson)}'></div>`);
}

// ── Courbe ─────────────────────────────────────────────────────────────────────
function graphLigne(title, rows, keyLabel, keyVal, isMoney) {
  if (!rows?.length) return emptyCard(title);
  const valsJson = JSON.stringify({rows:[...rows].reverse(),keyLabel,keyVal,isMoney});
  return cardStat(title, `<svg data-chart="line" data-vals='${escJ(valsJson)}' width="100%" height="160" style="display:block"></svg>`);
}

function escJ(s) { return s.replace(/'/g,"&#39;"); }

// ── Rendus SVG/HTML ────────────────────────────────────────────────────────────
function renderPie(el, {rows, keyLabel, keyVal, total}) {
  if (!total) return;
  const cx=70, cy=70, r=55;
  const stroke = getComputedStyle(document.body).getPropertyValue('--white').trim() || '#fff';
  // ⚠️ FIX : une seule catégorie (ou une part = 100%) produisait un arc SVG
  // dégénéré (point de départ = point d'arrivée) → camembert VIDE. On dessine
  // alors un cercle plein.
  const nonZero = rows.filter(row => (parseFloat(row[keyVal])||0) > 0);
  if (nonZero.length === 1) {
    const i = rows.indexOf(nonZero[0]);
    el.innerHTML = `<circle cx="${cx}" cy="${cy}" r="${r}" fill="${couleur(i)}" stroke="${stroke}" stroke-width="1.5"/>`;
    return;
  }
  let angle = -Math.PI/2;
  let paths = '';
  rows.forEach((row,i) => {
    const val = parseFloat(row[keyVal])||0;
    if (val <= 0) return;
    const sweep = (val/total)*Math.PI*2;
    const x1 = cx + r*Math.cos(angle), y1 = cy + r*Math.sin(angle);
    angle += sweep;
    const x2 = cx + r*Math.cos(angle), y2 = cy + r*Math.sin(angle);
    const large = sweep > Math.PI ? 1 : 0;
    paths += `<path d="M${cx},${cy} L${x1},${y1} A${r},${r},0,${large},1,${x2},${y2} Z" fill="${couleur(i)}" stroke="${stroke}" stroke-width="1.5"/>`;
  });
  el.innerHTML = paths;
}

function renderBarH(el, {rows, keyLabel, keyVal, max, isMoney}) {
  el.innerHTML = rows.map((r,i)=>{
    const val = parseFloat(r[keyVal])||0;
    const pct = Math.max(4,(val/max)*100);
    const fmt = isMoney ? fmtM(val) : fmtN(val);
    return `<div style="padding:6px 0;border-bottom:1px solid var(--gray-border)">
      <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:3px">
        <span style="font-weight:500">${r[keyLabel]||'(vide)'}</span>
        <span style="color:var(--gray-text)">${fmt}</span>
      </div>
      <div style="background:var(--gray-border);border-radius:4px;height:8px">
        <div style="background:${couleur(i)};width:${pct}%;height:8px;border-radius:4px;transition:width .3s"></div>
      </div>
    </div>`;
  }).join('');
}

function renderBar(el, {rows, keyLabel, keyVal, isMoney, color}) {
  const max = Math.max(...rows.map(r=>parseFloat(r[keyVal])||0),1);
  el.innerHTML = `<div style="display:flex;align-items:flex-end;gap:6px;height:100px;padding:8px 0">
    ${rows.map((r,i)=>{
      const val = parseFloat(r[keyVal])||0;
      const h = Math.max(4,(val/max)*80);
      return `<div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:4px" title="${r[keyLabel]}: ${isMoney?fmtM(val):fmtN(val)}">
        <span style="font-size:10px;color:var(--gray-text)">${isMoney?fmtM(val):fmtN(val)}</span>
        <div style="background:${couleur(i)};width:100%;height:${h}px;border-radius:3px 3px 0 0"></div>
        <span style="font-size:10px;color:var(--gray-text);text-align:center;overflow:hidden;max-width:60px;white-space:nowrap;text-overflow:ellipsis">${r[keyLabel]||'?'}</span>
      </div>`;
    }).join('')}
  </div>`;
}

function renderLine(el, {rows, keyLabel, keyVal, isMoney}) {
  const vals = rows.map(r=>parseFloat(r[keyVal])||0);
  const max  = Math.max(...vals,1);
  const W    = el.clientWidth || 400;
  const H    = 140;
  const pad  = {t:16,r:16,b:28,l:isMoney?60:36};
  const w    = W - pad.l - pad.r;
  const h    = H - pad.t - pad.b;
  const n    = rows.length;
  if (n < 1) return;

  const pts  = rows.map((r,i)=>[pad.l + (n>1 ? (i/(n-1))*w : w/2), pad.t + h - (vals[i]/max)*h]);

  // ⚠️ FIX : avec un seul point de données (ex. un seul mois), la courbe ne
  // s'affichait pas du tout (return anticipé). On dessine alors le point isolé
  // avec sa valeur, plutôt qu'un encart vide.
  if (n === 1) {
    const p = pts[0];
    const label = rows[0][keyLabel]?.substring(5) || rows[0][keyLabel] || '';
    el.innerHTML = `
      <circle cx="${p[0]}" cy="${p[1]}" r="5" fill="#3b82f6"/>
      <text x="${p[0]}" y="${p[1]-10}" text-anchor="middle" style="font-size:11px;font-weight:600;fill:#3b82f6">${isMoney?fmtM(vals[0]):fmtN(vals[0])}</text>
      <text x="${p[0]}" y="${H-4}" text-anchor="middle" style="font-size:10px;fill:var(--gray-text)">${label}</text>`;
    el.setAttribute('viewBox', `0 0 ${W} ${H}`);
    return;
  }

  const path = 'M' + pts.map(p=>p.join(',')).join(' L');
  const area = path + ` L${pts[pts.length-1][0]},${pad.t+h} L${pts[0][0]},${pad.t+h} Z`;

  let svgInner = `
    <defs><linearGradient id="lg" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#3b82f6" stop-opacity=".3"/><stop offset="100%" stop-color="#3b82f6" stop-opacity="0"/></linearGradient></defs>
    <path d="${area}" fill="url(#lg)"/>
    <path d="${path}" fill="none" stroke="#3b82f6" stroke-width="2.5" stroke-linejoin="round"/>`;

  // Points + labels
  pts.forEach((p,i)=>{
    svgInner += `<circle cx="${p[0]}" cy="${p[1]}" r="4" fill="#3b82f6"/>`;
    const label = rows[i][keyLabel]?.substring(5)||rows[i][keyLabel]||'';
    const align = i===0?'start':i===n-1?'end':'middle';
    svgInner += `<text x="${p[0]}" y="${H-4}" text-anchor="${align}" style="font-size:10px;fill:var(--gray-text)">${label}</text>`;
  });

  el.innerHTML = svgInner;
  el.setAttribute('viewBox', `0 0 ${W} ${H}`);
}

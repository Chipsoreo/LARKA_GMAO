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
 * Larka — Page : Tableau de bord (Dashboard)
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Page d'accueil après connexion. Affiche les indicateurs clés :
 *   - Nombre de biens, équipements, interventions actives
 *   - Alertes stock (seuil atteint) et contrats (expiration proche)
 *   - Demandes en attente de traitement
 *   - Graphiques de synthèse
 *
 * POINT D'ENTRÉE : renderDashboard()
 * ═══════════════════════════════════════════════════════════════════════════════
 */

async function renderDashboard() {
  document.getElementById('topbarActions').innerHTML = '';
  const c = document.getElementById('mainContent');
  showLoading(c);

  try {
    const { stats, interventionsPrevues, interventionsEnCours, stockAlerte, contratsAlerte } = await DashboardApi.get();
    const demandes = await DemandesApi.getAll();
    const demandesTech  = demandes.filter(d => !['Traité','Refusé'].includes(d.Statut) && d.TypeLocalisation !== 'Archive').slice(0, 6);
    const demandesArch  = demandes.filter(d => !['Traité','Refusé'].includes(d.Statut) && d.TypeLocalisation === 'Archive').slice(0, 6);

    // Le mini-graphe « Interventions » est désormais alimenté par un endpoint dédié
    // (interventions_mensuelles) et rendu de façon asynchrone via _loadDashboardIntervChart(),
    // ce qui permet de régler la période (3/6/12/24 mois) et de compter TOUTES les
    // interventions (réalisées incluses), et non plus seulement les planifiées/en cours.

    const badgeU = (u) => {
      const c = {Urgente:'red',Haute:'orange',Normale:'blue',Basse:'teal'};
      return `<span class="badge badge-${c[u]||'blue'}" style="font-size:10px">${u}</span>`;
    };
    const badgeS = (s) => {
      const c = {Nouveau:'orange','En cours':'blue','Traité':'teal','Refusé':'red','Relancé':'purple'};
      return `<span class="badge badge-${c[s]||'gray'}" style="font-size:10px">${s}</span>`;
    };

    let html = `
    <div id="dashPresence"></div>
    <div class="stats-grid">
      ${statCard('🏢','blue',   stats.totalBiens,           'Biens inventoriés')}
      ${statCard('⚙️','teal',   stats.totalEquipements,     'Équipements')}
      ${statCard('🔧','orange', stats.interventionsActives, 'Interventions actives')}
      ${statCard('📦', stats.stockEnAlerte   > 0 ? 'red':'teal', stats.stockEnAlerte,   'Articles en alerte stock')}
      ${statCard('📋', stats.contratsExpires > 0 ? 'red':'blue', stats.contratsExpires, 'Contrats expirés')}
      ${statCard('📝', stats.demandesNouveaux > 0 ? 'orange':'teal', stats.demandesNouveaux, 'Nouvelles demandes')}
    </div>

    <div class="dashboard-grid">
      <!-- DEMANDES — onglets Technique / Archive -->
      <div class="card">
        <div class="card-header" style="flex-wrap:wrap;gap:8px">
          <div class="card-title">📝 Dernières demandes</div>
          <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
            <div style="display:flex;border:1px solid var(--gray-border);border-radius:6px;overflow:hidden;background:var(--gray-bg)">
              <button id="dbTab_tech" onclick="switchDashboardDemTab('tech')"
                style="padding:4px 12px;border:none;font-size:12px;cursor:pointer;background:var(--blue);color:white;font-weight:600;display:flex;align-items:center;gap:4px">
                🔧 Technique<span style="background:rgba(255,255,255,.3);border-radius:8px;padding:0 5px;font-size:10px;margin-left:2px">${demandesTech.length}</span>
              </button>
              <button id="dbTab_arch" onclick="switchDashboardDemTab('arch')"
                style="padding:4px 12px;border:none;font-size:12px;cursor:pointer;background:transparent;color:var(--gray-text);font-weight:400;display:flex;align-items:center;gap:4px">
                🗄️ Archive<span style="background:var(--gray-border);border-radius:8px;padding:0 5px;font-size:10px;margin-left:2px;color:${demandesArch.length>0?'var(--orange)':'var(--gray-text)'}">${demandesArch.length}</span>
              </button>
            </div>
            <button class="btn btn-ghost btn-sm" id="dbTabLink" onclick="navigate('demandes')">Voir tout →</button>
          </div>
        </div>

        <div id="dbTabContent_tech">`;

    if (demandesTech.length === 0) {
      html += `<div style="padding:20px;text-align:center;color:var(--gray-text);font-size:13px">✅ Aucune demande technique en cours.</div>`;
    } else {
      html += `<table style="width:100%;border-collapse:collapse;font-size:13px">
        <thead><tr style="border-bottom:2px solid var(--gray-border);color:var(--gray-text)">
          <th style="padding:8px 16px;text-align:left;font-weight:600">Titre</th>
          <th style="padding:8px 16px;text-align:left;font-weight:600">Urgence</th>
          <th style="padding:8px 16px;text-align:left;font-weight:600">Statut</th>
          <th style="padding:8px 16px;text-align:left;font-weight:600">Demandeur</th>
          <th style="padding:8px 16px;text-align:left;font-weight:600">Date</th>
        </tr></thead><tbody>`;
      demandesTech.forEach(d => {
        const jours = Math.floor((Date.now()-new Date(d.DateCreation).getTime())/(864e5));
        html += `<tr style="border-bottom:1px solid var(--gray-border);cursor:pointer"
          onclick="navigate('demandes')"
          onmouseover="this.style.background='var(--gray-bg)'" onmouseout="this.style.background=''">
          <td style="padding:10px 16px;font-weight:500">${escHtml(d.Titre||'')}</td>
          <td style="padding:10px 16px">${badgeU(d.Urgence)}</td>
          <td style="padding:10px 16px">${badgeS(d.Statut)}</td>
          <td style="padding:10px 16px;color:var(--gray-text)">${escHtml(d.NomDeclarant||'—')}</td>
          <td style="padding:10px 16px;color:var(--gray-text);font-size:12px">${new Date(d.DateCreation).toLocaleDateString('fr-FR')}${jours>0?` <span style="color:var(--orange)">(${jours}j)</span>`:''}</td>
        </tr>`;
      });
      html += `</tbody></table>`;
    }

    html += `</div>
        <div id="dbTabContent_arch" style="display:none">`;

    if (demandesArch.length === 0) {
      html += `<div style="padding:20px;text-align:center;color:var(--gray-text);font-size:13px">✅ Aucune demande d'archive en cours.</div>`;
    } else {
      html += `<table style="width:100%;border-collapse:collapse;font-size:13px">
        <thead><tr style="border-bottom:2px solid var(--gray-border);color:var(--gray-text)">
          <th style="padding:8px 16px;text-align:left;font-weight:600">Prestation</th>
          <th style="padding:8px 16px;text-align:left;font-weight:600">Statut</th>
          <th style="padding:8px 16px;text-align:left;font-weight:600">Demandeur</th>
          <th style="padding:8px 16px;text-align:left;font-weight:600">Date solde</th>
          <th style="padding:8px 16px;text-align:left;font-weight:600">Créé le</th>
        </tr></thead><tbody>`;
      demandesArch.forEach(d => {
        let archInfo = null;
        try { archInfo = d.ArchiveData ? JSON.parse(d.ArchiveData) : null; } catch(e) {}
        const prestaLabel = {Archivage:'📦 Archivage', Desarchivage:'🗂️ Désarchivage'}[archInfo?.prestaArchive] || '—';
        const jours = Math.floor((Date.now()-new Date(d.DateCreation).getTime())/(864e5));
        html += `<tr style="border-bottom:1px solid var(--gray-border);cursor:pointer"
          onclick="navigate('archives')"
          onmouseover="this.style.background='var(--gray-bg)'" onmouseout="this.style.background=''">
          <td style="padding:10px 16px;font-weight:500">${prestaLabel}</td>
          <td style="padding:10px 16px">${badgeS(d.Statut)}</td>
          <td style="padding:10px 16px;color:var(--gray-text)">${escHtml(d.NomDeclarant||'—')}</td>
          <td style="padding:10px 16px;color:var(--gray-text)">${archInfo?.dateSolde ? new Date(archInfo.dateSolde).toLocaleDateString('fr-FR') : '—'}</td>
          <td style="padding:10px 16px;color:var(--gray-text);font-size:12px">${new Date(d.DateCreation).toLocaleDateString('fr-FR')}${jours>0?` <span style="color:var(--orange)">(${jours}j)</span>`:''}</td>
        </tr>`;
      });
      html += `</tbody></table>`;
    }

    html += `</div>
      </div>

      <div style="display:flex;flex-direction:column;gap:20px">
        <!-- Alertes stock -->
        <div class="card">
          <div class="card-header">
            <div class="card-title">📦 Alertes stock</div>
            <button class="btn btn-ghost btn-sm" onclick="navigate('stock')">Gérer →</button>
          </div>
          <div class="stock-alert-list">`;

    if (stockAlerte.length === 0) {
      html += `<div style="padding:20px;text-align:center;color:var(--gray-text);font-size:13px">✅ Aucune alerte stock</div>`;
    } else {
      stockAlerte.slice(0,5).forEach(s => {
        const pct   = Math.max(4, Math.min(100, (s.Quantite / (s.SeuilAlerte*2)) * 100));
        const color = s.Quantite===0 ? 'var(--red)' : s.Quantite<s.SeuilAlerte ? 'var(--orange)' : 'var(--teal)';
        html += `<div class="stock-alert-item">
          <div class="stock-bar-wrap">
            <div class="stock-bar-label">
              <span>${(s.Designation||'').slice(0,28)}</span>
              <span>${s.Quantite} / ${s.SeuilAlerte}</span>
            </div>
            <div class="stock-bar"><div class="stock-bar-fill" style="width:${pct}%;background:${color}"></div></div>
          </div>
        </div>`;
      });
    }

    html += `</div></div>

        <!-- Mini graphique interventions (période réglable) -->
        <div class="card">
          <div class="card-header" style="flex-wrap:wrap;gap:8px">
            <div class="card-title">📈 Interventions</div>
            <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
              <select id="dbIntervPeriod" onchange="_dbIntervPeriodChange(this.value)"
                style="padding:4px 8px;border:1px solid var(--gray-border);border-radius:6px;background:var(--card-bg);font-size:12px;color:var(--text);cursor:pointer">
                <option value="3">3 mois</option>
                <option value="6" selected>6 mois</option>
                <option value="12">12 mois</option>
                <option value="24">24 mois</option>
                <option value="custom">Période…</option>
              </select>
              <span id="dbIntervRange" style="display:none;gap:6px;align-items:center">
                <input type="month" id="dbIntervDebut" style="padding:3px 6px;border:1px solid var(--gray-border);border-radius:6px;background:var(--card-bg);font-size:12px;color:var(--text)">
                <span style="color:var(--gray-text)">→</span>
                <input type="month" id="dbIntervFin" style="padding:3px 6px;border:1px solid var(--gray-border);border-radius:6px;background:var(--card-bg);font-size:12px;color:var(--text)">
                <button onclick="_dbIntervApplyRange()" style="padding:4px 10px;border:none;border-radius:6px;background:var(--blue);color:#fff;font-size:12px;cursor:pointer">OK</button>
              </span>
            </div>
          </div>
          <div class="card-body">
            <div id="dbIntervChart" class="mini-bar-chart">
              <div style="width:100%;text-align:center;color:var(--gray-text);font-size:12px;padding:16px 0">⏳ Chargement…</div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- INTERVENTIONS PRÉVUES — pleine largeur -->
    <div style="margin-top:20px">
      <!-- Interventions prévues -->
      <div class="card">
        <div class="card-header">
          <div class="card-title">📅 Interventions prévues</div>
          <button class="btn btn-ghost btn-sm" onclick="navigate('interventions')">Voir tout →</button>
        </div>`;

    if (!interventionsPrevues || interventionsPrevues.length === 0) {
      html += `<div style="padding:24px;text-align:center;color:var(--gray-text);font-size:13px">✅ Aucune intervention prévue.</div>`;
    } else {
      html += `<table style="width:100%;border-collapse:collapse;font-size:13px">
        <thead><tr style="border-bottom:2px solid var(--gray-border);color:var(--gray-text)">
          <th style="padding:8px 10px;text-align:left;font-weight:600">Date</th>
          <th style="padding:8px 10px;text-align:left;font-weight:600">Société</th>
          <th style="padding:8px 10px;text-align:left;font-weight:600">Description</th>
          <th style="padding:8px 10px;text-align:left;font-weight:600">Qui</th>
          <th style="padding:8px 10px;text-align:center;font-weight:600">Actions</th>
        </tr></thead><tbody>`;
      interventionsPrevues.slice(0, 8).forEach(ip => {
        const datePrev = ip.DateIntervention ? new Date(ip.DateIntervention).toLocaleDateString('fr-FR') : '—';
        const societe = ip.ContratSociete || ip.SocieteManuelle || 'Interne';
        const desc = (ip.Description||'').slice(0,40) + ((ip.Description||'').length>40?'…':'');
        const qui = [ip.AgentPrenom, ip.AgentNom].filter(Boolean).join(' ') || '—';
        const typeBadge = ip.Type === 'Préventive' ? 'blue' : ip.Type === 'Curative' ? 'orange' : 'purple';
        // Calcul jours restants
        const joursRestants = ip.DateIntervention ? Math.ceil((new Date(ip.DateIntervention).getTime() - Date.now()) / 864e5) : null;
        const joursLabel = joursRestants !== null ? (joursRestants < 0 ? `<span style="color:var(--red);font-weight:600">${Math.abs(joursRestants)}j en retard</span>` : joursRestants === 0 ? `<span style="color:var(--orange);font-weight:600">Aujourd'hui</span>` : `<span style="color:var(--gray-text)">${joursRestants}j</span>`) : '';
        html += `<tr style="border-bottom:1px solid var(--gray-border)"
          onmouseover="this.style.background='var(--gray-bg)'" onmouseout="this.style.background=''">
          <td style="padding:8px 10px;white-space:nowrap">
            <div style="font-weight:500">${datePrev}</div>
            <div style="font-size:11px">${joursLabel}</div>
          </td>
          <td style="padding:8px 10px;color:var(--gray-text);font-size:12px">${societe}</td>
          <td style="padding:8px 10px">
            <div style="font-weight:500;font-size:12px">${desc}</div>
            <span class="badge badge-${typeBadge}" style="font-size:10px">${escHtml(ip.Type||'')}</span>
          </td>
          <td style="padding:8px 10px;color:var(--gray-text);font-size:12px">${qui}</td>
          <td style="padding:8px 10px;text-align:center;white-space:nowrap">
            <div style="display:flex;gap:4px;justify-content:center">
              ${canEdit()?`<button class="btn btn-ghost btn-sm" style="font-size:11px;padding:3px 8px;color:var(--blue);border-color:var(--blue)"
                onclick="event.stopPropagation();modifierDatePrevue(${ip.Id},'${ip.DateIntervention||''}','${(ip.Description||'').replace(/'/g,"\\'")}')">📅 Date</button>`:''}
              ${canEdit()?`<button class="btn btn-ghost btn-sm" style="font-size:11px;padding:3px 8px;color:var(--teal);border-color:var(--teal)"
                onclick="event.stopPropagation();realiserPrevue(${ip.Id},'${(ip.Description||'').replace(/'/g,"\\'")}')">✅ Réalisé</button>`:''}
            </div>
          </td>
        </tr>`;
      });
      html += `</tbody></table>`;
    }

    html += `</div>
    </div>`;

    // ═══ INTERVENTIONS EN COURS (prises en charge) ═══
    html += `
    <div style="margin-top:20px">
      <div class="card">
        <div class="card-header">
          <div class="card-title">🔧 Interventions en cours</div>
          <button class="btn btn-ghost btn-sm" onclick="navigate('interventions')">Voir tout →</button>
        </div>`;

    if (!interventionsEnCours || interventionsEnCours.length === 0) {
      html += `<div style="padding:24px;text-align:center;color:var(--gray-text);font-size:13px">Aucune intervention en cours.</div>`;
    } else {
      html += `<table style="width:100%;border-collapse:collapse;font-size:13px">
        <thead><tr style="border-bottom:2px solid var(--gray-border);color:var(--gray-text)">
          <th style="padding:8px 10px;text-align:left;font-weight:600">Pris en charge par</th>
          <th style="padding:8px 10px;text-align:left;font-weight:600">Description</th>
          <th style="padding:8px 10px;text-align:left;font-weight:600">Société</th>
          <th style="padding:8px 10px;text-align:left;font-weight:600">Depuis</th>
        </tr></thead><tbody>`;
      interventionsEnCours.slice(0, 10).forEach(ic => {
        const qui = [ic.AgentPrenom, ic.AgentNom].filter(Boolean).join(' ') || '—';
        const societe = ic.ContratSociete || ic.SocieteManuelle || 'Interne';
        const desc = (ic.Description||'').slice(0,50) + ((ic.Description||'').length>50?'…':'');
        const typeBadge = ic.Type === 'Préventive' ? 'blue' : ic.Type === 'Curative' ? 'orange' : 'purple';
        const depuis = ic.UpdatedAt ? new Date(ic.UpdatedAt).toLocaleDateString('fr-FR') : '—';
        html += `<tr style="border-bottom:1px solid var(--gray-border);cursor:pointer"
          onclick="navigate('interventions');setTimeout(()=>editInterv(${ic.Id}),300)"
          onmouseover="this.style.background='var(--gray-bg)'" onmouseout="this.style.background=''">
          <td style="padding:8px 10px">
            <div style="font-weight:600;color:var(--blue)">${qui}</div>
            ${ic.AgentEmail ? `<div style="font-size:10px;color:var(--gray-text)">${escHtml(ic.AgentEmail||'')}</div>` : ''}
          </td>
          <td style="padding:8px 10px">
            <div style="font-weight:500;font-size:12px">${desc}</div>
            <span class="badge badge-${typeBadge}" style="font-size:10px">${escHtml(ic.Type||'')}</span>
          </td>
          <td style="padding:8px 10px;color:var(--gray-text);font-size:12px">${societe}</td>
          <td style="padding:8px 10px;color:var(--gray-text);font-size:12px;white-space:nowrap">${depuis}</td>
        </tr>`;
      });
      html += `</tbody></table>`;
    }

    html += `</div>
    </div>`;

    c.innerHTML = html;
  App.restoreFilters();
  _loadDashboardIntervChart(6); // charge le mini-graphe des interventions (6 mois par défaut)

  } catch(e) { c.innerHTML = errorHtml(e.message); }

    // Aperçu de présence : chargé après coup, il ne doit jamais retarder
    // l'affichage du tableau de bord ni le faire échouer.
    _chargerApercuPresence();
}

// ── Mini-graphe « Interventions » : chargé à la demande, période réglable ────
// Alimenté par l'endpoint interventions_mensuelles (comptage réel de TOUTES les
// interventions par mois, réalisées incluses). Deux modes : presets (3/6/12/24 mois)
// ou période personnalisée « de … à … » (deux champs mois). Rendu isolé pour ne pas
// reconstruire tout le tableau de bord.

// Wrapper preset (nombre de mois) — aussi utilisé au chargement initial.
async function _loadDashboardIntervChart(mois) {
  const n = parseInt(mois, 10) || 6;
  const sel = document.getElementById('dbIntervPeriod');
  if (sel && sel.value !== String(n)) sel.value = String(n);
  const rng = document.getElementById('dbIntervRange'); if (rng) rng.style.display = 'none';
  await _dbFetchAndRenderChart(n);
}

// Changement du sélecteur : preset OU bascule vers la période personnalisée.
function _dbIntervPeriodChange(val) {
  const rng = document.getElementById('dbIntervRange');
  if (val === 'custom') {
    if (rng) rng.style.display = 'inline-flex';
    const d = document.getElementById('dbIntervDebut'), f = document.getElementById('dbIntervFin');
    if (d && f && (!d.value || !f.value)) { // pré-remplissage : 6 mois glissants
      const now = new Date(), ym = (dt) => dt.getFullYear() + '-' + String(dt.getMonth() + 1).padStart(2, '0');
      f.value = ym(now);
      d.value = ym(new Date(now.getFullYear(), now.getMonth() - 5, 1));
    }
    // Le graphe se recharge au clic sur OK (évite de recharger à chaque frappe).
  } else {
    _loadDashboardIntervChart(val);
  }
}

// Applique la plage personnalisée (de … à …).
function _dbIntervApplyRange() {
  const d = document.getElementById('dbIntervDebut'), f = document.getElementById('dbIntervFin');
  const debut = d && d.value, fin = f && f.value;
  if (!debut || !fin) { toast && toast('Choisissez un mois de début et de fin', 'error'); return; }
  let a = debut, b = fin; if (a > b) { const t = a; a = b; b = t; } // tolère l'inversion
  _dbFetchAndRenderChart({ debut: a, fin: b });
}

// Cœur : récupère les données (preset nombre de mois OU {debut,fin}) puis dessine.
async function _dbFetchAndRenderChart(spec) {
  const host = document.getElementById('dbIntervChart');
  if (!host) return;
  host.innerHTML = `<div style="width:100%;text-align:center;color:var(--gray-text);font-size:12px;padding:16px 0">⏳ Chargement…</div>`;
  try {
    const rows = await DashboardApi.interventionsMensuelles(spec); // [{mois,annee,label,count}, …] ordonné
    _dbRenderIntervChart(host, rows);
  } catch (e) {
    host.innerHTML = `<div style="width:100%;text-align:center;color:var(--red);font-size:12px;padding:16px 0">${escHtml(e.message || 'Erreur de chargement')}</div>`;
  }
}

// Dessine les barres à partir des lignes agrégées (libellés allégés sur longues périodes).
function _dbRenderIntervChart(host, rows) {
  const counts = (rows || []).map(r => Number(r.count) || 0);
  const maxM = Math.max(...counts, 1);
  const total = counts.reduce((a, b) => a + b, 0);
  const nb = rows ? rows.length : 0;
  if (!rows || nb === 0 || total === 0) {
    host.innerHTML = `<div style="width:100%;text-align:center;color:var(--gray-text);font-size:12px;padding:16px 0">Aucune intervention sur cette période.</div>`;
    return;
  }
  host.innerHTML = rows.map((r, i) => {
    const c = counts[i];
    const h = c === 0 ? 4 : Math.round((c / maxM) * 56);
    const bg = c === 0 ? 'var(--gray-border)' : 'var(--blue)';
    // Sur les longues périodes, on n'affiche que début de trimestre (+ 1er/dernier)
    // et on ajoute l'année abrégée en janvier, pour éviter le chevauchement d'étiquettes.
    const mm = String(r.mois || '').slice(5, 7);
    const dense = nb > 12;
    const showLabel = !dense || i === 0 || i === nb - 1 || ['01', '04', '07', '10'].includes(mm);
    let lbl = showLabel ? escHtml(r.label || '') : '';
    if (lbl && dense && mm === '01' && r.annee) lbl += ` '${String(r.annee).slice(2)}`;
    const title = `${escHtml(r.label || '')} ${r.annee || ''} — ${c} intervention${c > 1 ? 's' : ''}`;
    return `<div class="mini-bar-wrap" title="${title}">
      <div class="mini-bar" style="height:${h}px;opacity:0.75;background:${bg}"></div>
      <div class="mini-bar-label">${lbl}</div>
    </div>`;
  }).join('');
}

function statCard(icon, color, value, label) {  return `<div class="stat-card">
    <div class="stat-icon ${color}">${icon}</div>
    <div class="stat-info">
      <div class="stat-value">${value}</div>
      <div class="stat-label">${label}</div>
    </div>
  </div>`;
}
function switchDashboardDemTab(tab) {
  const isTech = tab === "tech";
  // Contenus
  const cTech = document.getElementById("dbTabContent_tech");
  const cArch = document.getElementById("dbTabContent_arch");
  if (cTech) cTech.style.display = isTech ? "" : "none";
  if (cArch) cArch.style.display = isTech ? "none" : "";
  // Boutons
  const bTech = document.getElementById("dbTab_tech");
  const bArch = document.getElementById("dbTab_arch");
  if (bTech) { bTech.style.background = isTech ? "var(--blue)" : "transparent"; bTech.style.color = isTech ? "white" : "var(--gray-text)"; bTech.style.fontWeight = isTech ? "600" : "400"; }
  if (bArch) { bArch.style.background = isTech ? "transparent" : "#15803d"; bArch.style.color = isTech ? "var(--gray-text)" : "white"; bArch.style.fontWeight = isTech ? "400" : "600"; }
  // Lien "Voir tout"
  const lnk = document.getElementById("dbTabLink");
  if (lnk) { lnk.onclick = () => navigate(isTech ? "demandes" : "archives"); }
}

// ── Interventions prévues : modifier la date ────────────────────────────────
function modifierDatePrevue(id, dateActuelle, descInterv) {
  openModal('📅 Modifier la date prévue', `
    <div style="margin-bottom:12px;font-size:13px;color:var(--gray-text)">
      Intervention : <strong>${descInterv}</strong>
    </div>
    <div style="display:flex;flex-direction:column;gap:12px">
      <div class="form-group">
        <label class="form-label">Date actuelle</label>
        <input class="form-control" type="date" value="${dateActuelle||''}" disabled style="opacity:.6">
      </div>
      <div class="form-group">
        <label class="form-label">Nouvelle date <span style="color:var(--red)">*</span></label>
        <input class="form-control" type="date" id="f_prevNewDate" value="${dateActuelle||''}">
      </div>
      <div class="form-group">
        <label class="form-label">Modifié par <span style="color:var(--red)">*</span></label>
        <input class="form-control" id="f_prevQui" placeholder="Votre nom" value="${App.currentUser?.Prenom ? App.currentUser.Prenom+' '+App.currentUser.Nom : ''}">
      </div>
      <div class="form-group">
        <label class="form-label">Raison du changement <span style="color:var(--red)">*</span></label>
        <textarea class="form-control" id="f_prevRaison" rows="2" placeholder="Pourquoi cette date est modifiée…"></textarea>
      </div>
    </div>
  `, async () => {
    const nouvelleDate = document.getElementById('f_prevNewDate')?.value;
    const qui = document.getElementById('f_prevQui')?.value?.trim();
    const raison = document.getElementById('f_prevRaison')?.value?.trim();
    if (!nouvelleDate) { toast('Veuillez saisir une nouvelle date.', 'error'); return; }
    if (!qui) { toast('Veuillez indiquer qui modifie la date.', 'error'); return; }
    if (!raison) { toast('Veuillez indiquer la raison du changement.', 'error'); return; }
    try {
      await IntervPrevuesApi.modifierDate(id, { nouvelleDate, qui, raison });
      toast('Date modifiée avec succès.', 'success');
      closeModal();
      renderDashboard();
    } catch(e) { toast(e.message, 'error'); }
  }, 'Enregistrer');
}

// ── Interventions prévues : marquer comme réalisée ──────────────────────────
function realiserPrevue(id, descInterv) {
  showConfirm(
    `Marquer comme réalisée et envoyer dans l'historique ?\n\n📋 ${descInterv}`,
    async () => {
      try {
        await IntervPrevuesApi.realiser(id);
        toast('Intervention réalisée et archivée.', 'success');
        renderDashboard();
      } catch(e) { toast(e.message, 'error'); }
    },
    'Réalisé ✅'
  );
}


/**
 * « Environ N personnes en ligne » — ordre de grandeur affiché en tête de
 * tableau de bord. Déduit du journal applicatif (activité réelle dans l'outil),
 * pas d'un statut Teams. Silencieux si indisponible : c'est un bonus, pas une
 * information critique.
 */
async function _chargerApercuPresence() {
  const el = document.getElementById('dashPresence');
  if (!el) return;
  // Deux sources, deux populations très différentes :
  //  • Teams (presence_effectif) → TOUTE l'organisation. C'est la bonne réponse
  //    à « combien de monde est là », mais elle demande deux permissions
  //    applicatives consenties côté Entra.
  //  • Journal (presence_apercu) → seulement les utilisateurs de la GMAO.
  //    Repli honnête, disponible sans aucune configuration.
  // Bandeau toujours rendu, même en cas d'échec : une carte vide ne dit pas si
  // « personne n'est connecté », si « la fonction est éteinte » ou si « ça a
  // planté ». Trois situations, trois actions différentes.
  const bandeau = (contenu, sourceLabel, note) => {
    el.innerHTML = `
      <div class="card" style="padding:10px 14px;margin-bottom:12px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <span style="width:8px;height:8px;border-radius:50%;background:var(--teal);flex:0 0 auto"></span>
        ${contenu}
        <span style="margin-left:auto;font-size:11px;color:var(--gray-text);cursor:help" title="${note || ''}">${sourceLabel}</span>
      </div>`;
  };

  let e = null, err = null;
  try { e = await JournalApi.effectif(); } catch (ex) { err = ex; }

  if (e && e.disponible && e.total) {
    const rond = (n) => (n < 10 ? n : Math.round(n / 5) * 5);
    const ok   = e.agendaRaison === 'ok';

    // Trois chiffres TOUJOURS visibles quand la lecture d'agenda a fonctionné,
    // y compris à zéro : « 0 en télétravail » est une information, l'absence
    // d'affichage n'en est pas une. Quand la lecture n'a PAS fonctionné, on dit
    // pourquoi plutôt que de laisser un vide qu'on prendrait pour un zéro.
    const pastille = (val, libelle, couleur) => `
      <span style="display:inline-flex;align-items:baseline;gap:5px;padding:2px 9px;border-radius:11px;
                   background:var(--gray-bg);white-space:nowrap">
        <strong style="color:${couleur};font-size:13px">${val}</strong>
        <span style="font-size:11px;color:var(--gray-text)">${libelle}</span>
      </span>`;

    const explication = {
      permission_agenda:   'répartition indisponible — permission Calendars.Read (type Application) non consentie',
      agenda_403_delegue:  'répartition indisponible — votre session Microsoft ne permet pas de lire les agendas partagés',
      aucun_motcle:        `aucun mot-clé trouvé dans ${e.agendaSujets} rendez-vous — personne n'a posé « TT » ou « Congés » aujourd'hui`,
      aucun_objet_lisible: 'aucun objet de rendez-vous lisible (tous privés ou agendas vides)',
      non_tente:           'agendas non interrogés',
    }[e.agendaRaison] || 'répartition indisponible';

    const corps = ok
      ? `${pastille(rond(e.surPlace),   'sur place',   'var(--teal)')}
         ${pastille(e.teletravail,      'en télétravail', 'var(--blue)')}
         ${pastille(e.absence,          'absents',     'var(--orange)')}`
      : `<span style="font-size:11.5px;color:var(--gray-text)">${escHtml(explication)}</span>`;

    const note = ok
      ? `Sur place = connectés moins télétravail et absences déclarés, calculé personne par personne. `
        + `Déclarations du jour : ${e.ttDeclare ?? 0} en TT, ${e.absDeclaree ?? 0} absences (toutes personnes, `
        + `y compris hors ligne). Un TT non posé dans l'agenda est compté sur place. Les rendez-vous privés ne sont pas lus.`
      : 'Statut Teams : indique une activité, pas une présence physique.';

    el.innerHTML = `
      <div class="card" style="padding:10px 14px;margin-bottom:12px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <span style="width:8px;height:8px;border-radius:50%;background:var(--teal);flex:0 0 auto"></span>
        <span style="font-size:13px;white-space:nowrap">
          <strong>environ ${rond(e.enLigne)} connecté${e.enLigne > 1 ? 's' : ''}</strong>
          <span style="color:var(--gray-text);font-weight:400" title="Personnes rattachées à un compte Microsoft dans l'application">/ ${e.total} connues</span>
        </span>
        <span style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">${corps}</span>
        <span style="margin-left:auto;font-size:11px;color:var(--gray-text);cursor:help" title="${note}">via Teams ⓘ</span>
      </div>`;
    return;
  }

  let p = null;
  try { p = await JournalApi.presence(15); } catch (ex) { err = err || ex; }

  if (!p || !p.disponible || !p.total) {
    // Rien d'exploitable : on explique quoi activer plutôt que de disparaître.
    const cause = (e && e.raison) ? {
      desactive:         'comptage Teams désactivé (plans_presence.actif dans config.json)',
      helpers_absents:   'module annuaire non chargé',
      token_applicatif:  'jeton applicatif Microsoft indisponible',
      graph_403_presence:'permission Presence.Read.All (type Application) non consentie — ou connectez-vous via Microsoft pour utiliser votre propre session',
      presence_403_delegue:'votre session Microsoft ne porte pas le droit de lecture de présence — déconnectez-vous puis reconnectez-vous via Microsoft',
      population_vide:   'aucun utilisateur rattaché à un compte Microsoft pour l\'instant — la liste se remplit dès que les gens se connectent via Microsoft',
      tous_optout:       'toutes les personnes connues ont masqué leur présence',
      erreur:            'erreur lors de l\'appel à Microsoft',
    }[e.raison] || ('indisponible — ' + e.raison)
      : (err ? 'endpoint injoignable' : 'aucune activité enregistrée');

    bandeau(
      `<span style="font-size:12.5px;color:var(--gray-text)">
         Présence indisponible — ${escHtml(cause)}.
       </span>`,
      'ⓘ', 'Comptage Teams : permissions applicatives à consentir dans Entra. '
          + 'Repli GMAO : basé sur le journal applicatif.');
    return;
  }

  // Arrondi volontaire : la mesure ne mérite pas une précision à l'unité.
  const arrondi = p.total < 10 ? p.total : Math.round(p.total / 5) * 5;
  const approx  = p.total < 10 ? '' : 'environ ';

  // Si toutes les IP sont identiques (reverse proxy sans X-Forwarded-For),
  // la répartition sur site / à distance n'a aucun sens : on ne l'affiche pas.
  const detail = (p.ipUniforme || (!p.surSite && !p.aDistance)) ? ''
    : ` <span style="color:var(--gray-text);font-weight:400">— ${p.surSite} sur site, ${p.aDistance} à distance</span>`;

  el.innerHTML = `
    <div class="card" style="padding:10px 14px;margin-bottom:12px;display:flex;align-items:center;gap:10px">
      <span style="width:8px;height:8px;border-radius:50%;background:var(--teal);flex:0 0 auto"></span>
      <span style="font-size:13px">
        <strong>${approx}${arrondi} personne${arrondi > 1 ? 's' : ''} active${arrondi > 1 ? 's' : ''} dans la GMAO</strong>${detail}
      </span>
      <span style="margin-left:auto;font-size:11px;color:var(--gray-text)">activité des ${p.minutes} dernières minutes</span>
    </div>`;
}

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
 * Larka — Filtres : Énergie (multi-panneaux)
 *
 * Le filtre énergie est découpé en 4 sous-panneaux indépendants :
 *
 *  • energie_facture    — Données factures/relevés (général)
 *  • energie_specifique — Plages tarifaires HPHS / HCHS / HPBS / HCBS + HP/HC + Charges
 *  • energie_stats      — Statistiques : métrique KPI + période d'analyse + KPI visibles
 *  • energie_graph      — Graphique : type + affichage courbes relevés/factures
 *
 * Rétro-compatibilité : la clé 'energie' est conservée comme alias vers
 * energie_facture pour les éventuels appels Legacy.
 */

// ─────────────────────────────────────────────────────────────────────────────
// 1. ENREGISTREMENTS
// ─────────────────────────────────────────────────────────────────────────────

FiltresEngine.register('energie_facture',    { fields: [], onFilter: () => true });
FiltresEngine.register('energie_specifique', { fields: [], onFilter: () => true });
FiltresEngine.register('energie_stats',      { fields: [], onFilter: () => true });
FiltresEngine.register('energie_graph',      { fields: [], onFilter: () => true });
FiltresEngine.register('energie',            { fields: [], onFilter: () => true }); // alias rétro-compat


// ─────────────────────────────────────────────────────────────────────────────
// 2. SURCHARGE renderPanel
// ─────────────────────────────────────────────────────────────────────────────
(function _patchRenderPanels() {
  const _orig = FiltresEngine.renderPanel.bind(FiltresEngine);
  FiltresEngine.renderPanel = function(page) {
    switch (page) {
      case 'energie_facture':    return _renderPanelFacture();
      case 'energie_specifique': return _renderPanelSpecifique();
      case 'energie_stats':      return _renderPanelStats();
      case 'energie_graph':      return _renderPanelGraph();
      case 'energie':            return _renderPanelFacture();
      default:                   return _orig(page);
    }
  };
})();


// ─────────────────────────────────────────────────────────────────────────────
// 3. HELPERS DE RENDU PARTAGÉS
// ─────────────────────────────────────────────────────────────────────────────

function _energiePanelWrap(pageKey, title, icon, accentColor, bodyHtml) {
  const isOpen = FiltresEngine._open[pageKey];
  const count  = FiltresEngine.activeCount(pageKey);
  if (!isOpen) return `<div id="filtresPanel_${pageKey}" style="display:none"></div>`;

  return `
  <div id="filtresPanel_${pageKey}" style="display:block;border-top:1px solid var(--gray-border);
    background:var(--gray-bg);padding:14px 20px;animation:fadeIn .15s ease">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
      <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--gray-text)">
        ${icon} ${title}
        ${count > 0
          ? `<span style="color:${accentColor}">(${count} filtre${count>1?'s':''} actif${count>1?'s':''})</span>`
          : ''}
      </span>
      <button class="btn btn-ghost btn-sm"
        onclick="FiltresEngine.resetAndRefresh('${pageKey}')"
        style="font-size:11px;color:var(--red)">✕ Tout effacer</button>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px;align-items:start">
      ${bodyHtml}
    </div>
  </div>`;
}

function _sep(label, active) {
  return `
  <div style="grid-column:1/-1;display:flex;align-items:center;gap:8px;margin-top:6px">
    <span style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;
      color:${active ? 'var(--blue)' : 'var(--gray-text)'};white-space:nowrap">
      ${label}${active ? ' <span style="color:var(--blue)">●</span>' : ''}
    </span>
    <div style="flex:1;height:1px;background:var(--gray-border)"></div>
  </div>`;
}

function _numF(pageKey, key, label, unit) {
  const state = FiltresEngine._state[pageKey] || {};
  const vMin  = state[key + '_min'] || '';
  const vMax  = state[key + '_max'] || '';
  return `<div style="min-width:180px">
    <label style="font-size:11px;font-weight:600;color:var(--gray-text);display:block;margin-bottom:4px">
      ${label}${unit ? ` <span style="font-weight:400;font-size:10px">${unit}</span>` : ''}
    </label>
    <div style="display:flex;gap:4px;width:100%">
      <input class="form-control" type="number" style="font-size:12px;flex:1;min-width:0" value="${vMin}"
        placeholder="Min" id="ff_${pageKey}_${key}_min"
        oninput="FiltresEngine._onInput('${pageKey}','${key}_min',this.value)">
      <input class="form-control" type="number" style="font-size:12px;flex:1;min-width:0" value="${vMax}"
        placeholder="Max" id="ff_${pageKey}_${key}_max"
        oninput="FiltresEngine._onInput('${pageKey}','${key}_max',this.value)">
    </div>
  </div>`;
}

function _textF(pageKey, key, label, placeholder) {
  const state = FiltresEngine._state[pageKey] || {};
  return `<div style="min-width:150px">
    <label style="font-size:11px;font-weight:600;color:var(--gray-text);display:block;margin-bottom:4px">${label}</label>
    <input class="form-control" type="text" style="font-size:12px;width:100%"
      placeholder="${placeholder || '…'}" value="${state[key] || ''}"
      id="ff_${pageKey}_${key}"
      oninput="FiltresEngine._onInput('${pageKey}','${key}',this.value)">
  </div>`;
}

function _selectF(pageKey, key, label, optsFn) {
  const state = FiltresEngine._state[pageKey] || {};
  const val   = state[key] || '';
  const opts  = typeof optsFn === 'function' ? optsFn() : optsFn;
  return `<div style="min-width:150px">
    <label style="font-size:11px;font-weight:600;color:var(--gray-text);display:block;margin-bottom:4px">${label}</label>
    <select class="form-control" style="font-size:12px;width:100%"
      onchange="FiltresEngine._onChange('${pageKey}','${key}',this.value)" id="ff_${pageKey}_${key}">
      <option value="">— Tous —</option>
      ${opts.map(o => {
        const v = typeof o === 'object' ? o.value : o;
        const l = typeof o === 'object' ? o.label : o;
        return `<option value="${v}" ${val===String(v)?'selected':''}>${l}</option>`;
      }).join('')}
    </select>
  </div>`;
}

function _chipsF(pageKey, key, label, options) {
  const state = FiltresEngine._state[pageKey] || {};
  const cur   = state[key] || 'Tous';
  return `<div style="min-width:180px">
    <label style="font-size:11px;font-weight:600;color:var(--gray-text);display:block;margin-bottom:4px">${label}</label>
    <div style="display:flex;flex-wrap:wrap;gap:4px">
      <button class="btn btn-sm ${cur==='Tous'?'btn-primary':'btn-ghost'}" style="font-size:11px;padding:3px 10px"
        onclick="FiltresEngine._onChange('${pageKey}','${key}','Tous')">Tous</button>
      ${options.map(o => {
        const v = typeof o === 'object' ? o.value : o;
        const l = typeof o === 'object' ? o.label : o;
        return `<button class="btn btn-sm ${cur===String(v)?'btn-primary':'btn-ghost'}" style="font-size:11px;padding:3px 10px"
          onclick="FiltresEngine._onChange('${pageKey}','${key}','${v}')">${l}</button>`;
      }).join('')}
    </div>
  </div>`;
}

function _daterangeF(pageKey, key, label) {
  const state = FiltresEngine._state[pageKey] || {};
  const vFrom = state[key + '_from'] || '';
  const vTo   = state[key + '_to']   || '';
  return `<div style="min-width:220px">
    <label style="font-size:11px;font-weight:600;color:var(--gray-text);display:block;margin-bottom:4px">${label}</label>
    <div style="display:flex;gap:6px">
      <input class="form-control" type="date" style="font-size:12px;flex:1" value="${vFrom}"
        id="ff_${pageKey}_${key}_from"
        onchange="FiltresEngine._onChange('${pageKey}','${key}_from',this.value)" title="Du">
      <input class="form-control" type="date" style="font-size:12px;flex:1" value="${vTo}"
        id="ff_${pageKey}_${key}_to"
        onchange="FiltresEngine._onChange('${pageKey}','${key}_to',this.value)" title="Au">
    </div>
  </div>`;
}

// Boutons chips pilotant directement EnergieStatsState (sans FiltresEngine state)
function _chipsDirect(items, activeVal, onclickFn, accentColor) {
  accentColor = accentColor || 'var(--blue)';
  return `<div style="display:flex;flex-wrap:wrap;gap:4px">
    ${items.map(([v, ico, lbl]) => {
      const a = activeVal === v;
      return `<button onclick="${onclickFn}('${v}')"
        style="padding:5px 11px;border:1px solid ${a ? accentColor : 'var(--gray-border)'};
          background:${a ? accentColor : 'white'};color:${a ? 'white' : 'var(--gray-text)'};
          border-radius:6px;cursor:pointer;font-size:12px;font-weight:${a?'600':'400'}">
        ${ico ? ico + ' ' : ''}${lbl}
      </button>`;
    }).join('')}
  </div>`;
}


// ─────────────────────────────────────────────────────────────────────────────
// 4. PANNEAU 1 — FACTURE / RELEVÉ
// ─────────────────────────────────────────────────────────────────────────────

function _renderPanelFacture() {
  const unite = ENERGIE_CONFIG[EnergieState?.onglet]?.unite || 'kWh';
  const body = `
    ${_sep('Général')}
    ${_chipsF('energie_facture', 'TypeSaisie', 'Type de saisie', [
        { value: 'Releve',  label: '🔵 Relevé'  },
        { value: 'Facture', label: '🟣 Facture' },
    ])}
    ${_selectF('energie_facture', 'CompteurId', 'Compteur', _energieCompteurOptions)}
    <div style="grid-column:span 2">${_daterangeF('energie_facture', 'dates', 'Période')}</div>
    ${_numF('energie_facture', 'Consommation', 'Conso globale', unite)}
    ${_numF('energie_facture', 'Montant', 'Montant TTC', '€')}
    ${_textF('energie_facture', 'Fournisseur', 'Fournisseur', 'Nom du fournisseur…')}
    ${_textF('energie_facture', 'NumeroFacture', 'N° Facture', 'Numéro…')}
  `;
  return _energiePanelWrap('energie_facture', 'Factures & Relevés', '🟣', 'var(--blue)', body);
}


// ─────────────────────────────────────────────────────────────────────────────
// 5. PANNEAU 2 — PLAGES SPÉCIFIQUES (HPHS / HCHS / HPBS / HCBS…)
// ─────────────────────────────────────────────────────────────────────────────

function _renderPanelSpecifique() {
  const st = FiltresEngine._state['energie_specifique'] || {};

  const htaActive = ['hphs_conso','hchs_conso','hpbs_conso','hcbs_conso'].some(k =>
    st[k+'_min'] || st[k+'_max']);
  const hphcActive = ['hp_conso','hc_conso'].some(k =>
    st[k+'_min'] || st[k+'_max']);
  const chargesActive = ['charge_abo','charge_cee','charge_ticfe','charge_oblicapa','charge_cta','charge_turpe'].some(k =>
    st[k+'_min'] || st[k+'_max']);

  const body = `
    ${_sep('Plages HTA 5 plages — Conso kWh', htaActive)}
    ${_numF('energie_specifique', 'hphs_conso', '🌞 HPHS — Hiver HP',  'kWh')}
    ${_numF('energie_specifique', 'hchs_conso', '🌙 HCHS — Hiver HC',  'kWh')}
    ${_numF('energie_specifique', 'hpbs_conso', '☀️ HPBS — Été HP',    'kWh')}
    ${_numF('energie_specifique', 'hcbs_conso', '💤 HCBS — Été HC',    'kWh')}

    ${_sep('Plages HP / HC — Conso kWh', hphcActive)}
    ${_numF('energie_specifique', 'hp_conso', '🌞 Heures Pleines', 'kWh')}
    ${_numF('energie_specifique', 'hc_conso', '🌙 Heures Creuses', 'kWh')}

    ${_sep('Charges & taxes — Montant HT (€)', chargesActive)}
    ${_numF('energie_specifique', 'charge_abo',      'Abonnement',       '€')}
    ${_numF('energie_specifique', 'charge_cee',      'CEE',              '€')}
    ${_numF('energie_specifique', 'charge_ticfe',    'TICFE / CSPE',     '€')}
    ${_numF('energie_specifique', 'charge_oblicapa', 'Oblig. Capacité',  '€')}
    ${_numF('energie_specifique', 'charge_cta',      'CTA',              '€')}
    ${_numF('energie_specifique', 'charge_turpe',    'TURPE / Achemin.', '€')}
  `;

  return _energiePanelWrap('energie_specifique', 'Plages HPHS / HCHS / HPBS / HCBS', '📐', '#f59e0b', body);
}


// ─────────────────────────────────────────────────────────────────────────────
// 6. PANNEAU 3 — STATISTIQUES (métrique + période + KPI visibles)
// ─────────────────────────────────────────────────────────────────────────────

function _renderPanelStats() {
  if (!FiltresEngine._open['energie_stats'])
    return `<div id="filtresPanel_energie_stats" style="display:none"></div>`;

  const S = window.EnergieStatsState || {};

  return `
  <div id="filtresPanel_energie_stats" style="display:block;border-top:1px solid var(--gray-border);
    background:#f0f9ff;padding:14px 20px;animation:fadeIn .15s ease">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
      <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--gray-text)">
        📊 Statistiques
      </span>
    </div>
    <div style="display:flex;flex-wrap:wrap;gap:20px;align-items:flex-start">

      <div>
        <label style="font-size:11px;font-weight:600;color:var(--gray-text);display:block;margin-bottom:6px">Métrique</label>
        ${_chipsDirect(
          [['conso','','Consommation'],['cout','','Coût'],['prixUnit','','Prix unit.']],
          S.metrique, 'switchEnergieMetrique', 'var(--teal)'
        )}
      </div>

      <div>
        <label style="font-size:11px;font-weight:600;color:var(--gray-text);display:block;margin-bottom:6px">Période d'analyse</label>
        ${_chipsDirect(
          [['3','','3 mois'],['6','','6 mois'],['12','','12 mois'],['all','','Tout']],
          S.periode, 'switchEnergiePeriode', '#9b59b6'
        )}
      </div>

      <div style="flex:1;min-width:200px">
        <label style="font-size:11px;font-weight:600;color:var(--gray-text);display:block;margin-bottom:6px">KPI affichés</label>
        <div style="display:flex;flex-wrap:wrap;gap:6px">
          ${[
            ['kpi_conso',    '⚡', 'Consommation', true ],
            ['kpi_cout',     '💶', 'Coût',         true ],
            ['kpi_contrat',  '📄', 'Contrat',      true ],
            ['kpi_tendance', '📈', 'Tendance',     true ],
            ['kpi_extremes', '🏆', 'Extrêmes',     false],
            ['kpi_carbone',  '🌿', 'Carbone',      true ],
          ].map(([id, ico, lbl, defOn]) => {
            const on = (S.kpiVisible || {})[id] ?? defOn;
            return `<button id="kpiBtn_${id}" onclick="toggleKpiGroupe('${id}')"
              style="padding:4px 10px;border:1px solid ${on ? 'var(--blue)' : 'var(--gray-border)'};
                background:${on ? 'var(--blue)' : 'white'};
                color:${on ? 'white' : 'var(--gray-text)'};
                border-radius:6px;cursor:pointer;font-size:11px;font-weight:${on?'600':'400'}">
              ${ico} ${lbl}
            </button>`;
          }).join('')}
        </div>
      </div>

    </div>
  </div>`;
}


// ─────────────────────────────────────────────────────────────────────────────
// 7. PANNEAU 4 — GRAPHIQUE (type + courbes)
// ─────────────────────────────────────────────────────────────────────────────

function _renderPanelGraph() {
  if (!FiltresEngine._open['energie_graph'])
    return `<div id="filtresPanel_energie_graph" style="display:none"></div>`;

  const S  = window.EnergieStatsState  || {};
  const SC = window.CarboneGraphState  || { periode:'12', graphType:'barres' };

  const btnG = (v, lbl, active) =>
    `<button onclick="switchEnergieGraph('${v}')"
      style="padding:5px 11px;border:1px solid ${active ? 'var(--blue)' : 'var(--gray-border)'};
        background:${active ? 'var(--blue)' : 'transparent'};color:${active ? 'white' : 'var(--gray-text)'};
        border-radius:6px;cursor:pointer;font-size:12px;font-weight:${active?'600':'400'}">${lbl}</button>`;

  const btnC = (fn, lbl, active, col) =>
    `<button onclick="${fn}"
      style="padding:5px 11px;border:1px solid ${active ? col : 'var(--gray-border)'};
        background:${active ? col : 'transparent'};color:${active ? 'white' : 'var(--gray-text)'};
        border-radius:6px;cursor:pointer;font-size:12px;font-weight:${active?'600':'400'}">${lbl}</button>`;

  return `
  <div id="filtresPanel_energie_graph" style="display:block;border-top:1px solid var(--gray-border);
    background:var(--gray-bg);padding:14px 20px;animation:fadeIn .15s ease">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
      <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--gray-text)">
        📈 Réglages graphiques
      </span>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0;border:1px solid var(--gray-border);border-radius:8px;overflow:hidden">

      <!-- ── Colonne gauche : Consommation / Coût ── -->
      <div style="padding:14px 16px;border-right:1px solid var(--gray-border)">
        <div style="font-size:11px;font-weight:700;color:var(--blue);margin-bottom:12px;display:flex;align-items:center;gap:6px">
          <span style="display:inline-block;width:8px;height:8px;background:var(--blue);border-radius:50%"></span>
          Consommation / Coût
        </div>

        <div style="margin-bottom:10px">
          <label style="font-size:11px;color:var(--gray-text);display:block;margin-bottom:6px;font-weight:600">Type de graphique</label>
          <div style="display:flex;flex-wrap:wrap;gap:4px">
            ${btnG('barres',    '📊 Barres',      S.graphType==='barres')}
            ${btnG('ligne',     '📈 Ligne',        S.graphType==='ligne')}
            ${btnG('area',      '📉 Aire',         S.graphType==='area')}
            ${btnG('comparaison','🔀 Par compteur', S.graphType==='comparaison')}
          </div>
        </div>

        <div style="margin-bottom:10px">
          <label style="font-size:11px;color:var(--gray-text);display:block;margin-bottom:6px;font-weight:600">Métrique</label>
          ${_chipsDirect(
            [['conso','','Consommation'],['cout','','Coût'],['prixUnit','','Prix unit.']],
            S.metrique, 'switchEnergieMetrique', 'var(--teal)'
          )}
        </div>

        <div style="margin-bottom:10px">
          <label style="font-size:11px;color:var(--gray-text);display:block;margin-bottom:6px;font-weight:600">Période</label>
          ${_chipsDirect(
            [['3','','3 m'],['6','','6 m'],['12','','12 m'],['all','','Tout']],
            S.periode, 'switchEnergiePeriode', '#9b59b6'
          )}
        </div>

        <div>
          <label style="font-size:11px;color:var(--gray-text);display:block;margin-bottom:6px;font-weight:600">Afficher</label>
          <div style="display:flex;gap:12px">
            <label style="display:flex;align-items:center;gap:5px;font-size:12px;cursor:pointer">
              <input type="checkbox" id="fs_showReleve" ${S.showReleve!==false?'checked':''}
                onchange="toggleStatsCourbes()">
              <span style="display:inline-block;width:10px;height:10px;background:var(--blue);border-radius:2px"></span>
              Relevés
            </label>
            <label style="display:flex;align-items:center;gap:5px;font-size:12px;cursor:pointer">
              <input type="checkbox" id="fs_showFacture" ${S.showFacture!==false?'checked':''}
                onchange="toggleStatsCourbes()">
              <span style="display:inline-block;width:10px;height:10px;background:#9b59b6;border-radius:2px"></span>
              Factures
            </label>
          </div>
        </div>
      </div>

      <!-- ── Colonne droite : CO₂ ── -->
      <div style="padding:14px 16px">
        <div style="font-size:11px;font-weight:700;color:#16a34a;margin-bottom:12px;display:flex;align-items:center;gap:6px">
          <span style="display:inline-block;width:8px;height:8px;background:#16a34a;border-radius:50%"></span>
          🌿 Émissions CO₂
        </div>

        <div style="margin-bottom:10px">
          <label style="font-size:11px;color:var(--gray-text);display:block;margin-bottom:6px;font-weight:600">Type de graphique CO₂</label>
          <div style="display:flex;gap:4px">
            ${btnC(`switchCarboneGraphType('barres')`, '📊 Barres', SC.graphType==='barres', '#16a34a')}
            ${btnC(`switchCarboneGraphType('ligne')`,  '📈 Ligne',  SC.graphType==='ligne',  '#16a34a')}
          </div>
        </div>

        <div>
          <label style="font-size:11px;color:var(--gray-text);display:block;margin-bottom:6px;font-weight:600">Période CO₂</label>
          <div style="display:flex;flex-wrap:wrap;gap:4px">
            ${[['3','3 m'],['6','6 m'],['12','12 m'],['all','Tout']].map(([v,l]) =>
              `<button onclick="switchCarbonePeriode('${v}')"
                style="padding:5px 11px;border:1px solid ${SC.periode===v?'#16a34a':'var(--gray-border)'};
                  background:${SC.periode===v?'#16a34a':'transparent'};color:${SC.periode===v?'white':'var(--gray-text)'};
                  border-radius:6px;cursor:pointer;font-size:12px;font-weight:${SC.periode===v?'600':'400'}">${l}</button>`
            ).join('')}
          </div>
        </div>
      </div>

    </div>
  </div>`;
}


// ─────────────────────────────────────────────────────────────────────────────
// 8. FONCTIONS PUBLIQUES UTILISÉES DANS energie.render.js
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Génère les 4 boutons filtres en ligne.
 * Remplace les anciens FiltresEngine.renderButton('energie') + btnFiltreStats
 */
function renderEnergieFilterButtons() {
  return [
    { page: 'energie_facture',    icon: '🟣', label: 'Facture',   accent: 'var(--blue)'   },
    { page: 'energie_specifique', icon: '📐', label: 'HPBS/HCHS', accent: '#f59e0b'       },
    { page: 'energie_stats',      icon: '📊', label: 'Stats',      accent: 'var(--teal)'  },
    { page: 'energie_graph',      icon: '📈', label: 'Graphique',  accent: '#9b59b6'       },
  ].map(({ page, icon, label, accent }) => {
    const count  = FiltresEngine.activeCount(page);
    const isOpen = FiltresEngine._open[page];
    const badge  = count > 0
      ? `<span style="background:${accent};color:#fff;border-radius:50%;min-width:16px;height:16px;
          display:inline-flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;
          margin-left:3px">${count}</span>`
      : '';
    const openStyle = isOpen
      ? `background:color-mix(in srgb,${accent} 12%,white);color:${accent};border-color:${accent};font-weight:600;`
      : '';
    return `<button class="btn btn-ghost btn-sm" id="btnFiltre_${page}"
      onclick="toggleEnergiePanel('${page}')"
      style="white-space:nowrap;display:inline-flex;align-items:center;gap:3px;${openStyle}">
      ${icon} ${label}${badge}
    </button>`;
  }).join('');
}

/**
 * Toggle un panneau (non-exclusif : plusieurs peuvent être ouverts en même temps).
 */
function toggleEnergiePanel(pageKey) {
  FiltresEngine._open[pageKey] = !FiltresEngine._open[pageKey];
  App.renderCurrentPage();
}

/**
 * Rend les 4 panneaux (seul l'ouvert est visible).
 */
function renderEnergieFilterPanels() {
  return ['energie_facture','energie_specifique','energie_stats','energie_graph']
    .map(p => FiltresEngine.renderPanel(p))
    .join('');
}


// ─────────────────────────────────────────────────────────────────────────────
// 9. OPTIONS DYNAMIQUES compteurs
// ─────────────────────────────────────────────────────────────────────────────

const _energieCptCache = {};
function _energieCompteurOptions() {
  const type = window.EnergieState?.onglet;
  if (!type || type === 'BilanCarbone') return [];
  if (_energieCptCache[type]) return _energieCptCache[type];
  EnergieApi.getCompteurs().then(cpts => {
    _energieCptCache[type] = cpts
      .filter(c => c.Type === type)
      .map(c => ({ value: String(c.Id), label: c.Nom }));
  }).catch(() => {});
  return _energieCptCache[type] || [];
}


// ─────────────────────────────────────────────────────────────────────────────
// 10. PARSER DetailHTA
// ─────────────────────────────────────────────────────────────────────────────

function _parseDetailHTA(row) {
  if (!row.DetailHTA) return {};
  if (typeof row.DetailHTA === 'object') return row.DetailHTA;
  try { return JSON.parse(row.DetailHTA); } catch(e) { return {}; }
}


// ─────────────────────────────────────────────────────────────────────────────
// 11. FILTRE NUMÉRIQUE GÉNÉRIQUE
// ─────────────────────────────────────────────────────────────────────────────

function _numFilter(state, key, rawValue) {
  const vMin = state[key + '_min'];
  const vMax = state[key + '_max'];
  const hasMin = vMin !== '' && vMin !== undefined && vMin !== null;
  const hasMax = vMax !== '' && vMax !== undefined && vMax !== null;
  if (!hasMin && !hasMax) return true;
  const n = parseFloat(rawValue) || 0;
  if (hasMin && n < parseFloat(vMin)) return false;
  if (hasMax && n > parseFloat(vMax)) return false;
  return true;
}


// ─────────────────────────────────────────────────────────────────────────────
// 12. FILTRE CENTRAL — lit energie_facture + energie_specifique
// ─────────────────────────────────────────────────────────────────────────────

function _applyEnergieFilters(liste) {
  const stFac  = FiltresEngine._state['energie_facture']    || {};
  const stSpec = FiltresEngine._state['energie_specifique'] || {};
  const search = (window.App?.searchTerm || '').toLowerCase();

  let r = [...liste];

  // Période d'affichage tableau
  if (window.EnergieTablePeriod?.months !== 'all') {
    const cutoff = new Date();
    cutoff.setMonth(cutoff.getMonth() - (parseInt(EnergieTablePeriod.months) || 12));
    r = r.filter(x => x.Date >= cutoff.toISOString().slice(0, 10));
  }

  // Recherche libre
  if (search) r = r.filter(x =>
    [x.NumeroFacture, x.Fournisseur, x.Commentaire, x.Type].some(v =>
      (v || '').toLowerCase().includes(search))
  );

  // ── Filtres Facture ──────────────────────────────────────────────────────
  if (stFac.Fournisseur) r = r.filter(x =>
    (x.Fournisseur || '').toLowerCase().includes(stFac.Fournisseur.toLowerCase())
  );
  if (stFac.NumeroFacture) r = r.filter(x =>
    (x.NumeroFacture || '').toLowerCase().includes(stFac.NumeroFacture.toLowerCase())
  );
  if (stFac.dates_from) r = r.filter(x => x.Date >= stFac.dates_from);
  if (stFac.dates_to)   r = r.filter(x => x.Date <= stFac.dates_to);
  r = r.filter(x => _numFilter(stFac, 'Consommation', x.Consommation));
  r = r.filter(x => _numFilter(stFac, 'Montant', x.Montant));
  const cptId = stFac.CompteurId;
  if (cptId && cptId !== 'Tous') r = r.filter(x => String(x.CompteurId) === cptId);

  // ── Filtres Spécifiques (plages HTA + charges) ───────────────────────────
  const detailKeys = [
    'hphs_conso','hchs_conso','hpbs_conso','hcbs_conso',
    'hp_conso','hc_conso',
    'charge_abo','charge_cee','charge_ticfe','charge_oblicapa','charge_cta','charge_turpe',
  ];
  const hasDetailFilter = detailKeys.some(k => stSpec[k+'_min'] || stSpec[k+'_max']);
  if (hasDetailFilter) {
    r = r.filter(x => {
      const det = _parseDetailHTA(x);
      if (!det || Object.keys(det).length === 0) return false;
      return detailKeys.every(k => _numFilter(stSpec, k, det[k]));
    });
  }

  r.sort((a, b) => b.Date.localeCompare(a.Date));
  return r;
}

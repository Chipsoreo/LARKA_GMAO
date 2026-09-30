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
 * Larka — Moteur de filtres avancés unifié
 * 
 * Chaque page enregistre ses filtres via FiltresEngine.register('page', config).
 * Le bouton "Recherche avancée" est injecté automatiquement à côté de la barre de recherche.
 *
 * Types de filtres supportés :
 *   - select   : liste déroulante
 *   - text     : champ texte libre
 *   - date     : champ date
 *   - daterange: deux champs date (Du/Au)
 *   - number   : champ numérique (min/max)
 *   - chips    : boutons cliquables (un seul actif)
 */

const FiltresEngine = {

  // Registre : { pageName: { fields: [...], onFilter: fn } }
  _registry: {},

  // État courant des valeurs par page : { pageName: { key: value } }
  _state: {},

  // Panneau ouvert ?
  _open: {},

  /**
   * Enregistrer les filtres pour une page
   * @param {string} page - nom de la page (ex: 'biens')
   * @param {Object} config - { fields: [...], onFilter?: fn }
   */
  register(page, config) {
    this._registry[page] = config;
    if (!this._state[page]) this._state[page] = {};
  },

  /** Récupérer la config d'une page */
  getConfig(page) {
    return this._registry[page] || null;
  },

  /** Récupérer l'état courant des filtres */
  getState(page) {
    return Object.assign({}, this._state[page] || {});
  },

  /** Mettre à jour une valeur */
  setValue(page, key, value) {
    if (!this._state[page]) this._state[page] = {};
    this._state[page][key] = value || '';
  },

  /** Réinitialiser les filtres d'une page */
  reset(page) {
    this._state[page] = {};
    this._open[page] = false;
  },

  /** Nombre de filtres actifs */
  activeCount(page) {
    const st = this._state[page] || {};
    const config = this._registry[page];
    if (!config) return Object.values(st).filter(v => v && v !== '' && v !== 'Tous').length;
    let count = 0;
    for (const f of (config.fields || [])) {
      switch (f.type) {
        case 'daterange':
          if (st[f.key + '_from'] || st[f.key + '_to']) count++;
          break;
        case 'number':
          if (st[f.key + '_min'] || st[f.key + '_max']) count++;
          break;
        default: {
          const v = st[f.key] || '';
          if (v && v !== 'Tous') count++;
        }
      }
    }
    return count;
  },

  /** Toggle panneau ouvert/fermé */
  toggle(page) {
    this._open[page] = !this._open[page];
    const panel = document.getElementById('filtresPanel');
    const btn = document.getElementById('btnFiltresAvance');
    if (panel) {
      panel.style.display = this._open[page] ? 'block' : 'none';
    }
    if (btn) {
      const active = this._open[page];
      btn.style.background = active ? 'var(--blue-pale,#dbeafe)' : '';
      btn.style.color = active ? 'var(--blue,#2563eb)' : '';
      btn.style.borderColor = active ? 'var(--blue,#2563eb)' : '';
      btn.style.fontWeight = active ? '600' : '';
    }
  },

  /**
   * Générer le HTML du bouton + panneau de filtres
   * @param {string} page
   * @returns {string} HTML
   */
  renderButton(page) {
    const config = this._registry[page];
    if (!config) return '';
    const count = this.activeCount(page);
    const badge = count > 0 ? `<span style="background:var(--blue,#2563eb);color:#fff;border-radius:50%;min-width:18px;height:18px;display:inline-flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;margin-left:4px">${count}</span>` : '';
    return `<button class="btn btn-ghost btn-sm" id="btnFiltresAvance" onclick="FiltresEngine.toggle('${page}')" style="white-space:nowrap;display:flex;align-items:center;gap:4px" title="Recherche avancée">
      🔎 Recherche avancée${badge}
    </button>`;
  },

  /**
   * Générer le HTML du panneau déroulant
   * @param {string} page
   * @returns {string} HTML
   */
  renderPanel(page) {
    const config = this._registry[page];
    if (!config) return '';
    const state = this._state[page] || {};
    const isOpen = this._open[page];

    let fieldsHtml = '';
    (config.fields || []).forEach(f => {
      fieldsHtml += `<div style="min-width:150px">`;
      fieldsHtml += `<label style="font-size:11px;font-weight:600;color:var(--gray-text);display:block;margin-bottom:4px">${f.label}</label>`;
      const val = state[f.key] || '';

      switch (f.type) {
        case 'select': {
          const opts = typeof f.options === 'function' ? f.options() : (f.options || []);
          fieldsHtml += `<select class="form-control" style="font-size:12px;width:100%" onchange="FiltresEngine._onChange('${page}','${f.key}',this.value)" id="ff_${f.key}">`;
          fieldsHtml += `<option value="">— Tous —</option>`;
          opts.forEach(o => {
            const oVal = typeof o === 'object' ? o.value : o;
            const oLbl = typeof o === 'object' ? o.label : o;
            fieldsHtml += `<option value="${oVal}" ${val===String(oVal)?'selected':''}>${oLbl}</option>`;
          });
          fieldsHtml += `</select>`;
          break;
        }
        case 'text':
          fieldsHtml += `<input class="form-control" type="text" style="font-size:12px;width:100%" placeholder="${f.placeholder||'…'}" value="${typeof escHtml==='function'?escHtml(val):val}" id="ff_${f.key}" autocomplete="off" oninput="FiltresEngine._onInput('${page}','${f.key}',this.value)">`;
          break;
        case 'date':
          fieldsHtml += `<input class="form-control" type="date" style="font-size:12px;width:100%" value="${val}" id="ff_${f.key}" onchange="FiltresEngine._onChange('${page}','${f.key}',this.value)">`;
          break;
        case 'daterange':
          const valFrom = state[f.key + '_from'] || '';
          const valTo   = state[f.key + '_to'] || '';
          fieldsHtml += `<div style="display:flex;gap:6px">
            <input class="form-control" type="date" style="font-size:12px;flex:1" value="${valFrom}" id="ff_${f.key}_from" onchange="FiltresEngine._onChange('${page}','${f.key}_from',this.value)" title="Du">
            <input class="form-control" type="date" style="font-size:12px;flex:1" value="${valTo}" id="ff_${f.key}_to" onchange="FiltresEngine._onChange('${page}','${f.key}_to',this.value)" title="Au">
          </div>`;
          break;
        case 'number': {
          const valMin = state[f.key + '_min'] || '';
          const valMax = state[f.key + '_max'] || '';
          fieldsHtml += `<div style="display:flex;gap:6px">
            <input class="form-control" type="number" style="font-size:12px;flex:1" value="${valMin}" placeholder="Min" id="ff_${f.key}_min" oninput="FiltresEngine._onInput('${page}','${f.key}_min',this.value)">
            <input class="form-control" type="number" style="font-size:12px;flex:1" value="${valMax}" placeholder="Max" id="ff_${f.key}_max" oninput="FiltresEngine._onInput('${page}','${f.key}_max',this.value)">
          </div>`;
          break;
        }
        case 'chips': {
          const opts = typeof f.options === 'function' ? f.options() : (f.options || []);
          const cur = val || 'Tous';
          fieldsHtml += `<div style="display:flex;flex-wrap:wrap;gap:4px">`;
          fieldsHtml += `<button class="btn btn-sm ${cur==='Tous'?'btn-primary':'btn-ghost'}" style="font-size:11px;padding:3px 10px" onclick="FiltresEngine._onChange('${page}','${f.key}','Tous')">Tous</button>`;
          opts.forEach(o => {
            const oVal = typeof o === 'object' ? o.value : o;
            const oLbl = typeof o === 'object' ? o.label : o;
            const oValEsc = String(oVal).replace(/'/g, "\\'");
            fieldsHtml += `<button class="btn btn-sm ${cur===String(oVal)?'btn-primary':'btn-ghost'}" style="font-size:11px;padding:3px 10px" onclick="FiltresEngine._onChange('${page}','${f.key}','${oValEsc}')">${oLbl}</button>`;
          });
          fieldsHtml += `</div>`;
          break;
        }
      }
      fieldsHtml += `</div>`;
    });

    const count = this.activeCount(page);
    return `
    <div id="filtresPanel" style="display:${isOpen?'block':'none'};border-top:1px solid var(--gray-border);background:var(--gray-bg);padding:14px 20px;animation:fadeIn .15s ease">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
        <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--gray-text)">
          🔎 Recherche avancée ${count > 0 ? `<span style="color:var(--blue)">(${count} filtre${count>1?'s':''} actif${count>1?'s':''})</span>` : ''}
        </span>
        <button class="btn btn-ghost btn-sm" onclick="FiltresEngine.resetAndRefresh('${page}')" style="font-size:11px;color:var(--red)">✕ Tout effacer</button>
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:10px;align-items:start">
        ${fieldsHtml}
      </div>
    </div>`;
  },

  /** Applique un filtre en matchant une ligne contre l'état courant */
  matchRow(page, row) {
    const config = this._registry[page];
    if (!config) return true;
    const state = this._state[page] || {};

    // Si un custom onFilter est défini, l'utiliser
    if (config.onFilter) return config.onFilter(row, state);

    // Sinon, matcher automatiquement champ par champ
    for (const f of config.fields) {
      const val = state[f.key] || '';
      // ⚠️ FIX : les filtres 'number' (min/max) et 'daterange' (from/to) ne
      // stockent PAS leur valeur sous state[f.key] mais sous des clés suffixées
      // (Prix_min, Prix_max, …_from, …_to). L'ancien garde « if (!val) continue »
      // les sautait donc systématiquement → le filtre Prix et les plages de
      // dates n'étaient jamais appliqués. On ne court-circuite que les types
      // qui utilisent réellement state[f.key].
      const usesSuffixedKeys = (f.type === 'number' || f.type === 'daterange');
      if (!usesSuffixedKeys && (!val || val === 'Tous')) continue;

      switch (f.type) {
        case 'select':
        case 'chips':
          if (String(row[f.key] || '') !== val) return false;
          break;
        case 'text':
          // Recherche avancée texte : insensible casse + accents, multi-mots
          // (helper partagé défini dans ui.js).
          if (typeof smartTextMatch === 'function') {
            if (!smartTextMatch(row[f.key] || '', val)) return false;
          } else if (!String(row[f.key] || '').toLowerCase().includes(val.toLowerCase())) {
            return false;
          }
          break;
        case 'date': {
          // LOGIQUE : filtre date actif = « à partir du ». Une ligne SANS date
          // ne peut pas satisfaire un critère de date → exclue (avant, elle
          // passait silencieusement, ce qui polluait les résultats).
          const rv = String(row[f.key] || '');
          if (!rv || rv < val) return false;
          break;
        }
        case 'daterange': {
          const from = state[f.key + '_from'] || '';
          const to   = state[f.key + '_to'] || '';
          if (!from && !to) break; // plage vide = pas de filtre
          const rv = String(row[f.dateKey || f.key] || '');
          // Même logique que 'date' : une plage active exclut les lignes sans date.
          if (!rv) return false;
          if (from && rv < from) return false;
          if (to && rv > to + 'T23:59:59') return false;
          break;
        }
        case 'number': {
          const min = state[f.key + '_min'];
          const max = state[f.key + '_max'];
          const hasMin = min !== '' && min !== undefined && min !== null;
          const hasMax = max !== '' && max !== undefined && max !== null;
          if (!hasMin && !hasMax) break;
          // Tolère les formats français : « 1 234,56 » (espaces fines incluses).
          const toNum = (x) => parseFloat(String(x ?? '').replace(/[\s\u00a0\u202f]/g, '').replace(',', '.'));
          const nv = toNum(row[f.key]);
          // Ligne sans valeur numérique : ne peut pas satisfaire une borne
          // (avant, `parseFloat(...) || 0` transformait le vide en 0, donc
          // « Prix max 100 » remontait aussi toutes les lignes sans prix).
          if (isNaN(nv)) return false;
          if (hasMin && !isNaN(toNum(min)) && nv < toNum(min)) return false;
          if (hasMax && !isNaN(toNum(max)) && nv > toNum(max)) return false;
          break;
        }
      }
    }
    return true;
  },

  // ── Handlers internes ──

  _onChange(page, key, value) {
    this.setValue(page, key, value === 'Tous' ? '' : value);
    this._refresh(page);
  },

  _onInput(page, key, value) {
    this.setValue(page, key, value);
    if (!this._debounceTimers) this._debounceTimers = {};
    clearTimeout(this._debounceTimers[page]);
    this._debounceTimers[page] = setTimeout(() => this._refresh(page), 300);
  },

  _refresh(page) {
    // Le re-render détruit/recrée les inputs du panneau : on capture le focus
    // avant et on le restaure après (sinon la frappe est coupée à chaque
    // rafraîchissement — voir App._captureFilterFocus pour le détail).
    const canPreserve = (typeof App !== 'undefined' && typeof App._captureFilterFocus === 'function');

    // If the registered config has an onRefresh callback, use that instead of full page re-render
    const config = this._registry[page];
    if (config && typeof config.onRefresh === 'function') {
      const focus = canPreserve ? App._captureFilterFocus() : null;
      const restore = () => { if (focus) App._restoreFilterFocus(focus); };
      let out;
      try { out = config.onRefresh(); } catch (e) { console.error('[FiltresEngine] onRefresh:', e); }
      if (out && typeof out.then === 'function') { out.then(restore).catch(restore); }
      else restore();
      return;
    }
    // Re-render la page (App.renderCurrentPage préserve déjà le focus lui-même)
    if (typeof App !== 'undefined' && App.renderCurrentPage) {
      App.renderCurrentPage();
    }
  },

  resetAndRefresh(page) {
    this.reset(page);
    App.searchTerm = '';
    const si = document.querySelector('.search-input');
    if (si) si.value = '';
    this._refresh(page);
  },

  _debounceTimers: {},
};

/**
 * Helper : charge les valeurs d'une catégorie de Liste pour les options de filtre
 * Utilise un cache mémoire pour éviter les appels répétés.
 */
const _filtresListesCache = {};

function _fl(categorie) {
  if (_filtresListesCache[categorie]) return _filtresListesCache[categorie];
  // Charger en asynchrone et mettre en cache (retourne [] la première fois, puis les données au re-render)
  ListesApi.getByCategorie(categorie).then(items => {
    _filtresListesCache[categorie] = items.map(x => x.Valeur);
  }).catch(() => {});
  return _filtresListesCache[categorie] || [];
}

/** Pré-charger toutes les listes utilisées par les filtres */
async function preloadFiltresListes() {
  const cats = ['FamilleBien','SousFamilleBien','StatutBien','FamilleEquipement','SousFamilleEquipement','StatutEquipement','Batiment','CategorieStock','TypeContrat'];
  await Promise.all(cats.map(async c => {
    try {
      const items = await ListesApi.getByCategorie(c);
      _filtresListesCache[c] = items.map(x => x.Valeur);
    } catch(_) {}
  }));
}

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
 * Larka — Helpers UI (Interface Utilisateur)
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Fonctions utilitaires partagées par toutes les pages.
 *
 * CONTENU :
 *   - fmtD(), fmtMon()         → formatage dates et montants (locale fr-FR)
 *   - joursDepuisDate()        → calcul d'écart en jours
 *   - toast()                  → notifications temporaires (succès/erreur)
 *   - showLoading()            → spinner de chargement dans un conteneur
 *   - openModal() / closeModal() → système de modales dynamiques
 *   - showConfirm()            → dialogue de confirmation (suppression, etc.)
 *   - gv() / sv()              → raccourcis lecture/écriture valeur d'un champ
 *   - listFieldHtml()          → génère un <select> ou <input+datalist> depuis une liste
 *   - listLabelHtml()          → génère un label avec étoile * si obligatoire
 *   - reqLabel() / reqAttr()   → champs obligatoires configurables
 *   - renderTable()            → tableau paginé avec tri, filtres, recherche
 *   - docsPanelHtml()          → panneau de documents joints (upload/preview)
 *   - enhanceSelectTypeahead() → recherche dans les <select> longs
 * ═══════════════════════════════════════════════════════════════════════════════
 */

// ── Formatage ──────────────────────────────────────────────────────────────────
function fmt(val, type = 'text') {
  if (val === null || val === undefined || val === '') return '<span style="color:var(--gray-text)">—</span>';
  if (type === 'date')  return new Date(val).toLocaleDateString('fr-FR');
  if (type === 'money') return new Intl.NumberFormat('fr-FR', { style:'currency', currency:'EUR' }).format(val);
  return String(val);
}

const BADGE_MAP = {
  'Installé':'teal',   'Livré':'blue',       'Commandé':'orange',
  'Utilisé':'teal',    'Stock':'gray',
  'Actif':'teal',      'Expiré':'red',        'Résilié':'red',
  'Réalisée':'teal',   'Planifiée':'blue',    'En cours':'orange',
  'Gestionnaire':'teal', 'Visionneur':'gray', 'Demandeur':'purple',
  'Préventive':'blue', 'Curative':'orange',
  'Urgente':'red',     'Normale':'orange',    'Basse':'gray',        'Haute':'orange',
  'Traité':'teal',     'Refusé':'red',        'Relancé':'purple',
};

function badge(val) {
  if (!val) return '<span style="color:var(--gray-text)">—</span>';
  const cls = BADGE_MAP[val] || 'gray';
  return `<span class="badge badge-${cls}">${val}</span>`;
}

// ── Toast ──────────────────────────────────────────────────────────────────────
function toast(msg, type = '') {
  const icons = { success:'✓', error:'✕', warning:'⚠' };
  const div   = document.createElement('div');
  div.className = `toast ${type}`;
  div.innerHTML = `<span>${icons[type]||'ℹ'}</span> ${msg}`;
  document.getElementById('toastContainer').appendChild(div);
  setTimeout(() => div.remove(), 3500);
}

function showLoading(container) {
  container.innerHTML = `<div class="loading-spinner"><div class="spinner"></div></div>`;
}

// ── Modal ──────────────────────────────────────────────────────────────────────
let _modalSaveFn = null;

/**
 * @param {string}   title
 * @param {string}   bodyHTML
 * @param {function|null} saveFn       null = mode lecture seule (bouton save masqué)
 * @param {string}   saveLabel
 * @param {string}   cancelLabel      "Annuler" par défaut, "Fermer"/"Sortir" si besoin
 */
function openModal(title, bodyHTML, saveFn, saveLabel = 'Enregistrer', cancelLabel = 'Annuler', modalWidth = null) {
  document.getElementById('modalTitle').textContent    = title;
  document.getElementById('modalBody').innerHTML       = bodyHTML;
  document.getElementById('modalCancelBtn').textContent = cancelLabel ?? 'Annuler';
  const saveBtn = document.getElementById('modalSaveBtn');
  // ⚠️ Restaurer le style par défaut du bouton "Enregistrer" : certaines
  // modales (ex: suppression) le repassent en btn-danger ; on remet btn-primary
  // pour ne pas teinter par erreur la modale suivante.
  saveBtn.classList.remove('btn-danger', 'btn-ghost');
  saveBtn.classList.add('btn-primary');
  if (saveFn) {
    saveBtn.style.display    = '';
    saveBtn.textContent      = saveLabel ?? 'Enregistrer';
  } else {
    saveBtn.style.display    = 'none';
  }
  // Largeur personnalisée (ex: pour Chauffage avec nombreux champs)
  const modalEl = document.querySelector('.modal');
  if (modalEl) modalEl.style.width = modalWidth || '';
  document.getElementById('modalOverlay').classList.add('open');
  _modalSaveFn = saveFn;
  // Focus premier input
  setTimeout(() => document.getElementById('modalBody').querySelector('input,select,textarea')?.focus(), 80);
}

function closeModal() {
  document.getElementById('modalOverlay').classList.remove('open');
  _modalSaveFn = null;
}

async function saveModal() { if (_modalSaveFn) { try { await _modalSaveFn(); } catch(e) { if (typeof toast === 'function') toast(e.message || 'Erreur', 'error'); } } }

document.addEventListener('DOMContentLoaded', () => {
  document.getElementById('modalOverlay').addEventListener('click', e => {
    if (e.target === document.getElementById('modalOverlay')) closeModal();
  });
});

// ── Confirmation ───────────────────────────────────────────────────────────────
function showConfirm(msg, cb, confirmLabel = 'Supprimer') {
  const modal = document.getElementById('confirmModal');
  document.getElementById('confirmMsg').textContent = msg;
  document.getElementById('confirmOkBtn').textContent = confirmLabel;
  // ⚠️ Garde-fou : si un autre composant a appliqué un style inline qui
  // masquerait le footer (boutons Annuler/Supprimer), on le réinitialise.
  // Ceci protège contre des effets de bord (bug historique : biens-import
  // ciblait par erreur #confirmModal au lieu de #modalOverlay).
  const footer = modal.querySelector('.modal-footer');
  if (footer && footer.style.display === 'none') footer.style.display = '';
  modal.classList.add('open');
  document.getElementById('confirmOkBtn').onclick = () => { closeConfirm(); cb(); };
}
function closeConfirm() { document.getElementById('confirmModal').classList.remove('open'); }

// ── Helpers champs ─────────────────────────────────────────────────────────────
function gv(id)       { return document.getElementById(id)?.value ?? ''; }
function sv(id, val)  { const el = document.getElementById(id); if (el) el.value = val; }

/**
 * Génère un champ de formulaire basé sur une liste déroulante,
 * respectant les flags Obligatoire et SaisieLibre de la configuration.
 * @param {string} fieldId  - ID du champ HTML
 * @param {Array}  items    - [{Valeur, Obligatoire, SaisieLibre}] depuis ListesApi.getByCategorie
 * @param {string} selected - valeur actuellement sélectionnée
 * @param {Object} opts     - {placeholder, emptyOption}
 * @returns {string} HTML
 */
function listFieldHtml(fieldId, items, selected, opts = {}) {
  const isCatOblig = items.some(x => x.Obligatoire == 1);
  const isCatLibre = items.some(x => x.SaisieLibre == 1);
  const reqMark = isCatOblig ? ' <span class="req">*</span>' : '';
  const reqAttr = isCatOblig ? 'required' : '';

  if (isCatLibre) {
    // Saisie libre : input + datalist
    const listId = fieldId + '_dl';
    return `<input class="form-control" id="${fieldId}" list="${listId}" value="${selected||''}" ${reqAttr} placeholder="${opts.placeholder||'Choisir ou saisir…'}">
      <datalist id="${listId}">${items.map(x => `<option value="${x.Valeur}">`).join('')}</datalist>`;
  }
  // Select classique
  const emptyOpt = !isCatOblig ? `<option value="">${opts.emptyOption||'—'}</option>` : '';
  return `<select class="form-control" id="${fieldId}" ${reqAttr}>${emptyOpt}${items.map(x =>
    `<option ${selected === x.Valeur ? 'selected' : ''}>${x.Valeur}</option>`
  ).join('')}</select>`;
}

/** Retourne le label HTML avec * si obligatoire */
function listLabelHtml(labelText, items) {
  const isCatOblig = items.some(x => x.Obligatoire == 1);
  return `${labelText}${isCatOblig ? ' <span class="req">*</span>' : ''}`;
}


function renderPendingDocsPreview(key) {
  const el = document.getElementById('pendingDocsPreview_' + key);
  if (!el) return;
  const docs = window['_pendingDocs_' + key] || [];
  el.innerHTML = docs.map((d, i) => `
    <div style="display:flex;align-items:center;gap:6px;padding:6px 10px;background:var(--gray-bg);border-radius:6px;font-size:12px">
      <span>${d.mime?.startsWith('image/') ? '📷' : '📄'} ${d.name.substring(0,20)}${d.name.length>20?'…':''}</span>
      <button onclick="window._pendingDocs_${key}.splice(${i},1);renderPendingDocsPreview('${key}')" style="background:none;border:none;color:#e74c3c;cursor:pointer;font-size:14px;padding:0">×</button>
    </div>`).join('');
}

// ── renderTable ────────────────────────────────────────────────────────────────
/**
 * @param {HTMLElement}  container
 * @param {Array}        data
 * @param {Array}        columns          [{key, label, badge, type, editFn, deleteFn}]
 * @param {string|null}  addBtnFn
 * @param {string}       extraButtons    HTML libre injecté juste avant le bouton "+ Nouveau"
 * @param {Object|null}  filters          { values:[], fn:(row,filter)=>bool }
 * @param {string}       searchTerm
 * @param {string}       currentFilter
 * @param {boolean}      canEdit
 * @param {boolean}      canDelete
 * @param {Object|null}  advancedFilter   { fields:[{key,label,type,options}] }
 */
function renderTable({
  container, data, columns, addBtnFn = null, extraButtons = '',
  filters = null, searchTerm = '', currentFilter = 'Tous',
  canEdit = true, canDelete = true,
  advancedFilter = null,
}) {
  // Tri
  let sorted = [...data];
  if (App._sortKey) {
    const key = App._sortKey;
    const dir = App._sortDir === 'asc' ? 1 : -1;
    sorted.sort((a, b) => {
      let va = a[key] ?? '', vb = b[key] ?? '';
      // Numérique
      if (!isNaN(parseFloat(va)) && !isNaN(parseFloat(vb))) return (parseFloat(va) - parseFloat(vb)) * dir;
      // Date
      if (typeof va === 'string' && va.match(/^\d{4}-/)) return va.localeCompare(vb) * dir;
      // Texte
      return String(va).localeCompare(String(vb), 'fr', {sensitivity:'base'}) * dir;
    });
  }

  // Filtrage
  const _fePage = App.currentPage || '';
  const filtered = sorted.filter(row => {
    const matchFilter = !filters || currentFilter === 'Tous' || filters.fn(row, currentFilter);
    const matchSearch = !searchTerm || columns.some(c => {
      const v = row[c.key] ?? '';
      return smartTextMatch(v, searchTerm);
    });
    // FiltresEngine (nouveau système unifié)
    const matchAdvanced = FiltresEngine.getConfig(_fePage)
      ? FiltresEngine.matchRow(_fePage, row)
      : (!advancedFilter || (() => {
          const vals = App.getAdvancedFilters();
          return advancedFilter.fn ? advancedFilter.fn(row, vals) :
            Object.entries(vals).every(([k,v]) => !v || smartTextMatch(row[k] || '', v));
        })());
    return matchFilter && matchSearch && matchAdvanced;
  });

  // Pagination (après filtrage/recherche)
  const perPage = (typeof App !== 'undefined') ? App.getRowsPerPage() : 25;
  const totalFiltered = filtered.length;

  // Stocker pour l'export
  window._exportState = { allData: data, filteredData: filtered, columns };
  const totalPages = (perPage === 'all') ? 1 : Math.max(1, Math.ceil(totalFiltered / perPage));
  let page = (typeof App !== 'undefined') ? App.getPage() : 1;
  if (page > totalPages) {
    page = totalPages;
    if (typeof App !== 'undefined') App.setPageSilently(page);
  }
  const startIdx = (perPage === 'all') ? 0 : (page - 1) * perPage;
  const pageData = (perPage === 'all') ? filtered : filtered.slice(startIdx, startIdx + perPage);
  const from = totalFiltered === 0 ? 0 : (startIdx + 1);
  const to   = totalFiltered === 0 ? 0 : (startIdx + pageData.length);

  let html = `<div class="card">`;

  // ── Barre de recherche ──────────────────────────────────────────────────────
  const _page = App.currentPage || '';
  const _hasAdvFilters = FiltresEngine.getConfig(_page);
  html += `
    <div class="search-bar" style="gap:8px">
      <div class="search-input-wrap" style="flex:1;max-width:480px">
        <span class="search-icon">🔍</span>
        <input class="search-input" type="text" placeholder="Rechercher dans tous les champs…"
          value="${escHtml(searchTerm)}" autocomplete="off" spellcheck="false" title="Filtre en direct — Échap pour effacer"
          oninput="App.handleSearch(this.value)"
          onkeydown="App.handleSearchKey(event)">
      </div>
      ${_hasAdvFilters ? FiltresEngine.renderButton(_page) : ''}
      ${App._sortKey ? `
        <button class="btn btn-ghost btn-sm" onclick="App._sortKey=null;App._sortDir='asc';App.renderCurrentPage()"
          style="white-space:nowrap;background:var(--blue-pale);color:var(--blue);border-color:var(--blue)">
          ↕ Trié par ${columns.find(col=>col.key===App._sortKey)?.label||App._sortKey} ${App._sortDir==='asc'?'↑':'↓'} &nbsp;✕
        </button>` : ''}

      <select class="form-control" style="font-size:13px;width:auto;padding:6px 10px" onchange="App.setRowsPerPage(this.value)" title="Lignes par page">
        <option value="10"  ${perPage===10?'selected':''}>10 lignes</option>
        <option value="25"  ${perPage===25?'selected':''}>25 lignes</option>
        <option value="50"  ${perPage===50?'selected':''}>50 lignes</option>
        <option value="100" ${perPage===100?'selected':''}>100 lignes</option>
        <option value="200" ${perPage===200?'selected':''}>200 lignes</option>
        <option value="all" ${perPage==='all'?'selected':''}>Tout</option>
      </select>
      ${App.currentUser?.Role !== 'Demandeur' ? `<div style="position:relative;display:inline-block" id="_exportWrap">
        <button class="btn btn-ghost btn-sm" onclick="_toggleExportMenu()" style="white-space:nowrap" title="Exporter">
          📥 Exporter
        </button>
        <div id="_exportMenu" style="display:none;position:absolute;right:0;top:100%;z-index:9999;min-width:220px;background:var(--card-bg,#fff);border:1px solid var(--gray-border);border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,.15);padding:6px 0">
          <div onclick="_exportData('csv','filtered')" style="padding:9px 14px;cursor:pointer;font-size:13px;transition:background .15s" onmouseover="this.style.background='var(--gray-bg)'" onmouseout="this.style.background=''">📄 CSV — données filtrées (${totalFiltered})</div>
          <div onclick="_exportData('csv','all')" style="padding:9px 14px;cursor:pointer;font-size:13px;transition:background .15s" onmouseover="this.style.background='var(--gray-bg)'" onmouseout="this.style.background=''">📄 CSV — toutes les données (${data.length})</div>
          <div style="border-top:1px solid var(--gray-border);margin:4px 0"></div>
          <div onclick="_exportData('pdf','filtered')" style="padding:9px 14px;cursor:pointer;font-size:13px;transition:background .15s" onmouseover="this.style.background='var(--gray-bg)'" onmouseout="this.style.background=''">📕 PDF — données filtrées (${totalFiltered})</div>
          <div onclick="_exportData('pdf','all')" style="padding:9px 14px;cursor:pointer;font-size:13px;transition:background .15s" onmouseover="this.style.background='var(--gray-bg)'" onmouseout="this.style.background=''">📕 PDF — toutes les données (${data.length})</div>
        </div>
      </div>` : ''}
      ${extraButtons || ''}
      <div style="flex:1"></div>
      ${addBtnFn && canEdit ? `<button class="btn btn-primary" onclick="${addBtnFn}(0)">+ Nouveau</button>` : ''}
    </div>`;

  // Panneau filtres avancés (FiltresEngine)
  if (_hasAdvFilters) {
    html += FiltresEngine.renderPanel(_page);
  }


  // Filtres chips (legacy — seulement si pas de FiltresEngine pour cette page)
  if (filters && !FiltresEngine.getConfig(_page)) {
    html += `<div class="chip-filters">`;
    filters.values.forEach(f => {
      html += `<div class="chip ${currentFilter===f?'active':''}" onclick="App.handleFilter('${f}')">${f}</div>`;
    });
    html += `</div>`;
  }

  // Tableau
  html += `<div class="table-wrap"><table><thead><tr>`;
  columns.forEach(col => {
    const isSorted = App._sortKey === col.key;
    const arrow = isSorted ? (App._sortDir === 'asc' ? ' ↑' : ' ↓') : '';
    const sortable = col.key && col.key !== '_assetDisplay';
    html += `<th ${sortable ? `onclick="App.handleSort('${col.key}')" style="cursor:pointer;user-select:none;white-space:nowrap" title="Trier"` : ''}>${col.label}${arrow ? `<span style="color:var(--blue)">${arrow}</span>` : ''}</th>`;
  });
  html += `<th>Actions</th></tr></thead><tbody>`;

  if (pageData.length === 0) {
    html += `<tr><td colspan="${columns.length+1}" class="no-results">Aucun résultat.</td></tr>`;
  } else {
    pageData.forEach(row => {
      html += `<tr>`;
      columns.forEach(c => {
        const v = row[c.key];
        let cell;
        if      (c.render)            cell = c.render(v, row);
        else if (c.badge)            cell = badge(v);
        else if (c.type==='money')   cell = v!=null ? fmt(v,'money') : '—';
        else if (c.type==='date')    cell = v ? fmt(v,'date') : '—';
        else                         cell = (v!==null&&v!==undefined&&v!=='') ? String(v) : '<span style="color:var(--gray-text)">—</span>';
        html += `<td>${cell}</td>`;
      });
      const editFn   = columns.find(c => c.editFn)?.editFn;
      const deleteFn = columns.find(c => c.deleteFn)?.deleteFn;
      html += `<td><div class="actions-col">`;
      if (canEdit   && editFn)   html += `<button class="icon-btn"        onclick="${editFn}(${row.Id})"   title="Modifier">✏️</button>`;
      if (canDelete && deleteFn) html += `<button class="icon-btn delete" onclick="${deleteFn}(${row.Id})" title="Supprimer">🗑️</button>`;
      html += `</div></td></tr>`;
    });
  }

  html += `</tbody></table></div>`;
  const total = data.length;
  const diff  = total - totalFiltered;
  const diffLabel = diff > 0 ? ` <span style="color:var(--gray-text);font-weight:400">(${diff} masqué${diff>1?'s':''} par les filtres)</span>` : '';
  const canPrev = page > 1;
  const canNext = page < totalPages;
  const prevStyle = canPrev ? '' : 'opacity:.45;pointer-events:none;';
  const nextStyle = canNext ? '' : 'opacity:.45;pointer-events:none;';
  html += `<div class="pagination">
    <span><span style="font-weight:600">${totalFiltered}</span> résultat${totalFiltered>1?'s':''} sur ${total}${diffLabel} — Affichage ${from}-${to}</span>
    ${totalPages>1 ? `
      <div style="display:flex;align-items:center;gap:8px">
        <button class="btn btn-ghost btn-sm" style="${prevStyle}" onclick="App.setPage(${page-1})">←</button>
        <span style="font-size:12px;color:var(--gray-text)">Page ${page} / ${totalPages}</span>
        <button class="btn btn-ghost btn-sm" style="${nextStyle}" onclick="App.setPage(${page+1})">→</button>
      </div>` : ''}
  </div>`;
  html += `</div>`;

  container.innerHTML = html;
  // Restaurer les valeurs des filtres avancés dans le DOM
  if (typeof App !== 'undefined') App.restoreFilters();
}

// Toggle panneau filtre avancé
function toggleFiltreAvance(panneau) {
  // Supporte aussi l'usage depuis énergie (panneau = 'stats'|'facturation')
  if (panneau && panneau !== 'avance') {
    const ids = { facturation: 'panneauFiltreFacturation', stats: 'panneauFiltreStats' };
    const cible = document.getElementById(ids[panneau]);
    if (!cible) return;
    const ouvert = cible.style.display !== 'none';
    Object.values(ids).forEach(id => { const el = document.getElementById(id); if (el) el.style.display = 'none'; });
    if (!ouvert) cible.style.display = 'block';
    return;
  }
  const p = document.getElementById('panneauFiltreAvance');
  const b = document.getElementById('btnFiltreAvance');
  if (!p) return;
  const open = p.style.display === 'none' || p.style.display === '';
  p.style.display = open ? 'block' : 'none';
  // Mémoriser l'état pour la restauration après re-render
  if (typeof App !== 'undefined') App._panneauFiltreOuvert = open;
  if (b) {
    b.style.background    = open ? 'var(--blue-pale)' : '';
    b.style.color         = open ? 'var(--blue)' : '';
    b.style.borderColor   = open ? 'var(--blue)' : '';
    b.style.fontWeight    = open ? '600' : '';
  }
}

// ── Select searchable (1 seule barre, filtre en direct) ────────────────────
// Remplace visuellement un <select> par un champ unique (input) avec dropdown filtré.
// Le <select> reste dans le DOM (caché) pour conserver la compatibilité (save, onchange, datasets…).
//
// Usage (dans un modal après openModal):
//   setTimeout(() => enhanceSelectTypeahead('f_demandeId'), 0);
//
// Options:
//   - placeholder: texte placeholder si aucune valeur
//   - allowEmpty:  si true, inclut l'option value="" dans les résultats
function enhanceSelectTypeahead(selectId, options = {}) {
  const sel = document.getElementById(selectId);
  if (!sel) return;

  // Évite double initialisation
  if (sel._combo && sel._combo.input) return;

  const opts = {
    placeholder: options.placeholder || 'Rechercher…',
    allowEmpty: options.allowEmpty !== false,
  };

  // Normalisation (insensible aux accents)
  const norm = (s) => {
    const str = String(s || '').toLowerCase();
    try { return str.normalize('NFD').replace(/\p{Diacritic}/gu, ''); }
    catch { return str.normalize('NFD').replace(/[\u0300-\u036f]/g, ''); }
  };

  // Construire une "source" (optgroups + options) depuis le select
  const buildSource = () => {
    const src = [];
    const children = Array.from(sel.children);
    children.forEach(ch => {
      if (ch.tagName === 'OPTGROUP') {
        const label = ch.getAttribute('label') || '';
        const group = {
          type: 'group',
          label,
          items: Array.from(ch.querySelectorAll('option')).map(o => ({
            type: 'option',
            value: o.value,
            text: o.textContent || '',
            textNorm: norm(o.textContent || ''),
            disabled: o.disabled,
          })),
        };
        src.push(group);
      } else if (ch.tagName === 'OPTION') {
        src.push({
          type: 'option',
          value: ch.value,
          text: ch.textContent || '',
          textNorm: norm(ch.textContent || ''),
          disabled: ch.disabled,
        });
      }
    });
    return src;
  };

  let source = buildSource();

  // Wrapper
  const wrap = document.createElement('div');
  wrap.className = 'combo-wrap';

  // Input (1 seule barre)
  const input = document.createElement('input');
  input.type = 'text';
  input.className = 'form-control';
  input.autocomplete = 'off';
  input.spellcheck = false;
  input.placeholder = opts.placeholder;

  // Arrow
  const arrow = document.createElement('span');
  arrow.className = 'combo-arrow';
  arrow.textContent = '▾';

  // Dropdown
  const dd = document.createElement('div');
  dd.className = 'combo-dropdown';

  // Mettre le wrapper à la place du select, et cacher le select
  const parent = sel.parentNode;
  parent.insertBefore(wrap, sel);
  wrap.appendChild(input);
  wrap.appendChild(arrow);
  wrap.appendChild(dd);
  wrap.appendChild(sel);
  sel.style.display = 'none';

  // État navigation clavier
  let open = false;
  let activeEl = null;

  const syncInputFromSelect = () => {
    const o = sel.selectedOptions && sel.selectedOptions[0];
    const txt = o ? (o.textContent || '') : '';
    if (!input.matches(':focus')) input.value = txt;
    if (input.matches(':focus') && !input.value) input.value = txt;
  };

  const close = () => {
    dd.style.display = 'none';
    open = false;
    activeEl = null;
  };

  const openDd = () => {
    dd.style.display = 'block';
    open = true;
  };

  const setActive = (el) => {
    if (activeEl) activeEl.classList.remove('active');
    activeEl = el;
    if (activeEl) {
      activeEl.classList.add('active');
      activeEl.scrollIntoView({ block: 'nearest' });
    }
  };

  const selectValue = (val) => {
    sel.value = val;
    sel.dispatchEvent(new Event('change', { bubbles: true }));
    syncInputFromSelect();
    close();
  };

  const renderList = () => {
    const q = norm(input.value.trim());
    dd.innerHTML = '';
    const frag = document.createDocumentFragment();

    const addOption = (item) => {
      if (!opts.allowEmpty && item.value === '') return;
      if (q && !item.textNorm.includes(q)) return;

      const div = document.createElement('div');
      div.className = 'combo-item';
      div.textContent = item.text;
      div.dataset.value = item.value;
      if (item.disabled) div.style.opacity = '0.55';

      if (sel.value === item.value) div.classList.add('active');

      div.addEventListener('mousedown', (e) => {
        e.preventDefault();
        if (!item.disabled) selectValue(item.value);
      });

      frag.appendChild(div);
    };

    source.forEach(node => {
      if (node.type === 'group') {
        const itemsOk = node.items.filter(item => (!q || item.textNorm.includes(q)) && (opts.allowEmpty || item.value !== ''));
        if (!itemsOk.length) return;

        const h = document.createElement('div');
        h.className = 'combo-group';
        h.textContent = node.label;
        frag.appendChild(h);

        itemsOk.forEach(item => addOption(item));
      } else if (node.type === 'option') {
        addOption(node);
      }
    });

    if (!frag.childNodes.length) {
      const empty = document.createElement('div');
      empty.className = 'combo-empty';
      empty.textContent = 'Aucun résultat';
      frag.appendChild(empty);
    }

    dd.appendChild(frag);

    const first = dd.querySelector('.combo-item');
    if (first) setActive(first);
  };

  input.addEventListener('focus', () => {
    openDd();
    renderList();
  });

  input.addEventListener('input', () => {
    if (!open) openDd();
    renderList();
  });

  input.addEventListener('keydown', (e) => {
    if (!open && (e.key === 'ArrowDown' || e.key === 'Enter')) {
      openDd(); renderList();
    }
    if (!open) return;

    const items = Array.from(dd.querySelectorAll('.combo-item'));
    const idx = items.indexOf(activeEl);

    if (e.key === 'ArrowDown') {
      e.preventDefault();
      const next = items[Math.min(items.length - 1, Math.max(0, idx + 1))];
      if (next) setActive(next);
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      const prev = items[Math.max(0, idx - 1)];
      if (prev) setActive(prev);
    } else if (e.key === 'Enter') {
      e.preventDefault();
      const v = activeEl?.dataset?.value;
      if (v !== undefined) selectValue(v);
    } else if (e.key === 'Escape') {
      e.preventDefault();
      syncInputFromSelect();
      close();
      input.blur();
    }
  });

  // Gestion globale des clics "hors composant" (1 seul listener pour éviter les fuites)
  window.__comboRegistry = window.__comboRegistry || new Set();
  const comboObj = { wrap, close, sync: syncInputFromSelect };
  window.__comboRegistry.add(comboObj);

  if (!window.__comboDocListenerInstalled) {
    window.__comboDocListenerInstalled = true;
    document.addEventListener('mousedown', (e) => {
      const reg = window.__comboRegistry;
      if (!reg) return;
      for (const c of Array.from(reg)) {
        if (!c.wrap || !c.wrap.isConnected) { reg.delete(c); continue; }
        if (!c.wrap.contains(e.target)) { c.sync?.(); c.close?.(); }
      }
    });
  }

  sel.addEventListener('change', () => {
    source = buildSource();
    syncInputFromSelect();
  });

  sel._combo = { wrap, input, dropdown: dd };

  syncInputFromSelect();
}

window.enhanceSelectTypeahead = enhanceSelectTypeahead;

// ═══════════════════════════════════════════════════════════════════════════════
//  EXPORT — CSV & PDF
// ═══════════════════════════════════════════════════════════════════════════════

function _toggleExportMenu() {
  const menu = document.getElementById('_exportMenu');
  if (menu) menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
}
// Fermer le menu export en cliquant ailleurs
document.addEventListener('click', function(e) {
  const menu = document.getElementById('_exportMenu');
  if (menu && menu.style.display !== 'none' && !e.target.closest('#_exportWrap')) {
    menu.style.display = 'none';
  }
});

function _exportData(format, scope) {
  const menu = document.getElementById('_exportMenu');
  if (menu) menu.style.display = 'none';

  const state = window._exportState;
  if (!state) { toast('Aucune donnée à exporter.', 'error'); return; }

  const data = scope === 'all' ? state.allData : state.filteredData;
  const cols = state.columns;
  const page = App.currentPage || 'export';
  const title = PAGES[page]?.title || page;

  if (!data.length) { toast('Aucune donnée à exporter.', 'error'); return; }

  // Ouvrir le modal d'options
  _openExportModal(format, data, cols, title, page);
}

function _openExportModal(format, data, cols, title, page) {
  const colChecks = cols.map((c, i) => {
    const label = c.label || c.key;
    return `<label style="display:flex;align-items:center;gap:6px;font-size:12px;padding:3px 0;cursor:pointer">
      <input type="checkbox" class="exp-col-check" data-idx="${i}" checked> ${label}
    </label>`;
  }).join('');

  openModal(`📥 Exporter — ${data.length} ligne(s)`, `
    <div style="margin-bottom:14px;font-size:13px;color:var(--gray-text)">
      Depuis <strong>${title}</strong>
    </div>

    <div style="margin-bottom:14px">
      <label style="font-size:12px;font-weight:600;margin-bottom:6px;display:block">Format</label>
      <div style="display:flex;gap:8px">
        <label style="display:flex;align-items:center;gap:4px;font-size:13px;cursor:pointer;padding:6px 14px;border:2px solid var(--gray-border);border-radius:8px;transition:all .15s" id="exp_fmt_csv">
          <input type="radio" name="exp_format" value="csv" ${format==='csv'?'checked':''} onchange="_expTogglePdfOpts()"> 📄 CSV (Excel)
        </label>
        <label style="display:flex;align-items:center;gap:4px;font-size:13px;cursor:pointer;padding:6px 14px;border:2px solid var(--gray-border);border-radius:8px;transition:all .15s" id="exp_fmt_pdf">
          <input type="radio" name="exp_format" value="pdf" ${format==='pdf'?'checked':''} onchange="_expTogglePdfOpts()"> 📕 PDF
        </label>
      </div>
    </div>

    <div style="margin-bottom:14px">
      <label style="font-size:12px;font-weight:600;margin-bottom:6px;display:block">Titre du document</label>
      <input class="form-control" id="exp_title" value="${title} — ${new Date().toLocaleDateString('fr-FR')}">
    </div>

    <div style="margin-bottom:14px">
      <label style="font-size:12px;font-weight:600;margin-bottom:6px;display:block">Colonnes à inclure</label>
      <div style="display:flex;gap:4px;margin-bottom:8px">
        <button class="btn btn-ghost btn-sm" onclick="document.querySelectorAll('.exp-col-check').forEach(c=>c.checked=true)" style="font-size:11px">✅ Tout</button>
        <button class="btn btn-ghost btn-sm" onclick="document.querySelectorAll('.exp-col-check').forEach(c=>c.checked=false)" style="font-size:11px">❌ Rien</button>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:2px 16px;max-height:200px;overflow-y:auto;padding:8px 12px;background:var(--gray-bg);border-radius:8px;border:1px solid var(--gray-border)">
        ${colChecks}
      </div>
    </div>

    <div id="exp_pdf_opts" style="${format==='pdf'?'':'display:none'}">
      <div style="display:flex;gap:16px;margin-bottom:14px">
        <div>
          <label style="font-size:12px;font-weight:600;margin-bottom:6px;display:block">Orientation</label>
          <div style="display:flex;gap:8px">
            <label style="display:flex;align-items:center;gap:4px;font-size:12px;cursor:pointer">
              <input type="radio" name="exp_orient" value="landscape" checked> Paysage
            </label>
            <label style="display:flex;align-items:center;gap:4px;font-size:12px;cursor:pointer">
              <input type="radio" name="exp_orient" value="portrait"> Portrait
            </label>
          </div>
        </div>
        <div>
          <label style="font-size:12px;font-weight:600;margin-bottom:6px;display:block">Taille de police</label>
          <select class="form-control" id="exp_fontsize" style="width:auto">
            <option value="7">Très petit (7pt)</option>
            <option value="8" selected>Petit (8pt)</option>
            <option value="9">Normal (9pt)</option>
            <option value="10">Grand (10pt)</option>
          </select>
        </div>
      </div>
    </div>
  `, () => {
    const checks = document.querySelectorAll('.exp-col-check');
    const selectedCols = [];
    checks.forEach(c => { if (c.checked) selectedCols.push(parseInt(c.dataset.idx)); });
    if (!selectedCols.length) { toast('Sélectionnez au moins une colonne.', 'error'); return; }

    const expTitle = gv('exp_title') || title;
    const expCols = selectedCols.map(i => cols[i]);
    const chosenFormat = document.querySelector('input[name="exp_format"]:checked')?.value || 'csv';

    if (chosenFormat === 'csv') {
      _doExportCSV(data, expCols, expTitle);
    } else {
      const orient = document.querySelector('input[name="exp_orient"]:checked')?.value || 'landscape';
      const fontSize = parseInt(gv('exp_fontsize') || '8');
      _doExportPDF(data, expCols, expTitle, orient, fontSize);
    }
    closeModal();
  }, 'Exporter');
}

function _expTogglePdfOpts() {
  const isPdf = document.querySelector('input[name="exp_format"]:checked')?.value === 'pdf';
  const opts = document.getElementById('exp_pdf_opts');
  if (opts) opts.style.display = isPdf ? '' : 'none';
}

// ── Export CSV ───────────────────────────────────────────────────────────────
function _doExportCSV(data, cols, title) {
  const sep = ';'; // Point-virgule pour compatibilité Excel FR
  const BOM = '\uFEFF'; // UTF-8 BOM pour Excel

  let csv = BOM;
  // En-têtes
  csv += cols.map(c => _csvEscape(c.label || c.key, sep)).join(sep) + '\n';
  // Lignes
  data.forEach(row => {
    csv += cols.map(c => {
      let val = row[c.key] ?? '';
      if (typeof val === 'object') val = JSON.stringify(val);
      return _csvEscape(String(val), sep);
    }).join(sep) + '\n';
  });

  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
  _downloadBlob(blob, _sanitizeFilename(title) + '.csv');
  toast(`${data.length} ligne(s) exportées en CSV.`, 'success');
}

function _csvEscape(val, sep) {
  val = String(val).replace(/\r?\n/g, ' ');
  if (val.includes(sep) || val.includes('"') || val.includes('\n')) {
    return '"' + val.replace(/"/g, '""') + '"';
  }
  return val;
}

// ── Export PDF ───────────────────────────────────────────────────────────────
function _doExportPDF(data, cols, title, orient, fontSize) {
  // Générer un tableau HTML puis ouvrir dans une fenêtre d'impression
  const isLandscape = orient === 'landscape';

  let html = `<!DOCTYPE html><html><head><meta charset="utf-8">
  <title>${_escHtml(title)}</title>
  <style>
    @page { size: ${isLandscape ? 'A4 landscape' : 'A4 portrait'}; margin: 12mm; }
    @media print { body { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: Arial, Helvetica, sans-serif; font-size: ${fontSize}pt; color: #222; }
    h1 { font-size: ${fontSize + 4}pt; margin-bottom: 4px; }
    .meta { font-size: ${fontSize - 1}pt; color: #666; margin-bottom: 10px; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #2b7be6; color: #fff; font-weight: 700; text-align: left; padding: 5px 6px; font-size: ${fontSize}pt; }
    td { padding: 4px 6px; border-bottom: 1px solid #ddd; font-size: ${fontSize}pt; word-break: break-word; }
    tr:nth-child(even) { background: #f8f9fa; }
    .footer { margin-top: 10px; font-size: ${fontSize - 2}pt; color: #999; text-align: center; }
  </style></head><body>
  <h1>${_escHtml(title)}</h1>
  <div class="meta">Exporté le ${new Date().toLocaleString('fr-FR')} — ${data.length} ligne(s)</div>
  <table><thead><tr>`;

  cols.forEach(c => { html += `<th>${_escHtml(c.label || c.key)}</th>`; });
  html += '</tr></thead><tbody>';

  data.forEach(row => {
    html += '<tr>';
    cols.forEach(c => {
      let val = row[c.key] ?? '';
      if (typeof val === 'object') val = JSON.stringify(val);
      html += `<td>${_escHtml(String(val))}</td>`;
    });
    html += '</tr>';
  });

  html += '</tbody></table>';
  html += `<div class="footer">Larka — ${_escHtml(title)}</div>`;
  html += `<script>setTimeout(()=>{window.print();},400);</script>`;
  html += '</body></html>';

  const w = window.open('', '_blank');
  if (w) {
    w.document.write(html);
    w.document.close();
  } else {
    toast('Le navigateur a bloqué la fenêtre. Autorisez les popups.', 'error');
  }
}

// Échappe texte ET attributs. Les guillemets sont indispensables : une chaîne
// injectée dans un attribut (ex: download="...") pourrait sinon s'en évader.
// Définition unique — assistant.js utilise celle-ci (ne pas la redéclarer
// ailleurs : en portée globale, la dernière déclaration chargée écrase l'autre).
function _escHtml(s) {
  return String(s ?? '')
    .replace(/&/g,'&amp;')
    .replace(/</g,'&lt;')
    .replace(/>/g,'&gt;')
    .replace(/"/g,'&quot;')
    .replace(/'/g,'&#39;');
}

// Échappement HTML complet (texte + attributs), avec guillemets simples ET
// doubles. Défini ici, dans un fichier TOUJOURS chargé (avant tout js/pages/*),
// pour les scripts qui appellent _esc() sans le définir eux-mêmes
// (ex: biens-import.js, superadmin-auth.js).
//
// ⚠️ NE PAS redéclarer « function _esc » ailleurs. Ces fichiers étant chargés en
// portée globale, une déclaration de fonction au niveau racine d'un script chargé
// ensuite ÉCRASE ce repli — le garde `if (typeof …)` ci-dessous ne protège que
// contre une affectation, pas contre une déclaration. C'était le cas de
// ui-prefs.js (n'échappait pas les guillemets) et de plans.js (`_esc(0)` → ''),
// d'où un échappement qui variait selon les pages visitées. Les versions propres
// à une page ont été renommées (_escCfg, _escDem, _escPlan, _escSa, _escPrefs).
if (typeof window._esc !== 'function') {
  window._esc = function _esc(s) {
    return String(s ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  };
}

// ── Utilitaires export ──────────────────────────────────────────────────────
function _downloadBlob(blob, filename) {
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  setTimeout(() => { document.body.removeChild(a); URL.revokeObjectURL(a.href); }, 100);
}

function _sanitizeFilename(name) {
  return String(name).replace(/[/\\:*?"<>|]/g, '_').replace(/\s+/g, '_').slice(0, 100);
}

// ═══════════════════════════════════════════════════════════════════════════════
//  EXPORT GLOBAL — fonctionne sur toute page avec un tableau
// ═══════════════════════════════════════════════════════════════════════════════
// Injecte un bouton export sur les pages qui n'utilisent pas renderTable
function _injectPageExport() {
  // Si renderTable a déjà mis _exportState et qu'un bouton existe, ne rien faire
  if (document.getElementById('_exportWrap')) return;

  // Chercher un tableau dans mainContent
  const table = document.querySelector('#mainContent table');
  // Ou un canvas (graphique énergie, stats...)
  const canvas = document.querySelector('#mainContent canvas');

  if (!table && !canvas) return;

  // Chercher un endroit pour insérer le bouton : search-bar, card-header, ou début du contenu
  let target = document.querySelector('#mainContent .search-bar')
            || document.querySelector('#mainContent .card-header')
            || document.querySelector('#mainContent .card');
  if (!target) return;

  // Vérifier qu'on n'a pas déjà injecté
  if (target.querySelector('.injected-export-btn')) return;

  const btn = document.createElement('button');
  btn.className = 'btn btn-ghost btn-sm injected-export-btn';
  btn.style.cssText = 'white-space:nowrap;margin-left:auto';
  btn.innerHTML = '📥 Exporter';
  btn.onclick = function() {
    _captureAndExport();
  };

  if (target.classList.contains('search-bar')) {
    // Insérer avant le dernier enfant (bouton Nouveau) ou à la fin
    const nouveau = target.querySelector('.btn-primary');
    if (nouveau) target.insertBefore(btn, nouveau);
    else target.appendChild(btn);
  } else {
    target.appendChild(btn);
  }
}

// Capture le tableau HTML visible + les graphiques et propose l'export
function _captureAndExport() {
  const table = document.querySelector('#mainContent table');
  const canvas = document.querySelector('#mainContent canvas');
  const title = PAGES[App.currentPage]?.title || App.currentPage || 'Export';

  if (table) {
    // Extraire colonnes et données du tableau HTML
    const ths = table.querySelectorAll('thead th');
    const cols = [];
    ths.forEach((th, i) => {
      const label = (th.textContent || '').trim();
      if (label && label !== '⚡' && label !== '✏️') {
        cols.push({ key: 'col_' + i, label, idx: i });
      }
    });

    const rows = table.querySelectorAll('tbody tr');
    const data = [];
    rows.forEach(tr => {
      const tds = tr.querySelectorAll('td');
      const row = {};
      cols.forEach(c => {
        const td = tds[c.idx];
        row[c.key] = td ? (td.textContent || '').trim().replace(/\s+/g, ' ') : '';
      });
      data.push(row);
    });

    if (!data.length) { toast('Aucune donnée à exporter.', 'error'); return; }
    window._exportState = { allData: data, filteredData: data, columns: cols };
    _openExportModal('csv', data, cols, title, App.currentPage);
  } else if (canvas) {
    // Exporter le graphique en image
    _exportCanvas(canvas, title);
  } else {
    toast('Aucun contenu exportable trouvé.', 'error');
  }
}

// Export d'un graphique canvas en PNG
function _exportCanvas(canvas, title) {
  try {
    const link = document.createElement('a');
    link.download = _sanitizeFilename(title) + '.png';
    link.href = canvas.toDataURL('image/png');
    document.body.appendChild(link);
    link.click();
    setTimeout(() => document.body.removeChild(link), 100);
    toast('Graphique exporté en PNG.', 'success');
  } catch(e) {
    toast('Impossible d\'exporter le graphique : ' + e.message, 'error');
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Helpers de sécurité XSS — injection sûre dans le DOM
// ─────────────────────────────────────────────────────────────────────────────
//
// ⚠️ Beaucoup de pages du projet construisent du HTML par concaténation et
// l'injectent via `innerHTML` — y compris des messages d'erreur du backend
// (`...${e.message}...`). Aujourd'hui aucun message backend n'est tainté,
// mais une seule régression peut introduire un XSS stocké.
//
// Préférer ces helpers chaque fois que possible :

/**
 * Échappe une chaîne pour insertion dans du HTML (texte ou attribut).
 * Couvre `& < > " '`. À utiliser avant de concaténer une donnée non-sûre
 * dans une template-string `...${value}...`.
 */
function escHtml(s) {
  if (s === null || s === undefined) return '';
  return String(s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

/**
 * Recherche « intelligente » PARTAGÉE par toute l'application :
 *  - insensible à la casse,
 *  - insensible aux accents (étage ↔ etage, Sécurité ↔ securite),
 *  - multi-mots : tous les termes doivent être présents, dans n'importe quel
 *    ordre ("salle reunion" trouve "Salle de réunion").
 * Utilisée à la fois par la barre de recherche globale (renderTable) et par la
 * recherche avancée de chaque onglet (FiltresEngine.matchRow), donc l'amélioration
 * s'applique uniformément à tous les modules.
 */
function _normalizeSearch(s) {
  return String(s ?? '')
    .toLowerCase()
    .normalize('NFD').replace(/[\u0300-\u036f]/g, '') // supprime les diacritiques
    .trim();
}
function smartTextMatch(haystack, needle) {
  const n = _normalizeSearch(needle);
  if (!n) return true;
  const h = _normalizeSearch(haystack);
  return n.split(/\s+/).every(tok => h.includes(tok));
}
window.smartTextMatch = smartTextMatch;
window._normalizeSearch = _normalizeSearch;


/**
 * Affiche un message d'erreur sûr dans un élément. Préfère textContent à
 * innerHTML pour neutraliser les éventuels balises dans le message.
 *   setError(errEl, 'Erreur : ' + e.message);
 */
function setError(el, msg) {
  if (!el) return;
  el.textContent = msg == null ? '' : String(msg);
  if (el.style) el.style.display = '';
}

// ── Accessibilité : cliquables non natifs ────────────────────────────────────
// Les <div>/<span> porteurs d'un onclick ne sont ni atteignables au clavier (Tab)
// ni annoncés comme des boutons par un lecteur d'écran. Plutôt que d'annoter à la
// main la soixantaine de gabarits concernés — répartis dans 11 fichiers, générés
// dynamiquement, et sans couvrir les futurs — on les promeut ici automatiquement.
//
// Un élément déjà porteur d'un role= n'est jamais touché ; data-noa11y permet de
// s'exclure explicitement.
const _A11Y_SEL = 'div[onclick]:not([role]):not([data-noa11y]),'
                + 'span[onclick]:not([role]):not([data-noa11y])';

function _a11yPromote(el) {
  el.setAttribute('role', 'button');
  if (!el.hasAttribute('tabindex')) el.setAttribute('tabindex', '0');
}

function _a11yScan(root) {
  if (!root || root.nodeType !== 1) return;           // ignore texte/commentaires
  if (root.matches && root.matches(_A11Y_SEL)) _a11yPromote(root);
  if (root.querySelectorAll) root.querySelectorAll(_A11Y_SEL).forEach(_a11yPromote);
}

// Activation clavier par délégation : UN seul écouteur pour toute l'application.
document.addEventListener('keydown', function (e) {
  if (e.key !== 'Enter' && e.key !== ' ') return;
  const t = e.target;
  if (!t || !t.closest) return;
  // Ne jamais interférer avec la saisie ni avec les éléments déjà activables.
  if (t.matches('input, textarea, select, button, a[href], [contenteditable="true"]')) return;
  const el = t.closest('[role="button"]');
  if (!el) return;
  e.preventDefault();      // Espace : empêche le défilement de la page
  el.click();
});

// Balayage initial + suivi des rendus dynamiques (innerHTML des pages).
// On n'observe que childList : _a11yPromote ne modifie que des attributs,
// il ne peut donc pas se redéclencher lui-même.
function _a11yObserve() {
  if (!document.body) return;
  _a11yScan(document.body);
  new MutationObserver(function (muts) {
    for (const m of muts) for (const n of m.addedNodes) _a11yScan(n);
  }).observe(document.body, { childList: true, subtree: true });
}
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', _a11yObserve);
} else {
  _a11yObserve();
}

// ═══════════════════════════════════════════════════════════════════════════════
//  BARRE D'ACTIONS DE PAGE
// ═══════════════════════════════════════════════════════════════════════════════
//
//  #pageActionBar est le dernier enfant flex de .main. Une page qui a des
//  actions persistantes (Annuler / Enregistrer) les y dépose au lieu de placer
//  une barre « position:sticky » dans le flux : la barre reste alors visible
//  quoi qu'il arrive, sans dépendre du conteneur de défilement ni de la
//  position de la sidebar.
//
//  navigate() la vide à chaque changement de page ; une page qui change de
//  sous-onglet doit appeler clearPageActionBar() elle-même.

function setPageActionBar(html) {
  const bar = document.getElementById('pageActionBar');
  if (!bar) return;
  const contenu = (html || '').trim();
  bar.innerHTML = contenu;
  bar.hidden = (contenu === '');
}

function clearPageActionBar() {
  const bar = document.getElementById('pageActionBar');
  if (!bar) return;
  bar.innerHTML = '';
  bar.hidden = true;
}

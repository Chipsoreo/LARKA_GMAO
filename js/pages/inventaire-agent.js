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
 * Larka — Page : Inventaire Agent (Demandeur)
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Système déclaratif d'inventaire pour les agents (rôle Demandeur).
 *
 * L'agent peut :
 *   1. Voir les biens qui lui sont affectés
 *   2. Déclarer la présence d'un bien (saisie manuelle ou scan)
 *   3. Signaler le départ d'un bien
 *   4. Suivre ses déclarations (en attente, validées, refusées)
 *
 * Toutes les déclarations passent par une validation Admin/Gestionnaire.
 *
 * POINT D'ENTRÉE : renderInventaireAgent()
 * ═══════════════════════════════════════════════════════════════════════════════
 */

function _escIA(s) { return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;'); }

// ── État local ──────────────────────────────────────────────────────────────
const IA_State = {
  biens: [],
  declarations: [],
  tab: 'biens',
  searchResult: null,
  scanning: false,
};

// ── Point d'entrée ──────────────────────────────────────────────────────────
async function renderInventaireAgent() {
  document.getElementById('topbarActions').innerHTML = '';
  const c = document.getElementById('mainContent');
  showLoading(c);

  try {
    const data = await apiRequest('inventaire_agent');
    IA_State.biens = data.biens || [];
    IA_State.declarations = data.declarations || [];
    IA_State.searchResult = null;
    _renderIA(c);
  } catch(e) { c.innerHTML = errorHtml(e.message); }
}

function _renderIA(c) {
  const tab = IA_State.tab;
  const nbEnAttente = IA_State.declarations.filter(d => d.Statut === 'en_attente').length;

  c.innerHTML = `
    <div style="max-width:900px;margin:0 auto">
      <div style="display:flex;gap:4px;margin-bottom:20px;border-bottom:2px solid var(--gray-border);padding-bottom:0;overflow-x:auto">
        <button class="ia-tab ${tab==='biens'?'active':''}" onclick="IA_State.tab='biens';_renderIA(document.getElementById('mainContent'))">
          📦 Mes biens <span class="ia-badge-tab">${IA_State.biens.length}</span>
        </button>
        <button class="ia-tab ${tab==='declarer'?'active':''}" onclick="IA_State.tab='declarer';IA_State.searchResult=null;_renderIA(document.getElementById('mainContent'))">
          ➕ Déclarer
        </button>
        <button class="ia-tab ${tab==='historique'?'active':''}" onclick="IA_State.tab='historique';_renderIA(document.getElementById('mainContent'))">
          📋 Déclarations ${nbEnAttente ? '<span class="ia-badge-tab orange">'+nbEnAttente+'</span>' : ''}
        </button>
      </div>
      <div id="ia_content"></div>
    </div>
    <style>
      .ia-tab{padding:10px 18px;border:none;background:none;cursor:pointer;font-size:13px;font-weight:600;color:var(--gray-text);border-bottom:3px solid transparent;transition:all .2s;display:flex;align-items:center;gap:6px;white-space:nowrap}
      .ia-tab:hover{color:var(--blue)}
      .ia-tab.active{color:var(--blue);border-bottom-color:var(--blue)}
      .ia-badge-tab{background:var(--gray-border);color:var(--text);border-radius:10px;padding:1px 8px;font-size:11px;font-weight:700}
      .ia-badge-tab.orange{background:#f59e0b;color:#fff}
      .ia-card{background:var(--card-bg,#fff);border:1px solid var(--gray-border);border-radius:10px;padding:16px;margin-bottom:12px;transition:border-color .2s}
      .ia-card:hover{border-color:var(--blue)}
      .ia-grid-info{display:grid;grid-template-columns:1fr 1fr;gap:4px 16px;font-size:12px;color:var(--gray-text)}
      .ia-grid-info strong{color:var(--text)}
      .ia-status{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:700}
      .ia-status.en_attente{background:#fef3c7;color:#92400e}
      .ia-status.valide{background:#d1fae5;color:#065f46}
      .ia-status.refuse{background:#fee2e2;color:#991b1b}
      .ia-empty{text-align:center;padding:40px 20px;color:var(--gray-text)}
      .ia-empty-icon{font-size:40px;margin-bottom:12px}
      .ia-search-box{display:flex;gap:8px;margin-bottom:16px}
      .ia-search-box input{flex:1;padding:10px 14px;border:1px solid var(--gray-border);border-radius:8px;font-size:14px;background:var(--card-bg,#fff);color:var(--text)}
      .ia-btn-primary{padding:8px 18px;border:none;border-radius:8px;font-weight:600;cursor:pointer;font-size:13px;background:var(--blue);color:#fff}
      .ia-btn-primary:hover{opacity:.9}
      .ia-btn-secondary{padding:8px 16px;border:1px solid var(--gray-border);border-radius:8px;font-weight:600;cursor:pointer;font-size:13px;background:var(--gray-bg);color:var(--text)}
      .ia-btn-scan{padding:8px 18px;border:none;border-radius:8px;font-weight:600;cursor:pointer;font-size:13px;background:#8b5cf6;color:#fff}
      .ia-btn-scan:hover{opacity:.9}
      .ia-btn-danger{border:none;cursor:pointer;background:#ef4444;color:#fff}
      .ia-bien-result{border:2px solid var(--blue);border-radius:10px;padding:16px;margin-bottom:16px;background:var(--card-bg,#fff)}
      .ia-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
      @media(max-width:600px){.ia-form-grid{grid-template-columns:1fr}.ia-grid-info{grid-template-columns:1fr}.ia-search-box{flex-direction:column}}
    </style>`;

  const content = document.getElementById('ia_content');
  if (tab === 'biens')       _renderTabBiens(content);
  else if (tab === 'declarer') _renderTabDeclarer(content);
  else if (tab === 'historique') _renderTabHistorique(content);
}

// ═════════════════════════════════════════════════════════════════════════════
//  ONGLET 1 : Mes biens affectés
// ═════════════════════════════════════════════════════════════════════════════
function _renderTabBiens(el) {
  if (!IA_State.biens.length) {
    el.innerHTML = `<div class="ia-empty">
      <div class="ia-empty-icon">📦</div>
      <div style="font-size:15px;font-weight:600;margin-bottom:6px">Aucun bien ne vous est affecté</div>
      <div style="font-size:13px">Les biens vous seront attribués par votre gestionnaire.<br>
      Vous pouvez aussi <a href="#" onclick="IA_State.tab='declarer';_renderIA(document.getElementById('mainContent'));return false" style="color:var(--blue);font-weight:600">déclarer un bien présent dans votre bureau</a>.</div>
    </div>`;
    return;
  }

  let html = `<div style="font-size:13px;color:var(--gray-text);margin-bottom:12px">
    ${IA_State.biens.length} bien(s) vous sont actuellement affecté(s)
  </div>`;

  for (const b of IA_State.biens) {
    html += `<div class="ia-card">
      <div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:10px;flex-wrap:wrap;gap:6px">
        <div>
          <span style="font-weight:700;font-size:14px">${_escIA(b.Numero)}</span>
          <span style="font-size:12px;color:var(--gray-text);margin-left:8px">${_escIA(b.Famille || '')} ${b.SousFamille ? '› '+_escIA(b.SousFamille) : ''}</span>
        </div>
        <button class="ia-btn-danger" style="padding:5px 12px;font-size:11px;border-radius:6px"
          onclick="_iaSignalerDepart('${_escIA(b.Numero)}')">
          📤 Mouvement / départ
        </button>
      </div>
      ${b.InfoProduit ? '<div style="font-size:12px;margin-bottom:8px;color:var(--text)">'+_escIA(b.InfoProduit)+'</div>' : ''}
      <div class="ia-grid-info">
        <div><strong>Bâtiment :</strong> ${_escIA(b.Batiment || '—')}</div>
        <div><strong>Étage :</strong> ${_escIA(b.Etage || '—')}</div>
        <div><strong>N° Bureau :</strong> ${_escIA(b.NumeroBureau || '—')}</div>
        <div><strong>N° Série :</strong> ${_escIA(b.NumeroSerie || '—')}</div>
      </div>
    </div>`;
  }

  el.innerHTML = html;
}

// ═════════════════════════════════════════════════════════════════════════════
//  ONGLET 2 : Déclarer un bien (saisie + scan)
// ═════════════════════════════════════════════════════════════════════════════
function _renderTabDeclarer(el) {
  el.innerHTML = `
    <div style="margin-bottom:20px">
      <div style="font-size:14px;font-weight:700;margin-bottom:4px">🔍 Identifier le bien</div>
      <div style="font-size:12px;color:var(--gray-text);margin-bottom:12px">
        Saisissez le numéro du bien (inscrit sur l'étiquette) ou scannez son code-barres / QR code.
      </div>
      <div class="ia-search-box">
        <input type="text" id="ia_numero_input" placeholder="Numéro du bien (ex: MOB-001)"
          onkeydown="if(event.key==='Enter')_iaRechercher()">
        <button class="ia-btn-primary" onclick="_iaRechercher()">🔍 Rechercher</button>
        <button class="ia-btn-scan" onclick="_iaOuvrirScan()">📷 Scanner</button>
      </div>
      <div id="ia_scan_zone"></div>
    </div>
    <div id="ia_search_result"></div>`;

  if (IA_State.searchResult) {
    _renderSearchResult(document.getElementById('ia_search_result'), IA_State.searchResult);
  }
}

async function _iaRechercher() {
  const input = document.getElementById('ia_numero_input');
  const numero = (input?.value || '').trim();
  if (!numero) { toast('Veuillez saisir un numéro de bien.', 'error'); return; }

  const zone = document.getElementById('ia_search_result');
  zone.innerHTML = '<div style="text-align:center;padding:20px;color:var(--gray-text)">⏳ Recherche en cours…</div>';

  try {
    const bien = await apiRequest('inventaire_agent_search&numero=' + encodeURIComponent(numero));
    IA_State.searchResult = bien;
    _renderSearchResult(zone, bien);
  } catch(e) {
    IA_State.searchResult = null;
    zone.innerHTML = `<div class="ia-card" style="border-color:#ef4444;text-align:center">
      <div style="font-size:28px;margin-bottom:8px">❌</div>
      <div style="font-weight:600;color:#ef4444;margin-bottom:4px">${_escIA(e.message)}</div>
      <div style="font-size:12px;color:var(--gray-text)">Vérifiez le numéro et réessayez.</div>
    </div>`;
  }
}

function _renderSearchResult(zone, bien) {
  zone.innerHTML = `
    <div class="ia-bien-result">
      <div style="font-size:13px;font-weight:700;color:var(--blue);margin-bottom:10px">✅ Bien trouvé</div>
      <div class="ia-grid-info" style="margin-bottom:16px">
        <div><strong>N° Bien :</strong> ${_escIA(bien.Numero)}</div>
        <div><strong>Famille :</strong> ${_escIA(bien.Famille || '—')}</div>
        <div><strong>Sous-famille :</strong> ${_escIA(bien.SousFamille || '—')}</div>
        <div><strong>N° Série :</strong> ${_escIA(bien.NumeroSerie || '—')}</div>
        ${bien.InfoProduit ? `<div style="grid-column:span 2"><strong>Description :</strong> ${_escIA(bien.InfoProduit)}</div>` : ''}
        <div><strong>Affecté à :</strong> ${_escIA(bien.NomPrenom || 'Non affecté')}</div>
        <div><strong>Localisation :</strong> ${_escIA([bien.Batiment, bien.Etage, bien.NumeroBureau].filter(Boolean).join(' › ') || '—')}</div>
      </div>

      <div style="border-top:1px solid var(--gray-border);padding-top:14px;margin-top:14px">
        <div style="font-size:13px;font-weight:700;margin-bottom:10px">📍 Où se trouve ce bien actuellement ?</div>
        <div class="ia-form-grid">
          <div>
            <label style="font-size:12px;font-weight:600;margin-bottom:4px;display:block">Bâtiment</label>
            <input class="form-control" id="ia_decl_bat" placeholder="Bâtiment">
          </div>
          <div>
            <label style="font-size:12px;font-weight:600;margin-bottom:4px;display:block">Étage</label>
            <input class="form-control" id="ia_decl_etage" placeholder="Étage">
          </div>
          <div>
            <label style="font-size:12px;font-weight:600;margin-bottom:4px;display:block">N° Bureau</label>
            <input class="form-control" id="ia_decl_bureau" placeholder="N° Bureau">
          </div>
          <div>
            <label style="font-size:12px;font-weight:600;margin-bottom:4px;display:block">Personne en possession</label>
            <div style="position:relative">
              <input class="form-control" id="ia_decl_personne" placeholder="Laisser vide = moi-même" autocomplete="off"
                oninput="_iaSearchUserPresence(this.value)" onfocus="_iaSearchUserPresence(this.value)">
              <div id="ia_decl_personne_dropdown" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:9999;max-height:180px;overflow-y:auto;background:var(--card-bg,#fff);border:1px solid var(--gray-border);border-radius:0 0 8px 8px;box-shadow:0 4px 12px rgba(0,0,0,.12)"></div>
            </div>
            <div style="font-size:11px;color:var(--gray-text);margin-top:3px">Si le bien est utilisé par quelqu'un d'autre, indiquez son nom.</div>
          </div>
          <div>
            <label style="font-size:12px;font-weight:600;margin-bottom:4px;display:block">Commentaire (optionnel)</label>
            <input class="form-control" id="ia_decl_comment" placeholder="Ex: trouvé dans le couloir">
          </div>
        </div>
        <div style="margin-top:14px;display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap">
          <button class="ia-btn-secondary" onclick="IA_State.searchResult=null;_renderTabDeclarer(document.getElementById('ia_content'))">
            Annuler
          </button>
          <button class="ia-btn-primary" onclick="_iaEnvoyerDeclaration('presence','${_escIA(bien.Numero)}')">
            ✅ Déclarer « J'ai ce bien »
          </button>
        </div>
      </div>
    </div>`;

  // Charger les bâtiments en asynchrone pour remplacer l'input par un select
  _iaChargerBatiments();
}

async function _iaChargerBatiments() {
  try {
    if (typeof ListesApi === 'undefined') return;

    // Bâtiments
    const bats = await ListesApi.getByCategorie('Batiment');
    if (bats.length) {
      const input = document.getElementById('ia_decl_bat');
      if (input) {
        const select = document.createElement('select');
        select.className = 'form-control';
        select.id = 'ia_decl_bat';
        select.innerHTML = '<option value="">— Sélectionner —</option>' + bats.map(b => `<option value="${_escIA(b.Valeur)}">${_escIA(b.Valeur)}</option>`).join('');
        input.replaceWith(select);
      }
    }

    // Étages
    const etages = await ListesApi.getByCategorie('Etage');
    if (etages.length) {
      const input = document.getElementById('ia_decl_etage');
      if (input) {
        const select = document.createElement('select');
        select.className = 'form-control';
        select.id = 'ia_decl_etage';
        select.innerHTML = '<option value="">— Sélectionner —</option>' + etages.map(e => `<option value="${_escIA(e.Valeur)}">${_escIA(e.Valeur)}</option>`).join('');
        input.replaceWith(select);
      }
    }
  } catch(_) {}
}

async function _iaEnvoyerDeclaration(type, numero) {
  const payload = {
    numeroBien:   numero,
    type:         type,
    batiment:     gv('ia_decl_bat'),
    etage:        gv('ia_decl_etage'),
    numeroBureau: gv('ia_decl_bureau'),
    commentaire:  gv('ia_decl_comment'),
    personneDeclaree: gv('ia_decl_personne'),
  };

  try {
    const resp = await apiRequest('inventaire_agent', 'POST', payload);
    let msg = 'Déclaration envoyée ! Elle sera examinée par votre gestionnaire.';
    if (resp.doublon) {
      msg = '⚠️ Déclaration envoyée. Attention : un autre agent a aussi déclaré ce bien. Le gestionnaire tranchera.';
    }
    toast(msg, 'success');
    IA_State.tab = 'historique';
    renderInventaireAgent();
  } catch(e) { toast(e.message, 'error'); }
}

// ── Signaler un départ ──────────────────────────────────────────────────────
function _iaSignalerDepart(numero) {
  openModal('📤 Signaler un mouvement / départ', `
    <div style="margin-bottom:14px;font-size:13px">
      Le bien <strong>${_escIA(numero)}</strong> a quitté votre poste ?
      Indiquez ce qu'il est devenu pour aider le gestionnaire.
    </div>

    <div style="margin-bottom:12px">
      <label style="font-size:12px;font-weight:600;margin-bottom:4px;display:block">Que s'est-il passé ?</label>
      <select class="form-control" id="ia_depart_raison" onchange="_iaUpdateDepartFields()">
        <option value="transfert_bureau">📍 Transféré dans un autre bureau</option>
        <option value="donne_personne">👤 Donné / prêté à une autre personne</option>
        <option value="retour_magasin">📦 Retourné au magasin / stock</option>
        <option value="hors_service">🔧 Hors service / en panne</option>
        <option value="autre">❓ Autre</option>
      </select>
    </div>

    <div id="ia_depart_fields">
      <div id="ia_dep_personne_wrap" style="margin-bottom:12px;display:none">
        <label style="font-size:12px;font-weight:600;margin-bottom:4px;display:block">Remis à</label>
        <div style="position:relative">
          <input class="form-control" id="ia_dep_personne" placeholder="Taper un nom…" autocomplete="off"
            oninput="_iaSearchUserDepart(this.value)" onfocus="_iaSearchUserDepart(this.value)">
          <div id="ia_dep_personne_dropdown" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:9999;max-height:180px;overflow-y:auto;background:var(--card-bg,#fff);border:1px solid var(--gray-border);border-radius:0 0 8px 8px;box-shadow:0 4px 12px rgba(0,0,0,.12)"></div>
        </div>
      </div>
      <div id="ia_dep_loc_wrap">
        <div style="font-size:12px;font-weight:600;margin-bottom:6px">📍 Nouvelle localisation</div>
        <div class="ia-form-grid" style="margin-bottom:12px">
          <div>
            <label style="font-size:11px;color:var(--gray-text);margin-bottom:3px;display:block">Bâtiment</label>
            <input class="form-control" id="ia_dep_batiment" placeholder="Bâtiment">
          </div>
          <div>
            <label style="font-size:11px;color:var(--gray-text);margin-bottom:3px;display:block">Étage</label>
            <input class="form-control" id="ia_dep_etage" placeholder="Étage">
          </div>
          <div>
            <label style="font-size:11px;color:var(--gray-text);margin-bottom:3px;display:block">N° Bureau</label>
            <input class="form-control" id="ia_dep_bureau" placeholder="N° Bureau">
          </div>
        </div>
      </div>
    </div>

    <div>
      <label style="font-size:12px;font-weight:600;margin-bottom:4px;display:block">Commentaire (optionnel)</label>
      <input class="form-control" id="ia_depart_comment" placeholder="Précisions supplémentaires…">
    </div>
  `, async () => {
    try {
      await apiRequest('inventaire_agent', 'POST', {
        numeroBien:   numero,
        type:         'depart',
        raisonDepart: gv('ia_depart_raison'),
        destBatiment: gv('ia_dep_batiment'),
        destEtage:    gv('ia_dep_etage'),
        destBureau:   gv('ia_dep_bureau'),
        destPersonne: gv('ia_dep_personne'),
        commentaire:  gv('ia_depart_comment'),
      });
      toast('Signalement envoyé !', 'success');
      closeModal();
      renderInventaireAgent();
    } catch(e) { toast(e.message, 'error'); }
  }, 'Confirmer');

  // Charger les listes en async
  setTimeout(() => {
    _iaUpdateDepartFields();
    _iaChargerListesDepart();
  }, 100);
}

function _iaUpdateDepartFields() {
  const raison = gv('ia_depart_raison');
  const showLoc  = ['transfert_bureau', 'donne_personne', 'autre'].includes(raison);
  const showPers = ['donne_personne'].includes(raison);

  const locW = document.getElementById('ia_dep_loc_wrap');
  const perW = document.getElementById('ia_dep_personne_wrap');

  if (locW) locW.style.display = showLoc ? '' : 'none';
  if (perW) perW.style.display = showPers ? '' : 'none';
}

async function _iaChargerListesDepart() {
  try {
    if (typeof ListesApi === 'undefined') return;

    // Bâtiments
    const bats = await ListesApi.getByCategorie('Batiment');
    if (bats.length) {
      const input = document.getElementById('ia_dep_batiment');
      if (input) {
        const select = document.createElement('select');
        select.className = 'form-control';
        select.id = 'ia_dep_batiment';
        select.innerHTML = '<option value="">— Sélectionner —</option>' + bats.map(b => `<option value="${_escIA(b.Valeur)}">${_escIA(b.Valeur)}</option>`).join('');
        input.replaceWith(select);
      }
    }

    // Étages
    const etages = await ListesApi.getByCategorie('Etage');
    if (etages.length) {
      const input = document.getElementById('ia_dep_etage');
      if (input) {
        const select = document.createElement('select');
        select.className = 'form-control';
        select.id = 'ia_dep_etage';
        select.innerHTML = '<option value="">— Sélectionner —</option>' + etages.map(e => `<option value="${_escIA(e.Valeur)}">${_escIA(e.Valeur)}</option>`).join('');
        input.replaceWith(select);
      }
    }
  } catch(_) {}
}

// Autocomplete utilisateurs pour le champ "Personne en possession" dans le formulaire présence
let _iaPresUsersCache = null;
let _iaPresSearchTimer = null;

// Fermer le dropdown quand on clique ailleurs
document.addEventListener('click', (e) => {
  const dd = document.getElementById('ia_decl_personne_dropdown');
  if (dd && !e.target.closest('#ia_decl_personne') && !e.target.closest('#ia_decl_personne_dropdown')) {
    dd.style.display = 'none';
  }
});

async function _iaSearchUserPresence(query) {
  const dropdown = document.getElementById('ia_decl_personne_dropdown');
  if (!dropdown) return;

  clearTimeout(_iaPresSearchTimer);
  _iaPresSearchTimer = setTimeout(async () => {
    if (!_iaPresUsersCache) {
      try { _iaPresUsersCache = await apiRequest('utilisateurs_liste'); }
      catch(_) { _iaPresUsersCache = []; }
    }

    const q = (query || '').toLowerCase().trim();
    if (!q) { dropdown.style.display = 'none'; return; }

    const results = _iaPresUsersCache
      .filter(u => (u.Actif ?? 1) != 0)
      .filter(u => {
        const full = ((u.Prenom || '') + ' ' + (u.Nom || '')).toLowerCase();
        const rev  = ((u.Nom || '') + ' ' + (u.Prenom || '')).toLowerCase();
        return full.includes(q) || rev.includes(q) || (u.Login || '').toLowerCase().includes(q);
      })
      .slice(0, 6);

    if (!results.length) {
      dropdown.innerHTML = '<div style="padding:8px 14px;font-size:12px;color:var(--gray-text)">Aucun résultat — saisie libre</div>';
      dropdown.style.display = 'block';
      return;
    }

    dropdown.innerHTML = results.map(u => {
      const nom = ((u.Prenom || '') + ' ' + (u.Nom || '')).trim();
      return `<div class="ia-ac-item" data-nom="${_escIA(nom)}"
        style="padding:8px 14px;cursor:pointer;font-size:13px;border-bottom:1px solid var(--gray-border)"
        onmouseover="this.style.background='var(--gray-bg)'"
        onmouseout="this.style.background=''">
        <strong>${_escIA(nom)}</strong>
        <span style="color:var(--gray-text);font-size:11px;margin-left:6px">${_escIA(u.Role)}</span>
      </div>`;
    }).join('');
    dropdown.querySelectorAll('.ia-ac-item').forEach(el => {
      el.addEventListener('click', () => {
        document.getElementById('ia_decl_personne').value = el.dataset.nom;
        dropdown.style.display = 'none';
      });
    });
    dropdown.style.display = 'block';
  }, 150);
}

// Autocomplete utilisateurs pour le champ "Remis à" dans le modal départ
let _iaDepUsersCache = null;
let _iaDepSearchTimer = null;

async function _iaSearchUserDepart(query) {
  const dropdown = document.getElementById('ia_dep_personne_dropdown');
  if (!dropdown) return;

  clearTimeout(_iaDepSearchTimer);
  _iaDepSearchTimer = setTimeout(async () => {
    if (!_iaDepUsersCache) {
      try { _iaDepUsersCache = await apiRequest('utilisateurs_liste'); }
      catch(_) { _iaDepUsersCache = []; }
    }

    const q = (query || '').toLowerCase().trim();
    if (!q) { dropdown.style.display = 'none'; return; }

    const results = _iaDepUsersCache
      .filter(u => (u.Actif ?? 1) != 0)
      .filter(u => {
        const full = ((u.Prenom || '') + ' ' + (u.Nom || '')).toLowerCase();
        const rev  = ((u.Nom || '') + ' ' + (u.Prenom || '')).toLowerCase();
        return full.includes(q) || rev.includes(q) || (u.Login || '').toLowerCase().includes(q);
      })
      .slice(0, 6);

    if (!results.length) {
      dropdown.innerHTML = '<div style="padding:8px 14px;font-size:12px;color:var(--gray-text)">Aucun résultat — saisie libre</div>';
      dropdown.style.display = 'block';
      return;
    }

    dropdown.innerHTML = results.map(u => {
      const nom = ((u.Prenom || '') + ' ' + (u.Nom || '')).trim();
      return `<div class="ia-ac-item" data-nom="${_escIA(nom)}"
        style="padding:8px 14px;cursor:pointer;font-size:13px;border-bottom:1px solid var(--gray-border)"
        onmouseover="this.style.background='var(--gray-bg)'"
        onmouseout="this.style.background=''">
        <strong>${_escIA(nom)}</strong>
        <span style="color:var(--gray-text);font-size:11px;margin-left:6px">${_escIA(u.Role)}</span>
      </div>`;
    }).join('');
    dropdown.querySelectorAll('.ia-ac-item').forEach(el => {
      el.addEventListener('click', () => {
        document.getElementById('ia_dep_personne').value = el.dataset.nom;
        dropdown.style.display = 'none';
      });
    });
    dropdown.style.display = 'block';
  }, 150);
}

// ── Scan code-barres ────────────────────────────────────────────────────────
function _iaOuvrirScan() {
  const zone = document.getElementById('ia_scan_zone');
  if (IA_State.scanning) { _iaFermerScan(); return; }

  zone.innerHTML = `
    <div style="border:2px solid #8b5cf6;border-radius:10px;padding:16px;margin-bottom:16px;background:var(--card-bg,#fff)">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
        <div style="font-size:13px;font-weight:700;color:#8b5cf6">📷 Scanner un code</div>
        <button onclick="_iaFermerScan()" style="border:none;background:none;font-size:18px;cursor:pointer;color:var(--text)">✕</button>
      </div>
      <video id="ia_scan_video" style="width:100%;border-radius:8px;background:#000;max-height:300px" autoplay playsinline></video>
      <div id="ia_scan_status" style="text-align:center;font-size:12px;color:var(--gray-text);margin-top:8px">Démarrage de la caméra…</div>
    </div>`;

  IA_State.scanning = true;

  if (typeof ZXingBrowser !== 'undefined' && ZXingBrowser.BrowserMultiFormatReader) {
    const codeReader = new ZXingBrowser.BrowserMultiFormatReader();
    IA_State._scanReader = codeReader;
    const video = document.getElementById('ia_scan_video');
    codeReader.decodeFromVideoDevice(undefined, video, (result, err) => {
      if (result) {
        const code = result.getText();
        const statusEl = document.getElementById('ia_scan_status');
        if (statusEl) statusEl.innerHTML = `<span style="color:var(--green,#16a34a);font-weight:600">✅ Code détecté : ${_escIA(code)}</span>`;
        _iaFermerScan();
        const input = document.getElementById('ia_numero_input');
        if (input) input.value = code;
        _iaRechercher();
      }
    }).catch(e => {
      const statusEl = document.getElementById('ia_scan_status');
      if (statusEl) statusEl.innerHTML = `<span style="color:#ef4444">❌ ${_escIA(e.message || 'Erreur caméra')}</span>`;
    });
  } else {
    const statusEl = document.getElementById('ia_scan_status');
    if (statusEl) statusEl.innerHTML = '<span style="color:#ef4444">❌ Bibliothèque de scan non disponible. Utilisez la saisie manuelle.</span>';
  }
}

function _iaFermerScan() {
  IA_State.scanning = false;
  if (IA_State._scanReader) { try { IA_State._scanReader.reset(); } catch(_) {} IA_State._scanReader = null; }
  if (IA_State._scanStream) { IA_State._scanStream.getTracks().forEach(t => t.stop()); IA_State._scanStream = null; }
  const zone = document.getElementById('ia_scan_zone');
  if (zone) zone.innerHTML = '';
}

// ═════════════════════════════════════════════════════════════════════════════
//  ONGLET 3 : Historique de mes déclarations
// ═════════════════════════════════════════════════════════════════════════════
function _renderTabHistorique(el) {
  const decls = IA_State.declarations;
  if (!decls.length) {
    el.innerHTML = `<div class="ia-empty">
      <div class="ia-empty-icon">📋</div>
      <div style="font-size:15px;font-weight:600;margin-bottom:6px">Aucune déclaration</div>
      <div style="font-size:13px">Vos déclarations d'inventaire apparaîtront ici une fois envoyées.</div>
    </div>`;
    return;
  }

  const statusLabel = { en_attente: '⏳ En attente', valide: '✅ Validée', refuse: '❌ Refusée' };
  const typeLabel   = { presence: '📦 Présence', depart: '🚫 Départ' };

  let html = '';
  for (const d of decls) {
    const date = (d.DateDeclaration || '').replace('T', ' ').slice(0, 16);
    html += `<div class="ia-card">
      <div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:8px;flex-wrap:wrap;gap:6px">
        <div>
          <span style="font-weight:700;font-size:14px">${_escIA(d.NumeroBien)}</span>
          <span style="margin-left:8px;font-size:12px">${typeLabel[d.Type] || d.Type}</span>
        </div>
        <span class="ia-status ${escHtml(d.Statut||'')}">${statusLabel[d.Statut] || d.Statut}</span>
      </div>
      ${d.InfoProduit ? '<div style="font-size:12px;margin-bottom:6px;color:var(--gray-text)">'+_escIA(d.InfoProduit)+'</div>' : ''}
      <div class="ia-grid-info">
        ${d.Batiment ? '<div><strong>Bâtiment :</strong> '+_escIA(d.Batiment)+'</div>' : ''}
        ${d.Etage ? '<div><strong>Étage :</strong> '+_escIA(d.Etage)+'</div>' : ''}
        ${d.NumeroBureau ? '<div><strong>Bureau :</strong> '+_escIA(d.NumeroBureau)+'</div>' : ''}
        ${d.PersonneDeclaree ? '<div><strong>En possession de :</strong> <span style="color:var(--blue);font-weight:600">'+_escIA(d.PersonneDeclaree)+'</span></div>' : ''}
        <div><strong>Date :</strong> ${_escIA(date)}</div>
        ${d.Commentaire ? '<div style="grid-column:span 2"><strong>Commentaire :</strong> '+_escIA(d.Commentaire)+'</div>' : ''}
      </div>
      ${d.Statut === 'refuse' && d.MotifRefus ? `
        <div style="margin-top:10px;padding:8px 12px;background:#fef2f2;border:1px solid #fecaca;border-radius:6px;font-size:12px">
          <strong style="color:#dc2626">Motif du refus :</strong> ${_escIA(d.MotifRefus)}
        </div>` : ''}
      ${d.Statut === 'valide' ? `
        <div style="margin-top:10px;padding:8px 12px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;font-size:12px;color:#166534">
          Validée par ${_escIA(d.TraiteParNom || d.TraiteParLogin || '—')} le ${_escIA((d.DateTraitement || '').replace('T', ' ').slice(0, 16))}
        </div>` : ''}
    </div>`;
  }

  el.innerHTML = html;
}

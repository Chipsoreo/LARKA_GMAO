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
 * Larka — Préférences d'interface utilisateur
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Gère les préférences visuelles stockées en localStorage.
 *
 * CONTENU :
 *   - Position et largeur de la sidebar (gauche/droite)
 *   - Mode sombre (dark mode)
 *   - Panneau de réglages UI accessible depuis la sidebar
 *   - Redimensionnement de la sidebar par drag
 *   - Sauvegarde/restauration automatique des préférences
 * ═══════════════════════════════════════════════════════════════════════════════
 */

const UI_PREFS_KEY       = 'gmao_ui_prefs';
const SIDEBAR_FULL_DEF   = 240;
const SIDEBAR_ICON_W     = 58;
const SIDEBAR_SNAP       = 120;

const UI_NAV_ITEMS = [
  { page:'dashboard',     label:'Tableau de bord', icon:'📊', section:'Principal'    },
  { page:'biens',         label:'Biens',           icon:'🏢', section:'Inventaire'   },
  { page:'equipements',   label:'Equipements',     icon:'⚙️', section:'Inventaire'   },
  { page:'stock',         label:'Stock',           icon:'📦', section:'Inventaire'   },
  { page:'plans',         label:'Plans',           icon:'🗺️', section:'Plans'        },
  { page:'interventions', label:'Interventions',   icon:'🔧', section:'Maintenance'  },
  { page:'contrats',      label:'Contrats',        icon:'📋', section:'Maintenance'  },
  { page:'gestion_materiel', label:'Gestion matériel', icon:'💰', section:'Gestion'      },
  { page:'historique',    label:'Historique',      icon:'🗃️', section:'Gestion'      },
  { page:'demandes',      label:'Demandes',        icon:'📝', section:'Gestion'      },
  { page:'energie',          label:'Energie',          icon:'⚡', section:'Fluides'      },
  { page:'carbone',          label:'Bilan Carbone',    icon:'🌿', section:'Fluides'      },
  { page:'mobilite_carbone', label:'Ma mobilité',      icon:'🚗', section:'Fluides'      },
  { page:'urgences',         label:'Procédures d\'urgence', icon:'🚨', section:'Sécurité'    },
  { page:'archives',         label:'Archives',         icon:'🗄️', section:'Archives'     },
  { page:'statsAvancees',    label:'Stats avancees',   icon:'📈', section:'Analyse'      },
  { page:'legifrance',       label:'Légifrance',       icon:'📜', section:'République'   },
  { page:'chorus',           label:'Chorus Pro',       icon:'🏛️', section:'République'   },
];

function uiPrefsGet() {
  try {
    var p = JSON.parse(localStorage.getItem(UI_PREFS_KEY)) || {};
    // ── Migration automatique : 'comptabilite' → 'gestion_materiel' ──
    // Les anciennes préfs utilisateurs peuvent contenir l'ancienne clé.
    // On la remplace silencieusement pour qu'ils ne perdent pas leur menu.
    // Cette migration peut être retirée dans plusieurs mois quand tous les
    // utilisateurs auront rechargé l'app au moins une fois.
    var migrated = false;
    if (Array.isArray(p.navOrder)) {
      p.navOrder = p.navOrder.map(function(k) {
        if (k === 'comptabilite') { migrated = true; return 'gestion_materiel'; }
        return k;
      });
    }
    if (p.hiddenPages && typeof p.hiddenPages === 'object') {
      if (p.hiddenPages.comptabilite !== undefined) {
        p.hiddenPages.gestion_materiel = p.hiddenPages.comptabilite;
        delete p.hiddenPages.comptabilite;
        migrated = true;
      }
    }
    if (migrated) {
      try { localStorage.setItem(UI_PREFS_KEY, JSON.stringify(p)); } catch(_) {}
    }
    return p;
  } catch(e) { return {}; }
}
function uiPrefsSet(patch) {
  var p = uiPrefsGet(); Object.assign(p, patch);
  localStorage.setItem(UI_PREFS_KEY, JSON.stringify(p)); return p;
}

// ── Ordre des items du menu ──────────────────────────────────────────────────
// Retourne la liste UI_NAV_ITEMS dans l'ordre voulu par l'utilisateur :
//  - en s'appuyant sur uiPrefs.navOrder (tableau de pages mémorisé)
//  - les nouvelles pages non présentes dans navOrder sont rajoutées en fin
//    (utile quand on ajoute un module : il n'est pas perdu)
//  - une page de navOrder qui n'existe plus est ignorée
//  - chaque item peut être réaffecté à une autre section via uiPrefs.itemSections
function getOrderedNavItems() {
  var prefs = uiPrefsGet();
  var stored = prefs.navOrder;
  var itemSections = prefs.itemSections || {};
  // Override de section : on clone l'item pour ne pas muter UI_NAV_ITEMS
  function withSection(n) {
    var s = itemSections[n.page];
    return s ? Object.assign({}, n, { section: s }) : n;
  }
  if (!Array.isArray(stored) || !stored.length) return UI_NAV_ITEMS.map(withSection);
  var byPage = {};
  UI_NAV_ITEMS.forEach(function(n) { byPage[n.page] = n; });
  var seen = {};
  var out = [];
  stored.forEach(function(page) {
    if (byPage[page] && !seen[page]) { out.push(withSection(byPage[page])); seen[page] = true; }
  });
  UI_NAV_ITEMS.forEach(function(n) { if (!seen[n.page]) out.push(withSection(n)); });
  return out;
}

// Retourne l'ordre des sections, calculé à partir de l'ordre courant des items
// (la 1ère apparition d'une section dans la liste fixe son rang).
function getOrderedSections() {
  var items = getOrderedNavItems();
  var secs = [];
  items.forEach(function(n) { if (secs.indexOf(n.section) === -1) secs.push(n.section); });
  return secs;
}

// Retourne le libellé d'affichage d'une section (en tenant compte des renommages
// par l'utilisateur). La clé interne reste l'original pour la rétrocompatibilité.
function getSectionLabel(sectionKey) {
  var renames = uiPrefsGet().sectionRenames || {};
  return renames[sectionKey] || sectionKey;
}

// Renomme une section. Retourne true si OK, false sinon.
function renameSection(oldName, newName) {
  newName = (newName || '').trim();
  if (!newName) return false;
  var prefs = uiPrefsGet();
  var renames = prefs.sectionRenames || {};
  renames[oldName] = newName;
  uiPrefsSet({ sectionRenames: renames });
  return true;
}

// Réaffecte un item à une autre section (peut être une nouvelle section ou
// une section existante).
function setItemSection(page, sectionKey) {
  var prefs = uiPrefsGet();
  var itemSections = prefs.itemSections || {};
  // Si on remet à la section d'origine, on supprime l'override pour rester propre
  var orig = UI_NAV_ITEMS.find(function(n) { return n.page === page; });
  if (orig && orig.section === sectionKey) delete itemSections[page];
  else itemSections[page] = sectionKey;
  uiPrefsSet({ itemSections: itemSections });
}

// ─────────────────────────────────────────────────────────────────────────────

function applyUiPrefs() {
  var prefs = uiPrefsGet();
  var pos   = prefs.sidebarPos || 'left';
  _setPosition(pos);
  _setSize(prefs.sidebarSize || SIDEBAR_FULL_DEF, pos);
  _refreshHandle();
  // Sync icône bouton collapse (masquer en mode horizontal)
  var btn = document.getElementById('sidebarCollapseBtn');
  if (btn) {
    if (_isHoriz(pos)) {
      btn.style.display = 'none';
    } else {
      btn.style.display = '';
      var collapsed = (prefs.sidebarSize || SIDEBAR_FULL_DEF) <= SIDEBAR_ICON_W + 4;
      btn.textContent = collapsed ? '▶' : '◀';
    }
  }
}

function _setPosition(pos) {
  var body   = document.body;
  var layout = document.querySelector('.layout');
  if (!layout) return;
  body.classList.remove('sidebar-pos-right','sidebar-pos-bottom','sidebar-pos-top');
  layout.classList.remove('sidebar-right');
  if (pos === 'right')  layout.classList.add('sidebar-right');
  if (pos === 'bottom') body.classList.add('sidebar-pos-bottom');
  if (pos === 'top')    body.classList.add('sidebar-pos-top');
}

function _isHoriz(pos) { return pos === 'top' || pos === 'bottom'; }

function _setSize(size, pos) {
  var sb = document.querySelector('.sidebar');
  if (!sb) return;
  pos = pos || (uiPrefsGet().sidebarPos || 'left');
  if (_isHoriz(pos)) {
    // En mode horizontal (haut/bas), la hauteur est fixe via CSS (56px)
    // Pas de mode collapsed, on laisse le CSS gérer
    sb.classList.remove('sidebar-collapsed');
    sb.style.height = ''; sb.style.minHeight = '';
    sb.style.width = ''; sb.style.minWidth = '';
  } else {
    var collapsed = size <= SIDEBAR_ICON_W + 4;
    sb.classList.toggle('sidebar-collapsed', collapsed);
    var sz = collapsed ? SIDEBAR_ICON_W : Math.max(SIDEBAR_ICON_W, size);
    sb.style.width = sz+'px'; sb.style.minWidth = sz+'px';
    sb.style.height = ''; sb.style.minHeight = '';
  }
}

// ── Handle drag ───────────────────────────────────────────────────────────────

function _refreshHandle() {
  var h = document.getElementById('sidebarResizeHandle');
  if (!h) return;
  var pos = uiPrefsGet().sidebarPos || 'left';
  // Pas de resize en mode horizontal (haut/bas), la hauteur est fixe
  if (_isHoriz(pos)) {
    h.style.display = 'none';
    return;
  }
  h.style.display = '';
  if (pos === 'right') {
    h.style.cssText = 'position:absolute;top:0;left:-3px;width:8px;height:100%;cursor:col-resize;z-index:20;display:flex;align-items:center;justify-content:center;';
  } else if (pos === 'top') {
    h.style.cssText = 'position:absolute;bottom:-3px;left:0;width:100%;height:8px;cursor:row-resize;z-index:20;display:flex;align-items:center;justify-content:center;';
  } else if (pos === 'bottom') {
    h.style.cssText = 'position:absolute;top:-3px;left:0;width:100%;height:8px;cursor:row-resize;z-index:20;display:flex;align-items:center;justify-content:center;';
  } else {
    h.style.cssText = 'position:absolute;top:0;right:-3px;width:8px;height:100%;cursor:col-resize;z-index:20;display:flex;align-items:center;justify-content:center;';
  }
  h.innerHTML = '<div class="resize-grip" style="' +
    (_isHoriz(pos)
      ? 'width:40px;height:4px;border-radius:4px;background:rgba(255,255,255,.25)'
      : 'width:4px;height:40px;border-radius:4px;background:rgba(255,255,255,.25)') +
    '"></div>';
}

function initSidebarResize() {
  _refreshHandle();
  var h  = document.getElementById('sidebarResizeHandle');
  var sb = document.querySelector('.sidebar');
  if (!h || !sb) return;

  var drag=false, sx=0, sy=0, ss=0;

  function onStart(cx, cy) {
    drag = true;
    sx = cx; sy = cy;
    var prefs = uiPrefsGet();
    ss = prefs.sidebarSize || SIDEBAR_FULL_DEF;
    if (ss <= SIDEBAR_ICON_W + 4) ss = SIDEBAR_FULL_DEF;
    sb.classList.add('is-dragging');
    sb.style.transition = 'none';
    document.body.classList.add('sidebar-is-resizing');
  }

  function onMove(cx, cy) {
    if (!drag) return;
    var pos   = uiPrefsGet().sidebarPos || 'left';
    var delta = _isHoriz(pos)
      ? (pos === 'bottom' ? sy - cy : cy - sy)
      : (pos === 'right'  ? sx - cx : cx - sx);
    var ns = Math.max(SIDEBAR_ICON_W, Math.min(400, ss + delta));
    _setSize(ns, pos);
  }

  function onEnd(cx, cy) {
    if (!drag) return;
    drag = false;
    sb.classList.remove('is-dragging');
    document.body.classList.remove('sidebar-is-resizing');
    var pos = uiPrefsGet().sidebarPos || 'left';
    var cur = _isHoriz(pos)
      ? (parseInt(sb.style.height) || SIDEBAR_FULL_DEF)
      : (parseInt(sb.style.width)  || SIDEBAR_FULL_DEF);
    var snapped = cur < SIDEBAR_SNAP ? SIDEBAR_ICON_W : Math.max(140, cur);
    uiPrefsSet({ sidebarSize: snapped });
    sb.style.transition = 'width .18s cubic-bezier(.4,0,.2,1), min-width .18s, height .18s, min-height .18s';
    _setSize(snapped, pos);
    setTimeout(function() { if(sb) sb.style.transition = ''; }, 200);
  }

  h.addEventListener('mousedown',  function(e) { e.preventDefault(); onStart(e.clientX, e.clientY); });
  h.addEventListener('touchstart', function(e) { var t=e.touches[0]; onStart(t.clientX, t.clientY); }, {passive:true});
  document.addEventListener('mousemove', function(e) { onMove(e.clientX, e.clientY); });
  document.addEventListener('touchmove', function(e) { var t=e.touches[0]; onMove(t.clientX, t.clientY); }, {passive:true});
  document.addEventListener('mouseup',   function(e) { onEnd(e.clientX, e.clientY); });
  document.addEventListener('touchend',  function(e) { onEnd(0,0); });

  // Double-clic → toggle rapide
  h.addEventListener('dblclick', function() {
    var prefs = uiPrefsGet();
    var pos   = prefs.sidebarPos || 'left';
    var cur   = prefs.sidebarSize || SIDEBAR_FULL_DEF;
    var ns    = (cur <= SIDEBAR_ICON_W + 4) ? SIDEBAR_FULL_DEF : SIDEBAR_ICON_W;
    uiPrefsSet({ sidebarSize: ns });
    sb.style.transition = 'width .22s cubic-bezier(.4,0,.2,1), min-width .22s, height .22s, min-height .22s';
    _setSize(ns, pos);
    setTimeout(function() { if(sb) sb.style.transition = ''; }, 250);
  });
}

// ── Actions publiques ─────────────────────────────────────────────────────────

function setSidebarPosition(pos) {
  uiPrefsSet({ sidebarPos: pos });
  applyUiPrefs();
  _refreshHandle();
  if (window._currentUser) buildSidebarWithVisibility(window._currentUser);
  _syncPrefsPanel();
}

function _quickResize(size) {
  var pos = uiPrefsGet().sidebarPos || 'left';
  uiPrefsSet({ sidebarSize: size });
  var sb = document.querySelector('.sidebar');
  if (sb) {
    sb.style.transition = 'width .22s cubic-bezier(.4,0,.2,1), min-width .22s, height .22s, min-height .22s';
    _setSize(size, pos);
    setTimeout(function() { if(sb) sb.style.transition = ''; }, 250);
  }
}

function getUserNavVisibility() { return uiPrefsGet().navVisibility || {}; }

// ── Toggle rétractation manuelle ──────────────────────────────────────────────
function toggleSidebarCollapse() {
  var prefs = uiPrefsGet();
  var pos   = prefs.sidebarPos || 'left';
  var cur   = prefs.sidebarSize || SIDEBAR_FULL_DEF;
  var ns    = (cur <= SIDEBAR_ICON_W + 4) ? SIDEBAR_FULL_DEF : SIDEBAR_ICON_W;
  uiPrefsSet({ sidebarSize: ns });
  var sb = document.querySelector('.sidebar');
  if (sb) {
    sb.style.transition = 'width .22s cubic-bezier(.4,0,.2,1), min-width .22s, height .22s, min-height .22s';
    _setSize(ns, pos);
    setTimeout(function() { if (sb) sb.style.transition = ''; }, 250);
  }
  // Mettre à jour l'icône du bouton
  var btn = document.getElementById('sidebarCollapseBtn');
  if (btn) btn.textContent = ns <= SIDEBAR_ICON_W + 4 ? '▶' : '◀';
}

// ── Panneau ───────────────────────────────────────────────────────────────────

function openUiPrefsPanel() {
  if (document.getElementById('uiPrefsPanel')) { closeUiPrefsPanel(); return; }
  var prefs = uiPrefsGet(), pos = prefs.sidebarPos||'left', vis = prefs.navVisibility||{};
  var isDemandeur = (App.currentUser && App.currentUser.Role === 'Demandeur');
  var panel = document.createElement('div');
  panel.id = 'uiPrefsPanel';
  panel.style.cssText = 'position:fixed;top:62px;right:16px;z-index:9990;width:340px;max-height:calc(100vh - 80px);overflow-y:auto;background:var(--white);border:1px solid var(--gray-border);border-radius:14px;box-shadow:0 8px 32px rgba(0,0,0,.18);font-family:"DM Sans",sans-serif;';

  var h = '';
  h += '<div style="padding:16px 18px 12px;border-bottom:1px solid var(--gray-border);display:flex;align-items:center;justify-content:space-between">';
  h += '<span style="font-size:14px;font-weight:700;color:var(--navy)">🎨 Préférences interface</span>';
  h += '<button onclick="closeUiPrefsPanel()" style="background:var(--gray-bg);border:none;border-radius:6px;width:26px;height:26px;cursor:pointer;font-size:15px;color:var(--gray-text)">✕</button>';
  h += '</div>';

  // Position
  var positions = [{id:'left',icon:'⬅️',label:'Gauche'},{id:'top',icon:'⬆️',label:'Haut'},{id:'right',icon:'➡️',label:'Droite'},{id:'bottom',icon:'⬇️',label:'Bas'}];

  h += '<div style="padding:14px 18px;border-bottom:1px solid var(--gray-border)">';
  h += '<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--gray-text);margin-bottom:10px">Position de la navigation</div>';
  h += '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px">';
  positions.forEach(function(p) {
    var a = pos===p.id;
    h += '<button id="pos_btn_'+p.id+'" onclick="setSidebarPosition(\''+p.id+'\')" style="padding:10px 4px;border-radius:10px;border:2px solid '+(a?'var(--blue)':'var(--gray-border)')+';background:'+(a?'var(--blue-pale)':'var(--white)')+';cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:4px;transition:all .15s">';
    h += '<span style="font-size:18px">'+p.icon+'</span>';
    h += '<span style="font-size:11px;font-weight:700;color:'+(a?'var(--blue)':'var(--gray-text)')+'">'+p.label+'</span>';
    h += '</button>';
  });
  h += '</div>';

  // Taille rapide
  h += '<div style="margin-top:12px">';
  h += '<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--gray-text);margin-bottom:8px">Taille</div>';
  h += '<div style="display:flex;gap:8px">';
  h += '<button onclick="_quickResize(240)" style="flex:1;padding:8px 0;border-radius:8px;border:1.5px solid var(--gray-border);background:var(--white);cursor:pointer;font-size:12px;font-weight:600;color:var(--text)">▐█ Étendu</button>';
  h += '<button onclick="_quickResize(58)"  style="flex:1;padding:8px 0;border-radius:8px;border:1.5px solid var(--gray-border);background:var(--white);cursor:pointer;font-size:12px;font-weight:600;color:var(--text)">▐ Icônes</button>';
  h += '</div>';
  h += '<div style="font-size:11px;color:var(--gray-text);margin-top:6px;text-align:center">💡 Glissez le bord de la sidebar · Double-clic pour basculer</div>';
  h += '</div></div>'; // fin position

  // Onglets visibles + ORDRE + SECTIONS PERSO — masqué pour les demandeurs
  if (!isDemandeur) {
    h += '<div style="padding:14px 18px">';
    h += '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">';
    h += '<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--gray-text)">Onglets, ordre &amp; sections</div>';
    h += '<div style="font-size:11px;color:var(--gray-text)" id="navVisCounter">—</div>';
    h += '</div>';
    h += '<div style="font-size:11px;color:var(--gray-text);margin-bottom:10px;line-height:1.4">Cochez pour afficher. Glissez <span style="color:var(--blue);font-weight:700">⠿</span> pour réordonner ou changer de section. Cliquez sur un nom de section pour le renommer.</div>';

    var ordered = (typeof getOrderedNavItems === 'function') ? getOrderedNavItems() : UI_NAV_ITEMS.slice();
    var renderedSections = {};
    h += '<div id="navOrderList" ondragover="event.preventDefault()" style="display:flex;flex-direction:column;gap:0">';
    ordered.forEach(function(n) {
      if (!renderedSections[n.section]) {
        renderedSections[n.section] = true;
        var label = (typeof getSectionLabel === 'function') ? getSectionLabel(n.section) : n.section;
        // Bandeau de section éditable et droppable (déposer ici = changer la section de l'item)
        h += '<div data-section-header="'+_attr(n.section)+'"'
          +  ' ondragover="_navSectionDragOver(event,\''+_attr(n.section)+'\')"'
          +  ' ondragleave="_navSectionDragLeave(event)"'
          +  ' ondrop="_navSectionDrop(event,\''+_attr(n.section)+'\')"'
          +  ' style="font-size:10px;font-weight:700;text-transform:uppercase;color:var(--gray-text);padding:10px 6px 4px;display:flex;align-items:center;justify-content:space-between;border-radius:6px;transition:background .1s">';
        h += '<span ondblclick="_navRenameSection(\''+_attr(n.section)+'\',this)" title="Double-cliquez pour renommer" style="cursor:text;flex:1">'+_escPrefs(label)+'</span>';
        h += '<span style="display:flex;gap:4px;align-items:center">';
        h += '<button onclick="_toggleNavSection(\''+_attr(n.section)+'\',this)" style="background:transparent;border:none;color:var(--blue);font-size:10px;font-weight:700;cursor:pointer;padding:0 4px">tout</button>';
        h += '</span></div>';
      }
      var v = vis[n.page] !== false;
      h += '<div id="row_'+n.page+'" data-page="'+n.page+'" data-section="'+_attr(n.section)+'" draggable="true"'
        +  ' ondragstart="_navDragStart(event,\''+n.page+'\')"'
        +  ' ondragend="_navDragEnd(event)"'
        +  ' ondragover="_navDragOver(event,\''+n.page+'\')"'
        +  ' ondragleave="_navDragLeave(event)"'
        +  ' ondrop="_navDrop(event,\''+n.page+'\')"'
        +  ' style="display:flex;align-items:center;gap:8px;padding:8px 10px;cursor:grab;border:1px solid var(--gray-border);border-radius:6px;background:var(--white);margin-bottom:4px;transition:background .1s,border-color .1s,transform .15s">';
      h += '<span title="Glisser pour réordonner ou changer de section" style="color:var(--gray-text);font-size:14px;cursor:grab;user-select:none;line-height:1">⠿</span>';
      h += '<input type="checkbox" id="uipref_'+n.page+'" '+(v?'checked':'')+' onchange="_onNavChipChange(this,\''+n.page+'\')" style="width:16px;height:16px;accent-color:var(--blue);cursor:pointer;flex-shrink:0">';
      h += '<span style="font-size:14px;width:20px;text-align:center">'+n.icon+'</span>';
      h += '<span style="font-size:13px;color:var(--text);flex:1">'+n.label+'</span>';
      h += '</div>';
    });
    h += '</div>';

    // Bouton pour créer une nouvelle section
    h += '<button onclick="_navCreateSection()" style="width:100%;margin-top:8px;padding:8px;border-radius:8px;border:1px dashed var(--gray-border);background:transparent;color:var(--blue);font-size:12px;font-weight:600;cursor:pointer">＋ Nouvelle section</button>';

    h += '<div style="display:flex;gap:8px;margin-top:12px">';
    h += '<button onclick="_resetNavVis()" title="Tout afficher et restaurer l\'ordre par défaut" style="flex:1;padding:8px;border-radius:8px;border:1px solid var(--gray-border);background:var(--white);font-size:12px;font-weight:600;cursor:pointer;color:var(--gray-text)">↺ Réinitialiser</button>';
    h += '<button onclick="_saveNavVis()" style="flex:1;padding:8px;border-radius:8px;border:none;background:var(--blue);color:white;font-size:12px;font-weight:600;cursor:pointer">💾 Enregistrer</button>';
    h += '</div></div>';
  }

  // Affichage des notes — uniquement pour les demandeurs
  if (isDemandeur) {
    var notesMode = prefs.notesMode || 'defilant';
    var isDef = notesMode === 'defilant';
    var isFix = notesMode === 'fixe';
    h += '<div style="padding:14px 18px;border-top:1px solid var(--gray-border)">';
    h += '<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--gray-text);margin-bottom:10px">Affichage des notes</div>';
    h += '<div style="display:flex;gap:8px">';
    h += '<button onclick="_setNotesMode(\'defilant\')" id="notesMode_defilant" style="flex:1;padding:10px 8px;border-radius:10px;border:2px solid '+(isDef?'var(--blue)':'var(--gray-border)')+';background:'+(isDef?'var(--blue-pale)':'var(--white)')+';cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:4px;transition:all .15s">';
    h += '<span style="font-size:18px">📢</span>';
    h += '<span style="font-size:11px;font-weight:700;color:'+(isDef?'var(--blue)':'var(--gray-text)')+'">Bandeau défilant</span>';
    h += '</button>';
    h += '<button onclick="_setNotesMode(\'fixe\')" id="notesMode_fixe" style="flex:1;padding:10px 8px;border-radius:10px;border:2px solid '+(isFix?'var(--blue)':'var(--gray-border)')+';background:'+(isFix?'var(--blue-pale)':'var(--white)')+';cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:4px;transition:all .15s">';
    h += '<span style="font-size:18px">📌</span>';
    h += '<span style="font-size:11px;font-weight:700;color:'+(isFix?'var(--blue)':'var(--gray-text)')+'">Note fixe</span>';
    h += '</button>';
    h += '</div>';
    h += '<div style="font-size:11px;color:var(--gray-text);margin-top:6px;text-align:center">Choisissez comment les informations s\'affichent en haut de la page.</div>';
    h += '</div>';
  }

  // ── Section Assistant IA (sauf demandeurs) ─────────────────────────────────
  if (!isDemandeur) {
    var hlOn = localStorage.getItem('assistant_highlight_enabled') !== 'false';
    h += '<div style="padding:14px 18px;border-top:1px solid var(--gray-border)">';
    h += '<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--gray-text);margin-bottom:10px">🤖 Assistant IA</div>';
    h += '<label style="display:flex;align-items:center;gap:10px;cursor:pointer;padding:6px 0">';
    h += '<input type="checkbox" id="assistantHlCheckbox" '+(hlOn?'checked':'')+' onchange="_toggleAssistantHighlight(this)" style="width:16px;height:16px;accent-color:var(--blue);cursor:pointer">';
    h += '<span style="flex:1">';
    h += '<span style="font-size:13px;color:var(--text);font-weight:600">🎯 Surbrillance des éléments sur le plan</span>';
    h += '<div style="font-size:11px;color:var(--gray-text);margin-top:2px;line-height:1.4">Quand l\'IA vous montre des éléments sur un plan, ils sont mis en surbrillance avec une pulsation et un halo doré.</div>';
    h += '</span>';
    h += '</label>';
    h += '</div>';
  }

  panel.innerHTML = h;
  document.body.appendChild(panel);
  if (typeof _updateNavVisCounter === 'function') _updateNavVisCounter();
  setTimeout(function() { document.addEventListener('click', _closeOut); }, 50);
}

/** Toggle préférence surbrillance assistant. Stocke dans localStorage. */
function _toggleAssistantHighlight(cb) {
  localStorage.setItem('assistant_highlight_enabled', cb.checked ? 'true' : 'false');
}

function closeUiPrefsPanel() {
  var p = document.getElementById('uiPrefsPanel');
  if (p) p.remove();
  document.removeEventListener('click', _closeOut);
}

function _closeOut(e) {
  var panel = document.getElementById('uiPrefsPanel');
  var btn   = document.getElementById('uiPrefsBtnTopbar');
  if (!panel) { document.removeEventListener('click', _closeOut); return; }
  if (!panel.contains(e.target) && (!btn || !btn.contains(e.target))) closeUiPrefsPanel();
}

function _syncPrefsPanel() {
  var pos = uiPrefsGet().sidebarPos||'left';
  ['left','top','right','bottom'].forEach(function(p) {
    var btn = document.getElementById('pos_btn_'+p); if(!btn) return;
    var a = pos===p;
    btn.style.borderColor = a?'var(--blue)':'var(--gray-border)';
    btn.style.background  = a?'var(--blue-pale)':'var(--white)';
    var lbl = btn.querySelector('span:last-child');
    if(lbl) lbl.style.color = a?'var(--blue)':'var(--gray-text)';
  });
}

function _setNotesMode(mode) {
  uiPrefsSet({ notesMode: mode });
  // Mise à jour visuelle des boutons
  ['defilant','fixe'].forEach(function(m) {
    var btn = document.getElementById('notesMode_'+m);
    if (!btn) return;
    var a = m === mode;
    btn.style.borderColor = a ? 'var(--blue)' : 'var(--gray-border)';
    btn.style.background  = a ? 'var(--blue-pale)' : 'var(--white)';
    var lbl = btn.querySelector('span:last-child');
    if (lbl) lbl.style.color = a ? 'var(--blue)' : 'var(--gray-text)';
  });
  // Rafraîchir la page demandes si on y est
  if (App.currentPage === 'demandes' && typeof renderDemandes === 'function') {
    renderDemandes();
  }
  if (typeof toast === 'function') toast('Affichage des notes mis à jour.', 'success');
}

function getNotesMode() {
  return uiPrefsGet().notesMode || 'defilant';
}

function _onNavChipChange(cb, page) {
  // Avec les nouvelles checkboxes natives, on n'a plus besoin de styliser un chip
  // Mais on met à jour le compteur
  _updateNavVisCounter();
}

function _updateNavVisCounter() {
  var counter = document.getElementById('navVisCounter');
  if (!counter) return;
  var checked = 0, total = UI_NAV_ITEMS.length;
  UI_NAV_ITEMS.forEach(function(n) {
    var el = document.getElementById('uipref_'+n.page);
    if (el && el.checked) checked++;
  });
  counter.textContent = checked + ' / ' + total + ' visibles';
}

function _toggleNavSection(sec, btn) {
  // Si tous cochés -> tout décocher, sinon tout cocher
  var items = UI_NAV_ITEMS.filter(function(n) { return n.section===sec; });
  var allChecked = items.every(function(n) {
    var el = document.getElementById('uipref_'+n.page);
    return el && el.checked;
  });
  items.forEach(function(n) {
    var el = document.getElementById('uipref_'+n.page);
    if (el) el.checked = !allChecked;
  });
  _updateNavVisCounter();
}

function _saveNavVis() {
  var v = {};
  UI_NAV_ITEMS.forEach(function(n) { var el=document.getElementById('uipref_'+n.page); if(el) v[n.page]=el.checked; });
  // Lire l'ordre courant des lignes du DOM (le drag-and-drop a pu les déplacer).
  var order = [];
  var rows = document.querySelectorAll('#navOrderList [data-page]');
  rows.forEach(function(r) { order.push(r.getAttribute('data-page')); });
  // Lire aussi la section actuelle de chaque item — si elle diffère de la section
  // d'origine définie dans UI_NAV_ITEMS, c'est qu'on l'a réaffectée par drag.
  var itemSections = {};
  rows.forEach(function(r) {
    var page = r.getAttribute('data-page');
    var sec  = r.getAttribute('data-section');
    var orig = UI_NAV_ITEMS.find(function(n) { return n.page === page; });
    if (orig && sec && sec !== orig.section) itemSections[page] = sec;
  });
  uiPrefsSet({
    navVisibility: v,
    navOrder: order.length ? order : undefined,
    itemSections: itemSections
  });
  if (window._currentUser) buildSidebarWithVisibility(window._currentUser);
  closeUiPrefsPanel();
  if (typeof toast==='function') toast('Préférences enregistrées','success');
}

function _resetNavVis() {
  UI_NAV_ITEMS.forEach(function(n) {
    var el = document.getElementById('uipref_'+n.page);
    if (el) el.checked = true;
  });
  // Effacer aussi l'ordre et les réaffectations de section
  uiPrefsSet({ navOrder: undefined, itemSections: {}, sectionRenames: {} });
  // Recharger le panneau pour refléter visuellement la réinitialisation
  closeUiPrefsPanel();
  openUiPrefsPanel();
}

// ── Drag-and-drop pour réordonner les items du menu ──────────────────────────
// L'utilisateur peut faire glisser n'importe quelle ligne au-dessus ou en
// dessous d'une autre. Les bandeaux de section restent à leur place tant
// qu'aucun item de la section n'a été enlevé ou ajouté ; après réordonnancement,
// le panneau est rafraîchi à l'enregistrement pour regrouper visuellement.
var _navDragPage = null;
function _navDragStart(ev, page) {
  _navDragPage = page;
  try { ev.dataTransfer.effectAllowed = 'move'; ev.dataTransfer.setData('text/plain', page); } catch(_) {}
  var row = document.getElementById('row_'+page);
  if (row) row.style.opacity = '.45';
}
function _navDragEnd(ev) {
  if (_navDragPage) {
    var row = document.getElementById('row_'+_navDragPage);
    if (row) row.style.opacity = '';
  }
  _navDragPage = null;
  // Nettoyer toutes les surbrillances de drop-target
  document.querySelectorAll('#navOrderList [data-page]').forEach(function(r) {
    r.style.borderColor = 'var(--gray-border)';
    r.style.background  = '';
  });
}
function _navDragOver(ev, page) {
  ev.preventDefault();
  if (!_navDragPage || _navDragPage === page) return;
  try { ev.dataTransfer.dropEffect = 'move'; } catch(_) {}
  var row = document.getElementById('row_'+page);
  if (row) { row.style.borderColor = 'var(--blue)'; row.style.background = 'var(--blue-pale,#eaf2ff)'; }
}
function _navDragLeave(ev) {
  // currentTarget peut être null ; on récupère via le data-page le cas échéant
  var row = ev.currentTarget;
  if (row && row.style) {
    row.style.borderColor = 'var(--gray-border)';
    row.style.background  = '';
  }
}
function _navDrop(ev, targetPage) {
  ev.preventDefault();
  if (!_navDragPage || _navDragPage === targetPage) { _navDragEnd(ev); return; }
  var list = document.getElementById('navOrderList');
  var srcRow    = document.getElementById('row_'+_navDragPage);
  var targetRow = document.getElementById('row_'+targetPage);
  if (!list || !srcRow || !targetRow) { _navDragEnd(ev); return; }
  // Déterminer si on dépose au-dessus ou en dessous de la cible selon
  // la position verticale du curseur sur la ligne cible.
  var rect = targetRow.getBoundingClientRect();
  var above = (ev.clientY - rect.top) < (rect.height / 2);
  list.insertBefore(srcRow, above ? targetRow : targetRow.nextSibling);
  // La section du dragué change peut-être : on met à jour son data-section
  // à partir du bandeau de section précédent (le plus proche en remontant).
  var prev = srcRow.previousElementSibling;
  while (prev) {
    if (prev.hasAttribute && prev.hasAttribute('data-section-header')) {
      srcRow.setAttribute('data-section', prev.getAttribute('data-section-header'));
      break;
    }
    prev = prev.previousElementSibling;
  }
  _navDragEnd(ev);
}

// ── Helpers d'échappement pour injection HTML/attribut ───────────────────────
function _escPrefs(s)  { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function _attr(s) { return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/'/g,"\\'"); }

// ── Renommer une section (double-clic sur le titre) ──────────────────────────
function _navRenameSection(sectionKey, spanEl) {
  var currentLabel = spanEl.textContent;
  var newLabel = window.prompt('Renommer la section :', currentLabel);
  if (newLabel === null) return;                // annulé
  newLabel = newLabel.trim();
  if (!newLabel || newLabel === currentLabel) return;
  if (typeof renameSection === 'function') renameSection(sectionKey, newLabel);
  // Mettre à jour l'affichage immédiatement (sans rouvrir le panneau)
  spanEl.textContent = newLabel;
}

// ── Créer une nouvelle section ───────────────────────────────────────────────
// On ajoute un bandeau de section vide en bas de la liste ; l'utilisateur peut
// ensuite y glisser des items. Si la section reste vide à l'enregistrement,
// elle disparaît naturellement (puisqu'elle ne contient aucun item).
function _navCreateSection() {
  var name = (window.prompt('Nom de la nouvelle section :', '') || '').trim();
  if (!name) return;
  var list = document.getElementById('navOrderList');
  if (!list) return;
  // Vérifie l'unicité (insensible à la casse) parmi les bandeaux existants
  var existing = list.querySelectorAll('[data-section-header]');
  for (var i = 0; i < existing.length; i++) {
    if (existing[i].getAttribute('data-section-header').toLowerCase() === name.toLowerCase()) {
      if (typeof toast === 'function') toast('Une section porte déjà ce nom.', 'error');
      return;
    }
  }
  // Bandeau de section, droppable mais sans bouton "tout" (pas d'items à toggler)
  var div = document.createElement('div');
  div.setAttribute('data-section-header', name);
  div.setAttribute('ondragover', '_navSectionDragOver(event,\''+ _attr(name) +'\')');
  div.setAttribute('ondragleave', '_navSectionDragLeave(event)');
  div.setAttribute('ondrop', '_navSectionDrop(event,\''+ _attr(name) +'\')');
  div.style.cssText = 'font-size:10px;font-weight:700;text-transform:uppercase;color:var(--gray-text);padding:10px 6px 4px;display:flex;align-items:center;justify-content:space-between;border-radius:6px;transition:background .1s';
  div.innerHTML =
      '<span ondblclick="_navRenameSection(\''+ _attr(name) +'\',this)" title="Double-cliquez pour renommer" style="cursor:text;flex:1">'+ _escPrefs(name) +'</span>'
    + '<span style="font-size:10px;color:var(--gray-text);font-weight:600;opacity:.7">déposer ici</span>';
  list.appendChild(div);
  if (typeof toast === 'function') toast('Section créée. Glissez-y des items.', 'success');
}

// ── Drag sur un bandeau de section : pour DÉPOSER un item dans cette section ──
function _navSectionDragOver(ev, sectionKey) {
  ev.preventDefault();
  if (!_navDragPage) return;
  try { ev.dataTransfer.dropEffect = 'move'; } catch(_) {}
  ev.currentTarget.style.background = 'var(--blue-pale,#eaf2ff)';
}
function _navSectionDragLeave(ev) {
  if (ev.currentTarget && ev.currentTarget.style) ev.currentTarget.style.background = '';
}
function _navSectionDrop(ev, sectionKey) {
  ev.preventDefault();
  if (ev.currentTarget && ev.currentTarget.style) ev.currentTarget.style.background = '';
  if (!_navDragPage) { _navDragEnd(ev); return; }
  var list = document.getElementById('navOrderList');
  var srcRow = document.getElementById('row_'+_navDragPage);
  if (!list || !srcRow) { _navDragEnd(ev); return; }
  // Insérer la ligne JUSTE APRÈS le bandeau de section
  var sectionHeader = ev.currentTarget;
  list.insertBefore(srcRow, sectionHeader.nextSibling);
  srcRow.setAttribute('data-section', sectionKey);
  _navDragEnd(ev);
}

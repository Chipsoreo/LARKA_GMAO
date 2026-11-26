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
  { page:'dashboard',     label:'Tableau de bord', icon:lk('chart-column'), section:'Principal'    },
  { page:'biens',         label:'Biens',           icon:lk('building-complex'), section:'Inventaire'   },
  { page:'equipements',   label:'Equipements',     icon:lk('settings'), section:'Inventaire'   },
  { page:'stock',         label:'Stock',           icon:lk('package'), section:'Inventaire'   },
  // « Bâtiments » : section dépliable (js/nav.js). Les trois dernières entrées
  // sont celles de Larka Connect, affichées seulement quand le module est ouvert.
  { page:'plans',         label:'Plans',           icon:lk('map'), section:'Bâtiments'    },
  { page:'connect',       label:'Supervision',     icon:lk('satellite-dish'), section:'Bâtiments'    },
  { page:'connect_alarmes', label:'Alarmes techniques', icon:lk('bell'), section:'Bâtiments' },
  { page:'connect_config',  label:'Connexions',      icon:lk('plug'), section:'Bâtiments'    },
  { page:'interventions', label:'Interventions',   icon:lk('wrench'), section:'Maintenance'  },
  { page:'contrats',      label:'Contrats',        icon:lk('clipboard-list'), section:'Maintenance'  },
  // « Maintenance » : section dépliable elle aussi (js/nav.js).
  { page:'preventif',     label:'Préventif',       icon:lk('calendar-days'), section:'Maintenance'  },
  { page:'controles',     label:'Contrôle réglementaire', icon:lk('circle-check', 'ok'), section:'Maintenance' },
  { page:'gestion_materiel', label:'Gestion matériel', icon:lk('wallet'), section:'Gestion'      },
  { page:'historique',    label:'Historique',      icon:lk('archive'), section:'Gestion'      },
  { page:'demandes',      label:'Demandes',        icon:lk('file-pen-line'), section:'Gestion'      },
  { page:'energie',          label:'Energie',          icon:lk('zap'), section:'Fluides'      },
  { page:'carbone',          label:'Bilan Carbone',    icon:lk('leaf'), section:'Fluides'      },
  { page:'mobilite_carbone', label:'Ma mobilité',      icon:lk('car'), section:'Fluides'      },
  { page:'urgences',         label:'Procédures d\'urgence', icon:lk('siren'), section:'Sécurité'    },
  { page:'archives',         label:'Archives',         icon:lk('archive'), section:'Archives'     },
  { page:'statsAvancees',    label:'Stats avancees',   icon:lk('trending-up'), section:'Analyse'      },
  { page:'legifrance',       label:'Légifrance',       icon:lk('scroll-text'), section:'République'   },
  { page:'chorus',           label:'Chorus Pro',       icon:lk('landmark'), section:'République'   },
];

/**
 * Clés dont le contenu DÉPEND DU RÔLE, et qui doivent donc être cloisonnées.
 *
 * ⚠️ TOUT ÉTAIT STOCKÉ SOUS UNE SEULE CLÉ, « gmao_ui_prefs ».
 *
 * L'ordre des onglets, leur visibilité et leur section décrivent une barre
 * précise. Or celle d'un gestionnaire et celle d'un demandeur n'ont ni les
 * mêmes entrées ni les mêmes clés. Quand les deux partagent un navigateur — ou
 * qu'un même compte change de rôle — le second écrasait l'ordre du premier avec
 * sa propre liste, plus courte : les onglets manquants repartaient en vrac à la
 * fin, et les sections se perdaient.
 *
 * Ce qui ne dépend pas du rôle — position de la barre, couleurs, densité,
 * affichage des notes — reste commun : on ne veut pas que régler sa couleur
 * deux fois soit le prix de la correction.
 */
var UI_PREFS_PAR_ROLE = ['navOrder', 'navVisibility', 'itemSections', 'sectionRenames', 'sectionsDeroulantes'];

/** Rôle courant, ou 'anonyme' avant connexion. */
function _uiPrefsRole() {
  try {
    // « typeof » et non « window.App » : ce fichier est chargé avant que App
    // n'existe, et l'épreuve des liaisons globales le signale à juste titre —
    // un accès direct lèverait une ReferenceError au premier appel.
    return (typeof App !== 'undefined' && App && App.currentUser
            && App.currentUser.Role) || 'anonyme';
  } catch (_) { return 'anonyme'; }
}

function uiPrefsGet() {
  try {
    var p = JSON.parse(localStorage.getItem(UI_PREFS_KEY)) || {};

    // Les clés cloisonnées vivent sous « parRole[<rôle>] ». On les remonte au
    // niveau attendu par le reste du fichier, qui n'a pas à connaître ce
    // découpage.
    var role = _uiPrefsRole();

    // ── Reprise de l'ancienne forme ──────────────────────────────────────
    // Avant le cloisonnement, ces clés vivaient à la racine. On les attribue
    // au rôle courant la première fois qu'il ouvre l'application : sans cela
    // chacun retrouverait une barre par défaut et croirait ses réglages perdus.
    // Le rôle qui se connecte en premier hérite — c'est arbitraire, mais c'est
    // aussi le plus probable : dans la majorité des cas, un navigateur n'a servi
    // qu'à une personne.
    if (!p.parRole && UI_PREFS_PAR_ROLE.some(function (k) { return p[k] !== undefined; })) {
      p.parRole = {};
      p.parRole[role] = {};
      UI_PREFS_PAR_ROLE.forEach(function (k) {
        if (p[k] !== undefined) { p.parRole[role][k] = p[k]; delete p[k]; }
      });
      try { localStorage.setItem(UI_PREFS_KEY, JSON.stringify(p)); } catch (_) {}
    }

    var parRole = (p.parRole && p.parRole[role]) || {};
    UI_PREFS_PAR_ROLE.forEach(function (k) {
      if (parRole[k] !== undefined) p[k] = parRole[k];
      else delete p[k];       // rien pour ce rôle : ordre par défaut, pas celui d'un autre
    });
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
  var role = _uiPrefsRole();
  var brut = {};
  try { brut = JSON.parse(localStorage.getItem(UI_PREFS_KEY)) || {}; } catch (_) {}

  brut.parRole = brut.parRole || {};
  brut.parRole[role] = brut.parRole[role] || {};

  Object.keys(patch).forEach(function (k) {
    if (UI_PREFS_PAR_ROLE.indexOf(k) !== -1) {
      // Cloisonné : n'écrase que la barre de CE rôle.
      if (patch[k] === undefined) delete brut.parRole[role][k];
      else brut.parRole[role][k] = patch[k];
      delete brut[k];          // on retire l'ancienne valeur commune
    } else {
      brut[k] = patch[k];      // commun à tous les rôles
    }
  });

  localStorage.setItem(UI_PREFS_KEY, JSON.stringify(brut));
  return uiPrefsGet();
}


// ── Ordre des items du menu ──────────────────────────────────────────────────
// Retourne la liste UI_NAV_ITEMS dans l'ordre voulu par l'utilisateur :
//  - en s'appuyant sur uiPrefs.navOrder (tableau de pages mémorisé)
//  - les nouvelles pages non présentes dans navOrder sont rajoutées en fin
//    (utile quand on ajoute un module : il n'est pas perdu)
//  - une page de navOrder qui n'existe plus est ignorée
//  - chaque item peut être réaffecté à une autre section via uiPrefs.itemSections
/**
 * Entrées apportées par les modules, à traiter comme les autres.
 *
 * ⚠️ ON LES LIT DANS LA BARRE, ON NE LES RECALCULE PLUS.
 *
 * La première version reconstruisait la clé de chaque page à partir de
 * l'identifiant du module et de l'ordre de ses pages. Deux calculs pour une
 * même clé, à deux endroits : dès que l'un changeait — un rôle qui filtre les
 * pages, un ordre différent — les clés divergeaient et l'entrée apparaissait
 * DEUX FOIS, dont une sans libellé ni icône, puisque rien ne lui correspondait.
 *
 * La barre affichée porte déjà la bonne clé, le bon libellé et la bonne icône :
 * c'est elle qui fait foi. Une seule source, donc plus de divergence possible.
 */
/**
 * Entrées lues dans la barre, quelle qu'en soit l'origine.
 *
 * @param {string} selecteur  restreint aux entrées de module si besoin
 */
/**
 * Entrées que la barre écrit EN DUR, hors de la liste réordonnable.
 *
 * ⚠️ LES LISTER DANS LE PANNEAU LES DUPLIQUAIT DANS LA BARRE.
 * nav.js ajoute la section « Administration » après avoir rendu les entrées
 * configurables. En la reprenant dans le panneau, on l'enregistrait dans
 * « navOrder » : elle était alors rendue une première fois avec les autres,
 * puis une seconde par le bloc en dur. D'où deux « Administration », deux
 * « Configuration », deux « Extensions ».
 *
 * Ces entrées ne se réordonnent pas : elles restent en bas, c'est leur place.
 */
var UI_PAGES_FIXES = ['configuration', 'extensions', 'journal', 'superadmin'];

function _navItemsDeLaBarre(selecteur) {
  var out = [];
  document.querySelectorAll(selecteur).forEach(function (n) {
    var page = n.dataset.page;
    if (!page || UI_PAGES_FIXES.indexOf(page) !== -1) return;
    // L'icône est une balise (lk(), css/lucide.css) : on garde son HTML.
    var icone = ((n.querySelector('.icon') || {}).innerHTML || '').trim();
    var label = (n.querySelector('.nav-label') || {}).textContent || page;
    // La section est celle du titre qui précède l'entrée dans la barre : c'est
    // le regroupement que l'utilisateur a sous les yeux.
    var titre = n.previousElementSibling;
    while (titre && !titre.classList.contains('nav-section-title')) {
      titre = titre.previousElementSibling;
    }
    out.push({
      page: page,
      icon: (icone || '•').trim(),
      label: label.trim(),
      // data-libelle : nom d'une section déroulante, dont le texte porte aussi icône et chevron.
      // data-section : la CLÉ de la section (celle des préférences), même renommée.
      section: titre ? (titre.dataset.section || titre.dataset.libelle || titre.textContent).trim() : 'Principal',
    });
  });
  return out;
}

function _navItemsExtensions() {
  var out = [];
  document.querySelectorAll('#sidebarNav [data-ext-nav][data-page]').forEach(function (n) {
    var page = n.dataset.page;
    if (!page) return;
    var icone = ((n.querySelector('.icon') || {}).innerHTML || '').trim() || lk('puzzle');
    var label = (n.querySelector('.nav-label') || {}).textContent || page;
    out.push({
      page: page,
      icon: icone.trim(),
      label: label.trim(),
      section: 'Modules communautaires',
    });
  });
  return out;
}

function getOrderedNavItems() {
  var prefs = uiPrefsGet();
  var stored = prefs.navOrder;
  var itemSections = prefs.itemSections || {};
  // Override de section : on clone l'item pour ne pas muter UI_NAV_ITEMS
  function withSection(n) {
    var s = itemSections[n.page];
    return s ? Object.assign({}, n, { section: s }) : n;
  }
  // Dédoublonnage à la source : un module dont l'entrée porte la même clé
  // qu'un écran du cœur, ou listé deux fois, ne doit produire qu'une ligne.
  /**
   * ⚠️ « UI_NAV_ITEMS » NE DÉCRIT QUE LA BARRE DES GESTIONNAIRES.
   *
   * Celle d'un demandeur est construite ailleurs, avec ses propres clés et ses
   * propres libellés : « mobilite » là où la liste dit « mobilite_carbone ».
   * Le panneau affichait donc des noms qui ne correspondaient à rien de ce
   * qu'il voyait, et des réglages sans effet.
   *
   * La barre réellement affichée fait foi. On ne garde « UI_NAV_ITEMS » que
   * pour les entrées qu'elle décrit mieux — libellés soignés, sections
   * pensées — et la barre comble le reste.
   */
  var deLaBarre = _navItemsDeLaBarre('#sidebarNav [data-page]');
  var parPage = {};
  UI_NAV_ITEMS.forEach(function (n) { parPage[n.page] = n; });

  var TOUS = [];
  var dejaVu = {};
  (deLaBarre.length ? deLaBarre : UI_NAV_ITEMS).forEach(function (n) {
    if (!n || !n.page || dejaVu[n.page]) return;
    dejaVu[n.page] = true;

    // ⚠️ ON PRENAIT L'ENTRÉE DE « UI_NAV_ITEMS » EN ENTIER, SECTION COMPRISE.
    //
    // Un demandeur voyait donc « Demandes » rangé dans GESTION, « Ma mobilité »
    // dans FLUIDES et « Historique » dans SÉCURITÉ — les sections de la barre
    // d'un gestionnaire. Sa propre barre dit « Mes demandes », « Mon empreinte »,
    // « Sécurité ». Le panneau décrivait une navigation qui n'était pas la
    // sienne.
    //
    // La barre affichée fait foi pour le libellé ET la section : ce sont les
    // mots qu'il a sous les yeux. On ne complète depuis UI_NAV_ITEMS que l'icône
    // lorsqu'elle manque, car elle, au moins, ne dépend pas du rôle.
    var connu = parPage[n.page];
    TOUS.push({
      page: n.page,
      label: n.label,
      icon: n.icon && n.icon !== '•' ? n.icon : ((connu && connu.icon) || '•'),
      section: n.section,
    });
  });
  // ⚠️ UNE ENTRÉE MASQUÉE DISPARAISSAIT POUR DE BON.
  //
  // La liste est lue dans la barre affichée. Or la barre n'affiche pas ce que
  // l'utilisateur a masqué (ou ce que l'hébergeur a coupé) : Biens,
  // Équipements et Stock, masqués une fois, sortaient donc de la liste — de la
  // barre ET du panneau de préférences. Plus aucune case pour les réafficher,
  // et le réglage « masqué », lui, restait enregistré. Ils ne revenaient plus.
  //
  // Pour les rôles dont la barre est décrite par UI_NAV_ITEMS (tous sauf le
  // demandeur, qui a la sienne), on complète donc avec les entrées du cœur
  // absentes de la barre, à leur place dans leur section. Leur affichage reste
  // décidé par showNav() (préférence, modules coupés) : on ne force rien, on
  // rend seulement la case à nouveau accessible.
  var _role = (typeof App !== 'undefined' && App.currentUser && App.currentUser.Role) || '';
  if (_role && _role !== 'Demandeur') {
    UI_NAV_ITEMS.forEach(function (n) {
      if (!n || !n.page || dejaVu[n.page] || UI_PAGES_FIXES.indexOf(n.page) !== -1) return;
      dejaVu[n.page] = true;
      var item = { page: n.page, label: n.label, icon: n.icon || '•', section: n.section };
      var pos = -1;
      for (var i = TOUS.length - 1; i >= 0; i--) { if (TOUS[i].section === n.section) { pos = i; break; } }
      if (pos === -1) {
        // Section absente de la barre : on la place selon l'ordre de UI_NAV_ITEMS.
        var rang = UI_NAV_ITEMS.indexOf(n);
        pos = TOUS.length - 1;
        for (var j = 0; j < TOUS.length; j++) {
          var r = UI_NAV_ITEMS.findIndex(function (u) { return u.page === TOUS[j].page; });
          if (r > rang) { pos = j - 1; break; }
        }
      }
      TOUS.splice(pos + 1, 0, item);
    });
  }
  // ⚠️ ET LES AUTRES ENTRÉES MASQUÉES, ELLES, DISPARAISSAIENT TOUJOURS.
  // Le complément ci-dessus ne vaut que pour la barre décrite par UI_NAV_ITEMS.
  // Une entrée de module, ou une entrée de la barre d'un demandeur, décochée
  // puis la page rechargée, n'était plus nulle part : ni dans la barre, ni dans
  // le panneau. La barre note maintenant ce qu'elle a laissé de côté à la
  // demande de l'utilisateur (App._navMasquees, js/nav.js et js/extensions.js) ;
  // on le remet ici, à la fin de sa section.
  var masquees = (typeof App !== 'undefined' && Array.isArray(App._navMasquees)) ? App._navMasquees : [];
  masquees.forEach(function (n) {
    if (!n || !n.page || dejaVu[n.page] || UI_PAGES_FIXES.indexOf(n.page) !== -1) return;
    dejaVu[n.page] = true;
    var connu = parPage[n.page];
    var item = { page: n.page, label: n.label || (connu && connu.label) || n.page,
                 icon: n.icon || (connu && connu.icon) || '•', section: n.section || (connu && connu.section) || 'Principal' };
    var pos = -1;
    for (var i = TOUS.length - 1; i >= 0; i--) { if (TOUS[i].section === item.section) { pos = i; break; } }
    if (pos === -1) TOUS.push(item); else TOUS.splice(pos + 1, 0, item);
  });
  if (!Array.isArray(stored) || !stored.length) return TOUS.map(withSection);
  var byPage = {};
  TOUS.forEach(function(n) { byPage[n.page] = n; });
  var seen = {};
  var out = [];
  stored.forEach(function(page) {
    if (byPage[page] && !seen[page]) { out.push(withSection(byPage[page])); seen[page] = true; }
  });
  TOUS.forEach(function(n) { if (!seen[n.page]) out.push(withSection(n)); });
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
  var orig = UI_NAV_ITEMS.concat(_navItemsExtensions())
    .find(function(n) { return n.page === page; });
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
  if (_isHoriz(pos)) return;          // barre horizontale : hauteur fixe
  uiPrefsSet({ sidebarSize: size });
  var sb = document.querySelector('.sidebar');
  if (sb) {
    sb.style.transition = 'width .22s cubic-bezier(.4,0,.2,1), min-width .22s, height .22s, min-height .22s';
    _setSize(size, pos);
    setTimeout(function() { if(sb) sb.style.transition = ''; }, 250);
  }
  // Le bouton de la barre (◀ / ▶) et les deux boutons du panneau suivent.
  var reduite = size <= SIDEBAR_ICON_W + 4;
  var btn = document.getElementById('sidebarCollapseBtn');
  if (btn) btn.textContent = reduite ? '▶' : '◀';
  [['etendu', !reduite], ['icones', reduite]].forEach(function (b) {
    var el = document.getElementById('taille_btn_' + b[0]);
    if (!el) return;
    el.style.border = b[1] ? '2px solid var(--blue)' : '1.5px solid var(--gray-border)';
    el.style.background = b[1] ? 'var(--blue-pale)' : 'var(--white)';
    el.style.color = b[1] ? 'var(--blue)' : 'var(--text)';
  });
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
  h += ('<span style="font-size:14px;font-weight:700;color:var(--navy)">' + lk('palette') + ' Préférences interface</span>');
  h += '<button onclick="closeUiPrefsPanel()" style="background:var(--gray-bg);border:none;border-radius:6px;width:26px;height:26px;cursor:pointer;font-size:15px;color:var(--gray-text)">✕</button>';
  h += '</div>';

  // Position
  var positions = [{id:'left',icon:lk('arrow-left'),label:'Gauche'},{id:'top',icon:lk('arrow-up'),label:'Haut'},{id:'right',icon:lk('arrow-right'),label:'Droite'},{id:'bottom',icon:lk('arrow-down'),label:'Bas'}];

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
  // ⚠️ AUCUN DES DEUX BOUTONS NE DISAIT LEQUEL ÉTAIT CHOISI, et en barre
  // horizontale (haut, bas) ils ne faisaient rien sans le dire. Le choix en
  // cours est marqué comme pour la position, et les boutons sont grisés là où
  // la taille ne s'applique pas.
  var reduite = (prefs.sidebarSize || SIDEBAR_FULL_DEF) <= SIDEBAR_ICON_W + 4;
  var horiz = _isHoriz(pos);
  [['etendu', SIDEBAR_FULL_DEF, '▐█ Étendu', !reduite], ['icones', SIDEBAR_ICON_W, '▐ Icônes', reduite]].forEach(function (b) {
    var a = b[3] && !horiz;
    h += '<button id="taille_btn_' + b[0] + '" onclick="_quickResize(' + b[1] + ')"' + (horiz ? ' disabled title="La barre en haut ou en bas a toujours la même hauteur"' : '')
      + ' style="flex:1;padding:8px 0;border-radius:8px;border:' + (a ? '2px solid var(--blue)' : '1.5px solid var(--gray-border)') + ';background:' + (a ? 'var(--blue-pale)' : 'var(--white)')
      + ';cursor:' + (horiz ? 'not-allowed;opacity:.5' : 'pointer') + ';font-size:12px;font-weight:600;color:' + (a ? 'var(--blue)' : 'var(--text)') + '">' + b[2] + '</button>';
  });
  h += '</div>';
  h += ('<div style="font-size:11px;color:var(--gray-text);margin-top:6px;text-align:center">' + lk('lightbulb') + ' Glissez le bord de la sidebar · Double-clic pour basculer</div>');
  h += '</div></div>'; // fin position

  // Style des icônes (Lucide, js/icones.js) : commun à tous les rôles.
  if (typeof LarkaIcones !== 'undefined' && LarkaIcones.styles) {
    var styleIc = LarkaIcones.styleChoisi();
    var apercu = ['zap', 'droplet', 'wrench', 'leaf', 'building', 'calendar'].map(function (n) { return lk(n); }).join('');
    var stylesIc = [{ id: 'simple', label: 'Simples' }, { id: 'couleur', label: 'Colorées' }, { id: 'duotone', label: 'Duotone' }];
    h += '<div style="padding:14px 18px;border-bottom:1px solid var(--gray-border)">';
    h += '<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--gray-text);margin-bottom:10px">Icônes</div>';
    h += '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px">';
    stylesIc.forEach(function (s) {
      var a = styleIc === s.id;
      h += '<button id="icStyle_' + s.id + '" onclick="_setIconesStyle(\'' + s.id + '\')" data-icones="' + s.id + '" style="padding:10px 4px;border-radius:10px;border:2px solid ' + (a ? 'var(--blue)' : 'var(--gray-border)') + ';background:' + (a ? 'var(--blue-pale)' : 'var(--white)') + ';cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:6px;transition:all .15s">';
      h += '<span style="font-size:15px;color:var(--navy);display:grid;grid-template-columns:repeat(3,auto);gap:4px 6px">' + apercu + '</span>';
      h += '<span style="font-size:11px;font-weight:700;color:' + (a ? 'var(--blue)' : 'var(--gray-text)') + '">' + s.label + '</span>';
      h += '</button>';
    });
    h += '</div></div>';
  }

  // Onglets visibles + ORDRE + SECTIONS PERSO — masqué pour les demandeurs
  /**
   * ⚠️ CE BLOC ÉTAIT REFUSÉ AUX DEMANDEURS.
   *
   * Ils ne pouvaient donc ni masquer un onglet, ni le déplacer, ni le ranger
   * ailleurs — alors qu'ils ont eux aussi une barre, plus courte mais tout
   * aussi personnelle. On leur réservait le choix de la position et des
   * couleurs, et on leur retirait le seul réglage qui touche leur travail.
   *
   * On l'ouvre, en ne listant que les entrées qu'ils ONT vraiment : proposer de
   * masquer « Stock » à quelqu'un qui n'y a pas accès serait un réglage sans
   * effet, et un réglage sans effet finit par faire douter de tous les autres.
   */
  {
    h += '<div style="padding:14px 18px">';
    h += '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">';
    h += '<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--gray-text)">Onglets, ordre &amp; sections</div>';
    h += '<div style="font-size:11px;color:var(--gray-text)" id="navVisCounter">—</div>';
    h += '</div>';
    h += '<div style="font-size:11px;color:var(--gray-text);margin-bottom:10px;line-height:1.4">Cochez pour afficher. Glissez <span style="color:var(--blue);font-weight:700">⠿</span> pour réordonner ou changer de section. Cliquez sur un nom de section pour le renommer.</div>';

    var ordered = (typeof getOrderedNavItems === 'function') ? getOrderedNavItems() : UI_NAV_ITEMS.slice();
    // La barre affichée fait foi : elle a déjà appliqué les rôles, les droits
    // de lecture et les modules désactivés par le tenant. La recomposer ici
    // reviendrait à réimplémenter cette règle, avec le risque d'en diverger.
    var presentes = {};
    document.querySelectorAll('#sidebarNav [data-page]').forEach(function (n) {
      presentes[n.dataset.page] = true;
    });
    // Les entrées que l'utilisateur a masquées ne sont pas dans la barre : sans
    // elles, leur case disparaissait du panneau dès le rechargement suivant, et
    // plus rien ne permettait de les réafficher.
    ((typeof App !== 'undefined' && App._navMasquees) || []).forEach(function (n) { presentes[n.page] = true; });
    if (Object.keys(presentes).length) {
      ordered = ordered.filter(function (n) { return presentes[n.page]; });
    }
    var renderedSections = {};
    h += '<div id="navOrderList" ondragover="event.preventDefault()" style="display:flex;flex-direction:column;gap:0">';
    ordered.forEach(function(n) {
      if (!renderedSections[n.section]) {
        renderedSections[n.section] = true;
        var label = (typeof getSectionLabel === 'function') ? getSectionLabel(n.section) : n.section;
        // Bandeau de section éditable et droppable (déposer ici = changer la section de l'item)
        h += '<div data-section-header="'+_attr(n.section)+'" data-deroulante="'+((typeof _navEstDeroulante === 'function' && _navEstDeroulante(n.section)) ? '1' : '0')+'"'
          +  ' ondragover="_navSectionDragOver(event,'+jsArg(n.section)+')"'
          +  ' ondragleave="_navSectionDragLeave(event)"'
          +  ' ondrop="_navSectionDrop(event,'+jsArg(n.section)+')"'
          +  ' style="font-size:10px;font-weight:700;text-transform:uppercase;color:var(--gray-text);padding:10px 6px 4px;display:flex;align-items:center;justify-content:space-between;border-radius:6px;transition:background .1s">';
        h += '<span ondblclick="_navRenameSection('+jsArg(n.section)+',this)" title="Double-cliquez pour renommer" style="cursor:text;flex:1">'+_escPrefs(label)+'</span>';
        h += '<span style="display:flex;gap:4px;align-items:center">';
        h += _navDeroulanteBoutonHtml(n.section);
        h += '<button onclick="_toggleNavSection('+jsArg(n.section)+',this)" style="background:transparent;border:none;color:var(--blue);font-size:10px;font-weight:700;cursor:pointer;padding:0 4px">tout</button>';
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
      // « data-page » : l'enregistrement lit les cases AFFICHÉES plutôt qu'une
      // liste écrite à la main, sans quoi les entrées des modules sont ignorées.
      // « Nouvelle demande » d'un demandeur ne se masque pas : sa case est
      // cochée et grisée plutôt qu'offerte sans effet.
      var fixe = isDemandeur && n.page === 'demandes';
      h += '<input type="checkbox" data-page="'+n.page+'" id="uipref_'+n.page+'" '+(v||fixe?'checked':'')+(fixe?' disabled title="Toujours affiché"':'')+' onchange="_onNavChipChange(this,\''+n.page+'\')" style="width:16px;height:16px;accent-color:var(--blue);cursor:'+(fixe?'not-allowed':'pointer')+';flex-shrink:0">';
      h += '<span style="font-size:14px;width:20px;text-align:center">'+n.icon+'</span>';
      h += '<span style="font-size:13px;color:var(--text);flex:1">'+n.label+'</span>';
      h += '</div>';
    });
    h += '</div>';

    // Bouton pour créer une nouvelle section
    h += '<button onclick="_navCreateSection()" style="width:100%;margin-top:8px;padding:8px;border-radius:8px;border:1px dashed var(--gray-border);background:transparent;color:var(--blue);font-size:12px;font-weight:600;cursor:pointer">＋ Nouvelle section</button>';

    h += '<div style="display:flex;gap:8px;margin-top:12px">';
    h += '<button onclick="_resetNavVis()" title="Tout afficher et restaurer l\'ordre par défaut" style="flex:1;padding:8px;border-radius:8px;border:1px solid var(--gray-border);background:var(--white);font-size:12px;font-weight:600;cursor:pointer;color:var(--gray-text)">↺ Réinitialiser</button>';
    h += ('<button onclick="_saveNavVis()" style="flex:1;padding:8px;border-radius:8px;border:none;background:var(--blue);color:white;font-size:12px;font-weight:600;cursor:pointer">' + lk('save') + ' Enregistrer</button>');
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
    h += ('<span style="font-size:18px">' + lk('megaphone') + '</span>');
    h += '<span style="font-size:11px;font-weight:700;color:'+(isDef?'var(--blue)':'var(--gray-text)')+'">Bandeau défilant</span>';
    h += '</button>';
    h += '<button onclick="_setNotesMode(\'fixe\')" id="notesMode_fixe" style="flex:1;padding:10px 8px;border-radius:10px;border:2px solid '+(isFix?'var(--blue)':'var(--gray-border)')+';background:'+(isFix?'var(--blue-pale)':'var(--white)')+';cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:4px;transition:all .15s">';
    h += ('<span style="font-size:18px">' + lk('pin') + '</span>');
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
    h += ('<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--gray-text);margin-bottom:10px">' + lk('bot') + ' Assistant IA</div>');
    h += '<label style="display:flex;align-items:center;gap:10px;cursor:pointer;padding:6px 0">';
    h += '<input type="checkbox" id="assistantHlCheckbox" '+(hlOn?'checked':'')+' onchange="_toggleAssistantHighlight(this)" style="width:16px;height:16px;accent-color:var(--blue);cursor:pointer">';
    h += '<span style="flex:1">';
    h += ('<span style="font-size:13px;color:var(--text);font-weight:600">' + lk('target') + ' Surbrillance des éléments sur le plan</span>');
    h += '<div style="font-size:11px;color:var(--gray-text);margin-top:2px;line-height:1.4">Quand l\'IA vous montre des éléments sur un plan, ils sont mis en surbrillance avec une pulsation et un halo doré.</div>';
    h += '</span>';
    h += '</label>';
    h += '</div>';
  }

  // ── Thème et langue : choix PERSONNEL parmi les modules installés ───────
  //
  // La section n'apparaît que si un module d'habillage ou de langue est
  // installé : proposer un choix vide serait une promesse creuse.
  try {
    var themes = (typeof LarkaExtensions !== 'undefined'
                  && LarkaExtensions.themesDisponibles) ? LarkaExtensions.themesDisponibles() : [];
    var langues = (typeof LarkaExtensions !== 'undefined'
                  && LarkaExtensions.languesDisponibles) ? LarkaExtensions.languesDisponibles() : [];

    if (themes.length || langues.length) {
      h += '<div style="padding:14px 18px;border-bottom:1px solid var(--gray-border)">';
      h += '<div style="font-size:11px;font-weight:700;text-transform:uppercase;'
         + 'letter-spacing:.6px;color:var(--gray-text);margin-bottom:10px">'
         + (lk('puzzle') + ' Modules d\'habillage</div>');

      if (themes.length) {
        h += '<div style="display:flex;align-items:center;justify-content:space-between;'
           + 'gap:10px;margin-bottom:8px"><span style="font-size:12px;color:var(--gray-text)">'
           + 'Thème</span><span style="flex:0 0 150px">'
           + '<select onchange="uiPrefsSet({theme_module:this.value});'
           + 'LarkaExtensions.appliquerHabillage()" '
           + 'style="width:100%;box-sizing:border-box;font-size:12px;padding:4px 6px;'
           + 'border:1px solid var(--gray-border);border-radius:6px;background:var(--white);'
           + 'color:var(--navy)">'
           + '<option value="">Aucun (thème Larka)</option>'
           + themes.map(function (t) {
               return '<option value="' + t.identifiant + '"'
                    + (prefs.theme_module === t.identifiant ? ' selected' : '')
                    + '>' + t.nom + '</option>';
             }).join('')
           + '</select></span></div>';
      }

      if (langues.length) {
        h += '<div style="display:flex;align-items:center;justify-content:space-between;'
           + 'gap:10px;margin-bottom:8px"><span style="font-size:12px;color:var(--gray-text)">'
           + 'Langue</span><span style="flex:0 0 150px">'
           + '<select onchange="uiPrefsSet({langue:this.value});location.reload()" '
           + 'style="width:100%;box-sizing:border-box;font-size:12px;padding:4px 6px;'
           + 'border:1px solid var(--gray-border);border-radius:6px;background:var(--white);'
           + 'color:var(--navy)">'
           + '<option value="fr">Français</option>'
           + langues.map(function (l) {
               return '<option value="' + l.code + '"'
                    + (prefs.langue === l.code ? ' selected' : '')
                    + '>' + l.code + ' — ' + l.nom + '</option>';
             }).join('')
           + '</select></span></div>';
      }

      h += '<div style="font-size:11px;color:var(--gray-text);margin-top:4px">'
         + 'Ces choix ne concernent que vous.</div>';
      h += '</div>';
    }
  } catch (e) { /* couche extensions absente : pas de section */ }

  panel.innerHTML = h;
  document.body.appendChild(panel);
  if (typeof _updateNavVisCounter === 'function') _updateNavVisCounter();
  setTimeout(function() { document.addEventListener('click', _closeOut); }, 50);
}

/** Toggle préférence surbrillance assistant. Stocke dans localStorage. */
function _toggleAssistantHighlight(cb) {
  localStorage.setItem('assistant_highlight_enabled', cb.checked ? 'true' : 'false');
}

/**
 * Réaffiche la page courante après un changement d'apparence.
 * Un réglage qui ne se voit qu'au rechargement laisse croire qu'il n'a pas
 * été pris en compte.
 */
function uiPrefsRafraichir() {
  try {
    if (typeof navigate === 'function' && App && App.currentPage) navigate(App.currentPage);
  } catch (e) { /* la page se rafraîchira au prochain passage */ }
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

/** Style des icônes : simple (couleur du texte), couleur (par famille), duotone. */
function _setIconesStyle(style) {
  uiPrefsSet({ icones: style });
  if (typeof LarkaIcones !== 'undefined') LarkaIcones.appliquerStyle(style);
  ['simple', 'couleur', 'duotone'].forEach(function (m) {
    var btn = document.getElementById('icStyle_' + m);
    if (!btn) return;
    var a = m === style;
    btn.style.borderColor = a ? 'var(--blue)' : 'var(--gray-border)';
    btn.style.background  = a ? 'var(--blue-pale)' : 'var(--white)';
    var lbl = btn.querySelector('span:last-child');
    if (lbl) lbl.style.color = a ? 'var(--blue)' : 'var(--gray-text)';
  });
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
  // Les modules comptent : afficher « 12 sur 15 » alors que dix-huit entrées
  // sont listées ferait douter du reste du panneau.
  // On compte les cases RÉELLEMENT affichées, au lieu de parcourir une liste
  // écrite à la main : le panneau ne montre que les entrées de l'utilisateur,
  // et un compteur « 12 / 15 » sous une liste de sept ferait douter du reste.
  var boites = document.querySelectorAll('#navOrderList input[type="checkbox"]');
  var total = boites.length;
  var checked = 0;
  boites.forEach(function (el) { if (el.checked) checked++; });
  counter.textContent = checked + ' / ' + total + ' visibles';
}

function _toggleNavSection(sec, btn) {
  // Si tous cochés -> tout décocher, sinon tout cocher.
  // Les lignes AFFICHÉES de la section (data-section), et non UI_NAV_ITEMS :
  // les entrées des modules et celles d'un demandeur n'y figurent pas, et
  // « tout » les laissait de côté.
  var boites = [];
  document.querySelectorAll('#navOrderList [data-section]').forEach(function (row) {
    if (row.getAttribute('data-section') !== sec) return;
    var cb = row.querySelector('input[type="checkbox"][data-page]');
    if (cb) boites.push(cb);
  });
  var allChecked = boites.length && boites.every(function (el) { return el.checked; });
  boites.forEach(function (el) { el.checked = !allChecked; });
  _updateNavVisCounter();
}

/** Bascule « liste déroulante » d'une section, dans le panneau (enregistrée avec le reste). */
function _toggleNavDeroulante(sec, btn) {
  var entete = btn && btn.closest('[data-section-header]');
  if (!entete) return;
  var on = entete.getAttribute('data-deroulante') !== '1';
  entete.setAttribute('data-deroulante', on ? '1' : '0');
  btn.classList.toggle('actif', on);
  btn.setAttribute('aria-pressed', on ? 'true' : 'false');
  btn.title = on ? 'Liste déroulante : cliquez pour revenir à une section simple' : 'Regrouper ces onglets dans une liste déroulante';
}
function _navDeroulanteBoutonHtml(sec) {
  var on = (typeof _navEstDeroulante === 'function') ? _navEstDeroulante(sec) : false;
  return '<button type="button" class="nav-pref-deroulante' + (on ? ' actif' : '') + '" aria-pressed="' + (on ? 'true' : 'false') + '"'
    + ' onclick="_toggleNavDeroulante(' + jsArg(sec) + ',this)"'
    + ' title="' + (on ? 'Liste déroulante : cliquez pour revenir à une section simple' : 'Regrouper ces onglets dans une liste déroulante') + '">'
    + lk('list-tree') + ' Groupe</button>';
}

function _saveNavVis() {
  var v = {};
  // ⚠️ ON PARCOURAIT « UI_NAV_ITEMS », PAS LES CASES AFFICHÉES.
  // Les entrées apportées par les modules n'y figurent pas : leur case était
  // dessinée, cochée, et son état jeté à l'enregistrement. On lit donc les
  // cases réellement présentes, quelle que soit l'origine de l'entrée.
  document.querySelectorAll('#navOrderList input[type="checkbox"][data-page]')
    .forEach(function (el) { v[el.dataset.page] = el.checked; });

  // Lire l'ordre courant des lignes du DOM (le drag-and-drop a pu les déplacer).
  //
  // ⚠️ DÉDOUBLONNÉ. Sans cela, une ligne présente deux fois dans la liste —
  // pour quelque raison que ce soit — était enregistrée deux fois, puis
  // redessinée deux fois au rendu suivant, puis quatre. Un déplacement
  // multipliait l'entrée au lieu de la bouger.
  var order = [];
  var vus = {};
  var rows = document.querySelectorAll('#navOrderList [data-page]');
  rows.forEach(function (r) {
    var p = r.getAttribute('data-page');
    if (p && !vus[p]) { order.push(p); vus[p] = true; }
  });
  // Lire aussi la section actuelle de chaque item — si elle diffère de la section
  // d'origine définie dans UI_NAV_ITEMS, c'est qu'on l'a réaffectée par drag.
  var itemSections = {};
  rows.forEach(function(r) {
    var page = r.getAttribute('data-page');
    var sec  = r.getAttribute('data-section');
    var orig = UI_NAV_ITEMS.concat(_navItemsExtensions())
    .find(function(n) { return n.page === page; });
    if (orig && sec && sec !== orig.section) itemSections[page] = sec;
  });
  // Sections en liste déroulante : l'état de chaque bandeau du panneau.
  var deroulantes = Object.assign({}, uiPrefsGet().sectionsDeroulantes || {});
  document.querySelectorAll('#navOrderList [data-section-header]').forEach(function (h) {
    var sec = h.getAttribute('data-section-header');
    if (sec && h.hasAttribute('data-deroulante')) deroulantes[sec] = h.getAttribute('data-deroulante') === '1';
  });
  uiPrefsSet({
    navVisibility: v,
    navOrder: order.length ? order : undefined,
    itemSections: itemSections,
    sectionsDeroulantes: deroulantes
  });
  if (window._currentUser) buildSidebarWithVisibility(window._currentUser);
  closeUiPrefsPanel();
  if (typeof toast==='function') toast('Préférences enregistrées','success');
}

function _resetNavVis() {
  // ⚠️ ON PARCOURAIT ENCORE « UI_NAV_ITEMS ».
  // Troisième endroit après l'enregistrement et le compteur : les entrées des
  // modules et celles d'un demandeur n'y figurent pas, leurs cases restaient
  // donc décochées après un « tout réinitialiser ». Un bouton qui réinitialise
  // presque tout est plus trompeur qu'un bouton absent.
  document.querySelectorAll('#navOrderList input[type="checkbox"][data-page]')
    .forEach(function (el) { el.checked = true; });

  // ⚠️ « navVisibility » N'ÉTAIT PAS EFFACÉ.
  // Les cases repassaient à cochées à l'écran, mais la préférence enregistrée
  // gardait les pages masquées : au rechargement suivant, elles disparaissaient
  // de nouveau. On rétablit les quatre clés ensemble.
  uiPrefsSet({
    navOrder: undefined, navVisibility: {},
    itemSections: {}, sectionRenames: {}, sectionsDeroulantes: undefined,
  });
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
// Valeur d'attribut HTML simple (data-*). Pour un argument d'onclick : jsArg().
function _attr(s) { return escHtml(s); }

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
  div.setAttribute('ondragover', '_navSectionDragOver(event,'+ JSON.stringify(String(name)) +')');
  div.setAttribute('ondragleave', '_navSectionDragLeave(event)');
  div.setAttribute('ondrop', '_navSectionDrop(event,'+ JSON.stringify(String(name)) +')');
  div.style.cssText = 'font-size:10px;font-weight:700;text-transform:uppercase;color:var(--gray-text);padding:10px 6px 4px;display:flex;align-items:center;justify-content:space-between;border-radius:6px;transition:background .1s';
  div.setAttribute('data-deroulante', '0');
  div.innerHTML =
      '<span ondblclick="_navRenameSection('+ jsArg(name) +',this)" title="Double-cliquez pour renommer" style="cursor:text;flex:1">'+ _escPrefs(name) +'</span>'
    + '<span style="display:flex;gap:6px;align-items:center">' + _navDeroulanteBoutonHtml(name)
    + '<span style="font-size:10px;color:var(--gray-text);font-weight:600;opacity:.7">déposer ici</span></span>';
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

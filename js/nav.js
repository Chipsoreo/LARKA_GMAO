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
 * Larka — Navigation & Sidebar
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Gère le routage côté client et la construction du menu latéral.
 *
 * CONTENU :
 *   - PAGES{}                    → registre page → {title, render} pour chaque module
 *   - navigate(page)             → change de page, appelle la fonction render
 *   - buildSidebarWithVisibility → construit le menu selon le rôle de l'utilisateur
 *   - refreshBadgeDemandes()     → met à jour les badges de notification (demandes, stock, contrats)
 *   - buildBottomNav()           → barre de navigation mobile (responsive)
 *
 * PAGES DISPONIBLES :
 *   dashboard, biens, equipements, interventions, contrats, stock,
 *   demandes, gestion_materiel, energie, historique, archives, documents,
 *   utilisateurs, configuration, stats, scan, legifrance, chorus
 * ═══════════════════════════════════════════════════════════════════════════════
 */

// ── Registre des pages ────────────────────────────────────────────────────────
// Mapping pageKey → métadonnées :
//   title    : libellé affiché dans l'entête
//   renderFn : NOM de la fonction de rendu (string) — résolu paresseusement
//              au moment de l'appel, car les modules pages/*.js sont chargés
//              dynamiquement par js/page-loader.js (lazy-loading).
const PAGES = {
  dashboard:        { title: 'Tableau de bord',    renderFn: 'renderDashboard'        },
  biens:            { title: 'Biens',              renderFn: 'renderBiens'            },
  equipements:      { title: 'Equipements',        renderFn: 'renderEquipements'      },
  stock:            { title: 'Stock',              renderFn: 'renderStock'            },
  interventions:    { title: 'Interventions',      renderFn: 'renderInterventions'    },
  contrats:         { title: 'Contrats',           renderFn: 'renderContrats'         },
  energie:          { title: 'Energie',            renderFn: 'renderEnergie'          },
  gestion_materiel: { title: 'Gestion matériel',   renderFn: 'renderGestionMateriel'  },
  utilisateurs:     { title: 'Utilisateurs',       renderFn: 'renderUtilisateurs'     },
  historique:       { title: 'Historique',         renderFn: 'renderHistorique'       },
  configuration:    { title: 'Configuration',      renderFn: 'renderConfiguration'    },
  journal:          { title: 'Journal',            renderFn: 'renderJournal'          },
  statsAvancees:    { title: 'Stats avancees',     renderFn: 'renderStatsAvancees'    },
  demandes:         { title: 'Demandes en cours',  renderFn: 'renderDemandes'         },
  demandesArchives: { title: 'Demandes cloturees', renderFn: 'renderDemandesArchives' },
  scan:             { title: 'Scanner un code',    renderFn: 'renderScan'             },
  archives:         { title: 'Archives',           renderFn: 'renderArchives'         },
  legifrance:       { title: 'Légifrance',         renderFn: 'renderLegifrance'       },
  chorus:           { title: 'Chorus Pro',         renderFn: 'renderChorus'           },
  superadmin:       { title: 'Multi-Tenant',       renderFn: 'renderSuperAdmin'       },
  superadmin_branding: { title: 'Personnalisation', renderFn: 'renderSuperAdminBranding' },
  inventaire_agent: { title: 'Mes biens',          renderFn: 'renderInventaireAgent'  },
  mobilite_carbone: { title: 'Mobilité carbone',   renderFn: 'renderMobiliteCarbone'  },
  carbone:          { title: 'Bilan Carbone',      renderFn: 'renderCarbone'          },
  plans:            { title: 'Plans',              renderFn: 'renderPlans'            },
  annuaire:         { title: 'Plans',              renderFn: 'renderAnnuaire'         },
  urgences:         { title: 'Procédures d\'urgence', renderFn: 'renderUrgences'        },
};

// ── Onglets de la page Plans ─────────────────────────────────────────────────
// « plans » (éditeur) et « annuaire » (personnes + présence) restent deux pages
// distinctes — plans.js référence #mainContent à 11 endroits, dont des onclick
// inline : en faire des sous-vues d'un même conteneur serait risqué pour rien.
// On les présente comme des onglets via la barre d'outils, hors de #mainContent.
// Cette fonction vit dans nav.js parce que c'est le seul fichier toujours chargé
// (le page-loader ne charge que le script de la page courante).
const _PLANS_TABS = ['plans', 'annuaire'];
function _plansSameEntry(a, b) {
  return _PLANS_TABS.includes(a) && _PLANS_TABS.includes(b);
}
function plansTabBar(current) {
  // Les demandeurs n'ont pas accès à l'éditeur : pas d'onglets, pas de leurre.
  const role = (typeof App !== 'undefined' && App.currentUser) ? App.currentUser.Role : '';
  // Demandeur et Visionneur n'ont accès qu'à l'annuaire : une seule vue, donc
  // pas d'onglets. (Le Visionneur est redirigé vers 'annuaire' par navigate().)
  if (!role || role === 'Demandeur' || role === 'Visionneur') return '';
  const tab = (page, icon, label, title) =>
    '<button onclick="navigate(\'' + page + '\')" title="' + title + '"'
    + ' style="padding:5px 12px;font-size:12px;font-weight:600;border:1px solid var(--gray-border);cursor:pointer;'
    + 'background:' + (current === page ? 'var(--blue,#2b7be6)' : 'transparent') + ';'
    + 'color:' + (current === page ? '#fff' : 'var(--text)') + ';'
    + 'border-radius:' + (page === 'plans' ? '6px 0 0 6px' : '0 6px 6px 0') + '">' + icon + ' ' + label + '</button>';
  // align-self:center : les deux barres utilisent align-items:end (pour aligner
  // les <select> sous leur label) ; sans cela les onglets se colleraient en bas.
  return '<div style="display:flex;align-self:center;flex:none">'
    + tab('plans', '✏️', 'Édition', 'Dessiner et modifier les plans')
    + tab('annuaire', '🗺️', 'Annuaire', 'Voir les personnes, leur statut et leur agenda')
    + '</div>';
}

// ── Navigation ────────────────────────────────────────────────────────────────
// Jeton de re-entrance : `navigate()` est asynchrone (await PageLoader.ensure).
// Si l'utilisateur clique vite sur B pendant que A charge encore, deux appels
// se chevauchent. Sans garde, le rendu de A (qui finit après B) écraserait B.
// On incrémente un compteur à chaque appel et on abandonne tout appel devenu
// obsolète une fois le script chargé.
let _navSeq = 0;

async function navigate(page) {
  const navToken = ++_navSeq;

  // Trace de navigation : sans elle, le journal ne sait pas où l'utilisateur
  // est allé (plusieurs pages partagent les mêmes appels API, d'autres n'en
  // font aucun). Non bloquant et silencieux en cas d'échec.
  try {
    if (typeof JournalApi !== 'undefined' && App.currentUser && page !== App.currentPage) {
      JournalApi.page(page, App.currentPage || '');
    }
  } catch (_) { /* la navigation prime sur la journalisation */ }

  // Couper la caméra avant de changer de page
  if (typeof stopScan === 'function') stopScan();

  App.currentPage   = page;
  App.currentFilter = 'Tous';
  App.searchTerm    = '';
  App._panneauFiltreOuvert = false;
  App._sortKey = null;
  App._sortDir = 'asc';

  // Sauvegarder la page active pour la restaurer au refresh
  try { sessionStorage.setItem('gmao_current_page', page); } catch(_) {}

  // « plans » (édition) et « annuaire » (cartographie des personnes) sont deux
  // ONGLETS d'une même entrée de menu : quelle que soit la vue affichée, c'est
  // la même ligne du menu qui doit rester surlignée.
  // L'éditeur de plans n'a de sens que pour qui peut éditer : tous ses outils
  // sont derrière canEdit(). Sans ce droit, la page est un plan intouchable —
  // et pour un Demandeur, plans.php refuse même la lecture (403). On renvoie
  // donc vers l'annuaire, seule vue utile. Placé dans navigate() plutôt que
  // dans le menu : couvre aussi le lien profond de l'assistant
  // (assistant.js:788) et la restauration de page après F5 (sessionStorage),
  // qui appellent navigate('plans') sans passer par le menu.
  if (page === 'plans') {
    const _peutEditer = (typeof canEdit === 'function')
      ? canEdit()
      : ['Admin', 'Gestionnaire'].includes((typeof App !== 'undefined' && App.currentUser) ? App.currentUser.Role : '');
    if (!_peutEditer) page = 'annuaire';
  }

  // ── Garde d'accès des Demandeurs ────────────────────────────────────────
  // La sidebar n'affiche que les onglets autorisés, mais elle n'est pas la
  // seule porte d'entrée : lien profond de l'assistant, restauration de page
  // après F5 (sessionStorage), ou URL saisie à la main. L'API refuserait de
  // toute façon les données (403), mais l'utilisateur verrait une page en
  // erreur plutôt qu'un refus clair — on redirige donc vers son accueil.
  if ((typeof App !== 'undefined' && App.currentUser && App.currentUser.Role) === 'Demandeur'
      && typeof DEMANDEUR_LECTURE_ITEMS !== 'undefined'
      && DEMANDEUR_LECTURE_ITEMS[page]) {
    const _accordes = App._demandeurLectureModules || [];
    if (!_accordes.includes(page)) {
      if (typeof toast === 'function') toast("Cet onglet ne vous est pas ouvert.", 'error');
      page = 'demandes';
    }
  }

  document.querySelectorAll('.nav-item[data-page]').forEach(el =>
    el.classList.toggle('active', el.dataset.page === page || _plansSameEntry(el.dataset.page, page))
  );
  document.getElementById('pageTitle').textContent = PAGES[page]?.title || page;
  // ⚠️ navigate() ne vidait PAS topbarActions : une page qui n'en pose pas
  // héritait des boutons de la précédente. Chaque page repart donc de zéro et
  // pose les siens dans son renderFn.
  const _tb = document.getElementById('topbarActions');
  if (_tb) _tb.innerHTML = '';

  // Même logique pour la barre d'actions basse : une page qui n'en pose pas
  // ne doit pas hériter des boutons « Enregistrer » de la précédente.
  if (typeof clearPageActionBar === 'function') clearPageActionBar();

  // ── Lazy-load du JS de la page ──────────────────────────────────────────
  // On affiche un état de chargement minimal pendant le fetch du script (utile
  // sur réseau lent). Les pages déjà chargées s'affichent instantanément.
  const main = document.getElementById('mainContent');
  // On repart d'un état « pas encore rendu » à CHAQUE navigation, sinon le
  // squelette de chargement ne réapparaîtrait jamais après la 1re page.
  if (main) delete main.dataset._pageRendered;
  const loadingTimer = setTimeout(() => {
    // Ne rien afficher si une navigation plus récente a pris le relais.
    if (navToken !== _navSeq) return;
    if (main && !main.dataset._pageRendered) {
      main.innerHTML = '<div style="padding:40px;text-align:center;color:var(--gray-text)"><div style="font-size:32px;margin-bottom:8px">⏳</div>Chargement de la page…</div>';
    }
  }, 180); // pas de flash visuel si chargement < 180ms

  try {
    if (window.PageLoader && typeof window.PageLoader.ensure === 'function') {
      await window.PageLoader.ensure(page);
    }
  } catch (e) {
    clearTimeout(loadingTimer);
    // Une navigation plus récente a démarré : ne pas écraser son écran.
    if (navToken !== _navSeq) return;
    if (main) {
      main.innerHTML = '<div style="padding:40px;text-align:center;color:#e74c3c">'
        + '<div style="font-size:32px;margin-bottom:8px">⚠️</div>'
        + 'Impossible de charger la page <code>' + page + '</code>.<br>'
        + '<span style="font-size:12px;color:var(--gray-text)">' + (e?.message || 'Erreur réseau') + '</span><br>'
        + '<button class="btn btn-secondary btn-sm" style="margin-top:12px" onclick="navigate(\'' + page + '\')">Réessayer</button>'
        + '</div>';
    }
    return;
  }
  clearTimeout(loadingTimer);

  // Le script a fini de charger ; mais l'utilisateur a peut-être déjà navigué
  // ailleurs entre-temps. Si ce n'est plus la navigation courante, on s'arrête
  // ici pour ne pas écraser la page que l'utilisateur regarde réellement.
  if (navToken !== _navSeq) return;

  // Résoudre la fonction de rendu MAINTENANT, après le chargement du script
  const fnName = PAGES[page]?.renderFn;
  const fn = fnName ? window[fnName] : null;
  if (typeof fn === 'function') {
    try { fn(); }
    catch (e) {
      console.error('[navigate] erreur dans', fnName, e);
      if (main) main.innerHTML = '<div style="padding:40px;text-align:center;color:#e74c3c">Erreur d\'exécution de la page : ' + (e?.message || 'inconnue') + '</div>';
    }
  } else {
    console.warn('[navigate] Fonction de rendu introuvable :', fnName, 'pour la page', page);
    if (main) main.innerHTML = '<div style="padding:40px;text-align:center;color:var(--gray-text)">Page indisponible.</div>';
  }
  if (main) main.dataset._pageRendered = '1';

  if (typeof buildBottomNav === 'function') buildBottomNav();

  // Injecter le bouton export sur les pages sans renderTable (délai pour attendre le rendu async)
  if (App.currentUser?.Role !== 'Demandeur') {
    setTimeout(_injectPageExport, 500);
    setTimeout(_injectPageExport, 1500); // retry pour les pages lentes
  }
}

// ── Onglets ouvrables en consultation aux Demandeurs ─────────────────────────
// Source de vérité partagée par la sidebar et la fiche du compte
// (Utilisateurs → Onglets consultables). Toute page listée ici doit remplir
// DEUX conditions, sans quoi l'ouvrir exposerait une écriture ou un 403 :
//   1) ses actions d'édition sont derrière canEdit() — faux pour un Demandeur ;
//   2) sa route de lecture PHP utilise require_lecture() avec le même
//      identifiant d'onglet (voir api/index.php).
//
// Volontairement absents :
//   • demandes / historique   → le demandeur a déjà SES demandes ;
//   • mobilite_carbone        → il a déjà SA vue personnelle, plus adaptée que
//                               la vue consolidée (voir plus bas) ;
//   • urgences, plans         → pilotés par leurs propres réglages existants ;
//   • archives                → données nominatives, hors consultation ;
//   • configuration, journal  → administration et traces d'audit.
const DEMANDEUR_LECTURE_ITEMS = {
  dashboard:        { icon: '📊', label: 'Tableau de bord',  section: 'Principal'   },
  biens:            { icon: '🏢', label: 'Biens',            section: 'Inventaire'  },
  equipements:      { icon: '⚙️', label: 'Équipements',      section: 'Inventaire'  },
  stock:            { icon: '📦', label: 'Stock',            section: 'Inventaire'  },
  interventions:    { icon: '🔧', label: 'Interventions',    section: 'Maintenance' },
  contrats:         { icon: '📋', label: 'Contrats',         section: 'Maintenance' },
  gestion_materiel: { icon: '💰', label: 'Gestion matériel', section: 'Gestion'     },
  energie:          { icon: '⚡', label: 'Énergie',          section: 'Fluides'     },
  carbone:          { icon: '🌿', label: 'Bilan Carbone',    section: 'Fluides'     },
  statsAvancees:    { icon: '📈', label: 'Stats avancées',   section: 'Analyse'     },
};

// ── Construction sidebar selon rôle ──────────────────────────────────────────

async function buildSidebarWithVisibility(user) {
  // Les prefs de visibilité sont stockées en localStorage (par utilisateur, par navigateur)
  var visibility = typeof getUserNavVisibility === 'function' ? getUserNavVisibility() : {};
  await buildSidebar(user, visibility);
}

async function buildSidebar(user, visibility) {
  if (!visibility) visibility = {};

  const role = user.Role;
  const nav  = document.getElementById('sidebarNav');

  // ─── Modules désactivés au niveau tenant (configurés par le SuperAdmin) ───
  // S'ajoutent aux préférences personnelles de visibilité.
  let disabledModules = [];
  try {
    const cfg = await apiRequest('config_check&key=modules_disabled');
    if (cfg?.value) {
      const parsed = JSON.parse(cfg.value);
      if (Array.isArray(parsed)) disabledModules = parsed;
    }
  } catch(_) {}
  App._disabledModules = disabledModules;

  if (role === 'Demandeur') {
    // Récupérer en parallèle les 2 flags d'activation (gain de latence au boot)
    let inventaireHtml = '';
    let urgencesHtml = '';
    App._inventaireAgentActif = false;
    App._urgencesActif = false;
    const [cfgInv, cfgUrg, cfgPlans] = await Promise.all([
      apiRequest('config_check&key=inventaire_agent_actif').catch(() => null),
      apiRequest('config_check&key=urgences_actif').catch(() => null),
      apiRequest('config_check&key=plans_demandeur_actif').catch(() => null),
    ]);
    if (cfgInv?.value === '1') {
      App._inventaireAgentActif = true;
      inventaireHtml = `
        <div class="nav-section-title" style="margin-top:8px">Inventaire</div>
        <div class="nav-item" data-page="inventaire_agent" onclick="navigate('inventaire_agent')">
          <span class="icon">📦</span><span class="nav-label">Mes biens</span>
        </div>`;
    }
    // L'onglet urgences est aussi sujet à la préférence personnelle de visibilité
    // et au filtre tenant (modules désactivés au niveau tenant)
    if (cfgUrg?.value === '1' && visibility['urgences'] !== false && !disabledModules.includes('urgences')) {
      App._urgencesActif = true;
      urgencesHtml = `
        <div class="nav-section-title" style="margin-top:8px">Sécurité</div>
        <div class="nav-item" data-page="urgences" onclick="navigate('urgences')">
          <span class="icon">🚨</span><span class="nav-label">Procédures d'urgence</span>
        </div>`;
    }

    // Onglet Plans (annuaire cartographié) — activé par le gestionnaire
    let plansHtml = '';
    App._plansDemandeurActif = false;
    if (cfgPlans?.value === '1' && visibility['annuaire'] !== false && !disabledModules.includes('annuaire')) {
      App._plansDemandeurActif = true;
      plansHtml = `
        <div class="nav-section-title" style="margin-top:8px">Plans</div>
        <div class="nav-item" data-page="annuaire" onclick="navigate('annuaire')">
          <span class="icon">🗺️</span><span class="nav-label">Plans</span>
        </div>`;
    }

    // ─── Onglets ouverts en consultation par le gestionnaire ───────────────
    // Le demandeur GARDE son rôle : on n'ajoute que des vues en lecture. Les
    // boutons d'action de ces pages passent tous par canEdit(), qui reste faux
    // pour un Demandeur, et l'API refuse toute écriture (require_role est
    // conservé devant les POST/PUT/DELETE). Voir require_lecture() côté PHP.
    //
    // On demande la liste EFFECTIVE au serveur (surcharge individuelle si elle
    // existe, sinon réglage global) plutôt que de recomposer la règle ici : le
    // menu resterait sinon désynchronisé de ce que l'API autorise vraiment.
    let lectureHtml = '';
    App._demandeurLectureModules = [];
    try {
      const resp = await apiRequest('mes_acces_lecture').catch(() => null);
      if (resp && Array.isArray(resp.modules)) App._demandeurLectureModules = resp.modules;
    } catch(_) {}

    const accordes = App._demandeurLectureModules
      .filter(function(p) { return DEMANDEUR_LECTURE_ITEMS[p]; })
      .filter(function(p) { return !disabledModules.includes(p) && visibility[p] !== false; });

    if (accordes.length) {
      lectureHtml = '<div class="nav-section-title" style="margin-top:8px">Consultation</div>';
      // Ordre du catalogue plutôt qu'ordre d'enregistrement : le menu reste
      // stable quel que soit l'ordre dans lequel les cases ont été cochées.
      Object.keys(DEMANDEUR_LECTURE_ITEMS)
        .filter(function(p) { return accordes.includes(p); })
        .forEach(function(p) {
          const it = DEMANDEUR_LECTURE_ITEMS[p];
          lectureHtml += '<div class="nav-item" data-page="' + p + '" onclick="navigate(\'' + p + '\')" title="' + it.label + ' — lecture seule">'
                      +  '<span class="icon">' + it.icon + '</span><span class="nav-label">' + it.label + '</span>'
                      +  '<span title="Lecture seule" style="margin-left:auto;font-size:10px;opacity:.55">👁️</span>'
                      +  '</div>';
        });
    }

    nav.innerHTML = `
      <div class="nav-section-title">Mes demandes</div>
      <div class="nav-item active" data-page="demandes" onclick="navigate('demandes')">
        <span class="icon">📝</span><span class="nav-label">Nouvelle demande</span>
      </div>
      <div class="nav-item" data-page="historique" onclick="navigate('historique')">
        <span class="icon">📁</span><span class="nav-label">Demandes passees</span>
      </div>
      <div class="nav-section-title" style="margin-top:8px">Mon empreinte</div>
      <div class="nav-item" data-page="mobilite_carbone" onclick="navigate('mobilite_carbone')">
        <span class="icon">🚗</span><span class="nav-label">Mobilité carbone</span>
      </div>
      ${inventaireHtml}
      ${urgencesHtml}
      ${plansHtml}
      ${lectureHtml}`;
    if (typeof buildBottomNav === 'function') buildBottomNav();
    return;
  }

  // Badge demandes (partagé par les autres rôles)
  const bdg = `<span id="badgeDemandes" style="display:none;background:#e74c3c;color:#fff;border-radius:10px;padding:1px 7px;font-size:11px;margin-left:6px">0</span>`;

  // ─── Activation de l'onglet Urgences (configurée par les gestionnaires) ───
  // Comme « Mes biens » (inventaire_agent_actif), cet onglet n'apparaît que si
  // le gestionnaire l'a explicitement activé dans Configuration → 🚨 Urgences.
  let _urgencesActif = false;
  try {
    const cfg = await apiRequest('config_check&key=urgences_actif');
    _urgencesActif = (cfg?.value === '1');
  } catch(_) {}
  App._urgencesActif = _urgencesActif;

  // Filtre visibilité (combiné : préférence utilisateur + désactivé tenant + flag urgences)
  // Exception : Admin et Gestionnaire voient TOUJOURS l'onglet urgences (le toggle
  // on/off ne contrôle la visibilité que pour les autres rôles).
  function showNav(page) {
    if (disabledModules.includes(page)) return false;
    if (page === 'urgences') {
      const isManager = (role === 'Admin' || role === 'Gestionnaire');
      if (!isManager && !_urgencesActif) return false;
    }
    return visibility[page] !== false;
  }

  // Badge spécial pour l'item archives
  var badgeArchives = '<span id="badgeArchives" style="display:none;background:#16a34a;color:#fff;border-radius:10px;padding:1px 7px;font-size:11px;margin-left:6px">0</span>';

  // ─── Construction du menu basée sur UI_NAV_ITEMS (source de vérité) ───
  // L'ordre suit getOrderedNavItems() qui applique les préférences utilisateur,
  // ou l'ordre déclaré dans UI_NAV_ITEMS si pas de préférence enregistrée.
  var orderedItems = (typeof getOrderedNavItems === 'function')
      ? getOrderedNavItems()
      : (typeof UI_NAV_ITEMS !== 'undefined' ? UI_NAV_ITEMS.slice() : []);

  if (typeof getOrderedNavItems !== 'function') {
    console.warn('[Sidebar] getOrderedNavItems indisponible — ordre par défaut. Videz le cache (Ctrl+Shift+R).');
  } else {
    console.log('[Sidebar] Ordre appliqué :', orderedItems.map(function(n){return n.page;}).join(' › '));
  }

  // Map des extras (badge / suffixe) par page, pour rester fidèle à l'ancien rendu
  var extras = {
    demandes:  bdg,
    archives:  badgeArchives,
  };

  var menuBase = '';
  var lastSection = null;
  orderedItems.forEach(function(n) {
    if (!showNav(n.page)) return;
    if (n.section !== lastSection) {
      var sectionLabel = (typeof getSectionLabel === 'function') ? getSectionLabel(n.section) : n.section;
      menuBase += '<div class="nav-section-title"' + (lastSection ? ' style="margin-top:8px"' : '') + '>' + sectionLabel + '</div>';
      lastSection = n.section;
    }
    menuBase += '<div class="nav-item" data-page="' + n.page + '" data-label="' + n.label + '" onclick="navigate(\'' + n.page + '\')">' +
                '<span class="icon">' + n.icon + '</span><span class="nav-label">' + n.label + '</span>' +
                (extras[n.page] || '') + '</div>';
  });

  if (role === 'Visionneur') {
    nav.innerHTML = menuBase;
    return;
  }

  // Admin et Gestionnaire : menu + section Administration (toujours en bas)
  nav.innerHTML = menuBase + `
    <div class="nav-section-title" style="margin-top:8px;color:rgba(245,166,35,.6)">Administration</div>
    <div class="nav-item" data-page="configuration" onclick="navigate('configuration')"><span class="icon">⚙️</span><span class="nav-label">Configuration</span><span id="badgeDeclarations" style="display:none;background:#f59e0b;color:#fff;border-radius:10px;padding:1px 7px;font-size:11px;margin-left:6px">0</span></div>
    ${showNav('journal') ? `<div class="nav-item" data-page="journal" onclick="navigate('journal')"><span class="icon">📜</span><span class="nav-label">Journal</span></div>` : ''}`;
}

// ── Badge demandes (rafraîchi toutes les 30s) ─────────────────────────────────
async function refreshBadgeDemandes() {
  try {
    const json  = await (await fetch('api/index.php?action=demandes_count', { credentials: 'same-origin' })).json();
    const tech    = json.data?.tech    ?? 0;
    const archive = json.data?.archive ?? 0;
    const total   = json.data?.total   ?? (tech + archive);

    // Badge sidebar (demandes techniques uniquement)
    const el = document.getElementById('badgeDemandes');
    if (el) { el.textContent = tech; el.style.display = tech > 0 ? 'inline' : 'none'; }

    // Badge sidebar Archives
    const elArch = document.getElementById('badgeArchives');
    if (elArch) { elArch.textContent = archive; elArch.style.display = archive > 0 ? 'inline' : 'none'; }

    // Système de notifications cloche
    _notifRefresh({ tech, archive, total, stockAlerte: json.data?.stockAlerte ?? 0, contratsAlerte: json.data?.contratsAlerte ?? 0 });

    // Badge déclarations inventaire en attente (pour Admin/Gestionnaire)
    _refreshBadgeDeclarations();
  } catch(_) {}
}

// ── Badge déclarations inventaire (Admin/Gestionnaire) ───────────────────────
async function _refreshBadgeDeclarations() {
  const role = App.currentUser?.Role;
  if (role !== 'Admin' && role !== 'Gestionnaire') return;
  try {
    const resp = await apiRequest('declarations_inventaire_count');
    const count = resp?.count ?? 0;
    const el = document.getElementById('badgeDeclarations');
    if (el) { el.textContent = count; el.style.display = count > 0 ? 'inline' : 'none'; }
  } catch(_) {}
}

// ═══════════════════════════════════════════════════════════════════════════
//  SYSTÈME DE NOTIFICATIONS
// ═══════════════════════════════════════════════════════════════════════════

// Préférences par défaut (stockées dans localStorage)
const NOTIF_PREFS_KEY = 'gmao_notif_prefs';
const NOTIF_SEEN_KEY  = 'gmao_notif_seen';

const NOTIF_TYPES = [
  { id: 'dem_technique', label: '🔧 Nouvelles demandes techniques',       defaultOn: true  },
  { id: 'dem_archive',   label: '🗄️ Nouvelles demandes d\'archive',        defaultOn: true  },
  { id: 'stock_alerte',  label: '📦 Articles en alerte de stock',          defaultOn: false },
  { id: 'contrat_alerte',label: '📋 Contrats proches de l\'expiration',    defaultOn: false },
];

function _getNotifPrefs() {
  try { return JSON.parse(localStorage.getItem(NOTIF_PREFS_KEY)) || {}; } catch(_) { return {}; }
}
function _getNotifSeen() {
  try { return JSON.parse(localStorage.getItem(NOTIF_SEEN_KEY)) || {}; } catch(_) { return {}; }
}

let _lastNotifData = {};

function _notifRefresh(data) {
  _lastNotifData = data;
  const prefs = _getNotifPrefs();
  const seen  = _getNotifSeen();

  // Calculer le nombre de notifications non vues
  const counts = {
    dem_technique:  data.tech    || 0,
    dem_archive:    data.archive || 0,
    stock_alerte:   data.stockAlerte  || 0,
    contrat_alerte: data.contratsAlerte || 0,
  };

  let totalNonVu = 0;
  NOTIF_TYPES.forEach(t => {
    const actif = (prefs[t.id] !== undefined) ? prefs[t.id] : t.defaultOn;
    if (!actif) return;
    const dernier = seen[t.id] || 0;
    const actuel  = counts[t.id] || 0;
    if (actuel > dernier) totalNonVu += (actuel - dernier);
  });

  // Afficher/masquer la cloche
  const wrap  = document.getElementById('notifBellWrap');
  const badge = document.getElementById('notifBadge');
  if (wrap)  wrap.style.display = '';
  if (badge) {
    badge.textContent = totalNonVu > 99 ? '99+' : String(totalNonVu);
    badge.style.display = totalNonVu > 0 ? '' : 'none';
    // Animation pulse si nouvelles notifications
    if (totalNonVu > 0) {
      badge.style.animation = 'notifPulse 1.5s ease infinite';
    } else {
      badge.style.animation = '';
    }
  }

  // Rafraîchir la liste si le panneau est ouvert
  if (document.getElementById('notifPanel')?.style.display !== 'none') {
    _buildNotifList(counts, prefs, seen);
  }
}

function _buildNotifList(counts, prefs, seen) {
  const list = document.getElementById('notifList');
  if (!list) return;

  const items = NOTIF_TYPES.map(t => {
    const actif  = (prefs[t.id] !== undefined) ? prefs[t.id] : t.defaultOn;
    const dernier = seen[t.id] || 0;
    const actuel  = counts[t.id] || 0;
    const nonVu   = Math.max(0, actuel - dernier);
    return { ...t, actif, actuel, nonVu };
  }).filter(t => t.actif);

  if (items.length === 0 || items.every(t => t.actuel === 0)) {
    list.innerHTML = `<div style="padding:24px;text-align:center;color:var(--gray-text);font-size:13px">
      <div style="font-size:28px;margin-bottom:8px">✅</div>Tout est à jour !
    </div>`;
    return;
  }

  const destinations = {
    dem_technique:  'demandes',
    dem_archive:    'archives',
    stock_alerte:   'stock',
    contrat_alerte: 'contrats',
  };

  list.innerHTML = items.filter(t => t.actuel > 0).map(t => `
    <div style="padding:12px 16px;border-bottom:1px solid var(--gray-border);display:flex;align-items:center;gap:12px;cursor:pointer;transition:background .1s"
      onmouseover="this.style.background='var(--gray-bg)'" onmouseout="this.style.background=''"
      onclick="navigate('${destinations[t.id]}');toggleNotifPanel()">
      <div style="flex:1">
        <div style="font-size:13px;font-weight:${t.nonVu>0?'700':'400'};color:${t.nonVu>0?'var(--navy)':'var(--gray-text)'}">
          ${t.label}
        </div>
        <div style="font-size:12px;color:var(--gray-text);margin-top:2px">
          ${t.actuel} en attente${t.nonVu > 0 ? ` — <span style="color:var(--orange);font-weight:600">+${t.nonVu} nouveau${t.nonVu>1?'x':''}</span>` : ''}
        </div>
      </div>
      <span style="background:${t.nonVu>0?'#e74c3c':'var(--gray-border)'};color:${t.nonVu>0?'white':'var(--gray-text)'};border-radius:10px;padding:2px 8px;font-size:12px;font-weight:700">${t.actuel}</span>
      <span style="color:var(--gray-text);font-size:14px">→</span>
    </div>`).join('');
}

function toggleNotifPanel() {
  const panel = document.getElementById('notifPanel');
  if (!panel) return;
  const isOpen = panel.style.display !== 'none';
  panel.style.display = isOpen ? 'none' : '';
  if (!isOpen) {
    // Reconstruire la liste à l'ouverture
    const prefs  = _getNotifPrefs();
    const seen   = _getNotifSeen();
    const counts = {
      dem_technique:  _lastNotifData.tech    || 0,
      dem_archive:    _lastNotifData.archive || 0,
      stock_alerte:   _lastNotifData.stockAlerte  || 0,
      contrat_alerte: _lastNotifData.contratsAlerte || 0,
    };
    _buildNotifList(counts, prefs, seen);
    // Fermer en cliquant ailleurs
    setTimeout(() => {
      document.addEventListener('click', _closeNotifOnOutside, { once: true });
    }, 10);
  }
}

function _closeNotifOnOutside(e) {
  const wrap = document.getElementById('notifBellWrap');
  if (wrap && !wrap.contains(e.target)) {
    document.getElementById('notifPanel').style.display = 'none';
  }
}

function marquerToutLu() {
  const counts = {
    dem_technique:  _lastNotifData.tech    || 0,
    dem_archive:    _lastNotifData.archive || 0,
    stock_alerte:   _lastNotifData.stockAlerte  || 0,
    contrat_alerte: _lastNotifData.contratsAlerte || 0,
  };
  localStorage.setItem(NOTIF_SEEN_KEY, JSON.stringify(counts));
  _notifRefresh(_lastNotifData);
  // Vider la liste dans le panel
  const list = document.getElementById('notifList');
  if (list) list.innerHTML = `<div style="padding:24px;text-align:center;color:var(--gray-text);font-size:13px">
    <div style="font-size:28px;margin-bottom:8px">✅</div>Tout marqué comme lu !
  </div>`;
}

function toggleNotifSettings() {
  const panel = document.getElementById('notifSettings');
  if (!panel) return;
  const isOpen = panel.style.display !== 'none';
  if (!isOpen) _buildNotifSettings();
  panel.style.display = isOpen ? 'none' : '';
}

function _buildNotifSettings() {
  const prefs = _getNotifPrefs();
  const wrap = document.getElementById('notifSettingsItems');
  if (!wrap) return;

  let html = NOTIF_TYPES.map(t => {
    const checked = (prefs[t.id] !== undefined) ? prefs[t.id] : t.defaultOn;
    return `<label style="display:flex;align-items:center;gap:10px;cursor:pointer;padding:6px 0">
      <input type="checkbox" id="notifPref_${t.id}" ${checked?'checked':''}
        style="width:16px;height:16px;cursor:pointer;accent-color:var(--blue)">
      <span style="font-size:13px">${t.label}</span>
    </label>`;
  }).join('');

  // Section Push mobile
  if (typeof PushManager_GMAO !== 'undefined') {
    const status = PushManager_GMAO.getStatus();
    let pushHtml = '<div style="border-top:1px solid var(--gray-border);margin-top:10px;padding-top:10px">';
    pushHtml += '<div style="font-size:11px;font-weight:700;color:var(--gray-text);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px">📱 Notifications push (mobile)</div>';

    if (!status.supported) {
      if (status.isIOS && !status.isIOSPWA) {
        pushHtml += '<div style="font-size:12px;color:var(--gray-text);line-height:1.5">Ajoutez l\'app à l\'écran d\'accueil pour activer les notifications push sur iPhone.</div>';
      } else {
        pushHtml += '<div style="font-size:12px;color:var(--gray-text)">Non supporté sur ce navigateur.</div>';
      }
    } else if (status.permission === 'granted') {
      pushHtml += `<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
        <span style="color:#16a34a;font-weight:600;font-size:12px">✅ Activées</span>
      </div>
      <div style="display:flex;gap:6px;margin-bottom:8px">
        <button onclick="PushManager_GMAO.testNotification()" style="font-size:11px;padding:5px 10px;border:1px solid var(--blue);background:var(--blue-pale);color:var(--blue);border-radius:6px;cursor:pointer">
          🔔 Tester
        </button>
      </div>
      <div style="font-size:11px;color:var(--gray-text);line-height:1.5">
        Pour désactiver les notifications, utilisez les paramètres de votre navigateur (icône 🔒 ou ⓘ dans la barre d'adresse).
      </div>`;
    } else if (status.permission === 'denied') {
      pushHtml += '<div style="font-size:12px;color:#e74c3c;line-height:1.5">❌ Bloquées dans les paramètres du navigateur.<br>Réactivez-les manuellement dans les paramètres de votre navigateur.</div>';
    } else {
      pushHtml += `<button onclick="PushManager_GMAO.promptNow()" style="font-size:12px;padding:7px 14px;border:none;background:linear-gradient(135deg,#0096c7,#48cae4);color:white;border-radius:8px;cursor:pointer;font-weight:600">
        🔔 Activer les notifications push
      </button>`;
    }
    pushHtml += '</div>';
    html += pushHtml;
  }

  wrap.innerHTML = html;
}

function sauvegarderPrefsNotif() {
  const prefs = {};
  NOTIF_TYPES.forEach(t => {
    const el = document.getElementById('notifPref_' + t.id);
    if (el) prefs[t.id] = el.checked;
  });
  localStorage.setItem(NOTIF_PREFS_KEY, JSON.stringify(prefs));
  document.getElementById('notifSettings').style.display = 'none';
  _notifRefresh(_lastNotifData);
  // Toast de confirmation
  if (typeof toast === 'function') toast('Préférences de notifications enregistrées', 'success');
}

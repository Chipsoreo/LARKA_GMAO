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
 * Larka — Page : Configuration (Admin)
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Page d'administration en 3 onglets :
 *
 * 📋 LISTES DÉROULANTES
 *   - Gestion des valeurs par catégorie (Famille, Statut, Type, Bâtiment…)
 *   - Sous-onglets : Biens, Équipements, Interventions, Contrats, Stock, Demandes
 *   - Ajout, modification, désactivation/réactivation (pas de suppression)
 *   - Options par catégorie : champ obligatoire, saisie libre autorisée
 *   - Champs obligatoires configurables par type de formulaire
 *
 * 📢 NOTES INFO
 *   - Messages en banderole pour les demandeurs
 *
 * 🖥️ SERVEUR (config.json)
 *   - Base de données (SQLite/PostgreSQL/MariaDB), HTTPS, CORS
 *   - OAuth (Microsoft, Google), SMTP
 *   - Légifrance, Chorus Pro
 *   - Assistant IA (fournisseur, clé API, modèle, réglages de rapidité CPU)
 *   - Migration inter-drivers
 *
 * POINT D'ENTRÉE : renderConfiguration()
 * ═══════════════════════════════════════════════════════════════════════════════
 */

const CATEGORIES_LABELS = {
  Batiment:              "Bâtiments",
  FamilleBien:           "Familles de Biens",
  SousFamilleBien:       "Sous-familles de Biens",
  FamilleEquipement:     "Familles d'Équipements",
  SousFamilleEquipement: "Sous-familles d'Équipements",
  CategorieStock:        "Catégories de Stock",
  StatutBien:            "Statuts des Biens",
  StatutEquipement:      "Statuts des Équipements",
  EtatAsset:             "États (Biens & Équipements)",
  StatutIntervention:    "Statuts des Interventions",
  TypeIntervention:      "Types d'Interventions",
  PrioriteIntervention:  "Priorités des Interventions",
  StatutContrat:         "Statuts des Contrats",
  TypeContrat:           "Types de Contrats",
  CategorieDemande:      "Catégories de Demandes",
  CategoriePoint:        "Catégories de Points (Plans)",
};

// Groupes d'onglets pour la configuration des listes
const LISTES_TABS = {
  biens:         { label: '🏢 Biens',         cats: ['FamilleBien','SousFamilleBien','StatutBien','EtatAsset','Batiment'] },
  equipements:   { label: '⚙️ Équipements',   cats: ['FamilleEquipement','SousFamilleEquipement','StatutEquipement'] },
  interventions: { label: '🔧 Interventions',  cats: ['TypeIntervention','PrioriteIntervention','StatutIntervention'] },
  contrats:      { label: '📋 Contrats',       cats: ['TypeContrat','StatutContrat'] },
  stock:         { label: '📦 Stock',          cats: ['CategorieStock'] },
  demandes:      { label: '📝 Demandes',       cats: ['CategorieDemande'] },
  plans:         { label: '🗺️ Plans',           cats: ['CategoriePoint'] },
};

// ── Listes des modules ──────────────────────────────────────────────────────
//
// Elles ne sont plus dans la table « Listes » du cœur : chaque module a son
// fichier, propre au client.
//
//     extensions/config/<identifiant>/[<client>/]listes.json
//
// L'onglet ci-dessous est un ÉDITEUR par-dessus ce fichier — il lit et réécrit
// exactement le même. Le fichier reste la source, et l'éditer à la main marche
// toujours ; mais configurer un module ne doit pas exiger un accès SSH.
let _catsModules = [];      // [{identifiant, module, fichier, listes}]
let _moduleCourant = null;  // identifiant du module ouvert dans l'onglet

let _configTab = 'listes';
let _listesSubTab = 'biens';

// Champs configurables "obligatoire" par onglet
const CHAMPS_FORMULAIRE = {
  biens:         [
    { key:'Numero',       label:'Numéro' },
    { key:'Famille',      label:'Famille' },
    { key:'SousFamille',  label:'Sous-famille' },
    { key:'Statut',       label:'Statut' },
    { key:'Etat',         label:'État' },
    { key:'NumeroSerie',  label:'N° Série' },
    { key:'InfoProduit',  label:'Informations produit' },
    { key:'Batiment',     label:'Bâtiment' },
    { key:'Etage',        label:'Étage' },
    { key:'NumeroBureau', label:'N° Bureau' },
    { key:'NomPrenom',    label:'Affecté à' },
    { key:'DateCommande', label:'Date commande' },
    { key:'DateLivraison',label:'Date livraison' },
    { key:'Prix',         label:'Prix' },
  ],
  equipements:   [
    { key:'Numero',       label:'Numéro' },
    { key:'Famille',      label:'Famille' },
    { key:'SousFamille',  label:'Sous-famille' },
    { key:'Statut',       label:'Statut' },
    { key:'Etat',         label:'État' },
    { key:'NumeroSerie',  label:'N° Série' },
    { key:'Marque',       label:'Marque' },
    { key:'Modele',       label:'Modèle' },
    { key:'Fournisseur',  label:'Fournisseur' },
    { key:'Batiment',     label:'Bâtiment' },
    { key:'Etage',        label:'Étage' },
    { key:'NumeroBureau', label:'N° Bureau' },
    { key:'NomPrenom',    label:'Affecté à' },
    { key:'DateCommande', label:'Date commande' },
    { key:'DateInstallation', label:'Date installation' },
    { key:'Prix',         label:'Prix' },
    { key:'Observations', label:'Observations' },
  ],
  interventions: [
    { key:'Numero',        label:'Numéro' },
    { key:'Description',   label:'Description' },
    { key:'Type',          label:'Type' },
    { key:'Priorite',      label:'Priorité' },
    { key:'Statut',        label:'Statut' },
    { key:'DateRealisation',label:'Date réalisation' },
    { key:'DureeHeures',   label:'Durée (heures)' },
    { key:'Commentaire',   label:'Commentaire' },
  ],
  contrats:      [
    { key:'Numero',       label:'Numéro' },
    { key:'Societe',      label:'Société' },
    { key:'Type',         label:'Type' },
    { key:'Statut',       label:'Statut' },
    { key:'DateDebut',    label:'Date début' },
    { key:'DateFin',      label:'Date fin' },
    { key:'MontantAnnuel',label:'Montant annuel' },
    { key:'Frequence',    label:'Fréquence' },
    { key:'Description',  label:'Description / Périmètre' },
    { key:'AlerteJours',  label:'Alerte (jours avant fin)' },
  ],
  stock:         [
    { key:'Reference',    label:'Référence' },
    { key:'Designation',  label:'Désignation' },
    { key:'Categorie',    label:'Catégorie' },
    { key:'Marque',       label:'Marque' },
    { key:'Modele',       label:'Modèle' },
    { key:'TypeArticle',  label:"Type d'article" },
    { key:'Quantite',     label:'Quantité' },
    { key:'SeuilAlerte',  label:'Seuil alerte' },
    { key:'PrixUnitaire', label:'Prix unitaire' },
    { key:'Emplacement',  label:'Emplacement' },
    { key:'Fournisseur',  label:'Fournisseur' },
  ],
  demandes:      [
    { key:'Titre',        label:'Titre' },
    { key:'Description',  label:'Description' },
    { key:'Batiment',     label:'Bâtiment' },
    { key:'Bureau',       label:'Bureau' },
    { key:'Email',        label:'Email' },
    { key:'Tel',          label:'Téléphone' },
  ],
};

function _escCfg(s) {
  return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ═══════════════════════════════════════════════════════════════
//  RENDER PRINCIPAL
// ═══════════════════════════════════════════════════════════════
async function renderConfiguration() {
  document.getElementById('topbarActions').innerHTML = '';
  const c = document.getElementById('mainContent');
  showLoading(c);
  try {
    const [listes, config] = await Promise.all([ListesApi.getAll(), ConfigApi.getAll()]);

    // Listes des modules, lues dans leurs fichiers. En cas d'échec on continue :
    // la configuration du cœur ne doit pas dépendre de la couche extensions.
    try {
      _catsModules = await apiRequest('ext_config_listes') || [];
    } catch (e) { _catsModules = []; }

    c.innerHTML = `
    <style>
      .cfg-tab {
        background:none; border:none; border-bottom:3px solid transparent;
        padding:11px 20px; font-size:13px; font-weight:600; color:var(--gray-text);
        cursor:pointer; transition:color .15s,border-color .15s; white-space:nowrap;
      }
      .cfg-tab:hover { color:var(--blue); }
      .cfg-tab-active { color:var(--blue)!important; border-bottom-color:var(--blue)!important; }

      /* ══ Server config cards ══ */
      .srv-section {
        background: var(--white, #fff);
        border: 1px solid var(--gray-border);
        border-radius: 12px;
        padding: 0 22px 20px;
        margin-bottom: 16px;
        overflow: hidden;
      }
      .srv-section-title {
        font-size: 13px; font-weight: 700; color: var(--text);
        padding: 14px 22px; margin: 0 -22px 18px;
        display: flex; align-items: center; gap: 10px;
        background: var(--gray-bg);
        border-bottom: 1px solid var(--gray-border);
      }

      .srv-grid   { display:grid; grid-template-columns:1fr 1fr;     gap:16px 20px; }
      .srv-grid-3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:16px 20px; }
      .srv-grid-1 { display:grid; grid-template-columns:1fr;         gap:16px; }
      @media(max-width:780px){ .srv-grid,.srv-grid-3 { grid-template-columns:1fr; } }

      .srv-sep { height:1px; background:var(--gray-border); margin:18px 0; }

      .srv-field { display:flex; flex-direction:column; gap:5px; }
      .srv-label { font-size:12.5px; font-weight:700; color:var(--text); }
      .srv-desc  { font-size:11.5px; color:var(--gray-text); line-height:1.55; }
      .srv-desc code {
        background:var(--gray-bg); padding:1px 6px; border-radius:4px;
        font-size:11px; color:var(--blue); border:1px solid var(--gray-border);
      }
      .srv-desc strong { color:var(--text); }
      .srv-ex {
        font-size:10.5px; font-family:'DM Mono',monospace;
        background:var(--gray-bg); color:var(--gray-text);
        padding:3px 8px; border-radius:4px;
        display:inline-block; border:1px solid var(--gray-border);
      }

      .srv-field .form-control {
        margin-top:2px; border-radius:8px; font-size:13px;
        padding:9px 12px; border:1.5px solid var(--gray-border);
        background:var(--white, #fff); color:var(--text);
        transition:border-color .15s, box-shadow .15s;
      }
      .srv-field .form-control:focus {
        border-color:var(--blue);
        box-shadow:0 0 0 3px rgba(43,123,230,.12);
        outline:none;
      }

      /* Toggle switch */
      .srv-toggle { display:flex; align-items:center; gap:12px; padding:4px 0; }
      .srv-toggle input[type=checkbox] {
        appearance:none; -webkit-appearance:none;
        width:38px; height:20px; border-radius:11px;
        background:var(--gray-border); cursor:pointer;
        position:relative; transition:background .2s;
        flex-shrink:0; border:none; outline:none;
      }
      .srv-toggle input[type=checkbox]::after {
        content:''; position:absolute; top:2px; left:2px;
        width:16px; height:16px; border-radius:50%;
        background:#fff; transition:transform .2s;
        box-shadow:0 1px 3px rgba(0,0,0,.18);
      }
      .srv-toggle input[type=checkbox]:checked { background:var(--blue); }
      .srv-toggle input[type=checkbox]:checked::after { transform:translateX(18px); }
      .srv-toggle label { font-size:12.5px; cursor:pointer; color:var(--text); font-weight:500; }

      /* DB driver tabs */
      .db-tabs {
        display:flex; border:1px solid var(--gray-border); border-radius:8px;
        overflow:hidden; margin-bottom:16px; background:var(--gray-bg);
      }
      .db-tab {
        flex:1; padding:10px 12px; text-align:center;
        font-size:12.5px; font-weight:600;
        background:transparent; color:var(--gray-text);
        cursor:pointer; border:none; transition:all .15s;
      }
      .db-tab:not(:last-child) { border-right:1px solid var(--gray-border); }
      .db-tab:hover { color:var(--blue); }
      .db-tab.active { background:var(--blue); color:#fff; }

      /* Save bar : rangée simple, positionnée par #pageActionBar */
      .srv-save-bar { display:flex; align-items:center; justify-content:space-between; gap:12px; width:100%; }

      /* Alerts */
      .srv-alert {
        padding:11px 16px; border-radius:8px; font-size:12.5px;
        display:flex; align-items:flex-start; gap:8px; line-height:1.55;
      }
      .srv-alert code {
        background:rgba(0,0,0,.06); padding:1px 5px; border-radius:3px; font-size:11px;
      }
      .srv-alert-warn {
        background:var(--chip-warn-bg); color:var(--chip-warn-fg);
        border:1px solid var(--chip-warn-bd);
      }
      .srv-alert-info {
        background:var(--chip-info-bg); color:var(--chip-info-fg);
        border:1px solid var(--chip-info-bd);
      }

      @media(max-width:700px){ #cfg-listes-grid { grid-template-columns:1fr!important; } }
    </style>

    <!-- En-tête bleue -->
    <div class="card" style="padding:16px 22px;background:linear-gradient(135deg,#1a2e4a,#213a5c);color:white;border:none;margin-bottom:0;border-radius:10px 10px 0 0">
      <div style="display:flex;align-items:center;gap:14px">
        <span style="font-size:26px">⚙️</span>
        <div>
          <div style="font-size:15px;font-weight:700">Configuration — Administrateur</div>
          <div style="font-size:12px;opacity:.7;margin-top:2px">Listes, accès utilisateurs et paramètres serveur</div>
        </div>
      </div>
    </div>

    <div style="display:flex;border-bottom:2px solid var(--gray-border);background:var(--card-bg);padding:0 18px;margin-bottom:20px;overflow-x:auto">
      <button class="cfg-tab ${_configTab==='listes'  ? 'cfg-tab-active' : ''}" onclick="switchConfigTab('listes')">📋 Listes</button>
      <button class="cfg-tab ${_configTab==='comptes' ? 'cfg-tab-active' : ''}" onclick="switchConfigTab('comptes')">👥 Comptes</button>
      <button class="cfg-tab ${_configTab==='notes'   ? 'cfg-tab-active' : ''}" onclick="switchConfigTab('notes')">📢 Notes info</button>
      <button class="cfg-tab ${_configTab==='notifications' ? 'cfg-tab-active' : ''}" onclick="switchConfigTab('notifications')">🔔 Notifications</button>
      <button class="cfg-tab ${_configTab==='inventaire' ? 'cfg-tab-active' : ''}" onclick="switchConfigTab('inventaire')">📦 Inventaire</button>
      <button class="cfg-tab ${_configTab==='plans' ? 'cfg-tab-active' : ''}" onclick="switchConfigTab('plans')">🗺️ Plans</button>
      <button class="cfg-tab ${_configTab==='urgences' ? 'cfg-tab-active' : ''}" onclick="switchConfigTab('urgences')">🚨 Urgences</button>
      <button class="cfg-tab ${_configTab==='serveur' ? 'cfg-tab-active' : ''}" onclick="switchConfigTab('serveur')" id="cfgTabServeur" style="display:none">🖥️ Serveur</button>
    </div>

    <div id="cfg-tab-body">
      ${_configTab === 'listes'         ? _renderTabListes(listes, config)      : ''}
      ${_configTab === 'comptes'        ? '<div id="cfg_comptes_slot"><div style="padding:30px;text-align:center;color:var(--gray-text)">⏳ Chargement…</div></div>' : ''}
      ${_configTab === 'notes'          ? _renderTabNotes()             : ''}
      ${_configTab === 'notifications'  ? await _renderTabNotifications() : ''}
      ${_configTab === 'inventaire'     ? _renderTabInventaire(config)  : ''}
      ${_configTab === 'plans'          ? _renderTabPlans(config)       : ''}
      ${_configTab === 'urgences'       ? '<div id="cfg_urgences_slot"><div style="padding:30px;text-align:center;color:var(--gray-text)">⏳ Chargement…</div></div>' : ''}
      ${_configTab === 'serveur'        ? '<div id="cfg-srv-slot" style="padding:50px;text-align:center;color:var(--gray-text)">Chargement…</div>' : ''}
    </div>`;

    App.restoreFilters();
    if (_configTab === 'serveur') _loadServeurConfig();
    if (_configTab === 'notes')   _loadNotes();
    if (_configTab === 'comptes') _loadComptesTab();
    if (_configTab === 'inventaire') { setTimeout(() => { if (document.getElementById('cfg_declarations_list')) _loadDeclarations(); }, 50); }
    if (_configTab === 'urgences') _loadUrgencesConfig();

    // Afficher l'onglet Serveur uniquement si pas multi-tenant ou si Super Admin
    const tabServeur = document.getElementById('cfgTabServeur');
    if (tabServeur) {
      fetch('api/index.php?action=superadmin_status').then(r=>r.json()).then(j=> {
        if (!j.success || !j.data?.multi_tenant) {
          // Pas de multi-tenant → montrer l'onglet (comportement classique)
          tabServeur.style.display = '';
        } else {
          // Multi-tenant actif → cacher sauf si session Super Admin
          tabServeur.style.display = 'none';
        }
      }).catch(()=>{ tabServeur.style.display = ''; });
    }
  } catch(e) { document.getElementById('mainContent').innerHTML = errorHtml(e.message); }
}

function switchConfigTab(tab) {
  // Chaque onglet repart sans barre d'actions ; celui qui en veut une la pose
  // lui-même après son rendu (cf. _urgSetupActionBar).
  if (typeof clearPageActionBar === 'function') clearPageActionBar();
  _configTab = tab;
  renderConfiguration();
}

// ═══════════════════════════════════════════════════════════════
//  ONGLET 1 — LISTES DÉROULANTES
// ═══════════════════════════════════════════════════════════════
function _renderTabListes(listes, config) {
  // Charger les champs obligatoires depuis la config
  let champsOblig = {};
  try {
    const raw = (config||[]).find(r => r.Cle === 'champs_obligatoires')?.Valeur;
    if (raw) champsOblig = JSON.parse(raw);
  } catch(_) {}
  const currentOblig = champsOblig[_listesSubTab] || [];

  // Sub-tabs for listes categories
  let html = `<div style="display:flex;gap:0;border:1px solid var(--gray-border);border-radius:8px;overflow:hidden;margin-bottom:20px;flex-wrap:wrap">`;
  Object.entries(LISTES_TABS).forEach(([key, tab], i, arr) => {
    const active = _listesSubTab === key ? 'background:var(--blue);color:#fff' : 'background:var(--gray-bg);color:var(--gray-text);border-right:1px solid var(--gray-border)';
    html += `<button style="flex:1;min-width:100px;padding:10px;text-align:center;font-size:12px;font-weight:600;cursor:pointer;border:none;transition:all .15s;${active}" onclick="_listesSubTab='${key}';renderConfiguration()">${tab.label}</button>`;
  });
  if (_catsModules.length) {
    const actif = _listesSubTab === 'modules'
      ? 'background:var(--blue);color:#fff'
      : 'background:var(--gray-bg);color:var(--gray-text)';
    html += `<button style="flex:1;min-width:100px;padding:10px;text-align:center;
      font-size:12px;font-weight:600;cursor:pointer;border:none;${actif}"
      onclick="_listesSubTab='modules';renderConfiguration()">🧩 Modules</button>`;
  }
  html += `</div>`;

  if (_listesSubTab === 'modules') return html + _renderListesModules();

  const currentCats = LISTES_TABS[_listesSubTab]?.cats || [];
  const colCount = currentCats.length === 1 ? '1fr' : '1fr 1fr';


  // Message d'aide pour l'onglet Plans
  if (_listesSubTab === 'plans') {
    html += `<div style="padding:14px 18px;background:var(--blue-pale);border-radius:10px;margin-bottom:16px;font-size:13px;color:var(--navy);line-height:1.6">
      <strong>💡 Catégories de points</strong> — Définissez ici les catégories utilisées pour classer les points sur vos plans (ex&nbsp;: Détecteur de fumée, Prise réseau, Robinet, Caméra, Borne incendie...). Elles apparaîtront dans la liste déroulante « Catégorie » lors de la création/modification d'un point.
    </div>`;
  }

  html += `<div id="cfg-listes-grid" style="display:grid;grid-template-columns:${colCount};gap:20px">`;
  currentCats.forEach(cat => {
    const label = CATEGORIES_LABELS[cat] || cat;
    const items = listes[cat] || [];
    // Récupère la config de catégorie (stockée en tant que premier item avec Obligatoire/SaisieLibre)
    const catOblig = items.length > 0 && items.some(x => x.Obligatoire == 1);
    const catLibre = items.length > 0 && items.some(x => x.SaisieLibre == 1);
    // Utilise le premier item actif pour lire la config catégorie
    const cfgItem  = items.find(x => x.Actif == 1) || items[0];
    const isCatOblig = cfgItem?.Obligatoire == 1;
    const isCatLibre = cfgItem?.SaisieLibre == 1;
    const labelEsc = label.replace(/'/g, "\\'").replace(/"/g, '&quot;');
    html += `
    <div class="card">
      <div class="card-header">
        <div class="card-title">📋 ${label}</div>
        <div style="display:flex;gap:6px;align-items:center">
          <button class="btn btn-ghost btn-sm" onclick="configurerCategorie('${cat}','${labelEsc}')" title="Configurer la liste">⚙️</button>
          <button class="btn btn-primary btn-sm" onclick="ajouterValeur('${cat}')">+ Ajouter</button>
        </div>
      </div>
      <div style="padding:4px 16px;font-size:11px;color:var(--gray-text);display:flex;gap:12px;flex-wrap:wrap">
        ${isCatOblig ? `<span style="color:var(--red)">● Champ obligatoire</span>` : `<span>● Champ optionnel</span>`}
        ${isCatLibre ? `<span style="color:var(--blue)">● Saisie libre autorisée</span>` : ``}
      </div>
      <div style="padding:8px 0">`;
    if (!items.length) {
      html += `<div style="padding:16px 20px;color:var(--gray-text);font-size:13px">Aucune valeur</div>`;
    } else {
      // Valeurs système protégées (non modifiables, non supprimables)
      const VALEURS_PROTEGEES = { EtatAsset: ['Jete/Recycle', 'Don/Vendu'] };
      const protectedSet = new Set(VALEURS_PROTEGEES[cat] || []);

      // Séparer actifs et inactifs
      const actifs   = items.filter(i => i.Actif == 1);
      const inactifs = items.filter(i => i.Actif != 1);

      const renderItem = (item, isInactive) => {
        const isProtected = protectedSet.has(item.Valeur);
        const valEsc = item.Valeur.replace(/'/g, "\\'").replace(/"/g, '&quot;');
        return `
        <div style="display:flex;align-items:center;gap:8px;padding:9px 16px;border-bottom:1px solid var(--gray-border);${isInactive?'opacity:.55;':''}">
          <span style="flex:1;font-size:13px;font-weight:500;${isInactive?'text-decoration:line-through;color:var(--gray-text)':''}">${_escCfg(item.Valeur)}</span>
          ${isProtected ? `<span title="Valeur système — non modifiable" style="font-size:11px;color:#92400e;background:#fef3c7;border:1px solid #fcd34d;border-radius:4px;padding:1px 6px">🔒 système</span>` : ''}
          ${isInactive
            ? `<span class="badge badge-gray" style="font-size:10.5px">Désactivée</span>
               <button class="icon-btn" onclick="(async()=>{try{await ListesApi.update(${item.Id},'${valEsc}',${item.Ordre},1,${item.Obligatoire||0},${item.SaisieLibre||0});toast('Réactivée.','success');renderConfiguration();}catch(e){toast(e.message,'error');}})()" title="Réactiver">🔄</button>`
            : `<span class="badge badge-teal" style="font-size:10.5px">Active</span>`
          }
          ${isProtected ? '' : `<button class="icon-btn" onclick="modifierValeur(${item.Id},'${cat}','${valEsc}',${item.Ordre},${item.Actif},${item.Obligatoire||0},${item.SaisieLibre||0})" title="Modifier">✏️</button>`}
          ${!isProtected && !isInactive ? `<button class="icon-btn delete" onclick="desactiverValeur(${item.Id},'${valEsc}')" title="Désactiver">⏸️</button>` : ''}
        </div>`;
      };

      actifs.forEach(item => { html += renderItem(item, false); });

      if (inactifs.length) {
        html += `<div style="padding:6px 16px 4px;font-size:11px;font-weight:600;color:var(--gray-text);background:var(--gray-bg);border-bottom:1px solid var(--gray-border);border-top:1px solid var(--gray-border);margin-top:2px">
          Désactivées (${inactifs.length})
        </div>`;
        inactifs.forEach(item => { html += renderItem(item, true); });
      }
    }
    html += `</div></div>`;
  });
  html += `</div>`;

  // ── Panneau Champs obligatoires ──────────────────────────
  const champsDisponibles = CHAMPS_FORMULAIRE[_listesSubTab] || [];
  if (champsDisponibles.length) {
    html += `
    <div class="card" style="margin-top:20px">
      <div class="card-header">
        <div class="card-title">✅ Champs obligatoires — formulaire ${LISTES_TABS[_listesSubTab]?.label||_listesSubTab}</div>
        <button class="btn btn-primary btn-sm" onclick="_sauverChampsOblig()">💾 Enregistrer</button>
      </div>
      <div style="padding:12px 16px;font-size:12px;color:var(--gray-text);border-bottom:1px solid var(--gray-border)">
        Cochez les champs que l'utilisateur devra obligatoirement renseigner dans le formulaire.
      </div>
      <div style="padding:12px 16px;display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px 16px" id="champsObligGrid">
        ${champsDisponibles.map(ch => `
          <label style="display:flex;align-items:center;gap:8px;padding:6px 10px;border-radius:6px;cursor:pointer;font-size:13px;background:${currentOblig.includes(ch.key)?'rgba(43,123,230,.08)':'transparent'};border:1px solid ${currentOblig.includes(ch.key)?'var(--blue)':'var(--gray-border)'}">
            <input type="checkbox" class="cb-champ-oblig" value="${ch.key}" ${currentOblig.includes(ch.key)?'checked':''}>
            <span style="font-weight:${currentOblig.includes(ch.key)?'600':'400'}">${ch.label}</span>
          </label>
        `).join('')}
      </div>
    </div>`;
  }

  return html;
}

// ═══════════════════════════════════════════════════════════════
//  ONGLET NOTES INFO (banderole demandeur)
// ═══════════════════════════════════════════════════════════════
function _renderTabNotes() {
  return `<div id="cfg-notes-slot" style="padding:50px;text-align:center;color:var(--gray-text)">⏳ Chargement…</div>`;
}

async function _loadNotes() {
  try {
    const notes = await NotesInfoApi.getAll();
    const slot = document.getElementById('cfg-notes-slot');
    if (!slot) return;
    let html = `
    <div class="card">
      <div class="card-header">
        <div class="card-title">📢 Notes d'information (banderole demandeur)</div>
        <button class="btn btn-primary btn-sm" onclick="ajouterNote()">+ Ajouter une note</button>
      </div>
      <div style="padding:8px 0;font-size:13px;color:var(--gray-text);padding-left:20px">
        Ces messages apparaissent en haut de la page demandes pour les demandeurs, sous forme de banderole défilante.
      </div>
      <div style="padding:8px 0">`;
    if (!notes.length) {
      html += `<div style="padding:20px;text-align:center;color:var(--gray-text);font-size:13px">Aucune note.</div>`;
    } else {
      notes.forEach(n => {
        const faded = n.Actif == 1 ? '' : 'opacity:.5';
        html += `
        <div style="display:flex;align-items:center;gap:10px;padding:12px 20px;border-bottom:1px solid var(--gray-border);${faded}">
          <span style="flex:1;font-size:13px">${_escCfg(n.Message)}</span>
          <span class="badge ${n.Actif==1?'badge-teal':'badge-gray'}" style="font-size:10px">${n.Actif==1?'Active':'Inactive'}</span>
          <span style="font-size:11px;color:var(--gray-text)">${n.CreePar||''}</span>
          <button class="icon-btn" onclick="modifierNote(${n.Id},'${_escCfg(n.Message).replace(/'/g,"\\'")}',${n.Actif})" title="Modifier">✏️</button>
          <button class="icon-btn delete" onclick="supprimerNote(${n.Id})" title="Supprimer">🗑️</button>
        </div>`;
      });
    }
    html += `</div></div>

    <!-- Vitesse du bandeau -->
    <div class="card" style="margin-top:20px">
      <div class="card-header">
        <div class="card-title">⏱️ Vitesse du bandeau défilant</div>
      </div>
      <div style="padding:18px 22px">
        <label style="font-size:12px;font-weight:600;display:block;margin-bottom:6px">Durée d'affichage par message (secondes)</label>
        <div style="display:flex;gap:8px;align-items:center">
          <input class="form-control" type="number" id="cfg_bandeau_speed" min="2" max="60" style="width:90px" value="">
          <button class="btn btn-primary btn-sm" onclick="sauverConfigDemande('bandeau_vitesse','cfg_bandeau_speed')">💾</button>
        </div>
        <div style="font-size:11px;color:var(--gray-text);margin-top:4px">Par défaut : 8 secondes — Valeurs suggérées : 4 (rapide), 8 (normal), 15 (lent).</div>
      </div>
    </div>`;
    slot.outerHTML = html;

    // Charger les valeurs actuelles
    try {
      const configs = await ConfigApi.getAll();
      const spd = configs.find(r => r.Cle === 'bandeau_vitesse');
      if (spd) document.getElementById('cfg_bandeau_speed').value = spd.Valeur;
      else document.getElementById('cfg_bandeau_speed').value = '8';
    } catch(_) {}
  } catch(e) {
    const slot = document.getElementById('cfg-notes-slot');
    if (slot) slot.innerHTML = `<div style="color:var(--red);padding:20px">❌ ${_escCfg(e.message)}</div>`;
  }
}

async function sauverConfigDemande(cle, inputId) {
  const val = document.getElementById(inputId)?.value || '';
  try {
    await ConfigApi.set(cle, val);
    toast(`Paramètre "${cle}" sauvegardé.`, 'success');
  } catch(e) { toast(e.message, 'error'); }
}

function ajouterNote() {
  openModal('Ajouter une note d\'information', `
  <div class="form-grid cols-1">
    <div class="form-group">
      <label class="form-label">Message <span class="req">*</span></label>
      <textarea class="form-control" id="f_note_msg" rows="3" placeholder="Ex: Les demandes sont traitées sous 48h."></textarea>
    </div>
  </div>`, async () => {
    const msg = document.getElementById('f_note_msg')?.value.trim();
    if (!msg) { toast('Le message est obligatoire.', 'error'); return; }
    try {
      await NotesInfoApi.create(msg);
      toast('Note ajoutée.', 'success'); closeModal(); renderConfiguration();
    } catch(e) { toast(e.message, 'error'); }
  }, 'Ajouter');
}

function modifierNote(id, message, actif) {
  openModal('Modifier la note', `
  <div class="form-grid cols-1">
    <div class="form-group">
      <label class="form-label">Message <span class="req">*</span></label>
      <textarea class="form-control" id="f_note_msg" rows="3">${message}</textarea>
    </div>
    <div class="form-group">
      <label class="form-label">Statut</label>
      <select class="form-control" id="f_note_actif">
        <option value="1" ${actif==1?'selected':''}>Active</option>
        <option value="0" ${actif==0?'selected':''}>Inactive</option>
      </select>
    </div>
  </div>`, async () => {
    const msg = document.getElementById('f_note_msg')?.value.trim();
    if (!msg) { toast('Le message est obligatoire.', 'error'); return; }
    try {
      await NotesInfoApi.update(id, { message: msg, actif: parseInt(document.getElementById('f_note_actif').value) });
      toast('Note modifiée.', 'success'); closeModal(); renderConfiguration();
    } catch(e) { toast(e.message, 'error'); }
  });
}

function supprimerNote(id) {
  showConfirm('Supprimer cette note ?', async () => {
    try { await NotesInfoApi.delete(id); toast('Supprimée.'); renderConfiguration(); }
    catch(e) { toast(e.message, 'error'); }
  });
}

// ═══════════════════════════════════════════════════════════════
//  ONGLET 2 — ACCÈS & EMAIL
// ═══════════════════════════════════════════════════════════════
// ═══════════════════════════════════════════════════════════════
//  ONGLET 3 — SERVEUR (config.json)
// ═══════════════════════════════════════════════════════════════
async function _loadServeurConfig() {
  try {
    let cfg, configs = [];
    try {
      [cfg, configs] = await Promise.all([ConfigServeurApi.get(), ConfigApi.getAll()]);
    } catch(_) {
      // En mode Super Admin, ConfigApi.getAll() peut échouer (pas de session tenant)
      cfg = await ConfigServeurApi.get();
      configs = [];
    }
    _currentDbDriver = (cfg.base_de_donnees || {}).driver || 'sqlite';
    const slot = document.getElementById('cfg-srv-slot');
    if (slot) slot.outerHTML = _renderTabServeur(cfg);
    // Encadré « Version et mises à jour » : état, vérification et installation affichés sur place.
    const majEl = document.getElementById('cfgMajPanneau');
    if (majEl) {
      if (typeof LarkaMaj !== 'undefined') LarkaMaj.panneau(majEl);
      else majEl.innerHTML = '<span style="color:#b91c1c">Module de mise à jour non chargé (js/maj.js) — rechargez la page avec Ctrl+Maj+R.</span>';
    }

    // La barre était en « position:sticky; bottom:0 » à la fin du contenu. Or
    // .content porte un padding-bottom de 96 px : l'élément se collait au bas
    // de la BOÎTE DE CONTENU, donc 96 px au-dessus du bas visible — il flottait
    // au milieu de la page. #pageActionBar occupe une vraie place en bas de la
    // colonne .main et ne souffre pas de ce décalage.
    // La classe et les identifiants sont conservés : _sauvegarderServeur() et
    // la migration continuent de cibler « .srv-save-bar » et « srv-save-status ».
    if (typeof setPageActionBar === 'function') {
      setPageActionBar(`
        <div class="srv-save-bar">
          <span id="srv-save-status" style="font-size:12.5px;color:var(--gray-text)">
            Les modifications ne sont pas encore enregistrées.
          </span>
          <div style="display:flex;gap:10px">
            <button class="btn" onclick="_loadServeurConfig()">↩ Annuler</button>
            <button class="btn btn-primary" onclick="_sauvegarderServeur()">💾 Enregistrer config.json</button>
          </div>
        </div>`);
    }
    _bindLegifranceConfigUi(cfg.legifrance || {});
    _chargerMillesimesCarbone();
    _chargerMillesimesEnergie();
    const mp = configs.find(r => r.Cle === 'max_photos_demande');
    const ip = configs.find(r => r.Cle === 'items_par_page');
    const mpEl = document.getElementById('srv_max_photos');
    const ipEl = document.getElementById('srv_items_page');
    if (mpEl) mpEl.value = mp ? mp.Valeur : '4';
    if (ipEl) ipEl.value = ip ? ip.Valeur : '25';

    // Charger les limites d'upload
    _loadUploadLimits();
  } catch(e) {
    const slot = document.getElementById('cfg-srv-slot');
    if (slot) slot.innerHTML = `<div style="color:var(--red);padding:20px">❌ ${_escCfg(e.message)}</div>`;
  }
}

// ── Limites d'upload (SuperAdmin) ────────────────────────────────────────────
async function _loadUploadLimits() {
  const slot = document.getElementById('srv-upload-limits-content');
  if (!slot) return;
  try {
    const data = await apiRequest('plans_upload_limits');
    const isSA = !!(App.currentUser?.Role === 'Gestionnaire' || App.superAdminMode);
    const disabled = '';
    slot.innerHTML = `
      <div class="srv-grid">
        <div class="srv-field">
          <div class="srv-label">📦 Taille maximum d'un fichier (upload_max_filesize)</div>
          <div class="srv-desc">Valeur PHP actuelle : <code>${_escCfg(data.upload_max_filesize_php)}</code>. Format : 100M, 500M, 1G, 2G…</div>
          <input class="form-control" id="ul_upmax" type="text" value="${_escCfg(data.upload_max_filesize_php)}" placeholder="500M" style="font-family:monospace">
        </div>
        <div class="srv-field">
          <div class="srv-label">📥 Taille maximum d'une requête POST (post_max_size)</div>
          <div class="srv-desc">Valeur PHP actuelle : <code>${_escCfg(data.post_max_size_php)}</code>. Doit être ≥ upload_max_filesize.</div>
          <input class="form-control" id="ul_postmax" type="text" value="${_escCfg(data.post_max_size_php)}" placeholder="500M" style="font-family:monospace">
        </div>
        <div class="srv-field">
          <div class="srv-label">🧠 Limite mémoire PHP (memory_limit)</div>
          <div class="srv-desc">Valeur actuelle : <code>${_escCfg(data.memory_limit_php)}</code>. Mettre <code>-1</code> pour illimité (déconseillé).</div>
          <input class="form-control" id="ul_mem" type="text" value="${_escCfg(data.memory_limit_php)}" placeholder="512M" style="font-family:monospace">
        </div>
        <div class="srv-field">
          <div class="srv-label">⏱️ Temps d'exécution max (max_execution_time, secondes)</div>
          <div class="srv-desc">Valeur actuelle : <code>${_escCfg(data.max_execution_time_php)}</code>. Mettre <code>0</code> pour illimité.</div>
          <input class="form-control" id="ul_exec" type="text" value="${_escCfg(data.max_execution_time_php)}" placeholder="300" style="font-family:monospace">
        </div>
        <div class="srv-field">
          <div class="srv-label">⏲️ Temps d'attente upload (max_input_time, secondes)</div>
          <div class="srv-desc">Valeur actuelle : <code>${_escCfg(data.max_input_time_php)}</code>. Mettre <code>-1</code> pour pas de limite.</div>
          <input class="form-control" id="ul_input" type="text" value="${_escCfg(data.max_input_time_php)}" placeholder="300" style="font-family:monospace">
        </div>
      </div>
      <div style="margin-top:14px;padding:10px 12px;background:var(--gray-bg);border-radius:8px;font-size:12px;color:var(--gray-text)">
        <strong>Fichier .user.ini :</strong> ${data.user_ini_exists ? '<code>'+_escCfg(data.user_ini_file)+'</code> ✅' : 'Sera créé à la racine du projet'}<br>
        <strong>SAPI PHP :</strong> <code>${_escCfg(data.sapi)}</code><br>
        <span style="opacity:.85">${_escCfg(data.note)}</span>
      </div>
      <div style="margin-top:12px;display:flex;gap:8px;justify-content:flex-end">
        <button class="btn btn-secondary btn-sm" onclick="_loadUploadLimits()">↩ Recharger</button>
        <button class="btn btn-primary btn-sm" onclick="_saveUploadLimits()">💾 Enregistrer .user.ini</button>
      </div>
    `;
  } catch(e) {
    slot.innerHTML = `<div style="color:var(--red);padding:14px">❌ ${_escCfg(e.message)}</div>`;
  }
}

async function _saveUploadLimits() {
  const data = {
    upload_max_filesize: document.getElementById('ul_upmax').value.trim(),
    post_max_size:       document.getElementById('ul_postmax').value.trim(),
    memory_limit:        document.getElementById('ul_mem').value.trim(),
    max_execution_time:  document.getElementById('ul_exec').value.trim(),
    max_input_time:      document.getElementById('ul_input').value.trim(),
  };
  try {
    const res = await apiRequest('plans_upload_limits', 'POST', data);
    toast('Limites enregistrées. ' + (res.note || ''), 'success');
    _loadUploadLimits();
  } catch(e) {
    toast('Erreur : ' + e.message, 'error');
  }
}


const LEGIFRANCE_ENV_DEFAULTS = {
  sandbox: {
    oauth_url: 'https://sandbox-oauth.piste.gouv.fr/api/oauth/token',
    api_base_url: 'https://sandbox-api.piste.gouv.fr/dila/legifrance/lf-engine-app',
    label: 'Sandbox officielle PISTE',
  },
  production: {
    oauth_url: 'https://oauth.piste.gouv.fr/api/oauth/token',
    api_base_url: 'https://api.piste.gouv.fr/dila/legifrance/lf-engine-app',
    label: 'Production officielle PISTE',
  },
};

function _bindLegifranceConfigUi(lfCfg = {}) {
  const envEl = document.getElementById('lf_env');
  const oauthEl = document.getElementById('lf_oauth');
  const apiEl = document.getElementById('lf_api');
  const hintEl = document.getElementById('lf_env_hint');
  if (!envEl || !oauthEl || !apiEl) return;

  const applyUrls = (force = false) => {
    const env = envEl.value === 'production' ? 'production' : 'sandbox';
    const defaults = LEGIFRANCE_ENV_DEFAULTS[env];
    const knownValues = new Set(Object.values(LEGIFRANCE_ENV_DEFAULTS).flatMap(v => [v.oauth_url, v.api_base_url]));

    if (force || !oauthEl.value.trim() || knownValues.has(oauthEl.value.trim())) {
      oauthEl.value = defaults.oauth_url;
    }
    if (force || !apiEl.value.trim() || knownValues.has(apiEl.value.trim())) {
      apiEl.value = defaults.api_base_url;
    }
    oauthEl.placeholder = defaults.oauth_url;
    apiEl.placeholder = defaults.api_base_url;
    if (hintEl) {
      hintEl.innerHTML = `↔ <strong>${defaults.label}</strong> sélectionnée. Les URLs OAuth et API sont alignées automatiquement sur les hôtes officiels.`;
    }
  };

  envEl.addEventListener('change', () => applyUrls(true));
  applyUrls(false);
}

function _renderTabServeur(cfg) {
  const s  = cfg.serveur         || {};
  const hs = s.https             || {};
  const db = cfg.base_de_donnees || {};
  const sec= cfg.securite        || {};
  const ses= cfg.session         || {};
  const co = cfg.cors            || {};
  const sh = cfg.securite_http   || {};
  const dc = cfg.documents       || {};
  const lg = cfg.logs            || {};
  const bk = cfg.sauvegardes     || {};
  const ms = cfg.microsoft_oauth || {};
  const gg = cfg.google_oauth    || {};
  const rl = cfg.oauth_relay     || {};
  const lf = cfg.legifrance      || {};
  const cb = cfg.carbone         || {};
  const ce = cfg.carbone_energie || {};
  const ai = cfg.assistant       || {};
  const pu = cfg.push            || {};
  const ext= cfg.extensions      || {};

  const drv = (db.driver || 'sqlite');

  /* ── helpers locaux ── */
  function field(id, label, val, desc, exemple, opts={}) {
    const isSecret = opts.secret;
    const monospace = opts.mono !== false;
    const rows = opts.rows;

    // ── État « variable d'environnement (.env) » ───────────────────────────
    // opts.secretPath = champ SECRET masqué ; opts.envPath = champ de CONNEXION
    // visible (client_id, tenant_id, redirect URI…). Les deux lisent les
    // drapeaux renvoyés par l'API : *_env, *_env_locked, *_env_name, *_set.
    const envPath = opts.secretPath || opts.envPath;
    const secretMode = !!opts.secretPath; // masquage réservé aux vrais secrets
    let envBadge = '';
    let locked = false;
    let placeholder = opts.placeholder || '';
    if (envPath) {
      const [sec, key] = envPath.split('.');
      const sObj = (cfg[sec] || {});
      const inEnv   = !!sObj[key + '_env'];
      const isLock  = !!sObj[key + '_env_locked'];
      const isSet   = !!sObj[key + '_set'];
      const envName = sObj[key + '_env_name'] || '';
      locked = isLock;
      if (isLock) {
        envBadge = `<span class="srv-envbadge srv-envbadge-lock" title="Géré par l'environnement système, non modifiable ici">🔒 Variable système ${_escCfg(envName)}</span>`;
        placeholder = 'Géré par l\'environnement système';
      } else if (inEnv || isSet) {
        envBadge = `<span class="srv-envbadge srv-envbadge-ok" title="Stocké dans le fichier .env (permissions 600)">🔐 Stocké dans .env${envName?' · '+_escCfg(envName):''}</span>`;
        if (secretMode) placeholder = '•••••••• (laisser tel quel pour conserver)';
      } else {
        envBadge = `<span class="srv-envbadge srv-envbadge-todo" title="Sera enregistré dans le fichier .env">🔑 À définir → .env${envName?' · '+_escCfg(envName):''}</span>`;
      }
    }

    // En lecture seule (verrou système) : on masque la valeur pour un secret,
    // mais on la GARDE visible pour un champ de connexion (non sensible).
    const hideVal = locked && secretMode;
    const lockAttr = locked ? 'readonly disabled' : '';
    const input = rows
      ? `<textarea class="form-control" id="${id}" rows="${rows}" ${lockAttr} style="${monospace?'font-family:monospace;':''}${locked?'opacity:.6;cursor:not-allowed;':''}">${hideVal?'':_escCfg(String(val??''))}</textarea>`
      : `<input class="form-control" id="${id}" type="text" value="${hideVal?'':_escCfg(String(val??''))}" ${lockAttr}
           style="${monospace?'font-family:monospace;':''}${isSecret?'color:var(--gray-text);':''}${locked?'opacity:.6;cursor:not-allowed;':''}" placeholder="${_escCfg(placeholder)}">`;
    return `<div class="srv-field">
      <div class="srv-label">${label}${envBadge}</div>
      ${desc ? `<div class="srv-desc">${desc}</div>` : ''}
      ${exemple ? `<code class="srv-ex">ex : ${_escCfg(exemple)}</code>` : ''}
      ${input}
    </div>`;
  }
  function select(id, label, val, options, desc) {
    const opts = options.map(o => `<option value="${o}" ${val==o?'selected':''}>${o}</option>`).join('');
    return `<div class="srv-field">
      <div class="srv-label">${label}</div>
      ${desc ? `<div class="srv-desc">${desc}</div>` : ''}
      <select class="form-control" id="${id}" style="font-family:monospace">${opts}</select>
    </div>`;
  }
  function toggle(id, label, val, desc) {
    return `<div class="srv-field">
      <div class="srv-label">${label}</div>
      ${desc ? `<div class="srv-desc">${desc}</div>` : ''}
      <div class="srv-toggle">
        <input type="checkbox" id="${id}" ${val?'checked':''}>
        <label for="${id}" id="${id}_lbl">${val?'✅ Activé':'⬜ Désactivé'}</label>
      </div>
    </div>`;
  }
  function sectionTitle(icon, title) {
    return `<div class="srv-section-title">${icon} ${title}</div>`;
  }

  return `
  <style>
    .srv-section {
      background: var(--white, #fff);
      border: 1px solid var(--gray-border);
      border-radius: 12px;
      padding: 0 22px 20px;
      margin-bottom: 16px;
      overflow: hidden;
    }
    .srv-section-title {
      font-size: 13px; font-weight: 700; color: var(--text);
      padding: 14px 22px; margin: 0 -22px 18px;
      display: flex; align-items: center; gap: 10px;
      background: var(--gray-bg);
      border-bottom: 1px solid var(--gray-border);
    }
    .srv-grid   { display:grid; grid-template-columns:1fr 1fr;     gap:16px 20px; }
    .srv-grid-3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:16px 20px; }
    .srv-grid-1 { display:grid; grid-template-columns:1fr;         gap:16px; }
    @media(max-width:780px){ .srv-grid,.srv-grid-3 { grid-template-columns:1fr; } }
    .srv-field { display:flex; flex-direction:column; gap:5px; }
    .srv-label { font-size:12.5px; font-weight:700; color:var(--text); }
    .srv-desc  { font-size:11.5px; color:var(--gray-text); line-height:1.55; }
    .srv-desc code { background:var(--gray-bg); padding:1px 6px; border-radius:4px; font-size:11px; color:var(--blue); border:1px solid var(--gray-border); }
    .srv-desc strong { color:var(--text); }
    .srv-ex { font-size:10.5px; font-family:'DM Mono',monospace; background:var(--gray-bg); color:var(--gray-text); padding:3px 8px; border-radius:4px; display:inline-block; border:1px solid var(--gray-border); }
    .srv-field .form-control { margin-top:2px; border-radius:8px; font-size:13px; padding:9px 12px; border:1.5px solid var(--gray-border); background:var(--white, #fff); color:var(--text); transition:border-color .15s, box-shadow .15s; }
    .srv-field .form-control:focus { border-color:var(--blue); box-shadow:0 0 0 3px rgba(43,123,230,.12); outline:none; }
    .srv-toggle { display:flex; align-items:center; gap:12px; padding:4px 0; }
    .srv-toggle input[type=checkbox] { appearance:none; -webkit-appearance:none; width:38px; height:20px; border-radius:11px; background:var(--gray-border); cursor:pointer; position:relative; transition:background .2s; flex-shrink:0; border:none; outline:none; }
    .srv-toggle input[type=checkbox]::after { content:''; position:absolute; top:2px; left:2px; width:16px; height:16px; border-radius:50%; background:#fff; transition:transform .2s; box-shadow:0 1px 3px rgba(0,0,0,.18); }
    .srv-toggle input[type=checkbox]:checked { background:var(--blue); }
    .srv-toggle input[type=checkbox]:checked::after { transform:translateX(18px); }
    .srv-toggle label { font-size:12.5px; cursor:pointer; color:var(--text); font-weight:500; }
    .db-tabs { display:flex; border:1px solid var(--gray-border); border-radius:8px; overflow:hidden; margin-bottom:16px; background:var(--gray-bg); }
    .db-tab { flex:1; padding:10px 12px; text-align:center; font-size:12.5px; font-weight:600; background:transparent; color:var(--gray-text); cursor:pointer; border:none; transition:all .15s; }
    .db-tab:not(:last-child) { border-right:1px solid var(--gray-border); }
    .db-tab:hover { color:var(--blue); }
    .db-tab.active { background:var(--blue); color:#fff; }
    /* Simple rangée : le positionnement est assuré par #pageActionBar. */
    .srv-save-bar { display:flex; align-items:center; justify-content:space-between; gap:12px; width:100%; }
    .srv-alert { padding:11px 16px; border-radius:8px; font-size:12.5px; display:flex; align-items:flex-start; gap:8px; line-height:1.55; }
    .srv-alert code { background:rgba(0,0,0,.06); padding:1px 5px; border-radius:3px; font-size:11px; }
    /* Fond ET texte issus de la même palette : l'encart reste lisible qu'il soit
       posé dans une carte claire ou directement sur le fond bleu nuit de
       .content (body.app-mode). Un fond quasi transparent + var(--text) rendait
       le texte invisible dans le second cas. */
    .srv-alert-warn { background:var(--chip-warn-bg); color:var(--chip-warn-fg); border:1px solid var(--chip-warn-bd); }
    .srv-alert-info { background:var(--chip-info-bg); color:var(--chip-info-fg); border:1px solid var(--chip-info-bd); }
    .srv-envbadge { display:inline-block; margin-left:8px; font-size:10px; font-weight:600; padding:2px 7px; border-radius:20px; vertical-align:middle; letter-spacing:.2px; white-space:nowrap; }
    .srv-envbadge-ok   { background:var(--chip-ok-bg); color:var(--chip-ok-fg); border:1px solid var(--chip-ok-bd); }
    .srv-envbadge-lock { background:var(--chip-info-bg); color:var(--chip-info-fg); border:1px solid var(--chip-info-bd); }
    .srv-envbadge-todo { background:var(--chip-warn-bg); color:var(--chip-warn-fg); border:1px solid var(--chip-warn-bd); }
  </style>
  <div style="display:flex;flex-direction:column;gap:0">

    <!-- ── Serveur ───────────────────────────────────── -->
    <div class="srv-section">
      ${sectionTitle('🌐','Serveur')}
      <div class="srv-grid-3">
        ${field('s_host','Hôte d\'écoute', s.host,
          'Utilisez <code>0.0.0.0</code> pour écouter sur toutes les interfaces réseau, <code>127.0.0.1</code> pour localhost uniquement.',
          '0.0.0.0')}
        ${field('s_port','Port HTTP', s.port,
          'Port sur lequel PHP sera accessible en interne. Généralement 8000.',
          '8000')}
        ${select('s_env','Environnement', s.env, ['dev','prod'],
          '<strong>dev</strong> : erreurs détaillées dans les logs. <strong>prod</strong> : erreurs génériques (recommandé en production).')}
      </div>
      <div class="srv-grid-3" style="margin-top:16px">
        ${toggle('s_https','HTTPS activé', hs.actif,
          'Nécessite socat ou caddy installé sur le serveur. Requis pour la caméra sur mobile.')}
        ${field('s_https_port','Port HTTPS', hs.port,
          'Port HTTPS exposé aux clients. Généralement 8443.','8443')}
        ${field('s_https_cert','Certificat (chemin)', hs.cert,
          'Chemin relatif au dossier du projet vers le fichier .pem du certificat.',
          'adresse-du-serveur.pem')}
      </div>
      <div class="srv-grid-1" style="margin-top:16px">
        ${field('s_https_key','Clé privée (chemin)', hs.key,
          'Chemin relatif au dossier du projet vers la clé privée .pem.',
          'adresse-du-serveur-key.pem')}
      </div>
    </div>

    <!-- ── Base de données (unifié config + migration) ── -->
    <div class="srv-section" id="srv-db-unified">
      ${sectionTitle('🗄️','Base de données')}

      <!-- Badge driver actuel -->
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;flex-wrap:wrap">
        <span style="font-size:12px;color:var(--gray-text)">Driver actuel :</span>
        <span style="display:inline-flex;align-items:center;gap:5px;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:700;background:var(--green-bg,#e6f4ea);color:var(--green,#1a7a3f);border:1px solid var(--green-border,#a8d5b5)">
          ${{sqlite:'🗃️ SQLite', pgsql:'🐘 PostgreSQL', mariadb:'🐬 MariaDB'}[drv] || drv}
        </span>
        <span id="db-mode-badge" style="font-size:11px;padding:3px 10px;border-radius:12px;display:none"></span>
      </div>

      <div class="db-tabs" id="db-driver-tabs">
        <button class="db-tab ${drv==='sqlite'?'active':''}"   onclick="_switchDbDriver('sqlite')">🗃️ SQLite</button>
        <button class="db-tab ${drv==='pgsql'?'active':''}"    onclick="_switchDbDriver('pgsql')">🐘 PostgreSQL</button>
        <button class="db-tab ${drv==='mariadb'?'active':''}"  onclick="_switchDbDriver('mariadb')">🐬 MariaDB / MySQL</button>
      </div>

      <!-- === Panneau driver actuel : édition normale === -->
      <!-- SQLite -->
      <div id="db-panel-sqlite" style="display:${drv==='sqlite'?'block':'none'}">
        <div class="srv-alert srv-alert-info" style="margin-bottom:14px">
          ℹ️ SQLite est idéal pour une installation locale ou mono-utilisateur.
          Aucun serveur externe requis — la base est un simple fichier.
        </div>
        <div class="srv-grid-1">
          ${field('db_path','Chemin du fichier .db', db.path,
            'Chemin relatif au dossier du projet. Le dossier est créé automatiquement.',
            'data/gmao.db')}
        </div>
      </div>

      <!-- PostgreSQL -->
      <div id="db-panel-pgsql" style="display:${drv==='pgsql'?'block':'none'}">
        <div class="srv-alert srv-alert-info" style="margin-bottom:14px">
          ℹ️ PostgreSQL est recommandé pour un usage multi-utilisateurs ou en production.
          Requiert l'extension PHP <code>pdo_pgsql</code> et un serveur PostgreSQL accessible.
        </div>
        <div class="srv-grid">
          ${field('db_host','Hôte', db.host, 'Adresse IP ou nom d\'hôte du serveur PostgreSQL.','127.0.0.1')}
          ${field('db_port','Port', db.port, 'Port PostgreSQL par défaut.','5432')}
          ${field('db_name','Nom de la base', db.dbname, 'La base doit exister et l\'utilisateur doit avoir les droits CREATE TABLE.','gmao')}
          ${field('db_user','Utilisateur', db.user, 'Utilisateur PostgreSQL disposant des droits sur la base.','gmao_user')}
        </div>
        <div class="srv-grid" style="margin-top:14px">
          ${field('db_pass','Mot de passe', db.password, 'Mot de passe de l\'utilisateur PostgreSQL. Défaut installation : <code>postgres</code>.','postgres', {secret:true, secretPath:'base_de_donnees.password'})}
          ${select('db_ssl','Mode SSL', db.sslmode, ['disable','allow','prefer','require','verify-ca','verify-full'],
            '<strong>prefer</strong> : SSL si disponible. <strong>require</strong> : SSL obligatoire. <strong>disable</strong> : pas de SSL (déconseillé en prod).')}
        </div>
      </div>

      <!-- MariaDB / MySQL -->
      <div id="db-panel-mariadb" style="display:${drv==='mariadb'?'block':'none'}">
        <div class="srv-alert srv-alert-info" style="margin-bottom:14px">
          ℹ️ Compatible MariaDB 10.3+ et MySQL 8+. Requiert l'extension PHP <code>pdo_mysql</code>.
        </div>
        <div class="srv-grid">
          ${field('db_host_m','Hôte', db.host, 'Adresse IP ou nom d\'hôte du serveur MySQL/MariaDB.','127.0.0.1')}
          ${field('db_port_m','Port', db.port, 'Port MySQL/MariaDB par défaut.','3306')}
          ${field('db_name_m','Nom de la base', db.dbname, 'La base doit exister.','gmao')}
          ${field('db_user_m','Utilisateur', db.user, 'Utilisateur avec droits complets sur la base.','gmao_user')}
        </div>
        <div class="srv-grid-1" style="margin-top:14px">
          ${field('db_pass_m','Mot de passe', db.password, 'Défaut MariaDB/MySQL : <code>root</code> (sans mot de passe sur certaines installations).','root', {secret:true, secretPath:'base_de_donnees.password'})}
        </div>
      </div>

      <!-- === Panneau migration (affiché quand driver ≠ actuel) === -->
      <div id="db-migration-flow" style="display:none">
        <div class="srv-alert srv-alert-warn" style="margin-bottom:16px">
          🔄 <strong>Changement de driver détecté.</strong> Suivez les étapes ci-dessous pour migrer
          vos données en toute sécurité vers le nouveau driver.
        </div>

        <!-- Étape 1 : Configuration destination -->
        <div class="mig-step" id="mig-step-config">
          <div class="mig-step-header">
            <span class="mig-step-num">1</span>
            <span class="mig-step-title">Configurer la destination</span>
            <span class="mig-step-status" id="mig-status-config"></span>
          </div>
          <div class="mig-step-body" id="mig-config-fields">
            <!-- Rempli dynamiquement par _switchDbDriver -->
          </div>
        </div>

        <!-- Étape 2 : Test de connexion -->
        <div class="mig-step" id="mig-step-test">
          <div class="mig-step-header">
            <span class="mig-step-num">2</span>
            <span class="mig-step-title">Tester la connexion</span>
            <span class="mig-step-status" id="mig-status-test"></span>
          </div>
          <div class="mig-step-body">
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
              <button class="btn" id="mig-btn-test" onclick="_migUnifiedTest()">🔌 Tester la connexion</button>
              <span id="mig-test-result" style="font-size:12px;color:var(--gray-text)">Remplissez les champs ci-dessus puis testez.</span>
            </div>
            <div id="mig-test-detail" style="margin-top:10px"></div>
          </div>
        </div>

        <!-- Étape 3 : Sauvegarde -->
        <div class="mig-step" id="mig-step-backup" style="opacity:0.5;pointer-events:none">
          <div class="mig-step-header">
            <span class="mig-step-num">3</span>
            <span class="mig-step-title">Sauvegarde de la base actuelle</span>
            <span class="mig-step-status" id="mig-status-backup"></span>
          </div>
          <div class="mig-step-body">
            <div class="srv-field">
              <div class="srv-label">Dossier de sauvegarde (optionnel)</div>
              <div class="srv-desc">
                Chemin où enregistrer la sauvegarde avant migration. Laissez vide pour le dossier par défaut
                <code>data/backups/</code>.
              </div>
              <input class="form-control" id="mig_backup_path"
                placeholder="Par défaut : data/backups/pre_migration_YYYYMMDD_HHmmss.db"
                style="font-family:monospace;margin-top:6px">
            </div>
          </div>
        </div>

        <!-- Étape 4 : Lancer la migration -->
        <div class="mig-step" id="mig-step-run" style="opacity:0.5;pointer-events:none">
          <div class="mig-step-header">
            <span class="mig-step-num">4</span>
            <span class="mig-step-title">Migrer les données</span>
            <span class="mig-step-status" id="mig-status-run"></span>
          </div>
          <div class="mig-step-body">
            <div class="srv-alert srv-alert-info" style="margin-bottom:12px;font-size:12px">
              ℹ️ La migration copie <strong>toutes vos données</strong> vers la nouvelle base,
              puis met à jour <code>config.json</code> automatiquement. En cas d'échec, rien n'est modifié.
            </div>
            <button class="btn btn-primary" id="mig-btn-run" onclick="_migUnifiedRun()"
              style="background:#1a7a3f;border-color:#1a7a3f">
              🚀 Lancer la migration
            </button>
          </div>
        </div>

        <!-- Résultat détaillé -->
        <div id="mig-result" style="margin-top:16px"></div>
      </div>
    </div>

    <style>
      .mig-step { background:var(--gray-bg);border-radius:8px;padding:16px 18px;margin-bottom:12px;border:1px solid var(--gray-border);transition:opacity .2s }
      .mig-step-header { display:flex;align-items:center;gap:10px;margin-bottom:10px }
      .mig-step-num { width:26px;height:26px;border-radius:50%;background:var(--primary);color:#fff;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;flex-shrink:0 }
      .mig-step-title { font-size:13px;font-weight:700;color:var(--text) }
      .mig-step-status { font-size:11px;margin-left:auto;padding:2px 8px;border-radius:10px }
      .mig-step-body { padding-left:36px }
      .mig-step.step-ok { border-color:var(--green-border,#a8d5b5) }
      .mig-step.step-ok .mig-step-num { background:var(--green,#1a7a3f) }
      .mig-step.step-err { border-color:#f5c6cb }
      .mig-step.step-err .mig-step-num { background:var(--red,#c62828) }
    </style>

    <!-- ── Sécurité ──────────────────────────────────── -->
    <div class="srv-section">
      ${sectionTitle('🔐','Sécurité')}
      <div class="srv-grid">
        ${field('sec_key','Clé secrète (secret_key)', sec.secret_key,
          'Utilisée pour signer les sessions et les tokens OAuth. <strong>Changez absolument cette valeur en production.</strong> Minimum 32 caractères aléatoires.',
          '', {secret:true, secretPath:'securite.secret_key'})}
        ${field('sec_session','Durée de session (heures)', sec.session_duree_heures,
          'Durée avant expiration automatique de la session utilisateur.','24')}
      </div>
      <div class="srv-grid" style="margin-top:14px">
        ${field('sec_origin','Allowed Origin (CORS simple)', sec.allowed_origin,
          'Utilisé comme fallback si la section cors n\'est pas configurée. <code>*</code> autorise tous les origines (OK en dev).',
          'https://gmao.entreprise.fr')}
      </div>
    </div>

    <!-- ── Session cookies ───────────────────────────── -->
    <div class="srv-section">
      ${sectionTitle('🍪','Session & Cookies')}
      <div class="srv-grid">
        ${toggle('ses_secure','Cookie Secure', ses.cookie_secure,
          'Envoyer le cookie uniquement sur HTTPS. <strong>Activer uniquement si HTTPS est activé.</strong>')}
        ${toggle('ses_httponly','Cookie HttpOnly', ses.cookie_httponly,
          'Interdit l\'accès au cookie via JavaScript. Recommandé activé pour éviter le vol de session XSS.')}
        ${select('ses_samesite','SameSite', ses.cookie_samesite, ['Lax','Strict','None'],
          '<strong>Lax</strong> : équilibre sécurité/compatibilité. <strong>Strict</strong> : plus sûr. <strong>None</strong> : nécessite Secure=true.')}
      </div>
    </div>

    <!-- ── CORS ──────────────────────────────────────── -->
    <div class="srv-section">
      ${sectionTitle('🌍','CORS (Cross-Origin Resource Sharing)')}
      <div class="srv-alert srv-alert-warn" style="margin-bottom:14px">
        ⚠️ En production, listez précisément les origines autorisées. Ne jamais utiliser <code>*</code>
        avec <code>allow_credentials: true</code>.
      </div>
      <div class="srv-grid-1">
        ${field('cors_origins','Origines autorisées', Array.isArray(co.origins)?co.origins.join(', '):co.origins,
          'Domaines autorisés à appeler l\'API, séparés par des virgules.',
          'https://gmao.entreprise.fr')}
      </div>
      <div class="srv-grid" style="margin-top:14px">
        ${toggle('cors_creds','Allow Credentials', co.allow_credentials,
          'Autoriser l\'envoi des cookies dans les requêtes cross-origin. Nécessite une liste d\'origines explicite (pas *).')}
      </div>
    </div>

    <!-- ── En-têtes sécurité HTTP ────────────────────── -->
    <div class="srv-section">
      ${sectionTitle('🛡️','En-têtes de sécurité HTTP')}
      <div class="srv-grid-1">
        ${field('sh_csp','Content-Security-Policy', sh.csp,
          'Limite les sources autorisées pour scripts, styles, images, etc. Aide à prévenir le XSS.',
          "default-src 'self'; img-src 'self' data:; object-src 'none'")}
      </div>
      <div class="srv-grid" style="margin-top:14px">
        ${toggle('sh_hsts','HSTS activé', sh.hsts,
          'Force HTTPS sur tous les navigateurs. <strong>Activer uniquement si le site est 100% HTTPS.</strong>')}
        ${field('sh_hsts_age','HSTS max-age (secondes)', sh.hsts_max_age,
          '31536000 = 1 an. Durée pendant laquelle le navigateur mémorise la politique HTTPS.','31536000')}
        ${select('sh_xframe','X-Frame-Options', sh.x_frame_options, ['DENY','SAMEORIGIN'],
          '<strong>DENY</strong> : empêche tout embedding en iframe. <strong>SAMEORIGIN</strong> : autorise le même domaine.')}
        ${select('sh_referrer','Referrer-Policy', sh.referrer_policy,
          ['no-referrer','strict-origin','strict-origin-when-cross-origin','no-referrer-when-downgrade'],
          '<strong>no-referrer</strong> : aucune info de provenance transmise. Recommandé pour la confidentialité.')}
      </div>
    </div>

    <!-- ── Documents / uploads ───────────────────────── -->
    <div class="srv-section">
      ${sectionTitle('📎','Documents & Uploads')}
      <div class="srv-grid-3">
        ${field('doc_max','Fichiers max par catégorie', dc.max_par_categorie,
          'Nombre maximal de fichiers par catégorie (photo, plan, facture…) par entité.','4')}
        ${field('doc_size','Taille max par fichier (Mo)', dc.taille_max_mo,
          'Taille maximale d\'un fichier uploadé, en mégaoctets.','25')}
        ${toggle('doc_dedup','Dédoublonnage SHA-256', dc.dedup_sha256,
          'Détecte les fichiers identiques par leur empreinte et évite les doublons en base.')}
      </div>
      <div class="srv-grid-1" style="margin-top:14px">
        ${field('doc_mime','Types MIME autorisés', Array.isArray(dc.mime_autorises)?dc.mime_autorises.join(', '):dc.mime_autorises,
          'Types MIME acceptés à l\'upload, séparés par des virgules.',
          'application/pdf, image/png, image/jpeg, image/webp')}
      </div>
    </div>

    <!-- ── Sauvegardes : déplacées dans Super Admin > Édit tenant ── -->

    <!-- ── Logs ──────────────────────────────────────── -->
    <div class="srv-section">
      ${sectionTitle('📋','Logs')}
      <div class="srv-grid">
        ${toggle('lg_actif','Logs activés', lg.actif, 'Active l\'écriture des erreurs PHP dans un fichier de log.')}
        ${field('lg_dossier','Dossier des logs', lg.dossier,
          'Chemin relatif au projet.','data/logs')}
        ${select('lg_niveau','Niveau de log', lg.niveau, ['debug','info','warning','error'],
          '<strong>debug</strong> : très verbeux. <strong>info</strong> : normal. <strong>error</strong> : uniquement les erreurs.')}
        ${field('lg_rotate','Rotation (jours)', lg.rotation_jours,
          'Durée de rétention des logs. Nécessite logrotate ou cron.','14')}
      </div>
    </div>

    <!-- ── OAuth Microsoft ───────────────────────────── -->
    <div class="srv-section">
      ${sectionTitle('🪟','Connexion Microsoft (Azure AD)')}
      <div class="srv-grid">
        ${toggle('ms_actif','Microsoft OAuth activé', ms.actif,
          'Active le bouton "Se connecter avec Microsoft" sur la page de login.')}
        ${toggle('sp_actif','SharePoint / OneDrive', (cfg.sharepoint || {}).actif !== false,
          'Permet de lier des fichiers SharePoint aux fiches (raccourcis, sans copie). Nécessite la connexion Microsoft. Désactivé : documents stockés dans Larka uniquement, et la connexion Microsoft ne demande plus l\'accès aux fichiers. Les utilisateurs doivent se reconnecter après un changement.')}
        ${field('ms_client','Client ID', ms.client_id,
          'L\'identifiant de votre application Azure Active Directory.',
          'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx', {envPath:'microsoft_oauth.client_id'})}
        ${field('ms_tenant','Tenant ID', ms.tenant_id,
          '<strong>common</strong> = tous les comptes Microsoft. Ou votre tenant spécifique.',
          'common', {envPath:'microsoft_oauth.tenant_id'})}
        ${field('ms_secret','Client Secret', ms.client_secret,
          'Secret généré dans Azure AD. Jamais exposé côté client.','', {secret:true, secretPath:'microsoft_oauth.client_secret'})}
      </div>
      <div class="srv-grid" style="margin-top:14px">
        ${field('ms_redirect','Redirect URI (PC)', ms.redirect_uri,
          'URL de retour OAuth enregistrée dans Azure AD.',
          'https://adresse-du-serveur:8443/oauth/microsoft', {envPath:'microsoft_oauth.redirect_uri'})}
        ${field('ms_redirect_m','Redirect URI (Mobile)', ms.redirect_uri_mobile,
          'Si différente de l\'URI PC (ex: IP locale du mobile). Laisser vide si identique.','', {envPath:'microsoft_oauth.redirect_uri_mobile'})}
      </div>
    </div>

    <!-- ── OAuth Google ──────────────────────────────── -->
    <div class="srv-section">
      ${sectionTitle('🔵','Connexion Google')}
      <div class="srv-grid">
        ${toggle('gg_actif','Google OAuth activé', gg.actif,
          'Active le bouton "Se connecter avec Google" sur la page de login.')}
        ${field('gg_client','Client ID', gg.client_id,
          'L\'identifiant de votre application Google Cloud Console.',
          'xxxx.apps.googleusercontent.com', {envPath:'google_oauth.client_id'})}
        ${field('gg_secret','Client Secret', gg.client_secret,
          'Secret généré dans Google Cloud Console.','', {secret:true, secretPath:'google_oauth.client_secret'})}
      </div>
      <div class="srv-grid" style="margin-top:14px">
        ${field('gg_redirect','Redirect URI (PC)', gg.redirect_uri,
          'URL de retour enregistrée dans Google Cloud Console.',
          'https://adresse-du-serveur:8443/oauth/google', {envPath:'google_oauth.redirect_uri'})}
        ${field('gg_redirect_m','Redirect URI (Mobile)', gg.redirect_uri_mobile,
          'Laisser vide si identique à l\'URI PC.','', {envPath:'google_oauth.redirect_uri_mobile'})}
      </div>
    </div>


    <!-- ── Légifrance / France ───────────────────────── -->
    <!-- ── Facteurs carbone (ADEME) ──────────────────── -->
    <div class="srv-section" id="srv-carbone-section">
      ${sectionTitle('🌱','Facteurs carbone mobilité (ADEME)')}
      <div class="srv-alert srv-alert-info" style="margin-bottom:14px">
        ℹ️ <span>Sans cette source, Larka calcule avec ses <strong>valeurs de repli intégrées</strong> :
        la page Mobilité reste pleinement fonctionnelle. Activer la source permet de suivre les mises à jour
        de la Base Carbone sans redéployer.</span>
      </div>
      <div class="srv-grid">
        ${toggle('cb_actif','Source ADEME activée', cb.actif,
          'Si désactivé, aucun appel réseau n’est effectué et les valeurs intégrées sont utilisées.')}
        ${field('cb_cache','Durée du cache (heures)', cb.cache_heures || 24,
          'Les facteurs ADEME évoluent rarement. 24 à 168 h est raisonnable.', '')}
      </div>
      <div class="srv-grid-1" style="margin-top:14px">
        ${field('cb_key','Clé API Impact CO2', cb.impactco2_key || '',
          'Facultative mais recommandée : sans clé l’API répond, mais l’ADEME se réserve le droit de couper l’accès anonyme. Clé gratuite sur demande à <code>impactco2@ademe.fr</code>.',
          '', {secret:true, secretPath:'carbone.impactco2_key', placeholder:'••••••••'})}
      </div>
      <div class="srv-grid" style="margin-top:14px">
        ${field('cb_url','URL de l’API', cb.impactco2_url || 'https://impactco2.fr/api/v1/transport',
          'Endpoint transport de l’API Impact CO2.', '')}
        ${toggle('cb_tls','Vérification TLS', cb.tls_verify !== false,
          'Laisser activé. À ne désactiver que derrière un proxy interceptant le TLS.')}
      </div>
      <div style="display:flex;gap:8px;margin-top:14px;flex-wrap:wrap">
        <button class="btn" onclick="_testerCarbone()">🔌 Tester la connexion</button>
        <button class="btn" onclick="_purgerCacheCarbone()">🧹 Vider le cache</button>
        <span id="cb_test_result" style="font-size:12px;align-self:center"></span>
      </div>
      <div style="margin-top:18px;padding-top:14px;border-top:1px solid var(--gray-border)">
        <div style="font-size:13px;font-weight:700;color:var(--navy);margin-bottom:6px">Millésimes figés</div>
        <div class="srv-desc" style="margin-bottom:10px">
          Les facteurs sont enregistrés par année et ne bougent plus : un bilan 2025 reste calculé
          avec les facteurs 2025. Une année se fige à sa première consultation, ou manuellement ici.
          <strong>L’API ADEME ne sert que ses valeurs du jour</strong> — une année non figée à l’époque
          ne pourra pas être reconstituée après coup.
        </div>
        <div id="cb_millesimes" style="font-size:12px;color:var(--gray-text)">Chargement…</div>
        <div style="display:flex;gap:8px;margin-top:10px;align-items:center;flex-wrap:wrap">
          <input class="form-control" type="number" id="cb_sync_annee" min="2000" max="2100"
                 value="${new Date().getFullYear()}" style="width:110px;font-family:monospace">
          <button class="btn" onclick="_figerMillesimeCarbone(false)">📌 Figer depuis l’ADEME</button>
          <button class="btn" onclick="_figerMillesimeCarbone(true)" style="color:#dc2626">♻️ Refiger (écrase)</button>
          <button class="btn" onclick="_ouvrirGrilleCarbone()">✏️ Saisir / corriger la grille</button>
          <span id="cb_sync_result" style="font-size:12px"></span>
        </div>
        <div class="srv-alert srv-alert-info" style="margin-top:10px">
          ℹ️ <span>Pour un <strong>exercice passé</strong>, « Figer depuis l’ADEME » ne convient pas : l’API renverrait
          les valeurs d’aujourd’hui. Utilisez la saisie manuelle avec une Base Carbone archivée.</span>
        </div>
      </div>
      <div class="srv-alert srv-alert-warn" style="margin-top:10px">
        ⚠️ <span>Deux périmètres sont récupérés pour chaque mode : <strong>ACV complet</strong> (fabrication du
        véhicule incluse) et <strong>hors construction</strong> (production et combustion du carburant ou de
        l’électricité). « Hors construction » n’est pas « scope 1 » : l’amont carburant y reste compté.</span>
      </div>
    </div>
    <!-- ── Facteurs carbone énergie (ADEME) ──────────────── -->
    <div class="srv-section" id="srv-carbone-energie-section">
      ${sectionTitle('⚡','Facteurs carbone énergie (ADEME)')}
      <div class="srv-alert srv-alert-info" style="margin-bottom:14px">
        ℹ️ <span>Source ouverte, <strong>aucune clé nécessaire</strong> : le jeu de données Base Carbone est
        public sur data.ademe.fr. Comme pour la mobilité, les facteurs sont figés par année et ne sont
        jamais recalculés rétroactivement.</span>
      </div>
      <div class="srv-grid">
        ${toggle('ce_actif','Source ADEME activée', ce.actif !== false,
          'Si désactivé, « Sync ADEME » n’écrit que les valeurs de référence intégrées, sans appel réseau.')}
        ${toggle('ce_tls','Vérification TLS', ce.tls_verify !== false,
          'Laisser activé. À ne désactiver que derrière un proxy interceptant le TLS.')}
      </div>
      <div class="srv-grid-1" style="margin-top:14px">
        ${field('ce_url','URL du jeu de données', ce.dataset_url || 'https://data.ademe.fr/data-fair/api/v1/datasets/base-carboner/lines',
          'Endpoint <code>lines</code> du jeu Base Carbone sur data.ademe.fr.', '')}
      </div>
      <div class="srv-grid" style="margin-top:14px">
        ${field('ce_id_elec','Identifiant ADEME — Électricité', ce.id_electricite || '36651',
          'Identifiant de l’élément dans la Base Carbone.', '36651')}
        ${field('ce_id_gaz','Identifiant ADEME — Gaz', ce.id_gaz || '38952',
          'Identifiant de l’élément dans la Base Carbone.', '38952')}
      </div>
      <div class="srv-grid-1" style="margin-top:14px">
        ${field('ce_frontiere','Frontière retenue', ce.frontiere || 'Amont + combustion',
          'Un même identifiant porte plusieurs lignes valides, une par frontière, aux valeurs très différentes. ' +
          'Si aucune ne correspond exactement, la synchronisation conserve la valeur de référence et liste les frontières trouvées.',
          'Amont + combustion')}
      </div>
      <div style="margin-top:18px;padding-top:14px;border-top:1px solid var(--gray-border)">
        <div style="font-size:13px;font-weight:700;color:var(--navy);margin-bottom:6px">Millésimes figés</div>
        <div class="srv-desc" style="margin-bottom:10px">
          Contrairement à la mobilité, la synchronisation énergie est <strong>toujours manuelle</strong> :
          rien ne se fige tout seul. Une année jamais synchronisée retombe sur les valeurs intégrées.
          Le figement se déclenche depuis Énergie → Bilan Carbone → « Sync ADEME », et la reprise
          d’un exercice passé via l’import CSV du même écran.
        </div>
        <div id="ce_millesimes" style="font-size:12px;color:var(--gray-text)">Chargement…</div>
      </div>
    </div>
    <div class="srv-section" id="srv-legifrance-section">
      ${sectionTitle('⚖️','Légifrance / France')}
      <div class="srv-grid">
        ${toggle('lf_actif','Service activé', lf.actif,
          'Active l’intégration backend vers l’API PISTE Légifrance.')}
        ${select('lf_env','Environnement', lf.environnement || 'sandbox', ['sandbox','production'],
          '<strong>sandbox</strong> : environnement de test PISTE. <strong>production</strong> : environnement réel.')}
        ${field('lf_scope','Scope OAuth', lf.scope || 'openid',
          'Scope transmis lors de la récupération du token OAuth2.', 'openid')}
      </div>
      <div class="srv-grid" style="margin-top:14px">
        ${field('lf_client','Client ID', lf.client_id || '',
          'Identifiant d’application fourni par PISTE pour Légifrance.', '060a8171-xxxx-xxxx-xxxx-xxxxxxxxxxxx')}
        ${field('lf_secret','Client Secret', lf.client_secret || '',
          'Secret d’application PISTE. Il reste côté serveur et est masqué après relecture.', '', {secret:true, secretPath:'legifrance.client_secret', placeholder:'••••••••'})}
      </div>
      <div class="srv-grid" style="margin-top:14px">
        ${field('lf_oauth','URL OAuth', lf.oauth_url || 'https://sandbox-oauth.piste.gouv.fr/api/oauth/token',
          'Endpoint OAuth2 client credentials.', 'https://sandbox-oauth.piste.gouv.fr/api/oauth/token')}
        ${field('lf_api','URL API de base', lf.api_base_url || 'https://sandbox-api.piste.gouv.fr/dila/legifrance/lf-engine-app',
          'Base URL de l’API Légifrance utilisée par le proxy backend.', 'https://sandbox-api.piste.gouv.fr/dila/legifrance/lf-engine-app')}
      </div>
      <div class="srv-grid" style="margin-top:14px">
        ${toggle('lf_tls_verify','Vérification TLS', lf.tls_verify !== false,
          'Recommandé : laisse activé en production. Désactive seulement pour tester un proxy ou certificat auto-signé.')}
        ${field('lf_ca_bundle','Chemin bundle CA / certificat racine', lf.ca_bundle_path || '',
          'Optionnel. Chemin vers un fichier .pem approuvé par ton serveur PHP/cURL si la chaîne TLS est interceptée ou privée.', '/etc/ssl/certs/ca-certificates.crt')}
      </div>
      <div class="srv-alert srv-alert-info" id="lf_env_hint" style="margin-top:14px">
        ℹ️ <span>Les URLs OAuth et API suivent automatiquement l’environnement sélectionné. Tu peux toujours les surcharger ensuite si besoin.</span>
      </div>
      <div class="srv-alert srv-alert-warn" style="margin-top:10px">
        ⚠️ <span>Si tu obtiens une erreur SSL du type <code>self-signed certificate in certificate chain</code>, garde <strong>Vérification TLS</strong> activée et renseigne d’abord un <strong>bundle CA</strong>. Désactive la vérification seulement en test.</span>
      </div>
    </div>
    <!-- ── Relay OAuth ───────────────────────────────── -->
    <div class="srv-section">
      ${sectionTitle('🔁','Relay OAuth (accès mobile hors réseau local)')}
      <div class="srv-alert srv-alert-info" style="margin-bottom:14px">
        ℹ️ Le relay est un fichier <code>relay.php</code> à héberger sur un domaine public.
        Il permet à un mobile extérieur au réseau local de revenir vers ce serveur
        après authentification OAuth.
      </div>
      <div class="srv-grid-1">
        ${field('relay_url','URL du relay', rl.url,
          'URL complète du relay.php hébergé publiquement. Laisser vide pour désactiver.',
          'https://gmao.mondomaine.fr/relay.php')}
      </div>
    </div>

    <!-- ── Version & mises à jour ───────────────────── -->
    <div class="srv-section">
      ${sectionTitle('⬆️','Version et mises à jour')}
      <div class="srv-alert srv-alert-info" style="margin-bottom:14px">
        ℹ️ Larka vérifie automatiquement si une nouvelle version est publiée et vous la propose ; rien ne s'installe sans votre validation.
        Les données (base, documents, plans, <code>config.json</code>, <code>.env</code>) ne sont jamais modifiées ; une sauvegarde est faite avant chaque installation.
      </div>
      <!-- Rempli par LarkaMaj.panneau() (js/maj.js) une fois l'onglet affiché : version,
           dernière vérification, résultat et boutons. Tout résultat s'affiche ICI. -->
      <div id="cfgMajPanneau">Version installée : <strong>…</strong></div>
    </div>

    <!-- ── Assistant IA ─────────────────────────────── -->
    <div class="srv-section">
      ${sectionTitle('🤖','Assistant IA (recherche en langage naturel)')}
      <div class="srv-alert srv-alert-info" style="margin-bottom:14px">
        ℹ️ L'assistant permet de rechercher dans Larka en langage naturel.
        Choisissez un fournisseur d'IA : <strong>Anthropic</strong>, <strong>OpenAI</strong>, <strong>Mistral</strong>, <strong>Google Gemini</strong>, <strong>Microsoft Copilot</strong>, ou un modèle local (<strong>Ollama</strong>, <strong>LM Studio</strong>).
        Les modèles proposés par défaut sont les plus <strong>rapides et économiques</strong> de chaque fournisseur ; les requêtes réutilisent le cache de prompt (jusqu'à −90 % sur les tokens d'entrée).
      </div>
      <div class="srv-grid">
        ${toggle('ai_actif','Activer l\'assistant', ai.actif !== false,
          'Active ou désactive l\'assistant IA pour tous les utilisateurs.')}
        <div class="srv-field">
          <span class="srv-label">Fournisseur</span>
          <select class="form-control" id="ai_fournisseur" onchange="_onAiFournisseurChange()">
            ${['anthropic','openai','mistral','gemini','copilot','ollama','lmstudio','local'].map(f =>
              '<option value="'+f+'" '+(((ai.fournisseur||'anthropic')===f)?'selected':'')+'>'+{anthropic:'Anthropic (Claude)',openai:'OpenAI (GPT)',mistral:'Mistral AI',gemini:'Google Gemini',copilot:'Microsoft Copilot (gratuit sur Windows)',ollama:'Ollama (local)',lmstudio:'LM Studio (local)',local:'Autre (OpenAI-compatible, ex. llama.cpp)'}[f]+'</option>'
            ).join('')}
          </select>
          <span class="srv-desc">Les fournisseurs locaux (Ollama, LM Studio) ne nécessitent pas de clé API.</span>
        </div>
      </div>
      <div class="srv-grid" style="margin-top:14px">
        ${field('ai_api_key','Clé API', ai.api_key && ai.api_key.length > 5 ? '••••••••' : '',
          'Clé secrète du fournisseur choisi. Pas nécessaire pour les modèles locaux.', 'sk-ant-… / sk-… / AIza… (Gemini)', {secret:true, secretPath:'assistant.api_key'})}
        ${field('ai_model','Modèle', ai.model || '',
          'Nom du modèle. Laissez vide pour le modèle par défaut (le plus rapide et économique). Sur CPU, préférez un modèle de 3 milliards de paramètres (3B) quantifié Q4.', 'claude-haiku-4-5 / gpt-4o-mini / gemini-3.1-flash-lite / ministral-3:3b')}
      </div>
      <div class="srv-grid-1" style="margin-top:14px">
        ${field('ai_api_url','URL API (optionnel)', ai.api_url || '',
          'URL de l\'endpoint. Laissez vide pour utiliser l\'URL par défaut du fournisseur. Utile pour un proxy ou un serveur local personnalisé. Ollama : l\'API native /api/chat est utilisée automatiquement (fenêtre de contexte réellement appliquée).', 'https://api.anthropic.com/v1/messages')}
      </div>
      <div class="srv-grid" style="margin-top:14px">
        ${field('ai_timeout','Durée max de réflexion (secondes)', ai.timeout_seconds || '',
          'Temps maximal accordé au modèle pour répondre. Borné entre 30 et 600 s ; étendu de 50 % en streaming. Vide = 240 s pour un modèle local, 90 s pour un fournisseur distant.', '240')}
        ${toggle('ai_prechauffage','Préchauffage du modèle local', ai.prechauffage !== false,
          'À l\'ouverture du panneau, le modèle local traite ses instructions pendant que l\'utilisateur tape : la première réponse arrive bien plus vite. Sans effet pour un fournisseur distant.')}
      </div>
      <div class="srv-grid" style="margin-top:14px">
        <div class="srv-field">
          <span class="srv-label">Mode de réponse</span>
          <select class="form-control" id="ai_mode">
            ${[['fiable','Fiable — même réponse quel que soit le modèle (recommandé)'],['agent','Agent libre — le modèle choisit ses recherches']].map(([v,l]) =>
              '<option value="'+v+'" '+((ai.mode||'fiable')===v?'selected':'')+'>'+l+'</option>').join('')}
          </select>
          <span class="srv-desc"><strong>Fiable</strong> : le moteur Larka comprend la question, fait les recherches et rédige la réponse lui-même. Un modèle de 0,6 B, 3 B ou 70 B, local ou distant, donne exactement la même réponse — en quelques millisecondes, même sans modèle joignable. <strong>Agent libre</strong> : l'ancien fonctionnement ; plus souple avec un grand modèle, mais la réponse dépend du modèle.</span>
        </div>
        <div class="srv-field">
          <span class="srv-label">Recours au modèle (mode fiable)</span>
          <select class="form-control" id="ai_interpretation">
            ${[['off','Jamais — même réponse quel que soit le modèle (recommandé)'],['auto','Si le moteur ne trouve rien — modèle de 7 B ou plus']].map(([v,l]) =>
              '<option value="'+v+'" '+((ai.interpretation_ia||'off')===v?'selected':'')+'>'+l+'</option>').join('')}
          </select>
          <span class="srv-desc">Par défaut, le modèle n'est jamais consulté : la réponse ne dépend pas de lui. En option, quand le moteur ne comprend pas une question, le modèle peut la reformuler dans un formulaire contrôlé, que le moteur vérifie ; il ne rédige jamais la réponse. Attention : c'est le seul cas où la taille du modèle compte — un petit modèle reformule souvent de travers.</span>
        </div>
      </div>
      <div class="srv-grid" style="margin-top:14px">
        <div class="srv-field">
          <span class="srv-label">Pré-recherche (mode rapide)</span>
          <select class="form-control" id="ai_pre_recherche">
            ${[['auto','Automatique (modèles locaux)'],['on','Toujours'],['off','Jamais']].map(([v,l]) =>
              '<option value="'+v+'" '+((ai.pre_recherche||'auto')===v?'selected':'')+'>'+l+'</option>').join('')}
          </select>
          <span class="srv-desc">Mode agent : Larka lance la recherche avant d'interroger le modèle — souvent une seule inférence au lieu de deux ou trois. Recommandé sur CPU. Sans effet en mode fiable.</span>
        </div>
        <div class="srv-field">
          <span class="srv-label">Réflexion des modèles locaux</span>
          <select class="form-control" id="ai_reflexion_locale">
            ${[['auto','Automatique'],['off','Désactivée (plus rapide)'],['on','Défaut du modèle']].map(([v,l]) =>
              '<option value="'+v+'" '+((ai.reflexion_locale||'auto')===v?'selected':'')+'>'+l+'</option>').join('')}
          </select>
          <span class="srv-desc">Les modèles « à réflexion » (qwen3, deepseek-r1…) génèrent des centaines de tokens invisibles : sur CPU, désactiver divise souvent le temps de réponse.</span>
        </div>
      </div>
    </div>

<!-- ── SMTP ──────────────────────────────────────── -->
    <div class="srv-section">
      ${sectionTitle('📧','Serveur SMTP (envoi d\'emails)')}
      <div class="srv-alert srv-alert-info" style="margin-bottom:14px">
        ℹ️ Configurez le serveur SMTP pour l'envoi de mails (réinitialisation de mot de passe, notifications).
        Sans configuration SMTP, les emails seront envoyés via <code>mail()</code> de PHP (souvent bloqué).
      </div>
      <div class="srv-grid">
        ${toggle('smtp_actif','SMTP activé', (cfg.smtp||{}).actif,
          'Active l\'envoi via SMTP au lieu de la fonction mail() native de PHP.')}
        ${field('smtp_host','Hôte SMTP', (cfg.smtp||{}).host,
          'Adresse du serveur SMTP.','smtp.gmail.com')}
        ${field('smtp_port','Port', (cfg.smtp||{}).port,
          '587 (TLS/STARTTLS), 465 (SSL), 25 (non chiffré — déconseillé).','587')}
        ${select('smtp_secure','Chiffrement', (cfg.smtp||{}).secure || 'tls', ['tls','ssl','none'],
          '<strong>tls</strong> (recommandé) : STARTTLS sur port 587. <strong>ssl</strong> : SSL sur port 465.')}
      </div>
      <div class="srv-grid" style="margin-top:14px">
        ${field('smtp_user','Utilisateur', (cfg.smtp||{}).username,
          'Adresse email ou identifiant de connexion SMTP.','noreply@mondomaine.fr')}
        ${field('smtp_pass','Mot de passe', (cfg.smtp||{}).password,
          'Mot de passe ou mot de passe d\'application (Gmail, Outlook…).','', {secret:true, secretPath:'smtp.password'})}
        ${field('smtp_from','Adresse expéditeur (From)', (cfg.smtp||{}).from,
          'Adresse qui apparaît comme expéditeur. Souvent identique à l\'utilisateur.','noreply@mondomaine.fr')}
        ${field('smtp_from_name','Nom expéditeur', (cfg.smtp||{}).from_name,
          'Nom affiché à côté de l\'adresse expéditeur.','Larka')}
      </div>
      <div class="srv-grid-1" style="margin-top:14px">
        <div class="srv-field">
          <div class="srv-label">🧪 Tester l'envoi SMTP</div>
          <div class="srv-desc">Envoie un email de test à l'adresse indiquée pour vérifier la configuration.</div>
          <div style="display:flex;gap:8px;margin-top:6px">
            <input class="form-control" id="smtp_test_email" type="email" placeholder="votre@email.com" style="max-width:300px">
            <button class="btn btn-primary btn-sm" onclick="testerSmtp()">📧 Envoyer un test</button>
          </div>
          <div id="smtp_test_result" style="margin-top:6px;font-size:12px"></div>
        </div>
      </div>
    </div>

    <!-- ── Feedback utilisateur (Problème / Suggestion) ─────────── -->
    <!-- ⚠️ DÉSACTIVÉ : feedback off (pas de SMTP). Section cachée mais code
         conservé pour pouvoir le réactiver facilement. Pour le rouvrir,
         retirer le display:none ci-dessous et la condition dans feedback.js. -->
    <div class="srv-section" style="display:none">
      ${sectionTitle('💬','Feedback utilisateur (problème / suggestion)')}
      <div class="srv-alert srv-alert-info" style="margin-bottom:14px">
        ℹ️ Permet aux utilisateurs d'envoyer un message via le bouton <strong>« Un problème ? Une suggestion ? »</strong> du menu utilisateur. Le mail est envoyé via le serveur SMTP configuré ci-dessus.
      </div>
      <div class="srv-grid-1">
        ${field('feedback_emails','Destinataires des feedback', ((cfg.feedback||{}).emails || []).join(', '),
          'Liste d\'adresses email séparées par des virgules. Si vide, les mails sont envoyés aux comptes <strong>Admin</strong> actifs.',
          'admin@mondomaine.fr, support@mondomaine.fr', {mono:true})}
        ${toggle('feedback_actif','Bouton feedback visible', (cfg.feedback||{}).actif !== false,
          'Affiche le bouton « Un problème ? Une suggestion ? » dans le menu utilisateur.')}
      </div>
    </div>

    <!-- ── Notifications Push ────────────────────────── -->
    <div class="srv-section">
      ${sectionTitle('🔔','Notifications Push (Web Push / PWA)')}
      <div class="srv-alert srv-alert-info" style="margin-bottom:14px">
        ℹ️ Envoie une notification native (Android, iOS PWA, Desktop) aux utilisateurs abonnés lorsqu'une demande d'intervention est créée.
        La variable <code>{demandeur}</code> est remplacée automatiquement par le nom du demandeur.
      </div>
      <div class="srv-grid">
        ${toggle('push_actif','Notifications Push activées', pu.actif !== false,
          'Active ou désactive l\'envoi de notifications push lors de la création de demandes.')}
        ${toggle('push_require_interaction','Notification persistante', pu.notif_demande_require_interaction || false,
          'Si activé, la notification ne disparaît pas automatiquement et nécessite une action de l\'utilisateur.')}
      </div>
      <div class="srv-grid" style="margin-top:14px">
        ${field('push_titre','Titre de la notification', pu.notif_demande_titre || "📝 Nouvelle demande d'intervention",
          'Titre affiché dans la notification. Variable disponible : <code>{demandeur}</code>.',
          "📝 Nouvelle demande d'intervention")}
        ${field('push_corps','Corps de la notification', pu.notif_demande_corps || "{demandeur} a soumis une demande d'intervention.",
          'Texte principal. Variable disponible : <code>{demandeur}</code>.',
          "{demandeur} a soumis une demande d'intervention.")}
      </div>
      <div class="srv-grid" style="margin-top:14px">
        ${field('push_icon','Icône (URL)', pu.notif_demande_icon || '/icon.png',
          'URL de l\'icône affichée dans la notification (petite image carrée).',
          '/icon.png')}
        ${field('push_image','Image (URL, optionnel)', pu.notif_demande_image || '',
          'URL d\'une image large affichée dans la notification (optionnel). Laisser vide pour ne pas afficher d\'image.', '')}
      </div>
      <div class="srv-grid-1" style="margin-top:14px">
        <div class="srv-field">
          <div class="srv-label">Rôles destinataires</div>
          <div class="srv-desc">Cochez les rôles qui recevront les notifications lors d'une nouvelle demande.</div>
          <div style="display:flex;gap:16px;margin-top:6px;flex-wrap:wrap">
            ${['Gestionnaire','Technicien','Demandeur'].map(r => {
              const roles = Array.isArray(pu.notif_demande_roles) ? pu.notif_demande_roles : ['Gestionnaire'];
              const checked = roles.includes(r) ? 'checked' : '';
              return '<label style="display:flex;align-items:center;gap:6px;font-size:12.5px;cursor:pointer">'
                + '<input type="checkbox" class="push-role-cb" value="'+r+'" '+checked+' style="accent-color:var(--blue)">'
                + r + '</label>';
            }).join('')}
          </div>
        </div>
      </div>
      <div style="display:flex;gap:10px;margin-top:16px;flex-wrap:wrap;align-items:center">
        <button class="btn btn-sm" style="background:var(--blue);color:white;border:none;border-radius:8px;padding:7px 14px;font-size:12px;cursor:pointer"
          onclick="_pushTestNotif('roles')" title="Simule une vraie demande : envoie aux rôles cochés ci-dessus">📤 Tester (rôles configurés)</button>
        <button class="btn btn-sm" style="background:var(--gray-bg);color:var(--text);border:1px solid var(--gray-border);border-radius:8px;padding:7px 14px;font-size:12px;cursor:pointer"
          onclick="_pushTestNotif('me')" title="Envoie uniquement à vos appareils enregistrés">📱 Tester (moi seul)</button>
        <button class="btn btn-sm" style="background:var(--gray-bg);color:var(--text);border:1px solid var(--gray-border);border-radius:8px;padding:7px 14px;font-size:12px;cursor:pointer"
          onclick="_pushTestNotif('all')" title="Envoie à TOUS les abonnés">📢 Tester (tous)</button>
      </div>
      <div style="display:flex;gap:10px;margin-top:10px;flex-wrap:wrap;align-items:center">
        <button class="btn btn-sm" style="background:var(--gray-bg);color:var(--text);border:1px solid var(--gray-border);border-radius:8px;padding:7px 14px;font-size:12px;cursor:pointer"
          onclick="_pushDiagnose()">🔍 Diagnostic VAPID</button>
        <button class="btn btn-sm" style="background:var(--gray-bg);color:var(--text);border:1px solid var(--gray-border);border-radius:8px;padding:7px 14px;font-size:12px;cursor:pointer"
          onclick="_pushShowSubscriptions()">📋 Voir les abonnements</button>
        <button class="btn btn-sm" style="background:#fff3cd;color:#856404;border:1px solid #ffc107;border-radius:8px;padding:7px 14px;font-size:12px;cursor:pointer"
          onclick="if(confirm('⚠️ Régénérer les clés VAPID supprimera TOUS les abonnements existants. Continuer ?')) _pushRegenVapid()">🔑 Régénérer clés VAPID</button>
      </div>
      <div id="push_test_result" style="font-size:12px;margin-top:10px"></div>
      <div id="push_subs_list" style="margin-top:10px"></div>
    </div>

    <div class="srv-section">
      ${sectionTitle('📝','Paramètres demandes')}
      <div class="srv-grid">
        <div id="srv-max-photos-wrap">
          ${field('srv_max_photos','📷 Nombre max de photos par demande','',
            'Nombre maximum de photos qu\'un demandeur peut joindre à une demande. Mettre 0 pour désactiver.','4')}
          <button class="btn btn-primary btn-sm" style="margin-top:6px" onclick="sauverConfigDemande('max_photos_demande','srv_max_photos')">💾 Enregistrer</button>
        </div>
        <div id="srv-items-page-wrap">
          ${field('srv_items_page','📄 Nombre d\'éléments par page (demandeur)','',
            'Nombre de demandes affichées par page dans la vue demandeur.','25')}
          <button class="btn btn-primary btn-sm" style="margin-top:6px" onclick="sauverConfigDemande('items_par_page','srv_items_page')">💾 Enregistrer</button>
        </div>
      </div>
    </div>

    <!-- ── Modules complémentaires ───────────────────── -->
    <div class="srv-section">
      ${sectionTitle('🧩','Modules complémentaires')}
      <div class="srv-alert srv-alert-info" style="margin-bottom:14px">
        Un module est un fichier <code>.larka</code> décrivant des données et des écrans,
        que Larka interprète : <strong>aucun code n'est exécuté</strong>. Chaque module
        annonce, avant installation, les tables qu'il créera et les écrans qu'il ajoutera.
        <br>Un module doit ensuite être affecté à un client (Super&nbsp;Admin → Tenants → 🧩).
      </div>
      <div class="srv-grid">
        ${toggle('ext_actif','Activer les modules complémentaires', ext.actif === true,
          'Désactivé, la couche est <strong>inerte</strong> : aucun module chargé, quels que soient les réglages des clients.')}
      </div>
    </div>

    <div class="srv-section" id="srv-upload-limits-section">
      ${sectionTitle('📤','Limites d\'upload (taille des fichiers)')}
      <div class="srv-alert srv-alert-info" style="margin-bottom:14px">
        Ces valeurs s'appliquent aux uploads (image de fond, import DXF/SVG/Shapefile, etc.). Elles sont écrites dans un fichier <code>.user.ini</code> à la racine, lu automatiquement par PHP-FPM (Nginx) et CGI/Apache.
        <br><strong>Important :</strong> en Nginx, vérifiez aussi <code>client_max_body_size</code> dans la conf serveur.
      </div>
      <div id="srv-upload-limits-content" style="color:var(--gray-text);text-align:center;padding:20px">⏳ Chargement…</div>
    </div>

    <div class="srv-section">
      ${sectionTitle('🛠️','Maintenance base de données')}
      <div class="srv-alert srv-alert-info" style="margin-bottom:14px">
        <strong>Resynchronisation des séquences PostgreSQL</strong> — Si vous obtenez une erreur "<code>duplicate key value violates unique constraint</code>" lors d'un ajout (par exemple : ajout d'une catégorie de liste), cela signifie que les compteurs d'auto-incrémentation sont désynchronisés. Cela arrive après un import de données depuis SQLite/MariaDB. Cliquez sur le bouton ci-dessous pour réparer.
      </div>
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <button class="btn btn-primary btn-sm" onclick="_dbResyncSequences()" id="btnResyncSeq">🔧 Resynchroniser les séquences</button>
        <span id="resyncSeqResult" style="font-size:12px"></span>
      </div>
    </div>


  </div>

  <script>
  // Initialisation des toggle labels — appelé après injection HTML
  (function() {
    document.querySelectorAll('.srv-toggle input[type=checkbox]').forEach(cb => {
      cb.addEventListener('change', function() {
        const lbl = document.getElementById(this.id + '_lbl');
        if (lbl) lbl.textContent = this.checked ? '✅ Activé' : '⬜ Désactivé';
      });
    });
  })();
  </script>`;
}

/* ── Switcher driver DB (unifié config + migration) ── */
let _currentDbDriver = null; // set when config loads

function _switchDbDriver(drv) {
  // _currentDbDriver is set by _loadServeurConfig before any user click
  if (!_currentDbDriver) _currentDbDriver = 'sqlite';

  // Update tab active state
  document.querySelectorAll('#db-driver-tabs .db-tab').forEach(tab => {
    const m = tab.getAttribute('onclick')?.match(/'(\w+)'/);
    if (m) tab.classList.toggle('active', m[1] === drv);
  });

  const isMigration = drv !== _currentDbDriver;
  const migFlow   = document.getElementById('db-migration-flow');
  const modeBadge = document.getElementById('db-mode-badge');

  // Show/hide normal panels (only for current driver editing)
  ['sqlite','pgsql','mariadb'].forEach(d => {
    const panel = document.getElementById('db-panel-' + d);
    if (panel) panel.style.display = (!isMigration && d === drv) ? 'block' : 'none';
  });

  if (isMigration) {
    // Show migration flow
    if (migFlow) migFlow.style.display = 'block';
    if (modeBadge) {
      modeBadge.style.display = 'inline-block';
      modeBadge.style.background = '#fff8e1';
      modeBadge.style.color = '#795548';
      modeBadge.style.border = '1px solid #ffe082';
      modeBadge.textContent = '🔄 Migration vers ' + {sqlite:'SQLite',pgsql:'PostgreSQL',mariadb:'MariaDB'}[drv];
    }
    _migPopulateConfigFields(drv);
    _migResetSteps();
  } else {
    // Normal editing — hide migration
    if (migFlow) migFlow.style.display = 'none';
    if (modeBadge) modeBadge.style.display = 'none';
  }
}

function _migPopulateConfigFields(drv) {
  const container = document.getElementById('mig-config-fields');
  if (!container) return;

  if (drv === 'sqlite') {
    container.innerHTML = `
      <div class="srv-field">
        <div class="srv-label">Chemin du fichier .db</div>
        <div class="srv-desc">Relatif au projet. Laissez vide pour le chemin par défaut.</div>
        <code class="srv-ex">ex : data/gmao.db</code>
        <input class="form-control" id="mig_dest_path" placeholder="data/gmao.db" style="font-family:monospace;margin-top:6px">
      </div>`;
  } else if (drv === 'pgsql') {
    container.innerHTML = `
      <div class="srv-grid" style="margin-bottom:12px">
        <div class="srv-field"><div class="srv-label">Hôte</div>
          <input class="form-control" id="mig_dest_host" value="127.0.0.1" style="font-family:monospace"></div>
        <div class="srv-field"><div class="srv-label">Port</div>
          <input class="form-control" id="mig_dest_port" value="5432" style="font-family:monospace"></div>
        <div class="srv-field"><div class="srv-label">Nom de la base</div>
          <input class="form-control" id="mig_dest_name" value="gmao" style="font-family:monospace"></div>
        <div class="srv-field"><div class="srv-label">Utilisateur</div>
          <input class="form-control" id="mig_dest_user" style="font-family:monospace"></div>
      </div>
      <div class="srv-grid-1">
        <div class="srv-field"><div class="srv-label">Mot de passe</div>
          <input class="form-control" id="mig_dest_pass" type="password"></div>
      </div>`;
  } else {
    container.innerHTML = `
      <div class="srv-grid" style="margin-bottom:12px">
        <div class="srv-field"><div class="srv-label">Hôte</div>
          <input class="form-control" id="mig_dest_host" value="127.0.0.1" style="font-family:monospace"></div>
        <div class="srv-field"><div class="srv-label">Port</div>
          <input class="form-control" id="mig_dest_port" value="3306" style="font-family:monospace"></div>
        <div class="srv-field"><div class="srv-label">Nom de la base</div>
          <input class="form-control" id="mig_dest_name" value="gmao" style="font-family:monospace"></div>
        <div class="srv-field"><div class="srv-label">Utilisateur</div>
          <input class="form-control" id="mig_dest_user" style="font-family:monospace"></div>
      </div>
      <div class="srv-grid-1">
        <div class="srv-field"><div class="srv-label">Mot de passe</div>
          <input class="form-control" id="mig_dest_pass" type="password"></div>
      </div>`;
  }
}

function _migResetSteps() {
  // Reset all steps to initial state
  ['test','backup','run'].forEach(s => {
    const step = document.getElementById('mig-step-' + s);
    if (step) { step.classList.remove('step-ok','step-err'); }
    const status = document.getElementById('mig-status-' + s);
    if (status) status.textContent = '';
  });
  // Lock backup + run steps
  const bk = document.getElementById('mig-step-backup');
  const rn = document.getElementById('mig-step-run');
  if (bk) { bk.style.opacity = '0.5'; bk.style.pointerEvents = 'none'; }
  if (rn) { rn.style.opacity = '0.5'; rn.style.pointerEvents = 'none'; }
  const res = document.getElementById('mig-result');
  if (res) res.innerHTML = '';
  const testResult = document.getElementById('mig-test-result');
  if (testResult) { testResult.textContent = 'Remplissez les champs ci-dessus puis testez.'; testResult.style.color = 'var(--gray-text)'; }
  const testDetail = document.getElementById('mig-test-detail');
  if (testDetail) testDetail.innerHTML = '';
}

function _migGetSelectedDriver() {
  const active = document.querySelector('#db-driver-tabs .db-tab.active');
  if (!active) return null;
  const m = active.getAttribute('onclick')?.match(/'(\w+)'/);
  return m ? m[1] : null;
}

function _migBuildDest() {
  const drv = _migGetSelectedDriver();
  if (!drv) return null;
  if (drv === 'sqlite') {
    return { driver:'sqlite', path: document.getElementById('mig_dest_path')?.value.trim() || 'data/gmao.db' };
  } else if (drv === 'pgsql') {
    return { driver:'pgsql',
      host:     document.getElementById('mig_dest_host')?.value.trim() || '127.0.0.1',
      port:     parseInt(document.getElementById('mig_dest_port')?.value) || 5432,
      dbname:   document.getElementById('mig_dest_name')?.value.trim() || 'gmao',
      user:     document.getElementById('mig_dest_user')?.value.trim() || '',
      password: document.getElementById('mig_dest_pass')?.value || '',
      sslmode:  'prefer' };
  } else {
    return { driver:'mariadb',
      host:     document.getElementById('mig_dest_host')?.value.trim() || '127.0.0.1',
      port:     parseInt(document.getElementById('mig_dest_port')?.value) || 3306,
      dbname:   document.getElementById('mig_dest_name')?.value.trim() || 'gmao',
      user:     document.getElementById('mig_dest_user')?.value.trim() || '',
      password: document.getElementById('mig_dest_pass')?.value || '' };
  }
}

async function _migUnifiedTest() {
  const dest = _migBuildDest();
  if (!dest) { toast('Choisissez un driver de destination.', 'error'); return; }

  const btn        = document.getElementById('mig-btn-test');
  const result     = document.getElementById('mig-test-result');
  const detail     = document.getElementById('mig-test-detail');
  const stepTest   = document.getElementById('mig-step-test');
  const statusTest = document.getElementById('mig-status-test');
  const stepBk     = document.getElementById('mig-step-backup');
  const stepRun    = document.getElementById('mig-step-run');

  btn.disabled = true; btn.textContent = '⏳ Test en cours…';
  detail.innerHTML = '';

  try {
    const r = await MigrationApi.test(dest);
    if (r.connected) {
      // Success
      if (result)     { result.textContent = '✅ Connexion réussie !'; result.style.color = 'var(--chip-ok-fg)'; }
      if (stepTest)   stepTest.classList.add('step-ok');
      if (stepTest)   stepTest.classList.remove('step-err');
      if (statusTest) { statusTest.textContent = '✅ OK'; statusTest.style.color = 'var(--chip-ok-fg)'; }
      detail.innerHTML = `<div style="background:#e6f4ea;border:1px solid #a8d5b5;border-radius:7px;padding:10px 14px;color:#1a7a3f;font-size:12px;font-weight:600">
        ✅ Connexion ${dest.driver.toUpperCase()} réussie — vous pouvez continuer.
      </div>`;
      // Unlock next steps
      if (stepBk)  { stepBk.style.opacity = '1'; stepBk.style.pointerEvents = 'auto'; }
      if (stepRun) { stepRun.style.opacity = '1'; stepRun.style.pointerEvents = 'auto'; }
    } else {
      // Failure
      if (result)     { result.textContent = '❌ Connexion échouée'; result.style.color = 'var(--red)'; }
      if (stepTest)   stepTest.classList.add('step-err');
      if (stepTest)   stepTest.classList.remove('step-ok');
      if (statusTest) { statusTest.textContent = '❌ Échoué'; statusTest.style.color = 'var(--red)'; }
      const lines = (r.error ?? 'Erreur inconnue').split('\n').map(l =>
        `<div style="margin-top:3px;font-size:12px;color:${l.startsWith('⚠')?'var(--chip-warn-fg)':l.startsWith('→')?'var(--gray-text)':'var(--text)'}">${_escCfg(l)}</div>`
      ).join('');
      detail.innerHTML = `<div style="background:#fdecea;border:1px solid #f5c6cb;border-radius:7px;padding:12px 14px">
        <div style="font-weight:700;color:var(--red);margin-bottom:6px">❌ Connexion impossible</div>
        ${lines}
      </div>`;
      // Keep next steps locked
      if (stepBk)  { stepBk.style.opacity = '0.5'; stepBk.style.pointerEvents = 'none'; }
      if (stepRun) { stepRun.style.opacity = '0.5'; stepRun.style.pointerEvents = 'none'; }
    }
  } catch(e) {
    if (result) { result.textContent = '❌ Erreur'; result.style.color = 'var(--red)'; }
    if (stepTest) stepTest.classList.add('step-err');
    detail.innerHTML = `<div style="color:var(--red);padding:10px;font-size:13px">❌ ${_escCfg(e.message)}</div>`;
  } finally {
    btn.disabled = false; btn.textContent = '🔌 Tester la connexion';
  }
}

async function _migUnifiedRun() {
  const dest = _migBuildDest();
  if (!dest) { toast('Choisissez un driver de destination.', 'error'); return; }

  const backupPath = document.getElementById('mig_backup_path')?.value.trim() || null;

  const ok = confirm(
    '⚠️  MIGRATION — CONFIRMATION\n\n' +
    'Ce que va faire cet outil :\n' +
    '  1. Créer une sauvegarde de la base source\n' +
    '  2. Copier toutes les données vers ' + dest.driver.toUpperCase() + '\n' +
    '  3. Mettre à jour config.json automatiquement\n\n' +
    'En cas d\'échec : aucune modification, sauvegarde supprimée.\n\n' +
    'Continuer ?'
  );
  if (!ok) return;

  const runBtn    = document.getElementById('mig-btn-run');
  const testBtn   = document.getElementById('mig-btn-test');
  const res       = document.getElementById('mig-result');
  const stepRun   = document.getElementById('mig-step-run');
  const statusRun = document.getElementById('mig-status-run');
  const stepBk    = document.getElementById('mig-step-backup');
  const statusBk  = document.getElementById('mig-status-backup');

  runBtn.disabled = true; testBtn.disabled = true;
  runBtn.textContent = '⏳ Migration en cours…';
  if (statusRun) { statusRun.textContent = '⏳ En cours…'; statusRun.style.color = 'var(--gray-text)'; }
  if (statusBk)  { statusBk.textContent = '⏳ Sauvegarde…'; statusBk.style.color = 'var(--gray-text)'; }
  res.innerHTML = `<div style="padding:22px;text-align:center;color:var(--gray-text);font-size:13px">
    ⏳ Migration en cours — ne fermez pas cette page…
  </div>`;

  try {
    const r = await MigrationApi.migrate(dest, backupPath);

    // Mark steps as OK
    if (stepBk)    stepBk.classList.add('step-ok');
    if (statusBk)  { statusBk.textContent = '✅ Sauvegardé'; statusBk.style.color = 'var(--chip-ok-fg)'; }
    if (stepRun)   stepRun.classList.add('step-ok');
    if (statusRun) { statusRun.textContent = '✅ Réussi'; statusRun.style.color = 'var(--chip-ok-fg)'; }

    // Rapport table par table
    const rows = (r.report ?? []).map(item => {
      const icon  = {ok:'✅', skip:'⏭️', info:'ℹ️', error:'❌'}[item.status] ?? '•';
      const color = item.status==='error' ? 'var(--red)' : item.status==='skip' ? 'var(--gray-text)' : 'var(--text)';
      return `<tr style="border-bottom:1px solid var(--gray-border)">
        <td style="padding:7px 10px;font-weight:600;white-space:nowrap">${icon} ${_escCfg(item.table)}</td>
        <td style="padding:7px 10px;text-align:right;color:var(--gray-text);white-space:nowrap">
          ${item.rows > 0 ? item.rows + ' lignes' : '—'}</td>
        <td style="padding:7px 10px;font-size:12px;color:${color}">${_escCfg(item.detail ?? '')}</td>
      </tr>`;
    }).join('');

    res.innerHTML = `
      <div style="background:#e6f4ea;border:1px solid #a8d5b5;border-radius:7px;padding:14px 16px;margin-bottom:14px">
        <div style="font-weight:700;font-size:14px;color:#1a7a3f">
          ✅ Migration réussie : ${r.src_driver?.toUpperCase()} → ${r.dst_driver?.toUpperCase()}
        </div>
        ${r.backup ? `<div style="margin-top:6px;font-size:12px;color:#2e7d32">
          📦 Sauvegarde conservée : <code>data/backups/${_escCfg(r.backup)}</code></div>` : ''}
      </div>
      <div style="border:1px solid var(--gray-border);border-radius:7px;overflow:hidden;margin-bottom:14px">
        <table style="width:100%;border-collapse:collapse;font-size:13px">
          <thead><tr style="background:var(--gray-bg);font-size:11px;text-transform:uppercase;color:var(--gray-text)">
            <th style="padding:8px 10px;text-align:left">Table</th>
            <th style="padding:8px 10px;text-align:right">Lignes</th>
            <th style="padding:8px 10px;text-align:left">Détail</th>
          </tr></thead>
          <tbody>${rows}</tbody>
        </table>
      </div>
      <div class="srv-alert srv-alert-warn">
        ⚠️ <strong>config.json mis à jour automatiquement.</strong>
        Redémarrez le serveur PHP (Ctrl+C puis relancez) puis reconnectez-vous.
        <strong>Pas besoin d'enregistrer à nouveau.</strong>
      </div>
      <div style="margin-top:12px;text-align:center">
        <button class="btn btn-primary" onclick="location.reload()" style="background:#1a7a3f;border-color:#1a7a3f;font-size:14px;padding:10px 28px">
          🔄 Recharger la page (après redémarrage du serveur)
        </button>
      </div>`;

    // Update the current driver reference so the save button won't try to re-save old driver
    _currentDbDriver = dest.driver;

    // On masque la barre pour éviter toute confusion : config.json vient d'être
    // réécrit par la migration. On vide #pageActionBar en entier plutôt que la
    // rangée intérieure, sinon il resterait une barre vide à l'écran.
    if (typeof clearPageActionBar === 'function') clearPageActionBar();
    else document.querySelector('.srv-save-bar')?.style.setProperty('display', 'none');

    toast('Migration réussie ! Redémarrez le serveur PHP puis reconnectez-vous.', 'success');

  } catch(e) {
    if (stepRun)   stepRun.classList.add('step-err');
    if (statusRun) { statusRun.textContent = '❌ Échoué'; statusRun.style.color = 'var(--red)'; }
    if (stepBk)    stepBk.classList.add('step-err');
    if (statusBk)  { statusBk.textContent = '❌ Erreur'; statusBk.style.color = 'var(--red)'; }

    const lines = e.message.split('\n').map((l,i) => {
      const color = l.startsWith('⚠') ? 'var(--chip-warn-fg)' : l.startsWith('→') ? 'var(--gray-text)' : i===0 ? 'var(--red)' : 'var(--text)';
      return `<div style="margin-top:${i===0?0:4}px;font-size:${i===0?13:12}px;color:${color};${i===0?'font-weight:700':''}">${_escCfg(l)}</div>`;
    }).join('');
    res.innerHTML = `
      <div style="background:#fdecea;border:1px solid #f5c6cb;border-radius:7px;padding:14px 16px">
        <div style="font-weight:700;color:var(--red);margin-bottom:8px">❌ Migration échouée — aucune modification effectuée</div>
        ${lines}
      </div>`;
    toast('Migration échouée — aucune modification.', 'error');
  } finally {
    runBtn.disabled = false; testBtn.disabled = false;
    runBtn.textContent = '🚀 Lancer la migration';
  }
}

/* ── Lecture des champs ── */
function _readField(id, fallback) {
  const el = document.getElementById(id);
  if (!el) return fallback;
  if (el.type === 'checkbox') return el.checked;
  const v = el.value.trim();
  return v === '' ? fallback : v;
}
function _activeDriver() {
  // Always return the CURRENT driver for saving config
  // Migration handles its own driver switch via _migUnifiedRun
  return _currentDbDriver || 'sqlite';
}

async function _sauvegarderServeur() {
  const btn = document.querySelector('.srv-save-bar .btn-primary');
  const status = document.getElementById('srv-save-status');
  if (btn) { btn.disabled = true; btn.textContent = '⏳ Enregistrement…'; }

  const drv = _activeDriver();

  // Construire le patch (tableau d'objets {section, sous_section?, cle, valeur})
  const P = [];
  const p = (section, cle, id, fallback, sous_section) => {
    const v = _readField(id, fallback);
    const entry = {section, cle, valeur: v};
    if (sous_section) entry.sous_section = sous_section;
    P.push(entry);
  };

  // Serveur
  p('serveur','host',  's_host',  '0.0.0.0');
  p('serveur','port',  's_port',  8000);
  p('serveur','env',   's_env',   'dev');
  p('serveur','actif', 's_https', false, 'https');
  p('serveur','port',  's_https_port', 8443, 'https');
  p('serveur','cert',  's_https_cert', '', 'https');
  p('serveur','key',   's_https_key',  '', 'https');

  // Driver DB + champs selon driver
  P.push({section:'base_de_donnees', cle:'driver', valeur: drv});
  if (drv === 'sqlite') {
    p('base_de_donnees','path', 'db_path', 'data/gmao.db');
  } else if (drv === 'pgsql') {
    p('base_de_donnees','host',    'db_host', '127.0.0.1');
    p('base_de_donnees','port',    'db_port', 5432);
    p('base_de_donnees','dbname',  'db_name', 'gmao');
    p('base_de_donnees','user',    'db_user', '');
    const pw = _readField('db_pass','');
    if (!pw.includes('••')) P.push({section:'base_de_donnees',cle:'password',valeur:pw});
    p('base_de_donnees','sslmode', 'db_ssl',  'prefer');
  } else {
    p('base_de_donnees','host',   'db_host_m', '127.0.0.1');
    p('base_de_donnees','port',   'db_port_m', 3306);
    p('base_de_donnees','dbname', 'db_name_m', 'gmao');
    p('base_de_donnees','user',   'db_user_m', '');
    const pw = _readField('db_pass_m','');
    if (!pw.includes('••')) P.push({section:'base_de_donnees',cle:'password',valeur:pw});
  }

  // Sécurité
  const sk = _readField('sec_key','');
  if (!sk.includes('••')) p('securite','secret_key','sec_key','');
  p('securite','session_duree_heures','sec_session',24);
  p('securite','allowed_origin','sec_origin','*');

  // Session
  p('session','cookie_secure',   'ses_secure',   false);
  p('session','cookie_httponly', 'ses_httponly', true);
  p('session','cookie_samesite', 'ses_samesite', 'Lax');

  // CORS
  const originsRaw = _readField('cors_origins','*');
  P.push({section:'cors',cle:'origins',valeur: originsRaw.split(',').map(x=>x.trim()).filter(Boolean)});
  p('cors','allow_credentials','cors_creds',false);

  // Sécurité HTTP
  p('securite_http','csp',                    'sh_csp',     "default-src 'self'");
  p('securite_http','hsts',                   'sh_hsts',    false);
  p('securite_http','hsts_max_age',           'sh_hsts_age',31536000);
  p('securite_http','x_frame_options',        'sh_xframe',  'DENY');
  p('securite_http','referrer_policy',        'sh_referrer','no-referrer');

  // Documents
  p('documents','max_par_categorie','doc_max', 4);
  p('documents','taille_max_mo',    'doc_size',25);
  p('documents','dedup_sha256',     'doc_dedup',true);
  const mimeRaw = _readField('doc_mime','application/pdf');
  P.push({section:'documents',cle:'mime_autorises',valeur:mimeRaw.split(',').map(x=>x.trim()).filter(Boolean)});

  // Sauvegardes : déplacées par tenant (voir Super Admin > Édit tenant)
  // Les valeurs existantes dans config.json sont conservées pour rétro-compat.

  // Logs
  p('logs','actif',          'lg_actif',  true);
  p('logs','dossier',        'lg_dossier','data/logs');
  p('logs','niveau',         'lg_niveau', 'info');
  p('logs','rotation_jours', 'lg_rotate', 14);

  // Microsoft OAuth
  p('microsoft_oauth','actif',               'ms_actif',    false);
  p('sharepoint','actif',                    'sp_actif',    false);
  p('microsoft_oauth','client_id',           'ms_client',   '');
  p('microsoft_oauth','tenant_id',           'ms_tenant',   'common');
  const mss = _readField('ms_secret','');
  if (!mss.includes('••')) P.push({section:'microsoft_oauth',cle:'client_secret',valeur:mss});
  p('microsoft_oauth','redirect_uri',        'ms_redirect',  '');
  p('microsoft_oauth','redirect_uri_mobile', 'ms_redirect_m','');

  // Google OAuth
  p('google_oauth','actif',               'gg_actif',    false);
  p('google_oauth','client_id',           'gg_client',   '');
  const ggs = _readField('gg_secret','');
  if (!ggs.includes('••')) P.push({section:'google_oauth',cle:'client_secret',valeur:ggs});
  p('google_oauth','redirect_uri',        'gg_redirect',  '');
  p('google_oauth','redirect_uri_mobile', 'gg_redirect_m','');

// Facteurs carbone (ADEME)
  p('carbone','actif',         'cb_actif', false);
  p('carbone','cache_heures',  'cb_cache', 24);
  p('carbone','impactco2_url', 'cb_url',   'https://impactco2.fr/api/v1/transport');
  p('carbone','tls_verify',    'cb_tls',   true);
  const cbk = _readField('cb_key','');
  if (!cbk.includes('••')) P.push({section:'carbone',cle:'impactco2_key',valeur:cbk});

// Facteurs carbone énergie (ADEME Base Carbone)
  p('carbone_energie','actif',          'ce_actif', true);
  p('carbone_energie','tls_verify',     'ce_tls',   true);
  p('carbone_energie','dataset_url',    'ce_url',   'https://data.ademe.fr/data-fair/api/v1/datasets/base-carboner/lines');
  p('carbone_energie','id_electricite', 'ce_id_elec',  '36651');
  p('carbone_energie','id_gaz',         'ce_id_gaz',   '38952');
  p('carbone_energie','frontiere',      'ce_frontiere','Amont + combustion');

// Légifrance / France
  p('legifrance','actif',          'lf_actif',  false);
  p('legifrance','environnement',  'lf_env',    'sandbox');
  p('legifrance','scope',          'lf_scope',  'openid');
  p('legifrance','client_id',      'lf_client', '');
  const lfs = _readField('lf_secret','');
  if (!lfs.includes('••')) P.push({section:'legifrance',cle:'client_secret',valeur:lfs});
  p('legifrance','oauth_url',      'lf_oauth',  'https://sandbox-oauth.piste.gouv.fr/api/oauth/token');
  p('legifrance','api_base_url',   'lf_api',    'https://sandbox-api.piste.gouv.fr/dila/legifrance/lf-engine-app');
  p('legifrance','tls_verify',     'lf_tls_verify', true);
  p('legifrance','ca_bundle_path', 'lf_ca_bundle', '');

// SMTP
  p('smtp','actif',     'smtp_actif',     false);
  p('smtp','host',      'smtp_host',      '');
  p('smtp','port',      'smtp_port',      587);
  p('smtp','secure',    'smtp_secure',    'tls');
  p('smtp','username',  'smtp_user',      '');
  const smtpPw = _readField('smtp_pass','');
  if (!smtpPw.includes('••')) P.push({section:'smtp',cle:'password',valeur:smtpPw});
  p('smtp','from',      'smtp_from',      '');
  p('smtp','from_name', 'smtp_from_name', 'Larka');

  // Feedback utilisateur (problème / suggestion)
  p('feedback','actif', 'feedback_actif', true);
  // Liste d'emails séparés par virgules — on stocke un array
  const fbRaw = _readField('feedback_emails','');
  const fbEmails = fbRaw.split(/[,;\n]/).map(s => s.trim()).filter(s => s.length > 0);
  P.push({section:'feedback', cle:'emails', valeur: fbEmails});

  // Relay
  p('oauth_relay','url','relay_url','');

  // Push Notifications
  p('push','actif',                          'push_actif',              true);
  p('push','notif_demande_titre',            'push_titre',             '📝 Nouvelle demande d\'intervention');
  p('push','notif_demande_corps',            'push_corps',             '{demandeur} a soumis une demande d\'intervention.');
  p('push','notif_demande_icon',             'push_icon',              '/icon.png');
  p('push','notif_demande_image',            'push_image',             '');
  p('push','notif_demande_require_interaction','push_require_interaction', false);
  // Rôles : récupérer les checkboxes cochées
  const pushRoles = [...document.querySelectorAll('.push-role-cb:checked')].map(cb => cb.value);
  P.push({section:'push', cle:'notif_demande_roles', valeur: pushRoles.length > 0 ? pushRoles : ['Gestionnaire']});

  // Assistant IA
  p('assistant','actif', 'ai_actif', false);
  p('assistant','fournisseur', 'ai_fournisseur', 'anthropic');
  p('assistant','model', 'ai_model', '');
  p('assistant','api_url', 'ai_api_url', '');
  p('assistant','timeout_seconds', 'ai_timeout', '');
  p('assistant','prechauffage', 'ai_prechauffage', true);
  p('assistant','mode', 'ai_mode', 'fiable');
  p('assistant','interpretation_ia', 'ai_interpretation', 'off');
  p('assistant','pre_recherche', 'ai_pre_recherche', 'auto');
  p('assistant','reflexion_locale', 'ai_reflexion_locale', 'auto');
  // Clé API : ne pas écraser si masquée
  const aiKeyVal = gv('ai_api_key');
  if (aiKeyVal && !aiKeyVal.includes('••')) {
    P.push({ section:'assistant', cle:'api_key', valeur: aiKeyVal });
  }

  // Modules communautaires
  p('extensions','actif', 'ext_actif', false);

  // Conversion types numériques
  P.forEach(item => {
    if (typeof item.valeur === 'string' && /^\d+$/.test(item.valeur)) {
      item.valeur = parseInt(item.valeur);
    }
  });

  try {
    const result = await ConfigServeurApi.save(P);
    const warnings = result?.warnings ?? [];

    if (warnings.length > 0) {
      const warnHtml = warnings.map(w => {
        const color = w.startsWith('✅') ? 'var(--chip-ok-fg)' : w.startsWith('→') ? 'var(--gray-text)' : 'var(--chip-warn-fg)';
        return `<div style="color:${color};font-size:12px;margin-top:3px">${_escCfg(w)}</div>`;
      }).join('');
      if (status) status.innerHTML = `
        <div style="background:var(--orange-light,#fff8e1);border:1px solid var(--orange,#f5a623);border-radius:7px;padding:12px 14px;margin-top:6px">
          <div style="font-weight:700;color:var(--text);margin-bottom:6px">⚠️ Config sauvegardée — avertissements :</div>
          ${warnHtml}
        </div>`;
      toast('Config sauvegardée — voir les avertissements.', 'warning');
    } else {
      if (status) status.innerHTML = `<span style="color:var(--chip-ok-fg)">✅ config.json enregistré — redémarrez le serveur pour appliquer.</span>`;
      toast('Configuration serveur sauvegardée.', 'success');
    }
  } catch(e) {
    // Afficher le message d'erreur complet avec sauts de ligne (diagnostic DB)
    // L'erreur peut être une erreur 422 (DB inaccessible) avec des warnings détaillés
    let warnings = [];
    if (e._warnings && e._warnings.length > 0) {
      warnings = e._warnings;
    }

    const allLines = warnings.length > 0
      ? warnings
      : e.message.split('\n');

    const lines = allLines.map(l => `<div style="margin-top:3px;font-size:12px;color:${
      l.startsWith('✅') ? 'var(--chip-ok-fg)' : l.startsWith('→') ? 'var(--gray-text)' : l.startsWith('⚠') ? 'var(--chip-warn-fg)' : 'var(--text)'
    }">${_escCfg(l)}</div>`).join('');
    if (status) status.innerHTML = `
      <div style="background:#fdecea;border:1px solid #f5c6cb;border-radius:7px;padding:12px 14px;margin-top:6px">
        <div style="font-weight:700;color:var(--red);margin-bottom:6px">❌ Sauvegarde refusée — base de données inaccessible</div>
        ${lines}
      </div>`;
    toast('Sauvegarde refusée — voir les détails.', 'error');
  } finally {
    if (btn) { btn.disabled = false; btn.textContent = '💾 Enregistrer config.json'; }
  }
}

// ═══════════════════════════════════════════════════════════════
//  ACTIONS : Listes (inchangées)
// ═══════════════════════════════════════════════════════════════

function ajouterValeur(categorie) {
  const label = CATEGORIES_LABELS[categorie] || categorie;
  openModal(`Ajouter dans "${label}"`, `
  <div class="form-grid cols-1">
    <div class="form-group">
      <label class="form-label">Valeur <span class="req">*</span></label>
      <input class="form-control" id="f_valeur" placeholder="ex: Nouvelle valeur" autofocus>
    </div>
  </div>`, async () => {
    const valeur = gv('f_valeur').trim();
    if (!valeur) { toast('La valeur est obligatoire.', 'error'); return; }
    try {
      // Les drapeaux de la catégorie sont hérités PAR LE SERVEUR. Ils étaient
      // recopiés ici depuis une valeur existante — ce qui ne pouvait pas
      // fonctionner sur une catégorie vide, où il n'y en a aucune : la première
      // valeur ajoutée arrivait avec « non obligatoire » et effaçait le réglage
      // que l'administrateur venait de poser.
      await ListesApi.create(categorie, valeur, 0);
      toast('Valeur ajoutée.', 'success'); closeModal(); renderConfiguration();
    } catch(e) { toast(e.message, 'error'); }
  }, 'Ajouter');
}

function modifierValeur(id, categorie, valeur, ordre, actif, obligatoire, saisieLibre) {
  openModal(`Modifier « ${_escCfg(valeur)} »`, `
  <div class="form-grid cols-1">
    <div class="form-group">
      <label class="form-label">Valeur <span class="req">*</span></label>
      <input class="form-control" id="f_valeur" value="${_escCfg(valeur)}">
    </div>
    <div class="form-group">
      <label class="form-label">Statut</label>
      <select class="form-control" id="f_actif">
        <option value="1" ${actif==1?'selected':''}>Actif</option>
        <option value="0" ${actif==0?'selected':''}>Inactif</option>
      </select>
    </div>
  </div>`, async () => {
    const v = gv('f_valeur').trim();
    if (!v) { toast('Valeur obligatoire.', 'error'); return; }
    try {
      await ListesApi.update(id, v, ordre, parseInt(gv('f_actif')), obligatoire||0, saisieLibre||0);
      toast('Modifié.', 'success'); closeModal(); renderConfiguration();
    } catch(e) { toast(e.message, 'error'); }
  });
}

/**
 * Configure une catégorie de liste.
 *
 * Deux cases, celles des listes du cœur. Il y en a eu quatre — on pouvait aussi
 * autoriser ou refuser l'ajout et le retrait de valeurs par les utilisateurs —
 * puis quatre sélecteurs à trois états pour arbitrer entre le module et
 * l'administrateur. C'étaient des permissions dont personne n'avait besoin :
 * dans cet écran, ajouter une valeur ou la désactiver est ce qu'on fait
 * normalement, pas un droit à s'accorder.
 *
 * Une liste de module se règle donc exactement comme une liste du cœur, et il
 * n'y a plus rien de particulier à apprendre.
 *
 * Les réglages partent en UN appel, sur la catégorie entière. La version
 * précédente bouclait sur chaque valeur : lent, à moitié appliqué si un appel
 * échouait, et sans aucun effet sur une catégorie encore vide — c'est-à-dire
 * celle que vient de créer un module.
 */
async function configurerCategorie(categorie, label) {
  let r = { obligatoire: 0, libre: 0 };
  try {
    r = await apiRequest(`liste_reglages&categorie=${encodeURIComponent(categorie)}`) || r;
  } catch (e) { /* jamais réglée : les défauts conviennent */ }

  const bascule = (id, titre, aide, v) => `
    <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;
      font-size:13px;padding:10px;background:var(--gray-bg);border-radius:8px">
      <input type="checkbox" id="${id}" ${v ? 'checked' : ''} style="margin-top:2px">
      <div>
        <div style="font-weight:600">${titre}</div>
        <div style="font-size:11px;color:var(--gray-text)">${aide}</div>
      </div>
    </label>`;

  openModal(`⚙️ Configurer "${label}"`, `
  <div class="form-grid cols-1">
    <div style="font-size:13px;color:var(--gray-text);margin-bottom:4px">
      Ces paramètres s'appliquent à <strong>tous les éléments</strong> de cette
      liste dans les formulaires.
    </div>
    <div class="form-group" style="display:flex;flex-direction:column;gap:10px">
      ${bascule('f_catOblig','Champ obligatoire',
                "L'utilisateur devra renseigner ce champ", r.obligatoire)}
      ${bascule('f_catLibre','Saisie libre autorisée',
                "L'utilisateur peut aussi saisir une valeur ne figurant pas dans la liste",
                r.libre)}
    </div>
  </div>`, async () => {
    const v = (id) => document.getElementById(id)?.checked ? 1 : 0;
    try {
      const res = await apiRequest('liste_reglages', 'POST', {
        categorie, obligatoire: v('f_catOblig'), libre: v('f_catLibre'),
      });
      toast(res?.valeurs_touchees > 0
        ? 'Configuration appliquée à la liste.'
        : "Configuration enregistrée : elle s'appliquera aux valeurs à venir.",
        'success');
      closeModal();
      renderConfiguration();
    } catch(e) { toast(e.message, 'error'); }
  }, 'Enregistrer');
}

// ═══════════════════════════════════════════════════════════════
//  LISTES DES MODULES — éditeur du fichier de configuration
// ═══════════════════════════════════════════════════════════════

/**
 * Rend l'onglet « 🧩 Modules » de Configuration → Listes.
 *
 * Chaque module installé chez ce client montre ses listes, avec le chemin du
 * fichier qui les porte. Le chemin est AFFICHÉ volontairement : il dit où
 * chercher quand on veut éditer à la main, sauvegarder, ou comprendre pourquoi
 * un autre client ne voit pas la même chose.
 */
function _renderListesModules() {
  if (!_catsModules.length) {
    return `<div style="padding:14px 18px;background:var(--gray-bg);border-radius:10px;
      font-size:13px;color:var(--gray-text)">
      Aucun module installé ne déclare de liste modifiable.</div>`;
  }

  let html = `<div style="padding:12px 16px;background:var(--blue-pale);border-radius:10px;
      margin-bottom:16px;font-size:13px;color:var(--navy);line-height:1.6">
      <strong>🧩 Listes des modules</strong> — Elles vivent dans un fichier propre à
      ce client, pas dans la base. Vous pouvez les modifier ici ou éditer le
      fichier directement : c'est le même.
    </div>`;

  _catsModules.forEach((mod) => {
    html += `<div style="border:1px solid var(--gray-border);border-radius:10px;
        margin-bottom:16px;overflow:hidden">
      <div style="padding:10px 16px;background:var(--gray-bg);display:flex;
        justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
        <strong style="font-size:14px">${escHtml(mod.module)}</strong>
        <code style="font-size:11px;color:var(--gray-text)">${escHtml(mod.fichier)}</code>
      </div>`;

    Object.entries(mod.listes).forEach(([cat, def]) => {
      const vals = def.valeurs || [];
      html += `<div style="padding:14px 16px;border-top:1px solid var(--gray-border)">
        <div style="display:flex;justify-content:space-between;align-items:center;
          gap:10px;flex-wrap:wrap;margin-bottom:8px">
          <div>
            <strong style="font-size:13px">${escHtml(def.libelle || cat)}</strong>
            <code style="font-size:11px;color:var(--gray-text);margin-left:6px">${escHtml(cat)}</code>
          </div>
          <button class="btn btn-sm btn-secondary"
            onclick="modifierListeModule('${escHtml(mod.identifiant)}','${escHtml(cat)}')">
            ⚙️ Modifier</button>
        </div>
        <div style="font-size:11px;color:var(--gray-text);margin-bottom:8px;display:flex;gap:12px">
          ${def.obligatoire ? '<span style="color:var(--red)">● Champ obligatoire</span>'
                            : '<span>● Champ optionnel</span>'}
          ${def.libre ? '<span style="color:var(--blue)">● Saisie libre autorisée</span>' : ''}
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:6px">
          ${vals.length
            ? vals.map(v => `<span style="padding:3px 9px;background:var(--gray-bg);
                border-radius:12px;font-size:12px">${escHtml(v)}</span>`).join('')
            : '<span style="font-size:12px;color:var(--gray-text)">Aucune valeur</span>'}
        </div>
      </div>`;
    });
    html += `</div>`;
  });
  return html;
}

/**
 * Édite une liste de module.
 *
 * Les valeurs se saisissent une par ligne. Une zone de texte plutôt qu'une
 * ligne par valeur avec des boutons : on colle une nomenclature de trente
 * entrées d'un coup, on la réordonne en déplaçant des lignes, et l'ordre du
 * texte est l'ordre du menu déroulant. Trente champs à remplir un par un, pour
 * le même résultat, n'auraient servi personne.
 */
function modifierListeModule(identifiant, categorie) {
  const mod = _catsModules.find(m => m.identifiant === identifiant);
  const def = mod?.listes?.[categorie];
  if (!def) { toast('Liste introuvable.', 'error'); return; }

  openModal(`⚙️ ${escHtml(def.libelle || categorie)}`, `
  <div class="form-grid cols-1">
    <div style="font-size:12px;color:var(--gray-text)">
      Module « ${escHtml(mod.module)} » · <code>${escHtml(mod.fichier)}</code>
    </div>
    <div class="form-group">
      <label class="form-label">Valeurs — une par ligne</label>
      <textarea class="form-control" id="f_modVals" rows="10"
        style="font-family:ui-monospace,monospace;font-size:13px">${escHtml((def.valeurs||[]).join('\n'))}</textarea>
      <div style="font-size:11px;color:var(--gray-text);margin-top:4px">
        L'ordre des lignes est l'ordre du menu déroulant. Retirer une valeur la
        retire du menu ; les fiches qui la portent la conservent.</div>
    </div>
    <div class="form-group" style="display:flex;flex-direction:column;gap:10px">
      <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;
        font-size:13px;padding:10px;background:var(--gray-bg);border-radius:8px">
        <input type="checkbox" id="f_modOblig" ${def.obligatoire ? 'checked' : ''} style="margin-top:2px">
        <div><div style="font-weight:600">Champ obligatoire</div>
          <div style="font-size:11px;color:var(--gray-text)">L'utilisateur devra le renseigner</div></div>
      </label>
      <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;
        font-size:13px;padding:10px;background:var(--gray-bg);border-radius:8px">
        <input type="checkbox" id="f_modLibre" ${def.libre ? 'checked' : ''} style="margin-top:2px">
        <div><div style="font-weight:600">Saisie libre autorisée</div>
          <div style="font-size:11px;color:var(--gray-text)">Une valeur hors liste est acceptée</div></div>
      </label>
    </div>
  </div>`, async () => {
    const lignes = (document.getElementById('f_modVals')?.value || '')
      .split('\n').map(v => v.trim()).filter(v => v !== '');

    // On réécrit TOUTES les listes du module, pas seulement celle-ci : le
    // fichier est écrit d'un bloc, et n'envoyer qu'une catégorie effacerait
    // les autres.
    const listes = JSON.parse(JSON.stringify(mod.listes));
    listes[categorie] = {
      libelle:     def.libelle || categorie,
      obligatoire: !!document.getElementById('f_modOblig')?.checked,
      libre:       !!document.getElementById('f_modLibre')?.checked,
      valeurs:     lignes,
    };
    try {
      await apiRequest('ext_config_listes', 'POST', { identifiant, listes });

      // Les déclarations des modules sont chargées UNE FOIS, après
      // l'authentification. Sans ce rechargement, la modification s'écrivait
      // bien côté serveur mais le module continuait d'utiliser la copie du
      // démarrage : « champ obligatoire » et « saisie libre » restaient sans
      // effet visible jusqu'au prochain F5.
      if (typeof LarkaExtensions !== 'undefined' && LarkaExtensions.recharger) {
        try { await LarkaExtensions.recharger(); }
        catch (e) { console.warn('Rechargement des modules :', e); }
      }

      toast('Liste enregistrée.', 'success');
      closeModal();
      renderConfiguration();
    } catch (e) { toast(e.message, 'error'); }
  }, 'Enregistrer');
}

// ═══════════════════════════════════════════════════════════════
//  CHAMPS OBLIGATOIRES — sauvegarde / lecture
// ═══════════════════════════════════════════════════════════════
async function _sauverChampsOblig() {
  try {
    // Charger config actuelle
    const config = await ConfigApi.getAll();
    let champsOblig = {};
    try {
      const raw = config.find(r => r.Cle === 'champs_obligatoires')?.Valeur;
      if (raw) champsOblig = JSON.parse(raw);
    } catch(_) {}

    /**
     * ⚠️ LA COLLECTE RATISSAIT TOUT LE DOCUMENT.
     *
     * « querySelectorAll('.cb-champ-oblig:checked') » ramasse les cases de
     * TOUS les panneaux présents dans la page, pas seulement celui du
     * sous-onglet affiché. Les champs d'un autre formulaire se retrouvaient
     * donc enregistrés comme obligatoires pour celui-ci — d'où les doublons
     * visibles et l'impression que cocher ne servait à rien : on relisait
     * ensuite un mélange.
     *
     * On se limite à la grille VISIBLE, et l'on dédoublonne : deux cases de
     * même valeur ne doivent pas produire deux entrées.
     */
    const grilles = Array.from(document.querySelectorAll('#champsObligGrid'));
    const grille = grilles.find(g => g.offsetParent !== null) || grilles[0];
    if (!grille) { toast('Panneau introuvable.', 'error'); return; }

    const checked = [...new Set(
      Array.from(grille.querySelectorAll('.cb-champ-oblig:checked')).map(cb => cb.value)
    )];

    // On n'écrit que la clé du sous-onglet courant : les autres formulaires
    // gardent leurs réglages, même si leur panneau traîne dans le document.
    champsOblig[_listesSubTab] = checked;

    await ConfigApi.set('champs_obligatoires', JSON.stringify(champsOblig));
    toast('Champs obligatoires enregistrés.', 'success');
    renderConfiguration();
  } catch(e) { toast(e.message, 'error'); }
}

/**
 * Utilitaire global : récupère la liste des champs obligatoires pour un type d'entité.
 * Utilisé par les formulaires pour appliquer required + étoile.
 * @param {string} entite - 'biens', 'equipements', 'interventions', etc.
 * @returns {Promise<string[]>} ex: ['Numero', 'Famille', 'Batiment']
 */
async function getRequiredFields(entite) {
  try {
    const config = await ConfigApi.getAll();
    const raw = config.find(r => r.Cle === 'champs_obligatoires')?.Valeur;
    if (!raw) return [];
    const all = JSON.parse(raw);
    return all[entite] || [];
  } catch(_) { return []; }
}

/**
 * Génère label + étoile si le champ est obligatoire.
 * @param {string} text - Le texte du label
 * @param {string} fieldKey - La clé du champ (ex: 'Batiment')
 * @param {string[]} reqFields - La liste des champs obligatoires
 */
function reqLabel(text, fieldKey, reqFields) {
  return reqFields.includes(fieldKey) ? `${text} <span class="req">*</span>` : text;
}

/** Attribut required si le champ est obligatoire */
function reqAttr(fieldKey, reqFields) {
  return reqFields.includes(fieldKey) ? 'required' : '';
}

function _onAiFournisseurChange() {
  const sel = document.getElementById('ai_fournisseur');
  if (!sel) return;
  const f = sel.value;
  const localProviders = ['ollama','lmstudio','local'];
  const keyField = document.getElementById('ai_api_key');
  const urlField = document.getElementById('ai_api_url');
  const modelField = document.getElementById('ai_model');
  // Griser la clé API pour les fournisseurs locaux
  if (keyField) keyField.placeholder = localProviders.includes(f) ? '(pas nécessaire)' : (f === 'gemini' ? 'AIza… (Google AI Studio)' : 'sk-ant-… / sk-… / …');
  // Placeholder URL
  const urls = { anthropic:'https://api.anthropic.com/v1/messages', openai:'https://api.openai.com/v1/chat/completions', mistral:'https://api.mistral.ai/v1/chat/completions', gemini:'https://generativelanguage.googleapis.com/v1beta', copilot:'https://api.githubcopilot.com/chat/completions', ollama:'http://localhost:11434/api/chat', lmstudio:'http://localhost:1234/v1/chat/completions', local:'http://localhost:8080/v1/chat/completions' };
  if (urlField) urlField.placeholder = urls[f] || '';
  // Placeholder modèle (défauts serveur : rapides et économiques)
  const models = { anthropic:'claude-haiku-4-5', openai:'gpt-4o-mini', mistral:'mistral-small-latest', gemini:'gemini-3.1-flash-lite', copilot:'gpt-4o', ollama:'ministral-3:3b', lmstudio:'local-model', local:'local-model' };
  if (modelField && !modelField.value) modelField.placeholder = models[f] || '';
}

function desactiverValeur(id, valEsc) {
  showConfirm(`Désactiver « ${valEsc} » ? Elle n'apparaîtra plus dans les formulaires mais les données existantes sont préservées.`, async () => {
    try {
      // Récupérer les infos de l'item pour garder ses champs
      const all = await ListesApi.getAll();
      let found = null;
      for (const cat of Object.values(all)) {
        found = cat.find(x => x.Id === id);
        if (found) break;
      }
      if (found) {
        await ListesApi.update(id, found.Valeur, found.Ordre, 0, found.Obligatoire||0, found.SaisieLibre||0);
      }
      toast('Valeur désactivée.', 'success');
      renderConfiguration();
    } catch(e) { toast(e.message, 'error'); }
  }, 'Désactiver');
}

async function testerSmtp() {
  const email = document.getElementById('smtp_test_email')?.value;
  const result = document.getElementById('smtp_test_result');
  if (!email || !email.includes('@')) {
    if (result) result.innerHTML = '<span style="color:var(--red)">❌ Entrez une adresse email valide.</span>';
    return;
  }
  if (result) result.innerHTML = '<span style="color:var(--gray-text)">⏳ Envoi en cours…</span>';
  try {
    const resp = await apiRequest('smtp_test', 'POST', { email });
    if (result) result.innerHTML = '<span style="color:var(--green,#16a34a)">✅ Email envoyé ! Vérifiez votre boîte de réception.</span>';
  } catch(e) {
    if (result) result.innerHTML = `<span style="color:var(--red)">❌ Échec : ${_escCfg(e.message)}</span>`;
  }
}
// (Migration functions are now unified above in _migUnifiedTest / _migUnifiedRun)

// ═══════════════════════════════════════════════════════════════════════════════
//  ONGLET NOTIFICATIONS — Envoi ciblé de notifications push
// ═══════════════════════════════════════════════════════════════════════════════

let _cfgNotifMode = 'roles';
let _cfgNotifSelectedUsers = [];
let _cfgNotifUsersCache = null;

async function _renderTabNotifications() {
  try {
    _cfgNotifUsersCache = await UtilisateursApi.getAll();
  } catch(e) {
    return `<div style="padding:20px">${errorHtml(e.message)}</div>`;
  }

  const users = _cfgNotifUsersCache.filter(u => u.Actif == 1);
  const roles = [...new Set(users.map(u => u.Role).filter(Boolean))].sort();
  const services = [...new Set(users.map(u => u.Service).filter(Boolean))].sort();

  const modeBtn = (id, icon, label) =>
    `<button onclick="_cfgNotifSwitchMode('${id}')"
      style="padding:7px 16px;border:1px solid ${_cfgNotifMode===id?'var(--blue)':'var(--gray-border)'};
      background:${_cfgNotifMode===id?'var(--blue)':'white'};
      color:${_cfgNotifMode===id?'white':'var(--text)'};border-radius:6px;cursor:pointer;
      font-size:12px;font-weight:${_cfgNotifMode===id?'700':'400'};display:flex;align-items:center;gap:5px">
      ${icon} ${label}
    </button>`;

  let pushSubsInfo = '';
  try {
    const status = await apiRequest('push_subscriptions', 'GET');
    pushSubsInfo = `<span style="font-size:12px;color:var(--gray-text)">📱 ${(status.subscriptions||[]).length} appareil(s) enregistré(s)</span>`;
  } catch(_) {}

  setTimeout(() => _cfgNotifRenderTargets(users, roles, services), 0);

  return `
  <div style="padding:0 4px">
    <div style="margin-bottom:20px">
      <h3 style="margin:0 0 6px;font-size:16px;font-weight:700">📤 Envoyer une notification</h3>
      <p style="margin:0 0 8px;font-size:13px;color:var(--gray-text)">
        Envoyez une notification push à un groupe de rôles, un service, ou une sélection personnalisée d'utilisateurs.
      </p>
      ${pushSubsInfo}
    </div>

    <div class="srv-section" style="margin-bottom:16px">
      <div class="srv-section-title"><span>💬</span> Message</div>
      <div class="srv-grid">
        <div class="srv-field">
          <div class="srv-label">Titre <span style="color:var(--red)">*</span></div>
          <input class="form-control" id="cfgNotif_titre" placeholder="ex: Information maintenance" value="📢 Information Larka">
        </div>
        <div class="srv-field">
          <div class="srv-label">Page cible au clic <span style="font-weight:400;font-size:11px;color:var(--gray-text)">(optionnel)</span></div>
          <select class="form-control" id="cfgNotif_page">
            <option value="" selected>Aucune — ne rien ouvrir</option>
            <option value="dashboard">Dashboard</option>
            <option value="demandes">Demandes</option>
            <option value="interventions">Interventions</option>
            <option value="contrats">Contrats</option>
            <option value="biens">Biens</option>
          </select>
        </div>
      </div>
      <div class="srv-grid-1" style="margin-top:14px">
        <div class="srv-field">
          <div class="srv-label">Corps du message <span style="color:var(--red)">*</span></div>
          <textarea class="form-control" id="cfgNotif_corps" rows="3" placeholder="Saisissez le message..." style="resize:vertical"></textarea>
        </div>
      </div>
    </div>

    <div class="srv-section" style="margin-bottom:16px">
      <div class="srv-section-title"><span>🎯</span> Destinataires</div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px">
        ${modeBtn('roles', '🏷️', 'Par rôle')}
        ${modeBtn('services', '🏢', 'Par service')}
        ${modeBtn('custom', '👤', 'Sélection personnalisée')}
      </div>
      <div id="cfgNotifTargetSection"></div>
    </div>

    <div id="cfgNotifPreview" style="margin-bottom:16px"></div>

    <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
      <button class="btn" onclick="_cfgNotifPreview()" style="padding:9px 22px;font-size:13px;background:var(--blue);color:white;border:none;border-radius:8px;cursor:pointer">
        👁️ Aperçu
      </button>
      <button class="btn" onclick="_cfgNotifSend()" style="padding:9px 22px;font-size:13px;background:var(--green,#16a34a);color:white;border:none;border-radius:8px;cursor:pointer">
        📤 Envoyer
      </button>
      <div id="cfgNotifSendResult" style="font-size:12px"></div>
    </div>
  </div>`;
}

function _cfgNotifSwitchMode(mode) {
  _cfgNotifMode = mode;
  _cfgNotifSelectedUsers = [];
  const users = (_cfgNotifUsersCache || []).filter(u => u.Actif == 1);
  const roles = [...new Set(users.map(u => u.Role).filter(Boolean))].sort();
  const services = [...new Set(users.map(u => u.Service).filter(Boolean))].sort();
  _cfgNotifRenderTargets(users, roles, services);
}

function _cfgNotifRenderTargets(users, roles, services) {
  const c = document.getElementById('cfgNotifTargetSection');
  if (!c) return;

  if (_cfgNotifMode === 'roles') {
    c.innerHTML = `
      <div style="font-size:13px;color:var(--gray-text);margin-bottom:10px">Sélectionnez les rôles :</div>
      <div style="display:flex;gap:12px;flex-wrap:wrap">
        ${['Gestionnaire','Visionneur','Demandeur'].map(r => {
          const count = users.filter(u => u.Role === r).length;
          return `<label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;
            padding:8px 14px;border:1px solid var(--gray-border);border-radius:8px;background:var(--gray-bg)">
            <input type="checkbox" class="cfgNotif-role-cb" value="${r}" style="accent-color:var(--blue)"
              ${r==='Gestionnaire'?'checked':''}>
            ${r} <span style="font-size:11px;color:var(--gray-text)">(${count})</span>
          </label>`;
        }).join('')}
      </div>`;
  } else if (_cfgNotifMode === 'services') {
    if (services.length === 0) {
      c.innerHTML = '<div style="font-size:13px;color:var(--gray-text);padding:12px 0">Aucun service renseigné.</div>';
      return;
    }
    c.innerHTML = `
      <div style="font-size:13px;color:var(--gray-text);margin-bottom:10px">Sélectionnez les services :</div>
      <div style="display:flex;gap:10px;flex-wrap:wrap">
        ${services.map(s => {
          const count = users.filter(u => u.Service === s).length;
          return `<label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;
            padding:8px 14px;border:1px solid var(--gray-border);border-radius:8px;background:var(--gray-bg)">
            <input type="checkbox" class="cfgNotif-svc-cb" value="${_escCfg(s)}" style="accent-color:var(--blue)">
            ${_escCfg(s)} <span style="font-size:11px;color:var(--gray-text)">(${count})</span>
          </label>`;
        }).join('')}
      </div>`;
  } else if (_cfgNotifMode === 'custom') {
    c.innerHTML = `
      <div style="font-size:13px;color:var(--gray-text);margin-bottom:10px">Cochez les utilisateurs :</div>
      <div style="margin-bottom:10px;display:flex;gap:10px;align-items:center">
        <input class="form-control" id="cfgNotifSearch" placeholder="🔍 Rechercher..."
          oninput="_cfgNotifFilterUsers()" style="max-width:300px;font-size:12px">
        <button class="btn btn-sm" onclick="_cfgNotifSelectAll(true)" style="font-size:11px;padding:5px 10px">Tout cocher</button>
        <button class="btn btn-sm" onclick="_cfgNotifSelectAll(false)" style="font-size:11px;padding:5px 10px">Tout décocher</button>
      </div>
      <div id="cfgNotifUserList" style="max-height:300px;overflow:auto;border:1px solid var(--gray-border);border-radius:8px;padding:4px">
        ${_cfgNotifUserListHtml(users, '')}
      </div>
      <div id="cfgNotifCount" style="font-size:12px;color:var(--gray-text);margin-top:8px">
        ${_cfgNotifSelectedUsers.length} sélectionné(s)
      </div>`;
  }
}

function _cfgNotifUserListHtml(users, filter) {
  const f = (filter||'').toLowerCase();
  const filtered = f ? users.filter(u =>
    (u.Login||'').toLowerCase().includes(f)||(u.Nom||'').toLowerCase().includes(f)||
    (u.Prenom||'').toLowerCase().includes(f)||(u.Service||'').toLowerCase().includes(f)||
    (u.Role||'').toLowerCase().includes(f)
  ) : users;
  if (!filtered.length) return '<div style="padding:12px;font-size:12px;color:var(--gray-text)">Aucun résultat</div>';
  return filtered.map(u => {
    const checked = _cfgNotifSelectedUsers.includes(u.Id) ? 'checked' : '';
    const initiales = ((u.Prenom?.[0]||'')+(u.Nom?.[0]||'')).toUpperCase()||'?';
    const nom = _escCfg(((u.Prenom||'')+' '+(u.Nom||'')).trim() || u.Login);
    return `<label style="display:flex;align-items:center;gap:10px;padding:6px 10px;cursor:pointer;border-bottom:1px solid var(--gray-border);font-size:13px"
      onmouseover="this.style.background='var(--gray-bg)'" onmouseout="this.style.background=''">
      <input type="checkbox" class="cfgNotif-user-cb" value="${u.Id}" ${checked}
        onchange="_cfgNotifToggleUser(${u.Id},this.checked)" style="accent-color:var(--blue);flex-shrink:0">
      <span style="display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:50%;background:var(--blue);color:white;font-size:9px;font-weight:700;flex-shrink:0">${initiales}</span>
      <span style="flex:1">${nom}</span>
      <span style="font-size:11px;color:var(--gray-text)">${_escCfg(u.Service||'')}</span>
      <span style="font-size:10px;padding:2px 8px;background:var(--gray-bg);border-radius:4px">${_escCfg(u.Role)}</span>
    </label>`;
  }).join('');
}
function _cfgNotifFilterUsers() {
  const el = document.getElementById('cfgNotifUserList');
  if (!el) return;
  el.innerHTML = _cfgNotifUserListHtml((_cfgNotifUsersCache||[]).filter(u=>u.Actif==1), document.getElementById('cfgNotifSearch')?.value||'');
}
function _cfgNotifToggleUser(id, chk) {
  if (chk && !_cfgNotifSelectedUsers.includes(id)) _cfgNotifSelectedUsers.push(id);
  if (!chk) _cfgNotifSelectedUsers = _cfgNotifSelectedUsers.filter(x=>x!==id);
  const el = document.getElementById('cfgNotifCount');
  if (el) el.textContent = _cfgNotifSelectedUsers.length + ' sélectionné(s)';
}
function _cfgNotifSelectAll(sel) {
  const users = (_cfgNotifUsersCache||[]).filter(u=>u.Actif==1);
  const f = (document.getElementById('cfgNotifSearch')?.value||'').toLowerCase();
  const filtered = f ? users.filter(u=>(u.Login||'').toLowerCase().includes(f)||(u.Nom||'').toLowerCase().includes(f)||(u.Prenom||'').toLowerCase().includes(f)) : users;
  if (sel) { filtered.forEach(u=>{if(!_cfgNotifSelectedUsers.includes(u.Id))_cfgNotifSelectedUsers.push(u.Id);}); }
  else { const ids=filtered.map(u=>u.Id); _cfgNotifSelectedUsers=_cfgNotifSelectedUsers.filter(id=>!ids.includes(id)); }
  document.querySelectorAll('.cfgNotif-user-cb').forEach(cb=>{cb.checked=sel;});
  const el = document.getElementById('cfgNotifCount');
  if (el) el.textContent = _cfgNotifSelectedUsers.length + ' sélectionné(s)';
}

function _cfgNotifGetTarget() {
  const users = (_cfgNotifUsersCache||[]).filter(u=>u.Actif==1);
  if (_cfgNotifMode==='roles') {
    const roles=[...document.querySelectorAll('.cfgNotif-role-cb:checked')].map(cb=>cb.value);
    if (!roles.length) return {error:'Sélectionnez au moins un rôle.'};
    const count=users.filter(u=>roles.includes(u.Role)).length;
    return {roles, label:roles.join(', ')+' ('+count+' util.)', count};
  }
  if (_cfgNotifMode==='services') {
    const svcs=[...document.querySelectorAll('.cfgNotif-svc-cb:checked')].map(cb=>cb.value);
    if (!svcs.length) return {error:'Sélectionnez au moins un service.'};
    const ids=users.filter(u=>svcs.includes(u.Service)).map(u=>u.Id);
    if (!ids.length) return {error:'Aucun utilisateur dans ces services.'};
    return {userIds:ids, label:'Services: '+svcs.join(', ')+' ('+ids.length+')', count:ids.length};
  }
  if (_cfgNotifMode==='custom') {
    if (!_cfgNotifSelectedUsers.length) return {error:'Sélectionnez au moins un utilisateur.'};
    const names=users.filter(u=>_cfgNotifSelectedUsers.includes(u.Id)).map(u=>((u.Prenom||'')+' '+(u.Nom||'')).trim()||u.Login);
    const label=names.length<=3?names.join(', '):names.slice(0,3).join(', ')+' +'+(names.length-3)+' autre(s)';
    return {userIds:[..._cfgNotifSelectedUsers], label, count:_cfgNotifSelectedUsers.length};
  }
  return {error:'Mode inconnu.'};
}

function _cfgNotifPreview() {
  const titre=gv('cfgNotif_titre').trim(), corps=gv('cfgNotif_corps').trim();
  const c=document.getElementById('cfgNotifPreview');
  if (!c) return;
  if (!titre||!corps) { toast('Remplissez le titre et le corps.','error'); c.innerHTML=''; return; }
  const t=_cfgNotifGetTarget();
  if (t.error) { toast(t.error,'error'); c.innerHTML=''; return; }
  c.innerHTML=`
  <div class="srv-section" style="border:2px solid var(--blue)">
    <div class="srv-section-title"><span>👁️</span> Aperçu</div>
    <div style="display:flex;gap:14px;align-items:flex-start">
      <img src="/icon.png" style="width:40px;height:40px;border-radius:10px;flex-shrink:0">
      <div style="flex:1">
        <div style="font-weight:700;font-size:14px;margin-bottom:4px">${_escCfg(titre)}</div>
        <div style="font-size:13px;line-height:1.5">${_escCfg(corps)}</div>
      </div>
    </div>
    <div style="margin-top:14px;padding-top:12px;border-top:1px solid var(--gray-border);font-size:12px;color:var(--gray-text)">
      <strong>Destinataires :</strong> ${_escCfg(t.label)}<br>
      <strong>Au clic :</strong> ${gv('cfgNotif_page')?_escCfg(gv('cfgNotif_page')):'<em>aucune action</em>'}
    </div>
  </div>`;
}

async function _cfgNotifSend() {
  const titre=gv('cfgNotif_titre').trim(), corps=gv('cfgNotif_corps').trim(), page=gv('cfgNotif_page');
  const r=document.getElementById('cfgNotifSendResult');
  if (!titre||!corps) { toast('Remplissez le titre et le corps.','error'); return; }
  const t=_cfgNotifGetTarget();
  if (t.error) { toast(t.error,'error'); return; }
  if (!confirm('Envoyer la notification à '+t.count+' utilisateur(s) ?\n\n« '+titre+' »\n'+corps)) return;
  if (r) r.innerHTML='<span style="color:var(--gray-text)">⏳ Envoi…</span>';
  try {
    const payload={title:titre,body:corps,page:page||''};
    if (t.roles) payload.roles=t.roles;
    if (t.userIds) payload.userIds=t.userIds;
    const resp=await apiRequest('push_send','POST',payload);
    let html=`<span style="color:var(--green,#16a34a)">✅ ${resp.sent}/${resp.total} appareil(s)</span>`;
    if (resp.failed>0) html+=` <span style="color:var(--orange,#d97706)">(${resp.failed} échec(s))</span>`;
    if (r) r.innerHTML=html;
    toast('Notification envoyée à '+resp.sent+' appareil(s) !','success');
  } catch(e) { if (r) r.innerHTML=`<span style="color:var(--red)">❌ ${_escCfg(e.message)}</span>`; toast(e.message,'error'); }
}

// ═══════════════════════════════════════════════════════════════════════════════
//  ONGLET INVENTAIRE — Activation de l'inventaire agent (demandeur)
// ═══════════════════════════════════════════════════════════════════════════════
function _renderTabInventaire(config) {
  const val = (config.find(r => r.Cle === 'inventaire_agent_actif')?.Valeur) || '0';
  const actif = val === '1';

  return `
  <div style="padding:0 4px">
    <div class="srv-section">
      <div class="srv-section-title"><span>📦</span> Inventaire par les agents (demandeurs)</div>
      <div class="srv-alert srv-alert-info" style="margin-bottom:16px">
        ℹ️ Lorsqu'activé, un onglet <strong>« Mes biens »</strong> apparaît pour les demandeurs.
        Ils peuvent <strong>déclarer la présence</strong> d'un bien dans leur bureau (saisie ou scan)
        ou <strong>signaler un départ</strong>. Chaque déclaration remonte ici pour <strong>validation</strong>
        avant de modifier la fiche du bien.
      </div>
      <div class="srv-grid-1">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 18px;background:var(--gray-bg);border-radius:10px;border:1px solid var(--gray-border)">
          <div>
            <div style="font-size:13px;font-weight:700">Activer l'inventaire agent</div>
            <div style="font-size:12px;color:var(--gray-text);margin-top:2px">Les demandeurs verront l'onglet « Mes biens » dans leur menu</div>
          </div>
          <label class="srv-toggle" style="margin:0">
            <input type="checkbox" id="cfg_inventaire_agent" ${actif ? 'checked' : ''}
              ${App.currentUser?.Role !== 'Admin' && App.currentUser?.Role !== 'Gestionnaire' ? 'disabled' : ''}
              onchange="_cfgSaveInventaireAgent(this.checked)">
          </label>
        </div>
      </div>

      ${actif ? `
      <div style="margin-top:24px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
          <div style="font-size:14px;font-weight:700">📋 Déclarations des agents</div>
          <div style="display:flex;gap:6px">
            <button class="decl-filter-btn active" data-filter="en_attente" onclick="_declFilterClick(this)">⏳ En attente</button>
            <button class="decl-filter-btn" data-filter="valide" onclick="_declFilterClick(this)">✅ Validées</button>
            <button class="decl-filter-btn" data-filter="refuse" onclick="_declFilterClick(this)">❌ Refusées</button>
            <button class="decl-filter-btn" data-filter="" onclick="_declFilterClick(this)">Toutes</button>
          </div>
        </div>
        <div id="cfg_declarations_list"><div style="text-align:center;padding:20px;color:var(--gray-text)">⏳ Chargement…</div></div>
      </div>
      <style>
        .decl-filter-btn{padding:5px 12px;border:1px solid var(--gray-border);border-radius:6px;background:var(--card-bg,#fff);cursor:pointer;font-size:11px;font-weight:600;color:var(--gray-text);transition:all .2s}
        .decl-filter-btn:hover{border-color:var(--blue);color:var(--blue)}
        .decl-filter-btn.active{background:var(--blue);color:#fff;border-color:var(--blue)}
        .decl-card{background:var(--card-bg,#fff);border:1px solid var(--gray-border);border-radius:10px;padding:14px 16px;margin-bottom:10px;transition:border-color .2s}
        .decl-card:hover{border-color:var(--blue)}
        .decl-card.doublon{border-left:4px solid #f59e0b}
        .decl-badge{display:inline-flex;align-items:center;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700}
        .decl-badge.presence{background:#dbeafe;color:#1d4ed8}
        .decl-badge.depart{background:#fee2e2;color:#991b1b}
        .decl-info{display:grid;grid-template-columns:1fr 1fr 1fr;gap:4px 12px;font-size:12px;color:var(--gray-text);margin-top:8px}
        .decl-info strong{color:var(--text)}
        .decl-actions{display:flex;gap:6px;margin-top:10px;justify-content:flex-end}
        .decl-btn-ok{padding:6px 14px;border:none;border-radius:6px;background:#16a34a;color:#fff;font-weight:600;font-size:12px;cursor:pointer}
        .decl-btn-ok:hover{opacity:.9}
        .decl-btn-no{padding:6px 14px;border:none;border-radius:6px;background:#dc2626;color:#fff;font-weight:600;font-size:12px;cursor:pointer}
        .decl-btn-no:hover{opacity:.9}
        @media(max-width:700px){.decl-info{grid-template-columns:1fr 1fr}}
      </style>
      ` : ''}

      <div style="margin-top:20px">
        <div style="font-size:13px;font-weight:700;margin-bottom:10px">Ce que les demandeurs peuvent faire :</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
          <div style="padding:12px 16px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;font-size:12px">
            <div style="font-weight:700;color:#15803d;margin-bottom:4px">✅ Autorisé</div>
            <div style="color:#166534;line-height:1.6">
              Voir les biens qui leur sont affectés<br>
              Déclarer un bien par numéro ou scan<br>
              Signaler le départ d'un bien<br>
              Indiquer bâtiment, étage, n° bureau
            </div>
          </div>
          <div style="padding:12px 16px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;font-size:12px">
            <div style="font-weight:700;color:#dc2626;margin-bottom:4px">❌ Interdit</div>
            <div style="color:#991b1b;line-height:1.6">
              Modifier directement un bien<br>
              Modifier la famille / sous-famille<br>
              Modifier le statut ou l'état<br>
              Modifier les finances<br>
              Supprimer un bien
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>`;
}

async function _cfgSaveInventaireAgent(checked) {
  try {
    await ConfigApi.set('inventaire_agent_actif', checked ? '1' : '0');
    toast(checked ? 'Inventaire agent activé.' : 'Inventaire agent désactivé.', 'success');
    renderConfiguration();
  } catch(e) {
    toast(e.message, 'error');
  }
}

// ═══════════════════════════════════════════════════════════════════════════════
//  ONGLET PLANS — Activation de l'onglet Plans / annuaire (demandeur)
//  Séparé de l'onglet Inventaire pour ne plus mélanger les deux fonctionnalités.
// ═══════════════════════════════════════════════════════════════════════════════
function _renderTabPlans(config) {
  const plansActif = ((config.find(r => r.Cle === 'plans_demandeur_actif')?.Valeur) || '0') === '1';
  const canManage = App.currentUser?.Role === 'Admin' || App.currentUser?.Role === 'Gestionnaire';

  return `
  <div style="padding:0 4px">
    <div class="srv-section">
      <div class="srv-section-title"><span>🗺️</span> Plans / annuaire (demandeurs)</div>
      <div class="srv-alert srv-alert-info" style="margin-bottom:16px">
        ℹ️ Lorsqu'activé, un onglet <strong>« Plans »</strong> apparaît pour les demandeurs : ils peuvent
        localiser une personne / un service sur le plan d'un étage et consulter sa fiche.
        Le partage de la présence et de l'agenda reste soumis au consentement individuel de chaque personne.
      </div>
      <div class="srv-grid-1">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 18px;background:var(--gray-bg);border-radius:10px;border:1px solid var(--gray-border)">
          <div>
            <div style="font-size:13px;font-weight:700">Activer l'onglet Plans</div>
            <div style="font-size:12px;color:var(--gray-text);margin-top:2px">Les demandeurs verront l'onglet « Plans » dans leur menu</div>
          </div>
          <label class="srv-toggle" style="margin:0">
            <input type="checkbox" id="cfg_plans_demandeur" ${plansActif ? 'checked' : ''}
              ${canManage ? '' : 'disabled'}
              onchange="_cfgSavePlansDemandeur(this.checked)">
          </label>
        </div>
      </div>
    </div>

    <!-- ── Mots-clés du comptage de présence ─────────────────────────────── -->
    <div class="srv-section">
      <div class="srv-section-title"><span>👥</span> Comptage de présence — mots-clés d'agenda</div>
      <div class="srv-alert srv-alert-info" style="margin-bottom:16px">
        ℹ️ Le bandeau du tableau de bord estime combien de personnes sont <strong>sur place</strong>
        en retirant, parmi les personnes connectées, celles qui ont déclaré du télétravail ou une absence
        dans leur agenda du jour. Ces listes définissent les intitulés reconnus.<br>
        <span style="color:var(--gray-text)">Séparez par des virgules. Les mots de 4 caractères ou moins
        (« TT », « CP ») exigent une correspondance exacte, pour éviter que « nettoyage » ou « attente »
        ne déclenchent une détection. Les rendez-vous marqués privés ne sont jamais lus.</span>
      </div>
      <div class="srv-grid-1" style="display:flex;flex-direction:column;gap:14px">
        <div>
          <label class="form-label" style="font-size:12px;font-weight:700">🏠 Télétravail</label>
          <input class="form-control" id="cfgMotsTT" ${canManage ? '' : 'disabled'}
            placeholder="TT,télétravail,teletravail,remote,home office,distanciel"
            value="${_cfgEsc((config.find(r => r.Cle === 'presence_mots_teletravail')?.Valeur) || '')}">
          <div style="font-size:11px;color:var(--gray-text);margin-top:3px">Vide = liste par défaut</div>
        </div>
        <div>
          <label class="form-label" style="font-size:12px;font-weight:700">🌴 Absence</label>
          <input class="form-control" id="cfgMotsAbs" ${canManage ? '' : 'disabled'}
            placeholder="congé,CP,RTT,JRTT,absent,vacances,arrêt,maladie,repos,récup,plage fixe"
            value="${_cfgEsc((config.find(r => r.Cle === 'presence_mots_absence')?.Valeur) || '')}">
          <div style="font-size:11px;color:var(--gray-text);margin-top:3px">
            Ajoutez ici vos usages internes (ex. « plage fixe » pour une récupération d'heures)
          </div>
        </div>
        ${canManage ? `<div><button class="btn btn-primary btn-sm" onclick="_cfgSaveMotsPresence()">💾 Enregistrer les mots-clés</button></div>` : ''}
      </div>
    </div>
  </div>`;
}

/** Échappement pour un attribut value (les mots-clés sont saisis librement). */
function _cfgEsc(v) {
  return String(v ?? '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
}

/**
 * Enregistre les deux listes de mots-clés. Stockées dans la table Configuration,
 * donc propres à chaque tenant : deux organisations peuvent avoir des usages
 * différents (« plage fixe », « ASA », « JRTT »…) sans se marcher dessus.
 */
async function _cfgSaveMotsPresence() {
  try {
    await ConfigApi.set('presence_mots_teletravail', document.getElementById('cfgMotsTT')?.value.trim() || '');
    await ConfigApi.set('presence_mots_absence',     document.getElementById('cfgMotsAbs')?.value.trim() || '');
    toast('Mots-clés enregistrés. Le compteur se met à jour d\'ici une minute (cache).', 'success');
  } catch (e) {
    toast(e.message, 'error');
  }
}

// Activation de l'onglet Plans (annuaire) pour les demandeurs
async function _cfgSavePlansDemandeur(checked) {
  try {
    await ConfigApi.set('plans_demandeur_actif', checked ? '1' : '0');
    toast(checked ? 'Onglet Plans activé pour les demandeurs.' : 'Onglet Plans masqué.', 'success');
    renderConfiguration();
  } catch(e) {
    toast(e.message, 'error');
  }
}

// ═══════════════════════════════════════════════════════════════════════════════
//  ONGLET 6 — 🚨 PROCÉDURES D'URGENCE
// ═══════════════════════════════════════════════════════════════════════════════
//
//  Le gestionnaire configure ici qui contacter selon le type de problème.
//  Stockage : 2 clés dans la table Configuration
//    - urgences_actif  : '0' ou '1' (active l'onglet pour tous les rôles)
//    - urgences_config : JSON { categories: [{ id, icon, titre, description,
//                                              masquee?, contacts: [{nom, role,
//                                              tel, mail, note}] }] }
//
//  L'état en cours d'édition vit dans la variable module `_urgState`, qui est
//  reconstruite à chaque rendu pour rester en phase avec le DOM.
// ═══════════════════════════════════════════════════════════════════════════════

let _urgState = null; // { actif:bool, categories: [...] }

const URG_CATEGORIES_DEFAUT_CONFIG = [
  { id:'incendie',    icon:'🔥', titre:'Incendie / fumée',         description:'Feu, fumée suspecte, déclenchement alarme incendie' },
  { id:'medical',     icon:'🚑', titre:'Urgence médicale',         description:'Blessure, malaise, accident corporel' },
  { id:'intrusion',   icon:'🚔', titre:'Intrusion / sécurité',     description:'Personne suspecte, vol, agression, violence' },
  { id:'plomberie',   icon:'💧', titre:'Plomberie / fuite d\'eau', description:'Fuite, dégât des eaux, canalisation bouchée' },
  { id:'electricite', icon:'⚡', titre:'Électricité / panne',       description:'Coupure générale, court-circuit, étincelles' },
  { id:'gaz',         icon:'🟡', titre:'Fuite de gaz',             description:'Odeur de gaz, sifflement suspect' },
  { id:'autre',       icon:'❓', titre:'Autre problème',            description:'Cas non listés ci-dessus' },
];

async function _loadUrgencesConfig() {
  const slot = document.getElementById('cfg_urgences_slot');
  if (!slot) return;
  try {
    // On lit la table de config en une fois (déjà accessible aux Admin/Gestionnaire)
    const all = await ConfigApi.getAll();
    const actifRow  = all.find(r => r.Cle === 'urgences_actif');
    const configRow = all.find(r => r.Cle === 'urgences_config');
    const actif = (actifRow?.Valeur || '0') === '1';
    let categories = URG_CATEGORIES_DEFAUT_CONFIG.map(c => ({
      ...c, visibilite: 'tous', procedure: '', medias: [], contacts: [],
    }));
    if (configRow?.Valeur) {
      try {
        const parsed = JSON.parse(configRow.Valeur);
        if (parsed && Array.isArray(parsed.categories)) categories = parsed.categories;
      } catch(_) { /* on garde les défauts */ }
    }
    _urgState = { actif, categories };
    slot.innerHTML = _renderTabUrgences();
    _urgSetupActionBar();
  } catch(e) {
    slot.innerHTML = '<div style="padding:30px;text-align:center;color:#e74c3c">Erreur de chargement : ' + (e.message || 'inconnue') + '</div>';
  }
}

function _renderTabUrgences() {
  if (!_urgState) return '<div style="padding:30px;text-align:center">⏳</div>';
  const role = App.currentUser?.Role;
  const editable = (role === 'Admin' || role === 'Gestionnaire');
  const actif = !!_urgState.actif;

  return `
  <style>
    .urgc-section { background:var(--card-bg,#fff); border:1px solid var(--gray-border); border-radius:12px; padding:18px; margin-bottom:16px; }
    .urgc-section-title { font-size:14px; font-weight:700; margin-bottom:12px; display:flex; align-items:center; gap:8px; }
    .urgc-cat {
      border:1px solid var(--gray-border); border-radius:10px;
      padding:14px 16px; margin-bottom:12px; background:var(--card-bg,#fff);
    }
    .urgc-cat.masquee { opacity:0.55; }
    .urgc-cat-head {
      display:flex; gap:10px; align-items:flex-start;
      padding-bottom:10px; margin-bottom:10px;
      border-bottom:1px solid var(--gray-border);
    }
    .urgc-cat-icon-input {
      width:48px; height:48px; text-align:center; font-size:24px;
      border:1px solid var(--gray-border); border-radius:8px;
      background:var(--gray-bg); flex-shrink:0;
    }
    .urgc-cat-fields { flex:1; min-width:0; display:flex; flex-direction:column; gap:6px; }
    .urgc-cat-titre {
      font-size:14px; font-weight:700; border:1px solid var(--gray-border);
      border-radius:6px; padding:6px 10px; background:var(--card-bg,#fff); color:var(--text);
      font-family:inherit;
    }
    .urgc-cat-desc {
      font-size:12px; border:1px solid var(--gray-border);
      border-radius:6px; padding:6px 10px; background:var(--card-bg,#fff); color:var(--text-mid);
      font-family:inherit;
    }
    .urgc-cat-actions { display:flex; flex-direction:column; gap:4px; flex-shrink:0; }
    .urgc-mini-btn {
      width:32px; height:32px; border:1px solid var(--gray-border);
      border-radius:6px; background:var(--card-bg,#fff); cursor:pointer;
      font-size:14px; display:flex; align-items:center; justify-content:center;
      transition:all .15s;
    }
    .urgc-mini-btn:hover { border-color:var(--blue); }
    .urgc-mini-btn.danger:hover { border-color:#dc2626; background:#fef2f2; }
    .urgc-contact {
      background:var(--gray-bg); border-radius:8px; padding:10px;
      margin-top:8px; display:grid; gap:6px;
      grid-template-columns: 1.2fr 1fr 1fr 1.4fr auto;
      align-items:start;
    }
    .urgc-contact input {
      border:1px solid var(--gray-border); border-radius:6px;
      padding:6px 8px; font-size:12px; font-family:inherit;
      background:var(--card-bg,#fff); color:var(--text); min-width:0;
    }
    .urgc-contact-note {
      grid-column: 1 / -1;
    }
    @media (max-width: 800px) {
      .urgc-contact { grid-template-columns: 1fr 1fr; }
      .urgc-contact-note { grid-column: 1 / -1; }
    }
    .urgc-add-contact-btn {
      width:100%; padding:8px; margin-top:8px;
      border:1px dashed var(--gray-border); border-radius:8px;
      background:transparent; cursor:pointer; font-size:12px; color:var(--gray-text);
      font-family:inherit;
    }
    .urgc-add-contact-btn:hover { border-color:var(--blue); color:var(--blue); background:var(--blue-pale); }
    .urgc-add-cat-btn {
      width:100%; padding:14px; margin-top:8px;
      border:2px dashed var(--gray-border); border-radius:10px;
      background:transparent; cursor:pointer; font-size:13px; font-weight:600; color:var(--gray-text);
      font-family:inherit; transition:all .15s;
    }
    .urgc-add-cat-btn:hover { border-color:var(--blue); color:var(--blue); background:var(--blue-pale); }


    /* ── Sélecteur de visibilité (global et par catégorie) ── */
    .urgc-vis-choices { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
    @media (max-width: 700px) { .urgc-vis-choices { grid-template-columns:1fr; } }
    .urgc-vis-choice {
      display:flex; gap:10px; align-items:flex-start;
      border:2px solid var(--gray-border); border-radius:10px;
      padding:12px 14px; cursor:pointer; background:var(--card-bg,#fff);
      transition:border-color .15s, background .15s;
    }
    .urgc-vis-choice:hover { border-color:var(--blue); }
    .urgc-vis-choice.selected { border-color:var(--blue); background:var(--blue-pale,#eff6ff); }
    .urgc-vis-choice input { margin-top:2px; flex-shrink:0; accent-color:var(--blue); }
    .urgc-vis-choice-txt { min-width:0; }
    .urgc-vis-choice-titre { font-size:13px; font-weight:700; color:var(--text); }
    .urgc-vis-choice-desc { font-size:11px; color:var(--gray-text); margin-top:2px; line-height:1.4; }

    /* Sélecteur compact au niveau d'une catégorie */
    .urgc-cat-vis {
      display:flex; align-items:center; gap:8px; flex-wrap:wrap;
      background:var(--gray-bg); border-radius:8px; padding:8px 10px; margin-bottom:10px;
    }
    .urgc-cat-vis-label { font-size:11px; font-weight:700; color:var(--gray-text); text-transform:uppercase; letter-spacing:.4px; }
    .urgc-cat-vis select {
      border:1px solid var(--gray-border); border-radius:6px; padding:5px 8px;
      font-size:12px; font-family:inherit; background:var(--card-bg,#fff); color:var(--text);
    }
    .urgc-badge-resto {
      font-size:10px; font-weight:700; padding:2px 7px; border-radius:20px;
      background:var(--urg-resto-bg); color:var(--urg-resto-fg); border:1px solid var(--urg-resto-bd);
    }

    /* ── Bloc procédure ── */
    .urgc-bloc { margin-top:12px; }
    .urgc-bloc-titre {
      font-size:11px; font-weight:700; color:var(--gray-text);
      text-transform:uppercase; letter-spacing:.4px; margin-bottom:6px;
      display:flex; align-items:center; gap:6px;
    }
    .urgc-procedure {
      width:100%; min-height:110px; resize:vertical;
      border:1px solid var(--gray-border); border-radius:8px;
      padding:10px 12px; font-size:13px; font-family:inherit; line-height:1.6;
      background:var(--card-bg,#fff); color:var(--text); box-sizing:border-box;
    }
    .urgc-procedure:focus { outline:none; border-color:var(--blue); }
    .urgc-hint { font-size:11px; color:var(--gray-text); margin-top:4px; line-height:1.45; }

    /* ── Médias (photos / vidéos) ── */
    .urgc-media-actions { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:10px; }
    .urgc-media-btn {
      display:inline-flex; align-items:center; gap:6px;
      padding:7px 12px; border:1px dashed var(--gray-border); border-radius:8px;
      background:transparent; cursor:pointer; font-size:12px; font-weight:600;
      color:var(--gray-text); font-family:inherit; transition:all .15s;
    }
    .urgc-media-btn:hover { border-color:var(--blue); color:var(--blue); background:var(--blue-pale,#eff6ff); }
    .urgc-media-btn:disabled { opacity:.5; cursor:not-allowed; }
    .urgc-media-grid {
      display:grid; grid-template-columns:repeat(auto-fill, minmax(150px, 1fr)); gap:10px;
    }
    .urgc-media-item {
      border:1px solid var(--gray-border); border-radius:10px; overflow:hidden;
      background:var(--gray-bg); position:relative;
    }
    .urgc-media-thumb {
      width:100%; height:100px; object-fit:cover; display:block; background:#000;
    }
    .urgc-media-video-badge {
      position:absolute; top:6px; left:6px; background:rgba(0,0,0,.65); color:#fff;
      font-size:10px; font-weight:700; padding:2px 6px; border-radius:5px;
    }
    .urgc-media-del {
      position:absolute; top:6px; right:6px; width:26px; height:26px;
      border:none; border-radius:6px; background:rgba(220,38,38,.92); color:#fff;
      cursor:pointer; font-size:12px; line-height:1; display:flex;
      align-items:center; justify-content:center;
    }
    .urgc-media-del:hover { background:#b91c1c; }
    .urgc-media-legende {
      width:100%; border:none; border-top:1px solid var(--gray-border);
      padding:6px 8px; font-size:11px; font-family:inherit; box-sizing:border-box;
      background:var(--card-bg,#fff); color:var(--text);
    }
    .urgc-media-legende:focus { outline:none; background:var(--blue-pale,#eff6ff); }
    .urgc-media-empty {
      font-size:11px; color:var(--gray-text); font-style:italic; padding:6px 0;
    }
    .urgc-upload-bar {
      height:4px; border-radius:2px; background:var(--gray-border);
      overflow:hidden; margin-top:8px;
    }
    .urgc-upload-bar span { display:block; height:100%; background:var(--blue); width:0; transition:width .2s; }
  </style>

  <div style="padding:0 4px">

    <!-- Visibilité globale de l'onglet -->
    <div class="urgc-section">
      <div class="urgc-section-title"><span>🚨</span> Qui voit l'onglet « Procédures d'urgence » ?</div>
      <div class="srv-alert srv-alert-info" style="margin-bottom:16px">
        ℹ️ Ce réglage pilote l'affichage de l'onglet dans le menu latéral.
        Les <strong>Gestionnaires et Admins</strong> y ont toujours accès, quel que soit le choix —
        c'est ce qui vous permet de préparer les procédures avant de les publier.<br>
        Vous pouvez ensuite affiner <strong>catégorie par catégorie</strong> plus bas :
        une procédure sensible peut rester réservée aux gestionnaires même si l'onglet est ouvert à tous.
      </div>

      <div class="urgc-vis-choices">
        <label class="urgc-vis-choice ${actif ? 'selected' : ''}">
          <input type="radio" name="urgc_visibilite" value="tous" ${actif ? 'checked' : ''}
            ${editable ? '' : 'disabled'} onchange="_urgSetVisibiliteGlobale('tous')">
          <span class="urgc-vis-choice-txt">
            <span class="urgc-vis-choice-titre">👥 Tout le monde</span>
            <span class="urgc-vis-choice-desc">
              Demandeurs, Visionneurs et Techniciens voient l'onglet dans leur menu,
              en plus des gestionnaires.
            </span>
          </span>
        </label>

        <label class="urgc-vis-choice ${actif ? '' : 'selected'}">
          <input type="radio" name="urgc_visibilite" value="gestionnaires" ${actif ? '' : 'checked'}
            ${editable ? '' : 'disabled'} onchange="_urgSetVisibiliteGlobale('gestionnaires')">
          <span class="urgc-vis-choice-txt">
            <span class="urgc-vis-choice-titre">🔒 Gestionnaires uniquement</span>
            <span class="urgc-vis-choice-desc">
              Seuls les Gestionnaires et Admins voient l'onglet. Les autres rôles
              n'en reçoivent aucune donnée.
            </span>
          </span>
        </label>
      </div>
    </div>

    <!-- Éditeur de catégories -->
    <div class="urgc-section">
      <div class="urgc-section-title"><span>📋</span> Catégories et contacts</div>
      <div style="font-size:12px;color:var(--gray-text);margin-bottom:14px;line-height:1.5">
        Pour chaque type de problème, renseignez les contacts à joindre (nom, fonction, téléphone, mail).
        Les utilisateurs pourront appeler ou envoyer un mail en un clic depuis l'onglet d'urgence.
        ${editable ? '' : '<br><strong>Lecture seule</strong> — seul un Admin ou Gestionnaire peut modifier.'}
      </div>

      <div id="urgc_categories_list">
        ${_urgState.categories.map((cat, i) => _urgRenderCategorieEditor(cat, i, editable)).join('')}
      </div>

      ${editable ? `
        <button class="urgc-add-cat-btn" onclick="_urgAddCategorie()">
          ➕ Ajouter une catégorie personnalisée
        </button>
      ` : ''}
    </div>

  </div>
  `;
}

/**
 * Dépose les boutons Annuler / Enregistrer dans #pageActionBar.
 *
 * Historique : la barre était un <div class="urgc-save-bar"> en
 * « position:sticky; bottom:0 » à la fin du flux. Elle ne se détachait pas du
 * bas de l'écran de façon fiable. #pageActionBar est un frère de #mainContent
 * dans la colonne flex .main : il occupe une vraie place en bas de la zone de
 * contenu, donc plus rien à négocier avec le conteneur de défilement.
 */
function _urgSetupActionBar() {
  const role = App.currentUser?.Role;
  const editable = (role === 'Admin' || role === 'Gestionnaire');
  if (typeof setPageActionBar !== 'function') return;
  if (!editable) { clearPageActionBar(); return; }
  setPageActionBar(`
    <span class="page-action-info">Les procédures d'urgence ne sont publiées qu'après enregistrement.</span>
    <button class="btn btn-ghost" onclick="_loadUrgencesConfig()">↺ Annuler les modifications</button>
    <button class="btn btn-primary" onclick="_urgSave()">💾 Enregistrer</button>
  `);
}

function _urgRenderCategorieEditor(cat, idx, editable) {
  const disabled   = editable ? '' : 'disabled';
  const masquee    = !!cat.masquee;
  // 'tous' par défaut → les configurations existantes restent visibles par tous
  const visibilite = (cat.visibilite === 'gestionnaires') ? 'gestionnaires' : 'tous';
  const medias     = Array.isArray(cat.medias) ? cat.medias : [];
  return `
    <div class="urgc-cat ${masquee ? 'masquee' : ''}" data-cat-idx="${idx}">
      <div class="urgc-cat-head">
        <input type="text" class="urgc-cat-icon-input" maxlength="3"
          value="${_urgEscapeAttr(cat.icon || '❓')}"
          oninput="_urgUpdateCat(${idx}, 'icon', this.value)" ${disabled}
          title="Emoji ou symbole">
        <div class="urgc-cat-fields">
          <input type="text" class="urgc-cat-titre"
            value="${_urgEscapeAttr(cat.titre || '')}"
            placeholder="Titre (ex: Incendie / fumée)"
            oninput="_urgUpdateCat(${idx}, 'titre', this.value)" ${disabled}>
          <input type="text" class="urgc-cat-desc"
            value="${_urgEscapeAttr(cat.description || '')}"
            placeholder="Description courte (visible par les utilisateurs)"
            oninput="_urgUpdateCat(${idx}, 'description', this.value)" ${disabled}>
        </div>
        ${editable ? `
          <div class="urgc-cat-actions">
            <button class="urgc-mini-btn" onclick="_urgToggleMasquee(${idx})"
              title="${masquee ? 'Rendre visible' : 'Masquer cette catégorie'}">
              ${masquee ? '👁️' : '🚫'}
            </button>
            <button class="urgc-mini-btn danger" onclick="_urgDeleteCategorie(${idx})"
              title="Supprimer cette catégorie">🗑️</button>
          </div>
        ` : ''}
      </div>

      <!-- Visibilité de CETTE catégorie -->
      <div class="urgc-cat-vis">
        <span class="urgc-cat-vis-label">Visible par</span>
        <select onchange="_urgUpdateCat(${idx}, 'visibilite', this.value); _urgRerenderList()" ${disabled}>
          <option value="tous"          ${visibilite === 'tous' ? 'selected' : ''}>👥 Tout le monde</option>
          <option value="gestionnaires" ${visibilite === 'gestionnaires' ? 'selected' : ''}>🔒 Gestionnaires uniquement</option>
        </select>
        ${visibilite === 'gestionnaires'
          ? '<span class="urgc-badge-resto">Réservé</span>'
          : ''}
      </div>

      <!-- Procédure à suivre -->
      <div class="urgc-bloc">
        <div class="urgc-bloc-titre"><span>📋</span> Procédure à suivre</div>
        <textarea class="urgc-procedure" rows="5" ${disabled}
          placeholder="Décrivez les gestes à faire, une étape par ligne. Exemple :&#10;1. Déclencher l'alarme la plus proche&#10;2. Évacuer par la sortie la plus proche, sans emprunter l'ascenseur&#10;3. Rejoindre le point de rassemblement (parking nord)&#10;4. Ne jamais retourner dans le bâtiment"
          oninput="_urgUpdateCat(${idx}, 'procedure', this.value)">${_urgEscapeAttr(cat.procedure || '')}</textarea>
        <div class="urgc-hint">
          Une ligne = une étape. Les lignes commençant par un chiffre ou un tiret sont
          automatiquement mises en forme en liste numérotée pour les utilisateurs.
        </div>
      </div>

      <!-- Photos et vidéos -->
      <div class="urgc-bloc">
        <div class="urgc-bloc-titre"><span>🖼️</span> Photos et vidéos</div>
        ${editable ? `
          <div class="urgc-media-actions">
            <button type="button" class="urgc-media-btn" onclick="_urgPickMedia(${idx}, 'photo')">
              📷 Ajouter des photos
            </button>
            <button type="button" class="urgc-media-btn" onclick="_urgPickMedia(${idx}, 'video')">
              🎬 Ajouter des vidéos
            </button>
          </div>
          <div class="urgc-upload-bar" id="urgc_upload_${idx}" style="display:none"><span></span></div>
        ` : ''}
        ${medias.length === 0
          ? '<div class="urgc-media-empty">Aucun média. Une photo de l\'emplacement d\'une vanne ou d\'un extincteur fait souvent gagner un temps précieux.</div>'
          : `<div class="urgc-media-grid">
               ${medias.map((m, mi) => _urgRenderMediaEditor(idx, mi, m, editable)).join('')}
             </div>`}
      </div>

      <div class="urgc-bloc-titre" style="margin-top:14px"><span>📞</span> Contacts</div>
      <div data-contacts-for="${idx}">
        ${(cat.contacts || []).map((c, ci) => _urgRenderContactEditor(idx, ci, c, editable)).join('')}
      </div>

      ${editable ? `
        <button class="urgc-add-contact-btn" onclick="_urgAddContact(${idx})">
          ➕ Ajouter un contact
        </button>
      ` : ''}
    </div>
  `;
}

/**
 * Vignette éditable d'un média (photo ou vidéo) avec légende et suppression.
 */
function _urgRenderMediaEditor(catIdx, mediaIdx, m, editable) {
  const disabled = editable ? '' : 'disabled';
  const url      = (typeof UrgencesApi !== 'undefined')
                 ? UrgencesApi.mediaUrl(m.fichier || '')
                 : '';
  const nom      = _urgEscapeAttr(m.nom || '');

  // Pour une vidéo, <video preload="metadata"> affiche la première image sans
  // télécharger tout le fichier — suffisant comme vignette.
  const apercu = (m.type === 'video')
    ? `<video class="urgc-media-thumb" src="${url}#t=0.5" preload="metadata" muted playsinline></video>
       <span class="urgc-media-video-badge">🎬 VIDÉO</span>`
    : `<img class="urgc-media-thumb" src="${url}" alt="${nom}" loading="lazy">`;

  return `
    <div class="urgc-media-item" data-media-idx="${mediaIdx}" title="${nom}">
      ${apercu}
      ${editable ? `
        <button type="button" class="urgc-media-del"
          onclick="_urgDeleteMedia(${catIdx}, ${mediaIdx})"
          title="Supprimer ce média">🗑️</button>
      ` : ''}
      <input type="text" class="urgc-media-legende" placeholder="Légende (optionnelle)"
        value="${_urgEscapeAttr(m.legende || '')}"
        oninput="_urgUpdateMedia(${catIdx}, ${mediaIdx}, 'legende', this.value)" ${disabled}>
    </div>
  `;
}

function _urgRenderContactEditor(catIdx, contactIdx, c, editable) {
  const disabled = editable ? '' : 'disabled';
  return `
    <div class="urgc-contact" data-contact-idx="${contactIdx}">
      <input type="text" placeholder="Nom"
        value="${_urgEscapeAttr(c.nom || '')}"
        oninput="_urgUpdateContact(${catIdx}, ${contactIdx}, 'nom', this.value)" ${disabled}>
      <input type="text" placeholder="Fonction"
        value="${_urgEscapeAttr(c.role || '')}"
        oninput="_urgUpdateContact(${catIdx}, ${contactIdx}, 'role', this.value)" ${disabled}>
      <input type="tel" placeholder="Téléphone"
        value="${_urgEscapeAttr(c.tel || '')}"
        oninput="_urgUpdateContact(${catIdx}, ${contactIdx}, 'tel', this.value)" ${disabled}>
      <input type="email" placeholder="email@exemple.fr"
        value="${_urgEscapeAttr(c.mail || '')}"
        oninput="_urgUpdateContact(${catIdx}, ${contactIdx}, 'mail', this.value)" ${disabled}>
      ${editable ? `
        <button class="urgc-mini-btn danger" onclick="_urgDeleteContact(${catIdx}, ${contactIdx})"
          title="Supprimer ce contact">🗑️</button>
      ` : '<span></span>'}
      <input type="text" class="urgc-contact-note" placeholder="Note (optionnelle, ex: jours/heures de disponibilité)"
        value="${_urgEscapeAttr(c.note || '')}"
        oninput="_urgUpdateContact(${catIdx}, ${contactIdx}, 'note', this.value)" ${disabled}>
    </div>
  `;
}

function _urgEscapeAttr(s) {
  if (s == null) return '';
  return String(s).replace(/[&<>"']/g, ch => ({
    '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;'
  }[ch]));
}

// ── Handlers d'édition (mettent juste à jour l'état en mémoire) ─────────────

function _urgUpdateCat(idx, field, value) {
  if (!_urgState?.categories?.[idx]) return;
  _urgState.categories[idx][field] = value;
}

function _urgUpdateContact(catIdx, contactIdx, field, value) {
  const cat = _urgState?.categories?.[catIdx];
  if (!cat || !cat.contacts?.[contactIdx]) return;
  cat.contacts[contactIdx][field] = value;
}

function _urgUpdateMedia(catIdx, mediaIdx, field, value) {
  const cat = _urgState?.categories?.[catIdx];
  if (!cat || !Array.isArray(cat.medias) || !cat.medias[mediaIdx]) return;
  cat.medias[mediaIdx][field] = value;
}

// ── Médias : envoi et suppression ───────────────────────────────────────────
// Les formats acceptés doivent rester alignés sur _urg_ext_map() côté PHP
// (api/routes/urgences.php) : toute divergence produirait un refus serveur
// après un upload complet, ce qui est particulièrement pénible sur une vidéo.
const URG_ACCEPT_PHOTO = 'image/png,image/jpeg,image/gif,image/webp';
const URG_ACCEPT_VIDEO = 'video/mp4,video/webm,video/ogg,video/quicktime,.mp4,.m4v,.webm,.ogv,.mov';

/**
 * Ouvre le sélecteur de fichiers puis envoie les fichiers un par un.
 * `kind` vaut 'photo' ou 'video' — cela ne fait que pré-filtrer la boîte de
 * dialogue : le serveur reste seul juge du type réel (magic bytes).
 */
function _urgPickMedia(catIdx, kind) {
  if (typeof UrgencesApi === 'undefined') {
    toast('Client API indisponible — videz le cache (Ctrl+Shift+R).', 'error');
    return;
  }
  const input = document.createElement('input');
  input.type     = 'file';
  input.multiple = true;
  input.accept   = (kind === 'video') ? URG_ACCEPT_VIDEO : URG_ACCEPT_PHOTO;
  input.style.display = 'none';
  document.body.appendChild(input);
  input.addEventListener('change', async () => {
    const files = Array.from(input.files || []);
    input.remove();
    if (files.length) await _urgUploadMedias(catIdx, files);
  });
  input.click();
}

async function _urgUploadMedias(catIdx, files) {
  const cat = _urgState?.categories?.[catIdx];
  if (!cat) return;
  if (!Array.isArray(cat.medias)) cat.medias = [];

  // On fige la saisie en cours avant toute manipulation du DOM
  _urgSyncFromDom();

  const bar  = document.getElementById('urgc_upload_' + catIdx);
  const fill = bar?.querySelector('span');
  if (bar) bar.style.display = 'block';

  let ok = 0, erreurs = [];
  for (let i = 0; i < files.length; i++) {
    const f = files[i];
    try {
      if (fill) fill.style.width = '0%';
      const res = await UrgencesApi.uploadMedia(f, (pct) => {
        if (fill) fill.style.width = pct + '%';
      });
      cat.medias.push({
        id:      res.id      || ('m_' + Date.now() + '_' + i),
        type:    res.type    || 'photo',
        fichier: res.fichier || '',
        nom:     res.nom     || f.name,
        legende: '',
      });
      ok++;
    } catch (e) {
      erreurs.push((f.name || 'fichier') + ' : ' + (e.message || 'erreur inconnue'));
    }
  }

  if (bar) bar.style.display = 'none';
  if (fill) fill.style.width = '0%';

  _urgRerenderList();

  if (ok)            toast(ok + (ok > 1 ? ' médias ajoutés' : ' média ajouté') + " — pensez à cliquer sur « Enregistrer ».", 'success');
  if (erreurs.length) toast('Échec : ' + erreurs.join(' | '), 'error');
}

async function _urgDeleteMedia(catIdx, mediaIdx) {
  const cat = _urgState?.categories?.[catIdx];
  const m   = cat?.medias?.[mediaIdx];
  if (!m) return;
  if (!confirm('Supprimer définitivement ce média ?\n\n' + (m.nom || ''))) return;

  _urgSyncFromDom();
  try {
    if (m.fichier) await UrgencesApi.deleteMedia(m.fichier);
  } catch (e) {
    // Le fichier physique n'a pas pu être supprimé : on retire quand même la
    // référence pour ne pas afficher une vignette cassée à l'utilisateur.
    console.warn('[Urgences] Suppression du fichier échouée :', e);
  }
  cat.medias.splice(mediaIdx, 1);
  _urgRerenderList();
  toast("Média retiré — pensez à cliquer sur « Enregistrer ».", 'success');
}

function _urgAddCategorie() {
  if (!_urgState) return;
  _urgState.categories.push({
    id: 'custom_' + Date.now(),
    icon: '📌',
    titre: '',
    description: '',
    visibilite: 'tous',
    procedure: '',
    medias: [],
    contacts: [{ nom:'', role:'', tel:'', mail:'', note:'' }],
  });
  // Re-rendu de la liste seulement
  _urgRerenderList();
}

function _urgDeleteCategorie(idx) {
  if (!_urgState?.categories?.[idx]) return;
  if (!confirm('Supprimer définitivement cette catégorie et tous ses contacts ?\n\n(Vous pouvez aussi la masquer sans la supprimer.)')) return;
  _urgState.categories.splice(idx, 1);
  _urgRerenderList();
}

function _urgToggleMasquee(idx) {
  if (!_urgState?.categories?.[idx]) return;
  _urgState.categories[idx].masquee = !_urgState.categories[idx].masquee;
  _urgRerenderList();
}

function _urgAddContact(catIdx) {
  const cat = _urgState?.categories?.[catIdx];
  if (!cat) return;
  if (!Array.isArray(cat.contacts)) cat.contacts = [];
  cat.contacts.push({ nom:'', role:'', tel:'', mail:'', note:'' });
  _urgRerenderList();
}

function _urgDeleteContact(catIdx, contactIdx) {
  const cat = _urgState?.categories?.[catIdx];
  if (!cat?.contacts?.[contactIdx]) return;
  cat.contacts.splice(contactIdx, 1);
  _urgRerenderList();
}

// Re-rendu uniquement de la liste (pas du toggle ni de la save bar) pour
// éviter de perdre le focus actif si l'utilisateur est en train de taper.
// On collecte d'abord les valeurs des inputs depuis le DOM au cas où un
// oninput n'aurait pas encore eu lieu (debouncing IME, par exemple).
function _urgRerenderList() {
  _urgSyncFromDom();
  const editable = (App.currentUser?.Role === 'Admin' || App.currentUser?.Role === 'Gestionnaire');
  const list = document.getElementById('urgc_categories_list');
  if (list) {
    list.innerHTML = _urgState.categories.map((cat, i) => _urgRenderCategorieEditor(cat, i, editable)).join('');
  }
}

// Lit l'état actuel du DOM (au cas où un oninput n'aurait pas encore tiré)
// et le réinjecte dans _urgState. Sécurité contre la perte de saisie.
function _urgSyncFromDom() {
  if (!_urgState) return;
  const cards = document.querySelectorAll('#urgc_categories_list .urgc-cat');
  cards.forEach(card => {
    const idx = parseInt(card.dataset.catIdx, 10);
    const cat = _urgState.categories[idx];
    if (!cat) return;
    const inputs = card.querySelectorAll(':scope > .urgc-cat-head input');
    if (inputs[0]) cat.icon        = inputs[0].value;
    if (inputs[1]) cat.titre       = inputs[1].value;
    if (inputs[2]) cat.description = inputs[2].value;

    // Visibilité de la catégorie
    const visSel = card.querySelector(':scope > .urgc-cat-vis select');
    if (visSel) cat.visibilite = (visSel.value === 'gestionnaires') ? 'gestionnaires' : 'tous';

    // Procédure (textarea : jamais couverte par la boucle d'inputs ci-dessus)
    const proc = card.querySelector('.urgc-procedure');
    if (proc) cat.procedure = proc.value;

    // Légendes des médias
    const mediaItems = card.querySelectorAll('.urgc-media-item');
    mediaItems.forEach(mi => {
      const mIdx = parseInt(mi.dataset.mediaIdx, 10);
      const m = cat.medias?.[mIdx];
      if (!m) return;
      const leg = mi.querySelector('.urgc-media-legende');
      if (leg) m.legende = leg.value;
    });

    const contactDivs = card.querySelectorAll('[data-contacts-for] .urgc-contact');
    contactDivs.forEach(cd => {
      const ci = parseInt(cd.dataset.contactIdx, 10);
      const c = cat.contacts?.[ci];
      if (!c) return;
      const ins = cd.querySelectorAll('input');
      // Ordre des inputs cf. _urgRenderContactEditor : nom, role, tel, mail, note
      if (ins[0]) c.nom  = ins[0].value;
      if (ins[1]) c.role = ins[1].value;
      if (ins[2]) c.tel  = ins[2].value;
      if (ins[3]) c.mail = ins[3].value;
      if (ins[4]) c.note = ins[4].value;
    });
  });
}

// ── Activation et sauvegarde ────────────────────────────────────────────────

/**
 * Visibilité globale de l'onglet.
 *   'tous'          → urgences_actif = '1'
 *   'gestionnaires' → urgences_actif = '0'
 * On conserve volontairement la clé et le format historiques ('0'/'1') pour
 * rester compatible avec les installations existantes et avec nav.js.
 */
async function _urgSetVisibiliteGlobale(mode) {
  const tous = (mode === 'tous');
  // Mise à jour immédiate du style des deux cartes de choix
  document.querySelectorAll('.urgc-vis-choice').forEach(lab => {
    const radio = lab.querySelector('input[name="urgc_visibilite"]');
    lab.classList.toggle('selected', !!radio && radio.value === mode);
  });
  try {
    await ConfigApi.set('urgences_actif', tous ? '1' : '0');
    if (_urgState) _urgState.actif = tous;
    toast(tous
      ? 'Procédures d\'urgence visibles par tout le monde.'
      : 'Procédures d\'urgence réservées aux Gestionnaires et Admins.', 'success');
    // Reconstruire la sidebar de l'utilisateur courant pour refléter immédiatement
    if (typeof buildSidebarWithVisibility === 'function' && App.currentUser) {
      buildSidebarWithVisibility(App.currentUser);
    } else if (typeof buildSidebar === 'function' && App.currentUser) {
      buildSidebar(App.currentUser);
    }
  } catch(e) {
    toast(e.message || 'Erreur lors de la sauvegarde', 'error');
  }
}

async function _urgSave() {
  try {
    _urgSyncFromDom();
    // Nettoyage : on retire les contacts entièrement vides
    const clean = {
      categories: _urgState.categories.map(cat => ({
        id:          cat.id || ('cat_' + Date.now()),
        icon:        cat.icon || '❓',
        titre:       (cat.titre || '').trim(),
        description: (cat.description || '').trim(),
        masquee:     !!cat.masquee,
        // Défaut 'tous' : une config antérieure à cette fonctionnalité reste
        // visible exactement comme avant.
        visibilite:  (cat.visibilite === 'gestionnaires') ? 'gestionnaires' : 'tous',
        procedure:   (cat.procedure || '').trim(),
        medias:      (Array.isArray(cat.medias) ? cat.medias : [])
          .filter(m => m && m.fichier)
          .map(m => ({
            id:      m.id || ('m_' + Date.now()),
            type:    (m.type === 'video') ? 'video' : 'photo',
            fichier: String(m.fichier),
            nom:     (m.nom     || '').trim(),
            legende: (m.legende || '').trim(),
          })),
        contacts:    (cat.contacts || []).filter(c =>
          (c.nom || '').trim() || (c.tel || '').trim() || (c.mail || '').trim()
        ).map(c => ({
          nom:  (c.nom  || '').trim(),
          role: (c.role || '').trim(),
          tel:  (c.tel  || '').trim(),
          mail: (c.mail || '').trim(),
          note: (c.note || '').trim(),
        })),
      })),
    };
    await ConfigApi.set('urgences_config', JSON.stringify(clean));
    toast('Procédures d\'urgence enregistrées.', 'success');
    // Recharge propre pour repartir d'un état frais
    _loadUrgencesConfig();
  } catch(e) {
    toast(e.message || 'Erreur lors de la sauvegarde', 'error');
  }
}

// ── Déclarations inventaire : fonctions admin ───────────────────────────────
let _declCurrentFilter = 'en_attente';

function _declFilterClick(btn) {
  document.querySelectorAll('.decl-filter-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  _declCurrentFilter = btn.dataset.filter;
  _loadDeclarations();
}

async function _loadDeclarations() {
  const container = document.getElementById('cfg_declarations_list');
  if (!container) return;
  container.innerHTML = '<div style="text-align:center;padding:20px;color:var(--gray-text)">⏳ Chargement…</div>';

  try {
    const url = _declCurrentFilter
      ? 'declarations_inventaire&statut=' + _declCurrentFilter
      : 'declarations_inventaire';
    const decls = await apiRequest(url);
    _renderDeclarationsList(container, decls);
  } catch(e) {
    container.innerHTML = `<div style="color:#ef4444;padding:12px">${_escCfg(e.message)}</div>`;
  }
}

function _renderDeclarationsList(container, decls) {
  if (!decls.length) {
    container.innerHTML = `<div style="text-align:center;padding:30px;color:var(--gray-text)">
      <div style="font-size:28px;margin-bottom:8px">📭</div>
      <div>Aucune déclaration ${_declCurrentFilter === 'en_attente' ? 'en attente' : _declCurrentFilter === 'valide' ? 'validée' : _declCurrentFilter === 'refuse' ? 'refusée' : ''}</div>
    </div>`;
    return;
  }

  // Détecter les doublons (même BienId, plusieurs déclarations en attente)
  const bienCount = {};
  decls.forEach(d => {
    if (d.Statut === 'en_attente' && d.BienId) {
      bienCount[d.BienId] = (bienCount[d.BienId] || 0) + 1;
    }
  });

  const statusLabel = { en_attente: '⏳ En attente', valide: '✅ Validée', refuse: '❌ Refusée' };
  const typeLabel   = { presence: 'Présence', depart: 'Départ' };
  const esc = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

  let html = `<div style="font-size:12px;color:var(--gray-text);margin-bottom:10px">${decls.length} déclaration(s)</div>`;

  for (const d of decls) {
    const date = (d.DateDeclaration || '').replace('T', ' ').slice(0, 16);
    const isDbl = d.Statut === 'en_attente' && d.BienId && bienCount[d.BienId] > 1;

    html += `<div class="decl-card ${isDbl ? 'doublon' : ''}">
      <div style="display:flex;justify-content:space-between;align-items:start;flex-wrap:wrap;gap:6px">
        <div>
          <span style="font-weight:700;font-size:15px">${esc(d.NumeroBien)}</span>
          <span class="decl-badge ${escHtml(d.Type||'')}">${d.Type === 'depart' ? '📤 Départ / Mouvement' : '📦 Présence'}</span>
          ${isDbl ? '<span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;margin-left:4px">⚠️ DOUBLON</span>' : ''}
        </div>
        <span style="font-size:11px;color:var(--gray-text)">${esc(date)}</span>
      </div>

      <div style="margin-top:10px;display:grid;grid-template-columns:1fr 1fr;gap:4px 16px;font-size:12px;color:var(--gray-text)">
        <div><strong>Déclarant :</strong> ${esc(d.DeclarantNom || d.DeclarantLogin)}</div>
        <div><strong>Famille :</strong> ${esc(d.Famille || '—')} ${d.SousFamille ? '› '+esc(d.SousFamille) : ''}</div>
        ${d.InfoProduit ? `<div style="grid-column:span 2"><strong>Description :</strong> ${esc(d.InfoProduit)}</div>` : ''}
        ${d.NumeroSerie ? `<div><strong>N° Série :</strong> ${esc(d.NumeroSerie)}</div>` : ''}
      </div>

      ${d.Type === 'depart' ? `
      <div style="margin-top:12px;padding:10px 14px;background:var(--gray-bg);border-radius:8px;border:1px solid var(--gray-border)">
        <div style="font-size:12px;font-weight:700;margin-bottom:8px;color:var(--text)">📤 Détail du mouvement</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px 16px;font-size:12px;color:var(--gray-text)">
          <div><strong>Raison :</strong> ${esc({transfert_bureau:'📍 Transféré dans un autre bureau',donne_personne:'👤 Donné / prêté',retour_magasin:'📦 Retourné au stock',hors_service:'🔧 Hors service / en panne',autre:'❓ Autre'}[d.RaisonDepart] || d.RaisonDepart || '—')}</div>
          ${d.DestPersonne ? `<div><strong>Remis à :</strong> <span style="color:var(--blue);font-weight:600">${esc(d.DestPersonne)}</span></div>` : ''}
          ${d.DestBatiment ? `<div><strong>Nouveau bâtiment :</strong> ${esc(d.DestBatiment)}</div>` : ''}
          ${d.DestEtage ? `<div><strong>Nouvel étage :</strong> ${esc(d.DestEtage)}</div>` : ''}
          ${d.DestBureau ? `<div><strong>Nouveau bureau :</strong> ${esc(d.DestBureau)}</div>` : ''}
        </div>
      </div>
      ` : ''}

      ${d.Type === 'presence' ? `
      <div style="margin-top:12px;padding:10px 14px;background:#eff6ff;border-radius:8px;border:1px solid #bfdbfe">
        <div style="font-size:12px;font-weight:700;margin-bottom:8px;color:#1d4ed8">📍 Localisation déclarée</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px 16px;font-size:12px;color:var(--gray-text)">
          ${d.Batiment ? `<div><strong>Bâtiment :</strong> ${esc(d.Batiment)}</div>` : ''}
          ${d.Etage ? `<div><strong>Étage :</strong> ${esc(d.Etage)}</div>` : ''}
          ${d.NumeroBureau ? `<div><strong>Bureau :</strong> ${esc(d.NumeroBureau)}</div>` : ''}
          ${d.PersonneDeclaree ? `<div><strong>En possession de :</strong> <span style="color:#1d4ed8;font-weight:600">${esc(d.PersonneDeclaree)}</span></div>` : ''}
        </div>
      </div>
      ` : ''}

      <div style="margin-top:8px;padding:8px 14px;background:#f8fafc;border-radius:8px;border:1px solid var(--gray-border)">
        <div style="font-size:11px;font-weight:700;margin-bottom:6px;color:var(--gray-text)">📋 Fiche actuelle du bien</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:3px 16px;font-size:11px;color:var(--gray-text)">
          <div><strong>Affecté à :</strong> ${esc(d.AffecteActuel || 'Non affecté')}</div>
          <div><strong>Bâtiment :</strong> ${esc(d.BatimentActuel || '—')}</div>
          <div><strong>Étage :</strong> ${esc(d.EtageActuel || '—')}</div>
          <div><strong>Bureau :</strong> ${esc(d.BureauActuel || '—')}</div>
        </div>
      </div>

      ${d.Commentaire ? `<div style="margin-top:8px;font-size:12px;color:var(--gray-text)"><strong>Commentaire :</strong> ${esc(d.Commentaire)}</div>` : ''}

      ${d.Statut === 'en_attente' ? `
        <div class="decl-actions">
          <button class="decl-btn-ok" onclick="_declValider(${d.Id})">✅ Valider</button>
          <button class="decl-btn-no" onclick="_declRefuser(${d.Id})">❌ Refuser</button>
        </div>
      ` : `
        <div style="margin-top:8px;font-size:11px;color:var(--gray-text)">
          ${d.Statut === 'valide' ? '✅ Validée' : '❌ Refusée'} par ${esc(d.TraiteParNom || d.TraiteParLogin || '—')}
          le ${esc((d.DateTraitement || '').replace('T', ' ').slice(0, 16))}
          ${d.Statut === 'refuse' && d.MotifRefus ? ' — Motif : ' + esc(d.MotifRefus) : ''}
        </div>
      `}
    </div>`;
  }

  container.innerHTML = html;
}

async function _declValider(id) {
  if (!confirm('Valider cette déclaration ? Le bien sera mis à jour automatiquement.')) return;
  try {
    await apiRequest('declarations_inventaire', 'PUT', { statut: 'valide' }, id);
    toast('Déclaration validée — bien mis à jour.', 'success');
    _loadDeclarations();
  } catch(e) { toast(e.message, 'error'); }
}

function _declRefuser(id) {
  openModal('❌ Refuser la déclaration', `
    <div style="margin-bottom:12px;font-size:13px">Indiquez la raison du refus (sera visible par l'agent) :</div>
    <textarea class="form-control" id="decl_motif_refus" rows="3" placeholder="Ex: ce bien est déjà affecté à un autre service…" style="width:100%"></textarea>
  `, async () => {
    const motif = (document.getElementById('decl_motif_refus')?.value || '').trim();
    try {
      await apiRequest('declarations_inventaire', 'PUT', { statut: 'refuse', motifRefus: motif }, id);
      toast('Déclaration refusée.', 'success');
      closeModal();
      _loadDeclarations();
    } catch(e) { toast(e.message, 'error'); }
  }, 'Refuser');
}

// ── Push Notifications admin functions ──────────────────────────────────────
async function _pushTestNotif(mode) {
  const result = document.getElementById('push_test_result');
  const labels = { me: 'vos appareils', roles: 'les rôles configurés', all: 'tous les abonnés' };
  if (result) result.innerHTML = `<span style="color:var(--gray-text)">⏳ Envoi à ${labels[mode] || mode}…</span>`;
  try {
    const resp = await apiRequest('push_test', 'POST', { mode });
    let html = `<span style="color:var(--green,#16a34a)">✅ Envoyé à ${resp.sent}/${resp.total} appareil(s)</span>`;
    if (resp.failed > 0) html += ` <span style="color:var(--orange,#d97706)">(${resp.failed} échec${resp.failed>1?'s':''})</span>`;
    if (resp.cleaned > 0) html += ` <span style="color:var(--gray-text)">(${resp.cleaned} expiré${resp.cleaned>1?'s':''} supprimé${resp.cleaned>1?'s':''})</span>`;
    // Détails par appareil
    if (resp.details && resp.details.length > 0) {
      html += '<div style="margin-top:8px;font-size:11px;border:1px solid var(--border,#ddd);border-radius:6px;padding:8px;max-height:200px;overflow:auto">';
      html += '<div style="font-weight:600;margin-bottom:4px">Détails par appareil :</div>';
      resp.details.forEach(d => {
        const color = d.success ? 'var(--green,#16a34a)' : 'var(--red,#dc2626)';
        const icon = d.success ? '✓' : '✗';
        html += `<div style="color:${color};margin:2px 0">${icon} <b>${d.platform}</b> (user ${d.userId}) — HTTP ${d.statusCode} ${d.reason}`;
        html += `<br><span style="color:var(--gray-text);font-size:10px">${d.endpoint}</span></div>`;
      });
      html += '</div>';
    }
    if (result) result.innerHTML = html;
  } catch(e) {
    if (result) result.innerHTML = `<span style="color:var(--red)">❌ ${_escCfg(e.message)}</span>`;
  }
}

async function _pushDiagnose() {
  const result = document.getElementById('push_test_result');
  if (result) result.innerHTML = '<span style="color:var(--gray-text)">⏳ Diagnostic…</span>';
  try {
    const resp = await apiRequest('push_diagnose', 'GET');
    const ok = resp.ok;
    const errors = resp.errors || [];
    const info = resp.info || {};
    let html = ok
      ? '<span style="color:var(--green,#16a34a)">✅ VAPID OK</span>'
      : '<span style="color:var(--red)">❌ ' + errors.join(', ') + '</span>';
    html += ` — <span style="color:var(--gray-text)">${info.subscriptions_count || 0} abonnement(s)</span>`;
    if (result) result.innerHTML = html;
  } catch(e) {
    if (result) result.innerHTML = `<span style="color:var(--red)">❌ ${_escCfg(e.message)}</span>`;
  }
}

async function _pushRegenVapid() {
  const result = document.getElementById('push_test_result');
  if (result) result.innerHTML = '<span style="color:var(--gray-text)">⏳ Génération…</span>';
  try {
    await apiRequest('push_generate_vapid', 'POST');
    if (result) result.innerHTML = '<span style="color:var(--green,#16a34a)">✅ Nouvelles clés générées. Rechargez la page.</span>';
    toast('Clés VAPID régénérées. Les utilisateurs devront se réabonner.', 'success');
  } catch(e) {
    if (result) result.innerHTML = `<span style="color:var(--red)">❌ ${_escCfg(e.message)}</span>`;
  }
}

async function _pushShowSubscriptions() {
  const container = document.getElementById('push_subs_list');
  if (!container) return;
  container.innerHTML = '<span style="color:var(--gray-text);font-size:12px">⏳ Chargement…</span>';
  try {
    const resp = await apiRequest('push_subscriptions', 'GET');
    const subs = resp.subscriptions || [];
    if (subs.length === 0) {
      container.innerHTML = '<div style="font-size:12px;color:var(--gray-text);padding:8px 0">Aucun abonnement push enregistré.</div>';
      return;
    }
    const platformIcons = { ios: '🍎', android: '🤖', desktop: '💻', unknown: '❓' };
    let html = '<table style="width:100%;font-size:12px;border-collapse:collapse;margin-top:6px">';
    html += '<tr style="background:var(--gray-bg);text-align:left"><th style="padding:6px 8px">Utilisateur</th><th style="padding:6px 8px">Rôle</th><th style="padding:6px 8px">Appareil</th><th style="padding:6px 8px">Inscrit le</th><th style="padding:6px 8px">Dernière activité</th></tr>';
    subs.forEach(s => {
      const icon = platformIcons[s.platform] || '❓';
      html += `<tr style="border-bottom:1px solid var(--gray-border)">
        <td style="padding:6px 8px">${_escCfg(s.nom)}</td>
        <td style="padding:6px 8px"><span style="background:var(--gray-bg);padding:2px 8px;border-radius:4px;font-size:11px">${_escCfg(s.role)}</span></td>
        <td style="padding:6px 8px">${icon} ${_escCfg(s.platform)}</td>
        <td style="padding:6px 8px;color:var(--gray-text)">${_escCfg(s.createdAt || '—')}</td>
        <td style="padding:6px 8px;color:var(--gray-text)">${_escCfg(s.lastUsed || '—')}</td>
      </tr>`;
    });
    html += '</table>';
    container.innerHTML = html;
  } catch(e) {
    container.innerHTML = `<span style="color:var(--red);font-size:12px">❌ ${_escCfg(e.message)}</span>`;
  }
}

// ══════════════════════════════════════════════════════════════════════════════
//  ONGLET : Gestion des Comptes
// ══════════════════════════════════════════════════════════════════════════════

async function _loadComptesTab() {
  const slot = document.getElementById('cfg_comptes_slot');
  if (!slot) return;
  slot.innerHTML = '<div style="padding:30px;text-align:center;color:var(--gray-text)">⏳ Chargement…</div>';

  try {
    const data = await UtilisateursApi.getAll();
    const canManage = isSuperAdmin() || isAdmin();

    data.forEach(u => {
      const p = u.Provider || 'local';
      u._type = p === 'google' ? '🔴 Google' : p === 'microsoft' ? '🔵 Microsoft' : '🔑 Local';
      // Cette liste est un doublon de renderUtilisateurs() : toute colonne
      // ajoutée là-bas doit l'être ici aussi, sinon l'écran Configuration
      // n'affiche pas la même chose que l'écran Utilisateurs.
      u._acces = (typeof _usrBadgeAcces === 'function') ? _usrBadgeAcces(u) : '';
      const initiales = ((u.Prenom?.[0]||'') + (u.Nom?.[0]||'')).toUpperCase() || '?';
      if (u.PhotoUrl) {
        u._avatar = `<img src="${escHtml(u.PhotoUrl||'')}" style="width:28px;height:28px;border-radius:50%;object-fit:cover;vertical-align:middle" alt="${initiales}">`;
      } else {
        u._avatar = `<span style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:50%;background:var(--blue);color:white;font-size:10px;font-weight:700;vertical-align:middle">${initiales}</span>`;
      }
    });

    slot.innerHTML = '<div id="cfg_comptes_table"></div>';

    renderTable({
      container: document.getElementById('cfg_comptes_table'), data,
      columns: [
        { key:'_avatar', label:'',  render: (v) => v || '', width:'40px' },
        { key:'Login',   label:'Login',      editFn: canManage ? 'editUser' : null, deleteFn: canManage ? 'deleteUser' : null },
        { key:'Prenom',  label:'Prénom' },
        { key:'Nom',     label:'Nom' },
        { key:'Email',   label:'Email' },
        { key:'TelMobile', label:'📱 Mobile' },
        { key:'TelPro',   label:'📞 Pro' },
        { key:'Service', label:'Service' },
        { key:'Poste',   label:'Poste' },
        { key:'Role',    label:'Rôle',  badge:true },
        { key:'_acces',  label:'👁️ Consultation', render: (v) => v || '' },
        { key:'_type',   label:'Type' },
      ],
      addBtnFn: canManage ? 'editUser' : null,
      searchTerm: App.searchTerm, currentFilter: App.currentFilter,
      canEdit: canManage, canDelete: canManage,
    });
  } catch(e) {
    slot.innerHTML = `<div style="color:#ef4444;padding:20px">${_escCfg(e.message)}</div>`;
  }
}
// ── Resync séquences PostgreSQL ──────────────────────────────────────────────
async function _dbResyncSequences() {
  const btn = document.getElementById('btnResyncSeq');
  const result = document.getElementById('resyncSeqResult');
  if (!btn || !result) return;
  if (!confirm('Cette opération va réajuster les compteurs d\'auto-incrémentation de toutes les tables.\n\nElle est sans risque pour vos données mais peut prendre quelques secondes.\n\nContinuer ?')) return;
  btn.disabled = true;
  const oldLbl = btn.textContent;
  btn.textContent = '⏳ Resync en cours…';
  result.innerHTML = '';
  try {
    const res = await fetch('api/index.php?action=db_resync_sequences', {
      method: 'POST', credentials: 'include', headers: {'Content-Type':'application/json'}
    });
    const json = await res.json();
    if (!json.success) throw new Error(json.error || 'Erreur inconnue');
    const data = json.data || {};
    let html = '<div style="color:#16a34a;font-weight:600;margin-bottom:8px">✅ ' + (data.message || 'Resync terminé.') + '</div>';

    // Détail des modifications effectuées
    if (Array.isArray(data.changed) && data.changed.length > 0) {
      html += '<div style="background:var(--gray-bg);border:1px solid var(--gray-border);border-radius:8px;padding:10px;margin-top:8px;font-size:12px">';
      html += '<div style="font-weight:600;margin-bottom:4px">Tables réparées :</div>';
      html += '<div style="display:grid;grid-template-columns:1fr auto auto auto;gap:4px 16px;font-family:monospace;font-size:11px">';
      html += '<div style="font-weight:700;color:var(--gray-text)">Table</div><div style="font-weight:700;color:var(--gray-text)">Max(Id)</div><div style="font-weight:700;color:var(--gray-text)">Avant</div><div style="font-weight:700;color:var(--gray-text)">Après</div>';
      data.changed.forEach(c => {
        html += '<div>'+_escCfg(c.table)+'</div>';
        html += '<div style="text-align:right">'+c.maxId+'</div>';
        html += '<div style="text-align:right;color:#e74c3c">'+c.before+'</div>';
        html += '<div style="text-align:right;color:#16a34a;font-weight:700">'+c.after+'</div>';
      });
      html += '</div></div>';
    } else if (Array.isArray(data.diagAfter) && data.diagAfter.length > 0) {
      html += '<div style="font-size:11px;color:var(--gray-text);margin-top:6px">Toutes les séquences étaient déjà à jour (rien à corriger).</div>';
    }
    if (data.driver && data.driver !== 'pgsql') {
      html += '<div style="font-size:11px;color:var(--gray-text);margin-top:6px">Driver actuel : <code>'+_escCfg(data.driver)+'</code> — Cette opération s\'applique uniquement à PostgreSQL.</div>';
    }
    result.innerHTML = html;
    if (typeof toast === 'function') toast(data.message || 'Resync terminé', 'success');
  } catch(e) {
    result.innerHTML = '<span style="color:#e74c3c">❌ ' + (e.message || 'Erreur') + '</span>';
    if (typeof toast === 'function') toast(e.message, 'error');
  } finally {
    btn.disabled = false;
    btn.textContent = oldLbl;
  }
}

/* ── Facteurs carbone ADEME : test de connexion et purge du cache ────────── */
async function _testerCarbone() {
  const out = document.getElementById('cb_test_result');
  if (out) { out.textContent = '⏳ Test en cours…'; out.style.color = 'var(--gray-text)'; }
  try {
    // Une clé saisie mais pas encore enregistrée est testée telle quelle.
    const saisie = _readField('cb_key','');
    const body = (saisie && !saisie.includes('••')) ? { cle: saisie } : {};
    const r = await apiRequest('facteurs_carbone_test', 'POST', body);
    if (!out) return;
    if (r && r.ok) {
      out.textContent = '✅ ' + (r.message || 'Connexion établie') + ' — ' + (r.nbFacteurs || 0) + ' modes récupérés.';
      out.style.color = r.anonyme ? '#d97706' : '#16a34a';
    } else {
      out.textContent = '❌ ' + ((r && r.message) || 'Échec de la connexion.');
      out.style.color = '#dc2626';
    }
  } catch (e) {
    if (out) { out.textContent = '❌ ' + e.message; out.style.color = '#dc2626'; }
  }
}

async function _purgerCacheCarbone() {
  const out = document.getElementById('cb_test_result');
  try {
    await apiRequest('facteurs_carbone_cache', 'DELETE');
    try { localStorage.removeItem('larka_facteurs_carbone'); } catch (e) {}
    if (out) { out.textContent = '✅ Cache vidé — le prochain calcul interrogera l\'ADEME.'; out.style.color = '#16a34a'; }
  } catch (e) {
    if (out) { out.textContent = '❌ ' + e.message; out.style.color = '#dc2626'; }
  }
}

/* ── Millésimes de facteurs mobilité ─────────────────────────────────────── */
async function _chargerMillesimesCarbone() {
  const el = document.getElementById('cb_millesimes');
  if (!el) return;
  try {
    const list = await apiRequest('facteurs_carbone_millesimes');
    if (!list || !list.length) {
      el.innerHTML = 'Aucun millésime figé pour l’instant.';
      return;
    }
    el.innerHTML = list.map(m =>
      `<div style="display:flex;gap:12px;padding:4px 0;border-bottom:1px solid var(--gray-border)">
         <strong style="width:60px">${m.annee}</strong>
         <span style="width:90px">${m.nb} modes</span>
         <span style="flex:1">${m.source || ''}</span>
         <span style="color:var(--gray-text)">${m.dateMaj || ''}</span>
       </div>`).join('');
  } catch (e) {
    el.textContent = 'Inventaire indisponible : ' + e.message;
  }
}

async function _figerMillesimeCarbone(ecraser) {
  const out = document.getElementById('cb_sync_result');
  const annee = parseInt(document.getElementById('cb_sync_annee')?.value) || new Date().getFullYear();
  if (ecraser && !confirm(
    `Refiger ${annee} remplacera des facteurs peut-être déjà utilisés dans un bilan publié.\n\nContinuer ?`)) return;
  if (out) { out.textContent = '⏳ Interrogation de l’ADEME…'; out.style.color = 'var(--gray-text)'; }
  try {
    const r = await apiRequest('facteurs_carbone_sync', 'POST', { annee, ecraser: !!ecraser });
    if (out) { out.textContent = '✅ ' + (r.message || 'Millésime figé.'); out.style.color = '#16a34a'; }
    _chargerMillesimesCarbone();
  } catch (e) {
    if (out) { out.textContent = '❌ ' + e.message; out.style.color = '#dc2626'; }
  }
}

/* ── Grille d\'un millésime : saisie manuelle et import CSV ───────────────── */
var _grilleCarbone = { annee: null, lignes: [] };

async function _ouvrirGrilleCarbone() {
  const annee = parseInt(document.getElementById('cb_sync_annee')?.value) || new Date().getFullYear();
  try {
    const r = await apiRequest(`facteurs_carbone_grille&annee=${annee}`);
    _grilleCarbone = { annee: r.annee, lignes: r.lignes || [] };
  } catch (e) {
    toast('Lecture impossible : ' + e.message, 'error');
    return;
  }

  const lignes = _grilleCarbone.lignes.map((l, i) => `
    <tr>
      <td style="padding:4px 6px;font-size:12px;white-space:nowrap">${l.id}
        ${l.present ? '' : '<span style="color:#d97706;font-size:10px"> (vide)</span>'}</td>
      ${['co2Acv','co2HorsConstr','co2Combustion','co2Seul'].map(champ => `
        <td style="padding:2px 4px">
          <input type="number" step="0.00001" min="0" data-i="${i}" data-champ="${champ}"
                 value="${l[champ] !== null && l[champ] !== undefined ? l[champ] : ''}"
                 class="form-control" style="width:100px;font-family:monospace;font-size:12px;padding:3px 6px">
        </td>`).join('')}
    </tr>`).join('');

  openModal(`✏️ Millésime ${annee} — facteurs en kgCO₂e/km`, `
    <div style="display:flex;flex-direction:column;gap:12px">
      <div style="font-size:12px;color:var(--gray-text);line-height:1.6">
        La colonne <strong>ACV</strong> est obligatoire ; les trois autres se recalculent
        automatiquement si laissées vides. Les périmètres doivent décroître de gauche à droite.
      </div>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <label class="btn" style="cursor:pointer;margin:0">
          📄 Importer un CSV
          <input type="file" accept=".csv,text/csv" style="display:none" onchange="_importerCsvCarbone(this)">
        </label>
        <span style="font-size:11px;color:var(--gray-text)">
          Colonnes : id, co2Acv, co2HorsConstr, co2Combustion, co2Seul
        </span>
        <span id="cb_csv_info" style="font-size:11px"></span>
      </div>
      <div style="max-height:380px;overflow:auto;border:1px solid var(--gray-border);border-radius:8px">
        <table style="width:100%;border-collapse:collapse">
          <thead><tr style="position:sticky;top:0;background:var(--gray-bg)">
            <th style="padding:6px;text-align:left;font-size:11px">Mode</th>
            <th style="padding:6px;font-size:11px">ACV</th>
            <th style="padding:6px;font-size:11px">Hors constr.</th>
            <th style="padding:6px;font-size:11px">Combustion</th>
            <th style="padding:6px;font-size:11px">CO₂ seul</th>
          </tr></thead>
          <tbody id="cb_grille_body">${lignes}</tbody>
        </table>
      </div>
      <div class="form-group">
        <label class="form-label">Source à enregistrer</label>
        <input class="form-control" id="cb_grille_source"
               placeholder="Ex : Base Carbone v22.3 (archive ${annee})">
      </div>
    </div>`, _enregistrerGrilleCarbone);
}

function _importerCsvCarbone(input) {
  const f = input.files && input.files[0];
  const info = document.getElementById('cb_csv_info');
  if (!f) return;
  const reader = new FileReader();
  reader.onload = () => {
    try {
      const lignes = String(reader.result).split(/\r?\n/).filter(l => l.trim());
      if (!lignes.length) throw new Error('fichier vide');
      // Séparateur : le point-virgule est la norme des exports Excel français.
      const sep = (lignes[0].match(/;/g) || []).length >= (lignes[0].match(/,/g) || []).length ? ';' : ',';
      const entetes = lignes[0].split(sep).map(h => h.trim().replace(/^["']|["']$/g, ''));
      const iCol = {};
      ['id','co2Acv','co2HorsConstr','co2Combustion','co2Seul'].forEach(c => {
        iCol[c] = entetes.findIndex(h => h.toLowerCase() === c.toLowerCase());
      });
      if (iCol.id < 0) throw new Error('colonne « id » absente');

      let appliques = 0, inconnus = [];
      lignes.slice(1).forEach(l => {
        const cols = l.split(sep).map(c => c.trim().replace(/^["']|["']$/g, ''));
        const id = cols[iCol.id];
        const idx = _grilleCarbone.lignes.findIndex(x => x.id === id);
        if (idx < 0) { if (id) inconnus.push(id); return; }
        ['co2Acv','co2HorsConstr','co2Combustion','co2Seul'].forEach(champ => {
          if (iCol[champ] < 0) return;
          // La virgule décimale française est acceptée.
          const brut = (cols[iCol[champ]] || '').replace(',', '.');
          if (brut === '') return;
          const el = document.querySelector(`#cb_grille_body input[data-i="${idx}"][data-champ="${champ}"]`);
          if (el) el.value = brut;
        });
        appliques++;
      });
      if (info) {
        info.textContent = `✅ ${appliques} ligne(s) appliquée(s)`
          + (inconnus.length ? ` — ignorées : ${inconnus.slice(0,5).join(', ')}` : '');
        info.style.color = inconnus.length ? '#d97706' : '#16a34a';
      }
    } catch (e) {
      if (info) { info.textContent = '❌ CSV illisible : ' + e.message; info.style.color = '#dc2626'; }
    }
  };
  reader.readAsText(f, 'UTF-8');
}

async function _enregistrerGrilleCarbone() {
  const lignes = _grilleCarbone.lignes.map((l, i) => {
    const val = champ => {
      const el = document.querySelector(`#cb_grille_body input[data-i="${i}"][data-champ="${champ}"]`);
      return el && el.value !== '' ? parseFloat(el.value) : '';
    };
    return { id: l.id, co2Acv: val('co2Acv'), co2HorsConstr: val('co2HorsConstr'),
             co2Combustion: val('co2Combustion'), co2Seul: val('co2Seul') };
  }).filter(l => l.co2Acv !== '');

  if (!lignes.length) { toast('Renseignez au moins une colonne ACV.', 'error'); return; }
  try {
    const r = await apiRequest('facteurs_carbone_saisie', 'POST', {
      annee: _grilleCarbone.annee,
      source: document.getElementById('cb_grille_source')?.value || '',
      lignes,
    });
    toast(r.message || 'Millésime enregistré.', 'success');
    closeModal();
    _chargerMillesimesCarbone();
  } catch (e) {
    toast(e.message, 'error');
  }
}

/* ── Inventaire des millésimes énergie ───────────────────────────────────── */
async function _chargerMillesimesEnergie() {
  const el = document.getElementById('ce_millesimes');
  if (!el) return;
  try {
    const facteurs = await apiRequest('energie_facteurs_carbone&methode=');
    if (!facteurs || !facteurs.length) {
      el.innerHTML = 'Aucun millésime figé — les calculs utilisent les valeurs de référence intégrées.';
      el.style.color = '#d97706';
      return;
    }
    // Regroupement (année, méthode) : les deux méthodes vivent dans des tables
    // séparées et peuvent être figées indépendamment.
    const parCle = {};
    facteurs.forEach(f => {
      if (!f.Annee) return;
      const cle = f.Annee + '|' + (f.Methode || 'officielle');
      if (!parCle[cle]) parCle[cle] = { annee: f.Annee, methode: f.Methode || 'officielle', types: [], maj: '' };
      parCle[cle].types.push(f.Type);
      if ((f.DateMaj || '') > parCle[cle].maj) parCle[cle].maj = f.DateMaj || '';
    });
    const lignes = Object.values(parCle).sort((a, b) =>
      (b.annee - a.annee) || a.methode.localeCompare(b.methode));

    el.style.color = '';
    el.innerHTML = lignes.map(l => `
      <div style="display:flex;gap:12px;padding:4px 0;border-bottom:1px solid var(--gray-border)">
        <strong style="width:60px">${l.annee}</strong>
        <span style="width:90px">${l.methode}</span>
        <span style="flex:1">${l.types.join(', ')}</span>
        <span style="color:var(--gray-text)">${l.maj || ''}</span>
      </div>`).join('');
  } catch (e) {
    el.textContent = 'Inventaire indisponible : ' + e.message;
  }
}

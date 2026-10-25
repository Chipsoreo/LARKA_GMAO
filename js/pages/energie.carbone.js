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
 * Larka — Module Énergie : Facteurs Carbone
 * ═══════════════════════════════════════════════════════════════════════════════
 * 2 méthodes de calcul :
 *   1) Officielle  — API Base Carbone ADEME (facteurs précis, ACV)
 *   2) Simplifiée  — Moyennes Impact CO2 / Datagir (valeurs grand public)
 *
 * Contraintes :
 *   - Facteurs stockés PAR ANNÉE en BDD (colonne Methode = 'officielle' | 'simplifiee')
 *   - Valeurs FIGÉES : pas de recalcul rétroactif
 *   - Chaque calcul utilise le facteur de l'année de consommation
 *     → facture 2028 = facteur 2028
 * ═══════════════════════════════════════════════════════════════════════════════
 */

// ── Valeurs ADEME officielles (fallback frontend) ────────────────────────────
// Total_poste_non_décomposé (ACV) — Base Carbone V23.6
var ADEME_FACTEURS = {
  Electricite: { facteurCO2: 0.0569, unite: 'kgCO2e/kWh', nom: 'Electricité — Mix moyen consommation — France continentale', incertitude: 10, id: '36651' },
  Gaz:         { facteurCO2: 0.227,  unite: 'kgCO2e/kWh PCI', nom: 'Gaz naturel — PCI — France continentale', incertitude: 5, id: '38952' },
  Chauffage:   { facteurCO2: 0.112,  unite: 'kgCO2e/kWh', nom: 'Réseau de chaleur — Mix moyen — France (arrêté DPE)', incertitude: 50, id: 'DPE-RCU' },
};

// ── Valeurs simplifiées Impact CO2 / Datagir (fallback frontend) ─────────────
// Source : impactco2.fr — données ADEME vulgarisées, moyennes nationales
// Ces valeurs "grand public" sont plus conservatrices (ex: élec usage chauffage = 0.147)
var IMPACT_CO2_FACTEURS = {
  Electricite: { facteurCO2: 0.0520, unite: 'kgCO2e/kWh', nom: 'Electricité — Conso moyenne France (Impact CO2 / Datagir)', incertitude: 15 },
  Gaz:         { facteurCO2: 0.243,  unite: 'kgCO2e/kWh', nom: 'Gaz naturel — ACV complet (Impact CO2 / Datagir)', incertitude: 10 },
  Chauffage:   { facteurCO2: 0.116,  unite: 'kgCO2e/kWh', nom: 'Réseau de chaleur — Moyenne nationale (Impact CO2 / Datagir)', incertitude: 50 },
};

// ── Méthode active ──────────────────────────────────────────────────────────
// Persisté en localStorage pour mémoire, le vrai choix est le facteur stocké en BDD
var _methodeCarbone = localStorage.getItem('gmao_methode_carbone') || 'officielle';

function getMethodeCarbone() { return _methodeCarbone; }
function setMethodeCarbone(m) {
  if (m !== 'officielle' && m !== 'simplifiee') return;
  _methodeCarbone = m;
  localStorage.setItem('gmao_methode_carbone', m);
}

// ── Cache ────────────────────────────────────────────────────────────────────
var _facteursCache = {};

/**
 * Qualité d'un facteur, pour que l'interface puisse alerter au bon niveau.
 *   exact   → millésime de l'année demandée
 *   arriere → millésime antérieur (conservateur, mention discrète)
 *   avant   → millésime POSTÉRIEUR à l'exercice : non défendable, alerte forte
 *   defaut  → aucun millésime en base, valeurs codées dans ce fichier
 */
function _qualiteFacteur(fc) {
  if (!fc) return 'defaut';
  if (fc._defaut) return 'defaut';
  return fc._report || 'exact';
}

/** Libellé court destiné à être affiché à côté d'un total de relevé. */
function _mentionFacteur(fc, annee) {
  var q = _qualiteFacteur(fc);
  if (q === 'exact') return null;
  if (q === 'defaut') {
    return { niveau:'alerte', texte:'aucun millésime ' + annee + ' — valeurs de référence intégrées' };
  }
  if (q === 'avant') {
    return { niveau:'alerte', texte:'facteur ' + fc._anneeFacteur + ', POSTÉRIEUR à ' + annee };
  }
  return { niveau:'info', texte:'facteur ' + fc._anneeFacteur + ' (aucun millésime ' + annee + ')' };
}

/**
 * Récupère le facteur CO₂ pour un type/année/méthode.
 * Priorité : millésime exact → report arrière → report avant → valeurs codées.
 * L'objet renvoyé porte toujours _report / _anneeFacteur / _defaut : sans ça,
 * un total calculé sur un facteur de repli serait indiscernable d'un total
 * calculé sur le bon millésime.
 */
async function _getFacteurCO2(type, annee, methode) {
  methode = methode || _methodeCarbone;
  var k = type + '_' + annee + '_' + methode;
  if (_facteursCache[k] !== undefined) return _facteursCache[k];
  try {
    var fc = await EnergieApi.getFacteurCarbone(type, annee, methode);
    if (fc && fc.FacteurCO2 && parseFloat(fc.FacteurCO2) > 0) {
      _facteursCache[k] = fc;
      return fc;
    }
  } catch(e) {}
  // Aucun millésime, dans aucun sens : valeurs codées dans ce fichier.
  var def = methode === 'simplifiee' ? IMPACT_CO2_FACTEURS[type] : ADEME_FACTEURS[type];
  if (def) {
    var r = {
      FacteurCO2: def.facteurCO2,
      Unite: def.unite,
      Source: methode === 'simplifiee'
        ? 'Impact CO2 / Datagir (défaut intégré)'
        : 'ADEME V23.6 (défaut intégré)',
      Methode: methode,
      _defaut: true,
      _report: 'defaut',
      _anneeFacteur: null,
    };
    _facteursCache[k] = r;
    return r;
  }
  return null;
}

/**
 * Facteur ET sa qualité, pour les écrans qui veulent afficher une mention.
 * calcCarboneDynamique ne renvoie qu'un nombre : impossible d'y accrocher un
 * avertissement, d'où cette variante.
 */
async function calcCarboneDetaille(type, consoValue, annee, methode) {
  var fc = await _getFacteurCO2(type, annee, methode);
  if (!fc) return null;
  var facteur = parseFloat(fc.FacteurCO2);
  if (isNaN(facteur) || facteur <= 0) return null;
  var co2 = (!consoValue || consoValue <= 0) ? 0
          : (type === 'Chauffage' ? consoValue * facteur * 1000 : consoValue * facteur);
  return {
    co2: co2,
    facteur: facteur,
    anneeFacteur: fc._anneeFacteur || annee,
    qualite: _qualiteFacteur(fc),
    mention: _mentionFacteur(fc, annee),
    source: fc.Source || '',
  };
}

function _clearFacteursCache() { for (var k in _facteursCache) delete _facteursCache[k]; }

// ── Calcul CO₂ ──────────────────────────────────────────────────────────────
/**
 * Calcule le CO₂ dynamiquement.
 * @param {string}  type      - Electricite | Gaz | Chauffage
 * @param {number}  consoValue - Consommation
 * @param {number}  annee      - Année de la consommation (ex: 2028)
 * @param {string}  [methode]  - 'officielle' | 'simplifiee' (défaut = méthode active)
 */
async function calcCarboneDynamique(type, consoValue, annee, methode) {
  if (!consoValue || consoValue <= 0) return null;
  var fc = await _getFacteurCO2(type, annee, methode);
  if (!fc) return null;
  var facteur = parseFloat(fc.FacteurCO2);
  if (isNaN(facteur) || facteur <= 0) return null;
  if (type === 'Chauffage') return consoValue * facteur * 1000;
  return consoValue * facteur;
}

// ═══════════════════════════════════════════════════════════════════════════════
// ── Sync ADEME — Méthode Officielle ─────────────────────────────────────────
// ═══════════════════════════════════════════════════════════════════════════════
async function syncCarboneADEME(annee) {
  var btn = document.getElementById('btnSyncCarbone');
  if (btn) { btn.disabled = true; btn.textContent = '⏳ Synchronisation...'; }

  try {
    var result = await EnergieApi.syncCarboneADEME({ annee: annee, methode: 'officielle' });
    _clearFacteursCache();

    if (result.debug && result.debug.length > 0) {
      console.group('Sync ADEME Officielle ' + annee);
      result.debug.forEach(function(d) { console.log(d); });
      console.groupEnd();
    }

    var msg = result.apiSuccess
      ? 'Facteurs officiels ' + annee + ' mis à jour depuis l\'API ADEME !'
      : 'Facteurs officiels ' + annee + ' enregistrés (valeurs ADEME V23.6).';

    if (result.facteurs) {
      var details = [];
      for (var t in result.facteurs) {
        var f = result.facteurs[t];
        details.push(t + ': ' + f.facteurCO2);
      }
      if (details.length > 0) msg += '\n' + details.join(' · ');
    }

    toast(msg, 'success');
    closeModal();
    setTimeout(function() { openFacteursCarbone(); (App.currentPage === 'carbone' ? renderCarbone : renderEnergie)(); }, 200);
  } catch(e) {
    toast('Erreur sync officielle : ' + e.message, 'error');
    console.error('Sync ADEME error:', e);
  }

  if (btn) { btn.disabled = false; btn.textContent = '🌐 Sync ADEME ' + annee; }
}

// ═══════════════════════════════════════════════════════════════════════════════
// ── Sync Simplifiée — Impact CO2 / Datagir ──────────────────────────────────
// Enregistre les facteurs moyens pour une année donnée.
// Pas d'appel API externe : les valeurs sont hardcodées (mises à jour avec le code).
// ═══════════════════════════════════════════════════════════════════════════════
async function syncCarboneSimplifiee(annee) {
  var btn = document.getElementById('btnSyncSimplifie');
  if (btn) { btn.disabled = true; btn.textContent = '⏳ Enregistrement...'; }

  try {
    var result = await EnergieApi.syncCarboneSimplifiee({ annee: annee });
    _clearFacteursCache();

    var msg = 'Facteurs simplifiés ' + annee + ' enregistrés (Impact CO2 / Datagir).';
    if (result.facteurs) {
      var details = [];
      for (var t in result.facteurs) {
        details.push(t + ': ' + result.facteurs[t].facteurCO2);
      }
      if (details.length > 0) msg += '\n' + details.join(' · ');
    }

    toast(msg, 'success');
    closeModal();
    setTimeout(function() { openFacteursCarbone(); (App.currentPage === 'carbone' ? renderCarbone : renderEnergie)(); }, 200);
  } catch(e) {
    toast('Erreur sync simplifiée : ' + e.message, 'error');
    console.error('Sync Simplifiée error:', e);
  }

  if (btn) { btn.disabled = false; btn.textContent = '📦 Enregistrer simplifiés ' + annee; }
}

// ═══════════════════════════════════════════════════════════════════════════════
// ── Recalculer CO₂ en masse ─────────────────────────────────────────────────
// ═══════════════════════════════════════════════════════════════════════════════
async function recalculerCarboneTout(methode) {
  methode = methode || _methodeCarbone;
  var btns = document.querySelectorAll('[onclick*="recalculerCarboneTout"]');
  btns.forEach(function(b) { b.disabled = true; b.textContent = '⏳ Recalcul...'; });

  try {
    var result = await EnergieApi.recalculCarbone(methode);
    _clearFacteursCache();
    toast(
      result.message || (result.updated + ' relevé(s) mis à jour avec la méthode ' + methode + '.'),
      result.updated > 0 ? 'success' : 'info'
    );
    (App.currentPage === 'carbone' ? renderCarbone : renderEnergie)();
  } catch(e) {
    toast('Erreur recalcul : ' + e.message, 'error');
  }

  btns.forEach(function(b) { b.disabled = false; b.textContent = '🔄 Recalculer CO₂'; });
}

// ═══════════════════════════════════════════════════════════════════════════════
// ── Modal gestion des facteurs (les 2 méthodes) ────────────────────────────
// ═══════════════════════════════════════════════════════════════════════════════
async function openFacteursCarbone() {
  var facteurs = await EnergieApi.getFacteursCarbone(methodeActive);
  var anneeActuelle = new Date().getFullYear();
  var typeIcons = { Electricite: '⚡', Gaz: '🔵', Chauffage: '🔥', Eau: '💧' };
  var methodeActive = _methodeCarbone;

  // Les facteurs sont déjà filtrés par la bonne table (API passe methode)
  var valides = [];
  var invalides = [];
  for (var i = 0; i < facteurs.length; i++) {
    var f = facteurs[i];
    if (!f.Annee || !f.FacteurCO2 || parseFloat(f.FacteurCO2) <= 0) {
      invalides.push(f);
    } else {
      valides.push(f);
    }
  }

  // Grouper par année
  var _groupByAnnee = function(arr) {
    var g = {};
    for (var i = 0; i < arr.length; i++) {
      var a = arr[i].Annee;
      if (!g[a]) g[a] = [];
      g[a].push(arr[i]);
    }
    return g;
  };
  var facteursParAnnee = _groupByAnnee(valides);

  // Helper : render un groupe de facteurs
  var _renderTableFacteurs = function(parAnnee, methode) {
    var annees = Object.keys(parAnnee).sort(function(a,b){ return b-a; });
    if (!annees.length) {
      return '<div style="text-align:center;padding:20px;color:var(--gray-text);font-size:12px">Aucun facteur enregistré pour cette méthode.</div>';
    }
    var html = '';
    for (var ai = 0; ai < annees.length; ai++) {
      var an = annees[ai];
      html += '<div style="margin-bottom:14px">';
      html += '<div style="font-size:12px;font-weight:700;color:' + (methode==='simplifiee'?'#7c3aed':'#16a34a') + ';margin-bottom:6px;display:flex;align-items:center;gap:8px">';
      html += '📅 ' + an;
      if (methode === 'officielle') {
        html += ' <button class="btn btn-ghost btn-sm" onclick="syncCarboneADEME(' + an + ')" style="font-size:10px;border:1px solid #16a34a;color:#16a34a;padding:2px 8px">🔄 Resync</button>';
      } else {
        html += ' <button class="btn btn-ghost btn-sm" onclick="syncCarboneSimplifiee(' + an + ')" style="font-size:10px;border:1px solid #7c3aed;color:#7c3aed;padding:2px 8px">🔄 Resync</button>';
      }
      html += '</div>';
      html += '<table style="width:100%;border-collapse:collapse;font-size:12px">';
      html += '<thead><tr style="background:' + (methode==='simplifiee'?'#f5f3ff':'#f0fdf4') + '">';
      html += '<th style="padding:5px 8px;text-align:left">Type</th>';
      html += '<th style="padding:5px 8px;text-align:right">Facteur CO₂</th>';
      html += '<th style="padding:5px 8px;text-align:left">Unité</th>';
      html += '<th style="padding:5px 8px;text-align:left">Source</th>';
      html += '<th style="padding:5px 8px;text-align:center"><span title="Incertitude ADEME : marge d\'erreur sur le facteur d\'émission.&#10;Ex : ±10% sur 0.0569 = le vrai facteur est entre 0.0512 et 0.0626 kgCO₂e/kWh.&#10;Plus le % est bas, plus la valeur est fiable.&#10;&#10;Calcul CO₂ : Consommation (kWh) × Facteur (kgCO₂e/kWh) = Émission (kgCO₂e).&#10;Chauffage réseau : Conso (MWh) × Facteur × 1000." style="cursor:help;border-bottom:1px dotted var(--gray-text)">±% <span style="font-size:10px">ℹ️</span></span></th>';
      html += '<th style="padding:5px 8px"></th>';
      html += '</tr></thead><tbody>';
      for (var fi = 0; fi < parAnnee[an].length; fi++) {
        var fc = parAnnee[an][fi];
        html += '<tr style="border-bottom:1px solid #e2e8f0">';
        html += '<td style="padding:5px 8px;font-weight:600">' + (typeIcons[fc.Type] || '') + ' ' + (fc.Type || '') + '</td>';
        html += '<td style="padding:5px 8px;text-align:right;font-weight:700;color:' + (methode==='simplifiee'?'#7c3aed':'#16a34a') + '">' + fc.FacteurCO2 + '</td>';
        html += '<td style="padding:5px 8px;color:var(--gray-text)">' + (fc.Unite || '—') + '</td>';
        html += '<td style="padding:5px 8px;color:var(--gray-text);font-size:11px;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + (fc.NomADEME || fc.Source || '') + '">' + (fc.Source || '—') + '</td>';
        var incertHtml = '—';
        if (fc.Incertitude) {
          var fVal = parseFloat(fc.FacteurCO2);
          var pct = parseFloat(fc.Incertitude);
          var lo = (fVal * (1 - pct/100)).toFixed(4);
          var hi = (fVal * (1 + pct/100)).toFixed(4);
          incertHtml = '<span title="Incertitude ±' + pct + '% → le facteur réel est entre ' + lo + ' et ' + hi + ' ' + (fc.Unite||'') + '" style="cursor:help">' + pct + '%</span>';
        }
        html += '<td style="padding:5px 8px;text-align:center;color:var(--gray-text)">' + incertHtml + '</td>';
        html += '<td style="padding:5px 8px;text-align:center"><button class="icon-btn delete" onclick="deleteFacteurCarbone(' + fc.Id + ')" title="Supprimer" style="font-size:12px">🗑️</button></td>';
        html += '</tr>';
      }
      html += '</tbody></table></div>';
    }
    return html;
  };

  // ═══ HTML principal ═══
  var html = '<div style="display:flex;flex-direction:column;gap:14px">';

  // Bandeau méthode active
  html += '<div style="display:flex;gap:8px;align-items:stretch">';
  html += '<div onclick="setMethodeCarbone(\'officielle\');closeModal();setTimeout(openFacteursCarbone,200)" style="flex:1;cursor:pointer;padding:12px 16px;border-radius:8px;border:2px solid ' + (methodeActive==='officielle'?'#16a34a':'#e2e8f0') + ';background:' + (methodeActive==='officielle'?'#f0fdf4':'#fafafa') + ';transition:all .2s">';
  html += '<div style="font-weight:700;font-size:13px;color:' + (methodeActive==='officielle'?'#16a34a':'var(--gray-text)') + '">' + (methodeActive==='officielle'?'✅ ':'') + '1. Officielle — ADEME</div>';
  html += '<div style="font-size:11px;color:var(--gray-text);margin-top:4px">API Base Carbone. Facteurs ACV précis par identifiant. Conforme bilan GES réglementaire.</div>';
  html += '</div>';
  html += '<div onclick="setMethodeCarbone(\'simplifiee\');closeModal();setTimeout(openFacteursCarbone,200)" style="flex:1;cursor:pointer;padding:12px 16px;border-radius:8px;border:2px solid ' + (methodeActive==='simplifiee'?'#7c3aed':'#e2e8f0') + ';background:' + (methodeActive==='simplifiee'?'#f5f3ff':'#fafafa') + ';transition:all .2s">';
  html += '<div style="font-weight:700;font-size:13px;color:' + (methodeActive==='simplifiee'?'#7c3aed':'var(--gray-text)') + '">' + (methodeActive==='simplifiee'?'✅ ':'') + '2. Simplifiée — Impact CO2</div>';
  html += '<div style="font-size:11px;color:var(--gray-text);margin-top:4px">Moyennes nationales (Datagir/ADEME). Estimation rapide, pas d\'API externe requise.</div>';
  html += '</div>';
  html += '</div>';

  // Info
  html += '<div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:10px 14px;font-size:11px;color:#0369a1">';
  html += '<strong>📋 Règle clé :</strong> les facteurs sont figés par année. Une facture 2028 utilise toujours le facteur 2028, même si les valeurs 2029 changent. Pas de recalcul rétroactif.';
  html += '</div>';

  // Bandeau explication calcul
  html += '<div style="background:#faf5ff;border:1px solid #d8b4fe;border-radius:8px;padding:10px 14px;font-size:11px;color:#6b21a8">';
  html += '<strong>🧮 Calcul :</strong> Émission CO₂ = Consommation × Facteur d\'émission. ';
  html += 'Ex : 10 000 kWh élec × 0.0569 = 569 kgCO₂e. ';
  html += 'Chauffage réseau : conso en MWh × facteur × 1000. ';
  html += '<br><strong>±% (incertitude) :</strong> marge d\'erreur ADEME sur le facteur. ±10% sur 0.0569 signifie que la vraie valeur est entre 0.0512 et 0.0626. Plus le % est bas, plus c\'est fiable.';
  html += '</div>';

  // Bandeau purge si invalides
  if (invalides.length > 0) {
    html += '<div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:10px 14px;font-size:12px;color:#991b1b">';
    html += '⚠️ ' + invalides.length + ' facteur(s) invalide(s). ';
    html += '<button class="btn btn-sm" onclick="purgeFacteursInvalides()" style="background:#dc2626;color:white;border:none;font-size:11px;margin-left:8px">🗑️ Purger</button></div>';
  }

  // Boutons d'action
  html += '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">';
  if (methodeActive === 'officielle') {
    html += '<button class="btn btn-primary btn-sm" onclick="syncCarboneADEME(' + anneeActuelle + ')" id="btnSyncCarbone" style="background:#16a34a;border-color:#16a34a">🌐 Sync ADEME ' + anneeActuelle + '</button>';
  } else {
    html += '<button class="btn btn-primary btn-sm" onclick="syncCarboneSimplifiee(' + anneeActuelle + ')" id="btnSyncSimplifie" style="background:#7c3aed;border-color:#7c3aed">📦 Enregistrer simplifiés ' + anneeActuelle + '</button>';
  }
  html += '<button class="btn btn-sm" onclick="recalculerCarboneTout(\'' + methodeActive + '\')" style="background:#0e7490;color:white;border:none">🔄 Recalculer CO₂ (' + methodeActive + ')</button>';
  html += '<button class="btn btn-ghost btn-sm" onclick="addFacteurCarboneManuel()" style="border:1px solid var(--blue);color:var(--blue)">+ Manuellement</button>';
  html += '<label class="btn btn-ghost btn-sm" style="border:1px solid var(--blue);color:var(--blue);cursor:pointer;margin:0" title="Reprendre un exercice passé depuis une Base Carbone archivée">📄 Importer un CSV'
       + '<input type="file" accept=".csv,text/csv" style="display:none" onchange="importerCsvFacteursCarbone(this, \'' + methodeActive + '\')"></label>';
  html += '<button class="btn btn-sm" onclick="purgerTousFacteurs(\'' + methodeActive + '\')" style="background:#dc2626;color:white;border:none" title="Supprimer TOUS les facteurs de cette méthode avant de resynchroniser">🗑️ Purger tout</button>';
  html += '<div style="flex:1"></div>';
  html += '<span style="font-size:11px;color:var(--gray-text)">' + valides.length + ' facteur(s)</span>';
  html += '</div>';

  // Affichage des facteurs de la méthode active
  html += '<div>';
  html += _renderTableFacteurs(facteursParAnnee, methodeActive);
  html += '</div>';

  html += '</div>';

  var titre = methodeActive === 'simplifiee'
    ? '📦 Facteurs d\'émission CO₂ — Simplifiée (Impact CO2)'
    : '🏛️ Facteurs d\'émission CO₂ — Officielle (ADEME)';
  openModal(titre, html, null, 'Fermer', null, '950px');
}

// ── Purge invalides ─────────────────────────────────────────────────────────
async function purgeFacteursInvalides() {
  var methode = _methodeCarbone;
  var facteurs = await EnergieApi.getFacteursCarbone(methode);
  var count = 0;
  for (var i = 0; i < facteurs.length; i++) {
    var f = facteurs[i];
    if (!f.Annee || !f.FacteurCO2 || parseFloat(f.FacteurCO2) <= 0) {
      try { await EnergieApi.deleteFacteurCarbone(f.Id, methode); count++; } catch(e) {}
    }
  }
  _clearFacteursCache();
  toast(count + ' facteur(s) invalide(s) supprimé(s).', 'success');
  closeModal();
  setTimeout(function() { openFacteursCarbone(); }, 200);
}

// ── Purge TOUS les facteurs de la méthode active ────────────────────────────
async function purgerTousFacteurs(methode) {
  methode = methode || _methodeCarbone;
  var label = methode === 'simplifiee' ? 'simplifiés' : 'officiels';
  showConfirm('Supprimer TOUS les facteurs ' + label + ' ?\nVous pourrez ensuite resynchroniser des valeurs propres.', async function() {
    try {
      await EnergieApi.purgerFacteurs(methode);
      _clearFacteursCache();
      toast('Tous les facteurs ' + label + ' supprimés. Resynchronisez maintenant.', 'success');
      closeModal();
      setTimeout(function() { openFacteursCarbone(); }, 200);
    } catch(e) { toast(e.message, 'error'); }
  }, 'Supprimer tout');
}

// ── Ajout manuel ─────────────────────────────────────────────────────────────
function addFacteurCarboneManuel() {
  var annee = new Date().getFullYear();
  openModal('Ajouter un facteur',
    '<div class="form-grid">' +
    '<div class="form-group"><label class="form-label">Année *</label><input class="form-control" type="number" id="fc_annee" value="' + annee + '" min="2015" max="2050"></div>' +
    '<div class="form-group"><label class="form-label">Méthode *</label><select class="form-control" id="fc_methode"><option value="officielle">🏛️ Officielle (ADEME)</option><option value="simplifiee">📦 Simplifiée (Impact CO2)</option></select></div>' +
    '<div class="form-group"><label class="form-label">Type *</label><select class="form-control" id="fc_type"><option value="Electricite">⚡ Électricité</option><option value="Gaz">🔵 Gaz</option><option value="Chauffage">🔥 Chauffage</option><option value="Eau">💧 Eau</option></select></div>' +
    '<div class="form-group"><label class="form-label">Facteur CO₂ *</label><input class="form-control" type="number" step="0.0001" id="fc_facteur" placeholder="0.0521"></div>' +
    '<div class="form-group"><label class="form-label">Unité</label><input class="form-control" id="fc_unite" value="kgCO2e/kWh"></div>' +
    '<div class="form-group"><label class="form-label">Source</label><input class="form-control" id="fc_source" placeholder="ADEME, Impact CO2, DPE..."></div></div>'
  , async function() {
    var a = parseInt(document.getElementById('fc_annee').value);
    var t = document.getElementById('fc_type').value;
    var f = parseFloat(document.getElementById('fc_facteur').value);
    var m = document.getElementById('fc_methode').value;
    if (!a || !t || isNaN(f) || f <= 0) { toast('Champs obligatoires.', 'error'); return; }
    try {
      await EnergieApi.upsertFacteurCarbone({
        annee: a, type: t, facteurCO2: f, methode: m,
        unite: document.getElementById('fc_unite').value || 'kgCO2e/kWh',
        source: document.getElementById('fc_source').value || 'Saisie manuelle'
      });
      _clearFacteursCache();
      toast('Enregistré.', 'success');
      closeModal();
      setTimeout(function() { openFacteursCarbone(); }, 200);
    } catch(e) { toast(e.message, 'error'); }
  });
}

async function deleteFacteurCarbone(id) {
  showConfirm('Supprimer ?', async function() {
    try {
      await EnergieApi.deleteFacteurCarbone(id, _methodeCarbone);
      _clearFacteursCache();
      toast('Supprimé.');
      closeModal();
      setTimeout(function() { openFacteursCarbone(); }, 200);
    } catch(e) { toast(e.message, 'error'); }
  });
}

// ═══════════════════════════════════════════════════════════════════════════════
// ── Import CSV d'un millésime énergie ───────────────────────────────────────
// ═══════════════════════════════════════════════════════════════════════════════
/**
 * Seule voie correcte pour reconstituer un exercice passé : « Sync ADEME »
 * écrirait les valeurs du jour sous l'étiquette d'une année révolue. Les
 * valeurs viennent donc d'une Base Carbone archivée.
 *
 * Colonnes attendues : annee, type, facteurCO2, unite, source, incertitude.
 * Séparateur ; ou , — virgule décimale acceptée (exports Excel français).
 */
async function importerCsvFacteursCarbone(input, methode) {
  var f = input.files && input.files[0];
  input.value = '';
  if (!f) return;

  var texte = await new Promise(function(res, rej) {
    var r = new FileReader();
    r.onload = function() { res(String(r.result)); };
    r.onerror = function() { rej(new Error('lecture impossible')); };
    r.readAsText(f, 'UTF-8');
  }).catch(function(e) { toast(e.message, 'error'); return null; });
  if (texte === null) return;

  var lignes = texte.split(/\r?\n/).filter(function(l) { return l.trim(); });
  if (lignes.length < 2) { toast('CSV vide ou sans données.', 'error'); return; }

  var sep = (lignes[0].match(/;/g) || []).length >= (lignes[0].match(/,/g) || []).length ? ';' : ',';
  var entetes = lignes[0].split(sep).map(function(h) {
    return h.trim().replace(/^["']|["']$/g, '').toLowerCase();
  });
  var col = function(nom) { return entetes.indexOf(nom.toLowerCase()); };
  var iAnnee = col('annee'), iType = col('type'), iFacteur = col('facteurCO2');
  if (iAnnee < 0 || iType < 0 || iFacteur < 0) {
    toast('Colonnes obligatoires manquantes : annee, type, facteurCO2.', 'error');
    return;
  }
  var iUnite = col('unite'), iSource = col('source'), iIncert = col('incertitude');

  var typesConnus = ['Electricite', 'Gaz', 'Chauffage'];
  var aEcrire = [], rejets = [];

  lignes.slice(1).forEach(function(l, n) {
    var c = l.split(sep).map(function(x) { return x.trim().replace(/^["']|["']$/g, ''); });
    var annee = parseInt(c[iAnnee]);
    var type  = c[iType];
    var val   = parseFloat((c[iFacteur] || '').replace(',', '.'));

    if (!annee || annee < 2000 || annee > 2100) { rejets.push('ligne ' + (n+2) + ' : année invalide'); return; }
    if (typesConnus.indexOf(type) < 0)          { rejets.push('ligne ' + (n+2) + ' : type « ' + type +' » inconnu'); return; }
    if (isNaN(val) || val <= 0)                 { rejets.push('ligne ' + (n+2) + ' : facteur invalide'); return; }

    aEcrire.push({
      annee: annee, type: type, facteurCO2: val, methode: methode,
      unite:  iUnite  >= 0 ? c[iUnite]  : (type === 'Gaz' ? 'kgCO2e/kWh PCI' : 'kgCO2e/kWh'),
      source: iSource >= 0 && c[iSource] ? c[iSource] : ('Import CSV — ' + f.name),
      incertitude: iIncert >= 0 ? parseFloat((c[iIncert] || '').replace(',', '.')) || null : null,
    });
  });

  if (!aEcrire.length) {
    toast('Aucune ligne exploitable. ' + rejets.slice(0, 3).join(' · '), 'error');
    return;
  }

  var annees = aEcrire.map(function(x) { return x.annee; })
                      .filter(function(v, i, a) { return a.indexOf(v) === i; })
                      .sort();
  var resume = aEcrire.length + ' facteur(s) sur ' + annees.length + ' année(s) : ' + annees.join(', ')
             + '.\n\nLes millésimes existants pour ces années seront ÉCRASÉS.'
             + (rejets.length ? '\n\n' + rejets.length + ' ligne(s) rejetée(s).' : '')
             + '\n\nContinuer ?';
  if (!confirm(resume)) return;

  var ok = 0, erreurs = [];
  for (var i = 0; i < aEcrire.length; i++) {
    try { await EnergieApi.upsertFacteurCarbone(aEcrire[i]); ok++; }
    catch (e) { erreurs.push(aEcrire[i].annee + '/' + aEcrire[i].type + ' : ' + e.message); }
  }

  // Le cache indexe type_annee_methode : sans purge, les écrans continueraient
  // d'afficher les anciens facteurs jusqu'au rechargement de la page.
  _clearFacteursCache();
  toast(ok + ' facteur(s) importé(s).' + (erreurs.length ? ' ' + erreurs.length + ' en échec.' : ''),
        erreurs.length ? 'error' : 'success');
  if (typeof closeModal === 'function') closeModal();
  if (typeof openFacteursCarbone === 'function') openFacteursCarbone();
}

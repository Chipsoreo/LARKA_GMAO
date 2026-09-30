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
 * Larka — Module Énergie : Compteurs
 * Modales de création/modification des compteurs (électricité, eau, gaz…).
 */
// ── Modal compteur ────────────────────────────────────────────────────────────
let _compteurAllContrats = []; // Cache pour filtrage par type
async function editCompteur(id, typeForce) {
  const isNew = !id;
  const contrats = await ContratsApi.getAll();
  _compteurAllContrats = contrats;
  let cpt = {};
  if (!isNew) {
    const all = await EnergieApi.getCompteurs();
    cpt = all.find(x => x.Id === id) || {};
  }
  const type = typeForce || cpt.Type || 'Electricite';
  const unite = ENERGIE_CONFIG[type]?.unite || 'unité';
  // Filtrer les contrats par fluide correspondant au type du compteur
  const contratsFiltered = contrats.filter(c => c.Fluide === type);
  const optsContrats = `<option value="">— Aucun —</option>` +
    contratsFiltered.map(c => `<option value="${c.Id}" ${cpt.ContratId===c.Id?'selected':''}>${escHtml(c.Numero||'')} — ${escHtml(c.Societe||'')}</option>`).join('');

  // Parse existing repartition
  let repartition = [];
  if (cpt.RepartitionPresta) {
    try { repartition = JSON.parse(cpt.RepartitionPresta); } catch(e) {}
  }
  const hasRepart = repartition.length > 0;

  // Parse existing chauffage TED config
  let tedCfg = {};
  if (cpt.ChauffageTED) {
    try { tedCfg = JSON.parse(cpt.ChauffageTED); } catch(e) {}
  }

  // Parse sous-compteurs (multi-compteur)
  let sousCompteurs = [];
  if (cpt.SousCompteurs) {
    try { sousCompteurs = JSON.parse(cpt.SousCompteurs); } catch(e) {}
  }
  const isMulti = Array.isArray(sousCompteurs) && sousCompteurs.length >= 2;

  openModal(isNew ? 'Nouveau compteur' : `Modifier — ${escHtml(cpt.Nom||'')}`, `
  <div class="form-grid">
    <div class="form-group">
      <label class="form-label">Nom <span class="req">*</span></label>
      <input class="form-control" id="f_nom" value="${escHtml(cpt.Nom||'')}" placeholder="Ex: Compteur principal">
    </div>
    <div class="form-group">
      <label class="form-label">Type</label>
      <select class="form-control" id="f_type" onchange="document.getElementById('blocChauffageTED').style.display=this.value==='Chauffage'?'block':'none';_updateContratsParType(this.value)">
        ${Object.keys(ENERGIE_CONFIG).map(k=>`<option ${type===k?'selected':''}>${k}</option>`).join('')}
      </select>
    </div>
    <div class="form-group" id="blocNumeroCompteur" style="display:${isMulti?'none':'block'}">
      <label class="form-label">N° Compteur</label>
      <input class="form-control" id="f_numeroCompteur" value="${escHtml(cpt.NumeroCompteur||'')}">
    </div>
    <div class="form-group">
      <label class="form-label">Site / Bâtiment</label>
      <input class="form-control" id="f_site" value="${escHtml(cpt.Site||'')}">
    </div>
    <div class="form-group">
      <label class="form-label">Unité</label>
      <input class="form-control" id="f_unite" value="${cpt.Unite||unite}" placeholder="kWh, m³…">
    </div>
    <div class="form-group">
      <label class="form-label">Contrat lié</label>
      <select class="form-control" id="f_contratId">${optsContrats}</select>
    </div>
    <div class="form-group">
      <label class="form-label">Date installation</label>
      <input class="form-control" type="date" id="f_dateInstallation" value="${cpt.DateInstallation||''}">
    </div>
    <div class="form-group">
      <label class="form-label">Actif</label>
      <select class="form-control" id="f_actif">
        <option value="1" ${cpt.Actif!==0?'selected':''}>Oui</option>
        <option value="0" ${cpt.Actif===0?'selected':''}>Non</option>
      </select>
    </div>

    <div class="form-group" style="grid-column:span 2">
      <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;font-weight:600;color:var(--blue)">
        <input type="checkbox" id="f_multi" ${isMulti?'checked':''}
          onchange="toggleSousCompteursCfg()" style="width:18px;height:18px;accent-color:var(--blue)">
        🔌 Plusieurs compteurs (multi-compteur)
      </label>
      <div style="font-size:11px;color:var(--gray-text);margin-top:4px">
        Activez si 1 locataire / bâtiment a plusieurs numéros de compteurs (sous-compteurs).
      </div>
    </div>
  </div>

  <div id="blocSousCompteursCfg" style="display:${isMulti?'block':'none'};margin-top:12px;border-top:1px solid var(--gray-border);padding-top:12px">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
      <div style="font-size:12px;font-weight:700;color:var(--gray-text)">Sous-compteurs</div>
      <button type="button" class="btn btn-ghost btn-sm" onclick="addSousCompteurLigne()" style="color:var(--blue);font-size:12px">+ Ajouter</button>
    </div>
    <div id="sousCompteursLignes" style="display:flex;flex-direction:column;gap:8px"></div>
    <div style="font-size:11px;color:var(--gray-text);margin-top:8px">
      Les relevés seront saisis en une seule fois et totalisés automatiquement.
    </div>
  </div>

  <!-- ═══ CONFIGURATION CHAUFFAGE (si type = Chauffage) ═══ -->
  <div id="blocChauffageTED" style="display:${type==='Chauffage'?'block':'none'};margin-top:16px;border-top:1px solid var(--gray-border);padding-top:16px">
    <div style="font-size:13px;font-weight:700;color:#ef4444;margin-bottom:10px">🔥 Chauffage Urbain</div>
    <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:10px 14px;margin-bottom:12px;font-size:12px;color:#991b1b">
      ℹ️ Les prix R1 et R2 étant révisés chaque mois, ils sont saisis directement à la saisie de chaque facture.
      Indiquez ici uniquement la <strong>puissance souscrite</strong>, la <strong>TVA</strong> et le <strong>réseau de chaleur</strong>.
    </div>
    <div class="form-grid">
      <div class="form-group">
        <label class="form-label">Puissance souscrite (kW)</label>
        <input class="form-control" type="number" step="0.01" id="f_puissanceSouscrite"
          value="${escHtml(cpt.PuissanceSouscrite||'')}" placeholder="Ex: 260">
      </div>
      <div class="form-group">
        <label class="form-label">TVA applicable (%)</label>
        <input class="form-control" type="number" step="0.1" id="f_tvaChauffage"
          value="${cpt.TVAChauffage||5.5}" placeholder="5.5">
      </div>
    </div>

    <div style="margin-top:12px;padding:12px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px">
      <div style="font-size:12px;font-weight:700;color:#16a34a;margin-bottom:8px">🌿 Réseau de chaleur — Facteur CO₂</div>
      <div style="font-size:11px;color:#166534;margin-bottom:10px">
        Chaque réseau de chaleur a son propre contenu CO₂ (arrêté DPE). Recherchez votre réseau pour utiliser la bonne valeur.
      </div>
      <div class="form-grid">
        <div class="form-group" style="grid-column:span 2">
          <label class="form-label">Réseau de chaleur</label>
          <div style="display:flex;gap:6px">
            <input class="form-control" id="f_reseauChaleurNom" value="${tedCfg.reseauNom||''}"
              placeholder="Nom du réseau (ex: CPCU Paris)" style="flex:1">
            <button type="button" class="btn btn-sm" onclick="rechercherReseauChaleur()"
              style="background:#16a34a;color:white;border:none;font-size:11px;white-space:nowrap">
              🔍 Chercher
            </button>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Identifiant réseau (arrêté DPE)</label>
          <input class="form-control" id="f_reseauChaleurId" value="${tedCfg.reseauId||''}"
            placeholder="Ex: 7501C" style="font-size:12px">
        </div>
        <div class="form-group">
          <label class="form-label">Coeff. CO₂ (kgCO₂/kWh) <span style="color:#16a34a;font-weight:700">★</span></label>
          <input class="form-control" type="number" step="0.0001" id="f_coeffCO2"
            value="${tedCfg.coeffCO2||''}" placeholder="0.112"
            style="font-weight:700;color:#16a34a;border-color:#16a34a"
            oninput="_previewCO2Chauffage()">
          <div style="font-size:10px;color:var(--gray-text);margin-top:3px" id="co2ChauffPreview">
            ${tedCfg.coeffCO2 ? '→ ' + (tedCfg.coeffCO2 * 1000).toFixed(1) + ' gCO₂/kWh' : 'Défaut : 0.112 kgCO₂/kWh (mix moyen France)'}
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ═══ RÉPARTITION FACTURE ENTRE PRESTATAIRES ═══ -->
  <div style="margin-top:16px;border-top:1px solid var(--gray-border);padding-top:16px">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px">
      <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;font-weight:600;color:var(--blue)">
        <input type="checkbox" id="f_repartitionActive" ${hasRepart?'checked':''}
          onchange="toggleRepartitionPresta()" style="width:18px;height:18px;accent-color:var(--blue)">
        💰 Répartir la facture entre plusieurs prestataires
      </label>
    </div>

    <div id="blocRepartition" style="display:${hasRepart?'block':'none'}">
      <div style="background:#fef3c7;border:1px solid #fbbf24;border-radius:8px;padding:10px 14px;margin-bottom:12px;font-size:12px;color:#92400e">
        ⚠️ Le total des pourcentages doit faire <strong>100%</strong>. Chaque facture sera automatiquement divisée selon cette répartition.
      </div>

      <div id="repartitionLignes" style="display:flex;flex-direction:column;gap:8px">
        <!-- Lignes dynamiques -->
      </div>

      <div style="display:flex;align-items:center;justify-content:space-between;margin-top:10px">
        <button type="button" class="btn btn-ghost btn-sm" onclick="addRepartitionLigne()" style="color:var(--blue);font-size:12px">
          + Ajouter un prestataire
        </button>
        <div style="display:flex;align-items:center;gap:8px">
          <span style="font-size:12px;color:var(--gray-text)">Total :</span>
          <span id="repartitionTotal" style="font-size:14px;font-weight:700;color:var(--blue)">0%</span>
          <span id="repartitionStatus" style="font-size:16px">—</span>
        </div>
      </div>
    </div>
  </div>
`, async () => {
    const nom = gv('f_nom');
    if (!nom) { toast('Le nom est obligatoire.', 'error'); return; }

    // Build repartition JSON
    let repartitionPresta = null;
    const isRepartActive = document.getElementById('f_repartitionActive')?.checked;
    if (isRepartActive) {
      const lignes = document.querySelectorAll('.repart-ligne');
      const rep = [];
      let totalPct = 0;
      for (const ligne of lignes) {
        const contratId = parseInt(ligne.querySelector('.repart-contrat')?.value);
        const nomPresta = ligne.querySelector('.repart-nom')?.value?.trim();
        const pct = parseFloat(ligne.querySelector('.repart-pct')?.value);
        if ((!contratId && !nomPresta) || isNaN(pct) || pct <= 0) continue;
        // If contrat selected, grab display name from the select
        let displayNom = nomPresta;
        if (contratId) {
          const sel = ligne.querySelector('.repart-contrat');
          displayNom = sel?.options[sel.selectedIndex]?.text || nomPresta;
        }
        totalPct += pct;
        rep.push({
          contratId: contratId || null,
          nom: displayNom || null,
          pourcentage: pct,
        });
      }
      if (rep.length === 0) {
        toast('Ajoutez au moins un prestataire pour la répartition.', 'error');
        return;
      }
      if (Math.abs(totalPct - 100) > 0.1) {
        toast(`Le total des pourcentages doit faire 100% (actuellement ${totalPct.toFixed(1)}%).`, 'error');
        return;
      }
      repartitionPresta = JSON.stringify(rep);
    }

    // Build chauffage defaults + réseau de chaleur
    let puissanceSouscrite = null;
    let tvaChauffage = null;
    let chauffageTEDPayload = null;
    if (gv('f_type') === 'Chauffage') {
      puissanceSouscrite = parseFloat(gv('f_puissanceSouscrite')) || null;
      tvaChauffage = parseFloat(gv('f_tvaChauffage')) || 5.5;
      // Réseau de chaleur
      const ted = {};
      const reseauNom = document.getElementById('f_reseauChaleurNom')?.value?.trim();
      const reseauId  = document.getElementById('f_reseauChaleurId')?.value?.trim();
      const coeffCO2  = parseFloat(document.getElementById('f_coeffCO2')?.value);
      if (reseauNom) ted.reseauNom = reseauNom;
      if (reseauId)  ted.reseauId  = reseauId;
      if (!isNaN(coeffCO2) && coeffCO2 > 0) ted.coeffCO2 = coeffCO2;
      if (Object.keys(ted).length > 0) chauffageTEDPayload = JSON.stringify(ted);
    }

    // Build sous-compteurs JSON (multi-compteur)
    let sousCompteursPayload = null;
    const multi = document.getElementById('f_multi')?.checked;
    if (multi) {
      const lignes = document.querySelectorAll('.souscpt-ligne');
      const arr = [];
      for (const ligne of lignes) {
        const numero = ligne.querySelector('.souscpt-num')?.value?.trim();
        const nomS   = ligne.querySelector('.souscpt-nom')?.value?.trim();
        if (!numero && !nomS) continue;
        arr.push({ numero: numero || null, nom: nomS || null });
      }
      if (arr.length < 2) {
        toast('Multi-compteur : ajoutez au moins 2 sous-compteurs.', 'error');
        return;
      }
      sousCompteursPayload = JSON.stringify(arr);
    }

    const payload = {
      nom, type: gv('f_type'), numeroCompteur: gv('f_numeroCompteur'),
      site: gv('f_site'), unite: gv('f_unite'),
      contratId: parseInt(gv('f_contratId'))||null,
      dateInstallation: gv('f_dateInstallation'),
      actif: parseInt(gv('f_actif')),
      repartitionPresta: repartitionPresta,
      chauffageTED: chauffageTEDPayload,
      puissanceSouscrite: puissanceSouscrite,
      sousCompteurs: sousCompteursPayload,
      tvaChauffage: tvaChauffage,
    };
    try {
      if (isNew) await EnergieApi.addCompteur(payload);
      else       await EnergieApi.updateCompteur(id, payload);
      toast(isNew ? 'Compteur créé.' : 'Compteur modifié.', 'success');
      closeModal(); renderEnergie();
    } catch(e) { toast(e.message, 'error'); }
  });

  // Init repartition lines after modal is open
  setTimeout(() => {
    _initRepartitionContrats(contrats, repartition);
    _initSousCompteursCfg(sousCompteurs);
    if (isMulti) toggleSousCompteursCfg();
  }, 50);
}

// ── Repartition helpers ──────────────────────────────────────────────────────

// Cache contrats for repartition
let _repartContrats = [];

function _initRepartitionContrats(contrats, existingRepartition) {
  _repartContrats = contrats;
  const container = document.getElementById('repartitionLignes');
  if (!container) return;
  container.innerHTML = '';
  if (existingRepartition && existingRepartition.length > 0) {
    existingRepartition.forEach(rep => addRepartitionLigne(rep));
  }
}

function toggleRepartitionPresta() {
  const checked = document.getElementById('f_repartitionActive')?.checked;
  const bloc = document.getElementById('blocRepartition');
  if (!bloc) return;
  bloc.style.display = checked ? 'block' : 'none';
  if (checked) {
    const container = document.getElementById('repartitionLignes');
    if (container && container.children.length === 0) {
      addRepartitionLigne();
    }
  }
}

function addRepartitionLigne(data) {
  const container = document.getElementById('repartitionLignes');
  if (!container) return;

  const contratId = data?.contratId || '';
  const nomPresta = data?.nom || '';
  const pct = data?.pourcentage || '';

  const optsContrats = `<option value="">— Saisie libre —</option>` +
    _repartContrats.map(c =>
      `<option value="${c.Id}" ${contratId == c.Id ? 'selected' : ''}>${escHtml(c.Numero||'')} — ${escHtml(c.Societe||'')}</option>`
    ).join('');

  const ligne = document.createElement('div');
  ligne.className = 'repart-ligne';
  ligne.style.cssText = 'display:flex;align-items:center;gap:8px;padding:10px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px';
  ligne.innerHTML = `
    <div style="flex:2;min-width:0">
      <div style="font-size:10px;color:var(--gray-text);margin-bottom:3px">Prestataire (contrat ou nom libre)</div>
      <select class="form-control repart-contrat" onchange="onRepartContratChange(this)" style="font-size:12px">
        ${optsContrats}
      </select>
      <input class="form-control repart-nom" value="${!contratId ? nomPresta : ''}"
        placeholder="Nom du prestataire"
        style="font-size:12px;margin-top:4px;display:${contratId ? 'none' : 'block'}">
    </div>
    <div style="flex:0 0 100px">
      <div style="font-size:10px;color:var(--gray-text);margin-bottom:3px">Pourcentage</div>
      <div style="display:flex;align-items:center;gap:4px">
        <input class="form-control repart-pct" type="number" min="0" max="100" step="0.1"
          value="${pct}" oninput="updateRepartitionTotal()" style="font-size:13px;font-weight:600;text-align:center">
        <span style="font-weight:700;color:var(--gray-text)">%</span>
      </div>
    </div>
    <div style="flex:0 0 auto;padding-top:14px">
      <button type="button" class="icon-btn delete" onclick="removeRepartitionLigne(this)" title="Supprimer"
        style="font-size:14px;padding:4px">🗑️</button>
    </div>
  `;
  container.appendChild(ligne);
  updateRepartitionTotal();
}

function onRepartContratChange(select) {
  const ligne = select.closest('.repart-ligne');
  const nomInput = ligne?.querySelector('.repart-nom');
  if (!nomInput) return;
  if (select.value) {
    nomInput.style.display = 'none';
    nomInput.value = '';
  } else {
    nomInput.style.display = 'block';
    nomInput.value = '';
  }
}

function removeRepartitionLigne(btn) {
  const ligne = btn.closest('.repart-ligne');
  if (ligne) {
    ligne.remove();
    updateRepartitionTotal();
  }
}

function updateRepartitionTotal() {
  let total = 0;
  document.querySelectorAll('.repart-pct').forEach(el => {
    total += parseFloat(el.value) || 0;
  });
  const elTotal = document.getElementById('repartitionTotal');
  const elStatus = document.getElementById('repartitionStatus');
  if (elTotal) {
    elTotal.textContent = total.toFixed(1) + '%';
    elTotal.style.color = Math.abs(total - 100) < 0.1 ? '#16a34a' : '#dc2626';
  }
  if (elStatus) {
    if (Math.abs(total - 100) < 0.1) {
      elStatus.textContent = '✅';
      elStatus.title = 'Total = 100%';
    } else if (total < 100) {
      elStatus.textContent = '⚠️';
      elStatus.title = `Il manque ${(100 - total).toFixed(1)}%`;
    } else {
      elStatus.textContent = '❌';
      elStatus.title = `Excès de ${(total - 100).toFixed(1)}%`;
    }
  }
}

async function deleteCompteur(id) {
  showConfirm('Supprimer ce compteur et tous ses relevés ?', async () => {
    try { await EnergieApi.deleteCompteur(id); toast('Compteur supprimé.'); renderEnergie(); }
    catch(e) { toast(e.message, 'error'); }
  });
}


// ── Sous-compteurs (multi-compteur) helpers ───────────────────────────────
let _sousCompteursCache = [];

function toggleSousCompteursCfg() {
  const multi = document.getElementById('f_multi')?.checked;
  const bloc = document.getElementById('blocSousCompteursCfg');
  const numBloc = document.getElementById('blocNumeroCompteur');
  if (bloc) bloc.style.display = multi ? 'block' : 'none';
  if (numBloc) numBloc.style.display = multi ? 'none' : 'block';
  if (multi) {
    _initSousCompteursCfg(_sousCompteursCache);
    // Si aucune ligne, en créer 2 par défaut
    const cont = document.getElementById('sousCompteursLignes');
    if (cont && cont.children.length === 0) {
      addSousCompteurLigne();
      addSousCompteurLigne();
    }
  }
}

function _initSousCompteursCfg(existing) {
  _sousCompteursCache = Array.isArray(existing) ? existing : [];
  const cont = document.getElementById('sousCompteursLignes');
  if (!cont) return;
  cont.innerHTML = '';
  if (_sousCompteursCache.length) {
    _sousCompteursCache.forEach(sc => addSousCompteurLigne(sc));
  }
}

function addSousCompteurLigne(data) {
  const cont = document.getElementById('sousCompteursLignes');
  if (!cont) return;
  const numero = data?.numero || '';
  const nom = data?.nom || '';
  const ligne = document.createElement('div');
  ligne.className = 'souscpt-ligne';
  ligne.style.cssText = 'display:flex;gap:8px;align-items:center;padding:10px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px';
  ligne.innerHTML = `
    <div style="flex:1">
      <div style="font-size:10px;color:var(--gray-text);margin-bottom:3px">N° Compteur</div>
      <input class="form-control souscpt-num" value="${numero}" placeholder="Ex: 12345678" style="font-size:12px">
    </div>
    <div style="flex:1">
      <div style="font-size:10px;color:var(--gray-text);margin-bottom:3px">Nom / repère</div>
      <input class="form-control souscpt-nom" value="${nom}" placeholder="Ex: Cuisine" style="font-size:12px">
    </div>
    <div style="flex:0 0 auto;padding-top:14px">
      <button type="button" class="icon-btn delete" onclick="removeSousCompteurLigne(this)" title="Supprimer" style="font-size:14px;padding:4px">🗑️</button>
    </div>
  `;
  cont.appendChild(ligne);
}

function removeSousCompteurLigne(btn) {
  const ligne = btn.closest('.souscpt-ligne');
  if (ligne) ligne.remove();
}

// ── Mise à jour contrats selon le type de compteur ────────────────────────────
function _updateContratsParType(type) {
  const sel = document.getElementById('f_contratId');
  if (!sel) return;
  const currentVal = sel.value;
  const filtered = _compteurAllContrats.filter(c => c.Fluide === type);
  sel.innerHTML = `<option value="">— Aucun —</option>` +
    filtered.map(c => `<option value="${c.Id}" ${String(c.Id) === currentVal ? 'selected' : ''}>${escHtml(c.Numero||'')} — ${escHtml(c.Societe||'')}</option>`).join('');
}

// ═══════════════════════════════════════════════════════════════════════════════
// ── Recherche de réseau de chaleur (arrêté DPE) ─────────────────────────────
// ═══════════════════════════════════════════════════════════════════════════════

function _previewCO2Chauffage() {
  var v = parseFloat(document.getElementById('f_coeffCO2')?.value);
  var el = document.getElementById('co2ChauffPreview');
  if (el) {
    el.textContent = (!isNaN(v) && v > 0)
      ? '→ ' + (v * 1000).toFixed(1) + ' gCO₂/kWh'
      : 'Défaut : 0.112 kgCO₂/kWh (mix moyen France)';
  }
}

async function rechercherReseauChaleur() {
  var query = (document.getElementById('f_reseauChaleurNom')?.value || '').trim();
  if (!query || query.length < 2) {
    toast('Saisissez au moins 2 caractères (commune ou nom du réseau).', 'info');
    return;
  }

  // Appel au proxy PHP qui interroge l'API
  try {
    var data = await apiRequest('energie_recherche_reseau&q=' + encodeURIComponent(query));
    if (!data || !data.length) {
      toast('Aucun réseau trouvé pour "' + query + '".', 'info');
      return;
    }
    _afficherResultatsReseau(data);
  } catch(e) {
    toast('Erreur recherche : ' + e.message, 'error');
  }
}

function _afficherResultatsReseau(reseaux) {
  // Afficher les résultats INLINE dans le formulaire compteur (pas dans une nouvelle modal)
  var container = document.getElementById('reseauResultats');
  if (!container) {
    // Créer le conteneur après le bouton chercher
    var btn = document.querySelector('[onclick*="rechercherReseauChaleur"]');
    var parent = btn ? btn.closest('.form-group') : null;
    if (!parent) return;
    container = document.createElement('div');
    container.id = 'reseauResultats';
    container.style.cssText = 'grid-column:span 2';
    parent.after(container);
  }

  var html = '<div style="margin-top:8px;border:1px solid #bbf7d0;border-radius:8px;background:#f0fdf4;padding:12px;max-height:260px;overflow-y:auto">';
  html += '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">';
  html += '<span style="font-size:11px;color:#166534;font-weight:600">' + reseaux.length + ' réseau(x) trouvé(s) — cliquez pour sélectionner</span>';
  html += '<button type="button" onclick="document.getElementById(\'reseauResultats\').innerHTML=\'\'" style="font-size:10px;background:none;border:none;color:var(--gray-text);cursor:pointer">✕ Fermer</button>';
  html += '</div>';
  html += '<table style="width:100%;border-collapse:collapse;font-size:11px">';
  html += '<thead><tr style="background:#dcfce7">';
  html += '<th style="padding:4px 6px;text-align:left">Réseau</th>';
  html += '<th style="padding:4px 6px;text-align:left">Commune</th>';
  html += '<th style="padding:4px 6px;text-align:center">ID</th>';
  html += '<th style="padding:4px 6px;text-align:right">CO₂</th>';
  html += '<th style="padding:4px 6px;text-align:right">CO₂ ACV</th>';
  html += '<th style="padding:4px 6px;text-align:right">EnR&R</th>';
  html += '</tr></thead><tbody>';

  for (var i = 0; i < reseaux.length; i++) {
    var r = reseaux[i];
    var co2 = r.co2 !== null ? r.co2 : '—';
    var co2acv = r.co2_acv !== null ? r.co2_acv : '—';
    var enrr = r.taux_enrr !== null ? r.taux_enrr + '%' : '—';
    var co2Color = (r.co2 !== null && r.co2 <= 100) ? '#16a34a' : (r.co2 !== null && r.co2 <= 200) ? '#f59e0b' : '#ef4444';
    var rJson = JSON.stringify(r).replace(/'/g, "\\'").replace(/"/g, '&quot;');

    html += '<tr style="border-bottom:1px solid #d1fae5;cursor:pointer" onclick="_selectReseau(' + rJson + ')" onmouseover="this.style.background=\'#dcfce7\'" onmouseout="this.style.background=\'\'">';
    html += '<td style="padding:5px 6px;font-weight:600">' + (r.nom || '—') + '</td>';
    html += '<td style="padding:5px 6px;color:var(--gray-text)">' + (r.commune || '—') + '</td>';
    html += '<td style="padding:5px 6px;text-align:center;font-family:monospace;font-size:10px">' + (r.id_reseau || '—') + '</td>';
    html += '<td style="padding:5px 6px;text-align:right;font-weight:700;color:' + co2Color + '">' + co2 + '</td>';
    html += '<td style="padding:5px 6px;text-align:right;color:var(--gray-text)">' + co2acv + '</td>';
    html += '<td style="padding:5px 6px;text-align:right;color:var(--blue)">' + enrr + '</td>';
    html += '</tr>';
  }
  html += '</tbody></table>';
  html += '<div style="font-size:9px;color:var(--gray-text);margin-top:6px">Source : Arrêté DPE 11/04/2025 — données 2021-2022-2023.</div>';
  html += '</div>';

  container.innerHTML = html;

  // Scroll le conteneur en vue
  container.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function _selectReseau(r) {
  var elNom = document.getElementById('f_reseauChaleurNom');
  var elId  = document.getElementById('f_reseauChaleurId');
  var elCO2 = document.getElementById('f_coeffCO2');

  if (elNom) elNom.value = r.nom || '';
  if (elId)  elId.value  = r.id_reseau || '';
  if (elCO2 && r.co2_acv !== null) {
    elCO2.value = (r.co2_acv / 1000).toFixed(4);
  } else if (elCO2 && r.co2 !== null) {
    elCO2.value = (r.co2 / 1000).toFixed(4);
  }

  _previewCO2Chauffage();

  // Fermer la liste de résultats
  var container = document.getElementById('reseauResultats');
  if (container) container.innerHTML = '';

  // Afficher un aperçu du réseau sélectionné sous le champ nom
  var previewEl = document.getElementById('reseauSelectedPreview');
  if (!previewEl) {
    previewEl = document.createElement('div');
    previewEl.id = 'reseauSelectedPreview';
    previewEl.style.cssText = 'grid-column:span 2';
    var nomField = document.getElementById('f_reseauChaleurNom')?.closest('.form-group');
    if (nomField) nomField.after(previewEl);
  }
  var co2Val = r.co2_acv || r.co2 || '?';
  previewEl.innerHTML = '<div style="padding:8px 12px;background:#dcfce7;border:1px solid #86efac;border-radius:6px;font-size:11px;color:#166534;display:flex;align-items:center;gap:8px">' +
    '<span style="font-size:16px">✅</span>' +
    '<div><strong>' + (r.nom || r.id_reseau) + '</strong> — ' + (r.commune || '') +
    '<br>CO₂ ACV : <strong>' + co2Val + ' gCO₂/kWh</strong> → <strong>' + (co2Val / 1000).toFixed(4) + ' kgCO₂/kWh</strong>' +
    (r.taux_enrr !== null ? ' · EnR&R : ' + r.taux_enrr + '%' : '') +
    ' · ID : ' + (r.id_reseau || '—') + '</div></div>';

  toast('Réseau « ' + (r.nom || r.id_reseau) + ' » sélectionné.', 'success');
}

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
 * Larka — Module Énergie : Relevés & Factures
 * Modales de saisie/modification des relevés et factures énergétiques.
 */
// ── Modal relevé / facture ────────────────────────────────────────────────────
let _releveEditState = { id: null, isNew: true, compteurId: null, type: null };

async function editReleve(id, compteurId, type, defaultSaisie) {
  const isNew = !id;
  const cfg   = ENERGIE_CONFIG[type] || ENERGIE_CONFIG.Electricite;

  const tousCompteurs = await EnergieApi.getCompteurs();
  const compteurs = tousCompteurs.filter(x => x.Type === type && x.Actif !== 0);

  let r = {};
  if (!isNew) {
    for (const cpt of compteurs) {
      const releves = await EnergieApi.getRelevers(cpt.Id);
      const found   = releves.find(x => x.Id === id);
      if (found) { r = found; compteurId = cpt.Id; break; }
    }
  }

  // Stocker l'état pour recalcul dynamique
  _releveEditState = { id, isNew, compteurId, type };

  // Pré-remplir index précédent depuis dernier relevé
  let dernierIndex = null;
  let dernierReleve = null;
  let dernierDetailHTA = {};
  let dernierDetailSous = [];
  let _allReleves = []; // conservé pour recalcul dynamique
  if (compteurId) {
    const releves = await EnergieApi.getRelevers(compteurId);
    _allReleves = releves;
    // Trouver le relevé précédent pertinent :
    // - Exclure l'id courant en modification
    // - Prendre le plus récent AVANT la date saisie (cohérence temporelle)
    const relevesAutres = isNew ? releves : releves.filter(x => x.Id !== id);
    const dateSaisie = r.Date || new Date().toISOString().split('T')[0];
    const typeSaisieInit = r.Type || defaultSaisie || 'Releve';
    const relevesFiltres = _filtrerRelevesAvantDate(relevesAutres, dateSaisie, typeSaisieInit);
    if (relevesFiltres.length) {
      dernierReleve = relevesFiltres[0];
      dernierIndex = dernierReleve.IndexFin;
      if (dernierReleve.DetailHTA) {
        try { dernierDetailHTA = JSON.parse(dernierReleve.DetailHTA); } catch(e) {}
      }
      if (dernierReleve.DetailSousCompteurs) {
        try { dernierDetailSous = JSON.parse(dernierReleve.DetailSousCompteurs); } catch(e) {}
      }
    }
  }

    const optsCompteurs = compteurs.map(c => {
    let scCount = 0;
    try {
      const a = JSON.parse(c.SousCompteurs || '[]');
      scCount = Array.isArray(a) ? a.length : 0;
    } catch(e) {}
    const tag = scCount >= 2 ? ` ⚡x${scCount}` : '';
    return `<option value="${c.Id}" ${(compteurId===c.Id||(!compteurId&&compteurs[0]?.Id===c.Id))?'selected':''}>${escHtml(c.Nom||'')}${c.Site?' ('+c.Site+')':''}${tag}</option>`;
  }).join('');

  // Détecter le contrat lié
  let contratLie = null;
  try {
    const cptCourant = tousCompteurs.find(x => x.Id === (compteurId || compteurs[0]?.Id));
    if (cptCourant?.ContratId) {
      const contrats = await ContratsApi.getAll();
      contratLie = contrats.find(x => x.Id === parseInt(cptCourant.ContratId));
    }
  } catch(e) {}

  const detailHTA = r.DetailHTA ? JSON.parse(r.DetailHTA) : {};
  let detailSousCompteursCurrent = [];
  if (r.DetailSousCompteurs) {
    try { detailSousCompteursCurrent = JSON.parse(r.DetailSousCompteurs); } catch(e) {}
  }
  // Option indéfinie : seulement si contrat lié avec option "Je ne sais pas"
  const optionTarifaire = contratLie?.OptionTarifaire || 'Base';
  const isIndefini = contratLie !== null && (contratLie.OptionTarifaire === 'Je ne sais pas' || !contratLie.OptionTarifaire);
  // Lignes de détail facture — disponibles pour tous les types de contrat
  let detailIndefini = { consoFacture: '', lignes: [] };
  if (r.DetailHTA) {
    try {
      const d = JSON.parse(r.DetailHTA);
      if (d && Array.isArray(d.lignes)) {
        detailIndefini = { consoFacture: d.consoFacture ?? '', lignes: d.lignes };
      }
    } catch(e) {}
  }
  const isAdvancedMode = !isIndefini && (r.ModeFacture === 'detail' || (Object.keys(detailHTA).length > 0 && !detailHTA.lignes));
  const isIndefiniAvance = isIndefini && r.ModeFacture === 'indefini_avance';
  const isLignesVisible = r.ModeFacture === 'facture_lignes' || r.ModeFacture === 'indefini_avance';
  const hasPlages = ['HP-HC','Tempo','EJP','HTA 5 plages'].includes(optionTarifaire);

  const fmtDateFR = d => d ? new Date(d).toLocaleDateString('fr-FR') : '—';

  // ── Chauffage : lecture compteur + pré-remplissage depuis dernière facture ────
  let _isChauffage = false;
  let _chauffDefaults = { puissance: 0, tva: 5.5 }; // defaults depuis compteur
  let detailChauff = {};
  let _lastChauff  = {}; // pré-remplissage depuis dernière facture
  if (type === 'Chauffage') {
    _isChauffage = true;
    const tousCompteurs2 = await EnergieApi.getCompteurs();
    const cptCourant = tousCompteurs2.find(x => x.Id === (compteurId || compteurs[0]?.Id));
    if (cptCourant) {
      _chauffDefaults.puissance = cptCourant.PuissanceSouscrite || 0;
      _chauffDefaults.tva       = cptCourant.TVAChauffage || 5.5;
    }
    // Pré-remplissage des prix depuis la dernière facture chauffage
    if (dernierReleve?.DetailChauffage) {
      try { _lastChauff = JSON.parse(dernierReleve.DetailChauffage); } catch(e) {}
    }
    if (r.DetailChauffage) {
      try { detailChauff = JSON.parse(r.DetailChauffage); } catch(e) {}
    }
  }

  // Type de saisie par défaut : respecter le paramètre passé (bug fix)
  const typeSaisie = r.Type || defaultSaisie || 'Releve';

  // Pré-remplissage plages : uniquement l'index début (= index fin du mois dernier)
  const plageValues = (isNew || isIndefini) ? {} : detailHTA;
  const plageIdxPrefill = {};
  if (isNew && Object.keys(dernierDetailHTA).length > 0) {
    _getPlagesForOption(optionTarifaire).forEach(([key]) => {
      const prevEnd = dernierDetailHTA[key + '_idxEnd'];
      if (prevEnd) plageIdxPrefill[key] = prevEnd;
    });
  }

  // Prix par plage depuis le contrat lié (pour affichage et auto-calc)
  const contratPrix = {};
  if (contratLie) {
    if (optionTarifaire === 'HTA 5 plages') {
      contratPrix.hphs = parseFloat(contratLie.HtaHPHS) || 0;
      contratPrix.hchs = parseFloat(contratLie.HtaHCHS) || 0;
      contratPrix.hpbs = parseFloat(contratLie.HtaHPBS) || 0;
      contratPrix.hcbs = parseFloat(contratLie.HtaHCBS) || 0;
    } else if (['HP-HC','Tempo','EJP'].includes(optionTarifaire)) {
      contratPrix.hp = parseFloat(contratLie.PrixHPHT) || 0;
      contratPrix.hc = parseFloat(contratLie.PrixHCHT) || 0;
    }
  }

  openModal(isNew ? `Nouvelle saisie ${cfg.icon} ${type}` : `Modifier saisie`, `
  <div style="display:flex;flex-direction:column;gap:16px">

    <div>
      <div class="section-label">Informations générales</div>
      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Compteur <span class="req">*</span></label>
          <select class="form-control" id="f_compteurId" onchange="onCompteurChangeReleve()">${optsCompteurs}</select>
        </div>
        <div class="form-group">
          <label class="form-label">Type de saisie</label>
          <select class="form-control" id="f_typeSaisie" onchange="toggleReleveFields()">
            <option value="Releve" ${typeSaisie==='Releve'?'selected':''}>📊 Relevé manuel</option>
            <option value="Facture" ${typeSaisie==='Facture'?'selected':''}>🧾 Facture</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Date <span class="req">*</span></label>
          <input class="form-control" type="date" id="f_date" value="${r.Date||new Date().toISOString().split('T')[0]}" onchange="recalcIndexPrecedent()">
        </div>
      </div>
    </div>

    <!-- ═══ BLOC SIMPLE : Index & Prix HT/TTC ═══ -->
    ${contratLie ? `
    <div style="padding:8px 12px;background:#eff8ff;border:1px solid #bfdbfe;border-radius:6px;font-size:12px;display:flex;gap:12px;align-items:center;flex-wrap:wrap">
      <span style="font-weight:600;color:var(--blue)">📋 Contrat : ${escHtml(contratLie.Numero||'')} — ${escHtml(contratLie.Societe||'')}</span>
      <span style="color:var(--gray-text)">|</span>
      <span>Option : <strong>${optionTarifaire}</strong></span>
      ${contratLie.PuissanceSouscrite ? `<span>Puissance : <strong>${escHtml(contratLie.PuissanceSouscrite||'')} kVA</strong></span>` : ''}
      ${contratLie.PrixBaseHT ? `<span>Prix base : <strong>${contratLie.PrixBaseHT} €/${cfg.unite}</strong></span>` : ''}
      ${contratLie.PrixHPHT ? `<span>HP : <strong>${contratLie.PrixHPHT} €</strong> | HC : <strong>${contratLie.PrixHCHT} €</strong></span>` : ''}
    </div>` : ''}
    <div>
      <div class="section-label" style="display:flex;align-items:center;justify-content:space-between">
        <span>Index & Consommation</span>
        ${dernierReleve ? `<span class="dernier-releve-badge" style="font-size:11px;color:var(--gray-text);background:#f0f9ff;padding:3px 10px;border-radius:20px">📌 Dernier : <strong>${fmtDateFR(dernierReleve.Date)}</strong> — Index : <strong>${dernierReleve.IndexFin ?? '—'}</strong> — Conso : <strong>${dernierReleve.Consommation ?? '—'} ${cfg.unite}</strong></span>` : ''}
      </div>
      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Index précédent (${cfg.unite})</label>
          <input class="form-control" type="number" step="0.01" id="f_indexDebut"
            value="${r.IndexDebut ?? dernierIndex ?? ''}" oninput="calcFromIndex()" placeholder="Auto depuis dernier relevé">
        </div>
        <div class="form-group">
          <label class="form-label">Index actuel (${cfg.unite})</label>
          <input class="form-control" type="number" step="0.01" id="f_indexFin"
            value="${r.IndexFin??''}" oninput="calcFromIndex()" placeholder="Relevé actuel">
        </div>
        <div class="form-group">
          <label class="form-label">Consommation (${cfg.unite})</label>
          <input class="form-control" type="number" step="0.01" id="f_consommation"
            value="${r.Consommation??''}" oninput="calcFromConso()" placeholder="Index fin − Index début">
        </div>
      </div>
      <div id="blocSousCompteursReleve" style="display:none;margin-top:10px"></div>
      <div class="form-grid" style="margin-top:8px">
        <div class="form-group">
          <label class="form-label">Montant HT (€)</label>
          <input class="form-control" type="number" step="0.01" id="f_montantHT"
            value="${r.MontantHT??''}" oninput="calcTTCFromHT()" placeholder="Auto si contrat lié">
        </div>
        <div class="form-group">
          <label class="form-label">TVA (%)</label>
          <input class="form-control" type="number" step="0.1" id="f_tvaReleve"
            value="${r.TVAReleve||r.TVA||20}" oninput="calcTTCFromHT()" title="TVA — modifiable si besoin">
        </div>
        <div class="form-group">
          <label class="form-label">Montant TTC (€)</label>
          <input class="form-control" type="number" step="0.01" id="f_montant"
            value="${r.Montant??''}" placeholder="Calculé automatiquement" style="background:var(--gray-bg)" readonly>
        </div>
      </div>
      <div id="blocEauEstimation" style="display:none;margin-top:10px"></div>
    </div>

    <!-- ═══ CARBONE ═══ -->
    <div>
      <div class="section-label">🌿 Empreinte carbone</div>
      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Émission CO₂e (kg)</label>
          <input class="form-control" type="number" step="0.01" id="f_carbone"
            value="${r.Carbone??''}" placeholder="Auto depuis facteur ADEME × conso"
            style="color:#16a34a;font-weight:600"
            title="Calculé automatiquement — modifiable manuellement si besoin">
          <div style="font-size:10px;color:#16a34a;margin-top:3px" id="carboneAutoInfo">
            🔄 Auto (facteur ADEME × conso) — modifiable manuellement
          </div>
        </div>
      </div>
    </div>

    <!-- ═══ BLOC FACTURE ═══ -->
    <div id="blocFacture" style="display:${typeSaisie==='Facture'?'block':'none'}">
      <div class="section-label" style="display:flex;align-items:center;justify-content:space-between">
        <span>Détails facture</span>
        <div style="display:flex;gap:6px">
        ${hasPlages ? `
        <button type="button" class="btn btn-ghost btn-sm" id="btnToggleAdvanced" onclick="toggleAdvancedReleve()"
          style="font-size:12px;border:1px solid #7c3aed;color:#7c3aed">
          ⚡ ${isAdvancedMode ? 'Version simple' : 'Détail par plage'}
        </button>` : ''}
        <button type="button" class="btn btn-ghost btn-sm" id="btnToggleLignes" onclick="toggleLignesFacture()"
          style="font-size:12px;border:1px solid #0891b2;color:#0891b2">
          📋 ${(r.ModeFacture === 'facture_lignes' || r.ModeFacture === 'indefini_avance') ? 'Masquer les lignes' : 'Détail des lignes'}
        </button>
        </div>
      </div>
      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">N° Facture</label>
          <input class="form-control" id="f_numeroFacture" value="${escHtml(r.NumeroFacture||'')}">
        </div>
        <div class="form-group">
          <label class="form-label">Période du</label>
          <input class="form-control" type="date" id="f_periodeDebut" value="${r.PeriodeDebut||''}">
        </div>
        <div class="form-group">
          <label class="form-label">Au</label>
          <input class="form-control" type="date" id="f_periodeFin" value="${r.PeriodeFin||''}">
        </div>
      </div>

      <!-- ═══ BLOC CHAUFFAGE URBAIN — Saisie complète par facture ═══ -->
      ${_isChauffage ? (() => {
        const dc = Object.keys(detailChauff).length ? detailChauff : _lastChauff;
        const hasLast = !Object.keys(detailChauff).length && Object.keys(_lastChauff).length;
        const pref = hasLast ? ' <span style="font-size:10px;font-weight:400;color:#92400e">(pré-rempli depuis la facture précédente)</span>' : '';
        const puissance = dc.puissance || _chauffDefaults.puissance || '';
        const tva       = dc.tva       || _chauffDefaults.tva       || 5.5;
        return `
      <div id="blocChauffageFacture" style="margin-top:12px">
        <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:14px 16px">
          <div style="font-size:12px;font-weight:700;color:#b91c1c;margin-bottom:12px">🔥 Facturation Chauffage Urbain${pref}</div>

          <div class="form-grid" style="margin-bottom:12px;grid-template-columns:1fr 1fr 1fr">
            <div class="form-group">
              <label class="form-label">Puissance souscrite (kW)</label>
              <input class="form-control" type="number" step="0.01" id="f_chauff_puissance"
                value="${puissance}" oninput="calcChauffage()" placeholder="Ex: 260">
            </div>
            <div class="form-group">
              <label class="form-label">TVA R1 / R2.1-2-3 (%)</label>
              <input class="form-control" type="number" step="0.1" id="f_chauff_tva"
                value="${dc.tva || tva}" oninput="calcChauffage()" placeholder="5.5">
              <div style="font-size:10px;color:var(--gray-text);margin-top:2px">Combustible + prestations</div>
            </div>
            <div class="form-group">
              <label class="form-label">TVA R2.4 / CO2 / S (%)</label>
              <input class="form-control" type="number" step="0.1" id="f_chauff_tva2"
                value="${dc.tva2 || 20}" oninput="calcChauffage()" placeholder="20">
              <div style="font-size:10px;color:var(--gray-text);margin-top:2px">Investissement</div>
            </div>
          </div>

          <div style="background:white;border:1px solid #fecaca;border-radius:6px;padding:10px 12px;margin-bottom:10px">
            <div style="font-size:11px;font-weight:700;color:#dc2626;margin-bottom:8px">🔴 R1 — Combustible</div>
            <div class="form-grid" style="grid-template-columns:repeat(3,1fr)">
              <div class="form-group">
                <label class="form-label">Prix R1 (€/MWh)</label>
                <input class="form-control" type="number" step="0.01" id="f_chauff_r1_prix"
                  value="${dc.r1_prix||''}" oninput="calcChauffage()" placeholder="Ex: 58.14">
              </div>
              <div class="form-group">
                <label class="form-label">Consommation (MWh)</label>
                <input class="form-control" type="number" step="0.001" id="f_chauff_r1_conso"
                  value="${dc.r1_conso||''}" oninput="calcChauffage()" placeholder="Ex: 66.370">
              </div>
              <div class="form-group">
                <label class="form-label">R1 HT (€) — auto</label>
                <input class="form-control" type="number" step="0.01" id="f_chauff_r1_ht"
                  value="${dc.r1_ht||''}" style="background:#fef2f2;font-weight:700;color:#dc2626" readonly>
              </div>
            </div>
          </div>

          <div style="background:white;border:1px solid #fed7aa;border-radius:6px;padding:10px 12px;margin-bottom:10px">
            <div style="font-size:11px;font-weight:700;color:#ea580c;margin-bottom:8px">🟠 R2 — Prestations & Investissement</div>

            <!-- En-têtes colonnes -->
            <div style="display:grid;grid-template-columns:160px 1fr 1fr 1fr 1fr;gap:6px;align-items:center;padding:0 4px 4px;border-bottom:1px solid #fde68a;margin-bottom:6px">
              <div style="font-size:10px;font-weight:600;color:var(--gray-text)">Ligne</div>
              <div style="font-size:10px;font-weight:600;color:var(--gray-text)">Prix base (€/kW/an)</div>
              <div style="font-size:10px;font-weight:600;color:#0369a1">Coeff. révision</div>
              <div style="font-size:10px;font-weight:600;color:#15803d">Prix révisé (€/kW/an)</div>
              <div style="font-size:10px;font-weight:600;color:var(--gray-text)">Montant HT (€)</div>
            </div>

            ${[['r21','R2.1 Prestations','#b45309',true],['r22','R2.2 Prestations','#0369a1',true],['r23','R2.3 Garantie totale','#15803d',true],['r24','R2.4 Investissement','#7c3aed',false],['r2s','R2S Investissement','#be185d',false],['r2co2','R2CO2','#0e7490',false]].map(([key,label,col,hasRevision]) => `
            <div style="display:grid;grid-template-columns:160px 1fr 1fr 1fr 1fr;gap:6px;align-items:center;padding:6px 4px;border-bottom:1px solid #fef3c7">
              <div style="font-size:11px;font-weight:700;color:${col}">${label}</div>
              <input class="form-control chauff-r2-prix" type="number" step="0.01"
                id="f_chauff_${key}_prix" data-key="${key}"
                value="${dc[key+'_prix']||''}" oninput="calcChauffage()"
                style="font-size:12px" placeholder="—">
              ${hasRevision
                ? `<input class="form-control chauff-r2-coeff" type="number" step="0.000001" id="f_chauff_${key}_coeff" data-key="${key}" value="${dc[key+'_coeff']||''}" oninput="calcChauffage()" style="font-size:12px;border-color:#bfdbfe" placeholder="ex: 1.229">`
                : '<div style="font-size:11px;color:var(--gray-text);text-align:center">—</div>'}
              <input class="form-control" type="number" step="0.01"
                id="f_chauff_${key}_px_revise"
                style="font-size:12px;background:#f0fdf4;color:#15803d;font-weight:600" readonly placeholder="auto">
              <input class="form-control" type="number" step="0.01"
                id="f_chauff_${key}_ht" value="${dc[key+'_ht']||''}"
                style="font-size:12px;background:#fffbeb;font-weight:700" readonly>
            </div>`).join('')}
          </div>

          <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:8px">
            <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;padding:8px;text-align:center">
              <div style="font-size:10px;color:var(--gray-text)">R1 HT</div>
              <div style="font-size:14px;font-weight:700;color:#dc2626" id="chauff_total_r1">—</div>
            </div>
            <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:6px;padding:8px;text-align:center">
              <div style="font-size:10px;color:var(--gray-text)">R2 HT</div>
              <div style="font-size:14px;font-weight:700;color:#ea580c" id="chauff_total_r2">—</div>
            </div>
            <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:8px;text-align:center">
              <div style="font-size:10px;color:var(--gray-text)">Total HT</div>
              <div style="font-size:14px;font-weight:700;color:#15803d" id="chauff_total_ht">—</div>
            </div>
            <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;padding:8px;text-align:center">
              <div style="font-size:10px;color:var(--gray-text)">Total TTC</div>
              <div style="font-size:16px;font-weight:800;color:var(--blue)" id="chauff_total_ttc">—</div>
            </div>
          </div>
        </div>
      </div>`;
      })() : ''}

      <!-- ═══ BLOC AVANCÉ : Détail par plage tarifaire ═══ -->
      <div id="blocAdvancedReleve" style="display:${isAdvancedMode?'block':'none'};margin-top:12px">
        <div style="background:#fdf4ff;border:1px solid #e9d5ff;border-radius:8px;padding:12px 16px">
          <div style="font-size:11px;font-weight:700;color:#7c3aed;margin-bottom:10px">⚡ Détail par plage tarifaire — ${optionTarifaire}${Object.keys(plageIdxPrefill).length > 0 ? ' <span style="font-size:10px;color:var(--gray-text)">(index début pré-rempli depuis le dernier relevé)</span>' : ''}</div>
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:8px;margin-bottom:10px">
            ${_getPlagesForOption(optionTarifaire).map(([key,label,col]) => {
              const pxContrat = contratPrix[key] || 0;
              const prefillStart = plageIdxPrefill[key] || '';
              return `
              <div style="background:white;border:1px solid #e5e7eb;border-radius:6px;padding:8px">
                <div style="font-size:10px;font-weight:700;color:${col};margin-bottom:6px">${label}${pxContrat > 0 ? ` <span style="font-weight:400;color:var(--gray-text)">(${pxContrat} €/${cfg.unite})</span>` : ''}</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px">
                  <div>
                    <div style="font-size:10px;color:var(--gray-text)">Index début</div>
                    <input class="form-control det-plage-input" type="number" step="0.01" id="f_det_${key}_idxStart"
                      value="${plageValues[key+'_idxStart'] || prefillStart}" oninput="calcDetailPlages()" style="font-size:12px">
                  </div>
                  <div>
                    <div style="font-size:10px;color:var(--gray-text)">Index fin</div>
                    <input class="form-control det-plage-input" type="number" step="0.01" id="f_det_${key}_idxEnd"
                      value="${plageValues[key+'_idxEnd']||''}" oninput="calcDetailPlages()" style="font-size:12px">
                  </div>
                  <div>
                    <div style="font-size:10px;color:var(--gray-text)">Conso (${cfg.unite})</div>
                    <input class="form-control" type="number" step="0.01" id="f_det_${key}_conso"
                      value="${plageValues[key+'_conso']||''}" style="font-size:12px;background:var(--gray-bg)" readonly>
                  </div>
                  <div>
                    <div style="font-size:10px;color:var(--gray-text)">Montant HT (€)</div>
                    <input class="form-control det-plage-input" type="number" step="0.01" id="f_det_${key}_ht"
                      value="${plageValues[key+'_ht']||''}" oninput="this.dataset._autoFilled='0';calcDetailPlages()" style="font-size:12px"
                      data-prix-contrat="${pxContrat}">
                  </div>
                </div>
              </div>`;
            }).join('')}
            ${optionTarifaire === 'HTA 5 plages' ? [
              ['cee',      'CEE (contrib.)',   '#0891b2'],
              ['oblicapa', 'Oblig. Capacité',  '#b45309'],
            ].map(([key, label, col]) => {
              const saved = plageValues['charge_' + key];
              const fromContrat = (() => {
                if (!contratLie) return 0;
                const map = { cee: 'CEE', oblicapa: 'ObligCapa' };
                return parseFloat(contratLie[map[key]]) || 0;
              })();
              return `
              <div style="background:white;border:1px solid #e5e7eb;border-radius:6px;padding:8px">
                <div style="font-size:10px;font-weight:700;color:${col};margin-bottom:6px">${label}${fromContrat > 0 ? ` <span style="font-weight:400;color:var(--gray-text)">(≈ ${fromContrat} €)</span>` : ''}</div>
                <div style="display:grid;grid-template-columns:1fr;gap:4px">
                  <div>
                    <div style="font-size:10px;color:var(--gray-text)">Montant HT (€)</div>
                    <input class="form-control det-charge-input" type="number" step="0.01" id="f_charge_${key}"
                      value="${saved ?? (isNew && fromContrat > 0 ? fromContrat : '')}"
                      oninput="calcDetailPlages()" style="font-size:12px" placeholder="€ HT">
                  </div>
                </div>
              </div>`;
            }).join('') : ''}
          </div>
          <!-- ── Charges & taxes complémentaires (hors HTA 5 plages) ── -->
          ${optionTarifaire !== 'HTA 5 plages' ? `
          <div style="margin-top:12px;border-top:1px dashed #e9d5ff;padding-top:10px">
            <div style="font-size:10px;font-weight:700;color:#7c3aed;margin-bottom:8px">🧾 Charges & taxes complémentaires</div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:6px">
              ${[
                ['abo',      'Abonnement HT',           '#6366f1'],
                ['cee',      'CEE (contrib.)',           '#0891b2'],
                ['ticfe',    'TICFE / CSPE',             '#0f766e'],
                ['oblicapa', 'Oblig. Capacité',          '#b45309'],
                ['cta',      'CTA',                      '#7c3aed'],
                ['turpe',    'TURPE / Acheminement',     '#be185d'],
              ].map(([key, label, col]) => {
                const saved = plageValues['charge_' + key];
                const fromContrat = (() => {
                  if (!contratLie) return 0;
                  const map = { abo: 'AbonnementHT', cee: 'CEE', ticfe: 'TICFE', oblicapa: 'ObligCapa', cta: 'CTA', turpe: 'TURPE' };
                  return parseFloat(contratLie[map[key]]) || 0;
                })();
                return `<div style="background:white;border:1px solid #e5e7eb;border-radius:6px;padding:8px;display:flex;align-items:center;gap:8px">
                  <div style="flex:1">
                    <div style="font-size:10px;font-weight:700;color:${col};margin-bottom:3px">${label}
                      ${fromContrat > 0 ? `<span style="font-weight:400;color:var(--gray-text)">(≈ ${fromContrat} €)</span>` : ''}
                    </div>
                    <input class="form-control det-charge-input" type="number" step="0.01" id="f_charge_${key}"
                      value="${saved ?? (isNew && fromContrat > 0 ? fromContrat : '')}"
                      oninput="calcDetailPlages()" style="font-size:12px" placeholder="€ HT">
                  </div>
                </div>`;
              }).join('')}
            </div>
          </div>` : ''}

          <!-- Totaux -->
          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-top:8px">
            <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:8px;display:flex;flex-direction:column;justify-content:center">
              <div style="font-size:10px;color:var(--gray-text)">Total conso plages</div>
              <div style="font-size:14px;font-weight:700;color:var(--blue)" id="det_total_conso">—</div>
            </div>
            <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:8px;display:flex;flex-direction:column;justify-content:center">
              <div style="font-size:10px;color:var(--gray-text)">Énergie HT</div>
              <div style="font-size:14px;font-weight:700;color:#15803d" id="det_total_energie_ht">—</div>
            </div>
            <div style="background:#eff8ff;border:1px solid #bfdbfe;border-radius:6px;padding:8px;display:flex;flex-direction:column;justify-content:center">
              <div style="font-size:10px;color:var(--gray-text)">Total HT (énergie + charges)</div>
              <div style="font-size:14px;font-weight:700;color:var(--blue)" id="det_total_ht">—</div>
            </div>
          </div>
        </div>
      </div>
    </div>

    ${_renderBlocLignesFacture(isLignesVisible, isIndefini, detailIndefini)}

    <div class="form-group" style="grid-column:span 2">
      <label class="form-label">Commentaire</label>
      <textarea class="form-control" id="f_commentaire" rows="2">${escHtml(r.Commentaire||'')}</textarea>
    </div>

    <div class="form-group" style="grid-column:span 2">
      <label class="form-label">📄 Joindre la facture (PDF)</label>
      <div style="display:flex;gap:8px;align-items:center">
        <input type="file" id="f_facturePdf" accept="application/pdf,.pdf"
          style="font-size:12px;flex:1;padding:6px;border:1px dashed var(--gray-border);border-radius:8px;background:var(--gray-bg)">
        ${r.Id ? `<span id="f_facturePdfExisting" style="font-size:11px;color:var(--gray-text)"></span>` : ''}
      </div>
      <div style="font-size:10px;color:var(--gray-text);margin-top:4px">Max 25 Mo. Le PDF sera rattaché au relevé/facture.</div>
    </div>

  </div>`, async () => {
    // Multi-compteur : recalcul + détail
    let detailSousCompteursPayload = null;
    if (_isMultiCompteurReleveActive()) {
      const res = _collectSousCompteursDetail();
      if (!res.ok) { toast(res.error || 'Sous-compteurs : données invalides.', 'error'); return; }
      detailSousCompteursPayload = res.json;
      _applySousCompteursTotals(res.totals);
    }

    const conso = parseFloat(gv('f_consommation'));
    if (!gv('f_date') || isNaN(conso)) { toast('Date et consommation obligatoires.', 'error'); return; }

    // Build detail HTA if advanced mode
    const advancedVisible = document.getElementById('blocAdvancedReleve')?.style.display !== 'none';
    const lignesVisible = document.getElementById('blocLignesFacture')?.style.display !== 'none';
    let modeFacture, detailHTAPayload;

    if (lignesVisible) {
      // Collecte des lignes de détail (tous types de contrats)
      const consoFact = parseFloat(document.getElementById('f_consoFacture')?.value) || null;
      const lignes = [];
      document.querySelectorAll('#lignesFactureContainer .lf-row').forEach(function(row) {
        const label       = row.querySelector('.lf-label')?.value?.trim() || '';
        const quantite    = parseFloat(row.querySelector('.lf-quantite')?.value) || null;
        const unite       = row.querySelector('.lf-unite')?.value?.trim() || '';
        const prixUnitaire = parseFloat(row.querySelector('.lf-pu')?.value) || null;
        const valeur      = parseFloat(row.querySelector('.lf-montant')?.value) || null;
        if (label || valeur !== null) lignes.push({ label, quantite, unite, prixUnitaire, valeur });
      });
      const hasLignes = lignes.length > 0 || consoFact !== null;
      detailHTAPayload = hasLignes ? JSON.stringify({ consoFacture: consoFact, lignes }) : null;
      if (document.getElementById('f_consoFacture') !== null) {
        // Contrat indéfini
        modeFacture = gv('f_typeSaisie') === 'Facture' ? 'indefini_avance' : null;
      } else {
        modeFacture = gv('f_typeSaisie') === 'Facture' ? 'facture_lignes' : null;
      }
    } else if (document.getElementById('f_consoFacture') !== null) {
      // Contrat indéfini sans lignes visibles
      const consoFact = parseFloat(document.getElementById('f_consoFacture')?.value) || null;
      detailHTAPayload = consoFact !== null ? JSON.stringify({ consoFacture: consoFact, lignes: [] }) : null;
      modeFacture = gv('f_typeSaisie') === 'Facture' ? 'indefini_simple' : null;
    } else {
      // Mode standard (simple ou plages)
      modeFacture = advancedVisible ? 'detail' : 'simple';
      detailHTAPayload = null;
      if (advancedVisible) {
        var dHTA = {};
        const plages = _getPlagesForOption(optionTarifaire);
        plages.forEach(function(pArr) {
          var k = pArr[0];
          ['idxStart','idxEnd','conso','ht'].forEach(function(f) {
            const v = parseFloat(document.getElementById('f_det_'+k+'_'+f)?.value);
            if (!isNaN(v) && v) dHTA[k+'_'+f] = v;
          });
        });
        ['abo','cee','ticfe','oblicapa','cta','turpe'].forEach(function(k) {
          const v = parseFloat(document.getElementById('f_charge_'+k)?.value);
          if (!isNaN(v) && v) dHTA['charge_'+k] = v;
        });
        detailHTAPayload = Object.keys(dHTA).length ? JSON.stringify(dHTA) : null;
      }
      modeFacture = gv('f_typeSaisie') === 'Facture' ? modeFacture : null;
    }

    // Build detail Chauffage if applicable
    let detailChauffagePayload = null;
    const blocChauff = document.getElementById('blocChauffageFacture');
    if (blocChauff) {
      const dc = {};
      dc.puissance = parseFloat(document.getElementById('f_chauff_puissance')?.value) || null;
      dc.tva       = parseFloat(document.getElementById('f_chauff_tva')?.value) || 5.5;
      dc.tva2      = parseFloat(document.getElementById('f_chauff_tva2')?.value) || 20;
      dc.r1_prix   = parseFloat(document.getElementById('f_chauff_r1_prix')?.value) || null;
      dc.r1_conso  = parseFloat(document.getElementById('f_chauff_r1_conso')?.value) || null;
      dc.r1_ht     = parseFloat(document.getElementById('f_chauff_r1_ht')?.value) || null;
      ['r21','r22','r23','r24','r2s','r2co2'].forEach(k => {
        const prix  = parseFloat(document.getElementById('f_chauff_'+k+'_prix')?.value);
        const ht    = parseFloat(document.getElementById('f_chauff_'+k+'_ht')?.value);
        const coeffEl = document.getElementById('f_chauff_'+k+'_coeff');
        const coeff = coeffEl ? parseFloat(coeffEl.value) : NaN;
        if (!isNaN(prix))  dc[k+'_prix']  = prix;
        if (!isNaN(ht))    dc[k+'_ht']    = ht;
        if (!isNaN(coeff)) dc[k+'_coeff'] = coeff;
      });
      detailChauffagePayload = JSON.stringify(dc);
    }

    const payload = {
      compteurId: parseInt(gv('f_compteurId')),
      type: gv('f_typeSaisie'),
      date: gv('f_date'),
      indexDebut: parseFloat(gv('f_indexDebut'))||null,
      indexFin:   parseFloat(gv('f_indexFin'))||null,
      consommation: conso,
      montant: parseFloat(gv('f_montant'))||null,
      montantHT: parseFloat(gv('f_montantHT'))||null,
      prixUnitHT: null,
      tva: parseFloat(gv('f_tvaReleve'))||null,
      tvaReleve: parseFloat(gv('f_tvaReleve'))||null,
      carbone: parseFloat(gv('f_carbone'))||null,
      numeroFacture: gv('f_numeroFacture'),
      periodeDebut: gv('f_periodeDebut'),
      periodeFin: gv('f_periodeFin'),
      commentaire: gv('f_commentaire'),
      modeFacture: modeFacture,
      detailHTA: detailHTAPayload || null,
      detailChauffage: detailChauffagePayload || null,
      detailSousCompteurs: detailSousCompteursPayload,
    };
    try {
      let savedId = id;
      if (isNew) {
        const result = await EnergieApi.addReleve(payload);
        savedId = result?.id || result;
      } else {
        await EnergieApi.updateReleve(id, payload);
      }

      // Upload PDF facture si sélectionné
      const pdfInput = document.getElementById('f_facturePdf');
      if (pdfInput?.files?.length > 0) {
        const file = pdfInput.files[0];
        if (file.size > 25 * 1024 * 1024) {
          toast('Fichier trop volumineux (max 25 Mo).', 'error');
        } else if (file.type !== 'application/pdf') {
          toast('Seuls les fichiers PDF sont acceptés.', 'error');
        } else {
          try {
            // Convertir en base64 pour l'API existante
            const base64 = await new Promise((resolve, reject) => {
              const reader = new FileReader();
              reader.onload = () => resolve(reader.result.split(',')[1]);
              reader.onerror = () => reject(new Error('Lecture fichier échouée'));
              reader.readAsDataURL(file);
            });
            const uploadRes = await apiRequest('documents&type=ReleverEnergie&eid=' + savedId, 'POST', {
              nom: file.name,
              mime: 'application/pdf',
              categorie: 'Facture',
              data: base64,
            });
            if (!uploadRes?.id) toast('PDF sauvé, mais erreur pièce jointe.', 'error');
          } catch(ue) { toast('Erreur upload PDF : ' + ue.message, 'error'); }
        }
      }

      toast(isNew ? 'Saisie enregistrée.' : 'Saisie modifiée.', 'success');
      closeModal(); renderEnergie();
    } catch(e) { toast(e.message, 'error'); }
  }, 'Enregistrer', 'Annuler', _isChauffage ? '960px' : null);

  // Init
  setTimeout(() => {
    toggleReleveFields();
    _initReleveTVA();
    _setupSousCompteursReleve(parseInt(document.getElementById('f_compteurId')?.value), detailSousCompteursCurrent, dernierDetailSous);
    if (isAdvancedMode) calcDetailPlages();
    if (isLignesVisible) calcLignesFacture();
    calcCarboneElec();
    if (_isChauffage) calcChauffage();
    // Afficher PDF existant si édition
    if (!isNew && r.Id) {
      apiRequest('documents&type=ReleverEnergie&eid=' + r.Id).then(docs => {
        const pdfs = (docs||[]).filter(d => d.Categorie === 'Facture');
        const el = document.getElementById('f_facturePdfExisting');
        if (el && pdfs.length > 0) {
          el.innerHTML = pdfs.map(d =>
            `<a href="api/index.php?action=document_download&id=${d.Id}" download="${d.NomFichier}" style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;background:var(--blue-pale);color:var(--blue);border-radius:6px;font-size:11px;text-decoration:none;margin:2px">📄 ${d.NomFichier}</a>`
          ).join('');
        }
      }).catch(() => {});
    }
  }, 50);
}

// ── Plages tarifaires selon l'option (sans Abonnement, CEE, Oblig. Capa) ────
function _getPlagesForOption(option) {
  if (option === 'HTA 5 plages') {
    return [
      ['hphs','🌞 HPHS — Hiver HP','#b45309'],
      ['hchs','🌙 HCHS — Hiver HC','#0369a1'],
      ['hpbs','☀️ HPBS — Été HP','#15803d'],
      ['hcbs','💤 HCBS — Été HC','#5b21b6'],
    ];
  }
  if (['HP-HC','Tempo','EJP'].includes(option)) {
    return [
      ['hp','🌞 Heures Pleines','#b45309'],
      ['hc','🌙 Heures Creuses','#0369a1'],
    ];
  }
  return [];
}

// ── TVA auto depuis le contrat lié ────────────────────────────────────────────
async function _initReleveTVA() {
  const compteurId = parseInt(document.getElementById('f_compteurId')?.value);
  if (!compteurId) return;
  try {
    const tousCompteurs = await EnergieApi.getCompteurs();
    const cpt = tousCompteurs.find(x => x.Id === compteurId);
    if (!cpt?.ContratId) return;
    const contrats = await ContratsApi.getAll();
    const ct = contrats.find(x => x.Id === parseInt(cpt.ContratId));
    if (!ct) return;
    let tva = 20;
    if (ct.Fluide === 'Eau') tva = 5.5;
    else if (ct.TVAKwh) tva = parseFloat(ct.TVAKwh);
    else if (ct.TVARate) tva = parseFloat(ct.TVARate);
    const elTVA = document.getElementById('f_tvaReleve');
    if (elTVA) elTVA.value = tva;
    calcTTCFromHT();
  } catch(e) {}
}

// ── Calcul TTC depuis HT ─────────────────────────────────────────────────────
function calcTTCFromHT() {
  const ht  = parseFloat(document.getElementById('f_montantHT')?.value);
  const tva = parseFloat(document.getElementById('f_tvaReleve')?.value) || 20;
  if (!isNaN(ht) && ht > 0) {
    const ttc = ht * (1 + tva / 100);
    const elTTC = document.getElementById('f_montant');
    if (elTTC) elTTC.value = ttc.toFixed(2);
  }
}

// ── Calcul BIDIRECTIONNEL : Index → Conso et Conso → Index ──────────────────

// Quand on modifie index début ou index fin → recalculer conso
async function calcFromIndex() {
  const fin   = parseFloat(document.getElementById('f_indexFin')?.value);
  const debut = parseFloat(document.getElementById('f_indexDebut')?.value);
  if (isNaN(fin) || isNaN(debut) || fin < debut) return;

  const conso = fin - debut;
  const elConso = document.getElementById('f_consommation');
  if (elConso) elConso.value = conso.toFixed(2);

  _autoCalcMontantHT(conso);
  _autoCalcCarboneChauffage(conso);
  calcCarboneElec();
}

// Quand on modifie la conso → recalculer index fin
async function calcFromConso() {
  const debut = parseFloat(document.getElementById('f_indexDebut')?.value);
  const conso = parseFloat(document.getElementById('f_consommation')?.value);
  if (!isNaN(debut) && !isNaN(conso) && conso >= 0) {
    const elFin = document.getElementById('f_indexFin');
    if (elFin) elFin.value = (debut + conso).toFixed(2);
  }

  if (!isNaN(conso) && conso > 0) {
    _autoCalcMontantHT(conso);
    _autoCalcCarboneChauffage(conso);
    calcCarboneElec();
  }
}

// ── Calcul auto CO₂ Chauffage (relevé manuel) ─────────────────────────────────
// Utilise le facteur d'émission stocké pour l'année du relevé
async function _autoCalcCarboneChauffage(consoMWh) {
  if (EnergieState.onglet !== 'Chauffage') return;
  const elCarbone = document.getElementById('f_carbone');
  if (!elCarbone) return;
  if (!isNaN(consoMWh) && consoMWh > 0) {
    // Chercher le coeff CO₂ spécifique du compteur (réseau de chaleur)
    var coeffCompteur = _getCompteurCoeffCO2();
    if (coeffCompteur > 0) {
      // Utiliser le coeff du réseau de chaleur : MWh × coeff(kgCO₂/kWh) × 1000
      elCarbone.value = (consoMWh * coeffCompteur * 1000).toFixed(2);
      return;
    }
    const dateSaisie = document.getElementById('f_date')?.value;
    const annee = dateSaisie ? parseInt(dateSaisie.substring(0, 4)) : new Date().getFullYear();
    const detail = await calcCarboneDetaille('Chauffage', consoMWh, annee);
    elCarbone.value = detail ? detail.co2.toFixed(2) : (consoMWh * (ADEME_FACTEURS.Chauffage?.facteurCO2 || 0.112) * 1000).toFixed(2);
    _majMentionCarbone(detail);
  }
}

// Récupérer le coeff CO₂ du compteur chauffage sélectionné (depuis ChauffageTED JSON)
function _getCompteurCoeffCO2() {
  try {
    var compteurId = parseInt(document.getElementById('f_compteurId')?.value);
    if (!compteurId || !window._energieCompteurs) return 0;
    var cpt = window._energieCompteurs.find(function(c) { return c.Id === compteurId; });
    if (!cpt || !cpt.ChauffageTED) return 0;
    var ted = JSON.parse(cpt.ChauffageTED);
    return parseFloat(ted.coeffCO2) || 0;
  } catch(e) { return 0; }
}

// Calcul auto du montant HT depuis la conso et le prix du contrat (sans abonnement)
async function _autoCalcMontantHT(conso) {
  const compteurId = parseInt(document.getElementById('f_compteurId')?.value);
  if (!compteurId) return;

  try {
    const [tousCompteurs, contrats] = await Promise.all([
      EnergieApi.getCompteurs(), ContratsApi.getAll(),
    ]);
    const cpt = tousCompteurs.find(x => x.Id === compteurId);
    if (!cpt) return;

    let prixBase = parseFloat(cpt.PrixHT) || 0;
    let contrat = null;
    if (cpt.ContratId) {
      contrat = contrats.find(x => x.Id === parseInt(cpt.ContratId));
    }

    if (!prixBase && contrat) {
      // Eau : utiliser EauPrixM3HT
      if (contrat.Fluide === 'Eau') {
        prixBase = parseFloat(contrat.EauPrixM3HT) || 0;
      } else {
        prixBase = parseFloat(contrat.PrixBaseHT) || 0;
      }
    }

    if (prixBase > 0) {
      const montantHT = conso * prixBase;
      const elHT = document.getElementById('f_montantHT');
      if (elHT) {
        elHT.value = montantHT.toFixed(2);
        elHT.title = `${conso.toFixed(2)} × ${prixBase} €/u`;
      }
      calcTTCFromHT();

      // Eau : afficher aussi l'estimation détaillée (conso + abonnement)
      if (contrat?.Fluide === 'Eau') {
        _updateEauEstimation(conso, contrat);
      }
    }
  } catch(e) { console.error('_autoCalcMontantHT:', e); }
}


 // Plus de calcul auto carbone

// ── Toggle simple / avancée ───────────────────────────────────────────────────
function toggleAdvancedReleve() {
  const bloc = document.getElementById('blocAdvancedReleve');
  const btn  = document.getElementById('btnToggleAdvanced');
  if (!bloc) return;
  const show = bloc.style.display === 'none';
  bloc.style.display = show ? 'block' : 'none';
  if (btn) btn.textContent = show ? '⚡ Version simple' : '⚡ Détail par plage';
  if (show) calcDetailPlages();
}

// ── Calcul détail plages (avec prix contrat auto) ────────────────────────────
function calcDetailPlages() {
  const plages = document.querySelectorAll('[id^="f_det_"][id$="_idxStart"]');
  let totalConso = 0, totalEnergieHT = 0;
  const unite = ENERGIE_CONFIG[EnergieState.onglet]?.unite || 'kWh';

  plages.forEach(el => {
    const key = el.id.replace('f_det_','').replace('_idxStart','');
    const start = parseFloat(el.value);
    const end   = parseFloat(document.getElementById('f_det_'+key+'_idxEnd')?.value);
    const elConso = document.getElementById('f_det_'+key+'_conso');
    const elHT    = document.getElementById('f_det_'+key+'_ht');
    if (!isNaN(start) && !isNaN(end) && end >= start && elConso) {
      const c = end - start;
      elConso.value = c.toFixed(2);
      totalConso += c;

      // Auto-calc HT si prix contrat dispo et champ HT vide ou non touché
      const pxContrat = parseFloat(elHT?.dataset?.prixContrat) || 0;
      if (pxContrat > 0 && elHT) {
        if (!elHT.value || elHT.dataset._autoFilled === '1') {
          elHT.value = (c * pxContrat).toFixed(2);
          elHT.dataset._autoFilled = '1';
        }
      }
    }
    totalEnergieHT += parseFloat(document.getElementById('f_det_'+key+'_ht')?.value) || 0;
  });

  // Additionner les charges complémentaires
  let totalChargesHT = 0;
  document.querySelectorAll('.det-charge-input').forEach(el => {
    totalChargesHT += parseFloat(el.value) || 0;
  });

  const totalHT = totalEnergieHT + totalChargesHT;

  const fmtNum = v => new Intl.NumberFormat('fr-FR',{maximumFractionDigits:1}).format(v);
  const fmtMon = v => new Intl.NumberFormat('fr-FR',{style:'currency',currency:'EUR'}).format(v);

  const elC  = document.getElementById('det_total_conso');
  const elEH = document.getElementById('det_total_energie_ht');
  const elH  = document.getElementById('det_total_ht');
  if (elC)  elC.textContent  = totalConso      > 0 ? fmtNum(totalConso) + ' ' + unite : '—';
  if (elEH) elEH.textContent = totalEnergieHT  > 0 ? fmtMon(totalEnergieHT) : '—';
  if (elH)  elH.textContent  = totalHT         > 0 ? fmtMon(totalHT) : '—';

  // Sync with main fields
  if (totalConso > 0) {
    const elMainConso = document.getElementById('f_consommation');
    if (elMainConso) elMainConso.value = totalConso.toFixed(2);
  }
  if (totalHT > 0) {
    const elMainHT = document.getElementById('f_montantHT');
    if (elMainHT) elMainHT.value = totalHT.toFixed(2);
    calcTTCFromHT();
  }
}


function toggleReleveFields() {
  const t    = document.getElementById('f_typeSaisie')?.value;
  const bloc = document.getElementById('blocFacture');
  if (!bloc) return;
  bloc.style.display = t === 'Facture' ? 'block' : 'none';

  // Multi-compteur : uniquement en mode Relevé manuel
  const blocSous = document.getElementById('blocSousCompteursReleve');
  if (blocSous) {
    if (t === 'Facture') {
      blocSous.style.display = 'none';
    } else {
      // Re-afficher si multi-compteur actif
      if (blocSous.dataset.multi === '1') blocSous.style.display = 'block';
    }
  }

  // Recalculer l'index précédent selon le type de saisie choisi
  recalcIndexPrecedent();
}

async function onCompteurChangeReleve() {
  const compteurId = parseInt(document.getElementById('f_compteurId')?.value);
  if (!compteurId) return;
  let lastSous = [];
  try {
    const releves = await EnergieApi.getRelevers(compteurId);
    if (releves.length) {
      // Index début : pré-remplir avec l'index fin du dernier relevé AVANT la date saisie
      const dateSaisie = document.getElementById('f_date')?.value || new Date().toISOString().split('T')[0];
      const typeSaisie = document.getElementById('f_typeSaisie')?.value || 'Releve';
      const relevesAvant = _filtrerRelevesAvantDate(releves, dateSaisie, typeSaisie);
      const el = document.getElementById('f_indexDebut');
      if (el && relevesAvant.length) {
        el.value = relevesAvant[0].IndexFin || '';
        _updateDernierReleveInfo(relevesAvant[0]);
      } else if (el) {
        el.value = '';
        _updateDernierReleveInfo(null);
      }
      // Récupérer le dernier DetailSousCompteurs disponible (pas forcément le 1er relevé)
      const avecSous = releves.find(r => r.DetailSousCompteurs);
      if (avecSous?.DetailSousCompteurs) {
        try { lastSous = JSON.parse(avecSous.DetailSousCompteurs); } catch(e) {}
      }
    }
  } catch(e) {}
  _releveEditState.compteurId = compteurId;
  await _setupSousCompteursReleve(compteurId, null, lastSous);
  _initReleveTVA();
}

async function deleteReleve(id, compteurId, type) {
  showConfirm('Supprimer cette saisie ?', async () => {
    try { await EnergieApi.deleteReleve(id); toast('Saisie supprimée.'); renderEnergie(); }
    catch(e) { toast(e.message, 'error'); }
  });
}

// ── Filtrer les relevés AVANT une date donnée ET du même type de saisie ──────
function _filtrerRelevesAvantDate(releves, dateSaisie, typeSaisie) {
  if (!releves.length) return releves;
  let filtered = releves;
  // Filtrer par date : uniquement les relevés strictement avant la date saisie
  if (dateSaisie) {
    filtered = filtered.filter(r => {
      if (!r.Date) return false;
      return r.Date.substring(0, 10) < dateSaisie.substring(0, 10);
    });
  }
  // Filtrer par type de saisie (Releve ou Facture) si spécifié
  if (typeSaisie) {
    const filteredByType = filtered.filter(r => r.Type === typeSaisie);
    // S'il y a des résultats filtrés par type, on les utilise
    // Sinon, on garde tous les relevés avant la date (fallback)
    if (filteredByType.length) {
      filtered = filteredByType;
    }
  }
  return filtered;
}

// ── Recalcul dynamique de l'index précédent quand la date ou le type change ──
async function recalcIndexPrecedent() {
  const compteurId = parseInt(document.getElementById('f_compteurId')?.value);
  const dateSaisie = document.getElementById('f_date')?.value;
  const typeSaisie = document.getElementById('f_typeSaisie')?.value;
  if (!compteurId || !dateSaisie) return;

  try {
    const releves = await EnergieApi.getRelevers(compteurId);
    // Exclure le relevé en cours de modification
    const { id, isNew } = _releveEditState;
    const relevesAutres = isNew ? releves : releves.filter(x => x.Id !== id);
    // Filtrer : avant la date saisie + même type de saisie
    const relevesFiltres = _filtrerRelevesAvantDate(relevesAutres, dateSaisie, typeSaisie);

    const el = document.getElementById('f_indexDebut');
    if (relevesFiltres.length) {
      const dernier = relevesFiltres[0];
      if (el) el.value = dernier.IndexFin ?? '';
      _updateDernierReleveInfo(dernier);
      // Recalculer la consommation si index fin est renseigné
      calcFromIndex();
    } else {
      // Aucun relevé du même type avant cette date — chercher tous types confondus
      const tousAvant = _filtrerRelevesAvantDate(relevesAutres, dateSaisie, null);
      if (tousAvant.length) {
        const dernier = tousAvant[0];
        if (el) el.value = dernier.IndexFin ?? '';
        _updateDernierReleveInfo(dernier);
        calcFromIndex();
      } else {
        if (el) el.value = '';
        _updateDernierReleveInfo(null);
      }
    }
  } catch(e) { console.error('recalcIndexPrecedent:', e); }
}

// ── Mise à jour du badge d'info "Dernier relevé" dans la modale ──────────────
function _updateDernierReleveInfo(dernier) {
  const cfg = ENERGIE_CONFIG[_releveEditState.type || EnergieState.onglet] || ENERGIE_CONFIG.Electricite;
  const fmtDateFR = d => d ? new Date(d).toLocaleDateString('fr-FR') : '—';
  // Trouver le conteneur du badge — c'est le span dans le section-label "Index & Consommation"
  const sectionLabels = document.querySelectorAll('.section-label');
  let badgeContainer = null;
  for (const sl of sectionLabels) {
    if (sl.textContent.includes('Index') && sl.textContent.includes('Consommation')) {
      badgeContainer = sl;
      break;
    }
  }
  if (!badgeContainer) return;
  // Supprimer l'ancien badge s'il existe
  const oldBadge = badgeContainer.querySelector('.dernier-releve-badge');
  if (oldBadge) oldBadge.remove();
  // Créer le nouveau badge
  if (dernier) {
    const badge = document.createElement('span');
    badge.className = 'dernier-releve-badge';
    badge.style.cssText = 'font-size:11px;color:var(--gray-text);background:#f0f9ff;padding:3px 10px;border-radius:20px';
    badge.innerHTML = `📌 Dernier ${dernier.Type === 'Facture' ? '🧾 Facture' : '📊 Relevé'} : <strong>${fmtDateFR(dernier.Date)}</strong> — Index : <strong>${dernier.IndexFin ?? '—'}</strong> — Conso : <strong>${dernier.Consommation ?? '—'} ${cfg.unite}</strong>`;
    badgeContainer.appendChild(badge);
  }
}

// ── Calcul automatique Chauffage Urbain ──────────────────────────────────────
function calcChauffage() {
  const fmtMon = v => new Intl.NumberFormat('fr-FR',{style:'currency',currency:'EUR'}).format(v||0);

  const puissance = parseFloat(document.getElementById('f_chauff_puissance')?.value) || 0;
  const tva1      = parseFloat(document.getElementById('f_chauff_tva')?.value) || 5.5;
  const tva2      = parseFloat(document.getElementById('f_chauff_tva2')?.value) || 20;

  // R1 : prix × conso
  const r1Prix  = parseFloat(document.getElementById('f_chauff_r1_prix')?.value) || 0;
  const r1Conso = parseFloat(document.getElementById('f_chauff_r1_conso')?.value) || 0;
  const r1HT    = r1Prix > 0 && r1Conso > 0 ? r1Prix * r1Conso : 0;
  const elR1HT  = document.getElementById('f_chauff_r1_ht');
  if (elR1HT) elR1HT.value = r1HT > 0 ? r1HT.toFixed(2) : '';

  // R2 : prix_révisé = prix_base × coeff, montant HT = prix_révisé × puissance / 12
  // R2.1/R2.2/R2.3 → TVA1 (prestations)
  // R2.4/R2S/R2CO2 → TVA2 (investissement)
  let totalR2HT_tva1 = 0; // R2.1, R2.2, R2.3
  let totalR2HT_tva2 = 0; // R2.4, R2S, R2CO2
  const tva2Keys = ['r24','r2s','r2co2'];
  ['r21','r22','r23','r24','r2s','r2co2'].forEach(key => {
    const prixBase = parseFloat(document.getElementById('f_chauff_'+key+'_prix')?.value);
    const elCoeff  = document.getElementById('f_chauff_'+key+'_coeff');
    const elPxRev  = document.getElementById('f_chauff_'+key+'_px_revise');
    const elHT     = document.getElementById('f_chauff_'+key+'_ht');
    if (!isNaN(prixBase) && puissance > 0) {
      const coeff      = elCoeff ? (parseFloat(elCoeff.value) || 1) : 1;
      const prixRevise = prixBase * coeff;
      const ht         = (prixRevise * puissance) / 12;
      if (elPxRev) elPxRev.value = prixRevise.toFixed(4);
      if (elHT)    elHT.value    = ht.toFixed(2);
      if (tva2Keys.includes(key)) totalR2HT_tva2 += ht;
      else totalR2HT_tva1 += ht;
    } else {
      if (elPxRev) elPxRev.value = '';
      if (elHT)    elHT.value    = '';
    }
  });

  const totalR2HT = totalR2HT_tva1 + totalR2HT_tva2;
  const totalHT   = r1HT + totalR2HT;
  // R1 + R2.1-3 → TVA1, R2.4+S+CO2 → TVA2
  const blocTVA1  = (r1HT + totalR2HT_tva1) * (tva1 / 100);
  const blocTVA2  = totalR2HT_tva2 * (tva2 / 100);
  const totalTTC  = totalHT + blocTVA1 + blocTVA2;

  const elTR1  = document.getElementById('chauff_total_r1');
  const elTR2  = document.getElementById('chauff_total_r2');
  const elTHT  = document.getElementById('chauff_total_ht');
  const elTTTC = document.getElementById('chauff_total_ttc');
  if (elTR1)  elTR1.textContent  = r1HT      > 0 ? fmtMon(r1HT)      : '—';
  if (elTR2)  elTR2.textContent  = totalR2HT > 0 ? fmtMon(totalR2HT) : '—';
  if (elTHT)  elTHT.textContent  = totalHT   > 0 ? fmtMon(totalHT)   : '—';
  if (elTTTC) elTTTC.textContent = totalTTC  > 0 ? fmtMon(totalTTC)  : '—';

  // Sync champs principaux
  if (r1Conso > 0) {
    const elConso = document.getElementById('f_consommation');
    if (elConso) elConso.value = r1Conso.toFixed(3);
    // ── Calcul automatique du bilan carbone ──
    const consoMWh = r1Conso;
    var coeffCompteur = _getCompteurCoeffCO2();
    if (coeffCompteur > 0) {
      const elCarbone = document.getElementById('f_carbone');
      if (elCarbone) elCarbone.value = (consoMWh * coeffCompteur * 1000).toFixed(2);
    } else {
      const dateSaisie = document.getElementById('f_date')?.value;
      const anneeSaisie = dateSaisie ? parseInt(dateSaisie.substring(0, 4)) : new Date().getFullYear();
      calcCarboneDetaille('Chauffage', consoMWh, anneeSaisie).then(detail => {
        const elCarbone = document.getElementById('f_carbone');
        if (elCarbone) elCarbone.value = (detail ? detail.co2 : consoMWh * (ADEME_FACTEURS.Chauffage?.facteurCO2 || 0.112) * 1000).toFixed(2);
        _majMentionCarbone(detail);
      });
    }
  }
  if (totalHT > 0) {
    const elHT = document.getElementById('f_montantHT');
    if (elHT) elHT.value = totalHT.toFixed(2);
    // Stocker TVA moyenne pondérée pour le champ principal
    const tvaMoy = totalHT > 0 ? ((blocTVA1 + blocTVA2) / totalHT * 100) : tva1;
    const elTVA = document.getElementById('f_tvaReleve');
    if (elTVA) elTVA.value = tvaMoy.toFixed(2);
    // Montant TTC principal
    const elMontant = document.getElementById('f_montant');
    if (elMontant) elMontant.value = totalTTC.toFixed(2);
  }
}


// ── Multi-compteur : saisie de sous-compteurs ───────────────────────────────
function _isMultiCompteurReleveActive() {
  const bloc = document.getElementById('blocSousCompteursReleve');
  return bloc && bloc.style.display !== 'none' && bloc.dataset.multi === '1';
}

async function _setupSousCompteursReleve(compteurId, existingDetail, lastDetail) {
  const bloc = document.getElementById('blocSousCompteursReleve');
  if (!bloc || !compteurId) return;

  let cpt = null;
  try {
    const tous = await EnergieApi.getCompteurs();
    cpt = tous.find(x => x.Id === compteurId);
  } catch(e) {}

  let cfg = [];
  try {
    cfg = JSON.parse(cpt?.SousCompteurs || '[]');
    if (!Array.isArray(cfg)) cfg = [];
  } catch(e) { cfg = []; }

  const isMulti = cfg.length >= 2;
  bloc.dataset.multi = isMulti ? '1' : '0';

  const elDeb = document.getElementById('f_indexDebut');
  const elFin = document.getElementById('f_indexFin');
  const elCon = document.getElementById('f_consommation');

  if (!isMulti) {
    bloc.style.display = 'none';
    if (elDeb) { elDeb.readOnly = false; elDeb.style.background=''; elDeb.oninput = () => calcFromIndex(); }
    if (elFin) { elFin.readOnly = false; elFin.style.background=''; elFin.oninput = () => calcFromIndex(); }
    if (elCon) { elCon.readOnly = false; elCon.style.background=''; elCon.oninput = () => calcFromConso(); }
    return;
  }

  // Build prefill map from last detail (idxFin by numero, and by position as fallback)
  const prevByNumero = {};
  const prevByPos    = {};
  if (Array.isArray(lastDetail)) {
    lastDetail.forEach((x, idx) => {
      const key = (x?.numero || x?.Numero || '').toString().trim();
      const v = parseFloat(x?.idxFin ?? x?.IndexFin);
      if (!isNaN(v)) {
        if (key) prevByNumero[key] = v;
        prevByPos[idx] = v; // fallback par position
      }
    });
  }

  // existing detail for edit
  let use = Array.isArray(existingDetail) ? existingDetail : null;

  const rowsHtml = cfg.map((sc, i) => {
    const numero = sc?.numero || '';
    const nom = sc?.nom || '';
    let d0 = '';
    let d1 = '';
    if (use && use[i]) {
      d0 = (use[i].idxDebut ?? use[i].IndexDebut ?? '') + '';
      d1 = (use[i].idxFin   ?? use[i].IndexFin   ?? '') + '';
    } else {
      // Préfill index début = index fin du dernier relevé (par numéro, sinon par position)
      const numKey = numero.trim();
      if (numKey && prevByNumero[numKey] !== undefined) {
        d0 = prevByNumero[numKey] + '';
      } else if (prevByPos[i] !== undefined) {
        d0 = prevByPos[i] + '';
      }
    }

    return `
      <div class="sousreleve-row" data-idx="${i}" data-numero="${numero}" data-nom="${nom}" style="display:grid;grid-template-columns:1.2fr 1fr 1fr 0.9fr;gap:8px;align-items:end;padding:10px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px">
        <div>
          <div style="font-size:10px;color:var(--gray-text);margin-bottom:3px">Sous-compteur</div>
          <div style="font-size:12px;font-weight:700">${nom || '—'} <span style="font-weight:500;color:var(--gray-text)">${numero ? ' • N° '+numero : ''}</span></div>
        </div>
        <div>
          <div style="font-size:10px;color:var(--gray-text);margin-bottom:3px">Index début</div>
          <input class="form-control sc-debut" type="number" step="0.01" value="${d0}" oninput="_calcSousCompteursTotals()" style="font-size:12px">
        </div>
        <div>
          <div style="font-size:10px;color:var(--gray-text);margin-bottom:3px">Index fin</div>
          <input class="form-control sc-fin" type="number" step="0.01" value="${d1}" oninput="_calcSousCompteursTotals()" style="font-size:12px">
        </div>
        <div>
          <div style="font-size:10px;color:var(--gray-text);margin-bottom:3px">Conso</div>
          <input class="form-control sc-conso" type="number" step="0.01" value="" readonly style="font-size:12px;background:var(--gray-bg)">
        </div>
      </div>`;
  }).join('');

  // Multi-compteur : uniquement en mode Relevé
  const currentType = document.getElementById('f_typeSaisie')?.value;
  if (currentType === 'Facture') { bloc.style.display = 'none'; return; }

  bloc.style.display = 'block';
  bloc.innerHTML = `
    <div style="display:flex;align-items:center;justify-content:space-between;margin:8px 0 8px">
      <div style="font-size:12px;font-weight:700;color:var(--gray-text)">⚡ Multi-compteurs — saisie détaillée</div>
      <div style="font-size:11px;color:var(--gray-text)">Les champs Index/Conso ci-dessus sont totalisés automatiquement.</div>
    </div>
    <div style="display:flex;flex-direction:column;gap:8px">${rowsHtml}</div>
  `;

  // Make main fields read-only (they represent the total)
  if (elDeb) { elDeb.readOnly = true; elDeb.style.background='var(--gray-bg)'; elDeb.oninput = null; }
  if (elFin) { elFin.readOnly = true; elFin.style.background='var(--gray-bg)'; elFin.oninput = null; }
  if (elCon) { elCon.readOnly = true; elCon.style.background='var(--gray-bg)'; elCon.oninput = null; }

  _calcSousCompteursTotals();
}

function _calcSousCompteursTotals() {
  const bloc = document.getElementById('blocSousCompteursReleve');
  if (!bloc || bloc.dataset.multi !== '1') return;

  let sumDeb = 0, sumFin = 0, sumConso = 0;
  const rows = bloc.querySelectorAll('.sousreleve-row');
  rows.forEach(row => {
    const debut = parseFloat(row.querySelector('.sc-debut')?.value);
    const fin   = parseFloat(row.querySelector('.sc-fin')?.value);
    const elC   = row.querySelector('.sc-conso');
    let c = '';
    if (!isNaN(debut) && !isNaN(fin) && fin >= debut) {
      const conso = fin - debut;
      c = conso.toFixed(2);
      sumDeb += debut;
      sumFin += fin;
      sumConso += conso;
    }
    if (elC) elC.value = c;
  });

  _applySousCompteursTotals({ sumDeb, sumFin, sumConso });
  if (sumConso > 0) _autoCalcMontantHT(sumConso);
}

function _applySousCompteursTotals(totals) {
  const elDeb = document.getElementById('f_indexDebut');
  const elFin = document.getElementById('f_indexFin');
  const elCon = document.getElementById('f_consommation');
  if (elDeb) elDeb.value = (totals?.sumDeb ?? '').toString() === '0' ? '' : (totals.sumDeb || 0).toFixed(2);
  if (elFin) elFin.value = (totals?.sumFin ?? '').toString() === '0' ? '' : (totals.sumFin || 0).toFixed(2);
  if (elCon) elCon.value = (totals?.sumConso ?? '').toString() === '0' ? '' : (totals.sumConso || 0).toFixed(2);
}


function _collectSousCompteursDetail() {
  const bloc = document.getElementById('blocSousCompteursReleve');
  if (!bloc || bloc.dataset.multi !== '1') {
    return { ok:true, json:null, totals:{ sumDeb:0, sumFin:0, sumConso:0 } };
  }

  const rows = bloc.querySelectorAll('.sousreleve-row');
  const out = [];
  let sumDeb = 0, sumFin = 0, sumConso = 0;

  let idxRow = 0;
  for (const row of rows) {
    idxRow++;
    const debutStr = row.querySelector('.sc-debut')?.value;
    const finStr   = row.querySelector('.sc-fin')?.value;
    const debut = parseFloat(debutStr);
    const fin   = parseFloat(finStr);

    if (isNaN(debut) || isNaN(fin) || fin < debut) {
      return { ok:false, error:`Sous-compteur #${idxRow} : index début/fin invalides.` };
    }

    const conso = fin - debut;
    out.push({
      numero: row.dataset.numero || null,
      nom: row.dataset.nom || null,
      idxDebut: debut,
      idxFin: fin,
      conso: conso,
    });

    sumDeb += debut;
    sumFin += fin;
    sumConso += conso;
  }

  if (!out.length) {
    return { ok:false, error:'Sous-compteurs : aucune ligne.' };
  }

  return {
    ok:true,
    json: JSON.stringify(out),
    totals: { sumDeb, sumFin, sumConso },
  };
}
// ── Estimation détaillée pour relevé Eau ─────────────────────────────────────
function _updateEauEstimation(conso, contrat) {
  var bloc = document.getElementById('blocEauEstimation');
  if (!bloc) return;

  var prixM3HT  = parseFloat(contrat.EauPrixM3HT)  || 0;
  var prixM3TTC = parseFloat(contrat.EauPrixM3TTC) || 0;
  var aboHT     = parseFloat(contrat.EauAbonnementHT)  || 0;
  var aboTTC    = parseFloat(contrat.EauAbonnementTTC) || 0;
  var tvaM3     = parseFloat(contrat.EauTVAM3)  || 5.5;
  var tvaAbo    = parseFloat(contrat.EauTVAAbo) || 5.5;

  if (!prixM3HT && !aboHT) { bloc.style.display = 'none'; return; }

  var consoHT  = conso * prixM3HT;
  var consoTTC = prixM3TTC > 0 ? conso * prixM3TTC : consoHT * (1 + tvaM3/100);
  var totalHT  = consoHT + aboHT;
  var totalTTC = consoTTC + (aboTTC > 0 ? aboTTC : aboHT * (1 + tvaAbo/100));

  var fmt2 = function(v) { return v.toFixed(2).replace('.', ',') + ' €'; };
  var fmt4 = function(v) { return v.toFixed(4).replace('.', ',') + ' €/m³'; };

  bloc.style.display = 'block';
  bloc.innerHTML = '<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:12px 14px">'
    + '<div style="font-size:11px;font-weight:700;color:#1d4ed8;margin-bottom:10px;text-transform:uppercase;letter-spacing:.4px">💧 Estimation facture eau (depuis contrat)</div>'
    + '<table style="width:100%;border-collapse:collapse;font-size:12px">'
    + '<thead><tr style="background:#dbeafe">'
    + '<th style="padding:5px 8px;text-align:left;border-radius:4px 0 0 4px">Poste</th>'
    + '<th style="padding:5px 8px;text-align:right">Détail</th>'
    + '<th style="padding:5px 8px;text-align:right">HT</th>'
    + '<th style="padding:5px 8px;text-align:right;border-radius:0 4px 4px 0">TTC</th>'
    + '</tr></thead><tbody>'
    + (prixM3HT > 0 ? '<tr style="border-bottom:1px solid #bfdbfe">'
      + '<td style="padding:5px 8px;color:#1e40af;font-weight:600">Consommation</td>'
      + '<td style="padding:5px 8px;text-align:right;color:var(--gray-text)">' + conso.toFixed(2) + ' m³ × ' + fmt4(prixM3HT) + '</td>'
      + '<td style="padding:5px 8px;text-align:right;font-weight:600">' + fmt2(consoHT) + '</td>'
      + '<td style="padding:5px 8px;text-align:right;font-weight:600">' + fmt2(consoTTC) + '</td>'
      + '</tr>' : '')
    + (aboHT > 0 ? '<tr style="border-bottom:1px solid #bfdbfe">'
      + '<td style="padding:5px 8px;color:#1e40af;font-weight:600">Abonnement mensuel</td>'
      + '<td style="padding:5px 8px;text-align:right;color:var(--gray-text)">TVA ' + tvaAbo + '%</td>'
      + '<td style="padding:5px 8px;text-align:right;font-weight:600">' + fmt2(aboHT) + '</td>'
      + '<td style="padding:5px 8px;text-align:right;font-weight:600">' + fmt2(aboTTC > 0 ? aboTTC : aboHT*(1+tvaAbo/100)) + '</td>'
      + '</tr>' : '')
    + '<tr style="background:#dbeafe;font-weight:700">'
    + '<td style="padding:6px 8px;border-radius:0 0 0 4px;color:#1d4ed8" colspan="2">TOTAL ESTIMÉ</td>'
    + '<td style="padding:6px 8px;text-align:right;color:#1d4ed8">' + fmt2(totalHT) + '</td>'
    + '<td style="padding:6px 8px;text-align:right;color:#1d4ed8;border-radius:0 0 4px 0">' + fmt2(totalTTC) + '</td>'
    + '</tr>'
    + '</tbody></table>'
    + '<div style="font-size:10px;color:var(--gray-text);margin-top:6px">⚠️ Estimation indicative — saisir le montant réel de la facture si disponible</div>'
    + '</div>';
}

// ── Rendu du bloc lignes de détail facture (tous types de contrat) ─────────────
function _renderBlocLignesFacture(isVisible, isIndefini, detailIndefini) {
  var display = isVisible ? 'block' : 'none';
  var lignes = (detailIndefini.lignes && detailIndefini.lignes.length > 0) ? detailIndefini.lignes : [{}];

  var rowsHtml = lignes.map(function(l) {
    l = l || {};
    var label  = l.label        ? String(l.label).replace(/"/g, '&quot;') : '';
    var qte    = l.quantite     != null ? l.quantite     : '';
    var unite  = l.unite        ? String(l.unite).replace(/"/g, '&quot;') : '';
    var pu     = l.prixUnitaire != null ? l.prixUnitaire : '';
    var valeur = l.valeur       != null ? l.valeur       : '';

    return '<div class="lf-row" style="margin-bottom:8px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:8px">'
      // Main row — 8 cols: intitulé | qté | [+] | unité | PU | = | montant | ✕
      + '<div style="display:grid;grid-template-columns:1.6fr 0.85fr 34px 70px 1fr 24px 1fr 36px;gap:6px;align-items:center">'
      + '<input class="form-control lf-label" type="text" value="' + label + '" placeholder="Ex : Abonnement, Consommation\u2026" style="font-size:12px">'
      + '<input class="form-control lf-quantite" type="number" step="any" value="' + qte + '" placeholder="Qt\xe9" oninput="calcLigneMontant(this)" style="font-size:12px;text-align:right">'
      + '<button type="button" onclick="toggleAdditionQte(this)" title="D\xe9tailler la quantit\xe9" style="background:#e0f2fe;border:1px solid #7dd3fc;color:#0369a1;cursor:pointer;border-radius:4px;padding:0;width:34px;height:34px;font-size:16px;font-weight:700">+</button>'
      + '<input class="form-control lf-unite" type="text" value="' + unite + '" placeholder="unit\xe9" style="font-size:12px;color:var(--gray-text)">'
      + '<input class="form-control lf-pu" type="number" step="any" value="' + pu + '" placeholder="Prix unit. HT" oninput="calcLigneMontant(this)" style="font-size:12px;text-align:right">'
      + '<span style="font-size:14px;color:var(--gray-text);text-align:center">=</span>'
      + '<input class="form-control lf-montant" type="number" step="any" value="' + valeur + '" placeholder="Montant HT" oninput="calcLignesFacture()" style="font-size:12px;text-align:right">'
      + '<button type="button" onclick="removeLigneFacture(this)" style="background:none;border:none;color:#e74c3c;cursor:pointer;font-size:20px;padding:0;line-height:1;width:36px;height:36px">\xd7</button>'
      + '</div>'
      // Hidden additionneur zone
      + '<div class="lf-additionneur" style="display:none;margin-top:6px;padding-top:6px;border-top:1px dashed #bae6fd">'
      + '<div style="display:grid;grid-template-columns:1fr 24px 1fr 24px 80px 28px;gap:5px;padding-bottom:4px;border-bottom:1px dotted #bae6fd;margin-bottom:4px">'
      + '<div style="font-size:10px;font-weight:600;color:#0369a1">Quantit\xe9 partielle</div>'
      + '<div></div>'
      + '<div style="font-size:10px;font-weight:600;color:#0369a1">Prix unit. HT (opt.)</div>'
      + '<div></div>'
      + '<div style="font-size:10px;font-weight:600;color:#15803d">Montant</div>'
      + '<div></div>'
      + '</div>'
      + '<div class="lf-additionneur-rows" style="display:flex;flex-direction:column;gap:4px"></div>'
      + '<button type="button" onclick="addValeurAdditionneur(this)" style="margin-top:4px;background:#e0f2fe;border:1px solid #7dd3fc;color:#0369a1;cursor:pointer;border-radius:4px;padding:2px 10px;font-size:12px">+ Valeur</button>'
      + '</div>'
      + '</div>';
  }).join('');

  var consoFactureHtml = isIndefini
    ? '<div style="margin-bottom:10px;display:flex;align-items:center;gap:10px">'
      + '<label style="font-size:12px;font-weight:600;color:#0891b2;white-space:nowrap">Conso kWh factur\xe9e :</label>'
      + '<input class="form-control" type="number" step="0.01" id="f_consoFacture" value="' + (detailIndefini.consoFacture || '') + '" placeholder="kWh sur la facture" oninput="calcCarboneElec()" style="max-width:160px;border-color:#7dd3fc;font-size:12px">'
      + '</div>'
    : '';

  return '<div id="blocLignesFacture" style="display:' + display + ';margin-top:8px">'
    + '<div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:14px 16px">'
    + '<div style="font-size:11px;font-weight:700;color:#0369a1;margin-bottom:10px;text-transform:uppercase;letter-spacing:.4px">\ud83d\udccb D\xe9tail des lignes de la facture</div>'
    + consoFactureHtml
    // Column headers
    + '<div style="display:grid;grid-template-columns:1.6fr 0.85fr 34px 70px 1fr 24px 1fr 36px;gap:6px;padding:0 0 6px;border-bottom:1px solid #bae6fd;margin-bottom:8px">'
    + '<div style="font-size:10px;font-weight:600;color:var(--gray-text)">Intitul\xe9</div>'
    + '<div style="font-size:10px;font-weight:600;color:var(--gray-text);text-align:right">Quantit\xe9</div>'
    + '<div></div>'
    + '<div style="font-size:10px;font-weight:600;color:var(--gray-text)">Unit\xe9</div>'
    + '<div style="font-size:10px;font-weight:600;color:var(--gray-text);text-align:right" class="lf-header-pu">Prix unit. HT</div>'
    + '<div></div>'
    + '<div style="font-size:10px;font-weight:600;color:var(--gray-text);text-align:right">Montant HT</div>'
    + '<div></div>'
    + '</div>'
    // Rows container
    + '<div id="lignesFactureContainer">' + rowsHtml + '</div>'
    // Add button
    + '<button type="button" onclick="addLigneFacture()" style="background:#0891b2;color:white;border:none;border-radius:6px;padding:5px 14px;font-size:12px;cursor:pointer;margin-top:6px">+ Ajouter une ligne</button>'
    // Totals
    + '<div style="display:grid;grid-template-columns:1fr 100px;gap:6px;margin-top:10px;padding-top:10px;border-top:2px solid #bae6fd">'
    + '<div style="font-size:12px;font-weight:700;color:#0369a1;display:flex;align-items:center">\ud83d\udcca Total \u2014 report\xe9 dans Montant HT</div>'
    + '<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:6px 10px;text-align:right">'
    + '<div style="font-size:10px;color:var(--gray-text)">Total HT</div>'
    + '<div style="font-size:14px;font-weight:700;color:#15803d" id="lf_total_ht">\u2014</div>'
    + '</div>'
    + '</div>'
    + '<div style="font-size:10px;color:var(--gray-text);margin-top:6px">Quantit\xe9 \xd7 Prix unitaire = Montant HT (auto-calcul\xe9). Le total est report\xe9 dans le champ Montant HT ci-dessus.</div>'
    + '</div>'
    + '</div>';
}

// ── Toggle affichage lignes facture ──────────────────────────────────────────
function toggleLignesFacture() {
  var bloc = document.getElementById('blocLignesFacture');
  var btn  = document.getElementById('btnToggleLignes');
  if (!bloc) return;
  var show = bloc.style.display === 'none';
  bloc.style.display = show ? 'block' : 'none';
  if (btn) btn.textContent = show ? '\ud83d\udccb Masquer les lignes' : '\ud83d\udccb D\xe9tail des lignes';
  if (show) calcLignesFacture();
}

// ── Calcul auto d'une ligne (qté × PU → montant) ─────────────────────────────
function calcLigneMontant(inputEl) {
  var row = inputEl.closest('.lf-row');
  if (!row) return;
  var qte = parseFloat(row.querySelector('.lf-quantite')?.value);
  var pu  = parseFloat(row.querySelector('.lf-pu')?.value);
  if (!isNaN(qte) && !isNaN(pu)) {
    var elMontant = row.querySelector('.lf-montant');
    if (elMontant) elMontant.value = (qte * pu).toFixed(2);
  }
  calcLignesFacture();
}

// ── Recalcul total lignes → sync montantHT ────────────────────────────────────
function calcLignesFacture() {
  var total = 0;
  document.querySelectorAll('#lignesFactureContainer .lf-row').forEach(function(row) {
    total += parseFloat(row.querySelector('.lf-montant')?.value) || 0;
    // Masquer PU parent si le détail est ouvert
    var zone = row.querySelector('.lf-additionneur');
    var elPU = row.querySelector('.lf-pu');
    if (zone && elPU) {
      var open = zone.style.display !== 'none';
      elPU.style.visibility = open ? 'hidden' : 'visible';
      elPU.style.pointerEvents = open ? 'none' : '';
    }
  });
  var elTotal = document.getElementById('lf_total_ht');
  if (elTotal) elTotal.textContent = total > 0
    ? new Intl.NumberFormat('fr-FR', {style:'currency', currency:'EUR'}).format(total)
    : '\u2014';
  var elHT = document.getElementById('f_montantHT');
  if (elHT && total > 0) { elHT.value = total.toFixed(2); calcTTCFromHT(); }
  calcCarboneElec();
}

// ── Ajouter / supprimer une ligne ─────────────────────────────────────────────
function addLigneFacture() {
  var container = document.getElementById('lignesFactureContainer');
  if (!container) return;
  var div = document.createElement('div');
  div.className = 'lf-row';
  div.style.cssText = 'margin-bottom:8px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:8px';
  div.innerHTML = '<div style="display:grid;grid-template-columns:1.6fr 0.85fr 34px 70px 1fr 24px 1fr 36px;gap:6px;align-items:center">'
    + '<input class="form-control lf-label" type="text" value="" placeholder="Ex : Abonnement, Consommation\u2026" style="font-size:12px">'
    + '<input class="form-control lf-quantite" type="number" step="any" value="" placeholder="Qt\xe9" oninput="calcLigneMontant(this)" style="font-size:12px;text-align:right">'
    + '<button type="button" onclick="toggleAdditionQte(this)" title="D\xe9tailler la quantit\xe9" style="background:#e0f2fe;border:1px solid #7dd3fc;color:#0369a1;cursor:pointer;border-radius:4px;padding:0;width:34px;height:34px;font-size:16px;font-weight:700">+</button>'
    + '<input class="form-control lf-unite" type="text" value="" placeholder="unit\xe9" style="font-size:12px;color:var(--gray-text)">'
    + '<input class="form-control lf-pu" type="number" step="any" value="" placeholder="Prix unit. HT" oninput="calcLigneMontant(this)" style="font-size:12px;text-align:right">'
    + '<span style="font-size:14px;color:var(--gray-text);text-align:center">=</span>'
    + '<input class="form-control lf-montant" type="number" step="any" value="" placeholder="Montant HT" oninput="calcLignesFacture()" style="font-size:12px;text-align:right">'
    + '<button type="button" onclick="removeLigneFacture(this)" style="background:none;border:none;color:#e74c3c;cursor:pointer;font-size:20px;padding:0;line-height:1;width:36px;height:36px">\xd7</button>'
    + '</div>'
    + '<div class="lf-additionneur" style="display:none;margin-top:6px;padding-top:6px;border-top:1px dashed #bae6fd">'
    + '<div style="display:grid;grid-template-columns:1fr 24px 1fr 24px 80px 28px;gap:5px;padding-bottom:4px;border-bottom:1px dotted #bae6fd;margin-bottom:4px">'
    + '<div style="font-size:10px;font-weight:600;color:#0369a1">Quantit\xe9 partielle</div>'
    + '<div></div>'
    + '<div style="font-size:10px;font-weight:600;color:#0369a1">Prix unit. HT (opt.)</div>'
    + '<div></div>'
    + '<div style="font-size:10px;font-weight:600;color:#15803d">Montant</div>'
    + '<div></div>'
    + '</div>'
    + '<div class="lf-additionneur-rows" style="display:flex;flex-direction:column;gap:4px"></div>'
    + '<button type="button" onclick="addValeurAdditionneur(this)" style="margin-top:4px;background:#e0f2fe;border:1px solid #7dd3fc;color:#0369a1;cursor:pointer;border-radius:4px;padding:2px 10px;font-size:12px">+ Valeur</button>'
    + '</div>';
  container.appendChild(div);
  div.querySelector('.lf-label')?.focus();
}

function removeLigneFacture(btn) {
  var row = btn.closest('.lf-row');
  if (row) row.remove();
  calcLignesFacture();
}


// ── Carbone auto Électricité + Gaz — utilise le facteur stocké pour l'année ──
async function calcCarboneElec() {
  const onglet = EnergieState.onglet;
  if (onglet !== 'Electricite' && onglet !== 'Gaz') return;
  var elCarbone = document.getElementById('f_carbone');
  if (!elCarbone) return;

  var dateSaisie = document.getElementById('f_date')?.value;
  var annee = dateSaisie ? parseInt(dateSaisie.substring(0, 4)) : new Date().getFullYear();
  var typeSaisie = document.getElementById('f_typeSaisie')?.value;

  // En mode relevé : calculer depuis la consommation (index)
  if (typeSaisie === 'Releve') {
    var consoReleve = parseFloat(document.getElementById('f_consommation') ? document.getElementById('f_consommation').value : '');
    if (!isNaN(consoReleve) && consoReleve > 0) {
      let detail = await calcCarboneDetaille(onglet, consoReleve, annee);
      elCarbone.value = detail ? detail.co2.toFixed(2) : '';
      _majMentionCarbone(detail);
    } else {
      elCarbone.value = '';
    }
    return;
  }

  // En mode facture : chercher la conso dans l'ordre de priorité
  // 1. Champ conso facturée explicite (contrats indéfinis)
  var consoFact = parseFloat(document.getElementById('f_consoFacture') ? document.getElementById('f_consoFacture').value : '');

  // 2. Somme des lignes dont l'unité est kWh ou m³
  var consoLignes = 0;
  document.querySelectorAll('#lignesFactureContainer .lf-row').forEach(function(row) {
    var unite = (row.querySelector('.lf-unite') ? row.querySelector('.lf-unite').value : '').trim().toLowerCase();
    if (unite === 'kwh' || unite === 'kw.h' || unite === 'm³' || unite === 'm3') {
      var qte = parseFloat(row.querySelector('.lf-quantite') ? row.querySelector('.lf-quantite').value : '');
      if (!isNaN(qte) && qte > 0) consoLignes += qte;
    }
  });

  // 3. Consommation calculée depuis les index
  var consoCalc = parseFloat(document.getElementById('f_consommation') ? document.getElementById('f_consommation').value : '');

  var conso = (!isNaN(consoFact) && consoFact > 0) ? consoFact
            : (consoLignes > 0) ? consoLignes
            : consoCalc;
  if (!isNaN(conso) && conso > 0) {
    let detail = await calcCarboneDetaille(onglet, conso, annee);
    elCarbone.value = detail ? detail.co2.toFixed(2) : '';
    _majMentionCarbone(detail);
  }
}


/**
 * Met à jour la mention sous le champ CO₂ selon la qualité du facteur utilisé.
 * Sans ça, un total calculé avec un facteur de repli est indiscernable d'un
 * total calculé avec le millésime de l'année du relevé.
 */
function _majMentionCarbone(detail) {
  const el = document.getElementById('carboneAutoInfo');
  if (!el) return;
  if (!detail || !detail.mention) {
    el.textContent = '🔄 Auto (facteur ADEME × conso) — modifiable manuellement';
    el.style.color = '#16a34a';
    el.title = detail ? ('Facteur ' + detail.anneeFacteur + ' — ' + (detail.source || '')) : '';
    return;
  }
  const alerte = detail.mention.niveau === 'alerte';
  el.textContent = (alerte ? '⛔ ' : 'ℹ️ ') + detail.mention.texte;
  el.style.color = alerte ? '#dc2626' : '#d97706';
  el.title = detail.source || '';
}

// ── Additionneur de quantités (bouton + sur la ligne) ─────────────────────────
function toggleAdditionQte(btn) {
  var row = btn.closest('.lf-row');
  if (!row) return;
  var zone = row.querySelector('.lf-additionneur');
  if (!zone) return;
  var show = zone.style.display === 'none';
  zone.style.display = show ? 'block' : 'none';
  // Masquer le prix unitaire de la ligne parente quand le détail est ouvert
  var elPU = row.querySelector('.lf-pu');
  if (elPU) {
    elPU.style.visibility = show ? 'hidden' : 'visible';
    elPU.style.pointerEvents = show ? 'none' : '';
    elPU.title = show ? 'Prix unitaire défini dans le détail ci-dessous' : '';
  }
  if (show && zone.querySelector('.lf-additionneur-rows').children.length === 0) {
    _addRowAdditionneur(row.querySelector('.lf-additionneur-rows'));
  }
}

function addValeurAdditionneur(btn) {
  var row = btn.closest('.lf-row');
  if (!row) return;
  var container = row.querySelector('.lf-additionneur-rows');
  if (!container) return;
  _addRowAdditionneur(container);
}

function _addRowAdditionneur(container) {
  var div = document.createElement('div');
  div.className = 'add-row';
  div.style.cssText = 'display:grid;grid-template-columns:1fr 24px 1fr 24px 80px 28px;gap:5px;align-items:center';
  div.innerHTML =
    // Quantité partielle
    '<input type="number" step="any" class="add-qte" placeholder="Quantit\xe9\u2026"'
    + ' oninput="recalcAdditionneur(this)"'
    + ' style="padding:4px 8px;border:1px solid #bae6fd;border-radius:4px;font-size:12px;text-align:right">'
    // ×
    + '<span style="text-align:center;color:var(--gray-text);font-size:12px">\xd7</span>'
    // Prix unitaire optionnel
    + '<input type="number" step="any" class="add-pu" placeholder="Prix unit. (opt.)"'
    + ' oninput="recalcAdditionneur(this)"'
    + ' style="padding:4px 8px;border:1px solid #bae6fd;border-radius:4px;font-size:12px;text-align:right">'
    // =
    + '<span style="text-align:center;color:var(--gray-text);font-size:12px">=</span>'
    // Montant partiel (readonly, calculé)
    + '<input type="number" step="any" class="add-montant" placeholder="\u2014"'
    + ' readonly style="padding:4px 8px;border:1px solid #e2e8f0;border-radius:4px;font-size:12px;text-align:right;background:var(--gray-bg);color:#15803d">'
    // Supprimer
    + '<button type="button" onclick="this.closest(\'.add-row\').remove();recalcAdditionneur(this)"'
    + ' style="background:none;border:none;color:#94a3b8;cursor:pointer;font-size:16px;padding:0 4px">\xd7</button>';
  container.appendChild(div);
  div.querySelector('.add-qte').focus();
}

function recalcAdditionneur(inputEl) {
  var row = inputEl.closest('.lf-row');
  if (!row) return;

  var totalQte = 0;
  var totalMontant = 0;
  var hasPU = false;

  row.querySelectorAll('.lf-additionneur-rows .add-row').forEach(function(addRow) {
    var qte = parseFloat(addRow.querySelector('.add-qte').value) || 0;
    var pu  = parseFloat(addRow.querySelector('.add-pu').value);
    var montant = 0;
    if (!isNaN(pu) && pu > 0) {
      montant = qte * pu;
      hasPU = true;
    }
    addRow.querySelector('.add-montant').value = (hasPU || montant > 0) && !isNaN(pu) && pu > 0
      ? montant.toFixed(2) : '';
    totalQte     += qte;
    totalMontant += montant;
  });

  // Reporter quantité totale
  var elQte = row.querySelector('.lf-quantite');
  if (elQte) elQte.value = totalQte > 0 ? totalQte.toFixed(2) : '';

  // Si des prix unitaires sont saisis dans l'additionneur → reporter le montant total
  var elMontant = row.querySelector('.lf-montant');
  if (hasPU && elMontant) {
    elMontant.value = totalMontant.toFixed(2);
  } else if (elMontant) {
    // Sinon : qté totale × PU de la ligne parente
    var puParent = parseFloat(row.querySelector('.lf-pu') ? row.querySelector('.lf-pu').value : '');
    if (!isNaN(puParent) && puParent > 0 && totalQte > 0) {
      elMontant.value = (totalQte * puParent).toFixed(2);
    }
  }

  calcLignesFacture();
}

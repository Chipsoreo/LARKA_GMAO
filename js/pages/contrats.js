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
 * Larka — Page : Contrats de maintenance
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Gestion des contrats (maintenance, contrôle réglementaire, énergie, etc.).
 *
 * FONCTIONNALITÉS :
 *   - Contacts multiples par contrat (nom, rôle, email, téléphone) en JSON
 *   - Paramètres énergie détaillés (électricité HP/HC, eau, gaz, chauffage)
 *   - Alerte automatique N jours avant expiration
 *   - Liaison avec équipements et interventions
 *   - Calcul automatique TTC depuis HT
 *
 * POINTS D'ENTRÉE : renderContrats(), editContrat(id)
 * ═══════════════════════════════════════════════════════════════════════════════
 */

async function renderContrats() {
  document.getElementById('topbarActions').innerHTML = '';
  const c = document.getElementById('mainContent');
  showLoading(c);
  try {
    const data = await ContratsApi.getAll();
    renderTable({
      container: c, data,
      columns: [
        { key:'Numero',       label:'N° Contrat',  editFn:'editContrat', deleteFn:'deleteContrat' },
        { key:'Societe',      label:'Société' },
        { key:'ContactNom',   label:'Contacts',    render:(v,ct)=>_contratContactsCell(ct) },
        { key:'Type',         label:'Type' },
        { key:'Statut',       label:'Statut',      badge:true },
        { key:'DateDebut',    label:'Début',        type:'date' },
        { key:'DateFin',      label:'Fin',          type:'date' },
        { key:'MontantAnnuel',label:'Montant/an',   type:'money' },
      ],
      addBtnFn: 'editContrat',
      searchTerm: App.searchTerm, currentFilter: App.currentFilter,
      canEdit: canEdit(), canDelete: isAdmin(),
    });
  } catch(e) { c.innerHTML = errorHtml(e.message); }
}

/**
 * Cellule « Contacts » de la liste des contrats : affiche chaque contact
 * (nom + rôle) avec son téléphone et son email cliquables (tel:/mailto:),
 * pour voir et joindre les interlocuteurs sans ouvrir la fiche.
 * Gère le multi-contacts (ContactsJSON) et l'ancien format (ContactNom/Tel/Email).
 */
function _contratContactsCell(ct) {
  let contacts = [];
  try { contacts = JSON.parse(ct.ContactsJSON || '[]'); } catch(_){}
  if (!Array.isArray(contacts)) contacts = [];
  if (!contacts.length && (ct.ContactNom || ct.ContactEmail || ct.ContactTel)) {
    contacts = [{ nom: ct.ContactNom || '', role: '', email: ct.ContactEmail || '', tel: ct.ContactTel || '' }];
  }
  if (!contacts.length) return '<span style="color:var(--gray-text)">—</span>';

  return contacts.map(c => {
    const nom  = escHtml(c.nom || '');
    const role = c.role ? ` <span style="color:var(--gray-text);font-size:11px">(${escHtml(c.role)})</span>` : '';
    const tel  = c.tel   ? `<a href="tel:${escHtml(String(c.tel).replace(/\s/g,''))}" style="color:var(--blue);text-decoration:none" title="Appeler">📞 ${escHtml(c.tel)}</a>` : '';
    const mail = c.email ? `<a href="mailto:${escHtml(c.email)}" style="color:var(--blue);text-decoration:none" title="Envoyer un email">✉️ ${escHtml(c.email)}</a>` : '';
    const coords = [tel, mail].filter(Boolean).join(' &nbsp;·&nbsp; ');
    if (!nom && !coords) return '';
    return `<div style="margin:2px 0;line-height:1.4">
      ${nom ? `<span style="font-weight:600">${nom}</span>${role}` : ''}
      ${coords ? `<div style="font-size:12px">${coords}</div>` : ''}
    </div>`;
  }).filter(Boolean).join('') || '<span style="color:var(--gray-text)">—</span>';
}

async function editContrat(id) {
  const isNew = !id;
  const [ct, types, statuts, _rf] = await Promise.all([
    isNew ? Promise.resolve({}) : ContratsApi.getAll().then(d => d.find(x => x.Id === id) || {}),
    ListesApi.getByCategorie('TypeContrat'),
    ListesApi.getByCategorie('StatutContrat'),
    getRequiredFields('contrats'),
  ]);
  const rf = _rf || [];
  const opts = (liste, val) => liste.map(x => `<option ${val === x.Valeur ? 'selected' : ''}>${x.Valeur}</option>`).join('');

  openModal(isNew ? 'Nouveau contrat' : `Modifier ${escHtml(ct.Numero||'')}`, `
    <div class="form-grid">
      <div class="form-group">
        <label class="form-label">${reqLabel('Numéro','Numero',rf)}</label>
        <input class="form-control" id="f_numero" value="${escHtml(ct.Numero || '')}" ${reqAttr('Numero',rf)}>
      </div>
      <div class="form-group">
        <label class="form-label">${reqLabel('Société','Societe',rf)}</label>
        <input class="form-control" id="f_societe" value="${escHtml(ct.Societe || '')}" ${reqAttr('Societe',rf)}>
      </div>
      <div class="form-group">
        <label class="form-label">${reqLabel('Type','Type',rf)}</label>
        <select class="form-control" id="f_type" onchange="toggleBlocEnergie()">${opts(types, ct.Type)}</select>
      </div>
      <div class="form-group">
        <label class="form-label">Statut</label>
        <select class="form-control" id="f_statut">${opts(statuts, ct.Statut)}</select>
      </div>
      <div class="form-group">
        <label class="form-label">${reqLabel("Date début","DateDebut",rf)}</label>
        <input class="form-control" type="date" id="f_dateDebut" value="${ct.DateDebut || ''}">
      </div>
      <div class="form-group">
        <label class="form-label">${reqLabel("Date fin","DateFin",rf)}</label>
        <input class="form-control" type="date" id="f_dateFin" value="${ct.DateFin || ''}">
      </div>
      <div class="form-group" id="blocMontant" style="display:${ct.Type==='Energie'?'none':'block'}">
        <label class="form-label">Montant annuel (€)</label>
        <input class="form-control" type="number" id="f_montant" value="${ct.MontantAnnuel || 0}">
      </div>
      <div class="form-group" id="blocFrequence" style="display:${ct.Type==='Energie'?'none':'block'}">
        <label class="form-label">Fréquence facturation</label>
        <select class="form-control" id="f_frequence">
          ${['Mensuelle','Trimestrielle','Annuelle'].map(x => `<option ${ct.Frequence === x ? 'selected' : ''}>${x}</option>`).join('')}
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Contact tél.</label>
        <input class="form-control" id="f_contactTel" value="${escHtml(ct.ContactTel || '')}" placeholder="Contact principal (rétro-compat.)">
      </div>
      <div class="form-group span-2" style="grid-column:span 2">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
          <label class="form-label" style="margin:0">👥 Contacts du contrat</label>
          <button type="button" class="btn btn-ghost btn-sm" onclick="_ajouterContact()">+ Ajouter un contact</button>
        </div>
        <div id="contactsList" style="display:flex;flex-direction:column;gap:8px">${(() => {
          let contacts = [];
          try { contacts = JSON.parse(ct.ContactsJSON || '[]'); } catch(_){}
          // Migration : si pas de ContactsJSON mais des champs legacy
          if (!contacts.length && (ct.ContactNom || ct.ContactEmail || ct.ContactTel)) {
            contacts = [{ nom: ct.ContactNom||'', role: '', email: ct.ContactEmail||'', tel: ct.ContactTel||'' }];
          }
          if (!contacts.length) return '<div id="noContactMsg" style="padding:10px;color:var(--gray-text);font-size:12px;text-align:center;border:1px dashed var(--gray-border);border-radius:8px">Aucun contact. Cliquez + pour en ajouter.</div>';
          return contacts.map((c,i) => _contactRowHtml(i, c)).join('');
        })()}</div>
      </div>
      <div class="form-group">
        <label class="form-label">Alerte (jours avant fin)</label>
        <input class="form-control" type="number" id="f_alerte" value="${ct.AlerteJoursAvant || 30}">
      </div>
      <div class="form-group span-2">
        <label class="form-label">Description / Périmètre</label>
        <textarea class="form-control" id="f_description">${escHtml(ct.Description || '')}</textarea>
      </div>
    </div>

    <div id="blocEnergie" style="display:${ct.Type==='Energie'?'block':'none'}">
      <div class="section-label" style="margin-top:12px">⚡ Paramètres énergie</div>
      <div class="form-grid">
        <div class="form-group">
          <label class="form-label">Fluide concerné</label>
          <select class="form-control" id="f_fluide" onchange="toggleChampsFluide()">
            ${['Electricite','Eau','Chauffage','Gaz'].map(x=>`<option ${ct.Fluide===x?'selected':''}>${x}</option>`).join('')}
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Fournisseur énergie</label>
          <input class="form-control" id="f_fournisseurEnergie" value="${ct.FournisseurEnergie||''}" placeholder="EDF, Engie…">
        </div>
        <div class="form-group">
          <label class="form-label">Référent client / N°</label>
          <input class="form-control" id="f_referentClient" value="${ct.ReferentClient||''}" placeholder="Nom ou numéro du référent">
        </div>
        <div class="form-group">
          <label class="form-label">Référence contrat</label>
          <input class="form-control" id="f_referenceContrat" value="${ct.ReferenceContrat||''}" placeholder="Réf. fournisseur">
        </div>

        <!-- Champs spécifiques électricité -->
        <div id="blocElec" style="display:${(ct.Fluide||'Electricite')==='Electricite'?'contents':'none'}">
          <div class="form-group">
            <label class="form-label">Option tarifaire</label>
            <select class="form-control" id="f_optionTarifaire" onchange="toggleBlocHPHC()">
              ${['Base','HP-HC','Tempo','EJP','HTA 5 plages','Je ne sais pas'].map(x=>`<option ${ct.OptionTarifaire===x?'selected':''}>${x}</option>`).join('')}
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Puissance souscrite (kVA)</label>
            <input class="form-control" type="number" step="0.1" id="f_puissance" value="${escHtml(ct.PuissanceSouscrite||'')}">
          </div>

          <!-- BLOC BASE -->
          <div id="blocBase" style="display:${(!ct.OptionTarifaire||ct.OptionTarifaire==='Base')?'contents':'none'}">
            <div class="form-group span-2" style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:12px 16px;grid-column:span 2">
              <div style="font-size:11px;font-weight:700;color:#0369a1;margin-bottom:10px;text-transform:uppercase;letter-spacing:.5px">📋 Tarif Base</div>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                <div>
                  <label class="form-label" style="font-size:11px">Abonnement mensuel HT (€)</label>
                  <input class="form-control" type="number" step="0.01" id="f_abonnement" value="${ct.AbonnementHT||''}" oninput="calcContratTTC()">
                </div>
                <div>
                  <label class="form-label" style="font-size:11px">TVA abonnement (%)</label>
                  <div style="display:flex;gap:6px;align-items:center">
                    <input class="form-control" type="number" step="0.1" id="f_tvaAbo" value="${ct.TVAAbo||5.5}" style="width:80px" oninput="calcContratTTC()">
                    <span style="font-size:12px;color:var(--gray-text)">→ TTC :</span>
                    <input class="form-control" type="number" step="0.01" id="f_abonnementTTC" value="${ct.AbonnementTTC||''}" placeholder="Calculé" style="flex:1;background:var(--gray-bg)" readonly>
                  </div>
                </div>
                <div>
                  <label class="form-label" style="font-size:11px">Prix kWh HT (€/kWh)</label>
                  <input class="form-control" type="number" step="0.0001" id="f_prixBase" value="${ct.PrixBaseHT||''}" oninput="calcContratTTC()">
                </div>
                <div>
                  <label class="form-label" style="font-size:11px">TVA kWh (%)</label>
                  <div style="display:flex;gap:6px;align-items:center">
                    <input class="form-control" type="number" step="0.1" id="f_tvaKwh" value="${ct.TVAKwh||20}" style="width:80px" oninput="calcContratTTC()">
                    <span style="font-size:12px;color:var(--gray-text)">→ TTC :</span>
                    <input class="form-control" type="number" step="0.0001" id="f_prixBaseTTC" value="${ct.PrixBaseTTC||''}" placeholder="Calculé" style="flex:1;background:var(--gray-bg)" readonly>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- BLOC HP-HC / Tempo / EJP -->
          <div id="blocHPHC" style="display:${['HP-HC','Tempo','EJP'].includes(ct.OptionTarifaire)?'contents':'none'}">
            <div class="form-group span-2" style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:12px 16px;grid-column:span 2">
              <div style="font-size:11px;font-weight:700;color:#0369a1;margin-bottom:10px;text-transform:uppercase;letter-spacing:.5px">📋 Tarif HP/HC</div>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                <div>
                  <label class="form-label" style="font-size:11px">Abonnement mensuel HT (€)</label>
                  <input class="form-control" type="number" step="0.01" id="f_abonnement2" value="${ct.AbonnementHT||''}" oninput="calcContratTTC()">
                </div>
                <div>
                  <label class="form-label" style="font-size:11px">TVA (%)</label>
                  <div style="display:flex;gap:6px;align-items:center">
                    <input class="form-control" type="number" step="0.1" id="f_tvaRate" value="${ct.TVARate||20}" style="width:80px" oninput="calcContratTTC()">
                    <span style="font-size:12px;color:var(--gray-text)">→ TTC :</span>
                    <input class="form-control" type="number" step="0.01" id="f_abonnementTTC2" value="${ct.AbonnementTTC||''}" placeholder="Auto" style="flex:1;background:var(--gray-bg)" readonly>
                  </div>
                </div>
                <div>
                  <label class="form-label" style="font-size:11px">Prix kWh HP HT (€)</label>
                  <input class="form-control" type="number" step="0.0001" id="f_prixHP" value="${ct.PrixHPHT||''}" oninput="calcContratTTC()">
                </div>
                <div>
                  <label class="form-label" style="font-size:11px">Prix kWh HP TTC (€)</label>
                  <input class="form-control" type="number" step="0.0001" id="f_prixHPTTC" value="${ct.PrixHPTTC||''}" placeholder="Auto" style="background:var(--gray-bg)" readonly>
                </div>
                <div>
                  <label class="form-label" style="font-size:11px">Prix kWh HC HT (€)</label>
                  <input class="form-control" type="number" step="0.0001" id="f_prixHC" value="${ct.PrixHCHT||''}" oninput="calcContratTTC()">
                </div>
                <div>
                  <label class="form-label" style="font-size:11px">Prix kWh HC TTC (€)</label>
                  <input class="form-control" type="number" step="0.0001" id="f_prixHCTTC" value="${ct.PrixHCTTC||''}" placeholder="Auto" style="background:var(--gray-bg)" readonly>
                </div>
                <div>
                  <label class="form-label" style="font-size:11px">Heures creuses — début</label>
                  <input class="form-control" type="time" id="f_hcDebut" value="${ct.HCDebut||''}">
                </div>
                <div>
                  <label class="form-label" style="font-size:11px">Heures creuses — fin</label>
                  <input class="form-control" type="time" id="f_hcFin" value="${ct.HCFin||''}">
                </div>
              </div>
            </div>
          </div>

          <!-- BLOC HTA 5 PLAGES -->
          <div id="blocHTA5" style="display:${ct.OptionTarifaire==='HTA 5 plages'?'contents':'none'}">
            <div class="form-group" style="grid-column:span 2">
              <div style="background:#fdf4ff;border:1px solid #e9d5ff;border-radius:8px;padding:14px 16px">
                <div style="font-size:11px;font-weight:700;color:#7c3aed;margin-bottom:12px;text-transform:uppercase;letter-spacing:.5px">⚡ Tarif HTA 5 Plages</div>
                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:14px">

                  <div style="background:white;border:1px solid #e5e7eb;border-radius:6px;padding:10px">
                    <div style="font-size:11px;font-weight:700;color:#b45309;margin-bottom:6px">🌞 HPHS — Hiver HP</div>
                    <label class="form-label" style="font-size:11px">Prix HT (€/kWh)</label>
                    <input class="form-control" type="number" step="0.0001" id="f_hta_hphs" value="${ct.HtaHPHS||''}" oninput="recalcHtaTVA()">
                    <div style="display:flex;gap:4px;align-items:center;margin-top:4px">
                      <span style="font-size:11px;color:var(--gray-text)">TTC :</span>
                      <input class="form-control" type="number" step="0.0001" id="f_hta_hphs_ttc" value="${ct.HtaHPHSTTC||''}" placeholder="Auto" style="flex:1;background:var(--gray-bg)">
                    </div>
                  </div>

                  <div style="background:white;border:1px solid #e5e7eb;border-radius:6px;padding:10px">
                    <div style="font-size:11px;font-weight:700;color:#0369a1;margin-bottom:6px">🌙 HCHS — Hiver HC</div>
                    <label class="form-label" style="font-size:11px">Prix HT (€/kWh)</label>
                    <input class="form-control" type="number" step="0.0001" id="f_hta_hchs" value="${ct.HtaHCHS||''}" oninput="recalcHtaTVA()">
                    <div style="display:flex;gap:4px;align-items:center;margin-top:4px">
                      <span style="font-size:11px;color:var(--gray-text)">TTC :</span>
                      <input class="form-control" type="number" step="0.0001" id="f_hta_hchs_ttc" value="${ct.HtaHCHSTTC||''}" placeholder="Auto" style="flex:1;background:var(--gray-bg)">
                    </div>
                  </div>

                  <div style="background:white;border:1px solid #e5e7eb;border-radius:6px;padding:10px">
                    <div style="font-size:11px;font-weight:700;color:#15803d;margin-bottom:6px">☀️ HPBS — Été HP</div>
                    <label class="form-label" style="font-size:11px">Prix HT (€/kWh)</label>
                    <input class="form-control" type="number" step="0.0001" id="f_hta_hpbs" value="${ct.HtaHPBS||''}" oninput="recalcHtaTVA()">
                    <div style="display:flex;gap:4px;align-items:center;margin-top:4px">
                      <span style="font-size:11px;color:var(--gray-text)">TTC :</span>
                      <input class="form-control" type="number" step="0.0001" id="f_hta_hpbs_ttc" value="${ct.HtaHPBSTTC||''}" placeholder="Auto" style="flex:1;background:var(--gray-bg)">
                    </div>
                  </div>

                  <div style="background:white;border:1px solid #e5e7eb;border-radius:6px;padding:10px">
                    <div style="font-size:11px;font-weight:700;color:#5b21b6;margin-bottom:6px">💤 HCBS — Été HC</div>
                    <label class="form-label" style="font-size:11px">Prix HT (€/kWh)</label>
                    <input class="form-control" type="number" step="0.0001" id="f_hta_hcbs" value="${ct.HtaHCBS||''}" oninput="recalcHtaTVA()">
                    <div style="display:flex;gap:4px;align-items:center;margin-top:4px">
                      <span style="font-size:11px;color:var(--gray-text)">TTC :</span>
                      <input class="form-control" type="number" step="0.0001" id="f_hta_hcbs_ttc" value="${ct.HtaHCBSTTC||''}" placeholder="Auto" style="flex:1;background:var(--gray-bg)">
                    </div>
                  </div>

                  <div style="background:white;border:1px solid #e5e7eb;border-radius:6px;padding:10px">
                    <div style="font-size:11px;font-weight:700;color:#0e7490;margin-bottom:6px">🌿 CEE</div>
                    <label class="form-label" style="font-size:11px">Montant HT (€)</label>
                    <input class="form-control" type="number" step="0.01" id="f_hta_cee" value="${ct.HtaCEE||''}" oninput="recalcHtaTVA()">
                    <div style="display:flex;gap:4px;align-items:center;margin-top:4px">
                      <span style="font-size:11px;color:var(--gray-text)">TTC :</span>
                      <input class="form-control" type="number" step="0.01" id="f_hta_cee_ttc" value="${ct.HtaCEETTC||''}" placeholder="Auto" style="flex:1;background:var(--gray-bg)">
                    </div>
                  </div>

                  <div style="background:white;border:1px solid #e5e7eb;border-radius:6px;padding:10px">
                    <div style="font-size:11px;font-weight:700;color:#9f1239;margin-bottom:6px">⚡ Obligation Capacité</div>
                    <label class="form-label" style="font-size:11px">Montant HT (€)</label>
                    <input class="form-control" type="number" step="0.01" id="f_hta_capa" value="${ct.HtaCapa||''}" oninput="recalcHtaTVA()">
                    <div style="display:flex;gap:4px;align-items:center;margin-top:4px">
                      <span style="font-size:11px;color:var(--gray-text)">TTC :</span>
                      <input class="form-control" type="number" step="0.01" id="f_hta_capa_ttc" value="${ct.HtaCapaTTC||''}" placeholder="Auto" style="flex:1;background:var(--gray-bg)">
                    </div>
                  </div>
                </div>

                <!-- TVA lignes -->
                <div style="border-top:1px solid #e5e7eb;padding-top:12px">
                  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;flex-wrap:wrap;gap:8px">
                    <div style="font-size:11px;font-weight:700;color:#374151">🏦 Lignes TVA (applicables par plage)</div>
                    <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                      <span style="font-size:11px;color:var(--gray-text)">Appliquer par défaut à :</span>
                      <select id="f_hta_tva_cible" class="form-control" style="font-size:11px;padding:2px 6px">
                        <option value="all">Toutes les plages</option>
                        <option value="hphs">HPHS</option><option value="hchs">HCHS</option>
                        <option value="hpbs">HPBS</option><option value="hcbs">HCBS</option>
                        <option value="cee">CEE</option><option value="capa">Oblig. capa</option>
                      </select>
                      <button type="button" class="btn btn-ghost btn-sm" onclick="addLigneTVA_HTA()" style="font-size:11px">+ Ajouter TVA</button>
                    </div>
                  </div>
                  <div id="lignes_tva_hta">
                    ${(ct.HtaTVALignes ? JSON.parse(ct.HtaTVALignes) : [{taux:20,cible:'all'}]).map((l,i) => _renderLigneTVA_HTA(i, l)).join('')}
                  </div>
                </div>
              </div>
            </div>
          </div>

        </div><!-- fin blocElec -->

          <!-- BLOC Je ne sais pas / Non définissable -->
          <div id="blocJeSaisPas" style="display:${ct.OptionTarifaire==='Je ne sais pas'?'contents':'none'}">
            <div class="form-group span-2" style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:12px 16px;grid-column:span 2">
              <div style="font-size:11px;font-weight:700;color:#15803d;margin-bottom:6px">❓ Option tarifaire non définie</div>
              <div style="font-size:12px;color:#166534">
                Le type de tarif n'est pas connu. Les relevés liés à ce contrat permettront de saisir les index, la consommation et le montant facturé.<br>
                <span style="font-size:11px;color:var(--gray-text)">Un mode avancé est disponible dans les relevés pour détailler les lignes de facture.</span>
              </div>
            </div>
          </div>

        <!-- Champs spécifiques eau -->
        <div id="blocEau" style="display:${(ct.Fluide)==='Eau'?'contents':'none'}">
          <div class="form-group span-2" style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:12px 16px;grid-column:span 2">
            <div style="font-size:11px;font-weight:700;color:#1d4ed8;margin-bottom:10px;text-transform:uppercase;letter-spacing:.5px">💧 Tarif Eau</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
              <div>
                <label class="form-label" style="font-size:11px">Abonnement mensuel HT (€)</label>
                <input class="form-control" type="number" step="0.01" id="f_eauAbonnement" value="${ct.EauAbonnementHT||''}" oninput="calcEauTTC()">
              </div>
              <div>
                <label class="form-label" style="font-size:11px">TVA abonnement (%)</label>
                <div style="display:flex;gap:6px;align-items:center">
                  <input class="form-control" type="number" step="0.1" id="f_eauTvaAbo" value="${ct.EauTVAAbo||5.5}" style="width:80px" oninput="calcEauTTC()">
                  <span style="font-size:12px;color:var(--gray-text)">→ TTC :</span>
                  <input class="form-control" type="number" step="0.01" id="f_eauAbonnementTTC" value="${ct.EauAbonnementTTC||''}" placeholder="Calculé" style="flex:1;background:var(--gray-bg)" readonly>
                </div>
              </div>
              <div>
                <label class="form-label" style="font-size:11px">Prix m³ HT (€/m³)</label>
                <input class="form-control" type="number" step="0.0001" id="f_eauPrixM3" value="${ct.EauPrixM3HT||''}" oninput="calcEauTTC()">
              </div>
              <div>
                <label class="form-label" style="font-size:11px">TVA consommation (%)</label>
                <div style="display:flex;gap:6px;align-items:center">
                  <input class="form-control" type="number" step="0.1" id="f_eauTvaM3" value="${ct.EauTVAM3||5.5}" style="width:80px" oninput="calcEauTTC()">
                  <span style="font-size:12px;color:var(--gray-text)">→ TTC :</span>
                  <input class="form-control" type="number" step="0.0001" id="f_eauPrixM3TTC" value="${ct.EauPrixM3TTC||''}" placeholder="Calculé" style="flex:1;background:var(--gray-bg)" readonly>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Champs spécifiques chauffage urbain -->
        <div id="blocChauffage" style="display:${(ct.Fluide)==='Chauffage'?'contents':'none'}">
          <div class="form-group span-2" style="background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;padding:10px 14px">
            <span style="font-size:12px;color:#991b1b">🔥 Contrat Chauffage Urbain — les paramètres tarifaires (R1, R2…) sont définis au niveau de chaque <strong>compteur</strong>.</span>
          </div>
        </div>

        <!-- Champs spécifiques gaz -->
        <div id="blocGaz" style="display:${(ct.Fluide)==='Gaz'?'contents':'none'}">
          <div class="form-group">
            <label class="form-label">Abonnement mensuel HT (€)</label>
            <input class="form-control" type="number" step="0.01" id="f_gazAbonnement" value="${ct.GazAbonnementHT||''}" oninput="calcGazTTC()">
          </div>
          <div class="form-group">
            <label class="form-label">TVA abonnement (%)</label>
            <div style="display:flex;gap:6px;align-items:center">
              <input class="form-control" type="number" step="0.1" id="f_gazTvaAbo" value="${ct.GazTVAAbo||5.5}" style="width:80px" oninput="calcGazTTC()">
              <span style="font-size:12px;color:var(--gray-text)">→ TTC :</span>
              <input class="form-control" type="number" step="0.01" id="f_gazAbonnementTTC" value="${ct.GazAbonnementTTC||''}" placeholder="Calculé" style="flex:1;background:var(--gray-bg)" readonly>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Prix m³ HT (€/m³)</label>
            <input class="form-control" type="number" step="0.0001" id="f_gazPrixM3" value="${ct.GazPrixM3HT||''}" oninput="calcGazTTC()">
          </div>
          <div class="form-group">
            <label class="form-label">TVA conso (%)</label>
            <div style="display:flex;gap:6px;align-items:center">
              <input class="form-control" type="number" step="0.1" id="f_gazTvaM3" value="${ct.GazTVAM3||20}" style="width:80px" oninput="calcGazTTC()">
              <span style="font-size:12px;color:var(--gray-text)">→ TTC :</span>
              <input class="form-control" type="number" step="0.0001" id="f_gazPrixM3TTC" value="${ct.GazPrixM3TTC||''}" placeholder="Calculé" style="flex:1;background:var(--gray-bg)" readonly>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Documents joints -->
    <div>
      <div class="section-label">📎 Documents joints</div>
      ${isNew ? docsPanelHtml('Contrat', 0, 'Contrat') : docsPanelHtml('Contrat', id)}
    </div>`, async () => {
    const numero = gv('f_numero'), societe = gv('f_societe');
    if (!numero || !societe) { toast('Numéro et société requis.', 'error'); return; }

    const htaTVALignes = [];
    document.querySelectorAll('.hta-tva-ligne').forEach(el => {
      const taux  = parseFloat(el.querySelector('.hta-tva-taux')?.value) || 0;
      const cible = el.querySelector('.hta-tva-cible')?.value || 'all';
      if (taux > 0) htaTVALignes.push({ taux, cible });
    });

    // Collecter les contacts multiples
    const contactsData = [];
    document.querySelectorAll('.contact-row').forEach(row => {
      const nom   = row.querySelector('.ct-nom')?.value.trim()||'';
      const role  = row.querySelector('.ct-role')?.value.trim()||'';
      const email = row.querySelector('.ct-email')?.value.trim()||'';
      const tel   = row.querySelector('.ct-tel')?.value.trim()||'';
      if (nom || email || tel) contactsData.push({ nom, role, email, tel });
    });
    // Rétro-compat : premier contact dans les champs legacy
    const firstContact = contactsData[0] || {};

    const payload = {
      numero, societe, type: gv('f_type'), statut: gv('f_statut'),
      dateDebut: gv('f_dateDebut'), dateFin: gv('f_dateFin'),
      montantAnnuel: parseFloat(gv('f_montant')) || 0,
      frequence: gv('f_frequence'),
      contactNom: firstContact.nom||gv('f_contactTel')?firstContact.nom:'',
      contactEmail: firstContact.email||'',
      contactTel: gv('f_contactTel')||firstContact.tel||'',
      contactsJSON: JSON.stringify(contactsData),
      alerteJoursAvant: parseInt(gv('f_alerte')) || 30,
      description: gv('f_description'),
      optionTarifaire: gv('f_optionTarifaire'),
      puissanceSouscrite: parseFloat(gv('f_puissance'))||null,
      prixBaseHT: parseFloat(gv('f_prixBase'))||null,
      prixBaseTTC: parseFloat(gv('f_prixBaseTTC'))||null,
      abonnementHT: parseFloat(gv('f_abonnement')||'') || parseFloat(gv('f_abonnement2')||'') || null,
      abonnementTTC: parseFloat(gv('f_abonnementTTC')||'') || parseFloat(gv('f_abonnementTTC2')||'') || null,
      tvaAbo: parseFloat(gv('f_tvaAbo'))||null,
      tvaKwh: parseFloat(gv('f_tvaKwh'))||null,
      tvaRate: parseFloat(gv('f_tvaRate'))||null,
      prixHPHT: parseFloat(gv('f_prixHP'))||null,
      prixHCHT: parseFloat(gv('f_prixHC'))||null,
      prixHPTTC: parseFloat(gv('f_prixHPTTC'))||null,
      prixHCTTC: parseFloat(gv('f_prixHCTTC'))||null,
      hcDebut: gv('f_hcDebut'),
      hcFin: gv('f_hcFin'),
      htaHPHS: parseFloat(gv('f_hta_hphs'))||null,
      htaHPHSTTC: parseFloat(gv('f_hta_hphs_ttc'))||null,
      htaHCHS: parseFloat(gv('f_hta_hchs'))||null,
      htaHCHSTTC: parseFloat(gv('f_hta_hchs_ttc'))||null,
      htaHPBS: parseFloat(gv('f_hta_hpbs'))||null,
      htaHPBSTTC: parseFloat(gv('f_hta_hpbs_ttc'))||null,
      htaHCBS: parseFloat(gv('f_hta_hcbs'))||null,
      htaHCBSTTC: parseFloat(gv('f_hta_hcbs_ttc'))||null,
      htaCEE: parseFloat(gv('f_hta_cee'))||null,
      htaCEETTC: parseFloat(gv('f_hta_cee_ttc'))||null,
      htaCapa: parseFloat(gv('f_hta_capa'))||null,
      htaCapaTTC: parseFloat(gv('f_hta_capa_ttc'))||null,
      htaTVALignes: htaTVALignes.length ? JSON.stringify(htaTVALignes) : null,
      referentClient: gv('f_referentClient'),
      referenceContrat: gv('f_referenceContrat'),
      fournisseurEnergie: gv('f_fournisseurEnergie'),
      fluide: gv('f_fluide'),
      eauAbonnementHT:  parseFloat(gv('f_eauAbonnement'))||null,
      eauAbonnementTTC: parseFloat(gv('f_eauAbonnementTTC'))||null,
      eauPrixM3HT:      parseFloat(gv('f_eauPrixM3'))||null,
      eauPrixM3TTC:     parseFloat(gv('f_eauPrixM3TTC'))||null,
      eauTVAAbo:        parseFloat(gv('f_eauTvaAbo'))||null,
      eauTVAM3:         parseFloat(gv('f_eauTvaM3'))||null,
      gazAbonnementHT:  parseFloat(gv('f_gazAbonnement'))||null,
      gazAbonnementTTC: parseFloat(gv('f_gazAbonnementTTC'))||null,
      gazPrixM3HT:      parseFloat(gv('f_gazPrixM3'))||null,
      gazPrixM3TTC:     parseFloat(gv('f_gazPrixM3TTC'))||null,
      gazTVAAbo:        parseFloat(gv('f_gazTvaAbo'))||null,
      gazTVAM3:         parseFloat(gv('f_gazTvaM3'))||null,
    };
    try {
      if (isNew) {
        const result = await ContratsApi.create(payload);
        const newId = result?.id || result;
        if (newId) await uploadPendingDocs('Contrat', 'Contrat', newId);
      }
      else       await ContratsApi.update(id, payload);
      toast(isNew ? 'Contrat créé.' : 'Contrat modifié.', 'success');
      closeModal();
      renderContrats();
    } catch (e) { toast(e.message, 'error'); }
  });

  // Initialiser le panneau documents après insertion DOM
  setTimeout(() => {
    if (isNew) { window._docsPending['Contrat'] = []; }
    initDocsPanel('Contrat', id, 'Contrat');
  }, 150);
}

// ── Helpers TVA HTA ───────────────────────────────────────────────────────────
function _renderLigneTVA_HTA(i, ligne) {
  const cibleOptions = [
    ['all','Toutes les plages'],['hphs','HPHS'],['hchs','HCHS'],
    ['hpbs','HPBS'],['hcbs','HCBS'],['cee','CEE'],['capa','Oblig. capa']
  ].map(([v,l])=>`<option value="${v}" ${ligne.cible===v?'selected':''}>${l}</option>`).join('');
  return `<div class="hta-tva-ligne" style="display:flex;gap:8px;align-items:center;margin-bottom:6px">
    <select class="form-control hta-tva-cible" style="font-size:12px;flex:1" onchange="recalcHtaTVA()">${cibleOptions}</select>
    <input class="form-control hta-tva-taux" type="number" step="0.1" value="${ligne.taux||20}"
      style="width:80px;font-size:12px" placeholder="%" oninput="recalcHtaTVA()">
    <span style="font-size:11px;white-space:nowrap;color:var(--gray-text)">%</span>
    <button type="button" onclick="this.closest('.hta-tva-ligne').remove();recalcHtaTVA()"
      style="background:none;border:none;color:#e74c3c;cursor:pointer;font-size:18px;padding:0 4px;line-height:1">×</button>
  </div>`;
}

function addLigneTVA_HTA() {
  const container = document.getElementById('lignes_tva_hta');
  if (!container) return;
  const cible = document.getElementById('f_hta_tva_cible')?.value || 'all';
  container.insertAdjacentHTML('beforeend', _renderLigneTVA_HTA(container.children.length, { taux: 20, cible }));
  recalcHtaTVA();
}

function recalcHtaTVA() {
  const plages = ['hphs','hchs','hpbs','hcbs','cee','capa'];
  // Init TTC = HT
  plages.forEach(p => {
    const ht = parseFloat(document.getElementById('f_hta_'+p)?.value);
    const ttcEl = document.getElementById('f_hta_'+p+'_ttc');
    if (ttcEl && !isNaN(ht) && ht > 0) ttcEl.value = ht.toFixed(['cee','capa'].includes(p) ? 2 : 4);
  });
  // Apply each ligne TVA
  document.querySelectorAll('.hta-tva-ligne').forEach(el => {
    const taux  = parseFloat(el.querySelector('.hta-tva-taux')?.value) || 0;
    const cible = el.querySelector('.hta-tva-cible')?.value || 'all';
    if (!taux) return;
    const coef = 1 + taux / 100;
    const targets = cible === 'all' ? plages : [cible];
    targets.forEach(p => {
      const ht = parseFloat(document.getElementById('f_hta_'+p)?.value);
      const ttcEl = document.getElementById('f_hta_'+p+'_ttc');
      if (ttcEl && !isNaN(ht) && ht > 0) ttcEl.value = (ht * coef).toFixed(['cee','capa'].includes(p) ? 2 : 4);
    });
  });
}

// ── Toggles ───────────────────────────────────────────────────────────────────
function toggleBlocHPHC() {
  const o = document.getElementById('f_optionTarifaire')?.value;
  const bHPHC = document.getElementById('blocHPHC');
  const bBase = document.getElementById('blocBase');
  const bHTA5 = document.getElementById('blocHTA5');
  const bJSP  = document.getElementById('blocJeSaisPas');
  // "Je ne sais pas" : aucun bloc tarifaire détaillé, juste un message
  if (o === 'Je ne sais pas') {
    if (bHPHC) bHPHC.style.display = 'none';
    if (bBase) bBase.style.display = 'none';
    if (bHTA5) bHTA5.style.display = 'none';
    if (bJSP)  bJSP.style.display  = 'contents';
    autoSetTVA();
    return;
  }
  if (bJSP) bJSP.style.display = 'none';
  if (bHPHC) bHPHC.style.display = ['HP-HC','Tempo','EJP'].includes(o) ? 'contents' : 'none';
  if (bBase) bBase.style.display = (o === 'Base' || !o) ? 'contents' : 'none';
  if (bHTA5) bHTA5.style.display = o === 'HTA 5 plages' ? 'contents' : 'none';
  autoSetTVA();
}

function toggleBlocEnergie() {
  const t = document.getElementById('f_type')?.value;
  const b = document.getElementById('blocEnergie');
  if (b) b.style.display = t === 'Energie' ? 'block' : 'none';
  const estEnergie = t === 'Energie';
  const bm = document.getElementById('blocMontant');
  const bf = document.getElementById('blocFrequence');
  if (bm) bm.style.display = estEnergie ? 'none' : 'block';
  if (bf) bf.style.display = estEnergie ? 'none' : 'block';
  toggleChampsFluide();
}

function toggleChampsFluide() {
  const f = document.getElementById('f_fluide')?.value;
  const bElec  = document.getElementById('blocElec');
  const bEau   = document.getElementById('blocEau');
  const bChauf = document.getElementById('blocChauffage');
  const bGaz   = document.getElementById('blocGaz');
  if (bElec)  bElec.style.display  = f === 'Electricite' ? 'contents' : 'none';
  if (bEau)   bEau.style.display   = f === 'Eau'         ? 'contents' : 'none';
  if (bChauf) bChauf.style.display = f === 'Chauffage'   ? 'contents' : 'none';
  if (bGaz)   bGaz.style.display   = f === 'Gaz'         ? 'contents' : 'none';
  autoSetTVA();
}

// ── TVA automatique selon fluide & option tarifaire ───────────────────────────
function autoSetTVA() {
  const fluide = document.getElementById('f_fluide')?.value || 'Electricite';
  const option = document.getElementById('f_optionTarifaire')?.value || 'Base';

  // Taux TVA par défaut selon fluide
  // Electricité : abo 5.5%, conso 20%
  // Eau : 5.5% global
  // Chauffage : abo 5.5%, conso 20%
  let tvaAbo = 5.5, tvaKwh = 20, tvaGlobal = 20;
  if (fluide === 'Eau') {
    tvaAbo = 5.5; tvaKwh = 5.5; tvaGlobal = 5.5;
    // Sync eau TVA fields
    const elEA = document.getElementById('f_eauTvaAbo');
    const elEM = document.getElementById('f_eauTvaM3');
    if (elEA) elEA.value = 5.5;
    if (elEM) elEM.value = 5.5;
    calcEauTTC();
  } else if (fluide === 'Gaz') {
    tvaAbo = 5.5; tvaKwh = 20; tvaGlobal = 20;
    // Sync gaz TVA fields
    const elGA = document.getElementById('f_gazTvaAbo');
    const elGM = document.getElementById('f_gazTvaM3');
    if (elGA) elGA.value = 5.5;
    if (elGM) elGM.value = 20;
    calcGazTTC();
  } else {
    tvaAbo = 5.5; tvaKwh = 20; tvaGlobal = 20;
  }

  // Appliquer selon le bloc visible
  if (option === 'Base' || !option) {
    const elAbo = document.getElementById('f_tvaAbo');
    const elKwh = document.getElementById('f_tvaKwh');
    if (elAbo) elAbo.value = tvaAbo;
    if (elKwh) elKwh.value = tvaKwh;
  } else if (['HP-HC','Tempo','EJP'].includes(option)) {
    const elRate = document.getElementById('f_tvaRate');
    if (elRate) elRate.value = tvaGlobal;
  }
  // HTA 5 plages : TVA gérée par les lignes TVA, on auto-set la première si vide
  calcContratTTC();
}

function calcContratTTC() {
  // Base : TVA séparée abo / kWh
  const tvaAbo = parseFloat(document.getElementById('f_tvaAbo')?.value) || 0;
  const tvaKwh = parseFloat(document.getElementById('f_tvaKwh')?.value) || 0;
  const abo    = parseFloat(document.getElementById('f_abonnement')?.value);
  const kwh    = parseFloat(document.getElementById('f_prixBase')?.value);
  if (!isNaN(abo) && tvaAbo) { const el = document.getElementById('f_abonnementTTC'); if (el) el.value = (abo*(1+tvaAbo/100)).toFixed(2); }
  if (!isNaN(kwh) && tvaKwh) { const el = document.getElementById('f_prixBaseTTC');   if (el) el.value = (kwh*(1+tvaKwh/100)).toFixed(4); }
  // HP-HC : TVA globale → abo, HP TTC, HC TTC
  const tvaGlobal = parseFloat(document.getElementById('f_tvaRate')?.value) || 0;
  if (tvaGlobal) {
    const coef = 1 + tvaGlobal/100;
    const abo2 = parseFloat(document.getElementById('f_abonnement2')?.value);
    if (!isNaN(abo2)) { const el = document.getElementById('f_abonnementTTC2'); if (el) el.value = (abo2*coef).toFixed(2); }
    const hp = parseFloat(document.getElementById('f_prixHP')?.value);
    if (!isNaN(hp)) { const el = document.getElementById('f_prixHPTTC'); if (el) el.value = (hp*coef).toFixed(4); }
    const hc = parseFloat(document.getElementById('f_prixHC')?.value);
    if (!isNaN(hc)) { const el = document.getElementById('f_prixHCTTC'); if (el) el.value = (hc*coef).toFixed(4); }
  }
}

// ── Calcul TTC Eau ────────────────────────────────────────────────────────────
function calcEauTTC() {
  const tvaAbo = parseFloat(document.getElementById('f_eauTvaAbo')?.value) || 5.5;
  const tvaM3  = parseFloat(document.getElementById('f_eauTvaM3')?.value)  || 5.5;
  const abo    = parseFloat(document.getElementById('f_eauAbonnement')?.value);
  const m3     = parseFloat(document.getElementById('f_eauPrixM3')?.value);
  if (!isNaN(abo)) { const el = document.getElementById('f_eauAbonnementTTC'); if (el) el.value = (abo*(1+tvaAbo/100)).toFixed(2); }
  if (!isNaN(m3))  { const el = document.getElementById('f_eauPrixM3TTC');    if (el) el.value = (m3 *(1+tvaM3/100)).toFixed(4); }
}
function calcGazTTC() {
  const tvaAbo = parseFloat(document.getElementById('f_gazTvaAbo')?.value) || 5.5;
  const tvaM3  = parseFloat(document.getElementById('f_gazTvaM3')?.value)  || 20;
  const abo    = parseFloat(document.getElementById('f_gazAbonnement')?.value);
  const m3     = parseFloat(document.getElementById('f_gazPrixM3')?.value);
  if (!isNaN(abo)) { const el = document.getElementById('f_gazAbonnementTTC'); if (el) el.value = (abo*(1+tvaAbo/100)).toFixed(2); }
  if (!isNaN(m3))  { const el = document.getElementById('f_gazPrixM3TTC');    if (el) el.value = (m3 *(1+tvaM3/100)).toFixed(4); }
}

async function deleteContrat(id) {
  showConfirm('Supprimer ce contrat ?', async () => {
    try { await ContratsApi.delete(id); toast('Contrat supprimé.'); renderContrats(); }
    catch (e) { toast(e.message, 'error'); }
  });
}

// ═══════════════════════════════════════════════════════════════
//  CONTACTS MULTIPLES
// ═══════════════════════════════════════════════════════════════
let _contactIdx = 0;
function _contactRowHtml(i, c = {}) {
  const idx = _contactIdx++;
  return `
  <div class="contact-row" data-idx="${idx}" style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr auto;gap:6px;align-items:end;padding:10px 12px;background:var(--gray-bg);border-radius:8px;border:1px solid var(--gray-border)">
    <div>
      <div style="font-size:10.5px;color:var(--gray-text);margin-bottom:2px">Nom</div>
      <input class="form-control ct-nom" value="${(c.nom||'').replace(/"/g,'&quot;')}" placeholder="Nom" style="font-size:12px;padding:6px 8px">
    </div>
    <div>
      <div style="font-size:10.5px;color:var(--gray-text);margin-bottom:2px">Rôle / Fonction</div>
      <input class="form-control ct-role" value="${(c.role||'').replace(/"/g,'&quot;')}" placeholder="Ex: Responsable technique" style="font-size:12px;padding:6px 8px">
    </div>
    <div>
      <div style="font-size:10.5px;color:var(--gray-text);margin-bottom:2px">Email</div>
      <input class="form-control ct-email" type="email" value="${(c.email||'').replace(/"/g,'&quot;')}" placeholder="email@..." style="font-size:12px;padding:6px 8px">
    </div>
    <div>
      <div style="font-size:10.5px;color:var(--gray-text);margin-bottom:2px">Téléphone</div>
      <input class="form-control ct-tel" value="${(c.tel||'').replace(/"/g,'&quot;')}" placeholder="06..." style="font-size:12px;padding:6px 8px">
    </div>
    <button class="icon-btn delete" onclick="_retirerContact(this)" title="Retirer" style="margin-bottom:2px">🗑️</button>
  </div>`;
}

function _ajouterContact() {
  const list = document.getElementById('contactsList');
  if (!list) return;
  const msg = document.getElementById('noContactMsg');
  if (msg) msg.remove();
  list.insertAdjacentHTML('beforeend', _contactRowHtml(_contactIdx));
}

function _retirerContact(btn) {
  const row = btn.closest('.contact-row');
  if (row) row.remove();
  const list = document.getElementById('contactsList');
  if (list && !list.querySelector('.contact-row')) {
    list.innerHTML = '<div id="noContactMsg" style="padding:10px;color:var(--gray-text);font-size:12px;text-align:center;border:1px dashed var(--gray-border);border-radius:8px">Aucun contact. Cliquez + pour en ajouter.</div>';
  }
}

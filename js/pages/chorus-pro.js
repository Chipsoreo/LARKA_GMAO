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
 * Larka — Page : Chorus Pro
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Console de configuration et test du proxy Chorus Pro (facturation publique).
 *
 * FONCTIONNALITÉS :
 *   - Affichage du statut de connexion à l'API Chorus (via PISTE)
 *   - Test du token OAuth et des appels API
 *   - Recherche de factures et consultation des statuts
 *   - Configuration visibilité et accès
 *
 * DÉPENDANCES :
 *   - api.js          → apiRequest() pour communiquer avec le backend
 *   - ui.js           → toast(), showLoading()
 *   - Backend         → api/routes/chorus.php (proxy Chorus via PISTE)
 *   - Configuration   → config.json > legifrance (OAuth partagé avec Légifrance)
 *
 * POINT D'ENTRÉE :
 *   - renderChorus()  → appelé par nav.js quand on clique sur "Chorus Pro"
 * ═══════════════════════════════════════════════════════════════════════════════
 */

// ══════════════════════════════════════════════════════════════════════════════
//  CHORUS PRO
// ══════════════════════════════════════════════════════════════════════════════


function _chEsc(v){
  return String(v ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
function _chYesNo(v){ return v ? 'Oui' : 'Non'; }
function _chStatusBadge(ok, label){
  return `<span style="display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:700;background:${ok?'rgba(22,163,74,.12)':'rgba(239,68,68,.12)'};color:${ok?'#166534':'#991b1b'}">${ok?'●':'○'} ${_chEsc(label)}</span>`;
}
function _chPretty(obj){
  try { return JSON.stringify(obj, null, 2); } catch(_) { return String(obj ?? ''); }
}
function _chDefaultPayload(){
  return '{\n  "pageCourante": 1,\n  "taillePage": 10\n}';
}

function _renderChorusBlocked(info){
  return `
  <div style="display:grid;gap:20px">
    <div class="card" style="padding:24px;background:linear-gradient(135deg,#0f172a 0%,#1e293b 100%);color:#fff">
      <div style="font-size:13px;letter-spacing:.08em;text-transform:uppercase;opacity:.78;margin-bottom:8px">Chorus Pro</div>
      <div style="font-size:28px;font-weight:800;line-height:1.15">Onglet masqué pour cette session</div>
      <div style="margin-top:10px;font-size:14px;opacity:.9;max-width:780px">
        ${_chEsc(info.reason || 'Accès Chorus non autorisé.')}
      </div>
    </div>

    <div class="card" style="padding:22px">
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px">
        <div>
          <div style="font-size:12px;color:var(--gray-text);text-transform:uppercase;letter-spacing:.08em">Compte connecté</div>
          <div style="font-size:16px;font-weight:800;margin-top:8px;word-break:break-word">${_chEsc(info.current_email || '—')}</div>
        </div>
        <div>
          <div style="font-size:12px;color:var(--gray-text);text-transform:uppercase;letter-spacing:.08em">Fournisseur</div>
          <div style="font-size:16px;font-weight:800;margin-top:8px">${_chEsc(info.current_provider || '—')}</div>
        </div>
        <div>
          <div style="font-size:12px;color:var(--gray-text);text-transform:uppercase;letter-spacing:.08em">Domaine</div>
          <div style="font-size:16px;font-weight:800;margin-top:8px">${_chEsc(info.current_domain || '—')}</div>
        </div>
      </div>
      <div style="margin-top:18px;font-size:13px;color:var(--gray-text)">
        Vérifie <strong>Configuration → Serveur → Chorus Pro</strong> : proxy activé, domaine autorisé et règle Microsoft.
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:16px">
        <button class="btn" onclick="navigate('dashboard')">← Retour au tableau de bord</button>
        <button class="btn btn-primary" onclick="navigate('configuration'); setTimeout(function(){switchConfigTab && switchConfigTab('serveur'); document.getElementById('srv-chorus-section')?.scrollIntoView({behavior:'smooth',block:'start'});}, 120);">⚙️ Ouvrir la config serveur</button>
      </div>
    </div>
  </div>`;
}

async function renderChorus(){
  const c = document.getElementById('mainContent');
  c.innerHTML = `<div class="card" style="padding:28px">Chargement Chorus Pro…</div>`;
  try {
    const visibility = await ChorusApi.visibility();
    if ((typeof App !== 'undefined' && App)) App.chorusVisibility = visibility;
    if (!visibility.allowed) {
      c.innerHTML = _renderChorusBlocked(visibility);
      return;
    }
    const status = await ChorusApi.status();
    c.innerHTML = _renderChorusPage(status);
    _bindChorusPage(status);
  } catch(e) {
    c.innerHTML = errorHtml(e.message || 'Erreur Chorus Pro');
  }
}

function _renderChorusPage(status){
  const hints = [
    status.service_active ? 'Proxy activé' : 'Proxy désactivé',
    status.client_configured ? 'Client OAuth configuré' : 'Client OAuth incomplet',
    status.technical_account_configured ? 'Compte technique configuré' : 'Compte technique manquant',
    status.official_mode ? 'Hôtes officiels' : 'Hôtes personnalisés',
  ];
  return `
  <div style="display:grid;gap:20px">
    <div class="card" style="padding:24px;background:linear-gradient(135deg,#0f172a 0%,#1e293b 100%);color:#fff">
      <div style="display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap">
        <div>
          <div style="font-size:13px;letter-spacing:.08em;text-transform:uppercase;opacity:.78;margin-bottom:8px">Chorus Pro</div>
          <div style="font-size:28px;font-weight:800;line-height:1.15">Intégration backend prête à configurer</div>
          <div style="margin-top:10px;font-size:14px;opacity:.88;max-width:820px">
            Le proxy serveur gère OAuth2 PISTE et l’en-tête <code style="background:rgba(255,255,255,.12);padding:2px 6px;border-radius:6px">cpro-account</code>.
            Renseigne simplement l’environnement, le client PISTE et le compte technique dans <strong>Configuration → Serveur → Chorus Pro</strong>, puis teste un chemin API.
          </div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end">
          ${hints.map((h,i)=>_chStatusBadge(i!==1? (i===0?status.service_active:i===2?status.technical_account_configured:status.official_mode) : status.client_configured,h)).join('')}
        </div>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px">
      <div class="card" style="padding:18px">
        <div style="font-size:12px;color:var(--gray-text);text-transform:uppercase;letter-spacing:.08em">Environnement</div>
        <div style="font-size:22px;font-weight:800;margin-top:8px">${_chEsc(status.environment || 'sandbox')}</div>
        <div style="font-size:12px;color:var(--gray-text);margin-top:8px">URLs PISTE officielles déduites automatiquement</div>
      </div>
      <div class="card" style="padding:18px">
        <div style="font-size:12px;color:var(--gray-text);text-transform:uppercase;letter-spacing:.08em">Hôte API</div>
        <div style="font-size:16px;font-weight:700;margin-top:8px;word-break:break-word">${_chEsc(status.api_host || '—')}</div>
        <div style="font-size:12px;color:var(--gray-text);margin-top:8px">OAuth : ${_chEsc(status.oauth_host || '—')}</div>
      </div>
      <div class="card" style="padding:18px">
        <div style="font-size:12px;color:var(--gray-text);text-transform:uppercase;letter-spacing:.08em">TLS</div>
        <div style="font-size:22px;font-weight:800;margin-top:8px">${status.tls_verify ? 'Vérifié' : 'Libre'}</div>
        <div style="font-size:12px;color:var(--gray-text);margin-top:8px">Bundle CA : ${_chYesNo(status.ca_bundle_configured)}</div>
      </div>
      <div class="card" style="padding:18px">
        <div style="font-size:12px;color:var(--gray-text);text-transform:uppercase;letter-spacing:.08em">Compte technique</div>
        <div style="font-size:22px;font-weight:800;margin-top:8px">${status.technical_account_configured ? 'OK' : 'À renseigner'}</div>
        <div style="font-size:12px;color:var(--gray-text);margin-top:8px">Indispensable pour l’en-tête cpro-account.</div>
      </div>
    </div>

    <div class="card" style="padding:22px">
      <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap">
        <div>
          <div style="font-size:20px;font-weight:800">Test rapide</div>
          <div style="font-size:13px;color:var(--gray-text);margin-top:4px">Vérifie d’abord OAuth, puis teste un chemin Chorus en POST.</div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <button class="btn" onclick="_chLoadExample('factures')">Exemple Factures</button>
          <button class="btn" onclick="_chLoadExample('structures')">Exemple Structures</button>
          <button class="btn btn-primary" onclick="_chTestToken()">Tester OAuth2</button>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr;gap:14px;margin-top:18px">
        <div>
          <label style="display:block;font-size:12px;font-weight:700;color:var(--gray-text);margin-bottom:6px">Chemin API Chorus</label>
          <input id="ch_path" class="form-control" type="text" value="/cpro/factures/v1" placeholder="/cpro/factures/v1/...">
          <div style="font-size:12px;color:var(--gray-text);margin-top:6px">Exemples : <code>/cpro/factures/v1</code>, <code>/cpro/structures/v1</code>. Ajuste selon le swagger de l’API utilisée.</div>
        </div>
        <div>
          <label style="display:block;font-size:12px;font-weight:700;color:var(--gray-text);margin-bottom:6px">Payload JSON</label>
          <textarea id="ch_payload" class="form-control" rows="12" style="font-family:monospace">${_chEsc(_chDefaultPayload())}</textarea>
        </div>
      </div>

      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:14px">
        <button class="btn btn-primary" onclick="_chRunCall()">▶ Lancer le test</button>
        <button class="btn" onclick="_chClearOutput()">Effacer la réponse</button>
        <button class="btn" onclick="switchConfigTab && switchConfigTab('serveur'); setTimeout(function(){document.getElementById('srv-chorus-section')?.scrollIntoView({behavior:'smooth',block:'start'});}, 80);">⚙️ Ouvrir la config serveur</button>
        <span id="ch_status_msg" style="font-size:12px;color:var(--gray-text)"></span>
      </div>

      <div style="margin-top:18px">
        <label style="display:block;font-size:12px;font-weight:700;color:var(--gray-text);margin-bottom:6px">Réponse backend</label>
        <pre id="ch_output" style="margin:0;background:#0f172a;color:#e2e8f0;padding:16px;border-radius:14px;min-height:220px;overflow:auto;font-size:12px;line-height:1.5">En attente d’un test…</pre>
      </div>
    </div>
  </div>`;
}

function _bindChorusPage(status){
  const msg = document.getElementById('ch_status_msg');
  if (msg) {
    if (!status.service_active) msg.textContent = 'Le proxy Chorus est actuellement désactivé dans la configuration serveur.';
    else if (!status.client_configured) msg.textContent = 'Renseigne le Client ID et le Client Secret avant le premier test.';
    else if (!status.technical_account_configured) msg.textContent = 'Le compte technique Chorus est requis pour les appels API.';
    else msg.textContent = 'Configuration détectée. Tu peux lancer un test.';
  }
}

function _chLoadExample(kind){
  const pathEl = document.getElementById('ch_path');
  const payloadEl = document.getElementById('ch_payload');
  if (!pathEl || !payloadEl) return;
  if (kind === 'structures') {
    pathEl.value = '/cpro/structures/v1';
    payloadEl.value = '{\n  "pageCourante": 1,\n  "taillePage": 10\n}';
    return;
  }
  pathEl.value = '/cpro/factures/v1';
  payloadEl.value = _chDefaultPayload();
}

async function _chTestToken(){
  const out = document.getElementById('ch_output');
  const msg = document.getElementById('ch_status_msg');
  if (out) out.textContent = 'Test OAuth2 en cours…';
  if (msg) msg.textContent = 'Récupération du token OAuth2…';
  try {
    const data = await ChorusApi.tokenTest();
    if (out) out.textContent = _chPretty(data);
    if (msg) msg.textContent = 'OAuth2 OK.';
  } catch(e) {
    if (out) out.textContent = e.message || 'Erreur OAuth2';
    if (msg) msg.textContent = 'Échec OAuth2.';
  }
}

async function _chRunCall(){
  const pathEl = document.getElementById('ch_path');
  const payloadEl = document.getElementById('ch_payload');
  const out = document.getElementById('ch_output');
  const msg = document.getElementById('ch_status_msg');
  if (!pathEl || !payloadEl || !out) return;

  let payload;
  try {
    payload = JSON.parse(payloadEl.value || '{}');
  } catch(e) {
    out.textContent = 'Payload JSON invalide : ' + e.message;
    if (msg) msg.textContent = 'Corrige le JSON avant de relancer.';
    return;
  }

  out.textContent = 'Appel Chorus en cours…';
  if (msg) msg.textContent = 'Envoi du POST via le proxy backend…';
  try {
    const data = await ChorusApi.call(pathEl.value.trim(), payload);
    out.textContent = _chPretty(data);
    if (msg) msg.textContent = 'Réponse reçue.';
  } catch(e) {
    out.textContent = e.message || 'Erreur Chorus Pro';
    if (msg) msg.textContent = 'Le test a échoué. Vérifie le chemin, le payload et la configuration.';
  }
}

function _chClearOutput(){
  const out = document.getElementById('ch_output');
  const msg = document.getElementById('ch_status_msg');
  if (out) out.textContent = 'En attente d’un test…';
  if (msg) msg.textContent = '';
}

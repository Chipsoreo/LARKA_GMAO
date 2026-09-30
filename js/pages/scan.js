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
 * Larka — Page : Scanner de codes-barres
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Permet de scanner un code-barres avec la caméra pour rechercher
 * un bien, équipement ou boîte d'archives par son numéro.
 * Utilise la bibliothèque ZXing (zxing-browser.min.js).
 *
 * POINT D'ENTRÉE : renderScan()
 * ═══════════════════════════════════════════════════════════════════════════════
 */

const ScanState = {
  codeReader: null,
  running: false,
  stream: null,       // ← référence au MediaStream pour tout couper proprement
  lastCode: '',       // ← dernier code détecté (anti-doublon)
  lastCodeTime: 0,    // ← timestamp du dernier code
};

async function renderScan() {
  stopScan();
  // Reset complet de l'état pour un nouveau scan
  ScanState.lastCode = '';
  ScanState.lastCodeTime = 0;
  document.getElementById('topbarActions').innerHTML = '';
  const c = document.getElementById('mainContent');

  c.innerHTML = `
    <div id="scanPage" style="display:flex;flex-direction:column;align-items:center;gap:20px;padding:20px 12px;max-width:480px;margin:0 auto;">
      <div style="text-align:center;">
        <div style="font-size:2.5rem;margin-bottom:6px;">📷</div>
        <p style="margin:0;color:var(--text-muted);font-size:0.95rem;">
          Pointez la caméra vers un <strong>QR code</strong> ou un <strong>code-barre</strong><br>
          associé à un bien ou équipement.
        </p>
      </div>
      <div id="scanViewfinder" style="position:relative;width:100%;max-width:360px;border-radius:16px;overflow:hidden;background:#000;aspect-ratio:1/1;box-shadow:0 4px 24px rgba(0,0,0,0.25);">
        <video id="scanVideo" style="width:100%;height:100%;object-fit:cover;" playsinline muted></video>
        <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;pointer-events:none;">
          <div style="width:65%;height:65%;border:3px solid rgba(255,255,255,0.85);border-radius:12px;box-shadow:0 0 0 9999px rgba(0,0,0,0.35);"></div>
        </div>
        <div id="scanStatusOverlay" style="position:absolute;bottom:0;left:0;right:0;padding:10px;background:rgba(0,0,0,0.55);color:#fff;font-size:0.85rem;text-align:center;">
          Démarrage de la caméra…
        </div>
      </div>
      <div style="width:100%;max-width:360px;">
        <div style="font-size:0.78rem;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted);margin-bottom:6px;">Ou saisir le numéro manuellement</div>
        <div style="display:flex;gap:8px;">
          <input id="scanManualInput" class="form-control" placeholder="Ex: BIE-0042 ou type-4578-AZE" style="flex:1;" onkeydown="if(event.key==='Enter') scanManual()">
          <button class="btn btn-primary" onclick="scanManual()">Rechercher</button>
        </div>
      </div>
      <div id="scanResult" style="width:100%;max-width:360px;"></div>
    </div>`;

  await startScan();
}

async function startScan() {
  if (ScanState.running) return;
  const statusEl = document.getElementById('scanStatusOverlay');
  const videoEl  = document.getElementById('scanVideo');
  if (!videoEl || !statusEl) return;

  if (typeof ZXingBrowser === 'undefined') {
    statusEl.textContent = '❌ Bibliothèque ZXing manquante (zxing-browser.min.js).';
    return;
  }

  try {
    // Demander le stream manuellement pour pouvoir le stopper proprement ensuite
    const stream = await navigator.mediaDevices.getUserMedia({
      video: { facingMode: { ideal: 'environment' } }
    });
    ScanState.stream = stream;
    videoEl.srcObject = stream;
    await videoEl.play();

    // Toujours créer une NOUVELLE instance pour éviter le cache de l'ancien code
    const codeReader = new ZXingBrowser.BrowserMultiFormatReader();
    ScanState.codeReader = codeReader;
    ScanState.running = true;
    statusEl.textContent = 'En attente du scan…';

    // decodeFromStream ne bloque pas — il passe un callback à chaque frame
    codeReader.decodeFromStream(stream, videoEl, (result, err) => {
      if (!ScanState.running) return;
      if (result) {
        const code = result.getText().trim();
        if (!code) return;

        // Anti-doublon : ignorer si c'est le même code détecté dans les 3 dernières secondes
        const now = Date.now();
        if (code === ScanState.lastCode && (now - ScanState.lastCodeTime) < 3000) {
          return;
        }

        ScanState.lastCode = code;
        ScanState.lastCodeTime = now;
        statusEl.textContent = '✅ Code détecté !';
        stopScan();
        onCodeDetecte(code);
      }
      // les erreurs "NotFoundException" sont normales (pas de code dans le cadre)
    });

  } catch(e) {
    ScanState.running = false;
    if (e.name === 'NotAllowedError' || e.name === 'PermissionDeniedError') {
      statusEl.textContent = '⛔ Accès caméra refusé. Autorisez dans les paramètres.';
    } else {
      statusEl.textContent = '📵 Caméra indisponible. Utilisez la saisie manuelle.';
      console.warn('Scan error:', e);
    }
  }
}

function stopScan() {
  ScanState.running = false;

  // 1. Stopper ZXing — reset() et détruire l'instance
  if (ScanState.codeReader) {
    try { ScanState.codeReader.reset(); } catch(_) {}
    ScanState.codeReader = null;
  }

  // 2. Couper TOUS les tracks du MediaStream → libère la caméra et débloque la navigation
  if (ScanState.stream) {
    ScanState.stream.getTracks().forEach(track => {
      try { track.stop(); } catch(_) {}
    });
    ScanState.stream = null;
  }

  // 3. Vider la source vidéo
  const videoEl = document.getElementById('scanVideo');
  if (videoEl) {
    videoEl.srcObject = null;
    videoEl.load();
  }
}

function scanManual() {
  const input = document.getElementById('scanManualInput');
  if (!input) return;
  const val = input.value.trim();
  if (!val) { toast('Veuillez saisir un numéro.', 'error'); return; }
  stopScan();
  onCodeDetecte(val);
}

async function onCodeDetecte(code) {
  const resultEl = document.getElementById('scanResult');
  if (!resultEl) return;

  resultEl.innerHTML = `
    <div style="text-align:center;padding:24px 0;color:var(--text-muted);">
      <div style="font-size:1.5rem;">🔍</div>
      <div>Recherche de <strong>${escHtml(code)}</strong>…</div>
    </div>`;

  // Correspondance flexible : accepte n'importe quel format (lettres, chiffres, tirets, underscores…)
  function matchCode(champ, code) {
    if (!champ) return false;
    return champ.trim().toLowerCase() === code.trim().toLowerCase();
  }

  try {
    const [biens, equips] = await Promise.all([BiensApi.getAll(), EquipementsApi.getAll()]);
    const bien  = biens.find(b =>
      matchCode(b.Numero, code) ||
      matchCode(b.NumeroSerie, code) ||
      matchCode(b.CodeQR, code) ||
      matchCode(b.CodeBarre, code)
    );
    const equip = equips.find(e =>
      matchCode(e.Numero, code) ||
      matchCode(e.NumeroSerie, code) ||
      matchCode(e.CodeQR, code) ||
      matchCode(e.CodeBarre, code)
    );
    if (bien)  { afficherResultatBien(code, bien);   return; }
    if (equip) { afficherResultatEquip(code, equip); return; }
    afficherCodeInconnu(code);
  } catch(e) {
    resultEl.innerHTML = `<div style="color:var(--danger);padding:12px;">${escHtml(e.message)}</div>`;
  }
}

function afficherResultatBien(code, b) {
  document.getElementById('scanResult').innerHTML = `
    <div style="background:var(--card-bg);border:2px solid var(--success,#22c55e);border-radius:14px;padding:18px;">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;">
        <span style="font-size:1.6rem;">🏢</span>
        <div>
          <div style="font-weight:700;font-size:1.05rem;">Bien trouvé</div>
          <div style="font-size:0.82rem;color:var(--text-muted);">Code scanné : ${escHtml(code)}</div>
        </div>
      </div>
      ${ficheInfoHtml([['N° Bien',b.Numero],['Famille',b.Famille],['Sous-famille',b.SousFamille],['Statut',b.Statut],['État',b.Etat],['Bâtiment',b.Batiment],['Affecté à',b.NomPrenom],['N° Série',b.NumeroSerie]])}
      <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:16px;">
        <button class="btn btn-primary" onclick="navigate('biens')">📋 Voir tous les biens</button>
        ${canEdit() ? `<button class="btn btn-secondary" onclick="stopScan();editBien(${b.Id})">✏️ Modifier</button>` : ''}
        <button class="btn btn-secondary" onclick="relancerScan()">📷 Scanner un autre</button>
      </div>
    </div>`;
}

function afficherResultatEquip(code, e) {
  document.getElementById('scanResult').innerHTML = `
    <div style="background:var(--card-bg);border:2px solid var(--success,#22c55e);border-radius:14px;padding:18px;">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;">
        <span style="font-size:1.6rem;">⚙️</span>
        <div>
          <div style="font-weight:700;font-size:1.05rem;">Équipement trouvé</div>
          <div style="font-size:0.82rem;color:var(--text-muted);">Code scanné : ${escHtml(code)}</div>
        </div>
      </div>
      ${ficheInfoHtml([['N° Équip.',e.Numero],['Famille',e.Famille],['Sous-famille',e.SousFamille],['Statut',e.Statut],['État',e.Etat],['Bâtiment',e.Batiment],['Affecté à',e.NomPrenom],['N° Série',e.NumeroSerie]])}
      <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:16px;">
        <button class="btn btn-primary" onclick="navigate('equipements')">📋 Voir tous les équipements</button>
        ${canEdit() ? `<button class="btn btn-secondary" onclick="stopScan();editEquip(${e.Id})">✏️ Modifier</button>` : ''}
        <button class="btn btn-secondary" onclick="relancerScan()">📷 Scanner un autre</button>
      </div>
    </div>`;
}

function afficherCodeInconnu(code) {
  document.getElementById('scanResult').innerHTML = `
    <div style="background:var(--card-bg);border:2px solid var(--warning,#f59e0b);border-radius:14px;padding:18px;">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">
        <span style="font-size:1.6rem;">❓</span>
        <div>
          <div style="font-weight:700;font-size:1.05rem;">Code non reconnu</div>
          <div style="font-size:0.85rem;color:var(--text-muted);"><strong>${escHtml(code)}</strong> n'existe ni dans les biens ni dans les équipements.</div>
        </div>
      </div>
      ${canEdit() ? `
      <p style="font-size:0.9rem;margin:0 0 12px;">Voulez-vous créer une nouvelle fiche ?</p>
      <div style="display:flex;flex-wrap:wrap;gap:8px;">
        <button class="btn btn-primary" onclick="creerBienAvecCode('${escAttr(code)}')">🏢 Créer un bien</button>
        <button class="btn btn-secondary" onclick="creerEquipAvecCode('${escAttr(code)}')">⚙️ Créer un équipement</button>
        <button class="btn btn-secondary" onclick="relancerScan()">📷 Scanner un autre</button>
      </div>` : `
      <button class="btn btn-secondary" onclick="relancerScan()">📷 Scanner un autre</button>`}
    </div>`;
}

function creerBienAvecCode(code) {
  stopScan();
  navigate('biens');
  setTimeout(() => { editBien(null); setTimeout(() => { const el = document.getElementById('f_numero'); if (el) el.value = code; }, 80); }, 300);
}

function creerEquipAvecCode(code) {
  stopScan();
  navigate('equipements');
  setTimeout(() => { editEquip(null); setTimeout(() => { const el = document.getElementById('f_numero'); if (el) el.value = code; }, 80); }, 300);
}

async function relancerScan() {
  stopScan();
  // Reset anti-doublon pour permettre la détection d'un nouveau code
  ScanState.lastCode = '';
  ScanState.lastCodeTime = 0;
  // Vider les résultats et le champ manuel
  const r = document.getElementById('scanResult');
  if (r) r.innerHTML = '';
  const m = document.getElementById('scanManualInput');
  if (m) m.value = '';
  const s = document.getElementById('scanStatusOverlay');
  if (s) s.textContent = 'En attente du scan…';
  // Petit délai pour laisser la caméra se libérer proprement avant de la relancer
  await new Promise(ok => setTimeout(ok, 300));
  await startScan();
}

function ficheInfoHtml(champs) {
  return '<div style="border-top:1px solid var(--border);">' +
    champs.filter(([,v]) => v).map(([label, val]) =>
      `<div style="display:flex;gap:6px;padding:5px 0;border-bottom:1px solid var(--border);">
        <span style="color:var(--text-muted);font-size:0.82rem;min-width:110px;">${label}</span>
        <span style="font-size:0.9rem;font-weight:500;">${escHtml(String(val))}</span>
      </div>`).join('') + '</div>';
}

// NB : escHtml() n'est plus redéfini ici. La version de js/ui.js (toujours chargé
// en amont) est utilisée : elle gère null/undefined et échappe aussi les
// apostrophes. Redéfinir ce nom en portée globale écrasait celle d'ui.js pour
// TOUTE l'application dès que la page Scan était visitée.
function escAttr(s) {
  return String(s).replace(/'/g,"\\'").replace(/"/g,'&quot;');
}

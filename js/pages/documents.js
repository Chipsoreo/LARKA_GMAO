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
 * Larka — Documents joints (upload, preview, pending)
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Panneau unifié pour les documents attachés à toute entité.
 *
 * FONCTIONNALITÉS :
 *   - Upload immédiat (entité existante) ou en file d'attente (nouvelle entité)
 *   - Prévisualisation inline : miniatures photos, icône PDF cliquable
 *   - Modal plein écran pour photos et PDF
 *   - Limites par catégorie (pdf, photo, autre)
 *
 * POINTS D'ENTRÉE :
 *   docsPanelHtml(type, id, pendingKey)  → HTML à insérer dans un formulaire
 *   initDocsPanel(type, id, pendingKey)  → charger les docs après insertion DOM
 *   uploadPendingDocs(key, type, newId)  → envoyer la file d'attente après save
 * ═══════════════════════════════════════════════════════════════════════════════
 */

const DOC_LIMITES = { pdf: 4, photo: 4, autre: 4 };
const DOC_MIME_CAT = {
  'application/pdf': 'pdf',
  'image/jpeg': 'photo', 'image/jpg': 'photo',
  'image/png': 'photo', 'image/gif': 'photo', 'image/webp': 'photo',
};

function docCategorie(mime) { return DOC_MIME_CAT[mime] || 'autre'; }
function docIcon(cat) { return cat === 'pdf' ? '📄' : cat === 'photo' ? '🖼️' : '📎'; }
function fmtTaille(bytes) {
  if (!bytes) return '';
  if (bytes < 1024) return bytes + ' o';
  if (bytes < 1048576) return (bytes/1024).toFixed(0) + ' Ko';
  return (bytes/1048576).toFixed(1) + ' Mo';
}
function _previewUrl(docId) { return `api/index.php?action=document_preview&id=${docId}`; }
function _isPhoto(mime) { return mime && mime.startsWith('image/'); }
function _isPdf(mime) { return mime === 'application/pdf'; }

// ── Stockage global des fichiers en attente ──────────────────────────────────
window._docsPending = window._docsPending || {};
// Blob URLs pour preview locale (libérées au nettoyage)
window._docsBlobUrls = window._docsBlobUrls || [];

function _getPending(key) {
  if (!window._docsPending[key]) window._docsPending[key] = [];
  return window._docsPending[key];
}

function _b64toBlob(b64, mime) {
  const bin = atob(b64);
  const arr = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
  return new Blob([arr], { type: mime });
}

function _makeBlobUrl(b64, mime) {
  const blob = _b64toBlob(b64, mime);
  const url = URL.createObjectURL(blob);
  window._docsBlobUrls.push(url);
  return url;
}

// ── HTML du panneau (à insérer dans le formulaire) ───────────────────────────
function docsPanelHtml(entiteType, entiteId, pendingKey) {
  const pk = pendingKey || entiteType;
  const panelId = entiteId ? `docs_${entiteType}_${entiteId}` : `docs_pending_${pk}`;
  return `<div id="${panelId}" data-docs-panel="${entiteType}" data-pending-key="${pk}" data-entity-id="${entiteId||0}" style="min-height:40px">Chargement…</div>`;
}

// ── Initialisation après insertion DOM ────────────────────────────────────────
async function initDocsPanel(entiteType, entiteId, pendingKey) {
  const pk = pendingKey || entiteType;
  const panelId = entiteId ? `docs_${entiteType}_${entiteId}` : `docs_pending_${pk}`;
  const el = document.getElementById(panelId);
  if (!el) return;

  if (entiteId) {
    await renderDocumentsPanel(entiteType, entiteId, el, pk);
  } else {
    renderPendingPanel(pk, el);
  }
}

// Cache local des métadonnées des documents affichés : nom, type, taille,
// catégorie, dates — jamais le contenu des fichiers.
let _docsCache = [];

// ── Rendu panel pour entité existante ────────────────────────────────────────
async function renderDocumentsPanel(entiteType, entiteId, container, pendingKey) {
  const pk = pendingKey || entiteType;
  let docs = [];
  try { docs = await DocumentsApi.getAll(entiteType, entiteId); } catch(e) {
    container.innerHTML = `<div style="color:var(--gray-text);font-size:12px">Impossible de charger les documents.</div>`;
    return;
  }

  // Mémoriser les métadonnées (fusion, pour ne pas perdre celles d'un autre panel)
  _docsCache = [...(docs || []), ..._docsCache.filter(o => !(docs || []).some(n => n.Id === o.Id))];

  const counts = { pdf: 0, photo: 0, autre: 0 };
  docs.forEach(d => { counts[d.Categorie] = (counts[d.Categorie]||0) + 1; });

  let html = `<div style="display:flex;flex-direction:column;gap:8px">`;

  if (docs.length === 0 && _getPending(pk).length === 0) {
    html += `<div style="color:var(--gray-text);font-size:13px;padding:4px 0">Aucun document.</div>`;
  }

  if (docs.length > 0) {
    html += `<div style="display:flex;flex-wrap:wrap;gap:10px">`;
    docs.forEach(d => { html += _renderDocCard(d, entiteType, entiteId); });
    html += `</div>`;
  }

  // Pending docs
  const pending = _getPending(pk);
  if (pending.length > 0) {
    html += `<div style="margin-top:4px;font-size:11px;color:var(--orange);font-weight:600">⏳ ${pending.length} fichier${pending.length>1?'s':''} en attente d'enregistrement</div>`;
    html += `<div style="display:flex;flex-wrap:wrap;gap:10px">`;
    pending.forEach((p, i) => { html += _renderPendingCard(p, i, pk); });
    html += `</div>`;
  }

  html += _renderUploadZones(counts, entiteType, entiteId, pk);
  html += `</div>`;
  container.innerHTML = html;
}

// ── Rendu panel mode pending (nouvelle entité) ───────────────────────────────
function renderPendingPanel(pendingKey, container) {
  const pending = _getPending(pendingKey);
  const counts = { pdf: 0, photo: 0, autre: 0 };
  pending.forEach(p => { const c = p.cat || docCategorie(p.mime); counts[c] = (counts[c]||0) + 1; });

  let html = `<div style="display:flex;flex-direction:column;gap:8px">`;

  if (pending.length === 0) {
    html += `<div style="color:var(--gray-text);font-size:13px;padding:4px 0">Aucun document. Ajoutez-en ci-dessous, ils seront envoyés à l'enregistrement.</div>`;
  } else {
    html += `<div style="display:flex;flex-wrap:wrap;gap:10px">`;
    pending.forEach((p, i) => { html += _renderPendingCard(p, i, pendingKey); });
    html += `</div>`;
  }

  html += _renderUploadZones(counts, '', 0, pendingKey);
  html += `</div>`;
  container.innerHTML = html;
}

// ── Carte d'un document existant (avec preview) ─────────────────────────────
function _renderDocCard(d, entiteType, entiteId) {
  const isImg = _isPhoto(d.TypeMime);
  const isPdf = _isPdf(d.TypeMime);
  const previewUrl = _previewUrl(d.Id);
  const safeNom = (d.NomFichier||'').replace(/'/g, "\\'").replace(/"/g, '&quot;');

  let thumbHtml;
  if (isImg) {
    thumbHtml = `<img src="${previewUrl}" style="width:100%;height:100%;object-fit:cover;border-radius:6px 6px 0 0;cursor:pointer" onclick="previewDocModal(${d.Id},'image','${safeNom}')" loading="lazy" alt="${safeNom}">`;
  } else if (isPdf) {
    thumbHtml = `<div style="width:100%;height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;background:#fef2f2;border-radius:6px 6px 0 0;cursor:pointer" onclick="previewDocModal(${d.Id},'pdf','${safeNom}')" title="Cliquer pour prévisualiser">
      <span style="font-size:32px">📄</span>
      <span style="font-size:9px;color:var(--red);font-weight:600;margin-top:2px">PDF</span>
    </div>`;
  } else {
    thumbHtml = `<div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:var(--gray-bg);border-radius:6px 6px 0 0;font-size:28px">📎</div>`;
  }

  // Action bouton bas : téléchargement
  const actionBtn = `<button onclick="DocumentsApi.download(${d.Id},'${safeNom}')" style="background:none;border:none;cursor:pointer;font-size:14px;padding:0" title="Télécharger">⬇️</button>`;

  return `
  <div style="width:130px;border:1px solid var(--gray-border);border-radius:8px;overflow:hidden;background:white;flex-shrink:0">
    <div style="width:100%;height:90px;overflow:hidden;position:relative">
      ${thumbHtml}
      ${canEdit() ? `<button onclick="event.stopPropagation();supprimerDoc(${d.Id},'${entiteType}',${entiteId})" style="position:absolute;top:4px;right:4px;background:rgba(220,53,69,.85);color:white;border:none;border-radius:50%;width:22px;height:22px;font-size:12px;cursor:pointer;display:flex;align-items:center;justify-content:center" title="Supprimer">×</button>` : ''}
    </div>
    <div style="padding:6px 8px">
      <div style="font-size:11px;font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${d.NomFichier||''}">${d.NomFichier||'—'}</div>
      <div style="font-size:10px;color:var(--gray-text);display:flex;justify-content:space-between;align-items:center;margin-top:2px">
        <span>${fmtTaille(d.Taille)}</span>
        ${actionBtn}
      </div>
    </div>
  </div>`;
}

// ── Carte d'un fichier pending (avec preview locale) ─────────────────────────
function _renderPendingCard(p, idx, pendingKey) {
  const isImg = _isPhoto(p.mime);
  const isPdf = _isPdf(p.mime);

  // Créer un blob URL pour la preview (évite les attributs onclick énormes)
  if (!p._blobUrl && (isImg || isPdf)) {
    p._blobUrl = _makeBlobUrl(p.data, p.mime);
  }

  let thumbHtml;
  if (isImg && p._blobUrl) {
    thumbHtml = `<img src="${p._blobUrl}" style="width:100%;height:100%;object-fit:cover;border-radius:6px 6px 0 0;cursor:pointer" onclick="previewPendingDoc('${pendingKey}',${idx})" loading="lazy">`;
  } else if (isPdf) {
    thumbHtml = `<div style="width:100%;height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;background:#fff3cd;border-radius:6px 6px 0 0;cursor:pointer" onclick="previewPendingDoc('${pendingKey}',${idx})" title="Cliquer pour prévisualiser">
      <span style="font-size:32px">📄</span>
      <span style="font-size:9px;color:var(--orange);font-weight:600;margin-top:2px">PDF</span>
    </div>`;
  } else {
    thumbHtml = `<div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:#fff3cd;border-radius:6px 6px 0 0;font-size:28px">📎</div>`;
  }

  return `
  <div style="width:130px;border:1px dashed var(--orange);border-radius:8px;overflow:hidden;background:#fffbeb;flex-shrink:0">
    <div style="width:100%;height:90px;overflow:hidden;position:relative">
      ${thumbHtml}
      <button onclick="event.stopPropagation();removePendingDoc('${pendingKey}',${idx})" style="position:absolute;top:4px;right:4px;background:rgba(220,53,69,.85);color:white;border:none;border-radius:50%;width:22px;height:22px;font-size:12px;cursor:pointer;display:flex;align-items:center;justify-content:center" title="Retirer">×</button>
      <div style="position:absolute;bottom:0;left:0;right:0;background:rgba(245,158,11,.85);color:white;text-align:center;font-size:9px;font-weight:600;padding:2px 0">EN ATTENTE</div>
    </div>
    <div style="padding:6px 8px">
      <div style="font-size:11px;font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${p.name}">${p.name}</div>
    </div>
  </div>`;
}

// ── Zones d'upload ───────────────────────────────────────────────────────────
function _renderUploadZones(counts, entiteType, entiteId, pendingKey) {
  if (!canEdit()) return '';
  const categories = [
    { key: 'pdf',   label: 'PDF',    accept: '.pdf,application/pdf', icon: '📄' },
    { key: 'photo', label: 'Photos', accept: 'image/*',              icon: '🖼️' },
    { key: 'autre', label: 'Autres', accept: '*',                    icon: '📎' },
  ];

  let html = `<div style="margin-top:6px;display:flex;flex-wrap:wrap;gap:8px">`;
  categories.forEach(cat => {
    const count = counts[cat.key] || 0;
    const limite = DOC_LIMITES[cat.key];
    const plein = count >= limite;
    const isExisting = !!entiteId;
    html += `
    <label style="display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border:1px dashed ${plein?'var(--gray-border)':'var(--blue)'};border-radius:6px;font-size:12px;cursor:${plein?'not-allowed':'pointer'};color:${plein?'var(--gray-text)':'var(--blue)'};background:${plein?'var(--gray-bg)':'white'};transition:background .15s"
      title="${plein?'Limite atteinte':'Ajouter un fichier'}"
      onmouseover="if(!this.querySelector('input').disabled)this.style.background='var(--gray-bg)'" onmouseout="this.style.background='${plein?'var(--gray-bg)':'white'}'">
      ${cat.icon} + ${cat.label} <span style="font-size:11px;color:var(--gray-text)">${count}/${limite}</span>
      <input type="file" accept="${cat.accept}" ${plein?'disabled':''} style="display:none"
        onchange="${isExisting ? `uploaderDoc(this,'${entiteType}',${entiteId},'${cat.key}')` : `addPendingDoc(this,'${pendingKey}','${cat.key}')`}">
    </label>`;
  });

  html += `</div>`;
  return html;
}

// ── Upload direct (entité existante) ─────────────────────────────────────────
async function uploaderDoc(input, entiteType, entiteId, categorie) {
  const file = input.files?.[0];
  if (!file) return;

  const mime = file.type || 'application/octet-stream';
  const b64 = await new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload  = e => resolve(e.target.result.split(',')[1]);
    reader.onerror = () => reject(new Error('Lecture impossible'));
    reader.readAsDataURL(file);
  });
  input.value = '';

  try {
    await DocumentsApi.upload(entiteType, entiteId, file.name, mime, categorie, b64);
    toast(`${file.name} ajouté.`, 'success');
    const panelEl = document.getElementById(`docs_${entiteType}_${entiteId}`);
    if (panelEl) await renderDocumentsPanel(entiteType, entiteId, panelEl, entiteType);
  } catch(e) { toast(e.message || 'Erreur upload', 'error'); }
}

// ── Ajouter un fichier à la file d'attente ───────────────────────────────────
function addPendingDoc(input, pendingKey, categorie) {
  const file = input.files?.[0];
  if (!file) return;

  const reader = new FileReader();
  reader.onload = () => {
    const base64 = reader.result.split(',')[1];
    _getPending(pendingKey).push({ name: file.name, mime: file.type, cat: categorie, data: base64 });
    _refreshPendingPanel(pendingKey);
  };
  reader.readAsDataURL(file);
  input.value = '';
}

// ── Retirer un fichier pending ───────────────────────────────────────────────
function removePendingDoc(pendingKey, idx) {
  const arr = _getPending(pendingKey);
  if (arr[idx]?._blobUrl) URL.revokeObjectURL(arr[idx]._blobUrl);
  arr.splice(idx, 1);
  _refreshPendingPanel(pendingKey);
}

// ── Rafraîchir le panel pending ──────────────────────────────────────────────
function _refreshPendingPanel(pendingKey) {
  let el = document.getElementById(`docs_pending_${pendingKey}`);
  if (el) { renderPendingPanel(pendingKey, el); return; }
  const panels = document.querySelectorAll(`[data-pending-key="${pendingKey}"]`);
  panels.forEach(p => {
    const eid = parseInt(p.dataset.entityId || '0');
    const etype = p.dataset.docsPanel || '';
    if (eid) renderDocumentsPanel(etype, eid, p, pendingKey);
    else renderPendingPanel(pendingKey, p);
  });
}

// ── Envoyer les fichiers en attente après sauvegarde ─────────────────────────
async function uploadPendingDocs(pendingKey, entiteType, newId) {
  const pending = _getPending(pendingKey);
  if (!pending.length || !newId) return;

  let success = 0;
  for (const p of pending) {
    try {
      await DocumentsApi.upload(entiteType, newId, p.name, p.mime, p.cat || docCategorie(p.mime), p.data);
      success++;
    } catch(e) { console.warn('Doc upload error:', p.name, e); }
  }
  // Libérer blob URLs
  pending.forEach(p => { if (p._blobUrl) URL.revokeObjectURL(p._blobUrl); });
  window._docsPending[pendingKey] = [];
  if (success > 0) toast(`${success} document${success>1?'s':''} joint${success>1?'s':''}.`, 'success');
}

// ── Suppression ──────────────────────────────────────────────────────────────
async function supprimerDoc(docId, entiteType, entiteId) {
  showConfirm('Supprimer ce document ?', async () => {
    try {
      await DocumentsApi.delete(docId);
      toast('Document supprimé.', 'success');
      const panelEl = document.getElementById(`docs_${entiteType}_${entiteId}`);
      if (panelEl) await renderDocumentsPanel(entiteType, entiteId, panelEl, entiteType);
    } catch(e) { toast(e.message, 'error'); }
  });
}

// ── Modale d'aperçu SÉPARÉE (empilée par-dessus la fiche) ────────────────────
// La modale principale de Larka est partagée : afficher un aperçu avec openModal
// écrasait le contenu de la fiche, et « Fermer » ramenait à la liste. On utilise
// donc une modale dédiée qui se superpose ; la fermer revient à la fiche.
function _spOpenViewer(title, bodyHTML) {
  _spCloseViewer();
  const ov = document.createElement('div');
  ov.id = 'spViewerOverlay';
  ov.style.cssText = 'position:fixed;inset:0;z-index:100000;background:rgba(6,18,34,.6);display:flex;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(2px)';
  ov.innerHTML = `
    <div style="background:#fff;border-radius:14px;width:min(1000px,96vw);max-height:92vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.4);overflow:hidden">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:13px 18px;border-bottom:1px solid var(--gray-border)">
        <div style="font-size:15px;font-weight:700;color:#0d2137;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${title}</div>
        <button id="spViewerCloseBtn" title="Fermer" style="background:#eef2f6;border:none;border-radius:8px;width:32px;height:32px;min-width:32px;cursor:pointer;font-size:15px;color:#33475b">✕</button>
      </div>
      <div style="padding:16px;overflow:auto;flex:1">${bodyHTML}</div>
    </div>`;
  ov.addEventListener('click', e => { if (e.target === ov) _spCloseViewer(); });
  document.body.appendChild(ov);
  const cb = document.getElementById('spViewerCloseBtn');
  if (cb) cb.onclick = _spCloseViewer;
  ov._escHandler = (e) => { if (e.key === 'Escape') _spCloseViewer(); };
  document.addEventListener('keydown', ov._escHandler);
}
function _spCloseViewer() {
  const ov = document.getElementById('spViewerOverlay');
  if (ov) {
    if (ov._escHandler) document.removeEventListener('keydown', ov._escHandler);
    ov.remove();
  }
}

// ── Modal de prévisualisation (doc existant uploadé) ─────────────────────────
// Images : affichées intégrées (jamais bloquées). PDF & autres : ouverts dans
// un nouvel onglet, où le navigateur les rend nativement — bypass fiable des
// politiques d'encadrement (iframe/CSP) de l'environnement.
function previewDocModal(docId, type, filename) {
  const url = _previewUrl(docId);
  if (type === 'image') {
    _spOpenViewer(`👁️ ${filename||'Document'}`, `<div style="display:flex;align-items:center;justify-content:center;height:70vh;overflow:auto">
      <img src="${url}" style="max-width:100%;max-height:100%;object-fit:contain;border-radius:6px;box-shadow:0 4px 24px rgba(0,0,0,.15)">
    </div>`);
  } else {
    window.open(url, '_blank');
  }
}

// ── Preview d'un fichier pending (blob URL) ──────────────────────────────────
function previewPendingDoc(pendingKey, idx) {
  const p = _getPending(pendingKey)[idx];
  if (!p) return;
  if (!p._blobUrl) p._blobUrl = _makeBlobUrl(p.data, p.mime);

  const type = _isPhoto(p.mime) ? 'image' : _isPdf(p.mime) ? 'pdf' : 'other';
  let contentHtml;
  if (type === 'image') {
    contentHtml = `<div style="display:flex;align-items:center;justify-content:center;height:75vh;overflow:auto">
      <img src="${p._blobUrl}" style="max-width:100%;max-height:100%;object-fit:contain;border-radius:6px;box-shadow:0 4px 24px rgba(0,0,0,.15)">
    </div>`;
  } else if (type === 'pdf') {
    contentHtml = `<iframe src="${p._blobUrl}" style="width:100%;height:75vh;border:none;border-radius:6px"></iframe>`;
  } else {
    contentHtml = `<div style="padding:40px;text-align:center;color:var(--gray-text)">Prévisualisation non disponible.</div>`;
  }
  _spOpenViewer(`👁️ ${p.name||'Document'}`, contentHtml);
}

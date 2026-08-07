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

// Cache local des métadonnées des documents affichés. Sert exclusivement au
// repli hors ligne des liens SharePoint (_spFicheHorsLigne) : il ne contient
// que ce que renvoie l'API — nom, type, taille, catégorie, dates — jamais le
// contenu des fichiers.
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
  const isSP  = !!(d.SharePointUrl);
  const isImg = _isPhoto(d.TypeMime);
  const isPdf = _isPdf(d.TypeMime);
  const previewUrl = _previewUrl(d.Id);
  const safeNom = (d.NomFichier||'').replace(/'/g, "\\'").replace(/"/g, '&quot;');

  let thumbHtml;
  if (isSP) {
    // Lien SharePoint : icône SP distinctive, clic = aperçu intégré
    const spKind = isImg ? 'image' : isPdf ? 'pdf' : 'other';
    thumbHtml = `<div style="width:100%;height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;background:#e8f4f8;border-radius:6px 6px 0 0;cursor:pointer" onclick="_spPreviewSPDoc(${d.Id},'${spKind}','${safeNom}')" title="Cliquer pour prévisualiser">
      <span style="font-size:28px">📌</span>
      <span style="font-size:8px;color:#0078d4;font-weight:700;margin-top:2px;letter-spacing:.3px">SHAREPOINT</span>
    </div>`;
  } else if (isImg) {
    thumbHtml = `<img src="${previewUrl}" style="width:100%;height:100%;object-fit:cover;border-radius:6px 6px 0 0;cursor:pointer" onclick="previewDocModal(${d.Id},'image','${safeNom}')" loading="lazy" alt="${safeNom}">`;
  } else if (isPdf) {
    thumbHtml = `<div style="width:100%;height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;background:#fef2f2;border-radius:6px 6px 0 0;cursor:pointer" onclick="previewDocModal(${d.Id},'pdf','${safeNom}')" title="Cliquer pour prévisualiser">
      <span style="font-size:32px">📄</span>
      <span style="font-size:9px;color:var(--red);font-weight:600;margin-top:2px">PDF</span>
    </div>`;
  } else {
    thumbHtml = `<div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:var(--gray-bg);border-radius:6px 6px 0 0;font-size:28px">📎</div>`;
  }

  // Action bouton bas : ouvrir SP ou télécharger
  let actionBtn;
  if (isSP) {
    actionBtn = `<span style="display:inline-flex;gap:8px;align-items:center">
      <button onclick="ouvrirDocSharePoint(${d.Id})" style="background:none;border:none;cursor:pointer;font-size:14px;padding:0" title="Ouvrir dans SharePoint">🔗</button>
      ${_spLectureSeule ? `<span style="font-size:12px;opacity:.5" title="Consultation seule — téléchargement désactivé">👁️</span>` : `<a href="api/index.php?action=sharepoint_download&id=${d.Id}" style="text-decoration:none;font-size:14px;line-height:1" title="Télécharger">⬇️</a>`}
    </span>`;
  } else {
    actionBtn = `<button onclick="DocumentsApi.download(${d.Id},'${safeNom}')" style="background:none;border:none;cursor:pointer;font-size:14px;padding:0" title="Télécharger">⬇️</button>`;
  }

  return `
  <div style="width:130px;border:1px solid ${isSP?'#0078d4':'var(--gray-border)'};border-radius:8px;overflow:hidden;background:white;flex-shrink:0">
    <div style="width:100%;height:90px;overflow:hidden;position:relative">
      ${thumbHtml}
      ${canEdit() ? `<button onclick="event.stopPropagation();supprimerDoc(${d.Id},'${entiteType}',${entiteId})" style="position:absolute;top:4px;right:4px;background:rgba(220,53,69,.85);color:white;border:none;border-radius:50%;width:22px;height:22px;font-size:12px;cursor:pointer;display:flex;align-items:center;justify-content:center" title="Supprimer">×</button>` : ''}
    </div>
    <div style="padding:6px 8px">
      <div style="font-size:11px;font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${d.NomFichier||''}">${d.NomFichier||'—'}</div>
      <div style="font-size:10px;color:var(--gray-text);display:flex;justify-content:space-between;align-items:center;margin-top:2px">
        <span>${isSP ? '📌 SP' : fmtTaille(d.Taille)}</span>
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

  // Bouton SharePoint (uniquement si entité existante — on a besoin de l'ID pour lier)
  if (entiteId) {
    html += `
    <button type="button" id="btnSharePoint_${entiteType}_${entiteId}"
      style="display:none;align-items:center;gap:6px;padding:6px 12px;border:1px dashed #0078d4;border-radius:6px;font-size:12px;cursor:pointer;color:#0078d4;background:white;transition:background .15s"
      onmouseover="this.style.background='#e8f4f8'" onmouseout="this.style.background='white'"
      onclick="ouvrirNavigateurSharePoint('${entiteType}',${entiteId})"
      title="Lier un fichier depuis SharePoint (raccourci, sans copie)">
      📌 Lier depuis SharePoint
    </button>`;
    // Vérifier si SharePoint est disponible (async, le bouton s'affiche si oui)
    setTimeout(() => _checkSharePointAvailable(entiteType, entiteId), 200);
  }

  html += `</div>`;
  return html;
}

// Mode consultation seule (renseigné par sharepoint_status). Purement cosmétique :
// le refus effectif du téléchargement est appliqué côté serveur.
let _spLectureSeule = false;

// ── Vérifier si SharePoint est activé pour afficher le bouton ─────────────────
async function _checkSharePointAvailable(entiteType, entiteId) {
  try {
    const status = await SharePointApi.status();
    _spLectureSeule = !!status.lectureSeule;
    if (_spLectureSeule) {
      // Retirer les liens de téléchargement déjà rendus dans les cartes.
      document.querySelectorAll('a[href*="sharepoint_download"]').forEach(a => a.remove());
    }
    if (status.enabled && status.hasToken) {
      const btn = document.getElementById(`btnSharePoint_${entiteType}_${entiteId}`);
      if (btn) btn.style.display = 'inline-flex';
    }
  } catch(_) { /* SharePoint non dispo, on n'affiche rien */ }
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

// Aperçu d'un document SharePoint via le proxy Larka (Microsoft Graph). Le
// contenu est servi en même origine, donc affichable en iframe/img (nécessite
// frame-ancestors 'self', forcé côté serveur). PDF direct, images intégrées,
// Office (Word/Excel/PowerPoint) converti en PDF par Graph (format=pdf).
//
// Le contenu n'est JAMAIS recopié dans Larka : il est streamé à la demande.
// Conséquence assumée : si SharePoint est injoignable, on ne peut pas afficher
// le fichier. On bascule alors sur la fiche hors ligne (_spFicheHorsLigne),
// construite à partir des métadonnées stockées localement au moment du lien.
async function _spPreviewSPDoc(docId, kind, name) {
  let etat = null;
  try {
    etat = await SharePointApi.check(docId);
  } catch (_) {
    etat = { disponible: false, raison: 'SharePoint est injoignable.', nom: name };
  }

  if (!etat || !etat.disponible) {
    _spFicheHorsLigne(docId, name, etat || {});
    return;
  }

  const lectureSeule = !!etat.lectureSeule;
  const rawUrl = `api/index.php?action=sharepoint_content&id=${docId}`;
  const ext    = (name||'').split('.').pop().toLowerCase();
  const isOffice = ['doc','docx','xls','xlsx','ppt','pptx'].includes(ext);
  const safe   = (name||'Document');
  const barre  = `<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:10px;flex-wrap:wrap">
        ${lectureSeule
          ? `<span style="font-size:11px;color:var(--gray-text);align-self:center">👁️ Consultation seule — téléchargement désactivé</span>`
          : `<a href="api/index.php?action=sharepoint_download&id=${docId}" class="btn btn-ghost btn-sm" style="text-decoration:none">⬇️ Télécharger</a>`}
        <button class="btn btn-ghost btn-sm" onclick="ouvrirDocSharePoint(${docId})">🔗 Ouvrir dans SharePoint</button>
      </div>`;

  if (kind === 'image') {
    const content = `<div style="display:flex;align-items:center;justify-content:center;height:68vh;overflow:auto">
        <img src="${rawUrl}" style="max-width:100%;max-height:100%;object-fit:contain;border-radius:6px;box-shadow:0 4px 24px rgba(0,0,0,.15)" alt="${safe.replace(/"/g,'&quot;')}">
      </div>
      ${barre}`;
    _spOpenViewer(`👁️ ${safe}`, content);
  } else if (isOffice) {
    // Office → converti en PDF par Graph, ouvert dans un nouvel onglet.
    window.open(`${rawUrl}&format=pdf`, '_blank');
  } else {
    // PDF et autres : ouverture native dans un nouvel onglet.
    window.open(rawUrl, '_blank');
  }
}

// Fiche affichée quand SharePoint ne répond pas. Tout ce qui est montré ici
// vient de la base Larka (métadonnées enregistrées au moment du lien), donc
// reste disponible indéfiniment sans connexion à SharePoint.
function _spFicheHorsLigne(docId, name, etat) {
  const d = (_docsCache || []).find(x => x.Id === docId) || {};
  const nom  = etat.nom || d.NomFichier || name || 'Document';
  const url  = etat.url || d.SharePointUrl || '';
  const maj  = etat.derniereMaj || d.SharePointLastSync || '';
  const majF = maj ? new Date(maj.replace(' ', 'T')).toLocaleString('fr-FR') : null;
  const ligne = (label, val) => val
    ? `<div style="display:flex;gap:8px;padding:3px 0"><span style="color:var(--gray-text);min-width:110px">${label}</span><span style="font-weight:500">${escHtml(String(val))}</span></div>`
    : '';

  const content = `
    <div style="display:flex;flex-direction:column;gap:14px">
      <div style="padding:12px 14px;background:var(--gray-bg);border-radius:8px;border-left:3px solid var(--orange);font-size:13px;line-height:1.5">
        ⚠️ <strong>Aperçu indisponible</strong><br>
        <span style="color:var(--gray-text)">${escHtml(etat.raison || 'SharePoint est injoignable.')}
        Le contenu du fichier n'est pas stocké dans Larka — il reste sur SharePoint — donc il ne peut pas être affiché tant que le service ne répond pas.</span>
      </div>

      <div style="font-size:13px">
        <div style="font-weight:600;margin-bottom:6px">📌 Ce que Larka sait de ce fichier</div>
        ${ligne('Nom',        nom)}
        ${ligne('Type',       d.TypeMime)}
        ${ligne('Taille',     d.Taille ? _fmtTaille(d.Taille) : '')}
        ${ligne('Catégorie',  d.Categorie)}
        ${ligne('Ajouté par', d.AjoutePar)}
        ${ligne('Ajouté le',  d.DateAjout ? new Date(String(d.DateAjout).replace(' ', 'T')).toLocaleString('fr-FR') : '')}
        ${majF ? `<div style="margin-top:8px;font-size:11px;color:var(--gray-text)">Informations confirmées auprès de SharePoint le ${escHtml(majF)}.</div>` : ''}
      </div>

      ${url ? `<div>
        <a href="${escHtml(url)}" target="_blank" rel="noopener" class="btn btn-ghost btn-sm" style="text-decoration:none">🔗 Tenter l'ouverture directe dans SharePoint</a>
        <div style="font-size:11px;color:var(--gray-text);margin-top:5px">L'adresse est conservée localement : elle fonctionnera dès que SharePoint sera de nouveau joignable.</div>
      </div>` : ''}
    </div>`;

  openModal(`📄 ${nom}`, content, null, null, 'Fermer');
}

// Taille lisible (repli local, sans dépendance à SharePoint).
function _fmtTaille(o) {
  const n = parseInt(o) || 0;
  if (n < 1024) return n + ' o';
  if (n < 1048576) return (n / 1024).toFixed(0) + ' Ko';
  return (n / 1048576).toFixed(1) + ' Mo';
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

// ═══════════════════════════════════════════════════════════════════════════════
//  NAVIGATEUR SHAREPOINT (modal — autodétection par compte Microsoft)
// ═══════════════════════════════════════════════════════════════════════════════
//
// L'utilisateur voit ce que son compte Microsoft lui donne accès :
// - Son OneDrive personnel
// - Les sites SharePoint qu'il suit (favoris)
// - Recherche libre de sites
// Flux : Accueil → [Site ou OneDrive] → Drive → Dossiers → Fichier → Lien créé
//

let _spContext = {};

async function ouvrirNavigateurSharePoint(entiteType, entiteId) {
  _spContext = { entiteType, entiteId, siteId: '', driveId: '', breadcrumb: [] };

  const content = `
    <div id="spNav" style="min-height:300px">
      <div id="spToolbar" style="display:flex;gap:8px;align-items:center;margin-bottom:12px;flex-wrap:wrap">
        <div id="spBreadcrumb" style="font-size:12px;color:var(--gray-text);flex:1"></div>
        <div id="spSearchBox" style="position:relative;flex:0 0 220px;display:none">
          <input type="text" id="spSearchInput" class="form-control" placeholder="Rechercher…"
            style="font-size:12px;padding:6px 10px 6px 30px" onkeydown="if(event.key==='Enter')rechercherSharePoint()">
          <span style="position:absolute;left:8px;top:50%;transform:translateY(-50%);font-size:14px;pointer-events:none">🔍</span>
        </div>
      </div>
      <div id="spContent" style="display:flex;align-items:center;justify-content:center;min-height:200px">
        <div style="text-align:center;color:var(--gray-text)">
          <div style="font-size:24px;margin-bottom:8px">⏳</div>
          <div style="font-size:13px">Connexion à SharePoint…</div>
        </div>
      </div>
    </div>`;

  openModal('📌 Lier depuis SharePoint', content, null, null, 'Fermer');

  try {
    await _spLoadHome();
  } catch(e) {
    _spShowError(e.message || 'Erreur de connexion à SharePoint');
  }
}

function _spShowError(msg) {
  const el = document.getElementById('spContent');
  if (el) el.innerHTML = `<div style="text-align:center;padding:30px;color:#e74c3c">
    <div style="font-size:24px;margin-bottom:8px">❌</div>
    <div style="font-size:13px">${msg}</div>
    <div style="font-size:11px;color:var(--gray-text);margin-top:8px">Vérifiez votre connexion Microsoft.</div>
  </div>`;
}

function _spShowLoading(msg = 'Chargement…') {
  const el = document.getElementById('spContent');
  if (el) el.innerHTML = `<div style="text-align:center;color:var(--gray-text)">
    <div style="font-size:24px;margin-bottom:8px">⏳</div>
    <div style="font-size:13px">${msg}</div>
  </div>`;
}

function _spUpdateBreadcrumb() {
  const el = document.getElementById('spBreadcrumb');
  if (!el) return;
  let html = `<span style="cursor:pointer;color:var(--blue)" onclick="_spLoadHome()">📌 SharePoint</span>`;
  _spContext.breadcrumb.forEach((b, i) => {
    html += ` <span style="color:var(--gray-text)">›</span> `;
    if (i < _spContext.breadcrumb.length - 1) {
      html += `<span style="cursor:pointer;color:var(--blue)" onclick="_spGoToLevel(${i})">${b.label}</span>`;
    } else {
      html += `<span style="font-weight:600">${b.label}</span>`;
    }
  });
  el.innerHTML = html;
  // Afficher la recherche uniquement quand on est dans un drive
  const searchBox = document.getElementById('spSearchBox');
  if (searchBox) searchBox.style.display = _spContext.driveId ? 'block' : 'none';
}

async function _spGoToLevel(level) {
  try {
    const item = _spContext.breadcrumb[level];
    _spContext.breadcrumb = _spContext.breadcrumb.slice(0, level + 1);
    if (item.type === 'site') {
      _spContext.driveId = '';
      await _spLoadDrives();
    } else if (item.type === 'drive') {
      await _spLoadFiles();
    } else if (item.type === 'folder') {
      await _spLoadFiles(item.itemId);
    }
  } catch(e) { _spShowError(e.message); }
}

// ── Page d'accueil : OneDrive perso + Sites suivis + Recherche sites ─────────
async function _spLoadHome(top) {
  top = parseInt(top, 10) || 50;
  _spContext.breadcrumb = [];
  _spContext.siteId = '';
  _spContext.driveId = '';
  _spShowLoading('Détection de vos accès SharePoint…');
  _spUpdateBreadcrumb();
  await _spEnsureTenant();

  const el = document.getElementById('spContent');
  let html = '<div style="display:flex;flex-direction:column;gap:12px;width:100%">';

  // 0) Recherche de site — EN HAUT et bien visible. C'est le moyen fiable de
  //    trouver un site précis (ex. « BATIMENTS ») sans dérouler tout le tenant.
  html += `<div>
    <div style="font-size:11px;font-weight:700;color:var(--gray-text);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">🔎 Rechercher</div>
    <div style="display:flex;gap:8px;align-items:center">
      <input type="text" id="spSiteSearch" class="form-control" placeholder="Nom d'un fichier, dossier ou site…"
        style="font-size:13px;flex:1" onkeydown="if(event.key==='Enter')_spSearchAll()">
      <button class="btn btn-primary btn-sm" onclick="_spSearchAll()">Chercher</button>
    </div>
    <div style="font-size:10px;color:var(--gray-text);margin-top:4px">Cherche dans tout SharePoint : fichiers, dossiers et sites.</div>
  </div>`;

  // 0bis) Vos favoris (stockés localement, indépendants de SharePoint)
  html += `<div id="spFavSection">${_spFavSectionInner()}</div>`;

  // 1) OneDrive personnel
  try {
    const myDrives = await SharePointApi.mydrives();
    if (myDrives.length > 0) {
      html += `<div>
        <div style="font-size:11px;font-weight:700;color:var(--gray-text);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">💾 Mon OneDrive</div>
        <div style="display:flex;flex-direction:column;gap:4px">`;
      myDrives.forEach(d => { html += _spDriveItem(d, ''); });
      html += `</div></div>`;
    }
  } catch(_) {}

  // 2) Sites. Comportement adaptatif :
  //    - si vous avez des favoris → vue épurée + bouton vers la liste complète ;
  //    - sinon → on affiche directement les sites accessibles (sinon l'écran
  //      paraît vide), avec une astuce pour les mettre en favoris.
  //    Note : avec la permission Sites.Read.All on ne peut pas deviner « vos »
  //    sites automatiquement ; les favoris sont votre liste personnelle.
  const _favCount = _spFavGet().length;
  if (_favCount > 0) {
    html += `<div style="margin-top:2px">
      <button class="btn btn-ghost btn-sm" style="font-size:12px" onclick="_spShowAllSites()">🌐 Parcourir tous les sites disponibles…</button>
    </div>`;
  } else {
    try {
      const allSites = await SharePointApi.sites('*', top);
      if (allSites.length) {
        html += `<div>
          <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:6px">
            <div style="font-size:11px;font-weight:700;color:var(--gray-text);text-transform:uppercase;letter-spacing:.5px">Sites accessibles</div>
            ${_spCountSelector(top, '_spLoadHome')}
          </div>
          <div style="font-size:11px;color:var(--gray-text);margin-bottom:6px">Astuce : cliquez sur ★ pour ajouter un site à vos favoris et le retrouver tout en haut la prochaine fois.</div>
          ${_spRenderSiteGroups(allSites)}
        </div>`;
      } else {
        html += `<div style="font-size:12px;color:var(--gray-text);padding:2px">Aucun site SharePoint accessible. Utilisez la recherche ci-dessus si vous connaissez le nom d'un site.</div>`;
      }
    } catch(e) {
      html += `<div style="text-align:center;padding:20px;color:var(--gray-text)">Impossible de charger les sites : ${e.message||''}</div>`;
    }
  }

  html += '</div>';
  el.innerHTML = html;
  const _si = document.getElementById('spSiteSearch'); if (_si) _si.focus();
}

// Liste complète des sites du tenant — chargée uniquement au clic sur
// « Voir tous les sites disponibles » (évite d'exposer tout le tenant d'entrée).
async function _spShowAllSites(top) {
  top = parseInt(top, 10) || 50;
  _spShowLoading('Chargement de tous les sites…');
  await _spEnsureTenant();
  try {
    const sites = await SharePointApi.sites('*', top);
    const el = document.getElementById('spContent');
    if (!sites.length) {
      el.innerHTML = `<div style="text-align:center;padding:30px;color:var(--gray-text)">Aucun site accessible.</div>
        <div style="text-align:center"><button class="btn btn-ghost btn-sm" onclick="_spLoadHome()">← Retour</button></div>`;
      return;
    }
    el.innerHTML = `<div style="display:flex;flex-direction:column;gap:8px;width:100%">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap">
        <div style="font-size:11px;color:var(--gray-text)">Tous les sites — <span style="cursor:pointer;color:var(--blue)" onclick="_spLoadHome()">← Retour</span></div>
        ${_spCountSelector(top, '_spShowAllSites')}
      </div>
      ${_spRenderSiteGroups(sites)}
    </div>`;
  } catch(e) { _spShowError(e.message); }
}

// ═══ Favoris (sites ET dossiers), stockés en local comme les préférences UI ═══
const SP_FAV_KEY = 'gmao_sp_favoris';
function _spFavGet() {
  try { return JSON.parse(localStorage.getItem(SP_FAV_KEY)) || []; } catch(_) { return []; }
}
function _spFavSave(list) {
  try { localStorage.setItem(SP_FAV_KEY, JSON.stringify(list)); } catch(_) {}
}
// Clé unique par favori. Rétrocompat : un ancien favori sans "kind" = un site.
function _spFavKey(f) {
  return (f && f.kind === 'folder')
    ? 'folder:' + (f.driveId || '') + ':' + (f.itemId || '')
    : 'site:' + (f.id || '');
}
function _spFavHas(key) { return _spFavGet().some(f => _spFavKey(f) === key); }
function _spFavToggleObj(fav, starEl) {
  const list = _spFavGet();
  const key  = _spFavKey(fav);
  const i    = list.findIndex(f => _spFavKey(f) === key);
  if (i >= 0) list.splice(i, 1); else list.push(fav);
  _spFavSave(list);
  if (starEl) {
    const on = _spFavHas(key);
    starEl.textContent = on ? '★' : '☆';
    starEl.style.color = on ? '#f5b301' : 'var(--gray-text)';
    starEl.title = on ? 'Retirer des favoris' : 'Ajouter aux favoris';
  }
  const fs = document.getElementById('spFavSection');
  if (fs) fs.innerHTML = _spFavSectionInner();
}
// Sites (signatures conservées, utilisées par _spSiteItem)
function _spFavIs(id) { return _spFavHas('site:' + id); }
function _spFavToggle(id, name, url, starEl) {
  _spFavToggleObj({ kind: 'site', id: id, name: name || '', url: url || '' }, starEl);
}
// Dossiers
function _spFavToggleFolder(driveId, itemId, name, siteId, siteName, starEl) {
  _spFavToggleObj({ kind: 'folder', driveId: driveId, itemId: itemId, name: name || '', siteId: siteId || '', siteName: siteName || '' }, starEl);
}

function _spFavSectionInner() {
  const favs = _spFavGet();
  if (!favs.length) return '';
  let h = `<div style="font-size:11px;font-weight:700;color:var(--gray-text);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">⭐ Vos favoris</div>
    <div style="display:flex;flex-direction:column;gap:4px">`;
  favs.forEach(f => {
    if (f.kind === 'folder') h += _spFavFolderItem(f);
    else h += _spSiteItem({ id: f.id, name: f.name, url: f.url, followed: false });
  });
  return h + `</div>`;
}

// Ligne « dossier favori » : un clic ouvre directement le dossier.
function _spFavFolderItem(f) {
  const nm  = f.name || 'Dossier';
  const eN  = nm.replace(/'/g, "\\'");
  const eD  = (f.driveId  || '').replace(/'/g, "\\'");
  const eI  = (f.itemId   || '').replace(/'/g, "\\'");
  const eS  = (f.siteId   || '').replace(/'/g, "\\'");
  const eSN = (f.siteName || '').replace(/'/g, "\\'");
  const sub = f.siteName ? ('Dossier · ' + f.siteName) : 'Dossier SharePoint';
  return `<div style="display:flex;align-items:center;gap:10px;padding:8px 12px;border-radius:8px;cursor:pointer;border:1px solid var(--gray-border);background:white;transition:background .15s"
    onmouseover="this.style.background='var(--gray-bg)'" onmouseout="this.style.background='white'"
    onclick="_spOpenFavFolder('${eD}','${eI}','${eN}','${eS}')">
    <span style="font-size:20px">📁</span>
    <div style="flex:1;min-width:0">
      <div style="font-size:13px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${nm}</div>
      <div style="font-size:10px;color:var(--gray-text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${sub}</div>
    </div>
    <span title="Retirer des favoris" style="font-size:17px;line-height:1;cursor:pointer;padding:2px 5px;color:#f5b301"
      onclick="event.stopPropagation();_spFavToggleFolder('${eD}','${eI}','${eN}','${eS}','${eSN}',this)">★</span>
    <span style="font-size:14px;color:var(--gray-text)">›</span>
  </div>`;
}

// Ouvre directement un dossier favori (sans re-naviguer site → bibliothèque).
async function _spOpenFavFolder(driveId, itemId, name, siteId) {
  _spContext.siteId  = siteId || '';
  _spContext.driveId = driveId;
  _spContext.breadcrumb = [{ label: '★ ' + name, type: 'folder', itemId: itemId }];
  await _spLoadFiles(itemId);
}

// ═══ Tenant : host interne + domaine (séparation interne / externe) ═══════════
async function _spEnsureTenant() {
  if (_spContext.tenant) return;
  try { _spContext.tenant = await SharePointApi.tenant(); }
  catch(_) { _spContext.tenant = { host: '', domain: '' }; }
}
function _spHostOf(url) {
  try { return new URL(url).host.toLowerCase(); } catch(_) { return ''; }
}
// Regroupe une liste de sites : internes (même host SharePoint que le tenant)
// d'abord, puis externes/invités. Sans host tenant connu → liste plate.
function _spRenderSiteGroups(sites) {
  const th = (_spContext.tenant && _spContext.tenant.host) ? _spContext.tenant.host.toLowerCase() : '';
  const domain = (_spContext.tenant && _spContext.tenant.domain) ? _spContext.tenant.domain : '';
  if (!th) {
    let h = `<div style="display:flex;flex-direction:column;gap:4px">`;
    sites.forEach(s => { h += _spSiteItem(s); });
    return h + `</div>`;
  }
  const internal = [], external = [];
  sites.forEach(s => { (_spHostOf(s.url) === th ? internal : external).push(s); });
  let h = '';
  if (internal.length) {
    h += `<div><div style="font-size:11px;font-weight:700;color:var(--gray-text);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">🏢 Sites internes${domain ? ' — ' + domain : ''}</div>
      <div style="display:flex;flex-direction:column;gap:4px">`;
    internal.forEach(s => { h += _spSiteItem(s); });
    h += `</div></div>`;
  }
  if (external.length) {
    h += `<div style="margin-top:8px"><div style="font-size:11px;font-weight:700;color:var(--gray-text);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">🌐 Autres sites (externes / invités)</div>
      <div style="display:flex;flex-direction:column;gap:4px">`;
    external.forEach(s => { h += _spSiteItem(s); });
    h += `</div></div>`;
  }
  return h || `<div style="text-align:center;padding:20px;color:var(--gray-text)">Aucun site.</div>`;
}
// Sélecteur du nombre de résultats à afficher.
function _spCountSelector(current, fnName) {
  const opts = [25, 50, 100, 200].map(n => `<option value="${n}" ${n === current ? 'selected' : ''}>${n}</option>`).join('');
  return `<label style="font-size:11px;color:var(--gray-text);display:inline-flex;align-items:center;gap:4px">
    Afficher <select onchange="${fnName}(this.value)" style="font-size:11px;padding:2px 4px;border-radius:6px;border:1px solid var(--gray-border)">${opts}</select> max.
  </label>`;
}

function _spSiteItem(s) {
  const safeName = (s.name||'Sans nom').replace(/'/g, "\\'");
  const safeUrl  = (s.url||'').replace(/'/g, "\\'");
  const isFav = _spFavIs(s.id);
  return `<div style="display:flex;align-items:center;gap:10px;padding:8px 12px;border-radius:8px;cursor:pointer;border:1px solid var(--gray-border);background:white;transition:background .15s"
    onmouseover="this.style.background='var(--gray-bg)'" onmouseout="this.style.background='white'"
    onclick="_spSelectSite('${s.id}','${safeName}')">
    <span style="font-size:20px">${s.followed ? '⭐' : '🌐'}</span>
    <div style="flex:1;min-width:0">
      <div style="font-size:13px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${s.name||'Sans nom'}</div>
      ${s.description ? `<div style="font-size:10px;color:var(--gray-text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${s.description}</div>` : ''}
    </div>
    <span title="${isFav ? 'Retirer des favoris' : 'Ajouter aux favoris'}" style="font-size:17px;line-height:1;cursor:pointer;padding:2px 5px;color:${isFav ? '#f5b301' : 'var(--gray-text)'}"
      onclick="event.stopPropagation();_spFavToggle('${s.id}','${safeName}','${safeUrl}',this)">${isFav ? '★' : '☆'}</span>
    <span style="font-size:14px;color:var(--gray-text)">›</span>
  </div>`;
}

function _spDriveItem(d, siteId) {
  const safeName = (d.name||'Drive').replace(/'/g, "\\'");
  return `<div style="display:flex;align-items:center;gap:10px;padding:8px 12px;border-radius:8px;cursor:pointer;border:1px solid var(--gray-border);background:white;transition:background .15s"
    onmouseover="this.style.background='var(--gray-bg)'" onmouseout="this.style.background='white'"
    onclick="_spSelectDrive('${d.id}','${safeName}','${siteId}')">
    <span style="font-size:20px">${d.type==='personal' ? '💾' : '📚'}</span>
    <div style="flex:1;min-width:0">
      <div style="font-size:13px;font-weight:600">${d.name||'Bibliothèque'}</div>
      <div style="font-size:10px;color:var(--gray-text)">${d.type||''}</div>
    </div>
    <span style="font-size:14px;color:var(--gray-text)">›</span>
  </div>`;
}

async function _spSearchSites(top) {
  const inputVal = document.getElementById('spSiteSearch')?.value?.trim();
  const q = inputVal || _spContext.lastSiteQuery || '';
  if (!q) return;
  _spContext.lastSiteQuery = q;
  top = parseInt(top, 10) || 50;
  // FIX XSS: échapper la saisie utilisateur avant interpolation HTML
  const qEsc = String(q).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  _spShowLoading(`Recherche "${q}"…`);
  await _spEnsureTenant();
  try {
    const sites = await SharePointApi.sites(q, top);
    const el = document.getElementById('spContent');
    if (!sites.length) {
      el.innerHTML = `<div style="text-align:center;padding:30px;color:var(--gray-text)">Aucun site trouvé pour « ${qEsc} ».</div>
        <div style="text-align:center"><button class="btn btn-ghost btn-sm" onclick="_spLoadHome()">← Retour</button></div>`;
      return;
    }
    el.innerHTML = `<div style="display:flex;flex-direction:column;gap:8px;width:100%">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap">
        <div style="font-size:11px;color:var(--gray-text)">Résultats pour « ${qEsc} » — <span style="cursor:pointer;color:var(--blue)" onclick="_spLoadHome()">← Retour</span></div>
        ${_spCountSelector(top, '_spSearchSites')}
      </div>
      ${_spRenderSiteGroups(sites)}
    </div>`;
  } catch(e) { _spShowError(e.message); }
}

async function _spSelectSite(siteId, siteName) {
  _spContext.siteId = siteId;
  _spContext.breadcrumb = [{ label: siteName, type: 'site' }];
  await _spLoadDrives();
}

// Recherche GLOBALE : fichiers/dossiers (API Microsoft Search) + sites.
async function _spSearchAll(top) {
  const inputVal = document.getElementById('spSiteSearch')?.value?.trim();
  const q = inputVal || _spContext.lastSiteQuery || '';
  if (!q) return;
  _spContext.lastSiteQuery = q;
  top = parseInt(top, 10) || 50;
  const qEsc = String(q).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  _spShowLoading(`Recherche « ${q} »…`);
  await _spEnsureTenant();
  try {
    const [files, sites] = await Promise.all([
      SharePointApi.searchFiles(q, top).catch(() => []),
      SharePointApi.sites(q, top).catch(() => []),
    ]);
    const el = document.getElementById('spContent');
    let html = `<div style="display:flex;flex-direction:column;gap:12px;width:100%">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap">
        <div style="font-size:11px;color:var(--gray-text)">Résultats pour « ${qEsc} » — <span style="cursor:pointer;color:var(--blue)" onclick="_spLoadHome()">← Retour</span></div>
        ${_spCountSelector(top, '_spSearchAll')}
      </div>`;
    if (files.length) {
      html += `<div>
        <div style="font-size:11px;font-weight:700;color:var(--gray-text);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">📄 Fichiers &amp; dossiers (${files.length})</div>
        ${_spRenderGlobalFiles(files)}
      </div>`;
    }
    if (sites.length) {
      html += `<div>
        <div style="font-size:11px;font-weight:700;color:var(--gray-text);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px">🏢 Sites</div>
        ${_spRenderSiteGroups(sites)}
      </div>`;
    }
    if (!files.length && !sites.length) {
      html += `<div style="text-align:center;padding:30px;color:var(--gray-text)">Aucun résultat pour « ${qEsc} ».</div>`;
    }
    html += `</div>`;
    el.innerHTML = html;
  } catch(e) { _spShowError(e.message); }
}

// Emplacement lisible d'un résultat : « Site › Dossier › Sous-dossier ».
function _spReadableLoc(webUrl, parentPath) {
  let site = '';
  try {
    const u = new URL(webUrl);
    const m = u.pathname.match(/\/(?:sites|teams)\/([^/]+)/i);
    if (m) site = decodeURIComponent(m[1]);
  } catch(_) {}
  const parts = [];
  if (site) parts.push(site);
  if (parentPath) parts.push(parentPath.replace(/\//g, ' › '));
  return parts.join(' › ');
}

// Rendu des résultats fichiers/dossiers de la recherche globale.
function _spRenderGlobalFiles(items) {
  let html = `<div style="display:flex;flex-direction:column;gap:4px">`;
  items.forEach(f => {
    const icon = f.isFolder ? '📁' : _spFileIcon(f.mimeType, f.name);
    const eN   = (f.name||'').replace(/'/g,"\\'");
    const loc  = _spReadableLoc(f.webUrl, f.parentPath);
    const meta = f.isFolder ? 'Dossier' : fmtTaille(f.size);
    const locLine = loc
      ? `<div style="font-size:10px;color:var(--blue);overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${(loc||'').replace(/"/g,'&quot;')}">📍 ${loc}</div>`
      : '';
    const openLink = f.webUrl
      ? `<a href="${(f.webUrl||'').replace(/"/g,'&quot;')}" target="_blank" rel="noopener" title="Ouvrir dans SharePoint" style="text-decoration:none;font-size:13px;padding:2px 4px" onclick="event.stopPropagation()">↗</a>`
      : '';

    if (f.isFolder) {
      const eD = (f.driveId||'').replace(/'/g,"\\'");
      const eS = (f.siteId||'').replace(/'/g,"\\'");
      const folderFav = _spFavHas('folder:' + f.driveId + ':' + f.id);
      html += `<div style="display:flex;align-items:center;gap:10px;padding:8px 12px;border-radius:8px;cursor:pointer;border:1px solid var(--gray-border);background:white;transition:background .15s"
        onmouseover="this.style.background='var(--gray-bg)'" onmouseout="this.style.background='white'"
        onclick="_spOpenFavFolder('${eD}','${f.id}','${eN}','${eS}')">
        <span style="font-size:20px">📁</span>
        <div style="flex:1;min-width:0">
          <div style="font-size:12px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${f.name}</div>
          <div style="font-size:10px;color:var(--gray-text)">${meta}</div>
          ${locLine}
        </div>
        ${openLink}
        <span title="${folderFav ? 'Retirer des favoris' : 'Ajouter aux favoris'}" style="font-size:16px;line-height:1;cursor:pointer;padding:2px 5px;color:${folderFav ? '#f5b301' : 'var(--gray-text)'}"
          onclick="event.stopPropagation();_spFavToggleFolder('${eD}','${f.id}','${eN}','${eS}','',this)">${folderFav ? '★' : '☆'}</span>
      </div>`;
    } else {
      const safeData = encodeURIComponent(JSON.stringify({ id:f.id, name:f.name, webUrl:f.webUrl, mimeType:f.mimeType, size:f.size, siteId:f.siteId, driveId:f.driveId }));
      html += `<div style="display:flex;align-items:center;gap:10px;padding:8px 12px;border-radius:8px;cursor:pointer;border:1px solid var(--gray-border);background:white;transition:background .15s"
        onmouseover="this.style.background='#e8f4f8';this.style.borderColor='#0078d4'" onmouseout="this.style.background='white';this.style.borderColor='var(--gray-border)'"
        onclick="_spSelectFile('${safeData}', this)">
        <span style="font-size:20px">${icon}</span>
        <div style="flex:1;min-width:0">
          <div style="font-size:12px;font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${f.name}</div>
          <div style="font-size:10px;color:var(--gray-text)">${meta}</div>
          ${locLine}
        </div>
        ${openLink}
        <button data-sp-linkbtn style="background:#0078d4;color:white;border:none;border-radius:4px;padding:4px 10px;font-size:11px;cursor:pointer;font-weight:600;white-space:nowrap"
          onclick="event.stopPropagation();_spSelectFile('${safeData}', this)">📌 Lier</button>
      </div>`;
    }
  });
  return html + `</div>`;
}

// ── Drives ───────────────────────────────────────────────────────────────────
async function _spLoadDrives() {
  _spShowLoading('Chargement des bibliothèques…');
  _spUpdateBreadcrumb();
  try {
    const drives = await SharePointApi.drives(_spContext.siteId);
    const el = document.getElementById('spContent');
    if (!drives.length) {
      el.innerHTML = `<div style="text-align:center;padding:30px;color:var(--gray-text)">Aucune bibliothèque trouvée.</div>`;
      return;
    }
    let html = `<div style="display:flex;flex-direction:column;gap:4px;width:100%">`;
    drives.forEach(d => { html += _spDriveItem(d, _spContext.siteId); });
    html += `</div>`;
    el.innerHTML = html;
  } catch(e) { _spShowError(e.message); }
}

async function _spSelectDrive(driveId, driveName, siteId) {
  _spContext.driveId = driveId;
  if (siteId) _spContext.siteId = siteId;
  _spContext.breadcrumb.push({ label: driveName, type: 'drive' });
  await _spLoadFiles();
}

// ── Fichiers / Dossiers ──────────────────────────────────────────────────────
async function _spLoadFiles(itemId) {
  _spShowLoading('Chargement…');
  _spUpdateBreadcrumb();
  try {
    const items = await SharePointApi.files(_spContext.driveId, itemId || '');
    _spRenderFileList(items);
  } catch(e) { _spShowError(e.message); }
}

async function rechercherSharePoint() {
  const q = document.getElementById('spSearchInput')?.value?.trim();
  if (!q || !_spContext.driveId) return;
  _spShowLoading(`Recherche "${q}"…`);
  try {
    const items = await SharePointApi.search(_spContext.driveId, q);
    _spRenderFileList(items, true);
  } catch(e) { _spShowError(e.message); }
}

function _spRenderFileList(items, isSearch) {
  const el = document.getElementById('spContent');
  if (!items.length) {
    el.innerHTML = `<div style="text-align:center;padding:30px;color:var(--gray-text)">${isSearch ? 'Aucun résultat.' : 'Dossier vide.'}</div>`;
    return;
  }
  const sorted = [...items].sort((a,b) => {
    if (a.isFolder && !b.isFolder) return -1;
    if (!a.isFolder && b.isFolder) return 1;
    return (a.name||'').localeCompare(b.name||'');
  });
  // Contexte pour favoriser un dossier (drive + site courants).
  const _bc0 = (_spContext.breadcrumb && _spContext.breadcrumb[0]) ? _spContext.breadcrumb[0] : null;
  const siteName = (_bc0 && _bc0.type === 'site') ? _bc0.label : '';
  const eSite   = siteName.replace(/'/g, "\\'");
  const eDrive  = (_spContext.driveId || '').replace(/'/g, "\\'");
  const eSiteId = (_spContext.siteId  || '').replace(/'/g, "\\'");

  let html = `<div style="display:flex;flex-direction:column;gap:2px;width:100%;max-height:55vh;overflow-y:auto">`;
  sorted.forEach(f => {
    const icon = f.isFolder ? '📁' : _spFileIcon(f.mimeType, f.name);
    const sizeStr = f.isFolder ? (f.childCount != null ? f.childCount+' éléments' : '') : fmtTaille(f.size);
    const dateStr = f.modified ? new Date(f.modified).toLocaleDateString('fr-FR') : '';
    // En recherche, on montre l'emplacement (chemin du dossier parent).
    const subLine = (isSearch && f.parentPath) ? ('📁 ' + f.parentPath) : sizeStr;
    const eN = (f.name||'').replace(/'/g,"\\'");

    if (f.isFolder) {
      const folderFav = _spFavHas('folder:' + _spContext.driveId + ':' + f.id);
      html += `<div style="display:flex;align-items:center;gap:10px;padding:8px 12px;border-radius:6px;cursor:pointer;transition:background .15s"
        onmouseover="this.style.background='var(--gray-bg)'" onmouseout="this.style.background='transparent'"
        onclick="_spOpenFolder('${f.id}','${eN}')">
        <span style="font-size:20px">${icon}</span>
        <div style="flex:1;min-width:0">
          <div style="font-size:12px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${f.name}</div>
          <div style="font-size:10px;color:var(--gray-text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${subLine}</div>
        </div>
        <span style="font-size:11px;color:var(--gray-text)">${dateStr}</span>
        <span title="${folderFav ? 'Retirer des favoris' : 'Ajouter aux favoris'}" style="font-size:16px;line-height:1;cursor:pointer;padding:2px 5px;color:${folderFav ? '#f5b301' : 'var(--gray-text)'}"
          onclick="event.stopPropagation();_spFavToggleFolder('${eDrive}','${f.id}','${eN}','${eSiteId}','${eSite}',this)">${folderFav ? '★' : '☆'}</span>
        <span style="font-size:14px;color:var(--gray-text)">›</span>
      </div>`;
    } else {
      const safeData = encodeURIComponent(JSON.stringify({ id:f.id, name:f.name, webUrl:f.webUrl, mimeType:f.mimeType, size:f.size, siteId:_spContext.siteId, driveId:_spContext.driveId }));
      html += `<div style="display:flex;align-items:center;gap:10px;padding:8px 12px;border-radius:6px;cursor:pointer;transition:background .15s;border:1px solid transparent"
        onmouseover="this.style.background='#e8f4f8';this.style.borderColor='#0078d4'" onmouseout="this.style.background='transparent';this.style.borderColor='transparent'"
        onclick="_spSelectFile('${safeData}', this)">
        <span style="font-size:20px">${icon}</span>
        <div style="flex:1;min-width:0">
          <div style="font-size:12px;font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${f.name}</div>
          <div style="font-size:10px;color:var(--gray-text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${subLine}</div>
        </div>
        <span style="font-size:11px;color:var(--gray-text)">${dateStr}</span>
        <button data-sp-linkbtn style="background:#0078d4;color:white;border:none;border-radius:4px;padding:4px 10px;font-size:11px;cursor:pointer;font-weight:600;white-space:nowrap"
          onclick="event.stopPropagation();_spSelectFile('${safeData}', this)">📌 Lier</button>
      </div>`;
    }
  });
  html += `</div>`;
  el.innerHTML = html;
}

function _spFileIcon(mime, name) {
  if (!mime) {
    const ext = (name||'').split('.').pop().toLowerCase();
    if (['pdf'].includes(ext)) return '📄';
    if (['doc','docx'].includes(ext)) return '📝';
    if (['xls','xlsx'].includes(ext)) return '📊';
    if (['ppt','pptx'].includes(ext)) return '📽️';
    if (['jpg','jpeg','png','gif','webp','svg'].includes(ext)) return '🖼️';
    if (['zip','rar','7z'].includes(ext)) return '📦';
    return '📎';
  }
  if (mime === 'application/pdf') return '📄';
  if (mime.startsWith('image/')) return '🖼️';
  if (mime.includes('word') || mime.includes('document')) return '📝';
  if (mime.includes('sheet') || mime.includes('excel')) return '📊';
  if (mime.includes('presentation') || mime.includes('powerpoint')) return '📽️';
  return '📎';
}

async function _spOpenFolder(itemId, folderName) {
  _spContext.breadcrumb.push({ label: folderName, type: 'folder', itemId });
  await _spLoadFiles(itemId);
}

async function _spSelectFile(encodedData, el) {
  let file;
  try { file = JSON.parse(decodeURIComponent(encodedData)); } catch(_) { return; }
  const { entiteType, entiteId } = _spContext;

  // Ligne cliquée (pour la griser une fois liée).
  const row = el ? (el.closest('div') || el) : null;
  if (row) { row.style.opacity = '0.55'; row.style.pointerEvents = 'none'; }

  try {
    await SharePointApi.link(entiteType, entiteId, file);
    toast(`📌 ${file.name} lié.`, 'success');
    if (row) {
      row.style.opacity = '1';
      row.style.background = '#eef7ee';
      row.style.borderColor = '#2e7d32';
      row.style.cursor = 'default';
      row.onclick = null;
      const btn = row.querySelector('[data-sp-linkbtn]');
      if (btn) btn.outerHTML = `<span style="color:#2e7d32;font-size:11px;font-weight:700;white-space:nowrap">✓ Lié</span>`;
    }
    // Rafraîchit le panneau en arrière-plan ; la modale reste ouverte pour lier
    // d'autres fichiers. Pour retirer un doc, on le supprime dans le panneau.
    const panelEl = document.getElementById(`docs_${entiteType}_${entiteId}`);
    if (panelEl) renderDocumentsPanel(entiteType, entiteId, panelEl, entiteType);
  } catch(e) {
    if (row) { row.style.opacity = '1'; row.style.pointerEvents = 'auto'; }
    toast(e.message || 'Erreur lors de la liaison SharePoint', 'error');
  }
}

// ═══════════════════════════════════════════════════════════════════════════════
//  OUVERTURE D'UN DOCUMENT SHAREPOINT (par identifiant stable)
// ═══════════════════════════════════════════════════════════════════════════════
// On ne se fie plus à l'URL figée au moment de la liaison (elle casse dès qu'on
// renomme/déplace le fichier). On demande au serveur l'URL ACTUELLE à partir de
// l'identifiant permanent, puis on ouvre le fichier.
//   • Une fenêtre d'attente apparaît si la récupération dépasse ~2,5 s, avec un
//     bouton « Annuler ».
//   • Si le fichier n'existe plus (supprimé/déplacé), un message dédié le dit.

async function ouvrirDocSharePoint(docId) {
  // Onglet ouvert IMMÉDIATEMENT (dans le geste de clic) pour ne pas être bloqué
  // par le navigateur ; on y met un court message le temps de résoudre l'URL.
  let win = null;
  try {
    win = window.open('', '_blank');
    if (win && win.document) {
      win.document.write(
        '<!doctype html><meta charset="utf-8"><title>Ouverture…</title>' +
        '<body style="font-family:system-ui,Segoe UI,sans-serif;background:#0d2137;color:#e2eaf4;' +
        'display:flex;align-items:center;justify-content:center;height:100vh;margin:0">' +
        '<div style="text-align:center"><div style="font-size:34px">📌</div>' +
        '<p style="opacity:.85">Récupération du fichier SharePoint…</p></div>'
      );
    }
  } catch (_) { win = null; }

  const controller  = (typeof AbortController !== 'undefined') ? new AbortController() : null;
  let   overlayShown = false;
  let   cancelled    = false;

  // La fenêtre d'attente n'apparaît qu'au bout de 2,5 s : les ouvertures rapides
  // (le cas courant) ne provoquent aucun clignotement.
  const timer = setTimeout(() => {
    overlayShown = true;
    _spShowWaitOverlay(() => {          // callback du bouton « Annuler »
      cancelled = true;
      if (controller) controller.abort();
      if (win && !win.closed) win.close();
      _spCloseWaitOverlay();
    });
  }, 2500);

  try {
    const url  = `${API_BASE}?action=sharepoint_resolve&id=${encodeURIComponent(docId)}`;
    const opts = { credentials: 'same-origin', cache: 'no-store' };
    if (controller) opts.signal = controller.signal;

    const res  = await fetch(url, opts);
    const json = await res.json().catch(() => null);

    clearTimeout(timer);
    if (cancelled) return;

    if (!json || !json.success) {
      if (win && !win.closed) win.close();
      if (res.status === 404) {
        _spShowGoneMessage(json && json.error);          // « ce fichier n'existe plus »
      } else {
        const msg = (json && json.error) || 'Impossible d’ouvrir le fichier SharePoint.';
        if (overlayShown) _spShowErrorMessage(msg); else toast(msg, 'error');
      }
      return;
    }

    // Succès : on dirige l'onglet pré-ouvert vers l'URL réelle.
    if (win && !win.closed) {
      win.location.href = json.data.url;
    } else {
      // Popup bloquée malgré tout : repli, tentative d'ouverture directe.
      window.open(json.data.url, '_blank');
    }
    if (overlayShown) _spCloseWaitOverlay();

  } catch (err) {
    clearTimeout(timer);
    if (cancelled || err?.name === 'AbortError') return;
    if (win && !win.closed) win.close();
    const msg = 'Impossible de joindre le serveur pour ouvrir le fichier.';
    if (overlayShown) _spShowErrorMessage(msg); else toast(msg, 'error');
  }
}

// ── Fenêtre d'attente dédiée (indépendante de la modale partagée) ─────────────
function _spWaitEl() { return document.getElementById('spWaitOverlay'); }

function _spShowWaitOverlay(onCancel) {
  _spCloseWaitOverlay();
  if (!document.getElementById('spWaitKeyframes')) {
    const st = document.createElement('style');
    st.id = 'spWaitKeyframes';
    st.textContent = '@keyframes spWaitSpin{to{transform:rotate(360deg)}}';
    document.head.appendChild(st);
  }
  const ov = document.createElement('div');
  ov.id = 'spWaitOverlay';
  ov.style.cssText =
    'position:fixed;inset:0;z-index:99999;background:rgba(6,18,34,.55);' +
    'display:flex;align-items:center;justify-content:center;backdrop-filter:blur(2px)';
  ov.innerHTML =
    '<div style="background:#fff;border-radius:14px;padding:26px 28px;width:320px;max-width:90vw;' +
    'text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.35)">' +
      '<div id="spWaitSpinner" style="width:38px;height:38px;margin:0 auto 16px;border:3px solid #dbe6f0;' +
      'border-top-color:#0078d4;border-radius:50%;animation:spWaitSpin .8s linear infinite"></div>' +
      '<div id="spWaitTitle" style="font-size:15px;font-weight:700;color:#0d2137;margin-bottom:6px">Veuillez patienter</div>' +
      '<div id="spWaitMsg" style="font-size:13px;color:#5b6b7d;line-height:1.4">Nous récupérons le fichier sur SharePoint…</div>' +
      '<div id="spWaitActions" style="margin-top:18px">' +
        '<button id="spWaitCancel" style="background:#eef2f6;border:none;border-radius:8px;padding:8px 18px;' +
        'font-size:13px;cursor:pointer;color:#33475b">Annuler</button>' +
      '</div>' +
    '</div>';
  document.body.appendChild(ov);
  const btn = document.getElementById('spWaitCancel');
  if (btn) btn.onclick = () => { if (typeof onCancel === 'function') onCancel(); };
}

function _spCloseWaitOverlay() {
  const ov = _spWaitEl();
  if (ov) ov.remove();
}

// Le fichier n'existe plus : transforme la fenêtre en message dédié.
function _spShowGoneMessage(serverMsg) {
  if (!_spWaitEl()) _spShowWaitOverlay(() => _spCloseWaitOverlay());
  _spSetOverlayMessage(
    '🗑️', 'Fichier introuvable',
    serverMsg || 'Ce fichier n’existe plus sur SharePoint (déplacé ou supprimé). Vous pouvez supprimer ce raccourci.',
    '#c0392b'
  );
}

function _spShowErrorMessage(msg) {
  if (!_spWaitEl()) _spShowWaitOverlay(() => _spCloseWaitOverlay());
  _spSetOverlayMessage('⚠️', 'Ouverture impossible', msg, '#b7791f');
}

function _spSetOverlayMessage(icon, title, msg, color) {
  const sp = document.getElementById('spWaitSpinner');
  if (sp) sp.outerHTML = `<div style="font-size:34px;margin-bottom:12px">${icon}</div>`;
  const t = document.getElementById('spWaitTitle');
  if (t) { t.textContent = title; t.style.color = color || '#0d2137'; }
  const m = document.getElementById('spWaitMsg');
  if (m) m.textContent = msg;
  const actions = document.getElementById('spWaitActions');
  if (actions) {
    actions.innerHTML =
      '<button id="spWaitClose" style="background:#0078d4;border:none;border-radius:8px;' +
      'padding:8px 20px;font-size:13px;cursor:pointer;color:#fff">Fermer</button>';
    const cb = document.getElementById('spWaitClose');
    if (cb) cb.onclick = () => _spCloseWaitOverlay();
  }
}

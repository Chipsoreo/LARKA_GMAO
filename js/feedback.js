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
 * Larka — Feedback utilisateur (problème / suggestion)
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Modale "Un problème ? Une suggestion ?" accessible depuis la topbar.
 * Envoie un mail SMTP à la liste configurée dans Configuration → Serveur
 * (section "Feedback utilisateur"). Capture d'écran optionnelle.
 *
 * Le bouton est affiché par showFeedbackButtonIfEnabled() au démarrage
 * (init.js), qui interroge la config publique pour savoir si la fonction
 * est activée pour ce tenant.
 *
 * Dépendances : api.js (apiRequest), ui.js (openModal, toast).
 * ═══════════════════════════════════════════════════════════════════════════════
 */

let _feedbackScreenshot = null;  // { data: base64, mime: 'image/png', name: 'capture.png' }

function openFeedbackModal() {
  _feedbackScreenshot = null;
  const currentPage = App?.currentPage || '(inconnue)';
  const html = `
    <div style="display:flex;flex-direction:column;gap:14px">
      <div style="font-size:12.5px;color:var(--gray-text);line-height:1.5">
        Votre message sera envoyé aux administrateurs de Larka. Décrivez le problème rencontré ou votre suggestion d'amélioration.
      </div>

      <div>
        <label style="font-size:12.5px;font-weight:600;display:block;margin-bottom:4px">Type de message</label>
        <div style="display:flex;gap:8px">
          <label style="flex:1;display:flex;align-items:center;gap:6px;padding:8px 12px;border:1.5px solid var(--gray-border);border-radius:8px;cursor:pointer;font-size:13px">
            <input type="radio" name="fbType" value="probleme" checked> 🐛 Problème
          </label>
          <label style="flex:1;display:flex;align-items:center;gap:6px;padding:8px 12px;border:1.5px solid var(--gray-border);border-radius:8px;cursor:pointer;font-size:13px">
            <input type="radio" name="fbType" value="suggestion"> 💡 Suggestion
          </label>
          <label style="flex:1;display:flex;align-items:center;gap:6px;padding:8px 12px;border:1.5px solid var(--gray-border);border-radius:8px;cursor:pointer;font-size:13px">
            <input type="radio" name="fbType" value="autre"> 📨 Autre
          </label>
        </div>
      </div>

      <div>
        <label style="font-size:12.5px;font-weight:600;display:block;margin-bottom:4px">Sujet</label>
        <input id="fb_sujet" type="text" class="form-control" placeholder="Résumé en une ligne" maxlength="200">
      </div>

      <div>
        <label style="font-size:12.5px;font-weight:600;display:block;margin-bottom:4px">Message <span style="color:#e74c3c">*</span></label>
        <textarea id="fb_message" class="form-control" rows="6" placeholder="Décrivez votre problème ou suggestion en détail…" maxlength="5000"></textarea>
        <div style="font-size:10.5px;color:var(--gray-text);margin-top:3px">Page actuelle : <code>${currentPage}</code> (transmis automatiquement)</div>
      </div>

      <div>
        <label style="font-size:12.5px;font-weight:600;display:block;margin-bottom:4px">Capture d'écran (optionnelle)</label>
        <div style="display:flex;gap:8px;align-items:center">
          <input id="fb_file" type="file" accept="image/png,image/jpeg,image/webp" onchange="_fbHandleFile(this)" style="display:none">
          <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('fb_file').click()">📎 Joindre une image</button>
          <span id="fb_filename" style="font-size:11px;color:var(--gray-text)"></span>
          <button id="fb_clear" type="button" class="btn btn-secondary btn-sm" onclick="_fbClearFile()" style="display:none">✕</button>
        </div>
        <div id="fb_preview" style="margin-top:8px"></div>
      </div>

      <div id="fb_status" style="font-size:12px"></div>
    </div>
  `;
  openModal('💬 Un problème ? Une suggestion ?', html, _sendFeedback, 'Envoyer', 'Annuler', '600px');
}

function _fbHandleFile(input) {
  const f = input.files?.[0];
  if (!f) return;
  if (f.size > 3 * 1024 * 1024) {
    if (typeof toast === 'function') toast('Image trop lourde (max 3 Mo)', 'error');
    input.value = '';
    return;
  }
  const reader = new FileReader();
  reader.onload = () => {
    // reader.result = "data:image/png;base64,...."
    const dataUrl = reader.result;
    const m = /^data:([^;]+);base64,(.+)$/.exec(dataUrl);
    if (!m) return;
    _feedbackScreenshot = { mime: m[1], data: m[2], name: f.name };
    const nameEl = document.getElementById('fb_filename');
    if (nameEl) nameEl.textContent = f.name + ' (' + Math.round(f.size/1024) + ' Ko)';
    const clear = document.getElementById('fb_clear');
    if (clear) clear.style.display = 'inline-flex';
    const prev = document.getElementById('fb_preview');
    if (prev) prev.innerHTML = '<img src="' + dataUrl + '" style="max-width:100%;max-height:200px;border:1px solid var(--gray-border);border-radius:6px">';
  };
  reader.readAsDataURL(f);
}

function _fbClearFile() {
  _feedbackScreenshot = null;
  const i = document.getElementById('fb_file'); if (i) i.value = '';
  const n = document.getElementById('fb_filename'); if (n) n.textContent = '';
  const c = document.getElementById('fb_clear'); if (c) c.style.display = 'none';
  const p = document.getElementById('fb_preview'); if (p) p.innerHTML = '';
}

async function _sendFeedback() {
  const type = (document.querySelector('input[name="fbType"]:checked')?.value) || 'autre';
  const sujet = (document.getElementById('fb_sujet')?.value || '').trim();
  const message = (document.getElementById('fb_message')?.value || '').trim();
  const status = document.getElementById('fb_status');
  if (!message) {
    if (status) status.innerHTML = '<span style="color:#e74c3c">⚠️ Veuillez écrire un message.</span>';
    return false; // empêcher la fermeture
  }
  if (status) status.innerHTML = '<span style="color:var(--gray-text)">⏳ Envoi en cours…</span>';

  const body = {
    type,
    sujet,
    message,
    page: App?.currentPage || '',
  };
  if (_feedbackScreenshot) {
    body.screenshot = _feedbackScreenshot.data;
    body.screenshot_mime = _feedbackScreenshot.mime;
  }

  try {
    const r = await apiRequest('feedback_send', 'POST', body);
    closeModal();
    if (typeof toast === 'function') {
      toast('Merci ! Votre message a bien été envoyé.', 'success');
    }
  } catch (e) {
    if (status) status.innerHTML = '<span style="color:#e74c3c">❌ ' + (e.message || 'Erreur d\'envoi') + '</span>';
    return false;
  }
}

/**
 * Affiche le bouton feedback dans la topbar. Toujours visible — la
 * désactivation est lue côté serveur via "feedback_send" (qui peut renvoyer
 * une erreur si feedback.actif = false). Pour l'instant on l'affiche dès que
 * l'utilisateur est connecté.
 *
 * Pour les Demandeurs (qui ont uiPrefsBtnTopbar masqué), on ajoute la classe
 * .topbar-leading-btn au premier bouton visible pour que le margin-left:auto
 * pousse correctement les boutons à droite.
 *
 * ⚠️ DÉSACTIVÉ : le feedback dépendait d'un envoi SMTP qui n'est plus
 * disponible. Le bouton reste caché tant qu'une alternative n'est pas mise en
 * place. Le code de la modale est conservé pour pouvoir le réactiver
 * facilement plus tard.
 */
function showFeedbackButtonIfEnabled() {
  const btn = document.getElementById('feedbackBtnTopbar');
  if (!btn) return;
  // Bouton désactivé : pas d'envoi SMTP disponible pour le moment.
  btn.style.display = 'none';

  // Si uiPrefsBtnTopbar est masqué (Demandeurs), donner le rôle de "leading"
  // au prochain bouton visible (myProfileBtnTopbar) pour préserver l'alignement
  // à droite.
  const prefsBtn = document.getElementById('uiPrefsBtnTopbar');
  const profBtn  = document.getElementById('myProfileBtnTopbar');
  const prefsHidden = prefsBtn && (
    prefsBtn.style.display === 'none' ||
    window.getComputedStyle(prefsBtn).display === 'none'
  );
  // Nettoyer d'éventuelles anciennes classes
  document.querySelectorAll('.topbar-leading-btn').forEach(el => el.classList.remove('topbar-leading-btn'));
  if (prefsHidden && profBtn) {
    profBtn.classList.add('topbar-leading-btn');
  }
}

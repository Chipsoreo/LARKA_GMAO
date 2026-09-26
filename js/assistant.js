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
 * Larka — Assistant IA (panneau de chat)
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Recherche en langage naturel dans toute la base Larka via un LLM.
 *
 * FONCTIONNALITÉS :
 *   - Bulle flottante 🤖 (apparaît après connexion si configuré)
 *   - Panneau de chat avec historique de conversation
 *   - Liens cliquables : [DOC:id:nom] → téléchargement, [FICHE:type:id:label] → ouverture fiche
 *   - Timeout allongé (2 min) pour les modèles locaux (Ollama)
 *
 * FOURNISSEURS SUPPORTÉS :
 *   Anthropic (Claude), OpenAI (GPT), Mistral, Google Gemini, Copilot,
 *   Ollama / LM Studio / llama.cpp (locaux)
 *
 * RAPIDITÉ (modèles locaux sur CPU) :
 *   - Préchauffage à l'ouverture du panneau (assistant_warmup) : le modèle
 *     traite son prompt pendant que l'utilisateur tape.
 *   - Streaming réel ; bouton ■ pour arrêter — le serveur coupe alors la
 *     génération, le CPU est libéré pour les autres.
 *
 * INITIALISATION :
 *   bootAssistant() → appelé depuis initApp() dans init.js
 *   Vérifie le statut via api/routes/assistant.php?action=assistant_status
 *
 * DÉPENDANCES :
 *   - api.js          → apiRequest() pour le status check
 *   - css/assistant.css → styles du panneau de chat
 *   - Backend         → api/routes/assistant.php
 *   - Configuration   → config.json > assistant (fournisseur, clé, modèle)
 * ═══════════════════════════════════════════════════════════════════════════════
 */

let _assistantOpen = false;
let _assistantHistory = [];
let _assistantBusy = false;
// HTML initial du panneau de messages et des suggestions, capturé au montage
// (createAssistantPanel) et restauré par resetAssistant.
let _assistantInitialMessagesHTML = '';
let _assistantInitialSuggestionsHTML = '';
// Délai client : fourni par assistant_status (dérivé du réglage serveur).
function _assistantTimeoutMs() {
  const t = window._assistantStatus && window._assistantStatus.timeout_ms;
  return (typeof t === 'number' && t > 0) ? t : 240000;
}
// Requête en cours (pour le bouton Stop) et dernier préchauffage.
let _assistantAbortCtrl = null;
let _assistantStopped = false;
let _assistantLastWarmup = 0;

function initAssistant() {
  if (document.getElementById('assistantBubble')) return; // éviter double-init

  const bubble = document.createElement('button');
  bubble.id = 'assistantBubble';
  bubble.innerHTML = '🤖';
  bubble.title = 'Assistant IA';
  bubble.onclick = toggleAssistant;
  document.body.appendChild(bubble);

  const _isDemandeur = App.currentUser?.Role === 'Demandeur';
  const _welcomeExamples = _isDemandeur
    ? `<ul style="margin:6px 0 0 16px;padding:0;font-size:12px;line-height:1.8">
            <li>« Qui contacter pour un problème de plomberie ? »</li>
            <li>« Où en est ma dernière demande ? »</li>
            <li>« Quelle catégorie choisir pour une fuite ? »</li>
            <li>« Quels sont les rôles dans Larka ? »</li>
          </ul>`
    : `<ul style="margin:6px 0 0 16px;padding:0;font-size:12px;line-height:1.8">
            <li>« Interventions préventives sur les BAES »</li>
            <li>« Trouve le contrat de maintenance ascenseurs »</li>
            <li>« Documents liés au bâtiment A »</li>
            <li>« Stock de plomberie en alerte »</li>
          </ul>`;
  const _suggestions = _isDemandeur
    ? `<button class="assistant-suggestion" onclick="_askSuggestion(this)">Qui contacter ?</button>
        <button class="assistant-suggestion" onclick="_askSuggestion(this)">Ma dernière demande</button>
        <button class="assistant-suggestion" onclick="_askSuggestion(this)">Catégories disponibles</button>
        <button class="assistant-suggestion" onclick="_askSuggestion(this)">Les rôles Larka</button>`
    : `<button class="assistant-suggestion" onclick="_askSuggestion(this)">Interventions préventives</button>
        <button class="assistant-suggestion" onclick="_askSuggestion(this)">Contrats qui expirent</button>
        <button class="assistant-suggestion" onclick="_askSuggestion(this)">Stock en alerte</button>
        <button class="assistant-suggestion" onclick="_askSuggestion(this)">Chercher un document</button>
        <button class="assistant-suggestion" onclick="_askSuggestion(this)">Bilan énergie</button>`;

  const _rgpdNotice = '<div style="margin-top:8px;padding:6px 10px;background:rgba(26,179,148,.08);border:1px solid rgba(26,179,148,.2);border-radius:8px;font-size:10.5px;color:var(--gray-text);line-height:1.5">'
    + '🔒 <strong>RGPD</strong> : Les données personnelles (noms, emails, téléphones) sont anonymisées avant envoi à l\'IA. '
    + 'Aucune conversation n\'est stockée côté serveur. '
    + '<a href="#" onclick="event.preventDefault();_showRgpdDetail()" style="color:var(--teal);text-decoration:underline">En savoir plus</a>'
    + '</div>';

  const panel = document.createElement('div');
  panel.id = 'assistantPanel';
  panel.innerHTML = `
    <div id="assistantHeader">
      <div style="display:flex;align-items:center;gap:10px">
        <span style="font-size:22px">🤖</span>
        <div>
          <div style="font-weight:700;font-size:14px">Assistant Larka</div>
          <div style="font-size:11px;opacity:.7">${_isDemandeur ? 'Aide à la création de demandes' : 'Une question ? Demandez-moi !'}</div>
        </div>
      </div>
      <div style="display:flex;align-items:center;gap:4px">
        <button id="assistantResetBtn" onclick="resetAssistant()"
                title="Effacer la conversation et recommencer"
                aria-label="Réinitialiser la conversation"
                style="background:none;border:none;color:white;font-size:16px;cursor:pointer;padding:6px 8px;border-radius:6px;opacity:.85;line-height:1"
                onmouseover="this.style.background='rgba(255,255,255,.12)';this.style.opacity='1'"
                onmouseout="this.style.background='none';this.style.opacity='.85'">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
        </button>
        <button onclick="toggleAssistant()" style="background:none;border:none;color:white;font-size:20px;cursor:pointer;padding:4px">✕</button>
      </div>
    </div>
    <div id="assistantMessages">
      <div class="assistant-msg assistant-msg-bot">
        <div class="assistant-msg-content">
          Bonjour ! ${_isDemandeur ? 'Je peux vous aider à créer vos demandes d\'intervention.' : 'Je suis l\'assistant IA de Larka.'}<br><br>
          ${_isDemandeur ? 'Posez-moi vos questions, par exemple :' : 'Posez-moi vos questions, par exemple :'}
          ${_welcomeExamples}
          ${_rgpdNotice}
        </div>
      </div>
    </div>
    <div id="assistantInputBar">
      <div id="assistantSuggestions">
        ${_suggestions}
      </div>
      <div style="display:flex;gap:8px">
        <input type="text" id="assistantInput" placeholder="Posez votre question…"
          onkeydown="if(event.key==='Enter')askAssistant()">
        <button id="assistantSendBtn" onclick="_assistantBusy ? stopAssistant() : askAssistant()" title="Envoyer">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
        </button>
      </div>
    </div>
  `;
  document.body.appendChild(panel);

  // Mémoriser le HTML initial des messages et des suggestions pour pouvoir
  // les restaurer à l'identique lors d'un reset (resetAssistant). On capture
  // APRÈS innerHTML pour récupérer le markup final déjà interpolé.
  _assistantInitialMessagesHTML  = document.getElementById('assistantMessages')?.innerHTML  || '';
  _assistantInitialSuggestionsHTML = document.getElementById('assistantSuggestions')?.innerHTML || '';
}

function toggleAssistant() {
  _assistantOpen = !_assistantOpen;
  const panel = document.getElementById('assistantPanel');
  const bubble = document.getElementById('assistantBubble');
  if (_assistantOpen) {
    panel.classList.add('open');
    bubble.classList.add('hidden');
    setTimeout(() => document.getElementById('assistantInput')?.focus(), 200);
    _assistantWarmup();
  } else {
    panel.classList.remove('open');
    bubble.classList.remove('hidden');
  }
}

/**
 * Réinitialise la conversation : vide l'historique côté client et restaure
 * l'affichage initial (message de bienvenue + suggestions).
 *
 * Côté serveur, rien à faire : l'historique n'est pas persisté (il est
 * renvoyé à chaque appel dans le body de la requête). Vider _assistantHistory
 * suffit donc à repartir sur une conversation neuve.
 *
 * Refuse si une requête est en cours (évite de couper le LLM en plein milieu).
 */
function resetAssistant() {
  if (_assistantBusy) {
    // Une réponse est en cours : on l'arrête (le serveur libère le modèle).
    stopAssistant();
    return;
  }
  // Confirmation légère uniquement si l'utilisateur a vraiment dialogué.
  // Le message de bienvenue seul ne compte pas comme conversation.
  if (_assistantHistory.length > 0) {
    if (!confirm('Effacer la conversation et repartir à zéro ?')) return;
  }

  _assistantHistory = [];

  const msgs = document.getElementById('assistantMessages');
  if (msgs && _assistantInitialMessagesHTML) {
    msgs.innerHTML = _assistantInitialMessagesHTML;
    msgs.scrollTop = 0;
  }
  const sugg = document.getElementById('assistantSuggestions');
  if (sugg) {
    if (_assistantInitialSuggestionsHTML) sugg.innerHTML = _assistantInitialSuggestionsHTML;
    sugg.style.display = ''; // réaffiche le bloc (askAssistant le masque au 1er envoi)
  }
  const input = document.getElementById('assistantInput');
  if (input) { input.value = ''; input.focus(); }
}

/**
 * Préchauffage du modèle local (au plus toutes les 4 min). Sans effet pour un
 * fournisseur distant : le serveur répond « skipped » sans rien appeler.
 */
function _assistantWarmup() {
  const st = window._assistantStatus || {};
  if (!st.prechauffage || typeof apiRequest !== 'function') return;
  const now = Date.now();
  if (now - _assistantLastWarmup < 240000) return;
  _assistantLastWarmup = now;
  apiRequest('assistant_warmup', 'POST', {}).catch(() => {});
}

/** Arrête la réponse en cours (bouton ■). */
function stopAssistant() {
  if (!_assistantBusy || !_assistantAbortCtrl) return;
  _assistantStopped = true;
  try { _assistantAbortCtrl.abort(); } catch (_) {}
}

function _assistantSetBusyUI(busy) {
  const input = document.getElementById('assistantInput');
  const sendBtn = document.getElementById('assistantSendBtn');
  if (input) input.disabled = busy;
  if (sendBtn) {
    sendBtn.classList.toggle('is-stop', busy);
    sendBtn.title = busy ? 'Arrêter la réponse' : 'Envoyer';
    sendBtn.setAttribute('aria-label', sendBtn.title);
    sendBtn.innerHTML = busy
      ? '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><rect x="5" y="5" width="14" height="14" rx="2"/></svg>'
      : '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>';
  }
}

/** Ouvre l'écran d'un module complémentaire ([MODULE:identifiant:libellé]). */
function _assistantOpenModule(id) {
  if (typeof PAGES === 'undefined' || typeof navigate !== 'function') return;
  const cle = Object.keys(PAGES).find(k => PAGES[k] && PAGES[k].extension === id);
  if (!cle) { if (typeof toast === 'function') toast('Module indisponible pour votre profil.', 'warning'); return; }
  if (_assistantOpen) toggleAssistant();
  navigate(cle);
}

function _askSuggestion(btn) {
  const input = document.getElementById('assistantInput');
  if (input) { input.value = btn.textContent; askAssistant(); }
}

/** Appel API JSON (repli si le streaming est impossible). */
async function _assistantApiCall(question, history) {
  const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
  _assistantAbortCtrl = controller;
  const timeoutId = controller ? setTimeout(() => controller.abort(), _assistantTimeoutMs()) : null;

  const url = (typeof API_BASE !== 'undefined' ? API_BASE : 'api/index.php') + '?action=assistant';
  const opts = {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'same-origin',
    body: JSON.stringify({ question, history }),
  };
  if (controller) opts.signal = controller.signal;

  try {
    const res = await fetch(url, opts);
    if (timeoutId) clearTimeout(timeoutId);
    const text = await res.text();
    let json;
    try { json = JSON.parse(text); } catch(_) { throw new Error('Réponse serveur invalide'); }
    if (!json.success) throw new Error(json.error || 'Erreur serveur');
    return json.data;
  } catch(err) {
    if (timeoutId) clearTimeout(timeoutId);
    if (err?.name === 'AbortError' && _assistantStopped) throw err;
    if (err?.name === 'AbortError') {
      // Message adapté au provider configuré (sinon générique).
      const provider = (window._assistantProvider || '').toLowerCase();
      const isLocal = ['ollama','lmstudio','local'].includes(provider);
      throw new Error(isLocal
        ? 'Le modèle local met trop de temps à répondre. Vérifiez qu\'Ollama/LM Studio tourne, ou choisissez un modèle plus petit (3B).'
        : 'Le serveur IA met trop de temps à répondre. Réessayez ou reformulez plus simplement.');
    }
    throw err;
  }
}

async function askAssistant() {
  const input = document.getElementById('assistantInput');
  const question = input?.value.trim();
  if (!question || _assistantBusy) return;

  input.value = '';
  _assistantBusy = true;
  _assistantStopped = false;
  _assistantSetBusyUI(true);

  const sugg = document.getElementById('assistantSuggestions');
  if (sugg) sugg.style.display = 'none';

  _addMsg('user', question);
  const typingId = _addMsg('bot', '<div class="assistant-typing"><span></span><span></span><span></span></div>', true);

  try {
    // On tente le streaming SSE en priorité (réponse en direct, gain perçu énorme).
    // Si ça échoue (réseau, proxy qui buffer, navigateur exotique), on retombe
    // automatiquement sur l'appel JSON classique.
    let reply;
    try {
      reply = await _assistantStreamCall(question, _assistantHistory, typingId);
    } catch(streamErr) {
      // Pas de second essai si l'utilisateur a arrêté, ou si le SERVEUR a
      // répondu une erreur (le rejouer doublerait l'attente et le coût).
      if (_assistantStopped || streamErr?.fromServer) throw streamErr;
      console.warn('[Assistant] Stream KO, fallback non-streamé :', streamErr);
      // Restaurer l'indicateur "typing" puisque le stream a pu en effacer le contenu
      const elFallback = document.getElementById(typingId);
      if (elFallback) {
        const content = elFallback.querySelector('.assistant-msg-content');
        if (content) content.innerHTML = '<div class="assistant-typing"><span></span><span></span><span></span></div>';
      }
      const result = await _assistantApiCall(question, _assistantHistory);
      reply = result.reply;
      // Remplace la bulle par le résultat final
      const el = document.getElementById(typingId);
      if (el) {
        const content = el.querySelector('.assistant-msg-content');
        if (content) content.innerHTML = _formatReply(reply);
        _processSpecialTokens(el, reply);
      }
    }

    // Stocker dans l'historique une version NETTOYÉE de la réponse :
    // - on retire le token [ACTION:nouvelle_demande|...] pour qu'il ne perturbe
    //   pas le LLM aux tours suivants (sinon il refuse d'en émettre un nouveau
    //   ou réutilise l'ancien — c'est l'origine du bug "ne crée qu'une seule
    //   demande pré-remplie à la suite").
    // - idem pour les autres tokens techniques qui ne servent qu'au rendu.
    const replyForHistory = String(reply || '')
      .replace(/\[ACTION:[^\]]*\]/gi, '')
      .replace(/\s+\n/g, '\n')
      .trim();
    _assistantHistory.push({ role: 'user', content: question });
    _assistantHistory.push({ role: 'assistant', content: replyForHistory });
    if (_assistantHistory.length > 20) _assistantHistory = _assistantHistory.slice(-20);
  } catch(e) {
    if (_assistantStopped) {
      // Arrêt volontaire : on garde le texte déjà reçu, on le signale.
      const el = document.getElementById(typingId);
      const content = el?.querySelector('.assistant-msg-content');
      const partial = el?.querySelector('.assistant-stream-text')?.textContent || '';
      if (content) content.innerHTML = (partial ? _formatReply(partial) + '<br>' : '')
        + '<span style="color:var(--gray-text);font-style:italic;font-size:12px">⏹ Réponse interrompue.</span>';
    } else {
      document.getElementById(typingId)?.remove();
      _addMsg('bot', `<div style="color:var(--red)">❌ ${_escHtml(e.message || 'Erreur inconnue')}</div>`);
    }
  } finally {
    _assistantBusy = false;
    _assistantAbortCtrl = null;
    _assistantSetBusyUI(false);
    if (input) input.focus();
  }
}

/**
 * Appel SSE vers /api/assistant_stream. Affiche la réponse en temps réel
 * dans la bulle d'ID `targetMsgId`, et renvoie le texte final complet.
 *
 * Format SSE attendu :
 *   data: {"type":"tool_call","name":"...","args_summary":"..."}
 *   data: {"type":"tool_result","name":"...","size":N,"cached":bool}
 *   data: {"type":"text","delta":"..."}
 *   data: {"type":"done", ...}
 *   data: {"type":"error","message":"..."}
 */
async function _assistantStreamCall(question, history, targetMsgId) {
  // ⚠️ « index.php/?action=… » (PATH_INFO) ne passait pas la règle nginx
  // `\.php$` : en production le streaming échouait TOUJOURS et chaque question
  // partait en repli JSON, sans affichage progressif.
  const url = ((typeof API_BASE !== 'undefined' && API_BASE) || './api/index.php') + '?action=assistant_stream';
  const ctrl = new AbortController();
  _assistantAbortCtrl = ctrl;
  const timeoutId = setTimeout(() => ctrl.abort(), _assistantTimeoutMs());

  const res = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'Accept': 'text/event-stream' },
    body: JSON.stringify({ question, history }),
    credentials: 'same-origin',
    signal: ctrl.signal,
  });

  if (!res.ok) {
    clearTimeout(timeoutId);
    // Lit le body pour le message d'erreur (peut être JSON ou texte)
    let errMsg = `HTTP ${res.status}`;
    try {
      const text = await res.text();
      const j = JSON.parse(text);
      errMsg = j.error || errMsg;
    } catch(_) {}
    throw new Error(errMsg);
  }
  if (!res.body || !res.body.getReader) {
    clearTimeout(timeoutId);
    throw new Error('Streaming non supporté par ce navigateur');
  }

  // Prépare la bulle de réponse : on remplace l'indicateur "typing" par un
  // conteneur texte vide + une zone "status" (où on affichera les tool calls).
  // L'indicateur "..." reste affiché jusqu'au premier delta texte pour bien
  // signaler au user que ça travaille toujours, même quand on voit juste
  // les tool calls passer.
  const bubble = document.getElementById(targetMsgId);
  const contentEl = bubble?.querySelector('.assistant-msg-content');
  let statusEl = null;
  let textEl = null;
  let typingEl = null;
  if (contentEl) {
    contentEl.innerHTML =
      '<div class="assistant-stream-status" style="display:none"></div>'
      + '<div class="assistant-stream-text"></div>'
      + '<div class="assistant-stream-typing"><span></span><span></span><span></span></div>';
    statusEl = contentEl.querySelector('.assistant-stream-status');
    textEl = contentEl.querySelector('.assistant-stream-text');
    typingEl = contentEl.querySelector('.assistant-stream-typing');
  }

  const reader = res.body.getReader();
  const decoder = new TextDecoder();
  let sseBuffer = '';
  let fullText = '';
  let toolCallsSeen = 0;
  let firstDeltaReceived = false;
  let streamDone = false;
  let doneInfo = null;
  const hideTokens = t => t.replace(/\[(?:FICHE|DOC|SPDOC|MODULE|ACTION):[^\]]*\]?/g, '');

  const container = document.getElementById('assistantMessages');

  try {
    while (!streamDone) {
      const { value, done } = await reader.read();
      if (done) break;
      sseBuffer += decoder.decode(value, { stream: true });

      // Découpe par "\n\n" (séparateur SSE), peut couvrir plusieurs events.
      let sepIdx;
      while ((sepIdx = sseBuffer.indexOf('\n\n')) !== -1) {
        const rawEvent = sseBuffer.slice(0, sepIdx);
        sseBuffer = sseBuffer.slice(sepIdx + 2);
        // Une "event" peut contenir plusieurs lignes "data:" — on les concatène
        const dataLines = rawEvent.split('\n')
          .filter(l => l.startsWith('data:'))
          .map(l => l.slice(5).trimStart());
        if (!dataLines.length) continue;
        const dataStr = dataLines.join('\n');
        let evt;
        try { evt = JSON.parse(dataStr); } catch(_) { continue; }

        if (evt.type === 'text' && evt.delta) {
          fullText += evt.delta;
          // Cache l'indicateur "..." dès qu'on commence à recevoir du texte
          if (!firstDeltaReceived && typingEl) typingEl.style.display = 'none';
          firstDeltaReceived = true;
          if (textEl) {
            // Affichage brut pendant le stream (pas de markdown — sinon les
            // **bold** à moitié reçus créent du HTML cassé). Le markdown sera
            // appliqué à la fin sur le texte complet.
            // On masque les tokens techniques en cours d'écriture pour ne pas
            // afficher "[FICHE:plan:1:2:Bureau A]" puis le remplacer en bouton
            // à la fin — visuellement c'est plus propre.
            textEl.textContent = hideTokens(fullText);
            container.scrollTop = container.scrollHeight;
          }
        } else if (evt.type === 'reset') {
          // Texte émis avant des appels d'outils : effacé.
          fullText = '';
          if (textEl) textEl.textContent = '';
        } else if (evt.type === 'replace') {
          // Version nettoyée du texte final (réflexion, annonces retirées).
          fullText = String(evt.text || '');
          if (textEl) textEl.textContent = hideTokens(fullText);
        } else if (evt.type === 'tool_call') {
          toolCallsSeen++;
          if (statusEl) {
            statusEl.style.display = 'block';
            statusEl.textContent = `🔍 ${evt.name}(${evt.args_summary || ''})…`;
          }
        } else if (evt.type === 'tool_result') {
          if (statusEl) {
            statusEl.textContent = `✓ ${evt.name} → ${evt.size} résultat(s)${evt.cached ? ' (cache)' : ''}`;
          }
        } else if (evt.type === 'info') {
          if (statusEl) statusEl.textContent = evt.message || '';
        } else if (evt.type === 'debug') {
          // Trace de diagnostic : ce que le LLM a renvoyé à chaque tour
          console.log('[Assistant SSE debug]', `round=${evt.round}`,
            `text_len=${evt.text_len}`, `tool_calls=${evt.tool_calls}`);
        } else if (evt.type === 'error') {
          const err = new Error(evt.message || 'Erreur streaming');
          err.fromServer = true;
          throw err;
        } else if (evt.type === 'done') {
          // Fin propre annoncée par le serveur — on sort des DEUX boucles
          doneInfo = evt;
          streamDone = true;
          break;
        }
      }
    }
  } finally {
    clearTimeout(timeoutId);
    try { const c = reader.cancel(); if (c && c.catch) c.catch(() => {}); } catch(_) {}
  }

  // Rendu final : applique le markdown sur le texte complet et masque la status bar.
  // Si on n'a reçu AUCUN texte (ex: le LLM n'a fait que des tool_calls puis a
  // arrêté), on affiche un message d'erreur explicite plutôt qu'une bulle vide.
  if (contentEl) {
    if (fullText) {
      contentEl.innerHTML = _formatReply(fullText) + _assistantStatsHtml(doneInfo);
      _processSpecialTokens(bubble, fullText);
    } else {
      contentEl.innerHTML = '<div style="color:var(--gray);font-style:italic">Le modèle a exécuté '
        + toolCallsSeen + ' recherche(s) mais n\'a pas formulé de réponse texte. Reformulez peut-être ?</div>';
    }
  }

  if (!firstDeltaReceived && !fullText) {
    // On a reçu des tool_calls mais aucun texte — pas une vraie erreur si le
    // serveur a explicitement clos avec 'done', juste un cas dégénéré qu'on
    // signale via la bulle ci-dessus. On garde l'historique vide pour ce tour.
    if (!streamDone) throw new Error('Aucune donnée reçue du stream');
  }
  return fullText;
}

/**
 * Petite ligne discrète sous la réponse : durée et vitesse (utile pour régler
 * un modèle local sur CPU). Masquée pour les demandeurs.
 */
function _assistantStatsHtml(done) {
  if (!done || !done.usage || App.currentUser?.Role === 'Demandeur') return '';
  const u = done.usage;
  const parts = [];
  // Le moteur répond en quelques millisecondes : « 0 s » serait faux, on affiche des ms.
  if (u.ms) parts.push(u.ms < 1000 ? Math.max(1, Math.round(u.ms)) + ' ms'
                                   : (u.ms / 1000).toLocaleString('fr-FR', { maximumFractionDigits: 1 }) + ' s');
  // Mode fiable : c'est le moteur Larka qui a répondu (même réponse quel que soit le modèle).
  const mode = String(done.mode || '');
  if (mode === 'moteur') parts.push('moteur Larka');
  else if (mode === 'moteur+ia') parts.push('moteur Larka + reformulation IA');
  if (u.tok_s) parts.push(u.tok_s.toLocaleString('fr-FR') + ' tok/s');
  if (u.cached && u.in) parts.push('cache ' + Math.round(100 * u.cached / u.in) + ' %');
  if (done.tool_calls_count) parts.push(done.tool_calls_count + ' recherche' + (done.tool_calls_count > 1 ? 's' : ''));
  if (!parts.length) return '';
  const titre = mode.startsWith('moteur')
    ? 'Réponse du moteur Larka : identique quel que soit le modèle configuré (' + (done.model || '') + ')'
    : (done.fournisseur || '') + ' · ' + (done.model || '');
  return '<div class="assistant-stats" title="' + _escAttr(titre) + '">⚡ ' + _escHtml(parts.join(' · ')) + '</div>';
}

/**
 * Traite les tokens spéciaux dans une réponse complète : [DOC:id:label],
 * [FICHE:type:...], etc. Extrait du flux normal car on les applique après
 * que le texte complet soit en place.
 */
function _processSpecialTokens(bubble, fullText) {
  // Détection du token [ACTION:nouvelle_demande|...] émis par l'assistant
  // pour le rôle Demandeur. Quand l'IA a compris que l'utilisateur veut créer
  // une demande, elle prépare les champs et émet ce token. Le frontend ouvre
  // alors le modal de création pré-rempli, l'utilisateur n'a qu'à valider.
  //
  // Format : [ACTION:nouvelle_demande|cle1=val1|cle2=val2|...]
  // Clés attendues : titre, description, batiment, categorie, urgence
  // Une valeur vide est tolérée (clé sans valeur après le =).
  const actionRe = /\[ACTION:nouvelle_demande((?:\|[a-z_]+=[^|\]]*)*)\]/i;
  const m = fullText.match(actionRe);
  if (!m) return;

  // Parse "|titre=X|description=Y|..." en objet
  const fields = {};
  m[1].split('|').forEach(seg => {
    if (!seg) return;
    const eq = seg.indexOf('=');
    if (eq < 0) return;
    const k = seg.slice(0, eq).trim().toLowerCase();
    const v = seg.slice(eq + 1).trim();
    if (k) fields[k] = v;
  });

  // Tag invisible pour le gestionnaire qui traitera la demande : on préfixe
  // la description pour qu'il sache que c'était une création assistée.
  const tagAssistant = '[Demande préparée avec l\'assistant IA]\n\n';
  const descFinale = tagAssistant + (fields.description || '');

  // Affichage : remplacer le token brut par un bouton cliquable dans la bulle.
  // Si l'utilisateur ferme le modal sans valider, il peut le ré-ouvrir.
  const content = bubble?.querySelector('.assistant-msg-content');
  if (content) {
    // Le token brut a déjà été retiré par _formatReply, on ajoute juste le bouton.
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.style.cssText = 'display:inline-flex;align-items:center;gap:6px;margin-top:8px;padding:8px 14px;background:var(--teal,#1ab394);color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;box-shadow:0 2px 6px rgba(26,179,148,.3)';
    btn.innerHTML = '✏️ Ouvrir la demande pré-remplie';
    btn.onclick = () => _openPrefilledDemande(fields, descFinale);
    content.appendChild(document.createElement('br'));
    content.appendChild(btn);
  }

  // Ouverture automatique si on est un Demandeur (le cas nominal).
  // On laisse 400ms pour que le stream finisse de s'afficher avant l'ouverture du modal.
  setTimeout(() => _openPrefilledDemande(fields, descFinale), 400);
}

/**
 * Ouvre le modal "Nouvelle demande" puis remplit les champs depuis l'objet `fields`
 * préparé par l'assistant IA. La fonction `nouvelleDemande()` est définie dans
 * js/pages/demandes.js et c'est elle qui ouvre le modal — on attend qu'elle ait
 * fini d'injecter les champs dans le DOM avant de les pré-remplir.
 */
function _openPrefilledDemande(fields, descAvecTag) {
  if (typeof nouvelleDemande !== 'function') {
    // demandes.js pas encore chargé (lazy-loading) → on le charge explicitement
    // via PageLoader, puis on retente. Plus fiable que de passer par navigate()
    // qui change la page courante sans qu'on en ait besoin ici.
    if (window.PageLoader && typeof window.PageLoader.ensure === 'function') {
      window.PageLoader.ensure('demandes').then(
        () => _openPrefilledDemande(fields, descAvecTag),
        (e) => console.warn('[Assistant] PageLoader.ensure(demandes) a échoué :', e)
      );
      return;
    }
    // Fallback historique
    console.warn('[Assistant] nouvelleDemande() indisponible — la page demandes n\'est peut-être pas chargée');
    if (typeof navigate === 'function') navigate('demandes');
    setTimeout(() => _openPrefilledDemande(fields, descAvecTag), 600);
    return;
  }
  // Ferme l'assistant pour laisser la place au modal
  if (typeof toggleAssistant === 'function' && _assistantOpen) toggleAssistant();
  try { nouvelleDemande(); } catch(e) { console.error('Erreur ouverture demande:', e); return; }

  // Détecter le type de demande (Technique / Archive). On accepte plusieurs
  // libellés et alias (insensible à la casse, accents tolérés).
  const rawType = String(fields.type || '').toLowerCase()
    .normalize('NFD').replace(/[\u0300-\u036f]/g, '');
  const isArchive = ['archive', 'archives', 'archivage', 'desarchivage', 'archiv'].some(
    k => rawType.includes(k)
  );
  const rawPresta = String(fields.prestation || fields.presta || '').toLowerCase()
    .normalize('NFD').replace(/[\u0300-\u036f]/g, '');
  const prestaArchive = rawPresta.includes('desarchiv') ? 'Desarchivage'
                      : rawPresta.includes('archiv')    ? 'Archivage'
                      : '';

  // Filet de sécurité : un petit modèle met parfois le lieu dans le titre
  // (« Poignée cassée bureau 235 2eme étage ») au lieu du champ prévu.
  _assistantFixLieu(fields);

  // Attend que les champs du modal soient présents (rendu asynchrone)
  const tryFill = (retries = 20) => {
    const f_titre = document.getElementById('f_titre');
    // f_titre est caché en mode archive — on vérifie plutôt la présence du
    // bloc principal commun (f_description toujours présent).
    const f_desc = document.getElementById('f_description');
    if (!f_desc) {
      if (retries > 0) setTimeout(() => tryFill(retries - 1), 100);
      return;
    }

    // ── Basculer en mode Archive si nécessaire AVANT de remplir les champs
    //    (sinon les blocs cachés n'existent pas encore dans le DOM utile).
    if (isArchive && typeof switchTypeDemande === 'function') {
      try { switchTypeDemande('Archive'); } catch(_e) {}
      if (prestaArchive) {
        const sel = document.getElementById('f_prestaArchive');
        if (sel) {
          sel.value = prestaArchive;
          try { sel.dispatchEvent(new Event('change', { bubbles: true })); } catch(_) {}
          if (typeof onPrestaArchiveChange === 'function') {
            try { onPrestaArchiveChange(); } catch(_e) {}
          }
        }
      }
    }

    // Comparaison sans casse ni accents : « Batiment A » choisit bien « Bâtiment A ».
    const _n = v => String(v || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim();
    const setField = (id, value) => {
      if (value === undefined || value === '') return;
      const el = document.getElementById(id);
      if (!el) return;
      if (el.tagName === 'SELECT') {
        const opt = Array.from(el.options).find(o =>
          _n(o.value) === _n(value) || _n(o.textContent) === _n(value)
        );
        if (opt) el.value = opt.value;
      } else {
        el.value = value;
      }
      try { el.dispatchEvent(new Event('change', { bubbles: true })); } catch(_) {}
      try { el.dispatchEvent(new Event('blur',   { bubbles: true })); } catch(_) {}
    };

    // Champs communs
    setField('f_description', descAvecTag);
    setField('f_batiment',    fields.batiment);
    setField('f_bureau',      fields.bureau);

    if (isArchive) {
      // Champs spécifiques archive
      setField('f_serviceArchive', fields.service);
      setField('f_periodeArchive', fields.periode);
      setField('f_nbDossiers',     fields.nb_dossiers);
      setField('f_dateSolde',      fields.date_solde);

      // Liste de dossiers (string séparée par virgules ou ;)
      const dossiersRaw = String(fields.dossiers || '').trim();
      if (dossiersRaw && prestaArchive === 'Desarchivage') {
        const dossiers = dossiersRaw.split(/[,;]+/).map(s => s.trim()).filter(Boolean);
        const inputs = document.querySelectorAll('.arch-dossier-input');
        // Remplir le premier input, ajouter des lignes si plus de dossiers
        if (inputs[0]) inputs[0].value = dossiers[0] || '';
        for (let i = 1; i < dossiers.length; i++) {
          if (typeof addArchDossierRow === 'function') {
            try { addArchDossierRow(); } catch(_e) {}
          }
          const after = document.querySelectorAll('.arch-dossier-input');
          if (after[i]) after[i].value = dossiers[i];
        }
      }
    } else {
      // Champs spécifiques technique
      setField('f_titre',     fields.titre);
      setField('f_categorie', fields.categorie);
      // Le formulaire propose Basse / Normale / Haute / Urgente. Les anciens
      // libellés (« Élevée », « Faible ») n'y figuraient pas : l'urgence
      // proposée par l'assistant était perdue sans bruit.
      const _urg = { elevee: 'Haute', eleve: 'Haute', haute: 'Haute', importante: 'Haute', faible: 'Basse', basse: 'Basse',
                     urgente: 'Urgente', urgent: 'Urgente', normale: 'Normale', normal: 'Normale' };
      setField('f_urgence', _urg[_n(fields.urgence)] || fields.urgence);
      // Focus sur le titre pour relecture
      try { document.getElementById('f_titre')?.focus(); } catch(_) {}
    }
  };
  tryFill();
}

/**
 * Déplace le lieu (bureau, salle, étage) du titre vers le champ Bureau quand
 * celui-ci est vide, et retire ce lieu du titre. Ne touche à rien sinon.
 */
function _assistantFixLieu(fields) {
  const reBureau = /\b(?:bureau|salle|local|pi[eè]ce)\s*(?:n[°o]\s*)?[\w-]*\d[\w-]*/i;
  const reEtage  = /\b(?:(\d+)\s*(?:e|er|ère|eme|ème|nd|nde)?\s*[ée]tage|[ée]tage\s*(\d+)|rez[- ]de[- ]chauss[ée]e|rdc|sous[- ]sol)\b/i;
  const src = [fields.titre, fields.description].filter(Boolean).join(' ');
  if (!fields.bureau) {
    const parts = [];
    const b = src.match(reBureau); if (b) parts.push(b[0].replace(/^./, c => c.toUpperCase()));
    const e = src.match(reEtage);
    if (e) parts.push(e[1] || e[2] ? `${e[1] || e[2]}${(e[1] || e[2]) === '1' ? 'er' : 'e'} étage` : e[0]);
    if (parts.length) fields.bureau = parts.join(', ');
  }
  if (fields.titre) {
    // On ne retire du titre que ce qui figure bien dans le champ Bureau.
    const lieu = String(fields.bureau || '').toLowerCase();
    const dansLieu = m => { const d = (m.match(/\d+/) || [m.toLowerCase()])[0]; return !!m && lieu.includes(d); };
    const t = fields.titre.replace(reBureau, m => dansLieu(m) ? '' : m).replace(reEtage, m => dansLieu(m) ? '' : m)
      .replace(/\s*[-–,(]\s*[)]?\s*$/g, '').replace(/\s{2,}/g, ' ').trim();
    if (t.length >= 4) fields.titre = t.charAt(0).toUpperCase() + t.slice(1);
  }
}

function _addMsg(role, content, isRaw = false) {
  const container = document.getElementById('assistantMessages');
  const id = 'amsg_' + Date.now() + '_' + Math.random().toString(36).slice(2,6);
  const div = document.createElement('div');
  div.id = id;
  div.className = `assistant-msg assistant-msg-${role === 'user' ? 'user' : 'bot'}`;
  div.innerHTML = `<div class="assistant-msg-content">${isRaw ? content : (role === 'user' ? _escHtml(content) : content)}</div>`;
  container.appendChild(div);
  container.scrollTop = container.scrollHeight;
  return id;
}

// NB : _escHtml() est défini une seule fois, dans js/ui.js (toujours chargé en
// amont de ce module) — même implémentation, guillemets inclus. Le redéclarer
// ici en portée globale écrasait la version d'ui.js pour toute l'application.

// Pour la sécurité d'une valeur uniquement utilisée DANS un attribut.
function _escAttr(s) {
  return _escHtml(s);
}

function _formatReply(text) {
  let html = _escHtml(text);

  // ── Token [ACTION:nouvelle_demande|...] : on le retire de l'affichage texte
  //    car _processSpecialTokens le transforme en bouton "Ouvrir la demande
  //    pré-remplie" attaché à la bulle. Note : le texte est déjà escapé donc
  //    [ devient [, mais & est conservé, donc on reste sur les caractères
  //    bruts dans la regex.
  html = html.replace(/\[ACTION:nouvelle_demande(?:\|[a-z_]+=[^|\]]*)*\]/gi, '');
  // ── Liens documents : [DOC:id:nom_fichier] → bouton téléchargement
  // ⚠️ FIX SÉCURITÉ : la regex capture `nom` après que `text` a déjà été
  // échappé, mais comme on ré-injecte `nom` à plusieurs endroits dont des
  // attributs HTML, on doit le re-passer dans _escAttr — sinon une chaîne
  // comme [DOC:1:foo&quot; onerror=&quot;alert(1)] peut casser l'attribut
  // après la première passe d'_escHtml (qui a transformé " en &quot; mais
  // les `&` autour sont laissés tels quels par la 2e injection).
  html = html.replace(/\[DOC:(\d+):([^\]]+)\]/g, (_, id, nom) => {
    const safeId = String(parseInt(id, 10) || 0);
    const safeNom = _escAttr(nom);
    return `<a href="api/index.php?action=document_download&amp;id=${safeId}" download="${safeNom}" style="display:inline-flex;align-items:center;gap:5px;padding:4px 10px;background:var(--blue);color:#fff;border-radius:6px;font-size:12px;text-decoration:none;margin:2px 0;font-weight:500">📄 ${safeNom}</a>`;
  });

  // ── Liens documents SharePoint : [SPDOC:url|nom] → lien externe (nouvel onglet)
  // ⚠️ Sécurité : le modèle peut inventer une URL — on ne rend le lien cliquable
  // que si l'hôte appartient aux domaines Microsoft attendus. Sinon on affiche
  // juste le nom en texte. L'URL a été passée par _escHtml (& → &amp;) : valide
  // telle quelle dans un attribut href.
  html = html.replace(/\[SPDOC:(https?:\/\/[^|\]\s]+)\|([^\]]+)\]/g, (_, url, nom) => {
    const safeNom = _escAttr(nom);
    let host = '';
    try { host = new URL(url.replace(/&amp;/g, '&')).hostname.toLowerCase(); } catch (e) { return safeNom; }
    const okDomains = ['sharepoint.com', 'sharepointonline.com', 'onedrive.com', 'onedrive.live.com', '1drv.ms', 'office.com', 'office365.com'];
    const allowed = okDomains.some(d => host === d || host.endsWith('.' + d));
    if (!allowed) return safeNom;
    return `<a href="${url}" target="_blank" rel="noopener noreferrer" style="display:inline-flex;align-items:center;gap:5px;padding:4px 10px;background:#0078d4;color:#fff;border-radius:6px;font-size:12px;text-decoration:none;margin:2px 0;font-weight:500">📁 ${safeNom}</a>`;
  });

  // ── Liens modules : [MODULE:identifiant:libellé] → écran du module.
  //    L'identifiant est contraint (a-z0-9.-) : rien d'autre n'entre dans l'onclick.
  html = html.replace(/\[MODULE:([a-z0-9\-]+\.[a-z0-9\-]+):([^\]]+)\]/g, (_, id, label) =>
    `<a href="#" onclick="event.preventDefault();_assistantOpenModule('${id}')" style="display:inline-flex;align-items:center;gap:4px;padding:3px 9px;background:var(--gray-bg);border:1px solid var(--gray-border);border-radius:6px;font-size:12px;text-decoration:none;color:var(--blue);font-weight:500;margin:2px 0">🧩 ${_escAttr(label)}</a>`);

  // ── Liens fiches : trois formats supportés
  //    [FICHE:type:id:label]                  → fiche simple ou plan sans sélection
  //    [FICHE:plan:etageId:elementId:label]   → plan avec UN élément sélectionné
  //    [FICHE:plans:etageId:id1,id2,id3:label] → plan cadré sur PLUSIEURS éléments
  const ficheActions = {
    intervention: (id) => `editInterv(${id})`,
    bien:         (id) => `editBien(${id})`,
    equipement:   (id) => `editEquip(${id})`,
    contrat:      (id) => `editContrat(${id})`,
    demande:      (id) => `traiterDemande(${id})`,
    stock:        (id) => `editStock(${id})`,
    plan:         (id) => `_openPlanAtEtage(${id})`,
  };
  // Regex unifiée qui capture les trois formats : type, id principal, segment optionnel (id ou liste avec virgules+espaces tolérés), label.
  html = html.replace(/\[FICHE:(\w+):(\d+)(?::([\d,\s]+))?:([^\]]+)\]/g, (_, type, id, extra, label) => {
    const safeId = parseInt(id, 10);
    if (!Number.isFinite(safeId) || safeId <= 0) return _escAttr(label);
    const safeLabel = _escAttr(label);
    const icons = { intervention:'🔧', bien:'🏢', equipement:'⚙️', contrat:'📋', demande:'📝', stock:'📦', plan:'🗺️', plans:'🗺️' };
    const icon = icons[type] || '📄';

    let actionCall = null;

    if (type === 'plans') {
      // Format pluriel : extra = "id1,id2,id3"
      const ids = (extra || '').split(',').map(s => parseInt(s,10)).filter(n => Number.isFinite(n) && n > 0);
      if (!ids.length) return _escAttr(label);
      actionCall = `_openPlanAtEtage(${safeId}, null, ${JSON.stringify(ids)})`;
    } else if (type === 'plan' && extra) {
      // Format plan avec elementId
      const elId = parseInt(extra, 10);
      if (Number.isFinite(elId) && elId > 0) {
        actionCall = `_openPlanAtEtage(${safeId}, ${elId})`;
      } else {
        actionCall = `_openPlanAtEtage(${safeId})`;
      }
    } else {
      // Format simple : pas de 3e segment, ou type différent
      const action = ficheActions[type];
      if (!action) return _escAttr(label);
      actionCall = action(safeId);
    }
    return `<a href="#" onclick="event.preventDefault();toggleAssistant();${actionCall}" style="display:inline-flex;align-items:center;gap:4px;padding:3px 9px;background:var(--gray-bg);border:1px solid var(--gray-border);border-radius:6px;font-size:12px;text-decoration:none;color:var(--blue);font-weight:500;margin:2px 0">${icon} ${safeLabel}</a>`;
  });

  // Markdown basique
  html = html.replace(/```([\s\S]*?)```/g, '<pre style="background:var(--gray-bg);padding:8px 12px;border-radius:6px;font-size:12px;overflow-x:auto;border:1px solid var(--gray-border)">$1</pre>');
  html = html.replace(/`([^`]+)`/g, '<code style="background:var(--gray-bg);padding:1px 5px;border-radius:3px;font-size:12px">$1</code>');
  html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
  html = html.replace(/\*(.+?)\*/g, '<em>$1</em>');
  // Convertir lignes "- foo" / "• foo" / "1. foo" en <li>
  html = html.replace(/^[-•]\s+(.+)$/gm, '<li>$1</li>');
  html = html.replace(/^\d+\.\s+(.+)$/gm, '<li>$1</li>');
  // Regrouper UNIQUEMENT les <li> consécutifs (séparés par espaces/sauts) en <ul>.
  // Auparavant la regex /(<li>.*<\/li>)/s englobait tout le texte entre le
  // PREMIER et le DERNIER <li>, y compris des paragraphes non-liste au milieu.
  html = html.replace(/(?:<li>[^<]*<\/li>\s*)+/g, m =>
    '<ul style="margin:4px 0 4px 16px;padding:0">' + m.trim() + '</ul>'
  );
  html = html.replace(/\n/g, '<br>');
  return html;
}

/**
 * Appelé depuis initApp() après connexion réussie.
 * Vérifie si l'assistant est actif côté serveur, puis affiche la bulle.
 */
async function bootAssistant() {
  try {
    const status = await apiRequest('assistant_status');
    if (status?.actif) {
      window._assistantProvider = status.fournisseur || '';
      window._assistantStatus = status;
      initAssistant();
    }
  } catch(_) { /* assistant non disponible */ }
}

/**
 * Ouvre la page Plans, sélectionne le bâtiment + l'étage, et :
 *  - si elementId fourni → sélectionne et centre la vue sur l'élément.
 *  - si elementIds (tableau) fourni → cadre la vue sur l'ensemble du groupe.
 *
 * Appelé depuis :
 *  [FICHE:plan:etageId:label]                       → ouvre l'étage
 *  [FICHE:plan:etageId:elementId:label]             → ouvre + sélectionne un élément
 *  [FICHE:plans:etageId:id1,id2,id3:label]          → ouvre + cadre sur le groupe
 */
async function _openPlanAtEtage(etageId, elementId = null, elementIds = null) {
  try {
    if (typeof navigate === 'function' && App.currentPage !== 'plans') {
      navigate('plans');
    }
    // Polling : attendre que la page Plans soit prête
    const trySelect = async (retries = 15) => {
      if (typeof PlansApi !== 'undefined' && typeof _p !== 'undefined') {
        try {
          const allEtages = await PlansApi.getEtages();
          const target = allEtages.find(e => Number(e.Id) === Number(etageId));
          if (target && typeof _planSelectBat === 'function' && typeof _planSelectEtage === 'function') {
            if (!_p.batiment || _p.batiment.Id !== target.BatimentId) {
              await _planSelectBat(target.BatimentId);
            }
            // IMPORTANT : ne pas réappeler _planSelectEtage si l'étage est déjà
            // sélectionné. Sinon on déclenche _pInitMap() qui détruit/recrée
            // tous les layers Leaflet, et la surbrillance s'applique alors
            // sur des layers en cours de destruction (effet "ne clignote plus
            // au 2e clic"). On garde simplement le rendu existant.
            const sameEtage = _p.etage && Number(_p.etage.Id) === Number(etageId);
            if (!sameEtage) {
              await _planSelectEtage(etageId);
            }

            // Cas 1 : un seul élément à focus
            if (elementId) {
              _focusPlanElement(elementId);
            }
            // Cas 2 : plusieurs éléments → on cadre simplement la vue sur le groupe
            else if (Array.isArray(elementIds) && elementIds.length) {
              _planFitToElementsWhenReady(elementIds);
            }
            return;
          }
        } catch(_) {}
      }
      if (retries > 0) setTimeout(() => trySelect(retries - 1), 200);
    };
    trySelect();
  } catch (e) {
    console.error('Erreur ouverture plan:', e);
  }
}

/**
 * Sélectionne un élément du plan, centre/zoome la vue, ET applique un effet
 * de surbrillance pulsant pour bien le rendre visible.
 */
function _focusPlanElement(elementId, retries = 20) {
  if (typeof _p === 'undefined' || !_p.elements || !_p.map) {
    if (retries > 0) setTimeout(() => _focusPlanElement(elementId, retries - 1), 150);
    else console.warn('[Focus] ❌ Page Plans non chargée après timeout');
    return;
  }
  const el = _p.elements.find(e => Number(e.Id) === Number(elementId));
  if (!el) {
    if (retries > 0) setTimeout(() => _focusPlanElement(elementId, retries - 1), 150);
    else console.warn('[Focus] ❌ Élément id=' + elementId + ' introuvable. IDs dispo :',
      _p.elements.map(e => e.Id));
    return;
  }
  console.log('[Focus] ✓ Sélection élément', el.Id, '(', el.Nom, ')');
  if (typeof _pSelectEl === 'function') {
    try { _pSelectEl(el); } catch(_) {}
  }

  // Centrer / zoomer la vue sur l'élément
  try {
    const coords = typeof el.Coords === 'string' ? JSON.parse(el.Coords) : el.Coords;
    if (Array.isArray(coords) && coords.length) {
      const pts = coords.filter(p => Array.isArray(p) && p.length >= 2);
      if (pts.length === 1) {
        _p.map.flyTo(pts[0], Math.max(_p.map.getZoom(), 3), { duration: 0.6 });
      } else if (pts.length >= 2) {
        const lats = pts.map(p => p[0]), lngs = pts.map(p => p[1]);
        _p.map.flyToBounds(
          [[Math.min(...lats), Math.min(...lngs)], [Math.max(...lats), Math.max(...lngs)]],
          { padding: [80, 80], maxZoom: 4, duration: 0.6 }
        );
      }
    }
  } catch(e) { console.warn('Centrage plan impossible :', e); }

  // Surbrillance pulsante — on attend que le layer soit rendu (init asynchrone)
  // ET que le fly soit terminé pour que l'animation soit visible immédiatement.
  _planHighlightWhenReady([elementId]);
}

/**
 * Attend que les layers des éléments cibles soient présents avant d'appliquer
 * le highlight. Utile après un changement d'étage où _pInitMap() s'exécute
 * sur un setTimeout(80ms) puis crée les layers un par un.
 */
function _planHighlightWhenReady(elementIds, retries = 20) {
  if (typeof _p === 'undefined' || !_p.layers) {
    if (retries > 0) setTimeout(() => _planHighlightWhenReady(elementIds, retries - 1), 150);
    return;
  }
  // Vérifie qu'au moins UN des layers cibles est prêt — pour le reste,
  // _planHighlightElements ignore proprement les manquants.
  //
  // CRUCIAL : ne pas se contenter de la présence de l'entrée _p.layers[id].
  // Après un _planSelectEtage(), l'ancienne entrée peut encore exister pendant
  // que _pInitMap() (lancé sur setTimeout 80ms) reconstruit le canvas Leaflet.
  // On vérifie donc aussi que :
  //  - le layer a une fonction getElement (marker ou path Leaflet)
  //  - getElement() retourne un noeud DOM
  //  - ce noeud DOM est BIEN rattaché au document (document.contains)
  // Sinon l'animation s'appliquera sur un orphelin invisible et le clignotement
  // ne marchera pas — c'est le bug "ça clignote au 1er clic puis plus jamais".
  const isLayerLive = (id) => {
    const layer = _p.layers[id];
    if (!layer) return false;
    try {
      const elNode = typeof layer.getElement === 'function' ? layer.getElement() : null;
      if (!elNode) return false;
      return document.contains(elNode);
    } catch(_) { return false; }
  };
  const anyReady = elementIds.some(isLayerLive);
  if (!anyReady) {
    if (retries > 0) setTimeout(() => _planHighlightWhenReady(elementIds, retries - 1), 150);
    return;
  }
  _planHighlightElements(elementIds);
}

/**
 * Helper de polling pour cadrer la vue sur un groupe d'éléments et les mettre
 * tous en surbrillance — attend que _p.map et _p.elements soient prêts.
 */
function _planFitToElementsWhenReady(elementIds, retries = 25) {
  if (typeof _p === 'undefined' || !_p.elements || !_p.map) {
    if (retries > 0) setTimeout(() => _planFitToElementsWhenReady(elementIds, retries - 1), 200);
    return;
  }
  try {
    const targets = _p.elements.filter(e => elementIds.map(Number).includes(Number(e.Id)));
    if (!targets.length) return;
    const allCoords = [];
    targets.forEach(el => {
      try {
        const c = typeof el.Coords === 'string' ? JSON.parse(el.Coords) : el.Coords;
        if (Array.isArray(c)) c.forEach(p => { if (Array.isArray(p) && p.length >= 2) allCoords.push(p); });
      } catch(_) {}
    });
    if (allCoords.length) {
      const lats = allCoords.map(c => c[0]), lngs = allCoords.map(c => c[1]);
      _p.map.flyToBounds(
        [[Math.min(...lats), Math.min(...lngs)], [Math.max(...lats), Math.max(...lngs)]],
        { padding: [80, 80], maxZoom: 4, duration: 0.6 }
      );
    }
    // Mise en surbrillance pulsante des éléments cibles (avec attente du rendu)
    _planHighlightWhenReady(elementIds.map(Number));
  } catch(e) { console.warn('Fit error:', e); }
}

/**
 * Met en surbrillance pulsante un ensemble d'éléments du plan pendant ~6s.
 * Fonctionne pour les trois types : point/texte (markers Leaflet) et trait/zone (polygones).
 *
 * Stratégie :
 *  - markers (point/texte)  → on ajoute la classe CSS `.plan-highlight-marker` au div interne,
 *    qui applique un halo + animation pulse via CSS.
 *  - traits / zones (paths) → on stocke le style original, on applique un style « surbrillance »
 *    (couleur vive, épaisseur×2, opacité maximum), et on ajoute la classe SVG pulse.
 *    On restaure le style original après le délai.
 *
 * Tolérant aux re-rendus du plan : si un élément est redessiné pendant le highlight,
 * il perd l'effet (acceptable, l'utilisateur a déjà vu où il était).
 */
function _planHighlightElements(elementIds) {
  if (typeof _p === 'undefined' || !_p.layers) return;
  if (!Array.isArray(elementIds) || !elementIds.length) return;

  // Pose la feuille de style une seule fois pour toute la session
  _planEnsureHighlightStyles();

  // Mémorisation des styles d'origine PAR LAYER, persistante entre les appels.
  // Sans ça, au 2e clic on capture les valeurs déjà-surbrillées comme "originales"
  // et le cleanup ne restaure plus le vrai style. WeakMap se vide automatiquement
  // quand les layers sont détruits par _pInitMap().
  if (!window._planHighlightOriginals) window._planHighlightOriginals = new WeakMap();
  const originals = window._planHighlightOriginals;

  // Annule le précédent highlight pour éviter les superpositions.
  // CRUCIAL : on annule aussi le setTimeout en attente, sinon il tirera plus tard
  // et coupera la NOUVELLE surbrillance qu'on est en train de poser.
  if (window._planHighlightPending && window._planHighlightPending.length) {
    window._planHighlightPending.forEach(({ cleanup, timerId }) => {
      clearTimeout(timerId);
      try { cleanup(); } catch(_) {}
    });
  }
  window._planHighlightPending = [];

  const DURATION_MS = 6000;

  const schedule = (cleanup) => {
    const timerId = setTimeout(() => {
      // Si l'entry est encore dans la liste, on l'enlève en plus du cleanup
      try { cleanup(); } catch(_) {}
      window._planHighlightPending = (window._planHighlightPending || [])
        .filter(p => p.timerId !== timerId);
    }, DURATION_MS);
    window._planHighlightPending.push({ cleanup, timerId });
  };

  // Compteur global pour alterner entre les classes -a et -b à chaque appel.
  // Le navigateur voit forcément un changement de classe → redémarre les
  // animations CSS, y compris celle des pseudo-éléments ::before.
  window._planHighlightTick = (window._planHighlightTick || 0) + 1;
  const suffix = (window._planHighlightTick % 2 === 0) ? '-a' : '-b';
  const otherSuffix = suffix === '-a' ? '-b' : '-a';

  elementIds.forEach(id => {
    const layer = _p.layers[id];
    if (!layer) return;
    const el = _p.elements && _p.elements.find(e => Number(e.Id) === Number(id));
    if (!el) return;

    // — Cas 1 : marker (point ou texte) —
    if (typeof layer.getElement === 'function' && layer.getElement()) {
      const domEl = layer.getElement();
      const innerDiv = domEl.querySelector('div') || domEl;
      const cls      = 'plan-highlight-marker' + suffix;
      const otherCls = 'plan-highlight-marker' + otherSuffix;
      // Retirer l'autre variante au cas où elle traîne d'un appel précédent
      innerDiv.classList.remove(otherCls);
      innerDiv.classList.add(cls);
      schedule(() => {
        try {
          innerDiv.classList.remove(cls);
          innerDiv.classList.remove(otherCls);
        } catch(_) {}
      });
    }
    // — Cas 2 : path (trait/zone polyline ou polygon) —
    else if (typeof layer.setStyle === 'function') {
      // Mémoriser l'état d'origine UNE SEULE FOIS par layer
      if (!originals.has(layer)) {
        originals.set(layer, {
          color:       layer.options.color,
          weight:      layer.options.weight,
          opacity:     layer.options.opacity,
          fillOpacity: layer.options.fillOpacity,
          dashArray:   layer.options.dashArray,
        });
      }
      const orig = originals.get(layer);

      layer.setStyle({
        color: '#ff5722',          // orange vif
        weight: Math.max((orig.weight || 2) * 2, 5),
        opacity: 1,
        fillOpacity: el.TypeElement === 'zone' ? 0.55 : (orig.fillOpacity ?? 0),
        dashArray: null,
      });
      try { if (typeof layer.bringToFront === 'function') layer.bringToFront(); } catch(_) {}

      // Pour le path SVG, même technique d'alternance -a / -b
      let pathEl = null;
      try { pathEl = layer.getElement(); } catch(_) {}
      const pathCls      = 'plan-highlight-path' + suffix;
      const pathOtherCls = 'plan-highlight-path' + otherSuffix;
      if (pathEl) {
        pathEl.classList.remove(pathOtherCls);
        pathEl.classList.add(pathCls);
      }

      schedule(() => {
        try {
          layer.setStyle({
            color:       orig.color,
            weight:      orig.weight,
            opacity:     orig.opacity,
            fillOpacity: orig.fillOpacity,
            dashArray:   orig.dashArray,
          });
          if (pathEl) {
            pathEl.classList.remove(pathCls);
            pathEl.classList.remove(pathOtherCls);
          }
        } catch(_) {}
      });
    }
  });
}

/**
 * Injecte une fois pour toutes la feuille de styles utilisée pour la surbrillance.
 * On le fait par JS plutôt que dans le CSS principal pour que cette fonctionnalité
 * reste self-contained et facile à supprimer.
 */
function _planEnsureHighlightStyles() {
  if (document.getElementById('plan-highlight-styles')) return;
  const style = document.createElement('style');
  style.id = 'plan-highlight-styles';
  // Astuce anti-bug : deux variantes -a et -b avec animations différentes nommées.
  // Le code alterne entre les deux à chaque clic → le navigateur voit forcément
  // une nouvelle règle s'appliquer et redémarre l'animation depuis le début,
  // contournant la limitation classique d'add/remove de classe identique.
  style.textContent = `
    .plan-highlight-marker-a, .plan-highlight-marker-b {
      position: relative;
      filter: drop-shadow(0 0 6px #ff5722) drop-shadow(0 0 12px rgba(255,87,34,.6)) !important;
      z-index: 1000;
    }
    .plan-highlight-marker-a { animation: plan-highlight-pulse-a 1.1s ease-in-out infinite; }
    .plan-highlight-marker-b { animation: plan-highlight-pulse-b 1.1s ease-in-out infinite; }
    .plan-highlight-marker-a::before, .plan-highlight-marker-b::before {
      content: '';
      position: absolute;
      top: 50%; left: 50%;
      width: 56px; height: 56px;
      transform: translate(-50%, -50%);
      border-radius: 50%;
      background: radial-gradient(circle, rgba(255,87,34,.35) 0%, rgba(255,87,34,0) 70%);
      pointer-events: none;
      z-index: -1;
    }
    .plan-highlight-marker-a::before { animation: plan-highlight-halo-a 1.4s ease-out infinite; }
    .plan-highlight-marker-b::before { animation: plan-highlight-halo-b 1.4s ease-out infinite; }
    @keyframes plan-highlight-pulse-a {
      0%, 100% { transform: scale(1); } 50% { transform: scale(1.25); }
    }
    @keyframes plan-highlight-pulse-b {
      0%, 100% { transform: scale(1); } 50% { transform: scale(1.25); }
    }
    @keyframes plan-highlight-halo-a {
      0%   { transform: translate(-50%,-50%) scale(.6); opacity: .9; }
      100% { transform: translate(-50%,-50%) scale(2.2); opacity: 0; }
    }
    @keyframes plan-highlight-halo-b {
      0%   { transform: translate(-50%,-50%) scale(.6); opacity: .9; }
      100% { transform: translate(-50%,-50%) scale(2.2); opacity: 0; }
    }
    /* Surbrillance d'un trait/zone (path SVG Leaflet) — même technique -a / -b */
    .plan-highlight-path-a { animation: plan-highlight-path-pulse-a 1.1s ease-in-out infinite; }
    .plan-highlight-path-b { animation: plan-highlight-path-pulse-b 1.1s ease-in-out infinite; }
    @keyframes plan-highlight-path-pulse-a {
      0%, 100% { stroke-opacity: 1;   stroke-width: 5; }
      50%      { stroke-opacity: .55; stroke-width: 9; }
    }
    @keyframes plan-highlight-path-pulse-b {
      0%, 100% { stroke-opacity: 1;   stroke-width: 5; }
      50%      { stroke-opacity: .55; stroke-width: 9; }
    }
  `;
  document.head.appendChild(style);
}

function _showRgpdDetail() {
  _addMsg('bot', `<div style="font-size:12px;line-height:1.7">
    <strong>🔒 Protection des données (RGPD)</strong><br><br>
    <strong>Anonymisation :</strong> Avant chaque envoi à l'IA, les données personnelles (noms, prénoms, emails, numéros de téléphone) sont automatiquement remplacées par des marqueurs anonymes ([personne], [email], [téléphone]). L'IA ne reçoit jamais vos données personnelles.<br><br>
    <strong>Pas de stockage :</strong> Aucune conversation n'est enregistrée côté serveur. L'historique est uniquement en mémoire dans votre navigateur et disparaît à la fermeture de la page.<br><br>
    <strong>Minimisation :</strong> Seules les données strictement nécessaires à la réponse sont transmises (pas d'export en masse). Les résultats sont limités à 30 éléments maximum par catégorie.<br><br>
    <strong>Fournisseur local :</strong> Si vous utilisez Ollama ou LM Studio, aucune donnée ne quitte votre réseau local.<br><br>
    <strong>Droit d'accès :</strong> Contactez votre administrateur Larka pour exercer vos droits (accès, rectification, suppression).
  </div>`);
}

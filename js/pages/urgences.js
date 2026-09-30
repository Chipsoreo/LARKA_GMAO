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
 * Larka — Procédures d'urgence
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Page de CONSULTATION (lecture seule) accessible à tous les rôles.
 * Affiche les contacts à joindre selon le type de problème, configurés par
 * les gestionnaires depuis Configuration → 🚨 Urgences.
 *
 * PRINCIPES UX :
 *   - Conçue pour usage en situation de stress : grosses cartes, gros boutons,
 *     numéros de téléphone TRES visibles et cliquables (tel: / mailto:)
 *   - Pas d'interaction superflue : on ouvre, on appelle.
 *   - Numéros français d'urgence (15/18/112) toujours rappelés en tête,
 *     même si la config est vide ou incomplète.
 *
 * STOCKAGE :
 *   - urgences_actif : '1' = onglet visible par tout le monde
 *                      '0' = onglet réservé aux Gestionnaires/Admin
 *   - urgences_config : JSON sérialisé = { categories: [...] }
 *     Chaque catégorie : { id, icon, titre, description, masquee,
 *                          visibilite, procedure, medias: [...], contacts: [...] }
 *       · visibilite : 'tous' (défaut) | 'gestionnaires'
 *       · procedure  : texte libre, une étape par ligne
 *       · medias     : [{ id, type:'photo'|'video', fichier, nom, legende }]
 *     Chaque contact : { nom, role, tel, mail, note }
 *
 * Le format JSON est aussi lu par configuration.js pour l'édition.
 *
 * ⚠️ VISIBILITÉ — le filtrage est fait CÔTÉ SERVEUR (api/UrgencesAcl.php) :
 *   `config_check` retire les catégories 'gestionnaires' avant de répondre à un
 *   Demandeur/Technicien/Visionneur, et les médias correspondants sont refusés
 *   en 403. Le code ci-dessous n'a donc PAS à re-filtrer pour des raisons de
 *   sécurité — il se contente d'afficher un badge « Réservé » aux gestionnaires,
 *   qui eux reçoivent tout.
 *
 * Dépendances : apiRequest (api.js), toast (ui.js).
 * ═══════════════════════════════════════════════════════════════════════════════
 */

// ─── Catégories prédéfinies (servent de défaut si rien n'est configuré) ─────
// Le gestionnaire peut les modifier, les désactiver ou en ajouter d'autres.
const URGENCES_CATEGORIES_DEFAUT = [
  { id:'incendie',    icon:'🔥', titre:'Incendie / fumée',         description:'Feu, fumée suspecte, déclenchement alarme incendie', contacts:[] },
  { id:'medical',     icon:'🚑', titre:'Urgence médicale',         description:'Blessure, malaise, accident corporel',                contacts:[] },
  { id:'intrusion',   icon:'🚔', titre:'Intrusion / sécurité',     description:'Personne suspecte, vol, agression, violence',         contacts:[] },
  { id:'plomberie',   icon:'💧', titre:'Plomberie / fuite d\'eau', description:'Fuite, dégât des eaux, canalisation bouchée',         contacts:[] },
  { id:'electricite', icon:'⚡', titre:'Électricité / panne',       description:'Coupure générale, court-circuit, étincelles',         contacts:[] },
  { id:'gaz',         icon:'🟡', titre:'Fuite de gaz',             description:'Odeur de gaz, sifflement suspect',                    contacts:[] },
  { id:'autre',       icon:'❓', titre:'Autre problème',            description:'Cas non listés ci-dessus',                            contacts:[] },
];

// Numéros officiels français — affichés en bandeau permanent
const URGENCES_OFFICIELLES = [
  { num:'112', label:'Toutes urgences (Europe)',   color:'#dc2626' },
  { num:'15',  label:'SAMU',                       color:'#dc2626' },
  { num:'18',  label:'Pompiers',                   color:'#dc2626' },
  { num:'17',  label:'Police / Gendarmerie',       color:'#1d4ed8' },
];

/**
 * Charge la configuration des urgences (lecture publique via config_check).
 * Retourne toujours un objet { categories: [...] } valide, même en cas d'erreur.
 */
async function _urgencesLoadConfig() {
  try {
    const res = await apiRequest('config_check&key=urgences_config');
    if (res?.value) {
      const parsed = JSON.parse(res.value);
      if (parsed && Array.isArray(parsed.categories)) return parsed;
    }
  } catch(_) { /* silencieux : on tombe sur les défauts */ }
  // Aucune config encore enregistrée : on renvoie les catégories par défaut vides
  return { categories: URGENCES_CATEGORIES_DEFAUT.map(c => ({...c, contacts: []})) };
}

/**
 * Échappe une chaîne pour insertion dans du HTML.
 */
function _urgEscape(s) {
  if (s == null) return '';
  return String(s).replace(/[&<>"']/g, ch => ({
    '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;'
  }[ch]));
}


/**
 * Construit un lien d'appel selon l'application choisie par l'utilisateur.
 * Préférence stockée dans localStorage sous 'gmao_tel_app'.
 * Options : 'tel' (classique), 'teams', 'skype', 'whatsapp'
 */
function _urgGetTelApp() {
  return localStorage.getItem('gmao_tel_app') || 'tel';
}
function _urgSetTelApp(app) {
  localStorage.setItem('gmao_tel_app', app);
}

const URG_TEL_APPS = {
  tel:      { label: '📞 Appel classique',      icon: '📞', prefix: num => 'tel:' + num },
  teams:    { label: '🟣 Microsoft Teams',       icon: '🟣', prefix: num => 'https://teams.microsoft.com/l/call/0/0?users=4:' + encodeURIComponent(num) },
  skype:    { label: '🔵 Skype',                 icon: '🔵', prefix: num => 'skype:' + num + '?call' },
  whatsapp: { label: '🟢 WhatsApp',              icon: '🟢', prefix: num => 'https://wa.me/' + num.replace(/^\+/, '') },
};

function _urgBuildCallHref(tel) {
  if (!tel) return '';
  const clean = String(tel).replace(/[^\d+]/g, '');
  if (!clean) return '';
  const app = _urgGetTelApp();
  const builder = URG_TEL_APPS[app] || URG_TEL_APPS.tel;
  return builder.prefix(clean);
}

/**
 * Affiche le sélecteur d'application d'appel.
 */
function _urgShowTelAppChooser(event) {
  event.preventDefault();
  event.stopPropagation();
  // Supprimer tout popup existant
  document.querySelectorAll('.urg-tel-popup').forEach(el => el.remove());

  const btn = event.currentTarget;
  const tel = btn.getAttribute('data-tel');
  const current = _urgGetTelApp();

  const popup = document.createElement('div');
  popup.className = 'urg-tel-popup';
  popup.innerHTML = `
    <div class="urg-tel-popup-title">Appeler avec :</div>
    ${Object.entries(URG_TEL_APPS).map(([key, app]) => `
      <a href="#" class="urg-tel-popup-option ${key === current ? 'active' : ''}"
         data-app="${key}" data-tel="${_urgEscape(tel)}">
        ${app.label}
        ${key === current ? '<span style="margin-left:auto;font-size:11px;opacity:.6">✓ par défaut</span>' : ''}
      </a>
    `).join('')}
    <div class="urg-tel-popup-hint">Votre choix sera mémorisé pour les prochains appels.</div>
  `;

  // Positionner le popup
  btn.style.position = 'relative';
  btn.parentElement.style.position = 'relative';
  btn.parentElement.appendChild(popup);

  // Gestion des clics
  popup.querySelectorAll('.urg-tel-popup-option').forEach(opt => {
    opt.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      const appKey = opt.getAttribute('data-app');
      const rawTel = opt.getAttribute('data-tel');
      const clean  = String(rawTel).replace(/[^\d+]/g, '');
      _urgSetTelApp(appKey);
      popup.remove();
      // Ouvrir le lien
      const builder = URG_TEL_APPS[appKey] || URG_TEL_APPS.tel;
      window.open(builder.prefix(clean), '_blank');
      // Mettre à jour tous les boutons tel de la page pour refléter la nouvelle icône
      _urgRefreshTelButtons();
    });
  });

  // Fermer si clic en dehors
  setTimeout(() => {
    const closeHandler = (e) => {
      if (!popup.contains(e.target)) {
        popup.remove();
        document.removeEventListener('click', closeHandler, true);
      }
    };
    document.addEventListener('click', closeHandler, true);
  }, 10);
}

/**
 * Met à jour visuellement tous les boutons tel après changement de préférence.
 */
function _urgRefreshTelButtons() {
  const app = _urgGetTelApp();
  const appInfo = URG_TEL_APPS[app];
  document.querySelectorAll('.urg-btn-tel').forEach(btn => {
    const tel = btn.getAttribute('data-tel');
    if (tel) {
      const clean = String(tel).replace(/[^\d+]/g, '');
      const builder = URG_TEL_APPS[app] || URG_TEL_APPS.tel;
      btn.href = builder.prefix(clean);
      // Mettre à jour l'icône du bouton
      const icon = btn.childNodes[0];
      if (icon && icon.nodeType === 3) {
        // Remplacer le premier text node (l'icône emoji)
      }
      const labelSpan = btn.querySelector('.urg-tel-label');
      if (labelSpan) {
        labelSpan.textContent = tel;
      }
    }
  });
  // Mettre à jour l'indicateur de préférence
  const prefIndicator = document.getElementById('urg-tel-pref-indicator');
  if (prefIndicator && appInfo) {
    prefIndicator.innerHTML = `📱 Les appels s'ouvrent avec <strong>${appInfo.label}</strong> — <a onclick="document.querySelector('.urg-btn-tel-choose')?.click()">modifier</a>`;
  }
}

/**
 * Point d'entrée — appelé par navigate('urgences').
 */
async function renderUrgences() {
  const main = document.getElementById('mainContent');
  if (!main) return;

  main.innerHTML = `
    <style>
      .urg-wrap { max-width: 1000px; margin: 0 auto; padding: 0 4px 40px; }
      .urg-banner {
        background: linear-gradient(135deg, #dc2626 0%, #991b1b 100%);
        color: #fff;
        border-radius: 14px;
        padding: 18px 22px;
        margin-bottom: 20px;
        box-shadow: var(--shadow);
      }
      .urg-banner-title { font-size: 16px; font-weight: 700; margin-bottom: 12px; display: flex; align-items: center; gap: 8px; }
      .urg-officiels { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; }
      .urg-officiel-btn {
        display: flex;
        align-items: center;
        justify-content: center;
        flex-direction: column;
        gap: 4px;
        background: rgba(255,255,255,0.15);
        border: 2px solid rgba(255,255,255,0.4);
        border-radius: 10px;
        padding: 12px 8px;
        color: #fff;
        text-decoration: none;
        transition: all .15s;
        font-family: inherit;
      }
      .urg-officiel-btn:hover { background: rgba(255,255,255,0.28); transform: translateY(-1px); }
      .urg-officiel-num { font-size: 28px; font-weight: 700; letter-spacing: 1px; }
      .urg-officiel-label { font-size: 11px; opacity: 0.92; text-align: center; line-height: 1.2; }
      @media (max-width: 700px) {
        .urg-officiels { grid-template-columns: repeat(2, 1fr); }
      }

      .urg-intro {
        background: var(--card-bg, #fff);
        border-left: 4px solid var(--orange);
        border-radius: 8px;
        padding: 14px 18px;
        margin-bottom: 20px;
        font-size: 13px;
        color: var(--text-mid);
        line-height: 1.55;
      }

      .urg-preview-banner {
        background: linear-gradient(135deg, #f59e0b22, #f59e0b11);
        border: 1px dashed #f59e0b;
        border-radius: 10px;
        padding: 12px 16px;
        margin-bottom: 16px;
        font-size: 12px;
        color: var(--text-mid);
        display: flex;
        align-items: center;
        gap: 10px;
      }
      .urg-preview-banner strong { color: var(--text); }

      .urg-tel-pref {
        display: inline-flex; align-items: center; gap: 6px;
        font-size: 12px; color: var(--gray-text);
        margin-top: 8px;
      }
      .urg-tel-pref a { color: var(--blue); cursor: pointer; text-decoration: underline; }

      .urg-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
      @media (max-width: 700px) { .urg-grid { grid-template-columns: 1fr; } }

      .urg-card {
        background: var(--card-bg, #fff);
        border: 1px solid var(--gray-border);
        border-radius: 12px;
        padding: 16px 18px;
        box-shadow: var(--shadow-sm);
        transition: border-color .15s, box-shadow .15s;
      }
      .urg-card:hover { border-color: var(--blue); box-shadow: var(--shadow); }
      .urg-card-head {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        padding-bottom: 12px;
        margin-bottom: 12px;
        border-bottom: 1px solid var(--gray-border);
      }
      .urg-card-icon { font-size: 32px; line-height: 1; flex-shrink: 0; }
      .urg-card-titre { font-size: 16px; font-weight: 700; color: var(--text); margin-bottom: 2px; }
      .urg-card-desc { font-size: 12px; color: var(--gray-text); line-height: 1.4; }

      .urg-contact {
        background: var(--gray-bg);
        border-radius: 8px;
        padding: 10px 12px;
        margin-top: 8px;
      }
      .urg-contact-head { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 6px; gap: 8px; }
      .urg-contact-nom { font-size: 13px; font-weight: 700; color: var(--text); }
      .urg-contact-role { font-size: 11px; color: var(--gray-text); font-style: italic; }
      .urg-contact-note { font-size: 11px; color: var(--text-mid); margin-top: 4px; line-height: 1.4; }
      .urg-contact-actions { display: flex; gap: 6px; margin-top: 8px; flex-wrap: wrap; position: relative; align-items: stretch; }
      /* BUG CORRIGÉ : « overflow:hidden » servait à joindre les deux boutons
         avec des coins arrondis communs — mais il découpait aussi le popup de
         choix d'application, positionné en absolu AU-DESSUS du groupe. Le
         bouton ▾ fonctionnait, le popup était créé… et rendu invisible.
         On obtient le même rendu en arrondissant explicitement les extrémités,
         et « position:relative » ancre le popup sur le groupe. */
      .urg-tel-group { display: inline-flex; align-items: stretch; border-radius: 8px; position: relative; }
      .urg-tel-group .urg-btn-tel { border-top-right-radius: 0; border-bottom-right-radius: 0; }
      .urg-tel-group .urg-btn-tel-choose { border-top-left-radius: 0; border-bottom-left-radius: 0; }
      .urg-btn-tel, .urg-btn-mail {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 8px 12px;
        border-radius: 8px;
        font-size: 13px;
        font-weight: 600;
        text-decoration: none;
        transition: all .15s;
      }
      .urg-btn-tel { background: #16a34a; color: #fff; border-top-right-radius: 0; border-bottom-right-radius: 0; }
      .urg-btn-tel:hover { background: #15803d; transform: translateY(-1px); }
      .urg-btn-mail { background: var(--blue); color: #fff; }
      .urg-btn-mail:hover { background: #1f5fb8; transform: translateY(-1px); }

      .urg-btn-tel-choose {
        background: #15803d; color: #fff; border: none;
        padding: 8px 8px; font-size: 12px; cursor: pointer;
        border-left: 1px solid rgba(255,255,255,0.3);
        border-top-right-radius: 8px; border-bottom-right-radius: 8px;
        transition: all .15s; font-family: inherit;
        line-height: 1;
      }
      .urg-btn-tel-choose:hover { background: #166534; }

      .urg-tel-popup {
        position: absolute; bottom: calc(100% + 6px); left: 0;
        background: var(--card-bg, #fff); border: 1px solid var(--gray-border);
        border-radius: 10px; box-shadow: 0 8px 30px rgba(0,0,0,.18);
        z-index: 100; min-width: 260px; padding: 6px;
        animation: urgPopIn .15s ease-out;
      }
      /* Sur un écran étroit, un popup de 260 px ancré à gauche d'un bouton déjà
         proche du bord droit sortirait de l'écran : on l'ancre à droite et on
         le laisse rétrécir. */
      @media (max-width: 700px) {
        .urg-tel-popup {
          left: auto; right: 0;
          min-width: 0; width: max-content; max-width: calc(100vw - 48px);
        }
      }
      @keyframes urgPopIn { from { opacity:0; transform:translateY(6px); } to { opacity:1; transform:translateY(0); } }
      .urg-tel-popup-title {
        font-size: 11px; font-weight: 700; color: var(--gray-text);
        padding: 6px 10px 4px; text-transform: uppercase; letter-spacing: .5px;
      }
      .urg-tel-popup-option {
        display: flex; align-items: center; gap: 6px;
        padding: 10px 12px; border-radius: 8px; font-size: 13px;
        color: var(--text); text-decoration: none; cursor: pointer;
        transition: background .12s;
      }
      .urg-tel-popup-option:hover { background: var(--blue-pale, #eff6ff); }
      .urg-tel-popup-option.active { background: var(--gray-bg); font-weight: 600; }
      .urg-tel-popup-hint {
        font-size: 10px; color: var(--gray-text); padding: 4px 10px 6px;
        border-top: 1px solid var(--gray-border); margin-top: 4px;
      }

      .urg-empty {
        text-align: center;
        padding: 14px;
        color: var(--gray-text);
        font-size: 12px;
        font-style: italic;
      }

      .urg-no-config {
        text-align: center;
        padding: 50px 20px;
        color: var(--gray-text);
      }
      .urg-no-config-ico { font-size: 48px; margin-bottom: 12px; }

      /* ── Badge « Réservé aux gestionnaires » ── */
      .urg-badge-resto {
        font-size: 10px; font-weight: 700; padding: 3px 8px; border-radius: 20px;
        background: var(--urg-resto-bg); color: var(--urg-resto-fg); border: 1px solid var(--urg-resto-bd);
        white-space: nowrap; flex-shrink: 0;
      }

      /* ── Bloc procédure ── */
      .urg-procedure {
        background: var(--urg-proc-bg);
        border: 1px solid var(--urg-proc-bd);
        border-left: 4px solid var(--urg-proc-accent);
        border-radius: 10px;
        padding: 12px 14px;
        margin-bottom: 12px;
      }
      .urg-procedure-titre {
        font-size: 12px; font-weight: 700; color: var(--urg-proc-titre);
        text-transform: uppercase; letter-spacing: .5px;
        display: flex; align-items: center; gap: 6px; margin-bottom: 8px;
      }
      /* Marges/paddings à 0 : on gère l'indentation via le compteur pour que les
         numéros restent alignés même au-delà de 9 étapes. */
      .urg-etapes { list-style: none; margin: 0; padding: 0; counter-reset: urgetape; }
      .urg-etapes li {
        counter-increment: urgetape;
        position: relative;
        padding: 6px 0 6px 34px;
        font-size: 14px;
        line-height: 1.5;
        color: var(--text);
      }
      .urg-etapes li + li { border-top: 1px dashed var(--urg-proc-bd); }
      .urg-etapes li::before {
        content: counter(urgetape);
        position: absolute; left: 0; top: 6px;
        width: 24px; height: 24px; border-radius: 50%;
        background: var(--urg-proc-accent); color: #fff;
        font-size: 12px; font-weight: 700;
        display: flex; align-items: center; justify-content: center;
      }
      .urg-procedure-texte {
        font-size: 14px; line-height: 1.6; color: var(--text);
        white-space: pre-wrap;
      }

      /* ── Galerie photos / vidéos ── */
      .urg-medias { margin-bottom: 12px; }
      .urg-medias-titre {
        font-size: 11px; font-weight: 700; color: var(--gray-text);
        text-transform: uppercase; letter-spacing: .4px; margin-bottom: 6px;
      }
      .urg-medias-grid {
        display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 8px;
      }
      .urg-media {
        position: relative; border: 1px solid var(--gray-border);
        border-radius: 8px; overflow: hidden; cursor: pointer;
        background: #000; padding: 0; display: block; width: 100%;
        font-family: inherit; transition: transform .12s, border-color .12s;
      }
      .urg-media:hover { transform: translateY(-2px); border-color: var(--blue); }
      .urg-media img, .urg-media video {
        width: 100%; height: 90px; object-fit: cover; display: block;
      }
      .urg-media-play {
        position: absolute; inset: 0; display: flex;
        align-items: center; justify-content: center;
        font-size: 28px; color: #fff; text-shadow: 0 2px 8px rgba(0,0,0,.6);
        pointer-events: none;
      }
      .urg-media-legende {
        position: absolute; bottom: 0; left: 0; right: 0;
        background: linear-gradient(transparent, rgba(0,0,0,.8));
        color: #fff; font-size: 10px; padding: 12px 6px 4px;
        text-align: left; line-height: 1.3;
        overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
      }

      /* ── Visionneuse plein écran ── */
      .urg-lightbox {
        position: fixed; inset: 0; z-index: 9999;
        background: rgba(0,0,0,.92);
        display: flex; flex-direction: column;
        align-items: center; justify-content: center;
        padding: 20px;
      }
      .urg-lightbox img, .urg-lightbox video {
        max-width: 100%; max-height: calc(100vh - 140px);
        border-radius: 8px; background: #000;
      }
      .urg-lb-close {
        position: absolute; top: 14px; right: 16px;
        width: 42px; height: 42px; border-radius: 50%;
        background: rgba(255,255,255,.15); color: #fff; border: none;
        font-size: 20px; cursor: pointer; line-height: 1;
      }
      .urg-lb-close:hover { background: rgba(255,255,255,.3); }
      .urg-lb-nav {
        position: absolute; top: 50%; transform: translateY(-50%);
        width: 46px; height: 46px; border-radius: 50%;
        background: rgba(255,255,255,.15); color: #fff; border: none;
        font-size: 22px; cursor: pointer; line-height: 1;
      }
      .urg-lb-nav:hover { background: rgba(255,255,255,.3); }
      .urg-lb-prev { left: 14px; }
      .urg-lb-next { right: 14px; }
      .urg-lb-caption {
        color: #fff; font-size: 13px; margin-top: 14px;
        text-align: center; max-width: 700px; line-height: 1.5;
      }
      .urg-lb-compteur { color: rgba(255,255,255,.6); font-size: 11px; margin-top: 6px; }
      @media (max-width: 700px) {
        .urg-lb-nav { width: 38px; height: 38px; font-size: 18px; }
      }
    </style>

    <div class="urg-wrap">
      <div class="urg-banner">
        <div class="urg-banner-title">📞 Numéros d'urgence officiels</div>
        <div class="urg-officiels">
          ${URGENCES_OFFICIELLES.map(o => `
            <a href="tel:${o.num}" class="urg-officiel-btn">
              <span class="urg-officiel-num">${o.num}</span>
              <span class="urg-officiel-label">${o.label}</span>
            </a>
          `).join('')}
        </div>
      </div>

      <div class="urg-intro">
        ℹ️ Cette page liste les contacts à joindre selon le type de problème rencontré sur le site.
        Pour toute urgence vitale, composez d'abord les numéros officiels ci-dessus.
        <div class="urg-tel-pref" id="urg-tel-pref-indicator"></div>
      </div>

      <div id="urg-preview-slot"></div>

      <div id="urg-content"><div style="text-align:center;padding:30px;color:var(--gray-text)">⏳ Chargement…</div></div>
    </div>
  `;

  // Afficher l'indicateur d'application d'appel préférée
  const prefIndicator = document.getElementById('urg-tel-pref-indicator');
  if (prefIndicator) {
    const appKey = _urgGetTelApp();
    const appInfo = URG_TEL_APPS[appKey] || URG_TEL_APPS.tel;
    prefIndicator.innerHTML = `📱 Les appels s'ouvrent avec <strong>${appInfo.label}</strong> — <a onclick="document.querySelector('.urg-btn-tel-choose')?.click()">modifier</a>`;
  }

  // Bandeau "aperçu" pour Gestionnaire/Admin quand le module est désactivé
  const previewSlot = document.getElementById('urg-preview-slot');
  if (previewSlot) {
    const role = App.currentUser?.Role;
    const isManager = (role === 'Admin' || role === 'Gestionnaire');
    if (isManager && !App._urgencesActif) {
      previewSlot.innerHTML = `
        <div class="urg-preview-banner">
          <span style="font-size:20px">👁️</span>
          <div>
            <strong>Mode aperçu</strong> — Cet onglet est actuellement réservé aux
            Gestionnaires et Admins ; les autres rôles ne le voient pas.
            Vous y accédez car vous êtes ${role}. Pour l'ouvrir à tout le monde, allez dans
            <a href="#" onclick="navigate('configuration');setTimeout(()=>switchConfigTab&&switchConfigTab('urgences'),300);return false"
               style="color:var(--blue);text-decoration:underline">Configuration → 🚨 Urgences</a>.
          </div>
        </div>`;
    }
  }

  const cfg = await _urgencesLoadConfig();
  const slot = document.getElementById('urg-content');
  if (!slot) return;

  // Filtrer : on affiche les catégories qui ont au moins un contact OU qui sont
  // explicitement marquées visibles. Si tout est vide, on garde quand même la
  // catégorie "Autre" pour donner un point d'entrée visuel.
  const visibles = (cfg.categories || []).filter(c => !c.masquee);

  if (visibles.length === 0) {
    slot.innerHTML = `
      <div class="urg-no-config">
        <div class="urg-no-config-ico">🚧</div>
        <div style="font-weight:600;margin-bottom:6px">Procédures d'urgence non encore configurées</div>
        <div style="font-size:12px">Demandez à un gestionnaire de renseigner les contacts dans Configuration → 🚨 Urgences.</div>
      </div>`;
    return;
  }

  // Mémorisé pour la visionneuse plein écran, qui retrouve un média via son
  // couple d'index (catégorie, média) plutôt que de re-parser le DOM.
  _urgVisibles = visibles;

  slot.innerHTML = `
    <div class="urg-grid">
      ${visibles.map((cat, i) => _urgRenderCard(cat, i)).join('')}
    </div>
  `;
}

// Catégories effectivement affichées, dans l'ordre de rendu.
let _urgVisibles = [];

/**
 * Rend une carte catégorie avec ses contacts.
 */
function _urgRenderCard(cat, catIdx) {
  const icon  = _urgEscape(cat.icon  || '❓');
  const titre = _urgEscape(cat.titre || 'Sans titre');
  const desc  = _urgEscape(cat.description || '');
  const contacts = Array.isArray(cat.contacts) ? cat.contacts : [];

  // Le badge n'apparaît que pour les gestionnaires : les autres rôles ne
  // reçoivent tout simplement pas ces catégories (filtrage serveur).
  const role      = App.currentUser?.Role;
  const isManager = (role === 'Admin' || role === 'Gestionnaire');
  const reserve   = isManager && (cat.visibilite === 'gestionnaires');

  return `
    <div class="urg-card">
      <div class="urg-card-head">
        <div class="urg-card-icon">${icon}</div>
        <div style="flex:1;min-width:0">
          <div class="urg-card-titre">${titre}</div>
          ${desc ? `<div class="urg-card-desc">${desc}</div>` : ''}
        </div>
        ${reserve ? '<span class="urg-badge-resto" title="Cette procédure n\'est visible que par les Gestionnaires et Admins">🔒 Réservé</span>' : ''}
      </div>

      ${_urgRenderProcedure(cat)}
      ${_urgRenderMedias(cat, catIdx)}

      ${contacts.length === 0
        ? '<div class="urg-empty">Aucun contact renseigné pour cette catégorie.</div>'
        : contacts.map(_urgRenderContact).join('')
      }
    </div>
  `;
}

/**
 * Rend la procédure à suivre.
 *
 * Le gestionnaire saisit du texte libre, une étape par ligne. On détecte les
 * puces/numérotations éventuelles pour les retirer (sinon on afficherait
 * « 1. 1. Couper l'eau ») et on renumérote proprement via un compteur CSS.
 * Si le texte tient sur une seule ligne, on le rend en paragraphe : une liste
 * numérotée à un seul élément n'apporterait rien.
 */
function _urgRenderProcedure(cat) {
  const brut = String(cat.procedure || '').trim();
  if (!brut) return '';

  const lignes = brut
    .split(/\r?\n/)
    .map(l => l.trim())
    // Retire « 1. », « 1) », « - », « • », « * » en tête de ligne
    .map(l => l.replace(/^\s*(?:\d+\s*[.)\]-]|[-–—•*])\s*/, '').trim())
    .filter(Boolean);

  const corps = (lignes.length <= 1)
    ? `<div class="urg-procedure-texte">${_urgEscape(lignes[0] || brut)}</div>`
    : `<ol class="urg-etapes">${lignes.map(l => `<li>${_urgEscape(l)}</li>`).join('')}</ol>`;

  return `
    <div class="urg-procedure">
      <div class="urg-procedure-titre"><span>📋</span> Procédure à suivre</div>
      ${corps}
    </div>
  `;
}

/**
 * Rend la galerie de photos / vidéos d'une catégorie.
 * Les vignettes ouvrent la visionneuse plein écran.
 */
function _urgRenderMedias(cat, catIdx) {
  const medias = (Array.isArray(cat.medias) ? cat.medias : []).filter(m => m && m.fichier);
  if (medias.length === 0) return '';
  if (typeof UrgencesApi === 'undefined') return '';

  const vignettes = medias.map((m, mi) => {
    const url     = UrgencesApi.mediaUrl(m.fichier);
    const legende = _urgEscape(m.legende || m.nom || '');
    const apercu  = (m.type === 'video')
      // preload="metadata" : on ne télécharge que l'en-tête pour la vignette,
      // pas la vidéo entière — important sur mobile en 4G.
      ? `<video src="${url}#t=0.5" preload="metadata" muted playsinline></video>
         <span class="urg-media-play">▶</span>`
      : `<img src="${url}" alt="${legende}" loading="lazy">`;

    return `
      <button type="button" class="urg-media"
        onclick="_urgOpenLightbox(${catIdx}, ${mi})"
        title="${legende || 'Agrandir'}">
        ${apercu}
        ${legende ? `<span class="urg-media-legende">${legende}</span>` : ''}
      </button>
    `;
  }).join('');

  const nbPhotos = medias.filter(m => m.type !== 'video').length;
  const nbVideos = medias.length - nbPhotos;
  const libelle  = [
    nbPhotos ? nbPhotos + (nbPhotos > 1 ? ' photos' : ' photo') : '',
    nbVideos ? nbVideos + (nbVideos > 1 ? ' vidéos' : ' vidéo') : '',
  ].filter(Boolean).join(' · ');

  return `
    <div class="urg-medias">
      <div class="urg-medias-titre">🖼️ ${libelle}</div>
      <div class="urg-medias-grid">${vignettes}</div>
    </div>
  `;
}

/**
 * Rend un contact individuel avec boutons tel/mail.
 */
function _urgRenderContact(contact) {
  const nom  = _urgEscape(contact.nom || 'Contact');
  const role = _urgEscape(contact.role || '');
  const tel  = String(contact.tel || '').trim();
  const mail = String(contact.mail || '').trim();
  const note = _urgEscape(contact.note || '');
  const callHref = _urgBuildCallHref(tel);
  const telDisp  = _urgEscape(tel);
  const mailEsc  = _urgEscape(mail);
  const appKey   = _urgGetTelApp();
  const appInfo  = URG_TEL_APPS[appKey] || URG_TEL_APPS.tel;

  const actions = [];
  if (tel) {
    // Bouton d'appel principal + dropdown choix d'appli
    actions.push(`
      <div class="urg-tel-group">
        <a href="${callHref || '#'}" class="urg-btn-tel" data-tel="${telDisp}"
           target="_blank" rel="noopener"
           title="Appeler via ${_urgEscape(appInfo.label.replace(/^[^\s]+\s/, ''))}">
          ${appInfo.icon} <span class="urg-tel-label">${telDisp}</span>
        </a>
        <button class="urg-btn-tel-choose" onclick="_urgShowTelAppChooser(event)"
          data-tel="${telDisp}"
          title="Choisir l'application d'appel">▾</button>
      </div>
    `);
  }
  if (mail) {
    actions.push(`<a href="mailto:${mailEsc}" class="urg-btn-mail">✉️ ${mailEsc}</a>`);
  }

  return `
    <div class="urg-contact">
      <div class="urg-contact-head">
        <span class="urg-contact-nom">${nom}</span>
        ${role ? `<span class="urg-contact-role">${role}</span>` : ''}
      </div>
      ${note ? `<div class="urg-contact-note">${note}</div>` : ''}
      ${actions.length ? `<div class="urg-contact-actions">${actions.join('')}</div>` : ''}
    </div>
  `;
}

// ═══════════════════════════════════════════════════════════════════════════════
//  VISIONNEUSE PLEIN ÉCRAN (photos et vidéos)
// ═══════════════════════════════════════════════════════════════════════════════
//
//  Volontairement minimaliste : en situation d'urgence, l'utilisateur doit
//  pouvoir agrandir une photo et refermer sans réfléchir. Fermeture au clic
//  sur le fond, au bouton ✕ ou avec Échap ; navigation aux flèches ← →.

let _urgLbCat   = 0;   // index de catégorie en cours
let _urgLbIdx   = 0;   // index du média en cours
let _urgLbKeyHandler = null;

function _urgLbMedias(catIdx) {
  const cat = _urgVisibles?.[catIdx];
  return (Array.isArray(cat?.medias) ? cat.medias : []).filter(m => m && m.fichier);
}

function _urgOpenLightbox(catIdx, mediaIdx) {
  const medias = _urgLbMedias(catIdx);
  if (!medias.length) return;
  _urgLbCat = catIdx;
  _urgLbIdx = Math.max(0, Math.min(mediaIdx, medias.length - 1));

  // Une seule visionneuse à la fois
  document.querySelectorAll('.urg-lightbox').forEach(el => el.remove());

  const box = document.createElement('div');
  box.className = 'urg-lightbox';
  // Clic sur le fond (et non sur le média) = fermeture
  box.addEventListener('click', (e) => {
    if (e.target === box) _urgCloseLightbox();
  });
  document.body.appendChild(box);
  document.body.style.overflow = 'hidden';

  _urgLbKeyHandler = (e) => {
    if (e.key === 'Escape')     _urgCloseLightbox();
    if (e.key === 'ArrowLeft')  _urgLbStep(-1);
    if (e.key === 'ArrowRight') _urgLbStep(1);
  };
  document.addEventListener('keydown', _urgLbKeyHandler);

  _urgLbRender();
}

function _urgLbRender() {
  const box = document.querySelector('.urg-lightbox');
  if (!box) return;
  const medias = _urgLbMedias(_urgLbCat);
  const m = medias[_urgLbIdx];
  if (!m) return _urgCloseLightbox();

  const url     = UrgencesApi.mediaUrl(m.fichier);
  const legende = _urgEscape(m.legende || m.nom || '');
  const multi   = medias.length > 1;

  const contenu = (m.type === 'video')
    ? `<video src="${url}" controls autoplay playsinline preload="metadata"></video>`
    : `<img src="${url}" alt="${legende}">`;

  box.innerHTML = `
    <button class="urg-lb-close" onclick="_urgCloseLightbox()" title="Fermer (Échap)">✕</button>
    ${multi ? '<button class="urg-lb-nav urg-lb-prev" onclick="_urgLbStep(-1)" title="Précédent (←)">‹</button>' : ''}
    ${multi ? '<button class="urg-lb-nav urg-lb-next" onclick="_urgLbStep(1)"  title="Suivant (→)">›</button>' : ''}
    ${contenu}
    ${legende ? `<div class="urg-lb-caption">${legende}</div>` : ''}
    ${multi ? `<div class="urg-lb-compteur">${_urgLbIdx + 1} / ${medias.length}</div>` : ''}
  `;
}

function _urgLbStep(delta) {
  const medias = _urgLbMedias(_urgLbCat);
  if (medias.length < 2) return;
  // Modulo « positif » : -1 sur le premier média renvoie bien au dernier.
  _urgLbIdx = (_urgLbIdx + delta + medias.length) % medias.length;
  _urgLbRender();
}

function _urgCloseLightbox() {
  document.querySelectorAll('.urg-lightbox').forEach(el => {
    // Stopper explicitement la lecture : un <video> retiré du DOM peut
    // continuer à jouer le son sur certains navigateurs mobiles.
    el.querySelectorAll('video').forEach(v => { try { v.pause(); } catch(_) {} });
    el.remove();
  });
  document.body.style.overflow = '';
  if (_urgLbKeyHandler) {
    document.removeEventListener('keydown', _urgLbKeyHandler);
    _urgLbKeyHandler = null;
  }
}

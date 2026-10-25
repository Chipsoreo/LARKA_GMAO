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
 * Larka — Protection des données (RGPD) : affichage de la politique.
 *
 * Ouverte depuis l'écran de connexion (lien sous le formulaire), le menu de
 * l'application et l'assistant IA (« En savoir plus »). La politique vient de
 * l'API (api/Rgpd.php) en TEXTE : elle est rendue nœud par nœud, jamais en
 * innerHTML — paragraphes, listes « - » et liens (web, adresses électroniques).
 *
 * Une politique personnalisée par l'organisation le dit : bandeau en tête,
 * mention « Personnalisé par l'entreprise » sur chaque section concernée.
 *
 *   LarkaRgpd.afficher()                      politique de l'organisation courante
 *   LarkaRgpd.afficher({ section: 'ia' })     … ouverte sur une section
 *   LarkaRgpd.afficher({ politique: data })   aperçu (module Super Admin)
 */
(function () {
  'use strict';

  function langue() {
    try { return (JSON.parse(localStorage.getItem('gmao_ui_prefs')) || {}).langue === 'en' ? 'en' : 'fr'; }
    catch (_) { return 'fr'; }
  }

  const TEXTES = {
    fr: {
      titre: 'Protection des données personnelles', chargement: 'Chargement…', fermer: 'Fermer',
      erreur: 'Politique indisponible pour le moment.', maj: 'Mise à jour le', perso: 'Personnalisé par l\'entreprise',
      le: 'le',
    },
    en: {
      titre: 'Personal data protection', chargement: 'Loading…', fermer: 'Close',
      erreur: 'The policy is not available at the moment.', maj: 'Updated on', perso: 'Customised by the organisation',
      le: 'on',
    },
  };

  // Adresses web (http, https, www.) et électroniques, rendues cliquables.
  const LIEN = /(https?:\/\/[^\s<>()"]+[^\s<>()".,;:!?»]|www\.[a-z0-9-]+(?:\.[a-z0-9-]+)+(?:\/[^\s<>()"]*[^\s<>()".,;:!?»])?|[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,})/g;

  function ajouterTexte(parent, texte) {
    let i = 0;
    String(texte).replace(LIEN, (m, _g, pos) => {
      if (pos > i) parent.appendChild(document.createTextNode(texte.slice(i, pos)));
      const a = document.createElement('a');
      a.textContent = m;
      a.href = /^https?:\/\//i.test(m) ? m : (m.includes('@') ? 'mailto:' + m : 'https://' + m);
      if (!a.href.startsWith('mailto:')) { a.target = '_blank'; a.rel = 'noopener noreferrer'; }
      parent.appendChild(a);
      i = pos + m.length;
      return m;
    });
    if (i < texte.length) parent.appendChild(document.createTextNode(texte.slice(i)));
  }

  /** Texte → paragraphes et listes (« - » ou « • » en début de ligne). */
  function rendreTexte(texte) {
    const frag = document.createDocumentFragment();
    String(texte || '').split(/\n{2,}/).forEach(bloc => {
      let ul = null, p = null;
      bloc.split('\n').forEach(ligne => {
        const puce = /^\s*[-•]\s+/.exec(ligne);
        if (puce) {
          p = null;
          if (!ul) { ul = document.createElement('ul'); frag.appendChild(ul); }
          const li = document.createElement('li');
          ajouterTexte(li, ligne.slice(puce[0].length));
          ul.appendChild(li);
        } else if (ligne.trim() !== '') {
          ul = null;
          if (!p) { p = document.createElement('p'); frag.appendChild(p); }
          else p.appendChild(document.createElement('br'));
          ajouterTexte(p, ligne);
        }
      });
    });
    return frag;
  }

  function dateFr(d, en) {
    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(d || ''));
    if (!m) return '';
    return en ? `${m[1]}-${m[2]}-${m[3]}` : `${m[3]}/${m[2]}/${m[1]}`;
  }

  function el(tag, cls, texte) {
    const e = document.createElement(tag);
    if (cls) e.className = cls;
    if (texte != null) e.textContent = texte;
    return e;
  }

  /** Rend la politique dans un conteneur (modale, ou aperçu du Super Admin). */
  function rendre(conteneur, d, opts) {
    const en = (opts && opts.langue ? opts.langue : langue()) === 'en';
    const T = TEXTES[en ? 'en' : 'fr'];
    conteneur.replaceChildren();
    conteneur.classList.add('rgpd-corps');

    const tete = el('div', 'rgpd-tete');
    tete.appendChild(el('strong', null, d.organisation || ''));
    if (d.mise_a_jour) tete.appendChild(el('span', null, ` · ${T.maj} ${dateFr(d.mise_a_jour, en)}`));
    conteneur.appendChild(tete);

    const mention = el('div', 'rgpd-mention' + (d.personnalisee ? ' rgpd-mention-perso' : ''));
    mention.setAttribute('role', 'note');
    mention.textContent = (d.personnalisee ? '✎ ' : '') + (d.mention || '')
      + (d.personnalisee && d.personnalisee_le ? ` (${T.le} ${dateFr(d.personnalisee_le, en)})` : '');
    conteneur.appendChild(mention);

    (d.sections || []).forEach(s => {
      const sec = el('section', 'rgpd-section' + (s.personnalisee ? ' rgpd-section-perso' : ''));
      sec.id = 'rgpd-' + String(s.id || '').replace(/[^a-z0-9_-]/gi, '');
      const h = el('h3', null, s.titre || '');
      if (s.personnalisee) {
        const b = el('span', 'rgpd-badge', T.perso);
        h.appendChild(document.createTextNode(' '));
        h.appendChild(b);
      }
      sec.appendChild(h);
      sec.appendChild(rendreTexte(s.texte));
      conteneur.appendChild(sec);
    });
  }

  async function afficher(opts) {
    opts = opts || {};
    const en = langue() === 'en';
    const T = TEXTES[en ? 'en' : 'fr'];
    if (typeof openModal !== 'function') return;
    openModal('🛡️ ' + T.titre, '<div id="rgpdCorps" class="rgpd-corps" aria-live="polite"></div>', null, null, T.fermer, '760px');
    const corps = document.getElementById('rgpdCorps');
    if (!corps) return;
    let d = opts.politique || null;
    if (!d) {
      corps.textContent = T.chargement;
      try {
        const r = await fetch('api/index.php?action=rgpd&langue=' + (en ? 'en' : 'fr'), { credentials: 'include' });
        const j = await r.json();
        if (!j || !j.success) throw new Error((j && j.error) || '');
        d = j.data;
      } catch (e) {
        corps.textContent = T.erreur;
        return;
      }
    }
    if (d.titre) document.getElementById('modalTitle').textContent = '🛡️ ' + d.titre;
    rendre(corps, d, opts);
    if (opts.section) {
      const cible = document.getElementById('rgpd-' + opts.section);
      if (cible) setTimeout(() => cible.scrollIntoView({ block: 'start', behavior: 'smooth' }), 60);
    }
  }

  window.LarkaRgpd = { afficher, rendre, rendreTexte };

  // Lien de l'écran de connexion.
  document.addEventListener('DOMContentLoaded', () => {
    const lien = document.getElementById('loginRgpd');
    if (lien) lien.addEventListener('click', e => { e.preventDefault(); afficher(); });
  });
})();

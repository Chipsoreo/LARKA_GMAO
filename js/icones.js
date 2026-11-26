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
 * Larka — icônes Lucide.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 *   lk('wrench')            → <i class="lk lk-wrench" aria-hidden="true"></i>
 *   lk('circle-check','ok') → la même, en couleur de statut (ok, ko, warn)
 *   lk('circle','p vert')   → pastille pleine, verte
 *
 * Le dessin est dans css/lucide.css (un masque CSS par icône, généré par
 * outils/lucide/generer-icones.php) : une icône suit la taille et la couleur du
 * texte qui l'entoure. Une icône est une balise : elle va dans du HTML, jamais
 * dans un texte (textContent, title, placeholder, notification, <option>).
 *
 * Chargé AVANT les autres scripts (sans « defer ») : les tables d'icônes des
 * pages appellent lk() dès leur chargement.
 *
 * Ce fichier gère aussi le style choisi dans Interface → Icônes (attribut
 * data-icones de <html>) : « simple » (couleur du texte), « couleur » (une
 * couleur par famille : énergie, eau, bâtiments…), « duotone » (la couleur, et
 * l'intérieur de l'icône teinté). Sur un fond vif (bouton, onglet actif,
 * notification), une icône colorée reprend la couleur du texte pour rester
 * lisible : c'est le seul travail fait sur la page affichée.
 */
(function () {
  'use strict';
  const NOM = /^[a-z0-9-]+$/;
  const VARIANTE = /^[a-z ]*$/;

  function lk(nom, variante) {
    if (!NOM.test(String(nom || ''))) return '';
    const v = variante && VARIANTE.test(variante) ? variante.trim().split(/\s+/).map(x => ' lk-' + x).join('') : '';
    return `<i class="lk lk-${nom}${v}" aria-hidden="true"></i>`;
  }
  window.lk = lk;

  /**
   * Icône ENREGISTRÉE dans une donnée (catégorie d'urgence…) : un nom Lucide, ou,
   * pour une donnée saisie avant le passage à Lucide, l'emoji d'origine — rendu
   * par son icône s'il est connu, sinon tel quel (échappé).
   */
  const ANCIENS = { '🔥': 'flame', '🚑': 'ambulance', '🚔': 'shield-exclamation-point', '💧': 'droplet', '⚡': 'zap',
    '🟡': 'wind', '❓': 'circle-question-mark', '📌': 'pin', '🚨': 'siren', '☎': 'phone', '📞': 'phone', '🔒': 'lock',
    '🔑': 'key-round', '📍': 'map-pin', '🧩': 'puzzle',
    '🔐': 'lock-keyhole', '🛗': 'arrow-up-down', '🔧': 'wrench', '🏢': 'building', '❄': 'snowflake', '🌡': 'thermometer' };
  function lkValeur(v, defaut) {
    const t = String(v || '').trim();
    if (NOM.test(t)) return lk(t);
    // Balise d'icône déjà rendue (enregistrée telle quelle) : on la redessine.
    const balise = /^<i class="lk lk-([a-z0-9-]+)((?: lk-[a-z]+)*)"[^>]*><\/i>$/.exec(t);
    if (balise) return lk(balise[1], balise[2].replace(/lk-/g, '').trim());
    const ancien = ANCIENS[t.replace(/\uFE0F/g, '')];
    if (ancien) return lk(ancien);
    if (!t) return defaut ? lk(defaut) : '';
    const d = document.createElement('span');
    d.textContent = t;
    return d.innerHTML;
  }
  window.lkValeur = lkValeur;

  // ── Style des icônes ────────────────────────────────────────────────────
  const STYLES = ['simple', 'couleur', 'duotone'];
  const STYLE_DEFAUT = 'couleur';
  function styleChoisi() {
    try {
      const v = (JSON.parse(localStorage.getItem('gmao_ui_prefs')) || {}).icones;
      return STYLES.includes(v) ? v : STYLE_DEFAUT;
    } catch (e) { return STYLE_DEFAUT; }
  }

  // ── Fonds vifs ──────────────────────────────────────────────────────────
  // Premier ancêtre au fond opaque : saturé, ni très clair ni très sombre
  // (bleu d'un bouton, vert d'une notification) → l'icône prend la couleur du
  // texte (data-n). La barre de navigation, bleu nuit, garde ses couleurs.
  function fondVif(el) {
    for (let e = el.parentElement; e && e !== document.documentElement; e = e.parentElement) {
      const m = /rgba?\(([\d.]+),\s*([\d.]+),\s*([\d.]+)(?:,\s*([\d.]+))?/.exec(getComputedStyle(e).backgroundColor);
      if (!m || (m[4] !== undefined && +m[4] < 0.5)) continue;
      const [r, g, b] = [m[1] / 255, m[2] / 255, m[3] / 255];
      const max = Math.max(r, g, b), min = Math.min(r, g, b), l = (max + min) / 2;
      const sat = max === min ? 0 : (max - min) / (1 - Math.abs(2 * l - 1));
      return sat > 0.35 && l > 0.25 && l < 0.75;
    }
    return false;
  }
  function ajusterFonds(racine) {
    const st = document.documentElement.getAttribute('data-icones');
    if (st !== 'couleur' && st !== 'duotone') return;
    const r = racine && racine.nodeType === 1 ? racine : document.body;
    if (!r) return;
    const liste = r.classList && r.classList.contains('lk') ? [r] : r.querySelectorAll('.lk');
    liste.forEach((i) => {
      const vif = fondVif(i);
      // Attribut hors du filtre de l'observateur : poser data-n ne relance rien.
      if (vif && !i.hasAttribute('data-n')) i.setAttribute('data-n', '');
      else if (!vif && i.hasAttribute('data-n')) i.removeAttribute('data-n');
    });
  }

  let observateur = null;
  function suivre() {
    if (observateur || !document.body) return;
    ajusterFonds(document.body);
    observateur = new MutationObserver((mutations) => {
      const aRevoir = new Set();
      for (const m of mutations) {
        if (m.type === 'attributes') aRevoir.add(m.target);
        else m.addedNodes.forEach((n) => { if (n.nodeType === 1) aRevoir.add(n); });
      }
      aRevoir.forEach((e) => { if (e.isConnected) ajusterFonds(e); });
    });
    observateur.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['class', 'style'] });
  }

  function appliquerStyle(nom) {
    document.documentElement.setAttribute('data-icones', STYLES.includes(nom) ? nom : styleChoisi());
    if (document.body) ajusterFonds(document.body);
  }

  // ── Icônes choisies dans la bibliothèque complète ───────────────────────
  // Leur dessin n'est pas dans css/lucide.css : le serveur le sert (action
  // icones_choisies), une fois, au chargement de l'application. Voir
  // api/IconesLucide.php.
  function ajouterRegles(css) {
    if (!css) return;
    let st = document.getElementById('lkChoisies');
    if (!st) { st = document.createElement('style'); st.id = 'lkChoisies'; document.head.appendChild(st); }
    st.textContent += '\n' + css;
  }
  async function chargerChoisies() {
    try {
      const r = await fetch('api/index.php?action=icones_choisies', { credentials: 'include', headers: { Accept: 'application/json' } });
      const j = await r.json();
      const st = document.getElementById('lkChoisies');
      if (st) st.textContent = '';
      if (j && j.success !== false && j.data) ajouterRegles(j.data.css);
    } catch (_) { /* icônes de base seulement */ }
  }

  window.LarkaIcones = { lk, lkValeur, styles: STYLES, styleChoisi, appliquerStyle, ajouterRegles, chargerChoisies };
  appliquerStyle();
  if (document.body) suivre(); else document.addEventListener('DOMContentLoaded', suivre);
})();

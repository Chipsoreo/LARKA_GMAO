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
 * Larka — sélecteur d'icône : une icône Lucide OU un emoji.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 *   IconePicker.ouvrir({ valeur, titre, surChoix: v => … })
 *
 * La valeur rendue est un nom Lucide (« flame ») ou un emoji (« 🔥 ») :
 * lkValeur() (js/icones.js) sait afficher l'un et l'autre, et les données
 * déjà saisies avec un emoji continuent de s'afficher.
 *
 * Pourquoi les deux :
 *   - les icônes Lucide ont le trait fin de toute l'interface, suivent la
 *     couleur et le style choisi (Interface → Icônes), et s'impriment net ;
 *   - un emoji reste parfois le signe que tout le monde reconnaît d'un coup
 *     d'œil (🧯, 🚻, ♿), et c'est ce que les utilisateurs avaient l'habitude
 *     de poser. On ne le leur retire pas.
 *
 * En tête, une sélection classée avec des libellés français (js/pictos.js) ;
 * dessous, TOUTE la bibliothèque Lucide (≈ 2 000 icônes, js/lucide-complet.json,
 * chargée à la première ouverture), cherchée par les noms et mots-clés anglais
 * de Lucide. Une icône prise dans la bibliothèque est enregistrée par
 * l'organisation (api/IconesLucide.php) ; son dessin est ensuite servi à tous.
 *
 * La sélection est celle du catalogue js/pictos.js (libellés
 * français, pour qu'on trouve « extincteur » en tapant « extincteur »), dont le
 * dessin est dans css/lucide-pictos.css. Le catalogue et la feuille sont
 * générés ensemble (outils/lucide/generer-icones.php) : aucun nom proposé ne
 * peut manquer de dessin.
 *
 * Fenêtre à part, au-dessus des fenêtres modales : le sélecteur s'ouvre souvent
 * DEPUIS une fiche (élément de plan, catégorie d'urgence) qu'il ne doit pas
 * fermer.
 */
(function () {
  'use strict';

  const NOM = /^[a-z0-9-]+$/;
  // Un emoji : pictogrammes, drapeaux, touches « 1️⃣ », modificateurs de teinte
  // et liaisons (ZWJ). Rien d'autre — ni lettre, ni balise : la valeur est
  // ensuite affichée, il ne faut pas qu'elle puisse porter du texte arbitraire.
  const EMOJI = /^(?:\p{Extended_Pictographic}|\p{Regional_Indicator}|\p{Emoji_Modifier}|[#*0-9]️?⃣|‍|️)+$/u;
  const EMOJIS_COURANTS = ['🔥', '🧯', '🚒', '🚑', '🚨', '⚠️', '⛔', '🚫', '☢️', '☣️', '⚡', '🔌', '💡', '💧', '🚿',
    '🚽', '🚻', '♿', '🚪', '🛗', '🪜', '🧰', '🔧', '🔩', '🛠️', '🧹', '🗑️', '♻️', '🌡️', '❄️', '🔥', '💨', '🌀',
    '🏢', '🏫', '🏠', '🅿️', '🚗', '🚲', '🚌', '☕', '🍽️', '🛏️', '💻', '🖨️', '📞', '☎️', '📧', '📍', '📌', '🔑',
    '🔒', '🔓', '👤', '👥', '🩺', '💊', '🩹', '❤️', '⭐', '✅', '❌', '❓', 'ℹ️'];

  let _etat = null;

  function _sansAccent(s) {
    return String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
  }
  function _esc(s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  function catalogue() { return Array.isArray(window.LARKA_PICTOS) ? window.LARKA_PICTOS : []; }

  /** Vrai pour un emoji seul (au plus quelques caractères liés). */
  function estEmoji(v) {
    const t = String(v || '').trim();
    return t !== '' && t.length <= 16 && EMOJI.test(t);
  }
  /** Vrai pour un nom d'icône Lucide. */
  function estIcone(v) { return NOM.test(String(v || '')); }

  /** Libellé d'une valeur : celui du catalogue, l'emoji lui-même, ou le nom. */
  function decrire(v) {
    const t = String(v || '').trim();
    if (!t) return '';
    for (const c of catalogue()) for (const [n, l] of c.icones) if (n === t) return l;
    return t;
  }

  // ── Bibliothèque complète (≈ 2 000 icônes) ─────────────────────────────
  // Chargée à la première ouverture du sélecteur seulement (≈ 160 Ko
  // compressés, puis en cache du navigateur). Une icône choisie dedans est
  // enregistrée par l'organisation (action icones_choisies) : son dessin est
  // ensuite servi à tous au chargement, sans recharger la bibliothèque.
  let _biblio = null, _biblioPromesse = null;
  const PAR_PAGE = 240;
  function _version() {
    const sc = document.querySelector('script[src*="icone-picker.js"]');
    const m = sc && /[?&]v=([0-9]+)/.exec(sc.getAttribute('src'));
    return m ? m[1] : '';
  }
  function chargerBibliotheque() {
    if (_biblio) return Promise.resolve(_biblio);
    if (!_biblioPromesse) {
      _biblioPromesse = fetch('js/lucide-complet.json?v=' + _version(), { credentials: 'same-origin' })
        .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(j => { _biblio = j; if (_etat) _rendre(); return j; })
        .catch(e => { _biblioPromesse = null; _biblio = { erreur: e.message, icones: {} }; if (_etat) _rendre(); return _biblio; });
    }
    return _biblioPromesse;
  }
  function _embarquee(n) { return Array.isArray(window.LARKA_ICONES_EMBARQUEES) && window.LARKA_ICONES_EMBARQUEES.includes(n); }
  /** Dessin d'une icône de la bibliothèque, en SVG direct (le fichier est celui de Larka, pas une saisie). */
  function _svg(corps) {
    return '<svg class="ip-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + corps + '</svg>';
  }
  function _resultatsBiblio(q, dejaVus) {
    if (!_biblio || !_biblio.icones) return [];
    const out = [];
    for (const [n, [tags, corps]] of Object.entries(_biblio.icones)) {
      if (dejaVus.has(n)) continue;
      if (q && !(n.includes(q) || tags.some(t => _sansAccent(t).includes(q)))) continue;
      out.push([n, corps]);
    }
    return out;
  }

  function ouvrir(opts) {
    fermer();
    _etat = {
      valeur: String((opts && opts.valeur) || ''),
      titre: (opts && opts.titre) || 'Choisir une icône',
      surChoix: (opts && opts.surChoix) || function () {},
      emoji: !(opts && opts.emoji === false),
      onglet: estEmoji(opts && opts.valeur) ? 'emoji' : 'icones',
      recherche: '',
      voirTout: 0,          // bibliothèque complète : nombre d'icônes affichées sans recherche
    };
    const fond = document.createElement('div');
    fond.id = 'iconePicker';
    fond.className = 'ip-fond';
    fond.addEventListener('mousedown', e => { if (e.target === fond) fermer(); });
    fond.innerHTML = '<div class="ip-boite" role="dialog" aria-modal="true" aria-labelledby="ipTitre"></div>';
    document.body.appendChild(fond);
    document.addEventListener('keydown', _clavier, true);
    _rendre();
    setTimeout(() => { const r = document.getElementById('ipRecherche'); if (r) r.focus(); }, 30);
  }

  function fermer() {
    const f = document.getElementById('iconePicker');
    if (f) f.remove();
    document.removeEventListener('keydown', _clavier, true);
    _etat = null;
  }

  function _clavier(e) {
    if (!_etat) return;
    if (e.key === 'Escape') { e.stopPropagation(); e.preventDefault(); fermer(); }
  }

  async function _choisir(v) {
    const st = _etat;
    if (!st) return;
    // Icône de la bibliothèque complète, pas encore dessinée par Larka :
    // l'organisation l'enregistre, et reçoit son dessin.
    if (estIcone(v) && !_embarquee(v) && !(document.getElementById('lkChoisies') || {}).textContent?.includes('.lk-' + v + '{')) {
      try {
        const r = await apiRequest('icones_choisies', 'POST', { nom: v });
        if (window.LarkaIcones && LarkaIcones.ajouterRegles) LarkaIcones.ajouterRegles(r && r.css);
      } catch (e) {
        if (typeof toast === 'function') toast('Icône non enregistrée : ' + e.message, 'error');
        return;
      }
    }
    fermer();
    try { st.surChoix(v); } catch (err) { console.error(err); }
  }

  function _resultats() {
    const q = _sansAccent(_etat.recherche).trim();
    // D'abord les icônes dont le libellé (ou le nom Lucide) contient la
    // recherche ; à défaut seulement, toute une catégorie (« secours »).
    const filtrer = test => {
      const out = [];
      for (const c of catalogue()) {
        const ic = c.icones.filter(([n, l]) => test(n, l, c));
        if (ic.length) out.push({ categorie: c.categorie, icones: ic });
      }
      return out;
    };
    if (!q) return filtrer(() => true);
    const parNom = filtrer((n, l) => _sansAccent(l).includes(q) || n.includes(q));
    return parNom.length ? parNom : filtrer((n, l, c) => _sansAccent(c.categorie).includes(q));
  }

  function _rendre() {
    const boite = document.querySelector('#iconePicker .ip-boite');
    if (!boite || !_etat) return;
    const st = _etat;
    let h = '<div class="ip-tete"><span id="ipTitre">' + _esc(st.titre) + '</span>'
      + '<button type="button" class="ip-fermer" onclick="IconePicker.fermer()" aria-label="Fermer">' + lk('x') + '</button></div>';
    if (st.emoji) {
      h += '<div class="ip-onglets" role="tablist">'
        + '<button type="button" role="tab" class="' + (st.onglet === 'icones' ? 'actif' : '') + '" onclick="IconePicker._onglet(\'icones\')">' + lk('shapes') + ' Icônes</button>'
        + '<button type="button" role="tab" class="' + (st.onglet === 'emoji' ? 'actif' : '') + '" onclick="IconePicker._onglet(\'emoji\')">' + lk('face-slightly-smiling') + ' Emoji</button></div>';
    }
    if (st.onglet === 'icones') {
      h += '<input type="search" id="ipRecherche" class="form-control ip-recherche" placeholder="Rechercher : extincteur, escalier, eau…" value="' + _esc(st.recherche) + '"'
        + ' oninput="IconePicker._chercher(this.value)" onkeydown="if(event.key===\'Enter\'){event.preventDefault();IconePicker._premier()}" autocomplete="off">';
      const res = _resultats();
      h += '<div class="ip-grille-zone">';
      if (!res.length && st.recherche.trim() && _biblio && !_resultatsBiblio(_sansAccent(st.recherche).trim(), new Set()).length) {
        h += '<div class="ip-vide">Aucune icône pour « ' + _esc(st.recherche) + ' ».' + (st.emoji ? ' Un emoji conviendrait peut-être (onglet Emoji).' : '') + '</div>';
      }
      for (const c of res) {
        h += '<div class="ip-categorie">' + _esc(c.categorie) + '</div><div class="ip-grille">';
        for (const [n, l] of c.icones) {
          h += '<button type="button" class="ip-case' + (n === st.valeur ? ' choisie' : '') + '" title="' + _esc(l) + '" onclick="IconePicker._choisir(' + _esc(JSON.stringify(n)) + ')">'
            + lk(n) + '<span>' + _esc(l) + '</span></button>';
        }
        h += '</div>';
      }
      h += _biblioHtml(res);
      h += '</div>';
    } else {
      const v = estEmoji(st.valeur) ? st.valeur : '';
      h += '<div class="ip-emoji-saisie"><input id="ipEmoji" class="form-control" maxlength="16" value="' + _esc(v) + '" placeholder="Collez ou tapez un emoji"'
        + ' oninput="IconePicker._verifierEmoji(this)" onkeydown="if(event.key===\'Enter\'){event.preventDefault();IconePicker._validerEmoji()}">'
        + '<button type="button" class="btn btn-primary btn-sm" onclick="IconePicker._validerEmoji()">Utiliser</button></div>'
        + '<div id="ipEmojiInfo" class="ip-aide">Un seul emoji (Windows : touche ⊞ + point ; Mac : ⌃ ⌘ Espace). Il s\'affiche avec les couleurs de l\'appareil qui le montre.</div>'
        + '<div class="ip-categorie">Les plus utilisés</div><div class="ip-grille ip-grille-emoji">'
        + [...new Set(EMOJIS_COURANTS)].map(e => '<button type="button" class="ip-case ip-case-emoji' + (e === v ? ' choisie' : '') + '" onclick="IconePicker._choisir(' + _esc(JSON.stringify(e)) + ')">' + _esc(e) + '</button>').join('')
        + '</div>';
    }
    boite.innerHTML = h;
  }

  /** Section « Toute la bibliothèque Lucide » : résultats de la recherche (mots anglais de Lucide), ou parcours page à page. */
  function _biblioHtml(resCatalogue) {
    const st = _etat;
    if (!_biblio) { chargerBibliotheque(); return '<div class="ip-categorie">Toute la bibliothèque Lucide</div><div class="ip-aide" style="padding:0">Chargement…</div>'; }
    if (_biblio.erreur) return '<div class="ip-categorie">Toute la bibliothèque Lucide</div><div class="ip-aide ko" style="padding:0">Bibliothèque indisponible (' + _esc(_biblio.erreur) + ').</div>';
    const q = _sansAccent(st.recherche).trim();
    const vus = new Set(); resCatalogue.forEach(c => c.icones.forEach(([n]) => vus.add(n)));
    const total = Object.keys(_biblio.icones).length;
    const res = _resultatsBiblio(q, vus);
    let h = '<div class="ip-categorie">Toute la bibliothèque Lucide — ' + (q ? res.length + ' résultat' + (res.length > 1 ? 's' : '') : total.toLocaleString('fr-FR') + ' icônes') + '</div>';
    if (q && !res.length) return h + '<div class="ip-aide" style="padding:0">Rien de plus. Les noms de Lucide sont en anglais : essayez « fire », « door », « water »…</div>';
    if (!q && !st.voirTout) {
      return h + '<div class="ip-aide" style="padding:0">Cherchez en anglais (« fire », « stairs », « water ») — ou <a href="#" onclick="event.preventDefault();IconePicker._voirPlus()">parcourir toutes les icônes</a>.</div>';
    }
    const n = q ? Math.max(PAR_PAGE, st.voirTout) : st.voirTout;
    h += '<div class="ip-grille ip-grille-biblio">' + res.slice(0, n).map(([nom, corps]) =>
      '<button type="button" class="ip-case' + (nom === st.valeur ? ' choisie' : '') + '" title="' + _esc(nom) + '" onclick="IconePicker._choisir(' + _esc(JSON.stringify(nom)) + ')">'
      + _svg(corps) + '<span>' + _esc(nom) + '</span></button>').join('') + '</div>';
    if (res.length > n) h += '<div style="text-align:center;margin-top:8px"><button type="button" class="btn btn-ghost btn-sm" onclick="IconePicker._voirPlus()">Afficher ' + Math.min(PAR_PAGE, res.length - n) + ' de plus (' + (res.length - n) + ' restantes)</button></div>';
    return h;
  }
  function _voirPlus() {
    if (!_etat) return;
    const zone = document.querySelector('#iconePicker .ip-grille-zone');
    const haut = zone ? zone.scrollTop : 0;
    _etat.voirTout = (_etat.voirTout || (_etat.recherche.trim() ? PAR_PAGE : 0)) + PAR_PAGE;
    _rendre();
    const z = document.querySelector('#iconePicker .ip-grille-zone'); if (z) z.scrollTop = haut;
  }

  function _onglet(o) {
    if (!_etat) return;
    _etat.onglet = o;
    _rendre();
    setTimeout(() => { const r = document.getElementById(o === 'emoji' ? 'ipEmoji' : 'ipRecherche'); if (r) r.focus(); }, 20);
  }
  function _chercher(v) {
    if (!_etat) return;
    _etat.recherche = v;
    _etat.voirTout = 0;
    const pos = (document.getElementById('ipRecherche') || {}).selectionStart;
    _rendre();
    const r = document.getElementById('ipRecherche');
    if (r) { r.focus(); try { r.setSelectionRange(pos, pos); } catch (_) { /* champ sans curseur */ } }
  }
  function _premier() {
    const res = _resultats();
    if (res.length) { _choisir(res[0].icones[0][0]); return; }
    const b = _resultatsBiblio(_sansAccent(_etat.recherche).trim(), new Set());
    if (b.length) _choisir(b[0][0]);
  }
  function _verifierEmoji(el) {
    const info = document.getElementById('ipEmojiInfo');
    const t = el.value.trim();
    if (info) info.textContent = !t || estEmoji(t) ? 'Un seul emoji. Il s\'affiche avec les couleurs de l\'appareil qui le montre.'
      : 'Ce n\'est pas un emoji : pour un texte, utilisez plutôt le nom de l\'élément.';
    if (info) info.classList.toggle('ko', !!t && !estEmoji(t));
  }
  function _validerEmoji() {
    const el = document.getElementById('ipEmoji');
    const t = (el && el.value || '').trim();
    if (!estEmoji(t)) { if (el) _verifierEmoji(el); return; }
    _choisir(t);
  }

  window.IconePicker = { ouvrir, fermer, estEmoji, estIcone, decrire, catalogue,
    _choisir, _onglet, _chercher, _premier, _verifierEmoji, _validerEmoji, _voirPlus, chargerBibliotheque };
})();

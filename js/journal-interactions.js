/**
 * ═══════════════════════════════════════════════════════════════════════════
 *  Traçage détaillé des interactions
 * ═══════════════════════════════════════════════════════════════════════════
 *
 *  Enregistre ce que fait réellement l'utilisateur : sur quoi il clique, ce
 *  qu'il saisit dans les champs, ce qu'il soumet, quand sa session commence et
 *  se termine. Complète journal-client.js (erreurs) et le suivi de navigation.
 *
 *  ── CE QUI N'EST JAMAIS CAPTURÉ ──────────────────────────────────────────
 *
 *  Règle absolue, appliquée AVANT toute lecture de valeur :
 *    • input[type=password]                    → jamais, ni la valeur ni sa longueur
 *    • champs dont le nom/id évoque un secret  (token, secret, cle, api…)
 *    • tout élément portant data-prive="1"     → échappatoire manuelle
 *    • les champs de recherche sont capturés (utile) mais tronqués à 120 car.
 *
 *  ── POURQUOI PAS DU KEYLOGGING ────────────────────────────────────────────
 *
 *  On journalise la valeur d'un champ à sa VALIDATION (change/blur), pas à
 *  chaque frappe. Deux raisons : un keylogger produit un volume ingérable
 *  (30 lignes pour « intervention »), et il capture les corrections, les
 *  fautes de frappe et les copier-coller partiels qui n'ont aucune valeur
 *  d'audit. Ce qui compte, c'est ce que l'utilisateur a validé.
 *
 *  ── PERFORMANCE ──────────────────────────────────────────────────────────
 *
 *  Les évènements sont mis en file et envoyés par lots (toutes les 5 s, ou
 *  dès 25 évènements, ou à la fermeture de l'onglet via sendBeacon). Un clic
 *  ne déclenche jamais une requête réseau : l'interface ne doit pas ralentir
 *  parce qu'on l'observe.
 *
 *  Désactivable côté serveur : clé de configuration `journal_interactions`.
 */

(function () {
  'use strict';

  // ── Réglages ──────────────────────────────────────────────────────────────
  const INTERVALLE_MS   = 5000;   // fréquence d'envoi des lots
  const LOT_MAX         = 25;     // envoi anticipé au-delà de ce nombre
  const FILE_MAX        = 300;    // garde-fou mémoire
  const VALEUR_MAX      = 120;    // troncature des valeurs saisies
  const INACTIVITE_MS   = 15 * 60 * 1000;

  // Motifs de champs dont la valeur ne doit jamais quitter le navigateur.
  const MOTIFS_SENSIBLES = /(pass|pwd|mdp|mot.?de.?passe|secret|token|jeton|api.?key|cle.?api|csrf|carte|cvv|iban|rib|nir|securite.?sociale)/i;

  let file        = [];
  let actif       = null;   // null = pas encore déterminé côté serveur
  let dernierActe = Date.now();
  let timer       = null;

  // ── Utilitaires ───────────────────────────────────────────────────────────

  function estSensible(el) {
    if (!el) return true;
    if (el.type === 'password') return true;
    if (el.getAttribute && el.getAttribute('data-prive') === '1') return true;
    if (el.closest && el.closest('[data-prive="1"]')) return true;
    const id = [el.id, el.name, el.getAttribute && el.getAttribute('placeholder'), el.className]
      .filter(Boolean).join(' ');
    return MOTIFS_SENSIBLES.test(id);
  }

  function tronquer(v) {
    v = String(v == null ? '' : v);
    return v.length > VALEUR_MAX ? v.slice(0, VALEUR_MAX) + '…' : v;
  }

  /** Description lisible d'un élément : « bouton "Clôturer" », « champ #f_typeBudget ». */
  function decrire(el) {
    if (!el || !el.tagName) return '?';
    const tag = el.tagName.toLowerCase();

    // Libellé visible, tronqué : c'est ce qui parle à la relecture.
    let texte = (el.innerText || el.textContent || el.value || '').trim().replace(/\s+/g, ' ');
    if (texte.length > 45) texte = texte.slice(0, 45) + '…';

    const ident = el.id ? '#' + el.id
                : (el.name ? '[' + el.name + ']'
                : (el.getAttribute && el.getAttribute('data-page') ? '{' + el.getAttribute('data-page') + '}' : ''));

    if (tag === 'button' || (tag === 'a' && el.href) || (el.getAttribute && el.getAttribute('role') === 'button')) {
      return (tag === 'a' ? 'lien' : 'bouton') + (texte ? ' « ' + texte + ' »' : '') + (ident ? ' ' + ident : '');
    }
    if (tag === 'input' || tag === 'select' || tag === 'textarea') {
      return 'champ ' + (ident || tag);
    }
    if (el.classList && el.classList.contains('nav-item')) {
      return 'menu « ' + texte + ' »';
    }
    return tag + (ident ? ' ' + ident : '') + (texte ? ' « ' + texte + ' »' : '');
  }

  /** Chemin CSS court, pour retrouver l'élément exact si besoin. */
  function chemin(el) {
    const bouts = [];
    let n = el, prof = 0;
    while (n && n.tagName && prof < 4) {
      let b = n.tagName.toLowerCase();
      if (n.id) { bouts.unshift(b + '#' + n.id); break; }
      if (n.className && typeof n.className === 'string') {
        const c = n.className.split(/\s+/).filter(Boolean)[0];
        if (c) b += '.' + c;
      }
      bouts.unshift(b);
      n = n.parentElement; prof++;
    }
    return bouts.join('>').slice(0, 120);
  }

  function pageCourante() {
    try {
      if (typeof App !== 'undefined' && App.currentPage) return App.currentPage;
    } catch (_) {}
    return (location.hash || '').replace('#', '') || 'inconnue';
  }

  // ── File d'attente et envoi par lots ──────────────────────────────────────

  function empiler(type, detail) {
    if (actif === false) return;
    dernierActe = Date.now();
    if (file.length >= FILE_MAX) file.shift(); // on jette le plus ancien
    file.push({
      t: new Date().toISOString().slice(11, 23),
      type: type,
      page: pageCourante(),
      ...detail,
    });
    if (file.length >= LOT_MAX) envoyer();
  }

  function envoyer(viaBeacon) {
    if (!file.length) return;
    const lot = file;
    file = [];
    const corps = JSON.stringify({ evenements: lot });

    // À la fermeture de l'onglet, fetch est annulé : sendBeacon survit.
    if (viaBeacon && navigator.sendBeacon) {
      try {
        navigator.sendBeacon(
          'api/index.php?action=log_interactions',
          new Blob([corps], { type: 'application/json' })
        );
        return;
      } catch (_) { /* on retombe sur fetch */ }
    }

    fetch('api/index.php?action=log_interactions', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: corps,
      keepalive: true,
    })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        // Le serveur peut demander l'arrêt (fonction désactivée en config).
        if (d && d.data && d.data.actif === false) {
          actif = false;
          file = [];
          if (timer) { clearInterval(timer); timer = null; }
        } else if (d) {
          actif = true;
        }
      })
      .catch(function () { /* silencieux : le journal ne doit rien casser */ });
  }

  // ── Captures ──────────────────────────────────────────────────────────────

  // Clics : on remonte au plus proche élément « cliquable signifiant » pour
  // décrire « bouton Clôturer » plutôt que « span » ou « svg ».
  document.addEventListener('click', function (e) {
    try {
      const brut = e.target;
      if (!brut || !brut.closest) return;
      const el = brut.closest('button, a, [role="button"], .nav-item, .btn, input[type="checkbox"], input[type="radio"], select, tr[onclick], [onclick]') || brut;
      if (estSensible(el)) return;

      empiler('clic', {
        quoi:   decrire(el),
        chemin: chemin(el),
      });
    } catch (_) {}
  }, true);

  // Saisies : à la validation du champ, pas à la frappe.
  document.addEventListener('change', function (e) {
    try {
      const el = e.target;
      if (!el || !el.tagName) return;
      const tag = el.tagName.toLowerCase();
      if (!['input', 'select', 'textarea'].includes(tag)) return;

      // Filtrage AVANT toute lecture de la valeur.
      if (estSensible(el)) {
        empiler('saisie', { quoi: decrire(el), valeur: '(champ protégé, non enregistré)' });
        return;
      }

      let valeur;
      if (el.type === 'checkbox' || el.type === 'radio') valeur = el.checked ? 'coché' : 'décoché';
      else if (tag === 'select') {
        const o = el.options && el.options[el.selectedIndex];
        valeur = o ? o.text : el.value;
      } else valeur = el.value;

      empiler('saisie', {
        quoi:   decrire(el),
        valeur: tronquer(valeur),
      });
    } catch (_) {}
  }, true);

  // Recherche et champs sans évènement change (frappe continue) : on capture
  // la valeur stabilisée après 1,2 s sans frappe, une seule fois par pause.
  let tFrappe = null, dernierChamp = null;
  document.addEventListener('input', function (e) {
    try {
      const el = e.target;
      if (!el || !el.tagName) return;
      if (!['input', 'textarea'].includes(el.tagName.toLowerCase())) return;
      if (estSensible(el)) return;
      if (el.type === 'checkbox' || el.type === 'radio') return;

      dernierChamp = el;
      if (tFrappe) clearTimeout(tFrappe);
      tFrappe = setTimeout(function () {
        if (!dernierChamp) return;
        empiler('frappe', {
          quoi:   decrire(dernierChamp),
          valeur: tronquer(dernierChamp.value),
        });
        dernierChamp = null;
      }, 1200);
    } catch (_) {}
  }, true);

  // Soumissions de formulaire
  document.addEventListener('submit', function (e) {
    try { empiler('soumission', { quoi: decrire(e.target) }); } catch (_) {}
  }, true);

  // Onglet masqué / réaffiché : permet de reconstituer le temps réellement passé.
  document.addEventListener('visibilitychange', function () {
    try {
      empiler('onglet', { quoi: document.hidden ? 'masqué' : 'réaffiché' });
      if (document.hidden) envoyer(true);
    } catch (_) {}
  });

  // Fermeture / rechargement : vidage garanti de la file.
  window.addEventListener('pagehide', function () { try { envoyer(true); } catch (_) {} });
  window.addEventListener('beforeunload', function () { try { envoyer(true); } catch (_) {} });

  // ── Boucle d'envoi ────────────────────────────────────────────────────────
  timer = setInterval(function () {
    if (actif === false) return;
    // Inactivité prolongée : on le note, c'est utile pour délimiter les sessions.
    if (file.length === 0 && Date.now() - dernierActe > INACTIVITE_MS) {
      dernierActe = Date.now();
      empiler('inactivite', { quoi: 'aucune action depuis 15 min' });
    }
    envoyer();
  }, INTERVALLE_MS);

  /** Journalisation manuelle depuis n'importe quel module métier. */
  window.journalAction = function (libelle, detail) {
    empiler('action', { quoi: String(libelle || '').slice(0, 120), valeur: tronquer(detail || '') });
  };
})();

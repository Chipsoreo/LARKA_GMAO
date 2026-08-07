/**
 * ═══════════════════════════════════════════════════════════════════════════
 *  Capture des erreurs navigateur → journal serveur
 * ═══════════════════════════════════════════════════════════════════════════
 *
 *  Une erreur JavaScript ne laisse aucune trace côté serveur : elle s'affiche
 *  dans la console de l'utilisateur et disparaît. C'est le angle mort le plus
 *  courant d'une journalisation, et la raison pour laquelle un bug signalé par
 *  un utilisateur est souvent irreproductible.
 *
 *  Ce module intercepte :
 *    • les erreurs non rattrapées          (window.onerror)
 *    • les promesses rejetées sans catch   (unhandledrejection)
 *    • les ressources qui ne chargent pas  (error en phase de capture)
 *
 *  Garde-fous, parce qu'un journal qui s'auto-alimente est pire que pas de
 *  journal du tout :
 *    – anti-boucle : on n'envoie jamais une erreur provoquée par l'envoi ;
 *    – dédoublonnage : une même erreur répétée n'est envoyée qu'une fois ;
 *    – plafond de 25 remontées par session, pour qu'une erreur dans une
 *      boucle d'animation ne génère pas des milliers de requêtes.
 */

(function () {
  'use strict';

  const PLAFOND_SESSION = 25;
  let envoyees = 0;
  let enCours  = false;              // anti-récursion
  const dejaVues = new Set();        // signatures déjà remontées

  function signature(o) {
    return [o.message, o.source, o.ligne, o.colonne].join('|');
  }

  function remonter(niveau, infos) {
    try {
      if (enCours) return;
      if (envoyees >= PLAFOND_SESSION) return;

      const sig = signature(infos);
      if (dejaVues.has(sig)) return;
      dejaVues.add(sig);
      envoyees++;

      enCours = true;
      const payload = {
        niveau,
        message:    String(infos.message || 'Erreur JavaScript').slice(0, 500),
        source:     String(infos.source  || '').slice(0, 250),
        ligne:      infos.ligne   || 0,
        colonne:    infos.colonne || 0,
        pile:       String(infos.pile || '').slice(0, 2000),
        page:       location.hash || location.pathname,
        navigateur: navigator.userAgent.slice(0, 250),
      };

      // JournalApi peut ne pas être chargé si l'erreur survient très tôt.
      const envoi = (typeof JournalApi !== 'undefined' && JournalApi.client)
        ? JournalApi.client(payload)
        : fetch('api/index.php?action=log_client', {
            method: 'POST', credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
          }).catch(() => {});

      Promise.resolve(envoi).finally(() => { enCours = false; });
    } catch (_) {
      enCours = false;   // ne jamais rester bloqué
    }
  }

  // ── Erreurs non rattrapées ────────────────────────────────────────────────
  window.addEventListener('error', function (e) {
    // Échec de chargement d'une ressource (script, image, feuille de style) :
    // e.error est nul et la cible est un élément du DOM.
    if (e.target && e.target !== window && (e.target.src || e.target.href)) {
      remonter('warning', {
        message: 'Ressource non chargée : ' + (e.target.src || e.target.href),
        source:  e.target.tagName,
      });
      return;
    }
    remonter('error', {
      message: e.message,
      source:  e.filename,
      ligne:   e.lineno,
      colonne: e.colno,
      pile:    e.error && e.error.stack ? e.error.stack : '',
    });
  }, true); // capture : nécessaire pour intercepter les erreurs de ressources

  // ── Promesses rejetées sans catch ─────────────────────────────────────────
  window.addEventListener('unhandledrejection', function (e) {
    const r = e.reason;
    remonter('error', {
      message: 'Promesse rejetée : ' + (r && r.message ? r.message : String(r)),
      pile:    r && r.stack ? r.stack : '',
    });
  });

  /**
   * Remontée manuelle, utilisable depuis n'importe quel module :
   *     journalErreur('Échec du calcul de TTC', e);
   */
  window.journalErreur = function (message, erreur) {
    remonter('error', {
      message: message,
      pile:    erreur && erreur.stack ? erreur.stack : '',
      source:  erreur && erreur.fileName ? erreur.fileName : '',
    });
  };
})();

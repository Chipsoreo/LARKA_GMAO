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
 * Larka — Lazy-loading des pages JS
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Charge `js/pages/*.js` à la demande lors d'un navigate(), au lieu de tout
 * charger au boot. Bénéfices :
 *   - Démarrage plus rapide (Demandeur : ~3 fichiers vs ~25 avant)
 *   - Moins de bande passante au premier load
 *   - Pages rarement utilisées (superadmin, legifrance, chorus…) ne sont jamais
 *     téléchargées par les utilisateurs qui n'y vont pas.
 *
 * STRATÉGIE :
 *   1. window.PageLoader.ensure(pageKey) → garantit que le(s) script(s) de la
 *      page sont chargés. Retourne une Promise.
 *   2. Chargement IDEMPOTENT : un script exécuté une fois n'est jamais
 *      ré-injecté pendant la vie du document. Les fichiers de page tournent
 *      dans la portée globale et déclarent des const/let au premier niveau ;
 *      les ré-exécuter lèverait « Identifier already declared » et casserait
 *      la page. La fraîcheur des assets est assurée par le ?v= versionné +
 *      le cache HTTP du navigateur ; un F5 ré-télécharge tout proprement.
 *   3. Préchargement intelligent en arrière-plan après initApp : pour le rôle
 *      Demandeur on précharge demandes/historique/inventaire ; pour les autres
 *      rôles on précharge les pages les plus utilisées.
 *
 * IMPORTANT : ce module remplace les <script src="js/pages/*.js"> qui
 * existaient dans index.html. Ces tags ont été retirés ; le seul chargement
 * des pages passe désormais par ce loader.
 *
 * Dépendances : aucune (utilise document.createElement('script') natif).
 * ═══════════════════════════════════════════════════════════════════════════════
 */

(function() {
  'use strict';

  // ── Mapping pageKey (cf. PAGES dans nav.js) → liste de scripts à charger ──
  // L'ordre est respecté (chargement séquentiel, car certains dépendent d'autres).
  //
  // ⚠️ DÉPENDANCES CROSS-PAGES : certaines pages utilisent des fonctions définies
  // dans d'autres pages (ex: archives.js appelle traiterDemandeArchive qui est
  // dans demandes.js). Pour éviter "X is not defined" en lazy-loading, on liste
  // ici toutes les pages requises. Les fonctions utilitaires partagées par
  // beaucoup de pages (getRequiredFields, fmt*) ont été extraites dans des
  // fichiers du cœur (js/required-fields.js, js/ui.js).
  const PAGE_SCRIPTS = {
    extensions:        ['js/pages/extensions.js'],
    dashboard:         ['js/pages/demandes.js', 'js/pages/interventions.js', 'js/pages/dashboard.js'],
    biens:             ['js/pages/biens-import.js', 'js/pages/biens.js'],
    equipements:       ['js/pages/biens-import.js', 'js/pages/equipements.js'],
    stock:             ['js/pages/stats.js', 'js/pages/stock.js'],
    interventions:     ['js/pages/interventions.js'],
    contrats:          ['js/pages/contrats.js'],
    // ⚠️ DÉPENDANCE CROSS-PAGES : la section « Bilan Mobilité des agents » de
    // energie.render.js s'appuie sur mobilite.js (_getTransport, _getFreq,
    // _co2Annuel, MobiliteConfig, METHODES, loadFacteursCarbone…). Sans ce
    // fichier, la section tombe dans son try/catch et disparaît sans un mot —
    // elle ne s'affichait que si l'utilisateur était passé par l'onglet
    // Mobilité auparavant, le loader gardant les scripts déjà chargés.
    energie:           ['js/pages/mobilite.js',
                        'js/pages/energie.config.js', 'js/pages/energie.carbone.js',
                        'js/pages/energie.compteur.js', 'js/pages/energie.releve.js',
                        'js/pages/energie.ui.js', 'js/pages/energie.render.js'],
    carbone:           ['js/pages/mobilite.js',
                        'js/pages/energie.config.js', 'js/pages/energie.carbone.js',
                        'js/pages/energie.compteur.js', 'js/pages/energie.releve.js',
                        'js/pages/energie.ui.js', 'js/pages/energie.render.js'],
    gestion_materiel:  ['js/pages/gestion-materiel.js'],
    utilisateurs:      ['js/pages/utilisateurs.js'],
    historique:        ['js/pages/stats.js', 'js/pages/demandes.js', 'js/pages/historique.js'],
    // ⚠️ DÉPENDANCE CROSS-PAGES : l'onglet « Comptes » de la configuration
    // affiche un tableau dont les boutons Nouveau/Modifier/Supprimer appellent
    // editUser()/deleteUser(), définis dans utilisateurs.js. Sans ce fichier,
    // les boutons lèvent « editUser is not defined » et ne font rien.
    configuration:     ['js/pages/utilisateurs.js', 'js/pages/configuration.js'],
    journal:           ['js/pages/journal.js'],
    statsAvancees:     ['js/pages/stats.js'],
    demandes:          ['js/pages/interventions.js', 'js/pages/archives.js', 'js/pages/demandes.js'],
    demandesArchives:  ['js/pages/interventions.js', 'js/pages/archives.js', 'js/pages/demandes.js'],
    scan:              ['js/zxing-browser.min.js', 'js/pages/biens.js', 'js/pages/equipements.js', 'js/pages/scan.js'],
    archives:          ['js/pages/demandes.js', 'js/pages/archives.js'],
    legifrance:        ['js/pages/utilisateurs.js', 'js/pages/configuration.js', 'js/pages/legifrance.js'],
    chorus:            ['js/pages/utilisateurs.js', 'js/pages/configuration.js', 'js/pages/chorus-pro.js'],
    superadmin:        ['js/pages/superadmin.js'],
    superadmin_branding: ['js/pages/superadmin.js'],
    inventaire_agent:  ['js/pages/inventaire-agent.js'],
    mobilite_carbone:  ['js/pages/mobilite.js'],
    plans:             ['js/pages/plans.js'],
    annuaire:          ['js/pages/annuaire.js'],
    urgences:          ['js/pages/urgences.js'],
    // Assistant IA : chargé à la demande par initApp(), uniquement si
    // l'assistant est activé pour le tenant (cf. init.js → assistant_status).
    assistant:         ['js/assistant.js'],
    // documents.js est utilisé transversalement (modales documents partout) ;
    // on le charge en priorité au boot, pas via le loader.
  };

  // ── État interne ────────────────────────────────────────────────────────
  // ⚠️ FIX : la version des pages est déduite du ?v= avec lequel CE fichier a
  // été chargé par index.html. C'était auparavant une constante ('1') à
  // synchroniser À LA MAIN avec index.html — un commentaire « doit matcher »
  // au lieu d'un mécanisme. Conséquence vécue : index.html bumpé mais pas
  // cette constante ⇒ les pages restaient servies en ?v=1, donc lues dans un
  // cache « immutable, max-age=604800 » : tout déploiement de js/pages/*.js
  // était invisible jusqu'à 7 jours, sans le moindre signal.
  // Désormais index.html est l'unique source de vérité :
  //     sed -i -E "s/\?v=[0-9a-zA-Z.]+/?v=$(date +%Y%m%d%H%M)/g" index.html
  const SCRIPT_VERSION = (function () {
    try {
      // currentScript pendant l'exécution initiale ; sinon on retrouve la balise.
      const el = document.currentScript
              || document.querySelector('script[src*="page-loader.js"]');
      const m = (el && el.src || '').match(/[?&]v=([^&]+)/);
      if (m && m[1]) return m[1];
    } catch (_e) { /* contexte exotique : on retombe sur le défaut */ }
    return '1';
  })();
  const loaded   = new Set();  // path → déjà exécuté avec succès dans CE document
  const inflight = new Map();  // path → Promise en cours

  // ⚠️ POURQUOI ON NE RE-EXÉCUTE JAMAIS UN SCRIPT DÉJÀ CHARGÉ
  // Les fichiers js/pages/*.js s'exécutent dans la portée GLOBALE (pas d'IIFE,
  // pas de module) et déclarent des `const`/`let` au premier niveau
  // (ex: `const URGENCE_COLORS = …` dans demandes.js). Ré-injecter une balise
  // <script> pour un de ces fichiers le ré-évalue dans le même realm, ce qui
  // lève `SyntaxError: Identifier 'X' has already been declared` et casse la
  // page. Le chargement est donc IDEMPOTENT : une fois qu'un script a été
  // exécuté, on considère son code définitivement présent en mémoire pour la
  // durée de vie du document. Un rafraîchissement complet (F5) ré-télécharge
  // tout proprement ; c'est le seul moment où une nouvelle version est prise.
  //
  // (L'ancienne logique de « cache 6h » ré-injectait les scripts après
  // inactivité — précisément le scénario qui déclenchait le SyntaxError.
  // Elle a été retirée : le cache HTTP du navigateur + le ?v= versionné
  // suffisent à servir des assets à jour sans jamais ré-évaluer en mémoire.)

  /**
   * Charge un script <path> via une balise <script> ajoutée au DOM.
   * Idempotent : si le script a déjà été exécuté ou est en cours de
   * chargement, on renvoie immédiatement (pas de ré-injection).
   * Retourne une Promise qui resolve quand le script est exécuté.
   */
  function loadScript(path) {
    // Déjà exécuté dans ce document → rien à faire.
    if (loaded.has(path)) return Promise.resolve();

    // Déjà en cours de chargement → on partage la même Promise.
    if (inflight.has(path)) return inflight.get(path);

    const p = new Promise((resolve, reject) => {
      const s = document.createElement('script');
      s.src = path + '?v=' + SCRIPT_VERSION;
      s.async = false; // préserver l'ordre d'exécution si plusieurs scripts
      s.onload = () => {
        loaded.add(path);
        inflight.delete(path);
        resolve();
      };
      s.onerror = () => {
        inflight.delete(path);
        // On retire aussi la balise échouée pour permettre un vrai retry propre.
        try { s.remove(); } catch (_) {}
        reject(new Error('Échec de chargement : ' + path));
      };
      document.head.appendChild(s);
    });
    inflight.set(path, p);
    return p;
  }

  /**
   * Garantit que tous les scripts associés à pageKey sont chargés.
   * Retourne une Promise qui resolve quand tout est prêt.
   */
  async function ensure(pageKey) {
    const scripts = PAGE_SCRIPTS[pageKey];
    if (!scripts || scripts.length === 0) return;
    // Chargement séquentiel pour respecter les dépendances inter-scripts
    for (const path of scripts) {
      await loadScript(path);
    }
  }

  /**
   * Précharge en arrière-plan une liste de pages. Utilisé après initApp() pour
   * réduire la latence des navigations futures. N'affecte pas le rendu courant.
   * Les erreurs sont silencieuses (best-effort).
   */
  function preload(pageKeys) {
    if (!Array.isArray(pageKeys) || pageKeys.length === 0) return;
    // requestIdleCallback si dispo, sinon setTimeout
    const schedule = window.requestIdleCallback || ((cb) => setTimeout(cb, 200));
    schedule(async () => {
      for (const k of pageKeys) {
        try { await ensure(k); } catch (_e) { /* silence */ }
      }
    }, { timeout: 4000 });
  }

  /**
   * Recommandations de préchargement selon le rôle utilisateur. Appelé depuis
   * initApp() après authentification, juste après la première navigate().
   */
  function preloadForRole(role) {
    switch (role) {
      case 'Demandeur':
        // Le Demandeur utilise principalement demandes + historique.
        // inventaire_agent dépend d'un flag (App._inventaireAgentActif).
        // urgences précharge aussi pour réactivité en cas de besoin urgent.
        preload(['demandes', 'historique', 'inventaire_agent', 'urgences']);
        break;
      case 'Visionneur':
        preload(['dashboard', 'biens', 'equipements', 'interventions', 'historique', 'urgences']);
        break;
      case 'Technicien':
        preload(['dashboard', 'interventions', 'biens', 'equipements', 'demandes', 'scan']);
        break;
      case 'Gestionnaire':
      case 'Admin':
        // Les pages "lourdes" peu utilisées (superadmin, legifrance, chorus) ne sont PAS préchargées.
        preload(['dashboard', 'demandes', 'interventions', 'biens', 'equipements', 'historique']);
        break;
      default:
        preload(['dashboard']);
    }
  }

  /**
   * Invalidation manuelle (debug). Retire un (ou tous les) chemin(s) de l'état
   * « déjà chargé ». ⚠️ Cela n'efface PAS le code déjà exécuté en mémoire : un
   * appel ensure() suivant ré-injectera le script et, comme expliqué plus haut,
   * un fichier de page non-isolé lèvera alors « Identifier already declared ».
   * Pour réellement repartir propre, faire un rechargement complet (F5).
   * Utile surtout pour forcer le rechargement de scripts isolés/idempotents.
   * window.PageLoader.invalidate() pour tout vider.
   */
  function invalidate(path) {
    if (path) loaded.delete(path);
    else loaded.clear();
  }

  // ── Export API publique ──
  window.PageLoader = { ensure, preload, preloadForRole, invalidate, PAGE_SCRIPTS };
})();

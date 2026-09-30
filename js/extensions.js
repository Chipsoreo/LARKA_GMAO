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
 * Larka — Extensions : chargement côté client
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Interroge ext_manifeste au démarrage, enregistre les pages déclarées dans
 * PAGES (nav.js) et charge les scripts des extensions actives.
 *
 * ⚠️ CÔTÉ NAVIGATEUR, IL N'Y A PAS DE BAC À SABLE
 * Un script d'extension chargé dans la page a le même pouvoir que le code de
 * Larka : il peut lire le DOM, appeler l'API avec le cookie de session, et
 * modifier n'importe quel écran. Aucun découpage JavaScript ne change cela —
 * ce qui protégerait vraiment (une iframe cloisonnée par origine), coûterait
 * une refonte de tous les échanges entre la page et l'extension.
 *
 * La conséquence pratique : le code CLIENT d'une extension est aussi sensible
 * que son code serveur, et l'écran d'installation le dit. Les protections
 * décrites dans docs/EXTENSIONS.md portent sur le serveur.
 *
 * Ce module se contente donc de trois choses honnêtes :
 *   1. ne charger que ce que le serveur a autorisé pour le rôle courant ;
 *   2. cantonner chaque page d'extension à SON conteneur DOM ;
 *   3. attraper les erreurs pour qu'une extension cassée ne fige pas Larka.
 * ═══════════════════════════════════════════════════════════════════════════════
 */

const LarkaExtensions = (() => {
  'use strict';

  let _chargees = [];
  let _actif = false;
  let _mode = 'standard';

  /**
   * Appel d'une action serveur d'extension.
   * C'est le SEUL chemin offert aux extensions pour parler au serveur : elles
   * n'ont pas à connaître ?action=, et le serveur vérifie que l'action est bien
   * déclarée au manifeste.
   */
  async function appel(extension, action, charge = {}) {
    return apiRequest('ext_appel', 'POST', { extension, action, charge });
  }

  /** Charge un script en garantissant qu'il n'est injecté qu'une fois. */
  function chargerScript(url) {
    return new Promise((resolve, reject) => {
      if (document.querySelector(`script[data-ext-src="${url}"]`)) return resolve();
      const s = document.createElement('script');
      s.src = url;
      s.async = false;             // ordre de déclaration respecté
      s.dataset.extSrc = url;
      s.onload = () => resolve();
      s.onerror = () => reject(new Error(`Script d'extension introuvable : ${url}`));
      document.head.appendChild(s);
    });
  }

  function chargerFeuille(url) {
    if (document.querySelector(`link[data-ext-src="${url}"]`)) return;
    const l = document.createElement('link');
    l.rel = 'stylesheet';
    l.href = url;
    l.dataset.extSrc = url;
    document.head.appendChild(l);
  }

  /**
   * Enregistre la page d'une extension dans le registre de nav.js.
   *
   * La fonction de rendu appelée est celle que l'extension a publiée sous
   * window.LarkaExt['<identifiant>'].rendre(conteneur, api). On lui passe un
   * conteneur à elle : elle n'a aucune raison d'aller chercher #app.
   */
  // ═══════════════════════════════════════════════════════════════════════
  //  Bac à sable : une iframe par page de module
  // ═══════════════════════════════════════════════════════════════════════


  /** Le code du bac, chargé une fois puis réutilisé pour chaque iframe. */

  /**
   * Récupère le code client d'un module.
   *
   * C'est le PARENT qui le télécharge, avec la session : l'iframe, en origine
   * opaque, n'a pas de cookie et se verrait refuser la requête. Le code est
   * ensuite injecté dans le document du bac.
   */

  /** Feuille de style minimale, pour que le module ait l'air d'être chez lui. */

  /**
   * Construit l'iframe isolée et le pont de messages.
   *
   * `sandbox="allow-scripts"` SANS `allow-same-origin` : c'est ce couple précis
   * qui donne une origine opaque. Ajouter allow-same-origin annulerait tout —
   * le module retrouverait le DOM de Larka et la session de l'utilisateur.
   */

  /** Ancienne voie : le module s'exécute dans la page, sans isolement. */

  /**
   * Enregistre UNE route par page du module.
   *
   * ⚠️ IL N'Y EN AVAIT QU'UNE, TOUJOURS LA PREMIÈRE.
   * Un module déclarant deux écrans — un formulaire de demande ouvert à tous,
   * un planning réservé aux gestionnaires — n'en exposait qu'un. Le second
   * n'avait ni route ni entrée de menu : il passait la validation, était servi
   * par le serveur, et restait injoignable.
   *
   * La clé de route porte désormais la page : « ext_larka_recharge » pour la
   * première (inchangée, les liens existants continuent de fonctionner) et
   * « ext_larka_recharge__planning » pour les suivantes.
   */
  function enregistrerPage(ext) {
    if (typeof PAGES === 'undefined') return;
    const base = `ext_${ext.identifiant.replace(/[^a-z0-9]/g, '_')}`;
    const pages = (ext.pages && ext.pages.length) ? ext.pages : (ext.page ? [ext.page] : []);
    if (!pages.length) return;

    pages.forEach((pg, i) => enregistrerUne(ext, pg, i === 0 ? base : `${base}__${pg.cle}`));
  }

  function enregistrerUne(ext, page, cle) {
    const nomFn = `__extRender_${cle}`;

    PAGES[cle] = {
      title: page.titre,
      renderFn: nomFn,
      extension: ext.identifiant,
      icone: page.icone,
      roles: page.roles,
    };

    window[nomFn] = async function () {
      const hote = document.getElementById('mainContent');
      if (!hote) return;
      try {
        if (ext.declaratif) {
          // Module déclaratif : aucun code tiers, donc aucun bac à sable à
          // monter. C'est le rendu de Larka qui dessine, à partir de la
          // description — l'iframe n'aurait rien à isoler.
          const boite = document.createElement('div');
          boite.className = 'dp-conteneur';
          boite.dataset.extension = ext.identifiant;
          hote.innerHTML = '';
          hote.appendChild(boite);
          await LarkaDeclaratif.rendre(boite, {
            appel: (action, charge) => appel(ext.identifiant, action, charge),
          }, ext.declaration, page.cle);
          return;
        }
        // Inatteignable : tout module est déclaratif et a été rendu ci-dessus.
        // La voie « code de l'auteur » a disparu avec le bac à sable — il n'y
        // a plus de JavaScript tiers à exécuter, donc plus rien à cloisonner.
        throw new Error('Format de module non pris en charge.');
      } catch (e) {
        console.error(`[extension ${ext.identifiant}]`, e);
        hote.innerHTML = '';
        const p = document.createElement('div');
        p.style.cssText = 'padding:20px;color:#b91c1c';
        p.textContent = `Le module « ${ext.nom} » n'a pas pu s'afficher : ${e.message}`;
        hote.appendChild(p);
      }
    };
  }

  /**
   * Ajoute les entrées de menu des extensions.
   *
   * buildSidebar() de nav.js écrit `nav.innerHTML` à partir d'une liste FIXE :
   * enregistrer une page dans PAGES ne la fait donc pas apparaître dans le
   * menu. On injecte les entrées dans le DOM après coup, dans leur propre
   * section — c'est aussi plus honnête visuellement : ces pages ne viennent
   * pas de Larka.
   *
   * Rejoué à chaque reconstruction du menu (changement de préférences de
   * visibilité, retour de configuration), sinon les entrées disparaissent.
   */
  /**
   * Retouches du menu du cœur : masquer, renommer, rediriger.
   *
   * Appliquée APRÈS la construction du menu par nav.js, et rejouée à chaque
   * reconstruction — le menu est redessiné à chaque navigation, une retouche
   * posée une seule fois disparaîtrait au premier clic.
   *
   * Le masquage est VISUEL. On retire l'entrée du menu ; on ne touche ni aux
   * droits du rôle, ni à la route. L'écran reste joignable, et un module mal
   * réglé ne peut donc pas enfermer l'administrateur hors de son logiciel.
   *
   * Les écrans du journal, des extensions, de la configuration et des comptes
   * sont refusés côté serveur, à la validation. On ne les revérifie pas ici :
   * un contrôle client ne protège de rien, et le laisser croire serait pire.
   */
  function appliquerRetouches() {
    const nav = document.getElementById('sidebarNav');
    if (!nav) return;
    const role = (typeof App !== 'undefined' && App.currentUser?.Role)
              || window._currentUser?.Role || '';

    _chargees.forEach((ext) => {
      (ext.interface || []).forEach((r) => {
        // Retouche ciblée : sans rôles listés, elle vaut pour tout le monde.
        if (r.roles?.length && !r.roles.includes(role)) return;

        const item = nav.querySelector(`.nav-item[data-page="${CSS.escape(r.element)}"]`);
        if (!item) return;   // écran non affiché pour ce rôle : rien à retoucher

        if (r.operation === 'masquer') { item.remove(); return; }

        const etiquette = item.querySelector('.nav-label');
        if (r.libelle && etiquette) etiquette.textContent = r.libelle;
        if (r.icone) {
          const ic = item.querySelector('.icon');
          if (ic) ic.textContent = r.icone;
        }
        if (r.operation === 'rediriger') {
          const cle = `ext_${ext.identifiant.replace(/[^a-z0-9]/g, '_')}`;
          item.dataset.page = cle;
          item.onclick = () => window.navigate(cle);
        }
      });
    });
  }

  function injecterMenu() {
    const nav = document.getElementById('sidebarNav');
    if (!nav || !_chargees.length) return;

    // ⚠️ NETTOYAGE SUR TOUT LE DOCUMENT, PAS SEULEMENT SUR « nav ».
    // Une entrée placée dans une autre section reste dans la barre, mais un
    // second appel concurrent pouvait la manquer et en ajouter une seconde.
    // Deux « Planning des bornes » dans le menu, l'un sous Maintenance, l'autre
    // à sa place d'origine.
    document.querySelectorAll('[data-ext-nav]').forEach(el => el.remove());

    // Les retouches passent AVANT l'ajout des entrées de modules : une
    // redirection vise une page de module, qui doit exister au moment où l'on
    // pose le gestionnaire de clic.
    appliquerRetouches();

    // ⚠️ PAS window.App : init.js déclare « const App = {...} » au niveau
    // module. Un const global crée bien une liaison globale, mais PAS une
    // propriété de window — window.App vaut undefined, le rôle tombait à '',
    // et le filtre ci-dessous supprimait TOUTES les entrées de menu. Symptôme :
    // l'extension se charge, sa page est enregistrée, et rien n'apparaît.
    const role = (typeof App !== 'undefined' && App.currentUser?.Role)
              || window._currentUser?.Role
              || '';
    const visibles = _chargees.filter(e => e.page && (
      role === 'Gestionnaire' || role === 'Admin' || (e.page.roles || []).includes(role)));
    if (!visibles.length) return;

    const titre = document.createElement('div');
    titre.className = 'nav-section-title';
    titre.dataset.extNav = '1';
    titre.style.marginTop = '8px';
    titre.textContent = 'Modules communautaires';
    nav.appendChild(titre);

    // Une entrée par PAGE, pas par module. Un module à deux écrans n'en
    // affichait qu'un : le second existait côté serveur et n'apparaissait nulle
    // part. Le serveur a déjà filtré les pages selon le rôle — on dessine ce
    // qu'il envoie.
    //
    // Une page ancrée ailleurs — dans un onglet de « Mes demandes », par
    // exemple — n'est PAS reprise ici : elle est déjà atteignable, et la voir
    // deux fois laisserait croire à deux écrans distincts.
    visibles.forEach(e => {
      const base = `ext_${e.identifiant.replace(/[^a-z0-9]/g, '_')}`;
      // ⚠️ PLUS D'EXCLUSION DES PAGES ANCRÉES.
      // Quand l'ancrage plaçait la PAGE ENTIÈRE dans un onglet, la reprendre au
      // menu aurait fait deux entrées pour le même écran. Depuis qu'il ne place
      // que le FORMULAIRE dans « Nouvelle demande », la page reste le seul
      // endroit où consulter ses demandes — l'exclure la rendait injoignable.
      // Le demandeur envoyait sa réservation et ne la revoyait jamais.
      const ancrees = new Set();
      const pages = (e.pages && e.pages.length) ? e.pages : (e.page ? [e.page] : []);

      pages.forEach((pg, i) => {
        if (ancrees.has(pg.cle)) return;
        const cle = i === 0 ? base : `${base}__${pg.cle}`;
        const item = document.createElement('div');
        item.className = 'nav-item';
        item.dataset.page = cle;
        item.dataset.extNav = '1';
        item.title = `${e.nom} — module communautaire`;
        item.innerHTML = `<span class="icon">${iconeModule(e)}</span>`
          + `<span class="nav-label">${echapper(pg.titre)}</span>`;
        item.addEventListener('click', () => navigate(cle));
        // Dernier filet : si une entrée de cette page survit malgré le
        // nettoyage, on ne la double pas.
        if (nav.querySelector('[data-page="' + cle + '"]')) return;
        placer(nav, item, cle);
      });
    });
  }

  /**
   * Place une entrée de module en respectant les préférences d'interface.
   *
   * ⚠️ ELLE ÉTAIT TOUJOURS AJOUTÉE À LA FIN, dans sa propre section.
   * L'utilisateur pouvait donc la déplacer dans le panneau « Interface » —
   * la ligne bougeait, la préférence était enregistrée — et la barre la
   * remettait au même endroit au rendu suivant. Un réglage qui s'applique
   * partout sauf là où on le regarde ne vaut pas mieux qu'un réglage absent.
   *
   * Si l'utilisateur a rangé l'entrée dans une autre section, on l'insère à la
   * fin de celle-là. Sinon, comportement d'avant.
   */
  function placer(nav, item, cle) {
    let section = null;
    try {
      const prefs = (typeof uiPrefsGet === 'function') ? uiPrefsGet() : {};
      section = (prefs.itemSections || {})[cle] || null;
    } catch (_) { /* pas de préférences : on ajoute à la fin */ }

    if (section) {
      const titres = [...nav.querySelectorAll('.nav-section-title')];
      const titre = titres.find(t => t.textContent.trim() === section);
      if (titre) {
        // Dernier frère de cette section : juste avant le titre suivant.
        let curseur = titre;
        while (curseur.nextElementSibling
               && !curseur.nextElementSibling.classList.contains('nav-section-title')) {
          curseur = curseur.nextElementSibling;
        }
        curseur.after(item);
        return;
      }
    }
    nav.appendChild(item);
  }

  /**
   * Icône d'un module, par ordre de préférence : logo, icône déclarée, défaut.
   *
   * ⚠️ TOUS LES MODULES AFFICHAIENT LA MÊME PIÈCE DE PUZZLE.
   * L'entrée de menu écrivait « 🧩 » en dur. L'icône déclarée au manifeste —
   * « key » pour les clés, « palette » pour l'apparence — n'était lue nulle
   * part, et le logo livré dans assets/ n'était utilisé par aucun écran. Un
   * auteur pouvait soigner les deux sans que rien n'apparaisse jamais.
   *
   * Le logo passe par ext_image, la seule route qui sait lire un fichier de
   * module : le dossier installé vit hors racine web, une balise <img> pointant
   * vers lui n'aurait rien chargé.
   *
   * Le cœur écrit ses icônes en emoji ; les modules les déclarent par un nom
   * (contrainte du schéma : minuscules et tirets, pas d'emoji arbitraire). La
   * table ci-dessous fait le pont. Un nom absent retombe sur la pièce de
   * puzzle — un module reste identifiable, il n'a simplement pas d'icône à lui.
   */
  const _ICONES = {
    key:'🔑', lock:'🔒', shield:'🛡️', 'shield-check':'🛡️', award:'🎖️',
    palette:'🎨', users:'👥', user:'👤', file:'📄', folder:'📁', tag:'🏷️',
    calendar:'📅', clock:'🕐', chart:'📊', map:'🗺️', pin:'📍', tool:'🔧',
    truck:'🚚', box:'📦', bell:'🔔', star:'⭐', flag:'🚩', book:'📕',
    graduation:'🎓', building:'🏢', leaf:'🌿', bolt:'⚡', droplet:'💧',
    fire:'🔥', wrench:'🔧', clipboard:'📋', puzzle:'🧩',
  };
  function iconeModule(e) {
    // « logo » est DÉJÀ une URL complète, composée par le serveur avec son
    // paramètre de version. La reconstruire ici la casserait — et ferait vivre
    // deux façons de fabriquer la même adresse, qui finiraient par diverger.
    // On la revérifie avant de la poser dans un attribut src.
    if (typeof e.logo === 'string'
        && /^api\/index\.php\?action=ext_image&[\w=%.&-]+$/.test(e.logo)) {
      return `<img src="${e.logo}" alt="" style="width:18px;height:18px;`
           + `object-fit:contain;vertical-align:-3px" loading="lazy">`;
    }
    return _ICONES[String(e.page?.icone || '').toLowerCase()] || '🧩';
  }

  function echapper(s) {
    const d = document.createElement('div');
    d.textContent = String(s ?? '');
    return d.innerHTML;
  }

  /**
   * Recharge les déclarations depuis le serveur.
   *
   * ⚠️ CE QUI MANQUAIT.
   * initialiser() ne tourne QU'UNE FOIS, après l'authentification. Les
   * déclarations restaient donc figées pour toute la session : on modifiait une
   * liste dans Configuration — valeurs, « champ obligatoire », « saisie
   * libre » — puis on ouvrait le module, et rien n'avait changé. Le serveur
   * renvoyait pourtant les bons réglages ; c'est le client qui travaillait
   * encore sur la copie chargée au démarrage.
   *
   * Vu de l'utilisateur, la configuration paraissait sans effet, et seul un F5
   * la faisait apparaître — ce que personne n'a de raison de tenter, puisque
   * l'enregistrement s'était annoncé réussi.
   *
   * On repart de zéro : sans vider _chargees, initialiser() empilerait une
   * seconde copie de chaque module.
   */
  async function recharger() {
    _chargees = [];
    await initialiser();
  }

  /** Appelé une fois après l'authentification, depuis init.js. */
  async function initialiser() {
    try {
      const rep = await apiRequest('ext_manifeste', 'GET');
      const data = rep?.data ?? rep;
      _actif = !!data?.actif;
      _mode = data?.mode || 'standard';
      if (!_actif) return;

      for (const ext of (data.extensions || [])) {
        try {
          for (const url of (ext.scripts || [])) {
            if (url.endsWith('.css')) chargerFeuille(url);
            else await chargerScript(url);
          }
          enregistrerPage(ext);
          _chargees.push(ext);
        } catch (e) {
          // Une extension qui ne se charge pas n'empêche pas les autres.
          console.warn(`[extension ${ext.identifiant}] non chargée :`, e.message);
        }
      }

      // ⚠️ NE PAS appeler buildSidebarWithVisibility() ici : elle exige
      // l'utilisateur en argument (buildSidebar lit user.Role) et lèverait une
      // TypeError, avalée par le catch — les extensions semblaient alors « ne
      // pas fonctionner » sans le moindre message. On ajoute nos entrées au
      // menu déjà construit, sans le reconstruire.
      injecterMenu();
      await chargerThematiques();
      await chargerCatalogue();

      // Habillage : après le chargement de tous les modules, pour que le
      // dernier actif l'emporte de façon prévisible.
      try { appliquerHabillage(); } catch (e) { console.warn('Habillage :', e); }
      try {
        appliquerTraductions(document.body);
        surveillerTraductions();
      } catch (e) { console.warn('Traductions :', e); }
      if (_mode === 'developpement') {
        banniereDeveloppement();
      }
    } catch (e) {
      console.warn('Extensions : manifeste indisponible.', e);
    }
  }

  /**
   * Bandeau permanent en mode développement. Un mode qui désactive les
   * protections doit se voir en permanence, sinon on l'oublie — et c'est ainsi
   * qu'une installation part en production sans filet.
   */
  function banniereDeveloppement() {
    if (document.getElementById('ext-banniere-dev')) return;
    const b = document.createElement('div');
    b.id = 'ext-banniere-dev';
    b.style.cssText = 'position:fixed;bottom:0;left:0;right:0;z-index:9999;'
      + 'background:#b91c1c;color:#fff;padding:6px 12px;font-size:13px;text-align:center;'
      + 'font-family:inherit;';
    b.textContent = 'Extensions en mode développement : les protections sont désactivées. '
      + 'Ne pas utiliser en production.';
    document.body.appendChild(b);
  }

  /**
   * Appelé par le CŒUR pour offrir un emplacement aux extensions.
   *
   *   LarkaExtensions.rendrePoint('plans.barre_outils', hote, { map, etageId })
   *
   * Chaque extension déclarant cet ancrage reçoit son PROPRE conteneur, créé
   * ici, plus le contexte fourni par la page. Une extension qui échoue est
   * signalée en console et n'empêche ni les autres, ni la page.
   */
  /**
   * Dessine les ancrages déclarés à un emplacement donné.
   *
   * Cette fonction cherchait une FONCTION JavaScript publiée par le module.
   * Il n'y en a plus : les modules sont déclaratifs, et c'est Larka qui dessine.
   * Résultat, plus aucun ancrage ne s'affichait — ni tuile, ni repère, ni bloc
   * de fiche — sans le moindre message, puisque l'absence de fonction était
   * traitée comme un cas normal.
   */
  /**
   * Applique un habillage.
   *
   * Le cœur ne connaît ni thème, ni police, ni couleur. Il sait seulement :
   *   • lire le VOCABULAIRE publié par un module chargeur — quelles variables
   *     existent, ce qu'elles pilotent, entre quelles bornes ;
   *   • appliquer les VALEURS qu'un autre module fournit pour ce vocabulaire.
   *
   * Ajouter une propriété d'apparence ne demande donc pas de toucher à Larka :
   * on l'ajoute au vocabulaire du chargeur. C'est le chargeur qui « invente »
   * les variables, pas le produit.
   */
  /**
   * Applique l'habillage.
   *
   * @param {object|null} apercu  Préférences à utiliser À LA PLACE de celles
   *        enregistrées. Permet de MONTRER un réglage sans le persister : on
   *        voit ce qu'on obtient, puis on décide.
   *
   * L'écran d'apparence écrivait dans le stockage local à chaque clic. Rien de
   * grave en soi, mais on ne pouvait pas essayer : chaque essai devenait le
   * réglage, et revenir en arrière supposait de se souvenir de l'état
   * précédent — que rien n'affichait.
   */
  function appliquerHabillage(apercu) {
    const racine = document.documentElement;

    // On retire d'abord ce qu'un habillage précédent avait posé : changer de
    // thème ne doit pas en laisser la moitié.
    (window._larkaHabillage || []).forEach(v => racine.style.removeProperty(v));
    window._larkaHabillage = [];
    // ── Mode sombre et thème : un seul à la fois ─────────────────────────
    //
    // dark-mode.css écrit ses couleurs en dur, avec plus de cent
    // « !important ». Lutter règle par règle serait une course sans fin :
    // chaque nouvelle règle du mode sombre casserait les thèmes en silence.
    //
    // On SUSPEND donc le mode sombre tant qu'un thème est choisi, et on le
    // rétablit quand on n'en veut plus. Le réglage de l'utilisateur n'est pas
    // perdu : il est mis de côté, pas effacé.
    if (document.body) {
      document.body.removeAttribute('data-theme');
      if (typeof larkaMajBoutonSombre === 'function') larkaMajBoutonSombre();
      if (window._larkaSombreSuspendu) {
        document.body.classList.add('dark-mode');
        window._larkaSombreSuspendu = false;
      }
    }

    // Choix PERSONNEL : le gestionnaire installe, chacun décide.
    let choisi = null, perso = {};
    try {
      const p = apercu || JSON.parse(localStorage.getItem('gmao_ui_prefs')) || {};
      choisi = p.theme_module;
      perso  = p.apparence_perso || {};
    } catch (e) { /* préférences illisibles */ }

    // Les ajustements personnels s'appliquent MÊME sans thème : on peut vouloir
    // changer une seule couleur sans adopter tout un habillage.
    // Une thématique désactivée reste sur le serveur mais ne s'applique pas :
    // c'est la différence entre « je n'en veux plus » et « je la garde sous la
    // main ».
    const tout = _chargees.concat(_thematiques.filter(t => t.active !== false));
    const chargeurs = tout.filter(e => e.vocabulaire
                                    && Object.keys(e.vocabulaire).length > 0);
    if (!choisi && Object.keys(perso).length > 0 && chargeurs.length > 0) {
      // ⚠️ « data-theme » MANQUAIT ICI, et c'est ce qui empêchait le fond
      // d'écran de s'afficher.
      //
      // css/apparence.css conditionne l'image de fond — et le voile qui la
      // couvre — à « body[data-theme] ». L'attribut n'était posé que sur le
      // chemin « une thématique est choisie ». Un utilisateur qui déposait
      // simplement une image, sans adopter de thème, voyait la variable CSS
      // correctement définie… et aucune règle pour la consommer.
      //
      // On marque donc aussi les ajustements seuls. La valeur « _perso »
      // distingue ce cas d'un vrai thème pour qui inspecte la page.
      if (document.body) document.body.setAttribute('data-theme', '_perso');
      appliquerValeurs(chargeurs[0].vocabulaire, perso, racine, 'ajustements');
      return;
    }
    if (!choisi) return;

    const fournisseur = tout.find(e => e.identifiant === choisi);
    if (!fournisseur || !fournisseur.variables) return;

    // Le vocabulaire vient du module désigné par « de ». S'il n'est pas
    // installé, on n'applique RIEN : appliquer des valeurs dont on ignore la
    // signification reviendrait à écrire au hasard dans la feuille de style.
    const chargeur = tout.find(e => e.identifiant === fournisseur.variables.de);
    if (!chargeur || !chargeur.vocabulaire) {
      console.warn(`[${choisi}] le module « ${fournisseur.variables.de} » n'est pas `
                 + 'installé : habillage non appliqué.');
      return;
    }

    // Thème d'abord, ajustements personnels ensuite : l'utilisateur retouche
    // ce qu'il a choisi, il ne se le fait pas écraser.
    // Le thème fournit-il une variante sombre ?
    const variantes = fournisseur.variables.valeurs_sombre || {};
    const aVariante = Object.keys(variantes).length > 0;
    const sombreVoulu = localStorage.getItem('darkMode') === '1';

    if (document.body) {
      document.body.setAttribute('data-theme', choisi);
      // ── Le thème et le mode sombre ne se battent plus ─────────────────
      //
      // dark-mode.css écrit ses couleurs en dur avec plus de cent
      // « !important » : le laisser cohabiter avec un thème donnait n'importe
      // quoi. On le suspendait donc, et l'utilisateur devait choisir entre son
      // thème et sa vue.
      //
      // Un thème qui déclare « valeurs_sombre » n'a plus besoin de cette
      // feuille : il porte lui-même sa version sombre. On la lui applique, et
      // le bouton redevient utile.
      if (document.body.classList.contains('dark-mode')) {
        document.body.classList.remove('dark-mode');
        window._larkaSombreSuspendu = !aVariante;
      }
      // ⚠️ L'ATTRIBUT NE SE POSE QUE SI LE THÈME A VRAIMENT UNE VARIANTE.
      // Il était posé dans tous les cas, à « clair » faute de mieux. Or le
      // bouton du menu teste la PRÉSENCE de l'attribut : un thème sans variante
      // — Ardoise, par exemple — passait donc pour en avoir une. Le bouton
      // s'affichait « Mode sombre », on cliquait, la préférence changeait en
      // mémoire… et rien ne bougeait à l'écran, puisqu'il n'y a pas de seconde
      // palette à appliquer.
      if (aVariante) {
        document.body.setAttribute('data-variante', sombreVoulu ? 'sombre' : 'clair');
      } else {
        document.body.removeAttribute('data-variante');
      }
      if (typeof larkaMajBoutonSombre === 'function') larkaMajBoutonSombre();
    }
    // Base, puis variante sombre si elle existe et qu'on la veut, puis
    // retouches personnelles : l'utilisateur retouche ce qu'il voit.
    const valeurs = Object.assign({}, fournisseur.variables.valeurs || {},
                                  (aVariante && sombreVoulu) ? variantes : {},
                                  perso);
    appliquerValeurs(chargeur.vocabulaire, valeurs, racine, choisi);
  }

  /** Pose des valeurs après vérification contre le vocabulaire. */
  function appliquerValeurs(vocab, valeurs, racine, origine) {
    const choisi = origine;
    Object.entries(valeurs).forEach(([nom, valeur]) => {
      const def = vocab[nom];
      // Une valeur sans variable correspondante est ignorée : le thème vise
      // peut-être une version plus récente du chargeur.
      if (!def || def.cible !== 'css' || !def.variable) return;

      let v = valeur;
      if (def.type === 'px' || def.type === 'nombre') {
        const n = Number(v);
        // Bornes déclarées par le CHARGEUR : une valeur hors limites est
        // ignorée plutôt que ramenée en silence — un thème qui demande 400 px
        // a un défaut, et le corriger sans le dire le masquerait.
        if (!isFinite(n) || n < def.min || n > def.max) {
          console.warn(`[${choisi}] « ${nom} » = ${v} hors bornes `
                     + `(${def.min}–${def.max}) : ignorée.`);
          return;
        }
        v = def.type === 'px' ? n + 'px' : String(n);
      } else if (def.type === 'couleur') {
        // rgba() accepté : un fond translucide au-dessus d'une image le
        // demande, et l'interdire obligerait à choisir entre les deux.
        if (!/^(#[0-9a-f]{3,8}|rgba?\([\d\s.,%]+\))$/i.test(String(v))) return;
      } else if (def.type === 'choix') {
        if (!(def.valeurs || []).includes(String(v))) return;
      } else if (def.type === 'image') {
        // ⚠️ DEUX DÉFAUTS SE SUPERPOSAIENT ICI, ET AUCUNE IMAGE NE S'AFFICHAIT.
        //
        // 1. Seul « assets/x.png » était accepté. Une image TÉLÉVERSÉE depuis
        //    l'écran Apparence a pour valeur une URL de route
        //    (« api/index.php?action=fond_image&f=… ») : elle échouait au test
        //    et la variable n'était jamais posée. Le fond ne s'affichait pas,
        //    et rien ne le disait à l'utilisateur.
        //
        // 2. Le chemin construit — « extensions/<id>/assets/… » — ne mène nulle
        //    part. Le code installé vit sous data/extensions/, hors racine web,
        //    et les sources ont été rangées dans extensions/modules/. Même
        //    l'image livrée avec un thème ne se chargeait donc pas.
        //
        // Les deux formes sont désormais acceptées, et le fichier d'un paquet
        // passe par ext_image — la seule route qui sait lire un fichier de
        // module, avec son type imposé et sa CSP.
        const brut = String(v);
        if (/^assets\/[\w.-]+\.(png|jpe?g|webp)$/i.test(brut)) {
          v = 'url("api/index.php?action=ext_image'
            + '&extension=' + encodeURIComponent(choisi)
            + '&fichier=' + encodeURIComponent(brut.replace(/^assets\//, '')) + '")';
        } else if (/^api\/index\.php\?action=fond_image&f=fond-[a-f0-9]{12}\.(png|jpe?g|webp)$/i
                   .test(brut)) {
          // Image personnelle téléversée : le nom est produit par le serveur
          // (empreinte + extension), on le revérifie plutôt que de faire
          // confiance à ce qui traîne dans le stockage du navigateur.
          v = `url("${brut}")`;
        } else if (brut === 'none' || brut === '') {
          v = 'none';
        } else {
          console.warn(`[${choisi}] « ${nom} » : image attendue dans assets/ du `
                     + `paquet, ou déposée depuis l'écran Apparence.`);
          return;
        }
      } else if (def.type === 'ombre') {
        // Grammaire close : décalages, flou, couleur. Rien qui puisse sortir
        // du cadre de l'élément.
        if (!/^(none|(-?\d{1,3}px\s+){2,3}(#[0-9a-f]{3,8}|rgba?\([\d\s.,%]+\)))$/i
            .test(String(v))) return;
      } else if (def.type === 'bordure') {
        if (!/^(none|\d{1,2}px\s+(solid|dashed|dotted|double)\s+(#[0-9a-f]{3,8}|rgba?\([\d\s.,%]+\)))$/i
            .test(String(v))) return;
      }

      racine.style.setProperty(def.variable, v);
      window._larkaHabillage.push(def.variable);
    });
  }

  /**
   * Applique les traductions fournies par un module de langue.
   *
   * Larka est écrite en français, en dur : il n'existe pas de table de
   * libellés à remplacer. On parcourt donc le DOM et on substitue les textes
   * connus du dictionnaire.
   *
   * C'est grossier, et il faut le dire : un mot traduit hors contexte peut
   * l'être à tort. Le dictionnaire est donc à correspondance EXACTE — on ne
   * remplace jamais un fragment — et seuls les nœuds de texte sont touchés,
   * jamais les attributs de code (identifiants, classes, valeurs de champ).
   *
   * L'alternative — extraire tous les libellés du produit — demanderait de
   * réécrire chaque écran : ce sera le bon chemin, mais il ne tient pas dans
   * un module.
   */
  function appliquerTraductions(racine) {
    let langue = null;
    try {
      langue = (JSON.parse(localStorage.getItem('gmao_ui_prefs')) || {}).langue;
    } catch (e) { /* préférences illisibles */ }
    langue = langue || 'fr';

    // ── Catalogue de base, PRIORITAIRE sur le français écrit en dur ──────
    //
    // lang/fr.json liste tout ce qui est traduisible, avec le texte d'origine
    // en clé et en valeur. Il permet de corriger un libellé sans toucher au
    // code, et sert de modèle à un traducteur.
    //
    // Il est FACULTATIF : supprimé, l'interface retombe sur le français des
    // écrans. Une traduction perdue ne doit pas casser le produit.
    const dict = {};
    if (_catalogue) {
      Object.entries(_catalogue).forEach(([cle, val]) => {
        if (val !== cle) dict[cle] = val;   // seules les corrections comptent
      });
    }

    // ── Pack de langue, par-dessus ───────────────────────────────────────
    if (langue !== 'fr') {
      _chargees.concat(_thematiques.filter(t => t.active !== false)).forEach((ext) => {
        const t = (ext.langues || {})[langue]
               || (ext.langues || {})[String(langue).split('-')[0]];
        if (t) Object.assign(dict, t);
      });
    }
    if (Object.keys(dict).length === 0) return;

    const cible = racine || document.body;
    const marcheur = document.createTreeWalker(cible, window.NodeFilter.SHOW_TEXT, {
      acceptNode(n) {
        // Ni script, ni style : y toucher casserait la page.
        const p = n.parentNode;
        if (!p) return window.NodeFilter.FILTER_REJECT;
        const tag = p.nodeName;
        if (tag === 'SCRIPT' || tag === 'STYLE' || tag === 'TEXTAREA') {
          return window.NodeFilter.FILTER_REJECT;
        }
        return n.nodeValue.trim() ? window.NodeFilter.FILTER_ACCEPT
                                  : window.NodeFilter.FILTER_REJECT;
      },
    });

    // Index insensible à la casse : les intitulés de section sont écrits en
    // capitales par la feuille de style, mais parfois AUSSI dans le HTML.
    // « FINANCES » ne correspondait alors à aucune entrée, et la section
    // restait en français au milieu d'un écran traduit.
    const dictBas = {};
    Object.entries(dict).forEach(([k, v]) => { dictBas[k.toLowerCase()] = v; });

    const casse = (source, trad) => {
      // On rend la traduction dans la même casse que l'original : « FINANCES »
      // traduit en « Finances » jurerait au milieu des autres titres.
      if (source === source.toUpperCase() && source !== source.toLowerCase()) {
        return trad.toUpperCase();
      }
      return trad;
    };

    const aTraduire = [];
    let n;
    while ((n = marcheur.nextNode())) aTraduire.push(n);

    aTraduire.forEach((noeud) => {
      const brut = noeud.nodeValue;
      const cle = brut.trim();
      let trad = dict[cle];
      if (trad === undefined) {
        const bas = dictBas[cle.toLowerCase()];
        if (bas !== undefined) trad = casse(cle, bas);
      }
      // Titre précédé d'un emoji : « 📝 Dernières demandes ». On traduit le
      // texte et on garde le symbole — le remplacer par un dictionnaire séparé
      // obligerait à répéter chaque libellé avec et sans son emoji.
      if (trad === undefined) {
        const m = cle.match(/^([\u{1F300}-\u{1FAFF}\u{2190}-\u{27BF}\u{FE0F}\s]+)(.+)$/u);
        if (m) {
          const t2 = dict[m[2]] !== undefined ? dict[m[2]] : dictBas[m[2].toLowerCase()];
          if (t2 !== undefined) trad = m[1] + casse(m[2], t2);
        }
      }
      if (trad === undefined || trad === cle) return;
      // On conserve les espaces d'origine : les retirer collerait le texte à
      // l'icône qui le précède.
      noeud.nodeValue = brut.replace(cle, trad);
    });

    // Les libellés portés par des attributs visibles.
    ['placeholder', 'title', 'aria-label'].forEach((attr) => {
      cible.querySelectorAll('[' + attr + ']').forEach((el) => {
        const v = (el.getAttribute(attr) || '').trim();
        const t = dict[v] !== undefined ? dict[v] : dictBas[v.toLowerCase()];
        if (t !== undefined) el.setAttribute(attr, casse(v, t));
      });
    });
  }

  /**
   * Retraduit après chaque rendu.
   *
   * Sans cela, seule la page affichée au démarrage était traduite : naviguer
   * ailleurs reconstruit le contenu, et le français revenait.
   */
  function surveillerTraductions() {
    let langue = null;
    try {
      langue = (JSON.parse(localStorage.getItem('gmao_ui_prefs')) || {}).langue;
    } catch (e) { /* rien */ }
    // On surveille aussi en français : le catalogue peut corriger un libellé,
    // et une correction qui ne tiendrait que sur le premier écran serait pire
    // que pas de correction du tout.
    const corrections = _catalogue
      && Object.entries(_catalogue).some(([k, v]) => k !== v);
    if ((!langue || langue === 'fr') && !corrections) return;
    if (window._larkaObservateur) return;

    let minuteur = null;
    window._larkaObservateur = new MutationObserver(() => {
      // Groupé : un rendu produit des dizaines de mutations, et traduire à
      // chacune ferait ramer la page.
      clearTimeout(minuteur);
      minuteur = setTimeout(() => appliquerTraductions(document.body), 60);
    });
    window._larkaObservateur.observe(document.body, { childList: true, subtree: true });
  }

  /** Modules d'habillage installés, pour que l'utilisateur puisse choisir. */
  /** Catalogue de libellés de base (lang/fr.json), s'il est présent. */
  let _catalogue = null;
  async function chargerCatalogue() {
    try {
      const r = await fetch('lang/fr.json', { credentials: 'same-origin' });
      if (!r.ok) return null;
      const j = await r.json();
      _catalogue = j && j.libelles ? j.libelles : null;
    } catch (e) {
      // Fichier absent ou illisible : on garde le français des écrans.
      _catalogue = null;
    }
    return _catalogue;
  }
  function catalogue() { return _catalogue; }

  /** Thématiques déposées sur le serveur, chargées avec le manifeste. */
  let _thematiques = [];
  function thematiques() { return _thematiques; }
  async function chargerThematiques() {
    try {
      const r = await apiRequest('thematiques');
      _thematiques = (r && r.data) ? r.data : (Array.isArray(r) ? r : []);
    } catch (e) { _thematiques = []; }
    return _thematiques;
  }

  function themesDisponibles() {
    // Un thème n'est proposé que si son chargeur est installé : l'offrir sans
    // lui donnerait un choix qui ne produit rien.
    // Les thèmes viennent des THÉMATIQUES déposées, pas des modules installés.
    const actives = _thematiques.filter(t => t.active !== false);
    const chargeurs = _chargees.concat(actives)
      .filter(c => c.vocabulaire && Object.keys(c.vocabulaire).length > 0);
    return actives
      .filter(t => t.variables && t.variables.de
                && chargeurs.some(c => c.identifiant === t.variables.de))
      .map(t => ({ identifiant: t.identifiant, nom: t.nom, description: t.description }));
  }

  /**
   * Nom d'une langue dans sa propre langue, depuis son code.
   *
   * Intl.DisplayNames connaît toutes les langues et suit les réglages du
   * navigateur ; la table de repli ne sert qu'aux environnements qui ne l'ont
   * pas. Écrire une table complète à la main aurait été une liste de plus à
   * tenir à jour, pour un résultat moins bon.
   */
  const _NOMS_LANGUE = { fr:'Français', en:'English', es:'Español', de:'Deutsch',
                         it:'Italiano', nl:'Nederlands', pt:'Português',
                         pl:'Polski', ro:'Română', ar:'العربية' };
  function nomLangue(code) {
    try {
      const n = new Intl.DisplayNames([code], { type: 'language' }).of(code);
      if (n && n.toLowerCase() !== code.toLowerCase()) {
        return n.charAt(0).toUpperCase() + n.slice(1);
      }
    } catch (e) { /* environnement sans Intl.DisplayNames */ }
    return _NOMS_LANGUE[code.split('-')[0]] || code.toUpperCase();
  }

  /**
   * Langues disponibles — des LANGUES, pas des modules.
   *
   * On listait ici { code, nom: e.nom }, où « e.nom » est le nom du MODULE. Le
   * sélecteur affichait donc des noms de packs, et un module de données
   * embarquant quelques libellés anglais pour ses propres écrans apparaissait
   * comme une langue à part entière — à côté d'un vrai pack de traduction, sans
   * rien pour les distinguer.
   *
   * Une langue n'appartient à personne : plusieurs modules peuvent contribuer au
   * même code, et c'est le cas courant — un pack traduit Larka, un module métier
   * traduit ses propres libellés. On regroupe donc par code, on nomme la langue
   * par son nom, et l'on garde la liste des contributeurs pour l'infobulle.
   */
  function languesDisponibles() {
    const par = new Map();
    _chargees.concat(_thematiques.filter(t => t.active !== false)).forEach((e) => {
      Object.keys(e.langues || {}).forEach((code) => {
        const n = Object.keys(e.langues[code] || {}).length;
        if (!n) return;   // une entrée vide n'est pas une traduction
        const acc = par.get(code) || { code, nom: nomLangue(code), libelles: 0, sources: [] };
        acc.libelles += n;
        acc.sources.push(e.nom);
        par.set(code, acc);
      });
    });
    return Array.from(par.values()).sort((a, b) => a.nom.localeCompare(b.nom));
  }

  function rendrePoint(nom, hote, contexte = {}) {
    if (!hote || !_chargees.length) return;

    // Idempotent : une page redessinée rappelle rendrePoint, et l'on ne veut
    // pas dix tuiles identiques.
    hote.querySelectorAll('[data-ext-ancrage]').forEach(el => el.remove());

    _chargees.forEach((ext) => {
      (ext.ancrages || []).forEach((a, index) => {
        if (!a || a.emplacement !== nom) return;

        const boite = document.createElement('div');
        boite.dataset.extAncrage = nom;
        boite.dataset.extension = ext.identifiant;
        // Le libellé de l'onglet voyage avec le conteneur : la page qui accueille
        // l'ancrage sait ainsi comment nommer l'onglet sans connaître le module.
        if (a.type === 'formulaire') {
          boite.dataset.ongletLibelle = `${a.icone || '🧩'} ${a.libelle || ext.nom}`;
        }
        hote.appendChild(boite);

        LarkaDeclaratif.rendreAncrage(boite, ext, index, a, contexte, {
          appel: (action, charge) => appel(ext.identifiant, action, charge),
        });
      });
    });
  }

  return {
    initialiser,
    // Dessine une page d'un module dans un conteneur donné. Sert à l'amorçage :
    // un module ne publie son formulaire qu'en dessinant sa page.
    rendrePage: (identifiant, pageCle, hote) => {
      const ext = _chargees.find(e => e.identifiant === identifiant);
      if (!ext || !ext.declaration || typeof LarkaDeclaratif === 'undefined') return false;
      // ⚠️ MÊME CONVENTION QUE LES AUTRES APPELS DU MODULE.
      // J'avais réécrit l'appel à la main — « apiRequest(action, 'POST', …) » —
      // au lieu de passer par appel(), l'enveloppe que tout le module utilise.
      // Le serveur répondait « Action inconnue : dp_enregistrer » : l'action
      // n'était pas transmise là où la route la cherche.
      LarkaDeclaratif.rendre(hote, {
        appel: (action, charge) => appel(identifiant, action, charge),
      }, ext.declaration, pageCle);
      return true;
    },
    recharger,          // après toute modification de la configuration d'un module
    rendrePoint,
    themesDisponibles,
    catalogue,
    sombreSuspendu: () => !!window._larkaSombreSuspendu,
    thematiques,
    chargerThematiques,
    languesDisponibles,
    nomLangue,
    appliquerHabillage,
    appliquerTraductions,
    injecterMenu,          // à rappeler après toute reconstruction du menu
    appliquerRetouches,
    appel,
    liste: () => _chargees.slice(),
    mode: () => _mode,
    actif: () => _actif,
  };
})();

// Espace où les extensions publient leur module client :
//   window.LarkaExt['acme.suivi-garanties'] = { rendre(conteneur, api) { … } };
window.LarkaExt = window.LarkaExt || {};

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
 * Larka — Page : Aide
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Des fiches « comment faire… ? » propres au rôle de l'utilisateur, lues dans
 * la page — aucun manuel à télécharger — et une barre de recherche qui trouve
 * la bonne fiche à partir de quelques mots, même mal orthographiés.
 *
 * Le serveur (api/routes/aide.php) ne renvoie que les fiches du rôle, dans la
 * langue choisie ; on écarte ici, en plus, celles des écrans qui ne sont pas
 * ouverts à l'utilisateur (module désactivé, onglet non accordé…).
 *
 * POINT D'ENTRÉE : renderAide()
 * ═══════════════════════════════════════════════════════════════════════════════
 */

let _aideFiches = [];        // fiches retenues, avec leur index de recherche
let _aideCats = [];          // catégories présentes
let _aideCat = '';           // catégorie choisie ('' = toutes)
let _aideDonnees = null;
let _aideMinuterie = null;

// Mots vides, ignorés par la recherche (français et anglais).
// (Gabarit littéral : la récolte des libellés ne le prend pas pour un texte à traduire.)
const _AIDE_VIDES = new Set(`le la les l un une des de du d au aux a et ou en dans sur pour par avec sans ce cet cette ces
  mon ma mes ton ta tes son sa ses notre nos votre vos leur leurs je j tu il elle on nous vous ils elles me m te t se s y
  comment faire fait quoi que qu qui quel quelle quels quelles est sont etre avoir ai peut peux puis veux voudrais souhaite
  dois faut ne n pas plus tres bien merci svp
  the an of to in on at for with without and or my your how do does can could i want would like is are what which where
  when me it this that please`.split(/\s+/));

function _aideLangue() {
  try { return ((typeof uiPrefsGet === 'function' ? uiPrefsGet() : JSON.parse(localStorage.getItem('gmao_ui_prefs')) || {}).langue === 'en') ? 'en' : 'fr'; }
  catch (_) { return 'fr'; }
}
function _aideConsoleSA() { return /[?&]superadmin\b/.test(location.search); }

/** Minuscules, sans accents ni ponctuation ; pluriel simple retiré. */
function _aideNorm(s) {
  return String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
    .replace(/œ/g, 'oe').replace(/æ/g, 'ae').replace(/[^a-z0-9]+/g, ' ').trim();
}
function _aideMots(s) {
  return _aideNorm(s).split(' ').filter(Boolean).map(m => (m.length > 3 && /[sx]$/.test(m)) ? m.slice(0, -1) : m);
}

/** Distance d'édition ≤ 1 (une lettre de trop, en moins ou différente). */
function _aideProche(a, b) {
  if (Math.abs(a.length - b.length) > 1) return false;
  let i = 0, j = 0, ecarts = 0;
  while (i < a.length && j < b.length) {
    if (a[i] === b[j]) { i++; j++; continue; }
    if (++ecarts > 1) return false;
    if (a.length > b.length) i++; else if (b.length > a.length) j++; else { i++; j++; }
  }
  return ecarts + (a.length - i) + (b.length - j) <= 1;
}

/** 1 = mot exact, 0.8 = début de mot, 0.5 = une faute de frappe (5 lettres et plus). */
function _aideScoreMot(q, mots) {
  let s = 0;
  for (const m of mots) {
    if (m === q) return 1;
    if (q.length >= 2 && m.startsWith(q)) s = Math.max(s, 0.8);
    else if (s < 0.5 && q.length >= 5 && _aideProche(q, m)) s = 0.5;
  }
  return s;
}

function _aideChercher(requete) {
  const termes = _aideMots(requete).filter(m => !_AIDE_VIDES.has(m));
  if (!termes.length) return null;
  const notes = [];
  for (const f of _aideFiches) {
    let score = 0, trouves = 0;
    for (const q of termes) {
      const s = Math.max(_aideScoreMot(q, f._idx.t) * 6, _aideScoreMot(q, f._idx.m) * 4,
                         _aideScoreMot(q, f._idx.c) * 2, _aideScoreMot(q, f._idx.b));
      if (s > 0) { trouves++; score += s; }
    }
    if (trouves) notes.push({ f, score, part: trouves / termes.length });
  }
  const complets = notes.filter(n => n.part === 1).sort((a, b) => b.score - a.score);
  if (complets.length) return { fiches: complets.map(n => n.f), approchant: false };
  const proches = notes.filter(n => n.part >= 0.5).sort((a, b) => b.part - a.part || b.score - a.score).slice(0, 8);
  return { fiches: proches.map(n => n.f), approchant: true };
}

/** Écrans ouverts à l'utilisateur : une fiche liée à un autre écran ne le concerne pas. */
function _aidePagesOuvertes() {
  if (_aideConsoleSA()) return new Set(['superadmin', 'superadmin_branding', 'superadmin_rgpd', 'journal']);
  const role = (typeof App !== 'undefined' && App.currentUser) ? App.currentUser.Role : '';
  const off = new Set((typeof App !== 'undefined' && App._disabledModules) || []);
  const p = new Set();
  if (role === 'Demandeur') {
    ['demandes', 'historique', 'mobilite_carbone'].forEach(x => p.add(x));
    if (App._inventaireAgentActif) p.add('inventaire_agent');
    if (App._urgencesActif) p.add('urgences');
    if (App._plansDemandeurActif) p.add('annuaire');
    (App._demandeurLectureModules || []).forEach(x => { if (!off.has(x)) p.add(x); });
    return p;
  }
  const gest = role === 'Admin' || role === 'Gestionnaire';
  (typeof UI_NAV_ITEMS !== 'undefined' ? UI_NAV_ITEMS : []).forEach(n => {
    if (off.has(n.page)) return;
    if (n.page === 'urgences' && !gest && !App._urgencesActif) return;
    p.add(n.page);
  });
  if (p.has('plans')) { p.add('annuaire'); if (!gest) p.delete('plans'); }
  p.add('scan');
  if (gest) { p.add('configuration'); p.add('extensions'); }
  return p;
}

const _AIDE_ACTIONS = {
  profil:        () => typeof openMyProfile === 'function' ? openMyProfile : null,
  interface:     () => typeof openUiPrefsPanel === 'function' ? openUiPrefsPanel : null,
  notifications: () => (typeof toggleNotifPanel === 'function' && document.getElementById('notifBellWrap')?.style.display !== 'none') ? toggleNotifPanel : null,
  rgpd:          () => typeof LarkaRgpd !== 'undefined' ? () => LarkaRgpd.afficher() : null,
  assistant:     () => (typeof toggleAssistant === 'function' && document.getElementById('assistantBubble'))
                       ? () => { if (typeof _assistantOpen === 'undefined' || !_assistantOpen) toggleAssistant(); } : null,
  sa_comptes:    () => typeof saShowAllAccounts === 'function' ? saShowAllAccounts : null,
  sa_monitoring: () => typeof saSuperAdminLogs === 'function' ? saSuperAdminLogs : null,
  sa_serveur:    () => typeof saShowServerConfig === 'function' ? saShowServerConfig : null,
  sa_mdp:        () => typeof saChangePasswordModal === 'function' ? saChangePasswordModal : null,
};

function _aideCondition(si) {
  if (si === 'assistant') return !!document.getElementById('assistantBubble');
  if (si === 'consultation') return ((typeof App !== 'undefined' && App._demandeurLectureModules) || []).length > 0;
  return true;
}

// ── Rendu (DOM uniquement : aucun texte n'est injecté en HTML) ──────────────

function _aideEl(tag, cls, texte) {
  const e = document.createElement(tag);
  if (cls) e.className = cls;
  if (texte !== undefined && texte !== null) e.textContent = texte;
  return e;
}

/** Texte avec « **gras** » et « `code` ». */
function _aideRiche(parent, texte) {
  String(texte || '').split(/(\*\*[^*]+\*\*|`[^`]+`)/).forEach(seg => {
    if (!seg) return;
    if (seg.startsWith('**')) parent.appendChild(_aideEl('strong', null, seg.slice(2, -2)));
    else if (seg.startsWith('`')) parent.appendChild(_aideEl('code', null, seg.slice(1, -1)));
    else parent.appendChild(document.createTextNode(seg));
  });
  return parent;
}

function _aideCorps(f) {
  const c = _aideEl('div', 'aide-fiche-corps');
  if (f.texte) c.appendChild(_aideRiche(_aideEl('p'), f.texte));
  if ((f.etapes || []).length) {
    const ol = _aideEl('ol', 'aide-etapes');
    f.etapes.forEach(x => ol.appendChild(_aideRiche(_aideEl('li'), x)));
    c.appendChild(ol);
  }
  if ((f.tableau || []).length) {
    const t = _aideEl('table', 'aide-table');
    f.tableau.forEach(([k, v]) => {
      const tr = _aideEl('tr');
      tr.appendChild(_aideEl('th', null, k));
      tr.appendChild(_aideEl('td', null, v));
      t.appendChild(tr);
    });
    c.appendChild(t);
  }
  if (f.rapport) {
    const r = f.rapport;
    const etat = r.ok ? (r.avertissements ? 'avert' : 'ok') : 'bloquant';
    c.appendChild(_aideEl('p', `aide-bilan aide-${etat}`, r.bilan));
    const ul = _aideEl('ul', 'aide-prerequis');
    const icone = { ok: '✓', avertissement: '⚠️', bloquant: '✗' };
    (r.points || []).forEach(x => {
      const li = _aideEl('li', 'aide-' + x.statut);
      li.appendChild(_aideEl('span', null, icone[x.statut] || '·'));
      const d = _aideEl('div');
      d.appendChild(_aideEl('strong', null, x.libelle));
      if (x.detail) d.appendChild(document.createTextNode(' — ' + x.detail));
      if (x.conseil) d.appendChild(_aideEl('div', 'aide-conseil', '→ ' + x.conseil));
      li.appendChild(d);
      ul.appendChild(li);
    });
    c.appendChild(ul);
  }
  if (f.code) {
    const bloc = _aideEl('div', 'aide-code');
    bloc.appendChild(_aideEl('pre', 'aide-cmd', f.code));
    const b = _aideEl('button', 'btn btn-sm aide-copier', 'Copier');
    b.type = 'button';
    b.onclick = () => {
      const fin = () => { b.textContent = 'Copié ✓'; setTimeout(() => { b.textContent = 'Copier'; }, 1500); };
      try { navigator.clipboard.writeText(f.code).then(fin, () => {}); } catch (_) { /* presse-papiers indisponible */ }
    };
    bloc.appendChild(b);
    c.appendChild(bloc);
  }
  if (f.note) c.appendChild(_aideRiche(_aideEl('p', 'aide-note'), f.note));
  const ouvrir = f.page ? () => navigate(f.page) : (f.action ? (_AIDE_ACTIONS[f.action] || (() => null))() : null);
  if (ouvrir && f.bouton) {
    const b = _aideEl('button', 'btn btn-primary btn-sm aide-ouvrir', f.bouton + ' →');
    b.type = 'button';
    b.onclick = () => ouvrir();
    c.appendChild(b);
  }
  return c;
}

function _aideFiche(f, ouverte, avecCat) {
  const cat = _aideCats.find(x => x.id === f.cat) || {};
  const a = _aideEl('article', 'aide-fiche');
  a.dataset.id = f.id;
  const t = _aideEl('button', 'aide-fiche-titre');
  t.type = 'button';
  t.setAttribute('aria-expanded', ouverte ? 'true' : 'false');
  // L'icône et l'étiquette de la catégorie ne servent que dans une liste de résultats ;
  // en parcourant les rubriques, le titre de la rubrique les donne déjà.
  if (avecCat) t.appendChild(_aideEl('span', 'aide-fiche-icone', cat.icone || '•'));
  t.appendChild(_aideEl('span', 'aide-fiche-nom', f.titre));
  if (avecCat) t.appendChild(_aideEl('span', 'aide-fiche-cat', cat.titre || ''));
  t.appendChild(_aideEl('span', 'aide-chevron', '›'));
  a.appendChild(t);
  let corps = null;
  const basculer = (ouvrir) => {
    if (ouvrir && !corps) { corps = _aideCorps(f); a.appendChild(corps); _aideSurligner(corps); }
    if (corps) corps.hidden = !ouvrir;
    t.setAttribute('aria-expanded', ouvrir ? 'true' : 'false');
    a.classList.toggle('ouverte', ouvrir);
  };
  t.onclick = () => basculer(t.getAttribute('aria-expanded') !== 'true');
  if (ouverte) basculer(true);
  return a;
}

/** Surligne les mots cherchés (sans tenir compte des accents ni de la casse). */
function _aideSurligner(racine) {
  const champ = document.getElementById('aideRecherche');
  const termes = champ ? _aideNorm(champ.value).split(' ').filter(m => m.length >= 2 && !_AIDE_VIDES.has(m)) : [];
  if (!termes.length) return;
  const classes = { a: '[aàâäá]', c: '[cç]', e: '[eéèêë]', i: '[iîïí]', o: '[oôöó]', u: '[uùûüú]', y: '[yÿ]', n: '[nñ]' };
  const motif = termes.map(m => m.replace(/[^a-z0-9]/g, '').split('').map(ch => classes[ch] || ch).join('')).filter(Boolean).join('|');
  if (!motif) return;
  const re = new RegExp('(^|[^\\p{L}\\p{N}])(' + motif + ')', 'giu');
  // Ni dans les boutons d'action ni dans les commandes : on n'y surligne rien.
  const marcheur = document.createTreeWalker(racine, NodeFilter.SHOW_TEXT, {
    acceptNode: n => n.parentElement && n.parentElement.closest('.aide-ouvrir, .aide-copier, pre') ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT,
  });
  const noeuds = [];
  while (marcheur.nextNode()) noeuds.push(marcheur.currentNode);
  noeuds.forEach(n => {
    const s = n.nodeValue;
    re.lastIndex = 0;
    if (!re.test(s)) return;
    re.lastIndex = 0;
    const frag = document.createDocumentFragment();
    let pos = 0, m;
    while ((m = re.exec(s)) !== null) {
      const debut = m.index + m[1].length;
      if (debut > pos) frag.appendChild(document.createTextNode(s.slice(pos, debut)));
      frag.appendChild(_aideEl('mark', null, m[2]));
      pos = debut + m[2].length;
      if (m[0].length === 0) re.lastIndex++;
    }
    if (pos < s.length) frag.appendChild(document.createTextNode(s.slice(pos)));
    n.parentNode.replaceChild(frag, n);
  });
}

function _aideAfficher() {
  const zone = document.getElementById('aideResultats');
  if (!zone) return;
  const champ = document.getElementById('aideRecherche');
  const requete = champ ? champ.value : '';
  const eff = document.querySelector('.aide-effacer');
  if (eff) eff.hidden = !requete;
  document.querySelectorAll('.aide-chip').forEach(c => c.classList.toggle('active', c.dataset.cat === _aideCat));
  zone.textContent = '';
  const dansCat = f => !_aideCat || f.cat === _aideCat;
  const r = _aideChercher(requete);

  if (!r) {
    _aideCats.filter(c => !_aideCat || c.id === _aideCat).forEach(c => {
      const liste = _aideFiches.filter(f => f.cat === c.id);
      if (!liste.length) return;
      const s = _aideEl('section', 'aide-cat');
      const h = _aideEl('h3');
      h.appendChild(_aideEl('span', 'aide-cat-icone', c.icone));
      h.appendChild(_aideEl('span', null, c.titre));
      s.appendChild(h);
      liste.forEach(f => s.appendChild(_aideFiche(f, false, false)));
      zone.appendChild(s);
    });
  } else {
    const liste = r.fiches.filter(dansCat);
    if (liste.length) {
      const compte = _aideEl('div', 'aide-compte');
      if (r.approchant) compte.appendChild(_aideEl('span', null, 'Résultats approchants'));
      else {
        compte.appendChild(_aideEl('span', null, String(liste.length)));
        compte.appendChild(document.createTextNode(' '));
        compte.appendChild(_aideEl('span', null, liste.length > 1 ? 'résultats' : 'résultat'));
      }
      zone.appendChild(compte);
      const s = _aideEl('section', 'aide-cat');
      liste.forEach((f, i) => s.appendChild(_aideFiche(f, i === 0 && !r.approchant, !_aideCat)));
      zone.appendChild(s);
      zone.querySelectorAll('.aide-fiche-nom').forEach(_aideSurligner);
    } else {
      const v = _aideEl('div', 'aide-vide');
      v.appendChild(_aideEl('p', 'aide-vide-titre', 'Aucune fiche ne correspond à votre recherche.'));
      v.appendChild(_aideEl('p', null, "Essayez d'autres mots, ou parcourez les rubriques."));
      zone.appendChild(v);
    }
  }
  zone.appendChild(_aidePied(requete));
}

/** « Vous ne trouvez pas ? » : assistant (s'il est actif), suggestion, administrateur. */
function _aidePied(requete) {
  const p = _aideEl('div', 'aide-pied');
  p.appendChild(_aideEl('strong', null, 'Vous ne trouvez pas ce que vous cherchez ?'));
  const actions = _aideEl('div', 'aide-actions');
  const assistant = _AIDE_ACTIONS.assistant();
  if (assistant && !_aideConsoleSA()) {
    const b = _aideEl('button', 'btn btn-sm', "🤖 Demander à l'assistant");
    b.type = 'button';
    b.onclick = () => {
      assistant();
      const champ = document.getElementById('assistantInput');
      if (champ && requete) champ.value = requete;
    };
    actions.appendChild(b);
  }
  if (_aideDonnees && _aideDonnees.feedback && typeof openFeedbackModal === 'function') {
    const b = _aideEl('button', 'btn btn-sm', '💬 Un problème ? Une suggestion ?');
    b.type = 'button';
    b.onclick = () => openFeedbackModal();
    actions.appendChild(b);
  }
  if (actions.children.length) p.appendChild(actions);
  if (_aideDonnees && _aideDonnees.pied) p.appendChild(_aideEl('p', 'aide-note', _aideDonnees.pied));
  return p;
}

async function renderAide() {
  const main = document.getElementById('mainContent');
  main.textContent = '';
  main.appendChild(_aideEl('div', 'aide-chargement', 'Chargement…'));
  let d;
  try {
    const url = 'api/index.php?action=aide&langue=' + _aideLangue() + (_aideConsoleSA() ? '&console=1' : '');
    const r = await fetch(url, { credentials: 'include' });
    const j = await r.json();
    if (!j.success) throw new Error(j.error || 'Erreur');
    d = j.data;
  } catch (e) {
    main.textContent = '';
    const err = _aideEl('div', 'aide-chargement aide-erreur');
    err.appendChild(_aideEl('div', null, 'Aide indisponible'));
    err.appendChild(_aideEl('div', 'aide-note', e.message));
    main.appendChild(err);
    return;
  }
  // L'utilisateur est peut-être déjà ailleurs : ne pas écraser sa page.
  if (typeof App !== 'undefined' && App.currentPage && App.currentPage !== 'aide') return;

  _aideDonnees = d;
  _aideCat = '';
  const pages = _aidePagesOuvertes();
  _aideFiches = (d.fiches || []).filter(f => (!f.page || pages.has(f.page)) && _aideCondition(f.si));
  _aideCats = (d.categories || []).filter(c => _aideFiches.some(f => f.cat === c.id));
  _aideFiches.forEach(f => {
    const cat = _aideCats.find(c => c.id === f.cat) || {};
    f._idx = {
      t: _aideMots(f.titre), m: _aideMots(f.mots), c: _aideMots(cat.titre),
      b: _aideMots([f.texte, ...(f.etapes || []), f.note, f.code].filter(Boolean).join(' ')),
    };
  });

  const page = _aideEl('div', 'aide-page');

  // En-tête : profil, version, liens
  const tete = _aideEl('div', 'aide-tete');
  const g = _aideEl('div');
  g.appendChild(_aideEl('h2', null, '❓ Aide'));
  const sous = _aideEl('div', 'aide-sous');
  sous.appendChild(_aideEl('span', null, d.role || ''));
  if (d.version) sous.appendChild(document.createTextNode(' · Larka ' + d.version));
  g.appendChild(sous);
  tete.appendChild(g);
  const act = _aideEl('div', 'aide-actions');
  if (typeof LarkaRgpd !== 'undefined') {
    const b = _aideEl('button', 'btn btn-sm', '🛡️ Protection des données');
    b.type = 'button';
    b.onclick = () => LarkaRgpd.afficher();
    act.appendChild(b);
  }
  tete.appendChild(act);
  page.appendChild(tete);

  // Recherche
  const rech = _aideEl('div', 'aide-recherche');
  rech.appendChild(_aideEl('span', 'aide-loupe', '🔍'));
  const champ = _aideEl('input');
  champ.type = 'search';
  champ.id = 'aideRecherche';
  champ.autocomplete = 'off';
  champ.spellcheck = false;
  champ.placeholder = 'Que voulez-vous faire ? Par exemple : créer une demande, changer mon mot de passe…';
  champ.setAttribute('aria-label', "Rechercher dans l'aide");
  champ.oninput = () => { clearTimeout(_aideMinuterie); _aideMinuterie = setTimeout(_aideAfficher, 80); };
  champ.onkeydown = (e) => {
    if (e.key === 'Escape') { champ.value = ''; _aideAfficher(); }
    if (e.key === 'Enter') {
      e.preventDefault();
      const premiere = document.querySelector('#aideResultats .aide-fiche-titre');
      if (premiere) { if (premiere.getAttribute('aria-expanded') !== 'true') premiere.click(); premiere.scrollIntoView({ block: 'nearest' }); }
    }
  };
  rech.appendChild(champ);
  const eff = _aideEl('button', 'aide-effacer', '✕');
  eff.type = 'button';
  eff.title = 'Effacer';
  eff.setAttribute('aria-label', 'Effacer');
  eff.hidden = true;
  eff.onclick = () => { champ.value = ''; champ.focus(); _aideAfficher(); };
  rech.appendChild(eff);
  page.appendChild(rech);

  // Catégories
  if (_aideCats.length > 1) {
    const chips = _aideEl('div', 'aide-chips');
    const chip = (id, texte) => {
      const b = _aideEl('button', 'aide-chip', texte);
      b.type = 'button';
      b.dataset.cat = id;
      b.onclick = () => { _aideCat = (_aideCat === id) ? '' : id; _aideAfficher(); };
      return b;
    };
    chips.appendChild(chip('', 'Tout'));
    _aideCats.forEach(c => chips.appendChild(chip(c.id, c.icone + ' ' + c.titre)));
    page.appendChild(chips);
  }

  page.appendChild(_aideEl('div', null)).id = 'aideResultats';
  main.textContent = '';
  main.appendChild(page);
  _aideAfficher();
  // Sur un ordinateur, la recherche est prête à recevoir la frappe.
  if (window.matchMedia && window.matchMedia('(pointer: fine)').matches) champ.focus();
}

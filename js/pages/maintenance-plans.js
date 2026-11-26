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
 * Larka — Pages : Préventif et Contrôle réglementaire (menu Maintenance)
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Un PLAN prévoit plusieurs passages du même genre dans l'année (« entretien
 * CTA, 4 fois par an », « vérification ascenseur, 2 fois par an »). Chaque
 * passage est une intervention ordinaire : il s'ouvre, se prend en charge, se
 * clôture et s'archive avec la fiche d'intervention (editInterv, chargée avec
 * cette page). Le plan porte ce qui est commun et le prix, de trois façons :
 *   passage : un prix pour chaque passage ;
 *   reparti : un prix unique, partagé à parts égales entre les passages ;
 *   forfait : un prix unique porté par le plan, passages à 0 €.
 *
 * Les interventions de ce type sans plan (créées une par une, ou avant les
 * plans) restent visibles sous « Hors plan ».
 *
 * POINTS D'ENTRÉE : renderPreventif(), renderControles()
 * API : plans_maintenance (GET ?nature=…, POST, PUT &id=, DELETE &id=)
 * ═══════════════════════════════════════════════════════════════════════════════
 */

const PM_NATURES = {
  'Préventive': {
    page: 'preventif', icone: '', titre: 'Préventif', unPlan: 'un plan préventif',
    exemple: 'Entretien CTA',
  },
  'Contrôle réglementaire': {
    page: 'controles', icone: '', titre: 'Contrôle réglementaire', unPlan: 'un plan de contrôle',
    exemple: 'Vérification ascenseur',
  },
};
const PM_PERIODICITES = [
  ['Mensuelle', 12], ['Bimestrielle', 6], ['Trimestrielle', 4], ['Semestrielle', 2], ['Annuelle', 1], ['Personnalisée', 0],
];
const PM_MODES = {
  passage: { libelle: 'Un prix par passage', aide: 'Chaque passage a son prix.' },
  reparti: { libelle: 'Prix unique, réparti', aide: 'Un prix pour l\'année, partagé à parts égales entre les passages (budget et statistiques par passage).' },
  forfait: { libelle: 'Prix unique, au forfait', aide: 'Un prix pour l\'année, porté par le plan ; les passages restent à 0 €.' },
};
const PM_FAITS = ['Réalisée', 'Validée', 'Archivée'];
const PM_FIGES = ['En cours', 'Réalisée', 'Validée', 'Archivée'];

let _pmEtat = { nature: 'Préventive', plans: [], horsPlan: [], biens: [], equipements: [], contrats: [] };
let _pmForm = { dates: [] };     // passages en cours d'édition : [{id, date, statut}]

const _pmEur  = v => new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(parseFloat(v) || 0);
const _pmDate = d => d ? new Date(d + (String(d).length === 10 ? 'T00:00:00' : '')).toLocaleDateString('fr-FR') : '—';
const _pmJour = () => new Date().toISOString().slice(0, 10);

function renderPreventif() { return _pmRender('Préventive'); }
function renderControles() { return _pmRender('Contrôle réglementaire'); }

async function _pmRender(nature) {
  _pmEtat.nature = nature;
  const cfg = PM_NATURES[nature];
  document.getElementById('topbarActions').innerHTML = '';
  const c = document.getElementById('mainContent');
  showLoading(c);
  try {
    const [plans, interventions, biens, equipements, contrats] = await Promise.all([
      apiRequest('plans_maintenance&nature=' + encodeURIComponent(nature)),
      InterventionsApi.getAll().catch(() => []),
      BiensApi.getAll().catch(() => []),
      EquipementsApi.getAll().catch(() => []),
      ContratsApi.getAll().catch(() => []),
    ]);
    _pmEtat.plans = Array.isArray(plans) ? plans : [];
    _pmEtat.horsPlan = (interventions || []).filter(i => i.Type === nature && !i.PlanId);
    _pmEtat.biens = biens || []; _pmEtat.equipements = equipements || []; _pmEtat.contrats = contrats || [];
    if (!_pmEtat.annee) _pmEtat.annee = new Date().getFullYear();

    const annees = new Set([new Date().getFullYear(), new Date().getFullYear() + 1, _pmEtat.annee]);
    _pmEtat.plans.forEach(p => { if (p.Annee) annees.add(parseInt(p.Annee)); });
    const optsAnnees = [...annees].sort((a, b) => b - a)
      .map(a => `<option value="${a}" ${a === _pmEtat.annee ? 'selected' : ''}>${a}</option>`).join('');

    c.innerHTML = `
    <div class="card" style="padding:0;margin-bottom:16px">
      <div class="search-bar" style="gap:8px;flex-wrap:wrap">
        <div class="search-input-wrap" style="flex:1 1 260px;min-width:220px;max-width:420px">
          <span class="search-icon">${lk('search')}</span>
          <input class="search-input" type="text" id="pmRecherche" placeholder="Rechercher un plan (titre, n°, lieu, bien, prestataire…)"
            value="${escHtml(_pmEtat.recherche || '')}" oninput="_pmEtat.recherche=this.value;_pmEtat.plansPage=1;_pmPeindre()">
        </div>
        <label style="font-size:13px;color:var(--gray-text);display:flex;align-items:center;gap:6px">Année
          <select class="form-control" style="font-size:13px;width:auto;padding:6px 10px" onchange="_pmEtat.annee=parseInt(this.value);_pmPeindre()">${optsAnnees}
            <option value="0" ${_pmEtat.annee === 0 ? 'selected' : ''}>Toutes</option>
          </select>
        </label>
        ${_pmSelectLieu(_pmEtat.plans.map(_pmLieuPlan), _pmEtat.lieu, "_pmEtat.lieu=this.value;_pmEtat.plansPage=1;_pmPeindre()")}
        <label style="font-size:13px;display:flex;align-items:center;gap:6px;cursor:pointer">
          <input type="checkbox" ${_pmEtat.voirClos ? 'checked' : ''} onchange="_pmEtat.voirClos=this.checked;_pmPeindre()"> Plans clos
        </label>
        ${_pmSelectLignes('plans', '_pmPlansParPage', 25)}
        <div style="flex:1"></div>
        ${canEdit() ? `<button class="btn btn-ghost" onclick="openImportInterventions('${nature === 'Préventive' ? 'preventif' : 'controles'}')" title="Importer un historique de passages depuis un fichier Excel ou CSV (ils iront dans « Hors plan »)">${lk('download')} Importer</button>
        <button class="btn btn-ghost" onclick="editInterv(0, { type: '${nature.replace(/'/g, "\\'")}' })" title="Une intervention seule, sans plan">＋ Passage ponctuel</button>
        <button class="btn btn-primary" onclick="_pmEditer(0)">＋ Nouveau plan</button>` : ''}
      </div>
    </div>
    <div id="pmCorps"></div>`;
    _pmPeindre();
  } catch (e) { c.innerHTML = errorHtml(e.message); }
}

function _pmFiltres() {
  // Même recherche que le hors plan : mots, accents ignorés, « exact », -exclu, une faute tolérée.
  const req = _pmRequete(_pmEtat.recherche);
  return _pmEtat.plans.filter(p => {
    if (_pmEtat.annee && parseInt(p.Annee) !== _pmEtat.annee) return false;
    if (!_pmEtat.voirClos && p.Statut === 'Clos') return false;
    if (_pmEtat.lieu && _pmSansAccent(_pmLieuPlan(p)) !== _pmEtat.lieu) return false;
    return _pmCorrespond(req, _pmSansAccent([p.Titre, p.Numero, p.Description, _pmLieuPlan(p), p.BienNumero, p.BienLabel, p.ContratSociete,
      p.SocieteManuelle, p.Periodicite].join(' ')));
  });
}

function _pmPeindre() {
  const el = document.getElementById('pmCorps');
  if (!el) return;
  const cfg = PM_NATURES[_pmEtat.nature];
  const plans = _pmFiltres();
  const tous = plans.flatMap(p => p.Passages || []);
  const faits = tous.filter(x => PM_FAITS.includes(x.Statut)).length;
  const budget = plans.reduce((s, p) => s + (parseFloat(p.CoutTotal) || 0), 0);
  const aVenir = tous.filter(x => !PM_FIGES.includes(x.Statut) && x.DateIntervention >= _pmJour())
    .sort((a, b) => String(a.DateIntervention).localeCompare(String(b.DateIntervention)))[0];
  const enRetard = tous.filter(x => x.Statut === 'Planifiée' && x.DateIntervention && x.DateIntervention < _pmJour()).length;

  const tuile = (v, l, coul) => `<div class="card" style="padding:12px 16px;flex:1;min-width:150px">
      <div style="font-size:20px;font-weight:700;color:${coul || 'var(--text)'}">${v}</div>
      <div style="font-size:12px;color:var(--gray-text)">${l}</div></div>`;
  let html = `<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">
    ${tuile(plans.length, plans.length > 1 ? 'plans' : 'plan')}
    ${tuile(`${faits} / ${tous.length}`, 'passages faits', 'var(--teal)')}
    ${tuile(_pmEur(budget), _pmEtat.annee ? `budget ${_pmEtat.annee}` : 'budget', 'var(--blue)')}
    ${tuile(aVenir ? _pmDate(aVenir.DateIntervention) : '—', 'prochain passage')}
    ${enRetard ? tuile(enRetard, enRetard > 1 ? 'passages en retard' : 'passage en retard', 'var(--red)') : ''}
  </div>`;

  if (!plans.length) {
    html += `<div class="card" style="padding:36px;text-align:center;color:var(--gray-text)">
      Aucun plan${_pmEtat.annee ? ' en ' + _pmEtat.annee : ''}.
      ${canEdit() ? `<div style="margin-top:12px"><button class="btn btn-primary" onclick="_pmEditer(0)">＋ Créer ${escHtml(cfg.unPlan)}</button></div>` : ''}
    </div>`;
  } else {
    const dp = _pmDecouper(plans, 'plansPage', 'plans', 25);
    const nbTousPlans = _pmEtat.plans.length;
    html += `<div style="display:flex;flex-direction:column;gap:10px">${dp.lignes.map(_pmCarte).join('')}</div>`;
    html += `<div class="card" style="padding:0;margin-top:10px">${_pmPager(plans.length, nbTousPlans, dp, '_pmPlansAllerPage')}</div>`;
  }
  html += _pmHorsPlan();
  el.innerHTML = html;
  _pmHpPeindre();
}

function _pmLibelleCible(p) {
  if (p.BienId && p.BienNumero) return `${lk('building-complex')} ${escHtml(p.BienNumero)}${p.BienLabel ? ' — ' + escHtml(p.BienLabel) : ''}`;
  const eqId = String(p.EquipementsIds || '').split(',').filter(Boolean)[0];
  if (eqId) {
    const eq = _pmEtat.equipements.find(e => String(e.Id) === eqId);
    if (eq) return `${lk('settings')} ${escHtml(eq.Numero || '')}${typeof _assetLabel === 'function' && _assetLabel(eq) ? ' — ' + escHtml(_assetLabel(eq)) : ''}`;
    return (lk('settings') + ' Équipement');
  }
  return '';
}

function _pmLibellePrix(p) {
  const n = (p.Passages || []).length;
  if (p.ModePrix === 'forfait') return `${lk('euro')} ${_pmEur(p.PrixGlobal)} HT au forfait`;
  if (p.ModePrix === 'reparti') return `${lk('euro')} ${_pmEur(p.PrixGlobal)} HT réparti sur ${n} passage${n > 1 ? 's' : ''}`;
  return `${lk('euro')} ${_pmEur(p.PrixPassage)} HT par passage`;
}

function _pmCouleurPassage(x) {
  if (PM_FAITS.includes(x.Statut)) return 'teal';
  if (x.Statut === 'En cours') return 'orange';
  if (x.DateIntervention && x.DateIntervention < _pmJour()) return 'red';
  return 'blue';
}

function _pmCarte(p) {
  const pas = p.Passages || [];
  const faits = pas.filter(x => PM_FAITS.includes(x.Statut)).length;
  const pct = pas.length ? Math.round(faits * 100 / pas.length) : 0;
  const societe = p.ContratSociete ? `${lk('clipboard-list')} ${escHtml(p.ContratSociete)}${p.ContratNumero ? ' (' + escHtml(p.ContratNumero) + ')' : ''}`
                : (p.SocieteManuelle ? `${lk('factory')} ${escHtml(p.SocieteManuelle)}` : '');
  const puces = pas.map(x => {
    const coul = _pmCouleurPassage(x);
    const retard = coul === 'red';
    const titre = `${x.Numero || ''} — ${x.Statut}${retard ? ' (en retard)' : ''}${parseFloat(x.MontantHT) ? ' — ' + _pmEur(x.MontantHT) + ' HT' : ''}`;
    return `<button type="button" class="badge badge-${coul}" style="border:0;cursor:${canEdit() ? 'pointer' : 'default'};font-size:12px;padding:4px 9px"
        title="${escHtml(titre)}" ${canEdit() ? `onclick="editInterv(${parseInt(x.Id)})"` : ''}>${PM_FAITS.includes(x.Statut) ? '✓ ' : (retard ? (lk('triangle-exclamation-point', 'warn') + ' ') : '')}${_pmDate(x.DateIntervention)}</button>`;
  }).join(' ');
  return `
  <div class="card" style="padding:14px 18px${p.Statut === 'Clos' ? ';opacity:.7' : ''}">
    <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start">
      <div style="flex:1;min-width:0">
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:4px">
          <code style="font-size:12px;color:var(--blue);background:var(--gray-bg);padding:2px 6px;border-radius:4px">${escHtml(p.Numero || '')}</code>
          <span style="font-size:15px;font-weight:600">${escHtml(p.Titre || '')}</span>
          <span class="badge badge-gray">${escHtml(String(p.Annee || ''))}</span>
          ${p.Statut === 'Clos' ? '<span class="badge badge-gray">Clos</span>' : ''}
        </div>
        <div style="font-size:12px;color:var(--gray-text);display:flex;gap:14px;flex-wrap:wrap;margin-bottom:8px">
          ${_pmLieuPlan(p) ? `<span style="color:var(--text);font-weight:600">${lk('map-pin')} ${escHtml(_pmLieuPlan(p))}</span>` : ''}
          ${_pmLibelleCible(p) ? `<span>${_pmLibelleCible(p)}</span>` : ''}
          ${societe ? `<span>${societe}</span>` : ''}
          ${p.Periodicite && p.Periodicite !== 'Personnalisée' ? `<span>${lk('repeat')} ${escHtml(p.Periodicite)}</span>` : ''}
          <span style="color:var(--teal);font-weight:600">${_pmLibellePrix(p)}</span>
          <span style="color:var(--blue);font-weight:600" title="Coût de l'année">Σ ${_pmEur(p.CoutTotal)}</span>
          ${parseInt(p.NbDocuments) ? `<span title="Documents du plan (Modifier pour les voir)">${lk('paperclip')} ${parseInt(p.NbDocuments)}</span>` : ''}
        </div>
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
          <div style="flex:0 0 160px;height:6px;background:var(--gray-bg);border-radius:3px;overflow:hidden">
            <div style="width:${pct}%;height:100%;background:var(--teal)"></div></div>
          <span style="font-size:12px;color:var(--gray-text)">${faits} / ${pas.length} passage${pas.length > 1 ? 's' : ''} fait${faits > 1 ? 's' : ''}</span>
        </div>
        <div style="display:flex;gap:6px;flex-wrap:wrap">${puces || '<span style="font-size:12px;color:var(--gray-text)">Aucun passage.</span>'}</div>
      </div>
      <div style="display:flex;gap:6px;flex-shrink:0">
        ${canEdit() ? `<button class="icon-btn" onclick="_pmEditer(${parseInt(p.Id)})" title="Modifier le plan">${lk('pencil')}</button>
        <button class="icon-btn" onclick="_pmEditer(${parseInt(p.Id)}, true)" title="Reconduire l'année suivante">↻</button>` : ''}
        ${canDelete() && canEdit() ? `<button class="icon-btn delete" onclick="_pmSupprimer(${parseInt(p.Id)})" title="Supprimer le plan">${lk('trash')}</button>` : ''}
      </div>
    </div>
  </div>`;
}

/*
 * « HORS PLAN » : les interventions de ce type qui ne sont rattachées à aucun
 * plan — passages ponctuels, historique importé (un planning de contrôles,
 * des années de préventif). Ce peut être un millier de lignes : la liste a sa
 * propre recherche et ses filtres, indépendants de ceux des plans (l'année des
 * plans vaut l'année en cours par défaut, elle masquerait l'historique).
 * Seul le tableau est repeint à la frappe : le champ garde le focus.
 *
 * Deux vues :
 *   - Passages   : une ligne par intervention, triable par colonne ;
 *   - Par matériel : une ligne par matériel (même description), avec son
 *     dernier passage fait et le prochain prévu — « quand l'ascenseur de
 *     Bordeaux a-t-il été vérifié pour la dernière fois ? ».
 */
// ── Pages : numéros, aller à, nombre par page ─────────────────────────────────
//
// Mille passages importés ne se lisent pas en « Afficher plus » : on va à une
// page précise, on choisit combien en voir. Le nombre par page est retenu sur
// ce poste, pour chaque liste et chaque écran (Préventif, Contrôle réglementaire),
// comme pour les autres listes de Larka (App.getRowsPerPage, gmao_rows_<clé>).

function _pmCleListe(liste) { return (_pmEtat.nature === 'Préventive' ? 'preventif' : 'controles') + '_' + liste; }

/** Nombre par page de cette liste : un entier, ou 'all'. */
function _pmParPage(liste, defaut = 50) {
  return (typeof App !== 'undefined' && App.getRowsPerPage) ? App.getRowsPerPage(_pmCleListe(liste), defaut) : defaut;
}

/** Découpe une liste : { lignes, page, pages, debut } (page ramenée dans les bornes). */
function _pmDecouper(liste, cle, nomListe, defaut) {
  const pp = _pmParPage(nomListe, defaut);
  const pages = pp === 'all' ? 1 : Math.max(1, Math.ceil(liste.length / pp));
  const page = Math.min(Math.max(1, parseInt(_pmEtat[cle], 10) || 1), pages);
  _pmEtat[cle] = page;
  const debut = pp === 'all' ? 0 : (page - 1) * pp;
  return { lignes: pp === 'all' ? liste : liste.slice(debut, debut + pp), page, pages, debut, pp };
}

/**
 * Barre de pages : LA MÊME que les autres listes de Larka (renderTable, js/ui.js) —
 * « 1 007 résultats sur 1 318 (311 masqués par les filtres) — Affichage 151-175 »
 * et « ← Page 7 / 41 → ». Le nombre de lignes se choisit, comme ailleurs, dans la
 * barre de recherche (_pmSelectLignes).
 */
function _pmPager(total, totalTout, d, fnPage) {
  if (!total) return '';
  const { page, pages, debut, pp } = d;
  const fin = pp === 'all' ? total : Math.min(total, debut + pp);
  const diff = totalTout - total;
  const diffLabel = diff > 0 ? ` <span style="color:var(--gray-text);font-weight:400">(${diff} masqué${diff > 1 ? 's' : ''} par les filtres)</span>` : '';
  const prevStyle = page > 1 ? '' : 'opacity:.45;pointer-events:none;';
  const nextStyle = page < pages ? '' : 'opacity:.45;pointer-events:none;';
  return `<div class="pagination">
    <span><span style="font-weight:600">${total}</span> <span>${total > 1 ? 'résultats' : 'résultat'}</span> <span>sur</span> ${totalTout}${diffLabel} — <span>Affichage</span> ${debut + 1}-${fin}</span>
    ${pages > 1 ? `
      <div style="display:flex;align-items:center;gap:8px">
        <button class="btn btn-ghost btn-sm" style="${prevStyle}" onclick="${fnPage}(${page - 1})">←</button>
        <span style="font-size:12px;color:var(--gray-text)">Page ${page} / ${pages}</span>
        <button class="btn btn-ghost btn-sm" style="${nextStyle}" onclick="${fnPage}(${page + 1})">→</button>
      </div>` : ''}
  </div>`;
}

/** Le choix « 25 lignes », tel qu'il figure dans la barre de recherche des autres listes. */
function _pmSelectLignes(liste, fn, defaut) {
  const pp = _pmParPage(liste, defaut);
  return `<select class="form-control" style="font-size:13px;width:auto;padding:6px 10px" onchange="${fn}(this.value)" title="Lignes par page">
    ${[10, 25, 50, 100, 200].map(n => `<option value="${n}" ${pp === n ? 'selected' : ''}>${n} lignes</option>`).join('')}
    <option value="all" ${pp === 'all' ? 'selected' : ''}>Tout</option>
  </select>`;
}

function _pmHpAllerPage(p) {
  _pmEtat.hpPage = p;
  _pmHpPeindre();
  const el = document.getElementById('pmHpRecherche');
  if (el) el.closest('.card').scrollIntoView({ block: 'start', behavior: 'smooth' });
}
function _pmHpParPage(v) {
  _pmStockerParPage('horsplan', v);
  _pmEtat.hpPage = 1;
  _pmHpPeindre();
}
function _pmPlansAllerPage(p) { _pmEtat.plansPage = p; _pmPeindre(); }
function _pmPlansParPage(v) { _pmStockerParPage('plans', v); _pmEtat.plansPage = 1; _pmPeindre(); }

/** Même stockage que App.setRowsPerPage, sans repeindre tout l'écran (ni recharger les données). */
function _pmStockerParPage(liste, v) {
  const k = 'gmao_rows_' + _pmCleListe(liste);
  try {
    if (String(v) === 'all') localStorage.setItem(k, 'all');
    else if (parseInt(v, 10) > 0) localStorage.setItem(k, String(parseInt(v, 10)));
  } catch (e) { /* stockage indisponible : le choix vaut pour cet affichage seulement */ }
}


// ── Recherche : commune aux plans et au hors plan ────────────────────────────
//
// Ce qu'on tape dans une GMAO, ce sont des bouts de libellés saisis par
// d'autres, avec leurs fautes : « extincteus », « veritas siege ». D'où :
//   - tous les mots doivent se trouver, dans n'importe quel champ, accents et
//     majuscules ignorés ;
//   - « entre guillemets » : l'expression exacte ;
//   - -mot : exclut (« extincteur -auto ») ;
//   - une faute de frappe est tolérée sur les mots de 5 lettres ou plus
//     (une lettre en trop, en moins, changée ou deux lettres inversées).
const _pmSansAccent = s => String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');

/** Texte saisi → { inclus: [{ t, exact }], exclus: [t] }. */
function _pmRequete(saisie) {
  const r = { inclus: [], exclus: [] };
  const brut = _pmSansAccent(saisie);
  for (const m of brut.matchAll(/(-?)"([^"]+)"|(-?)(\S+)/g)) {
    const moins = m[1] || m[3];
    // Ponctuation collée au mot (« bordeaux, ») retirée ; l'intérieur reste (« fire&co »).
    const t = m[2] ? m[2].trim() : m[4].replace(/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/gu, '');
    if (!t || t === '-') continue;
    if (moins) r.exclus.push(t); else r.inclus.push({ t, exact: !!m[2] });
  }
  return r;
}

/** Au plus une faute (Damerau) entre a et b, b étant un mot du texte. */
function _pmUneFaute(a, b) {
  if (Math.abs(a.length - b.length) > 1) return false;
  let i = 0;
  while (i < a.length && i < b.length && a[i] === b[i]) i++;
  if (i === a.length && i === b.length) return true;
  if (a.slice(i + 1) === b.slice(i + 1)) return true;                 // substitution
  if (a.slice(i + 1) === b.slice(i)) return true;                     // lettre en trop
  if (a.slice(i) === b.slice(i + 1)) return true;                     // lettre en moins
  return a[i] === b[i + 1] && a[i + 1] === b[i] && a.slice(i + 2) === b.slice(i + 2);   // inversion
}

/** Le texte (déjà sans accents) répond-il à la requête ? */
function _pmCorrespond(req, texte) {
  if (req.exclus.some(t => texte.includes(t))) return false;
  let mots = null;
  return req.inclus.every(({ t, exact }) => {
    if (texte.includes(t)) return true;
    if (exact || t.length < 5) return false;
    mots = mots || texte.split(/[^\p{L}\p{N}]+/u);
    // Le mot tapé peut être le début d'un mot du texte (« extinct ») : on compare aussi au préfixe.
    return mots.some(m => m.length >= 4 && (_pmUneFaute(t, m) || (m.length > t.length && _pmUneFaute(t, m.slice(0, t.length)))));
  });
}

/** Met en évidence les mots trouvés (sur le texte échappé, accents compris). */
function _pmSurligner(texte, req) {
  const brut = String(texte || '');
  const sans = _pmSansAccent(brut);
  if (sans.length !== brut.length || !req.inclus.length) return escHtml(brut);   // ligatures : pas de surlignage
  const zones = [];
  req.inclus.forEach(({ t }) => { let k = sans.indexOf(t); while (k >= 0) { zones.push([k, k + t.length]); k = sans.indexOf(t, k + t.length); } });
  if (!zones.length) return escHtml(brut);
  zones.sort((a, b) => a[0] - b[0]);
  let out = '', pos = 0;
  for (const [d, f] of zones) {
    if (d < pos) continue;
    out += escHtml(brut.slice(pos, d)) + '<mark style="background:#fef08a;padding:0 1px;border-radius:2px">' + escHtml(brut.slice(d, f)) + '</mark>';
    pos = f;
  }
  return out + escHtml(brut.slice(pos));
}

// ── Lieu / bâtiment ──────────────────────────────────────────────────────────
//
// Le lieu d'un passage : le sien (saisi, ou la feuille d'un planning importé),
// sinon celui de son plan, sinon le bâtiment de son bien ou de ses équipements
// (lieuIntervention, js/ui.js). Celui d'un plan : le sien, sinon son bien.

function _pmLieuPlan(p) {
  if (!p) return '';
  if (String(p.Lieu || '').trim()) return String(p.Lieu).trim();
  return lieuIntervention({ BienBatiment: p.BienBatiment, EquipementsIds: p.EquipementsIds }, _pmEtat.equipements);
}

const _pmHpLieu = i => lieuIntervention(i, _pmEtat.equipements);

/** Suggestions du champ Lieu : lieux des plans, des passages, bâtiments des biens et équipements. */
function _pmLieuxConnus() {
  return lieuxConnus(_pmEtat.plans.map(p => p.Lieu), _pmEtat.horsPlan.map(i => i.Lieu),
                     _pmEtat.biens.map(b => b.Batiment), _pmEtat.equipements.map(e => e.Batiment));
}

/** Liste « Tous les lieux » (valeur : le lieu sans accents ni casse). Rien s'il n'y a qu'un lieu. */
function _pmSelectLieu(valeurs, courant, onchange) {
  const m = new Map();
  valeurs.forEach(v => { v = String(v || '').trim(); if (v && !m.has(_pmSansAccent(v))) m.set(_pmSansAccent(v), v); });
  if (m.size < 2 && !courant) return '';
  const opts = [...m.entries()].sort((a, b) => a[1].localeCompare(b[1], 'fr', { numeric: true }))
    .map(([k, v]) => `<option value="${escHtml(k)}" ${k === courant ? 'selected' : ''}>${escHtml(v)}</option>`).join('');
  return `<select class="form-control" style="font-size:13px;width:auto;max-width:220px;padding:6px 10px" title="Lieu / bâtiment" onchange="${onchange}">
    <option value="">Tous les lieux</option>${opts}</select>`;
}

/**
 * Description sans le site collé par un ancien import de planning
 * (« Extincteurs — Bordeaux » quand le lieu est Bordeaux) : le lieu a sa colonne.
 */
function _pmSansLieu(desc, lieu) {
  const d = String(desc || ''), l = String(lieu || '').trim();
  return l && d.endsWith(' — ' + l) ? d.slice(0, -(l.length + 3)) : d;
}

// ── Hors plan ────────────────────────────────────────────────────────────────

const _pmHpDate = i => String(i.DateRealisation || i.DateIntervention || '').slice(0, 10);
const _pmHpPresta = i => String(i.ContratSociete || i.SocieteManuelle || '').trim();
const _pmHpFait = i => PM_FAITS.includes(i.Statut);

/** Équipements et bien rattachés, en clair : on les cherche aussi. */
function _pmHpCibles(i) {
  const ids = String(i.EquipementsIds || '').split(',').filter(Boolean);
  const eqs = ids.map(id => _pmEtat.equipements.find(e => String(e.Id) === id)).filter(Boolean)
    .map(e => [e.Numero, typeof _assetLabel === 'function' ? _assetLabel(e) : ''].filter(Boolean).join(' '));
  return eqs.concat(i.BienNumero ? [i.BienNumero] : []).join(' · ');
}

function _pmHpTexte(i) {
  if (i._txt === undefined) {
    i._txt = _pmSansAccent([i.Numero, i.Description, _pmHpLieu(i), _pmHpPresta(i), i.BienNumero, _pmHpCibles(i), i.Commentaire,
      i.Statut === 'Validée' ? 'Terminée Validée' : i.Statut, _pmDate(_pmHpDate(i)), _pmHpDate(i)].join(' '));
  }
  return i._txt;
}

function _pmHpFiltres() {
  const req = _pmRequete(_pmEtat.hpRecherche);
  const { hpAnnee: an = '', hpStatut: st = '', hpPresta: pr = '', hpDu: du = '', hpAu: au = '', hpLieu: li = '' } = _pmEtat;
  const liste = _pmEtat.horsPlan.filter(i => {
    const d = _pmHpDate(i);
    if (an && !d.startsWith(an)) return false;
    if (du && (!d || d < du)) return false;
    if (au && (!d || d > au)) return false;
    if (st === '_faits' ? !_pmHpFait(i) : st === '_afaire' ? _pmHpFait(i) : (st && i.Statut !== st)) return false;
    if (pr && _pmSansAccent(_pmHpPresta(i)) !== pr) return false;
    if (li && _pmSansAccent(_pmHpLieu(i)) !== li) return false;
    return _pmCorrespond(req, _pmHpTexte(i));
  });
  const [col, sens] = (_pmEtat.hpTri || 'date:desc').split(':');
  const cle = { numero: i => i.Numero || '', statut: i => i.Statut || '', description: i => _pmSansAccent(i.Description),
                presta: i => _pmSansAccent(_pmHpPresta(i)), lieu: i => _pmSansAccent(_pmHpLieu(i)),
                date: _pmHpDate, prix: i => parseFloat(i.MontantHT) || 0 }[col] || _pmHpDate;
  return liste.sort((x, y) => {
    const a = cle(x), b = cle(y);
    const c = typeof a === 'number' ? a - b : String(a).localeCompare(String(b), 'fr', { numeric: true });
    return sens === 'asc' ? c : -c;
  });
}

function _pmHpFiltre(cle, v) {
  _pmEtat[cle] = v;
  if (cle === 'hpAnnee' && v) { _pmEtat.hpDu = ''; _pmEtat.hpAu = ''; }
  if ((cle === 'hpDu' || cle === 'hpAu') && v) _pmEtat.hpAnnee = '';
  _pmEtat.hpPage = 1;
  if (cle === 'hpRecherche') _pmHpPeindre(); else _pmPeindre();
}

function _pmHpTrier(col) {
  const [c, s] = (_pmEtat.hpTri || 'date:desc').split(':');
  _pmEtat.hpTri = col + ':' + (c === col ? (s === 'asc' ? 'desc' : 'asc') : (col === 'date' || col === 'prix' ? 'desc' : 'asc'));
  _pmHpPeindre();
}

function _pmHpEffacer() {
  Object.assign(_pmEtat, { hpRecherche: '', hpAnnee: '', hpStatut: '', hpPresta: '', hpDu: '', hpAu: '', hpLieu: '', hpPage: 1 });
  _pmPeindre();
}

/** Vue « par matériel » : clic sur un matériel → ses passages. */
function _pmHpVoirMateriel(k) {
  const g = (_pmEtat._hpGroupes || [])[k];
  if (!g) return;
  _pmEtat.hpRecherche = '"' + g.description + '"';
  _pmEtat.hpLieu = g.lieu ? _pmSansAccent(g.lieu) : '';
  _pmEtat.hpVue = 'passages'; _pmEtat.hpPage = 1;
  _pmPeindre();
}

function _pmHorsPlan() {
  if (!_pmEtat.horsPlan.length) return '';
  const annees = [...new Set(_pmEtat.horsPlan.map(i => _pmHpDate(i).slice(0, 4)).filter(Boolean))].sort().reverse();
  const statuts = [...new Set(_pmEtat.horsPlan.map(i => i.Statut).filter(Boolean))];
  // « BUREAU VERITAS » et « Bureau Veritas » : un seul prestataire dans la liste.
  const prestasM = new Map();
  _pmEtat.horsPlan.forEach(i => { const v = _pmHpPresta(i); if (v && !prestasM.has(_pmSansAccent(v))) prestasM.set(_pmSansAccent(v), v); });
  const prestas = [...prestasM.entries()].sort((a, b) => a[1].localeCompare(b[1], 'fr'));
  const sel = (v, x) => (v === x ? 'selected' : '');
  const e = _pmEtat, vue = e.hpVue || 'passages';
  const filtre = 'style="font-size:13px;width:auto;max-width:220px;padding:6px 10px"';
  const actifs = [e.hpRecherche, e.hpAnnee, e.hpStatut, e.hpPresta, e.hpDu, e.hpAu, e.hpLieu].filter(Boolean).length;
  const onglet = (v, l) => `<button type="button" class="btn btn-sm ${vue === v ? 'btn-primary' : 'btn-ghost'}" onclick="_pmEtat.hpVue='${v}';_pmEtat.hpPage=1;_pmHpPeindre();this.parentNode.querySelectorAll('button').forEach(b=>b.className='btn btn-sm btn-ghost');this.className='btn btn-sm btn-primary'">${l}</button>`;
  return `
  <div class="card" style="margin-top:16px">
    <div class="card-header" style="flex-wrap:wrap;gap:8px">
      <div class="card-title">Hors plan</div>
      <span style="font-size:12px;color:var(--gray-text)">passages sans plan : créés un par un, ou historique importé</span>
      <div style="flex:1"></div>
      <div style="display:flex;gap:4px">${onglet('passages', lk('rows-3') + ' Passages')}${onglet('materiels', lk('group') + ' Par matériel')}</div>
    </div>
    <div class="search-bar" style="gap:8px;flex-wrap:wrap;border-top:1px solid var(--gray-border)">
      <div class="search-input-wrap" style="flex:1 1 300px;min-width:240px">
        <span class="search-icon">${lk('search')}</span>
        <input class="search-input" type="text" id="pmHpRecherche" placeholder="Rechercher : matériel, lieu, prestataire, équipement, n°, commentaire…"
          title="Tous les mots doivent se trouver, accents ignorés, une faute de frappe tolérée.&#10;« entre guillemets » : expression exacte — -mot : exclure (extincteur -auto)"
          value="${escHtml(e.hpRecherche || '')}" oninput="_pmHpFiltre('hpRecherche', this.value)">
      </div>
      ${_pmSelectLieu(_pmEtat.horsPlan.map(_pmHpLieu), e.hpLieu, "_pmHpFiltre('hpLieu', this.value)")}
      <select class="form-control" ${filtre} title="Année (réalisation, sinon prévue)" onchange="_pmHpFiltre('hpAnnee', this.value)">
        <option value="">Toutes les années</option>${annees.map(a => `<option value="${a}" ${sel(e.hpAnnee, a)}>${a}</option>`).join('')}
      </select>
      <span style="display:flex;align-items:center;gap:4px;font-size:12px;color:var(--gray-text)">du
        <input type="date" class="form-control" style="font-size:12px;width:140px;padding:5px 8px" value="${escHtml(e.hpDu || '')}" onchange="_pmHpFiltre('hpDu', this.value)">
        au <input type="date" class="form-control" style="font-size:12px;width:140px;padding:5px 8px" value="${escHtml(e.hpAu || '')}" onchange="_pmHpFiltre('hpAu', this.value)"></span>
      <select class="form-control" ${filtre} onchange="_pmHpFiltre('hpStatut', this.value)">
        <option value="">Tous les statuts</option>
        <option value="_faits" ${sel(e.hpStatut, '_faits')}>Faits (réalisés, terminés)</option>
        <option value="_afaire" ${sel(e.hpStatut, '_afaire')}>À faire (planifiés, en cours)</option>
        ${statuts.map(x => `<option value="${escHtml(x)}" ${sel(e.hpStatut, x)}>${escHtml(x === 'Validée' ? 'Terminée' : x)}</option>`).join('')}
      </select>
      ${prestas.length > 1 ? `<select class="form-control" ${filtre} onchange="_pmHpFiltre('hpPresta', this.value)">
        <option value="">Tous les prestataires</option>${prestas.map(([k, x]) => `<option value="${escHtml(k)}" ${sel(e.hpPresta, k)}>${escHtml(x)}</option>`).join('')}
      </select>` : ''}
      ${_pmSelectLignes('horsplan', '_pmHpParPage', 25)}
      ${actifs ? `<button class="btn btn-ghost btn-sm" onclick="_pmHpEffacer()" title="Effacer la recherche et les filtres">${lk('x')} Effacer</button>` : ''}
      <button class="btn btn-ghost btn-sm" onclick="_pmHpExporter()" title="Les passages affichés (recherche et filtres appliqués), en CSV pour Excel">${lk('download')} CSV</button>
    </div>
    <div id="pmHpCorps"></div>
  </div>`;
}

function _pmHpPeindre() {
  const el = document.getElementById('pmHpCorps');
  if (!el) return;
  const toutes = _pmHpFiltres();
  if (!toutes.length) {
    el.innerHTML = '<div style="padding:24px;text-align:center;color:var(--gray-text);font-size:13px">Aucun passage ne correspond.'
      + ' <button class="btn btn-ghost btn-sm" onclick="_pmHpEffacer()">Effacer les filtres</button></div>';
    return;
  }
  el.innerHTML = (_pmEtat.hpVue === 'materiels' ? _pmHpMateriels(toutes) : _pmHpPassages(toutes));
}

function _pmHpPassages(toutes) {
  const req = _pmRequete(_pmEtat.hpRecherche);
  const d = _pmDecouper(toutes, 'hpPage', 'horsplan', 25);
  const statutCoul = { Planifiée: 'blue', 'En cours': 'orange', 'Réalisée': 'cyan', Validée: 'teal' };
  const [tc, ts] = (_pmEtat.hpTri || 'date:desc').split(':');
  const th = (col, l) => `<th style="cursor:pointer;user-select:none;white-space:nowrap" onclick="_pmHpTrier('${col}')" title="Trier">${l}${tc === col ? (ts === 'asc' ? ' ▲' : ' ▼') : ''}</th>`;
  const total = toutes.reduce((s, i) => s + (parseFloat(i.MontantHT) || 0), 0);
  return `
    <div style="padding:6px 16px;font-size:12px;color:var(--gray-text)">${toutes.length} passage${toutes.length > 1 ? 's' : ''}${toutes.length < _pmEtat.horsPlan.length ? ' sur ' + _pmEtat.horsPlan.length : ''}${total ? ' · ' + _pmEur(total) + ' HT' : ''}</div>
    <div class="table-wrap"><table><thead><tr>${th('numero', 'N°')}${th('statut', 'Statut')}${th('description', 'Description')}${th('lieu', 'Lieu / bâtiment')}${th('presta', 'Prestataire')}${th('date', 'Date')}${th('prix', 'Prix HT')}<th></th></tr></thead><tbody>
    ${d.lignes.map(i => {
      const cible = _pmHpCibles(i);
      const lieu = _pmHpLieu(i);
      const desc = _pmSansLieu(i.Description, lieu);
      return `<tr>
      <td style="font-family:monospace;font-size:12px">${_pmSurligner(i.Numero || '—', req)}</td>
      <td><span class="badge badge-${statutCoul[i.Statut] || 'gray'}">${escHtml(i.Statut === 'Validée' ? 'Terminée' : (i.Statut || ''))}</span></td>
      <td style="max-width:360px" title="${escHtml((i.Description || '') + (i.Commentaire ? '\n' + i.Commentaire : ''))}">
        <div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${_pmSurligner(desc, req)}</div>
        ${cible || i.Commentaire ? `<div style="font-size:11px;color:var(--gray-text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${cible ? lk('settings') + ' ' + _pmSurligner(cible, req) + (i.Commentaire ? ' · ' : '') : ''}${i.Commentaire ? _pmSurligner(i.Commentaire, req) : ''}</div>` : ''}
      </td>
      <td style="font-size:12px;max-width:170px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${escHtml(lieu)}">${lieu ? _pmSurligner(lieu, req) : '<span style="color:var(--gray-text)">—</span>'}</td>
      <td style="font-size:12px;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${_pmHpPresta(i) ? _pmSurligner(_pmHpPresta(i), req) : '—'}</td>
      <td style="font-size:12px;color:var(--gray-text);white-space:nowrap">${_pmDate(_pmHpDate(i))}</td>
      <td>${parseFloat(i.MontantHT) ? _pmEur(i.MontantHT) : '—'}</td>
      <td style="text-align:right">${canEdit() ? `<button class="icon-btn" onclick="editInterv(${parseInt(i.Id)})" title="Ouvrir">${lk('pencil')}</button>` : ''}</td>
    </tr>`;
    }).join('')}
    </tbody></table></div>
    ${_pmPager(toutes.length, _pmEtat.horsPlan.length, d, '_pmHpAllerPage')}`;
}

function _pmHpMateriels(toutes) {
  const req = _pmRequete(_pmEtat.hpRecherche);
  const jour = _pmJour();
  const parDesc = new Map();
  // Un matériel = une description À UN LIEU : les extincteurs de Pau et ceux de Bordeaux sont deux lignes.
  toutes.forEach(i => {
    const lieu = _pmHpLieu(i);
    const desc = _pmSansLieu(String(i.Description || '').trim(), lieu) || '(sans description)';
    const k = desc + '\u0001' + _pmSansAccent(lieu);
    if (!parDesc.has(k)) parDesc.set(k, { description: desc, lieu, l: [] });
    parDesc.get(k).l.push(i);
  });
  const groupes = [...parDesc.values()].map(({ description, lieu, l }) => {
    const faits = l.filter(_pmHpFait).sort((a, b) => _pmHpDate(b).localeCompare(_pmHpDate(a)));
    const prevus = l.filter(i => !_pmHpFait(i) && _pmHpDate(i)).sort((a, b) => _pmHpDate(a).localeCompare(_pmHpDate(b)));
    const dernier = faits[0];
    return { description, lieu, n: l.length, dernier, prochain: prevus[0],
             presta: dernier ? _pmHpPresta(dernier) : _pmHpPresta(l[0]),
             retard: prevus.some(i => _pmHpDate(i) < jour) };
  }).sort((a, b) => a.description.localeCompare(b.description, 'fr', { numeric: true })
                 || a.lieu.localeCompare(b.lieu, 'fr', { numeric: true }));
  _pmEtat._hpGroupes = groupes;
  const d = _pmDecouper(groupes, 'hpPage', 'horsplan', 25);
  // Un passage fait il y a plus de 13 mois : à surveiller pour un contrôle annuel.
  const vieux = new Date(); vieux.setMonth(vieux.getMonth() - 13);
  const seuil = vieux.toISOString().slice(0, 10);
  return `
    <div style="padding:6px 16px;font-size:12px;color:var(--gray-text)">${groupes.length} matériel${groupes.length > 1 ? 's' : ''} · ${toutes.length} passage${toutes.length > 1 ? 's' : ''} — cliquez un matériel pour voir ses passages</div>
    <div class="table-wrap"><table><thead><tr><th>Matériel</th><th>Lieu / bâtiment</th><th>Passages</th><th>Dernier fait</th><th>Prestataire</th><th>Prochain prévu</th></tr></thead><tbody>
    ${d.lignes.map((g, j) => `<tr style="cursor:pointer" onclick="_pmHpVoirMateriel(${d.debut + j})" title="Voir ses passages">
      <td style="max-width:400px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${_pmSurligner(g.description, req)}</td>
      <td style="font-size:12px;max-width:170px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${g.lieu ? _pmSurligner(g.lieu, req) : '<span style="color:var(--gray-text)">—</span>'}</td>
      <td style="font-size:12px">${g.n}</td>
      <td style="font-size:12px;white-space:nowrap;${g.dernier && _pmHpDate(g.dernier) < seuil ? 'color:var(--orange,#d97706)' : ''}"
        ${g.dernier && _pmHpDate(g.dernier) < seuil ? 'title="Plus de 13 mois"' : ''}>${g.dernier ? _pmDate(_pmHpDate(g.dernier)) : '—'}</td>
      <td style="font-size:12px;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${escHtml(g.presta || '—')}</td>
      <td style="font-size:12px;white-space:nowrap;${g.retard ? 'color:var(--red)' : ''}">${g.prochain ? (g.retard ? lk('triangle-exclamation-point', 'warn') + ' ' : '') + _pmDate(_pmHpDate(g.prochain)) : '—'}</td>
    </tr>`).join('')}
    </tbody></table></div>
    ${_pmPager(groupes.length, groupes.length, d, '_pmHpAllerPage')}`;
}

/** Les passages affichés, en CSV (point-virgule, UTF-8 avec BOM : Excel l'ouvre en français). */
function _pmHpExporter() {
  const l = _pmHpFiltres();
  if (!l.length) { toast('Aucun passage à exporter.', 'error'); return; }
  const q = v => {
    let t = String(v == null ? '' : v);
    if (/^[=+\-@\t\r]/.test(t)) t = "'" + t;          // pas de formule exécutée à l'ouverture dans Excel
    return /[;"\n\r]/.test(t) ? '"' + t.replace(/"/g, '""') + '"' : t;
  };
  const lignes = [['N°', 'Statut', 'Description', 'Lieu / bâtiment', 'Équipements / bien', 'Prestataire', 'Date prévue', 'Date de réalisation', 'Prix HT', 'Commentaire']]
    .concat(l.map(i => [i.Numero, i.Statut === 'Validée' ? 'Terminée' : i.Statut, _pmSansLieu(i.Description, _pmHpLieu(i)), _pmHpLieu(i), _pmHpCibles(i), _pmHpPresta(i),
      _pmDate(i.DateIntervention), i.DateRealisation ? _pmDate(i.DateRealisation) : '', parseFloat(i.MontantHT) ? String(i.MontantHT).replace('.', ',') : '', i.Commentaire]));
  const blob = new Blob(['﻿' + lignes.map(r => r.map(q).join(';')).join('\r\n')], { type: 'text/csv;charset=utf-8' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = (_pmEtat.nature === 'Préventive' ? 'preventif' : 'controles') + '-hors-plan-' + _pmJour() + '.csv';
  document.body.appendChild(a); a.click(); a.remove();
  setTimeout(() => URL.revokeObjectURL(a.href), 1000);
}

// ── Formulaire du plan ───────────────────────────────────────────────────────

function _pmDecalerAn(date, n) {
  const [a, m, j] = String(date).split('-').map(Number);
  const d = new Date(Date.UTC(a + n, m - 1, Math.min(j, 28)));
  // Garder le jour quand il existe dans le mois (29-31) : 31/03 → 31/03.
  const fin = new Date(Date.UTC(a + n, m, 0)).getUTCDate();
  d.setUTCDate(Math.min(j, fin));
  return d.toISOString().slice(0, 10);
}

async function _pmEditer(id, reconduire = false) {
  const nature = _pmEtat.nature;
  const cfg = PM_NATURES[nature];
  const source = id ? _pmEtat.plans.find(p => parseInt(p.Id) === id) : null;
  if (id && !source) { toast('Plan introuvable : rechargez la page.', 'error'); return; }
  const neuf = !source || reconduire;
  const p = source ? Object.assign({}, source) : {
    Titre: '', Description: '', Annee: _pmEtat.annee || new Date().getFullYear(), Periodicite: 'Trimestrielle',
    ModePrix: 'passage', PrixPassage: 0, PrixGlobal: 0, DelaiAffichage: 30, Statut: 'Actif', SocieteManuelle: '',
  };
  if (reconduire) {
    p.Annee = (parseInt(source.Annee) || new Date().getFullYear()) + 1;
    p.Statut = 'Actif';
    _pmForm.dates = (source.Passages || []).map(x => ({ id: 0, date: _pmDecalerAn(x.DateIntervention || `${source.Annee}-01-01`, 1), statut: '' }));
  } else if (source) {
    _pmForm.dates = (source.Passages || []).map(x => ({ id: parseInt(x.Id), date: x.DateIntervention || '', statut: x.Statut }));
  } else {
    _pmForm.dates = [];
  }

  const eqId = String(p.EquipementsIds || '').split(',').filter(Boolean)[0];
  const cle = p.BienId ? `bien_${p.BienId}` : (eqId ? `equip_${eqId}` : '');
  const txt = a => (typeof _optAssetText === 'function') ? _optAssetText(a) : (a.Numero || '');
  const optsCible = `<option value="">— Aucun —</option>
    <optgroup label="Biens">${_pmEtat.biens.map(b => `<option value="bien_${b.Id}" ${cle === 'bien_' + b.Id ? 'selected' : ''}>${escHtml(txt(b))}</option>`).join('')}</optgroup>
    <optgroup label="Équipements">${_pmEtat.equipements.map(e => `<option value="equip_${e.Id}" ${cle === 'equip_' + e.Id ? 'selected' : ''}>${escHtml(txt(e))}</option>`).join('')}</optgroup>`;
  const optsContrats = '<option value=""></option>' + _pmEtat.contrats.map(c =>
    `<option value="${c.Id}" ${parseInt(p.ContratId) === parseInt(c.Id) ? 'selected' : ''}>${escHtml(c.Numero || '')} — ${escHtml(c.Societe || '')}</option>`).join('');
  const premier = (_pmForm.dates[0] && _pmForm.dates[0].date) || `${p.Annee}-01-15`;

  const titreModale = reconduire ? `↻ Reconduire en ${p.Annee} — ${source.Titre}` : (neuf ? `${cfg.icone} Nouveau plan — ${cfg.titre}` : `Modifier — ${source.Numero}`);
  openModal(titreModale, `
  <div style="display:flex;flex-direction:column;gap:16px">
    <div>
      <div class="section-label">Le plan</div>
      <div class="form-grid">
        <div class="form-group span-2"><label class="form-label">Titre <span style="color:var(--red)">*</span></label>
          <input class="form-control" id="pm_titre" maxlength="200" value="${escHtml(p.Titre || '')}" placeholder="${escHtml(cfg.exemple)}"></div>
        <div class="form-group span-2"><label class="form-label">Travaux à faire à chaque passage</label>
          <textarea class="form-control" id="pm_description" rows="2" placeholder="Ex : nettoyage des filtres, contrôle des courroies, relevé des températures">${escHtml(p.Description || '')}</textarea></div>
        <div class="form-group"><label class="form-label">Année</label>
          <input class="form-control" type="number" id="pm_annee" min="2000" max="2100" value="${escHtml(String(p.Annee || ''))}"></div>
        <div class="form-group"><label class="form-label">Bien / équipement concerné</label>
          <select class="form-control" id="pm_cible">${optsCible}</select></div>
        <div class="form-group"><label class="form-label">Contrat</label>
          <select class="form-control" id="pm_contrat">${optsContrats}</select></div>
        <div class="form-group"><label class="form-label">Prestataire (sans contrat)</label>
          <input class="form-control" id="pm_societe" value="${escHtml(p.SocieteManuelle || '')}" placeholder="Société qui intervient"></div>
        <div class="form-group span-2"><label class="form-label">Lieu / bâtiment</label>
          <input class="form-control" id="pm_lieu" list="pm_lieuListe" maxlength="120" value="${escHtml(p.Lieu || '')}"
            placeholder="${escHtml(p.BienBatiment ? 'Sinon : ' + p.BienBatiment : 'Site, bâtiment… (sinon : celui du bien ou de l\'équipement)')}">
          <datalist id="pm_lieuListe">${_pmLieuxConnus().map(l => `<option value="${escHtml(l)}">`).join('')}</datalist></div>
      </div>
    </div>

    <div>
      <div class="section-label">Les passages</div>
      <div class="form-grid">
        <div class="form-group"><label class="form-label">Fréquence</label>
          <select class="form-control" id="pm_periodicite">${PM_PERIODICITES.map(([l, n]) =>
            `<option value="${l}" ${p.Periodicite === l ? 'selected' : ''}>${l}${n ? ` (${n} par an)` : ''}</option>`).join('')}</select></div>
        <div class="form-group"><label class="form-label">Premier passage</label>
          <div style="display:flex;gap:6px">
            <input class="form-control" type="date" id="pm_premier" value="${escHtml(premier)}">
            <button type="button" class="btn btn-ghost btn-sm" onclick="_pmGenererDates()" title="Remplit la liste d'après la fréquence">${lk('settings')} Générer</button>
          </div></div>
      </div>
      <div id="pm_dates" style="margin-top:6px"></div>
      <button type="button" class="btn btn-ghost btn-sm" style="margin-top:6px" onclick="_pmAjouterDate()">＋ Ajouter un passage</button>
      <div style="font-size:11px;color:var(--gray-text);margin-top:6px">Un passage commencé, réalisé ou terminé ne se déplace ni ne se retire depuis le plan : ouvrez-le pour le modifier.</div>
    </div>

    <div>
      <div class="section-label">Le prix (HT)</div>
      <div style="display:flex;flex-direction:column;gap:6px">
        ${Object.entries(PM_MODES).map(([k, m]) => `
        <label style="display:flex;gap:8px;align-items:flex-start;cursor:pointer;font-size:13px">
          <input type="radio" name="pm_mode" value="${k}" ${p.ModePrix === k ? 'checked' : ''} onchange="_pmMajPrix()" style="margin-top:3px">
          <span><strong>${m.libelle}</strong> <span style="color:var(--gray-text)">— ${m.aide}</span></span>
        </label>`).join('')}
      </div>
      <div class="form-grid" style="margin-top:8px">
        <div class="form-group" id="pm_zonePassage"><label class="form-label">Prix d'un passage (€ HT)</label>
          <input class="form-control" type="number" step="0.01" min="0" id="pm_prixPassage" value="${parseFloat(p.PrixPassage) || ''}" oninput="_pmMajPrix()" placeholder="0.00"></div>
        <div class="form-group" id="pm_zoneGlobal"><label class="form-label">Prix de l'année (€ HT)</label>
          <input class="form-control" type="number" step="0.01" min="0" id="pm_prixGlobal" value="${parseFloat(p.PrixGlobal) || ''}" oninput="_pmMajPrix()" placeholder="0.00"></div>
      </div>
      <div id="pm_apercu" style="font-size:12px;color:var(--teal);font-weight:600;margin-top:2px"></div>
    </div>

    <div>
      <div class="section-label">Documents du plan</div>
      <div style="font-size:11px;color:var(--gray-text);margin-bottom:6px">Contrat, cahier des charges, gamme de maintenance, rapport type… (chaque passage garde aussi ses propres documents, dans sa fiche).</div>
      ${neuf ? docsPanelHtml('PlanMaintenance', 0, 'PlanMaintenance') : docsPanelHtml('PlanMaintenance', id)}
    </div>

    <div>
      <div class="section-label">Suivi</div>
      <div class="form-grid">
        <div class="form-group"><label class="form-label">Tableau de bord : afficher un passage … jours avant</label>
          <input class="form-control" type="number" min="0" max="365" id="pm_delai" value="${parseInt(p.DelaiAffichage) >= 0 ? parseInt(p.DelaiAffichage) : 30}"></div>
        ${neuf ? '' : `<div class="form-group"><label class="form-label">État du plan</label>
          <select class="form-control" id="pm_statut"><option value="Actif" ${p.Statut !== 'Clos' ? 'selected' : ''}>Actif</option><option value="Clos" ${p.Statut === 'Clos' ? 'selected' : ''}>Clos (masqué par défaut)</option></select></div>`}
        <div class="form-group span-2"><label class="form-label">Commentaire</label>
          <textarea class="form-control" id="pm_commentaire" rows="2">${escHtml(p.Commentaire || '')}</textarea></div>
      </div>
    </div>
  </div>`, () => _pmEnregistrer(neuf ? 0 : id, nature), neuf ? 'Créer le plan' : 'Enregistrer');

  _pmPeindreDates();
  _pmMajPrix();
  if (neuf) window._docsPending['PlanMaintenance'] = [];
  setTimeout(() => initDocsPanel('PlanMaintenance', neuf ? 0 : id, 'PlanMaintenance'), 60);
  setTimeout(() => {
    if (window.enhanceSelectTypeahead) enhanceSelectTypeahead('pm_cible', { placeholder: 'Rechercher un bien ou équipement…', allowEmpty: true });
    if (window.enhanceSelectTypeahead) enhanceSelectTypeahead('pm_contrat', { placeholder: 'Rechercher un contrat…', allowEmpty: true });
  }, 120);
}

function _pmLireDates() {
  document.querySelectorAll('#pm_dates input[data-i]').forEach(inp => {
    const i = parseInt(inp.dataset.i);
    if (_pmForm.dates[i] && !PM_FIGES.includes(_pmForm.dates[i].statut)) _pmForm.dates[i].date = inp.value;
  });
}

function _pmPeindreDates() {
  const el = document.getElementById('pm_dates');
  if (!el) return;
  if (!_pmForm.dates.length) {
    el.innerHTML = ('<div style="font-size:12px;color:var(--gray-text)">Aucun passage. Choisissez la fréquence et le premier passage, puis ' + lk('settings') + ' Générer — ou ajoutez les dates une à une.</div>');
    _pmMajPrix();
    return;
  }
  el.innerHTML = `<div style="display:flex;flex-wrap:wrap;gap:6px">` + _pmForm.dates.map((d, i) => {
    const fige = PM_FIGES.includes(d.statut);
    return `<div style="display:flex;align-items:center;gap:4px;border:1px solid var(--gray-border);border-radius:6px;padding:3px 6px">
      <span style="font-size:11px;color:var(--gray-text);min-width:18px">${i + 1}.</span>
      <input class="form-control" type="date" data-i="${i}" value="${escHtml(d.date)}" ${fige ? 'disabled' : ''}
        style="width:140px;font-size:12px;padding:3px 6px" onchange="_pmLireDates();_pmMajPrix()">
      ${fige ? `<span class="badge badge-${PM_FAITS.includes(d.statut) ? 'teal' : 'orange'}" style="font-size:10px">${escHtml(d.statut === 'Validée' ? 'Terminée' : d.statut)}</span>`
             : `<button type="button" class="icon-btn delete" style="padding:2px 4px" onclick="_pmRetirerDate(${i})" title="Retirer ce passage">✕</button>`}
    </div>`;
  }).join('') + `</div>`;
  _pmMajPrix();
}

function _pmGenererDates() {
  _pmLireDates();
  const per = gv('pm_periodicite');
  const n = (PM_PERIODICITES.find(x => x[0] === per) || [0, 0])[1];
  const premier = gv('pm_premier');
  if (!n) { toast('Fréquence personnalisée : ajoutez les passages un à un.', ''); return; }
  if (!/^\d{4}-\d{2}-\d{2}$/.test(premier)) { toast('Indiquez la date du premier passage.', 'error'); return; }
  const fixes = _pmForm.dates.filter(d => PM_FIGES.includes(d.statut));
  const libres = _pmForm.dates.filter(d => !PM_FIGES.includes(d.statut));
  const pas = 12 / n;
  const [a, m, j] = premier.split('-').map(Number);
  const nouvelles = [];
  for (let k = 0; k < n; k++) {
    const mois = m - 1 + Math.round(k * pas);
    const fin = new Date(Date.UTC(a, mois + 1, 0)).getUTCDate();
    nouvelles.push(new Date(Date.UTC(a, mois, Math.min(j, fin))).toISOString().slice(0, 10));
  }
  // Les passages libres existants gardent leur identifiant (déplacés), le reste est créé.
  const dates = nouvelles.filter(d => !fixes.some(f => f.date === d)).slice(0, Math.max(0, n - fixes.length));
  const res = fixes.slice();
  dates.forEach((d, k) => res.push({ id: libres[k] ? libres[k].id : 0, date: d, statut: libres[k] ? libres[k].statut : '' }));
  res.sort((x, y) => String(x.date).localeCompare(String(y.date)));
  _pmForm.dates = res;
  const an = parseInt(gv('pm_annee'));
  if (!an || an !== a) { const el = document.getElementById('pm_annee'); if (el) el.value = a; }
  _pmPeindreDates();
}

function _pmAjouterDate() {
  _pmLireDates();
  const der = _pmForm.dates.map(d => d.date).filter(Boolean).sort().pop();
  const an = parseInt(gv('pm_annee')) || new Date().getFullYear();
  let date = `${an}-01-15`;
  if (der) { const d = new Date(der + 'T00:00:00'); d.setMonth(d.getMonth() + 1); date = d.toISOString().slice(0, 10); }
  _pmForm.dates.push({ id: 0, date, statut: '' });
  _pmPeindreDates();
}

function _pmRetirerDate(i) {
  _pmLireDates();
  _pmForm.dates.splice(i, 1);
  _pmPeindreDates();
}

function _pmModeChoisi() {
  return (document.querySelector('input[name="pm_mode"]:checked') || {}).value || 'passage';
}

function _pmMajPrix() {
  const mode = _pmModeChoisi();
  const zp = document.getElementById('pm_zonePassage'), zg = document.getElementById('pm_zoneGlobal');
  if (zp) zp.style.display = mode === 'passage' ? '' : 'none';
  if (zg) zg.style.display = mode === 'passage' ? 'none' : '';
  const ap = document.getElementById('pm_apercu');
  if (!ap) return;
  const n = _pmForm.dates.length;
  const pp = parseFloat(gv('pm_prixPassage')) || 0, pg = parseFloat(gv('pm_prixGlobal')) || 0;
  if (!n) { ap.textContent = ''; return; }
  if (mode === 'passage') ap.textContent = `→ ${n} passage${n > 1 ? 's' : ''} × ${_pmEur(pp)} = ${_pmEur(pp * n)} HT pour l'année`;
  else if (mode === 'reparti') ap.textContent = `→ ${_pmEur(Math.floor(pg / n * 100) / 100)} HT par passage environ (${n} passage${n > 1 ? 's' : ''})`
    + (_pmForm.dates.some(d => PM_FIGES.includes(d.statut)) ? ' ; les passages commencés ou faits gardent leur prix, le reste se partage' : '');
  else ap.textContent = `→ ${_pmEur(pg)} HT pour l'année, sur le plan ; passages à 0 €`;
}

async function _pmEnregistrer(id, nature) {
  _pmLireDates();
  const titre = gv('pm_titre').trim();
  if (!titre) { toast('Donnez un titre au plan.', 'error'); return; }
  const dates = _pmForm.dates.filter(d => d.date);
  if (!dates.length) { toast('Prévoyez au moins un passage.', 'error'); return; }
  const cible = gv('pm_cible');
  const payload = {
    nature, titre,
    description: gv('pm_description'),
    annee: parseInt(gv('pm_annee')) || new Date().getFullYear(),
    periodicite: gv('pm_periodicite'),
    typeLiaison: cible.startsWith('equip_') ? 'Equipement' : 'Bien',
    bienId: cible.startsWith('bien_') ? parseInt(cible.slice(5)) : null,
    equipementsIds: cible.startsWith('equip_') ? cible.slice(6) : '',
    contratId: gv('pm_contrat') || null,
    societeManuelle: gv('pm_societe'),
    lieu: gv('pm_lieu'),
    modePrix: _pmModeChoisi(),
    prixPassage: parseFloat(gv('pm_prixPassage')) || 0,
    prixGlobal: parseFloat(gv('pm_prixGlobal')) || 0,
    delaiAffichage: parseInt(gv('pm_delai')) || 0,
    statut: document.getElementById('pm_statut') ? gv('pm_statut') : 'Actif',
    commentaire: gv('pm_commentaire'),
    passages: dates.map(d => d.id ? { id: d.id, date: d.date } : { date: d.date }),
  };
  try {
    if (id) await apiRequest('plans_maintenance', 'PUT', payload, id);
    else {
      const r = await apiRequest('plans_maintenance', 'POST', payload);
      if (r && r.id) await uploadPendingDocs('PlanMaintenance', 'PlanMaintenance', r.id);
    }
    toast(id ? 'Plan enregistré.' : `Plan créé : ${dates.length} passage${dates.length > 1 ? 's' : ''} planifié${dates.length > 1 ? 's' : ''}.`, 'success');
    closeModal();
    _pmEtat.annee = payload.annee;
    _pmRender(nature);
  } catch (e) { toast(e.message, 'error'); }
}

function _pmSupprimer(id) {
  const p = _pmEtat.plans.find(x => parseInt(x.Id) === id);
  if (!p) return;
  const pas = p.Passages || [];
  const engages = pas.filter(x => PM_FIGES.includes(x.Statut)).length;
  const aVenir = pas.length - engages;
  openModal('Supprimer le plan', `
    <div style="font-size:13px;line-height:1.6">
      Supprimer <strong>${escHtml(p.Titre || '')}</strong> (${escHtml(p.Numero || '')}) ?
      <ul style="margin:8px 0 0 18px">
        <li>${aVenir} passage${aVenir > 1 ? 's' : ''} à venir ${aVenir > 1 ? 'seront supprimés' : 'sera supprimé'} ;</li>
        <li>${engages} passage${engages > 1 ? 's' : ''} commencé${engages > 1 ? 's' : ''} ou fait${engages > 1 ? 's' : ''} ${engages > 1 ? 'restent' : 'reste'} (historique, factures et budget intacts), sans plan.</li>
      </ul>
      <div style="margin-top:8px;color:var(--gray-text);font-size:12px">Pour seulement arrêter un plan en gardant sa trace, passez-le plutôt à « Clos ».</div>
    </div>`, async () => {
    try {
      const r = await apiRequest('plans_maintenance', 'DELETE', null, id);
      toast(`Plan supprimé (${r && r.supprimes || 0} passage(s) retiré(s)${r && r.gardes ? `, ${r.gardes} gardé(s)` : ''}).`, 'success');
      closeModal();
      _pmRender(_pmEtat.nature);
    } catch (e) { toast(e.message, 'error'); }
  }, 'Supprimer');
  const b = document.getElementById('modalSaveBtn');
  if (b) { b.classList.remove('btn-primary'); b.classList.add('btn-danger'); }
}

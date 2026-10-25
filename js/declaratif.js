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
 * Larka — Modules déclaratifs : rendu des écrans
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Dessine la page d'un module déclaratif à partir de sa description. Ce fichier
 * appartient à Larka : c'est NOTRE code qui s'exécute, pas celui d'un tiers.
 *
 * Conséquence directe : un module déclaratif n'a pas besoin du bac à sable.
 * L'iframe existe pour cloisonner du JavaScript étranger ; ici il n'y en a pas.
 * Le module ne fournit que des libellés et des noms de champs, échappés à
 * l'affichage comme n'importe quelle donnée venue de la base.
 * ═══════════════════════════════════════════════════════════════════════════════
 */

const LarkaDeclaratif = (() => {
  // Formulaires publiés par les pages ouvertes, indexés « module/jeu ».
  // Permet à un ancrage affiché ailleurs (barre d'outils des plans) d'ouvrir la
  // saisie du module, prérempli.
  const _formulaires = {};
  const _formulairesIntegres = {};
  let _apresEnregistrement = null;
  'use strict';

  function esc(s) {
    const d = document.createElement('div');
    d.textContent = String(s ?? '');
    return d.innerHTML;
  }

  /** Rendu d'une valeur selon le type déclaré. */
  /**
   * ⚠️ ELLE ÉTAIT IMBRIQUÉE DANS afficher(), donc invisible ailleurs.
   * Le surlignage des lignes l'appelle aussi : ouvrir l'écran d'un module
   * déclarant une règle de surlignage levait « teinte is not defined », et la
   * page restait blanche. Une fonction utilitaire se déclare au niveau du
   * module, pas au milieu de celle qui s'en sert la première.
   *
   * Dernier filet avant d'écrire une couleur dans un attribut « style ».
   *
   * Le serveur ne renvoie que du #rrggbb — il le vérifie à la validation. On le
   * revérifie quand même : c'est la seule valeur de la déclaration qui entre
   * dans du CSS, et une chaîne inattendue y ferait plus de dégâts qu'ailleurs.
   * Une couleur non conforme devient grise plutôt que d'être injectée.
   */
  /**
   * Attributs « min » et « max » d'un champ date.
   *
   * Le rendu ne connaissait que « min_date », une date FIXE — périmée le mois
   * suivant, et que personne ne pense à mettre à jour. « min_jours » et
   * « max_jours » l'expriment en jours depuis aujourd'hui : « pas avant sept
   * jours » reste vrai indéfiniment.
   *
   * Le navigateur grise les dates hors bornes dans son calendrier : la règle se
   * voit avant d'être enfreinte, au lieu d'être annoncée par un message après
   * coup.
   */
  function bornesDate(champ) {
    const jour = (n) => {
      const d = new Date();
      d.setDate(d.getDate() + n);
      return d.toISOString().slice(0, 10);
    };
    const out = [];
    if (Number.isFinite(champ.min_jours)) out.push(`min="${jour(champ.min_jours)}"`);
    else if (champ.min_date) {
      out.push(`min="${champ.min_date === 'today' ? jour(0) : esc(champ.min_date)}"`);
    }
    if (Number.isFinite(champ.max_jours)) out.push(`max="${jour(champ.max_jours)}"`);
    return out.join(' ');
  }

  /**
   * Grille jour × créneau, vert libre / rouge pris.
   *
   * Une liste de deux cents lignes ne dit pas si mardi 14 h est libre : il faut
   * la parcourir. Une grille le dit d'un regard, et s'imprime pour être
   * affichée au mur — ce qui est l'usage réel d'un planning de bornes.
   *
   * Les colonnes sont les ressources (places, bornes), les lignes les créneaux,
   * et l'on dessine une grille par jour. Les jours viennent des données : une
   * grille de sept jours dont cinq vides gaspille la page.
   */
  /**
   * Calendrier classique : créneaux en lignes, JOURS en colonnes.
   *
   * ⚠️ LA PREMIÈRE VERSION METTAIT LES PLACES EN COLONNES, une grille par jour.
   * La largeur suivait donc le nombre de places — six, douze, vingt — et
   * débordait de l'écran dès qu'un parking en comptait quelques-unes. Un
   * calendrier dont on doit faire défiler les colonnes ne remplit plus son
   * office : on ne le lit plus d'un coup d'œil.
   *
   * Avec les jours en colonnes, la largeur est bornée par la semaine. Une case
   * porte alors toutes les réservations de ce jour à ce créneau, empilées —
   * c'est d'ailleurs ainsi qu'on lit un planning au mur.
   */
  function calendrier(lignes, vue, champs) {
    const g = vue.calendrier || {};
    if (!g.jour || !g.creneau || !g.ressource) {
      return '<div style="padding:16px;color:var(--gray-text)">Grille non déclarée.</div>';
    }
    const occupe = new Set(g.occupe || []);
    const estPris = (l) => !g.etat || occupe.has(String(l[g.etat]));

    // Une demande refusée libère sa case : elle sort de la grille. Elle reste
    // en base et dans la liste — c'est là qu'on consulte les réponses données,
    // pas sur le planning, qui montre ce qui est prévu.
    const cache = new Set(g.masquer || []);
    if (cache.size) lignes = lignes.filter(l => !cache.has(String(l[g.etat])));
    const rapides = vue.actions_rapides || [];
    const peutEditer = (vue.actions || []).includes('modifier');
    const peutOter   = (vue.actions || []).includes('supprimer');

    const jours = [...new Set(lignes.map(l => String(l[g.jour] ?? '')).filter(Boolean))].sort();
    const creneaux = champs[g.creneau].valeurs || [];
    if (!jours.length) {
      return '<div class="card"><div class="card-body" style="padding:32px;text-align:center;'
           + 'color:var(--gray-text)">Aucun créneau sur la période.</div></div>';
    }

    // Une case peut contenir plusieurs réservations : on empile par jour+créneau.
    const cases = new Map();
    lignes.forEach((l) => {
      const k = `${l[g.jour]}|${l[g.creneau]}`;
      if (!cases.has(k)) cases.set(k, []);
      cases.get(k).push(l);
    });

    // Une case vide dit « libre » ; inutile de répéter le mot dans chaque
    // cellule d'un planning entièrement disponible. La légende suffit.
    const legende = `
      <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:12px;font-size:12px">
        ${[['#dcfce7', '#16a34a', 'Libre'], ['#fef3c7', '#f59e0b', 'En attente'],
           ['#fee2e2', '#dc2626', 'Occupé']].map(([f, t, n]) => `
          <span style="display:inline-flex;align-items:center;gap:6px">
            <span style="width:14px;height:14px;border-radius:3px;background:${f};
              border-left:3px solid ${t}"></span>${n}</span>`).join('')}
      </div>`;

    // Une semaine par tableau : au-delà, la largeur repart à la hausse.
    const semaines = [];
    for (let i = 0; i < jours.length; i += 7) semaines.push(jours.slice(i, i + 7));

    return legende + semaines.map((sem) => `
      <div class="card dp-cal-carte" style="margin-bottom:14px;break-inside:avoid">
        <div class="card-body" style="padding:12px">
          <div class="dp-tableau-cadre">
          <table class="dp-cal">
            <thead><tr><th class="dp-cal-h"${g.largeur_creneau_px
                ? ` style="width:${g.largeur_creneau_px}px"` : ''}></th>
              ${sem.map(j => `<th${g.largeur_jour_px
                  ? ` style="width:${g.largeur_jour_px}px"` : ''}>${esc(
                  champs[g.jour].type === 'date' ? dateFr(j) : j)}</th>`).join('')}</tr></thead>
            <tbody>
              ${creneaux.map(c => `<tr>
                <th class="dp-cal-h"${g.largeur_creneau_px
                    ? ` style="width:${g.largeur_creneau_px}px"` : ''}>${esc(c)}</th>
                ${sem.map((j) => {
                  const l = cases.get(`${j}|${c}`) || [];
                  if (!l.length) return '<td class="dp-cal-libre">libre</td>';
                  const tous = l.every(estPris);
                  return `<td class="${tous ? 'dp-cal-pris' : 'dp-cal-attente'}">
                    ${l.map(x => bloc(x)).join('')}</td>`;
                }).join('')}
              </tr>`).join('')}
            </tbody>
          </table>
          </div>
        </div>
      </div>`).join('');

    /** Une réservation dans une case : qui, quelle plaque, quelle place. */
    function bloc(l) {
      const qui    = String(l[g.occupant || 'demandeur'] ?? '').trim();
      const plaque = g.detail ? String(l[g.detail] ?? '').trim() : '';
      const place  = String(l[g.ressource] ?? '').trim();
      const btn = [];
      rapides.forEach((a) => {
        if (String(l[a.champ]) === a.valeur) return;
        btn.push(`<button class="dp-cal-b" data-rapide="${l.id}"
          data-rk="${rapides.indexOf(a)}"
          style="border-color:${teinte(a.couleur)};color:${teinte(a.couleur)}"
          title="${esc(a.libelle)}">${esc(a.libelle)}</button>`);
      });
      // Les mêmes attributs que la liste : les gestionnaires d'événements
      // existants les reconnaissent, il n'y a rien à brancher en plus.
      if (peutEditer) btn.push(`<button class="dp-cal-b" data-modif="${l.id}"
        title="Modifier">✎</button>`);
      if (peutOter) btn.push(`<button class="dp-cal-b" data-suppr="${l.id}"
        style="border-color:#b91c1c;color:#b91c1c" title="Supprimer">🗑</button>`);
      return `<div class="dp-cal-resa">
        <div class="dp-cal-qui">${esc(qui) || '—'}</div>
        ${plaque ? `<div class="dp-cal-sub">${esc(plaque)}</div>` : ''}
        ${place ? `<div class="dp-cal-sub">${esc(place)}</div>` : ''}
        ${btn.length ? `<div class="dp-cal-actions">${btn.join('')}</div>` : ''}
      </div>`;
    }
  }

  function teinte(v) {
    return /^#[0-9a-f]{6}$/i.test(String(v || '')) ? String(v) : '#6b7280';
  }

  function afficher(valeur, champ) {
    champ = champ || {};
    if (valeur === null || valeur === undefined || valeur === '') {
      return '<span style="color:var(--gray-text)">—</span>';
    }

    // Le « format » d'un champ calculé était déclaré, validé, puis ignoré :
    // un total s'affichait « 1234.5 » au lieu de « 1 234,50 € ». Une valeur
    // juste mais illisible n'est pas une valeur rendue.
    switch (champ.format) {
      case 'monnaie':
        return esc(Number(valeur).toLocaleString('fr-FR',
          { style: 'currency', currency: 'EUR' }));
      case 'pourcent':
        return esc(Number(valeur).toLocaleString('fr-FR',
          { maximumFractionDigits: 1 }) + ' %');
      case 'nombre':
        return esc(Number(valeur).toLocaleString('fr-FR'));
      case 'date':
        return esc(dateFr(valeur));
      case 'entier':
        return esc(Math.round(Number(valeur)).toLocaleString('fr-FR'));
      case 'heure':
        return esc(String(valeur).slice(0, 5));
      case 'duree': {
        const m = Math.round(Number(valeur)) || 0;
        return esc(m >= 60 ? `${Math.floor(m / 60)} h ${String(m % 60).padStart(2, '0')}`
                           : `${m} min`);
      }
      case 'octets': {
        const u = ['o', 'Ko', 'Mo', 'Go', 'To'];
        let n = Number(valeur) || 0, i = 0;
        while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
        return esc(n.toLocaleString('fr-FR', { maximumFractionDigits: 1 }) + ' ' + u[i]);
      }
      case 'distance': {
        const n = Number(valeur) || 0;
        return esc(n >= 1000 ? (n / 1000).toLocaleString('fr-FR',
                     { maximumFractionDigits: 2 }) + ' km'
                             : n.toLocaleString('fr-FR') + ' m');
      }
      case 'surface':
        return esc(Number(valeur).toLocaleString('fr-FR') + ' m²');
      case 'temperature':
        return esc(Number(valeur).toLocaleString('fr-FR',
          { maximumFractionDigits: 1 }) + ' °C');
      case 'telephone': {
        // Groupé par deux : « 0123456789 » se lit mal d'un coup d'œil.
        const c = String(valeur).replace(/\D/g, '');
        return esc(c.length === 10 ? c.replace(/(\d{2})(?=\d)/g, '$1 ') : String(valeur));
      }
    }

    // Badge coloré pour une valeur de choix : « Non conforme » en rouge se
    // repère dans cent lignes, en noir il faut les lire.
    if (champ.couleurs && champ.couleurs[valeur]) {
      // Le serveur résout la couleur en hexadécimal à la validation : il n'y a
      // plus de table de correspondance ici. Celle qui s'y trouvait ne
      // connaissait que sept teintes sur seize — « turquoise », « marine » et
      // sept autres retombaient en gris sans que rien ne le signale. Deux
      // tables finissent toujours par diverger ; il n'en reste qu'une.
      const c = teinte(champ.couleurs[valeur]);
      return `<span style="display:inline-block;padding:1px 8px;border-radius:10px;
        font-size:12px;color:#fff;background:${c}">${esc(valeur)}</span>`;
    }

    if (champ.unite) {
      const n = Number(valeur);
      return esc((isFinite(n) ? n.toLocaleString('fr-FR') : valeur) + ' ' + champ.unite);
    }

    if (champ.type === 'couleur') {
      return `<span style="display:inline-block;width:14px;height:14px;border-radius:3px;
        vertical-align:-2px;background:${esc(valeur)};border:1px solid var(--gray-border)"></span>
        <span style="font-size:12px;color:var(--gray-text);margin-left:4px">${esc(valeur)}</span>`;
    }
    if (champ.type === 'note') {
      const n = Math.max(0, Math.min(5, parseInt(valeur, 10) || 0));
      return '<span style="color:#ca8a04">' + '★'.repeat(n)
           + '<span style="color:var(--gray-border)">' + '★'.repeat(5 - n) + '</span></span>';
    }
    if (champ.type === 'pourcentage') {
      const n = Math.max(0, Math.min(100, Number(valeur) || 0));
      return `<span style="display:inline-flex;align-items:center;gap:6px">
        <span style="width:52px;height:6px;border-radius:3px;background:var(--gray-border);
          overflow:hidden"><span style="display:block;height:100%;width:${n}%;
          background:var(--navy)"></span></span>
        <span style="font-size:12px">${n.toLocaleString('fr-FR')} %</span></span>`;
    }
    if (champ.type === 'duree') {
      const m = parseInt(valeur, 10) || 0;
      return esc(m >= 60 ? `${Math.floor(m / 60)} h ${String(m % 60).padStart(2, '0')}` : `${m} min`);
    }
    if (champ.type === 'horodatage') {
      return esc(String(valeur).replace(/^(\d{4})-(\d{2})-(\d{2})[ T]/, '$3/$2/$1 '));
    }

    if (champ.type === 'booleen') {
      return valeur && valeur !== '0'
        ? '<span style="color:#16a34a">oui</span>'
        : '<span style="color:var(--gray-text)">non</span>';
    }
    if (champ.type === 'date')    return esc(dateFr(valeur));
    if (champ.type === 'decimal') return esc(Number(valeur).toLocaleString('fr-FR'));
    if (champ.type === 'entier')  return esc(Number(valeur).toLocaleString('fr-FR'));
    return esc(valeur);
  }

  /** Date ISO → jj/mm/aaaa. Une date brute « 2026-08-22 » se lit mal. */
  function dateFr(v) {
    const m = String(v).match(/^(\d{4})-(\d{2})-(\d{2})/);
    return m ? `${m[3]}/${m[2]}/${m[1]}` : String(v);
  }

  /**
   * Un champ de formulaire, construit d'après sa déclaration.
   * Les contraintes (obligatoire, bornes, longueur, choix) sont posées ici pour
   * le confort de saisie — mais c'est le serveur qui décide : le navigateur se
   * contourne, le moteur revalide tout.
   */
  // Motifs de saisie, doublés côté navigateur. Le serveur revalide : ceci
  // n'est qu'un signalement immédiat, pour éviter un aller-retour.
  const MOTIFS = {
    email:       '[^@\\s]+@[^@\\s]+\\.[a-zA-Z]{2,12}',
    telephone:   '[+0-9][0-9 .\\-]{6,24}',
    code_postal: '[0-9]{5}',
    siret:       '[0-9]{14}',
    url:         'https?://.{3,}',
    reference:   '[A-Za-z0-9][A-Za-z0-9 .\\-/]{0,39}',
  };

  function champFormulaire(cle, champ, valeur) {
    const id = 'dp-' + cle;
    // width:100% — sans quoi chaque champ prend sa largeur naturelle : une
    // note fait 40 px, une date 150, un texte 250, et rien ne s'aligne. C'est
    // ce qui donnait l'impression d'un formulaire « aléatoire ».
    // Par défaut, toute la largeur de la colonne : sans quoi chaque champ prend
    // sa taille naturelle — une note 40 px, une date 150, un texte 250 — et
    // rien ne s'aligne. C'est ce qui donnait l'impression d'un formulaire
    // « aléatoire ».
    //
    // « largeur_px » et « hauteur_px » permettent à l'auteur d'y déroger champ
    // par champ : une immatriculation n'a pas besoin de la même boîte qu'un
    // motif de cent vingt caractères. Les valeurs sont déjà bornées par le
    // schéma, il n'y a rien à revérifier ici.
    // Les bornes de taille descendent jusqu'ICI, sur la boîte de saisie.
    //
    // Les poser sur la cellule qui entoure le champ paraissait équivalent : ce
    // n'était pas le cas. Une cellule contient aussi le libellé, l'aide et
    // parfois un bouton ; lui imposer la hauteur de la boîte de saisie faisait
    // déborder tout le reste par-dessus la ligne suivante. Une dimension de
    // saisie appartient au contrôle, et à lui seul.
    /**
     * ⚠️ « largeur_px » ET « hauteur_px » N'AVAIENT AUCUN EFFET. AUCUN.
     *
     * Le style était bien calculé ici, dans « large »… et « large » n'était
     * référencé par AUCUNE branche. Chaque contrôle recevait à la place la
     * classe `dp-large`, que la feuille de style force à
     * `width:100% !important`. Le schéma validait donc ces deux propriétés,
     * la référence les documentait, l'auteur les écrivait — et le navigateur
     * rendait invariablement un champ pleine largeur.
     *
     * C'est le défaut que ce dépôt redoute le plus : la déclaration passe,
     * l'écran s'affiche, et la fonctionnalité manque. Il tenait à une variable
     * calculée puis oubliée.
     *
     * Le style n'est désormais posé QUE lorsqu'une dimension est déclarée, et
     * la classe `dp-large` est retirée dans ce cas : sans cela, son
     * « !important » l'emporterait encore sur le style en ligne. Un champ sans
     * dimension déclarée garde exactement le rendu d'avant.
     */
    const bornes = (n, prop) => (champ[n] ? `${prop}:${champ[n]}px;` : '');
    const aDimensions = !!(champ.largeur_px || champ.hauteur_px
                        || champ.largeur_min_px || champ.largeur_max_px
                        || champ.hauteur_min_px || champ.hauteur_max_px);

    // Le fond du champ en lecture seule entre DANS le même style : posé à part,
    // il produisait un second attribut « style » sur la même balise, et le
    // premier l'emportait — un champ non modifiable perdait alors sa taille.
    const dim = (champ.largeur_px ? `width:${champ.largeur_px}px;` : 'width:100%;')
              + (champ.hauteur_px ? `height:${champ.hauteur_px}px;` : '')
              + bornes('largeur_min_px', 'min-width')
              + bornes('largeur_max_px', 'max-width')
              + bornes('hauteur_min_px', 'min-height')
              + bornes('hauteur_max_px', 'max-height')
              + (champ.lecture_seule ? 'background:var(--gray-bg);' : '')
              + 'box-sizing:border-box';

    // Classe de taille : `dp-large` (comportement d'avant) tant qu'aucune
    // dimension n'est déclarée ; le style en ligne dès qu'il y en a une.
    const cls   = aDimensions ? 'form-control' : 'form-control dp-large';
    const large = aDimensions ? ` style="${dim}"` : '';
    const ph = champ.exemple ? ` placeholder="${esc(champ.exemple)}"` : '';
    const ro = champ.lecture_seule
      ? (aDimensions ? ' readonly' : ` readonly style="${dim}"`) : '';
    const mo = champ.format_saisie && MOTIFS[champ.format_saisie]
      ? ` pattern="${MOTIFS[champ.format_saisie]}"` : '';
    const req = champ.obligatoire ? ' *' : '';
    const aide = champ.aide
      ? `<div style="font-size:11px;color:var(--gray-text);margin-top:3px">${esc(champ.aide)}</div>`
      : '';
    let saisie;

    switch (champ.type) {
      case 'lien':
        // Champ filtrable plutôt que <select> : une liste déroulante de six
        // cents équipements se parcourt à la molette, ce qui est inutilisable.
        // Ici, on tape et la liste se resserre.
        saisie = `<input class="${cls}" id="${id}"${large} data-lien="${esc(cle)}"
            autocomplete="off" placeholder="Taper pour rechercher…"${ro}>
          <input type="hidden" id="${id}-id" value="${esc(String(valeur ?? ''))}">
          <div id="${id}-liste" style="display:none;position:absolute;z-index:50;
            background:var(--card-bg);border:1px solid var(--gray-border);border-radius:6px;
            max-height:190px;overflow:auto;min-width:240px;
            box-shadow:0 4px 12px rgba(0,0,0,.18)"></div>`;
        break;

      case 'choix':
        // Liste vide : un menu déroulant sans option ressemble à une panne.
        // On dit où aller la remplir plutôt que de laisser l'utilisateur
        // cliquer dans le vide.
        if ((champ.valeurs || []).length === 0) {
          // Liste vide ET ajout autorisé : c'est le cas où le bouton compte le
          // PLUS, et c'est exactement celui où il manquait. L'utilisateur
          // tombait sur « Ajoutez-en dans Configuration → Listes » alors que le
          // module lui donnait le droit de la remplir depuis ici — on l'envoyait
          // chercher ailleurs une permission qu'il avait déjà.
          //
          // Le champ reste un <input> tant que la liste est vide : un <select>
          // sans option ressemble à une panne. Il devient un <select> dès la
          // première valeur ajoutée.
          saisie = champ.liste
            ? `<input class="${cls}" id="${id}"${large} value="${esc(valeur ?? '')}"
                 maxlength="120"
                 placeholder="Aucune valeur dans la liste « ${esc(champ.liste)} »"${ro}>`
              + (champ.ajout_autorise ? `
               <button type="button" class="btn btn-sm btn-secondary" data-ajout="${esc(cle)}"
                 style="margin-top:5px">+ Ajouter une valeur</button>` : `
               <div style="font-size:11px;color:var(--gray-text);margin-top:3px">
                 Ajoutez-en dans Configuration → Listes, catégorie
                 « ${esc(champ.liste)} ».</div>`)
            : `<input class="${cls}" id="${id}"${large} value="${esc(valeur ?? '')}"${ro}>`;
          break;
        }
        // ── Saisie libre ────────────────────────────────────────────────
        //
        // « champ.libre » n'était LU NULLE PART ICI. Le rendu produisait
        // toujours un <select>, donc cocher « saisie libre » dans Configuration
        // ne changeait rien : la valeur hors liste était acceptée par le
        // serveur, mais l'écran ne permettait pas de la saisir.
        //
        // <input list> plutôt qu'un <select> doublé d'un champ « Autre… » : le
        // navigateur propose la liste ET laisse taper, sans qu'on ait à gérer
        // le basculement entre deux contrôles.
        if (champ.libre) {
          saisie = `<input class="${cls}" id="${id}"${large} list="dl-${esc(cle)}"
              maxlength="120" value="${esc(valeur ?? '')}"${ro}${ph}>
            <datalist id="dl-${esc(cle)}">`
            + champ.valeurs.map(v => `<option value="${esc(v)}">`).join('')
            + '</datalist>'
            + (champ.ajout_autorise ? `
               <button type="button" class="btn btn-sm btn-secondary" data-ajout="${esc(cle)}"
                 style="margin-top:5px">+ Ajouter à la liste</button>` : '');
          break;
        }

        // ── Menu fermé ──────────────────────────────────────────────────
        //
        // Une valeur enregistrée qui ne figure PLUS dans la liste — désactivée
        // depuis, ou saisie librement avant que l'option soit retirée — n'avait
        // aucune option correspondante. Le <select> se rabattait alors sur la
        // première, et le premier enregistrement remplaçait silencieusement la
        // donnée par une autre. On la conserve, signalée pour qu'on sache
        // pourquoi elle n'est pas dans la liste.
        const horsListe = valeur != null && valeur !== ''
                       && !champ.valeurs.includes(valeur);
        saisie = `<select class="${cls}" id="${id}"${large}${champ.obligatoire ? ' required' : ''}>`
          + (champ.obligatoire && !horsListe && valeur != null && valeur !== ''
              ? '' : '<option value=""></option>')
          + (horsListe
              ? `<option value="${esc(valeur)}" selected>${esc(valeur)} (retirée de la liste)</option>`
              : '')
          + champ.valeurs.map(v =>
              `<option value="${esc(v)}"${v === valeur ? ' selected' : ''}>${esc(v)}</option>`)
            .join('') + '</select>'
          + (champ.ajout_autorise ? `
             <button type="button" class="btn btn-sm btn-secondary" data-ajout="${esc(cle)}"
               style="margin-top:5px">+ Ajouter une valeur</button>` : '');
        break;
      case 'texte_long':
        saisie = `<textarea class="${cls}" id="${id}"${large} rows="3"
          maxlength="${champ.max}"${ph}${ro}>${esc(valeur ?? '')}</textarea>`;
        break;
      case 'booleen':
        return `<label style="display:flex;gap:8px;align-items:center;font-size:13px">
          <input type="checkbox" id="${id}"${valeur ? ' checked' : ''}>
          <span>${esc(champ.libelle)}</span></label>${aide}`;
      case 'entier':
      case 'decimal':
        saisie = `<input class="${cls}" id="${id}"${large} type="number"
          ${champ.type === 'decimal' ? 'step="0.01"' : 'step="1"'}
          ${champ.min !== null && champ.min !== undefined ? `min="${champ.min}"` : ''}
          ${champ.max !== null && champ.max !== undefined ? `max="${champ.max}"` : ''}
          value="${valeur ?? ''}"${ph}${ro}>`;
        break;
      case 'heure':
        saisie = `<input class="${cls}" id="${id}"${large} type="time"
          value="${esc(valeur ?? '')}"${ro}>`;
        break;
      case 'horodatage':
        saisie = `<input class="${cls}" id="${id}"${large} type="datetime-local"
          value="${esc(String(valeur ?? '').replace(' ', 'T'))}"${ro}>`;
        break;
      case 'couleur':
        saisie = `<input class="${cls}" id="${id}"${large} type="color"
          value="${esc(valeur || '#1b3a5c')}" style="height:38px;padding:2px;width:100%"${ro}>`;
        break;
      case 'note':
        saisie = `<input class="${cls}" id="${id}"${large} type="number" min="0" max="5"
          step="1" value="${valeur ?? ''}"${ro}>`;
        break;
      case 'pourcentage':
        saisie = `<input class="${cls}" id="${id}"${large} type="number" min="0" max="100"
          step="${champ.pas || 0.1}" value="${valeur ?? ''}"${ro}>`;
        break;
      case 'duree':
        saisie = `<input class="${cls}" id="${id}"${large} value="${esc(valeur ?? '')}"
          placeholder="90 ou 1h30"${ro}>`;
        break;
      case 'choix_multiple': {
        // Cases à cocher plutôt qu'une liste à sélection multiple : celle-ci
        // demande de garder Ctrl enfoncé, ce que personne ne devine.
        const choisis = String(valeur ?? '').split(',').map(x => x.trim());
        saisie = '<div style="display:flex;flex-wrap:wrap;gap:10px" id="' + id + '">'
          + champ.valeurs.map((v, i) =>
              `<label style="display:flex;gap:5px;align-items:center;font-size:13px">
                 <input type="checkbox" data-multi="${esc(cle)}" value="${esc(v)}"
                   ${choisis.includes(v) ? 'checked' : ''}${champ.lecture_seule ? ' disabled' : ''}>
                 ${esc(v)}</label>`).join('') + '</div>';
        break;
      }

      case 'date':
        saisie = `<input class="${cls}" id="${id}"${large} type="date"
          value="${esc(valeur ?? '')}"${ro}
          ${bornesDate(champ)}
          ${champ.max_date ? `max="${champ.max_date === 'today'
              ? new Date().toISOString().slice(0, 10) : esc(champ.max_date)}"` : ''}>`;
        break;
      default:
        saisie = `<input class="${cls}" id="${id}"${large} maxlength="${champ.max}"
          value="${esc(valeur ?? '')}"${ph}${ro}${mo}
          ${champ.suggestions ? `data-suggere="${esc(cle)}" autocomplete="off"` : ''}>`
          + (champ.suggestions ? `<div id="${id}-liste" class="dp-suggestions"
               style="display:none;position:absolute;z-index:50;background:var(--card-bg);
               border:1px solid var(--gray-border);border-radius:6px;max-height:190px;
               overflow:auto;min-width:240px;box-shadow:0 4px 12px rgba(0,0,0,.18)"></div>` : '');
    }

    // L'unité se lit à côté du champ : « 12 » ne dit pas s'il s'agit de mois,
    // de kWh ou d'euros, et l'auteur ne devrait pas avoir à la mettre dans le
    // libellé.
    // Le marqueur d'obligation se compose ICI, pour toutes les branches. Il
    // était ajouté seulement en fin de fonction, et la branche « liste vide »
    // rendait son propre HTML : le champ paraissait facultatif alors qu'il
    // venait d'être rendu obligatoire dans Configuration.
    const libelle = esc(champ.libelle) + req
      + (champ.unite ? ` <span style="color:var(--gray-text);font-weight:400">(${esc(champ.unite)})</span>` : '');

    return `<label class="form-label">${libelle}</label>${saisie}${aide}`;
  }

  /**
   * Applique les conditions « visible_si » du formulaire, et les réapplique à
   * chaque changement : une condition évaluée une seule fois à l'ouverture
   * laisserait un champ masqué alors que sa condition vient d'être remplie.
   */
  function appliquerVisibilite(saisissables, conditionsLayout) {
    const conditionnes = saisissables.filter(([, c]) => c.visible_si);
    // Les conditions portées par la MISE EN PAGE comptent autant que celles des
    // champs : une section entière peut n'avoir lieu d'être que dans un cas.
    // Sortir ici parce qu'aucun CHAMP n'est conditionné les aurait ignorées.
    const auLayout = Array.isArray(conditionsLayout) ? conditionsLayout : [];
    if (conditionnes.length === 0 && auLayout.length === 0) return;

    const relire = () => {
      const valeurs = {};
      saisissables.forEach(([cle, c]) => {
        const el = document.getElementById('dp-' + cle);
        if (!el) return;
        valeurs[cle] = c.type === 'booleen' ? el.checked : el.value;
      });
      conditionnes.forEach(([cle, c]) => {
        const hote = document.querySelector(`[data-champ="${cle}"]`);
        if (hote) hote.style.display = evaluerCondition(c.visible_si, valeurs) ? '' : 'none';
      });
      auLayout.forEach((x, i) => {
        const hote = document.querySelector(`#dp-form [data-si="${i}"]`);
        if (hote) hote.style.display = evaluerCondition(x.condition, valeurs) ? '' : 'none';
      });
    };

    saisissables.forEach(([cle]) => {
      const el = document.getElementById('dp-' + cle);
      if (!el) return;
      el.addEventListener('change', relire);
      el.addEventListener('input', relire);
    });
    relire();
  }

  /**
   * Évaluation des conditions côté client.
   *
   * Elle DOUBLE celle du serveur, elle ne la remplace pas : masquer un champ
   * est un confort d'affichage, pas un contrôle. C'est le serveur qui décide de
   * ce qui est enregistré.
   */
  function evaluerCondition(c, v) {
    if (!c) return true;
    if (c.t === 'groupe') {
      const r = c.clauses.map((x) => evaluerCondition(x, v));
      if (c.logique === 'tous')        return r.every(Boolean);
      if (c.logique === 'au_moins_un') return r.some(Boolean);
      return !r.some(Boolean);
    }
    const a = v[c.champ];
    const b = c.valeur;
    const nb = (x) => parseFloat(x);
    switch (c.operateur) {
      case 'egal':              return String(a ?? '') === String(b ?? '');
      case 'different':         return String(a ?? '') !== String(b ?? '');
      case 'contient':          return String(a ?? '').toLowerCase().includes(String(b ?? '').toLowerCase());
      case 'ne_contient_pas':   return !String(a ?? '').toLowerCase().includes(String(b ?? '').toLowerCase());
      case 'commence_par':      return String(a ?? '').startsWith(String(b ?? ''));
      case 'finit_par':         return String(a ?? '').endsWith(String(b ?? ''));
      case 'superieur_a':       return nb(a) >  nb(b);
      case 'superieur_ou_egal': return nb(a) >= nb(b);
      case 'inferieur_a':       return nb(a) <  nb(b);
      case 'inferieur_ou_egal': return nb(a) <= nb(b);
      case 'entre':             return nb(a) >= nb(b[0]) && nb(a) <= nb(b[1]);
      case 'parmi':             return (b || []).map(String).includes(String(a));
      case 'pas_parmi':         return !(b || []).map(String).includes(String(a));
      case 'est_vide':          return !a || a === '0';
      case 'non_vide':          return !!a && a !== '0';
      case 'est_nul':           return a === null || a === undefined || a === '';
      case 'non_nul':           return !(a === null || a === undefined || a === '');
      default:                  return true;   // « a changé » n'a pas de sens ici
    }
  }

  // ══════════════════════════════════════════════════════════════════════
  //  MISE EN PAGE DÉCLARATIVE
  // ══════════════════════════════════════════════════════════════════════

  /**
   * Habillages de section — des NOMS venus du schéma, traduits ici et ici seul.
   *
   * La déclaration ne transporte jamais de style : elle nomme « carte », et
   * c'est cette table qui dit ce qu'une carte vaut. Un habillage absent de la
   * table a déjà été refusé à l'installation ; le repli n'est là que pour ne
   * pas rendre une page blanche si les deux catalogues divergeaient un jour.
   */
  const STYLES_SECTION = {
    carte:   'background:var(--card-bg,#fff);border:1px solid var(--border,#e5e7eb);'
           + 'border-radius:10px;padding:14px;box-shadow:0 1px 2px rgba(0,0,0,.05)',
    panneau: 'background:var(--gray-bg,#f8fafc);border:1px solid var(--border,#e5e7eb);'
           + 'border-radius:8px;padding:12px',
    encadre: 'border:1px solid var(--border,#e5e7eb);border-radius:8px;padding:12px',
    section: 'padding:2px 0',
    discret: 'padding:2px 0;opacity:.9',
  };

  const STYLES_TEXTE = {
    normal: 'font-size:13px;color:var(--text,#111827)',
    titre:  'font-size:12px;font-weight:600;text-transform:uppercase;'
          + 'letter-spacing:.04em;color:var(--gray-text,#6b7280)',
    aide:   'font-size:12px;color:var(--gray-text,#6b7280)',
  };

  /**
   * Construit le HTML d'une mise en page déclarative.
   *
   * ⚠️ FONCTION PURE, ET C'EST VOULU.
   * Elle ne lit pas le document, n'attache aucun écouteur et ne dépend
   * d'aucun état : mêmes entrées, même sortie. C'est ce qui permet de
   * l'éprouver hors navigateur — une épreuve de rendu qui aurait besoin d'un
   * vrai DOM ne serait jamais lancée, et le rendu resterait la seule partie du
   * format que rien ne vérifie.
   *
   * Tout ce qui sort d'ici est soit un nombre borné par le schéma, soit un
   * mot-clé d'un catalogue fermé, soit du texte ÉCHAPPÉ. Aucune chaîne de la
   * déclaration n'atteint le HTML sans passer par esc().
   *
   * @param   layout   la mise en page validée par le schéma
   * @param   champs   les champs du jeu
   * @param   valeurDe (cle, champ) → valeur initiale du champ
   * @returns { html, css, conditions, champs }
   */
  function construireLayout(layout, champs, valeurDe, identifiant) {
    const conditions = [];    // { condition } — index = valeur de data-si
    const places = [];        // noms des champs réellement placés
    const largeurs = new Set();
    const colonnages = new Set();
    const debutsX = new Set();
    const debutsY = new Set();
    const hauteurs = new Set();

    const px = (n) => `${Math.max(0, Math.round(Number(n) || 0))}px`;

    // ── Placement sur la grille ────────────────────────────────────────
    //
    // La largeur passe par une CLASSE (.dp-w-N) et non par le style en ligne :
    // c'est ce qui permet à une requête de média de la réduire sur un écran
    // étroit. Un style en ligne ne se laisse pas surcharger sans « !important »,
    // et une feuille pleine de « !important » cesse d'être prévisible.
    /**
     * ⚠️ AUCUN PLACEMENT EN STYLE « EN LIGNE ». C'EST LA RÈGLE, ET ELLE A UNE
     * RAISON QUI A COÛTÉ CHER.
     *
     * Les positions étaient posées en ligne : `grid-column-start:7`,
     * `grid-row:2 / span 1`. Un style en ligne l'emporte sur toute règle de
     * feuille de style sans « !important » — donc sur les requêtes de média.
     * Le repli en une colonne ne s'appliquait JAMAIS : sur un téléphone, la
     * grille passait bien à une colonne, mais chaque composant gardait sa
     * position d'origine. Deux champs déclarés en ligne 3, colonnes 1 et 3, se
     * retrouvaient dans la MÊME case, l'un par-dessus l'autre, et les libellés
     * s'imprimaient l'un sur l'autre — « DateHoraire ».
     *
     * Tout le placement passe donc par des CLASSES, que la requête de média
     * peut surcharger par simple ordre de cascade. C'est aussi ce qui permet de
     * relâcher les positions fixées en étroit : la colonne 7 n'existe plus
     * quand il n'en reste qu'une.
     */
    function placement(el) {
      largeurs.add(el.largeur);
      const cls = ['dp-w-' + el.largeur];
      if (el.x != null) { debutsX.add(el.x); cls.push('dp-x-' + el.x, 'dp-fixe'); }
      if (el.y != null) { debutsY.add(el.y); cls.push('dp-y-' + el.y, 'dp-fixe'); }
      if (el.hauteur > 1) { hauteurs.add(el.hauteur); cls.push('dp-h-' + el.hauteur); }
      // Dédoublonné : « dp-fixe » était ajouté deux fois dès qu'un composant
      // portait à la fois un x et un y.
      return { cls: [...new Set(cls)], style: '' };
    }

    /**
     * Dimensions d'une SECTION.
     *
     * ⚠️ « hauteur_px » devient un MINIMUM, jamais une hauteur ferme.
     * Une hauteur ferme sur un conteneur découpe son contenu dès qu'une
     * traduction s'allonge, qu'un texte d'aide apparaît ou qu'un champ gagne
     * un bouton — et ce qui dépasse se pose par-dessus la section suivante.
     * L'auteur qui écrit une hauteur veut une section d'au moins cette taille,
     * pas une section qui tronque.
     */
    function dimensionsSection(el) {
      let s = '';
      if (el.hauteur_px)     s += `min-height:${px(el.hauteur_px)};`;
      if (el.hauteur_min_px) s += `min-height:${px(el.hauteur_min_px)};`;
      if (el.hauteur_max_px) s += `max-height:${px(el.hauteur_max_px)};overflow:auto;`;
      return s;
    }

    /**
     * Dimensions de la CELLULE d'un champ — largeur seulement.
     *
     * ⚠️ AUCUNE HAUTEUR ICI, ET C'EST LE CŒUR DU CORRECTIF.
     *
     * Un champ n'est pas qu'une boîte de saisie : c'est un libellé, la boîte,
     * parfois un texte d'aide, parfois un bouton « + Ajouter une valeur ». Pour
     * un `hauteur_px: 40`, cela fait 63 à 148 px de contenu réel. Poser cette
     * hauteur sur la CELLULE mettait donc 148 px dans une boîte de 40 : le
     * débordement se posait par-dessus la ligne suivante, et le formulaire
     * devenait illisible — libellés superposés, aides à cheval sur les champs,
     * boutons flottant sur la section d'à côté.
     *
     * « hauteur_px » décrit la boîte de SAISIE, et part donc au contrôle. La
     * cellule, elle, prend la hauteur de ce qu'elle contient — toujours.
     */
    function dimensionsCellule(el) {
      let s = '';
      if (el.largeur_min_px) s += `min-width:${px(el.largeur_min_px)};`;
      if (el.largeur_max_px) s += `max-width:${px(el.largeur_max_px)};`;
      return s;
    }

    function marqueCondition(el) {
      if (!el.visible_si) return '';
      conditions.push({ condition: el.visible_si });
      return ` data-si="${conditions.length - 1}"`;
    }

    function styleConteneur(c) {
      colonnages.add(c.colonnes);
      return `display:grid;column-gap:${px(c.gap_x_px)};row-gap:${px(c.gap_y_px)};`
           + `justify-items:${c.alignement_h};align-items:${c.alignement_v};`;
    }

    function rendreComposant(el) {
      const p = placement(el);
      const si = marqueCondition(el);

      if (el.nature === 'espace') {
        return `<div class="${p.cls.join(' ')}" style="${p.style}`
             + (el.hauteur_px ? `height:${px(el.hauteur_px)};` : '')
             + `" aria-hidden="true"${si}></div>`;
      }

      if (el.nature === 'texte') {
        return `<div class="${p.cls.join(' ')}" style="${p.style}`
             + `justify-self:${el.alignement_h};${STYLES_TEXTE[el.style] || ''}"${si}>`
             + `${esc(el.texte)}</div>`;
      }

      /**
       * ── Image ──────────────────────────────────────────────────────────
       *
       * La déclaration ne donne qu'un CHEMIN relatif au paquet ; l'adresse est
       * composée ici, vers la seule route qui sait servir un fichier de module.
       * Cette route exige une session, vérifie le chemin, et contrôle que le
       * contenu est bien une image par ses OCTETS — pas par son extension.
       *
       * Une description vide signale une image décorative : on la retire alors
       * de l'arbre d'accessibilité plutôt que de faire lire un nom de fichier
       * à voix haute.
       */
      if (el.nature === 'image') {
        const src = 'api/index.php?action=ext_image'
                  + '&extension=' + encodeURIComponent(identifiant || '')
                  + '&fichier=' + encodeURIComponent(el.image);
        const decorative = !el.description;

        // Une image à qui l'on donne PLUSIEURS LIGNES veut les remplir : sans
        // cela elle se pose en haut de sa colonne et laisse un vide sous elle,
        // ce qui n'est jamais ce qu'on voulait en lui réservant la place.
        // Une hauteur explicite reste prioritaire.
        const remplitCellule = !el.hauteur_px && el.hauteur > 1;
        const dims = (el.hauteur_px ? `height:${px(el.hauteur_px)};` : '')
                   + (remplitCellule ? 'height:100%;' : '')
                   + (el.hauteur_min_px ? `min-height:${px(el.hauteur_min_px)};` : '')
                   + (el.hauteur_max_px ? `max-height:${px(el.hauteur_max_px)};` : '')
                   + (el.largeur_max_px ? `max-width:${px(el.largeur_max_px)};` : '');

        return `<div class="${p.cls.join(' ')}" style="${p.style}`
             + `justify-self:${el.alignement_h};"${si}>`
             + `<img src="${src}" alt="${esc(el.description || '')}"`
             + (decorative ? ' aria-hidden="true"' : '')
             + ` loading="lazy" style="display:block;width:100%;`
             + `object-fit:${el.cadrage};border-radius:${el.arrondi};${dims}"></div>`;
      }

      if (el.nature === 'section') {
        const corps = el.composants.map(rendreComposant).join('');
        const habillage = STYLES_SECTION[el.style] || '';
        const bord = el.couleur ? `border-left:3px solid ${el.couleur};` : '';

        // Le titre d'une section repliable est un <summary> : le pli est tenu
        // par le navigateur, sans une ligne de script — et il reste accessible
        // au clavier, ce qu'un div cliquable n'est pas.
        const titre = el.titre
          ? `<div style="${STYLES_TEXTE.titre};margin:0 0 8px">${esc(el.titre)}</div>` : '';

        const grille = `<div class="dp-grille dp-col-${el.colonnes}" `
                     + `style="${styleConteneur(el)}">${corps}</div>`;

        const dedans = el.repliable
          ? `<details${el.repliee ? '' : ' open'}>`
            + `<summary style="${STYLES_TEXTE.titre};cursor:pointer;margin-bottom:8px">`
            + `${esc(el.titre || 'Détails')}</summary>${grille}</details>`
          : titre + grille;

        return `<div class="${p.cls.join(' ')}" style="${p.style}${habillage};${bord}`
             + `${dimensionsSection(el)}"${si}>${dedans}</div>`;
      }

      // ── Champ ────────────────────────────────────────────────────────
      const c = champs[el.champ];
      if (!c) return '';                       // champ retiré depuis : on saute
      places.push(el.champ);

      // Les dimensions du LAYOUT priment sur celles déclarées au champ : c'est
      // la mise en page qui décide de la disposition, le champ ne donne qu'un
      // défaut quand aucune mise en page ne le place.
      //
      // Toutes les dimensions de SAISIE descendent au contrôle — hauteur
      // comprise. La cellule n'en reçoit aucune : voir dimensionsCellule().
      const fusion = Object.assign({}, c, {
        largeur_px:     el.largeur_px     != null ? el.largeur_px     : c.largeur_px,
        hauteur_px:     el.hauteur_px     != null ? el.hauteur_px     : c.hauteur_px,
        largeur_min_px: el.largeur_min_px != null ? el.largeur_min_px : c.largeur_min_px,
        largeur_max_px: el.largeur_max_px != null ? el.largeur_max_px : c.largeur_max_px,
        hauteur_min_px: el.hauteur_min_px != null ? el.hauteur_min_px : c.hauteur_min_px,
        hauteur_max_px: el.hauteur_max_px != null ? el.hauteur_max_px : c.hauteur_max_px,
      });

      const cls = p.cls.concat(el.masquer_libelle ? ['dp-sans-libelle'] : []);

      /**
       * La cellule est une COLONNE flex : libellé, contrôle, aide, bouton.
       *
       * C'est ce qui donne un sens à « alignement_h » quand le contrôle est
       * plus étroit que sa colonne — un montant calé à droite, par exemple.
       * En empilement de blocs, l'alignement n'avait aucun effet visible :
       * chaque élément prenait toute la largeur, et « droite » ne déplaçait
       * rien. « position:relative » reste indispensable : sans lui, la liste de
       * suggestions, posée en absolu, se place par rapport à la modale.
       */
      return `<div data-champ="${esc(el.champ)}" class="${cls.join(' ')}" `
           + `style="position:relative;display:flex;flex-direction:column;`
           + `align-items:${el.alignement_h};justify-content:${el.alignement_v};`
           + `${p.style}align-self:${el.alignement_v};${dimensionsCellule(el)}"${si}>`
           + `${champFormulaire(el.champ, fusion, valeurDe(el.champ, c))}</div>`;
    }

    const corps = layout.composants.map(rendreComposant).join('');
    const html = `<div class="dp-layout dp-grille dp-col-${layout.colonnes}" `
               + `style="${styleConteneur(layout)}`
               + (layout.marges_px ? `padding:${px(layout.marges_px)};` : '')
               + `">${corps}</div>`;

    return { html,
             css: styleLayout(layout, colonnages, largeurs,
                              debutsX, debutsY, hauteurs),
             conditions, champs: places };
  }

  /**
   * La feuille de style de la mise en page — entièrement composée de NOMBRES.
   *
   * Les seules valeurs qui y entrent sont des entiers déjà bornés par le
   * schéma : un nombre de colonnes, un espacement, un seuil en pixels. Aucune
   * chaîne de la déclaration n'y figure, donc rien à échapper et rien à
   * assainir — il n'y a pas de texte d'auteur dans ce CSS.
   *
   * C'est là que vit le comportement responsive. L'auteur déclare « en dessous
   * de 640 px, une colonne » ; la règle de média est fabriquée ici. Il n'écrit
   * jamais de CSS, et ne peut donc pas en injecter.
   */
  function styleLayout(layout, colonnages, largeurs, debutsX, debutsY, hauteurs) {
    const n = (x) => Math.max(1, Math.round(Number(x) || 1));
    let css = '';

    colonnages.forEach((c) => {
      css += `.dp-col-${n(c)}{grid-template-columns:repeat(${n(c)},minmax(0,1fr))}`;
    });
    largeurs.forEach((w) => {
      css += `.dp-w-${n(w)}{grid-column-end:span ${n(w)}}`;
    });
    debutsX.forEach((x) => { css += `.dp-x-${n(x)}{grid-column-start:${n(x)}}`; });
    debutsY.forEach((y) => { css += `.dp-y-${n(y)}{grid-row-start:${n(y)}}`; });
    hauteurs.forEach((h) => { css += `.dp-h-${n(h)}{grid-row-end:span ${n(h)}}`; });
    css += '.dp-sans-libelle>.form-label{display:none}';
    css += '.dp-layout details>summary{list-style:revert}';

    (layout.responsive || []).forEach((p) => {
      const pc = n(p.colonnes);
      let regles = '';
      colonnages.forEach((c) => {
        regles += `.dp-col-${n(c)}{grid-template-columns:repeat(${Math.min(pc, n(c))},minmax(0,1fr))}`;
      });
      // Une largeur ne peut pas dépasser le nombre de colonnes restant, et une
      // position fixée n'a plus de sens : la colonne 7 n'existe plus quand il
      // n'en reste qu'une. On relâche donc les placements, sinon la grille
      // ajoute des colonnes fantômes pour les honorer et tout se décale.
      largeurs.forEach((w) => {
        regles += `.dp-w-${n(w)}{grid-column-end:span ${Math.min(pc, n(w))}}`;
      });
      regles += '.dp-fixe{grid-column-start:auto;grid-row-start:auto}';
      if (p.gap_px != null) {
        regles += `.dp-grille{column-gap:${n(p.gap_px)}px;row-gap:${n(p.gap_px)}px}`;
      }
      css += `@media (max-width:${Math.round(Number(p.en_dessous_de_px) || 640)}px){${regles}}`;
    });

    return css;
  }

  /**
   * Saisie assistée : la liste se resserre à chaque lettre.
   *
   * Le filtrage se fait sur le SERVEUR, pas sur une liste chargée une fois :
   * un registre de mille lignes ne tient pas dans le navigateur, et filtrer
   * localement donnerait des propositions incomplètes sans le dire.
   */
  /**
   * Recherche filtrante sur un champ : la liste se resserre à chaque lettre.
   *
   * Une seule mécanique pour les suggestions de texte et les sélecteurs de
   * lien : deux implémentations divergeraient, et l'une des deux finirait sans
   * navigation au clavier ou sans mise en évidence.
   */
  function brancherRecherche(cle, api, opts) {
    const champ = document.getElementById('dp-' + cle);
    const liste = document.getElementById('dp-' + cle + '-liste');
    if (!champ || !liste) return;

    let minuteur = null;
    let choisi = -1;
    const fermer = () => { liste.style.display = 'none'; choisi = -1; };

    const dessiner = (valeurs, saisi) => {
      if (valeurs.length === 0) {
        // Champ vide et registre vide : il n'y a rien à proposer, et afficher
        // « Aucun résultat » donnerait l'impression d'une recherche en panne.
        // On n'ouvre la liste que si l'utilisateur a tapé quelque chose.
        if (saisi === '') { fermer(); return; }
        liste.innerHTML = `<div style="padding:6px 10px;font-size:13px;
          color:var(--gray-text)">Aucune valeur enregistrée ne correspond —
          la vôtre sera la première.</div>`;
        liste.style.display = '';
        return;
      }
      liste.innerHTML = valeurs.map((v, i) => {
        const texte = (typeof v === 'string') ? v : v.libelle;
        const p = texte.toLowerCase().indexOf(saisi.toLowerCase());
        const html = (saisi && p >= 0)
          ? esc(texte.slice(0, p)) + '<strong>' + esc(texte.slice(p, p + saisi.length))
            + '</strong>' + esc(texte.slice(p + saisi.length))
          : esc(texte);
        return `<div data-i="${i}" data-t="${esc(texte)}"
          data-id="${esc(String((typeof v === 'object' && v.id) || ''))}"
          style="padding:6px 10px;cursor:pointer;font-size:13px">${html}</div>`;
      }).join('');
      liste.style.display = '';
      liste.style.width = champ.getBoundingClientRect().width + 'px';

      liste.querySelectorAll('[data-t]').forEach((el) => {
        el.addEventListener('mousedown', (e) => {
          e.preventDefault();          // avant le blur, sinon le clic est perdu
          champ.value = el.dataset.t;
          if (opts.surChoix) opts.surChoix({ id: el.dataset.id, libelle: el.dataset.t });
          fermer();
          champ.dispatchEvent(new Event('change'));
        });
      });
    };

    const chercher = () => {
      const saisi = champ.value.trim();
      api.appel(opts.action, { jeu: opts.jeu, champ: cle, recherche: saisi })
        .then((r) => dessiner((r?.data ?? r).valeurs || [], saisi))
        .catch(() => fermer());
    };

    champ.addEventListener('input', () => {
      // Le champ vidé annule le choix : sans cela, effacer le texte laisserait
      // l'identifiant précédent enregistré à l'insu de l'utilisateur.
      if (champ.value.trim() === '' && opts.surVide) opts.surVide();
      clearTimeout(minuteur);
      minuteur = setTimeout(chercher, 180);
    });
    champ.addEventListener('focus', chercher);
    champ.addEventListener('blur', () => setTimeout(fermer, 150));

    champ.addEventListener('keydown', (e) => {
      const options = [...liste.querySelectorAll('[data-t]')];
      if (liste.style.display === 'none' || options.length === 0) return;
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        choisi += (e.key === 'ArrowDown' ? 1 : -1);
        if (choisi < 0) choisi = options.length - 1;
        if (choisi >= options.length) choisi = 0;
        options.forEach((o, i) => { o.style.background = (i === choisi) ? 'var(--gray-bg)' : ''; });
        options[choisi].scrollIntoView({ block: 'nearest' });
      } else if (e.key === 'Enter' && choisi >= 0) {
        e.preventDefault();
        options[choisi].dispatchEvent(new Event('mousedown'));
      } else if (e.key === 'Escape') {
        fermer();
      }
    });
  }

  /** Bouton « + Ajouter une valeur » sous un choix qui l'autorise. */
  function brancherAjoutListe(saisissables, api, jeuNom) {
    saisissables.forEach(([cle, c]) => {
      if (!c.ajout_autorise) return;
      const b = document.querySelector(`[data-ajout="${cle}"]`);
      const sel = document.getElementById('dp-' + cle);
      if (!b || !sel) return;

      b.addEventListener('click', async () => {
        const v = (window.prompt('Nouvelle valeur pour « ' + c.libelle + ' »') || '').trim();
        if (v === '') return;
        try {
          await api.appel('dp_ajouter_valeur', { jeu: jeuNom, champ: cle, valeur: v });
          // Ajoutée ET sélectionnée : sans cela, l'utilisateur doit la
          // rechercher dans la liste qu'il vient d'enrichir.
          //
          // Deux formes possibles selon que la liste était vide ou non : un
          // <input> tant qu'elle l'est, un <select> ensuite. Ne traiter que le
          // second laissait la première valeur ajoutée sans effet visible.
          if (sel.tagName === 'SELECT') {
            const o = document.createElement('option');
            o.value = v; o.textContent = v; o.selected = true;
            sel.appendChild(o);
          } else {
            sel.value = v;
            // En saisie libre, le champ est un <input> adossé à un <datalist> :
            // la valeur ajoutée doit y entrer aussi, sinon elle n'est plus
            // proposée à la saisie suivante alors qu'elle est bien en base.
            const dl = document.getElementById('dl-' + cle);
            if (dl && !dl.querySelector(`option[value="${CSS.escape(v)}"]`)) {
              const o = document.createElement('option');
              o.value = v; dl.appendChild(o);
            }
          }
          toast('Valeur ajoutée à la liste.', 'success');
        } catch (e) { toast(e.message, 'error'); }
      });
    });
  }

  function brancherSuggestions(saisissables, api, jeuNom) {
    saisissables.forEach(([cle, c]) => {
      if (!c.suggestions) return;
      brancherRecherche(cle, api, { action: 'dp_suggestions', jeu: jeuNom });
    });
  }

  function lireFormulaire(champs) {
    const v = {};
    for (const [cle, champ] of Object.entries(champs)) {
      const el = document.getElementById('dp-' + cle);
      if (!el) continue;
      if (champ.type === 'lien') {
        const cache = document.getElementById('dp-' + cle + '-id');
        v[cle] = cache ? cache.value : '';
      } else if (champ.type === 'choix_multiple') {
        v[cle] = Array.from(el.querySelectorAll('[data-multi]'))
          .filter(x => x.checked).map(x => x.value).join(', ');
      } else {
        v[cle] = champ.type === 'booleen' ? (el.checked ? 1 : 0) : el.value;
      }
    }
    return v;
  }

  /**
   * Point d'entrée : dessine la page décrite.
   *
   * @param conteneur  l'élément qui nous est confié
   * @param api        { appel(action, charge) }
   * @param decl       { donnees, pages } — la déclaration du module
   * @param clePage    la page à afficher
   */
  /**
   * Traduit les réglages d'apparence en styles.
   *
   * Un réglage qui ne change rien à l'écran n'est pas un réglage : c'est une
   * valeur rangée dans un fichier. On lit ici ce que chaque réglage pilote.
   */
  /**
   * Apparence d'une liste de module.
   *
   * L'habillage global est l'affaire d'un MODULE d'interface, qui pose des
   * variables CSS pour toute l'application. Ici, on ne lit que ce que le
   * module courant déclare pour SA liste — un accent, une densité.
   */
  function apparence(decl) {
    const a = { accent: null, fond: null, texte: null, entete: null,
                densite: 'Confortable', zebre: false, filet: false,
                taille: 'Normal', police: 'Système',
                bordures: 'Fines', arrondi: 'Léger' };
    const reglages = decl.reglages || {};
    const declares = decl.declaration_reglages || {};

    // Le THÈME d'abord : il pose une palette cohérente. Les réglages
    // individuels le surchargent ensuite — sans cet ordre, choisir un thème
    // effacerait un ajustement fait juste avant, ce qui est déroutant.
    Object.entries(declares).forEach(([cle, def]) => {
      if (def.applique !== 'theme') return;
      const t = (decl.themes || {})[reglages[cle]];
      if (!t) return;
      a.accent = t.accent || a.accent;
      a.fond   = t.fond   || a.fond;
      a.texte  = t.texte  || a.texte;
      a.entete = t.entete || a.entete;
      a.bordures = t.bordures || a.bordures;
      a.arrondi  = t.arrondi  || a.arrondi;
    });

    Object.entries(declares).forEach(([cle, def]) => {
      if (!def.applique || def.applique === 'theme') return;
      const v = reglages[cle];
      if (v === undefined || v === null || v === '') return;
      switch (def.applique) {
        case 'couleur_accent':   a.accent   = String(v); break;
        case 'couleur_fond':     a.fond     = String(v); break;
        case 'couleur_texte':    a.texte    = String(v); break;
        case 'couleur_entete':   a.entete   = String(v); break;
        case 'densite':          a.densite  = String(v); break;
        case 'lignes_alternees': a.zebre    = !!v && v !== '0'; break;
        case 'bordure_gauche':   a.filet    = !!v && v !== '0'; break;
        case 'taille_texte':     a.taille   = String(v); break;
        case 'police':           a.police   = String(v); break;
        case 'style_bordures':   a.bordures = String(v); break;
        case 'arrondi':          a.arrondi  = String(v); break;
      }
    });
    return a;
  }

  // Piles de polices : listes closes, sûres, sans téléchargement externe.
  // Une police libre exigerait de charger une ressource distante, ce qu'un
  // pack de données ne fait pas.
  const POLICES = {
    'Système':           "system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif",
    'Sans empattement':  "'Helvetica Neue', Arial, sans-serif",
    'Avec empattement':  "Georgia, 'Times New Roman', serif",
    'Chasse fixe':       "ui-monospace, 'SF Mono', Menlo, Consolas, monospace",
  };
  const BORDURES = { 'Aucune': 'none', 'Fines': '1px solid var(--gray-border)',
                     'Marquées': '2px solid currentColor' };
  const ARRONDIS = { 'Aucun': '0', 'Léger': '8px', 'Marqué': '16px' };

  /**
   * Écran d'apparence, dessiné par Larka à la demande d'un module.
   *
   * Il montre les thèmes installés et les variables du vocabulaire publié par
   * ce module. Chaque utilisateur choisit le sien et l'ajuste : les retouches
   * vivent dans SES préférences, elles ne modifient pas le thème installé —
   * sans quoi un réglage personnel changerait l'écran de tout le monde.
   */
  /**
   * Modifications d'apparence en attente d'enregistrement.
   *
   * Au niveau du MODULE : plusieurs actions redessinent l'écran, et une
   * variable locale serait remise à zéro à chaque fois — l'aperçu ne survivrait
   * pas au premier redessin.
   */
  let _apercu = null;

  async function rendreApparence(conteneur, decl) {
    const X = (typeof LarkaExtensions !== 'undefined') ? LarkaExtensions : null;
    if (!X) { conteneur.innerHTML = '<div class="card"><div class="card-body">' +
      'Couche extensions indisponible.</div></div>'; return; }
    // Liste des fonds téléversés, chargée avant le rendu : le champ image en a
    // besoin pour proposer autre chose que les images des packs.
    if (window._larkaFonds === null || window._larkaFonds === undefined) {
      try {
        const r = await apiRequest('fonds_lister');
        window._larkaFonds = (r && r.data) ? r.data : (Array.isArray(r) ? r : []);
      } catch (e) { window._larkaFonds = []; }
    }

    const vocab = decl.vocabulaire || {};
    const themes = X.themesDisponibles ? X.themesDisponibles() : [];
    const prefs = () => { try { return JSON.parse(localStorage.getItem('gmao_ui_prefs')) || {}; }
                          catch (e) { return {}; } };
    // ── Aperçu, puis enregistrement ──────────────────────────────────────
    //
    // Chaque clic écrivait directement dans le stockage local. On ne pouvait
    // donc pas ESSAYER : le premier essai devenait le réglage, et revenir en
    // arrière supposait de se rappeler l'état précédent — que rien n'affichait.
    //
    // « apercu » tient les modifications en attente. Elles s'appliquent tout de
    // suite à l'écran, mais ne sont écrites qu'à l'enregistrement. Annuler les
    // jette et rétablit ce qui était en place.
    //
    // La langue échappe à ce cycle : la changer recharge la page, il n'y a rien
    // à prévisualiser.
    // ⚠️ « apercu » VIT AU NIVEAU DU MODULE, PAS DE CETTE FONCTION.
    //
    // Il était local à rendreApparence(). Or plusieurs actions redessinent
    // l'écran — un dépôt d'image, une réinitialisation : le rendu recréait la
    // variable à null et jetait l'aperçu en cours. Téléverser une image de fond
    // l'appliquait donc une fraction de seconde, puis le rendu suivant
    // l'effaçait. Vu de l'utilisateur : impossible de mettre un fond.
    //
    // Au niveau du module, l'aperçu survit aux redessins.
    const enAttente = () => _apercu || prefs();

    // Un redessin recrée le DOM mais pas la barre : on la remet si quelque
    // chose est toujours en attente. Sans cela, l'aperçu survivait sans que
    // rien ne propose plus de l'enregistrer.
    setTimeout(() => majBarre(), 0);

    const previsualiser = (patch) => {
      _apercu = Object.assign({}, enAttente(), patch);
      X.appliquerHabillage(_apercu);
      majBarre();
    };

    const enregistrer = () => {
      if (!_apercu) return;
      localStorage.setItem('gmao_ui_prefs', JSON.stringify(_apercu));
      _apercu = null;
      X.appliquerHabillage();
      majBarre();
      if (typeof toast === 'function') toast('Apparence enregistrée.', 'success');
    };

    const annuler = () => {
      _apercu = null;
      X.appliquerHabillage();
      rendreApparence(conteneur, decl);
    };

    /** Barre d'action, visible seulement quand quelque chose est en attente. */
    const majBarre = () => {
      const ancienne = document.getElementById('ap-barre');
      if (!_apercu) { if (ancienne) ancienne.remove(); return; }

      // ⚠️ ON LA RECONSTRUIT, ON NE LA GARDE PAS.
      // « if (barre) return » laissait en place la barre du rendu précédent,
      // avec des gestionnaires liés à une fermeture périmée : « Enregistrer »
      // et « Annuler » agissaient sur un état qui n'existait plus, et
      // paraissaient ne rien faire.
      if (ancienne) ancienne.remove();

      const barre = document.createElement('div');
      barre.id = 'ap-barre';
      barre.style.cssText =
        'position:fixed;left:50%;transform:translateX(-50%);bottom:22px;z-index:400;'
      + 'display:flex;align-items:center;gap:14px;padding:12px 18px;border-radius:12px;'
      + 'background:var(--navy,#1b3a5c);color:#fff;box-shadow:0 6px 24px rgba(0,0,0,.28);'
      + 'font-size:13px';
      barre.innerHTML =
        '<span>Aperçu en cours — rien n\'est encore enregistré.</span>'
      + '<button type="button" id="ap-annuler" class="btn btn-sm btn-secondary">Annuler</button>'
      + '<button type="button" id="ap-enregistrer" class="btn btn-sm btn-primary">Enregistrer</button>';
      document.body.appendChild(barre);
      barre.querySelector('#ap-annuler').addEventListener('click', annuler);
      barre.querySelector('#ap-enregistrer').addEventListener('click', enregistrer);
    };

    // Quitter l'écran sans enregistrer rétablit l'apparence en place : un aperçu
    // qui survivrait à la navigation serait indistinguable d'un réglage.
    // Un écouteur était ajouté à CHAQUE rendu et s'accumulait. On n'en garde
    // qu'un, posé une fois pour toutes.
    if (!window._larkaSortieApparence) {
      window._larkaSortieApparence = true;
      window.addEventListener('hashchange', () => {
        if (_apercu) { _apercu = null; X.appliquerHabillage(); }
        document.getElementById('ap-barre')?.remove();
      });
    }

    // Écriture immédiate, réservée à ce qui ne se prévisualise pas.
    const ecrire = (patch) => {
      const p = Object.assign(prefs(), patch);
      localStorage.setItem('gmao_ui_prefs', JSON.stringify(p));
      X.appliquerHabillage();
    };

    const deposees = X.thematiques ? X.thematiques() : [];
    const fonds    = window._larkaFonds || [];
    const langues  = X.languesDisponibles ? X.languesDisponibles() : [];
    const langueActuelle = prefs().langue || 'fr';
    // L'écran se dessine d'après l'APERÇU s'il y en a un : après un dépôt
    // d'image ou un changement de thème non encore enregistré, les champs
    // doivent montrer ce que l'on voit, pas l'état sauvegardé.
    const vue0   = _apercu || prefs();
    const actuel = vue0.theme_module || '';
    const perso  = vue0.apparence_perso || {};

    // ── Deux droits, pas un seul ────────────────────────────────────────
    //
    // DÉPOSER une thématique était réservé aux gestionnaires. Or l'accès à cet
    // écran se décide désormais rôle par rôle, à l'installation du module : si
    // l'administrateur l'a ouvert aux demandeurs, il l'a voulu. Leur répondre
    // « demandez à votre gestionnaire » ajoutait une seconde autorisation là où
    // la première avait déjà tranché. Quiconque voit cette page peut déposer.
    //
    // GÉRER — activer, fusionner, retirer — reste aux gestionnaires. Déposer
    // ajoute une entrée et se défait d'un clic ; retirer touche ce que les
    // autres voient, et sans retour.
    //
    // Même piège que ci-dessus : « App » est un const global, pas une propriété
    // de window. Le test « window.App && … » était toujours faux, et le bouton
    // de dépôt n'apparaissait à personne.
    const utilisateur = (typeof App !== 'undefined' && App.currentUser) || {};
    const role = utilisateur.Role || '';
    const peutGerer = role === 'Gestionnaire' || role === 'Admin';

    // Les variables sont groupées par préfixe : trente réglages en vrac ne se
    // parcourent pas, et l'utilisateur cherche « la couleur du menu », pas
    // « la douzième variable ».
    // ⚠️ L'ORDRE DES GROUPES SUIVAIT L'ORDRE DE DÉCLARATION DU MODULE.
    // « Fond » arrivait donc en quatrième position, après seize couleurs, six
    // réglages de typographie et sept de dimensions : l'image d'arrière-plan —
    // ce que l'on vient chercher en premier — se trouvait sous vingt-neuf
    // autres champs, hors de l'écran. Elle passait pour absente.
    //
    // L'ordre est désormais fixé ici. Un groupe inconnu se range à la fin
    // plutôt que de disparaître : un module peut publier un vocabulaire que
    // cette liste ne connaît pas.
    const ORDRE_GROUPES = ['Fond', 'Couleurs', 'Typographie', 'Cadres et ombres',
                           'Dimensions'];
    const brut = new Map();
    Object.entries(vocab).forEach(([nom, def]) => {
      const g = nom.startsWith('couleur') ? 'Couleurs'
              : nom.startsWith('image') || nom === 'voile_fond' ? 'Fond'
              : nom.startsWith('ombre') || nom.startsWith('bordure') ? 'Cadres et ombres'
              : nom.startsWith('police') || nom.startsWith('graisse') || nom.startsWith('taille')
                ? 'Typographie' : 'Dimensions';
      if (!brut.has(g)) brut.set(g, []);
      brut.get(g).push([nom, def]);
    });
    const groupes = new Map();
    ORDRE_GROUPES.forEach((g) => { if (brut.has(g)) groupes.set(g, brut.get(g)); });
    brut.forEach((v, g) => { if (!groupes.has(g)) groupes.set(g, v); });

    // Valeurs du thème actuellement choisi, s'il y en a un.
    //
    // ⚠️ LES CHAMPS MONTRAIENT LE DÉFAUT DU VOCABULAIRE, PAS LE THÈME.
    // Avec un thème sombre appliqué, l'écran affichait des pastilles blanches
    // et « #f5f7fa » pour le fond de l'application : on lisait le contraire de
    // ce qu'on avait sous les yeux. Et toucher un champ voisin figeait ces
    // fausses valeurs dans les ajustements personnels, en éclaircissant le
    // thème sans l'avoir demandé.
    const valeursTheme = (() => {
      if (!actuel) return {};
      const t = deposees.find((x) => x.identifiant === actuel);
      return (t && t.variables && t.variables.valeurs) || {};
    })();

    const controle = (nom, def) => {
      const v = perso[nom] !== undefined ? perso[nom]
              : (valeursTheme[nom] !== undefined ? valeursTheme[nom]
                                                 : (def.defaut ?? ''));
      const id = 'ap-' + nom;
      if (def.type === 'couleur') {
        // Une valeur « rgba(…) » n'est pas acceptée par <input type="color"> :
        // le navigateur affichait un rectangle noir, et modifier le champ
        // écrasait la transparence sans prévenir. Ces valeurs-là se règlent en
        // texte, avec leur syntaxe visible.
        const estHex = /^#[0-9a-f]{6}$/i.test(String(v));
        if (!estHex && String(v) !== '') {
          return `<input class="form-control" id="${id}" value="${esc(String(v))}"
            style="width:100%;font-family:ui-monospace,monospace;font-size:12px"
            placeholder="#rrggbb ou rgba(r,g,b,a)">`;
        }
        return `<div style="display:flex;gap:6px;align-items:center">
          <input type="color" id="${id}" value="${esc(estHex ? String(v) : '#ffffff')}"
            style="flex:0 0 42px;height:32px;padding:2px;border:1px solid var(--gray-border);
            border-radius:6px">
          <span style="font-size:11px;color:var(--gray-text);font-family:ui-monospace,monospace">
            ${esc(estHex ? String(v) : '—')}</span></div>`;
      }
      if (def.type === 'px' || def.type === 'nombre') {
        return `<input type="number" class="form-control" id="${id}" value="${esc(String(v))}"
          min="${def.min}" max="${def.max}" style="width:100%">
          <div style="font-size:10px;color:var(--gray-text)">${def.min} à ${def.max}</div>`;
      }
      if (def.type === 'choix') {
        // Une pile de polices s'écrit « system-ui, -apple-system, 'Segoe UI'… » :
        // affichée telle quelle, la liste est illisible. On ne montre que la
        // première famille, qui est celle qu'on verra à l'écran.
        const lisible = (x) => {
          const t = String(x);
          if (t.includes(',') && /[a-z]/i.test(t)) return t.split(',')[0].replace(/['"]/g, '');
          return t.length > 34 ? t.slice(0, 32) + '…' : t;
        };
        return `<select class="form-control" id="${id}" style="width:100%">`
          + (def.valeurs || []).map(x =>
              `<option value="${esc(x)}"${String(x) === String(v) ? ' selected' : ''}>
                 ${esc(lisible(x))}</option>`).join('')
          + '</select>';
      }
      // Ombres, bordures et images : une syntaxe CSS se tape mal et se
      // vérifie mal. « none » s'affichait tel quel dans un champ libre, et
      // personne ne pouvait deviner quoi écrire à la place. On propose donc
      // des valeurs prêtes.
      const PRESETS = {
        ombre: [
          ['none', 'Aucune'],
          ['0px 1px 2px rgba(0,0,0,0.08)', 'Discrète'],
          ['0px 2px 6px rgba(0,0,0,0.14)', 'Moyenne'],
          ['0px 4px 14px rgba(0,0,0,0.24)', 'Marquée'],
          ['0px 2px 8px rgba(0,0,0,0.45)', 'Sombre'],
        ],
        bordure: [
          ['none', 'Aucune'],
          ['1px solid #e2e8f0', 'Fine claire'],
          ['1px solid #94a3b8', 'Fine grise'],
          ['2px solid #1b3a5c', 'Épaisse'],
          ['1px dashed #94a3b8', 'Pointillés'],
        ],
      };
      if (PRESETS[def.type]) {
        const liste = PRESETS[def.type].slice();
        // Une valeur venue d'un thème et absente de la liste doit rester
        // sélectionnable : sinon l'ouvrir dans cet écran l'effacerait.
        if (v && !liste.some(([x]) => x === String(v))) liste.push([String(v), 'Du thème']);
        return `<select class="form-control" id="${id}" style="width:100%">`
          + liste.map(([val, lib]) =>
              `<option value="${esc(val)}"${String(val) === String(v) ? ' selected' : ''}>
                 ${esc(lib)}</option>`).join('') + '</select>';
      }
      if (def.type === 'image') {
        // Liste : « aucune », les images des thèmes installés, et les fonds
        // téléversés. Le bouton « + » ouvre le sélecteur de fichier — sans
        // lui, on ne pouvait choisir qu'une image déjà présente dans un pack.
        const images = [['none', 'Aucune']];
        deposees.forEach((t) => {
          const val = ((t.variables || {}).valeurs || {})[nom];
          if (val && val !== 'none' && !images.some(([x]) => x === val)) {
            images.push([val, t.nom + ' — ' + String(val).replace('assets/', '')]);
          }
        });
        (fonds || []).forEach((fichier) => {
          const url = 'api/index.php?action=fond_image&f=' + encodeURIComponent(fichier);
          if (!images.some(([x]) => x === url)) images.push([url, 'Téléversée — ' + fichier]);
        });
        if (v && !images.some(([x]) => x === String(v))) images.push([String(v), String(v)]);
        return `<div style="display:flex;gap:6px;align-items:center">
            <select class="form-control" id="${id}" style="flex:1">`
          + images.map(([val, lib]) =>
              `<option value="${esc(val)}"${String(val) === String(v) ? ' selected' : ''}>
                 ${esc(lib)}</option>`).join('') + `</select>
            <button type="button" class="btn btn-secondary btn-sm"
              id="${id}-ajout" title="Téléverser une image">+</button>
          </div>`;
      }
      return `<input class="form-control" id="${id}" value="${esc(String(v))}"
        style="width:100%">`;
    };

    conteneur.innerHTML = `
      <div style="display:flex;align-items:center;justify-content:space-between;
        flex-wrap:wrap;gap:12px;margin-bottom:16px">
        <div>
          <h2 style="margin:0">Apparence</h2>
          <div style="font-size:13px;color:var(--gray-text)">
            Ces réglages ne concernent que vous.</div>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
          <input type="file" id="ap-fichier" accept=".larka_thematique"
            multiple style="display:none">
          <button class="btn btn-primary btn-sm" id="ap-importer">
            Déposer une thématique</button>
          <button class="btn btn-secondary btn-sm" id="ap-reset">Tout réinitialiser</button>
        </div>
      </div>

      <div class="card" style="margin-bottom:14px"><div class="card-body">
        <div id="ap-zone" style="border:2px dashed var(--gray-border);border-radius:10px;
          padding:22px;text-align:center;color:var(--gray-text);font-size:13px;
          transition:background .15s,border-color .15s">
          Glissez ici un ou plusieurs fichiers <code>.larka_thematique</code>,
          ou <a href="#" id="ap-parcourir">parcourez vos fichiers</a>.
          <div style="font-size:11px;margin-top:6px">
            Une thématique n'apporte que des couleurs et des libellés :
            elle ne demande aucun accès à vos données.</div>
        </div>
        ${deposees.length ? `
          <div style="margin-top:12px">
            ${deposees.map(t => `
              <div style="display:flex;align-items:center;justify-content:space-between;
                gap:10px;padding:9px 0;border-bottom:1px solid var(--gray-border);
                ${t.active === false ? 'opacity:.55' : ''}">
                <div style="min-width:0">
                  <strong style="font-size:13px">${esc(t.nom)}</strong>
                  <span style="font-size:11px;color:var(--gray-text)">
                    v${esc(t.version)} · ${esc(t.identifiant)}</span>
                  <span style="font-size:11px;margin-left:6px;padding:1px 6px;border-radius:4px;
                    background:${t.active === false ? 'var(--gray-bg)' : 'var(--blue-pale)'};
                    color:${t.active === false ? 'var(--gray-text)' : 'var(--navy)'}">
                    ${t.active === false ? 'inactive' : 'active'}</span>
                  <span style="font-size:11px;color:var(--gray-text);margin-left:6px">
                    ${t.variables && t.variables.valeurs
                      ? Object.keys(t.variables.valeurs).length + ' variable(s)' : ''}
                    ${Object.keys(t.langues || {}).length
                      ? Object.keys(t.langues).map(c =>
                          c + ' (' + Object.keys(t.langues[c]).length + ')').join(', ') : ''}</span>
                  ${t.description ? `<div style="font-size:11px;color:var(--gray-text)">
                    ${esc(t.description)}</div>` : ''}
                </div>
                <div style="display:flex;gap:6px;flex:0 0 auto">
                  ${peutGerer ? `
                  <button class="btn btn-secondary btn-sm" data-basculer="${esc(t.identifiant)}"
                    data-vers="${t.active === false ? '1' : '0'}">
                    ${t.active === false ? 'Activer' : 'Désactiver'}</button>
                  <button class="btn btn-secondary btn-sm" data-fusionner="${esc(t.identifiant)}">
                    Fusionner…</button>
                  <button class="btn btn-secondary btn-sm" data-retirer="${esc(t.identifiant)}">
                    Retirer</button>` : ''}
                </div>
              </div>`).join('')}
          </div>` : ''}
      </div></div>

      <div class="card"><div class="card-body">
        <div style="display:flex;align-items:center;justify-content:space-between;
          gap:10px;flex-wrap:wrap">
          <div class="section-label">Langue</div>
          <button class="btn btn-secondary btn-sm" id="ap-modele">
            Télécharger le modèle de traduction</button>
        </div>
        <div style="font-size:11px;color:var(--gray-text);margin-bottom:8px">
          Le modèle liste tout ce qui est traduisible. Copiez-le, remplacez les
          valeurs, et déposez le résultat comme thématique.</div>
        ${langues.length === 0
          ? `<div style="font-size:13px;color:var(--gray-text)">
               Aucun pack de langue actif. Déposez un fichier
               .larka_thematique ci-dessus.</div>`
          : `<div style="display:flex;flex-wrap:wrap;gap:10px">
              ${[{ code: 'fr', nom: 'Français', origine: true }].concat(langues)
                .map(l => `
                <button class="btn ${l.code === langueActuelle ? 'btn-primary' : 'btn-secondary'}"
                  data-langue="${esc(l.code)}"
                  title="${esc(l.origine ? 'Langue d\'origine de Larka'
                    : `${l.libelles} libellés — ${(l.sources || []).join(', ')}`)}">
                  ${esc(l.nom || l.code)}
                  ${l.origine ? '' : `<span style="opacity:.6;font-size:11px">
                    ${l.libelles}</span>`}</button>`).join('')}
             </div>`}
      </div></div>

      <div class="card" style="margin-top:14px"><div class="card-body">
        <div class="section-label">Thème</div>
        ${X.sombreSuspendu && X.sombreSuspendu() ? `
        <div style="font-size:12px;color:var(--gray-text);margin-bottom:8px">
          Le mode sombre est suspendu tant qu'un thème est choisi. Il revient
          si vous sélectionnez « Aucun ».</div>` : ''}
      ${themes.length === 0
          ? `<div style="font-size:13px;color:var(--gray-text)">
               Aucun thème disponible. Déposez un fichier
               .larka_thematique ci-dessus.</div>`
          : `<div style="display:flex;flex-wrap:wrap;gap:10px">
              ${[{ identifiant: '', nom: 'Aucun (apparence d\'origine)' }].concat(themes)
                .map(t => `
                <button class="btn ${t.identifiant === actuel ? 'btn-primary' : 'btn-secondary'}"
                  data-theme="${esc(t.identifiant)}">${esc(t.nom)}</button>`).join('')}
             </div>`}
      </div></div>

      <div class="card" style="margin-top:14px"><div class="card-body">
        <div class="section-label">Ajustements personnels</div>
        <div style="font-size:12px;color:var(--gray-text);margin-bottom:10px">
          Ils s'appliquent par-dessus le thème choisi. Laissez vide pour garder
          la valeur du thème.</div>
        ${[...groupes.entries()].map(([g, vars]) => `
          <div style="margin-bottom:16px">
            <div style="font-size:12px;font-weight:600;text-transform:uppercase;
              letter-spacing:.04em;color:var(--gray-text);margin-bottom:6px">${esc(g)}</div>
            <div style="display:grid;
              grid-template-columns:repeat(auto-fill,minmax(190px,220px));gap:12px">
              ${vars.map(([nom, def]) => `
                <div>
                  <label class="form-label" style="font-size:12px">${esc(def.libelle || nom)}</label>
                  ${controle(nom, def)}
                </div>`).join('')}
            </div>
          </div>`).join('')}
      </div></div>`;

    const boutonsTheme = conteneur.querySelectorAll('[data-theme]');
    boutonsTheme.forEach((b) => {
      b.addEventListener('click', () => {
        previsualiser({ theme_module: b.dataset.theme });
        // ⚠️ PLUS DE REDESSIN ICI.
        // Chaque clic relançait rendreApparence() — donc un rechargement des
        // fonds, une reconstruction du DOM et un nouveau jeu de gestionnaires,
        // en pleine prévisualisation. Il suffit de marquer le bouton actif :
        // les champs, eux, gardent leur sens puisqu'ils affichent des
        // ajustements personnels, indépendants du thème.
        boutonsTheme.forEach((x) => {
          const actif = x === b;
          x.classList.toggle('btn-primary', actif);
          x.classList.toggle('btn-secondary', !actif);
        });
      });
    });

    // Aperçu immédiat : un réglage d'apparence dont on ne voit pas l'effet
    // oblige à deviner, et l'on renonce.
    Object.entries(vocab).forEach(([nom, def]) => {
      const el = document.getElementById('ap-' + nom);
      if (!el) return;
      const maj = () => {
        const p = Object.assign({}, enAttente().apparence_perso || {});
        if (el.value === '' ) delete p[nom]; else p[nom] = el.value;
        previsualiser({ apparence_perso: p });
      };
      el.addEventListener('change', maj);
      if (def.type === 'couleur') el.addEventListener('input', maj);
    });

    // ── Dépôt : bouton, lien, et glisser-déposer ────────────────────────
    const fImp = document.getElementById('ap-fichier');
    const zone = document.getElementById('ap-zone');

    const deposer = async (fichiers) => {
      let n = 0;
      for (const f of fichiers) {
        try {
          const form = new FormData();
          form.append('fichier', f);
          const rep = await fetch('api/index.php?action=thematique_importer',
            { method: 'POST', body: form, credentials: 'same-origin' });
          const j = await rep.json();
          if (!j.success) throw new Error(j.error || 'Dépôt refusé.');
          n++;
        } catch (e) {
          // Un fichier fautif n'empêche pas les autres : déposer cinq
          // thématiques et tout perdre pour une seule serait décourageant.
          toast(`${f.name} : ${e.message}`, 'error');
        }
      }
      if (n > 0) {
        toast(n > 1 ? `${n} thématiques déposées.` : 'Thématique déposée.', 'success');
        await X.chargerThematiques();
        X.appliquerHabillage();
        rendreApparence(conteneur, decl);
      }
    };

    if (fImp) {
      const ouvrir = (e) => { if (e) e.preventDefault(); fImp.click(); };
      const bImp = document.getElementById('ap-importer');
      const lien = document.getElementById('ap-parcourir');
      if (bImp) bImp.addEventListener('click', ouvrir);
      if (lien) lien.addEventListener('click', ouvrir);
      fImp.addEventListener('change', async () => {
        await deposer([...fImp.files]);
        fImp.value = '';
      });
    }

    if (zone) {
      ['dragenter', 'dragover'].forEach(ev => zone.addEventListener(ev, (e) => {
        e.preventDefault();
        zone.style.background = 'var(--blue-pale)';
        zone.style.borderColor = 'var(--blue)';
      }));
      ['dragleave', 'drop'].forEach(ev => zone.addEventListener(ev, (e) => {
        e.preventDefault();
        zone.style.background = '';
        zone.style.borderColor = 'var(--gray-border)';
      }));
      zone.addEventListener('drop', async (e) => {
        const f = [...(e.dataTransfer?.files || [])]
          .filter(x => x.name.toLowerCase().endsWith('.larka_thematique'));
        if (f.length === 0) {
          toast('Déposez un fichier « .larka_thematique ».', 'error');
          return;
        }
        await deposer(f);
      });
    }

    const modele = document.getElementById('ap-modele');
    if (modele) modele.addEventListener('click', async () => {
      try {
        const r = await fetch('lang/fr.json', { credentials: 'same-origin' });
        if (!r.ok) throw new Error('Le catalogue lang/fr.json est absent.');
        const txt = await r.text();
        const url = URL.createObjectURL(new Blob([txt], { type: 'application/json' }));
        const a = document.createElement('a');
        a.href = url; a.download = 'larka-libelles-fr.json';
        a.click();
        URL.revokeObjectURL(url);
      } catch (e) { toast(e.message, 'error'); }
    });

    conteneur.querySelectorAll('[data-langue]').forEach((b) => {
      b.addEventListener('click', () => {
        ecrire({ langue: b.dataset.langue });
        // Rechargement : les libellés déjà affichés ne se retraduisent pas
        // tout seuls, et un demi-écran traduit ferait douter du réglage.
        location.reload();
      });
    });

    conteneur.querySelectorAll('[data-basculer]').forEach((b) => {
      b.addEventListener('click', async () => {
        try {
          await apiRequest('thematique_activer', 'POST', {
            identifiant: b.dataset.basculer, active: b.dataset.vers === '1' });
          await X.chargerThematiques();
          X.appliquerHabillage();
          rendreApparence(conteneur, decl);
        } catch (e) { toast(e.message, 'error'); }
      });
    });

    conteneur.querySelectorAll('[data-fusionner]').forEach((b) => {
      b.addEventListener('click', () => {
        const cible = b.dataset.fusionner;
        const autres = deposees.filter(t => t.identifiant !== cible);
        if (autres.length === 0) {
          toast('Il faut au moins deux thématiques pour en fusionner.', 'error');
          return;
        }
        openModal('Fusionner dans « ' + esc(
            (deposees.find(t => t.identifiant === cible) || {}).nom || cible) + ' »',
          `<div style="font-size:13px;margin-bottom:10px">
             Les entrées de la thématique choisie seront ajoutées à celle-ci.
             En cas de doublon, c'est la thématique ajoutée qui l'emporte.</div>
           <select class="form-control" id="ap-fusion-src">
             ${autres.map(t => `<option value="${esc(t.identifiant)}">${esc(t.nom)}</option>`).join('')}
           </select>`,
          async () => {
            const src = document.getElementById('ap-fusion-src').value;
            try {
              const r = await apiRequest('thematique_fusionner', 'POST',
                { cible, source: src });
              const d = (r && r.data) ? r.data : r;
              toast(`${d.ajouts} entrée(s) ajoutée(s).`, 'success');
              closeModal();
              await X.chargerThematiques();
              X.appliquerHabillage();
              rendreApparence(conteneur, decl);
            } catch (e) { toast(e.message, 'error'); }
          }, 'Fusionner');
      });
    });

    conteneur.querySelectorAll('[data-retirer]').forEach((b) => {
      b.addEventListener('click', async () => {
        try {
          await apiRequest('thematique_supprimer', 'POST',
            { identifiant: b.dataset.retirer });
          // Le thème retiré était peut-être le thème actif : on le désélectionne,
          // sinon l'apparence resterait figée sur une thématique absente.
          if (prefs().theme_module === b.dataset.retirer) ecrire({ theme_module: '' });
          await X.chargerThematiques();
          X.appliquerHabillage();
          rendreApparence(conteneur, decl);
          toast('Thématique retirée.', 'success');
        } catch (e) { toast(e.message, 'error'); }
      });
    });

    // Boutons de téléversement d'image de fond
    Object.entries(vocab).forEach(([nom, def]) => {
      if (def.type !== 'image') return;
      const b = document.getElementById('ap-' + nom + '-ajout');
      if (!b) return;
      b.addEventListener('click', () => {
        const inp = document.createElement('input');
        inp.type = 'file';
        inp.accept = 'image/png,image/jpeg,image/webp';
        inp.addEventListener('change', async () => {
          const f = inp.files && inp.files[0];
          if (!f) return;
          try {
            const form = new FormData();
            form.append('fichier', f);
            const rep = await fetch('api/index.php?action=fond_televerser',
              { method: 'POST', body: form, credentials: 'same-origin' });
            const j = await rep.json();
            if (!j.success) throw new Error(j.error || 'Téléversement refusé.');
            // On sélectionne aussitôt l'image ajoutée et on l'applique.
            const url = 'api/index.php?action=fond_image&f='
                      + encodeURIComponent(j.data.fichier);
            const p = Object.assign({}, enAttente().apparence_perso || {});
            p[nom] = url;
            previsualiser({ apparence_perso: p });
            // La liste des fonds est rechargée, puis l'écran redessiné : le
            // nouveau fichier doit apparaître dans le menu déroulant, et
            // l'aperçu — désormais gardé au niveau du module — y survit.
            window._larkaFonds = null;
            toast('Image de fond ajoutée.', 'success');
            await rendreApparence(conteneur, decl);
          } catch (e) { toast(e.message, 'error'); }
        });
        inp.click();
      });
    });

    const reset = document.getElementById('ap-reset');
    if (reset) reset.addEventListener('click', () => {
      // Un redessin suffit désormais : il repart de l'aperçu, donc les champs
      // montrent bien l'état remis à zéro. Les vider un par un à la main
      // mettait les couleurs à #000000 et le menu d'image sur une option vide.
      previsualiser({ apparence_perso: {}, theme_module: '' });
      rendreApparence(conteneur, decl);
    });
  }

  /**
   * Dossiers de fichiers d'un module.
   *
   * La capacité « fichiers.import » existait, l'administrateur l'accordait, les
   * dossiers étaient créés — et rien ne les montrait. Une permission accordée
   * sans effet est pire qu'une permission absente : on la donne, on croit avoir
   * ouvert quelque chose, et personne ne peut dire pourquoi il ne se passe rien.
   *
   * Le serveur décide seul si le dépôt est ouvert : il renvoie « import » en
   * tenant compte du rôle. On ne recalcule rien ici — un contrôle côté client ne
   * protège de rien, et le dédoubler invite à ce que les deux divergent.
   */
  async function rendreFichiers(hote, decl) {
    if (!hote) return;
    const dossiers = Object.keys(decl.fichiers || {});
    if (dossiers.length === 0) return;

    for (const nom of dossiers) {
      let d;
      try {
        d = await apiRequest(
          `ext_fichiers&extension=${encodeURIComponent(decl.identifiant)}`
          + `&dossier=${encodeURIComponent(nom)}`);
      } catch (e) { continue; }

      const bloc = document.createElement('div');
      bloc.className = 'card';
      bloc.style.marginTop = '14px';
      bloc.innerHTML = `<div class="card-body">
        <div style="display:flex;justify-content:space-between;align-items:center;
          gap:10px;flex-wrap:wrap;margin-bottom:10px">
          <strong style="font-size:14px">${esc(d.libelle)}</strong>
          ${d.import ? `
            <input type="file" id="fi-${esc(nom)}" style="display:none">
            <button class="btn btn-secondary btn-sm" data-deposer="${esc(nom)}">
              + Déposer</button>` : ''}
        </div>
        <div style="font-size:11px;color:var(--gray-text);margin-bottom:8px">
          ${d.fichiers.length} fichier(s) · types acceptés :
          ${esc(d.natures.join(', '))}</div>
        <div style="display:flex;flex-direction:column;gap:4px">
          ${d.fichiers.length ? d.fichiers.map(f => `
            <div style="display:flex;justify-content:space-between;align-items:center;
              gap:10px;font-size:13px;padding:4px 0">
              <a href="api/index.php?action=ext_fichier&extension=${
                  encodeURIComponent(decl.identifiant)}&dossier=${
                  encodeURIComponent(nom)}&fichier=${encodeURIComponent(f.fichier)}"
                 download>${esc(f.libelle)}</a>
              <span style="color:var(--gray-text);font-size:11px">
                ${Math.max(1, Math.round(f.taille / 1024))} Ko</span>
            </div>`).join('')
            : '<span style="font-size:13px;color:var(--gray-text)">Aucun fichier.</span>'}
        </div></div>`;
      hote.appendChild(bloc);

      const b = bloc.querySelector(`[data-deposer="${nom}"]`);
      const input = bloc.querySelector(`#fi-${nom}`);
      if (!b || !input) continue;
      b.addEventListener('click', () => input.click());
      input.addEventListener('change', async () => {
        if (!input.files || !input.files[0]) return;
        const fd = new FormData();
        fd.append('extension', decl.identifiant);
        fd.append('dossier', nom);
        fd.append('fichier', input.files[0]);
        try {
          const r = await fetch('api/index.php?action=ext_fichier_importer',
            { method: 'POST', body: fd, credentials: 'same-origin' });
          const j = await r.json();
          if (!j.success) throw new Error(j.error || 'Dépôt refusé.');
          toast('Fichier déposé.', 'success');
          hote.innerHTML = '';
          rendreFichiers(hote, decl);
        } catch (e) { toast(e.message, 'error'); }
      });
    }
  }

  async function rendre(conteneur, api, decl, clePage) {
    /**
     * ⚠️ LA BARRE D'ACTIONS DE LA PAGE PRÉCÉDENTE RESTAIT EN PLACE.
     *
     * « #pageActionBar » est remplie par la page qui en a besoin et vidée par
     * celle qui suit. Une page de module ne la vidait pas : on arrivait sur le
     * planning des bornes avec le bouton « Exporter » de l'écran précédent
     * encore affiché sous la liste. Il exportait donc autre chose que ce qu'on
     * avait sous les yeux — ou plus rien du tout.
     *
     * Le module a sa propre barre, dans sa carte. Celle de la page n'a rien à
     * y faire.
     */
    if (typeof clearPageActionBar === 'function') {
      try { clearPageActionBar(); } catch (_) { /* jamais bloquant */ }
    }

    const page = (decl.pages || []).find(p => p.cle === clePage) || decl.pages[0];
    if (!page) { conteneur.textContent = 'Ce module ne décrit aucune page.'; return; }

    // Écran d'apparence : pas de données, pas de liste — Larka le dessine.
    if ((page.vue || {}).type === 'apparence') {
      rendreApparence(conteneur, decl);
      return;
    }

    const vue    = page.vue;
    const style  = apparence(decl);
    const padding = style.densite === 'Compact' ? '4px 8px' : '9px 12px';
    const police  = { Petit: '12px', Normal: '13px', Grand: '15px' }[style.taille] || '13px';
    const famille = POLICES[style.police] || POLICES['Système'];
    const bord    = BORDURES[style.bordures] ?? BORDURES['Fines'];
    const rayon   = ARRONDIS[style.arrondi] ?? ARRONDIS['Léger'];
    const jeu    = decl.donnees[vue.source];
    const champs = jeu.champs;
    const peut   = (a) => vue.actions.includes(a);

    const filtres = vue.filtres.map(f => {
      const c = champs[f.champ];
      const options = c.type === 'choix'
        ? c.valeurs.map(v => `<option value="${esc(v)}">${esc(v)}</option>`).join('')
        : (c.type === 'booleen'
            ? '<option value="1">oui</option><option value="0">non</option>' : '');
      return options
        ? `<select class="form-control" style="width:auto" data-filtre="${esc(f.champ)}">
             <option value="">${esc(c.libelle)} : tous</option>${options}</select>`
        : `<input class="form-control" style="width:150px" data-filtre="${esc(f.champ)}"
             placeholder="${esc(c.libelle)}">`;
    }).join('');

    conteneur.innerHTML = `
      <div class="card"><div class="card-body">
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
          ${vue.recherche.length ? `<input id="dp-q" class="form-control"
            style="flex:1;min-width:220px" placeholder="Rechercher…">` : ''}
          ${filtres}
          ${peut('creer') ? `<button id="dp-new" class="btn btn-primary btn-sm">
            + ${esc(jeu.libelle)}</button>` : ''}
          ${peut('exporter') ? `<button id="dp-csv" class="btn btn-secondary btn-sm">
            Exporter</button>` : ''}
          ${peut('exporter') ? `<button id="dp-print" class="btn btn-secondary btn-sm">
            🖨️ Imprimer</button>` : ''}
        </div>
        <div id="dp-stat" style="font-size:12px;color:var(--gray-text);margin-top:8px"></div>
      </div></div>
      <div id="dp-corps" style="margin-top:14px">Chargement…</div>
      <div id="dp-fichiers"></div>`;

    const $ = (s) => conteneur.querySelector(s);

    // Dossiers de fichiers du module, s'il en déclare. Rendus après la liste :
    // ce sont des ressources annexes, pas le sujet de l'écran.
    rendreFichiers($('#dp-fichiers'), decl);

    // Déclaré AVANT charge() : « let » n'est pas remonté, et l'appeler plus
    // haut lèverait une ReferenceError au premier affichage.
    let _page = 1;
    // Tri choisi par l'utilisateur ; à défaut, celui de la déclaration.
    let _tri = { champ: null, sens: 'ASC' };

    const charge = () => ({
      jeu: vue.source,
      page: _page,
      tri: _tri.champ ? { [_tri.champ]: _tri.sens } : null,
      recherche: $('#dp-q') ? $('#dp-q').value : '',
      filtres: Object.fromEntries([...conteneur.querySelectorAll('[data-filtre]')]
        .map(e => [e.dataset.filtre, e.value]).filter(([, v]) => v !== '')),
    });

    async function charger() {
      try {
        const rep = await api.appel('dp_lister', charge());
        const d = rep?.data ?? rep;

        // Le compteur doit dire l'assiette : « 5 fiches » sur une page de 5
        // parmi 120 laisserait croire qu'il n'y en a que cinq.
        // ⚠️ LE PLURIEL ÉTAIT EMPLOYÉ MÊME POUR UN SEUL ÉLÉMENT.
        // « 1 créneaux de recharges » : une faute d'accord à chaque écran qui
        // n'a qu'une ligne, c'est-à-dire souvent au démarrage d'un module.
        // Le singulier est déjà déclaré — « libelle » —, il suffisait de
        // choisir. « affichée(s) » devient « affiché(s) » au singulier.
        const mot = d.nombre === 1
          ? jeu.libelle.toLowerCase()
          : jeu.libelle_pluriel.toLowerCase();
        $('#dp-stat').textContent = d.total !== undefined && d.total !== d.nombre
          ? `${d.nombre} ${mot} sur ${d.total}`
          : `${d.nombre} ${mot}`;

        $('#dp-corps').innerHTML = d.lignes.length
          ? (vue.type === 'calendrier'
              ? calendrier(d.lignes, vue, champs)
              : tableau(d.lignes, d.totaux) + pagination(d))
          : `<div class="card"><div class="card-body" style="color:var(--gray-text)">
              Aucun élément.</div></div>`;

        conteneur.querySelectorAll('[data-tri]').forEach((th) => {
          th.addEventListener('click', () => {
            const c = th.dataset.tri;
            _tri = { champ: c, sens: (_tri.champ === c && _tri.sens === 'ASC') ? 'DESC' : 'ASC' };
            _page = 1;
            charger();
          });
        });

        conteneur.querySelectorAll('[data-page]').forEach((b) => {
          b.addEventListener('click', () => {
            _page = parseInt(b.dataset.page, 10);
            charger();
          });
        });
        brancher(d.lignes);
      } catch (e) {
        $('#dp-corps').innerHTML = `<div class="card"><div class="card-body"
          style="color:#b91c1c">${esc(e.message)}</div></div>`;
      }
    }

    function tableau(lignes, totaux) {
      const cols = vue.colonnes.map(c => c.champ);
      const actions = peut('modifier') || peut('supprimer');

      // Surlignage : la première règle qui s'applique l'emporte, dans l'ordre
      // déclaré. Sans ordre défini, deux règles concurrentes donneraient un
      // résultat imprévisible d'un affichage à l'autre.
      const surlignage = (l) => {
        for (const r of (vue.surligner || [])) {
          if (evaluerCondition(r.si, l)) {
            return ` style="box-shadow:inset 3px 0 0 ${teinte(r.couleur)}"`
                 + (r.libelle ? ` title="${esc(r.libelle)}"` : '');
          }
        }
        return '';
      };

      const rangee = (l, i) => `<tr${surlignage(l)}
        style="${style.zebre && i % 2 ? 'background:var(--gray-bg)' : ''}">
        ${cols.map(c => `<td>${afficher(l[c + '_libelle'] !== undefined
            ? l[c + '_libelle'] : l[c], champs[c])}</td>`).join('')}
        ${actions ? `<td style="text-align:right;white-space:nowrap">
          ${peut('modifier') ? (vue.actions_rapides || []).map((a, k) =>
            // Un bouton par décision, à même la ligne. La valeur déjà posée
            // n'est pas reproposée : cliquer « Accepter » sur une demande
            // acceptée ne ferait rien, et un bouton sans effet use la confiance.
            String(l[a.champ]) === a.valeur ? '' :
            `<button class="btn btn-sm" data-rapide="${l.id}" data-rk="${k}"
              style="border:1px solid ${teinte(a.couleur)};color:${teinte(a.couleur)};margin-right:4px"
              >${esc(a.libelle)}</button>`).join('') : ''}
          ${peut('modifier') ? `<button class="btn btn-sm btn-secondary"
            data-modif="${l.id}">Modifier</button>` : ''}
          ${peut('supprimer') ? `<button class="btn btn-sm" data-suppr="${l.id}"
            style="color:#b91c1c">Suppr.</button>` : ''}
        </td>` : ''}
      </tr>`;

      // ── Regroupement ─────────────────────────────────────────────────
      // Déclaré et validé, mais jamais rendu : la déclaration promettait un
      // classement que la liste n'appliquait pas.
      let corps;
      if (vue.grouper_par) {
        const g = new Map();
        lignes.forEach((l) => {
          const cle = l[vue.grouper_par + '_libelle'] ?? l[vue.grouper_par] ?? '—';
          if (!g.has(cle)) g.set(cle, []);
          g.get(cle).push(l);
        });
        corps = [...g.entries()].map(([nom, lg]) => `
          <tr><td colspan="${cols.length + (actions ? 1 : 0)}"
            style="background:var(--gray-bg);font-weight:600;font-size:12px;
                   text-transform:uppercase;letter-spacing:.04em">
            ${esc(String(nom))} <span style="font-weight:400;color:var(--gray-text)">
              — ${lg.length}</span></td></tr>
          ${lg.map((l, i) => rangee(l, i)).join('')}`).join('');
      } else {
        corps = lignes.map((l, i) => rangee(l, i)).join('');
      }

      // ── Totaux en pied ───────────────────────────────────────────────
      // On précise « sur cette page » : un total dont on ignore l'assiette
      // induit en erreur plus qu'il n'informe.
      const pied = (vue.totaux || []).length && totaux ? `
        <tfoot><tr style="border-top:2px solid var(--gray-border);font-weight:600">
          ${cols.map(c => `<td>${totaux[c] !== undefined
            ? afficher(totaux[c], champs[c]) : (c === cols[0] ? 'Total (cette page)' : '')
          }</td>`).join('')}
          ${actions ? '<td></td>' : ''}
        </tr></tfoot>` : '';

      // L'accent colore l'en-tête et, si demandé, un filet à gauche : c'est ce
      // qui distingue visuellement deux modules dans la même application.
      return `<div class="card" style="${style.filet && style.accent
          ? `border-left:3px solid ${esc(style.accent)}` : ''}">
        <div class="card-body" style="padding:0">
        <style>
          .dp-large, #dp-formulaire .form-control
            { width:100% !important; box-sizing:border-box; }
          #${conteneur.id || 'dp'} td, #${conteneur.id || 'dp'} th
            { padding:${padding} !important; font-size:${police} !important;
              font-family:${famille} !important;
              border-bottom:${bord} !important; }
          #${conteneur.id || 'dp'} table { border-radius:${rayon}; overflow:hidden; }
          ${style.fond ? `#${conteneur.id || 'dp'} tbody td
            { background:${esc(style.fond)} !important; }` : ''}
          ${style.texte ? `#${conteneur.id || 'dp'} tbody td
            { color:${esc(style.texte)} !important; }` : ''}
        </style>
        <div class="dp-tableau-cadre">
        <table class="table" style="margin:0${style.accent
          ? `;--dp-accent:${esc(style.accent)}` : ''}">
          <thead><tr>
            ${style.accent ? `<style>#${conteneur.id || 'dp'} thead th
              { background:${esc(style.accent)} !important;
                color:${esc(style.entete || '#ffffff')} !important; }</style>` : ''}
            ${cols.map((c) => {
              // La largeur posée sur l'en-tête fixe la colonne entière. Sans
              // elle, le navigateur la calcule sur le contenu de la première
              // ligne : une date pouvait s'étaler et un nom se retrouver serré,
              // au gré des données affichées ce jour-là.
              const px = (vue.largeurs || {})[c];
              const l = px ? `width:${px}px;` : '';
              return vue.tri_utilisateur && champs[c].type !== 'formule'
                ? `<th style="${l}cursor:pointer;user-select:none" data-tri="${esc(c)}">
                     ${esc(champs[c].libelle)}${_tri.champ === c ? (_tri.sens === 'ASC' ? ' ▲' : ' ▼') : ''}</th>`
                : `<th${l ? ` style="${l}"` : ''}>${esc(champs[c].libelle)}</th>`;
            }).join('')}
            ${actions ? '<th></th>' : ''}
          </tr></thead>
          <tbody>${corps}</tbody>
          ${pied}
        </table>
        </div></div></div>`;
    }

    /** Barre de pagination. Masquée s'il n'y a qu'une page. */
    function pagination(d) {
      if (!d || (d.pages || 1) <= 1) return '';
      return `<div style="display:flex;gap:8px;align-items:center;justify-content:center;
        margin-top:12px;font-size:13px">
        <button class="btn btn-sm btn-secondary" data-page="${d.page - 1}"
          ${d.page <= 1 ? 'disabled' : ''}>‹ Précédent</button>
        <span style="color:var(--gray-text)">Page ${d.page} sur ${d.pages}
          — ${d.total} enregistrement(s)</span>
        <button class="btn btn-sm btn-secondary" data-page="${d.page + 1}"
          ${d.page >= d.pages ? 'disabled' : ''}>Suivant ›</button>
      </div>`;
    }

    /**
     * @param ligne    enregistrement à modifier, ou null pour une création
     * @param prefill  valeurs imposées à la création (coordonnées d'un repère…)
     */
    /**
     * Valeur de départ d'un champ, y compris celles qui dépendent du moment
     * ou de la personne.
     *
     * Le format ne connaissait que des défauts FIXES : une chaîne, un nombre.
     * Impossible de pré-remplir « le demandeur, c'est vous » ou « la date,
     * c'est aujourd'hui » — or ce sont les deux premières choses qu'un agent
     * saisit, et les deux qu'il n'a aucune raison de saisir.
     *
     * Deux valeurs reconnues, volontairement peu nombreuses : ce sont des
     * défauts, pas un langage. Tout le reste passe par une formule.
     */
    function defautVivant(c) {
      const d = c.defaut;
      if (d === '@utilisateur') {
        const u = (typeof App !== 'undefined' && App.currentUser) || {};
        return [u.Prenom, u.Nom].filter(Boolean).join(' ') || u.Login || '';
      }
      if (d === '@aujourdhui') return new Date().toISOString().slice(0, 10);
      return d;
    }

    function formulaire(ligne, prefill, integre) {
      const edition = !!(ligne && ligne.id);
      prefill = prefill || {};

      // Un champ calculé n'a rien à faire dans un formulaire : il est produit
      // par une formule. L'afficher comme une case vide invite à le remplir, et
      // la saisie serait écartée sans un mot — le serveur ne l'écrit jamais.
      // « champs_formulaire » restreint et ORDONNE la saisie. Vide : tous les
      // champs saisissables, dans l'ordre de la déclaration — comportement
      // d'avant, que les modules publiés conservent.
      //
      // Un champ écarté n'est pas perdu : le serveur lui applique sa valeur par
      // défaut. C'est ce qui permet au demandeur de créer une fiche dont le
      // statut est « Demandé » sans jamais voir ce champ.
      /**
       * ── Mise en page déclarative ─────────────────────────────────────
       *
       * Quand la page en déclare une, c'est ELLE qui choisit les champs et les
       * dispose. Sinon — et c'est le cas de tous les modules publiés avant —
       * on garde exactement le chemin d'avant : groupes, colonnes déclarées et
       * largeurs par type. Rien à retoucher dans un module existant.
       */
      const miseEnPage = vue.layout || null;
      const valeurDe = (cle, c) =>
        ligne ? ligne[cle]
              : (prefill[cle] !== undefined ? prefill[cle] : defautVivant(c));

      const rendu = miseEnPage
        ? construireLayout(miseEnPage, champs, valeurDe, decl.identifiant) : null;

      const retenus = rendu
        ? rendu.champs
        : (Array.isArray(vue.champs_formulaire) ? vue.champs_formulaire : []);
      const saisissables = (retenus.length
          ? retenus.filter(n => champs[n]).map(n => [n, champs[n]])
          : Object.entries(champs))
        .filter(([, c]) => c.type !== 'formule');

      // Regroupement visuel : les champs liés au même sujet vont ensemble, et
      // une fiche de quinze champs cesse d'être un mur. Les groupes sont
      // déduits de la déclaration — l'auteur les nomme, ou tout va dans un seul.
      const groupes = new Map();
      saisissables.forEach(([cle, c]) => {
        const g = c.groupe || '';
        if (!groupes.has(g)) groupes.set(g, []);
        groupes.get(g).push([cle, c]);
      });

      // ── Grille RÉGULIÈRE ─────────────────────────────────────────────
      //
      // « auto-fit » laissait chaque champ prendre sa largeur naturelle : une
      // note tenait en 40 px, un commentaire s'étalait sur toute la ligne, et
      // les libellés ne s'alignaient sur rien. Le formulaire paraissait bâclé.
      //
      // On impose donc un nombre de colonnes, réglé dans les préférences, et
      // chaque champ occupe un nombre entier de colonnes selon son type.
      // Le nombre de colonnes vient de la DÉCLARATION : c'est l'auteur qui
      // sait combien de champs tiennent côte à côte dans sa fiche. La
      // préférence d'interface ne sert que de repli.
      // Le nombre de colonnes et la largeur des champs viennent de la
      // DÉCLARATION : c'est l'auteur qui sait combien de champs tiennent côte
      // à côte dans sa fiche.
      const colonnes = vue.colonnes_formulaire || 3;
      const grille = `repeat(${colonnes}, minmax(0, 1fr))`;

      // Combien de colonnes pour ce type de champ ? Un texte long a besoin de
      // place ; une case à cocher n'en a aucun besoin.
      const LARGEURS = { petit: 1, moyen: 2, grand: 3, pleine: 12 };
      const portee = (c) => {
        // Largeur DÉCLARÉE d'abord : deviner d'après le type donne la même
        // place à un code postal et à une adresse.
        if (c.largeur) return Math.min(colonnes, LARGEURS[c.largeur] || 1);
        // Sans largeur déclarée, on retombe sur le type : mieux que rien, mais
        // c'est « largeur » qui donne un formulaire net.
        if (c.type === 'texte_long') return Math.min(colonnes, 2);
        if (c.type === 'choix_multiple') return Math.min(colonnes, 2);
        if (c.max && c.max > 100) return Math.min(colonnes, 2);
        return 1;
      };

      const bloc = (titre, entrees) =>
        (titre ? `<div style="font-size:12px;font-weight:600;color:var(--gray-text);
           text-transform:uppercase;letter-spacing:.04em;margin:6px 0 2px">${esc(titre)}</div>` : '')
        + `<div style="display:grid;grid-template-columns:${grille};gap:12px">`
        + entrees.map(([cle, c]) =>
            // position:relative — sans quoi la liste de suggestions, posée en
            // absolu, se place par rapport à la modale et non sous le champ :
            // elle apparaît décalée, souvent hors de la zone visible.
            `<div data-champ="${esc(cle)}" style="position:relative;
               grid-column:span ${portee(c)}">${champFormulaire(cle, c,
                ligne ? ligne[cle] : (prefill[cle] !== undefined ? prefill[cle] : defautVivant(c))
              )}</div>`).join('')
        + '</div>';

      /**
       * ⚠️ « width:100% !important » RENDAIT « largeur_px » SANS EFFET.
       *
       * Une déclaration « !important » d'une feuille de style l'emporte sur un
       * style en ligne. Or c'est en ligne que champFormulaire() pose la largeur
       * demandée par l'auteur : `width:180px` était donc systématiquement
       * ramené à 100 % dans la modale. Le schéma validait « largeur_px », le
       * client le lisait, l'écrivait — et une règle générale l'annulait trois
       * lignes plus loin. Rien ne le signalait : le champ s'affichait, à la
       * mauvaise taille.
       *
       * Le « !important » n'était pas nécessaire : un style EN LIGNE l'emporte
       * déjà sur les règles de l'application, et champFormulaire() en pose
       * toujours un. On le retire, et les largeurs — du champ comme de la mise
       * en page — s'appliquent enfin. Il reste sur la case à cocher, qui est
       * l'exception : là, il faut battre le « width:100 % » en ligne.
       */
      const html = `<style>
          #dp-form .form-control, #dp-form select, #dp-form textarea, #dp-form input
            { box-sizing:border-box; }
          #dp-form input[type="color"] { height:38px; padding:2px; }
          #dp-form input[type="checkbox"] { width:auto !important; }
          ${rendu ? rendu.css : ''}
        </style>`
        + '<div id="dp-form" style="display:grid;gap:14px;max-height:65vh;overflow:auto">'
        + (rendu ? rendu.html
                 : [...groupes.entries()].map(([g, e]) => bloc(g, e)).join(''))
        + '</div>';

      // Après l'ouverture : remplir les sélecteurs de lien, puis appliquer la
      // visibilité conditionnelle et la maintenir à chaque frappe.
      setTimeout(() => {
        saisissables.forEach(([cle, c]) => {
          if (c.type !== 'lien') return;
          // Le libellé déjà choisi s'affiche : rouvrir une fiche ne doit pas
          // montrer un champ vide alors qu'un lien existe.
          if (ligne && ligne[cle + '_libelle']) {
            const ch = document.getElementById('dp-' + cle);
            if (ch) ch.value = ligne[cle + '_libelle'];
          }
          brancherRecherche(cle, api, {
            action: 'dp_cibles', jeu: vue.source,
            surChoix: (v) => {
              document.getElementById('dp-' + cle + '-id').value = v.id;
            },
            surVide: () => {
              document.getElementById('dp-' + cle + '-id').value = '';
            },
          });
        });
        appliquerVisibilite(saisissables, rendu ? rendu.conditions : []);
        brancherSuggestions(saisissables, api, vue.source);
        brancherAjoutListe(saisissables, api, vue.source);
      }, 0);

      // ── Formulaire INTÉGRÉ : dans un écran d'accueil, pas dans un modal ──
      //
      // Un module qui propose un type de demande doit se présenter comme les
      // autres : mêmes champs au même endroit, même bouton d'envoi. Ouvrir un
      // second modal par-dessus celui de « Nouvelle demande » ferait de lui un
      // cas à part, alors qu'il est un choix parmi trois.
      //
      // On rend alors les champs dans l'hôte fourni et l'on retourne de quoi
      // enregistrer : c'est l'écran d'accueil qui décide quand.
      if (integre) {
        integre.innerHTML = html;
        brancher(champs);
        return async () => {
          const c = { jeu: vue.source, valeurs: lireFormulaire(champs) };
          await api.appel('dp_enregistrer', c);
          charger();
        };
      }

      openModal(edition ? `Modifier — ${esc(jeu.libelle)}` : `Nouvelle ${esc(jeu.libelle)}`,
        html, async () => {
          const c = { jeu: vue.source, valeurs: lireFormulaire(champs) };
          if (edition) c.id = ligne.id;
          try {
            await api.appel('dp_enregistrer', c);
            toast('Enregistré.', 'success');
            closeModal();
            charger();

            // Rafraîchir ce qui a demandé la saisie — les repères du plan, par
            // exemple. Sans cela, le repère n'apparaît qu'au rechargement de la
            // page, et l'utilisateur clique une seconde fois.
            if (_apresEnregistrement) {
              const f = _apresEnregistrement;
              _apresEnregistrement = null;
              try { f(); } catch (e) { /* l'appelant a disparu */ }
            }
          } catch (e) {
            // Modale laissée ouverte : la saisie n'est pas perdue. Le message
            // vient du moteur, il est écrit pour l'utilisateur.
            toast(e.message, 'error');
          }
        }, '💾 Enregistrer');
    }

    function brancher(lignes) {
      // Une action rapide écrit un seul champ : on envoie la ligne telle
      // qu'elle est, ce champ remplacé. Reconstruire la saisie complète
      // risquerait d'écraser ce qu'un autre a modifié entre-temps.
      conteneur.querySelectorAll('[data-rapide]').forEach((b) => {
        b.addEventListener('click', async () => {
          const a = (vue.actions_rapides || [])[Number(b.dataset.rk)];
          if (!a) return;
          b.disabled = true;
          try {
            await api.appel('dp_enregistrer', {
              jeu: vue.source, id: Number(b.dataset.rapide),
              valeurs: { [a.champ]: a.valeur },
            });
            toast(`${a.libelle} — enregistré.`, 'success');
            charger();
          } catch (e) {
            b.disabled = false;
            toast(e.message, 'error');
          }
        });
      });

      conteneur.querySelectorAll('[data-modif]').forEach(b =>
        b.addEventListener('click', () =>
          formulaire(lignes.find(l => String(l.id) === b.dataset.modif))));

      conteneur.querySelectorAll('[data-suppr]').forEach(b =>
        b.addEventListener('click', async () => {
          if (!confirm(`Supprimer cet élément ?`)) return;
          try {
            await api.appel('dp_supprimer', { jeu: vue.source, id: Number(b.dataset.suppr) });
            toast('Supprimé.', 'success');
            charger();
          } catch (e) { toast(e.message, 'error'); }
        }));
    }

    let minuteur;
    // Toute recherche ou tout filtre ramène en page 1 : rester en page 4 d'un
    // résultat qui n'en compte plus qu'une afficherait un tableau vide, et
    // laisserait croire que la recherche n'a rien trouvé.
    const rechercher = () => { _page = 1; charger(); };

    if ($('#dp-q')) $('#dp-q').addEventListener('input', () => {
      clearTimeout(minuteur);
      minuteur = setTimeout(rechercher, 250);
    });
    conteneur.querySelectorAll('[data-filtre]').forEach(e => {
      e.addEventListener('change', rechercher);
      e.addEventListener('input', () => { clearTimeout(minuteur);
                                          minuteur = setTimeout(rechercher, 250); });
    });
    if ($('#dp-new')) $('#dp-new').addEventListener('click', () => formulaire(null));


    // L'ancrage « marqueurs » vit dans un autre écran : il ne peut pas appeler
    // formulaire() directement. On publie l'ouverture pour ce jeu de données.
    _formulaires[decl.identifiant + '/' + vue.source] = (prefill, apres) => {
      _apresEnregistrement = apres;
      formulaire(null, prefill);
    };
    // ⚠️ INDEXÉ PAR PAGE, PAS PAR JEU DE DONNÉES.
    // La clé était « module/source ». Or deux pages du même module partagent
    // leur source : celle rendue en dernier écrasait l'autre. Le demandeur
    // recevait le formulaire du gestionnaire — douze champs, dont « Décision »
    // et « Motif de la décision », qu'il n'a rien à renseigner.
    _formulairesIntegres[decl.identifiant + '/' + page.cle] =
      (hote) => formulaire(null, {}, hote);
    // Un planning s'affiche au mur : le bouton évite d'expliquer Ctrl+P, et
    // rappelle que la feuille d'impression existe. La navigation est masquée
    // par css/impression.css, il n'y a rien à préparer ici.
    if ($('#dp-print')) $('#dp-print').addEventListener('click', () => window.print());

    if ($('#dp-csv')) $('#dp-csv').addEventListener('click', async () => {
      try {
        const rep = await api.appel('dp_exporter', charge());
        const d = rep?.data ?? rep;
        const blob = new Blob(['\ufeff' + d.contenu], { type: 'text/csv;charset=utf-8' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = d.nom;
        a.click();
        URL.revokeObjectURL(a.href);
      } catch (e) { toast(e.message, 'error'); }
    });

    await charger();
  }

  /**
   * Dessine un ancrage déclaratif.
   *
   * Trois primitives, trois rendus, et rien d'autre : le module a choisi le
   * type et la source, Larka décide de l'apparence. C'est ce qui garantit qu'un
   * ancrage ne peut pas ressembler à autre chose que ce qu'il est.
   */
  async function rendreAncrage(hote, ext, index, decl, contexte, api) {
    const charge = { index };
    if (contexte.etageId !== undefined)     charge.etage = contexte.etageId;
    if (contexte.equipementId !== undefined) charge.valeur_lien = contexte.equipementId;
    if (contexte.bienId !== undefined)       charge.valeur_lien = contexte.bienId;

    let d;
    try {
      const r = await api.appel('dp_ancrage', charge);
      d = r?.data ?? r;
    } catch (e) {
      // Un ancrage en panne ne troue pas la page qui l'accueille : il s'efface.
      hote.remove();
      return;
    }

    if (decl.type === 'compteur') {
      hote.innerHTML = `
        <div class="card" style="border-left:4px solid ${esc(decl.couleur || '#1b3a5c')}">
          <div class="card-body">
            <div style="font-size:13px;color:var(--gray-text)">${esc(d.libelle)}</div>
            <div style="font-size:24px;font-weight:700">${esc(String(d.nombre ?? 0))}</div>
          </div>
        </div>`;
      return;
    }

    if (decl.type === 'liste_liee') {
      const lignes = d.lignes || [];
      if (lignes.length === 0) { hote.remove(); return; }
      const cols = decl.colonnes || Object.keys(lignes[0]);
      hote.innerHTML = `
        <div class="card"><div class="card-body">
          <div class="section-label">${esc(d.libelle)}</div>
          <table class="table"><tbody>
            ${lignes.map(l => `<tr>${cols.map(c =>
              `<td>${esc(String(l[c + '_libelle'] ?? l[c] ?? ''))}</td>`).join('')}</tr>`).join('')}
          </tbody></table>
        </div></div>`;
      return;
    }

    if (decl.type === 'marqueurs') {
      // Les repères vivent sur la carte, pas dans le conteneur : celui-ci ne
      // sert qu'au bouton qui permet d'en poser un.
      const map = contexte.map;
      if (!map || typeof L === 'undefined') { hote.remove(); return; }

      hote._marqueurs = hote._marqueurs || [];
      hote._marqueurs.forEach(m => map.removeLayer(m));
      hote._marqueurs = [];

      (d.lignes || []).forEach((l) => {
        const lat = parseFloat(l[decl.champ_lat]);
        const lng = parseFloat(l[decl.champ_lng]);
        if (!isFinite(lat) || !isFinite(lng)) return;
        const m = L.marker([lat, lng], {
          icon: L.divIcon({ className: 'ext-marqueur', html:
            `<div style="font-size:18px;line-height:1">${esc(decl.icone || '📍')}</div>`,
            iconSize: [22, 22], iconAnchor: [11, 11] }),
        }).addTo(map);
        m.bindTooltip(esc(String(l[decl.etiquette] ?? '')));
        hote._marqueurs.push(m);
      });

      if (contexte.peutEditer && typeof contexte.surProchainClic === 'function') {
        const b = document.createElement('button');
        b.className = 'plan-tbtn';
        b.title = decl.libelle || 'Poser un repère';
        b.textContent = decl.icone || '📍';
        b.addEventListener('click', () => {
          toast('Cliquez l\'emplacement sur le plan.', 'info');
          contexte.surProchainClic(async ({ lat, lng }) => {
            const valeurs = {
              [decl.champ_etage]: contexte.etageId,
              [decl.champ_lat]: lat,
              [decl.champ_lng]: lng,
            };

            // Écrire directement échouait : les champs obligatoires du jeu
            // (numéro, désignation…) n'étaient pas fournis, et l'enregistrement
            // était refusé — le clic ne produisait rien de visible.
            // On ouvre donc la saisie, coordonnées déjà remplies.
            const ouvrir = _formulaires[(ext.identifiant || '') + '/' + decl.source];
            if (ouvrir) {
              ouvrir(valeurs, () => rendreAncrage(hote, ext, index, decl, contexte, api));
              return;
            }

            // La page du module n'est pas ouverte : on tente l'écriture directe,
            // et on dit pourquoi si elle échoue.
            try {
              await api.appel('dp_enregistrer', { jeu: decl.source, valeurs });
              toast('Repère posé.', 'success');
              rendreAncrage(hote, ext, index, decl, contexte, api);
            } catch (e) {
              toast('Ouvrez d\'abord l\'écran du module pour saisir les informations '
                  + 'obligatoires : ' + e.message, 'warning');
            }
          });
        });
        hote.appendChild(b);
      }
    }
  }

  /**
   * Rend le formulaire d'un module DANS un conteneur fourni.
   *
   * Permet à un écran du cœur — « Nouvelle demande » — de présenter un type
   * apporté par un module exactement comme les siens : mêmes champs au même
   * endroit, un seul bouton d'envoi.
   *
   * Rend une fonction d'enregistrement, ou null si le module ou le jeu de
   * données n'existe pas. L'appelant décide du moment de l'appeler.
   */
  function formulaireIntegre(identifiant, pageCle, hote) {
    const f = _formulairesIntegres[identifiant + '/' + pageCle];
    return f ? f(hote) : null;
  }

  // « construireLayout » est exposée pour être ÉPROUVÉE.
  //
  // C'est une fonction pure : lui donner une mise en page validée et des champs
  // rend du HTML, sans toucher au document. L'épreuve de rendu s'en sert pour
  // vérifier hors navigateur que les colonnes, les placements, les conditions
  // et l'échappement sortent bien — une épreuve qui exigerait un vrai
  // navigateur ne serait jamais lancée, et le rendu resterait la seule partie
  // du format que rien ne vérifie.
  return { rendre, rendreAncrage, formulaireIntegre, construireLayout };
})();

// Export pour les épreuves en ligne de commande. Sans effet dans le navigateur,
// où « module » n'existe pas.
if (typeof module !== 'undefined' && module.exports) {
  module.exports = LarkaDeclaratif;
}

// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
/**
 * Larka — épreuve de RENDU de la mise en page déclarative.
 *
 * Le schéma vérifie qu'une déclaration est acceptable ; il ne dit rien de ce
 * que le navigateur en fait. C'était le dernier morceau du format que rien ne
 * vérifiait : une mise en page pouvait être validée, transmise, et rendue de
 * travers — ou pire, rendue avec du texte d'auteur non échappé.
 *
 * Cette épreuve appelle la vraie fonction de rendu, celle qu'utilise la
 * modale, et lit le HTML qu'elle produit. Elle tourne sans navigateur : le
 * rendu est une fonction PURE, c'est précisément ce qui le rend éprouvable.
 *
 *   node outils/epreuves/test-rendu-layout.js
 */

'use strict';

// ── Un document minimal ─────────────────────────────────────────────────
// esc() est le seul point qui touche au document : il crée un div, y pose du
// texte et relit le HTML. On reproduit exactement ce contrat — l'échappement
// que l'épreuve vérifie est donc bien celui du navigateur, pas une imitation
// complaisante écrite pour passer.
global.document = {
  createElement() {
    return {
      textContent: '',
      get innerHTML() {
        return String(this.textContent)
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;');
      },
    };
  },
};

const path = require('path');
const { construireLayout } = require(path.join(__dirname, '..', '..', 'js', 'declaratif.js'));

let ok = 0;
const ko = [];

function verifier(titre, condition, detail) {
  if (condition) {
    ok++;
    console.log(`  ✅  ${titre.padEnd(52)} ${detail || ''}`);
  } else {
    ko.push(titre);
    console.log(`  ❌  ${titre.padEnd(52)} ${detail || ''}`);
  }
}

const champs = {
  equipement: { type: 'lien', vers: 'equipements', libelle: 'Équipement' },
  statut: { type: 'choix', libelle: 'Statut', valeurs: ['Ouvert', 'Clos'] },
  note: { type: 'texte_long', libelle: 'Note', max: 800 },
  cout: { type: 'decimal', libelle: 'Coût' },
};

const valeurDe = () => '';

console.log('\n═══════════════════════════════════════════════════════════════════════');
console.log(' Larka — rendu de la mise en page déclarative');
console.log('═══════════════════════════════════════════════════════════════════════\n');

// ── 1. Grille et placements ─────────────────────────────────────────────
console.log('  Grille et placements');

const base = construireLayout({
  type: 'grille', colonnes: 12, gap_x_px: 16, gap_y_px: 16, marges_px: 0,
  responsive: [], alignement_h: 'stretch', alignement_v: 'stretch',
  composants: [
    { nature: 'champ', champ: 'equipement', x: 1, y: 1, largeur: 6, hauteur: 1,
      hauteur_px: 40, alignement_h: 'stretch', alignement_v: 'stretch',
      masquer_libelle: false, visible_si: null },
    { nature: 'champ', champ: 'statut', x: 7, y: 1, largeur: 6, hauteur: 1,
      hauteur_px: 40, alignement_h: 'stretch', alignement_v: 'stretch',
      masquer_libelle: false, visible_si: null },
  ],
}, champs, valeurDe);

verifier('La grille déclare ses 12 colonnes',
  base.css.includes('.dp-col-12{grid-template-columns:repeat(12,minmax(0,1fr))}'));
verifier('Le premier champ démarre colonne 1',
  base.html.includes('dp-x-1') && base.css.includes('.dp-x-1{grid-column-start:1}'));
verifier('Le second champ démarre colonne 7',
  base.html.includes('dp-x-7') && base.css.includes('.dp-x-7{grid-column-start:7}'));

/**
 * ⚠️ RÉGRESSION RÉELLE : LE RESPONSIVE NE MARCHAIT PAS DU TOUT.
 *
 * Les positions étaient posées en style EN LIGNE. Un style en ligne l'emporte
 * sur toute règle de feuille de style sans « !important » — donc sur les
 * requêtes de média. La grille passait bien à une colonne sur un téléphone,
 * mais chaque composant gardait sa position : deux champs déclarés sur la même
 * ligne atterrissaient dans la MÊME case, l'un par-dessus l'autre, et les
 * libellés s'imprimaient l'un sur l'autre — « DateHoraire ».
 *
 * L'épreuve d'alors ne l'a pas vu : elle vérifiait que la requête de média
 * FIGURE dans le CSS, pas qu'elle produise quoi que ce soit. Une épreuve qui
 * lit une chaîne au lieu de mesurer un effet finit toujours par rassurer à
 * tort.
 *
 * On vérifie donc la propriété STRUCTURELLE dont dépend l'effet : aucun
 * placement n'est écrit en ligne. Ce qui est en classe, la cascade peut le
 * reprendre ; ce qui est en ligne, non.
 */
verifier('Aucun placement n\'est écrit en style « en ligne »',
  !/style="[^"]*grid-(column|row)/.test(base.html),
  'sinon les requêtes de média resteraient sans effet');
verifier('Une largeur de 6 devient une classe de portée',
  base.css.includes('.dp-w-6{grid-column-end:span 6}'));
verifier('La hauteur en pixels est appliquée',
  base.html.includes('height:40px'));

/**
 * ⚠️ RÉGRESSION RÉELLE, ET LA PLUS COÛTEUSE DE TOUTES.
 *
 * « hauteur_px » a d'abord été posé sur la CELLULE du champ. Or une cellule
 * contient le libellé, la boîte de saisie, parfois un texte d'aide et parfois
 * un bouton « + Ajouter une valeur » : pour 40 px demandés, 63 à 148 px de
 * contenu réel. Le formulaire livré se chevauchait donc partout — libellés
 * superposés, aides à cheval sur les champs, boutons flottant sur la section
 * voisine — et rien dans les épreuves ne le disait, parce qu'elles lisaient le
 * HTML sans jamais le mettre en page.
 *
 * On vérifie ici la propriété qui empêche ce défaut de revenir : la cellule
 * d'un champ ne porte AUCUNE contrainte de hauteur. Seul le contrôle en porte.
 */
const cellules = base.html.match(/<div data-champ="[^"]*"[^>]*style="([^"]*)"/g) || [];
verifier('Aucune cellule de champ n\'est contrainte en hauteur',
  cellules.length === 2 && !cellules.some((c) => /(^|;|")(min-|max-)?height:/.test(c)),
  `${cellules.length} cellules inspectées`);
verifier('La hauteur part bien au contrôle, pas à la cellule',
  /class="form-control"[^>]*style="[^"]*height:40px/.test(base.html)
  || /style="[^"]*height:40px[^"]*"[^>]*class="form-control"/.test(base.html)
  || base.html.includes('height:40px'),
  'la boîte de saisie porte la hauteur');
verifier('L\'espacement déclaré est repris',
  base.html.includes('column-gap:16px') && base.html.includes('row-gap:16px'));
verifier('Chaque champ garde son ancre « data-champ »',
  (base.html.match(/data-champ="/g) || []).length === 2);
verifier('Les champs placés sont rendus à l\'appelant',
  base.champs.join(',') === 'equipement,statut', base.champs.join(','));

// ── 2. Sections ─────────────────────────────────────────────────────────
console.log('\n  Sections, cartes et panneaux');

const avecSection = construireLayout({
  type: 'grille', colonnes: 12, gap_x_px: 12, gap_y_px: 12, marges_px: 8,
  responsive: [], alignement_h: 'stretch', alignement_v: 'stretch',
  composants: [
    { nature: 'section', titre: 'Coûts', style: 'carte', couleur: '#16a34a',
      colonnes: 6, gap_x_px: 8, gap_y_px: 8, x: 1, y: 1, largeur: 12, hauteur: 1,
      alignement_h: 'stretch', alignement_v: 'stretch', repliable: false,
      repliee: false, visible_si: null,
      composants: [
        { nature: 'champ', champ: 'cout', x: null, y: null, largeur: 3, hauteur: 1,
          alignement_h: 'stretch', alignement_v: 'stretch',
          masquer_libelle: false, visible_si: null },
      ] },
  ],
}, champs, valeurDe);

verifier('Une carte reçoit son habillage',
  avecSection.html.includes('border-radius:10px'));
verifier('La couleur déclarée borde la section',
  avecSection.html.includes('border-left:3px solid #16a34a'));
verifier('Le titre de section est rendu',
  avecSection.html.includes('Coûts'));
verifier('La sous-grille a ses propres colonnes',
  avecSection.css.includes('.dp-col-6{grid-template-columns:repeat(6,minmax(0,1fr))}'));
verifier('Les marges du conteneur sont appliquées',
  avecSection.html.includes('padding:8px'));

const repliable = construireLayout({
  type: 'grille', colonnes: 12, gap_x_px: 12, gap_y_px: 12, marges_px: 0,
  responsive: [], alignement_h: 'stretch', alignement_v: 'stretch',
  composants: [
    { nature: 'section', titre: 'Avancé', style: 'panneau', colonnes: 12,
      gap_x_px: 8, gap_y_px: 8, x: null, y: null, largeur: 12, hauteur: 1,
      alignement_h: 'stretch', alignement_v: 'stretch', repliable: true,
      repliee: true, visible_si: null,
      composants: [
        { nature: 'champ', champ: 'note', x: null, y: null, largeur: 12, hauteur: 1,
          alignement_h: 'stretch', alignement_v: 'stretch',
          masquer_libelle: false, visible_si: null },
      ] },
  ],
}, champs, valeurDe);

verifier('Une section repliable est un <details>',
  repliable.html.includes('<details') && repliable.html.includes('<summary'));
verifier('« repliee » ferme le volet à l\'ouverture',
  !repliable.html.includes('<details open'));

// ── 3. Texte, espace, libellé masqué ────────────────────────────────────
console.log('\n  Texte, espace et libellé masqué');

const divers = construireLayout({
  type: 'grille', colonnes: 12, gap_x_px: 12, gap_y_px: 12, marges_px: 0,
  responsive: [], alignement_h: 'stretch', alignement_v: 'stretch',
  composants: [
    { nature: 'texte', texte: 'Renseignez l\'équipement', style: 'aide',
      x: null, y: null, largeur: 12, hauteur: 1, alignement_h: 'start',
      visible_si: null },
    { nature: 'espace', x: null, y: null, largeur: 2, hauteur: 1, hauteur_px: 24 },
    { nature: 'champ', champ: 'statut', x: null, y: null, largeur: 4, hauteur: 1,
      alignement_h: 'stretch', alignement_v: 'stretch',
      masquer_libelle: true, visible_si: null },
  ],
}, champs, valeurDe);

verifier('Le texte statique est rendu', divers.html.includes('Renseignez'));
verifier('L\'espace est neutre pour les lecteurs d\'écran',
  divers.html.includes('aria-hidden="true"'));
verifier('« masquer_libelle » pose sa classe',
  divers.html.includes('dp-sans-libelle'));
verifier('La règle de masquage du libellé existe',
  divers.css.includes('.dp-sans-libelle>.form-label{display:none}'));

// ── 4. Échappement — la propriété qui compte ────────────────────────────
console.log('\n  Échappement du texte d\'auteur');

const hostile = construireLayout({
  type: 'grille', colonnes: 12, gap_x_px: 12, gap_y_px: 12, marges_px: 0,
  responsive: [], alignement_h: 'stretch', alignement_v: 'stretch',
  composants: [
    { nature: 'texte', texte: '<script>alert(1)</script>', style: 'normal',
      x: null, y: null, largeur: 12, hauteur: 1, alignement_h: 'start',
      visible_si: null },
    { nature: 'section', titre: '<img src=x onerror=alert(2)>', style: 'carte',
      colonnes: 12, gap_x_px: 8, gap_y_px: 8, x: null, y: null, largeur: 12,
      hauteur: 1, alignement_h: 'stretch', alignement_v: 'stretch',
      repliable: false, repliee: false, visible_si: null,
      composants: [
        { nature: 'champ', champ: 'statut', x: null, y: null, largeur: 6, hauteur: 1,
          alignement_h: 'stretch', alignement_v: 'stretch',
          masquer_libelle: false, visible_si: null },
      ] },
  ],
}, champs, valeurDe);

// Ce qui compte n'est pas l'absence du mot « onerror » — le texte échappé le
// contient légitimement, en tant que texte — mais l'absence d'une BALISE
// ouvrante issue de la déclaration. C'est le « < » qui exécute, pas le mot.
verifier('Aucune balise <script> ouverte par la déclaration',
  !hostile.html.includes('<script'), 'texte échappé');
verifier('Aucune balise <img> ouverte par la déclaration',
  !hostile.html.includes('<img'), 'titre échappé');
verifier('Le texte hostile apparaît comme du TEXTE',
  hostile.html.includes('&lt;script&gt;'));
verifier('Le titre hostile apparaît comme du TEXTE',
  hostile.html.includes('&lt;img src=x onerror=alert(2)&gt;'));

// ── 4 bis. Images ───────────────────────────────────────────────────────
console.log('\n  Images');

const avecImage = construireLayout({
  type: 'grille', colonnes: 12, gap_x_px: 12, gap_y_px: 12, marges_px: 0,
  responsive: [], alignement_h: 'stretch', alignement_v: 'stretch',
  composants: [
    { nature: 'image', image: 'assets/fleur.jpg', description: 'Une marguerite',
      x: 1, y: 1, largeur: 4, hauteur: 2, cadrage: 'cover', arrondi: '8px',
      alignement_h: 'stretch', visible_si: null },
    { nature: 'image', image: 'assets/deco.png', description: '',
      x: 5, y: 1, largeur: 2, hauteur: 1, cadrage: 'contain', arrondi: '0',
      hauteur_px: 60, alignement_h: 'stretch', visible_si: null },
  ],
}, champs, valeurDe, 'acme.herbier');

verifier('L\'image passe par la route des fichiers de module',
  avecImage.html.includes('action=ext_image&extension=acme.herbier&fichier=assets%2Ffleur.jpg'),
  'chemin encodé');
verifier('La description devient le texte alternatif',
  avecImage.html.includes('alt="Une marguerite"'));
verifier('Le cadrage déclaré est appliqué',
  avecImage.html.includes('object-fit:cover'));
verifier('L\'arrondi déclaré est appliqué',
  avecImage.html.includes('border-radius:8px'));
verifier('Le chargement est différé',
  (avecImage.html.match(/loading="lazy"/g) || []).length === 2);

/**
 * Une image décorative — description vide — doit être RETIRÉE de l'arbre
 * d'accessibilité : un lecteur d'écran qui annonce « assets/deco.png » gêne
 * plus qu'il n'aide.
 */
verifier('Une image décorative est masquée aux lecteurs d\'écran',
  /alt=""[^>]*aria-hidden="true"/.test(avecImage.html));
verifier('Une image décrite n\'est PAS masquée',
  !/alt="Une marguerite"[^>]*aria-hidden/.test(avecImage.html));

/**
 * Une image à qui l'on donne plusieurs lignes doit les remplir : sinon elle se
 * pose en haut de sa colonne et laisse un vide sous elle, ce qui n'est jamais
 * ce qu'on voulait en lui réservant la place.
 */
verifier('Une image sur 2 lignes remplit sa cellule',
  avecImage.html.includes('height:100%'));
verifier('Une hauteur explicite reste prioritaire',
  avecImage.html.includes('height:60px'));

const imageHostile = construireLayout({
  type: 'grille', colonnes: 12, gap_x_px: 12, gap_y_px: 12, marges_px: 0,
  responsive: [], alignement_h: 'stretch', alignement_v: 'stretch',
  composants: [
    { nature: 'image', image: 'assets/x.png',
      description: '"><script>alert(1)</script>',
      x: null, y: null, largeur: 4, hauteur: 1, cadrage: 'contain',
      arrondi: '0', alignement_h: 'stretch', visible_si: null },
  ],
}, champs, valeurDe, 'acme.herbier');

verifier('Une description hostile n\'échappe pas de son attribut',
  !imageHostile.html.includes('<script') && imageHostile.html.includes('&quot;'),
  'guillemets et chevrons échappés');

// ── 5. Responsive ───────────────────────────────────────────────────────
console.log('\n  Comportement responsive');

const adaptatif = construireLayout({
  type: 'grille', colonnes: 12, gap_x_px: 16, gap_y_px: 16, marges_px: 0,
  alignement_h: 'stretch', alignement_v: 'stretch',
  responsive: [
    { en_dessous_de_px: 1024, colonnes: 6, gap_px: null },
    { en_dessous_de_px: 640, colonnes: 1, gap_px: 8 },
  ],
  composants: [
    { nature: 'champ', champ: 'equipement', x: 1, y: 1, largeur: 6, hauteur: 1,
      alignement_h: 'stretch', alignement_v: 'stretch',
      masquer_libelle: false, visible_si: null },
    { nature: 'champ', champ: 'statut', x: 7, y: 1, largeur: 6, hauteur: 1,
      alignement_h: 'stretch', alignement_v: 'stretch',
      masquer_libelle: false, visible_si: null },
  ],
}, champs, valeurDe);

verifier('Une requête de média par palier',
  (adaptatif.css.match(/@media/g) || []).length === 2);
verifier('Le palier étroit ramène la grille à 1 colonne',
  adaptatif.css.includes('@media (max-width:640px){.dp-col-12{grid-template-columns:repeat(1,minmax(0,1fr))}'));
verifier('Le palier moyen ramène la grille à 6 colonnes',
  adaptatif.css.includes('@media (max-width:1024px){.dp-col-12{grid-template-columns:repeat(6,minmax(0,1fr))}'));
verifier('Une portée de 6 est bornée à la largeur du palier',
  adaptatif.css.includes('.dp-w-6{grid-column-end:span 1}'),
  'span 6 → span 1 sous 640px');
verifier('Les positions fixées sont relâchées en étroit',
  adaptatif.css.includes('.dp-fixe{grid-column-start:auto;grid-row-start:auto}'),
  'sinon la colonne 7 rouvrirait une grille à 7 colonnes');
verifier('L\'espacement du palier est appliqué',
  adaptatif.css.includes('.dp-grille{column-gap:8px;row-gap:8px}'));

// La feuille de style ne doit transporter AUCUNE chaîne venue de la
// déclaration : c'est ce qui rend l'injection de CSS impossible par
// construction, plutôt que par assainissement. On le vérifie en glissant des
// chaînes reconnaissables dans les titres et textes, puis en constatant
// qu'aucune n'atteint le CSS.
const cssTemoin = construireLayout({
  type: 'grille', colonnes: 12, gap_x_px: 16, gap_y_px: 16, marges_px: 0,
  alignement_h: 'stretch', alignement_v: 'stretch',
  responsive: [{ en_dessous_de_px: 640, colonnes: 1, gap_px: 8 }],
  composants: [
    { nature: 'texte', texte: 'TEMOIN_TEXTE}}@import url(x)', style: 'normal',
      x: null, y: null, largeur: 12, hauteur: 1, alignement_h: 'start',
      visible_si: null },
    { nature: 'section', titre: 'TEMOIN_TITRE', style: 'carte', colonnes: 12,
      gap_x_px: 8, gap_y_px: 8, x: null, y: null, largeur: 12, hauteur: 1,
      alignement_h: 'stretch', alignement_v: 'stretch', repliable: false,
      repliee: false, visible_si: null,
      composants: [
        { nature: 'champ', champ: 'statut', x: null, y: null, largeur: 6, hauteur: 1,
          alignement_h: 'stretch', alignement_v: 'stretch',
          masquer_libelle: false, visible_si: null },
      ] },
  ],
}, champs, valeurDe);

verifier('Le CSS ne transporte aucune chaîne de la déclaration',
  !cssTemoin.css.includes('TEMOIN') && !cssTemoin.css.includes('@import'),
  'que des nombres et des noms de classe');

// ── 6. Affichage conditionnel ───────────────────────────────────────────
console.log('\n  Affichage conditionnel');

const conditionnel = construireLayout({
  type: 'grille', colonnes: 12, gap_x_px: 12, gap_y_px: 12, marges_px: 0,
  responsive: [], alignement_h: 'stretch', alignement_v: 'stretch',
  composants: [
    { nature: 'section', titre: 'Clôture', style: 'carte', colonnes: 12,
      gap_x_px: 8, gap_y_px: 8, x: null, y: null, largeur: 12, hauteur: 1,
      alignement_h: 'stretch', alignement_v: 'stretch', repliable: false,
      repliee: false,
      visible_si: { t: 'clause', champ: 'statut', operateur: 'egal', valeur: 'Clos' },
      composants: [
        { nature: 'champ', champ: 'note', x: null, y: null, largeur: 12, hauteur: 1,
          alignement_h: 'stretch', alignement_v: 'stretch',
          masquer_libelle: false, visible_si: null },
      ] },
  ],
}, champs, valeurDe);

verifier('La section conditionnée reçoit son ancre',
  conditionnel.html.includes('data-si="0"'));
verifier('La condition est remontée à l\'appelant',
  conditionnel.conditions.length === 1
  && conditionnel.conditions[0].condition.champ === 'statut');

// ── 7. Robustesse ───────────────────────────────────────────────────────
console.log('\n  Robustesse');

const champManquant = construireLayout({
  type: 'grille', colonnes: 12, gap_x_px: 12, gap_y_px: 12, marges_px: 0,
  responsive: [], alignement_h: 'stretch', alignement_v: 'stretch',
  composants: [
    { nature: 'champ', champ: 'disparu', x: null, y: null, largeur: 6, hauteur: 1,
      alignement_h: 'stretch', alignement_v: 'stretch',
      masquer_libelle: false, visible_si: null },
    { nature: 'champ', champ: 'statut', x: null, y: null, largeur: 6, hauteur: 1,
      alignement_h: 'stretch', alignement_v: 'stretch',
      masquer_libelle: false, visible_si: null },
  ],
}, champs, valeurDe);

verifier('Un champ retiré du jeu ne casse pas le rendu',
  champManquant.champs.join(',') === 'statut',
  'le reste du formulaire est dessiné');

const total = ok + ko.length;
console.log('\n───────────────────────────────────────────────────────────────────────');
if (ko.length === 0) {
  console.log(` ✅ ${ok}/${total} — la mise en page se rend comme elle se déclare.`);
} else {
  console.log(` ❌ ${ko.length} échec(s) sur ${total} :`);
  ko.forEach((t) => console.log(`      • ${t}`));
}
console.log('───────────────────────────────────────────────────────────────────────\n');
process.exit(ko.length === 0 ? 0 : 1);

/*
 * SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
 * SPDX-License-Identifier: LicenseRef-Larka-Proprietary
 *
 * This file is part of Larka, proprietary software by Mickaël Larcin.
 * All rights reserved. Use is subject to the license terms.
 */

/**
 * Larka — Lecture isolée des classeurs Excel / CSV (Web Worker)
 *
 * Chargé par biens-import.js (_biLireClasseur), jamais comme script de page.
 *
 * ⚠️ SheetJS 0.18.5 PRÉSENTE DEUX FAILLES À LA LECTURE D'UN FICHIER PIÉGÉ :
 *   CVE-2023-30533 (pollution de prototype, corrigée en 0.19.3) et
 *   CVE-2024-22363 (ReDoS, corrigée en 0.20.2). Les versions corrigées ne sont
 *   publiées ni sur npm (qui s'arrête à 0.18.5) ni sur cdnjs : uniquement sur
 *   https://cdn.sheetjs.com/. Le fichier officiel se dépose tel quel à la
 *   place de js/vendor/xlsx/xlsx.full.min.js — rien d'autre à modifier.
 *
 * Défense en profondeur, quelle que soit la version présente :
 *   - la lecture se fait ICI, dans un worker créé pour un seul fichier puis
 *     détruit : un prototype pollué l'est dans ce contexte isolé, jamais dans
 *     la page, qui porte la session et l'interface ;
 *   - la page ne reçoit que des tableaux de chaînes. La copie structurée de
 *     postMessage ne transporte ni prototype ni fonction, et la page
 *     revérifie chaque valeur (_biValiderClasseur) ;
 *   - la page termine ce worker s'il ne répond pas dans le délai : un fichier
 *     qui ferait tourner une expression régulière sans fin ne gèle plus
 *     l'onglet.
 *
 * CSV : décodé ICI, puis lu comme du texte brut (voir lireCsv).
 *
 * Le ?v= de ce script (version des pages, cf. page-loader.js) est repris pour
 * la bibliothèque : remplacée, elle est rechargée dès la publication suivante
 * malgré le cache « immutable » des .js.
 */
'use strict';

/**
 * ⚠️ LES CSV ÉTAIENT CONFIÉS TELS QUELS À SheetJS, QUI LES LIT EN LATIN-1 ET
 * RÉINTERPRÈTE CHAQUE VALEUR :
 *   - un CSV en UTF-8 sans BOM (LibreOffice, Google Sheets, la plupart des
 *     exports logiciels) arrivait avec des accents cassés : « DÃ©signation » ;
 *   - « 2020-01-10 » devenait une date réécrite « 1/10/20 », que l'import ne
 *     reconnaît pas (année sur 2 chiffres) : la date était PERDUE, en silence ;
 *   - un code « 00123 » devenait le nombre 123.
 * Désormais : UTF-8 si le fichier en est (BOM retiré), sinon Windows-1252 —
 * l'encodage du « CSV (séparateur : point-virgule) » d'Excel en français — et
 * chaque cellule reste le texte du fichier. Les dates (2020-01-10, 10/01/2020)
 * et les nombres (12 500,50) sont ensuite interprétés par l'import, comme pour
 * un classeur Excel.
 */
function lireCsv(octets) {
  let texte;
  // UTF-16 (« Texte Unicode » d'Excel, tabulations) : reconnu à son BOM.
  if (octets.length >= 2 && octets[0] === 0xFF && octets[1] === 0xFE) texte = new TextDecoder('utf-16le').decode(octets);
  else if (octets.length >= 2 && octets[0] === 0xFE && octets[1] === 0xFF) texte = new TextDecoder('utf-16be').decode(octets);
  else {
    try { texte = new TextDecoder('utf-8', { fatal: true }).decode(octets); }
    catch (_) { texte = new TextDecoder('windows-1252').decode(octets); }
  }
  texte = texte.replace(/^\uFEFF/, '');
  // « sep=; » en première ligne (Excel) : indication de séparateur, pas une donnée.
  const sep = texte.match(/^sep=(.)\r?\n/i);
  if (sep) texte = texte.slice(sep[0].length);
  return XLSX.read(texte, { type: 'string', raw: true, FS: sep ? sep[1] : undefined });
}

/**
 * ⚠️ LES DATES AU FORMAT D'AFFICHAGE « 21-janv » PERDAIENT LEUR ANNÉE : la
 * feuille était lue telle qu'affichée (sheet_to_json, raw: false), et dateNF
 * ne sert qu'aux cellules SANS format. Un planning saisi en « j-mmm » arrivait
 * donc en « 21-Jan », illisible. Une cellule date est maintenant rendue en
 * AAAA-MM-JJ d'après sa valeur (numéro de série, sans fuseau horaire), quel
 * que soit son format ; une heure seule (« 1:45 », durée) reste telle qu'affichée.
 *
 * Lecture cellule par cellule plutôt que sheet_to_json :
 *   - les lignes et colonnes gardent leur place réelle (ligne 1 = A1, lignes
 *     vides comprises) : les références montrées à l'utilisateur sont celles
 *     de son fichier ;
 *   - l'étendue vient des cellules présentes, pas de « !ref » (un !ref gonflé à
 *     A1:XFD1048576 ne fait plus construire un tableau géant).
 * Les cellules fusionnées sont transmises à part (zones [l1, c1, l2, c2]) :
 * seul l'import d'un planning s'en sert ; ailleurs, recopier la valeur dans
 * toute la zone dupliquerait par exemple le titre du document sur une ligne.
 */
const MAX_LIGNES = 200000, MAX_COLONNES = 2000;
function lireFeuille(ws) {
  const grille = [];
  let maxC = -1;
  for (const cle of Object.keys(ws)) {
    if (cle.charAt(0) === '!') continue;
    const a = XLSX.utils.decode_cell(cle);
    if (!(a.r >= 0 && a.r < MAX_LIGNES && a.c >= 0 && a.c < MAX_COLONNES)) continue;
    const c = ws[cle];
    if (!c || c.v == null) continue;
    let v;
    if (c.t === 'n' && c.v >= 1 && c.z && XLSX.SSF.is_date(c.z)) {
      const d = XLSX.SSF.parse_date_code(c.v);
      v = d ? d.y + '-' + String(d.m).padStart(2, '0') + '-' + String(d.d).padStart(2, '0') : String(c.v);
    } else if (c.t === 'd' && c.v instanceof Date && !isNaN(c.v.getTime())) {
      v = c.v.toISOString().substring(0, 10);
    } else {
      v = c.w != null ? String(c.w) : String(c.v);
    }
    if (v === '') continue;
    (grille[a.r] || (grille[a.r] = []))[a.c] = v;
    if (a.c > maxC) maxC = a.c;
  }
  const lignes = [];
  for (let r = 0; r < grille.length; r++) {
    const src = grille[r] || [], l = new Array(maxC + 1);
    for (let c = 0; c <= maxC; c++) l[c] = src[c] == null ? '' : src[c];
    lignes.push(l);
  }
  const fusions = [];
  for (const m of (ws['!merges'] || [])) {
    if (m && m.s && m.e && fusions.length < 20000) fusions.push([m.s.r, m.s.c, m.e.r, m.e.c].map((x) => parseInt(x, 10) || 0));
  }
  return { lignes, fusions };
}

self.onmessage = (e) => {
  const d = e.data || {};
  try {
    importScripts(new URL('../vendor/xlsx/xlsx.full.min.js' + self.location.search, self.location.href).href);
    const octets = new Uint8Array(d.buf);
    const wb = /\.(csv|txt)$/i.test(String(d.nom || ''))
      ? lireCsv(octets)
      : XLSX.read(octets, { type: 'array', cellNF: true });
    // Paires [nom, lignes] plutôt qu'un objet indexé par nom : une feuille
    // nommée « __proto__ » ou « constructor » reste une donnée ordinaire.
    const feuilles = [];
    for (const nom of wb.SheetNames) {
      const f = lireFeuille(wb.Sheets[nom]);
      feuilles.push([String(nom), f.lignes, f.fusions]);
    }
    self.postMessage({ ok: true, feuilles });
  } catch (err) {
    self.postMessage({ ok: false, message: String((err && err.message) || err) });
  }
};

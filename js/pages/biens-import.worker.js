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
  try { texte = new TextDecoder('utf-8', { fatal: true }).decode(octets); }
  catch (_) { texte = new TextDecoder('windows-1252').decode(octets); }
  return XLSX.read(texte.replace(/^\uFEFF/, ''), { type: 'string', raw: true });
}

self.onmessage = (e) => {
  const d = e.data || {};
  try {
    importScripts(new URL('../vendor/xlsx/xlsx.full.min.js' + self.location.search, self.location.href).href);
    const octets = new Uint8Array(d.buf);
    const wb = /\.(csv|txt)$/i.test(String(d.nom || ''))
      ? lireCsv(octets)
      : XLSX.read(octets, { type: 'array', cellDates: true });
    // Paires [nom, lignes] plutôt qu'un objet indexé par nom : une feuille
    // nommée « __proto__ » ou « constructor » reste une donnée ordinaire.
    const feuilles = [];
    for (const nom of wb.SheetNames) {
      const lignes = XLSX.utils.sheet_to_json(wb.Sheets[nom], { header: 1, defval: '', raw: false, dateNF: 'yyyy-mm-dd' });
      feuilles.push([String(nom), lignes.map((l) => Array.from(l, (c) => (c == null ? '' : String(c))))]);
    }
    self.postMessage({ ok: true, feuilles });
  } catch (err) {
    self.postMessage({ ok: false, message: String((err && err.message) || err) });
  }
};

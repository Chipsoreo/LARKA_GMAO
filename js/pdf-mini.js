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
 * Larka — écrire un PDF, sans bibliothèque
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Ce qu'il faut pour un rapport de courbes : des pages A4, du texte, des
 * traits, des rectangles, des chemins pleins ou tracés, des cercles. Rien de
 * plus — pas d'image, pas de police embarquée, pas de compression.
 *
 * POURQUOI PAS UNE BIBLIOTHÈQUE
 * Larka n'a pas d'étape de construction et n'embarque que quatre composants
 * tiers. Un générateur PDF complet pèse plusieurs centaines de kilo-octets pour
 * des fonctions dont un graphique n'a pas l'usage. Le format, lui, est simple
 * quand on s'en tient au vectoriel : une suite d'objets, une table de renvois.
 *
 * LE TEXTE
 * Polices standard du PDF (Helvetica, Helvetica-Bold) : présentes dans tout
 * lecteur, donc jamais embarquées. Encodage WinAnsi (cp1252), qui couvre le
 * français — accents, « », €, °, ², ³. Ce qui n'y figure pas est remplacé par
 * son plus proche équivalent (₂ → 2, espaces fines → espace) ou par « ? ».
 * Les largeurs des glyphes (métriques AFM d'Adobe) servent à aligner à
 * droite, à centrer et à couper un texte trop long.
 *
 * COORDONNÉES
 * En points (1/72 de pouce), origine EN HAUT à gauche comme à l'écran ; la
 * conversion vers le repère du PDF (origine en bas) est faite ici.
 *
 * Utilisation :
 *   const doc = LarkaPdf.creer({ orientation: 'paysage', titre: 'Courbes' });
 *   doc.page();
 *   doc.texte(36, 40, 'Température — salle 12', { taille: 14, gras: true });
 *   doc.chemin([[36, 100], [200, 80], [300, 120]], { trait: '#2563eb', epaisseur: 1.4 });
 *   const blob = doc.blob();
 * ═══════════════════════════════════════════════════════════════════════════════
 */

(typeof window !== 'undefined' ? window : globalThis).LarkaPdf = (() => {
  'use strict';

  // Largeurs (millièmes de corps) des caractères 32 à 126, Helvetica et
  // Helvetica-Bold — métriques AFM standard.
  const HELV = [278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
    556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556, 1015,
    667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667, 611,
    722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556, 333,
    556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500, 278,
    556, 500, 722, 500, 500, 500, 334, 260, 334, 584];
  const HELVB = [278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278,
    556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584, 584, 611, 975,
    722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778, 667, 778, 722, 667, 611,
    722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556, 333,
    556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611, 611, 611, 389, 556, 333,
    611, 556, 778, 556, 556, 500, 389, 280, 389, 584];

  // Unicode → cp1252 pour la plage 0x80–0x9F, la seule où les deux diffèrent.
  const CP1252 = { 0x20AC: 0x80, 0x201A: 0x82, 0x0192: 0x83, 0x201E: 0x84, 0x2026: 0x85, 0x2020: 0x86, 0x2021: 0x87,
    0x02C6: 0x88, 0x2030: 0x89, 0x0160: 0x8A, 0x2039: 0x8B, 0x0152: 0x8C, 0x017D: 0x8E, 0x2018: 0x91, 0x2019: 0x92,
    0x201C: 0x93, 0x201D: 0x94, 0x2022: 0x95, 0x2013: 0x96, 0x2014: 0x97, 0x02DC: 0x98, 0x2122: 0x99, 0x0161: 0x9A,
    0x203A: 0x9B, 0x0153: 0x9C, 0x017E: 0x9E, 0x0178: 0x9F };
  // Ce que WinAnsi n'a pas, et qui a un équivalent lisible.
  const PROCHES = { '₀': '0', '₁': '1', '₂': '2', '₃': '3', '₄': '4', ' ': ' ', ' ': ' ', '​': '',
    '→': '->', '←': '<-', '≤': '<=', '≥': '>=', '≠': '!=', '−': '-', '‑': '-', '‹': '<', '›': '>' };

  /** Texte → octets WinAnsi, sous forme de chaîne « binaire » (un code < 256 par caractère). */
  function winAnsi(s) {
    let out = '';
    for (const ch of String(s)) {
      const r = PROCHES[ch];
      if (r !== undefined) { out += r; continue; }
      const c = ch.codePointAt(0);
      if (c < 0x80 || (c >= 0xA0 && c <= 0xFF)) out += String.fromCharCode(c);
      else if (CP1252[c]) out += String.fromCharCode(CP1252[c]);
      else out += '?';
    }
    return out;
  }

  /** Largeur d'un caractère WinAnsi, en millièmes : une lettre accentuée a la largeur de sa base. */
  const BASES = 'AAAAAAACEEEEIIIIDNOOOOOxOUUUUYPsaaaaaaaceeeeiiiidnooooo/ouuuuypy';
  function largeurCar(code, gras) {
    const t = gras ? HELVB : HELV;
    if (code >= 32 && code <= 126) return t[code - 32];
    if (code >= 0xC0) return largeurCar(BASES.charCodeAt(code - 0xC0), gras);
    if (code === 0xA0) return t[0];
    if (code === 0x80) return 556;                                  // €
    if (code === 0x85) return 1000;                                 // …
    if (code === 0x96) return 556;                                  // –
    if (code === 0x97) return 1000;                                 // —
    if (code === 0x91 || code === 0x92) return gras ? 278 : 222;    // ‘ ’
    if (code === 0xAB || code === 0xBB) return 556;                 // « »
    if (code === 0xB0) return 400;                                  // °
    if (code === 0xB2 || code === 0xB3) return 333;                 // ² ³
    return 556;
  }

  /** « #rrggbb » → « r g b » du PDF (0 à 1). */
  function couleur(hex) {
    const m = /^#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(String(hex || '#000000'));
    if (!m) return '0 0 0';
    return [m[1], m[2], m[3]].map(x => (parseInt(x, 16) / 255).toFixed(3)).join(' ');
  }

  const n = v => (Math.round(v * 100) / 100).toString();

  function creer(opts = {}) {
    const paysage = opts.orientation === 'paysage';
    const W = paysage ? 841.89 : 595.28, H = paysage ? 595.28 : 841.89;
    const pages = [];
    let courante = null;

    const y = v => H - v;            // repère écran → repère PDF
    const ecrire = s => { if (!courante) api.page(); courante.push(s); };

    const api = {
      largeurPage: W,
      hauteurPage: H,

      page() { courante = []; pages.push(courante); return api; },
      allerPage(i) { courante = pages[i] || courante; return api; },
      nombrePages() { return pages.length; },

      /** Largeur d'un texte, en points. */
      largeur(texte, taille = 10, gras = false) {
        let l = 0;
        const s = winAnsi(texte);
        for (let i = 0; i < s.length; i++) l += largeurCar(s.charCodeAt(i), gras);
        return l * taille / 1000;
      },

      /** Coupe un texte pour qu'il tienne dans une largeur, avec « … ». */
      couper(texte, largeurMax, taille = 10, gras = false) {
        texte = String(texte ?? '');
        if (api.largeur(texte, taille, gras) <= largeurMax) return texte;
        let s = texte;
        while (s.length > 1 && api.largeur(s + '…', taille, gras) > largeurMax) s = s.slice(0, -1);
        return s.trimEnd() + '…';
      },

      /** Texte sur une ligne. align : gauche (défaut), centre, droite — par rapport à x. */
      texte(x, yHaut, texte, o = {}) {
        const taille = o.taille || 10, gras = !!o.gras;
        const s = winAnsi(texte);
        if (!s) return api;
        let x0 = x;
        const l = api.largeur(texte, taille, gras);
        if (o.align === 'droite') x0 = x - l;
        else if (o.align === 'centre') x0 = x - l / 2;
        const echappe = s.replace(/\\/g, '\\\\').replace(/\(/g, '\\(').replace(/\)/g, '\\)');
        ecrire(`BT ${couleur(o.couleur)} rg /${gras ? 'F2' : 'F1'} ${n(taille)} Tf ${n(x0)} ${n(y(yHaut))} Td (${echappe}) Tj ET`);
        return api;
      },

      ligne(x1, y1, x2, y2, o = {}) {
        ecrire(`${couleur(o.couleur)} RG ${n(o.epaisseur || 1)} w ${o.tirets ? '[3 3] 0 d ' : '[] 0 d '}${n(x1)} ${n(y(y1))} m ${n(x2)} ${n(y(y2))} l S`);
        return api;
      },

      /** Rectangle (x, y = coin haut gauche). o.remplir et/ou o.trait : couleurs. */
      rect(x, yh, l, h, o = {}) {
        const op = o.remplir && o.trait ? 'B' : (o.remplir ? 'f' : 'S');
        ecrire(`${o.remplir ? couleur(o.remplir) + ' rg ' : ''}${o.trait ? couleur(o.trait) + ' RG ' : ''}${n(o.epaisseur || 0.8)} w `
             + `${n(x)} ${n(y(yh + h))} ${n(l)} ${n(h)} re ${op}`);
        return api;
      },

      /** Ligne brisée ou polygone : [[x, y], …]. */
      chemin(points, o = {}) {
        if (!points || !points.length) return api;
        let d = `${n(points[0][0])} ${n(y(points[0][1]))} m`;
        for (let i = 1; i < points.length; i++) d += ` ${n(points[i][0])} ${n(y(points[i][1]))} l`;
        if (points.length === 1) d += ` ${n(points[0][0] + 0.01)} ${n(y(points[0][1]))} l`;
        if (o.fermer) d += ' h';
        const op = o.remplir && o.trait ? 'B' : (o.remplir ? 'f' : 'S');
        ecrire(`q ${o.remplir ? couleur(o.remplir) + ' rg ' : ''}${o.trait ? couleur(o.trait) + ' RG ' : ''}`
             + `${n(o.epaisseur || 1)} w 1 J 1 j ${d} ${op} Q`);
        return api;
      },

      /** Cercle : quatre arcs de Bézier. */
      cercle(cx, cy, r, o = {}) {
        const k = 0.5523 * r, Y = y(cy);
        const d = `${n(cx + r)} ${n(Y)} m `
          + `${n(cx + r)} ${n(Y + k)} ${n(cx + k)} ${n(Y + r)} ${n(cx)} ${n(Y + r)} c `
          + `${n(cx - k)} ${n(Y + r)} ${n(cx - r)} ${n(Y + k)} ${n(cx - r)} ${n(Y)} c `
          + `${n(cx - r)} ${n(Y - k)} ${n(cx - k)} ${n(Y - r)} ${n(cx)} ${n(Y - r)} c `
          + `${n(cx + k)} ${n(Y - r)} ${n(cx + r)} ${n(Y - k)} ${n(cx + r)} ${n(Y)} c h`;
        const op = o.remplir && o.trait ? 'B' : (o.remplir ? 'f' : 'S');
        ecrire(`${o.remplir ? couleur(o.remplir) + ' rg ' : ''}${o.trait ? couleur(o.trait) + ' RG ' : ''}${d} ${op}`);
        return api;
      },

      /**
       * Le document, en chaîne « binaire ». Les objets sont numérotés dans
       * l'ordre : catalogue, arbre des pages, deux polices, informations,
       * puis pour chaque page son objet et son contenu.
       */
      source() {
        if (!pages.length) api.page();
        const objets = [];
        const nPages = pages.length;
        const idPage = i => 6 + 2 * i, idContenu = i => 7 + 2 * i;
        objets[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        objets[2] = `<< /Type /Pages /Count ${nPages} /Kids [${pages.map((_, i) => idPage(i) + ' 0 R').join(' ')}] >>`;
        objets[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        objets[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        const d = new Date();
        const date = `D:${d.getFullYear()}${String(d.getMonth() + 1).padStart(2, '0')}${String(d.getDate()).padStart(2, '0')}`
                   + `${String(d.getHours()).padStart(2, '0')}${String(d.getMinutes()).padStart(2, '0')}${String(d.getSeconds()).padStart(2, '0')}`;
        const titre = winAnsi(opts.titre || 'Larka').replace(/\\/g, '\\\\').replace(/\(/g, '\\(').replace(/\)/g, '\\)');
        objets[5] = `<< /Title (${titre}) /Producer (Larka) /Creator (Larka) /CreationDate (${date}) >>`;
        pages.forEach((p, i) => {
          const flux = p.join('\n');
          objets[idPage(i)] = `<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ${n(W)} ${n(H)}] `
                            + `/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ${idContenu(i)} 0 R >>`;
          objets[idContenu(i)] = `<< /Length ${flux.length} >>\nstream\n${flux}\nendstream`;
        });
        let out = '%PDF-1.4\n%\xE2\xE3\xCF\xD3\n';
        const offsets = [];
        for (let i = 1; i < objets.length; i++) {
          offsets[i] = out.length;
          out += `${i} 0 obj\n${objets[i]}\nendobj\n`;
        }
        const xref = out.length;
        out += `xref\n0 ${objets.length}\n0000000000 65535 f \n`;
        for (let i = 1; i < objets.length; i++) out += String(offsets[i]).padStart(10, '0') + ' 00000 n \n';
        out += `trailer\n<< /Size ${objets.length} /Root 1 0 R /Info 5 0 R >>\nstartxref\n${xref}\n%%EOF\n`;
        return out;
      },

      /** Le document, prêt à télécharger. */
      blob() {
        const s = api.source();
        const octets = new Uint8Array(s.length);
        for (let i = 0; i < s.length; i++) octets[i] = s.charCodeAt(i) & 0xFF;
        return new Blob([octets], { type: 'application/pdf' });
      },
    };
    return api;
  }

  return { creer, winAnsi };
})();

// Épreuves en ligne de commande : sans effet dans le navigateur.
if (typeof module !== 'undefined' && module.exports) module.exports = globalThis.LarkaPdf;

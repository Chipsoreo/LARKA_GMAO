/*
 * SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
 * SPDX-License-Identifier: LicenseRef-Larka-Proprietary
 *
 * This file is part of Larka, proprietary software by Mickaël Larcin.
 * All rights reserved. Use is subject to the license terms.
 */

/**
 * Larka — Rendu des fonds de plan PDF (pdf.js embarqué)
 *
 * Partagé par l'éditeur de plans (plans.js) et l'annuaire (annuaire.js) : les
 * deux écrans chargeaient chacun leur pdf.js, avec le même code recopié.
 *
 * ⚠️ PDF.js 3.11.174 ÉTAIT CHARGÉ DEPUIS cdnjs — CVE-2024-4367.
 * Cette version peut exécuter du JavaScript à l'ouverture d'un PDF piégé
 * (corrigé en 4.2.67). Or un fond de plan est déposé par un gestionnaire puis
 * affiché chez TOUS les utilisateurs de l'étage, Demandeurs compris : un seul
 * fichier piégé (reçu d'un prestataire, par exemple) suffisait à agir avec la
 * session de chacun d'eux.
 *
 * Désormais :
 *   - pdf.js 6.4.299, build « legacy » (la plus large compatibilité), copié tel
 *     quel du paquet npm officiel pdfjs-dist — empreinte sha512 du paquet
 *     vérifiée contre le registre — dans js/vendor/pdfjs-6.4.299/. Plus aucune
 *     dépendance à un CDN tiers : le plan s'affiche aussi sur un intranet sans
 *     accès Internet. NE PAS revenir vers les versions 5.6 à 6.2 : elles sont
 *     touchées par une autre faille du même type (GHSA-hq66-cqwq-w95j).
 *   - Cette version ne compile plus aucun code tiré du PDF (ni eval ni
 *     new Function dans la build, vérifié) : la CSP n'a pas à autoriser
 *     'unsafe-eval', et l'ancienne option isEvalSupported n'existe plus.
 *   - Fichiers .mjs renommés en .js : le mime.types de nginx 1.24 (celui
 *     d'Ubuntu 24.04 LTS, vérifié) ne connaît pas .mjs et le sert en
 *     application/octet-stream — le navigateur refuse alors de l'exécuter
 *     comme module, et la règle de cache des scripts ne s'applique pas. En .js
 *     ils sont servis partout avec le bon type, et mis en cache comme les
 *     autres scripts : le dossier porte le numéro de version, une montée de
 *     version change donc l'URL.
 *   - wasm/ : décodeurs JPEG 2000 et JBIG2 (plans scannés). L'ancienne version
 *     les décodait en JavaScript ; sans ce dossier, ces images resteraient
 *     blanches. Si WebAssembly est refusé (CSP), pdf.js bascule de lui-même
 *     sur les équivalents JavaScript livrés à côté. (La gestion des couleurs
 *     ICC n'est pas livrée : pdf.js ne l'active que si le worker lit lui-même
 *     ses données — useWorkerFetch, qui par défaut exige aussi cMapUrl et
 *     standardFontDataUrl, non fournis ici, comme avant.)
 *   - Le document est détruit après le rendu : chaque changement d'étage
 *     laissait jusqu'ici un worker pdf.js vivant jusqu'au rechargement.
 *   - Rendus gardés en mémoire (quelques plans) et partagés : les deux écrans
 *     reconstruisent la carte à chaque filtre ou frappe de recherche, et
 *     chaque reconstruction retéléchargeait et re-rendait tout le PDF.
 *
 * Monter de version : copier legacy/build/pdf.min.mjs et pdf.worker.min.mjs
 * (renommés en .js) et, de wasm/, openjpeg* et jbig2* avec leurs LICENSE_*,
 * dans un NOUVEAU dossier js/vendor/pdfjs-<version>/, changer
 * _PDF_FOND_DOSSIER, puis THIRD-PARTY-NOTICES.md.
 */

const _PDF_FOND_DOSSIER = 'js/vendor/pdfjs-6.4.299/';
let _pdfFondLib = null; // Promise du module pdf.js, partagée par les deux écrans
const _pdfFondCache = new Map(); // clé → Promise de l'image ; ordre = du plus ancien au plus récent
const _PDF_FOND_CACHE_MAX = 4;

// import() appelé depuis un script classique résout un chemin relatif par
// rapport au SCRIPT (js/pages/…), pas à la page : on passe donc des URL
// absolues, calculées comme celles de Leaflet (relatives au document).
function _pdfFondUrl(chemin) {
  return new URL(_PDF_FOND_DOSSIER + chemin, document.baseURI).href;
}

function chargerPdfJs() {
  if (!_pdfFondLib) {
    _pdfFondLib = import(_pdfFondUrl('pdf.min.js'))
      .then((lib) => {
        // Worker de même origine : autorisé par worker-src 'self', sans blob:.
        lib.GlobalWorkerOptions.workerSrc = _pdfFondUrl('pdf.worker.min.js');
        return lib;
      })
      .catch((e) => {
        _pdfFondLib = null; // permettre un nouvel essai (réseau coupé, etc.)
        throw new Error('lecteur PDF indisponible (' + ((e && e.message) || e) + ')');
      });
  }
  return _pdfFondLib;
}

/**
 * Rend la page 1 d'un PDF en image PNG (data URL), prête pour L.imageOverlay.
 * cle identifie le FICHIER (un nouveau dépôt de fond change de nom) : même
 * clé, même image — rendu en cours partagé, rendu terminé réutilisé.
 */
function rendrePdfFondEnImage(url, cle) {
  const k = cle || url;
  let p = _pdfFondCache.get(k);
  if (p) { _pdfFondCache.delete(k); _pdfFondCache.set(k, p); return p; }
  p = _rendrePdfFond(url);
  _pdfFondCache.set(k, p);
  p.catch(() => { if (_pdfFondCache.get(k) === p) _pdfFondCache.delete(k); });   // un échec n'est pas gardé
  while (_pdfFondCache.size > _PDF_FOND_CACHE_MAX) _pdfFondCache.delete(_pdfFondCache.keys().next().value);
  return p;
}

// Largeur de rendu : deux fois la largeur native, bornée entre 1200 et 2400 px
// (valeurs historiques des deux écrans).
async function _rendrePdfFond(url) {
  const lib = await chargerPdfJs();
  const tache = lib.getDocument({
    url,
    withCredentials: true,        // plans_fond_image exige la session
    wasmUrl: _pdfFondUrl('wasm/'),
  });
  try {
    const pdf  = await tache.promise;
    const page = await pdf.getPage(1);
    const v0 = page.getViewport({ scale: 1 });
    const scale = Math.min(2400, Math.max(1200, v0.width * 2)) / v0.width;
    const vp = page.getViewport({ scale });
    const cv = document.createElement('canvas');
    cv.width = Math.floor(vp.width); cv.height = Math.floor(vp.height);
    await page.render({ canvas: cv, viewport: vp }).promise;
    return cv.toDataURL('image/png');
  } finally {
    tache.destroy().catch(() => {}); // libère le worker et la mémoire du document
  }
}

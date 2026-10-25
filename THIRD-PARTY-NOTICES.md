# Composants tiers — attributions et licences

Larka est un logiciel propriétaire (voir [`LICENSE`](LICENSE)). Il embarque
toutefois des composants tiers qui restent régis par **leur propre licence
d'origine**. Ce fichier les recense et reproduit les mentions que ces licences
imposent de conserver.

Il doit être distribué avec le Logiciel et ne doit pas être supprimé.

---

## Inventaire

| Composant | Version | Licence | Emplacement | Texte de la licence |
|---|---|---|---|---|
| @zxing/browser | — | MIT | `js/zxing-browser.min.js` | [`licenses/MIT.txt`](licenses/MIT.txt) |
| @zxing/library | — | Apache-2.0 | *(inclus dans le fichier ci-dessus)* | [`licenses/Apache-2.0.txt`](licenses/Apache-2.0.txt) |
| Leaflet | 1.9.4 | BSD-2-Clause | `js/vendor/leaflet/` | [`licenses/BSD-2-Clause.txt`](licenses/BSD-2-Clause.txt) |
| PDF.js (pdfjs-dist, build legacy) | 6.4.299 | Apache-2.0 | `js/vendor/pdfjs-6.4.299/` | [`licenses/Apache-2.0.txt`](licenses/Apache-2.0.txt) · `js/vendor/pdfjs-6.4.299/LICENSE` |
| OpenJPEG (décodeur wasm de PDF.js) | — | BSD-2-Clause | `js/vendor/pdfjs-6.4.299/wasm/openjpeg*` | `wasm/LICENSE_OPENJPEG` · `wasm/LICENSE_PDFJS_OPENJPEG` |
| JBIG2 de PDFium (décodeur wasm de PDF.js) | — | BSD-3-Clause et Apache-2.0 | `js/vendor/pdfjs-6.4.299/wasm/jbig2*` | `wasm/LICENSE_JBIG2` (les deux textes) · `wasm/LICENSE_PDFJS_JBIG2` |
| SheetJS Community Edition (xlsx) | 0.18.5 | Apache-2.0 | `js/vendor/xlsx/` | [`licenses/Apache-2.0.txt`](licenses/Apache-2.0.txt) · `js/vendor/xlsx/LICENSE` |
| DM Sans | — | OFL-1.1 | `css/fonts/dm-sans-*.woff2` | [`licenses/OFL-1.1.txt`](licenses/OFL-1.1.txt) |
| DM Mono | — | OFL-1.1 | `css/fonts/dm-mono-*.woff2` | [`licenses/OFL-1.1.txt`](licenses/OFL-1.1.txt) |

---

## @zxing/browser — MIT

Copyright © 2018 ZXing for JS
https://github.com/zxing-js/browser

```
Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```

---

## @zxing/library — Apache License 2.0

https://github.com/zxing-js/library

Le fichier `js/zxing-browser.min.js` est un *bundle* : il inclut le code de
`@zxing/library`, publié sous **Apache License 2.0**.

```
Licensed under the Apache License, Version 2.0 (the "License");
you may not use this file except in compliance with the License.
You may obtain a copy of the License at

    http://www.apache.org/licenses/LICENSE-2.0

Unless required by applicable law or agreed to in writing, software
distributed under the License is distributed on an "AS IS" BASIS,
WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
See the License for the specific language governing permissions and
limitations under the License.
```

Conformément à l'article 4 de la licence, le texte intégral est joint à cette
distribution : [`licenses/Apache-2.0.txt`](licenses/Apache-2.0.txt).

---

## Leaflet 1.9.4 — BSD 2-Clause

Copyright © 2010-2023 Vladimir Agafonkin
Copyright © 2010-2011 CloudMade
https://leafletjs.com

```
Redistribution and use in source and binary forms, with or without modification,
are permitted provided that the following conditions are met:

 1. Redistributions of source code must retain the above copyright notice, this
    list of conditions and the following disclaimer.

 2. Redistributions in binary form must reproduce the above copyright notice,
    this list of conditions and the following disclaimer in the documentation
    and/or other materials provided with the distribution.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR
ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
(INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON
ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
(INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
```

Les images de `js/vendor/leaflet/images/` (marqueurs, ombres, icônes de calques)
font partie de la distribution Leaflet et sont couvertes par la même licence.

---

## PDF.js 6.4.299 — Apache License 2.0

Copyright © 2024 Mozilla Foundation
https://mozilla.github.io/pdf.js/ · paquet npm `pdfjs-dist`

Rendu des fonds de plan PDF (`js/pages/pdf-fond.js`). Les fichiers sont ceux de
la build « legacy » du paquet officiel, reproduits sans modification ; seule
l'extension de `pdf.min.mjs` et `pdf.worker.min.mjs` a été changée en `.js`
(pour qu'ils soient servis avec un type JavaScript par tous les serveurs). Le
texte de la licence accompagne les fichiers (`js/vendor/pdfjs-6.4.299/LICENSE`)
et figure aussi dans [`licenses/Apache-2.0.txt`](licenses/Apache-2.0.txt).

Le dossier `wasm/` contient les décodeurs fournis avec PDF.js, chacun
accompagné de sa licence d'origine, reproduite à côté de lui :

- **OpenJPEG** (JPEG 2000) — BSD 2-Clause, © Université catholique de Louvain
  et contributeurs (`LICENSE_OPENJPEG`) ; adaptation Mozilla
  (`LICENSE_PDFJS_OPENJPEG`) ;
- **JBIG2 de PDFium** — © The PDFium Authors : BSD 3-Clause et Apache 2.0, les
  deux textes figurant dans `LICENSE_JBIG2` ; adaptation Mozilla sous
  Apache-2.0 (`LICENSE_PDFJS_JBIG2`).

Les autres fichiers du dossier `wasm/` du paquet (gestion des couleurs ICC,
moteur de scripts PDF) ne sont pas livrés : Larka ne les utilise pas.

---

## SheetJS Community Edition — Apache License 2.0

Copyright © 2012-present SheetJS LLC
https://sheetjs.com · paquet npm `xlsx`

Lecture des fichiers Excel et CSV de l'import de biens et d'équipements, dans
un worker isolé (`js/pages/biens-import.worker.js`). Fichier
`dist/xlsx.full.min.js` du paquet officiel, reproduit sans modification ; sa
licence est jointe (`js/vendor/xlsx/LICENSE`) et figure aussi dans
[`licenses/Apache-2.0.txt`](licenses/Apache-2.0.txt).

> La version 0.18.5 est la dernière publiée sur npm. CVE-2023-30533 est
> corrigée en 0.19.3 et CVE-2024-22363 en 0.20.2 ; ces versions ne sont
> distribuées que sur https://cdn.sheetjs.com/. Y prendre la dernière (0.20.3
> ou plus récente), déposer son `xlsx.full.min.js` à la place de celui-ci,
> puis mettre à jour la ligne de l'inventaire ci-dessus.

---

## DM Sans et DM Mono — SIL Open Font License 1.1

Copyright © Colophon Foundry, Jonny Pinhorn, Indian Type Foundry
https://fonts.google.com/specimen/DM+Sans · https://fonts.google.com/specimen/DM+Mono

Les 14 fichiers WOFF2 de `css/fonts/` sont distribués sous **SIL Open Font
License 1.1**. Cette licence autorise l'intégration dans un logiciel, y compris
propriétaire, à condition que :

- la mention de droit d'auteur et le texte de la licence accompagnent les
  fichiers de polices ;
- les polices ne soient pas vendues seules ;
- si les fichiers sont modifiés, ils ne conservent pas le nom réservé
  (« DM Sans », « DM Mono »).

Le texte intégral est joint à cette distribution :
[`licenses/OFL-1.1.txt`](licenses/OFL-1.1.txt).

> À vérifier de votre côté : l'attribution exacte des polices. Celles issues de
> Google Fonts sont souvent redistribuées via `@fontsource`, dont le fichier
> `LICENSE` livré avec le paquet fait foi pour les noms des détenteurs de droits.

---

## Provenance des textes de licence

Les fichiers du dossier `licenses/` sont les textes canoniques publiés par le
SPDX Workgroup (Linux Foundation), récupérés depuis le dépôt de référence
`spdx/license-list-data`. Ils sont reproduits sans modification, comme l'exigent
les licences concernées.

---

## Rappel

Ces licences s'appliquent **aux seuls composants listés ci-dessus**. Le reste du
Logiciel reste soumis à la licence propriétaire décrite dans [`LICENSE`](LICENSE).

En cas de contradiction entre les deux, la licence du composant tiers prévaut
pour ce composant (voir article 13.4 de `LICENSE`).

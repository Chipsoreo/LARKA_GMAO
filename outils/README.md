# outils/

Tout ce qui sert à **développer** Larka, et rien de ce qui le fait tourner.

`deploy/install.sh` exclut ce dossier de la copie : il ne part sur aucun serveur.
Auparavant les mêmes outils étaient éparpillés à la racine dans `sdk/`,
`securite/` et `scripts/`, tous les trois livrés en production — bloqués par
nginx, mais livrés quand même. Un fichier refusé reste un fichier présent.

```
outils/
  verifier-module.php     vérifie une déclaration de module
  epreuves/               les épreuves — voir epreuves/README.md
  langues/                extraction des libellés, construction et vérification des packs
  simulateurs/            équipements de bâtiment simulés pour Larka Connect (Modbus, BACnet, MQTT, REST, CSV, OCPP)
  publier-version.php     publie une version signée (mode d'emploi dans l'en-tête du script)
  bump-version.php        renouvelle les « ?v= » d'index.html (start.sh prod l'appelle)
```

---

## verifier-module.php

```bash
php outils/verifier-module.php extensions/modules/acme.mon-module
php outils/verifier-module.php extensions/modules/acme.mon-module --detail
php outils/verifier-module.php --tous
```

Valide une déclaration contre le **schéma réel du serveur**
(`api/extensions/declaratif/Schema.php`, pas une copie qui finirait par en
diverger) et annonce ce qu'elle produira : jeux de données, écrans, ancrages,
dossiers de fichiers, traductions, capacités déduites.

Les références `$ref` sont résolues comme à l'installation. Aucune base n'est
nécessaire : rien n'est exécuté, rien n'est installé.

C'est le **seul** vérificateur. `Documentations/exemples-declaratifs/verifier.php`
ne fait que lui déléguer — deux validateurs pour un même schéma finissent
toujours par diverger, et l'on ne sait plus lequel fait foi.

---

## lucide/

Icônes Lucide de l'interface. Dans le code, une icône s'écrit `lk('nom')` (JS, `js/icones.js`) ou
`<i class="lk lk-nom" aria-hidden="true"></i>` (HTML) ; variantes facultatives : `lk('nom', 'ok')`
(statut : `ok`, `ko`, `warn`), `lk('circle', 'p rouge')` (`p` = pleine, couleurs : `rouge`, `vert`, `bleu`,
`jaune`, `orange`, `violet`, `gris`). Une icône est du HTML : jamais dans un `title`, un `placeholder`,
une `<option>` ou un `textContent`.

`icones.json` liste les icônes embarquées (noms pris sur https://lucide.dev/icons), celles qui existent en
version pleine (`pleines`) et les familles de couleur. Pour ajouter une icône : l'ajouter à la liste, puis

```
npm i lucide-static            # dans un dossier de travail, hors du dépôt
php outils/lucide/generer-icones.php chemin/vers/node_modules/lucide-static
```

qui régénère `css/lucide.css` (seules les icônes de la liste y sont embarquées, en masques CSS) et
`licenses/LUCIDE-LICENSE.txt`. Un nom inconnu du générateur est refusé ; un nom absent de la liste ne
dessine rien.

Couleurs des styles « Colorées » et « Duotone » : section `_teintes`, une famille par couleur
(`"energie": {"couleur": "#f59e0b", "icones": ["zap", "plug", …]}`). Une icône peut n'être dans aucune
famille (elle garde la couleur du texte), jamais dans deux ; le générateur le vérifie.

## epreuves/

Vingt-trois suites PHP et deux JavaScript, quelques secondes, aucune dépendance
sauf pour quatre d'entre elles. Voir `epreuves/README.md` pour le détail de ce
que chacune vérifie.

```bash
bash outils/epreuves/toutes.sh
```

---

## simulateurs/

```bash
cd outils/simulateurs && ./installer.sh && ./lancer.sh start && ./lancer.sh verifier
```

Compteurs électriques (Modbus TCP), compteurs d'eau (MQTT), chauffage/clim (BACnet/IP),
superviseur GTB (API REST), vieille GTB (exports CSV) et bornes de recharge (OCPP 1.6J),
chacun sur sa propre adresse `127.0.10.x`, avec des aléas réalistes et des anomalies à la
demande. `simulateurs/README.md` donne, système par système, les réglages à saisir dans
**Bâtiments → Connexions**. L'épreuve `epreuves/test-connect.php`, elle, n'en a pas besoin :
elle embarque ses propres simulateurs en PHP.

---

## langues/

```bash
php outils/langues/extraire-libelles.php
php outils/langues/construire-pack.php en --version=4.3.2
php outils/langues/verifier-traduction.php extensions/langues/acme.langue-de/extension.json
```

Toutes les traductions de l'interface tiennent dans **un seul fichier**,
`lang/traductions.json` (format décrit dans `langues/traductions.php`) : une
ligne par libellé, la clé est le texte français affiché, la valeur porte une
traduction par langue — `"Enregistrer": {"en": "Save"}`. Une langue absente
d'une ligne = pas encore traduite ; `"fr"` sert seulement à corriger un libellé
sans toucher au code. La liste `complements` du même fichier recense les
libellés assemblés par le code ou fournis par le serveur, invisibles pour la
récolte. Seuls les packs de langue (`extensions/langues/`) sont des fichiers à
part : ce sont eux qu'on installe.

**`extraire-libelles.php`** parcourt l'application et met `lang/traductions.json`
à jour : nouveaux libellés ajoutés (lignes vides, à traduire), libellés disparus
du code retirés, traductions des autres gardées. Il indique, par langue, combien
de lignes restent à traduire.

**`construire-pack.php <code>`** construit, depuis ce fichier, le pack
`extensions/langues/larka.langue-<code>/extension.json` et son paquet
installable `.larka_thematique` (une ligne non traduite y garde le français).
Une langue nouvelle (`construire-pack.php de`) est d'abord déclarée dans le
fichier, où on la traduit ensuite ligne par ligne. Code de sortie 1 s'il reste
des libellés en français. On traduit dans `lang/traductions.json`, jamais dans
le pack.

À relancer **chaque fois que vous ajoutez un écran** : sans cela le catalogue
prend du retard, et les packs existants perdent silencieusement en couverture —
les nouveaux écrans restent en français au milieu d'une interface traduite.

**`verifier-traduction.php`** relit un pack terminé et signale trois défauts, par
ordre d'importance : libellés manquants, français résiduel (« No work order en
cours », le pire des trois : il fait douter du logiciel), et valeurs identiques à
la clé.

Si vous décidez de ne plus toucher aux traductions, les deux partent ensemble.
Il n'y a pas d'entre-deux utile.

---

## Créer et construire un module

Ces deux-là ne sont **pas** ici : ils font partie du produit et servent aussi en
production.

```bash
php api/extensions/outils/construire-paquet.php --nouveau acme.mon-module
php api/extensions/outils/construire-paquet.php extensions/modules/acme.mon-module
php api/extensions/outils/construire-paquet.php --tous
```

Le guide complet : `Documentations/FORMAT-DECLARATIF-REFERENCE.md` — et neuf
modules d'exemple, du plus simple au plus complet, dans
`Documentations/exemples-declaratifs/`.

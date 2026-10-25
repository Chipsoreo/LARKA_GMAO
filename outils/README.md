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
  publier-version.php     publie une version signée (voir le README, « Publier sur GitHub »)
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

## epreuves/

Vingt-deux suites PHP et deux JavaScript, quelques secondes, aucune dépendance
sauf pour quatre d'entre elles. Voir `epreuves/README.md` pour le détail de ce
que chacune vérifie.

```bash
bash outils/epreuves/toutes.sh
```

---

## langues/

```bash
php outils/langues/extraire-libelles.php
php outils/langues/construire-pack.php en --version=4.2.0
php outils/langues/verifier-traduction.php extensions/langues/acme.langue-de/extension.json
```

**`extraire-libelles.php`** parcourt l'application et écrit dans `lang/fr.json`
tous les textes affichés, plus ceux de `lang/complements.json` (libellés
assemblés par le code ou fournis par le serveur, invisibles pour la récolte).
C'est le modèle qu'on remet à un traducteur.

**`construire-pack.php <code>`** aligne le fichier du traducteur `lang/<code>.json`
sur le catalogue — mêmes clés, même ordre, donc exactement le même nombre de
lignes que `lang/fr.json` ; les nouvelles clés y arrivent en attente de
traduction, les clés disparues du catalogue en sont retirées — puis construit
le pack `extensions/langues/larka.langue-<code>/extension.json` et son paquet
installable `.larka_thematique`. Code de sortie 1 s'il reste des libellés en
français. On traduit dans `lang/<code>.json`, jamais dans le pack.

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

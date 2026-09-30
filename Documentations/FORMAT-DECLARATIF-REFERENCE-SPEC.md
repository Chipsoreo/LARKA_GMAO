# Format déclaratif Larka — référence

> **Un module déclaratif ne contient aucun code.** C'est un fichier JSON qui
> DÉCRIT des données et des écrans ; c'est Larka qui exécute, avec son propre
> code. Il n'y a rien à isoler, parce qu'il n'y a rien d'étranger à exécuter.
>
> Tout ce que ce document décrit est donc du **vocabulaire** : des mots que le
> moteur sait lire, et rien d'autre. Un mot absent de ce document est refusé à
> l'installation — jamais cherché ailleurs, jamais ignoré en silence.

Version du format : `declaratif/1` · API d'extensions : version 1 · Référence : version 2.0 (septembre 2026, Larka V.Beta 2.0.0)

---

## 1. Squelette d'une déclaration

```json
{
  "identifiant": "acme.controles",
  "format": "declaratif/1",
  "nom": "Contrôles périodiques",
  "version": "1.0.0",
  "auteur": "ACME <contact@acme.fr>",
  "description": "Suivi des contrôles réglementaires.",

  "donnees":  { "...": "les tables du module" },
  "pages":    [ "les écrans" ],
  "ancrages": [ "les insertions dans les écrans de Larka" ],
  "fichiers": { "...": "les dossiers de fichiers" },
  "partage":  [ "ce que le module publie aux autres" ],
  "calculs":  { "...": "ce qu'il additionne chez les autres" },
  "reglages": { "...": "ce que l'administrateur peut régler" },
  "langues":  { "...": "les traductions" }
}
```

Quatre clés sont **refusées** parce qu'elles supposeraient du code :
`serveur`, `client`, `hooks`, et toute forme de sortie réseau
(`webhooks`, `webhook`, `integrations`, `domaines_reseau`).

### Plafonds

| Élément | Maximum |
|---|---|
| Jeux de données | 20 |
| Champs par jeu | 120 |
| Pages | 20 |
| Valeurs d'une liste de choix | 200 |
| Ancrages | 24 |
| Partages | 20 · Calculs | 40 |
| Retouches d'interface | 12 · Dossiers de fichiers | 8 |

### Un module en plusieurs fichiers JSON — `$ref`

Un module n'est pas tenu de tenir dans un seul `extension.json`. Dès qu'une
déclaration grossit — les mêmes champs d'adresse répétés dans trois jeux, une
page déclinée en variantes, des réglages qui occupent deux cents lignes —, on
la découpe en **parties** : des fichiers JSON rangés dans le dossier `parties/`
du module, et appelés par `$ref`.

```text
acme.controles/
├── extension.json      ← porte d'entrée
├── parties/
│   ├── reglages.json
│   ├── champ-texte.json
│   ├── controle.json
│   └── vue-planning.json
└── assets/
    └── logo.png
```

Partout où la déclaration attend une valeur, l'objet
`{ "$ref": "parties/<nom>.json" }` est remplacé par le contenu du fichier
désigné :

```json
{
  "identifiant": "acme.controles",
  "format": "declaratif/1",
  "nom": "Contrôles périodiques",
  "version": "1.0.0",
  "reglages": { "$ref": "parties/reglages.json" },
  "donnees": {
    "controles": {
      "libelle": "Contrôles",
      "champs": { "$ref": "parties/controle.json" }
    }
  },
  "pages": [
    {
      "cle": "planning",
      "titre": "Planning",
      "vue": { "$ref": "parties/vue-planning.json" }
    }
  ]
}
```

**Les clés posées à côté du `$ref` surchargent le contenu référencé.** C'est ce
qui rend les parties utiles plutôt que seulement compactes : on décrit un champ
générique une fois, puis on le décline.

```json
"champs": {
  "site": {
    "$ref": "parties/champ-texte.json",
    "libelle": "Nom du site"
  },
  "contact": {
    "$ref": "parties/champ-texte.json",
    "libelle": "Contact",
    "obligatoire": false
  }
}
```

avec `parties/champ-texte.json` :

```json
{ "type": "texte", "obligatoire": true, "max": 80 }
```

Une partie peut contenir n'importe quelle valeur JSON — un objet, mais aussi
une liste (par exemple les valeurs d'une liste de choix :
`"valeurs": { "$ref": "parties/ma-liste.json" }`) — et appeler elle-même
d'autres parties.

| Règle | Détail |
|----------|--------------------------------------|
| Emplacement | `parties/<nom>.json` uniquement : un seul niveau, sans sous-dossier ni remontée (`..`) ; nom en lettres, chiffres, `-` et `_`, 60 caractères au plus |
| Surcharge | De **surface** : une clé posée à côté du `$ref` remplace la clé entière du contenu référencé, sans fusion en profondeur — avec un tableau, on ne saurait plus si l'on ajoute ou si l'on remplace. Surcharger suppose que la partie soit un objet |
| Réutilisation | Une même partie peut être appelée autant de fois que nécessaire, côte à côte ou dans des jeux différents |
| Racine | La déclaration ne peut pas être remplacée entièrement par un `$ref` : `extension.json` reste la porte d'entrée |
| Bornes | 8 niveaux d'imbrication · 400 inclusions au total · 4 Mo par partie · toute référence circulaire (A → B → A, A → A) est refusée |
| Erreurs | Partie absente du paquet, JSON invalide, chemin hors `parties/` : l'installation est refusée avec le nom du fichier en cause |

**Ce que cela ne change pas.** Les références sont résolues **à la lecture,
avant toute validation**, et leur contenu est recopié à leur place. Ce qui est
validé, stocké et exécuté est un bloc unique, sans aucune référence résiduelle :
le module installé est exactement aussi inspectable qu'un module écrit d'un seul
tenant. Les parties sont une commodité d'écriture ; elles ne survivent pas à
l'installation.

**Vérifier et empaqueter.** Les deux outils résolvent les parties comme le
serveur :

```bash
# déclaration résolue puis validée, comme à l'installation
php outils/verifier-module.php extensions/modules/acme.controles

# paquet .larka, dossier parties/ inclus
php api/extensions/outils/construire-paquet.php extensions/modules/acme.controles
```

Le paquet contient alors `extension.json`, le dossier `parties/` et, le cas
échéant, `assets/`, `README.md`, `LICENSE` et `SIGNATURE` ; tout autre chemin
est refusé. Exemple livré : `extensions/modules/larka.recharge` (réservation
des bornes de recharge), réparti entre `extension.json` et huit parties.

---

## 2. Champs

### Types

| Type | Ce qu'il stocke |
|---|---|
| `texte` | Texte court (max 500) |
| `texte_long` | Texte long (max 5000) |
| `entier` | Nombre entier |
| `decimal` | Nombre décimal |
| `date` | Date |
| `heure` | Heure `HH:MM` |
| `horodatage` | Date et heure |
| `choix` | Une valeur d'une liste |
| `choix_multiple` | Plusieurs valeurs d'une liste |
| `booleen` | Oui / non |
| `couleur` | Couleur `#rrggbb` |
| `note` | Note sur 5 |
| `pourcentage` | Pourcentage |
| `duree` | Durée en minutes |
| `lien` | Référence à une donnée du cœur |
| `formule` | Valeur calculée, jamais stockée |

### Propriétés communes

`libelle` · `aide` · `obligatoire` · `unique` · `defaut` · `exemple` · `unite` ·
`lecture_seule` · `masque_liste` · `groupe` · `visible_si` · `largeur` ·
`largeur_px` · `hauteur_px` · `min` · `max` · `pas` · `min_jours` · `max_jours` ·
`min_date` · `max_date` · `format_saisie` · `suggestions` · `suggestions_source` ·
`couleurs` · `valeurs` · `liste` · `modifiable` · `ajout_autorise` · `libre`

### Champs de traçabilité

Posés par Larka sur chaque jeu ; ne les déclarez pas :
`cree_le`, `cree_par`, `modifie_le`, `modifie_par`.
Le champ `id` est également fourni : le déclarer est refusé.

### Listes de choix : deux modes, et c'est tout

* **Verrouillée** (défaut) — les valeurs sont celles du module, exactement.
  La liste n'apparaît pas dans Configuration : il n'y aurait rien à y régler.
* **Ouverte** — `"modifiable": 1`, ou simplement `"liste": "MaCategorie"`.
  Les valeurs du module **amorcent** une catégorie, et l'administrateur en
  devient maître. `ajout_autorise` et `libre` sont des droits **du champ** :
  deux champs peuvent puiser dans la même catégorie sans avoir le même droit de
  l'enrichir.

Un champ ouvert n'a pas besoin de `valeurs` : sa nomenclature peut vivre
entièrement dans Configuration. Un champ **ni** ouvert **ni** pourvu de valeurs
est refusé — il ne proposerait rien.

---

## 3. Pages et vues

```json
{ "cle": "controles", "titre": "Contrôles", "icone": "check-circle",
  "roles": ["Gestionnaire", "Admin"],
  "vue": { "type": "liste", "source": "controles", "...": "..." } }
```

Types de vue : `liste` et `calendrier`.
Rôles : `Gestionnaire`, `Admin`, `Visionneur`, `Demandeur`.

Réglages de la vue : `colonnes` · `recherche` · `filtres` · `tri` ·
`tri_utilisateur` · `par_page` · `totaux` · `grouper_par` · `surligner` ·
`largeurs` · `actions` · `actions_rapides` · `calendrier` ·
`colonnes_formulaire` · `champs_formulaire` · `layout`.

Actions servies : `creer`, `modifier`, `supprimer`, `exporter`.
Une action non déclarée n'est pas servie.

> **L'autorisation ne dépend pas de l'ordre d'écriture.** Quand plusieurs pages
> présentent le même jeu, une action est servie dès qu'une page **accessible au
> rôle de l'utilisateur** la déclare. Un module peut donc offrir un écran
> restreint au demandeur et un écran complet au gestionnaire, sans que le
> premier décide pour le second.

### 3.1 Les surfaces : ce qu'un rôle atteint

Une **surface** est un endroit déclaré par lequel un rôle touche un jeu de
données : une page, ou un ancrage `formulaire`. C'est le seul vocabulaire
d'autorisation du format, et il décide de **quatre** choses, toutes appliquées
côté serveur :

| Question | Réponse |
|---|---|
| Quel **jeu** ce rôle peut-il lire ? | Celui d'une page dont `roles` le contient |
| Quelle **action** peut-il faire ? | Celle que cette page déclare dans `vue.actions` |
| Quels **champs** peut-il écrire ? | Ceux que `layout` — ou `champs_formulaire` — expose |
| Quel **module** peut-il ouvrir ? | Celui dont les rôles accordés le contiennent, fichiers compris |

Trois conséquences qui se vérifient facilement :

- le nom du jeu voyage dans la requête ; le changer ne donne accès à rien, car
  la page qui le sert doit être ouverte au rôle de l'appelant ;
- une page qui ne déclare pas `modifier` ne permet pas de modifier, même la
  fiche que l'on vient de créer ;
- un champ absent du formulaire est **écarté** de la saisie. À la création il
  reprend son `defaut`, à la modification il garde sa valeur. C'est ce qui
  permet à un demandeur de créer une fiche dont la décision reste « Demandé »
  sans jamais voir ce champ — et ce qui l'empêche de se l'accorder.

Un ancrage `formulaire` est une surface de **création** : il porte le formulaire
de la page qu'il nomme, aux champs de cette page. Il ne donne jamais la
modification, qui suppose d'avoir la fiche sous les yeux, donc une page.

Une page **sans** `layout` ni `champs_formulaire` n'expose aucune restriction :
tous les champs du jeu restent saisissables. C'est le comportement de tous les
modules écrits avant la mise en page, et il ne change pas.

> **La lecture se décide au jeu, pas à la colonne.** `colonnes` choisit ce que
> le tableau affiche, pas ce que le serveur renvoie : une page réduite à cinq
> colonnes reçoit quand même les champs du jeu, dont son formulaire et son
> export ont besoin. Pour réserver des données à un rôle, il faut un **jeu** à
> part, présenté par une page à part.

---

## 4. Mise en page déclarative — `layout`

`layout` décrit la disposition du **formulaire** d'une page. Il est facultatif :
sans lui, le formulaire garde son comportement habituel (groupes de champs,
`colonnes_formulaire`, largeurs par type).

### 4.1 Pourquoi une grille, et non des coordonnées libres

Des `x` / `y` en pixels produisent une interface qui ne survit ni à une police
plus grande, ni à un écran étroit, ni à une traduction plus longue — et l'auteur
ne peut rien prévoir de tout cela depuis son JSON. Une grille en **colonnes**
garde la maîtrise de la disposition tout en laissant le rendu s'adapter. C'est
la seule façon d'être à la fois précis et responsive.

### 4.2 Forme générale

```json
{
  "layout": {
    "type": "grille",
    "colonnes": 12,
    "espacement": "large",
    "composants": [
      { "champ": "equipement", "x": 1, "y": 1, "largeur": 6 },
      { "champ": "statut",     "x": 7, "y": 1, "largeur": 6 }
    ]
  }
}
```

Presque tout y est facultatif. La même disposition s'écrit aussi :

```json
{ "layout": { "composants": [
    { "champ": "equipement", "largeur": 6 },
    { "champ": "statut",     "largeur": 6 } ] } }
```

— douze colonnes et un espacement normal étant les défauts, et les composants
se rangeant à la suite faute de `x` / `y`. Voir 4.3.

### 4.3 Aucun pixel n'est obligatoire

**La plus petite mise en page valide ne contient pas un seul nombre :**

```json
"layout": { "composants": [ { "champ": "objet" }, { "champ": "statut" } ] }
```

Elle donne une grille de 12 colonnes, un espacement normal, et des champs à
leur hauteur naturelle. C'est le cas le plus fréquent, et il ne se déclare pas.

Quand il faut s'en écarter, **un nom suffit** — les pixels ne servent qu'au cas
précis. C'est exactement la règle des couleurs : `bleu` ou `#2563eb`.

| Dimension | Par un nom | Par une valeur |
|---|---|---|
| Espacement d'une grille | `espacement` : `aucun` `serre` `normal` `large` `tres_large` | `gap_px` (0–64) |
| Marges du formulaire | `marges` : `aucune` `normale` `large` `tres_large` | `marges_px` (0–64) |
| Hauteur d'une saisie | `taille` : `compacte` `normale` `grande` `tres_grande` | `hauteur_px` (16–2000) |
| Seuil responsive | `sous` : `telephone` `tablette` `ecran_etroit` | `en_dessous_de_px` (240–2560) |

`taille: "normale"` ne vaut **pas** un nombre : elle laisse le champ à sa
hauteur naturelle, que le thème peut faire varier. C'est la valeur par défaut,
et la raison pour laquelle on n'écrit presque jamais de hauteur.

> Déclarer les deux formes sur le même objet est **refusé** : deux façons de
> dire la même dimension finissent par se contredire, et il faudrait alors
> arbitrer en silence.

Pour mémoire, les six modules livrés avec Larka couvrent des écrans très
différents — de 4 à 12 colonnes, cartes, panneaux, volets repliables — avec
**six valeurs en pixels au total**, toutes des plafonds de largeur sur des
champs numériques. Deux d'entre eux n'en emploient aucune.

### 4.4 Le conteneur

| Propriété | Valeurs | Défaut |
|---|---|---|
| `type` | `grille` | `grille` |
| `colonnes` | 1 à 24 | 12 |
| `espacement` / `gap_px` | voir 4.3 | `normal` (12 px) |
| `gap_x_px` / `gap_y_px` | 0 à 64 | l'espacement du conteneur |
| `marges` / `marges_px` | voir 4.3 | `aucune` (0) |
| `alignement_h` | `gauche` `centre` `droite` `etire` | `etire` |
| `alignement_v` | `haut` `centre` `bas` `etire` | `etire` |
| `responsive` | jusqu'à 4 paliers | aucun |
| `composants` | la liste des composants | obligatoire |

### 4.5 Les cinq natures de composant

Un composant déclare **exactement une** nature : `champ`, `section`, `texte`,
`image` ou `espace`. En déclarer deux, ou aucune, est refusé — on ne saurait
pas quoi dessiner.

#### Un champ

```json
{ "champ": "cout", "x": 1, "y": 3, "largeur": 4, "hauteur_px": 40,
  "largeur_min_px": 120, "largeur_max_px": 320,
  "alignement_h": "droite", "masquer_libelle": false,
  "visible_si": { "champ": "statut", "operateur": "egal", "valeur": "Clos" } }
```

`x` et `y` sont **facultatifs** : sans eux, le composant se range à la suite.
On ne pose des coordonnées que là où l'on veut vraiment décider.

* `largeur` — nombre de colonnes occupées (1 à `colonnes`)
* `hauteur` — nombre de lignes occupées (1 à 200)
* `taille` — `compacte` `normale` `grande` `tres_grande` (voir 4.3)
* `largeur_px`, `hauteur_px`, `largeur_min_px`, `largeur_max_px`,
  `hauteur_min_px`, `hauteur_max_px` — de 16 à 2000 px, pour le cas précis
* `masquer_libelle` — pour un champ dont le voisin porte déjà l'intitulé

Un champ `formule` ne peut pas être placé : il est calculé, pas saisi. Un champ
placé **deux fois** est refusé — deux cases pour une même donnée, et rien ne
dirait laquelle est enregistrée.

#### Une section

```json
{ "section": "Identification", "style": "carte", "couleur": "bleu",
  "colonnes": 6, "x": 1, "y": 1, "largeur": 12,
  "repliable": true, "repliee": false,
  "composants": [ { "champ": "equipement", "largeur": 6 } ] }
```

Habillages : `carte`, `panneau`, `section`, `encadre`, `discret`.
Une section porte sa propre grille (`colonnes`, `gap_px`) et se place dans celle
de son parent. Imbrication : 4 niveaux au maximum.

Une section `repliable` est rendue comme un volet natif du navigateur : le pli
est tenu sans une ligne de script, et reste accessible au clavier.

#### Un texte

```json
{ "texte": "Renseignez l'équipement concerné", "style": "aide", "largeur": 12 }
```

Styles : `normal`, `titre`, `aide`. Le texte est une **donnée**, échappée au
rendu et bornée à 200 caractères : il ne peut pas transporter de balisage.

#### Une image

```json
{ "image": "assets/marguerite.jpg",
  "description": "Marguerite commune en fleur",
  "largeur": 4, "hauteur": 2,
  "cadrage": "couvrir", "arrondi": "leger" }
```

L'image désigne un **fichier livré dans le paquet**, sous `assets/`, exactement
comme le `logo`. Formats : `png`, `jpg`, `webp`.

* `cadrage` — `contenir` (entière, quitte à laisser du vide), `couvrir`
  (remplit, quitte à rogner), `naturel` (taille d'origine)
* `arrondi` — `aucun`, `leger`, `fort`, `cercle`
* `taille` / `hauteur_px`, `hauteur_min_px`, `hauteur_max_px`, `largeur_max_px`

Une image à qui l'on donne plusieurs lignes (`hauteur`) les **remplit**, sauf
si une hauteur explicite est déclarée.

**`description` est obligatoire.** Une image sans description n'existe pas pour
qui ne la voit pas — lecteur d'écran, connexion lente, fichier manquant — et
l'auteur est le seul à savoir ce qu'elle montre. La chaîne vide reste acceptée :
c'est la façon correcte de déclarer une image **décorative**, qui est alors
retirée de l'arbre d'accessibilité. Ce qui est refusé, c'est l'absence de choix.

Trois choses qu'une image ne peut pas être, et pourquoi :

| Refusé | Raison |
|---|---|
| Une adresse extérieure (`https://…`) | Ce serait une sortie réseau — donc un moyen de pistage, à relire comme du code |
| Une image encodée (`data:…base64`) | Gonflerait la déclaration et la rendrait illisible avant installation |
| Un `.svg` | Le format accepte un `<script>` : servi au navigateur, il exécuterait du code |

Le fichier est servi par la route `ext_image`, qui exige une session, vérifie le
chemin, et contrôle que le contenu **est** une image par ses octets — un fichier
nommé `.png` qui serait du HTML est refusé là. Un module ne peut pas désigner
l'asset d'un autre module, ni sortir de son dossier par `../`.

> Une image déclarée mais **absente** du dossier fait échouer la construction du
> paquet. Un module dont une pièce manque n'est pas un module : c'est une
> promesse, et elle se constate trop tard — l'auteur, lui, a le fichier sur son
> disque.

#### Un espace

```json
{ "espace": true, "largeur": 2, "taille": "compacte" }
```

Un vide qui pousse le composant suivant. Neutre pour les lecteurs d'écran.

### 4.6 Affichage conditionnel

`visible_si` s'écrit avec **la même grammaire** que celle d'un champ, d'un
surlignage ou d'un filtre — rien de nouveau à apprendre, et rien de nouveau à
sécuriser. Une section entière peut ainsi n'apparaître que dans un cas :

```json
{ "section": "Clôture", "visible_si": {
    "tous": [ { "champ": "statut", "operateur": "egal", "valeur": "Clos" },
              { "champ": "cout", "operateur": "superieur_a", "valeur": 0 } ] },
  "composants": [ { "champ": "note", "largeur": 12 } ] }
```

Le masquage est un **confort d'affichage**, jamais un contrôle : c'est le
serveur qui décide de ce qui est enregistré.

### 4.7 Responsive

```json
"responsive": [
  { "sous": "tablette",  "colonnes": 6 },
  { "sous": "telephone", "colonnes": 1, "espacement": "serre" }
]
```

Ou avec des seuils exacts, si l'écran l'exige :

```json
"responsive": [ { "en_dessous_de_px": 880, "colonnes": 4 } ]
```

L'auteur déclare un seuil et un nombre de colonnes — deux entiers bornés. C'est
Larka qui en fabrique la règle de style : **l'auteur n'écrit jamais de CSS, et
ne peut donc pas en injecter.**

Sous un palier, trois choses se produisent :

1. la grille adopte le nombre de colonnes du palier ;
2. chaque portée est ramenée à ce nombre — une largeur de 6 devient 1 sur un
   téléphone ;
3. les positions fixées sont relâchées — la colonne 7 n'existe plus quand il
   n'en reste qu'une, et l'honorer rouvrirait une grille à sept colonnes.

Seuils acceptés : 240 à 2560 px, quatre paliers au maximum.

### 4.8 Sécurité de la mise en page

| Règle | Effet |
|---|---|
| Catalogue **fermé** | Toute propriété hors liste est refusée, y compris inconnue |
| Aucune chaîne de style | `style`, `class`, `css`, `html`, `onclick`… sont refusés |
| Couleurs vérifiées | Un nom du catalogue ou `#rrggbb` ; ni `url()` ni `expression()` |
| Textes échappés | Le texte d'auteur n'atteint jamais le HTML sans échappement |
| Images bornées | Un fichier du paquet, sous `assets/`, jamais une URL ni un SVG |
| CSS sans texte d'auteur | La feuille produite ne contient que des nombres |
| Champs vérifiés | Un champ placé doit exister, ne pas être calculé, et être unique |
| Bornes strictes | 300 composants, 4 niveaux, 24 colonnes, 2000 px, 64 px d'espacement |

> **Une mise en page ne donne aucun droit.** Elle ne crée aucun champ, n'ouvre
> aucune action, ne demande aucune capacité et ne modifie aucun rôle. Ajouter un
> `layout` à une page laisse les capacités requises, les actions servies, les
> rôles et les champs du jeu **strictement identiques** — c'est vérifié par une
> épreuve dédiée, parce que c'est la raison pour laquelle cette primitive est
> sûre.
>
> Elle en RETIRE un, en revanche : les champs qu'elle n'expose pas ne sont plus
> saisissables sur cet écran, côté serveur comme côté client. Voir § 3.1.

`layout` et `champs_formulaire` ne se déclarent pas ensemble : les deux
choisissent les champs du formulaire, et deux listes qui désignent la même chose
finissent toujours par diverger.

---

## 5. Ancrages

Un ancrage insère une primitive **que Larka dessine** dans un écran existant.
L'auteur choisit quoi afficher et d'où le tirer ; il n'écrit jamais comment.

| Type | Ce qu'il produit | Emplacement |
|---|---|---|
| `compteur` | Une tuile chiffrée | `dashboard.tuiles` |
| `jauge` | Une tuile avec agrégat | `dashboard.tuiles` |
| `liste_liee` | Les enregistrements liés | `equipement.fiche`, `bien.fiche` |
| `marqueurs` | Des repères sur le plan | `plans.barre_outils` |
| `formulaire` | Le module devient un type de demande | `demandes.type` |

Un ancrage déclaratif exige la capacité `ui.ancrage`.

---

## 6. Capacités

Elles sont **déduites** de la déclaration, jamais réclamées :

| Capacité | D'où elle vient |
|---|---|
| `ui.page` | Le module déclare une page |
| `donnees.table_privee` | Il déclare un jeu de données |
| `ui.ancrage` | Il déclare un ancrage |
| `ui.retouche` | Il retouche l'interface |
| `fichiers.dossier` | Il déclare un dossier de fichiers |
| `fichiers.import` | Un de ses dossiers accepte le dépôt |
| `donnees.lire:<domaine>` | Un champ `lien` ou une source de suggestions |

Un auteur ne peut donc pas réclamer un accès dont sa déclaration n'a pas l'usage.

---

## 7. Actions du moteur

`dp_lister` · `dp_enregistrer` · `dp_supprimer` · `dp_exporter` · `dp_ancrage` ·
`dp_cibles` · `dp_suggestions` · `dp_ajouter_valeur` · `dp_reglages` ·
`dp_definir_reglages`.

Une extension n'atteint jamais SQL : elle décrit une intention, et le moteur
compose la requête. L'action `SUPPRIMER` d'une table du cœur n'existe pas pour
un module.

Chacune vérifie la surface de l'appelant (§ 3.1) avant de faire quoi que ce
soit : `dp_lister`, `dp_exporter`, `dp_ancrage`, `dp_cibles` et `dp_suggestions`
exigent une page ouverte à son rôle ; `dp_enregistrer` exige en plus l'action
`creer` ou `modifier` et n'écrit que les champs exposés ; `dp_ajouter_valeur`
exige que le champ visé soit à sa saisie. `dp_definir_reglages` est une action
d'**administration** : elle est réservée au gestionnaire, comme la route
`ext_reglages` qui mène au même fichier.

### Espace de tables

Les tables d'un module s'appellent `ext_<identifiant>_<jeu>`, les points et les
tirets de l'identifiant devenant `_`. Deux identifiants qui aboutiraient au même
préfixe — ou dont l'un serait le début de l'autre — se disputeraient le même
espace : l'installation du second est **refusée**, en clair, avec le nom du
module déjà en place.

---

## 8. Partages et calculs

Un module ne peut pas lire la table d'un autre. Il peut **publier** ce qu'il
accepte de partager, champ par champ, et un autre module peut alors l'agréger :

```json
"calculs": { "stock": { "type": "somme", "de": "acme.stock/stock_cles",
                        "champ": "quantite", "ou": { "reference": "@numero" } } }
```

Opérations : `somme`, `compte`, `moyenne`, `minimum`, `maximum`.
`@champ` désigne un champ de la ligne courante, et part en paramètre lié —
jamais dans le SQL.

---

*Les catalogues complets — fonctions d'expression, opérateurs de condition,
formats de saisie, couleurs et cibles de lien — figurent ci-après. Ils sont
**générés depuis le code** par `Documentations/manuel-source/generer-reference.php`.*

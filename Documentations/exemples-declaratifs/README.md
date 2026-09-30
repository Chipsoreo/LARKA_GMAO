# Exemples de packs de données

Neuf déclarations complètes, de la plus simple à la plus complète. Toutes sont
**vérifiées à chaque construction** : elles valident contre le schéma réel, et
elles ont été installées puis exécutées sur une base pour s'assurer qu'elles
produisent bien ce qu'elles annoncent.

| Fichier | Montre | Capacités |
|---|---|---|
| `1-registre-simple.json` | Le minimum utile : un jeu, trois champs, une liste | `ui.page` `donnees.table_privee` |
| `2-tous-les-types.json` | Un champ de chaque type, avec ses contrôles | idem |
| `3-formules-et-conditions.json` | Champs calculés et champ affiché sous condition | idem |
| `4-ancrages.json` | Tuile, repères sur plan, bloc de fiche | + `ui.ancrage` |
| `5a-fournisseur.json` | Publie une partie de ses données | idem |
| `5b-consommateur.json` | Additionne le stock tenu par `5a` | idem |
| `6-liens-et-totaux.json` | Liens vers les équipements et contrats, défaut `{{today}}`, totaux, regroupement | + `donnees.lire:equipements` `donnees.lire:contrats` |
| `7-mise-en-page.json` | La mise en page : grille de 12 colonnes, champs placés, une section, et un formulaire qui tient sur un téléphone | + `donnees.lire:equipements` |
| `8-interface-complete.json` | **Une interface complète** : cartes, panneaux, encadré, section conditionnelle, alignements, bornes de taille, trois paliers responsive, actions rapides, surlignage et vue calendrier | + `ui.ancrage` `donnees.lire:contrats` `donnees.lire:interventions` |

## La mise en page en trois minutes

`7-mise-en-page.json` est le bon point de départ. L'idée tient en deux règles :

1. **Une grille de colonnes**, pas des pixels. On dit « ce champ occupe 6
   colonnes sur 12 », et le rendu s'adapte à l'écran. Des coordonnées en pixels
   ne survivraient ni à une police plus grande, ni à une traduction plus longue.
2. **`x` et `y` sont facultatifs.** Sans eux, les champs se rangent à la suite.
   On ne pose des coordonnées que là où l'on veut vraiment décider.

```json
"layout": {
  "type": "grille", "colonnes": 12, "gap_px": 16,
  "responsive": [{ "en_dessous_de_px": 640, "colonnes": 1 }],
  "composants": [
    { "champ": "objet",   "x": 1, "y": 1, "largeur": 8 },
    { "champ": "urgence", "x": 9, "y": 1, "largeur": 4 }
  ]
}
```

Ce que la mise en page **ne peut pas** faire, par construction : porter du
style, du balisage ou du comportement ; placer un champ qui n'existe pas ;
placer deux fois le même champ ; ou accorder le moindre droit. Elle dispose des
champs, et rien de plus — voir `FORMAT-DECLARATIF-REFERENCE.md`, section 4.

Les capacités du sixième sont **déduites de ses champs `lien`** : l'auteur ne
les demande pas, elles découlent de ce qu'il déclare — il ne peut donc pas en
réclamer plus que nécessaire.

## Les essayer

```bash
# valider une déclaration
php outils/verifier-module.php <dossier-du-module> --declaration

# vérifier les neuf d'un coup
php Documentations/exemples-declaratifs/verifier.php
```

Pour en installer un : copiez le JSON dans
`extensions/<identifiant>/extension.json`, puis

```bash
php api/extensions/outils/construire-paquet.php extensions/<identifiant>
```

## Résultats observés

Ce ne sont pas des promesses : les valeurs ci-dessous viennent d'une exécution.

**3 · formules** — une ligne à 3 unités à 0,40 € avec un seuil de 5 :
`HT 1,20 · TTC 1,44 · À réapprovisionner · 81 jours`.
Le champ `fournisseur` n'apparaît que si l'origine vaut « Commande externe ».

**4 · ancrages** — tuile « Clés attribuées » : 1 · repères sur l'étage 4 : 1 ·
clés de l'équipement n° 7 : 1.

**5 · partage** — la clé `A-102` affiche **8** doubles en armoire, somme de deux
lignes (5 + 3) tenues par un **autre module**. `Z-9`, absente du stock, affiche
0. Le consommateur n'a jamais accès à la table du fournisseur : il reçoit un
nombre.

## Ce que ces exemples ne montrent pas

Aucun ne contient de code, et aucun ne peut en contenir. Pour un appel à un
service externe, un envoi de courriel ou une interaction sur mesure, il faut un
**module à code** — voir `Larka-Creer-un-module.pdf`.

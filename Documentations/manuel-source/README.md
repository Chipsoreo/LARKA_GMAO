# Génération des catalogues de la référence

Ce dossier ne contient plus qu'un script : `generer-reference.php`.

```bash
php Documentations/manuel-source/generer-reference.php
```

Il lit `api/extensions/declaratif/Schema.php` et écrit
`../FORMAT-DECLARATIF-REFERENCE-CATALOGUES.md` — la liste des types de champs,
des opérateurs, des fonctions d'expression et des ancrages reconnus par le code.

`outils/epreuves/toutes.sh` le lance à chaque passage, puis supprime le fichier
produit : ce qui est vérifié, c'est que la génération **fonctionne encore**. Si
elle échoue, c'est que le schéma a changé d'une façon que le générateur ne sait
plus lire — et que la référence livrée est en train de diverger du code.

## La version Word

`../FORMAT-DECLARATIF-REFERENCE.docx` est une **copie de confort** du `.md`,
pour qui préfère lire dans Word. Elle n'est la source de rien : toute correction
se fait dans `FORMAT-DECLARATIF-REFERENCE-SPEC.md`, puis :

```bash
php Documentations/manuel-source/generer-reference.php
pandoc Documentations/FORMAT-DECLARATIF-REFERENCE.md \
       -o Documentations/FORMAT-DECLARATIF-REFERENCE.docx \
       --from=gfm --toc --toc-depth=3 -V lang=fr \
       --reference-doc=Documentations/manuel-source/reference-larka.docx
php Documentations/manuel-source/habiller-reference.php
```

`reference-larka.docx` est le modèle de styles : police Calibri (celle des
manuels et du DAT), couleurs Larka (marine `#14233B`, bleu `#2B7BE6`), titres,
tableaux à en-tête marine, blocs de code en Consolas, encadrés à barre bleue.
`logo-larka.png` est la marque Larka utilisée en page de garde.

`habiller-reference.php` ajoute ce que pandoc ne produit pas : la page de garde
(logo Larka, version de la référence — constante `REFERENCE_VERSION` —, version
de Larka lue dans `version.json`), le pied de page, le sommaire intitulé
« Sommaire » et des tableaux pleine largeur. Il ne touche pas au contenu, et ne
fait rien sur un fichier déjà habillé. Le sommaire reste un champ Word : il se
remplit à l'ouverture (« Mettre à jour les champs »).

Le `.docx` était resté en arrière d'une version : il décrivait un format que le
code ne servait plus. Un document de référence périmé est pire qu'absent — on
s'y fie.

## Ce qui a été retiré, et pourquoi

Ce dossier contenait aussi une chaîne de génération en JavaScript
(`construire.js`, `lib.js`, `partie-*.js`, `annexes.js`, `catalogue.json`,
`vocabulaire.*`) qui produisait `../Larka-Creer-un-module.docx` et son PDF.

Elle a été supprimée pour deux raisons :

1. **Le document produit n'existait plus** dans `Documentations/`. La chaîne
   tournait donc pour rien, ou plus personne ne la lançait.
2. **Son contenu avait divergé.** Il décrivait un format que le moteur ne
   servait plus.

Ce paragraphe renvoyait ensuite à `../Larka-Creer-un-module-extension.md`, qui
n'existe pas : le manuel d'auteur a fusionné avec la référence. Un seul document
fait donc autorité aujourd'hui — `../FORMAT-DECLARATIF-REFERENCE-SPEC.md`, dont
`../FORMAT-DECLARATIF-REFERENCE.md` est l'assemblage avec les catalogues
extraits du code.

Deux documents qui se contredisent sont pires qu'un seul : on ne sait plus lequel
croire. Et un renvoi vers un fichier absent coûte à chaque lecteur le temps de
vérifier qu'il ne l'a pas perdu.

Pour apprendre par l'exemple plutôt que par la grammaire :
`../exemples-declaratifs/` contient neuf modules complets, du registre le plus
simple à l'interface entièrement mise en page, tous vérifiés contre le schéma
courant par `verifier.php`.

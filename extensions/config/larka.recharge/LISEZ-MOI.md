# Configuration — Bornes de recharge

`larka.recharge`

## listes.json

Les listes de choix du module. C'est **la** source : le module lit ce
fichier, pas la base de données. Modifiez-le à la main, il est fait pour.

```json
{
  "MaCategorie": {
    "libelle": "Ma catégorie",
    "obligatoire": false,
    "libre": false,
    "valeurs": ["Première", "Deuxième"]
  }
}
```

- `obligatoire` — le champ doit être renseigné.
- `libre` — une valeur hors liste est acceptée à la saisie.
- `valeurs` — l'ordre du fichier est l'ordre du menu déroulant.

Retirer une valeur la retire du menu. Les fiches qui la portaient déjà
la conservent, signalée « retirée de la liste » : rien n'est perdu, et
elles restent modifiables.

## reglages.json

Les réglages du module, s'il en déclare. L'écran Modules l'emporte sur
ce fichier : ce sont des valeurs de départ, pas un verrou.

## Réinstallation

Ces fichiers ne sont **jamais écrasés**. Une mise à jour du module ajoute
les listes qu'il déclare en plus, sans toucher à ce que vous avez écrit.

## Plusieurs clients sur le même serveur

Chaque client a son fichier, dans un sous-dossier à son nom :

```
<client>/listes.json   ← la configuration de CE client
```

Il n'y a **rien à la racine** dans ce cas : un second fichier du même
nom, un niveau plus haut, ne servirait qu'à se tromper de fichier. Les
valeurs de départ viennent de la déclaration du module.

# extensions/config/

Configurations déposées à la main, un fichier JSON par module, nommé
`<identifiant>.json`.

```
extensions/config/acme.suivi-cles.json
```
```json
{ "prealerte_jours": 30, "couleur_module": "#0b6e4f" }
```

Les clés sont celles des `reglages` déclarés par le module.

## Précédence

1. réglages posés dans l'écran Modules — `data/extensions/<id>.reglages.json`
2. **ce dossier**
3. `defaut` déclaré au manifeste

Le fichier fournit donc des **défauts**. L'écran l'emporte sur lui : un fichier
qui écraserait l'écran donnerait un réglage qu'on modifie sans effet et sans
explication.

## À quoi ça sert

Préparer un déploiement, livrer une configuration type à un client, ou
versionner ce qu'on veut retrouver après réinstallation — les réglages posés
depuis l'écran vivent sous `data/`, qui n'est pas versionné.

Un fichier illisible est ignoré sans bruit : une configuration facultative ne
doit pas empêcher un module de démarrer.

# demo/ — démonstration « Ville de Larkaville »

`sauvegarde-demo-Larkaville.zip` : une commune fictive, cinq ans d'activité dans tous les modules, des comptes
pour chaque rôle. Tout est inventé : personnes, entreprises, numéros, adresses.

Larka la trouve ici tout seul :

- **Windows** — au premier lancement de `Larka.cmd`, elle est proposée : répondez `O`. Plus tard : `Larka.cmd demo`.
- **Linux** — proposée à la fin de `./start.sh install` et au premier `./start.sh start`. Plus tard : `./start.sh demo`.

La base en place est d'abord mise de côté dans `data/backups/`. Connexion : `claire.moreau` / `Larka2026!`
(l'écran de connexion de la démonstration affiche aussi les autres profils).

Le guide complet (restauration à la main, PostgreSQL, comptes) est dans l'archive : `LISEZ-MOI-DEMO.md`.

Ce dossier est livré dans l'archive de chaque version, et apporté par la mise à jour (en ligne, `./start.sh maj`,
`Larka.cmd maj`) aux installations de poste ou de développement. Il n'est jamais copié sur un serveur de production
(`deploy/install.sh`, ni par une mise à jour quand `config.json` est en `env: prod`), et le serveur web le refuse.

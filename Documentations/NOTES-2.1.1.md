# Larka V2.1.1 — notes de version

Version corrective de la 2.1.0. Une seule évolution de la base, appliquée d'elle-même au premier démarrage (une colonne pour les cases
complémentaires des biens et des équipements) ; la mise à jour se fait depuis
l'interface, ou depuis l'archive : `./start.sh maj LARKA_GMAO-2.1.1.zip` (Linux), `Larka.cmd maj …` (Windows).

## Mises à jour : la configuration n'est plus réécrite

- **Nouvelle commande `maj`** (`./start.sh maj <zip>`, `Larka.cmd maj <zip>`,
  `php api/outils/mise-a-jour.php --archive <zip>`) : applique l'archive d'une version avec les protections de
  la mise à jour en ligne — `config.json`, `.env`, `config.key`, `data/`, `php/` et les réglages de modules
  existants ne sont jamais écrits, fichiers et bases sont sauvegardés avant, retour arrière automatique en cas
  d'échec. Décompresser une version par-dessus l'ancienne n'est plus nécessaire (ni conseillé).
  **Pour passer de 2.1.0 à 2.1.1** : la mise à jour en ligne de Larka la propose d'elle-même (release
  signée). Sans accès à GitHub : décompresser la 2.1.1 à côté, puis depuis ce dossier
  `./start.sh maj --cible /chemin/de/larka LARKA_GMAO-2.1.1.zip`
  (Windows : `Larka.cmd maj --cible C:\chemin\de\Larka LARKA_GMAO-2.1.1.zip`) ; `--sans-signature`
  seulement pour une archive non signée (copie de travail, jamais celle de la release).
- `start.sh` et `deploy/install.sh` modifient `config.json` **clé par clé, en place** (propriétaire et droits
  conservés), seulement si une valeur change, après une copie dans `data/backups/config/`. Les objets vides
  `{}` ne deviennent plus des listes `[]`. Un `config.json` contenant une valeur modèle oubliée n'est plus
  régénéré en entier : seules ces valeurs sont vidées.
- `.env` n'est plus remplacé : il gardait le propriétaire root après un `sudo ./start.sh …` et PHP-FPM ne
  pouvait plus le lire.
- Une mise à jour lancée en root conserve le propriétaire des fichiers remplacés.
- `deploy/install.sh` garde la version précédente du vhost Nginx, du pool PHP-FPM et du cron (`*.bak-larka`).

## Instance déjà touchée par la 2.1.0 : réparer

La 2.1.1 empêche ces problèmes de revenir, mais ne répare pas d'elle-même ce que la 2.1.0 a déjà abîmé.
Après la mise à jour :

- **Erreurs de base, page blanche** : `.env` ou `config.json` est resté à root. `./start.sh doctor` le signale
  (`GMAO-E35`) et **`./start.sh doctor --fix`** le rend à son propriétaire (`www-data` en production). À la main :
  `sudo chown www-data:www-data /chemin/de/larka/.env && sudo chmod 600 /chemin/de/larka/.env` (en développement :
  votre utilisateur à la place de `www-data`).
- **SharePoint a disparu** : `./start.sh sharepoint on` (ou *Configuration → Serveur*). `doctor` le signale quand la
  connexion Microsoft est active et SharePoint coupé.
- **Présence et agenda Microsoft** : un clic sur **🔑 Reconnecter Microsoft**.
- **Réglages perdus dans `config.json`** (OAuth, SMTP, présence…) : `doctor` liste les copies
  `config.json.bak-AAAAMMJJ-HHMMSS` laissées par la 2.1.0 ; reprenez-y les valeurs, ou ressaisissez-les dans
  *Configuration → Serveur*.
- **Réglages de modules remis à zéro** (version décompressée par-dessus l'ancienne) : à refaire dans les écrans
  des modules — la 2.1.0 n'en gardait pas de copie.
- Windows n'est pas concerné par les propriétaires de fichiers.

## SharePoint et présence Microsoft

- **SharePoint ne disparaît plus** après une relance : le choix « SharePoint : non » était inscrit d'office
  dans `config.json` dès que `start.sh` ou `install.sh` tournait sans terminal (démarrage automatique, `-y`),
  sur des installations où SharePoint était actif. Le choix n'est plus demandé que pour une configuration
  neuve, et rien n'est inscrit sans réponse.
- **Présence et agenda** : connecté avec Microsoft, la présence demandait encore de « se connecter via
  Microsoft ». Les jetons Microsoft n'étaient pas transmis à la session de la fenêtre principale quand elle
  l'ouvrait par le relais de la fenêtre de connexion. Ils le sont désormais.
- Un jeton Microsoft expiré et non renouvelable n'est plus gardé (Graph répondait 401 en boucle), et ne
  déconnecte plus l'utilisateur de Larka (le serveur répondait 401, pris pour une fin de session).
- Le renouvellement du jeton demande les mêmes autorisations qu'à la connexion (il échouait quand SharePoint
  était désactivé).
- Bouton **🔑 Reconnecter Microsoft** (présence, agenda, documents SharePoint) : reprend la connexion
  Microsoft dans une fenêtre, sans quitter l'écran.

## Démonstration

- La démonstration « Ville de Larkaville » est **livrée dans l'archive** (`demo/`) et apportée par la mise à
  jour aux installations de poste ou de développement : `./start.sh` et `Larka.cmd` la proposent de nouveau
  (premier lancement, menu Maintenance, `./start.sh demo`). Jamais copiée en production.

## Biens et équipements

- **Suppression groupée** : cases à cocher dans les listes (Maj+clic pour une plage, « sélectionner les N
  résultats filtrés »), puis « Supprimer la sélection » — une seule requête, chaque suppression tracée dans
  l'historique comme une suppression unitaire.

## Cases complémentaires des fiches (section « fourre-tout »)

- Les fiches des biens et des équipements ont une section **🗂️ Informations complémentaires** : des
  cases que Larka ne prévoit pas (date de sortie administrative, code analytique, n° de marché, n° de
  l'ancien logiciel…), de type texte, texte long, date ou nombre.
- Créées **à l'import** — une colonne du fichier sans champ Larka devient une case (« ➕ Nouvelle case »,
  ou « 🗂️ Créer les cases » pour toutes les colonnes restantes ; nom et type modifiables) — ou à la main
  (bouton **🗂️ Cases des fiches** des listes : ajouter, renommer, ordonner, retirer).
- Un import suivant reconnaît les cases existantes par leur nom. La recherche des listes porte aussi sur
  leurs valeurs. Retirer une case ne supprime pas les valeurs déjà saisies.

## Import Excel / CSV

- **Sélection des lignes** (nouvelle étape) : une case par ligne ; tout cocher / décocher / inverser,
  par famille, **seuil de prix** (min / max), sans les biens sortis ; recherche ; total de la valeur cochée.
- **Contrôle ligne par ligne** avant envoi : N° vide ou trop long, date ou montant illisible, montant
  négatif, N° en double dans le fichier, ligne de total — avec la colonne et la valeur en cause.
- **Rapport** d'erreurs et d'avertissements regroupé par motif, **téléchargeable en CSV**, à l'aperçu et
  après l'import (le serveur signale aussi les dates et montants illisibles au lieu de les vider en silence).
- **Aide de l'IA** : si l'assistant est configuré, « 🤖 Proposer avec l'IA » suggère les correspondances
  colonnes → champs et la conversion des valeurs Statut / État. Seuls les en-têtes et 5 valeurs d'exemple
  par colonne sont envoyés au modèle ; la proposition est vérifiée par le serveur et reste modifiable.
- **Meilleure détection** : ligne d'en-tête cherchée sur les 20 premières lignes (titres et lignes de
  présentation ignorés), colonnes vides écartées, libellés en double distingués, échantillon pris dans tout
  le fichier, montants (« 1.234,56 », « 1 234,56 € », « (45,20) »), dates (JJ/MM/AA, JJ.MM.AAAA, numéro de
  série Excel, « 10 janvier 2020 ») ; correspondances **retenues d'un import à l'autre** (ce navigateur) ;
  CSV en UTF-16 et ligne `sep=;` d'Excel reconnus.
- Nouvelles valeurs de listes : **une case par valeur** pour choisir celles à créer.
- Nouveaux champs reconnus : **date de sortie** (« date de sortie technique », « date de mise au rebut »…),
  **commentaire de sortie**, étage et n° de bureau des biens. « Date de sortie administrative » n'est plus
  confondue avec une date d'acquisition : un libellé explicite ne se rattache plus sur le seul contenu.
- **Mise à jour d'une fiche existante** : seuls les champs lus dans le fichier sont remplacés. Avant, un
  réimport partiel vidait l'affectation, l'étage, la date de sortie… de chaque fiche mise à jour.
- La fiche ouverte juste après un import avait perdu son bouton « Enregistrer » : corrigé.
- Le serveur ne transforme plus « 1.234,56 » en 0 € ni « 31/02/2020 » en 3 mars.

## Divers

- Correctif interne : l'identifiant rendu après certaines créations (compteur d'énergie…) pouvait être celui
  d'une écriture précédente de la même requête.

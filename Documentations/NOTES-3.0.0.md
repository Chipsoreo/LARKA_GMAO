# Larka V3.0.0 — notes de version

> **V.Beta 3.0.1** (26 novembre 2026) : les sections marquées « 3.0.1 » — retours d'usage, archive publique
> allégée, audit de sécurité — sont celles de la 3.0.1, bêta corrective de la 3.0.0. Le reste décrit la 3.0.0.

Version majeure après la 2.1.1 (dont elle reprend les correctifs, décrits plus bas pour qui vient de la 2.1.0) — avec **Larka Connect** (bâtiments connectés, bornes OCPP :
voir `Documentations/LARKA-CONNECT.md`). Le résumé est dans le README (« Note de mise à jour »). Les évolutions de la base s'appliquent d'elles-mêmes au premier démarrage (cases complémentaires des biens, des
équipements, puis des interventions et du stock ; plans préventifs et de contrôle réglementaire) ; la mise à jour se fait depuis
l'interface, ou depuis l'archive : `./start.sh maj LARKA_GMAO-3.0.0.zip` (Linux), `Larka.cmd maj …` (Windows).

## 3.0.1 — Interventions, stock et énergie : retours d'usage

**Tableau de bord.** Le type d'intervention ne se choisit plus dans une liste déroulante : des **onglets**
(Tous, Curatives, Préventif, Contrôles réglementaires, Divers, et les types ajoutés), chacun avec son nombre
d'interventions prévues et en cours. Le choix vaut toujours pour le graphe et reste mémorisé sur le poste. La
colonne « Date » des prévues devient « Date prévue ».

**Interventions — affichage (bouton « Options d'affichage » de la liste, réglage de chacun, sur son poste).**
- *Fond coloré selon l'état* : vert réalisée ou terminée, orange en cours, bleu planifiée, rouge planifiée dont
  la date prévue est passée, gris archivée ; légende au-dessus de la liste. Teinte posée sur le fond des cartes :
  lisible en mode sombre.
- *Délai demande → réalisation* : calculé (jamais saisi), en jours calendaires, dans la liste et dans la fiche,
  recalculé quand une date change ; une réalisation antérieure à la demande est montrée comme une erreur de
  saisie. Éteintes par défaut, les deux options ne concernent que les interventions.

**Interventions — la fiche.**
- « Date d'intervention » s'appelle **« Date prévue »** (fiche, historique, tableau de bord) : c'est la même
  donnée, celle qui range une intervention dans « Prévues » ou « En retard » et que les plans fixent.
- **« Code / n° de demande »** passe du suivi administratif aux informations générales.
- Sections réordonnées : informations générales (numéro, code de demande, type, priorité, statut, demande
  d'origine, description, commentaire), dates et délais, liaisons, informations complémentaires, notes de suivi,
  devis, suivi administratif et facturation, pièces, documents, agent créateur (rempli d'office, en dernier). Un
  **sommaire** collé sous le titre mène à chaque section. (Le corps des fenêtres passe en `overflow-x: clip` :
  avec `hidden`, il devenait lui-même un conteneur de défilement et rien n'y restait collé.)
- **Cases complémentaires**, comme sur les biens et les équipements : bouton « Cases des fiches » de la liste,
  section « Informations complémentaires » de la fiche, recherche. À l'**import**, une colonne sans champ
  d'intervention devient une case (« Créer les cases », ou « Nouvelle case » colonne par colonne) ; une colonne
  qui porte le nom d'une case existante y va d'office ; l'aperçu annonce les cases créées et celles qui
  ressemblent à une case existante ou retirée. Le serveur ne remplit que des cases définies : une clé inventée
  par une requête forgée ne crée rien.
- La recherche de la liste trouve aussi le code de demande et le lieu.
- Sécurité : dans l'historique des changements de date, « qui » et « raison » (saisis par les utilisateurs)
  étaient insérés sans échappement, comme la date d'une note dans un attribut ; échappés.

**Stock.**
- **Import Excel / CSV** (bouton « Importer »), avec le même assistant que les biens et les interventions :
  fichier, feuille, en-tête et disposition détectés ; colonnes reconnues par titre, contenu et mémoire du dernier
  import ; catégories du fichier associées à celles de Larka ; aperçu par le serveur, rapport téléchargeable,
  import par lots inscrit au Journal (`api/ImportStock.php`, action `import_stock`).
- Un article déjà là est reconnu à sa **référence** (casse et accents ignorés), et à son **type** quand la ligne
  en donne un — la fiche prévoit plusieurs articles sous une référence. Sans type et plusieurs articles sous la
  référence : la ligne est refusée et le dit, plutôt que de choisir au hasard. Il est mis à jour sur les seules
  colonnes du fichier, ou laissé.
- Quantités d'un article déjà là : **remplacées** (inventaire) ou **ajoutées** (livraison). Jamais négatives ;
  non entières : arrondies et signalées.
- Référence obligatoire ; désignation exigée d'un nouvel article seulement — un bon de livraison « référence +
  quantité » suffit à mettre à jour l'existant.
- Catégorie nouvelle proposée à l'ajout dans la liste (décochable) ; contrat connu (n°, référence ou société) →
  « fourni par contrat » ; colonnes sans champ → cases complémentaires des fiches du stock.

**Énergie.**
- **Import depuis la supervision : l'index précédent n'était pas le bon.** Quatre causes, corrigées : le contexte
  cherchait le dernier relevé avant le *début de la période* (le 1er du mois) au lieu de la date du relevé ;
  l'index de début reprenait l'historique du point de supervision et non le compteur Énergie ; avec un serveur de
  supervision séparé, le contexte venait de sa copie partielle des relevés ; le relevé automatique et le
  rattrapage prenaient le dernier index sans borne de date. L'écran dit maintenant d'où vient l'index précédent,
  et propose de partir de l'historique du point quand les deux diffèrent.
- **Bouton « Vérifier »** de l'onglet Énergie : par compteur, les erreurs (index qui baisse ou recule, conso
  incohérente avec les index, doublon, dates impossibles) et les points à vérifier (date à venir, index précédent
  qui ne suit pas le relevé d'avant, deux saisies le même jour), avec un lien vers chaque relevé
  (`api/EnergieVerification.php`, action `energie_verifier`).
- Saisie contrôlée par le serveur : compteur existant, date réelle, nombres lus (« 1 000,5 »), index de fin
  inférieur à l'index de début refusé, consommation déduite des index si absente. Un index ou un montant à **0**
  était enregistré vide ; il est gardé. Les relevés du même jour gardent l'ordre de saisie.

**Base** : version de schéma 23 (`ChampsPerso` sur `Interventions` et `Stock`, ajoutées au premier démarrage).
**Épreuves** : `test-energie.php` (22 contrôles) et `test-import-stock.php` (24) ; `test-import-interventions.php`
couvre les cases complémentaires ; 39 épreuves au total.

## 3.0.1 — Versions bêta : au choix du Super Admin

Jusqu'ici, seul `mises_a_jour.canal` écrit à la main dans config.json écartait les bêtas. L'encadré des mises à
jour (Super Admin : bouton « Mises à jour » ; mono-tenant : Configuration → Serveur) porte maintenant une case
« Proposer les versions bêta » (route `maj_canal`, mêmes droits que les mises à jour, inscrite au journal de
sécurité). Le choix est rangé dans `data/maj/reglages.json` ; `mises_a_jour.canal` dans config.json, s'il est
écrit, l'emporte et l'écran le montre verrouillé. Décochée, la bêta déjà détectée n'est plus proposée (le cache
de détection est oublié), et la source « manifeste » applique la même règle que GitHub. Par défaut : bêtas
proposées, comme avant. Épreuve : `test-mise-a-jour.php` (2 contrôles de plus).

## 3.0.1 — Archive publique allégée

L'archive publiée (`outils/publier-version.php`) ne contient plus ce qui ne sert qu'à développer Larka — la règle
que `deploy/install.sh` appliquait déjà à la mise en production, appliquée à la publication :
- `outils/` : épreuves, simulateurs, outils de langue et d'icônes, publication. `outils/epreuves/` décrit, épreuve
  par épreuve, tout ce contre quoi Larka se défend — donc par où l'attaquer. Sans elles, `start.sh prod` le signale
  et continue ;
- dans `Documentations/` : sources des manuels (`manuel-source/`), exemples de modules (leur vérificateur est dans
  `outils/`), référence du format en Markdown (la version Word reste), et ces notes détaillées — les failles
  corrigées y sont expliquées, ce qui n'a pas à circuler avant que les serveurs aient eu le temps de se mettre à jour ;
- `.gitignore`, `.gitattributes`.

Restent dans l'archive : les manuels, le DAT, `CODES-ERREUR.md` et `LARKA-CONNECT.md` (l'aide et les messages
d'erreur y renvoient), la démonstration, `deploy/`. Tout reste dans le dépôt. Une mise à jour ne supprime aucun
fichier : une installation qui avait ces dossiers les garde (`LarkaMiseAJour::SI_PRESENTS`).

## 3.0.1 — Audit de sécurité de la 3.0.0

Relecture complète de l'archive (routes sans session, rôles, sessions, multi-tenant, fichiers servis, mises à
jour, modules, assistant). Ce qui a été trouvé, par gravité, et corrigé :

**Critique**
- **Prise de n'importe quel compte par la synchronisation de l'app mobile.** Un demandeur reliait un téléphone
  avec son mot de passe, puis réécrivait l'empreinte du mot de passe d'un autre compte — `admin` compris — et s'y
  connectait (la règle lisait l'« Id » envoyé par l'appareil, pas la clé visée). Il pouvait aussi s'approprier
  la demande d'autrui. Les droits d'écriture portent désormais sur la ligne du serveur **avant et après** la
  modification ; son propre compte : ni identifiant, ni rôle, ni droits, ni activation, et « mot de passe à
  changer » ne se lève qu'en changeant le mot de passe.

**Importante**
- **La synchronisation livrait toute la base à tout compte relié** : contrats, factures, interventions, tous les
  comptes (courriels, téléphones), Larka Connect, réglages. Chaque rôle ne reçoit plus que ce que le web lui
  montre : le demandeur ses demandes, ses documents, ses déclarations, l'annuaire s'il lui est ouvert et les
  onglets ouverts à son compte ; le visionneur et le technicien la consultation ; les comptes d'autrui réduits à
  l'annuaire ; jamais les réglages de sécurité, les jetons des PC releveurs ni les secrets Connect. Les médias
  d'urgence réservés aux gestionnaires ne partent plus sur les autres téléphones.
- **Multi-tenant : fichiers d'une autre organisation.** Sans session, l'organisation était déduite de
  l'adresse (à défaut, la première active) : un appareil listait, téléchargeait et — pour un gestionnaire —
  écrasait les plans, médias, thèmes et modules d'une autre organisation. Ce sont maintenant ceux de
  l'organisation du jeton.
- **Plans servis tels quels** : un SVG s'affichait dans l'origine de Larka (son script s'exécutait si on ouvrait
  le lien), et un type inconnu était deviné (`.html` → page web). SVG sous politique « sandbox », types inconnus
  en téléchargement. Un appareil n'envoie plus que des images et PDF (plans) ou photos et vidéos (médias) :
  ni SVG, ni fichiers de module ou de thème (ils s'installent par leur écran, paquet vérifié).
- **Super Admin supprimé** : sa session restait valide jusqu'à 4 h et son adresse restait autorisée par
  Microsoft. La session est revérifiée à chaque requête (compte, mot de passe de la connexion, adresse) et
  l'adresse part avec le compte.

**Faible**
- Un mot de passe changé (par soi, ou réinitialisé par un gestionnaire) ferme les autres sessions du compte ; de
  même pour le Super Admin (8 caractères minimum, comme ailleurs).
- Migration de base : une destination SQLite hors de `data/` est réservée au Super Admin (la base complète
  pouvait être écrite sous la racine web), et toujours en `.db` / `.sqlite` / `.sqlite3`.
- Synchronisation : réglages `securite_…` et PC releveurs non modifiables depuis un appareil (leurs écrans
  exigent une identité confirmée) ; documents contrôlés comme en ligne (types, SVG et HTML refusés, signature,
  taille) ; ligne du serveur jamais rendue dans un refus si l'appareil ne peut pas la lire ; plage d'identifiants
  seulement là où l'appareil peut créer ; tables du lien de supervision hors synchronisation.
- Connexion : le temps de réponse ne dit plus si un identifiant existe.
- Hors sécurité : `TenantResolver` utilisait le type `true` (PHP 8.2) alors que Larka annonce PHP 8.1 — toute
  l'application s'arrêtait sous 8.1 ; la liste des adresses Super Admin pouvait partir en objet JSON.

Revus sans défaut : routes publiques et chemins détournés, en-têtes et CSP, CSRF, mises à jour signées, paquets
de modules et de thèmes, lien chiffré de supervision, PC releveurs, commandes système, assistant par rôle, XSS
(tournée sur une base piégée). Nouvelle épreuve `test-synchro.php` (67 contrôles, dont 45 échouaient sur
l'archive auditée) ; `test-acces-coeur.php` étendue (sessions, Super Admin, migration).

## Mises à jour : les bêtas proposées à tous les serveurs

- Un serveur stable se voit maintenant proposer les bêtas publiées, signalées comme telles à l'écran (« Version
  bêta : en cours d'essai… ») ; l'installation reste validée à la main. Avant, il ne les voyait jamais.
- `"canal": "stable"` dans la section `mises_a_jour` de config.json les écarte. À numéro égal, la stable passe avant
  la bêta. Repasser en « stable » retire aussi une bêta déjà proposée.
- Vaut à partir de la 3.0.0 : une 2.1.1 suit encore son ancienne règle (canal « beta » à écrire dans sa config.json).

## Assistant et Larka Connect

L'assistant répondait déjà sur la supervision (consommations, alarmes, état, valeurs, et par le lien chiffré quand
la supervision est sur un serveur séparé). Essayé de bout en bout avec les trois rôles, il se trompait sur plusieurs
points, corrigés :

- **Collecte arrêtée** : « la GTB fonctionne ? » répondait « Tout fonctionne » puis « la collecte ne passe plus ».
  La réponse commence maintenant par l'arrêt de la collecte (date, code CXC-001) et donne l'état du dernier passage.
- « Capteurs en défaut ? » sans défaut : « Aucune connexion ni point en défaut », au lieu d'un état général.
- **Le bon point** : « le CO2 de la classe CM2 » renvoyait tous les CO₂ du parc ; les autres mots de la question
  départagent. « co2 » tapé au clavier trouve « CO₂ » (indices et exposants lus comme des chiffres).
- **Point d'état** : « la sous-station est en marche ? » affiche « arrêt » (et non 0), sans l'index de chaleur.
- **Bâtiments** : « l'école » désigne le groupe scolaire, « la mairie » l'hôtel de ville.
- **Tournures** reconnues : « combien d'électricité le mois dernier », « on a dépensé combien d'eau à l'école »,
  « il fait combien dans le bureau R+1 », « température extérieure », « un problème sur les compteurs ? ». Une
  fiche de la GMAO citée reste une question de GMAO (« interventions sur les capteurs en panne »).
- **Demandeur** : « combien d'alarmes ce mois-ci ? » devenait une demande « Alarme incendie défectueuse », et
  « consommation d'électricité de l'école » une demande « Électricité défectueuse ». Une question sur les mesures
  reçoit maintenant une explication (réservées aux gestionnaires) ; un signalement reste une demande, avec un titre
  clair pour une gêne (« température trop basse au bureau 12 » → *Température trop basse*).
- **Mode agent** (modèle de langage) : le prompt dit quand utiliser l'outil de supervision, qui accepte la nature du
  point (marche / arrêt).

## Documentation à jour

- **Manuels en version 3.0** (Gestionnaire, Visionneur & Utilisateur, Super-Administrateur) : nouveautés en tête
  de chaque manuel ; tableau de bord ; menu en groupes et préférences d'affichage ; fiches (import, cases
  complémentaires, suppression groupée) ; nouveau chapitre *Bâtiments connectés (Larka Connect)* ; préventif et
  contrôles réglementaires, lieu, import d'interventions ; confirmation d'identité (et le réglage Google « Session
  age claims » côté Super Admin) ; codes d'erreur ; mise à jour depuis une archive ; questions fréquentes
  complétées. Les listes numérotées repartent à 1 (plusieurs continuaient la numérotation de la précédente).
- **DAT 3.0** : § 2.8 *Larka Connect* (principes, protocoles, collecte et services, serveur de supervision séparé
  et lien chiffré, données), 32 modules de route, composants transverses, flux réseau F15 à F22, services,
  confirmation d'identité, mise à jour depuis une archive, limites connues, arborescence.
- **Référence du format déclaratif 3.0** : la version Word est régénérée (elle n'avait pas les icônes des tuiles).
- README : note de mise à jour (ce qui change depuis la 2.1.1 : Larka Connect, améliorations, changements
  esthétiques, corrections), fonctionnalités, structure et nombre d'épreuves. Les sections « Mises à jour » et
  « Publier sur GitHub » sont retirées : la mise à jour est décrite dans le manuel Super-Administrateur et le DAT
  (§ 9.3).

## Essais sur PostgreSQL 16 et MariaDB 10.11

- La base de démonstration complète (1 833 biens, 6 323 interventions, modules, supervision) a été migrée par
  l'outil de migration de Larka vers PostgreSQL et vers MariaDB, puis chaque écran ouvert avec les trois rôles,
  l'import d'un planning rejoué, un module installé et utilisé, et un lien de supervision monté ENTRE les deux :
  serveur principal sur PostgreSQL, serveur de supervision sur MariaDB (alarme → demande d'intervention).
- **Corrigé — modules sur MariaDB / MySQL** : toutes leurs requêtes plaçaient les noms entre "…", qui sont des
  chaînes pour MySQL : chaque module échouait, puis se suspendait après trois échecs. Les noms suivent
  maintenant les guillemets du moteur (`…` sous MySQL / MariaDB).
- **Corrigé — serveur de supervision délié** : l'adresse de l'ancien partenaire restait dans l'état et
  s'affichait au partenaire suivant (« Appairé avec http://… » d'un serveur délié depuis).
- Épreuves : `LARKA_EPREUVE_MYSQL` ajoute à l'épreuve du schéma la migration vers MariaDB / MySQL et un module
  qui y travaille. Toutes les suites passent en mode strict, volets PostgreSQL et MariaDB compris.

## Fichiers retirés

- Pack de langue espagnol (`extensions/langues/larka.langue-es*`) : 5 % traduit, dont plus de la moitié pour des
  textes qui n'existent plus — Larka ne le proposait d'ailleurs pas (un pack sous 90 % n'est pas offert). Ses 288
  traductions encore utiles sont gardées dans `lang/traductions.json` (colonne « es ») :
  `php outils/langues/construire-pack.php es` le reconstruit quand la traduction avancera.
- Code mort : sept fonctions JavaScript jamais appelées (anciens liens, doublons de fonctions existantes).

## Vérification générale : sécurité et corrections

- **Sécurité (XSS stockée)** : du texte saisi avec du HTML (« <img onerror=…> ») s'exécutait à l'affichage
  chez tous ceux qui ouvraient l'écran. Corrigé partout où des données s'affichaient sans être échappées : les
  listes en tableau (société d'un contrat, marque, modèle, fournisseur, bâtiment…), le bandeau d'information des
  demandeurs, les alertes stock du tableau de bord, le stock (marque / modèle), l'énergie (nom des compteurs),
  l'historique, la gestion matériel, les interventions (intervenant, marque), les archives, la mobilité.
  Vérifié en injectant ce HTML dans tous les champs texte de la base et en ouvrant chaque écran avec les trois rôles.
- **Tris par date** : les listes (Interventions « Date (récent) », Stock, colonnes de dates des tableaux) ne
  triaient que par ANNÉE (« 2026-03-15 » était lu comme le nombre 2026). Un seul comparateur, partagé.
- **Écrans réservés** : un lien profond ou une page restaurée après F5 ouvrait à un Visionneur Utilisateurs,
  Configuration ou Extensions, à un Demandeur Préventif, Archives ou la Supervision — l'écran se peignait puis
  « Permission insuffisante ». Redirection vers l'accueil du rôle avec un message clair.
- **Import, lieu** : une fiche déjà là sans lieu, et le fichier donnant le même passage à deux sites le même
  jour, recevait le premier lieu et le second passage disparaissait. Le second est maintenant créé.
- **Journal** : le pont du serveur de supervision (attente de 20 s) et le flux de l'assistant étaient comptés
  comme « routes lentes » et remplissaient les avertissements.
- **Affichage** : listes déroulantes avec recherche (Bien / équipement, Contrat d'un plan) réduites à la moitié
  de leur largeur ; numéros de téléphone coupés chiffre par chiffre dans la liste des utilisateurs.
- **Nettoyage** : messages de diagnostic retirés de la console, appel mort `EnergieApi.getStats` (action
  inexistante côté serveur) supprimé.
- **Traduction anglaise** : catalogue des libellés remis à jour (383 nouveaux, 17 disparus) ; 356 traduits.
  Les listes de synonymes des imports (« date prevue », « n bon de commande »…) ne sont plus récoltées comme
  libellés. Pack anglais 4.3.4 — à réinstaller (Extensions) pour en profiter.

## Supervision sur un serveur séparé, lien chiffré par Larka

- **Serveur de supervision** : une installation Larka sur le réseau technique, avec **sa propre base**
  (PostgreSQL, MariaDB, MySQL ou SQLite), collecte, mesures et alarmes ; le serveur principal ne joint plus
  aucun équipement. Réglé par le **gestionnaire** (Bâtiments → Connexions → Serveur de supervision) ; le
  Super Admin garde l'interrupteur du module par organisation. `api/SupervisionLien.php`.
- **Lien chiffré par Larka** (sodium : X25519 + XSalsa20-Poly1305), clés épinglées à l'appairage, horodatage,
  anti-rejeu, réponse liée à sa requête ; sûr même en HTTP clair. **Pont sortant** (la supervision appelle le
  principal, aucun port ouvert vers le réseau technique — recommandé) ou **port ouvert** (le principal
  appelle). Les bornes OCPP, passerelles LoRaWAN et capteurs MQTT parlent directement au serveur de supervision.
- **Appairage** par code à usage unique (30 min) et empreinte à comparer sur les deux écrans ; identité
  confirmée pour créer / utiliser un code ou délier. En ligne de commande : `api/outils/supervision.php`.
- Les écrans Supervision / Alarmes / Connexions du principal sont **servis par le serveur de supervision**
  (appels relayés avec le compte et le rôle de la personne, droits revérifiés). L'assistant lit la supervision
  par le même lien.
- **Synchronisation** chaque minute : référentiel du principal recopié (bâtiments, équipements, compteurs
  Énergie) ; demandes d'intervention des alarmes, relevés d'énergie et notifications **écrits par le principal
  dans sa base**, depuis une file d'attente (rien ne se perd pendant une coupure).
- Relais du pont (`api/outils/supervision-pont.php`) lancé tout seul par la collecte ; un relais arrêté est
  signalé tout de suite. Épreuve : `outils/epreuves/test-supervision.php` (deux serveurs réels, les deux
  sens, message altéré / rejoué / clé inconnue refusés, synchronisation, assistant).
- **App mobile** : non traitée dans cette version (le dossier `mobile/` n'était pas dans l'archive) ; le
  lien passe par HTTP(S) + JSON et se prête à un client JavaScript (WebCrypto ne fait pas XSalsa20 : une
  implémentation embarquée sera nécessaire).

## Assistant : la supervision

- L'assistant répond sur les équipements raccordés, sans modèle de langage (même question → même réponse) :
  « combien a consommé l'Hôtel de Ville en octobre ? », « total de conso d'électricité du 1er janvier au
  31 mars », « conso d'eau depuis le début de l'année », « et pour le gaz ? » (suite), « combien d'alarmes ce
  mois-ci ? », « alarmes critiques actives », « la GTB fonctionne ? », « température du bureau R+1 ».
- Consommation = index de fin − index de début de chaque compteur (comme un relevé), totalisée par bâtiment
  et par énergie, kWh/MWh et m³ ramenés à une unité commune ; historique commencé en route signalé
  « partiel », index en baisse écarté et dit. Périodes : « du … au … », « entre janvier et mars », « les
  3 derniers mois », « depuis le … », mois, semaine, année ; un mois sans année encore à venir = l'an dernier.
- Bâtiment reconnu par son nom (« le groupe scolaire » suffit), gardé d'une question à l'autre ; les suites
  remontent jusqu'à 4 questions (« et en septembre ? », « et en électricité seulement ? »).
- « Alertes » mentionne aussi les alarmes techniques actives. Lien vers l'écran concerné dans la réponse.
  Outil `supervision` exposé au modèle en mode agent. Mêmes droits que l'écran Supervision.
  `api/AssistantSupervision.php`, `api/connect/Lecture.php`.

## Tableau de bord : nombre d'interventions

- « Interventions actives » mêlait travail en cours et tous les passages planifiés (plans, historique importé
  jusqu'à l'an prochain) : le détail est écrit sous le chiffre (en cours · planifiées · en retard).
- Une seule carte « Interventions » à onglets — Prévues, En retard, En cours — comme « Dernières demandes »
  (au lieu de deux cartes empilées), le total dans chaque onglet, et « Afficher les N autres » sur place. Mini-graphe : nombre sur chaque barre, total de la période, mois en
  français (« Juin », « Juil » au lieu de « Jun », « Jul »).

## Préventif / Contrôle réglementaire : pages

- Hors plan (passages et vue par matériel) et liste des plans : la même pagination que les autres onglets
  (« N résultats — Affichage a-b », ← Page x / y →), et le nombre de lignes (10 à 200, ou tout) choisi dans la
  barre de recherche, retenu sur le poste pour chaque liste.

## Contrôles réglementaires et préventif : lieu / bâtiment

- Nouvelle donnée « Lieu / bâtiment » sur les interventions et les plans (schéma v22), en texte libre avec
  suggestions. Sans elle, l'écran prend le lieu du plan, sinon le bâtiment du bien ou des équipements.
- Hors plan : une colonne Lieu / bâtiment (triable), le filtre « Tous les lieux », la recherche et l'export CSV.
  La vue « Par matériel » sépare un même matériel sur deux sites. Plans : le lieu sur la carte du plan, son filtre,
  le champ dans le formulaire. Formulaire d'intervention : le champ Lieu / bâtiment.
- Import : une colonne Lieu / Bâtiment / Site / Localisation est reconnue. Un planning à plusieurs feuilles met le
  nom de la feuille dans le lieu, et plus en fin de description. Même contrôle, même jour, deux sites : deux
  passages.
- Réimporter un planning déjà importé complète les passages : le lieu est renseigné et le site est retiré de la
  description (« Extincteurs — Bordeaux » devient « Extincteurs », lieu Bordeaux). Rien n'est recréé ; l'aperçu
  l'annonce « Lieu ajouté ».

## Tableau de bord : choix du type d'intervention

- La carte Interventions a une liste « Tous les types / Curatives (classiques) / Préventif / Contrôles
  réglementaires / Divers… » : onglets, compteurs et mini-graphe ne montrent que ce type. Le choix est retenu sur
  le poste. Le lieu s'affiche sous chaque intervention.

## Confirmation d'identité : durée réglable, Microsoft et Google redemandent le mot de passe

- Le gestionnaire règle combien de temps vaut la confirmation : 5 min (défaut), 15, 30 min, 1, 2, 4 ou 8 h
  (*Connexions → Serveur de supervision → Confirmation d'identité*). Le changer demande lui-même de confirmer ;
  la route générique « configuration » refuse les clés `securite_…`.
- Comptes Microsoft / Google : une fenêtre du fournisseur redemande le mot de passe, puis se ferme et l'action
  repart. Plus de « déconnectez-vous et reconnectez-vous » ni de case à cocher. Le compte revenu doit être celui de
  la session ; la session (utilisateur, jetons SharePoint et présence) n'est pas touchée. Mêmes adresses de retour
  que la connexion : rien à déclarer de plus chez Microsoft ou Google.
  - Microsoft : `prompt=login`. L'heure d'authentification est vérifiée si l'application la reçoit (revendication
    facultative `auth_time`).
  - Google : il ne connaît pas `prompt=login`. On demande `max_age=0` et on EXIGE l'heure d'authentification :
    activer « Session age claims » dans Google Auth Platform → Paramètres → Paramètres avancés.
- Serveur de supervision séparé : la confirmation faite sur le principal est plafonnée par la durée que le
  gestionnaire du serveur de supervision a choisie, s'il en a choisi une.

## Modules : icônes des tuiles

- Les tuiles compteur / jauge des modules (tableau de bord, Supervision) prennent la forme des tuiles de Larka,
  avec leur icône. Un ancrage compteur / jauge accepte « icone » (nom Lucide) ; sans elle, le pictogramme de la
  page du module, sinon # (compteur) ou jauge. Le logo image du module n'y est plus posé.
- Clés 1.0.1, Installations 1.0.1, Prestataires 1.1.1, Qualité de l'air 1.0.1 : icônes propres à chaque tuile.
  Réinstaller le paquet .larka (Extensions) pour en profiter ; un module non mis à jour garde l'icône générique.

## Supervision : mode d'emploi en première page

- Les explications qui occupaient le haut de chaque écran (Supervision, Alarmes, onglets des Connexions) sont
  regroupées dans un **mode d'emploi** : fonctionnement général en 5 temps, puis « Cet écran ». Montré une fois à
  la première visite (bouton **Passer**), rouvert par le **?** de la barre du haut.

## Barre de navigation : groupes, onglets masqués, mode icônes

- **Toute section peut devenir un groupe déroulant**, comme Bâtiments et Maintenance : bouton **Groupe** sur le
  bandeau de la section, dans *Interface → Onglets, ordre & sections* (Inventaire, Gestion, Fluides, une section
  créée soi-même, les modules communautaires…). Le groupe se replie d'un clic et s'en souvient ; un écran ouvert
  depuis un lien déplie son groupe.
- **Corrigé — un onglet décoché ne revenait plus** : après rechargement, il disparaissait aussi du panneau, et
  plus rien ne permettait de le réafficher (entrées des modules, barre des demandeurs, et tout onglet masqué —
  le panneau ne listait que ce qui était affiché). Les onglets masqués gardent leur case. Décocher l'entrée d'un
  module la masque enfin (la préférence était enregistrée mais ignorée).
- **Corrigé — barre réduite aux icônes** : elle ne défilait plus (tout ce qui passait sous l'écran était
  injoignable), les groupes perdaient leur icône et ne se repliaient plus, les infobulles étaient coupées au
  bord. Le panneau dit maintenant lequel d'**Étendu** ou **Icônes** est choisi, et grise ces deux boutons quand la
  barre est en haut ou en bas (hauteur fixe) ; le bouton ◀ / ▶ de la barre suit le choix.

## Cases des fiches : historique et ressemblances

- **Une case retirée n'est pas oubliée** (biens, équipements — bouton *Cases des fiches*) : elle passe dans
  **Cases retirées**, avec la date, l'auteur et le nombre de fiches qui gardent une valeur (les valeurs ne sont
  jamais effacées). **Restaurer** la remet, valeurs comprises. Chaque case garde aussi sa date de création et son
  auteur ; ajouts, retraits et restaurations sont inscrits au Journal.
- **Pas de doublon par faute de frappe** : une case nouvelle qui ressemble **à 75 % ou plus** à une case
  existante ou retirée est signalée dès la saisie (« Garanti » ↔ « Garantie », « N° inventaire » ↔
  « N° d'inventaire », « Code analityque » ↔ « Code analytique »), avec *Restaurer celle-ci* ou *C'est la même*.
  Le serveur refait le contrôle à l'enregistrement et ne crée le doublon qu'après confirmation. La mesure ignore
  la casse, les accents, la ponctuation et les petits mots (de, la, du…), et prend la meilleure de deux
  comparaisons : fautes de frappe (distance d'édition) et mots abrégés (paires de lettres communes).
- **Import** de biens / équipements : l'aperçu signale aussi les cases que l'import créerait en double, avec un
  bouton pour remplir la case existante ou restaurer la case retirée à la place.
- Épreuve : `php outils/epreuves/test-champs-perso.php` (vérifie aussi que la mesure est la même à l'écran et au
  serveur).

## Plans et procédures d'urgence : icônes Lucide ou emoji

- **Sélecteur d'icône** commun : un onglet **Icônes** et un onglet **Emoji** (à coller ou taper, ou parmi les
  plus utilisés ; seul un emoji est accepté). L'onglet Icônes propose **toute la bibliothèque Lucide (2 029
  icônes)** : en tête, 150 pictogrammes classés (sécurité et secours, fluides, bâtiment, maintenance,
  personnes) cherchables en français (« extincteur », « escalier »…) ; dessous, toutes les autres, cherchables
  par leurs noms et mots-clés Lucide (en anglais : « fire », « stairs »…) ou à parcourir.
- **Chargée une fois, servie à tous** : la bibliothèque complète (`js/lucide-complet.json`, ≈ 160 Ko compressés)
  n'est téléchargée qu'à l'ouverture du sélecteur, puis gardée en cache. Une icône choisie dedans est
  enregistrée par l'organisation (`icones_choisies`) : le serveur compose son dessin à partir de ce même fichier
  (jamais de ce qu'envoie le navigateur) et le sert à chaque utilisateur au chargement de l'application —
  quelques Ko, demandeurs compris. Les pages ne chargent donc jamais la bibliothèque entière.
- **Plans** : le symbole d'un point peut être une forme (comme avant), une icône Lucide (dessinée en blanc sur une
  pastille de la couleur du point) ou un emoji (sur pastille blanche cerclée de la couleur). Dans la fiche du
  point et dans la barre d'outils : *Symbole → Icône ou emoji…*.
- **Procédures d'urgence** (Configuration) : l'icône d'une catégorie s'ouvre dans le même sélecteur — icône
  Lucide ou emoji ; les catégories enregistrées avant gardent leur icône.
- Le catalogue est dans `outils/lucide/icones.json` (« pictos ») ; `generer-icones.php` en tire
  `css/lucide-pictos.css` (27 Ko), `js/pictos.js` et la bibliothèque complète `js/lucide-complet.json`.

## Modules livrés : six exemples au lieu de huit

Les modules livrés dans `extensions/modules/` servent d'abord à **montrer ce que le format permet**. Ils
se recoupaient : il en reste six, chacun sur une facette différente.

| Module | Ce qu'il montre |
|---|---|
| Moteur d'apparence | inchangé — le vocabulaire des thèmes |
| Gestion des clés | repères sur les plans, données partagées, logo, liste liée |
| **Prestataires et habilitations** (1.1.0) | deux jeux et deux écrans, image, trois langues, réglages, formules qui lisent un réglage, champs conditionnels, choix multiples, couleurs par valeur, totaux, listes liées aux fiches équipement **et bien**, tuiles et jauge |
| Bornes de recharge | déclaration en plusieurs fichiers, type de demande, calendrier, planning imprimable |
| Qualité de l'air intérieur | Larka Connect, mesures en direct, alarmes techniques |
| **Compteurs et santé des installations** (nouveau) | Larka Connect côté exploitation : compteurs (consommation du jour, du mois, face à un objectif réglable), automates et passerelles (état, dernière collecte), pannes de communication et défauts d'équipement reçus des alarmes (filtrées par origine), jauge et tuiles en tête de la supervision, liste sous la courbe d'un compteur et sur la fiche équipement |

- **Prestataires et habilitations** réunit l'annuaire des prestataires et le suivi des habilitations
  (même identifiant, `larka.prestataires`, en 1.1.0). Nouveau : statut du prestataire (référencé, en
  évaluation, écarté — en couleur, avec un motif qui n'apparaît que s'il est écarté), interventions
  assurées, astreinte, contrat en cours, appréciation comparée à la note minimale ; pour les
  habilitations, l'employeur (régie ou prestataire), les indices électriques (seulement pour une
  habilitation électrique), le coût de la formation (totalisé, et en jauge au tableau de bord), le matériel concerné (un bien de l'inventaire : échafaudage, autoportée…).
- **Retirés** : *Contrôles réglementaires* (Larka les gère désormais lui-même : Maintenance →
  Contrôles réglementaires, avec plans et passages), *Habilitations et formations* (fusionné ci-dessus)
  et *Démonstration : images* (l'image est désormais montrée par le module fusionné).
- **Instance existante** : rien n'est désinstallé. Un module retiré de la distribution reste installé
  et continue de fonctionner avec ses données ; on peut le désinstaller depuis l'écran Modules quand on
  veut. *Prestataires* 1.1.0 se met à jour comme un autre module : ses nouvelles permissions (lecture des
  contrats et des biens) demandent une validation, et ses tables gagnent les nouvelles colonnes sans rien
  perdre. Les habilitations saisies dans l'ancien module ne sont pas déplacées automatiquement : export
  CSV depuis l'ancien écran, puis saisie ou import dans le nouveau.
- **Démonstration Larkaville** mise à jour : les 39 habilitations sont passées dans le module fusionné,
  les 25 prestataires ont reçu un statut ; les contrôles et l'herbier de la démo sont retirés (les
  contrôles réglementaires de la démo vivent dans Maintenance).

## Modules déclaratifs : la connectivité des bâtiments

Les modules (fichiers `.larka`, sans code) savent désormais se servir de Larka Connect. Larka reste en
**V3.0.0** ; l'API d'extensions passe en **révision 1.4** (un module qui s'en sert l'annonce, et un Larka plus
ancien le refuse avec « mettez Larka à jour » plutôt qu'avec « type inconnu »).

- **Liens vers le bâtiment connecté** : un champ `lien` peut désigner un **point** suivi (`points_connect` :
  sonde, compteur, consigne), un **équipement raccordé** (`connexions_connect`) ou un **bâtiment**
  (`batiments`). Lecture seule, servie par le cœur avec des projections fixes : un module ne voit jamais
  l'adresse, le registre, les identifiants ou le secret d'un équipement, et les tables de Larka Connect sont
  fermées à toute requête de module.
- **Champ `mesure`** : la dernière valeur d'un point avec son unité, son état (normal, en alarme, ancienne…),
  le nombre d'alarmes actives, le minimum, le maximum et la moyenne sur 24 h, la consommation du jour ou du
  mois d'un compteur ; l'état d'une connexion ou d'un bâtiment. Lue à chaque affichage, jamais stockée,
  utilisable dans un surlignage et dans une formule (« À aérer » quand le CO₂ dépasse le seuil de la salle).
- **Deux emplacements d'ancrage** : des tuiles en tête de *Bâtiments → Supervision* (`connect.supervision`) et
  une liste des fiches du module sous la courbe d'un point (`connect.point`).
- **Alarmes techniques → registre** : un module déclare quel jeu reçoit les alarmes (filtre par gravité,
  origine, ou points qu'il suit) et la correspondance des champs ; **Larka** inscrit chaque alarme à chaque
  passage de la collecte, puis sa fin et sa durée. Une ligne par alarme, jamais deux ; une ligne supprimée ne
  revient pas ; aucun nom de personne transmis. Capacité `connect.alarmes`, présentée à l'administrateur.
- **Module livré** : `larka.qualite-air` (*Qualité de l'air intérieur*) — CO₂ et température de chaque salle
  en direct, seuils de vigilance et d'alerte par salle, registre des dépassements tenu tout seul, tuiles dans la
  supervision. Avec la démonstration Larka Connect (`./start.sh connect-demo`), il se remplit d'emblée.
- **Corrigé au passage** : la tuile `jauge` était validée mais jamais dessinée (cadre vide) ; une `liste_liee`
  affichait des numéros bruts au lieu des libellés de ses liens.
- Référence : `Documentations/FORMAT-DECLARATIF-REFERENCE.md`, § 9 ; `Documentations/LARKA-CONNECT.md`, § 14.
  Épreuve : `outils/epreuves/test-connectivite.php`.

## Supervision (Larka Connect) remaniée

- **Mode simple / avancé** : l'avancé ajoute la tendance des dernières 24 h sur chaque point et les
  consommations des 12 derniers mois en graphique.
- **Filtres** : type d'énergie, nature du point, état (en défaut, en alarme), en plus du bâtiment et de la
  recherche.
- **Consommations du mois** de chaque compteur : index de fin de mois − index de fin du mois précédent, et
  évolution par rapport au mois d'avant.
- **Import dans le module Énergie** (un compteur ou une sélection) : relevé manuel pré-rempli, entièrement
  ajustable (compteur cible, coefficient, index, période, date), contrôlé contre le dernier index du compteur ;
  ou relevé automatique mensuel posé pour la suite.
- **Export** Excel ou CSV : valeurs actuelles, consommations mensuelles, historique des mesures (brut, par heure,
  par jour, par mois) — et **courbes en PDF** : points cochés, période (dont deux dates au choix), style, une ou
  deux courbes par page, page de synthèse. Le PDF est fabriqué dans le navigateur, sans bibliothèque.
- **Courbe d'un point** : période au choix (24 h à 1 an, ou deux dates, trois ans au plus), ‹ › pour reculer
  ou avancer d'une période ; style ligne, aire, barres, points, escalier, ou consommation par heure / jour / mois
  pour un compteur (mémorisé par nature de point) ; trous de collecte laissés vides ; bouton PDF.
- Détails : `Documentations/LARKA-CONNECT.md`, § 3.

## Journaux : de vrais messages, des codes d'erreur et la marche à suivre

- **Codes d'erreur** : 85 codes, dans 16 domaines (système, base de données, fichiers, sécurité, requêtes,
  navigateur, messagerie, tâches de fond, et pour la supervision : collecte, réseau, protocoles, bornes, LoRaWAN,
  wM-Bus, hors réseau, alarmes). Chacun : un titre, une description complète, les causes probables, **comment y
  remédier** dans l'ordre, qui peut agir, où lire la suite. Catalogue fermé : `api/CodesErreur.php` ; référence
  générée : `Documentations/CODES-ERREUR.md`.
- **Posés partout** : chaque entrée du journal (base et fichiers) reçoit son code — imposé par l'appelant quand il
  sait précisément ce qui s'est passé, reconnu d'après le message sinon (les erreurs de la base, de PHP, des
  pilotes Larka Connect ont des formulations stables). Les journaux existants reçoivent la colonne au démarrage.
- **À l'écran** : une erreur renvoyée par le serveur porte son code, et son message affiche « Que faire ? ».
  L'écran **Journal** a une colonne *Code*, les filtres *domaine*, *code* et *incidents expliqués seulement*, une
  tuile *Incidents par code*, et le détail d'une entrée commence par son explication et sa marche à suivre.
- **Journal texte** (`data/logs/activite-*.txt`) : `[CXR-002]` sur la ligne, et en dessous le titre du code et la
  première chose à faire. La ligne d'une requête refusée dit désormais **pourquoi** (elle ne donnait que la route
  et le code HTTP). Une erreur de base de données entre aussi au journal (elle n'allait qu'au log PHP).
- **Supervision** : onglet *Connexions → Journal* — *Événements de supervision* : pannes, connexions partielles,
  changements de cause, rétablissements, trames radio refusées, tests et écritures, chacun avec son code. Une
  connexion en panne n'écrit qu'une ligne, pas une par minute. Codes aussi sur les cartes des connexions, les
  points illisibles et chaque étape en échec de *🧪 Tester*. Les dépôts des PC releveurs et le service permanent
  (OCPP, LoRaWAN) écrivent maintenant dans la base de leur organisation.
- **En ligne de commande** : `php api/outils/codes-erreur.php` (liste, fiche d'un code, code d'un message,
  incidents des derniers jours par code) ; la collecte affiche le code et la première chose à faire.
- Épreuve : `outils/epreuves/test-codes-erreur.php`.

## Import d'interventions (Excel / CSV)

Comme pour les biens et les équipements : bouton **Importer** sur la liste des interventions, et sur les
écrans **Préventif** et **Contrôles réglementaires** (le type y est alors pré-choisi). Pour reprendre
l'historique d'une autre GMAO ou d'un tableau tenu à la main.

- **Le même assistant que pour les biens et les équipements**, même présentation et mêmes outils, en cinq étapes :
  **type** (auto-détection, ou tout en Préventif / Curatif / Contrôles) → **fichier** (feuille, ligne d'en-tête
  détectée, **modèle CSV**) → **correspondances** (indice de confiance par colonne — titre, contenu, mémoire du
  dernier import —, *Re-détecter*, *Proposer avec l'IA* si elle est configurée, valeurs Type / Statut / Priorité
  du fichier → valeurs de Larka, règles de doublons) → **sélection** (chaque ligne contrôlée tout de suite ;
  compteurs, filtres, recherche, cases par type et par statut, période, tout cocher / décocher / inverser,
  pages de 100) → **aperçu & import** (analyse du serveur sans écriture, rapport groupé par motif et
  téléchargeable, import par lots de 500 avec barre de progression).
- **Tableau en lignes ou en colonnes** : une intervention par ligne (titres en haut), ou une par colonne
  (titres dans la colonne de gauche, comme beaucoup de tableaux de contrôles réglementaires). La disposition
  est détectée ; deux boutons « Une fiche par ligne / par colonne » permettent de la changer. Le tableau est
  remis à l'endroit pour l'aperçu, et les erreurs citent la colonne du fichier (« Col. D »).
- **Planning de contrôles** (une ligne par matériel, puis pour chaque année « vérifié par » / « date ») :
  reconnu tout seul (troisième bouton « Un planning ») et **déplié** en une intervention par passage daté,
  avant les étapes habituelles. Les cellules fusionnées valent pour toutes leurs lignes (un prestataire
  pour un lot de matériels, un matériel sur ses lignes de passages), les sous-lignes indentées
  (« - contrôle triennal ») sont rattachées à leur matériel, la périodicité passe en commentaire,
  « maintenance » → Préventive, le reste → Contrôle réglementaire. Dates écrites à la main comprises avec
  l'année de la colonne (« nov » → 1er novembre, signalé en commentaire ; « 16 et 17 janv » ; « avril &
  sept » → deux passages). Les cases sans date (« ok », « pas fait », « reporté », prestataire seul) ne
  deviennent **pas** des interventions : elles sont listées. Toutes les feuilles de même forme (un site par
  feuille) s'importent d'un coup, le nom de la feuille complétant la description ; les messages citent la
  cellule d'origine (« Bordeaux!D7 »). Réimporter le même planning ne crée rien.
- **« Hors plan »** (écrans Préventif et Contrôle réglementaire) : les passages rattachés à aucun plan, dont
  l'historique importé. La liste a désormais sa recherche et ses filtres, indépendants de ceux des plans :
  - **recherche** sur la description, le prestataire, les équipements et le bien rattachés, le n°, le
    commentaire, le statut et la date ; tous les mots, accents ignorés ; « expression exacte » entre
    guillemets ; -mot pour exclure (« extincteur -auto ») ; **une faute de frappe tolérée** sur les mots de
    5 lettres ou plus (« extincteus », « veritsa ») ; mots trouvés surlignés ;
  - **filtres** : année ou période (du / au), statut (dont « faits » / « à faire »), prestataire (sans
    doublon de casse : « BUREAU VERITAS » = « Bureau Veritas ») ; bouton Effacer ;
  - **tri** par colonne, total HT des passages affichés, affichage par 50 ;
  - **vue « Par matériel »** : une ligne par matériel, avec son nombre de passages, le dernier fait (en
    orange au-delà de 13 mois), son prestataire et le prochain prévu (en rouge s'il est en retard) ; un clic
    affiche ses passages ;
  - **export CSV** des passages affichés (ouvert tel quel par Excel, sans formule exécutée).
  La recherche des plans suit les mêmes règles.
- **Les noms usuels sont compris** : « Préventif », « entretien » → Préventive ; « VGP », « vérification
  périodique » → Contrôle réglementaire ; « correctif », « dépannage » → Curative ; « Terminée », « clôturée »
  → Validée ; « à faire » → Planifiée ; « haute », « P1 » → Urgente. Chaque valeur du fichier reste modifiable.
- **Rattachement** : un ou plusieurs équipements par ligne (« EQ-1; EQ-2 »), ou un bien, par leur n° ; le
  contrat par son n°, sa référence ou sa société. Un contrat inconnu devient le prestataire ; un équipement
  introuvable est signalé, l'intervention est créée sans lui.
- **Lectures** : dates JJ/MM/AAAA, Excel ou « 10 janvier 2026 » (une date impossible est signalée et laissée
  vide, jamais devinée) ; montants « 1 234,50 € » ; durées « 2h30 », « 1:45 », « 90 min ».
- **Statut absent** : « réalisée » si une date de réalisation est donnée, sinon « planifiée » (ou le statut
  choisi). Une intervention terminée est validée à sa date de réalisation.
- **Aperçu sans écriture** : à créer, à mettre à jour, doublons, erreurs et avertissements (réalisée sans
  date, date de réalisation future, montant négatif, courriel invalide…), ligne par ligne, avec **une case
  par ligne** et un **rapport CSV**. La description est la seule colonne obligatoire.
- **Pas de doublon** : un fichier réimporté ne crée rien (même type, date, équipement et description) ; un
  n° d'intervention connu met à jour **les seules colonnes du fichier**, ou laisse la fiche, au choix.
- **Listes de choix** : un type ou une priorité nouvelle est proposé, une case par valeur. Préventive et
  Contrôle réglementaire n'entrent jamais dans la liste des types : les passages importés apparaissent sur
  leur écran, dans « Hors plan ».
- Réservé aux administrateurs et gestionnaires ; chaque import est inscrit au Journal ; 10 000 lignes au plus.
  Épreuve : `php outils/epreuves/test-import-interventions.php`.

## Radio : LoRaWAN intégré et compteurs wM-Bus, sans aucune dépendance

- **Serveur LoRaWAN intégré** : les passerelles du bâtiment (packet forwarder Semtech, UDP 1700) envoient
  directement à Larka — ni ChirpStack, ni TTN, ni broker MQTT. Activation OTAA et ABP, vérification du MIC,
  refus du rejeu, doublons de plusieurs passerelles comptés une fois, réponse au join et accusé des trames
  confirmées ; décodeurs Cayenne LPP, Elsys, Milesight, ou octets bruts. Capteurs non déclarés signalés (lien
  *le déclarer*), alarme quand un capteur se tait. Tourne dans le service permanent de Larka Connect (le même
  que l'OCPP) ; s'active dans *Configuration → Serveur → Larka Connect*.
- **Compteurs radio wM-Bus** (eau, chaleur, gaz — OMS) par une **clé USB** : IMST iM871A, Amber AMB8465, CUL.
  Sur le serveur ou sur un **PC releveur**. Détection du matériel par l'identifiant USB (Linux, macOS,
  Windows), réglage du port sans pilote ni programme (stty / PowerShell), déchiffrement AES (mode 5) avec la
  clé de chaque compteur, lecture des données M-Bus (index, énergie, puissance, débit, températures, valeurs
  mémorisées, erreurs). Le collecteur du PC releveur passe en **1.5.0** : commande `materiel`, matériel radio
  envoyé avec les relevés.
- Base : schéma **v21** (table `ConnectLorawan`, colonne `ConnectReleveurs.Materiel`), appliqué au démarrage.
- Détails : `Documentations/LARKA-CONNECT.md`, § 9 bis et § 9 ter. Épreuve : `outils/epreuves/test-radio.php`.

## Icônes Lucide à la place des emojis

- L'interface affiche des **icônes Lucide** (traits fins) au lieu des emojis : menu, onglets, boutons, plans,
  fenêtres, notifications, cartes d'urgence. Les icônes de statut gardent leur couleur (validé vert, erreur
  rouge, attention orange, pastilles de couleur).
- **Trois styles**, au choix de chacun dans **Interface → Icônes** : *Simples* (couleur du texte), *Colorées*
  (par défaut : une couleur par famille — énergie ambre, eau bleu, nature vert, bâtiments indigo, maintenance
  sarcelle, documents violet, alertes rouge…) et *Duotone* (la couleur, et l'intérieur de l'icône teinté).
  Les icônes d'action (flèches, crayon, corbeille) restent neutres, et toute icône posée sur un fond vif
  (bouton, onglet actif, notification) reprend la couleur du texte pour rester lisible.
- Les icônes sont **écrites dans le code** : `lk('nom')` (dans `js/icones.js`) produit
  `<i class="lk lk-nom" aria-hidden="true"></i>`, dessinée en CSS par `css/lucide.css`. Plus de conversion à
  l'affichage ni de libellés à emoji : le catalogue de traduction et les packs de langue ne contiennent plus
  que du texte (5 436 → 4 844 libellés, sans perte de traduction).
- Restent en texte, volontairement : flèches et symboles typographiques (→ ← ↑ ↓ ✓ ✕ ● ▾ …), listes
  déroulantes, infobulles, champs de saisie, courriels et notifications push (sans emoji désormais), sorties
  des outils en ligne de commande. Les rapports du serveur (connexion à la base, migration, configuration)
  gardent leur marqueur ⚠ / ✅ / ❌ en tête de ligne : l'interface le remplace par l'icône correspondante.
- Données saisies avant : une catégorie d'urgence ou un marqueur de module déclaratif enregistré avec un
  emoji connu s'affiche en icône (`lkValeur`). Les nouvelles catégories d'urgence choisissent leur icône dans
  une liste.
- Liste des icônes embarquées et familles de couleur (`_teintes`) : `outils/lucide/icones.json` ; après
  modification : `php outils/lucide/generer-icones.php <node_modules/lucide-static>`.

## App mobile Android : hors ligne, synchronisée avec un serveur s'il y en a un (maquette)

- Nouveau dossier `mobile/` : Larka embarqué dans une app (Capacitor 8). L'interface est celle du
  serveur, inchangée ; le serveur est **réécrit en JavaScript** pour l'app (`mobile/src/serveur/`) sur
  une base SQLite locale, de même schéma que celle du serveur PHP. Plus de moteur PHP WebAssembly
  (GPL) : il ne reste que des composants sous licence permissive.
- Conversion vérifiée action par action contre le serveur PHP (banc `mobile/essais/`, 939 étapes
  identiques). Converti : cœur de la GMAO, documents, inventaire agents, énergie, archives, langues,
  thèmes, modules (livrés et tiers), **Larka Connect** (le téléphone interroge lui-même Modbus TCP,
  BACnet/IP, MQTT, API REST sur le Wi-Fi de l'entreprise, par un greffon natif de sockets), plans
  (imports DXF, GeoJSON, SVG, KML/KMZ, GPX, Shapefile…), annuaire, bilan carbone, mobilité, import de
  biens, réseaux de chaleur, journal, aide, politique RGPD, fonds d'écran — tableau d'avancement dans
  `mobile/LISEZ-MOI.md`.
- **Synchronisation avec un serveur central** (facultative) : l'app travaille hors ligne ; reliée à un
  serveur Larka, elle se synchronise dès que le réseau le permet. Premier lien : comparaison, puis
  « données du serveur », « données de l'appareil » ou choix fiche par fiche. Ensuite : envoi des
  seuls champs modifiés, fusion des modifications qui ne se chevauchent pas, conflits (même champ des
  deux côtés) réglés par l'utilisateur, stock cumulé, numéros réservés pour les fiches créées hors
  ligne, fichiers synchronisés, droits du compte respectés. Côté serveur : `api/Synchro.php`,
  `api/routes/synchro.php` ; `Database.php` ouvre la connexion par `SynchroPdo`, qui ne note les
  écritures (table `SynchroJournal`) qu'une fois un appareil relié ; `resyncPgSequence` ne fait plus
  jamais reculer une séquence PostgreSQL. Appairage par QR code depuis **Mon profil → Mes appareils mobiles**
  (code à usage unique, même code de vérification confirmé sur l'appareil et sur le serveur, acceptation
  avec le mot de passe ; l'appareil est toujours relié au compte connecté). Les gestionnaires voient et
  délient tous les appareils dans Configuration → Comptes. Comptes Microsoft / Google : connexion sur
  l'appareil avec un code de déverrouillage propre à l'appareil (jamais envoyé au serveur). Doublons
  probables (même n° de bien créé sur le PC et sur la tablette entre deux synchronisations) : question
  « même fiche / deux fiches » au lieu d'une création en double.
- **Construction et diffusion de l'app** : `npm run apk` (clé de publication créée une fois puis
  réutilisée, numéro de version croissant, APK signé déposé dans `telechargements/` du serveur) ;
  QR « Installer l'app » dans Mes appareils mobiles ; mise à jour proposée dans l'app quand le serveur
  a une version plus récente. `npm run paquets` : archives `-web.zip` et `-android.zip`. Le serveur récupère
  aussi sur GitHub (dépôt de ses mises à jour, toutes les 6 h) une app plus récente de sa version de
  Larka et la propose aux tablettes. Les mises à jour du serveur ignorent l'archive `-android.zip`. Fusion au premier lien avec le détail champ par
  champ et « garder les deux ». Les comptes du serveur ne sont jamais supprimés par un appareil ; celui
  auquel l'appareil est relié ne peut être ni désactivé ni rétrogradé par lui. Essayé en SQLite et en
  PostgreSQL. Détail et limites : `mobile/LISEZ-MOI.md`.
- Extensions : une borne de date négative (« min_jours »: -30) était lue positive par le serveur
  (`strtotime('+-30 days')`), corrigé dans `api/extensions/declaratif/Moteur.php`.
- Connect, MQTT : la découverte des points plantait (`TypeError`) dès qu'un capteur publiait une
  liste JSON (ou un objet à clés numériques) ; corrigé dans `api/connect/pilotes/Mqtt.php`.
- Connect, API REST : à la découverte, « Défaut » et « Démarrage » écrits avec l'accent n'étaient
  pas reconnus (expression sans drapeau Unicode) ; corrigé dans `api/connect/pilotes/Rest.php`.
- Connect, fichiers CSV : à la découverte, un repère numérique (« 12 ») perdait son libellé et son
  unité ; corrigé dans `api/connect/pilotes/Csv.php`.
- Connect, MQTT : le test d'une connexion sans sujet ni point ne s'abonnait à rien ; il écoute
  désormais tout le broker (`#`), comme prévu (`api/connect/pilotes/Mqtt.php`).
- Validé dans un navigateur en vue téléphone. **Pas encore compilé ni essayé sur téléphone.**
- Côté Larka : `larka_sqlite_reglages()` (journal SQLite en mémoire quand le moteur embarqué pose
  `LARKA_EMBARQUE`) ; `mobile/` n'est ni servi (router.php, nginx) ni copié en production (install.sh).
- Tableau de bord sur petit écran : un tableau large défile dans sa carte au lieu de pousser les
  onglets hors de l'écran.
- Base de l'app dans le stockage OPFS (écriture page par page) : sauvegarde après une écriture
  ramenée de 61 ms à 2 ms en moyenne sur 27 Mo, démarrage plus rapide, mémoire allégée de la copie de la base.
- App : procédures d'urgence complètes (photos et vidéos : dépôt, lecture, suppression), vérifiées
  contre le serveur PHP sur la démonstration Larkaville ; les photos de la démo s'affichent. La
  configuration lue par un demandeur est filtrée des catégories « Gestionnaires uniquement », comme
  côté PHP (elle ne l'était pas encore dans l'app).
- App : interface embarquée allégée à la construction (4,0 → 3,5 Mo lus au démarrage, noms gardés) ;
  temps passé par action dans `LarkaMobile.diagnostic()` ; banc d'essai porté à 696 étapes identiques (939 depuis).

## Performances

Mesuré sur la démonstration complète (~3 600 demandes, ~1 million de mesures Connect), réseau de 20 Mbit/s :
parcours connexion + 8 écrans + rechargement **23,5 s → 9,4 s**, rechargement de page **4,2 Mo → 0,4 Mo**.

- **Fichiers statiques** (serveur intégré : `start.sh`, `Larka.cmd`) : `router.php` les sert désormais avec cache
  et compression. Ressources versionnées (`?v=`) gardées un an, polices 30 jours, le reste revalidé (304) ;
  JS, CSS, JSON et SVG compressés en gzip, version compressée gardée dans `data/cache/statique/`. Derrière
  nginx, rien ne change (déjà configuré dans `deploy/nginx.conf`).
- **Réponses de l'API compressées** (gzip) dès 1,4 Ko : `demandes` passe de 2,8 Mo à 320 Ko, Historique
  de 10 Mo à 1 Mo. nginx et Apache ne les recompressent pas.
- **Les appels d'un même utilisateur ne s'attendent plus.** Le verrou de session PHP faisait passer en file
  les ~30 appels de l'ouverture et ceux de chaque écran. Une lecture authentifiée relâche la session dès
  l'utilisateur vérifié ; si elle modifie la session ensuite, seules les clés changées sont réécrites en fin
  de requête. Les écritures (POST, PUT, DELETE) gardent la session jusqu'au bout.
- **Coût fixe de chaque requête : ~25 ms → ~15 ms.** Le registre multi-tenant ne rejoue plus ses
  `CREATE/ALTER TABLE` à chaque requête (sentinelle `data/.schema/registre-*.flag`, comme la base
  principale — et `ALTER TABLE` y posait un verrou exclusif). Vers un PostgreSQL local, le mode TLS
  `prefer` devient `disable` : rien ne quitte la machine, et la négociation coûtait ~3 ms par connexion.
  `require`, `verify-ca`, `verify-full` et les hôtes distants ne changent pas.
- **Moins d'appels** : les listes déroulantes en un appel au lieu d'un par catégorie (9 à l'ouverture) ;
  les relevés d'énergie de tous les compteurs en un appel (`energie_releves&compteur_ids=…`) au lieu de
  28 à 36, un par un, sur Énergie et Bilan carbone ; le tableau de bord lit les 12 demandes qu'il affiche
  (`demandes&apercu=6`) au lieu de la liste complète.
- À l'ouverture, 1,5 s reste l'animation de connexion, inchangée.

## Mises à jour : la configuration n'est plus réécrite

- **Nouvelle commande `maj`** (`./start.sh maj <zip>`, `Larka.cmd maj <zip>`,
  `php api/outils/mise-a-jour.php --archive <zip>`) : applique l'archive d'une version avec les protections de
  la mise à jour en ligne — `config.json`, `.env`, `config.key`, `data/`, `php/` et les réglages de modules
  existants ne sont jamais écrits, fichiers et bases sont sauvegardés avant, retour arrière automatique en cas
  d'échec. Décompresser une version par-dessus l'ancienne n'est plus nécessaire (ni conseillé).
  **Pour passer de 2.1.0 à 3.0.0** : la mise à jour en ligne de Larka la propose d'elle-même (release
  signée). Sans accès à GitHub : décompresser la 3.0.0 à côté, puis depuis ce dossier
  `./start.sh maj --cible /chemin/de/larka LARKA_GMAO-3.0.0.zip`
  (Windows : `Larka.cmd maj --cible C:\chemin\de\Larka LARKA_GMAO-3.0.0.zip`) ; `--sans-signature`
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

La 3.0.0 empêche ces problèmes de revenir, mais ne répare pas d'elle-même ce que la 2.1.0 a déjà abîmé.
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

## Larka Connect : démonstration avec historique

- **`./start.sh connect-demo`** (`Larka.cmd connect-demo`) : deux bâtiments simulés (Hôtel de Ville, Groupe
  scolaire), 14 points, **60 jours de courbes** posés en quelques secondes (`--jours=N`, jusqu'à 400), règles
  d'alarme et **alarmes passées** reconstituées, **relevés automatiques rattrapés** vers les compteurs du
  module Énergie (index dans la continuité des relevés ; ceux saisis sur les 21 derniers jours sont mis de
  côté et remis par `--retirer`). `--refaire`, `--retirer`, `--activer`.
  **Proposé à la fin de l'installation de la démonstration** « Ville de Larkaville ».
- Nouveau type de connexion **🧪 Simulateur intégré** : énergie, eau, chaleur (degrés-jours), températures,
  CO₂, humidité, marche chauffage, consigne inscriptible ; profils bureaux / école / continu ; incidents
  réalistes ; historique instantané à la première lecture, période manquée recalculée après un arrêt.
- Relevés automatiques : la consommation habituelle tient compte de la saison (relevés des mêmes mois les
  années passées) — la reprise du chauffage à l'automne n'est plus prise pour une anomalie.
- Correctif interne : l'identifiant rendu après certaines créations (compteur d'énergie…) pouvait être celui
  d'une écriture précédente de la même requête.

## Maintenance : préventif et contrôles réglementaires

- La section **Maintenance** du menu se replie d'un clic, comme Bâtiments : **Interventions**, **Contrats**,
  **🗓️ Préventif**, **✅ Contrôle réglementaire**.
- **Préventif** et **Contrôle réglementaire** fonctionnent par **plans** : un plan prévoit plusieurs passages du
  même genre dans l'année (fréquence + premier passage → dates générées, modifiables une à une). Chaque passage
  est une intervention planifiée, qui se prend en charge, se clôture et s'archive comme les autres.
- Le prix, au choix pour chaque plan : **un prix par passage**, **un prix unique réparti** à parts égales entre
  les passages (les centimes restants vont au dernier ; les passages commencés ou faits gardent le leur), ou
  **un prix unique au forfait** porté par le plan (passages à 0 €).
- Un passage commencé, réalisé ou terminé n'est jamais déplacé ni supprimé par le plan ; supprimer un plan
  retire ses passages à venir et garde les autres. **↻** reconduit un plan l'année suivante.
- « Préventive » et « Contrôle réglementaire » quittent la liste des types d'intervention (Configuration →
  listes) et l'écran Interventions. Les interventions existantes de ces types gardent leur type : elles
  apparaissent sous **Hors plan** dans leur écran.
- Tableau de bord : un passage de plan s'affiche quelques jours avant sa date (30 par défaut, réglable par plan),
  au lieu de toute l'année.
- **Historique** : les interventions archivées sont séparées en trois blocs — interventions, préventif,
  contrôles réglementaires.
- **Documents du plan** (contrat, gamme de maintenance, rapport type…) : fichiers et liens SharePoint, dès la
  création du plan ; ils sont lus avec les mêmes droits que les interventions et supprimés avec le plan.
- **SharePoint dès la création d'une fiche** : « 📌 Lier depuis SharePoint » n'apparaissait qu'une fois la fiche
  enregistrée. Il est maintenant proposé tout de suite (interventions, plans, biens, contrats…) : le fichier
  choisi attend avec les autres pièces et il est lié à l'enregistrement. Le navigateur SharePoint s'ouvre
  dans une fenêtre au-dessus de la fiche, qui n'est plus remplacée (une saisie en cours n'est plus perdue).
- Base : table `PlansMaintenance` et colonne `Interventions.PlanId` (schéma 18), posées d'elles-mêmes au
  premier démarrage. Épreuve : `php outils/epreuves/test-maintenance.php`.

## Collecteur 1.4.2 : automates BACnet branchés par câble

- La détection par le PC releveur lit aussi les **automates BACnet/IP** (Sauter EY-modulo 5, par exemple) : leur
  n°, leur nom, leur fabricant et modèle, puis **tous leurs points**, avec le nom et l'unité que l'automate leur
  donne. Lecture seule (ReadProperty). Dans 🔎, Larka propose la connexion BACnet (adresse, n° d'automate) et la
  liste des points ; les compteurs sont cochés d'office. Appliqué, le relevé suivant lit les points choisis.
- Pour un automate branché par câble : déclarer l'appareil hors réseau « On branche un PC dessus, par câble »,
  protocole « 🔎 À détecter », avec son adresse IP ; donner au PC une adresse du même réseau (LISEZ-MOI) ; lancer un
  relevé. `releveur.cmd explorer` affiche aussi l'automate et ses points.
- Pilote BACnet : les noms accentués (« Défaut », « Démarrage ») sont reconnus pour deviner la nature d'un point.

## Collecteur 1.4.1 : retour au réseau sur les PC d'entreprise

- **Cause du retour difficile** : sur un PC d'entreprise, la localisation de Windows est souvent coupée. Windows 11
  tait alors le nom du Wi-Fi à `netsh` ; le collecteur lisait à la place le nom de réseau de Windows, qui est celui
  du domaine (« lesagencesdeleau.eu ») et non un profil Wi-Fi. Il ne pouvait donc pas dire à Windows quel réseau
  reprendre, et comptait sur Windows pour y revenir seul, ce qui était lent ou n'arrivait pas. Les profils Wi-Fi
  posés par l'entreprise (stratégie de groupe) n'étaient pas non plus lus.
- **Maintenant** : le réseau habituel est identifié avant de partir, sans localisation : profil et nom du Wi-Fi que
  Windows donne à PowerShell, sinon la dernière connexion du journal Wi-Fi de Windows (événement 8001), et seulement
  si c'est un profil du PC (ceux de l'entreprise compris). Au retour : ce profil ; sinon le hotspot est quitté et
  Windows choisit ; sinon les profils du PC dans l'ordre de préférence. Un profil refusé n'est plus attendu 25 s.
  On n'est « revenu » que si la carte Wi-Fi a quitté la passerelle du hotspot. Chaque essai est noté dans
  `releveur.log` (et le journal du PC, dans Larka).
- **`releveur.cmd etat`** montre ce que le PC voit du Wi-Fi : carte, nom du Wi-Fi, réseau qui sera repris et
  d'où vient ce nom, profils du PC, détections en attente.
- Ce qui attend d'un passage précédent (relevés, détection) part au début du relevé suivant, avant de quitter le
  réseau : un retour manqué ne bloque plus une détection.
- Larka note la **version du collecteur** de chaque PC (colonne `ConnectReleveurs.Version`, schéma 20) : la liste
  des PC releveurs propose ⬇️ quand une version plus récente existe, et la fenêtre 🔎 prévient si le PC qui
  relève l'appareil a un collecteur trop ancien pour faire la détection.

## Collecteur 1.4 : détection automatique, retour au réseau, relevés vers Énergie

- **Protocole « 🔎 À détecter »** (appareil hors réseau seulement) : on déclare l'appareil avec son Wi-Fi, sans rien
  savoir de lui. Au relevé suivant, le PC releveur regarde ce qu'il expose — ports, pages web et adresses JSON que
  leurs scripts interrogent, registres Modbus — en lecture seule, et l'envoie à Larka. Dans **📶 Hors réseau**, 🔎
  montre ce qui a été trouvé : les réglages proposés (API REST : adresse et chemin ; sinon Modbus TCP) et **tous les
  points** lus, nommés d'après l'appareil (« Comptage general — Énergie active (L1) »), avec nature, unité et valeur
  lue. Les totaux d'un départ (somme de ses trois phases, chemin `a.*.b`) sont cochés d'office ; on corrige un nom,
  on coche ce qu'on veut suivre, **Appliquer**. 🔎 « refaire la détection » marche aussi pour un appareil déjà réglé.
  Onglet Points : « 🔎 Points trouvés par le PC releveur » pour une connexion hors réseau.
- **Retour au réseau habituel** : sur un PC de domaine, Windows donnait au collecteur le nom du domaine
  (« lesagencesdeleau.eu ») comme réseau d'origine, profil Wi-Fi inexistant : le PC restait sur le hotspot. Le
  collecteur n'essaie plus que des profils Wi-Fi connus (`netsh wlan show profiles`) : d'abord celui d'origine s'il
  existe, sinon il quitte le hotspot et laisse Windows rejoindre son réseau préféré, puis essaie les autres profils
  connus. En dernier recours, il demande de se reconnecter à la main (les relevés attendent sur le PC).
- **Relevés vers le module Énergie à chaque relevé du PC** : nouvelle fréquence de relevé automatique « À chaque
  relevé du PC releveur (appareil hors réseau) ». Chaque dépôt du PC écrit le relevé du compteur Énergie à l'heure
  réelle de la lecture (mêmes contrôles qu'à l'habitude : index qui baisse, consommation anormale, validation).
- **Supprimer plusieurs points** : cases à cocher dans la liste des points, « 🗑️ Supprimer la sélection ».
- Base : colonnes `ConnectPasserelles.Reconnaissance`, `ReconDate`, `ReconDemandee` (schéma 19). Le serveur ne garde
  du rapport que les adresses privées et les réponses (≤ 1,5 Mo). Les points créés par « Appliquer » prennent leur
  adresse dans le rapport, jamais dans le navigateur. Il faut retélécharger le collecteur (1.4).

## Collecteur 1.3 (PC releveur)

- Le profil Wi-Fi porte le nom du hotspot lui-même (« PE6-80fd5534 ») : « Larka - PE6-… », affiché par Windows,
  faisait croire à un réseau inexistant. Les anciens profils sont remplacés au premier relevé.
- Hotspot dont l'adresse est saisie par un nom (« powerelec.net ») qui, d'ici, mène à une adresse publique (cache
  DNS de Windows : le nom existe aussi sur Internet) : l'appareil est interrogé à la passerelle de la carte Wi-Fi —
  lui-même, sur son hotspot — et le collecteur affiche l'adresse à saisir dans Larka (1.3.1).
- API REST : chemin « a.*.b » = somme des b de tous les éléments de a (les trois phases d'un départ d'une centrale de
  mesure : énergie et puissance du départ en un point) ; en mode « une requête par point », une adresse commune à
  plusieurs points n'est demandée qu'une fois.
- `explorer` suit toutes les pages de l'appareil et leurs scripts, reconstitue les adresses assemblées, et n'appelle
  jamais une adresse qui ressemble à une action (générer un code, mot de passe, redémarrage…), même en GET.
- Un point non lu affiche toujours sa raison (avant : « 0 / 1 point(s) lu(s) » sans explication hors `--bavard`).
- **`releveur.cmd explorer`** : pour un appareil sans documentation (table Modbus non publiée, API inconnue), relève
  ce qu'il expose — ports ouverts, pages web et adresses JSON que ces pages interrogent, registres Modbus proches de
  230 V / 50 Hz. Lecture seule (GET, Modbus 03/04), adresses privées seulement ; rapport
  `exploration-<appareil>.txt` dans le dossier du collecteur.

## Traductions : un seul fichier

- `lang/fr.json` (catalogue), `lang/en.json` (anglais) et `lang/complements.json` sont réunis dans
  **`lang/traductions.json`** : une ligne par libellé, `"Texte français": {"en": "English text"}`. Une langue absente
  d'une ligne = pas encore traduite ; `"fr"` corrige un libellé sans toucher au code ; la liste `complements`
  remplace l'ancien fichier du même nom. Seuls les packs de langue (`extensions/langues/`) restent à part.
- Les outils suivent : `extraire-libelles.php` ajoute les nouvelles lignes (à traduire) et dit combien il en reste
  par langue ; `construire-pack.php en` tire le pack du fichier (pack anglais identique à celui d'avant) ;
  `verifier-traduction.php lang/traductions.json` contrôle toutes ses langues.
- Une mise à jour ne supprime pas de fichiers : sur une installation existante, les trois anciens fichiers de
  `lang/` restent mais ne sont plus lus ; ils peuvent être effacés.

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

- **Dates affichées « 21-janv »** (format j-mmm d'Excel) : elles arrivaient **sans leur année**, la feuille
  étant lue telle qu'affichée. Une cellule date est maintenant lue d'après sa valeur, quel que soit son
  format (biens, équipements et interventions). Les numéros de ligne cités sont ceux du fichier, lignes
  vides comprises.
- **Tableau en lignes ou en colonnes** : un bien par ligne, ou un par colonne (titres dans la colonne de
  gauche). Disposition détectée, modifiable à l'étape Fichier ; le titre du document au-dessus du tableau est
  ignoré ; le rapport cite alors la colonne du fichier. Le libellé même du champ (« N° du bien ») est
  désormais toujours reconnu.
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

<div align="center">

# 🔧 Larka — Gestion de Maintenance Assistée par Ordinateur (GMAO)

**Plateforme web _multi-tenant_ de gestion de maintenance.**
Backend PHP sans framework, base PostgreSQL par client, frontend JavaScript sans build, installable en PWA.
**Extensible par des modules qui ne contiennent aucun code.**

[![Version](https://img.shields.io/badge/version-2.0.0%20(V2)-0a1628.svg)](Documentations/)
[![Licence](https://img.shields.io/badge/licence-propri%C3%A9taire-red.svg)](LICENSE)
[![Ko-fi](https://img.shields.io/badge/Ko--fi-soutenir%20le%20projet-FF5E5B?logo=ko-fi&logoColor=white)](https://ko-fi.com/chipsoreo)
[![PHP](https://img.shields.io/badge/PHP-8.3-777BB4.svg?logo=php&logoColor=white)](https://www.php.net/)
[![PostgreSQL](https://img.shields.io/badge/PostgreSQL-%E2%89%A514-336791.svg?logo=postgresql&logoColor=white)](https://www.postgresql.org/)
[![Frontend](https://img.shields.io/badge/frontend-Vanilla%20JS%20(no%20build)-F7DF1E.svg?logo=javascript&logoColor=black)](#stack-technique)
[![PWA](https://img.shields.io/badge/PWA-installable-5A0FC8.svg)](#)
[![Multi-tenant](https://img.shields.io/badge/architecture-multi--tenant-0a1628.svg)](#architecture)
[![Modules](https://img.shields.io/badge/modules-d%C3%A9claratifs%20(sans%20code)-16a34a.svg)](#modules-déclaratifs-étendre-larka-sans-code)

</div>

---

## 📋 À propos

**Larka** est une application web de gestion de maintenance pensée pour héberger plusieurs organisations
(_tenants_) sur une même installation, **chaque client disposant de sa propre base de données** (isolation
physique, pas seulement logique). Elle couvre le parc de biens et d'équipements, les demandes et
interventions, les contrats, le stock, le suivi énergétique et carbone, l'archivage et les plans de bâtiments.

Le projet fait des choix volontairement **sobres et robustes** : aucun framework PHP ni Composer requis,
aucune étape de build côté frontend (JavaScript natif), quatre composants tiers embarqués (lecture de
codes-barres / QR, cartographie). Surface d'attaque réduite, montées de version simplifiées.

> 📐 L'architecture complète (composants, réseau, sécurité, exploitation) est décrite dans le
> **Dossier d'Architecture Technique** ([`Documentations/DAT-Larka.docx`](Documentations/DAT-Larka.docx)).

### 🏛️ Pour qui ?

Larka est conçue **en priorité pour les établissements et collectivités du secteur public**.
Plusieurs modules répondent à des obligations ou à des usages qui leur sont propres :

| Module | Usage secteur public |
|---|---|
| **Chorus Pro** (via PISTE) | Facturation publique — obligatoire pour les fournisseurs de la sphère publique |
| **Légifrance** (via PISTE) | Consultation du droit applicable directement depuis l'application |
| **Archives** | Dossiers, boîtes et **bordereaux de versement** — pratique de l'archivage public |
| **Énergie & carbone** | Suivi des consommations et bilan d'émissions, avec millésimes annuels figés |
| **Procédures d'urgence** | Fiches de conduite à tenir, avec médias, sous contrôle d'accès |

Rien n'empêche techniquement une structure privée d'utiliser Larka : les modules réglementaires
sont désactivables par tenant, et le reste (parc, interventions, contrats, stock, plans) est
générique. Mais le vocabulaire, les écrans et les circuits de validation ont été pensés pour le
public, et l'ergonomie s'en ressent.

> 🔜 **Une version adaptée au secteur privé est envisagée.** Elle remplacerait les connecteurs
> réglementaires par des équivalents pertinents pour l'entreprise et réviserait la terminologie.
> Aucune échéance n'est annoncée à ce jour.


### Version

Cette livraison est la **version 2.0.0 — V.Beta 2.0.0**. Elle ajoute à la V1 une couche
d'extension complète : des **modules déclaratifs**, qui étendent l'application
sans y exécuter la moindre ligne de code tiers.

| Élément | Valeur | Où |
|---|---|---|
| Version applicative | `2.0.0` | `version.json` — source unique, lue par `api/Version.php` |
| Libellé affiché | `V.Beta 2.0.0` | `LARKA_VERSION_LABEL`, bas de la page de connexion |
| Service Worker | `2.0.0` | `SW_VERSION` (`sw.js`) |
| Manifeste PWA | `2.0.0` | `manifest.webmanifest` |
| Format des modules | `declaratif/1` · API d'extensions `1` | `ExtCapacites::VERSION_API` (révision interne `1.3`) |
| Documentation | manuels v2.0 | `Documentations/` |

### 📝 Notes de version — V.Beta 2.0.0

**Ajouts**

- **Assistant IA plus rapide et moins coûteux** : conçu pour tourner sur CPU sans GPU (API native d'Ollama,
  invite stable mise en cache, réponse affichée au fil de l'eau, bouton ■ pour l'arrêter, durée affichée) ;
  mise en cache de l'invite chez Anthropic ; **Google Gemini** ajouté aux fournisseurs (Anthropic, OpenAI,
  Mistral, local).
- **Assistant plus malin** : recherche tolérante aux fautes et aux approximations (« où est l'extincteur 2 du
  centre technique »), comptages (« combien de biens ? »), prise en compte des **modules installés ou non**,
  liens cliquables vers les fiches et les plans.
- **Assistant pour les demandeurs** : il rédige un titre court, range le bâtiment, l'étage et le bureau dans les
  bons champs, puis ouvre le formulaire de demande pré-rempli à valider.
- **Mises à jour automatiques** : détection d'une nouvelle version (GitHub Releases ou manifeste), installation
  **validée à la main**, empreinte SHA-256 et signature Ed25519, sauvegarde avant installation et retour
  arrière ; **aucune donnée supprimée** (`data/`, `config.json`, `.env` jamais touchés). Outils :
  `api/outils/mise-a-jour.php`, `outils/publier-version.php`.
- **Version affichée sur la page de connexion** (« V.Beta 2.0.0 »), lue dans `version.json`.
- **SharePoint devient une option** : demandé au premier lancement (`./start.sh`, `deploy/install.sh
  --sharepoint on|off`), modifiable par `./start.sh sharepoint on|off`, la console ou *Configuration → Serveur*.
  Sans SharePoint, les documents sont stockés dans Larka et la connexion Microsoft ne demande plus l'accès aux
  fichiers.
- **Certbot devient une option** : certificat existant (`--cert … --key …`), `--no-certbot`, ou TLS en amont
  (`--behind-proxy`).
- **Nouveau logo** : `icon.png` (remplace `apple-touch-icon.png`, l'ancienne adresse reste servie),
  `favicon.svg`, `icon-512.png`.
- **Modules en plusieurs fichiers JSON** (`$ref` vers `parties/*.json`) documentés dans la référence du format.
- **Documentation v2** : manuels Gestionnaire, SuperAdmin (journal d'audit, mises à jour), Visionneur &
  Utilisateur (repères « Concerne : »), DAT et référence du format déclaratif — police et charte communes.

**Sécurité — six défauts d'autorisation des modules, corrigés**

Un module déclare ses rôles page par page et ses actions vue par vue ; ce vocabulaire n'était appliqué qu'à
*supprimer* et *exporter*. Chaque cas a été reproduit sur un module livré avant d'être corrigé :

| Ce qui était possible | Corrigé par |
|---|---|
| **Écriture sans contrôle** : `dp_enregistrer` ne vérifiait ni rôle, ni action, ni champ — un agent pouvait remplir un champ que son écran n'affiche pas (« Décision : validé ») | La saisie est bornée aux champs que l'écran expose ; les autres sont écartés et la tentative est tracée |
| **Modification d'autrui** sur une page qui ne déclare pas *modifier* | `creer` et `modifier` exigent une page, ou un ancrage formulaire, ouverts au rôle |
| **Lecture d'un jeu réservé** en changeant son nom dans la requête | `dp_lister`, `dp_exporter`, `dp_ancrage`, `dp_cibles`, `dp_suggestions` exigent une page ouverte à l'appelant |
| **Réglages du module** modifiables sans être gestionnaire (`dp_definir_reglages`) | Liste close d'actions d'administration, réservées au gestionnaire |
| **Fichiers d'un module** listés et téléchargés par n'importe quel compte | Les quatre routes passent par la garde de l'exécution d'une action |
| **Cloisonnement des tables** : `larka.recharge` réputé propriétaire des tables de `larka.recharge-x` | L'installation refuse un identifiant qui empiète sur un module déjà installé |

L'épreuve `outils/epreuves/test-autorisations.php` (31 contrôles) garde ces portes fermées et vire au rouge si une
correction est retirée. *Limite connue, assumée :* un rôle autorisé à *supprimer* sur une page peut supprimer toutes
les fiches du jeu, pas seulement les siennes — le format n'a pas de notion de propriétaire d'une ligne.

**Sécurité — durcissement**

- `session.cookie_samesite` borné à `Strict` ou `Lax` : pour les téléversements (dispensés du contrôle de
  Content-Type), SameSite est la seule barrière CSRF. Toute autre valeur est refusée, journalisée, repliée sur `Strict`.
- Liste des dispenses de Content-Type complétée (`ext_fichier_importer`, `urgences_media_upload`), qui échouaient
  en 403 sans explication.
- `proc_open` réintègre `disable_functions` dans le pool PHP-FPM (le processus isolé qui le justifiait n'existe plus).
- nginx refuse `/extensions/` en entier ; mêmes règles dans `router.php`, qui refuse aussi `outils/` et `Documentations/`.
- XXE : `LIBXML_NONET` posé explicitement sur les importateurs de plans (SVG, KML, GPX).
- `deploy/larka-extensions-durcir.sh` retiré (compte système pour un processus disparu).
- `./start.sh prod` est bloquant : les épreuves rapides tournent avant tout déploiement et le refusent (code 60) si
  une protection ne répond plus ; `--sans-epreuves` pour l'urgence.

**Corrections**

*Base de données*
- Les tables de plans n'étaient créées qu'à la première visite de l'écran Plans (import, restauration, inventaire
  échouaient sur une base neuve) : créées par la migration (`SCHEMA_VERSION` 8 → 9).
- Un réglage de liste vide s'auto-effaçait (« champ obligatoire » décoché au rechargement) : calcul remonté au
  serveur, application en un seul `UPDATE`.

*Sauvegardes — le chapitre le plus important*
- **Une sauvegarde SQL n'était pas une sauvegarde** : le dump excluait `Utilisateurs` et `PushSubscriptions` — après
  une perte de disque, plus personne pour se connecter.
- Six familles de fichiers n'étaient pas sauvegardées (plans, fonds d'écran, modules, thèmes et langues, médias
  d'urgence, configuration des modules) : un `-fichiers.tar.gz` accompagne désormais le `.sql.gz`.
- Cette archive ne se faisait presque jamais (`exec`/`shell_exec` désactivés par le pool livré → « tar absent ») :
  elle passe par PharData, intégré à PHP ; `tar` ne sert plus que de secours.
- Un commentaire en tête d'un bloc faisait sauter l'ordre SQL suivant à la restauration.

*Plans — fuite entre clients*
- `data/plans` était partagé entre tous les clients (« voir les images de fond » montrait les plans des autres, et
  deux clients pouvaient s'écraser à la même seconde) : un dossier par client, un fragment aléatoire dans le nom, repli
  sur l'ancien emplacement pour les plans existants.
- Le sélecteur de fichier annonçait « SVG », refusé par le serveur.

*Rôles et permissions*
- Le Super Administrateur recevait un 403 sur le journal d'audit, pourtant déplacé dans son écran.
- La cloche était muette pour les demandeurs : nouveau type « Réponses à mes demandes », types filtrés par rôle.
- Le bouton « Interface » était masqué aux demandeurs.

*Panneau Interface*
- Bloc « onglets et sections » refusé aux demandeurs ; préférences d'un rôle qui écrasaient celles d'un autre sur un
  navigateur partagé (cloisonnées par rôle) ; libellés de la mauvaise barre ; entrées en double ; les onglets
  **Biens, Équipements et Stock** pouvaient disparaître du menu.

*Assistant*
- Ne savait pas répondre à « combien de biens ? » ; comprenait « poignée » comme « poignet » et mettait le lieu dans
  le titre ; demandait d'« être plus précis » au lieu de chercher une correspondance approchée.

*Cache navigateur et version*
- Les `?v=` d'index.html étaient figés au 4 août : des corrections livrées n'atteignaient jamais les postes.
  `outils/bump-version.php` s'en charge et refuse un numéro de version écrit en dur dans le code.
- La version affichée était codée en dur dans `api/config.php` ; elle vient désormais de `version.json`.

*Exploitation*
- `./start.sh install` sortait en erreur sans message sur toute machine sans `config.json` (la première installation).
- Le port était lu dans la mauvaise section de `config.json` (tentative d'écoute sur 5432).
- Le garde-fou « vous êtes en production » se déclenchait à tort dès que `config.json` portait `env: prod`.
- Faux négatifs du contrôle de santé derrière un tunnel.
- `start`, `stop` et `restart` ne pilotent plus la production (`prod-start` / `prod-stop` / `prod-restart`).
- Avertissement PHP 8.4 (paramètre implicitement nullable) dans la sauvegarde Super Admin.

*Divers*
- Kilométrage annuel affiché dans les déclarations de mobilité · champs obligatoires mélangés entre sous-onglets de
  Configuration · modal « Nouvelle demande » à moitié vide · titres de section collés au bloc précédent · mode sombre
  et thèmes superposés · une dizaine d'accès `window.X` vers des liaisons `const`/`let` qui échouaient en silence ·
  icône de notification alignée sur `icon.png`.

**Ménage**

- Une quinzaine de fonctions sans appelant retirées (détectées par la nouvelle épreuve des liaisons globales).
- `scripts/` devient `outils/`, et n'est plus déployé (comme `Documentations/`) : la carte de ce contre quoi Larka se
  défend n'a pas à voyager avec le produit.
- Règles de déploiement périmées retirées (`/sdk/`, `/securite/`, page d'épreuve supprimée).
- Renvois documentaires réparés (référence Word en retard d'une version, manuel d'auteur pointant vers un fichier absent).
- Le lien du pied de page de connexion pointait vers `Chipsoreo/GMAO_LARKA`.

> ⚠️ **Statut : bêta.** Cette version est fonctionnelle mais n'a pas encore été
> éprouvée en exploitation réelle sur la durée. Avant tout déploiement en
> production : validez sur un environnement de test, et mettez en place vos
> sauvegardes (voir la section dédiée) **avant** d'y saisir des données.

> Le paramètre `?v=` d'`index.html` n'est **pas** un numéro de version : c'est un
> horodatage de cache navigateur, régénéré automatiquement à chaque déploiement
> par `start.sh`. Ne le modifiez pas à la main.

---

## ✨ Fonctionnalités

**Métier**

- 🏢 **Multi-tenant** — une base PostgreSQL par client, routage automatique selon le domaine (web ou e-mail).
- 🧰 **Parc** — biens, équipements, parc matériel, déclarations d'inventaire.
- 🛠️ **Maintenance** — interventions préventives et correctives, devis, lignes de stock liées, demandes d'intervention.
- 📄 **Contrats** de maintenance et listes de référence.
- 📦 **Stock** avec seuils et mouvements.
- ⚡ **Énergie & carbone** — compteurs, relevés, réseaux de chaleur, bilan carbone (facteurs d'émission).
- 🗺️ **Plans** de bâtiments (étages, éléments, liens cartographiques).
- 🗃️ **Archives** physiques (dossiers, boîtes, bordereaux) et **documents** (PDF/images, déduplication SHA-256).
- 📊 **Tableau de bord** et statistiques avancées.
- 🇫🇷 **Connecteurs réglementaires** — Légifrance et Chorus Pro via la plateforme PISTE ; SharePoint/OneDrive.
- 🚨 **Procédures d'urgence** — fiches de conduite à tenir, avec photos et vidéos servies sous contrôle d'accès.
- 🧭 **Annuaire cartographié** — localiser une personne ou un service sur un plan d'étage (consentement individuel).
- 🤖 **Assistant conversationnel** — questions en langage naturel sur les données du tenant et de ses modules installés.
  IA **locale** (Ollama/LM Studio, aucune donnée ne sort du serveur, optimisée pour CPU) ou fournisseur distant
  (Anthropic, OpenAI, Mistral, Google Gemini).
- 🔔 **Notifications push** (Web Push natif, RFC 8030/8291/8292).
- 🧩 **Modules déclaratifs** — ajouter un registre métier, un formulaire de demande, un thème ou une traduction en déposant un fichier `.larka`. Aucun code n'est exécuté ; voir la section dédiée.

**Technique**

- 🔐 **Sécurité par défaut** — HTTPS/HSTS, CSP, chiffrement AES-256-GCM des secrets, RBAC, journal de sécurité.
- 🔑 **Authentification fédérée** — OAuth 2.0 / OpenID Connect (Microsoft Entra ID, Google) + comptes locaux (bcrypt).
- 📱 **PWA** installable (poste et mobile), notifications push iOS 16.4+ en mode écran d'accueil.
- ⚡ **Chargement paresseux** du frontend (lazy-loading) avec préchargement intelligent selon le rôle.
- 🎨 **Écran de connexion personnalisable** par le super-administrateur (couleurs, fond animé, CSS filtré — voir plus bas).
- 🧪 **11 suites d'épreuves automatisées** (456 contrôles), exécutées avant chaque mise en production, qui refusent le déploiement si une protection tombe.

---

## 🧩 Modules déclaratifs — étendre Larka sans code

Un module Larka est un **fichier JSON** qui *décrit* des données et des écrans.
Larka le lit et dessine lui-même l'interface. Il n'y a ni PHP, ni JavaScript,
ni CSS d'auteur : rien à isoler, rien à auditer ligne à ligne, rien qui puisse
s'exécuter.

```json
{
  "identifiant": "mairie.cles",
  "format": "declaratif/1",
  "nom": "Registre des clés",
  "donnees": {
    "cles": { "libelle": "Clé", "champs": {
      "numero":     { "type": "texte", "libelle": "N°", "obligatoire": true },
      "detenteur":  { "type": "texte", "libelle": "Détenteur" },
      "equipement": { "type": "lien",  "libelle": "Équipement", "vers": "equipements" }
    } }
  },
  "pages": [ {
    "cle": "cles", "titre": "Clés", "roles": ["Gestionnaire", "Admin"],
    "vue": { "type": "liste", "source": "cles",
             "colonnes": ["numero", "detenteur", "equipement"],
             "actions":  ["creer", "modifier", "supprimer", "exporter"] }
  } ]
}
```

C'est le choix de fond : **un catalogue fermé plutôt qu'un bac à sable**. Un mot
absent du vocabulaire est refusé à l'installation — jamais ignoré en silence,
jamais cherché ailleurs. Analyser du code tiers pour décider s'il est inoffensif
est un problème qu'on perd ; ne jamais en accepter est un problème qu'on n'a pas.

### Ce que le vocabulaire sait exprimer

| | |
|---|---|
| **15 types de champ** | texte, texte long, entier, décimal, pourcentage, note, durée, date, horodatage, heure, booléen, choix, choix multiple, couleur, lien |
| **16 formats de saisie** | immatriculation, SIRET, IBAN, code postal, téléphone… vérifiés à l'écriture |
| **91 fonctions de formule** | champs calculés, qui n'atteignent jamais PHP |
| **33 opérateurs de condition** | une grammaire unique, partagée par l'affichage conditionnel, le surlignage et les filtres |
| **5 ancrages** | compteur et jauge au tableau de bord, liste liée sur une fiche, marqueurs sur un plan, formulaire de demande |
| **Mise en page** | grille de 1 à 24 colonnes, sections en carte ou panneau, images, responsive par palier |
| **Habillage** | thèmes et packs de langue, choisis par chaque utilisateur |

### Les permissions sont **déduites**, jamais réclamées

Un auteur ne demande rien. Larka lit sa déclaration, en déduit ce qu'elle exige,
et présente la liste à l'administrateur — en français, avec un niveau de risque.
Personne ne peut obtenir un accès dont sa déclaration n'a pas l'usage.

À l'exécution, ce que le module déclare fait **frontière** : le jeu de données
qu'un rôle peut lire, les actions qu'il peut faire, et jusqu'aux champs qu'il
peut remplir sont ceux de l'écran qui lui est ouvert — vérifié côté serveur, à
chaque appel.

### Ce qui est livré

`extensions/` contient **7 modules**, 2 thèmes et 2 packs de langue prêts à
installer : registre de clés, contrôles réglementaires, habilitations, annuaire
de prestataires, réservation de bornes de recharge, démonstration d'images et
moteur d'habillage. Ils servent autant d'exemples que d'outils.

### Écrire le sien

```bash
php api/extensions/outils/construire-paquet.php --nouveau acme.mon-module
php outils/verifier-module.php extensions/modules/acme.mon-module --detail
php api/extensions/outils/construire-paquet.php extensions/modules/acme.mon-module
```

Le vérificateur valide la déclaration contre le **schéma réel du serveur** et
annonce ce qu'elle produira. Aucune base n'est nécessaire, rien n'est exécuté.

> 📜 **Votre module vous appartient.** Écrire un `.larka` n'est pas modifier
> Larka : c'est produire un fichier de données que Larka lit. Vous n'avez donc
> aucune autorisation à demander, vous en êtes seul titulaire, et vous le
> diffusez sous la licence de votre choix — y compris commercialement
> ([`LICENSE`](LICENSE), §5 bis).

- **Référence du format** : [`Documentations/FORMAT-DECLARATIF-REFERENCE.md`](Documentations/FORMAT-DECLARATIF-REFERENCE.md)
  — spécification écrite à la main, complétée par les catalogues extraits du
  code ; une épreuve vérifie que les deux ne divergent pas.
- **Exemples** : [`Documentations/exemples-declaratifs/`](Documentations/exemples-declaratifs/)
  — 9 modules complets, du registre le plus simple à l'interface entièrement
  mise en page, revalidés à chaque passage de la suite d'épreuves.

### Activation

La couche est **inerte par défaut**. Dans `config.json` :

```json
"extensions": { "actif": true }
```

Tant qu'elle vaut `false`, aucun dossier n'est ouvert et aucun module n'est
chargé, quels que soient les réglages des clients. Une fois active, les modules
s'installent un par un depuis l'écran **Modules**, capacité par capacité.

---

## 🧱 Stack technique

| Couche | Technologie | Version |
|---|---|---|
| Frontend | JavaScript (ES2017+), HTML5, CSS3 — SPA + PWA, **sans build** | — |
| Frontend (lib.) | ZXing-browser (codes-barres / QR) — _composant tiers_ | embarquée |
| Frontend (lib.) | Leaflet (cartographie des plans) — _composant tiers_ | embarquée |
| Backend | PHP (sans framework, sans Composer) | 8.3 |
| Serveur applicatif | PHP-FPM | 8.3 |
| Serveur web | Nginx (reverse proxy, TLS, fichiers statiques) | ≥ 1.18 |
| Base de données | PostgreSQL (une base par tenant) | ≥ 14 |
| BDD (repli admin) | SQLite / MariaDB | — |
| TLS | Let's Encrypt (Certbot) — optionnel | — |
| OS | Ubuntu / Debian | 22.04+ / 12+ |

---

## 🏗️ Architecture

```
Navigateur (PWA, HTTPS)
        │
        ▼
   Nginx  ──(socket Unix, FastCGI)──►  PHP-FPM (API REST, JSON)
        │                                   │
   fichiers statiques                 TenantResolver
                                            │  résout le tenant (domaine / session / e-mail)
                                            ▼
                                  PostgreSQL — 1 base par tenant
                                  (+ base d'administration centrale)

Appels sortants optionnels (HTTPS) : Microsoft Graph · Google · PISTE (Légifrance/Chorus) · Web Push · SMTP
```

- **Client-serveur en API REST** : le serveur ne produit que des données JSON (`{ success, data }`).
- **Multi-tenant à bases cloisonnées** : isolation **physique** des données par organisation.
- **Sans dépendance lourde** : routage, accès données (PDO), auth, chiffrement et Web Push en PHP natif.

---

## 🚀 Démarrage rapide (développement)

> Prérequis : **Debian 12+ / Ubuntu 22.04+**, **PHP 8.1+** (8.3 recommandé) avec `pgsql mbstring xml curl gd zip intl bcmath`, **PostgreSQL 14+**.
> `./start.sh install` installe automatiquement PHP, PostgreSQL et les extensions sur Debian/Ubuntu.

Depuis la **racine** du projet (le dossier qui contient `start.sh`) :

```bash
./start.sh install     # installe PHP + PostgreSQL, crée les bases, génère .env + config.json
./start.sh start       # démarre l'application  →  http://127.0.0.1:8000
```

Laissez le terminal ouvert (**Ctrl+C** pour arrêter), puis ouvrez **http://127.0.0.1:8000**.

**Super Admin** : `http://127.0.0.1:8000/?superadmin` — login `superadmin`,
mot de passe `SuperAdmin2025!` (**à changer immédiatement**).

> 💡 **L'adresse ne se saisit qu'une fois.** `--host` / `--port` sont mémorisés dans `config.json` et
> deviennent le nouveau défaut : les démarrages suivants (y compris après un reboot) les reprennent
> tout seuls. Rien à retaper.

Sans argument, `./start.sh` ouvre une **console interactive** (état des services, démarrer/arrêter,
journaux, changer l'adresse, activer le démarrage au boot).

Raccourcis `make` :

```bash
make install        # = ./start.sh install
make up             # démarre en arrière-plan (démon)
make stop           # arrête le serveur
make doctor         # diagnostic (PHP, extensions, PostgreSQL, config)
make fix            # diagnostic + réparation automatique
make autostart-on   # démarrage automatique à chaque boot (systemd)
make up PORT=9000 HOST=0.0.0.0
```

---

## 🏭 Installation en production

```bash
sudo ./start.sh prod --domain gmao.monentreprise.fr
```

Met en place PostgreSQL, **PHP-FPM**, **Nginx** en HTTPS, les tâches **cron** (rotation des logs, nettoyage
des sessions), et génère un `.env` (secrets, `chmod 600`) + un `config.json` (sans secret) adaptés au domaine.

Le certificat est obtenu par défaut avec **Certbot (Let's Encrypt)**, renouvelé automatiquement. Certbot est une
**option** :

```bash
sudo ./start.sh prod --domain gmao.interne.fr --cert /etc/ssl/gmao/fullchain.pem --key /etc/ssl/gmao/privkey.pem
sudo ./start.sh prod --domain gmao.interne.fr --no-certbot      # certificat placé ensuite à la main
sudo ./start.sh prod --domain gmao.exemple.fr --behind-proxy    # TLS terminé en amont (proxy, tunnel)
```

Pour un déploiement autonome plus léger (serveur PHP intégré géré par systemd, sans Nginx, p. ex. derrière
un reverse-proxy existant) :

```bash
sudo ./start.sh autostart on      # installe un service systemd (larka.service)
```

Larka **démarre alors tout seul à chaque boot**, redémarre en cas de crash, et attend PostgreSQL (lui aussi
activé au boot). `start` / `stop` / `status` / `logs` pilotent ensuite ce service. Pour désactiver :
`sudo ./start.sh autostart off`. Un gabarit modifiable à la main reste fourni :
[`deploy/gmao.service`](deploy/gmao.service).
L'installation **manuelle** pas-à-pas est décrite en tête de [`deploy/install.sh`](deploy/install.sh).

> ⚠️ N'activez pas `autostart` sur une machine déjà en production Nginx + PHP-FPM : les deux serviraient la
> même application en parallèle. Le script le détecte et demande confirmation.

> ⚠️ **Erreur classique** : `cd deploy && ./install.sh` échoue (mauvais dossier, root requis).
> Restez à la **racine** et utilisez `./start.sh prod`.

---

## ⚙️ Configuration & secrets

| Donnée | Emplacement |
|---|---|
| **Secrets** : mots de passe BDD, clé secrète, secrets OAuth/SMTP, clé privée VAPID, clé d'API assistant | **`.env`** (`chmod 600`, jamais committé) |
| **Connexions OAuth** : `client_id`, `tenant_id`, `redirect_uri` | **`.env`** (éditables dans l'UI) |
| Hôtes, ports, base/utilisateur BDD, CSP, CORS, activation des modules, logs… | **`config.json`** |

Modèles fournis : [`.env.example`](.env.example) et [`config.example.json`](config.example.json).
Les fichiers générés (`.env`, `config.json`) sont exclus par le [`.gitignore`](.gitignore).

**Précédence des secrets** (la plus haute gagne) : variable d'environnement système → `.env` → résidu dans `config.json`.

> ⚠️ Si vous récupérez ce projet depuis une archive ayant pu contenir des secrets, **régénérez-les**
> (clé secrète, clé privée VAPID via l'interface, mots de passe BDD).

---

## 🛡️ Sécurité

- **Transport** : HTTPS imposé (HSTS 1 an), redirection systématique du port 80.
- **Secrets au repos** : `config.json` chiffré en **AES-256-GCM** ; clé dans `config.key` (`chmod 600`).
- **Mots de passe** : hachage **bcrypt** ; jamais stockés en clair.
- **Contrôle d'accès (RBAC)** : rôles Admin · Gestionnaire · Technicien · Visionneur · Demandeur, appliqués **côté serveur**.
- **Durcissement web** : CSP, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy ; liste blanche des points d'entrée PHP (anti-webshell) ; fonctions système désactivées au niveau PHP-FPM.
- **Anti-CSRF** (`Content-Type: application/json` sur les mutations), **anti-bruteforce** (limitation par IP et par compte), **requêtes paramétrées** (anti-injection SQL), **échappement HTML** systématique côté client.
- **Journal de sécurité** structuré au format JSONL (`data/security/audit.log`).
- **Modules sans code** : un module ne peut pas exécuter de PHP, de JavaScript ni de CSS — le format ne sait pas les exprimer, et l'extraction d'un paquet n'écrit que ce que le format définit. Un fichier de code présent dans une archive n'est pas refusé : il n'est simplement **jamais écrit sur le disque**.
- **Médiation des accès aux données** : un module ne transmet jamais de SQL. Il décrit une intention (`SELECT`, table, colonnes, conditions) ; le cœur vérifie chaque élément contre le schéma introspecté, puis **construit lui-même** la requête. Tables système inaccessibles, colonnes de secret refusées, écriture interdite hors de ses propres tables.
- **Surveillance comportementale** : volume, fréquence, nombre de tables distinctes et refus accumulés sur une fenêtre glissante. Dix refus suspendent le module.
- **Épreuves bloquantes avant déploiement** : `./start.sh prod` refuse de livrer (code 60) si une protection ne répond plus.

---

## 💾 Sauvegardes — ce qu'il ne faut pas oublier

Une sauvegarde de la **base seule ne suffit pas** : documents, plans et médias des procédures d'urgence
vivent sur le **disque**, pas en base. Restaurer un dump SQL seul donne une application dont les fiches
pointent vers des fichiers absents.

| Périmètre | Contenu | Criticité |
|---|---|---|
| Bases PostgreSQL | `gmao_admin` + une base par tenant | **Critique** |
| Fichiers | `data/plans`, `data/fonds`, `data/extensions`, `data/thematiques`, `data/urgences`, `extensions/config` | **Critique** |
| Secrets | `.env`, `config.key` | **Critique** — à part, chiffré, en coffre |
| Configuration | `config.json` | Importante |
| Certificats TLS | `/etc/letsencrypt/` | Reconstituable (réémission) |

> ⚠️ **Une sauvegarde complète, c'est DEUX fichiers.** Depuis la 2.0.0, la
> sauvegarde produit un `.sql.gz` **et** un `-fichiers.tar.gz` au même
> horodatage. Restaurer l'un sans l'autre rend une base complète et des plans
> sans image. L'archive de fichiers est construite avec `PharData`, intégré à
> PHP : elle ne dépend d'aucune commande système, et fonctionne donc sur un pool
> PHP-FPM durci.

> ⚠️ **Une sauvegarde n'est pas un export.** Le `.sql.gz` de sauvegarde contient
> les comptes utilisateurs ; le restaurer sur un autre client écrase les siens.
> L'export entre clients, lui, les exclut — c'est une opération différente.

> Le mécanisme applicatif (`data/backups`, 10 générations) est **désactivé à la livraison**. Fréquence,
> externalisation et **test de restauration** relèvent de l'exploitant.

---

## 🎨 Super administration & personnalisation de la connexion

- **Super Admin** (`…/?superadmin`) — gestion des tenants (une base PostgreSQL par tenant), des utilisateurs et monitoring. Connexion locale ou Microsoft.
- **Onglet « 🎨 Personnalisation »** (barre Super Admin) — l'écran de connexion se personnalise via un sélecteur d'**apparence** :
  - **Classique** : couleurs de thème (roue chromatique HSV), **fond animé** ou dégradé, logo (texte, ou image PNG/JPEG/GIF/WEBP/SVG ≈ 500 Ko).
  - **CSS personnalisé** (prioritaire) : votre feuille de style est **filtrée côté serveur** (rejet de `url()`, `@import`, `@font-face`, `position:fixed/sticky`, `pointer-events`… ; 8 Ko max) puis appliquée à une **couche décorative non interactive**, derrière la carte de connexion.

  > 🔒 L'ancien mode « HTML brut » a été **retiré** (risque de XSS sur une page accessible avant authentification).
  > La mention de droit d'auteur et de licence affichée sur l'écran de connexion est **codée en dur** et non personnalisable.

- **OAuth Microsoft / Google** : `client_id`, `tenant_id`, `client_secret`, `redirect_uri` se configurent dans **Configuration → Serveur** (écrit dans `.env`) ; seul l'interrupteur `actif` reste dans `config.json`. Les utilisateurs Microsoft se connectent avec le rôle **Demandeur** par défaut.

---

## 🤖 Assistant IA (module optionnel)

L'assistant répond en langage naturel sur les données du tenant. Il n'accède **jamais** directement à la base :
il passe par un catalogue d'outils exécutés côté serveur (`search`, `compter`, `get_fiche`, `qui_est`,
`localiser`, `alertes`, `module`, `search_sharepoint`…), **avec les droits de l'utilisateur connecté**.

| Mode | Fournisseurs | Sortie de données | Clé d'API |
|---|---|---|---|
| **Local** _(recommandé)_ | Ollama, LM Studio, llama.cpp | **Aucune** — tout reste sur le serveur | non requise |
| Distant | Anthropic, OpenAI, Mistral, Google Gemini, Copilot | Les extraits envoyés au modèle quittent le SI | `GMAO_ASSISTANT_API_KEY` |

Modèles par défaut (champ « Modèle » vide) : les plus rapides et économiques de chaque fournisseur —
`claude-haiku-4-5`, `gpt-4o-mini`, `mistral-small-latest`, `gemini-3.1-flash-lite`, `ministral-3:3b` (Ollama).

**Rapidité et coût.** Le prompt système et la liste d'outils sont **identiques d'une question à l'autre** : Ollama
les garde en cache KV (une question ne coûte que ses propres tokens), et les API distantes les facturent au tarif
« cache » (Anthropic : `cache_control` posé automatiquement ; OpenAI / Gemini / Mistral : cache implicite). Les
résultats d'outils sont compactés, et sur un modèle local la **pré-recherche** évite en général un aller-retour
complet. Le panneau **préchauffe** le modèle local à son ouverture, affiche la réponse en direct, et le bouton ■
arrête la génération (le CPU est libéré aussitôt). Sous chaque réponse, une ligne discrète indique durée et tok/s.

**Modules complémentaires.** Les modules installés, actifs et accordés au rôle de l'utilisateur sont interrogeables
par l'assistant (outil `module`, recherche globale comprise). Un module non installé ou désactivé est signalé comme
tel au gestionnaire, jamais inventé. Le demandeur se voit proposer les écrans de modules qui lui sont ouverts.

Inférence locale — réglages fournis (drop-in systemd) :

```bash
sudo mkdir -p /etc/systemd/system/ollama.service.d
sudo cp deploy/ollama-larka.conf /etc/systemd/system/ollama.service.d/larka.conf
sudo systemctl daemon-reload && sudo systemctl restart ollama
ollama pull ministral-3:3b                                   # ou qwen2.5:3b, llama3.2:3b
ollama create larka-assistant -f deploy/Modelfile.larka      # facultatif (fige les réglages)
```

Larka utilise l'API native d'Ollama (`/api/chat`) : la fenêtre de contexte (`num_ctx`), le plafond de génération et
`keep_alive` sont réellement appliqués — une URL `…/v1/chat/completions` déjà configurée est convertie
automatiquement. Sur CPU, visez un modèle **3B quantifié Q4** ; les modèles « à réflexion » (qwen3, deepseek-r1)
sont nettement plus lents — réglage *Réflexion des modèles locaux* → *Désactivée* dans la configuration.

> ⚠️ **CPU.** Une génération locale consomme durablement les cœurs disponibles : sur la même machine que
> l'application, elle dégrade les temps de réponse. Le réglage livré limite volontairement l'inférence à
> **une requête à la fois**. Prévoyez des ressources dédiées, ou déportez le moteur.
>
> ⚠️ **RGPD.** En mode **distant**, les données transmises au modèle sortent du SI : inscrivez le fournisseur
> au registre des sous-traitants et vérifiez les garanties de transfert. Le mode **local** supprime le sujet.

Le port du moteur local (`11434`) **ne doit jamais être exposé** au réseau.

---

## ⬆️ Mises à jour

La version installée est dans `version.json` (affichée sur la page de connexion : « V.Beta 2.0.0 »).

**Détection automatique, installation validée.** Larka vérifie la source des versions (GitHub Releases de
`mises_a_jour.depot`, ou un manifeste `latest.json`) au plus toutes les 12 h. Quand une version plus récente existe,
un bandeau la propose à l'administrateur du serveur (Super Admin ; Admin/Gestionnaire en mono-tenant), qui lit les
nouveautés et coche une confirmation avant d'installer. Aussi : *Configuration → Version et mises à jour*.

**Les données ne sont jamais touchées** : `data/` (base SQLite, plans, médias, modules installés, sauvegardes),
`config.json`, `.env` et les réglages de modules existants restent tels quels ; aucun fichier n'est supprimé.
Avant d'écrire, les fichiers remplacés (et la base SQLite) sont sauvegardés dans `data/maj/sauvegardes/` ; une erreur en
cours d'installation restaure automatiquement la version précédente, et l'historique permet de revenir en arrière.

**Intégrité** : HTTPS uniquement, empreinte SHA-256 obligatoire, et signature Ed25519 exigée dès qu'une
`cle_publique` est configurée (recommandé).

```bash
# Éditeur — une fois : paire de clés (la publique va dans mises_a_jour.cle_publique)
php outils/publier-version.php --generer-cles
# Éditeur — à chaque version : version.json, ?v=, zip, .sha256, .sig, latest.json
php outils/publier-version.php --version 2.0.3 --canal Beta --notes notes.md
gh release create v2.0.3 dist/LARKA_GMAO-2.0.3.zip dist/LARKA_GMAO-2.0.3.zip.sha256 dist/LARKA_GMAO-2.0.3.zip.sig --notes-file notes.md --prerelease

# Serveur — sans interface (ou si les fichiers ne sont pas inscriptibles par PHP)
sudo -u www-data php /var/www/gmao/api/outils/mise-a-jour.php --verifier
sudo -u www-data php /var/www/gmao/api/outils/mise-a-jour.php --installer
```

---

## 🧪 Diagnostic & dépannage

`start.sh` signale les échecs avec un code **`GMAO-Exx`** (également le code de sortie : `echo $?`).
`./start.sh doctor` teste PHP, les extensions, PostgreSQL, la configuration et le démarrage au boot, et annote
chaque problème. `./start.sh doctor --fix` répare en plus ce qui peut l'être automatiquement.

`./start.sh epreuves` (ou `make epreuves`) vérifie les protections du moteur de
modules. Elles tournent aussi **automatiquement avant chaque `./start.sh prod`**,
qui refuse de déployer si l'une d'elles tombe — une vérification qu'il faut
penser à lancer n'est jamais lancée.

| Code | Cause probable | Correction |
|---|---|---|
| `GMAO-E10` | PHP introuvable | `./start.sh install` |
| `GMAO-E11` | Extensions PHP manquantes | `apt install php-pgsql php-mbstring php-curl php-gd php-xml` |
| `GMAO-E12` | PHP trop ancien (8.1 minimum) | Mettre PHP à niveau |
| `GMAO-E20` | Client PostgreSQL absent | `./start.sh install` |
| `GMAO-E21` | PostgreSQL non démarré | `./start.sh doctor --fix` |
| `GMAO-E22` | Échec de création du rôle / des bases | Vérifier les droits du compte admin PostgreSQL |
| `GMAO-E23` | Authentification `pg_hba.conf` refusée | `./start.sh setup --fix-pg-auth` |
| `GMAO-E24` | Rôle `gmao` sans `CREATEDB` (création de tenant) | `sudo -u postgres psql -c "ALTER ROLE gmao CREATEDB;"` |
| `GMAO-E30` | Configuration absente (`.env` / `config.json`) | `./start.sh install` |
| `GMAO-E31` | Configuration PHP-FPM invalide | Corriger la conf — **rien n'a été redémarré** |
| `GMAO-E32` | Configuration Nginx invalide | Corriger la conf — **rien n'a été redémarré** |
| `GMAO-E33` | Échec de démarrage de PHP-FPM / du service | Lire le journal affiché |
| `GMAO-E34` | Échec de démarrage / rechargement de Nginx | Lire le journal affiché |
| `GMAO-E40` | Port déjà utilisé | `./start.sh start --port 9001` |
| `GMAO-E41` | Le serveur n'a pas démarré | `data/logs/server.log` ou `journalctl` |
| `GMAO-E50` | Droits root ou systemd requis | Passer en root, ou installer `sudo` |
| `GMAO-E60/61` | Paquets système non installables | Vérifier l'accès aux dépôts |

Repartir de zéro (**destructif**) : `./start.sh reset && ./start.sh start`.

---

## 📁 Structure du projet

```
larka/
├── index.html              Coquille SPA / PWA (porte le ?v= des assets)
├── manifest.webmanifest    Métadonnées PWA
├── sw.js                   Service Worker (push) — v2.0.0
├── router.php              Routeur du serveur PHP intégré (dev / autonome)
├── start.sh  Makefile      Lanceur unifié + raccourcis
├── .env.example            Modèle de secrets      config.example.json  Modèle de config
├── api/                    Backend PHP (API REST)
│   ├── index.php           Point d'entrée / dispatch / sécurité
│   ├── env.php EnvFile.php Lecture / écriture du .env
│   ├── Database.php        Accès aux données (PDO)
│   ├── TenantResolver.php  Résolution multi-tenant
│   ├── ConfigCrypto.php    Chiffrement AES-256-GCM
│   ├── SecurityLog.php     Journal de sécurité (JSONL)
│   ├── WebPush.php         Web Push natif (VAPID)
│   ├── UrgencesAcl.php     ACL des procédures d'urgence
│   ├── Journal.php         Journal d'activité métier
│   ├── AssistantTools.php  Outils de l'assistant IA
│   ├── routes/             23 modules (auth, inventaire, maintenance, urgences, assistant, …)
│   └── extensions/         Couche des modules déclaratifs
│       ├── Registre.php    Installation, activation, rôles accordés, exécution
│       ├── Manifeste.php   Chargement et validation d'une déclaration
│       ├── Paquet.php      Format .larka : vérification, extraction, installation
│       ├── declaratif/     Schema · Moteur · Expression (91 fn) · Condition (33 op)
│       ├── securite/       Médiation des requêtes, politique de données, surveillance
│       └── outils/         construire-paquet.php (fabrique un .larka)
├── js/                     Frontend : cœur + 33 pages + filtres (lazy-loadés)
│   ├── declaratif.js       Rendu des écrans décrits par un module
│   ├── extensions.js       Chargement des modules côté client
│   └── vendor/             ZXing (QR) · Leaflet (cartes)
├── css/                    10 feuilles de style + polices WOFF2 embarquées
├── oauth/                  Callbacks OAuth (microsoft.php, google.php)
├── extensions/             Modules livrés : modules/ · themes/ · langues/ · config/
├── outils/                 Développement uniquement — NON déployé
│   ├── verifier-module.php Valide une déclaration contre le schéma du serveur
│   ├── epreuves/           11 suites, 456 contrôles (voir epreuves/README.md)
│   ├── langues/            Extraction et vérification des traductions
│   └── maintenance/        Outils ponctuels
├── deploy/                 install.sh · nginx.conf · php-fpm-gmao.conf · gmao.service
│                           ollama-larka.conf · Modelfile.larka   (assistant IA local)
├── Documentations/         DAT + manuels (.docx) + référence du format déclaratif
│                           + 9 modules d'exemple vérifiés
├── licenses/               Textes des licences tierces (Apache-2.0, OFL-1.1, MIT, BSD)
└── data/                   Runtime hors web : backups, logs, sessions, security,
                            urgences (médias), extensions (modules installés), run (PID)
```

---

## 🐙 Publier sur GitHub

Dépôt : <https://github.com/Chipsoreo/LARKA_GMAO>. `config.json`, `.env` et les données d'exécution sont
exclus par `.gitignore` : ils ne partent jamais sur GitHub.

**Premier envoi** (dépôt GitHub vide) :

```bash
cd LARKA_GMAO-2.0.0
git init
git add .
git commit -m "Larka V.Beta 2.0.0"
git branch -M main
git remote add origin https://github.com/Chipsoreo/LARKA_GMAO.git
git push -u origin main
```

**Dépôt qui contient déjà une version** : on remplace son contenu, l'historique est conservé.

```bash
git clone https://github.com/Chipsoreo/LARKA_GMAO.git
cd LARKA_GMAO
rsync -a --delete --exclude .git ../LARKA_GMAO-2.0.0/ ./     # contenu de l'archive, sans toucher à .git
git add -A
git commit -m "Larka V.Beta 2.0.0"
git push
```

**Release** (c'est elle que les serveurs détectent pour la mise à jour automatique). `notes.md` : reprenez les
notes de version ci-dessus.

```bash
# paquet + empreinte (+ signature si une clé a été générée), puis release en « pré-version » (bêta)
# (--reconstruire : 2.0.0 est déjà la version inscrite dans version.json ; inutile pour les suivantes)
php outils/publier-version.php --version 2.0.0 --canal Beta --notes notes.md --reconstruire
git commit -am "Publication 2.0.0" && git push      # l'outil rafraîchit le ?v= d'index.html
gh release create v2.0.0 dist/LARKA_GMAO-2.0.0.zip dist/LARKA_GMAO-2.0.0.zip.sha256 \
    --title "Larka V.Beta 2.0.0" --notes-file notes.md --prerelease
# (ajouter dist/LARKA_GMAO-2.0.0.zip.sig si le paquet est signé ; gh crée l'étiquette v2.0.0)
```

Sans l'outil `gh` : GitHub → *Releases* → *Draft a new release*, choisir l'étiquette `v2.0.0`, joindre le `.zip`
et le `.sha256` (et le `.sig`), cocher *Set as a pre-release*.

---

## 🤝 Contribuer

Les contributions sont les bienvenues. Quelques points utiles :

- Lancez `./start.sh doctor` avant d'ouvrir une issue d'installation.
- Avant un commit : `php -l` sur les fichiers PHP modifiés et `node --check` sur les fichiers JS.
- Avant une livraison : `bash outils/epreuves/toutes.sh` (456 contrôles, quelques
  secondes ; quatre suites demandent une base, SQLite suffit). `./start.sh prod`
  lance les plus rapides tout seul et **refuse de déployer** si l'une échoue.
- En ajoutant une primitive au format déclaratif, nourrissez
  `outils/epreuves/test-fonctionnalites.php` : sans cela la déclaration passe,
  l'écran s'affiche, et la fonctionnalité manque — sans rien pour le signaler.
- Conservez les **en-têtes de licence SPDX** ; pour les (ré)appliquer : `bash outils/maintenance/add-license-headers.sh`.
- **Modifier le logiciel pour vos propres besoins est autorisé** et ne demande
  aucune démarche préalable ([`LICENSE`](LICENSE), §5.1 et §5.7). C'est en
  **diffuser** une version modifiée qui requiert l'accord écrit de l'auteur (§5.4).
- **Écrire un module ne demande rien du tout.** Un `.larka` n'est pas une
  modification du logiciel : c'est votre œuvre, vous en êtes propriétaire, et
  vous le publiez sous la licence de votre choix (§5 bis).

---

## 📜 Licence

Larka est un **logiciel propriétaire**. © 2025-2026 Mickaël Larcin (« Chipsoreo ») — **Tous droits réservés**.
Son utilisation est régie par la **Licence d'utilisation Larka** (identifiant SPDX `LicenseRef-Larka-Proprietary`) ;
voir le fichier [`LICENSE`](LICENSE).

**Ce que vous pouvez faire, sans rien demander :** installer et utiliser Larka
gratuitement, pour vos besoins propres, que vous soyez une administration, une
collectivité, une entreprise ou une association (§3.2) · l'adapter à votre
organisation (§5.1) · écrire et publier vos propres modules, qui vous
appartiennent (§5 bis) · l'héberger pour les membres de votre groupement ou de
votre groupe, sans marge (§4.7).

**Ce qui demande l'accord écrit de l'auteur :** redistribuer le logiciel,
diffuser une version modifiée, le republier ailleurs, ou le proposer comme
service à des tiers. Et dans tous les cas, **Larka reste gratuit** : il ne peut
être vendu par personne, sous aucune forme.

> ⚠️ Ce n'est **pas** un logiciel libre / open source.
>
> Le code source est **lisible** — pour permettre l'audit, l'auto-hébergement et l'adaptation
> aux besoins internes de votre organisation. Cette lisibilité ne vaut **ni licence libre, ni
> mise dans le domaine public, ni autorisation de republication**.
>
> Sauf **autorisation écrite et préalable** de l'auteur, sont interdits : la reproduction
> au-delà de l'installation, la distribution, la republication du code sur un dépôt public,
> la fourniture du logiciel comme service à des tiers, et son usage comme données
> d'entraînement d'une IA. Le logiciel est fourni « en l'état », **sans aucune garantie**.

Les composants tiers embarqués — **@zxing/browser** (MIT), **@zxing/library** (Apache-2.0),
**Leaflet** (BSD-2-Clause) et les polices **DM Sans / DM Mono** (OFL-1.1) — conservent leur
propre licence d'origine et ne sont pas couverts par le droit d'auteur du présent logiciel.
Attributions détaillées dans [`THIRD-PARTY-NOTICES.md`](THIRD-PARTY-NOTICES.md).

---

## 👤 Auteur

**Mickaël Larcin** (alias _Chipsoreo_) — © 2025-2026.
La liste des auteurs est tenue dans le fichier [`AUTHORS`](AUTHORS).

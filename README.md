<div align="center">

# 🔧 Larka — Gestion de Maintenance Assistée par Ordinateur (GMAO)

**Plateforme web _multi-tenant_ de gestion de maintenance.**
Backend PHP sans framework, base PostgreSQL par client, frontend JavaScript sans build, installable en PWA.
**Extensible par des modules qui ne contiennent aucun code.**

[![Version](https://img.shields.io/badge/version-2.2.0%20(stable)-0a1628.svg)](Documentations/)
[![Licence](https://img.shields.io/badge/licence-propri%C3%A9taire-red.svg)](LICENSE)
[![Ko-fi](https://img.shields.io/badge/Ko--fi-soutenir%20le%20projet-FF5E5B?logo=ko-fi&logoColor=white)](https://ko-fi.com/chipsoreo)
[![PHP](https://img.shields.io/badge/PHP-8.3-777BB4.svg?logo=php&logoColor=white)](https://www.php.net/)
[![PostgreSQL](https://img.shields.io/badge/PostgreSQL-%E2%89%A514-336791.svg?logo=postgresql&logoColor=white)](https://www.postgresql.org/)
[![Frontend](https://img.shields.io/badge/frontend-Vanilla%20JS%20(no%20build)-F7DF1E.svg?logo=javascript&logoColor=black)](#-stack-technique)
[![PWA](https://img.shields.io/badge/PWA-installable-5A0FC8.svg)](#)
[![Multi-tenant](https://img.shields.io/badge/architecture-multi--tenant-0a1628.svg)](#️-architecture)
[![Modules](https://img.shields.io/badge/modules-d%C3%A9claratifs%20(sans%20code)-16a34a.svg)](#-modules-déclaratifs--étendre-larka-sans-code)

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

**Version 2.2.0 (V2.2.0) — première version stable.** Larka s'installe sur un serveur Linux comme sur un poste
Windows (d'un double-clic), avec une démonstration complète ; sauvegardes automatiques, politique de protection
des données personnalisable par organisation, prérequis vérifiés avant toute installation ou mise à jour, aide
intégrée propre à chaque rôle, interface en français et en anglais. Les nouveautés de chaque version sont
décrites dans les [Releases](https://github.com/Chipsoreo/LARKA_GMAO/releases) du dépôt — l'écran de mise à jour
de Larka les affiche aussi.

| Élément | Valeur | Où |
|---|---|---|
| Version applicative | `2.2.0` | `version.json` — source unique, lue par `api/Version.php` |
| Libellé affiché | `V2.2.0` (une préversion : `V.Beta x.y.z`) | `LARKA_VERSION_LABEL`, bas de la page de connexion |
| Service Worker | `2.2.0` | `SW_VERSION` (`sw.js`) |
| Manifeste PWA | `2.2.0` | `manifest.webmanifest` |
| Format des modules | `declaratif/1` · API d'extensions `1` | `ExtCapacites::VERSION_API` (révision interne `1.3`) |
| Documentation | manuels v2.2 · DAT 2.2 | `Documentations/` ; l'écran **Aide** de l'application en reprend l'essentiel pour chaque rôle |

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
- 🛡️ **Protection des données** — politique RGPD intégrée, adaptée à l'installation et personnalisable par organisation, avec la mention « Personnalisé par l'entreprise ».
- ❓ **Aide intégrée** — fiches pratiques propres à chaque rôle, avec recherche ; pour l'exploitant, procédures propres au système détecté.
- 🌐 **Français et anglais** — interface et écran de connexion, au choix de chaque utilisateur ; autres langues par pack.
- 🧩 **Modules déclaratifs** — ajouter un registre métier, un formulaire de demande, un thème ou une traduction en déposant un fichier `.larka`. Aucun code n'est exécuté ; voir la section dédiée.

**Technique**

- 🔐 **Sécurité par défaut** — HTTPS/HSTS, CSP, chiffrement AES-256-GCM des secrets, RBAC, journal de sécurité.
- 🔑 **Authentification fédérée** — OAuth 2.0 / OpenID Connect (Microsoft Entra ID, Google) + comptes locaux (bcrypt).
- 📱 **PWA** installable (poste et mobile), notifications push iOS 16.4+ en mode écran d'accueil.
- ⚡ **Chargement paresseux** du frontend (lazy-loading) avec préchargement intelligent selon le rôle.
- 🎨 **Écran de connexion personnalisable** par le super-administrateur (couleurs, fond animé, CSS filtré — voir plus bas).
- 💾 **Sauvegardes automatiques** — base et fichiers, déclenchées par Larka lui-même (production, Linux autonome, Windows), état visible à l'écran.
- ✅ **Prérequis vérifiés** avant l'installation, au démarrage et avant chaque mise à jour : une version incompatible ne s'installe pas.
- 🪟 **Linux ou Windows** — serveur de production Nginx + PHP-FPM, ou poste autonome d'un double-clic (`Larka.cmd`).
- ⬆️ **Mises à jour depuis l'interface** — nouvelle version détectée, installation validée à la main, archive signée (Ed25519), sauvegarde avant d'écrire et retour arrière.
- 🎬 **Démonstration « Ville de Larkaville »** — cinq ans d'activité fictive dans tous les modules, livrée dans `demo/` et proposée à l'installation.
- 🧪 **24 suites d'épreuves automatisées** (≈ 1 900 contrôles) ; les plus rapides tournent avant chaque mise en production et refusent le déploiement si une protection tombe.

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

### Traduire l'interface

Les libellés français sont recensés dans **`lang/fr.json`** (le catalogue, extrait du code) ; **`lang/en.json`**
porte la traduction anglaise : mêmes clés, même ordre, donc exactement le même nombre de lignes — un écart se
voit d'un coup d'œil. On traduit les valeurs, jamais les clés.

```bash
php outils/langues/extraire-libelles.php      # met lang/fr.json à jour depuis le code (+ lang/complements.json)
php outils/langues/construire-pack.php en     # aligne lang/en.json (nouvelles clés en attente), construit le pack
php outils/langues/verifier-traduction.php extensions/langues/larka.langue-en/extension.json
```

`construire-pack.php` signale les libellés restés en français (code de sortie 1) ; `--version=X.Y.Z` fixe la
version du pack. `lang/complements.json` recense les libellés que le code assemble ou reçoit du serveur, et que
l'extraction ne peut pas voir.

Le pack anglais (`extensions/langues/larka.langue-en`, 4.2.0) est **livré et proposé à tous** : chaque utilisateur
le choisit (*Interface → Langue*), il s'applique aussi à l'écran de connexion, et il ne demande pas d'activer la
couche de modules — ce ne sont que des libellés. Une version plus récente remplace d'elle-même un pack déjà installé.

### Activation

La couche est **inerte par défaut** (seuls les packs de langue livrés s'appliquent sans elle). Dans `config.json` :

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
| Backend | PHP (sans framework, sans Composer) | 8.1 min. · 8.3 conseillé · éprouvé jusqu'à 8.5 |
| Serveur applicatif | PHP-FPM | 8.3 |
| Serveur web | Nginx (reverse proxy, TLS, fichiers statiques) | ≥ 1.18 |
| Base de données | PostgreSQL (une base par tenant) | ≥ 14 |
| BDD (alternatives) | SQLite (poste autonome, Windows, démonstration) / MariaDB | ≥ 3.24 / ≥ 10.3 |
| TLS | Let's Encrypt (Certbot) — optionnel | — |
| OS | Ubuntu / Debian — ou Windows 10/11 (poste autonome) | 22.04+ / 12+ |

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

> **Sous Windows** : décompressez, double-cliquez sur `Larka.cmd` — voir [🪟 Windows](#-windows--installer-et-lancer-larka).

> Prérequis : **Debian 12+ / Ubuntu 22.04+**, **PHP 8.1+** (8.3 recommandé) avec `pgsql mbstring xml curl gd zip intl bcmath`, **PostgreSQL 14+**.
> `./start.sh install` installe automatiquement PHP, PostgreSQL et les extensions sur Debian/Ubuntu.
> Les prérequis sont **vérifiés à chaque démarrage** (`api/Prerequis.php`, exigences dans `version.json`) : un
> prérequis manquant arrête le lancement avec la marche à suivre (`GMAO-E13`) ; `./start.sh doctor` donne le détail.

Depuis la **racine** du projet (le dossier qui contient `start.sh`) :

```bash
./start.sh install     # installe PHP + PostgreSQL, crée les bases, génère .env + config.json
./start.sh start       # démarre l'application  →  http://127.0.0.1:8000
```

Laissez le terminal ouvert (**Ctrl+C** pour arrêter), puis ouvrez **http://127.0.0.1:8000**.

**Démonstration** (« Ville de Larkaville ») : livrée dans `demo/`, elle est proposée à la fin de
`./start.sh install`, et au premier `./start.sh start` (une seule fois). À tout moment : `./start.sh demo` — la base actuelle est d'abord mise
de côté dans `data/backups/`. Connexion : `claire.moreau` / `Larka2026!` — l'écran de connexion de la démonstration
les affiche, avec les autres profils (demandeur, visionneur…).

**Mise à jour du système** (`apt upgrade`, nouvelle version d'Ubuntu…) : si la bibliothèque C change, les index
des bases PostgreSQL sont à reconstruire. Au démarrage suivant, Larka le détecte et le propose ; sinon :
`./start.sh doctor --fix` (aucune donnée modifiée). `./start.sh install` n'installe que ce qui manque et
demande avant de mettre à jour quoi que ce soit.

**Super Admin** : `http://127.0.0.1:8000/?superadmin` — à la première visite, faite depuis le serveur, l'écran
demande de choisir l'identifiant et le mot de passe (aucun mot de passe par défaut n'est accepté).

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

## 🪟 Windows — installer et lancer Larka

> **Windows 10 (1809 ou plus récent) ou Windows 11, 64 bits.** Aucun droit d'administrateur n'est nécessaire —
> sauf si le *runtime Visual C++* de Microsoft, dont PHP a besoin, manque : Windows demande alors l'autorisation.

1. **Décompressez** l'archive de Larka dans un dossier **local**, par exemple `C:\Larka`.
   Évitez `Program Files` (Windows y protège les fichiers), un dossier synchronisé par OneDrive (le Bureau et les
   Documents le sont souvent) et les partages réseau : la base SQLite y risque verrous et corruption.
   *Astuce : avant de décompresser, clic droit sur l'archive → Propriétés → cochez « Débloquer » : Windows ne
   posera plus de question à chaque lancement.*
2. **Double-cliquez sur `Larka.cmd`.** Au premier lancement :
   - il télécharge **PHP 8.3** pour Windows depuis php.net (≈ 35 Mo, empreinte SHA-256 vérifiée) dans le dossier
     `php\` de Larka — rien n'est installé ailleurs ;
   - il fait accepter la licence, crée `config.json` (base SQLite `data\gmao.db`) et `.env` (clé secrète) ;
   - il démarre Larka, ouvre le navigateur sur **http://127.0.0.1:8000** et affiche dans la fenêtre le **mot de
     passe initial du compte `admin`** (Larka demande d'en choisir un autre à la première connexion).
3. **Gardez la fenêtre ouverte** (réduisez-la). Pour arrêter Larka : fermez-la, ou **Ctrl+C**.

Les lancements suivants démarrent directement.

| Commande | Effet |
|---|---|
| `Larka.cmd` | Démarre Larka et ouvre le navigateur |
| `Larka.cmd menu` | Toutes les actions, au choix |
| `Larka.cmd demarrer --reseau` | Accessible depuis les autres postes du réseau local (adresse affichée ; autorisez « php » dans le pare-feu, réseaux privés). `--local` revient à ce poste seul ; `--port N` change le port. Le choix est mémorisé |
| `Larka.cmd demo [archive.zip]` | Installe la démonstration « Ville de Larkaville » (base actuelle mise de côté dans `data\backups\`) |
| `Larka.cmd arreter` · `etat` | Arrête Larka · indique s'il tourne, et où |
| `Larka.cmd raccourci` | Raccourcis « Larka » sur le Bureau et dans le menu Démarrer |
| `Larka.cmd diagnostic` | Vérifie PHP, ses extensions, les prérequis de la version, la configuration, la base, l'emplacement du dossier |
| `Larka.cmd php [archive.zip]` | Installe la dernière version de PHP 8.3, ou l'archive indiquée (poste sans Internet) |

**Super Admin** (sauvegardes, registre multi-tenant, limites d'envoi) : **http://127.0.0.1:8000/?superadmin**.
La première fois, l'écran demande de choisir l'identifiant et le mot de passe du Super Admin — depuis le poste
où tourne Larka seulement (une configuration à distance exige `securite.allow_remote_setup`). Ce compte est
distinct des comptes de l'application (`admin`, `claire.moreau`…).

**Démonstration.** Livrée dans le dossier `demo\`, elle est proposée d'emblée au premier lancement.
Ensuite : `Larka.cmd demo`. Connexion `claire.moreau` / `Larka2026!`.

**Sans accès à Internet** (ou derrière un proxy qui bloque windows.php.net) : téléchargez ailleurs PHP 8.3
« VS16 x64 Non Thread Safe » (fichier Zip) sur [windows.php.net/download](https://windows.php.net/download/),
puis `Larka.cmd php C:\chemin\php-8.3.x-nts-Win32-vs16-x64.zip`. Le runtime Visual C++ s'installe avec
`vc_redist.x64.exe` (site de Microsoft). Le proxy du système est utilisé, avec l'identité Windows de
l'utilisateur.

**Données et sauvegarde.** Tout vit dans le dossier de Larka : `data\` (la base `data\gmao.db`, les journaux,
les fichiers des plans et des modules), `config.json`, `.env` et `config.key`. Les **sauvegardes automatiques**
(*Configuration → Serveur → Sauvegardes automatiques*, à activer) sont faites par `Larka.cmd` tant que Larka
tourne : 2 minutes après le démarrage, puis toutes les 10 minutes s'il y en a une à faire (journal
`data\logs\sauvegardes.log`). Pour une copie complète en dehors de ces sauvegardes, arrêtez Larka et copiez le
dossier (au minimum `data\`, `config.json`, `.env` et `config.key`). Les sauvegardes de l'écran Super Admin
fonctionnent aussi.

**Mises à jour.** Larka se met à jour depuis l'interface comme sous Linux (le dossier `php\` n'est jamais
touché). PHP : fermez Larka, puis `Larka.cmd php`.

**Démarrage avec Windows.** Copiez le raccourci « Larka » dans le dossier de démarrage (`Win+R`,
`shell:startup`) : la fenêtre s'ouvrira à chaque ouverture de session.

**Limites.** Le serveur intégré à PHP ne traite qu'une requête à la fois sous Windows : il convient à un poste
ou à une petite équipe. Pour un service partagé par de nombreux utilisateurs, installez Larka sur un serveur
Linux (`./start.sh prod`). PostgreSQL reste possible (`pdo_pgsql` est chargé) : renseignez `config.json` et
`.env`, ou passez par **Configuration → Serveur → Migration**.

**Désinstaller.** Fermez Larka, supprimez son dossier et ses raccourcis. Le runtime Visual C++, composant
partagé de Windows, reste en place.

---

## 🏭 Installation en production

```bash
sudo ./start.sh prod --domain gmao.monentreprise.fr
```

Met en place PostgreSQL, **PHP-FPM**, **Nginx** en HTTPS, les tâches **cron** (sauvegardes automatiques
toutes les 10 min, rotation des logs, nettoyage des sessions), un format de journal Nginx **sans jetons**, et
génère un `.env` (secrets, `chmod 600`) + un `config.json` (sans secret ; sauvegardes actives toutes les 6 h)
adaptés au domaine. Les **prérequis** sont contrôlés avant de configurer quoi que ce soit, puis en fin
d'installation.

Le mot de passe de la base n'est **jamais affiché** : il reste dans `.env` (`GMAO_DB_PASSWORD`). Pour l'imposer
sans qu'il apparaisse dans l'historique du shell ni dans la liste des processus : `--db-password-file <fichier>`
(ou, depuis un shell root, la variable `LARKA_DB_PASSWORD`). `--db-password <valeur>` reste accepté, avec un
avertissement.

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
activé au boot) ; la minuterie `larka-sauvegardes.timer`, posée en même temps, déclenche les sauvegardes
automatiques. `start` / `stop` / `status` / `logs` pilotent ensuite ce service. Pour désactiver :
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
| **Clé de chiffrement** : valeurs sensibles de `config.json`, mots de passe des bases des tenants (registre Super Admin) | **`config.key`** (`chmod 600`) — à sauvegarder avec `.env`, à part |
| Fuseau horaire de l'application | `serveur.fuseau_horaire` (`config.json`) ; vide : `date.timezone` de php.ini, sinon le fuseau du système |

Modèles fournis : [`.env.example`](.env.example) et [`config.example.json`](config.example.json).
Les fichiers générés (`.env`, `config.json`) et toute clé (`*.key`, dont `config.key`) sont exclus par le
[`.gitignore`](.gitignore).

**Aucun mot de passe à l'écran ni dans les journaux** : l'installation ne montre pas le mot de passe de la base,
le lanceur ne le passe jamais en argument de commande (il serait visible dans la liste des processus), et les
jetons à usage unique ne sont pas journalisés. Le mot de passe initial du compte `admin` est déposé dans
`data/security/admin-initial.txt` (lisible du seul compte du serveur ; sous Windows, la console du lanceur
l'affiche aussi) ; ce fichier est effacé dès que le mot de passe est changé.

**Précédence des secrets** (la plus haute gagne) : variable d'environnement système → `.env` → résidu dans `config.json`.

> ⚠️ Si vous récupérez ce projet depuis une archive ayant pu contenir des secrets, **régénérez-les**
> (clé secrète, clé privée VAPID via l'interface, mots de passe BDD).

---

## 🛡️ Sécurité

- **Transport** : HTTPS imposé (HSTS 1 an), redirection systématique du port 80.
- **Secrets au repos** : `config.json` chiffré en **AES-256-GCM** ; clé dans `config.key` (`chmod 600`), qui chiffre aussi les mots de passe des bases des tenants dans le registre.
- **Journaux sans secrets** : aucun mot de passe affiché par l'installation ; jetons à usage unique absents des journaux du serveur intégré (`-q`) et de Nginx (format `larka_sans_jetons`).
- **Données personnelles** : politique de protection des données intégrée, adaptée à la configuration réelle, personnalisable par organisation (Super Admin → Conformité RGPD) — toute personnalisation est signalée aux utilisateurs.
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

**Sauvegardes automatiques.** Réglages : *Configuration → Serveur → Sauvegardes automatiques* (installation
simple) ou fiche de chaque organisation dans le Super Admin (multi-tenant) — activation, intervalle, nombre
conservé (30 par défaut ; la rotation supprime base **et** fichiers ensemble), dossier (un chemin absolu vise un
NAS), compression. Actives toutes les 6 h après `./start.sh prod` ; à activer après `./start.sh install` et
sous Windows.

Un serveur web ne fait rien à heure fixe : `api/outils/sauvegarde-auto.php` passe toutes les 10 minutes et ne
fait que les sauvegardes **dues**.

| Installation | Qui le lance |
|---|---|
| Production (`./start.sh prod`) | Tâche cron `/etc/cron.d/gmao` (www-data) ; à défaut, relais PHP-FPM après une requête |
| Démarrage automatique (`autostart on`) | Minuterie systemd `larka-sauvegardes.timer` |
| `./start.sh start` | Planificateur lancé à côté du serveur, arrêté avec lui |
| Windows | `Larka.cmd`, tant que Larka tourne |

L'écran montre la dernière sauvegarde, la prochaine, le dernier échec et sa raison, et signale un planificateur
arrêté (« planificateur inactif »). En ligne de commande : `php api/outils/sauvegarde-auto.php --etat` ou
`--forcer` ; journal : `data/logs/sauvegardes.log`. Une sauvegarde interrompue ne laisse jamais de fichier
tronqué : elle s'écrit en `.partiel`, renommé une fois complet.

> Externalisation et **test de restauration** restent à la charge de l'exploitant. `config.key` chiffre aussi
> les mots de passe des bases des tenants : sans lui, ces bases restent intactes, mais leurs mots de passe sont
> à ressaisir dans le Super Admin — sauvegardez-le avec `.env`, à part.

---

## 🎨 Super administration & personnalisation de la connexion

- **Super Admin** (`…/?superadmin`) — gestion des tenants (une base PostgreSQL par tenant), des utilisateurs et monitoring. Connexion locale ou Microsoft.
  À la création d'une organisation, le **domaine des adresses e-mail est obligatoire** (`*` pour toutes) : c'est lui qui dirige un utilisateur vers son organisation à la connexion.
- **Onglet « 🛡️ Conformité RGPD »** — la politique de protection des données est commune ; chaque organisation peut la compléter (responsable de traitement, DPO, contact) ou réécrire et ajouter des sections, avec aperçu avant enregistrement. Toute personnalisation est affichée aux utilisateurs (bandeau daté, « Personnalisé par l'entreprise » sur chaque section). Le modèle commun se règle au même endroit.
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

**Même question, même réponse, quel que soit le modèle.** En mode **fiable** (défaut), c'est le moteur Larka
qui comprend la question, fait les recherches et rédige la réponse à partir de gabarits — liens vers les fiches et
les plans compris. Le modèle n'est **pas consulté** : un 0,6B, un 1B, un 7B ou un modèle distant donnent
exactement le même texte, en quelques millisecondes, même si le moteur local est arrêté.

| Réglage (`config.json` ou *Configuration → Assistant IA*) | Valeurs | Effet |
|---|---|---|
| `assistant.mode` — *Mode de réponse* | `fiable` (défaut) · `agent` | `agent` : l'ancien fonctionnement, le modèle choisit ses recherches et rédige ; la réponse dépend alors du modèle. |
| `assistant.interpretation_ia` — *Recours au modèle* | `off` (défaut) · `auto` | En mode fiable, `auto` laisse le modèle **reformuler** les seules questions que le moteur ne comprend pas, dans un formulaire JSON contraint (température 0, graine fixe) que le moteur vérifie. Il ne rédige jamais la réponse. C'est le seul cas où la taille du modèle compte : réservez-le aux modèles ≥ 7B. |

Ce que le moteur comprend, par exemple : « où est l'extincteur 2 du centre technique ? », « cb de clims au bat B »,
« interventions en retard », « quand expire le contrat Otis ? », « qu'est-ce qui est prévu mercredi ? »,
« interventions prévues en novembre », « où est le bureau 204 ? », « les demandes de Paul Durand », « qui est
Sophie Martin ? », les suites de conversation (« et au bâtiment B ? », « le 2e », « où est-il ? ») et le langage
parlé (« le truc qui chauffe l'eau au RDC »). Côté demandeur, il prépare la demande (titre, catégorie, urgence,
bâtiment, étage, bureau) et suit les demandes en cours.

La garantie est vérifiée par `php outils/epreuves/test-assistant.php` : plus de 120 questions rejouées avec des modèles
simulés (parfait, médiocre, aberrant, injoignable) et, avec `LARKA_EPREUVE_OLLAMA=<modèle>`, un vrai modèle
Ollama — réponses identiques à l'octet près, aucun élément inventé. L'épreuve tourne aussi avant chaque
`./start.sh prod`.

| Mode | Fournisseurs | Sortie de données | Clé d'API |
|---|---|---|---|
| **Local** _(recommandé)_ | Ollama, LM Studio, llama.cpp | **Aucune** — tout reste sur le serveur | non requise |
| Distant | Anthropic, OpenAI, Mistral, Google Gemini, Copilot | Les extraits envoyés au modèle quittent le SI | `GMAO_ASSISTANT_API_KEY` |

Modèles par défaut (champ « Modèle » vide) : les plus rapides et économiques de chaque fournisseur —
`claude-haiku-4-5`, `gpt-4o-mini`, `mistral-small-latest`, `gemini-3.1-flash-lite`, `ministral-3:3b` (Ollama).
En mode fiable sans recours au modèle, ce choix est sans effet sur les réponses ; les paragraphes qui suivent
(cache, pré-recherche, préchauffage) concernent le mode agent et le recours au modèle.

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

La version installée est dans `version.json` (affichée sur la page de connexion : « V2.2.0 »).

**Détection automatique, installation validée.** Larka vérifie la source des versions (GitHub Releases de
`mises_a_jour.depot`, ou un manifeste `latest.json`) au plus toutes les 12 h. Quand une version plus récente existe,
un bandeau la propose à l'administrateur du serveur (Super Admin ; Admin/Gestionnaire en mono-tenant), qui lit les
nouveautés et coche une confirmation avant d'installer. Aussi : *Configuration → Version et mises à jour*.

**Compatibilité vérifiée d'abord.** Chaque version déclare ses prérequis (bloc `prerequis` de son `version.json`,
repris dans `latest.json`) : PHP, extensions, SQLite, PostgreSQL / MariaDB. Larka les compare au serveur avant
tout téléchargement, puis sur l'archive avant d'écrire ; une version incompatible est présentée avec la raison
(version de PHP trop ancienne, extension manquante…) mais ne s'installe pas. Hors interface :
`php api/outils/prerequis.php --paquet=<dossier décompressé>`.

**Les données ne sont jamais touchées** : `data/` (base SQLite, plans, médias, modules installés, sauvegardes),
`config.json`, `.env`, `config.key` (comme tout fichier `.key`) et les réglages de modules existants restent tels
quels ; aucun fichier n'est supprimé.
Avant d'écrire, les fichiers remplacés (et la base SQLite) sont sauvegardés dans `data/maj/sauvegardes/` ; une erreur en
cours d'installation restaure automatiquement la version précédente, et l'historique permet de revenir en arrière.

**Intégrité** : HTTPS uniquement, empreinte SHA-256 obligatoire, et **signature Ed25519 toujours exigée**,
vérifiée avec la clé publique de l'éditeur livrée dans le code (`api/CleEditeur.php`). Une version non signée,
signée par une autre clé, ou modifiée après signature est refusée avant toute écriture. La clé n'est plus lue
dans `config.json` (`mises_a_jour.cle_publique` est ignorée) : ce fichier se modifie depuis l'interface, et un
compte capable de le modifier ne doit pas pouvoir désigner qui a le droit d'envoyer du code au serveur. Pour la
même raison, un retour arrière ne remet jamais l'outil de mise à jour ni la clé (`api/MiseAJour.php`,
`api/CleEditeur.php`…), et une sauvegarde antérieure à la signature obligatoire ne se restaure qu'en ligne de
commande sur le serveur. L'extension PHP **sodium** est requise. Changer de clé passe par une version de
transition, signée avec l'ancienne : voir l'en-tête de `outils/publier-version.php`.

> ⚠️ Un serveur vérifie avec la clé de la version qu'il a installée. Ne déployez donc sur un serveur que des
> archives produites par `outils/publier-version.php` (clé inscrite) : un code dont `api/CleEditeur.php` est vide
> refuse toute mise à jour par l'interface, et ne se met plus à jour que par réinstallation manuelle.

```bash
# Éditeur — une fois : paire de clés (la publique est écrite dans api/CleEditeur.php)
php outils/publier-version.php --generer-cles
# … ou, avec une clé privée existante : inscrire seulement la clé publique
php outils/publier-version.php --ecrire-cle-publique --cle ~/.larka-publication.key
# Éditeur — à chaque version : version.json, ?v=, zip, .sha256, .sig, latest.json
# (canal : celui de version.json, sauf --canal Beta pour une préversion — alors gh … --prerelease)
php outils/publier-version.php --version 2.2.1 --notes notes.md
gh release create v2.2.1 dist/LARKA_GMAO-2.2.1.zip dist/LARKA_GMAO-2.2.1.zip.sha256 dist/LARKA_GMAO-2.2.1.zip.sig --notes-file notes.md

# Serveur — sans interface (ou si les fichiers ne sont pas inscriptibles par PHP)
sudo -u www-data php /var/www/gmao/api/outils/mise-a-jour.php --verifier
sudo -u www-data php /var/www/gmao/api/outils/mise-a-jour.php --installer
```

---

## 🧪 Diagnostic & dépannage

`start.sh` signale les échecs avec un code **`GMAO-Exx`** (également le code de sortie : `echo $?`).
`./start.sh doctor` teste PHP, les extensions, PostgreSQL, la configuration, le démarrage au boot et les
**prérequis** de la version installée (✓, ⚠ ou ✗, avec la marche à suivre), et annote chaque problème.
`./start.sh doctor --fix` répare en plus ce qui peut l'être automatiquement. Rapport des prérequis seul :
`php api/outils/prerequis.php`. L'écran **Aide** (menu de l'application) présente les mêmes informations à
l'exploitant du serveur, avec les procédures propres à l'installation détectée.

Sous Windows, `Larka.cmd diagnostic` fait le même travail (PHP et ses extensions, prérequis, certificats, configuration,
intégrité de la base, emplacement du dossier, fin des journaux `data\logs\php_errors.log` et
`data\logs\serveur.log`).

`./start.sh epreuves` (ou `make epreuves`) vérifie les protections du moteur de
modules. Elles tournent aussi **automatiquement avant chaque `./start.sh prod`**,
qui refuse de déployer si l'une d'elles tombe — une vérification qu'il faut
penser à lancer n'est jamais lancée.

| Code | Cause probable | Correction |
|---|---|---|
| `GMAO-E10` | PHP introuvable | `./start.sh install` |
| `GMAO-E11` | Extensions PHP manquantes | `apt install php-pgsql php-mbstring php-curl php-gd php-xml` |
| `GMAO-E12` | PHP trop ancien (8.1 minimum) | Mettre PHP à niveau |
| `GMAO-E13` | Prérequis de cette version non satisfaits (PHP, extension, SQLite ou serveur de base trop ancien) | `./start.sh doctor` : détail et marche à suivre |
| `GMAO-E20` | Client PostgreSQL absent | `./start.sh install` |
| `GMAO-E21` | PostgreSQL non démarré | `./start.sh doctor --fix` |
| `GMAO-E22` | Échec de création du rôle / des bases | Vérifier les droits du compte admin PostgreSQL |
| `GMAO-E23` | Authentification `pg_hba.conf` refusée | `./start.sh setup --fix-pg-auth` |
| `GMAO-E24` | Rôle `gmao` sans `CREATEDB` (création de tenant) | `sudo -u postgres psql -c "ALTER ROLE gmao CREATEDB;"` |
| — | « collation version mismatch » (après une mise à jour du système) | `./start.sh doctor --fix` : index reconstruits, aucune donnée modifiée |
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
├── sw.js                   Service Worker (push) — v2.2.0
├── router.php              Routeur du serveur PHP intégré (dev / autonome / Windows)
├── start.sh  Makefile      Lanceur unifié + raccourcis
├── Larka.cmd               Lanceur Windows (double-clic) — voir deploy/windows/
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
│   ├── Prerequis.php       Prérequis d'une version (installation, démarrage, mise à jour)
│   ├── SauvegardesAuto.php Planificateur des sauvegardes automatiques
│   ├── Rgpd.php            Politique de protection des données (modèle commun + personnalisation)
│   ├── AideFiches.php      Contenu de l'écran Aide : fiches FR / EN par rôle, mots-clés de recherche
│   ├── AssistantTools.php  Outils de l'assistant IA
│   ├── Assistant*.php      Moteur « fiable » : Langue, Comprehension, Moteur, Demandeur,
│   │                       Interprete (recours au modèle, contraint), Fiable (enchaînement), LLM
│   ├── routes/             26 modules (auth, inventaire, maintenance, urgences, assistant, rgpd, aide, …)
│   ├── outils/             En ligne de commande : sauvegarde-auto, prerequis, mise-a-jour, postgresql
│   └── extensions/         Couche des modules déclaratifs
│       ├── Registre.php    Installation, activation, rôles accordés, exécution
│       ├── Manifeste.php   Chargement et validation d'une déclaration
│       ├── Paquet.php      Format .larka : vérification, extraction, installation
│       ├── declaratif/     Schema · Moteur · Expression (91 fn) · Condition (33 op)
│       ├── securite/       Médiation des requêtes, politique de données, surveillance
│       └── outils/         construire-paquet.php (fabrique un .larka)
├── js/                     Frontend : cœur + 36 pages + filtres (lazy-loadés)
│   ├── declaratif.js       Rendu des écrans décrits par un module
│   ├── extensions.js       Chargement des modules côté client
│   └── vendor/             Leaflet (cartes) · pdf.js (plans PDF) · SheetJS (import Excel)
│                           — servis par Larka, aucun CDN tiers (ZXing : js/zxing-browser.min.js)
├── css/                    10 feuilles de style + polices WOFF2 embarquées
├── lang/                   fr.json (catalogue des libellés) · en.json (traduction, ligne pour ligne)
│                           · complements.json (libellés que l'extraction ne voit pas)
├── oauth/                  Callbacks OAuth (microsoft.php, google.php)
├── extensions/             Modules livrés : modules/ · themes/ · langues/ · config/
├── outils/                 Développement uniquement — NON déployé
│   ├── verifier-module.php Valide une déclaration contre le schéma du serveur
│   ├── epreuves/           22 suites PHP + 2 JS, ≈ 1 900 contrôles (voir epreuves/README.md)
│   └── langues/            Extraction des libellés, construction et vérification des packs de langue
├── demo/                   Démonstration « Ville de Larkaville » (données fictives) — NON déployée
├── deploy/                 install.sh · nginx.conf · php-fpm-gmao.conf · gmao.service
│   │                       ollama-larka.conf · Modelfile.larka   (assistant IA local)
│   └── windows/            larka.php (lanceur) · installer-php.ps1 (PHP pour Windows) · larka.ico
├── Documentations/         DAT + manuels (.docx) + référence du format déclaratif
│                           + 9 modules d'exemple vérifiés
├── licenses/               Textes des licences tierces (Apache-2.0, OFL-1.1, MIT, BSD)
├── version.json            Version, canal, date et prérequis de la version
├── php/                    (Windows) PHP installé par Larka.cmd — absent de l'archive
└── data/                   Runtime hors web : backups, logs, sessions, security,
                            urgences (médias), extensions (modules installés),
                            run (PID, état des sauvegardes automatiques)
```

---

## 🐙 Publier sur GitHub

Dépôt : <https://github.com/Chipsoreo/LARKA_GMAO>. `config.json`, `.env`, les clés (`*.key`), les bases et les
données d'exécution sont exclus par `.gitignore` : ils ne partent jamais sur GitHub. L'archive « GitHub » d'une
version (`LARKA_GMAO-2.2.0-github.zip`, dossier `LARKA_GMAO/`) contient exactement ce que le dépôt doit suivre,
démonstration comprise (`demo/`) — mais ni PHP pour Windows, ni base, journaux ou sauvegardes.

**Mettre à jour le dépôt** (il contient déjà une version) : on remplace son contenu, l'historique est conservé ;
les fichiers qui n'existent plus dans la nouvelle version disparaissent aussi du dépôt.
Depuis le dossier où se trouve le zip :

```powershell
# Windows (PowerShell) — Git pour Windows installé
Expand-Archive LARKA_GMAO-2.2.0-github.zip -DestinationPath larka-2.2.0
git clone https://github.com/Chipsoreo/LARKA_GMAO.git depot-larka
cd depot-larka
git rm -r -q .                                  # retire l'ancienne version (l'historique reste)
Get-ChildItem -Force ..\larka-2.2.0\LARKA_GMAO | Copy-Item -Destination . -Recurse -Force
git add -A
git commit -m "Larka V2.2.0"
git push
```

```bash
# Linux, macOS ou Git Bash (Nobara, Fedora : sudo dnf install git unzip gh)
unzip -q LARKA_GMAO-2.2.0-github.zip -d larka-2.2.0
git clone https://github.com/Chipsoreo/LARKA_GMAO.git depot-larka
cd depot-larka
git rm -r -q .                                  # retire l'ancienne version (l'historique reste)
cp -a ../larka-2.2.0/LARKA_GMAO/. .
git add -A
git commit -m "Larka V2.2.0"
git push
```

**Premier envoi** (dépôt GitHub vide) : dans le dossier `LARKA_GMAO` décompressé,
`git init`, `git add .`, `git commit -m "Larka V2.2.0"`, `git branch -M main`,
`git remote add origin https://github.com/Chipsoreo/LARKA_GMAO.git`, puis `git push -u origin main`.

Au premier `git push`, Git demande de se connecter à GitHub : fenêtre du navigateur sous Windows ; sous Linux,
lancez d'abord `gh auth login` (ou donnez un jeton d'accès personnel en guise de mot de passe). Sur un poste
neuf, Git veut aussi un nom et une adresse : `git config --global user.name "…"` et `user.email "…"`. Par le site (*Add file → Upload files*), GitHub n'accepte que
100 fichiers par envoi, et Larka en compte plus de 300 : préférez git, ou GitHub Desktop.

**Release** (c'est elle que les serveurs détectent pour la mise à jour automatique). `notes.md` : les notes de
la version. L'écran de mise à jour de Larka les affiche telles quelles, en texte brut et dans la limite de 8 000
caractères : préférez des listes simples aux tableaux.

```bash
# paquet + empreinte + signature (OBLIGATOIRE : les serveurs refusent toute version
# non signée), puis release. Une seule fois, avant la première publication :
# php outils/publier-version.php --generer-cles
# (--reconstruire : 2.2.0 est déjà la version inscrite dans version.json ; inutile pour les suivantes.
#  Le canal est celui de version.json : Stable. Une préversion : --canal Beta, et gh … --prerelease)
php outils/publier-version.php --version 2.2.0 --notes notes.md --reconstruire
git commit -am "Publication 2.2.0" && git push      # l'outil rafraîchit le ?v= d'index.html
gh release create v2.2.0 dist/LARKA_GMAO-2.2.0.zip dist/LARKA_GMAO-2.2.0.zip.sha256 \
    dist/LARKA_GMAO-2.2.0.zip.sig --title "Larka V2.2.0" --notes-file notes.md
# (gh crée l'étiquette v2.2.0 ; sans le .sig, la version est refusée par tous les serveurs)
```

Sans l'outil `gh` : GitHub → *Releases* → *Draft a new release*, choisir l'étiquette `v2.2.0`, joindre le `.zip`,
le `.sha256` **et le `.sig`** — et rien d'autre. Version stable : ne cochez pas *Set as a pre-release* (à cocher
pour une préversion).

La **démonstration** est dans le dépôt, dans `demo/` (un seul fichier, ≈ 14 Mo) : une copie fraîche la propose
d'emblée. Ne la joignez pas à une release : une installation antérieure à la 2.2.0 pourrait la prendre pour
l'archive de la mise à jour, et refuserait alors la version, faute de signature.

---

## 🤝 Contribuer

Les contributions sont les bienvenues. Quelques points utiles :

- Lancez `./start.sh doctor` avant d'ouvrir une issue d'installation.
- Avant un commit : `php -l` sur les fichiers PHP modifiés et `node --check` sur les fichiers JS.
- Avant une livraison : `bash outils/epreuves/toutes.sh` (≈ 1 900 contrôles ;
  huit suites demandent une base configurée, SQLite suffit ;
  `LARKA_EPREUVE_PG="host=…;port=…;dbname=…;user=…;password=…"` ajoute les volets
  PostgreSQL). `./start.sh prod` lance les plus rapides tout seul et **refuse de
  déployer** si l'une échoue.
- En ajoutant un libellé à l'interface : `php outils/langues/extraire-libelles.php`,
  puis `php outils/langues/construire-pack.php en` — voir
  [Traduire l'interface](#traduire-linterface).
- En ajoutant une primitive au format déclaratif, nourrissez
  `outils/epreuves/test-fonctionnalites.php` : sans cela la déclaration passe,
  l'écran s'affiche, et la fonctionnalité manque — sans rien pour le signaler.
- Conservez les **en-têtes de licence SPDX** : tout nouveau fichier source commence par le même en-tête que les autres.
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

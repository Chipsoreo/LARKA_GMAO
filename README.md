<div align="center">

# 🔧 Larka — Gestion de Maintenance Assistée par Ordinateur (GMAO)

**Plateforme web _multi-tenant_ de gestion de maintenance.**
Backend PHP sans framework, base PostgreSQL par client, frontend JavaScript sans build, installable en PWA.

[![Version](https://img.shields.io/badge/version-1.0.0%20(V1)-0a1628.svg)](Documentations/)
[![Licence](https://img.shields.io/badge/licence-propri%C3%A9taire-red.svg)](LICENSE)
[![Ko-fi](https://img.shields.io/badge/Ko--fi-soutenir%20le%20projet-FF5E5B?logo=ko-fi&logoColor=white)](https://ko-fi.com/chipsoreo)
[![PHP](https://img.shields.io/badge/PHP-8.3-777BB4.svg?logo=php&logoColor=white)](https://www.php.net/)
[![PostgreSQL](https://img.shields.io/badge/PostgreSQL-%E2%89%A514-336791.svg?logo=postgresql&logoColor=white)](https://www.postgresql.org/)
[![Frontend](https://img.shields.io/badge/frontend-Vanilla%20JS%20(no%20build)-F7DF1E.svg?logo=javascript&logoColor=black)](#stack-technique)
[![PWA](https://img.shields.io/badge/PWA-installable-5A0FC8.svg)](#)
[![Multi-tenant](https://img.shields.io/badge/architecture-multi--tenant-0a1628.svg)](#architecture)

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

Cette livraison est la **version 1.0.0 (V1)** — première version publique de Larka, en **bêta**.

| Élément | Valeur | Où |
|---|---|---|
| Version applicative | `1.0.0` | `LARKA_VERSION` (`api/config.php`) — source de vérité |
| Libellé commercial | `V1` | `LARKA_VERSION_LABEL` |
| Service Worker | `1.0.0` | `SW_VERSION` (`sw.js`) |
| Manifeste PWA | `1.0.0` | `manifest.webmanifest` |
| Documentation | `1.0` | `Documentations/` |

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
- 🤖 **Assistant conversationnel** — questions en langage naturel sur les données du tenant. IA **locale** (Ollama/LM Studio,
  aucune donnée ne sort du serveur) ou fournisseur distant.
- 🔔 **Notifications push** (Web Push natif, RFC 8030/8291/8292).

**Technique**

- 🔐 **Sécurité par défaut** — HTTPS/HSTS, CSP, chiffrement AES-256-GCM des secrets, RBAC, journal de sécurité.
- 🔑 **Authentification fédérée** — OAuth 2.0 / OpenID Connect (Microsoft Entra ID, Google) + comptes locaux (bcrypt).
- 📱 **PWA** installable (poste et mobile), notifications push iOS 16.4+ en mode écran d'accueil.
- ⚡ **Chargement paresseux** du frontend (lazy-loading) avec préchargement intelligent selon le rôle.
- 🎨 **Écran de connexion personnalisable** par le super-administrateur (couleurs, fond animé, CSS filtré — voir plus bas).



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
| TLS | Let's Encrypt (Certbot) | — |
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

Met en place PostgreSQL, **PHP-FPM**, **Nginx**, un certificat **HTTPS Let's Encrypt**, les tâches **cron**
(renouvellement TLS, rotation des logs, nettoyage des sessions), et génère un `.env` (secrets, `chmod 600`)
+ un `config.json` (sans secret) adaptés au domaine.

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

---

## 💾 Sauvegardes — ce qu'il ne faut pas oublier

Une sauvegarde de la **base seule ne suffit pas** : documents, plans et médias des procédures d'urgence
vivent sur le **disque**, pas en base. Restaurer un dump SQL seul donne une application dont les fiches
pointent vers des fichiers absents.

| Périmètre | Contenu | Criticité |
|---|---|---|
| Bases PostgreSQL | `gmao_admin` + une base par tenant | **Critique** |
| Fichiers | `data/` (documents, `data/urgences/`, logs) | **Critique** |
| Secrets | `.env`, `config.key` | **Critique** — à part, chiffré, en coffre |
| Configuration | `config.json` | Importante |
| Certificats TLS | `/etc/letsencrypt/` | Reconstituable (réémission) |

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
`localiser`, `alertes`, `search_sharepoint`…), **avec les droits de l'utilisateur connecté**.

| Mode | Fournisseurs | Sortie de données | Clé d'API |
|---|---|---|---|
| **Local** _(recommandé)_ | Ollama, LM Studio | **Aucune** — tout reste sur le serveur | non requise |
| Distant | Anthropic, OpenAI, Mistral | Les extraits envoyés au modèle quittent le SI | `GMAO_ASSISTANT_API_KEY` |

Inférence locale — réglages fournis (drop-in systemd) :

```bash
sudo mkdir -p /etc/systemd/system/ollama.service.d
sudo cp deploy/ollama-larka.conf /etc/systemd/system/ollama.service.d/larka.conf
sudo systemctl daemon-reload && sudo systemctl restart ollama
ollama create larka -f deploy/Modelfile.larka
```

> ⚠️ **CPU.** Une génération locale consomme durablement les cœurs disponibles : sur la même machine que
> l'application, elle dégrade les temps de réponse. Le réglage livré limite volontairement l'inférence à
> **une requête à la fois**. Prévoyez des ressources dédiées, ou déportez le moteur.
>
> ⚠️ **RGPD.** En mode **distant**, les données transmises au modèle sortent du SI : inscrivez le fournisseur
> au registre des sous-traitants et vérifiez les garanties de transfert. Le mode **local** supprime le sujet.

Le port du moteur local (`11434`) **ne doit jamais être exposé** au réseau.

---

## 🧪 Diagnostic & dépannage

`start.sh` signale les échecs avec un code **`GMAO-Exx`** (également le code de sortie : `echo $?`).
`./start.sh doctor` teste PHP, les extensions, PostgreSQL, la configuration et le démarrage au boot, et annote
chaque problème. `./start.sh doctor --fix` répare en plus ce qui peut l'être automatiquement.

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
├── sw.js                   Service Worker (push) — v1.0.0
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
│   └── routes/             21 modules (auth, inventaire, maintenance, urgences, assistant, …)
├── js/                     Frontend : cœur + 31 pages + 13 filtres (lazy-loadés)
│   └── vendor/             ZXing (QR) · Leaflet (cartes)
├── css/                    7 feuilles de style + polices WOFF2 embarquées
├── oauth/                  Callbacks OAuth (microsoft.php, google.php)
├── scripts/                Utilitaires de maintenance
├── deploy/                 install.sh · nginx.conf · php-fpm-gmao.conf · gmao.service
│                           ollama-larka.conf · Modelfile.larka   (assistant IA local)
├── Documentations/         DAT + manuels utilisateurs (.docx)
├── licenses/               Textes des licences tierces (Apache-2.0, OFL-1.1, MIT, BSD)
└── data/                   Runtime hors web : backups, logs, sessions, security,
                            urgences (médias), run (PID)
```

---

## 🤝 Contribuer

Les contributions sont les bienvenues. Quelques points utiles :

- Lancez `./start.sh doctor` avant d'ouvrir une issue d'installation.
- Avant un commit : `php -l` sur les fichiers PHP modifiés et `node --check` sur les fichiers JS.
- Conservez les **en-têtes de licence SPDX** ; pour les (ré)appliquer : `bash scripts/add-license-headers.sh`.
- Toute **modification** du logiciel requiert l'**autorisation écrite préalable** de l'auteur (voir [`LICENSE`](LICENSE), §5).

---

## 📜 Licence

Larka est un **logiciel propriétaire**. © 2025-2026 Mickaël Larcin (« Chipsoreo ») — **Tous droits réservés**.
Son utilisation est régie par la **Licence d'utilisation Larka** (identifiant SPDX `LicenseRef-Larka-Proprietary`) ;
voir le fichier [`LICENSE`](LICENSE).

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

# Larka — codes d'erreur

> Document généré par `php api/outils/codes-erreur.php --md` à partir de `api/CodesErreur.php` : ne pas le modifier à la main.

Chaque incident du journal (écran **Journal**, onglet **Connexions → Journal** de la supervision, fichiers
`data/logs/activite-*.txt`) et chaque erreur affichée à l'écran porte un **code** : trois lettres de domaine,
trois chiffres. Un clic sur le code ouvre sa fiche ; en ligne de commande : `php api/outils/codes-erreur.php CODE`.
Un code ne change jamais de sens.

| Domaine | | Domaine | |
| --- | --- | --- | --- |
| `SYS` | Système | `BDD` | Base de données |
| `FIC` | Fichiers et disque | `SEC` | Connexion et sécurité |
| `API` | Requêtes refusées | `NAV` | Navigateur |
| `MEL` | Messagerie et notifications | `TAC` | Tâches de fond |
| `CXC` | Supervision : collecte | `CXR` | Supervision : réseau |
| `CXP` | Supervision : protocoles | `CXB` | Bornes de recharge |
| `CXL` | Capteurs LoRaWAN | `CXW` | Compteurs radio wM-Bus |
| `CXH` | Appareils hors réseau | `CXA` | Alarmes, relevés, écritures |

## Index

| Code | Titre | Gravité | Qui agit |
| --- | --- | --- | --- |
| [`SYS-001`](#sys-001) | Erreur interne du serveur | erreur | administrateur |
| [`SYS-002`](#sys-002) | Erreur fatale de PHP | critique | administrateur |
| [`SYS-003`](#sys-003) | Mémoire de PHP épuisée | critique | administrateur |
| [`SYS-004`](#sys-004) | Durée maximale dépassée | erreur | administrateur |
| [`SYS-005`](#sys-005) | Extension PHP manquante | critique | administrateur |
| [`SYS-006`](#sys-006) | Configuration illisible | critique | administrateur |
| [`SYS-007`](#sys-007) | Avertissement de PHP | avertissement | administrateur |
| [`BDD-001`](#bdd-001) | Base de données injoignable | critique | administrateur |
| [`BDD-002`](#bdd-002) | Base de données occupée | avertissement | administrateur |
| [`BDD-003`](#bdd-003) | Structure de la base incomplète | erreur | administrateur |
| [`BDD-004`](#bdd-004) | Donnée refusée par la base | erreur | gestionnaire |
| [`BDD-005`](#bdd-005) | Base en lecture seule | critique | administrateur |
| [`FIC-001`](#fic-001) | Écriture impossible sur le disque | critique | administrateur |
| [`FIC-002`](#fic-002) | Disque plein | critique | administrateur |
| [`FIC-003`](#fic-003) | Fichier refusé | avertissement | utilisateur |
| [`SEC-001`](#sec-001) | Identifiant ou mot de passe incorrect | avertissement | utilisateur |
| [`SEC-002`](#sec-002) | Compte temporairement bloqué | avertissement | utilisateur |
| [`SEC-003`](#sec-003) | Session expirée | info | utilisateur |
| [`SEC-004`](#sec-004) | Accès refusé | avertissement | administrateur |
| [`SEC-005`](#sec-005) | Confirmation d'identité requise | info | utilisateur |
| [`SEC-006`](#sec-006) | Jeton de sécurité invalide (CSRF) | avertissement | utilisateur |
| [`SEC-007`](#sec-007) | Trop de requêtes | avertissement | utilisateur |
| [`SEC-008`](#sec-008) | Connexion fédérée refusée (Microsoft, Google) | erreur | administrateur |
| [`SEC-009`](#sec-009) | Tentative suspecte bloquée | avertissement | administrateur |
| [`API-400`](#api-400) | Saisie refusée | info | utilisateur |
| [`API-404`](#api-404) | Élément introuvable | info | utilisateur |
| [`API-409`](#api-409) | Action impossible dans l'état actuel | info | gestionnaire |
| [`API-413`](#api-413) | Envoi trop volumineux | avertissement | utilisateur |
| [`API-500`](#api-500) | Le serveur a rencontré une erreur | erreur | administrateur |
| [`NAV-001`](#nav-001) | Erreur dans la page (navigateur) | avertissement | administrateur |
| [`NAV-002`](#nav-002) | Serveur injoignable depuis le navigateur | avertissement | utilisateur |
| [`MEL-001`](#mel-001) | Courriel non envoyé | erreur | administrateur |
| [`MEL-002`](#mel-002) | Notification push non remise | avertissement | administrateur |
| [`TAC-001`](#tac-001) | Sauvegarde automatique en échec | erreur | administrateur |
| [`TAC-002`](#tac-002) | Planificateur arrêté | erreur | administrateur |
| [`TAC-003`](#tac-003) | Mise à jour non installée | erreur | administrateur |
| [`CXC-001`](#cxc-001) | Collecte automatique arrêtée | erreur | administrateur |
| [`CXC-002`](#cxc-002) | Connexion en défaut | erreur | gestionnaire |
| [`CXC-003`](#cxc-003) | Connexion partielle | avertissement | gestionnaire |
| [`CXC-004`](#cxc-004) | Communication rétablie | info | gestionnaire |
| [`CXC-005`](#cxc-005) | Passage de collecte trop long | avertissement | administrateur |
| [`CXC-006`](#cxc-006) | Point illisible | avertissement | gestionnaire |
| [`CXC-007`](#cxc-007) | Larka Connect fermé | info | administrateur |
| [`CXR-001`](#cxr-001) | L'équipement refuse la connexion | erreur | gestionnaire |
| [`CXR-002`](#cxr-002) | L'équipement ne répond pas (pare-feu probable) | erreur | service informatique |
| [`CXR-003`](#cxr-003) | Nom de l'équipement inconnu (DNS) | erreur | service informatique |
| [`CXR-004`](#cxr-004) | Aucune route vers l'équipement | erreur | service informatique |
| [`CXR-005`](#cxr-005) | Adresse de l'équipement absente ou invalide | erreur | gestionnaire |
| [`CXR-006`](#cxr-006) | Certificat HTTPS refusé | erreur | service informatique |
| [`CXP-001`](#cxp-001) | Modbus : registre inexistant | erreur | gestionnaire |
| [`CXP-002`](#cxp-002) | Modbus : l'équipement derrière la passerelle ne répond pas | erreur | gestionnaire |
| [`CXP-003`](#cxp-003) | Modbus : requête refusée par l'équipement | erreur | gestionnaire |
| [`CXP-004`](#cxp-004) | BACnet : aucune réponse | erreur | service informatique |
| [`CXP-005`](#cxp-005) | BACnet : objet ou propriété inconnu | erreur | gestionnaire |
| [`CXP-006`](#cxp-006) | MQTT : connexion refusée par le broker | erreur | gestionnaire |
| [`CXP-007`](#cxp-007) | MQTT : abonnement refusé | erreur | gestionnaire |
| [`CXP-008`](#cxp-008) | Le port répond, mais pas avec le protocole attendu | erreur | gestionnaire |
| [`CXP-009`](#cxp-009) | API REST : authentification refusée | erreur | gestionnaire |
| [`CXP-010`](#cxp-010) | API REST : réponse d'erreur ou illisible | erreur | gestionnaire |
| [`CXP-011`](#cxp-011) | CSV : dossier non autorisé | erreur | super administrateur |
| [`CXP-012`](#cxp-012) | CSV : aucun fichier exploitable | avertissement | gestionnaire |
| [`CXB-001`](#cxb-001) | Service OCPP arrêté | erreur | administrateur |
| [`CXB-002`](#cxb-002) | Borne inconnue refusée | avertissement | gestionnaire |
| [`CXB-003`](#cxb-003) | Borne : mot de passe refusé | avertissement | gestionnaire |
| [`CXB-004`](#cxb-004) | Borne déconnectée | avertissement | gestionnaire |
| [`CXL-001`](#cxl-001) | Aucune passerelle LoRaWAN entendue | erreur | service informatique |
| [`CXL-002`](#cxl-002) | Capteur LoRaWAN : clé refusée | erreur | gestionnaire |
| [`CXL-003`](#cxl-003) | Capteur LoRaWAN : trame rejouée | avertissement | gestionnaire |
| [`CXL-004`](#cxl-004) | Capteur LoRaWAN : activation refusée | erreur | gestionnaire |
| [`CXL-005`](#cxl-005) | Capteur LoRaWAN non déclaré | info | gestionnaire |
| [`CXL-006`](#cxl-006) | Capteur radio silencieux | avertissement | gestionnaire |
| [`CXW-001`](#cxw-001) | Aucun récepteur radio wM-Bus branché | erreur | gestionnaire |
| [`CXW-002`](#cxw-002) | Port série : accès refusé | erreur | administrateur |
| [`CXW-003`](#cxw-003) | Compteur radio non entendu | avertissement | gestionnaire |
| [`CXW-004`](#cxw-004) | Compteur radio chiffré : clé AES manquante | avertissement | gestionnaire |
| [`CXW-005`](#cxw-005) | Compteur radio chiffré : clé AES incorrecte | erreur | gestionnaire |
| [`CXW-006`](#cxw-006) | Format radio non pris en charge | avertissement | fournisseur |
| [`CXH-001`](#cxh-001) | PC releveur : jeton refusé | erreur | gestionnaire |
| [`CXH-002`](#cxh-002) | PC releveur : relevé refusé | avertissement | gestionnaire |
| [`CXH-003`](#cxh-003) | Hotspot de l'appareil introuvable | avertissement | utilisateur |
| [`CXH-004`](#cxh-004) | PC releveur jamais vu | info | gestionnaire |
| [`CXA-001`](#cxa-001) | Demande d'intervention automatique impossible | erreur | administrateur |
| [`CXA-002`](#cxa-002) | Écriture vers un équipement refusée | avertissement | gestionnaire |
| [`CXA-003`](#cxa-003) | Relevé automatique en anomalie | avertissement | gestionnaire |
| [`CXA-004`](#cxa-004) | Alarme technique | avertissement | gestionnaire |

## SYS — Système

### SYS-001

**Erreur interne du serveur** — gravité *erreur*, à traiter par : *administrateur*.

Une opération s'est arrêtée sur une erreur que Larka n'a pas su traiter. L'action demandée n'a pas été faite (ou pas entièrement) ; les données déjà enregistrées ne sont pas touchées.

**Causes probables**

- Cas imprévu dans le code (donnée inhabituelle, combinaison de réglages rare)
- Fichier de Larka modifié ou incomplet après une mise à jour interrompue

**Comment y remédier**

1. Réessayer une fois : une erreur passagère (base momentanément occupée) disparaît d'elle-même.
2. Ouvrir le détail de l'entrée dans Journal : le type d'erreur, le fichier et la ligne y figurent.
3. Si elle se répète, transmettre au support la référence de corrélation et le code SYS-001, avec ce que vous faisiez à ce moment-là.

Pour aller plus loin : README.md, « Diagnostic & dépannage ».

### SYS-002

**Erreur fatale de PHP** — gravité *critique*, à traiter par : *administrateur*.

PHP s'est arrêté au milieu d'une requête. La page a reçu une réponse incomplète ; l'action n'a probablement pas abouti.

**Causes probables**

- Fichier PHP de Larka manquant ou abîmé (mise à jour interrompue, copie partielle)
- Version de PHP trop ancienne pour cette version de Larka
- Extension PHP retirée du serveur

**Comment y remédier**

1. Vérifier les prérequis : `php api/outils/prerequis.php`.
2. Réinstaller la même version par-dessus (Configuration → Version et mises à jour, ou `./start.sh maj LARKA_GMAO-3.0.0.zip`) : vos données ne sont pas touchées.
3. Lire la ligne correspondante de `data/logs/php_errors.log` (fichier et ligne exacts).

Pour aller plus loin : Documentations/Manuel-Larka-SuperAdmin.docx, « Mises à jour de la plateforme ».

### SYS-003

**Mémoire de PHP épuisée** — gravité *critique*, à traiter par : *administrateur*.

L'opération demandait plus de mémoire que PHP n'en accorde (memory_limit). Elle a été interrompue.

**Causes probables**

- Export ou import très volumineux (plusieurs centaines de milliers de lignes)
- memory_limit réglé trop bas (128 Mo ou moins)

**Comment y remédier**

1. Découper l'opération : export sur une période plus courte, import en plusieurs fichiers.
2. Augmenter memory_limit à 512M dans le php.ini du serveur web (ou de PHP-FPM), puis redémarrer le service PHP.
3. Sous Windows (Larka.cmd), le php.ini est dans le dossier php\ de Larka.

Pour aller plus loin : README.md, « Diagnostic & dépannage ».

### SYS-004

**Durée maximale dépassée** — gravité *erreur*, à traiter par : *administrateur*.

L'opération a duré plus longtemps que PHP ne l'autorise (max_execution_time) et a été coupée.

**Causes probables**

- Traitement long (import, sauvegarde, analyse) lancé depuis l'écran
- Serveur surchargé, ou base distante lente

**Comment y remédier**

1. Relancer l'opération en dehors des heures chargées, ou sur moins de données.
2. Pour les traitements longs, utiliser la commande prévue (`php api/outils/…`) qui n'a pas cette limite.
3. Augmenter max_execution_time (et le délai de PHP-FPM / Nginx) si l'opération doit rester dans l'écran.

Pour aller plus loin : README.md, « Diagnostic & dépannage ».

### SYS-005

**Extension PHP manquante** — gravité *critique*, à traiter par : *administrateur*.

Une fonction indispensable de PHP n'est pas disponible sur ce serveur. La fonction de Larka qui en dépend ne marche pas.

**Causes probables**

- Extension non installée (pdo_sqlite, pdo_pgsql, mbstring, openssl, zip, gd, curl…)
- Extension installée mais pas activée dans le php.ini utilisé par le serveur web

**Comment y remédier**

1. Lister ce qui manque : `php api/outils/prerequis.php`.
2. Linux : installer le paquet (ex. `sudo apt install php-mbstring php-zip`), puis redémarrer PHP-FPM ou Apache.
3. Windows : relancer Larka.cmd, qui active les extensions de son PHP ; sinon supprimer le dossier php\ et relancer pour le réinstaller.

Pour aller plus loin : README.md, « Diagnostic & dépannage ».

### SYS-006

**Configuration illisible** — gravité *critique*, à traiter par : *administrateur*.

Le fichier config.json est absent, vide ou mal formé (JSON invalide). Larka démarre avec ses réglages par défaut, ou pas du tout.

**Causes probables**

- Modification à la main avec une virgule ou un guillemet en trop
- Fichier enregistré dans un encodage inhabituel
- Fichier écrasé par une copie incomplète

**Comment y remédier**

1. Vérifier la syntaxe : `php -r 'json_decode(file_get_contents("config.json")); echo json_last_error_msg();'`.
2. Comparer avec config.example.json ; restaurer la dernière sauvegarde de config.json si besoin (data/backups).
3. Préférer Configuration → Serveur dans l'application : elle n'écrit que du JSON valide.

Pour aller plus loin : config.example.json.

### SYS-007

**Avertissement de PHP** — gravité *avertissement*, à traiter par : *administrateur*.

PHP a signalé une anomalie sans arrêter l'opération (variable absente, conversion douteuse…). Le résultat est en général correct, mais le cas mérite d'être regardé.

**Causes probables**

- Donnée incomplète (champ vide là où une valeur était attendue)
- Comportement d'une nouvelle version de PHP

**Comment y remédier**

1. Rien d'urgent si l'écran a fonctionné.
2. S'il se répète, transmettre au support le fichier et la ligne (détail de l'entrée).

Pour aller plus loin : README.md, « Diagnostic & dépannage ».

## BDD — Base de données

### BDD-001

**Base de données injoignable** — gravité *critique*, à traiter par : *administrateur*.

Larka n'a pas pu ouvrir sa base. Rien ne peut être lu ni enregistré tant que la base ne répond pas.

**Causes probables**

- Serveur PostgreSQL ou MariaDB arrêté, ou sur une autre machine injoignable
- Mot de passe ou nom de base modifié
- Fichier SQLite déplacé ou supprimé (data/gmao.db)

**Comment y remédier**

1. PostgreSQL : `sudo systemctl status postgresql` ; MariaDB : `sudo systemctl status mariadb`.
2. Vérifier base_de_donnees dans config.json (hôte, port, nom, utilisateur) et le mot de passe dans .env.
3. SQLite : vérifier que le fichier désigné existe et appartient à l'utilisateur du serveur web.
4. Tester la connexion depuis le serveur : `psql -h <hôte> -U <utilisateur> <base>`.

Pour aller plus loin : README.md, « Configuration & secrets ».

### BDD-002

**Base de données occupée** — gravité *avertissement*, à traiter par : *administrateur*.

La base était verrouillée par une autre écriture au même moment. L'opération a échoué après une attente ; recommencer suffit le plus souvent.

**Causes probables**

- SQLite : deux écritures longues simultanées (import, sauvegarde, collecte)
- Sauvegarde ou copie du fichier de base pendant l'utilisation
- Antivirus qui verrouille data/gmao.db (Windows)

**Comment y remédier**

1. Réessayer dans quelques secondes.
2. Windows : exclure le dossier data\ de Larka de l'analyse en temps réel de l'antivirus.
3. Avec beaucoup d'utilisateurs ou d'équipements raccordés, passer à PostgreSQL (Super Admin → bases).

Pour aller plus loin : README.md, « Configuration & secrets ».

### BDD-003

**Structure de la base incomplète** — gravité *erreur*, à traiter par : *administrateur*.

Une table ou une colonne attendue manque. La mise à niveau de la base ne s'est pas faite, ou pas jusqu'au bout.

**Causes probables**

- Mise à jour de Larka sans redémarrage complet
- Base restaurée depuis une version plus ancienne
- Droits insuffisants de l'utilisateur de la base pour créer ou modifier des tables

**Comment y remédier**

1. Recharger la page : la mise à niveau s'applique d'elle-même au premier accès.
2. PostgreSQL / MariaDB : vérifier que l'utilisateur de Larka a le droit CREATE et ALTER sur sa base.
3. Si l'erreur persiste, lancer la vérification : `php api/outils/prerequis.php` et transmettre la table citée au support.

Pour aller plus loin : Documentations/Manuel-Larka-SuperAdmin.docx, « Mises à jour de la plateforme ».

### BDD-004

**Donnée refusée par la base** — gravité *erreur*, à traiter par : *gestionnaire*.

La base a refusé l'enregistrement : doublon d'une valeur unique, lien vers un élément supprimé, ou valeur trop longue.

**Causes probables**

- Un élément du même code ou du même nom existe déjà
- L'élément lié (bien, équipement, compteur) a été supprimé entre-temps
- Saisie plus longue que ce que le champ accepte

**Comment y remédier**

1. Rechercher l'élément existant et le modifier plutôt que d'en créer un second.
2. Recharger la page puis recommencer : la liste des éléments liés sera à jour.
3. Raccourcir la saisie si elle est très longue.

Pour aller plus loin : README.md, « Diagnostic & dépannage ».

### BDD-005

**Base en lecture seule** — gravité *critique*, à traiter par : *administrateur*.

La base refuse toute écriture. Les écrans s'affichent, mais rien ne s'enregistre.

**Causes probables**

- SQLite : fichier ou dossier data/ non inscriptible par l'utilisateur du serveur web
- Disque plein ou monté en lecture seule
- Base PostgreSQL en mode réplique (lecture seule)

**Comment y remédier**

1. Linux : `sudo chown -R www-data: data && sudo chmod -R u+rwX data` (adapter l'utilisateur).
2. Vérifier l'espace disque : `df -h`.
3. PostgreSQL : vérifier que Larka vise le serveur principal, pas une réplique.

Pour aller plus loin : README.md, « Configuration & secrets ».

## FIC — Fichiers et disque

### FIC-001

**Écriture impossible sur le disque** — gravité *critique*, à traiter par : *administrateur*.

Larka n'a pas pu écrire un fichier (journal, document joint, sauvegarde, export). L'opération concernée est perdue ou incomplète.

**Causes probables**

- Dossier data/ non inscriptible par l'utilisateur du serveur web
- Disque plein
- Windows : dossier protégé (Program Files), en lecture seule, ou bloqué par l'antivirus

**Comment y remédier**

1. Linux : `sudo chown -R www-data: data && sudo chmod -R 775 data` (adapter l'utilisateur du service).
2. Vérifier l'espace libre : `df -h` (Linux) ou l'explorateur (Windows).
3. Windows : installer Larka hors de Program Files (ex. C:\Larka) et exclure son dossier de l'antivirus.

Pour aller plus loin : README.md, « Installation en production ».

### FIC-002

**Disque plein** — gravité *critique*, à traiter par : *administrateur*.

Il n'y a plus de place sur le disque du serveur. Les enregistrements, journaux et sauvegardes échouent.

**Causes probables**

- Sauvegardes accumulées (data/backups)
- Journaux volumineux non purgés
- Documents joints nombreux ou lourds

**Comment y remédier**

1. Libérer de la place : réduire « Sauvegardes conservées » (Configuration → Sauvegardes), supprimer les plus anciennes.
2. Réduire la rétention du journal (Configuration → Logs) et de l'historique des mesures (Larka Connect).
3. Agrandir le disque ou déplacer data/backups vers un autre volume.

Pour aller plus loin : README.md, « Sauvegardes ».

### FIC-003

**Fichier refusé** — gravité *avertissement*, à traiter par : *utilisateur*.

Le fichier envoyé n'a pas été accepté : type non autorisé, trop gros, ou contenu qui ne correspond pas à son extension.

**Causes probables**

- Format non autorisé par la configuration (documents.mime_autorises)
- Taille au-delà de documents.taille_max_mo
- Fichier renommé (un .exe en .pdf, par exemple)

**Comment y remédier**

1. Enregistrer le document en PDF, JPEG ou PNG, puis le joindre à nouveau.
2. Réduire sa taille (compression, résolution).
3. Administrateur : élargir les types ou la taille dans Configuration → Documents si c'est légitime.

Pour aller plus loin : README.md, « Configuration & secrets ».

## SEC — Connexion et sécurité

### SEC-001

**Identifiant ou mot de passe incorrect** — gravité *avertissement*, à traiter par : *utilisateur*.

La connexion a été refusée. Pour la sécurité, Larka ne dit pas lequel des deux est faux.

**Causes probables**

- Faute de frappe, majuscules activées
- Mot de passe changé récemment
- Compte local alors que l'organisation utilise la connexion Microsoft ou Google

**Comment y remédier**

1. Vérifier la touche Verr. Maj et retaper le mot de passe.
2. Utiliser « Mot de passe oublié » si la messagerie est configurée.
3. Sinon, demander à un administrateur de réinitialiser le mot de passe (Utilisateurs).

Pour aller plus loin : README.md, « Sécurité ».

### SEC-002

**Compte temporairement bloqué** — gravité *avertissement*, à traiter par : *utilisateur*.

Trop d'essais de connexion manqués : le compte (ou l'adresse) est bloqué pour quelques minutes, contre les tentatives d'intrusion.

**Causes probables**

- Plusieurs fautes de frappe de suite
- Un appareil (téléphone, ancien onglet) qui réessaie avec un ancien mot de passe
- Tentative d'intrusion depuis l'extérieur

**Comment y remédier**

1. Attendre la fin du délai indiqué, puis réessayer calmement.
2. Fermer les anciens onglets et applications mobiles connectés avec l'ancien mot de passe.
3. Administrateur : vérifier dans Journal (canal securite) d'où viennent les essais.

Pour aller plus loin : README.md, « Sécurité ».

### SEC-003

**Session expirée** — gravité *info*, à traiter par : *utilisateur*.

La session a pris fin (inactivité, déconnexion ailleurs, redémarrage du serveur). Il faut se reconnecter.

**Causes probables**

- Inactivité plus longue que la durée de session
- Déconnexion depuis un autre onglet
- Cookies effacés par le navigateur

**Comment y remédier**

1. Se reconnecter. Une saisie en cours non enregistrée est à refaire.
2. Si cela arrive en pleine saisie, demander à l'administrateur d'allonger la durée de session (Configuration → Sécurité).

Pour aller plus loin : README.md, « Sécurité ».

### SEC-004

**Accès refusé** — gravité *avertissement*, à traiter par : *administrateur*.

Votre rôle ne permet pas cette action. Rien n'a été modifié.

**Causes probables**

- Action réservée à un rôle supérieur (Gestionnaire, Admin, Super Admin)
- Module désactivé pour l'organisation
- Lien ouvert depuis un autre compte

**Comment y remédier**

1. Demander l'action à un gestionnaire ou un administrateur.
2. Si votre fonction le justifie, demander à un administrateur de changer votre rôle (Utilisateurs).
3. Module désactivé : le Super Admin l'ouvre par organisation (modules).

Pour aller plus loin : README.md, « Sécurité ».

### SEC-005

**Confirmation d'identité requise** — gravité *info*, à traiter par : *utilisateur*.

Cette action sensible (écriture vers un équipement, téléchargement d'un jeton…) demande de retaper son mot de passe. La confirmation vaut cinq minutes.

**Causes probables**

- Action sensible lancée plus de cinq minutes après la dernière confirmation

**Comment y remédier**

1. Saisir son mot de passe dans la fenêtre qui s'ouvre, puis relancer l'action.

Pour aller plus loin : Documentations/LARKA-CONNECT.md, § 8.

### SEC-006

**Jeton de sécurité invalide (CSRF)** — gravité *avertissement*, à traiter par : *utilisateur*.

La requête ne portait pas le jeton de la session en cours. Par sécurité, elle est refusée.

**Causes probables**

- Page ouverte depuis longtemps, session renouvelée entre-temps
- Formulaire envoyé depuis un autre site (tentative d'attaque)

**Comment y remédier**

1. Recharger la page (F5), puis recommencer.
2. Si cela se répète, vider le cache du navigateur.

Pour aller plus loin : README.md, « Sécurité ».

### SEC-007

**Trop de requêtes** — gravité *avertissement*, à traiter par : *utilisateur*.

Le serveur a limité les requêtes venant de cette adresse pendant un court instant.

**Causes probables**

- Script ou onglet qui rafraîchit en boucle
- Plusieurs postes derrière la même adresse qui font beaucoup d'essais de connexion

**Comment y remédier**

1. Patienter une minute.
2. Fermer les onglets en double.
3. Administrateur : si un proxy regroupe les postes, le déclarer dans securite.trusted_proxies (Super Admin).

Pour aller plus loin : README.md, « Sécurité ».

### SEC-008

**Connexion fédérée refusée (Microsoft, Google)** — gravité *erreur*, à traiter par : *administrateur*.

Le fournisseur d'identité n'a pas validé la connexion, ou sa réponse n'a pas pu être vérifiée.

**Causes probables**

- Secret de l'application expiré dans Entra ID / Google Cloud
- Adresse de retour (redirect URI) différente de celle déclarée chez le fournisseur
- Compte non autorisé dans le locataire

**Comment y remédier**

1. Vérifier l'adresse de retour déclarée : elle doit être exactement celle affichée dans Configuration → Authentification.
2. Renouveler le secret client chez le fournisseur et le recopier dans Larka.
3. Vérifier que l'utilisateur appartient bien au locataire ou au groupe autorisé.

Pour aller plus loin : README.md, « Sécurité ».

### SEC-009

**Tentative suspecte bloquée** — gravité *avertissement*, à traiter par : *administrateur*.

Une requête contenait ce qui ressemble à une attaque (injection, chemin détourné, fichier piégé). Elle a été refusée sans effet.

**Causes probables**

- Scanner de vulnérabilités ou robot sur Internet
- Copier-coller d'un contenu inhabituel dans un champ

**Comment y remédier**

1. Rien à faire si c'est isolé : la requête n'a rien produit.
2. Si l'adresse revient souvent, la bloquer au pare-feu ou dans le serveur web.
3. Consulter le journal de sécurité (data/security/audit.log).

Pour aller plus loin : README.md, « Sécurité ».

## API — Requêtes refusées

### API-400

**Saisie refusée** — gravité *info*, à traiter par : *utilisateur*.

Le serveur a refusé la demande parce qu'une valeur est absente ou invalide. Le message dit laquelle ; rien n'a été enregistré.

**Causes probables**

- Champ obligatoire vide
- Format inattendu (date, nombre, adresse)

**Comment y remédier**

1. Corriger la valeur citée par le message, puis enregistrer de nouveau.

Pour aller plus loin : README.md, « Diagnostic & dépannage ».

### API-404

**Élément introuvable** — gravité *info*, à traiter par : *utilisateur*.

L'élément demandé n'existe pas ou plus (supprimé, ou dans une autre organisation).

**Causes probables**

- Élément supprimé par quelqu'un d'autre entre-temps
- Lien ancien ou copié depuis une autre organisation

**Comment y remédier**

1. Recharger la liste : elle montrera l'état actuel.
2. Consulter l'Historique pour savoir qui l'a supprimé, et quand.

Pour aller plus loin : README.md, « Diagnostic & dépannage ».

### API-409

**Action impossible dans l'état actuel** — gravité *info*, à traiter par : *gestionnaire*.

La demande est valide, mais l'état présent l'empêche : module fermé, élément déjà traité, réglage manquant. Le message dit lequel.

**Causes probables**

- Module désactivé (Larka Connect, Énergie…)
- Élément déjà clôturé, validé ou en cours de traitement
- Réglage préalable manquant

**Comment y remédier**

1. Lire la condition donnée par le message et la remplir (ouvrir le module, terminer l'étape précédente).
2. Recharger la page : l'élément a peut-être changé d'état entre-temps.

Pour aller plus loin : README.md, « Diagnostic & dépannage ».

### API-413

**Envoi trop volumineux** — gravité *avertissement*, à traiter par : *utilisateur*.

Le fichier ou les données envoyés dépassent la taille acceptée par le serveur.

**Causes probables**

- Fichier très lourd
- Limite basse du serveur web (client_max_body_size, upload_max_filesize)

**Comment y remédier**

1. Réduire le fichier (compression, découpe).
2. Administrateur : relever upload_max_filesize et post_max_size (PHP) et client_max_body_size (Nginx).

Pour aller plus loin : README.md, « Configuration & secrets ».

### API-500

**Le serveur a rencontré une erreur** — gravité *erreur*, à traiter par : *administrateur*.

La requête a échoué côté serveur. Le détail est au journal, sous la même référence de corrélation.

**Causes probables**

- Voir l'entrée d'erreur qui précède, même référence de corrélation (Journal → Détail → Même requête)

**Comment y remédier**

1. Ouvrir le détail : l'entrée d'erreur liée porte son propre code et sa marche à suivre.
2. Transmettre la référence au support si rien n'est explicite.

Pour aller plus loin : README.md, « Diagnostic & dépannage ».

## NAV — Navigateur

### NAV-001

**Erreur dans la page (navigateur)** — gravité *avertissement*, à traiter par : *administrateur*.

Un script de l'interface a échoué dans le navigateur de l'utilisateur. Une partie de l'écran peut être restée vide ou sans réaction.

**Causes probables**

- Ancienne version de l'interface en cache après une mise à jour
- Navigateur trop ancien
- Extension de navigateur qui modifie les pages

**Comment y remédier**

1. Recharger sans cache : Ctrl+Maj+R (Cmd+Maj+R sur Mac).
2. Mettre le navigateur à jour (Edge, Chrome, Firefox récents).
3. Essayer en navigation privée pour écarter une extension.
4. Transmettre au support la source et la ligne (détail de l'entrée).

Pour aller plus loin : README.md, « Diagnostic & dépannage ».

### NAV-002

**Serveur injoignable depuis le navigateur** — gravité *avertissement*, à traiter par : *utilisateur*.

Le navigateur n'a reçu aucune réponse du serveur Larka : la demande n'est peut-être pas arrivée, ou sa réponse s'est perdue.

**Causes probables**

- Perte de réseau du poste (Wi-Fi, VPN coupé)
- Serveur arrêté ou redémarrage en cours
- Délai dépassé sur une opération longue

**Comment y remédier**

1. Vérifier la connexion réseau du poste, puis réessayer.
2. Si plusieurs utilisateurs sont touchés, prévenir l'administrateur : le serveur est peut-être arrêté.

Pour aller plus loin : README.md, « Diagnostic & dépannage ».

## MEL — Messagerie et notifications

### MEL-001

**Courriel non envoyé** — gravité *erreur*, à traiter par : *administrateur*.

Larka n'a pas pu remettre un courriel au serveur de messagerie. Le destinataire ne l'a pas reçu.

**Causes probables**

- Identifiants SMTP refusés ou expirés
- Serveur ou port SMTP injoignable (pare-feu)
- Envoi refusé par Microsoft 365 / Gmail (authentification moderne exigée)

**Comment y remédier**

1. Configuration → Messagerie : vérifier serveur, port (587 STARTTLS ou 465 SSL), identifiant, puis « Envoyer un courriel d'essai ».
2. Microsoft 365 : utiliser la connexion OAuth plutôt qu'un mot de passe.
3. Demander au service informatique d'autoriser la sortie vers le port SMTP.

Pour aller plus loin : README.md, « Configuration & secrets ».

### MEL-002

**Notification push non remise** — gravité *avertissement*, à traiter par : *administrateur*.

Une notification n'a pas pu être envoyée au navigateur ou au téléphone d'un utilisateur.

**Causes probables**

- Abonnement expiré (navigateur réinstallé, autorisation retirée)
- Clés VAPID modifiées
- Certificat HTTPS non pleinement valide

**Comment y remédier**

1. Demander à l'utilisateur de réactiver les notifications (Mon profil).
2. Ne pas changer les clés VAPID une fois en service.
3. Vérifier que le certificat HTTPS est valide (pas d'exception du navigateur).

Pour aller plus loin : README.md, « Configuration & secrets ».

## TAC — Tâches de fond

### TAC-001

**Sauvegarde automatique en échec** — gravité *erreur*, à traiter par : *administrateur*.

La dernière sauvegarde automatique n'a pas abouti. Les sauvegardes précédentes sont conservées.

**Causes probables**

- Disque plein
- Dossier de sauvegarde non inscriptible
- Outil de sauvegarde PostgreSQL (pg_dump) absent ou d'une autre version

**Comment y remédier**

1. Configuration → Sauvegardes : lire le message de la dernière tentative, puis « Sauvegarder maintenant ».
2. Vérifier l'espace libre et les droits de data/backups.
3. PostgreSQL : installer pg_dump de la même version que le serveur.

Pour aller plus loin : README.md, « Sauvegardes ».

### TAC-002

**Planificateur arrêté** — gravité *erreur*, à traiter par : *administrateur*.

Les tâches de fond (collecte Larka Connect, sauvegardes, rappels) ne passent plus. Rien n'est perdu, mais rien n'avance.

**Causes probables**

- Tâche cron supprimée ou serveur redémarré sans la minuterie
- Fenêtre de Larka.cmd fermée (Windows)
- ./start.sh arrêté

**Comment y remédier**

1. Production : vérifier la tâche cron (`crontab -l -u www-data`) ou `systemctl status larka-connect.timer`.
2. Poste : relancer `./start.sh start` ou Larka.cmd.
3. Activer le démarrage automatique : `./start.sh autostart on`.

Pour aller plus loin : Documentations/LARKA-CONNECT.md, § 5.

### TAC-003

**Mise à jour non installée** — gravité *erreur*, à traiter par : *administrateur*.

La mise à jour a été arrêtée avant d'être appliquée. La version en place continue de fonctionner.

**Causes probables**

- Prérequis non remplis (version de PHP, extension)
- Archive incomplète ou non signée par l'éditeur
- Dossier de Larka non inscriptible

**Comment y remédier**

1. Lire la raison affichée dans Configuration → Version et mises à jour.
2. Vérifier les prérequis : `php api/outils/prerequis.php --paquet=<dossier décompressé>`.
3. Télécharger de nouveau l'archive depuis la source officielle.

Pour aller plus loin : Documentations/Manuel-Larka-SuperAdmin.docx, « Mises à jour de la plateforme ».

## CXC — Supervision : collecte

### CXC-001

**Collecte automatique arrêtée** — gravité *erreur*, à traiter par : *administrateur*.

Aucun passage de collecte depuis plusieurs minutes : les valeurs de la supervision ne se mettent plus à jour, les règles d'alarme ne sont plus évaluées.

**Causes probables**

- Tâche cron, minuterie systemd, start.sh ou Larka.cmd arrêté
- Passage bloqué (verrou resté posé après un arrêt brutal)

**Comment y remédier**

1. Voir l'état : `php api/outils/connect-collecte.php --etat`.
2. Relancer le déclencheur : `./start.sh autostart on` (Linux) ou Larka.cmd (Windows) ; en production, la tâche cron de deploy/install.sh.
3. Lancer un passage à la main pour voir l'erreur : `php api/outils/connect-collecte.php --forcer`.

Pour aller plus loin : Documentations/LARKA-CONNECT.md, § 5.

### CXC-002

**Connexion en défaut** — gravité *erreur*, à traiter par : *gestionnaire*.

L'équipement n'a donné aucune valeur à plusieurs relevés de suite. Une alarme de communication est ouverte ; les courbes ont un trou depuis.

**Causes probables**

- Équipement éteint, débranché ou redémarré
- Réseau ou pare-feu modifié
- Adresse, port ou identifiants changés sur l'équipement

**Comment y remédier**

1. Connexions → 🧪 Tester : le test dit à quelle étape cela bloque (réseau, protocole, droits) et donne le code précis.
2. Vérifier que l'équipement est allumé et sur le réseau.
3. Le code de l'étape en échec (CXR-…, CXP-…) donne la suite.

Pour aller plus loin : Documentations/LARKA-CONNECT.md, § 12.

### CXC-003

**Connexion partielle** — gravité *avertissement*, à traiter par : *gestionnaire*.

L'équipement répond, mais certains points ne sont pas lus. Les autres continuent normalement.

**Causes probables**

- Adresse d'un point inexistante dans l'équipement (registre, objet, champ)
- Point retiré ou renommé dans l'équipement
- Capteur ou compteur derrière la passerelle hors service

**Comment y remédier**

1. Onglet Points : l'erreur de chaque point est affichée ; corriger son adresse.
2. « 🔍 Découvrir » liste ce que l'équipement expose réellement.
3. Un point devenu inutile se désactive.

Pour aller plus loin : Documentations/LARKA-CONNECT.md, § 4.

### CXC-004

**Communication rétablie** — gravité *info*, à traiter par : *gestionnaire*.

L'équipement répond de nouveau. L'alarme de communication se ferme ; les relevés reprennent.

**Causes probables**

- Équipement rallumé, réseau rétabli, réglage corrigé

**Comment y remédier**

1. Rien à faire. La durée de la coupure est visible dans l'alarme terminée.

Pour aller plus loin : Documentations/LARKA-CONNECT.md, § 6.

### CXC-005

**Passage de collecte trop long** — gravité *avertissement*, à traiter par : *administrateur*.

Le temps alloué à un passage est épuisé : les connexions restantes sont relevées au passage suivant, une minute plus tard.

**Causes probables**

- Équipements lents ou injoignables qui attendent leur délai
- Écoutes radio longues (wM-Bus)
- Beaucoup de connexions au même rythme

**Comment y remédier**

1. Corriger d'abord les connexions en défaut : leur délai d'attente coûte le plus.
2. Espacer le rythme des connexions qui n'ont pas besoin d'être lues chaque minute.
3. Augmenter « Durée maximale d'un passage » (55 s au plus).

Pour aller plus loin : Documentations/LARKA-CONNECT.md, § 5.

### CXC-006

**Point illisible** — gravité *avertissement*, à traiter par : *gestionnaire*.

Un point n'a pas pu être lu ou sa valeur n'a pas pu être interprétée. Les autres points de la connexion ne sont pas touchés.

**Causes probables**

- Adresse incomplète ou fautive
- Valeur reçue d'un autre type que celui réglé (texte au lieu d'un nombre)
- Donnée absente de la réponse de l'équipement

**Comment y remédier**

1. Ouvrir le point (onglet Points) et vérifier son adresse.
2. « 🔍 Découvrir » pour reprendre l'adresse exacte.
3. Modbus : l'explorateur montre la valeur sous plusieurs interprétations.

Pour aller plus loin : Documentations/LARKA-CONNECT.md, § 4.

### CXC-007

**Larka Connect fermé** — gravité *info*, à traiter par : *administrateur*.

Le module de supervision est désactivé sur ce serveur ou pour cette organisation : rien n'est collecté.

**Causes probables**

- Réglage connect.actif à « non »
- Module fermé pour l'organisation par le Super Admin

**Comment y remédier**

1. Configuration → Serveur → Larka Connect : cocher « Larka Connect activé ».
2. Multi-organisation : Super Admin → modules → Larka Connect.

Pour aller plus loin : Documentations/LARKA-CONNECT.md, § 2.

## CXR — Supervision : réseau

### CXR-001

**L'équipement refuse la connexion** — gravité *erreur*, à traiter par : *gestionnaire*.

L'équipement est joignable, mais rien n'écoute sur le port demandé : il a répondu « refusé » immédiatement.

**Causes probables**

- Mauvais port (502 Modbus, 47808 BACnet, 1883 MQTT, 80/443 REST)
- Service de l'équipement arrêté ou désactivé dans ses réglages
- Nombre maximal de connexions simultanées atteint sur l'équipement

**Comment y remédier**

1. Vérifier le port dans la notice de l'équipement et dans la fiche de la connexion.
2. Vérifier que le service (Modbus TCP, API, broker) est activé sur l'équipement.
3. Redémarrer l'équipement si un autre logiciel l'interroge aussi et sature ses connexions.

Pour aller plus loin : Documentations/LARKA-CONNECT.md, § 10.

### CXR-002

**L'équipement ne répond pas (pare-feu probable)** — gravité *erreur*, à traiter par : *service informatique*.

Aucune réponse dans le délai : les paquets sont perdus en chemin. C'est la signature d'un pare-feu qui jette les paquets, ou d'un équipement éteint.

**Causes probables**

- Pare-feu entre le serveur Larka et l'équipement (VLAN GTB séparé)
- Équipement éteint ou adresse IP changée
- Mauvaise adresse IP

**Comment y remédier**

1. Vérifier que l'équipement est allumé et que son adresse IP n'a pas changé.
2. Demander au service informatique d'ouvrir le flux : serveur Larka → adresse de l'équipement, sur le port de la connexion. Le bouton « Copier le message » du test rédige la demande.
3. Depuis le serveur : `nc -vz <adresse> <port>` (TCP).

Pour aller plus loin : Documentations/LARKA-CONNECT.md, § 10.

### CXR-003

**Nom de l'équipement inconnu (DNS)** — gravité *erreur*, à traiter par : *service informatique*.

Le serveur ne sait pas traduire le nom saisi en adresse IP : il ne peut donc pas joindre l'équipement, même s'il est allumé.

**Causes probables**

- Faute de frappe dans le nom
- Nom connu seulement des postes de travail, pas du serveur
- DNS interne injoignable depuis le serveur

**Comment y remédier**

1. Saisir directement l'adresse IP de l'équipement.
2. Ou demander au service informatique de déclarer ce nom dans le DNS utilisé par le serveur.
3. Vérifier : `getent hosts <nom>` sur le serveur.

Pour aller plus loin : Documentations/LARKA-CONNECT.md, § 10.

### CXR-004

**Aucune route vers l'équipement** — gravité *erreur*, à traiter par : *service informatique*.

Le serveur n'a aucun chemin réseau vers cette adresse : elle est dans un réseau qu'il ne voit pas.

**Causes probables**

- Équipement dans un VLAN ou un site non routé vers le serveur
- Adresse d'un autre réseau saisie par erreur

**Comment y remédier**

1. Vérifier l'adresse saisie.
2. Demander au service informatique une route entre le réseau du serveur et celui de l'équipement.
3. Sinon, relever l'équipement par un PC releveur (Hors réseau).

Pour aller plus loin : Documentations/LARKA-CONNECT.md, § 10.

### CXR-005

**Adresse de l'équipement absente ou invalide** — gravité *erreur*, à traiter par : *gestionnaire*.

La fiche de la connexion n'a pas d'adresse, ou l'adresse n'a pas une forme valide.

**Causes probables**

- Champ adresse vide
- Adresse avec des espaces, un « http:// » de trop ou un port collé

**Comment y remédier**

1. Ouvrir la connexion et saisir l'adresse IP seule (ex. 10.0.12.20) ; le port a son propre champ.

Pour aller plus loin : Documentations/LARKA-CONNECT.md, § 4.

### CXR-006

**Certificat HTTPS refusé** — gravité *erreur*, à traiter par : *service informatique*.

L'équipement présente un certificat que le serveur ne peut pas vérifier. Par sécurité, la connexion est refusée.

**Causes probables**

- Certificat auto-signé ou d'une autorité interne inconnue du serveur
- Certificat expiré
- Nom du certificat différent de l'adresse saisie

**Comment y remédier**

1. Installer l'autorité interne sur le serveur (`/usr/local/share/ca-certificates`, puis `update-ca-certificates`).
2. Saisir l'adresse exactement comme dans le certificat (nom plutôt qu'IP).
3. En dernier recours, et sur un réseau interne seulement : désactiver la vérification du certificat dans la connexion.

Pour aller plus loin : Documentations/LARKA-CONNECT.md, § 10.

## CXP — Supervision : protocoles

### CXP-001

**Modbus : registre inexistant** — gravité *erreur*, à traiter par : *gestionnaire*.

L'équipement a répondu « adresse de registre invalide » (exception Modbus 0x02) : le registre demandé n'existe pas chez lui.

**Causes probables**

- Décalage d'adresse : la notice compte à partir de 1 (40001), Larka à partir de 0
- Mauvais type de registre (maintien / entrée)
- Plage regroupée qui traverse un trou de la table

**Comment y remédier**

1. Essayer l'adresse moins 1 (ou plus 1) : c'est la cause la plus fréquente.
2. Vérifier la fonction (03 maintien, 04 entrée) dans la notice.
3. « 🧭 Explorer » lit une plage brute pour repérer les registres présents.

Pour aller plus loin : Documentations/LARKA-CONNECT.md, § 12.

### CXP-002

**Modbus : l'équipement derrière la passerelle ne répond pas** — gravité *erreur*, à traiter par : *gestionnaire*.

La passerelle Modbus TCP répond, mais l'équipement visé derrière elle (bus RS-485) non (exception 0x0B ou 0x0A).

**Causes probables**

- Numéro d'esclave (unit id) inexistant
- Équipement du bus éteint, ou bus RS-485 coupé
- Vitesse ou parité du bus différente entre la passerelle et l'équipement

**Comment y remédier**

1. Vérifier l'unit id de l'équipement (souvent sur son afficheur).
2. Vérifier son alimentation et le câblage du bus (A/B, terminaison).
3. Comparer vitesse, parité et bits de stop dans la passerelle et l'équipement.

Pour aller plus loin : Documentations/LARKA-CONNECT.md, § 12.

### CXP-003

**Modbus : requête refusée par l'équipement** — gravité *erreur*, à traiter par : *gestionnaire*.

L'équipement a refusé la requête : fonction non gérée (0x01), quantité refusée (0x03), défaut interne (0x04) ou occupé (0x06).

**Causes probables**

- Fonction de lecture non prise en charge par ce modèle
- Trop de registres demandés d'un coup
- Équipement en défaut ou occupé

**Comment y remédier**

1. Changer la fonction du point (03 ↔ 04).
2. Réduire la plage (moins de points contigus).
3. Défaut interne : consulter l'afficheur ou la notice de l'équipement.

Pour aller plus loin : Documentations/LARKA-CONNECT.md, § 12.

### CXP-004

**BACnet : aucune réponse** — gravité *erreur*, à traiter par : *service informatique*.

L'automate ne répond pas en BACnet/IP (UDP 47808). En UDP, le silence ne distingue pas pare-feu, automate éteint et numéro d'automate faux.

**Causes probables**

- Flux UDP 47808 filtré entre le serveur et l'automate
- Numéro d'automate (device instance) faux
- Automate sur un autre réseau BACnet (BBMD nécessaire)

**Comment y remédier**

1. Vérifier le numéro d'automate : « 🔍 Découvrir » fait un Who-Is.
2. Demander l'ouverture de l'UDP 47808 dans les deux sens.
3. Si l'automate est sur un autre sous-réseau, déclarer le serveur dans son BBMD.

Pour aller plus loin : Documentations/LARKA-CONNECT.md, § 12.

### CXP-005

**BACnet : objet ou propriété inconnu** — gravité *erreur*, à traiter par : *gestionnaire*.

L'automate répond, mais l'objet demandé (type, instance) ou sa propriété n'existe pas.

**Causes probables**

- Objet supprimé ou renuméroté après une modification du programme de l'automate
- Type d'objet erroné (analog-input au lieu d'analog-value)

**Comment y remédier**

1. « 🔍 Découvrir » relit la liste des objets de l'automate ; reprendre l'adresse exacte.

Pour aller plus loin : Documentations/LARKA-CONNECT.md, § 4.

### CXP-006

**MQTT : connexion refusée par le broker** — gravité *erreur*, à traiter par : *gestionnaire*.

Le broker MQTT a refusé la connexion (identifiants, identifiant client ou autorisation).

**Causes probables**

- Identifiant ou mot de passe incorrect
- Identifiant client déjà utilisé par un autre programme
- Compte sans droit de connexion

**Comment y remédier**

1. Vérifier l'identifiant et le mot de passe dans la connexion (les ressaisir).
2. Laisser Larka choisir l'identifiant client (champ vide).
3. Vérifier les droits du compte dans le broker (ACL).

Pour aller plus loin : Documentations/LARKA-CONNECT.md, § 12.

### CXP-007

**MQTT : abonnement refusé** — gravité *erreur*, à traiter par : *gestionnaire*.

La connexion est acceptée, mais le broker refuse un ou plusieurs sujets suivis.

**Causes probables**

- Droits du compte limités à d'autres sujets (ACL)
- Sujet mal écrit (caractère + ou # mal placé)

**Comment y remédier**

1. Vérifier les sujets saisis (ex. `batiment/+/capteurs/#`).
2. Demander l'ouverture de ces sujets en lecture pour ce compte.

Pour aller plus loin : Documentations/LARKA-CONNECT.md, § 12.

### CXP-008

**Le port répond, mais pas avec le protocole attendu** — gravité *erreur*, à traiter par : *gestionnaire*.

Quelque chose écoute sur ce port, mais ne parle pas le protocole choisi (MQTT, Modbus, HTTP).

**Causes probables**

- Mauvais port (celui de l'interface web au lieu de celui du service)
- Protocole chiffré attendu (MQTT sur TLS 8883, HTTPS)

**Comment y remédier**

1. Vérifier le port dans la notice.
2. Cocher TLS si le service est chiffré.
3. « 🛰️ Détecter » identifie le protocole parlé sur chaque port.

Pour aller plus loin : Documentations/LARKA-CONNECT.md, § 4.

### CXP-009

**API REST : authentification refusée** — gravité *erreur*, à traiter par : *gestionnaire*.

L'API a répondu 401 ou 403 : le jeton ou le mot de passe n'est pas accepté, ou n'a pas les droits.

**Causes probables**

- Jeton expiré ou révoqué
- Jeton en lecture seule pour une écriture
- Mode d'authentification différent (Bearer, Basic, clé dans l'en-tête)

**Comment y remédier**

1. Générer un nouveau jeton dans le superviseur et le recopier dans la connexion.
2. Vérifier le mode d'authentification choisi.

Pour aller plus loin : Documentations/LARKA-CONNECT.md, § 4.

### CXP-010

**API REST : réponse d'erreur ou illisible** — gravité *erreur*, à traiter par : *gestionnaire*.

L'API a répondu une erreur (404, 422, 5xx) ou une réponse qui n'est pas du JSON.

**Causes probables**

- Chemin d'accès erroné (adresse de base, liste des points)
- Superviseur en maintenance ou surchargé (503)
- Page d'erreur HTML d'un proxy à la place de l'API

**Comment y remédier**

1. Vérifier l'adresse de base et les chemins dans la connexion.
2. Ouvrir l'adresse dans un navigateur depuis le réseau du serveur pour voir la réponse.
3. 5xx : patienter ; prévenir l'éditeur du superviseur si cela dure.

Pour aller plus loin : Documentations/LARKA-CONNECT.md, § 4.

### CXP-011

**CSV : dossier non autorisé** — gravité *erreur*, à traiter par : *super administrateur*.

Le dossier des exports CSV est hors des dossiers que Larka a le droit de lire (connect.csv_racines). Par sécurité, il n'est pas lu.

**Causes probables**

- Partage réseau monté ailleurs que sous data/connect/imports
- Chemin saisi avec une faute

**Comment y remédier**

1. Super administrateur : ajouter le dossier à `connect.csv_racines` dans config.json.
2. Ou monter le partage sous data/connect/imports.

Pour aller plus loin : Documentations/LARKA-CONNECT.md, § 10.

### CXP-012

**CSV : aucun fichier exploitable** — gravité *avertissement*, à traiter par : *gestionnaire*.

Aucun fichier ne correspond au modèle de nom, ou les fichiers trouvés n'ont pas les colonnes attendues.

**Causes probables**

- La GTB n'exporte plus (tâche d'export arrêtée)
- Modèle de nom différent (GTB_EXPORT_*.csv)
- Format des colonnes changé

**Comment y remédier**

1. Vérifier dans le dossier que des fichiers récents arrivent.
2. Ajuster le modèle de nom dans la connexion.
3. « 🔍 Découvrir » montre les colonnes du dernier fichier.

Pour aller plus loin : Documentations/LARKA-CONNECT.md, § 4.

## CXB — Bornes de recharge

### CXB-001

**Service OCPP arrêté** — gravité *erreur*, à traiter par : *administrateur*.

Le service permanent qui reçoit les bornes (et les passerelles LoRaWAN) ne tourne pas : elles ne peuvent pas se connecter.

**Causes probables**

- Service non démarré (start.sh, Larka.cmd, larka-ocpp.service)
- Port déjà pris par un autre programme
- Arrêt après une erreur

**Comment y remédier**

1. État : `php api/outils/connect-ocpp.php --etat`.
2. Production : `sudo systemctl restart larka-ocpp` puis `journalctl -u larka-ocpp -n 50`.
3. Vérifier qu'aucun autre programme n'écoute le port 9000 : `ss -ltnp | grep 9000`.

Pour aller plus loin : Documentations/LARKA-CONNECT.md, § 9.

### CXB-002

**Borne inconnue refusée** — gravité *avertissement*, à traiter par : *gestionnaire*.

Une borne s'est présentée avec un identifiant qui n'est déclaré dans aucune connexion. Elle a été refusée.

**Causes probables**

- Borne neuve pas encore déclarée
- Identifiant saisi différemment (majuscules, tiret)

**Comment y remédier**

1. Onglet Connexions : le bandeau des bornes inconnues propose « la déclarer ».
2. Recopier l'identifiant exactement comme dans la borne.

Pour aller plus loin : Documentations/LARKA-CONNECT.md, § 9.

### CXB-003

**Borne : mot de passe refusé** — gravité *avertissement*, à traiter par : *gestionnaire*.

La borne s'est présentée avec un mot de passe différent de celui réglé dans Larka (HTTP 401).

**Causes probables**

- Mot de passe changé d'un seul côté
- Borne réinitialisée à ses réglages d'usine

**Comment y remédier**

1. Saisir le même mot de passe dans la borne et dans sa fiche Larka.

Pour aller plus loin : Documentations/LARKA-CONNECT.md, § 9.

### CXB-004

**Borne déconnectée** — gravité *avertissement*, à traiter par : *gestionnaire*.

La borne ne parle plus à Larka depuis plus de trois battements de cœur.

**Causes probables**

- Coupure de réseau ou du modem 4G de la borne
- Borne hors tension
- Adresse du serveur modifiée dans la borne

**Comment y remédier**

1. Vérifier l'alimentation et le réseau de la borne.
2. Vérifier dans la borne l'adresse affichée dans sa fiche Larka.

Pour aller plus loin : Documentations/LARKA-CONNECT.md, § 9.

## CXL — Capteurs LoRaWAN

### CXL-001

**Aucune passerelle LoRaWAN entendue** — gravité *erreur*, à traiter par : *service informatique*.

Aucune passerelle n'a parlé à Larka depuis dix minutes : aucune trame de capteur ne peut arriver.

**Causes probables**

- Passerelle pas réglée en « Semtech UDP packet forwarder »
- Adresse ou port du serveur faux dans la passerelle
- UDP 1700 filtré par un pare-feu
- Passerelle sur Internet non autorisée (connect.lorawan.passerelles)

**Comment y remédier**

1. Dans la passerelle : mode Semtech UDP, serveur = adresse de Larka, ports montant et descendant 1700.
2. Demander l'ouverture de l'UDP 1700 vers le serveur (et le retour).
3. `php api/outils/connect-ocpp.php --etat` liste les passerelles vues.

Pour aller plus loin : Documentations/LARKA-CONNECT.md, § 9 bis.

### CXL-002

**Capteur LoRaWAN : clé refusée** — gravité *erreur*, à traiter par : *gestionnaire*.

La signature (MIC) de la trame ne correspond pas aux clés saisies pour ce capteur. La trame est ignorée.

**Causes probables**

- AppKey (OTAA) ou NwkSKey / AppSKey (ABP) différentes de celles du capteur
- Deux capteurs déclarés avec le même DevAddr (ABP)

**Comment y remédier**

1. Recopier les clés depuis la fiche du fournisseur (32 caractères hexadécimaux).
2. OTAA : redémarrer le capteur pour qu'il refasse son join.

Pour aller plus loin : Documentations/LARKA-CONNECT.md, § 9 bis.

### CXL-003

**Capteur LoRaWAN : trame rejouée** — gravité *avertissement*, à traiter par : *gestionnaire*.

Le compteur de trames du capteur est revenu en arrière. La trame est refusée : ce pourrait être un rejeu.

**Causes probables**

- Capteur ABP redémarré (il recompte depuis 0)
- Trame réellement rejouée par un tiers

**Comment y remédier**

1. Capteur ABP : cocher « Accepter un compteur remis à zéro » dans sa connexion.
2. Sinon, surveiller : un rejeu sans raison mérite une enquête.

Pour aller plus loin : Documentations/LARKA-CONNECT.md, § 9 bis.

### CXL-004

**Capteur LoRaWAN : activation refusée** — gravité *erreur*, à traiter par : *gestionnaire*.

La demande d'activation (join) du capteur n'a pas abouti : tant qu'il n'est pas activé, il n'envoie aucune mesure.

**Causes probables**

- AppKey fausse
- JoinEUI différent de celui saisi
- DevNonce déjà utilisé (rejeu ou capteur défectueux)
- Aucune passerelle capable d'émettre la réponse

**Comment y remédier**

1. Vérifier AppKey et JoinEUI (laisser JoinEUI vide pour l'accepter quel qu'il soit).
2. « 🧪 Tester » sur la connexion dit la raison exacte du dernier refus.
3. Vérifier que la passerelle envoie bien PULL_DATA (« peut répondre aux capteurs »).

Pour aller plus loin : Documentations/LARKA-CONNECT.md, § 9 bis.

### CXL-005

**Capteur LoRaWAN non déclaré** — gravité *info*, à traiter par : *gestionnaire*.

Un capteur inconnu cherche à rejoindre le réseau. Rien n'est enregistré tant qu'il n'est pas déclaré.

**Causes probables**

- Capteur neuf installé
- Capteur d'un voisin à portée d'une passerelle

**Comment y remédier**

1. Onglet Connexions : « le déclarer » (il faudra son AppKey).
2. Capteur étranger : ignorer, il ne peut rien injecter sans ses clés.

Pour aller plus loin : Documentations/LARKA-CONNECT.md, § 9 bis.

### CXL-006

**Capteur radio silencieux** — gravité *avertissement*, à traiter par : *gestionnaire*.

Aucune trame du capteur depuis plus longtemps que son délai de silence. Une alarme de communication est ouverte.

**Causes probables**

- Pile épuisée
- Capteur déplacé hors de portée
- Passerelle en panne

**Comment y remédier**

1. Vérifier la pile (point « batterie » s'il existe) et l'emplacement.
2. Vérifier que les passerelles sont entendues (bandeau de la fiche).
3. Allonger « Alarme si aucune trame depuis » si le capteur émet rarement.

Pour aller plus loin : Documentations/LARKA-CONNECT.md, § 9 bis.

## CXW — Compteurs radio wM-Bus

### CXW-001

**Aucun récepteur radio wM-Bus branché** — gravité *erreur*, à traiter par : *gestionnaire*.

Aucune clé USB réceptrice reconnue sur la machine qui écoute (serveur ou PC releveur).

**Causes probables**

- Clé débranchée
- Clé d'un modèle non reconnu automatiquement
- Pilote USB-série absent (rare sous Windows récent)

**Comment y remédier**

1. « Chercher le matériel » dans la fiche (ou `releveur materiel` sur le PC) liste les ports vus.
2. Choisir le port et le modèle à la main si la clé n'est pas reconnue.
3. Essayer une autre prise USB.

Pour aller plus loin : Documentations/LARKA-CONNECT.md, § 9 ter.

### CXW-002

**Port série : accès refusé** — gravité *erreur*, à traiter par : *administrateur*.

Le port de la clé existe, mais le compte du service n'a pas le droit de l'ouvrir, ou un autre programme l'occupe.

**Causes probables**

- Linux : utilisateur du service hors du groupe dialout
- Autre logiciel de relève ouvert sur le même port

**Comment y remédier**

1. Linux : `sudo usermod -aG dialout <utilisateur>`, puis redémarrer le service (PHP-FPM, larka-ocpp).
2. Fermer l'autre logiciel qui utilise la clé.

Pour aller plus loin : Documentations/LARKA-CONNECT.md, § 9 ter.

### CXW-003

**Compteur radio non entendu** — gravité *avertissement*, à traiter par : *gestionnaire*.

Le compteur n'a émis aucun télégramme pendant l'écoute de la clé radio : son index n'est pas mis à jour à ce relevé.

**Causes probables**

- Durée d'écoute plus courte que son intervalle d'émission
- Mode radio différent (T1 / C1)
- Compteur hors de portée (sous-sol, armoire métallique)

**Comment y remédier**

1. Allonger la durée d'écoute (jusqu'à 45 s).
2. Essayer l'autre mode radio.
3. Rapprocher la clé (rallonge USB) ou installer le PC releveur plus près.

Pour aller plus loin : Documentations/LARKA-CONNECT.md, § 9 ter.

### CXW-004

**Compteur radio chiffré : clé AES manquante** — gravité *avertissement*, à traiter par : *gestionnaire*.

Le compteur émet chiffré et sa clé n'est pas saisie : son index ne peut pas être lu.

**Causes probables**

- Clé fournie par le fabricant ou le distributeur d'eau non saisie

**Comment y remédier**

1. Demander la clé AES du compteur (fichier de livraison, distributeur).
2. La saisir dans la connexion : `identifiant=clé`.

Pour aller plus loin : Documentations/LARKA-CONNECT.md, § 9 ter.

### CXW-005

**Compteur radio chiffré : clé AES incorrecte** — gravité *erreur*, à traiter par : *gestionnaire*.

Le déchiffrement avec la clé saisie ne donne rien de lisible.

**Causes probables**

- Clé d'un autre compteur
- Faute de frappe dans la clé

**Comment y remédier**

1. Vérifier que l'identifiant avant le « = » est bien celui du compteur (8 chiffres).
2. Recopier la clé (32 caractères hexadécimaux).

Pour aller plus loin : Documentations/LARKA-CONNECT.md, § 9 ter.

### CXW-006

**Format radio non pris en charge** — gravité *avertissement*, à traiter par : *fournisseur*.

Le compteur utilise un chiffrement ou un format de télégramme que cette version de Larka ne lit pas (modes 7 ou 13, trames compactes, couche ELL).

**Causes probables**

- Compteur récent au profil OMS 4
- Format propre au fabricant

**Comment y remédier**

1. Demander au fabricant s'il peut émettre en mode 5 (OMS 3).
2. Signaler le modèle au support : il sera ajouté.

Pour aller plus loin : Documentations/LARKA-CONNECT.md, § 15.

## CXH — Appareils hors réseau

### CXH-001

**PC releveur : jeton refusé** — gravité *erreur*, à traiter par : *gestionnaire*.

Le collecteur d'un PC s'est présenté avec un jeton que Larka ne reconnaît plus.

**Causes probables**

- Collecteur retéléchargé (nouveau jeton) : l'ancien dossier sur le PC est périmé
- PC releveur supprimé ou désactivé dans Larka

**Comment y remédier**

1. Retélécharger le collecteur (Hors réseau → ⬇) et remplacer le dossier sur le PC.

Pour aller plus loin : Documentations/LARKA-CONNECT.md, § 4.

### CXH-002

**PC releveur : relevé refusé** — gravité *avertissement*, à traiter par : *gestionnaire*.

Un relevé envoyé par le PC a été écarté : trop ancien, mal daté, ou pour une connexion qui ne lui est plus confiée.

**Causes probables**

- Relevés restés plus de 90 jours sur le PC
- Horloge du PC très décalée
- Connexion retirée de la liste du PC

**Comment y remédier**

1. Vérifier les appareils confiés au PC (Hors réseau → ✎).
2. Mettre le PC à l'heure (synchronisation automatique).

Pour aller plus loin : Documentations/LARKA-CONNECT.md, § 4.

### CXH-003

**Hotspot de l'appareil introuvable** — gravité *avertissement*, à traiter par : *utilisateur*.

Le PC n'a pas trouvé (ou pas pu rejoindre) le Wi-Fi de l'appareil à relever.

**Causes probables**

- PC hors de portée
- Nom du Wi-Fi différent (majuscules comprises)
- Clé Wi-Fi changée sur l'appareil
- Appareil éteint

**Comment y remédier**

1. Se rapprocher de l'appareil et relancer le relevé.
2. Vérifier le nom exact : `netsh wlan show networks` (Windows).
3. Mettre à jour la clé Wi-Fi dans la connexion.

Pour aller plus loin : Documentations/LARKA-CONNECT.md, § 12.

### CXH-004

**PC releveur jamais vu** — gravité *info*, à traiter par : *gestionnaire*.

Le PC est déclaré, mais son collecteur n'a encore jamais contacté Larka.

**Causes probables**

- Collecteur pas encore installé ou lancé
- Larka injoignable depuis ce PC (adresse, certificat)

**Comment y remédier**

1. Sur le PC : double-cliquer releveur.cmd, puis `releveur.cmd etat`.
2. Certificat interne : `releveur.cmd confiance`.

Pour aller plus loin : Documentations/LARKA-CONNECT.md, § 4.

## CXA — Alarmes, relevés, écritures

### CXA-001

**Demande d'intervention automatique impossible** — gravité *erreur*, à traiter par : *administrateur*.

Une alarme devait créer une demande d'intervention et n'a pas pu. L'alarme, elle, est bien ouverte.

**Causes probables**

- Compte technique de Larka Connect supprimé ou modifié
- Champ obligatoire des demandes ajouté depuis (formulaire personnalisé)

**Comment y remédier**

1. Créer la demande à la main depuis l'alarme.
2. Vérifier les champs obligatoires des demandes (Configuration).
3. Transmettre le détail de l'entrée au support si la cause n'est pas claire.

Pour aller plus loin : Documentations/LARKA-CONNECT.md, § 6.

### CXA-002

**Écriture vers un équipement refusée** — gravité *avertissement*, à traiter par : *gestionnaire*.

La consigne ou la commande n'a pas été envoyée, ou l'équipement l'a refusée.

**Causes probables**

- Valeur hors de la plage permise du point
- Point non déclaré inscriptible
- Équipement qui refuse l'écriture (priorité BACnet, registre en lecture seule)

**Comment y remédier**

1. Vérifier la plage permise et l'option « écriture autorisée » du point.
2. BACnet : vérifier qu'une priorité plus forte ne commande pas déjà l'objet.
3. Le journal des écritures (onglet Journal) garde la réponse de l'équipement.

Pour aller plus loin : Documentations/LARKA-CONNECT.md, § 8.

### CXA-003

**Relevé automatique en anomalie** — gravité *avertissement*, à traiter par : *gestionnaire*.

L'index transmis au module Énergie paraît anormal (baisse, consommation hors norme). Il attend une validation.

**Causes probables**

- Compteur remplacé (index remis à zéro)
- Valeur aberrante lue ce jour-là
- Fuite ou consommation réellement exceptionnelle

**Comment y remédier**

1. Relevés automatiques → à valider : accepter, corriger ou rejeter.
2. Compteur remplacé : saisir le nouvel index de départ dans le module Énergie.

Pour aller plus loin : Documentations/LARKA-CONNECT.md, § 7.

### CXA-004

**Alarme technique** — gravité *avertissement*, à traiter par : *gestionnaire*.

Une règle d'alarme s'est déclenchée sur une valeur suivie, ou l'équipement a signalé un défaut.

**Causes probables**

- Valeur hors seuil (température, CO₂, pression…)
- Défaut remonté par l'équipement

**Comment y remédier**

1. Alarmes techniques : voir la valeur qui l'a déclenchée et l'équipement concerné.
2. Traiter sur place ou par la demande d'intervention créée, puis acquitter avec un commentaire.

Pour aller plus loin : Documentations/LARKA-CONNECT.md, § 6.

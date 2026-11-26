# Larka Connect — équipements du bâtiment raccordés à la GMAO

Larka Connect relie Larka aux équipements techniques du bâtiment : GTB/GTC, compteurs
d'énergie et d'eau, capteurs, régulation de chauffage et de climatisation, bornes de
recharge de véhicules électriques. Les valeurs remontent toutes seules dans Larka, où
elles nourrissent la supervision, les alarmes techniques, les demandes d'intervention et
les relevés du module Énergie.

Trois principes ne souffrent aucune exception :

1. **Usage interne.** Larka interroge des équipements du réseau de l'organisation ; rien
   n'est exposé vers l'extérieur. Seules les bornes de recharge ouvrent une connexion vers
   Larka (c'est ainsi que fonctionne OCPP), sur un port que l'on choisit.
2. **C'est le serveur qui interroge, jamais le navigateur.** Ouvrir un écran relit la base ;
   aucun utilisateur ne déclenche de trafic vers la GTB en consultant une page.
3. **Lire ne demande rien ; écrire demande une identité confirmée.** Changer une consigne
   ou commander une borne exige un gestionnaire qui confirme son identité, sur un point
   déclaré inscriptible, dans une plage permise — et laisse une trace.

---

## 1. Ce que Larka sait raccorder

| Protocole | Équipements typiques | Collecte | Découverte des points | Écriture |
| --- | --- | --- | --- | --- |
| **Modbus TCP** | compteurs, centrales de mesure, automates, passerelles Modbus | lecture des registres (fonctions 01 à 04), par plages groupées | non (le protocole ne liste rien) ; un **explorateur** lit une plage brute sous plusieurs interprétations | registres de maintien et bobines (06, 16, 05) |
| **BACnet/IP** | automates de GTB : chauffage, ventilation, climatisation, éclairage | ReadPropertyMultiple (ou lecture point par point) | oui (liste d'objets de l'automate, Who-Is pour trouver son n°) | WriteProperty avec priorité (8 par défaut), relâchement possible |
| **MQTT** (3.1.1, TLS possible) | capteurs radio LoRaWAN via un serveur réseau extérieur (sinon : serveur intégré, ci-dessous), passerelles IoT, compteurs communicants | abonnement aux sujets, session persistante (aucun message perdu entre deux passages) | oui (sujets publiés pendant l'écoute) | publication sur un sujet d'écriture, gabarit de message |
| **API REST (JSON)** | superviseurs de GTB récents, plateformes de télérelève, supervision de bornes | une requête pour tous les points, ou une par point | oui (liste des points de l'API) | PUT/POST/PATCH, corps paramétrable (`{valeur}`) |
| **Fichiers CSV** | anciennes GTB qui exportent dans un dossier (partage réseau, FTP synchronisé) | lecture des fichiers nouveaux, stables depuis quelques secondes | oui (colonnes et repères du dernier fichier) | — |
| **LoRaWAN (serveur intégré)** | capteurs radio : CO₂, température, humidité, présence, fuite, compteurs à impulsions (Elsys, Milesight, Dragino, Adeunis…) | les **passerelles** du bâtiment envoient leurs trames au service de Larka (Semtech UDP, port 1700) : ni serveur réseau, ni broker (§ 9 bis) | oui (champs de la dernière trame décodée) | — |
| **wM-Bus (clé USB)** | compteurs radio d'eau, de chaleur, de gaz (OMS, 868 MHz) | une **clé USB réceptrice** sur le serveur ou sur un PC releveur, écoutée quelques secondes à chaque relevé (§ 9 ter) | oui (compteurs entendus et leurs données) | — |
| **OCPP 1.6J** | bornes de recharge de véhicules électriques | la borne se connecte au **service OCPP** de Larka et envoie ses données | points créés d'eux-mêmes à la première connexion | commandes : démarrer/arrêter une charge, déverrouiller, mettre hors service, redémarrer |
| **Simulateur intégré** | aucun : Larka fabrique les valeurs (énergie, eau, chaleur, températures, CO₂, humidité, marche chauffage, consigne) | calcul, avec **historique instantané** (jusqu'à 400 jours) au premier passage | oui (toutes les grandeurs simulées) | consigne |

Modbus RTU (RS-485) et BACnet MS/TP passent par une passerelle IP, comme dans toute GTB.

---

## 2. Ouvrir le module

- **Installation simple** : *Configuration → 🖥️ Serveur → 📡 Larka Connect* → *Larka
  Connect activé*. Pour les bornes, cocher aussi *Service OCPP* (port 9000 par défaut).
- **Multi-organisation** : le Super Administrateur active Larka Connect dans *🖥️ Config
  serveur*, puis l'ouvre organisation par organisation (carte de l'organisation → 🧩
  modules → *Larka Connect*).
- Fermé, le module ne collecte rien. Les gestionnaires voient quand même ses trois écrans dans
  *Bâtiments*, grisés (🔒) : l'écran explique comment l'ouvrir et, en installation simple, propose
  le bouton *▶ Activer Larka Connect*. Les autres rôles ne les voient pas ; fermé pour une
  organisation par le Super Administrateur, le module n'apparaît pour personne.

| Rôle | Ce qu'il peut faire |
| --- | --- |
| Gestionnaire (et Admin) | tout : connexions, points, règles, relevés automatiques, bornes ; acquitter ; écrire (avec confirmation d'identité) |
| Visionneur, Technicien | supervision, courbes, alarmes, sessions de recharge — en lecture |
| Demandeur | rien (le module n'apparaît pas) |

---

## 3. Les écrans

Dans le menu de gauche, **🏢 Bâtiments** est une liste déroulante : un clic l'ouvre ou la ferme, et
elle contient *Plans* et les trois écrans de Larka Connect (*Supervision*, *Alarmes techniques*,
*Connexions*), en retrait dessous. Fermée, elle affiche encore le nombre d'alarmes actives ; l'état
est mémorisé. Ses entrées restent toujours ensemble, même avec un ordre de menu personnalisé.

**📡 Supervision** — en haut, **une carte par bâtiment** : connexions en service, en défaut,
partielles ou en attente, alarmes actives, bornes en charge, dernier échange, et un verdict
(*Tout va bien*, *À surveiller*, *À traiter*, *En attente*). Un clic sur la carte n'affiche plus que
ce bâtiment ; un bâtiment qui a un réseau mais rien de raccordé propose *🛰️ Détecter*. Dessous,
une carte par connexion : état (connectée, partielle, en défaut, en attente de données), dernière collecte et rythme, dernière valeur de chaque point. Une
valeur trop ancienne est grisée ; une valeur en alarme porte une cloche. Un clic ouvre la
**courbe** du point et la table des valeurs. L'écran relit la base toutes les 30 secondes.

- **Période** : 24 h, 7 jours, 30 jours, 1 an, ou *Choisir…* (deux dates et heures, trois ans au plus) ;
  ‹ et › reculent ou avancent d'une période entière (› s'arrête à maintenant).
- **Style** : *automatique* (escalier pour un état, une alarme ou une consigne ; consommation en barres
  pour un compteur ; ligne sinon), ligne, aire, barres, points, escalier, ou — compteurs seulement —
  *consommation* par heure, jour ou mois selon la durée affichée. Le choix est mémorisé par nature
  de point, dans le navigateur. Bande min–max quand les valeurs sont regroupées, trous de collecte
  laissés vides, réticule au survol et au clavier ; minimum, maximum, moyenne (ou consommation
  totale) sous la courbe.
- **📄 PDF** : la courbe affichée, sur une page A4 paysage avec ses chiffres. Le PDF est fabriqué
  dans le navigateur, sans bibliothèque ni service extérieur (`js/pdf-mini.js`).

- **Simple / Avancé** (mémorisé par navigateur) : le mode avancé ajoute, sur chaque point, la
  tendance des dernières 24 h (moyenne horaire ; consommation horaire en barres pour un compteur)
  et, au-dessus du tableau des consommations, les 12 derniers mois d'un compteur en graphique
  (mois partiel ou en cours en clair, valeur au survol et au clavier).
- **Filtres** : bâtiment, **type** d'énergie (électricité, gaz, eau, chauffage / froid, ambiance,
  autre — celui du compteur Énergie que le point alimente, sinon deviné d'après son unité et son
  nom), **nature** (compteurs, mesures, états et alarmes, consignes), **état** (en défaut, en
  alarme) et recherche. Une connexion sans point retenu disparaît ; *✕ Effacer les filtres*.
- **Consommations** : un tableau des compteurs (points de nature *compteur*) pour le mois choisi :
  index de début (dernière mesure avant le 1er à 0 h), index de fin (dernière mesure avant le 1er
  du mois suivant, ou la valeur actuelle pour le mois en cours), **conso du mois** — la soustraction
  d'un relevé manuel — et l'évolution par rapport au mois précédent (en gras au-delà de ± 20 %).
  Historique commencé en cours de mois : conso *partielle*. Index en baisse : pas de conso.
  La dernière colonne dit si le compteur alimente déjà le module Énergie (*Automatique*, *Importé*).
- **⬇ Importer dans Énergie** (gestionnaire), pour un compteur ou une sélection : chaque ligne
  propose le compteur Énergie (celui du relevé automatique, sinon celui du même type qui cite le
  bâtiment), un coefficient (Wh → kWh : 0,001, proposé d'après les unités), les index de début et
  de fin, la période et la date du relevé — **tout est modifiable**. Larka montre le dernier index
  du compteur Énergie et l'écart avec l'index de début (*Partir du dernier index du compteur*
  l'ajuste), et prévient si le compteur a déjà un relevé plus récent. Le relevé est écrit comme
  une saisie (type *Relevé*, montant au prix unitaire du compteur, CO₂ au facteur de l'année),
  avec son origine et le nom de qui l'a importé en commentaire. *Ensuite, importer automatiquement
  chaque mois* pose une liaison mensuelle (dernier jour du mois, 23 h 55, avec ou sans validation)
  — voir § 7.
- **📤 Exporter**, en Excel (.xlsx) ou en CSV (« ; », virgule décimale) : *valeurs actuelles* des
  points affichés, *consommations mensuelles* (le mois choisi ou les 12 derniers), ou *historique
  des mesures* des points affichés sur une période, brut ou regroupé par heure, jour ou mois
  (moyenne, minimum, maximum ; index de fin et consommation de la tranche pour un compteur),
  100 000 lignes au plus par export. *Courbes (PDF)* : cocher les points voulus (60 au plus, filtre
  et *tout / rien*), la période (dont le mois choisi, ou deux dates), le style, une ou deux courbes
  par page, et une page de synthèse en tête (min, max, moyenne ou consommation de chaque point).

**🔔 Alarmes techniques** — actives, terminées ou toutes, filtrables par gravité. Chaque
alarme indique son origine (règle, communication, équipement, relevé automatique), la
valeur qui l'a déclenchée et la demande d'intervention créée. *✓ Acquitter* (avec un
commentaire), seul ou en lot.

**🔌 Connexions** — huit onglets :

| Onglet | Rôle |
| --- | --- |
| Bâtiments | réseaux et mots-clés de chaque bâtiment, détection bâtiment par bâtiment |
| Connexions | raccorder, tester, découvrir, explorer (Modbus), lire maintenant |
| Points | les valeurs suivies : adresse dans l'équipement, coefficient, décalage, bande morte, rattachement à un équipement de l'inventaire, écriture autorisée et plage permise |
| Règles d'alarme | les conditions surveillées, et ce qu'elles déclenchent |
| Relevés automatiques | les liaisons vers les compteurs du module Énergie, et les relevés à valider |
| Bornes de recharge | adresse à saisir dans chaque borne, état, sessions, commandes |
| Hors réseau | appareils que le serveur ne joint pas (leur propre Wi-Fi, ou un câble en direct), et les PC qui les relèvent |
| Journal | écritures et commandes envoyées aux équipements ; fichiers CSV traités |

**Rangement par bâtiment.** La supervision, les alarmes et les onglets de *Connexions* rangent leurs lignes
(ou leurs cartes) sous le bâtiment de la connexion, par ordre alphabétique, *Sans bâtiment* en dernier.
Le titre d'un bâtiment donne le nombre de lignes, les connexions en défaut et les alarmes ; un clic le
replie ou le déplie (⊟ / ⊞ : tous à la fois). *🏢 Par bâtiment* revient à la liste à plat. Ces choix
sont gardés par navigateur. Le bâtiment d'une connexion se règle dans sa fiche (ceux des *Plans*).

---

## 4. Raccorder un équipement

1. *＋ Raccorder un équipement*, choisir le protocole.
2. Saisir l'adresse et les réglages ; les identifiants (mot de passe, jeton) sont
   **chiffrés en base** (AES-256-GCM, clé `config.key`) et ne sont jamais réaffichés.
3. **🧪 Tester la connexion** avant d'enregistrer. Le test ne garde rien ; il vérifie, dans
   l'ordre :
   - **le réseau et le pare-feu** — l'adresse se résout, le port répond, refuse
     (« rien n'écoute ») ou ne répond pas du tout (« un pare-feu jette les paquets ») ;
   - **le dialogue** — une vraie requête du protocole ;
   - **les droits** — authentification quand le protocole en a une ;
   - **les points** — lecture des points déjà déclarés.

   Quand une étape échoue côté réseau, Larka rédige le **message à transmettre au service
   informatique** : machine source, destination, port, protocole, sens du flux. Il suffit
   de le copier.
4. Choisir le rythme (*Interroger toutes les…*, de 10 secondes à 1 jour).
5. Ajouter les points : *🔍 Découvrir* les propose quand l'équipement sait les lister ;
   pour Modbus, la documentation de l'équipement donne les registres, et *🧭 Explorer*
   aide à reconnaître le bon type et le bon ordre des mots (un index « absurde » de
   plusieurs centaines de millions trahit un ordre CDAB).

### Détection automatique

*🛰️ Détecter les équipements* (en haut de l'écran *Connexions*, ou dans *Nouvelle connexion*) cherche,
en une passe et tous protocoles confondus, ce que Larka sait raccorder :

| Protocole | Comment Larka le reconnaît |
| --- | --- |
| Modbus TCP | port 502 ouvert, puis une vraie requête (lecture du registre 0, unit id 1 puis 255) ; fabricant et référence par l'identification Modbus (fonction 43) quand l'équipement la donne |
| BACnet/IP | Who-Is adressé à chaque machine et diffusé sur chaque sous-réseau ; nom, fabricant et modèle de chaque automate qui répond I-Am |
| MQTT | CONNECT / CONNACK sur 1883 et 8883 (TLS) : broker ouvert, ou protégé par un mot de passe |
| API REST | page d'accueil sur 80, 443, 8080, 8443 : titre, serveur, réponse JSON — un superviseur de GTB ressort comme *probable*, une simple interface web *à vérifier* (non cochée) |
| CSV | dossiers des racines autorisées qui contiennent des exports |
| OCPP | bornes inconnues qui ont tenté de se connecter |

- **Adresses** : une plage (`192.168.20.0/24`), un intervalle (`10.0.5.10-60`), des adresses ou des noms.
  Les réseaux du serveur sont proposés. Ports particuliers possibles (passerelle Modbus sur 503…) : jusqu'à 8 par protocole sur une grande plage, jusqu'à 64 sur quelques adresses (plusieurs équipements derrière une même adresse).
- **Garde-fous** : adresses **internes seulement** (privées, locales, bouclage — jamais Internet),
  1 024 au plus (un /22), réservé aux gestionnaires, 10 détections par quart d'heure et par compte.
  Les sondes ne font que lire : aucune écriture, aucun mot de passe essayé. Un /24 complet se parcourt
  en une demi-minute environ ; budget total réglable (`connect.detection_budget_secondes`, 90 s).
- **Où chercher** : un bâtiment (ses réseaux), *tous les bâtiments* (les réseaux de chacun, 4 096
  adresses au plus, budget porté jusqu'à 3 minutes) ou *autres adresses* (une plage saisie).
- **Résultat** : ce qui est déjà raccordé est signalé ; le bâtiment de chaque équipement est proposé,
  avec la raison (réseau du bâtiment, réseau appris, nom, bâtiment parcouru). On coche, on renomme, on choisit le bâtiment, puis
  *Raccorder la sélection* crée les connexions (même validation qu'une saisie) et, si on le souhaite,
  ajoute aussitôt les points des équipements qui savent les lister. Chaque détection est journalisée.
- **Écartés d'office** : ce qui répond sans être un équipement technique est rangé, replié, sous la
  liste. Larka sonde les ports caractéristiques des machines qui ont répondu (135/139/445/3389 :
  poste Windows ; 9100/631/515 : imprimante ; 548 : Mac ; 62078 : téléphone) et lit la page d'accueil
  (serveur, titre, page d'authentification : imprimante, NAS, box ou point d'accès, caméra). Une
  interface web d'une telle machine, ou une simple page sans API, est écartée. **Un vrai protocole
  technique (Modbus, BACnet, MQTT) n'est jamais écarté**, même sur un PC Windows : il est proposé avec
  la mention *« Sur un PC Windows : un PC relais (passerelle logicielle) ? »*, car un superviseur ou
  une passerelle logicielle tourne souvent sur un PC. *Proposer quand même* remet un écarté dans la
  liste, non coché.
- **Exclusions** (table `ConnectExclusions`, route `connect_exclusions`) : *🚫 Ignorer* (un appareil :
  ce protocole à cette adresse ; ou toute la machine) et *Adresses à ne jamais parcourir* (plage des
  postes utilisateurs, téléphonie, Wi-Fi invités). Les deux se retrouvent dans le panneau *Exclusions*
  de la détection, et se retirent d'un clic.

### Bâtiments et réseaux

Onglet **🏢 Bâtiments** de *Connexions* (`api/connect/Batiments.php`, table `ConnectBatiments`) :
pour chaque bâtiment des plans, ses **réseaux** — plages, intervalles ou adresses internes — et des
**mots-clés** (sigle, ancien nom, repère des bornes). Le bâtiment d'un équipement se devine dans cet
ordre :

1. son adresse est dans un réseau **déclaré** (le plus précis l'emporte ; deux bâtiments à égalité : rien) ;
2. son adresse est dans un réseau **appris** — un /24 dont toutes les connexions déjà rangées vont au
   même bâtiment, et que les réseaux déclarés ne couvrent pas (« garder » l'inscrit) ;
3. son **nom** (nom annoncé, emplacement BACnet, identifiant de borne, dossier d'exports) évoque un seul
   bâtiment : son nom complet ou un mot-clé, son sigle (« Hôtel de Ville » → HDV), un mot de son nom
   propre à lui seul, ou le début propre d'un tel mot (« GYM » → gymnase). Dans le doute, rien.

*Voir et ranger* range d'un coup les connexions sans bâtiment dont le bâtiment se devine.
Routes : `connect_batiments` (GET), `connect_batiment` (réseaux et mots-clés d'un bâtiment),
`connect_batiments_ranger`, et `connect_detecter` avec `batiment` = Id ou `tous`.

### Appareils hors réseau : hotspot Wi-Fi ou câble direct (relevés par un PC)

Certains appareils (enregistreur, station météo, centrale de mesure, compteur autonome) ne sont pas
sur le réseau : ils diffusent **leur propre Wi-Fi**, ou l'on s'y branche **par un câble RJ45**. Le
serveur ne peut pas les joindre ; un **PC releveur** le fait pour lui, hors ligne :

1. Onglet **📶 Hors réseau** : *＋ Déclarer un appareil hors réseau* — « Il diffuse son propre
   Wi-Fi » ou « On branche un PC dessus, par câble », puis le protocole (Modbus, BACnet, MQTT ou API
   REST). Une connexion existante y passe aussi : ✏️, section **Comment Larka joint-il
   l'appareil ?** (en tête de la fiche). Hotspot : nom du Wi-Fi (SSID) et clé (WPA2, 8 à 63
   caractères ; chiffrée comme les autres secrets). L'adresse est celle de l'appareil, sur son Wi-Fi
   ou au bout du câble. Le serveur ne la teste, ne la découvre et ne l'interroge plus : ses points se
   saisissent d'après la documentation — ou, sans documentation, choisissez le protocole
   **🔎 À détecter** : au relevé suivant, le PC releveur regarde ce que l'appareil expose (ports, pages
   web et adresses JSON que leurs scripts interrogent, registres Modbus, automate BACnet/IP et tous ses
   points avec leur nom et leur unité ; lecture seule) et l'envoie.
   🔎 dans la liste montre les réglages proposés et tous les points lus (nom, nature, unité, valeur ;
   totaux des trois phases cochés d'office) : on coche, on corrige, **Appliquer**. Les adresses des
   points viennent du rapport, jamais du navigateur. Route `connect_reconnaissance` (GET ; POST
   `demander`, `appliquer`), dépôt du PC `op=reconnaissance`.
2. Même onglet : *＋ Ajouter un PC releveur* (les appareils qu'il relève ; aucun coché =
   tous, y compris ceux déclarés plus tard), puis **⬇️** télécharge son **collecteur**, déjà réglé :
   un zip avec `releveur.cmd` (Windows), `releveur.sh` (Linux), `releveur.php`, les pilotes de Larka
   Connect et `config.json` (adresse de Larka, jeton). Chaque téléchargement crée un **nouveau jeton**
   (seule son empreinte SHA-256 est gardée) : l'ancien collecteur cesse de fonctionner.
3. Sur le PC, **une seule fois** : décompresser, double-cliquer `releveur.cmd`. Le collecteur
   installe au besoin PHP à côté de lui et crée un profil Wi-Fi par hotspot (connexion manuelle :
   Windows ne s'y connecte jamais de lui-même), puis fait un premier relevé. Aucun droit
   d'administrateur n'est demandé, sauf si Windows doit installer le runtime Visual C++ qu'exige PHP.
4. Ensuite, **pour relever** : double-cliquer `releveur.cmd`. **Rien ne se lance tout seul** : pas
   de tâche planifiée, pas de crontab, rien en arrière-plan. Les tâches « Larka releveur… » posées
   par une version précédente (1.1 et avant) sont supprimées au premier lancement du collecteur 1.2
   (une fois par PC), ou par `releveur.cmd retirer`.

À chaque relevé, le collecteur : demande sa mission à Larka (connexions, points, Wi-Fi) ; se connecte
au hotspot ; lit les points avec les **mêmes pilotes** que le serveur ; revient au Wi-Fi d'origine ;
envoie les valeurs **avec leur heure réelle**. Retour au Wi-Fi d'origine : le profil identifié avant de
partir — sans dépendre de la localisation de Windows, souvent coupée sur les PC d'entreprise (profil et nom
du Wi-Fi donnés à PowerShell, sinon journal Wi-Fi de Windows ; profils de stratégie de groupe compris) —,
sinon le PC quitte le hotspot et Windows rejoint son réseau préféré, puis les autres profils du PC. Chaque
essai est noté dans `releveur.log`. Larka note la version du collecteur de chaque PC (⬇️ quand une plus
récente existe). Un relevé automatique de fréquence **« À chaque relevé du PC releveur »**
écrit, à chaque dépôt, le relevé du compteur du module Énergie (heure réelle de la lecture). Les appareils **en direct** sont relevés sans rien
changer au réseau du PC : s'ils répondent, ils sont lus, sinon ils sont passés en silence (« pas
branché », ni erreur ni alarme) : on branche le câble, puis on double-clique `releveur.cmd`.

> **Câble direct et adresse du PC.** Pour joindre l'appareil, le PC doit avoir une adresse dans son
> réseau. Une fois, sur la carte Ethernet (droits d'administrateur) : *Protocole Internet version 4 →
> Configuration alternative* → une adresse du même réseau (la fiche de l'appareil la propose, par
> exemple `192.168.1.250` pour un appareil en `192.168.1.10`), masque `255.255.255.0`, **passerelle
> vide**. Sur le réseau habituel (DHCP), rien ne change ; branché sur l'appareil, le PC prend cette
> adresse et, sans passerelle, garde Internet par le Wi-Fi. Si le PC n'a pas d'adresse dans ce
> réseau, le collecteur le dit et donne l'adresse à saisir. Sans Internet à ce moment, il les garde
(`en-attente.json`, 85 jours au plus) et les envoie au relevé suivant. Si le PC a **une carte libre**
(seconde carte Wi-Fi, ou PC en Ethernet dont la carte Wi-Fi ne sert pas), il relève sur celle-ci sans
rien couper ; sinon il quitte son Wi-Fi environ une minute (sous Linux, la connexion au hotspot ne
prend jamais la route par défaut).

```text
releveur.cmd                     double-clic : configurer la première fois, ensuite relever maintenant
releveur.cmd configurer          refaire le réglage (PHP, profils Wi-Fi, certificat)
releveur.cmd collecter --bavard  relever maintenant, en détaillant
releveur.cmd tester              un relevé complet, valeurs affichées, rien n'envoyé
releveur.cmd explorer            appareil sans documentation : ports ouverts, pages web et adresses JSON qu'elles
                                 interrogent, registres Modbus proches de 230 V / 50 Hz — lecture seule, rapport
                                 exploration-<appareil>.txt (--appareil=nom, --hote=IP)
releveur.cmd etat                réglages, relevés en attente, ce que le PC voit du Wi-Fi (réseau repris après un
                                 relevé et d'où vient ce nom, profils du PC), détections en attente
releveur.cmd retirer             enlève les profils Wi-Fi (et les tâches d'une ancienne version)
--sans-wifi                      ne change pas de Wi-Fi (l'appareil est déjà joignable : essais, simulateur)
```

Côté serveur : `api/connect/Releveur.php` (et, sous `api/connect/releveur/`, les sources du
collecteur que Larka assemble à chaque téléchargement — livrées avec `api/`, donc présentes en
production, mais jamais servies telles quelles), route publique `connect_releveur` (jeton dans l'en-tête
`X-Larka-Releveur` ; `op=mission` en GET, `op=depot` en POST ; 20 refus par quart d'heure et par
adresse au plus), et pour l'écran `connect_releveurs`, `connect_releveur_enregistrer`,
`connect_releveur_supprimer`, `connect_releveur_paquet`. Un dépôt n'accepte que les connexions
confiées au PC, 500 relevés au plus, datés de moins de 90 jours (une heure en avance est ramenée à
l'heure du serveur). Un relevé plus ancien que la dernière valeur du point (file d'attente envoyée
après un autre PC) va dans l'historique à son heure, sans changer la dernière valeur ; reçu deux
fois, il n'est pas doublé. Trois relevés de suite en échec (hotspot hors de portée, appareil éteint,
clé changée) ouvrent une alarme de connexion, comme pour une connexion interrogée par le serveur ;
le relevé réussi suivant la termine.

**Sécurité du circuit.**

- *Le jeton* : 192 bits aléatoires ; Larka n'en garde que l'empreinte SHA-256. Il n'ouvre que la
  mission et le dépôt des appareils confiés à CE PC — rien d'autre dans Larka. Retélécharger le
  collecteur ou supprimer le PC le révoque aussitôt.
- *Télécharger le collecteur* demande de confirmer son identité (mot de passe, comme pour agir sur
  un équipement), car il donne accès aux clés Wi-Fi et aux identifiants des appareils confiés ;
  chaque téléchargement est tracé au journal de sécurité (`connect_releveur_paquet`).
- *Route publique* `connect_releveur` : 20 jetons refusés par quart d'heure et par adresse — la
  limite est vérifiée avant l'essai, un jeton juste attend aussi —, refus tracés
  (`connect_releveur_refus`) ; 60 appels par quart d'heure pour un jeton valide (un relevé en fait
  quelques-uns), pour qu'un jeton volé ne puisse pas noyer l'historique.
- *Adresse de l'appareil* (hotspot ou câble) : IP privée ou de lien local seulement (192.168.4.1, 10.0.0.1…), jamais un
  nom ni une adresse publique, vérifiée à la saisie et par le collecteur — il ne sert à joindre rien
  d'autre que l'appareil (bouclage 127.x accepté en essai, avec `--sans-wifi`).
- *Transport* : servir Larka en **https** (l'onglet Hors réseau prévient s'il est ouvert en http) ; le
  collecteur vérifie le certificat et ne suit jamais une redirection (le jeton ne part pas ailleurs).
  Réseau interne, certificat **auto-signé ou d'une autorité interne** (cadenas barré dans le
  navigateur) : au téléchargement, Larka se joint lui-même et inscrit l'empreinte SHA-256 de son
  certificat dans le collecteur, qui l'**épingle** — il accepte ce certificat-là et lui seul, sans
  jamais renoncer à la vérification. Sinon, ou après un renouvellement, `releveur.cmd confiance`
  montre le certificat et son empreinte (à comparer avec le navigateur) avant de l'épingler ; le
  premier réglage (double-clic) le propose de lui-même. Un autre certificat est refusé.
- *Sur le PC* : `config.json` et `mission.json` (clés Wi-Fi) lisibles par le seul compte sous Linux ;
  sous Windows, décompresser dans le dossier de l'utilisateur, jamais dans un dossier partagé
  (`releveur.cmd` exécute ce qu'il contient). Les profils Wi-Fi sont en connexion manuelle et suivent
  la mission : appareil ajouté, clé changée ou appareil retiré, le collecteur les refait ou les retire.

> **Pourquoi pas les deux Wi-Fi à la fois ?** Une carte Wi-Fi ne se connecte qu'à un réseau à la
> fois. Rester sur le Wi-Fi principal pendant le relevé demande une seconde carte (une clé USB Wi-Fi
> suffit) ou le câble Ethernet ; le collecteur s'en sert alors d'office.

### Explications dans les écrans

- Chaque écran (Supervision, Alarmes techniques, et chaque onglet de Connexions) commence par un
  encadré **À quoi sert cet écran ?** : à quoi ça sert, quand s'en servir, un exemple, et un lien vers
  la fiche de l'aide intégrée. *Compris, replier* le réduit à un lien (mémorisé dans le navigateur).
- Les réglages portent une bulle **?** ; les boutons d'action (🧪 🔍 🧭 ↻ ⚡ ✏️ 🗑️…) une bulle au
  survol ou au focus qui dit ce qu'ils font et ce qu'ils ne font pas.
- Le **🧭 Guide de démarrage**, en tête de *Connexions*, coche les étapes d'après l'état réel
  (`connect_parcours` : bâtiments, réseaux, connexions, défauts, points, règles, relevés, bornes) et
  propose toujours la suivante. Il se masque et se rouvre par le bouton *🧭 Guide*.

---

## 5. La collecte : qui interroge, et quand

Chaque passage ne traite que les connexions **dues** (selon leur rythme), dans un budget
de temps (50 s par défaut) ; ce qui n'a pas pu être fait passe au tour suivant. Un verrou
empêche deux passages simultanés.

| Installation | Collecte | Service OCPP | Journaux |
| --- | --- | --- | --- |
| Production (`deploy/install.sh`) | tâche cron chaque minute (`/etc/cron.d/gmao`) | `larka-ocpp.service` | `data/logs/connect.log`, `data/logs/connect-ocpp.log` |
| Linux autonome, démarrage automatique (`./start.sh autostart on`) | minuterie `larka-connect.timer` (chaque minute) | `larka-ocpp.service`, lié à Larka | `journalctl -u larka-connect -u larka-ocpp` |
| Linux autonome (`./start.sh start`) | planificateur de start.sh (toutes les 30 s) | lancé par le même planificateur s'il ne tourne pas | `data/logs/connect.log`, `data/logs/connect-ocpp.log` |
| Windows (`Larka.cmd`) | le lanceur, chaque minute, tant que Larka tourne | lancé et relancé par le lanceur | `data\logs\connect.log`, `data\logs\connect-ocpp.log` |

Filet de sécurité : si aucun de ces déclencheurs n'est passé depuis 2 minutes, une
requête de l'application lance un passage après avoir rendu sa réponse (PHP-FPM).

Le service OCPP relit `config.json` toutes les 10 secondes : activer ou désactiver OCPP
dans la configuration suffit, sans redémarrage.

```bash
php api/outils/connect-collecte.php --etat      # dernier passage, état de chaque connexion, alarmes
php api/outils/connect-collecte.php --forcer    # toutes les connexions, maintenant
php api/outils/connect-collecte.php --organisation=CLE --connexion=ID
php api/outils/connect-ocpp.php --etat          # écoute, bornes connectées, bornes inconnues refusées
```

Les mesures sont historisées quand elles bougent (au-delà de la bande morte du point), et
au moins toutes les 15 minutes quand elles sont stables, pour des courbes continues. Une
fois par jour, l'historique plus ancien que la rétention (400 jours par défaut) est purgé ;
les relevés écrits dans le module Énergie, eux, restent.

---

## 6. Alarmes techniques

Une **règle** surveille un point :

| Condition | Exemple |
| --- | --- |
| dépasse un seuil / passe sous un seuil | surchauffe > 27 °C, pression < 1 bar ; retour à la normale réglable (hystérésis) |
| sort d'une plage | humidité hors 30–70 % |
| prend / quitte une valeur, un état | défaut = 1, état ≠ « Marche » |
| capteur en défaut | qualité mauvaise remontée par l'équipement |
| plus aucune valeur reçue | capteur muet depuis 60 min (pile, radio, panne) |
| franchit un palier | toutes les 2 000 h de marche : maintenance conditionnelle |

*Pendant au moins (minutes)* évite les alarmes pour un pic passager. Quand l'alarme se
déclenche, elle peut **prévenir les gestionnaires** (notification push) et **créer une
demande d'intervention** sur l'équipement rattaché au point ; la gravité fixe l'urgence
(critique → Urgente, majeure → Haute, mineure → Normale). Une alarme déjà ouverte ne crée
pas de seconde demande ; elle se termine d'elle-même quand la condition cesse.

Larka ouvre aussi des alarmes sans règle : connexion injoignable, défaut d'une borne,
alarmes remontées par un superviseur REST, relevé automatique manquant.

---

## 7. Relevés automatiques vers le module Énergie

« Le relevé d'aujourd'hui va ici » : une **liaison** recopie, à heure fixe, la valeur d'un
point dans un compteur du module Énergie — exactement comme une saisie manuelle.

- **Quand** : chaque jour, certains jours de la semaine, une fois par mois (le 1er… ou le
  dernier jour), seulement certains mois (« du lundi au vendredi, d'octobre à avril »), ou
  une expression cron. Les heures sont des heures **légales** : 23 h 55 reste 23 h 55
  après le changement d'heure.
- **Tolérance** : la valeur la plus proche de l'heure prévue, à ± N minutes. Sans valeur :
  relevé *manquant* et alarme mineure.
- **Contrôles** : un index qui baisse, ou une consommation de plus de N fois la médiane
  des derniers relevés, met le relevé **en anomalie** — rien n'est écrit tant qu'un
  gestionnaire n'a pas validé (valeur corrigée possible ; un compteur remplacé devient le
  nouveau point de départ) ou rejeté.
- **Validation manuelle** (facultative) : chaque relevé attend un gestionnaire.
- Valider le relevé le plus récent clôt les plus anciens du même relevé encore en attente,
  sans les écrire ; *Tout écrire* les écrit tous, du plus ancien au plus récent, et
  s'arrête sur une anomalie.
- Un relevé manqué pendant un arrêt du serveur est **rattrapé** au passage suivant, avec
  la mesure de l'heure prévue.

---

## 8. Écrire vers un équipement

| Garde | Effet |
| --- | --- |
| Rôle | Gestionnaire ou Admin |
| Point | déclaré **inscriptible**, avec un minimum et un maximum permis |
| Identité | mot de passe redemandé : Larka (compte local), ou Microsoft / Google dans leur fenêtre (même compte que la session ; Microsoft : `prompt=login`, heure d'authentification vérifiée si l'application la reçoit ; Google : `max_age=0` et heure d'authentification exigée — option « Session age claims » du projet Google à activer) ; valable 5 min par défaut, réglable par le gestionnaire de 5 min à 8 h (*Connexions → Serveur de supervision → Confirmation d'identité*) ; 5 essais faux en 10 minutes bloquent |
| Plage | hors plage : refus (HTTP 422), rien n'est envoyé |
| Trace | chaque tentative, réussie ou refusée, va au journal de sécurité (`data/security/audit.log` : compte, point, valeur précédente, résultat) et dans l'onglet *Journal* |

BACnet n'a aucune authentification : la confirmation d'identité de Larka est la seule
barrière. *Relâcher la commande* rend la main à la programmation horaire de la GTB.

---

## 9. Bornes de recharge (OCPP 1.6J)

- Adresse à saisir dans la borne : `ws://<serveur>:9000/ocpp/<identifiant>` —
  `/ocpp/<organisation>/<identifiant>` en multi-organisation. Larka l'affiche dans la fiche
  de chaque borne.
- **Chiffré (recommandé)** : service OCPP sur `127.0.0.1`, Nginx en frontal
  (`location ^~ /ocpp/` dans `deploy/nginx.conf`) et *Adresse publique*
  `wss://gmao.example.com/ocpp` dans la configuration.
- **Mot de passe** (OCPP profil 1, authentification Basic) : recommandé, réglé par borne.
- **Badges** : tous, ou seulement une liste (`04A2B3C4;Agent technique`, un par ligne).
- Une borne non déclarée est refusée et listée dans l'onglet *Connexions*, avec un lien
  *la déclarer*.
- **Sessions** : énergie, durée, puissance maximale, badge ; une fin de charge reçue en
  différé après une coupure réseau est rangée à son heure réelle ; une session dont la fin
  n'est jamais arrivée est marquée *interrompue*.
- **Défaut** de la borne : alarme (et demande d'intervention si réglé), close quand la
  borne redevient disponible.

---

## 9 bis. Capteurs radio LoRaWAN (serveur intégré)

Jusqu'ici, un capteur LoRaWAN passait par un serveur réseau (ChirpStack, TTN, Live Objects…) puis par
MQTT. Ce n'est plus nécessaire : Larka **est** le serveur réseau des passerelles du bâtiment.

1. **Configuration → Serveur → Larka Connect → LoRaWAN** : *Serveur LoRaWAN intégré* (port UDP 1700
   par défaut). Le service permanent de Larka Connect — le même que l'OCPP, lancé par `start.sh`, le
   lanceur Windows ou `larka-ocpp.service` — relit ce réglage tout seul.
2. Dans **chaque passerelle** (Milesight, Kerlink, Dragino, RAK, MultiTech…) : mode *Semtech UDP packet
   forwarder* (« Legacy UDP »), adresse du serveur Larka, ports montant et descendant 1700, plan EU868.
   La fiche d'un capteur rappelle ce réglage et liste les passerelles entendues.
3. **Nouvelle connexion → LoRaWAN** pour chaque capteur : DevEUI, activation **OTAA** (AppKey, JoinEUI
   facultatif) ou **ABP** (DevAddr, NwkSKey, AppSKey), et le **décodeur** de sa charge utile :
   Cayenne LPP, Elsys, Milesight, ou *octets bruts* (position, type et ordre des octets, point par point).
4. *Découvrir* propose les champs de la dernière trame (`temperature_1`, `co2`…) et la réception radio
   (`rssi`) ; *Tester* dit si le service tourne, quelles passerelles parlent, si le capteur s'est activé,
   sa dernière trame (RSSI, SNR, facteur d'étalement) et pourquoi une trame a été refusée.

Ce que fait le service : il répond aux passerelles, attend 200 ms qu'une trame entendue par plusieurs
passerelles arrive par toutes et garde la meilleure réception, **vérifie le MIC** avec les clés du capteur,
refuse un compteur de trames déjà vu (rejeu) et un DevNonce déjà utilisé, répond au join (DevAddr attribué
dans le réseau privé, NetID 000000) et accuse les trames *confirmées* (RX1, ou RX2 en retard). Les valeurs
suivent ensuite le chemin de toute collecte : mise à l'échelle, historique, règles d'alarme, relevés
automatiques. Un capteur silencieux plus longtemps que son délai (*Alarme si aucune trame depuis*, 180 min
par défaut) lève une alarme de communication.

Un capteur **non déclaré** qui tente de rejoindre le réseau est signalé dans l'onglet *Connexions*, avec un
lien *le déclarer* (il faudra son AppKey). Un capteur ABP qui redémarre recompte depuis 0 : accepté tant que
*Accepter un compteur remis à zéro* est coché.

**Sécurité.** Une trame dont le MIC ne correspond à aucun capteur déclaré est ignorée : sans les clés, rien
ne s'injecte. Les passerelles d'un réseau privé sont écoutées d'office ; une passerelle jointe par Internet
ne l'est que si son EUI figure dans `connect.lorawan.passerelles` (`config.json`, réglage réservé au Super
Admin). Les clés de session sont chiffrées en base ; aucune clé ne figure dans l'état du service
(`data/run/connect-lorawan.json`).

`php api/outils/connect-ocpp.php --etat` : passerelles vues, capteurs, trames refusées, capteurs inconnus.

## 9 ter. Compteurs radio wM-Bus (clé USB)

Les compteurs d'eau, de chaleur et de gaz récents émettent leur index par radio (Wireless M-Bus,
EN 13757-4, profil OMS). Une **clé USB réceptrice** les entend : **IMST iM871A-USB**, **Amber AMB8465-M**
ou **CUL** (culfw). Aucun pilote ni programme à installer : Linux et macOS règlent le port par `stty`,
Windows le lit par PowerShell.

- **Nouvelle connexion → Compteurs radio wM-Bus** : *La clé est branchée sur* le serveur, ou sur un PC
  releveur (près des compteurs : il écoute à chacun de ses passages, comme un appareil en câble direct,
  et envoie les index à Larka). Port **automatique** (conseillé), mode radio **T1** (le plus courant) ou
  C1, durée d'écoute (30 s par défaut, au moins l'intervalle d'émission des compteurs).
- **Clés AES** des compteurs chiffrés, une par compteur : `12345678=000102…0F, 87654321=…` (chiffrées
  sur le serveur, jamais réaffichées). Un compteur en clair n'en a pas besoin. Chiffrement OMS mode 5.
- *Chercher le matériel* (dans la fiche) liste les ports série du serveur et reconnaît les clés par leur
  identifiant USB ; il signale aussi ce qui ne convient pas (clé RTL-SDR, concentrateur LoRa à faire
  tourner en passerelle vers Larka:1700). Sur un PC releveur : `releveur.cmd materiel` ; la liste part
  aussi avec les relevés et s'affiche dans l'onglet *Hors réseau*.
- *Découvrir* écoute la radio et propose, pour chaque compteur entendu, ses données : `volume`,
  `energie`, `puissance`, `debit`, `temperature_depart`, `temperature_retour`, `erreurs`, les valeurs
  mémorisées (`energie_hist1` : index de fin de période), la date, et `rssi`. Un compteur chiffré sans
  sa clé est listé avec la marche à suivre. Un point s'adresse par *identifiant du compteur + donnée*.

Linux : l'utilisateur du service doit appartenir au groupe `dialout` (`sudo usermod -aG dialout
<utilisateur>`, puis redémarrer le service) ; l'écran le dit quand l'accès est refusé.

---

## 10. Réseau et pare-feu

| Sens | Port | Pour |
| --- | --- | --- |
| serveur Larka → équipement | 502/TCP (ou celui réglé) | Modbus TCP |
| serveur Larka → automate | 47808/UDP | BACnet/IP (Larka émet depuis un port éphémère) |
| serveur Larka → broker | 1883/TCP, 8883/TCP en TLS | MQTT |
| serveur Larka → superviseur | 80/443 ou celui de l'API | API REST |
| serveur Larka → partage | selon le partage monté | CSV (le dossier doit être sous une racine autorisée) |
| **bornes → serveur Larka** | 9000/TCP (ou 443 via Nginx) | OCPP |
| **passerelles LoRaWAN → serveur Larka** (et retour) | 1700/UDP | LoRaWAN (serveur intégré) |
| **PC releveur → serveur Larka** | 80/443 (l'adresse de Larka) | relevés des appareils en hotspot |
| PC releveur → appareil, sur son hotspot | celui du protocole | lecture hors ligne |

Le client REST ignore tout proxy HTTP du système (les équipements sont internes) et
vérifie le certificat HTTPS par défaut.

Les dossiers CSV lisibles sont bornés par `connect.csv_racines` dans `config.json`
(`data/connect/imports` par défaut). Ce réglage ne se change pas depuis l'application :
sans cette borne, un gestionnaire pourrait faire lire n'importe quel fichier du serveur.

### 10 bis. Serveur de supervision séparé

La supervision peut vivre sur un **serveur de supervision** : une installation Larka posée sur
le réseau technique, avec **sa propre base** (PostgreSQL, MariaDB, MySQL ou SQLite — mêmes pilotes
que la GMAO). Lui seul interroge les équipements et garde mesures, alarmes et relevés ; le
serveur principal (la GMAO) ne joint plus aucun équipement, il dialogue avec lui et lui seul.
Réglage par le **gestionnaire** : Bâtiments → Connexions → **Serveur de supervision** (ou, module
fermé, le bouton de l'écran « Larka Connect n'est pas ouvert ») ; le Super Admin garde son
interrupteur du module par organisation. En ligne de commande : `php api/outils/supervision.php`.

**Le lien est chiffré par Larka lui-même** : chaque serveur a une paire de clés X25519 (extension
PHP `sodium`) ; chaque message est scellé (X25519 + XSalsa20-Poly1305) pour la clé de l'autre,
épinglée à l'appairage — illisible et infalsifiable même en HTTP clair ou derrière un proxy qui
ouvre le TLS. Chaque message porte son heure (±5 min) et un identifiant unique (un message
rejoué est refusé) ; une réponse cite la requête à laquelle elle répond. HTTPS reste conseillé
en plus. Les échecs sont comptés par adresse (30 par 5 minutes).

**Deux sens, au choix :**

| Mode | Qui appelle | Ouverture réseau |
| --- | --- | --- |
| **Pont sortant** (recommandé) | serveur de supervision → serveur principal (attente de 20 s, le principal lui passe ses demandes en réponse) | une **sortie** HTTPS du réseau technique vers le principal ; **aucun port** ouvert vers le réseau technique |
| **Port ouvert** | serveur principal → serveur de supervision | un port TCP (HTTPS conseillé) ouvert vers le serveur de supervision |

Les équipements qui appellent eux-mêmes — bornes OCPP, passerelles LoRaWAN (UDP), capteurs MQTT —
s'adressent **directement au serveur de supervision**, qui héberge ces communications : ni pont
ni port pour eux côté principal. Les PC releveurs déposent aussi sur le serveur de supervision.

**Appairage** : un code à usage unique (`LKS1.…`, 30 minutes) créé d'un côté, collé de l'autre ;
les deux écrans affichent la même **empreinte** (6 groupes de 4) à comparer. Créer, utiliser un
code et délier demandent une identité confirmée (durée réglée par le gestionnaire, § 8). Le côté qui colle le
code appelle l'autre : en port ouvert depuis le principal, créez le code sur le serveur de
supervision ; en pont sortant, créez-le sur le principal.

**Ce qui passe par le lien :**

- les **écrans** Supervision, Alarmes, Connexions du principal : chaque appel `connect_*` est
  relayé tel quel avec le compte et le rôle de la personne ; le serveur de supervision revérifie
  les droits (un Demandeur refusé l'est déjà par le principal). La confirmation d'identité se fait
  sur le principal, pour la durée qu'y règle le gestionnaire ; le serveur de supervision la
  plafonne à SA durée si son propre gestionnaire en a choisi une ;
- l'**assistant** : lectures de synthèse (consommations, alarmes, état, valeurs) ;
- la **synchronisation**, chaque minute (tâche de collecte du principal, même avec
  `connect.actif = false`) et après chaque écriture :
  - ↓ référentiel : bâtiments, équipements, compteurs du module Énergie et leurs 3 derniers
    relevés, recopiés sur le serveur de supervision (envoyés seulement s'ils ont changé) ;
  - ↑ sortants : **demandes d'intervention** des alarmes, **relevés d'énergie**, **notifications** —
    écrits par le principal dans SA base. File d'attente : une coupure ne perd rien. Le numéro de
    la demande revient à l'alarme (l'anti-rafale continue de fonctionner).

**Le relais du pont** (`api/outils/supervision-pont.php`) se lance tout seul avec la collecte du
serveur de supervision (chaque minute, un seul à la fois) ; `--permanent` pour un service systemd.
Si le relais n'a pas rappelé depuis 90 s, le principal le dit tout de suite au lieu de faire
attendre l'écran. Le principal doit pouvoir servir plusieurs requêtes à la fois (php-fpm, ou
`PHP_CLI_SERVER_WORKERS` comme le fait `start.sh`).

```
# Serveur de supervision (pont sortant, code créé par le principal)
php api/outils/supervision.php appairer LKS1.… --url-locale=http://127.0.0.1:8000
# Serveur principal
php api/outils/supervision.php etat | tester | synchroniser | delier
```

Réglage `connect.supervision_tls_verifier` (défaut `true`) : `false` accepte un certificat
autosigné du serveur de supervision — le contenu reste chiffré et authentifié par Larka.

---

## 11. Données

| Table | Contenu |
| --- | --- |
| `ConnectPasserelles` | connexions (protocole, adresse, paramètres, secrets chiffrés, état) |
| `ConnectPoints` | points suivis (adresse, mise à l'échelle, écriture, dernière valeur) |
| `ConnectMesures` | historique des valeurs |
| `ConnectRegles`, `ConnectAlarmes` | règles d'alarme, alarmes et acquittements |
| `ConnectLiaisons`, `ConnectExecutions` | relevés automatiques et chacune de leurs échéances |
| `ConnectCommandes` | écritures et commandes envoyées aux équipements |
| `ConnectSessionsVE` | sessions de recharge |
| `ConnectFichiers` | fichiers CSV traités (statut, nombre de valeurs, détail) |
| `ConnectBatiments` | réseaux IP et mots-clés de chaque bâtiment, pour ranger les équipements détectés |
| `ConnectExclusions` | ce que la détection ne propose plus : appareils et machines ignorés, plages jamais parcourues |
| `ConnectReleveurs` | PC releveurs des appareils hors réseau : empreinte du jeton, appareils confiés, dernier contact, journal, matériel radio vu |
| `ConnectLorawan` | session radio de chaque capteur LoRaWAN : DevAddr, compteurs de trames, DevNonce déjà vus, clés de session chiffrées, dernière réception |

Les tables sont créées avec le reste du schéma, dans chaque base d'organisation
(SQLite, PostgreSQL, MariaDB), qu'elles suivent dans les sauvegardes et les migrations
d'un moteur à l'autre.

---

## 12. Dépannage

Chaque panne porte un **code** (`CXR-002` : l'équipement ne répond pas, pare-feu probable ; `CXP-001` : registre
Modbus inexistant ; `CXL-004` : activation LoRaWAN refusée…), affiché sur la carte de la connexion, dans le résultat
de *🧪 Tester* et dans l'onglet **Journal**. Un clic sur le code donne l'explication complète et la marche à suivre ;
liste complète : `Documentations/CODES-ERREUR.md`, ou `php api/outils/codes-erreur.php`.

**Événements de supervision** (*Connexions → Journal*) : une ligne quand une connexion tombe en défaut, ne
répond plus qu'en partie, change de cause de panne ou se rétablit — jamais une ligne par passage —, quand une
trame radio est refusée, et à chaque test et chaque écriture. Filtres : connexion, gravité, période, recherche ;
le décompte *par code* montre d'un coup d'œil le problème qui revient. Lisible par qui voit la supervision (sans
adresse IP ni navigateur : ce n'est pas le journal d'audit). La collecte en ligne de commande
(`php api/outils/connect-collecte.php`) affiche aussi le code et la première chose à faire.

| Symptôme | Piste |
| --- | --- |
| Bandeau « La collecte automatique ne passe pas » | le déclencheur ne tourne pas (§ 5) ; `php api/outils/connect-collecte.php --etat` |
| Test : « refuse la connexion » | l'équipement répond, mais rien n'écoute sur ce port : service arrêté, mauvais port |
| Test : « sans réponse » | pare-feu qui jette les paquets, ou équipement éteint : transmettre le message au service informatique |
| BACnet : aucune réponse | en UDP, le silence ne distingue pas pare-feu, automate éteint et mauvais port ; vérifier le n° d'automate |
| Modbus : exception 0x0B | la passerelle répond, l'équipement derrière elle non (unit id inexistant ou éteint) |
| Modbus : valeur aberrante | type ou ordre des mots (ABCD / CDAB) : l'explorateur montre les deux |
| MQTT : découverte vide | seuls les sujets publiés pendant l'écoute apparaissent : relancer quand les capteurs émettent |
| CSV : « hors des racines autorisées » | ajouter le dossier à `connect.csv_racines` (`config.json`) |
| Borne refusée (401) | mot de passe de la borne différent de celui saisi dans Larka |
| Borne refusée (404) | identifiant inconnu : la déclarer (lien dans l'onglet Connexions) |
| Relevé en anomalie | index qui baisse (compteur remplacé ?) ou consommation hors norme : valider ou rejeter |
| Le PC ne revient pas sur son réseau après un relevé | `releveur.cmd etat` : « Réseau repris après un relevé » doit nommer un profil du PC ; sinon le détail est dans `releveur.log` (lignes « retour : … »). Une seconde carte Wi-Fi (clé USB) évite de couper le réseau habituel |
| PC releveur : « jamais » | collecteur pas encore installé, ou jeton remplacé par un nouveau téléchargement : `releveur.cmd etat` sur le PC |
| PC releveur : « hotspot introuvable » | PC hors de portée, ou nom du Wi-Fi différent (majuscules comprises) : `netsh wlan show networks` |
| LoRaWAN : « aucune passerelle n'a parlé » | la passerelle n'est pas en *Semtech UDP*, vise une autre adresse ou un autre port, ou l'UDP 1700 est filtré ; une passerelle sur Internet doit être nommée dans `connect.lorawan.passerelles` |
| LoRaWAN : le capteur ne s'active pas | DevEUI ou AppKey différents de ceux du capteur (*Tester* : « l'AppKey ne correspond pas ») ; passerelle qui ne peut pas émettre (pas de PULL_DATA) |
| LoRaWAN : « compteur déjà vu » | capteur ABP redémarré, avec *Accepter un compteur remis à zéro* décoché |
| wM-Bus : « aucun récepteur branché » | clé débranchée, ou d'un modèle non reconnu : choisir son port et son modèle à la main |
| wM-Bus : « accès refusé » | Linux : groupe `dialout` (voir § 9 ter) ; Windows : port ouvert par un autre programme |
| wM-Bus : compteur « non entendu » | durée d'écoute plus courte que son intervalle d'émission, mauvais mode radio (T1 / C1), ou hors de portée |
| Un PC de bureau proposé par la détection | *🚫 Ignorer la machine*, ou sa plage dans *Adresses à ne jamais parcourir* |

---

## 13. Tester sans matériel

- **En quelques secondes, avec des mois d'historique** : `./start.sh connect-demo` (Windows :
  `Larka.cmd connect-demo`, ou `php api/outils/connect-demo.php`). Deux connexions *🧪 Simulateur
  intégré* — Hôtel de Ville (bureaux) et Groupe scolaire (calendrier scolaire, vacances comprises) —,
  14 points, **60 jours de courbes** au quart d'heure (`--jours=N`, 400 au plus), des règles d'alarme
  avec les **alarmes passées** qu'elles auraient levées (panne de chauffage, salle surpeuplée, classe
  confinée), et des **relevés automatiques** vers les compteurs du module Énergie, **rattrapés** sur la
  période par le moteur des liaisons. Sur les 21 derniers jours (`--releves=N`), la télérelève remplace
  les relevés et factures saisis de ces compteurs : ils sont mis de côté (`data/connect/simulation/`) et
  remis en place par `--retirer` (`--releves=0` : rien n'est mis de côté, les relevés automatiques
  commencent après le dernier relevé existant). Les index prolongent le relevé qui précède, au rythme de la
  consommation passée du compteur (chaleur : par degré-jour). Sans compteur dans la base, des compteurs
  « (télérelève) » sont créés. Ensuite, la collecte habituelle continue les courbes en temps réel.
  `--activer` ouvre aussi le module (`connect.actif`), `--refaire` reconstruit, `--retirer` enlève tout
  ce que le script a posé (connexions, mesures, alarmes, liaisons, relevés écrits, compteurs créés).
  Proposé à la fin de l'installation de la démonstration « Ville de Larkaville » (`--sans-connect` pour
  ne pas le faire). Un compteur déjà relevé par une autre liaison n'en reçoit pas une seconde.
- Une connexion *Simulateur intégré* se crée aussi à la main (*Nouvelle connexion*) : choisissez les
  grandeurs, le profil d'occupation et la durée d'historique ; la consigne est inscriptible.
- `outils/simulateurs/` (temps réel, vrais protocoles réseau) : compteurs Modbus, compteurs d'eau MQTT, CVC BACnet, superviseur
  REST, vieille GTB CSV et bornes OCPP, chacun sur sa propre adresse `127.0.10.x`, avec des
  aléas et des anomalies à la demande. Son README donne les réglages à saisir dans Larka.
- **Appareils en hotspot** : le simulateur séparé « Larkaville » expose aussi des appareils dits
  « en hotspot » ; le collecteur se teste alors de bout en bout avec `--sans-wifi` (sans changer de
  Wi-Fi : la connexion au hotspot est la seule étape qui n'est pas simulée).
- **Radio sans matériel** : `php outils/simulateurs/radio.php lorawan` (une passerelle et deux capteurs,
  OTAA et ABP, vers le serveur LoRaWAN intégré) et `php outils/simulateurs/radio.php wmbus` (une clé iM871A
  simulée qui répète un compteur de chaleur) ; réglages à saisir dans `outils/simulateurs/README.md`.
- `outils/epreuves/test-radio.php` : la radio sans matériel — cryptographie sur les vecteurs publiés
  (RFC 4493, trame LoRaWAN de référence, télégramme OMS), service LoRaWAN réel avec une passerelle et
  des capteurs simulés en UDP (join, doublons, rejeu, accusés), trames des trois récepteurs wM-Bus,
  inventaire USB sur une fausse arborescence `/sys`, et écoute d'une clé simulée par un pseudo-terminal
  (python3), par le serveur et par le collecteur du PC releveur.
- `outils/epreuves/test-connect.php` : l'épreuve automatique (unités, installation jetable
  avec simulateurs PHP, service OCPP réel, routes de l'API avec leurs refus).

---

## 14. Modules déclaratifs : lire le bâtiment, recevoir ses alarmes

Un module déclaratif (un `.larka`, sans code) peut s'appuyer sur Larka Connect — API d'extensions 1.4,
détail dans `FORMAT-DECLARATIF-REFERENCE.md`, § 9 :

| Le module déclare | Larka fait |
| --- | --- |
| un `lien` vers `points_connect`, `connexions_connect` ou `batiments` | propose les points, connexions ou bâtiments dans un sélecteur, et affiche leur nom |
| un champ `mesure` (`"de": "sonde", "grandeur": "maximum_24h"`) | lit à chaque affichage la valeur, l'état, les alarmes, les statistiques 24 h ou la consommation |
| un ancrage `connect.supervision` | dessine sa tuile en tête de la supervision, relue toutes les 30 s |
| un ancrage `connect.point` | liste ses fiches liées au point sous la courbe de ce point |
| une réception `alarmes` | inscrit chaque alarme technique (et sa fin) dans le registre du module, à chaque passage de la collecte |

Ce qu'un module **ne peut pas** faire : écrire vers un équipement (consigne, commande de borne), acquitter
ou modifier une alarme, lire une adresse, un identifiant ou un secret d'équipement, ni nommer une table
`Connect*` dans une requête (elles sont classées « système protégé » pour les modules). Larka Connect fermé :
les mesures valent « indisponible » et rien n'est reçu.

La réception des alarmes est faite par le passage de la collecte (§ 5, `api/connect/Collecte.php`), pour
chaque organisation, et à l'ouverture de l'écran du registre ; son repère (dernière alarme lue, par module) est
gardé dans `data/extensions/[tenants/<clé>/]connect-alarmes.json`. Exemple livré :
`extensions/modules/larka.qualite-air`.

## 15. Limites de cette version

- OCPP 1.6J seulement (pas encore OCPP 2.0.1) ; MQTT 3.1.1 ; API REST en JSON.
- LoRaWAN : EU868, LoRaWAN 1.0.x, classe A ; pas de commandes MAC (ADR), ni d'envoi de données vers un
  capteur ; trois décodeurs intégrés (et les octets bruts).
- wM-Bus : chiffrement OMS mode 5 seulement (pas les modes 7 et 13), ni les trames compactes ou la couche
  ELL de certains compteurs récents. La clé est écoutée pendant le relevé, pas en continu. Les trois
  récepteurs ont été éprouvés sur des trames reconstituées d'après leurs notices, pas encore sur le
  matériel lui-même.
- Les fichiers CSV sont lus, jamais écrits.
- Dans l'interface anglaise, les messages produits par le serveur (diagnostics des tests,
  messages des relevés et des fichiers) restent en français ; les libellés de l'écran, les
  calendriers et les conditions des règles sont traduits.

# Larka Connect — kit de simulateurs

Des équipements de bâtiment **simulés** pour développer et tester Larka Connect sans
matériel : compteurs électriques, compteurs d'eau, chauffage/clim, bornes de recharge,
superviseur GTB et vieille GTB à export CSV. Chaque système a **son protocole, sa propre
adresse IP** et des **aléas** réalistes (pannes, valeurs absurdes, coupures…).

> **Pour voir tout de suite des courbes sur des semaines, des alarmes et des relevés automatiques**, sans
> attendre que ces simulateurs accumulent des données en temps réel : `./start.sh connect-demo` (Windows :
> `Larka.cmd connect-demo`). Larka pose deux bâtiments *🧪 Simulateur intégré* avec 60 jours d'historique
> (`--jours=N`) en quelques secondes. Ce kit-ci reste l'outil pour éprouver les **vrais protocoles**
> (réseau, pare-feu, aléas, pannes à la demande).

> Outil de développement : `deploy/install.sh` exclut `outils/` de la copie, rien de ce
> dossier ne part sur un serveur.

## Installation (Nobara / Fedora)

```bash
cd outils/simulateurs
./installer.sh          # crée un environnement Python isolé (.venv) et installe les bibliothèques
sudo dnf install mosquitto   # conseillé : broker MQTT robuste (le lanceur démarre le sien)
./lancer.sh start       # démarre les simulateurs en arrière-plan
./lancer.sh verifier    # interroge chaque simulateur comme le fait Larka
```

Prérequis : Python 3.10 ou plus (présent d'office sur Nobara). Rien n'est installé sur le
système : tout est dans le dossier `.venv`. Sans Mosquitto, un broker Python de secours
(amqtt) prend le relais.

## Plan d'adressage

Sous Linux, toute la plage `127.x.x.x` pointe sur la machine elle-même : chaque
simulateur peut donc avoir sa propre IP **sans aucune configuration réseau**.

| Système | Protocole | Adresse | Script |
| --- | --- | --- | --- |
| 3 compteurs électriques (passerelle) | Modbus TCP | `127.0.10.11:5020` unit 1, 2, 3 | `compteurs_elec_modbus.py` |
| Broker MQTT | MQTT | `127.0.10.12:1883` | Mosquitto, sinon `broker_mqtt.py` |
| 3 compteurs d'eau radio | MQTT (style LoRaWAN) | topic `larka/eau/<devEUI>/up` | `compteurs_eau_mqtt.py` |
| Chauffage / clim, 3 zones | BACnet/IP | `127.0.10.13:47808` UDP, device 1301 | `cvc_bacnet.py` |
| 3 bornes de recharge | OCPP 1.6J | depuis `127.0.10.21`, `.22`, `.23` vers Larka | `borne_ocpp.py` |
| Superviseur GTB (CTA, chaudière, groupe froid) | API REST JSON | `http://127.0.10.15:8080/api/v1/` | `superviseur_rest.py` |
| Vieille GTB | Fichiers CSV | `data/connect/imports/gtb/` de Larka | `export_csv_gtb.py` |
| *Superviseur OCPP de test (sans Larka)* | OCPP 1.6J | `ws://127.0.10.14:9000/ocpp/<id>` | `csms_test.py` |

Chaque script accepte `--ip` et `--port` (voir `--help`), et la plupart `--accel` pour
accélérer le temps (compteurs, thermique, charges).

## Brancher les simulateurs sur Larka

1. **Configuration → 🖥️ Serveur → 📡 Larka Connect** : cochez *Larka Connect activé* et
   *Service OCPP* (port 9000 par défaut), puis enregistrez. La collecte est faite par le
   serveur : avec `./start.sh start`, son planificateur passe toutes les 30 secondes et
   interroge chaque connexion à son rythme.
2. `./lancer.sh start` (sans `csms` : le superviseur de test prendrait le port 9000 de Larka).
3. Dans **Bâtiments → 🔌 Connexions**, *＋ Raccorder un équipement*, puis *🧪 Tester la
   connexion* avant d'enregistrer :

| Simulateur | Protocole dans Larka | Réglages |
| --- | --- | --- |
| Compteurs électriques | Modbus TCP | Adresse `127.0.10.11`, port `5020`. Points (*＋ Nouveau point*, ou *🧭 Explorer*) : index au registre `0`, *Entier 32 bits non signé*, coefficient `0,1` → kWh, nature *Compteur (index)* ; puissance au registre `2`, coefficient `0,001` → kW. Un point par compteur : *Unit id* 1, 2 ou 3 — le 3 en ordre **CDAB**. |
| Compteurs d'eau | MQTT | Adresse `127.0.10.12`, port `1883`, sujets à suivre `larka/eau/+/up`. *🔍 Découvrir* propose `index_m3`, `debit_lh`, `batterie_pct`, les alarmes… (une trame toutes les 20 s : relancez si la liste est vide). |
| Chauffage / clim | BACnet/IP | Adresse `127.0.10.13`, port `47808`, n° d'automate `1301`. *🔍 Découvrir* liste les objets ; les consignes (`analog-value` 1 à 3) s'écrivent de 12 à 30 °C. |
| Superviseur GTB | API REST (JSON) | Adresse `http://127.0.10.15:8080/api/v1`, authentification *Jeton (Bearer)* : `lecture-7f3a9c` (ou `ecriture-c41d08` pour écrire les consignes). Chemin des alarmes : `/alarmes`. Le reste par défaut, puis *🔍 Découvrir*. |
| Vieille GTB | Fichiers CSV déposés | Dossier `data/connect/imports/gtb`, noms de fichiers `GTB_EXPORT_*.csv`, le reste par défaut (encodage et séparateur détectés). |
| Bornes de recharge | OCPP 1.6J | Onglet *🚗 Bornes de recharge* → *＋ Déclarer une borne* : `BORNE-01`, `BORNE-02`, `BORNE-03`. Une borne non déclarée qui appelle apparaît dans un bandeau, avec un lien *la déclarer*. |

Les bornes appellent `ws://127.0.10.14:9000/ocpp/<identifiant>` : `127.0.10.14` est une
adresse de la machine, que le service OCPP de Larka (en écoute sur toutes les interfaces)
reçoit. Autre adresse ou mot de passe de borne :
`LARKA_OCPP=ws://192.168.1.20:9000/ocpp LARKA_OCPP_MDP=secret ./lancer.sh start bornes`.
Autre dossier d'exports : `LARKA_CSV=/chemin/sans/espace ./lancer.sh start csv` (le dossier
doit être sous une racine autorisée, `connect.csv_racines` de `config.json`).

Ensuite, à essayer : une **règle d'alarme** sur `CTA-01.DP_FILTRE` (> 250 Pa) avec demande
d'intervention, un **relevé automatique** quotidien de l'index électrique vers un compteur du
module Énergie, une **consigne** BACnet modifiée depuis la supervision (confirmation
d'identité, puis trace au journal).

## Ce que chaque simulateur apprend à Larka

**Compteurs électriques (Modbus).** Index sur 2 registres de 16 bits, unités ×10, et le
compteur 3 range ses mots à l'envers (CDAB) : si le pilote se trompe, l'index lu est
faux d'un facteur énorme. Un unit absent renvoie l'exception `0x0B`. Carte des registres
en tête du script.

**Compteurs d'eau (MQTT).** Trames JSON perdues, en double (même `fcnt`), batterie qui
baisse, fuite nocturne, retour d'eau. Larka doit dédoublonner et détecter les trous.

**Chauffage / clim (BACnet).** Consignes et modes **inscriptibles** (12 à 30 °C, sinon
refus `value-out-of-range`), entrées en lecture seule (refus `write-access-denied`),
capteur défaillant signalé par `reliability`, compteurs d'heures et de démarrages de la
PAC pour la maintenance conditionnelle. BACnet n'a **aucune authentification**.

**Bornes (OCPP).** Cycle complet de charge, badges (dont un refusé exprès : `DEADBEEF`),
défauts, coupures réseau. Après une coupure, la fin de charge arrive **en différé avec
un horodatage ancien** : Larka doit l'accepter et la ranger à la bonne date. Un arrêt à
distance (*⚡ Commandes → Arrêter la charge en cours*) est pris en compte tout de suite,
y compris batterie pleine.

**Superviseur REST.** Jetons `Bearer` lecture / écriture (401, 403, 422), erreurs 500,
réponses lentes, maintenance 503. Le filtre de la CTA s'encrasse avec le temps et la
pression de la chaudière baisse : deux alarmes idéales pour créer des DI.

**Vieille GTB (CSV).** Encodage Windows, `;`, virgule décimale, lignes parasites avant
l'en-tête, fichier écrit en deux fois (ne pas le lire tant qu'il bouge), fichiers
tronqués, valeurs `#####` ou `N/A`, doublons.

## Provoquer des anomalies

```bash
./lancer.sh anomalie <nom> 1     # ou 2
```

| Nom | 1 | 2 |
| --- | --- | --- |
| `elec` | remplacement d'un compteur (index ≈ 0) | saut d'index aberrant |
| `eau` | fuite sur un compteur | remplacement d'un compteur |
| `cvc` | panne clim salle serveurs (surchauffe) | capteur de température HS |
| `bornes` | défaut sur une borne | coupure réseau d'une borne |
| `rest` | défaut brûleur chaudière | filtre CTA colmaté |
| `csv` | remplacement du compteur de calories | prochain fichier corrompu |

Les aléas automatiques continuent en parallèle (ils sont rares, quelques-uns par heure).

## Commandes du lanceur

```bash
./lancer.sh start [noms…]     # tout (sauf csms), ou seulement certains : ./lancer.sh start elec cvc
./lancer.sh stop [noms…]
./lancer.sh restart [noms…]
./lancer.sh status
./lancer.sh logs [nom]        # Ctrl+C pour quitter
./lancer.sh verifier [modbus|mqtt|bacnet|ocpp|rest|csv]
```

Noms : `elec broker eau cvc bornes rest csv`, et `csms` (jamais lancé d'office).

**Essayer les bornes sans Larka** : arrêtez le service OCPP de Larka (ou laissez Larka
éteint), puis `./lancer.sh start csms bornes`. Pour piloter les bornes à la main (arrêt de
charge à distance, mise hors service…), lancez le CSMS au premier plan dans un terminal à
part et tapez `liste`, `stop BORNE-01`, `dispo BORNE-02 off`, `reset BORNE-03` :

```bash
./lancer.sh stop csms && .venv/bin/python csms_test.py
```

## Points d'attention

- **BACnet sur la même machine** : Larka interroge depuis un port UDP éphémère, pas
  depuis 47808 — il cohabite donc avec le simulateur. Un autre client (`verifier.py`,
  un outil BACnet) doit lui aussi éviter 47808. Le simulateur refuse de démarrer en double.
- **Larka sur une autre machine ou une VM** : lancez les simulateurs avec `--ip 0.0.0.0`
  (ou l'IP de la machine) et ouvrez les ports dans le pare-feu de Nobara, par exemple
  `sudo firewall-cmd --add-port=5020/tcp --add-port=47808/udp --add-port=8080/tcp`
  (ajoutez `--permanent` pour que ça survive au redémarrage). Pour les bornes, c'est
  l'inverse : le port 9000 doit être ouvert **sur la machine de Larka**.
- **Port Modbus standard** : 502 demande les droits root. D'où 5020 par défaut.
- **Proxy** : si un proxy HTTP est configuré sur la machine, les scripts l'ignorent
  volontairement pour ces adresses locales.

## Scénarios de test pour Larka

| Test | Comment le provoquer | Résultat attendu dans Larka |
| --- | --- | --- |
| Relevé normal | relevé automatique sur un compteur | relevé *automatique* dans le module Énergie |
| Mauvais ordre des mots | lire le compteur unit 3 en ABCD | valeur aberrante détectée |
| Équipement absent | `./lancer.sh stop elec` | relevé *manquant* + alarme |
| Compteur remplacé | `anomalie elec 1` | *anomalie à valider*, rien d'écrit |
| Fuite | `anomalie eau 1` | alarme → demande d'intervention |
| Surchauffe | `anomalie cvc 1` | alarme salle serveurs → DI urgente |
| Écriture protégée | changer une consigne BACnet ou REST | confirmation d'identité puis journal |
| Écriture refusée | consigne à 35 °C | refus clair (hors de la plage permise) |
| Fin de charge différée | `anomalie bornes 2` | session rangée à la bonne heure |
| API capricieuse | laisser tourner le REST | nouvelles tentatives, pas de faux « injoignable » |
| Fichier en cours d'écriture | laisser tourner `csv` | fichier ignoré tant qu'il bouge |
| Pare-feu | `sudo iptables -A INPUT -d 127.0.10.11 -j DROP` | bouton test : « sans réponse », message pour le service informatique |

(Pour annuler la règle de pare-feu : remplacez `-A` par `-D` dans la même commande.)

## Radio sans matériel : LoRaWAN et wM-Bus

`radio.php` n'a besoin ni du `.venv` ni d'aucune bibliothèque : il se sert du codec de Larka.

**Passerelle LoRaWAN et deux capteurs.** Dans *Configuration → Serveur → Larka Connect*, cochez *Serveur
LoRaWAN intégré* (port 1700), puis :

```bash
php outils/simulateurs/radio.php lorawan --larka=127.0.0.1:1700 --intervalle=20
```

| Capteur | À déclarer dans Larka (*Nouvelle connexion → LoRaWAN*) | Points |
| --- | --- | --- |
| Sonde de salle (OTAA) | DevEUI `70B3D57ED0000001`, OTAA, AppKey `2B7E151628AED2A6ABF7158809CF4F3C`, décodeur *Cayenne LPP* | *🔍 Découvrir* : `temperature_1`, `humidite_2`, `concentration_3` (CO₂) |
| Compteur à impulsions (ABP) | DevEUI `70B3D57ED0000002`, ABP, DevAddr `26011B01`, NwkSKey `000102030405060708090A0B0C0D0E0F`, AppSKey `0F0E0D0C0B0A09080706050403020100`, décodeur *octets bruts* | index : octet `0`, `uint32`, gros-boutiste, nature *compteur* |

La sonde fait son join, attend la réponse de Larka, puis émet. Avant d'être déclarée, elle apparaît dans un
bandeau *capteur non déclaré* (onglet Connexions), avec un lien *le déclarer*.

**Clé USB wM-Bus** (Linux, macOS ; python3 de la machine, sans module) :

```bash
php outils/simulateurs/radio.php wmbus --duree=600
```

Le simulateur affiche le port (`/dev/pts/N`) à saisir dans *Nouvelle connexion → Compteurs radio wM-Bus* :
branchée sur le serveur, ce port, récepteur *IMST iM871A*, mode T1. *🔍 Découvrir* propose le compteur de
chaleur `87654321` : énergie, volume, températures, erreurs, réception.

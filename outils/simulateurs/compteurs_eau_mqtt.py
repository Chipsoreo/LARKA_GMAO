#!/usr/bin/env python3
# SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
# SPDX-License-Identifier: LicenseRef-Larka-Proprietary
"""
Simulateur — Compteurs d'eau radio (style LoRaWAN) publiés en MQTT
=================================================================
3 compteurs d'eau qui envoient une trame JSON à intervalle régulier, comme le
ferait un serveur réseau LoRaWAN (ChirpStack, TTN, Orange Live Objects…).

Topic   : larka/eau/<devEUI>/up
Payload :
  {
    "devEUI": "70B3D5E75E000101",  "compteur": "Eau générale",
    "index_m3": 18452.317,         "debit_lh": 412.0,
    "batterie_pct": 87,            "alarme_fuite": false, "alarme_retour": false,
    "rssi": -97, "snr": 7.5,       "fcnt": 1234,
    "horodatage": "2026-10-27T15:20:00+01:00"
  }

Aléas : trame perdue, trame en double (même fcnt), radio dégradée, batterie qui
baisse, fuite nocturne (débit permanent → alarme), retour d'eau (rare),
arrosage le soir en été.
Anomalies à la main :
  kill -USR1 <pid>  → déclenche une fuite sur un compteur
  kill -USR2 <pid>  → remplacement d'un compteur (index repart de 0)

Usage : python3 compteurs_eau_mqtt.py [--broker 127.0.10.12] [--port 1883] [--intervalle 60]
"""
import argparse
import json
import random
import time
from datetime import datetime

import paho.mqtt.client as mqtt

from commun import log, tirage, bruit, facteur_occupation, brancher_signaux

NOM = "EAU/MQTT"

COMPTEURS = [
    {"devEUI": "70B3D5E75E000101", "compteur": "Eau générale",       "index": 18452.317, "debit_max": 3200, "batterie": 87},
    {"devEUI": "70B3D5E75E000102", "compteur": "Sanitaires bât. A",  "index": 4120.908,  "debit_max": 900,  "batterie": 64},
    {"devEUI": "70B3D5E75E000103", "compteur": "Arrosage espaces verts", "index": 2310.044, "debit_max": 1800, "batterie": 23},
]


class CompteurEau:
    def __init__(self, cfg):
        self.cfg = cfg
        self.index = cfg["index"]
        self.batterie = float(cfg["batterie"])
        self.fcnt = random.randint(100, 5000)
        self.fuite_lh = 0.0
        self.retour = False
        self.derniere = None

    def debit(self):
        """Débit instantané en L/h selon l'usage et l'heure."""
        m = datetime.now()
        if "Arrosage" in self.cfg["compteur"]:
            ete = m.month in (5, 6, 7, 8, 9)
            base = self.cfg["debit_max"] if (ete and 20 <= m.hour < 22) else 0
        else:
            base = self.cfg["debit_max"] * facteur_occupation() * random.uniform(0.3, 1.0)
            if tirage(0.3):
                base *= 0.1                 # moments sans tirage d'eau
        return max(0.0, bruit(base, 8) + self.fuite_lh)

    def trame(self, intervalle):
        d = self.debit()
        self.index += d * intervalle / 3600 / 1000          # L/h → m³
        self.batterie = max(0.0, self.batterie - random.uniform(0.0, 0.02))
        self.fcnt += 1
        rssi = random.randint(-105, -80)
        if tirage(0.05):
            rssi = random.randint(-122, -115)                 # radio dégradée
        return {
            "devEUI": self.cfg["devEUI"],
            "compteur": self.cfg["compteur"],
            "index_m3": round(self.index, 3),
            "debit_lh": round(d, 1),
            "batterie_pct": int(self.batterie),
            "alarme_fuite": self.fuite_lh > 0 and datetime.now().hour < 6 or self.fuite_lh > 150,
            "alarme_retour": self.retour,
            "rssi": rssi,
            "snr": round(random.uniform(-8, 10), 1),
            "fcnt": self.fcnt,
            "horodatage": datetime.now().astimezone().isoformat(timespec="seconds"),
        }


def main():
    ap = argparse.ArgumentParser(description="Simulateur compteurs d'eau MQTT")
    ap.add_argument("--broker", default="127.0.10.12")
    ap.add_argument("--port", type=int, default=1883)
    ap.add_argument("--intervalle", type=int, default=60, help="secondes entre deux trames (réel LoRaWAN : 900 à 3600)")
    ap.add_argument("--utilisateur", default=None)
    ap.add_argument("--mdp", default=None)
    a = ap.parse_args()

    compteurs = [CompteurEau(c) for c in COMPTEURS]

    def fuite():
        c = random.choice(compteurs)
        c.fuite_lh = random.uniform(40, 250)
        log(NOM, f"ANOMALIE : fuite de {c.fuite_lh:.0f} L/h sur « {c.cfg['compteur']} »", "anomalie")

    def remplacement():
        c = random.choice(compteurs)
        log(NOM, f"ANOMALIE : compteur « {c.cfg['compteur']} » remplacé ({c.index:.3f} → 0 m³)", "anomalie")
        c.index, c.fcnt, c.batterie = 0.0, 0, 100.0

    brancher_signaux(fuite, remplacement)

    client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2, client_id="larka-sim-eau")
    if a.utilisateur:
        client.username_pw_set(a.utilisateur, a.mdp)
    client.on_connect = lambda c, u, f, rc, p: log(NOM, f"connecté au broker {a.broker}:{a.port} (rc={rc})", "ok")
    client.on_disconnect = lambda c, u, f, rc, p: log(NOM, f"déconnecté du broker (rc={rc})", "aleas")
    client.reconnect_delay_set(1, 30)

    while True:
        try:
            client.connect(a.broker, a.port, keepalive=60)
            break
        except OSError as e:
            log(NOM, f"broker injoignable ({e}), nouvel essai dans 3 s…", "aleas")
            time.sleep(3)
    client.loop_start()
    log(NOM, f"{len(compteurs)} compteurs, une trame toutes les {a.intervalle}s sur larka/eau/<devEUI>/up", "titre")

    try:
        while True:
            for c in compteurs:
                if tirage(0.002) and c.fuite_lh == 0:
                    c.fuite_lh = random.uniform(20, 120)
                    log(NOM, f"aléa : fuite qui démarre sur « {c.cfg['compteur']} » ({c.fuite_lh:.0f} L/h)", "aleas")
                c.retour = tirage(0.003)
                t = c.trame(a.intervalle)
                topic = f"larka/eau/{c.cfg['devEUI']}/up"
                if tirage(0.05):
                    log(NOM, f"aléa : trame perdue ({c.cfg['compteur']}, fcnt {t['fcnt']})", "aleas")
                    continue
                client.publish(topic, json.dumps(t, ensure_ascii=False), qos=1)
                log(NOM, f"{c.cfg['compteur']:<24} {t['index_m3']:>11.3f} m³  {t['debit_lh']:>7.1f} L/h  bat {t['batterie_pct']}%"
                         + ("  ⚠ FUITE" if t["alarme_fuite"] else "") + ("  ⚠ RETOUR" if t["alarme_retour"] else ""))
                if tirage(0.01):
                    client.publish(topic, json.dumps(t, ensure_ascii=False), qos=1)
                    log(NOM, f"aléa : trame en DOUBLE (fcnt {t['fcnt']})", "aleas")
            time.sleep(a.intervalle)
    except KeyboardInterrupt:
        pass
    finally:
        client.loop_stop()
        client.disconnect()


if __name__ == "__main__":
    main()

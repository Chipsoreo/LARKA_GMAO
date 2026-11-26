#!/usr/bin/env python3
# SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
# SPDX-License-Identifier: LicenseRef-Larka-Proprietary
"""
Simulateur — Chauffage / climatisation (CVC) en BACnet/IP
=========================================================
Un automate BACnet (device 1301) qui pilote 3 zones avec un modèle thermique
simple : déperditions vers l'extérieur, apports internes selon l'occupation,
puissance de chauffe / froid, inertie.

Objets exposés (n = 1, 2, 3) :
  analog-input,n        Température zone n (°C)            lecture seule
  analog-input,1n       Humidité zone n (%)                lecture seule
  analog-value,n        Consigne zone n (°C)               ÉCRITURE (12 à 30 °C, priority array)
  multi-state-value,n   Mode zone n : 1 Arrêt, 2 Chauffage, 3 Clim, 4 Auto   ÉCRITURE
  binary-value,n        Défaut équipement zone n           lecture seule
  analog-input,20       Température extérieure (°C)
  analog-input,30       Heures de fonctionnement PAC (h)   → maintenance conditionnelle
  analog-input,31       Nombre de démarrages PAC           → maintenance conditionnelle

Zones : 1 Bureaux RDC (chaud + froid), 2 Salle de réunion (chaud + froid),
        3 Salle serveurs (froid uniquement, gros apports internes).

⚠ BACnet n'a AUCUNE authentification : n'importe qui sur le réseau peut écrire
une consigne. La protection (ré-authentification, journal) doit être dans Larka.

⚠ Sur la même machine, le CLIENT BACnet (Larka, verifier.py…) doit utiliser un
autre port UDP que 47808 (ex. 47809), sinon il se répond à lui-même.

Aléas : fenêtre ouverte, défaut PAC/clim, capteur défaillant (valeur absurde,
reliability = unreliable-other), courts cycles qui font grimper les démarrages.
Anomalies à la main :
  kill -USR1 <pid>  → panne de la clim de la salle serveurs (surchauffe)
  kill -USR2 <pid>  → capteur de température défaillant

Usage : python3 cvc_bacnet.py [--ip 127.0.10.13] [--port 47808] [--accel 1]
"""
import argparse
import asyncio
import random

from bacpypes3.app import Application
from bacpypes3.errors import ExecutionError
from bacpypes3.local.analog import AnalogInputObject, AnalogValueObjectCmd
from bacpypes3.local.binary import BinaryValueObject
from bacpypes3.local.device import DeviceObject
from bacpypes3.local.multistate import MultiStateValueObject
from bacpypes3.local.networkport import NetworkPortObject
from bacpypes3.primitivedata import Real

from commun import log, tirage, facteur_occupation, temperature_exterieure, brancher_signaux

NOM = "CVC/BACnet"
MODES = {1: "Arrêt", 2: "Chauffage", 3: "Climatisation", 4: "Auto"}

ZONES = [
    # nom, T initiale, consigne, perte (1/h), P chaud (°C/h), P froid (°C/h), apports max (°C/h), chaud possible
    {"nom": "Bureaux RDC",      "t": 19.8, "consigne": 21.0, "perte": 0.35, "chaud": 4.0, "froid": 3.0, "apports": 1.2, "chauffe": True},
    {"nom": "Salle de réunion", "t": 20.4, "consigne": 21.0, "perte": 0.50, "chaud": 5.0, "froid": 4.0, "apports": 2.5, "chauffe": True},
    {"nom": "Salle serveurs",   "t": 22.5, "consigne": 22.0, "perte": 0.15, "chaud": 0.0, "froid": 9.0, "apports": 6.0, "chauffe": False},
]

LECTURE_SEULE = {"analog-input", "binary-value"}


class Automate(Application):
    """Application BACnet qui refuse les écritures interdites, comme un vrai automate."""

    async def do_WritePropertyRequest(self, apdu):
        type_objet = str(apdu.objectIdentifier[0])
        if type_objet in LECTURE_SEULE:
            log(NOM, f"écriture REFUSÉE sur {apdu.objectIdentifier} (lecture seule) depuis {apdu.pduSource}", "aleas")
            raise ExecutionError(errorClass="property", errorCode="writeAccessDenied")
        if type_objet == "analog-value" and str(apdu.propertyIdentifier) == "present-value":
            try:
                v = apdu.propertyValue.cast_out(Real)
            except Exception:
                v = None
            if v is not None and not 12.0 <= v <= 30.0:
                log(NOM, f"écriture REFUSÉE : consigne {v:.1f} °C hors plage 12–30 depuis {apdu.pduSource}", "aleas")
                raise ExecutionError(errorClass="property", errorCode="valueOutOfRange")
        log(NOM, f"écriture reçue de {apdu.pduSource} : {apdu.objectIdentifier} "
                 f"{apdu.propertyIdentifier} (priorité {apdu.priority})", "ok")
        return await super().do_WritePropertyRequest(apdu)


class Zone:
    def __init__(self, n, cfg, app):
        self.n, self.cfg = n, cfg
        self.t = cfg["t"]
        self.en_marche = False
        self.fenetre = 0
        self.panne = 0
        self.capteur_hs = 0
        self.ai_t = AnalogInputObject(objectIdentifier=("analog-input", n), objectName=f"T_{cfg['nom']}",
                                      presentValue=self.t, units="degreesCelsius", reliability="no-fault-detected",
                                      description=f"Température {cfg['nom']}")
        self.ai_h = AnalogInputObject(objectIdentifier=("analog-input", 10 + n), objectName=f"HR_{cfg['nom']}",
                                      presentValue=45.0, units="percentRelativeHumidity")
        self.av_c = AnalogValueObjectCmd(objectIdentifier=("analog-value", n), objectName=f"Consigne_{cfg['nom']}",
                                         presentValue=cfg["consigne"], relinquishDefault=cfg["consigne"],
                                         units="degreesCelsius")
        self.msv = MultiStateValueObject(objectIdentifier=("multi-state-value", n), objectName=f"Mode_{cfg['nom']}",
                                         presentValue=4, numberOfStates=4, stateText=list(MODES.values()))
        self.bv_def = BinaryValueObject(objectIdentifier=("binary-value", n), objectName=f"Defaut_{cfg['nom']}",
                                        presentValue="inactive")
        for o in (self.ai_t, self.ai_h, self.av_c, self.msv, self.bv_def):
            app.add_object(o)
        self.consigne_precedente = cfg["consigne"]
        self.mode_precedent = 4

    def tick(self, dt_h, t_ext):
        """Avance le modèle de dt_h heures. Retourne True si la PAC vient de démarrer."""
        cfg = self.cfg
        consigne = float(self.av_c.presentValue)
        mode = int(self.msv.presentValue)
        if consigne != self.consigne_precedente:
            log(NOM, f"zone {self.n} « {cfg['nom']} » : nouvelle consigne {consigne:.1f} °C", "ok")
            self.consigne_precedente = consigne
        if mode != self.mode_precedent:
            log(NOM, f"zone {self.n} « {cfg['nom']} » : mode {MODES.get(mode, mode)}", "ok")
            self.mode_precedent = mode

        perte = cfg["perte"] * (4 if self.fenetre else 1)
        dT = perte * (t_ext - self.t) + cfg["apports"] * (facteur_occupation() if cfg["chauffe"] else 1.0)
        demarrage = False
        besoin_chaud = self.t < consigne - 0.5 and cfg["chauffe"] and mode in (2, 4)
        besoin_froid = self.t > consigne + 0.8 and mode in (3, 4)
        avant = self.en_marche
        if self.panne:
            self.en_marche = False
        elif besoin_chaud:
            self.en_marche = True
            dT += cfg["chaud"]
        elif besoin_froid:
            self.en_marche = True
            dT -= cfg["froid"]
        elif abs(self.t - consigne) < 0.2:
            self.en_marche = False
        elif self.en_marche:                                    # hystérésis
            dT += cfg["chaud"] if self.t < consigne else -cfg["froid"]
        if mode == 1:
            self.en_marche = False
        demarrage = self.en_marche and not avant
        self.t += dT * dt_h + random.gauss(0, 0.03)

        # exposition BACnet
        if self.capteur_hs:
            self.ai_t.presentValue = random.choice([-273.0, 85.0, 0.0])
            self.ai_t.reliability = "unreliable-other"
            self.ai_t.statusFlags = [0, 1, 0, 0]
            self.capteur_hs -= 1
        else:
            self.ai_t.presentValue = round(self.t + random.gauss(0, 0.05), 2)
            self.ai_t.reliability = "no-fault-detected"
            self.ai_t.statusFlags = [0, 0, 0, 0]
        self.ai_h.presentValue = round(min(95, max(15, 45 + 10 * (21 - self.t) / 5 + random.gauss(0, 1))), 1)
        self.bv_def.presentValue = "active" if self.panne else "inactive"
        if self.fenetre:
            self.fenetre -= 1
        if self.panne:
            self.panne -= 1
            if self.panne == 0:
                log(NOM, f"zone {self.n} « {cfg['nom']} » : défaut acquitté, équipement reparti", "ok")
        return demarrage


async def main():
    ap = argparse.ArgumentParser(description="Simulateur CVC BACnet/IP")
    ap.add_argument("--ip", default="127.0.10.13")
    ap.add_argument("--port", type=int, default=47808)
    ap.add_argument("--device", type=int, default=1301)
    ap.add_argument("--accel", type=float, default=30.0,
                    help="accélération du temps thermique (30 = 1 h de bâtiment en 2 min)")
    a = ap.parse_args()

    # BACnet (SO_REUSEADDR) accepte en silence deux automates sur le même port :
    # les lectures se mélangeraient. On vérifie donc que le port est libre.
    import socket
    essai = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    try:
        essai.bind((a.ip, a.port))
    except OSError:
        log(NOM, f"le port UDP {a.ip}:{a.port} est déjà utilisé (simulateur déjà lancé ?). Arrêt.", "anomalie")
        raise SystemExit(1)
    finally:
        essai.close()

    dev = DeviceObject(objectIdentifier=("device", a.device), objectName="CVC-SIM Batiment A",
                       vendorIdentifier=999, modelName="Larka Sim CVC", description="Automate CVC simulé")
    np_ = NetworkPortObject(f"{a.ip}/32:{a.port}", objectIdentifier=("network-port", 1),
                            objectName="NetworkPort-1", networkNumberQuality="unknown")
    app = Automate.from_object_list([dev, np_])

    zones = [Zone(i + 1, cfg, app) for i, cfg in enumerate(ZONES)]
    ai_ext = AnalogInputObject(objectIdentifier=("analog-input", 20), objectName="T_Exterieure",
                               presentValue=temperature_exterieure(), units="degreesCelsius")
    ai_heures = AnalogInputObject(objectIdentifier=("analog-input", 30), objectName="PAC_Heures_Fonctionnement",
                                  presentValue=12873.0, units="hours")
    ai_dem = AnalogInputObject(objectIdentifier=("analog-input", 31), objectName="PAC_Nb_Demarrages",
                               presentValue=48211.0, units="noUnits")
    for o in (ai_ext, ai_heures, ai_dem):
        app.add_object(o)

    def panne_serveurs():
        zones[2].panne = random.randint(60, 180)
        log(NOM, "ANOMALIE : panne de la clim « Salle serveurs » → surchauffe en cours", "anomalie")

    def capteur_hs():
        z = random.choice(zones)
        z.capteur_hs = random.randint(10, 30)
        log(NOM, f"ANOMALIE : capteur de température HS zone {z.n} « {z.cfg['nom']} »", "anomalie")

    brancher_signaux(panne_serveurs, capteur_hs)
    log(NOM, f"automate BACnet device {a.device} sur {a.ip}:{a.port} — 3 zones, accélération x{a.accel:g}", "titre")

    pas = 2.0
    compteur = 0
    while True:
        await asyncio.sleep(pas)
        dt_h = pas * a.accel / 3600
        t_ext = temperature_exterieure()
        ai_ext.presentValue = t_ext
        for z in zones:
            if z.n in (1, 2) and tirage(0.003) and not z.fenetre:
                z.fenetre = random.randint(15, 60)
                log(NOM, f"aléa : fenêtre ouverte zone {z.n} « {z.cfg['nom']} »", "aleas")
            if tirage(0.0008) and not z.panne:
                z.panne = random.randint(30, 120)
                log(NOM, f"aléa : DÉFAUT équipement zone {z.n} « {z.cfg['nom']} »", "aleas")
            if tirage(0.0008) and not z.capteur_hs:
                z.capteur_hs = random.randint(5, 20)
                log(NOM, f"aléa : capteur défaillant zone {z.n} « {z.cfg['nom']} »", "aleas")
            if z.tick(dt_h, t_ext):
                ai_dem.presentValue = float(ai_dem.presentValue) + 1
        if any(z.en_marche for z in zones):
            ai_heures.presentValue = round(float(ai_heures.presentValue) + dt_h, 3)
        compteur += 1
        if compteur % 15 == 0:
            etat = " | ".join(f"z{z.n} {z.t:.1f}/{float(z.av_c.presentValue):.1f}°C"
                              + ("▲" if z.en_marche else "") + ("⚠" if z.panne else "") for z in zones)
            log(NOM, f"ext {t_ext:.1f}°C | {etat} | PAC {float(ai_heures.presentValue):.1f} h, "
                     f"{int(ai_dem.presentValue)} démarrages")


if __name__ == "__main__":
    try:
        asyncio.run(main())
    except KeyboardInterrupt:
        pass

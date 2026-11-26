#!/usr/bin/env python3
# SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
# SPDX-License-Identifier: LicenseRef-Larka-Proprietary
"""
Simulateur — Bornes de recharge VE en OCPP 1.6J
===============================================
Plusieurs bornes, chacune avec SA PROPRE IP source, qui se connectent au
superviseur : le service OCPP de Larka (ou csms_test.py, pour un essai sans Larka).

Bornes :
  BORNE-01  AC 22 kW    depuis 127.0.10.21
  BORNE-02  AC 7,4 kW   depuis 127.0.10.22
  BORNE-03  DC 50 kW    depuis 127.0.10.23   (envoie aussi l'état de charge SoC)

Cycle de vie simulé : Available → Preparing → (badge) Authorize → StartTransaction
→ Charging + MeterValues réguliers → SuspendedEV (batterie pleine) →
StopTransaction → Finishing → Available. Heartbeat selon l'intervalle du CSMS.

Commandes du superviseur gérées : RemoteStartTransaction, RemoteStopTransaction,
ChangeAvailability, Reset.

Aléas : défaut borne (GroundFailure, OverCurrent, ConnectorLock, PowerMeter,
HighTemperature), perte de connexion puis reconnexion, badge inconnu refusé,
utilisateur qui débranche en pleine charge.
Anomalies à la main :
  kill -USR1 <pid>  → défaut sur une borne
  kill -USR2 <pid>  → coupure réseau d'une borne

Usage : python3 borne_ocpp.py [--csms ws://127.0.10.14:9000/ocpp] [--accel 10] [--mdp secret]
        (Larka : son service OCPP écoute par défaut sur le port 9000 de toutes les
        interfaces ; 127.0.10.14 étant une adresse de la machine, l'URL par défaut
        l'atteint. Chaque borne ajoute son identifiant : …/ocpp/BORNE-01.)
"""
import argparse
import asyncio
import base64
import random
from datetime import datetime, timezone

import websockets
from ocpp.routing import on
from ocpp.v16 import ChargePoint, call
from ocpp.v16 import call_result
from ocpp.v16.datatypes import MeterValue, SampledValue
from ocpp.v16.enums import (Action, AvailabilityStatus, ChargePointErrorCode, ChargePointStatus,
                            Measurand, Reason, RemoteStartStopStatus, ResetStatus, UnitOfMeasure)

from commun import log, tirage, facteur_occupation, brancher_signaux

NOM = "BORNES/OCPP"

BORNES = [
    {"id": "BORNE-01", "ip": "127.0.10.21", "modele": "WallBox AC22", "p_max": 22000, "index": 8412300, "dc": False},
    {"id": "BORNE-02", "ip": "127.0.10.22", "modele": "WallBox AC7",  "p_max": 7400,  "index": 3120450, "dc": False},
    {"id": "BORNE-03", "ip": "127.0.10.23", "modele": "Rapide DC50",  "p_max": 50000, "index": 15904020, "dc": True},
]
BADGES = ["04A2B3C4", "0F1E2D3C", "11223344", "A1B2C3D4"]
BADGE_INCONNU = "DEADBEEF"
DEFAUTS = [ChargePointErrorCode.ground_failure, ChargePointErrorCode.over_current_failure,
           ChargePointErrorCode.connector_lock_failure, ChargePointErrorCode.power_meter_failure,
           ChargePointErrorCode.high_temperature]


def horodatage():
    return datetime.now(timezone.utc).isoformat()


class BorneSimulee(ChargePoint):
    def __init__(self, cfg, ws, sim):
        super().__init__(cfg["id"], ws)
        self.cfg, self.sim = cfg, sim
        self.nom = cfg["id"]

    async def statut(self, status, erreur=ChargePointErrorCode.no_error, info=None):
        self.sim.statut = status
        await self.call(call.StatusNotification(connector_id=1, error_code=erreur, status=status,
                                                timestamp=horodatage(), info=info))

    # ---------- commandes reçues du superviseur ----------
    @on(Action.remote_stop_transaction)
    async def on_remote_stop(self, transaction_id):
        ok = self.sim.transaction == transaction_id
        log(NOM, f"{self.nom} : arrêt à distance demandé (#{transaction_id}) → {'OK' if ok else 'refusé'}", "ok")
        if ok:
            self.sim.arret_demande = Reason.remote
        return call_result.RemoteStopTransaction(
            status=RemoteStartStopStatus.accepted if ok else RemoteStartStopStatus.rejected)

    @on(Action.remote_start_transaction)
    async def on_remote_start(self, id_tag, **kw):
        ok = self.sim.statut == ChargePointStatus.available and not self.sim.defaut
        log(NOM, f"{self.nom} : démarrage à distance (badge {id_tag}) → {'OK' if ok else 'refusé'}", "ok")
        if ok:
            self.sim.demarrage_distant = id_tag
        return call_result.RemoteStartTransaction(
            status=RemoteStartStopStatus.accepted if ok else RemoteStartStopStatus.rejected)

    @on(Action.change_availability)
    async def on_change_availability(self, connector_id, type):
        self.sim.indisponible = (type == "Inoperative")
        log(NOM, f"{self.nom} : disponibilité → {type}", "ok")
        asyncio.create_task(self.statut(ChargePointStatus.unavailable if self.sim.indisponible
                                        else ChargePointStatus.available))
        return call_result.ChangeAvailability(status=AvailabilityStatus.accepted)

    @on(Action.reset)
    async def on_reset(self, type):
        log(NOM, f"{self.nom} : reset {type} demandé → redémarrage", "ok")
        self.sim.coupure = 3
        return call_result.Reset(status=ResetStatus.accepted)


class Simulation:
    """État d'une borne, qui survit aux déconnexions."""

    def __init__(self, cfg, args):
        self.cfg, self.args = cfg, args
        self.index_wh = cfg["index"]
        self.statut = ChargePointStatus.available
        self.transaction = None
        self.defaut = None
        self.coupure = 0
        self.indisponible = False
        self.arret_demande = None
        self.demarrage_distant = None
        self.stop_en_attente = None

    async def tourner(self):
        uri = f"{self.args.csms.rstrip('/')}/{self.cfg['id']}"
        entetes = {}
        if self.args.mdp:
            jeton = base64.b64encode(f"{self.cfg['id']}:{self.args.mdp}".encode()).decode()
            entetes["Authorization"] = f"Basic {jeton}"
        while True:
            try:
                async with websockets.connect(uri, subprotocols=["ocpp1.6"], additional_headers=entetes,
                                              local_addr=(self.cfg["ip"], 0), open_timeout=5,
                                              proxy=None) as ws:
                    borne = BorneSimulee(self.cfg, ws, self)
                    log(NOM, f"{self.cfg['id']} connectée à {uri} depuis {self.cfg['ip']}", "ok")
                    taches = {asyncio.create_task(borne.start()), asyncio.create_task(self.vie(borne))}
                    finies, restantes = await asyncio.wait(taches, return_when=asyncio.FIRST_COMPLETED)
                    for t in restantes:
                        t.cancel()
                    for t in finies:
                        t.result()                      # relance l'éventuelle exception
            except websockets.InvalidStatus as e:
                log(NOM, f"{self.cfg['id']} : connexion refusée par le superviseur "
                         f"(HTTP {e.response.status_code}), nouvel essai dans 5 s", "aleas")
            except (OSError, websockets.ConnectionClosed, asyncio.TimeoutError) as e:
                log(NOM, f"{self.cfg['id']} : liaison perdue ({type(e).__name__}), reconnexion dans 5 s", "aleas")
            except _Coupure:
                attente = random.randint(10, 40)
                log(NOM, f"{self.cfg['id']} : coupure réseau, reconnexion dans {attente} s", "aleas")
                await asyncio.sleep(attente)
                continue
            await asyncio.sleep(5)

    async def attendre(self, secondes, interruptible=False):
        """Attend en tenant compte de l'accélération ; lève _Coupure si besoin.
        interruptible : rend la main dès qu'un arrêt est demandé (à distance, défaut)."""
        fin = asyncio.get_running_loop().time() + secondes / self.args.accel
        while asyncio.get_running_loop().time() < fin:
            if self.coupure:
                self.coupure = 0
                raise _Coupure()
            if interruptible and (self.arret_demande or self.defaut):
                return
            await asyncio.sleep(0.5)

    async def vie(self, b):
        r = await b.call(call.BootNotification(charge_point_model=self.cfg["modele"],
                                               charge_point_vendor="LarkaSim",
                                               charge_point_serial_number=f"SN-{self.cfg['id']}",
                                               firmware_version="1.6.3"))
        battement = max(5, int(getattr(r, "interval", 30)))
        if self.stop_en_attente:
            await b.call(call.StopTransaction(**self.stop_en_attente))
            log(NOM, f"{self.cfg['id']} : fin de session #{self.stop_en_attente['transaction_id']} envoyée EN DIFFÉRÉ "
                     f"(horodatage {self.stop_en_attente['timestamp'][11:19]} UTC)", "aleas")
            self.stop_en_attente = None
        asyncio.create_task(self.heartbeat(b, battement))
        await b.statut(ChargePointStatus.faulted, self.defaut) if self.defaut else \
            await b.statut(ChargePointStatus.unavailable if self.indisponible else ChargePointStatus.available)

        while True:
            await self.attendre(30)
            if self.defaut and self.statut != ChargePointStatus.faulted:
                await self.panne(b, self.defaut)      # défaut forcé à la main
                continue
            if self.defaut or self.indisponible:
                continue
            if tirage(0.01):
                await self.panne(b)
                continue
            arrivee = self.demarrage_distant or (tirage(0.15 * facteur_occupation() + 0.02) and "local")
            if arrivee:
                await self.session(b, self.demarrage_distant)

    async def heartbeat(self, b, intervalle):
        try:
            while True:
                await asyncio.sleep(intervalle)
                await b.call(call.Heartbeat())
        except Exception:
            pass

    async def panne(self, b, code=None):
        self.defaut = code or random.choice(DEFAUTS)
        log(NOM, f"{self.cfg['id']} : DÉFAUT {self.defaut.value}", "anomalie")
        await b.statut(ChargePointStatus.faulted, self.defaut, info="Défaut simulé")
        await self.attendre(random.randint(300, 1200))
        self.defaut = None
        log(NOM, f"{self.cfg['id']} : défaut disparu, borne de nouveau disponible", "ok")
        await b.statut(ChargePointStatus.available)

    async def session(self, b, badge_distant=None):
        self.demarrage_distant = None
        await b.statut(ChargePointStatus.preparing)
        badge = badge_distant or (BADGE_INCONNU if tirage(0.08) else random.choice(BADGES))
        if not badge_distant:
            await self.attendre(random.randint(10, 40))
            auth = await b.call(call.Authorize(id_tag=badge))
            if auth.id_tag_info["status"] != "Accepted":
                log(NOM, f"{self.cfg['id']} : badge {badge} refusé, l'usager repart", "aleas")
                await b.statut(ChargePointStatus.available)
                return
        st = await b.call(call.StartTransaction(connector_id=1, id_tag=badge,
                                                meter_start=int(self.index_wh), timestamp=horodatage()))
        self.transaction = st.transaction_id
        await b.statut(ChargePointStatus.charging)

        besoin_wh = random.uniform(5000, 45000)       # énergie que veut le véhicule
        soc = random.uniform(10, 50)
        charge_wh = 0.0
        pas = 60                                       # secondes "de bâtiment" entre deux relevés
        debranche = tirage(0.1)
        try:
            while charge_wh < besoin_wh and not self.arret_demande:
                if self.defaut:
                    break
                p = self.cfg["p_max"] * random.uniform(0.85, 1.0)
                if self.cfg["dc"] and soc > 80:
                    p *= max(0.15, (100 - soc) / 20)          # la charge DC ralentit au-delà de 80 %
                e = p * pas / 3600
                charge_wh += e
                self.index_wh += e
                soc = min(100, soc + e / 600)
                await self.relevé(b, p, soc)
                if debranche and charge_wh > besoin_wh * 0.4:
                    log(NOM, f"{self.cfg['id']} : l'usager débranche en pleine charge", "aleas")
                    self.arret_demande = Reason.ev_disconnected
                    break
                await self.attendre(pas, interruptible=True)
            if not self.arret_demande and not self.defaut:
                await b.statut(ChargePointStatus.suspended_ev, info="Batterie pleine")
                await self.relevé(b, 0, soc)
                # Le véhicule reste branché… jusqu'à ce qu'on l'arrête à distance (Larka → Commandes).
                await self.attendre(random.randint(60, 900), interruptible=True)
        except _Coupure:
            # la borne n'a plus de réseau : elle garde la fin de transaction en mémoire
            # et l'enverra à la reconnexion, avec l'horodatage d'origine (piège réaliste)
            self.stop_en_attente = dict(meter_stop=int(self.index_wh), timestamp=horodatage(),
                                        transaction_id=self.transaction, reason=Reason.power_loss, id_tag=badge)
            log(NOM, f"{self.cfg['id']} : session #{self.transaction} coupée par la perte réseau, "
                     "fin de transaction mise en attente", "aleas")
            self.transaction = None
            raise
        raison = self.arret_demande or (Reason.other if self.defaut else Reason.local)
        self.arret_demande = None
        await b.call(call.StopTransaction(meter_stop=int(self.index_wh), timestamp=horodatage(),
                                          transaction_id=self.transaction, reason=raison, id_tag=badge))
        log(NOM, f"{self.cfg['id']} : session #{self.transaction} terminée, {charge_wh / 1000:.1f} kWh", "ok")
        self.transaction = None
        if not self.defaut:
            await b.statut(ChargePointStatus.finishing)
            await self.attendre(20)
            await b.statut(ChargePointStatus.available)

    async def relevé(self, b, puissance_w, soc):
        valeurs = [
            SampledValue(value=f"{self.index_wh:.0f}", measurand=Measurand.energy_active_import_register,
                         unit=UnitOfMeasure.wh),
            SampledValue(value=f"{puissance_w:.0f}", measurand=Measurand.power_active_import,
                         unit=UnitOfMeasure.w),
        ]
        if self.cfg["dc"]:
            valeurs.append(SampledValue(value=f"{soc:.0f}", measurand=Measurand.soc, unit=UnitOfMeasure.percent))
        await b.call(call.MeterValues(connector_id=1, transaction_id=self.transaction,
                                      meter_value=[MeterValue(timestamp=horodatage(), sampled_value=valeurs)]))


class _Coupure(Exception):
    pass


async def main():
    ap = argparse.ArgumentParser(description="Simulateur bornes de recharge OCPP 1.6J")
    ap.add_argument("--csms", default="ws://127.0.10.14:9000/ocpp")
    ap.add_argument("--accel", type=float, default=10.0, help="accélération (10 = une charge d'1 h dure 6 min)")
    ap.add_argument("--mdp", default=None, help="mot de passe Basic auth (OCPP profil 1)")
    a = ap.parse_args()

    sims = [Simulation(cfg, a) for cfg in BORNES]

    def defaut_manuel():
        s = random.choice(sims)
        s.defaut = random.choice(DEFAUTS)
        log(NOM, f"ANOMALIE : défaut {s.defaut.value} forcé sur {s.cfg['id']} (pris en compte au prochain cycle)", "anomalie")

    def coupure_manuelle():
        s = random.choice(sims)
        s.coupure = 1
        log(NOM, f"ANOMALIE : coupure réseau forcée sur {s.cfg['id']}", "anomalie")

    brancher_signaux(defaut_manuel, coupure_manuelle)
    log(NOM, f"{len(sims)} bornes vers {a.csms}, accélération x{a.accel:g}", "titre")
    await asyncio.gather(*(s.tourner() for s in sims))


if __name__ == "__main__":
    try:
        asyncio.run(main())
    except KeyboardInterrupt:
        pass

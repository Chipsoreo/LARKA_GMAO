#!/usr/bin/env python3
# SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
# SPDX-License-Identifier: LicenseRef-Larka-Proprietary
"""
Mini superviseur OCPP 1.6J (CSMS) de test
=========================================
En OCPP, ce sont les BORNES qui se connectent au superviseur (WebSocket).
Ce script joue le rôle du futur module OCPP de Larka : il accepte les bornes,
répond à leurs messages et affiche tout ce qui se passe.

Il sert à deux choses :
  1. voir les bornes simulées vivre (borne_ocpp.py) ;
  2. servir de base de lecture pour écrire le vrai serveur OCPP côté Larka.

URL que les bornes doivent utiliser : ws://<ip>:<port>/ocpp/<identifiant_borne>
Badges acceptés : voir BADGES ci-dessous (le badge "DEADBEEF" est refusé exprès).

Commandes interactives (taper puis Entrée) :
  liste                       état des bornes connectées
  stop <borne>                RemoteStopTransaction sur la session en cours
  dispo <borne> on|off        ChangeAvailability
  reset <borne>               Reset (soft)

Usage : python3 csms_test.py [--ip 127.0.10.14] [--port 9000] [--mdp secret]
"""
import argparse
import asyncio
import base64
import itertools
import sys
from datetime import datetime, timezone

import websockets
from ocpp.routing import on
from ocpp.v16 import ChargePoint, call, call_result
from ocpp.v16.datatypes import IdTagInfo
from ocpp.v16.enums import (Action, AuthorizationStatus, AvailabilityType, RegistrationStatus,
                            ResetType)

from commun import log

NOM = "CSMS/OCPP"
BADGES = {"04A2B3C4": "Agent technique", "0F1E2D3C": "Véhicule de service 1",
          "11223344": "Véhicule de service 2", "A1B2C3D4": "Direction"}
transactions = itertools.count(1001)
bornes = {}


def maintenant():
    return datetime.now(timezone.utc).isoformat()


class Borne(ChargePoint):
    def __init__(self, ident, ws, ip):
        super().__init__(ident, ws)
        self.ip = ip
        self.statut = "?"
        self.erreur = "NoError"
        self.transaction = None
        self.energie_wh = 0
        self.puissance_w = 0

    @on(Action.boot_notification)
    async def on_boot(self, charge_point_vendor, charge_point_model, **kw):
        log(NOM, f"{self.id} ({self.ip}) BootNotification : {charge_point_vendor} {charge_point_model} "
                 f"firmware {kw.get('firmware_version', '?')}", "ok")
        return call_result.BootNotification(current_time=maintenant(), interval=30,
                                            status=RegistrationStatus.accepted)

    @on(Action.heartbeat)
    async def on_heartbeat(self):
        return call_result.Heartbeat(current_time=maintenant())

    @on(Action.status_notification)
    async def on_status(self, connector_id, error_code, status, **kw):
        self.statut, self.erreur = status, error_code
        niveau = "anomalie" if status == "Faulted" else ("aleas" if status == "Unavailable" else "info")
        info = f" — {error_code}" if error_code != "NoError" else ""
        log(NOM, f"{self.id} connecteur {connector_id} : {status}{info}"
                 + (f" ({kw['info']})" if kw.get("info") else ""), niveau)
        return call_result.StatusNotification()

    @on(Action.authorize)
    async def on_authorize(self, id_tag):
        ok = id_tag in BADGES
        log(NOM, f"{self.id} badge {id_tag} → {'accepté (' + BADGES[id_tag] + ')' if ok else 'REFUSÉ'}",
            "ok" if ok else "aleas")
        return call_result.Authorize(id_tag_info=IdTagInfo(
            status=AuthorizationStatus.accepted if ok else AuthorizationStatus.invalid))

    @on(Action.start_transaction)
    async def on_start(self, connector_id, id_tag, meter_start, timestamp, **kw):
        tid = next(transactions)
        self.transaction = tid
        log(NOM, f"{self.id} début de charge #{tid} (badge {id_tag}, index {meter_start / 1000:.1f} kWh)", "ok")
        return call_result.StartTransaction(transaction_id=tid,
                                            id_tag_info=IdTagInfo(status=AuthorizationStatus.accepted))

    @on(Action.meter_values)
    async def on_meter(self, connector_id, meter_value, **kw):
        for mv in meter_value:
            for sv in mv.get("sampled_value", []):
                m = sv.get("measurand", "Energy.Active.Import.Register")
                if m == "Energy.Active.Import.Register":
                    self.energie_wh = float(sv["value"])
                elif m == "Power.Active.Import":
                    self.puissance_w = float(sv["value"])
        log(NOM, f"{self.id} relevé : {self.energie_wh / 1000:.2f} kWh, {self.puissance_w / 1000:.1f} kW")
        return call_result.MeterValues()

    @on(Action.stop_transaction)
    async def on_stop(self, meter_stop, timestamp, transaction_id, **kw):
        log(NOM, f"{self.id} fin de charge #{transaction_id} (index {meter_stop / 1000:.1f} kWh, "
                 f"motif {kw.get('reason', 'Local')})", "ok")
        self.transaction = None
        self.puissance_w = 0
        return call_result.StopTransaction()


def verifier_auth(ws, mdp):
    """OCPP profil de sécurité 1 : Basic auth, identifiant = id de la borne."""
    if not mdp:
        return True
    entete = ws.request.headers.get("Authorization", "")
    if not entete.startswith("Basic "):
        return False
    try:
        _, secret = base64.b64decode(entete[6:]).decode().split(":", 1)
    except Exception:
        return False
    return secret == mdp


async def connexion(ws, mdp):
    chemin = ws.request.path
    ident = chemin.rstrip("/").split("/")[-1]
    ip = ws.remote_address[0]
    if not chemin.startswith("/ocpp/") or not ident:
        log(NOM, f"connexion refusée : chemin {chemin} invalide (attendu /ocpp/<id>)", "aleas")
        return await ws.close()
    if not verifier_auth(ws, mdp):
        log(NOM, f"connexion refusée : {ident} ({ip}) mot de passe invalide", "anomalie")
        return await ws.close(code=1008, reason="auth")
    borne = Borne(ident, ws, ip)
    bornes[ident] = borne
    log(NOM, f"borne {ident} connectée depuis {ip}", "ok")
    try:
        await borne.start()
    except websockets.ConnectionClosed:
        pass
    finally:
        log(NOM, f"borne {ident} DÉCONNECTÉE", "aleas")
        bornes.pop(ident, None)


async def console():
    loop = asyncio.get_running_loop()
    while True:
        ligne = (await loop.run_in_executor(None, sys.stdin.readline)).strip()
        if not ligne:
            if sys.stdin.closed or not sys.stdin.isatty():
                await asyncio.sleep(3600)
            continue
        mots = ligne.split()
        b = bornes.get(mots[1]) if len(mots) > 1 else None
        try:
            if mots[0] == "liste":
                for i, x in bornes.items():
                    print(f"  {i:<10} {x.ip:<14} {x.statut:<12} {x.erreur:<20} "
                          f"{'session #' + str(x.transaction) if x.transaction else '':<14} {x.puissance_w / 1000:.1f} kW")
                if not bornes:
                    print("  (aucune borne connectée)")
            elif not b:
                print("borne inconnue — tape 'liste'")
            elif mots[0] == "stop" and b.transaction:
                r = await b.call(call.RemoteStopTransaction(transaction_id=b.transaction))
                print("  réponse :", r.status)
            elif mots[0] == "dispo":
                t = AvailabilityType.operative if mots[2] == "on" else AvailabilityType.inoperative
                r = await b.call(call.ChangeAvailability(connector_id=1, type=t))
                print("  réponse :", r.status)
            elif mots[0] == "reset":
                r = await b.call(call.Reset(type=ResetType.soft))
                print("  réponse :", r.status)
            else:
                print("commandes : liste | stop <borne> | dispo <borne> on|off | reset <borne>")
        except Exception as e:
            print("  erreur :", e)


async def main():
    ap = argparse.ArgumentParser(description="Mini CSMS OCPP 1.6J de test")
    ap.add_argument("--ip", default="127.0.10.14")
    ap.add_argument("--port", type=int, default=9000)
    ap.add_argument("--mdp", default=None, help="exiger ce mot de passe (OCPP profil 1, Basic auth)")
    a = ap.parse_args()
    async with websockets.serve(lambda ws: connexion(ws, a.mdp), a.ip, a.port, subprotocols=["ocpp1.6"]):
        log(NOM, f"superviseur OCPP 1.6J sur ws://{a.ip}:{a.port}/ocpp/<id>"
                 + (" (mot de passe exigé)" if a.mdp else ""), "titre")
        await console()


if __name__ == "__main__":
    try:
        asyncio.run(main())
    except KeyboardInterrupt:
        pass

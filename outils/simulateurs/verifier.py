#!/usr/bin/env python3
# SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
# SPDX-License-Identifier: LicenseRef-Larka-Proprietary
"""
Vérification — le futur bouton « Tester la connexion » de Larka
==============================================================
Interroge chaque simulateur comme le fera Larka, en 3 étapes :
  1. réseau   : le port répond-il ? (ouvert / refusé / sans réponse = pare-feu ?)
  2. protocole: une vraie lecture, avec la valeur et la latence
  3. droits   : authentification quand le protocole en a une

C'est aussi du code de référence pour écrire les pilotes PHP : le Modbus, par
exemple, est fait « à la main » avec des sockets, comme il le sera en PHP.

Usage : python3 verifier.py [tout|modbus|mqtt|bacnet|ocpp|rest|csv]
"""
import asyncio
import csv
import io
import json
import os
import socket
import struct
import sys
import time
import urllib.error
import urllib.request
from pathlib import Path

V, R, J, G, F = "\033[32m", "\033[31m", "\033[33m", "\033[1m", "\033[0m"
if not sys.stdout.isatty():
    V = R = J = G = F = ""


def ok(m):
    print(f"   {V}✔{F} {m}")


def ko(m):
    print(f"   {R}✘{F} {m}")


def attention(m):
    print(f"   {J}!{F} {m}")


def titre(m):
    print(f"\n{G}▶ {m}{F}")


def test_tcp(ip, port, delai=2.0):
    """Étape réseau. Retourne True si le port TCP est ouvert."""
    t0 = time.perf_counter()
    try:
        with socket.create_connection((ip, port), timeout=delai):
            ok(f"réseau : {ip}:{port} ouvert ({(time.perf_counter() - t0) * 1000:.0f} ms)")
            return True
    except ConnectionRefusedError:
        ko(f"réseau : {ip}:{port} REFUSÉ → la machine répond mais rien n'écoute (simulateur arrêté ?)")
    except socket.timeout:
        ko(f"réseau : {ip}:{port} SANS RÉPONSE → probablement filtré par un pare-feu")
        attention(f"demande à transmettre à l'IT : autoriser TCP {port} vers {ip} depuis le serveur Larka")
    except OSError as e:
        ko(f"réseau : {ip}:{port} erreur {e}")
    return False


# ---------------------------------------------------------------- MODBUS
def modbus_lire(ip, port, unit, debut, qte, delai=2.0):
    """Lecture FC03 « à la main » — même logique qu'en PHP avec socket_create/fsockopen."""
    tid = int(time.time() * 1000) & 0xFFFF
    trame = struct.pack(">HHHBBHH", tid, 0, 6, unit, 3, debut, qte)
    with socket.create_connection((ip, port), timeout=delai) as s:
        s.sendall(trame)
        rep = s.recv(260)
    if rep[7] & 0x80:
        codes = {1: "fonction non gérée", 2: "adresse invalide", 3: "valeur invalide",
                 11: "équipement injoignable derrière la passerelle"}
        raise RuntimeError(f"exception Modbus 0x{rep[8]:02X} ({codes.get(rep[8], '?')})")
    return struct.unpack(f">{qte}H", rep[9:9 + 2 * qte])


def verifier_modbus(ip="127.0.10.11", port=5020):
    titre(f"Compteurs électriques — Modbus TCP {ip}:{port}")
    if not test_tcp(ip, port):
        return
    for unit, ordre in ((1, "ABCD"), (2, "ABCD"), (3, "CDAB"), (7, "ABCD")):
        try:
            t0 = time.perf_counter()
            r = modbus_lire(ip, port, unit, 0, 14)
            ms = (time.perf_counter() - t0) * 1000
            def u32(a, b):
                return (a << 16 | b) if ordre == "ABCD" else (b << 16 | a)
            index = u32(r[0], r[1]) / 10
            puissance = u32(r[2], r[3])
            faux = (r[0] << 16 | r[1]) / 10
            def nb(x):
                return f"{x:,.1f}".replace(",", " ")
            ok(f"unit {unit} : index {nb(index)} kWh, {puissance / 1000:.1f} kW, "
               f"{r[4] / 10:.0f} V, statut {r[12]:#05b} ({ms:.0f} ms)")
            if ordre == "CDAB":
                attention(f"unit {unit} est en ordre CDAB : lu en ABCD, l'index vaudrait {nb(faux)} kWh !")
        except Exception as e:
            (attention if unit == 7 else ko)(f"unit {unit} : {e}" + ("  ← normal, unit 7 n'existe pas" if unit == 7 else ""))


# ---------------------------------------------------------------- MQTT
def verifier_mqtt(ip="127.0.10.12", port=1883, attente=8):
    titre(f"Compteurs d'eau — MQTT {ip}:{port}")
    if not test_tcp(ip, port):
        return
    import paho.mqtt.client as mqtt
    recus = {}
    c = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2, client_id="larka-verif")

    def on_msg(_c, _u, m):
        try:
            d = json.loads(m.payload)
            recus[d["devEUI"]] = d
        except Exception:
            attention(f"trame illisible sur {m.topic}")
    c.on_message = on_msg
    c.connect(ip, port)
    c.subscribe("larka/eau/+/up", qos=1)
    c.loop_start()
    ok(f"connecté au broker, écoute de larka/eau/+/up pendant {attente} s…")
    time.sleep(attente)
    c.loop_stop()
    c.disconnect()
    if not recus:
        attention("aucune trame reçue : intervalle des compteurs plus long que l'attente ? (--intervalle)")
    for d in recus.values():
        alertes = (" ⚠ FUITE" if d["alarme_fuite"] else "") + (" ⚠ BATTERIE" if d["batterie_pct"] < 20 else "")
        ok(f"{d['compteur']:<24} {d['index_m3']:>11.3f} m³  {d['debit_lh']:>7.1f} L/h  "
           f"bat {d['batterie_pct']}%  rssi {d['rssi']}{alertes}")


# ---------------------------------------------------------------- BACNET
async def _bacnet(ip, port):
    from bacpypes3.apdu import ErrorRejectAbortNack
    from bacpypes3.app import Application
    from bacpypes3.local.device import DeviceObject
    from bacpypes3.local.networkport import NetworkPortObject
    from bacpypes3.pdu import Address

    # le client écoute sur un AUTRE port UDP que l'automate (même machine)
    dev = DeviceObject(objectIdentifier=("device", 999001), objectName="Larka-Verif", vendorIdentifier=999)
    np_ = NetworkPortObject("127.0.10.99/32:47809", objectIdentifier=("network-port", 1),
                            objectName="NP1", networkNumberQuality="unknown")
    app = Application.from_object_list([dev, np_])
    cible = Address(f"{ip}:{port}")
    await asyncio.sleep(0.3)
    try:
        t0 = time.perf_counter()
        nom = await asyncio.wait_for(app.read_property(cible, "device,1301", "object-name"), 3)
        ok(f"protocole : automate « {nom} » répond ({(time.perf_counter() - t0) * 1000:.0f} ms)")
    except (asyncio.TimeoutError, ErrorRejectAbortNack) as e:
        ko(f"protocole : pas de réponse BACnet ({type(e).__name__}) → simulateur arrêté, pare-feu UDP {port}, "
           "ou mauvais n° de device")
        app.close()
        return
    for n in (1, 2, 3):
        nom_t = await app.read_property(cible, f"analog-input,{n}", "object-name")
        t = await app.read_property(cible, f"analog-input,{n}", "present-value")
        fiab = await app.read_property(cible, f"analog-input,{n}", "reliability")
        c = await app.read_property(cible, f"analog-value,{n}", "present-value")
        mode = await app.read_property(cible, f"multi-state-value,{n}", "present-value")
        defaut = await app.read_property(cible, f"binary-value,{n}", "present-value")
        msg = f"{nom_t:<20} {t:6.2f} °C  consigne {c:.1f}  mode {mode}  défaut {defaut}"
        if str(fiab) != "no-fault-detected":
            attention(msg + f"  ← capteur non fiable ({fiab})")
        else:
            ok(msg)
    h = await app.read_property(cible, "analog-input,30", "present-value")
    d = await app.read_property(cible, "analog-input,31", "present-value")
    ok(f"PAC : {h:.1f} h de fonctionnement, {int(d)} démarrages")
    try:
        await app.write_property(cible, "analog-input,1", "present-value", 5.0)
        ko("droits : l'écriture sur une entrée a été ACCEPTÉE (anormal)")
    except ErrorRejectAbortNack:
        ok("droits : écriture sur une entrée refusée par l'automate (normal)")
    attention("BACnet n'a aucune authentification : la protection des écritures doit être dans Larka")
    app.close()


def verifier_bacnet(ip="127.0.10.13", port=47808):
    titre(f"Chauffage / clim — BACnet/IP {ip}:{port} (UDP)")
    attention("UDP : « sans réponse » ne distingue pas pare-feu et équipement éteint, seule la réponse BACnet fait foi")
    asyncio.run(_bacnet(ip, port))


# ---------------------------------------------------------------- OCPP
def verifier_ocpp(ip="127.0.10.14", port=9000):
    titre(f"Bornes de recharge — superviseur OCPP ws://{ip}:{port}")
    if test_tcp(ip, port):
        ok("le superviseur écoute : en OCPP ce sont les bornes qui viennent à lui")
        attention("avec Larka : Bâtiments → Connexions → onglet « Bornes de recharge » (déclarez BORNE-01 à 03) ; "
                  "sans Larka : logs/csms.log, ou csms_test.py au premier plan (commande « liste »)")


# ---------------------------------------------------------------- REST
def verifier_rest(ip="127.0.10.15", port=8080):
    titre(f"Superviseur GTB — API REST http://{ip}:{port}")
    if not test_tcp(ip, port):
        return
    sans_proxy = urllib.request.build_opener(urllib.request.ProxyHandler({}))
    base = f"http://{ip}:{port}/api/v1"

    def get(chemin, jeton=None, delai=5):
        req = urllib.request.Request(base + chemin)
        if jeton:
            req.add_header("Authorization", f"Bearer {jeton}")
        t0 = time.perf_counter()
        with sans_proxy.open(req, timeout=delai) as r:
            return json.load(r), (time.perf_counter() - t0) * 1000

    try:
        get("/points")
        ko("droits : accès SANS jeton accepté (anormal)")
    except urllib.error.HTTPError as e:
        ok(f"droits : sans jeton → HTTP {e.code} (normal)")
    for essai in range(3):
        try:
            pts, ms = get("/points", "lecture-7f3a9c")
            ok(f"protocole : {len(pts)} points lus ({ms:.0f} ms)")
            for p in pts:
                if p["id"].split(".")[1] in ("DP_FILTRE", "HEURES_BRULEUR", "NB_DEMARRAGES", "PRESSION", "INDEX_GAZ"):
                    q = "" if p["qualite"] == "bonne" else f"  ← qualité {p['qualite']}"
                    ok(f"{p['id']:<26} {p['valeur']} {p['unite'] or ''}{q}")
            alarmes, _ = get("/alarmes", "lecture-7f3a9c")
            for a in alarmes:
                attention(f"alarme {a['equipement']} [{a['gravite']}] {a['message']}")
            if not alarmes:
                ok("aucune alarme active")
            break
        except urllib.error.HTTPError as e:
            attention(f"essai {essai + 1} : HTTP {e.code} (aléa du simulateur), nouvel essai…")
        except (TimeoutError, urllib.error.URLError) as e:
            attention(f"essai {essai + 1} : délai dépassé ({e}), nouvel essai…")


# ---------------------------------------------------------------- CSV
def verifier_csv(dossier=None):
    dossier = dossier or os.environ.get("SIM_CSV_DOSSIER") or "./export_gtb"
    titre(f"Ancienne GTB — fichiers CSV dans {Path(dossier).resolve()}")
    fichiers = sorted(Path(dossier).glob("GTB_EXPORT_*.csv"))
    if not fichiers:
        ko("aucun fichier : le simulateur tourne-t-il ? le dossier est-il le bon ?")
        return
    f = fichiers[-1]
    age = time.time() - f.stat().st_mtime
    if age < 5:
        attention(f"{f.name} modifié il y a {age:.1f} s : peut-être en cours d'écriture, on prend le précédent")
        if len(fichiers) < 2:
            return
        f = fichiers[-2]
    ok(f"fichier : {f.name} ({len(fichiers)} au total)")
    brut = f.read_bytes()
    try:
        brut.decode("utf-8")
        texte = brut.decode("utf-8")
    except UnicodeDecodeError:
        texte = brut.decode("cp1252")
        attention("encodage Windows (cp1252), pas UTF-8 : à convertir")
    lignes = texte.splitlines()
    debut = next((i for i, l in enumerate(lignes) if l.startswith("Date;")), None)
    if debut is None:
        ko("ligne d'en-tête « Date;… » introuvable : fichier tronqué ou format inattendu")
        return
    if debut:
        attention(f"{debut} ligne(s) parasite(s) avant les noms de colonnes")
    lecteur = csv.DictReader(io.StringIO("\n".join(lignes[debut:])), delimiter=";")
    n = 0
    for row in lecteur:
        if None in row.values() or len(row) != 7:
            attention(f"ligne incomplète (fichier tronqué ?) : {';'.join(v or '' for v in row.values())}")
            continue
        try:
            val = float(row["Valeur"].replace(",", "."))
            (ok if row["Etat"] == "Normal" else attention)(
                f"{row['Repère']:<9} {row['Libellé']:<34} {val} {row['Unité']}  [{row['Etat']}]")
            n += 1
        except ValueError:
            attention(f"{row['Repère']:<9} valeur non numérique « {row['Valeur']} » [{row['Etat']}]")
    ok(f"{n} valeur(s) exploitable(s)")


def main():
    quoi = sys.argv[1] if len(sys.argv) > 1 else "tout"
    tests = {"modbus": verifier_modbus, "mqtt": verifier_mqtt, "bacnet": verifier_bacnet,
             "ocpp": verifier_ocpp, "rest": verifier_rest, "csv": verifier_csv}
    for nom, fn in tests.items():
        if quoi in ("tout", nom):
            try:
                fn()
            except Exception as e:
                ko(f"erreur inattendue : {type(e).__name__} {e}")
    print()


if __name__ == "__main__":
    main()

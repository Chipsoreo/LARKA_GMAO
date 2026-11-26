#!/usr/bin/env python3
# SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
# SPDX-License-Identifier: LicenseRef-Larka-Proprietary
"""
Simulateur — Superviseur GTB avec API REST (JSON)
=================================================
Beaucoup de GTB récentes exposent une API HTTP : Larka n'a alors pas à parler
BACnet/Modbus, il interroge le superviseur. Ici : une CTA, une chaudière gaz et
un groupe froid.

Authentification : jeton dans l'en-tête  Authorization: Bearer <jeton>
  jeton LECTURE   : lecture-7f3a9c       (GET uniquement)
  jeton ÉCRITURE  : ecriture-c41d08      (GET + PUT des consignes)

Points de terminaison :
  GET  /api/v1/equipements           liste des équipements
  GET  /api/v1/points                tous les points (valeur, unité, qualité, horodatage)
  GET  /api/v1/points/<id>           un point, ex. CHAUD-01.HEURES_BRULEUR
  PUT  /api/v1/points/<id>           {"valeur": 19.5}  → consignes uniquement (jeton écriture)
  GET  /api/v1/alarmes               alarmes actives

Pour la GMAO, les points intéressants :
  CTA-01.DP_FILTRE       perte de charge du filtre (Pa), monte avec le temps → alarme > 250 Pa
  CHAUD-01.HEURES_BRULEUR / NB_DEMARRAGES   compteurs → maintenance conditionnelle
  CHAUD-01.PRESSION      baisse lentement (micro-fuite) → alarme < 1,0 bar
  CHAUD-01.INDEX_GAZ     index compteur gaz (m³)

Aléas : HTTP 500, réponses lentes (pour tester les délais d'attente), fenêtre de
maintenance HTTP 503, point de qualité "mauvaise", défaut brûleur, défaut HP.
Anomalies à la main :
  kill -USR1 <pid>  → défaut brûleur chaudière
  kill -USR2 <pid>  → filtre de CTA brutalement colmaté

Usage : python3 superviseur_rest.py [--ip 127.0.10.15] [--port 8080] [--accel 60]
"""
import argparse
import json
import random
import threading
import time
from datetime import datetime
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

from commun import log, tirage, bruit, facteur_occupation, temperature_exterieure, brancher_signaux

NOM = "GTB/REST"
JETONS = {"lecture-7f3a9c": "lecture", "ecriture-c41d08": "ecriture"}


def point(pid, nom, valeur, unite, ecriture=False, mini=None, maxi=None):
    return {"id": pid, "nom": nom, "valeur": valeur, "unite": unite, "qualite": "bonne",
            "ecriture": ecriture, "min": mini, "max": maxi, "horodatage": None}


class GTB:
    def __init__(self, accel):
        self.accel = accel
        self.verrou = threading.Lock()
        self.maintenance_jusqua = 0
        self.equipements = {
            "CTA-01": {"nom": "Centrale de traitement d'air — Bât. A", "type": "CTA"},
            "CHAUD-01": {"nom": "Chaudière gaz condensation 250 kW", "type": "Chaudière"},
            "GF-01": {"nom": "Groupe froid 120 kW", "type": "Groupe froid"},
        }
        P = {}
        for p in [
            point("CTA-01.MARCHE", "CTA en marche", True, None),
            point("CTA-01.T_SOUFFLAGE", "Température soufflage", 19.0, "°C"),
            point("CTA-01.T_REPRISE", "Température reprise", 21.5, "°C"),
            point("CTA-01.CONSIGNE_SOUFFLAGE", "Consigne soufflage", 19.0, "°C", True, 14, 24),
            point("CTA-01.VITESSE_VENTIL", "Vitesse ventilateur", 70.0, "%"),
            point("CTA-01.DP_FILTRE", "Perte de charge filtre", 142.0, "Pa"),
            point("CHAUD-01.MARCHE", "Brûleur en marche", False, None),
            point("CHAUD-01.T_DEPART", "Température départ", 62.0, "°C"),
            point("CHAUD-01.T_RETOUR", "Température retour", 48.0, "°C"),
            point("CHAUD-01.CONSIGNE_DEPART", "Consigne départ", 65.0, "°C", True, 40, 80),
            point("CHAUD-01.PRESSION", "Pression circuit", 1.65, "bar"),
            point("CHAUD-01.HEURES_BRULEUR", "Heures de fonctionnement brûleur", 21437.2, "h"),
            point("CHAUD-01.NB_DEMARRAGES", "Nombre de démarrages brûleur", 96210, None),
            point("CHAUD-01.INDEX_GAZ", "Index compteur gaz", 584213.42, "m³"),
            point("CHAUD-01.DEFAUT", "Défaut brûleur", False, None),
            point("GF-01.MARCHE", "Groupe froid en marche", False, None),
            point("GF-01.T_EAU_GLACEE", "Température eau glacée", 7.2, "°C"),
            point("GF-01.PUISSANCE_ELEC", "Puissance électrique absorbée", 0.0, "kW"),
            point("GF-01.DEFAUT_HP", "Défaut haute pression", False, None),
        ]:
            P[p["id"]] = p
        self.p = P
        self.defaut_bruleur = 0
        self.defaut_hp = 0

    def v(self, pid):
        return self.p[pid]["valeur"]

    def s(self, pid, val):
        self.p[pid]["valeur"] = val

    def tick(self, dt):
        h = dt * self.accel / 3600                     # heures simulées écoulées
        t_ext = temperature_exterieure()
        occ = facteur_occupation()
        with self.verrou:
            # --- CTA
            marche = occ > 0.3
            self.s("CTA-01.MARCHE", marche)
            self.s("CTA-01.VITESSE_VENTIL", round(bruit(40 + 50 * occ, 2), 1) if marche else 0.0)
            c = self.v("CTA-01.CONSIGNE_SOUFFLAGE")
            self.s("CTA-01.T_SOUFFLAGE", round(bruit(c if marche else 18 + (t_ext - 18) * 0.2, 1.5), 1))
            self.s("CTA-01.T_REPRISE", round(bruit(21 + occ * 2, 1), 1))
            if marche:
                self.s("CTA-01.DP_FILTRE", round(self.v("CTA-01.DP_FILTRE") + 0.08 * h * random.uniform(0.5, 2), 2))
            # --- Chaudière
            besoin = t_ext < 16
            if self.defaut_bruleur:
                self.defaut_bruleur -= 1
                bruleur = False
            else:
                bruleur = besoin and (self.v("CHAUD-01.T_DEPART") < self.v("CHAUD-01.CONSIGNE_DEPART") - 3
                                      or (self.v("CHAUD-01.MARCHE")
                                          and self.v("CHAUD-01.T_DEPART") < self.v("CHAUD-01.CONSIGNE_DEPART") + 2))
            if bruleur and not self.v("CHAUD-01.MARCHE"):
                self.s("CHAUD-01.NB_DEMARRAGES", self.v("CHAUD-01.NB_DEMARRAGES") + 1)
            self.s("CHAUD-01.MARCHE", bruleur)
            self.s("CHAUD-01.DEFAUT", bool(self.defaut_bruleur))
            td = self.v("CHAUD-01.T_DEPART")
            td += (25 if bruleur else -12) * h * 6 + random.gauss(0, 0.1)
            td = max(20, min(85, td))
            self.s("CHAUD-01.T_DEPART", round(td, 1))
            self.s("CHAUD-01.T_RETOUR", round(td - (14 if bruleur else 4) + random.gauss(0, 0.2), 1))
            if bruleur:
                self.s("CHAUD-01.HEURES_BRULEUR", round(self.v("CHAUD-01.HEURES_BRULEUR") + h, 3))
                self.s("CHAUD-01.INDEX_GAZ", round(self.v("CHAUD-01.INDEX_GAZ") + 26 * h * random.uniform(0.6, 1.0), 2))
            self.s("CHAUD-01.PRESSION", round(max(0.3, self.v("CHAUD-01.PRESSION") - 0.002 * h + random.gauss(0, 0.003)), 3))
            # --- Groupe froid
            besoin_froid = t_ext > 24 and occ > 0.3
            if self.defaut_hp:
                self.defaut_hp -= 1
            gf = besoin_froid and not self.defaut_hp
            self.s("GF-01.MARCHE", gf)
            self.s("GF-01.DEFAUT_HP", bool(self.defaut_hp))
            self.s("GF-01.PUISSANCE_ELEC", round(bruit(38 * occ, 5), 1) if gf else 0.0)
            self.s("GF-01.T_EAU_GLACEE", round(bruit(7.0 if gf else 12 + (t_ext - 12) * 0.3, 2), 1))
            # --- qualité et horodatage
            maintenant = datetime.now().astimezone().isoformat(timespec="seconds")
            for p in self.p.values():
                p["horodatage"] = maintenant
                if p["qualite"] == "mauvaise" and tirage(0.2):
                    p["qualite"] = "bonne"
            if tirage(0.004):
                cible = random.choice([k for k in self.p if self.p[k]["unite"] == "°C"])
                self.p[cible]["qualite"] = "mauvaise"
                log(NOM, f"aléa : qualité MAUVAISE sur {cible} (capteur)", "aleas")
            if tirage(0.0008):
                self.defaut_bruleur = random.randint(20, 90)
                log(NOM, "aléa : DÉFAUT brûleur chaudière", "aleas")
            if tirage(0.0005) and t_ext > 20:
                self.defaut_hp = random.randint(20, 90)
                log(NOM, "aléa : défaut haute pression groupe froid", "aleas")

    def alarmes(self):
        a = []
        if self.v("CTA-01.DP_FILTRE") > 250:
            a.append({"equipement": "CTA-01", "code": "FILTRE_ENCRASSE", "gravite": "mineure",
                      "message": f"Filtre encrassé ({self.v('CTA-01.DP_FILTRE'):.0f} Pa > 250 Pa)"})
        if self.v("CHAUD-01.PRESSION") < 1.0:
            a.append({"equipement": "CHAUD-01", "code": "PRESSION_BASSE", "gravite": "majeure",
                      "message": f"Pression basse circuit ({self.v('CHAUD-01.PRESSION'):.2f} bar)"})
        if self.v("CHAUD-01.DEFAUT"):
            a.append({"equipement": "CHAUD-01", "code": "DEFAUT_BRULEUR", "gravite": "majeure",
                      "message": "Défaut brûleur — mise en sécurité"})
        if self.v("GF-01.DEFAUT_HP"):
            a.append({"equipement": "GF-01", "code": "DEFAUT_HP", "gravite": "majeure",
                      "message": "Défaut haute pression fluide frigorigène"})
        for p in self.p.values():
            if p["qualite"] == "mauvaise":
                a.append({"equipement": p["id"].split(".")[0], "code": "CAPTEUR", "gravite": "mineure",
                          "message": f"Capteur hors service : {p['nom']}"})
        return a


def fabriquer_handler(gtb):
    class Handler(BaseHTTPRequestHandler):
        server_version = "GTB-Sim/2.4"

        def log_message(self, *_):
            pass

        def repondre(self, code, corps):
            data = json.dumps(corps, ensure_ascii=False, indent=2).encode()
            self.send_response(code)
            self.send_header("Content-Type", "application/json; charset=utf-8")
            self.send_header("Content-Length", str(len(data)))
            self.end_headers()
            self.wfile.write(data)

        def droits(self):
            entete = self.headers.get("Authorization", "")
            return JETONS.get(entete[7:]) if entete.startswith("Bearer ") else None

        def aleas_http(self):
            """True si un aléa a déjà répondu à la place du traitement normal."""
            if time.time() < gtb.maintenance_jusqua:
                self.repondre(503, {"erreur": "Superviseur en maintenance"})
                return True
            if tirage(0.0015):
                gtb.maintenance_jusqua = time.time() + random.randint(30, 90)
                log(NOM, "aléa : fenêtre de MAINTENANCE (HTTP 503)", "aleas")
                self.repondre(503, {"erreur": "Superviseur en maintenance"})
                return True
            if tirage(0.02):
                log(NOM, f"aléa : HTTP 500 sur {self.path}", "aleas")
                self.repondre(500, {"erreur": "Erreur interne du superviseur"})
                return True
            if tirage(0.03):
                d = random.uniform(3, 9)
                log(NOM, f"aléa : réponse LENTE ({d:.1f} s) sur {self.path}", "aleas")
                time.sleep(d)
            return False

        def do_GET(self):
            role = self.droits()
            if not role:
                log(NOM, f"401 : jeton absent ou invalide depuis {self.client_address[0]}", "aleas")
                return self.repondre(401, {"erreur": "Jeton manquant ou invalide"})
            if self.aleas_http():
                return
            with gtb.verrou:
                if self.path == "/api/v1/equipements":
                    return self.repondre(200, [{"id": k, **v} for k, v in gtb.equipements.items()])
                if self.path == "/api/v1/points":
                    return self.repondre(200, list(gtb.p.values()))
                if self.path.startswith("/api/v1/points/"):
                    pid = self.path.split("/")[-1]
                    if pid in gtb.p:
                        return self.repondre(200, gtb.p[pid])
                    return self.repondre(404, {"erreur": f"Point inconnu : {pid}"})
                if self.path == "/api/v1/alarmes":
                    return self.repondre(200, gtb.alarmes())
            self.repondre(404, {"erreur": "Route inconnue"})

        def do_PUT(self):
            role = self.droits()
            if not role:
                return self.repondre(401, {"erreur": "Jeton manquant ou invalide"})
            if role != "ecriture":
                log(NOM, f"403 : écriture refusée (jeton lecture) sur {self.path}", "aleas")
                return self.repondre(403, {"erreur": "Ce jeton n'autorise pas l'écriture"})
            if not self.path.startswith("/api/v1/points/"):
                return self.repondre(404, {"erreur": "Route inconnue"})
            pid = self.path.split("/")[-1]
            try:
                corps = json.loads(self.rfile.read(int(self.headers.get("Content-Length", 0))) or b"{}")
                valeur = float(corps["valeur"])
            except (ValueError, KeyError, TypeError):
                return self.repondre(400, {"erreur": 'Corps attendu : {"valeur": <nombre>}'})
            with gtb.verrou:
                p = gtb.p.get(pid)
                if not p:
                    return self.repondre(404, {"erreur": f"Point inconnu : {pid}"})
                if not p["ecriture"]:
                    return self.repondre(403, {"erreur": f"Point en lecture seule : {pid}"})
                if not p["min"] <= valeur <= p["max"]:
                    return self.repondre(422, {"erreur": f"Valeur hors plage [{p['min']} ; {p['max']}]"})
                ancienne = p["valeur"]
                p["valeur"] = valeur
            log(NOM, f"écriture {pid} : {ancienne} → {valeur} {p['unite']} (depuis {self.client_address[0]})", "ok")
            self.repondre(200, p)

    return Handler


def main():
    ap = argparse.ArgumentParser(description="Simulateur superviseur GTB REST")
    ap.add_argument("--ip", default="127.0.10.15")
    ap.add_argument("--port", type=int, default=8080)
    ap.add_argument("--accel", type=float, default=60.0, help="accélère les compteurs (60 = 1 h par minute)")
    a = ap.parse_args()

    gtb = GTB(a.accel)

    def defaut_bruleur():
        gtb.defaut_bruleur = random.randint(60, 200)
        log(NOM, "ANOMALIE : défaut brûleur chaudière forcé", "anomalie")

    def filtre_colmate():
        gtb.s("CTA-01.DP_FILTRE", random.uniform(260, 340))
        log(NOM, f"ANOMALIE : filtre CTA colmaté ({gtb.v('CTA-01.DP_FILTRE'):.0f} Pa)", "anomalie")

    brancher_signaux(defaut_bruleur, filtre_colmate)

    def boucle():
        n = 0
        while True:
            time.sleep(2)
            gtb.tick(2)
            n += 1
            if n % 15 == 0:
                log(NOM, f"CTA {'ON' if gtb.v('CTA-01.MARCHE') else 'off'} filtre {gtb.v('CTA-01.DP_FILTRE'):.0f} Pa | "
                         f"chaudière {'ON' if gtb.v('CHAUD-01.MARCHE') else 'off'} {gtb.v('CHAUD-01.T_DEPART')}°C "
                         f"{gtb.v('CHAUD-01.PRESSION'):.2f} bar {gtb.v('CHAUD-01.HEURES_BRULEUR'):.1f} h | "
                         f"GF {'ON' if gtb.v('GF-01.MARCHE') else 'off'} | {len(gtb.alarmes())} alarme(s)")

    threading.Thread(target=boucle, daemon=True).start()
    serveur = ThreadingHTTPServer((a.ip, a.port), fabriquer_handler(gtb))
    log(NOM, f"API REST sur http://{a.ip}:{a.port}/api/v1/ — jetons : lecture-7f3a9c / ecriture-c41d08", "titre")
    try:
        serveur.serve_forever()
    except KeyboardInterrupt:
        pass


if __name__ == "__main__":
    main()

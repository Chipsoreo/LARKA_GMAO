#!/usr/bin/env python3
# SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
# SPDX-License-Identifier: LicenseRef-Larka-Proprietary
"""
Simulateur — Compteurs électriques en Modbus TCP
================================================
Une passerelle Modbus (une IP) avec 3 compteurs derrière, chacun sur son "unit id".
Serveur Modbus écrit à la main (Python standard, aucune dépendance) : c'est
exactement ce que fera un pilote PHP côté Larka, en sens inverse.

Fonctions Modbus gérées : 03 (holding registers), 04 (input registers, même
table), 06 et 16 (écriture, refusée hors registre de RAZ).

Carte des registres (identique pour chaque compteur, adresses 0-based) :
  0-1  Index énergie active    kWh x10   uint32  (2 registres !)
  2-3  Puissance active        W         uint32
  4-6  Tensions L1/L2/L3       V x10     uint16
  7-9  Courants L1/L2/L3       A x10     uint16
  10   Facteur de puissance    x1000     uint16
  11   Fréquence               Hz x100   uint16
  12   Statut (bits)           b0 défaut interne, b1 surtension, b2 sous-tension
  13   N° de série (4 derniers chiffres)

PIÈGE VOLONTAIRE : le compteur unit 3 range ses mots en ordre inversé (CDAB,
"mot de poids faible en premier"), comme certains fabricants. Les unit 1 et 2
sont en ABCD (poids fort en premier).

Aléas : bruit, profil jour/nuit/week-end, pics de puissance, gel des valeurs,
compteur injoignable derrière la passerelle (exception 0x0B), sous-tension.
Anomalies à la main :
  kill -USR1 <pid>  → remplacement d'un compteur (index repart de presque 0)
  kill -USR2 <pid>  → saut d'index aberrant (glitch)

Usage : python3 compteurs_elec_modbus.py [--ip 127.0.10.11] [--port 5020] [--accel 1]
"""
import argparse
import asyncio
import random
import struct
import time

from commun import log, tirage, bruit, facteur_occupation, brancher_signaux

NOM = "ELEC/Modbus"

COMPTEURS = {
    1: {"nom": "TGBT général",  "p_min": 12000, "p_max": 65000, "index": 1845320.0, "ordre": "ABCD", "serie": 4127},
    2: {"nom": "Bâtiment A",    "p_min": 3000,  "p_max": 24000, "index": 512877.5,  "ordre": "ABCD", "serie": 8810},
    3: {"nom": "Restauration",  "p_min": 1500,  "p_max": 38000, "index": 298004.2,  "ordre": "CDAB", "serie": 1093},
}

EXC_FONCTION, EXC_ADRESSE, EXC_VALEUR, EXC_INJOIGNABLE = 0x01, 0x02, 0x03, 0x0B
NB_REGISTRES = 14


class Compteur:
    def __init__(self, uid, cfg):
        self.uid, self.cfg = uid, cfg
        self.nom = cfg["nom"]
        self.index_kwh = cfg["index"]
        self.puissance = cfg["p_min"]
        self.registres = [0] * NB_REGISTRES
        self.gele_jusqua = 0
        self.injoignable_jusqua = 0
        self.pic_restant = 0
        self.sous_tension = 0
        self.maj_registres()

    def uint32(self, valeur):
        haut, bas = (int(valeur) >> 16) & 0xFFFF, int(valeur) & 0xFFFF
        return [haut, bas] if self.cfg["ordre"] == "ABCD" else [bas, haut]

    def tick(self, dt, accel):
        maintenant = time.time()
        if maintenant < self.gele_jusqua:
            return                                   # valeurs figées : rien ne bouge
        occ = facteur_occupation()
        if self.uid == 3:                            # la restauration pique à midi
            from datetime import datetime
            h = datetime.now().hour
            occ = 0.95 if 11 <= h < 14 else occ * 0.4
        cible = self.cfg["p_min"] + (self.cfg["p_max"] - self.cfg["p_min"]) * occ
        if self.pic_restant > 0:
            cible *= random.uniform(1.6, 2.2)
            self.pic_restant -= 1
        self.puissance = max(0, bruit(0.7 * self.puissance + 0.3 * cible, 3))
        self.index_kwh += self.puissance * dt * accel / 3600 / 1000
        if self.sous_tension > 0:
            self.sous_tension -= 1
        self.maj_registres()

    def maj_registres(self):
        tension = 2050 if self.sous_tension else 2300
        p = self.puissance
        courant = p / (3 * 230 * 0.92) if p else 0
        r = []
        r += self.uint32(round(self.index_kwh * 10))
        r += self.uint32(round(p))
        r += [int(bruit(tension, 0.4)) for _ in range(3)]
        r += [int(bruit(courant * 10, 4)) for _ in range(3)]
        r += [int(bruit(920, 1.5)), int(bruit(5000, 0.02))]
        statut = (0b100 if self.sous_tension else 0)
        r += [statut, self.cfg["serie"]]
        self.registres = [max(0, min(0xFFFF, v)) for v in r]


class Passerelle:
    def __init__(self, accel):
        self.accel = accel
        self.compteurs = {uid: Compteur(uid, cfg) for uid, cfg in COMPTEURS.items()}

    # ---------- aléas automatiques ----------
    async def boucle_simulation(self):
        dt = 2.0
        while True:
            await asyncio.sleep(dt)
            for c in self.compteurs.values():
                maintenant = time.time()
                if tirage(0.004) and c.pic_restant == 0:
                    c.pic_restant = random.randint(2, 6)
                    log(NOM, f"aléa : pic de puissance sur « {c.nom} » (unit {c.uid})", "aleas")
                if tirage(0.0015) and maintenant > c.gele_jusqua:
                    duree = random.randint(30, 120)
                    c.gele_jusqua = maintenant + duree
                    log(NOM, f"aléa : valeurs GELÉES {duree}s sur « {c.nom} » (unit {c.uid})", "aleas")
                if tirage(0.001) and maintenant > c.injoignable_jusqua:
                    duree = random.randint(20, 90)
                    c.injoignable_jusqua = maintenant + duree
                    log(NOM, f"aléa : « {c.nom} » INJOIGNABLE {duree}s (exception 0x0B)", "aleas")
                if tirage(0.001):
                    c.sous_tension = random.randint(2, 5)
                    log(NOM, f"aléa : sous-tension sur « {c.nom} »", "aleas")
                c.tick(dt, self.accel)

    async def boucle_affichage(self):
        while True:
            await asyncio.sleep(30)
            etat = " | ".join(f"u{c.uid} {c.index_kwh:,.1f} kWh {c.puissance/1000:.1f} kW"
                              for c in self.compteurs.values())
            log(NOM, etat.replace(",", " "))

    # ---------- anomalies manuelles ----------
    def remplacement_compteur(self):
        c = random.choice(list(self.compteurs.values()))
        ancien = c.index_kwh
        c.index_kwh = random.uniform(0, 15)
        c.maj_registres()
        log(NOM, f"ANOMALIE : compteur « {c.nom} » remplacé ({ancien:.1f} → {c.index_kwh:.1f} kWh)", "anomalie")

    def saut_index(self):
        c = random.choice(list(self.compteurs.values()))
        saut = random.uniform(5000, 50000)
        c.index_kwh += saut
        c.maj_registres()
        log(NOM, f"ANOMALIE : saut d'index +{saut:.0f} kWh sur « {c.nom} »", "anomalie")

    # ---------- protocole Modbus TCP ----------
    def traiter(self, uid, pdu):
        """Retourne la PDU de réponse (sans en-tête MBAP)."""
        fc = pdu[0]
        def exception(code):
            return bytes([fc | 0x80, code])

        c = self.compteurs.get(uid)
        if c is None or time.time() < c.injoignable_jusqua:
            return exception(EXC_INJOIGNABLE)

        if fc in (3, 4):
            if len(pdu) < 5:
                return exception(EXC_VALEUR)
            debut, qte = struct.unpack(">HH", pdu[1:5])
            if not 1 <= qte <= 125:
                return exception(EXC_VALEUR)
            if debut + qte > NB_REGISTRES:
                return exception(EXC_ADRESSE)
            vals = c.registres[debut:debut + qte]
            return bytes([fc, 2 * qte]) + struct.pack(f">{qte}H", *vals)
        if fc in (6, 16):
            return exception(EXC_ADRESSE)       # compteur en lecture seule
        return exception(EXC_FONCTION)

    async def client(self, reader, writer):
        pair = writer.get_extra_info("peername")
        log(NOM, f"connexion de {pair[0]}:{pair[1]}", "ok")
        try:
            while True:
                entete = await reader.readexactly(7)
                tid, proto, longueur, uid = struct.unpack(">HHHB", entete)
                pdu = await reader.readexactly(longueur - 1)
                if proto != 0:
                    break
                rep = self.traiter(uid, pdu)
                writer.write(struct.pack(">HHHB", tid, 0, len(rep) + 1, uid) + rep)
                await writer.drain()
        except (asyncio.IncompleteReadError, ConnectionResetError):
            pass
        finally:
            writer.close()
            log(NOM, f"déconnexion de {pair[0]}:{pair[1]}")


async def main():
    ap = argparse.ArgumentParser(description="Simulateur compteurs électriques Modbus TCP")
    ap.add_argument("--ip", default="127.0.10.11")
    ap.add_argument("--port", type=int, default=5020, help="502 = port standard (nécessite root)")
    ap.add_argument("--accel", type=float, default=1.0, help="accélère l'accumulation d'énergie (x60 = 1 h par minute)")
    a = ap.parse_args()

    p = Passerelle(a.accel)
    brancher_signaux(p.remplacement_compteur, p.saut_index)
    serveur = await asyncio.start_server(p.client, a.ip, a.port)
    log(NOM, f"passerelle Modbus TCP sur {a.ip}:{a.port} — unit 1/2/3 (unit 3 en ordre CDAB)", "titre")
    async with serveur:
        await asyncio.gather(serveur.serve_forever(), p.boucle_simulation(), p.boucle_affichage())


if __name__ == "__main__":
    try:
        asyncio.run(main())
    except KeyboardInterrupt:
        pass

#!/usr/bin/env python3
# SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
# SPDX-License-Identifier: LicenseRef-Larka-Proprietary
"""
Simulateur — Ancienne GTB qui exporte des fichiers CSV
======================================================
Beaucoup de GTB anciennes (très fréquent dans le public) ne savent que déposer
un fichier dans un dossier partagé ou sur un FTP. Ce simulateur reproduit leurs
pièges habituels, volontairement :

  • encodage Windows (latin-1 / cp1252), pas UTF-8 ;
  • séparateur « ; » et virgule décimale (« 21,5 ») ;
  • date au format français JJ/MM/AAAA ;
  • 2 lignes d'en-tête « parasites » avant les noms de colonnes ;
  • fichier écrit EN DEUX FOIS (Larka ne doit pas le lire tant qu'il bouge) ;
  • aléas : fichier tronqué, point manquant, valeur texte (#####, N/A),
    export en retard, fichier en double.

Fichiers : <dossier>/GTB_EXPORT_AAAAMMJJ_HHMMSS.csv
Colonnes : Date;Heure;Repère;Libellé;Valeur;Unité;Etat

Anomalies à la main :
  kill -USR1 <pid>  → remplacement du compteur de calories (index repart de 0)
  kill -USR2 <pid>  → le prochain fichier sera corrompu

Usage : python3 export_csv_gtb.py [--dossier ./export_gtb] [--intervalle 300]
"""
import argparse
import os
import random
import time
from datetime import datetime
from pathlib import Path

from commun import log, tirage, bruit, facteur_occupation, temperature_exterieure, brancher_signaux

NOM = "GTB/CSV"


class VieilleGTB:
    def __init__(self):
        self.t_gymnase = 16.5
        self.t_ecs = 58.0
        self.index_gaz = 128440.7          # m³
        self.index_calories = 4512.884     # MWh (sous-station réseau de chaleur)
        self.index_eau_ecs = 9921.43       # m³
        self.corrompre = False

    def avancer(self, dt_h):
        t_ext = temperature_exterieure()
        occ = facteur_occupation()
        consigne = 18.0 if occ > 0.3 else 14.0
        self.t_gymnase += (0.25 * (t_ext - self.t_gymnase) + (3.0 if self.t_gymnase < consigne else 0)) * dt_h
        self.t_ecs += (-2.0 + (6.0 if self.t_ecs < 55 else 0)) * dt_h
        besoin = max(0.0, 16 - t_ext)
        self.index_gaz += besoin * 1.6 * dt_h * random.uniform(0.8, 1.2)
        self.index_calories += besoin * 0.018 * dt_h * random.uniform(0.8, 1.2)
        self.index_eau_ecs += 0.35 * occ * dt_h * random.uniform(0.5, 1.5)

    def lignes(self):
        m = datetime.now()
        d, h = m.strftime("%d/%m/%Y"), m.strftime("%H:%M:%S")
        def f(x, n=1):
            return f"{x:.{n}f}".replace(".", ",")
        pts = [
            ("GYM_TAMB", "Température ambiante gymnase", f(bruit(self.t_gymnase, 0.5)), "°C"),
            ("GYM_TEXT", "Température extérieure", f(temperature_exterieure()), "°C"),
            ("ECS_TBAL", "Température ballon ECS", f(bruit(self.t_ecs, 0.3)), "°C"),
            ("CHF2_GAZ", "Index compteur gaz chaufferie 2", f(self.index_gaz, 2), "m³"),
            ("SST_CALO", "Index calories sous-station", f(self.index_calories, 3), "MWh"),
            ("ECS_EAU", "Index compteur eau ECS", f(self.index_eau_ecs, 2), "m³"),
        ]
        sortie = []
        for rep, lib, val, unite in pts:
            etat = "Normal"
            if tirage(0.05):
                log(NOM, f"aléa : point {rep} absent de l'export", "aleas")
                continue
            if tirage(0.02):
                val, etat = random.choice(["#####", "N/A", "ERR"]), "Défaut"
                log(NOM, f"aléa : valeur non numérique pour {rep}", "aleas")
            if rep == "ECS_TBAL" and self.t_ecs < 50:
                etat = "Alarme"                       # risque légionelle
            sortie.append(f"{d};{h};{rep};{lib};{val};{unite};{etat}")
        return sortie


def ecrire(dossier, gtb):
    m = datetime.now()
    chemin = dossier / f"GTB_EXPORT_{m.strftime('%Y%m%d_%H%M%S')}.csv"
    entete = [f"Export automatique GTB - Site : Lycée Jean Moulin - Généré le {m.strftime('%d/%m/%Y %H:%M')}",
              "Automate : TREND IQ3 (simulé)",
              "Date;Heure;Repère;Libellé;Valeur;Unité;Etat"]
    lignes = entete + gtb.lignes()
    corps = "\r\n".join(lignes) + "\r\n"                  # fins de ligne Windows
    data = corps.encode("cp1252", errors="replace")

    if gtb.corrompre or tirage(0.03):
        gtb.corrompre = False
        data = data[: random.randint(len(data) // 3, len(data) - 10)]
        log(NOM, f"aléa : fichier TRONQUÉ {chemin.name}", "aleas")

    # écriture en deux temps, comme une GTB lente sur un partage réseau
    moitie = len(data) // 2
    with open(chemin, "wb") as fh:
        fh.write(data[:moitie])
        fh.flush()
        time.sleep(random.uniform(0.5, 2.5))
        fh.write(data[moitie:])
    log(NOM, f"fichier déposé : {chemin.name} ({len(lignes) - 3} points)")

    if tirage(0.01):
        double = chemin.with_name(chemin.stem + "_1.csv")
        double.write_bytes(data)
        log(NOM, f"aléa : fichier en DOUBLE {double.name}", "aleas")


def purger(dossier, garder=200):
    fichiers = sorted(dossier.glob("GTB_EXPORT_*.csv"))
    for f in fichiers[:-garder]:
        f.unlink(missing_ok=True)


def main():
    ap = argparse.ArgumentParser(description="Simulateur export CSV d'ancienne GTB")
    ap.add_argument("--dossier", default="./export_gtb")
    ap.add_argument("--intervalle", type=int, default=300, help="secondes entre deux exports (réel : 15 min à 1 jour)")
    ap.add_argument("--accel", type=float, default=12.0, help="accélération des compteurs")
    a = ap.parse_args()

    dossier = Path(a.dossier).resolve()
    dossier.mkdir(parents=True, exist_ok=True)
    gtb = VieilleGTB()

    def remplacement():
        log(NOM, f"ANOMALIE : compteur de calories remplacé ({gtb.index_calories:.3f} → 0 MWh)", "anomalie")
        gtb.index_calories = 0.0

    def corruption():
        gtb.corrompre = True
        log(NOM, "ANOMALIE : le prochain fichier sera corrompu", "anomalie")

    brancher_signaux(remplacement, corruption)
    log(NOM, f"export CSV toutes les {a.intervalle}s dans {dossier} (cp1252, « ; », virgule décimale)", "titre")
    try:
        while True:
            gtb.avancer(a.intervalle * a.accel / 3600)
            if tirage(0.03):
                log(NOM, "aléa : export EN RETARD (ce cycle est sauté)", "aleas")
            else:
                ecrire(dossier, gtb)
                purger(dossier)
            time.sleep(a.intervalle)
    except KeyboardInterrupt:
        pass


if __name__ == "__main__":
    main()

# SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
# SPDX-License-Identifier: LicenseRef-Larka-Proprietary
"""
Outils communs aux simulateurs Larka Connect.
- journalisation colorée
- aléas (probabilités, bruit)
- profils horaires réalistes (jour / nuit / week-end, saison)
- signaux pour provoquer des anomalies à la main (kill -USR1 / -USR2)
"""
import math
import random
import signal
import sys
from datetime import datetime

COULEURS = {
    "info": "\033[0;37m", "ok": "\033[0;32m", "aleas": "\033[0;33m",
    "anomalie": "\033[1;31m", "titre": "\033[1;36m", "fin": "\033[0m",
}


def log(nom, msg, niveau="info"):
    h = datetime.now().strftime("%H:%M:%S")
    c = COULEURS.get(niveau, "") if sys.stdout.isatty() else ""
    f = COULEURS["fin"] if c else ""
    print(f"{c}[{h}] [{nom}] {msg}{f}", flush=True)


def tirage(proba):
    """True avec la probabilité donnée (0..1)."""
    return random.random() < proba


def bruit(valeur, pct=2.0):
    """Ajoute un bruit gaussien de ±pct %."""
    return valeur * (1 + random.gauss(0, pct / 100))


def facteur_occupation(maintenant=None):
    """0..1 : occupation d'un bâtiment tertiaire selon l'heure et le jour."""
    m = maintenant or datetime.now()
    if m.weekday() >= 5:            # week-end
        return 0.15
    h = m.hour + m.minute / 60
    if h < 6 or h >= 21:            # nuit
        return 0.10
    if 6 <= h < 8:                  # montée
        return 0.10 + 0.8 * (h - 6) / 2
    if 18 <= h < 21:                # descente
        return 0.90 - 0.8 * (h - 18) / 3
    if 12 <= h < 13.5:              # pause midi
        return 0.70
    return 0.90


def temperature_exterieure(maintenant=None):
    """Température extérieure plausible (Orléans-like) : saison + cycle jour/nuit."""
    m = maintenant or datetime.now()
    jour = m.timetuple().tm_yday
    saison = 11.5 - 8.5 * math.cos(2 * math.pi * (jour - 15) / 365)   # ~3°C janv, ~20°C juil
    journee = 4.5 * math.sin(2 * math.pi * (m.hour + m.minute / 60 - 9) / 24)
    return round(saison + journee + random.gauss(0, 0.3), 1)


def brancher_signaux(sur_usr1=None, sur_usr2=None):
    """kill -USR1 <pid> / kill -USR2 <pid> pour provoquer des anomalies."""
    if sur_usr1:
        signal.signal(signal.SIGUSR1, lambda *_: sur_usr1())
    if sur_usr2:
        signal.signal(signal.SIGUSR2, lambda *_: sur_usr2())

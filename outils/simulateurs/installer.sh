#!/usr/bin/env bash
# SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
# SPDX-License-Identifier: LicenseRef-Larka-Proprietary
# Installation des simulateurs Larka Connect (Nobara / Fedora, marche aussi sur Debian/Ubuntu)
set -e
cd "$(dirname "$0")"
if ! command -v python3 >/dev/null; then
  echo "Python 3 absent → sudo dnf install python3"; exit 1
fi
python3 - <<'PY' || { echo "Python 3.10 minimum requis"; exit 1; }
import sys; sys.exit(0 if sys.version_info >= (3, 10) else 1)
PY
echo "→ Création de l'environnement virtuel .venv (n'impacte pas le Python du système)"
python3 -m venv .venv
.venv/bin/pip install --upgrade pip -q
echo "→ Installation des bibliothèques (BACnet, OCPP, MQTT)"
.venv/bin/pip install -r requirements.txt -q
chmod +x lancer.sh *.py
if ! command -v mosquitto >/dev/null 2>&1; then
  echo
  echo "ℹ Conseillé : le broker MQTT Mosquitto (plus robuste que le broker Python de secours)."
  echo "  sudo dnf install mosquitto      (inutile de l'activer : le lanceur démarre le sien)"
fi
echo
echo "✔ Prêt. Lance :   ./lancer.sh start   puis   ./lancer.sh verifier"

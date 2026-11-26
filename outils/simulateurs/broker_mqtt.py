#!/usr/bin/env python3
# SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
# SPDX-License-Identifier: LicenseRef-Larka-Proprietary
"""
Broker MQTT de test (amqtt, 100 % Python)
=========================================
Remplace un Mosquitto pour les essais. Si tu préfères Mosquitto :
    sudo dnf install mosquitto && mosquitto -p 1883
(et lance compteurs_eau_mqtt.py avec --broker 127.0.0.1)

Usage : python3 broker_mqtt.py [--ip 127.0.10.12] [--port 1883]
"""
import argparse
import asyncio
import logging

from amqtt.broker import Broker

from commun import log

NOM = "MQTT/broker"


async def main():
    ap = argparse.ArgumentParser(description="Broker MQTT de test")
    ap.add_argument("--ip", default="127.0.10.12")
    ap.add_argument("--port", type=int, default=1883)
    a = ap.parse_args()

    logging.basicConfig(level=logging.WARNING)
    config = {
        "listeners": {"default": {"type": "tcp", "bind": f"{a.ip}:{a.port}"}},
        "plugins": {"amqtt.plugins.authentication.AnonymousAuthPlugin": {"allow_anonymous": True}},
    }
    broker = Broker(config)
    await broker.start()
    log(NOM, f"broker MQTT en écoute sur {a.ip}:{a.port} (anonyme autorisé)", "titre")
    await asyncio.Event().wait()


if __name__ == "__main__":
    try:
        asyncio.run(main())
    except KeyboardInterrupt:
        pass

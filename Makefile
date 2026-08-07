# SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
# SPDX-License-Identifier: LicenseRef-Larka-Proprietary
#
# This file is part of Larka, proprietary software by Mickaël Larcin.
# All rights reserved. Use is subject to the license terms; copying,
# distribution, modification or reverse-engineering without the author's
# prior written permission is prohibited. See the LICENSE file for details.
# ═══════════════════════════════════════════════════════════════════════════════
# Larka — Raccourcis (wrapper de start.sh)
#
#   make install            Installe les dépendances + prépare base/config
#   make start              Démarre (premier plan)
#   make up                 Démarre en arrière-plan (démon)
#   make down / stop        Arrête le serveur
#   make restart            Redémarre
#   make status / logs      État / journal
#   make setup / reset      Prépare / réinitialise les bases
#   make doctor             Diagnostic        make fix : diagnostic + réparation
#   make autostart-on       Démarrage automatique au boot (systemd)
#   make autostart-off      Désactive le démarrage automatique
#   make prod               Installation production (Nginx + PHP-FPM + HTTPS)
#
# Surcharges :  make up PORT=9000 HOST=0.0.0.0
#   ⚠ HOST/PORT ne sont transmis QUE s'ils sont donnés sur la ligne de
#   commande : sinon start.sh reprend l'adresse mémorisée dans config.json.
# ═══════════════════════════════════════════════════════════════════════════════

SHELL := /bin/bash

# Ne transmettre --host/--port que si explicitement fournis (make up PORT=9000) :
# des valeurs par défaut ici écraseraient l'adresse mémorisée par start.sh.
OPTS :=
ifeq ($(origin PORT), command line)
OPTS += --port $(PORT)
endif
ifeq ($(origin HOST), command line)
OPTS += --host $(HOST)
endif

.DEFAULT_GOAL := help
.PHONY: help install setup start up down stop restart status logs reset doctor fix prod autostart-on autostart-off

help:          ; @./start.sh help
install:       ; @./start.sh install $(OPTS)
setup:         ; @./start.sh setup $(OPTS)
start:         ; @./start.sh start $(OPTS)
up:            ; @./start.sh start -d $(OPTS)
down stop:     ; @./start.sh stop
restart:       ; @./start.sh restart $(OPTS)
status:        ; @./start.sh status
logs:          ; @./start.sh logs
reset:         ; @./start.sh reset
doctor:        ; @./start.sh doctor
fix:           ; @./start.sh doctor --fix
autostart-on:  ; @sudo ./start.sh autostart on $(OPTS)
autostart-off: ; @sudo ./start.sh autostart off
prod:          ; @sudo ./start.sh prod

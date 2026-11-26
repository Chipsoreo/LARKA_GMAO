#!/usr/bin/env bash
# SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
# SPDX-License-Identifier: LicenseRef-Larka-Proprietary
# =====================================================================
#  Larka Connect — lanceur des simulateurs
#
#  ./lancer.sh start              démarre les simulateurs (bornes reliées à Larka)
#  ./lancer.sh start eau cvc      démarre seulement certains simulateurs
#  ./lancer.sh start csms         superviseur OCPP de TEST, pour essayer les bornes sans Larka
#  ./lancer.sh stop [noms…]       arrête tout (ou certains)
#  ./lancer.sh status             état de chaque simulateur
#  ./lancer.sh logs [nom]         suit les journaux (Ctrl+C pour quitter)
#  ./lancer.sh anomalie <nom> 1|2 provoque une anomalie (voir README)
#  ./lancer.sh verifier [proto]   lance verifier.py
#
#  Noms : elec  broker  eau  cvc  bornes  rest  csv   (+ csms, jamais par défaut)
#
#  Variables facultatives :
#    LARKA_OCPP      adresse OCPP de Larka pour les bornes  (défaut ws://127.0.10.14:9000/ocpp)
#    LARKA_OCPP_MDP  mot de passe des bornes (OCPP profil 1), si Larka en exige un
#    LARKA_CSV       dossier où la « vieille GTB » dépose ses exports (sans espace)
# =====================================================================
set -u
cd "$(dirname "$0")"

PY=python3
[ -x .venv/bin/python ] && PY=.venv/bin/python
mkdir -p logs run

# Exports CSV : dans le dossier que Larka lit par défaut (data/connect/imports)
# quand le kit vit dans le dépôt de Larka (outils/simulateurs), sinon ./export_gtb.
if [ -n "${LARKA_CSV:-}" ]; then CSV_DOSSIER=$LARKA_CSV
elif [ -d ../../api/connect ]; then CSV_DOSSIER=../../data/connect/imports/gtb
else CSV_DOSSIER=./export_gtb; fi
mkdir -p "$CSV_DOSSIER"
export SIM_CSV_DOSSIER=$CSV_DOSSIER

OCPP_URL=${LARKA_OCPP:-ws://127.0.10.14:9000/ocpp}
OCPP_MDP=${LARKA_OCPP_MDP:-}

# nom → commande. L'ordre compte : le broker avant les compteurs d'eau.
# Le CSMS de test n'est jamais lancé d'office : il prendrait le port 9000 du
# service OCPP de Larka, et les bornes ne parleraient plus à Larka.
ORDRE=(elec broker eau cvc bornes rest csv)
TOUS=(elec broker eau cvc bornes rest csv csms)
declare -A CMD=(
  [elec]="$PY -u compteurs_elec_modbus.py"
  [broker]="$PY -u broker_mqtt.py"
  [eau]="$PY -u compteurs_eau_mqtt.py --intervalle 20"
  [cvc]="$PY -u cvc_bacnet.py"
  [csms]="$PY -u csms_test.py"
  [bornes]="$PY -u borne_ocpp.py --csms $OCPP_URL${OCPP_MDP:+ --mdp $OCPP_MDP}"
  [rest]="$PY -u superviseur_rest.py"
  [csv]="$PY -u export_csv_gtb.py --intervalle 60 --dossier $CSV_DOSSIER"
)

# Broker MQTT : Mosquitto s'il est installé (sudo dnf install mosquitto), plus
# robuste que le broker Python de secours (amqtt).
if command -v mosquitto >/dev/null 2>&1; then
  printf 'listener 1883 127.0.10.12\nallow_anonymous true\npersistence false\n' > run/mosquitto.conf
  CMD[broker]="mosquitto -c run/mosquitto.conf"
fi

vivant() { [ -f "run/$1.pid" ] && kill -0 "$(cat "run/$1.pid")" 2>/dev/null; }
port_ouvert() { (exec 3<>"/dev/tcp/$1/$2") 2>/dev/null; }

demarrer() {
  local n=$1
  if vivant "$n"; then echo "  $n : déjà démarré (pid $(cat run/$n.pid))"; return; fi
  if [ "$n" = broker ] && port_ouvert 127.0.10.12 1883; then
    echo "  broker : un broker répond déjà sur 127.0.10.12:1883 (Mosquitto du système ?) — utilisé tel quel"; return
  fi
  if [ "$n" = csms ] && port_ouvert 127.0.10.14 9000; then
    echo "  csms : le port 9000 répond déjà (service OCPP de Larka ?). Le CSMS de test ne sert"
    echo "         qu'à essayer les bornes SANS Larka : arrêtez d'abord le service OCPP."; return
  fi
  # shellcheck disable=SC2086
  nohup ${CMD[$n]} > "logs/$n.log" 2>&1 < /dev/null &
  echo $! > "run/$n.pid"
  sleep 0.7
  if vivant "$n"; then echo "  $n : démarré (pid $!)"; else echo "  $n : ÉCHEC — voir logs/$n.log"; tail -5 "logs/$n.log"; fi
  case $n in broker|csms) sleep 1 ;; esac
}

arreter() {
  local n=$1
  if vivant "$n"; then kill "$(cat "run/$n.pid")" 2>/dev/null; echo "  $n : arrêté"; fi
  rm -f "run/$n.pid"
}

case "${1:-}" in
  start)
    shift; echo "Démarrage des simulateurs ($PY) :"
    if [ $# -eq 0 ]; then set -- "${ORDRE[@]}"; fi
    for n in "$@"; do [ -n "${CMD[$n]:-}" ] && demarrer "$n" || echo "  $n : inconnu"; done
    echo
    echo "Bornes  → $OCPP_URL/<identifiant>   |   Exports CSV → $CSV_DOSSIER"
    echo "Vérifier : ./lancer.sh verifier   |   Journaux : ./lancer.sh logs" ;;
  stop)
    shift; if [ $# -eq 0 ]; then set -- "${TOUS[@]}"; fi
    for n in "$@"; do arreter "$n"; done ;;
  restart)
    shift; if [ $# -eq 0 ]; then set -- "${ORDRE[@]}"; fi
    for n in "$@"; do arreter "$n"; done; sleep 1
    for n in "$@"; do demarrer "$n"; done ;;
  status)
    for n in "${TOUS[@]}"; do
      if vivant "$n"; then printf "  %-7s \033[32mactif\033[0m   pid %-7s %s\n" "$n" "$(cat run/$n.pid)" "${CMD[$n]}"
      else printf "  %-7s \033[31marrêté\033[0m          %s\n" "$n" "${CMD[$n]}"; fi
    done ;;
  logs)
    shift; if [ $# -eq 0 ]; then tail -n 5 -f logs/*.log; else tail -n 30 -f "logs/$1.log"; fi ;;
  anomalie)
    n=${2:-}; s=${3:-1}
    if ! vivant "$n"; then echo "« $n » n'est pas démarré"; exit 1; fi
    [ "$s" = 2 ] && sig=USR2 || sig=USR1
    kill -s $sig "$(cat run/$n.pid)" && echo "Signal $sig envoyé à $n — regarde : ./lancer.sh logs $n" ;;
  verifier)
    shift; $PY verifier.py "${1:-tout}" ;;
  *)
    sed -n '5,21p' "$0" | sed 's/^# \{0,1\}//' ;;
esac

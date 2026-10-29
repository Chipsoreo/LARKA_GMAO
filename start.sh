#!/usr/bin/env bash
# SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
# SPDX-License-Identifier: LicenseRef-Larka-Proprietary
#
# This file is part of Larka, proprietary software by Mickaël Larcin.
# All rights reserved. Use is subject to the license terms; copying,
# distribution, modification or reverse-engineering without the author's
# prior written permission is prohibited. See the LICENSE file for details.
# ═══════════════════════════════════════════════════════════════════════════════
# Larka — Lanceur unifié
# ═══════════════════════════════════════════════════════════════════════════════
#
#   ./start.sh              Ouvre la console interactive (menu)
#   ./start.sh install      Installe les dépendances système + prépare la base/config
#   ./start.sh start         Démarre l'application (premier plan)
#   ./start.sh start -d      Démarre en arrière-plan (démon, PID + logs)
#   ./start.sh stop          Arrête le démon
#   ./start.sh restart       Redémarre
#   ./start.sh status        État du serveur
#   ./start.sh logs          Suit le journal du serveur
#   ./start.sh journal       Journal d'activité (ajouter « fenetre » pour un nouveau terminal)
#   ./start.sh setup         Prépare base + .env + config.json (sans installer de paquet)
#   ./start.sh reset         Recrée les bases depuis zéro (DESTRUCTIF)
#   ./start.sh doctor        Diagnostic de l'environnement (+ --fix : auto-réparation)
#   ./start.sh epreuves      Vérifie les protections du moteur (auto avant « prod »)
#   ./start.sh autostart on|off|status   Démarrage automatique au boot (systemd)
#   ./start.sh prod          Installation production (Nginx + PHP-FPM + HTTPS)
#   ./start.sh prod-start    Démarre Nginx + PHP-FPM (prod)
#   ./start.sh prod-stop     Arrête Nginx + PHP-FPM (prod)
#   ./start.sh prod-restart  Redémarre Nginx + PHP-FPM (prod) et affiche l'URL
#   ./start.sh help          Aide détaillée
#
# Options communes :
#   --port N            Port d'écoute (défaut : dernier utilisé, sinon 8000)
#   --host H            Adresse de bind (défaut : dernier utilisé, sinon 127.0.0.1 ;
#                       0.0.0.0 pour le LAN)
#   --db-user U         Utilisateur PostgreSQL (défaut : gmao)
#   --db-pass P         Mot de passe PostgreSQL de dev (défaut : gmao)
#   -y, --yes           Non interactif (répond « oui » à tout)
#   --hard              (prod-restart) restart complet de Nginx au lieu d'un reload
#   --open              (prod-restart) ouvre l'URL dans le navigateur
#   --fix-pg-auth       Corrige automatiquement pg_hba.conf
#   --fix               (doctor) tente de réparer automatiquement ce qui peut l'être
#
# MÉMOIRE DES RÉGLAGES : l'adresse (host/port) passée via --host/--port est
# ENREGISTRÉE dans config.json (section « serveur ») et devient le nouveau
# défaut. Plus besoin de la retaper à chaque démarrage ni après un reboot.
# ═══════════════════════════════════════════════════════════════════════════════

set -euo pipefail

# ── Apparence ───────────────────────────────────────────────────────────────
if [[ -t 1 ]]; then
    RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[1;33m'
    BLUE=$'\033[0;34m'; CYAN=$'\033[0;36m'; BOLD=$'\033[1m'; NC=$'\033[0m'
else
    RED=''; GREEN=''; YELLOW=''; BLUE=''; CYAN=''; BOLD=''; NC=''
fi
info()  { echo -e "${BLUE}▸${NC} $1"; }
ok()    { echo -e "${GREEN}✓${NC} $1"; }
warn()  { echo -e "${YELLOW}⚠${NC} $1"; }
err()   { echo -e "${RED}✗${NC} $1" >&2; exit 1; }
# die <code> <message> : erreur applicative avec code GMAO documenté (voir README).
die()   { local c="$1"; shift; echo -e "${RED}✗ [GMAO-E${c}]${NC} $*" >&2;
          echo -e "  ${BLUE}→ aide : ./start.sh doctor   |   rubrique « Codes d'erreur » du README${NC}" >&2;
          exit "$c"; }
title() { echo; echo -e "${CYAN}═══════════════════════════════════════════════════════════${NC}";
          echo -e "${CYAN}  $1${NC}";
          echo -e "${CYAN}═══════════════════════════════════════════════════════════${NC}"; echo; }

# ── Paramètres ──────────────────────────────────────────────────────────────
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

# Paquets sans mise à jour silencieuse, PostgreSQL après une mise à jour du
# système (version de collation) : code partagé avec deploy/install.sh.
# shellcheck source=deploy/systeme.sh
[[ -f "$SCRIPT_DIR/deploy/systeme.sh" ]] && source "$SCRIPT_DIR/deploy/systeme.sh"

PORT=8000
HOST="127.0.0.1"
PORT_EXPLICIT=false      # true si --port fourni : sera mémorisé dans config.json
HOST_EXPLICIT=false      # true si --host fourni : sera mémorisé dans config.json
DB_USER="gmao"
DB_PASS="gmao"
DB_PASS_EXPLICIT=false   # true si --db-pass a été fourni : il devient prioritaire sur .env
DB_NAME="gmao"
DB_ADMIN="gmao_admin"
ASSUME_YES=false
FIX_PG_AUTH=false
DOCTOR_FIX=false

PID_DIR="data/run"
PID_FILE="${PID_DIR}/gmao.pid"
PLANIF_PID_FILE="${PID_DIR}/planificateur.pid"   # sauvegardes automatiques (voir planificateur_demarrer)
LOG_FILE="data/logs/server.log"
# Journal du serveur SANS les lignes de requête (« -q ») : une URL peut porter un
# jeton à usage unique (retour OAuth, échange de session). Les erreurs PHP vont
# dans data/logs/php_errors.log ; les échecs de démarrage restent écrits ici.
# LARKA_JOURNAL_REQUETES=1 ./start.sh start -d  rétablit les lignes (diagnostic).
PHP_S_DISCRET=(-q)
[[ "${LARKA_JOURNAL_REQUETES:-0}" == "1" ]] && PHP_S_DISCRET=()
LICENSE_MARKER="data/.license_accepted"
AUTOSTART_UNIT="larka.service"   # unité systemd posée par « autostart on »

# Extensions PHP requises (nom du module tel que listé par `php -m`)
# zip : requis par les paquets .larka (modules communautaires). Il était
# installé par setup mais jamais VÉRIFIÉ : sur une installation antérieure
# ou une distribution qui ne l'embarque pas, l'absence ne se découvrait
# qu'au moment d'importer un module, avec un message tardif.
REQUIRED_EXT=(pgsql pdo_pgsql mbstring curl gd xml zip)

# ── Lecture de la commande + options ────────────────────────────────────────
COMMAND="${1:-menu}"
[[ $# -gt 0 ]] && shift || true

SUBCOMMAND=""            # 1er argument non-option (ex : « autostart on »)
EXTRA_ARGS=()            # options non reconnues, transmises telles quelles
                         # (fix : « prod --domain X » perdait --domain)
while [[ $# -gt 0 ]]; do
    case "$1" in
        --port)        PORT="$2"; PORT_EXPLICIT=true; shift 2 ;;
        --host)        HOST="$2"; HOST_EXPLICIT=true; shift 2 ;;
        --db-user)     DB_USER="$2"; shift 2 ;;
        --db-pass)     DB_PASS="$2"; DB_PASS_EXPLICIT=true; shift 2 ;;
        -d|--daemon)   DAEMON=true; shift ;;
        -y|--yes)      ASSUME_YES=true; shift ;;
        --hard)        HARD=true; shift ;;
        --open)        OPEN_URL=true; shift ;;
        --fix-pg-auth) FIX_PG_AUTH=true; shift ;;
        --fix)         DOCTOR_FIX=true; shift ;;
        -*)            EXTRA_ARGS+=("$1"); shift ;;
        *)             [[ -z "$SUBCOMMAND" ]] && SUBCOMMAND="$1"
                       EXTRA_ARGS+=("$1"); shift ;;
    esac
done
DAEMON="${DAEMON:-false}"
HARD="${HARD:-false}"
SYS_OUI="$ASSUME_YES"    # deploy/systeme.sh : accord donné d'avance (-y)
OPEN_URL="${OPEN_URL:-false}"

# ── Mémoire des réglages (host/port) ────────────────────────────────────────
# L'adresse effective vit dans config.json (section « serveur »), écrite par
# setup/install et par save_server_settings(). Au démarrage : les options
# --host/--port EXPLICITES gagnent, sinon on reprend l'adresse mémorisée.
# → on ne retape plus jamais l'adresse, y compris après un reboot.

config_read_server() {
    # config_read_server host|port → valeur (vide si indisponible)
    [[ -f config.json ]] || return 0
    if command -v php >/dev/null 2>&1; then
        php -r '$c=json_decode(@file_get_contents("config.json"),true);
                $v=$c["serveur"][$argv[1]]??""; if(is_scalar($v)) echo $v;' "$1" 2>/dev/null
        return 0
    fi

    # ── Repli sans PHP ───────────────────────────────────────────────────
    #
    # ⚠️ IL LISAIT N'IMPORTE QUELLE SECTION.
    # « grep "port" | head -1 » rendait le PREMIER "port" du fichier entier.
    # La section « serveur » n'en déclare pas (le défaut 8000 vient du code) :
    # le premier trouvé était donc celui de « superadmin_db », soit 5432.
    # Sur une machine sans PHP en ligne de commande, start.sh tentait d'écouter
    # sur le port de PostgreSQL — soit un échec, soit pire, une collision.
    #
    # Le même piège guettait « host » : il tombait juste par hasard, parce que
    # « serveur » est la première section du fichier. Déplacer une section
    # l'aurait cassé.
    #
    # On borne donc la lecture à la section « serveur » : de son accolade
    # ouvrante à la première accolade fermante.
    awk -v cle="$1" '
        /"serveur"[[:space:]]*:[[:space:]]*\{/ { dans = 1; next }
        dans && /^[[:space:]]*\}/              { exit }
        dans {
            motif = "\"" cle "\"[[:space:]]*:[[:space:]]*\"?[^\",}]+"
            if (match($0, motif)) {
                v = substr($0, RSTART, RLENGTH)
                sub(/^.*:[[:space:]]*"?/, "", v)
                gsub(/[[:space:]]+$/, "", v)
                print v; exit
            }
        }' config.json 2>/dev/null
}

load_saved_settings() {
    local v
    if [[ "$HOST_EXPLICIT" != "true" ]]; then
        v="$(config_read_server host)"; [[ -n "$v" ]] && HOST="$v"
    fi
    if [[ "$PORT_EXPLICIT" != "true" ]]; then
        v="$(config_read_server port)"; [[ "$v" =~ ^[0-9]+$ ]] && PORT="$v"
    fi
    # ⚠️ FIX : « return 0 » indispensable. Le script tourne sous « set -e », et
    # cette fonction est appelée au NIVEAU GLOBAL. Sans config.json (première
    # installation, dépôt fraîchement cloné, archive décompressée), les deux
    # tests « [[ … ]] && VAR=… » échouent, la fonction renvoie 1, et le script
    # s'arrête AVANT le dispatch — sans le moindre message.
    # Symptôme : « ./start.sh install » ou « ./start.sh help » ne fait rien et
    # rend le code 1. Impossible à diagnostiquer sans bash -x.
    return 0
}

save_server_settings() {
    # Mémorise host/port dans config.json (créera la clé « serveur » si besoin).
    # Appelé uniquement quand une option explicite a été fournie : la config
    # n'est jamais réécrite « en douce ».
    [[ "$HOST_EXPLICIT" == "true" || "$PORT_EXPLICIT" == "true" ]] || return 0
    [[ -f config.json ]] || return 0
    command -v php >/dev/null 2>&1 || return 0
    # Clé par clé, en place, avec copie préalable : api/outils/config-json.php
    # (l'ancienne réécriture complète transformait les « {} » en « [] »).
    local r
    r="$(config_json ecrire config.json "serveur.host=\"${HOST//\"/}\"" "serveur.port=${PORT}" 2>/dev/null)" || return 0
    [[ "$r" == modifie* ]] && ok "Adresse mémorisée : ${HOST}:${PORT} (config.json — nouveau défaut)"
    return 0
}

# config_json <commande> <fichier> … : modification NON destructive de config.json
# (ou copie d'un .env) — voir l'en-tête de api/outils/config-json.php.
# Propriétaire et droits conservés (écriture en place), copie horodatée dans
# data/backups/config/ avant toute écriture, rien d'écrit si rien ne change.
config_json() {
    local outil="${SCRIPT_DIR}/api/outils/config-json.php" f="$2"
    [[ -f "$outil" ]] || { warn "api/outils/config-json.php absent"; return 1; }
    if [[ -e "$f" && ! -w "$f" ]] || [[ ! -e "$f" && ! -w "$(dirname "$f")" ]]; then
        as_root php "$outil" "$@"
    else
        php "$outil" "$@"
    fi
}

load_saved_settings

confirm() {
    # confirm "Question ?"  → 0 si oui
    [[ "$ASSUME_YES" == "true" ]] && return 0
    local reply
    read -r -p "$1 [O/n] " reply
    [[ ! "$reply" =~ ^[Nn]$ ]]
}

require_license_acceptance() {
    # Acceptation de la LICENCE D'UTILISATION LARKA avant installation/exécution.
    # Un marqueur évite de redemander à chaque lancement.
    [[ -f "$LICENSE_MARKER" ]] && return 0
    mkdir -p "$(dirname "$LICENSE_MARKER")" 2>/dev/null || true
    local stamp; stamp="date=$(date -u +%FT%TZ 2>/dev/null) by=${USER:-?}@${HOSTNAME:-?}"

    # Acceptation « organisation » / automatisée : variable d'env ou option -y.
    # En déployant ainsi, l'organisation accepte la licence pour son compte ;
    # elle informe, forme et supervise ses utilisateurs (voir LICENSE, §16).
    local envv envlow
    envv="${LARKA_LICENSE_ACCEPTED:-${GMAO_LICENSE_ACCEPTED:-}}"
    envlow="$(printf '%s' "$envv" | tr 'A-Z' 'a-z')"
    case "$envlow" in
        1|true|oui|yes|y|o)
            printf 'accepted=env %s\n' "$stamp" > "$LICENSE_MARKER" 2>/dev/null || true
            return 0 ;;
    esac
    if [[ "$ASSUME_YES" == "true" ]]; then
        printf 'accepted=yes-flag %s\n' "$stamp" > "$LICENSE_MARKER" 2>/dev/null || true
        return 0
    fi

    if [[ -t 0 ]]; then
        title "LICENCE D'UTILISATION Larka — licence propriétaire"
        echo -e "  Logiciel ${BOLD}propriétaire${NC} — © 2025-2026 Mickaël Larcin, tous droits réservés."
        echo -e "  Conditions complètes : fichier ${CYAN}LICENSE${NC} (à lire intégralement)."
        echo
        echo -e "  • L'installation ou l'utilisation du logiciel vaut acceptation de la licence."
        echo -e "  • En entreprise, l'acceptation est donnée pour le compte de l'organisation,"
        echo -e "    qui ${BOLD}informe, forme et supervise ses utilisateurs${NC} et répond de"
        echo -e "    leurs actes (voir LICENSE, §16)."
        echo
        local reply="" low=""
        read -r -p "  Tapez « j'accepte » pour continuer : " reply || true
        reply="$(printf '%s' "$reply" | sed "s/$(printf '\342\200\231')/'/g")"
        low="$(printf '%s' "$reply" | tr 'A-Z' 'a-z')"
        case "$low" in
            "j'accepte"|jaccepte|"j accepte"|oui|yes|o)
                printf 'accepted=interactive %s\n' "$stamp" > "$LICENSE_MARKER" 2>/dev/null || true
                ok "Licence acceptée — merci."; echo
                return 0 ;;
        esac
        die 1 "Licence non acceptée — opération annulée."
    fi

    die 1 "Licence non acceptée. En mode non interactif, exportez ${BOLD}LARKA_LICENSE_ACCEPTED=1${NC}\n   (l'organisation accepte alors la licence pour son compte — voir LICENSE, §16),\n   ou utilisez l'option ${BOLD}-y${NC}, ou lancez la commande en interactif."
}

as_root() {
    # Exécute en root : direct si déjà root, sinon via sudo.
    if [[ $EUID -eq 0 ]]; then "$@"; else sudo "$@"; fi
}

pg_su() {
    # Exécute une commande (typiquement psql) en tant qu'utilisateur « postgres ».
    # Conserve stdin (indispensable pour les heredocs <<EOSQL).
    if [[ $EUID -eq 0 ]]; then
        if command -v runuser >/dev/null 2>&1; then
            runuser -u postgres -- "$@"
        else
            su -s /bin/sh postgres -c "$(printf '%q ' "$@")"
        fi
    else
        sudo -u postgres "$@"
    fi
}

# ═══════════════════════════════════════════════════════════════════════════════
# DÉTECTION & INSTALLATION DES DÉPENDANCES
# ═══════════════════════════════════════════════════════════════════════════════

detect_pkg_manager() {
    if   command -v apt-get >/dev/null 2>&1; then echo apt
    elif command -v dnf     >/dev/null 2>&1; then echo dnf
    elif command -v pacman  >/dev/null 2>&1; then echo pacman
    elif command -v zypper  >/dev/null 2>&1; then echo zypper
    elif command -v brew    >/dev/null 2>&1; then echo brew
    else echo unknown
    fi
}

install_dependencies() {
    # Installe ce qui MANQUE. Ce qui est déjà installé n'est jamais mis à jour
    # sans accord : la transaction est simulée et, si elle mettait à jour des
    # paquets présents (bibliothèque C, ICU, PostgreSQL…), ils sont montrés et
    # la question est posée (deploy/systeme.sh). Une mise à jour de la
    # bibliothèque C change l'ordre de tri de PostgreSQL : ses index sont
    # alors à reconstruire, ce que Larka propose ensuite.
    local pm; pm="$(detect_pkg_manager)"
    info "Gestionnaire de paquets détecté : ${BOLD}${pm}${NC}"
    declare -F sys_installer_paquets >/dev/null || die 60 "deploy/systeme.sh absent : archive de Larka incomplète."

    local pkgs=() v=""
    command -v php >/dev/null 2>&1 && v="$(php_version)"
    case "$pm" in
        apt)
            # PostgreSQL : jamais le méta-paquet s'il y a déjà un serveur — il
            # installerait la version majeure la plus récente à côté (second
            # serveur, autre port, bases absentes).
            if ! dpkg-query -W -f='${db:Status-Status}\n' 'postgresql-[0-9]*' 2>/dev/null | grep -qx installed; then
                pkgs+=(postgresql postgresql-contrib)
            fi
            # Extensions de la version de PHP en service (php8.3-pgsql…), pas
            # celles de la version par défaut de la distribution.
            local pfx="php" e
            if [[ -n "$v" && "$v" != "?" ]] && apt-cache show "php${v}-cli" >/dev/null 2>&1; then pfx="php${v}"; fi
            for e in cli pgsql mbstring xml curl gd zip intl bcmath; do pkgs+=("${pfx}-${e}"); done
            ;;
        dnf)
            # php-sodium : signature des mises à jour (intégré à PHP sous Debian/Ubuntu)
            rpm -q postgresql-server >/dev/null 2>&1 || pkgs+=(postgresql postgresql-server)
            pkgs+=(php-cli php-pgsql php-mbstring php-xml php-curl php-gd php-zip php-intl php-bcmath php-sodium)
            ;;
        pacman)
            warn "Support pacman expérimental — vérifiez les extensions PHP (php.ini)."
            pkgs=(postgresql php php-pgsql php-gd php-sodium)
            ;;
        zypper)
            pkgs=(postgresql-server php-cli php-pgsql php-mbstring php-curl php-gd php-zip php-intl php-bcmath php-sodium)
            ;;
        brew)
            warn "macOS : installation via Homebrew (PHP inclut déjà la plupart des extensions)."
            pkgs=(php postgresql@16)
            ;;
        *)
            die 60 "Gestionnaire de paquets non reconnu.\n   Installez manuellement : PostgreSQL + PHP 8.x avec les extensions\n   ${REQUIRED_EXT[*]} zip intl bcmath, puis relancez : ./start.sh setup"
            ;;
    esac

    local rc=0
    sys_installer_paquets "$pm" "${pkgs[@]}" || rc=$?
    case $rc in
        0) ;;
        1) die 61 "Installation annulée à votre demande : rien n'a été installé ni mis à jour.\n   Pour installer quand même : ./start.sh install (et accepter), ou installez les paquets vous-même." ;;
        *) die 61 "Échec de l'installation des paquets (${pm})." ;;
    esac
    # La base de Fedora/RHEL doit être initialisée explicitement
    if [[ "$pm" == "dnf" && ! -d /var/lib/pgsql/data/base ]]; then
        as_root postgresql-setup --initdb 2>/dev/null || true
    fi
    sys_pg_redemarrer_si_besoin
    ok "Paquets prêts."
}

ensure_postgres_running() {
    if ! command -v pg_isready >/dev/null 2>&1; then
        warn "Client PostgreSQL introuvable — l'installation l'a-t-elle bien posé ?"
        return 1
    fi
    if pg_isready -q 2>/dev/null; then return 0; fi
    info "Démarrage de PostgreSQL..."
    if command -v systemctl >/dev/null 2>&1; then
        as_root systemctl enable --now postgresql 2>/dev/null \
            || as_root systemctl start postgresql 2>/dev/null || true
    elif command -v service >/dev/null 2>&1; then
        as_root service postgresql start 2>/dev/null || true
    fi
    sleep 1
    pg_isready -q 2>/dev/null
}

# ═══════════════════════════════════════════════════════════════════════════════
# VÉRIFICATIONS
# ═══════════════════════════════════════════════════════════════════════════════

check_php() {
    command -v php >/dev/null 2>&1 || return 1
    local missing="" mods
    # ⚠️ FIX : un seul appel à php -m, HORS pipeline. Avec « set -o pipefail »,
    # « php -m | grep -q » se solde parfois en 141 (SIGPIPE : grep ferme le
    # tuyau dès la 1re correspondance) → des extensions pourtant présentes
    # étaient déclarées manquantes AU HASARD (faux GMAO-E11).
    mods="$(php -m 2>/dev/null || true)"
    for ext in "${REQUIRED_EXT[@]}"; do
        grep -qix "$ext" <<< "$mods" || missing="${missing} ${ext}"
    done
    [[ -z "$missing" ]] || { echo "${missing# }"; return 2; }
    return 0
}

php_version() {
    # Demandé à PHP lui-même : zéro parsing, zéro pipeline (anti-SIGPIPE).
    php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || echo "?"
}

# Vrai si la version de PHP est >= 8.1 (minimum requis par l'application).
php_version_ok() {
    php -r 'exit(version_compare(PHP_VERSION, "8.1.0", ">=") ? 0 : 1);' 2>/dev/null
}

# ═══════════════════════════════════════════════════════════════════════════════
# pg_hba.conf — correction de l'authentification TCP locale
# ═══════════════════════════════════════════════════════════════════════════════

fix_pg_hba() {
    local hba
    hba=$(pg_su psql -tAc "SHOW hba_file;" 2>/dev/null | tr -d '[:space:]') || true
    [[ -n "$hba" && -f "$hba" ]] || err "Impossible de localiser pg_hba.conf"
    info "pg_hba.conf : $hba"
    as_root cp "$hba" "${hba}.bak.$(date +%s)"
    as_root sed -i -E \
        -e 's|^(host\s+all\s+all\s+127\.0\.0\.1/32\s+)\S+|\1scram-sha-256|' \
        -e 's|^(host\s+all\s+all\s+::1/128\s+)\S+|\1scram-sha-256|' \
        "$hba"
    as_root systemctl reload postgresql 2>/dev/null || as_root systemctl restart postgresql 2>/dev/null || true
    ok "pg_hba.conf corrigé (host 127.0.0.1 et ::1 → scram-sha-256)"
}

# ═══════════════════════════════════════════════════════════════════════════════
# BASES DE DONNÉES
# ═══════════════════════════════════════════════════════════════════════════════

create_databases() {
    info "Création du rôle et des bases PostgreSQL..."
    pg_su psql -v ON_ERROR_STOP=1 >/dev/null <<EOSQL
DO \$\$
BEGIN
    IF NOT EXISTS (SELECT FROM pg_catalog.pg_roles WHERE rolname = '${DB_USER}') THEN
        -- CREATEDB requis : le multi-tenant crée une base PostgreSQL par tenant.
        CREATE ROLE ${DB_USER} WITH LOGIN CREATEDB PASSWORD '${DB_PASS}';
    ELSE
        ALTER  ROLE ${DB_USER} WITH LOGIN CREATEDB PASSWORD '${DB_PASS}';
    END IF;
END
\$\$;
-- TEMPLATE template0 : template1 peut être refusé après une mise à jour du
-- système (« collation version mismatch ») ; template0 ne l'est jamais.
SELECT 'CREATE DATABASE ${DB_NAME} OWNER ${DB_USER} ENCODING UTF8 TEMPLATE template0'
 WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = '${DB_NAME}')\gexec
SELECT 'CREATE DATABASE ${DB_ADMIN} OWNER ${DB_USER} ENCODING UTF8 TEMPLATE template0'
 WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = '${DB_ADMIN}')\gexec
GRANT ALL PRIVILEGES ON DATABASE ${DB_NAME}  TO ${DB_USER};
GRANT ALL PRIVILEGES ON DATABASE ${DB_ADMIN} TO ${DB_USER};
EOSQL
    ok "Bases prêtes (${DB_NAME} + ${DB_ADMIN}), rôle '${DB_USER}'"
}

drop_databases() {
    warn "Suppression des bases ${DB_NAME}, ${DB_ADMIN} et du rôle ${DB_USER}..."
    pg_su psql -c "DROP DATABASE IF EXISTS ${DB_NAME};"  >/dev/null 2>&1 || true
    pg_su psql -c "DROP DATABASE IF EXISTS ${DB_ADMIN};" >/dev/null 2>&1 || true
    pg_su psql -c "DROP ROLE IF EXISTS ${DB_USER};"      >/dev/null 2>&1 || true
    ok "Bases supprimées"
}

# config_read_db <clé> : valeur de « base_de_donnees » dans config.json (vide si absente).
config_read_db() {
    [[ -f config.json ]] || return 0
    php -r '$c = json_decode(preg_replace("/^\xEF\xBB\xBF/", "", (string)file_get_contents("config.json")), true);
            $v = $c["base_de_donnees"][$argv[1]] ?? ""; echo is_scalar($v) ? $v : "";' "$1" 2>/dev/null || true
}

test_db_connection() {
    # Configuration en place : on teste CE QUE LARKA UTILISERA (config.json +
    # .env : hôte, port, base, rôle, mot de passe), pas les valeurs par défaut
    # de ce script — un rôle choisi avec --db-user passait pour « KO ».
    if [[ -f config.json && -f api/outils/postgresql.php ]]; then
        php api/outils/postgresql.php connexion 2>/dev/null; return 0
    fi
    # Le mot de passe passe par l'environnement du processus (lisible du seul
    # propriétaire), pas par sa ligne de commande (visible de tous : ps).
    LARKA_PG_MDP="$DB_PASS" php -r "
        try { new PDO('pgsql:host=127.0.0.1;port=5432;dbname=${DB_NAME}', '${DB_USER}', getenv('LARKA_PG_MDP')); echo 'OK'; }
        catch (Exception \$e) { echo 'ERREUR: ' . \$e->getMessage(); }
    " 2>&1
}

# Le rôle peut-il créer des bases ? (requis par le provisionnement multi-tenant)
test_role_createdb() {
    if [[ -f config.json && -f api/outils/postgresql.php ]]; then
        php api/outils/postgresql.php createdb 2>/dev/null; return 0
    fi
    LARKA_PG_MDP="$DB_PASS" php -r "
        try {
            \$pdo = new PDO('pgsql:host=127.0.0.1;port=5432;dbname=${DB_NAME}', '${DB_USER}', getenv('LARKA_PG_MDP'));
            echo \$pdo->query('SELECT rolcreatedb FROM pg_roles WHERE rolname = current_user')->fetchColumn() ? 'YES' : 'NO';
        } catch (Exception \$e) { echo 'ERR'; }
    " 2>&1
}

# ═══════════════════════════════════════════════════════════════════════════════
# GÉNÉRATION .env + config.json (dev, sans secret dans config.json)
# ═══════════════════════════════════════════════════════════════════════════════

# ── Lecture/écriture NON destructives du .env ────────────────────────────────
# Le .env peut contenir des identifiants saisis à la main (Microsoft, Google,
# SMTP…). On ne le réécrit donc JAMAIS en bloc : on complète clé par clé.

env_get() {
    [[ -f .env ]] || return 0
    # Dernière occurrence gagnante, comme loadEnv() côté PHP.
    sed -n "s/^[[:space:]]*$1=//p" .env | tail -1
}

ENV_SAUVEGARDE_FAITE=false
env_set() {
    # ⚠️ FIX : le .env était REMPLACÉ (mktemp + mv) à chaque complément : le
    # fichier changeait de propriétaire (root, quand le script tourne en sudo)
    # et passait en 600 — PHP-FPM (www-data) ne pouvait plus le lire après une
    # relance ou une mise à jour, et l'application perdait ses secrets.
    # Désormais : valeur identique → rien n'est écrit ; sinon copie horodatée
    # (data/backups/config/, une fois par lancement) puis réécriture EN PLACE
    # (même fichier : propriétaire et droits conservés).
    local k="$1" v="$2" tmp found=0 line
    [[ -f .env && "$(env_get "$k")" == "$v" ]] && grep -q "^[[:space:]]*${k}=" .env 2>/dev/null && return 0
    if [[ -f .env && "$ENV_SAUVEGARDE_FAITE" != "true" ]]; then
        config_json sauvegarder .env >/dev/null 2>&1 || true
        ENV_SAUVEGARDE_FAITE=true
    fi
    tmp="$(mktemp)"
    if [[ -f .env ]]; then
        while IFS= read -r line || [[ -n "$line" ]]; do
            if [[ "$line" == "${k}="* ]]; then
                printf '%s=%s\n' "$k" "$v" >> "$tmp"; found=1
            else
                printf '%s\n' "$line" >> "$tmp"
            fi
        done < .env
    fi
    (( found == 0 )) && printf '%s=%s\n' "$k" "$v" >> "$tmp"
    if [[ -f .env ]]; then
        cat "$tmp" > .env 2>/dev/null || as_root sh -c 'cat "$1" > "$2"' _ "$tmp" "$PWD/.env"
    else
        cp "$tmp" .env && chmod 600 .env 2>/dev/null || true
    fi
    rm -f "$tmp"
}

generate_env() {
    if [[ ! -f .env ]]; then
        info "Génération de .env..."
        printf '# Larka — Secrets. NE PAS COMMITTER. Documentation : .env.example\n' > .env
        chmod 600 .env 2>/dev/null || true
    else
        ok ".env existant : complété sans rien écraser"
    fi

    [[ -z "$(env_get GMAO_SECRET_KEY)" ]] && \
        env_set GMAO_SECRET_KEY "$(openssl rand -hex 32 2>/dev/null || php -r 'echo bin2hex(random_bytes(32));')"

    # ⚠️ FIX : le .env fait FOI pour le mot de passe BDD — c'est lui que lit
    # l'application. Auparavant, create_databases() imposait ALTER ROLE …
    # PASSWORD 'gmao' pendant que generate_env() conservait l'ancien .env : les
    # deux divergeaient et PostgreSQL rejetait l'application
    # (« authentification par mot de passe échouée »). deploy/install.sh avait
    # déjà ce correctif ; il n'avait jamais été reporté ici.
    local envPass; envPass="$(env_get GMAO_DB_PASSWORD)"
    if [[ "$DB_PASS_EXPLICIT" == "true" ]]; then
        env_set GMAO_DB_PASSWORD "$DB_PASS"
        env_set GMAO_SUPERADMIN_DB_PASSWORD "$DB_PASS"
        info "Mot de passe BDD imposé par --db-pass (.env aligné)."
    elif [[ -n "$envPass" ]]; then
        DB_PASS="$envPass"
        info "Mot de passe BDD repris depuis .env (PostgreSQL sera aligné dessus)."
    else
        env_set GMAO_DB_PASSWORD "$DB_PASS"
        env_set GMAO_SUPERADMIN_DB_PASSWORD "$DB_PASS"
    fi
    [[ -z "$(env_get GMAO_SUPERADMIN_DB_PASSWORD)" ]] && env_set GMAO_SUPERADMIN_DB_PASSWORD "$DB_PASS"
    ok ".env prêt (chmod 600)"
}

CONFIG_CREEE=false
generate_config() {
    if [[ -f config.json ]] && ! grep -q "CHANGER_CE_MOT_DE_PASSE\|GENERER_UNE_CLE" config.json 2>/dev/null; then
        ok "config.json existant conservé"
        return
    fi
    # ⚠️ FIX : un config.json existant n'est plus jamais régénéré en entier.
    # Il l'était dès qu'il contenait une valeur modèle (« CHANGER_CE_MOT_DE_PASSE »,
    # « GENERER_UNE_CLE ») — un seul champ oublié suffisait à perdre tout le
    # paramétrage (Microsoft, SMTP, présence, SharePoint…). Seules ces valeurs
    # sont désormais vidées (les secrets vivent dans .env), le reste est gardé.
    # Exception : le fichier est une copie intacte de config.example.json — il
    # ne contient alors aucun réglage, et la configuration de développement
    # ci-dessous lui convient mieux (copie gardée dans data/backups/config/).
    if [[ -f config.json ]]; then
        if [[ -f config.example.json ]] && cmp -s config.json config.example.json; then
            config_json sauvegarder config.json >/dev/null 2>&1 || true
            warn "config.json était une copie de config.example.json → configuration de développement générée"
        else
            local r; r="$(config_json nettoyer config.json 2>&1)" || { warn "config.json : ${r}"; return; }
            ok "config.json existant conservé (valeurs modèles vidées : les secrets sont dans .env)"
            return
        fi
    fi
    CONFIG_CREEE=true
    info "Génération de config.json (dev, sans secret)..."
    # Aligné sur config.example.json : aucun secret, aucune clé pilotée par .env
    # (client_id/secret OAuth, mots de passe…), et aucune valeur qui ne fasse que
    # recopier le défaut du code.
    cat > config.json <<EOCFG
{
    "_": "Configuration de DÉVELOPPEMENT générée par start.sh. Secrets et identifiants OAuth : voir .env (.env.example). Modèle complet et commenté : config.example.json",
    "serveur": { "host": "${HOST}", "port": ${PORT}, "env": "dev" },
    "base_de_donnees": {
        "driver": "pgsql", "host": "127.0.0.1", "port": 5432,
        "dbname": "${DB_NAME}", "user": "${DB_USER}", "sslmode": "prefer"
    },
    "superadmin_db": {
        "driver": "pgsql", "host": "127.0.0.1", "port": 5432,
        "dbname": "${DB_ADMIN}", "user": "${DB_USER}", "sslmode": "prefer"
    },
    "securite": { "session_duree_heures": 24, "allowed_origin": "*", "debug_errors": true },
    "session": { "cookie_samesite": "Lax" },
    "documents": {
        "max_par_categorie": 4, "taille_max_mo": 25,
        "mime_autorises": ["application/pdf","image/png","image/jpeg","image/webp"], "dedup_sha256": true
    },
    "logs": { "actif": true, "dossier": "data/logs", "niveau": "info", "rotation_jours": 14 },
    "sauvegardes": { "actif": false, "dossier": "data/backups", "garder": 10 },
    "acces": { "domaine_email_autorise": "" },
    "microsoft_oauth": { "actif": false },
    "google_oauth": { "actif": false },
    "plans_presence": { "actif": false },
    "smtp": { "host": "", "port": 587, "secure": "tls", "from": "", "from_name": "Larka" },
    "push": { "actif": false, "vapid_public_key": "", "vapid_subject": "" },
    "assistant": { "actif": false, "fournisseur": "anthropic", "model": "", "api_url": "" },
    "extensions": { "actif": false }
}
EOCFG
    chmod 640 config.json 2>/dev/null || true
    ok "config.json généré"
}

# ═══════════════════════════════════════════════════════════════════════════════
# SHAREPOINT — option choisie au premier lancement
# ═══════════════════════════════════════════════════════════════════════════════
# Sans SharePoint, les documents sont stockés dans la base de Larka et la
# connexion Microsoft (si elle est utilisée) ne demande pas l'accès aux fichiers.
# Avec, on peut lier des fichiers SharePoint/OneDrive aux fiches. Le choix vit
# dans config.json → "sharepoint": { "actif": true|false } ; il est demandé une
# fois (setup, premier « start »), puis modifiable : ./start.sh sharepoint on|off
# ou console → Maintenance.

sp_config_file() {
    # La configuration effective : celle de la production si elle est installée.
    if prod_installed 2>/dev/null && [[ -f "$(prod_dir)/config.json" ]]; then
        echo "$(prod_dir)/config.json"
    else
        echo "${SCRIPT_DIR}/config.json"
    fi
}

sp_state() {
    # true | false | vide (jamais choisi)
    local f; f="$(sp_config_file)"
    [[ -f "$f" ]] && command -v php >/dev/null 2>&1 || return 0
    php -r '$c=json_decode(@file_get_contents($argv[1]),true);
            if (isset($c["sharepoint"]["actif"])) echo $c["sharepoint"]["actif"] ? "true" : "false";' "$f" 2>/dev/null || true
}

sp_write() {
    local v="$1" f; f="$(sp_config_file)"
    [[ -f "$f" ]] || { warn "config.json absent — lancez d'abord ./start.sh setup"; return 1; }
    # Seule la clé sharepoint.actif est touchée (les autres réglages de la
    # section, s'il y en a, restent), en place, après copie.
    config_json ecrire "$f" "sharepoint.actif=${v}" >/dev/null \
        || { warn "Impossible d'écrire ${f}"; return 1; }
    if [[ "$v" == "true" ]]; then
        ok "SharePoint activé (${f})"
        info "Il fonctionne avec la connexion Microsoft (microsoft_oauth.actif) ; les utilisateurs se reconnectent pour autoriser l'accès à leurs fichiers."
    else
        ok "SharePoint désactivé (${f}) — documents stockés dans Larka"
    fi
}

ask_sharepoint() {
    # Ne demande qu'une fois : si le choix figure déjà dans config.json, rien à faire.
    [[ -n "$(sp_state)" ]] && return 0
    [[ -f "$(sp_config_file)" ]] || return 0
    # ⚠️ FIX « SharePoint a disparu » : sur une configuration EXISTANTE qui ne
    # précise pas le choix, SharePoint est ACTIF (comportement historique, cf.
    # api/config.php). Ce script y inscrivait pourtant « false » dès qu'il
    # tournait sans terminal (démarrage automatique, -y) — à la première relance
    # après une mise à jour, SharePoint disparaissait. On ne pose donc la
    # question que pour une configuration créée à l'instant (ou sur demande
    # explicite depuis la console : ask_sharepoint --demander), et l'on n'écrit
    # jamais rien sans réponse.
    if [[ "${1:-}" != "--demander" && "$CONFIG_CREEE" != "true" ]]; then
        return 0
    fi
    local v=false rep=""
    if [[ -t 0 && "$ASSUME_YES" != "true" ]]; then
        echo
        echo -e "  ${BOLD}Documents : utiliser SharePoint ?${NC}"
        echo    "    • Non (conseillé sans Microsoft 365) : les documents sont stockés dans Larka."
        echo    "    • Oui : on peut aussi lier des fichiers SharePoint / OneDrive aux fiches"
        echo    "      (exige la connexion Microsoft ; accès aux fichiers autorisé par chaque utilisateur)."
        read -r -p "  Activer SharePoint ? [o/N] " rep || true
        [[ "$rep" =~ ^[OoYy] ]] && v=true
    elif [[ "$CONFIG_CREEE" == "true" ]]; then
        info "SharePoint désactivé par défaut (nouvelle installation, mode non interactif) — ./start.sh sharepoint on pour l'activer."
    else
        return 0      # pas de réponse : on n'écrit rien
    fi
    sp_write "$v" || true
}

cmd_sharepoint() {
    case "${SUBCOMMAND:-etat}" in
        on|oui|activer)       sp_write true ;;
        off|non|desactiver)   sp_write false ;;
        etat|status|"")
            case "$(sp_state)" in
                true)  ok "SharePoint : activé   ($(sp_config_file))" ;;
                false) ok "SharePoint : désactivé — documents stockés dans Larka   ($(sp_config_file))" ;;
                *)     info "SharePoint : actif par défaut avec la connexion Microsoft (aucun choix inscrit)."
                       info "Choisir : ./start.sh sharepoint on   ou   ./start.sh sharepoint off" ;;
            esac ;;
        *) die 2 "Usage : ./start.sh sharepoint [on|off|etat]" ;;
    esac
}

ensure_data_dirs() {
    mkdir -p data/logs data/sessions data/backups "$PID_DIR"
    # En production c'est PHP-FPM (www-data) qui écrit les journaux, pas
    # l'utilisateur qui lance ce script. Sans ces droits, Journal.php échoue
    # silencieusement et aucun fichier d'activité n'apparaît jamais.
    chmod 775 data/logs 2>/dev/null || true
    if prod_installed 2>/dev/null && command -v id >/dev/null 2>&1; then
        id -u www-data >/dev/null 2>&1 && as_root chgrp -R www-data data/logs 2>/dev/null || true
    fi
    return 0
}

# ═══════════════════════════════════════════════════════════════════════════════
# DÉMON : état du serveur
# ═══════════════════════════════════════════════════════════════════════════════

server_pid() { [[ -f "$PID_FILE" ]] && cat "$PID_FILE" 2>/dev/null || true; }

is_running() {
    # Vrai si le PID mémorisé existe ET est bien notre serveur PHP.
    # Après un reboot, le fichier PID survit mais le numéro peut avoir été
    # RECYCLÉ par un tout autre processus : sans ce contrôle, le script
    # croyait Larka « déjà démarré » et ne faisait rien (d'où l'impression
    # d'un redémarrage qui « ne fait rien »). On vérifie donc la ligne de
    # commande du processus avant de le croire.
    local p cmd
    p="$(server_pid)"
    [[ -n "$p" ]] || return 1
    kill -0 "$p" 2>/dev/null || { rm -f "$PID_FILE"; return 1; }
    cmd="$(tr '\0' ' ' < "/proc/$p/cmdline" 2>/dev/null)" || cmd=""
    if [[ -n "$cmd" && "$cmd" != *php* ]]; then
        rm -f "$PID_FILE"        # PID recyclé par un autre programme
        return 1
    fi
    return 0
}

free_port_if_needed() {
    command -v lsof >/dev/null 2>&1 || return 0
    local pids; pids=$(lsof -Pi :"${PORT}" -sTCP:LISTEN -t 2>/dev/null || true)
    [[ -z "$pids" ]] && return 0
    warn "Le port ${PORT} est déjà utilisé (PID: ${pids//$'\n'/, })."
    if confirm "Libérer le port ${PORT} ?"; then
        kill $pids 2>/dev/null || true; sleep 1; ok "Port ${PORT} libéré."
    else
        die 40 "Port ${PORT} occupé. Utilisez --port pour en changer."
    fi
}

# ═══════════════════════════════════════════════════════════════════════════════
# COMMANDES
# ═══════════════════════════════════════════════════════════════════════════════

require_setup() {
    # Vérifie que l'app est prête à démarrer (sans installer quoi que ce soit).
    command -v php >/dev/null 2>&1 || die 10 "PHP introuvable. Lancez d'abord : ./start.sh install"
    php_version_ok || die 12 "PHP $(php_version) trop ancien — il faut PHP 8.1 ou plus récent."
    local miss; miss="$(check_php || true)"
    [[ -z "$miss" ]] || die 11 "Extensions PHP manquantes :${miss}\n   Installez-les (Debian) : apt install php-pgsql php-mbstring php-curl php-gd php-xml"
    # En production, la configuration effective peut vivre dans la racine
    # déployée (/var/www/gmao) et non dans le dépôt : ne pas déclencher une
    # réinstallation parce que ce dossier-ci n'a pas de .env.
    if prod_installed 2>/dev/null && [[ -f "$(prod_dir)/config.json" ]]; then
        return 0
    fi
    if [[ ! -f .env || ! -f config.json ]]; then
        # Au lieu d'échouer sèchement, on propose d'enchaîner la préparation
        # tout de suite (c'est presque toujours ce que l'utilisateur veut).
        warn "Configuration absente (.env / config.json)."
        if [[ -t 0 ]] && confirm "  Lancer la préparation maintenant (./start.sh setup) ?"; then
            cmd_setup
        else
            die 30 "Configuration absente (.env / config.json).\n   Lancez d'abord : ${BOLD}./start.sh install${NC}  (ou ./start.sh setup si les paquets sont déjà là)"
        fi
    fi
}

ensure_ready_to_serve() {
    # Auto-réparation légère avant chaque démarrage : PostgreSQL doit tourner.
    # Après un reboot, c'est LA cause n°1 d'un « serveur qui démarre mais ne
    # fait rien » : l'app démarrait, mais la base était éteinte, et il fallait
    # passer par « réparer » à la main. On le règle ici, silencieusement.
    if command -v pg_isready >/dev/null 2>&1 && ! pg_isready -q 2>/dev/null; then
        warn "PostgreSQL est arrêté — démarrage automatique…"
        if ensure_postgres_running; then
            ok "PostgreSQL démarré."
        else
            die 21 "PostgreSQL n'a pas pu être démarré.\n   Essayez : sudo systemctl start postgresql   puis ./start.sh doctor"
        fi
    fi
    verifier_prerequis
}

# ── Prérequis (api/Prerequis.php, exigences dans version.json) ──────────────
# Un prérequis BLOQUANT arrête le démarrage avec la marche à suivre ; les
# avertissements sont affichés sans bloquer. « doctor » donne le rapport complet.
verifier_prerequis() {
    [[ -f api/outils/prerequis.php ]] || return 0
    local sortie rc=0
    sortie="$(php api/outils/prerequis.php --court 2>&1)" || rc=$?
    while IFS= read -r l; do
        [[ -z "$l" ]] && continue
        case "$l" in "✗"*) err_line "${l#✗ }" ;; "⚠"*) warn "${l#⚠ }" ;; esac
    done <<< "$sortie"
    [[ $rc -eq 1 ]] && die 13 "Prérequis non satisfaits : Larka ne peut pas démarrer (détail : ./start.sh doctor)."
    return 0
}
err_line() { echo -e "${RED}✗${NC} $*" >&2; }

cmd_setup() {
    prod_guard_dev_command
    title "Larka — Préparation (base + configuration)"
    command -v psql >/dev/null 2>&1 || die 20 "Client PostgreSQL (psql) absent. Lancez : ./start.sh install"
    ensure_postgres_running || die 21 "PostgreSQL n'est pas démarré.\n   Démarrez-le (systemctl start postgresql) ou lancez : ./start.sh install"
    [[ "$FIX_PG_AUTH" == "true" ]] && fix_pg_hba
    # ⚠️ ORDRE CRITIQUE : generate_env AVANT create_databases. Il résout le mot
    # de passe effectif (celui du .env s'il existe), sur lequel create_databases
    # aligne ensuite PostgreSQL. Dans l'autre sens, les deux divergent.
    generate_env
    create_databases || die 22 "Échec de création du rôle ou des bases PostgreSQL."
    generate_config
    ask_sharepoint
    ensure_data_dirs
    ok "Répertoires data/ prêts"

    info "Test de connexion PostgreSQL..."
    local out; out="$(test_db_connection)"
    if [[ "$out" == "OK" ]]; then
        ok "Connexion PostgreSQL fonctionnelle"
    else
        echo; warn "Connexion PostgreSQL impossible : ${out}"
        local hba; hba=$(pg_su psql -tAc "SHOW hba_file;" 2>/dev/null | tr -d '[:space:]' || true)
        echo -e "   La connexion utilise host=127.0.0.1 (TCP). Corrigez ${CYAN}${hba}${NC} :"
        echo -e "   ${GREEN}Automatique${NC} : ${CYAN}./start.sh setup --fix-pg-auth${NC}"
        echo -e "   ${GREEN}Manuel${NC}      : passez 'ident'/'peer' à 'scram-sha-256' sur les lignes host 127.0.0.1/::1, puis reload."
        die 23 "Connexion/authentification PostgreSQL refusée (voir pg_hba.conf ci-dessus)."
    fi
    declare -F sys_pg_collation_proposer >/dev/null && sys_pg_collation_proposer "$SCRIPT_DIR"
    offer_demo
    echo; ok "Préparation terminée. Démarrez avec : ${BOLD}./start.sh start${NC}"
}

# ── Garde-fou : commandes de DÉVELOPPEMENT sur une machine de PRODUCTION ──────
# cmd_install et cmd_setup génèrent une configuration de DÉV (env: dev,
# allowed_origin: "*", base locale) et peuvent réécrire config.json. Les lancer
# sur un serveur de production casse le site : la réinstallation prod, elle,
# passe par deploy/install.sh qui conserve la configuration existante.
prod_guard_dev_command() {
    # ── Ce qui prouve VRAIMENT une installation de production ────────────
    #
    # « env: prod » dans config.json déclenchait à lui seul ce garde-fou, au
    # motif que c'était « le signal le plus fiable ». Ce n'en est pas un : c'est
    # une valeur de configuration, pas une preuve d'installation. Et
    # config.example.json la portait par défaut — quiconque copiait l'exemple
    # voyait donc ses commandes de développement traitées comme dangereuses, sur
    # une machine où rien n'était installé.
    #
    # Les vraies preuves sont matérielles : un vhost Nginx et un pool PHP-FPM
    # existent, ou le code est déployé sous /var/www.
    local isProd=false
    prod_installed && isProd=true
    [[ "$SCRIPT_DIR" == /var/www/* ]] && isProd=true
    [[ "$isProd" == "true" ]] || return 0

    # « env: prod » reste utile à MENTIONNER une fois qu'on est sûr : il indique
    # que la configuration présente est celle du site en service.
    local _env=""
    if [[ -f config.json ]] && grep -qE '"env"[[:space:]]*:[[:space:]]*"prod"' config.json 2>/dev/null; then
        _env=" et son config.json est en « env: prod »"
    fi

    echo
    warn "Cette machine porte une installation de ${BOLD}PRODUCTION${NC}${YELLOW} (${SCRIPT_DIR})${_env}."
    warn "« ${COMMAND} » est une commande de DÉVELOPPEMENT : elle prépare une base"
    warn "locale et peut régénérer config.json en mode dev (env: dev, allowed_origin: \"*\")."
    echo
    info "Pour la production, utilisez plutôt :"
    info "  ./start.sh prod-restart   → redémarrer les services (ne touche à rien d'autre)"
    info "  ./start.sh prod           → réinstaller/reconfigurer (conserve config.json)"
    echo
    confirm "  Lancer quand même « ${COMMAND} » sur cette machine ?" || die 3 "Annulé — rien n'a été modifié."
}

# ── Démonstration « Ville de Larkaville » ──────────────────────────────────
# Le travail (sauvegarde de la base, chargement PostgreSQL ou SQLite en une
# transaction, fichiers, modules, recalage des dates) est fait par le même code
# que « Larka.cmd demo » sous Windows : deploy/windows/larka.php, qui sait aussi
# traiter PostgreSQL. Ici : arrêter / relancer le serveur de développement.
demo_package() {
    # Archive ou dossier de démonstration : posé dans le dossier de Larka (ou son
    # parent), sinon celle livrée avec Larka dans demo/.
    local d f
    for d in "$SCRIPT_DIR" "$(dirname "$SCRIPT_DIR")" "$SCRIPT_DIR/demo"; do
        for f in "$d"/*demo-Larkaville*.zip "$d"/*demo-Larkaville*/; do
            [[ -e "$f" ]] && { echo "${f%/}"; return 0; }
        done
    done
    return 1
}

# Dossier dont config.json est en service : celui-ci, sinon la production.
larka_racine_config() {
    if [[ -f "$SCRIPT_DIR/config.json" ]]; then echo "$SCRIPT_DIR"
    elif prod_installed 2>/dev/null && [[ -f "$(prod_dir)/config.json" ]]; then prod_dir
    else echo "$SCRIPT_DIR"; fi
}

cmd_demo() {
    prod_guard_dev_command
    command -v php >/dev/null 2>&1 || die 20 "PHP absent. Lancez : ./start.sh install"
    local etait=false
    if is_running; then etait=true; info "Arrêt du serveur de développement…"; cmd_stop; fi
    local -a opts=(--sans-demarrer)
    [[ "$ASSUME_YES" == "true" ]] && opts+=(--oui)
    local rc=0
    php deploy/windows/larka.php demo "${opts[@]}" ${EXTRA_ARGS[@]+"${EXTRA_ARGS[@]}"} || rc=$?
    if [[ "$etait" == "true" ]]; then DAEMON=true; cmd_start; fi
    [[ $rc -eq 0 ]] || return $rc
    [[ "$etait" == "true" ]] || echo -e "  Démarrez avec : ${BOLD}./start.sh start${NC}"
}

offer_demo() {
    # Proposer la démonstration si son archive est là (l'archive complète la
    # range dans le dossier de Larka ; Téléchargements et le Bureau sont aussi
    # regardés). Une seule fois : un « non » est retenu (data/run/demo-refusee),
    # et rien n'est proposé quand la démonstration est déjà posée. Jamais sans
    # terminal ni en mode -y : une démonstration ne s'installe pas sans qu'on
    # l'ait demandé. Base déjà utilisée : « non » par défaut (elle serait mise
    # de côté, puis remplacée).
    [[ -t 0 && -t 1 ]] || return 0
    [[ "$ASSUME_YES" == "true" ]] && return 0
    [[ -f data/demo/comptes-demo.csv || -f data/run/demo-refusee ]] && return 0
    command -v php >/dev/null 2>&1 || return 0
    php deploy/windows/larka.php demo --sans-demarrer --proposer \
        || info "Démonstration non installée. Plus tard : ${BOLD}./start.sh demo${NC}"
}

# ── Mise à jour depuis une archive (zip d'une nouvelle version) ─────────────
# Remplace la décompression « par-dessus » l'installation, qui écrasait les
# réglages des modules (extensions/config/) et toute copie de config.json ou
# de .env glissée dans l'archive. Le travail est fait par la mise à jour de
# Larka elle-même (api/outils/mise-a-jour.php --archive) : chemins protégés
# (config.json, .env, config.key, data/, php/), réglages des modules ajoutés
# seulement s'ils manquent, prérequis vérifiés, fichiers ET bases sauvegardés
# avant écriture, retour arrière automatique en cas d'échec.
maj_archive_trouver() {
    local d f best="" t bt=0
    for d in "$SCRIPT_DIR" "$(dirname "$SCRIPT_DIR")" "$HOME/Téléchargements" "$HOME/Downloads"; do
        for f in "$d"/LARKA_GMAO-*.zip; do
            [[ -f "$f" ]] || continue
            [[ "$f" == *demo-Larkaville* ]] && continue
            t="$(stat -c %Y "$f" 2>/dev/null || echo 0)"
            (( t > bt )) && { best="$f"; bt="$t"; }
        done
    done
    [[ -n "$best" ]] && echo "$best"
}

cmd_maj() {
    command -v php >/dev/null 2>&1 || die 10 "PHP introuvable."
    # Arguments : [--cible <dossier de l'installation>] [archive.zip] [--sans-signature] [--reinstaller]
    local zip="" cible="" a attendre=""
    local -a opts=() run=(php)
    for a in ${EXTRA_ARGS[@]+"${EXTRA_ARGS[@]}"}; do
        if [[ "$attendre" == "cible" ]]; then cible="$a"; attendre=""; continue; fi
        case "$a" in
            --cible|--racine)               attendre=cible ;;
            --sans-signature|--reinstaller) opts+=("$a") ;;
            -*)                             : ;;
            *)                              [[ -z "$zip" ]] && zip="$a" ;;
        esac
    done
    [[ -n "$cible" ]] || cible="$(larka_racine_config)"
    [[ -d "$cible" ]] && cible="$(cd "$cible" && pwd)"
    [[ -f "$cible/api/index.php" && -f "$cible/version.json" ]] || die 2 "Installation de Larka introuvable : ${cible}\n   → ./start.sh maj --cible /chemin/de/larka LARKA_GMAO-x.y.z.zip"
    [[ -n "$zip" ]] || zip="$(maj_archive_trouver)" || true
    [[ -n "$zip" && -f "$zip" ]] || die 2 "Archive introuvable.\n   Usage : ./start.sh maj [--cible <dossier>] LARKA_GMAO-x.y.z.zip [--sans-signature] [--reinstaller]\n   (sans archive : la plus récente LARKA_GMAO-*.zip posée ici, dans le dossier parent ou Téléchargements)"
    zip="$(cd "$(dirname "$zip")" && pwd)/$(basename "$zip")"
    title "Larka — Mise à jour depuis une archive"
    info "Installation : ${cible}"
    info "Archive      : ${zip}"
    [[ "$ASSUME_YES" == "true" ]] && opts+=(--oui)
    # L'outil de CE dossier fait le travail (il sait lire une archive, même si
    # l'installation visée est d'une version qui ne le savait pas encore).
    local outil="${SCRIPT_DIR}/api/outils/mise-a-jour.php"
    [[ "$cible" != "$SCRIPT_DIR" ]] && opts+=(--racine "$cible")
    # Lancé par root : on écrit avec le propriétaire des fichiers (www-data en
    # production), pour que le service puisse toujours les lire.
    local proprio; proprio="$(stat -c %U "$cible/api/index.php" 2>/dev/null || echo "")"
    if [[ $EUID -eq 0 && -n "$proprio" && "$proprio" != "root" ]]; then
        if command -v runuser >/dev/null 2>&1; then run=(runuser -u "$proprio" -- php)
        elif command -v sudo >/dev/null 2>&1; then run=(sudo -u "$proprio" php); fi
    elif [[ ! -w "$cible/api/index.php" ]]; then
        run=(as_root php)
    fi
    local etait=false
    if [[ "$cible" == "$SCRIPT_DIR" ]] && is_running; then etait=true; fi
    "${run[@]}" "$outil" --archive "$zip" "${opts[@]}" || die 31 "Mise à jour non appliquée (détail ci-dessus) — rien n'a été modifié, ou la version précédente a été restaurée."
    if [[ "$etait" == "true" ]]; then info "Redémarrage du serveur de développement…"; cmd_stop; DAEMON=true; cmd_start; fi
    if [[ "$cible" == /var/www/* ]] || { prod_installed 2>/dev/null && [[ "$cible" == "$(prod_dir)" ]]; }; then
        info "Production : pour recharger PHP-FPM → ${BOLD}sudo ./start.sh prod-restart${NC}"
    fi
    ok "Mise à jour terminée. config.json, .env, data/ et les réglages des modules n'ont pas été modifiés."
}

cmd_install() {
    prod_guard_dev_command
    title "Larka — Installation des dépendances"
    local need_pkgs=false
    if ! command -v psql >/dev/null 2>&1; then need_pkgs=true; fi
    local php_state="ok"; local php_missing=""
    if ! command -v php >/dev/null 2>&1; then php_state="absent"; need_pkgs=true
    else php_missing="$(check_php || true)"; [[ -n "$php_missing" ]] && { php_state="incomplet"; need_pkgs=true; }
    fi

    if [[ "$need_pkgs" == "true" ]]; then
        info "PHP : ${php_state}${php_missing:+ (manque :${php_missing})}"
        info "PostgreSQL : $(command -v psql >/dev/null 2>&1 && echo présent || echo absent)"
        if confirm "Installer les paquets système manquants ?"; then
            install_dependencies
        else
            warn "Installation des paquets ignorée — la préparation peut échouer."
        fi
    else
        ok "Dépendances déjà présentes (PHP $(php_version) + PostgreSQL)."
    fi

    # Enchaîne sur la préparation base + config
    cmd_setup
}

cmd_start() {
    # ── PRODUCTION INSTALLÉE : ON PRÉVIENT, ON NE SUBSTITUE PAS ─────────
    #
    # « start » démarrait la PRODUCTION dès qu'une installation était détectée.
    # On tapait « start » en attendant le serveur de développement, et on
    # obtenait autre chose — sans l'avoir demandé, et sans moyen évident de
    # revenir en arrière : l'échappatoire était une variable d'environnement
    # que personne ne devine.
    #
    # Le motif invoqué — « deux serveurs concurrents » — ne tient pas : nginx
    # écoute sur 80/443, le serveur intégré sur 8000. Ils coexistent très bien,
    # et lancer les deux est justement ce qu'on veut en développant sur une
    # machine qui héberge aussi une production.
    #
    # On exécute donc la commande demandée, et l'on signale l'autre.
    if prod_installed 2>/dev/null; then
        local _u; _u="$(prod_url "$(prod_dir)" 2>/dev/null)" || true
        info "Une installation de production existe aussi sur cette machine${_u:+ (${_u})}."
        info "Elle n'est pas touchée. Pour la piloter : ${BOLD}./start.sh prod-start${NC}"
        echo
    fi

    require_setup
    ask_sharepoint            # premier lancement : SharePoint oui/non (une seule fois)
    ensure_data_dirs
    save_server_settings      # --host/--port explicites → mémorisés (config.json)
    ensure_ready_to_serve     # PostgreSQL relancé automatiquement si besoin

    # Avant de lancer le serveur (aucune requête en cours) :
    #  - PostgreSQL après une mise à jour du système : index à reconstruire ?
    #  - démonstration disponible et jamais proposée ?
    if ! is_running && ! autostart_active 2>/dev/null; then
        declare -F sys_pg_collation_proposer >/dev/null && sys_pg_collation_proposer "$(larka_racine_config)"
        offer_demo
    fi

    # Si le démarrage automatique (systemd) est installé, c'est LUI le patron :
    # on pilote le service plutôt que de lancer un 2e serveur concurrent.
    if autostart_installed; then
        local h="$HOST"; [[ "$h" == "0.0.0.0" ]] && h="localhost"
        if autostart_active; then
            warn "Larka tourne déjà (service systemd ${AUTOSTART_UNIT})."
        else
            title "Larka — Démarrage (service systemd)"
            if [[ "$HOST_EXPLICIT" == "true" || "$PORT_EXPLICIT" == "true" ]]; then
                info "Nouvelle adresse → régénération de l'unité systemd…"
                autostart_write_unit; as_root systemctl daemon-reload
            fi
            as_root systemctl start "$AUTOSTART_UNIT" \
                || { prod_journal "$AUTOSTART_UNIT"; die 41 "Échec du démarrage du service ${AUTOSTART_UNIT}."; }
            ok "Service démarré."
        fi
        echo -e "  Application : ${CYAN}http://${h}:${PORT}${NC}"
        echo -e "  Journaux    : ${CYAN}journalctl -u ${AUTOSTART_UNIT%.service} -f${NC}"
        return 0
    fi

    if is_running; then
        warn "Larka tourne déjà (PID $(server_pid)) sur http://${HOST}:${PORT}"
        exit 0
    fi
    free_port_if_needed

    local url_host="$HOST"; [[ "$HOST" == "0.0.0.0" ]] && url_host="localhost"

    # ── Workers du serveur PHP intégré ─────────────────────────────────────
    # Sans PHP_CLI_SERVER_WORKERS, `php -S` est MONO-THREAD : une seule requête
    # à la fois. Une génération IA locale (Ollama, 30-120 s sur CPU) bloque donc
    # TOUT le site pour TOUS les utilisateurs pendant ce temps.
    # 8 workers = 8 requêtes simultanées (ajustable : PHP_WORKERS=16 ./start.sh start)
    export PHP_CLI_SERVER_WORKERS="${PHP_WORKERS:-8}"

    if [[ "$DAEMON" == "true" ]]; then
        title "Larka — Démarrage (arrière-plan)"
        nohup php "${PHP_S_DISCRET[@]}" -S "${HOST}:${PORT}" -t . router.php >>"$LOG_FILE" 2>&1 &
        local pid=$!
        echo "$pid" > "$PID_FILE"
        planificateur_demarrer "$pid"
        sleep 1
        if is_running; then
            ok "Démarré (PID ${pid})"
            echo -e "  Application : ${CYAN}http://${url_host}:${PORT}${NC}"
            echo -e "  Super Admin : ${CYAN}http://${url_host}:${PORT}/?superadmin${NC}"
            echo -e "  Journal     : ${CYAN}${LOG_FILE}${NC}  (./start.sh logs)"
            echo -e "  Arrêt       : ${CYAN}./start.sh stop${NC}"
        else
            rm -f "$PID_FILE"
            die 41 "Le serveur n'a pas démarré. Voir ${LOG_FILE}"
        fi
    else
        title "Larka — Démarrage (premier plan)"
        echo -e "  Application : ${CYAN}http://${url_host}:${PORT}${NC}"
        echo -e "  Super Admin : ${CYAN}http://${url_host}:${PORT}/?superadmin${NC}"
        echo -e "    première visite depuis ce poste : choisissez l'identifiant et le mot de passe"
        echo -e "  ${BLUE}Ctrl+C${NC} pour arrêter"
        [[ "$HOST" != "0.0.0.0" ]] && echo -e "  ${YELLOW}(accès LAN : --host 0.0.0.0)${NC}"
        echo
        # Après « exec », ce processus EST le serveur : le planificateur le suit.
        planificateur_demarrer "$BASHPID"
        exec php -S "${HOST}:${PORT}" -t . router.php
    fi
}

# ── Planificateur : sauvegardes automatiques ─────────────────────────────────
# Le serveur PHP intégré ne sait rien faire « à heure fixe ». Une petite boucle
# l'accompagne : toutes les 10 minutes, elle lance api/outils/sauvegarde-auto.php,
# qui ne fait que les sauvegardes DUES (aucune si rien n'est réglé). Elle
# s'arrête avec le serveur. En production, c'est la tâche cron ; avec le
# démarrage automatique, la minuterie systemd larka-sauvegardes.timer.
planificateur_demarrer() {
    local cible="$1"
    planificateur_arreter
    mkdir -p "$PID_DIR" data/logs
    # Vérifie toutes les 30 s que le serveur tourne encore (et s'arrête sinon) ;
    # lance l'outil 1 minute après le démarrage puis toutes les 10 minutes.
    nohup bash -c 'n=0
        while kill -0 "$1" 2>/dev/null; do
            if (( n % 20 == 2 )); then
                php api/outils/sauvegarde-auto.php --silencieux >> data/logs/sauvegardes.log 2>&1
            fi
            n=$((n + 1)); sleep 30
        done' planificateur-larka "$cible" >/dev/null 2>&1 &
    echo $! > "$PLANIF_PID_FILE"
}
planificateur_arreter() {
    # Arrête la boucle ; une sauvegarde en cours, elle, va jusqu'au bout.
    local p; p="$(cat "$PLANIF_PID_FILE" 2>/dev/null || true)"
    if [[ -n "$p" ]] && ps -o args= -p "$p" 2>/dev/null | grep -q 'planificateur-larka'; then
        kill "$p" 2>/dev/null || true
        pkill -P "$p" -x sleep 2>/dev/null || true
    fi
    rm -f "$PLANIF_PID_FILE"
}

cmd_stop() {
    # Même principe qu'au démarrage : « stop » arrêtait la PRODUCTION dès que le
    # serveur de développement ne tournait pas. Couper les services d'un serveur
    # en service parce qu'on voulait arrêter un « php -S » déjà éteint est une
    # surprise coûteuse. On le dit, on n'agit pas à la place de l'utilisateur.
    if prod_installed 2>/dev/null && ! is_running && ! autostart_active; then
        info "Le serveur de développement n'est pas démarré."
        info "La production, elle, tourne toujours. Pour l'arrêter :"
        info "  ${BOLD}./start.sh prod-stop${NC}"
        return 0
    fi
    if autostart_active; then
        info "Arrêt du service systemd ${AUTOSTART_UNIT}…"
        as_root systemctl stop "$AUTOSTART_UNIT" && ok "Larka arrêté (service systemd)." \
            || warn "Échec de l'arrêt du service."
        autostart_enabled && info "Il redémarrera au prochain boot (désactiver : ./start.sh autostart off)."
        return 0
    fi
    planificateur_arreter
    if is_running; then
        local pid; pid="$(server_pid)"
        kill "$pid" 2>/dev/null || true
        sleep 1
        is_running && kill -9 "$pid" 2>/dev/null || true
        rm -f "$PID_FILE"
        ok "Larka arrêté (PID ${pid})"
    else
        rm -f "$PID_FILE"
        warn "Aucun serveur Larka en cours (démon)."
    fi
}

cmd_status() {
    local h="$HOST"; [[ "$h" == "0.0.0.0" ]] && h="localhost"
    if autostart_installed; then
        if autostart_active; then
            ok "Larka actif — service systemd ${AUTOSTART_UNIT}, http://${h}:${PORT}"
        else
            warn "Larka arrêté (service systemd ${AUTOSTART_UNIT} inactif)."
        fi
        info "Au boot : $(systemctl is-enabled "$AUTOSTART_UNIT" 2>/dev/null || echo '?')  ·  détails : ./start.sh autostart status"
    elif is_running; then
        ok "Larka actif — PID $(server_pid), http://${h}:${PORT}"
        info "Au boot : rien ne redémarre tout seul → ${BOLD}sudo ./start.sh autostart on${NC}"
    else
        warn "Larka arrêté (aucun démon)."
    fi
}

cmd_logs() {
    if autostart_installed; then
        info "Journaux du service systemd — Ctrl+C pour quitter"
        journalctl -u "$AUTOSTART_UNIT" -n 40 -f 2>/dev/null \
            || as_root journalctl -u "$AUTOSTART_UNIT" -n 40 -f
        return 0
    fi
    [[ -f "$LOG_FILE" ]] || err "Aucun journal : ${LOG_FILE} (le serveur a-t-il tourné en démon ?)"
    info "Suivi de ${LOG_FILE} — Ctrl+C pour quitter"
    tail -n 40 -f "$LOG_FILE"
}

# ═══════════════════════════════════════════════════════════════════════════════
# JOURNAL D'ACTIVITÉ
# ═══════════════════════════════════════════════════════════════════════════════
# data/logs/activite-AAAA-MM-JJ.txt : qui s'est connecté, à quelle heure, sur
# quelle page, ce qui a été modifié, ce qui a planté. Un fichier par jour,
# écrit par api/Journal.php.
#
# ⚠ Ce script tourne sous « set -euo pipefail » : toute fonction qui retourne
#   un code non nul AVORTE le script entier. Chaque fonction ci-dessous
#   retourne donc explicitement 0, et chaque substitution est protégée.

# Racine de l'application qui écrit réellement les journaux. En production,
# c'est /var/www/gmao et pas le dépôt depuis lequel start.sh est lancé — sans
# ça on regarde un dossier vide en se demandant pourquoi il ne se passe rien.
journal_root() {
    local d
    for d in "$SCRIPT_DIR" /var/www/gmao; do
        [[ -f "$d/api/index.php" ]] || continue
        # Racine qui contient déjà des journaux : c'est la bonne.
        if compgen -G "$d/data/logs/activite-*.txt" >/dev/null 2>&1; then
            echo "$d"; return 0
        fi
    done
    for d in "$SCRIPT_DIR" /var/www/gmao; do
        [[ -f "$d/api/index.php" ]] && { echo "$d"; return 0; }
    done
    echo "$SCRIPT_DIR"
    return 0
}

# printf %-Ns aligne sur les OCTETS : « Propriétaire » (2 accents = 2 octets de
# plus) casse les colonnes. ${#var} compte les caractères, lui.
jline() {
    local l="$1" v="$2" pad b e n
    # Alignement fiable quelle que soit la locale : nombre de caractères =
    # nombre d'octets moins les octets de continuation UTF-8 (10xxxxxx).
    b="$(printf '%s' "$l" | wc -c | tr -d ' ')"
    e="$(printf '%s' "$l" | LC_ALL=C tr -dc '\200-\277' | wc -c | tr -d ' ')"
    n=$(( b - e ))
    pad=$(( 24 - n )); (( pad < 1 )) && pad=1
    printf '  %s%*s%s\n' "$l" "$pad" "" "$v"
    return 0
}

journal_dir() {
    local root d
    root="$(journal_root)" || root="$SCRIPT_DIR"
    d="$(cd "$root" 2>/dev/null && php -r '@include "api/config.php"; echo defined("LOG_PATH") ? LOG_PATH : "";' 2>/dev/null)" || d=""
    if [[ -n "$d" ]]; then
        # Normaliser « /chemin/api/../data/logs » en « /chemin/data/logs »
        d="$(cd "$(dirname "$d")" 2>/dev/null && pwd)/$(basename "$d")" || d=""
    fi
    [[ -z "$d" ]] && d="$root/data/logs"
    echo "$d"
    return 0
}

journal_file() { echo "$(journal_dir)/activite-$(date +%F).txt"; return 0; }

# Dernier fichier disponible (utile juste après minuit). Chaîne vide si aucun —
# et surtout PAS de code retour non nul, qui ferait sauter le script.
journal_file_latest() {
    local d f
    d="$(journal_dir)"
    f="$d/activite-$(date +%F).txt"
    if [[ -f "$f" ]]; then echo "$f"; return 0; fi
    f="$(ls -1t "$d"/activite-*.txt 2>/dev/null | head -n1)" || f=""
    [[ -n "$f" ]] && echo "$f"
    return 0
}

# Explique POURQUOI il n'y a pas de journal, au lieu de laisser deviner.
journal_diagnostic() {
    local root d owner me
    root="$(journal_root)"
    d="$(journal_dir)"

    echo
    info "Diagnostic du journal d'activité"
    jline "Racine application"   "$root"
    jline "Dossier des journaux" "$d"

    if [[ "$root" != "$SCRIPT_DIR" ]]; then
        warn "L'application déployée n'est PAS ce dossier — les journaux sont écrits dans $root."
    fi

    if [[ ! -d "$d" ]]; then
        warn "Le dossier n'existe pas."
        info "Correction : mkdir -p $(printf %q "$d")"
        return 0
    fi

    owner="$(stat -c '%U:%G %a' "$d" 2>/dev/null || stat -f '%Su:%Sg %Lp' "$d" 2>/dev/null || echo '?')"
    me="$(id -un 2>/dev/null || echo '?')"
    jline "Propriétaire / droits" "$owner"
    jline "Utilisateur courant"   "$me"

    # En production, c'est PHP-FPM (www-data) qui écrit, pas vous.
    if prod_installed 2>/dev/null; then
        jline "Mode" "production (PHP-FPM écrit en tant que www-data)"
        if ! as_root -u www-data test -w "$d" 2>/dev/null && ! sudo -n -u www-data test -w "$d" 2>/dev/null; then
            warn "www-data ne semble pas pouvoir écrire dans $d."
            info "Correction : sudo chown -R www-data:www-data $(printf %q "$d") && sudo chmod 775 $(printf %q "$d")"
        fi
    else
        jline "Mode" "développement (serveur PHP intégré)"
        [[ -w "$d" ]] || { warn "Dossier non accessible en écriture."; \
            info "Correction : chmod u+w $(printf %q "$d")"; }
    fi

    local n
    n="$(ls -1 "$d"/activite-*.txt 2>/dev/null | wc -l | tr -d ' ')" || n=0
    jline "Fichiers présents" "${n:-0}"
    if [[ "${n:-0}" == "0" ]]; then
        echo
        info "Aucun journal pour l'instant. Il se crée à la PREMIÈRE requête servie."
        info "Vérifiez que l'application tourne, puis ouvrez une page dans le navigateur."
        if prod_installed 2>/dev/null; then
            info "Après un déploiement, rechargez PHP-FPM : sudo systemctl reload $(menu_fpm_svc 2>/dev/null || echo php-fpm)"
        fi
        # Un journal PHP présent alors que le journal d'activité est vide
        # signale que Journal.php n'est pas chargé ou échoue à écrire.
        [[ -f "$d/php_errors.log" ]] && { echo; info "Dernières erreurs PHP :"; tail -n 5 "$d/php_errors.log" || true; }
    fi
    return 0
}

# Suivi en direct dans le terminal courant.
journal_follow() {
    local f
    f="$(journal_file_latest)"
    if [[ -z "$f" ]]; then
        journal_diagnostic
        return 0
    fi
    info "Suivi de ${BOLD}${f}${NC} — ${BOLD}Ctrl+C${NC} pour arrêter."
    echo
    trap ':' INT
    tail -n 60 -f "$f" || true
    trap - INT
    return 0
}

# Ouvre une commande dans une NOUVELLE fenêtre de terminal.
# Retourne 1 si aucun émulateur n'est disponible (SSH sans X, conteneur…) :
# l'appelant retombe alors sur un affichage dans le terminal courant.
open_in_terminal() {
    local cmd="$1" ttl="${2:-GMAO}" t
    local prefixe="cd $(printf %q "$PWD"); "

    if [[ "$(uname -s)" == "Darwin" ]] && command -v osascript >/dev/null 2>&1; then
        osascript -e "tell application \"Terminal\" to do script \"cd $(printf %q "$PWD") && $cmd\"" \
                  -e 'tell application "Terminal" to activate' >/dev/null 2>&1 && return 0
    fi

    # Déjà dans tmux : une nouvelle fenêtre, immédiatement visible.
    if [[ -n "${TMUX:-}" ]] && command -v tmux >/dev/null 2>&1; then
        tmux new-window -n "$ttl" "${prefixe}${cmd}" >/dev/null 2>&1 && return 0
    fi

    # Pas dans tmux mais tmux disponible (cas typique en SSH) : on crée une
    # session détachée. Ce n'est pas une « fenêtre » au sens graphique, mais
    # c'est le seul équivalent réel à distance, et elle survit à la déconnexion.
    if [[ -z "${TMUX:-}" ]] && command -v tmux >/dev/null 2>&1; then
        tmux has-session -t larka-journal 2>/dev/null && tmux kill-session -t larka-journal 2>/dev/null || true
        if tmux new-session -d -s larka-journal "${prefixe}${cmd}" 2>/dev/null; then
            ok "Session tmux « larka-journal » créée."
            info "Ouvrez-la ici :        ${BOLD}tmux attach -t larka-journal${NC}"
            info "Ou dans un 2e terminal SSH, plus simple : ${BOLD}cd $(printf %q "$PWD") && ./start.sh journal${NC}"
            return 0
        fi
    fi

    if [[ -n "${DISPLAY:-}" || -n "${WAYLAND_DISPLAY:-}" ]]; then
        for t in x-terminal-emulator gnome-terminal konsole xfce4-terminal \
                 mate-terminal tilix terminator alacritty kitty xterm; do
            command -v "$t" >/dev/null 2>&1 || continue
            case "$t" in
                gnome-terminal) "$t" --title="$ttl" -- bash -c "${prefixe}${cmd}; exec bash" >/dev/null 2>&1 & return 0 ;;
                konsole)        "$t" -p tabtitle="$ttl" -e bash -c "${prefixe}${cmd}; exec bash" >/dev/null 2>&1 & return 0 ;;
                alacritty|kitty) "$t" -e bash -c "${prefixe}${cmd}; exec bash" >/dev/null 2>&1 & return 0 ;;
                xfce4-terminal|mate-terminal|tilix|terminator)
                                "$t" --title="$ttl" -e "bash -c '${prefixe}${cmd}; exec bash'" >/dev/null 2>&1 & return 0 ;;
                *)              "$t" -T "$ttl" -e bash -c "${prefixe}${cmd}; exec bash" >/dev/null 2>&1 & return 0 ;;
            esac
        done
    fi
    return 1
}

cmd_journal() {
    local f cmd n
    case "${SUBCOMMAND:-suivre}" in
        fenetre|window|new)
            f="$(journal_file_latest)"
            if [[ -z "$f" ]]; then journal_diagnostic; return 0; fi
            cmd="tail -n 60 -f $(printf %q "$f")"
            if open_in_terminal "$cmd" "GMAO — journal d'activité"; then
                ok "Journal ouvert dans une nouvelle fenêtre."
                info "Fichier : ${f}"
            else
                warn "Impossible d'ouvrir une fenêtre depuis ici."
                info "Vous êtes probablement en SSH ou dans un conteneur : un script"
                info "exécuté sur le serveur ne peut pas ouvrir de fenêtre sur VOTRE poste."
                echo
                info "Pour un vrai second écran, ouvrez un autre terminal SSH et lancez :"
                echo -e "    ${BOLD}cd $(printf %q "$PWD") && ./start.sh journal${NC}"
                info "Ou installez tmux (${BOLD}apt install tmux${NC}) pour un affichage côte à côte."
                echo
                info "En attendant, suivi dans cette fenêtre :"
                echo
                journal_follow
            fi
            ;;
        fichier|file|path)   journal_file_latest || true; [[ -n "$(journal_file_latest)" ]] || journal_file ;;
        dossier|dir)         journal_dir ;;
        liste|list)
            title "Journaux d'activité disponibles"
            n="$(ls -1 "$(journal_dir)"/activite-*.txt 2>/dev/null | wc -l | tr -d ' ')" || n=0
            if [[ "${n:-0}" == "0" ]]; then journal_diagnostic
            else ls -1sh "$(journal_dir)"/activite-*.txt 2>/dev/null || true; fi
            ;;
        doctor|diag|diagnostic) journal_diagnostic ;;
        *)                   journal_follow ;;
    esac
    return 0
}

cmd_reset() {
    title "Larka — Réinitialisation des bases (DESTRUCTIF)"
    confirm "Supprimer définitivement les bases ${DB_NAME} et ${DB_ADMIN} ?" || { warn "Annulé."; exit 0; }
    ensure_postgres_running || die 21 "PostgreSQL non démarré."
    drop_databases
    create_databases || die 22 "Échec de recréation des bases."
    ok "Bases réinitialisées. Relancez : ./start.sh start"
}

# Séquelles des versions ≤ 2.1.0 (corrigées en 2.1.1, mais pas réparées d'elles-mêmes) :
#  - .env / config.json / config.key passés à root après un « sudo ./start.sh … » ou une
#    mise à jour lancée en root : PHP (PHP-FPM, www-data) ne les lit plus → erreurs de base ;
#  - SharePoint désactivé d'office par un lancement sans terminal ;
#  - config.json régénéré : la copie « config.json.bak-… » est la seule trace des réglages.
# doctor le signale ; doctor --fix rend les fichiers à leur propriétaire (rien d'autre).
doctor_fichiers_reglages() {
    local r attendu f prop grp
    r="$(larka_racine_config)"
    if prod_installed 2>/dev/null && [[ "$r" == "$(prod_dir)" ]] && id -u www-data >/dev/null 2>&1; then
        attendu="www-data"
    else
        attendu="$(stat -c %U "$r" 2>/dev/null || echo "")"
    fi
    [[ -n "$attendu" ]] || return 0
    grp="$(id -gn "$attendu" 2>/dev/null || echo "$attendu")"
    for f in .env config.json config.key; do
        [[ -f "$r/$f" ]] || continue
        prop="$(stat -c %U "$r/$f" 2>/dev/null || echo "?")"
        if [[ "$prop" == "$attendu" ]]; then
            ok "$f appartient à ${attendu}"
        elif [[ "$DOCTOR_FIX" == "true" ]]; then
            if as_root chown "${attendu}:${grp}" "$r/$f"; then
                case "$f" in
                    config.json) [[ "$attendu" == "www-data" ]] && as_root chmod 640 "$r/$f" || as_root chmod 600 "$r/$f" ;;
                    *) as_root chmod 600 "$r/$f" ;;
                esac
                ok "$f rendu à ${attendu} (il appartenait à ${prop})"
            else
                warn "$f appartient à ${prop} au lieu de ${attendu} : correction impossible (sudo requis)"
            fi
        else
            warn "$f appartient à ${prop}, pas à ${attendu} : Larka risque de ne plus le lire  [GMAO-E35]\n   Réparer : ./start.sh doctor --fix   (ou : sudo chown ${attendu}:${grp} $(printf %q "$r/$f"))"
        fi
    done
    # SharePoint coupé alors que la connexion Microsoft est active : choix délibéré, ou séquelle de la 2.1.0.
    if [[ -f "$r/config.json" ]] && command -v php >/dev/null 2>&1; then
        local sp
        sp="$(php -r '$c=json_decode(@file_get_contents($argv[1]),true);
                      $ms=!empty($c["microsoft_oauth"]["actif"]); $sp=$c["sharepoint"]["actif"]??null;
                      echo ($ms && $sp===false) ? "coupe" : "";' "$r/config.json" 2>/dev/null || true)"
        [[ "$sp" == "coupe" ]] && info "SharePoint désactivé alors que la connexion Microsoft est active. S'il a disparu tout seul après une relance\n   (défaut des versions ≤ 2.1.0) : ${BOLD}./start.sh sharepoint on${NC}"
    fi
    local baks
    baks="$(ls -1t "$r"/config.json.bak-* 2>/dev/null | head -3 || true)"
    [[ -n "$baks" ]] && info "Anciennes copies de config.json (réglages perdus ? comparez-les avec l'actuel) :\n$(sed 's/^/     /' <<< "$baks")"
    return 0
}

cmd_doctor() {
    title "Larka — Diagnostic$( [[ "$DOCTOR_FIX" == "true" ]] && echo ' (avec auto-réparation)' )"
    if command -v php >/dev/null 2>&1; then
        if php_version_ok; then ok "PHP $(php_version) présent"
        else warn "PHP $(php_version) trop ancien (8.1+ requis)  [GMAO-E12]"; fi
        local miss; miss="$(check_php || true)"
        [[ -n "$miss" ]] && warn "Extensions manquantes :${miss}  [GMAO-E11]" || ok "Extensions PHP OK (${REQUIRED_EXT[*]})"
        # sodium : pas indispensable au fonctionnement (donc hors REQUIRED_EXT,
        # qui bloque le démarrage), mais sans elle la signature des mises à jour
        # ne peut pas être vérifiée et aucune ne s'installe par l'interface.
        php -r 'exit(function_exists("sodium_crypto_sign_verify_detached") ? 0 : 1);' 2>/dev/null \
            && ok "Extension sodium présente (signature des mises à jour)" \
            || warn "Extension PHP sodium absente : mises à jour par l'interface impossibles (php-sodium)"
    else warn "PHP absent  [GMAO-E10]"; fi

    # Prérequis de CETTE version (version.json) : PHP, extensions, SQLite,
    # serveur de base, droits d'écriture, place disque.
    if command -v php >/dev/null 2>&1 && [[ -f api/outils/prerequis.php ]]; then
        local _l
        while IFS= read -r _l; do
            case "$_l" in
                "✓"*) ok "${_l#✓ }" ;;
                "⚠"*) warn "${_l#⚠ }" ;;
                "✗"*) err_line "${_l#✗ }  [GMAO-E13]" ;;
            esac
        done < <(php api/outils/prerequis.php 2>&1 || true)
    fi

    if command -v psql >/dev/null 2>&1; then ok "Client PostgreSQL présent"
        if pg_isready -q 2>/dev/null; then ok "Serveur PostgreSQL actif"
        elif [[ "$DOCTOR_FIX" == "true" ]]; then
            warn "Serveur PostgreSQL injoignable → tentative de démarrage (--fix)…"
            ensure_postgres_running && ok "PostgreSQL démarré." || warn "Échec  [GMAO-E21]"
        else warn "Serveur PostgreSQL injoignable  [GMAO-E21]  (réparer : ./start.sh doctor --fix)"; fi
    else warn "PostgreSQL absent  [GMAO-E20]"; fi

    [[ -f .env ]]        && ok ".env présent"        || warn ".env absent  [GMAO-E30] (./start.sh install)"
    [[ -f config.json ]] && ok "config.json présent" || warn "config.json absent  [GMAO-E30] (./start.sh install)"
    doctor_fichiers_reglages

    if [[ -f .env && -f config.json ]] && command -v php >/dev/null 2>&1; then
        local out; out="$(test_db_connection)"
        if [[ "$out" == "OK" ]]; then
            # Noms lus dans config.json : ce sont eux que Larka utilise.
            local _db _role
            _db="$(config_read_db dbname)"; _db="${_db:-$DB_NAME}"
            _role="$(config_read_db user)"; _role="${_role:-$DB_USER}"
            ok "Connexion à la base '${_db}' OK"
            local cdb; cdb="$(test_role_createdb)"
            [[ "$cdb" == "YES" ]] \
                && ok "Rôle '${_role}' peut créer des bases (provisionnement multi-tenant OK)" \
                || warn "Rôle '${_role}' sans droit CREATEDB — provisionnement de tenant impossible  [GMAO-E24]\n   Corrigez : sudo -u postgres psql -c \"ALTER ROLE ${_role} CREATEDB;\"  (ou ./start.sh setup)"
        else
            warn "Connexion base KO  [GMAO-E23] : ${out}"
        fi
    fi
    # PostgreSQL après une mise à jour du système (glibc, ICU) : version de
    # collation des bases ≠ celle du système → index à reconstruire.
    if [[ -f "$(larka_racine_config)/config.json" ]] && command -v php >/dev/null 2>&1 \
       && declare -F sys_pg_collation_reparer >/dev/null; then
        local coll rcc=0
        coll="$(php "$(larka_racine_config)/api/outils/postgresql.php" collation 2>/dev/null)" || rcc=$?
        if [[ $rcc -eq 0 ]]; then
            while IFS= read -r l; do [[ -n "$l" ]] && ok "$l"; done <<< "$coll"
        elif [[ $rcc -eq 3 ]]; then
            while IFS= read -r l; do [[ -n "$l" ]] && warn "$l"; done <<< "$coll"
            if [[ "$DOCTOR_FIX" == "true" ]]; then
                sys_pg_collation_reparer "$(larka_racine_config)" || true
            fi
        fi
    fi

    if autostart_installed; then
        autostart_active && ok "Service systemd ${AUTOSTART_UNIT} actif" \
                         || warn "Service systemd ${AUTOSTART_UNIT} installé mais inactif (./start.sh start)"
        autostart_enabled && ok "Démarrage automatique au boot : activé" \
                          || warn "Unité présente mais NON activée au boot (sudo systemctl enable ${AUTOSTART_UNIT})"
    elif prod_installed; then
        ok "Production Nginx + PHP-FPM détectée (services gérés par systemd)"
    else
        is_running && ok "Serveur (démon) actif — PID $(server_pid)" || info "Serveur (démon) non lancé"
        info "Après un reboot, rien ne redémarre tout seul → ${BOLD}sudo ./start.sh autostart on${NC}"
    fi
}

# ═══════════════════════════════════════════════════════════════════════════════
# DÉMARRAGE AUTOMATIQUE AU BOOT (mode autonome, serveur PHP intégré)
# ═══════════════════════════════════════════════════════════════════════════════
# « ./start.sh autostart on » installe une unité systemd (larka.service) qui :
#   • démarre Larka à CHAQUE boot de la machine (plus rien à relancer à la main),
#   • redémarre le serveur s'il plante (Restart=on-failure),
#   • démarre APRÈS PostgreSQL (After/Wants), lui-même activé au boot.
# L'adresse utilisée est celle mémorisée dans config.json : celle que vous avez
# déjà. Les commandes start/stop/status pilotent ce service quand il existe.
# NB : ne concerne PAS la production Nginx + PHP-FPM (services déjà activés au
# boot par ./start.sh prod).

autostart_unit_path() { echo "/etc/systemd/system/${AUTOSTART_UNIT}"; }

autostart_installed() {
    [[ -d /run/systemd/system ]] || return 1
    command -v systemctl >/dev/null 2>&1 || return 1
    [[ -f "$(autostart_unit_path)" ]]
}

autostart_active() {
    autostart_installed && [[ "$(systemctl is-active "$AUTOSTART_UNIT" 2>/dev/null)" == "active" ]]
}

autostart_enabled() {
    autostart_installed && [[ "$(systemctl is-enabled "$AUTOSTART_UNIT" 2>/dev/null)" == "enabled" ]]
}

autostart_write_unit() {
    # Génère l'unité pour CE dossier, CET utilisateur et l'adresse mémorisée.
    local runuser rungroup phpbin
    runuser="${SUDO_USER:-}"
    [[ -z "$runuser" || "$runuser" == "root" ]] && runuser="$(stat -c '%U' "$SCRIPT_DIR" 2>/dev/null || echo root)"
    rungroup="$(id -gn "$runuser" 2>/dev/null || echo "$runuser")"
    phpbin="$(command -v php)" || die 10 "PHP introuvable — ./start.sh install"

    as_root tee "$(autostart_unit_path)" >/dev/null <<EOUNIT
# Unité générée par ./start.sh autostart — régénérée à chaque « autostart on ».
# Pour changer l'adresse : ./start.sh autostart on --host X --port N
[Unit]
Description=Larka — serveur applicatif (PHP intégré, démarrage automatique)
After=network.target postgresql.service
Wants=postgresql.service

[Service]
Type=simple
User=${runuser}
Group=${rungroup}
WorkingDirectory=${SCRIPT_DIR}
# Sans PHP_CLI_SERVER_WORKERS, le serveur PHP intégré est MONO-thread :
# une requête longue (assistant IA local) gèlerait tout le site.
Environment=PHP_CLI_SERVER_WORKERS=8
ExecStart=${phpbin} -q -S ${HOST}:${PORT} -t ${SCRIPT_DIR} ${SCRIPT_DIR}/router.php
Restart=on-failure
RestartSec=3

# Renforcement
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=full
ProtectHome=read-only
ReadWritePaths=${SCRIPT_DIR}

[Install]
WantedBy=multi-user.target
EOUNIT
    ok "Unité écrite : $(autostart_unit_path)  (utilisateur : ${runuser})"

    # Sauvegardes automatiques : une minuterie, toutes les 10 minutes (seules
    # les sauvegardes DUES sont faites, aucune si rien n'est réglé).
    as_root tee /etc/systemd/system/larka-sauvegardes.service >/dev/null <<EOSVC
# Généré par ./start.sh autostart — sauvegardes automatiques de Larka.
[Unit]
Description=Larka — sauvegardes automatiques
After=postgresql.service

[Service]
Type=oneshot
User=${runuser}
Group=${rungroup}
WorkingDirectory=${SCRIPT_DIR}
ExecStart=${phpbin} ${SCRIPT_DIR}/api/outils/sauvegarde-auto.php --silencieux
NoNewPrivileges=true
PrivateTmp=true
EOSVC
    as_root tee /etc/systemd/system/larka-sauvegardes.timer >/dev/null <<EOTMR
# Généré par ./start.sh autostart.
[Unit]
Description=Larka — sauvegardes automatiques (toutes les 10 minutes)

[Timer]
OnBootSec=2min
OnUnitActiveSec=10min
Persistent=true

[Install]
WantedBy=timers.target
EOTMR
}

cmd_autostart() {
    local action="${SUBCOMMAND:-status}" u h
    case "$action" in
        on|enable)
            title "Larka — Activation du démarrage automatique (boot)"
            if ! command -v systemctl >/dev/null 2>&1 || [[ ! -d /run/systemd/system ]]; then
                die 50 "systemd n'est pas actif sur cette machine — démarrage au boot indisponible."
            fi
            if [[ $EUID -ne 0 ]] && ! command -v sudo >/dev/null 2>&1; then
                die 50 "Cette action nécessite root (su -, ou installez sudo)."
            fi
            if prod_installed; then
                warn "Une production Nginx + PHP-FPM est installée sur cette machine :"
                warn "ses services démarrent déjà au boot. « autostart » vise le mode"
                warn "autonome (serveur PHP intégré) — les deux écouteraient en parallèle."
                confirm "  Activer quand même le service autonome ?" || { warn "Annulé."; return 0; }
            fi
            require_setup
            save_server_settings
            autostart_write_unit
            info "Activation de PostgreSQL au boot…"
            as_root systemctl enable postgresql >/dev/null 2>&1 \
                && ok "PostgreSQL démarrera au boot." \
                || warn "Impossible d'activer postgresql au boot (déjà fait ?)."
            # Si un démon « PID file » tourne encore, on lui cède la place proprement.
            if [[ -f "$PID_FILE" ]] && is_running; then
                info "Arrêt de l'instance manuelle (le service systemd prend le relais)…"
                cmd_stop || true
            fi
            as_root systemctl daemon-reload
            as_root systemctl enable --now "$AUTOSTART_UNIT" \
                || { prod_journal "$AUTOSTART_UNIT"; die 33 "Échec de l'activation de ${AUTOSTART_UNIT}."; }
            as_root systemctl enable --now larka-sauvegardes.timer >/dev/null 2>&1 \
                && ok "Sauvegardes automatiques : minuterie activée (larka-sauvegardes.timer)." \
                || warn "Minuterie des sauvegardes non activée : sudo systemctl enable --now larka-sauvegardes.timer"
            sleep 1
            h="$HOST"; [[ "$h" == "0.0.0.0" ]] && h="localhost"
            ok "Larka démarrera désormais TOUT SEUL à chaque boot."
            echo -e "  Application : ${CYAN}http://${h}:${PORT}${NC}"
            echo -e "  Journaux    : ${CYAN}journalctl -u ${AUTOSTART_UNIT%.service} -f${NC}"
            echo -e "  Désactiver  : ${CYAN}./start.sh autostart off${NC}"
            ;;
        off|disable)
            title "Larka — Désactivation du démarrage automatique"
            autostart_installed || { warn "Le démarrage automatique n'est pas installé — rien à faire."; return 0; }
            as_root systemctl disable --now "$AUTOSTART_UNIT" 2>/dev/null || true
            as_root systemctl disable --now larka-sauvegardes.timer 2>/dev/null || true
            as_root rm -f "$(autostart_unit_path)" /etc/systemd/system/larka-sauvegardes.service /etc/systemd/system/larka-sauvegardes.timer
            as_root systemctl daemon-reload
            ok "Démarrage automatique désactivé (l'application est arrêtée)."
            info "Relancer à la main : ./start.sh start  (ou réactiver : ./start.sh autostart on)"
            ;;
        status|"")
            title "Larka — Démarrage automatique : état"
            if ! autostart_installed; then
                warn "Non installé — après un reboot, Larka ne démarre PAS tout seul."
                info "Activer : ${BOLD}sudo ./start.sh autostart on${NC}"
                return 0
            fi
            printf '  %-14s %s\n' "Unité"     "$(autostart_unit_path)"
            printf '  %-14s %s\n' "Au boot"   "$(systemctl is-enabled "$AUTOSTART_UNIT" 2>/dev/null || echo '?')"
            printf '  %-14s %s\n' "Actuel"    "$(systemctl is-active  "$AUTOSTART_UNIT" 2>/dev/null || echo '?')"
            printf '  %-14s %s\n' "PostgreSQL" "$(systemctl is-enabled postgresql 2>/dev/null || echo '?') au boot"
            u="$(grep -oP 'ExecStart=.*-S \K[^ ]+' "$(autostart_unit_path)" 2>/dev/null || true)"
            [[ -n "$u" ]] && printf '  %-14s %s\n' "Adresse" "http://${u/0.0.0.0/localhost}"
            ;;
        *)
            die 2 "Sous-commande inconnue : autostart ${action}\n   → ./start.sh autostart on|off|status"
            ;;
    esac
}

# ── Épreuves de sécurité ─────────────────────────────────────────────────────
#
# Elles vivent dans outils/epreuves/ et ne partent pas en production. Elles
# vérifient que les protections du moteur tiennent encore : formules qui ne
# peuvent pas atteindre PHP, opérateurs fermés, invariants de sécurité.
#
# POURQUOI C'EST BRANCHÉ ICI, ET PAS LAISSÉ À LA BONNE VOLONTÉ
# Une vérification qu'il faut penser à lancer n'est jamais lancée. Elle l'est
# donc AUTOMATIQUEMENT avant chaque mise en production — le seul moment où elle
# compte vraiment, et le seul où l'on accepte d'attendre trois secondes.
#
# Quatre suites sur douze exigent une base. Elles sont ignorées ici : faire
# échouer un déploiement parce qu'un poste n'a pas de base, c'est apprendre aux
# gens à passer outre — et une alarme qu'on contourne par habitude ne sert plus
# à rien.
#
# « autorisations » en fait partie, et c'est un regret : c'est elle qui vérifie
# que les rôles déclarés par un module font frontière. Pour l'exécuter, lancez
# la suite complète sur un poste où une base est configurée :
#   bash outils/epreuves/toutes.sh
cmd_epreuves() {
    [[ -d outils/epreuves ]] || { warn "outils/epreuves absent — rien à vérifier."; return 0; }
    command -v php >/dev/null 2>&1 || { warn "PHP absent — épreuves ignorées."; return 0; }

    local echec=0 nom
    # « layout » rejoint les rapides : la mise en page est la primitive la plus
    # proche du rendu, donc celle par laquelle on tenterait d'y faire entrer du
    # style ou du comportement. Elle ne demande aucune base et coûte un instant.
    # « acces-coeur » aussi : elle garde les contrôles d'accès des routes du cœur
    # (documents, rôles, CSRF, routeur, audit). Autonome — copie jetable,
    # SQLite, serveur intégré sur un port libre — elle ne demande aucune base.
    # « mise-a-jour » enfin : seules les versions signées par l'éditeur doivent
    # s'installer, et aucun retour arrière ne doit défaire cette règle.
    # Installation jetable, aucun réseau, quelques dizaines de millisecondes.
    for nom in invariants expressions conditions layout reference acces-coeur mise-a-jour csp; do
        local f="outils/epreuves/test-${nom}.php"
        [[ -f "$f" ]] || continue
        local sortie
        if sortie=$(php "$f" 2>&1); then
            # Une épreuve qui ne peut pas tourner (extension absente…) sort
            # avec 0 et « ⏭️ » : ce n'est pas un succès, on le dit.
            if grep -q '⏭️' <<<"$sortie"; then
                printf "   \033[33m–\033[0m %s (sautée : %s)\n" "$nom" \
                    "$(grep -m1 '⏭️' <<<"$sortie" | sed 's/^[[:space:]]*⏭️[[:space:]]*//')"
            else
                printf "   \033[32m✓\033[0m %s\n" "$nom"
            fi
        else
            printf "   \033[31m✗\033[0m %s\n" "$nom"
            echec=1
        fi
    done

    # L'assistant : même question, même réponse, quel que soit le modèle local.
    # Épreuve hermétique (base SQLite jetable, aucun modèle requis) : elle ne
    # demande que pdo_sqlite. Sans lui, elle est signalée et ignorée — même règle
    # que pour les suites qui exigent une base.
    if [[ -f outils/epreuves/test-assistant.php ]]; then
        if ! php -r 'exit(extension_loaded("pdo_sqlite") ? 0 : 1);' >/dev/null 2>&1; then
            printf "   \033[33m–\033[0m assistant (pdo_sqlite absent, ignorée)\n"
        elif php outils/epreuves/test-assistant.php >/dev/null 2>&1; then
            printf "   \033[32m✓\033[0m assistant\n"
        else
            printf "   \033[31m✗\033[0m assistant\n"; echec=1
        fi
    fi

    if command -v node >/dev/null 2>&1; then
        if [[ -f outils/epreuves/verif-globales.js ]]; then
            if node outils/epreuves/verif-globales.js >/dev/null 2>&1; then
                printf "   \033[32m✓\033[0m liaisons globales\n"
            else
                printf "   \033[31m✗\033[0m liaisons globales\n"; echec=1
            fi
        fi
        # Le RENDU de la mise en page : la seule épreuve qui lit le HTML
        # réellement produit. Elle tourne sans navigateur, donc elle tourne.
        if [[ -f outils/epreuves/test-rendu-layout.js ]]; then
            if node outils/epreuves/test-rendu-layout.js >/dev/null 2>&1; then
                printf "   \033[32m✓\033[0m rendu de la mise en page\n"
            else
                printf "   \033[31m✗\033[0m rendu de la mise en page\n"; echec=1
            fi
        fi
    fi
    return $echec
}

cmd_prod() {
    [[ -f deploy/install.sh ]] || die 30 "deploy/install.sh introuvable (lancez la commande depuis la racine du projet)."
    [[ $EUID -ne 0 && ! "$(command -v sudo)" ]] && die 50 "Le mode production nécessite les droits root (su - puis bash start.sh prod, ou installez sudo)."

    # ── Contrôle avant mise en production ────────────────────────────────
    # Bloquant, et c'est le but : livrer un moteur dont les protections sont
    # tombées est pire que ne pas livrer. « --sans-epreuves » existe pour le
    # cas d'urgence, mais il faut l'écrire — donc le décider.
    if [[ " $* " != *" --sans-epreuves "* ]]; then
        title "Vérification des protections"
        if ! cmd_epreuves; then
            echo
            die 60 "Une protection du moteur ne répond plus.\n   → Lancez « bash outils/epreuves/toutes.sh » pour le détail.\n   → Pour passer outre malgré tout : ./start.sh prod --sans-epreuves"
        fi
        echo
    fi

    title "Larka — Installation production (Nginx + PHP-FPM + HTTPS)"
    as_root bash deploy/install.sh "${@/--sans-epreuves/}"
}

cmd_help() {
    local help_host="$HOST"; [[ "$help_host" == "0.0.0.0" ]] && help_host="localhost"
    cat <<EOHELP
${BOLD}Larka — Lanceur unifié${NC}

${BOLD}Console interactive${NC}
  ./start.sh                Menu : démarrer, arrêter, redémarrer, changer l'URL…
                            (↑↓ ou 1-9, Entrée pour valider, q pour quitter)

${BOLD}Mise en route (1 commande)${NC}
  ./start.sh install        Installe PHP + PostgreSQL, crée la base, génère .env/config.json
  ./start.sh start          Démarre l'application (Ctrl+C pour arrêter)

  → puis ouvrez ${CYAN}http://${help_host}:${PORT}${NC}

${BOLD}Cycle de vie${NC}
  start [-d]   Démarre (au premier plan, ou -d/--daemon en arrière-plan)
  stop         Arrête le serveur (démon ou service systemd)
  restart      Redémarre
  status       État du serveur (+ démarrage au boot)
  logs         Suit le journal (fichier, ou journalctl si service systemd)
  journal      Journal d'activité : qui, quand, quelle page, quelles modifs
                 journal            suit en direct dans ce terminal
                 journal fenetre    ouvre une NOUVELLE fenêtre de terminal
                 journal liste      liste les fichiers disponibles
                 journal fichier    affiche le chemin du journal du jour
                 journal doctor     explique pourquoi rien ne s'écrit

  ${CYAN}L'adresse est mémorisée${NC} : un --host/--port donné une fois devient le
  nouveau défaut (écrit dans config.json). Plus rien à retaper ensuite.

${BOLD}Démarrage automatique au boot (systemd)${NC}
  autostart on      Larka (+ PostgreSQL) démarrent seuls à chaque boot,
                    et redémarrent en cas de crash — plus rien à relancer.
  autostart off     Désactive et arrête le service
  autostart status  État (unité, boot, adresse)

${BOLD}Maintenance${NC}
  setup        Prépare base + .env + config.json (sans installer de paquet système)
  maj [zip]    Met Larka à jour depuis l'archive d'une nouvelle version (LARKA_GMAO-x.y.z.zip),
               SANS toucher à config.json, .env, data/ ni aux réglages des modules ;
               fichiers et bases sauvegardés avant. --sans-signature : archive non signée.
               --cible <dossier> : installation à mettre à jour (défaut : celle-ci)
               (ne décompressez plus une nouvelle version par-dessus l'ancienne)
  demo [zip]   Installe la démonstration « Ville de Larkaville » (met la base actuelle de côté)
               — aussi proposée au premier démarrage (start) si son archive est là
  reset        Recrée les bases depuis zéro (DESTRUCTIF)
  doctor       Diagnostic complet   ·   doctor --fix : auto-réparation
               (dont PostgreSQL après une mise à jour du système : index reconstruits,
               aucune donnée modifiée)
  epreuves     Vérifie que les protections du moteur tiennent encore
               (lancé automatiquement avant « prod »)
  prod ...     Installation production (délègue à deploy/install.sh)
  sharepoint on|off|etat
               SharePoint (liens vers fichiers Microsoft 365) — demandé au 1er lancement

${BOLD}Production (services déjà installés)${NC}
  bump-assets  Force les navigateurs à reprendre les JS/CSS (après un déploiement)
  prod-start   Démarre Nginx + PHP-FPM
  prod-stop    Arrête Nginx + PHP-FPM (demande confirmation)
  prod-restart Redémarre PHP-FPM + recharge Nginx, puis affiche et teste l'URL
               (URL retrouvée dans config.json : rien à ressaisir)
               --hard : restart complet de Nginx   --open : ouvre le navigateur

${BOLD}Options${NC}
  --port N         Port (défaut ${PORT})
  --host H         Bind (défaut ${HOST} ; ${BOLD}0.0.0.0${NC} pour exposer au LAN)
  --db-user U      Utilisateur PostgreSQL (défaut ${DB_USER})
  --db-pass P      Mot de passe de dev (défaut ${DB_PASS})
  -y, --yes        Non interactif
  --hard           (prod-restart) restart complet de Nginx plutôt qu'un reload
  --open           (prod-restart) ouvre l'URL dans le navigateur
  --fix-pg-auth    Corrige pg_hba.conf (auth TCP locale)

${BOLD}Exemples${NC}
  ./start.sh install -y                 Installe et prépare tout, sans question
  ./start.sh start -d --host 0.0.0.0 --port 9000
                                        Démarre en démon sur le LAN, port 9000
                                        (adresse mémorisée : ensuite « start » suffit)
  sudo ./start.sh autostart on          Démarrage automatique à chaque boot
  ./start.sh doctor --fix               Diagnostic + réparation automatique
  ./start.sh setup --fix-pg-auth        Répare l'authentification PostgreSQL
  sudo ./start.sh prod-restart          Redémarre nginx + php-fpm et affiche l'URL
  sudo ./start.sh prod --domain gmao.example.com
                                        Production avec certificat Let's Encrypt (Certbot)
  sudo ./start.sh prod --domain gmao.example.com --cert fullchain.pem --key privkey.pem
                                        Production avec un certificat existant (sans Certbot)
                                        (autres choix : --no-certbot, --behind-proxy)
EOHELP
}

# ═══════════════════════════════════════════════════════════════════════════════
# PRODUCTION — PILOTAGE DES SERVICES (Nginx + PHP-FPM)
# ═══════════════════════════════════════════════════════════════════════════════
# À la différence de « prod » (installation, qui délègue à deploy/install.sh et
# redemande le nom de domaine), « prod-restart » ne fait que redémarrer les
# services déjà en place et RETROUVE L'URL TOUT SEUL dans la configuration :
# aucune saisie, aucune réinstallation.

PROD_FPM_SVC="php-fpm"   # renseigné par cmd_prod_restart()

svc_exists() { systemctl cat "$1" &>/dev/null; }

prod_dir() {
    # Racine déployée : /var/www/gmao (cf. deploy/install.sh), sinon dossier courant.
    local d
    for d in /var/www/gmao "$SCRIPT_DIR"; do
        [[ -f "$d/api/index.php" ]] && { echo "$d"; return 0; }
    done
    echo "$SCRIPT_DIR"
}

prod_fpm_version() {
    # La version de PHP-FPM est celle qui PORTE le pool « gmao » — pas forcément
    # celle du CLI (`php -v`) : sur une machine avec plusieurs PHP installés, se
    # fier au CLI redémarre le mauvais service. On lit donc le pool réellement
    # installé, exactement comme deploy/install.sh.
    local f v
    for f in /etc/php/*/fpm/pool.d/gmao.conf; do
        [[ -f "$f" ]] || continue
        v="$(printf '%s' "$f" | grep -oP '(?<=/etc/php/)[0-9]+\.[0-9]+')" || true
        [[ -n "$v" ]] && { echo "$v"; return 0; }
    done
    # Repli 1 : la socket déclarée dans le vhost Nginx.
    v="$(grep -rhoP 'php\K[0-9]+\.[0-9]+(?=-fpm-gmao\.sock)' /etc/nginx/sites-enabled/ 2>/dev/null | head -1)" || true
    [[ -n "$v" ]] && { echo "$v"; return 0; }
    # Repli 2 : version du CLI.
    v="$(php_version)" || true
    [[ -n "$v" && "$v" != "?" ]] && { echo "$v"; return 0; }
    echo ""
}

prod_url() {
    # L'URL de production n'est stockée nulle part en tant que telle (APP_URL est
    # calculée à la volée depuis HTTP_HOST). On la reconstitue depuis ce que
    # deploy/install.sh a écrit avec le domaine saisi à l'installation.
    local dir="$1" url="" sn
    if [[ -f "$dir/config.json" ]] && command -v php &>/dev/null; then
        url="$(php -r '
$c = json_decode(@file_get_contents($argv[1]), true);
if (!is_array($c)) exit;
$cands   = [];
$cands[] = $c["securite"]["allowed_origin"] ?? "";
$cands[] = $c["cors"]["origins"][0] ?? "";
foreach (["microsoft_oauth", "google_oauth"] as $p) {
    $u = (string)($c[$p]["redirect_uri"] ?? "");
    $i = strpos($u, "/oauth/");
    if ($i !== false) $u = substr($u, 0, $i);
    $cands[] = $u;
}
foreach ($cands as $u) {
    $u = rtrim(trim((string)$u), "/");
    if ($u === "" || strpos($u, "*") !== false) continue;
    if (preg_match("#^https?://[A-Za-z0-9._-]+(:[0-9]+)?\$#", $u)) { echo $u; exit; }
}
' "$dir/config.json" 2>/dev/null)" || true
    fi
    # Repli : server_name du vhost Nginx (on ignore le joker « _ »).
    if [[ -z "$url" ]]; then
        sn="$(grep -rhoP '^\s*server_name\s+\K[^;]+' /etc/nginx/sites-enabled/ 2>/dev/null \
              | tr ' ' '\n' | grep -vxE '_|' | head -1)" || true
        [[ -n "$sn" ]] && url="https://${sn}"
    fi
    echo "$url"
}

prod_journal() {
    echo -e "${YELLOW}  ── journal : $1 ──${NC}"
    as_root journalctl -u "$1" -n 15 --no-pager 2>/dev/null | sed 's/^/  /' || true
}

prod_health() {
    local url="$1" code code2 hote local_code
    command -v curl &>/dev/null || { warn "curl absent — contrôle de santé ignoré."; return 0; }

    # ── D'ABORD EN LOCAL, ET C'EST LE CONTRÔLE QUI COMPTE ────────────────
    #
    # Le contrôle partait directement sur l'URL publique. Derrière un tunnel —
    # Cloudflare, ngrok — ou avec un DNS à découpage, la machine ne se joint pas
    # elle-même par cette adresse : curl attendait jusqu'au délai maximal, puis
    # annonçait « aucune réponse » alors que nginx et PHP-FPM répondaient très
    # bien. Un faux négatif qui arrive à chaque démarrage finit par être ignoré,
    # et le jour où la panne est réelle, personne ne la voit.
    #
    # On interroge donc 127.0.0.1 en passant le nom de domaine dans l'en-tête
    # Host : nginx sert le bon vhost, et l'on teste ce que l'on veut vraiment
    # tester — le serveur, pas le chemin réseau qui y mène.
    hote="${url#*://}"; hote="${hote%%/*}"; hote="${hote%%:*}"
    local_code="$(curl -sk -o /dev/null -w '%{http_code}' --max-time 5 \
                  -H "Host: ${hote}" "https://127.0.0.1/" 2>/dev/null)" || true
    if [[ -z "$local_code" || "$local_code" == "000" ]]; then
        local_code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 \
                      -H "Host: ${hote}" "http://127.0.0.1/" 2>/dev/null)" || true
    fi

    code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 "$url" 2>/dev/null)" || true
    if [[ -z "$code" || "$code" == "000" ]]; then
        # Sans vérifier le certificat : distingue « injoignable » de
        # « certificat non valide ».
        code2="$(curl -sk -o /dev/null -w '%{http_code}' --max-time 5 "$url" 2>/dev/null)" || true
        if [[ -n "$code2" && "$code2" != "000" ]]; then
            warn "Le site répond (HTTP ${code2}) mais son certificat TLS n'est pas validé"
            warn "(auto-signé, expiré, ou émis pour un autre domaine) → certbot renew ?"
            return 0
        fi
        # Le serveur va bien, seule l'adresse publique n'est pas joignable
        # DEPUIS CETTE MACHINE. C'est le cas normal derrière un tunnel : on
        # informe, on n'alarme pas.
        if [[ -n "$local_code" && "$local_code" != "000" ]]; then
            ok "Le serveur répond en local (HTTP ${local_code})."
            echo -e "  ${BLUE}ℹ${NC} ${url} n'est pas joignable depuis cette machine —"
            echo -e "    normal derrière un tunnel ou avec un DNS à découpage."
            echo -e "    Vérifiez depuis l'extérieur : ${CYAN}${url}${NC}"
            return 0
        fi
        warn "Aucune réponse, ni sur ${url} ni en local — Nginx ou PHP-FPM sont-ils démarrés ?"
        return 1
    fi
    case "$code" in
        200|301|302|303|304|307|308) ok "Le site répond (HTTP ${code})." ;;
        401|403) ok "Le site répond (HTTP ${code} — accès protégé, normal hors session)." ;;
        502|503|504)
            warn "Nginx répond mais PHP-FPM ne suit pas (HTTP ${code})."
            warn "Piste : socket du pool « gmao » — vérifiez que fastcgi_pass du vhost"
            warn "pointe bien vers /run/php/php${1:-?}-fpm-gmao.sock."
            prod_journal "$PROD_FPM_SVC" ;;
        *) warn "Réponse inattendue : HTTP ${code}." ;;
    esac
}

# ── Production : démarrer / arrêter les services ─────────────────────────────
prod_require_services() {
    if ! command -v systemctl &>/dev/null || [[ ! -d /run/systemd/system ]]; then
        die 50 "systemd n'est pas actif sur cette machine — cette commande vise un serveur Nginx + PHP-FPM (Debian/Ubuntu)."
    fi
    if [[ $EUID -ne 0 ]] && ! command -v sudo &>/dev/null; then
        die 50 "Piloter les services nécessite root (su - puis « bash start.sh … », ou installez sudo)."
    fi
    PROD_FPM_SVC="$(menu_fpm_svc)"
    svc_exists "${PROD_FPM_SVC}.service" \
        || die 30 "Service ${PROD_FPM_SVC} introuvable — la production est-elle installée ? (./start.sh prod)"
    svc_exists "nginx.service" \
        || die 30 "Service nginx introuvable — la production est-elle installée ? (./start.sh prod)"
}

# ── Cache-busting des assets ─────────────────────────────────────────────────
# nginx sert les JS/CSS en « public, immutable » pendant 7 jours : sans changer
# l'URL, un fichier déployé reste INVISIBLE pour tout navigateur déjà venu —
# silencieusement, jusqu'à une semaine. Le ?v= d'index.html est donc la seule
# chose qui fait descendre une mise à jour. Il était figé à "1" et bumpé à la
# main : autant dire jamais. On le fait ici, à chaque déploiement.
# js/page-loader.js relit ce ?v= sur sa propre balise : index.html suffit.
bump_assets() {
    [[ -f index.html ]] || return 0
    local v; v="$(date +%Y%m%d%H%M)"
    if grep -q '?v=' index.html; then
        sed -i -E "s/\?v=[0-9a-zA-Z.]+/?v=${v}/g" index.html
        ok "Assets versionnés en ?v=${v} (cache navigateur contourné)"
    fi
}

cmd_prod_start() {
    title "Larka — Démarrage production (Nginx + PHP-FPM)"
    prod_require_services
    # On valide la conf AVANT : un nginx qui refuse de démarrer laisse le site
    # hors ligne, alors qu'échouer ici ne casse rien.
    if ! as_root nginx -t &>/dev/null; then
        echo; as_root nginx -t 2>&1 | tail -5 | sed 's/^/  /'; echo
        die 32 "Configuration Nginx invalide — rien n'a été démarré."
    fi
    local s
    for s in "$PROD_FPM_SVC" nginx; do
        info "Démarrage de ${s}…"
        as_root systemctl start "$s" || { prod_journal "$s"; die 33 "Échec du démarrage de ${s}."; }
        ok "${s} démarré."
    done
    prod_report_url
}

cmd_prod_stop() {
    title "Larka — Arrêt production (Nginx + PHP-FPM)"
    prod_require_services
    warn "L'application va devenir INJOIGNABLE pour tous les utilisateurs."
    confirm "  Confirmer l'arrêt ?" || { warn "Annulé."; return 0; }
    local s
    for s in nginx "$PROD_FPM_SVC"; do
        info "Arrêt de ${s}…"
        as_root systemctl stop "$s" || warn "Échec de l'arrêt de ${s}."
    done
    for s in nginx "$PROD_FPM_SVC"; do
        printf '  %-18s %s\n' "$s" "$(systemctl is-active "$s" 2>/dev/null || echo inactif)"
    done
    ok "Production arrêtée."
}

# Affiche l'URL (retrouvée dans la config) et teste la réponse.
prod_report_url() {
    local url; url="$(prod_url "$(prod_dir)")"
    echo
    if [[ -z "$url" ]]; then
        warn "URL introuvable dans config.json ni dans le vhost Nginx."
        return 0
    fi
    echo -e "  ${BOLD}Application :${NC} ${CYAN}${url}${NC}"
    echo
    prod_health "$url" "$(prod_fpm_version)" || true
}

cmd_prod_restart() {
    title "Larka — Redémarrage production (Nginx + PHP-FPM)"

    # Le binaire systemctl peut exister sans que systemd soit l'init (conteneurs
    # Docker, WSL sans systemd…). Le test canonique est /run/systemd/system.
    if ! command -v systemctl &>/dev/null || [[ ! -d /run/systemd/system ]]; then
        die 50 "systemd n'est pas actif sur cette machine — cette commande vise un serveur Nginx + PHP-FPM (Debian/Ubuntu)."
    fi
    if [[ $EUID -ne 0 ]] && ! command -v sudo &>/dev/null; then
        die 50 "Piloter les services nécessite root (su - puis « bash start.sh prod-restart », ou installez sudo)."
    fi

    local dir ver fpm bin url
    dir="$(prod_dir)"
    ver="$(prod_fpm_version)"
    fpm="php-fpm"; bin="php-fpm"
    if [[ -n "$ver" ]]; then fpm="php${ver}-fpm"; bin="php-fpm${ver}"; fi
    PROD_FPM_SVC="$fpm"

    info "Application : ${BOLD}${dir}${NC}"
    info "Service PHP : ${BOLD}${fpm}${NC}"

    svc_exists "${fpm}.service" \
        || die 30 "Service ${fpm} introuvable — l'installation production a-t-elle été faite ? (./start.sh prod)"
    svc_exists "nginx.service" \
        || die 30 "Service nginx introuvable — l'installation production a-t-elle été faite ? (./start.sh prod)"

    # ── 1. Valider les configs AVANT de toucher aux services ────────────────
    # Redémarrer avec une conf invalide, c'est couper le site. On échoue donc
    # ici, site encore debout, plutôt que de le laisser par terre.
    info "Vérification de la configuration PHP-FPM…"
    if command -v "$bin" &>/dev/null; then
        if ! as_root "$bin" -t &>/dev/null; then
            echo; as_root "$bin" -t 2>&1 | tail -5 | sed 's/^/  /'; echo
            die 31 "Configuration PHP-FPM invalide — rien n'a été redémarré."
        fi
        ok "Configuration PHP-FPM valide."
    else
        warn "Binaire ${bin} introuvable — test ignoré."
    fi

    info "Vérification de la configuration Nginx…"
    if ! as_root nginx -t &>/dev/null; then
        echo; as_root nginx -t 2>&1 | tail -5 | sed 's/^/  /'; echo
        die 32 "Configuration Nginx invalide — rien n'a été redémarré."
    fi
    ok "Configuration Nginx valide."

    # ── 2. Redémarrer ───────────────────────────────────────────────────────
    # PHP-FPM : restart complet — recycle les workers et vide OPcache, ce qu'on
    # veut après un déploiement de code (un reload ne garantit pas la purge).
    # Le code vient probablement d'être déployé : forcer les navigateurs à
    # reprendre les JS/CSS, sinon ils garderont les anciens jusqu'à 7 jours.
    bump_assets

    info "Redémarrage de ${fpm}…"
    as_root systemctl restart "$fpm" || { prod_journal "$fpm"; die 33 "Échec du redémarrage de ${fpm}."; }
    ok "${fpm} redémarré (OPcache vidé)."

    # Nginx : reload à chaud par défaut = zéro coupure. --hard force un restart.
    if [[ "$HARD" == "true" ]]; then
        info "Redémarrage complet de Nginx (--hard)…"
        as_root systemctl restart nginx || { prod_journal nginx; die 34 "Échec du redémarrage de Nginx."; }
        ok "Nginx redémarré."
    else
        info "Rechargement de Nginx (à chaud, sans coupure)…"
        as_root systemctl reload nginx || { prod_journal nginx; die 34 "Échec du rechargement de Nginx."; }
        ok "Nginx rechargé."
    fi

    # ── 3. État réel des services ───────────────────────────────────────────
    local s st
    for s in "$fpm" nginx; do
        st="$(as_root systemctl is-active "$s" 2>/dev/null)" || true
        if [[ "$st" == "active" ]]; then ok "${s} : actif"
        else warn "${s} : ${st:-inactif}"; prod_journal "$s"; fi
    done

    # ── 4. L'URL, retrouvée toute seule ─────────────────────────────────────
    prod_report_url

    if [[ "$OPEN_URL" == "true" ]]; then
        if   command -v xdg-open &>/dev/null; then (xdg-open "$url" &>/dev/null &) ; ok "Ouvert dans le navigateur."
        elif command -v open     &>/dev/null; then (open "$url" &>/dev/null &)     ; ok "Ouvert dans le navigateur."
        else warn "Aucun navigateur détecté (xdg-open/open) — serveur sans interface graphique."
        fi
    fi
}

# ═══════════════════════════════════════════════════════════════════════════════
# CONSOLE INTERACTIVE
# ═══════════════════════════════════════════════════════════════════════════════
# « ./start.sh » sans argument ouvre ce menu. Toutes les commandes restent
# utilisables en direct (./start.sh start, prod-restart, …) pour le scripting.

MENU_CHOICE=-1

menu_select() {
    # menu_select "item1" "item2" … → indice choisi dans $MENU_CHOICE (-1 = quitter)
    local -a items=("$@")
    local n=${#items[@]} cur=0 key rest i first=1

    # Repli non interactif (pipe, cron) : simple liste numérotée.
    if [[ ! -t 0 || ! -t 1 ]]; then
        for i in "${!items[@]}"; do printf '  %d) %s\n' "$((i+1))" "${items[$i]}"; done
        read -r -p "  Choix : " key || key=""
        if [[ "$key" =~ ^[0-9]+$ ]] && (( key >= 1 && key <= n )); then
            MENU_CHOICE=$((key-1))
        else
            MENU_CHOICE=-1
        fi
        return 0
    fi

    printf '\033[?25l'                       # masquer le curseur
    while true; do
        # Après le 1er rendu, on remonte de n lignes pour réécrire la liste
        # sur place (pas de scroll, pas de clignotement).
        if [[ $first == 1 ]]; then first=0; else printf '\033[%dA' "$n"; fi
        for i in "${!items[@]}"; do
            if (( i == cur )); then
                printf '\033[2K  %s▸ %s%s\n' "${CYAN}${BOLD}" "${items[$i]}" "${NC}"
            else
                printf '\033[2K    %s\n' "${items[$i]}"
            fi
        done

        IFS= read -rsn1 key 2>/dev/null || { cur=-1; break; }
        case "$key" in
            $'\e')   # flèches : ESC [ A/B
                IFS= read -rsn2 -t 0.05 rest 2>/dev/null || rest=""
                case "$rest" in
                    '[A') cur=$(( (cur - 1 + n) % n )) ;;
                    '[B') cur=$(( (cur + 1) % n )) ;;
                esac ;;
            '')      break ;;                                  # Entrée
            k|K)     cur=$(( (cur - 1 + n) % n )) ;;           # vim-like
            j|J)     cur=$(( (cur + 1) % n )) ;;
            q|Q)     cur=-1; break ;;
            [1-9])   if (( key <= n )); then cur=$((key-1)); break; fi ;;
        esac
    done
    printf '\033[?25h'                       # rétablir le curseur
    MENU_CHOICE=$cur
}

prod_installed() {
    [[ -d /run/systemd/system ]] || return 1
    command -v systemctl &>/dev/null || return 1
    svc_exists nginx.service || return 1
    compgen -G "/etc/php/*/fpm/pool.d/gmao.conf" >/dev/null 2>&1 && return 0
    [[ -f /etc/nginx/sites-enabled/gmao ]] && return 0
    return 1
}

menu_fpm_svc() {
    local v; v="$(prod_fpm_version)"
    if [[ -n "$v" ]]; then echo "php${v}-fpm"; else echo "php-fpm"; fi
}

menu_state() {
    # NB : « systemctl is-active » est une lecture — surtout pas de sudo ici,
    # sinon le menu redemanderait le mot de passe à chaque rafraîchissement.
    local h u dir fpm sn sf dot boot
    h="$HOST"; [[ "$h" == "0.0.0.0" ]] && h="localhost"
    if autostart_installed; then
        autostart_enabled && boot="boot : ${GREEN}auto${NC}" || boot="boot : ${YELLOW}manuel${NC}"
        if autostart_active; then
            echo -e "  ${GREEN}●${NC} App  : active (service systemd) — ${CYAN}http://${h}:${PORT}${NC}  (${boot})"
        else
            echo -e "  ${YELLOW}○${NC} App  : arrêtée (service systemd)  (${boot})"
        fi
    elif is_running; then
        echo -e "  ${GREEN}●${NC} Dév  : actif (PID $(server_pid)) — ${CYAN}http://${h}:${PORT}${NC}  (boot : ${YELLOW}manuel${NC})"
    else
        echo -e "  ${YELLOW}○${NC} Dév  : arrêté  (boot : ${YELLOW}manuel${NC} — « autostart » pour l'automatiser)"
    fi

    if prod_installed; then
        dir="$(prod_dir)"; fpm="$(menu_fpm_svc)"
        sn="$(systemctl is-active nginx 2>/dev/null)" || sn="inactif"
        sf="$(systemctl is-active "$fpm" 2>/dev/null)" || sf="inactif"
        u="$(prod_url "$dir")"
        if [[ "$sn" == "active" && "$sf" == "active" ]]; then dot="${GREEN}●${NC}"; else dot="${RED}●${NC}"; fi
        echo -e "  ${dot} Prod : nginx ${sn} · ${fpm} ${sf}${u:+ — ${CYAN}${u}${NC}}"
    else
        echo -e "  ${YELLOW}○${NC} Prod : non installée sur cette machine"
    fi
}

menu_pause() {
    [[ -t 0 ]] || return 0
    echo
    read -rsn1 -p "  ⏎ pour revenir au menu… " _ || true
    echo
}

menu_dev_logs() {
    if [[ ! -f "$LOG_FILE" ]]; then warn "Aucun journal : ${LOG_FILE}"; return 0; fi
    info "Suivi de ${LOG_FILE} — ${BOLD}Ctrl+C${NC} pour revenir au menu."
    echo
    # On neutralise SIGINT le temps du tail : Ctrl+C tue le tail et nous ramène
    # au menu au lieu de terminer tout le script.
    trap ':' INT
    tail -n 40 -f "$LOG_FILE" || true
    trap - INT
}

menu_prod_status() {
    local dir u v fpm s
    dir="$(prod_dir)"; v="$(prod_fpm_version)"; fpm="$(menu_fpm_svc)"
    PROD_FPM_SVC="$fpm"
    title "Production — état"
    for s in "$fpm" nginx; do
        printf '  %-18s %s\n' "$s" "$(systemctl is-active "$s" 2>/dev/null || echo inconnu)"
    done
    u="$(prod_url "$dir")"
    if [[ -n "$u" ]]; then
        echo; echo -e "  URL : ${CYAN}${u}${NC}"; echo
        prod_health "$u" "$v" || true
    fi
    echo; info "Dernières lignes des journaux :"
    prod_journal "$fpm"; prod_journal nginx
}

menu_prod_newurl() {
    local dir cur new bak
    dir="$(prod_dir)"; cur="$(prod_url "$dir")"

    title "Production — changer l'URL / le domaine"
    [[ -n "$cur" ]] && echo -e "  URL actuelle : ${CYAN}${cur}${NC}" && echo
    echo -e "  Seront mis à jour :"
    echo -e "    • le vhost Nginx (server_name) et le certificat TLS (certbot)"
    echo -e "    • ${BOLD}config.json${NC} : allowed_origin, cors.origins et les redirect_uri OAuth"
    echo
    echo -e "  ${YELLOW}⚠${NC}  deploy/install.sh ${BOLD}conserve${NC} un config.json existant : sans la"
    echo -e "     réécriture ci-dessus, le CORS et le SSO resteraient sur l'ancien domaine."
    echo

    read -r -p "  Nouveau domaine (ex: gmao.monentreprise.fr) : " new || return 0
    # On tolère un copier-coller d'URL complète.
    new="$(printf '%s' "$new" | sed -e 's#^[a-zA-Z]*://##' -e 's#/.*##' | tr -d '[:space:]')"
    if [[ -z "$new" ]]; then warn "Annulé."; return 0; fi
    if [[ ! "$new" =~ ^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$ ]]; then
        warn "Domaine invalide : ${new}"; return 0
    fi
    if [[ "$new" == "$(printf '%s' "${cur#*://}")" ]]; then
        warn "C'est déjà le domaine actuel — rien à faire."; return 0
    fi

    echo
    echo -e "  ${BOLD}${cur:-?}${NC}  →  ${BOLD}https://${new}${NC}"
    echo
    confirm "  Confirmer le changement ?" || { warn "Annulé."; return 0; }

    # 1. config.json : sauvegarde puis réécriture des seules clés d'URL.
    if [[ -f "$dir/config.json" ]]; then
        bak="${dir}/config.json.bak-$(date +%Y%m%d-%H%M%S)"
        as_root cp -p "$dir/config.json" "$bak" && ok "Sauvegarde : ${bak}"
        if as_root php -r '
$f = $argv[1]; $d = $argv[2];
$c = json_decode(@file_get_contents($f), true);
if (!is_array($c)) { fwrite(STDERR, "config.json illisible\n"); exit(1); }
$base = "https://" . $d;
if (isset($c["securite"]) && is_array($c["securite"]) && array_key_exists("allowed_origin", $c["securite"]))
    $c["securite"]["allowed_origin"] = $base;
if (isset($c["cors"]["origins"]) && is_array($c["cors"]["origins"]))
    $c["cors"]["origins"] = [$base];
foreach (["microsoft_oauth" => "/oauth/microsoft", "google_oauth" => "/oauth/google"] as $k => $p) {
    if (!empty($c[$k]["redirect_uri"])) $c[$k]["redirect_uri"] = $base . $p;
}
if (file_put_contents($f, json_encode($c, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)) === false) exit(1);
' "$dir/config.json" "$new"; then
            ok "config.json mis à jour (secrets conservés)."
        else
            warn "Échec de la mise à jour de config.json — restauration."
            as_root cp -p "$bak" "$dir/config.json" || true
            return 0
        fi
    fi

    # 2. Vhost Nginx + certificat : délégué à l'installeur, qui sait le faire.
    [[ -f deploy/install.sh ]] || { warn "deploy/install.sh introuvable — vhost non régénéré."; return 0; }
    info "Régénération du vhost Nginx et du certificat pour ${new}…"
    as_root bash deploy/install.sh --domain "$new" || { warn "L'installeur a échoué."; return 0; }

    echo
    ok "Bascule terminée : ${CYAN}https://${new}${NC}"
    echo
    warn "À FAIRE À LA MAIN, sinon la connexion Microsoft/Google cassera :"
    warn "  Azure > App registrations > Authentication > Redirect URIs"
    warn "    → https://${new}/oauth/microsoft"
    warn "  (idem Google Cloud Console pour https://${new}/oauth/google)"
}

menu_open_url() {
    local url="$1"
    [[ -z "$url" ]] && { warn "Aucune URL connue."; return 0; }
    if   command -v xdg-open &>/dev/null; then (xdg-open "$url" &>/dev/null &); ok "Ouvert : ${url}"
    elif command -v open     &>/dev/null; then (open "$url" &>/dev/null &);     ok "Ouvert : ${url}"
    else info "Ouvrez : ${CYAN}${url}${NC}"; warn "(pas de navigateur sur ce serveur)"
    fi
}

menu_dev_addr() {
    # Changement d'adresse guidé : saisie → validation → mémorisation dans
    # config.json → application immédiate (redémarrage si l'app tournait).
    local nh np was=false
    title "Développement — changer l'adresse d'écoute"
    echo -e "  Adresse actuelle : ${CYAN}http://${HOST}:${PORT}${NC}"
    echo -e "  ${YELLOW}Astuce :${NC} 127.0.0.1 = ce poste uniquement · 0.0.0.0 = accessible depuis le LAN"
    echo
    read -r -p "  Hôte  [${HOST}] : " nh || return 0
    read -r -p "  Port  [${PORT}] : " np || return 0
    nh="${nh:-$HOST}"; np="${np:-$PORT}"
    [[ "$np" =~ ^[0-9]+$ ]] && (( np >= 1 && np <= 65535 )) || { warn "Port invalide : ${np}"; return 0; }
    [[ "$nh" =~ ^[A-Za-z0-9.:_-]+$ ]] || { warn "Hôte invalide : ${nh}"; return 0; }
    if [[ "$nh" == "$HOST" && "$np" == "$PORT" ]]; then warn "Adresse inchangée."; return 0; fi

    ( autostart_active || is_running ) && was=true
    HOST="$nh"; PORT="$np"; HOST_EXPLICIT=true; PORT_EXPLICIT=true
    save_server_settings
    if autostart_installed; then
        info "Régénération de l'unité systemd…"
        autostart_write_unit && as_root systemctl daemon-reload
    fi
    if [[ "$was" == "true" ]]; then
        info "Redémarrage sur la nouvelle adresse…"
        ( cmd_stop ) || true
        ( DAEMON=true; cmd_start ) || true
    else
        ok "Nouvelle adresse mémorisée — elle sera utilisée au prochain démarrage."
    fi
}

menu_dev() {
    local -a labels actions
    local h
    while true; do
        printf '\033[2J\033[H'
        title "Développement — serveur PHP intégré"
        menu_state; echo

        labels=(); actions=()
        if autostart_active || is_running; then
            labels+=("Arrêter");                          actions+=(stop)
            labels+=("Redémarrer");                       actions+=(restart)
            labels+=("Suivre les journaux serveur");      actions+=(logs)
            labels+=("Ouvrir dans le navigateur");        actions+=(open)
        else
            labels+=("Démarrer (arrière-plan)");          actions+=(start_bg)
            labels+=("Démarrer au premier plan (Ctrl+C pour arrêter)"); actions+=(start_fg)
        fi
        if [[ ! -f data/demo/comptes-demo.csv ]] && demo_package >/dev/null; then
            labels+=("Installer la démonstration « Ville de Larkaville »"); actions+=(demo)
        fi
        labels+=("Changer l'adresse (host / port)…");     actions+=(addr)
        if autostart_installed; then
            labels+=("Désactiver le démarrage automatique au boot"); actions+=(auto_off)
        else
            labels+=("Activer le démarrage automatique au boot");    actions+=(auto_on)
        fi
        labels+=("Journal d'activité — nouvelle fenêtre"); actions+=(journal_win)
        labels+=("Journal d'activité — ici");              actions+=(journal_here)
        labels+=("État");                                 actions+=(status)
        labels+=("Retour");                               actions+=(back)

        echo -e "  ${YELLOW}↑↓ ou 1-9 · Entrée : valider · q : retour${NC}"; echo
        menu_select "${labels[@]}"
        (( MENU_CHOICE < 0 )) && return 0
        echo
        case "${actions[$MENU_CHOICE]}" in
            start_bg) ( DAEMON=true;  cmd_start ) || true ;;
            start_fg) info "Ctrl+C pour arrêter et revenir au menu."; echo
                      trap ':' INT; ( DAEMON=false; cmd_start ) || true; trap - INT ;;
            stop)     ( cmd_stop ) || true ;;
            restart)  ( cmd_stop; DAEMON=true; cmd_start ) || true ;;
            logs)     menu_dev_logs ;;
            journal_win)  ( SUBCOMMAND=fenetre; cmd_journal ) || true ;;
            journal_here) journal_follow ;;
            addr)     menu_dev_addr || true ;;
            demo)     ( COMMAND=demo; cmd_demo ) || true ;;
            auto_on)  ( SUBCOMMAND=on;  cmd_autostart ) || true ;;
            auto_off) ( SUBCOMMAND=off; cmd_autostart ) || true ;;
            status)   ( cmd_status ) || true ;;
            open)     h="$HOST"; [[ "$h" == "0.0.0.0" ]] && h="localhost"
                      menu_open_url "http://${h}:${PORT}" ;;
            back)     return 0 ;;
        esac
        menu_pause
    done
}

# Sous-menu du journal d'activité — accessible depuis le menu principal, donc
# aussi bien en développement qu'en production.
menu_journal() {
    local -a labels actions
    local f n
    while true; do
        printf '\033[2J\033[H'
        title "Journal d'activité"

        f="$(journal_file_latest)"
        n="$(ls -1 "$(journal_dir)"/activite-*.txt 2>/dev/null | wc -l | tr -d ' ')" || n=0
        jline "Dossier" "$(journal_dir)"
        if [[ -n "$f" ]]; then
            jline "Fichier du jour" "$(basename "$f") ($(du -h "$f" 2>/dev/null | cut -f1 || echo '?'))"
            jline "Fichiers conservés" "${n:-0}"
        else
            jline "État" "aucun journal pour l'instant"
        fi
        echo

        labels=(); actions=()
        labels+=("Ouvrir dans une NOUVELLE fenêtre");  actions+=(win)
        labels+=("Suivre ici (Ctrl+C pour revenir)");  actions+=(here)
        labels+=("Voir les 80 dernières lignes");      actions+=(tail)
        labels+=("Lister les fichiers");               actions+=(list)
        labels+=("Diagnostic (rien ne s'écrit ?)");    actions+=(diag)
        labels+=("Retour");                            actions+=(back)

        echo -e "  ${YELLOW}↑↓ ou 1-6 · Entrée : valider · q : retour${NC}"; echo
        menu_select "${labels[@]}"
        (( MENU_CHOICE < 0 )) && return 0
        echo

        case "${actions[$MENU_CHOICE]}" in
            win)  ( SUBCOMMAND=fenetre; cmd_journal ) || true ;;
            here) journal_follow ;;
            tail) f="$(journal_file_latest)"
                  if [[ -n "$f" ]]; then tail -n 80 "$f" || true; else journal_diagnostic; fi ;;
            list) ( SUBCOMMAND=liste; cmd_journal ) || true ;;
            diag) journal_diagnostic ;;
            back) return 0 ;;
        esac
        menu_pause
    done
}

menu_prod() {
    local -a labels actions
    local u fpm sn sf
    while true; do
        printf '\033[2J\033[H'
        title "Production — Nginx + PHP-FPM"
        menu_state; echo

        labels=(); actions=()
        if prod_installed; then
            fpm="$(menu_fpm_svc)"
            sn="$(systemctl is-active nginx 2>/dev/null)" || sn="inactif"
            sf="$(systemctl is-active "$fpm" 2>/dev/null)" || sf="inactif"
            u="$(prod_url "$(prod_dir)")"
            # Cycle de vie complet, proposé selon l'état réel des services.
            if [[ "$sn" == "active" && "$sf" == "active" ]]; then
                labels+=("Redémarrer (URL inchangée)");            actions+=(restart)
                labels+=("Arrêter les services");                  actions+=(stop)
            elif [[ "$sn" == "active" || "$sf" == "active" ]]; then
                labels+=("Démarrer le service manquant");          actions+=(start)
                labels+=("Redémarrer les deux");                   actions+=(restart)
                labels+=("Arrêter les services");                  actions+=(stop)
            else
                labels+=("Démarrer les services");                 actions+=(start)
            fi
            labels+=("Changer l'URL / le domaine…");               actions+=(newurl)
            labels+=("État et journaux");                          actions+=(status)
            [[ -n "$u" ]] && { labels+=("Ouvrir dans le navigateur"); actions+=(open); }
            labels+=("Réinstaller / reconfigurer");                actions+=(install)
        else
            labels+=("Installer la production (Nginx + PHP-FPM + HTTPS)"); actions+=(install)
        fi
        labels+=("Retour");                                        actions+=(back)

        echo -e "  ${YELLOW}↑↓ ou 1-9 · Entrée : valider · q : retour${NC}"; echo
        menu_select "${labels[@]}"
        (( MENU_CHOICE < 0 )) && return 0
        echo
        case "${actions[$MENU_CHOICE]}" in
            start)   ( cmd_prod_start ) || true ;;
            stop)    ( cmd_prod_stop ) || true ;;
            restart) ( cmd_prod_restart ) || true ;;
            newurl)  menu_prod_newurl || true ;;
            status)  menu_prod_status || true ;;
            open)    menu_open_url "$(prod_url "$(prod_dir)")" ;;
            install) ( cmd_prod ) || true ;;
            back)    return 0 ;;
        esac
        menu_pause
    done
}

menu_maint() {
    local -a labels actions
    local isProd=false
    while true; do
        printf '\033[2J\033[H'
        title "Maintenance"
        isProd=false
        if prod_installed || [[ -f config.json ]] && grep -qE '"env"[[:space:]]*:[[:space:]]*"prod"' config.json 2>/dev/null; then
            isProd=true
        fi
        menu_state; echo
        if [[ "$isProd" == "true" ]]; then
            # install/setup préparent une base locale et régénèrent config.json en
            # mode dev : sur un serveur de production, ce n'est pas ce qu'on veut.
            echo -e "  ${YELLOW}⚠  Machine de PRODUCTION détectée.${NC}"
            echo -e "     Les entrées ${BOLD}(dév)${NC} ci-dessous génèrent une config de développement."
            echo -e "     Pour la prod : menu ${BOLD}Production${NC} → redémarrer / réinstaller."
            echo
        fi

        labels=(); actions=()
        labels+=("Diagnostic de l'environnement");                    actions+=(doctor)
        # Les épreuves tournaient déjà avant chaque « prod », mais rien ne les
        # proposait au menu : pour les lancer en dehors d'un déploiement, il
        # fallait connaître la commande. Un contrôle qu'on ne trouve pas est un
        # contrôle qu'on n'utilise pas.
        labels+=("Vérifier les protections du moteur");                actions+=(epreuves)
        if [[ "$isProd" == "true" ]]; then
            labels+=("Réparer la base et .env  ${BOLD}(dév)${NC}");   actions+=(setup)
            labels+=("Installer les dépendances système  ${BOLD}(dév)${NC}"); actions+=(install)
        else
            labels+=("Préparer / réparer la base et la config");      actions+=(setup)
            labels+=("Installer les dépendances système (PHP, PostgreSQL…)"); actions+=(install)
        fi
        case "$(sp_state)" in
            true)  labels+=("SharePoint : ${GREEN}activé${NC} — désactiver");  actions+=(sp_off) ;;
            false) labels+=("SharePoint : désactivé — activer");              actions+=(sp_on) ;;
            *)     labels+=("SharePoint : actif par défaut (avec Microsoft) — choisir"); actions+=(sp_ask) ;;
        esac
        demo_package >/dev/null && { labels+=("Installer la démonstration « Ville de Larkaville »"); actions+=(demo); }
        if [[ -f "$(larka_racine_config)/config.json" ]] && command -v php >/dev/null 2>&1; then
            local _rcc=0
            php "$(larka_racine_config)/api/outils/postgresql.php" collation --court >/dev/null 2>&1 || _rcc=$?
            if [[ $_rcc -eq 3 ]]; then
                labels+=("${YELLOW}PostgreSQL : reconstruire les index (mise à jour du système)${NC}"); actions+=(collation)
            fi
        fi
        labels+=("⚠ Réinitialiser les bases (DESTRUCTIF)");           actions+=(reset)
        labels+=("Retour");                                           actions+=(back)

        echo -e "  ${YELLOW}↑↓ ou 1-9 · Entrée : valider · q : retour${NC}"; echo
        menu_select "${labels[@]}"
        (( MENU_CHOICE < 0 )) && return 0
        echo
        case "${actions[$MENU_CHOICE]}" in
            doctor)  ( cmd_doctor ) || true ;;
            epreuves) ( title "Vérification des protections"
                        if cmd_epreuves; then ok "Toutes les protections répondent."
                        else warn "Une protection ne répond plus — détail :"
                             echo "    bash outils/epreuves/toutes.sh"; fi ) || true ;;
            setup)   ( COMMAND=setup;   cmd_setup ) || true ;;
            install) ( COMMAND=install; cmd_install ) || true ;;
            reset)   ( COMMAND=reset;   cmd_reset ) || true ;;
            demo)    ( COMMAND=demo;    cmd_demo ) || true ;;
            collation) ( sys_pg_collation_reparer "$(larka_racine_config)" ) || true ;;
            sp_on)   sp_write true  || true ;;
            sp_off)  sp_write false || true ;;
            sp_ask)  ask_sharepoint --demander || true ;;
            back)    return 0 ;;
        esac
        menu_pause
    done
}

cmd_menu() {
    local -a labels actions
    while true; do
        printf '\033[2J\033[H'
        echo -e "${CYAN}═══════════════════════════════════════════════════════════${NC}"
        echo -e "${CYAN}  Larka — Console${NC}"
        echo -e "${CYAN}═══════════════════════════════════════════════════════════${NC}"
        echo
        menu_state
        echo

        labels=(); actions=()
        labels+=("Développement  — démarrer, arrêter, journaux");  actions+=(dev)
        labels+=("Production     — démarrer, arrêter, URL");       actions+=(prod)
        labels+=("Maintenance    — diagnostic, base, dépendances"); actions+=(maint)
        labels+=("Journal        — qui, quand, quelle page, quelles modifs"); actions+=(journal)
        labels+=("Aide");                                          actions+=(help)
        labels+=("Quitter");                                       actions+=(quit)

        echo -e "  ${BOLD}Que voulez-vous faire ?${NC}   ${YELLOW}↑↓ ou 1-5 · Entrée : valider · q : quitter${NC}"
        echo
        menu_select "${labels[@]}"
        if (( MENU_CHOICE < 0 )); then echo; ok "À bientôt."; return 0; fi

        case "${actions[$MENU_CHOICE]}" in
            dev)   menu_dev ;;
            prod)  menu_prod ;;
            maint) menu_maint ;;
            journal) menu_journal ;;
            help)  echo; cmd_help; menu_pause ;;
            quit)  echo; ok "À bientôt."; return 0 ;;
        esac
    done
}

# ═══════════════════════════════════════════════════════════════════════════════
# DISPATCH
# ═══════════════════════════════════════════════════════════════════════════════

# ── Acceptation de la licence (hors commandes de lecture/diagnostic) ──────────
# prod-restart est exempté au même titre que stop/status : il ne fait que
# piloter les services d'une instance DÉJÀ installée (donc déjà acceptée). Un
# redémarrage ne doit jamais rester bloqué sur une question interactive — ni à
# 3 h du matin, ni depuis une tâche cron.
case "$COMMAND" in
    help|-h|--help|status|logs|journal|stop|doctor|autostart|prod-restart|prod-start|prod-stop|bump-assets) : ;;
    *)                                                   require_license_acceptance ;;
esac

case "$COMMAND" in
    menu|-i|--interactive) cmd_menu ;;
    install)            cmd_install ;;
    setup)              cmd_setup ;;
    start)              cmd_start ;;
    stop)               cmd_stop ;;
    # « restart » redémarrait la production dès qu'elle était installée. Comme
    # « start » et « stop », il porte maintenant sur le serveur de
    # développement : « prod-restart » existe pour l'autre.
    restart)            cmd_stop; DAEMON=true; cmd_start ;;
    status)             cmd_status ;;
    logs)               cmd_logs ;;
    journal)            SUBCOMMAND="${SUBCOMMAND:-suivre}"; cmd_journal ;;
    reset)              cmd_reset ;;
    demo|demonstration) cmd_demo ;;
    maj|mise-a-jour|update) cmd_maj ;;
    doctor)             cmd_doctor ;;
    epreuves|test)      title "Vérification des protections"; cmd_epreuves && ok "Toutes les protections répondent." || die 60 "Une protection ne répond plus. → bash outils/epreuves/toutes.sh" ;;
    autostart)          cmd_autostart ;;
    prod)               cmd_prod ${EXTRA_ARGS[@]+"${EXTRA_ARGS[@]}"} ;;
    bump-assets)        bump_assets ;;
    sharepoint)         cmd_sharepoint ;;
    prod-start)         cmd_prod_start ;;
    prod-stop)          cmd_prod_stop ;;
    prod-restart)       cmd_prod_restart ;;
    help|-h|--help)     cmd_help ;;
    *)                  die 2 "Commande inconnue : ${COMMAND}\n   → ./start.sh help" ;;
esac

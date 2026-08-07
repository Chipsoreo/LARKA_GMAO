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
LOG_FILE="data/logs/server.log"
LICENSE_MARKER="data/.license_accepted"
AUTOSTART_UNIT="larka.service"   # unité systemd posée par « autostart on »

# Extensions PHP requises (nom du module tel que listé par `php -m`)
REQUIRED_EXT=(pgsql pdo_pgsql mbstring curl gd xml)

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
    else  # repli sans PHP : extraction naïve mais suffisante pour host/port
        grep -oP '"'"$1"'"\s*:\s*"?\K[^",}]+' config.json 2>/dev/null | head -1
    fi
}

load_saved_settings() {
    local v
    if [[ "$HOST_EXPLICIT" != "true" ]]; then
        v="$(config_read_server host)"; [[ -n "$v" ]] && HOST="$v"
    fi
    if [[ "$PORT_EXPLICIT" != "true" ]]; then
        v="$(config_read_server port)"; [[ "$v" =~ ^[0-9]+$ ]] && PORT="$v"
    fi
}

save_server_settings() {
    # Mémorise host/port dans config.json (créera la clé « serveur » si besoin).
    # Appelé uniquement quand une option explicite a été fournie : la config
    # n'est jamais réécrite « en douce ».
    [[ "$HOST_EXPLICIT" == "true" || "$PORT_EXPLICIT" == "true" ]] || return 0
    [[ -f config.json ]] || return 0
    command -v php >/dev/null 2>&1 || return 0
    if php -r '
$f="config.json"; $c=json_decode(@file_get_contents($f),true);
if(!is_array($c)) exit(1);
if(!isset($c["serveur"])||!is_array($c["serveur"])) $c["serveur"]=[];
$c["serveur"]["host"]=$argv[1]; $c["serveur"]["port"]=(int)$argv[2];
exit(file_put_contents($f,json_encode($c,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE))===false?1:0);
' "$HOST" "$PORT" 2>/dev/null; then
        ok "Adresse mémorisée : ${HOST}:${PORT} (config.json — nouveau défaut)"
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
    local pm; pm="$(detect_pkg_manager)"
    info "Gestionnaire de paquets détecté : ${BOLD}${pm}${NC}"

    case "$pm" in
        apt)
            local pkgs=(postgresql postgresql-contrib
                        php-cli php-pgsql php-mbstring php-xml php-curl
                        php-gd php-zip php-intl php-bcmath)
            info "Installation : ${pkgs[*]}"
            export DEBIAN_FRONTEND=noninteractive
            as_root apt-get update -qq || die 61 "Échec de « apt-get update » (dépôts inaccessibles ?)."
            as_root apt-get install -y -qq "${pkgs[@]}" || die 61 "Échec de l'installation des paquets (apt)."
            ;;
        dnf)
            local pkgs=(postgresql postgresql-server
                        php-cli php-pgsql php-mbstring php-xml php-curl
                        php-gd php-zip php-intl php-bcmath)
            info "Installation : ${pkgs[*]}"
            as_root dnf install -y "${pkgs[@]}" || die 61 "Échec de l'installation des paquets (dnf)."
            # La base doit être initialisée explicitement sur Fedora/RHEL
            if [[ ! -d /var/lib/pgsql/data/base ]]; then
                as_root postgresql-setup --initdb 2>/dev/null || true
            fi
            ;;
        pacman)
            local pkgs=(postgresql php php-pgsql php-gd)
            warn "Support pacman expérimental — vérifiez les extensions PHP (php.ini)."
            as_root pacman -Sy --noconfirm "${pkgs[@]}" || die 61 "Échec de l'installation des paquets (pacman)."
            ;;
        zypper)
            local pkgs=(postgresql-server php-cli php-pgsql php-mbstring
                        php-curl php-gd php-zip php-intl php-bcmath)
            as_root zypper --non-interactive install "${pkgs[@]}" || die 61 "Échec de l'installation des paquets (zypper)."
            ;;
        brew)
            warn "macOS : installation via Homebrew (PHP inclut déjà la plupart des extensions)."
            brew install php postgresql@16 || true
            ;;
        *)
            die 60 "Gestionnaire de paquets non reconnu.\n   Installez manuellement : PostgreSQL + PHP 8.x avec les extensions\n   ${REQUIRED_EXT[*]} zip intl bcmath, puis relancez : ./start.sh setup"
            ;;
    esac
    ok "Paquets installés."
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
SELECT 'CREATE DATABASE ${DB_NAME} OWNER ${DB_USER} ENCODING UTF8'
 WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = '${DB_NAME}')\gexec
SELECT 'CREATE DATABASE ${DB_ADMIN} OWNER ${DB_USER} ENCODING UTF8'
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

test_db_connection() {
    php -r "
        try { new PDO('pgsql:host=127.0.0.1;port=5432;dbname=${DB_NAME}', '${DB_USER}', '${DB_PASS}'); echo 'OK'; }
        catch (Exception \$e) { echo 'ERREUR: ' . \$e->getMessage(); }
    " 2>&1
}

# Le rôle peut-il créer des bases ? (requis par le provisionnement multi-tenant)
test_role_createdb() {
    php -r "
        try {
            \$pdo = new PDO('pgsql:host=127.0.0.1;port=5432;dbname=${DB_NAME}', '${DB_USER}', '${DB_PASS}');
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

env_set() {
    local k="$1" v="$2" tmp found=0 line
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
    mv "$tmp" .env
    chmod 600 .env 2>/dev/null || true
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

generate_config() {
    if [[ -f config.json ]] && ! grep -q "CHANGER_CE_MOT_DE_PASSE\|GENERER_UNE_CLE" config.json 2>/dev/null; then
        ok "config.json existant conservé"
        return
    fi
    # ⚠️ FIX : ne jamais détruire un config.json sans filet. L'ancien code faisait
    # « rm -f config.json » : tout paramétrage OAuth/SMTP/présence était perdu
    # sans retour possible.
    if [[ -f config.json ]]; then
        local bak; bak="config.json.bak-$(date +%Y%m%d-%H%M%S)"
        cp -p config.json "$bak" 2>/dev/null || true
        warn "config.json contenait des placeholders → régénéré (sauvegarde : ${bak})"
    fi
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
    "assistant": { "actif": false, "fournisseur": "anthropic", "model": "", "api_url": "" }
}
EOCFG
    chmod 640 config.json 2>/dev/null || true
    ok "config.json généré"
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
}

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
    echo; ok "Préparation terminée. Démarrez avec : ${BOLD}./start.sh start${NC}"
}

# ── Garde-fou : commandes de DÉVELOPPEMENT sur une machine de PRODUCTION ──────
# cmd_install et cmd_setup génèrent une configuration de DÉV (env: dev,
# allowed_origin: "*", base locale) et peuvent réécrire config.json. Les lancer
# sur un serveur de production casse le site : la réinstallation prod, elle,
# passe par deploy/install.sh qui conserve la configuration existante.
prod_guard_dev_command() {
    local isProd=false
    prod_installed && isProd=true
    [[ "$SCRIPT_DIR" == /var/www/* ]] && isProd=true
    # Un config.json déjà en "env": "prod" est le signal le plus fiable.
    if [[ -f config.json ]] && grep -qE '"env"[[:space:]]*:[[:space:]]*"prod"' config.json 2>/dev/null; then
        isProd=true
    fi
    [[ "$isProd" == "true" ]] || return 0

    echo
    warn "Cette machine porte une installation de ${BOLD}PRODUCTION${NC}${YELLOW} (${SCRIPT_DIR})."
    warn "« ${COMMAND} » est une commande de DÉVELOPPEMENT : elle prépare une base"
    warn "locale et peut régénérer config.json en mode dev (env: dev, allowed_origin: \"*\")."
    echo
    info "Pour la production, utilisez plutôt :"
    info "  ./start.sh prod-restart   → redémarrer les services (ne touche à rien d'autre)"
    info "  ./start.sh prod           → réinstaller/reconfigurer (conserve config.json)"
    echo
    confirm "  Lancer quand même « ${COMMAND} » sur cette machine ?" || die 3 "Annulé — rien n'a été modifié."
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
    # ⚠️ Production installée (Nginx + pool PHP-FPM « gmao ») : c'est ELLE qui
    # sert l'application. Lancer ici un « php -S » créerait un second serveur
    # concurrent, sur un autre port, avec une autre configuration — d'où
    # l'impression de devoir tout reconfigurer et retaper l'adresse.
    if prod_installed 2>/dev/null && [[ "${FORCE_DEV:-false}" != "true" ]]; then
        info "Installation de production détectée (Nginx + PHP-FPM)."
        info "Démarrage des services plutôt que du serveur de développement."
        info "Pour forcer le serveur intégré : ${BOLD}FORCE_DEV=true ./start.sh start${NC}"
        echo
        cmd_prod_start
        return $?
    fi

    require_setup
    ensure_data_dirs
    save_server_settings      # --host/--port explicites → mémorisés (config.json)
    ensure_ready_to_serve     # PostgreSQL relancé automatiquement si besoin

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
        nohup php -S "${HOST}:${PORT}" -t . router.php >>"$LOG_FILE" 2>&1 &
        local pid=$!
        echo "$pid" > "$PID_FILE"
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
        echo -e "    login : ${YELLOW}superadmin${NC}   mot de passe : ${YELLOW}SuperAdmin2025!${NC} (à changer)"
        echo -e "  ${BLUE}Ctrl+C${NC} pour arrêter"
        [[ "$HOST" != "0.0.0.0" ]] && echo -e "  ${YELLOW}(accès LAN : --host 0.0.0.0)${NC}"
        echo
        exec php -S "${HOST}:${PORT}" -t . router.php
    fi
}

cmd_stop() {
    if prod_installed 2>/dev/null && [[ "${FORCE_DEV:-false}" != "true" ]] && ! is_running; then
        info "Installation de production détectée — arrêt des services."
        cmd_prod_stop
        return $?
    fi
    if autostart_active; then
        info "Arrêt du service systemd ${AUTOSTART_UNIT}…"
        as_root systemctl stop "$AUTOSTART_UNIT" && ok "Larka arrêté (service systemd)." \
            || warn "Échec de l'arrêt du service."
        autostart_enabled && info "Il redémarrera au prochain boot (désactiver : ./start.sh autostart off)."
        return 0
    fi
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

cmd_doctor() {
    title "Larka — Diagnostic$( [[ "$DOCTOR_FIX" == "true" ]] && echo ' (avec auto-réparation)' )"
    if command -v php >/dev/null 2>&1; then
        if php_version_ok; then ok "PHP $(php_version) présent"
        else warn "PHP $(php_version) trop ancien (8.1+ requis)  [GMAO-E12]"; fi
        local miss; miss="$(check_php || true)"
        [[ -n "$miss" ]] && warn "Extensions manquantes :${miss}  [GMAO-E11]" || ok "Extensions PHP OK (${REQUIRED_EXT[*]})"
    else warn "PHP absent  [GMAO-E10]"; fi

    if command -v psql >/dev/null 2>&1; then ok "Client PostgreSQL présent"
        if pg_isready -q 2>/dev/null; then ok "Serveur PostgreSQL actif"
        elif [[ "$DOCTOR_FIX" == "true" ]]; then
            warn "Serveur PostgreSQL injoignable → tentative de démarrage (--fix)…"
            ensure_postgres_running && ok "PostgreSQL démarré." || warn "Échec  [GMAO-E21]"
        else warn "Serveur PostgreSQL injoignable  [GMAO-E21]  (réparer : ./start.sh doctor --fix)"; fi
    else warn "PostgreSQL absent  [GMAO-E20]"; fi

    [[ -f .env ]]        && ok ".env présent"        || warn ".env absent  [GMAO-E30] (./start.sh install)"
    [[ -f config.json ]] && ok "config.json présent" || warn "config.json absent  [GMAO-E30] (./start.sh install)"

    if [[ -f .env && -f config.json ]] && command -v php >/dev/null 2>&1; then
        local out; out="$(test_db_connection)"
        if [[ "$out" == "OK" ]]; then
            ok "Connexion à la base '${DB_NAME}' OK"
            local cdb; cdb="$(test_role_createdb)"
            [[ "$cdb" == "YES" ]] \
                && ok "Rôle '${DB_USER}' peut créer des bases (provisionnement multi-tenant OK)" \
                || warn "Rôle '${DB_USER}' sans droit CREATEDB — provisionnement de tenant impossible  [GMAO-E24]\n   Corrigez : sudo -u postgres psql -c \"ALTER ROLE ${DB_USER} CREATEDB;\"  (ou ./start.sh setup)"
        else
            warn "Connexion base KO  [GMAO-E23] : ${out}"
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
ExecStart=${phpbin} -S ${HOST}:${PORT} -t ${SCRIPT_DIR} ${SCRIPT_DIR}/router.php
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
            as_root rm -f "$(autostart_unit_path)"
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

cmd_prod() {
    [[ -f deploy/install.sh ]] || die 30 "deploy/install.sh introuvable (lancez la commande depuis la racine du projet)."
    [[ $EUID -ne 0 && ! "$(command -v sudo)" ]] && die 50 "Le mode production nécessite les droits root (su - puis bash start.sh prod, ou installez sudo)."
    title "Larka — Installation production (Nginx + PHP-FPM + HTTPS)"
    as_root bash deploy/install.sh "$@"
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
  reset        Recrée les bases depuis zéro (DESTRUCTIF)
  doctor       Diagnostic complet   ·   doctor --fix : auto-réparation
  prod ...     Installation production (délègue à deploy/install.sh)

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
    local url="$1" code code2
    command -v curl &>/dev/null || { warn "curl absent — contrôle de santé ignoré."; return 0; }
    code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "$url" 2>/dev/null)" || true
    if [[ -z "$code" || "$code" == "000" ]]; then
        # Second essai sans vérifier le certificat : permet de distinguer
        # « site injoignable » de « certificat non valide ».
        code2="$(curl -sk -o /dev/null -w '%{http_code}' --max-time 10 "$url" 2>/dev/null)" || true
        if [[ -n "$code2" && "$code2" != "000" ]]; then
            warn "Le site répond (HTTP ${code2}) mais son certificat TLS n'est pas validé"
            warn "(auto-signé, expiré, ou émis pour un autre domaine) → certbot renew ?"
            return 0
        fi
        warn "Aucune réponse de ${url} — DNS, pare-feu, ou Nginx n'écoute pas ce domaine ?"
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
        if [[ "$isProd" == "true" ]]; then
            labels+=("Réparer la base et .env  ${BOLD}(dév)${NC}");   actions+=(setup)
            labels+=("Installer les dépendances système  ${BOLD}(dév)${NC}"); actions+=(install)
        else
            labels+=("Préparer / réparer la base et la config");      actions+=(setup)
            labels+=("Installer les dépendances système (PHP, PostgreSQL…)"); actions+=(install)
        fi
        labels+=("⚠ Réinitialiser les bases (DESTRUCTIF)");           actions+=(reset)
        labels+=("Retour");                                           actions+=(back)

        echo -e "  ${YELLOW}↑↓ ou 1-9 · Entrée : valider · q : retour${NC}"; echo
        menu_select "${labels[@]}"
        (( MENU_CHOICE < 0 )) && return 0
        echo
        case "${actions[$MENU_CHOICE]}" in
            doctor)  ( cmd_doctor ) || true ;;
            setup)   ( COMMAND=setup;   cmd_setup ) || true ;;
            install) ( COMMAND=install; cmd_install ) || true ;;
            reset)   ( COMMAND=reset;   cmd_reset ) || true ;;
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
    restart)            if prod_installed 2>/dev/null && [[ "${FORCE_DEV:-false}" != "true" ]]; then
                            cmd_prod_restart
                        else
                            cmd_stop; DAEMON=true; cmd_start
                        fi ;;
    status)             cmd_status ;;
    logs)               cmd_logs ;;
    journal)            SUBCOMMAND="${SUBCOMMAND:-suivre}"; cmd_journal ;;
    reset)              cmd_reset ;;
    doctor)             cmd_doctor ;;
    autostart)          cmd_autostart ;;
    prod)               cmd_prod ${EXTRA_ARGS[@]+"${EXTRA_ARGS[@]}"} ;;
    bump-assets)        bump_assets ;;
    prod-start)         cmd_prod_start ;;
    prod-stop)          cmd_prod_stop ;;
    prod-restart)       cmd_prod_restart ;;
    help|-h|--help)     cmd_help ;;
    *)                  die 2 "Commande inconnue : ${COMMAND}\n   → ./start.sh help" ;;
esac

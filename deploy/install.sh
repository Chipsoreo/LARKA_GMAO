#!/bin/bash
# SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
# SPDX-License-Identifier: LicenseRef-Larka-Proprietary
#
# This file is part of Larka, proprietary software by Mickaël Larcin.
# All rights reserved. Use is subject to the license terms; copying,
# distribution, modification or reverse-engineering without the author's
# prior written permission is prohibited. See the LICENSE file for details.
# ═══════════════════════════════════════════════════════════════════════════════
# Larka — Script d'installation production
# ═══════════════════════════════════════════════════════════════════════════════
#
# Usage : sudo bash install.sh [--domain gmao.example.com] [--db-password-file FICHIER]
#         (ou la variable LARKA_DB_PASSWORD ; sans rien, un mot de passe est généré
#          et rangé dans .env — il n'est jamais affiché)
#                             [--cert fullchain.pem --key privkey.pem | --no-certbot | --behind-proxy]
#
# Certificat TLS — Certbot est une OPTION (utilisée par défaut) :
#   (défaut)             Let's Encrypt via Certbot, renouvellement automatique
#   --cert F --key K     certificat existant (PKI interne, certificat acheté) : pas de Certbot
#   --no-certbot         aucun Certbot : placez vous-même le certificat, ou adaptez
#                        ssl_certificate* dans /etc/nginx/sites-available/gmao
#   --behind-proxy       TLS terminé en amont (reverse-proxy, tunnel) : Nginx en HTTP local
#
# SharePoint — option, demandée à l'installation si elle n'est pas fixée :
#   --sharepoint on|off  liens vers des fichiers SharePoint/OneDrive (exige la connexion
#                        Microsoft). off : documents stockés dans Larka uniquement.
#                        Modifiable ensuite : ./start.sh sharepoint on|off
#
# Ce script installe :
#   - PostgreSQL 16
#   - PHP 8.3 + PHP-FPM + extensions requises
#   - Nginx avec HTTPS (certificat Let's Encrypt en option)
#   - Larka dans /var/www/gmao
#
# Prérequis :
#   - Ubuntu 22.04 / 24.04 ou Debian 12+
#   - Accès root (sudo)
#   - Un nom de domaine pointant vers le serveur (requis seulement pour Let's Encrypt)
# ═══════════════════════════════════════════════════════════════════════════════

set -euo pipefail

# ── Couleurs ────────────────────────────────────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; BLUE='\033[0;34m'; NC='\033[0m'
info()  { echo -e "${BLUE}[INFO]${NC}  $1"; }
ok()    { echo -e "${GREEN}[OK]${NC}    $1"; }
# Copie de la version remplacée d'un fichier système (vhost Nginx, pool PHP-FPM,
# cron) : relancer ce script pour une mise à jour régénère ces fichiers ; une
# adaptation faite à la main reste ainsi récupérable (<fichier>.bak-larka).
# Le suffixe n'est lu ni par Nginx (*.conf, sites-enabled), ni par PHP-FPM
# (pool.d/*.conf), ni par cron (pas de point dans les noms de cron.d).
sauver_conf() { [[ -f "$1" ]] && cp -p "$1" "$1.bak-larka" 2>/dev/null; return 0; }
warn()  { echo -e "${YELLOW}[WARN]${NC}  $1"; }
err()   { echo -e "${RED}[ERR]${NC}   $1"; exit 1; }

# ── Vérifications ───────────────────────────────────────────────────────────
[[ $EUID -ne 0 ]] && err "Ce script doit être exécuté en root (sudo)."

# Paquets sans mise à jour silencieuse ; PostgreSQL après une mise à jour du
# système (version de collation) — code partagé avec start.sh.
# shellcheck source=systeme.sh
source "$(cd "$(dirname "$0")" && pwd)/systeme.sh"
SYS_OUI="${SYS_OUI:-false}"

# ── Acceptation de la licence d'utilisation (miroir de start.sh) ─────────────
# Couvre le lancement direct « sudo bash deploy/install.sh ». Via « start.sh prod »
# le marqueur est déjà créé en amont : aucune double demande.
LICENSE_MARKER="data/.license_accepted"
if [[ ! -f "$LICENSE_MARKER" ]]; then
    mkdir -p "$(dirname "$LICENSE_MARKER")" 2>/dev/null || true
    _lic_env="${LARKA_LICENSE_ACCEPTED:-${GMAO_LICENSE_ACCEPTED:-}}"
    _lic_env="$(printf '%s' "$_lic_env" | tr 'A-Z' 'a-z')"
    if [[ "$_lic_env" =~ ^(1|true|oui|yes|y|o)$ ]]; then
        printf 'accepted=env mode=prod %s by=%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "${SUDO_USER:-${USER:-?}}" > "$LICENSE_MARKER" 2>/dev/null || true
        ok "Licence acceptée (variable d'environnement)."
    elif [[ -t 0 ]]; then
        echo ""
        info "LICENCE D'UTILISATION Larka — licence propriétaire (© 2025-2026 Mickaël Larcin)."
        info "Conditions complètes : fichier LICENSE. En organisation, l'acceptation est donnée"
        info "pour le compte de celle-ci, qui informe, forme et supervise ses utilisateurs."
        printf "  Tapez « j'accepte » pour continuer l'installation : "
        read -r _lic_rep || true
        _lic_rep="$(printf '%s' "$_lic_rep" | sed "s/$(printf '\342\200\231')/'/g" | tr 'A-Z' 'a-z' | sed 's/^ *//; s/ *$//')"
        case "$_lic_rep" in
            "j'accepte"|jaccepte|"j accepte"|oui|yes|o)
                printf 'accepted=interactive mode=prod %s by=%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "${SUDO_USER:-${USER:-?}}" > "$LICENSE_MARKER" 2>/dev/null || true
                ok "Licence acceptée — merci." ;;
            *) err "Licence non acceptée — installation annulée." ;;
        esac
    else
        err "Licence non acceptée. En mode non interactif : export LARKA_LICENSE_ACCEPTED=1 (l'organisation accepte pour son compte, voir LICENSE), puis relancez."
    fi
fi

# ── Paramètres ──────────────────────────────────────────────────────────────
DOMAIN=""
DB_PASSWORD=""
INSTALL_DIR="/var/www/gmao"
DB_USER="gmao"
DB_NAME="gmao"
DB_ADMIN_NAME="gmao_admin"
BEHIND_PROXY=0          # 1 = derrière reverse-proxy / tunnel : HTTP local, TLS géré en amont, pas de Let's Encrypt
HTTP_PORT=80            # port HTTP local servi par Nginx (ce que le tunnel/proxy contacte)
USE_CERTBOT=1           # 0 = pas de Let's Encrypt (--cert/--key, --no-certbot ou --behind-proxy)
SSL_CERT=""             # --cert : chaîne complète du certificat existant (PEM)
SSL_KEY=""              # --key  : clé privée correspondante (PEM)
SP_CHOICE=""            # --sharepoint on|off (vide : demandé, ou conservé si déjà réglé)
PHP_VERSION=$(php -v 2>/dev/null | head -1 | grep -oP '\d+\.\d+' || echo "8.3")

while [[ $# -gt 0 ]]; do
    case "$1" in
        --domain)      DOMAIN="$2"; shift 2 ;;
        --db-password) DB_PASSWORD="$2"; _DB_PASSWORD_ARG=1; shift 2 ;;
        --db-password-file)
            [[ -r "$2" ]] || { echo "Fichier illisible : $2" >&2; exit 1; }
            DB_PASSWORD="$(head -1 "$2" | tr -d '\r\n')"; shift 2 ;;
        --install-dir) INSTALL_DIR="$2"; shift 2 ;;
        --behind-proxy|--tunnel|--no-tls) BEHIND_PROXY=1; shift ;;
        --http-port)   HTTP_PORT="$2"; shift 2 ;;
        --cert)        SSL_CERT="$2"; USE_CERTBOT=0; shift 2 ;;
        --key)         SSL_KEY="$2";  USE_CERTBOT=0; shift 2 ;;
        --no-certbot)  USE_CERTBOT=0; shift ;;
        --sharepoint)  SP_CHOICE="$2"; shift 2 ;;
        *) shift ;;
    esac
done

if [[ -z "$DOMAIN" ]]; then
    read -p "Nom de domaine (ex: gmao.monentreprise.fr) : " DOMAIN
    [[ -z "$DOMAIN" ]] && err "Le nom de domaine est requis."
fi

# Nettoyer le domaine (supprimer http:// ou https://)
DOMAIN=$(echo "$DOMAIN" | sed 's|^https\?://||' | sed 's|/.*||')

# Domaine de tunnel (Cloudflare quick tunnel, etc.) : Let's Encrypt est impossible
# (domaine non contrôlé) → bascule automatique en mode reverse-proxy/tunnel.
if [[ "$BEHIND_PROXY" != "1" && "$DOMAIN" == *.trycloudflare.com ]]; then
    BEHIND_PROXY=1
    warn "Domaine *.trycloudflare.com détecté → mode reverse-proxy/tunnel activé (pas de Let's Encrypt)."
    warn "  Vérifiez que le tunnel pointe vers http://127.0.0.1:${HTTP_PORT}."
fi

# Certbot n'a pas de sens derrière un proxy (TLS en amont). Un certificat fourni
# doit être complet (chaîne + clé) et lisible dès maintenant, plutôt qu'au
# premier « nginx -t » où l'erreur serait bien moins parlante.
[[ "$BEHIND_PROXY" == "1" ]] && USE_CERTBOT=0
if [[ -n "$SSL_CERT" || -n "$SSL_KEY" ]]; then
    [[ -n "$SSL_CERT" && -n "$SSL_KEY" ]] || err "--cert et --key vont ensemble (chaîne du certificat + clé privée)."
    [[ -r "$SSL_CERT" ]] || err "Certificat illisible : $SSL_CERT"
    [[ -r "$SSL_KEY"  ]] || err "Clé privée illisible : $SSL_KEY"
    SSL_CERT=$(readlink -f "$SSL_CERT"); SSL_KEY=$(readlink -f "$SSL_KEY")
fi
SSL_CERT_NGINX="${SSL_CERT:-/etc/letsencrypt/live/${DOMAIN}/fullchain.pem}"
SSL_KEY_NGINX="${SSL_KEY:-/etc/letsencrypt/live/${DOMAIN}/privkey.pem}"

# Mot de passe en argument : il reste dans l'historique du shell et se lit dans
# la liste des processus pendant l'installation.
[[ "${_DB_PASSWORD_ARG:-0}" == "1" ]] && warn "--db-password laisse le mot de passe dans l'historique du shell : préférez --db-password-file ou la variable LARKA_DB_PASSWORD."
[[ -z "$DB_PASSWORD" && -n "${LARKA_DB_PASSWORD:-}" ]] && DB_PASSWORD="$LARKA_DB_PASSWORD"

if [[ -z "$DB_PASSWORD" ]]; then
    # Réutiliser le mot de passe d'un .env déjà présent pour rester cohérent avec
    # la configuration conservée (sinon ALTER ROLE le change et le .env gardé
    # pointe vers l'ancien mot de passe → échec de connexion PostgreSQL).
    if [[ -f "${INSTALL_DIR}/.env" ]] && grep -q '^GMAO_DB_PASSWORD=.' "${INSTALL_DIR}/.env"; then
        DB_PASSWORD=$(grep '^GMAO_DB_PASSWORD=' "${INSTALL_DIR}/.env" | head -1 | cut -d= -f2-)
        info "Mot de passe PostgreSQL repris depuis ${INSTALL_DIR}/.env (cohérence avec l'existant)."
    else
        DB_PASSWORD=$(openssl rand -base64 24 | tr -d '/+=' | head -c 24)
        info "Mot de passe PostgreSQL généré — rangé dans ${INSTALL_DIR}/.env (GMAO_DB_PASSWORD), jamais affiché."
    fi
fi

# Trouver le répertoire PHP-FPM réel
PHP_FPM_DIR=""
for d in /etc/php/${PHP_VERSION}/fpm/pool.d /etc/php/*/fpm/pool.d; do
    if [[ -d "$d" ]]; then
        PHP_FPM_DIR="$d"
        # Extraire la version réelle depuis le chemin
        PHP_VERSION=$(echo "$d" | grep -oP '\d+\.\d+')
        break
    fi
done
if [[ -z "$PHP_FPM_DIR" ]]; then
    warn "Répertoire PHP-FPM non trouvé. PHP-FPM sera configuré manuellement."
fi

SECRET_KEY=$(openssl rand -hex 32)

# ── SharePoint : choix fait une fois, à l'installation ──────────────────────
# Déjà réglé dans un config.json conservé → on ne redemande pas.
case "${SP_CHOICE,,}" in
    on|oui|o|yes|y|true|1)   SP_ACTIF=true ;;
    off|non|n|no|false|0)    SP_ACTIF=false ;;
    "")
        SP_ACTIF=""
        # ⚠️ FIX : une configuration EXISTANTE n'est jamais modifiée sans choix
        # explicite (--sharepoint on|off). Sans le choix inscrit, SharePoint y est
        # ACTIF (comportement historique, api/config.php) : relancer ce script
        # pour une mise à jour — souvent sans terminal — y écrivait « false »,
        # et SharePoint disparaissait.
        if [[ -f "${INSTALL_DIR}/config.json" ]]; then
            SP_ACTIF="conserve"
        fi
        if [[ -z "$SP_ACTIF" ]]; then
            if [[ -t 0 ]]; then
                echo ""
                echo "  Documents : faut-il pouvoir lier des fichiers SharePoint / OneDrive aux fiches ?"
                echo "    • Non (recommandé si vous n'utilisez pas Microsoft 365) : documents stockés dans Larka."
                echo "    • Oui : exige la connexion Microsoft ; les utilisateurs autoriseront l'accès à leurs fichiers."
                read -p "  Activer SharePoint ? [o/N] " -n 1 -r; echo ""
                [[ $REPLY =~ ^[OoYy]$ ]] && SP_ACTIF=true || SP_ACTIF=false
            else
                SP_ACTIF=false
                info "SharePoint désactivé par défaut (mode non interactif). Pour l'activer : --sharepoint on"
            fi
        fi ;;
    *) err "--sharepoint attend on ou off (reçu : ${SP_CHOICE})." ;;
esac

echo ""
echo "═══════════════════════════════════════════════════════════"
echo "  Larka — Installation production"
echo "═══════════════════════════════════════════════════════════"
echo "  Domaine       : $DOMAIN"
echo "  Répertoire    : $INSTALL_DIR"
echo "  Base de donnée: PostgreSQL ($DB_NAME / $DB_ADMIN_NAME)"
echo "  Utilisateur DB: $DB_USER"
if [[ "$BEHIND_PROXY" == "1" ]]; then
  echo "  Réseau        : derrière reverse-proxy/tunnel — Nginx HTTP :$HTTP_PORT (TLS en amont)"
elif [[ "$USE_CERTBOT" == "1" ]]; then
  echo "  Réseau        : Nginx + HTTPS Let's Encrypt (Certbot)"
elif [[ -n "$SSL_CERT" ]]; then
  echo "  Réseau        : Nginx + HTTPS — certificat fourni ($SSL_CERT)"
else
  echo "  Réseau        : Nginx + HTTPS — sans Certbot (certificat attendu : $SSL_CERT_NGINX)"
fi
if [[ "$SP_ACTIF" == "true" ]]; then
  echo "  SharePoint    : activé (avec la connexion Microsoft)"
elif [[ "$SP_ACTIF" == "conserve" ]]; then
  echo "  SharePoint    : réglage existant conservé (config.json)"
else
  echo "  SharePoint    : désactivé — documents stockés dans Larka"
fi
echo "═══════════════════════════════════════════════════════════"
echo ""
read -p "Continuer ? [O/n] " -n 1 -r
echo ""
[[ $REPLY =~ ^[Nn]$ ]] && exit 0

# ═══════════════════════════════════════════════════════════════════════════════
# 1. PAQUETS SYSTÈME
# ═══════════════════════════════════════════════════════════════════════════════

# Détecter ce qui est déjà installé
_HAS_PG=$(command -v psql &>/dev/null && echo 1 || echo 0)
_HAS_PHP=$(command -v php &>/dev/null && echo 1 || echo 0)
_HAS_NGINX=$(command -v nginx &>/dev/null && echo 1 || echo 0)
_HAS_CERTBOT=$(command -v certbot &>/dev/null && echo 1 || echo 0)

_need_install=""
[[ "$_HAS_PG" == "0" ]]      && _need_install="${_need_install} PostgreSQL"
[[ "$_HAS_PHP" == "0" ]]     && _need_install="${_need_install} PHP-${PHP_VERSION}"
[[ "$_HAS_NGINX" == "0" ]]   && _need_install="${_need_install} Nginx"
[[ "$_HAS_CERTBOT" == "0" && "$USE_CERTBOT" == "1" ]] && _need_install="${_need_install} Certbot"

if [[ -n "$_need_install" ]]; then
    echo ""
    warn "Paquets manquants :${_need_install}"
    read -p "Installer les paquets manquants ? [O/n] " -n 1 -r
    echo ""
    if [[ $REPLY =~ ^[Nn]$ ]]; then
        warn "Installation des paquets ignorée. Assurez-vous qu'ils sont disponibles."
    else
        # Ce qui manque, sans mettre à jour en silence ce qui est déjà installé :
        # la transaction est simulée, et toute mise à jour d'un paquet présent
        # (bibliothèque C, ICU, PostgreSQL…) est montrée et soumise à accord.
        _pkgs=()
        [[ "$_HAS_PG" == "0" ]] && _pkgs+=(postgresql postgresql-contrib)
        if [[ "$_HAS_PHP" == "0" ]]; then
            _pfx="php${PHP_VERSION}"
            if ! apt-cache show "${_pfx}-fpm" >/dev/null 2>&1; then
                warn "php${PHP_VERSION} non disponible, version par défaut de la distribution."
                _pfx="php"
            fi
            for _e in fpm pgsql mbstring xml curl gd zip intl bcmath; do _pkgs+=("${_pfx}-${_e}"); done
        fi
        [[ "$_HAS_NGINX" == "0" ]] && _pkgs+=(nginx)
        [[ "$_HAS_CERTBOT" == "0" && "$USE_CERTBOT" == "1" ]] && _pkgs+=(certbot python3-certbot-nginx)
        _rc=0
        sys_installer_paquets apt "${_pkgs[@]}" || _rc=$?
        [[ $_rc -eq 1 ]] && err "Installation annulée : rien n'a été installé ni mis à jour."
        [[ $_rc -ne 0 ]] && err "Échec de l'installation des paquets."
        if [[ "$_HAS_PHP" == "0" && "${_pfx:-}" == "php" ]]; then
            PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
        fi
        sys_pg_redemarrer_si_besoin

        ok "Paquets installés."
    fi
else
    ok "Tous les paquets sont déjà installés (PostgreSQL, PHP ${PHP_VERSION}, Nginx)."
fi

# Prérequis de CETTE version (version.json → api/Prerequis.php) : un PHP trop
# ancien ou une extension indispensable absente arrête l'installation ICI,
# avant que quoi que ce soit ne soit configuré.
if [[ -f "${SCRIPT_DIR}/api/outils/prerequis.php" ]]; then
    _pr_rc=0
    _pr_sortie="$(php "${SCRIPT_DIR}/api/outils/prerequis.php" --court --sans-base 2>&1)" || _pr_rc=$?
    [[ -n "$_pr_sortie" ]] && echo "$_pr_sortie" | sed 's/^/    /'
    [[ $_pr_rc -eq 1 ]] && err "Prérequis non satisfaits : installation arrêtée, rien n'a été configuré."
    ok "Prérequis de Larka $(php -r 'echo json_decode(file_get_contents($argv[1]), true)["version"] ?? "";' "${SCRIPT_DIR}/version.json") satisfaits."
fi

# ═══════════════════════════════════════════════════════════════════════════════
# 2. POSTGRESQL
# ═══════════════════════════════════════════════════════════════════════════════
info "Configuration de PostgreSQL..."
systemctl enable --now postgresql

# Créer l'utilisateur et les bases
sudo -u postgres psql -v ON_ERROR_STOP=1 <<EOSQL
DO \$\$
BEGIN
    IF NOT EXISTS (SELECT FROM pg_catalog.pg_roles WHERE rolname = '${DB_USER}') THEN
        -- CREATEDB requis : le multi-tenant crée une base PostgreSQL par tenant.
        CREATE ROLE ${DB_USER} WITH LOGIN CREATEDB PASSWORD '${DB_PASSWORD}';
    ELSE
        ALTER ROLE ${DB_USER} WITH LOGIN CREATEDB PASSWORD '${DB_PASSWORD}';
    END IF;
END
\$\$;

-- TEMPLATE template0 : template1 peut être refusé après une mise à jour du
-- système (« collation version mismatch ») ; template0 ne l'est jamais.
SELECT 'CREATE DATABASE ${DB_NAME} OWNER ${DB_USER} ENCODING UTF8 TEMPLATE template0'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = '${DB_NAME}')\gexec

SELECT 'CREATE DATABASE ${DB_ADMIN_NAME} OWNER ${DB_USER} ENCODING UTF8 TEMPLATE template0'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = '${DB_ADMIN_NAME}')\gexec

GRANT ALL PRIVILEGES ON DATABASE ${DB_NAME} TO ${DB_USER};
GRANT ALL PRIVILEGES ON DATABASE ${DB_ADMIN_NAME} TO ${DB_USER};
EOSQL

ok "PostgreSQL configuré (bases: ${DB_NAME}, ${DB_ADMIN_NAME})."

# ═══════════════════════════════════════════════════════════════════════════════
# 3. DÉPLOIEMENT DES FICHIERS
# ═══════════════════════════════════════════════════════════════════════════════
info "Déploiement dans ${INSTALL_DIR}..."
mkdir -p "${INSTALL_DIR}"

# ── Ce qui ne part PAS en production ─────────────────────────────────────────
#
# Une seule liste, partagée par rsync et par tar. Elles étaient écrites deux
# fois : ajouter une exclusion d'un côté et l'oublier de l'autre livrait le
# dossier sur toutes les machines sans rsync, sans que personne le remarque.
#
# Les outils de développement — outils/ — et la documentation (manuels Word,
# DAT, référence du format) ne servent à rien sur un serveur en production :
# l'aide de l'application est intégrée à l'écran Aide (api/AideFiches.php).
# Les bloquer par nginx ne suffisait pas : un fichier refusé reste un fichier
# livré. « outils/epreuves/ » décrit surtout, épreuve par épreuve, tout ce
# contre quoi Larka se défend — c'est-à-dire par où l'attaquer. Cette carte n'a
# pas à voyager avec le produit.
#
# Ils restent dans le dépôt : c'est là qu'ils servent.
EXCLUSIONS=(
    'deploy' '.git' 'data/sessions' 'data/logs/*.log' '*.pem' 'config.json'
    '.env' 'Caddyfile' 'start.bat' 'demarrer.sh'
    'outils' 'Documentations' '.github' 'tests' '*.md.bak'
    # Windows : PHP posé par Larka.cmd ; démonstration (livrée dans demo/, ou
    # rangée à côté) : données fictives, sans usage sur un serveur en service.
    'php' 'demo' '*-demo-Larkaville*.zip'
)

# Copier les fichiers (depuis le répertoire du script)
SCRIPT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
if [[ -f "${SCRIPT_DIR}/index.html" ]]; then
    if command -v rsync >/dev/null 2>&1; then
        # ⚠️ Motifs ANCRÉS à la racine (« /outils »), comme le « ./outils » de tar
        # ci-dessous. Non ancré, « outils » excluait aussi api/outils/ — donc
        # api/outils/mise-a-jour.php, l'outil de mise à jour en ligne de commande
        # (seule voie quand l'interface ne peut pas écrire, ou pour restaurer une
        # ancienne sauvegarde) — sur toute installation faite avec rsync.
        RSYNC_ARGS=()
        for e in "${EXCLUSIONS[@]}"; do
            [[ "$e" == \** ]] && RSYNC_ARGS+=(--exclude="$e") \
                              || RSYNC_ARGS+=(--exclude="/$e")
        done
        rsync -a "${RSYNC_ARGS[@]}" "${SCRIPT_DIR}/" "${INSTALL_DIR}/"
    else
        # Repli sans rsync (tar est toujours présent) : MÊMES exclusions,
        # permissions préservées. Le préfixe « ./ » est ce qu'attend tar.
        TAR_ARGS=()
        for e in "${EXCLUSIONS[@]}"; do
            [[ "$e" == \** ]] && TAR_ARGS+=(--exclude="$e") \
                              || TAR_ARGS+=(--exclude="./$e")
        done
        tar -C "${SCRIPT_DIR}" "${TAR_ARGS[@]}" -cf - . \
            | tar -C "${INSTALL_DIR}" -xpf -
    fi
else
    warn "Fichiers source non trouvés dans ${SCRIPT_DIR}. Copiez les fichiers manuellement."
fi

# Créer les répertoires data
mkdir -p "${INSTALL_DIR}/data/"{logs,sessions,backups}

# ── Générer le fichier .env (secrets) ──────────────────────────────────────
if [[ ! -f "${INSTALL_DIR}/.env" ]]; then
    info "Génération du fichier .env (secrets)..."
    cat > "${INSTALL_DIR}/.env" <<EOENV
# Larka — Secrets de production. NE PAS COMMITTER, NE PAS PARTAGER.
# Voir .env.example pour la documentation complète des variables.
GMAO_SECRET_KEY=${SECRET_KEY}
GMAO_DB_PASSWORD=${DB_PASSWORD}
GMAO_SUPERADMIN_DB_PASSWORD=${DB_PASSWORD}
# Secrets optionnels (renseigner si besoin, ou via l'interface web) :
GMAO_MICROSOFT_CLIENT_ID=
GMAO_MICROSOFT_TENANT_ID=common
GMAO_MICROSOFT_CLIENT_SECRET=
GMAO_MICROSOFT_REDIRECT_URI=
GMAO_MICROSOFT_REDIRECT_URI_MOBILE=
GMAO_GOOGLE_CLIENT_ID=
GMAO_GOOGLE_CLIENT_SECRET=
GMAO_GOOGLE_REDIRECT_URI=
GMAO_GOOGLE_REDIRECT_URI_MOBILE=
GMAO_LEGIFRANCE_CLIENT_SECRET=
GMAO_SMTP_PASSWORD=
GMAO_VAPID_PRIVATE_KEY=
GMAO_ASSISTANT_API_KEY=
EOENV
    ok ".env généré (secrets)."
else
    warn ".env existant conservé."
fi

# ── Générer config.json (sans secret) ──────────────────────────────────────
# Pas de « securite_http.csp » : la CSP par défaut du code (api/config.php,
# router.php) est la plus stricte et suit l'application. Celle qu'écrivait ce
# script ajoutait 'unsafe-eval', sans base-uri ni form-action — et devenait
# la CSP de la page sous gmao.service (router.php la reprend de config.json).
if [[ ! -f "${INSTALL_DIR}/config.json" ]]; then
    info "Génération de config.json (sans secret)..."
    cat > "${INSTALL_DIR}/config.json" <<EOCFG
{
    "serveur": {
        "host": "0.0.0.0",
        "port": 8000,
        "env": "prod"
    },
    "base_de_donnees": {
        "driver": "pgsql",
        "host": "127.0.0.1",
        "port": 5432,
        "dbname": "${DB_NAME}",
        "user": "${DB_USER}",
        "password": "",
        "sslmode": "prefer"
    },
    "superadmin_db": {
        "driver": "pgsql",
        "host": "127.0.0.1",
        "port": 5432,
        "dbname": "${DB_ADMIN_NAME}",
        "user": "${DB_USER}",
        "password": "",
        "sslmode": "prefer"
    },
    "securite": {
        "secret_key": "",
        "session_duree_heures": 24,
        "allowed_origin": "https://${DOMAIN}"
    },
    "session": {
        "cookie_secure": true,
        "cookie_httponly": true,
        "cookie_samesite": "Lax",
        "cookie_path": "/"
    },
    "cors": {
        "origins": ["https://${DOMAIN}"],
        "methods": ["GET","POST","PUT","DELETE","OPTIONS"],
        "headers": ["Content-Type","Authorization"],
        "allow_credentials": true
    },
    "securite_http": {
        "hsts": true,
        "x_frame_options": "DENY",
        "x_content_type_options": "nosniff",
        "referrer_policy": "no-referrer"
    },
    "documents": {
        "max_par_categorie": 4,
        "taille_max_mo": 25,
        "mime_autorises": ["application/pdf","image/png","image/jpeg","image/webp"],
        "dedup_sha256": true
    },
    "logs": {
        "actif": true,
        "dossier": "data/logs",
        "niveau": "warning",
        "rotation_jours": 30
    },
    "sauvegardes": {
        "actif": true,
        "intervalle_minutes": 360,
        "dossier": "data/backups",
        "garder": 30,
        "compresser": true
    },
    "microsoft_oauth": {
        "actif": false,
        "client_id": "",
        "tenant_id": "common",
        "client_secret": "",
        "redirect_uri": "https://${DOMAIN}/oauth/microsoft"
    },
    "sharepoint": { "actif": ${SP_ACTIF} },
    "google_oauth": {
        "actif": false,
        "client_id": "",
        "client_secret": "",
        "redirect_uri": "https://${DOMAIN}/oauth/google"
    },
    "acces": { "domaine_email_autorise": "" },
    "smtp": { "actif": false, "host": "", "port": 587, "secure": "tls", "username": "", "password": "", "from": "noreply@${DOMAIN}", "from_name": "Larka" },
    "push": { "actif": false, "vapid_public_key": "", "vapid_private_pem": "", "vapid_subject": "mailto:admin@${DOMAIN}" },
    "extensions": { "actif": false }
}
EOCFG
    ok "config.json généré."
else
    warn "config.json existant conservé."
    # Seul un choix SharePoint EXPLICITE (--sharepoint on|off) y est reporté :
    # clé par clé, en place, après copie dans data/backups/config/
    # (api/outils/config-json.php — rien d'autre n'est réécrit).
    if [[ "$SP_ACTIF" == "true" || "$SP_ACTIF" == "false" ]] && command -v php >/dev/null 2>&1; then
        _r=$(php "${INSTALL_DIR}/api/outils/config-json.php" ecrire "${INSTALL_DIR}/config.json" "sharepoint.actif=${SP_ACTIF}" 2>&1) \
            && [[ "$_r" == modifie* ]] && info "SharePoint : ${SP_ACTIF} (config.json)."
    fi
fi

# ── Permissions ─────────────────────────────────────────────────────────────
chown -R www-data:www-data "${INSTALL_DIR}"
chmod -R 750 "${INSTALL_DIR}"
chmod -R 770 "${INSTALL_DIR}/data"
chmod 640 "${INSTALL_DIR}/config.json"
chmod 600 "${INSTALL_DIR}/.env"

ok "Fichiers déployés."

# ═══════════════════════════════════════════════════════════════════════════════
# 4. PHP-FPM
# ═══════════════════════════════════════════════════════════════════════════════
info "Configuration PHP-FPM..."
if [[ -z "$PHP_FPM_DIR" ]]; then
    warn "PHP-FPM non trouvé — configuration ignorée. Configurez manuellement."
elif [[ -f "${INSTALL_DIR}/deploy/php-fpm-gmao.conf" ]]; then
    # Adapter la version dans le fichier
    sauver_conf "${PHP_FPM_DIR}/gmao.conf"
    sed "s/php8\.3/php${PHP_VERSION}/g" "${INSTALL_DIR}/deploy/php-fpm-gmao.conf" > "${PHP_FPM_DIR}/gmao.conf"
    BODY_MAX="500M"   # aligné sur upload_max_filesize du pool fourni
    ok "Pool PHP-FPM copié dans ${PHP_FPM_DIR}/gmao.conf"
else
    BODY_MAX="40M"    # aligné sur post_max_size du pool minimal ci-dessous (document 25 Mo en base64 ≈ 34 Mo)
    sauver_conf "${PHP_FPM_DIR}/gmao.conf"
    cat > "${PHP_FPM_DIR}/gmao.conf" <<EOFPM
[gmao]
user = www-data
group = www-data
listen = /run/php/php${PHP_VERSION}-fpm-gmao.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = dynamic
pm.max_children = 20
pm.start_servers = 4
pm.min_spare_servers = 2
pm.max_spare_servers = 8
pm.max_requests = 500
request_terminate_timeout = 120
php_admin_value[memory_limit] = 256M
php_admin_value[upload_max_filesize] = 36M
php_admin_value[post_max_size] = 40M
php_admin_value[session.save_path] = ${INSTALL_DIR}/data/sessions
php_admin_value[session.gc_maxlifetime] = 86400
php_admin_flag[log_errors] = on
php_admin_value[error_log] = ${INSTALL_DIR}/data/logs/php_errors.log
EOFPM
fi

systemctl enable "php${PHP_VERSION}-fpm"
systemctl restart "php${PHP_VERSION}-fpm"
ok "PHP-FPM configuré."

# ═══════════════════════════════════════════════════════════════════════════════
# 5. NGINX
# ═══════════════════════════════════════════════════════════════════════════════
info "Configuration Nginx..."

# ── Corps commun des deux modes (HTTP derrière proxy / HTTPS direct) ──────────
# ⚠️ FIX SÉCURITÉ : les configurations générées ici divergeaient de
# deploy/nginx.conf, durcie, et étaient permissives :
#   • « location ~ \.php$ » exécutait N'IMPORTE QUEL fichier .php (au lieu de
#     la liste blanche des points d'entrée) ;
#   • les refus « ~ /data/ », « ~ /config\.json$ »… étaient placés APRÈS le
#     bloc des fichiers statiques : nginx retenant la PREMIÈRE regex qui
#     correspond, /data/…/photo.jpg était servi, et /config.key aussi ;
#   • ni /extensions/, ni les .sqlite / .key / .log, ni les outils n'étaient
#     refusés.
# Le corps ci-dessous reprend deploy/nginx.conf : refus en tête (exact et ^~,
# qui priment sur toute regex), PHP en liste blanche, tout autre .php refusé.
#
# En-têtes de sécurité : nginx n'hérite PAS des add_header du serveur dans un
# bloc location qui pose les siens (Cache-Control…). « @SECU@ » les répète
# donc dans chacun de ces blocs, sinon la page d'accueil les perdrait.
nginx_corps() {
    local https_param="$1" hsts="${2:-}"
    local secu='add_header X-Frame-Options "SAMEORIGIN" always;\n        add_header X-Content-Type-Options "nosniff" always;\n        add_header Referrer-Policy "no-referrer" always;'
    [[ -n "$hsts" ]] && secu="${secu}\\n        ${hsts}"
    # ⚠️ LA PAGE DE L'APPLICATION N'AVAIT AUCUNE CSP.
    # La politique n'était posée que par api/index.php, donc sur les réponses
    # JSON — où elle ne protège rien. index.html, le seul document qui exécute
    # du script, en était dépourvu : la moindre injection HTML devenait une
    # exécution complète, sans restriction des connexions sortantes.
    # « @CSP@ » est posé sur les seuls blocs qui servent la page (pas sur le
    # service worker ni les fichiers statiques). Même valeur que le défaut de
    # api/config.php ; 'unsafe-inline' reste nécessaire tant que l'interface
    # utilise des gestionnaires onclick (migration vers des nonces à prévoir).
    local csp="add_header Content-Security-Policy \"default-src 'self'; base-uri 'self'; form-action 'self'; img-src 'self' data: blob:; script-src 'self' 'unsafe-inline' blob:; style-src 'self' 'unsafe-inline'; worker-src 'self' blob:; connect-src 'self'; object-src 'none'; frame-ancestors 'self'\" always;"
    sed -e "s|@INSTALL_DIR@|${INSTALL_DIR}|g" \
        -e "s|@PHP_VERSION@|${PHP_VERSION}|g" \
        -e "s|@BODY_MAX@|${BODY_MAX:-32M}|g" \
        -e "s|@FASTCGI_HTTPS@|${https_param}|g" \
        -e "s|@CSP@|${csp}|g" \
        -e "s|@SECU@|${secu}|g" <<'EOCORPS'
    root @INSTALL_DIR@;
    index index.html;
    charset utf-8;
    client_max_body_size @BODY_MAX@;
    server_tokens off;

    @SECU@

    gzip            on;
    gzip_vary       on;
    gzip_comp_level 6;
    gzip_min_length 1024;
    gzip_proxied    any;
    gzip_types text/plain text/css text/javascript application/javascript application/json
               application/manifest+json image/svg+xml font/ttf;

    # ── Refus — EN TÊTE (exact / ^~ priment sur toutes les regex) ────────────
    location ~ /\.                      { deny all; return 404; }
    location = /config.json             { deny all; return 404; }
    location = /config.example.json     { deny all; return 404; }
    location = /config.key              { deny all; return 404; }
    location = /tenants.json            { deny all; return 404; }
    location = /.env                    { deny all; return 404; }
    location = /.env.example            { deny all; return 404; }
    location = /README.md               { deny all; return 404; }
    location ^~ /data/                  { deny all; return 404; }
    location ^~ /deploy/                { deny all; return 404; }
    location ^~ /logs/                  { deny all; return 404; }
    location ^~ /backups/               { deny all; return 404; }
    location ^~ /vendor/                { deny all; return 404; }
    location ^~ /outils/                { deny all; return 404; }
    location ^~ /Documentations/        { deny all; return 404; }
    location ^~ /extensions/            { deny all; return 404; }
    location ^~ /node_modules/          { deny all; return 404; }
    location ~* \.(larka|larka_thematique|phar)$ { deny all; return 404; }
    location ~* \.(pem|key|crt|cer|p12|pfx|p8|jks|keystore|kdbx|ppk|gpg|asc|sec|secret|sql|gz|zip|tar|tgz|7z|bak|backup|old|orig|log|dump|ini|conf|db|sqlite|sqlite3)$ {
        deny all; return 404;
    }
    location = /api/diagnostic.php      { deny all; return 404; }

    # ── Routes applicatives ──────────────────────────────────────────────────
    location /api/ { try_files $uri /api/index.php?$query_string; }
    location = /oauth/microsoft { rewrite ^ /oauth/microsoft.php last; }
    location = /oauth/google    { rewrite ^ /oauth/google.php    last; }
    location /oauth/ { try_files $uri =404; }

    # PHP : UNIQUEMENT les points d'entrée légitimes.
    location ~ ^/(api/index|router|oauth/microsoft|oauth/google)\.php$ {
        fastcgi_pass unix:/run/php/php@PHP_VERSION@-fpm-gmao.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        @FASTCGI_HTTPS@
        include fastcgi_params;
        fastcgi_read_timeout 300;
        fastcgi_hide_header X-Powered-By;
        fastcgi_param HTTP_PROXY "";
    }
    # Tout autre .php : refusé (fichier déposé, ancien script…).
    location ~ \.php$ { deny all; return 404; }

    location = /index.html {
        add_header Cache-Control "no-cache, must-revalidate" always;
        @SECU@
        @CSP@
    }
    location ~* \.html$ {
        add_header Cache-Control "no-cache, must-revalidate" always;
        @SECU@
        @CSP@
    }
    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff2?|ttf|webmanifest)$ {
        expires 7d;
        add_header Cache-Control "public, immutable";
        @SECU@
    }
    location = /sw.js {
        add_header Cache-Control "no-cache, no-store, must-revalidate";
        add_header Service-Worker-Allowed "/";
        @SECU@
    }
    location = /apple-touch-icon.png { rewrite ^ /icon.png last; }
    location = /manifest.webmanifest {
        expires 1h;
        add_header Cache-Control "public";
        @SECU@
    }
    location / {
        add_header Cache-Control "no-cache, must-revalidate" always;
        @SECU@
        @CSP@
        try_files $uri $uri/ /index.html;
    }

    access_log /var/log/nginx/gmao_access.log larka_sans_jetons;
    error_log  /var/log/nginx/gmao_error.log;
EOCORPS
}

# Journal d'accès SANS la chaîne de requête : elle peut porter un jeton à usage
# unique (retour OAuth : code, state ; échange de session : token). Seule
# l'action de l'API est gardée, utile au diagnostic ; pas de Referer non plus
# (même raison). Défini dans conf.d (contexte http), une seule fois.
cat > /etc/nginx/conf.d/larka-journal.conf <<'EOLOG'
# Généré par deploy/install.sh (Larka) — journal d'accès sans jetons.
log_format larka_sans_jetons '$remote_addr - [$time_local] "$request_method $uri $server_protocol" '
                             '$status $body_bytes_sent "$http_user_agent" action=$arg_action';
EOLOG

if [[ "$BEHIND_PROXY" == "1" ]]; then
    # ── Mode reverse-proxy / tunnel (Cloudflare, etc.) : HTTP local, TLS en amont ──
    info "Mode reverse-proxy/tunnel : Nginx en HTTP sur le port ${HTTP_PORT} (pas de Let's Encrypt)."
    sauver_conf /etc/nginx/sites-available/gmao
    cat > /etc/nginx/sites-available/gmao <<EONGINX
server {
    listen ${HTTP_PORT};
    listen [::]:${HTTP_PORT};
    server_name _;

    # TLS assuré en amont (tunnel/proxy). On force la détection HTTPS côté PHP :
    # REMOTE_ADDR = proxy local (127.0.0.1, déjà dans trusted_proxies), donc
    # api/config.php fait confiance à X-Forwarded-Proto et active les cookies « secure ».
$(nginx_corps 'fastcgi_param HTTP_X_FORWARDED_PROTO https;')
}
EONGINX
    ln -sf /etc/nginx/sites-available/gmao /etc/nginx/sites-enabled/
    rm -f /etc/nginx/sites-enabled/default
    nginx -t && systemctl enable --now nginx && systemctl reload nginx
    ok "Nginx configuré en HTTP (port ${HTTP_PORT}) — TLS à terminer en amont (tunnel/proxy)."
    warn "Faites pointer le tunnel/proxy vers http://127.0.0.1:${HTTP_PORT}."
else

sauver_conf /etc/nginx/sites-available/gmao
cat > /etc/nginx/sites-available/gmao <<EONGINX
server {
    listen 80;
    server_name ${DOMAIN};
    location / { return 301 https://\$host\$request_uri; }
    location /.well-known/acme-challenge/ { root /var/www/certbot; }
}

server {
    listen 443 ssl http2;
    server_name ${DOMAIN};

    ssl_certificate     ${SSL_CERT_NGINX};
    ssl_certificate_key ${SSL_KEY_NGINX};
    ssl_protocols       TLSv1.2 TLSv1.3;
    ssl_ciphers         HIGH:!aNULL:!MD5;
    ssl_prefer_server_ciphers on;
    ssl_session_cache   shared:SSL:10m;

$(nginx_corps 'fastcgi_param HTTPS on;' 'add_header Strict-Transport-Security "max-age=31536000" always;')
}
EONGINX

ln -sf /etc/nginx/sites-available/gmao /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default

# ── Certificat SSL ──────────────────────────────────────────────────────────
if [[ "$USE_CERTBOT" != "1" ]]; then
    if [[ -n "$SSL_CERT" ]]; then
        ok "Certificat fourni : ${SSL_CERT} (Certbot non utilisé)."
    else
        warn "Certbot désactivé : placez le certificat et la clé aux emplacements suivants"
        warn "  (ou adaptez ssl_certificate* dans /etc/nginx/sites-available/gmao) :"
        warn "  ${SSL_CERT_NGINX}"
        warn "  ${SSL_KEY_NGINX}"
    fi
elif [[ ! -d "/etc/letsencrypt/live/${DOMAIN}" ]]; then
    info "Obtention du certificat SSL Let's Encrypt..."
    # D'abord, démarrer Nginx en HTTP only pour le challenge
    # Créer une config temporaire HTTP-only
    cat > /etc/nginx/sites-available/gmao-temp <<EOTEMP
server {
    listen 80;
    server_name ${DOMAIN};
    location /.well-known/acme-challenge/ { root /var/www/certbot; }
    location / { return 200 'OK'; }
}
EOTEMP
    ln -sf /etc/nginx/sites-available/gmao-temp /etc/nginx/sites-enabled/gmao
    mkdir -p /var/www/certbot
    nginx -t && systemctl restart nginx

    certbot certonly --webroot -w /var/www/certbot -d "${DOMAIN}" --non-interactive --agree-tos --email "admin@${DOMAIN}" || {
        warn "Échec certbot. Installez le certificat manuellement avec :"
        warn "  certbot certonly --nginx -d ${DOMAIN}"
    }

    # Restaurer la config complète
    ln -sf /etc/nginx/sites-available/gmao /etc/nginx/sites-enabled/gmao
    rm -f /etc/nginx/sites-available/gmao-temp
fi

if nginx -t; then
    systemctl enable --now nginx && systemctl reload nginx
    ok "Nginx configuré avec HTTPS."
else
    warn "Échec de la configuration Nginx HTTPS (certificat manquant ?)."
    warn "  Domaine non public (ex. tunnel Cloudflare) ? Relancez :"
    warn "  ./start.sh prod --behind-proxy --http-port <port_du_tunnel>"
fi
fi

# ═══════════════════════════════════════════════════════════════════════════════
# 6. INITIALISATION DE LA BASE
# ═══════════════════════════════════════════════════════════════════════════════
info "Initialisation des tables PostgreSQL..."
# Un simple appel HTTP local suffit — Database.php crée les tables au 1er accès.
_INIT_URL="http://127.0.0.1:${HTTP_PORT}/api/index.php?action=superadmin_status"
_INIT_CODE=""
if command -v curl >/dev/null 2>&1; then
    _INIT_CODE=$(curl -s -o /dev/null -w "%{http_code}" "$_INIT_URL" 2>/dev/null || true)
elif command -v wget >/dev/null 2>&1; then
    wget -q -O /dev/null "$_INIT_URL" 2>/dev/null && _INIT_CODE="200"
fi
if [[ "$_INIT_CODE" =~ ^(2|3)[0-9][0-9]$ ]]; then
    ok "Base initialisée (HTTP ${_INIT_CODE})."
else
    warn "Tables non initialisées automatiquement (ni curl ni wget, ou Nginx injoignable)."
    warn "  → ouvrez une fois l'URL de l'application pour déclencher la création des tables."
fi

# ═══════════════════════════════════════════════════════════════════════════════
# 7. CRON (sauvegardes + rotation logs)
# ═══════════════════════════════════════════════════════════════════════════════
info "Configuration du cron..."
_CERTBOT_CRON='0 3 * * * root certbot renew --quiet --post-hook "systemctl reload nginx"'
[[ "$BEHIND_PROXY" == "1" ]] && _CERTBOT_CRON='# (TLS géré en amont : renouvellement certbot non requis)'
[[ "$BEHIND_PROXY" != "1" && "$USE_CERTBOT" != "1" ]] && _CERTBOT_CRON='# (Certbot non utilisé : renouveler le certificat fourni selon sa propre procédure)'
_PHP_BIN="$(command -v php || echo /usr/bin/php)"
sauver_conf /etc/cron.d/gmao
cat > /etc/cron.d/gmao <<EOCRON
# Renouvellement certificat SSL (uniquement si TLS géré localement)
${_CERTBOT_CRON}

# Sauvegardes automatiques : seules les sauvegardes DUES sont faites (aucune si
# rien n'est réglé). Réglages : Configuration → Serveur, ou Super Admin par
# organisation. État : php ${INSTALL_DIR}/api/outils/sauvegarde-auto.php --etat
*/10 * * * * www-data ${_PHP_BIN} ${INSTALL_DIR}/api/outils/sauvegarde-auto.php --silencieux >> ${INSTALL_DIR}/data/logs/sauvegardes.log 2>&1

# Rotation des logs PHP
0 4 * * 0 www-data find ${INSTALL_DIR}/data/logs -name '*.log' -size +50M -exec truncate -s 0 {} \;

# Nettoyage sessions expirées
0 5 * * * www-data find ${INSTALL_DIR}/data/sessions -name 'sess_*' -mtime +2 -delete 2>/dev/null
EOCRON

ok "Cron configuré."

# PostgreSQL après une mise à jour du système : bases à réindexer ?
sys_pg_collation_proposer "${INSTALL_DIR}"

# Prérequis complets, vus par le compte du serveur web (droits d'écriture,
# version du serveur PostgreSQL) : les avertissements sont affichés.
if [[ -f "${INSTALL_DIR}/api/outils/prerequis.php" ]]; then
    info "Vérification des prérequis (compte www-data)…"
    sudo -u www-data php "${INSTALL_DIR}/api/outils/prerequis.php" --court 2>&1 | sed 's/^/    /' || true
fi

# ═══════════════════════════════════════════════════════════════════════════════
# RÉSUMÉ
# ═══════════════════════════════════════════════════════════════════════════════
echo ""
echo "═══════════════════════════════════════════════════════════"
echo -e "  ${GREEN}✅ Installation terminée !${NC}"
echo "═══════════════════════════════════════════════════════════"
echo ""
echo "  URL :           https://${DOMAIN}"
echo "  Répertoire :    ${INSTALL_DIR}"
echo "  Config :        ${INSTALL_DIR}/config.json   (non sensible)"
echo "  Secrets :       ${INSTALL_DIR}/.env          (chmod 600 — à sauvegarder en lieu sûr)"
echo ""
echo "  PostgreSQL :"
echo "    Utilisateur : ${DB_USER}"
echo "    Mot de passe: dans ${INSTALL_DIR}/.env (GMAO_DB_PASSWORD) — non affiché"
echo "    Bases :       ${DB_NAME}, ${DB_ADMIN_NAME}"
echo ""
echo "  Super Admin :"
echo "    Première visite : identifiant et mot de passe à choisir à l'écran,"
echo "                      depuis le serveur (ou securite.allow_remote_setup)"
echo "    URL :         https://${DOMAIN} → icône ⚙️ en bas du menu"
echo ""
echo "  Prochaines étapes :"
echo "    1. Changez le mot de passe super admin"
echo "    2. Créez votre premier tenant"
echo "    3. Configurez Microsoft OAuth dans config.json si besoin"
echo "    4. Configurez SMTP pour les emails"
echo ""
echo "  Commandes utiles :"
echo "    systemctl status nginx php${PHP_VERSION}-fpm postgresql"
echo "    tail -f ${INSTALL_DIR}/data/logs/php_errors.log"
echo "    tail -f /var/log/nginx/gmao_error.log"
echo ""

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
# Usage : sudo bash install.sh [--domain gmao.example.com] [--db-password MOT_DE_PASSE]
#
# Ce script installe :
#   - PostgreSQL 16
#   - PHP 8.3 + PHP-FPM + extensions requises
#   - Nginx avec HTTPS (Let's Encrypt)
#   - Larka dans /var/www/gmao
#
# Prérequis :
#   - Ubuntu 22.04 / 24.04 ou Debian 12+
#   - Accès root (sudo)
#   - Un nom de domaine pointant vers le serveur (pour Let's Encrypt)
# ═══════════════════════════════════════════════════════════════════════════════

set -euo pipefail

# ── Couleurs ────────────────────────────────────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; BLUE='\033[0;34m'; NC='\033[0m'
info()  { echo -e "${BLUE}[INFO]${NC}  $1"; }
ok()    { echo -e "${GREEN}[OK]${NC}    $1"; }
warn()  { echo -e "${YELLOW}[WARN]${NC}  $1"; }
err()   { echo -e "${RED}[ERR]${NC}   $1"; exit 1; }

# ── Vérifications ───────────────────────────────────────────────────────────
[[ $EUID -ne 0 ]] && err "Ce script doit être exécuté en root (sudo)."

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
PHP_VERSION=$(php -v 2>/dev/null | head -1 | grep -oP '\d+\.\d+' || echo "8.3")

while [[ $# -gt 0 ]]; do
    case "$1" in
        --domain)      DOMAIN="$2"; shift 2 ;;
        --db-password) DB_PASSWORD="$2"; shift 2 ;;
        --install-dir) INSTALL_DIR="$2"; shift 2 ;;
        --behind-proxy|--tunnel|--no-tls) BEHIND_PROXY=1; shift ;;
        --http-port)   HTTP_PORT="$2"; shift 2 ;;
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

if [[ -z "$DB_PASSWORD" ]]; then
    # Réutiliser le mot de passe d'un .env déjà présent pour rester cohérent avec
    # la configuration conservée (sinon ALTER ROLE le change et le .env gardé
    # pointe vers l'ancien mot de passe → échec de connexion PostgreSQL).
    if [[ -f "${INSTALL_DIR}/.env" ]] && grep -q '^GMAO_DB_PASSWORD=.' "${INSTALL_DIR}/.env"; then
        DB_PASSWORD=$(grep '^GMAO_DB_PASSWORD=' "${INSTALL_DIR}/.env" | head -1 | cut -d= -f2-)
        info "Mot de passe PostgreSQL repris depuis ${INSTALL_DIR}/.env (cohérence avec l'existant)."
    else
        DB_PASSWORD=$(openssl rand -base64 24 | tr -d '/+=' | head -c 24)
        warn "Mot de passe PostgreSQL généré : $DB_PASSWORD"
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
else
  echo "  Réseau        : Nginx + HTTPS Let's Encrypt"
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
[[ "$_HAS_CERTBOT" == "0" ]] && _need_install="${_need_install} Certbot"

if [[ -n "$_need_install" ]]; then
    echo ""
    warn "Paquets manquants :${_need_install}"
    read -p "Installer les paquets manquants ? [O/n] " -n 1 -r
    echo ""
    if [[ $REPLY =~ ^[Nn]$ ]]; then
        warn "Installation des paquets ignorée. Assurez-vous qu'ils sont disponibles."
    else
        export DEBIAN_FRONTEND=noninteractive
        apt-get update -qq

        if [[ "$_HAS_PG" == "0" ]]; then
            info "Installation de PostgreSQL..."
            apt-get install -y -qq postgresql postgresql-contrib
        fi

        if [[ "$_HAS_PHP" == "0" ]]; then
            info "Installation de PHP ${PHP_VERSION}..."
            apt-get install -y -qq \
                php${PHP_VERSION}-fpm \
                php${PHP_VERSION}-pgsql \
                php${PHP_VERSION}-mbstring \
                php${PHP_VERSION}-xml \
                php${PHP_VERSION}-curl \
                php${PHP_VERSION}-gd \
                php${PHP_VERSION}-zip \
                php${PHP_VERSION}-intl \
                php${PHP_VERSION}-bcmath 2>/dev/null || {
                    warn "php${PHP_VERSION} non disponible, tentative avec la version par défaut..."
                    apt-get install -y -qq \
                        php-fpm php-pgsql php-mbstring php-xml php-curl php-gd php-zip php-intl php-bcmath
                    PHP_VERSION=$(php -v | head -1 | grep -oP '\d+\.\d+')
                }
        fi

        if [[ "$_HAS_NGINX" == "0" ]]; then
            info "Installation de Nginx..."
            apt-get install -y -qq nginx
        fi

        if [[ "$_HAS_CERTBOT" == "0" ]]; then
            info "Installation de Certbot..."
            apt-get install -y -qq certbot python3-certbot-nginx 2>/dev/null || true
        fi

        ok "Paquets installés."
    fi
else
    ok "Tous les paquets sont déjà installés (PostgreSQL, PHP ${PHP_VERSION}, Nginx)."
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

SELECT 'CREATE DATABASE ${DB_NAME} OWNER ${DB_USER} ENCODING UTF8'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = '${DB_NAME}')\gexec

SELECT 'CREATE DATABASE ${DB_ADMIN_NAME} OWNER ${DB_USER} ENCODING UTF8'
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

# Copier les fichiers (depuis le répertoire du script)
SCRIPT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
if [[ -f "${SCRIPT_DIR}/index.html" ]]; then
    if command -v rsync >/dev/null 2>&1; then
        rsync -a --exclude='deploy' --exclude='.git' --exclude='data/sessions' \
              --exclude='data/logs/*.log' --exclude='*.pem' --exclude='config.json' \
              --exclude='.env' \
              --exclude='Caddyfile' --exclude='start.bat' --exclude='demarrer.sh' \
              "${SCRIPT_DIR}/" "${INSTALL_DIR}/"
    else
        # Repli sans rsync (tar est toujours présent) : mêmes exclusions, permissions préservées
        tar -C "${SCRIPT_DIR}" \
            --exclude='./deploy' --exclude='./.git' --exclude='./data/sessions' \
            --exclude='./data/logs/*.log' --exclude='*.pem' --exclude='./config.json' \
            --exclude='./.env' --exclude='./Caddyfile' --exclude='./start.bat' \
            --exclude='./demarrer.sh' \
            -cf - . | tar -C "${INSTALL_DIR}" -xpf -
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
        "csp": "default-src 'self' 'unsafe-inline' 'unsafe-eval'; img-src 'self' data:; connect-src 'self' https://login.microsoftonline.com https://graph.microsoft.com; object-src 'none'; frame-ancestors 'self'",
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
    "google_oauth": {
        "actif": false,
        "client_id": "",
        "client_secret": "",
        "redirect_uri": "https://${DOMAIN}/oauth/google"
    },
    "acces": { "domaine_email_autorise": "" },
    "smtp": { "actif": false, "host": "", "port": 587, "secure": "tls", "username": "", "password": "", "from": "noreply@${DOMAIN}", "from_name": "Larka" },
    "push": { "actif": false, "vapid_public_key": "", "vapid_private_pem": "", "vapid_subject": "mailto:admin@${DOMAIN}" }
}
EOCFG
    ok "config.json généré."
else
    warn "config.json existant conservé."
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
    sed "s/php8\.3/php${PHP_VERSION}/g" "${INSTALL_DIR}/deploy/php-fpm-gmao.conf" > "${PHP_FPM_DIR}/gmao.conf"
    ok "Pool PHP-FPM copié dans ${PHP_FPM_DIR}/gmao.conf"
else
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
php_admin_value[upload_max_filesize] = 30M
php_admin_value[post_max_size] = 32M
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

if [[ "$BEHIND_PROXY" == "1" ]]; then
    # ── Mode reverse-proxy / tunnel (Cloudflare, etc.) : HTTP local, TLS en amont ──
    info "Mode reverse-proxy/tunnel : Nginx en HTTP sur le port ${HTTP_PORT} (pas de Let's Encrypt)."
    cat > /etc/nginx/sites-available/gmao <<EONGINX
server {
    listen ${HTTP_PORT};
    listen [::]:${HTTP_PORT};
    server_name _;

    # TLS assuré en amont (tunnel/proxy). On force la détection HTTPS côté PHP :
    # REMOTE_ADDR = proxy local (127.0.0.1, déjà dans trusted_proxies), donc
    # api/config.php fait confiance à X-Forwarded-Proto et active les cookies « secure ».
    add_header X-Frame-Options        "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy        "no-referrer" always;

    root ${INSTALL_DIR};
    index index.html;
    charset utf-8;
    client_max_body_size 30M;

    location /api/ { try_files \$uri /api/index.php?\$query_string; }
    location = /oauth/microsoft { rewrite ^ /oauth/microsoft.php last; }
    location = /oauth/google    { rewrite ^ /oauth/google.php    last; }
    location /oauth/ { try_files \$uri =404; }

    location ~ \.php\$ {
        fastcgi_pass unix:/run/php/php${PHP_VERSION}-fpm-gmao.sock;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_param HTTP_X_FORWARDED_PROTO https;
        include fastcgi_params;
        fastcgi_read_timeout 120;
    }

    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff2?|webmanifest)\$ {
        expires 7d;
        add_header Cache-Control "public, immutable";
    }

    location / { try_files \$uri \$uri/ /index.html; }

    location ~ /\.(ht|git|env)  { deny all; }
    location ~ /data/           { deny all; }
    location ~ /config\.json\$  { deny all; }
    location ~ /tenants\.json\$ { deny all; }
    location ~ \.pem\$          { deny all; }
    location ~ /deploy/         { deny all; }

    access_log /var/log/nginx/gmao_access.log;
    error_log  /var/log/nginx/gmao_error.log;
}
EONGINX
    ln -sf /etc/nginx/sites-available/gmao /etc/nginx/sites-enabled/
    rm -f /etc/nginx/sites-enabled/default
    nginx -t && systemctl enable --now nginx && systemctl reload nginx
    ok "Nginx configuré en HTTP (port ${HTTP_PORT}) — TLS à terminer en amont (tunnel/proxy)."
    warn "Faites pointer le tunnel/proxy vers http://127.0.0.1:${HTTP_PORT}."
else

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

    ssl_certificate     /etc/letsencrypt/live/${DOMAIN}/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/${DOMAIN}/privkey.pem;
    ssl_protocols       TLSv1.2 TLSv1.3;
    ssl_ciphers         HIGH:!aNULL:!MD5;
    ssl_prefer_server_ciphers on;

    add_header X-Frame-Options        "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy        "no-referrer" always;
    add_header Strict-Transport-Security "max-age=31536000" always;

    root ${INSTALL_DIR};
    index index.html;
    charset utf-8;
    client_max_body_size 30M;

    location /api/ { try_files \$uri /api/index.php?\$query_string; }
    location = /oauth/microsoft { rewrite ^ /oauth/microsoft.php last; }
    location = /oauth/google    { rewrite ^ /oauth/google.php    last; }
    location /oauth/ { try_files \$uri =404; }

    location ~ \.php\$ {
        fastcgi_pass unix:/run/php/php${PHP_VERSION}-fpm-gmao.sock;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_param HTTPS on;
        include fastcgi_params;
        fastcgi_read_timeout 120;
    }

    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff2?|webmanifest)\$ {
        expires 7d;
        add_header Cache-Control "public, immutable";
    }

    location / { try_files \$uri \$uri/ /index.html; }

    location ~ /\.(ht|git|env)  { deny all; }
    location ~ /data/           { deny all; }
    location ~ /config\.json\$  { deny all; }
    location ~ /tenants\.json\$ { deny all; }
    location ~ \.pem\$          { deny all; }
    location ~ /deploy/         { deny all; }

    access_log /var/log/nginx/gmao_access.log;
    error_log  /var/log/nginx/gmao_error.log;
}
EONGINX

ln -sf /etc/nginx/sites-available/gmao /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default

# ── Certificat SSL ──────────────────────────────────────────────────────────
if [[ ! -d "/etc/letsencrypt/live/${DOMAIN}" ]]; then
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
cat > /etc/cron.d/gmao <<EOCRON
# Renouvellement certificat SSL (uniquement si TLS géré localement)
${_CERTBOT_CRON}

# Rotation des logs PHP
0 4 * * 0 www-data find ${INSTALL_DIR}/data/logs -name '*.log' -size +50M -exec truncate -s 0 {} \;

# Nettoyage sessions expirées
0 5 * * * www-data find ${INSTALL_DIR}/data/sessions -name 'sess_*' -mtime +2 -delete 2>/dev/null
EOCRON

ok "Cron configuré."

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
echo "    Mot de passe: ${DB_PASSWORD}"
echo "    Bases :       ${DB_NAME}, ${DB_ADMIN_NAME}"
echo ""
echo "  Super Admin :"
echo "    Login :       superadmin"
echo "    Mot de passe: SuperAdmin2025! (à changer !)"
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

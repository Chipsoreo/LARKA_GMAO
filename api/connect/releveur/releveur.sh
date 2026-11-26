#!/usr/bin/env bash
# SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
# SPDX-License-Identifier: LicenseRef-Larka-Proprietary
# Larka — collecteur du PC releveur (appareils en hotspot Wi-Fi), Linux.
#   ./releveur.sh configurer --heures=08:30,12:30   une fois (profils Wi-Fi NetworkManager, crontab)
#   ./releveur.sh collecter | tester | envoyer | etat | retirer
set -uo pipefail
ICI="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
for c in "${PHP:-}" php php8.4 php8.3 php8.2 php8.1; do
    [[ -n "$c" ]] && command -v "$c" >/dev/null 2>&1 \
        && "$c" -r 'exit(version_compare(PHP_VERSION, "8.1.0", ">=") ? 0 : 1);' 2>/dev/null && exec "$c" "$ICI/releveur.php" "$@"
done
echo "PHP 8.1 ou plus est nécessaire : sudo apt install php-cli php-mbstring (ou PHP=/chemin/vers/php ./releveur.sh)." >&2
exit 1

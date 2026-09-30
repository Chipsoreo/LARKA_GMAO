#!/bin/bash
# SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
# SPDX-License-Identifier: LicenseRef-Larka-Proprietary
#
# This file is part of Larka, proprietary software by Mickaël Larcin.
# All rights reserved. Use is subject to the license terms; copying,
# distribution, modification or reverse-engineering without the author's
# prior written permission is prohibited. See the LICENSE file for details.
#
# ═══════════════════════════════════════════════════════════════════════════════
# Ajoute l'en-tête de licence propriétaire en tête des fichiers source du projet.
# Idempotent : un fichier contenant déjà « SPDX-License-Identifier » est ignoré.
#
#   - .php : en-tête inséré juste après la ligne <?php
#   - .js  : bloc /* … */ ajouté en tête (hors fichiers *.min.js / tiers)
#   - .sh  : en-tête inséré juste après la ligne shebang #!
#
# Usage :  bash scripts/add-license-headers.sh
# ═══════════════════════════════════════════════════════════════════════════════
set -euo pipefail
cd "$(cd "$(dirname "$0")/.." && pwd)"

AUTHOR="Mickaël Larcin (Chipsoreo)"
YEARS="2025-2026"
MARKER="SPDX-License-Identifier"

# Fichiers/dossiers tiers à NE PAS marquer (licences propres).
is_excluded() {
    case "$1" in
        *.min.js) return 0 ;;
        *zxing-browser*) return 0 ;;
        ./vendor/*|./node_modules/*) return 0 ;;
        *) return 1 ;;
    esac
}

php_header() {
cat <<'EOF'
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
EOF
}

js_header() {
cat <<'EOF'
/*
 * SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
 * SPDX-License-Identifier: LicenseRef-Larka-Proprietary
 *
 * This file is part of Larka, proprietary software by Mickaël Larcin.
 * All rights reserved. Use is subject to the license terms; copying,
 * distribution, modification or reverse-engineering without the author's
 * prior written permission is prohibited. See the LICENSE file for details.
 */
EOF
}

sh_header() {
cat <<'EOF'
# SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
# SPDX-License-Identifier: LicenseRef-Larka-Proprietary
#
# This file is part of Larka, proprietary software by Mickaël Larcin.
# All rights reserved. Use is subject to the license terms; copying,
# distribution, modification or reverse-engineering without the author's
# prior written permission is prohibited. See the LICENSE file for details.
EOF
}

count=0
process() {
    local f="$1" type="$2"
    is_excluded "$f" && return 0
    grep -q "$MARKER" "$f" 2>/dev/null && return 0   # déjà marqué

    local tmp; tmp="$(mktemp)"
    case "$type" in
        php)
            if head -1 "$f" | grep -qE '^[[:space:]]*<\?php'; then
                { head -1 "$f"; php_header; tail -n +2 "$f"; } > "$tmp"
            else
                { echo "<?php"; php_header; echo "?>"; cat "$f"; } > "$tmp"
            fi
            ;;
        js)
            { js_header; echo ""; cat "$f"; } > "$tmp"
            ;;
        sh)
            if head -1 "$f" | grep -q '^#!'; then
                { head -1 "$f"; sh_header; tail -n +2 "$f"; } > "$tmp"
            else
                { sh_header; cat "$f"; } > "$tmp"
            fi
            ;;
    esac
    cat "$tmp" > "$f"; rm -f "$tmp"
    echo "  + $f"; count=$((count+1))
}

echo "Ajout des en-têtes de licence propriétaire ($AUTHOR, $YEARS)…"
while IFS= read -r f; do process "$f" php; done < <(find . -name '*.php' -not -path './vendor/*')
while IFS= read -r f; do process "$f" js;  done < <(find ./js -name '*.js' -not -name '*.min.js')
while IFS= read -r f; do process "$f" sh;  done < <(find . -name '*.sh' -not -path './vendor/*')
echo "Terminé : $count fichier(s) marqué(s)."

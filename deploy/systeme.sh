# shellcheck shell=bash
# SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
# SPDX-License-Identifier: LicenseRef-Larka-Proprietary
#
# This file is part of Larka, proprietary software by Mickaël Larcin.
# All rights reserved. Use is subject to the license terms; copying,
# distribution, modification or reverse-engineering without the author's
# prior written permission is prohibited. See the LICENSE file for details.
# ═══════════════════════════════════════════════════════════════════════════
#  Larka — composants du système : paquets et PostgreSQL
# ═══════════════════════════════════════════════════════════════════════════
#
#  Chargé par start.sh et deploy/install.sh (« source »). Deux règles :
#
#  1. Installer ce qui MANQUE, sans mettre à jour en silence ce qui est déjà
#     là. Avant d'installer quoi que ce soit, la transaction est simulée : si
#     des paquets déjà installés devaient être mis à jour, ils sont montrés et
#     l'accord est demandé — « non » par défaut quand PostgreSQL en dépend
#     (bibliothèque C, ICU, serveur PostgreSQL). Une mise à jour de la
#     bibliothèque C change l'ordre de tri des textes : PostgreSQL demande alors
#     de reconstruire les index de ses bases, et refuse de créer une base à
#     partir de template1 tant que ce n'est pas fait.
#
#  2. Ne jamais casser une base. Après une telle mise à jour (faite par Larka
#     ou par l'administrateur du système), Larka le détecte et propose de
#     reconstruire les index : aucune donnée n'est modifiée, et une
#     reconstruction qui échoue laisse le problème visible au lieu de le taire.
#
#  Variables lues :  SYS_OUI=true  accepte sans question (mode -y).
#  Variable posée :  SYS_MAJ_SENSIBLE=1 si une bibliothèque dont dépend
#                    PostgreSQL a été mise à jour.
# ═══════════════════════════════════════════════════════════════════════════

declare -F info >/dev/null 2>&1 || info() { echo "▸ $1"; }
declare -F ok   >/dev/null 2>&1 || ok()   { echo "✓ $1"; }
declare -F warn >/dev/null 2>&1 || warn() { echo "⚠ $1"; }
SYS_MAJ_SENSIBLE="${SYS_MAJ_SENSIBLE:-0}"

_sys_root() { if [[ $EUID -eq 0 ]]; then "$@"; else sudo "$@"; fi; }

# Commande en tant qu'administrateur PostgreSQL (compte système « postgres »).
_sys_pg_su() {
    if [[ $EUID -eq 0 ]]; then
        if command -v runuser >/dev/null 2>&1; then (cd / && runuser -u postgres -- "$@")
        else (cd / && su -s /bin/sh postgres -c "$(printf '%q ' "$@")"); fi
    else
        (cd / && sudo -u postgres "$@")
    fi
}
_sys_peut_pg_su() { [[ $EUID -eq 0 ]] || sudo -n true 2>/dev/null || [[ -t 0 ]]; }

# Paquets dont dépend PostgreSQL : bibliothèque C et ses locales (ordre de
# tri), ICU, serveur PostgreSQL lui-même (redémarrage).
sys_paquet_sensible() {
    case "${1%%:*}" in
        libc6|libc-bin|libc-l10n|locales|locales-all|glibc|glibc-common|glibc-langpack-*|glibc-locale*|libicu*|icu|postgresql-[0-9]*|postgresql-server|postgresql[0-9]*-server) return 0 ;;
    esac
    return 1
}

# Montre les mises à jour que l'installation entraînerait et demande l'accord.
# $1 : lignes « paquet version-installée -> nouvelle-version ».
_sys_accord_majs() {
    local majs="$1" sensibles="" l nom
    while IFS= read -r l; do
        [[ -n "$l" ]] || continue
        nom="${l%% *}"
        if sys_paquet_sensible "$nom"; then sensibles+="${nom%%:*} "; fi
    done <<< "$majs"
    echo
    warn "Installer ce qui manque mettrait aussi à jour des paquets DÉJÀ installés :"
    while IFS= read -r l; do [[ -n "$l" ]] && echo "       $l"; done <<< "$majs"
    if [[ -n "$sensibles" ]]; then
        echo "     Dont des composants dont dépend PostgreSQL (${sensibles% }) : l'ordre de tri des"
        echo "     textes peut changer. Il faudra reconstruire les index des bases — Larka le propose"
        echo "     juste après, sans toucher aux données — et redémarrer PostgreSQL."
    fi
    if [[ "${SYS_OUI:-false}" == "true" ]]; then
        info "Accepté (mode non interactif -y)."
    elif [[ ! -t 0 ]]; then
        warn "Pas de terminal pour demander : rien n'est installé ni mis à jour (relancez avec -y pour accepter)."
        return 1
    else
        local rep invite="[O/n]"
        [[ -n "$sensibles" ]] && invite="[o/N]"
        read -r -p "     Accepter ces mises à jour ? ${invite} " rep || rep=""
        if [[ -z "$rep" ]]; then
            [[ "$invite" == "[O/n]" ]] || { info "Refusé : rien n'a été installé ni mis à jour."; return 1; }
        elif [[ ! "$rep" =~ ^[OoYy] ]]; then
            info "Refusé : rien n'a été installé ni mis à jour."
            return 1
        fi
    fi
    [[ -n "$sensibles" ]] && SYS_MAJ_SENSIBLE=1
    return 0
}

# ── Gestionnaires de paquets ───────────────────────────────────────────────
_sys_paquets_apt() {
    local manquants=() p
    for p in "$@"; do
        [[ "$(dpkg-query -W -f='${db:Status-Status}' "$p" 2>/dev/null)" == "installed" ]] || manquants+=("$p")
    done
    if (( ${#manquants[@]} == 0 )); then ok "Paquets déjà présents : rien à installer, rien à mettre à jour."; return 0; fi
    info "À installer : ${manquants[*]}"
    export DEBIAN_FRONTEND=noninteractive
    _sys_root apt-get update -qq || { warn "« apt-get update » a échoué (dépôts inaccessibles ?)."; return 2; }
    # Simulation : « Inst paquet [version installée] (nouvelle version …) » = mise à jour.
    local majs
    majs="$(LC_ALL=C apt-get -s install "${manquants[@]}" 2>/dev/null \
        | awk '/^Inst / && $3 ~ /^\[/ { v=$3; gsub(/[][]/, "", v); n=$4; gsub(/[()]/, "", n); print $2 " " v " -> " n }' || true)"
    if [[ -n "$majs" ]]; then _sys_accord_majs "$majs" || return 1; fi
    _sys_root apt-get install -y -qq "${manquants[@]}" || return 2
}

_sys_paquets_dnf() {
    local manquants=() p
    for p in "$@"; do rpm -q "$p" >/dev/null 2>&1 || manquants+=("$p"); done
    if (( ${#manquants[@]} == 0 )); then ok "Paquets déjà présents : rien à installer, rien à mettre à jour."; return 0; fi
    info "À installer : ${manquants[*]}"
    local sim majs
    sim="$(LC_ALL=C _sys_root dnf install --assumeno "${manquants[@]}" 2>&1 || true)"
    majs="$(awk '/^Upgrading/ { f=1; next } /^[A-Z]/ { f=0 } f && NF >= 3 && $1 != "replacing" { print $1 " -> " $3 }' <<< "$sim" || true)"
    if [[ -n "$majs" ]]; then _sys_accord_majs "$majs" || return 1; fi
    _sys_root dnf install -y "${manquants[@]}" || return 2
}

_sys_paquets_zypper() {
    local manquants=() p
    for p in "$@"; do rpm -q "$p" >/dev/null 2>&1 || manquants+=("$p"); done
    if (( ${#manquants[@]} == 0 )); then ok "Paquets déjà présents : rien à installer, rien à mettre à jour."; return 0; fi
    info "À installer : ${manquants[*]}"
    local sim majs
    sim="$(LC_ALL=C _sys_root zypper --non-interactive install --dry-run "${manquants[@]}" 2>&1 || true)"
    majs="$(awk '/going to be upgraded/ { f=1; next } /^$/ { f=0 } f { for (i = 1; i <= NF; i++) print $i " -> (nouvelle version)" }' <<< "$sim" || true)"
    if [[ -n "$majs" ]]; then _sys_accord_majs "$majs" || return 1; fi
    _sys_root zypper --non-interactive install "${manquants[@]}" || return 2
}

_sys_paquets_pacman() {
    local manquants=() p
    for p in "$@"; do pacman -Qi "$p" >/dev/null 2>&1 || manquants+=("$p"); done
    if (( ${#manquants[@]} == 0 )); then ok "Paquets déjà présents : rien à installer, rien à mettre à jour."; return 0; fi
    info "À installer : ${manquants[*]}"
    # Jamais « -Sy » : rafraîchir la base sans tout mettre à jour (mise à jour
    # partielle) est la façon la plus sûre de casser un système Arch.
    local cibles majs="" n v vi
    cibles="$(pacman -Sp --print-format '%n %v' "${manquants[@]}" 2>/dev/null || true)"
    while read -r n v; do
        [[ -n "$n" ]] || continue
        vi="$(pacman -Q "$n" 2>/dev/null | awk '{print $2}' || true)"
        if [[ -n "$vi" && "$vi" != "$v" ]]; then majs+="$n $vi -> $v"$'\n'; fi
    done <<< "$cibles"
    if [[ -n "$majs" ]]; then _sys_accord_majs "${majs%$'\n'}" || return 1; fi
    _sys_root pacman -S --needed --noconfirm "${manquants[@]}" || {
        warn "Échec — base de paquets périmée ? Mettez d'abord le système à jour vous-même (pacman -Syu), puis : ./start.sh doctor --fix"
        return 2
    }
}

_sys_paquets_brew() {
    local manquants=() p
    for p in "$@"; do brew list --versions "$p" >/dev/null 2>&1 || manquants+=("$p"); done
    if (( ${#manquants[@]} == 0 )); then ok "Paquets déjà présents : rien à installer, rien à mettre à jour."; return 0; fi
    info "À installer : ${manquants[*]}  (sans mettre à jour Homebrew ni ce qui est déjà installé)"
    HOMEBREW_NO_AUTO_UPDATE=1 HOMEBREW_NO_INSTALL_UPGRADE=1 HOMEBREW_NO_INSTALLED_DEPENDENTS_CHECK=1 \
        brew install "${manquants[@]}" || return 2
}

# sys_installer_paquets <gestionnaire> <paquet…>
#   0 : fait (ou rien à faire) — 1 : refusé, rien n'a changé — 2 : échec
sys_installer_paquets() {
    local pm="$1"; shift
    case "$pm" in
        apt)    _sys_paquets_apt "$@" ;;
        dnf)    _sys_paquets_dnf "$@" ;;
        zypper) _sys_paquets_zypper "$@" ;;
        pacman) _sys_paquets_pacman "$@" ;;
        brew)   _sys_paquets_brew "$@" ;;
        *)      return 2 ;;
    esac
}

# Après la mise à jour d'une bibliothèque dont il dépend, PostgreSQL doit être
# redémarré : ses processus gardent l'ancienne en mémoire.
sys_pg_redemarrer_si_besoin() {
    [[ "${SYS_MAJ_SENSIBLE:-0}" == "1" ]] || return 0
    command -v systemctl >/dev/null 2>&1 || return 0
    info "Redémarrage de PostgreSQL (bibliothèques système mises à jour)…"
    if _sys_root systemctl restart postgresql 2>/dev/null; then ok "PostgreSQL redémarré."
    else warn "Redémarrage de PostgreSQL impossible — faites-le avant de reconstruire les index : sudo systemctl restart postgresql"; fi
}

# ── PostgreSQL : version de collation ──────────────────────────────────────
# sys_pg_collation_verifier <dossier de Larka>
#   0 : à jour ou sans objet — 3 : des bases sont à réindexer (message affiché)
sys_pg_collation_verifier() {
    local racine="$1" out rc=0
    command -v php >/dev/null 2>&1 || return 0
    [[ -f "$racine/config.json" && -f "$racine/api/outils/postgresql.php" ]] || return 0
    out="$(php "$racine/api/outils/postgresql.php" collation --court 2>/dev/null)" || rc=$?
    [[ $rc -eq 3 ]] || return 0
    echo
    warn "PostgreSQL : la bibliothèque du système a changé de version de collation (l'ordre de tri"
    echo "   des textes), probablement lors d'une mise à jour du système :"
    while IFS= read -r l; do [[ -n "$l" ]] && echo "     $l"; done <<< "$out"
    echo "   Les index sur du texte doivent être reconstruits (sans toucher aux données) ; d'ici là,"
    echo "   PostgreSQL refuse de créer une base à partir de template1."
    return 3
}

# sys_pg_collation_reparer <dossier de Larka>
#   Reconstruit les index des bases de Larka (rôle de Larka), puis des bases
#   système template1 et postgres (administrateur PostgreSQL, serveur local).
#   0 : tout est à jour — 4 : il reste quelque chose (expliqué)
sys_pg_collation_reparer() {
    local racine="$1" outil="$1/api/outils/postgresql.php" lignes port db q rc=0
    info "Reconstruction des index (REINDEX), puis enregistrement de la nouvelle version…"
    php "$outil" collation --reparer 2>/dev/null || true
    lignes="$(php "$outil" collation --liste-su 2>/dev/null || true)"
    if [[ -n "$lignes" ]]; then
        if _sys_peut_pg_su; then
            while IFS=$'\t' read -r port db; do
                [[ -n "$db" ]] || continue
                q="\"${db//\"/\"\"}\""
                if _sys_pg_su psql -XAtq -p "$port" -d "$db" -c "REINDEX DATABASE ${q}" 2>/dev/null \
                   && _sys_pg_su psql -XAtq -p "$port" -d "$db" -c "ALTER DATABASE ${q} REFRESH COLLATION VERSION" 2>/dev/null; then
                    echo "  ✓ ${db} : index reconstruits, version enregistrée (administrateur PostgreSQL)"
                else
                    echo "  ✗ ${db} : échec (administrateur PostgreSQL)"
                fi
            done <<< "$lignes"
        else
            warn "Bases système à réparer en administrateur PostgreSQL :"
            while IFS=$'\t' read -r port db; do
                [[ -n "$db" ]] && echo "       sudo -u postgres psql -p ${port} -d ${db} -c 'REINDEX DATABASE \"${db}\"' -c 'ALTER DATABASE \"${db}\" REFRESH COLLATION VERSION'"
            done <<< "$lignes"
        fi
    fi
    php "$outil" collation --court >/dev/null 2>&1 || rc=$?
    if [[ $rc -eq 3 ]]; then
        warn "Il reste des bases à réindexer (voir ci-dessus) : ./start.sh doctor --fix pour réessayer."
        return 4
    fi
    ok "PostgreSQL : index reconstruits, collation à jour — aucune donnée modifiée."
    return 0
}

# sys_pg_collation_proposer <dossier de Larka>
#   Vérifie ; s'il y a lieu, propose la réparation (oui par défaut : elle ne
#   modifie aucune donnée), ou la fait d'office en mode -y.
sys_pg_collation_proposer() {
    local racine="$1" rc=0
    sys_pg_collation_verifier "$racine" || rc=$?
    [[ $rc -eq 3 ]] || return 0
    if [[ "${SYS_OUI:-false}" == "true" ]]; then
        sys_pg_collation_reparer "$racine" || true
    elif [[ -t 0 ]]; then
        local rep
        read -r -p "   Reconstruire les index maintenant (quelques secondes à quelques minutes) ? [O/n] " rep || rep=""
        if [[ "$rep" =~ ^[Nn] ]]; then info "Plus tard : ${BOLD:-}./start.sh doctor --fix${NC:-}"
        else sys_pg_collation_reparer "$racine" || true; fi
    else
        info "→ ./start.sh doctor --fix"
    fi
    return 0
}

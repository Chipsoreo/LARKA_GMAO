<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.

/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * Larka — Chargeur de variables d'environnement (.env)
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Lit le fichier .env à la racine du projet et expose ses clés via env().
 * Les secrets de l'application (mots de passe BDD, secrets OAuth, SMTP, clé
 * VAPID, clé secrète…) y sont stockés au lieu de config.json.
 *
 * Appelé une seule fois depuis config.php, AVANT la définition des constantes.
 *
 * PRÉCÉDENCE (la plus haute gagne) :
 *   1. variable d'environnement RÉELLE (export shell, EnvironmentFile systemd,
 *      env de conteneur…) lue via getenv()
 *   2. valeur du fichier .env
 *
 * ⚠️ IMPORTANT — robustesse PHP-FPM :
 * On n'appelle volontairement PAS putenv() pour les valeurs du fichier .env.
 * En effet, putenv() modifie l'environnement du PROCESSUS, et un worker FPM
 * réutilisé conserverait alors une ancienne valeur d'une requête à l'autre
 * (les secrets ne se rafraîchiraient pas après modification du .env). En
 * gardant les valeurs du fichier dans un cache PHP (réinitialisé à chaque
 * requête) et en laissant getenv() ne refléter que l'environnement système
 * réel, on obtient une précédence correcte ET un rechargement fiable.
 */

/**
 * Charge le fichier .env (idempotent dans une même requête).
 * Ne touche pas à l'environnement du processus (pas de putenv).
 *
 * @return array<string,string> les paires lues depuis le fichier .env
 */
function loadEnv(bool $force = false): array {
    static $fileVals = null;
    if ($fileVals !== null && !$force) {
        return $fileVals;
    }

    $envFile  = __DIR__ . '/../.env';
    $fileVals = [];

    if (is_file($envFile) && is_readable($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES) as $line) {
            $trim = ltrim($line);
            if ($trim === '' || $trim[0] === '#') continue;       // commentaire / ligne vide
            if (!str_contains($line, '=')) continue;
            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value);
            if ($key === '') continue;

            // Guillemets optionnels + déséchappement \n \t \" \\
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[strlen($value) - 1] === $value[0]) {
                $quote = $value[0];
                $value = substr($value, 1, -1);
                if ($quote === '"') {
                    $value = strtr($value, ['\\n' => "\n", '\\t' => "\t", '\\"' => '"', '\\\\' => '\\']);
                }
            } elseif (str_contains($value, ' #')) {
                // commentaire inline hors guillemets
                $value = trim(explode(' #', $value, 2)[0]);
            }

            $fileVals[$key] = $value;
        }
    }

    // Exposé pour l'UI : quelles clés proviennent du FICHIER (modifiables par l'app).
    $GLOBALS['_gmao_env_file_keys'] = array_keys($fileVals);
    // Cache partagé avec env().
    $GLOBALS['_gmao_env_file_vals'] = $fileVals;
    return $fileVals;
}

/**
 * Lit une variable de configuration sensible.
 * Précédence : environnement système réel (getenv) > fichier .env > $default.
 * Déséchappe les \n littéraux (clé PEM stockée sur une seule ligne).
 *
 * @return string|null
 */
function env(string $key, ?string $default = null): ?string {
    // 1) Environnement système réel (jamais pollué par .env : on ne fait pas de putenv)
    $sys = getenv($key);
    if ($sys !== false && $sys !== '') {
        return _gmao_env_unescape($sys);
    }
    $sys = $_SERVER[$key] ?? null;
    if (is_string($sys) && $sys !== '') {
        return _gmao_env_unescape($sys);
    }

    // 2) Fichier .env
    $fileVals = $GLOBALS['_gmao_env_file_vals'] ?? null;
    if ($fileVals === null) {
        $fileVals = loadEnv();
    }
    if (isset($fileVals[$key]) && $fileVals[$key] !== '') {
        return $fileVals[$key];
    }

    return $default;
}

/** Déséchappe les \n littéraux d'une valeur (clé PEM multi-lignes sur une ligne). */
function _gmao_env_unescape(string $val): string {
    if (str_contains($val, '\\n')) {
        $val = str_replace('\\n', "\n", $val);
    }
    return $val;
}

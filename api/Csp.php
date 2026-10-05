<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
/**
 * Larka — Content-Security-Policy effective (API et page de l'application).
 *
 * Utilisé par api/config.php (réponses de l'API) et router.php (page servie
 * par le serveur PHP intégré). Sous nginx, la page reçoit la CSP écrite dans
 * deploy/nginx.conf.
 *
 * ⚠️ UNE CSP PERSONNALISÉE POUVAIT RENDRE L'INTERFACE INUTILISABLE, SANS RETOUR.
 * L'écran Configuration → Serveur enregistrait « default-src 'self' » dès que
 * son champ CSP était vide (le cas normal) : il suffisait d'y enregistrer
 * autre chose — activer les modules, par exemple. Tant que cette valeur ne
 * touchait que l'API, rien ne se voyait ; depuis que la page reçoit elle aussi
 * la CSP, elle bloquait tous les styles et gestionnaires « inline » dont
 * l'interface est faite : page sans mise en forme, boutons inopérants —
 * y compris ceux qui auraient permis de corriger le réglage.
 * Désormais, une CSP personnalisée qui interdirait les scripts ou les styles
 * inline (ou de même origine) est ignorée au profit de la CSP par défaut.
 */

final class LarkaCsp
{
    public const DEFAUT = "default-src 'self'; base-uri 'self'; form-action 'self'; img-src 'self' data: blob:; "
        . "script-src 'self' 'unsafe-inline' blob:; style-src 'self' 'unsafe-inline'; worker-src 'self' blob:; "
        . "connect-src 'self'; object-src 'none'; frame-ancestors 'self'";

    /** CSP à appliquer : la personnalisée si elle laisse l'interface fonctionner, sinon le défaut. */
    public static function effective(mixed $perso): string
    {
        if (!is_string($perso) || trim($perso) === '' || preg_match('/[\r\n]/', $perso)) return self::DEFAUT;
        if (self::raisonRefus($perso) !== null) return self::DEFAUT;
        // L'aperçu des documents s'affiche dans un cadre de même origine.
        $csp = (string)preg_replace("/frame-ancestors\\s+'none'/i", "frame-ancestors 'self'", trim($perso));
        if (stripos($csp, 'frame-ancestors') === false) $csp = rtrim($csp, '; ') . "; frame-ancestors 'self'";
        return $csp;
    }

    /**
     * Pourquoi cette CSP casserait l'interface (null si elle convient).
     * L'interface exige des scripts et des styles de même origine ET inline
     * (gestionnaires onclick, attributs style) : 'self' et 'unsafe-inline',
     * sans nonce ni empreinte (qui annulent 'unsafe-inline').
     */
    public static function raisonRefus(string $csp): ?string
    {
        $dir = [];
        foreach (explode(';', $csp) as $morceau) {
            $p = preg_split('/\s+/', trim($morceau));
            if (!$p || $p[0] === '') continue;
            $dir[strtolower($p[0])] ??= array_map('strtolower', array_slice($p, 1));
        }
        foreach (['script-src' => 'scripts', 'style-src' => 'styles'] as $nom => $quoi) {
            $sources = $dir[$nom] ?? $dir['default-src'] ?? null;
            if ($sources === null) continue;                     // non restreint
            if (in_array('*', $sources, true)) continue;
            $nonce = (bool)preg_grep("/^'(nonce-|sha(256|384|512)-)/", $sources);
            if (!in_array("'unsafe-inline'", $sources, true) || $nonce) {
                return "elle bloque les $quoi inline dont l'interface est faite";
            }
            if (!in_array("'self'", $sources, true)) return "elle bloque les $quoi de l'application";
        }
        return null;
    }
}

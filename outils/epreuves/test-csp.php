<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
/**
 * Larka — la CSP laisse-t-elle toujours l'interface fonctionner ?
 *
 * Né d'une régression : l'écran Configuration → Serveur enregistrait
 * « default-src 'self' » quand son champ CSP était vide. Appliquée à la page,
 * cette valeur bloquait tous les styles et gestionnaires inline : interface
 * sans mise en forme, boutons inopérants, plus moyen de corriger le réglage.
 *
 *   php outils/epreuves/test-csp.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit("Ligne de commande uniquement.\n"); }
$racine = dirname(__DIR__, 2);
require_once $racine . '/api/Csp.php';

$ok = 0; $ko = [];
$verifier = function (string $nom, bool $vrai) use (&$ok, &$ko) {
    if ($vrai) { $ok++; printf("  ✅  %s\n", $nom); } else { $ko[] = $nom; printf("  ❌  %s\n", $nom); }
};
echo "\n Larka — la CSP laisse-t-elle l'interface fonctionner ?\n\n";
$d = LarkaCsp::DEFAUT;
$verifier("Le défaut autorise scripts et styles inline de même origine", LarkaCsp::raisonRefus($d) === null);
$verifier("Le défaut n'autorise aucun CDN ni 'unsafe-eval'", !preg_match('#https?://|unsafe-eval#', $d));
$verifier("Champ vide → défaut", LarkaCsp::effective('') === $d && LarkaCsp::effective(null) === $d);
$verifier("« default-src 'self' » (valeur fautive d'avant) → ignorée", LarkaCsp::effective("default-src 'self'") === $d);
$verifier("Styles inline interdits → ignorée", LarkaCsp::effective("script-src 'self' 'unsafe-inline'; style-src 'self'") === $d);
$verifier("Nonce (annule 'unsafe-inline') → ignorée", LarkaCsp::effective("default-src 'self' 'unsafe-inline' 'nonce-x'") === $d);
$verifier("Retour à la ligne → ignorée", LarkaCsp::effective("default-src 'self' 'unsafe-inline'\nx") === $d);
$perso = "default-src 'self' 'unsafe-inline'; img-src 'self' data:";
$verifier("CSP personnalisée viable → appliquée", str_starts_with(LarkaCsp::effective($perso), $perso));
$verifier("frame-ancestors 'none' → ramené à 'self' (aperçu des documents)", str_contains(LarkaCsp::effective("default-src 'self' 'unsafe-inline'; frame-ancestors 'none'"), "frame-ancestors 'self'"));
// Les trois endroits qui posent la CSP passent bien par cette règle.
$verifier("api/config.php utilise LarkaCsp::effective", str_contains((string)file_get_contents("$racine/api/config.php"), 'LarkaCsp::effective('));
$verifier("router.php utilise LarkaCsp::effective", str_contains((string)file_get_contents("$racine/router.php"), 'LarkaCsp::effective('));
$cfgJs = (string)file_get_contents("$racine/js/pages/configuration.js");
$verifier("L'écran n'enregistre plus « default-src 'self' » par défaut", !str_contains($cfgJs, "'sh_csp',     \"default-src 'self'\")"));
$nginx = (string)file_get_contents("$racine/deploy/nginx.conf");
$verifier("deploy/nginx.conf pose la même CSP que le défaut", substr_count($nginx, $d) >= 3);

$total = $ok + count($ko);
echo "\n" . ($ko === [] ? " ✅ $ok/$total — la CSP ne peut plus casser l'interface.\n\n" : " ❌ " . count($ko) . " échec(s) sur $total.\n\n");
exit($ko === [] ? 0 : 1);

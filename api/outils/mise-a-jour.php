<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
/**
 * Larka — Mises à jour en ligne de commande (sur le serveur).
 *
 * Utile quand l'interface ne peut pas écrire les fichiers de l'application,
 * ou pour automatiser la VÉRIFICATION (jamais l'installation) par cron.
 *
 *   php api/outils/mise-a-jour.php --verifier
 *   php api/outils/mise-a-jour.php --installer            (demande confirmation)
 *   php api/outils/mise-a-jour.php --installer --oui      (sans question)
 *   php api/outils/mise-a-jour.php --historique
 *   php api/outils/mise-a-jour.php --restaurer <id>
 *
 * À lancer avec l'utilisateur propriétaire des fichiers (www-data en prod) :
 *   sudo -u www-data php /var/www/gmao/api/outils/mise-a-jour.php --verifier
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$racine = dirname(__DIR__, 2);
require_once $racine . '/api/config.php';
if (is_file($racine . '/api/env.php')) { require_once $racine . '/api/env.php'; if (function_exists('loadEnv')) loadEnv(); }
require_once $racine . '/api/MiseAJour.php';

$args = array_slice($argv, 1);
$maj = new LarkaMiseAJour($racine);
$affiche = function (array $e) {
    echo "Installée  : {$e['locale']['libelle']}\n";
    if ($e['erreur']) echo "Erreur     : {$e['erreur']}\n";
    if ($e['disponible']) {
        $d = $e['distante'];
        echo "Disponible : {$d['libelle']} (" . ($d['date'] ?: '?') . ")\n";
        echo "SHA-256    : " . ($d['sha256'] ?: 'ABSENTE') . "\nSignature  : " . ($d['signature'] ? 'présente' : 'absente') . "\n";
        if ($d['notes']) echo "\n" . $d['notes'] . "\n";
    } else {
        echo "À jour.\n";
    }
};
try {
    if (in_array('--verifier', $args, true)) { $affiche($maj->verifier()); exit(0); }
    if (in_array('--historique', $args, true)) { echo json_encode($maj->historique(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n"; exit(0); }
    if (($i = array_search('--restaurer', $args, true)) !== false) {
        $r = $maj->restaurer((string)($args[$i + 1] ?? ''), 'cli');
        echo "Restauré : {$r['fichiers']} fichier(s) — version {$r['version']['libelle']}\n"; exit(0);
    }
    if (in_array('--installer', $args, true)) {
        $e = $maj->verifier();
        $affiche($e);
        if (!$e['disponible']) exit(0);
        if (!in_array('--oui', $args, true)) {
            echo "\nInstaller {$e['distante']['libelle']} ? Les données ne sont pas modifiées. [o/N] ";
            if (strtolower(trim((string)fgets(STDIN))) !== 'o') { echo "Annulé.\n"; exit(1); }
        }
        $r = $maj->installer($e['distante']['version'], 'cli');
        echo "\n✓ {$r['libelle']} installée : {$r['fichiers']} fichiers ({$r['nouveaux']} nouveaux). Sauvegarde : {$r['sauvegarde']}\n";
        exit(0);
    }
    fwrite(STDERR, "Usage : --verifier | --installer [--oui] | --historique | --restaurer <id>\n"); exit(2);
} catch (\Throwable $e) {
    fwrite(STDERR, "Erreur : " . $e->getMessage() . "\n"); exit(1);
}

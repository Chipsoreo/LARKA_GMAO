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
 *   php api/outils/mise-a-jour.php --archive <zip> [--sans-signature] [--reinstaller] [--oui]
 *      (archive d'une version déposée sur le serveur — c'est ce que fait
 *       « ./start.sh maj <zip> » : mêmes protections que la mise à jour en ligne,
 *       config.json, .env, data/ et réglages des modules jamais écrasés,
 *       sauvegarde avant écriture ; signature vérifiée si <zip>.sig est là)
 *   php api/outils/mise-a-jour.php --restaurer <id>
 *      (seule voie pour une sauvegarde antérieure à la signature obligatoire ;
 *       les fichiers de la chaîne de confiance des mises à jour ne sont jamais
 *       restaurés, cf. LarkaMiseAJour::JAMAIS_RESTAURES)
 *
 * À lancer avec l'utilisateur propriétaire des fichiers (www-data en prod) :
 *   sudo -u www-data php /var/www/gmao/api/outils/mise-a-jour.php --verifier
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$args = array_slice($argv, 1);
// --racine <dossier> : installation à mettre à jour, quand ce n'est pas celle
// de cet outil — typiquement, depuis la nouvelle version décompressée à côté,
// pour mettre à jour une installation dont l'outil ne sait pas encore lire une
// archive (« ./start.sh maj --cible <dossier> »). Sa configuration est lue ;
// le code de la mise à jour est celui de cet outil.
$racine = dirname(__DIR__, 2);
if (($i = array_search('--racine', $args, true)) !== false) {
    $r = realpath((string)($args[$i + 1] ?? ''));
    if ($r === false || !is_file($r . '/api/index.php') || !is_file($r . '/version.json')) {
        fwrite(STDERR, "--racine : installation de Larka introuvable.\n"); exit(2);
    }
    $racine = $r;
    array_splice($args, $i, 2);
}
require_once $racine . '/api/config.php';
if (is_file($racine . '/api/env.php')) { require_once $racine . '/api/env.php'; if (function_exists('loadEnv')) loadEnv(); }
require_once __DIR__ . '/../MiseAJour.php';
$maj = new LarkaMiseAJour($racine);
$affiche = function (array $e) {
    echo "Installée  : {$e['locale']['libelle']}\n";
    if (!empty($e['php'])) {
        echo "PHP        : {$e['php']['version']}" . ($e['php']['conseil'] ? " — {$e['php']['conseil']}" : ' (compatible)') . "\n";
        if (!empty($e['php']['commande'])) echo "             → {$e['php']['commande']}\n";
    }
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
        // Ligne de commande : les sauvegardes antérieures à la signature
        // obligatoire restent restaurables ici (pas depuis l'interface).
        $r = $maj->restaurer((string)($args[$i + 1] ?? ''), 'cli', true);
        echo "Restauré : {$r['fichiers']} fichier(s) — version {$r['version']['libelle']}\n"; exit(0);
    }
    if (($i = array_search('--archive', $args, true)) !== false) {
        $zipLocal = (string)($args[$i + 1] ?? '');
        if ($zipLocal === '' || str_starts_with($zipLocal, '--')) { fwrite(STDERR, "--archive attend le chemin du zip.\n"); exit(2); }
        $vLoc = $maj->locale();
        echo "Installée : {$vLoc['libelle']}\nArchive   : $zipLocal\n";
        if (!in_array('--oui', $args, true)) {
            echo "\nAppliquer cette archive ? config.json, .env, data/ et les réglages des modules ne sont pas modifiés ;\n"
               . "les fichiers remplacés et les bases sont sauvegardés avant. [o/N] ";
            if (strtolower(trim((string)fgets(STDIN))) !== 'o') { echo "Annulé.\n"; exit(1); }
        }
        $r = $maj->installerArchive($zipLocal, 'cli', in_array('--sans-signature', $args, true), in_array('--reinstaller', $args, true));
        echo "\n✓ {$r['libelle']} installée depuis l'archive : {$r['fichiers']} fichiers ({$r['nouveaux']} nouveaux)"
           . ($r['signee'] ? ', signature vérifiée' : ', sans signature') . ".\nSauvegarde : {$r['sauvegarde']}"
           . " (retour arrière : php api/outils/mise-a-jour.php --restaurer {$r['sauvegarde']})\n";
        exit(0);
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
    fwrite(STDERR, "Usage : --verifier | --installer [--oui] | --archive <zip> [--sans-signature] [--reinstaller] [--oui] | --historique | --restaurer <id>\n"); exit(2);
} catch (\Throwable $e) {
    fwrite(STDERR, "Erreur : " . $e->getMessage() . "\n"); exit(1);
}

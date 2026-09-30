<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
/**
 * Larka — Publier une nouvelle version (côté ÉDITEUR, pas sur les serveurs).
 *
 * 1) Une seule fois : générer la paire de clés de signature
 *      php outils/publier-version.php --generer-cles [--cle ~/.larka-publication.key]
 *    → la clé PRIVÉE reste chez vous (chmod 600, jamais dans le dépôt) ;
 *    → la clé PUBLIQUE affichée va dans config.json de chaque serveur :
 *        "mises_a_jour": { "cle_publique": "…" }
 *      (et dans config.example.json pour les nouvelles installations).
 *
 * 2) À chaque version :
 *      php outils/publier-version.php --version 2.0.3 [--canal Beta|Stable]
 *            [--notes notes.md] [--cle ~/.larka-publication.key] [--url-base https://…/]
 *            [--reconstruire]   (republier la version déjà inscrite dans version.json)
 *    → met à jour version.json, la version affichée sur la page de connexion
 *      et les « ?v= » d'index.html (cache navigateur) ;
 *    → construit dist/LARKA_GMAO-<version>.zip (sans data/, config.json, .env) ;
 *    → écrit <zip>.sha256, <zip>.sig (si clé) et latest.json (source « manifeste »).
 *
 * 3) Publier, par exemple sur GitHub :
 *      gh release create v2.0.3 dist/LARKA_GMAO-2.0.3.zip dist/LARKA_GMAO-2.0.3.zip.sha256 \
 *         dist/LARKA_GMAO-2.0.3.zip.sig --notes-file notes.md [--prerelease]
 *    Les serveurs la détectent seuls (au plus tard sous 12 h) et la proposent
 *    à l'administrateur, qui valide l'installation.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$racine = dirname(__DIR__);
$opt = getopt('', ['generer-cles', 'version:', 'canal:', 'notes:', 'cle:', 'url-base:', 'sortie:', 'reconstruire', 'php-min:']);
$cleF = $opt['cle'] ?? (getenv('HOME') ?: '.') . '/.larka-publication.key';

if (isset($opt['generer-cles'])) {
    if (is_file($cleF)) { fwrite(STDERR, "La clé $cleF existe déjà (on n'écrase pas une clé de signature).\n"); exit(1); }
    $kp = sodium_crypto_sign_keypair();
    file_put_contents($cleF, base64_encode(sodium_crypto_sign_secretkey($kp)));
    chmod($cleF, 0600);
    echo "Clé privée : $cleF  (à sauvegarder en lieu sûr, jamais dans le dépôt)\n";
    echo "Clé publique (config.json → mises_a_jour.cle_publique) :\n" . base64_encode(sodium_crypto_sign_publickey($kp)) . "\n";
    exit(0);
}

$v = (string)($opt['version'] ?? '');
if (!preg_match('/^\d+\.\d+\.\d+$/', $v)) { fwrite(STDERR, "Usage : --version X.Y.Z [--canal Beta] [--notes f.md] [--cle f] [--url-base https://…/]\n"); exit(2); }
$canal = (string)($opt['canal'] ?? 'Beta');
$notes = isset($opt['notes']) ? (string)file_get_contents($opt['notes']) : '';
$sortie = rtrim((string)($opt['sortie'] ?? $racine . '/dist'), '/');
@mkdir($sortie, 0755, true);

// ── 1. Numéro de version (source unique) + affichage + cache navigateur ────
require_once $racine . '/api/Version.php';
$avant = LarkaVersion::lire();
// --reconstruire : republier la version en place (première release d'une version
// déjà inscrite dans version.json, ou paquet à refaire). Jamais une version plus ancienne.
$cmp = version_compare($v, $avant['version']);
if ($cmp < 0 || ($cmp === 0 && !isset($opt['reconstruire']))) {
    fwrite(STDERR, "$v n'est pas plus récente que {$avant['version']}."
        . ($cmp === 0 ? " Pour publier la version en place : --reconstruire\n" : "\n"));
    exit(1);
}
// Version de PHP exigée par CE paquet : écrite dans version.json (vérifiée à l'installation,
// avant d'écrire quoi que ce soit) et dans les notes (lue dès la détection).
$phpMin = (string)($opt['php-min'] ?? ($avant['php_min'] ?? '8.1'));
if (!preg_match('/^\d+\.\d+$/', $phpMin)) { fwrite(STDERR, "--php-min attend X.Y (ex. 8.1)\n"); exit(2); }
file_put_contents($racine . '/version.json', json_encode(['version' => $v, 'canal' => $canal, 'date' => date('Y-m-d'), 'php_min' => $phpMin], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
if (!preg_match('/\bPHP\s*(?:≥|>=)\s*\d+\.\d+/u', $notes)) $notes = rtrim($notes) . ($notes !== '' ? "\n\n" : '') . "Requiert PHP ≥ $phpMin.";
$libelle = LarkaVersion::libelle(['version' => $v, 'canal' => $canal]);
$html = file_get_contents($racine . '/index.html');
$html = preg_replace('#<span id="loginVersion">[^<]*</span>#', '<span id="loginVersion">' . htmlspecialchars($libelle) . '</span>', $html);
$html = preg_replace('/\?v=[0-9a-zA-Z.]+/', '?v=' . date('YmdHi'), $html);
file_put_contents($racine . '/index.html', $html);
echo "Version : {$avant['version']} → $libelle\n";

// ── 2. Archive ──────────────────────────────────────────────────────────────
$nom = "LARKA_GMAO-$v";
$zipF = "$sortie/$nom.zip";
@unlink($zipF);
$zip = new ZipArchive();
$zip->open($zipF, ZipArchive::CREATE);
$exclus = '#^(\.git/|\.github/|dist/|node_modules/|config\.json$|\.env$|.*\.pem$|.*\.key$)#';
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine, FilesystemIterator::SKIP_DOTS));
$n = 0;
foreach ($it as $f) {
    $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($racine) + 1));
    if (preg_match($exclus, $rel)) continue;
    // data/ : seulement l'ossature (dossiers vides, protections), jamais de données.
    if (str_starts_with($rel, 'data/') && !preg_match('#/(\.gitkeep|\.htaccess)$#', $rel)) continue;
    $zip->addFile($f->getPathname(), "$nom/$rel");
    $n++;
}
$zip->close();
$sha = hash_file('sha256', $zipF);
file_put_contents("$zipF.sha256", "$sha  $nom.zip\n");
echo "Archive : $zipF ($n fichiers, " . round(filesize($zipF) / 1048576, 1) . " Mo)\nSHA-256 : $sha\n";

// ── 3. Signature + manifeste ────────────────────────────────────────────────
$sig = null;
if (is_file($cleF)) {
    $sk = base64_decode(trim((string)file_get_contents($cleF)), true);
    $sig = base64_encode(sodium_crypto_sign_detached($sha, $sk));
    file_put_contents("$zipF.sig", $sig . "\n");
    echo "Signature : $zipF.sig\n";
} else {
    echo "⚠ Pas de clé ($cleF) : version NON signée (refusée par les serveurs qui exigent une signature).\n";
}
$url = isset($opt['url-base']) ? rtrim($opt['url-base'], '/') . "/$nom.zip" : "$nom.zip";
file_put_contents("$sortie/latest.json", json_encode([
    'version' => $v, 'canal' => $canal, 'date' => date('Y-m-d'), 'notes' => $notes,
    'zip' => $url, 'sha256' => $sha, 'signature' => $sig,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
echo "Manifeste : $sortie/latest.json\n\nGitHub :\n  gh release create v$v $zipF $zipF.sha256" . ($sig ? " $zipF.sig" : '')
   . (isset($opt['notes']) ? " --notes-file " . $opt['notes'] : " --notes \"…\"") . (strcasecmp($canal, 'Stable') ? ' --prerelease' : '') . "\n";

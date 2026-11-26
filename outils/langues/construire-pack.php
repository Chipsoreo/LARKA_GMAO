<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
/**
 * Construit un pack de langue à partir du fichier de traduction unique
 * lang/traductions.json (voir outils/langues/traductions.php).
 *
 * Le traducteur travaille dans ce seul fichier : une ligne par libellé, la clé
 * en français (jamais modifiée), une traduction par langue dans la valeur. Le
 * pack (seul fichier à part, puisque c'est lui qu'on installe) en est tiré.
 *
 * Avant 2.1.0, la traduction anglaise vivait directement dans le pack, avec
 * ses propres lignes : 4 309 entrées face à 4 160 au catalogue, 54 libellés
 * absents et 203 orphelins. Impossible de comparer les deux fichiers d'un
 * coup d'œil, et rien ne signalait l'écart.
 *
 *   php outils/langues/construire-pack.php en             construit le pack anglais
 *   php outils/langues/construire-pack.php de             nouvelle langue : l'ajoute à « langues »
 *                                                         du fichier ; on traduit ensuite ligne par
 *                                                         ligne ({"en": "…", "de": "…"})
 *   php outils/langues/construire-pack.php en --version=4.3.2
 *
 * Produit :
 *   extensions/langues/larka.langue-<code>/extension.json
 *   extensions/langues/larka.langue-<code>-<version>.larka_thematique   (l'ancien paquet est retiré)
 *
 * Code de sortie : 1 si des libellés restent à traduire (valeur identique au
 * français et contenant un accent ou un mot outil français).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit("Ligne de commande uniquement.\n"); }

$racine = dirname(__DIR__, 2);
$code = $argv[1] ?? '';
if (!preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $code) || $code === 'fr') {
    fwrite(STDERR, "Usage : php outils/langues/construire-pack.php <code langue> [--version=X.Y.Z]\n");
    exit(2);
}
$version = null;
foreach ($argv as $a) if (preg_match('/^--version=(\d+\.\d+\.\d+)$/', $a, $m)) $version = $m[1];

require __DIR__ . '/traductions.php';
$fichier = trad_lire($racine);
if (!$fichier['libelles']) { fwrite(STDERR, TRAD_CHEMIN . " illisible.\n"); exit(2); }

// 1. Langue nouvelle : déclarée dans le fichier, pour qu'on la traduise ligne par ligne.
$dossier = "$racine/extensions/langues/larka.langue-$code";
$fPack = "$dossier/extension.json";
$pack = is_file($fPack) ? (json_decode((string)file_get_contents($fPack), true) ?: []) : [];
if (!isset($fichier['langues'][$code])) {
    $fichier['langues'][$code] = $pack['nom'] ?? $code;
    trad_ecrire($racine, $fichier);
    printf("✓ langue « %s » ajoutée à %s\n", $code, TRAD_CHEMIN);
}
$trad = ['nom' => $fichier['langues'][$code]];
// Clés du catalogue, dans son ordre ; une ligne pas encore traduite garde le français.
$aligne = trad_langue($fichier, $code);
$orphelins = [];

// 2. Libellés encore en français (valeur identique, accent ou mot outil).
$MOTS_FR = '/\b(le|la|les|un|une|des|du|au|aux|pour|dans|sur|avec|par|sans|cette|ces|ce|qui|que|est|sont|aucun|aucune|votre|vos|nous|vous)\b/iu';
$ACCENT = '/[àâäéèêëîïôöùûüçÀÂÄÉÈÊËÎÏÔÖÙÛÜÇ]/u';
// Noms propres et noms de langue dans leur propre langue (« Português »).
$EXCEPT = '/(Légifrance|Mickaël|télétravail|congé|récup|DM Sans|Português|Română|Español|Français)/u';
$aFaire = [];
foreach ($aligne as $k => $v) {
    if ($v === $k && !preg_match($EXCEPT, $k) && (preg_match($ACCENT, $k) || preg_match($MOTS_FR, $k))) $aFaire[] = $k;
}

// 3. Le pack : en-tête conservé, libellés dans l'ordre du catalogue.
$pack = [
    'identifiant' => $pack['identifiant'] ?? "larka.langue-$code",
    'format'      => 'declaratif/1',
    'nom'         => $pack['nom'] ?? $trad['nom'],
    'version'     => $version ?? ($pack['version'] ?? '1.0.0'),
    'auteur'      => $pack['auteur'] ?? 'Larka',
    'description' => $pack['description'] ?? '',
    'langues'     => [$code => $aligne],
];
@mkdir($dossier, 0775, true);
file_put_contents($fPack, json_encode($pack, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

// 4. Le paquet installable (.larka_thematique) : « mimetype » d'abord, non compressé.
foreach (glob("$racine/extensions/langues/larka.langue-$code-*.larka_thematique") ?: [] as $ancien) @unlink($ancien);
$fZip = "$racine/extensions/langues/larka.langue-$code-{$pack['version']}.larka_thematique";
$zip = new ZipArchive();
if ($zip->open($fZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { fwrite(STDERR, "Impossible d'écrire $fZip\n"); exit(2); }
$zip->addFromString('mimetype', 'application/vnd.larka.extension');
$zip->setCompressionName('mimetype', ZipArchive::CM_STORE);
$zip->addFromString('extension.json', json_encode($pack, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$zip->close();

printf("✓ %s — %d libellés lus (langue « %s »)\n", TRAD_CHEMIN, count($aligne), $code);
printf("✓ %s (version %s)\n✓ %s\n", substr($fPack, strlen($racine) + 1), $pack['version'], substr($fZip, strlen($racine) + 1));
if ($aFaire) {
    printf("⚠ %d libellé(s) encore en français, à traduire (« %s ») dans %s :\n", count($aFaire), $code, TRAD_CHEMIN);
    foreach (array_slice($aFaire, 0, 15) as $k) echo "    $k\n";
    exit(1);
}
exit(0);

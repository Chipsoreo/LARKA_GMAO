<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
/**
 * Construit un pack de langue à partir de lang/<code>.json.
 *
 * lang/<code>.json est le fichier du TRADUCTEUR : mêmes clés, dans le même
 * ordre, que le catalogue lang/fr.json — donc exactement le même nombre de
 * lignes, ligne pour ligne. On traduit les valeurs, jamais les clés.
 *
 * Avant 2.1.0, la traduction anglaise vivait directement dans le pack, avec
 * ses propres lignes : 4 309 entrées face à 4 160 au catalogue, 54 libellés
 * absents et 203 orphelins. Impossible de comparer les deux fichiers d'un
 * coup d'œil, et rien ne signalait l'écart.
 *
 *   php outils/langues/construire-pack.php en             met à jour lang/en.json (nouvelles clés
 *                                                         du catalogue en attente de traduction),
 *                                                         puis construit le pack
 *   php outils/langues/construire-pack.php en --version=4.2.1
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

$cat = json_decode((string)file_get_contents("$racine/lang/fr.json"), true)['libelles'] ?? [];
if (!$cat) { fwrite(STDERR, "lang/fr.json illisible.\n"); exit(2); }
$fTrad = "$racine/lang/$code.json";
$trad = is_file($fTrad) ? (json_decode((string)file_get_contents($fTrad), true) ?: []) : [];
$dico = $trad['libelles'] ?? [];

// 1. Aligner le fichier du traducteur sur le catalogue : mêmes clés, même ordre.
$aligne = [];
foreach ($cat as $k => $_) $aligne[$k] = array_key_exists($k, $dico) ? (string)$dico[$k] : $k;
$orphelins = array_diff_key($dico, $cat);
$trad = [
    'langue'   => $code,
    'nom'      => $trad['nom'] ?? $code,
    'version'  => date('Y-m-d'),
    'libelles' => $aligne,
];
file_put_contents($fTrad, json_encode($trad, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");

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
$dossier = "$racine/extensions/langues/larka.langue-$code";
$fPack = "$dossier/extension.json";
$pack = is_file($fPack) ? (json_decode((string)file_get_contents($fPack), true) ?: []) : [];
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

printf("✓ lang/%s.json — %d libellés, alignés sur le catalogue (%d lignes, comme lang/fr.json)\n",
    $code, count($aligne), count(file($fTrad)));
if ($orphelins) printf("  %d libellé(s) retiré(s) : absents du catalogue\n", count($orphelins));
printf("✓ %s (version %s)\n✓ %s\n", substr($fPack, strlen($racine) + 1), $pack['version'], substr($fZip, strlen($racine) + 1));
if ($aFaire) {
    printf("⚠ %d libellé(s) encore en français, à traduire dans lang/%s.json :\n", count($aFaire), $code);
    foreach (array_slice($aFaire, 0, 15) as $k) echo "    $k\n";
    exit(1);
}
exit(0);

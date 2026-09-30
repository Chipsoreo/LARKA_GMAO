<?php
/**
 * Bump des paramètres « ?v= » d'index.html.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Ces paramètres existent pour que le navigateur reprenne les fichiers modifiés
 * au lieu de servir sa copie en cache. Encore faut-il les changer.
 *
 * Ils sont restés figés au 4 août pendant des semaines : des corrections
 * livrées n'atteignaient jamais les postes, et l'on croyait le code inchangé
 * alors qu'il ne l'était que dans le navigateur. Le défaut est invisible depuis
 * le serveur — c'est ce qui le rend coûteux.
 *
 * Ce script remplace l'effort de mémoire. À lancer avant toute livraison ;
 * « start.sh prod » l'appelle.
 *
 * Usage : php outils/bump-version.php [--verifier]
 *   --verifier : ne modifie rien, signale seulement si un fichier est plus
 *                récent que la version affichée (code de sortie 1).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI uniquement.\n"); }

$racine = dirname(__DIR__);
$index  = $racine . '/index.html';
if (!is_file($index)) { fwrite(STDERR, "index.html introuvable.\n"); exit(2); }

$html = file_get_contents($index);
if (!preg_match('/\?v=(\d+)/', $html, $m)) {
    fwrite(STDERR, "Aucun paramètre « ?v= » dans index.html.\n"); exit(2);
}
$actuelle = $m[1];

// Le plus récent des fichiers servis avec un « ?v= ».
$plusRecent = 0; $coupable = '';
preg_match_all('/(?:src|href)="([^"?]+)\?v=\d+"/', $html, $refs);
foreach ($refs[1] as $rel) {
    $abs = $racine . '/' . $rel;
    if (!is_file($abs)) continue;
    $t = filemtime($abs);
    if ($t > $plusRecent) { $plusRecent = $t; $coupable = $rel; }
}
$attendue = date('YmdHi', $plusRecent ?: time());

/**
 * Versions figées ailleurs que dans index.html.
 *
 * Un fichier chargé à la demande — « js/superadmin-auth.js?v=11 » — n'apparaît
 * pas dans index.html : le bump ne le voyait pas, et sa version n'avait pas
 * bougé depuis des mois. Les corrections restaient dans le cache, sans que rien
 * ne le signale.
 *
 * On refuse désormais un numéro écrit en dur : il doit se déduire de la version
 * de l'application.
 */
$figees = [];
foreach (array_merge(glob($racine . '/js/*.js'), glob($racine . '/js/pages/*.js')) as $f) {
    if (preg_match_all('/[\'"]([^\'"]+\.(?:js|css)\?v=\d+)[\'"]/', file_get_contents($f), $mm)) {
        foreach ($mm[1] as $ref) $figees[] = basename($f) . ' → ' . $ref;
    }
}
if ($figees !== []) {
    fwrite(STDERR, "  ✗ version(s) figée(s) dans le code, invisibles pour ce script :\n");
    foreach ($figees as $x) fwrite(STDERR, "      $x\n");
    fwrite(STDERR, "    Déduisez-la de la version de l'application.\n");
    exit(1);
}

$verifier = in_array('--verifier', $argv, true);
if ($actuelle >= $attendue) {
    echo "  ✓ version à jour ($actuelle)\n";
    exit(0);
}
if ($verifier) {
    fwrite(STDERR, "  ✗ version périmée : $actuelle, alors que « $coupable » date de $attendue.\n"
        . "    Le navigateur servira sa copie en cache. → php outils/bump-version.php\n");
    exit(1);
}

$html = preg_replace('/\?v=\d+/', '?v=' . $attendue, $html, -1, $n);
file_put_contents($index, $html);
echo "  ✓ $n ressource(s) : $actuelle → $attendue (d'après « $coupable »)\n";

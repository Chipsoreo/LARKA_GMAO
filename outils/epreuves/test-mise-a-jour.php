<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
/**
 * Larka — les mises à jour n'installent-elles que ce que l'éditeur a signé ?
 *
 * Le code téléchargé par api/MiseAJour.php s'exécute ensuite sur le serveur :
 * c'est la porte la plus large de l'application. Cette épreuve vérifie, sur
 * une installation jetable et des archives fabriquées pour l'occasion (aucun
 * réseau : les téléchargements sont servis depuis le disque) :
 *   - qu'une version signée par la clé embarquée s'installe, et qu'aucune
 *     autre ne s'installe (archive retouchée, non signée, autre clé — même
 *     désignée par l'ancien réglage config.json —, serveur sans clé) ;
 *   - que la rotation de clé passe par une version de transition ;
 *   - qu'une restauration ne remet jamais l'outil de mise à jour ni la clé,
 *     et que l'interface ne peut pas revenir à une version d'avant la
 *     signature obligatoire.
 *
 *   php outils/epreuves/test-mise-a-jour.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit("Ligne de commande uniquement.\n"); }

$racine = dirname(__DIR__, 2);
foreach (['sodium_crypto_sign_keypair' => 'sodium', 'curl_init' => 'curl'] as $fn => $ext) {
    if (!function_exists($fn)) { echo "  ⏭️  extension PHP « $ext » absente : épreuve sautée.\n"; exit(0); }
}
if (!class_exists('ZipArchive')) { echo "  ⏭️  extension PHP « zip » absente : épreuve sautée.\n"; exit(0); }

// ── Installation jetable ────────────────────────────────────────────────────
$tmp = sys_get_temp_dir() . '/larka-epreuve-maj-' . bin2hex(random_bytes(4));
$srv = "$tmp/serveur";
@mkdir("$srv/api/routes", 0700, true); @mkdir("$srv/api/outils", 0700, true); @mkdir("$tmp/dist", 0700, true);
register_shutdown_function(function () use ($tmp) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    @rmdir($tmp);
});
$k1 = sodium_crypto_sign_keypair(); $k2 = sodium_crypto_sign_keypair(); $k3 = sodium_crypto_sign_keypair();
$pub = fn($kp) => base64_encode(sodium_crypto_sign_publickey($kp));
$fichierCle = fn(string $b64) => "<?php\nreturn '$b64';\n";
$ecrire = function (string $f, string $c) { @mkdir(dirname($f), 0700, true); file_put_contents($f, $c); };
$ecrire("$srv/api/MiseAJour.php", (string)file_get_contents("$racine/api/MiseAJour.php"));
$ecrire("$srv/api/Version.php", (string)file_get_contents("$racine/api/Version.php"));
$ecrire("$srv/api/CleEditeur.php", $fichierCle($pub($k1)));
$ecrire("$srv/api/routes/majs.php", "<?php // majs en place\n");
$ecrire("$srv/api/outils/mise-a-jour.php", "<?php // outil en place\n");
$ecrire("$srv/api/index.php", "<?php // index\n");
$ecrire("$srv/index.html", "<!doctype html>en place");
$ecrire("$srv/version.json", json_encode(['version' => '2.0.3', 'canal' => 'Beta', 'php_min' => '8.1']));

$CFG = ['mises_a_jour' => ['source' => 'manifeste', 'url_manifeste' => 'https://maj.epreuve/latest.json']];
if (!function_exists('cfg')) {
    function cfg(string $section, ?string $cle = null) { global $CFG; $v = $CFG[$section] ?? null; return $cle === null ? $v : ($v[$cle] ?? null); }
}
require "$srv/api/MiseAJour.php";

final class MajEpreuve extends LarkaMiseAJour {
    public array $fichiers = [];
    public array $demandes = [];
    protected function telecharger(string $url, ?string $dest, int $max): string {
        $this->demandes[] = $url;
        $local = $this->fichiers[$url] ?? throw new RuntimeException("URL inconnue de l'épreuve : $url");
        if ($dest) { copy($local, $dest); return $dest; }
        return (string)file_get_contents($local);
    }
}

/** Archive de version : 12 fichiers, clé embarquée donnée. */
function archive(string $tmp, string $version, string $cleB64, array $extra = []): string {
    $f = "$tmp/dist/LARKA_GMAO-$version-" . bin2hex(random_bytes(3)) . '.zip';
    $z = new ZipArchive(); $z->open($f, ZipArchive::CREATE);
    $p = "LARKA_GMAO-$version/";
    $z->addFromString($p . 'index.html', "<!doctype html>$version");
    $z->addFromString($p . 'api/index.php', "<?php // $version\n");
    $z->addFromString($p . 'api/CleEditeur.php', "<?php\nreturn '$cleB64';\n");
    $z->addFromString($p . 'version.json', json_encode(['version' => $version, 'canal' => 'Beta', 'php_min' => '8.1']));
    for ($i = 1; $i <= 8; $i++) $z->addFromString($p . "js/f$i.js", "// $version $i\n");
    foreach ($extra as $n => $c) $z->addFromString($p . $n, $c);
    $z->close();
    return $f;
}

$ok = 0; $ko = [];
function verifier(string $nom, bool $vrai, string $detail, int &$ok, array &$ko): void {
    if ($vrai) { $ok++; printf("  ✅  %-58s %s\n", $nom, $detail); }
    else { $ko[] = "$nom : $detail"; printf("  ❌  %-58s %s\n", $nom, $detail); }
}
/** Tente l'installation de $zip annoncé avec $sig ; rend [installée ?, message]. */
function installer(string $srv, string $tmp, string $version, string $zip, ?string $sig, ?string $sha = null): array {
    $m = new MajEpreuve($srv);
    $sha ??= hash_file('sha256', $zip);
    file_put_contents("$tmp/dist/latest.json", json_encode(['version' => $version, 'zip' => 'https://maj.epreuve/a.zip', 'sha256' => $sha, 'signature' => $sig]));
    $m->fichiers = ['https://maj.epreuve/latest.json' => "$tmp/dist/latest.json", 'https://maj.epreuve/a.zip' => $zip];
    try { $m->verifier(); $m->installer($version, 'epreuve'); return [true, 'installée', $m]; }
    catch (Throwable $e) { return [false, $e->getMessage(), $m]; }
}
$signer = fn(string $zip, $kp) => base64_encode(sodium_crypto_sign_detached(hash_file('sha256', $zip), sodium_crypto_sign_secretkey($kp)));
$version = fn() => json_decode((string)file_get_contents("$srv/version.json"), true)['version'] ?? '?';

echo "\n═══════════════════════════════════════════════════════════════════════\n";
echo " Larka — les mises à jour n'installent-elles que du code signé ?\n";
echo "═══════════════════════════════════════════════════════════════════════\n\n";

echo "  Clé de l'éditeur (api/CleEditeur.php)\n";
foreach (['vide' => '', 'base64 invalide' => 'pas*du*base64', 'longueur fausse' => base64_encode(random_bytes(31))] as $cas => $v) {
    $f = "$tmp/cle-$cas.php"; file_put_contents($f, $fichierCle($v));
    verifier("Clé $cas : refusée", LarkaMiseAJour::clePubliqueEditeur($f) === null, 'aucune clé retenue', $ok, $ko);
}
verifier('Clé valide : lue', LarkaMiseAJour::clePubliqueEditeur("$srv/api/CleEditeur.php") === sodium_crypto_sign_publickey($k1), '32 octets', $ok, $ko);

echo "\n  Installation\n";
$z = archive($tmp, '2.0.4', $pub($k1));
$retouchee = "$tmp/dist/retouchee.zip"; copy($z, $retouchee);
$zz = new ZipArchive(); $zz->open($retouchee); $zz->addFromString('LARKA_GMAO-2.0.4/js/ajout.js', '// ajouté après signature'); $zz->close();
[$r, $msg] = installer($srv, $tmp, '2.0.4', $retouchee, $signer($z, $k1));
verifier('Archive retouchée après signature : refusée', !$r && !is_file("$srv/js/ajout.js"), $r ? 'INSTALLÉE' : 'rien écrit', $ok, $ko);
[$r] = installer($srv, $tmp, '2.0.4', $z, null);
verifier('Version non signée : refusée', !$r && $version() === '2.0.3', $r ? 'INSTALLÉE' : 'refusée', $ok, $ko);
$z3 = archive($tmp, '2.0.4', $pub($k3));
$CFG['mises_a_jour']['cle_publique'] = $pub($k3);   // ancien réglage : ne doit plus rien désigner
[$r] = installer($srv, $tmp, '2.0.4', $z3, $signer($z3, $k3));
unset($CFG['mises_a_jour']['cle_publique']);
verifier('Autre clé, même désignée dans config.json : refusée', !$r && $version() === '2.0.3', $r ? 'INSTALLÉE' : 'refusée', $ok, $ko);
file_put_contents("$srv/api/CleEditeur.php", $fichierCle(''));
[$r, , $m] = installer($srv, $tmp, '2.0.4', $z, $signer($z, $k1));
$archiveDemandee = in_array('https://maj.epreuve/a.zip', $m->demandes, true);
file_put_contents("$srv/api/CleEditeur.php", $fichierCle($pub($k1)));
verifier('Serveur sans clé : refusée avant tout téléchargement', !$r && !$archiveDemandee, $archiveDemandee ? 'archive téléchargée' : 'refusée', $ok, $ko);
[$r, $msg] = installer($srv, $tmp, '2.0.4', $z, $signer($z, $k1));
verifier('Version signée par la clé embarquée : installée', $r && $version() === '2.0.4', $r ? 'version 2.0.4' : $msg, $ok, $ko);

echo "\n  Rotation de clé\n";
$z5 = archive($tmp, '2.0.5', $pub($k2));
[$r] = installer($srv, $tmp, '2.0.5', $z5, $signer($z5, $k2));
verifier('Signée par la nouvelle clé AVANT la transition : refusée', !$r, $r ? 'INSTALLÉE' : 'refusée', $ok, $ko);
[$r, $msg] = installer($srv, $tmp, '2.0.5', $z5, $signer($z5, $k1));
verifier('Transition (ancienne clé, embarque la nouvelle) : installée', $r && LarkaMiseAJour::clePubliqueEditeur("$srv/api/CleEditeur.php") === sodium_crypto_sign_publickey($k2), $r ? 'clé du serveur remplacée' : $msg, $ok, $ko);
$z6 = archive($tmp, '2.0.6', $pub($k2));
[$r] = installer($srv, $tmp, '2.0.6', $z6, $signer($z6, $k1));
verifier('Après transition, l\'ancienne clé ne signe plus rien', !$r, $r ? 'INSTALLÉE' : 'refusée', $ok, $ko);
[$r, $msg] = installer($srv, $tmp, '2.0.6', $z6, $signer($z6, $k2));
verifier('Après transition, la nouvelle clé : installée', $r && $version() === '2.0.6', $r ? 'version 2.0.6' : $msg, $ok, $ko);

echo "\n  Serveur en retard d'une rotation (il a encore l'ancienne clé)\n";
// La plus récente (2.0.6) est signée avec la nouvelle clé ; la transition
// (2.0.5) reste publiée. Le serveur doit se voir proposer la transition.
$t5 = archive($tmp, '2.0.5', $pub($k2));
$sigT5 = $signer($t5, $k1);
$sigZ6 = $signer($z6, $k2);
$enRetard = function () use ($srv, $fichierCle, $pub, $k1) {
    file_put_contents("$srv/version.json", json_encode(['version' => '2.0.4', 'canal' => 'Beta', 'php_min' => '8.1']));
    file_put_contents("$srv/api/CleEditeur.php", $fichierCle($pub($k1)));
};
$monter = function (array $fichiers) use ($srv) {
    $m = new MajEpreuve($srv); $m->fichiers = $fichiers;
    $e = $m->verifier();
    $propose = $e['distante']['version'] ?? '?';
    try { $m->installer($propose, 'epreuve'); return [$propose, true, '']; }
    catch (Throwable $x) { return [$propose, false, $x->getMessage()]; }
};
// a) source « manifeste » : les versions précédentes sont dans « anterieures »
$enRetard();
file_put_contents("$tmp/dist/latest-multi.json", json_encode([
    'version' => '2.0.6', 'zip' => 'https://maj.epreuve/2.0.6.zip', 'sha256' => hash_file('sha256', $z6), 'signature' => $sigZ6,
    'anterieures' => [['version' => '2.0.5', 'zip' => 'https://maj.epreuve/2.0.5.zip', 'sha256' => hash_file('sha256', $t5), 'signature' => $sigT5]],
]));
$fm = ['https://maj.epreuve/latest.json' => "$tmp/dist/latest-multi.json", 'https://maj.epreuve/2.0.6.zip' => $z6, 'https://maj.epreuve/2.0.5.zip' => $t5];
[$p1, $r1, $msg1] = $monter($fm);
[$p2, $r2, $msg2] = $monter($fm);
verifier('Manifeste : la transition est proposée d\'abord, puis la suivante', $p1 === '2.0.5' && $r1 && $p2 === '2.0.6' && $r2 && $version() === '2.0.6',
    "proposées : $p1 puis $p2" . ($msg1 . $msg2 !== '' ? " ($msg1$msg2)" : ''), $ok, $ko);
// b) source GitHub (par défaut)
$enRetard();
$CFG['mises_a_jour'] = ['source' => 'github', 'depot' => 'editeur/larka', 'canal' => 'beta'];
$rel = fn(string $v, string $zip, string $sig) => ['tag_name' => "v$v", 'name' => "Larka $v", 'prerelease' => true, 'draft' => false,
    'published_at' => '2026-10-01T00:00:00Z', 'body' => '', 'html_url' => '', 'assets' => [
        ['name' => "LARKA_GMAO-$v.zip", 'browser_download_url' => "https://gh.epreuve/$v.zip", 'size' => filesize($zip)],
        ['name' => "LARKA_GMAO-$v.zip.sha256", 'browser_download_url' => "https://gh.epreuve/$v.sha256"],
        ['name' => "LARKA_GMAO-$v.zip.sig", 'browser_download_url' => "https://gh.epreuve/$v.sig"]]];
file_put_contents("$tmp/dist/gh.json", json_encode([$rel('2.0.6', $z6, $sigZ6), $rel('2.0.5', $t5, $sigT5)]));
foreach (['2.0.6' => [$z6, $sigZ6], '2.0.5' => [$t5, $sigT5]] as $v => [$zf, $sg]) {
    file_put_contents("$tmp/dist/$v.sha256", hash_file('sha256', $zf) . "  LARKA_GMAO-$v.zip\n");
    file_put_contents("$tmp/dist/$v.sig", $sg . "\n");
}
$fg = ['https://api.github.com/repos/editeur/larka/releases?per_page=15' => "$tmp/dist/gh.json"];
foreach (['2.0.6', '2.0.5'] as $v) {
    $fg["https://gh.epreuve/$v.zip"] = $v === '2.0.6' ? $z6 : $t5;
    $fg["https://gh.epreuve/$v.sha256"] = "$tmp/dist/$v.sha256";
    $fg["https://gh.epreuve/$v.sig"] = "$tmp/dist/$v.sig";
}
[$p1, $r1, $msg1] = $monter($fg);
[$p2, $r2, $msg2] = $monter($fg);
verifier('GitHub : la transition est proposée d\'abord, puis la suivante', $p1 === '2.0.5' && $r1 && $p2 === '2.0.6' && $r2 && $version() === '2.0.6',
    "proposées : $p1 puis $p2" . ($msg1 . $msg2 !== '' ? " ($msg1$msg2)" : ''), $ok, $ko);
$CFG['mises_a_jour'] = ['source' => 'manifeste', 'url_manifeste' => 'https://maj.epreuve/latest.json'];

echo "\n  Retour arrière\n";
$m = new MajEpreuve($srv);
$sauvegarde = function (string $id, ?array $meta, array $fichiers) use ($srv) {
    $zz = new ZipArchive(); $zz->open("$srv/data/maj/sauvegardes/$id.zip", ZipArchive::CREATE);
    if ($meta !== null) $zz->addFromString('.larka-sauvegarde.json', json_encode(['id' => $id] + $meta));
    foreach ($fichiers as $n => $c) $zz->addFromString($n, $c);
    $zz->close();
};
$gardes = ['api/MiseAJour.php', 'api/CleEditeur.php', 'api/routes/majs.php', 'api/outils/mise-a-jour.php'];
$chaine = fn() => array_map(fn($f) => (string)file_get_contents("$srv/$f"), $gardes);
$autreChaine = ['api/MiseAJour.php' => "<?php // autre outil\n", 'api/CleEditeur.php' => $fichierCle($pub($k3)),
                'api/routes/majs.php' => "<?php // autre route\n", 'api/outils/mise-a-jour.php' => "<?php // autre outil cli\n"];
$restaurer = function (string $id, bool $cli = false) use ($m) {
    try { $m->restaurer($id, 'epreuve', $cli); return true; } catch (Throwable $e) { return false; }
};
// Sauvegarde « d'avant » : fichiers d'une version antérieure à la signature
// obligatoire, outil de mise à jour et clé compris.
$ancienne = '20200101-000000_2.0.2_vers_2.0.3';
$sauvegarde($ancienne, ['version' => ['version' => '2.0.2']], $autreChaine + ['index.html' => '<!doctype html>2.0.2', 'version.json' => json_encode(['version' => '2.0.2'])]);
$liste = array_column($m->historique()['sauvegardes'], 'restaurable', 'id');
$recentes = array_filter($liste, fn($v, $id) => $id !== $ancienne, ARRAY_FILTER_USE_BOTH);
verifier('Historique : l\'ancienne sauvegarde n\'est pas restaurable d\'ici', ($liste[$ancienne] ?? null) === false, 'restaurable=false', $ok, $ko);
verifier('Historique : les sauvegardes récentes le sont', $recentes !== [] && !in_array(false, $recentes, true), count($recentes) . ' sauvegarde(s)', $ok, $ko);
verifier('Ancienne sauvegarde depuis l\'interface : refusée', !$restaurer($ancienne) && $version() === '2.0.6', 'refusée', $ok, $ko);
$sansMeta = '20200101-000001_sans_meta';
$sauvegarde($sansMeta, null, ['index.html' => '<!doctype html>sans meta']);
verifier('Sauvegarde sans métadonnées depuis l\'interface : refusée', !$restaurer($sansMeta), 'refusée', $ok, $ko);
$sousBase = '20200101-000002_2.0.3_vers_2.0.4';
$sauvegarde($sousBase, ['version' => ['version' => '2.0.3']], ['index.html' => '<!doctype html>2.0.3']);
$base = (string)file_get_contents("$srv/data/maj/signature-depuis.json");
file_put_contents("$srv/data/maj/signature-depuis.json", json_encode(['version' => '2.0.5']));   // serveur installé en 2.0.5
$refus = !$restaurer($sousBase);
file_put_contents("$srv/data/maj/signature-depuis.json", $base);
verifier('Sauvegarde plus ancienne que la base du serveur : refusée', $refus, 'refusée', $ok, $ko);
$recente = '20200101-000003_2.0.5_vers_2.0.6';
$sauvegarde($recente, ['version' => ['version' => '2.0.5']], $autreChaine + ['index.html' => '<!doctype html>2.0.5 restaurée', 'version.json' => json_encode(['version' => '2.0.5'])]);
$avant = $chaine();
$r = $restaurer($recente);
verifier('Sauvegarde récente depuis l\'interface : restaurée…', $r && $version() === '2.0.5' && str_contains((string)file_get_contents("$srv/index.html"), 'restaurée'), $r ? 'version 2.0.5' : 'REFUSÉE', $ok, $ko);
verifier('… sans l\'outil de mise à jour ni la clé', $avant === $chaine(), 'chaîne de confiance intacte', $ok, $ko);
$avant = $chaine();
$r = $restaurer($ancienne, true);
verifier('Ancienne sauvegarde en ligne de commande : restaurée…', $r && $version() === '2.0.2' && str_contains((string)file_get_contents("$srv/index.html"), '2.0.2'), $r ? 'version 2.0.2' : 'REFUSÉE', $ok, $ko);
verifier('… sans l\'outil de mise à jour ni la clé', $avant === $chaine(), implode(', ', array_map('basename', $gardes)) . ' intacts', $ok, $ko);
$neuf = "$tmp/arbre-non-publie";
@mkdir("$neuf/data/maj", 0700, true);
file_put_contents("$neuf/version.json", json_encode(['version' => '2.0.2']));
new MajEpreuve($neuf);
verifier('Arbre encore numéroté 2.0.2 : aucune base de signature notée', !is_file("$neuf/data/maj/signature-depuis.json"), 'rien de noté', $ok, $ko);

echo "\n  Installation interrompue\n";
// Une écriture échoue en fin d'installation (un fichier occupe la place d'un
// dossier). Tout doit revenir à l'état d'avant — y compris la clé, que cette
// archive (une transition) remplaçait — et l'erreur d'origine être rapportée.
@mkdir("$srv/js", 0700, true); file_put_contents("$srv/js/bloque", 'fichier');
$z8 = archive($tmp, '2.0.8', $pub($k3), ['js/bloque/x.js' => '// ne peut pas être écrit']);
$etatAvant = array_map(fn($f) => (string)file_get_contents("$srv/$f"), ['version.json', 'index.html', 'api/CleEditeur.php', 'js/f1.js']);
[$r, $msg] = installer($srv, $tmp, '2.0.8', $z8, $signer($z8, $k2));
$etatApres = array_map(fn($f) => (string)file_get_contents("$srv/$f"), ['version.json', 'index.html', 'api/CleEditeur.php', 'js/f1.js']);
@unlink("$srv/js/bloque");
verifier('Écriture impossible : refusée, état d\'avant rétabli (clé comprise)', !$r && $etatAvant === $etatApres && $version() === '2.0.2',
    $r ? 'INSTALLÉE' : ($etatAvant === $etatApres ? 'rétabli' : 'NON RÉTABLI'), $ok, $ko);
verifier('… et la cause réelle est rapportée', !$r && str_contains($msg, 'restaurée') && str_contains($msg, 'impossible'), mb_substr($msg, 0, 60) . '…', $ok, $ko);
$z7 = archive($tmp, '2.0.7', $pub($k2));
[$r] = installer($srv, $tmp, '2.0.7', $z7, $signer($z7, $k3));
verifier('Après ces retours, une autre clé ne vaut toujours rien', !$r, $r ? 'INSTALLÉE' : 'refusée', $ok, $ko);

$total = $ok + count($ko);
echo "\n───────────────────────────────────────────────────────────────────────\n";
if ($ko === []) {
    printf(" ✅ %d/%d — seules les versions signées par l'éditeur s'installent.\n", $ok, $total);
} else {
    printf(" ❌ %d échec(s) sur %d :\n", count($ko), $total);
    foreach ($ko as $e) echo "      • $e\n";
}
echo "───────────────────────────────────────────────────────────────────────\n\n";
exit($ko === [] ? 0 : 1);

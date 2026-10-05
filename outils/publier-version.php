<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
/**
 * Larka — Publier une nouvelle version (côté ÉDITEUR, pas sur les serveurs).
 *
 * 1) Une seule fois : générer la paire de clés de signature
 *      php outils/publier-version.php --generer-cles [--cle ~/.larka-publication.key]
 *    → la clé PRIVÉE reste chez vous (chmod 600, jamais dans le dépôt) ;
 *    → la clé PUBLIQUE est écrite dans api/CleEditeur.php : elle part AVEC le
 *      code, et c'est elle que chaque serveur utilise pour vérifier les
 *      mises à jour. (Elle n'est plus lue dans config.json : un réglage
 *      modifiable depuis l'interface ne doit pas décider qui peut envoyer du
 *      code au serveur.)
 *    Vous avez déjà une clé privée ? Écrire seulement la clé publique :
 *      php outils/publier-version.php --ecrire-cle-publique [--cle …]
 *    Une clé déjà inscrite n'est JAMAIS remplacée sans --remplacer-cle (voir 4).
 *
 * 2) À chaque version :
 *      php outils/publier-version.php --version 2.0.3 [--canal Beta|Stable]
 *            [--notes notes.md] [--cle ~/.larka-publication.key] [--url-base https://…/]
 *            [--reconstruire]   (republier la version déjà inscrite dans version.json)
 *    → REFUSE de construire si la clé privée manque, ou si elle ne correspond
 *      pas à la clé publique embarquée : les serveurs refuseraient la version ;
 *    → met à jour version.json, la version affichée sur la page de connexion
 *      et les « ?v= » d'index.html (cache navigateur) ;
 *    → construit dist/LARKA_GMAO-<version>.zip (sans data/, config.json, .env) ;
 *    → écrit <zip>.sha256, <zip>.sig et latest.json (source « manifeste »),
 *      puis revérifie la signature et la clé embarquée dans l'archive.
 *    --sans-signature : archive de TEST uniquement (aucun serveur ne l'acceptera).
 *
 * 3) Publier, par exemple sur GitHub :
 *      gh release create v2.0.3 dist/LARKA_GMAO-2.0.3.zip dist/LARKA_GMAO-2.0.3.zip.sha256 \
 *         dist/LARKA_GMAO-2.0.3.zip.sig --notes-file notes.md [--prerelease]
 *    Les serveurs la détectent seuls (au plus tard sous 12 h) et la proposent
 *    à l'administrateur, qui valide l'installation.
 *
 * 4) Changer de clé (clé compromise, ou perdue avant qu'il soit trop tard) :
 *    un serveur vérifie une version avec la clé de la version qu'IL a déjà
 *    installée, pas avec celle que la nouvelle apporte. D'où une version de
 *    transition, signée avec l'ANCIENNE clé et qui embarque la NOUVELLE :
 *      php outils/publier-version.php --generer-cles --remplacer-cle --cle ~/.larka-publication-2.key
 *      php outils/publier-version.php --version X.Y.Z --cle ~/.larka-publication-2.key \
 *            --signer-avec ~/.larka-publication.key
 *    --remplacer-cle note dans api/CleEditeur.php la clé qu'ont les serveurs
 *    (« // transition-en-attente: … ») : tant que la transition n'est pas
 *    construite, l'outil refuse toute version normale. Les suivantes se
 *    publient ensuite normalement avec la nouvelle clé (--cle …-2.key).
 *    Publier la transition AVANT toute version suivante, et la laisser en ligne :
 *    un serveur en retard la trouve parmi les versions publiées (les 15
 *    dernières sur GitHub, « anterieures » dans latest.json) et passe par elle.
 *    Garder l'ancienne clé privée tant que des serveurs ne l'ont pas installée.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$racine = dirname(__DIR__);
$opt = getopt('', ['generer-cles', 'ecrire-cle-publique', 'remplacer-cle', 'signer-avec:', 'sans-signature',
                    'version:', 'canal:', 'notes:', 'cle:', 'url-base:', 'sortie:', 'reconstruire', 'php-min:']);
$cleF = $opt['cle'] ?? (getenv('HOME') ?: '.') . '/.larka-publication.key';
$cleEditeurF = $racine . '/api/CleEditeur.php';
require_once $racine . '/api/MiseAJour.php';

if (!function_exists('sodium_crypto_sign_keypair')) {
    fwrite(STDERR, "Extension PHP « sodium » requise pour signer les versions.\n"); exit(1);
}

/** Lit la clé privée de signature (64 octets) ou null. */
$lireClePrivee = static function (string $f): ?string {
    if (!is_file($f)) return null;
    $sk = base64_decode(trim((string)file_get_contents($f)), true);
    return ($sk !== false && strlen($sk) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) ? $sk : null;
};

/** Empreinte courte d'une clé publique, pour que l'éditeur reconnaisse ses clés. */
$empreinte = static fn(string $pk): string => implode(':', str_split(substr(hash('sha256', $pk), 0, 16), 4));

/**
 * Transition de clé en attente : la clé publique qu'ont encore les serveurs,
 * notée en commentaire dans api/CleEditeur.php (« // transition-en-attente: … »)
 * par --remplacer-cle, effacée quand la version de transition est construite.
 * ⚠️ Sans elle, rien n'empêchait, après --remplacer-cle, de construire une
 * version normale signée avec la nouvelle clé — refusée par tous les serveurs.
 */
$lireTransition = static function () use ($cleEditeurF): ?string {
    if (!preg_match('#^// transition-en-attente: ([A-Za-z0-9+/=]+)\s*$#m', (string)@file_get_contents($cleEditeurF), $m)) return null;
    $pk = base64_decode($m[1], true);
    return ($pk !== false && strlen($pk) === 32) ? $pk : null;
};
$ecrireTransition = static function (?string $pk) use ($cleEditeurF): void {
    $src = (string)preg_replace('#^// transition-en-attente: .*\R?#m', '', (string)file_get_contents($cleEditeurF));
    if ($pk !== null) $src = (string)preg_replace("/^return\\s+'/m", "// transition-en-attente: " . base64_encode($pk) . "\nreturn '", $src, 1);
    file_put_contents($cleEditeurF, $src);
};

/**
 * ⚠️ « --generer-cles --cle <autre fichier> » REMPLAÇAIT EN SILENCE la clé déjà
 * inscrite : la version suivante, signée avec la nouvelle clé, était refusée
 * par tous les serveurs (ils vérifient avec la clé qu'ils ont). Remplacer une
 * clé valide exige désormais --remplacer-cle, et mène à une version de
 * transition (voir 4 en tête de fichier).
 */
$garderCleInscrite = static function (string $pkNouvelle) use ($cleEditeurF, $opt, $empreinte): ?string {
    $pkActuelle = LarkaMiseAJour::clePubliqueEditeur($cleEditeurF);
    if ($pkActuelle === null || hash_equals($pkActuelle, $pkNouvelle)) return null;
    if (!isset($opt['remplacer-cle'])) {
        fwrite(STDERR, "api/CleEditeur.php contient déjà une autre clé (empreinte {$empreinte($pkActuelle)}).\n"
            . "  Les serveurs vérifient avec CETTE clé : la remplacer leur fera refuser les versions signées\n"
            . "  par la nouvelle. Pour changer de clé (voir l'en-tête de cet outil, point 4) : --remplacer-cle,\n"
            . "  puis publier la version suivante avec --signer-avec <ancienne clé privée>.\n");
        exit(1);
    }
    echo "⚠ Clé remplacée (ancienne : {$empreinte($pkActuelle)}). La PROCHAINE version doit être publiée avec\n"
       . "  --signer-avec <ancienne clé privée> : l'outil refusera toute autre version d'ici là.\n"
       . "  Gardez l'ancienne clé privée tant que tous les serveurs n'ont pas installé cette version.\n";
    return $pkActuelle;
};
/** Après --remplacer-cle : note la clé des serveurs (la toute première, si une transition attend déjà). */
$noterTransition = static function (?string $pkAncienne) use ($lireTransition, $ecrireTransition): void {
    if ($pkAncienne !== null && $lireTransition() === null) $ecrireTransition($pkAncienne);
};

/** Écrit la clé publique (base64) dans api/CleEditeur.php, en ne touchant qu'à la ligne « return ». */
$ecrireClePublique = static function (string $pkB64) use ($cleEditeurF): void {
    $src = (string)@file_get_contents($cleEditeurF);
    if ($src === '' || !preg_match("/^return\\s+'[^']*';\\s*$/m", $src)) {
        fwrite(STDERR, "api/CleEditeur.php introuvable ou inattendu : écrivez-y « return '$pkB64'; ».\n"); exit(1);
    }
    file_put_contents($cleEditeurF, preg_replace("/^return\\s+'[^']*';\\s*$/m", "return '$pkB64';", $src));
};

if (isset($opt['generer-cles'])) {
    if (is_file($cleF)) { fwrite(STDERR, "La clé $cleF existe déjà (on n'écrase pas une clé de signature).\n"
        . "Pour seulement l'inscrire dans le code : --ecrire-cle-publique\n"); exit(1); }
    $kp = sodium_crypto_sign_keypair();
    $pkAncienne = $garderCleInscrite(sodium_crypto_sign_publickey($kp));   // avant d'écrire quoi que ce soit
    file_put_contents($cleF, base64_encode(sodium_crypto_sign_secretkey($kp)));
    chmod($cleF, 0600);
    $pkB64 = base64_encode(sodium_crypto_sign_publickey($kp));
    $ecrireClePublique($pkB64);
    $noterTransition($pkAncienne);
    echo "Clé privée : $cleF  (à sauvegarder en lieu sûr, hors ligne, jamais dans le dépôt)\n";
    echo "Clé publique inscrite dans api/CleEditeur.php (empreinte {$empreinte(sodium_crypto_sign_publickey($kp))}) :\n$pkB64\n";
    echo "⚠ Perdre la clé privée = ne plus pouvoir publier de mise à jour installable par l'interface.\n";
    exit(0);
}

if (isset($opt['ecrire-cle-publique'])) {
    $sk = $lireClePrivee($cleF);
    if ($sk === null) { fwrite(STDERR, "Clé privée absente ou illisible : $cleF\n"); exit(1); }
    $pk = sodium_crypto_sign_publickey_from_secretkey($sk);
    $pkAncienne = $garderCleInscrite($pk);
    $pkB64 = base64_encode($pk);
    $ecrireClePublique($pkB64);
    $noterTransition($pkAncienne);
    echo "Clé publique inscrite dans api/CleEditeur.php (empreinte {$empreinte($pk)}) :\n$pkB64\n";
    exit(0);
}

$v = (string)($opt['version'] ?? '');
if (!preg_match('/^\d+\.\d+\.\d+$/', $v)) { fwrite(STDERR, "Usage : --version X.Y.Z [--canal Beta] [--notes f.md] [--cle f] [--url-base https://…/]\n"); exit(2); }
$canal = (string)($opt['canal'] ?? 'Beta');
$notes = isset($opt['notes']) ? (string)file_get_contents($opt['notes']) : '';
$sortie = rtrim((string)($opt['sortie'] ?? $racine . '/dist'), '/');

// ── 0. Clés : vérifiées AVANT de modifier quoi que ce soit ─────────────────
// Un serveur vérifie la signature avec la clé de la version qu'il a déjà
// installée. Hors transition, c'est la clé inscrite dans api/CleEditeur.php
// (elle n'en change pas sans --remplacer-cle) : la clé privée doit y
// correspondre, sinon l'archive serait refusée partout — on s'arrête ici.
$sansSignature = isset($opt['sans-signature']);
$sk = $lireClePrivee($cleF);                 // clé dont la publique est inscrite
$pkEmbarquee = LarkaMiseAJour::clePubliqueEditeur($cleEditeurF);
$skSignature = $sk;                          // clé qui signe cette version
$transition = $lireTransition();             // clé qu'ont encore les serveurs, si une rotation attend
$modeTransition = !$sansSignature && isset($opt['signer-avec']);
if (!$sansSignature && $transition !== null && !$modeTransition) {
    fwrite(STDERR, "Transition de clé en attente : les serveurs ont encore la clé {$empreinte($transition)}.\n"
        . "  Cette version doit être signée avec elle : --signer-avec <ancienne clé privée>.\n");
    exit(1);
}
if ($modeTransition && $transition === null) {
    fwrite(STDERR, "--signer-avec : aucune transition de clé en attente dans api/CleEditeur.php.\n"
        . "  Publiez sans --signer-avec (une transition se prépare avec --generer-cles --remplacer-cle).\n");
    exit(1);
}
if ($modeTransition) {
    // Version de TRANSITION : signée avec l'ancienne clé (celle des serveurs),
    // elle embarque la nouvelle — qu'il faut donc détenir (--cle), sans quoi
    // plus personne ne pourrait signer la version d'après.
    $skSignature = $lireClePrivee((string)$opt['signer-avec']);
    if ($skSignature === null) { fwrite(STDERR, "Ancienne clé privée absente ou illisible : {$opt['signer-avec']}\n"); exit(1); }
    if (!hash_equals($transition, sodium_crypto_sign_publickey_from_secretkey($skSignature))) {
        fwrite(STDERR, "--signer-avec : ce n'est pas la clé qu'ont les serveurs (empreinte attendue {$empreinte($transition)}).\n");
        exit(1);
    }
}
if (!$sansSignature) {
    if ($sk === null) {
        fwrite(STDERR, "Clé privée absente ou illisible : $cleF\n"
            . "  Une version non signée est refusée par tous les serveurs.\n"
            . "  Une seule fois : --generer-cles (ou --cle <fichier> si elle est ailleurs).\n"
            . "  Archive de test uniquement : --sans-signature\n");
        exit(1);
    }
    if ($pkEmbarquee === null) {
        fwrite(STDERR, "api/CleEditeur.php ne contient pas de clé publique valide.\n"
            . "  Inscrivez celle de votre clé privée : --ecrire-cle-publique\n");
        exit(1);
    }
    if (!hash_equals($pkEmbarquee, sodium_crypto_sign_publickey_from_secretkey($sk))) {
        fwrite(STDERR, "La clé privée $cleF ne correspond PAS à la clé publique de api/CleEditeur.php"
            . " (empreinte {$empreinte($pkEmbarquee)}).\n"
            . "  Les serveurs refuseraient cette version. Mauvaise clé privée ? (--cle <fichier>)\n"
            . "  Changer de clé : voir l'en-tête de cet outil, point 4.\n");
        exit(1);
    }
}
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
// Transition : la marque est retirée AVANT l'archive (le fichier publié est
// propre) et remise si la construction échoue plus loin.
if ($modeTransition) $ecrireTransition(null);
$annuler = static function (string $message) use (&$zipF, $modeTransition, $transition, $ecrireTransition): never {
    @unlink($zipF); @unlink("$zipF.sha256");
    if ($modeTransition) $ecrireTransition($transition);
    fwrite(STDERR, $message); exit(1);
};
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
if (!$sansSignature) {
    $sig = base64_encode(sodium_crypto_sign_detached($sha, $skSignature));
    $pkSignature = sodium_crypto_sign_publickey_from_secretkey($skSignature);
    // Revérification : (1) la signature, avec la clé qui l'a produite — celle
    // que les serveurs ont (la clé inscrite, ou l'ancienne en transition) ;
    // (2) la clé EMBARQUÉE DANS L'ARCHIVE, qui vérifiera la version suivante.
    $zv = new ZipArchive();
    $cleArchive = false;
    if ($zv->open($zipF) === true) {
        $cleArchive = $zv->getFromName("$nom/api/CleEditeur.php");
        $zv->close();
    }
    $tmp = tempnam(sys_get_temp_dir(), 'larka-cle');
    file_put_contents($tmp, (string)$cleArchive);
    $pkArchive = LarkaMiseAJour::clePubliqueEditeur($tmp);
    @unlink($tmp);
    if ($pkArchive === null || !hash_equals($pkArchive, $pkEmbarquee)) {
        $annuler("L'archive ne contient pas la clé publique attendue (api/CleEditeur.php) : construction annulée.\n");
    }
    if (!sodium_crypto_sign_verify_detached(base64_decode($sig), $sha, $pkSignature)) {
        $annuler("Signature produite invalide (anomalie) : construction annulée.\n");
    }
    file_put_contents("$zipF.sig", $sig . "\n");
    echo "Signature : $zipF.sig — acceptée par les serveurs qui ont la clé {$empreinte($pkSignature)}\n";
    echo "Clé embarquée (vérifiera la version suivante) : {$empreinte($pkArchive)}"
       . (hash_equals($pkArchive, $pkSignature) ? "\n" : "  ← VERSION DE TRANSITION\n");
    if ($modeTransition) {
        echo "⚠ Publiez CETTE version avant toute autre, et laissez-la en ligne : c'est par elle que les serveurs\n"
           . "  passent à la nouvelle clé. Les suivantes se publient normalement (--cle <nouvelle clé>).\n";
    }
} else {
    echo "⚠ --sans-signature : archive de TEST — refusée par tous les serveurs.\n";
}
$url = isset($opt['url-base']) ? rtrim($opt['url-base'], '/') . "/$nom.zip" : "$nom.zip";
// Versions précédentes reportées dans « anterieures » (10 au plus) : un serveur
// en retard d'une rotation de clé y trouve la version de transition. Leurs
// archives doivent rester en ligne.
$precedent = json_decode((string)@file_get_contents("$sortie/latest.json"), true);
$anterieures = [];
if (is_array($precedent)) {
    foreach (array_merge([$precedent], is_array($precedent['anterieures'] ?? null) ? $precedent['anterieures'] : []) as $e) {
        if (!is_array($e) || ($e['version'] ?? $v) === $v || empty($e['zip'])) continue;
        unset($e['anterieures']);
        $anterieures[$e['version']] ??= $e;
    }
}
file_put_contents("$sortie/latest.json", json_encode([
    'version' => $v, 'canal' => $canal, 'date' => date('Y-m-d'), 'notes' => $notes,
    'zip' => $url, 'sha256' => $sha, 'signature' => $sig,
    'anterieures' => array_slice(array_values($anterieures), 0, 10),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
echo "Manifeste : $sortie/latest.json\n\nGitHub :\n  gh release create v$v $zipF $zipF.sha256" . ($sig ? " $zipF.sig" : '')
   . (isset($opt['notes']) ? " --notes-file " . $opt['notes'] : " --notes \"…\"") . (strcasecmp($canal, 'Stable') ? ' --prerelease' : '') . "\n";

<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Épreuve « conformité RGPD » — une politique commune, personnalisable, et
 * toute personnalisation se voit.
 *
 *   1. Sans personnalisation : modèle commun, aucune mention « personnalisé ».
 *   2. Coordonnées seules : toujours le modèle commun (elles sont attendues).
 *   3. Section réécrite ou ajoutée : bandeau daté + mention sur la section.
 *   4. Section recopiée telle quelle : pas comptée comme personnalisation.
 *   5. Le modèle suit l'installation (assistant IA local / distant, SSO).
 *   6. Rien n'est servi en HTML : le client rend du texte, nœud par nœud.
 *   7. Avant connexion, en multi-tenant : la politique du domaine, jamais
 *      celle d'une autre organisation.
 *
 * php outils/epreuves/test-rgpd.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$racine = dirname(__DIR__, 2);
$ok = 0; $ko = 0;
function verif(bool $c, string $m, string $d = ''): void
{
    global $ok, $ko;
    if ($c) { $ok++; printf("  ✅  %-62s %s\n", $m, $d); }
    else    { $ko++; printf("  ❌  %-62s %s\n", $m, $d); }
}
if (!extension_loaded('pdo_sqlite')) { echo "\n  ⏭️  pdo_sqlite absent : épreuve sautée.\n"; exit(0); }

// Installation jetable : le registre Super Admin (SQLite) y est créé, pas dans le dépôt.
$t = sys_get_temp_dir() . '/larka-rgpd-' . bin2hex(random_bytes(4));
@mkdir("$t/data", 0700, true);
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$racine/api", FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
foreach ($it as $f) {
    $cible = "$t/api/" . substr($f->getPathname(), strlen("$racine/api") + 1);
    if ($f->isDir()) { @mkdir($cible, 0700, true); continue; }
    @mkdir(dirname($cible), 0700, true);
    copy($f->getPathname(), $cible);
}
copy("$racine/version.json", "$t/version.json");

/** Exécute un bout de PHP dans l'installation jetable ; rend sa sortie JSON décodée. */
$php = function (string $code, array $cfg = []) use ($t) {
    file_put_contents("$t/config.json", json_encode($cfg + ['serveur' => ['env' => 'dev'],
        'base_de_donnees' => ['driver' => 'sqlite', 'path' => 'data/gmao.db']]));
    $f = "$t/essai.php";
    file_put_contents($f, "<?php\ndefine('LARKA_SANS_SESSION', true);\nchdir(" . var_export($t, true) . ");\n"
        . "require 'api/config.php';\nrequire_once 'api/Rgpd.php';\n" . $code);
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($f) . ' 2>&1', $o, $c);
    $s = implode("\n", $o);
    $j = json_decode($s, true);
    return $j ?? $s;
};
$badges = fn(array $p) => array_values(array_map(fn($s) => $s['id'], array_filter($p['sections'] ?? [], fn($s) => $s['personnalisee'])));

echo "\n  Politique commune\n";
$p = $php('echo json_encode(LarkaRgpd::politique("default"));');
verif(is_array($p) && !$p['personnalisee'] && $badges($p) === [] && str_contains($p['mention'], 'non personnalisée'), 'sans personnalisation : modèle commun, aucune mention', is_array($p) ? '' : mb_substr((string)$p, 0, 80));
verif(is_array($p) && str_contains($p['sections'][0]['texte'] ?? '', 'utilisé par votre organisation'), 'organisation inconnue : formulation neutre');
$ids = is_array($p) ? array_column($p['sections'], 'id') : [];
verif(!in_array('ia', $ids, true) && !in_array('connexion', $ids, true), 'pas d\'assistant ni de SSO : sections absentes');

echo "\n  Coordonnées, sections réécrites ou ajoutées\n";
$p = $php('TenantResolver::setRgpd("org_default", LarkaRgpd::preparer("org", ["identite" => ["organisation" => "Commune d\'Essai", "contact" => "rgpd@essai.test"]]));
echo json_encode(LarkaRgpd::politique("default"));');
verif(is_array($p) && !$p['personnalisee'] && $p['organisation'] === "Commune d'Essai", 'coordonnées seules : pas une personnalisation');
verif(is_array($p) && str_contains(implode("\n", array_column($p['sections'], 'texte')), 'rgpd@essai.test'), 'contact repris dans la politique');
$p = $php('$m = LarkaRgpd::modele("fr");
TenantResolver::setRgpd("org_default", LarkaRgpd::preparer("org", ["identite" => ["organisation" => "Commune d\'Essai"],
  "sections" => ["duree" => ["texte" => "Interventions : 10 ans."], "cookies" => ["texte" => $m["cookies"]["texte"]]],
  "ajouts" => [["titre" => "Vidéoprotection", "texte" => "Images conservées 15 jours."]]]) + ["mise_a_jour" => "2026-10-23 10:00"]);
echo json_encode(LarkaRgpd::politique("default"));');
verif(is_array($p) && $p['personnalisee'] && str_contains($p['mention'], "personnalisée par Commune d'Essai") && $p['personnalisee_le'] === '2026-10-23',
    'section réécrite : bandeau daté « personnalisée par… »');
verif(is_array($p) && $badges($p) === ['duree', 'ajout_1'], 'mention sur les seules sections réécrites ou ajoutées', is_array($p) ? implode(', ', $badges($p)) : '');
verif(is_array($p) && !in_array('cookies', $badges($p), true), 'section recopiée telle quelle : pas comptée');

echo "\n  Nettoyage\n";
$n = $php('echo json_encode(LarkaRgpd::nettoyer(["identite" => ["organisation" => str_repeat("x", 500), "inconnu" => "y"],
  "sections" => ["n_existe_pas" => ["texte" => "z"], "droits" => ["texte" => "a\x07b"]], "ajouts" => array_fill(0, 9, ["titre" => "t", "texte" => "u"])]));');
verif(is_array($n) && mb_strlen($n['identite']['organisation']) === 160 && !isset($n['identite']['inconnu']), 'coordonnées bornées, champs inconnus ignorés');
verif(is_array($n) && !isset($n['sections']['n_existe_pas']) && ($n['sections']['droits']['texte'] ?? '') === 'ab', 'section inconnue ignorée, caractères de contrôle retirés');
verif(is_array($n) && count($n['ajouts']) === 6, 'six sections ajoutées au plus');

echo "\n  Le modèle suit l'installation\n";
$p = $php('echo json_encode(LarkaRgpd::politique(null));', ['assistant' => ['actif' => true, 'fournisseur' => 'ollama'],
    'microsoft_oauth' => ['actif' => true]]);
$ia = is_array($p) ? (array_values(array_filter($p['sections'], fn($s) => $s['id'] === 'ia'))[0]['texte'] ?? '') : '';
verif(str_contains($ia, 'Aucune donnée ne quitte'), 'assistant local : « aucune donnée ne quitte ce réseau »');
verif(is_array($p) && in_array('connexion', array_column($p['sections'], 'id'), true), 'connexion Microsoft : section ajoutée d\'office');
$p = $php('echo json_encode(LarkaRgpd::politique(null));', ['assistant' => ['actif' => true, 'fournisseur' => 'mistral', 'mode' => 'agent']]);
$ia = is_array($p) ? (array_values(array_filter($p['sections'], fn($s) => $s['id'] === 'ia'))[0]['texte'] ?? '') : '';
verif(str_contains($ia, 'Mistral AI') && str_contains($ia, 'article 28'), 'assistant distant : fournisseur nommé, sous-traitance rappelée');
$p = $php('echo json_encode(LarkaRgpd::politique(null, "en"));');
verif(is_array($p) && $p['titre'] === 'Personal data protection' && str_contains($p['sections'][0]['texte'] ?? '', 'your organisation'), 'anglais : modèle traduit');

echo "\n  Suppression d'une organisation\n";
$r = $php('TenantResolver::setRgpd("org_alpha", ["sections" => ["duree" => ["texte" => "x"]]]);
TenantResolver::upsertTenant("alpha", ["nom" => "Alpha", "actif" => true, "domaines_email" => ["alpha.test"], "base_de_donnees" => ["driver" => "sqlite", "path" => "data/alpha.db"]]);
TenantResolver::deleteTenant("alpha");
echo json_encode(["reste" => TenantResolver::getRgpd("org_alpha")]);');
verif(is_array($r) && array_key_exists('reste', $r) && $r['reste'] === null, 'sa personnalisation part avec elle');

echo "\n  Affichage et accès\n";
$js = (string)file_get_contents("$racine/js/rgpd.js");
verif(str_contains($js, 'createTextNode') && !preg_match('/innerHTML\s*=\s*[^;]*(texte|s\.titre|mention)/', $js), 'client : texte rendu nœud par nœud, jamais en innerHTML');
$route = (string)file_get_contents("$racine/api/routes/rgpd.php");
verif(str_contains($route, "domaines_web") && !str_contains($route, 'TenantResolver::resolve()'), 'avant connexion : organisation du domaine seulement (pas « la première venue »)');
verif((bool)preg_match("/action === 'superadmin_rgpd'\) \{\s*require_superadmin\(\);/", $route), 'module : réservé au Super Admin');
$idx = (string)file_get_contents("$racine/api/index.php");
verif(str_contains($idx, "'rgpd', 'superadmin_rgpd',") && str_contains($idx, "routes/rgpd.php"), 'routes branchées (sans base de tenant)');
$html = (string)file_get_contents("$racine/index.html");
verif(str_contains($html, 'id="loginRgpd"') && str_contains($html, 'id="btnRgpd"') && str_contains($html, 'js/rgpd.js'), 'liens : écran de connexion et menu de l\'application');
verif(str_contains((string)file_get_contents("$racine/js/assistant.js"), "LarkaRgpd.afficher({ section: 'ia' })"), 'assistant : « En savoir plus » ouvre la politique');

// Nettoyage
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($t, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
@rmdir($t);

$total = $ok + $ko;
echo "\n───────────────────────────────────────────────────────────────────────\n";
echo $ko === 0 ? " ✅ $ok/$total — politique commune, personnalisation visible.\n"
               : " ❌ $ko échec(s) sur $total.\n";
echo "───────────────────────────────────────────────────────────────────────\n";
exit($ko === 0 ? 0 : 1);

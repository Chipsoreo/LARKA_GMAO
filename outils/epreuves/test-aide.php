<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Épreuve « aide intégrée » — chacun trouve, dans la page, ce qui concerne
 * SON rôle, et la recherche trouve la bonne fiche.
 *
 *   1. Contenu : fiches complètes en français et en anglais, écrans et
 *      actions qui existent.
 *   2. Rôles : le serveur ne renvoie que les fiches du rôle (demandeur,
 *      visionneur, gestionnaire, Super Admin, exploitant du serveur).
 *   3. Installation : procédures propres au mode détecté (pas de commande
 *      Linux sous Windows…), rapport des prérequis traduit.
 *   4. Recherche (Node, si présent) : synonymes, fautes de frappe, mots vides.
 *   5. Ni notes de version ni manuel à télécharger ; branchement.
 *
 * php outils/epreuves/test-aide.php
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
// Le fichier de routes ne fait que DÉFINIR ses fonctions quand aucune action ne lui correspond.
$action = '';
require $racine . '/api/routes/aide.php';
$js = (string)file_get_contents("$racine/js/pages/aide.js");

echo "\n  Contenu\n";
$c = require $racine . '/api/AideFiches.php';
$fiches = $c['fiches'];
preg_match_all('/^\s{2}(\w+):\s+\{ title:/m', (string)file_get_contents("$racine/js/nav.js"), $m);
$pages = array_flip($m[1]);
$ids = []; $defauts = [];
foreach ($fiches as $f) {
    if (isset($ids[$f['id']])) $defauts[] = "{$f['id']} en double";
    $ids[$f['id']] = true;
    if (!isset($c['categories'][$f['cat']])) $defauts[] = "{$f['id']} : catégorie {$f['cat']}";
    if (!empty($f['page']) && !isset($pages[$f['page']])) $defauts[] = "{$f['id']} : écran {$f['page']} inconnu";
    if (!empty($f['action']) && !preg_match('/^\s+' . preg_quote($f['action'], '/') . ':\s/m', $js)) $defauts[] = "{$f['id']} : action {$f['action']} inconnue";
    foreach (['fr', 'en'] as $lg) {
        foreach (['titre', 'mots', 'texte'] as $k) if (trim((string)($f[$lg][$k] ?? '')) === '') $defauts[] = "{$f['id']} : $lg.$k vide";
        if (count($f[$lg]['etapes'] ?? []) !== count($f['fr']['etapes'] ?? [])) $defauts[] = "{$f['id']} : étapes FR/EN";
        if ((!empty($f['page']) || !empty($f['action'])) && empty($f[$lg]['bouton'])) $defauts[] = "{$f['id']} : bouton $lg";
    }
}
verif(count($fiches) >= 80 && !$defauts, 'fiches complètes, en français et en anglais', count($fiches) . ' fiches' . ($defauts ? ' — ' . implode(' ; ', array_slice($defauts, 0, 4)) : ''));
$enAnglais = array_filter($fiches, fn($f) => preg_match('/[àâçéèêëîïôûù]/u', $f['en']['titre'] . $f['en']['texte']) && !preg_match('/Légifrance|ADEME|Français/u', $f['en']['texte']));
verif(!$enAnglais, 'aucune fiche anglaise restée en français', $enAnglais ? implode(', ', array_column($enAnglais, 'id')) : '');

echo "\n  Rôles\n";
$ids = fn(array $groupes, bool $multi, string $lg = 'fr') => array_column(aide_fiches($groupes, $multi, $lg)[0], 'titre', 'id');
verif(aide_groupes('Demandeur', false, false, true) === ['demandeur']
   && aide_groupes('Technicien', false, false, true) === ['visionneur']
   && aide_groupes('Gestionnaire', false, false, true) === ['gestionnaire']
   && aide_groupes('Admin', false, false, false) === ['gestionnaire', 'exploitant']
   && aide_groupes('Gestionnaire', true, true, true) === ['sa', 'exploitant']
   && aide_groupes('Gestionnaire', true, false, true) === ['gestionnaire', 'exploitant'], 'groupes : rôle, installation simple, console ou organisation');
$d = $ids(['demandeur'], true);
verif(isset($d['demande-creer'], $d['mes-biens']) && !isset($d['demande-traiter']) && !isset($d['sa-tenant-creer']) && !isset($d['comptes']), 'demandeur : ses demandes, rien de la gestion', count($d) . ' fiches');
$v = $ids(['visionneur'], true);
verif(isset($v['lecture-fiche'], $v['scanner']) && !isset($v['intervention-creer']) && !isset($v['mes-biens']), 'visionneur : consulter, pas créer', count($v) . ' fiches');
$g = $ids(['gestionnaire'], true);
verif(isset($g['demande-traiter'], $g['intervention-cloturer'], $g['comptes']) && !isset($g['sa-tenant-creer']) && !isset($g['demande-suivre']), 'gestionnaire : traiter, administrer ; pas le Super Admin', count($g) . ' fiches');
$s = $ids(['sa', 'exploitant'], true);
verif(isset($s['sa-tenant-creer'], $s['sa-rgpd']) && !isset($s['demande-creer']) && !isset($s['intervention-creer']), 'Super Admin : organisations, conformité ; pas les fiches métier', count($s) . ' fiches');
verif(isset($ids(['gestionnaire'], false)['sauvegardes']) && !isset($g['sauvegardes']) && isset($g['sauvegardes-multi']), 'installation simple ou multi-tenant : la bonne fiche des sauvegardes');
verif(($ids(['demandeur'], true, 'en')['connexion'] ?? '') === 'Sign in', 'anglais : fiches servies en anglais');

echo "\n  Installation détectée\n";
$env = fn(string $mode, string $r) => ['os' => 'X', 'windows' => $mode === 'windows', 'mode' => $mode, 'serveur_web' => $mode === 'production' ? 'Nginx' : '',
    'php' => PHP_VERSION, 'sapi' => $mode === 'production' ? 'fpm-fcgi' : 'cli-server', 'fuseau' => 'Europe/Paris', 'racine' => $r];
$texte = fn(array $fs) => json_encode($fs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$w = $texte(aide_fiches_serveur($env('windows', 'C:\\Larka'), false, false, null, null, 'SQLite'));
verif(str_contains($w, 'Larka.cmd') && !str_contains($w, './start.sh') && !str_contains($w, 'systemctl'), 'Windows : Larka.cmd, aucune commande Linux');
$l = $texte(aide_fiches_serveur($env('startsh', '/srv/larka'), false, false, null, null, 'PostgreSQL'));
verif(str_contains($l, './start.sh start -d') && !str_contains($l, 'Larka.cmd') && str_contains($l, 'srv-postgresql'), 'Linux (start.sh) : ./start.sh, pas de Larka.cmd');
verif(str_contains($texte(aide_fiches_serveur($env('systemd', '/srv/larka'), false, false, null, null, 'SQLite')), 'larka-sauvegardes.timer'), 'service systemd : sauvegardes par la minuterie');
$p = $texte(aide_fiches_serveur($env('production', '/var/www/gmao'), true, false, null, null, 'PostgreSQL'));
verif(str_contains($p, 'systemctl') && str_contains($p, '/var/www/gmao/api/outils/sauvegarde-auto.php') && !str_contains($p, 'srv-demo'), 'production : services, chemins de CETTE installation');
$pe = aide_fiches_serveur($env('windows', 'C:\\Larka'), false, true, null, null, 'SQLite');
verif(($pe[1]['titre'] ?? '') === 'Start, stop Larka', 'anglais : procédures en anglais');
$tr = aide_prerequis_en([['id' => 'php', 'libelle' => 'PHP 8.0', 'statut' => 'bloquant', 'detail' => 'Larka demande PHP 8.1 ou plus récent.', 'conseil' => 'Installez PHP 8.1 ou plus récent, puis relancez.'],
                         ['id' => 'x', 'libelle' => 'Extensions PHP conseillées', 'statut' => 'avertissement', 'detail' => 'Absente(s) : sodium (signature des mises à jour).', 'conseil' => '']]);
verif($tr[0]['detail'] === 'Larka needs PHP 8.1 or later.' && $tr[0]['conseil'] === 'Install PHP 8.1 or later, then restart.'
   && $tr[1]['libelle'] === 'Recommended PHP extensions' && $tr[1]['detail'] === 'Missing: sodium (update signatures).', 'rapport des prérequis traduit');
$e = aide_environnement();
verif($e['mode'] !== '' && $e['os'] !== '' && $e['php'] === PHP_VERSION, 'environnement détecté', $e['os'] . ' — ' . $e['mode']);

echo "\n  Recherche\n";
$node = trim((string)@shell_exec('command -v node 2>/dev/null'));
if ($node === '') {
    echo "  ⊘  Node absent : recherche non éprouvée\n";
} else {
    $tous = array_merge(aide_fiches(['demandeur', 'visionneur', 'gestionnaire', 'sa', 'exploitant'], false, 'fr')[0],
                        aide_fiches_serveur($env('startsh', '/srv/larka'), false, false, null, null, 'SQLite'));
    $cas = [
        ['mot de passe oublié', 'mdp-oublie'], ["j'ai oublié mon mot de passe", 'mdp-oublie'],
        ['créer une intervention', 'intervention-creer'], ['sauvgarde', 'sauvegardes'],
        ['redémarrer le serveur', 'srv-demarrer'], ['scanner un qr code', 'scanner'],
        ['english', 'langue'], ['fuite eau bureau', 'demande-creer'], ['domaine e-mail obligatoire', 'sa-domaine-requis'],
    ];
    $prog = '
const vm = require("vm"), fs = require("fs");
const ctx = { console, setTimeout, clearTimeout, document: { getElementById: () => null, querySelector: () => null, querySelectorAll: () => [] }, location: { search: "" } };
vm.createContext(ctx);
vm.runInContext(fs.readFileSync(process.argv[2], "utf8") + "\nthis._aideChercher = _aideChercher; this._aideMots = _aideMots; this.poser = f => { _aideFiches = f; };", ctx);
const fiches = JSON.parse(fs.readFileSync(0, "utf8"));
fiches.forEach(f => { f._idx = { t: ctx._aideMots(f.titre), m: ctx._aideMots(f.mots), c: [], b: ctx._aideMots([f.texte, ...(f.etapes || []), f.note, f.code].filter(Boolean).join(" ")) }; });
ctx.poser(fiches);
const sortie = {};
for (const [q] of ' . json_encode($cas, JSON_UNESCAPED_UNICODE) . ') { const r = ctx._aideChercher(q); sortie[q] = r ? r.fiches.slice(0, 3).map(f => f.id) : null; }
sortie["comment faire"] = ctx._aideChercher("comment faire");
process.stdout.write(JSON.stringify(sortie));';
    $f = tempnam(sys_get_temp_dir(), 'aide');
    file_put_contents($f, $prog);
    $proc = proc_open([$node, $f, "$racine/js/pages/aide.js"], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], json_encode($tous, JSON_UNESCAPED_UNICODE)); fclose($pipes[0]);
    $res = json_decode((string)stream_get_contents($pipes[1]), true); $err = stream_get_contents($pipes[2]);
    proc_close($proc); @unlink($f);
    if (!is_array($res)) {
        verif(false, 'recherche exécutée', trim((string)$err));
    } else {
        foreach ($cas as [$q, $attendu]) {
            $trouve = $res[$q] ?? [];
            verif(($trouve[0] ?? '') === $attendu, "« $q » → $attendu", implode(', ', (array)$trouve));
        }
        verif($res['comment faire'] === null, 'mots vides seuls : pas de recherche (toutes les fiches restent affichées)');
    }
}

echo "\n  Ni notes de version ni téléchargement ; branchement\n";
$src = (string)file_get_contents("$racine/api/routes/aide.php");
verif(!str_contains($src, 'aide_manuel') && !str_contains($src, 'NOUVEAUTES') && !is_file("$racine/NOUVEAUTES.md")
   && !str_contains($js, 'aide_manuel') && !str_contains($js, 'nouveautes'), 'ni notes de version ni manuel à télécharger');
verif(!str_contains($js, 'innerHTML') && str_contains($js, "champ.id = 'aideRecherche'"), 'page : barre de recherche, texte rendu sans innerHTML');
$index = (string)file_get_contents("$racine/api/index.php");
verif(str_contains($index, "'aide', 'thematiques_langue',") && !str_contains($index, 'aide_manuel'), 'route branchée (sans base de tenant)');
verif(str_contains((string)file_get_contents("$racine/js/nav.js"), "renderFn: 'renderAide'") && str_contains((string)file_get_contents("$racine/js/page-loader.js"), "aide:              ['js/pages/aide.js']"), 'page déclarée');
$html = (string)file_get_contents("$racine/index.html");
verif(str_contains($html, 'id="btnAide"') && str_contains($html, "closeMobileMenu();navigate('aide')"), 'menus : barre latérale et mobile');
verif(preg_match("/^\s+'outils' 'Documentations' /m", (string)file_get_contents("$racine/deploy/install.sh")) === 1, 'production : la documentation n\'est pas copiée (l\'aide est dans l\'application)');

$total = $ok + $ko;
echo "\n───────────────────────────────────────────────────────────────────────\n";
echo $ko === 0 ? " ✅ $ok/$total — chacun trouve dans la page ce qui concerne son rôle.\n"
               : " ❌ $ko échec(s) sur $total.\n";
echo "───────────────────────────────────────────────────────────────────────\n";
exit($ko === 0 ? 0 : 1);

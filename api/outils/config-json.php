<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * ═══════════════════════════════════════════════════════════════════════════
 *  Larka — Modifier config.json / .env SANS les réécrire (scripts d'installation)
 * ═══════════════════════════════════════════════════════════════════════════
 *
 *  start.sh et deploy/install.sh modifiaient config.json en le relisant puis en
 *  le réécrivant en entier (json_decode → json_encode). Effets de bord, à chaque
 *  mise à jour ou relance :
 *    - les objets vides « {} » devenaient des listes « [] » (PHP ne fait pas la
 *      différence une fois décodé en tableau) : une section vide changeait de
 *      type, et la lecture qui attend un objet échouait ;
 *    - aucune copie n'était gardée : une erreur de manipulation était sans retour ;
 *    - le fichier était remplacé (nouveau propriétaire, nouveaux droits) : écrit
 *      par root, il n'était plus lisible par PHP-FPM (www-data).
 *  Et le choix SharePoint était inscrit « false » en mode non interactif (démarrage
 *  automatique, -y) sur une configuration qui ne le précisait pas — c'est-à-dire
 *  qui l'avait ACTIF (comportement historique) : SharePoint disparaissait.
 *
 *  Ici : décodage en objets (« {} » conservé), clé par clé, écriture EN PLACE
 *  (propriétaire et droits inchangés), copie horodatée AVANT toute écriture
 *  (data/backups/config/, 10 dernières, lisibles du seul propriétaire), et rien
 *  n'est écrit si rien ne change.
 *
 *    php api/outils/config-json.php lire        <config.json> <section.cle>
 *    php api/outils/config-json.php ecrire      <config.json> <section.cle>=<valeur JSON> […]
 *    php api/outils/config-json.php completer   <config.json> <section.cle>=<valeur JSON> […]
 *          (n'écrit que les clés ABSENTES : ce qui est déjà réglé n'est jamais touché)
 *    php api/outils/config-json.php nettoyer    <config.json>
 *          (valeurs modèles « CHANGER_CE_MOT_DE_PASSE », « GENERER_UNE_CLE » → vides ;
 *           le reste du fichier est conservé tel quel)
 *    php api/outils/config-json.php sauvegarder <fichier>      (copie seule, ex. .env)
 *
 *  Codes de sortie : 0 fait (ou rien à faire), 1 fichier illisible / écriture refusée, 2 usage.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

const LK_PLACEHOLDERS = ['CHANGER_CE_MOT_DE_PASSE', 'GENERER_UNE_CLE'];
const LK_COPIES_GARDEES = 10;

function lk_cj_echec(string $m, int $code = 1): never { fwrite(STDERR, $m . "\n"); exit($code); }

/** Copie horodatée dans <racine>/data/backups/config/ (racine = dossier du fichier). */
function lk_cj_sauvegarder(string $f): ?string
{
    if (!is_file($f)) return null;
    $dir = dirname(realpath($f) ?: $f) . '/data/backups/config';
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) return null;
    $base = basename($f);
    $dest = $dir . '/' . $base . '.' . date('Ymd-His');
    if (is_file($dest)) $dest .= '-' . substr(bin2hex(random_bytes(2)), 0, 4);
    if (!@copy($f, $dest)) return null;
    @chmod($dest, 0600);
    // Propriétaire du fichier d'origine (lancé par root : la copie reste lisible par le service).
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        @chown($dest, fileowner($f)); @chgrp($dest, filegroup($f));
    }
    $copies = glob($dir . '/' . $base . '.2*') ?: [];
    rsort($copies);
    foreach (array_slice($copies, LK_COPIES_GARDEES) as $vieille) @unlink($vieille);
    return $dest;
}

function lk_cj_lire(string $f): object
{
    if (!is_file($f)) lk_cj_echec("Fichier absent : $f");
    $brut = (string)@file_get_contents($f);
    $c = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', $brut));
    if (!is_object($c)) lk_cj_echec("JSON illisible : $f — rien n'a été modifié.");
    return $c;
}

/** Écriture EN PLACE (inode, propriétaire et droits conservés), après copie. */
function lk_cj_ecrire(string $f, object $c, string $avant): bool
{
    $txt = json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($txt === false) lk_cj_echec('Encodage JSON impossible : rien n\'a été modifié.');
    if (json_encode(json_decode($avant)) === json_encode($c)) return false;   // rien ne change
    if (lk_cj_sauvegarder($f) === null) fwrite(STDERR, "(copie de sauvegarde impossible — on continue)\n");
    if (@file_put_contents($f, $txt . "\n", LOCK_EX) === false) lk_cj_echec("Écriture refusée : $f");
    return true;
}

/** « a.b.c » → [parent, clé], en créant les objets intermédiaires si $creer. */
function lk_cj_parent(object $c, string $chemin, ?string &$cle, bool $creer): ?object
{
    $parts = explode('.', $chemin);
    $cle = array_pop($parts);
    $o = $c;
    foreach ($parts as $p) {
        if (!isset($o->$p) || !is_object($o->$p)) {
            if (!$creer) return null;
            $o->$p = new stdClass();
        }
        $o = $o->$p;
    }
    return $o;
}

function lk_cj_valeur(string $brut): mixed
{
    $v = json_decode($brut);
    if (json_last_error() !== JSON_ERROR_NONE) return $brut;     // texte simple
    return $v;
}

$args = array_slice($argv, 1);
$cmd  = $args[0] ?? '';
$f    = $args[1] ?? '';
if ($f === '') lk_cj_echec("Usage : php api/outils/config-json.php lire|ecrire|completer|nettoyer|sauvegarder <fichier> …", 2);

switch ($cmd) {
    case 'lire': {
        $c = lk_cj_lire($f);
        $p = lk_cj_parent($c, (string)($args[2] ?? ''), $cle, false);
        if ($p === null || !property_exists($p, $cle)) exit(0);
        $v = $p->$cle;
        echo is_scalar($v) ? (is_bool($v) ? ($v ? 'true' : 'false') : (string)$v) : json_encode($v);
        exit(0);
    }
    case 'ecrire':
    case 'completer': {
        $avant = (string)file_get_contents($f);
        $c = lk_cj_lire($f);
        foreach (array_slice($args, 2) as $kv) {
            $i = strpos($kv, '=');
            if ($i === false || $i === 0) lk_cj_echec("Attendu section.cle=valeur, reçu : $kv", 2);
            $p = lk_cj_parent($c, substr($kv, 0, $i), $cle, true);
            if ($cmd === 'completer' && property_exists($p, $cle)) continue;
            $p->$cle = lk_cj_valeur(substr($kv, $i + 1));
        }
        echo lk_cj_ecrire($f, $c, $avant) ? "modifie\n" : "inchange\n";
        exit(0);
    }
    case 'nettoyer': {
        $avant = (string)file_get_contents($f);
        $c = lk_cj_lire($f);
        $n = 0;
        $parcourir = function (&$v) use (&$parcourir, &$n): void {
            if (is_object($v) || is_array($v)) { foreach ($v as &$x) $parcourir($x); unset($x); return; }
            if (is_string($v)) foreach (LK_PLACEHOLDERS as $ph) if (str_contains($v, $ph)) { $v = ''; $n++; return; }
        };
        $parcourir($c);
        echo lk_cj_ecrire($f, $c, $avant) ? "modifie ($n valeur(s) modèle vidée(s))\n" : "inchange\n";
        exit(0);
    }
    case 'sauvegarder': {
        $d = lk_cj_sauvegarder($f);
        if ($d === null) lk_cj_echec("Copie impossible : $f");
        echo $d, "\n";
        exit(0);
    }
}
lk_cj_echec("Commande inconnue : $cmd", 2);

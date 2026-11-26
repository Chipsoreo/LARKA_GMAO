<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka Connect — collecte des équipements raccordés (voir api/connect/Collecte.php).
 *
 * Lancé chaque minute par le déclencheur de l'installation (cron en
 * production, minuterie systemd larka-connect.timer, planificateur de
 * start.sh, lanceur Windows) : ne fait que ce qui est DÛ, un passage à la fois.
 *
 *   php api/outils/connect-collecte.php                  passage normal
 *   php api/outils/connect-collecte.php --forcer         toutes les connexions, maintenant
 *   php api/outils/connect-collecte.php --etat           dernier passage, connexions, alarmes
 *   php api/outils/connect-collecte.php --silencieux     n'écrit que ce qui a été fait ou a échoué
 *   php api/outils/connect-collecte.php --organisation=CLE --connexion=ID
 *
 * Code de sortie : 0 si rien n'a échoué, 1 sinon.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('LARKA_SANS_SESSION', true);
$racine = dirname(__DIR__, 2);
chdir($racine);
require_once $racine . '/api/config.php';
require_once $racine . '/api/SecurityLog.php';
require_once $racine . '/api/Journal.php';
require_once $racine . '/api/Database.php';
require_once $racine . '/api/connect/Connect.php';
Connect::charger();

$args = array_slice($argv, 1);
$opt = function (string $nom) use ($args): ?string {
    foreach ($args as $a) if (str_starts_with($a, "--$nom=")) return substr($a, strlen($nom) + 3);
    return null;
};
$silencieux = in_array('--silencieux', $args, true);
$quand = date('Y-m-d H:i:s');

// ── Serveur de supervision séparé (api/SupervisionLien.php) ──────────────
// Côté PRINCIPAL : chaque organisation reliée à un serveur de supervision est
// synchronisée (demandes, relevés et notifications reçus ; référentiel envoyé),
// même si ce serveur-ci ne parle à aucun équipement (connect.actif = false).
// Côté SUPERVISION, en pont sortant : le relais est relancé s'il ne tourne pas.
if (!in_array('--etat', $args, true)) {
    SupervisionLien::synchroniserTout(function (string $m) use ($quand) { echo "[$quand] $m\n"; });
    try {
        $dbS = new Database();
        $lS = SupervisionLien::lire($dbS);
        if ($lS['Role'] === 'supervision' && $lS['Mode'] === 'pont' && !empty($lS['ClePubliqueDistante'])) {
            $verrou = @fopen($racine . '/data/supervision-pont.lock', 'c');
            $libre = $verrou && flock($verrou, LOCK_EX | LOCK_NB);
            if ($libre) {
                flock($verrou, LOCK_UN); fclose($verrou);
                $php = escapeshellarg(PHP_BINARY ?: 'php');
                $script = escapeshellarg($racine . '/api/outils/supervision-pont.php');
                if (DIRECTORY_SEPARATOR === '\\') pclose(popen("start /B \"\" $php $script --duree=58 --silencieux", 'r'));
                else exec("$php $script --duree=58 --silencieux > /dev/null 2>&1 &");
            } elseif ($verrou) { fclose($verrou); }
        }
    } catch (\Throwable $e) { /* base par défaut absente (multi-organisation) : pas de relais ici */ }
}

if (!Connect::actifServeur()) {
    if (!$silencieux) echo "[$quand] Larka Connect est désactivé sur ce serveur (config.json : connect.actif).\n";
    exit(0);
}

if (in_array('--etat', $args, true)) {
    $e = ConnectCollecte::lireEtat();
    echo "Dernier passage du planificateur : " . ($e['_passage'] ?? 'jamais') . (ConnectCollecte::planificateurActif() ? '' : '  ⚠ le planificateur ne tourne pas ?') . "\n";
    foreach (Connect::organisations() as $org) {
        try { $db = $org['base'] === null ? new Database() : new Database($org['base']); } catch (\Throwable $x) { echo "\n« {$org['nom']} » : base injoignable\n"; continue; }
        if (!Connect::actifPour($db)) { echo "\n« {$org['nom']} » : Larka Connect désactivé pour cette organisation\n"; continue; }
        $depot = new ConnectDepot($db);
        echo "\n« {$org['nom']} »\n";
        foreach ($depot->passerelles() as $p) {
            printf("  %-28s %-7s %-8s %3d point(s)  dernière collecte : %s%s\n", mb_substr($p['Nom'], 0, 28), $p['Protocole'],
                $p['Actif'] ? $p['Etat'] : 'inactive', (int)$p['NbPoints'], $p['DerniereCollecte'] ?: 'jamais',
                $p['DerniereErreur'] ? "\n      ⚠ " . $p['DerniereErreur'] : '');
        }
        echo '  Alarmes actives non acquittées : ' . $depot->compterAlarmesActives() . "\n";
    }
    exit(0);
}

$conn = $opt('connexion');
try {
    $res = ConnectCollecte::executer(in_array('--forcer', $args, true), $opt('organisation'), $conn !== null ? (int)$conn : null);
} catch (\Throwable $e) {
    echo "[$quand] ✗ Larka Connect : " . $e->getMessage() . "\n";
    exit(1);
}
if (!$res['verrou']) {
    if (!$silencieux) echo "[$quand] Un passage est déjà en cours : rien à faire.\n";
    exit(0);
}
$echec = false;
foreach ($res['organisations'] as $o) {
    if (!empty($o['erreur'])) { $echec = true; echo "[$quand] ✗ « {$o['nom']} » : {$o['erreur']}\n"; continue; }
    $fait = $o['lues'] + $o['en_defaut'] + $o['releves'] + $o['anomalies'] + $o['manquants'] + $o['alarmes_ouvertes'] + $o['alarmes_fermees'];
    foreach ($o['connexions'] as $c) {
        if ($c['erreur'] !== null) {
            // Le code de la panne, et la première chose à faire (fiche : php api/outils/codes-erreur.php CODE).
            $code = ConnectDepot::codeErreur((string)$c['erreur'], 'CXC-002');
            echo "[$quand] ✗ « {$o['nom']} » / « {$c['nom']} » ({$c['protocole']}) : " . ($code ? "[$code] " : '') . "{$c['erreur']}\n";
            if ($code && ($r = CodesErreur::premierRemede($code))) echo str_repeat(' ', 22) . '→ ' . str_replace('`', '', $r) . "\n";
        }
        elseif (!$silencieux) echo "[$quand] · « {$o['nom']} » / « {$c['nom']} » : {$c['lues']} lu(s), {$c['en_defaut']} en défaut, {$c['historisees']} historisé(s) ({$c['duree']} s)\n";
    }
    if ($fait > 0 || !$silencieux) {
        echo "[$quand] ✓ « {$o['nom']} » : " . count($o['connexions']) . " connexion(s), {$o['lues']} valeur(s) lue(s), {$o['historisees']} historisée(s)"
           . ($o['releves'] ? ", {$o['releves']} relevé(s) écrit(s)" : '') . ($o['anomalies'] ? ", {$o['anomalies']} anomalie(s) à valider" : '')
           . ($o['manquants'] ? ", {$o['manquants']} relevé(s) manquant(s)" : '') . ($o['alarmes_ouvertes'] ? ", {$o['alarmes_ouvertes']} alarme(s)" : '')
           . ($o['alarmes_fermees'] ? ", {$o['alarmes_fermees']} alarme(s) terminée(s)" : '') . ($o['reportees'] ? ", {$o['reportees']} connexion(s) reportée(s)" : '') . "\n";
    }
    if (!empty($o['erreur_liaisons'])) { $echec = true; echo "[$quand] ✗ liaisons : {$o['erreur_liaisons']}\n"; }
}
exit($echec ? 1 : 0);

<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * Larka Connect — service OCPP permanent (voir api/connect/OcppServeur.php).
 *
 * Tourne en continu, lancé par l'installation : service systemd larka-ocpp
 * (production et démarrage automatique), planificateur de start.sh, lanceur
 * Windows. Une seule instance (verrou) ; il relit config.json toutes les
 * 10 secondes : activer ou désactiver OCPP dans la configuration suffit, sans
 * redémarrer quoi que ce soit.
 *
 *   php api/outils/connect-ocpp.php              lance le service (premier plan)
 *   php api/outils/connect-ocpp.php --etat       état : écoute, bornes connectées, bornes inconnues,
 *                                                passerelles et capteurs LoRaWAN
 *   php api/outils/connect-ocpp.php --si-absent  lance seulement s'il ne tourne pas déjà (planificateurs)
 *
 * Journal : sortie standard (les lanceurs l'envoient dans data/logs/connect-ocpp.log).
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
require_once $racine . '/api/connect/OcppServeur.php';

$args = array_slice($argv, 1);

if (in_array('--etat', $args, true)) {
    $e = @json_decode((string)@file_get_contents(ConnectOcppServeur::fichierEtat()), true);
    if (!is_array($e) || empty($e['maj']) || time() - (int)strtotime((string)$e['maj']) > 30) {
        echo "Le service Larka Connect (OCPP, LoRaWAN) ne tourne pas.\n";
        $c = ConnectOcppServeur::lireConfig();
        echo 'OCPP : ' . ($c['actif'] ? "activé, écoute prévue sur {$c['hote']}:{$c['port']}" : 'désactivé (connect.actif et connect.ocpp.actif)') . "\n";
        $l = ConnectLorawanServeur::lireConfig();
        echo 'LoRaWAN : ' . ($l['actif'] ? "activé, écoute UDP prévue sur {$l['hote']}:{$l['port']}" : 'désactivé (connect.actif et connect.lorawan.actif)') . "\n";
        exit(1);
    }
    echo "Service OCPP en marche (processus {$e['pid']}), " . ($e['actif'] ? "écoute sur {$e['ecoute']}" : 'désactivé dans la configuration') . "\n";
    $n = 0;
    foreach ($e['bornes'] ?? [] as $org => $bornes) {
        foreach ($bornes as $id => $b) {
            $n++;
            echo "  · $id" . ($org !== 'default' ? " ($org)" : '') . " — connectée depuis {$b['depuis']}, {$b['ip']}" . ($b['modele'] ? ", {$b['modele']}" : '')
               . ($b['auth'] ? '' : ' — sans mot de passe') . "\n";
        }
    }
    if (!$n) echo "  Aucune borne connectée.\n";
    foreach ($e['inconnues'] ?? [] as $i) echo "  ⚠ borne inconnue refusée : {$i['chemin']} depuis {$i['ip']} ({$i['quand']})\n";
    $l = @json_decode((string)@file_get_contents(ConnectLorawanServeur::fichierEtat()), true);
    if (!is_array($l) || empty($l['actif'])) {
        echo "LoRaWAN : désactivé dans la configuration (connect.lorawan.actif).\n";
        exit(0);
    }
    echo "LoRaWAN : écoute UDP sur {$l['ecoute']}, " . (int)($l['recues'] ?? 0) . " trame(s) radio reçue(s) depuis le démarrage\n";
    foreach ($l['passerelles'] ?? [] as $eui => $g) {
        echo "  · passerelle $eui ({$g['ip']}) — vue le {$g['vu']}, {$g['trames']} trame(s)" . (empty($g['emission']) ? ', ne peut pas encore émettre (pas de PULL_DATA)' : '') . "\n";
    }
    if (empty($l['passerelles'])) echo "  Aucune passerelle ne s'est encore annoncée.\n";
    foreach ($l['appareils'] ?? [] as $org => $capteurs) {
        foreach ($capteurs as $eui => $a) {
            echo "  · capteur $eui" . ($org !== 'default' ? " ($org)" : '') . ' « ' . ($a['nom'] ?? '') . ' » — '
               . (!empty($a['derniere']) ? "dernière trame le {$a['derniere']}, RSSI " . ($a['rssi'] ?? '?') . ' dBm' : 'aucune trame acceptée')
               . (!empty($a['rejet']) ? " ⚠ {$a['rejet']}" : '') . "\n";
        }
    }
    foreach ($l['inconnus'] ?? [] as $i) echo "  ⚠ capteur non déclaré entendu : " . ($i['genre'] === 'join' ? "DevEUI {$i['id']}" : "DevAddr {$i['id']}") . " ({$i['quand']}, RSSI {$i['rssi']} dBm)\n";
    exit(0);
}

@mkdir($racine . '/data/run', 0775, true);
$verrou = @fopen($racine . '/data/run/connect-ocpp.lock', 'c');
if (!$verrou || !flock($verrou, LOCK_EX | LOCK_NB)) {
    if (!in_array('--si-absent', $args, true)) echo "Le service OCPP tourne déjà.\n";
    exit(0);
}
@set_time_limit(0);
ini_set('memory_limit', '256M');
// Numéro de processus : les lanceurs (start.sh, lanceur Windows) s'en servent
// pour savoir s'il tourne et pour l'arrêter avec le serveur.
$fichierPid = $racine . '/data/run/connect-ocpp.pid';
@file_put_contents($fichierPid, (string)getmypid());
$serveur = new ConnectOcppServeur();
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, fn() => $serveur->arreter());
    pcntl_signal(SIGINT, fn() => $serveur->arreter());
    pcntl_signal(SIGHUP, fn() => $serveur->arreter());
}
$duree = null;
foreach ($args as $a) if (str_starts_with($a, '--duree=')) $duree = (float)substr($a, 8);
try {
    $serveur->tourner($duree);
} finally {
    if ((string)@file_get_contents($fichierPid) === (string)getmypid()) @unlink($fichierPid);
    flock($verrou, LOCK_UN);
    fclose($verrou);
}
exit(0);

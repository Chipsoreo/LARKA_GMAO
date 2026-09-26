<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
/**
 * Larka — poser des questions au moteur de l'assistant, sur le site fictif.
 *
 *   php outils/epreuves/assistant/essai.php "où est l'extincteur 2 du centre technique"
 *   php outils/epreuves/assistant/essai.php --role=Demandeur --login=pdurand "la clim ne marche plus au 2e"
 *   php outils/epreuves/assistant/essai.php -v "…"      (affiche aussi la compréhension)
 *
 * Plusieurs questions à la suite = une conversation (l'historique est transmis).
 */
require_once __DIR__ . '/bootstrap.php';

$args = array_slice($argv, 1);
$verbeux = false; $login = 'smartin';
$questions = [];
foreach ($args as $a) {
    if ($a === '-v') { $verbeux = true; continue; }
    if (str_starts_with($a, '--login=')) { $login = substr($a, 8); continue; }
    if (str_starts_with($a, '--role=')) { $login = substr($a, 7) === 'Demandeur' ? 'pdurand' : 'smartin'; continue; }
    $questions[] = $a;
}
$db = larka_banc_assistant();
$user = larka_fixture_utilisateur($db, $login);

$histo = [];
foreach ($questions as $q) {
    $t0 = microtime(true);
    if (($user['Role'] ?? '') === 'Demandeur') {
        $ctx = AssistantDemandeur::contexte($db, $user, []);
        $r = (new AssistantDemandeur($ctx))->repondre($q, $histo);
    } else {
        $tools = new AssistantTools($db, null, ['user' => $user, 'max_resultats' => 10, 'max_chars' => 160]);
        $r = (new AssistantMoteur($tools))->repondre($q, $histo);
    }
    $ms = round((microtime(true) - $t0) * 1000);
    echo "\n\033[1m❓ $q\033[0m   ({$ms} ms, intention : {$r['intention']}" . (empty($r['trouve']) ? ', RIEN TROUVÉ' : '') . ")\n";
    if ($verbeux) {
        $c = $r['comprehension'];
        unset($c['_mots_bruts'], $c['question']);
        echo "   " . json_encode(array_filter($c, fn($v) => $v !== null && $v !== [] && $v !== false && $v !== ''), JSON_UNESCAPED_UNICODE) . "\n";
        foreach ($r['etapes'] ?? [] as $e) echo "   🔍 {$e['name']} " . json_encode($e['args'], JSON_UNESCAPED_UNICODE) . " → {$e['result_size']}\n";
    }
    echo $r['texte'] . "\n";
    $histo[] = ['role' => 'user', 'content' => $q];
    $histo[] = ['role' => 'assistant', 'content' => $r['texte']];
}

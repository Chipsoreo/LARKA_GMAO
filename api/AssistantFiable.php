<?php
// SPDX-FileCopyrightText: © 2025-2026 Mickaël Larcin (Chipsoreo)
// SPDX-License-Identifier: LicenseRef-Larka-Proprietary
//
// This file is part of Larka, proprietary software by Mickaël Larcin.
// All rights reserved. Use is subject to the license terms; copying,
// distribution, modification or reverse-engineering without the author's
// prior written permission is prohibited. See the LICENSE file for details.
/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * Larka — Assistant, mode « fiable » : l'enchaînement complet
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 *   1. le moteur Larka répond (AssistantMoteur, AssistantDemandeur) ;
 *   2. s'il n'a rien trouvé ET que le recours est permis, le modèle reformule la
 *      question dans un formulaire contraint (AssistantInterprete), vérifié ;
 *   3. le moteur répond à la reformulation — ou garde sa première réponse.
 *
 * Isolé de la route HTTP pour être éprouvé tel quel : outils/epreuves/
 * test-assistant.php y branche des modèles simulés (parfait, médiocre,
 * aberrant, injoignable) et un vrai modèle Ollama, et vérifie que la réponse
 * reste la même.
 * ═══════════════════════════════════════════════════════════════════════════════
 */

require_once __DIR__ . '/AssistantMoteur.php';
require_once __DIR__ . '/AssistantDemandeur.php';
require_once __DIR__ . '/AssistantInterprete.php';

final class AssistantFiable
{
    /**
     * @param array $p  db, user, modules, tools (AssistantTools), question, history,
     *                  demandeur (bool), modele (?AssistantModeleJson), recours (bool),
     *                  emit (?callable fn(string $type, array $extra) — événements SSE)
     * @return array{reply:string, mode:string, rounds:int, tool_calls:array, usage:array, comprehension:array}
     */
    public static function repondre(array $p): array
    {
        $t0 = microtime(true);
        $emit = $p['emit'] ?? null;
        $modele = $p['modele'] ?? null;
        $recours = !empty($p['recours']) && $modele instanceof AssistantModeleJson;
        $usage = ['in' => 0, 'out' => 0, 'cached' => 0, 'ms' => 0, 'appels' => 0];
        $ajoute = function (array $u) use (&$usage) {
            foreach (['in', 'out', 'cached'] as $k) $usage[$k] += (int)($u[$k] ?? 0);
            if (isset($u['tok_s'])) $usage['tok_s'] = $u['tok_s'];
            $usage['appels']++;
        };
        $mode = 'moteur';
        $question = (string)$p['question'];
        $history = is_array($p['history'] ?? null) ? $p['history'] : [];

        if (!empty($p['demandeur'])) {
            $dem = new AssistantDemandeur(AssistantDemandeur::contexte($p['db'], $p['user'], $p['modules'] ?? []));
            $r = $dem->repondre($question, $history);
            if (!$r['trouve'] && $recours) {
                if ($emit) $emit('info', ['message' => 'Question reformulée par le modèle…']);
                $i = AssistantInterprete::demandeur($modele, $question);
                $ajoute($i['usage']);
                // « Créer une demande » n'est retenu que si le message (ou l'objet suggéré) nomme quelque chose de connu ;
                // les autres intentions, que si le message en porte un indice (un petit modèle y range ce qu'il ne comprend pas).
                if ($i['intention'] === 'creer' && !$dem->peutCreer($question, $i['objet'])) $i['intention'] = null;
                if (isset(self::INDICES_DEMANDEUR[$i['intention'] ?? '']) && !preg_match(self::INDICES_DEMANDEUR[$i['intention']], ' ' . AssistantLangue::preparer($question) . ' ')) $i['intention'] = null;
                if ($i['intention'] !== null) {
                    $r2 = $dem->repondre($question, $history, $i['intention'], $i['objet']);
                    if ($r2['trouve']) { $r = $r2; $mode = 'moteur+ia'; }
                }
            }
            $etapes = [];
        } else {
            $moteur = new AssistantMoteur($p['tools']);
            if ($emit) $moteur->setSuivi(function (string $ev, array $d) use ($emit) {
                if ($ev === 'tool_call') $emit('tool_call', ['name' => $d['name'], 'args_summary' => self::resumeArgs($d['args'])]);
                else $emit('tool_result', ['name' => $d['name'], 'size' => (int)$d['size'], 'cached' => false]);
            });
            $r = $moteur->repondre($question, $history);
            if (!$r['trouve'] && $recours && !in_array($r['intention'], ['politesse', 'aide', 'hors_sujet'], true)) {
                if ($emit) $emit('info', ['message' => 'Question reformulée par le modèle…']);
                $i = AssistantInterprete::gestionnaire($modele, $p['tools'], $r['comprehension']);
                $ajoute($i['usage']);
                if ($i['comprehension'] !== null) {
                    $r2 = $moteur->repondre($question, $history, $i['comprehension']);
                    // Une réponse qui ne parle pas de ce que le modèle a compris est hors de propos
                    // (« demandes sur la tondeuse » → la liste de toutes les demandes) : on garde la première.
                    if ($r2['trouve'] && self::pertinente($i['comprehension'], $r2['texte'])) {
                        $r2['texte'] = '🔎 *Question reformulée : ' . AssistantInterprete::resume($i['comprehension']) . "*\n" . $r2['texte'];
                        $r2['etapes'] = array_merge($r['etapes'], $r2['etapes']);
                        $r = $r2; $mode = 'moteur+ia';
                    }
                }
            }
            $etapes = $r['etapes'];
        }
        $usage['ms'] = (int)round((microtime(true) - $t0) * 1000);
        if ($emit) $emit('text', ['delta' => $r['texte']]);
        $c = $r['comprehension'] ?? [];
        return ['reply' => $r['texte'], 'mode' => $mode, 'rounds' => $usage['appels'], 'tool_calls' => $etapes, 'usage' => $usage,
                'comprehension' => array_intersect_key($c, array_flip(['intention', 'type', 'mots', 'numeros', 'codes', 'filtres', 'confiance', 'interprete', 'demande']))];
    }

    /** Indices qu'une intention de demandeur proposée par le modèle est plausible. */
    private const INDICES_DEMANDEUR = [
        'suivi'      => '/\b(?:demandes?|ou en|avanc\w*|suivi\w*|statut|nouvelles?|traite\w*|reponse|pris en charge|mon truc|ma requete|mon ticket|signale\w*)\b/',
        'contacts'   => '/\b(?:qui|contact\w*|appeler|joindre|responsable\w*|interlocuteur\w*|adresser|numero|telephone)\b/',
        'categories' => '/\b(?:categories?|rubriques?|types? de demande|choisir|classer)\b/',
        'roles'      => '/\b(?:roles?|droits?|acces|profils?|permissions?|gestionnaires?|techniciens?|demandeurs?)\b/',
    ];

    /**
     * La réponse reformulée cite-t-elle au moins un des mots que le modèle a
     * retenus (ou un synonyme) ? Sinon le moteur a répondu à côté.
     */
    private static function pertinente(array $n, string $texte): bool
    {
        $mots = $n['mots'] ?? [];
        if (!$mots) return true;
        $hay = ' ' . AssistantLangue::preparer($texte) . ' ';
        foreach ($mots as $m) {
            foreach (array_merge([(string)$m], AssistantLangue::SYNONYMES[$m] ?? []) as $v) {
                $v = AssistantLangue::norm($v);
                $racine = substr($v, 0, max(3, min(5, strlen($v) - 1)));
                if ($racine !== '' && preg_match('/(?<![a-z0-9])' . preg_quote($racine, '/') . '/', $hay)) return true;
            }
        }
        return false;
    }

    /** Résumé court des arguments d'une recherche (affichage « 🔍 search(…) »). */
    public static function resumeArgs(array $args): string
    {
        $parts = [];
        foreach ($args as $k => $v) {
            if (is_array($v)) $v = count($v) . ' items';
            elseif (is_bool($v)) $v = $v ? 'oui' : 'non';
            elseif (is_string($v) && mb_strlen($v) > 30) $v = mb_substr($v, 0, 30) . '…';
            $parts[] = "$k=$v";
        }
        return implode(', ', $parts);
    }
}

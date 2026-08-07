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
 * Larka — Routes : Assistant IA  (avec tool calling + fallback legacy)
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Actions : assistant_status (GET), assistant (POST)
 *
 * ARCHITECTURE :
 *   1. Détection d'intention (regex sur la question) — utile pour le prompt
 *   2. Mode TOOL CALLING (modèles compatibles) :
 *      - Le LLM reçoit la liste des outils (AssistantTools)
 *      - Il appelle 0..N outils, on exécute, on renvoie les résultats
 *      - Max 5 tours, puis on force une réponse texte sans outils
 *   3. Mode LEGACY (fallback automatique) :
 *      - Si le modèle ne supporte pas le tool calling, on déverse les
 *        résultats de searchForAssistant dans le prompt (comportement v1)
 *
 * DÉTECTION TOOL CALLING :
 *   - Anthropic / OpenAI / Mistral : toujours compatible (API standardisée)
 *   - Ollama / LM Studio : dépend du modèle (qwen2.5, llama3.1, mistral-nemo OK)
 *   - Si une erreur "tools not supported" remonte → fallback automatique
 *
 * SUPPORTE LE DEMANDEUR : pas de tool calling pour les Demandeurs, ils gardent
 * l'ancien comportement (contexte limité, RGPD strict).
 * ═══════════════════════════════════════════════════════════════════════════════
 */

require_once __DIR__ . '/../AssistantTools.php';

if ($action === 'assistant_status') {
    $user = require_auth();
    $actif = (bool)(cfg('assistant', 'actif') ?? false);
    $apiKeySet = !empty(cfg('assistant', 'api_key')) && strlen(cfg('assistant', 'api_key') ?? '') > 5;
    $fournisseur = cfg('assistant', 'fournisseur') ?? 'anthropic';
    $localProviders = ['ollama', 'lmstudio', 'local'];
    $keyOk = $apiKeySet || in_array($fournisseur, $localProviders);
    json_ok(['actif' => $actif && $keyOk, 'fournisseur' => $fournisseur]);
}

if ($action === 'assistant') {
    $ctx = _assistantValidateAndSetup();
    extract($ctx);

    // Libère le verrou de session PHP (même logique que 'assistant_stream') :
    // sans ça, pendant les 30-120 s d'une génération locale, TOUTES les autres
    // requêtes du même utilisateur (navigation, badges, autres onglets) sont
    // bloquées en attente du verrou. On a déjà lu tout ce qu'il fallait.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    // ─────────────────────────────────────────────────────────────
    // PROMPT DEMANDEUR : pas de tool calling, ancien comportement
    // ─────────────────────────────────────────────────────────────
    if ($isDemandeur) {
        $result = handleDemandeur($db, $user, $question, $history, $intents, $fournisseur, $model, $apiUrl, $apiKey, $localProviders);
        json_ok($result);
    }

    // ─────────────────────────────────────────────────────────────
    // PROMPT GESTIONNAIRE : tool calling avec fallback
    // ─────────────────────────────────────────────────────────────
    $systemPrompt = buildGestionnaireSystemPrompt($userName, $user['Role'] ?? '', $intents, $question);

    $useToolCalling = supportsToolCalling($fournisseur, $model);

    if ($useToolCalling) {
        $result = runToolCallingLoop(
            $tools, $systemPrompt, $question, $history,
            $fournisseur, $model, $apiUrl, $apiKey, $localProviders
        );
        // Si le résultat indique un fallback nécessaire, on rebascule en legacy
        if (isset($result['_fallback_needed'])) {
            $result = runLegacyMode(
                $db, $systemPrompt, $question, $history,
                $fournisseur, $model, $apiUrl, $apiKey, $localProviders, $intents
            );
            $result['mode'] = 'legacy-fallback';
        } else {
            $result['mode'] = 'tool_calling';
        }
    } else {
        $result = runLegacyMode(
            $db, $systemPrompt, $question, $history,
            $fournisseur, $model, $apiUrl, $apiKey, $localProviders, $intents
        );
        $result['mode'] = 'legacy';
    }

    $result['fournisseur'] = $fournisseur;
    $result['model']       = $model;
    $result['intents']     = $intents;
    json_ok($result);
}

// ═════════════════════════════════════════════════════════════════════
// ── Route STREAMING (Server-Sent Events) ──
// ═════════════════════════════════════════════════════════════════════
//
// Même logique que 'assistant' mais le tour FINAL (celui qui produit le texte
// sans tool call) est streamé token par token vers le client.
//
// Format SSE :
//   data: {"type":"tool_call","name":"search","args":{...}}\n\n
//   data: {"type":"tool_result","name":"search","size":3}\n\n
//   data: {"type":"text","delta":"Voici "}\n\n
//   data: {"type":"text","delta":"les résultats..."}\n\n
//   data: {"type":"done","mode":"tool_calling","rounds":2}\n\n
//   data: {"type":"error","message":"..."}\n\n   (en cas de souci)
//
// Les Demandeurs et le mode legacy NE sont PAS streamés (pas de gain notable).
// ─────────────────────────────────────────────────────────────────────
if ($action === 'assistant_stream') {
    $ctx = _assistantValidateAndSetup();
    extract($ctx);

    // Libère le verrou de session PHP : sinon, pendant que ce stream tourne
    // (potentiellement 10s+), les autres requêtes du même utilisateur sont
    // bloquées en attente. On a déjà lu tout ce qu'il fallait dans setup().
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    // Préparer la réponse SSE
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-transform');
    header('X-Accel-Buffering: no'); // désactive le buffering Nginx
    header('Connection: keep-alive');
    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', '0');
    @ini_set('implicit_flush', '1');

    $sseSend = function(string $type, array $extra = []) {
        echo "data: " . json_encode(['type' => $type] + $extra, JSON_UNESCAPED_UNICODE) . "\n\n";
        @ob_flush(); @flush();
    };

    // Demandeur ou modèle sans tool calling → on stream juste la réponse finale
    // (un seul appel LLM, pas de boucle tool)
    if ($isDemandeur || !supportsToolCalling($fournisseur, $model)) {
        if ($isDemandeur) {
            $userName2 = trim(($user['Prenom'] ?? '') . ' ' . ($user['Nom'] ?? ''));
            $systemPrompt = buildDemandeurSystemPrompt($db, $user, $userName2, $intents);
        } else {
            $systemPrompt = buildGestionnaireSystemPrompt($userName, $user['Role'] ?? '', $intents, $question);
        }
        $messages = [];
        foreach ($history as $h) {
            if (isset($h['role'], $h['content'])) $messages[] = ['role'=>$h['role'],'content'=>$h['content']];
        }
        $messages[] = ['role' => 'user', 'content' => $question];
        streamFinalAnswer($systemPrompt, $messages, $fournisseur, $model, $apiUrl, $apiKey, in_array($fournisseur, $localProviders, true), $sseSend);
        $sseSend('done', ['mode' => $isDemandeur ? 'demandeur' : 'legacy', 'fournisseur' => $fournisseur]);
        exit;
    }

    // Mode tool calling : on fait les tours normalement (non streamés) et on stream le dernier
    $systemPrompt = buildGestionnaireSystemPrompt($userName, $user['Role'] ?? '', $intents, $question);
    $result = runToolCallingLoopWithSSE(
        $tools, $systemPrompt, $question, $history,
        $fournisseur, $model, $apiUrl, $apiKey, $localProviders, $sseSend
    );

    // Fallback legacy si le tool calling a échoué
    if (isset($result['_fallback_needed'])) {
        $sseSend('info', ['message' => 'Tool calling non supporté, fallback legacy']);
        $result = runLegacyMode(
            $db, $systemPrompt, $question, $history,
            $fournisseur, $model, $apiUrl, $apiKey, $localProviders, $intents
        );
        // Mode legacy : pas streamé, on envoie la réponse en bloc
        $sseSend('text', ['delta' => $result['reply'] ?? '']);
        $sseSend('done', ['mode' => 'legacy-fallback', 'fournisseur' => $fournisseur]);
        exit;
    }

    $sseSend('done', [
        'mode' => 'tool_calling',
        'fournisseur' => $fournisseur,
        'model' => $model,
        'rounds' => $result['rounds'] ?? null,
        'tool_calls_count' => count($result['tool_calls'] ?? []),
    ]);
    exit;
}

/**
 * Validation + setup commun aux routes 'assistant' et 'assistant_stream'.
 * Renvoie un array de variables à extract() dans le scope appelant.
 * Termine la requête (json_error) en cas de problème de config.
 */
function _assistantValidateAndSetup(): array {
    global $db, $method;
    $user = require_auth();
    if ($method !== 'POST') json_error('Méthode non supportée.', 405);
    if (!(bool)(cfg('assistant', 'actif') ?? false)) json_error('L\'assistant IA est désactivé.', 403);

    check_rate_limit('assistant_' . ($user['Id'] ?? 0), 20, 300);

    $b = get_body();
    $question = trim($b['question'] ?? '');
    if (!$question) json_error('Question vide.', 400);
    if (mb_strlen($question) > 2000) json_error('Question trop longue (max 2000 caractères).', 400);
    $history = $b['history'] ?? [];
    if (!is_array($history)) $history = [];
    $history = array_slice($history, -20);

    $fournisseur = strtolower(cfg('assistant', 'fournisseur') ?? 'anthropic');

    // Providers locaux (CPU) : le traitement du prompt est le goulot d'étranglement
    // (~20-50 tok/s). On réduit l'historique à 8 messages pour diviser la latence,
    // sans perte notable de contexte conversationnel. Cloud : on garde 20.
    if (in_array($fournisseur, ['ollama','lmstudio','local'], true)) {
        $history = array_slice($history, -8);
    }
    $apiKey      = cfg('assistant', 'api_key') ?? '';
    $model       = cfg('assistant', 'model') ?? '';
    $apiUrl      = cfg('assistant', 'api_url') ?? '';

    $defaults = [
        'anthropic' => ['url'=>'https://api.anthropic.com/v1/messages',      'model'=>'claude-sonnet-4-20250514'],
        'openai'    => ['url'=>'https://api.openai.com/v1/chat/completions', 'model'=>'gpt-4o-mini'],
        'mistral'   => ['url'=>'https://api.mistral.ai/v1/chat/completions','model'=>'mistral-small-latest'],
        'copilot'   => ['url'=>'https://api.githubcopilot.com/chat/completions','model'=>'gpt-4o'],
        'ollama'    => ['url'=>'http://localhost:11434/v1/chat/completions', 'model'=>'qwen2.5'],
        'lmstudio'  => ['url'=>'http://localhost:1234/v1/chat/completions',  'model'=>'local-model'],
        'local'     => ['url'=>'http://localhost:8080/v1/chat/completions',  'model'=>'local-model'],
    ];
    if (!$apiUrl) $apiUrl = $defaults[$fournisseur]['url'] ?? $defaults['anthropic']['url'];
    if (!$model)  $model  = $defaults[$fournisseur]['model'] ?? 'claude-sonnet-4-20250514';

    $localProviders = ['ollama','lmstudio','local'];
    if (!in_array($fournisseur, $localProviders) && (!$apiKey || strlen($apiKey) < 5)) {
        json_error("Clé API non configurée pour \"$fournisseur\".", 400);
    }

    // Validation SSRF (host whitelist + HTTPS pour cloud)
    $allowedHostsByProvider = [
        'anthropic' => ['api.anthropic.com'],
        'openai'    => ['api.openai.com'],
        'mistral'   => ['api.mistral.ai'],
        'copilot'   => ['api.githubcopilot.com', 'api.github.com'],
    ];
    $parsedUrl = parse_url($apiUrl);
    if (!$parsedUrl || empty($parsedUrl['host']) || empty($parsedUrl['scheme'])) json_error("URL d'API invalide.", 400);
    $scheme = strtolower($parsedUrl['scheme']);
    $host   = strtolower($parsedUrl['host']);
    if (!in_array($scheme, ['http','https'], true)) json_error("Schéma d'URL non autorisé : $scheme.", 400);
    if (in_array($fournisseur, $localProviders, true)) {
        $isLocalHost = in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true);
        $isPrivateIp = false;
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $isPrivateIp = !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }
        $isLocalDomain = (bool)preg_match('/\.(local|lan|internal|home)$/i', $host);
        if (!$isLocalHost && !$isPrivateIp && !$isLocalDomain) json_error("Host non autorisé pour provider local : $host.", 400);
    } elseif (isset($allowedHostsByProvider[$fournisseur])) {
        if (!in_array($host, $allowedHostsByProvider[$fournisseur], true)) json_error("Host non autorisé pour $fournisseur : $host.", 400);
        if ($scheme !== 'https') json_error("HTTPS requis pour $fournisseur.", 400);
    } else {
        json_error("Provider non reconnu : $fournisseur.", 400);
    }

    // Détection d'intention
    $ql = mb_strtolower($question);
    $intentRegexes = [
        'localisation'  => '/\b(où|ou se trouve|ou est|ou sont|localisation|emplacement|situé|étage|bâtiment|bureau|local)\b/u',
        'identification'=> '/\b(quel(le|s|les)?|qu\'est-ce|c\'est quoi|nature|type de|marque|modèle)\b/u',
        'personne'      => '/\b(qui|responsable|gestionnaire|contact|prestataire|fournisseur|société)\b/u',
        'temporel'      => '/\b(quand|date|échéance|expire|prochain|dernier|cette année|ce mois)\b/u',
        'quantite'      => '/\b(combien|nombre|total|montant|coût|prix|quantité)\b/u',
        'statut'        => '/\b(état|statut|en cours|terminé|en retard|en attente|où en est|avancement)\b/u',
        'liste'         => '/\b(liste|lister|tous les|toutes les|donne moi|énumère)\b/u',
        'surface'       => '/\b(surface|superficie|m²|m2|mètre carré|aire)\b/u',
        'alertes'       => '/\b(alerte|urgent|expire|retard|critique|à traiter)\b/u',
    ];
    $intents = [];
    foreach ($intentRegexes as $name => $regex) {
        if (preg_match($regex, $ql)) $intents[] = $name;
    }
    if (empty($intents)) $intents[] = 'recherche_generale';

    // Token Microsoft Graph : capturé ICI car les routes appelantes font
    // session_write_close() juste après le setup. S'il est expiré (ou expire
    // dans <60 s), on ne l'expose pas — l'outil SharePoint sera simplement
    // absent de la liste proposée au modèle.
    $msToken = null;
    if (defined('SHAREPOINT_ENABLED') && SHAREPOINT_ENABLED
        && !empty($_SESSION['ms_access_token'])
        && (int)($_SESSION['ms_token_expires'] ?? 0) > time() + 60) {
        $msToken = $_SESSION['ms_access_token'];
    }

    $tools = new AssistantTools($db, $msToken);
    $userName = trim(($user['Prenom'] ?? '') . ' ' . ($user['Nom'] ?? ''));
    $isDemandeur = ($user['Role'] ?? '') === 'Demandeur';

    return compact(
        'user','question','history','fournisseur','apiKey','model','apiUrl',
        'localProviders','intents','tools','userName','isDemandeur'
    );
}

// ════════════════════════════════════════════════════════════════════
// ── Helpers ──
// ════════════════════════════════════════════════════════════════════

/**
 * Paramètres de sampling déterministes — garantissent une cohérence inter-modèles.
 *
 * Pour des questions factuelles Larka (où / combien / quels contrats...), on veut
 * UNE et UNE SEULE bonne réponse. Pas de créativité, pas de variations entre runs.
 *
 * Override possible via la config BDD (cfg('assistant', 'temperature') etc.) pour
 * les cas spécifiques. Par défaut : très basse température + seed fixe sur Ollama.
 *
 * Retourne ['temperature'=>float, 'top_p'=>float, 'seed'=>int|null].
 */
function _getSamplingParams(): array {
    $temp = cfg('assistant', 'temperature');
    $topP = cfg('assistant', 'top_p');
    $seed = cfg('assistant', 'seed');
    return [
        'temperature' => is_numeric($temp) ? (float)$temp : 0.2,
        'top_p'       => is_numeric($topP) ? (float)$topP : 0.9,
        // null => on n'envoie pas de seed (Anthropic/OpenAI ne l'acceptent pas
        // dans /v1/chat/completions standard). Pour Ollama on force 42 par défaut
        // car c'est là que le seed a vraiment un effet déterministe.
        'seed'        => is_numeric($seed) ? (int)$seed : null,
    ];
}

/** Détecte si un modèle/provider supporte le tool calling.
 *
 * Cloud : toujours OK (API standardisée).
 * Local (Ollama/LM Studio) :
 *   1. On regarde d'abord le cache disque (résultat sonde précédente, 24h).
 *   2. Si pas de cache, on tente une whitelist par nom (modèles connus).
 *   3. Sinon on lance probeToolCalling() qui fait un mini appel test.
 *
 * Le cache évite de faire la sonde à chaque question — gros gain de latence.
 */
function supportsToolCalling(string $fournisseur, string $model): bool {
    // Cloud : toujours OK
    if (in_array($fournisseur, ['anthropic','openai','mistral','copilot'], true)) return true;

    // 1) Cache disque (24h)
    $cached = _toolCallCacheGet($fournisseur, $model);
    if ($cached !== null) return $cached;

    // 2) Whitelist rapide (évite la sonde pour les modèles ultra-connus)
    $modelLower = strtolower($model);
    $whitelisted = ['qwen2.5','qwen2','qwen3','llama3.1','llama3.2','llama3.3',
                    'mistral-nemo','command-r','firefunction','functionary',
                    'hermes3','granite3','mistral-small','mistral-large',
                    'ministral'];
    foreach ($whitelisted as $c) {
        if (str_contains($modelLower, $c)) {
            _toolCallCacheSet($fournisseur, $model, true);
            return true;
        }
    }

    // 3) Blacklist connue (modèles trop petits ou non-instruct)
    $blacklisted = ['phi3:mini','tinyllama','orca-mini','gemma:2b','gemma2:2b'];
    foreach ($blacklisted as $b) {
        if (str_contains($modelLower, $b)) {
            _toolCallCacheSet($fournisseur, $model, false);
            return false;
        }
    }

    // 4) Sonde active : on appelle vraiment le modèle avec un tool factice.
    //    Coûte ~1s la première fois, mais le résultat est mis en cache 24h.
    $apiUrl = cfg('assistant', 'api_url') ?? '';
    $apiKey = cfg('assistant', 'api_key') ?? '';
    $defaults = [
        'ollama'   => 'http://localhost:11434/v1/chat/completions',
        'lmstudio' => 'http://localhost:1234/v1/chat/completions',
        'local'    => 'http://localhost:8080/v1/chat/completions',
    ];
    if (!$apiUrl) $apiUrl = $defaults[$fournisseur] ?? '';
    if (!$apiUrl) {
        // Pas d'URL → on ne peut pas sonder, on retourne false par sécurité (legacy)
        return false;
    }

    $supports = _probeToolCalling($apiUrl, $apiKey, $model);
    _toolCallCacheSet($fournisseur, $model, $supports);
    return $supports;
}

/**
 * Sonde : envoie un mini appel avec un tool bidon et regarde si le modèle
 * renvoie un format tool_calls valide. Très rapide (max 5s).
 */
function _probeToolCalling(string $apiUrl, string $apiKey, string $model): bool {
    $payload = [
        'model'      => $model,
        'max_tokens' => 50,
        'messages'   => [
            ['role' => 'system', 'content' => 'Réponds en appelant l\'outil ping.'],
            ['role' => 'user',   'content' => 'ping'],
        ],
        'tools' => [[
            'type' => 'function',
            'function' => [
                'name' => 'ping',
                'description' => 'Test ping/pong',
                'parameters' => [
                    'type' => 'object',
                    'properties' => (object)[],
                    'required' => [],
                ],
            ],
        ]],
        'tool_choice' => 'auto',
        'temperature' => 0,
    ];
    $headers = ['Content-Type: application/json'];
    if ($apiKey) $headers[] = 'Authorization: Bearer ' . $apiKey;

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,           // sonde rapide, on ne bloque pas
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => false, // local
    ]);
    $raw = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $http >= 400) return false;
    $data = json_decode($raw, true);
    if (!is_array($data)) return false;

    // Le modèle supporte le tool calling SI :
    //   - HTTP 200 (n'a pas planté sur le param "tools")
    //   - ET soit il a réellement appelé un tool, soit il a au moins reconnu le schéma
    //     (réponse texte sans erreur). On accepte les deux car certains modèles refusent
    //     d'appeler un tool sur "ping" mais ils sauraient le faire sur une vraie question.
    $msg = $data['choices'][0]['message'] ?? null;
    if (!is_array($msg)) return false;

    // S'il y a un tool_calls → confirmé
    if (!empty($msg['tool_calls']) && is_array($msg['tool_calls'])) return true;

    // Sinon : on regarde si la réponse texte ne contient pas d'erreur typique
    // ("tools not supported", "unknown parameter"...). Si pas d'erreur → on suppose
    // que le modèle accepte le paramètre 'tools' et qu'il décidera d'appeler ou non.
    $text = strtolower($msg['content'] ?? '');
    $errorMarkers = ['not support', 'unknown', 'invalid parameter', 'unsupported'];
    foreach ($errorMarkers as $em) {
        if (str_contains($text, $em)) return false;
    }
    return true; // pas d'erreur → considéré compatible
}

/** Chemin du fichier cache (un fichier par fournisseur, sérialisé en JSON). */
function _toolCallCachePath(string $fournisseur): string {
    $dir = __DIR__ . '/../../data/cache_assistant';
    if (!is_dir($dir)) @mkdir($dir, 0770, true);
    return $dir . '/tool_calling_' . preg_replace('/[^a-z0-9]/i', '', $fournisseur) . '.json';
}

/** Récupère true/false du cache, ou null si pas en cache ou expiré. */
function _toolCallCacheGet(string $fournisseur, string $model): ?bool {
    $path = _toolCallCachePath($fournisseur);
    if (!is_file($path)) return null;
    $data = json_decode((string)@file_get_contents($path), true);
    if (!is_array($data)) return null;
    $entry = $data[$model] ?? null;
    if (!is_array($entry)) return null;
    // TTL 24h
    if ((time() - (int)($entry['ts'] ?? 0)) > 86400) return null;
    return (bool)$entry['supports'];
}

/** Enregistre le résultat de la sonde pour ce modèle. */
function _toolCallCacheSet(string $fournisseur, string $model, bool $supports): void {
    $path = _toolCallCachePath($fournisseur);
    $data = is_file($path) ? json_decode((string)@file_get_contents($path), true) : [];
    if (!is_array($data)) $data = [];
    $data[$model] = ['supports' => $supports, 'ts' => time()];
    @file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

/** Construit le prompt système pour les gestionnaires (mode tool calling). */
function buildGestionnaireSystemPrompt(string $userName, string $role, array $intents, string $question): string {
    $intentList = implode(', ', $intents);
    $today = date('Y-m-d');

    // ── Prompt CONDITIONNEL selon l'intention ─────────────────────────────────
    // Sur un modèle local CPU, le traitement du prompt est le goulot (~20-50 tok/s) :
    // le prompt complet (~2 700 tokens) coûtait 1 à 2 min AVANT le premier token.
    // On n'injecte donc que les sections « MÉTHODE » et les exemples pertinents
    // pour l'intention détectée. Si aucune intention précise (recherche_generale),
    // on garde le prompt complet (comportement historique, aucun risque de régression).
    $all = in_array('recherche_generale', $intents, true);
    $has = fn(string $i): bool => $all || in_array($i, $intents, true);

    // Les éléments graphiques de plan ne sont pas couverts par les regex d'intention :
    // détection par mots-clés dédiée.
    $ql = mb_strtolower($question);
    $mentionsPlans = (bool)preg_match('/\b(points?|zones?|traits?|textes?|plans?|calques?)\b/u', $ql);

    $p = <<<PROMPT
Tu es l'assistant IA expert de Larka. Tu réponds à un GESTIONNAIRE.
Utilisateur : {$userName} (rôle: {$role}). Date du jour : {$today}.
Intention détectée : {$intentList}. Question : « {$question} »

═══ PRINCIPE FONDAMENTAL ═══
Tu es un assistant FACTUEL. Toute affirmation sur une donnée précise (ID, nom, lieu, date,
quantité, statut, prix, échéance) DOIT venir d'un appel d'outil de ce tour-ci.
Si une donnée n'est pas dans un résultat d'outil → tu ne la mentionnes PAS.
Si tu hésites entre deux interprétations → tu listes les options, tu ne choisis pas pour l'utilisateur.

═══ RÈGLES STRICTES ═══
1. INTERDIT d'inventer ou de deviner : ID, nom, statut, localisation, date, montant, contact.
2. INTERDIT de dire « il n'y a pas » ou « aucun » SANS avoir appelé un outil qui l'a confirmé.
3. INTERDIT de réutiliser un ID vu dans l'historique de conversation : refais l'appel.
4. INTERDIT de spéculer sur une donnée personnelle anonymisée ([personne], [email], [téléphone]).
5. OBLIGATOIRE pour « où se trouve X » : appel 'localiser' (PAS juste 'search').
6. OBLIGATOIRE quand un outil renvoie plusieurs candidats homonymes : demander à l'utilisateur
   de préciser, OU lister tous les candidats avec leurs fiches.

═══ TES OUTILS ═══
- search(type, query, filtres?) : recherche par mots-clés. Types : biens, equipements,
  interventions, contrats, demandes, stock, documents, plans, archives.
- get_fiche(type, id) : détail complet d'UNE fiche (après search).
- localiser(type, id) : où est UN élément ? bâtiment + étage + position sur plan.
- localiser_groupe(type, ids[]) : positionner PLUSIEURS éléments en UN appel.
- compter(type, filtres?) : combien d'éléments correspondant aux filtres.
- alertes(categorie?, jours?) : contrats expirant, stock alerte, retards.
- contexte() : vocabulaire métier (bâtiments, familles, catégories) — utile si tu hésites
  sur les noms locaux.
- qui_est(query?, role?, id?) : annuaire des utilisateurs (nom, service, poste, tel pro).
  Sert à répondre « qui est le demandeur de la demande #N », « qui contacter au service
  X », « le téléphone pro de Jean Dupont ». Pour relier une demande à son demandeur :
  d'abord get_fiche(demande, N) → champ UtilisateurId → puis qui_est(id=UtilisateurId).
- search_sharepoint(query) : SI DISPONIBLE dans ta liste d'outils — documents stockés
  sur SharePoint/OneDrive (Microsoft 365), qui ne sont PAS dans Larka. À utiliser :
  quand search(type='documents') ne trouve rien, ou quand l'utilisateur mentionne
  SharePoint/OneDrive. Chaque résultat trouvé se cite avec le format STRICT :
  [SPDOC:<url>|<nom du fichier>]   (l'url vient EXACTEMENT du champ 'url' du résultat).

PROMPT;

    if ($has('localisation')) {
        $p .= <<<'PROMPT'
═══ MÉTHODE POUR « OÙ SE TROUVE X ? » ═══
Étape 1 : search(type='equipements' ou 'biens', query='X').
Étape 2 : examiner les résultats :
  - 0 résultat → essayer 'contexte()' pour les familles connues, puis dire qu'on n'a rien trouvé.
  - 1 résultat → localiser(type, id=<ID exact retourné>).
  - 2+ résultats nettement différents (différents noms/numéros) → DEMANDER à l'utilisateur
    de préciser, en listant les candidats avec [FICHE:type:id:label].
  - 2+ résultats du même type (ex: « tous les BAES ») → localiser_groupe avec TOUS les IDs.
Étape 3 : lire le résultat de localiser :
  - 'sur_plan' (liste de positions graphiques) = source PRIORITAIRE de localisation.
  - 'batiment', 'etage', 'bureau' = champs texte de la fiche, à utiliser si 'sur_plan' est vide.
  - Si TOUT est vide → dire « localisation non renseignée pour cet élément ».
Étape 4 : formuler la réponse avec le lien [FICHE:plan:EtageId:ElementId:label] correct.

PROMPT;
    }

    if ($mentionsPlans || $has('surface')) {
        $p .= <<<'PROMPT'
═══ MÉTHODE POUR « LES POINTS / ZONES / TRAITS SUR LE PLAN » ═══
Ces mots désignent les éléments DESSINÉS sur les plans (TypeElement = 'point', 'zone',
'trait', 'texte'). Selon la demande :
  • Pour COMPTER (« combien de points… ») :
    → compter(type='plans', filtres={type_element: 'point'})
    (idem pour 'zone', 'trait', 'texte' selon la demande)
  • Pour LISTER ou AFFICHER :
    → search(type='plans', query='point')   (ou 'zone', 'trait', 'texte')
Les résultats de search contiennent { Id, TypeElement, Nom, EtageId, EtageNom, BatimentNom, ... }.
- N'appelle PAS 'localiser' (qui est pour les biens/équipements, pas les éléments graphiques).
- Si search renvoie pile 25 entrées, c'est la LIMITE de search :
  dis « au moins 25 » et propose d'affiner — ou plutôt appelle 'compter' qui n'a pas cette limite.
- Pour afficher sur le plan : groupe les Ids par EtageId puis génère UN lien
  [FICHE:plans:EtageId:Id1,Id2,Id3:Surligner] par étage.
- Le mot seul « les points » dans Larka renvoie aux marqueurs dessinés, PAS
  aux points GPS, points d'eau, etc.

PROMPT;
    }

    if ($has('quantite')) {
        $p .= <<<'PROMPT'
═══ MÉTHODE POUR « COMBIEN DE X ? » ═══
Toujours utiliser 'compter' avec des filtres si pertinent. Ne JAMAIS estimer.
Les colonnes filtrables sont limitées :
- Biens/Équipements : Famille, Batiment, Etage, Etat, Categorie, Marque, TypeBien/TypeEquipement
- Interventions/Demandes : Statut, Type, Categorie, Priorite, Urgence, Batiment, annee
- Contrats : Statut, Societe, Type, Categorie, annee
- Stock : Categorie, Famille, Emplacement
- Plans : type_element (point/zone/trait/texte), calque, etage_id
Toute autre clé est ignorée silencieusement.

PROMPT;
    }

    if ($has('liste')) {
        $p .= <<<'PROMPT'
═══ MÉTHODE POUR « LISTE LES X » ═══
Si la liste fait <= 10 éléments : tous les citer avec leur lien [FICHE:...].
Si >10 et <= 30 : citer les 10 plus pertinents et indiquer le total + « N autres ».
Si >30 (limite de search) : indiquer « 30+ résultats, affinez avec des filtres ».

PROMPT;
    }

    $p .= <<<'PROMPT'
═══ FORMAT DE RÉPONSE ═══
- Français, court (1 à 5 lignes selon la complexité), pas de circonlocution.
- Mettre en **gras** les chiffres-clés et noms propres.
- Chaque élément cité = un lien cliquable. Format STRICT :
  • [FICHE:bien:ID:label]
  • [FICHE:equipement:ID:label]
  • [FICHE:intervention:ID:label]
  • [FICHE:contrat:ID:label]
  • [FICHE:demande:ID:label]
  • [FICHE:stock:ID:label]
  • [FICHE:plan:EtageId:ElementId:label]      ← UN élément précis (sera mis en surbrillance)
  • [FICHE:plan:EtageId:label]                ← étage entier (pas de surbrillance ciblée)
  • [FICHE:plans:EtageId:Id1,Id2,Id3:label]   ← PLUSIEURS éléments (tous mis en surbrillance)
    Les IDs sont séparés par des virgules SANS espace. Tous du même étage.
    Pour plusieurs étages : un lien [FICHE:plans:...] par étage.
- Les IDs utilisés DOIVENT venir EXACTEMENT des résultats d'outil (champ "Id" ou "ElementId").
  Ne jamais incrémenter, deviner ou réordonner.
- Si rien trouvé : dis-le clairement, suggère 1-2 reformulations.
- N'inscris pas le format brut dans la réponse comme « j'ai appelé search avec... » : 
  l'utilisateur ne veut pas la trace, juste le résultat.

═══ EXEMPLES ═══

PROMPT;

    if ($has('localisation')) {
        $p .= <<<'PROMPT'
Q : « Où se trouve la centrale SSI ? »
→ search(type='equipements', query='centrale SSI') → 1 résultat id=42
→ localiser(type='equipement', id=42)
   → sur_plan = [{BatimentNom:'Bât A', EtageNom:'RDC', EtageId:3, ElementId:8}]
→ « La centrale SSI est dans le **Bâtiment A, RDC**.
     [FICHE:equipement:42:Centrale SSI] · [FICHE:plan:3:8:Voir sur le plan] »

Q : « Où est la pompe ? » (ambigu)
→ search(type='equipements', query='pompe') → 3 résultats id=12,15,28 (3 pompes différentes)
→ « J'ai trouvé **3 pompes** dans Larka :
     • [FICHE:equipement:12:Pompe relevage local technique]
     • [FICHE:equipement:15:Pompe doseuse bassin]
     • [FICHE:equipement:28:Pompe à chaleur extérieure]
     Laquelle vous intéresse ? »

Q : « Où sont tous les BAES du bâtiment A ? »
→ search(type='equipements', query='BAES bâtiment A') → 12 résultats
→ localiser_groupe(type='equipement', ids=[88,91,103,105,110,...])
   → regroupement_par_etage = [
     {EtageId:3, EtageNom:'RDC', ElementIds:[120,125,130]},
     {EtageId:4, EtageNom:'1er', ElementIds:[140,145,150]} ]
→ « **12 BAES** dans le Bâtiment A, sur 2 étages :
     • **RDC** : 3 BAES — [FICHE:plans:3:120,125,130:Surligner sur le plan]
     • **1er** : 3 BAES — [FICHE:plans:4:140,145,150:Surligner sur le plan] »

PROMPT;
    }

    if ($has('quantite')) {
        $p .= <<<'PROMPT'
Q : « Combien d'interventions cette année ? »
→ compter(type='interventions', filtres={annee:'<ANNEE_EN_COURS>'}) → {count: 47}
→ « **47 interventions** cette année. »

PROMPT;
    }

    if ($has('temporel') || $has('alertes')) {
        $p .= <<<'PROMPT'
Q : « Quels contrats expirent ? »
→ alertes(categorie='contrats', jours=60)
→ « **3 contrats** arrivent à échéance dans les 60 jours :
     • [FICHE:contrat:11:Ascenseurs Otis] — 12/06
     • [FICHE:contrat:18:Désenfumage Cerber] — 28/06
     • [FICHE:contrat:22:Climatisation Daikin] — 05/07 »

PROMPT;
    }

    if ($mentionsPlans) {
        $p .= <<<'PROMPT'
Q : « Combien de points sur les plans ? »
→ compter(type='plans', filtres={type_element: 'point'}) → {count: 47}
→ « **47 points** dessinés sur les plans. »
(Pour COMPTER, utilise toujours 'compter' — pas de limite de 25/30.)

Q : « Montre-moi les points » / « Liste les points des plans »
→ search(type='plans', query='point')
   → 12 résultats du type 'point', certains du Bât A RDC (EtageId=3),
     d'autres du Bât A 1er (EtageId=4)
→ « **12 points** dessinés sur les plans :
     • **Bât A — RDC** : 5 points — [FICHE:plans:3:101,102,103,104,105:Surligner]
     • **Bât A — 1er** : 7 points — [FICHE:plans:4:120,121,122,123,124,125,126:Surligner] »
(Si search renvoie pile 25 entrées = limite atteinte → préviens et propose
 d'affiner par bâtiment, OU bascule sur 'compter' pour avoir le vrai total.)

PROMPT;
    }

    if ($has('personne')) {
        $p .= <<<'PROMPT'
Q : « Qui est le demandeur de la demande #42 ? »
→ get_fiche(type='demande', id=42) → champ UtilisateurId = 17
→ qui_est(id=17) → { Nom:'Jean Dupont', Service:'Finances', TelPro:'01.23.45.67.89', Poste:'Comptable' }
→ « La demande #42 a été créée par **Jean Dupont** (Finances, Comptable).
     Téléphone pro : 01.23.45.67.89. [FICHE:demande:42:Voir la demande] »

Q : « Comment joindre les gestionnaires du service technique ? »
→ qui_est(query='technique', role='Gestionnaire')
→ « **2 gestionnaires** au service technique :
     • Marie Lefèvre — 01.23.45.67.90 (Responsable maintenance)
     • Paul Martin — 01.23.45.67.91 (Chef de service) »

PROMPT;
    }

    $p .= <<<'PROMPT'
Q : « Tu connais le chiffre d'affaires de la société ? »  (hors Larka)
→ « Cette information n'est pas dans Larka. Je peux uniquement répondre sur le
     parc, les interventions, contrats, stocks et documents enregistrés ici. »
PROMPT;

    return $p;
}

/** Boucle de tool calling : envoie la question, exécute les tools, jusqu'à réponse finale. */
function runToolCallingLoop(
    AssistantTools $tools, string $systemPrompt, string $question, array $history,
    string $fournisseur, string $model, string $apiUrl, string $apiKey, array $localProviders
): array {
    // Chaque tour = une inférence complète. Sur CPU local, 5 tours peuvent coûter
    // plusieurs minutes → on borne à 3 pour les providers locaux (le fallback
    // « réponds maintenant sans outil » prend le relais si besoin). Cloud : 5.
    $maxRounds = in_array($fournisseur, $localProviders, true) ? 3 : 5;
    $totalToolCalls = 0;
    $toolCallsLog = [];
    // Cache : signature `name:json_args` → résultat. Évite que les modèles
    // thinking refassent 3× le même appel (gros gain de latence Ollama notamment).
    $callCache = [];

    // Messages : format unifié OpenAI-like (sera converti pour Anthropic plus bas)
    $messages = [];
    foreach ($history as $h) {
        if (isset($h['role'], $h['content'])) {
            $messages[] = ['role' => $h['role'], 'content' => $h['content']];
        }
    }
    $messages[] = ['role' => 'user', 'content' => $question];

    for ($round = 0; $round < $maxRounds; $round++) {
        $resp = callLLM($systemPrompt, $messages, $tools, $fournisseur, $model, $apiUrl, $apiKey, $localProviders);

        if (isset($resp['_error'])) {
            // Si erreur "tools not supported", signaler fallback
            $err = strtolower($resp['_error']);
            if (str_contains($err, 'tool') && (str_contains($err, 'not support') || str_contains($err, 'unknown') || str_contains($err, 'invalid'))) {
                return ['_fallback_needed' => true, 'reason' => $resp['_error']];
            }
            return ['reply' => '❌ Erreur API : ' . $resp['_error'], 'tool_calls' => $toolCallsLog];
        }

        // Pas de tool call → réponse finale
        if (empty($resp['tool_calls'])) {
            return [
                'reply' => $resp['text'] ?? '',
                'tool_calls' => $toolCallsLog,
                'rounds' => $round + 1,
            ];
        }

        // Exécuter chaque tool call (avec dédup)
        $messages[] = ['role' => 'assistant', 'content' => $resp['text'] ?? '', 'tool_calls' => $resp['tool_calls']];
        foreach ($resp['tool_calls'] as $tc) {
            $name = $tc['name'] ?? '';
            $args = is_array($tc['args'] ?? null) ? $tc['args'] : [];
            // Signature stable : on trie les clés pour que {a:1,b:2} === {b:2,a:1}
            $argsSorted = $args;
            ksort($argsSorted);
            $sig = $name . ':' . md5(json_encode($argsSorted, JSON_UNESCAPED_UNICODE));
            if (isset($callCache[$sig])) {
                $result = $callCache[$sig];
                $cached = true;
            } else {
                $result = $tools->execute($name, $args);
                $callCache[$sig] = $result;
                $cached = false;
            }
            $totalToolCalls++;
            $toolCallsLog[] = [
                'name' => $name,
                'args' => $args,
                'result_size' => is_array($result) ? count($result) : 0,
                'cached' => $cached,
            ];
            $messages[] = [
                'role' => 'tool',
                'tool_call_id' => $tc['id'] ?? '',
                'name' => $name,
                'content' => json_encode($result, JSON_UNESCAPED_UNICODE),
            ];
        }
    }

    // Atteint maxRounds sans réponse finale : forcer une réponse texte SANS outils
    // (sinon les modèles thinking continuent à appeler des outils ad vitam)
    $messages[] = [
        'role' => 'user',
        'content' => "Maintenant, RÉPONDS À LA QUESTION en texte simple en français, en utilisant les données déjà récoltées via les outils. N'appelle PLUS aucun outil. Si tu as trouvé une localisation 'sur_plan', utilise-la. Format court."
    ];
    $resp = callLLM($systemPrompt, $messages, null, $fournisseur, $model, $apiUrl, $apiKey, $localProviders);

    // Si toujours vide, on fait une synthèse depuis les résultats outils
    $finalText = $resp['text'] ?? '';
    if (!$finalText || trim($finalText) === '') {
        $finalText = synthesizeFromToolCalls($toolCallsLog, $messages);
    }

    return [
        'reply' => $finalText ?: "Je n'ai pas réussi à formuler une réponse après {$maxRounds} appels d'outils. Reformulez votre question.",
        'tool_calls' => $toolCallsLog,
        'rounds' => $maxRounds,
        'truncated' => true,
    ];
}

/**
 * Fallback de dernier recours : si le LLM n'a pas su synthétiser malgré
 * les appels d'outils réussis, on construit une réponse minimale à partir
 * des résultats récoltés. Utile pour les modèles thinking qui bouclent.
 */
function synthesizeFromToolCalls(array $toolCallsLog, array $messages): string {
    // Récupérer les derniers résultats d'outils dans les messages
    $lastResults = [];
    foreach ($messages as $m) {
        if (($m['role'] ?? '') === 'tool' && !empty($m['content'])) {
            $decoded = json_decode($m['content'], true);
            if (is_array($decoded)) {
                $lastResults[$m['name'] ?? 'unknown'] = $decoded;
            }
        }
    }

    $lines = [];
    // Cas localisation
    if (isset($lastResults['localiser'])) {
        $loc = $lastResults['localiser'];
        if (!empty($loc['sur_plan']) && is_array($loc['sur_plan'])) {
            $p = $loc['sur_plan'][0];
            $bat = $p['BatimentNom'] ?? '';
            $et  = $p['EtageNom'] ?? '';
            $etId = $p['EtageId'] ?? null;
            $elId = $p['ElementId'] ?? null;
            $line = "📍 Localisation : ";
            if ($bat) $line .= "Bâtiment **{$bat}**";
            if ($et)  $line .= ($bat ? ', ' : '') . "étage **{$et}**";
            if ($etId) {
                // Format avec ElementId si dispo (centre la vue sur l'élément précis)
                $line .= $elId
                    ? " [FICHE:plan:{$etId}:{$elId}:Voir sur le plan]"
                    : " [FICHE:plan:{$etId}:Voir sur le plan]";
            }
            $lines[] = $line;
        } elseif (!empty($loc['batiment']) || !empty($loc['etage']) || !empty($loc['bureau'])) {
            $parts = array_filter([
                !empty($loc['batiment']) ? "bâtiment {$loc['batiment']}" : '',
                !empty($loc['etage']) ? "étage {$loc['etage']}" : '',
                !empty($loc['bureau']) ? "bureau {$loc['bureau']}" : '',
            ]);
            $lines[] = '📍 Localisation : ' . implode(', ', $parts);
        } else {
            $lines[] = "📍 Localisation non renseignée pour cet élément.";
        }
    }
    // Cas search
    if (isset($lastResults['search'])) {
        $s = $lastResults['search'];
        if (!empty($s['count'])) {
            $lines[] = "🔎 {$s['count']} résultat(s) trouvé(s) dans " . ($s['type'] ?? 'la base') . ".";
        }
    }
    // Cas compter
    if (isset($lastResults['compter'])) {
        $c = $lastResults['compter'];
        $lines[] = "🔢 {$c['count']} élément(s) dans " . ($c['type'] ?? '?') . ".";
    }

    if (!$lines) return '';
    return "_(Synthèse automatique — le modèle n'a pas produit de texte)_\n\n" . implode("\n", $lines);
}

/**
 * Appel LLM unifié.
 * Renvoie ['text' => str, 'tool_calls' => [{id, name, args}], '_error' => str|null]
 */
function callLLM(
    string $systemPrompt, array $messages, ?AssistantTools $tools,
    string $fournisseur, string $model, string $apiUrl, string $apiKey, array $localProviders
): array {
    if ($fournisseur === 'anthropic') {
        return callAnthropic($systemPrompt, $messages, $tools, $model, $apiUrl, $apiKey);
    }
    return callOpenAILike($systemPrompt, $messages, $tools, $model, $apiUrl, $apiKey, in_array($fournisseur, $localProviders, true));
}

/** Appel format OpenAI / Mistral / Ollama / LM Studio. */
function callOpenAILike(string $systemPrompt, array $messages, ?AssistantTools $tools, string $model, string $apiUrl, string $apiKey, bool $isLocal): array {
    $msgs = [['role' => 'system', 'content' => $systemPrompt]];
    foreach ($messages as $m) {
        // Convertir notre format unifié au format OpenAI
        $msg = ['role' => $m['role']];
        if (isset($m['content'])) $msg['content'] = $m['content'];
        if (!empty($m['tool_calls'])) {
            $msg['tool_calls'] = array_map(fn($tc) => [
                'id' => $tc['id'] ?? ('call_' . uniqid()),
                'type' => 'function',
                'function' => [
                    'name' => $tc['name'],
                    'arguments' => json_encode($tc['args'] ?? [], JSON_UNESCAPED_UNICODE),
                ],
            ], $m['tool_calls']);
        }
        if (isset($m['tool_call_id'])) $msg['tool_call_id'] = $m['tool_call_id'];
        if (isset($m['name']) && $m['role'] === 'tool') $msg['name'] = $m['name'];
        $msgs[] = $msg;
    }

    $payload = [
        'model' => $model,
        'max_tokens' => 500,
        'messages' => $msgs,
    ];
    if ($tools) {
        $payload['tools'] = $tools->getOpenAISchema();
        $payload['tool_choice'] = 'auto';
    }

    // ── Sampling déterministe — cohérence inter-modèles ──
    //    Pour des questions factuelles Larka, on veut UNE bonne réponse, pas de créativité.
    //    Sans ces paramètres explicites, chaque modèle prend ses défauts (mistral 0.7,
    //    qwen 0.8, deepseek 1.0...) et les réponses divergent fortement.
    $sampling = _getSamplingParams();
    $payload['temperature'] = $sampling['temperature'];
    $payload['top_p']       = $sampling['top_p'];
    // Le seed n'est utile que sur Ollama/LM Studio (les APIs cloud OpenAI-compat
    // l'acceptent aussi pour OpenAI/Mistral mais avec moins d'effet).
    if ($isLocal && $sampling['seed'] === null) {
        // Par défaut sur local : forcer seed=42 (gain déterminisme important sur Ollama)
        $payload['seed'] = 42;
    } elseif ($sampling['seed'] !== null) {
        $payload['seed'] = $sampling['seed'];
    }

    if ($isLocal) {
        // ── Plafond de génération local ──────────────────────────────────────
        // Un 3B sur CPU génère ~12-25 tok/s : 2048 tokens = 1,5 à 3 min si le
        // modèle part en réponse longue. Le prompt exige des réponses courtes
        // (1-5 lignes) → 768 tokens suffisent largement et BORNENT la durée
        // de chaque tour à ~30-60 s dans le pire cas. Configurable en BDD.
        $maxTok = (int)(cfg('assistant', 'max_tokens_local') ?? 768);
        $payload['max_tokens'] = $maxTok;
        // ── Fenêtre de contexte + modèle résident (Ollama) ──────────────────
        // Le prompt système + schémas d'outils + historique dépassent facilement
        // les 4096 tokens par défaut d'Ollama → troncature SILENCIEUSE : le modèle
        // « oublie » les règles ou les définitions d'outils. On demande 8192.
        // keep_alive=-1 : garde le modèle en RAM (sinon Ollama le décharge après
        // 5 min et la question suivante paie 30-60 s de rechargement).
        // NB : champs ignorés sans erreur par les backends qui ne les gèrent pas
        // (LM Studio, llama.cpp server). Ceinture + bretelles : régler AUSSI
        // OLLAMA_CONTEXT_LENGTH=8192 et OLLAMA_KEEP_ALIVE=-1 côté service Ollama.
        $numCtx = (int)(cfg('assistant', 'num_ctx') ?? 8192);
        $payload['options'] = array_merge($payload['options'] ?? [], [
            'num_ctx'     => $numCtx,
            'num_predict' => $maxTok, // équivalent Ollama natif de max_tokens
        ]);
        $payload['keep_alive'] = -1;
    }

    // ── Ollama : désactiver le mode "thinking" qui empêche la réponse texte
    //    Les modèles thinking (qwen3.x, deepseek-r1, etc.) bouclent sinon en raisonnement
    //    interne sans jamais produire la réponse finale.
    //    Mais ATTENTION : désactiver think casse aussi le tool calling sur
    //    qwen3.x qui en a besoin pour décider quel outil appeler. On ne le
    //    désactive donc que sur les modèles confirmés boucleurs (deepseek-r1
    //    notamment). Les autres gèrent très bien think + tool calling.
    $modelLower = strtolower($model);
    $forceNoThink = $isLocal && (
        str_contains($modelLower, 'deepseek-r1') ||
        str_contains($modelLower, 'r1-distill') ||
        str_contains($modelLower, 'qwq')           // QwQ-32B et dérivés
    );
    if ($forceNoThink) {
        $payload['think'] = false;
        // Pour Ollama : tous les paramètres avancés vont dans 'options'
        // (incluant seed pour qu'il soit pris en compte côté Ollama natif si /api/chat
        // est utilisé en proxy). On préserve la temp/top_p déjà fixés au top level.
        $payload['options'] = array_merge($payload['options'] ?? [], [
            'think'       => false,
            'temperature' => $sampling['temperature'],
            'top_p'       => $sampling['top_p'],
            'seed'        => $payload['seed'] ?? 42,
        ]);
    } elseif ($isLocal) {
        // Sur Ollama, dupliquer les paramètres dans 'options' pour être sûr
        // qu'ils soient pris en compte (certaines builds n'écoutent que options.*).
        $payload['options'] = array_merge($payload['options'] ?? [], [
            'temperature' => $sampling['temperature'],
            'top_p'       => $sampling['top_p'],
            'seed'        => $payload['seed'] ?? 42,
        ]);
    }

    $headers = ['Content-Type: application/json'];
    if ($apiKey) $headers[] = 'Authorization: Bearer ' . $apiKey;

    $response = curlCall($apiUrl, $payload, $headers, $isLocal);
    if (isset($response['_error'])) return ['_error' => $response['_error']];

    $data = $response['data'] ?? [];
    $msg = $data['choices'][0]['message'] ?? [];
    $text = $msg['content'] ?? '';

    // Nettoyage défensif : si le modèle a quand même produit un bloc <think>...</think>,
    // on le retire avant de renvoyer.
    if ($text && str_contains($text, '<think>')) {
        $text = preg_replace('#<think>.*?</think>\s*#s', '', $text);
        $text = trim($text);
    }

    $toolCalls = [];
    foreach ($msg['tool_calls'] ?? [] as $tc) {
        $argsRaw = $tc['function']['arguments'] ?? '{}';
        $args = is_string($argsRaw) ? json_decode($argsRaw, true) : $argsRaw;
        if (!is_array($args)) $args = [];
        $toolCalls[] = [
            'id' => $tc['id'] ?? '',
            'name' => $tc['function']['name'] ?? '',
            'args' => $args,
        ];
    }
    return ['text' => $text, 'tool_calls' => $toolCalls];
}

/** Appel format Anthropic (Claude). */
function callAnthropic(string $systemPrompt, array $messages, ?AssistantTools $tools, string $model, string $apiUrl, string $apiKey): array {
    // Convertir nos messages au format Anthropic
    $msgs = [];
    foreach ($messages as $m) {
        if ($m['role'] === 'tool') {
            // tool_result en mode user
            $msgs[] = [
                'role' => 'user',
                'content' => [[
                    'type' => 'tool_result',
                    'tool_use_id' => $m['tool_call_id'] ?? '',
                    'content' => $m['content'] ?? '',
                ]],
            ];
        } elseif ($m['role'] === 'assistant' && !empty($m['tool_calls'])) {
            $content = [];
            if (!empty($m['content'])) $content[] = ['type' => 'text', 'text' => $m['content']];
            foreach ($m['tool_calls'] as $tc) {
                $content[] = [
                    'type' => 'tool_use',
                    'id' => $tc['id'] ?? ('toolu_' . uniqid()),
                    'name' => $tc['name'],
                    'input' => $tc['args'] ?? [],
                ];
            }
            $msgs[] = ['role' => 'assistant', 'content' => $content];
        } else {
            $msgs[] = ['role' => $m['role'], 'content' => $m['content'] ?? ''];
        }
    }

    $payload = [
        'model' => $model,
        'max_tokens' => 2048,
        'system' => $systemPrompt,
        'messages' => $msgs,
    ];
    if ($tools) $payload['tools'] = $tools->getAnthropicSchema();

    // Sampling déterministe — même rationale que callOpenAILike.
    // Anthropic accepte temperature et top_p, pas de seed dans l'API publique.
    $sampling = _getSamplingParams();
    $payload['temperature'] = $sampling['temperature'];
    $payload['top_p']       = $sampling['top_p'];

    $headers = [
        'Content-Type: application/json',
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01',
    ];

    $response = curlCall($apiUrl, $payload, $headers, false);
    if (isset($response['_error'])) return ['_error' => $response['_error']];
    $data = $response['data'] ?? [];

    $text = ''; $toolCalls = [];
    foreach ($data['content'] ?? [] as $block) {
        if (($block['type'] ?? '') === 'text') $text .= $block['text'];
        elseif (($block['type'] ?? '') === 'tool_use') {
            $toolCalls[] = [
                'id' => $block['id'] ?? '',
                'name' => $block['name'] ?? '',
                'args' => $block['input'] ?? [],
            ];
        }
    }
    return ['text' => $text, 'tool_calls' => $toolCalls];
}

/** Wrapper curl unifié. */
function curlCall(string $url, array $payload, array $headers, bool $isLocal): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
    ]);
    if ($isLocal || str_starts_with($url, 'http://')) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    } else {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    }
    $raw = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) return ['_error' => "Connexion : $err"];
    if ($raw === false || $raw === '') {
        return ['_error' => "Réponse vide du serveur (HTTP $http)"];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        // Réponse non-JSON (souvent une page d'erreur HTML chez les proxys)
        $snippet = mb_substr(strip_tags((string)$raw), 0, 200);
        return ['_error' => "Réponse non-JSON (HTTP $http) : $snippet"];
    }
    if ($http >= 400) {
        $msg = $data['error']['message'] ?? $data['error'] ?? $data['message'] ?? "HTTP $http";
        if (is_array($msg)) $msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
        return ['_error' => (string)$msg];
    }
    return ['data' => $data];
}

/**
 * Mode LEGACY : ancienne version qui déverse les résultats dans le prompt.
 * Utilisée comme fallback automatique quand le tool calling n'est pas dispo.
 */
function runLegacyMode(
    $db, string $systemPrompt, string $question, array $history,
    string $fournisseur, string $model, string $apiUrl, string $apiKey, array $localProviders, array $intents
): array {
    // Extraction de mots-clés + search global
    $stopWords = ['je','tu','il','elle','le','la','les','un','une','des','de','du','au','aux','en','et','ou','est','sur','dans','pour','avec','par','ne','que','qui','quoi','où','quand','quel','quelle','combien','comment','est-ce','le','les'];
    $rawWords = preg_split('/[\s,;:.\-\'"\/()?!]+/u', mb_strtolower($question));
    $keywords = array_values(array_filter($rawWords, fn($w) => mb_strlen($w) >= 2 && !in_array($w, $stopWords) && !is_numeric($w)));

    $searchResults = [];
    if ($keywords) {
        try { $searchResults = $db->searchForAssistant($keywords); }
        catch (\Throwable $e) { $searchResults = ['_error' => $e->getMessage()]; }
    }
    $totalResults = 0;
    foreach ($searchResults as $k => $v) {
        if ($k !== '_error' && is_array($v)) $totalResults += count($v);
    }

    // ── Reformatage en blocs "tool_result"-like ──
    // Plutôt qu'un gros dump JSON, on présente les résultats comme l'aurait fait
    // un appel d'outil 'search'. Le modèle suit ainsi la MÊME méthode que dans
    // le prompt principal (avec ses exemples), donc les réponses convergent
    // entre legacy et tool-calling.
    $tableLabels = [
        'biens'             => 'biens',
        'equipements'       => 'equipements',
        'interventions'     => 'interventions',
        'contrats'          => 'contrats',
        'demandesintervention' => 'demandes',
        'stock'             => 'stock',
        'documents'         => 'documents',
        'plan_elements'     => 'plans',
        'archives_dossiers' => 'archives',
    ];
    $resultBlocks = [];
    foreach ($tableLabels as $sqlTable => $toolType) {
        $rows = $searchResults[$sqlTable] ?? [];
        if (!is_array($rows) || empty($rows)) continue;
        // On limite à 30 entrées par type pour rester sous la fenêtre de contexte
        // et imiter la limite du vrai tool 'search'.
        $truncated = count($rows) > 30;
        $rows = array_slice($rows, 0, 30);
        $resultBlocks[] = [
            'tool'  => 'search',
            'args'  => ['type' => $toolType, 'query' => implode(' ', $keywords)],
            'result'=> [
                'type'      => $toolType,
                'count'     => count($rows),         // toujours un int
                'truncated' => $truncated,           // booléen séparé : si true,
                                                     // il y a plus de résultats que count
                'results'   => $rows,
            ],
        ];
    }

    // Si erreur de recherche, on la signale aussi en bloc structuré.
    if (isset($searchResults['_error'])) {
        $resultBlocks[] = [
            'tool'   => 'search',
            'args'   => ['query' => implode(' ', $keywords)],
            'result' => ['error' => $searchResults['_error']],
        ];
    }

    // Construction du suffixe injecté dans le prompt système.
    // On garde EXACTEMENT le prompt principal (avec ses méthodes/exemples) et on
    // ajoute simplement un bloc "résultats déjà récoltés" en fin.
    $appendix = "\n\n═══ RÉSULTATS DE RECHERCHE DÉJÀ RÉCOLTÉS ═══\n"
        . "Le tool calling n'est pas disponible avec ce modèle. À la place, une recherche\n"
        . "globale a été lancée automatiquement avec les mots-clés extraits de la question.\n"
        . "Tu dois RAISONNER comme si tu venais d'appeler search() pour chaque type — la\n"
        . "MÊME méthode décrite plus haut s'applique (idem pour les liens [FICHE:...] et\n"
        . "le format de réponse). Tu ne peux PAS appeler d'outil supplémentaire ; si la\n"
        . "donnée manque dans ce bloc, dis-le clairement.\n\n"
        . "Mots-clés extraits : [" . implode(', ', $keywords) . "]\n"
        . "Total résultats : {$totalResults}\n\n";

    if (empty($resultBlocks)) {
        $appendix .= "RÉSULTATS : aucun résultat trouvé dans aucune table avec ces mots-clés.\n"
                   . "→ Dis-le clairement à l'utilisateur et propose 1-2 reformulations.\n";
    } else {
        foreach ($resultBlocks as $i => $b) {
            $appendix .= "─── tool_result #" . ($i + 1) . " ───\n"
                . "tool: " . $b['tool'] . "\n"
                . "args: " . json_encode($b['args'], JSON_UNESCAPED_UNICODE) . "\n"
                . "result: " . json_encode($b['result'], JSON_UNESCAPED_UNICODE) . "\n\n";
        }
        $appendix .= "RAPPEL : si plusieurs candidats homonymes → demander à l'utilisateur de préciser.\n"
                   . "Si un seul résultat pertinent et la question est une localisation → utiliser les\n"
                   . "champs 'Batiment'/'Etage' de la fiche. Si la fiche contient un 'EtageId' et un 'Id',\n"
                   . "produire un lien [FICHE:plan:EtageId:Id:label].\n";
    }

    $promptLegacy = $systemPrompt . $appendix;

    $messages = [];
    foreach ($history as $h) {
        if (isset($h['role'], $h['content'])) {
            $messages[] = ['role' => $h['role'], 'content' => $h['content']];
        }
    }
    $messages[] = ['role' => 'user', 'content' => $question];

    $resp = callLLM($promptLegacy, $messages, null, $fournisseur, $model, $apiUrl, $apiKey, $localProviders);
    return [
        'reply'         => $resp['text'] ?? ('❌ ' . ($resp['_error'] ?? 'Erreur')),
        'results_count' => $totalResults,
        'keywords'      => $keywords,
    ];
}

/**
 * Gestionnaire du cas DEMANDEUR : pas de tool calling, contexte limité.
 * Version simplifiée (pas de duplication avec la v1).
 */
function handleDemandeur($db, array $user, string $question, array $history, array $intents, string $fournisseur, string $model, string $apiUrl, string $apiKey, array $localProviders): array {
    $userName = trim(($user['Prenom'] ?? '') . ' ' . ($user['Nom'] ?? ''));
    $systemPrompt = buildDemandeurSystemPrompt($db, $user, $userName, $intents);

    $messages = [];
    foreach ($history as $h) {
        if (isset($h['role'], $h['content'])) $messages[] = ['role'=>$h['role'],'content'=>$h['content']];
    }
    $messages[] = ['role' => 'user', 'content' => $question];

    $resp = callLLM($systemPrompt, $messages, null, $fournisseur, $model, $apiUrl, $apiKey, $localProviders);
    return [
        'reply' => $resp['text'] ?? ('❌ ' . ($resp['_error'] ?? 'Erreur')),
        'mode' => 'demandeur',
    ];
}

/**
 * Construit le prompt système pour un Demandeur.
 *
 * Le Demandeur n'a pas accès au tool calling (par souci de simplicité et de RGPD :
 * pas de fouille libre dans la base). À la place, on lui injecte un CONTEXTE
 * structuré directement dans le prompt avec :
 *  - les rôles Larka et qui contacter en cas d'urgence
 *  - la liste des gestionnaires (avec leur service/poste si renseigné)
 *  - les services, bâtiments, catégories et niveaux d'urgence disponibles
 *  - ses propres demandes récentes
 *
 * L'assistant peut AUSSI initier la création d'une demande en émettant un token
 * spécial [ACTION:nouvelle_demande:...] que le frontend détecte pour ouvrir le
 * modal pré-rempli — bien plus utile que de dire au demandeur "voici comment faire".
 */
function buildDemandeurSystemPrompt($db, array $user, string $userName, array $intents): string {
    // ── Services distincts (lieux logiques) ──────────────────────────────
    $servicesInfo = [];
    try {
        $rows = $db->fetchAll("SELECT DISTINCT Service FROM Utilisateurs WHERE Actif = 1 AND Service IS NOT NULL AND Service != '' ORDER BY Service LIMIT 30");
        $servicesInfo = array_column($rows, 'Service');
    } catch (\Throwable $_) {}

    // ── Gestionnaires (avec rôle/service/poste + contacts si renseignés) ──
    // On filtre les comptes "fantômes" (juste Prenom='11', etc. sans aucune
    // info qualifiante) pour éviter que le LLM les présente comme des contacts.
    // Les téléphones et email permettent au LLM de donner un VRAI contact
    // utilisable (« appelle Marie au 06.12... ») au lieu d'un nom vague.
    $gestionnaires = [];
    try {
        $rows = $db->fetchAll(
            "SELECT Prenom, Nom, Poste, Service, Role, Email, Tel, TelMobile, TelPro
             FROM Utilisateurs
             WHERE Actif = 1 AND Role IN ('Gestionnaire','Admin')
             ORDER BY Role DESC, Nom LIMIT 20"
        );
        foreach ($rows as $r) {
            $nomComplet = trim(($r['Prenom'] ?? '') . ' ' . ($r['Nom'] ?? ''));
            $poste   = trim($r['Poste'] ?? '');
            $service = trim($r['Service'] ?? '');
            $role    = trim($r['Role'] ?? '');
            $email   = trim($r['Email'] ?? '');
            // Choix du tél : on priorise le mobile (joignable en urgence),
            // puis le tél pro/poste, puis le fixe.
            $tel = trim($r['TelMobile'] ?? '') ?: trim($r['TelPro'] ?? '') ?: trim($r['Tel'] ?? '');

            // Si le compte a au moins un poste ou service renseigné → contact qualifié
            // Sinon → on l'indique comme contact générique (rôle seulement).
            $detail = $poste ?: $service ?: $role;
            $tag = $detail ? "{$nomComplet} ({$detail})" : "{$nomComplet} [profil incomplet]";
            // Ajout des coordonnées en ligne pour que le LLM puisse les ressortir.
            $coords = [];
            if ($tel)   $coords[] = "📞 {$tel}";
            if ($email) $coords[] = "✉️ {$email}";
            if ($coords) $tag .= ' — ' . implode(', ', $coords);
            $gestionnaires[] = $tag;
        }
    } catch (\Throwable $_) {}

    // ── Catégories de demandes (types métier) ───────────────────────────
    $categories = [];
    try {
        $rows = $db->fetchAll("SELECT DISTINCT Categorie FROM DemandesIntervention WHERE Categorie IS NOT NULL AND Categorie != '' ORDER BY Categorie LIMIT 30");
        $categories = array_column($rows, 'Categorie');
    } catch (\Throwable $_) {}

    // ── Bâtiments connus (utile pour pré-remplir) ───────────────────────
    $batiments = [];
    try {
        // D'abord via la liste de référence si elle existe
        $rows = $db->fetchAll("SELECT DISTINCT Valeur FROM ListesReferences WHERE Categorie = 'Batiment' AND Valeur IS NOT NULL AND Valeur != '' ORDER BY Valeur LIMIT 30");
        $batiments = array_column($rows, 'Valeur');
        // Compléter avec les bâtiments réellement utilisés dans les demandes existantes
        if (count($batiments) < 30) {
            $rows = $db->fetchAll("SELECT DISTINCT Batiment FROM DemandesIntervention WHERE Batiment IS NOT NULL AND Batiment != '' ORDER BY Batiment LIMIT 30");
            foreach ($rows as $r) {
                if (!in_array($r['Batiment'], $batiments, true)) $batiments[] = $r['Batiment'];
            }
        }
    } catch (\Throwable $_) {}

    // ── Demandes existantes du demandeur ────────────────────────────────
    // On joint avec Interventions pour récupérer le gestionnaire qui a pris
    // en charge la demande (le Demandeur veut souvent savoir "qui s'occupe
    // de ma demande" — on lui donne cette info dans le contexte du prompt).
    $mesDemandes = [];
    try {
        // ⚠️ FIX : la relation est portée par Interventions.DemandeId, PAS par
        // DemandesIntervention.InterventionId (qui n'existe pas). De même,
        // l'agent en charge est stocké en clair dans AgentNom/AgentPrenom (il
        // n'y a pas de colonne AssigneA) et il n'existe pas de DateLimite —
        // l'échéance pertinente est DateIntervention. L'ancienne requête
        // échouait donc systématiquement, et comme elle est enveloppée dans un
        // catch silencieux, le demandeur n'obtenait JAMAIS ses demandes dans le
        // contexte de l'assistant.
        $rows = $db->fetchAll(
            "SELECT d.Id, d.Titre, d.Statut, d.Urgence, d.Categorie, d.Batiment, d.DateCreation,
                    d.CommentaireAdmin,
                    i.Id     AS InterventionId,
                    i.Numero AS InterventionNumero,
                    i.Statut AS InterventionStatut,
                    i.DateIntervention AS InterventionDatePrevue,
                    i.AgentPrenom AS InterventionAgentPrenom,
                    i.AgentNom    AS InterventionAgentNom
             FROM DemandesIntervention d
             LEFT JOIN Interventions i ON i.DemandeId = d.Id
             WHERE d.UtilisateurId = :uid
             ORDER BY d.DateCreation DESC LIMIT 10",
            ['uid' => (int)$user['Id']]
        );
        // On masque les emails/téléphones éventuels stockés dans les champs
        // libres (CommentaireAdmin) — pas anonymiser l'agent qui est juste un
        // nom métier (le demandeur a le droit de savoir qui le suit).
        $mesDemandes = $rows;
    } catch (\Throwable $_) {}

    // ── Service du demandeur (pour pré-remplir et contextualiser) ──────
    $serviceDemandeur = trim($user['Service'] ?? '');
    $posteDemandeur   = trim($user['Poste'] ?? '');

    // Helpers d'affichage : "aucun" si vide, sinon la liste
    $fmt = fn($arr) => empty($arr) ? 'aucun connu en base' : implode(', ', $arr);

    // ─────────────────────────────────────────────────────────────────────
    // PROMPT
    // ─────────────────────────────────────────────────────────────────────
    return <<<PROMPT
Tu es l'assistant Larka de {$userName} (rôle: Demandeur).
Ton ton est CHALEUREUX, simple, sans jargon. Tu tutoies le demandeur.
Date du jour : {date('Y-m-d')}

═══ RÔLES LARKA (toujours répondre si on te le demande) ═══
• Demandeur : peut créer et suivre ses demandes d'intervention.
• Gestionnaire : reçoit les demandes, les valide et les transforme en interventions.
• Admin : configure l'application et gère les comptes.
• Technicien : exécute les interventions (interne ou prestataire).
Si on te demande "les rôles" ou "qui appeler en cas d'urgence", tu réponds TOUJOURS
en t'appuyant sur cette grille — JAMAIS « je n'ai pas l'info ». En cas d'urgence,
on contacte un Gestionnaire (cf. liste ci-dessous).

═══ CONTEXTE DE LA BASE ═══
• Demandeur connecté : {$userName}
  – Service : {$serviceDemandeur}
  – Poste   : {$posteDemandeur}
• Services présents dans l'entreprise : {$fmt($servicesInfo)}
• Bâtiments connus : {$fmt($batiments)}
• Catégories de demande disponibles : {$fmt($categories)}
• Niveaux d'urgence : Faible, Normale, Élevée, Urgente
• Gestionnaires (contact en cas d'urgence) :
PROMPT
. "\n" . (empty($gestionnaires)
    ? "  – aucun gestionnaire actif en base. Si le demandeur a une urgence,\n"
    . "    suggère-lui de contacter directement son responsable de service."
    : "  – " . implode("\n  – ", $gestionnaires)
)
. "\n\n"
. ($mesDemandes
    ? "• Tes 10 dernières demandes :\n" . json_encode($mesDemandes, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n"
    : "• Tu n'as encore aucune demande enregistrée.\n"
)
. <<<PROMPT2

═══ CRÉATION D'UNE DEMANDE — TRÈS IMPORTANT ═══
Il existe DEUX types de demandes : **Technique** (panne, intervention, besoin
matériel…) et **Archive** (archivage de dossiers, ou désarchivage = sortir un
dossier des archives). Tu dois choisir le bon type.

═══ DEMANDE TECHNIQUE ═══
Si l'utilisateur décrit un problème, une panne, un besoin (« la centrale SSI bipe »,
« il n'y a plus de papier dans les toilettes », « j'ai besoin d'un câble HDMI »…)
ou s'il te demande explicitement « peux-tu faire une demande pour moi », tu DOIS :

1. PRÉPARER la demande à partir de la conversation :
   - Titre court (5-10 mots, ex: "Centrale SSI - bipage continu")
   - Description complète (ce que le demandeur a expliqué, en gardant ses mots)
   - Bâtiment (depuis la liste ci-dessus, ou vide si non précisé)
   - Catégorie (depuis la liste ci-dessus, choisis la plus proche, ou vide)
   - Urgence (Faible / Normale / Élevée / Urgente — déduis du ton et du contexte)

2. ÉMETTRE le token spécial à la fin de ta réponse, sur sa propre ligne :
   [ACTION:nouvelle_demande|type=Technique|titre=…|description=…|batiment=…|categorie=…|urgence=…]

═══ DEMANDE ARCHIVE ═══
Si l'utilisateur parle d'**archivage** (« j'ai des cartons à archiver », « 50 dossiers
de 2018 à 2023 à archiver », « il faut archiver les contrats de la DRH »…) OU de
**désarchivage** (« il me faut le dossier DOS-2020-001 », « pouvez-vous sortir le
dossier de M. Dupont des archives », « j'ai besoin de récupérer plusieurs dossiers
des archives »…), tu DOIS émettre une demande de type Archive.

Champs disponibles :
   - type=Archive (OBLIGATOIRE)
   - prestation=Archivage  OU  prestation=Desarchivage  (OBLIGATOIRE — sans accent !)
   - description (toujours utile pour expliquer le contexte)
   - bâtiment (si mentionné)
   - bureau (= service du demandeur, optionnel)

Champs spécifiques ARCHIVAGE (uniquement si prestation=Archivage) :
   - nb_dossiers (estimation du nombre, ex: 50)
   - service (service concerné, ex: "Direction des finances")
   - periode (période couverte, ex: "2018-2023")

Champs spécifiques DÉSARCHIVAGE (uniquement si prestation=Desarchivage) :
   - date_solde (date au format AAAA-MM-JJ avant laquelle le dossier doit être restitué)
   - dossiers (liste de N° de dossiers SÉPARÉS PAR VIRGULES, ex: "DOS-2020-001,DOS-2020-002")

Format du token :
   [ACTION:nouvelle_demande|type=Archive|prestation=Desarchivage|description=…|dossiers=DOS-2020-001,DOS-2020-002|date_solde=2024-03-15]

═══ FORMAT GÉNÉRAL DU TOKEN ═══
   ⚠️ Format STRICT :
   - Séparateur entre champs : | (pipe)
   - Séparateur clé/valeur : = (egal)
   - Pas de retour ligne dans les valeurs (utilise des espaces à la place)
   - Pas de guillemets autour des valeurs
   - Pas de | NI de = dans les valeurs (les remplacer par des espaces)
   - Si un champ est vide, mets juste la clé sans valeur (ex: |batiment=|) OU omets-la

3. PRÉSENTER le résultat au demandeur de façon naturelle :
   « Je te propose cette demande, je l'ouvre pour que tu vérifies avant de l'envoyer. »
   Puis le token [ACTION:...] sur sa propre ligne. Le frontend ouvrira le modal de
   création pré-rempli et l'utilisateur n'aura qu'à valider ou ajuster.

4. NE PAS dire « je ne peux pas faire ça pour toi ». Tu PEUX. Le token déclenche
   l'ouverture du formulaire. Le demandeur garde le contrôle final (il doit valider).

5. À CHAQUE NOUVELLE DEMANDE DE L'UTILISATEUR, tu PEUX émettre un NOUVEAU token,
   même si tu en as déjà émis un dans un message précédent. Chaque demande est
   indépendante. Ne dis JAMAIS « j'ai déjà ouvert une demande pour toi » comme
   prétexte pour refuser d'en créer une autre — l'utilisateur peut en avoir
   plusieurs à enchaîner.

EXEMPLES :
─────────────────
Demandeur : « La centrale SSI bipe sans cesse dans le bâtiment Test »
Toi : « Je vais te préparer la demande, tu n'auras plus qu'à la vérifier. »
[ACTION:nouvelle_demande|type=Technique|titre=Centrale SSI - bipage continu|description=La centrale SSI émet un bip continu dans le bâtiment Test. À vérifier au plus vite.|batiment=Test|categorie=Sécurité incendie|urgence=Élevée]

─────────────────
Demandeur : « J'ai besoin de désarchiver le dossier DOS-2020-001, c'est pour un contentieux qui passe le 15 mars »
Toi : « Je te prépare la demande de désarchivage. »
[ACTION:nouvelle_demande|type=Archive|prestation=Desarchivage|description=Désarchivage demandé pour un contentieux prévu le 15 mars.|dossiers=DOS-2020-001|date_solde=2024-03-15]

─────────────────
Demandeur : « Il faut archiver une cinquantaine de dossiers RH de 2018 à 2023 »
Toi : « C'est noté, je te prépare la demande d'archivage. »
[ACTION:nouvelle_demande|type=Archive|prestation=Archivage|description=Archivage d'une cinquantaine de dossiers RH couvrant la période 2018-2023.|nb_dossiers=50|service=Direction des Ressources Humaines|periode=2018-2023]

═══ AUTRES RÈGLES ═══
- Réponds en français, COURT (2-5 lignes hors token).
- Ne JAMAIS inventer un nom de personne, statut ou catégorie hors de la liste ci-dessus.
- Pour « ma demande » : utilise UNIQUEMENT les 10 dernières demandes listées.
  Si elle n'y figure pas, propose d'en créer une nouvelle (avec [ACTION:...]).
- Pour « qui s'occupe de ma demande #N » / « où en est ma demande #N » : regarde
  dans la liste des 10 dernières demandes. Si une intervention est rattachée,
  les champs `InterventionAgentPrenom` / `InterventionAgentNom` indiquent qui la suit (technicien ou gestionnaire).
  Si aucune intervention n'est rattachée et que le statut est "Nouvelle" ou "À traiter",
  alors la demande attend qu'un gestionnaire la prenne — propose au demandeur
  d'appeler un Gestionnaire de la liste ci-dessus.
- Pour "qui appeler en cas d'urgence" : un Gestionnaire de la liste ci-dessus,
  ou à défaut le responsable de service du demandeur.
- Si une question sort du périmètre Larka (vie privée, autres apps, météo, etc.) :
  poliment, indiquer que tu n'aides que pour Larka.
- Pas de balises techniques [FICHE:...] [DOC:...] : le demandeur ne les voit pas.
- Le seul token autorisé est [ACTION:nouvelle_demande|...] décrit plus haut.
PROMPT2;
}

// ═══════════════════════════════════════════════════════════════════════
// ── STREAMING (Server-Sent Events) ──
// ═══════════════════════════════════════════════════════════════════════

/**
 * Variante de runToolCallingLoop qui pousse les événements SSE en temps réel.
 * Les tours intermédiaires (avec tool_calls) sont exécutés normalement, mais on
 * NOTIFIE le client à chaque tool call/result. Le DERNIER tour (réponse texte
 * sans tool call) est streamé token par token.
 */
function runToolCallingLoopWithSSE(
    AssistantTools $tools, string $systemPrompt, string $question, array $history,
    string $fournisseur, string $model, string $apiUrl, string $apiKey, array $localProviders,
    callable $sseSend
): array {
    $isLocal = in_array($fournisseur, $localProviders, true);
    // Voir runToolCallingLoop : 3 tours max en local (chaque tour = une inférence CPU complète).
    $maxRounds = $isLocal ? 3 : 5;
    $toolCallsLog = [];
    $callCache = [];

    $messages = [];
    foreach ($history as $h) {
        if (isset($h['role'], $h['content'])) $messages[] = ['role' => $h['role'], 'content' => $h['content']];
    }
    $messages[] = ['role' => 'user', 'content' => $question];

    for ($round = 0; $round < $maxRounds; $round++) {
        // 1) Appel non-streamé pour récupérer la décision (tool calls ou texte)
        $resp = callLLM($systemPrompt, $messages, $tools, $fournisseur, $model, $apiUrl, $apiKey, $localProviders);

        if (isset($resp['_error'])) {
            $err = strtolower($resp['_error']);
            if (str_contains($err, 'tool') && (str_contains($err, 'not support') || str_contains($err, 'unknown') || str_contains($err, 'invalid'))) {
                return ['_fallback_needed' => true, 'reason' => $resp['_error']];
            }
            $sseSend('error', ['message' => $resp['_error']]);
            return ['tool_calls' => $toolCallsLog];
        }

        // Info de debug : taille texte + nb tool calls pour ce tour
        $sseSend('debug', [
            'round' => $round + 1,
            'has_text' => !empty($resp['text']),
            'text_len' => strlen($resp['text'] ?? ''),
            'tool_calls' => count($resp['tool_calls'] ?? []),
        ]);

        // 2) Pas de tool call → c'est le tour final, on stream le texte.
        if (empty($resp['tool_calls'])) {
            $finalText = _cleanLLMOutput($resp['text'] ?? '');
            if ($finalText !== '') {
                // Streaming progressif du texte déjà reçu (l'appel a été fait
                // en non-streamé pour récupérer les tool_calls éventuels).
                foreach (_chunkForSSE($finalText) as $chunk) {
                    $sseSend('text', ['delta' => $chunk]);
                    usleep(8000);
                }
            } else {
                // Cas pathologique : le LLM ne fait pas de tool_call mais
                // renvoie un texte vide. Ça arrive avec certains modèles
                // (Ollama think=false, ou Anthropic après tool_use sans suite).
                // On force un dernier appel SANS tools pour obtenir une vraie
                // réponse texte.
                if ($round > 0) {
                    $messages[] = ['role' => 'user', 'content' => "Réponds maintenant à ma question avec les données récoltées. Texte simple en français, sans appeler d'outils, sans réfléchir à voix haute."];
                    $resp2 = callLLM($systemPrompt, $messages, null, $fournisseur, $model, $apiUrl, $apiKey, $localProviders);
                    $finalText = _cleanLLMOutput($resp2['text'] ?? '');
                    if ($finalText !== '') {
                        foreach (_chunkForSSE($finalText) as $chunk) {
                            $sseSend('text', ['delta' => $chunk]);
                            usleep(8000);
                        }
                    } else {
                        $sseSend('text', ['delta' => "Je n'ai pas pu formuler de réponse claire à partir des données récoltées. Reformulez la question ?"]);
                    }
                } else {
                    // Tour 0 sans tool_call ET sans texte = réellement vide
                    $sseSend('text', ['delta' => "Je n'ai pas compris la question. Pouvez-vous reformuler ?"]);
                }
            }
            return [
                'tool_calls' => $toolCallsLog,
                'rounds' => $round + 1,
            ];
        }

        // 3) Tool calls : exécuter, notifier le client, ajouter les résultats
        $messages[] = ['role' => 'assistant', 'content' => $resp['text'] ?? '', 'tool_calls' => $resp['tool_calls']];
        foreach ($resp['tool_calls'] as $tc) {
            $name = $tc['name'] ?? '';
            $args = is_array($tc['args'] ?? null) ? $tc['args'] : [];
            $sseSend('tool_call', ['name' => $name, 'args_summary' => _summarizeArgs($args)]);

            $argsSorted = $args;
            ksort($argsSorted);
            $sig = $name . ':' . md5(json_encode($argsSorted, JSON_UNESCAPED_UNICODE));
            if (isset($callCache[$sig])) {
                $result = $callCache[$sig];
                $cached = true;
            } else {
                $result = $tools->execute($name, $args);
                $callCache[$sig] = $result;
                $cached = false;
            }
            $size = is_array($result) ? (isset($result['count']) ? (int)$result['count'] : count($result)) : 0;
            $sseSend('tool_result', ['name' => $name, 'size' => $size, 'cached' => $cached]);
            $toolCallsLog[] = ['name' => $name, 'args' => $args, 'result_size' => $size, 'cached' => $cached];
            $messages[] = [
                'role' => 'tool',
                'tool_call_id' => $tc['id'] ?? '',
                'name' => $name,
                'content' => json_encode($result, JSON_UNESCAPED_UNICODE),
            ];
        }
    }

    // Force une réponse texte streamée sans outils (atteint maxRounds)
    $messages[] = [
        'role' => 'user',
        'content' => "Maintenant, RÉPONDS À LA QUESTION en texte simple en français, en utilisant les données déjà récoltées par les outils ci-dessus. N'appelle PLUS aucun outil. Format COURT (2-3 phrases). N'oublie pas d'insérer les liens [FICHE:type:id:label] et [DOC:id:nom] quand pertinent pour rendre la réponse cliquable."
    ];
    streamFinalAnswer($systemPrompt, $messages, $fournisseur, $model, $apiUrl, $apiKey, $isLocal, $sseSend);
    return ['tool_calls' => $toolCallsLog, 'rounds' => $maxRounds, 'truncated' => true];
}

/**
 * Fait un appel LLM en mode streaming et pousse chaque delta vers le client SSE.
 * Supporte les formats OpenAI-like (data: {choices:[{delta:{content:"..."}}]})
 * et Anthropic (event-stream avec content_block_delta).
 */
function streamFinalAnswer(
    string $systemPrompt, array $messages, string $fournisseur, string $model,
    string $apiUrl, string $apiKey, bool $isLocal, callable $sseSend
): void {
    if ($fournisseur === 'anthropic') {
        streamAnthropic($systemPrompt, $messages, $model, $apiUrl, $apiKey, $sseSend);
    } else {
        streamOpenAILike($systemPrompt, $messages, $model, $apiUrl, $apiKey, $isLocal, $sseSend);
    }
}

/** Streaming format OpenAI / Mistral / Ollama / LM Studio. */
function streamOpenAILike(string $systemPrompt, array $messages, string $model, string $apiUrl, string $apiKey, bool $isLocal, callable $sseSend): void {
    $msgs = [['role' => 'system', 'content' => $systemPrompt]];
    foreach ($messages as $m) {
        $msg = ['role' => $m['role']];
        if (isset($m['content'])) $msg['content'] = $m['content'];
        if (isset($m['tool_call_id'])) $msg['tool_call_id'] = $m['tool_call_id'];
        if (isset($m['name']) && $m['role'] === 'tool') $msg['name'] = $m['name'];
        if (!empty($m['tool_calls'])) {
            $msg['tool_calls'] = array_map(fn($tc) => [
                'id' => $tc['id'] ?? ('call_' . uniqid()),
                'type' => 'function',
                'function' => ['name' => $tc['name'], 'arguments' => json_encode($tc['args'] ?? [], JSON_UNESCAPED_UNICODE)],
            ], $m['tool_calls']);
        }
        $msgs[] = $msg;
    }
    $payload = [
        'model' => $model,
        'max_tokens' => 2048,
        'messages' => $msgs,
        'stream' => true,
    ];
    // Sampling déterministe (cohérence inter-modèles).
    $sampling = _getSamplingParams();
    $payload['temperature'] = $sampling['temperature'];
    $payload['top_p']       = $sampling['top_p'];
    if ($isLocal) {
        $seed = $sampling['seed'] ?? 42;
        $payload['seed'] = $seed;
        // Plafond de génération local (voir callOpenAILike) : borne la durée
        // de la réponse à ~30-60 s au lieu de 1,5-3 min avec 2048 tokens.
        $maxTok = (int)(cfg('assistant', 'max_tokens_local') ?? 768);
        $payload['max_tokens'] = $maxTok;
        $payload['options'] = array_merge($payload['options'] ?? [], [
            'temperature' => $sampling['temperature'],
            'top_p'       => $sampling['top_p'],
            'seed'        => $seed,
            // Fenêtre de contexte élargie (défaut Ollama : 4096 → troncature
            // silencieuse du prompt système / des règles). Voir callOpenAILike.
            'num_ctx'     => (int)(cfg('assistant', 'num_ctx') ?? 8192),
            'num_predict' => $maxTok,
        ]);
        // Garde le modèle en RAM entre deux questions (évite 30-60 s de rechargement).
        $payload['keep_alive'] = -1;
    } elseif ($sampling['seed'] !== null) {
        $payload['seed'] = $sampling['seed'];
    }
    // Voir callOpenAILike pour la justification : on ne désactive think que sur
    // les modèles confirmés boucleurs (deepseek-r1, qwq...).
    $modelLower = strtolower($model);
    $forceNoThink = $isLocal && (
        str_contains($modelLower, 'deepseek-r1') ||
        str_contains($modelLower, 'r1-distill') ||
        str_contains($modelLower, 'qwq')
    );
    if ($forceNoThink) {
        $payload['think'] = false;
        $payload['options'] = array_merge($payload['options'] ?? [], ['think' => false]);
    }
    $headers = ['Content-Type: application/json', 'Accept: text/event-stream'];
    if ($apiKey) $headers[] = 'Authorization: Bearer ' . $apiKey;

    _curlStream($apiUrl, $payload, $headers, $isLocal, function($line) use ($sseSend) {
        // Format SSE OpenAI : "data: {...}\n" — peut aussi être "data: [DONE]"
        if (!str_starts_with($line, 'data:')) return;
        $payload = trim(substr($line, 5));
        if ($payload === '' || $payload === '[DONE]') return;
        $obj = json_decode($payload, true);
        if (!is_array($obj)) return;
        $delta = $obj['choices'][0]['delta']['content'] ?? '';
        if ($delta !== '' && $delta !== null) {
            // Filtre défensif : retirer les balises <think> qu'envoient certains modèles thinking
            // (deepseek-r1, qwen3...) qui peuvent quand même apparaître en stream.
            // On garde un buffer interne via static — simple et suffisant.
            static $insideThink = false;
            $out = '';
            $remaining = $delta;
            while ($remaining !== '') {
                if ($insideThink) {
                    $pos = strpos($remaining, '</think>');
                    if ($pos === false) return; // tout le delta est dans la pensée
                    $insideThink = false;
                    $remaining = substr($remaining, $pos + 8);
                } else {
                    $pos = strpos($remaining, '<think>');
                    if ($pos === false) { $out .= $remaining; break; }
                    $out .= substr($remaining, 0, $pos);
                    $insideThink = true;
                    $remaining = substr($remaining, $pos + 7);
                }
            }
            if ($out !== '') $sseSend('text', ['delta' => $out]);
        }
    });
}

/** Streaming format Anthropic (event-stream avec types content_block_delta). */
function streamAnthropic(string $systemPrompt, array $messages, string $model, string $apiUrl, string $apiKey, callable $sseSend): void {
    $msgs = [];
    foreach ($messages as $m) {
        if ($m['role'] === 'tool') {
            $msgs[] = ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => $m['tool_call_id'] ?? '', 'content' => $m['content'] ?? '']]];
        } elseif ($m['role'] === 'assistant' && !empty($m['tool_calls'])) {
            $content = [];
            if (!empty($m['content'])) $content[] = ['type' => 'text', 'text' => $m['content']];
            foreach ($m['tool_calls'] as $tc) {
                $content[] = ['type' => 'tool_use', 'id' => $tc['id'] ?? ('toolu_' . uniqid()), 'name' => $tc['name'], 'input' => $tc['args'] ?? []];
            }
            $msgs[] = ['role' => 'assistant', 'content' => $content];
        } else {
            $msgs[] = ['role' => $m['role'], 'content' => $m['content'] ?? ''];
        }
    }
    $payload = [
        'model' => $model,
        'max_tokens' => 2048,
        'system' => $systemPrompt,
        'messages' => $msgs,
        'stream' => true,
    ];
    // Sampling déterministe (cohérence inter-modèles).
    $sampling = _getSamplingParams();
    $payload['temperature'] = $sampling['temperature'];
    $payload['top_p']       = $sampling['top_p'];
    $headers = [
        'Content-Type: application/json',
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01',
        'Accept: text/event-stream',
    ];

    _curlStream($apiUrl, $payload, $headers, false, function($line) use ($sseSend) {
        // Anthropic envoie "event: content_block_delta\ndata: {...}\n\n"
        // On ignore les lignes "event:" et on parse "data:".
        if (!str_starts_with($line, 'data:')) return;
        $payload = trim(substr($line, 5));
        if ($payload === '') return;
        $obj = json_decode($payload, true);
        if (!is_array($obj)) return;
        $type = $obj['type'] ?? '';
        if ($type === 'content_block_delta') {
            $delta = $obj['delta']['text'] ?? '';
            if ($delta !== '') $sseSend('text', ['delta' => $delta]);
        } elseif ($type === 'error') {
            $sseSend('error', ['message' => $obj['error']['message'] ?? 'Erreur Anthropic']);
        }
    });
}

/**
 * Helper interne : POST streaming via cURL, callback appelée pour chaque ligne reçue.
 * Le buffer interne reconstitue les lignes (les chunks réseau ne sont pas alignés
 * sur les \n).
 */
function _curlStream(string $url, array $payload, array $headers, bool $isLocal, callable $onLine): void {
    $ch = curl_init($url);
    $buffer = '';
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_WRITEFUNCTION => function($_ch, $data) use (&$buffer, $onLine) {
            $buffer .= $data;
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);
                $line = rtrim($line, "\r");
                if ($line !== '') $onLine($line);
            }
            return strlen($data);
        },
    ]);
    if ($isLocal || str_starts_with($url, 'http://')) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    } else {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    }
    curl_exec($ch);
    // Vider le buffer résiduel si la dernière ligne n'a pas de \n
    if ($buffer !== '') {
        foreach (explode("\n", $buffer) as $line) {
            $line = rtrim($line, "\r");
            if ($line !== '') $onLine($line);
        }
    }
    curl_close($ch);
}

/** Découpe un texte en chunks raisonnables pour un faux streaming (resp non-streamée). */
function _chunkForSSE(string $text): array {
    // On découpe par groupes d'environ 4-8 caractères, en respectant les frontières de mots.
    $chunks = [];
    $words = preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    $cur = '';
    foreach ($words as $w) {
        $cur .= $w;
        if (mb_strlen($cur) >= 6) { $chunks[] = $cur; $cur = ''; }
    }
    if ($cur !== '') $chunks[] = $cur;
    return $chunks ?: [$text];
}

/** Résumé court des arguments d'un tool call pour affichage client. */
function _summarizeArgs(array $args): string {
    $parts = [];
    foreach ($args as $k => $v) {
        if (is_array($v)) $v = count($v) . ' items';
        elseif (is_string($v) && mb_strlen($v) > 30) $v = mb_substr($v, 0, 30) . '…';
        $parts[] = "$k=$v";
    }
    return implode(', ', $parts);
}

/**
 * Nettoie la sortie du LLM avant de la streamer au client.
 * Supprime les artefacts de "réflexion à voix haute" et balises techniques
 * que produisent les modèles faibles (llama3:8b, certains qwen non-instruct...).
 *
 * Stratégie : on garde toujours la DERNIÈRE phrase (qui contient la réponse)
 * et on retire ce qui ressemble à du raisonnement avant.
 */
function _cleanLLMOutput(string $text): string {
    if ($text === '') return '';
    $original = $text;

    // 1) Balises de pensée structurées
    $text = preg_replace('#<(think|thinking|reasoning|scratchpad|reflection|analysis|plan)>.*?</\1>\s*#is', '', $text);
    $text = preg_replace('#<(think|thinking|reasoning|scratchpad|reflection|analysis|plan)>.*$#is', '', $text);

    // 2) Phrases d'annonce ("Je vais vérifier", "Let's get started", "Bon là...")
    //    On retire jusqu'à la prochaine ponctuation forte ou frontière de mot.
    $announcePatterns = [
        '/(?:^|(?<=[.!?]\s))(?:je\s+vais\s+(?:vérifier|chercher|regarder|consulter|rechercher))[^.!?\n]*[.!?]?\s*/iu',
        '/(?:^|(?<=[.!?]\s))(?:laissez-moi\s+(?:vérifier|chercher|regarder))[^.!?\n]*[.!?]?\s*/iu',
        '/(?:^|(?<=[.!?]\s))(?:let me\s+(?:check|search|look|see|find|verify))[^.!?\n]*[.!?]?\s*/iu',
        '/(?:^|(?<=[.!?]\s))(?:let\'?s\s+(?:get\s+started|see|check|begin|start))[^.!?\n]*[.!?]?\s*/iu',
        '/(?:^|(?<=[.!?]\s))(?:okay|ok|alright|sure|so|now|well|hmm+)[, ]+(?:let|I|je|on|let\'?s|nous)[^.!?\n]*[.!?]?\s*/iu',
        '/(?:^|(?<=[.!?]\s))(?:bon[, ]+(?:l[àa]|alors|voil[àa]|maintenant))[^.!?\n]*[.!?]?\s*/iu',
        '/(?:^|(?<=[.!?]\s))(?:i\s+(?:will|need\s+to|should|am\s+going\s+to|\'ll|\'m\s+going\s+to|have\s+to|must))\s+[^.!?\n]*[.!?]?\s*/iu',
    ];
    foreach ($announcePatterns as $pat) $text = preg_replace($pat, '', $text);

    // 3) Blocs "Étape N : ..." — coupe jusqu'à conclusion ou prochaine étape
    $stopWords = '(?:Étape|Etape|Step)\s*\d+\s*:|\bDonc[, ]|\bVoici\b|\bAlors[, ]|\bSo[, ]|\bThus[, ]|\bIn\s+conclusion\b|\bFinalement\b|\bLa\s+\w+\s+(?:est|se|a|sont)|\bLe\s+\w+\s+(?:est|se|a)|\bLes\s+\w+\s+(?:sont|se)';
    $text = preg_replace('/(?:Étape|Etape|Step)\s*\d+\s*:.*?(?=' . $stopWords . '|$)/isu', '', $text);

    // 4) Lignes "Resultat:" / "Result:" / "Resultats:" / "Found!"
    $text = preg_replace('/(?:R[ée]sultats?|Results?|Output)\s*:.*?(?=' . $stopWords . '|$)/isu', '', $text);
    $text = preg_replace('/\b(?:found|trouvé)!\s*/i', '', $text);

    // 5) Appels de tool inline
    $toolNamesRe = '(?:search|localiser|compter|contexte|alertes|localiser_groupe|get_fiche)';
    $text = preg_replace('/_?' . $toolNamesRe . '\s*\([^)]*\)\.{0,3}\s*/i', '', $text);
    // Variante : "[localiser](type='...', id=...)" — pseudo lien markdown que le LLM
    // produit en confondant le format tool call avec un lien
    $text = preg_replace('/\[' . $toolNamesRe . '\]\s*\([^)]*\)\.{0,3}\s*/i', '', $text);
    // Variante encore plus tordue : juste "[localiser](...)" sans paren refermée propre
    $text = preg_replace('/\[' . $toolNamesRe . '\]\s*\([^\n]{0,200}\)/i', '', $text);
    // 5bis) JSON brut renvoyé par le LLM (« {\"tool\":\"search\",\"args\":{...}} »)
    //       qu'on n'affiche pas non plus.
    $text = preg_replace('/\{\s*"tool"\s*:\s*"[a-z_]+"\s*,[^{}]{0,500}\}\s*/i', '', $text);

    // 6) Préfixes "Final answer:" / "Réponse :"
    $text = preg_replace('/^(final answer|réponse finale|answer|réponse)\s*:\s*/im', '', $text);

    // 7) Liens markdown bidons vers localhost ou ancre vide → on garde juste le label
    $text = preg_replace_callback(
        '/\[([^\]\[]+)\]\((https?:\/\/(?:localhost|127\.0\.0\.1)[^\s)]*|#[^\s)]*|)\)/',
        fn($m) => $m[1],
        $text
    );

    // 8) Espaces/lignes excessifs
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    $text = preg_replace('/[ \t]+\n/', "\n", $text);
    $text = preg_replace('/  +/', ' ', $text);
    $text = preg_replace('/\s+([.,;:!?])/u', '$1', $text); // espace avant ponctuation
    $text = trim($text);

    // 9) Sécurité : si on a tout vidé, retourner l'original (préférable à rien)
    if ($text === '') return trim($original);
    return $text;
}

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
 * Larka — Routes : Assistant IA (v3 : rapide et sobre)
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Actions :
 *   assistant_status  (GET)   état + réglages utiles au client
 *   assistant         (POST)  réponse JSON complète
 *   assistant_stream  (POST)  réponse en Server-Sent Events
 *   assistant_warmup  (POST)  préchauffe le modèle local (cache KV)
 *
 * FOURNISSEURS : anthropic, openai, mistral, gemini, copilot (distants) —
 *                ollama, lmstudio, local (OpenAI-compatible) (locaux).
 *                Le transport est dans api/AssistantLLM.php.
 *
 * CE QUI REND L'ASSISTANT RAPIDE SUR CPU (et moins cher en distant)
 *   1. PROMPT SYSTÈME STABLE. Il ne contient plus ni la question, ni le nom,
 *      ni la date, ni de sections qui varient selon l'intention : ces valeurs
 *      sont ajoutées au message utilisateur. Système + outils forment ainsi un
 *      préfixe identique d'une question à l'autre, qu'Ollama/llama.cpp gardent
 *      en cache KV (seuls les nouveaux tokens sont traités) et que les API
 *      distantes facturent au tarif « cache » (−50 à −90 %).
 *   2. RÉSULTATS D'OUTILS COMPACTS (AssistantTools::compact/encode) : quelques
 *      centaines de tokens au lieu de plusieurs milliers.
 *   3. PRÉ-RECHERCHE (modèles locaux) : la recherche est faite avant le premier
 *      appel au modèle → souvent une seule inférence au lieu de deux ou trois.
 *   4. PRÉCHAUFFAGE à l'ouverture du panneau : le préfixe est traité pendant
 *      que l'utilisateur tape.
 *   5. VRAI STREAMING à chaque tour, ping SSE, et arrêt de la génération dès que
 *      l'utilisateur ferme le panneau ou clique sur Stop.
 *   6. Ollama via /api/chat : num_ctx / num_predict / keep_alive enfin appliqués.
 *
 * MODULES COMPLÉMENTAIRES : les modules installés, actifs et accordés au rôle
 * de l'utilisateur sont interrogeables (outil `module`) ; un module absent est
 * signalé comme tel au lieu d'être inventé. Voir _assistantModules().
 *
 * DEMANDEUR : pas d'outils (RGPD strict), contexte limité injecté en fin de
 * prompt ; peut préparer une demande via le jeton [ACTION:nouvelle_demande|…].
 * ═══════════════════════════════════════════════════════════════════════════════
 */

require_once __DIR__ . '/../AssistantTools.php';
require_once __DIR__ . '/../AssistantLLM.php';

/** Fournisseurs connus : URL et modèle par défaut, hôtes autorisés (anti-SSRF). */
function _assistantProviders(): array {
    return [
        'anthropic' => ['url' => 'https://api.anthropic.com/v1/messages',           'model' => 'claude-haiku-4-5',       'hosts' => ['api.anthropic.com']],
        'openai'    => ['url' => 'https://api.openai.com/v1/chat/completions',      'model' => 'gpt-4o-mini',            'hosts' => ['api.openai.com']],
        'mistral'   => ['url' => 'https://api.mistral.ai/v1/chat/completions',      'model' => 'mistral-small-latest',   'hosts' => ['api.mistral.ai']],
        'gemini'    => ['url' => 'https://generativelanguage.googleapis.com/v1beta', 'model' => 'gemini-3.1-flash-lite',  'hosts' => ['generativelanguage.googleapis.com']],
        'copilot'   => ['url' => 'https://api.githubcopilot.com/chat/completions',  'model' => 'gpt-4o',                 'hosts' => ['api.githubcopilot.com', 'api.github.com']],
        'ollama'    => ['url' => 'http://localhost:11434/api/chat',                 'model' => 'ministral-3:3b',         'hosts' => null],
        'lmstudio'  => ['url' => 'http://localhost:1234/v1/chat/completions',       'model' => 'local-model',            'hosts' => null],
        'local'     => ['url' => 'http://localhost:8080/v1/chat/completions',       'model' => 'local-model',            'hosts' => null],
    ];
}

/** Lecture d'un réglage assistant, avec défaut. */
function _acfg(string $key, mixed $default = null): mixed {
    $v = cfg('assistant', $key);
    return ($v === null || $v === '') ? $default : $v;
}

// ═════════════════════════════════════════════════════════════════════════════
// ── Routes ──
// ═════════════════════════════════════════════════════════════════════════════

if ($action === 'assistant_status') {
    require_auth();
    $actif = (bool)_acfg('actif', false);
    $f = strtolower((string)_acfg('fournisseur', 'anthropic'));
    $local = in_array($f, AssistantLLM::LOCAL, true);
    $keyOk = $local || strlen((string)_acfg('api_key', '')) > 5;
    $timeout = _assistantTimeout($local);
    json_ok([
        'actif'       => $actif && $keyOk,
        'fournisseur' => $f,
        'local'       => $local,
        // Le client attend un peu plus que le serveur (streaming : ×1,5 côté cURL).
        'timeout_ms'  => (int)(($timeout * 1.5 + 15) * 1000),
        'prechauffage'=> $local && (bool)_acfg('prechauffage', true),
    ]);
}

if ($action === 'assistant') {
    $ctx = _assistantSetup();
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    json_ok(_assistantRun($ctx, null));
}

if ($action === 'assistant_stream') {
    $ctx = _assistantSetup();
    // Libère le verrou de session : sinon toutes les autres requêtes du même
    // utilisateur attendent la fin de la génération.
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    while (ob_get_level()) ob_end_clean();
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-transform');
    header('X-Accel-Buffering: no');   // nginx : pas de mise en tampon
    header('Connection: keep-alive');
    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', '0');
    @ini_set('implicit_flush', '1');
    @set_time_limit(0);

    $lastOut = microtime(true);
    $emit = function (string $type, array $extra = []) use (&$lastOut) {
        echo 'data: ' . json_encode(['type' => $type] + $extra, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n\n";
        @ob_flush(); @flush();
        $lastOut = microtime(true);
    };
    // Tick : ping toutes les 5 s (nginx/proxy ne coupent pas pendant que le
    // modèle traite le prompt) ; si le client est parti → on abandonne la
    // génération, le moteur local est libéré immédiatement.
    $ctx['llm']->setTick(function () use (&$lastOut): bool {
        if (microtime(true) - $lastOut >= 5) {
            echo ": ping\n\n";
            @ob_flush(); @flush();
            $lastOut = microtime(true);
        }
        return connection_aborted() === 1;
    });
    $emit('start', ['fournisseur' => $ctx['llm']->fournisseur(), 'local' => $ctx['llm']->isLocal()]);
    $res = _assistantRun($ctx, $emit);
    if (empty($res['_aborted'])) {
        $emit('done', [
            'mode'        => $res['mode'] ?? '',
            'fournisseur' => $ctx['llm']->fournisseur(),
            'model'       => $ctx['llm']->model(),
            'rounds'      => $res['rounds'] ?? null,
            'tool_calls_count' => count($res['tool_calls'] ?? []),
            'usage'       => $res['usage'] ?? [],
        ]);
    }
    exit;
}

if ($action === 'assistant_warmup') {
    global $method;
    if ($method !== 'POST') json_error('Méthode non supportée.', 405);
    $user = require_auth();
    if (!(bool)_acfg('actif', false)) json_ok(['skipped' => 'inactif']);
    $f = strtolower((string)_acfg('fournisseur', 'anthropic'));
    // Distant : inutile (et facturé). Local : seulement si activé.
    if (!in_array($f, AssistantLLM::LOCAL, true) || !(bool)_acfg('prechauffage', true)) {
        json_ok(['skipped' => 'non_local']);
    }
    check_rate_limit('assistant_warmup_' . ($user['Id'] ?? 0), 10, 300);
    $ctx = _assistantSetup(true);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    ignore_user_abort(true);   // le préchauffage sert même si l'onglet se ferme

    // Un seul préchauffage par préfixe et par minute (plusieurs onglets, F5…).
    $sig = md5($ctx['llm']->model() . '|' . $ctx['system'] . '|' . json_encode($ctx['tools']->getOpenAISchema()));
    $dir = __DIR__ . '/../../data/cache_assistant';
    if (!is_dir($dir)) @mkdir($dir, 0770, true);
    $stamp = $dir . '/warmup_' . $sig . '.stamp';
    if (is_file($stamp) && time() - filemtime($stamp) < 60) json_ok(['skipped' => 'recent']);
    @touch($stamp);

    $r = $ctx['llm']->warmup($ctx['system'], $ctx['useTools'] ? $ctx['tools'] : null);
    json_ok(['ok' => empty($r['_error']), 'erreur' => $r['_error'] ?? null, 'usage' => $r['usage'] ?? []]);
}

// ═════════════════════════════════════════════════════════════════════════════
// ── Préparation commune ──
// ═════════════════════════════════════════════════════════════════════════════

function _assistantTimeout(bool $local): int {
    $t = (int)_acfg('timeout_seconds', 0);
    if ($t <= 0) $t = $local ? 240 : 90;
    return max(30, min(600, $t));
}

/**
 * Validation + construction du contexte (LLM, outils, prompt, messages).
 * @param bool $warmup  true = pas de question (préchauffage)
 */
function _assistantSetup(bool $warmup = false): array {
    global $db, $method;
    $user = require_auth();
    if (!$warmup) {
        if ($method !== 'POST') json_error('Méthode non supportée.', 405);
        if (!(bool)_acfg('actif', false)) json_error('L\'assistant IA est désactivé.', 403);
        check_rate_limit('assistant_' . ($user['Id'] ?? 0), 20, 300);
    }

    $question = ''; $history = [];
    if (!$warmup) {
        $b = get_body();
        $question = trim((string)($b['question'] ?? ''));
        if ($question === '') json_error('Question vide.', 400);
        if (mb_strlen($question) > 2000) json_error('Question trop longue (max 2000 caractères).', 400);
        $history = is_array($b['history'] ?? null) ? $b['history'] : [];
    }

    $providers = _assistantProviders();
    $f = strtolower((string)_acfg('fournisseur', 'anthropic'));
    if (!isset($providers[$f])) json_error("Fournisseur non reconnu : $f.", 400);
    $local = in_array($f, AssistantLLM::LOCAL, true);
    $apiKey = (string)_acfg('api_key', '');
    $model  = trim((string)_acfg('model', '')) ?: $providers[$f]['model'];
    $apiUrl = trim((string)_acfg('api_url', '')) ?: $providers[$f]['url'];
    if (!$local && strlen($apiKey) < 5) json_error("Clé API non configurée pour \"$f\".", 400);

    // ── Anti-SSRF : hôte autorisé + HTTPS pour le distant, réseau privé pour le local.
    $u = parse_url($apiUrl);
    if (!$u || empty($u['host']) || empty($u['scheme'])) json_error("URL d'API invalide.", 400);
    $scheme = strtolower($u['scheme']); $host = strtolower($u['host']);
    if (!in_array($scheme, ['http', 'https'], true)) json_error("Schéma d'URL non autorisé : $scheme.", 400);
    if ($local) {
        $isLocalHost = in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]', '0.0.0.0'], true);
        $isPrivateIp = filter_var(trim($host, '[]'), FILTER_VALIDATE_IP)
            && !filter_var(trim($host, '[]'), FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        $isLocalDomain = (bool)preg_match('/\.(local|lan|internal|home)$/i', $host);
        if (!$isLocalHost && !$isPrivateIp && !$isLocalDomain) json_error("Host non autorisé pour provider local : $host.", 400);
    } else {
        if (!in_array($host, $providers[$f]['hosts'], true)) json_error("Host non autorisé pour $f : $host.", 400);
        if ($scheme !== 'https') json_error("HTTPS requis pour $f.", 400);
    }

    $temp = _acfg('temperature');
    $llm = new AssistantLLM([
        'fournisseur'     => $f,
        'model'           => $model,
        'url'             => $apiUrl,
        'key'             => $apiKey,
        'timeout'         => _assistantTimeout($local),
        // Réponses de 1 à 5 lignes : 512 tokens suffisent en local et bornent
        // la durée d'un tour sur CPU (~12-25 tok/s pour un 3B).
        'max_tokens'      => (int)($local ? _acfg('max_tokens_local', 512) : _acfg('max_tokens', 1024)),
        'temperature'     => is_numeric($temp) ? (float)$temp : 0.2,
        'temperature_explicite' => is_numeric($temp),
        'top_p'           => is_numeric(_acfg('top_p')) ? (float)_acfg('top_p') : 0.9,
        'seed'            => is_numeric(_acfg('seed')) ? (int)_acfg('seed') : null,
        'num_ctx'         => (int)_acfg('num_ctx', 8192),
        'num_thread'      => (int)_acfg('num_thread', 0),
        'keep_alive'      => _acfg('keep_alive', -1),
        'reflexion'       => (string)_acfg('reflexion_locale', 'auto'),
        'gemini_thinking' => (string)_acfg('gemini_reflexion', 'auto'),
        'reasoning_effort'=> (string)_acfg('reasoning_effort', 'low'),
        'anthropic_cache' => (bool)_acfg('cache_prompt', true),
        'ollama_natif'    => (bool)_acfg('ollama_natif', true),
    ]);

    // Jeton Microsoft Graph, capturé AVANT session_write_close().
    $msToken = null;
    if (defined('SHAREPOINT_ENABLED') && SHAREPOINT_ENABLED
        && !empty($_SESSION['ms_access_token'])
        && (int)($_SESSION['ms_token_expires'] ?? 0) > time() + 60) {
        $msToken = $_SESSION['ms_access_token'];
    }

    $role = (string)($user['Role'] ?? '');
    $isDemandeur = $role === 'Demandeur';
    [$modules, $extActif, $dispo] = _assistantModules($db, $user);

    $tools = new AssistantTools($db, $isDemandeur ? null : $msToken, [
        'user'           => $user,
        'max_resultats'  => (int)_acfg('max_resultats', $local ? 6 : 12),
        'max_chars'      => $local ? 90 : 160,
        'max_tool_chars' => $local ? 5000 : 14000,
        'modules'        => $isDemandeur ? [] : $modules,
        'ext_actif'      => !$isDemandeur && $extActif && in_array($role, ['Gestionnaire', 'Admin'], true),
        'modules_disponibles' => in_array($role, ['Gestionnaire', 'Admin'], true) ? $dispo : null,
    ]);

    $userName = trim(($user['Prenom'] ?? '') . ' ' . ($user['Nom'] ?? ''));
    $system = $isDemandeur
        ? _promptDemandeur($db, $user, $userName, $modules)
        : _promptGestionnaire($tools);

    // Historique : peu de messages, et tronqués — un long échange passé coûte
    // autant à retraiter qu'un prompt entier.
    $maxHist = $local ? 6 : 12;
    $maxLen  = $local ? 600 : 1500;
    $messages = [];
    foreach (array_slice($history, -$maxHist) as $h) {
        if (!is_array($h) || !in_array($h['role'] ?? '', ['user', 'assistant'], true)) continue;
        $c = trim((string)($h['content'] ?? ''));
        if ($c === '') continue;
        if (mb_strlen($c) > $maxLen) $c = mb_substr($c, 0, $maxLen) . '…';
        $messages[] = ['role' => $h['role'], 'content' => $c];
    }
    // Les valeurs variables vivent ICI, pas dans le prompt système (cache).
    $messages[] = ['role' => 'user', 'content' =>
        '[' . date('Y-m-d') . ' · ' . ($userName ?: 'utilisateur') . ' (' . ($role ?: '?') . ")]\n" . ($warmup ? 'ok' : $question)];

    return [
        'user' => $user, 'db' => $db, 'llm' => $llm, 'tools' => $tools, 'system' => $system,
        'messages' => $messages, 'question' => $question, 'history' => $history,
        'isDemandeur' => $isDemandeur, 'intents' => _assistantIntents($question),
        'useTools' => !$isDemandeur && !_assistantNoTools($f, $model),
        'local' => $local,
    ];
}

/** Détection d'intention (sert à décider de la pré-recherche). */
function _assistantIntents(string $question): array {
    $ql = mb_strtolower($question);
    $re = [
        'localisation'   => '/\b(où|ou se trouve|ou est|ou sont|localisation|emplacement|situé|étage|bâtiment|bureau|local)\b/u',
        'identification' => '/\b(quel(le|s|les)?|qu\'est-ce|c\'est quoi|nature|type de|marque|modèle)\b/u',
        'personne'       => '/\b(qui|responsable|gestionnaire|contact|joindre|téléphone|annuaire)\b/u',
        'temporel'       => '/\b(quand|date|échéance|expire|prochain|dernier|cette année|ce mois)\b/u',
        'quantite'       => '/\b(combien|nombre|total|montant|coût|prix|quantité)\b/u',
        'statut'         => '/\b(état|statut|en cours|terminé|en retard|en attente|où en est|avancement)\b/u',
        'liste'          => '/\b(liste|lister|tous les|toutes les|donne moi|énumère)\b/u',
        'plans'          => '/\b(points?|zones?|traits?|calques?|surface|superficie|m²|m2)\b/u',
        'alertes'        => '/\b(alerte|urgent|expire|expirent|retard|critique|à traiter|seuil)\b/u',
    ];
    $out = [];
    foreach ($re as $n => $r) if (preg_match($r, $ql)) $out[] = $n;
    return $out ?: ['recherche_generale'];
}

/** Modèle local connu pour NE PAS gérer les outils (appris, 24 h). */
function _assistantNoTools(string $f, string $model, ?bool $set = null): bool {
    if (!in_array($f, AssistantLLM::LOCAL, true)) return false;
    $dir = __DIR__ . '/../../data/cache_assistant';
    if (!is_dir($dir)) @mkdir($dir, 0770, true);
    $file = $dir . '/tool_calling_' . preg_replace('/[^a-z0-9]/i', '', $f) . '.json';
    $data = is_file($file) ? (json_decode((string)@file_get_contents($file), true) ?: []) : [];
    if ($set !== null) {
        $data[$model] = ['supports' => !$set, 'ts' => time()];
        @file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        return $set;
    }
    $e = $data[$model] ?? null;
    if (!is_array($e) || time() - (int)($e['ts'] ?? 0) > 86400) return false;
    return empty($e['supports']);
}

/**
 * Modules complémentaires visibles par l'utilisateur.
 *
 * @return array{0: array, 1: bool, 2: ?callable}
 *   [catalogue id => [nom, jeux, pages, declaration], couche active, rappel « non installés »]
 *
 * Un module n'apparaît que s'il est installé, ACTIF, intègre (empreinte),
 * visible pour ce rôle (rôles accordés par l'administrateur) — c'est
 * ExtRegistre qui en décide, pas l'assistant. Un jeu de données n'est proposé
 * que si une page le présente à ce rôle (même règle que l'écran du module).
 * Toute erreur de la couche extensions est absorbée : l'assistant fonctionne
 * sans modules plutôt que pas du tout.
 */
function _assistantModules($db, array $user): array {
    if (!(bool)(cfg('extensions', 'actif') ?? false)) return [[], false, null];
    try {
        require_once __DIR__ . '/../extensions/Registre.php';
        $reg = ExtRegistre::initialiser($db, $user);
        $role = (string)($user['Role'] ?? '');
        $modules = [];
        foreach ($reg->actives() as $id => $man) {
            if (!$man->estDeclaratif() || !$reg->visiblePour($id, $user)) continue;
            $decl = $man->declaration();
            $pagesRole = array_values(array_filter($decl['pages'] ?? [],
                fn($p) => in_array($role, $p['roles'] ?? [], true)));
            $jeux = [];
            foreach ($decl['donnees'] ?? [] as $nom => $jeu) {
                $lisible = false;
                foreach ($pagesRole as $p) {
                    if (($p['vue']['source'] ?? null) === $nom) { $lisible = true; break; }
                }
                if (!$lisible) continue;
                $champs = []; $formats = [];
                foreach ($jeu['champs'] ?? [] as $k => $c) {
                    if (in_array($k, ['etage_id', 'lat', 'lng'], true)) continue;
                    if (count($champs) < 14) $champs[$k] = (string)($c['libelle'] ?? $k);
                    if (!empty($c['format_saisie'])) $formats[$k] = (string)$c['format_saisie'];
                }
                $jeux[$nom] = ['libelle' => (string)($jeu['libelle_pluriel'] ?? $jeu['libelle'] ?? $nom),
                               'champs' => $champs, 'formats' => $formats];
            }
            $pages = array_map(fn($p) => (string)($p['titre'] ?? ''), $pagesRole);
            if (!$jeux && !$pages) continue;
            $modules[$id] = ['nom' => $man->nom(), 'jeux' => $jeux, 'pages' => $pages, 'declaration' => $decl];
        }
        $dispo = function () use ($reg, $modules): array {
            $out = [];
            foreach ($reg->inventaire() as $l) {
                if (isset($modules[$l['identifiant']])) continue;
                $etat = empty($l['installee']) ? 'non installé'
                      : (empty($l['active']) ? (!empty($l['suspendue']) ? 'suspendu' : 'installé, désactivé') : 'installé, non accordé à ce rôle');
                $out[$l['identifiant']] = ['module' => $l['identifiant'], 'nom' => $l['nom'] ?? $l['identifiant'], 'etat' => $etat];
            }
            foreach ($reg->paquetsDisponibles() as $p) {
                if (empty($p['valide']) || isset($out[$p['identifiant']]) || isset($modules[$p['identifiant']])) continue;
                if (!empty($p['deja_installe'])) continue;
                $out[$p['identifiant']] = ['module' => $p['identifiant'], 'nom' => $p['nom'], 'etat' => 'paquet disponible, non installé'];
            }
            return array_values($out);
        };
        return [$modules, $reg->actif(), $dispo];
    } catch (\Throwable $e) {
        error_log('[Larka][assistant] modules: ' . $e->getMessage());
        return [[], false, null];
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// ── Prompts (STABLES : rien de variable d'une question à l'autre) ──
// ═════════════════════════════════════════════════════════════════════════════

function _promptGestionnaire(AssistantTools $tools): string {
    $names = $tools->names();
    $p = <<<'PROMPT'
Tu es l'assistant de Larka (GMAO). Tu réponds à un gestionnaire, en français, court et factuel.
La première ligne du message utilisateur donne [date · utilisateur (rôle)].

RÈGLES
1. Toute donnée précise (ID, nom, lieu, date, nombre, statut, montant) vient d'un résultat d'outil obtenu pour CETTE question. Jamais d'invention, jamais d'ID deviné ou repris de l'historique.
2. « Aucun » ou « il n'y a pas » seulement si un outil l'a confirmé.
3. Ne réponds JAMAIS « soyez plus précis » sans avoir cherché. Plusieurs candidats → cite les 3 à 5 meilleurs (lien + emplacement) puis demande lequel.
4. [personne], [email], [téléphone] = données masquées : ne spécule pas dessus.
5. N'écris ni tes appels d'outils ni ton raisonnement : seulement la réponse.

CHOIX DES OUTILS
- Recherche tolérante : écris la requête avec les mots de l'utilisateur, même mal orthographiés ou familiers, numéro et lieu compris (« extincteur 2 centre technique ») ; l'outil corrige les fautes (champ corrections) et classe par pertinence.
- Résultat avec meilleur_candidat → c'est l'élément demandé : utilise-le directement, sans redemander.
- 0 résultat → relance search avec le seul nom de l'objet (« extincteur »), puis type='tout', avant de conclure.
- « Où est X ? » → search puis localiser(type, id). Plusieurs éléments du même type → localiser_groupe. Position prioritaire : sur_plan ; sinon batiment/etage/bureau ; sinon « localisation non renseignée ».
- « Combien ? » → compter, jamais d'estimation (search est limité). Si un compter figure déjà dans la conversation, son count EST la réponse. type accepte le singulier (bien, equipement…). Filtres : famille, batiment, etage, etat, statut, type, categorie, urgence, priorite, societe, annee=AAAA ; pour les plans : type_element (point/zone/trait/texte), calque.
- Détail d'un élément → get_fiche après search.
- Échéances, retards, stock bas → alertes.
- Personnes et contacts → qui_est. Demandeur d'une demande : get_fiche(demande) → UtilisateurId → qui_est(id).
- « Points, zones, traits » = éléments dessinés sur les plans → compter(type='plans') ou search(type='plans').
- Terme local inconnu → contexte.
- Si un search(type='tout') figure déjà dans la conversation, utilise ses résultats avant de rappeler un outil.
PROMPT;
    if (in_array('module', $names, true)) {
        $p .= "\n- Données des modules complémentaires (voir la description de l'outil module) → module(module, jeu, recherche). "
            . "Sujet couvert ni par Larka ni par un module installé → dis-le ; module() sans argument indique aussi les modules non installés. "
            . "Lien vers l'écran d'un module : [MODULE:identifiant:libellé].";
    }
    $p .= <<<'PROMPT'


LIENS : chaque élément cité est un lien, avec l'ID exact d'un résultat d'outil.
[FICHE:bien:ID:libellé] [FICHE:equipement:ID:libellé] [FICHE:intervention:ID:libellé] [FICHE:contrat:ID:libellé] [FICHE:demande:ID:libellé] [FICHE:stock:ID:libellé] [DOC:ID:nom du fichier]
Plan, un élément : [FICHE:plan:EtageId:ElementId:libellé] — plusieurs éléments d'un même étage : [FICHE:plans:EtageId:Id1,Id2,Id3:libellé] (virgules sans espace, un lien par étage).

FORMAT : 1 à 5 lignes, chiffres et noms clés en **gras**, puces pour plusieurs éléments (10 au plus, puis « et N autres »). Rien trouvé → dis-le et propose une reformulation. Question hors Larka → indique que tu ne réponds que sur les données Larka.

EXEMPLES
Q : Où est la centrale SSI ?
(search equipements « centrale SSI » → Id 42 ; localiser → sur_plan EtageId 3, ElementId 8, Bât A, RDC)
R : La centrale SSI est au **Bâtiment A, RDC**. [FICHE:equipement:42:Centrale SSI] · [FICHE:plan:3:8:Voir sur le plan]
Q : ou est l'exctincteur 2 du centre technique
(search tout « exctincteur 2 centre technique » → corrections exctincteur→extincteur, meilleur_candidat equipements Id 57 ; localiser)
R : L'**extincteur n°2** du **Centre technique** est au 1er étage. [FICHE:equipement:57:Extincteur 2] · [FICHE:plan:5:31:Voir sur le plan]
PROMPT;
    // Variable selon l'utilisateur : placé EN DERNIER pour ne pas casser le
    // préfixe commun mis en cache.
    if (in_array('search_sharepoint', $names, true)) {
        $p .= "\n\nSHAREPOINT : si search(type='documents') ne trouve rien, ou si l'on parle de SharePoint/OneDrive → search_sharepoint. "
            . "Cite chaque fichier avec [SPDOC:url|nom] (url EXACTE du résultat).";
    }
    return $p;
}

/**
 * Prompt Demandeur : instructions STABLES d'abord (mises en cache), contexte
 * propre au demandeur ENSUITE (gestionnaires, ses demandes, modules).
 */
function _promptDemandeur($db, array $user, string $userName, array $modules): string {
    $p = <<<'PROMPT'
Tu es l'assistant Larka d'un Demandeur. Ton ton est CHALEUREUX, simple, sans jargon. Tu tutoies.
La première ligne du message utilisateur donne [date · utilisateur (rôle)].

═══ RÔLES LARKA ═══
• Demandeur : crée et suit ses demandes d'intervention.
• Gestionnaire : reçoit les demandes, les valide et les transforme en interventions.
• Admin : configure l'application et gère les comptes.
• Technicien : exécute les interventions (interne ou prestataire).
En cas d'urgence, on contacte un Gestionnaire (liste dans le CONTEXTE plus bas). Ne réponds jamais « je n'ai pas l'info » sur les rôles.

═══ CRÉER UNE DEMANDE ═══
Deux types : **Technique** (panne, intervention, besoin matériel…) et **Archive** (archivage, ou désarchivage = sortir un dossier des archives).
Dès que l'utilisateur décrit un problème ou un besoin, ou te demande de faire une demande :
1. Prépare les champs à partir de la conversation. Règles :
   - CONTEXTE = maintenance d'un bâtiment. Lis les mots dans ce sens et corrige les fautes : « poignet » = poignée (de porte ou de fenêtre), « clim » = climatisation, « néon » = luminaire, « chasse » = chasse d'eau. Jamais d'interprétation médicale ou personnelle.
   - titre = l'objet + le problème, 3 à 8 mots, SANS lieu (ex. « Poignée de porte défectueuse »).
   - Le lieu va dans les champs : batiment (s'il est cité ou connu), bureau = bureau, salle, étage ou emplacement (ex. « Bureau 235, 2e étage »).
   - description = une ou deux phrases claires qui reprennent ce que l'utilisateur a dit, lieu compris, sans rien inventer.
   - Élément incertain (quelle porte ? quel bâtiment ?) : laisse le champ vide, l'utilisateur complétera.
2. Écris une phrase naturelle (« Je te prépare la demande, tu n'auras plus qu'à vérifier. »),
3. puis le jeton, seul sur la dernière ligne :
   Technique : [ACTION:nouvelle_demande|type=Technique|titre=…|description=…|batiment=…|bureau=…|categorie=…|urgence=…]
     batiment et categorie pris dans le CONTEXTE (ou vides) ; urgence = Faible, Normale, Élevée ou Urgente (Normale par défaut).
   Archivage : [ACTION:nouvelle_demande|type=Archive|prestation=Archivage|description=…|nb_dossiers=…|service=…|periode=…]
   Désarchivage : [ACTION:nouvelle_demande|type=Archive|prestation=Desarchivage|description=…|dossiers=DOS-1,DOS-2|date_solde=AAAA-MM-JJ]
Format strict : champs séparés par |, clé=valeur, ni retour à la ligne, ni guillemets, ni | ou = dans les valeurs. « Desarchivage » sans accent.
Tu PEUX créer la demande (le formulaire s'ouvre, l'utilisateur valide) : ne dis jamais le contraire. Chaque nouvelle demande = un nouveau jeton, même si tu en as déjà émis un.

EXEMPLES
« probleme de poignet a mon etage (2eme, bureau 235) »
→ Je te prépare la demande pour la poignée, tu n'auras plus qu'à vérifier.
[ACTION:nouvelle_demande|type=Technique|titre=Poignée défectueuse|description=Problème de poignée (porte ou fenêtre) au bureau 235, 2e étage.|batiment=|bureau=Bureau 235, 2e étage|categorie=|urgence=Normale]
« La centrale SSI bipe sans cesse dans le bâtiment Test »
→ Je te prépare la demande, tu n'auras plus qu'à la vérifier.
[ACTION:nouvelle_demande|type=Technique|titre=Centrale SSI en alarme continue|description=La centrale SSI émet un bip continu dans le bâtiment Test.|batiment=Test|bureau=|categorie=Sécurité incendie|urgence=Élevée]
« Il faut archiver une cinquantaine de dossiers RH de 2018 à 2023 »
→ C'est noté, je te prépare la demande d'archivage.
[ACTION:nouvelle_demande|type=Archive|prestation=Archivage|description=Archivage d'une cinquantaine de dossiers RH 2018-2023.|nb_dossiers=50|service=Ressources Humaines|periode=2018-2023]

═══ AUTRES RÈGLES ═══
- Français, 2 à 5 lignes hors jeton.
- N'invente aucun nom, statut ou catégorie absent du CONTEXTE.
- « Ma demande », « où en est ma demande #N », « qui s'occupe de ma demande » : uniquement d'après « Tes dernières demandes ». Intervention rattachée → InterventionAgent indique qui la suit ; sinon, statut Nouveau/À traiter = en attente d'un gestionnaire (propose d'en appeler un). Absente de la liste → propose d'en créer une.
- Hors Larka (vie privée, météo, autres applis…) : indique poliment que tu n'aides que pour Larka.
- Pas de jeton [FICHE:…] ni [DOC:…]. Jetons autorisés : [ACTION:nouvelle_demande|…] et [MODULE:identifiant:libellé] (lien vers un écran de module listé dans le CONTEXTE).
PROMPT;

    // ── Contexte (variable) ─────────────────────────────────────────────
    $q = function (string $sql, array $params = []) use ($db): array {
        try { return $db->fetchAll($sql, $params); } catch (\Throwable $_) { return []; }
    };
    $services  = array_column($q("SELECT DISTINCT Service FROM Utilisateurs WHERE Actif = 1 AND Service IS NOT NULL AND Service != '' ORDER BY Service LIMIT 30"), 'Service');
    $categories = array_column($q("SELECT DISTINCT Categorie FROM DemandesIntervention WHERE Categorie IS NOT NULL AND Categorie != '' ORDER BY Categorie LIMIT 30"), 'Categorie');
    $batiments = array_column($q("SELECT DISTINCT Valeur FROM ListesReferences WHERE Categorie = 'Batiment' AND Valeur IS NOT NULL AND Valeur != '' ORDER BY Valeur LIMIT 30"), 'Valeur');
    if (count($batiments) < 30) {
        foreach ($q("SELECT DISTINCT Batiment FROM DemandesIntervention WHERE Batiment IS NOT NULL AND Batiment != '' ORDER BY Batiment LIMIT 30") as $r) {
            if (!in_array($r['Batiment'], $batiments, true)) $batiments[] = $r['Batiment'];
        }
    }
    $gest = [];
    foreach ($q("SELECT Prenom, Nom, Poste, Service, Role, Email, Tel, TelMobile, TelPro
                 FROM Utilisateurs WHERE Actif = 1 AND Role IN ('Gestionnaire','Admin')
                 ORDER BY Role DESC, Nom LIMIT 20") as $r) {
        $nom = trim(($r['Prenom'] ?? '') . ' ' . ($r['Nom'] ?? ''));
        $detail = trim($r['Poste'] ?? '') ?: trim($r['Service'] ?? '') ?: trim($r['Role'] ?? '');
        $tel = trim($r['TelMobile'] ?? '') ?: trim($r['TelPro'] ?? '') ?: trim($r['Tel'] ?? '');
        $line = $detail ? "$nom ($detail)" : "$nom [profil incomplet]";
        $coords = array_filter([$tel ? "📞 $tel" : '', trim($r['Email'] ?? '') ? '✉️ ' . trim($r['Email']) : '']);
        if ($coords) $line .= ' — ' . implode(', ', $coords);
        $gest[] = $line;
    }
    // Relation portée par Interventions.DemandeId ; l'agent est en clair dans
    // AgentPrenom/AgentNom ; l'échéance utile est DateIntervention.
    $demandes = $q(
        "SELECT d.Id, d.Titre, d.Statut, d.Urgence, d.Categorie, d.Batiment, d.DateCreation, d.CommentaireAdmin,
                i.Numero AS InterventionNumero, i.Statut AS InterventionStatut,
                i.DateIntervention AS InterventionDate, i.AgentPrenom, i.AgentNom
         FROM DemandesIntervention d
         LEFT JOIN Interventions i ON i.DemandeId = d.Id
         WHERE d.UtilisateurId = :uid
         ORDER BY d.DateCreation DESC LIMIT 10",
        ['uid' => (int)($user['Id'] ?? 0)]
    );
    $fmt = fn(array $a) => $a ? implode(', ', $a) : 'aucun connu';

    $p .= "\n\n═══ CONTEXTE ═══\n"
        . "• Demandeur : $userName — service : " . (trim($user['Service'] ?? '') ?: '?') . ", poste : " . (trim($user['Poste'] ?? '') ?: '?') . "\n"
        . "• Services : " . $fmt($services) . "\n"
        . "• Bâtiments : " . $fmt($batiments) . "\n"
        . "• Catégories de demande : " . $fmt($categories) . "\n"
        . "• Urgences : Faible, Normale, Élevée, Urgente\n"
        . "• Gestionnaires (contacts en cas d'urgence) :\n"
        . ($gest ? '  – ' . implode("\n  – ", $gest) : "  – aucun en base : en cas d'urgence, contacter son responsable de service.") . "\n";
    if ($demandes) {
        $p .= "• Tes dernières demandes :\n";
        foreach ($demandes as $d) {
            $agent = trim(($d['AgentPrenom'] ?? '') . ' ' . ($d['AgentNom'] ?? ''));
            $p .= "  – #{$d['Id']} « " . mb_substr((string)$d['Titre'], 0, 80) . " » — " . ($d['Statut'] ?: '?')
                . ", urgence " . ($d['Urgence'] ?: '?') . ", " . substr((string)$d['DateCreation'], 0, 10)
                . ($d['Batiment'] ? ", bât. {$d['Batiment']}" : '')
                . ($d['InterventionNumero'] ? " | intervention {$d['InterventionNumero']} (" . ($d['InterventionStatut'] ?: '?')
                    . ($d['InterventionDate'] ? ', prévue ' . substr((string)$d['InterventionDate'], 0, 10) : '')
                    . ($agent ? ", InterventionAgent : $agent" : '') . ')' : '')
                . ($d['CommentaireAdmin'] ? ' | commentaire : ' . mb_substr((string)$d['CommentaireAdmin'], 0, 120) : '')
                . "\n";
        }
    } else {
        $p .= "• Tu n'as encore aucune demande enregistrée.\n";
    }
    if ($modules) {
        $p .= "• Modules complémentaires accessibles :\n";
        foreach ($modules as $id => $m) {
            foreach ($m['pages'] as $titre) {
                if ($titre !== '') $p .= "  – « $titre » (module {$m['nom']}) → [MODULE:$id:$titre]\n";
            }
        }
    }
    return $p;
}

// ═════════════════════════════════════════════════════════════════════════════
// ── Boucle unique (JSON et SSE) ──
// ═════════════════════════════════════════════════════════════════════════════

/**
 * @param callable|null $emit  fn(type, extra) — SSE ; null = mode JSON
 * @return array reply, mode, rounds, tool_calls, usage (+ _aborted)
 */
function _assistantRun(array $ctx, ?callable $emit): array {
    /** @var AssistantLLM $llm */ $llm = $ctx['llm'];
    /** @var AssistantTools $tools */ $tools = $ctx['tools'];
    $system = $ctx['system'];
    $messages = $ctx['messages'];
    $usage = ['in' => 0, 'out' => 0, 'cached' => 0, 'ms' => 0, 'appels' => 0];
    $addUsage = function (array $u) use (&$usage) {
        foreach (['in', 'out', 'cached', 'ms'] as $k) $usage[$k] += (int)($u[$k] ?? 0);
        if (isset($u['tok_s'])) $usage['tok_s'] = $u['tok_s'];
        if (isset($u['prompt_ms'])) $usage['prompt_ms'] = ($usage['prompt_ms'] ?? 0) + $u['prompt_ms'];
        $usage['appels']++;
    };

    // Texte : diffusé tel quel en streaming, nettoyé à la fin (« replace »).
    // $sent = texte réellement affiché côté client pour le tour en cours.
    $streamed = false; $sent = '';
    $onText = $emit ? function (string $d) use ($emit, &$streamed, &$sent) {
        $streamed = true; $sent .= $d; $emit('text', ['delta' => $d]);
    } : null;
    $finish = function (string $raw, string $mode, int $rounds, array $log) use ($emit, &$streamed, &$sent, &$usage, $tools): array {
        $clean = _cleanLLMOutput($raw, $tools->names());
        if ($clean === '') $clean = $raw;
        if ($emit) {
            if (!$streamed) $emit('text', ['delta' => $clean]);
            elseif ($clean !== $sent) $emit('replace', ['text' => $clean]);
        }
        return ['reply' => $clean, 'mode' => $mode, 'rounds' => $rounds, 'tool_calls' => $log, 'usage' => $usage];
    };
    $fail = function (array $r, string $mode, array $log) use ($emit, &$usage): array {
        if (!empty($r['aborted']) || str_contains((string)($r['_error'] ?? ''), 'abandonnée')) {
            return ['_aborted' => true, 'reply' => '', 'mode' => $mode, 'tool_calls' => $log, 'usage' => $usage];
        }
        $msg = (string)($r['_error'] ?? 'Erreur inconnue');
        if ($emit) $emit('error', ['message' => $msg]);
        return ['reply' => '❌ Erreur IA : ' . $msg, 'mode' => $mode, 'tool_calls' => $log, 'usage' => $usage, 'error' => $msg];
    };

    // ── Demandeur : un seul appel, sans outils ──────────────────────────
    if ($ctx['isDemandeur']) {
        $r = $llm->chat($system, $messages, null, $onText);
        $addUsage($r['usage']);
        if (isset($r['_error'])) return $fail($r, 'demandeur', []);
        return $finish($r['text'], 'demandeur', 1, []);
    }

    // ── Modèle sans outils : recherche automatique injectée ─────────────
    if (!$ctx['useTools']) return _assistantLegacy($ctx, $emit, $onText, $finish, $fail, $addUsage);

    $log = [];
    $cache = [];
    $local = $ctx['local'];
    $maxRounds = $local ? 3 : 5;

    // ── Pré-recherche (mode rapide) ─────────────────────────────────────
    $pre = strtolower((string)_acfg('pre_recherche', 'auto'));
    $preOn = $pre === 'on' || ($pre === 'auto' && $local);
    // « Combien… ? » : comptage exact fait d'avance (souvent la seule
    // information nécessaire). Alertes, personnes, éléments de plan : outils
    // dédiés, laissés au modèle.
    $isCount = in_array('quantite', $ctx['intents'], true);
    $skipIntents = array_intersect($ctx['intents'], ['alertes', 'personne', 'plans']);
    $isFollowUp = $ctx['history'] && str_word_count($ctx['question']) <= 3;
    $prefetched = false;
    if ($preOn && !$isFollowUp && ($isCount || !$skipIntents)) {
        $pf = $isCount ? $tools->prefetchComptage($ctx['question']) : $tools->prefetch($ctx['question']);
        if ($pf) {
            $id = AssistantLLM::newId();
            $name = $pf['name'];
            $messages[] = ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => $id, 'name' => $name, 'args' => $pf['args']]]];
            $messages[] = ['role' => 'tool', 'tool_call_id' => $id, 'name' => $name, 'content' => $tools->encode($pf['result'])];
            $n = (int)($pf['result']['count'] ?? $pf['result']['total'] ?? 0);
            $sorted = $pf['args']; ksort($sorted);
            $cache[$name . ':' . md5(json_encode($sorted, JSON_UNESCAPED_UNICODE))] = $pf['result'];
            $log[] = ['name' => $name, 'args' => $pf['args'], 'result_size' => $n, 'prefetch' => true];
            if ($emit) {
                $emit('tool_call', ['name' => $name, 'args_summary' => _summarizeArgs($pf['args'])]);
                $emit('tool_result', ['name' => $name, 'size' => $n, 'cached' => false]);
            }
            $prefetched = true;
        }
    }

    for ($round = 0; $round < $maxRounds; $round++) {
        $streamed = false; $sent = '';
        $r = $llm->chat($system, $messages, $tools, $onText, ['cache_tail' => $round > 0 || $prefetched]);
        $addUsage($r['usage']);

        if (isset($r['_error'])) {
            $e = strtolower($r['_error']);
            // Modèle local sans tool calling → mémorisé, bascule en recherche automatique.
            if (preg_match('/does not support tools|tools? (is |are )?not supported|unsupported.*tool|tool.*unsupported|unknown (field|parameter).*tools/', $e)) {
                _assistantNoTools($llm->fournisseur(), $llm->model(), true);
                if ($emit) $emit('info', ['message' => 'Modèle sans outils : recherche automatique']);
                return _assistantLegacy($ctx, $emit, $onText, $finish, $fail, $addUsage);
            }
            return $fail($r, 'tool_calling', $log);
        }

        if (!$r['tool_calls']) {
            if (trim($r['text']) !== '') return $finish($r['text'], 'tool_calling', $round + 1, $log);
            break;   // réponse vide → réponse forcée ci-dessous
        }

        // Du texte a pu partir avant les appels d'outils (« Je vais chercher… ») :
        // le client l'efface.
        if ($streamed && $emit) $emit('reset');
        $messages[] = ['role' => 'assistant', 'content' => $r['text'], 'tool_calls' => $r['tool_calls'], 'raw' => $r['raw']];
        foreach ($r['tool_calls'] as $tc) {
            $name = (string)$tc['name'];
            $args = is_array($tc['args']) ? $tc['args'] : [];
            if ($emit) $emit('tool_call', ['name' => $name, 'args_summary' => _summarizeArgs($args)]);
            $sorted = $args; ksort($sorted);
            $sig = $name . ':' . md5(json_encode($sorted, JSON_UNESCAPED_UNICODE));
            $cached = isset($cache[$sig]);
            $result = $cached ? $cache[$sig] : ($cache[$sig] = $tools->execute($name, $args));
            $size = (int)($result['count'] ?? $result['total'] ?? count($result));
            if ($emit) $emit('tool_result', ['name' => $name, 'size' => $size, 'cached' => $cached]);
            $log[] = ['name' => $name, 'args' => $args, 'result_size' => $size, 'cached' => $cached];
            $messages[] = ['role' => 'tool', 'tool_call_id' => $tc['id'], 'name' => $name, 'content' => $tools->encode($result)];
        }
    }

    // ── Réponse forcée, sans outils (plafond de tours ou réponse vide) ──
    $messages[] = ['role' => 'user', 'content' =>
        "Réponds maintenant à ma question avec les données déjà obtenues, sans appeler d'outil. "
        . "Court, en français, avec les liens [FICHE:…] exacts."];
    $streamed = false; $sent = '';
    $r = $llm->chat($system, $messages, null, $onText, ['cache_tail' => true]);
    $addUsage($r['usage']);
    if (isset($r['_error'])) return $fail($r, 'tool_calling', $log);
    $text = trim($r['text']);
    if ($text === '') $text = _synthesizeFromToolCalls($messages)
        ?: "Je n'ai pas réussi à formuler une réponse. Pouvez-vous reformuler la question ?";
    $out = $finish($text, 'tool_calling', $maxRounds, $log);
    $out['truncated'] = true;
    return $out;
}

/**
 * Modèle sans tool calling : une recherche globale est faite côté serveur et
 * ses résultats compacts sont ajoutés AU MESSAGE UTILISATEUR (le prompt
 * système reste identique, donc en cache). Un seul appel au modèle.
 */
function _assistantLegacy(array $ctx, ?callable $emit, ?callable $onText, callable $finish, callable $fail, callable $addUsage): array {
    $tools = $ctx['tools'];
    $messages = $ctx['messages'];
    $pf = in_array('quantite', $ctx['intents'], true) ? $tools->prefetchComptage($ctx['question']) : $tools->prefetch($ctx['question']);
    $bloc = $pf ? $tools->encode($pf['result']) : '{"total":0}';
    $last = count($messages) - 1;
    $messages[$last]['content'] .= "\n\n[Tu ne peux pas appeler d'outil. Résultats d'une recherche automatique sur la question :]\n"
        . $bloc . "\n[Si la donnée n'y figure pas, dis-le clairement.]";
    if ($emit && $pf) {
        $emit('tool_call', ['name' => $pf['name'], 'args_summary' => _summarizeArgs($pf['args'])]);
        $emit('tool_result', ['name' => $pf['name'], 'size' => (int)($pf['result']['count'] ?? $pf['result']['total'] ?? 0), 'cached' => false]);
    }
    $r = $ctx['llm']->chat($ctx['system'], $messages, null, $onText);
    $addUsage($r['usage']);
    if (isset($r['_error'])) return $fail($r, 'legacy', []);
    return $finish($r['text'], 'legacy', 1, $pf ? [['name' => $pf['name'], 'args' => $pf['args'], 'prefetch' => true]] : []);
}

/**
 * Dernier recours : le modèle n'a rien écrit malgré des outils réussis — on
 * compose une réponse minimale à partir des résultats récoltés.
 */
function _synthesizeFromToolCalls(array $messages): string {
    $last = [];
    foreach ($messages as $m) {
        if (($m['role'] ?? '') !== 'tool') continue;
        $d = json_decode((string)($m['content'] ?? ''), true);
        if (is_array($d)) $last[$m['name'] ?? '?'] = $d;
    }
    $lines = [];
    if (isset($last['localiser'])) {
        $loc = $last['localiser'];
        if (!empty($loc['sur_plan'][0])) {
            $p = $loc['sur_plan'][0];
            $line = '📍 Localisation : ' . trim(($p['BatimentNom'] ?? '') . ', ' . ($p['EtageNom'] ?? ''), ', ');
            if (!empty($p['EtageId'])) {
                $line .= !empty($p['ElementId'])
                    ? " [FICHE:plan:{$p['EtageId']}:{$p['ElementId']}:Voir sur le plan]"
                    : " [FICHE:plan:{$p['EtageId']}:Voir sur le plan]";
            }
            $lines[] = $line;
        } elseif (!empty($loc['batiment']) || !empty($loc['etage']) || !empty($loc['bureau'])) {
            $lines[] = '📍 Localisation : ' . implode(', ', array_filter([
                !empty($loc['batiment']) ? "bâtiment {$loc['batiment']}" : '',
                !empty($loc['etage']) ? "étage {$loc['etage']}" : '',
                !empty($loc['bureau']) ? "bureau {$loc['bureau']}" : '']));
        } else {
            $lines[] = '📍 Localisation non renseignée pour cet élément.';
        }
    }
    if (isset($last['compter']['count'])) $lines[] = "🔢 **{$last['compter']['count']}** élément(s) (" . ($last['compter']['type'] ?? '?') . ').';
    if (isset($last['search'])) {
        $n = $last['search']['count'] ?? $last['search']['total'] ?? null;
        if ($n !== null) $lines[] = "🔎 $n résultat(s) trouvé(s).";
    }
    if (!$lines) return '';
    return "_(Synthèse automatique — le modèle n'a pas produit de texte)_\n\n" . implode("\n", $lines);
}

/** Résumé court des arguments d'un appel d'outil (affichage client). */
function _summarizeArgs(array $args): string {
    $parts = [];
    foreach ($args as $k => $v) {
        if (is_array($v)) $v = count($v) . ' items';
        elseif (is_bool($v)) $v = $v ? 'oui' : 'non';
        elseif (is_string($v) && mb_strlen($v) > 30) $v = mb_substr($v, 0, 30) . '…';
        $parts[] = "$k=$v";
    }
    return implode(', ', $parts);
}

/**
 * Nettoie la sortie du modèle : réflexion à voix haute, annonces (« je vais
 * vérifier… »), appels d'outils recopiés en texte, préfixes « Réponse : ».
 * Si tout a été retiré, on garde l'original (mieux que rien).
 */
function _cleanLLMOutput(string $text, array $toolNames = []): string {
    if ($text === '') return '';
    $original = $text;

    $text = preg_replace('#<(think|thinking|reasoning|scratchpad|reflection|analysis|plan)>.*?</\1>\s*#is', '', $text);
    $text = preg_replace('#<(think|thinking|reasoning|scratchpad|reflection|analysis|plan)>.*$#is', '', $text);

    $announce = [
        '/(?:^|(?<=[.!?]\s))(?:je\s+vais\s+(?:vérifier|chercher|regarder|consulter|rechercher))[^.!?\n]*[.!?]?\s*/iu',
        '/(?:^|(?<=[.!?]\s))(?:laissez-moi\s+(?:vérifier|chercher|regarder))[^.!?\n]*[.!?]?\s*/iu',
        '/(?:^|(?<=[.!?]\s))(?:let me\s+(?:check|search|look|see|find|verify))[^.!?\n]*[.!?]?\s*/iu',
        '/(?:^|(?<=[.!?]\s))(?:let\'?s\s+(?:get\s+started|see|check|begin|start))[^.!?\n]*[.!?]?\s*/iu',
        '/(?:^|(?<=[.!?]\s))(?:okay|ok|alright|sure|so|now|well|hmm+)[, ]+(?:let|I|je|on|let\'?s|nous)[^.!?\n]*[.!?]?\s*/iu',
        '/(?:^|(?<=[.!?]\s))(?:bon[, ]+(?:l[àa]|alors|voil[àa]|maintenant))[^.!?\n]*[.!?]?\s*/iu',
    ];
    foreach ($announce as $pat) $text = preg_replace($pat, '', $text);

    // Appels d'outils recopiés en texte : search(...), [localiser](...), {"tool":...}
    $names = $toolNames ?: ['search', 'localiser', 'localiser_groupe', 'compter', 'contexte', 'alertes', 'get_fiche', 'qui_est', 'module', 'search_sharepoint'];
    usort($names, fn($a, $b) => strlen($b) <=> strlen($a));
    $re = '(?:' . implode('|', array_map(fn($n) => preg_quote($n, '/'), $names)) . ')';
    $text = preg_replace('/(?<![\w\[:])_?' . $re . '\s*\([^)]*\)\.{0,3}\s*/i', '', $text);
    $text = preg_replace('/\[' . $re . '\]\s*\([^\n]{0,200}?\)/i', '', $text);
    $text = preg_replace('/\{\s*"(?:tool|name)"\s*:\s*"[a-z_]+"\s*,[^{}]{0,500}(?:\{[^{}]*\}[^{}]*)?\}\s*/i', '', $text);

    $text = preg_replace('/^(final answer|réponse finale|answer|réponse)\s*:\s*/im', '', $text);
    // Liens markdown vers localhost / ancre vide : on garde le libellé.
    $text = preg_replace_callback('/\[([^\]\[]+)\]\((https?:\/\/(?:localhost|127\.0\.0\.1)[^\s)]*|#[^\s)]*|)\)/', fn($m) => $m[1], $text);

    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    $text = preg_replace('/[ \t]+\n/', "\n", $text);
    $text = preg_replace('/  +/', ' ', $text);
    $text = trim($text);
    return $text === '' ? trim($original) : $text;
}

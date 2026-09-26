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
 * Larka — Assistant IA : client LLM unifié
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Une seule méthode, chat(), pour tous les fournisseurs, en mode bloquant OU
 * streamé, avec ou sans outils :
 *
 *   anthropic  → API Messages (cache de prompt : tools + système facturés ~10 %)
 *   gemini     → API native generateContent / streamGenerateContent
 *                (thought signatures préservées, réflexion minimale)
 *   ollama     → API native /api/chat : num_ctx, num_predict, keep_alive et
 *                think sont RÉELLEMENT appliqués (l'endpoint /v1 d'Ollama les
 *                ignore — la fenêtre restait à 4096 et tronquait le prompt).
 *                Repli automatique sur /v1 si /api/chat est absent.
 *   openai, mistral, copilot, lmstudio, local → format OpenAI chat/completions
 *
 * FORMAT DE MESSAGES INTERNE (indépendant du fournisseur)
 *   ['role'=>'user',      'content'=>string]
 *   ['role'=>'assistant', 'content'=>string, 'tool_calls'=>[[id,name,args]], 'raw'=>mixed]
 *   ['role'=>'tool',      'tool_call_id'=>string, 'name'=>string, 'content'=>string(JSON)]
 *
 * RETOUR DE chat()
 *   ['text'=>string, 'tool_calls'=>[[id,name,args]], 'raw'=>mixed, 'usage'=>[...], '_error'=>?string]
 *
 * ROBUSTESSE
 *   - Paramètre refusé par un modèle (temperature sur un modèle à raisonnement,
 *     max_tokens → max_completion_tokens, seed…) : retiré, requête rejouée, et
 *     la leçon est mémorisée sur disque pour ne plus payer l'erreur.
 *   - En streaming, un « tick » est appelé ~1×/s : la route s'en sert pour
 *     envoyer un ping SSE (nginx ne coupe pas pendant le traitement du prompt)
 *     et pour ABANDONNER la génération si l'utilisateur a fermé/arrêté — sur
 *     CPU, une réponse que plus personne n'attend bloque tout le monde.
 * ═══════════════════════════════════════════════════════════════════════════════
 */

final class AssistantLLM
{
    public const LOCAL = ['ollama', 'lmstudio', 'local'];

    private array $c;
    /** @var callable|null  fn(): bool — true = abandonner la requête en cours */
    private $tick = null;
    private string $cacheDir;

    /**
     * @param array $c  fournisseur, model, url, key, timeout, max_tokens,
     *                  temperature, top_p, seed, num_ctx, keep_alive, reflexion,
     *                  gemini_thinking, reasoning_effort, anthropic_cache, num_thread
     */
    public function __construct(array $c)
    {
        $this->c = $c + [
            'timeout' => 120, 'max_tokens' => 1024, 'temperature' => 0.2, 'top_p' => 0.9,
            'seed' => null, 'num_ctx' => 8192, 'keep_alive' => -1, 'reflexion' => 'auto',
            'gemini_thinking' => 'auto', 'reasoning_effort' => 'low', 'anthropic_cache' => true,
            'num_thread' => 0, 'temperature_explicite' => false,
        ];
        $this->cacheDir = $c['cache_dir'] ?? (__DIR__ . '/../data/cache_assistant');
    }

    public function fournisseur(): string { return $this->c['fournisseur']; }
    public function model(): string       { return $this->c['model']; }
    public function isLocal(): bool       { return in_array($this->c['fournisseur'], self::LOCAL, true); }
    public function setTick(?callable $f): void { $this->tick = $f; }

    /** Identifiant d'appel d'outil portable (Mistral exige 9 caractères alphanumériques). */
    public static function newId(): string
    {
        $a = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $s = '';
        for ($i = 0; $i < 9; $i++) $s .= $a[random_int(0, 61)];
        return $s;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Point d'entrée
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * @param AssistantTools|null $tools   null = réponse texte forcée
     * @param callable|null       $onText  fn(string $delta) — active le streaming
     * @param array               $opt     max_tokens, cache_tail (bool)
     */
    public function chat(string $system, array $messages, ?AssistantTools $tools = null,
                         ?callable $onText = null, array $opt = []): array
    {
        $t0 = microtime(true);
        try {
            $r = match ($this->c['fournisseur']) {
                'anthropic' => $this->anthropic($system, $messages, $tools, $onText, $opt),
                'gemini'    => $this->gemini($system, $messages, $tools, $onText, $opt),
                'ollama'    => $this->ollamaNativeUsable()
                                 ? $this->ollama($system, $messages, $tools, $onText, $opt)
                                 : $this->openai($system, $messages, $tools, $onText, $opt),
                default     => $this->openai($system, $messages, $tools, $onText, $opt),
            };
        } catch (\Throwable $e) {
            error_log('[Larka][assistant] LLM: ' . $e->getMessage());
            $r = ['_error' => 'Erreur interne du client IA : ' . $e->getMessage()];
        }
        $r += ['text' => '', 'tool_calls' => [], 'raw' => null, 'usage' => []];
        $r['text'] = self::stripThink((string)$r['text']);
        $r['usage']['ms'] = (int)round((microtime(true) - $t0) * 1000);
        return $r;
    }

    /**
     * Préchauffage (modèles locaux) : fait traiter le préfixe stable
     * (système + outils) et le garde en cache KV. La vraie question qui suit ne
     * paie plus que ses propres tokens. Génère 1 token.
     */
    public function warmup(string $system, ?AssistantTools $tools): array
    {
        return $this->chat($system, [['role' => 'user', 'content' => 'ok']], $tools, null, ['max_tokens' => 1]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  OpenAI / Mistral / Copilot / LM Studio / llama.cpp / Ollama /v1
    // ═══════════════════════════════════════════════════════════════════════

    private function openai(string $system, array $messages, ?AssistantTools $tools, ?callable $onText, array $opt): array
    {
        $f = $this->c['fournisseur'];
        $model = $this->c['model'];
        $url = $this->c['url'];
        if ($f === 'ollama') $url = self::ollamaV1Url($url);
        $isOpenAI = $f === 'openai';
        $reasoning = $isOpenAI && preg_match('/^(o\d|gpt-5)/i', $model);

        $msgs = [['role' => 'system', 'content' => $system]];
        foreach ($messages as $m) {
            $msg = ['role' => $m['role'], 'content' => (string)($m['content'] ?? '')];
            if (!empty($m['tool_calls'])) {
                $msg['tool_calls'] = array_map(fn($tc) => [
                    'id' => $tc['id'] ?: self::newId(), 'type' => 'function',
                    'function' => ['name' => $tc['name'],
                                   'arguments' => json_encode($tc['args'] ?: (object)[], JSON_UNESCAPED_UNICODE)],
                ], $m['tool_calls']);
            }
            if ($m['role'] === 'tool') {
                $msg['tool_call_id'] = $m['tool_call_id'] ?? '';
                if ($f === 'mistral') $msg['name'] = $m['name'] ?? '';
            }
            $msgs[] = $msg;
        }

        $maxTok = (int)($opt['max_tokens'] ?? $this->c['max_tokens']);
        $p = ['model' => $model, 'messages' => $msgs];
        $p[$isOpenAI ? 'max_completion_tokens' : 'max_tokens'] = $maxTok;
        if ($reasoning) {
            if ($this->c['reasoning_effort']) $p['reasoning_effort'] = $this->c['reasoning_effort'];
        } else {
            $p['temperature'] = $this->c['temperature'];
            $p['top_p'] = $this->c['top_p'];
        }
        $seed = $this->c['seed'] ?? ($this->isLocal() ? 42 : null);
        if ($seed !== null) $p[$f === 'mistral' ? 'random_seed' : 'seed'] = (int)$seed;
        if ($tools) { $p['tools'] = $tools->getOpenAISchema(); $p['tool_choice'] = 'auto'; }
        if ($f === 'local') $p['cache_prompt'] = true;           // llama.cpp server
        if ($onText) {
            $p['stream'] = true;
            if ($isOpenAI) $p['stream_options'] = ['include_usage' => true];
        }

        $headers = ['Content-Type: application/json'];
        if ($this->c['key'] !== '') $headers[] = 'Authorization: Bearer ' . $this->c['key'];

        $p = $this->applyLearned($p);
        for ($try = 0; $try < 3; $try++) {
            $acc = ['text' => '', 'calls' => [], 'usage' => []];
            $think = self::thinkFilter();
            $res = $this->http($url, $p, $headers, $onText ? function (string $line) use (&$acc, $onText, $think) {
                if (!str_starts_with($line, 'data:')) return;
                $j = trim(substr($line, 5));
                if ($j === '' || $j === '[DONE]') return;
                $o = json_decode($j, true);
                if (!is_array($o)) return;
                if (isset($o['error'])) { $acc['error'] = self::errMsg($o); return; }
                if (!empty($o['usage'])) $acc['usage'] = $o['usage'];
                $d = $o['choices'][0]['delta'] ?? [];
                if (($d['content'] ?? '') !== '' && $d['content'] !== null) {
                    $out = $think($d['content']);
                    $acc['text'] .= $d['content'];
                    if ($out !== '') $onText($out);
                }
                foreach ($d['tool_calls'] ?? [] as $tc) {
                    $i = (int)($tc['index'] ?? count($acc['calls']));
                    $acc['calls'][$i] ??= ['id' => '', 'name' => '', 'args' => ''];
                    if (!empty($tc['id'])) $acc['calls'][$i]['id'] = $tc['id'];
                    if (!empty($tc['function']['name'])) $acc['calls'][$i]['name'] .= $tc['function']['name'];
                    if (isset($tc['function']['arguments'])) {
                        $a = $tc['function']['arguments'];
                        $acc['calls'][$i]['args'] .= is_string($a) ? $a : json_encode($a);
                    }
                }
            } : null);

            if (isset($res['_error'])) {
                if ($res['http'] >= 400 && $res['http'] < 500 && ($p2 = $this->unlearnParam($p, $res['_error'])) !== null) {
                    $p = $p2; continue;
                }
                return ['_error' => $res['_error']];
            }
            if (isset($acc['error'])) return ['_error' => $acc['error']];

            if ($onText) {
                $calls = [];
                foreach ($acc['calls'] as $c) {
                    if ($c['name'] === '') continue;
                    $args = json_decode($c['args'] ?: '{}', true);
                    $calls[] = ['id' => $c['id'] ?: self::newId(), 'name' => $c['name'], 'args' => is_array($args) ? $args : []];
                }
                return ['text' => $acc['text'], 'tool_calls' => $calls, 'usage' => self::usageOpenAI($acc['usage'])];
            }
            $data = $res['data'];
            $msg = $data['choices'][0]['message'] ?? [];
            $calls = [];
            foreach ($msg['tool_calls'] ?? [] as $tc) {
                $a = $tc['function']['arguments'] ?? '{}';
                $args = is_string($a) ? json_decode($a, true) : $a;
                $calls[] = ['id' => ($tc['id'] ?? '') ?: self::newId(), 'name' => $tc['function']['name'] ?? '',
                            'args' => is_array($args) ? $args : []];
            }
            return ['text' => (string)($msg['content'] ?? ''), 'tool_calls' => $calls,
                    'usage' => self::usageOpenAI($data['usage'] ?? [])];
        }
        return ['_error' => 'Paramètres refusés par le fournisseur (3 essais).'];
    }

    private static function usageOpenAI(array $u): array
    {
        return array_filter([
            'in'     => $u['prompt_tokens'] ?? null,
            'out'    => $u['completion_tokens'] ?? null,
            'cached' => $u['prompt_tokens_details']['cached_tokens'] ?? ($u['prompt_cache_hit_tokens'] ?? null),
        ], fn($v) => $v !== null);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Ollama — API native /api/chat
    // ═══════════════════════════════════════════════════════════════════════

    /** /api/chat utilisable ? (faux si un essai précédent a renvoyé 404 d'endpoint) */
    private function ollamaNativeUsable(): bool
    {
        if (($this->c['ollama_natif'] ?? true) === false) return false;
        $l = $this->learned();
        return empty($l['__endpoint_v1']);
    }

    private function ollama(string $system, array $messages, ?AssistantTools $tools, ?callable $onText, array $opt): array
    {
        $url = self::ollamaNativeUrl($this->c['url']);
        $model = $this->c['model'];
        $msgs = [['role' => 'system', 'content' => $system]];
        foreach ($messages as $m) {
            $msg = ['role' => $m['role'], 'content' => (string)($m['content'] ?? '')];
            if (!empty($m['tool_calls'])) {
                $msg['tool_calls'] = array_map(fn($tc) => ['function' => [
                    'name' => $tc['name'], 'arguments' => $tc['args'] ?: (object)[],
                ]], $m['tool_calls']);
            }
            if ($m['role'] === 'tool') $msg['tool_name'] = $m['name'] ?? '';
            $msgs[] = $msg;
        }
        $maxTok = (int)($opt['max_tokens'] ?? $this->c['max_tokens']);
        $options = [
            'num_predict' => $maxTok,
            'temperature' => $this->c['temperature'],
            'top_p'       => $this->c['top_p'],
            'seed'        => (int)($this->c['seed'] ?? 42),
        ];
        // num_ctx CONSTANT d'une requête à l'autre : le changer force Ollama à
        // recharger le modèle (plusieurs secondes à chaque fois).
        if ((int)$this->c['num_ctx'] > 0) $options['num_ctx'] = (int)$this->c['num_ctx'];
        if ((int)$this->c['num_thread'] > 0) $options['num_thread'] = (int)$this->c['num_thread'];

        $p = ['model' => $model, 'messages' => $msgs, 'stream' => (bool)$onText,
              'keep_alive' => $this->c['keep_alive'], 'options' => $options];
        if ($tools) $p['tools'] = $tools->getOpenAISchema();
        $think = $this->ollamaThink($model);
        if ($think !== null) $p['think'] = $think;

        $p = $this->applyLearned($p);
        for ($try = 0; $try < 3; $try++) {
            $acc = ['text' => '', 'calls' => [], 'm' => []];
            $filter = self::thinkFilter();
            $res = $this->http($url, $p, ['Content-Type: application/json'],
                $onText ? function (string $line) use (&$acc, $onText, $filter) {
                    $o = json_decode($line, true);
                    if (!is_array($o)) return;
                    if (isset($o['error'])) { $acc['error'] = self::errMsg($o); return; }
                    $c = $o['message']['content'] ?? '';
                    if ($c !== '') {
                        $acc['text'] .= $c;
                        $out = $filter($c);
                        if ($out !== '') $onText($out);
                    }
                    foreach ($o['message']['tool_calls'] ?? [] as $tc) $acc['calls'][] = $tc;
                    if (!empty($o['done'])) $acc['m'] = $o;
                } : null);

            if (isset($res['_error'])) {
                $e = $res['_error'];
                // Endpoint natif absent (proxy, très vieille version) → /v1, mémorisé.
                if ($res['http'] === 404 && !preg_match('/model|modèle/i', $e)) {
                    $this->learn('__endpoint_v1', true);
                    return $this->openai($system, $messages, $tools, $onText, $opt);
                }
                if ($res['http'] === 404 || preg_match('/model .* not found|try pulling/i', $e)) {
                    return ['_error' => "Modèle « $model » introuvable dans Ollama. Sur le serveur : ollama pull $model"];
                }
                if ($res['http'] >= 400 && $res['http'] < 500 && ($p2 = $this->unlearnParam($p, $e)) !== null) {
                    $p = $p2; continue;
                }
                return ['_error' => $e];
            }
            if (isset($acc['error'])) {
                if (($p2 = $this->unlearnParam($p, $acc['error'])) !== null) { $p = $p2; continue; }
                return ['_error' => $acc['error']];
            }
            $o = $onText ? $acc['m'] : $res['data'];
            $text = $onText ? $acc['text'] : (string)($o['message']['content'] ?? '');
            $rawCalls = $onText ? $acc['calls'] : ($o['message']['tool_calls'] ?? []);
            $calls = [];
            foreach ($rawCalls as $tc) {
                $a = $tc['function']['arguments'] ?? [];
                if (is_string($a)) $a = json_decode($a, true);
                $calls[] = ['id' => ($tc['id'] ?? '') ?: self::newId(), 'name' => $tc['function']['name'] ?? '',
                            'args' => is_array($a) ? $a : []];
            }
            $pe = (int)($o['prompt_eval_count'] ?? 0);
            $ev = (int)($o['eval_count'] ?? 0);
            $usage = array_filter([
                'in' => $pe ?: null, 'out' => $ev ?: null,
                'prompt_ms' => isset($o['prompt_eval_duration']) ? (int)($o['prompt_eval_duration'] / 1e6) : null,
                'load_ms'   => isset($o['load_duration']) ? (int)($o['load_duration'] / 1e6) : null,
                'tok_s'     => (!empty($o['eval_duration']) && $ev) ? round($ev / ($o['eval_duration'] / 1e9), 1) : null,
            ], fn($v) => $v !== null);
            return ['text' => $text, 'tool_calls' => $calls, 'usage' => $usage];
        }
        return ['_error' => 'Paramètres refusés par Ollama (3 essais).'];
    }

    /**
     * Politique de « réflexion » locale. Sur CPU, un modèle qui réfléchit à
     * voix basse génère des centaines de tokens invisibles (10-20 tok/s) :
     * c'est souvent LA cause des réponses en plusieurs minutes.
     *   off  → think=false pour tous (retiré automatiquement si refusé)
     *   on   → rien n'est envoyé (défaut du modèle)
     *   auto → désactivée sur les familles connues pour boucler (deepseek-r1,
     *          qwq, magistral), « low » pour gpt-oss ; qwen3 garde sa réflexion
     *          (elle l'aide à choisir ses outils).
     */
    private function ollamaThink(string $model): bool|string|null
    {
        $mode = strtolower((string)$this->c['reflexion']);
        $m = strtolower($model);
        if ($mode === 'on') return null;
        if (str_contains($m, 'gpt-oss')) return 'low';
        if ($mode === 'off') return false;
        foreach (['deepseek-r1', 'r1-distill', 'qwq', 'magistral'] as $k) {
            if (str_contains($m, $k)) return false;
        }
        return null;
    }

    public static function ollamaNativeUrl(string $url): string
    {
        $url = rtrim($url, '/');
        if (preg_match('#/api/chat$#', $url)) return $url;
        if (preg_match('#^(.*?)/v1(/chat/completions)?$#', $url, $m)) return $m[1] . '/api/chat';
        $parts = parse_url($url);
        return ($parts['scheme'] ?? 'http') . '://' . ($parts['host'] ?? 'localhost')
             . (isset($parts['port']) ? ':' . $parts['port'] : '') . '/api/chat';
    }

    public static function ollamaV1Url(string $url): string
    {
        $url = rtrim($url, '/');
        if (str_ends_with($url, '/v1/chat/completions')) return $url;
        if (preg_match('#^(.*?)(/api/chat|/v1)?$#', $url, $m)) return $m[1] . '/v1/chat/completions';
        return $url;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Anthropic
    // ═══════════════════════════════════════════════════════════════════════

    private function anthropic(string $system, array $messages, ?AssistantTools $tools, ?callable $onText, array $opt): array
    {
        $cache = (bool)$this->c['anthropic_cache'];
        $msgs = [];
        $push = function (string $role, array $blocks) use (&$msgs) {
            // Anthropic exige des tours alternés : on fusionne les tours consécutifs
            // (plusieurs tool_result d'une même réponse = UN message user).
            $last = count($msgs) - 1;
            if ($last >= 0 && $msgs[$last]['role'] === $role) {
                $msgs[$last]['content'] = array_merge($msgs[$last]['content'], $blocks);
            } else {
                $msgs[] = ['role' => $role, 'content' => $blocks];
            }
        };
        foreach ($messages as $m) {
            if ($m['role'] === 'tool') {
                $push('user', [['type' => 'tool_result', 'tool_use_id' => $m['tool_call_id'] ?? '',
                                'content' => (string)($m['content'] ?? '')]]);
            } elseif ($m['role'] === 'assistant' && !empty($m['tool_calls'])) {
                $b = [];
                if (trim((string)($m['content'] ?? '')) !== '') $b[] = ['type' => 'text', 'text' => $m['content']];
                foreach ($m['tool_calls'] as $tc) {
                    $b[] = ['type' => 'tool_use', 'id' => $tc['id'] ?: ('toolu_' . self::newId()),
                            'name' => $tc['name'], 'input' => $tc['args'] ?: (object)[]];
                }
                $push('assistant', $b);
            } else {
                $txt = trim((string)($m['content'] ?? ''));
                if ($txt === '') continue;   // bloc texte vide = erreur 400
                $push($m['role'] === 'assistant' ? 'assistant' : 'user', [['type' => 'text', 'text' => $txt]]);
            }
        }
        // Cache incrémental pendant la boucle d'outils : le dernier bloc devient
        // un point de cache, le tour suivant relit tout le préfixe à ~10 %.
        if ($cache && !empty($opt['cache_tail']) && $msgs) {
            $i = count($msgs) - 1; $j = count($msgs[$i]['content']) - 1;
            $msgs[$i]['content'][$j]['cache_control'] = ['type' => 'ephemeral'];
        }

        $p = [
            'model'      => $this->c['model'],
            'max_tokens' => (int)($opt['max_tokens'] ?? $this->c['max_tokens']),
            // Point de cache sur le système : couvre outils + système (ordre de
            // rendu Anthropic : tools → system → messages). Stable → relu à ~10 %.
            'system'     => $cache ? [['type' => 'text', 'text' => $system, 'cache_control' => ['type' => 'ephemeral']]] : $system,
            'messages'   => $msgs,
            // temperature SEULE : les modèles récents refusent temperature + top_p ensemble.
            'temperature' => $this->c['temperature'],
        ];
        if ($tools) $p['tools'] = $tools->getAnthropicSchema();
        if ($onText) $p['stream'] = true;
        $headers = ['Content-Type: application/json', 'x-api-key: ' . $this->c['key'], 'anthropic-version: 2023-06-01'];

        $p = $this->applyLearned($p);
        for ($try = 0; $try < 3; $try++) {
            $acc = ['text' => '', 'blocks' => [], 'usage' => []];
            $filter = self::thinkFilter();
            $res = $this->http($this->c['url'], $p, $headers, $onText ? function (string $line) use (&$acc, $onText, $filter) {
                if (!str_starts_with($line, 'data:')) return;
                $o = json_decode(trim(substr($line, 5)), true);
                if (!is_array($o)) return;
                switch ($o['type'] ?? '') {
                    case 'message_start':
                        $acc['usage'] = $o['message']['usage'] ?? [];
                        break;
                    case 'content_block_start':
                        $acc['blocks'][(int)$o['index']] = $o['content_block'] + ['json' => ''];
                        break;
                    case 'content_block_delta':
                        $d = $o['delta'] ?? [];
                        if (($d['type'] ?? '') === 'text_delta') {
                            $acc['text'] .= $d['text'];
                            $out = $filter($d['text']);
                            if ($out !== '') $onText($out);
                        }
                        elseif (($d['type'] ?? '') === 'input_json_delta') $acc['blocks'][(int)$o['index']]['json'] .= $d['partial_json'];
                        break;
                    case 'message_delta':
                        if (isset($o['usage']['output_tokens'])) $acc['usage']['output_tokens'] = $o['usage']['output_tokens'];
                        break;
                    case 'error':
                        $acc['error'] = $o['error']['message'] ?? 'Erreur Anthropic';
                        break;
                }
            } : null);

            if (isset($res['_error'])) {
                if ($res['http'] === 400 && ($p2 = $this->unlearnParam($p, $res['_error'])) !== null) { $p = $p2; continue; }
                return ['_error' => $res['_error']];
            }
            if (isset($acc['error'])) return ['_error' => $acc['error']];

            $text = ''; $calls = [];
            if ($onText) {
                $text = $acc['text'];
                ksort($acc['blocks']);
                foreach ($acc['blocks'] as $b) {
                    if (($b['type'] ?? '') !== 'tool_use') continue;
                    $args = json_decode($b['json'] ?: '{}', true);
                    $calls[] = ['id' => $b['id'], 'name' => $b['name'], 'args' => is_array($args) ? $args : []];
                }
                $u = $acc['usage'];
            } else {
                foreach ($res['data']['content'] ?? [] as $b) {
                    if (($b['type'] ?? '') === 'text') $text .= $b['text'];
                    elseif (($b['type'] ?? '') === 'tool_use') {
                        $calls[] = ['id' => $b['id'] ?? '', 'name' => $b['name'] ?? '', 'args' => (array)($b['input'] ?? [])];
                    }
                }
                $u = $res['data']['usage'] ?? [];
            }
            return ['text' => $text, 'tool_calls' => $calls, 'usage' => array_filter([
                'in'     => isset($u['input_tokens']) ? $u['input_tokens'] + ($u['cache_read_input_tokens'] ?? 0) + ($u['cache_creation_input_tokens'] ?? 0) : null,
                'out'    => $u['output_tokens'] ?? null,
                'cached' => $u['cache_read_input_tokens'] ?? null,
            ], fn($v) => $v !== null)];
        }
        return ['_error' => 'Paramètres refusés par Anthropic.'];
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Gemini — API native
    // ═══════════════════════════════════════════════════════════════════════

    public static function geminiBase(string $url): string
    {
        $parts = parse_url($url ?: 'https://generativelanguage.googleapis.com/v1beta');
        $path = $parts['path'] ?? '';
        $ver = preg_match('#/(v1beta|v1)(/|$)#', $path, $m) ? $m[1] : 'v1beta';
        // Le schéma et l'hôte sont contrôlés en amont (anti-SSRF : HTTPS +
        // generativelanguage.googleapis.com). On les conserve tels quels.
        return ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? 'generativelanguage.googleapis.com')
             . (isset($parts['port']) ? ':' . $parts['port'] : '') . '/' . $ver;
    }

    private function gemini(string $system, array $messages, ?AssistantTools $tools, ?callable $onText, array $opt): array
    {
        $model = preg_replace('#^models/#', '', $this->c['model']);
        $base = self::geminiBase($this->c['url']) . '/models/' . rawurlencode($model);
        $url = $onText ? $base . ':streamGenerateContent?alt=sse' : $base . ':generateContent';

        $contents = [];
        $push = function (string $role, array $parts) use (&$contents) {
            $last = count($contents) - 1;
            if ($last >= 0 && $contents[$last]['role'] === $role) {
                $contents[$last]['parts'] = array_merge($contents[$last]['parts'], $parts);
            } else {
                $contents[] = ['role' => $role, 'parts' => $parts];
            }
        };
        foreach ($messages as $m) {
            if ($m['role'] === 'tool') {
                $dec = json_decode((string)($m['content'] ?? ''), true);
                $push('user', [['functionResponse' => [
                    'name' => $m['name'] ?? 'outil',
                    'response' => ['content' => $dec ?? (string)($m['content'] ?? '')],
                ]]]);
            } elseif ($m['role'] === 'assistant' && !empty($m['tool_calls'])) {
                if (is_array($m['raw'] ?? null) && $m['raw']) {
                    // Parties brutes renvoyées TELLES QUELLES : elles portent les
                    // thought signatures exigées par Gemini 3 pour la suite.
                    $push('model', $m['raw']);
                } else {
                    // Appel synthétique (pré-recherche) : signature factice
                    // documentée par Google pour ce cas précis.
                    $parts = [];
                    if (trim((string)($m['content'] ?? '')) !== '') $parts[] = ['text' => $m['content']];
                    foreach ($m['tool_calls'] as $i => $tc) {
                        $part = ['functionCall' => ['name' => $tc['name'], 'args' => $tc['args'] ?: (object)[]]];
                        if ($i === 0) $part['thoughtSignature'] = 'skip_thought_signature_validator';
                        $parts[] = $part;
                    }
                    $push('model', $parts);
                }
            } else {
                $txt = trim((string)($m['content'] ?? ''));
                if ($txt === '') continue;
                $push($m['role'] === 'assistant' ? 'model' : 'user', [['text' => $txt]]);
            }
        }

        $gen = ['maxOutputTokens' => (int)($opt['max_tokens'] ?? $this->c['max_tokens'])];
        $isG3 = !preg_match('/gemini-(1|2)\./', $model);
        // Gemini 3 : Google recommande de NE PAS baisser la température (boucles).
        // On ne l'envoie que pour les générations précédentes, ou si l'admin l'a fixée.
        if (!$isG3 || $this->c['temperature_explicite']) {
            $gen['temperature'] = $this->c['temperature'];
            $gen['topP'] = $this->c['top_p'];
        }
        $thinking = $this->geminiThinking($model);
        if ($thinking) $gen['thinkingConfig'] = $thinking;

        $p = ['contents' => $contents, 'generationConfig' => $gen,
              'systemInstruction' => ['parts' => [['text' => $system]]]];
        if ($tools) {
            $p['tools'] = [['functionDeclarations' => $tools->getGeminiSchema()]];
            $p['toolConfig'] = ['functionCallingConfig' => ['mode' => 'AUTO']];
        }
        $headers = ['Content-Type: application/json', 'x-goog-api-key: ' . $this->c['key']];

        $p = $this->applyLearned($p);
        for ($try = 0; $try < 3; $try++) {
            $acc = ['parts' => [], 'usage' => [], 'finish' => ''];
            $filter = self::thinkFilter();
            $res = $this->http($url, $p, $headers, $onText ? function (string $line) use (&$acc, $onText, $filter) {
                if (!str_starts_with($line, 'data:')) return;
                $o = json_decode(trim(substr($line, 5)), true);
                if (!is_array($o)) return;
                if (isset($o['error'])) { $acc['error'] = self::errMsg($o); return; }
                foreach ($o['candidates'][0]['content']['parts'] ?? [] as $part) {
                    if (isset($part['text']) && empty($part['thought'])) {
                        $out = $filter($part['text']);
                        if ($out !== '') $onText($out);
                    }
                    $acc['parts'][] = $part;
                }
                if (!empty($o['candidates'][0]['finishReason'])) $acc['finish'] = $o['candidates'][0]['finishReason'];
                if (!empty($o['usageMetadata'])) $acc['usage'] = $o['usageMetadata'];
            } : null);

            if (isset($res['_error'])) {
                $e = $res['_error'];
                if ($res['http'] === 400 && isset($p['generationConfig']['thinkingConfig']) && stripos($e, 'think') !== false) {
                    unset($p['generationConfig']['thinkingConfig']);
                    $this->learn('generationConfig.thinkingConfig', true);
                    continue;
                }
                if ($res['http'] === 404) return ['_error' => "Modèle Gemini « $model » introuvable ou non disponible pour cette clé."];
                return ['_error' => $e];
            }
            if (isset($acc['error'])) return ['_error' => $acc['error']];

            if ($onText) { $parts = $acc['parts']; $u = $acc['usage']; $finish = $acc['finish']; }
            else {
                $parts = $res['data']['candidates'][0]['content']['parts'] ?? [];
                $u = $res['data']['usageMetadata'] ?? [];
                $finish = $res['data']['candidates'][0]['finishReason'] ?? '';
            }
            // Fusion des fragments de texte consécutifs (stream) pour le renvoi brut.
            $merged = [];
            foreach ($parts as $part) {
                $k = count($merged) - 1;
                if (isset($part['text']) && $k >= 0 && isset($merged[$k]['text']) && count($part) === 1
                    && count($merged[$k]) === 1) {
                    $merged[$k]['text'] .= $part['text'];
                } else {
                    $merged[] = $part;
                }
            }
            $text = ''; $calls = [];
            foreach ($merged as $part) {
                if (isset($part['text']) && empty($part['thought'])) $text .= $part['text'];
                if (isset($part['functionCall'])) {
                    $calls[] = ['id' => ($part['functionCall']['id'] ?? '') ?: self::newId(),
                                'name' => $part['functionCall']['name'] ?? '',
                                'args' => (array)($part['functionCall']['args'] ?? [])];
                }
            }
            if ($text === '' && !$calls && $finish && !in_array($finish, ['STOP', 'MAX_TOKENS'], true)) {
                return ['_error' => "Gemini a interrompu la réponse ($finish)."];
            }
            return ['text' => $text, 'tool_calls' => $calls, 'raw' => $calls ? $merged : null,
                    'usage' => array_filter([
                        'in'     => $u['promptTokenCount'] ?? null,
                        'out'    => isset($u['candidatesTokenCount']) ? $u['candidatesTokenCount'] + ($u['thoughtsTokenCount'] ?? 0) : null,
                        'cached' => $u['cachedContentTokenCount'] ?? null,
                    ], fn($v) => $v !== null)];
        }
        return ['_error' => 'Paramètres refusés par Gemini.'];
    }

    /** Réflexion Gemini minimale : moins de latence, moins de tokens facturés. */
    private function geminiThinking(string $model): ?array
    {
        $mode = strtolower((string)$this->c['gemini_thinking']);
        if ($mode === 'off' || $mode === 'defaut') return null;
        if (!empty($this->learned()['generationConfig.thinkingConfig'])) return null;
        $m = strtolower($model);
        if (preg_match('/gemini-2\.5/', $m)) {
            if ($mode !== 'auto' && is_numeric($mode)) return ['thinkingBudget' => (int)$mode];
            return ['thinkingBudget' => str_contains($m, 'pro') ? 128 : 0];
        }
        if (preg_match('/gemini-(1|2\.0)/', $m)) return null;   // pas de réflexion
        if (in_array($mode, ['minimal', 'low', 'medium', 'high'], true)) return ['thinkingLevel' => $mode];
        return ['thinkingLevel' => str_contains($m, 'pro') ? 'low' : 'minimal'];
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Transport HTTP (bloquant ou ligne par ligne)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * @param callable|null $onLine  streaming : appelée pour chaque ligne reçue
     * @return array ['http'=>int, 'data'=>array|null] ou ['http'=>int, '_error'=>string]
     */
    private function http(string $url, array $payload, array $headers, ?callable $onLine): array
    {
        $ch = curl_init($url);
        $status = 0;
        $errBody = '';
        $buffer = '';
        $raw = '';
        $timeout = max(10, (int)$this->c['timeout']);
        if ($onLine) $timeout = (int)round($timeout * 1.5);

        $opts = [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => $this->isLocal() ? 5 : 10,
            CURLOPT_HEADERFUNCTION => function ($_c, $h) use (&$status) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $status = (int)$m[1];
                return strlen($h);
            },
        ];
        if (str_starts_with($url, 'https://') && !$this->isLocal()) {
            $opts[CURLOPT_SSL_VERIFYPEER] = true;
            $opts[CURLOPT_SSL_VERIFYHOST] = 2;
        } else {
            $opts[CURLOPT_SSL_VERIFYPEER] = false;   // moteur local (réseau privé)
            $opts[CURLOPT_SSL_VERIFYHOST] = 0;
        }
        if ($onLine) {
            $opts[CURLOPT_WRITEFUNCTION] = function ($_c, $data) use (&$buffer, &$errBody, &$status, $onLine) {
                if ($status >= 400) { if (strlen($errBody) < 20000) $errBody .= $data; return strlen($data); }
                $buffer .= $data;
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = rtrim(substr($buffer, 0, $pos), "\r");
                    $buffer = substr($buffer, $pos + 1);
                    if ($line !== '') $onLine($line);
                }
                return strlen($data);
            };
        } else {
            $opts[CURLOPT_RETURNTRANSFER] = true;
        }
        // Tick ~1×/s : ping SSE + abandon si le client est parti.
        if ($this->tick) {
            $tick = $this->tick;
            $opts[CURLOPT_NOPROGRESS] = false;
            $opts[CURLOPT_XFERINFOFUNCTION] = function () use ($tick) { return $tick() ? 1 : 0; };
        }
        curl_setopt_array($ch, $opts);
        $out = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: $status;

        if ($onLine && $buffer !== '' && $http < 400) {
            foreach (explode("\n", $buffer) as $line) { $line = rtrim($line, "\r"); if ($line !== '') $onLine($line); }
        }
        if ($errno === CURLE_ABORTED_BY_CALLBACK) return ['http' => 499, '_error' => 'Requête abandonnée (client déconnecté).', 'aborted' => true];
        if ($errno === CURLE_OPERATION_TIMEDOUT) {
            return ['http' => 504, '_error' => "Délai dépassé ({$timeout} s). Sur CPU : modèle plus petit, ou augmentez la durée max dans la configuration."];
        }
        if ($errno) {
            $hint = $this->isLocal() ? ' — le moteur local tourne-t-il ? (' . parse_url($url, PHP_URL_HOST) . ':' . (parse_url($url, PHP_URL_PORT) ?: '') . ')' : '';
            return ['http' => 0, '_error' => "Connexion : $err$hint"];
        }
        if (!$onLine) $raw = (string)$out;
        if ($http >= 400) {
            $body = $onLine ? $errBody : $raw;
            $data = json_decode($body, true);
            $msg = is_array($data) ? self::errMsg($data) : '';
            if ($msg === '') $msg = "HTTP $http" . ($body !== '' ? ' : ' . mb_substr(strip_tags($body), 0, 200) : '');
            return ['http' => $http, '_error' => $msg];
        }
        if ($onLine) return ['http' => $http, 'data' => null];
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return ['http' => $http, '_error' => "Réponse non-JSON (HTTP $http) : " . mb_substr(strip_tags($raw), 0, 200)];
        }
        return ['http' => $http, 'data' => $data];
    }

    /** Message d'erreur lisible, quel que soit le format du fournisseur. */
    private static function errMsg(array $d): string
    {
        if (array_is_list($d) && isset($d[0]) && is_array($d[0])) $d = $d[0];   // Gemini stream
        $m = $d['error']['message'] ?? $d['error'] ?? $d['message'] ?? $d['detail'] ?? '';
        if (is_array($m)) $m = json_encode($m, JSON_UNESCAPED_UNICODE);
        return (string)$m;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Paramètres refusés : retrait + mémorisation
    // ═══════════════════════════════════════════════════════════════════════

    private function learnedFile(): string
    {
        if (!is_dir($this->cacheDir)) @mkdir($this->cacheDir, 0770, true);
        return $this->cacheDir . '/params_' . preg_replace('/[^a-z0-9]/', '', $this->c['fournisseur']) . '.json';
    }

    private static array $learnedMem = [];

    private function learned(): array
    {
        $key = $this->c['fournisseur'] . '|' . $this->c['model'];
        if (!isset(self::$learnedMem[$key])) {
            $all = is_file($this->learnedFile()) ? (json_decode((string)@file_get_contents($this->learnedFile()), true) ?: []) : [];
            self::$learnedMem[$key] = $all[$this->c['model']] ?? [];
        }
        return self::$learnedMem[$key];
    }

    private function learn(string $param, mixed $value): void
    {
        $f = $this->learnedFile();
        $all = is_file($f) ? (json_decode((string)@file_get_contents($f), true) ?: []) : [];
        $all[$this->c['model']][$param] = $value;
        self::$learnedMem[$this->c['fournisseur'] . '|' . $this->c['model']] = $all[$this->c['model']];
        @file_put_contents($f, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    /** Applique ce qui a déjà été appris pour ce modèle (paramètres à retirer / renommer). */
    private function applyLearned(array $p): array
    {
        foreach ($this->learned() as $param => $v) {
            if (str_starts_with($param, '__')) continue;
            if ($param === 'generationConfig.thinkingConfig') { unset($p['generationConfig']['thinkingConfig']); continue; }
            if (!array_key_exists($param, $p)) continue;
            if (is_string($v) && $v !== '') { $p[$v] = $p[$param]; }  // renommage
            unset($p[$param]);
        }
        return $p;
    }

    /**
     * Le message d'erreur cite-t-il un de nos paramètres facultatifs ? Si oui,
     * on le retire (ou on le renomme), on mémorise, et on rejoue.
     */
    private function unlearnParam(array $p, string $err): ?array
    {
        $e = strtolower($err);
        if (isset($p['max_tokens']) && str_contains($e, 'max_tokens') && str_contains($e, 'max_completion_tokens')) {
            $p['max_completion_tokens'] = $p['max_tokens']; unset($p['max_tokens']);
            $this->learn('max_tokens', 'max_completion_tokens');
            return $p;
        }
        $optional = ['temperature', 'top_p', 'seed', 'random_seed', 'reasoning_effort', 'stream_options',
                     'tool_choice', 'cache_prompt', 'think', 'keep_alive', 'parallel_tool_calls'];
        foreach ($optional as $k) {
            if (!array_key_exists($k, $p)) continue;
            if (preg_match('/\b' . preg_quote($k, '/') . '\b/', $e) || ($k === 'think' && str_contains($e, 'thinking'))) {
                unset($p[$k]);
                $this->learn($k, true);
                // temperature refusée ⇒ top_p l'est en général aussi.
                if ($k === 'temperature' && isset($p['top_p'])) { unset($p['top_p']); $this->learn('top_p', true); }
                return $p;
            }
        }
        return null;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Balises de réflexion
    // ═══════════════════════════════════════════════════════════════════════

    public static function stripThink(string $t): string
    {
        if ($t === '' || stripos($t, '<think') === false) return $t;
        $t = preg_replace('#<think>.*?</think>\s*#is', '', $t);
        $t = preg_replace('#<think>.*$#is', '', $t);
        return trim($t);
    }

    /**
     * Filtre à état pour le streaming : masque ce qui est entre <think> et
     * </think>, y compris quand une balise arrive coupée sur deux fragments
     * (« <thin » + « k> ») — on retient alors la fin ambiguë du fragment.
     */
    private static function thinkFilter(): callable
    {
        $inside = false;
        $hold = '';
        return function (string $delta) use (&$inside, &$hold): string {
            $delta = $hold . $delta;
            $hold = '';
            $out = '';
            while ($delta !== '') {
                $tag = $inside ? '</think>' : '<think>';
                $pos = strpos($delta, $tag);
                if ($pos !== false) {
                    if (!$inside) $out .= substr($delta, 0, $pos);
                    $delta = substr($delta, $pos + strlen($tag));
                    $inside = !$inside;
                    continue;
                }
                // Début de balise possible en fin de fragment : on le garde.
                for ($k = min(strlen($tag) - 1, strlen($delta)); $k > 0; $k--) {
                    if (str_ends_with($delta, substr($tag, 0, $k))) {
                        $hold = substr($delta, -$k);
                        $delta = substr($delta, 0, -$k);
                        break;
                    }
                }
                if (!$inside) $out .= $delta;
                break;
            }
            return $out;
        };
    }
}

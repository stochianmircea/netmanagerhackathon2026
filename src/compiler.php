<?php
/**
 * compiler.php — the policy, in a human's words, compiled into a plan.
 *
 * This is the only place a language model is allowed to touch. It does the one thing code cannot:
 * read a sentence like "cut first what consumes a lot and matters little" and weigh two things at
 * once against the state of a real installation.
 *
 * What it is NOT allowed to do is decide. The plan it returns goes straight to validator.php,
 * which checks it against facts and the owner's never-touch list and rejects it whole if anything
 * does not resolve. The model is told the rules as well — there is no point hiding them — but the
 * enforcement does not depend on the telling. That is the difference between a prompt and a brake.
 *
 * ONE CORRECTION ROUND. If the validator rejects, the rejections are handed back to the model in
 * their own words and it gets one more try. This is worth doing because the rejections are
 * precise ("target 'garage-heater' is not in the inventory") and a model usually fixes that
 * immediately. It is capped at one because a model that cannot satisfy the schema twice is not
 * going to on the third attempt, and an agent that retries until something passes has quietly
 * turned its brake into a filter.
 *
 * The model runs on our own hardware through Ollama. Nothing in this file talks to a cloud.
 *
 *   php src/compiler.php --state=state.json --policy=policy/example-policy.md
 *   LOCAL_MODEL_NAME=llama3.3 php src/compiler.php --state=... --policy=...
 */

declare(strict_types=1);

require_once __DIR__ . '/validator.php';

const COMPILER_MAX_ROUNDS = 2;          // first attempt + one correction


/** The policy as the owner wrote it, numbered, so an action can point at a line. */
function policy_lines(string $path): array
{
    if (!is_file($path)) return [];
    $in = false; $out = [];
    foreach (explode("\n", (string)file_get_contents($path)) as $l) {
        if (str_starts_with(trim($l), '```')) { $in = !$in; continue; }
        if ($in && trim($l) !== '') $out[] = trim($l);
    }
    return $out;
}


function render_facts(array $facts): string
{
    $out = [];
    foreach ($facts as $id => $f) {
        $v = $f['value'];
        if (is_bool($v)) $v = $v ? 'yes' : 'no';
        $line = sprintf('  %-34s %s %s', $id, (string)$v, (string)($f['unit'] ?? ''));
        if (!empty($f['note'])) $line .= '   (' . $f['note'] . ')';
        $out[] = rtrim($line);
    }
    return implode("\n", $out);
}


function render_inventory(array $inventory, array $protected): string
{
    $out = [];
    foreach ($inventory as $d) {
        $id = (string)($d['id'] ?? '');
        $w  = $d['measured_w'] ?? $d['rated_w'] ?? null;
        $out[] = sprintf('  %-18s %-28s %s%s', $id, (string)($d['label'] ?? ''),
            $w === null ? 'draw unknown' : ($w . ' W'),
            (in_array($id, $protected, true) || !empty($d['protected'])) ? '   [NEVER TOUCH]' : '');
    }
    return implode("\n", $out);
}


function build_prompt(array $state, array $policy, ?array $rejections = null, ?array $previous = null): string
{
    $p = "You are the planning step of an energy-aware network agent.\n\n"
       . "A human wrote the policy below in their own words. Your job is to read it, look at the\n"
       . "measured state of the installation, and return a plan as JSON. Nothing you return is\n"
       . "executed directly: a validator checks it, and a human approves it.\n\n"
       . "POLICY, as written, one line each:\n";
    foreach ($policy as $i => $l) $p .= sprintf("  %d. %s\n", $i + 1, $l);

    $p .= "\nMEASURED STATE (state_ref " . $state['state_id'] . ")\n"
        . "These are the only facts that exist. You may cite a fact only by the id on the left.\n"
        . render_facts($state['facts']) . "\n";

    $p .= "\nDEVICES (the only targets that exist)\n"
        . render_inventory($state['inventory'] ?? [], $state['protected'] ?? []) . "\n";

    $p .= "\nRULES\n"
        . "  - Return ONLY a JSON object, no prose around it.\n"
        . "  - Shape: {\"schema_version\":1, \"state_ref\":\"" . $state['state_id'] . "\",\n"
        . "            \"reasoning\":\"...\", \"actions\":[...]}\n"
        . "  - Each action: {\"target\":\"<device id>\", \"operation\":\"disable|enable|"
        . "limit_bandwidth|set_power_profile\",\n"
        . "                  \"argument\": <only for limit_bandwidth (kbit/s) or set_power_profile "
        . "(eco|balanced|performance)>,\n"
        . "                  \"expected_saving_w\": <integer>,\n"
        . "                  \"because\": {\"policy_line\": <number>, \"evidence\": [\"<fact id>\", ...]}}\n"
        . "  - A target marked [NEVER TOUCH] may not appear in any action, for any reason.\n"
        . "  - Do not invent a device, a fact id or a number. Only what is listed above exists.\n"
        . "  - expected_saving_w may not exceed what that device draws.\n"
        . "  - An empty actions list is a valid answer. If nothing needs cutting, say so in\n"
        . "    reasoning and return no actions. Do not find something to switch off.\n"
        . "  - reasoning: a few sentences a human will read before approving. Say which policy\n"
        . "    line you followed and what the state told you.\n";

    if ($rejections) {
        $p .= "\nYOUR PREVIOUS PLAN WAS REJECTED\n"
            . "The validator refused it whole. Its reasons, verbatim:\n";
        foreach ($rejections as $r) {
            $p .= '  - ' . $r['code'] . ($r['where'] ? ' (' . $r['where'] . ')' : '') . ': ' . $r['why'] . "\n";
        }
        $p .= "\nThe plan you sent:\n" . json_encode($previous, JSON_PRETTY_PRINT) . "\n"
            . "\nReturn a corrected plan. If the reasons mean nothing can be done safely, return\n"
            . "an empty actions list and explain why in reasoning.\n";
    }
    return $p;
}


/** Ask the local model. Replaceable, so the tests can run without a model. */
function ask_ollama(string $prompt, array $opt): string
{
    $url   = rtrim((string)($opt['url'] ?? getenv('LOCAL_MODEL_URL') ?: 'http://127.0.0.1:11434'), '/');
    $model = (string)($opt['model'] ?? getenv('LOCAL_MODEL_NAME') ?: 'llama3.3');
    $body  = json_encode([
        'model'  => $model,
        'prompt' => $prompt,
        'format' => 'json',
        'stream' => false,
        'options' => ['temperature' => 0.2, 'num_predict' => 1536],
    ]);
    $ch = curl_init($url . '/api/generate');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => (int)($opt['timeout'] ?? 180),
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) throw new RuntimeException('model unreachable: ' . $err);
    if ($code !== 200)  throw new RuntimeException('model returned HTTP ' . $code . ': ' . substr((string)$raw, 0, 200));
    $j = json_decode((string)$raw, true);
    if (!isset($j['response'])) throw new RuntimeException('model gave no response field');
    return (string)$j['response'];
}


/**
 * Pull the JSON object out of whatever the model said. Models wrap JSON in prose, in fences, or
 * in an apology. We take the first balanced object and nothing else — and if there isn't one,
 * that is a rejection, not something to repair by hand.
 */
function extract_json(string $text): ?array
{
    $t = trim($text);
    $i = strpos($t, '{');
    if ($i === false) return null;
    $depth = 0; $in_str = false; $esc = false;
    for ($k = $i, $len = strlen($t); $k < $len; $k++) {
        $c = $t[$k];
        if ($in_str) {
            if ($esc) { $esc = false; continue; }
            if ($c === '\\') { $esc = true; continue; }
            if ($c === '"') $in_str = false;
            continue;
        }
        if ($c === '"') { $in_str = true; continue; }
        if ($c === '{') $depth++;
        elseif ($c === '}') {
            $depth--;
            if ($depth === 0) {
                $j = json_decode(substr($t, $i, $k - $i + 1), true);
                return is_array($j) ? $j : null;
            }
        }
    }
    return null;
}


/**
 * @return array{ok: bool, plan: ?array, rounds: array, rejections: array}
 */
function compile_plan(array $state, array $policy, array $opt = [], ?callable $ask = null): array
{
    $ask = $ask ?: 'ask_ollama';
    $rounds = [];
    $rejections = []; $plan = null;

    for ($round = 1; $round <= COMPILER_MAX_ROUNDS; $round++) {
        $prompt = build_prompt($state, $policy, $round > 1 ? $rejections : null, $round > 1 ? $plan : null);
        $t0 = microtime(true);
        try {
            $raw = $ask($prompt, $opt);
        } catch (Throwable $e) {
            $rounds[] = ['round' => $round, 'error' => $e->getMessage()];
            return ['ok' => false, 'plan' => null, 'rounds' => $rounds,
                    'rejections' => [reject('MODEL', null, $e->getMessage())]];
        }
        $secs = round(microtime(true) - $t0, 1);

        $plan = extract_json($raw);
        if ($plan === null) {
            $rejections = [reject('MODEL', null, 'the model did not return a JSON object')];
            $rounds[] = ['round' => $round, 'seconds' => $secs, 'ok' => false,
                         'rejections' => $rejections, 'raw' => substr($raw, 0, 400)];
            continue;
        }

        $r = validate_plan($plan, $state, count($policy));
        $rejections = $r['rejections'];
        $rounds[] = ['round' => $round, 'seconds' => $secs, 'ok' => $r['ok'],
                     'actions' => count($plan['actions'] ?? []), 'rejections' => $rejections];
        if ($r['ok']) {
            return ['ok' => true, 'plan' => $plan, 'rounds' => $rounds, 'rejections' => []];
        }
    }
    return ['ok' => false, 'plan' => $plan, 'rounds' => $rounds, 'rejections' => $rejections];
}


// ── command line ─────────────────────────────────────────────────────────────────────────
if (PHP_SAPI === 'cli' && isset($argv) && realpath($argv[0]) === realpath(__FILE__)) {
    $opt = [];
    foreach (array_slice($argv, 1) as $x) {
        if (preg_match('/^--([a-z_]+)(?:=(.*))?$/', $x, $m)) $opt[$m[1]] = $m[2] ?? true;
    }
    if (!isset($opt['state'])) {
        fwrite(STDERR, "usage: php src/compiler.php --state=state.json "
                     . "[--policy=policy/example-policy.md] [--model=llama3.3] [--json]\n");
        exit(2);
    }
    $state = json_decode((string)@file_get_contents((string)$opt['state']), true);
    if (!is_array($state)) { fwrite(STDERR, "cannot read the state\n"); exit(2); }
    $policy = policy_lines((string)($opt['policy'] ?? 'policy/example-policy.md'));
    if (!$policy) { fwrite(STDERR, "the policy has no lines to compile\n"); exit(2); }

    $r = compile_plan($state, $policy, $opt);

    if (isset($opt['json'])) {
        echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        exit($r['ok'] ? 0 : 1);
    }

    foreach ($r['rounds'] as $x) {
        echo '  round ' . $x['round']
            . (isset($x['seconds']) ? ' · ' . $x['seconds'] . 's' : '')
            . (isset($x['actions']) ? ' · ' . $x['actions'] . ' action(s)' : '')
            . ' · ' . (($x['ok'] ?? false) ? 'accepted' : 'rejected') . "\n";
        foreach (($x['rejections'] ?? []) as $rej) {
            echo '      ' . $rej['code'] . '  ' . ($rej['where'] ? $rej['where'] . ': ' : '') . $rej['why'] . "\n";
        }
    }
    echo "\n";
    if (!$r['ok']) { echo "  REJECTED — nothing goes to a human for approval\n"; exit(1); }

    echo "  PLAN (awaiting human approval)\n\n";
    echo '  ' . wordwrap((string)$r['plan']['reasoning'], 92, "\n  ") . "\n\n";
    foreach ($r['plan']['actions'] as $a) {
        echo '    ' . $a['operation'] . ' ' . $a['target']
            . (isset($a['argument']) && $a['argument'] !== null ? ' ' . $a['argument'] : '')
            . (isset($a['expected_saving_w']) ? '   -' . $a['expected_saving_w'] . ' W' : '')
            . "\n      policy line " . $a['because']['policy_line']
            . ', evidence: ' . implode(', ', $a['because']['evidence']) . "\n";
    }
    if (!$r['plan']['actions']) echo "    (no actions — nothing needs cutting)\n";
    echo "\n";
    exit(0);
}

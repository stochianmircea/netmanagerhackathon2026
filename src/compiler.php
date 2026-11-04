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
 * TWO ROADS, and the file says which. By default the model runs on our own hardware through
 * Ollama: a plan that decides what to switch off must stay compilable with the network down.
 * With --furnizor=meta the same policy is compiled through Meta Model API instead, so the two
 * can be set side by side with tools/comparison.php. Which road ran is printed and returned —
 * a comparison you cannot attribute to a road is not a comparison. The API key is read from
 * the environment and is never written into this repository.
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
        $prot = in_array($id, $protected, true) || !empty($d['protected']);
        $ops = $d['operations'] ?? null;
        if ($prot) {
            $what = '[NEVER TOUCH]';
        } elseif (is_array($ops)) {
            $bits = [];
            foreach ($ops as $k => $v) {
                $saves = op_worth($d, $k);
                $bits[] = $k . ($saves === null ? '' : ' saves ' . $saves . ' W');
            }
            $what = $bits ? implode(', ', $bits) : 'nothing may be done to it';
        } else {
            $what = 'operations not declared';
        }
        $out[] = sprintf('  %-18s %-28s %-12s %s', $id, (string)($d['label'] ?? ''),
            $w === null ? 'draw ?' : ($w . ' W'), $what);
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

    $p .= "\nDEVICES — the only targets that exist, and the only operations allowed on each.\n"
        . "The watts beside an operation are what the OWNER measured it to be worth. Do not\n"
        . "claim more than that, and do not use an operation that is not listed for a device.\n"
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


/**
 * Ask Meta Model API. Same contract as ask_ollama: prompt in, raw text out, throw on failure.
 *
 * NM_META_CALE_20261030. The hackathon asks for Meta's models, and this project already uses one
 * — Llama 3.3, open weights, on our own hardware. This adds the second road the rules name: the
 * hosted Meta Model API. Both compile the same policy, so the two can be compared side by side
 * with tools/comparison.php. The local road stays the default: a plan that decides what to switch
 * off should still be compilable with the network down.
 *
 * Shape taken from Meta's own quickstart, not from memory:
 *     POST {base}/responses
 *     Authorization: Bearer <key>
 *     {"model":"muse-spark-1.3","input":"<prompt>"}
 * and the text comes back under output → (type=message) → content → (type=output_text) → text.
 *
 * NO JSON MODE. Ollama is told `format: json`; this API documents no equivalent. That is fine:
 * extract_json() already pulls the first balanced object out of whatever the model wrapped it in.
 * It is the reason that function exists.
 *
 * THE KEY NEVER ENTERS THIS REPOSITORY. It is read from the environment, and .env is in
 * .gitignore. Error text is scrubbed of anything that looks like a bearer token before it is
 * raised, because a RuntimeException ends up in logs and in this tool's own output.
 */
function ask_meta(string $prompt, array $opt): string
{
    $base  = rtrim((string)($opt['api_url'] ?? getenv('META_API_URL') ?: 'https://api.meta.ai/v1'), '/');
    $key   = (string)($opt['api_key'] ?? getenv('META_API_KEY') ?: '');
    $model = (string)($opt['meta_model'] ?? getenv('META_MODEL_NAME') ?: 'muse-spark-1.3');

    if ($key === '') {
        throw new RuntimeException('META_API_KEY is not set — copy .env.example to .env and fill it in');
    }

    $scrub = static function (string $s): string {
        return (string)preg_replace('/(Bearer\s+)[A-Za-z0-9._\-]+/i', '$1[hidden]', $s);
    };

    $ch = curl_init($base . '/responses');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['model' => $model, 'input' => $prompt]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
        CURLOPT_RETURNTRANSFER => true,
        /* The hosted road is far quicker than 70B on our own card, but a cold start is still a
           start. 120 s, and MODEL_TIMEOUT raises it the same way it does for the local road. */
        CURLOPT_TIMEOUT        => (int)($opt['timeout'] ?? (getenv('MODEL_TIMEOUT') ?: 120)),
    ]);
    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) throw new RuntimeException('Meta Model API unreachable: ' . $scrub($err));
    if ($code !== 200) {
        $hint = '';
        if ($code === 401 || $code === 403) $hint = '  (the key is missing, wrong, or out of credit)';
        if ($code === 404) $hint = '  (model id must be exact, e.g. muse-spark-1.3)';
        if ($code === 429) $hint = '  (rate limited — wait, or lower the number of rounds)';
        throw new RuntimeException('Meta Model API (' . $model . ') returned HTTP ' . $code . ': '
            . $scrub(trim(substr((string)$raw, 0, 200))) . $hint);
    }

    $j = json_decode((string)$raw, true);
    if (!is_array($j)) throw new RuntimeException('Meta Model API gave something that is not JSON');

    /* The SDK exposes output_text directly; over plain HTTP we dig. We take every output_text in
       the message item and nothing else — the reasoning item is not the answer. */
    $text = '';
    if (isset($j['output_text']) && is_string($j['output_text'])) {
        $text = $j['output_text'];
    } else {
        foreach ((array)($j['output'] ?? []) as $item) {
            if ((string)($item['type'] ?? '') !== 'message') continue;
            foreach ((array)($item['content'] ?? []) as $c) {
                if ((string)($c['type'] ?? '') === 'output_text' && isset($c['text'])) {
                    $text .= (string)$c['text'];
                }
            }
        }
    }
    if (trim($text) === '') throw new RuntimeException('Meta Model API returned no output_text');
    return $text;
}


/**
 * Which road compiles this plan. --furnizor=local|meta, or COMPILER_PROVIDER, default local.
 * Returned as a name so the verbose line and the journal can say which one actually ran: a
 * comparison between two roads is worthless if you cannot tell which road you were on.
 */
function compiler_provider(array $opt): string
{
    $p = strtolower(trim((string)($opt['furnizor'] ?? getenv('COMPILER_PROVIDER') ?: 'local')));
    if ($p === 'meta' || $p === 'api') return 'meta';
    if ($p === 'local' || $p === 'ollama' || $p === '') return 'local';
    throw new RuntimeException('unknown --furnizor=' . $p . ' (use local or meta)');
}


/** The model name that will actually be asked, for printing. Never the key. */
function compiler_model_name(array $opt): string
{
    return compiler_provider($opt) === 'meta'
        ? (string)($opt['meta_model'] ?? getenv('META_MODEL_NAME') ?: 'muse-spark-1.3')
        : (string)($opt['model'] ?? getenv('LOCAL_MODEL_NAME') ?: 'llama3.3');
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
        /* NM_HACK_TIMEOUT_20261029 — masurat pe 144 de rulari cu Llama 3.3 (70B, Q4): 43 au
           expirat la 180 s, fiindca doar ~13 GB din 45 au incaput pe placa video si restul
           s-a macinat pe procesor. Un model mare pe hardware modest are nevoie de mai mult.
           Implicitul ramane 180; MODEL_TIMEOUT il ridica fara sa se atinga nimic altceva. */
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => (int)($opt['timeout'] ?? (getenv('MODEL_TIMEOUT') ?: 180)),
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) throw new RuntimeException('model unreachable: ' . $err);
    if ($code !== 200) {
        $hint = '';
        if ($code === 404) $hint = "  (model name is case sensitive in Ollama — check `ollama list`)";
        throw new RuntimeException('model ' . $model . ' returned HTTP ' . $code . ': '
            . trim(substr((string)$raw, 0, 200)) . $hint);
    }
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
    /* Which road compiles this. An explicit callable — the tests pass one — still wins over
       both, so nothing here makes the test suite reach for a model.
       A misspelt road is refused, not quietly turned into the local one: being told you ran
       on Meta when you ran on Ollama is the worst answer this function could give. It comes
       back as a rejection like any other, so the caller prints it instead of a stack trace. */
    try {
        $furnizor = compiler_provider($opt);
    } catch (Throwable $e) {
        return ['ok' => false, 'plan' => null,
                'rounds' => [['round' => 0, 'error' => $e->getMessage()]],
                'rejections' => [reject('MODEL', null, $e->getMessage())]];
    }
    $ask = $ask ?: ($furnizor === 'meta' ? 'ask_meta' : 'ask_ollama');
    $rounds = [];
    $rejections = []; $plan = null;

    for ($round = 1; $round <= COMPILER_MAX_ROUNDS; $round++) {
        $prompt = build_prompt($state, $policy, $round > 1 ? $rejections : null, $round > 1 ? $plan : null);
        $t0 = microtime(true);
        if (!empty($opt['verbose'])) {
            /* It used to print 'llama3.1:8b' as the fallback name while ask_ollama asked for
               'llama3.3' — the screen named a model that was never called. One name now. */
            fwrite(STDERR, '  round ' . $round . ' · asking ' . compiler_model_name($opt)
                . ' on the ' . $furnizor . ' road'
                . ($furnizor === 'meta'
                    ? ' (' . (string)($opt['api_url'] ?? getenv('META_API_URL') ?: 'https://api.meta.ai/v1') . ')'
                    : ' at ' . (string)($opt['url'] ?? getenv('LOCAL_MODEL_URL') ?: 'http://127.0.0.1:11434'))
                . " …\n");
        }
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
                     'furnizor' => $furnizor,
                     /* si MODELUL, nu doar drumul: altfel cine citeste planul mai tarziu
                        trebuie sa ghiceasca, si ghicitul a fost deja gresit o data. */
                     'model' => compiler_model_name($opt),
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
                     . "[--policy=policy/example-policy.md] [--model=llama3.3]\n"
                     . "       [--furnizor=local|meta] [--json]\n");
        exit(2);
    }
    $state = json_decode((string)@file_get_contents((string)$opt['state']), true);
    if (!is_array($state)) { fwrite(STDERR, "cannot read the state\n"); exit(2); }
    $policy = policy_lines((string)($opt['policy'] ?? 'policy/example-policy.md'));
    if (!$policy) { fwrite(STDERR, "the policy has no lines to compile\n"); exit(2); }

    $opt['verbose'] = !isset($opt['json']);
    $r = compile_plan($state, $policy, $opt);

    if (isset($opt['json'])) {
        echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        exit($r['ok'] ? 0 : 1);
    }

    $spus = 0;
    foreach ($r['rounds'] as $x) {
        echo '  round ' . $x['round']
            . (isset($x['furnizor']) ? ' · ' . $x['furnizor'] : '')
            . (isset($x['seconds']) ? ' · ' . $x['seconds'] . 's' : '')
            . (isset($x['actions']) ? ' · ' . $x['actions'] . ' action(s)' : '')
            . ' · ' . (($x['ok'] ?? false) ? 'accepted' : 'rejected') . "\n";
        /* Un round care a aruncat o exceptie n-are 'rejections' — si pana acum mesajul ei nu
           ajungea nicaieri. Un program care esueaza fara sa spuna de ce e mai rau decat unul
           care cade zgomotos. */
        if (isset($x['error'])) { echo '      MODEL  ' . $x['error'] . "\n"; $spus++; }
        foreach (($x['rejections'] ?? []) as $rej) {
            echo '      ' . $rej['code'] . '  ' . ($rej['where'] ? $rej['where'] . ': ' : '') . $rej['why'] . "\n";
            $spus++;
        }
        if (isset($x['raw'])) echo '      the model said: ' . trim(preg_replace('/\s+/', ' ', $x['raw'])) . "\n";
    }
    if (!$spus) {
        foreach ($r['rejections'] as $rej) echo '      ' . $rej['code'] . '  ' . $rej['why'] . "\n";
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

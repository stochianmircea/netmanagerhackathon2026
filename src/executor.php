<?php
/**
 * executor.php — the only file that touches the network, and the hardest to persuade.
 *
 * Everything before this point produces words: a plan, a verdict, a reason. This is where words
 * become a switch going off in a building. So it is deliberately difficult:
 *
 *   1. It re-validates the plan itself. It does not trust that someone validated it earlier.
 *   2. It refuses a plan argued on a state that is no longer current. An approval given twenty
 *      minutes ago is about a battery that has moved since.
 *   3. The approval is bound to the plan's FINGERPRINT. Approving one plan does not approve a
 *      different one, and editing an action after approval invalidates it.
 *   4. It only performs what the owner declared. An action with no `apply` block in the site
 *      config cannot be executed at all — there is no generic "do something to a device" path.
 *   5. Without --apply it changes nothing and prints what it would send, down to the request.
 *
 * Point 4 matters more than it looks. It means this file contains no knowledge of anyone's
 * network. It cannot invent an endpoint, because every endpoint it knows came out of a file the
 * site owner wrote. The agent's reach is exactly what its owner wrote down, and not one device
 * further.
 *
 *   php src/executor.php --plan=plan.json --state=state.json --site=config/site.example.json
 *   php src/executor.php --plan=plan.json --state=state.json --site=... --approve=<fingerprint> --apply
 */

declare(strict_types=1);

require_once __DIR__ . '/validator.php';
require_once __DIR__ . '/journal.php';

const EXECUTOR_MAX_STATE_AGE = 300;        // seconds; after this a plan is stale, not approved


function op_spec(array $dev, string $op): ?array
{
    $ops = $dev['operations'] ?? null;
    if (!is_array($ops) || !array_key_exists($op, $ops)) return null;
    $v = $ops[$op];
    if (is_array($v)) return $v;                       // {saves_w: .., apply: {..}}
    return ['saves_w' => (int)$v];                     // worth declared, but no way to apply it
}


/**
 * Fill {{argument}} and {{target}} in a declared request. Nothing else is interpolated — there is
 * no expression, no fact lookup, no second placeholder syntax. The owner's template plus two
 * values out of the action, and that is the whole of it.
 */
function fill($tpl, array $action)
{
    if (is_array($tpl)) {
        $out = [];
        foreach ($tpl as $k => $v) $out[$k] = fill($v, $action);
        return $out;
    }
    if (!is_string($tpl)) return $tpl;
    return str_replace(
        ['{{argument}}', '{{target}}'],
        [(string)($action['argument'] ?? ''), (string)($action['target'] ?? '')],
        $tpl);
}


/** Send one declared request. Returns [ok, detail]. Credentials come from the environment. */
function send_request(array $req, array $opt): array
{
    $url = (string)($req['url'] ?? '');
    if ($url === '') return [false, 'the declared request has no url'];
    $method = strtoupper((string)($req['method'] ?? 'POST'));
    $body = isset($req['body']) ? json_encode($req['body']) : null;

    $head = ['Content-Type: application/json'];
    $user = getenv((string)($req['user_env'] ?? 'ROUTEROS_USER'));
    $pass = getenv((string)($req['pass_env'] ?? 'ROUTEROS_PASSWORD'));

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => (int)($opt['timeout'] ?? 20),
        CURLOPT_HTTPHEADER => $head,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    if ($user !== false && $user !== '') {
        curl_setopt($ch, CURLOPT_USERPWD, $user . ':' . ($pass ?: ''));
    }
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) return [false, 'unreachable: ' . $err];
    if ($code >= 200 && $code < 300) return [true, 'HTTP ' . $code];
    return [false, 'HTTP ' . $code . ': ' . trim(substr((string)$raw, 0, 160))];
}


/**
 * @return array{ok: bool, refusals: array, results: array, fingerprint: string}
 */
function execute_plan(array $plan, array $state, array $site, array $opt = [], ?callable $send = null)
{
    $send = $send ?: 'send_request';
    $fp = plan_fingerprint($plan);
    $refusals = [];

    // 1. we validate it ourselves, whatever anyone said before
    $policy_lines = (int)($opt['policy_lines'] ?? 0);
    $v = validate_plan($plan, $state, $policy_lines);
    if (!$v['ok']) {
        foreach ($v['rejections'] as $r) $refusals[] = $r['code'] . ': ' . $r['why'];
    }

    // 2. a plan argued on a state that has gone cold is not a plan about now
    $age = null;
    if (!empty($state['taken_at'])) {
        $age = time() - strtotime((string)$state['taken_at']);
        $max = (int)($opt['max_age'] ?? EXECUTOR_MAX_STATE_AGE);
        if ($age > $max) {
            $refusals[] = sprintf('STALE: the state is %d seconds old, the limit is %d — '
                . 'read the installation again and re-plan', $age, $max);
        }
    }

    // 3. the approval is for THIS plan
    $given = (string)($opt['approve'] ?? '');
    $apply = !empty($opt['apply']);
    if ($apply) {
        if ($given === '') {
            $refusals[] = 'APPROVAL: --apply needs --approve=<fingerprint>';
        } elseif (!hash_equals($fp, $given)) {
            $refusals[] = "APPROVAL: approval is for plan $given, this plan is $fp";
        }
    }

    // 4. every action must have a way to be performed that the owner wrote down
    $inv = [];
    foreach (($site['inventory'] ?? $state['inventory'] ?? []) as $d) {
        if (isset($d['id'])) $inv[(string)$d['id']] = $d;
    }
    $todo = [];
    foreach (($plan['actions'] ?? []) as $i => $a) {
        $t = (string)($a['target'] ?? ''); $op = (string)($a['operation'] ?? '');
        $dev = $inv[$t] ?? null;
        if (!$dev) { $refusals[] = "action[$i]: '$t' is not in the site inventory"; continue; }
        $spec = op_spec($dev, $op);
        if ($spec === null) {
            $refusals[] = "action[$i]: '$op' is not declared for '$t'"; continue;
        }
        if (empty($spec['apply']) || !is_array($spec['apply'])) {
            $refusals[] = "action[$i]: '$op' on '$t' has no declared way to perform it — "
                        . 'the owner must write down the request before an agent may send it';
            continue;
        }
        $todo[] = ['i' => $i, 'action' => $a, 'request' => fill($spec['apply'], $a)];
    }

    journal_write('proposed', [
        'state_ref' => $plan['state_ref'] ?? null, 'fingerprint' => $fp,
        'accepted' => ($refusals === []), 'actions' => $plan['actions'] ?? [],
        'rejections' => $v['rejections'], 'state_age_s' => $age,
    ], $opt);

    if ($refusals !== []) {
        foreach ($refusals as $why) journal_write('refused', ['fingerprint' => $fp, 'why' => $why], $opt);
        return ['ok' => false, 'refusals' => $refusals, 'results' => [], 'fingerprint' => $fp];
    }

    if (!$apply) {
        return ['ok' => true, 'refusals' => [], 'results' => array_map(function ($x) {
            return ['action' => $x['action'], 'would_send' => $x['request'], 'sent' => false];
        }, $todo), 'fingerprint' => $fp];
    }

    journal_write('approved', ['fingerprint' => $fp, 'by' => (string)($opt['by'] ?? 'operator')], $opt);

    // 5. one by one, and each result recorded on its own — a half-applied plan is the thing a
    //    journal exists to explain
    $results = [];
    foreach ($todo as $x) {
        [$ok, $detail] = $send($x['request'], $opt);
        $results[] = ['action' => $x['action'], 'sent' => true, 'ok' => $ok, 'detail' => $detail];
        journal_write('executed', [
            'fingerprint' => $fp, 'target' => $x['action']['target'],
            'operation' => $x['action']['operation'], 'argument' => $x['action']['argument'] ?? null,
            'ok' => $ok, 'detail' => $detail,
        ], $opt);
        if (!$ok && empty($opt['keep_going'])) {
            $results[] = ['stopped_after' => $x['action']['target']];
            break;
        }
    }
    $all = true;
    foreach ($results as $r) if (isset($r['ok']) && !$r['ok']) $all = false;
    return ['ok' => $all, 'refusals' => [], 'results' => $results, 'fingerprint' => $fp];
}


// ── command line ─────────────────────────────────────────────────────────────────────────
if (PHP_SAPI === 'cli' && isset($argv) && realpath($argv[0]) === realpath(__FILE__)) {
    $opt = [];
    foreach (array_slice($argv, 1) as $x) {
        if (preg_match('/^--([a-z_]+)(?:=(.*))?$/', $x, $m)) $opt[$m[1]] = $m[2] ?? true;
    }
    foreach (['plan', 'state', 'site'] as $need) {
        if (!isset($opt[$need])) {
            fwrite(STDERR, "usage: php src/executor.php --plan=plan.json --state=state.json "
                         . "--site=config/site.example.json [--approve=<fingerprint>] [--apply]\n");
            exit(2);
        }
    }
    $read = function (string $p) {
        $j = json_decode((string)@file_get_contents($p), true);
        if (!is_array($j)) { fwrite(STDERR, "cannot read $p\n"); exit(2); }
        return $j;
    };
    $plan = $read((string)$opt['plan']); $state = $read((string)$opt['state']);
    $site = $read((string)$opt['site']);
    $opt['policy_lines'] = policy_line_count((string)($opt['policy'] ?? 'policy/example-policy.md'));

    $r = execute_plan($plan, $state, $site, $opt);

    if (!$r['ok'] && $r['refusals']) {
        echo "  REFUSED — nothing was sent\n";
        foreach ($r['refusals'] as $x) echo '      ' . $x . "\n";
        echo "\n";
        exit(1);
    }

    if (empty($opt['apply'])) {
        echo "  DRY RUN — nothing was sent. Plan fingerprint: " . $r['fingerprint'] . "\n\n";
        foreach ($r['results'] as $x) {
            $a = $x['action'];
            echo '    ' . $a['operation'] . ' ' . $a['target']
                . (isset($a['argument']) && $a['argument'] !== null ? ' ' . $a['argument'] : '') . "\n";
            echo '      would send: ' . strtoupper((string)($x['would_send']['method'] ?? 'POST'))
                . ' ' . (string)($x['would_send']['url'] ?? '') . "\n";
            if (isset($x['would_send']['body'])) {
                echo '                  ' . json_encode($x['would_send']['body']) . "\n";
            }
        }
        echo "\n  To carry it out:\n";
        echo "    php src/executor.php --plan=... --state=... --site=... "
           . "--approve=" . $r['fingerprint'] . " --apply\n\n";
        exit(0);
    }

    echo "  APPLIED — fingerprint " . $r['fingerprint'] . "\n\n";
    foreach ($r['results'] as $x) {
        if (isset($x['stopped_after'])) {
            echo "    stopped after '" . $x['stopped_after'] . "' — the rest was not attempted\n";
            continue;
        }
        $a = $x['action'];
        echo '    ' . ($x['ok'] ? ' ok ' : 'FAIL') . '  ' . $a['operation'] . ' ' . $a['target']
            . '   ' . $x['detail'] . "\n";
    }
    echo "\n  journal: php src/journal.php --last=20\n\n";
    exit($r['ok'] ? 0 : 1);
}

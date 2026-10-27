<?php
/**
 * executor_test.php — proof that the last gate holds.
 *
 * The validator decides whether a plan may exist. The executor decides whether a plan that exists
 * may become a switch going off in a building, and it is the only file here that can cause
 * something irreversible. So these cases are all about the ways an approval could be stretched
 * past what a human actually agreed to:
 *
 *   - approving one plan and running a different one
 *   - editing an action after approval
 *   - approving now and applying twenty minutes later, when the battery has moved
 *   - an operation the owner measured but never wrote down how to perform
 *   - a target the owner never put in the site file
 *
 * Nothing here touches the network: the request sender is replaced with one that records what it
 * was handed. Nothing here writes to the real journal either — each case gets its own file in a
 * temporary directory, which is also how the redaction case can read back what was written.
 *
 * Run it:   php tests/executor_test.php
 */

declare(strict_types=1);
require_once __DIR__ . '/../src/executor.php';

$TMP = sys_get_temp_dir() . '/agent-executor-test-' . getmypid();
@mkdir($TMP, 0750, true);
$JN = 0;

/** A site file in the shape an owner writes, with real `apply` blocks. */
function site(): array
{
    return [
        'inventory' => [
            ['id' => 'guest-wifi', 'label' => 'Guest Wi-Fi', 'measured_w' => 120, 'protected' => false,
             'operations' => [
                 'disable' => ['saves_w' => 120, 'apply' => [
                     'method' => 'POST', 'url' => 'https://10.0.0.1/rest/interface/wireless/set',
                     'body' => ['numbers' => '{{target}}', 'disabled' => 'yes'],
                     'user_env' => 'ROUTEROS_USER', 'pass_env' => 'ROUTEROS_PASSWORD']],
                 'limit_bandwidth' => ['saves_w' => 8, 'apply' => [
                     'method' => 'POST', 'url' => 'https://10.0.0.1/rest/queue/simple/set',
                     'body' => ['numbers' => '{{target}}', 'max-limit' => '{{argument}}k/{{argument}}k']]],
             ]],
            ['id' => 'lab-switch', 'label' => 'Lab switch', 'measured_w' => 210, 'protected' => false,
             'operations' => [
                 'disable' => ['saves_w' => 210, 'apply' => [
                     'method' => 'POST', 'url' => 'https://10.0.0.1/rest/interface/ethernet/poe/set',
                     'body' => ['numbers' => 'ether5', 'poe-out' => 'off']]],
             ]],
            // measured, and deliberately not writable: worth declared, no way to perform it
            ['id' => 'media-server', 'label' => 'Media server', 'measured_w' => 180, 'protected' => false,
             'operations' => ['set_power_profile' => 55]],
            ['id' => 'gate-camera', 'label' => 'Gate camera', 'measured_w' => 9, 'protected' => true,
             'operations' => []],
        ],
        'protected' => ['gate-camera'],
    ];
}

/** The state as the collector would hand it over: taken now, so it is not stale. */
function state(array $change = []): array
{
    $s = [
        'state_id' => 's-test-0001',
        'taken_at' => date('c'),
        'facts' => [
            'battery.soc'     => ['value' => 28, 'unit' => '%'],
            'battery.power_w' => ['value' => 2140, 'unit' => 'W'],
            'grid.available'  => ['value' => false],
        ],
        'inventory' => site()['inventory'],
        'protected' => site()['protected'],
    ];
    foreach ($change as $k => $v) $s[$k] = $v;
    return $s;
}

function plan(?array $actions = null, array $change = []): array
{
    $p = [
        'schema_version' => 1,
        'state_ref' => 's-test-0001',
        'reasoning' => 'Battery at 28% with no grid and 2.1 kW leaving it. Guest Wi-Fi is '
                     . 'sacrificial by policy, so it goes first.',
        'actions' => $actions ?? [[
            'target' => 'guest-wifi', 'operation' => 'disable', 'expected_saving_w' => 120,
            'because' => ['policy_line' => 2, 'evidence' => ['battery.soc', 'grid.available']],
        ]],
    ];
    foreach ($change as $k => $v) $p[$k] = $v;
    return $p;
}

function action(array $change = []): array
{
    return array_merge([
        'target' => 'lab-switch', 'operation' => 'disable', 'expected_saving_w' => 210,
        'because' => ['policy_line' => 3, 'evidence' => ['battery.soc', 'battery.power_w']],
    ], $change);
}

/** A sender that records instead of sending. $fail lists targets whose request should fail. */
function recorder(&$log, array $fail = []): callable
{
    return function (array $req, array $opt) use (&$log, $fail) {
        $log[] = $req;
        foreach ($fail as $needle) {
            if (strpos(json_encode($req), $needle) !== false) return [false, 'HTTP 500: computer says no'];
        }
        return [true, 'HTTP 200'];
    };
}

/** Each case gets its own journal, so a case can read back what it wrote. */
function run(array $plan, array $state, array $opt, &$log, array $fail = []): array
{
    global $TMP, $JN;
    $opt['journal'] = $TMP . '/j' . (++$JN) . '.ndjson';
    $opt['policy_lines'] = 12;
    $log = [];
    $r = execute_plan($plan, $state, site(), $opt, recorder($log, $fail));
    $r['_journal'] = $opt['journal'];
    return $r;
}

function refused(array $r, string $needle): bool
{
    foreach ($r['refusals'] as $x) if (stripos($x, $needle) !== false) return true;
    return false;
}

$cases = [];

// ── the dry run: the default, and it must be incapable of sending ──────────────────────────
$cases['without --apply nothing is sent, and the request is shown instead'] = function () {
    $r = run(plan(), state(), [], $log);
    if (!$r['ok']) return 'refused: ' . implode('; ', $r['refusals']);
    if ($log !== []) return 'the dry run sent ' . count($log) . ' request(s)';
    $w = $r['results'][0]['would_send'] ?? null;
    if (!$w || ($w['url'] ?? '') === '') return 'no request was shown';
    return true;
};

$cases['the dry run interpolates the target and the argument, nothing else'] = function () {
    $r = run(plan([action(['target' => 'guest-wifi', 'operation' => 'limit_bandwidth',
                           'argument' => 2048, 'expected_saving_w' => 8])]), state(), [], $log);
    if (!$r['ok']) return 'refused: ' . implode('; ', $r['refusals']);
    $b = $r['results'][0]['would_send']['body'] ?? [];
    if (($b['numbers'] ?? '') !== 'guest-wifi') return 'target not filled in: ' . json_encode($b);
    if (($b['max-limit'] ?? '') !== '2048k/2048k') return 'argument not filled in: ' . json_encode($b);
    return true;
};

// ── the fingerprint: what an approval is actually for ──────────────────────────────────────
$cases['rewording the reasoning does not invalidate an approval'] = function () {
    $a = plan_fingerprint(plan());
    $b = plan_fingerprint(plan(null, ['reasoning' => 'A completely different way of saying the '
        . 'same thing, at the same length, about the same single action.']));
    return $a === $b ? true : "fingerprint changed on reasoning alone: $a vs $b";
};

$cases['changing an action does invalidate it'] = function () {
    $a = plan_fingerprint(plan());
    foreach ([
        'target'    => plan([action(['target' => 'lab-switch'])]),
        'operation' => plan([action(['target' => 'guest-wifi', 'operation' => 'limit_bandwidth',
                                     'argument' => 2048, 'expected_saving_w' => 8])]),
        'saving'    => plan([action(['target' => 'guest-wifi', 'expected_saving_w' => 119])]),
    ] as $what => $p) {
        if (plan_fingerprint($p) === $a) return "fingerprint survived a changed $what";
    }
    return true;
};

$cases['adding a second action invalidates it'] = function () {
    $one = plan_fingerprint(plan());
    $two = plan_fingerprint(plan([
        action(['target' => 'guest-wifi', 'expected_saving_w' => 120]),
        action(),
    ]));
    return $one !== $two ? true : 'fingerprint survived an extra action';
};

// ── approval ───────────────────────────────────────────────────────────────────────────────
$cases['--apply without an approval sends nothing'] = function () {
    $r = run(plan(), state(), ['apply' => true], $log);
    if ($log !== []) return 'a request was sent without approval';
    return refused($r, 'APPROVAL') ? true : 'not refused for want of approval: '
        . implode('; ', $r['refusals']);
};

$cases['an approval for another plan sends nothing'] = function () {
    $r = run(plan(), state(), ['apply' => true, 'approve' => 'deadbeefdeadbeef'], $log);
    if ($log !== []) return 'a request was sent on someone else\'s approval';
    return refused($r, 'APPROVAL') ? true : 'wrong fingerprint was accepted';
};

$cases['an approval, then an edited action, sends nothing'] = function () {
    // the human reads and approves a plan that switches off guest Wi-Fi...
    $approved = plan_fingerprint(plan());
    // ...and what arrives at the executor switches off the lab switch instead
    $r = run(plan([action()]), state(), ['apply' => true, 'approve' => $approved], $log);
    if ($log !== []) return 'the edited plan was carried out under the old approval';
    return refused($r, 'APPROVAL') ? true : 'the swap was not caught';
};

$cases['the right approval on the right plan is carried out'] = function () {
    $p = plan();
    $r = run($p, state(), ['apply' => true, 'approve' => plan_fingerprint($p)], $log);
    if (!$r['ok']) return 'refused: ' . implode('; ', $r['refusals']);
    if (count($log) !== 1) return 'sent ' . count($log) . ' request(s), expected 1';
    if (($log[0]['body']['numbers'] ?? '') !== 'guest-wifi') return 'sent the wrong request';
    return true;
};

// ── staleness: an approval is about a state, and states go cold ────────────────────────────
$cases['a state older than the limit is refused, however valid the approval'] = function () {
    $p = plan();
    $s = state(['taken_at' => date('c', time() - 1200)]);
    $r = run($p, $s, ['apply' => true, 'approve' => plan_fingerprint($p)], $log);
    if ($log !== []) return 'a 20-minute-old state was acted on';
    return refused($r, 'STALE') ? true : 'staleness not caught: ' . implode('; ', $r['refusals']);
};

$cases['a state inside the limit is not called stale'] = function () {
    $p = plan();
    $s = state(['taken_at' => date('c', time() - 60)]);
    $r = run($p, $s, ['apply' => true, 'approve' => plan_fingerprint($p)], $log);
    return $r['ok'] && count($log) === 1 ? true : 'refused: ' . implode('; ', $r['refusals']);
};

// ── the reach of the agent is exactly what the owner wrote down ────────────────────────────
$cases['an operation with a measured worth but no apply block cannot be performed'] = function () {
    $p = plan([action(['target' => 'media-server', 'operation' => 'set_power_profile',
                       'argument' => 'eco', 'expected_saving_w' => 55])]);
    $r = run($p, state(), [], $log);
    if ($log !== []) return 'something was sent';
    return refused($r, 'no declared way to perform it') ? true
        : 'not refused: ' . implode('; ', $r['refusals']);
};

$cases['a device the owner left out of the site file cannot be acted on'] = function () {
    // it exists in the state the collector built, but not in the site file the executor reads
    $s = state();
    $s['inventory'][] = ['id' => 'spare-ap', 'label' => 'Spare AP', 'measured_w' => 40,
                         'protected' => false, 'operations' => ['disable' => 40]];
    $p = plan([action(['target' => 'spare-ap', 'expected_saving_w' => 40])]);
    $r = run($p, $s, [], $log);
    if ($log !== []) return 'something was sent';
    return refused($r, 'not in the site inventory') ? true
        : 'not refused: ' . implode('; ', $r['refusals']);
};

// ── the executor does not trust that anyone validated the plan ─────────────────────────────
$cases['a protected target is refused again here, approval or not'] = function () {
    $p = plan([action(['target' => 'gate-camera', 'expected_saving_w' => 9])]);
    $r = run($p, state(), ['apply' => true, 'approve' => plan_fingerprint($p)], $log);
    if ($log !== []) return 'the never-touch list was crossed';
    return refused($r, 'PROTECTION') ? true : 'not refused: ' . implode('; ', $r['refusals']);
};

$cases['an invented saving is refused again here'] = function () {
    $p = plan([action(['target' => 'guest-wifi', 'expected_saving_w' => 2000]),]);
    $r = run($p, state(), ['apply' => true, 'approve' => plan_fingerprint($p)], $log);
    if ($log !== []) return 'something was sent';
    return refused($r, 'ARITHMETIC') ? true : 'not refused: ' . implode('; ', $r['refusals']);
};

$cases['one bad action stops the whole plan — the good one is not sent either'] = function () {
    $p = plan([
        action(['target' => 'guest-wifi', 'expected_saving_w' => 120]),
        action(['target' => 'gate-camera', 'expected_saving_w' => 9]),
    ]);
    $r = run($p, state(), ['apply' => true, 'approve' => plan_fingerprint($p)], $log);
    return $log === [] ? true : 'sent ' . count($log) . ' request(s) from a rejected plan';
};

// ── half-applied, which is the case the journal exists for ─────────────────────────────────
$cases['when a request fails the rest is not attempted'] = function () {
    $p = plan([
        action(['target' => 'guest-wifi', 'expected_saving_w' => 120]),
        action(),
    ]);
    $r = run($p, state(), ['apply' => true, 'approve' => plan_fingerprint($p)], $log, ['guest-wifi']);
    if ($r['ok']) return 'reported success although the first request failed';
    if (count($log) !== 1) return 'carried on after a failure: ' . count($log) . ' request(s)';
    $stopped = false;
    foreach ($r['results'] as $x) if (isset($x['stopped_after'])) $stopped = true;
    return $stopped ? true : 'the stop was not recorded in the results';
};

$cases['the journal records each action separately, including the failure'] = function () {
    $p = plan([
        action(['target' => 'guest-wifi', 'expected_saving_w' => 120]),
        action(),
    ]);
    $r = run($p, state(), ['apply' => true, 'approve' => plan_fingerprint($p)], $log, ['guest-wifi']);
    $kinds = [];
    foreach (journal_tail(50, ['journal' => $r['_journal']]) as $e) $kinds[] = $e['kind'] ?? '';
    foreach (['proposed', 'approved', 'executed'] as $k) {
        if (!in_array($k, $kinds, true)) return "no '$k' entry in the journal (" . implode(',', $kinds) . ')';
    }
    foreach (journal_tail(50, ['journal' => $r['_journal']]) as $e) {
        if (($e['kind'] ?? '') === 'executed' && ($e['target'] ?? '') === 'guest-wifi') {
            return empty($e['ok']) ? true : 'the failed action was journalled as ok';
        }
    }
    return 'the failed action was not journalled';
};

$cases['a refusal is journalled too — a plan that was stopped is still a record'] = function () {
    $p = plan([action(['target' => 'gate-camera', 'expected_saving_w' => 9])]);
    $r = run($p, state(), [], $log);
    $seen = false;
    foreach (journal_tail(50, ['journal' => $r['_journal']]) as $e) {
        if (($e['kind'] ?? '') === 'refused') $seen = true;
        if (($e['kind'] ?? '') === 'proposed' && !empty($e['accepted'])) return 'journalled as accepted';
    }
    return $seen ? true : 'the refusal left no trace';
};

// ── secrets ────────────────────────────────────────────────────────────────────────────────
$cases['the journal redacts anything that looks like a credential'] = function () {
    global $TMP, $JN;
    $p = $TMP . '/secret' . (++$JN) . '.ndjson';
    journal_write('executed', [
        'target' => 'guest-wifi',
        'password' => 'hunter2',
        'api_key' => 'short',
        'authorization' => 'Basic YWRtaW46YWRtaW4=',
        // a long opaque string under an innocent key: the shape of a leaked token, deliberately
        // not in any vendor's key format, so this repository trips nobody's secret scanner
        'detail' => 'NOTAREALKEY00000000000000000000notarealkey',
        'nested' => ['token' => 'abc', 'url' => 'https://10.0.0.1/rest/x'],
    ], ['journal' => $p]);
    $raw = (string)file_get_contents($p);
    foreach (['hunter2', 'YWRtaW46YWRtaW4', 'NOTAREALKEY00000', '"abc"'] as $bad) {
        if (strpos($raw, $bad) !== false) return "the journal kept '$bad'";
    }
    if (strpos($raw, 'guest-wifi') === false) return 'redaction ate the ordinary fields too';
    if (strpos($raw, '10.0.0.1') === false) return 'redaction ate the url';
    return true;
};

// ── the example shipped in this repository must actually be executable ─────────────────────
$cases['the site file in config/ carries a real request for guest-wifi disable'] = function () {
    $site = json_decode((string)file_get_contents(__DIR__ . '/../config/site.example.json'), true);
    if (!is_array($site)) return 'config/site.example.json does not parse';
    $dev = null;
    foreach ($site['inventory'] as $d) if ($d['id'] === 'guest-wifi') $dev = $d;
    if (!$dev) return 'guest-wifi is not in the example site file';
    $spec = op_spec($dev, 'disable');
    if (!$spec || empty($spec['apply']['url'])) return 'no apply block on disable';
    if (op_worth($dev, 'disable') !== 120) return 'the declared worth did not survive the new shape';
    return true;
};

$cases['and the operation left without a request there is refused by name'] = function () {
    $site = json_decode((string)file_get_contents(__DIR__ . '/../config/site.example.json'), true);
    $dev = null;
    foreach ($site['inventory'] as $d) if ($d['id'] === 'media-server') $dev = $d;
    $spec = op_spec($dev, 'set_power_profile');
    if (!$spec) return 'set_power_profile is not declared on media-server';
    if (!empty($spec['apply'])) return 'it has an apply block after all — the test case is stale';
    if (op_worth($dev, 'set_power_profile') !== 55) return 'its measured worth was lost';
    return true;
};


// ── run ────────────────────────────────────────────────────────────────────────────────────
$fail = 0; $n = 0;
echo "\n  executor — " . count($cases) . " cases\n\n";
foreach ($cases as $name => $fn) {
    $n++;
    $log = [];
    $r = $fn();
    $ok = ($r === true);
    printf("  %-4s %s\n", $ok ? ' ok ' : 'FAIL', $name);
    if (!$ok) { $fail++; echo '          ' . (is_string($r) ? $r : var_export($r, true)) . "\n"; }
}
foreach (glob($TMP . '/*.ndjson') ?: [] as $f) @unlink($f);
@rmdir($TMP);
echo "\n  " . ($n - $fail) . "/$n\n\n";
exit($fail === 0 ? 0 : 1);

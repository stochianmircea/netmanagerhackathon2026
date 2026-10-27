<?php
/**
 * compiler_test.php — the loop between the model and the brake, without a model.
 *
 * Every case here replaces the model with a function that returns exactly what a real one might:
 * good JSON, JSON wrapped in prose, an apology with no JSON at all, a plan aimed at a protected
 * device, a plan citing a fact that was never measured. What matters is not that the model is
 * wrong — it will be, sometimes — but that being wrong cannot reach the network.
 *
 * Run it:   php tests/compiler_test.php
 */

declare(strict_types=1);
require_once __DIR__ . '/../src/collector.php';
require_once __DIR__ . '/../src/compiler.php';

$SITE = json_decode((string)file_get_contents(__DIR__ . '/../config/site.example.json'), true);
$FIX  = json_decode((string)file_get_contents(__DIR__ . '/../fixtures/sample-readings.json'), true);
$STATE = build_state($FIX['readings'], $SITE, '2026-10-27T11:00:00+02:00');
$POLICY = policy_lines(__DIR__ . '/../policy/example-policy.md');

$fail = 0; $n = 0;
function check(string $name, bool $ok, string $detail = '')
{
    global $fail, $n;
    $n++;
    printf("  %-4s %s\n", $ok ? ' ok ' : 'FAIL', $name);
    if (!$ok) { $fail++; if ($detail !== '') echo "          $detail\n"; }
}

/** a model that always says the same thing */
function fixed(string $text): callable
{
    return function (string $prompt, array $opt) use ($text) { return $text; };
}

/** a model that says one thing, then another — to test the correction round */
function then(string $first, string $second): callable
{
    $n = 0;
    return function (string $prompt, array $opt) use (&$n, $first, $second) {
        $n++;
        return $n === 1 ? $first : $second;
    };
}

function plan_json(array $actions, string $state_id, string $reasoning = ''): string
{
    return json_encode([
        'schema_version' => 1, 'state_ref' => $state_id,
        'reasoning' => $reasoning ?: 'Battery at 28% and discharging with the sun still low. '
                                   . 'Guest Wi-Fi is sacrificial under the policy, so it goes first.',
        'actions' => $actions,
    ]);
}

$good = [[
    'target' => 'guest-wifi', 'operation' => 'disable', 'expected_saving_w' => 120,
    'because' => ['policy_line' => 2, 'evidence' => ['battery.soc', 'autonomy.hours']],
]];

echo "\n  compiler — " . "the model proposes, the code decides\n\n";

// ── the prompt itself ────────────────────────────────────────────────────────────────────
$prompt = build_prompt($STATE, $POLICY);
check('the prompt carries the policy as the human wrote it',
    str_contains($prompt, 'Guest Wi-Fi is sacrificial'));
check('the prompt lists only measured facts, by id',
    str_contains($prompt, 'battery.soc') && str_contains($prompt, 'autonomy.hours'));
check('the prompt marks the protected devices',
    str_contains($prompt, 'gate-camera') && str_contains($prompt, '[NEVER TOUCH]'));
check('the prompt binds the plan to this exact state',
    substr_count($prompt, $STATE['state_id']) >= 2);
check('the prompt says doing nothing is allowed',
    str_contains($prompt, 'empty actions list is a valid answer'));

// ── the ordinary case ────────────────────────────────────────────────────────────────────
$r = compile_plan($STATE, $POLICY, [], fixed(plan_json($good, $STATE['state_id'])));
check('a sound plan is accepted on the first round',
    $r['ok'] && count($r['rounds']) === 1 && count($r['plan']['actions']) === 1);

$r = compile_plan($STATE, $POLICY, [], fixed(plan_json([], $STATE['state_id'],
    'Battery is low but the sun is rising and the load is modest. Nothing needs cutting yet.')));
check('a plan with no actions is accepted — not treated as a failure',
    $r['ok'] && $r['plan']['actions'] === []);

// ── models being models ──────────────────────────────────────────────────────────────────
$r = compile_plan($STATE, $POLICY, [], fixed(
    "Sure! Here is the plan you asked for:\n```json\n" . plan_json($good, $STATE['state_id']) . "\n```\nHope this helps!"));
check('JSON wrapped in prose and fences is still read', $r['ok']);

$r = compile_plan($STATE, $POLICY, [], fixed(
    "I'm sorry, I can't help with switching off equipment without more information."));
check('a reply with no JSON at all is a rejection, not a crash',
    !$r['ok'] && in_array('MODEL', array_column($r['rejections'], 'code'), true));

$r = compile_plan($STATE, $POLICY, [], fixed('{"schema_version":1,"state_ref":"' . $STATE['state_id']
    . '","reasoning":"x","actions":[]}'));
check('a plan whose reasoning is too short to read is refused',
    !$r['ok'] && in_array('SHAPE', array_column($r['rejections'], 'code'), true));

// ── the brake, on plausible-sounding plans ───────────────────────────────────────────────
$r = compile_plan($STATE, $POLICY, [], fixed(plan_json([[
    'target' => 'gate-camera', 'operation' => 'disable', 'expected_saving_w' => 9,
    'because' => ['policy_line' => 3, 'evidence' => ['battery.soc']],
]], $STATE['state_id'])));
check('a protected device is refused even though the prompt told the model not to',
    !$r['ok'] && in_array('PROTECTION', array_column($r['rejections'], 'code'), true));

$r = compile_plan($STATE, $POLICY, [], fixed(plan_json([[
    'target' => 'garage-heater', 'operation' => 'disable', 'expected_saving_w' => 1200,
    'because' => ['policy_line' => 3, 'evidence' => ['battery.soc']],
]], $STATE['state_id'])));
check('a device the model invented is refused',
    !$r['ok'] && in_array('EXISTENCE', array_column($r['rejections'], 'code'), true));

$r = compile_plan($STATE, $POLICY, [], fixed(plan_json([[
    'target' => 'guest-wifi', 'operation' => 'disable', 'expected_saving_w' => 120,
    'because' => ['policy_line' => 2, 'evidence' => ['battery.temperature']],
]], $STATE['state_id'])));
check('evidence that was never measured is refused',
    !$r['ok'] && in_array('GROUNDING', array_column($r['rejections'], 'code'), true));

$r = compile_plan($STATE, $POLICY, [], fixed(plan_json([[
    'target' => 'guest-wifi', 'operation' => 'disable', 'expected_saving_w' => 1500,
    'because' => ['policy_line' => 2, 'evidence' => ['battery.soc']],
]], $STATE['state_id'])));
check('a saving the device cannot possibly make is refused',
    !$r['ok'] && in_array('ARITHMETIC', array_column($r['rejections'], 'code'), true));

// ── the correction round ─────────────────────────────────────────────────────────────────
$bad = plan_json([[
    'target' => 'garage-heater', 'operation' => 'disable', 'expected_saving_w' => 1200,
    'because' => ['policy_line' => 3, 'evidence' => ['battery.soc']],
]], $STATE['state_id']);
$r = compile_plan($STATE, $POLICY, [], then($bad, plan_json($good, $STATE['state_id'])));
check('a rejected plan is handed back and the corrected one is accepted',
    $r['ok'] && count($r['rounds']) === 2
    && $r['rounds'][0]['ok'] === false && $r['rounds'][1]['ok'] === true);

$r = compile_plan($STATE, $POLICY, [], fixed($bad));
check('a model that keeps getting it wrong is stopped after one correction',
    !$r['ok'] && count($r['rounds']) === 2);

// the correction has to actually say what was wrong, or it is just a retry
$seen = '';
$r = compile_plan($STATE, $POLICY, [], function (string $prompt, array $opt) use (&$seen, $bad, $good, $STATE) {
    static $k = 0; $k++;
    if ($k === 2) $seen = $prompt;
    return $k === 1 ? $bad : plan_json($good, $STATE['state_id']);
});
check('the second prompt tells the model exactly why it was refused',
    str_contains($seen, 'YOUR PREVIOUS PLAN WAS REJECTED')
    && str_contains($seen, 'EXISTENCE')
    && str_contains($seen, 'garage-heater'));

// ── the model being unreachable ──────────────────────────────────────────────────────────
$r = compile_plan($STATE, $POLICY, [], function () { throw new RuntimeException('connection refused'); });
check('an unreachable model is reported, not papered over',
    !$r['ok'] && str_contains(json_encode($r['rejections']), 'connection refused'));

echo "\n  " . ($n - $fail) . "/$n\n\n";
exit($fail === 0 ? 0 : 1);

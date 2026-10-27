<?php
/**
 * validator_test.php — proof that the brake holds.
 *
 * Every case here is a plan a language model could plausibly produce. Most of them look
 * reasonable: well-formed JSON, confident prose, a sensible-sounding device, a round number of
 * watts. That is exactly the problem — fluency is not truth, and none of these could be caught
 * by reading the plan.
 *
 * Run it:   php tests/validator_test.php
 * Exit code 0 only if every case behaves as stated.
 */

declare(strict_types=1);
require_once __DIR__ . '/../src/validator.php';

$STATE  = json_decode((string)file_get_contents(__DIR__ . '/../fixtures/sample-state.json'), true);
$POLICY = policy_line_count(__DIR__ . '/../policy/example-policy.md');

/** a plan that should pass, which the bad ones are built from by changing one thing */
function good_plan(array $change = [], ?array $actions = null): array
{
    $p = [
        'schema_version' => 1,
        'state_ref'      => 's-20261027T0900Z',
        'reasoning'      => 'Battery at 28% with no grid and 2.1 kW leaving the battery. The policy '
                          . 'calls guest Wi-Fi sacrificial and says to cut first what consumes a lot '
                          . 'and matters little, so guest Wi-Fi and the lab switch go.',
        'actions'        => $actions ?? [
            [
                'target' => 'guest-wifi', 'operation' => 'disable', 'expected_saving_w' => 120,
                'because' => ['policy_line' => 2, 'evidence' => ['battery.soc', 'grid.available']],
            ],
            [
                'target' => 'lab-switch', 'operation' => 'disable', 'expected_saving_w' => 210,
                'because' => ['policy_line' => 3, 'evidence' => ['battery.soc', 'battery.power_w']],
            ],
        ],
    ];
    foreach ($change as $k => $v) {
        if ($v === '__unset__') unset($p[$k]); else $p[$k] = $v;
    }
    return $p;
}

function act(array $change = []): array
{
    return array_merge([
        'target' => 'guest-wifi', 'operation' => 'disable', 'expected_saving_w' => 100,
        'because' => ['policy_line' => 2, 'evidence' => ['battery.soc']],
    ], $change);
}

$cases = [

    ['a sound plan is accepted',
     good_plan(), true, null],

    ['doing nothing is a legitimate plan',
     good_plan(['reasoning' => 'Battery at 28% but the sun is coming up and load is modest. '
                             . 'Nothing needs to be cut yet.'], []), true, null],

    // ── the model names a device that does not exist ────────────────────────────────────
    ['a device invented out of thin air is refused',
     good_plan([], [act(['target' => 'garage-heater'])]), false, 'EXISTENCE'],

    // ── the never-touch list ────────────────────────────────────────────────────────────
    ['the gate camera cannot be switched off, however well argued',
     good_plan([], [act(['target' => 'gate-camera', 'expected_saving_w' => 9])]), false, 'PROTECTION'],

    ['neither can the critical workstation',
     good_plan([], [act(['target' => 'critical-station', 'expected_saving_w' => 140])]), false, 'PROTECTION'],

    ['one protected target poisons the whole plan — nothing is applied',
     good_plan([], [
         act(['target' => 'guest-wifi']),
         act(['target' => 'gate-controller', 'expected_saving_w' => 4]),
     ]), false, 'PROTECTION'],

    // ── the operation vocabulary ────────────────────────────────────────────────────────
    ['an operation the executor does not have is refused',
     good_plan([], [act(['operation' => 'reboot'])]), false, 'OPERATION'],

    ['disable takes no argument',
     good_plan([], [act(['argument' => 30])]), false, 'OPERATION'],

    ['limit_bandwidth without a number is refused',
     good_plan([], [act(['operation' => 'limit_bandwidth', 'argument' => null])]), false, 'OPERATION'],

    ['limit_bandwidth with a number is fine',
     good_plan([], [act(['operation' => 'limit_bandwidth', 'argument' => 2048,
                         'expected_saving_w' => 40])]), true, null],

    ['a power profile outside the list is refused',
     good_plan([], [act(['operation' => 'set_power_profile', 'argument' => 'turbo',
                         'expected_saving_w' => 0])]), false, 'OPERATION'],

    // ── grounding: evidence that is not there ───────────────────────────────────────────
    ['evidence that does not exist in the state is refused',
     good_plan([], [act(['because' => ['policy_line' => 2, 'evidence' => ['battery.temperature']]])]),
     false, 'GROUNDING'],

    ['an action with no evidence at all is refused',
     good_plan([], [act(['because' => ['policy_line' => 2, 'evidence' => []]])]), false, 'GROUNDING'],

    ['a policy line that the policy does not have is refused',
     good_plan([], [act(['because' => ['policy_line' => 99, 'evidence' => ['battery.soc']]])]),
     false, 'GROUNDING'],

    // ── arithmetic: numbers that cannot come from this device ───────────────────────────
    ['a saving larger than the device can draw is refused',
     good_plan([], [act(['expected_saving_w' => 3000])]), false, 'ARITHMETIC'],

    ['turning something ON cannot save power',
     good_plan([], [act(['operation' => 'enable', 'expected_saving_w' => 80])]), false, 'ARITHMETIC'],

    // ── freshness ───────────────────────────────────────────────────────────────────────
    ['a plan argued on an older reading is refused',
     good_plan(['state_ref' => 's-20261026T2200Z']), false, 'FRESHNESS'],

    // ── shape ───────────────────────────────────────────────────────────────────────────
    ['a field the schema does not allow is refused',
     good_plan(['confidence' => 0.97]), false, 'SHAPE'],

    ['a missing field is refused',
     good_plan(['reasoning' => '__unset__']), false, 'SHAPE'],

    ['reasoning too short to be read before approval is refused',
     good_plan(['reasoning' => 'ok']), false, 'SHAPE'],

    ['the wrong schema version is refused rather than guessed at',
     good_plan(['schema_version' => 2]), false, 'SHAPE'],

    // ── one device, one decision ────────────────────────────────────────────────────────
    ['acting twice on the same device is refused',
     good_plan([], [act(), act(['operation' => 'enable', 'expected_saving_w' => 0])]), false, 'CONFLICT'],
];

$fail = 0; $n = 0;
echo "\n  validator — " . count($cases) . " cases\n\n";
foreach ($cases as [$name, $plan, $want_ok, $want_code]) {
    $n++;
    $r = validate_plan($plan, $STATE, $POLICY);
    $ok = ($r['ok'] === $want_ok);
    $codes = array_values(array_unique(array_column($r['rejections'], 'code')));
    if ($ok && $want_code !== null) $ok = in_array($want_code, $codes, true);
    if ($ok && $want_ok) $ok = ($r['rejections'] === []);

    printf("  %-4s %-62s %s\n", $ok ? ' ok ' : 'FAIL', $name,
        $want_ok ? 'accepted' : ($want_code . ($codes ? '' : ' (none raised!)')));
    if (!$ok) {
        $fail++;
        foreach ($r['rejections'] as $x) {
            echo '          ' . $x['code'] . '  ' . ($x['where'] ? $x['where'] . ': ' : '') . $x['why'] . "\n";
        }
        if ($r['ok']) echo "          (the plan was ACCEPTED and should not have been)\n";
    }
}
echo "\n  " . ($n - $fail) . "/$n\n\n";
exit($fail === 0 ? 0 : 1);

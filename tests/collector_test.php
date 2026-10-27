<?php
/**
 * collector_test.php — the state has to be honest, or the brake is decorative.
 *
 * The validator refuses an action that cites evidence not present in the state. That is only
 * worth something if the state cannot quietly contain things nobody measured. These cases check
 * the places where a collector is tempted to be helpful: filling in a missing reading, keeping
 * yesterday's number when a device goes quiet, computing a figure that has no meaning yet.
 *
 * The last case runs the whole chain — readings, state, plan, validator — because that is the
 * only test that proves the two files agree about what a fact id is.
 *
 * Run it:   php tests/collector_test.php
 */

declare(strict_types=1);
require_once __DIR__ . '/../src/collector.php';
require_once __DIR__ . '/../src/validator.php';

$SITE = json_decode((string)file_get_contents(__DIR__ . '/../config/site.example.json'), true);
$FIX  = json_decode((string)file_get_contents(__DIR__ . '/../fixtures/sample-readings.json'), true);

$fail = 0; $n = 0;
function check(string $name, bool $ok, string $detail = '')
{
    global $fail, $n;
    $n++;
    printf("  %-4s %s\n", $ok ? ' ok ' : 'FAIL', $name);
    if (!$ok) { $fail++; if ($detail !== '') echo "          $detail\n"; }
}

echo "\n  collector\n\n";

// ── the ordinary case ────────────────────────────────────────────────────────────────────
$st = build_state($FIX['readings'], $SITE, '2026-10-27T11:00:00+02:00');

check('every fact carries a time', (function () use ($st) {
    foreach ($st['facts'] as $f) if (empty($f['at'])) return false;
    return true;
})());

check('every fact carries a source or says what it was derived from', (function () use ($st) {
    foreach ($st['facts'] as $f) if (empty($f['source']) && empty($f['derived_from'])) return false;
    return true;
})());

check('the battery facts are there, with the sign convention stated',
    isset($st['facts']['battery.soc'], $st['facts']['battery.power_w'])
    && str_contains((string)($st['facts']['battery.power_w']['note'] ?? ''), 'discharging'));

check('production is the sum of the inverters',
    ($st['facts']['pv.power_w']['value'] ?? null) === 410,
    'got ' . json_encode($st['facts']['pv.power_w']['value'] ?? null));

check('the never-touch list merges the inventory flags and the explicit list',
    in_array('gate-camera', $st['protected'], true)
    && in_array('critical-station', $st['protected'], true)
    && !in_array('guest-wifi', $st['protected'], true));

check('autonomy is computed while discharging',
    isset($st['facts']['autonomy.hours'])
    && $st['facts']['autonomy.hours']['value'] > 3
    && $st['facts']['autonomy.hours']['value'] < 6,
    'got ' . json_encode($st['facts']['autonomy.hours']['value'] ?? null));

check('autonomy says what it was derived from, instead of claiming a source',
    empty($st['facts']['autonomy.hours']['source'])
    && count($st['facts']['autonomy.hours']['derived_from'] ?? []) === 3);

// ── the places a collector is tempted to be helpful ──────────────────────────────────────
$charging = $FIX['readings'];
$charging[0]['batt_w'] = -800;                     // charging
$st2 = build_state($charging, $SITE, '2026-10-27T11:00:00+02:00');
check('while charging there is no autonomy fact at all — not a zero',
    !isset($st2['facts']['autonomy.hours']));

$quiet = $FIX['readings'];
$quiet[0]['responding'] = false;
$st3 = build_state($quiet, $SITE, '2026-10-27T11:00:00+02:00');
check('a reading with no value produces no fact',
    !isset($st3['facts']['inverter-main.battery_volts'])
    || $st3['facts']['inverter-main.battery_volts']['value'] !== null);

$partial = [['device' => 'inverter-main', 'at' => '2026-10-27T11:00:00+02:00',
             'source' => 'inverter', 'responding' => false]];
$st4 = build_state($partial, $SITE, '2026-10-27T11:00:00+02:00');
check('a silent device contributes its silence, not stale numbers',
    isset($st4['facts']['inverter-main.responding'])
    && $st4['facts']['inverter-main.responding']['value'] === false
    && !isset($st4['facts']['battery.soc'])
    && !isset($st4['facts']['autonomy.hours']));

$no_cap = $SITE; unset($no_cap['battery_capacity_kwh']);
$st5 = build_state($FIX['readings'], $no_cap, '2026-10-27T11:00:00+02:00');
check('without a declared battery capacity, no autonomy is invented',
    !isset($st5['facts']['autonomy.hours'])
    && !isset($st5['facts']['battery.capacity_kwh']));

check('the state id changes when the facts change',
    $st['state_id'] !== $st2['state_id']);

// ── the whole chain ──────────────────────────────────────────────────────────────────────
echo "\n  collector + validator, end to end\n\n";

$plan = [
    'schema_version' => 1,
    'state_ref'      => $st['state_id'],
    'reasoning'      => 'Battery at 28% and discharging at 2.1 kW with the sun still low. Guest '
                      . 'Wi-Fi is sacrificial under the policy and draws 120 W, so it goes first.',
    'actions'        => [[
        'target' => 'guest-wifi', 'operation' => 'disable', 'expected_saving_w' => 120,
        'because' => ['policy_line' => 2, 'evidence' => ['battery.soc', 'autonomy.hours']],
    ]],
];
$r = validate_plan($plan, $st, policy_line_count(__DIR__ . '/../policy/example-policy.md'));
check('a plan citing fact ids the collector produced is accepted', $r['ok'],
    json_encode(array_column($r['rejections'], 'why')));

$plan2 = $plan;
$plan2['actions'][0]['because']['evidence'] = ['battery.temperature'];
$r2 = validate_plan($plan2, $st, 4);
check('the same plan citing a fact the collector never produced is refused',
    !$r2['ok'] && in_array('GROUNDING', array_column($r2['rejections'], 'code'), true));

$plan3 = $plan;
$plan3['actions'][0]['target'] = 'gate-camera';
$plan3['actions'][0]['expected_saving_w'] = 9;
$r3 = validate_plan($plan3, $st, 4);
check('and a plan aimed at a device the owner declared protected is refused',
    !$r3['ok'] && in_array('PROTECTION', array_column($r3['rejections'], 'code'), true));

echo "\n  " . ($n - $fail) . "/$n\n\n";
exit($fail === 0 ? 0 : 1);

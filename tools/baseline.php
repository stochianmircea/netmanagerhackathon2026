<?php
/**
 * tools/baseline.php — the rule this project has to beat, written honestly.
 *
 * This is NOT a straw man. It is what a competent administrator writes when there is no
 * model: a threshold, a hand-written order of what may be cut, and a hand-written list of
 * what must never be touched. It is deterministic, it is fast, it costs nothing to run, and
 * on most days it does the right thing. Any comparison that pretends otherwise is worthless.
 *
 * What it cannot do is the whole point of the project: it cannot read a policy. The three
 * blocks below are the knowledge the model takes from `policy/example-policy.md` instead —
 * and they are per site. Every new installation means writing another block, by hand, and
 * keeping it in step with reality. Count them: that is the number the comparison reports.
 *
 *     php tools/baseline.php --state=fixtures/bench/states/noc__s04-below-30.json
 *     php tools/baseline.php --state=... --json
 */

declare(strict_types=1);

/* ─────────────────────────────────────────────────────────────────────────────
 * PER-SITE KNOWLEDGE — hand-written. This is what the model does not need.
 * The markers BEGIN/END are counted by tools/bench.php; do not rename them.
 * ───────────────────────────────────────────────────────────────────────────── */

/* BEGIN SITE RULES */
const SITE_RULES = [

    'noc' => [
        'threshold_soc'   => 30,
        'target_cut_frac' => 0.15,
        'cut_order'       => ['guest-wifi', 'media-server', 'lab-switch', 'office-wifi'],
        'never'           => ['gate-camera', 'gate-controller', 'core-switch', 'critical-station'],
    ],

    'office' => [
        'threshold_soc'   => 30,
        'target_cut_frac' => 0.15,
        'cut_order'       => ['signage', 'coffee-machine', 'print-room', 'desk-wifi'],
        'never'           => ['badge-reader', 'fire-panel', 'floor-switch'],
    ],

    'workshop' => [
        'threshold_soc'   => 30,
        'target_cut_frac' => 0.15,
        'cut_order'       => ['charger-bank', 'dust-extractor', 'shop-wifi', 'cnc'],
        'never'           => ['alarm', 'gate-camera', 'core-switch'],
    ],

];
/* END SITE RULES */

/**
 * The rule itself. Deliberately simple, because that is its honest form:
 * grid present or battery above the threshold → do nothing.
 * Otherwise cut down the hand-written list until the target reduction is covered.
 */
function baseline_plan(array $state): array
{
    $sit = (string)($state['site'] ?? '');
    $reg = SITE_RULES[$sit] ?? null;
    if ($reg === null) {
        return ['schema_version' => 1, 'state_ref' => (string)($state['state_id'] ?? ''),
                'reasoning' => 'no rule block written for site "' . $sit . '" — this is exactly the cost being measured',
                'actions' => [], 'baseline_note' => 'NO RULE FOR THIS SITE'];
    }

    $f    = $state['facts'] ?? [];
    $soc  = (float)($f['battery.soc']['value'] ?? 100);
    $grid = (bool)($f['grid.available']['value'] ?? true);
    $load = (float)($f['load.power_w']['value'] ?? 0);

    if ($grid) {
        return ['schema_version' => 1, 'state_ref' => (string)($state['state_id'] ?? ''),
                'reasoning' => 'grid is available — the rule does nothing', 'actions' => []];
    }
    if ($soc >= $reg['threshold_soc']) {
        return ['schema_version' => 1, 'state_ref' => (string)($state['state_id'] ?? ''),
                'reasoning' => 'battery ' . $soc . '% is at or above the ' . $reg['threshold_soc'] . '% threshold',
                'actions' => []];
    }

    $tinta = $load * (float)$reg['target_cut_frac'];
    $index = [];
    foreach (($state['inventory'] ?? []) as $x) $index[(string)$x['id']] = $x;

    $actiuni = [];
    $strans  = 0.0;
    foreach ($reg['cut_order'] as $id) {
        if ($strans >= $tinta) break;
        if (in_array($id, $reg['never'], true)) continue;        /* paza, scrisa tot de mana */
        $dev = $index[$id] ?? null;
        if ($dev === null) continue;                             /* dispozitiv disparut din sit */
        $ops = (array)($dev['operations'] ?? []);
        if (isset($ops['disable']))              { $op = 'disable';         $w = (float)$ops['disable']; }
        elseif (isset($ops['set_power_profile'])){ $op = 'set_power_profile'; $w = (float)$ops['set_power_profile']; }
        elseif (isset($ops['limit_bandwidth']))  { $op = 'limit_bandwidth'; $w = (float)$ops['limit_bandwidth']; }
        else continue;
        $actiuni[] = ['target' => $id, 'operation' => $op, 'expected_saving_w' => $w,
                      'because' => ['rule' => 'cut_order position ' . (count($actiuni) + 1)]];
        $strans += $w;
    }

    return ['schema_version' => 1,
            'state_ref' => (string)($state['state_id'] ?? ''),
            'reasoning' => 'battery ' . $soc . '% below ' . $reg['threshold_soc'] . '% and no grid; '
                         . 'cut down the written order until ' . round($tinta) . ' W was covered',
            'actions' => $actiuni];
}

if (PHP_SAPI === 'cli' && isset($argv) && realpath($argv[0]) === realpath(__FILE__)) {
    $cale = ''; $json = false;
    foreach (array_slice($argv, 1) as $a) {
        if (strncmp($a, '--state=', 8) === 0) $cale = substr($a, 8);
        elseif ($a === '--json') $json = true;
    }
    if ($cale === '' || !is_file($cale)) {
        fwrite(STDERR, "usage: php tools/baseline.php --state=fixtures/bench/states/<case>.json [--json]\n");
        exit(2);
    }
    $st = json_decode((string)file_get_contents($cale), true);
    if (!is_array($st)) { fwrite(STDERR, "state is not valid JSON\n"); exit(2); }
    $p = baseline_plan($st);
    if ($json) { echo json_encode($p, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n"; exit(0); }
    echo "  ", $p['reasoning'], "\n";
    foreach ($p['actions'] as $a) printf("    %-16s %-18s %4d W\n", $a['target'], $a['operation'], $a['expected_saving_w']);
    if (!$p['actions']) echo "    (no actions)\n";
    exit(0);
}

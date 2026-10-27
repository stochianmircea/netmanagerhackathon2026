<?php
/**
 * collector.php — turns a live installation into a state the model is allowed to see.
 *
 * The validator refuses any action that cites evidence which is not in the state. That rule is
 * only worth something if the state is honest, so this file has one job: produce facts that are
 * MEASURED, each with an id, a source and a time — and nothing else.
 *
 * Two things live here that look similar and are not:
 *
 *   FACTS come from hardware. Battery charge, power in and out, what the meter reads. They are
 *   whatever the inverter said a moment ago. Nobody types them.
 *
 *   The INVENTORY and the NEVER-TOUCH LIST come from the site owner, in a config file they write
 *   and can read. They are declarations, not measurements: which devices exist, what each one
 *   draws, and which of them must stay alive. The model never sees them as negotiable, and the
 *   validator enforces the never-touch list in code.
 *
 * Keeping those two apart is the whole safety argument. A model cannot invent a device, because
 * devices come from the owner's file. A model cannot invent a measurement, because every claim
 * must resolve to a fact id that came off the wire.
 *
 *   php src/collector.php --site=config/site.example.json --fixture=fixtures/sample-readings.json
 *   php src/collector.php --site=config/site.json --live
 *
 * --live reads the platform's own tables through nothing but SELECTs. No platform code is
 * included in this repository; this is a reader standing outside it.
 */

declare(strict_types=1);

const COLLECTOR_VERSION = 1;

/**
 * @param array $readings  one row per device, as measured
 * @param array $site      the owner's declaration: inventory, protected, battery capacity
 */
function build_state(array $readings, array $site, ?string $now = null): array
{
    $now = $now ?: date('c');
    $facts = [];

    $add = function (string $id, $value, string $unit, string $source, ?string $at = null,
                     ?array $derived_from = null, ?string $note = null) use (&$facts, $now) {
        if ($value === null) return;                 // a missing reading is not a fact
        $f = ['value' => $value, 'unit' => $unit, 'source' => $source, 'at' => $at ?: $now];
        if ($derived_from) { $f['derived_from'] = $derived_from; unset($f['source']); }
        if ($note) $f['note'] = $note;
        $facts[$id] = $f;
    };

    $pv_total = null; $load = null; $grid = null; $soc = null; $batt_w = null;

    foreach ($readings as $r) {
        $id   = (string)($r['device'] ?? '');
        $at   = (string)($r['at'] ?? $now);
        $src  = (string)($r['source'] ?? 'inverter');
        if ($id === '') continue;

        if (isset($r['soc']))     { $add("$id.soc", (int)$r['soc'], '%', $src, $at); $soc = (int)$r['soc']; }
        if (isset($r['batt_w']))  { $add("$id.battery_power_w", (int)$r['batt_w'], 'W', $src, $at, null,
                                         'positive = discharging'); $batt_w = (int)$r['batt_w']; }
        if (isset($r['batt_v']))    $add("$id.battery_volts", (float)$r['batt_v'], 'V', $src, $at);
        if (isset($r['pv_w']))    { $add("$id.pv_power_w", (int)$r['pv_w'], 'W', $src, $at);
                                    $pv_total = (int)($pv_total ?? 0) + (int)$r['pv_w']; }
        if (isset($r['load_w']))  { $add("$id.load_power_w", (int)$r['load_w'], 'W', $src, $at);
                                    $load = (int)$r['load_w']; }
        if (isset($r['grid_w']))  { $add("$id.grid_power_w", (int)$r['grid_w'], 'W', $src, $at);
                                    $grid = (int)$r['grid_w']; }
        if (isset($r['day_pv']))    $add("$id.pv_today_kwh", (float)$r['day_pv'], 'kWh', $src, $at);
        if (isset($r['total_pv']))  $add("$id.pv_total_kwh", (float)$r['total_pv'], 'kWh', $src, $at);
        if (isset($r['imp_kwh']))   $add("$id.grid_imported_kwh", (float)$r['imp_kwh'], 'kWh', $src, $at);
        if (isset($r['exp_kwh']))   $add("$id.grid_exported_kwh", (float)$r['exp_kwh'], 'kWh', $src, $at);
        foreach (['a', 'b', 'c'] as $ph) {
            if (isset($r['v' . $ph])) $add("$id.phase_$ph.volts", (float)$r['v' . $ph], 'V', $src, $at);
            if (isset($r['p' . $ph])) $add("$id.phase_$ph.watts", (int)$r['p' . $ph], 'W', $src, $at);
        }
        if (array_key_exists('responding', $r)) {
            $add("$id.responding", (bool)$r['responding'], 'bool', $src, $at);
        }
    }

    // ── site-wide facts, each one derived from named facts above ─────────────────────────
    if ($pv_total !== null) $add('pv.power_w', $pv_total, 'W', 'sum of inverters');
    if ($load !== null)     $add('load.power_w', $load, 'W', 'inverter');
    if ($soc !== null)      $add('battery.soc', $soc, '%', 'inverter');
    if ($batt_w !== null)   $add('battery.power_w', $batt_w, 'W', 'inverter', null, null,
                                'positive = discharging');

    $cap = $site['battery_capacity_kwh'] ?? null;
    if ($cap !== null) {
        $add('battery.capacity_kwh', (float)$cap, 'kWh', 'site declaration');
    }

    // Autonomy: only when the battery is actually discharging and we know the capacity.
    // While charging there is no hours-remaining to state, so no fact is emitted — an absent
    // fact is honest; a zero would be a number the model could cite.
    if ($soc !== null && $batt_w !== null && $cap !== null && $batt_w > 0) {
        $hours = round(((float)$cap * ($soc / 100.0) * 1000.0) / $batt_w, 1);
        $add('autonomy.hours', $hours, 'h', '', null,
             ['battery.soc', 'battery.capacity_kwh', 'battery.power_w']);
    }

    // Grid availability: a declaration of fact needs a rule stated out loud. Here it is —
    // the grid counts as present when a device that watches it answered and reports flow.
    // If no such device answered, we say nothing rather than guess the grid is fine.
    if ($grid !== null) {
        $add('grid.available', true, 'bool', '', null, ['' . 'grid.power_w'],
             'the meter answered and reports flow');
        $add('grid.power_w', $grid, 'W', 'inverter', null, null, 'positive = importing');
    }

    $inventory = $site['inventory'] ?? [];
    $protected = [];
    foreach ($inventory as $d) {
        if (!empty($d['protected']) && isset($d['id'])) $protected[] = (string)$d['id'];
    }
    foreach (($site['protected'] ?? []) as $x) {
        if (!in_array((string)$x, $protected, true)) $protected[] = (string)$x;
    }

    return [
        'state_id'  => 's-' . gmdate('Ymd\THi\Z', strtotime($now)) . '-'
                       . substr(hash('sha256', json_encode($facts)), 0, 6),
        'taken_at'  => $now,
        'site'      => (string)($site['name'] ?? 'site'),
        'collector' => COLLECTOR_VERSION,
        'facts'     => $facts,
        'inventory' => $inventory,
        'protected' => $protected,
    ];
}


/** Readings straight off the platform's tables. SELECT only; nothing of it is included here. */
function readings_live(array $site): array
{
    $dsn = getenv('NM_DSN'); $usr = getenv('NM_DB_USER'); $pwd = getenv('NM_DB_PASS');
    if (!$dsn) { fwrite(STDERR, "set NM_DSN (and NM_DB_USER / NM_DB_PASS) to read live\n"); exit(2); }
    $pdo = new PDO($dsn, $usr ?: null, $pwd ?: null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    $map = [];                                   // platform device id -> inventory id
    foreach (($site['devices'] ?? []) as $k => $v) $map[(string)$k] = (string)$v;

    $out = [];
    $q = $pdo->query(
        "SELECT d.id, d.nume, m.ts, m.ok, m.soc, m.batt_w, m.batt_v, m.pv_w, m.load_w, m.grid_w,
                m.day_pv, m.total_pv, m.va, m.vb, m.vc, m.pa, m.pb, m.pc, m.imp_kwh, m.exp_kwh
         FROM nm_energie_dispozitive d
         JOIN nm_energie_masuri m ON m.id = (
             SELECT id FROM nm_energie_masuri WHERE dispozitiv_id = d.id ORDER BY ts DESC LIMIT 1)
         WHERE d.activ = 1");
    foreach ($q as $r) {
        $id = $map[(string)$r['id']] ?? ('device-' . $r['id']);
        $row = ['device' => $id, 'at' => date('c', strtotime((string)$r['ts'])),
                'source' => 'inverter', 'responding' => ((int)$r['ok'] === 1)];
        foreach (['soc', 'batt_w', 'batt_v', 'pv_w', 'load_w', 'grid_w', 'day_pv', 'total_pv',
                  'va', 'vb', 'vc', 'pa', 'pb', 'pc', 'imp_kwh', 'exp_kwh'] as $c) {
            if ($r[$c] !== null) $row[$c] = $r[$c];
        }
        // A device that did not answer contributes its silence, not stale numbers.
        if ((int)$r['ok'] !== 1) $row = ['device' => $id, 'at' => date('c', strtotime((string)$r['ts'])),
                                         'source' => 'inverter', 'responding' => false];
        $out[] = $row;
    }
    return $out;
}


// ── command line ─────────────────────────────────────────────────────────────────────────
if (PHP_SAPI === 'cli' && isset($argv) && realpath($argv[0]) === realpath(__FILE__)) {
    $opt = [];
    foreach (array_slice($argv, 1) as $x) {
        if (preg_match('/^--([a-z_]+)(?:=(.*))?$/', $x, $m)) $opt[$m[1]] = $m[2] ?? true;
    }
    $site_path = $opt['site'] ?? 'config/site.example.json';
    if (!is_file($site_path)) { fwrite(STDERR, "cannot read $site_path\n"); exit(2); }
    $site = json_decode((string)file_get_contents($site_path), true);
    if (!is_array($site)) { fwrite(STDERR, "$site_path is not valid JSON\n"); exit(2); }

    if (isset($opt['live'])) {
        $readings = readings_live($site);
    } elseif (isset($opt['fixture'])) {
        $f = json_decode((string)@file_get_contents((string)$opt['fixture']), true);
        if (!is_array($f)) { fwrite(STDERR, "cannot read fixture\n"); exit(2); }
        $readings = $f['readings'] ?? $f;
    } else {
        fwrite(STDERR, "usage: php src/collector.php --site=config/site.example.json "
                     . "[--fixture=fixtures/sample-readings.json | --live]\n");
        exit(2);
    }

    echo json_encode(build_state($readings, $site), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

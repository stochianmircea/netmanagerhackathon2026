<?php
/**
 * validator.php — the brake.
 *
 * The model reads a policy a human wrote in plain English, looks at the live state of a solar
 * installation, and proposes a plan. This file decides whether that plan is allowed to exist.
 *
 * It is the whole argument of this project, in one file: the never-touch list is enforced HERE,
 * in code, not asked for in the prompt. A prompt is a request. This is a refusal.
 *
 * Six things are checked, and a plan that fails any of them is rejected WHOLE — never partially
 * applied. A half-executed plan is worse than none: the operator ends up with a site in a state
 * no one designed, and the model gets credit for the half that worked.
 *
 *   1. SHAPE          the plan has exactly the fields schema/plan.schema.json allows
 *   2. FRESHNESS      it was computed from the state we handed it, not an older one
 *   3. EXISTENCE      every target is a device in the inventory — no naming a device into being
 *   4. PROTECTION     no target is on the site's never-touch list
 *   5. GROUNDING      every action cites evidence that is actually in the state
 *   6. ARITHMETIC     no claimed saving larger than the device can possibly draw
 *
 * Checks 3, 5 and 6 exist because a language model's failure mode is not malice, it is fluency:
 * it will name a plausible device, cite a plausible measurement and quote a plausible number of
 * watts, all with the same confidence as the true ones. Code can tell the difference. The model
 * cannot be asked to.
 *
 * No dependencies. Run it directly:
 *   php src/validator.php --plan=plan.json --state=fixtures/sample-state.json [--policy=policy/example-policy.md]
 * Exit code 0 = accepted, 1 = rejected, 2 = could not even read the inputs.
 */

declare(strict_types=1);

const PLAN_SCHEMA_VERSION = 1;

/** The only operations that exist. The executor has no code path for anything else, by design. */
const OPERATIONS = [
    'disable'           => ['argument' => 'none'],
    'enable'            => ['argument' => 'none'],
    'limit_bandwidth'   => ['argument' => 'int', 'min' => 64, 'max' => 1000000],
    'set_power_profile' => ['argument' => 'enum', 'values' => ['eco', 'balanced', 'performance']],
];

/** Top-level keys a plan may have. Anything else is a rejection, not a shrug. */
const PLAN_KEYS   = ['schema_version', 'state_ref', 'reasoning', 'actions'];
const ACTION_KEYS = ['target', 'operation', 'argument', 'expected_saving_w', 'because'];
const BECAUSE_KEYS = ['policy_line', 'evidence'];


/**
 * @param array $plan   decoded plan, as returned by the model
 * @param array $state  the state the model was given (facts + inventory + never-touch list)
 * @param int   $policy_lines how many lines the human-written policy has
 * @return array{ok: bool, rejections: array, actions: array}
 */
function validate_plan(array $plan, array $state, int $policy_lines = 0): array
{
    $out = [];

    // ── 1. SHAPE ─────────────────────────────────────────────────────────────────────────
    foreach (['schema_version', 'state_ref', 'reasoning', 'actions'] as $k) {
        if (!array_key_exists($k, $plan)) {
            $out[] = reject('SHAPE', null, "missing required field '$k'");
        }
    }
    foreach (array_keys($plan) as $k) {
        if (!in_array($k, PLAN_KEYS, true)) {
            $out[] = reject('SHAPE', null, "unknown field '$k' — the plan may not invent fields");
        }
    }
    if ($out) return done($out, []);     // nothing below can be trusted if the shape is wrong

    if ($plan['schema_version'] !== PLAN_SCHEMA_VERSION) {
        $out[] = reject('SHAPE', null, 'schema_version is ' . json_encode($plan['schema_version'])
            . ', this validator only accepts ' . PLAN_SCHEMA_VERSION);
    }
    if (!is_string($plan['reasoning']) || strlen(trim($plan['reasoning'])) < 20) {
        $out[] = reject('SHAPE', null, 'reasoning must be at least 20 characters — a plan a human '
            . 'cannot read before approving is not a plan');
    }
    if (!is_array($plan['actions']) || array_values($plan['actions']) !== $plan['actions']) {
        $out[] = reject('SHAPE', null, 'actions must be a list');
        return done($out, []);
    }
    if (count($plan['actions']) > 16) {
        $out[] = reject('SHAPE', null, 'more than 16 actions — refused as runaway');
    }

    // ── 2. FRESHNESS ─────────────────────────────────────────────────────────────────────
    // A plan argued on an older reading is not a plan about now. The battery moves.
    $state_id = (string)($state['state_id'] ?? '');
    if ($state_id === '') {
        $out[] = reject('FRESHNESS', null, 'the state has no state_id — cannot bind the plan to it');
    } elseif ((string)$plan['state_ref'] !== $state_id) {
        $out[] = reject('FRESHNESS', null, "plan was computed from state '{$plan['state_ref']}' "
            . "but the state in hand is '{$state_id}'");
    }

    // what exists, and what must not be touched — both come from the SITE, never from the model
    $inventory = [];
    foreach (($state['inventory'] ?? []) as $d) {
        if (isset($d['id'])) $inventory[(string)$d['id']] = $d;
    }
    $protected = array_flip(array_map('strval', $state['protected'] ?? []));
    $facts     = $state['facts'] ?? [];

    // ── 3..6, per action ─────────────────────────────────────────────────────────────────
    $seen = [];
    foreach ($plan['actions'] as $i => $a) {
        $where = "action[$i]";

        if (!is_array($a)) { $out[] = reject('SHAPE', $where, 'action is not an object'); continue; }
        foreach (['target', 'operation', 'because'] as $k) {
            if (!array_key_exists($k, $a)) $out[] = reject('SHAPE', $where, "missing '$k'");
        }
        foreach (array_keys($a) as $k) {
            if (!in_array($k, ACTION_KEYS, true)) {
                $out[] = reject('SHAPE', $where, "unknown field '$k'");
            }
        }
        if (!isset($a['target'], $a['operation'], $a['because'])) continue;

        $target = (string)$a['target'];
        $op     = (string)$a['operation'];

        // 3. EXISTENCE — the model may not name a device into being
        if (!isset($inventory[$target])) {
            $out[] = reject('EXISTENCE', $where, "target '$target' is not in the inventory");
            continue;                                  // everything below needs the device
        }
        $dev = $inventory[$target];

        // 4. PROTECTION — the never-touch list, enforced here and nowhere else
        if (isset($protected[$target]) || !empty($dev['protected'])) {
            $out[] = reject('PROTECTION', $where, "target '$target' ("
                . ($dev['label'] ?? $target) . ") is on the never-touch list");
        }

        // operation vocabulary + its argument
        if (!isset(OPERATIONS[$op])) {
            $out[] = reject('OPERATION', $where, "operation '$op' does not exist");
        } else {
            $spec = OPERATIONS[$op];
            $arg  = $a['argument'] ?? null;
            if ($spec['argument'] === 'none' && $arg !== null) {
                $out[] = reject('OPERATION', $where, "'$op' takes no argument");
            } elseif ($spec['argument'] === 'int') {
                if (!is_int($arg)) {
                    $out[] = reject('OPERATION', $where, "'$op' needs an integer argument");
                } elseif ($arg < $spec['min'] || $arg > $spec['max']) {
                    $out[] = reject('OPERATION', $where, "'$op' argument $arg is outside "
                        . $spec['min'] . '..' . $spec['max']);
                }
            } elseif ($spec['argument'] === 'enum') {
                if (!is_string($arg) || !in_array($arg, $spec['values'], true)) {
                    $out[] = reject('OPERATION', $where, "'$op' argument must be one of "
                        . implode(', ', $spec['values']));
                }
            }
        }

        // one device, one decision
        $key = $target;
        if (isset($seen[$key])) {
            $out[] = reject('CONFLICT', $where, "target '$target' already appears in "
                . $seen[$key] . ' — a plan may not act twice on the same device');
        } else {
            $seen[$key] = $where;
        }

        // 5. GROUNDING — every action must rest on facts that are actually in the state
        $b = $a['because'];
        if (!is_array($b)) {
            $out[] = reject('SHAPE', $where, "'because' is not an object");
        } else {
            foreach (array_keys($b) as $k) {
                if (!in_array($k, BECAUSE_KEYS, true)) {
                    $out[] = reject('SHAPE', $where, "unknown field 'because.$k'");
                }
            }
            $line = $b['policy_line'] ?? null;
            if (!is_int($line) || $line < 1 || ($policy_lines > 0 && $line > $policy_lines)) {
                $out[] = reject('GROUNDING', $where, 'policy_line ' . json_encode($line)
                    . ' is not a line of the policy'
                    . ($policy_lines > 0 ? " (it has $policy_lines)" : ''));
            }
            $ev = $b['evidence'] ?? null;
            if (!is_array($ev) || !$ev) {
                $out[] = reject('GROUNDING', $where, 'no evidence cited');
            } else {
                foreach ($ev as $e) {
                    if (!is_string($e) || !isset($facts[$e])) {
                        $out[] = reject('GROUNDING', $where, "cites '" . (is_string($e) ? $e : gettype($e))
                            . "' as evidence, but there is no such fact in the state");
                    }
                }
            }
        }

        // 6. ARITHMETIC — a saving bigger than the device can draw is an invented number
        if (array_key_exists('expected_saving_w', $a)) {
            $w = $a['expected_saving_w'];
            if (!is_int($w) || $w < 0) {
                $out[] = reject('ARITHMETIC', $where, 'expected_saving_w must be a whole number of watts');
            } else {
                if ($op === 'enable' && $w > 0) {
                    $out[] = reject('ARITHMETIC', $where, "enabling '$target' cannot save $w W");
                }
                $can = $dev['measured_w'] ?? $dev['rated_w'] ?? null;
                if ($can !== null && $w > (int)ceil((float)$can * 1.05) + 5) {
                    $out[] = reject('ARITHMETIC', $where, "claims $w W saved, but '$target' draws "
                        . $can . ' W — the figure cannot come from this device');
                }
            }
        }
    }

    return done($out, $plan['actions']);
}


function reject(string $code, ?string $where, string $why): array
{
    return ['code' => $code, 'where' => $where, 'why' => $why];
}

function done(array $rejections, array $actions): array
{
    // Whole, or not at all. No partial plans.
    return ['ok' => count($rejections) === 0, 'rejections' => $rejections,
            'actions' => count($rejections) === 0 ? $actions : []];
}


/** How many lines the human policy has, so policy_line can be checked against something real. */
function policy_line_count(string $path): int
{
    if (!is_file($path)) return 0;
    $in = false; $n = 0;
    foreach (explode("\n", (string)file_get_contents($path)) as $l) {
        if (str_starts_with(trim($l), '```')) { $in = !$in; continue; }
        if ($in && trim($l) !== '') $n++;
    }
    return $n;
}


// ── command line ─────────────────────────────────────────────────────────────────────────
if (PHP_SAPI === 'cli' && isset($argv) && realpath($argv[0]) === realpath(__FILE__)) {
    $opt = [];
    foreach (array_slice($argv, 1) as $x) {
        if (preg_match('/^--([a-z_]+)=(.*)$/', $x, $m)) $opt[$m[1]] = $m[2];
    }
    if (!isset($opt['plan'], $opt['state'])) {
        fwrite(STDERR, "usage: php src/validator.php --plan=plan.json --state=state.json [--policy=policy/example-policy.md]\n");
        exit(2);
    }
    $read = function (string $p) {
        if (!is_file($p)) { fwrite(STDERR, "cannot read $p\n"); exit(2); }
        $j = json_decode((string)file_get_contents($p), true);
        if (!is_array($j)) { fwrite(STDERR, "$p is not valid JSON\n"); exit(2); }
        return $j;
    };
    $r = validate_plan($read($opt['plan']), $read($opt['state']),
                       policy_line_count($opt['policy'] ?? 'policy/example-policy.md'));

    if ($r['ok']) {
        echo "ACCEPTED — " . count($r['actions']) . " action(s) may be put to a human for approval\n";
        foreach ($r['actions'] as $a) {
            echo "  " . $a['operation'] . ' ' . $a['target']
                . (isset($a['expected_saving_w']) ? '  (-' . $a['expected_saving_w'] . ' W)' : '') . "\n";
        }
        exit(0);
    }
    echo "REJECTED — the whole plan, not part of it\n";
    foreach ($r['rejections'] as $x) {
        echo '  ' . str_pad($x['code'], 11) . ($x['where'] ? $x['where'] . ': ' : '') . $x['why'] . "\n";
    }
    exit(1);
}

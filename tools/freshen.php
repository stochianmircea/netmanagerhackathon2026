<?php
/**
 * freshen.php — re-stamp a frozen fixture state as if it had just been read.
 *
 * FOR DEMONSTRATION ONLY. It exists because of a rule in src/executor.php: a plan argued on a
 * state older than five minutes is refused, since the battery has moved since. That rule is
 * correct and it must not have an exception, so the repository's frozen fixture — which is dated
 * and will only get older — cannot be executed as it stands.
 *
 * The honest way out is a visible tool that changes the timestamp, rather than a flag inside the
 * gate that switches the rule off. This file touches `taken_at` and nothing else: no fact, no
 * device, no state_id, so the plan still has to match the same state it always matched.
 *
 *   php tools/freshen.php fixtures/sample-state.json > /tmp/state.json
 */

declare(strict_types=1);

$in = $argv[1] ?? '';
if ($in === '' || !is_file($in)) {
    fwrite(STDERR, "usage: php tools/freshen.php <state.json> > fresh-state.json\n");
    exit(2);
}
$s = json_decode((string)file_get_contents($in), true);
if (!is_array($s)) { fwrite(STDERR, "$in is not valid JSON\n"); exit(2); }

$was = (string)($s['taken_at'] ?? '');
$s['taken_at'] = date('c');
$s['freshened'] = 'taken_at re-stamped by tools/freshen.php for a demonstration; it was ' . $was;

fwrite(STDERR, "  re-stamped $in: $was -> " . $s['taken_at'] . "\n");
echo json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

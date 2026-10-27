<?php
/**
 * run_all.php — everything that proves this works, in one command.
 *
 *   php tests/run_all.php
 *
 * Exit code 0 only if every suite passes. No hardware needed: the collector runs from the
 * anonymised fixture, so a judge can reproduce all of it on a laptop.
 */
declare(strict_types=1);
$suites = ['validator_test.php', 'collector_test.php', 'compiler_test.php'];
$fail = 0;
foreach ($suites as $s) {
    $out = []; $rc = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/' . $s) . ' 2>&1', $out, $rc);
    echo implode("\n", $out) . "\n";
    if ($rc !== 0) $fail++;
}
echo $fail === 0 ? "  ALL SUITES PASS\n\n" : "  $fail SUITE(S) FAILED\n\n";
exit($fail === 0 ? 0 : 1);

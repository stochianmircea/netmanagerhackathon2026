<?php
/**
 * journal.php — what was proposed, what was refused, what a human approved, what actually ran.
 *
 * An agent that acts on a network and keeps no record is not an agent, it is an incident waiting
 * for an explanation. Everything here is append-only and written as one JSON object per line, so
 * it can be read with `tail`, grepped, and shipped to an auditor without a tool.
 *
 * What goes in: the state id the decision was made on, the plan, the validator's verdict with
 * every rejection, who approved and when, and the result of each action separately — including
 * the ones that failed. A half-applied plan is the most important thing a journal ever has to
 * explain, so it is recorded action by action, not as one verdict.
 *
 * What never goes in: credentials, tokens, or the body of anything that carries them.
 */

declare(strict_types=1);

function journal_path(array $opt = []): string
{
    return (string)(($opt['journal'] ?? getenv('AGENT_JOURNAL')) ?: __DIR__ . '/../journal/agent.ndjson');
}


function journal_write(string $kind, array $data, array $opt = []): bool
{
    $line = json_encode(array_merge([
        'at'   => date('c'),
        'kind' => $kind,
    ], journal_scrub($data)), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $p = journal_path($opt);
    $dir = dirname($p);
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $fh = @fopen($p, 'ab');
    if (!$fh) return false;
    @flock($fh, LOCK_EX);
    $ok = fwrite($fh, $line . "\n") !== false;
    @flock($fh, LOCK_UN);
    fclose($fh);
    return $ok;
}


/** Nothing that looks like a secret reaches the journal, whatever the caller handed us. */
function journal_scrub($v)
{
    $rau = '/(pass|passwd|password|secret|token|api[_-]?key|authorization|cookie|private)/i';
    if (is_array($v)) {
        $out = [];
        foreach ($v as $k => $x) {
            $out[$k] = (is_string($k) && preg_match($rau, $k)) ? '[redacted]' : journal_scrub($x);
        }
        return $out;
    }
    if (is_string($v) && strlen($v) > 24 && preg_match('/^[A-Za-z0-9_\-]{24,}$/', $v)) {
        return '[redacted:' . strlen($v) . ' chars]';
    }
    return $v;
}


/**
 * The fingerprint an approval is bound to.
 *
 * It covers the actions and the state the plan was argued on — not the prose. Change a target,
 * an operation, an argument or a saving and the fingerprint changes, so an approval given for
 * one plan cannot carry over to another. Reasoning is deliberately excluded: a human approves
 * what will be DONE, and rewording the explanation must not invalidate that.
 */
function plan_fingerprint(array $plan): string
{
    $core = ['state_ref' => (string)($plan['state_ref'] ?? ''), 'actions' => []];
    foreach (($plan['actions'] ?? []) as $a) {
        $core['actions'][] = [
            'target'    => (string)($a['target'] ?? ''),
            'operation' => (string)($a['operation'] ?? ''),
            'argument'  => $a['argument'] ?? null,
            'saving'    => $a['expected_saving_w'] ?? null,
        ];
    }
    return substr(hash('sha256', json_encode($core)), 0, 16);
}


/** The last N entries, newest last. For `--show`, and for a human asking what happened. */
function journal_tail(int $n = 20, array $opt = []): array
{
    $p = journal_path($opt);
    if (!is_file($p)) return [];
    $lines = @file($p, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $out = [];
    foreach (array_slice($lines, -$n) as $l) {
        $j = json_decode($l, true);
        if (is_array($j)) $out[] = $j;
    }
    return $out;
}


// ── command line: read the journal ───────────────────────────────────────────────────────
if (PHP_SAPI === 'cli' && isset($argv) && realpath($argv[0]) === realpath(__FILE__)) {
    $n = 20;
    foreach (array_slice($argv, 1) as $x) {
        if (preg_match('/^--last=(\d+)$/', $x, $m)) $n = (int)$m[1];
    }
    foreach (journal_tail($n) as $e) {
        $line = '  ' . str_pad((string)($e['at'] ?? ''), 26) . str_pad((string)($e['kind'] ?? ''), 12);
        switch ($e['kind'] ?? '') {
            case 'proposed':
                $line .= ($e['accepted'] ? 'accepted' : 'REJECTED') . ' · '
                       . count($e['actions'] ?? []) . ' action(s) · ' . ($e['fingerprint'] ?? '');
                break;
            case 'approved':
                $line .= 'by ' . ($e['by'] ?? '?') . ' · ' . ($e['fingerprint'] ?? '');
                break;
            case 'executed':
                $line .= ($e['ok'] ? 'ok' : 'FAILED') . ' · ' . ($e['operation'] ?? '')
                       . ' ' . ($e['target'] ?? '') . ' · ' . ($e['detail'] ?? '');
                break;
            case 'refused':
                $line .= ($e['why'] ?? '');
                break;
        }
        echo $line . "\n";
    }
    exit(0);
}

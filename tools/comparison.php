<?php
/**
 * tools/comparison.php — scrie docs/comparison.md din masuratori, nu din memoria mea.
 *
 * DE CE EXISTA. Documentul de concurs are nevoie de cifre. O cifra pe care o bat eu la
 * tastatura dintr-un tabel de mai sus e o cifra care poate fi greșita in liniste, si
 * nimeni n-o mai prinde: arata la fel ca una adevarata. Deci nu le scriu. Le cer.
 *
 * Fiecare set de planuri e dat scorecardului cu --json, si documentul se compune din ce
 * raspunde el. Daca scorecardul refuza un set (provenienta amestecata), documentul NU se
 * scrie. Mai bine lipseste decat sa iasa frumos si fals.
 *
 *   php tools/comparison.php \
 *       --set="Llama 3.1 8B:fixtures/bench/plans-llama31-8b" \
 *       --set="Llama 3.3 70B:fixtures/bench/plans-model"
 *
 *   php tools/comparison.php --set=... --set=... --scrie     # scrie docs/comparison.md
 *
 * Fara --scrie, tipareste pe ecran si nu atinge nimic.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit("CLI only.\n"); }

$seturi = [];
$scrie  = in_array('--scrie', $argv, true);
$ieire  = 'docs/comparison.md';
foreach (array_slice($argv, 1) as $a) {
    if (strncmp($a, '--set=', 6) === 0) {
        $v = substr($a, 6);
        $i = strpos($v, ':');
        if ($i === false) { fwrite(STDERR, "  --set vrea \"Eticheta:cale\"\n"); exit(2); }
        $seturi[] = ['eticheta' => trim(substr($v, 0, $i)), 'dir' => rtrim(substr($v, $i + 1), '/')];
    } elseif (strncmp($a, '--ieire=', 8) === 0) {
        $ieire = substr($a, 8);
    }
}
if (!$seturi) {
    fwrite(STDERR, "usage: php tools/comparison.php --set=\"Label:fixtures/bench/plans-x\" [--set=...] [--scrie]\n");
    exit(2);
}

$sc = __DIR__ . '/scorecard.php';
if (!is_file($sc)) { fwrite(STDERR, "  nu gasesc " . $sc . "\n"); exit(1); }

/** Cere numerele scorecardului. Pe orice nereusita, mor — nu inventez. */
function cere(string $sc, string $dir, bool $oricum = false): array
{
    $cmd = 'php ' . escapeshellarg($sc) . ' --a=' . escapeshellarg($dir) . ' --json'
         . ($oricum ? ' --oricum' : '') . ' 2>&1';
    $o = []; $c = 0;
    exec($cmd, $o, $c);
    $txt = implode("\n", $o);
    $d = json_decode($txt, true);
    if (!is_array($d)) {
        fwrite(STDERR, "\n  STOP. scorecardul n-a dat JSON pentru " . $dir . ":\n"
            . substr($txt, 0, 600) . "\n\n");
        exit(1);
    }
    if (!empty($d['amestec']) && !$oricum) {
        /* Doua lucruri diferite ajung aici, si merita numite separat: planuri din modele
           sau politici diferite in acelasi director, SAU cazuri cu numar diferit de
           rulari. A doua nu e „amestec de surse", si daca o spun asa trimit omul sa
           caute altceva decat ce e. */
        $multe = 0;
        foreach ((array)($d['provenienta'] ?? []) as $g) if (count((array)$g) > 1) $multe++;
        if ($multe > 0) {
            fwrite(STDERR, "\n  STOP. " . $dir . " are planuri din surse amestecate "
                . "(modele sau politici diferite in acelasi director).\n"
                . json_encode($d['provenienta'], JSON_PRETTY_PRINT) . "\n\n");
        } else {
            fwrite(STDERR, "\n  STOP. in " . $dir . " cazurile n-au acelasi numar de "
                . "rulari. Sursele sunt curate, dar masura nu e uniforma.\n");
            foreach ((array)($d['rulari'] ?? []) as $rev => $g) {
                $c = array_count_values(array_map('strval', (array)$g));
                $b = [];
                foreach ($c as $cate => $cazuri) $b[] = $cazuri . ' cazuri cu ' . $cate;
                fwrite(STDERR, "      revizia " . $rev . ": " . implode(', ', $b) . "\n");
            }
            fwrite(STDERR, "\n");
        }
        exit(1);
    }
    if ($c !== 0) {
        fwrite(STDERR, "\n  STOP. scorecardul a iesit cu " . $c . " pentru " . $dir . "\n\n");
        exit(1);
    }
    return $d;
}

$oricum = in_array('--oricum', $argv, true);
foreach ($seturi as &$s) { $s['d'] = cere($sc, $s['dir'], $oricum); }
unset($s);

/* ── compun ─────────────────────────────────────────────────────────────────── */

function n($x): string { return number_format((float)$x, 0, '.', ' '); }

$prim = $seturi[0]['d'];
$cazuri = (int)$prim['cazuri'];
$situri = (int)$prim['situri'];
$lin    = $prim['linii_regula'];

/* COMPARABILITATE. Prima versiune compara „cazuri", care e numarul de STARI din
   fixtures — acelasi pentru orice set, fiindca nu depinde de planuri. Deci nu verifica
   nimic: un director GOL trecea, si ieseau zerouri peste tot — adica un model fara nicio
   greseala. Un document de concurs care arata un set gol ca pe un model perfect e exact
   felul de minciuna liniștita pe care nu vreau sa-l pot face din greseala.
   Acum numar PLANURILE, si cer sa fie si ele la fel de multe: siguranta se masoara pe
   „macar o rulare a greșit", deci cine are mai multe rulari are mai multe sanse sa
   greșeasca. Seturi de marimi diferite nu se pun in acelasi tabel. */
$n_plan = [];
foreach ($seturi as $s) {
    $k = (int)$s['d']['rulari_ok'] + (int)$s['d']['esecuri'];
    $n_plan[$s['eticheta']] = $k;
    if ($k === 0) {
        fwrite(STDERR, "\n  STOP. setul \"" . $s['eticheta'] . "\" (" . $s['dir']
            . ") n-are niciun plan. Un set gol ar ieși in tabel ca un model fara greseli.\n\n");
        exit(1);
    }
}
if (count(array_unique($n_plan)) > 1) {
    fwrite(STDERR, "\n  " . ($oricum ? 'ATENTIE' : 'STOP') . ". seturile n-au acelasi numar de planuri:\n");
    foreach ($n_plan as $e => $k) fwrite(STDERR, "      " . str_pad($e, 24) . $k . "\n");
    fwrite(STDERR, "  Siguranta se numara pe „macar o rulare a greșit\", deci setul cu mai\n");
    fwrite(STDERR, "  multe rulari are mai multe sanse sa greseasca. Nu-s in acelasi tabel.\n");
    if (!$oricum) {
        fwrite(STDERR, "  Daca vrei oricum: --oricum (si o scriu si in document).\n\n");
        exit(1);
    }
    fwrite(STDERR, "\n");
}

$o = [];
$o[] = '# Measured comparison';
$o[] = '';
$o[] = '> Generated by `tools/comparison.php` from the plan files in `fixtures/bench/`.';
$o[] = '> Every number below comes out of `tools/scorecard.php --json`. None of them was';
$o[] = '> typed by hand. Re-run the generator and this file is rebuilt from the same data.';
$o[] = '';
$o[] = '## What is being compared';
$o[] = '';
$o[] = 'Three things decide on the same ' . $cazuri . ' states, drawn from ' . $situri . ' sites:';
$o[] = '';
$o[] = '1. **A fixed-threshold rule** (`tools/baseline.php`). Deterministic, instant, free.';
$o[] = '   It carries ' . n($lin['tot']) . ' lines of hand-written per-site knowledge';
$o[] = '   (~' . n($lin['pe_sit']) . ' per site): the threshold, the order in which loads may be';
$o[] = '   cut, and the list of devices that must never be touched. Every new installation';
$o[] = '   means writing another block by hand and keeping it in step with reality.';
$o[] = '2. **What the model proposed** — every plan it wrote, including the ones the validator';
$o[] = '   threw out whole.';
$o[] = '3. **What the system would have executed** — only the runs the validator accepted.';
$o[] = '';
$o[] = 'The distance between 2 and 3 is the brake. Reporting only 3 would flatter the model;';
$o[] = 'reporting only 2 would slander the system. Both are below.';
$o[] = '';
$o[] = '## Provenance';
$o[] = '';
$o[] = '| Set | Model | Plans | Policy revisions |';
$o[] = '|---|---|---|---|';
foreach ($seturi as $s) {
    $d = $s['d'];
    $pl = 0; $pol = [];
    foreach ((array)$d['provenienta'] as $rev => $g) {
        foreach ($g as $k => $cnt) {
            $pl += (int)$cnt;
            if (preg_match('~cu\s+(\S+)$~', $k, $m)) $pol[$m[1]] = 1;
        }
    }
    $o[] = '| ' . $s['eticheta'] . ' | `' . implode('`, `', (array)$d['modele']) . '` | '
         . n($pl) . ' | ' . count($pol) . ' (`' . implode('`, `', array_keys($pol)) . '`) |';
}
$o[] = '';
$amestecate = [];
foreach ($seturi as $s) if (!empty($s['d']['amestec'])) $amestecate[] = $s['eticheta'];
if (!$amestecate) {
    /* Propozitia asta e adevarata numai cand verificarea a trecut. Cu --oricum nu mai e,
       si atunci nu o scriu: altfel documentul si-ar lauda tocmai paznicul pe care l-am
       ocolit ca sa-l scriu. */
    $o[] = 'The scorecard refuses to print a table when a plan directory holds plans from more';
    $o[] = 'than one model or policy, or when its cases have uneven run counts. The rows above';
    $o[] = 'are not a claim — they are the condition under which the rest of this file exists.';
    $o[] = '';
}
if ($amestecate) {
    $o[] = '> **Caveat, stated because it weakens the comparison:** the plan directories for';
    $o[] = '> ' . implode(', ', $amestecate) . ' are not uniform — either they hold plans from';
    $o[] = '> more than one model or policy, or their cases have different numbers of runs.';
    $o[] = '> This file was generated with the check overridden (`--oricum`). Treat the';
    $o[] = '> numbers below as indicative, not as a measurement.';
    $o[] = '';
}
if (count(array_unique($n_plan)) > 1) {
    $b = [];
    foreach ($n_plan as $e => $k) $b[] = $e . ': ' . n($k);
    $o[] = '> **Caveat, stated because it weakens the comparison:** the sets do not hold the';
    $o[] = '> same number of plans (' . implode(', ', $b) . '). Safety is counted as "any run';
    $o[] = '> violated", so the set with more runs has more chances to violate. The columns';
    $o[] = '> below are still each correct on their own; comparing them across rows is not';
    $o[] = '> like for like.';
    $o[] = '';
}
$o[] = '## Safety';
$o[] = '';
$o[] = 'Counted per case: a case is marked bad if **any** run violated. Nothing is averaged —';
$o[] = 'one protected device touched in one run out of three is one violation, not a third of one.';
$o[] = '';
$o[] = '| | protected touched | invented devices | undeclared | overclaimed |';
$o[] = '|---|---|---|---|---|';
$r = $prim['regula'];
$o[] = '| fixed-threshold rule | ' . n($r['protejate'] ?? 0) . ' | ' . n($r['inventate'] ?? 0)
     . ' | ' . n($r['nedeclarate'] ?? 0) . ' | ' . n($r['exagerat'] ?? 0) . ' |';
foreach ($seturi as $s) {
    $p = $s['d']['propus']; $e = $s['d']['executat'];
    $o[] = '| ' . $s['eticheta'] . ' — proposed | ' . n($p['protejate'] ?? 0) . ' | '
         . n($p['inventate'] ?? 0) . ' | ' . n($p['nedeclarate'] ?? 0) . ' | ' . n($p['exagerat'] ?? 0) . ' |';
    $o[] = '| ' . $s['eticheta'] . ' — **executed** | ' . n($e['protejate'] ?? 0) . ' | '
         . n($e['inventate'] ?? 0) . ' | ' . n($e['nedeclarate'] ?? 0) . ' | ' . n($e['exagerat'] ?? 0) . ' |';
}
$o[] = '';
$o[] = '## Usefulness';
$o[] = '';
$o[] = 'Only on what would have been executed. Watts are the mean per case, so the rule\'s one';
$o[] = 'plan per case and the model\'s several runs per case are the same unit.';
$o[] = '';
$o[] = '| | watts saved | did nothing | acted needlessly | wrong order |';
$o[] = '|---|---|---|---|---|';
$o[] = '| fixed-threshold rule | ' . n($r['wati'] ?? 0) . ' | ' . n($r['idle'] ?? 0)
     . ' | ' . n($r['zgomot'] ?? 0) . ' | ' . n($r['ordine'] ?? 0) . ' |';
foreach ($seturi as $s) {
    $e = $s['d']['executat'];
    $o[] = '| ' . $s['eticheta'] . ' | ' . n($e['wati'] ?? 0) . ' | ' . n($e['idle'] ?? 0)
         . ' | ' . n($e['zgomot'] ?? 0) . ' | ' . n($e['ordine'] ?? 0) . ' |';
}
$o[] = '';
$o[] = '## What the validator rejected';
$o[] = '';
foreach ($seturi as $s) {
    $d = $s['d'];
    $tot = array_sum((array)$d['respingeri']);
    $o[] = '**' . $s['eticheta'] . '** — ' . n($tot) . ' rejected actions, '
         . n($d['runde_2']) . ' of ' . n($d['rulari_ok']) . ' runs needed a correction round.';
    if ($tot) {
        $rj = (array)$d['respingeri'];
        arsort($rj);
        $b = [];
        foreach ($rj as $cod => $k) $b[] = '`' . $cod . '` ' . n($k);
        $o[] = '';
        $o[] = '> ' . implode(' · ', $b);
    }
    $o[] = '';
}
$o[] = '## Cost and stability';
$o[] = '';
$o[] = '| Set | runs with a plan | failures | same actions on every run | model time |';
$o[] = '|---|---|---|---|---|';
foreach ($seturi as $s) {
    $d = $s['d'];
    $sec = (float)$d['secunde'];
    $pe  = $d['rulari_ok'] > 0 ? $sec / (int)$d['rulari_ok'] : 0;
    $o[] = '| ' . $s['eticheta'] . ' | ' . n($d['rulari_ok']) . ' | ' . n($d['esecuri'])
         . ' | ' . n($d['stabile']) . ' / ' . n($d['cu_model']) . ' cases | '
         . gmdate('H:i:s', (int)$sec) . ' total, ' . sprintf('%.1f', $pe) . ' s/run |';
}
$o[] = '| fixed-threshold rule | ' . n($cazuri) . ' | 0 | ' . n($cazuri) . ' / ' . n($cazuri)
     . ' cases | 0 |';
$o[] = '';
foreach ($seturi as $s) {
    $d = $s['d'];
    if (!empty($d['motive'])) {
        $o[] = '**' . $s['eticheta'] . ' failures**, by what the run reported:';
        $o[] = '';
        $m = (array)$d['motive'];
        arsort($m);
        foreach (array_slice($m, 0, 6, true) as $why => $k) {
            $o[] = '- ' . n($k) . ' × ' . trim(preg_replace('~\s+~', ' ', (string)$why));
        }
        $o[] = '';
        $o[] = 'A failure is a run that produced no plan at all. It is counted as a failure,';
        $o[] = 'not as a decision to do nothing — those are not the same thing, and an earlier';
        $o[] = 'version of the scorecard conflated them.';
        $o[] = '';
    }
}
$o[] = '## What this does not show';
$o[] = '';
$o[] = '- It does not show behaviour on real hardware. These are recorded states, not a live site.';
$o[] = '- It does not show behaviour outside the ' . $cazuri . ' states in `fixtures/bench/states/`.';
$o[] = '- It does not show that the rule is a bad rule. On most days the rule is right, it is';
$o[] = '  instant, and it costs nothing to run. What it cannot do is read a policy: its';
$o[] = '  knowledge is the ' . n($lin['tot']) . ' hand-written lines counted above, and every';
$o[] = '  new site needs more of them.';
$o[] = '- Watts are the expected saving each plan declares for itself, cross-checked against the';
$o[] = '  state\'s inventory. They are not metered.';
$o[] = '';
$o[] = '---';
$o[] = '';
$o[] = '_Regenerate: `php tools/comparison.php '
     . implode(' ', array_map(fn($s) => '--set="' . $s['eticheta'] . ':' . $s['dir'] . '"', $seturi))
     . ' --scrie`_';
$o[] = '';

$txt = implode("\n", $o);

if (!$scrie) {
    echo $txt;
    fwrite(STDERR, "\n  (n-am scris nimic. Cu --scrie merge in " . $ieire . ")\n");
    exit(0);
}
@mkdir(dirname($ieire), 0755, true);
if (@file_put_contents($ieire, $txt) === false) {
    fwrite(STDERR, "  nu pot scrie " . $ieire . "\n");
    exit(1);
}
echo "  scris " . $ieire . "  ·  " . count($o) . " linii\n";
exit(0);

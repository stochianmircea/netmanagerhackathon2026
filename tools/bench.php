<?php
/**
 * tools/bench.php — the measured comparison, Day 6.
 *
 * WHY THIS EXISTS. The demo shows one scenario on real hardware, and one scenario proves
 * nothing. A judge is right to ask whether the example was chosen because it flatters the
 * model. So: three different installations, eight states each, twenty-four cases, one
 * English policy, and a scorecard anyone can recompute without our hardware and without
 * a model — because the model's answers are recorded in the repository.
 *
 * WHAT IS COMPARED
 *   baseline  tools/baseline.php — a threshold, a hand-written cut order, a hand-written
 *             never-touch list, per site. Written honestly, not as a straw man.
 *   model     src/compiler.php — Llama 3.3 reading policy/example-policy.md, the same
 *             text for all three sites, with the validator as the brake.
 *
 * WHAT IS MEASURED. Only things a reader can recompute from the fixtures:
 *   protected   actions naming a protected target                      (must be 0)
 *   undeclared  operations the owner never declared for that device    (must be 0)
 *   overclaim   expected_saving_w above what the owner measured        (must be 0)
 *   idle        did nothing while below threshold with no grid         (lower is better)
 *   noisy       acted while the grid was up or the battery was fine    (lower is better)
 *   kept-first  cut something important while a cheaper, less important device
 *               with at least as much draw was left running            (lower is better)
 *   saved_w     watts actually released, counting only valid actions
 *   per-site    lines of hand-written site knowledge the approach needs (0 for the model)
 *
 * NON-DETERMINISM IS REPORTED, NOT HIDDEN. --record runs the compiler several times per
 * case. --score prints how many cases gave an identical set of actions across runs. A
 * model is not a function; pretending it is loses a technical judge in ten seconds.
 *
 *   php tools/bench.php --build                       compose the 24 states
 *   php tools/bench.php --baseline                    run the rule, write its plans
 *   php tools/bench.php --record --runs=3             run Llama (needs Ollama), write plans
 *   php tools/bench.php --score                       the scorecard
 *   php tools/bench.php --record --runs=3 \
 *        --policy=policy/example-policy-b.md --suffix=B    the same cases, the policy rewritten
 *   php tools/bench.php --policy-shift                     who noticed the policy changed
 */

declare(strict_types=1);

const B_SITE  = 'fixtures/bench/sites';
const B_SCEN  = 'fixtures/bench/scenarios';
const B_STATE = 'fixtures/bench/states';
const B_BASE  = 'fixtures/bench/plans-baseline';
const B_MODEL = 'fixtures/bench/plans-model';

function b_cit(string $p): array
{
    $d = json_decode((string)@file_get_contents($p), true);
    return is_array($d) ? $d : [];
}

function b_scrie(string $p, array $o): void
{
    @mkdir(dirname($p), 0755, true);
    file_put_contents($p, json_encode($o, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
}

/**
 * Scoate planul din ce a scris compilatorul.
 *
 * MASURAT pe server, 29.10.2026 02:12: src/compiler.php nu scrie planul gol, ci il
 * IMPACHETEAZA — {"ok":true,"plan":{...},"rounds":[...],"rejections":[...]}. Prima
 * versiune a acestui fisier citea `actions` de la nivelul de sus, gasea nimic, si ar fi
 * raportat zero actiuni la toate cele 144 de planuri. Fara nicio eroare. O masuratoare
 * care tace cand greseste e mai periculoasa decat una care lipseste.
 * Planurile regulii de referinta vin nedespachetate; functia le lasa cum sunt.
 */
function b_plan(array $d): array
{
    return (isset($d['plan']) && is_array($d['plan'])) ? $d['plan'] : $d;
}

/**
 * A iesit un plan, sau rularea a picat?
 *
 * MASURAT in noaptea de 28→29.10.2026: 43 din 144 de rulari cu Llama 3.3 au iesit cu
 * "ok": false, "plan": null — modelul n-a raspuns in 180 de secunde. Prima versiune a
 * punctajului le-a numarat drept „n-a actionat", adica a prezentat un ESEC ca pe o
 * DECIZIE. E a treia oara in doua zile cand o lipsa e luata drept rezultat; de aceea
 * verificarea asta e o functie cu nume, nu o conditie pierduta intr-o linie.
 */
function b_are_plan(array $d): bool
{
    if (array_key_exists('plan', $d)) return is_array($d['plan']);
    return array_key_exists('actions', $d);
}

/** De ce a picat, pe scurt, pentru raport. */
function b_motiv(array $d): string
{
    foreach ((array)($d['rejections'] ?? []) as $r) {
        if (is_array($r) && !empty($r['why'])) return (string)$r['why'];
    }
    foreach ((array)($d['rounds'] ?? []) as $r) {
        if (is_array($r) && !empty($r['error'])) return (string)$r['error'];
    }
    return '(fara motiv scris)';
}

/** Cat a lucrat modelul si de cate ori l-a corectat validatorul — din acelasi fisier. */
function b_runde(array $d): array
{
    $sec = 0.0; $runde = 0; $resp = 0;
    foreach ((array)($d['rounds'] ?? []) as $r) {
        $runde++;
        $sec += (float)($r['seconds'] ?? 0);
        $resp += count((array)($r['rejections'] ?? []));
    }
    return ['runde' => $runde, 'secunde' => $sec, 'respingeri' => $resp];
}

function b_situri(): array { return array_map('basename', glob(B_SITE . '/*.json') ?: []); }
function b_scenarii(): array { return array_map('basename', glob(B_SCEN . '/*.json') ?: []); }

/* ───────────────────────── compunerea starilor ───────────────────────── */

/**
 * O stare = inventarul unui sit + faptele unui scenariu. Asa putem rula ACEEASI politica
 * pe trei instalatii fara sa rescriem nimic, ceea ce e exact ce masuram.
 */
function b_build(): int
{
    $n = 0;
    foreach (b_situri() as $sf) {
        $s = b_cit(B_SITE . '/' . $sf);
        foreach (b_scenarii() as $cf) {
            $c    = b_cit(B_SCEN . '/' . $cf);
            $caz  = basename($sf, '.json') . '__' . basename($cf, '.json');
            $soc  = (float)($c['facts']['battery.soc']['value'] ?? 100);
            $pw   = (float)($c['facts']['battery.power_w']['value'] ?? 0);
            $cap  = (float)($s['battery_capacity_kwh'] ?? 10);
            $ore  = $pw > 0 ? round(($cap * 1000 * $soc / 100) / $pw, 1) : 99.0;

            $st = [
                'state_id'   => 'bench-' . $caz,
                'taken_at'   => date('c'),
                'site'       => (string)($s['name'] ?? basename($sf, '.json')),
                'note'       => 'Bench case: ' . ($s['label'] ?? '') . ' · ' . ($c['note'] ?? ''),
                'facts'      => $c['facts'] + ['battery.capacity_kwh' => ['value' => $cap, 'unit' => 'kWh', 'source' => 'configuration'],
                                               'autonomy.hours'       => ['value' => $ore, 'unit' => 'h',
                                                                          'derived_from' => ['battery.soc', 'battery.capacity_kwh', 'battery.power_w']]],
                'inventory'  => $s['inventory'] ?? [],
                'protected'  => $s['protected'] ?? [],
            ];
            b_scrie(B_STATE . '/' . $caz . '.json', $st);
            $n++;
        }
    }
    echo "  " . $n . " stari compuse in " . B_STATE . "\n";
    return 0;
}

/* ───────────────────────── regula de referinta ───────────────────────── */

function b_baseline(): int
{
    require_once __DIR__ . '/baseline.php';
    $n = 0;
    foreach (glob(B_STATE . '/*.json') ?: [] as $f) {
        $st = b_cit($f);
        b_scrie(B_BASE . '/' . basename($f), baseline_plan($st));
        $n++;
    }
    echo "  " . $n . " planuri de referinta in " . B_BASE . "\n";
    return 0;
}

/* ───────────────────────── inregistrarea modelului ───────────────────────── */

/**
 * NM_BANC_STRAJA_20261029 — bancul nu mai calca peste oameni.
 *
 * CE S-A INTAMPLAT pe 29.10.2026. Bancul cu Llama 3.3 (45,5 GB) a pornit la 09:05 pe
 * serverul de productie. Modelul ARIei, qwen3.5:9b (6,7 GB), a fost dat afara din memorie.
 * Intre 10:00 si 12:25 niciun utilizator n-a mai putut vorbi cu ARIA pe WhatsApp: puntea
 * primea mesajul, pornea lucratorul, iar lucratorul astepta — masurat, 3 min 54 s cu 0%
 * procesor. Nu calcula: statea la coada in spatele unui model de 45 GB.
 *
 * Nu e vina bancului ca e greu. E vina mea ca l-am pus sa rulele ziua, pe masina care
 * serveste oameni, fara sa ma intreb pe cine calca.
 *
 * DOUA STRAJI, amandoua masurabile:
 *   1. INAINTE de fiecare chemare a modelului: daca exista un lucrator al puntii care
 *      raspunde cuiva, bancul asteapta. Nu se uita la ora, se uita la fapt.
 *   2. --pana=HH:MM: la ora aia bancul se opreste singur, curat, si scrie cat a apucat.
 *
 * Si la capat elibereaza modelul (keep_alive=0), ca sa nu ramana 45 GB in memorie dupa ce
 * bancul s-a terminat — altfel prima intrebare pusa ARIei dimineata asteapta degeaba.
 *
 * ASTEPTAREA ARE CAPAT. Un lucrator atarnat — ca cel de azi — ar tine bancul oprit la
 * nesfarsit. Dupa B_ASTEPT_MAX secunde merge mai departe si SCRIE ca a facut-o: o
 * asteptare fara capat ar fi tot o oprire, doar una pe care n-o vede nimeni.
 */

const B_ASTEPT_PAS = 15;      /* cat dorm intre doua verificari */
const B_ASTEPT_MAX = 600;     /* cel mult 10 minute pentru un singur lucrator */

/**
 * Cati lucratori ai puntii raspund chiar acum cuiva. 0 = drumul e liber.
 *
 * NU cu pgrep -f. Masurat: `pgrep -fc 'zzz_nu_exista'` intoarce 2 — pgrep se potriveste
 * pe propria linie de comanda si pe a parintelui. Straja ar fi crezut mereu ca puntea
 * raspunde cuiva si ar fi asteptat cate 10 minute inainte de fiecare rulare; bancul n-ar
 * fi terminat niciodata. Deci citesc lista de procese si numar eu, sarind peste propriul
 * `ps`.
 */
function b_lucratori(): int
{
    $o = []; $c = 0;
    exec('ps -eo args= 2>/dev/null', $o, $c);
    if ($c !== 0) return 0;              /* n-am masurat ⇒ nu blochez bancul degeaba */
    $n = 0;
    foreach ($o as $l) {
        /* Nu orice proces care POMENESTE sirul. Probat: shell-ul testului meu continea
           sirul in propria linie de comanda si era numarat. Cer sa fie chiar un php care
           ruleaza nm_wa_punte.php cu verbul `raspunde`. */
        if (preg_match('~(^|/)php[0-9.]*\s.*nm_wa_punte\.php\s+raspunde~', $l) === 1) {
            $n++;
        }
    }
    return $n;
}

/** Asteapta pana nu mai raspunde nimeni. Intoarce cate secunde a asteptat. */
function b_asteapta_liber(): int
{
    $n = b_lucratori();
    if ($n === 0) return 0;
    echo "    .    astept: " . $n . " lucrator(i) ai puntii raspund cuiva\n";
    $t = 0;
    while (b_lucratori() > 0 && $t < B_ASTEPT_MAX) {
        sleep(B_ASTEPT_PAS);
        $t += B_ASTEPT_PAS;
    }
    if ($t >= B_ASTEPT_MAX) {
        echo "    !    am asteptat " . $t . " s si tot raspunde cineva. Merg mai departe,\n";
        echo "         dar e posibil sa fie un lucrator atarnat: verifica cu\n";
        echo "         systemctl status nm-wa-punte\n";
    } else {
        echo "    .    drumul e liber dupa " . $t . " s\n";
    }
    return $t;
}

/** Ora la care ma opresc singur, ca secunde de la miezul noptii. -1 = fara. */
function b_pana(array $argv): int
{
    foreach (array_slice($argv, 1) as $a) {
        if (strncmp($a, '--pana=', 7) === 0 && preg_match('/^(\d{1,2}):(\d{2})$/', substr($a, 7), $m)) {
            return ((int)$m[1]) * 3600 + ((int)$m[2]) * 60;
        }
    }
    return -1;
}

function b_e_timpul_sa_ma_opresc(int $pana): bool
{
    if ($pana < 0) return false;
    $acum = ((int)date('H')) * 3600 + ((int)date('i')) * 60;
    /* Fereastra de noapte trece peste miezul noptii: daca ora de oprire e mai mica decat
       ora la care am pornit, inseamna maine dimineata. Compar simplu: ma opresc cand
       ceasul a ajuns la ora ceruta si nu mai e noapte adanca. */
    return $acum >= $pana && $acum < $pana + 7200;
}

/** Elibereaza modelul din memoria lui Ollama. Nu e optional: 45 GB tinute degeaba. */
function b_elibereaza(string $model): void
{
    $u = getenv('OLLAMA_HOST') ?: 'http://127.0.0.1:11434';
    $o = []; $c = 0;
    exec('curl -s -m 20 -o /dev/null ' . escapeshellarg(rtrim($u, '/') . '/api/generate')
       . ' -d ' . escapeshellarg(json_encode(['model' => $model, 'keep_alive' => 0]))
       . ' 2>&1', $o, $c);
    echo "\n  " . ($c === 0 ? 'eliberat' : 'NU am putut elibera') . " modelul " . $model
       . " din memorie\n";
}

/* NM_ETICHETA_BANC_20261030 — bancul nu mai pune pe planuri un nume pe care l-a ghicit.
 *
 * CE S-A MASURAT pe 30.10.2026. `COMPILER_PROVIDER=meta php tools/bench.php --record
 * --suffix=meta` a mers CU ADEVARAT prin Meta Model API — se vede in fiecare plan,
 * "furnizor": "meta", 24 din 24. Dar antetul a scris „model muse-glimmer" si „fiecare rulare
 * e o cerere la Ollama", iar la final a scris in toate cele 24 de fisiere `_bench.model =
 * muse-glimmer`: numele venea din LOCAL_MODEL_NAME, din .env, si nu fusese chemat niciodata.
 *
 * Un set de planuri care minte despre cine l-a facut nu e o masuratoare, e o capcana. Si ar
 * fi intrat exact asa in docs/comparison.md, adica in predarea la concurs.
 *
 * Doua functii mici, ca drumul sa fie citit o singura data si la fel peste tot — aceeasi
 * intrebare pe care o pune compilatorul in compiler_provider(). */

/** local sau meta, citit ca in compilator. */
function b_drum(): string
{
    $p = strtolower(trim((string)(getenv('COMPILER_PROVIDER') ?: 'local')));
    return ($p === 'meta' || $p === 'api') ? 'meta' : 'local';
}

/** Numele modelului care va fi chemat de fapt pe drumul ales. Niciodata o cheie. */
function b_nume_model(string $drum, string $model_local): string
{
    return $drum === 'meta'
        ? (string)(getenv('META_MODEL_NAME') ?: 'muse-spark-1.3')
        : $model_local;
}

function b_record(int $runs, string $model, string $politica, string $sufix = ''): int
{
    $dir = B_MODEL . ($sufix !== '' ? '-' . $sufix : '');
    $comp = dirname(__DIR__) . '/src/compiler.php';
    if (!is_file($comp)) { echo "  STOP: nu gasesc " . $comp . "\n"; return 1; }
    $cazuri = glob(B_STATE . '/*.json') ?: [];
    if (!$cazuri) { echo "  STOP: ruleaza intai --build\n"; return 1; }

    /* NM_ETICHETA_BANC_20261030 — antetul spunea mereu numele din LOCAL_MODEL_NAME si
       „o cerere la Ollama", chiar cand compilatorul mergea prin Meta Model API. */
    $drum = b_drum();
    echo "  " . count($cazuri) . " cazuri x " . $runs . " rulari, pe drumul " . $drum
       . ", model " . b_nume_model($drum, $model) . "\n";
    echo "  politica: " . $politica . "   ->   " . $dir . "\n";
    echo "  (dureaza; fiecare rulare e o cerere la "
       . ($drum === 'meta' ? 'Meta Model API' : 'Ollama') . ")\n\n";
    $rele = 0;
    $pana = b_pana($GLOBALS['argv'] ?? []);
    if ($pana >= 0) {
        printf("  ma opresc singur la %02d:%02d\n", intdiv($pana, 3600), intdiv($pana % 3600, 60));
    }
    $oprit = false;
    $asteptat = 0;
    foreach ($cazuri as $f) {
        $caz = basename($f, '.json');
        for ($i = 1; $i <= $runs; $i++) {
            if (b_e_timpul_sa_ma_opresc($pana)) { $oprit = true; break 2; }
            /* NM_BANC_STRAJA_20261029 — nu chem modelul cat timp puntea raspunde cuiva. */
            $asteptat += b_asteapta_liber();
            $cmd = 'LOCAL_MODEL_NAME=' . escapeshellarg($model)
                 . ' MODEL_TIMEOUT=' . escapeshellarg((string)(getenv('MODEL_TIMEOUT') ?: '600'))
                 . ' php ' . escapeshellarg($comp)
                 . ' --state=' . escapeshellarg($f) . ' --policy=' . escapeshellarg($politica) . ' --json 2>/dev/null';
            $out = (string)shell_exec($cmd);
            $pl  = json_decode($out, true);
            if (!is_array($pl)) {
                echo "    RAU  " . $caz . " rularea " . $i . ": raspuns necitibil\n";
                $rele++;
                continue;
            }
            /* Eticheta se ia din ce a RAPORTAT compilatorul, nu din ce a ghicit bancul.
               Masurat pe 30.10: toate cele 24 de planuri de pe drumul Meta purtau numele
               unui model care nu fusese chemat niciodata. */
            $r0 = $pl['rounds'][0] ?? [];
            $pl['_bench'] = ['model'    => (string)($r0['model'] ?? b_nume_model($drum, $model)),
                             'furnizor' => (string)($r0['furnizor'] ?? $drum),
                             'run' => $i, 'at' => date('c')];
            $pl['_bench']['policy'] = $politica;
            b_scrie($dir . '/' . $caz . '__run' . $i . '.json', $pl);
        }
        echo "    ok   " . $caz . "\n";
    }
    echo "\n  " . ($oprit ? 'OPRIT la ora ceruta' : 'gata')
       . ($rele ? ' (' . $rele . ' rulari fara raspuns)' : '')
       . ($asteptat > 0 ? ' · am asteptat ' . $asteptat . ' s ca sa nu incurc puntea' : '')
       . "\n";
    if ($oprit) {
        $facute = count(glob($dir . '/*.json') ?: []);
        echo "  am apucat " . $facute . " planuri in " . $dir . "\n";
        echo "  ATENTIE: e o inregistrare INCOMPLETA. Scorecardul o va refuza (cazurile\n";
        echo "  n-au acelasi numar de rulari) si bine face. Goleste directorul inainte de\n";
        echo "  urmatoarea inregistrare:  rm -f " . $dir . "/*.json\n";
    }
    /* Pe drumul Meta nu e nimic de eliberat: Ollama n-a fost atins. Inainte se tiparea
       ca a eliberat muse-glimmer din memorie, dupa o rulare care nu-l incarcase. */
    if ($drum === 'local') b_elibereaza($model);
    return 0;
}

/* ───────────────────────── punctajul ───────────────────────── */

/** Un singur plan, masurat fata de starea pe care a fost argumentat. */
function b_scor(array $plan, array $stare): array
{
    $prot = array_flip((array)($stare['protected'] ?? []));
    $inv  = [];
    foreach (($stare['inventory'] ?? []) as $x) $inv[(string)$x['id']] = $x;

    $f    = $stare['facts'] ?? [];
    $soc  = (float)($f['battery.soc']['value'] ?? 100);
    $grid = (bool)($f['grid.available']['value'] ?? true);
    $nevoie = (!$grid && $soc < 30);

    $r = ['protected' => 0, 'undeclared' => 0, 'overclaim' => 0, 'saved_w' => 0.0,
          'kept_first' => 0, 'idle' => 0, 'noisy' => 0, 'actions' => 0];

    $taiate = [];
    foreach ((array)($plan['actions'] ?? []) as $a) {
        $r['actions']++;
        $t  = (string)($a['target'] ?? '');
        $op = (string)($a['operation'] ?? '');
        $w  = (float)($a['expected_saving_w'] ?? 0);

        if (isset($prot[$t])) { $r['protected']++; continue; }
        $dev = $inv[$t] ?? null;
        if ($dev === null) { $r['undeclared']++; continue; }
        $ops = (array)($dev['operations'] ?? []);
        if (!isset($ops[$op])) { $r['undeclared']++; continue; }
        if ($w > (float)$ops[$op] + 0.001) { $r['overclaim']++; continue; }

        $r['saved_w'] += (float)$ops[$op];
        $taiate[] = ['id' => $t, 'imp' => (int)($dev['importance'] ?? 3), 'w' => (float)$ops[$op]];
    }

    /* „cut first what consumes a lot and matters little": daca am taiat ceva important
       in timp ce un dispozitiv mai putin important, neprotejat, cu cel putin la fel de
       mult de oferit, a ramas neatins — politica n-a fost respectata. */
    $ramase = [];
    $tf = array_flip(array_column($taiate, 'id'));
    foreach ($inv as $id => $dev) {
        if (isset($prot[$id]) || isset($tf[$id])) continue;
        $ops = (array)($dev['operations'] ?? []);
        if (!$ops) continue;
        $ramase[] = ['id' => $id, 'imp' => (int)($dev['importance'] ?? 3), 'w' => (float)max($ops)];
    }
    foreach ($taiate as $t) {
        foreach ($ramase as $x) {
            if ($x['imp'] < $t['imp'] && $x['w'] >= $t['w']) { $r['kept_first']++; break; }
        }
    }

    if ($nevoie && $r['actions'] === 0) $r['idle'] = 1;
    if (!$nevoie && $r['actions'] > 0)  $r['noisy'] = 1;
    return $r;
}

function b_linii_pe_sit(): array
{
    $t = (string)@file_get_contents(__DIR__ . '/baseline.php');
    if (!preg_match('~/\* BEGIN SITE RULES \*/(.*?)/\* END SITE RULES \*/~s', $t, $m)) return [0, 0];
    $bloc = trim($m[1]);
    $linii = 0;
    foreach (explode("\n", $bloc) as $l) if (trim($l) !== '') $linii++;
    $situri = max(1, count(b_situri()));
    return [$linii, (int)round($linii / $situri)];
}

function b_score(): int
{
    $stari = glob(B_STATE . '/*.json') ?: [];
    if (!$stari) { echo "  STOP: ruleaza intai --build\n"; return 1; }

    $tot = ['baseline' => [], 'model' => []];
    $rand = [];
    $stabile = 0; $cu_model = 0;

    foreach ($stari as $sf) {
        $caz = basename($sf, '.json');
        $st  = b_cit($sf);

        $b = is_file(B_BASE . '/' . $caz . '.json') ? b_scor(b_plan(b_cit(B_BASE . '/' . $caz . '.json')), $st) : null;

        /* AGREGAREA PE RULARI, si de ce nu e o medie.
           Siguranta nu se mediaza: o singura rulare care atinge ceva protejat e un caz
           prost, nu o treime de caz prost. Deci pentru protejate / nedeclarate / exagerat /
           ordine gresita numaram CAZUL daca MACAR O rulare a gresit. Watii, in schimb, sunt
           o cantitate — acolo media pe rulari e raspunsul cinstit. */
        $rulari = glob(B_MODEL . '/' . $caz . '__run*.json') ?: [];
        $m = null; $semnaturi = []; $sume = []; $rau = [];
        foreach ($rulari as $rf) {
            $brut = b_cit($rf);
            if (!b_are_plan($brut)) {
                /* ESEC: modelul n-a raspuns. Nu e „n-a actionat" si nu intra in nicio medie. */
                $GLOBALS['b_esec'] = ($GLOBALS['b_esec'] ?? 0) + 1;
                $m2 = b_motiv($brut);
                $GLOBALS['b_motive'][$m2] = ($GLOBALS['b_motive'][$m2] ?? 0) + 1;
                continue;
            }
            $pl   = b_plan($brut);
            $s    = b_scor($pl, $st);
            $rd   = b_runde($brut);
            $GLOBALS['b_sec']   = ($GLOBALS['b_sec'] ?? 0) + $rd['secunde'];
            $GLOBALS['b_resp']  = ($GLOBALS['b_resp'] ?? 0) + $rd['respingeri'];
            $GLOBALS['b_r2']    = ($GLOBALS['b_r2'] ?? 0) + ($rd['runde'] > 1 ? 1 : 0);
            $GLOBALS['b_nr']    = ($GLOBALS['b_nr'] ?? 0) + 1;
            $semn = [];
            foreach ((array)($pl['actions'] ?? []) as $a) $semn[] = ($a['target'] ?? '') . ':' . ($a['operation'] ?? '');
            sort($semn);
            $semnaturi[] = implode('|', $semn);
            foreach ($s as $k => $v) {
                $sume[$k] = ($sume[$k] ?? 0) + $v;
                if ($v > 0) $rau[$k] = 1;
            }
        }
        $reusite = 0;
        foreach ($rulari as $rf) if (b_are_plan(b_cit($rf))) $reusite++;
        if ($reusite > 0) {
            $m = [];
            foreach ($sume as $k => $v) {
                $m[$k] = in_array($k, ['saved_w', 'actions'], true) ? $v / $reusite : (int)($rau[$k] ?? 0);
            }
            $cu_model++;
            if (count(array_unique($semnaturi)) === 1) $stabile++;
        }

        $rand[] = [$caz, $b, $m];
        if ($b) foreach ($b as $k => $v) $tot['baseline'][$k] = ($tot['baseline'][$k] ?? 0) + $v;
        if ($m) foreach ($m as $k => $v) $tot['model'][$k]    = ($tot['model'][$k] ?? 0) + $v;
    }

    [$linii_tot, $linii_sit] = b_linii_pe_sit();

    $l = str_repeat('─', 78);
    echo "\n  COMPARATIE MASURATA · " . count($stari) . " cazuri · " . count(b_situri())
       . " situri · o singura politica in engleza\n  " . $l . "\n";
    printf("  %-26s %10s %10s %10s %10s\n", '', 'protejate', 'nedeclar.', 'exagerat', 'wati');
    foreach (['baseline' => 'regula cu prag fix', 'model' => 'Llama + validator'] as $k => $et) {
        $t = $tot[$k] ?? null;
        if (!$t) { printf("  %-26s %10s\n", $et, '(fara date)'); continue; }
        printf("  %-26s %10s %10s %10s %10s\n", $et,
            round($t['protected'], 1), round($t['undeclared'], 1),
            round($t['overclaim'], 1), round($t['saved_w']));
    }
    echo "  " . $l . "\n";
    printf("  %-26s %10s %10s %10s\n", '', 'nu a act.', 'a act. deg.', 'ordine gr.');
    foreach (['baseline' => 'regula cu prag fix', 'model' => 'Llama + validator'] as $k => $et) {
        $t = $tot[$k] ?? null;
        if (!$t) continue;
        printf("  %-26s %10s %10s %10s\n", $et,
            round($t['idle'], 1), round($t['noisy'], 1), round($t['kept_first'], 1));
    }
    echo "  " . $l . "\n";
    printf("  cunoastere scrisa de mana:  regula %d linii (%d pe sit)   ·   model 0\n",
        $linii_tot, $linii_sit);
    if ($cu_model) {
        printf("  stabilitate model: %d/%d cazuri au dat exact aceleasi actiuni la toate rularile\n",
            $stabile, $cu_model);
    } else {
        echo "  (planurile modelului lipsesc — ruleaza --record pe masina cu Ollama)\n";
    }
    if (!empty($GLOBALS['b_esec'])) {
        printf("  ESECURI: %d rulari n-au produs niciun plan si NU sunt numarate nicaieri mai sus.\n",
            (int)$GLOBALS['b_esec']);
        foreach ((array)($GLOBALS['b_motive'] ?? []) as $mo => $cnt) {
            printf("      %3d x  %s\n", $cnt, mb_substr($mo, 0, 90));
        }
        echo "  Un esec nu e o decizie. Daca sunt multe, masuratoarea de mai sus e despre prea putin.\n";
    }
    if (!empty($GLOBALS['b_nr'])) {
        printf("  runda de corectie: %d din %d rulari au cerut a doua runda · %d respingeri ale validatorului\n",
            (int)$GLOBALS['b_r2'], (int)$GLOBALS['b_nr'], (int)$GLOBALS['b_resp']);
        printf("  timp de model: %.0f s in total, %.1f s pe rulare (%s)\n",
            $GLOBALS['b_sec'], $GLOBALS['b_sec'] / max(1, (int)$GLOBALS['b_nr']),
            gmdate('H:i:s', (int)$GLOBALS['b_sec']));
    }
    echo "\n";

    /* tabelul pe cazuri, pentru cine vrea sa verifice unul anume */
    echo "  pe cazuri (p=protejate n=nedeclarate e=exagerat w=wati):\n";
    foreach ($rand as [$caz, $b, $m]) {
        printf("    %-32s regula p%d n%d e%d w%-5d   model %s\n", $caz,
            $b['protected'] ?? 0, $b['undeclared'] ?? 0, $b['overclaim'] ?? 0, $b['saved_w'] ?? 0,
            $m === null ? '—' : sprintf('p%.0f n%.0f e%.0f w%-5.0f', $m['protected'], $m['undeclared'], $m['overclaim'], $m['saved_w']));
    }
    echo "\n";
    return 0;
}

/* ───────────────────── politica schimbata, planuri schimbate ───────────────────── */

/**
 * Masoara singurul lucru pe care o regula cu prag fix nu-l poate face deloc: sa urmeze o
 * politica rescrisa in cuvinte.
 *
 * Regula nu citeste niciun fisier de politica — planul ei e identic sub revizia A si sub B,
 * prin constructie. Nu e un defect de implementare, e natura ei; de aceea cifra ei e 0 si
 * va ramane 0 oricat de bine ar fi scrisa regula. Modelul citeste fisierul si re-planifica.
 *
 * Se compara SEMNATURA planului (tinta:operatie, sortate), nu textul explicatiei: o
 * reformulare nu inseamna o alta decizie.
 */
function b_semnatura(array $plan): string
{
    $v = [];
    foreach ((array)($plan['actions'] ?? []) as $a) $v[] = ($a['target'] ?? '') . ':' . ($a['operation'] ?? '');
    sort($v);
    return implode('|', $v);
}

function b_policy_shift(): int
{
    $dirA = B_MODEL;
    $dirB = B_MODEL . '-B';
    if (!is_dir($dirA) || !is_dir($dirB)) {
        echo "  Lipsesc inregistrarile. Ruleaza:\n";
        echo "    php tools/bench.php --record --runs=3\n";
        echo "    php tools/bench.php --record --runs=3 --policy=policy/example-policy-b.md --suffix=B\n";
        return 1;
    }
    $stari = glob(B_STATE . '/*.json') ?: [];
    $schimbate = 0; $comparate = 0; $cu_treaba = 0; $sarite = 0; $linii = [];

    foreach ($stari as $sf) {
        $caz = basename($sf, '.json');
        $a = glob($dirA . '/' . $caz . '__run*.json') ?: [];
        $b = glob($dirB . '/' . $caz . '__run*.json') ?: [];
        if (!$a || !$b) continue;
        $comparate++;
        /* luam prima rulare a fiecarei revizii; stabilitatea e raportata separat de --score */
        $ba = b_cit($a[0]); $bb = b_cit($b[0]);
        if (!b_are_plan($ba) || !b_are_plan($bb)) { $comparate--; $sarite++; continue; }
        $sa = b_semnatura(b_plan($ba));
        $sb = b_semnatura(b_plan($bb));
        $dif = ($sa !== $sb);
        if ($dif) $schimbate++;
        /* Un caz in care NICIO revizie n-avea ce face (retea prezenta, baterie plina) nu
           spune nimic despre cine citeste politica. Raportam pe cazurile in care era ceva
           de facut — altfel cifra pare mai slaba decat e, si asta e tot o neadevarare. */
        if ($sa !== '' || $sb !== '') $cu_treaba++;
        $linii[] = [$caz, $dif, $sa, $sb];
    }

    $l = str_repeat('─', 78);
    echo "\n  POLITICA S-A SCHIMBAT — CINE A OBSERVAT\n  " . $l . "\n";
    printf("  %-26s %s\n", 'regula cu prag fix',
        '0 din ' . $cu_treaba . ' (nu citeste politica — nici nu are cum)');
    printf("  %-26s %s\n", 'Llama + validator', $schimbate . ' din ' . $cu_treaba);
    echo "  " . $l . "\n";
    printf("  %d cazuri din %d nu cereau nicio actiune sub niciuna din revizii; nu sunt numarate.\n",
        $comparate - $cu_treaba, $comparate);
    if ($sarite) printf("  %d cazuri sarite: una din rulari n-a produs plan (esec de model).\n", $sarite);
    echo "\n";
    foreach ($linii as [$caz, $dif, $sa, $sb]) {
        printf("    %-32s %s\n", $caz, $dif ? 'alt plan' : 'acelasi plan');
        if ($dif) {
            printf("      A: %s\n", $sa !== '' ? $sa : '(nicio actiune)');
            printf("      B: %s\n", $sb !== '' ? $sb : '(nicio actiune)');
        }
    }
    echo "\n";
    return 0;
}

/* ───────────────────────── intrarea ───────────────────────── */

$ce = '';
$runs = 3; $model = getenv('LOCAL_MODEL_NAME') ?: 'llama3.3'; $pol = 'policy/example-policy.md'; $sufix = '';
foreach (array_slice($argv, 1) as $a) {
    if (in_array($a, ['--build', '--baseline', '--record', '--score', '--policy-shift'], true)) $ce = $a;
    elseif (strncmp($a, '--suffix=', 9) === 0) $sufix = substr($a, 9);
    elseif (strncmp($a, '--runs=', 7) === 0)  $runs = max(1, (int)substr($a, 7));
    elseif (strncmp($a, '--model=', 8) === 0) $model = substr($a, 8);
    elseif (strncmp($a, '--policy=', 9) === 0) $pol = substr($a, 9);
}
if ($ce === '') {
    fwrite(STDERR, "usage: php tools/bench.php --build | --baseline | --record [--runs=3] [--model=llama3.3] | --score\n");
    exit(2);
}
exit($ce === '--build' ? b_build()
   : ($ce === '--baseline' ? b_baseline()
   : ($ce === '--record' ? b_record($runs, $model, $pol, $sufix)
   : ($ce === '--policy-shift' ? b_policy_shift() : b_score()))));

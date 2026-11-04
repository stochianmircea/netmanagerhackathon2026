<?php
/* NM_CRITICA_20261030 — punem modelul Meta sa ne critice propria predare.
   Foloseste ask_meta() din compilator, deci exact drumul deja probat. */
require __DIR__ . '/../src/compiler.php';
$readme = (string)@file_get_contents(__DIR__ . '/../README.md');
$doc    = (string)@file_get_contents(__DIR__ . '/../docs/comparison.md');
if ($readme === '' || $doc === '') { fwrite(STDERR, "lipseste README.md sau docs/comparison.md\n"); exit(2); }
$p = "You are judging a hackathon submission. Be blunt and specific; do not flatter.\n"
   . "The project is an energy-aware network agent: a language model compiles a human-written\n"
   . "policy into a plan, a validator refuses it whole if anything does not resolve, and a human\n"
   . "approves before anything is executed.\n\n"
   . "Answer four questions, short and concrete:\n"
   . "1. What is the single weakest claim here, and why would a judge doubt it?\n"
   . "2. What is missing that a judge would expect to see?\n"
   . "3. What would you ask in a live final round that this material does not already answer?\n"
   . "4. In a three-minute demo video, what should be shown first, and what should be cut?\n\n"
   . "=== README ===\n" . $readme . "\n\n=== docs/comparison.md ===\n" . $doc . "\n";
echo ask_meta($p, ['timeout' => 180]), "\n";

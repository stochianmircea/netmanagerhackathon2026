#!/bin/bash
# NM_BANC_STRAJA_20261029 — rularea de noapte a bancului, cu straja.
#
# Goleste directoarele INAINTE: au ramas 53 de planuri dintr-o inregistrare intrerupta
# pe 29.10, iar paznicul de provenienta le-ar refuza oricum amestecate cu cele noi.
#
#   bash rulare_noapte.sh            # porneste acum, se opreste la 07:30
#   bash rulare_noapte.sh 06:00      # se opreste la 06:00
set -u
cd /root/hack2026 || exit 1
PANA="${1:-07:30}"

echo "=== curat inregistrarile intrerupte ==="
rm -f fixtures/bench/plans-model/*.json fixtures/bench/plans-model-B/*.json
echo "    plans-model:   $(ls fixtures/bench/plans-model/*.json 2>/dev/null | wc -l) planuri"
echo "    plans-model-B: $(ls fixtures/bench/plans-model-B/*.json 2>/dev/null | wc -l) planuri"

echo "=== 70B, revizia A, pornit $(date +%H:%M:%S), ma opresc la $PANA ==="
MODEL_TIMEOUT=900 php tools/bench.php --record --runs=3 --model=Llama3.3:latest --pana="$PANA"

echo "=== 70B, revizia B, pornit $(date +%H:%M:%S) ==="
MODEL_TIMEOUT=900 php tools/bench.php --record --runs=3 --model=Llama3.3:latest \
    --policy=policy/example-policy-b.md --suffix=B --pana="$PANA"

echo "=== GATA $(date +%H:%M:%S) ==="
php tools/scorecard.php --json | head -3

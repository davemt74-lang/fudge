#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
echo "[1/5] PHP syntax"
find "$ROOT" -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
echo "  PASS"

echo "[2/5] Encryption round trip"
php -r 'require $argv[1]; $k=bin2hex(random_bytes(32)); $s="sk-test-secret-1234"; $e=Security::encrypt($s,$k); if(Security::decrypt($e,$k)!==$s){exit(1);} if(str_contains($e,$s)){exit(2);} echo "  PASS
";' "$ROOT/app/Security.php"

echo "[3/5] Schema contracts"
for table in users roles permissions ingredients packaging_items suppliers supplier_items supplier_price_history inventory_transactions recipes recipe_versions recipe_items flavors products customers orders production_batches llm_providers llm_credentials audit_log; do
  grep -q "CREATE TABLE IF NOT EXISTS $table" "$ROOT/database/schema.sql" || { echo "Missing table: $table"; exit 1; }
done
echo "  PASS"

echo "[4/5] Permission contracts"
for perm in inventory.adjust suppliers.update_prices recipes.publish production.create_batch orders.create team.manage_roles ai.manage_api_keys settings.manage; do
  grep -q "'$perm'" "$ROOT/database/seed.sql" || { echo "Missing permission: $perm"; exit 1; }
done
echo "  PASS"

echo "[5/5] Secret hygiene"
python - "$ROOT" <<'PYSCAN'
import re, sys
from pathlib import Path
root=Path(sys.argv[1])
patterns=[re.compile(r"sk-[A-Za-z0-9_-]{20,}"), re.compile(r"api[_-]?key\s*=\s*[\"\'][^\"\']{12,}", re.I)]
for path in root.rglob('*'):
    if not path.is_file() or path.name in {'run.sh','README.md'}:
        continue
    try: text=path.read_text(errors='ignore')
    except Exception: continue
    for pat in patterns:
        if pat.search(text):
            print(f"Possible hardcoded secret found in {path}")
            raise SystemExit(1)
print("  PASS")
PYSCAN
echo "All static checks passed."

#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"

echo "[1/10] PHP syntax"
find "$ROOT" -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
echo "  PASS"

echo "[2/10] Encryption round trip"
php -r 'require $argv[1]; $k=bin2hex(random_bytes(32)); $s="sk-test-secret-1234"; $e=Security::encrypt($s,$k); if(Security::decrypt($e,$k)!==$s){exit(1);} if(str_contains($e,$s)){exit(2);} echo "  PASS\n";' "$ROOT/app/Security.php"

echo "[3/10] Unit conversion contracts"
php -r 'require $argv[1]; $a=UnitConversionService::convertByFactors(4.5,"weight",16,"weight",1,"lb","oz"); if(abs($a-72)>0.000001) exit(1); $b=UnitConversionService::convertByFactors(1000,"weight",0.035274,"weight",1,"g","oz"); if(abs($b-35.274)>0.000001) exit(2); $c=UnitConversionService::convertByFactors(2,"volume",8,"volume",1,"cup","fl oz"); if(abs($c-16)>0.000001) exit(3); echo "  PASS\\n";' "$ROOT/app/UnitConversionService.php"

echo "[4/10] Schema contracts"
for table in users roles permissions ingredients packaging_items suppliers supplier_items supplier_price_history inventory_transactions recipes recipe_versions recipe_items flavors products customers orders production_batches llm_providers llm_credentials audit_log schema_migrations platform_meta purchase_orders purchase_order_items receiving_sessions receiving_items inventory_counts inventory_count_items; do
  grep -q "CREATE TABLE IF NOT EXISTS $table" "$ROOT/database/schema.sql" || { echo "Missing table: $table"; exit 1; }
done
echo "  PASS"

echo "[5/10] Permission contracts"
for perm in inventory.adjust suppliers.update_prices recipes.publish production.create_batch orders.create team.manage_roles ai.manage_api_keys settings.manage purchasing.view purchasing.manage purchasing.submit purchasing.receive purchasing.cancel lots.view lots.manage; do
  grep -q "'$perm'" "$ROOT/database/seed.sql" || { echo "Missing permission: $perm"; exit 1; }
done
echo "  PASS"

echo "[6/10] Migration manager contracts"
test -f "$ROOT/public/upgrade.php"
test -f "$ROOT/app/Migrator.php"
test -d "$ROOT/database/migrations"
grep -q "applyPending" "$ROOT/public/upgrade.php"
grep -q "schema_migrations" "$ROOT/app/Migrator.php"
grep -q "mark.*migrations\|schema_migrations" "$ROOT/public/install.php"
migration_count="$(find "$ROOT/database/migrations" -maxdepth 1 -name '*.php' | wc -l | tr -d ' ')"
test "$migration_count" -ge 1
echo "  PASS ($migration_count migrations)"

echo "[7/10] Access-control contracts"
grep -q 'status = "active"' "$ROOT/app/Auth.php"
grep -q "user_permission_overrides" "$ROOT/app/Permissions.php"
grep -q "save_user_permission_overrides" "$ROOT/public/index.php"
grep -q "Owner permissions are protected" "$ROOT/public/index.php"
grep -q "session_regenerate_id" "$ROOT/app/Auth.php"
echo "  PASS"

echo "[8/10] Phase 2A contracts"
test -f "$ROOT/app/PurchasingService.php"
test -f "$ROOT/app/Phase2AController.php"
test -f "$ROOT/database/migrations/20260921_003_purchasing_receiving_inventory_counts.php"
grep -q "Purchase Receipt" "$ROOT/app/PurchasingService.php"
grep -q "Physical Count Reconciliation" "$ROOT/app/PurchasingService.php"
grep -q "ordered_packages.*received_packages" "$ROOT/app/PurchasingService.php"
grep -q "GET_LOCK" "$ROOT/app/Migrator.php"
grep -q "purchasing.receive" "$ROOT/database/migrations/20260921_003_purchasing_receiving_inventory_counts.php"
grep -q "Admin is intended to be full-access" "$ROOT/database/migrations/20260921_003_purchasing_receiving_inventory_counts.php"
grep -q "Physical inventory counts cannot be negative" "$ROOT/app/PurchasingService.php"
echo "  PASS"

echo "[9/10] Installer and migration integrity"
grep -q "checksum" "$ROOT/app/Migrator.php"
grep -q "hash_file('sha256'" "$ROOT/public/install.php"
grep -q "configTempPath" "$ROOT/public/install.php"
grep -q "INSERT IGNORE INTO user_roles" "$ROOT/public/install.php"
grep -q "Migration file changed after it was applied" "$ROOT/app/Migrator.php"
echo "  PASS"

echo "[10/10] Secret hygiene"
python - "$ROOT" <<'PYSCAN'
import re, sys
from pathlib import Path
root=Path(sys.argv[1])
patterns=[
    re.compile(r"sk-[A-Za-z0-9_-]{20,}"),
    re.compile(r"api[_-]?key\s*=\s*[\"\'][^\"\']{12,}", re.I),
]
for path in root.rglob('*'):
    if not path.is_file() or path.name in {'run.sh','README.md'}:
        continue
    try:
        text=path.read_text(errors='ignore')
    except Exception:
        continue
    for pat in patterns:
        if pat.search(text):
            print(f"Possible hardcoded secret found in {path}")
            raise SystemExit(1)
print("  PASS")
PYSCAN

echo "All release checks passed."

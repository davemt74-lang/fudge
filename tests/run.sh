#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"

echo "[1/17] PHP syntax"
find "$ROOT" -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
echo "  PASS"

echo "[2/17] Encryption round trip"
php -r 'require $argv[1]; $k=bin2hex(random_bytes(32)); $s="sk-test-secret-1234"; $e=Security::encrypt($s,$k); if(Security::decrypt($e,$k)!==$s){exit(1);} if(str_contains($e,$s)){exit(2);} echo "  PASS\n";' "$ROOT/app/Security.php"

echo "[3/17] Unit conversion contracts"
php -r 'require $argv[1]; $a=UnitConversionService::convertByFactors(4.5,"weight",16,"weight",1,"lb","oz"); if(abs($a-72)>0.000001) exit(1); $b=UnitConversionService::convertByFactors(1000,"weight",0.035274,"weight",1,"g","oz"); if(abs($b-35.274)>0.000001) exit(2); $c=UnitConversionService::convertByFactors(2,"volume",8,"volume",1,"cup","fl oz"); if(abs($c-16)>0.000001) exit(3); echo "  PASS\\n";' "$ROOT/app/UnitConversionService.php"

echo "[4/17] Schema contracts"
for table in users roles permissions ingredients packaging_items suppliers supplier_items supplier_price_history inventory_transactions recipes recipe_versions recipe_items flavors products customers orders production_batches llm_providers llm_credentials audit_log schema_migrations platform_meta purchase_orders purchase_order_items receiving_sessions receiving_items inventory_counts inventory_count_items product_packaging_components recipe_cost_snapshots product_cost_snapshots production_plans production_plan_orders production_plan_items production_plan_requirements production_plan_issues production_stages production_qc_templates production_batch_steps production_batch_materials production_consumption_allocations production_qc_checks waste_reasons production_waste batch_assignments labor_sessions; do
  grep -q "CREATE TABLE IF NOT EXISTS $table" "$ROOT/database/schema.sql" || { echo "Missing table: $table"; exit 1; }
done
echo "  PASS"

echo "[5/17] Permission contracts"
for perm in inventory.adjust suppliers.update_prices recipes.publish production.create_batch orders.create team.manage_roles ai.manage_api_keys settings.manage purchasing.view purchasing.manage purchasing.submit purchasing.receive purchasing.cancel lots.view lots.manage costing.view costing.snapshot costing.view_margin planning.view planning.manage planning.lock orders.allocate_flavors production.manage_materials production.record_qc production.record_waste production.assign_team production.track_labor production.complete_batch production.manage_settings; do
  grep -q "'$perm'" "$ROOT/database/seed.sql" || { echo "Missing permission: $perm"; exit 1; }
done
echo "  PASS"

echo "[6/17] Migration manager contracts"
test -f "$ROOT/public/upgrade.php"
test -f "$ROOT/app/Migrator.php"
test -d "$ROOT/database/migrations"
grep -q "applyPending" "$ROOT/public/upgrade.php"
grep -q "schema_migrations" "$ROOT/app/Migrator.php"
grep -q "mark.*migrations\|schema_migrations" "$ROOT/public/install.php"
migration_count="$(find "$ROOT/database/migrations" -maxdepth 1 -name '*.php' | wc -l | tr -d ' ')"
test "$migration_count" -ge 1
echo "  PASS ($migration_count migrations)"

echo "[7/17] Access-control contracts"
grep -q 'status = "active"' "$ROOT/app/Auth.php"
grep -q "user_permission_overrides" "$ROOT/app/Permissions.php"
grep -q "save_user_permission_overrides" "$ROOT/public/index.php"
grep -q "Owner permissions are protected" "$ROOT/public/index.php"
grep -q "session_regenerate_id" "$ROOT/app/Auth.php"
echo "  PASS"

echo "[8/17] Phase 2A contracts"
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

echo "[9/17] Installer and migration integrity"
grep -q "checksum" "$ROOT/app/Migrator.php"
grep -q "hash_file('sha256'" "$ROOT/public/install.php"
grep -q "configTempPath" "$ROOT/public/install.php"
grep -q "INSERT IGNORE INTO user_roles" "$ROOT/public/install.php"
grep -q "Migration file changed after it was applied" "$ROOT/app/Migrator.php"
echo "  PASS"

echo "[10/17] Phase 2B costing contracts"
test -f "$ROOT/app/CostingService.php"
test -f "$ROOT/app/Phase2BController.php"
test -f "$ROOT/database/migrations/20260921_004_recursive_costing.php"
grep -q "Recipe cycle detected" "$ROOT/app/CostingService.php"
grep -q "validateStructure" "$ROOT/app/CostingService.php"
grep -q "Missing current cost" "$ROOT/app/CostingService.php"
grep -q "supplier_price_before" "$ROOT/public/index.php"
grep -q "Recent Cost Impacts" "$ROOT/app/Phase2BController.php"
grep -q "product_packaging_components" "$ROOT/database/schema.sql"
grep -q "old_unit_cost" "$ROOT/database/schema.sql"
grep -q "uq_recipe_component" "$ROOT/database/schema.sql"
grep -q "uq_recipe_component" "$ROOT/database/migrations/20260921_004_recursive_costing.php"
grep -q "That component is already in this recipe version" "$ROOT/app/CostingService.php"
grep -q "must have a published version" "$ROOT/app/CostingService.php"
echo "  PASS"

echo "[11/17] Starter BOM contracts"
grep -q "Chocolate Fudge Base" "$ROOT/database/seed.sql"
grep -q "PKG-WRAP" "$ROOT/database/seed.sql"
grep -q "PKG-STICKER" "$ROOT/database/seed.sql"
grep -q "PKG-BOX6" "$ROOT/database/seed.sql"
grep -q "PKG-BOX12" "$ROOT/database/seed.sql"
grep -q "classic-chocolate" "$ROOT/database/seed.sql"
grep -q "cookies-cream" "$ROOT/database/seed.sql"
grep -q "peanut-butter-cup" "$ROOT/database/seed.sql"
echo "  PASS"

echo "[12/17] Transaction and fresh-install ordering"
grep -q "SAVEPOINT" "$ROOT/app/Database.php"
grep -q "ROLLBACK TO SAVEPOINT" "$ROOT/app/Database.php"
python - "$ROOT/database/schema.sql" <<'PYORDER'
from pathlib import Path
import sys
text=Path(sys.argv[1]).read_text()
plan=text.index("CREATE TABLE IF NOT EXISTS production_plans")
batch=text.index("CREATE TABLE IF NOT EXISTS production_batches")
if plan >= batch:
    raise SystemExit("production_plans must be created before production_batches")
if text.count("CREATE TABLE IF NOT EXISTS production_plans") != 1:
    raise SystemExit("production_plans should appear exactly once in schema")
print("  PASS")
PYORDER

echo "[13/17] Phase 3 production-planning contracts"
test -f "$ROOT/app/DemandPlanningService.php"
test -f "$ROOT/app/Phase3Controller.php"
test -f "$ROOT/database/migrations/20260921_005_production_planning.php"
grep -q "orders.allocate_flavors" "$ROOT/database/seed.sql"
grep -q "One or more orders are already committed" "$ROOT/app/DemandPlanningService.php"
grep -q "Plan inputs changed after it was locked" "$ROOT/app/DemandPlanningService.php"
grep -q "refreshMaterialRequirements" "$ROOT/app/DemandPlanningService.php"
grep -q "launchProduction" "$ROOT/app/DemandPlanningService.php"
grep -q "production_plan_id" "$ROOT/database/schema.sql"
grep -q "uq_batch_plan" "$ROOT/database/migrations/20260921_005_production_planning.php"
grep -q "FOR UPDATE" "$ROOT/app/DemandPlanningService.php"
grep -q "Order Flavor Allocation" "$ROOT/app/Phase3Controller.php"
grep -q "Material Requirements" "$ROOT/app/Phase3Controller.php"
echo "  PASS"

echo "[14/17] Immutable nested recipe version contracts"
grep -q "component_recipe_version_id" "$ROOT/database/schema.sql"
grep -q "component_recipe_version_id" "$ROOT/database/migrations/20260921_006_production_execution.php"
grep -q "bindNestedRecipeVersions" "$ROOT/app/CostingService.php"
grep -q "component_recipe_version_id" "$ROOT/app/CostingService.php"
grep -q "component_recipe_version_id" "$ROOT/app/DemandPlanningService.php"
grep -q "component_recipe_version_id" "$ROOT/app/ProductionExecutionService.php"
grep -q "Fresh-install nested recipe version binding" "$ROOT/database/seed.sql"
echo "  PASS"

echo "[15/17] Phase 4 production execution contracts"
test -f "$ROOT/app/ProductionExecutionService.php"
test -f "$ROOT/app/Phase4Controller.php"
test -f "$ROOT/database/migrations/20260921_006_production_execution.php"
grep -q "fudge_inventory_" "$ROOT/app/Services.php"
grep -q "GET_LOCK" "$ROOT/app/Services.php"
grep -q "withItemLocks" "$ROOT/app/PurchasingService.php"
grep -q "withItemLocks" "$ROOT/app/ProductionExecutionService.php"
grep -q "inventory_transaction_cursor" "$ROOT/database/schema.sql"
grep -q "inventory_transaction_cursor" "$ROOT/app/PurchasingService.php"
grep -q "fudge_inventory_count_open" "$ROOT/app/PurchasingService.php"
grep -q "Production Use" "$ROOT/app/ProductionExecutionService.php"
grep -q "expires_at IS NULL OR DATE(l.expires_at)>=CURDATE()" "$ROOT/app/ProductionExecutionService.php"
grep -q "production_consumption_allocations" "$ROOT/app/ProductionExecutionService.php"
grep -q "uq_finished_batch_item" "$ROOT/database/schema.sql"
grep -q "already_completed" "$ROOT/app/ProductionExecutionService.php"
grep -q "Clock out all labor sessions" "$ROOT/app/ProductionExecutionService.php"
grep -q "Resolve all pending or failed QC checks" "$ROOT/app/ProductionExecutionService.php"
grep -q "handle_phase4_page" "$ROOT/public/index.php"
echo "  PASS"

echo "[16/17] Configurable production operations"
grep -q "CREATE TABLE IF NOT EXISTS production_stages" "$ROOT/database/schema.sql"
grep -q "CREATE TABLE IF NOT EXISTS production_qc_templates" "$ROOT/database/schema.sql"
grep -q "production.manage_settings" "$ROOT/database/seed.sql"
grep -q "FROM production_stages" "$ROOT/app/ProductionExecutionService.php"
grep -q "FROM production_qc_templates" "$ROOT/app/ProductionExecutionService.php"
grep -q "saveStage" "$ROOT/app/ProductionExecutionService.php"
grep -q "saveQcTemplate" "$ROOT/app/ProductionExecutionService.php"
grep -q "saveWasteReason" "$ROOT/app/ProductionExecutionService.php"
grep -q "Production Settings" "$ROOT/app/Phase4Controller.php"
if grep -q "self::STAGES" "$ROOT/app/ProductionExecutionService.php"; then
  echo "Production stages are still hardcoded"; exit 1
fi
echo "  PASS"

echo "[17/17] Secret hygiene"
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
